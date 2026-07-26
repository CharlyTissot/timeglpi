<?php
/**
 * Page de saisie des horaires de travail (semaine normale + semaine A/B).
 *
 * - Un utilisateur non-RH saisit ses propres horaires.
 * - Un membre RH peut choisir l'utilisateur à configurer.
 */

use GlpiPlugin\Gestiontemps\Account;
use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\Menu;
use GlpiPlugin\Gestiontemps\Schedule;
use GlpiPlugin\Gestiontemps\Toolbox\Compat;

include('../../../inc/includes.php');

Config::checkAccessOrDie();

Html::header(
    Schedule::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'tools',
    'GlpiPlugin\\Gestiontemps\\Menu',
    'schedule'
);

Menu::showNav('schedule');

$isRh = Config::currentUserIsRh();

// Utilisateur ciblé : le filtre de la page s'il est posé, sinon l'utilisateur
// consulté depuis le tableau de bord, sinon soi-même.
if (isset($_GET['users_id'])) {
    $target = (int) $_GET['users_id'];
    Config::setViewedUser($target);
} else {
    $target = Config::effectiveUser();
}
if (!$isRh) {
    // Un non-RH ne configure que ses propres horaires.
    $target = (int) Session::getLoginUserID();
}

if ($isRh) {
    echo "<form method='get' action='" . $_SERVER['PHP_SELF'] . "' class='mb-3'>";
    echo "<label>" . __('Utilisateur', 'gestiontemps') . " </label> ";
    User::dropdown([
        'name'      => 'users_id',
        'value'     => $target,
        'right'     => 'all',
        'on_change' => 'this.form.submit()',
    ]);
    Html::closeForm();
}

echo "<div class='alert alert-info'>"
    . __('Renseignez les minutes travaillées par jour. Utilisez « Semaine normale » si vos horaires sont identiques chaque semaine, ou « Semaine A » / « Semaine B » en cas d\'alternance.', 'gestiontemps')
    . "</div>";

$schedule = new Schedule();
$schedule->showForUser($target);

// --- Recalcul après changement d'horaire ou de contrat (RH) ------------------
if ($isRh) {
    $start = Account::startDateForUser($target);

    echo "<div class='card mt-3'><div class='card-body'>";
    echo "<h3>" . __('Recalcul du compte de temps', 'gestiontemps') . "</h3>";
    echo "<p class='text-muted'>"
        . __("Le solde est recalculé en permanence à partir des horaires en vigueur : modifier un horaire ou un contrat réécrit donc tout l'historique. Ce recalcul fige le solde acquis la veille de la date choisie ; à partir de cette date, le calcul repart avec les nouveaux horaires.", 'gestiontemps')
        . "</p>";
    $startInfo = Account::startDateInfoForUser($target);
    if ($start !== null) {
        $originLabels = [
            'user'        => __('propre à cet utilisateur', 'gestiontemps'),
            'config'      => __('configuration générale', 'gestiontemps'),
            'first_entry' => __('première saisie', 'gestiontemps'),
        ];
        echo "<p>" . sprintf(
            __('Point de départ actuel : %1$s (%2$s).', 'gestiontemps'),
            '<b>' . Html::convDate($start) . '</b>',
            $originLabels[$startInfo['origin']] ?? $startInfo['origin']
        ) . "</p>";
    }

    echo "<form method='post' action='" . Compat::webPath() . "/front/schedule.form.php'>";
    echo Html::hidden('users_id', ['value' => $target]);
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo "<label>" . __('Recalculer à partir du', 'gestiontemps') . "</label> ";
    Html::showDateField('recompute_from', ['value' => date('Y-m-d')]);
    echo " <button type='submit' name='recompute' value='1' class='btn btn-outline-primary'>"
        . __('Recalculer', 'gestiontemps') . "</button>";
    echo "<label class='d-block mt-2'>"
        . "<input type='checkbox' name='reset_balance' value='1'> "
        . __('Repartir de zéro (solde figé à 0 au lieu d\'être calculé)', 'gestiontemps')
        . "</label>";
    echo "<div class='text-muted'><small>"
        . __("À cocher lors d'une mise en service : sans historique avant cette date, les journées vides seraient comptées comme des heures en retard.", 'gestiontemps')
        . "</small></div>";
    Html::closeForm();

    // Retour au réglage global : utile quand un recalcul a posé une date
    // individuelle qui ne devrait plus s'appliquer.
    if ($startInfo['origin'] === 'user') {
        echo "<form method='post' action='" . Compat::webPath() . "/front/schedule.form.php' class='mt-2'>";
        echo Html::hidden('users_id', ['value' => $target]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo "<button type='submit' name='clear_start' value='1' class='btn btn-sm btn-outline-secondary'>"
            . __('Revenir à la date de la configuration générale', 'gestiontemps')
            . "</button>";
        Html::closeForm();
    }

    echo "</div></div>";
}

Html::footer();
