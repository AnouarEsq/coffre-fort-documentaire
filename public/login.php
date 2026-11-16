<?php
/**
 * Authentification : bcrypt (password_verify), messages génériques, CSRF,
 * régénération d'ID de session et journalisation LOGIN / LOGIN_FAILED.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

if (current_user() !== null) {
    redirect('/dashboard.php');
}

$error = '';
$email = '';

// Hash factice pour égaliser approximativement le temps de réponse lorsque
// l'adresse e-mail n'existe pas (limite l'énumération de comptes).
const DUMMY_BCRYPT_HASH = '$2y$12$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $email = trim(input_string($_POST, 'email'));
    $password = input_string($_POST, 'password');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Email ou mot de passe incorrect.';
        journaliser('LOGIN_FAILED', null, [
            'email' => $email,
            'reason' => 'INVALID_INPUT',
        ], null);
    } else {
        $stmt = db()->prepare(
            'SELECT u.id, u.nom, u.email, u.password_hash, u.role_id, r.nom AS role_nom
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE lower(u.email) = lower(:email)
              LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        $hash = is_array($user) ? (string) $user['password_hash'] : DUMMY_BCRYPT_HASH;
        $valid = password_verify($password, $hash);

        if (is_array($user) && $valid) {
            if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])) {
                $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $update = db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                $update->execute([':hash' => $newHash, ':id' => (int) $user['id']]);
            }

            login_user($user);
            journaliser('LOGIN', null, [
                'role' => $user['role_nom'],
                'success' => true,
            ]);
            flash('success', 'Connexion réussie. Bienvenue ' . $user['nom'] . '.');
            redirect('/dashboard.php');
        }

        $error = 'Email ou mot de passe incorrect.';
        journaliser('LOGIN_FAILED', null, [
            'email' => $email,
            'reason' => is_array($user) ? 'BAD_PASSWORD' : 'UNKNOWN_ACCOUNT',
        ], is_array($user) ? (int) $user['id'] : null);
    }
}

$flashes = consume_flashes();
render_auth_header('Connexion');
foreach ($flashes as $flash) {
    echo '<div class="alert alert-' . e($flash['type']) . '" role="status">' . e($flash['message']) . '</div>';
}
if ($error !== '') {
    echo '<div class="alert alert-error" role="alert">' . e($error) . '</div>';
}
?>
<form method="post" action="/login.php" class="stack" autocomplete="on" novalidate>
    <?= csrf_field() ?>
    <div class="field">
        <label for="email">Adresse e-mail</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>"
               required maxlength="255" autocomplete="username" autofocus
               placeholder="prenom.nom@exemple.fr">
    </div>
    <div class="field">
        <label for="password">Mot de passe</label>
        <input type="password" id="password" name="password" required
               autocomplete="current-password" placeholder="••••••••••••">
    </div>
    <button type="submit" class="btn btn-primary btn-block">Se connecter</button>
    <p class="form-note">Toutes les tentatives de connexion, réussies ou refusées,
        sont enregistrées dans le journal d’audit.</p>
</form>
<?php
render_auth_footer();
