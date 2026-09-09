<?php
/**
 * ---------------------------------------------------------------------
 * Traitement de la connexion
 * ---------------------------------------------------------------------
 * Endpoint AJAX appelé par index.php. Répond exclusivement en JSON,
 * au format {success, data, message, errors}.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';

// ---------------------------------------------------------------------
// Seule une requête POST est acceptée
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Méthode non autorisée.', null, 405);
}

require_csrf();

// ---------------------------------------------------------------------
// Lecture et validation des entrées
// ---------------------------------------------------------------------
$email    = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

$errors = [];

if ($email === '') {
    $errors['email'] = 'Saisissez votre adresse email.';
} elseif (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = "Cette adresse email n'est pas valide.";
}

if ($password === '') {
    $errors['password'] = 'Saisissez votre mot de passe.';
}

if ($errors !== []) {
    json_response(false, null, 'Le formulaire est incomplet.', $errors, 422);
}

$email = mb_strtolower($email);

// ---------------------------------------------------------------------
// Limitation des tentatives
// ---------------------------------------------------------------------
$lockout = login_lockout_seconds($email);

if ($lockout > 0) {
    header('Retry-After: ' . $lockout);
    json_response(
        false,
        null,
        'Trop de tentatives infructueuses. Réessayez dans ' . ceil($lockout / 60) . ' minute(s).',
        null,
        429
    );
}

// ---------------------------------------------------------------------
// Vérification des identifiants
// ---------------------------------------------------------------------
$stmt = $sql->prepare(
    'SELECT id, first_name, last_name, email, password_hash, role, staff_type,
            is_active, theme, last_login_at
       FROM fi_users
      WHERE email = ?
      LIMIT 1'
);
$stmt->execute([$email]);
$user = $stmt->fetch();

// Hash factice : la vérification prend le même temps que le compte
// existe ou non, ce qui empêche d'énumérer les adresses valides.
$hash = ($user !== false && $user['is_active'] === 1)
    ? $user['password_hash']
    : '$2y$12$usesomesillystringfoeu1uaWJHNyoAvSHMTKlaBSNKLuwCTfeeqhO';

$passwordOk = password_verify($password, $hash);
$granted    = $passwordOk && $user !== false && (int) $user['is_active'] === 1;

record_login_attempt($email, $granted);

if (!$granted) {
    throttle_delay(recent_login_failures($email)['email']);
    log_activity('login_failed', 'user', $user !== false ? (int) $user['id'] : null, $email, null, null);

    json_response(
        false,
        null,
        'Adresse email ou mot de passe incorrect.',
        null,
        401
    );
}

// ---------------------------------------------------------------------
// Rehachage si l'algorithme ou son coût a évolué depuis l'inscription
// ---------------------------------------------------------------------
if (password_needs_rehash($user['password_hash'], password_algorithm())) {
    $update = $sql->prepare('UPDATE fi_users SET password_hash = ? WHERE id = ?');
    $update->execute([hash_password($password), (int) $user['id']]);
}

unset($user['password_hash']);
login_user($user);

json_ok(['redirect' => 'home.php'], 'Connexion réussie.');
