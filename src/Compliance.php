<?php

namespace GlpiPlugin\Gestiontemps;

use GlpiPlugin\Gestiontemps\Dashboard\ProductionCard;
use GlpiPlugin\Gestiontemps\Toolbox\Time;

/**
 * Détection des infractions au Code du travail (règles de base, France).
 *
 * Le contrôle est purement dérivé des écritures de temps déjà collectées : il
 * rejoue les segments d'une journée / d'une semaine contre les seuils légaux,
 * sans nouvelle table ni accumulation (même philosophie que le solde de la
 * tirelire, calculé en direct). Aucune règle conventionnelle : ce sont les
 * planchers du Code du travail, qu'une convention collective ne fait que
 * durcir.
 *
 * Les données d'entrée sont les lignes produites par
 * {@see ProductionCard::dailySummary()} : chaque jour porte ses `segments`
 * (`start` en secondes depuis minuit, `duration`, `source`) et sa `date`.
 */
class Compliance
{
    /** Travail effectif quotidien maximal — art. L3121-18 : 10 h. */
    private const MAX_DAILY_WORK = 10 * 3600;

    /** Amplitude quotidienne maximale (24 h − 11 h de repos) : 13 h. */
    private const MAX_AMPLITUDE = 13 * 3600;

    /** Repos quotidien minimal entre deux journées — art. L3131-1 : 11 h. */
    private const MIN_DAILY_REST = 11 * 3600;

    /** Travail continu au-delà duquel une pause est due — art. L3121-16 : 6 h. */
    private const CONTINUOUS_WORK_LIMIT = 6 * 3600;

    /** Durée minimale de la pause obligatoire — art. L3121-16 : 20 min. */
    private const MIN_PAUSE = 20 * 60;

    /** Durée hebdomadaire absolue maximale — art. L3121-20 : 48 h. */
    private const MAX_WEEKLY_WORK = 48 * 3600;

    /** Repos hebdomadaire minimal — art. L3132-2 : 35 h consécutives. */
    private const MIN_WEEKLY_REST = 35 * 3600;

    /**
     * Vrai si le contrôle est activé dans la configuration globale.
     */
    public static function isEnabled(): bool
    {
        return Config::isComplianceEnabled();
    }

    /**
     * Infractions d'une journée (règles quotidiennes + repos vis-à-vis de la
     * veille).
     *
     * @param array{date:string,covered:int,segments:array<int,array{start:int,duration:int,source:string}>} $day
     * @param array{date:string,segments:array<int,array{start:int,duration:int,source:string}>}|null        $prevDay
     *        Jour calendaire précédent (pour le repos quotidien), ou null.
     *
     * @return array<int,array{code:string,label:string,detail:string}>
     */
    public static function dayInfractions(array $day, ?array $prevDay = null): array
    {
        $out    = [];
        $worked = self::workedRanges($day['segments']);

        // Rien travaillé : aucune infraction quotidienne possible.
        if ($worked === []) {
            return $out;
        }

        // 1. Travail effectif quotidien > 10 h (art. L3121-18).
        $effective = 0;
        foreach ($worked as [$s, $e]) {
            $effective += $e - $s;
        }
        if ($effective > self::MAX_DAILY_WORK) {
            $out[] = [
                'code'   => 'daily_work',
                'label'  => __('Durée quotidienne > 10 h', 'gestiontemps'),
                'detail' => sprintf(
                    __('Travail effectif %1$s (maximum légal %2$s, art. L3121-18).', 'gestiontemps'),
                    Time::human($effective),
                    Time::human(self::MAX_DAILY_WORK)
                ),
            ];
        }

        // 2. Amplitude de la journée > 13 h (24 h − repos quotidien).
        $amplitude = self::amplitude($day['segments']);
        if ($amplitude > self::MAX_AMPLITUDE) {
            $out[] = [
                'code'   => 'amplitude',
                'label'  => __('Amplitude > 13 h', 'gestiontemps'),
                'detail' => sprintf(
                    __('Amplitude %1$s entre la première et la dernière saisie (maximum %2$s).', 'gestiontemps'),
                    Time::human($amplitude),
                    Time::human(self::MAX_AMPLITUDE)
                ),
            ];
        }

        // 3. Pause de 20 min due dès 6 h de travail continu (art. L3121-16).
        $longest = self::longestContinuousWork($worked);
        if ($longest > self::CONTINUOUS_WORK_LIMIT) {
            $out[] = [
                'code'   => 'break',
                'label'  => __('Pause de 20 min manquante', 'gestiontemps'),
                'detail' => sprintf(
                    __('%1$s de travail continu sans pause d\'au moins 20 min (art. L3121-16).', 'gestiontemps'),
                    Time::human($longest)
                ),
            ];
        }

        // 4. Repos quotidien < 11 h par rapport à la veille (art. L3131-1).
        if ($prevDay !== null && self::areConsecutive($prevDay['date'], $day['date'])) {
            $rest = self::restBetween($prevDay, $day);
            if ($rest !== null && $rest < self::MIN_DAILY_REST) {
                $out[] = [
                    'code'   => 'daily_rest',
                    'label'  => __('Repos quotidien < 11 h', 'gestiontemps'),
                    'detail' => sprintf(
                        __('Seulement %1$s de repos depuis la veille (minimum %2$s, art. L3131-1).', 'gestiontemps'),
                        Time::human($rest),
                        Time::human(self::MIN_DAILY_REST)
                    ),
                ];
            }
        }

        return $out;
    }

