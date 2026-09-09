<?php
/**
 * ---------------------------------------------------------------------
 * action.php — point d'entrée unique des écritures
 * ---------------------------------------------------------------------
 * Toutes les créations, modifications et suppressions passent par ici.
 * La réponse est toujours du JSON au format {success, data, message,
 * errors} ; aucune redirection n'est jamais émise.
 *
 * Contrôles systématiques avant tout traitement :
 *   1. méthode POST ;
 *   2. session valide ;
 *   3. token CSRF valide ;
 *   4. rôle suffisant, action par action.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Méthode non autorisée.', null, 405);
}

require_login(true);
require_csrf();

$action = (string) ($_POST['action'] ?? '');

try {
    switch ($action) {

        // -------------------------------------------------------------
        // Profil de l'utilisateur courant
        // -------------------------------------------------------------
        case 'profil_theme':
            action_profil_theme();
            break;

        case 'profil_password':
            action_profil_password();
            break;

        // -------------------------------------------------------------
        // Utilisateurs — administrateurs uniquement
        // -------------------------------------------------------------
        case 'utilisateur_save':
            action_utilisateur_save();
            break;

        case 'utilisateur_toggle':
            action_utilisateur_toggle();
            break;

        // -------------------------------------------------------------
        // Comptes bancaires
        // -------------------------------------------------------------
        case 'banque_save':
            action_banque_save();
            break;

        case 'banque_delete':
            action_banque_delete();
            break;

        // -------------------------------------------------------------
        // Cartes bancaires
        // -------------------------------------------------------------
        case 'carte_save':
            action_carte_save();
            break;

        case 'carte_delete':
            action_carte_delete();
            break;

        // -------------------------------------------------------------
        // Services et abonnements
        // -------------------------------------------------------------
        case 'service_save':
            action_service_save();
            break;

        case 'service_delete':
            action_service_delete();
            break;

        // -------------------------------------------------------------
        // Réglages — administrateurs uniquement
        // -------------------------------------------------------------
        case 'reglages_save':
            action_reglages_save();
            break;

        // -------------------------------------------------------------
        default:
            json_error('Action inconnue.', null, 400);
    }
} catch (PDOException $e) {
    error_log('[DEUS-DAF] Erreur SQL sur l\'action « ' . $action . ' » : ' . $e->getMessage());

    $message = $isProd
        ? "Une erreur technique est survenue. L'action n'a pas été enregistrée."
        : 'Erreur SQL : ' . $e->getMessage();

    json_error($message, null, 500);
} catch (Throwable $e) {
    error_log('[DEUS-DAF] Erreur sur l\'action « ' . $action . ' » : ' . $e->getMessage());

    $message = $isProd
        ? "Une erreur technique est survenue. L'action n'a pas été enregistrée."
        : 'Erreur : ' . $e->getMessage();

    json_error($message, null, 500);
}

// =====================================================================
// Implémentation des actions
// =====================================================================

/**
 * Enregistre la préférence de thème de l'utilisateur courant.
 * Appelée silencieusement par theme-modes.js à chaque bascule.
 */
function action_profil_theme()
{
    global $sql;

    $theme = (string) ($_POST['theme'] ?? '');

    if (!in_array($theme, ['auto', 'light', 'dark'], true)) {
        json_error('Thème inconnu.', ['theme' => 'Valeur non autorisée.'], 422);
    }

    $me = current_user();

    $stmt = $sql->prepare('UPDATE fi_users SET theme = ?, updated_by = ? WHERE id = ?');
    $stmt->execute([$theme, (int) $me['id'], (int) $me['id']]);

    json_ok(['theme' => $theme]);
}

/**
 * Changement de mot de passe par l'utilisateur lui-même.
 * L'ancien mot de passe est exigé : sans lui, un poste laissé ouvert
 * suffirait à verrouiller le compte de son propriétaire.
 */
