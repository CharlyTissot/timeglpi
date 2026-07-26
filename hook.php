<?php
/**
 * Installation / désinstallation du plugin gestiontemps.
 * Création du schéma de données et des droits.
 */

use GlpiPlugin\Gestiontemps\Config;
use GlpiPlugin\Gestiontemps\Journal;
use GlpiPlugin\Gestiontemps\Leave;
use GlpiPlugin\Gestiontemps\Profile as GtProfile;
use GlpiPlugin\Gestiontemps\TimeEntry;

/**
 * Installation : création des tables, droits et configuration par défaut.
 */
function plugin_gestiontemps_install(): bool
{
    global $DB;

    $default_charset   = method_exists(DBConnection::class, 'getDefaultCharset')
        ? DBConnection::getDefaultCharset()
        : 'utf8mb4';
    $default_collation = method_exists(DBConnection::class, 'getDefaultCollation')
        ? DBConnection::getDefaultCollation()
        : 'utf8mb4_unicode_ci';
    $default_sign      = method_exists(DBConnection::class, 'getDefaultPrimaryKeySignOption')
        ? DBConnection::getDefaultPrimaryKeySignOption()
        : '';

    $tables = [];

    // --- Configuration (singleton) -----------------------------------------
    $tables['glpi_plugin_gestiontemps_configs'] = "
        CREATE TABLE IF NOT EXISTS `glpi_plugin_gestiontemps_configs` (
            `id` int {$default_sign} NOT NULL AUTO_INCREMENT,
            `rh_profiles` text,
            `access_profiles` text,
            `access_users` text
                COMMENT 'liste blanche d utilisateurs ; si non vide, prime sur access_profiles',
            `nonprod_ticket_names` text
                COMMENT 'titres de tickets comptes en non-production, un par ligne',
            `weekly_quota_minutes` int NOT NULL DEFAULT '2100',
            `week_ref_parity` tinyint NOT NULL DEFAULT '0'
                COMMENT '0 = semaines paires -> A, 1 = semaines paires -> B',
            `rounding_minutes` int NOT NULL DEFAULT '0',
            `balance_start_date` date DEFAULT NULL COMMENT 'départ du compte de temps',
            `compliance_enabled` tinyint NOT NULL DEFAULT '0'
                COMMENT 'controle des infractions au Code du travail',
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

    // --- Écritures de temps -------------------------------------------------
    $tables['glpi_plugin_gestiontemps_timeentries'] = "
        CREATE TABLE IF NOT EXISTS `glpi_plugin_gestiontemps_timeentries` (
            `id` int {$default_sign} NOT NULL AUTO_INCREMENT,
            `entities_id` int {$default_sign} NOT NULL DEFAULT '0',
            `users_id` int {$default_sign} NOT NULL DEFAULT '0',
            `tickets_id` int {$default_sign} DEFAULT NULL,
            `tickettasks_id` int {$default_sign} DEFAULT NULL,
            `date_start` timestamp NULL DEFAULT NULL,
            `duration` int NOT NULL DEFAULT '0' COMMENT 'en secondes',
            `type` varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'production | manual',
            `source` varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'ticket_task | timer | manual',
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `tickettasks_id` (`tickettasks_id`),
            KEY `type` (`type`),
            KEY `date_start` (`date_start`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

    // --- Horaires théoriques (semaine A/B) ----------------------------------
    $tables['glpi_plugin_gestiontemps_schedules'] = "
        CREATE TABLE IF NOT EXISTS `glpi_plugin_gestiontemps_schedules` (
            `id` int {$default_sign} NOT NULL AUTO_INCREMENT,
            `users_id` int {$default_sign} NOT NULL DEFAULT '0',
            `week_type` varchar(10) NOT NULL DEFAULT 'normal' COMMENT 'normal | A | B',
            `weekday` tinyint NOT NULL DEFAULT '1' COMMENT '1=lundi .. 7=dimanche',
            `morning_start` varchar(5) DEFAULT NULL,
            `morning_end` varchar(5) DEFAULT NULL,
            `afternoon_start` varchar(5) DEFAULT NULL,
            `afternoon_end` varchar(5) DEFAULT NULL,
            `expected_minutes` int NOT NULL DEFAULT '0',
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`users_id`,`week_type`,`weekday`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

    // --- Compte de temps / tirelire -----------------------------------------
    $tables['glpi_plugin_gestiontemps_accounts'] = "
        CREATE TABLE IF NOT EXISTS `glpi_plugin_gestiontemps_accounts` (
            `id` int {$default_sign} NOT NULL AUTO_INCREMENT,
            `users_id` int {$default_sign} NOT NULL DEFAULT '0',
            `balance_seconds` int NOT NULL DEFAULT '0',
            `balance_start_date` date DEFAULT NULL
                COMMENT 'point de depart individuel : le passe est fige dans balance_seconds',
            `weekly_quota_minutes` int DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

    // --- Mouvements de la tirelire ------------------------------------------
    $tables['glpi_plugin_gestiontemps_accountmoves'] = "
        CREATE TABLE IF NOT EXISTS `glpi_plugin_gestiontemps_accountmoves` (
            `id` int {$default_sign} NOT NULL AUTO_INCREMENT,
            `users_id` int {$default_sign} NOT NULL DEFAULT '0' COMMENT 'bénéficiaire',
            `delta_seconds` int NOT NULL DEFAULT '0' COMMENT 'positif = crédit, négatif = débit',
            `reason` varchar(20) NOT NULL DEFAULT 'adjustment'
                COMMENT 'overtime | rh_debit | adjustment',
            `actor_users_id` int {$default_sign} NOT NULL DEFAULT '0',
            `period_start` date DEFAULT NULL,
            `period_end` date DEFAULT NULL,
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `actor_users_id` (`actor_users_id`),
            KEY `reason` (`reason`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

    // --- Congés --------------------------------------------------------------
    $tables['glpi_plugin_gestiontemps_leaves'] = "
        CREATE TABLE IF NOT EXISTS `glpi_plugin_gestiontemps_leaves` (
            `id` int {$default_sign} NOT NULL AUTO_INCREMENT,
            `users_id` int {$default_sign} NOT NULL DEFAULT '0',
            `date` date DEFAULT NULL,
            `period` varchar(15) NOT NULL DEFAULT 'day' COMMENT 'day | morning | afternoon',
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `date` (`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

    // --- Journal d'audit -----------------------------------------------------
    $tables['glpi_plugin_gestiontemps_journals'] = "
        CREATE TABLE IF NOT EXISTS `glpi_plugin_gestiontemps_journals` (
            `id` int {$default_sign} NOT NULL AUTO_INCREMENT,
            `actor_users_id` int {$default_sign} NOT NULL DEFAULT '0',
            `target_users_id` int {$default_sign} NOT NULL DEFAULT '0',
            `itemtype` varchar(100) DEFAULT NULL,
            `items_id` int {$default_sign} DEFAULT NULL,
            `action` varchar(50) NOT NULL DEFAULT '',
            `details` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `actor_users_id` (`actor_users_id`),
            KEY `target_users_id` (`target_users_id`),
            KEY `action` (`action`),
            KEY `item` (`itemtype`,`items_id`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

    // doQueryOrDie() (GLPI 10.0.6+/11) avec repli sur queryOrDie() si besoin.
    $runQuery = static function (string $sql) use ($DB): void {
        if (method_exists($DB, 'doQueryOrDie')) {
            $DB->doQueryOrDie($sql, $DB->error());
        } else {
            $DB->queryOrDie($sql, $DB->error());
        }
    };

    foreach ($tables as $name => $query) {
        if (!$DB->tableExists($name)) {
            $runQuery($query);
        }
    }

    // --- Migrations sur installations existantes ------------------------------
    // Colonne « utilisateur concerné » du journal (ajoutée après la 1.0.0) :
    // permet de filtrer l'historique visible par un utilisateur non-RH.
    $journal_table = 'glpi_plugin_gestiontemps_journals';
    if ($DB->tableExists($journal_table) && !$DB->fieldExists($journal_table, 'target_users_id')) {
        $runQuery(
            "ALTER TABLE `{$journal_table}`
                ADD COLUMN `target_users_id` int {$default_sign} NOT NULL DEFAULT '0' AFTER `actor_users_id`,
                ADD KEY `target_users_id` (`target_users_id`)"
        );
    }

    // Liste blanche d'utilisateurs (ajoutée après la 1.0.2) : permet de limiter
    // l'accès au plugin à quelques personnes pendant une phase de test.
    $config_table = 'glpi_plugin_gestiontemps_configs';
    if ($DB->tableExists($config_table) && !$DB->fieldExists($config_table, 'access_users')) {
        $runQuery(
            "ALTER TABLE `{$config_table}`
                ADD COLUMN `access_users` text DEFAULT NULL AFTER `access_profiles`"
        );
    }

    // Titres de tickets comptés en non-production (après la 1.0.6). La colonne
    // s'appelait « excluded_ticket_names » en 1.0.7/1.0.8, quand ces temps
    // étaient supprimés au lieu d'être reclassés : on la renomme en conservant
    // les titres déjà saisis.
    if ($DB->tableExists($config_table) && $DB->fieldExists($config_table, 'excluded_ticket_names')) {
        $runQuery(
            "ALTER TABLE `{$config_table}`
                CHANGE `excluded_ticket_names` `nonprod_ticket_names` text DEFAULT NULL"
        );
    }
    if ($DB->tableExists($config_table) && !$DB->fieldExists($config_table, 'nonprod_ticket_names')) {
        $runQuery(
            "ALTER TABLE `{$config_table}`
                ADD COLUMN `nonprod_ticket_names` text DEFAULT NULL AFTER `access_users`"
        );
        $runQuery(
            "UPDATE `{$config_table}`
                SET `nonprod_ticket_names` = 'Saisie des tâches Duotech'
             WHERE `nonprod_ticket_names` IS NULL OR `nonprod_ticket_names` = ''"
        );
    }

    // Contrôle des infractions au Code du travail (après la 1.4.2) : désactivé
    // par défaut, s'active depuis la configuration globale.
    if ($DB->tableExists($config_table) && !$DB->fieldExists($config_table, 'compliance_enabled')) {
        $runQuery(
            "ALTER TABLE `{$config_table}`
                ADD COLUMN `compliance_enabled` tinyint NOT NULL DEFAULT '0' AFTER `balance_start_date`"
        );
    }

    // Point de départ individuel du compte de temps (après la 1.0.9) : permet
    // de figer le passé lors d'un changement d'horaire ou de contrat.
    $acc_table = 'glpi_plugin_gestiontemps_accounts';
    if ($DB->tableExists($acc_table) && !$DB->fieldExists($acc_table, 'balance_start_date')) {
        $runQuery(
            "ALTER TABLE `{$acc_table}`
                ADD COLUMN `balance_start_date` date DEFAULT NULL AFTER `balance_seconds`"
        );
    }

    // Purge des temps issus de tâches désormais exclues : les tâches planifiées
    // (créneau de planning begin/end) ne doivent plus être comptabilisées.
    // Nettoyage idempotent (aucune ligne à supprimer aux exécutions suivantes).
    $te_table = 'glpi_plugin_gestiontemps_timeentries';
    if ($DB->tableExists($te_table) && $DB->tableExists('glpi_tickettasks')) {
        $runQuery(
            "DELETE `te` FROM `{$te_table}` `te`
                INNER JOIN `glpi_tickettasks` `tt` ON `tt`.`id` = `te`.`tickettasks_id`
             WHERE `te`.`source` = 'ticket_task'
               AND (
                    (`tt`.`begin` IS NOT NULL AND `tt`.`begin` <> '0000-00-00 00:00:00')
                 OR (`tt`.`end`   IS NOT NULL AND `tt`.`end`   <> '0000-00-00 00:00:00')
               )"
        );
    }

    // Recalage du début des écritures issues de tâches (après la 1.0.4) : le
    // champ `date` d'une tâche est l'heure de saisie, donc la FIN du travail.
    // On repositionne le début à (date - durée). Le calcul repart de la tâche
    // et non de la valeur stockée : rejouer cette migration est sans effet.
    if ($DB->tableExists($te_table) && $DB->tableExists('glpi_tickettasks')) {
        $runQuery(
            "UPDATE `{$te_table}` `te`
                INNER JOIN `glpi_tickettasks` `tt` ON `tt`.`id` = `te`.`tickettasks_id`
                SET `te`.`date_start` = DATE_SUB(`tt`.`date`, INTERVAL `te`.`duration` SECOND)
             WHERE `te`.`source` = 'ticket_task'
               AND `tt`.`date` IS NOT NULL
               AND `tt`.`date` <> '0000-00-00 00:00:00'"
        );
    }

    // Configuration par défaut (une seule ligne).
    if (countElementsInTable('glpi_plugin_gestiontemps_configs') === 0) {
        $config = new Config();
        $config->add([
            'rh_profiles'          => json_encode([]),
            'access_profiles'      => json_encode([]),
            'nonprod_ticket_names' => 'Saisie des tâches Duotech',
            'weekly_quota_minutes' => 2100, // 35 h
            'week_ref_parity'      => 0,
            'rounding_minutes'     => 0,
            'date_mod'             => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    // Application de la règle au passé : les écritures rattachées à un ticket
    // « non-production » sont reclassées (et inversement si le titre est retiré
    // de la liste).
    Config::resetCache();
    TimeEntry::reclassifyNonProdTickets();

    // Colonnes affichées par défaut dans les listes (Search).
    $displayprefs = [
        'GlpiPlugin\\Gestiontemps\\TimeEntry'    => [2, 3, 5, 6, 4], // date, user, nature, ticket, durée
        'GlpiPlugin\\Gestiontemps\\Journal'      => [1, 2, 3, 4, 5], // date, acteur, action, type, id
        'GlpiPlugin\\Gestiontemps\\AccountMove'  => [1, 2, 3, 4, 5], // date, bénéf., montant, motif, acteur
        'GlpiPlugin\\Gestiontemps\\Leave'        => [1, 2, 3, 4],    // date, user, période, commentaire
    ];
    foreach ($displayprefs as $itemtype => $nums) {
        $rank = 1;
        foreach ($nums as $num) {
            $exists = countElementsInTable('glpi_displaypreferences', [
                'itemtype' => $itemtype,
                'num'      => $num,
                'users_id' => 0,
            ]);
            if (!$exists) {
                $DB->insert('glpi_displaypreferences', [
                    'itemtype' => $itemtype,
                    'num'      => $num,
                    'rank'     => $rank,
                    'users_id' => 0,
                ]);
            }
            $rank++;
        }
    }

    // Droits par profil.
    GtProfile::installRights();

    // Tâche cron : calcul des heures supplémentaires.
    CronTask::register(
        \GlpiPlugin\Gestiontemps\Account::class,
        'computeOvertime',
        DAY_TIMESTAMP,
        [
            'comment' => __('Calcul des heures supplémentaires et alimentation de la tirelire', 'gestiontemps'),
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );

    return true;
}

/**
 * Désinstallation : suppression des tables, droits et cron.
 */
function plugin_gestiontemps_uninstall(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_gestiontemps_configs',
        'glpi_plugin_gestiontemps_timeentries',
        'glpi_plugin_gestiontemps_schedules',
        'glpi_plugin_gestiontemps_accounts',
        'glpi_plugin_gestiontemps_accountmoves',
        'glpi_plugin_gestiontemps_journals',
        'glpi_plugin_gestiontemps_leaves',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            if (method_exists($DB, 'doQueryOrDie')) {
                $DB->doQueryOrDie("DROP TABLE `{$table}`", $DB->error());
            } else {
                $DB->queryOrDie("DROP TABLE `{$table}`", $DB->error());
            }
        }
    }

    // Suppression des droits.
    GtProfile::uninstallRights();

    // Suppression du cron.
    if (class_exists('CronTask')) {
        CronTask::unregister('gestiontemps');
    }

    // Nettoyage des displaypreferences éventuels.
    $DB->delete('glpi_displaypreferences', [
        'itemtype' => [
            'LIKE', 'PluginGestiontemps%',
        ],
    ]);

    return true;
}

/**
 * Restriction des lignes visibles dans le moteur Search de GLPI.
 *
 * GLPI appelle cette fonction (via Plugin::doOneHook) pour chaque itemtype du
 * plugin afin d'injecter une condition WHERE. On délègue la logique aux classes
 * concernées : un utilisateur non-RH ne voit que ce qui le concerne.
 *
 * @param string $itemtype Classe de l'objet listé.
 * @return string Fragment SQL ajouté à la clause WHERE (vide = aucune restriction).
 */
function plugin_gestiontemps_addDefaultWhere($itemtype)
{
    switch ($itemtype) {
        case TimeEntry::class:
            return TimeEntry::addDefaultWhere();
        case Journal::class:
            return Journal::addDefaultWhere();
        case Leave::class:
            return Leave::addDefaultWhere();
        default:
            return '';
    }
}
