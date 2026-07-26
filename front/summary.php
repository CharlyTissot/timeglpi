<?php
/**
 * Synthèse jour par jour d'un utilisateur sur une période.
 *
 * - Tableau : ventilation production / hors-production / pause, avec
 *   sous-totaux hebdomadaires et mensuels lorsque la période les couvre.
 * - Frises horaires : le disque journalier « déroulé à plat », un par jour.
 */

use GlpiPlugin\Gestiontemps\Compliance;
use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\Dashboard\ProductionCard;
use GlpiPlugin\Gestiontemps\Menu;
use GlpiPlugin\Gestiontemps\TimeEntry;
use GlpiPlugin\Gestiontemps\Toolbox\Time;

include('../../../inc/includes.php');

Config::checkAccessOrDie();

Html::header(
    __('Synthèse', 'gestiontemps'),
    $_SERVER['PHP_SELF'],
    'tools',
    'GlpiPlugin\\Gestiontemps\\Menu',
    'summary'
);

Menu::showNav('summary');

$isRh = Config::currentUserIsRh();

// Utilisateur ciblé : filtre de la page, sinon contexte partagé, sinon soi-même.
if (isset($_GET['users_id'])) {
    $target = (int) $_GET['users_id'];
    Config::setViewedUser($target);
} else {
    $target = Config::effectiveUser();
}
if (!$isRh) {
    $target = (int) Session::getLoginUserID();
}

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
if (strtotime($from) > strtotime($to)) {
    [$from, $to] = [$to, $from];
}

// --- Barre de filtres --------------------------------------------------------
echo "<form method='get' action='" . $_SERVER['PHP_SELF'] . "' class='card mb-3'>";
echo "<div class='card-body row g-2 align-items-end'>";
echo "<div class='col-auto'><label>" . __('Du', 'gestiontemps') . "</label>";
Html::showDateField('from', ['value' => $from]);
echo "</div>";
echo "<div class='col-auto'><label>" . __('Au', 'gestiontemps') . "</label>";
Html::showDateField('to', ['value' => $to]);
echo "</div>";
if ($isRh) {
    echo "<div class='col-auto'><label>" . __('Utilisateur', 'gestiontemps') . "</label>";
    User::dropdown(['name' => 'users_id', 'value' => $target, 'right' => 'all']);
    echo "</div>";
}
echo "<div class='col-auto'>" . Html::submit(__('Filtrer', 'gestiontemps'), ['class' => 'btn btn-primary']) . "</div>";
echo "</div></form>";

$days = ProductionCard::dailySummary($target, $from, $to);

// Contrôle des infractions au Code du travail (si activé en configuration) :
// on annote chaque jour et on mémorise les infractions hebdomadaires par
// semaine ISO, calculées une fois pour toutes.
// Les règles sont individuelles (amplitude, repos, travail continu) : elles
// n'ont de sens que pour un utilisateur donné, pas sur l'agrégat « tous ».
$showCompliance = Compliance::isEnabled() && $target > 0;
$dayInfractions  = [];   // date => infractions
$weekInfractions = [];   // 'o-W' => infractions
if ($showCompliance) {
    $byWeek = [];
    $prev   = null;
    foreach ($days as $d) {
        $dayInfractions[$d['date']] = Compliance::dayInfractions($d, $prev);
        $byWeek[date('o-W', strtotime($d['date']))][] = $d;
        $prev = $d;
    }
    foreach ($byWeek as $key => $weekDays) {
        $weekInfractions[$key] = Compliance::weekInfractions($weekDays);
    }
}

// Une période « multi-semaines » / « multi-mois » déclenche les sous-totaux.
$weeks  = [];
$months = [];
foreach ($days as $d) {
    $weeks[date('o-W', strtotime($d['date']))]  = true;
    $months[date('Y-m', strtotime($d['date']))] = true;
}
$showWeekly  = count($weeks) > 1;
$showMonthly = count($months) > 1;

/** Cumul d'un ensemble de jours. */
$sum = static function (array $rows): array {
    $acc = ['production' => 0, 'nonprod' => 0, 'pause' => 0, 'breaktime' => 0,
            'worked' => 0, 'covered' => 0, 'expected' => 0, 'delta' => 0];
    foreach ($rows as $r) {
        foreach ($acc as $k => $v) {
            $acc[$k] = $v + $r[$k];
        }
    }
    return $acc;
};

/** Ligne de totaux (hebdo, mensuel ou général). */
$totalRow = static function (string $label, array $acc, string $class, string $extra = '') use ($showCompliance): void {
    $pct = Time::percent($acc['production'], $acc['worked']);
    echo "<tr class='{$class}'>";
    echo "<td><b>{$label}</b></td>";
    echo "<td class='text-end'><b>" . Time::human($acc['worked']) . "</b></td>";
    echo "<td class='text-end'>" . Time::human($acc['production']) . "</td>";
    echo "<td class='text-end'>" . Time::human($acc['nonprod']) . "</td>";
    echo "<td class='text-end'>" . Time::human($acc['pause']) . "</td>";
    echo "<td class='text-end text-muted'>" . Time::human($acc['breaktime']) . "</td>";
    echo "<td class='text-end'>" . $pct . " %</td>";
    echo "<td class='text-end'>" . Time::human($acc['expected']) . "</td>";
    $cls = $acc['delta'] >= 0 ? 'text-green' : 'text-red';
    echo "<td class='text-end {$cls}'>" . Time::human($acc['delta']) . "</td>";
    if ($showCompliance) {
        echo "<td>" . $extra . "</td>";
    }
    echo "</tr>";
};

