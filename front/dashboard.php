<?php
/**
 * Tableau de bord : % production et disque (donut) production / non-production.
 */

use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\Dashboard\ProductionCard;
use GlpiPlugin\Gestiontemps\Menu;
use GlpiPlugin\Gestiontemps\Schedule;
use GlpiPlugin\Gestiontemps\Toolbox\Compat;
use GlpiPlugin\Gestiontemps\Toolbox\Time;

include('../../../inc/includes.php');

Config::checkAccessOrDie();

Html::header(
    __('Tableau de bord — Gestion du temps', 'gestiontemps'),
    $_SERVER['PHP_SELF'],
    'tools',
    'GlpiPlugin\\Gestiontemps\\Menu',
    'dashboard'
);

Menu::showNav('dashboard');

// Filtres : période + utilisateur (RH / droit étendu peut cibler un autre user).
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

// Seuls les profils RH voient/sélectionnent les autres utilisateurs ;
// les autres ne voient que leurs propres données (utilisateur courant).
$canSeeAll = Config::currentUserIsRh();

$users_id = null;
if (isset($_GET['users_id']) && $_GET['users_id'] !== '') {
    $users_id = (int) $_GET['users_id'];
}
if (!$canSeeAll) {
    // Sans droit étendu, l'utilisateur ne voit que ses propres données.
    $users_id = (int) Session::getLoginUserID();
} elseif (isset($_GET['users_id'])) {
    // Le RH vient de choisir un utilisateur (ou de vider le filtre) : ce choix
    // devient le contexte de toutes les pages du plugin.
    Config::setViewedUser($users_id);
} else {
    // Arrivée sur la page sans filtre explicite : on reprend le contexte courant.
    $users_id = Config::getViewedUser();
}

// Jour affiché par le disque journalier (navigation jour par jour, indépendante
// de la période from/to). Défaut : aujourd'hui.
$day = $_GET['day'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $day) || strtotime((string) $day) === false) {
    $day = date('Y-m-d');
}

$data     = ProductionCard::compute($users_id, $from, $to);
$att      = ProductionCard::dailyAttendance($users_id, $from, $to);
$timeline = ProductionCard::dayTimeline($users_id, $day); // jour sélectionné

// --- Barre de filtres --------------------------------------------------------
echo "<form method='get' action='" . $_SERVER['PHP_SELF'] . "' class='card mb-3'><div class='card-body row g-2 align-items-end'>";
echo "<div class='col-auto'><label>" . __('Du', 'gestiontemps') . "</label>";
Html::showDateField('from', ['value' => $from]);
echo "</div>";
echo "<div class='col-auto'><label>" . __('Au', 'gestiontemps') . "</label>";
Html::showDateField('to', ['value' => $to]);
echo "</div>";
if ($canSeeAll) {
    echo "<div class='col-auto'><label>" . __('Utilisateur (vide = tous)', 'gestiontemps') . "</label>";
    User::dropdown([
        'name'  => 'users_id',
        'value' => $users_id ?? 0,
        'right' => 'all',
        'display_emptychoice' => true,
    ]);
    echo "</div>";
}
echo "<div class='col-auto'>";
echo Html::submit(__('Filtrer', 'gestiontemps'), ['class' => 'btn btn-primary']);
echo "</div>";

// Bouton chrono start/stop (piloté en JS).
echo "<div class='col-auto'>";
echo "<button type='button' id='gt-timer-btn' class='btn btn-success'>"
    . "<i class='ti ti-player-play'></i> <span class='gt-timer-label'>"
    . __('Démarrer le chrono', 'gestiontemps') . "</span></button>";
echo "</div>";

echo "</div></form>";

// --- Popup de fin de chrono (commentaire obligatoire) ------------------------
echo "<div id='gt-timer-modal' class='gt-timer-modal' style='display:none'>";
echo "<div class='gt-timer-modal-box card'>";
echo "<div class='card-body'>";
echo "<h3>" . __('Fin du chrono', 'gestiontemps') . "</h3>";
echo "<p>" . __('Durée mesurée :', 'gestiontemps')
    . " <b id='gt-timer-elapsed'>0:00:00</b></p>";
