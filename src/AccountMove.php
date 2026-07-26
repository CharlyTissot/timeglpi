<?php

namespace GlpiPlugin\Gestiontemps;

use CommonDBTM;
use GlpiPlugin\Gestiontemps\Toolbox\Time;

/**
 * Mouvement de compte de temps (crédit / débit de la tirelire).
 *
 * Chaque mouvement est immuable et tracé : bénéficiaire, acteur, motif,
 * montant (secondes, positif = crédit, négatif = débit).
 */
class AccountMove extends CommonDBTM
{
    public static $rightname = 'plugin_gestiontemps_account';

    public const REASON_OVERTIME   = 'overtime';
    public const REASON_RH_DEBIT   = 'rh_debit';
    public const REASON_ADJUSTMENT = 'adjustment';

    public static function getTypeName($nb = 0)
    {
        return _n('Mouvement', 'Mouvements', $nb, 'gestiontemps');
    }

    public static function getIcon()
    {
        return 'ti ti-arrows-exchange';
    }

    /**
     * Libellés des motifs.
     *
     * @return array<string,string>
     */
    public static function reasonLabels(): array
    {
        return [
            self::REASON_OVERTIME   => __('Heures supplémentaires', 'gestiontemps'),
            self::REASON_RH_DEBIT   => __('Prise / débit RH', 'gestiontemps'),
            self::REASON_ADJUSTMENT => __('Ajustement', 'gestiontemps'),
        ];
    }

    public static function reasonLabel(string $reason): string
    {
        return self::reasonLabels()[$reason] ?? $reason;
    }

    /**
     * Liste des mouvements d'un utilisateur (les plus récents d'abord).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forUser(int $users_id, int $limit = 100): array
    {
        global $DB;

        $rows = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
            'ORDER' => 'date_creation DESC',
            'LIMIT' => $limit,
        ]);
        foreach ($iterator as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function rawSearchOptions()
    {
        $opts = [];
        $opts[] = ['id' => 'common', 'name' => self::getTypeName()];
        $opts[] = [
            'id' => 1, 'table' => self::getTable(), 'field' => 'date_creation',
            'name' => __('Date', 'gestiontemps'), 'datatype' => 'datetime',
        ];
        $opts[] = [
            'id' => 2, 'table' => 'glpi_users', 'field' => 'name',
            'linkfield' => 'users_id', 'name' => __('Bénéficiaire', 'gestiontemps'),
            'datatype' => 'dropdown',
        ];
        $opts[] = [
            'id' => 3, 'table' => self::getTable(), 'field' => 'delta_seconds',
            'name' => __('Montant (s)', 'gestiontemps'), 'datatype' => 'number',
        ];
        $opts[] = [
            'id' => 4, 'table' => self::getTable(), 'field' => 'reason',
            'name' => __('Motif', 'gestiontemps'), 'datatype' => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];
        $opts[] = [
            'id' => 5, 'table' => 'glpi_users', 'field' => 'name',
            'linkfield' => 'actor_users_id', 'name' => __('Acteur', 'gestiontemps'),
            'datatype' => 'dropdown',
        ];
        return $opts;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'reason') {
            return self::reasonLabel($values['reason']);
        }
        if ($field === 'delta_seconds') {
            return Time::human((int) $values['delta_seconds']);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }
}
