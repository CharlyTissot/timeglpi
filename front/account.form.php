<?php
/**
 * Action de débit RH sur une tirelire.
 */

use GlpiPlugin\Gestiontemps\Account;
use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\Toolbox\Time;

include('../../../inc/includes.php');

Config::checkAccessOrDie();

// CSRF déjà vérifié par GLPI (plugin csrf_compliant) : ne pas re-vérifier.
$users_id = (int) ($_POST['users_id'] ?? 0);

if (isset($_POST['debit'])) {
    $minutes = (int) ($_POST['debit_minutes'] ?? 0);
    $comment = trim((string) ($_POST['comment'] ?? ''));

    if ($users_id > 0 && $minutes > 0) {
        if (Account::debitByRh($users_id, Time::minutesToSeconds($minutes), $comment)) {
            Session::addMessageAfterRedirect(
                sprintf(__('Tirelire décrémentée de %s.', 'gestiontemps'), Time::human($minutes * 60))
            );
        }
    } else {
        Session::addMessageAfterRedirect(__('Saisie invalide.', 'gestiontemps'), false, ERROR);
    }
} elseif (isset($_POST['set_opening'])) {
    $minutes = (int) ($_POST['opening_minutes'] ?? 0);
    if ($users_id > 0) {
        if (Account::setOpeningBalance($users_id, Time::minutesToSeconds($minutes))) {
            Session::addMessageAfterRedirect(
                sprintf(__('Solde initial défini à %s.', 'gestiontemps'), Time::human($minutes * 60))
            );
        }
    }
}

Html::back();
