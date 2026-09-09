<?php
/**
 * ---------------------------------------------------------------------
 * bin/install.php — création du premier compte administrateur
 * ---------------------------------------------------------------------
 * À exécuter UNE SEULE FOIS après avoir importé db/schema.sql et créé
 * inc_config.php :
 *
 *     php bin/install.php
 *
 * Le script est utilisable en ligne de commande uniquement.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/inc_connexion.php';

/** Pose une question et renvoie la réponse saisie. */
function ask(string $question, bool $required = true): string
{
    while (true) {
        echo $question;
        $answer = trim((string) fgets(STDIN));

        if ($answer !== '' || !$required) {
            return $answer;
        }
        echo "  → Cette information est obligatoire.\n";
    }
}

/** Pose une question dont la réponse n'est pas affichée à l'écran. */
function ask_hidden(string $question): string
{
    echo $question;

    // On ne coupe l'écho que si l'entrée est bien un terminal : avec une
    // entrée redirigée (script d'installation automatisé, tuyau), stty
    // échouerait bruyamment sans rien masquer.
    $masquable = function_exists('stream_isatty')
        && @stream_isatty(STDIN)
        && @shell_exec('command -v stty 2>/dev/null') !== null;

    if ($masquable) {
        shell_exec('stty -echo 2>/dev/null');
    }

    $answer = trim((string) fgets(STDIN));

    if ($masquable) {
        shell_exec('stty echo 2>/dev/null');
        echo "\n";
    }

    return $answer;
}

echo "\n";
echo "==========================================================\n";
echo "  Deus DAF — création du premier compte administrateur\n";
echo "==========================================================\n\n";

// ---------------------------------------------------------------------
// Vérifications préalables
// ---------------------------------------------------------------------
try {
    $existing = (int) $sql->query('SELECT COUNT(*) FROM fi_users')->fetchColumn();
} catch (PDOException $e) {
    fwrite(STDERR, "Erreur : les tables sont introuvables.\n");
    fwrite(STDERR, "Importez d'abord db/schema.sql dans la base.\n\n");
    exit(1);
}

if ($existing > 0) {
    echo "Il existe déjà {$existing} compte(s) dans la base.\n";
    $confirm = ask('Créer un administrateur supplémentaire quand même ? (oui/non) : ');

    if (!in_array(mb_strtolower($confirm), ['o', 'oui', 'y', 'yes'], true)) {
        echo "Abandon.\n\n";
        exit(0);
    }
    echo "\n";
}

// ---------------------------------------------------------------------
// Saisie
// ---------------------------------------------------------------------
$firstName = ask('Prénom                      : ');
$lastName  = ask('Nom                         : ');

do {
    $email = mb_strtolower(ask('Email (identifiant)         : '));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "  → Adresse email invalide.\n";
        continue;
    }

    $check = $sql->prepare('SELECT COUNT(*) FROM fi_users WHERE email = ?');
    $check->execute([$email]);

    if ((int) $check->fetchColumn() > 0) {
        echo "  → Cette adresse est déjà utilisée.\n";
        continue;
    }

    break;
} while (true);

$staffType = '';
while (!in_array($staffType, ['director', 'collaborator'], true)) {
    $answer = mb_strtolower(ask('Dirigeant ou collaborateur ? (d/c) : '));
    $staffType = $answer === 'd' ? 'director' : ($answer === 'c' ? 'collaborator' : '');
}

do {
    $password = ask_hidden('Mot de passe                : ');
    $problem  = password_problem($password);

    if ($problem !== null) {
        echo "  → {$problem}\n";
        continue;
    }

    $confirmation = ask_hidden('Confirmation                : ');

    if (!hash_equals($password, $confirmation)) {
        echo "  → Les deux saisies diffèrent.\n";
        continue;
    }

    break;
} while (true);

// ---------------------------------------------------------------------
// Création
// ---------------------------------------------------------------------
$stmt = $sql->prepare(
    'INSERT INTO fi_users (first_name, last_name, email, password_hash, role, staff_type, is_active)
     VALUES (?, ?, ?, ?, \'admin\', ?, 1)'
);
$stmt->execute([$firstName, $lastName, $email, hash_password($password), $staffType]);

$newId = (int) $sql->lastInsertId();

log_activity('create', 'user', $newId, $email, ['role' => ['from' => null, 'to' => 'admin']], $newId);

echo "\n";
echo "----------------------------------------------------------\n";
echo "  Compte administrateur créé (identifiant interne #{$newId}).\n";
echo "  Connectez-vous sur " . ($config['app']['base_url'] ?? '') . "/index.php\n";
echo "  Algorithme de hachage utilisé : "
     . (defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt') . "\n";
echo "----------------------------------------------------------\n\n";
