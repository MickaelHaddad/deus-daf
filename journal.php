<?php
/**
 * ---------------------------------------------------------------------
 * Journal d'activité — administrateurs uniquement
 * ---------------------------------------------------------------------
 * Pagination côté serveur : cette table est la seule qui grossisse
 * indéfiniment, il n'est pas question de tout charger en mémoire.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';
require_admin();

const JOURNAL_PAR_PAGE = 50;

// ---------------------------------------------------------------------
// Filtres
// ---------------------------------------------------------------------
$page       = max(1, (int) ($_GET['page'] ?? 1));
$filtreUser = (int) ($_GET['utilisateur'] ?? 0);
$filtreType = (string) ($_GET['entite'] ?? '');
$typesConnus = ['user', 'bank', 'card', 'service', 'setting'];

if (!in_array($filtreType, $typesConnus, true)) {
    $filtreType = '';
}

$conditions = [];
$params     = [];

if ($filtreUser > 0) {
    $conditions[] = 'l.user_id = ?';
    $params[]     = $filtreUser;
}
if ($filtreType !== '') {
    $conditions[] = 'l.entity_type = ?';
    $params[]     = $filtreType;
}

$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

// ---------------------------------------------------------------------
// Lecture
// ---------------------------------------------------------------------
$stmt = $sql->prepare('SELECT COUNT(*) FROM fi_activity_log l' . $where);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

$nbPages = max(1, (int) ceil($total / JOURNAL_PAR_PAGE));
$page    = min($page, $nbPages);
$offset  = ($page - 1) * JOURNAL_PAR_PAGE;

$stmt = $sql->prepare(
    'SELECT l.*, u.first_name, u.last_name, INET6_NTOA(l.ip_address) AS ip
       FROM fi_activity_log l
       LEFT JOIN fi_users u ON u.id = l.user_id'
    . $where .
    ' ORDER BY l.id DESC LIMIT ' . JOURNAL_PAR_PAGE . ' OFFSET ' . $offset
);
$stmt->execute($params);
$entrees = $stmt->fetchAll();

$utilisateurs = $sql->query(
    'SELECT id, first_name, last_name FROM fi_users ORDER BY last_name, first_name'
)->fetchAll();

/** Libellé français d'une action journalisée. */
function action_label(string $action): array
{
    return match ($action) {
        'create'          => ['Création', 'text-bg-success'],
        'update'          => ['Modification', 'text-bg-info'],
        'delete'          => ['Suppression', 'text-bg-danger'],
        'login'           => ['Connexion', 'text-bg-secondary'],
        'logout'          => ['Déconnexion', 'text-bg-secondary'],
        'login_failed'    => ['Échec de connexion', 'text-bg-warning'],
        'password_change' => ['Mot de passe modifié', 'text-bg-info'],
        'enable'          => ['Réactivation', 'text-bg-success'],
        'disable'         => ['Désactivation', 'text-bg-warning'],
        'export'          => ['Export CSV', 'text-bg-secondary'],
        default           => [$action, 'text-bg-secondary'],
    };
}

/** Libellé français d'un type d'entité. */
function entity_label(?string $type): string
{
    return match ($type) {
        'user'    => 'Utilisateur',
        'bank'    => 'Banque',
        'card'    => 'Carte',
        'service' => 'Service',
        'setting' => 'Réglage',
        default   => '—',
    };
}

/** Rend le diff JSON d'une entrée sous une forme lisible. */
function render_changes(?string $json): string
{
    if ($json === null || $json === '') {
        return '<span class="text-secondary">—</span>';
    }

    $changes = json_decode($json, true);

    if (!is_array($changes) || $changes === []) {
        return '<span class="text-secondary">—</span>';
    }

    $lignes = [];

    foreach ($changes as $champ => $valeurs) {
        $avant  = $valeurs['from'] ?? null;
        $apres  = $valeurs['to'] ?? null;
        $avant  = ($avant === null || $avant === '') ? '∅' : (string) $avant;
        $apres  = ($apres === null || $apres === '') ? '∅' : (string) $apres;

        $lignes[] = '<code class="text-xsm">' . h((string) $champ) . '</code> : '
            . h(mb_substr($avant, 0, 40)) . ' → <strong>' . h(mb_substr($apres, 0, 40)) . '</strong>';
    }

    return '<div class="text-xsm">' . implode('<br>', $lignes) . '</div>';
}

