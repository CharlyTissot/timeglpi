<?php

namespace GlpiPlugin\Gestiontemps;

use CommonDBTM;
use CommonGLPI;
use CronTask;
use GlpiPlugin\Gestiontemps\Toolbox\Compat;
use GlpiPlugin\Gestiontemps\Toolbox\Time;
use Html;
use Session;

/**
 * Compte de temps (« tirelire ») d'un utilisateur.
 *
 * Le solde est alimenté par les heures supplémentaires (temps réel au-delà de
 * l'horaire théorique) et décrémenté par le service RH lors des prises.
 * Toute variation passe par un AccountMove tracé et par le journal.
 */
class Account extends CommonDBTM
{
    public static $rightname = 'plugin_gestiontemps_account';

    public static function getTypeName($nb = 0)
    {
        return __('Tirelire', 'gestiontemps');
    }

    public static function getIcon()
    {
        return 'ti ti-pig-money';
    }

    /**
     * Solde « live » (secondes) d'un utilisateur = compteur d'heures net.
     *
     * Solde = attendance nette (heures en plus − heures en retard, depuis la
     * première saisie jusqu'à aujourd'hui) + mouvements manuels (débits RH,
     * ajustements). Calculé à la volée, sans dépendre du cron.
     */
    public static function balanceForUser(int $users_id): int
    {
        global $DB;

        // Net d'assiduité depuis la date de départ configurée (ou, à défaut, la
        // première saisie) jusqu'à aujourd'hui.
        $net   = 0;
        $start = self::startDateForUser($users_id);
        if ($start !== null) {
            $net = \GlpiPlugin\Gestiontemps\Dashboard\ProductionCard::dailyAttendance(
                $users_id,
                $start,
                date('Y-m-d')
            )['net'];
        }

        // Mouvements manuels (débits RH, ajustements) — hors « overtime » (déjà
        // compté par le net vivant). Seuls ceux postérieurs au point de départ
        // comptent : les précédents sont déjà inclus dans le solde figé.
        $moves = 0;
        $where = ['users_id' => $users_id];
        if ($start !== null) {
            $where[] = ['date_creation' => ['>=', $start . ' 00:00:00']];
        }
        $iterator = $DB->request([
            'SELECT' => ['delta_seconds', 'reason'],
            'FROM'   => AccountMove::getTable(),
            'WHERE'  => $where,
        ]);
        foreach ($iterator as $m) {
            if ($m['reason'] === AccountMove::REASON_OVERTIME) {
                continue;
            }
            $moves += (int) $m['delta_seconds'];
        }

        // Solde initial (repris de l'ancien logiciel) + net + mouvements RH.
        return self::openingBalance($users_id) + $net + $moves;
    }

    /**
     * Point de départ du compte de temps d'un utilisateur.
     *
     * Priorité : date individuelle (posée par un recalcul lors d'un changement
     * d'horaire ou de contrat), sinon date globale de la configuration, sinon
     * la première saisie connue.
     */
    public static function startDateForUser(int $users_id): ?string
    {
        return self::startDateInfoForUser($users_id)['date'];
    }

