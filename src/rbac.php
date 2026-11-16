<?php
/**
 * Contrôle d'accès basé sur les rôles (RBAC).
 *
 * La vérité est en base : la fonction can() interroge la matrice
 * role_permissions / permissions. Aucune permission n'est codée en dur
 * dans les pages, ce qui garantit que la matrice livrée est bien celle
 * appliquée par l'application.
 */
declare(strict_types=1);

/** @return list<string> codes de permission du rôle courant */
function current_permissions(): array
{
    static $cache = [];

    $user = current_user();
    if ($user === null) {
        return [];
    }
    $roleId = (int) $user['role_id'];
    if (isset($cache[$roleId])) {
        return $cache[$roleId];
    }

    $stmt = db()->prepare(
        'SELECT p.code
           FROM role_permissions rp
           JOIN permissions p ON p.id = rp.permission_id
          WHERE rp.role_id = :role_id
          ORDER BY p.code'
    );
    $stmt->execute([':role_id' => $roleId]);
    $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $cache[$roleId] = array_values(array_map('strval', $codes));
    return $cache[$roleId];
}

/** Vérifie une permission pour l'utilisateur connecté. */
function can(string $permission): bool
{
    if (current_user() === null) {
        return false;
    }
    return in_array($permission, current_permissions(), true);
}

/**
 * Garde RBAC : refuse l'action (403) et journalise ACCESS_DENIED si la
 * permission requise n'est pas accordée au rôle de l'utilisateur.
 */
function require_permission(string $permission, ?int $documentId = null): void
{
    if (current_user() === null) {
        require_login();
    }
    if (can($permission)) {
        return;
    }

    $user = current_user();
    journaliser('ACCESS_DENIED', $documentId, [
        'reason' => 'RBAC',
        'required_permission' => $permission,
        'role' => $user['role_nom'] ?? 'inconnu',
        'path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    ]);

    render_error(
        403,
        'Accès refusé',
        sprintf(
            "Votre rôle « %s » ne dispose pas de la permission « %s ». "
            . "Cette tentative a été enregistrée dans le journal d'audit.",
            $user['role_nom'] ?? 'inconnu',
            $permission
        )
    );
}
