<?php
/**
 * ---------------------------------------------------------------------
 * bin/diagnostic.php — vérification de l'installation (ligne de commande)
 * ---------------------------------------------------------------------
 *     php bin/diagnostic.php
 *
 * Les contrôles vivent dans inc_diagnostic.php ; ce fichier ne fait que
 * les afficher. Il est volontairement inaccessible depuis le web : si
 * vous n'avez pas d'accès shell, utiliser la version web temporaire,
 * deploy/diagnostic-web.php.sample.
 *
 * Ce script ne teste QUE la couche PHP et la base. Si le serveur web
 * renvoie une 500 alors que tout est au vert ici, la panne est dans la
 * configuration Apache — voir la section « Dépannage » du README.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('DEUS_DAF', true);

$racine = dirname(__DIR__);
require_once $racine . '/inc_diagnostic.php';

$resultat = collect_diagnostic($racine);

echo "\n";
echo "======================================================================\n";
echo "  Deus DAF — diagnostic d'installation\n";
echo '  ' . date('Y-m-d H:i:s') . ' — ' . $racine . "\n";
echo "======================================================================\n";

foreach ($resultat['sections'] as $section) {
    echo "\n" . $section['titre'] . "\n" . str_repeat('-', 72) . "\n";

    foreach ($section['checks'] as $check) {
        $marque = match ($check['etat']) {
            'ok'    => '  [ OK ]   ',
            'warn'  => '  [ ! ]    ',
            default => '  [ÉCHEC]  ',
        };

        echo $marque . str_pad($check['libelle'], 44) . $check['detail'] . "\n";

        foreach ($check['remede'] as $ligne) {
            echo '           ' . $ligne . "\n";
        }
    }
}

echo "\n======================================================================\n";

if ($resultat['echecs'] === 0 && $resultat['alertes'] === 0) {
    echo "  Tout est en ordre.\n";
    echo "  Si le site renvoie malgré tout une erreur 500, la panne est dans la\n";
    echo "  configuration du serveur web et non dans PHP :\n";
    echo "      tail -50 /var/log/apache2/error.log\n";
    echo "      cp deploy/htaccess-restricted.sample .htaccess   (hébergement bridé)\n";
} elseif ($resultat['echecs'] === 0) {
    echo '  ' . $resultat['alertes'] . " avertissement(s), aucun échec bloquant.\n";
} else {
    echo '  ' . $resultat['echecs'] . ' échec(s) et ' . $resultat['alertes']
        . " avertissement(s) — voir le détail ci-dessus.\n";
}

echo "======================================================================\n\n";

exit($resultat['echecs'] === 0 ? 0 : 1);
