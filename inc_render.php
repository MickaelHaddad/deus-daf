<?php
/**
 * ---------------------------------------------------------------------
 * Rendu HTML partagé
 * ---------------------------------------------------------------------
 * Ces fonctions sont utilisées à deux endroits : par les pages, au
 * chargement, et par action.php après un enregistrement, pour renvoyer
 * le fragment mis à jour. Un seul code de rendu, donc aucun risque de
 * divergence entre l'affichage initial et l'affichage rafraîchi.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

if (!defined('DEUS_DAF')) {
    http_response_code(404);
    exit;
}

/**
 * Une ligne du tableau des services.
 *
 * @param array<string,mixed> $s Ligne renvoyée par fetch_service()
 */
function render_service_row(array $s): string
{
    $statutCarte = $s['card_id'] !== null
        ? card_effective_status([
            'status'     => $s['card_status'],
            'expires_on' => $s['card_expires_on'],
        ])
        : null;

    $carteEnPanne = in_array($statutCarte, ['expired', 'cancelled'], true)
        && $s['status'] === 'active';

    $badgeStatut = service_status_badge((string) $s['status']);
    $mensuel     = (float) $s['monthly_cost'];

    ob_start();
    ?>
<tr data-service-id="<?= (int) $s['id'] ?>"
    data-card="<?= (int) ($s['card_id'] ?? 0) ?>"
    data-owner="<?= (int) ($s['owner_id'] ?? 0) ?>"
    data-cycle="<?= h((string) $s['billing_cycle']) ?>"
    data-status="<?= h((string) $s['status']) ?>"
    data-alert="<?= $carteEnPanne ? '1' : '0' ?>">

    <td>
        <div class="d-flex align-items-center gap-2">
<?php if ($carteEnPanne) { ?>
            <i class="fa-solid fa-triangle-exclamation icon-alert" aria-hidden="true"
               data-bs-toggle="tooltip"
               data-bs-title="<?= h($statutCarte === 'expired'
                   ? 'La carte ••••' . $s['card_last4'] . ' est expirée : ce paiement va échouer.'
                   : 'La carte ••••' . $s['card_last4'] . ' est résiliée : ce paiement va échouer.') ?>"></i>
            <span class="visually-hidden">Paiement menacé :</span>
<?php } ?>
            <span class="fw-medium"><?= h((string) $s['name']) ?></span>
<?php if ($s['url'] !== null && $s['url'] !== '') { ?>
            <a href="<?= h((string) $s['url']) ?>" target="_blank" rel="noopener noreferrer"
               class="text-secondary" data-bs-toggle="tooltip" data-bs-title="Ouvrir <?= h((string) $s['url']) ?>"
               aria-label="Ouvrir le site de <?= h((string) $s['name']) ?> dans un nouvel onglet">
                <i class="fa-solid fa-arrow-up-right-from-square fa-xs" aria-hidden="true"></i>
            </a>
<?php } ?>
        </div>
    </td>

    <td><?= h(billing_cycle_label((string) $s['billing_cycle'])) ?></td>

    <td>
<?php if ($s['card_id'] === null) { ?>
        <span class="text-secondary">—</span>
<?php } else { ?>
        <span class="card-number text-sm">••••&nbsp;<?= h((string) $s['card_last4']) ?></span>
        <span class="text-xsm text-secondary d-block"><?= h((string) $s['card_label']) ?></span>
<?php } ?>
    </td>

    <td class="text-end" data-order="<?= (float) $s['amount'] ?>">
        <?= h(money((float) $s['amount'])) ?>
    </td>

    <td class="text-end" data-order="<?= $mensuel ?>">
        <?= $mensuel > 0 ? h(money($mensuel)) : '<span class="text-secondary">—</span>' ?>
    </td>

    <td>