echo "<form id='gt-timer-form' method='post' action='" . Compat::webPath() . "/front/timer.php'>";
echo Html::hidden('stop_timer', ['value' => 1]);
echo Html::hidden('duration', ['id' => 'gt-timer-duration', 'value' => 0]);
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo "<label class='mt-2 d-block'>" . __('Nature', 'gestiontemps') . "</label>";
echo "<select name='nature' id='gt-timer-nature' class='form-select'>";
echo "<option value=''>" . __('Travail (non-production)', 'gestiontemps') . "</option>";
echo "<option value='pause'>" . __('Mise à disposition (comptée dans le temps de travail)', 'gestiontemps') . "</option>";
echo "<option value='break'>" . __('Coupure (hors temps de travail)', 'gestiontemps') . "</option>";
echo "</select>";
echo "<label>" . __('Commentaire de la tâche', 'gestiontemps') . "</label>";
echo "<textarea name='comment' id='gt-timer-comment' class='form-control' rows='3' required></textarea>";
echo "<div class='mt-2 text-end'>";
echo "<button type='button' id='gt-timer-cancel' class='btn btn-outline-secondary'>"
    . _x('button', 'Cancel') . "</button> ";
echo "<button type='submit' class='btn btn-primary'>"
    . __('Enregistrer', 'gestiontemps') . "</button>";
echo "</div>";
echo "</form>";
echo "</div></div></div>";

// --- Popup info d'un temps (clic sur un arc du disque) -----------------------
echo "<div id='gt-clock-info' class='gt-timer-modal' style='display:none'>";
echo "<div class='gt-timer-modal-box card'><div class='card-body'>";
echo "<h3>" . __('Détail du temps', 'gestiontemps') . "</h3>";
echo "<div id='gt-clock-info-body'></div>";
echo "<div class='mt-2 text-end'>";
echo "<button type='button' id='gt-clock-info-close' class='btn btn-primary'>"
    . __('Fermer', 'gestiontemps') . "</button>";
echo "</div>";
echo "</div></div></div>";

// --- Popup création sur une zone libre du disque -----------------------------
echo "<div id='gt-clock-create' class='gt-timer-modal' style='display:none'>";
echo "<div class='gt-timer-modal-box card'><div class='card-body'>";
echo "<h3>" . __('Ajouter un temps sur cette plage', 'gestiontemps') . "</h3>";
echo "<form method='post' action='" . Compat::webPath() . "/front/timer.php'>";
echo Html::hidden('quick_add', ['value' => 1]);
// Jour visualisé : le temps ajouté est daté sur ce jour, pas forcément aujourd'hui.
echo Html::hidden('day', ['value' => $day]);
echo Html::hidden('from', ['value' => $from]);
echo Html::hidden('to', ['value' => $to]);
if ($canSeeAll && $users_id !== null) {
    echo Html::hidden('users_id', ['value' => $users_id]);
}
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo "<div class='row'>";
echo "<div class='col'><label>" . __('Heure de début', 'gestiontemps') . "</label>";
echo "<input type='time' name='start_time' id='gt-create-start' class='form-control' required></div>";
echo "<div class='col'><label>" . __('Heure de fin', 'gestiontemps') . "</label>";
echo "<input type='time' name='end_time' id='gt-create-end' class='form-control' required></div>";
echo "</div>";
echo "<label class='mt-2 d-block'>" . __('Nature', 'gestiontemps') . "</label>";
echo "<select name='nature' id='gt-create-nature' class='form-select'>";
echo "<option value=''>" . __('Travail (non-production)', 'gestiontemps') . "</option>";
echo "<option value='pause'>" . __('Mise à disposition (comptée dans le temps de travail)', 'gestiontemps') . "</option>";
echo "<option value='break'>" . __('Coupure (hors temps de travail)', 'gestiontemps') . "</option>";
echo "</select>";
echo "<label class='mt-2'>" . __('Commentaire', 'gestiontemps') . "</label>";
echo "<textarea name='comment' id='gt-create-comment' class='form-control' rows='2' required></textarea>";
echo "<div class='mt-2 text-end'>";
echo "<button type='button' id='gt-clock-create-cancel' class='btn btn-outline-secondary'>"
    . _x('button', 'Cancel') . "</button> ";
