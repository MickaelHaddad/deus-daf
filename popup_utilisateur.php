<?php
/**
 * ---------------------------------------------------------------------
 * Modale de création / modification d'un utilisateur
 * ---------------------------------------------------------------------
 * Chargée en AJAX dans #modalContainer par DAF.openPopup().
 * Ne renvoie que le fragment HTML de la modale, sans layout.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';
require_admin();

$id          = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$utilisateur = null;

if ($id > 0) {
    $stmt = $sql->prepare(
        'SELECT id, first_name, last_name, email, role, staff_type, is_active
           FROM fi_users WHERE id = ?'
    );
    $stmt->execute([$id]);
    $utilisateur = $stmt->fetch();

    if ($utilisateur === false) {
        http_response_code(404);
        exit('Utilisateur introuvable.');
    }
}

$creation  = $utilisateur === null;
$estMoi    = !$creation && (int) $utilisateur['id'] === (int) current_user()['id'];
$minLength = (int) ($config['security']['password_min_length'] ?? 12);
?>
<div class="modal fade" id="modalUtilisateur" tabindex="-1" aria-labelledby="titreModalUtilisateur" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form class="daf-ajax-form" data-action="utilisateur_save" data-close-modal="1"
                  data-success="<?= $creation ? 'Utilisateur créé.' : 'Utilisateur mis à jour.' ?>" novalidate>
                <input type="hidden" name="id" value="<?= $creation ? '' : (int) $utilisateur['id'] ?>">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="titreModalUtilisateur">
                        <?= $creation ? 'Nouvel utilisateur' : 'Modifier ' . h(full_name($utilisateur)) ?>
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="u_first_name">Prénom</label>
                            <input type="text" class="form-control" id="u_first_name" name="first_name"
                                   maxlength="80" required
                                   value="<?= h((string) ($utilisateur['first_name'] ?? '')) ?>">
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="u_last_name">Nom</label>
                            <input type="text" class="form-control" id="u_last_name" name="last_name"
                                   maxlength="80" required
                                   value="<?= h((string) ($utilisateur['last_name'] ?? '')) ?>">
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="u_email">Adresse email</label>
                            <input type="email" class="form-control" id="u_email" name="email"
                                   maxlength="190" required autocomplete="off"
                                   value="<?= h((string) ($utilisateur['email'] ?? '')) ?>">
                            <div class="form-text">Sert d'identifiant de connexion.</div>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="u_role">Rôle</label>
                            <select class="form-select" id="u_role" name="role" <?= $estMoi ? 'disabled' : '' ?>>
<?php foreach (['member' => 'Membre', 'admin' => 'Administrateur'] as $value => $label) { ?>
                                <option value="<?= h($value) ?>"
                                    <?= ($utilisateur['role'] ?? 'member') === $value ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
<?php } ?>
                            </select>
<?php if ($estMoi) { ?>
                            <input type="hidden" name="role" value="<?= h((string) $utilisateur['role']) ?>">
                            <div class="form-text">Vous ne pouvez pas modifier votre propre rôle.</div>
<?php } else { ?>
                            <div class="form-text">Un administrateur gère les comptes et les réglages.</div>
<?php } ?>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="u_staff_type">Position</label>
                            <select class="form-select" id="u_staff_type" name="staff_type">
<?php foreach (['collaborator' => 'Collaborateur', 'director' => 'Dirigeant'] as $value => $label) { ?>
                                <option value="<?= h($value) ?>"
                                    <?= ($utilisateur['staff_type'] ?? 'collaborator') === $value ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
<?php } ?>
                            </select>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-12">
                            <hr class="my-1">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="u_password">
                                Mot de passe<?= $creation ? '' : ' <span class="text-secondary fw-normal">(inchangé si vide)</span>' ?>
                            </label>
                            <input type="password" class="form-control" id="u_password" name="password"
                                   autocomplete="new-password" <?= $creation ? 'required' : '' ?>>
                            <div class="form-text"><?= $minLength ?> caractères minimum, avec majuscules, minuscules et chiffres.</div>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="u_password_confirm">Confirmation</label>
                            <input type="password" class="form-control" id="u_password_confirm"
                                   name="password_confirm" autocomplete="new-password" <?= $creation ? 'required' : '' ?>>
                            <div class="invalid-feedback"></div>
                        </div>

<?php if (!$estMoi) { ?>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="u_is_active" name="is_active"
                                       value="1" <?= ($utilisateur['is_active'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="u_is_active">
                                    Compte actif — décocher empêche la connexion sans rien supprimer
                                </label>
                            </div>
                        </div>
<?php } else { ?>
                        <input type="hidden" name="is_active" value="1">
<?php } ?>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">
                        <?= $creation ? 'Créer l\'utilisateur' : 'Enregistrer' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
