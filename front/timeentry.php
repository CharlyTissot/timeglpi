<?php
/**
 * Liste / recherche des saisies de temps.
 */

use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\Menu;
use GlpiPlugin\Gestiontemps\TimeEntry;

include('../../../inc/includes.php');

Config::checkAccessOrDie();

Html::header(
    TimeEntry::getTypeName(),
    $_SERVER['PHP_SELF'],
    'tools',
    'GlpiPlugin\\Gestiontemps\\Menu',
    'timeentry'
);

Menu::showNav('timeentry');

Search::show(TimeEntry::class);

Html::footer();
