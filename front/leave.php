<?php
/**
 * Liste des congés + accès à la création.
 */

use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\Leave;
use GlpiPlugin\Gestiontemps\Menu;

include('../../../inc/includes.php');

Config::checkAccessOrDie();

Html::header(
    Leave::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'tools',
    'GlpiPlugin\\Gestiontemps\\Menu',
    'leave'
);

Menu::showNav('leave');

echo "<div class='mb-2'>";
echo "<a class='btn btn-primary' href='" . \GlpiPlugin\Gestiontemps\Toolbox\Compat::webPath()
    . "/front/leave.form.php'><i class='ti ti-plus'></i> " . __('Ajouter un congé', 'gestiontemps') . "</a>";
echo "</div>";

Search::show(Leave::class);

Html::footer();
