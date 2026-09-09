<?php
/**
 * ---------------------------------------------------------------------
 * bin/purge_login_attempts.php — entretien de la table des tentatives
 * ---------------------------------------------------------------------
 * La table fi_login_attempts ne sert qu'à la limitation de débit, sur
 * une fenêtre de quelques minutes. Au-delà, ses lignes ne servent plus
 * à rien : les échecs de connexion restent tracés dans le journal
 * d'activité, qui est la source d'audit.
 *
 * Crontab suggérée — toutes les nuits à 3 h 30 :
 *     30 3 * * * /usr/bin/php /var/www/deus-daf/bin/purge_login_attempts.php >> /var/log/deus-daf-cron.log 2>&1
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/inc_connexion.php';

// Marge très large par rapport à la fenêtre de limitation (15 minutes
// par défaut), pour conserver de quoi enquêter sur une attaque récente.
const JOURS_DE_CONSERVATION = 7;

$stmt = $sql->prepare(
    'DELETE FROM fi_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
);
$stmt->execute([JOURS_DE_CONSERVATION]);

$supprimees = $stmt->rowCount();
$restantes  = (int) $sql->query('SELECT COUNT(*) FROM fi_login_attempts')->fetchColumn();

echo '[' . date('Y-m-d H:i:s') . "] Purge des tentatives de connexion\n";
echo "  {$supprimees} ligne(s) supprimée(s), {$restantes} conservée(s).\n";
