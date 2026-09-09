<?php
/**
 * ---------------------------------------------------------------------
 * Fonctions métier : cartes, services, libellés
 * ---------------------------------------------------------------------
 * Règle centrale du projet : le statut « expirée » d'une carte n'est
 * jamais stocké en base. Il est dérivé de la date d'expiration à chaque
 * lecture, ce qui le rend toujours exact sans aucune tâche planifiée.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

if (!defined('DEUS_DAF')) {
    http_response_code(404);
    exit;
}

// =====================================================================
// Cartes bancaires
// =====================================================================

/**
 * Dernier jour du mois d'expiration, au format SQL.
 * Une carte qui expire en 03/2027 reste valable jusqu'au 31/03/2027.
 */
function expiry_date_from_month(int $month, int $year): string
{
    return date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
}

/**
 * Lit une date d'expiration saisie au format MM/AA ou MM/AAAA.
 *
 * Renvoie la date SQL du dernier jour du mois, ou un message d'erreur
 * si la saisie est illisible ou aberrante. La fenêtre acceptée va de
 * 10 ans en arrière à 20 ans en avant : au-delà, c'est une faute de
 * frappe, pas une carte.
 *
 * @return array{0:?string,1:?string} [date SQL, message d'erreur]
 */
function parse_expiry(string $input): array
{
    $input = trim($input);

    if (!preg_match('#^(0?[1-9]|1[0-2])\s*/\s*(\d{2}|\d{4})$#', $input, $m)) {
        return [null, 'Format attendu : MM/AA, par exemple 03/29.'];
    }

    $month = (int) $m[1];
    $year  = (int) $m[2];

    if ($year < 100) {
        $year += 2000;
    }

    $currentYear = (int) date('Y');

    if ($year < $currentYear - 10 || $year > $currentYear + 20) {
        return [null, 'Cette année d\'expiration est incohérente.'];
    }

    return [expiry_date_from_month($month, $year), null];
}

/**
 * Nombre de jours restants avant expiration.
 * Négatif si la carte est déjà expirée, 0 le dernier jour de validité.
 */
function card_days_left(string $expiresOn): int
{
    $today   = new DateTimeImmutable('today');
    $expiry  = new DateTimeImmutable($expiresOn);

    return (int) $today->diff($expiry)->format('%r%a');
}

/**
 * Statut réel d'une carte, calculé et non stocké.
 *
 * - cancelled : résiliée à la main, prime sur tout le reste
 * - expired   : date d'expiration dépassée
 * - expiring  : expire dans moins de « warning » jours
 * - watch     : expire dans moins de « info » jours
 * - active    : rien à signaler
 *
 * @param array<string,mixed> $card Ligne de la table fi_cards
 */
function card_effective_status(array $card): string
{
    if (($card['status'] ?? '') === 'cancelled') {
        return 'cancelled';
    }

    $days    = card_days_left((string) $card['expires_on']);
    $warning = setting_int('card_alert_warning_days', 60);
    $info    = setting_int('card_alert_info_days', 90);

    if ($days < 0) {
        return 'expired';
    }
    if ($days <= $warning) {
        return 'expiring';
    }
    if ($days <= $info) {
        return 'watch';
    }

    return 'active';
}

/**
 * Rendu visuel d'un statut de carte : classe Bootstrap, libellé, icône.
 *
 * @return array{class:string,label:string,icon:string}
 */
function card_status_badge(string $effectiveStatus): array
{
    return match ($effectiveStatus) {
        'cancelled' => ['class' => 'text-bg-secondary', 'label' => 'Résiliée',  'icon' => 'fa-ban'],
        'expired'   => ['class' => 'text-bg-danger',    'label' => 'Expirée',   'icon' => 'fa-circle-exclamation'],
        'expiring'  => ['class' => 'text-bg-warning',   'label' => 'Expire bientôt', 'icon' => 'fa-triangle-exclamation'],
        'watch'     => ['class' => 'text-bg-info',      'label' => 'À surveiller',   'icon' => 'fa-clock'],
        default     => ['class' => 'text-bg-success',   'label' => 'Active',    'icon' => 'fa-circle-check'],
    };
}

