<?php
/**
 * Diagnostic CLI d'un document (utilisé par les tests d'intégration).
 *
 *    php scripts/document_info.php <id>
 *
 * Ne divulgue ni clé ni contenu : uniquement les métadonnées et le chemin du
 * fichier chiffré. Le contenu .enc reste illisible sans la clé maître.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script s’exécute uniquement en ligne de commande.');
}

require_once __DIR__ . '/../src/bootstrap.php';

if ($argc < 2 || !ctype_digit((string) $argv[1])) {
    fwrite(STDERR, "Usage : php scripts/document_info.php <id>\n");
    exit(2);
}
$id = (int) $argv[1];

$stmt = db()->prepare(
    'SELECT d.id, d.nom_original, d.chemin_chiffre, d.taille, d.mime_type,
            d.sha256, encode(d.nonce, \'hex\') AS nonce_hex,
            encode(d.tag_auth, \'hex\') AS tag_hex, d.uploade_par,
            d.supprime_le, u.email AS uploadeur_email
       FROM documents d
       JOIN users u ON u.id = d.uploade_par
      WHERE d.id = :id'
);
$stmt->execute([':id' => $id]);
$document = $stmt->fetch();
if ($document === false) {
    fwrite(STDERR, "Document #{$id} introuvable.\n");
    exit(1);
}

$absolutePath = null;
$fileExists = false;
try {
    $absolutePath = assert_document_file($document);
    $fileExists = is_file($absolutePath);
} catch (Throwable $exception) {
    // Chemin invalide : on l'indique sans faire échouer le diagnostic.
}

$payload = [
    'id' => (int) $document['id'],
    'nom_original' => $document['nom_original'],
    'chemin_chiffre' => $document['chemin_chiffre'],
    'chemin_absolu' => $absolutePath,
    'fichier_present' => $fileExists,
    'taille' => (int) $document['taille'],
    'mime_type' => $document['mime_type'],
    'sha256' => $document['sha256'],
    'nonce_hex' => $document['nonce_hex'],
    'tag_hex' => $document['tag_hex'],
    'uploade_par' => (int) $document['uploade_par'],
    'uploadeur_email' => $document['uploadeur_email'],
    'supprime_le' => $document['supprime_le'],
];
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
