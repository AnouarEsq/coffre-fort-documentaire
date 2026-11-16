<?php
/**
 * Authentification et gestion de l'identité en session.
 */
declare(strict_types=1);

/** Utilisateur connecté (données strictement issues de la session serveur). */
function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || !isset($user['id'], $user['role_id'], $user['email'])) {
        return null;
    }
    return $user;
}

/**
 * Ouvre la session applicative après vérification du mot de passe.
 * L'ID de session est TOUJOURS régénéré pour éviter la fixation de session.
 */
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = [
        'user' => [
            'id' => (int) $user['id'],
            'nom' => (string) $user['nom'],
            'email' => (string) $user['email'],
            'role_id' => (int) $user['role_id'],
            'role_nom' => (string) $user['role_nom'],
        ],
        'login_at' => time(),
        '_last_regeneration' => time(),
        'csrf_token' => bin2hex(random_bytes(32)),
    ];
}

/** Détruit complètement la session et son cookie. */
function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

/**
 * Garde réutilisable : refuse toute page sans session authentifiée.
 * L'accès direct à une URL protégée est journalisé (ACCESS_DENIED, user NULL).
 */
function require_login(): void
{
    if (current_user() !== null) {
        return;
    }

    journaliser('ACCESS_DENIED', null, [
        'reason' => 'NO_SESSION',
        'path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    ], null);

    flash('warning', 'Veuillez vous authentifier pour accéder à cette page.');
    redirect('/login.php');
}