<?= $s['owner_id'] === null
        ? '<span class="text-warning" data-bs-toggle="tooltip" data-bs-title="Aucun référent désigné">— non défini</span>'
        : h(trim((string) $s['owner_first_name'] . ' ' . (string) $s['owner_last_name'])) ?>
    </td>

    <td data-order="<?= h((string) ($s['next_renewal_on'] ?? '')) ?>">
        <?= h(date_fr($s['next_renewal_on'])) ?>
    </td>

    <td>
        <span class="badge <?= h($badgeStatut['class']) ?>"><?= h($badgeStatut['label']) ?></span>
    </td>

    <td class="text-center text-nowrap">
        <button type="button" class="btn-action text-bg-info"
                data-daf-popup="popup_service.php?id=<?= (int) $s['id'] ?>"
                data-bs-toggle="tooltip" data-bs-title="Modifier"
                aria-label="Modifier <?= h((string) $s['name']) ?>">
            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn-action text-bg-danger"
                data-daf-action="service_delete" data-daf-id="<?= (int) $s['id'] ?>"
                data-daf-confirm-title="Supprimer ce service"
                data-daf-confirm="Supprimer définitivement « <?= h((string) $s['name']) ?> » ? Pour garder une trace, préférez le statut « résilié »."
                data-daf-confirm-label="Supprimer"
                data-bs-toggle="tooltip" data-bs-title="Supprimer"
                aria-label="Supprimer <?= h((string) $s['name']) ?>">
            <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
        </button>
    </td>
</tr>
    <?php
    return (string) ob_get_clean();
}

/**
 * Le bandeau de totaux et la répartition par carte.
 * Recalculé intégralement à chaque appel : après un enregistrement,
 * action.php renvoie ce bloc et le JavaScript remplace l'existant, ce
 * qui évite tout écart entre les chiffres affichés et la base.
 */
function render_service_totals(): string
{
    global $sql;

    $totaux   = service_totals();
    $parCarte = service_breakdown_by_card();

    ob_start();
    ?>
<div class="kpi-grid mb-3">
    <div class="kpi">
        <div class="kpi-label">Coût mensualisé</div>
        <div class="kpi-value"><?= h(money($totaux['monthly'])) ?></div>
        <div class="kpi-hint"><?= (int) $totaux['active'] ?> service<?= $totaux['active'] > 1 ? 's' : '' ?> actif<?= $totaux['active'] > 1 ? 's' : '' ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Coût annualisé</div>
        <div class="kpi-value"><?= h(money($totaux['monthly'] * 12)) ?></div>
        <div class="kpi-hint">projection sur 12 mois</div>
    </div>
    <div class="kpi">
        <div class="kpi-label">À la demande</div>
        <div class="kpi-value"><?= (int) $totaux['on_demand'] ?></div>
        <div class="kpi-hint">services hors montant fixe</div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Sans carte rattachée</div>
        <div class="kpi-value"><?= (int) $totaux['no_card'] ?></div>
        <div class="kpi-hint">services actifs et payants</div>
    </div>
</div>

<?php if ($parCarte !== []) { ?>
<div class="kpi mb-3">
    <div class="kpi-label mb-2">Répartition mensuelle par carte</div>
    <?= render_card_breakdown() ?>
</div>
<?php } ?>
    <?php
    return (string) ob_get_clean();
}

/**
 * Les barres de répartition du coût mensuel par carte, sans habillage.
 * Utilisée telle quelle par le tableau de bord et enveloppée d'un titre
 * par render_service_totals().
 *
 * Les barres sont dessinées en CSS pur, via une variable --bar-width :
 * aucune librairie de graphiques n'est chargée.
 */
function render_card_breakdown(): string
{
    $parCarte = service_breakdown_by_card();
    $maximum  = 0.0;

    foreach ($parCarte as $ligne) {
        $maximum = max($maximum, (float) $ligne['cout_mensuel']);
    }

    if ($parCarte === []) {
        return '<p class="text-sm text-secondary mb-0">Aucune dépense mensuelle enregistrée.</p>';
    }

    ob_start();
    ?>
<div class="breakdown">
<?php foreach ($parCarte as $ligne) {
    $part = $maximum > 0 ? ((float) $ligne['cout_mensuel'] / $maximum) * 100 : 0;
?>
    <div class="breakdown-row">
        <span class="text-truncate">
<?php if ($ligne['card_id'] === null) { ?>
            <span class="text-warning">Sans carte</span>
<?php } else { ?>
            <?= h((string) $ligne['label']) ?>
            <span class="text-secondary">••••<?= h((string) $ligne['last4']) ?></span>
<?php } ?>
        </span>
        <span class="breakdown-track">
            <span class="breakdown-bar" style="--bar-width: <?= number_format($part, 2, '.', '') ?>%"></span>
        </span>
        <span class="text-nowrap"><?= h(money((float) $ligne['cout_mensuel'])) ?></span>
    </div>
<?php } ?>
</div>
    <?php
    return (string) ob_get_clean();
}
