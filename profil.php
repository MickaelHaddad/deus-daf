<?php
/**
 * ---------------------------------------------------------------------
 * Profil de l'utilisateur connecté
 * ---------------------------------------------------------------------
 * Chacun peut y changer son mot de passe et son thème. Le reste (nom,
 * email, rôle) relève de l'administration des comptes.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';
require_login();

$minLength = (int) ($config['security']['password_min_length'] ?? 12);

// Ce que porte l'utilisateur, à titre informatif.
$stmt = $sql->prepare(
    "SELECT (SELECT COUNT(*) FROM fi_cards    c WHERE c.holder_id = ?)                          AS nb_cartes,
            (SELECT COUNT(*) FROM fi_services s WHERE s.owner_id  = ? AND s.status = 'active') AS nb_services"
);
$stmt->execute([(int) current_user()['id'], (int) current_user()['id']]);
$rattachements = $stmt->fetch();

$title   = 'Mon profil — ' . $config['app']['name'];
$page_id = 'page_profil';
$menu    = '';

include __DIR__ . '/inc_header.php';
?>
        <section>
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        <h1>Mon profil</h1>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Informations, en lecture seule -->
                    <div class="col-lg-5">
                        <div class="kpi h-100">
                            <h2 class="h5 mb-3"><?= h(full_name($me)) ?></h2>

                            <dl class="row mb-0 text-sm">
                                <dt class="col-5 text-secondary fw-normal">Email</dt>
                                <dd class="col-7"><?= h((string) $me['email']) ?></dd>

                                <dt class="col-5 text-secondary fw-normal">Rôle</dt>
                                <dd class="col-7"><?= h(role_label((string) $me['role'])) ?></dd>

                                <dt class="col-5 text-secondary fw-normal">Position</dt>
                                <dd class="col-7"><?= h(staff_type_label((string) $me['staff_type'])) ?></dd>

                                <dt class="col-5 text-secondary fw-normal">Dernière connexion</dt>
                                <dd class="col-7"><?= h(datetime_fr($me['last_login_at'])) ?></dd>

                                <dt class="col-5 text-secondary fw-normal">Cartes détenues</dt>
                                <dd class="col-7"><?= (int) $rattachements['nb_cartes'] ?></dd>

                                <dt class="col-5 text-secondary fw-normal">Services référencés</dt>
                                <dd class="col-7"><?= (int) $rattachements['nb_services'] ?></dd>
                            </dl>

                            <p class="text-xsm text-secondary mt-3 mb-0">
                                Ces informations sont modifiées par un administrateur.
                            </p>
                        </div>
                    </div>

                    <!-- Changement de mot de passe -->
                    <div class="col-lg-7">
                        <div class="kpi h-100">
                            <h2 class="h5 mb-3">Changer mon mot de passe</h2>

                            <form class="daf-ajax-form" data-action="profil_password"
                                  data-success="Mot de passe modifié." id="formMotDePasse" novalidate>
                                <div class="mb-3">
                                    <label class="form-label" for="p_current">Mot de passe actuel</label>
                                    <input type="password" class="form-control" id="p_current"
                                           name="current_password" autocomplete="current-password" required>
                                    <div class="invalid-feedback"></div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="p_new">Nouveau mot de passe</label>
                                    <input type="password" class="form-control" id="p_new"
                                           name="password" autocomplete="new-password" required>
                                    <div class="form-text">
                                        <?= $minLength ?> caractères minimum, avec majuscules, minuscules et chiffres.
                                    </div>
                                    <div class="invalid-feedback"></div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="p_confirm">Confirmation</label>
                                    <input type="password" class="form-control" id="p_confirm"
                                           name="password_confirm" autocomplete="new-password" required>
                                    <div class="invalid-feedback"></div>
                                </div>

                                <button type="submit" class="btn btn-primary">Modifier le mot de passe</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>

<?php include __DIR__ . '/inc_footer.php'; ?>
        <script nonce="<?= h(csp_nonce()) ?>">
            // Après un changement de mot de passe réussi, on vide le
            // formulaire plutôt que de recharger la page.
            window.dafOnSaved = function (action) {
                if (action === "profil_password") {
                    document.getElementById("formMotDePasse").reset();
                }
            };
        </script>
    </body>
</html>
