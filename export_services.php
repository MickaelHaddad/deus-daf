<?php
/**
 * ---------------------------------------------------------------------
 * Export CSV des services
 * ---------------------------------------------------------------------
 * Les filtres actifs à l'écran sont transmis en paramètres d'URL par
 * services_liste.php, afin que le fichier corresponde exactement à ce
 * que l'utilisateur a sous les yeux.
 *
 * Format : UTF-8 avec BOM et séparateur point-virgule, pour qu'Excel en
 * version française ouvre le fichier correctement d'un double-clic.
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';
require_login();

// ---------------------------------------------------------------------
// Filtres, repris de la liste
// ---------------------------------------------------------------------
$filtreCarte    = (string) ($_GET['carte'] ?? '');
$filtreReferent = (string) ($_GET['referent'] ?? '');
$filtreCycle    = post_enum_value((string) ($_GET['cycle'] ?? ''), array_keys(billing_cycles()));
$filtreStatut   = (string) ($_GET['statut'] ?? '');

$conditions = [];
$params     = [];

if ($filtreCarte === 'none') {
    $conditions[] = 's.card_id IS NULL';
} elseif ($filtreCarte !== '' && ctype_digit($filtreCarte)) {
    $conditions[] = 's.card_id = ?';
    $params[]     = (int) $filtreCarte;
}

if ($filtreReferent === 'none') {
    $conditions[] = 's.owner_id IS NULL';
} elseif ($filtreReferent !== '' && ctype_digit($filtreReferent)) {
    $conditions[] = 's.owner_id = ?';
    $params[]     = (int) $filtreReferent;
}

if ($filtreCycle !== null) {
    $conditions[] = 's.billing_cycle = ?';
    $params[]     = $filtreCycle;
}

if (in_array($filtreStatut, array_keys(service_statuses()), true)) {
    $conditions[] = 's.status = ?';
    $params[]     = $filtreStatut;
} elseif ($filtreStatut === 'alert') {
    // Services actifs dont la carte est expirée ou résiliée.
    $conditions[] = "s.status = 'active' AND c.id IS NOT NULL
                     AND (c.status = 'cancelled' OR c.expires_on < CURDATE())";
}

$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

$stmt = $sql->prepare(service_select_sql() . $where . ' ORDER BY s.name');
$stmt->execute($params);
$services = $stmt->fetchAll();

// ---------------------------------------------------------------------
// Génération du fichier
// ---------------------------------------------------------------------
$nomFichier = 'services-' . date('Y-m-d') . '.csv';

// Le tampon ouvert par inc_connexion.php contiendrait des octets
// parasites en tête de fichier : on le vide avant d'écrire.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$sortie = fopen('php://output', 'wb');

// Marque d'ordre des octets : sans elle, Excel FR affiche « MÃ©tier ».
fwrite($sortie, "\xEF\xBB\xBF");

fputcsv($sortie, [
    'Service', 'URL', 'Type d\'abonnement', 'Montant EUR', 'Coût mensualisé EUR',
    'Coût annualisé EUR', 'Carte', '4 derniers chiffres', 'État de la carte',
    'Banque', 'Société', 'Référent', 'Prochaine échéance', 'Statut', 'Commentaire',
], ';', '"', '');

$totalMensuel = 0.0;

foreach ($services as $s) {
    $mensuel = (float) $s['monthly_cost'];

    if ($s['status'] === 'active') {
        $totalMensuel += $mensuel;
    }

    $etatCarte = $s['card_id'] === null
        ? ''
        : card_status_badge(card_effective_status([
            'status'     => $s['card_status'],
            'expires_on' => $s['card_expires_on'],
        ]))['label'];

    fputcsv($sortie, [
        $s['name'],
        $s['url'] ?? '',
        billing_cycle_label((string) $s['billing_cycle']),
        number_format((float) $s['amount'], 2, ',', ''),
        number_format($mensuel, 2, ',', ''),
        number_format($mensuel * 12, 2, ',', ''),
        $s['card_label'] ?? '',
        $s['card_last4'] ?? '',
        $etatCarte,
        $s['bank_name'] ?? '',
        $s['bank_company'] === null ? '' : company_label((string) $s['bank_company']),
        $s['owner_id'] === null ? '' : trim((string) $s['owner_first_name'] . ' ' . (string) $s['owner_last_name']),
        $s['next_renewal_on'] ?? '',
        service_status_badge((string) $s['status'])['label'],
        // Les sauts de ligne d'un commentaire casseraient la lecture du
        // fichier dans certains tableurs.
        str_replace(["\r\n", "\r", "\n"], ' ', (string) ($s['notes'] ?? '')),
    ], ';', '"', '');
}

// Ligne de totaux, sur les seuls services actifs.
fputcsv($sortie, [], ';', '"', '');
fputcsv($sortie, [
    'TOTAL (services actifs)', '', '', '',
    number_format($totalMensuel, 2, ',', ''),
    number_format($totalMensuel * 12, 2, ',', ''),
], ';', '"', '');

fclose($sortie);

log_activity('export', 'service', null, count($services) . ' service(s) exporté(s)');
exit;
