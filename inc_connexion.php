<?php
/**
 * ---------------------------------------------------------------------
 * Amorçage de l'application
 * ---------------------------------------------------------------------
 * Unique fichier à inclure en tête de chaque page ou traitement :
 *
 *     require_once __DIR__ . '/inc_connexion.php';
 *     require_login();
 *
 * Il charge la configuration, ouvre la base, définit les fonctions
 * communes, envoie les en-têtes de sécurité, démarre une session
 * durcie et charge l'utilisateur courant.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Aucun include ne doit être atteignable directement en HTTP.
// On refuse aussi bien l'appel de ce fichier que celui de n'importe quel
// inc_*.php, y compris ceux qui incluent eux-mêmes cet amorçage.
// C'est la deuxième barrière : la première est la configuration serveur.
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    $entryScript = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

    if (str_starts_with($entryScript, 'inc_')
        || realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
        http_response_code(404);
        exit;
    }

    // Tampon de sortie : une notice ou un avertissement imprévu ne doit
    // jamais partir avant les en-têtes, sous peine de rendre impossibles
    // la session et toute redirection.
    ob_start();
}

define('DEUS_DAF', true);
define('DEUS_DAF_ROOT', __DIR__);

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------
$configFile = DEUS_DAF_ROOT . '/inc_config.php';

if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuration absente : copiez inc_config.sample.php en inc_config.php.');
}

/** @var array<string,mixed> $config */
$config = require $configFile;
$GLOBALS['config'] = $config;

$isProd = ($config['env'] ?? 'production') === 'production';

// ---------------------------------------------------------------------
// Erreurs : jamais affichées en production, toujours journalisées.
// ---------------------------------------------------------------------
$logDir = DEUS_DAF_ROOT . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0770, true);
}

error_reporting(E_ALL);
ini_set('display_errors', $isProd ? '0' : '1');
ini_set('display_startup_errors', $isProd ? '0' : '1');
ini_set('log_errors', '1');
ini_set('error_log', $logDir . '/php-error.log');

date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Paris');

// ---------------------------------------------------------------------
// Gestion des erreurs fatales
// ---------------------------------------------------------------------
// Sans ces gestionnaires, une erreur fatale en production produit une
// page parfaitement blanche : ni message, ni indice, ni moyen de relier
// ce qu'a vu l'utilisateur à une ligne du journal. On produit donc
// toujours quelque chose de lisible, avec une référence permettant de
// retrouver la trace complète dans storage/logs/php-error.log.

/**
 * Affiche une erreur fatale, en JSON ou en HTML selon ce qu'attend
 * l'appelant. Volontairement sans aucune dépendance : cette fonction
 * doit fonctionner même si le reste de l'application n'a pas pu être
 * chargé.
 */
function daf_fatal(string $technique): void
{
    $isProd = (($GLOBALS['config']['env'] ?? 'production') === 'production');

    // Référence courte, journalisée puis affichée : elle relie l'écran
    // de l'utilisateur à la ligne exacte du journal.
    $reference = strtoupper(bin2hex(random_bytes(3)));

    error_log('[DEUS-DAF][' . $reference . '] ' . $technique);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    // Une requête AJAX attend du JSON : lui renvoyer du HTML produirait
    // un « réponse inattendue du serveur » sans aucune information.
    $attendJson = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    $message = $isProd
        ? "Une erreur technique est survenue (référence {$reference})."
        : $technique;

    if ($attendJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success'   => false,
            'data'      => null,
            'message'   => $message,
            'errors'    => null,
            'reference' => $reference,
        ], JSON_UNESCAPED_UNICODE);

        return;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
       . '<title>Erreur technique</title></head><body>'
       . '<h1>Erreur technique</h1><p>'
       . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
       . '</p>';

    if ($isProd) {
        echo '<p>Le détail a été enregistré dans le journal du serveur sous la '
           . 'référence <strong>' . $reference . '</strong>.</p>';
    }

    echo '</body></html>';
}

// Exceptions non rattrapées.
set_exception_handler(static function (Throwable $e): void {
    daf_fatal(
        get_class($e) . ' : ' . $e->getMessage()
        . ' — ' . $e->getFile() . ':' . $e->getLine()
    );
});

