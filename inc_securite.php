<?php
/**
 * ---------------------------------------------------------------------
 * Sécurité : en-têtes HTTP, CSP, CSRF, authentification, anti-bruteforce
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

if (!defined('DEUS_DAF')) {
    http_response_code(404);
    exit;
}

// =====================================================================
// En-têtes de sécurité et Content-Security-Policy
// =====================================================================

/**
 * Nonce unique à la requête, injecté dans la CSP et dans chaque bloc
 * <script> inline des pages. Sans ce nonce, un script injecté dans la
 * page par une faille XSS ne s'exécutera pas.
 */
function csp_nonce(): string
{
    static $nonce = null;

    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }

    return $nonce;
}

/**
 * Envoie les en-têtes de sécurité. Appelé une seule fois, au tout début
 * de la requête, avant toute sortie.
 */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    // On masque la version de PHP.
    header_remove('X-Powered-By');

    $csp = implode('; ', [
        "default-src 'self'",
        "base-uri 'none'",
        "object-src 'none'",
        "frame-ancestors 'none'",
        "form-action 'self'",
        "img-src 'self' data:",
        "font-src 'self'",
        // 'unsafe-inline' est nécessaire pour les styles : Bootstrap et
        // DataTables injectent des règles inline au fonctionnement.
        // Les SCRIPTS, eux, restent verrouillés par nonce.
        "style-src 'self' 'unsafe-inline'",
        "script-src 'self' 'nonce-" . csp_nonce() . "'",
        "connect-src 'self'",
    ]);

    header('Content-Security-Policy: ' . $csp);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');

    // HSTS uniquement si la requête est bien arrivée en HTTPS, pour ne
    // pas verrouiller un environnement de développement en HTTP.
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Vrai si la requête courante est en HTTPS, proxy inverse compris.
 *
 * Les proxys ne signalent pas tous le HTTPS de la même façon : cette
 * liste couvre les en-têtes rencontrés en pratique. Elle doit rester
 * alignée sur les conditions RewriteCond du .htaccess, faute de quoi
 * l'application et le serveur web se contrediraient.
 */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }

    $entetes = [
        'HTTP_X_FORWARDED_PROTO'    => 'https',
        'HTTP_X_FORWARDED_PROTOCOL' => 'https',
        'HTTP_X_FORWARDED_SSL'      => 'on',
        'HTTP_X_URL_SCHEME'         => 'https',
        'HTTP_FRONT_END_HTTPS'      => 'on',
        'HTTP_X_FORWARDED_PORT'     => '443',
    ];

    foreach ($entetes as $entete => $attendu) {
        if (strtolower((string) ($_SERVER[$entete] ?? '')) === $attendu) {
            return true;
        }
    }

    return false;
}

// =====================================================================
// Protection CSRF
// =====================================================================

/** Token CSRF de la session courante, généré à la demande. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Vérifie le token CSRF envoyé par le client, dans l'en-tête
 * X-CSRF-Token ou, à défaut, dans le champ POST csrf_token.
 */
function csrf_is_valid(): bool
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');

    if (!is_string($sent) || $sent === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $sent);
}

/**
 * Exige un token CSRF valide. Interrompt la requête avec une réponse
 * JSON 403 dans le cas contraire.
 */
function require_csrf(): void
{
    if (!csrf_is_valid()) {
        json_error(
            'Votre session a changé. Rechargez la page puis réessayez.',
            null,
            403
        );
    }
}

// =====================================================================
// Utilisateur courant et contrôle d'accès
// =====================================================================

/**
 * Utilisateur connecté, ou null. La ligne est rechargée depuis la base
 * à chaque requête : un compte désactivé perd donc son accès
 * immédiatement, sans attendre l'expiration de sa session.
 *
 * @return array<string,mixed>|null
 */
function current_user(): ?array
{
    return $GLOBALS['current_user'] ?? null;
}

/** Vrai si l'utilisateur connecté est administrateur. */
function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

/**
 * Exige une session valide.
 *
 * @param bool $json true pour les endpoints AJAX (réponse 401 JSON),
 *                   false pour les pages HTML (redirection vers l'écran
 *                   de connexion).
 */
function require_login(bool $json = false): void
{
    if (current_user() !== null) {
        return;
    }

    if ($json) {
        json_error('Votre session a expiré. Reconnectez-vous.', null, 401);
    }

    redirect_to('index.php?action=expire');
}

/** Exige un compte administrateur. */
function require_admin(bool $json = false): void
{
    require_login($json);

    if (is_admin()) {
        return;
    }

    if ($json) {
        json_error("Vous n'avez pas les droits nécessaires pour cette action.", null, 403);
    }

    redirect_to('home.php?erreur=droits');
}

// =====================================================================
// Mots de passe
// =====================================================================

/** Argon2id si le serveur le propose, bcrypt sinon. */
function password_algorithm(): string
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

/** Hache un mot de passe avec le meilleur algorithme disponible. */
function hash_password(string $plain): string
{
    return password_hash($plain, password_algorithm());
}

/**
 * Vérifie la robustesse d'un mot de passe.
 *
 * @return string|null Message d'erreur, ou null si le mot de passe convient.
 */
