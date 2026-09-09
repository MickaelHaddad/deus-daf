<?php
/**
 * ---------------------------------------------------------------------
 * Pied de page commun : fermeture du layout et scripts partagés
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

if (!defined('DEUS_DAF')) {
    http_response_code(404);
    exit;
}

$sans_menu   = $sans_menu ?? false;
$includeJs   = $includeJs ?? '';
$nonce       = csp_nonce();
$idleMinutes = setting_int('session_idle_minutes', (int) ($config['session']['idle_minutes'] ?? 120));
?>
                </div>
                <!-- /PAGE-WRAPPER -->

                <footer>
                    <?= date('Y') ?> © <?= h($config['app']['company'] ?? 'Deus') ?>
                </footer>
            </div>
            <!-- /CONTENT-WRAPPER -->
        </main>

        <script src="assets/js/jquery-4.0.0.min.js"></script>
        <script src="assets/bootstrap-5.3.6/dist/js/bootstrap.bundle.min.js"></script>
        <?= $includeJs ?>
        <script src="assets/js/script.js"></script>
        <script nonce="<?= h($nonce) ?>">
            window.DAF = {
                csrfToken:    document.querySelector('meta[name="csrf-token"]').content,
                connected:    <?= current_user() !== null ? 'true' : 'false' ?>,
                isAdmin:      <?= is_admin() ? 'true' : 'false' ?>,
                idleSeconds:  <?= (int) ($idleMinutes * 60) ?>
            };
        </script>
        <script src="assets/js/app.js"></script>
<?php
/*
 * Volontairement, ce fichier ne ferme ni <body> ni <html> : chaque page
 * peut ainsi ajouter son propre bloc <script nonce="…"> après le
 * chargement de jQuery, Bootstrap et app.js, puis fermer le document.
 * C'est la convention en vigueur dans deus-console.
 */