/** Libellé français du type de carte. */
function card_type_label(string $type): string
{
    return match ($type) {
        'credit'  => 'Crédit',
        'virtual' => 'Virtuelle',
        'prepaid' => 'Prépayée',
        default   => 'Débit',
    };
}

/** Types de carte disponibles, pour les listes déroulantes. */
function card_types(): array
{
    return [
        'debit'   => 'Débit',
        'credit'  => 'Crédit',
        'virtual' => 'Virtuelle',
        'prepaid' => 'Prépayée',
    ];
}

// =====================================================================
// Services / abonnements
// =====================================================================

/** Coût ramené au mois, quelle que soit la périodicité. */
function monthly_cost(float $amount, string $billingCycle): float
{
    return match ($billingCycle) {
        'monthly' => $amount,
        'yearly'  => $amount / 12,
        default   => 0.0,
    };
}

/** Libellé français du type d'abonnement. */
function billing_cycle_label(string $cycle): string
{
    return match ($cycle) {
        'yearly'    => 'Annuel',
        'on_demand' => 'À la demande',
        default     => 'Mensuel',
    };
}

/** Types d'abonnement disponibles, pour les listes déroulantes. */
function billing_cycles(): array
{
    return [
        'monthly'   => 'Mensuel',
        'yearly'    => 'Annuel',
        'on_demand' => 'À la demande',
    ];
}

/**
 * Rendu visuel d'un statut de service.
 *
 * @return array{class:string,label:string}
 */
function service_status_badge(string $status): array
{
    return match ($status) {
        'suspended' => ['class' => 'text-bg-warning',   'label' => 'Suspendu'],
        'cancelled' => ['class' => 'text-bg-secondary', 'label' => 'Résilié'],
        default     => ['class' => 'text-bg-success',   'label' => 'Actif'],
    };
}

/** Statuts de service disponibles, pour les listes déroulantes. */
function service_statuses(): array
{
    return [
        'active'    => 'Actif',
        'suspended' => 'Suspendu',
        'cancelled' => 'Résilié',
    ];
}

// =====================================================================
// Utilisateurs
// =====================================================================

/** Libellé français de la position dans la structure. */
function staff_type_label(string $type): string
{
    return $type === 'director' ? 'Dirigeant' : 'Collaborateur';
}

/** Libellé français du rôle applicatif. */
function role_label(string $role): string
{
    return $role === 'admin' ? 'Administrateur' : 'Membre';
}

/**
 * Nombre d'administrateurs actifs, en excluant éventuellement un compte.
 *
 * Sert à empêcher la dernière porte de se refermer : rétrograder ou
 * désactiver le dernier administrateur rendrait la gestion des comptes
 * et des réglages définitivement inaccessible depuis l'interface.
 */
function count_active_admins(?int $excludeId = null): int
{
    global $sql;

    $stmt = $sql->prepare(
        "SELECT COUNT(*) FROM fi_users
          WHERE role = 'admin' AND is_active = 1 AND id <> ?"
    );
    $stmt->execute([$excludeId ?? 0]);

    return (int) $stmt->fetchColumn();
}

// =====================================================================
// Lectures : services
// =====================================================================

/**
 * Le SELECT commun à toutes les lectures de services : la fiche, plus
 * ce qu'il faut de la carte et du référent pour l'afficher sans requête
 * supplémentaire.
 */
function service_select_sql(): string
{
    return "SELECT s.*,
                   c.label      AS card_label,
                   c.last4      AS card_last4,
                   c.status     AS card_status,
                   c.expires_on AS card_expires_on,
                   u.first_name AS owner_first_name,
                   u.last_name  AS owner_last_name
              FROM fi_services s
              LEFT JOIN fi_cards c ON c.id = s.card_id
              LEFT JOIN fi_users u ON u.id = s.owner_id";
}

/**
 * Tous les services, triés par nom.
 *
 * @return array<int,array<string,mixed>>
 */
function fetch_services(): array
{
    global $sql;

    return $sql->query(service_select_sql() . ' ORDER BY s.name')->fetchAll();
}

/**
 * Un service et son contexte, ou null.
 *
 * @return array<string,mixed>|null
 */
