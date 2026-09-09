<?php
/**
 * ---------------------------------------------------------------------
 * Cartes bancaires — vue en vignettes
 * ---------------------------------------------------------------------
 * Le tri par défaut est l'expiration la plus proche en premier : c'est
 * la question que l'outil doit répondre en priorité.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';
require_login();

// Une seule requête : carte, titulaire, et ce qu'elle porte réellement.
$cartes = $sql->query(
    "SELECT c.*,
            u.first_name, u.last_name,
            COUNT(CASE WHEN s.status = 'active' THEN 1 END)                       AS nb_services,
            COUNT(s.id)                                                           AS nb_services_total,
            COALESCE(SUM(CASE WHEN s.status = 'active' THEN s.monthly_cost END),0) AS cout_mensuel
       FROM fi_cards c
       JOIN fi_users u    ON u.id = c.holder_id
       LEFT JOIN fi_services s ON s.card_id = c.id
      GROUP BY c.id, u.first_name, u.last_name
      ORDER BY c.expires_on ASC"
)->fetchAll();

$title   = 'Cartes bancaires — ' . $config['app']['name'];
$page_id = 'page_cartes';
$menu    = 'cartes';

include __DIR__ . '/inc_header.php';
?>
        <section>
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12 d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <h1 class="mb-1">Cartes bancaires</h1>
                            <p class="text-secondary text-sm mb-0">
                                Seuls les 4 derniers chiffres et la date d'expiration sont enregistrés.
                            </p>
                        </div>
                        <button type="button" class="btn btn-primary" data-daf-popup="popup_carte.php">
                            <i class="fa-solid fa-plus me-2" aria-hidden="true"></i>Ajouter une carte
                        </button>
                    </div>
                </div>

                <!-- Barre de filtres -->
                <div class="row g-2 my-2">
                    <div class="col-md-5">
                        <label class="visually-hidden" for="filtreRecherche">Rechercher une carte</label>
                        <input type="search" class="form-control" id="filtreRecherche" data-daf-search="1"
                               placeholder="Rechercher : libellé, 4 chiffres, titulaire, banque…">
                    </div>
                    <div class="col-md-4">
                        <label class="visually-hidden" for="filtreStatut">Filtrer par statut</label>
                        <select class="form-select" id="filtreStatut">
                            <option value="all">Toutes les cartes</option>
                            <option value="renew">À renouveler (expirées ou proches)</option>
                            <option value="ok">Sans souci</option>
                            <option value="cancelled">Résiliées</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="visually-hidden" for="filtreTri">Trier</label>
                        <select class="form-select" id="filtreTri">
                            <option value="expiry-asc">Expiration la plus proche</option>
                            <option value="expiry-desc">Expiration la plus lointaine</option>
                            <option value="cost-desc">Montant porté décroissant</option>
                            <option value="label-asc">Libellé A → Z</option>
                        </select>
                    </div>
                </div>

                <p class="text-sm text-secondary" id="compteurCartes" role="status" aria-live="polite"></p>

                <div class="card-grid" id="grilleCartes">
<?php foreach ($cartes as $c) {
    $statut = card_effective_status($c);
    $badge  = card_status_badge($statut);
    $jours  = card_days_left((string) $c['expires_on']);
    $classe = match ($statut) {
        'expired'   => 'is-expired',
        'expiring'  => 'is-expiring',
        'cancelled' => 'is-cancelled',
        default     => '',
    };
?>
                    <article class="card-tile <?= $classe ?>"
                             data-statut="<?= h($statut) ?>"
                             data-rang="<?= $statut === 'cancelled' ? 1 : 0 ?>"
                             data-expiry="<?= h((string) $c['expires_on']) ?>"
                             data-cost="<?= (float) $c['cout_mensuel'] ?>"
                             data-label="<?= h(mb_strtolower((string) $c['label'])) ?>"
                             data-recherche="<?= h(mb_strtolower(
                                 $c['label'] . ' ' . $c['last4'] . ' ' . full_name($c) . ' ' . (string) $c['issuer']
                             )) ?>">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-bold"><?= h((string) $c['label']) ?></div>
                                <div class="card-number">•••• <?= h((string) $c['last4']) ?></div>
                            </div>
                            <span class="badge <?= h($badge['class']) ?> text-nowrap">
                                <i class="fa-solid <?= h($badge['icon']) ?> me-1" aria-hidden="true"></i><?= h($badge['label']) ?>
                            </span>
                        </div>

                        <div class="text-sm text-secondary">
                            <div>
                                <i class="fa-solid fa-user fa-fw me-1" aria-hidden="true"></i><?= h(full_name($c)) ?>
                                <?= $c['issuer'] !== null && $c['issuer'] !== '' ? ' · ' . h((string) $c['issuer']) : '' ?>
                            </div>
                            <div>
                                <i class="fa-solid fa-calendar fa-fw me-1" aria-hidden="true"></i>
                                Expire <?= h(expiry_fr((string) $c['expires_on'])) ?>
<?php if ($statut === 'expired') { ?>
                                <span class="text-danger fw-bold">— dépassée depuis <?= abs($jours) ?> jour<?= abs($jours) > 1 ? 's' : '' ?></span>
<?php } elseif ($statut === 'expiring' || $statut === 'watch') { ?>
                                <span class="fw-bold">— dans <?= $jours ?> jour<?= $jours > 1 ? 's' : '' ?></span>
<?php } ?>
                            </div>
                            <div>
                                <i class="fa-solid fa-tag fa-fw me-1" aria-hidden="true"></i><?= h(card_type_label((string) $c['type'])) ?>
                            </div>
                        </div>

<?php if ($c['notes'] !== null && trim((string) $c['notes']) !== '') { ?>
                        <p class="text-xsm text-secondary mb-0"><?= nl2br(h((string) $c['notes'])) ?></p>
<?php } ?>

                        <div class="card-figures">
                            <span>
                                <strong><?= (int) $c['nb_services'] ?></strong>
                                service<?= (int) $c['nb_services'] > 1 ? 's' : '' ?> actif<?= (int) $c['nb_services'] > 1 ? 's' : '' ?>
                            </span>
                            <span><strong><?= h(money((float) $c['cout_mensuel'])) ?></strong> / mois</span>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
<?php if ((int) $c['nb_services'] > 0) { ?>
                            <a class="btn btn-sm btn-outline-secondary" href="services_liste.php?carte=<?= (int) $c['id'] ?>">
                                Voir les services
                            </a>
<?php } ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    data-daf-popup="popup_carte.php?id=<?= (int) $c['id'] ?>">
                                <i class="fa-solid fa-pen-to-square me-1" aria-hidden="true"></i>Modifier
                            </button>
<?php if ((int) $c['nb_services_total'] === 0) { ?>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    data-daf-action="carte_delete"
                                    data-daf-id="<?= (int) $c['id'] ?>"
                                    data-daf-confirm-title="Supprimer cette carte"
                                    data-daf-confirm="Supprimer définitivement « <?= h((string) $c['label']) ?> » ? Aucun service n'y est rattaché, l'opération est sans effet de bord."
                                    data-daf-confirm-label="Supprimer">
                                <i class="fa-solid fa-trash-can me-1" aria-hidden="true"></i>Supprimer
                            </button>
<?php } ?>
                        </div>
                    </article>
<?php } ?>
                </div>

<?php if ($cartes === []) { ?>
                <div class="empty-state">
                    <div><i class="fa-solid fa-credit-card" aria-hidden="true"></i></div>
                    <p>Aucune carte enregistrée pour l'instant.</p>
                </div>
<?php } ?>

                <div class="empty-state d-none" id="aucunResultat">
                    <div><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></div>
                    <p>Aucune carte ne correspond à ce filtre.</p>
                </div>
            </div>
        </section>

<?php include __DIR__ . '/inc_footer.php'; ?>
        <script nonce="<?= h(csp_nonce()) ?>">
            document.addEventListener("DOMContentLoaded", function () {
                var grille    = document.getElementById("grilleCartes");
                var recherche = document.getElementById("filtreRecherche");
                var statut    = document.getElementById("filtreStatut");
                var tri       = document.getElementById("filtreTri");
                var compteur  = document.getElementById("compteurCartes");
                var vide      = document.getElementById("aucunResultat");
                var vignettes = Array.prototype.slice.call(grille.querySelectorAll(".card-tile"));

                // « À renouveler » regroupe les cartes expirées et celles
                // dont l'échéance est dans la fenêtre d'avertissement.
                var groupes = {
                    all: null,
                    renew: ["expired", "expiring"],
                    ok: ["active", "watch"],
                    cancelled: ["cancelled"]
                };

                function appliquer() {
                    var terme = recherche.value.trim().toLowerCase();
                    var accepte = groupes[statut.value];
                    var visibles = 0;

                    vignettes.forEach(function (tile) {
                        var okTexte = terme === "" || tile.dataset.recherche.indexOf(terme) !== -1;
                        var okStatut = accepte === null || accepte.indexOf(tile.dataset.statut) !== -1;
                        var affiche = okTexte && okStatut;

                        tile.hidden = !affiche;
                        if (affiche) { visibles++; }
                    });

                    var ordonnees = vignettes.slice().sort(function (a, b) {
                        // Les cartes résiliées passent toujours en dernier :
                        // elles n'appellent aucune action, les faire remonter
                        // en tête sur un tri par échéance n'aurait aucun sens.
                        var rang = parseInt(a.dataset.rang, 10) - parseInt(b.dataset.rang, 10);
                        if (rang !== 0) { return rang; }

                        switch (tri.value) {
                            case "expiry-desc": return b.dataset.expiry.localeCompare(a.dataset.expiry);
                            case "cost-desc":   return parseFloat(b.dataset.cost) - parseFloat(a.dataset.cost);
                            case "label-asc":   return a.dataset.label.localeCompare(b.dataset.label, "fr");
                            default:            return a.dataset.expiry.localeCompare(b.dataset.expiry);
                        }
                    });
                    ordonnees.forEach(function (tile) { grille.appendChild(tile); });

                    compteur.textContent = visibles + (visibles > 1 ? " cartes affichées" : " carte affichée")
                                         + " sur " + vignettes.length;
                    vide.classList.toggle("d-none", visibles > 0 || vignettes.length === 0);
                }

                recherche.addEventListener("input", appliquer);
                statut.addEventListener("change", appliquer);
                tri.addEventListener("change", appliquer);
                appliquer();
            });
        </script>
    </body>
</html>
