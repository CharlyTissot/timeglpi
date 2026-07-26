<?php
/**
 * Vue tirelire.
 *
 * - Un utilisateur non-RH voit sa propre tirelire.
 * - Un membre RH peut choisir un utilisateur dans la liste.
 */

use GlpiPlugin\Gestiontemps\Account;
use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\Menu;
use GlpiPlugin\Gestiontemps\Profile;

include('../../../inc/includes.php');

Config::checkAccessOrDie();

Html::header(
    Account::getTypeName(),
    $_SERVER['PHP_SELF'],
    'tools',
    'GlpiPlugin\\Gestiontemps\\Menu',
    'account'
);

Menu::showNav('account');

$isRh = Profile::canDebitAccount();

// Utilisateur ciblé : le filtre de la page s'il est posé, sinon l'utilisateur
// consulté depuis le tableau de bord, sinon soi-même.
if (isset($_GET['users_id'])) {
    $target = (int) $_GET['users_id'];
    Config::setViewedUser($target);
} else {
    $target = Config::effectiveUser();
}

// Un non-RH ne peut consulter que sa propre tirelire.
if (!$isRh) {
    $target = (int) Session::getLoginUserID();
}

if ($isRh) {
    echo "<form method='get' action='" . $_SERVER['PHP_SELF'] . "' class='mb-3'>";
    echo "<label>" . __('Utilisateur', 'gestiontemps') . " </label> ";
    User::dropdown([
        'name'     => 'users_id',
        'value'    => $target,
        'right'    => 'all',
        'on_change' => 'this.form.submit()',
    ]);
    Html::closeForm();
}

Account::showForUser($target);

Html::footer();
