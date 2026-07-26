<?php

namespace GlpiPlugin\Gestiontemps;

use CommonDBTM;
use CommonGLPI;
use GlpiPlugin\Gestiontemps\Toolbox\Compat;
use GlpiPlugin\Gestiontemps\Toolbox\Time;
use Html;
use Session;

/**
 * Horaires théoriques d'un utilisateur.
 *
 * Chaque utilisateur définit, pour chaque jour de la semaine, un nombre de
 * minutes attendues. Deux jeux sont possibles :
 *  - « normal » : semaine identique toutes les semaines ;
 *  - « A » / « B » : alternance semaine A / semaine B.
 *
 * Le type de semaine réel se déduit du n° de semaine ISO et de la parité
 * configurée (Config::week_ref_parity).
 */
class Schedule extends CommonDBTM
{
    public static $rightname = 'plugin_gestiontemps_timeentry';

    public const WEEK_NORMAL = 'normal';
    public const WEEK_A      = 'A';
    public const WEEK_B      = 'B';

    public static function getTypeName($nb = 0)
    {
        return _n('Horaire', 'Horaires', $nb, 'gestiontemps');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-time';
    }

    /**
     * Noms des jours (1=lundi .. 7=dimanche).
     *
     * @return array<int,string>
     */
    public static function weekdayNames(): array
    {
        return [
            1 => __('Lundi', 'gestiontemps'),
            2 => __('Mardi', 'gestiontemps'),
            3 => __('Mercredi', 'gestiontemps'),
            4 => __('Jeudi', 'gestiontemps'),
            5 => __('Vendredi', 'gestiontemps'),
            6 => __('Samedi', 'gestiontemps'),
            7 => __('Dimanche', 'gestiontemps'),
        ];
    }

    /**
     * Détermine le type de semaine (A/B) pour une date donnée, selon le n° de
     * semaine ISO et la parité de référence configurée.
     */
    public static function weekTypeForDate(string $date): string
    {
        $isoWeek = (int) date('W', strtotime($date));
        $parity  = (int) (Config::current()['week_ref_parity'] ?? 0);
        // Semaine paire : A si parity=0, B si parity=1.
        $even = ($isoWeek % 2) === 0;
        if ($even) {
            return $parity === 0 ? self::WEEK_A : self::WEEK_B;
        }
        return $parity === 0 ? self::WEEK_B : self::WEEK_A;
    }

    /**
     * Minutes attendues pour un utilisateur à une date donnée.
     *
     * On privilégie l'horaire du type de semaine calculé (A/B) ; à défaut on
     * retombe sur l'horaire « normal ».
     */
    /**
     * Vrai si l'utilisateur a au moins une ligne d'horaire théorique.
     *
     * Sans horaire configuré, l'attendu vaut 0 pour tous les jours : tout le
     * temps saisi basculerait en « heures en plus », ce qui n'a aucun sens.
     * Les indicateurs d'assiduité sont donc neutralisés pour ces utilisateurs.
     */
    public static function hasAnySchedule(int $users_id): bool
    {
        return countElementsInTable(self::getTable(), ['users_id' => $users_id]) > 0;
    }

    public static function expectedMinutesForDate(int $users_id, string $date): int
    {
        $row = self::rowForDate($users_id, $date);
        return $row ? (int) $row['expected_minutes'] : 0;
    }

