<?php

namespace GlpiPlugin\Gestiontemps\Dashboard;

use GlpiPlugin\Gestiontemps\Leave;
use GlpiPlugin\Gestiontemps\Schedule;
use GlpiPlugin\Gestiontemps\TimeEntry;
use GlpiPlugin\Gestiontemps\Toolbox\Time;

/**
 * Fournit les données agrégées du tableau de bord « % production ».
 */
class ProductionCard
{
    /**
     * Calcule les indicateurs de production sur une période.
     *
     * @return array{
     *   production:int, manual:int, total:int,
     *   production_pct:float, manual_pct:float,
     *   production_human:string, manual_human:string, total_human:string
     * }
     */
    public static function compute(?int $users_id, string $from, string $to): array
    {
        $sum = TimeEntry::sumByType($users_id, $from, $to);

        $prodPct   = Time::percent($sum['production'], $sum['total']);
        $manualPct = $sum['total'] > 0 ? round(100 - $prodPct, 1) : 0.0;

        return [
            'production'       => $sum['production'],
            'manual'           => $sum['manual'],
            'total'            => $sum['total'],
            'production_pct'   => $prodPct,
            'manual_pct'       => $manualPct,
            'production_human' => Time::human($sum['production']),
            'manual_human'     => Time::human($sum['manual']),
            'total_human'      => Time::human($sum['total']),
        ];
    }

    /**
     * Assiduité jour par jour sur une période : temps travaillé, temps
     * théorique (horaire), et écart. Agrège les heures en plus (dépassements)
     * et les heures en retard (déficits).
     *
     * @return array{
     *   days: array<int,array{date:string,worked:int,expected:int,delta:int}>,
     *   over:int, late:int, net:int
     * }
     */
    public static function dailyAttendance(?int $users_id, string $from, string $to): array
    {
        global $DB;

        // Aucun temps n'est collecté avant le point de départ du compte : ces
        // journées apparaîtraient intégralement « en retard » alors qu'elles ne
        // sont tout simplement pas suivies. On borne donc la période.
        $start = $users_id !== null
            ? \GlpiPlugin\Gestiontemps\Account::startDateForUser($users_id)
            : \GlpiPlugin\Gestiontemps\Config::getBalanceStartDate();
        if ($start !== null && strtotime($start) > strtotime($from)) {
            $from = $start;
        }
        if (strtotime($from) > strtotime($to)) {
            return ['days' => [], 'over' => 0, 'late' => 0, 'net' => 0];
        }

        // Segments de travail par jour : 'Y-m-d' => [[startSec, endSec], ...]
        $byDay = [];
        $where  = ['date_start' => ['>=', $from . ' 00:00:00']];
        $where[] = ['date_start' => ['<=', $to . ' 23:59:59']];
        if ($users_id !== null) {
            $where['users_id'] = $users_id;
        }
        $iterator = $DB->request([
            'SELECT' => ['date_start', 'duration', 'source'],
            'FROM'   => TimeEntry::getTable(),
            'WHERE'  => $where,
        ]);
        foreach ($iterator as $row) {
            // Une coupure n'est pas du temps de travail : elle ne compte ni
            // dans l'assiduité ni dans les heures supplémentaires.
            if ((string) $row['source'] === TimeEntry::SOURCE_BREAK) {
                continue;
            }
            $ts = strtotime((string) $row['date_start']);
            $d  = date('Y-m-d', $ts);
            $s  = $ts - strtotime($d . ' 00:00:00');
            $byDay[$d][] = [$s, $s + (int) $row['duration']];
        }

        $days = [];
        $over = 0;
        $late = 0;
        $cursor = strtotime($from);
        $end    = strtotime($to);
        $guard  = 0;

        while ($cursor <= $end && $guard < 366) {
            $d = date('Y-m-d', $cursor);
            $day = self::computeDay($users_id, $d, $byDay[$d] ?? []);

            $over += $day['over'];
            $late += $day['late'];

            $days[] = [
                'date'     => $d,
                'worked'   => $day['worked'],
                'expected' => $day['expected'],
                'delta'    => $day['over'] - $day['late'],
            ];

            $cursor = strtotime('+1 day', $cursor);
            $guard++;
        }

        return [
            'days' => $days,
            'over' => $over,
            'late' => $late,
            'net'  => $over - $late,
        ];
    }

