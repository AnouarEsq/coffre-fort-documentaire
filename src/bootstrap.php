<?php
/**
 * Amorçage commun à toutes les pages web et scripts CLI.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/crypto_bridge.php';
require_once __DIR__ . '/documents.php';

date_default_timezone_set((string) config('timezone', 'Europe/Paris'));

// Le module Python hérite de ces variables : chemin du keystore et, si besoin,
// phrase de passe OpenSSL. Elles ne sont jamais exposées au navigateur.
putenv('COFFRE_MASTER_KEY_FILE=' . (string) config('crypto.key_file'));
$keyPassphrase = (string) config('crypto.key_passphrase');
if ($keyPassphrase !== '') {
    putenv('COFFRE_KEY_PASSPHRASE=' . $keyPassphrase);
}
putenv('PYTHONUTF8=1');

// Création des dossiers de stockage avec permissions restrictives.
foreach (['base', 'encrypted', 'tmp', 'uploads'] as $key) {
    $dir = (string) config('storage.' . $key);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
}

$isWeb = PHP_SAPI !== 'cli';
if ($isWeb) {
    error_reporting(E_ALL);
    ini_set('display_errors', config('app.debug') ? '1' : '0');
    ini_set('log_errors', '1');
    send_security_headers();
    start_secure_session();
}
