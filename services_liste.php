<?php
/**
 * ---------------------------------------------------------------------
 * Services et abonnements — vue principale de l'outil
 * ---------------------------------------------------------------------
 * Tout est chargé en une fois : recherche, tri et filtres travaillent
 * côté client, sans aller-retour serveur. Le volume attendu (quelques
 * centaines de lignes au plus) le permet largement.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';
require_login();

$services = fetch_services();

// Listes des filtres, construites depuis les données réellement
// présentes plutôt que depuis les tables complètes.
$cartes = $sql->query(
    'SELECT c.id, c.label, c.last4 FROM fi_cards c ORDER BY c.label'
)->fetchAll();

$referents = $sql->query(
    'SELECT DISTINCT u.id, u.first_name, u.last_name
       FROM fi_users u JOIN fi_services s ON s.owner_id = u.id
      ORDER BY u.last_name, u.first_name'
)->fetchAll();

// Pré-filtrage éventuel depuis la page des cartes.
$carteFiltre = isset($_GET['carte']) ? (int) $_GET['carte'] : 0;

$title          = 'Services — ' . $config['app']['name'];
$page_id        = 'page_services';
$menu           = 'services';
$includePlugins = ['datatables'];

include __DIR__ . '/inc_header.php';
?>
        <section>
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12 d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <h1 class="mb-1">Services et abonnements</h1>
                            <p class="text-secondary text-sm mb-0">
                                Appuyez sur <kbd>/</kbd> pour rechercher.
                            </p>
                        </div>
                        <div class="d-flex gap-2">
                            <a class="btn btn-outline-secondary" href="export_services.php" id="lienExport">
                                <i class="fa-solid fa-file-csv me-2" aria-hidden="true"></i>Exporter
                            </a>
                            <button type="button" class="btn btn-primary" data-daf-popup="popup_service.php">
                                <i class="fa-solid fa-plus me-2" aria-hidden="true"></i>Ajouter un service
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Totaux et répartition, remplacés en bloc après chaque enregistrement -->
                <div id="blocTotaux"><?= render_service_totals() ?></div>

                <!-- Filtres rapides -->
                <div class="row g-2 mb-2">
                    <div class="col-md-3">
                        <label class="form-label text-xsm mb-1" for="filtreCarte">Carte</label>
                        <select class="form-select form-select-sm" id="filtreCarte">
                            <option value="">Toutes</option>
                            <option value="none">Sans carte</option>
<?php foreach ($cartes as $c) { ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $carteFiltre === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= h((string) $c['label']) ?> ••••<?= h((string) $c['last4']) ?>
                            </option>
<?php } ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-xsm mb-1" for="filtreReferent">Référent</label>
                        <select class="form-select form-select-sm" id="filtreReferent">
                            <option value="">Tous</option>
                            <option value="none">Sans référent</option>
<?php foreach ($referents as $r) { ?>
                            <option value="<?= (int) $r['id'] ?>"><?= h(full_name($r)) ?></option>
<?php } ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-xsm mb-1" for="filtreCycle">Type d'abonnement</label>
                        <select class="form-select form-select-sm" id="filtreCycle">
                            <option value="">Tous</option>
<?php foreach (billing_cycles() as $value => $label) { ?>
                            <option value="<?= h($value) ?>"><?= h($label) ?></option>
<?php } ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-xsm mb-1" for="filtreStatut">Statut</label>
                        <select class="form-select form-select-sm" id="filtreStatut">
                            <option value="active">Actifs seulement</option>
                            <option value="">Tous</option>
                            <option value="suspended">Suspendus</option>
                            <option value="cancelled">Résiliés</option>
                            <option value="alert">Paiement menacé</option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <div class="table-responsive datatable-responsive">
                            <table id="tableServices" class="table dataTable table-sm table-striped table-hover align-middle">
                                <thead>
                                    <tr class="no-wrap">
                                        <th>Service</th>
                                        <th>Abonnement</th>
                                        <th>Carte</th>
                                        <th class="text-end">Montant</th>
                                        <th class="text-end">Mensualisé</th>
                                        <th>Référent</th>
                                        <th>Prochaine échéance</th>
                                        <th>Statut</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="corpsServices">
<?php foreach ($services as $s) {
    echo render_service_row($s);
} ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>

<?php include __DIR__ . '/inc_footer.php'; ?>
        <script nonce="<?= h(csp_nonce()) ?>">
            var tableServices;

            document.addEventListener("DOMContentLoaded", function () {
                var fCarte    = document.getElementById("filtreCarte");
                var fReferent = document.getElementById("filtreReferent");
                var fCycle    = document.getElementById("filtreCycle");
                var fStatut   = document.getElementById("filtreStatut");

                // Filtre personnalisé : il lit les attributs data-* posés
                // sur chaque <tr> par render_service_row().
                DataTable.ext.search.push(function (settings, data, dataIndex) {
                    if (settings.nTable.id !== "tableServices") {
                        return true;
                    }

                    var tr = settings.aoData[dataIndex].nTr;

                    if (fCarte.value === "none" ? tr.dataset.card !== "0"
                        : fCarte.value !== "" && tr.dataset.card !== fCarte.value) {
                        return false;
                    }
                    if (fReferent.value === "none" ? tr.dataset.owner !== "0"
                        : fReferent.value !== "" && tr.dataset.owner !== fReferent.value) {
                        return false;
                    }
                    if (fCycle.value !== "" && tr.dataset.cycle !== fCycle.value) {
                        return false;
                    }
                    if (fStatut.value === "alert") {
                        return tr.dataset.alert === "1";
                    }
                    if (fStatut.value !== "" && tr.dataset.status !== fStatut.value) {
                        return false;
                    }

                    return true;
                });

                tableServices = $("#tableServices").DataTable({
                    dom: '<"d-flex justify-content-between flex-wrap gap-2 mb-2"<"d-flex align-items-center"l><"d-flex align-items-center"f>><t>i<p>',
                    pageLength: 50,
                    order: [[0, "asc"]],
                    columnDefs: [{ targets: [8], orderable: false }],
                    initComplete: function () {
                        $("#tableServices_filter input")
                            .attr("data-daf-search", "1")
                            .attr("placeholder", "Rechercher un service…");
                    }
                });

                // L'export reprend exactement les filtres à l'écran :
                // le lien est réécrit à chaque changement.
                var lienExport = document.getElementById("lienExport");

                function majLienExport() {
                    var params = new URLSearchParams();
                    if (fCarte.value)    { params.set("carte", fCarte.value); }
                    if (fReferent.value) { params.set("referent", fReferent.value); }
                    if (fCycle.value)    { params.set("cycle", fCycle.value); }
                    if (fStatut.value)   { params.set("statut", fStatut.value); }

                    var query = params.toString();
                    lienExport.href = "export_services.php" + (query ? "?" + query : "");
                }

                [fCarte, fReferent, fCycle, fStatut].forEach(function (select) {
                    select.addEventListener("change", function () {
                        tableServices.draw();
                        majLienExport();
                    });
                });

                majLienExport();
                tableServices.draw();
            });

            // Mise à jour en place après un enregistrement : la ligne
            // concernée et le bandeau de totaux sont régénérés par le
            // serveur, ce qui garantit qu'ils disent la même chose que
            // la base.
            window.dafOnSaved = function (action, data) {
                if (!data) {
                    window.location.reload();
                    return;
                }

                if (data.totals_html) {
                    document.getElementById("blocTotaux").innerHTML = data.totals_html;
                }

                var existante = document.querySelector('tr[data-service-id="' + data.id + '"]');

                if (action === "service_delete") {
                    if (existante) {
                        tableServices.row(existante).remove().draw(false);
                    }
                    return;
                }

                if (!data.row_html) {
                    return;
                }

                if (existante) {
                    var remplacante = $(data.row_html)[0];
                    existante.replaceWith(remplacante);
                    tableServices.row(remplacante).invalidate().draw(false);
                } else {
                    tableServices.row.add($(data.row_html)).draw(false);
                }

                $('#tableServices [data-bs-toggle="tooltip"]').each(function () {
                    if (!bootstrap.Tooltip.getInstance(this)) {
                        new bootstrap.Tooltip(this);
                    }
                });
            };
        </script>
    </body>
</html>
