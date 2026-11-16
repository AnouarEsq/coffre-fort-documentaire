<?php
/**
 * Retrait d'un document (permission document.delete).
 *
 * Le document est « supprimé » logiquement (supprime_le) pour préserver
 * l'intégrité du journal d'audit (append-only) ; le fichier .enc est ensuite
 * effacé du stockage. L'action DELETE est journalisée.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

require_login();
require_post();
require_csrf();
require_permission('document.delete');

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    render_error(400, 'Requête invalide', 'Identifiant de document invalide.');
}
$id = (int) $id;

$document = find_document($id);
if ($document === null) {
    render_error(404, 'Document introuvable', 'Ce document n’existe pas.');
}
if ($document['supprime_le'] !== null) {
    render_error(410, 'Document déjà retiré', 'Ce document a déjà été retiré du coffre-fort.');
}

$encryptedPath = null;
try {
    $encryptedPath = assert_document_file($document);
} catch (Throwable $exception) {
    error_log('Retrait #' . $id . ' : ' . $exception->getMessage());
}

$pdo = db();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'UPDATE documents
            SET supprime_le = now()
          WHERE id = :id AND supprime_le IS NULL'
    );
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() !== 1) {
        $pdo->rollBack();
        render_error(409, 'Document déjà retiré', 'Le document a été retiré entre-temps.');
    }
    journaliser('DELETE', $id, [
        'nom_original' => (string) $document['nom_original'],
        'fichier_supprime' => $encryptedPath !== null,
        'mode' => 'suppression_logique_et_effacement_du_chiffre',
    ]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Retrait #' . $id . ' : ' . $exception->getMessage());
    render_error(500, 'Retrait impossible', 'Le document n’a pas pu être retiré.');
}

$fileDeleted = false;
if ($encryptedPath !== null && is_file($encryptedPath)) {
    $fileDeleted = @unlink($encryptedPath);
    if (!$fileDeleted) {
        error_log('Retrait #' . $id . ' : le fichier chiffré n’a pas pu être effacé.');
    }
}

flash('success', 'Document retiré du coffre-fort'
    . ($fileDeleted ? ' et fichier chiffré effacé.' : '.'));
redirect('/dashboard.php');
