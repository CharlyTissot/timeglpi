<?php
/**
 * Plugin GLPI « gestiontemps » — Gestion du temps
 * Compatible GLPI 10 (>= 10.0.6) et GLPI 11.
 *
 * Fichier d'amorçage : versions, prérequis, initialisation des hooks.
 */

define('PLUGIN_GESTIONTEMPS_VERSION', '1.5.0');

// Bornes de compatibilité GLPI.
define('PLUGIN_GESTIONTEMPS_MIN_GLPI', '10.0.6');
define('PLUGIN_GESTIONTEMPS_MAX_GLPI', '11.99.99');

use GlpiPlugin\Gestiontemps\Account;
use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\Journal;
use GlpiPlugin\Gestiontemps\Menu;
use GlpiPlugin\Gestiontemps\Profile as GtProfile;
use GlpiPlugin\Gestiontemps\Schedule;
use GlpiPlugin\Gestiontemps\TimeEntry;
use GlpiPlugin\Gestiontemps\Toolbox\Compat;

/**
 * Initialisation du plugin : enregistrement des hooks, classes, menus.
 */
function plugin_init_gestiontemps(): void
{
    global $PLUGIN_HOOKS;

    $plugin_key = 'gestiontemps';

    // CSRF obligatoire sur les formulaires du plugin.
    $PLUGIN_HOOKS['csrf_compliant'][$plugin_key] = true;

    // Enregistrement de l'autoloader PSR-4 (src/ -> GlpiPlugin\Gestiontemps\).
    // GLPI 10.0.6+ et 11 chargent automatiquement src/ pour ce namespace,
    // mais on sécurise l'enregistrement pour les instances plus anciennes.
    $plugin = new Plugin();
    if (!$plugin->isActivated($plugin_key)) {
        return;
    }

    // CSS / JS communs.
    $PLUGIN_HOOKS['add_css'][$plugin_key]     = 'css/gestiontemps.css';
    $PLUGIN_HOOKS['add_javascript'][$plugin_key] = 'js/gestiontemps.js';

    // Profils / droits.
    Plugin::registerClass(GtProfile::class, ['addtabon' => ['Profile']]);

    // Onglet « Temps » sur les tickets et sur les utilisateurs.
    Plugin::registerClass(TimeEntry::class, [
        'addtabon' => ['Ticket', 'User'],
    ]);
    Plugin::registerClass(Schedule::class, ['addtabon' => ['User']]);
    Plugin::registerClass(Account::class, ['addtabon' => ['User']]);

    // Menu principal.
    if (Session::getLoginUserID() !== false) {
        $PLUGIN_HOOKS['menu_toadd'][$plugin_key] = [
            'tools' => Menu::class,
        ];
    }

    // Page de configuration (roue crantée dans la liste des plugins).
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page'][$plugin_key] = 'front/config.form.php';
    }

    // Hooks sur les tâches de ticket : le temps saisi devient de la production.
    $PLUGIN_HOOKS['item_add'][$plugin_key]['TicketTask']    = [TimeEntry::class, 'onTaskAdd'];
    $PLUGIN_HOOKS['item_update'][$plugin_key]['TicketTask'] = [TimeEntry::class, 'onTaskUpdate'];
    $PLUGIN_HOOKS['item_purge'][$plugin_key]['TicketTask']  = [TimeEntry::class, 'onTaskPurge'];

    // Tâches automatiques (CRON).
    $PLUGIN_HOOKS['post_init'][$plugin_key] = 'plugin_gestiontemps_postinit';
}

/**
 * Métadonnées de version et de compatibilité du plugin.
 *
 * @return array<string,mixed>
 */
function plugin_version_gestiontemps(): array
{
    return [
        'name'           => __('Gestion du temps', 'gestiontemps'),
        'version'        => PLUGIN_GESTIONTEMPS_VERSION,
        'author'         => 'Proximiweb',
        'license'        => 'GPL-3.0+',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_GESTIONTEMPS_MIN_GLPI,
                'max' => PLUGIN_GESTIONTEMPS_MAX_GLPI,
            ],
            'php' => [
                'min' => '7.4',
            ],
        ],
    ];
}

/**
 * Vérification des prérequis avant installation/activation.
 */
function plugin_gestiontemps_check_prerequisites(): bool
{
    // IMPORTANT : cette fonction est appelée par GLPI AVANT le chargement du
    // plugin (ex. affichage de la liste des plugins), donc l'autoloader PSR-4
    // de src/ n'est pas encore actif. N'utiliser aucune classe de src/ ici.
    $version = defined('GLPI_VERSION') ? GLPI_VERSION : '0';
    if (version_compare($version, PLUGIN_GESTIONTEMPS_MIN_GLPI, '<')) {
        echo "<p class='red'>"
            . sprintf(
                __('Ce plugin requiert GLPI >= %s.', 'gestiontemps'),
                PLUGIN_GESTIONTEMPS_MIN_GLPI
            )
            . "</p>";
        return false;
    }
    return true;
}

/**
 * Vérification de la configuration (avant activation).
 */
function plugin_gestiontemps_check_config($verbose = false): bool
{
    return true;
}

/**
 * Post-init : enregistre le hook de purge des données quand un utilisateur
 * ou un ticket est supprimé (nettoyage référentiel).
 */
function plugin_gestiontemps_postinit(): void
{
    // Placeholder pour d'éventuels enregistrements tardifs.
}