function fetch_service(int $id): ?array
{
    global $sql;

    $stmt = $sql->prepare(service_select_sql() . ' WHERE s.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * Totaux du parc de services actifs.
 *
 * @return array{monthly:float,active:int,total:int,on_demand:int,no_card:int}
 */
function service_totals(): array
{
    global $sql;

    $row = $sql->query(
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'active' THEN monthly_cost END), 0) AS monthly,
            COUNT(CASE WHEN status = 'active' THEN 1 END)                       AS active,
            COUNT(*)                                                            AS total,
            COUNT(CASE WHEN status = 'active' AND billing_cycle = 'on_demand'
                       THEN 1 END)                                              AS on_demand,
            COUNT(CASE WHEN status = 'active' AND card_id IS NULL
                        AND amount > 0 THEN 1 END)                              AS no_card
         FROM fi_services"
    )->fetch();

    return [
        'monthly'   => (float) $row['monthly'],
        'active'    => (int) $row['active'],
        'total'     => (int) $row['total'],
        'on_demand' => (int) $row['on_demand'],
        'no_card'   => (int) $row['no_card'],
    ];
}

/**
 * Répartition du coût mensuel par carte, la plus chère en premier.
 * Les services actifs sans carte sont regroupés sur une ligne à part.
 *
 * @return array<int,array<string,mixed>>
 */
function service_breakdown_by_card(): array
{
    global $sql;

    return $sql->query(
        "SELECT s.card_id, c.label, c.last4,
                SUM(s.monthly_cost) AS cout_mensuel
           FROM fi_services s
           LEFT JOIN fi_cards c ON c.id = s.card_id
          WHERE s.status = 'active' AND s.monthly_cost > 0
          GROUP BY s.card_id, c.label, c.last4
          ORDER BY cout_mensuel DESC"
    )->fetchAll();
}

/**
 * Répartition du coût mensuel par référent.
 *
 * @return array<int,array<string,mixed>>
 */
function service_breakdown_by_owner(): array
{
    global $sql;

    return $sql->query(
        "SELECT s.owner_id, u.first_name, u.last_name,
                COUNT(*)            AS nb_services,
                SUM(s.monthly_cost) AS cout_mensuel
           FROM fi_services s
           LEFT JOIN fi_users u ON u.id = s.owner_id
          WHERE s.status = 'active'
          GROUP BY s.owner_id, u.first_name, u.last_name
          ORDER BY cout_mensuel DESC"
    )->fetchAll();
}

// =====================================================================
// Alertes
// =====================================================================

/**
 * Alertes d'expiration de carte.
 *
 * Une seule requête ramène les cartes dont l'échéance entre dans la
 * fenêtre de surveillance, jointes à leurs services actifs. Le
 * regroupement par carte se fait ensuite en PHP : c'est plus lisible
 * qu'un GROUP_CONCAT à découper, et le volume reste dérisoire.
 *
 * Niveaux de gravité :
 *   critical — carte expirée portant au moins un service actif : des
 *              paiements vont échouer, c'est le cas qui justifie l'outil
 *   warning  — expire dans moins de « card_alert_warning_days » jours,
 *              ou déjà expirée mais sans service rattaché
 *   info     — expire dans moins de « card_alert_info_days » jours
 *
 * @return array<int,array<string,mixed>>
 */
