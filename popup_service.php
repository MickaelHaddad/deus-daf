<?php
/**
 * ---------------------------------------------------------------------
 * Modale de création / modification d'un service
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';
require_login();

$id      = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$service = null;

if ($id > 0) {
    $service = fetch_service($id);

    if ($service === null) {
        http_response_code(404);
        exit('Service introuvable.');
    }
}

$creation = $service === null;

// Cartes proposées : toutes sauf les résiliées, plus la carte
// actuellement rattachée même si elle est résiliée ou expirée.
$stmt = $sql->prepare(
    "SELECT c.id, c.label, c.last4, c.status, c.expires_on, b.company
       FROM fi_cards c
       JOIN fi_banks b ON b.id = c.bank_id
      WHERE c.status = 'active' OR c.id = ?
      ORDER BY c.label"
);
$stmt->execute([$creation ? 0 : (int) ($service['card_id'] ?? 0)]);
$cartes = $stmt->fetchAll();

$stmt = $sql->prepare(
    'SELECT id, first_name, last_name, is_active
       FROM fi_users
      WHERE is_active = 1 OR id = ?
      ORDER BY last_name, first_name'
);
$stmt->execute([$creation ? 0 : (int) ($service['owner_id'] ?? 0)]);
$referents = $stmt->fetchAll();
?>
<div class="modal fade" id="modalService" tabindex="-1" aria-labelledby="titreModalService" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form class="daf-ajax-form" data-action="service_save" data-close-modal="1" novalidate>
                <input type="hidden" name="id" value="<?= $creation ? '' : (int) $service['id'] ?>">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="titreModalService">
                        <?= $creation ? 'Nouveau service' : 'Modifier ' . h((string) $service['name']) ?>
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label" for="s_name">Nom du service</label>
                            <input type="text" class="form-control" id="s_name" name="name" maxlength="150" required
                                   placeholder="Figma Professional"
                                   value="<?= h((string) ($service['name'] ?? '')) ?>">
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-5">
                            <label class="form-label" for="s_status">Statut</label>
                            <select class="form-select" id="s_status" name="status">
<?php foreach (service_statuses() as $value => $label) { ?>
                                <option value="<?= h($value) ?>" <?= ($service['status'] ?? 'active') === $value ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
<?php } ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="s_url">Adresse du site</label>
                            <input type="url" class="form-control" id="s_url" name="url" maxlength="500"
                                   placeholder="https://www.figma.com/"
                                   value="<?= h((string) ($service['url'] ?? '')) ?>">
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="s_billing_cycle">Type d'abonnement</label>
                            <select class="form-select" id="s_billing_cycle" name="billing_cycle">
<?php foreach (billing_cycles() as $value => $label) { ?>
                                <option value="<?= h($value) ?>" <?= ($service['billing_cycle'] ?? 'monthly') === $value ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
<?php } ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="s_amount">Montant théorique</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="s_amount" name="amount"
                                       inputmode="decimal" placeholder="29,90"
                                       value="<?= $creation ? '' : h(number_format((float) $service['amount'], 2, ',', '')) ?>">
                                <span class="input-group-text">€</span>
                            </div>
                            <div class="form-text" id="aideMontant">Par période de facturation.</div>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="s_next_renewal_on">Prochaine échéance</label>
                            <input type="date" class="form-control" id="s_next_renewal_on" name="next_renewal_on"
                                   value="<?= h((string) ($service['next_renewal_on'] ?? '')) ?>">
                            <div class="form-text">Facultatif.</div>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="s_card_id">Carte utilisée</label>
                            <select class="form-select" id="s_card_id" name="card_id">
                                <option value="">Aucune — service gratuit ou payé autrement</option>
<?php foreach ($cartes as $c) {
    $statut = card_effective_status($c);
    $suffixe = match ($statut) {
        'expired'   => ' — EXPIRÉE',
        'cancelled' => ' — résiliée',
        'expiring'  => ' — expire bientôt',
        default     => '',
    };
?>
                                <option value="<?= (int) $c['id'] ?>"
                                    <?= (int) ($service['card_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                                    <?= h((string) $c['label']) ?> ••••<?= h((string) $c['last4']) ?> (<?= h(company_label((string) $c['company'])) ?>)<?= h($suffixe) ?>
                                </option>
<?php } ?>
                            </select>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="s_owner_id">Utilisateur référent</label>
                            <select class="form-select" id="s_owner_id" name="owner_id">
                                <option value="">Aucun — à désigner</option>
<?php foreach ($referents as $r) { ?>
                                <option value="<?= (int) $r['id'] ?>"
                                    <?= (int) ($service['owner_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>>
                                    <?= h(full_name($r)) ?><?= (int) $r['is_active'] === 0 ? ' (compte désactivé)' : '' ?>
                                </option>
<?php } ?>
                            </select>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="s_notes">À quoi ça sert</label>
                            <textarea class="form-control" id="s_notes" name="notes" rows="3"
                                      placeholder="Usage interne, périmètre couvert, conditions de résiliation…"><?= h((string) ($service['notes'] ?? '')) ?></textarea>
                        </div>

<?php if (!$creation) { ?>
                        <div class="col-12">
                            <p class="text-xsm text-secondary mb-0">
                                Créé le <?= h(datetime_fr($service['created_at'])) ?>,
                                modifié le <?= h(datetime_fr($service['updated_at'])) ?>.
                            </p>
                        </div>
<?php } ?>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">
                        <?= $creation ? 'Créer le service' : 'Enregistrer' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
