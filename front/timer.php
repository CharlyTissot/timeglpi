<?php
/**
 * Enregistrement d'un temps :
 *  - depuis le chrono du tableau de bord (stop_timer), ou
 *  - depuis un clic sur une zone libre du disque journalier (quick_add).
 *
 * Toujours en non-production. Les natures « mise à disposition » (pause
 * comptée dans le temps de travail) et « coupure » (temps non travaillé)
 * utilisent une source dédiée et n'exigent pas de commentaire.
 */

use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\TimeEntry;
use GlpiPlugin\Gestiontemps\Toolbox\Compat;

include('../../../inc/includes.php');

Config::checkAccessOrDie();

if (isset($_POST['stop_timer']) || isset($_POST['quick_add'])) {
    // CSRF vérifié automatiquement par GLPI (plugin csrf_compliant).
    $comment  = trim((string) ($_POST['comment'] ?? ''));
    // Nature : travail (défaut), mise à disposition, ou coupure.
    $nature   = (string) ($_POST['nature'] ?? '');
    if ($nature === '' && !empty($_POST['pause'])) {
        $nature = 'pause'; // compatibilité avec l'ancienne case à cocher
    }
    $is_pause = in_array($nature, ['pause', 'break'], true);

    if (isset($_POST['quick_add'])) {
        // Création depuis le disque : heure de début + heure de fin (HH:MM) sur
        // le jour visualisé (paramètre « day », défaut aujourd'hui).
        $day = (string) ($_POST['day'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || strtotime($day) === false) {
            $day = date('Y-m-d');
        }
        $re    = '/^\d{1,2}:\d{2}$/';
        $st    = preg_match($re, (string) ($_POST['start_time'] ?? '')) ? $_POST['start_time'] : '00:00';
        $en    = preg_match($re, (string) ($_POST['end_time'] ?? '')) ? $_POST['end_time'] : '00:00';
        $date_start = $day . ' ' . $st . ':00';
        $duration   = strtotime($day . ' ' . $en . ':00') - strtotime($date_start);
    } else {
        // Chrono : la durée mesurée se termine maintenant.
        $duration   = (int) ($_POST['duration'] ?? 0);
        $date_start = date('Y-m-d H:i:s', time() - $duration);
    }

    // Commentaire obligatoire sauf pour une pause.
    $comment_ok = $is_pause || $comment !== '';

    if ($duration > 0 && $comment_ok) {
        $entry = new TimeEntry();
        $data  = [
            'users_id'   => (int) Session::getLoginUserID(),
            'date_start' => $date_start,
            'duration'   => $duration,
            'comment'    => $comment,
        ];
        if ($nature === 'pause') {
            $data['source'] = TimeEntry::SOURCE_PAUSE;
        } elseif ($nature === 'break') {
            $data['source'] = TimeEntry::SOURCE_BREAK;
        }
        $entry->add($data);

        Session::addMessageAfterRedirect(
            $nature === 'break'
                ? __('Coupure enregistrée (hors temps de travail).', 'gestiontemps')
                : ($nature === 'pause'
                    ? __('Mise à disposition enregistrée.', 'gestiontemps')
                    : __('Temps enregistré (non-production).', 'gestiontemps'))
        );
    } else {
        Session::addMessageAfterRedirect(
            __('Durée ou commentaire manquant : rien enregistré.', 'gestiontemps'),
            false,
            ERROR
        );
    }
}

// Retour au tableau de bord en préservant le contexte (jour visualisé, période,
// utilisateur ciblé) pour rester sur la même journée après un ajout.
$redirectParams = [];
foreach (['day', 'from', 'to', 'users_id'] as $k) {
    $v = $_POST[$k] ?? '';
    if ($v !== '') {
        $redirectParams[$k] = $v;
    }
}
$redirectUrl = Compat::webPath() . '/front/dashboard.php';
if (!empty($redirectParams)) {
    $redirectUrl .= '?' . http_build_query($redirectParams);
}
Html::redirect($redirectUrl);
