<?php
/**
 * ---------------------------------------------------------------------
 * En-tête commun : <head>, menu de navigation, ouverture du layout
 * ---------------------------------------------------------------------
 * Variables attendues de la page appelante (toutes facultatives) :
 *
 *   $title          Titre du navigateur
 *   $page_id        Identifiant CSS posé sur <body>
 *   $menu           Entrée de menu à mettre en surbrillance
 *   $includePlugins Plugins JS/CSS à charger, ex. ['datatables']
 *   $sans_menu      true pour une page plein écran sans navigation
 *   $page_publique  true pour une page accessible sans session
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';

$sans_menu     = $sans_menu     ?? false;
$page_publique = $page_publique ?? false;

// Aucune page n'est accessible sans session valide, hors écran de connexion.
if (!$page_publique) {
    require_login();
}

$title          = $title   ?? ($config['app']['name'] ?? 'Deus DAF');
$page_id        = $page_id ?? 'page';
$menu           = $menu    ?? '';
$includePlugins = $includePlugins ?? [];

$includeCss = '';
$includeJs  = '';

foreach ($includePlugins as $plugin) {
    switch ($plugin) {
        case 'datatables':
            $includeCss .= "<link rel=\"stylesheet\" href=\"assets/plugins/DataTables-2.2.1/datatables.min.css\">\n";
            $includeJs  .= "<script src=\"assets/plugins/DataTables-2.2.1/datatables.min.js\"></script>\n";
            break;
    }
}

/** Renvoie « active » si l'entrée de menu passée est celle de la page courante. */
function menu_active(string $entry): string
{
    global $menu;

    return $menu === $entry ? 'active' : '';
}

$me    = current_user();
$nonce = csp_nonce();
?>
<!DOCTYPE html>
<html lang="fr" data-user-theme="<?= h($me['theme'] ?? 'auto') ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
        <title><?= h($title) ?></title>

        <!-- Bascule de thème appliquée avant le premier rendu, pour éviter
             tout clignotement clair/sombre au chargement. -->
        <script src="assets/js/theme-modes.js"></script>

        <!-- PRELOAD -->
        <link rel="preload" as="font" type="font/woff2" href="assets/fonts/noto-sans/noto-sans-v27-latin-regular.woff2" crossorigin="anonymous">
        <link rel="preload" as="font" type="font/woff2" href="assets/fonts/noto-sans/noto-sans-v27-latin-600.woff2" crossorigin="anonymous">
        <link rel="preload" as="font" type="font/woff2" href="assets/fonts/noto-sans/noto-sans-v27-latin-700.woff2" crossorigin="anonymous">

        <!-- FAVICON -->
        <link rel="icon" type="image/png" sizes="96x96" href="assets/img/favicon/favicon-96x96.png">
        <link rel="shortcut icon" href="assets/img/favicon/favicon.ico">
        <link rel="apple-touch-icon" sizes="180x180" href="assets/img/favicon/apple-touch-icon.png">

        <!-- CSS -->
        <link href="assets/scss/custom.min.css" rel="stylesheet" type="text/css">
        <link href="assets/fonts/fontawesome-free-6.7.2-web/css/all.min.css" rel="stylesheet" type="text/css">
        <?= $includeCss ?>
        <link href="assets/scss/main.min.css" rel="stylesheet" type="text/css">
        <link href="assets/scss/daf.css" rel="stylesheet" type="text/css">
    </head>
    <body id="<?= h($page_id) ?>">
        <!-- Conteneur des modales chargées en AJAX -->
        <div id="modalContainer"></div>

        <main>
