<?php
/**
 * Gabarit HTML commun (navigation, messages flash, pied de page).
 */
declare(strict_types=1);

function render_flashes(): void
{
    foreach (consume_flashes() as $flash) {
        $type = in_array($flash['type'], ['success', 'warning', 'error', 'info'], true)
            ? $flash['type']
            : 'info';
        echo '<div class="alert alert-' . e($type) . '" role="status">' . e($flash['message']) . '</div>';
    }
}

function format_time(?string $value): string
{
    if ($value === null || $value === '') {
        return '--:--:--';
    }
    try {
        $date = new DateTimeImmutable($value);
        $date = $date->setTimezone(new DateTimeZone((string) config('timezone', 'Europe/Paris')));
        return $date->format('H:i:s');
    } catch (Throwable) {
        return '--:--:--';
    }
}

/** Identifiant de page (classe CSS `page-xxx` posée sur <body>). */
function page_slug(): string
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string) pathinfo($script, PATHINFO_FILENAME)));
    return ($slug === '' || $slug === null) ? 'accueil' : $slug;
}

/**
 * Icônes SVG inline (aucune dépendance, aucune requête réseau).
 * `aria-hidden` : le sens est porté par le texte ou l'attribut `title`.
 */
function icon_lock_both(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        // cadenas ouvert
        . '<g class="lock-open"><rect x="4" y="11" width="16" height="9" rx="2"></rect>'
        . '<path d="M8 11V7a4 4 0 0 1 7.5-2"></path></g>'
        // cadenas fermé
        . '<g class="lock-closed"><rect x="4" y="11" width="16" height="9" rx="2"></rect>'
        . '<path d="M8 11V8a4 4 0 0 1 8 0v3"></path></g>'
        . '</svg>';
}

function icon_vault(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . '<rect x="3" y="4" width="18" height="16" rx="2"></rect>'
        . '<circle cx="12" cy="12" r="4"></circle>'
        . '<path d="M12 8v1M12 15v1M8 12h1M15 12h1"></path>'
        . '<path d="M21 9v6"></path></svg>';
}

/** Bouton de bascule sombre/clair (aria-pressed géré par theme.js). */
function render_theme_toggle(string $class = ''): void
{
    $classes = trim('theme-toggle ' . $class);
    echo '<button type="button" class="' . e($classes) . '" data-theme-toggle'
        . ' aria-pressed="true" aria-label="Passer en thème clair">';
    echo '<svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false">'
        . '<circle cx="12" cy="12" r="4"></circle>'
        . '<path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4'
        . 'M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"></path></svg>';
    echo '<svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true" focusable="false">'
        . '<path d="M21 12.8A8.5 8.5 0 1 1 11.2 3a6.6 6.6 0 0 0 9.8 9.8z"></path></svg>';
    echo '</button>';
}

/**
 * Dépendances front-end communes.
 *
 * Les bibliothèques d'animation sont auto-hébergées dans /assets/vendor :
 * la CSP reste `script-src 'self'`, aucun CDN n'est contacté et la démo
 * fonctionne hors ligne. Elles sont chargées en `defer` et restent une
 * amélioration progressive (voir anim-flag.js).
 */
function render_assets(): void
{
    // Bloquants et en premier : thème (anti-flash) puis drapeau d'animation.
    echo '<script src="/assets/theme.js"></script>';
    echo '<script src="/assets/anim-flag.js"></script>';
    echo '<link rel="stylesheet" href="/assets/vendor/lenis.css">';
    echo '<link rel="stylesheet" href="/assets/style.css">';
    echo '<script src="/assets/vendor/gsap.min.js" defer></script>';
    echo '<script src="/assets/vendor/ScrollTrigger.min.js" defer></script>';
    echo '<script src="/assets/vendor/lenis.min.js" defer></script>';
    echo '<script src="/assets/app.js" defer></script>';
    echo '<script src="/assets/animations.js" defer></script>';
}

