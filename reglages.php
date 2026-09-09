<?php
/**
 * ---------------------------------------------------------------------
 * Réglages applicatifs — administrateurs uniquement
 * ---------------------------------------------------------------------
 * Les seuils d'alerte sont en base plutôt qu'en dur, pour être ajustés
 * sans intervention sur les fichiers.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';
require_admin();

$title   = 'Réglages — ' . $config['app']['name'];
$page_id = 'page_reglages';
$menu    = 'admin';

include __DIR__ . '/inc_header.php';
?>
        <section>
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        <h1>Réglages</h1>
                        <p class="text-secondary text-sm">
                            Ces valeurs pilotent le bloc « Alertes » du tableau de bord et les
                            pastilles de couleur sur les cartes.
                        </p>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-8">
                        <form class="daf-ajax-form kpi" data-action="reglages_save"
                              data-success="Réglages enregistrés." novalidate>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="r_warning">
                                        Seuil d'avertissement (orange)
                                    </label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="r_warning"
                                               name="card_alert_warning_days" min="1" max="365" required
                                               value="<?= setting_int('card_alert_warning_days', 60) ?>">
                                        <span class="input-group-text">jours</span>
                                    </div>
                                    <div class="form-text">
                                        Une carte passe en orange quand il lui reste moins de ce nombre de jours.
                                    </div>
                                    <div class="invalid-feedback"></div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="r_info">
                                        Seuil de surveillance (neutre)
                                    </label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="r_info"
                                               name="card_alert_info_days" min="1" max="730" required
                                               value="<?= setting_int('card_alert_info_days', 90) ?>">
                                        <span class="input-group-text">jours</span>
                                    </div>
                                    <div class="form-text">
                                        Début de la surveillance. Doit être supérieur au seuil d'avertissement.
                                    </div>
                                    <div class="invalid-feedback"></div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="r_renewal">
                                        Alerte d'échéance de service
                                    </label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="r_renewal"
                                               name="service_renewal_days" min="1" max="365" required
                                               value="<?= setting_int('service_renewal_days', 30) ?>">
                                        <span class="input-group-text">jours</span>
                                    </div>
                                    <div class="form-text">
                                        Signale les renouvellements à venir, quand la date est renseignée.
                                    </div>
                                    <div class="invalid-feedback"></div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="r_idle">
                                        Déconnexion automatique
                                    </label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="r_idle"
                                               name="session_idle_minutes" min="5" max="1440" required
                                               value="<?= setting_int('session_idle_minutes', 120) ?>">
                                        <span class="input-group-text">minutes</span>
                                    </div>
                                    <div class="form-text">
                                        Durée d'inactivité au bout de laquelle la session est fermée.
                                    </div>
                                    <div class="invalid-feedback"></div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label" for="r_company">Nom de la structure</label>
                                    <input type="text" class="form-control" id="r_company"
                                           name="company_name" maxlength="120"
                                           value="<?= h((string) setting('company_name', 'Deus')) ?>">
                                    <div class="form-text">Affiché en pied de page.</div>
                                    <div class="invalid-feedback"></div>
                                </div>

                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">Enregistrer les réglages</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="col-lg-4">
                        <div class="kpi h-100">
                            <div class="kpi-label mb-2">Comment sont classées les cartes</div>
                            <ul class="text-sm mb-0 ps-3">
                                <li class="mb-2">
                                    <span class="badge text-bg-danger">Critique</span> — carte expirée
                                    portant au moins un service actif.
                                </li>
                                <li class="mb-2">
                                    <span class="badge text-bg-warning">Avertissement</span> — échéance
                                    sous le seuil d'avertissement, ou carte expirée sans service.
                                </li>
                                <li class="mb-2">
                                    <span class="badge text-bg-info">Information</span> — échéance sous
                                    le seuil de surveillance. Bloc replié par défaut.
                                </li>
                            </ul>
                            <p class="text-xsm text-secondary mt-3 mb-0">
                                Le statut « expirée » n'est jamais enregistré en base : il est recalculé
                                à chaque affichage à partir de la date d'expiration. Modifier ces seuils
                                prend donc effet immédiatement, sans tâche planifiée.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

<?php include __DIR__ . '/inc_footer.php'; ?>
        <script nonce="<?= h(csp_nonce()) ?>">
            // Rien à recharger : les valeurs saisies sont déjà à l'écran.
            window.dafOnSaved = function () {};
        </script>
    </body>
</html>
