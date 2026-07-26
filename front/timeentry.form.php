<?php
/**
 * Formulaire d'ajout / édition d'une saisie de temps (manuelle).
 */

use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\Menu;
use GlpiPlugin\Gestiontemps\TimeEntry;

include('../../../inc/includes.php');

Config::checkAccessOrDie();

$entry = new TimeEntry();

// Note : le CSRF est vérifié automatiquement par GLPI pour les plugins
// déclarés « csrf_compliant » (voir setup.php). Ne pas rappeler
// Session::checkCSRF() ici, sous peine de re-valider un jeton déjà consommé.
if (isset($_POST['add'])) {
    $entry->check(-1, CREATE, $_POST);
    $entry->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    $entry->check($_POST['id'], UPDATE);
    $entry->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $entry->check($_POST['id'], PURGE);
    $entry->delete($_POST, true);
    $entry->redirectToList();
} else {
    Html::header(
        TimeEntry::getTypeName(),
        $_SERVER['PHP_SELF'],
        'tools',
        'GlpiPlugin\\Gestiontemps\\Menu',
        'timeentry'
    );

    Menu::showNav('timeentry');

    $id = (int) ($_GET['id'] ?? 0);
    $entry->showForm($id);

    Html::footer();
}
