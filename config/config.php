<?php
/**
 * Configuration centralisée du coffre-fort.
 *
 * Toutes les valeurs sensibles viennent de l'environnement (.env non versionné
 * ou variables du serveur web). Aucun secret n'est écrit en dur ici.
 */
declare(strict_types=1);

/**
 * Charge un fichier .env minimaliste (KEY=VALUE) sans écraser les variables
 * déjà définies par le serveur. Les commentaires (#) et lignes vides sont
 * ignorés. Les guillemets simples/doubles englobants sont retirés.
 */
function load_dotenv(string $file): void
{
    if (!is_readable($file)) {
        return;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
            continue;
        }
        $value = trim($value);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function env_value(string $key, string $default = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function env_bool(string $key, bool $default = false): bool
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

$root = dirname(__DIR__);
load_dotenv($root . '/.env');

$config = [
    'root' => $root,
    'timezone' => env_value('APP_TIMEZONE', 'Europe/Paris'),

    'app' => [
        'name' => env_value('APP_NAME', 'Coffre-fort documentaire'),
        'debug' => env_bool('APP_DEBUG', false),
        'demo_mode' => env_bool('APP_DEMO_MODE', false),
    ],

    'db' => [
        'host' => env_value('DB_HOST', '127.0.0.1'),
        'port' => (int) env_value('DB_PORT', '5432'),
        'name' => env_value('DB_NAME', 'coffre_fort'),
        'user' => env_value('DB_USER', 'coffre_app'),
        'password' => env_value('DB_PASSWORD', ''),
        // Compte administrateur utilisé UNIQUEMENT par scripts/init_db.php.
        'admin_user' => env_value('DB_ADMIN_USER', 'postgres'),
        'admin_password' => env_value('DB_ADMIN_PASSWORD', ''),
    ],

    'python' => [
        'bin' => env_value('PYTHON_BIN', 'python'),
        'timeout' => (int) env_value('CRYPTO_TIMEOUT', '60'),
    ],

    'crypto' => [
        'dir' => $root . '/crypto',
        'key_file' => env_value('COFFRE_MASTER_KEY_FILE', $root . '/crypto/keystore/master.key'),
        'key_passphrase' => env_value('COFFRE_KEY_PASSPHRASE', ''),
    ],

    'storage' => [
        'base' => $root . '/storage',
        'encrypted' => $root . '/storage/encrypted',
        'tmp' => $root . '/storage/tmp',
        'uploads' => $root . '/storage/uploads',
    ],

    'upload' => [
        'max_bytes' => (int) env_value('UPLOAD_MAX_BYTES', (string) (10 * 1024 * 1024)),
    ],

    'session' => [
        'name' => env_value('SESSION_NAME', 'COFFRE_SESSID'),
        'lifetime' => (int) env_value('SESSION_LIFETIME', '1800'),
    ],
];

$GLOBALS['COFFRE_CONFIG'] = $config;

/**
 * Accès à la configuration par chemin pointé : config('db.host').
 */
function config(?string $path = null, mixed $default = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = $GLOBALS['COFFRE_CONFIG'] ?? require __DIR__ . '/config.php';
        $GLOBALS['COFFRE_CONFIG'] = $cfg;
    }
    if ($path === null || $path === '') {
        return $cfg;
    }
    $value = $cfg;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

return $config;