    /**
     * Infractions hebdomadaires (durée absolue et repos hebdomadaire), pour un
     * ensemble de jours appartenant à la même semaine ISO.
     *
     * @param array<int,array{date:string,covered:int,segments:array<int,array{start:int,duration:int,source:string}>}> $days
     *
     * @return array<int,array{code:string,label:string,detail:string}>
     */
    public static function weekInfractions(array $days): array
    {
        $out = [];

        // 1. Durée hebdomadaire absolue > 48 h (art. L3121-20).
        $total = 0;
        foreach ($days as $d) {
            $total += (int) $d['covered'];
        }
        if ($total > self::MAX_WEEKLY_WORK) {
            $out[] = [
                'code'   => 'weekly_work',
                'label'  => __('Durée hebdomadaire > 48 h', 'gestiontemps'),
                'detail' => sprintf(
                    __('Total travaillé %1$s (maximum absolu %2$s, art. L3121-20).', 'gestiontemps'),
                    Time::human($total),
                    Time::human(self::MAX_WEEKLY_WORK)
                ),
            ];
        }

        // 2. Repos hebdomadaire : au moins 35 h consécutives sans travail
        //    (art. L3132-2). On cherche le plus grand intervalle libre entre
        //    deux périodes de travail de la semaine.
        $gap = self::longestWeeklyRest($days);
        if ($gap !== null && $gap < self::MIN_WEEKLY_REST) {
            $out[] = [
                'code'   => 'weekly_rest',
                'label'  => __('Repos hebdomadaire < 35 h', 'gestiontemps'),
                'detail' => sprintf(
                    __('Plus long repos continu de la semaine : %1$s (minimum %2$s, art. L3132-2).', 'gestiontemps'),
                    Time::human($gap),
                    Time::human(self::MIN_WEEKLY_REST)
                ),
            ];
        }

        return $out;
    }

