<?php
/**
 * ---------------------------------------------------------------------
 * Modale de création / modification d'un compte bancaire
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';
require_login();

$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$banque = null;

if ($id > 0) {
    $stmt = $sql->prepare(
        'SELECT b.*, (SELECT COUNT(*) FROM fi_cards c WHERE c.bank_id = b.id) AS nb_cartes
           FROM fi_banks b WHERE b.id = ?'
    );
    $stmt->execute([$id]);
    $banque = $stmt->fetch();

    if ($banque === false) {
        http_response_code(404);
        exit('Compte bancaire introuvable.');
    }
}

$creation = $banque === null;
?>
<div class="modal fade" id="modalBanque" tabindex="-1" aria-labelledby="titreModalBanque" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form class="daf-ajax-form" data-action="banque_save" data-close-modal="1" novalidate>
                <input type="hidden" name="id" value="<?= $creation ? '' : (int) $banque['id'] ?>">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="titreModalBanque">
                        <?= $creation ? 'Nouveau compte bancaire' : 'Modifier ' . h((string) $banque['name']) ?>
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="b_name">Nom de la banque</label>
                            <input type="text" class="form-control" id="b_name" name="name" maxlength="120" required
                                   placeholder="Qonto, BNP Paribas, Revolut…"
                                   value="<?= h((string) ($banque['name'] ?? '')) ?>">
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="b_company">Société titulaire du compte</label>
                            <select class="form-select" id="b_company" name="company" required>
                                <option value="">— Choisir —</option>
<?php foreach (companies() as $cle => $libelle) { ?>
                                <option value="<?= h($cle) ?>"
                                    <?= ($banque['company'] ?? '') === $cle ? 'selected' : '' ?>>
                                    <?= h($libelle) ?>
                                </option>
<?php } ?>
                            </select>
                            <div class="form-text">
                                Détermine à quelle société sont imputées les dépenses des cartes de ce compte.
                            </div>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="b_notes">Commentaire</label>
                            <textarea class="form-control" id="b_notes" name="notes" rows="2"
                                      placeholder="Agence, conseiller, usage du compte…"><?= h((string) ($banque['notes'] ?? '')) ?></textarea>
                        </div>

<?php if (!$creation && (int) $banque['nb_cartes'] > 0) { ?>
                        <div class="col-12">
                            <div class="alert alert-info py-2 text-sm mb-0" role="status">
                                <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>
                                <?= (int) $banque['nb_cartes'] ?> carte<?= (int) $banque['nb_cartes'] > 1 ? 's dépendent' : ' dépend' ?>
                                de ce compte. Changer la société ré-impute leurs dépenses.
                            </div>
                        </div>
<?php } ?>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">
                        <?= $creation ? 'Créer le compte' : 'Enregistrer' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
