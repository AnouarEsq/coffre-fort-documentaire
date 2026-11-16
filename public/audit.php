<?php
/**
 * Journal d'audit (Module F) — consultation en lecture seule.
 *
 * Filtres : date, utilisateur, action, document, adresse IP.
 * Aucun formulaire de modification/suppression n'existe ; les triggers
 * PostgreSQL bloquent toute mutation de audit_log.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

require_login();
require_permission('audit.read');

$actionLabels = [
    'LOGIN' => 'Connexion',
    'UPLOAD' => 'Dépôt',
    'VIEW' => 'Consultation',
    'DOWNLOAD' => 'Téléchargement',
    'DELETE' => 'Retrait',
    'LOGIN_FAILED' => 'Échec de connexion',
    'ACCESS_DENIED' => 'Accès refusé',
];
$allowedActions = array_keys($actionLabels);

$action = input_string($_GET, 'action');
if (!in_array($action, $allowedActions, true)) {
    $action = '';
}

$userId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userId = ($userId === false || $userId === null) ? null : (int) $userId;
$documentId = filter_input(INPUT_GET, 'document_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$documentId = ($documentId === false || $documentId === null) ? null : (int) $documentId;

$ip = trim(input_string($_GET, 'ip'));
if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
    $ip = '';
}

$dateDebut = trim(input_string($_GET, 'date_debut'));
$dateFin = trim(input_string($_GET, 'date_fin'));

$isValidDate = static function (string $value): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
if (!$isValidDate($dateDebut)) {
    $dateDebut = '';
}
if (!$isValidDate($dateFin)) {
    $dateFin = '';
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = ($page === false || $page === null) ? 1 : (int) $page;
$perPage = 50;

$where = [];
$params = [];
if ($action !== '') {
    $where[] = 'a.action = CAST(:action AS action_type)';
    $params[':action'] = $action;
}
if ($userId !== null) {
    $where[] = 'a.user_id = :user_id';
    $params[':user_id'] = $userId;
}
if ($documentId !== null) {
    $where[] = 'a.document_id = :document_id';
    $params[':document_id'] = $documentId;
}
if ($ip !== '') {
    $where[] = 'a.ip_address = CAST(:ip AS inet)';
    $params[':ip'] = $ip;
}
if ($dateDebut !== '') {
    $where[] = 'a.created_at >= :date_debut';
    $params[':date_debut'] = $dateDebut . ' 00:00:00';
}
if ($dateFin !== '') {
    $end = (new DateTimeImmutable($dateFin))->modify('+1 day');
    $where[] = 'a.created_at < :date_fin';
    $params[':date_fin'] = $end->format('Y-m-d') . ' 00:00:00';
}
$whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

$countStmt = db()->prepare('SELECT count(*) FROM audit_log a ' . $whereSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$countStmt->execute();
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$sql = 'SELECT a.id, a.user_id, a.action, a.document_id, a.ip_address, a.details,
               a.created_at, u.nom AS user_nom, u.email AS user_email,
               d.nom_original
          FROM audit_log a
          LEFT JOIN users u ON u.id = a.user_id
          LEFT JOIN documents d ON d.id = a.document_id
        ' . $whereSql . '
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT :limit OFFSET :offset';
$stmt = db()->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$users = db()->query(
    'SELECT id, nom, email FROM users ORDER BY nom, email'
)->fetchAll();
$documents = db()->query(
    'SELECT id, nom_original FROM documents ORDER BY created_at DESC'
)->fetchAll();

$queryBase = $_GET;
unset($queryBase['page']);
$buildPageUrl = static function (int $target) use ($queryBase): string {
    $queryBase['page'] = $target;
    return '/audit.php?' . http_build_query($queryBase);
};

render_header('Journal d’audit', 'Traçabilité complète des opérations — lecture seule, aucun événement ne peut être modifié.');
?>
<div class="alert alert-info">
    Le journal est <strong>append-only</strong> : l’application n’exécute que des
    <code>INSERT</code>, et des triggers PostgreSQL rejettent toute opération
    <code>UPDATE</code>, <code>DELETE</code> ou <code>TRUNCATE</code>.
</div>

<form method="get" action="/audit.php" class="filter-panel">
    <div class="filter-grid">
        <div class="field">
            <label for="date_debut">Du</label>
            <input type="date" id="date_debut" name="date_debut" value="<?= e($dateDebut) ?>">
        </div>
        <div class="field">
            <label for="date_fin">Au</label>
            <input type="date" id="date_fin" name="date_fin" value="<?= e($dateFin) ?>">
        </div>
        <div class="field">
            <label for="action">Action</label>
            <select id="action" name="action">
                <option value="">Toutes</option>
                <?php foreach ($actionLabels as $code => $label): ?>
                    <option value="<?= e($code) ?>"<?= $action === $code ? ' selected' : '' ?>>
                        <?= e($label) ?> (<?= e($code) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="user_id">Utilisateur</label>
            <select id="user_id" name="user_id">
                <option value="">Tous</option>
                <?php foreach ($users as $item): ?>
                    <option value="<?= (int) $item['id'] ?>"<?= $userId === (int) $item['id'] ? ' selected' : '' ?>>
                        <?= e($item['nom']) ?> — <?= e($item['email']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="document_id">Document</label>
            <select id="document_id" name="document_id">
                <option value="">Tous</option>
                <?php foreach ($documents as $item): ?>
                    <option value="<?= (int) $item['id'] ?>"<?= $documentId === (int) $item['id'] ? ' selected' : '' ?>>
                        #<?= (int) $item['id'] ?> — <?= e($item['nom_original']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="ip">Adresse IP</label>
            <input type="text" id="ip" name="ip" value="<?= e($ip) ?>" placeholder="127.0.0.1">
        </div>
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary">Filtrer</button>
        <a class="btn btn-ghost" href="/audit.php">Réinitialiser</a>
        <span class="filter-count"><?= $total ?> événement<?= $total > 1 ? 's' : '' ?></span>
    </div>
</form>

<?php if ($rows === []): ?>
    <div class="empty-state">
        <span class="empty-icon"><?= icon_vault() ?></span>
        <p class="empty-title">Aucun événement</p>
        <p>Aucune entrée ne correspond aux filtres sélectionnés.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table audit-table">
            <thead>
            <tr>
                <th scope="col">Horodatage</th>
                <th scope="col">Utilisateur</th>
                <th scope="col">Action</th>
                <th scope="col">Document</th>
                <th scope="col">IP</th>
                <th scope="col">Détails</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $code = (string) $row['action'];
                $label = $actionLabels[$code] ?? $code;
                $badgeClass = 'action-' . strtolower(str_replace('_', '-', $code));
                $details = '';
                if (is_string($row['details']) && $row['details'] !== '') {
                    $decoded = json_decode($row['details'], true);
                    $details = $decoded === null
                        ? (string) $row['details']
                        : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                ?>
                <tr>
                    <td class="nowrap"><?= e(format_datetime((string) $row['created_at'])) ?></td>
                    <td>
                        <?php if ($row['user_id'] !== null): ?>
                            <span class="cell-strong"><?= e($row['user_nom'] ?? 'Utilisateur supprimé') ?></span>
                            <span class="cell-muted"><?= e($row['user_email'] ?? ('#' . $row['user_id'])) ?></span>
                        <?php else: ?>
                            <span class="cell-muted">Anonyme / non authentifié</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="action-badge <?= e($badgeClass) ?>"><?= e($label) ?></span>
                        <span class="cell-muted mono"><?= e($code) ?></span>
                    </td>
                    <td>
                        <?php if ($row['document_id'] !== null): ?>
                            <?php if (can('document.view')): ?>
                                <a href="/view.php?id=<?= (int) $row['document_id'] ?>">#<?= (int) $row['document_id'] ?></a>
                            <?php else: ?>
                                <span>#<?= (int) $row['document_id'] ?></span>
                            <?php endif; ?>
                            <span class="cell-muted"><?= e($row['nom_original'] ?? 'Document retiré') ?></span>
                        <?php else: ?>
                            <span class="cell-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="mono nowrap"><?= e($row['ip_address'] ?? '—') ?></td>
                    <td>
                        <?php if ($details !== ''): ?>
                            <details class="details-cell">
                                <summary>Voir le JSON</summary>
                                <pre><?= e($details) ?></pre>
                            </details>
                        <?php else: ?>
                            <span class="cell-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Pagination du journal">
            <?php if ($page > 1): ?>
                <a class="btn btn-sm btn-secondary" href="<?= e($buildPageUrl($page - 1)) ?>">← Précédent</a>
            <?php endif; ?>
            <span>Page <?= $page ?> sur <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a class="btn btn-sm btn-secondary" href="<?= e($buildPageUrl($page + 1)) ?>">Suivant →</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
<?php render_footer(); ?>