function action_profil_password()
{
    global $sql;

    $me      = current_user();
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    $stmt = $sql->prepare('SELECT password_hash FROM fi_users WHERE id = ?');
    $stmt->execute([(int) $me['id']]);
    $hash = (string) $stmt->fetchColumn();

    $errors = [];

    if ($current === '') {
        $errors['current_password'] = 'Saisissez votre mot de passe actuel.';
    } elseif (!password_verify($current, $hash)) {
        $errors['current_password'] = 'Mot de passe actuel incorrect.';
    }

    $problem = password_problem($new);
    if ($problem !== null) {
        $errors['password'] = $problem;
    }
    if (!hash_equals($new, $confirm)) {
        $errors['password_confirm'] = 'Les deux saisies ne correspondent pas.';
    }
    if ($current !== '' && hash_equals($current, $new)) {
        $errors['password'] = 'Le nouveau mot de passe doit être différent de l\'actuel.';
    }

    if ($errors !== []) {
        json_response(false, null, 'Le formulaire contient des erreurs.', $errors, 422);
    }

    $update = $sql->prepare('UPDATE fi_users SET password_hash = ?, updated_by = ? WHERE id = ?');
    $update->execute([hash_password($new), (int) $me['id'], (int) $me['id']]);

    log_activity('password_change', 'user', (int) $me['id'], (string) $me['email']);

    json_ok(null, 'Mot de passe modifié.');
}

// =====================================================================
// Utilisateurs
// =====================================================================

/**
 * Création ou modification d'un utilisateur.
 * Un compte n'est jamais supprimé : il est désactivé.
 */
