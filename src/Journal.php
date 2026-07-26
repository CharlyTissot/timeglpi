<?php

namespace GlpiPlugin\Gestiontemps;

use CommonDBTM;
use GlpiPlugin\Gestiontemps\Toolbox\Compat;
use Session;

/**
 * Journal d'audit : trace « qui fait quoi et quand » pour toutes les
 * actions du plugin (saisie de temps, crédit/débit tirelire, config, horaires).
 */
class Journal extends CommonDBTM
{
    public static $rightname = 'plugin_gestiontemps_timeentry';

    public static function getTypeName($nb = 0)
    {
        return _n('Entrée de journal', 'Journal', $nb, 'gestiontemps');
    }

    public static function getIcon()
    {
        return 'ti ti-history';
    }

    /**
     * Accès piloté par la configuration (profils d'accès / RH), indépendant
     * des droits GLPI par profil.
     */
    public static function canView()
    {
        return Config::currentUserCanAccess();
    }

    public static function canCreate()
    {
        return Config::currentUserCanAccess();
    }

    /**
     * Restreint les lignes visibles dans le moteur Search de GLPI.
     *
     * - Le service RH (et l'administrateur ayant le droit « config ») voit tout
     *   le journal.
     * - Tout autre utilisateur ne voit que les entrées le concernant : celles
     *   qu'il a lui-même générées (actor_users_id) et celles produites par le
     *   RH à son sujet (target_users_id). Il ne voit jamais les entrées des
     *   autres utilisateurs.
     *
     * @return string Fragment SQL ajouté à la clause WHERE.
     */
    public static function addDefaultWhere()
    {
        if (Session::haveRight('config', UPDATE) || Config::currentUserIsRh()) {
            return '';
        }

        $uid   = (int) Session::getLoginUserID();
        $table = self::getTable();
        return "({$table}.actor_users_id = {$uid} OR {$table}.target_users_id = {$uid})";
    }

    /**
     * Enregistre une entrée de journal.
     *
     * @param string               $itemtype        Type d'objet concerné (nom court)
     * @param int                  $items_id        Id de l'objet concerné
     * @param string               $action          Code d'action (ex. time_add)
     * @param array<string,mixed>  $details         Données contextuelles (JSON)
     * @param int                  $target_users_id Utilisateur concerné par l'action
     *                                              (0 = action globale sans sujet).
     */
    public static function log(
        string $itemtype,
        int $items_id,
        string $action,
        array $details = [],
        int $target_users_id = 0
    ): void {
        $journal = new self();
        $journal->add([
            'actor_users_id'  => (int) (Session::getLoginUserID() ?: 0),
            'target_users_id' => $target_users_id,
            'itemtype'        => $itemtype,
            'items_id'        => $items_id,
            'action'          => $action,
            'details'         => json_encode($details, JSON_UNESCAPED_UNICODE),
            'date_creation'   => Compat::now(),
        ]);
    }

    /**
     * Libellés lisibles des codes d'action.
     *
     * @return array<string,string>
     */
    public static function actionLabels(): array
    {
        return [
            'time_add'       => __('Ajout de temps', 'gestiontemps'),
            'time_update'    => __('Modification de temps', 'gestiontemps'),
            'time_delete'    => __('Suppression de temps', 'gestiontemps'),
            'timer_start'    => __('Démarrage minuteur', 'gestiontemps'),
            'timer_stop'     => __('Arrêt minuteur', 'gestiontemps'),
            'overtime_credit' => __('Crédit heures supp', 'gestiontemps'),
            'account_debit'  => __('Débit tirelire (RH)', 'gestiontemps'),
            'account_adjust' => __('Ajustement tirelire', 'gestiontemps'),
            'account_opening' => __('Définition solde initial', 'gestiontemps'),
            'schedule_update' => __('Modification horaires', 'gestiontemps'),
            'leave_add'      => __('Ajout de congé', 'gestiontemps'),
            'leave_delete'   => __('Suppression de congé', 'gestiontemps'),
            'config_update'  => __('Modification configuration', 'gestiontemps'),
        ];
    }

    /**
     * Options de recherche (moteur Search de GLPI).
     *
     * @return array<int,array<string,mixed>>
     */
    public function rawSearchOptions()
    {
        $opts = [];

        $opts[] = ['id' => 'common', 'name' => self::getTypeName()];

        $opts[] = [
            'id'    => 1,
            'table' => self::getTable(),
            'field' => 'date_creation',
            'name'  => __('Date', 'gestiontemps'),
            'datatype' => 'datetime',
            'massiveaction' => false,
        ];
        $opts[] = [
            'id'    => 2,
            'table' => 'glpi_users',
            'field' => 'name',
            'name'  => __('Acteur', 'gestiontemps'),
            'linkfield' => 'actor_users_id',
            'datatype'  => 'dropdown',
        ];
        $opts[] = [
            'id'    => 3,
            'table' => self::getTable(),
            'field' => 'action',
            'name'  => __('Action', 'gestiontemps'),
            'datatype' => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];
        $opts[] = [
            'id'    => 4,
            'table' => self::getTable(),
            'field' => 'itemtype',
            'name'  => __('Type d\'objet', 'gestiontemps'),
            'datatype' => 'string',
        ];
        $opts[] = [
            'id'    => 5,
            'table' => self::getTable(),
            'field' => 'items_id',
            'name'  => __('Id objet', 'gestiontemps'),
            'datatype' => 'integer',
        ];
        $opts[] = [
            'id'    => 6,
            'table' => self::getTable(),
            'field' => 'details',
            'name'  => __('Détails', 'gestiontemps'),
            'datatype' => 'text',
        ];
        $opts[] = [
            'id'    => 7,
            'table' => 'glpi_users',
            'field' => 'name',
            'name'  => __('Utilisateur concerné', 'gestiontemps'),
            'linkfield' => 'target_users_id',
            'datatype'  => 'dropdown',
        ];

        return $opts;
    }

    /**
     * Rendu spécifique de la colonne « action ».
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'action') {
            $labels = self::actionLabels();
            return $labels[$values['action']] ?? $values['action'];
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }
}