// --- Tableau -----------------------------------------------------------------
echo "<div class='card'><div class='card-body'>";
echo "<h3>" . sprintf(
    __('Synthèse de %s', 'gestiontemps'),
    $target > 0 ? getUserName($target) : __('tous les utilisateurs', 'gestiontemps')
) . "</h3>";

echo "<table class='tab_cadre_fixehov'>";
echo "<tr class='tab_bg_2'>";
echo "<th>" . __('Jour', 'gestiontemps') . "</th>";
echo "<th class='text-end'>" . __('Temps de travail', 'gestiontemps') . "</th>";
echo "<th class='text-end'>" . __('Production', 'gestiontemps') . "</th>";
echo "<th class='text-end'>" . __('Hors production', 'gestiontemps') . "</th>";
echo "<th class='text-end' title='"
    . __('pause comptée dans le temps de travail', 'gestiontemps') . "'>"
    . __('Mise à disposition', 'gestiontemps') . "</th>";
echo "<th class='text-end' title='"
    . __('temps non travaillé, exclu du temps de travail', 'gestiontemps') . "'>"
    . __('Coupure', 'gestiontemps') . "</th>";
echo "<th class='text-end'>" . __('% prod.', 'gestiontemps') . "</th>";
echo "<th class='text-end'>" . __('Théorique', 'gestiontemps') . "</th>";
echo "<th class='text-end'>" . __('Écart', 'gestiontemps') . "</th>";
if ($showCompliance) {
    echo "<th>" . __('Conformité', 'gestiontemps') . "</th>";
}
echo "</tr>";

$weekBuffer  = [];
$monthBuffer = [];
$curWeek     = null;
$curMonth    = null;

$flushWeek = static function () use (&$weekBuffer, &$curWeek, $sum, $totalRow, $showWeekly, $showCompliance, $weekInfractions): void {
    if ($showWeekly && $weekBuffer !== []) {
        $extra = '';
        if ($showCompliance) {
            $key   = date('o-W', strtotime($weekBuffer[0]['date']));
            $extra = Compliance::renderBadges($weekInfractions[$key] ?? []);
        }
        $totalRow(sprintf(__('Total semaine %s', 'gestiontemps'), $curWeek), $sum($weekBuffer), 'tab_bg_1', $extra);
    }
    $weekBuffer = [];
};
$flushMonth = static function () use (&$monthBuffer, &$curMonth, $sum, $totalRow, $showMonthly): void {
    if ($showMonthly && $monthBuffer !== []) {
        $totalRow(sprintf(__('Total %s', 'gestiontemps'), $curMonth), $sum($monthBuffer), 'tab_bg_2');
    }
    $monthBuffer = [];
};

foreach ($days as $d) {
    $ts    = strtotime($d['date']);
    $week  = date('W', $ts);
    $month = date('m/Y', $ts);

    // Bascule de semaine / de mois : on vide les tampons AVANT d'écrire le jour.
    if ($curWeek !== null && $week !== $curWeek) {
        $flushWeek();
    }
    if ($curMonth !== null && $month !== $curMonth) {
        $flushWeek();
        $flushMonth();
    }
    $curWeek  = $week;
    $curMonth = $month;

    $weekBuffer[]  = $d;
    $monthBuffer[] = $d;

    // Jour sans aucune saisie : affiché en grisé pour rester lisible.
    $empty = $d['worked'] === 0;
    echo "<tr class='" . ($empty ? 'tab_bg_1 text-muted' : 'tab_bg_1') . "'>";
    echo "<td>" . Html::convDate($d['date']) . " <small class='text-muted'>"
        . ucfirst(strftime_compat($ts)) . "</small></td>";
    echo "<td class='text-end'><b>" . Time::human($d['worked']) . "</b>";
    if ($d['covered'] < $d['worked']) {
        // Des temps se chevauchent : on rappelle l'amplitude réelle.
        echo " <small class='text-muted' title='"
            . __('amplitude réelle, chevauchements fusionnés', 'gestiontemps') . "'>("
            . Time::human($d['covered']) . ")</small>";
    }
    echo "</td>";
    echo "<td class='text-end'>" . Time::human($d['production']) . "</td>";
    echo "<td class='text-end'>" . Time::human($d['nonprod']) . "</td>";
    echo "<td class='text-end'>" . Time::human($d['pause']) . "</td>";
    echo "<td class='text-end text-muted'>" . Time::human($d['breaktime']) . "</td>";
    echo "<td class='text-end'>" . Time::percent($d['production'], $d['worked']) . " %</td>";
    echo "<td class='text-end'>" . ($d['tracked'] ? Time::human($d['expected']) : '—') . "</td>";
    if (!$d['has_schedule'] || !$d['tracked']) {
        echo "<td class='text-end text-muted'>—</td>";
    } else {
        $cls = $d['delta'] >= 0 ? 'text-green' : 'text-red';
        echo "<td class='text-end {$cls}'>" . Time::human($d['delta']) . "</td>";
    }
    if ($showCompliance) {
        echo "<td>" . Compliance::renderBadges($dayInfractions[$d['date']] ?? []) . "</td>";
    }
    echo "</tr>";
}

