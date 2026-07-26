<?php

namespace GlpiPlugin\Gestiontemps;

use CommonDBTM;
use GlpiPlugin\Gestiontemps\Toolbox\Compat;
use Html;
use Session;

/**
 * Congé d'un utilisateur : journée complète ou demi-journée (matin / après-midi).
 *
 * Les congés réduisent le temps théorique attendu d'une journée ; un temps
 * travaillé pendant un congé bascule donc en heures supplémentaires.
 */
class Leave extends CommonDBTM
{
    public static $rightname = 'plugin_gestiontemps_timeentry';

    public const PERIOD_DAY       = 'day';
    public const PERIOD_MORNING   = 'morning';
    public const PERIOD_AFTERNOON = 'afternoon';

    public static function getTypeName($nb = 0)
    {
        return _n('Congé', 'Congés', $nb, 'gestiontemps');
    }

    public static function getIcon()
    {
        return 'ti ti-beach';
    }

    public static function canView()
    {
        return Config::currentUserCanAccess();
    }

    /**
     * Restreint les lignes visibles dans le moteur Search de GLPI :
     * l'utilisateur consulté par le RH, sinon ses propres congés (le RH sans
     * utilisateur sélectionné voit tout le monde).
     *
     * @return string Fragment SQL ajouté à la clause WHERE.
     */
    public static function addDefaultWhere()
    {
        $viewed = Config::getViewedUser();
        if ($viewed !== null) {
            return self::getTable() . ".users_id = " . $viewed;
        }

        if (Session::haveRight('config', UPDATE) || Config::currentUserIsRh()) {
            return '';
        }

        return self::getTable() . ".users_id = " . (int) Session::getLoginUserID();
    }

    public static function canCreate()
    {
        return Config::currentUserCanAccess();
    }

    public static function canUpdate()
    {
        return Config::currentUserCanAccess();
    }

    public static function canPurge()
    {
        return Config::currentUserCanAccess();
    }

    public static function canDelete()
    {
        return Config::currentUserCanAccess();
    }

    /**
     * @return array<string,string>
     */
    public static function periodLabels(): array
    {
        return [
            self::PERIOD_DAY       => __('Journée', 'gestiontemps'),
            self::PERIOD_MORNING   => __('Matin', 'gestiontemps'),
            self::PERIOD_AFTERNOON => __('Après-midi', 'gestiontemps'),
        ];
    }

    public static function periodLabel(string $p): string
    {
        return self::periodLabels()[$p] ?? $p;
    }

    /**
     * Périodes de congé d'un utilisateur pour une date (['day'] ou ['morning'] …).
     *
     * @return string[]
     */
    public static function periodsForDate(int $users_id, string $date): array
    {
        global $DB;

        $periods = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id, 'date' => $date],
        ]);
        foreach ($iterator as $row) {
            $periods[] = $row['period'];
        }
        return $periods;
    }

    /**
     * Minutes d'horaire théorique couvertes par un congé pour une date.
     * (journée = matin + après-midi ; demi-journée = la moitié concernée)
     */
    public static function coveredScheduledMinutes(int $users_id, string $date): int
    {
        $periods = self::periodsForDate($users_id, $date);
        if (empty($periods)) {
            return 0;
        }

        // Total attendu du jour (colonne expected_minutes, fiable même sans
        // les heures matin/après-midi détaillées).
        $total = Schedule::expectedMinutesForDate($users_id, $date);

        // Congé journée -> couvre tout l'attendu.
        if (in_array(self::PERIOD_DAY, $periods, true)) {
            return $total;
        }

        // Demi-journées : minutes détaillées si dispo, sinon la moitié du total.
        $split = Schedule::periodMinutesForDate($users_id, $date);
        $half  = (int) round($total / 2);
        $covered = 0;
        if (in_array(self::PERIOD_MORNING, $periods, true)) {
            $covered += $split['morning'] > 0 ? $split['morning'] : $half;
        }
        if (in_array(self::PERIOD_AFTERNOON, $periods, true)) {
            $covered += $split['afternoon'] > 0 ? $split['afternoon'] : $half;
        }
        return min($covered, $total);
    }

    public function prepareInputForAdd($input)
    {
        if (empty($input['users_id'])) {
            $input['users_id'] = (int) Session::getLoginUserID();
        }
        $input['date_creation'] = Compat::now();
        return $input;
    }

    public function post_addItem()
    {
        Journal::log('Leave', (int) $this->getID(), 'leave_add', [
            'users_id' => (int) ($this->fields['users_id'] ?? 0),
            'date'     => $this->fields['date'] ?? null,
            'period'   => $this->fields['period'] ?? null,
        ], (int) ($this->fields['users_id'] ?? 0));
    }

    public function post_purgeItem()
    {
        Journal::log('Leave', (int) $this->getID(), 'leave_delete', [
            'date'   => $this->fields['date'] ?? null,
            'period' => $this->fields['period'] ?? null,
        ], (int) ($this->fields['users_id'] ?? 0));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function rawSearchOptions()
    {
        $opts = [];
        $opts[] = ['id' => 'common', 'name' => self::getTypeName()];
        $opts[] = [
            'id' => 1, 'table' => self::getTable(), 'field' => 'date',
            'name' => __('Date', 'gestiontemps'), 'datatype' => 'date',
        ];
        $opts[] = [
            'id' => 2, 'table' => 'glpi_users', 'field' => 'name',
            'linkfield' => 'users_id', 'name' => __('Utilisateur', 'gestiontemps'),
            'datatype' => 'dropdown',
        ];
        $opts[] = [
            'id' => 3, 'table' => self::getTable(), 'field' => 'period',
            'name' => __('Période', 'gestiontemps'), 'datatype' => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];
        $opts[] = [
            'id' => 4, 'table' => self::getTable(), 'field' => 'comment',
            'name' => __('Commentaire', 'gestiontemps'), 'datatype' => 'text',
        ];
        return $opts;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'period') {
            return self::periodLabel($values['period']);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Formulaire d'ajout / édition d'un congé.
     */
    public function showForm($ID, $options = [])
    {
        if ((int) $ID > 0) {
            $this->getFromDB((int) $ID);
        } else {
            $this->getEmpty();
        }

        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Utilisateur', 'gestiontemps') . "</td><td>";
        \User::dropdown([
            'name'  => 'users_id',
            'value' => $this->fields['users_id'] ?: Session::getLoginUserID(),
            'right' => 'all',
        ]);
        echo "</td>";
        echo "<td>" . __('Date', 'gestiontemps') . "</td><td>";
        Html::showDateField('date', ['value' => $this->fields['date'] ?: date('Y-m-d')]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Période', 'gestiontemps') . "</td><td>";
        \Dropdown::showFromArray('period', self::periodLabels(), [
            'value' => $this->fields['period'] ?: self::PERIOD_DAY,
        ]);
        echo "</td>";
        echo "<td>" . __('Commentaire', 'gestiontemps') . "</td><td>";
        echo "<textarea name='comment' class='form-control' rows='1'>"
            . Html::cleanInputText($this->fields['comment'] ?? '') . "</textarea>";
        echo "</td></tr>";

        $this->showFormButtons($options);
        return true;
    }
}