function action_utilisateur_save()
{
    global $sql;

    require_admin(true);

    $me   = current_user();
    $id   = post_int('id') ?? 0;
    $self = $id > 0 && $id === (int) $me['id'];

    // -----------------------------------------------------------------
    // État avant modification, pour le diff du journal
    // -----------------------------------------------------------------
    $before = null;
    if ($id > 0) {
        $stmt = $sql->prepare(
            'SELECT id, first_name, last_name, email, role, staff_type, is_active
               FROM fi_users WHERE id = ?'
        );
        $stmt->execute([$id]);
        $before = $stmt->fetch();

        if ($before === false) {
            json_error('Cet utilisateur n\'existe plus.', null, 404);
        }
    }

    // -----------------------------------------------------------------
    // Lecture et validation
    // -----------------------------------------------------------------
    $firstName = post_string('first_name', 80);
    $lastName  = post_string('last_name', 80);
    $email     = mb_strtolower(post_string('email', 190));
    $role      = post_enum('role', ['admin', 'member']);
    $staffType = post_enum('staff_type', ['director', 'collaborator']);
    $isActive  = post_bool('is_active');
    $password  = (string) ($_POST['password'] ?? '');
    $confirm   = (string) ($_POST['password_confirm'] ?? '');

    $errors = [];

    if ($firstName === '') {
        $errors['first_name'] = 'Le prénom est obligatoire.';
    }
    if ($lastName === '') {
        $errors['last_name'] = 'Le nom est obligatoire.';
    }

    if ($email === '') {
        $errors['email'] = "L'adresse email est obligatoire.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "Cette adresse email n'est pas valide.";
    } else {
        $check = $sql->prepare('SELECT id FROM fi_users WHERE email = ? AND id <> ?');
        $check->execute([$email, $id]);
        if ($check->fetch() !== false) {
            $errors['email'] = 'Cette adresse est déjà utilisée par un autre compte.';
        }
    }

    if ($role === null) {
        $errors['role'] = 'Rôle invalide.';
    }
    if ($staffType === null) {
        $errors['staff_type'] = 'Position invalide.';
    }

    // Mot de passe : obligatoire à la création, facultatif ensuite.
    $changePassword = $id === 0 || $password !== '';
    if ($changePassword) {
        $problem = password_problem($password);
        if ($problem !== null) {
            $errors['password'] = $problem;
        }
        if (!hash_equals($password, $confirm)) {
            $errors['password_confirm'] = 'Les deux saisies ne correspondent pas.';
        }
    }

    // On ne se retire pas soi-même les droits ni l'accès.
    if ($self) {
        $role     = (string) $before['role'];
        $isActive = true;
    }

    // Filet de sécurité : ne jamais laisser la base sans administrateur
    // actif. Dans le flux actuel il est inatteignable — l'auteur de la
    // requête est lui-même un administrateur actif, donc le compte reste
    // au minimum, et le cas « je me rétrograde moi-même » est déjà neutralisé
    // juste au-dessus. Il est conservé pour que l'invariant tienne encore si
    // ces règles évoluent un jour.
    if ($id > 0 && (string) $before['role'] === 'admin'
        && ($role !== 'admin' || !$isActive)
        && count_active_admins($id) === 0) {
        $errors['role'] = 'C\'est le dernier administrateur actif : désignez-en un autre avant.';
    }

    if ($errors !== []) {
        json_response(false, null, 'Le formulaire contient des erreurs.', $errors, 422);
    }

    // -----------------------------------------------------------------
    // Écriture
    // -----------------------------------------------------------------
    if ($id === 0) {
        $stmt = $sql->prepare(
            'INSERT INTO fi_users
                (first_name, last_name, email, password_hash, role, staff_type, is_active, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $firstName, $lastName, $email, hash_password($password),
            $role, $staffType, $isActive ? 1 : 0, (int) $me['id'], (int) $me['id'],
        ]);

        $id = (int) $sql->lastInsertId();

        log_activity('create', 'user', $id, $email, [
            'role'       => ['from' => null, 'to' => $role],
            'staff_type' => ['from' => null, 'to' => $staffType],
        ]);

        json_ok(['id' => $id], 'Utilisateur créé.', 201);
    }

    $stmt = $sql->prepare(
        'UPDATE fi_users
            SET first_name = ?, last_name = ?, email = ?, role = ?,
                staff_type = ?, is_active = ?, updated_by = ?
          WHERE id = ?'
    );
    $stmt->execute([
        $firstName, $lastName, $email, $role,
        $staffType, $isActive ? 1 : 0, (int) $me['id'], $id,
    ]);

    if ($changePassword) {
        $stmt = $sql->prepare('UPDATE fi_users SET password_hash = ? WHERE id = ?');
        $stmt->execute([hash_password($password), $id]);
    }

    $after = [
        'first_name' => $firstName, 'last_name' => $lastName, 'email' => $email,
        'role' => $role, 'staff_type' => $staffType, 'is_active' => $isActive ? 1 : 0,
    ];
    $changes = diff_fields($before, $after, array_keys($after));

    if ($changePassword) {
        $changes['password'] = ['from' => '—', 'to' => 'modifié'];
    }

    log_activity('update', 'user', $id, $email, $changes);

    json_ok(['id' => $id], 'Utilisateur mis à jour.');
}

/** Activation ou désactivation d'un compte (jamais de suppression). */
function action_utilisateur_toggle()
{
    global $sql;

    require_admin(true);

    $me = current_user();
    $id = post_int('id') ?? 0;

    if ($id === (int) $me['id']) {
        json_error('Vous ne pouvez pas désactiver votre propre compte.', null, 403);
    }

    $stmt = $sql->prepare('SELECT id, email, first_name, last_name, role, is_active FROM fi_users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if ($user === false) {
        json_error('Cet utilisateur n\'existe plus.', null, 404);
    }

    $nouvelEtat = (int) $user['is_active'] === 1 ? 0 : 1;

    if ($nouvelEtat === 0 && (string) $user['role'] === 'admin' && count_active_admins($id) === 0) {
        json_error(
            "C'est le dernier administrateur actif : désignez-en un autre avant de le désactiver.",
            null,
            409
        );
    }

    $update = $sql->prepare('UPDATE fi_users SET is_active = ?, updated_by = ? WHERE id = ?');
    $update->execute([$nouvelEtat, (int) $me['id'], $id]);

    log_activity(
        $nouvelEtat === 1 ? 'enable' : 'disable',
        'user',
        $id,
        (string) $user['email'],
        ['is_active' => ['from' => (int) $user['is_active'], 'to' => $nouvelEtat]]
    );

    json_ok(
        ['id' => $id, 'is_active' => $nouvelEtat],
        $nouvelEtat === 1
            ? full_name($user) . ' peut de nouveau se connecter.'
            : full_name($user) . ' ne peut plus se connecter.'
    );
}

