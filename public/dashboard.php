<?php
/**
 * Tableau de bord : documents accessibles, rôle et permissions effectives.
 * Le filtrage d'affichage respecte le principe du moindre privilège : un rôle
 * sans document.view ne voit que ses propres dépôts, sans lien de consultation.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

require_login();
$user = current_user();
$canView = can('document.view');
$canDownload = can('document.download');
$canDelete = can('document.delete');
$canUpload = can('document.upload');

if ($canView) {
    $stmt = db()->prepare(
        'SELECT d.id, d.nom_original, d.taille, d.mime_type, d.created_at,
                u.nom AS uploadeur_nom, u.email AS uploadeur_email
           FROM documents d
           JOIN users u ON u.id = d.uploade_par
          WHERE d.supprime_le IS NULL
          ORDER BY d.created_at DESC, d.id DESC'
    );
    $stmt->execute();
} else {
    $stmt = db()->prepare(
        'SELECT d.id, d.nom_original, d.taille, d.mime_type, d.created_at,
                u.nom AS uploadeur_nom, u.email AS uploadeur_email
           FROM documents d
           JOIN users u ON u.id = d.uploade_par
          WHERE d.supprime_le IS NULL AND d.uploade_par = :user_id
          ORDER BY d.created_at DESC, d.id DESC'
    );
    $stmt->execute([':user_id' => (int) $user['id']]);
}
$documents = $stmt->fetchAll();
$permissions = current_permissions();

// Journal « en direct » : réservé aux rôles disposant de audit.read.
$recentEvents = [];
if (can('audit.read')) {
    $recentEvents = db()->query(
        'SELECT a.action, a.created_at, d.nom_original
           FROM audit_log a
           LEFT JOIN documents d ON d.id = a.document_id
          ORDER BY a.created_at DESC, a.id DESC
          LIMIT 8'
    )->fetchAll();
}

$eventLabels = [
    'LOGIN' => ['Session ouverte', 'ok'],
    'LOGIN_FAILED' => ['Échec de connexion', 'deny'],
    'ACCESS_DENIED' => ['Accès refusé', 'deny'],
    'UPLOAD' => ['Document chiffré', 'ok'],
    'VIEW' => ['Accès autorisé', 'ok'],
    'DOWNLOAD' => ['Téléchargement autorisé', 'ok'],
    'DELETE' => ['Document retiré', 'deny'],
];

render_header('Tableau de bord', 'Vue d’ensemble de votre espace documentaire sécurisé.');
?>
<div class="stat-grid">
    <article class="stat-card">
        <span class="stat-label">Documents accessibles</span>
        <strong class="stat-value"><?= count($documents) ?></strong>
        <span class="stat-hint"><?= $canView ? 'tous les documents actifs' : 'vos dépôts uniquement' ?></span>
    </article>
    <article class="stat-card">
        <span class="stat-label">Rôle applicatif</span>
        <strong class="stat-value role-value role-<?= e($user['role_nom']) ?>"><?= e($user['role_nom']) ?></strong>
        <span class="stat-hint"><?= e($user['email']) ?></span>
    </article>
    <article class="stat-card">
        <span class="stat-label">Permissions effectives</span>
        <strong class="stat-value"><?= count($permissions) ?></strong>
        <span class="stat-hint">matrice RBAC en base</span>
    </article>
</div>

<div class="vault-strip" aria-label="État du coffre">
    <span class="vault-status">Coffre opérationnel</span>
    <span class="chip chip-crypto">AES-256-GCM</span>
    <span class="chip chip-hash">SHA-256</span>
    <span class="chip">nonce 96 bits</span>
    <span class="chip chip-lock">journal append-only</span>
    <span class="chip">RBAC</span>
</div>

<div class="toolbar">
    <h2 class="section-title">Documents</h2>
    <?php if ($canUpload): ?>
        <a class="btn btn-primary" href="/upload.php">Déposer un document</a>
    <?php endif; ?>
</div>

<?php if (!$canView): ?>
    <div class="alert alert-info">
        Votre rôle ne dispose pas de la permission <code>document.view</code>.
        Seuls vos dépôts sont listés, sans possibilité de les consulter ni de les télécharger.
        Toute tentative d’accès direct serait journalisée et refusée.
    </div>
<?php endif; ?>

<?php if ($documents === []): ?>
    <div class="empty-state">
        <span class="empty-icon"><?= icon_vault() ?></span>
        <p class="empty-title">Aucun document — déposez votre premier fichier dans le coffre</p>
        <p>PDF, image ou document bureautique, 10 Mo maximum. Le fichier est chiffré
            en AES-256-GCM et sa copie en clair est supprimée immédiatement.</p>
        <?php if ($canUpload): ?>
            <a class="btn btn-primary" href="/upload.php">Déposer un document</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th scope="col">Document</th>
                <th scope="col">Type</th>
                <th scope="col" class="num">Taille</th>
                <th scope="col">Déposé par</th>
                <th scope="col" class="num">Date</th>
                <th scope="col" class="actions-col">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($documents as $document): ?>
                <?php
                $extension = strtoupper((string) pathinfo((string) $document['nom_original'], PATHINFO_EXTENSION));
                $documentId = (int) $document['id'];
                $hasActions = $canView || $canDownload || $canDelete;
                ?>
                <tr>
                    <td>
                        <span class="doc-name"><?= e($document['nom_original']) ?></span>
                        <span class="doc-id">#<?= $documentId ?></span>
                        <span class="doc-meta">
                            <span class="chip chip-crypto">AES-256</span>
                            <span class="chip chip-hash">SHA-256</span>
                        </span>
                    </td>
                    <td><span class="type-badge"><?= e($extension !== '' ? $extension : 'FICHIER') ?></span></td>
                    <td class="num"><?= e(format_bytes((int) $document['taille'])) ?></td>
                    <td>
                        <span class="cell-strong"><?= e($document['uploadeur_nom']) ?></span>
                        <span class="cell-muted"><?= e($document['uploadeur_email']) ?></span>
                    </td>
                    <td class="nowrap num"><?= e(format_datetime((string) $document['created_at'])) ?></td>
                    <td class="actions-cell">
                        <span class="lock-indicator" aria-hidden="true"><?= icon_lock_both() ?></span>
                        <?php if ($hasActions): ?>
                            <button type="button" class="btn btn-sm btn-secondary lock-toggle"
                                    data-lock-toggle aria-expanded="true"
                                    aria-controls="doc-actions-<?= $documentId ?>" hidden>Déverrouiller</button>
                            <span class="doc-actions" id="doc-actions-<?= $documentId ?>">
                                <?php if ($canView): ?>
                                    <a class="btn btn-sm btn-secondary" href="/view.php?id=<?= $documentId ?>">Consulter</a>
                                <?php endif; ?>
                                <?php if ($canDownload): ?>
                                    <a class="btn btn-sm btn-secondary" href="/download.php?id=<?= $documentId ?>">Télécharger</a>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <form method="post" action="/delete.php" class="inline-form"
                                          data-confirm="Retirer définitivement ce document ? Le fichier chiffré sera supprimé du stockage.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $documentId ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Retirer</button>
                                    </form>
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="cell-muted">Consultation non autorisée</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($recentEvents !== []): ?>
    <section class="terminal-panel" aria-label="Journal d’audit en direct">
        <div class="terminal-head">
            <span class="dot" aria-hidden="true"></span>
            journal en direct
            <span class="terminal-tail"><?= count($recentEvents) ?> derniers événements</span>
        </div>
        <div class="terminal-body">
            <?php foreach ($recentEvents as $event): ?>
                <?php
                $code = (string) $event['action'];
                [$label, $tone] = $eventLabels[$code] ?? [$code, 'info'];
                $target = $event['nom_original'] !== null ? (string) $event['nom_original'] : $code;
                ?>
                <div class="term-line">
                    <span class="term-time"><?= e(format_time((string) $event['created_at'])) ?></span>
                    <span class="term-mark term-<?= e($tone) ?>"><?= $tone === 'deny' ? '✗' : '✓' ?></span>
                    <span class="term-text"><?= e($label) ?> — <span class="term-target"><?= e($target) ?></span></span>
                </div>
            <?php endforeach; ?>
            <div class="term-line">
                <span class="term-time">&nbsp;</span>
                <span class="term-mark term-ok">$</span>
                <span class="term-text term-ok">coffre --audit<span class="term-caret" aria-hidden="true"></span></span>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="panel">
    <h2 class="section-title">Vos permissions</h2>
    <p class="panel-intro">Ces droits sont lus en base à chaque requête dans la table
        <code>role_permissions</code>. Ils ne peuvent pas être modifiés côté navigateur.</p>
    <div class="chip-list">
        <?php foreach ($permissions as $permission): ?>
            <span class="perm-chip"><?= e($permission) ?></span>
        <?php endforeach; ?>
        <?php if ($permissions === []): ?>
            <span class="cell-muted">Aucune permission accordée.</span>
        <?php endif; ?>
    </div>
</section>
<?php render_footer(); ?>
