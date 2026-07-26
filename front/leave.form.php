<?php
/**
 * Formulaire d'ajout / édition / suppression d'un congé.
 */

use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\Leave;
use GlpiPlugin\Gestiontemps\Menu;

include('../../../inc/includes.php');

Config::checkAccessOrDie();

$leave = new Leave();

if (isset($_POST['add'])) {
    // CSRF vérifié automatiquement par GLPI (plugin csrf_compliant).
    $leave->check(-1, CREATE, $_POST);
    $leave->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    $leave->check($_POST['id'], UPDATE);
    $leave->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $leave->check($_POST['id'], PURGE);
    $leave->delete($_POST, true);
    $leave->redirectToList();
} else {
    Html::header(
        Leave::getTypeName(),
        $_SERVER['PHP_SELF'],
        'tools',
        'GlpiPlugin\\Gestiontemps\\Menu',
        'leave'
    );

    Menu::showNav('leave');

    $id = (int) ($_GET['id'] ?? 0);
    $leave->showForm($id);

    Html::footer();
}
