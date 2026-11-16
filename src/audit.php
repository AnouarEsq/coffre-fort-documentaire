<?php
/**
 * Journal d'audit append-only.
 *
 * Ce module ne contient QUE des INSERT. Aucune fonction de mise à jour ou de
 * suppression n'existe, et des triggers PostgreSQL (schema_hardening.sql)
 * bloquent toute mutation directe de la table audit_log.
 */
declare(strict_types=1);

/**
 * Écrit un événement dans audit_log.
 *
 * @param string     $action     Valeur de l'enum action_type
 * @param int|null   $documentId Document concerné (NULL si non applicable)
 * @param array      $details    Contexte JSON (jamais de secret ni de contenu)
 * @param int|null   $userId     Utilisateur ; par défaut celui de la session.
 *                               Passer explicitement NULL pour un anonyme.
 */
function journaliser(
    string $action,
    ?int $documentId = null,
    array $details = [],
    ?int $userId = null
): void {
    $allowed = [
        'LOGIN',
        'UPLOAD',
        'VIEW',
        'DOWNLOAD',
        'DELETE',
        'LOGIN_FAILED',
        'ACCESS_DENIED',
    ];
    if (!in_array($action, $allowed, true)) {
        throw new InvalidArgumentException('Action d’audit inconnue : ' . $action);
    }

    // Si le 4e argument n'est pas fourni, on prend l'utilisateur en session.
    if (func_num_args() < 4) {
        $user = current_user();
        $userId = $user['id'] ?? null;
    }

    $json = json_encode(
        (object) $details,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        throw new RuntimeException('Impossible de sérialiser les détails d’audit.');
    }

    $stmt = db()->prepare(
        'INSERT INTO audit_log (user_id, action, document_id, ip_address, details)
         VALUES (
             :user_id,
             CAST(:action AS action_type),
             :document_id,
             CAST(:ip_address AS inet),
             CAST(:details AS jsonb)
         )'
    );

    if ($userId === null) {
        $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':action', $action, PDO::PARAM_STR);
    if ($documentId === null) {
        $stmt->bindValue(':document_id', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':document_id', $documentId, PDO::PARAM_INT);
    }
    $ip = client_ip();
    if ($ip === null) {
        $stmt->bindValue(':ip_address', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':ip_address', $ip, PDO::PARAM_STR);
    }
    $stmt->bindValue(':details', $json, PDO::PARAM_STR);
    $stmt->execute();
}
