<?php
/**
 * Configuration LOCALE DE TEST — générée pour valider le socle.
 * Ne pas déployer telle quelle : voir inc_config.sample.php.
 */
declare(strict_types=1);

if (!defined('DEUS_DAF')) {
    http_response_code(404);
    exit;
}

return [
    'env' => 'development',
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'deus_daf',
        'user'     => 'deus_daf',
        'password' => 'MotDePasseLocalTest',
    ],
    'app' => [
        'name'     => 'Deus DAF',
        'company'  => 'Deus',
        'base_url' => 'http://127.0.0.1:8080',
        'timezone' => 'Europe/Paris',
    ],
    'session' => [
        'name'             => 'DEUSDAF',
        'idle_minutes'     => 120,
        'absolute_minutes' => 720,
        'secure'           => false, // test en HTTP local
        'save_path'        => __DIR__ . '/storage/sessions',
    ],
    'security' => [
        'login' => [
            'max_attempts'    => 8,
            'max_attempts_ip' => 40,
            'window_minutes'  => 15,
            'lockout_minutes' => 15,
        ],
        'password_min_length' => 12,
    ],
    'mail' => [
        'enabled'          => false,
        'from'             => 'daf@exemple.fr',
        'from_name'        => 'Deus DAF',
        'admin_recipients' => [],
    ],
];
