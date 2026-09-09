<?php
/**
 * ---------------------------------------------------------------------
 * Liste des utilisateurs — réservée aux administrateurs
 * ---------------------------------------------------------------------
 * Un utilisateur n'est jamais supprimé : il est désactivé, car il reste
 * référencé comme titulaire de carte ou référent de service.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';
require_admin();

// Le décompte des rattachements sert à expliquer, dans l'interface,
// pourquoi un compte ne peut pas simplement disparaître.
$utilisateurs = $sql->query(
    "SELECT u.*,
            (SELECT COUNT(*) FROM fi_cards    c WHERE c.holder_id = u.id) AS nb_cartes,
            (SELECT COUNT(*) FROM fi_services s WHERE s.owner_id  = u.id) AS nb_services
       FROM fi_users u
      ORDER BY u.is_active DESC, u.last_name, u.first_name"
)->fetchAll();

$title          = 'Utilisateurs — ' . $config['app']['name'];
$page_id        = 'page_utilisateurs';
$menu           = 'utilisateurs';
$includePlugins = ['datatables'];

include __DIR__ . '/inc_header.php';
?>
        <section>
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        <h1>Utilisateurs</h1>
                        <p class="text-secondary text-sm">
                            Les comptes désactivés ne peuvent plus se connecter, mais restent
                            visibles sur les fiches qu'ils portent.
                        </p>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <div class="table-responsive">
                            <table id="tableUtilisateurs" class="table dataTable table-sm table-striped table-hover align-middle">
                                <thead>
                                    <tr class="no-wrap">
                                        <th>Nom</th>
                                        <th>Email</th>
                                        <th>Rôle</th>
                                        <th>Position</th>
                                        <th>Statut</th>
                                        <th>Rattachements</th>
                                        <th>Dernière connexion</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
<?php foreach ($utilisateurs as $u) {
    $estMoi     = (int) $u['id'] === (int) $me['id'];
    $rattache   = (int) $u['nb_cartes'] + (int) $u['nb_services'];
    $actif      = (int) $u['is_active'] === 1;
?>
                                    <tr data-user-id="<?= (int) $u['id'] ?>">
                                        <td>
                                            <?= h(full_name($u)) ?>
<?php if ($estMoi) { ?>
                                            <span class="badge text-bg-primary ms-1">vous</span>
<?php } ?>
                                        </td>
                                        <td><?= h((string) $u['email']) ?></td>
                                        <td><?= h(role_label((string) $u['role'])) ?></td>
                                        <td><?= h(staff_type_label((string) $u['staff_type'])) ?></td>
                                        <td>
                                            <span class="badge <?= $actif ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                                <?= $actif ? 'Actif' : 'Désactivé' ?>
                                            </span>
                                        </td>
                                        <td data-order="<?= $rattache ?>">
<?php if ($rattache === 0) { ?>
                                            <span class="text-secondary">—</span>
<?php } else { ?>
                                            <span class="text-sm">
                                                <?= (int) $u['nb_cartes'] ?> carte<?= (int) $u['nb_cartes'] > 1 ? 's' : '' ?>,
                                                <?= (int) $u['nb_services'] ?> service<?= (int) $u['nb_services'] > 1 ? 's' : '' ?>
                                            </span>
<?php } ?>
                                        </td>
                                        <td data-order="<?= h((string) ($u['last_login_at'] ?? '')) ?>">
                                            <?= h(datetime_fr($u['last_login_at'])) ?>
                                        </td>
                                        <td class="text-center text-nowrap">
                                            <button type="button" class="btn-action text-bg-info"
                                                    data-daf-popup="popup_utilisateur.php?id=<?= (int) $u['id'] ?>"
                                                    data-bs-toggle="tooltip" data-bs-title="Modifier"
                                                    aria-label="Modifier <?= h(full_name($u)) ?>">
                                                <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                            </button>
<?php if (!$estMoi) { ?>
                                            <button type="button"
                                                    class="btn-action <?= $actif ? 'text-bg-warning' : 'text-bg-success' ?>"
                                                    data-daf-action="utilisateur_toggle"
                                                    data-daf-id="<?= (int) $u['id'] ?>"
                                                    data-daf-confirm-title="<?= $actif ? 'Désactiver ce compte' : 'Réactiver ce compte' ?>"
                                                    data-daf-confirm="<?= $actif
                                                        ? h(full_name($u)) . ' ne pourra plus se connecter. Ses cartes et services restent inchangés.'
                                                        : h(full_name($u)) . ' pourra de nouveau se connecter.' ?>"
                                                    data-daf-confirm-label="<?= $actif ? 'Désactiver' : 'Réactiver' ?>"
                                                    data-bs-toggle="tooltip"
                                                    data-bs-title="<?= $actif ? 'Désactiver' : 'Réactiver' ?>"
                                                    aria-label="<?= $actif ? 'Désactiver' : 'Réactiver' ?> <?= h(full_name($u)) ?>">
                                                <i class="fa-solid <?= $actif ? 'fa-user-slash' : 'fa-user-check' ?>" aria-hidden="true"></i>
                                            </button>
<?php } ?>
                                        </td>
                                    </tr>
<?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>

<?php include __DIR__ . '/inc_footer.php'; ?>
        <script nonce="<?= h(csp_nonce()) ?>">
            document.addEventListener("DOMContentLoaded", function () {
                var boutonAjout =
                    '<button type="button" class="btn btn-sm btn-primary" data-daf-popup="popup_utilisateur.php">' +
                    '<i class="fa-solid fa-plus me-2" aria-hidden="true"></i>Ajouter un utilisateur</button>';

                $("#tableUtilisateurs").DataTable({
                    dom: '<"d-flex justify-content-between flex-wrap gap-2 mb-2"<"#divLeft.d-flex align-items-center"l><"#divRight.d-flex align-items-center flex-wrap gap-2"f>><t>i<p>',
                    pageLength: 25,
                    order: [],
                    columnDefs: [{ targets: [7], orderable: false }],
                    initComplete: function () {
                        $("#divRight").prepend(boutonAjout);
                        $("#tableUtilisateurs_filter input").attr("data-daf-search", "1");
                    }
                });
            });
        </script>
    </body>
</html>