// Erreurs fatales et erreurs d'analyse survenues dans un fichier inclus.
register_shutdown_function(static function (): void {
    $erreur = error_get_last();

    if ($erreur === null) {
        return;
    }
    if (!in_array($erreur['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    daf_fatal($erreur['message'] . ' — ' . $erreur['file'] . ':' . $erreur['line']);
});

// ---------------------------------------------------------------------
// Base de données et fonctions communes
// ---------------------------------------------------------------------
require_once DEUS_DAF_ROOT . '/inc_bdd.php';        // définit $sql
require_once DEUS_DAF_ROOT . '/inc_fonctions.php';
require_once DEUS_DAF_ROOT . '/inc_securite.php';
require_once DEUS_DAF_ROOT . '/inc_metier.php';
require_once DEUS_DAF_ROOT . '/inc_render.php';

$GLOBALS['sql'] = $sql;

// ---------------------------------------------------------------------
// En-têtes de sécurité (avant toute sortie)
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    send_security_headers();
}

// ---------------------------------------------------------------------
// Session durcie
// ---------------------------------------------------------------------
$GLOBALS['current_user'] = null;
$GLOBALS['session_expired'] = false;

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $sessionCfg = $config['session'];

    // -----------------------------------------------------------------
    // Emplacement de stockage des sessions
    // -----------------------------------------------------------------
    // On n'impose un dossier QUE si l'hébergeur utilise le gestionnaire
    // « files ». Beaucoup de serveurs mutualisés stockent les sessions
    // dans memcached ou redis : pour ces gestionnaires, session.save_path
    // attend une adresse de serveur (« localhost:11211 »), pas un
    // répertoire. Y écrire un chemin de fichier empêche purement et
    // simplement la création de toute session.
    $savePath = $sessionCfg['save_path'] ?? '';
    $handler  = strtolower((string) ini_get('session.save_handler'));

    if ($savePath !== '' && $handler === 'files') {
        if (!is_dir($savePath)) {
            @mkdir($savePath, 0770, true);
        }
        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }
    }

    // Une session dont l'identifiant n'existe pas côté serveur est
    // refusée au lieu d'être adoptée : c'est la parade à la fixation.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) (((int) $sessionCfg['idle_minutes']) * 60));
    // session.sid_length et session.sid_bits_per_character ne sont pas
    // touchés : ils sont dépréciés depuis PHP 8.4 et leurs valeurs par
    // défaut (32 caractères sur 4 bits) donnent déjà 128 bits d'entropie.

    session_name((string) $sessionCfg['name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (bool) $sessionCfg['secure'],
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();

    // -----------------------------------------------------------------
    // Expiration : inactivité et durée de vie absolue
    // -----------------------------------------------------------------
    if (!empty($_SESSION['user_id'])) {
        $now      = time();
        $idleMax  = ((int) setting_int('session_idle_minutes', (int) $sessionCfg['idle_minutes'])) * 60;
        $absMax   = ((int) $sessionCfg['absolute_minutes']) * 60;
        $idleFor  = $now - (int) ($_SESSION['last_activity'] ?? 0);
        $liveFor  = $now - (int) ($_SESSION['login_time'] ?? 0);

        if ($idleFor > $idleMax || ($absMax > 0 && $liveFor > $absMax)) {
            logout_user(false);
            session_start();
            $GLOBALS['session_expired'] = true;
        } else {
            $_SESSION['last_activity'] = $now;
        }
    }

    // -----------------------------------------------------------------
    // Chargement de l'utilisateur courant, rechargé à chaque requête
    // pour qu'une désactivation prenne effet immédiatement.
    // -----------------------------------------------------------------
    if (!empty($_SESSION['user_id'])) {
        $stmt = $sql->prepare(
            'SELECT id, first_name, last_name, email, role, staff_type,
                    is_active, theme, last_login_at
               FROM fi_users
              WHERE id = ? AND is_active = 1'
        );
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch();

        if ($user === false) {
            logout_user(false);
            session_start();
        } else {
            $GLOBALS['current_user'] = $user;
        }
    }
}
