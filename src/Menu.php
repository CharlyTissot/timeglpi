<?php

namespace GlpiPlugin\Gestiontemps;

use CommonGLPI;
use GlpiPlugin\Gestiontemps\Toolbox\Compat;
use Session;

/**
 * Entrée de menu principale du plugin (sous « Outils »).
 */
class Menu extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Gestion du temps', 'gestiontemps');
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getIcon()
    {
        return 'ti ti-clock-hour-4';
    }

    /**
     * Contenu du sous-menu.
     *
     * @return array<string,mixed>
     */
    public static function getMenuContent()
    {
        $web  = Compat::webPath();
        $menu = [
            'title' => self::getTypeName(),
            'page'  => '/plugins/gestiontemps/front/dashboard.php',
            'icon'  => self::getIcon(),
        ];

        $menu['options'] = [];

        // Le menu n'est visible que pour les profils autorisés en configuration.
        if (!Config::currentUserCanAccess()) {
            return false;
        }

        $menu['options']['dashboard'] = [
            'title' => __('Tableau de bord', 'gestiontemps'),
            'page'  => '/plugins/gestiontemps/front/dashboard.php',
            'icon'  => 'ti ti-chart-pie',
        ];
        $menu['options']['timeentry'] = [
            'title' => __('Saisies de temps', 'gestiontemps'),
            'page'  => '/plugins/gestiontemps/front/timeentry.php',
            'icon'  => 'ti ti-clock-edit',
            'links' => [
                'add'    => '/plugins/gestiontemps/front/timeentry.form.php',
                'search' => '/plugins/gestiontemps/front/timeentry.php',
            ],
        ];
        $menu['options']['summary'] = [
            'title' => __('Synthèse', 'gestiontemps'),
            'page'  => '/plugins/gestiontemps/front/summary.php',
            'icon'  => 'ti ti-table',
        ];
        $menu['options']['schedule'] = [
            'title' => __('Horaires', 'gestiontemps'),
            'page'  => '/plugins/gestiontemps/front/schedule.php',
            'icon'  => 'ti ti-calendar-time',
        ];
        $menu['options']['leave'] = [
            'title' => __('Congés', 'gestiontemps'),
            'page'  => '/plugins/gestiontemps/front/leave.php',
            'icon'  => 'ti ti-beach',
        ];
        $menu['options']['account'] = [
            'title' => __('Tirelire', 'gestiontemps'),
            'page'  => '/plugins/gestiontemps/front/account.php',
            'icon'  => 'ti ti-pig-money',
        ];
        $menu['options']['journal'] = [
            'title' => __('Journal', 'gestiontemps'),
            'page'  => '/plugins/gestiontemps/front/journal.php',
            'icon'  => 'ti ti-history',
        ];

        return $menu;
    }

    /**
     * Barre de navigation interne du plugin, à afficher en haut de chaque
     * page (fiable quel que soit le rendu du sous-menu GLPI).
     *
     * @param string $current Clé de la page courante (dashboard, timeentry,
     *                        schedule, account, journal).
     */
    public static function showNav(string $current = ''): void
    {
        if (!Config::currentUserCanAccess()) {
            return;
        }

        $web = Compat::webPath();

        $items = [
            'dashboard' => [__('Tableau de bord', 'gestiontemps'), '/front/dashboard.php', 'ti ti-chart-pie'],
            'timeentry' => [__('Saisies de temps', 'gestiontemps'), '/front/timeentry.php', 'ti ti-clock-edit'],
            'summary'   => [__('Synthèse', 'gestiontemps'), '/front/summary.php', 'ti ti-table'],
            'schedule'  => [__('Horaires', 'gestiontemps'), '/front/schedule.php', 'ti ti-calendar-time'],
            'leave'     => [__('Congés', 'gestiontemps'), '/front/leave.php', 'ti ti-beach'],
            'account'   => [__('Tirelire', 'gestiontemps'), '/front/account.php', 'ti ti-pig-money'],
            'journal'   => [__('Journal', 'gestiontemps'), '/front/journal.php', 'ti ti-history'],
        ];

        echo "<div class='gestiontemps-nav mb-3'>";
        foreach ($items as $key => $it) {
            $cls = ($key === $current) ? 'btn btn-primary btn-sm' : 'btn btn-outline-secondary btn-sm';
            echo "<a class='{$cls}' href='" . $web . $it[1] . "'>"
                . "<i class='" . $it[2] . "'></i> " . $it[0] . "</a> ";
        }
        echo "</div>";

        self::showViewedUserBanner();
    }

    /**
     * Bandeau rappelant qu'un membre RH consulte les données d'un autre
     * utilisateur, avec le moyen de revenir à ses propres données. Sans ce
     * rappel, la portée des listes filtrées serait invisible.
     */
    private static function showViewedUserBanner(): void
    {
        $viewed = Config::getViewedUser();
        if ($viewed === null) {
            return;
        }

        echo "<div class='alert alert-warning d-flex align-items-center justify-content-between'>";
        echo "<span><i class='ti ti-user-search'></i> "
            . sprintf(
                __('Vous consultez les données de %s.', 'gestiontemps'),
                '<b>' . getUserName($viewed) . '</b>'
            )
            . "</span>";
        echo "<a class='btn btn-sm btn-outline-secondary' href='"
            . Compat::webPath() . "/front/dashboard.php?users_id='>"
            . __('Revenir à mes données', 'gestiontemps') . "</a>";
        echo "</div>";
    }
}