    /**
     * Point de départ d'un utilisateur, avec son origine.
     *
     * @return array{date:?string, origin:string} origin ∈ user|config|first_entry|none
     */
    public static function startDateInfoForUser(int $users_id): array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => 'balance_start_date',
            'FROM'   => self::getTable(),
            'WHERE'  => ['users_id' => $users_id],
            'LIMIT'  => 1,
        ])->current();

        $individual = $row['balance_start_date'] ?? null;
        if ($individual && $individual !== '0000-00-00') {
            return ['date' => substr((string) $individual, 0, 10), 'origin' => 'user'];
        }

        $config = Config::getBalanceStartDate();
        if ($config !== null) {
            return ['date' => $config, 'origin' => 'config'];
        }

        $first = self::firstEntryDate($users_id);
        return [
            'date'   => $first,
            'origin' => $first !== null ? 'first_entry' : 'none',
        ];
    }

    /**
     * Recalcule le compte de temps à partir d'une date, en figeant le passé.
     *
     * Le solde est calculé en direct à partir des horaires COURANTS : modifier
     * un horaire ou un contrat réécrit donc rétroactivement tout l'historique.
     * Pour l'éviter, cette opération :
     *   1. calcule le solde tel qu'il est la veille de la date choisie ;
     *   2. l'enregistre comme solde figé (balance_seconds) ;
     *   3. place le point de départ individuel à cette date.
     * Les jours antérieurs ne sont plus recalculés ; à partir de la date, le
     * calcul repart avec les nouveaux horaires.
     *
     * @param int    $users_id Utilisateur concerné.
     * @param string $date     Date « Y-m-d » à partir de laquelle recalculer.
     * @param bool   $reset    Repartir de zéro : le solde figé vaut 0 au lieu
     *                         d'être calculé. À utiliser lors d'une mise en
     *                         service, quand l'historique antérieur n'existe
     *                         pas et produirait un déficit fictif.
     * @return int|null Solde figé en secondes, ou null si l'opération est refusée.
     */
    public static function recomputeFrom(int $users_id, string $date, bool $reset = false): ?int
    {
        global $DB;

        if (!Profile::canDebitAccount()) {
            Session::addMessageAfterRedirect(
                __('Seul le service RH peut recalculer un compte de temps.', 'gestiontemps'),
                false,
                ERROR
            );
            return null;
        }
        if ($users_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            return null;
        }

        // Solde arrêté à la veille : c'est la valeur que l'on fige.
        $eve    = date('Y-m-d', strtotime($date . ' -1 day'));
        $frozen = $reset ? 0 : self::balanceUpTo($users_id, $eve);

        $existing = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
            'LIMIT' => 1,
        ])->current();

        $account = new self();
        $data    = [
            'users_id'           => $users_id,
            'balance_seconds'    => $frozen,
            'balance_start_date' => $date,
            'date_mod'           => Compat::now(),
        ];
        if ($existing) {
            $data['id'] = $existing['id'];
            $account->update($data);
        } else {
            $account->add($data);
        }

        Journal::log('Account', $users_id, 'account_recompute', [
            'from'   => $date,
            'frozen' => $frozen,
            'reset'  => $reset,
        ], $users_id);

        return $frozen;
    }

    /**
     * Efface le point de départ individuel : l'utilisateur repasse sur la date
     * de la configuration générale (ou sa première saisie si elle est vide).
     */
    public static function clearStartDate(int $users_id): bool
    {
        global $DB;

        if (!Profile::canDebitAccount()) {
            return false;
        }

        $existing = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
            'LIMIT' => 1,
        ])->current();
        if (!$existing) {
            return true; // rien à effacer
        }

        // Mise à NULL en SQL direct : CommonDBTM::update() ne transmet pas
        // fiablement une valeur nulle selon les versions de GLPI.
        $ok = $DB->update(
            self::getTable(),
            ['balance_start_date' => null, 'date_mod' => Compat::now()],
            ['id' => (int) $existing['id']]
        );

        Journal::log('Account', $users_id, 'account_start_cleared', [], $users_id);

        return (bool) $ok;
    }

    /**
     * Solde arrêté à une date incluse, selon les règles en vigueur.
     */
    private static function balanceUpTo(int $users_id, string $until): int
    {
        global $DB;

        $start = self::startDateForUser($users_id);
        if ($start === null || strtotime($start) > strtotime($until)) {
            // Rien de calculable avant la date : seul le solde figé subsiste.
            return self::openingBalance($users_id);
        }

        $net = \GlpiPlugin\Gestiontemps\Dashboard\ProductionCard::dailyAttendance(
            $users_id,
            $start,
            $until
        )['net'];

        $moves    = 0;
        $iterator = $DB->request([
            'SELECT' => ['delta_seconds', 'reason'],
            'FROM'   => AccountMove::getTable(),
            'WHERE'  => [
                'users_id' => $users_id,
                ['date_creation' => ['>=', $start . ' 00:00:00']],
                ['date_creation' => ['<=', $until . ' 23:59:59']],
            ],
        ]);
        foreach ($iterator as $m) {
            if ($m['reason'] === AccountMove::REASON_OVERTIME) {
                continue;
            }
            $moves += (int) $m['delta_seconds'];
        }

        return self::openingBalance($users_id) + $net + $moves;
    }

    /**
     * Solde initial (secondes) d'un utilisateur, saisi par les RH — point de
     * départ à la date de départ du compte de temps.
     */
    public static function openingBalance(int $users_id): int
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
            'LIMIT' => 1,
        ])->current();

        return $row ? (int) $row['balance_seconds'] : 0;
    }

    /**
     * Définit le solde initial d'un utilisateur (réservé RH).
     */
    public static function setOpeningBalance(int $users_id, int $seconds): bool
    {
        global $DB;

        if (!Profile::canDebitAccount()) {
            Session::addMessageAfterRedirect(
                __('Vous n\'êtes pas autorisé à définir un solde initial.', 'gestiontemps'),
                false,
                ERROR
            );
            return false;
        }

        $account = new self();
        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
            'LIMIT' => 1,
        ])->current();

        if ($row) {
            $account->update(['id' => $row['id'], 'balance_seconds' => $seconds, 'date_mod' => Compat::now()]);
        } else {
            $account->add(['users_id' => $users_id, 'balance_seconds' => $seconds, 'date_mod' => Compat::now()]);
        }

        Journal::log('Account', $users_id, 'account_opening', ['seconds' => $seconds], $users_id);
        return true;
    }

    public const DEFAULT_WEEKLY_QUOTA = 2100; // 35 h en minutes

    /**
     * Quota hebdomadaire de référence (minutes) d'un utilisateur.
     * Défaut : 2100 min (35 h) si non défini.
     */
    public static function getWeeklyQuota(int $users_id): int
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
            'LIMIT' => 1,
        ])->current();

        $v = ($row && $row['weekly_quota_minutes'] !== null) ? (int) $row['weekly_quota_minutes'] : null;
        return $v ?? self::DEFAULT_WEEKLY_QUOTA;
    }

    /**
     * Définit le quota hebdomadaire de référence d'un utilisateur (RH).
     */
    public static function setWeeklyQuota(int $users_id, int $minutes): bool
    {
        global $DB;

        if (!Profile::canDebitAccount()) {
            return false;
        }

        $account = new self();
        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
            'LIMIT' => 1,
        ])->current();

        if ($row) {
            $account->update(['id' => $row['id'], 'weekly_quota_minutes' => $minutes, 'date_mod' => Compat::now()]);
        } else {
            $account->add(['users_id' => $users_id, 'weekly_quota_minutes' => $minutes, 'date_mod' => Compat::now()]);
        }
        return true;
    }

    /**
     * Date (Y-m-d) de la première saisie de temps d'un utilisateur, ou null.
     */
    private static function firstEntryDate(int $users_id): ?string
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['date_start'],
            'FROM'   => TimeEntry::getTable(),
            'WHERE'  => ['users_id' => $users_id],
            'ORDER'  => 'date_start ASC',
            'LIMIT'  => 1,
        ])->current();

        return ($row && !empty($row['date_start'])) ? substr((string) $row['date_start'], 0, 10) : null;
    }

    /**
     * Applique un mouvement au solde et enregistre l'AccountMove + le journal.
     *
     * @param int    $users_id  Bénéficiaire
     * @param int    $delta      Secondes (+ crédit / - débit)
     * @param string $reason     AccountMove::REASON_*
     * @param string $comment    Commentaire libre
     * @param array{start?:string,end?:string} $period Période concernée
     */
    public static function applyMove(
        int $users_id,
        int $delta,
        string $reason,
        string $comment = '',
        array $period = []
    ): bool {
        global $DB;

        if ($delta === 0) {
            return false;
        }

        // Note : le solde est calculé « en live » (solde initial + net + mouvements).
        // On enregistre donc uniquement le mouvement, sans toucher au solde initial.

        // Enregistrement du mouvement.
        $move  = new AccountMove();
        $moveid = (int) $move->add([
            'users_id'       => $users_id,
            'delta_seconds'  => $delta,
            'reason'         => $reason,
            'actor_users_id' => (int) (Session::getLoginUserID() ?: 0),
            'period_start'   => $period['start'] ?? null,
            'period_end'     => $period['end'] ?? null,
            'comment'        => $comment,
            'date_creation'  => Compat::now(),
        ]);

        // Journalisation.
        $action = $delta >= 0 ? 'overtime_credit' : 'account_debit';
        if ($reason === AccountMove::REASON_ADJUSTMENT) {
            $action = 'account_adjust';
        }
        Journal::log('Account', $users_id, $action, [
            'delta'   => $delta,
            'reason'  => $reason,
            'comment' => $comment,
            'moveid'  => $moveid,
        ], $users_id);

        return true;
    }

    /**
     * Décrément par le service RH (prise sur la tirelire).
     */
    public static function debitByRh(int $users_id, int $seconds, string $comment): bool
    {
        if (!Profile::canDebitAccount()) {
            Session::addMessageAfterRedirect(
                __('Vous n\'êtes pas autorisé à décréditer la tirelire.', 'gestiontemps'),
                false,
                ERROR
            );
            return false;
        }
        if ($seconds <= 0) {
            return false;
        }
        return self::applyMove($users_id, -$seconds, AccountMove::REASON_RH_DEBIT, $comment);
    }

    // ---------------------------------------------------------------------
    // CRON : calcul des heures supplémentaires.
    // ---------------------------------------------------------------------

    /**
     * Libellé de la tâche cron.
     */
    public static function cronInfo(string $name): array
    {
        if ($name === 'computeOvertime') {
            return ['description' => __('Calcul des heures supplémentaires (tirelire)', 'gestiontemps')];
        }
        return [];
    }

    /**
     * Tâche cron (conservée pour compatibilité).
     *
     * Le solde de la tirelire est désormais calculé « en live » (net d'assiduité
     * + mouvements manuels), donc ce cron ne crédite plus rien pour éviter un
     * double comptage.
     */
    public static function cronComputeOvertime(CronTask $task): int
    {
        $task->addVolume(0);
        return 0;
    }

    // ---------------------------------------------------------------------
    // Onglet sur la fiche User.
    // ---------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \User && Config::currentUserCanAccess()) {
            return self::getTypeName();
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \User) {
            self::showForUser((int) $item->getID());
        }
        return true;
    }

    /**
     * Affiche le solde, le formulaire de débit RH et l'historique des mouvements.
     */
    public static function showForUser(int $users_id): void
    {
        $balance = self::balanceForUser($users_id);
        $opening = self::openingBalance($users_id);
        $start   = self::startDateForUser($users_id);

        echo "<div class='card'><div class='card-body'>";
        echo "<h3>" . self::getTypeName() . " — " . getUserName($users_id) . "</h3>";
        echo "<div class='alert " . ($balance >= 0 ? 'alert-success' : 'alert-warning') . "'>";
        echo __('Solde', 'gestiontemps') . " : <b>" . Time::human($balance) . "</b>";
        echo "</div>";
        // Décomposition du solde : sans elle, le chiffre affiché paraît sorti
        // de nulle part dès qu'un solde initial a été figé par un recalcul.
        $net   = 0;
        $moves = 0;
        if ($start !== null) {
            $net = \GlpiPlugin\Gestiontemps\Dashboard\ProductionCard::dailyAttendance(
                $users_id,
                $start,
                date('Y-m-d')
            )['net'];
            $moves = $balance - $opening - $net;
        }

        // Origine de la date : sans ce rappel, impossible de savoir si le
        // décompte suit la configuration générale ou un réglage individuel.
        $origins = [
            'user'        => __('propre à cet utilisateur, posé par un recalcul', 'gestiontemps'),
            'config'      => __('configuration générale du plugin', 'gestiontemps'),
            'first_entry' => __('première saisie enregistrée', 'gestiontemps'),
            'none'        => __('aucune saisie', 'gestiontemps'),
        ];
        $origin = self::startDateInfoForUser($users_id)['origin'];

        echo "<p class='text-muted'>";
        echo __('Solde initial', 'gestiontemps') . " : <b>" . Time::human($opening) . "</b>";
        if ($start !== null) {
            echo " &nbsp;·&nbsp; " . sprintf(
                __('point de départ : %1$s (%2$s)', 'gestiontemps'),
                Html::convDate($start),
                $origins[$origin] ?? $origin
            );
        } else {
            echo " &nbsp;·&nbsp; " . __('point de départ : première saisie', 'gestiontemps');
        }
        echo "</p>";

        if ($start !== null) {
            echo "<table class='tab_cadre_fixe mb-3'>";
            echo "<tr class='tab_bg_2'><th colspan='2'>" . __('Détail du solde', 'gestiontemps') . "</th></tr>";
            echo "<tr class='tab_bg_1'><td>"
                . sprintf(__('Solde figé au %s', 'gestiontemps'), Html::convDate($start))
                . "</td><td class='text-end'>" . Time::human($opening) . "</td></tr>";
            echo "<tr class='tab_bg_1'><td>"
                . sprintf(__('Acquis depuis le %s (heures en plus − en retard)', 'gestiontemps'), Html::convDate($start))
                . "</td><td class='text-end'>" . Time::human($net) . "</td></tr>";
            if ($moves !== 0) {
                echo "<tr class='tab_bg_1'><td>" . __('Mouvements RH (débits, ajustements)', 'gestiontemps')
                    . "</td><td class='text-end'>" . Time::human($moves) . "</td></tr>";
            }
            echo "<tr class='tab_bg_2'><td><b>" . __('Solde', 'gestiontemps')
                . "</b></td><td class='text-end'><b>" . Time::human($balance) . "</b></td></tr>";
            echo "</table>";
        }

        // Formulaire de définition du solde initial (RH).
        if (Profile::canDebitAccount()) {
            echo "<form method='post' action='" . Compat::webPath() . "/front/account.form.php' class='row g-2 align-items-end mb-2'>";
            echo Html::hidden('users_id', ['value' => $users_id]);
            echo "<div class='col-auto'><label>" . __('Solde initial (minutes, peut être négatif)', 'gestiontemps') . "</label>";
            echo Html::input('opening_minutes', ['type' => 'number', 'value' => (int) round($opening / 60)]);
            echo "</div>";
            echo "<div class='col-auto'>";
            echo Html::submit(__('Définir le solde initial', 'gestiontemps'), ['name' => 'set_opening', 'class' => 'btn btn-secondary']);
            echo "</div>";
            Html::closeForm();
        }

        // Formulaire de débit RH.
        if (Profile::canDebitAccount()) {
            echo "<form method='post' action='" . Compat::webPath() . "/front/account.form.php' class='row g-2 align-items-end'>";
            echo Html::hidden('users_id', ['value' => $users_id]);
            echo "<div class='col-auto'><label>" . __('Débit (minutes)', 'gestiontemps') . "</label>";
            echo Html::input('debit_minutes', ['type' => 'number', 'min' => '1']);
            echo "</div>";
            echo "<div class='col'><label>" . __('Motif', 'gestiontemps') . "</label>";
            echo "<input type='text' name='comment' class='form-control' /></div>";
            echo "<div class='col-auto'>";
            echo Html::submit(__('Décréditer', 'gestiontemps'), ['name' => 'debit', 'class' => 'btn btn-danger']);
            echo "</div>";
            Html::closeForm();
        }

        // Historique des mouvements.
        $moves = AccountMove::forUser($users_id);
        echo "<h4 class='mt-3'>" . __('Historique des mouvements', 'gestiontemps') . "</h4>";
        echo "<div class='table-responsive'><table class='tab_cadre_fixe'>";
        echo "<tr><th>" . __('Date', 'gestiontemps') . "</th>";
        echo "<th>" . __('Montant', 'gestiontemps') . "</th>";
        echo "<th>" . __('Motif', 'gestiontemps') . "</th>";
        echo "<th>" . __('Acteur', 'gestiontemps') . "</th>";
        echo "<th>" . __('Commentaire', 'gestiontemps') . "</th></tr>";
        foreach ($moves as $m) {
            $cls = ((int) $m['delta_seconds']) >= 0 ? 'text-green' : 'text-red';
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . Html::convDateTime($m['date_creation']) . "</td>";
            echo "<td class='{$cls}'>" . Time::human((int) $m['delta_seconds']) . "</td>";
            echo "<td>" . AccountMove::reasonLabel($m['reason']) . "</td>";
            echo "<td>" . getUserName($m['actor_users_id']) . "</td>";
            echo "<td>" . htmlspecialchars((string) $m['comment']) . "</td>";
            echo "</tr>";
        }
        echo "</table></div>";
        echo "</div></div>";
    }
}