    /**
     * Synthèse jour par jour d'un utilisateur sur une période.
     *
     * Pour chaque jour : ventilation production / hors-production / pause,
     * total déclaré, amplitude réellement couverte (chevauchements fusionnés),
     * horaire théorique et écart, plus les segments pour la frise horaire.
     *
     * @return array<int,array{
     *   date:string, production:int, nonprod:int, pause:int, breaktime:int, worked:int,
     *   covered:int, expected:int, delta:int, has_schedule:bool,
     *   segments:array<int,array{start:int,duration:int,type:string,source:string,comment:string}>
     * }>
     */
    public static function dailySummary(?int $users_id, string $from, string $to): array
    {
        global $DB;

        $where   = [['date_start' => ['>=', $from . ' 00:00:00']], ['date_start' => ['<=', $to . ' 23:59:59']]];
        if ($users_id !== null) {
            $where['users_id'] = $users_id;
        }

        // Regroupement par jour en une seule requête.
        $byDay = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'date_start', 'duration', 'type', 'source', 'comment'],
            'FROM'   => TimeEntry::getTable(),
            'WHERE'  => $where,
            'ORDER'  => 'date_start ASC',
        ]);
        foreach ($iterator as $row) {
            $ts  = strtotime((string) $row['date_start']);
            $d   = date('Y-m-d', $ts);
            $byDay[$d][] = [
                'id'       => (int) $row['id'],
                'start'    => $ts - strtotime($d . ' 00:00:00'),
                'duration' => (int) $row['duration'],
                'type'     => (string) $row['type'],
                'source'   => (string) $row['source'],
                'comment'  => self::cleanText((string) $row['comment']),
            ];
        }

        // Jours antérieurs au point de départ du compte : aucun temps n'y est
        // collecté, l'écart au planning n'a donc pas de sens.
        $start = $users_id !== null
            ? \GlpiPlugin\Gestiontemps\Account::startDateForUser($users_id)
            : \GlpiPlugin\Gestiontemps\Config::getBalanceStartDate();

        $out    = [];
        $cursor = strtotime($from);
        $end    = strtotime($to);
        $guard  = 0;

        while ($cursor <= $end && $guard < 400) {
            $d    = date('Y-m-d', $cursor);
            $segs = $byDay[$d] ?? [];

            $production = 0;
            $nonprod    = 0;
            $pause      = 0;
            $breaktime  = 0;
            foreach ($segs as $s) {
                if ($s['source'] === TimeEntry::SOURCE_BREAK) {
                    $breaktime += $s['duration'];
                } elseif ($s['source'] === TimeEntry::SOURCE_PAUSE) {
                    $pause += $s['duration'];
                } elseif ($s['type'] === TimeEntry::TYPE_PRODUCTION) {
                    $production += $s['duration'];
                } else {
                    $nonprod += $s['duration'];
                }
            }

            // computeDay attend des couples [début, fin] en secondes ; les
            // coupures sont exclues du temps de travail.
            $worked_segs = array_filter($segs, static function (array $s): bool {
                return $s['source'] !== TimeEntry::SOURCE_BREAK;
            });
            $ranges = array_map(static function (array $s): array {
                return [$s['start'], $s['start'] + $s['duration']];
            }, $worked_segs);
            $day     = self::computeDay($users_id, $d, $ranges);
            $tracked = $start === null || strtotime($d) >= strtotime($start);

            $out[] = [
                'date'         => $d,
                'production'   => $production,
                'nonprod'      => $nonprod,
                'pause'        => $pause,
                'breaktime'    => $breaktime,
                'worked'       => $production + $nonprod + $pause,
                'covered'      => self::coveredDuration($worked_segs),
                // Hors période suivie : ni attendu ni écart, sinon les totaux
                // hebdo/mensuels accumuleraient un retard fictif.
                'expected'     => $tracked ? $day['expected'] : 0,
                'delta'        => $tracked ? $day['over'] - $day['late'] : 0,
                'has_schedule' => $users_id === null || Schedule::hasAnySchedule($users_id),
                'tracked'      => $tracked,
                'segments'     => $segs,
            ];

            $cursor = strtotime('+1 day', $cursor);
            $guard++;
        }

        return $out;
    }

    /**
     * Calcule, pour un jour, le temps travaillé, l'attendu net (horaire théorique
     * − congés) et les heures en plus / en retard (en secondes).
     *
     * Règle « net par jour » : heures en plus uniquement si le total travaillé
     * dépasse l'attendu net du jour ; sinon le déficit compte en retard.
     *
     * @param array<int,array{0:int,1:int}> $segs
     * @return array{worked:int, expected:int, over:int, late:int}
     */
    public static function computeDay(?int $users_id, string $date, array $segs): array
    {
        $worked = 0;
        foreach ($segs as $s) {
            $worked += max(0, $s[1] - $s[0]);
        }

        if ($users_id === null) {
            $exp = Schedule::expectedMinutesForDateAllUsers($date) * 60;
        } elseif (!Schedule::hasAnySchedule($users_id)) {
            // Aucun horaire théorique défini : l'attendu est inconnu, pas nul.
            // On n'affiche ni heures en plus ni heures en retard, sinon tout le
            // temps saisi apparaîtrait comme des heures supplémentaires.
            return [
                'worked'   => $worked,
                'expected' => 0,
                'over'     => 0,
                'late'     => 0,
            ];
        } else {
            // Attendu net = horaire théorique du jour − congés (journée/demi-journée).
            $expMin = Schedule::expectedMinutesForDate($users_id, $date)
                - Leave::coveredScheduledMinutes($users_id, $date);
            $exp = max(0, $expMin) * 60;
        }

        $delta = $worked - $exp;
        return [
            'worked'   => $worked,
            'expected' => $exp,
            'over'     => max(0, $delta),
            'late'     => max(0, -$delta),
        ];
    }

    /**
     * Segments de travail du JOUR MÊME (minuit → minuit), pour le disque
     * journalier type tachygraphe.
     *
     * Conservé pour compatibilité : délègue à dayTimeline() sur la date du jour.
     *
     * @return array{
     *   segments: array<int,array{start:int,duration:int,type:string}>,
     *   total:int
     * }
     */
    public static function todayTimeline(?int $users_id): array
    {
        return self::dayTimeline($users_id, date('Y-m-d'));
    }

    /**
     * Segments de travail d'un JOUR donné (minuit → minuit), pour le disque
     * journalier type tachygraphe.
     *
     * @param string $date Jour au format 'Y-m-d'.
     * @return array{
     *   segments: array<int,array{start:int,duration:int,type:string}>,
     *   total:int
     * }
     *   start = secondes écoulées depuis minuit ; duration = secondes.
     */
    public static function dayTimeline(?int $users_id, string $date): array
    {
        global $DB;

        $midnight = strtotime($date . ' 00:00:00');

        $where = ['date_start' => ['>=', $date . ' 00:00:00']];
        $where[] = ['date_start' => ['<=', $date . ' 23:59:59']];
        if ($users_id !== null) {
            $where['users_id'] = $users_id;
        }

        $segments = [];
        $total    = 0;
        $iterator = $DB->request([
            'SELECT' => ['id', 'date_start', 'duration', 'type', 'source', 'comment'],
            'FROM'   => TimeEntry::getTable(),
            'WHERE'  => $where,
            'ORDER'  => 'date_start ASC',
        ]);
        foreach ($iterator as $row) {
            $start = strtotime((string) $row['date_start']) - $midnight;
            if ($start < 0) {
                $start = 0;
            }
            $dur = (int) $row['duration'];
            $segments[] = [
                'id'       => (int) $row['id'],
                'start'    => $start,
                'duration' => $dur,
                'type'     => $row['type'],
                'source'   => (string) $row['source'],
                'comment'  => self::cleanText((string) $row['comment']),
            ];
            if ((string) $row['source'] !== TimeEntry::SOURCE_BREAK) {
                $total += $dur;
            }
        }

        return [
            'segments' => $segments,
            'total'    => $total,
            'covered'  => self::coveredDuration($segments),
        ];
    }

    /**
     * Amplitude réellement couverte par des segments, chevauchements fusionnés.
     *
     * Le total simple additionne les durées déclarées : deux temps saisis sur
     * le même créneau y comptent double. Cette valeur donne l'occupation réelle
     * de la journée, ce que dessine le disque.
     *
     * @param array<int,array{start:int,duration:int}> $segments
     */
    public static function coveredDuration(array $segments): int
    {
        $ranges = [];
        foreach ($segments as $s) {
            $start = max(0, (int) $s['start']);
            $end   = $start + max(0, (int) $s['duration']);
            if ($end > $start) {
                $ranges[] = [$start, $end];
            }
        }
        if (empty($ranges)) {
            return 0;
        }

        usort($ranges, static function (array $a, array $b): int {
            return $a[0] <=> $b[0];
        });

        $covered = 0;
        [$curStart, $curEnd] = $ranges[0];
        foreach (array_slice($ranges, 1) as [$s, $e]) {
            if ($s > $curEnd) {
                $covered += $curEnd - $curStart;
                $curStart = $s;
                $curEnd   = $e;
            } elseif ($e > $curEnd) {
                $curEnd = $e;
            }
        }
        return $covered + ($curEnd - $curStart);
    }

    /**
     * Décode le texte riche GLPI (entités HTML) et retire les balises pour
     * un affichage en texte brut lisible.
     */
    private static function cleanText(string $raw): string
    {
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);
        return trim(strip_tags($raw));
    }
}
