<?php
/**
 * Enregistrement de la grille d'horaires d'un utilisateur.
 */

use GlpiPlugin\Gestiontemps\Account;
use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\Schedule;
use GlpiPlugin\Gestiontemps\Toolbox\Time;

include('../../../inc/includes.php');

Config::checkAccessOrDie();

if (isset($_POST['save'])) {
    // CSRF déjà vérifié par GLPI (plugin csrf_compliant) : ne pas re-vérifier.
    $schedule = new Schedule();
    $schedule->saveGrid($_POST);
    Session::addMessageAfterRedirect(__('Horaires enregistrés.', 'gestiontemps'));
}

if (isset($_POST['clear_start'])) {
    if (Account::clearStartDate((int) ($_POST['users_id'] ?? 0))) {
        Session::addMessageAfterRedirect(
            __('Point de départ individuel effacé : la date de la configuration générale s\'applique de nouveau.', 'gestiontemps')
        );
    }
}

if (isset($_POST['recompute'])) {
    $reset  = !empty($_POST['reset_balance']);
    $frozen = Account::recomputeFrom(
        (int) ($_POST['users_id'] ?? 0),
        (string) ($_POST['recompute_from'] ?? ''),
        $reset
    );
    if ($frozen !== null) {
        Session::addMessageAfterRedirect(
            $reset
                ? __('Compte recalculé à partir de zéro : le passé n\'est plus compté.', 'gestiontemps')
                : sprintf(
                    __('Compte recalculé. Solde figé à la veille : %s.', 'gestiontemps'),
                    Time::human($frozen)
                )
        );
    }
}

Html::back();
