<?php
/**
 * Accès aux documents : métadonnées, vérification d'intégrité, déchiffrement
 * à la volée et envoi au navigateur.
 */
declare(strict_types=1);

/** Types autorisés au dépôt : PDF, images et bureautique. */
function allowed_document_types(): array
{
    return [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'odt' => ['application/vnd.oasis.opendocument.text'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet'],
    ];
}

function is_allowed_document(string $extension, string $mime): bool
{
    $types = allowed_document_types();
    $extension = strtolower($extension);
    return isset($types[$extension]) && in_array(strtolower($mime), $types[$extension], true);
}

/** @return list<string> */
function all_allowed_mimes(): array
{
    $mimes = [];
    foreach (allowed_document_types() as $list) {
        foreach ($list as $mime) {
            $mimes[] = $mime;
        }
    }
    return array_values(array_unique($mimes));
}

/** Récupère un document et son déposant (y compris les documents retirés). */
function find_document(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT d.*, u.nom AS uploadeur_nom, u.email AS uploadeur_email
           FROM documents d
           JOIN users u ON u.id = d.uploade_par
          WHERE d.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $document = $stmt->fetch();
    return $document === false ? null : $document;
}

/**
 * Résout le chemin du .enc en garantissant qu'il reste dans storage/encrypted
 * (protection contre le path traversal, même si la base était compromise).
 */
function assert_document_file(array $document): string
{
    $stored = (string) ($document['chemin_chiffre'] ?? '');
    $name = basename(str_replace('\\', '/', $stored));
    if (!preg_match('/^[a-f0-9]{32}\.enc$/', $name)) {
        throw new RuntimeException('Nom de fichier chiffré invalide.');
    }

    $base = realpath((string) config('storage.encrypted'));
    if ($base === false) {
        throw new RuntimeException('Dossier de stockage chiffré introuvable.');
    }
    $candidate = $base . DIRECTORY_SEPARATOR . $name;
    $full = realpath($candidate);
    if ($full === false || !is_file($full)) {
        throw new RuntimeException('Fichier chiffré absent du stockage.');
    }

    $baseNorm = rtrim(str_replace('\\', '/', $base), '/');
    $fullNorm = str_replace('\\', '/', $full);
    if (!str_starts_with($fullNorm, $baseNorm . '/')) {
        throw new RuntimeException('Chemin de document hors du stockage autorisé.');
    }
    return $full;
}

/**
 * Vérifie le SHA-256 du fichier chiffré puis déchiffre en mémoire (fichier
 * temporaire 0600). En cas d'échec : journalise ACCESS_DENIED et affiche 409.
 * Retourne le chemin du fichier clair temporaire (à supprimer par l'appelant).
 */
function decrypt_document_or_fail(array $document): string
{
    $documentId = (int) $document['id'];
    $expected = trim((string) $document['sha256']);

    try {
        $encryptedPath = assert_document_file($document);
    } catch (Throwable $exception) {
        error_log('Document #' . $documentId . ' : ' . $exception->getMessage());
        render_error(
            500,
            'Document indisponible',
            'Le fichier chiffré est introuvable ou son emplacement est invalide. Contactez un administrateur.'
        );
    }

    // Première barrière d'intégrité : empreinte du .enc stockée en base.
    $actual = hash_file('sha256', $encryptedPath);
    if ($actual === false || !hash_equals($expected, $actual)) {
        journaliser('ACCESS_DENIED', $documentId, [
            'reason' => 'INTEGRITY_CHECK_FAILED',
            'etape' => 'sha256_du_fichier_chiffre',
            'sha256_attendu' => $expected,
            'sha256_obtenu' => $actual === false ? null : $actual,
        ]);
        render_error(
            409,
            'Intégrité compromise',
            "Le fichier chiffré ne correspond plus à l'empreinte enregistrée. "
            . "Le document n'a pas été déchiffré et l'incident a été journalisé."
        );
    }

    $tmpDir = (string) config('storage.tmp');
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
        render_error(500, 'Erreur interne', 'Le dossier temporaire est inaccessible.');
    }
    $tmpPath = tempnam($tmpDir, 'dec_');
    if ($tmpPath === false) {
        render_error(500, 'Erreur interne', 'Impossible de créer un fichier temporaire sécurisé.');
    }
    @chmod($tmpPath, 0600);

    try {
        // Seconde barrière : tag GCM vérifié par Python (et SHA-256 si fourni).
        crypto_decrypt($encryptedPath, $tmpPath, $expected);
    } catch (CryptoBridgeError $exception) {
        @unlink($tmpPath);
        if ($exception->errorCode === 'INTEGRITY_CHECK_FAILED') {
            journaliser('ACCESS_DENIED', $documentId, [
                'reason' => 'INTEGRITY_CHECK_FAILED',
                'etape' => 'tag_gcm',
                'erreur' => $exception->errorCode,
            ]);
            render_error(
                409,
                'Intégrité compromise',
                "Le tag d'authentification GCM est invalide : le document a été altéré "
                . "ou la clé est incorrecte. Le déchiffrement a été refusé et l'incident journalisé."
            );
        }
        error_log('Déchiffrement #' . $documentId . ' : ' . $exception->getMessage());
        render_error(
            500,
            'Déchiffrement impossible',
            "Le module cryptographique n'a pas pu déchiffrer le document. Consultez les journaux du serveur."
        );
    } catch (Throwable $exception) {
        @unlink($tmpPath);
        error_log('Déchiffrement #' . $documentId . ' : ' . $exception->getMessage());
        render_error(500, 'Déchiffrement impossible', 'Une erreur interne est survenue.');
    }

    if (!is_file($tmpPath) || filesize($tmpPath) === false) {
        @unlink($tmpPath);
        render_error(500, 'Déchiffrement impossible', 'Le fichier clair temporaire est absent.');
    }
    return $tmpPath;
}

/**
 * Envoie un fichier clair déjà déchiffré. Les en-têtes interdisent le cache et
 * le reniflage de type ; le nom de fichier est encodé proprement.
 */
function stream_plaintext(string $tmpPath, string $mime, string $filename, bool $attachment): void
{
    $mime = strtolower($mime);
    if (!in_array($mime, all_allowed_mimes(), true)) {
        $mime = 'application/octet-stream';
    }

    $fallback = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'document';
    $fallback = str_replace(['"', '\\', ';', "\r", "\n"], '_', $fallback);
    if ($fallback === '') {
        $fallback = 'document';
    }
    $disposition = $attachment ? 'attachment' : 'inline';
    $encoded = rawurlencode($filename);
    $size = (int) filesize($tmpPath);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header(
        'Content-Disposition: ' . $disposition . '; filename="' . $fallback
        . '"; filename*=UTF-8\'\'' . $encoded
    );
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: sandbox');
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');

    readfile($tmpPath);
}