function fetch_card_alerts(): array
{
    global $sql;

    $warningDays = setting_int('card_alert_warning_days', 60);
    $infoDays    = setting_int('card_alert_info_days', 90);

    $stmt = $sql->prepare(
        "SELECT c.id, c.label, c.last4, c.expires_on,
                DATEDIFF(c.expires_on, CURDATE()) AS days_left,
                u.first_name, u.last_name,
                s.id            AS service_id,
                s.name          AS service_name,
                s.billing_cycle AS service_cycle,
                s.amount        AS service_amount,
                s.monthly_cost  AS service_monthly
           FROM fi_cards c
           JOIN fi_users u ON u.id = c.holder_id
           LEFT JOIN fi_services s
                  ON s.card_id = c.id AND s.status = 'active'
          WHERE c.status = 'active'
            AND c.expires_on <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
          ORDER BY c.expires_on ASC, s.name ASC"
    );
    $stmt->execute([$infoDays]);

    $alertes = [];

    foreach ($stmt as $row) {
        $cardId = (int) $row['id'];

        if (!isset($alertes[$cardId])) {
            $alertes[$cardId] = [
                'id'              => $cardId,
                'label'           => $row['label'],
                'last4'           => $row['last4'],
                'expires_on'      => $row['expires_on'],
                'days_left'       => (int) $row['days_left'],
                'holder'          => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
                'services'        => [],
                'monthly_at_risk' => 0.0,
                'level'           => 'info',
            ];
        }

        if ($row['service_id'] !== null) {
            $alertes[$cardId]['services'][] = [
                'id'      => (int) $row['service_id'],
                'name'    => $row['service_name'],
                'cycle'   => $row['service_cycle'],
                'amount'  => (float) $row['service_amount'],
                'monthly' => (float) $row['service_monthly'],
            ];
            $alertes[$cardId]['monthly_at_risk'] += (float) $row['service_monthly'];
        }
    }

    foreach ($alertes as &$alerte) {
        $expiree   = $alerte['days_left'] < 0;
        $aServices = $alerte['services'] !== [];

        if ($expiree && $aServices) {
            $alerte['level'] = 'critical';
        } elseif ($expiree || $alerte['days_left'] <= $warningDays) {
            $alerte['level'] = 'warning';
        } else {
            $alerte['level'] = 'info';
        }
    }
    unset($alerte);

    // Le plus grave d'abord, puis l'échéance la plus proche.
    $poids = ['critical' => 0, 'warning' => 1, 'info' => 2];
    uasort($alertes, static function (array $a, array $b) use ($poids): int {
        return [$poids[$a['level']], $a['days_left']] <=> [$poids[$b['level']], $b['days_left']];
    });

    return array_values($alertes);
}

/**
 * Alertes secondaires : anomalies de saisie et pistes d'économie.
 *
 * @return array{no_card:array,no_owner:array,unused_cards:array,renewals:array}
 */
function fetch_other_alerts(): array
{
    global $sql;

    // Services actifs et payants qui ne sont rattachés à aucune carte.
    $sansCarte = $sql->query(
        "SELECT id, name, monthly_cost
           FROM fi_services
          WHERE status = 'active' AND card_id IS NULL AND amount > 0
          ORDER BY monthly_cost DESC"
    )->fetchAll();

    // Services actifs sans référent désigné.
    $sansReferent = $sql->query(
        "SELECT id, name
           FROM fi_services
          WHERE status = 'active' AND owner_id IS NULL
          ORDER BY name"
    )->fetchAll();

    // Cartes actives ne portant aucun service actif : candidates à la
    // résiliation, donc à une économie de frais de tenue de compte.
    $cartesInutilisees = $sql->query(
        "SELECT c.id, c.label, c.last4
           FROM fi_cards c
          WHERE c.status = 'active'
            AND NOT EXISTS (
                SELECT 1 FROM fi_services s
                 WHERE s.card_id = c.id AND s.status = 'active'
            )
          ORDER BY c.label"
    )->fetchAll();

    // Échéances de renouvellement proches.
    $stmt = $sql->prepare(
        "SELECT id, name, next_renewal_on, amount, billing_cycle,
                DATEDIFF(next_renewal_on, CURDATE()) AS days_left
           FROM fi_services
          WHERE status = 'active'
            AND next_renewal_on IS NOT NULL
            AND next_renewal_on <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
          ORDER BY next_renewal_on"
    );
    $stmt->execute([setting_int('service_renewal_days', 30)]);
    $echeances = $stmt->fetchAll();

    return [
        'no_card'      => $sansCarte,
        'no_owner'     => $sansReferent,
        'unused_cards' => $cartesInutilisees,
        'renewals'     => $echeances,
    ];
}

/** Vrai si au moins une alerte est à signaler. */
function has_alerts(array $cardAlerts, array $otherAlerts): bool
{
    return $cardAlerts !== []
        || $otherAlerts['no_card'] !== []
        || $otherAlerts['no_owner'] !== []
        || $otherAlerts['unused_cards'] !== []
        || $otherAlerts['renewals'] !== [];
}
