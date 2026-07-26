<?php
/**
 * Consultation du journal d'audit (qui / quoi / quand).
 */

use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\Journal;
use GlpiPlugin\Gestiontemps\Menu;

include('../../../inc/includes.php');

Config::checkAccessOrDie();

Html::header(
    Journal::getTypeName(),
    $_SERVER['PHP_SELF'],
    'tools',
    'GlpiPlugin\\Gestiontemps\\Menu',
    'journal'
);

Menu::showNav('journal');

Search::show(Journal::class);

Html::footer();