echo "<button type='submit' class='btn btn-primary'>" . _x('button', 'Add') . "</button>";
echo "</div>";
echo "</form>";
echo "</div></div></div>";

// --- Disque + indicateurs ----------------------------------------------------
$payload = htmlspecialchars(json_encode([
    'production'     => $data['production'],
    'manual'         => $data['manual'],
    'production_pct' => $data['production_pct'],
    'manual_pct'     => $data['manual_pct'],
]), ENT_QUOTES);

// Payload du disque journalier (jour même) + repères horaires théoriques.
$clockPayload = htmlspecialchars(json_encode([
    'segments' => $timeline['segments'],
    'total'    => $timeline['total'],
]), ENT_QUOTES);

$clockSched = ($users_id !== null)
    ? Schedule::clockPeriodsForDate($users_id, $day)
    : [];
$schedPayload = htmlspecialchars(json_encode($clockSched), ENT_QUOTES);

// Contexte du rafraîchissement automatique (lu par le JS).
$liveUrl = Compat::webPath() . '/ajax/dashboard.php';
$liveParams = htmlspecialchars(json_encode([
    'url'      => $liveUrl,
    'from'     => $from,
    'to'       => $to,
    'day'      => $day,
    'users_id' => $users_id ?? '',
    // Le disque journalier ne bouge en direct que si l'on regarde aujourd'hui.
    'is_today' => ($day === date('Y-m-d')),
]), ENT_QUOTES);
echo "<div id='gt-live' data-live='{$liveParams}' class='text-muted mb-2'>";
echo "<small><i class='ti ti-refresh'></i> "
    . sprintf(
        __('Mise à jour automatique — dernière synchronisation à %s', 'gestiontemps'),
        "<span id='gt-live-stamp'>" . date('H:i:s') . "</span>"
    )
    . "</small></div>";

echo "<div class='row'>";

// Colonne disque de production (sur la période).
echo "<div class='col-md-4'>";
echo "<div class='card'><div class='card-body text-center'>";
echo "<h3>" . __('Disque de production', 'gestiontemps') . "</h3>";
echo "<div id='gestiontemps-donut' class='gestiontemps-donut' data-values='{$payload}'></div>";
echo "<div class='gestiontemps-legend mt-2'>";
echo "<span class='legend-prod'></span> " . __('Production', 'gestiontemps') . " &nbsp; ";
echo "<span class='legend-manual'></span> " . __('Non-production', 'gestiontemps');
echo "</div>";
echo "</div></div>";
echo "</div>";

// Colonne disque journalier (tachygraphe 24 h, jour sélectionné).
echo "<div class='col-md-4'>";
echo "<div class='card'><div class='card-body text-center'>";
echo "<h3>" . sprintf(__('Journée du %s', 'gestiontemps'), date('d/m/Y', strtotime($day))) . "</h3>";