    /**
     * Rendu HTML d'une liste d'infractions : une pastille par infraction, le
     * détail en infobulle. Chaîne vide s'il n'y a aucune infraction.
     *
     * @param array<int,array{code:string,label:string,detail:string}> $infractions
     */
    public static function renderBadges(array $infractions): string
    {
        if ($infractions === []) {
            return '';
        }
        $html = '';
        foreach ($infractions as $inf) {
            $html .= "<span class='badge bg-red text-white gt-infraction' title='"
                . \Html::cleanInputText($inf['detail']) . "'>"
                . '&#9888; ' . \Html::cleanInputText($inf['label'])
                . "</span> ";
        }
        return trim($html);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * Plages de travail effectif d'une journée (coupures exclues), fusionnées
     * et triées. Chaque plage = [début, fin] en secondes depuis minuit.
     *
     * @param array<int,array{start:int,duration:int,source:string}> $segments
     * @return array<int,array{0:int,1:int}>
     */
    private static function workedRanges(array $segments): array
    {
        $ranges = [];
        foreach ($segments as $s) {
            if (($s['source'] ?? '') === TimeEntry::SOURCE_BREAK) {
                continue;
            }
            $start = max(0, (int) $s['start']);
            $end   = $start + max(0, (int) $s['duration']);
            if ($end > $start) {
                $ranges[] = [$start, $end];
            }
        }
        return self::merge($ranges);
    }

    /**
     * Amplitude couverte par tous les segments (coupures comprises : une
     * coupure reste incluse dans l'amplitude de la journée).
     *
     * @param array<int,array{start:int,duration:int}> $segments
     */
    private static function amplitude(array $segments): int
    {
        $min = null;
        $max = null;
        foreach ($segments as $s) {
            $start = max(0, (int) $s['start']);
            $end   = $start + max(0, (int) $s['duration']);
            if ($end <= $start) {
                continue;
            }
            $min = $min === null ? $start : min($min, $start);
            $max = $max === null ? $end : max($max, $end);
        }
        return ($min === null) ? 0 : $max - $min;
    }

    /**
     * Plus longue période de travail continu : des plages séparées par un trou
     * inférieur à 20 min (la pause légale) sont considérées comme continues.
     *
     * @param array<int,array{0:int,1:int}> $worked Plages fusionnées, triées.
     */
    private static function longestContinuousWork(array $worked): int
    {
        $longest    = 0;
        $blockStart = null;
        $blockEnd   = null;
        foreach ($worked as [$s, $e]) {
            if ($blockStart === null) {
                $blockStart = $s;
                $blockEnd   = $e;
                continue;
            }
            if ($s - $blockEnd < self::MIN_PAUSE) {
                // Trou trop court pour valoir pause : le travail se poursuit.
                $blockEnd = max($blockEnd, $e);
            } else {
                $longest    = max($longest, $blockEnd - $blockStart);
                $blockStart = $s;
                $blockEnd   = $e;
            }
        }
        if ($blockStart !== null) {
            $longest = max($longest, $blockEnd - $blockStart);
        }
        return $longest;
    }

    /**
     * Repos (en secondes) entre la fin de travail de la veille et le début de
     * travail du jour, ou null si l'un des deux jours n'a aucun travail.
     *
     * @param array{date:string,segments:array<int,array{start:int,duration:int,source:string}>} $prevDay
     * @param array{date:string,segments:array<int,array{start:int,duration:int,source:string}>} $day
     */
    private static function restBetween(array $prevDay, array $day): ?int
    {
        $prev = self::workedRanges($prevDay['segments']);
        $cur  = self::workedRanges($day['segments']);
        if ($prev === [] || $cur === []) {
            return null;
        }
        $prevLastEnd  = end($prev)[1];
        $curFirstStart = $cur[0][0];
        $prevMidnight = strtotime($prevDay['date'] . ' 00:00:00');
        $curMidnight  = strtotime($day['date'] . ' 00:00:00');
        return ($curMidnight + $curFirstStart) - ($prevMidnight + $prevLastEnd);
    }

    /**
     * Plus long intervalle libre (en secondes) sur la semaine, ou null si aucun
     * travail n'y est saisi.
     *
     * Le repos hebdomadaire tombe le plus souvent le week-end, où aucun segment
     * n'existe : on mesure donc les trous par rapport aux bornes de la semaine
     * ISO (lundi 00:00 → lundi suivant 00:00), pas seulement entre deux périodes
     * de travail. La queue (après le dernier travail) capture ainsi le repos du
     * week-end même si ces jours ne sont pas dans la période affichée.
     *
     * @param array<int,array{date:string,segments:array<int,array{start:int,duration:int,source:string}>}> $days
     */
    private static function longestWeeklyRest(array $days): ?int
    {
        $ranges = [];
        foreach ($days as $d) {
            $midnight = strtotime($d['date'] . ' 00:00:00');
            foreach (self::workedRanges($d['segments']) as [$s, $e]) {
                $ranges[] = [$midnight + $s, $midnight + $e];
            }
        }
        if ($ranges === []) {
            return null;
        }
        usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $ranges = self::merge($ranges);

        // Bornes de la semaine ISO déduites du premier jour fourni.
        $firstTs   = strtotime($days[0]['date'] . ' 00:00:00');
        $monday    = strtotime('-' . ((int) date('N', $firstTs) - 1) . ' days', $firstTs);
        $weekStart = strtotime(date('Y-m-d', $monday) . ' 00:00:00');
        $weekEnd   = strtotime(date('Y-m-d', strtotime('+7 days', $weekStart)) . ' 00:00:00');

        $longest = 0;
        $prev    = $weekStart;
        foreach ($ranges as [$s, $e]) {
            $longest = max($longest, $s - $prev);
            $prev    = max($prev, $e);
        }
        return max($longest, $weekEnd - $prev);
    }

    /**
     * Fusionne des plages [début, fin] qui se chevauchent ou se touchent.
     *
     * @param array<int,array{0:int,1:int}> $ranges
     * @return array<int,array{0:int,1:int}>
     */
    private static function merge(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }
        usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [$ranges[0]];
        foreach (array_slice($ranges, 1) as [$s, $e]) {
            $last = &$out[count($out) - 1];
            if ($s <= $last[1]) {
                $last[1] = max($last[1], $e);
            } else {
                $out[] = [$s, $e];
            }
            unset($last);
        }
        return $out;
    }

    /**
     * Vrai si $b est le lendemain calendaire de $a (dates 'Y-m-d').
     */
    private static function areConsecutive(string $a, string $b): bool
    {
        return strtotime($b . ' 00:00:00') - strtotime($a . ' 00:00:00') === 86400
            || date('Y-m-d', strtotime($a . ' +1 day')) === $b;
    }
}