function render_header(string $title, string $subtitle = ''): void
{
    $user = current_user();
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $appName = (string) config('app.name');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' — ' . e($appName) . '</title>';
    echo '<meta name="theme-color" content="#0d1117">';
    render_assets();
    echo '</head><body class="app-page page-' . e(page_slug()) . '">';
    echo '<a class="skip-link" href="#contenu">Aller au contenu</a>';
    echo '<header class="topbar"><div class="topbar-inner">';
    echo '<a class="brand" href="/dashboard.php" aria-label="Accueil du coffre-fort">';
    echo '<span class="brand-mark" aria-hidden="true">CF</span>';
    echo '<span class="brand-text">' . e($appName) . '</span></a>';

    echo '<nav class="main-nav" aria-label="Navigation principale">';
    $links = [
        ['/dashboard.php', 'Tableau de bord', true],
        ['/upload.php', 'Déposer', can('document.upload')],
        ['/audit.php', 'Journal', can('audit.read')],
        ['/users.php', 'Utilisateurs', can('user.manage')],
    ];
    foreach ($links as [$href, $label, $visible]) {
        if (!$visible) {
            continue;
        }
        $active = ($script === basename($href)) ? ' class="active" aria-current="page"' : '';
        echo '<a href="' . e($href) . '"' . $active . '>' . e($label) . '</a>';
    }
    echo '</nav>';

    if ($user !== null) {
        echo '<div class="user-zone">';
        render_theme_toggle();
        echo '<div class="user-chip"><span class="user-name">' . e($user['nom']) . '</span>';
        echo '<span class="role-badge role-' . e($user['role_nom']) . '">' . e($user['role_nom']) . '</span></div>';
        echo '<form method="post" action="/logout.php" class="logout-form">';
        echo csrf_field();
        echo '<button type="submit" class="btn btn-ghost btn-sm">Déconnexion</button>';
        echo '</form></div>';
    }
    echo '</div></header>';

    echo '<main class="container" id="contenu">';
    render_flashes();
    echo '<div class="page-head"><div><h1>' . e($title) . '</h1>';
    if ($subtitle !== '') {
        echo '<p class="page-subtitle">' . e($subtitle) . '</p>';
    }
    echo '</div></div>';
}

function render_footer(): void
{
    echo '</main><footer class="footer"><div class="footer-inner">';
    echo '<span>AES-256-GCM · RBAC · Journal append-only</span>';
    echo '<span>Prototype pédagogique — ne pas utiliser en production sans durcissement.</span>';
    echo '</div></footer></body></html>';
}

function render_auth_header(string $title): void
{
    $appName = (string) config('app.name');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' — ' . e($appName) . '</title>';
    echo '<meta name="theme-color" content="#0d1117">';
    render_assets();
    echo '</head><body class="auth-page page-' . e(page_slug()) . '">';
    render_theme_toggle();
    echo '<main class="auth-main">';
    echo '<section class="auth-brand-panel">';
    echo '<span class="brand-mark brand-mark-lg" aria-hidden="true">CF</span>';
    echo '<h1>' . e($appName) . '</h1>';
    echo '<p class="auth-tagline">Confidentialité, intégrité, contrôle d’accès et traçabilité '
        . 'pour vos documents sensibles.</p>';
    echo '<ul class="auth-points">';
    echo '<li>Chiffrement authentifié AES-256-GCM</li>';
    echo '<li>Contrôle d’accès par rôle (RBAC)</li>';
    echo '<li>Journal d’audit inaltérable</li>';
    echo '</ul></section>';
    echo '<section class="auth-card-wrap"><div class="auth-card">';
    echo '<h2>' . e($title) . '</h2>';
    render_flashes();
}

function render_auth_footer(): void
{
    echo '</div><p class="auth-footnote">Prototype de stage — Cybersécurité</p>';
    echo '</section></main></body></html>';
}
