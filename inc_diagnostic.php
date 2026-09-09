<?php
/**
 * ---------------------------------------------------------------------
 * Contrôles de diagnostic — logique partagée
 * ---------------------------------------------------------------------
 * Utilisé par bin/diagnostic.php (ligne de commande) et par la version
 * web temporaire (deploy/diagnostic-web.php.sample). Une seule source
 * de vérité : les deux affichages ne peuvent pas diverger.
 *
 * VOLONTAIREMENT AUTONOME : ce fichier ne charge pas inc_connexion.php,
 * puisque c'est peut-être précisément l'amorçage qui échoue. Il ne
 * renvoie jamais le mot de passe de la base.
 *
 * @return array{sections:array,echecs:int,alertes:int}
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

if (!defined('DEUS_DAF')) {
    http_response_code(404);
    exit;
}

function collect_diagnostic(string $racine): array
{
    $sections = [];
    $echecs   = 0;
    $alertes  = 0;

    /** Fabrique une ligne de résultat. */
    $c = static function (string $etat, string $libelle, string $detail = '', array $remede = []) use (&$echecs, &$alertes): array {
        if ($etat === 'fail') {
            $echecs++;
        } elseif ($etat === 'warn') {
            $alertes++;
        }

        return ['etat' => $etat, 'libelle' => $libelle, 'detail' => $detail, 'remede' => $remede];
    };

    // =================================================================
    // 1. Environnement PHP
    // =================================================================
    $checks = [];

    if (PHP_VERSION_ID >= 80200) {
        $checks[] = $c('ok', 'Version de PHP', PHP_VERSION);
    } elseif (PHP_VERSION_ID >= 80000) {
        $checks[] = $c('warn', 'Version de PHP', PHP_VERSION . ' — fonctionne, 8.2 recommandé');
    } else {
        $checks[] = $c('fail', 'Version de PHP', PHP_VERSION . ' — 8.0 minimum, 8.2 recommandé', [
            'Le code utilise match(), str_contains() et les types union, tous en PHP 8.0.',
            "Une version antérieure provoque une erreur d'analyse, donc une 500 sans message.",
        ]);
    }

    foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'session'] as $extension) {
        $checks[] = extension_loaded($extension)
            ? $c('ok', 'Extension ' . $extension, 'chargée')
            : $c('fail', 'Extension ' . $extension, 'ABSENTE');
    }

    $checks[] = defined('PASSWORD_ARGON2ID')
        ? $c('ok', 'Hachage des mots de passe', 'Argon2id disponible')
        : $c('warn', 'Hachage des mots de passe', 'Argon2id absent, bcrypt sera utilisé');

    $checks[] = $c('ok', 'Interface PHP', PHP_SAPI);

    $sections[] = ['titre' => '1. Environnement PHP', 'checks' => $checks];

    // =================================================================
    // 2. Fichiers et permissions
    // =================================================================
    $checks = [];
    $fichierConfig = $racine . '/inc_config.php';

    if (!is_file($fichierConfig)) {
        $checks[] = $c('fail', 'inc_config.php', 'INTROUVABLE', [
            'cp inc_config.sample.php inc_config.php',
        ]);
    } elseif (!is_readable($fichierConfig)) {
        $checks[] = $c('fail', 'inc_config.php', 'présent mais illisible par cet utilisateur', [
            'Vérifier le propriétaire et les droits : chmod 640, groupe = utilisateur du serveur web.',
        ]);
    } else {
        $checks[] = $c('ok', 'inc_config.php', 'présent — droits ' . substr(sprintf('%o', fileperms($fichierConfig)), -4));
    }

    // Le gestionnaire de sessions détermine si storage/sessions sert à
    // quelque chose. Sur un hébergement qui stocke les sessions dans
    // memcached ou redis, ce dossier est simplement inutilisé — et lui
    // imposer un chemin empêcherait toute création de session.
    $handlerSession = strtolower((string) ini_get('session.save_handler'));
    $sessionsFichier = $handlerSession === 'files';

    $checks[] = $sessionsFichier
        ? $c('ok', 'Gestionnaire de sessions', 'files — stockage dans storage/sessions')
        : $c('ok', 'Gestionnaire de sessions', $handlerSession
            . ' — géré par l\'hébergeur, storage/sessions inutilisé');

    $dossiers = $sessionsFichier ? ['storage/logs', 'storage/sessions'] : ['storage/logs'];

    foreach ($dossiers as $dossier) {
        $chemin = $racine . '/' . $dossier;

        if (!is_dir($chemin)) {
            $checks[] = $c('fail', $dossier, 'dossier absent', ['mkdir -p ' . $chemin]);
        } elseif (!is_writable($chemin)) {
            $checks[] = $c('fail', $dossier, 'NON accessible en écriture', [
                'chown -R www-data:www-data ' . $chemin,
                'chmod 770 ' . $chemin,
            ]);
        } else {
            $checks[] = $c('ok', $dossier, 'accessible en écriture');
        }
    }

    // Contrôle décisif : une session peut-elle réellement être créée ?
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
        $ancienRapport = error_reporting(0);
        $demarree = @session_start();
        error_reporting($ancienRapport);

        if ($demarree) {
            $_SESSION['diagnostic'] = 1;
            session_write_close();
            $checks[] = $c('ok', 'Création de session', 'fonctionnelle (' . $handlerSession . ')');
        } else {
            $checks[] = $c('fail', 'Création de session', 'IMPOSSIBLE avec le gestionnaire ' . $handlerSession, [
                "Sans session, aucune connexion n'est possible et l'application semble",
                'boucler entre la page de connexion et le tableau de bord.',
                '',
                "Si le gestionnaire n'est pas « files », vérifiez que session.save_path",
                "n'est pas écrasé par un chemin de dossier : ces gestionnaires y attendent",
                "une adresse de serveur. save_path actuel : « " . (string) ini_get('session.save_path') . " »",
            ]);
        }
    }

    $assetsAttendus = [
        'assets/scss/custom.min.css',
        'assets/scss/main.min.css',
        'assets/scss/daf.css',
        'assets/js/jquery-4.0.0.min.js',
        'assets/js/app.js',
        'assets/js/script.js',
        'assets/js/theme-modes.js',
        'assets/bootstrap-5.3.6/dist/js/bootstrap.bundle.min.js',
        'assets/plugins/DataTables-2.2.1/datatables.min.js',
        'assets/fonts/fontawesome-free-6.7.2-web/css/all.min.css',
        'assets/fonts/noto-sans/noto-sans-v27-latin-regular.woff2',
    ];
    $manquants = array_values(array_filter(
        $assetsAttendus,
        static fn (string $f): bool => !is_file($racine . '/' . $f)
    ));

    $checks[] = $manquants === []
        ? $c('ok', 'Assets front', count($assetsAttendus) . ' fichiers clés présents')
        : $c('fail', 'Assets front', count($manquants) . ' manquant(s)', $manquants);

    // Un .htaccess absent laisserait le schéma SQL et les journaux
    // accessibles depuis le web.
    $checks[] = is_file($racine . '/.htaccess')
        ? $c('ok', '.htaccess racine', 'présent (' . filesize($racine . '/.htaccess') . ' octets)')
        : $c('fail', '.htaccess racine', 'ABSENT — les dossiers sensibles ne sont plus protégés', [
            'Restaurer le fichier, ou : cp deploy/htaccess-restricted.sample .htaccess',
        ]);

    if (is_file($racine . '/.user.ini')) {
        $checks[] = $c('warn', '.user.ini', 'présent — fichier de dépannage à supprimer après usage', [
            'rm .user.ini',
        ]);
    }

    $sections[] = ['titre' => '2. Fichiers et permissions', 'checks' => $checks];

    // =================================================================
    // 3. Configuration
    // =================================================================
    $checks = [];
    $config = null;

    if (is_file($fichierConfig) && is_readable($fichierConfig)) {
        try {
            $config = require $fichierConfig;
        } catch (Throwable $e) {
            $checks[] = $c('fail', 'Lecture de inc_config.php', $e->getMessage());
        }

        if (!is_array($config)) {
            $checks[] = $c('fail', 'Contenu de inc_config.php', 'le fichier ne renvoie pas de tableau');
            $config = null;
        }
    }

    if ($config !== null) {
        foreach (['env', 'db', 'app', 'session', 'security'] as $cle) {
            $checks[] = isset($config[$cle])
                ? $c('ok', 'Clé « ' . $cle . ' »', 'présente')
                : $c('fail', 'Clé « ' . $cle . ' »', 'MANQUANTE');
        }

        $env = (string) ($config['env'] ?? '');
        $checks[] = $env === 'production'
            ? $c('ok', 'Mode', 'production')
            : $c('warn', 'Mode', $env . " — les erreurs techniques sont affichées à l'écran");

        $checks[] = ($config['session']['secure'] ?? true)
            ? $c('ok', 'Cookie de session', 'Secure activé (HTTPS exigé)')
            : $c('warn', 'Cookie de session', 'Secure DÉSACTIVÉ', [
                "À ne pas laisser en production : le cookie circulerait en clair en HTTP.",
            ]);

        $baseUrl = (string) ($config['app']['base_url'] ?? '');
        if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            $checks[] = $c('warn', 'app.base_url', 'absente ou invalide');
        } elseif (str_ends_with($baseUrl, '/')) {
            $checks[] = $c('warn', 'app.base_url', 'ne doit pas se terminer par un slash');
        } else {
            $checks[] = $c('ok', 'app.base_url', $baseUrl);
        }

        $tz = (string) ($config['app']['timezone'] ?? '');
        $checks[] = in_array($tz, timezone_identifiers_list(), true)
            ? $c('ok', 'Fuseau horaire', $tz)
            : $c('fail', 'Fuseau horaire', '« ' . $tz . ' » inconnu');
    }

    $sections[] = ['titre' => '3. Configuration', 'checks' => $checks];

    // =================================================================
    // 4. Base de données
    // =================================================================
    $checks = [];
    $pdo = null;

    if ($config !== null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['db']['host'] ?? '127.0.0.1',
            (int) ($config['db']['port'] ?? 3306),
            $config['db']['name'] ?? ''
        );

        try {
            $pdo = new PDO($dsn, $config['db']['user'] ?? '', $config['db']['password'] ?? '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);

            $checks[] = $c('ok', 'Connexion', ($config['db']['user'] ?? '?') . '@'
                . ($config['db']['host'] ?? '?') . ' → ' . ($config['db']['name'] ?? '?'));
            $checks[] = $c('ok', 'Version du serveur', (string) $pdo->query('SELECT VERSION()')->fetchColumn());
        } catch (PDOException $e) {
            // Le message de PDO ne contient jamais le mot de passe.
            $checks[] = $c('fail', 'Connexion', $e->getMessage(), [
                'Vérifier host, name, user et password dans inc_config.php,',
                'et que le compte MySQL a bien les droits sur cette base.',
            ]);
        }
    }

    if ($pdo !== null) {
        $attendues = ['fi_users', 'fi_banks', 'fi_cards', 'fi_services',
                      'fi_settings', 'fi_login_attempts', 'fi_activity_log'];
        $presentes = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $absentes  = array_values(array_diff($attendues, $presentes));

        if ($absentes !== []) {
            $checks[] = $c('fail', 'Tables', 'manquantes : ' . implode(', ', $absentes), [
                'mysql -u root -p ' . ($config['db']['name'] ?? '') . ' < db/schema.sql',
                '',
                'Si seule fi_banks manque, il s\'agit d\'une installation antérieure :',
                'appliquer db/migration-2026-09-banques.sql plutôt que de tout réimporter.',
            ]);
        } else {
            $checks[] = $c('ok', 'Tables', '7 tables fi_* présentes');

            foreach (['fi_users', 'fi_banks', 'fi_cards', 'fi_services', 'fi_activity_log'] as $table) {
                $n = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
                $checks[] = $c('ok', 'Contenu de ' . $table, $n . ' ligne(s)');
            }

            $admins = (int) $pdo->query(
                "SELECT COUNT(*) FROM fi_users WHERE role = 'admin' AND is_active = 1"
            )->fetchColumn();

            $checks[] = $admins === 0
                ? $c('fail', 'Administrateurs actifs', 'AUCUN — connexion impossible', ['php bin/install.php'])
                : $c('ok', 'Administrateurs actifs', (string) $admins);

            $reglages = (int) $pdo->query('SELECT COUNT(*) FROM fi_settings')->fetchColumn();
            $checks[] = $reglages >= 5
                ? $c('ok', 'Réglages', $reglages . ' clé(s)')
                : $c('warn', 'Réglages', $reglages . ' clé(s) — le schéma en insère 5 par défaut');

            $genere = $pdo->query(
                "SELECT EXTRA FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'fi_services'
                    AND COLUMN_NAME = 'monthly_cost'"
            )->fetchColumn();

            $checks[] = str_contains(strtoupper((string) $genere), 'GENERATED')
                ? $c('ok', 'Colonne monthly_cost', 'générée par MySQL')
                : $c('fail', 'Colonne monthly_cost', 'non générée — schéma incomplet');

            $demo = (int) $pdo->query(
                "SELECT COUNT(*) FROM fi_users WHERE email LIKE '%@exemple.fr'"
            )->fetchColumn();

            if ($demo > 0 && ($config['env'] ?? '') === 'production') {
                $checks[] = $c('warn', 'Comptes de démonstration', $demo . ' compte(s) @exemple.fr', [
                    'Mot de passe PUBLIC : supprimer ces comptes en production.',
                ]);
            }
        }
    }

    $sections[] = ['titre' => '4. Base de données', 'checks' => $checks];

    // =================================================================
    // 5. Compatibilité du code avec cette version de PHP
    // =================================================================
    //
    // Contrôle le plus important en cas de 500 « blanche ». Une erreur
    // d'analyse se produit AVANT la moindre ligne de code : ni
    // display_errors, ni le gestionnaire d'erreurs de l'application ne
    // peuvent la rapporter. token_get_all() avec TOKEN_PARSE analyse le
    // fichier exactement comme le ferait l'interpréteur.
    $checks = [];

    $fichiers = array_merge(
        glob($racine . '/*.php') ?: [],
        glob($racine . '/bin/*.php') ?: []
    );

    $illisibles = [];

    foreach ($fichiers as $fichier) {
        $code = @file_get_contents($fichier);

        if ($code === false) {
            $illisibles[] = basename($fichier) . ' (illisible)';
            continue;
        }

        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (ParseError $e) {
            $illisibles[] = basename($fichier) . ' — ' . $e->getMessage() . ' (ligne ' . $e->getLine() . ')';
        }
    }

    $checks[] = $illisibles === []
        ? $c('ok', 'Analyse des fichiers PHP', count($fichiers) . ' fichiers, aucune erreur')
        : $c('fail', 'Analyse des fichiers PHP', count($illisibles) . ' fichier(s) en erreur', array_merge(
            $illisibles,
            [
                '',
                "Cette version de PHP ne sait pas lire ce code : c'est la cause d'une 500 sans message.",
                'Le code exige PHP 8.0 au minimum, 8.2 recommandé.',
            ]
        ));

    $sections[] = ['titre' => '5. Compatibilité du code', 'checks' => $checks];

    // =================================================================
    // 6. Écriture réelle
    // =================================================================
    $checks = [];
    $test = $racine . '/storage/logs/.diagnostic-' . bin2hex(random_bytes(4));

    if (@file_put_contents($test, 'test') !== false) {
        @unlink($test);
        $checks[] = $c('ok', 'Écriture dans storage/logs', 'effective');
    } else {
        $checks[] = $c('fail', 'Écriture dans storage/logs', 'impossible');
    }

    $sections[] = ['titre' => '6. Écriture réelle', 'checks' => $checks];

    // =================================================================
    // 7. Protection effective, vue depuis l'extérieur
    // =================================================================
    //
    // Le seul contrôle qui vérifie ce que voit réellement un visiteur :
    // on interroge le site par HTTP, sur ses propres URL. C'est ce qui
    // distingue « le .htaccess est présent » de « le .htaccess est
    // appliqué » — sur un hébergement où AllowOverride est limité, le
    // fichier existe mais ne protège rien.
    $checks = [];
    $baseUrl = rtrim((string) ($config['app']['base_url'] ?? ''), '/');

    if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        $checks[] = $c('warn', 'Contrôle externe', 'impossible : app.base_url non renseignée');
    } else {
        $aTester = [
            'inc_config.php'             => 'mot de passe de la base',
            'db/seed.sql'                => 'comptes de démonstration',
            'db/schema.sql'              => 'structure de la base',
            'storage/logs/php-error.log' => 'journal des erreurs',
            'bin/install.php'            => "script de création d'administrateur",
        ];

        $joignable = false;
        $premier   = true;

        foreach ($aTester as $chemin => $contenu) {
            $code = diagnostic_http_status($baseUrl . '/' . $chemin);

            // Si la toute première requête échoue, le serveur ne peut pas
            // s'appeler lui-même : inutile d'attendre le délai d'expiration
            // sur les suivantes. Cela arrive notamment quand un seul
            // processus PHP est disponible — il est occupé par CE script.
            if ($code === null) {
                if ($premier) {
                    break;
                }
                $premier = false;
                continue;
            }

            $premier   = false;
            $joignable = true;

            if (in_array($code, [403, 404], true)) {
                $checks[] = $c('ok', $chemin, 'inaccessible (HTTP ' . $code . ')');
            } elseif ($code === 200) {
                $checks[] = $c('fail', $chemin, 'ACCESSIBLE PUBLIQUEMENT (HTTP 200) — ' . $contenu, [
                    'Le .htaccess n\'est pas appliqué. Causes possibles :',
                    '  · AllowOverride limité par l\'hébergeur → utiliser deploy/htaccess-restricted.sample',
                    '  · fichier .htaccess absent ou non téléversé (les fichiers cachés sont souvent masqués en FTP)',
                ]);
            } else {
                $checks[] = $c('warn', $chemin, 'réponse inattendue : HTTP ' . $code);
            }
        }

        if (!$joignable) {
            $checks[] = $c('warn', 'Contrôle externe', 'le serveur ne peut pas s\'appeler lui-même', [
                'Ni curl ni allow_url_fopen ne sont disponibles, ou les connexions sortantes sont bloquées.',
                'Vérifiez alors à la main en ouvrant ces URL dans votre navigateur :',
                '  ' . $baseUrl . '/inc_config.php',
                '  ' . $baseUrl . '/db/seed.sql',
                'Elles doivent toutes répondre « Forbidden » ou « Not Found ».',
            ]);
        }
    }

    $sections[] = ['titre' => '7. Protection effective (vue de l\'extérieur)', 'checks' => $checks];

    // =================================================================
    // 8. Contexte de la requête et boucles de redirection
    // =================================================================
    //
    // Section décisive pour diagnostiquer un « too many redirects ».
    // La règle HTTPS du .htaccess redirige quand Apache ne voit pas de
    // requête sécurisée. Si le TLS est terminé par un proxy en amont qui
    // transmet ensuite en clair SANS en-tête X-Forwarded-Proto, Apache
    // croit être en HTTP à chaque fois : il redirige vers HTTPS, le
    // proxy retransmet en clair, et la boucle est sans fin.
    if (PHP_SAPI !== 'cli') {
        $checks = [];

        $vuHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');

        $entetesProxy = [];
        foreach ($_SERVER as $cle => $valeur) {
            if (str_starts_with((string) $cle, 'HTTP_X_FORWARDED')
                || (string) $cle === 'HTTP_X_URL_SCHEME'
                || (string) $cle === 'HTTP_FRONT_END_HTTPS') {
                $entetesProxy[$cle] = (string) $valeur;
            }
        }

        $proxyDitHttps = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTOCOL'] ?? '')) === 'https'
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on'
            || strtolower((string) ($_SERVER['HTTP_X_URL_SCHEME'] ?? '')) === 'https'
            || strtolower((string) ($_SERVER['HTTP_FRONT_END_HTTPS'] ?? '')) === 'on'
            || (string) ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '') === '443';

        $checks[] = $c('ok', 'SCRIPT_NAME', (string) ($_SERVER['SCRIPT_NAME'] ?? '(absent)'));
        $checks[] = $c('ok', 'REQUEST_URI', (string) ($_SERVER['REQUEST_URI'] ?? '(absent)'));
        $checks[] = $c('ok', 'Chemin de base calculé',
            '« ' . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . ' »');
        $checks[] = $c('ok', 'SERVER_PORT', (string) ($_SERVER['SERVER_PORT'] ?? '(absent)'));
        $checks[] = $c('ok', 'Variable HTTPS', $vuHttps ? 'renseignée' : 'absente ou « off »');

        $checks[] = $entetesProxy === []
            ? $c('ok', 'En-têtes de proxy', 'aucun — pas de proxy inverse détecté')
            : $c('ok', 'En-têtes de proxy', implode(' · ', array_map(
                static fn (string $k, string $v): string => substr($k, 5) . '=' . $v,
                array_keys($entetesProxy),
                $entetesProxy
            )));

        // Le verdict : Apache voit-il du HTTP alors que le visiteur est
        // arrivé en HTTPS ?
        $visiteurEnHttps = str_starts_with((string) ($config['app']['base_url'] ?? ''), 'https://');

        if (!$vuHttps && !$proxyDitHttps && $visiteurEnHttps) {
            $checks[] = $c('fail', 'Redirection HTTPS', 'BOUCLE : Apache ne voit pas la requête comme sécurisée', [
                'Le site est en HTTPS mais ni la variable HTTPS ni un en-tête X-Forwarded-Proto',
                "n'atteignent PHP. La règle HTTPS du .htaccess redirige donc en boucle.",
                '',
                'Deux corrections possibles :',
                '  · si un en-tête de proxy figure ci-dessus, il faut l\'ajouter à la règle ;',
                '  · sinon, retirer purement et simplement la redirection HTTPS du .htaccess :',
                '    l\'hébergeur la fait déjà en amont. Utiliser',
                '    deploy/htaccess-sans-redirection.sample',
            ]);
        } elseif ($vuHttps) {
            $checks[] = $c('ok', 'Redirection HTTPS', 'Apache voit bien du HTTPS, aucune boucle possible');
        } elseif ($proxyDitHttps) {
            $checks[] = $c('ok', 'Redirection HTTPS', 'un en-tête de proxy signale le HTTPS, la règle est neutralisée');
        } else {
            $checks[] = $c('warn', 'Redirection HTTPS', 'requête en HTTP simple — normal si vous testez sans TLS');
        }

        $sections[] = ['titre' => '8. Contexte de la requête', 'checks' => $checks];
    }

    return ['sections' => $sections, 'echecs' => $echecs, 'alertes' => $alertes];
}

/**
 * Code HTTP renvoyé par une URL, ou null si la requête est impossible.
 * Essaie curl puis les enveloppes de flux, car l'un ou l'autre est
 * fréquemment désactivé en hébergement mutualisé.
 */
function diagnostic_http_status(string $url): ?int
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);

        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($code > 0) {
                return $code;
            }
        }
    }

    if (!ini_get('allow_url_fopen')) {
        return null;
    }

    $contexte = stream_context_create([
        'http' => ['method' => 'HEAD', 'timeout' => 3, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $entetes = @get_headers($url, false, $contexte);

    if ($entetes === false || $entetes === []) {
        return null;
    }

    return preg_match('#\s(\d{3})\s#', $entetes[0], $m) === 1 ? (int) $m[1] : null;
}
