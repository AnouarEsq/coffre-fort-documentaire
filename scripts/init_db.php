<?php
/**
 * Initialisation PostgreSQL (à exécuter une fois).
 *
 *    php scripts/init_db.php
 *
 * Le script utilise le compte d'administration (DB_ADMIN_*) pour :
 *   1. créer le rôle applicatif et la base s'ils n'existent pas ;
 *   2. appliquer schema_coffre_fort.sql ;
 *   3. appliquer le durcissement append-only et les extensions.
 *
 * Ensuite, se connecter avec le rôle applicatif (DB_USER) pour créer les
 * tables : l'application n'a pas besoin des droits superutilisateur.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script s’exécute uniquement en ligne de commande.');
}

require_once __DIR__ . '/../src/bootstrap.php';

$dbName = (string) config('db.name');
$appUser = (string) config('db.user');
$appPassword = (string) config('db.password');

foreach (['base' => $dbName, 'rôle' => $appUser] as $label => $identifier) {
    if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $identifier)) {
        fwrite(STDERR, "Identifiant de {$label} invalide : {$identifier}\n");
        exit(1);
    }
}
if ($appPassword === '') {
    fwrite(STDERR, "DB_PASSWORD est vide. Renseignez un mot de passe applicatif dans .env.\n");
    exit(1);
}

try {
    $admin = db_admin();

    // --- Rôle applicatif ---------------------------------------------------
    $stmt = $admin->prepare('SELECT 1 FROM pg_roles WHERE rolname = :name');
    $stmt->execute([':name' => $appUser]);
    $quotedRole = '"' . $appUser . '"';
    $quotedPassword = $admin->quote($appPassword);
    if ($stmt->fetchColumn() === false) {
        $admin->exec("CREATE ROLE {$quotedRole} LOGIN PASSWORD {$quotedPassword}");
        echo "Rôle applicatif créé : {$appUser}\n";
    } else {
        $admin->exec("ALTER ROLE {$quotedRole} WITH LOGIN PASSWORD {$quotedPassword}");
        echo "Rôle applicatif existant, mot de passe synchronisé : {$appUser}\n";
    }

    // --- Base de données ----------------------------------------------------
    $stmt = $admin->prepare(
        'SELECT pg_get_userbyid(datdba) AS owner FROM pg_database WHERE datname = :name'
    );
    $stmt->execute([':name' => $dbName]);
    $database = $stmt->fetch();
    $quotedDb = '"' . $dbName . '"';
    if ($database === false) {
        $admin->exec(
            "CREATE DATABASE {$quotedDb} OWNER {$quotedRole} "
            . "ENCODING 'UTF8' TEMPLATE template0"
        );
        echo "Base créée : {$dbName} (propriétaire {$appUser})\n";
    } else {
        if (strcasecmp((string) $database['owner'], $appUser) !== 0) {
            $admin->exec("ALTER DATABASE {$quotedDb} OWNER TO {$quotedRole}");
            echo "Propriétaire de la base ajusté à {$appUser}\n";
        }
        echo "Base existante : {$dbName}\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "ERREUR d'initialisation : " . $exception->getMessage() . "\n");
    fwrite(STDERR, "Vérifiez DB_ADMIN_USER / DB_ADMIN_PASSWORD et que PostgreSQL est démarré.\n");
    exit(1);
}

try {
    $pdo = make_pdo($dbName, $appUser, $appPassword);

    $alreadyInitialized = $pdo->query("SELECT to_regclass('public.roles')")->fetchColumn();
    if ($alreadyInitialized === false || $alreadyInitialized === null) {
        $schemaFile = dirname(__DIR__) . '/schema_coffre_fort.sql';
        $schema = file_get_contents($schemaFile);
        if ($schema === false) {
            throw new RuntimeException('Lecture de schema_coffre_fort.sql impossible.');
        }
        $pdo->exec($schema);
        echo "Schéma principal appliqué.\n";
    } else {
        echo "Schéma principal déjà présent (aucune recréation).\n";
    }

    foreach (['schema_hardening.sql', 'schema_extensions.sql'] as $file) {
        $sql = file_get_contents(dirname(__DIR__) . '/' . $file);
        if ($sql === false) {
            throw new RuntimeException('Lecture impossible : ' . $file);
        }
        $pdo->exec($sql);
        echo "Appliqué : {$file}\n";
    }

    $counts = [];
    foreach (['roles', 'permissions', 'role_permissions', 'users', 'documents', 'audit_log'] as $table) {
        $counts[$table] = (int) $pdo->query('SELECT count(*) FROM ' . $table)->fetchColumn();
    }
    echo "\nÉtat de la base :\n";
    foreach ($counts as $table => $count) {
        printf("  %-18s %d\n", $table, $count);
    }
    echo "\nInitialisation terminée. Étape suivante : php scripts/seed_demo.php\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "ERREUR d'application du schéma : " . $exception->getMessage() . "\n");
    exit(1);
}