// --- Navigation jour par jour (préserve utilisateur + période) ---------------
$navParams = ['from' => $from, 'to' => $to];
if ($canSeeAll && $users_id !== null) {
    $navParams['users_id'] = $users_id;
}
$dayUrl = static function (string $d) use ($navParams): string {
    $p = $navParams;
    $p['day'] = $d;
    return htmlspecialchars($_SERVER['PHP_SELF'] . '?' . http_build_query($p));
};
$prevDay = date('Y-m-d', strtotime($day . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($day . ' +1 day'));
$isToday = ($day === date('Y-m-d'));

echo "<div class='d-flex flex-wrap justify-content-center align-items-center gap-2 mb-2'>";
echo "<div class='btn-group btn-group-sm' role='group'>";
echo "<a class='btn btn-outline-secondary' href='" . $dayUrl($prevDay) . "' title='"
    . __('Jour précédent', 'gestiontemps') . "'><i class='ti ti-chevron-left'></i></a>";
if (!$isToday) {
    echo "<a class='btn btn-outline-secondary' href='" . $dayUrl($nextDay) . "' title='"
        . __('Jour suivant', 'gestiontemps') . "'><i class='ti ti-chevron-right'></i></a>";
}
echo "</div>";
// Sélecteur de date directe.
echo "<form method='get' action='" . $_SERVER['PHP_SELF'] . "' class='d-inline-flex align-items-center gap-1 mb-0'>";
foreach ($navParams as $k => $v) {
    echo Html::hidden($k, ['value' => $v]);
}
Html::showDateField('day', ['value' => $day]);
echo "<button type='submit' class='btn btn-sm btn-secondary'>" . __('Voir', 'gestiontemps') . "</button>";
echo "</form>";
if (!$isToday) {
    echo "<a class='btn btn-sm btn-outline-primary' href='" . $dayUrl(date('Y-m-d')) . "'>"
        . __("Aujourd'hui", 'gestiontemps') . "</a>";
}
echo "</div>";

echo "<div id='gestiontemps-clock' class='gestiontemps-clock' data-segments='{$clockPayload}' data-schedule='{$schedPayload}'></div>";
echo "<div class='gestiontemps-legend mt-2'>";
echo "<span class='legend-prod'></span> " . __('Production', 'gestiontemps') . " &nbsp; ";
echo "<span class='legend-manual'></span> " . __('Non-production', 'gestiontemps') . " &nbsp; ";
echo "<span class='legend-pause'></span> " . __('Mise à disposition', 'gestiontemps') . " &nbsp; ";
echo "<span class='legend-break'></span> " . __('Coupure', 'gestiontemps') . " &nbsp; ";
echo "<span class='legend-sched'></span> " . __('Horaire théorique', 'gestiontemps');
echo "</div>";
// Quand des temps se chevauchent, le total déclaré dépasse l'amplitude
// réellement occupée : on affiche les deux pour éviter toute confusion.
if ($timeline['covered'] < $timeline['total']) {
    echo "<div class='text-muted mt-1'><small>"
        . sprintf(
            __('%1$s déclarées sur %2$s d\'amplitude : des temps se superposent (couches successives sur le disque).', 'gestiontemps'),
            Time::human($timeline['total']),
            Time::human($timeline['covered'])
        )
        . "</small></div>";
}

echo "<div class='text-muted mt-1'><small>"
    . __('Cliquez un arc pour le détail, une zone libre pour ajouter un temps.', 'gestiontemps')
    . "</small></div>";
echo "</div></div>";
echo "</div>";

// Colonne indicateurs.
echo "<div class='col-md-4'>";
echo "<div class='card'><div class='card-body'>";
echo "<h3>" . __('Indicateurs', 'gestiontemps') . "</h3>";
echo "<table class='tab_cadre_fixe'>";
echo "<tr class='tab_bg_1'><td>" . __('% Production', 'gestiontemps') . "</td>"
    . "<td><b style='font-size:1.6em' id='gt-ind-pct'>" . $data['production_pct'] . " %</b></td></tr>";
echo "<tr class='tab_bg_1'><td>" . __('Temps production', 'gestiontemps') . "</td>"
    . "<td id='gt-ind-prod'>" . $data['production_human'] . "</td></tr>";
echo "<tr class='tab_bg_1'><td>" . __('Temps non-production', 'gestiontemps') . "</td>"
    . "<td id='gt-ind-manual'>" . $data['manual_human'] . "</td></tr>";
echo "<tr class='tab_bg_2'><td><b>" . __('Total', 'gestiontemps') . "</b></td>"
    . "<td><b id='gt-ind-total'>" . $data['total_human'] . "</b></td></tr>";
echo "</table>";
echo "</div></div>";
echo "</div>";

echo "</div>"; // row

// --- Assiduité : heures en plus / en retard + graphique jour par jour --------
$attPayload = htmlspecialchars(json_encode(array_map(static function ($d) {
    return [
        'date'     => $d['date'],
        'worked'   => (int) $d['worked'],
        'expected' => (int) $d['expected'],
    ];
}, $att['days'])), ENT_QUOTES);

echo "<div class='card mt-3'><div class='card-body'>";
echo "<h3>" . __('Assiduité — jour par jour', 'gestiontemps') . "</h3>";

// Le décompte ne commence qu'au point de départ du compte de temps : les
// journées antérieures ne sont pas suivies et ne comptent pas en retard.
$attStart = $users_id !== null
    ? \GlpiPlugin\Gestiontemps\Account::startDateForUser($users_id)
    : Config::getBalanceStartDate();
if ($attStart !== null && strtotime($attStart) > strtotime($from)) {
    echo "<div class='text-muted mb-2'><small>"
        . sprintf(
            __('Décompte à partir du %s (point de départ du compte de temps) : les journées antérieures ne sont pas suivies.', 'gestiontemps'),
            Html::convDate($attStart)
        )
        . "</small></div>";
}

// Sans horaire théorique, l'écart au planning n'a pas de sens : on le signale
// plutôt que d'afficher un solde trompeur.
if ($users_id !== null && !Schedule::hasAnySchedule($users_id)) {
    echo "<div class='alert alert-info'>"
        . __("Aucun horaire théorique n'est défini pour cet utilisateur : les heures en plus et en retard ne peuvent pas être calculées.", 'gestiontemps')
        . "</div>";
}

// Tuiles d'indicateurs.
echo "<div class='row text-center mb-2'>";
echo "<div class='col'><div class='card'><div class='card-body'>";
echo "<div class='text-muted'>" . __('Heures en plus', 'gestiontemps') . "</div>";
echo "<div style='font-size:1.6em' class='text-green'><b id='gt-att-over'>" . Time::human($att['over']) . "</b></div>";
echo "</div></div></div>";
echo "<div class='col'><div class='card'><div class='card-body'>";
echo "<div class='text-muted'>" . __('Heures en retard', 'gestiontemps') . "</div>";
echo "<div style='font-size:1.6em' class='text-red'><b id='gt-att-late'>" . Time::human($att['late']) . "</b></div>";
echo "</div></div></div>";
echo "<div class='col'><div class='card'><div class='card-body'>";
echo "<div class='text-muted'>" . __('Solde sur la période', 'gestiontemps') . "</div>";
$soldeCls = $att['net'] >= 0 ? 'text-green' : 'text-red';
echo "<div style='font-size:1.6em' class='{$soldeCls}' id='gt-att-net-wrap'><b id='gt-att-net'>" . Time::human($att['net']) . "</b></div>";
echo "</div></div></div>";
echo "</div>";

// Graphique à barres jour par jour (travaillé vs théorique).
echo "<div id='gestiontemps-bars' class='gestiontemps-bars' data-days='{$attPayload}'></div>";
echo "<div class='gestiontemps-legend mt-2'>";
echo "<span class='legend-worked'></span> " . __('Travaillé', 'gestiontemps') . " &nbsp; ";
echo "<span class='legend-expected'></span> " . __('Théorique', 'gestiontemps');
echo "</div>";

echo "</div></div>";

// Initialisation des graphiques (le JS est chargé globalement via add_javascript).
echo Html::scriptBlock("
    (function() {
        function initGt() {
            if (window.gestiontempsDrawDonut) {
                window.gestiontempsDrawDonut(document.getElementById('gestiontemps-donut'));
            }
            if (window.gestiontempsDrawClock) {
                window.gestiontempsDrawClock(document.getElementById('gestiontemps-clock'));
            }
            if (window.gestiontempsDrawBars) {
                window.gestiontempsDrawBars(document.getElementById('gestiontemps-bars'));
            }
        }
        if (document.readyState !== 'loading') {
            initGt();
        } else {
            document.addEventListener('DOMContentLoaded', initGt);
        }
    })();
");

Html::footer();