// =====================================================================
// Cartes bancaires
// =====================================================================

/**
 * Création ou modification d'une carte.
 *
 * PCI DSS : aucun champ de numéro complet ni de CVV n'est lu ici, et
 * last4 est contraint à exactement quatre chiffres, côté PHP comme en
 * base. Le statut « expirée » n'est pas saisissable : il se déduit de
 * la date d'expiration à chaque lecture.
 */
function action_carte_save()
{
    global $sql;

    $me = current_user();
    $id = post_int('id') ?? 0;

    $before = null;
    if ($id > 0) {
        $stmt = $sql->prepare('SELECT * FROM fi_cards WHERE id = ?');
        $stmt->execute([$id]);
        $before = $stmt->fetch();

        if ($before === false) {
            json_error('Cette carte n\'existe plus.', null, 404);
        }
    }

    $label    = post_string('label', 120);
    $bankId   = post_int('bank_id');
    // Lecture volontairement large : post_string() tronque à la longueur
    // demandée, or tronquer « 12345 » en « 1234 » enregistrerait les 4
    // PREMIERS chiffres en les faisant passer pour les 4 derniers. On lit
    // donc sans rogner et on refuse toute saisie qui ne fait pas
    // exactement quatre chiffres.
    $last4    = post_string('last4', 32);
    $expiry   = post_string('expiry', 32);
    $holderId = post_int('holder_id');
    $type     = post_enum('type', array_keys(card_types()));
    $notes    = post_string('notes', 2000);
    $status   = post_bool('cancelled') ? 'cancelled' : 'active';

    $errors = [];

    if ($label === '') {
        $errors['label'] = 'Le libellé est obligatoire.';
    }

    if (!preg_match('/^\d{4}$/', $last4)) {
        $errors['last4'] = 'Saisissez exactement les 4 derniers chiffres.';
    }

    // Une carte dépend forcément d'un compte bancaire : c'est lui qui
    // porte la société à laquelle la dépense sera imputée.
    if ($bankId === null || $bankId <= 0) {
        $errors['bank_id'] = 'Choisissez le compte bancaire dont dépend cette carte.';
    } else {
        $check = $sql->prepare('SELECT id FROM fi_banks WHERE id = ?');
        $check->execute([$bankId]);
        if ($check->fetch() === false) {
            $errors['bank_id'] = "Ce compte bancaire n'existe pas.";
        }
    }

    [$expiresOn, $expiryError] = parse_expiry($expiry);
    if ($expiryError !== null) {
        $errors['expiry'] = $expiryError;
    }

    if ($type === null) {
        $errors['type'] = 'Type de carte invalide.';
    }

    if ($holderId === null) {
        $errors['holder_id'] = 'Choisissez un titulaire.';
    } else {
        // Un compte désactivé n'est accepté que s'il était déjà titulaire.
        $check = $sql->prepare('SELECT id FROM fi_users WHERE id = ? AND (is_active = 1 OR id = ?)');
        $check->execute([$holderId, (int) ($before['holder_id'] ?? 0)]);
        if ($check->fetch() === false) {
            $errors['holder_id'] = "Ce titulaire n'est pas disponible.";
        }
    }

    // On ne résilie pas une carte qui paie encore quelque chose.
    if ($status === 'cancelled' && $id > 0) {
        $check = $sql->prepare(
            "SELECT COUNT(*) FROM fi_services WHERE card_id = ? AND status = 'active'"
        );
        $check->execute([$id]);
        $actifs = (int) $check->fetchColumn();

        if ($actifs > 0) {
            $errors['cancelled'] = $actifs . ' service' . ($actifs > 1 ? 's actifs sont' : ' actif est')
                . ' encore payé' . ($actifs > 1 ? 's' : '') . ' par cette carte. Réaffectez-'
                . ($actifs > 1 ? 'les' : 'le') . ' avant de la résilier.';
        }
    }

    if ($errors !== []) {
        json_response(false, null, 'Le formulaire contient des erreurs.', $errors, 422);
    }

    if ($id === 0) {
        $stmt = $sql->prepare(
            'INSERT INTO fi_cards
                (label, last4, expires_on, bank_id, holder_id, type, status, notes, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $label, $last4, $expiresOn, $bankId,
            $holderId, $type, $status, $notes !== '' ? $notes : null,
            (int) $me['id'], (int) $me['id'],
        ]);

        $id = (int) $sql->lastInsertId();

        log_activity('create', 'card', $id, $label . ' ••••' . $last4, [
            'expires_on' => ['from' => null, 'to' => $expiresOn],
        ]);

        json_ok(['id' => $id], 'Carte créée.', 201);
    }

    $stmt = $sql->prepare(
        'UPDATE fi_cards
            SET label = ?, last4 = ?, expires_on = ?, bank_id = ?, holder_id = ?,
                type = ?, status = ?, notes = ?, updated_by = ?
          WHERE id = ?'
    );
    $stmt->execute([
        $label, $last4, $expiresOn, $bankId, $holderId,
        $type, $status, $notes !== '' ? $notes : null, (int) $me['id'], $id,
    ]);

    $after = [
        'label' => $label, 'last4' => $last4, 'expires_on' => $expiresOn,
        'bank_id' => $bankId, 'holder_id' => $holderId,
        'type' => $type, 'status' => $status, 'notes' => $notes !== '' ? $notes : null,
    ];

    log_activity('update', 'card', $id, $label . ' ••••' . $last4,
        diff_fields($before, $after, array_keys($after)));

    json_ok(['id' => $id], 'Carte mise à jour.');
}

