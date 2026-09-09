<?php
/**
 * ---------------------------------------------------------------------
 * Modale de création / modification d'une carte bancaire
 * ---------------------------------------------------------------------
 * PCI DSS : ce formulaire ne propose ni numéro complet, ni CVV/CVC, et
 * le traitement côté serveur n'accepte aucun champ de ce type.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';
require_login();

$id    = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$carte = null;

if ($id > 0) {
    $stmt = $sql->prepare(
        "SELECT c.*,
                (SELECT COUNT(*) FROM fi_services s WHERE s.card_id = c.id AND s.status = 'active') AS nb_services
           FROM fi_cards c WHERE c.id = ?"
    );
    $stmt->execute([$id]);
    $carte = $stmt->fetch();

    if ($carte === false) {
        http_response_code(404);
        exit('Carte introuvable.');
    }
}

$creation = $carte === null;

// Titulaires proposés : comptes actifs, plus le titulaire actuel même
// s'il a été désactivé depuis, pour ne pas le perdre silencieusement.
$stmt = $sql->prepare(
    'SELECT id, first_name, last_name, is_active
       FROM fi_users
      WHERE is_active = 1 OR id = ?
      ORDER BY last_name, first_name'
);
$stmt->execute([$creation ? 0 : (int) $carte['holder_id']]);
$titulaires = $stmt->fetchAll();

$expiryValeur = $creation ? '' : date('m/y', (int) strtotime((string) $carte['expires_on']));
?>
<div class="modal fade" id="modalCarte" tabindex="-1" aria-labelledby="titreModalCarte" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form class="daf-ajax-form" data-action="carte_save" data-close-modal="1" novalidate>
                <input type="hidden" name="id" value="<?= $creation ? '' : (int) $carte['id'] ?>">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="titreModalCarte">
                        <?= $creation ? 'Nouvelle carte' : 'Modifier ' . h((string) $carte['label']) ?>
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="c_label">Libellé</label>
                            <input type="text" class="form-control" id="c_label" name="label" maxlength="120" required
                                   placeholder="CB Pro Qonto — Mickaël"
                                   value="<?= h((string) ($carte['label'] ?? '')) ?>">
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-5">
                            <label class="form-label" for="c_last4">4 derniers chiffres</label>
                            <input type="text" class="form-control input-expiry" id="c_last4" name="last4"
                                   inputmode="numeric" maxlength="4" pattern="[0-9]{4}" required
                                   autocomplete="off" placeholder="4242" data-daf-mask="digits"
                                   value="<?= h((string) ($carte['last4'] ?? '')) ?>">
                            <div class="form-text">Jamais le numéro complet.</div>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="c_expiry">Expiration</label>
                            <input type="text" class="form-control input-expiry" id="c_expiry" name="expiry"
                                   inputmode="numeric" maxlength="7" required autocomplete="off"
                                   placeholder="MM/AA" data-daf-mask="expiry" value="<?= h($expiryValeur) ?>">
                            <div class="form-text">Valable jusqu'à la fin du mois.</div>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="c_type">Type</label>
                            <select class="form-select" id="c_type" name="type">
<?php foreach (card_types() as $value => $label) { ?>
                                <option value="<?= h($value) ?>" <?= ($carte['type'] ?? 'debit') === $value ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
<?php } ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="c_holder_id">Titulaire</label>
                            <select class="form-select" id="c_holder_id" name="holder_id" required>
                                <option value="">— Choisir —</option>
<?php foreach ($titulaires as $t) { ?>
                                <option value="<?= (int) $t['id'] ?>"
                                    <?= (int) ($carte['holder_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>>
                                    <?= h(full_name($t)) ?><?= (int) $t['is_active'] === 0 ? ' (compte désactivé)' : '' ?>
                                </option>
<?php } ?>
                            </select>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="c_issuer">Banque / émetteur</label>
                            <input type="text" class="form-control" id="c_issuer" name="issuer" maxlength="80"
                                   placeholder="Qonto, BNP, Revolut…"
                                   value="<?= h((string) ($carte['issuer'] ?? '')) ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="c_notes">Commentaire</label>
                            <textarea class="form-control" id="c_notes" name="notes" rows="2"
                                      placeholder="À quoi sert cette carte, plafond, démarches en cours…"><?= h((string) ($carte['notes'] ?? '')) ?></textarea>
                        </div>

                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="c_cancelled" name="cancelled"
                                       value="1" <?= ($carte['status'] ?? 'active') === 'cancelled' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="c_cancelled">
                                    Carte résiliée — elle sort des alertes et des totaux
                                </label>
                            </div>
<?php if (!$creation && (int) $carte['nb_services'] > 0) { ?>
                            <div class="alert alert-warning py-2 text-sm mt-2 mb-0" role="status">
                                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                <?= (int) $carte['nb_services'] ?> service<?= (int) $carte['nb_services'] > 1 ? 's actifs sont' : ' actif est' ?>
                                encore payé<?= (int) $carte['nb_services'] > 1 ? 's' : '' ?> par cette carte.
                                Réaffectez-les avant de la marquer résiliée.
                            </div>
<?php } ?>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">
                        <?= $creation ? 'Créer la carte' : 'Enregistrer' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

