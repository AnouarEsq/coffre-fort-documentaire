<?php
/**
 * Téléchargement d'un document (Module E).
 *
 * Même vérifications que view.php, avec la permission document.download et
 * l'action DOWNLOAD. Le fichier clair temporaire est supprimé après envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

require_login();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    render_error(400, 'Requête invalide', 'Identifiant de document manquant ou invalide.');
}
$id = (int) $id;

require_permission('document.download', $id);

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

journaliser('DOWNLOAD', $id, [
    'nom_original' => (string) $document['nom_original'],
    'mime_type' => (string) $document['mime_type'],
    'taille' => (int) $document['taille'],
    'mode' => 'attachment',
]);

stream_plaintext(
    $tmpPath,
    (string) $document['mime_type'],
    (string) $document['nom_original'],
    true
);
@unlink($tmpPath);
