<?php
/**
 * ---------------------------------------------------------------------
 * Fonctions utilitaires communes
 * ---------------------------------------------------------------------
 * Échappement, formatage, réponses JSON, réglages, journal d'activité.
 * Les fonctions métier (cartes, services, alertes) vivent dans
 * inc_metier.php.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

if (!defined('DEUS_DAF')) {
    http_response_code(404);
    exit;
}

// =====================================================================
// Sortie HTML
// =====================================================================

/**
 * Échappement systématique de toute valeur affichée dans du HTML.
 * À utiliser sans exception : <?= h($valeur) ?>
 */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Échappement d'une valeur destinée à être injectée dans du JavaScript
 * inline (dans un bloc <script nonce="...">).
 */
function js(mixed $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?: 'null';
}

// =====================================================================
// Réponses JSON
// =====================================================================

/**
 * Réponse JSON homogène pour toute l'API interne.
 * Format invariable : {success, data, message, errors}.
 *
 * @param array<string,string>|null $errors Erreurs par champ : ['email' => 'message']
 */
function json_response(
    bool $success,
    mixed $data = null,
    ?string $message = null,
    ?array $errors = null,
    int $status = 200
): never {
    // Toute sortie parasite antérieure (notice PHP, espace en trop dans
    // un include) rendrait le JSON illisible par le client : on vide le
    // tampon avant d'écrire, en la journalisant pour ne pas la perdre.
    while (ob_get_level() > 0) {
        $stray = (string) ob_get_clean();
        if (trim($stray) !== '') {
            error_log('[DEUS-DAF] Sortie parasite avant une réponse JSON : ' . trim($stray));
        }
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode([
        'success' => $success,
        'data'    => $data,
        'message' => $message,
        'errors'  => $errors,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

/** Raccourci : réponse de succès. */
function json_ok(mixed $data = null, ?string $message = null, int $status = 200): never
{
    json_response(true, $data, $message, null, $status);
}

/** Raccourci : réponse d'échec. */
function json_error(string $message, ?array $errors = null, int $status = 400): never
{
    json_response(false, null, $message, $errors, $status);
}

// =====================================================================
// Réglages (table fi_settings)
// =====================================================================

/**
 * Lit un réglage applicatif. Tous les réglages sont chargés une seule
 * fois par requête puis conservés en mémoire.
 */
function setting(string $key, string|int|null $default = null): string|int|null
{
    static $cache = null;

    if ($cache === null) {
        global $sql;
        $cache = [];
        foreach ($sql->query('SELECT setting_key, setting_value FROM fi_settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }

    return $cache[$key] ?? $default;
}

/** Lit un réglage numérique. */
function setting_int(string $key, int $default): int
{
    $value = setting($key);

    return ($value === null || $value === '') ? $default : (int) $value;
}

// =====================================================================
// Formatage
// =====================================================================

/** Formate un montant en euros : 1 234,50 € */
function money(float|string|null $amount): string
{
    return number_format((float) ($amount ?? 0), 2, ',', ' ') . ' €';
}

/** Formate une date SQL (Y-m-d ou Y-m-d H:i:s) en jj/mm/aaaa. */
function date_fr(?string $sqlDate): string
{
    if ($sqlDate === null || $sqlDate === '' || str_starts_with($sqlDate, '0000')) {
        return '—';
    }
    $ts = strtotime($sqlDate);

    return $ts === false ? '—' : date('d/m/Y', $ts);
}

/** Formate une date SQL en jj/mm/aaaa à hh:mm. */
function datetime_fr(?string $sqlDate): string
{
    if ($sqlDate === null || $sqlDate === '' || str_starts_with($sqlDate, '0000')) {
        return '—';
    }
    $ts = strtotime($sqlDate);

    return $ts === false ? '—' : date('d/m/Y \à H:i', $ts);
}

/** Formate une date d'expiration de carte au format MM/AA. */
function expiry_fr(?string $sqlDate): string
{
    if ($sqlDate === null || $sqlDate === '') {
        return '—';
    }
    $ts = strtotime($sqlDate);

    return $ts === false ? '—' : date('m/y', $ts);
}

/** Nom complet d'un utilisateur à partir d'une ligne de la table fi_users. */
function full_name(?array $user): string
{
    if ($user === null) {
        return '—';
    }

    return trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
}

// =====================================================================
// Divers
// =====================================================================

/** IP du client au format binaire, prête pour une colonne VARBINARY(16). */
function client_ip_binary(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $packed = @inet_pton($ip);

    return $packed === false ? inet_pton('0.0.0.0') : $packed;
}

/** IP du client sous forme lisible. */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Chemin de base de l'installation, sans slash final.
 * Vaut '' quand l'application est à la racine du domaine, et
 * '/sous-dossier' si elle est installée ailleurs.
 */
function base_path(): string
{
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));

    return ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');
}

/**
 * Redirige vers une page de l'application.
 *
 * Toujours en chemin ABSOLU : un « Location: home.php » relatif est
 * résolu par le navigateur contre l'URL demandée, ce qui envoie sur un
 * chemin fantaisiste dès que la requête ne correspond pas à un fichier
 * réel — et peut boucler.
 */
function redirect_to(string $page): never
{
    header('Location: ' . base_path() . '/' . ltrim($page, '/'));
    exit;
}

/** Vrai si la requête courante est une requête AJAX attendant du JSON. */
function is_ajax(): bool
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// =====================================================================
// Lecture et assainissement des entrées
// =====================================================================
//
// Toute donnée entrante passe par l'une de ces fonctions. Le contrôle
// fait en JavaScript n'est qu'un confort d'affichage : il n'a aucune
// valeur ici, la requête pouvant être forgée à la main.
//

/**
 * Chaîne nettoyée : espaces de bord retirés, caractères de contrôle
 * supprimés, longueur bornée. Les sauts de ligne et tabulations sont
 * conservés pour les champs multilignes.
 *
 * ATTENTION : la troncature est SILENCIEUSE. Elle convient aux champs
 * libres, où couper un commentaire trop long vaut mieux qu'une erreur
 * SQL. Elle ne convient PAS aux champs dont la longueur porte du sens
 * (les 4 derniers chiffres d'une carte, un code) : pour ceux-là, lire
 * avec une borne large puis valider explicitement le format.
 */
function post_string(string $key, int $maxLength = 255): string
{
    $value = (string) ($_POST[$key] ?? '');
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

    return mb_substr(trim($value), 0, $maxLength);
}

/** Entier, ou null si le champ est vide ou non numérique. */
function post_int(string $key): ?int
{
    $value = trim((string) ($_POST[$key] ?? ''));

    if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
        return null;
    }

    return (int) $value;
}

/**
 * Montant décimal, ou null si le champ est vide ou illisible.
 * Accepte indifféremment « 29.90 » et « 29,90 », et tolère les espaces
 * de séparation des milliers.
 */
function post_decimal(string $key): ?float
{
    $value = trim((string) ($_POST[$key] ?? ''));
    $value = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $value);
    $value = str_replace(',', '.', $value);

    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    return round((float) $value, 2);
}

/**
 * Valeur appartenant à une liste fermée, ou null.
 *
 * @param string[] $allowed
 */
function post_enum(string $key, array $allowed): ?string
{
    $value = trim((string) ($_POST[$key] ?? ''));

    return in_array($value, $allowed, true) ? $value : null;
}

/**
 * Même contrôle que post_enum(), mais sur une valeur déjà lue ailleurs
 * (un paramètre d'URL, par exemple).
 *
 * @param string[] $allowed
 */
function post_enum_value(string $value, array $allowed): ?string
{
    $value = trim($value);

    return in_array($value, $allowed, true) ? $value : null;
}

/** Case à cocher : vrai pour 1, "1", "on", "true". */
function post_bool(string $key): bool
{
    $value = $_POST[$key] ?? '';

    return in_array((string) $value, ['1', 'on', 'true'], true);
}

/**
 * URL http(s) valide, ou null si le champ est vide.
 * Renvoie false si la valeur est renseignée mais invalide, pour que
 * l'appelant puisse distinguer « non renseigné » de « incorrect ».
 */
function post_url(string $key): string|false|null
{
    $value = post_string($key, 500);

    if ($value === '') {
        return null;
    }

    // On tolère « exemple.fr » saisi sans protocole.
    if (!preg_match('#^https?://#i', $value)) {
        $value = 'https://' . $value;
    }

    if (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://[^/\s]+#i', $value)) {
        return false;
    }

    return $value;
}

/**
 * Date au format Y-m-d, ou null si le champ est vide.
 * Renvoie false si la valeur est renseignée mais n'est pas une date
 * réelle (le 31 février est refusé).
 */
function post_date(string $key): string|false|null
{
    $value = post_string($key, 10);

    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    if ($date === false || $date->format('Y-m-d') !== $value) {
        return false;
    }

    return $value;
}

// =====================================================================
// Journal d'activité
// =====================================================================

/**
 * Enregistre une entrée dans le journal d'activité.
 *
 * @param string     $action     create, update, delete, login, logout, login_failed…
 * @param array|null $changes    Diff des champs : ['champ' => ['from' => x, 'to' => y]]
 */
function log_activity(
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $entityLabel = null,
    ?array $changes = null,
    ?int $userId = null
): void {
    global $sql;

    if ($userId === null) {
        $current = current_user();
        $userId = $current['id'] ?? null;
    }

    try {
        $stmt = $sql->prepare(
            'INSERT INTO fi_activity_log
                (user_id, action, entity_type, entity_id, entity_label, changes, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $entityLabel !== null ? mb_substr($entityLabel, 0, 190) : null,
            $changes !== null && $changes !== [] ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
            client_ip_binary(),
        ]);
    } catch (PDOException $e) {
        // Le journal ne doit jamais faire échouer une action métier.
        error_log('[DEUS-DAF] Journal d\'activité indisponible : ' . $e->getMessage());
    }
}

/**
 * Calcule le diff entre l'état avant et l'état après d'une fiche, en ne
 * retenant que les champs réellement modifiés.
 *
 * @param string[] $fields Champs à comparer
 * @return array<string,array{from:mixed,to:mixed}>
 */
function diff_fields(array $before, array $after, array $fields): array
{
    $changes = [];

    foreach ($fields as $field) {
        $old = $before[$field] ?? null;
        $new = $after[$field] ?? null;

        // Comparaison souple : « 10.00 » et 10.0 ne sont pas un changement.
        if (is_numeric($old) && is_numeric($new)) {
            if (abs((float) $old - (float) $new) < 0.0001) {
                continue;
            }
        } elseif ((string) $old === (string) $new) {
            continue;
        }

        $changes[$field] = ['from' => $old, 'to' => $new];
    }

    return $changes;
}
