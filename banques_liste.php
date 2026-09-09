<?php
/**
 * ---------------------------------------------------------------------
 * Comptes bancaires
 * ---------------------------------------------------------------------
 * Niveau au-dessus des cartes : une banque appartient à une société du
 * groupe et porte une ou plusieurs cartes. C'est ce qui permet de
 * remonter la dépense jusqu'à la société qui la supporte.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';
require_login();

$banques = fetch_banks();

// Regroupement par société pour l'affichage.
$parSociete = [];
foreach ($banques as $banque) {
    $parSociete[(string) $banque['company']][] = $banque;
}

$title   = 'Banques — ' . $config['app']['name'];
$page_id = 'page_banques';
$menu    = 'banques';

include __DIR__ . '/inc_header.php';
?>
        <section>
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12 d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <h1 class="mb-1">Comptes bancaires</h1>
                            <p class="text-secondary text-sm mb-0">
                                Chaque carte dépend d'un compte, et chaque compte d'une société du groupe.
                            </p>
                        </div>
                        <button type="button" class="btn btn-primary" data-daf-popup="popup_banque.php">
                            <i class="fa-solid fa-plus me-2" aria-hidden="true"></i>Ajouter une banque
                        </button>
                    </div>
                </div>

<?php if ($banques === []) { ?>
                <div class="empty-state">
                    <div><i class="fa-solid fa-building-columns" aria-hidden="true"></i></div>
                    <p class="mb-3">
                        Aucun compte bancaire enregistré.<br>
                        C'est le point de départ : une carte ne peut exister sans banque.
                    </p>
                    <button type="button" class="btn btn-primary" data-daf-popup="popup_banque.php">
                        Créer le premier compte
                    </button>
                </div>
<?php } ?>

<?php foreach (companies() as $cle => $libelle) {
    if (!isset($parSociete[$cle])) {
        continue;
    }

    $totalSociete = 0.0;
    foreach ($parSociete[$cle] as $b) {
        $totalSociete += (float) $b['cout_mensuel'];
    }
?>
                <div class="row mt-3">
                    <div class="col-12 d-flex justify-content-between align-items-baseline">
                        <h2 class="h5 mb-2">
                            <i class="fa-solid fa-building fa-fw me-2 text-secondary" aria-hidden="true"></i>
                            <?= h($libelle) ?>
                        </h2>
                        <span class="text-sm text-secondary">
                            <?= h(money($totalSociete)) ?> / mois
                        </span>
                    </div>
                </div>

                <div class="card-grid">
<?php foreach ($parSociete[$cle] as $b) { ?>
                    <article class="card-tile">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-bold"><?= h((string) $b['name']) ?></div>
<?php if ((int) $b['nb_cartes_actives'] !== (int) $b['nb_cartes']) { ?>
                                <div class="text-xsm text-secondary">
                                    dont <?= (int) $b['nb_cartes'] - (int) $b['nb_cartes_actives'] ?> résiliée<?= ((int) $b['nb_cartes'] - (int) $b['nb_cartes_actives']) > 1 ? 's' : '' ?>
                                </div>
<?php } ?>
                            </div>
                            <span class="badge text-bg-secondary text-nowrap">
                                <?= (int) $b['nb_cartes'] ?> carte<?= (int) $b['nb_cartes'] > 1 ? 's' : '' ?>
                            </span>
                        </div>

<?php if ($b['notes'] !== null && trim((string) $b['notes']) !== '') { ?>
                        <p class="text-xsm text-secondary mb-0"><?= nl2br(h((string) $b['notes'])) ?></p>
<?php } ?>

                        <div class="card-figures">
                            <span>
                                <strong><?= (int) $b['nb_services'] ?></strong>
                                service<?= (int) $b['nb_services'] > 1 ? 's' : '' ?> actif<?= (int) $b['nb_services'] > 1 ? 's' : '' ?>
                            </span>
                            <span><strong><?= h(money((float) $b['cout_mensuel'])) ?></strong> / mois</span>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
<?php if ((int) $b['nb_cartes'] > 0) { ?>
                            <a class="btn btn-sm btn-outline-secondary" href="cartes_liste.php?banque=<?= (int) $b['id'] ?>">
                                Voir les cartes
                            </a>
<?php } ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    data-daf-popup="popup_banque.php?id=<?= (int) $b['id'] ?>">
                                <i class="fa-solid fa-pen-to-square me-1" aria-hidden="true"></i>Modifier
                            </button>
<?php if ((int) $b['nb_cartes'] === 0) { ?>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    data-daf-action="banque_delete"
                                    data-daf-id="<?= (int) $b['id'] ?>"
                                    data-daf-confirm-title="Supprimer ce compte"
                                    data-daf-confirm="Supprimer définitivement « <?= h((string) $b['name']) ?> » ? Aucune carte n'y est rattachée."
                                    data-daf-confirm-label="Supprimer">
                                <i class="fa-solid fa-trash-can me-1" aria-hidden="true"></i>Supprimer
                            </button>
<?php } ?>
                        </div>
                    </article>
<?php } ?>
                </div>
<?php } ?>
            </div>
        </section>

<?php include __DIR__ . '/inc_footer.php'; ?>
    </body>
</html>
