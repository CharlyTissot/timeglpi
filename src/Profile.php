<?php

namespace GlpiPlugin\Gestiontemps;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;

/**
 * Gestion des droits du plugin par profil GLPI.
 *
 * Droits déclarés :
 *  - plugin_gestiontemps_timeentry : voir / saisir du temps
 *  - plugin_gestiontemps_account   : consulter sa tirelire
 *  - plugin_gestiontemps_rh        : décréditer une tirelire (RH)
 *  - plugin_gestiontemps_config    : configurer le plugin
 */
class Profile extends CommonDBTM
{
    public static $rightname = 'profile';

    public const RIGHT_TIMEENTRY = 'plugin_gestiontemps_timeentry';
    public const RIGHT_ACCOUNT   = 'plugin_gestiontemps_account';
    public const RIGHT_RH        = 'plugin_gestiontemps_rh';
    public const RIGHT_CONFIG    = 'plugin_gestiontemps_config';

    public static function getTypeName($nb = 0)
    {
        return __('Gestion du temps', 'gestiontemps');
    }

    /**
     * Liste des droits gérés par le plugin (pour l'onglet Profil).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => TimeEntry::class,
                'label'    => __('Saisie de temps', 'gestiontemps'),
                'field'    => self::RIGHT_TIMEENTRY,
                'rights'   => [READ => __('Voir'), UPDATE => __('Saisir / modifier')],
            ],
            [
                'itemtype' => Account::class,
                'label'    => __('Tirelire (compte de temps)', 'gestiontemps'),
                'field'    => self::RIGHT_ACCOUNT,
                'rights'   => [READ => __('Voir sa tirelire')],
            ],
            [
                'itemtype' => Account::class,
                'label'    => __('RH — décrémenter une tirelire', 'gestiontemps'),
                'field'    => self::RIGHT_RH,
                'rights'   => [UPDATE => __('Décréditer')],
            ],
            [
                'itemtype' => Config::class,
                'label'    => __('Configuration', 'gestiontemps'),
                'field'    => self::RIGHT_CONFIG,
                'rights'   => [UPDATE => __('Configurer')],
            ],
        ];
    }

    /**
     * Installe les droits par défaut sur les profils existants.
     * Le profil « super-admin » reçoit tous les droits.
     */
    public static function installRights(): void
    {
        global $DB;

        $rights = [];
        foreach (self::getAllRights() as $right) {
            $rights[$right['field']] = array_sum(array_keys($right['rights']));
        }

        // Profils disposant déjà du droit "config" -> considérés admins.
        $iterator = $DB->request(['FROM' => 'glpi_profiles']);
        foreach ($iterator as $profile) {
            foreach ($rights as $name => $value) {
                $exists = countElementsInTable('glpi_profilerights', [
                    'profiles_id' => $profile['id'],
                    'name'        => $name,
                ]);
                if ($exists) {
                    continue;
                }
                // Par défaut : droits complets uniquement pour le profil super-admin (id 4).
                $granted = ((int) $profile['id'] === 4) ? $value : 0;
                $DB->insert('glpi_profilerights', [
                    'profiles_id' => $profile['id'],
                    'name'        => $name,
                    'rights'      => $granted,
                ]);
            }
        }
    }

    /**
     * Supprime les droits du plugin de tous les profils.
     */
    public static function uninstallRights(): void
    {
        global $DB;

        $names = [];
        foreach (self::getAllRights() as $right) {
            $names[] = $right['field'];
        }
        if (!empty($names)) {
            $DB->delete('glpi_profilerights', ['name' => $names]);
        }
    }

    /**
     * Nom de l'onglet affiché sur la fiche Profil.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile) {
            return self::getTypeName();
        }
        return '';
    }

    /**
     * Affichage de l'onglet dans la fiche Profil.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Profile) {
            $self = new self();
            $self->showRightsForm((int) $item->getID());
        }
        return true;
    }

    /**
     * Formulaire d'attribution des droits pour un profil donné.
     */
    public function showRightsForm(int $profiles_id): void
    {
        if (!Session::haveRight('profile', READ)) {
            return;
        }

        $matrix = [];
        foreach (self::getAllRights() as $right) {
            $matrix[] = [
                'itemtype' => $right['itemtype'],
                'label'    => $right['label'],
                'field'    => $right['field'],
                'rights'   => $right['rights'],
            ];
        }

        $profile = new \Profile();
        $profile->getFromDB($profiles_id);

        echo "<form method='post' action='" . \Toolbox::getItemTypeFormURL(\Profile::class) . "'>";
        echo Html::hidden('id', ['value' => $profiles_id]);

        $profile->displayRightsChoiceMatrix($matrix, [
            'canedit'       => Session::haveRight('profile', UPDATE),
            'default_class' => 'tab_bg_2',
            'title'         => self::getTypeName(),
        ]);

        if (Session::haveRight('profile', UPDATE)) {
            echo "<div class='center mt-2'>";
            echo Html::submit(_x('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo "</div>";
        }

        Html::closeForm();
    }

    /**
     * Vrai si l'utilisateur courant peut décréditer une tirelire (droit RH
     * OU appartenance à un profil RH listé en configuration).
     */
    public static function canDebitAccount(): bool
    {
        // Réservé aux profils RH sélectionnés dans la configuration.
        return Config::currentUserIsRh();
    }
}
