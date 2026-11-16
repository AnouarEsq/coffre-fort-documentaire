<?php
/**
 * Gestion des utilisateurs (permission user.manage, réservée à l'admin).
 * Les mots de passe sont hachés avec bcrypt (coût 12) et jamais affichés.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

require_login();
require_permission('user.manage');

$errors = [];
$form = ['nom' => '', 'email' => '', 'role_id' => ''];

$roles = db()->query('SELECT id, nom FROM roles ORDER BY nom')->fetchAll();
$validRoleIds = array_map(static fn(array $role): int => (int) $role['id'], $roles);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $form['nom'] = trim(input_string($_POST, 'nom'));
    $form['email'] = trim(input_string($_POST, 'email'));
    $form['role_id'] = input_string($_POST, 'role_id');
    $password = input_string($_POST, 'password');
    $passwordConfirm = input_string($_POST, 'password_confirm');
    $roleId = (int) $form['role_id'];

    if ($form['nom'] === '' || strlen($form['nom']) > 100) {
        $errors[] = 'Le nom est obligatoire (100 caractères maximum).';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 255) {
        $errors[] = 'Adresse e-mail invalide.';
    }
    if (!in_array($roleId, $validRoleIds, true)) {
        $errors[] = 'Rôle invalide.';
    }
    if (strlen($password) < 12
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/\d/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        $errors[] = 'Le mot de passe doit contenir au moins 12 caractères, avec majuscule, minuscule, chiffre et caractère spécial.';
    }
    if ($password !== $passwordConfirm) {
        $errors[] = 'La confirmation du mot de passe ne correspond pas.';
    }

    if ($errors === []) {
        try {
            $stmt = db()->prepare(
                'INSERT INTO users (nom, email, password_hash, role_id)
                 VALUES (:nom, :email, :password_hash, :role_id)
                 RETURNING id'
            );
            $stmt->bindValue(':nom', $form['nom'], PDO::PARAM_STR);
            $stmt->bindValue(':email', strtolower($form['email']), PDO::PARAM_STR);
            $stmt->bindValue(
                ':password_hash',
                password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                PDO::PARAM_STR
            );
            $stmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $stmt->execute();
            $newId = (int) $stmt->fetchColumn();
            error_log('Utilisateur créé #' . $newId . ' par admin #' . (int) current_user()['id']);
            flash('success', 'Utilisateur créé avec succès.');
            redirect('/users.php');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23505') {
                $errors[] = 'Un compte existe déjà avec cette adresse e-mail.';
            } else {
                error_log('Création utilisateur : ' . $exception->getMessage());
                $errors[] = 'La création du compte a échoué.';
            }
        }
    }
}

$users = db()->query(
    'SELECT u.id, u.nom, u.email, u.created_at, r.nom AS role_nom
       FROM users u
       JOIN roles r ON r.id = u.role_id
      ORDER BY u.created_at DESC, u.id DESC'
)->fetchAll();

render_header('Utilisateurs', 'Créez des comptes et attribuez-leur un rôle. Les permissions découlent de la matrice RBAC.');
?>
<div class="two-col two-col-wide">
    <section class="panel">
        <h2 class="section-title">Comptes existants</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th scope="col">Nom</th>
                    <th scope="col">E-mail</th>
                    <th scope="col">Rôle</th>
                    <th scope="col">Créé le</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $item): ?>
                    <tr>
                        <td><span class="cell-strong"><?= e($item['nom']) ?></span></td>
                        <td><?= e($item['email']) ?></td>
                        <td><span class="role-badge role-<?= e($item['role_nom']) ?>"><?= e($item['role_nom']) ?></span></td>
                        <td class="nowrap"><?= e(format_datetime((string) $item['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="panel">
        <h2 class="section-title">Nouveau compte</h2>
        <?php foreach ($errors as $message): ?>
            <div class="alert alert-error" role="alert"><?= e($message) ?></div>
        <?php endforeach; ?>
        <form method="post" action="/users.php" class="stack">
            <?= csrf_field() ?>
            <div class="field">
                <label for="nom">Nom complet</label>
                <input type="text" id="nom" name="nom" value="<?= e($form['nom']) ?>" required maxlength="100">
            </div>
            <div class="field">
                <label for="email">Adresse e-mail</label>
                <input type="email" id="email" name="email" value="<?= e($form['email']) ?>" required maxlength="255">
            </div>
            <div class="field">
                <label for="role_id">Rôle</label>
                <select id="role_id" name="role_id" required>
                    <option value="">— Choisir —</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= (int) $role['id'] ?>"<?= $form['role_id'] === (string) $role['id'] ? ' selected' : '' ?>>
                            <?= e($role['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="password">Mot de passe initial</label>
                <input type="password" id="password" name="password" required minlength="12" autocomplete="new-password">
                <p class="field-hint">12 caractères minimum, avec majuscule, minuscule, chiffre et caractère spécial.</p>
            </div>
            <div class="field">
                <label for="password_confirm">Confirmation</label>
                <input type="password" id="password_confirm" name="password_confirm" required minlength="12" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary">Créer le compte</button>
        </form>
        <p class="security-note">Le mot de passe est haché avec bcrypt (coût 12) avant insertion.
            Il n’est jamais stocké ni journalisé en clair.</p>
    </aside>
</div>
<?php render_footer(); ?>
