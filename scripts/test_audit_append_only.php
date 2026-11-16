<?php
/**
 * Vérifie que audit_log est réellement append-only.
 *
 * Chaque tentative de mutation est exécutée dans une transaction, puis
 * annulée par rollback. Si un trigger ne fonctionnait pas, le rollback
 * empêcherait quand même toute modification persistante.
 *
 * Sortie JSON :
 *   {"triggers":[...], "update_bloque":true, "delete_bloque":true,
 *    "truncate_bloque":true, "lignes_audit":N}
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script s’exécute uniquement en ligne de commande.');
}

require_once __DIR__ . '/../src/bootstrap.php';

$pdo = db();
$result = [
    'triggers' => [],
    'update_bloque' => false,
    'delete_bloque' => false,
    'truncate_bloque' => false,
    'lignes_audit' => 0,
];

$result['lignes_audit'] = (int) $pdo->query('SELECT count(*) FROM audit_log')->fetchColumn();
$result['triggers'] = $pdo->query(
    "SELECT tgname FROM pg_trigger
      WHERE tgrelid = 'audit_log'::regclass AND NOT tgisinternal
      ORDER BY tgname"
)->fetchAll(PDO::FETCH_COLUMN);

/**
 * Exécute une requête interdite dans une transaction et vérifie que le
 * trigger l'a rejetée avec le message attendu.
 */
function tentative_interdite(PDO $pdo, string $sql): bool
{
    $pdo->beginTransaction();
    $bloque = false;
    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        $bloque = str_contains($exception->getMessage(), 'append-only');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    return $bloque;
}

if ($result['lignes_audit'] > 0) {
    $result['update_bloque'] = tentative_interdite(
        $pdo,
        "UPDATE audit_log SET details = details WHERE id = (SELECT max(id) FROM audit_log)"
    );
    $result['delete_bloque'] = tentative_interdite(
        $pdo,
        "DELETE FROM audit_log WHERE id = (SELECT max(id) FROM audit_log)"
    );
}
$result['truncate_bloque'] = tentative_interdite($pdo, 'TRUNCATE audit_log');

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
