<?php
/**
 * Extrait la matrice des accès réellement stockée en base et génère
 * docs/MATRICE_ACCES.md (livrable 3).
 *
 *    php scripts/export_matrix.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script s’exécute uniquement en ligne de commande.');
}

require_once __DIR__ . '/../src/bootstrap.php';

$roles = db()->query('SELECT id, nom FROM roles ORDER BY id')->fetchAll();
$permissions = db()->query('SELECT id, code, description FROM permissions ORDER BY id')->fetchAll();
$matrixRows = db()->query('SELECT role_id, permission_id FROM role_permissions')->fetchAll();

$matrix = [];
foreach ($matrixRows as $row) {
    $matrix[(int) $row['role_id']][(int) $row['permission_id']] = true;
}

$lines = [];
$lines[] = '# Matrice des accès (extraite de la base)';
$lines[] = '';
$lines[] = '> Généré automatiquement le ' . date('d/m/Y à H:i') . ' depuis PostgreSQL ('
    . config('db.name') . ').';
$lines[] = '> Source de vérité : tables `roles`, `permissions` et `role_permissions`.';
$lines[] = '';
$lines[] = '| Permission | ' . implode(' | ', array_map(
    static fn(array $role): string => '`' . $role['nom'] . '`',
    $roles
)) . ' |';
$lines[] = '|---|' . implode('|', array_fill(0, count($roles), ':---:')) . '|';
foreach ($permissions as $permission) {
    $cells = [];
    foreach ($roles as $role) {
        $cells[] = isset($matrix[(int) $role['id']][(int) $permission['id']]) ? '✅' : '—';
    }
    $lines[] = '| `' . $permission['code'] . '`<br>' . str_replace('|', '\\|', (string) $permission['description'])
        . ' | ' . implode(' | ', $cells) . ' |';
}
$lines[] = '';
$lines[] = '## Synthèse';
$lines[] = '';
foreach ($roles as $role) {
    $count = 0;
    foreach ($permissions as $permission) {
        if (isset($matrix[(int) $role['id']][(int) $permission['id']])) {
            $count++;
        }
    }
    $lines[] = '- **' . $role['nom'] . '** : ' . $count . ' permission(s) sur ' . count($permissions) . '.';
}
$lines[] = '';
$lines[] = '## Règles d’application';
$lines[] = '';
$lines[] = '- Chaque page sensible appelle `require_login()` puis `require_permission($code)`.';
$lines[] = '- La fonction `can($code)` interroge la base à chaque requête (pas de droits codés en dur côté page).';
$lines[] = '- Tout refus est journalisé dans `audit_log` avec l’action `ACCESS_DENIED`, l’utilisateur, l’IP et l’horodatage.';
$lines[] = '- Le rôle `auditeur` ne peut que lire le journal (`audit.read`) ; il ne voit aucun document.';
$lines[] = '';
$lines[] = '## Commande de régénération';
$lines[] = '';
$lines[] = '```bash';
$lines[] = 'php scripts/export_matrix.php';
$lines[] = '```';
$lines[] = '';

$target = dirname(__DIR__) . '/docs/MATRICE_ACCES.md';
if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
    fwrite(STDERR, "Impossible de créer le dossier docs/.\n");
    exit(1);
}
if (file_put_contents($target, implode(PHP_EOL, $lines)) === false) {
    fwrite(STDERR, "Écriture impossible : {$target}\n");
    exit(1);
}

echo 'Matrice exportée : ' . $target . PHP_EOL;
foreach ($roles as $role) {
    $count = 0;
    foreach ($permissions as $permission) {
        if (isset($matrix[(int) $role['id']][(int) $permission['id']])) {
            $count++;
        }
    }
    printf("  %-12s %d/%d permissions\n", $role['nom'], $count, count($permissions));
}
