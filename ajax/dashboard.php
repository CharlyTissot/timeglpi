<?php
/**
 * Données du tableau de bord au format JSON.
 *
 * Interrogé périodiquement par le navigateur pour rafraîchir les disques et
 * les indicateurs sans recharger la page. Lecture seule : aucun effet de bord,
 * donc pas de jeton CSRF nécessaire (requête GET).
 */

use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\Dashboard\ProductionCard;
use GlpiPlugin\Gestiontemps\Schedule;
use GlpiPlugin\Gestiontemps\Toolbox\Time;

include('../../../inc/includes.php');

Config::checkAccessOrDie();

header('Content-Type: application/json');

$isRh = Config::currentUserIsRh();

// Mêmes règles de portée que la page : un non-RH ne lit que ses données.
$users_id = null;
if (isset($_GET['users_id']) && $_GET['users_id'] !== '') {
    $users_id = (int) $_GET['users_id'];
}
if (!$isRh) {
    $users_id = (int) Session::getLoginUserID();
}

$today = date('Y-m-d');

/** Valide une date « Y-m-d », avec repli sur une valeur sûre. */
$validDate = static function ($value, string $fallback): string {
    $value = (string) $value;
    return (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false)
        ? $value
        : $fallback;
};

$from = $validDate($_GET['from'] ?? null, date('Y-m-01'));
$to   = $validDate($_GET['to']   ?? null, $today);
$day  = $validDate($_GET['day']  ?? null, $today);

$data     = ProductionCard::compute($users_id, $from, $to);
$att      = ProductionCard::dailyAttendance($users_id, $from, $to);
$timeline = ProductionCard::dayTimeline($users_id, $day);

echo json_encode([
    'donut' => [
        'production'     => $data['production'],
        'manual'         => $data['manual'],
        'production_pct' => $data['production_pct'],
        'manual_pct'     => $data['manual_pct'],
    ],
    'clock' => [
        'segments' => $timeline['segments'],
        'total'    => $timeline['total'],
    ],
    'schedule' => $users_id !== null ? Schedule::clockPeriodsForDate($users_id, $day) : [],
    'indicators' => [
        'production_pct'   => $data['production_pct'],
        'production_human' => $data['production_human'],
        'manual_human'     => $data['manual_human'],
        'total_human'      => $data['total_human'],
    ],
    'attendance' => [
        'over_human' => Time::human($att['over']),
        'late_human' => Time::human($att['late']),
        'net_human'  => Time::human($att['net']),
        'net'        => $att['net'],
        'days'       => array_map(static function (array $d): array {
            return [
                'date'     => $d['date'],
                'worked'   => (int) $d['worked'],
                'expected' => (int) $d['expected'],
            ];
        }, $att['days']),
    ],
    // Horodatage serveur : affiché comme « mis à jour à … ».
    'now' => date('H:i:s'),
]);
