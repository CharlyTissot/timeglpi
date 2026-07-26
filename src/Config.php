<?php

namespace GlpiPlugin\Gestiontemps;

use CommonDBTM;
use Dropdown;
use GlpiPlugin\Gestiontemps\Toolbox\Compat;
use Html;
use Session;

/**
 * Configuration globale du plugin (ligne unique).
 *
 * - Profils GLPI autorisés à décréditer la tirelire (service RH).
 * - Quota hebdomadaire de référence.
 * - Parité de semaine pour l'alternance A/B.
 */
class Config extends CommonDBTM
{
    public static $rightname = 'config';

    /** Cache mémoire de la config. */
    private static ?array $cache = null;

    public static function getTypeName($nb = 0)
    {
        return __('Gestion du temps', 'gestiontemps');
    }

    /**
     * Retourne la configuration courante (tableau associatif).
     *
     * @return array<string,mixed>
     */
    public static function current(): array
    {
        global $DB;

        if (self::$cache !== null) {
            return self::$cache;
        }

        $row = $DB->request([
            'FROM'  => 'glpi_plugin_gestiontemps_configs',
            'ORDER' => 'id ASC',
            'LIMIT' => 1,
        ])->current();

        self::$cache = $row ?: [
            'id'                   => 0,
            'rh_profiles'          => '[]',
            'access_profiles'      => '[]',
            'access_users'         => '[]',
            'nonprod_ticket_names' => '',
            'weekly_quota_minutes' => 2100,
            'week_ref_parity'      => 0,
            'rounding_minutes'     => 0,
            'balance_start_date'   => null,
            'compliance_enabled'   => 0,
        ];

        return self::$cache;
    }

    /**
     * Vrai si le contrôle des infractions au Code du travail est activé.
     *
     * Désactivé par défaut : n'apparaît sur la Synthèse que si un membre de
     * config l'a explicitement coché dans la configuration globale.
     */
    public static function isComplianceEnabled(): bool
    {
        return (int) (self::current()['compliance_enabled'] ?? 0) === 1;
    }

    /**
     * Vide le cache mémoire (à appeler après une modification hors updateFromForm,
     * par exemple une migration SQL à l'installation).
     */
    public static function resetCache(): void
    {
        self::$cache = null;
    }

    /**
     * Date de départ (Y-m-d) du compte de temps, ou null si non définie.
     */
    public static function getBalanceStartDate(): ?string
    {
        $v = self::current()['balance_start_date'] ?? null;
        return ($v && $v !== '0000-00-00' && $v !== '') ? substr((string) $v, 0, 10) : null;
    }

    /**
     * Liste des identifiants de profils ayant accès aux pages du plugin.
     *
     * @return int[]
     */
    public static function getAccessProfiles(): array
    {
        $cfg  = self::current();
        $list = json_decode($cfg['access_profiles'] ?? '[]', true);
        return is_array($list) ? array_map('intval', $list) : [];
    }

    /** Clé de session portant l'utilisateur consulté par un membre RH. */
    private const SESSION_VIEWED_USER = 'plugin_gestiontemps_viewed_user';

    /**
     * Mémorise l'utilisateur consulté par un membre RH (null = lui-même).
     *
     * Ce contexte est partagé par toutes les pages du plugin : sélectionner un
     * utilisateur dans le tableau de bord cadre aussi les saisies de temps, les
     * congés et la tirelire. Sans droit RH, l'appel est sans effet.
     */
    public static function setViewedUser(?int $users_id): void
    {
        if (!self::currentUserIsRh()) {
            return;
        }
        if ($users_id === null || $users_id <= 0 || $users_id === (int) Session::getLoginUserID()) {
            unset($_SESSION[self::SESSION_VIEWED_USER]);
            return;
        }
        $_SESSION[self::SESSION_VIEWED_USER] = $users_id;
    }

    /**
     * Utilisateur actuellement consulté par un membre RH, ou null.
     */
    public static function getViewedUser(): ?int
    {
        if (!self::currentUserIsRh()) {
            return null;
        }
        $uid = (int) ($_SESSION[self::SESSION_VIEWED_USER] ?? 0);
        return $uid > 0 ? $uid : null;
    }

    /**
     * Utilisateur dont les données doivent être affichées sur les pages qui ne
     * portent pas de filtre propre : l'utilisateur consulté s'il y en a un,
     * sinon l'utilisateur connecté.
     */
    public static function effectiveUser(): int
    {
        return self::getViewedUser() ?? (int) Session::getLoginUserID();
    }

