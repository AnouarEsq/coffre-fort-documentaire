<?php
/**
 * Données de démonstration (livrable 9).
 *
 *    php scripts/seed_demo.php
 *
 * - crée (ou met à jour) les 4 comptes de démo avec des mots de passe bcrypt ;
 * - génère 3 PDF fictifs valides ;
 * - les chiffre via le module Python et insère leurs métadonnées ;
 * - journalise chaque dépôt (UPLOAD) dans audit_log ;
 * - supprime les PDF en clair après chiffrement.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script s’exécute uniquement en ligne de commande.');
}

require_once __DIR__ . '/../src/bootstrap.php';

$pdo = db();
$tableExists = $pdo->query("SELECT to_regclass('public.documents')")->fetchColumn();
if ($tableExists === false || $tableExists === null) {
    fwrite(STDERR, "Le schéma n'est pas initialisé. Lancez d'abord : php scripts/init_db.php\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 1. Comptes de démonstration
// ---------------------------------------------------------------------------
$accounts = [
    ['nom' => 'Admin Demo', 'email' => 'admin@test.com', 'role' => 'admin', 'password' => 'Admin#2026!'],
    ['nom' => 'Dr Camille Martin', 'email' => 'medecin@test.com', 'role' => 'medecin', 'password' => 'Medecin#2026!'],
    ['nom' => 'Agent Accueil', 'email' => 'agent@test.com', 'role' => 'agent', 'password' => 'Agent#2026!'],
    ['nom' => 'Auditeur Interne', 'email' => 'auditeur@test.com', 'role' => 'auditeur', 'password' => 'Auditeur#2026!'],
];

$roleStmt = $pdo->prepare('SELECT id FROM roles WHERE nom = CAST(:nom AS role_name)');
$roleIds = [];
foreach (['admin', 'medecin', 'agent', 'auditeur'] as $roleName) {
    $roleStmt->execute([':nom' => $roleName]);
    $roleId = $roleStmt->fetchColumn();
    if ($roleId === false) {
        fwrite(STDERR, "Rôle absent de la base : {$roleName}. Réinitialisez le schéma.\n");
        exit(1);
    }
    $roleIds[$roleName] = (int) $roleId;
}

$accountIds = [];
$upsert = $pdo->prepare(
    'INSERT INTO users (nom, email, password_hash, role_id)
     VALUES (:nom, :email, :password_hash, :role_id)
     ON CONFLICT (email) DO UPDATE
        SET nom = EXCLUDED.nom,
            password_hash = EXCLUDED.password_hash,
            role_id = EXCLUDED.role_id
     RETURNING id'
);
foreach ($accounts as $account) {
    $upsert->execute([
        ':nom' => $account['nom'],
        ':email' => strtolower($account['email']),
        ':password_hash' => password_hash($account['password'], PASSWORD_BCRYPT, ['cost' => 12]),
        ':role_id' => $roleIds[$account['role']],
    ]);
    $accountIds[$account['email']] = (int) $upsert->fetchColumn();
}
echo "Comptes de démonstration prêts :\n";
foreach ($accounts as $account) {
    printf("  %-20s %-12s %s\n", $account['email'], $account['role'], $account['password']);
}
echo "  (mots de passe de démonstration — à changer hors prototype)\n\n";

// ---------------------------------------------------------------------------
// 2. Clé maître (générée au premier lancement si absente)
// ---------------------------------------------------------------------------
if (!is_file((string) config('crypto.key_file'))) {
    echo "Aucune clé maître détectée : génération...\n";
    generate_master_key_if_missing();
}
echo 'Keystore : ' . config('crypto.key_file') . "\n\n";

// ---------------------------------------------------------------------------
// 3. PDF fictifs + chiffrement + métadonnées + audit
// ---------------------------------------------------------------------------
$pdfDir = rtrim((string) config('storage.tmp'), '/\\') . DIRECTORY_SEPARATOR . 'seed_pdfs';
if (!is_dir($pdfDir) && !mkdir($pdfDir, 0700, true) && !is_dir($pdfDir)) {
    fwrite(STDERR, "Impossible de créer le dossier des PDF de démonstration.\n");
    exit(1);
}

try {
    $generated = run_python_script(
        (string) config('root') . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'make_demo_pdfs.py',
        [$pdfDir],
        120
    );
} catch (CryptoBridgeError $exception) {
    fwrite(STDERR, 'Génération des PDF impossible : ' . $exception->getMessage() . "\n");
    exit(1);
}

$demoDocuments = [
    [
        'fichier' => 'certificat_medical.pdf',
        'nom_original' => 'Certificat médical - Anouar Essadiq.pdf',
        'uploader' => 'medecin@test.com',
    ],
    [
        'fichier' => 'attestation_domicile.pdf',
        'nom_original' => 'Attestation de domicile - Anouar Essadiq.pdf',
        'uploader' => 'agent@test.com',
    ],
    [
        'fichier' => 'ordonnance_medicale.pdf',
        'nom_original' => 'Ordonnance médicale - Dr Martin.pdf',
        'uploader' => 'medecin@test.com',
    ],
];

$created = 0;
$skipped = 0;
echo "Documents de démonstration :\n";

foreach ($demoDocuments as $demo) {
    $pdfPath = $pdfDir . DIRECTORY_SEPARATOR . $demo['fichier'];
    if (!is_file($pdfPath)) {
        fwrite(STDERR, "Fichier généré absent : {$pdfPath}\n");
        continue;
    }

    $existing = $pdo->prepare(
        'SELECT id FROM documents WHERE nom_original = :nom AND supprime_le IS NULL LIMIT 1'
    );
    $existing->execute([':nom' => $demo['nom_original']]);
    $existingId = $existing->fetchColumn();
    if ($existingId !== false) {
        printf("  = %-50s déjà présent (#%d)\n", $demo['nom_original'], (int) $existingId);
        @unlink($pdfPath);
        $skipped++;
        continue;
    }

    $encryptedName = bin2hex(random_bytes(16)) . '.enc';
    $encryptedPath = rtrim((string) config('storage.encrypted'), '/\\') . DIRECTORY_SEPARATOR . $encryptedName;
    $documentId = null;
    $success = false;

    try {
        $plainSize = (int) filesize($pdfPath);
        $crypto = crypto_encrypt($pdfPath, $encryptedPath);
        $actualSha = hash_file('sha256', $encryptedPath);
        if ($actualSha === false || !hash_equals((string) $crypto['sha256'], $actualSha)) {
            throw new RuntimeException('SHA-256 incohérent après chiffrement.');
        }

        $pdo->beginTransaction();
        $insert = $pdo->prepare(
            'INSERT INTO documents
                (nom_original, chemin_chiffre, taille, mime_type, sha256,
                 nonce, tag_auth, uploade_par)
             VALUES
                (:nom_original, :chemin_chiffre, :taille, :mime_type, :sha256,
                 decode(:nonce, \'hex\'), decode(:tag_auth, \'hex\'), :uploade_par)
             RETURNING id'
        );
        $insert->bindValue(':nom_original', $demo['nom_original'], PDO::PARAM_STR);
        $insert->bindValue(':chemin_chiffre', 'encrypted/' . $encryptedName, PDO::PARAM_STR);
        $insert->bindValue(':taille', $plainSize, PDO::PARAM_INT);
        $insert->bindValue(':mime_type', 'application/pdf', PDO::PARAM_STR);
        $insert->bindValue(':sha256', (string) $crypto['sha256'], PDO::PARAM_STR);
        $insert->bindValue(':nonce', (string) $crypto['nonce'], PDO::PARAM_STR);
        $insert->bindValue(':tag_auth', (string) $crypto['tag'], PDO::PARAM_STR);
        $insert->bindValue(':uploade_par', $accountIds[$demo['uploader']], PDO::PARAM_INT);
        $insert->execute();
        $documentId = (int) $insert->fetchColumn();

        journaliser('UPLOAD', $documentId, [
            'nom_original' => $demo['nom_original'],
            'mime_type' => 'application/pdf',
            'taille' => $plainSize,
            'sha256' => (string) $crypto['sha256'],
            'source' => 'seed_demo',
        ], $accountIds[$demo['uploader']]);

        $pdo->commit();
        $success = true;
        printf("  + %-50s chiffré (#%d, nonce %s…)\n", $demo['nom_original'], $documentId, substr((string) $crypto['nonce'], 0, 8));
        $created++;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, '  ! Échec pour ' . $demo['nom_original'] . ' : ' . $exception->getMessage() . "\n");
    } finally {
        @unlink($pdfPath);
        if (!$success && is_file($encryptedPath)) {
            @unlink($encryptedPath);
        }
    }
}

@rmdir($pdfDir);

echo "\nRésumé : {$created} document(s) créé(s), {$skipped} déjà présent(s).\n";
echo "Base : " . config('db.name') . ' · Stockage chiffré : ' . config('storage.encrypted') . "\n";
echo "Connectez-vous avec admin@test.com / Admin#2026! pour la démonstration.\n";