<?php if (!$sans_menu) { ?>
            <!-- HEADER -->
            <header>
                <nav id="navbar-header" class="navbar navbar-expand-lg">
                    <a class="navbar-brand d-flex align-items-center gap-2 me-3" href="home.php">
                        <i class="fa-solid fa-credit-card text-primary" aria-hidden="true"></i>
                        <span class="fw-bold"><?= h($config['app']['name']) ?></span>
                    </a>

                    <button class="navbar-toggler ms-auto" type="button" data-bs-toggle="offcanvas"
                            data-bs-target="#navbarOffcanvasLg" aria-controls="navbarOffcanvasLg"
                            aria-label="Ouvrir la navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>

                    <div class="offcanvas offcanvas-end" tabindex="-1" id="navbarOffcanvasLg"
                         aria-labelledby="navbarOffcanvasLgLabel">
                        <div class="offcanvas-header">
                            <h2 class="offcanvas-title h5 mb-0" id="navbarOffcanvasLgLabel">Navigation</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fermer"></button>
                        </div>
                        <div class="offcanvas-body">
                            <ul class="navbar-nav flex-grow-1 gap-1">
                                <li class="nav-item">
                                    <a class="nav-link <?= menu_active('home') ?>" href="home.php">
                                        <i class="fa-solid fa-gauge-high fa-fw me-2" aria-hidden="true"></i>Tableau de bord
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?= menu_active('services') ?>" href="services_liste.php">
                                        <i class="fa-solid fa-cubes fa-fw me-2" aria-hidden="true"></i>Services
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?= menu_active('cartes') ?>" href="cartes_liste.php">
                                        <i class="fa-solid fa-credit-card fa-fw me-2" aria-hidden="true"></i>Cartes
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?= menu_active('banques') ?>" href="banques_liste.php">
                                        <i class="fa-solid fa-building-columns fa-fw me-2" aria-hidden="true"></i>Banques
                                    </a>
                                </li>
<?php if (is_admin()) { ?>
                                <li class="nav-item">
                                    <a class="nav-link <?= menu_active('utilisateurs') ?>" href="utilisateurs_liste.php">
                                        <i class="fa-solid fa-users fa-fw me-2" aria-hidden="true"></i>Utilisateurs
                                    </a>
                                </li>
                                <li class="nav-item dropdown">
                                    <button class="nav-link dropdown-toggle <?= menu_active('admin') ?>" type="button"
                                            data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fa-solid fa-gears fa-fw me-2" aria-hidden="true"></i>Administration
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="reglages.php">Réglages des alertes</a></li>
                                        <li><a class="dropdown-item" href="journal.php">Journal d'activité</a></li>
                                    </ul>
                                </li>
<?php } ?>
                            </ul>

                            <ul class="navbar-nav gap-1">
                                <!-- Bascule de thème -->
                                <li class="nav-item dropdown">
                                    <button class="btn btn-link nav-link px-0 px-lg-2 py-2 dropdown-toggle d-flex align-items-center"
                                            id="bd-theme" type="button" aria-expanded="false"
                                            data-bs-toggle="dropdown" data-bs-display="static"
                                            aria-label="Changer de thème">
                                        <i class="fa-solid fa-circle-half-stroke fa-fw theme-icon-active" aria-hidden="true"></i>
                                        <span class="ms-2 d-lg-none" id="bd-theme-text">Thème</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="bd-theme-text">
                                        <li>
                                            <button type="button" class="dropdown-item d-flex align-items-center"
                                                    data-bs-theme-value="light" aria-pressed="false">
                                                <i class="fa-solid fa-sun fa-fw me-2" aria-hidden="true"></i>Clair
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item d-flex align-items-center"
                                                    data-bs-theme-value="dark" aria-pressed="false">
                                                <i class="fa-solid fa-moon fa-fw me-2" aria-hidden="true"></i>Sombre
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item d-flex align-items-center active"
                                                    data-bs-theme-value="auto" aria-pressed="true">
                                                <i class="fa-solid fa-circle-half-stroke fa-fw me-2" aria-hidden="true"></i>Auto
                                            </button>
                                        </li>
                                    </ul>
                                </li>

                                <!-- Compte -->
                                <li class="nav-item dropdown">
                                    <button class="nav-link dropdown-toggle" type="button"
                                            data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fa-solid fa-circle-user fa-fw me-2" aria-hidden="true"></i><?= h(full_name($me)) ?>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <span class="dropdown-item-text text-xsm text-secondary">
                                                <?= h(role_label((string) $me['role'])) ?> ·
                                                <?= h(staff_type_label((string) $me['staff_type'])) ?>
                                            </span>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item" href="profil.php">Mon profil</a></li>
                                        <li>
                                            <a class="dropdown-item text-danger" href="index.php?action=deconnexion">
                                                <i class="fa-solid fa-right-from-bracket fa-fw me-2" aria-hidden="true"></i>Déconnexion
                                            </a>
                                        </li>
                                    </ul>
                                </li>
                            </ul>
                        </div>
                    </div>
                </nav>
            </header>
            <!-- /HEADER -->
<?php } ?>

            <!-- CONTENT-WRAPPER -->
            <div class="content-wrapper">
                <!-- PAGE-WRAPPER -->
                <div id="page-wrapper"<?= $sans_menu ? ' class="no-header"' : '' ?>>