$flushWeek();
$flushMonth();
// Sur une seule semaine ISO, aucune ligne « Total semaine » n'apparaît : on
// reporte alors les infractions hebdomadaires sur la ligne de total général.
$totalExtra = '';
if ($showCompliance && !$showWeekly && $weekInfractions !== []) {
    $totalExtra = Compliance::renderBadges(reset($weekInfractions));
}
$totalRow(__('TOTAL PÉRIODE', 'gestiontemps'), $sum($days), 'tab_bg_2', $totalExtra);

echo "</table>";

if ($days !== [] && !$days[0]['has_schedule']) {
    echo "<div class='alert alert-info mt-2'>"
        . __("Aucun horaire théorique n'est défini pour cet utilisateur : la colonne « Écart » ne peut pas être calculée.", 'gestiontemps')
        . "</div>";
}

echo "</div></div>";

// --- Frises horaires : le disque journalier déroulé à plat -------------------
echo "<div class='card mt-3'><div class='card-body'>";
echo "<h3>" . __('Activité jour par jour', 'gestiontemps') . "</h3>";
echo "<div class='gestiontemps-legend mb-2'>";
echo "<span class='legend-prod'></span> " . __('Production', 'gestiontemps') . " &nbsp; ";
echo "<span class='legend-manual'></span> " . __('Non-production', 'gestiontemps') . " &nbsp; ";
echo "<span class='legend-pause'></span> " . __('Mise à disposition', 'gestiontemps') . " &nbsp; ";
echo "<span class='legend-break'></span> " . __('Coupure (hors temps de travail)', 'gestiontemps');
echo "</div>";

$DAY = 86400;
$hasAny = false;
foreach ($days as $d) {
    if ($d['segments'] === []) {
        continue;
    }
    $hasAny = true;

    echo "<div class='gt-flat-day'>";
    echo "<div class='gt-flat-label'>" . Html::convDate($d['date'])
        . " <span class='text-muted'>" . Time::human($d['worked']) . "</span></div>";

    echo "<div class='gt-flat-track'>";
    // Graduations toutes les 2 h.
    for ($h = 0; $h <= 24; $h += 2) {
        $left = ($h / 24) * 100;
        echo "<span class='gt-flat-tick' style='left:{$left}%'></span>";
        echo "<span class='gt-flat-hour' style='left:{$left}%'>" . $h . "</span>";
    }
    foreach ($d['segments'] as $s) {
        $start = max(0, (int) $s['start']);
        $dur   = max(0, (int) $s['duration']);
        if ($dur <= 0) {
            continue;
        }
        $left  = ($start / $DAY) * 100;
        $width = min(100 - $left, ($dur / $DAY) * 100);
        $cls   = 'gt-flat-manual';
        if ($s['source'] === TimeEntry::SOURCE_BREAK) {
            $cls = 'gt-flat-break';
        } elseif ($s['source'] === TimeEntry::SOURCE_PAUSE) {
            $cls = 'gt-flat-pause';
        } elseif ($s['type'] === TimeEntry::TYPE_PRODUCTION) {
            $cls = 'gt-flat-prod';
        }
        $tip = sprintf(
            '%s → %s (%s) %s',
            date('H:i', strtotime($d['date'] . ' 00:00:00') + $start),
            date('H:i', strtotime($d['date'] . ' 00:00:00') + $start + $dur),
            Time::human($dur),
            $s['comment']
        );
        echo "<span class='gt-flat-seg {$cls}' style='left:{$left}%;width:{$width}%' title='"
            . Html::cleanInputText($tip) . "'></span>";
    }
    echo "</div>";
    echo "</div>";
}

if (!$hasAny) {
    echo "<div class='text-muted'>" . __('Aucune saisie sur la période.', 'gestiontemps') . "</div>";
}

echo "</div></div>";

Html::footer();

/**
 * Nom du jour de la semaine, sans dépendre de strftime() (retiré en PHP 8.1).
 */
function strftime_compat(int $ts): string
{
    $names = [
        1 => __('lundi', 'gestiontemps'),
        2 => __('mardi', 'gestiontemps'),
        3 => __('mercredi', 'gestiontemps'),
        4 => __('jeudi', 'gestiontemps'),
        5 => __('vendredi', 'gestiontemps'),
        6 => __('samedi', 'gestiontemps'),
        7 => __('dimanche', 'gestiontemps'),
    ];
    return $names[(int) date('N', $ts)] ?? '';
}
