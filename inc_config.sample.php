<?php
/**
 * ---------------------------------------------------------------------
 * Configuration de l'application — MODÈLE
 * ---------------------------------------------------------------------
 * Copier ce fichier en « inc_config.php » puis renseigner les valeurs.
 * Le fichier inc_config.php réel est exclu du dépôt par .gitignore et
 * ne doit jamais être versionné.
 *
 *   cp inc_config.sample.php inc_config.php
 *   chmod 640 inc_config.php
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

// Ce fichier n'est jamais appelé directement : il est inclus par
// inc_connexion.php, qui définit la constante DEUS_DAF.
if (!defined('DEUS_DAF')) {
    http_response_code(404);
    exit;
}

return [

    // -----------------------------------------------------------------
    // Environnement
    // -----------------------------------------------------------------
    // 'production'  : aucune erreur technique affichée, tout est journalisé
    //                 dans storage/logs/.
    // 'development' : erreurs affichées à l'écran et détaillées en JSON.
    'env' => 'production',

    // -----------------------------------------------------------------
    // Base de données (MySQL 8)
    // -----------------------------------------------------------------
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'deus_daf',
        'user'     => 'deus_daf',
        'password' => 'CHANGE_ME',
    ],

    // -----------------------------------------------------------------
    // Application
    // -----------------------------------------------------------------
    'app' => [
        // Nom affiché dans le menu et le titre du navigateur.
        'name' => 'Suivi CB',

        // Nom de la structure, affiché dans le pied de page.
        'company' => 'Deus',

        // URL publique, SANS slash final. Sert aux liens des emails
        // d'alerte envoyés par le script cron.
        'base_url' => 'https://daf.exemple.fr',

        // Fuseau horaire de l'application. PHP et MySQL sont alignés
        // dessus à chaque requête : les dates en base sont donc en heure
        // locale, sans conversion à l'affichage.
        'timezone' => 'Europe/Paris',
    ],

    // -----------------------------------------------------------------
    // Session
    // -----------------------------------------------------------------
    'session' => [
        // Nom du cookie de session.
        'name' => 'DEUSDAF',

        // Déconnexion automatique après ce délai d'inactivité (minutes).
        'idle_minutes' => 120,

        // Durée de vie absolue d'une session (minutes), quelle que soit
        // l'activité. 0 pour désactiver.
        'absolute_minutes' => 720,

        // Cookie Secure : exige HTTPS. À laisser à true en production.
        // Passer à false UNIQUEMENT pour un développement en HTTP local.
        'secure' => true,

        // Dossier des fichiers de session, isolé du /tmp partagé du
        // serveur. Doit être accessible en écriture par PHP.
        //
        // Ce réglage n'est appliqué QUE si l'hébergeur utilise le
        // gestionnaire de sessions « files ». Beaucoup de serveurs
        // mutualisés stockent les sessions dans memcached ou redis : ces
        // gestionnaires attendent une adresse de serveur dans
        // session.save_path, pas un répertoire, et y écrire un chemin de
        // dossier empêche toute création de session. L'application
        // détecte le cas et laisse alors la configuration de l'hébergeur
        // intacte.
        'save_path' => __DIR__ . '/storage/sessions',
    ],

    // -----------------------------------------------------------------
    // Sécurité
    // -----------------------------------------------------------------
    'security' => [
        // Limitation des tentatives de connexion.
        //
        // max_attempts    : échecs tolérés pour UN MÊME COMPTE dans la
        //                   fenêtre. Seuil bas : il protège d'une
        //                   attaque ciblée sur une adresse connue.
        // max_attempts_ip : échecs tolérés pour UNE MÊME IP. Seuil
        //                   volontairement haut, car tous vos postes
        //                   sortent derrière la même IP publique : un
        //                   seuil bas verrouillerait toute l'équipe dès
        //                   qu'une personne se trompe plusieurs fois.
        //                   Il ne sert qu'à stopper un balayage
        //                   automatisé de nombreux comptes.
        // Passé l'un de ces seuils, la connexion est refusée pendant
        // lockout_minutes.
        'login' => [
            'max_attempts'    => 8,
            'max_attempts_ip' => 40,
            'window_minutes'  => 15,
            'lockout_minutes' => 15,
        ],

        // Longueur minimale imposée aux mots de passe.
        'password_min_length' => 12,
    ],

    // -----------------------------------------------------------------
    // Notifications par email — script bin/notify_expiring_cards.php
    // -----------------------------------------------------------------
    // Désactivé par défaut. Voir le README pour la ligne de crontab.
    'mail' => [
        'enabled'   => false,
        'from'      => 'daf@exemple.fr',
        'from_name' => 'Suivi CB',
        // Destinataires en copie des alertes, en plus du titulaire de
        // la carte concernée.
        'admin_recipients' => [],
    ],
];
