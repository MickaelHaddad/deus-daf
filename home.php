<?php
/**
 * ---------------------------------------------------------------------
 * Tableau de bord
 * ---------------------------------------------------------------------
 * Le bloc « Alertes » est en tête et ne s'affiche que s'il a quelque
 * chose à dire. Tous les calculs sont faits par MySQL, aucun par le
 * navigateur.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';
require_login();

$alertesCartes = fetch_card_alerts();
$autresAlertes = fetch_other_alerts();
$totaux        = service_totals();
$parReferent   = service_breakdown_by_owner();

// Cartes réellement utilisables : ni résiliées, ni expirées.
$nbCartesValides = (int) $sql->query(
    "SELECT COUNT(*) FROM fi_cards WHERE status = 'active' AND expires_on >= CURDATE()"
)->fetchColumn();

$derniersServices = $sql->query(
    service_select_sql() . ' ORDER BY s.updated_at DESC LIMIT 6'
)->fetchAll();

$maxReferent = 0.0;
foreach ($parReferent as $ligne) {
    $maxReferent = max($maxReferent, (float) $ligne['cout_mensuel']);
}

$niveaux = [
    'critical' => ['classe' => 'level-critical', 'icone' => 'fa-circle-exclamation', 'couleur' => 'text-danger'],
    'warning'  => ['classe' => 'level-warning',  'icone' => 'fa-triangle-exclamation', 'couleur' => 'text-warning'],
    'info'     => ['classe' => 'level-info',     'icone' => 'fa-clock', 'couleur' => 'text-info'],
];

$title   = 'Tableau de bord — ' . $config['app']['name'];
$page_id = 'page_home';
$menu    = 'home';

include __DIR__ . '/inc_header.php';
?>
        <section>
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        <h1>Tableau de bord</h1>
                    </div>
                </div>

<?php if (has_alerts($alertesCartes, $autresAlertes)) { ?>
                <!-- ==================== ALERTES ==================== -->
                <div class="row">
                    <div class="col-12">
                        <h2 class="h5 mb-2">
                            <i class="fa-solid fa-bell me-2" aria-hidden="true"></i>Alertes
                        </h2>
                    </div>
                </div>

<?php foreach ($alertesCartes as $a) {
    $n        = $niveaux[$a['level']];
    $repliee  = $a['level'] === 'info';
    $idBloc   = 'alerte-carte-' . $a['id'];
?>
                <div class="alert-block <?= h($n['classe']) ?>" role="<?= $a['level'] === 'critical' ? 'alert' : 'status' ?>">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <div class="alert-title">
                                <i class="fa-solid <?= h($n['icone']) ?> <?= h($n['couleur']) ?> me-2" aria-hidden="true"></i>
                                <?= h((string) $a['label']) ?>
                                <span class="card-number">••••&nbsp;<?= h((string) $a['last4']) ?></span>
                            </div>
                            <div class="alert-meta">
                                Titulaire <?= h((string) $a['holder']) ?> ·
                                expiration <?= h(expiry_fr((string) $a['expires_on'])) ?> ·
<?php if ($a['days_left'] < 0) { ?>
                                <strong class="text-danger">expirée depuis <?= abs($a['days_left']) ?> jour<?= abs($a['days_left']) > 1 ? 's' : '' ?></strong>
<?php } else { ?>
                                <strong>encore <?= $a['days_left'] ?> jour<?= $a['days_left'] > 1 ? 's' : '' ?></strong>
<?php } ?>
                            </div>
                        </div>
                        <div class="text-end">
<?php if ($a['services'] !== []) { ?>
                            <div class="fw-bold"><?= h(money($a['monthly_at_risk'])) ?> / mois</div>
                            <div class="alert-meta">
                                <?= count($a['services']) ?> service<?= count($a['services']) > 1 ? 's' : '' ?> concerné<?= count($a['services']) > 1 ? 's' : '' ?>
                            </div>
<?php } else { ?>
                            <div class="alert-meta">Aucun service rattaché</div>
<?php } ?>
                            <a class="btn btn-sm btn-outline-secondary mt-1" href="cartes_liste.php">Voir la carte</a>
                        </div>
                    </div>

<?php if ($a['services'] !== []) { ?>
<?php if ($repliee) { ?>
                    <button class="btn btn-sm btn-link px-0 mt-1" type="button" data-bs-toggle="collapse"
                            data-bs-target="#<?= h($idBloc) ?>" aria-expanded="false" aria-controls="<?= h($idBloc) ?>">
                        Voir les services concernés
                    </button>
                    <div class="collapse" id="<?= h($idBloc) ?>">
<?php } else { ?>
                    <div id="<?= h($idBloc) ?>">
<?php } ?>
                        <ul class="alert-services">
<?php foreach ($a['services'] as $srv) { ?>
                            <li>
                                <a href="services_liste.php?carte=<?= (int) $a['id'] ?>" class="fw-medium">
                                    <?= h((string) $srv['name']) ?>
                                </a>
                                <span class="text-secondary"><?= h(billing_cycle_label((string) $srv['cycle'])) ?></span>
                                <span class="ms-auto"><?= h(money($srv['amount'])) ?><?= $srv['cycle'] === 'yearly' ? ' / an' : ($srv['cycle'] === 'monthly' ? ' / mois' : '') ?></span>
                            </li>
<?php } ?>
                        </ul>
                    </div>
<?php } ?>
                </div>
<?php } ?>

<?php
// ----- Alertes secondaires, regroupées dans un même bloc -----
$secondaires = [];

if ($autresAlertes['renewals'] !== []) {
    $secondaires[] = ['icone' => 'fa-calendar-day', 'titre' => 'Échéances proches', 'items' =>
        array_map(static fn (array $r): string =>
            $r['name'] . ' — ' . date_fr($r['next_renewal_on'])
            . ' (' . ((int) $r['days_left'] < 0 ? 'dépassée' : 'dans ' . (int) $r['days_left'] . ' j') . ', '
            . money((float) $r['amount']) . ')',
            $autresAlertes['renewals'])];
}
if ($autresAlertes['no_card'] !== []) {
    $secondaires[] = ['icone' => 'fa-credit-card', 'titre' => 'Services payants sans carte rattachée', 'items' =>
        array_map(static fn (array $r): string => (string) $r['name'], $autresAlertes['no_card'])];
}
if ($autresAlertes['no_owner'] !== []) {
    $secondaires[] = ['icone' => 'fa-user-slash', 'titre' => 'Services sans référent', 'items' =>
        array_map(static fn (array $r): string => (string) $r['name'], $autresAlertes['no_owner'])];
}
if ($autresAlertes['unused_cards'] !== []) {
    $secondaires[] = ['icone' => 'fa-scissors', 'titre' => 'Cartes actives sans aucun service — candidates à la résiliation', 'items' =>
        array_map(static fn (array $r): string => $r['label'] . ' ••••' . $r['last4'], $autresAlertes['unused_cards'])];
}

if ($secondaires !== []) { ?>
                <div class="alert-block level-info">
                    <div class="row g-3">
<?php foreach ($secondaires as $bloc) { ?>
                        <div class="col-md-6">
                            <div class="alert-title text-sm">
                                <i class="fa-solid <?= h($bloc['icone']) ?> me-2 text-secondary" aria-hidden="true"></i>
                                <?= h($bloc['titre']) ?>
                            </div>
                            <ul class="text-sm mb-0 ps-4">
<?php foreach ($bloc['items'] as $item) { ?>
                                <li><?= h($item) ?></li>
<?php } ?>
                            </ul>
                        </div>
<?php } ?>
                    </div>
                </div>
<?php } ?>
<?php } ?>

                <!-- ==================== INDICATEURS ==================== -->
                <div class="row">
                    <div class="col-12">
                        <div class="kpi-grid my-3">
                            <div class="kpi">
                                <div class="kpi-label">Coût mensualisé</div>
                                <div class="kpi-value"><?= h(money($totaux['monthly'])) ?></div>
                                <div class="kpi-hint">services actifs uniquement</div>
                            </div>
                            <div class="kpi">
                                <div class="kpi-label">Coût annualisé</div>
                                <div class="kpi-value"><?= h(money($totaux['monthly'] * 12)) ?></div>
                                <div class="kpi-hint">projection sur 12 mois</div>
                            </div>
                            <div class="kpi">
                                <div class="kpi-label">Services actifs</div>
                                <div class="kpi-value"><?= (int) $totaux['active'] ?></div>
                                <div class="kpi-hint">sur <?= (int) $totaux['total'] ?> enregistrés</div>
                            </div>
                            <div class="kpi">
                                <div class="kpi-label">Cartes utilisables</div>
                                <div class="kpi-value"><?= $nbCartesValides ?></div>
                                <div class="kpi-hint">ni expirées, ni résiliées</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <!-- Répartition par carte -->
                    <div class="col-lg-6">
                        <div class="kpi h-100">
                            <div class="kpi-label mb-2">Dépense mensuelle par carte</div>
                            <?= render_card_breakdown() ?>
                        </div>
                    </div>

                    <!-- Répartition par référent -->
                    <div class="col-lg-6">
                        <div class="kpi h-100">
                            <div class="kpi-label mb-2">Dépense mensuelle par référent</div>
                            <div class="breakdown">
<?php foreach ($parReferent as $ligne) {
    $part = $maxReferent > 0 ? ((float) $ligne['cout_mensuel'] / $maxReferent) * 100 : 0;
?>
                                <div class="breakdown-row">
                                    <span class="text-truncate">
                                        <?= $ligne['owner_id'] === null
                                            ? '<span class="text-warning">Sans référent</span>'
                                            : h(full_name($ligne)) ?>
                                        <span class="text-secondary text-xsm">(<?= (int) $ligne['nb_services'] ?>)</span>
                                    </span>
                                    <span class="breakdown-track">
                                        <span class="breakdown-bar" style="--bar-width: <?= number_format($part, 2, '.', '') ?>%"></span>
                                    </span>
                                    <span class="text-nowrap"><?= h(money((float) $ligne['cout_mensuel'])) ?></span>
                                </div>
<?php } ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Derniers services modifiés -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="kpi">
                            <div class="kpi-label mb-2">Derniers services modifiés</div>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Service</th>
                                            <th>Carte</th>
                                            <th class="text-end">Mensualisé</th>
                                            <th>Référent</th>
                                            <th>Modifié le</th>
                                        </tr>
                                    </thead>
                                    <tbody>
<?php foreach ($derniersServices as $s) { ?>
                                        <tr>
                                            <td><?= h((string) $s['name']) ?></td>
                                            <td>
                                                <?= $s['card_id'] === null
                                                    ? '<span class="text-secondary">—</span>'
                                                    : '••••&nbsp;' . h((string) $s['card_last4']) ?>
                                            </td>
                                            <td class="text-end"><?= h(money((float) $s['monthly_cost'])) ?></td>
                                            <td>
                                                <?= $s['owner_id'] === null
                                                    ? '<span class="text-secondary">—</span>'
                                                    : h(trim((string) $s['owner_first_name'] . ' ' . (string) $s['owner_last_name'])) ?>
                                            </td>
                                            <td class="text-sm text-secondary"><?= h(datetime_fr($s['updated_at'])) ?></td>
                                        </tr>
<?php } ?>
                                    </tbody>
                                </table>
                            </div>
                            <a class="btn btn-sm btn-outline-secondary mt-2" href="services_liste.php">
                                Voir tous les services
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

<?php include __DIR__ . '/inc_footer.php'; ?>
    </body>
</html>