    /**
     * Ligne d'horaire applicable à une date (semaine A/B sinon « normale »).
     *
     * @return array<string,mixed>|null
     */
    public static function rowForDate(int $users_id, string $date): ?array
    {
        global $DB;

        $weekday  = (int) date('N', strtotime($date)); // 1..7
        $weekType = self::weekTypeForDate($date);

        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id, 'week_type' => $weekType, 'weekday' => $weekday],
            'LIMIT' => 1,
        ])->current();
        if ($row) {
            return $row;
        }

        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id, 'week_type' => self::WEEK_NORMAL, 'weekday' => $weekday],
            'LIMIT' => 1,
        ])->current();

        return $row ?: null;
    }

    /**
     * Minutes matin / après-midi / total attendues pour une date.
     *
     * @return array{morning:int, afternoon:int, total:int}
     */
    public static function periodMinutesForDate(int $users_id, string $date): array
    {
        $row = self::rowForDate($users_id, $date);
        if (!$row) {
            return ['morning' => 0, 'afternoon' => 0, 'total' => 0];
        }
        $m = self::rangeMinutes((string) ($row['morning_start'] ?? ''), (string) ($row['morning_end'] ?? ''));
        $a = self::rangeMinutes((string) ($row['afternoon_start'] ?? ''), (string) ($row['afternoon_end'] ?? ''));
        return ['morning' => $m, 'afternoon' => $a, 'total' => $m + $a];
    }

    /**
     * Bornes (secondes depuis minuit) des plages matin/après-midi pour une date,
     * pour tracer les repères théoriques sur le disque journalier.
     *
     * @return array<string,array{0:int,1:int}>  ex. ['morning'=>[28800,43200], ...]
     */
    public static function clockPeriodsForDate(int $users_id, string $date): array
    {
        $row = self::rowForDate($users_id, $date);
        $res = [];
        if (!$row) {
            return $res;
        }
        if (!empty($row['morning_start']) && !empty($row['morning_end'])) {
            $res['morning'] = [self::toMinutes($row['morning_start']) * 60, self::toMinutes($row['morning_end']) * 60];
        }
        if (!empty($row['afternoon_start']) && !empty($row['afternoon_end'])) {
            $res['afternoon'] = [self::toMinutes($row['afternoon_start']) * 60, self::toMinutes($row['afternoon_end']) * 60];
        }
        return $res;
    }

    /**
     * Total des minutes attendues d'un utilisateur sur un intervalle de dates
     * (bornes incluses).
     */
    public static function expectedMinutesForRange(int $users_id, string $from, string $to): int
    {
        $total = 0;
        $cursor = strtotime($from);
        $end    = strtotime($to);
        while ($cursor <= $end) {
            $total += self::expectedMinutesForDate($users_id, date('Y-m-d', $cursor));
            $cursor = strtotime('+1 day', $cursor);
        }
        return $total;
    }

    /**
     * Identifiants des utilisateurs ayant au moins un horaire renseigné.
     *
     * @return int[]
     */
    public static function usersWithSchedule(): array
    {
        global $DB;

        $ids = [];
        $iterator = $DB->request([
            'SELECT'   => 'users_id',
            'DISTINCT' => true,
            'FROM'     => self::getTable(),
        ]);
        foreach ($iterator as $row) {
            $ids[] = (int) $row['users_id'];
        }
        return $ids;
    }

    /**
     * Minutes attendues, à une date, tous utilisateurs ayant un horaire
     * confondus (utilisé pour la vue globale RH « tous les utilisateurs »).
     */
    public static function expectedMinutesForDateAllUsers(string $date): int
    {
        $total = 0;
        foreach (self::usersWithSchedule() as $uid) {
            $total += self::expectedMinutesForDate($uid, $date);
        }
        return $total;
    }

    // ---------------------------------------------------------------------
    // Onglet sur la fiche User + formulaire de saisie.
    // ---------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \User && Config::currentUserCanAccess()) {
            return self::getTypeName(Session::getPluralNumber());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \User) {
            $self = new self();
            $self->showForUser((int) $item->getID());
        }
        return true;
    }

    /**
     * Grille de saisie des horaires (normal + A + B) pour un utilisateur.
     */
    public function showForUser(int $users_id): void
    {
        global $DB;

        $canedit = Config::currentUserCanAccess();

        // Valeurs existantes -> [week_type][weekday] = ligne complète.
        $rows = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
        ]);
        foreach ($iterator as $row) {
            $rows[$row['week_type']][(int) $row['weekday']] = $row;
        }

        echo "<form method='post' action='" . Compat::webPath() . "/front/schedule.form.php'>";
        echo Html::hidden('users_id', ['value' => $users_id]);

        // Quota hebdomadaire de référence (réservé RH), en h:m.
        if (Profile::canDebitAccount()) {
            echo "<div class='mb-3'><label><b>" . __('Quota hebdomadaire de référence', 'gestiontemps') . "</b> </label> ";
            \Dropdown::showTimeStamp('weekly_quota', [
                'value'   => Account::getWeeklyQuota($users_id) * 60,
                'min'     => 0,
                'max'     => 60 * HOUR_TIMESTAMP,
                'step'    => 5 * MINUTE_TIMESTAMP,
                'inhours' => true,
            ]);
            echo " <span class='text-muted'>" . __('temps de base (défaut 35h00)', 'gestiontemps') . "</span></div>";
        }

        $weekTypes = [
            self::WEEK_NORMAL => __('Semaine normale', 'gestiontemps'),
            self::WEEK_A      => __('Semaine A', 'gestiontemps'),
            self::WEEK_B      => __('Semaine B', 'gestiontemps'),
        ];

        $cols = [
            'ms' => __('Matin début', 'gestiontemps'),
            'me' => __('Matin fin', 'gestiontemps'),
            'as' => __('Après-midi début', 'gestiontemps'),
            'ae' => __('Après-midi fin', 'gestiontemps'),
        ];

        foreach ($weekTypes as $wt => $wtLabel) {
            echo "<h4 class='mt-3'>" . $wtLabel . "</h4>";
            echo "<div class='table-responsive'><table class='tab_cadre_fixe'>";
            echo "<tr><th>" . __('Jour', 'gestiontemps') . "</th>";
            foreach ($cols as $colLabel) {
                echo "<th>" . $colLabel . "</th>";
            }
            echo "<th>" . __('Total', 'gestiontemps') . "</th></tr>";

            foreach (self::weekdayNames() as $wd => $wdName) {
                $r = $rows[$wt][$wd] ?? [];
                echo "<tr class='tab_bg_1'><td><b>" . $wdName . "</b></td>";
                $map = ['ms' => 'morning_start', 'me' => 'morning_end', 'as' => 'afternoon_start', 'ae' => 'afternoon_end'];
                foreach ($map as $key => $field) {
                    $val = (string) ($r[$field] ?? '');
                    echo "<td>";
                    if ($canedit) {
                        echo "<input type='time' name='sched[{$wt}][{$wd}][{$key}]' value='"
                            . htmlspecialchars($val) . "' class='form-control' style='width:120px'>";
                    } else {
                        echo $val !== '' ? htmlspecialchars($val) : '—';
                    }
                    echo "</td>";
                }
                echo "<td><b>" . Time::human((int) ($r['expected_minutes'] ?? 0) * 60) . "</b></td>";
                echo "</tr>";
            }
            echo "</table></div>";
        }

        echo "<p class='text-muted mt-1'>"
            . __('Laissez « Semaine A » et « Semaine B » vides si vous utilisez uniquement la semaine normale. Le total est recalculé à l\'enregistrement.', 'gestiontemps')
            . "</p>";

        if ($canedit) {
            echo "<div class='mt-2'>";
            echo Html::submit(_x('button', 'Save'), ['name' => 'save', 'class' => 'btn btn-primary']);
            echo "</div>";
        }

        Html::closeForm();
    }

    /**
     * Enregistre la grille d'horaires postée.
     *
     * @param array<string,mixed> $input
     */
    public function saveGrid(array $input): void
    {
        global $DB;

        $users_id = (int) ($input['users_id'] ?? 0);
        if ($users_id <= 0) {
            return;
        }
        // Seul le service RH configure les horaires d'un tiers : sans ce droit,
        // la cible est forcée sur l'utilisateur connecté, quel que soit le POST.
        if (!Config::currentUserIsRh()) {
            $users_id = (int) Session::getLoginUserID();
        }
        $grid = $input['sched'] ?? [];

        foreach ($grid as $weekType => $days) {
            if (!in_array($weekType, [self::WEEK_NORMAL, self::WEEK_A, self::WEEK_B], true)) {
                continue;
            }
            foreach ($days as $weekday => $t) {
                $weekday = (int) $weekday;
                $ms = self::validTime($t['ms'] ?? '');
                $me = self::validTime($t['me'] ?? '');
                $as = self::validTime($t['as'] ?? '');
                $ae = self::validTime($t['ae'] ?? '');
                $minutes = self::rangeMinutes($ms, $me) + self::rangeMinutes($as, $ae);

                $existing = $DB->request([
                    'FROM'  => self::getTable(),
                    'WHERE' => [
                        'users_id'  => $users_id,
                        'week_type' => $weekType,
                        'weekday'   => $weekday,
                    ],
                    'LIMIT' => 1,
                ])->current();

                $data = [
                    'morning_start'    => $ms !== '' ? $ms : null,
                    'morning_end'      => $me !== '' ? $me : null,
                    'afternoon_start'  => $as !== '' ? $as : null,
                    'afternoon_end'    => $ae !== '' ? $ae : null,
                    'expected_minutes' => $minutes,
                    'date_mod'         => Compat::now(),
                ];

                if ($existing) {
                    $data['id'] = $existing['id'];
                    $this->update($data);
                } elseif ($minutes > 0 || $ms !== '' || $me !== '' || $as !== '' || $ae !== '') {
                    $data['users_id']  = $users_id;
                    $data['week_type'] = $weekType;
                    $data['weekday']   = $weekday;
                    $this->add($data);
                }
            }
        }

        // Quota hebdomadaire de référence (RH uniquement).
        if (isset($input['weekly_quota']) && Profile::canDebitAccount()) {
            Account::setWeeklyQuota($users_id, (int) round(((int) $input['weekly_quota']) / 60));
        }

        Journal::log('Schedule', $users_id, 'schedule_update', ['users_id' => $users_id], $users_id);
    }

    /** Valide une heure "HH:MM" (chaîne vide sinon). */
    private static function validTime($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{1,2}:\d{2}$/', $v) ? $v : '';
    }

    /** Minutes entre deux heures "HH:MM" (0 si invalide ou fin <= début). */
    private static function rangeMinutes(string $start, string $end): int
    {
        if ($start === '' || $end === '') {
            return 0;
        }
        $s = self::toMinutes($start);
        $e = self::toMinutes($end);
        return $e > $s ? ($e - $s) : 0;
    }

    /** Convertit "HH:MM" en minutes depuis minuit. */
    private static function toMinutes(string $hhmm): int
    {
        $p = explode(':', $hhmm);
        return ((int) ($p[0] ?? 0)) * 60 + (int) ($p[1] ?? 0);
    }
}
