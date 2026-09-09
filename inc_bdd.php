<?php
/**
 * ---------------------------------------------------------------------
 * Connexion à la base de données
 * ---------------------------------------------------------------------
 * Expose $sql, une instance PDO configurée en mode exception, sans
 * émulation des requêtes préparées (les types SQL sont donc préservés
 * et les entiers reviennent bien en int côté PHP).
 *
 * Toutes les requêtes de l'application passent par des requêtes
 * préparées : aucune valeur ne doit jamais être concaténée dans du SQL.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

if (!defined('DEUS_DAF')) {
    http_response_code(404);
    exit;
}

/** @var array $config fourni par inc_connexion.php */

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $config['db']['host'],
    (int) $config['db']['port'],
    $config['db']['name']
);

try {
    $sql = new PDO($dsn, $config['db']['user'], $config['db']['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);

    // On aligne le fuseau horaire de MySQL sur celui de PHP pour que
    // NOW() et date() renvoient la même heure. Le décalage est recalculé
    // à chaque requête, ce qui gère le passage à l'heure d'été.
    $offset = (new DateTime('now', new DateTimeZone($config['app']['timezone'])))->format('P');
    $stmt = $sql->prepare('SET time_zone = ?');
    $stmt->execute([$offset]);
} catch (PDOException $e) {
    error_log('[DEUS-DAF] Connexion BDD impossible : ' . $e->getMessage());

    http_response_code(503);
    if (($config['env'] ?? 'production') !== 'production') {
        exit('Connexion à la base de données impossible : ' . $e->getMessage());
    }
    exit('Service momentanément indisponible.');
}
