<?php
/**
 * Minuteur AJAX : démarre / arrête un chrono lié (ou non) à un ticket.
 *
 * Le minuteur en cours est stocké en session. À l'arrêt, une saisie de temps
 * est créée (production si un ticket est associé, sinon manuel).
 */

use GlpiPlugin\Gestiontemps\Journal;
use GlpiPlugin\Gestiontemps\Profile;
use GlpiPlugin\Gestiontemps\TimeEntry;
use GlpiPlugin\Gestiontemps\Toolbox\Compat;
use GlpiPlugin\Gestiontemps\Toolbox\Time;

include('../../../inc/includes.php');

Session::checkRight(Profile::RIGHT_TIMEENTRY, UPDATE);
header('Content-Type: application/json');

$action     = $_POST['action'] ?? $_GET['action'] ?? '';
$tickets_id = (int) ($_POST['tickets_id'] ?? 0);

if (!isset($_SESSION['plugin_gestiontemps_timer'])) {
    $_SESSION['plugin_gestiontemps_timer'] = null;
}

switch ($action) {
    case 'start':
        if (isset($_POST['_glpi_csrf_token'])) {
            Session::checkCSRF($_POST);
        }
        $_SESSION['plugin_gestiontemps_timer'] = [
            'started_at' => time(),
            'tickets_id' => $tickets_id ?: null,
        ];
        Journal::log('TimeEntry', 0, 'timer_start', ['tickets_id' => $tickets_id]);
        echo json_encode(['status' => 'running', 'started_at' => time()]);
        break;

    case 'stop':
        Session::checkCSRF($_POST);
        $timer = $_SESSION['plugin_gestiontemps_timer'];
        if (!$timer) {
            echo json_encode(['status' => 'idle']);
            break;
        }
        $duration = max(0, time() - (int) $timer['started_at']);
        $entry    = new TimeEntry();
        $newid    = (int) $entry->add([
            'users_id'   => (int) Session::getLoginUserID(),
            'tickets_id' => $timer['tickets_id'],
            'date_start' => date('Y-m-d H:i:s', (int) $timer['started_at']),
            'duration'   => $duration,
            'source'     => $timer['tickets_id'] ? TimeEntry::SOURCE_TIMER : TimeEntry::SOURCE_MANUAL,
            'comment'    => $_POST['comment'] ?? '',
        ]);
        $_SESSION['plugin_gestiontemps_timer'] = null;
        Journal::log('TimeEntry', $newid, 'timer_stop', [
            'duration'   => $duration,
            'tickets_id' => $timer['tickets_id'],
        ]);
        echo json_encode([
            'status'   => 'stopped',
            'duration' => $duration,
            'human'    => Time::human($duration),
        ]);
        break;

    case 'status':
    default:
        $timer = $_SESSION['plugin_gestiontemps_timer'];
        echo json_encode([
            'status'     => $timer ? 'running' : 'idle',
            'started_at' => $timer['started_at'] ?? null,
            'tickets_id' => $timer['tickets_id'] ?? null,
        ]);
        break;
}