function password_problem(string $plain): ?string
{
    global $config;
    $min = (int) ($config['security']['password_min_length'] ?? 12);

    if (mb_strlen($plain) < $min) {
        return "Le mot de passe doit contenir au moins {$min} caractères.";
    }
    if (!preg_match('/[a-zà-ÿ]/u', $plain) || !preg_match('/[A-ZÀ-Ý]/u', $plain)) {
        return 'Le mot de passe doit contenir des minuscules et des majuscules.';
    }
    if (!preg_match('/\d/', $plain)) {
        return 'Le mot de passe doit contenir au moins un chiffre.';
    }

    return null;
}

// =====================================================================
// Limitation des tentatives de connexion
// =====================================================================

/**
 * Compte les échecs récents, séparément par compte et par adresse IP.
 *
 * Les deux compteurs sont volontairement distincts. Dans une petite
 * structure, tous les postes sortent derrière la même IP publique : un
 * seuil commun ferait qu'une personne se trompant huit fois de mot de
 * passe verrouillerait toute l'équipe. Le seuil par compte reste donc
 * bas — c'est lui qui protège d'une attaque ciblée — et celui par IP
 * beaucoup plus haut, réservé au balayage automatisé de comptes.
 *
 * @return array{email:int,ip:int}
 */
function recent_login_failures(string $email): array
{
    global $sql, $config;

    $window = (int) ($config['security']['login']['window_minutes'] ?? 15);

    $stmt = $sql->prepare(
        'SELECT
            SUM(email = ?)      AS by_email,
            SUM(ip_address = ?) AS by_ip
           FROM fi_login_attempts
          WHERE succeeded = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            AND (email = ? OR ip_address = ?)'
    );
    $stmt->execute([$email, client_ip_binary(), $window, $email, client_ip_binary()]);
    $row = $stmt->fetch();

    return [
        'email' => (int) ($row['by_email'] ?? 0),
        'ip'    => (int) ($row['by_ip'] ?? 0),
    ];
}

/**
 * Détermine si la connexion est temporairement bloquée.
 *
 * @return int Nombre de secondes à attendre, 0 si la connexion est permise.
 */
function login_lockout_seconds(string $email): int
{
    global $sql, $config;

    $maxEmail = (int) ($config['security']['login']['max_attempts'] ?? 8);
    $maxIp    = (int) ($config['security']['login']['max_attempts_ip'] ?? 40);
    $lockout  = (int) ($config['security']['login']['lockout_minutes'] ?? 15);
    $window   = (int) ($config['security']['login']['window_minutes'] ?? 15);

    $failures = recent_login_failures($email);

    $lockedByEmail = $failures['email'] >= $maxEmail;
    $lockedByIp    = $failures['ip'] >= $maxIp;

    if (!$lockedByEmail && !$lockedByIp) {
        return 0;
    }

    // On ne mesure le délai que sur les tentatives ayant réellement
    // provoqué le blocage, et à l'intérieur de la fenêtre d'observation.
    $conditions = [];
    $params     = [$lockout, $window];

    if ($lockedByEmail) {
        $conditions[] = 'email = ?';
        $params[]     = $email;
    }
    if ($lockedByIp) {
        $conditions[] = 'ip_address = ?';
        $params[]     = client_ip_binary();
    }

    $stmt = $sql->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(attempted_at), INTERVAL ? MINUTE))
           FROM fi_login_attempts
          WHERE succeeded = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            AND (' . implode(' OR ', $conditions) . ')'
    );
    $stmt->execute($params);

    return max(0, (int) $stmt->fetchColumn());
}

/** Enregistre une tentative de connexion, réussie ou non. */
function record_login_attempt(string $email, bool $succeeded): void
{
    global $sql;

    $stmt = $sql->prepare(
        'INSERT INTO fi_login_attempts (email, ip_address, succeeded) VALUES (?, ?, ?)'
    );
    $stmt->execute([mb_substr($email, 0, 190), client_ip_binary(), $succeeded ? 1 : 0]);
}

/**
 * Temporisation progressive : chaque échec récent ralentit un peu plus
 * la réponse, ce qui rend un balayage automatisé impraticable sans
 * pénaliser un utilisateur qui se trompe une fois.
 */
function throttle_delay(int $failures): void
{
    if ($failures > 0) {
        usleep(min($failures, 6) * 250_000);
    }
}

// =====================================================================
// Ouverture et fermeture de session
// =====================================================================

/**
 * Ouvre la session applicative pour l'utilisateur donné.
 * L'identifiant de session est régénéré pour couper toute fixation.
 */
function login_user(array $user): void
{
    global $sql;

    session_regenerate_id(true);

    $_SESSION['user_id']       = (int) $user['id'];
    $_SESSION['login_time']    = time();
    $_SESSION['last_activity'] = time();
    unset($_SESSION['csrf_token']);
    csrf_token();

    $stmt = $sql->prepare('UPDATE fi_users SET last_login_at = NOW() WHERE id = ?');
    $stmt->execute([(int) $user['id']]);

    $GLOBALS['current_user'] = $user;

    log_activity('login', 'user', (int) $user['id'], $user['email'], null, (int) $user['id']);
}

/** Ferme la session et détruit le cookie. */
function logout_user(bool $journalise = true): void
{
    if ($journalise && current_user() !== null) {
        $u = current_user();
        log_activity('logout', 'user', (int) $u['id'], $u['email'], null, (int) $u['id']);
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Strict',
        ]);
    }

    session_destroy();
    $GLOBALS['current_user'] = null;
}