/**
 * Suppression d'une carte.
 * Refusée dès qu'un service la référence, quel que soit son statut :
 * la clé étrangère est en RESTRICT et l'historique doit rester lisible.
 */
function action_carte_delete()
{
    global $sql;

    $id = post_int('id') ?? 0;

    $stmt = $sql->prepare('SELECT id, label, last4 FROM fi_cards WHERE id = ?');
    $stmt->execute([$id]);
    $carte = $stmt->fetch();

    if ($carte === false) {
        json_error('Cette carte n\'existe plus.', null, 404);
    }

    $stmt = $sql->prepare('SELECT COUNT(*) FROM fi_services WHERE card_id = ?');
    $stmt->execute([$id]);
    $rattaches = (int) $stmt->fetchColumn();

    if ($rattaches > 0) {
        json_error(
            $rattaches . ' service' . ($rattaches > 1 ? 's sont rattachés' : ' est rattaché')
            . ' à cette carte. Réaffectez-' . ($rattaches > 1 ? 'les' : 'le')
            . ' ou marquez la carte comme résiliée plutôt que de la supprimer.',
            null,
            409
        );
    }

    $stmt = $sql->prepare('DELETE FROM fi_cards WHERE id = ?');
    $stmt->execute([$id]);

    log_activity('delete', 'card', $id, $carte['label'] . ' ••••' . $carte['last4']);

    json_ok(['id' => $id], 'Carte supprimée.');
}

// =====================================================================
// Services et abonnements
// =====================================================================

/**
 * Création ou modification d'un service.
 *
 * La réponse contient la ligne de tableau et le bandeau de totaux
 * régénérés par le serveur : le navigateur n'a rien à recalculer, donc
 * l'affichage ne peut pas diverger de la base.
 */
