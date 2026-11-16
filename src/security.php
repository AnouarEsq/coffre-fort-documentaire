<?php
/**
 * Fonctions transverses de sécurité : sessions, CSRF, échappement, erreurs.
 */
declare(strict_types=1);

/** Échappement systématique de toute donnée affichée (anti-XSS). */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Lit une entrée de requête en garantissant une chaîne.
 *
 * Si le client envoie un tableau (ex. ?action[]=x), la valeur est ignorée
 * au lieu de provoquer une conversion « Array to string » et une fuite
 * d'avertissement PHP dans la réponse.
 *
 * @param array<string,mixed> $source  Typiquement $_GET ou $_POST.
 */
function input_string(array $source, string $key, string $default = ''): string
{
    $value = $source[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** En-têtes de sécurité appliqués à toutes les réponses HTML de l'application. */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header(
        "Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
        . "style-src 'self'; script-src 'self'; object-src 'none'; "
        . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
    );
    header('Cache-Control: no-store, private');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Démarre une session durcie :
 *  - cookie HttpOnly, SameSite=Lax, Secure si HTTPS ;
 *  - use_strict_mode pour refuser les identifiants non générés par le serveur ;
 *  - use_only_cookies pour interdire la session dans l'URL ;
 *  - régénération périodique de l'ID (en plus de celle après login).
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string) config('session.lifetime', 1800));
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '5');

    session_name((string) config('session.name', 'COFFRE_SESSID'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now = time();
    $last = (int) ($_SESSION['_last_regeneration'] ?? 0);
    if ($last !== 0 && ($now - $last) > 900) {
        session_regenerate_id(true);
        $_SESSION['_last_regeneration'] = $now;
    }
}

/** Jeton CSRF lié à la session (32 octets aléatoires). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_validate(): bool
{
    $provided = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    return is_string($provided)
        && is_string($expected)
        && $expected !== ''
        && hash_equals($expected, $provided);
}

/** Refuse la requête si le jeton CSRF est absent/invalide (et journalise). */
function require_csrf(): void
{
    if (!csrf_validate()) {
        journaliser('ACCESS_DENIED', null, [
            'reason' => 'CSRF_TOKEN_INVALID',
            'path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        ]);
        render_error(
            403,
            'Requête refusée',
            "Jeton CSRF absent ou invalide. Rechargez la page et réessayez."
        );
    }
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        render_error(405, 'Méthode non autorisée', 'Cette action nécessite une requête POST.');
    }
}

function client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($ip) || $ip === '') {
        return null;
    }
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 302);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flashes'][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type:string,message:string}> */
function consume_flashes(): array
{
    $flashes = $_SESSION['_flashes'] ?? [];
    unset($_SESSION['_flashes']);
    return is_array($flashes) ? $flashes : [];
}

function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' o';
    }
    $units = ['Ko', 'Mo', 'Go', 'To'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'To') {
            return number_format($value, $value < 10 ? 1 : 0, ',', ' ') . ' ' . $unit;
        }
        $value /= 1024;
    }
    return $bytes . ' o';
}

function format_datetime(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    try {
        $date = new DateTimeImmutable($value);
        $date = $date->setTimezone(new DateTimeZone((string) config('timezone', 'Europe/Paris')));
        return $date->format('d/m/Y H:i:s');
    } catch (Throwable) {
        return e($value);
    }
}

/**
 * Page d'erreur autonome (aucune donnée utilisateur non échappée).
 * Utilisée notamment pour les refus RBAC et les erreurs d'intégrité.
 */
function render_error(int $status, string $title, string $message): never
{
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        send_security_headers();
    }
    $isLogged = function_exists('current_user') && current_user() !== null;
    $home = $isLogged ? '/dashboard.php' : '/login.php';
    $homeLabel = $isLogged ? 'Retour au tableau de bord' : 'Se connecter';
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' — ' . e((string) config('app.name')) . '</title>';
    echo '<link rel="stylesheet" href="/assets/style.css"></head><body class="error-page">';
    echo '<main class="error-card"><p class="error-code">' . $status . '</p>';
    echo '<h1>' . e($title) . '</h1><p>' . e($message) . '</p>';
    echo '<a class="btn btn-primary" href="' . e($home) . '">' . e($homeLabel) . '</a>';
    echo '</main></body></html>';
    exit;
}
