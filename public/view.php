<?php
/**
 * Consultation d'un document (Module E).
 *
 * Contrôles : session, permission document.view, existence du document,
 * SHA-256 du .enc, tag GCM au déchiffrement. Le clair est écrit dans un
 * temporaire 0600, envoyé, puis supprimé. L'action VIEW est journalisée.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

require_login();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    render_error(400, 'Requête invalide', 'Identifiant de document manquant ou invalide.');
}
$id = (int) $id;

require_permission('document.view', $id);

$document = find_document($id);
if ($document === null) {
    render_error(404, 'Document introuvable', 'Ce document n’existe pas.');
}
if ($document['supprime_le'] !== null) {
    render_error(410, 'Document retiré', 'Ce document a été retiré du coffre-fort.');
}

$tmpPath = decrypt_document_or_fail($document);
register_shutdown_function(static function () use ($tmpPath): void {
    if (is_file($tmpPath)) {
        @unlink($tmpPath);
    }
});

journaliser('VIEW', $id, [
    'nom_original' => (string) $document['nom_original'],
    'mime_type' => (string) $document['mime_type'],
    'taille' => (int) $document['taille'],
    'mode' => 'inline',
]);

stream_plaintext(
    $tmpPath,
    (string) $document['mime_type'],
    (string) $document['nom_original'],
    false
);
@unlink($tmpPath);
