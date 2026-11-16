<?php
/**
 * Connexion PostgreSQL via PDO.
 *
 * Toutes les requêtes de l'application utilisent des requêtes préparées
 * (aucune concaténation de valeurs dans le SQL).
 */
declare(strict_types=1);

function db_dsn(string $database): string
{
    $host = (string) config('db.host');
    $port = (int) config('db.port');
    return sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);
}

/**
 * Fabrique une connexion PDO sûre.
 */
function make_pdo(string $database, string $user, string $password): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_PERSISTENT => false,
    ];

    $pdo = new PDO(db_dsn($database), $user, $password, $options);
    $pdo->exec("SET client_encoding = 'UTF8'");
    $timezone = (string) config('timezone', 'Europe/Paris');
    if (preg_match('/^[A-Za-z0-9_+\-\/]+$/', $timezone)) {
        $pdo->exec('SET TIME ZONE ' . $pdo->quote($timezone));
    }
    $pdo->exec("SET application_name = 'coffre_fort_web'");
    return $pdo;
}

/** Connexion applicative (singleton par requête). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $pdo = make_pdo(
            (string) config('db.name'),
            (string) config('db.user'),
            (string) config('db.password')
        );
        return $pdo;
    } catch (PDOException $exception) {
        error_log('Connexion PostgreSQL impossible : ' . $exception->getMessage());
        if (PHP_SAPI === 'cli') {
            throw new RuntimeException(
                'Connexion PostgreSQL impossible. Vérifiez .env et que la base est initialisée.',
                0,
                $exception
            );
        }
        render_error(
            503,
            'Base de données indisponible',
            "Le service est momentanément indisponible. Vérifiez la configuration PostgreSQL puis réessayez."
        );
    }
}

/** Connexion d'administration (uniquement pour scripts/init_db.php). */
function db_admin(): PDO
{
    return make_pdo(
        'postgres',
        (string) config('db.admin_user'),
        (string) config('db.admin_password')
    );
}