$title   = "Journal d'activité — " . $config['app']['name'];
$page_id = 'page_journal';
$menu    = 'admin';

include __DIR__ . '/inc_header.php';
?>
        <section>
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        <h1>Journal d'activité</h1>
                        <p class="text-secondary text-sm">
                            <?= $total ?> entrée<?= $total > 1 ? 's' : '' ?> — les libellés sont figés
                            au moment de l'action, un renommage ultérieur ne réécrit pas l'historique.
                        </p>
                    </div>
                </div>

                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label text-xsm mb-1" for="f_utilisateur">Utilisateur</label>
                        <select class="form-select form-select-sm" id="f_utilisateur" name="utilisateur">
                            <option value="0">Tous</option>
<?php foreach ($utilisateurs as $u) { ?>
                            <option value="<?= (int) $u['id'] ?>" <?= $filtreUser === (int) $u['id'] ? 'selected' : '' ?>>
                                <?= h(full_name($u)) ?>
                            </option>
<?php } ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-xsm mb-1" for="f_entite">Type d'objet</label>
                        <select class="form-select form-select-sm" id="f_entite" name="entite">
                            <option value="">Tous</option>
<?php foreach ($typesConnus as $t) { ?>
                            <option value="<?= h($t) ?>" <?= $filtreType === $t ? 'selected' : '' ?>>
                                <?= h(entity_label($t)) ?>
                            </option>
<?php } ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">Filtrer</button>
                        <a class="btn btn-sm btn-outline-secondary" href="journal.php">Réinitialiser</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr class="no-wrap">
                                <th>Date</th>
                                <th>Auteur</th>
                                <th>Action</th>
                                <th>Objet</th>
                                <th>Détail</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
<?php foreach ($entrees as $e) {
    [$libelle, $classe] = action_label((string) $e['action']);
?>
                            <tr>
                                <td class="text-sm text-nowrap"><?= h(datetime_fr($e['created_at'])) ?></td>
                                <td class="text-sm">
                                    <?= $e['user_id'] === null
                                        ? '<span class="text-secondary">—</span>'
                                        : h(trim((string) $e['first_name'] . ' ' . (string) $e['last_name'])) ?>
                                </td>
                                <td><span class="badge <?= h($classe) ?>"><?= h($libelle) ?></span></td>
                                <td class="text-sm">
                                    <span class="text-secondary text-xsm d-block"><?= h(entity_label($e['entity_type'])) ?></span>
                                    <?= h((string) ($e['entity_label'] ?? '—')) ?>
                                </td>
                                <td><?= render_changes($e['changes']) ?></td>
                                <td class="text-xsm text-secondary"><?= h((string) ($e['ip'] ?? '—')) ?></td>
                            </tr>
<?php } ?>
<?php if ($entrees === []) { ?>
                            <tr>
                                <td colspan="6" class="text-center text-secondary py-4">
                                    Aucune entrée pour ce filtre.
                                </td>
                            </tr>
<?php } ?>
                        </tbody>
                    </table>
                </div>

<?php if ($nbPages > 1) {
    $base = 'journal.php?utilisateur=' . $filtreUser . '&entite=' . urlencode($filtreType) . '&page=';
?>
                <nav aria-label="Pagination du journal">
                    <ul class="pagination pagination-sm">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= h($base . ($page - 1)) ?>">Précédent</a>
                        </li>
                        <li class="page-item disabled">
                            <span class="page-link">Page <?= $page ?> sur <?= $nbPages ?></span>
                        </li>
                        <li class="page-item <?= $page >= $nbPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= h($base . ($page + 1)) ?>">Suivant</a>
                        </li>
                    </ul>
                </nav>
<?php } ?>
            </div>
        </section>

<?php include __DIR__ . '/inc_footer.php'; ?>
    </body>
</html>
