<?php
/**
 * Page de configuration du plugin gestiontemps.
 */

use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\TimeEntry;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

$config = new Config();

if (isset($_POST['import_tasks'])) {
    // CSRF déjà vérifié par GLPI (plugin csrf_compliant) : ne pas re-vérifier.
    // On enregistre d'abord le formulaire (notamment la date de départ), puis
    // on importe l'historique à partir de cette date.
    $config->updateFromForm($_POST);
    $count = TimeEntry::importExistingTasks(Config::getBalanceStartDate());
    Session::addMessageAfterRedirect(
        sprintf(
            _n('%d tâche importée.', '%d tâches importées.', $count, 'gestiontemps'),
            $count
        )
    );
    Html::back();
}

if (isset($_POST['update'])) {
    // CSRF déjà vérifié par GLPI (plugin csrf_compliant) : ne pas re-vérifier.
    $config->updateFromForm($_POST);
    Session::addMessageAfterRedirect(__('Configuration enregistrée.', 'gestiontemps'));
    Html::back();
}

Html::header(
    Config::getTypeName(),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$config->showConfigForm();

Html::footer();