function action_service_save()
{
    global $sql;

    $me = current_user();
    $id = post_int('id') ?? 0;

    $before = null;
    if ($id > 0) {
        $stmt = $sql->prepare('SELECT * FROM fi_services WHERE id = ?');
        $stmt->execute([$id]);
        $before = $stmt->fetch();

        if ($before === false) {
            json_error('Ce service n\'existe plus.', null, 404);
        }
    }

    $name    = post_string('name', 150);
    $url     = post_url('url');
    $cycle   = post_enum('billing_cycle', array_keys(billing_cycles()));
    $cardId  = post_int('card_id');
    $ownerId = post_int('owner_id');
    $amount  = post_decimal('amount');
    $renewal = post_date('next_renewal_on');
    $status  = post_enum('status', array_keys(service_statuses()));
    $notes   = post_string('notes', 5000);

    $errors = [];

    if ($name === '') {
        $errors['name'] = 'Le nom du service est obligatoire.';
    }

    if ($url === false) {
        $errors['url'] = 'Adresse invalide. Attendu : https://exemple.fr';
    }

    if ($cycle === null) {
        $errors['billing_cycle'] = "Type d'abonnement invalide.";
    }

    if ($status === null) {
        $errors['status'] = 'Statut invalide.';
    }

    if ($amount === null) {
        $amount = 0.0;
    } elseif ($amount < 0) {
        $errors['amount'] = 'Le montant ne peut pas être négatif.';
    } elseif ($amount > 9999999.99) {
        $errors['amount'] = 'Ce montant dépasse la limite acceptée.';
    }

    if ($renewal === false) {
        $errors['next_renewal_on'] = "Cette date n'existe pas.";
    }

    // Carte : facultative, mais si elle est fournie elle doit exister et
    // être utilisable — une carte résiliée n'est acceptée que si le
    // service y était déjà rattaché.
    if ($cardId !== null && $cardId > 0) {
        $check = $sql->prepare(
            "SELECT id FROM fi_cards WHERE id = ? AND (status = 'active' OR id = ?)"
        );
        $check->execute([$cardId, (int) ($before['card_id'] ?? 0)]);
        if ($check->fetch() === false) {
            $errors['card_id'] = "Cette carte n'est pas disponible.";
        }
    } else {
        $cardId = null;
    }

    if ($ownerId !== null && $ownerId > 0) {
        $check = $sql->prepare('SELECT id FROM fi_users WHERE id = ? AND (is_active = 1 OR id = ?)');
        $check->execute([$ownerId, (int) ($before['owner_id'] ?? 0)]);
        if ($check->fetch() === false) {
            $errors['owner_id'] = "Ce référent n'est pas disponible.";
        }
    } else {
        $ownerId = null;
    }

    if ($errors !== []) {
        json_response(false, null, 'Le formulaire contient des erreurs.', $errors, 422);
    }

    $valeurs = [
        $name,
        $url !== null && $url !== false ? $url : null,
        $cycle,
        $cardId,
        $amount,
        $renewal !== false ? $renewal : null,
        $ownerId,
        $notes !== '' ? $notes : null,
        $status,
    ];

    if ($id === 0) {
        $stmt = $sql->prepare(
            'INSERT INTO fi_services
                (name, url, billing_cycle, card_id, amount, next_renewal_on,
                 owner_id, notes, status, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([...$valeurs, (int) $me['id'], (int) $me['id']]);

        $id = (int) $sql->lastInsertId();

        log_activity('create', 'service', $id, $name, [
            'amount'        => ['from' => null, 'to' => $amount],
            'billing_cycle' => ['from' => null, 'to' => $cycle],
        ]);

        $message = 'Service créé.';
        $code    = 201;
    } else {
        $stmt = $sql->prepare(
            'UPDATE fi_services
                SET name = ?, url = ?, billing_cycle = ?, card_id = ?, amount = ?,
                    next_renewal_on = ?, owner_id = ?, notes = ?, status = ?, updated_by = ?
              WHERE id = ?'
        );
        $stmt->execute([...$valeurs, (int) $me['id'], $id]);

        $after = [
            'name' => $name,
            'url' => $url !== null && $url !== false ? $url : null,
            'billing_cycle' => $cycle,
            'card_id' => $cardId,
            'amount' => $amount,
            'next_renewal_on' => $renewal !== false ? $renewal : null,
            'owner_id' => $ownerId,
            'notes' => $notes !== '' ? $notes : null,
            'status' => $status,
        ];

        log_activity('update', 'service', $id, $name,
            diff_fields($before, $after, array_keys($after)));

        $message = 'Service mis à jour.';
        $code    = 200;
    }

    $service = fetch_service($id);

    json_ok([
        'id'          => $id,
        'row_html'    => $service !== null ? render_service_row($service) : null,
        'totals_html' => render_service_totals(),
    ], $message, $code);
}

/**
 * Suppression d'un service.
 * Rien ne l'empêche techniquement, mais l'interface pousse d'abord vers
 * le statut « résilié », qui conserve la trace de la dépense passée.
 */
function action_service_delete()
{
    global $sql;

    $id = post_int('id') ?? 0;

    $stmt = $sql->prepare('SELECT id, name FROM fi_services WHERE id = ?');
    $stmt->execute([$id]);
    $service = $stmt->fetch();

    if ($service === false) {
        json_error('Ce service n\'existe plus.', null, 404);
    }

    $stmt = $sql->prepare('DELETE FROM fi_services WHERE id = ?');
    $stmt->execute([$id]);

    log_activity('delete', 'service', $id, (string) $service['name']);

    json_ok([
        'id'          => $id,
        'totals_html' => render_service_totals(),
    ], 'Service supprimé.');
}

// =====================================================================
// Réglages
// =====================================================================

/**
 * Enregistrement des réglages applicatifs.
 * Chaque valeur est bornée : un seuil aberrant rendrait le bloc
 * d'alertes inutilisable, soit vide, soit saturé.
 */
function action_reglages_save()
{
    global $sql;

    require_admin(true);

    $me = current_user();

    $warning = post_int('card_alert_warning_days');
    $info    = post_int('card_alert_info_days');
    $renewal = post_int('service_renewal_days');
    $idle    = post_int('session_idle_minutes');
    $company = post_string('company_name', 120);

    $errors = [];

    if ($warning === null || $warning < 1 || $warning > 365) {
        $errors['card_alert_warning_days'] = 'Indiquez un nombre de jours entre 1 et 365.';
    }
    if ($info === null || $info < 1 || $info > 730) {
        $errors['card_alert_info_days'] = 'Indiquez un nombre de jours entre 1 et 730.';
    }
    if ($errors === [] && $info <= $warning) {
        $errors['card_alert_info_days'] =
            "Le seuil de surveillance doit être supérieur au seuil d'avertissement ({$warning} jours).";
    }
    if ($renewal === null || $renewal < 1 || $renewal > 365) {
        $errors['service_renewal_days'] = 'Indiquez un nombre de jours entre 1 et 365.';
    }
    if ($idle === null || $idle < 5 || $idle > 1440) {
        $errors['session_idle_minutes'] = 'Indiquez une durée entre 5 et 1440 minutes.';
    }
    if ($company === '') {
        $errors['company_name'] = 'Le nom de la structure est obligatoire.';
    }

    if ($errors !== []) {
        json_response(false, null, 'Le formulaire contient des erreurs.', $errors, 422);
    }

    $nouvelles = [
        'card_alert_warning_days' => (string) $warning,
        'card_alert_info_days'    => (string) $info,
        'service_renewal_days'    => (string) $renewal,
        'session_idle_minutes'    => (string) $idle,
        'company_name'            => $company,
    ];

    // État avant modification, pour ne journaliser que ce qui change.
    $avant = [];
    foreach ($sql->query('SELECT setting_key, setting_value FROM fi_settings') as $row) {
        $avant[$row['setting_key']] = $row['setting_value'];
    }

    $stmt = $sql->prepare(
        'INSERT INTO fi_settings (setting_key, setting_value, updated_by)
              VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                 updated_by    = VALUES(updated_by)'
    );

    foreach ($nouvelles as $key => $value) {
        $stmt->execute([$key, $value, (int) $me['id']]);
    }

    $changes = diff_fields($avant, $nouvelles, array_keys($nouvelles));

    if ($changes !== []) {
        log_activity('update', 'setting', null, 'Réglages applicatifs', $changes);
    }

    json_ok($nouvelles, 'Réglages enregistrés.');
}

// =====================================================================
// Comptes bancaires
// =====================================================================

/**
 * Création ou modification d'un compte bancaire.
 * Le couple (nom, société) doit rester unique : une même banque peut
 * servir plusieurs sociétés, mais pas deux fois la même.
 */
function action_banque_save(): void
{
    global $sql;

    $me = current_user();
    $id = post_int('id') ?? 0;

    $before = null;
    if ($id > 0) {
        $stmt = $sql->prepare('SELECT * FROM fi_banks WHERE id = ?');
        $stmt->execute([$id]);
        $before = $stmt->fetch();

        if ($before === false) {
            json_error('Ce compte bancaire n\'existe plus.', null, 404);
        }
    }

    $name    = post_string('name', 120);
    $company = post_enum('company', array_keys(companies()));
    $notes   = post_string('notes', 2000);

    $errors = [];

    if ($name === '') {
        $errors['name'] = 'Le nom de la banque est obligatoire.';
    }

    if ($company === null) {
        $errors['company'] = 'Choisissez la société titulaire du compte.';
    }

    if ($errors === []) {
        $check = $sql->prepare('SELECT id FROM fi_banks WHERE name = ? AND company = ? AND id <> ?');
        $check->execute([$name, $company, $id]);

        if ($check->fetch() !== false) {
            $errors['name'] = 'Ce compte existe déjà pour ' . company_label($company) . '.';
        }
    }

    if ($errors !== []) {
        json_response(false, null, 'Le formulaire contient des erreurs.', $errors, 422);
    }

    if ($id === 0) {
        $stmt = $sql->prepare(
            'INSERT INTO fi_banks (name, company, notes, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name, $company, $notes !== '' ? $notes : null,
            (int) $me['id'], (int) $me['id'],
        ]);

        $id = (int) $sql->lastInsertId();

        log_activity('create', 'bank', $id, $name . ' — ' . company_label($company), [
            'company' => ['from' => null, 'to' => $company],
        ]);

        json_ok(['id' => $id], 'Compte bancaire créé.', 201);
    }

    $stmt = $sql->prepare(
        'UPDATE fi_banks SET name = ?, company = ?, notes = ?, updated_by = ? WHERE id = ?'
    );
    $stmt->execute([$name, $company, $notes !== '' ? $notes : null, (int) $me['id'], $id]);

    $after = ['name' => $name, 'company' => $company, 'notes' => $notes !== '' ? $notes : null];

    log_activity('update', 'bank', $id, $name . ' — ' . company_label($company),
        diff_fields($before, $after, array_keys($after)));

    json_ok(['id' => $id], 'Compte bancaire mis à jour.');
}

/**
 * Suppression d'un compte bancaire.
 * Refusée dès qu'une carte en dépend : la clé étrangère est en RESTRICT,
 * et il n'y a aucun sens à orpheliner des cartes.
 */
function action_banque_delete(): void
{
    global $sql;

    $id = post_int('id') ?? 0;

    $stmt = $sql->prepare('SELECT id, name, company FROM fi_banks WHERE id = ?');
    $stmt->execute([$id]);
    $banque = $stmt->fetch();

    if ($banque === false) {
        json_error('Ce compte bancaire n\'existe plus.', null, 404);
    }

    $stmt = $sql->prepare('SELECT COUNT(*) FROM fi_cards WHERE bank_id = ?');
    $stmt->execute([$id]);
    $cartes = (int) $stmt->fetchColumn();

    if ($cartes > 0) {
        json_error(
            $cartes . ' carte' . ($cartes > 1 ? 's dépendent' : ' dépend') . ' de ce compte. '
            . 'Rattachez-' . ($cartes > 1 ? 'les' : 'la') . ' à un autre compte avant de le supprimer.',
            null,
            409
        );
    }

    $stmt = $sql->prepare('DELETE FROM fi_banks WHERE id = ?');
    $stmt->execute([$id]);

    log_activity('delete', 'bank', $id,
        $banque['name'] . ' — ' . company_label((string) $banque['company']));

    json_ok(['id' => $id], 'Compte bancaire supprimé.');
}
