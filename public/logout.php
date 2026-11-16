<?php
/** Déconnexion : POST + CSRF, destruction complète de la session. */
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

if (current_user() === null) {
    redirect('/login.php');
}
require_post();
require_csrf();
logout_user();
redirect('/login.php');