    /**
     * Titres de tickets dont le temps compte en NON-production (un par ligne).
     *
     * Sert aux tickets « fourre-tout » (saisie administrative, réunions…) : le
     * temps y est bien enregistré, mais il ne compte pas comme de la production.
     * La comparaison se fait en minuscules, espaces de bord retirés.
     *
     * @return string[] Titres normalisés.
     */
    public static function getNonProdTicketNames(): array
    {
        $raw = (string) (self::current()['nonprod_ticket_names'] ?? '');
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = mb_strtolower($line);
            }
        }
        return $out;
    }

    /**
     * Vrai si le temps passé sur ce ticket compte en non-production.
     */
    public static function isTicketNameNonProd(?string $name): bool
    {
        if ($name === null || trim($name) === '') {
            return false;
        }
        return in_array(mb_strtolower(trim($name)), self::getNonProdTicketNames(), true);
    }

    /**
     * Liste blanche d'utilisateurs autorisés (vide = pas de restriction).
     *
     * @return int[]
     */
    public static function getAccessUsers(): array
    {
        $cfg  = self::current();
        $list = json_decode($cfg['access_users'] ?? '[]', true);
        return is_array($list) ? array_map('intval', $list) : [];
    }

    /**
     * Vrai si l'utilisateur courant peut accéder aux pages « Gestion du temps ».
     *
     * Si une liste blanche d'utilisateurs est définie, elle prime sur tout le
     * reste (y compris le droit « config ») : seuls ces utilisateurs voient le
     * menu et les pages. C'est le mode « phase de test ». Le formulaire de
     * configuration du plugin reste joignable par les administrateurs via
     * Configuration > Plugins, donc aucun risque de verrouillage.
     *
     * Sinon : accès accordé si le profil actif figure dans la liste des profils
     * d'accès OU des profils RH, ou si l'utilisateur est administrateur.
     */
    public static function currentUserCanAccess(): bool
    {
        $allowed = self::getAccessUsers();
        if (!empty($allowed)) {
            return in_array((int) Session::getLoginUserID(), $allowed, true);
        }

        if (Session::haveRight('config', UPDATE)) {
            return true;
        }
        $active = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        return in_array($active, self::getAccessProfiles(), true)
            || in_array($active, self::getRhProfiles(), true);
    }

    /**
     * Refuse l'accès (page d'erreur GLPI) si l'utilisateur courant n'a pas
     * le droit d'accéder au plugin.
     */
    public static function checkAccessOrDie(): void
    {
        if (!self::currentUserCanAccess()) {
            Html::displayRightError();
        }
    }

    /**
     * Liste des identifiants de profils habilités RH.
     *
     * @return int[]
     */
    public static function getRhProfiles(): array
    {
        $cfg  = self::current();
        $list = json_decode($cfg['rh_profiles'] ?? '[]', true);
        return is_array($list) ? array_map('intval', $list) : [];
    }

    /**
     * Vrai si l'utilisateur courant appartient au service RH (peut décréditer).
     */
    public static function currentUserIsRh(): bool
    {
        $rh = self::getRhProfiles();
        if (empty($rh)) {
            return false;
        }
        $active = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        return in_array($active, $rh, true);
    }

    /**
     * Enregistre la configuration depuis un POST de formulaire.
     *
     * @param array<string,mixed> $input
     */
    public function updateFromForm(array $input): bool
    {
        $cfg = self::current();

        $profiles = $input['rh_profiles'] ?? [];
        if (!is_array($profiles)) {
            $profiles = [];
        }
        $profiles = array_values(array_unique(array_map('intval', $profiles)));

        $access = $input['access_profiles'] ?? [];
        if (!is_array($access)) {
            $access = [];
        }
        $access = array_values(array_unique(array_map('intval', $access)));

        $users = $input['access_users'] ?? [];
        if (!is_array($users)) {
            $users = [];
        }
        // Le dropdown utilisateurs renvoie 0 pour l'entrée vide -> on l'ignore.
        $users = array_values(array_filter(array_unique(array_map('intval', $users))));

        $data = [
            'id'                   => $cfg['id'],
            'nonprod_ticket_names' => (string) ($input['nonprod_ticket_names'] ?? ''),
            'rh_profiles'          => json_encode($profiles),
            'access_profiles'      => json_encode($access),
            'access_users'         => json_encode($users),
            'week_ref_parity'      => (int) ($input['week_ref_parity'] ?? 0),
            'rounding_minutes'     => (int) ($input['rounding_minutes'] ?? 0),
            'balance_start_date'   => !empty($input['balance_start_date']) ? $input['balance_start_date'] : null,
            'compliance_enabled'   => !empty($input['compliance_enabled']) ? 1 : 0,
            'date_mod'             => Compat::now(),
        ];

        self::$cache = null;

        if ((int) $cfg['id'] > 0) {
            $ok = $this->update($data);
        } else {
            $ok = (bool) $this->add($data);
        }

        Journal::log('Config', (int) $cfg['id'], 'config_update', [
            'rh_profiles'          => $profiles,
            'access_profiles'      => $access,
            'access_users'         => $users,
        ]);

        return $ok;
    }

    /**
     * Affiche le formulaire de configuration.
     */
    public function showConfigForm(): void
    {
        global $DB;

        if (!Session::haveRight('config', UPDATE)) {
            return;
        }

        $cfg          = self::current();
        $selected     = self::getRhProfiles();
        $selectedacc  = self::getAccessProfiles();

        // Liste des profils (id => nom) pour les multi-sélecteurs.
        $all_profiles = [];
        foreach ($DB->request(['FROM' => 'glpi_profiles', 'ORDER' => 'name']) as $p) {
            $all_profiles[(int) $p['id']] = $p['name'];
        }

        // Utilisateurs actifs, pour la liste blanche d'accès.
        $all_users = [];
        $user_rows = $DB->request([
            'FROM'  => 'glpi_users',
            'WHERE' => ['is_deleted' => 0, 'is_active' => 1],
            'ORDER' => ['realname', 'firstname', 'name'],
        ]);
        foreach ($user_rows as $u) {
            $label = trim(($u['realname'] ?? '') . ' ' . ($u['firstname'] ?? ''));
            $all_users[(int) $u['id']] = ($label !== '' ? $label . ' (' . $u['name'] . ')' : $u['name']);
        }

        // On s'appuie sur la machinerie de formulaire native de GLPI
        // (showFormHeader / showFormButtons) : elle produit un <form> propre,
        // avec la bonne action et le jeton CSRF correctement placé.
        $this->getFromDB((int) $cfg['id']);
        $this->showFormHeader(['candel' => false]);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Profils ayant accès à la Gestion du temps', 'gestiontemps') . "</td>";
        echo "<td>";
        Dropdown::showFromArray('access_profiles', $all_profiles, [
            'values'   => $selectedacc,
            'multiple' => true,
            'width'    => '80%',
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Restreindre à ces utilisateurs (phase de test)', 'gestiontemps') . "</td>";
        echo "<td>";
        Dropdown::showFromArray('access_users', $all_users, [
            'values'   => self::getAccessUsers(),
            'multiple' => true,
            'width'    => '80%',
        ]);
        echo " <span class='text-muted'>"
            . __('vide = accès piloté par les profils ci-dessus', 'gestiontemps')
            . "</span>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Profils RH (vue globale + décrément tirelire)', 'gestiontemps') . "</td>";
        echo "<td>";
        Dropdown::showFromArray('rh_profiles', $all_profiles, [
            'values'   => $selected,
            'multiple' => true,
            'width'    => '80%',
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Semaines paires (n° ISO) correspondent à', 'gestiontemps') . "</td>";
        echo "<td>";
        Dropdown::showFromArray('week_ref_parity', [
            0 => __('Semaine A', 'gestiontemps'),
            1 => __('Semaine B', 'gestiontemps'),
        ], ['value' => (int) $cfg['week_ref_parity']]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Arrondi des saisies (minutes, 0 = aucun)', 'gestiontemps') . "</td>";
        echo "<td>";
        echo Html::input('rounding_minutes', [
            'value' => (int) $cfg['rounding_minutes'],
            'type'  => 'number',
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Date de départ du compte de temps', 'gestiontemps') . "</td>";
        echo "<td>";
        Html::showDateField('balance_start_date', [
            'value'     => self::getBalanceStartDate(),
            'clearable' => true,
        ]);
        echo " <span class='text-muted'>" . __('vide = depuis la première saisie', 'gestiontemps') . "</span>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Contrôle des infractions au Code du travail', 'gestiontemps') . "</td>";
        echo "<td>";
        echo Html::getCheckbox([
            'name'    => 'compliance_enabled',
            'checked' => self::isComplianceEnabled(),
        ]);
        echo " <span class='text-muted'>"
            . __('signale sur la Synthèse les dépassements de durée, amplitude, repos et pause (règles de base)', 'gestiontemps')
            . "</span>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Titres de tickets comptés en non-production', 'gestiontemps') . "</td>";
        echo "<td>";
        echo "<textarea name='nonprod_ticket_names' rows='3' style='width:80%'>"
            . Html::cleanInputText((string) ($cfg['nonprod_ticket_names'] ?? ''))
            . "</textarea>";
        echo " <span class='text-muted'>"
            . __('un titre par ligne ; le temps de ces tickets est enregistré mais classé en non-production', 'gestiontemps')
            . "</span>";
        echo "</td></tr>";

        // Rattrapage des tâches antérieures à l'installation du plugin : les
        // hooks ne voient que les tâches créées/modifiées après coup.
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Importer les tâches existantes', 'gestiontemps') . "</td>";
        echo "<td>";
        echo "<button type='submit' name='import_tasks' value='1' class='btn btn-outline-secondary'>"
            . __('Lancer l\'import', 'gestiontemps')
            . "</button> ";
        echo "<span class='text-muted'>"
            . __('reprend les tâches de ticket déjà saisies, à partir de la date de départ ci-dessus (sans doublon)', 'gestiontemps')
            . "</span>";
        echo "</td></tr>";

        $this->showFormButtons(['candel' => false]);
    }
}
