<?php
/**
 * ---------------------------------------------------------------------
 * bin/notify_expiring_cards.php — alertes d'expiration par email
 * ---------------------------------------------------------------------
 * Envoie un message au titulaire de chaque carte dont l'échéance
 * approche, avec la liste nominative des services qui cesseront d'être
 * payés. Les administrateurs configurés reçoivent une copie.
 *
 * DÉSACTIVÉ PAR DÉFAUT : mettre 'mail' => ['enabled' => true] dans
 * inc_config.php pour l'activer.
 *
 * Utilisation :
 *     php bin/notify_expiring_cards.php            envoi réel
 *     php bin/notify_expiring_cards.php --dry-run  aperçu sans envoi
 *
 * Crontab suggérée — tous les lundis à 8 h :
 *     0 8 * * 1 /usr/bin/php /var/www/deus-daf/bin/notify_expiring_cards.php >> /var/log/deus-daf-cron.log 2>&1
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/inc_connexion.php';

$dryRun = in_array('--dry-run', $argv, true);
$actif  = (bool) ($config['mail']['enabled'] ?? false);

echo '[' . date('Y-m-d H:i:s') . "] Alertes d'expiration de carte\n";

if (!$actif && !$dryRun) {
    echo "  Envoi désactivé (mail.enabled = false dans inc_config.php). Rien à faire.\n";
    exit(0);
}

// ---------------------------------------------------------------------
// Les alertes sont calculées par la même fonction que le tableau de
// bord : aucun risque que l'email dise autre chose que l'écran.
// ---------------------------------------------------------------------
$alertes = fetch_card_alerts();

// On ne dérange personne pour une échéance encore lointaine : seuls les
// niveaux « critique » et « avertissement » déclenchent un envoi.
$aEnvoyer = array_filter(
    $alertes,
    static fn (array $a): bool => in_array($a['level'], ['critical', 'warning'], true)
);

if ($aEnvoyer === []) {
    echo "  Aucune carte à signaler.\n";
    exit(0);
}

echo '  ' . count($aEnvoyer) . " carte(s) à signaler.\n";

// ---------------------------------------------------------------------
// Regroupement par titulaire : un seul email par personne, même si
// plusieurs de ses cartes arrivent à échéance.
// ---------------------------------------------------------------------
$parTitulaire = [];

foreach ($aEnvoyer as $alerte) {
    $stmt = $sql->prepare(
        'SELECT u.id, u.email, u.first_name, u.last_name
           FROM fi_cards c JOIN fi_users u ON u.id = c.holder_id
          WHERE c.id = ? AND u.is_active = 1'
    );
    $stmt->execute([$alerte['id']]);
    $titulaire = $stmt->fetch();

    if ($titulaire === false) {
        echo "  · carte #{$alerte['id']} : titulaire désactivé, ignorée.\n";
        continue;
    }

    $parTitulaire[(int) $titulaire['id']]['user'] = $titulaire;
    $parTitulaire[(int) $titulaire['id']]['cards'][] = $alerte;
}

// ---------------------------------------------------------------------
// Rédaction et envoi
// ---------------------------------------------------------------------
$baseUrl     = rtrim((string) ($config['app']['base_url'] ?? ''), '/');
$expediteur  = (string) ($config['mail']['from'] ?? 'no-reply@localhost');
$nomExp      = (string) ($config['mail']['from_name'] ?? 'Deus DAF');
$copies      = (array) ($config['mail']['admin_recipients'] ?? []);
$envoyes     = 0;

foreach ($parTitulaire as $groupe) {
    $user  = $groupe['user'];
    $corps = "Bonjour " . $user['first_name'] . ",\n\n";
    $corps .= "Les cartes bancaires suivantes arrivent à échéance ou sont déjà expirées.\n";
    $corps .= "Les services listés cesseront d'être payés si la carte n'est pas remplacée.\n\n";

    foreach ($groupe['cards'] as $carte) {
        $etat = $carte['days_left'] < 0
            ? 'EXPIRÉE depuis ' . abs($carte['days_left']) . ' jour(s)'
            : 'expire dans ' . $carte['days_left'] . ' jour(s)';

        $corps .= str_repeat('-', 64) . "\n";
        $corps .= $carte['label'] . ' (••••' . $carte['last4'] . ") — {$etat}\n";
        $corps .= 'Échéance : ' . date('m/Y', (int) strtotime((string) $carte['expires_on'])) . "\n";

        if ($carte['services'] === []) {
            $corps .= "Aucun service actif n'est rattaché à cette carte.\n";
        } else {
            $corps .= "\nServices concernés (" . number_format($carte['monthly_at_risk'], 2, ',', ' ')
                . " EUR par mois au total) :\n";

            foreach ($carte['services'] as $service) {
                $corps .= '  · ' . $service['name']
                    . ' — ' . billing_cycle_label((string) $service['cycle'])
                    . ' — ' . number_format($service['amount'], 2, ',', ' ') . " EUR\n";
            }
        }
        $corps .= "\n";
    }

    $corps .= str_repeat('-', 64) . "\n\n";
    $corps .= "Consulter le tableau de bord : {$baseUrl}/home.php\n\n";
    $corps .= "Message automatique, envoyé par {$nomExp}. Ne pas y répondre.\n";

    $nbCartes = count($groupe['cards']);
    $sujet    = '[' . $nomExp . '] ' . $nbCartes . ' carte'
        . ($nbCartes > 1 ? 's bancaires arrivent' : ' bancaire arrive') . ' à échéance';

    // Encodage du sujet : sans cela, les accents partent en charabia
    // dans la plupart des clients de messagerie.
    $sujetEncode = '=?UTF-8?B?' . base64_encode($sujet) . '?=';

    $entetes = [
        'From: ' . '=?UTF-8?B?' . base64_encode($nomExp) . '?= <' . $expediteur . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Auto-Response-Suppress: All',
        'Auto-Submitted: auto-generated',
    ];

    if ($copies !== []) {
        $entetes[] = 'Cc: ' . implode(', ', $copies);
    }

    if ($dryRun) {
        echo "\n  --- APERÇU (aucun envoi) ---\n";
        echo '  À      : ' . $user['email'] . "\n";
        echo '  Sujet  : ' . $sujet . "\n";
        echo '  ' . str_replace("\n", "\n  ", trim($corps)) . "\n";
        continue;
    }

    $ok = mail((string) $user['email'], $sujetEncode, $corps, implode("\r\n", $entetes));

    if ($ok) {
        $envoyes++;
        echo '  · envoyé à ' . $user['email'] . "\n";
        log_activity('notify', 'user', (int) $user['id'], (string) $user['email'], [
            'cards' => ['from' => null, 'to' => count($groupe['cards'])],
        ], (int) $user['id']);
    } else {
        echo '  · ÉCHEC pour ' . $user['email'] . " (voir le journal de la messagerie du serveur)\n";
    }
}

if (!$dryRun) {
    echo "  {$envoyes} message(s) envoyé(s).\n";
}
