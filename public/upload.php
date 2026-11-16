<?php
/**
 * Dépôt sécurisé d'un document (Module D).
 *
 * 1. Contrôles : session, permission document.upload, CSRF, extension, MIME
 *    réel (finfo), taille <= 10 Mo, fichier réellement uploadé.
 * 2. Déplacement du temporaire PHP vers storage/uploads (nom aléatoire).
 * 3. Chiffrement AES-256-GCM via crypto/chiffrer.py (subprocess, sans shell).
 * 4. Suppression IMMÉDIATE du fichier en clair, quoi qu'il arrive.
 * 5. Insertion des métadonnées + journal UPLOAD dans la même transaction.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

require_login();
require_permission('document.upload');

$errors = [];
$maxBytes = (int) config('upload.max_bytes', 10 * 1024 * 1024);

// Si le corps dépasse post_max_size, PHP vide $_POST et $_FILES : on affiche
// un message explicite plutôt qu'un échec CSRF trompeur.
$payloadTooLarge = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && $_POST === []
    && $_FILES === []
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
) {
    $errors[] = 'Le fichier dépasse la limite acceptée par la configuration PHP '
        . '(post_max_size / upload_max_filesize).';
    $payloadTooLarge = true;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$payloadTooLarge) {
    require_csrf();

    $file = $_FILES['document'] ?? null;
    if (!is_array($file) || !isset($file['error']) || is_array($file['error'])
        || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        $errors[] = 'Aucun fichier sélectionné.';
    } elseif ((int) $file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = match ((int) $file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille maximale autorisée par le serveur.',
            UPLOAD_ERR_PARTIAL => 'Le transfert du fichier est incomplet. Réessayez.',
            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire PHP indisponible.',
            UPLOAD_ERR_CANT_WRITE => 'Écriture du fichier temporaire impossible.',
            default => 'Erreur lors du transfert du fichier (code ' . (int) $file['error'] . ').',
        };
    } else {
        $tmpName = input_string($file, 'tmp_name');
        $originalName = input_string($file, 'name');
        $declaredSize = (int) ($file['size'] ?? 0);

        if (!is_uploaded_file($tmpName)) {
            $errors[] = 'Fichier invalide : ce n’est pas un dépôt HTTP légitime.';
        } elseif ($declaredSize <= 0) {
            $errors[] = 'Le fichier est vide.';
        } elseif ($declaredSize > $maxBytes) {
            $errors[] = 'Le fichier dépasse la limite de ' . format_bytes($maxBytes) . '.';
        } else {
            // Nom affiché : on retire les caractères de contrôle et tout chemin.
            $originalName = str_replace(["\0", "\r", "\n"], '', $originalName);
            $originalName = basename(str_replace('\\', '/', $originalName));
            if ($originalName === '' || $originalName === '.' || $originalName === '..') {
                $originalName = 'document';
            }
            if (function_exists('mb_substr')) {
                $originalName = mb_substr($originalName, 0, 255, 'UTF-8');
            } else {
                $originalName = substr($originalName, 0, 255);
            }

            $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo !== false ? (string) finfo_file($finfo, $tmpName) : '';
            if ($finfo !== false) {
                finfo_close($finfo);
            }

            if (!is_allowed_document($extension, $mime)) {
                $errors[] = sprintf(
                    'Type de fichier refusé (extension « %s », type MIME « %s »). '
                    . 'Formats autorisés : PDF, images (PNG/JPEG/GIF/WebP) et bureautique (DOC/DOCX, XLS/XLSX, PPT/PPTX, ODT/ODS).',
                    $extension,
                    $mime
                );
            }
        }
    }

    if ($errors === []) {
        // Connexion établie AVANT de manipuler le fichier : si la base est
        // indisponible, aucun fichier en clair n'est créé sur le disque.
        $pdo = db();

        $plainPath = rtrim((string) config('storage.uploads'), '/\\')
            . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.upl';
        $encryptedName = bin2hex(random_bytes(16)) . '.enc';
        $encryptedPath = rtrim((string) config('storage.encrypted'), '/\\')
            . DIRECTORY_SEPARATOR . $encryptedName;
        $success = false;

        try {
            if (!move_uploaded_file($tmpName, $plainPath)) {
                throw new RuntimeException('Impossible de déplacer le fichier téléversé.');
            }
            @chmod($plainPath, 0600);
            $plainSize = filesize($plainPath);
            if ($plainSize === false || $plainSize <= 0) {
                throw new RuntimeException('Fichier téléversé vide ou illisible.');
            }

            // Chiffrement : l'original en clair ne quitte pas le serveur.
            $crypto = crypto_encrypt($plainPath, $encryptedPath);

            // Cohérence entre le JSON du module crypto et le fichier sur disque.
            $actualSha = hash_file('sha256', $encryptedPath);
            if ($actualSha === false || !hash_equals((string) $crypto['sha256'], $actualSha)) {
                throw new RuntimeException('Le SHA-256 du fichier chiffré est incohérent.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO documents
                    (nom_original, chemin_chiffre, taille, mime_type, sha256,
                     nonce, tag_auth, uploade_par)
                 VALUES
                    (:nom_original, :chemin_chiffre, :taille, :mime_type, :sha256,
                     decode(:nonce, \'hex\'), decode(:tag_auth, \'hex\'), :uploade_par)
                 RETURNING id'
            );
            $stmt->bindValue(':nom_original', $originalName, PDO::PARAM_STR);
            $stmt->bindValue(':chemin_chiffre', 'encrypted/' . $encryptedName, PDO::PARAM_STR);
            $stmt->bindValue(':taille', (int) $plainSize, PDO::PARAM_INT);
            $stmt->bindValue(':mime_type', $mime, PDO::PARAM_STR);
            $stmt->bindValue(':sha256', (string) $crypto['sha256'], PDO::PARAM_STR);
            $stmt->bindValue(':nonce', (string) $crypto['nonce'], PDO::PARAM_STR);
            $stmt->bindValue(':tag_auth', (string) $crypto['tag'], PDO::PARAM_STR);
            $stmt->bindValue(':uploade_par', (int) current_user()['id'], PDO::PARAM_INT);
            $stmt->execute();
            $documentId = (int) $stmt->fetchColumn();

            // Traçabilité atomique : métadonnées + UPLOAD dans la même transaction.
            journaliser('UPLOAD', $documentId, [
                'nom_original' => $originalName,
                'mime_type' => $mime,
                'taille' => (int) $plainSize,
                'sha256' => (string) $crypto['sha256'],
                'chiffrement' => 'AES-256-GCM',
            ]);
            $pdo->commit();
            $success = true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Échec du dépôt : ' . $exception->getMessage());
            $errors[] = $exception instanceof CryptoBridgeError
                ? 'Le chiffrement du document a échoué. Aucun fichier n’a été conservé.'
                : 'Le dépôt a échoué. Aucun fichier n’a été conservé.';
        } finally {
            // RÈGLE : le fichier en clair est supprimé immédiatement.
            if (isset($plainPath) && is_file($plainPath)) {
                @unlink($plainPath);
            }
            if (!$success && isset($encryptedPath) && is_file($encryptedPath)) {
                @unlink($encryptedPath);
            }
        }

        if ($success) {
            flash('success', 'Document chiffré et enregistré avec succès.');
            redirect('/dashboard.php');
        }
    }
}

render_header('Déposer un document', 'Le fichier sera chiffré en AES-256-GCM avant tout stockage.');
?>
<div class="two-col">
    <section class="panel">
        <?php foreach ($errors as $message): ?>
            <div class="alert alert-error" role="alert"><?= e($message) ?></div>
        <?php endforeach; ?>

        <form method="post" action="/upload.php" enctype="multipart/form-data" class="stack"
              id="upload-form" data-max-bytes="<?= $maxBytes ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="document">Fichier à protéger</label>
                <input type="file" id="document" name="document" required
                       accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods">
                <p class="field-hint">Taille maximale : <strong><?= e(format_bytes($maxBytes)) ?></strong>.
                    Types : PDF, images et documents bureautiques.</p>
                <p class="field-hint" id="file-check" aria-live="polite"></p>
            </div>
            <button type="submit" class="btn btn-primary">Chiffrer et déposer</button>
        </form>
    </section>

    <aside class="panel panel-muted">
        <h2 class="section-title">Ce qui se passe ensuite</h2>
        <ol class="steps">
            <li><strong>Contrôle</strong> de l’extension, du type MIME réel et de la taille.</li>
            <li><strong>Chiffrement</strong> AES-256-GCM avec un nonce aléatoire unique.</li>
            <li><strong>Suppression</strong> immédiate du fichier en clair sur le serveur.</li>
            <li><strong>Métadonnées</strong> uniquement en base : SHA-256, nonce, tag, taille.</li>
            <li><strong>Journalisation</strong> de l’événement UPLOAD (utilisateur, IP, horodatage).</li>
        </ol>
        <p class="security-note">Le contenu du document ne transite jamais en base de données
            et n’est jamais accessible directement par une URL de fichier.</p>
    </aside>
</div>
<?php render_footer(); ?>
