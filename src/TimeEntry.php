<?php

namespace GlpiPlugin\Gestiontemps;

use CommonDBTM;
use CommonGLPI;
use GlpiPlugin\Gestiontemps\Toolbox\Compat;
use GlpiPlugin\Gestiontemps\Toolbox\Time;
use Html;
use Session;

/**
 * Écriture de temps.
 *
 * Deux natures :
 *  - production : temps issu des tâches de ticket (hooks) ou d'un minuteur lié
 *    à un ticket ;
 *  - manuel : temps saisi à la main sans ticket, ET temps passé sur les tickets
 *    dont le titre est déclaré « non-production » dans la configuration
 *    (tickets fourre-tout : saisie administrative, réunions…).
 */
class TimeEntry extends CommonDBTM
{
    public static $rightname = 'plugin_gestiontemps_timeentry';

    public const TYPE_PRODUCTION = 'production';
    public const TYPE_MANUAL     = 'manual';

    public const SOURCE_TASK   = 'ticket_task';
    public const SOURCE_TIMER  = 'timer';
    public const SOURCE_MANUAL = 'manual';
    /** Pause « mise à disposition » : comptée dans le temps de travail. */
    public const SOURCE_PAUSE  = 'pause';
    /** « Coupure » : temps non travaillé, hors du temps de travail. */
    public const SOURCE_BREAK  = 'break';

    public static function getTypeName($nb = 0)
    {
        return _n('Saisie de temps', 'Saisies de temps', $nb, 'gestiontemps');
    }

    public static function getIcon()
    {
        return 'ti ti-clock-edit';
    }

    // ---------------------------------------------------------------------
    // Contrôle d'accès piloté par la configuration (profils d'accès / RH),
    // indépendant des droits GLPI par profil -> le plugin est autonome et
    // portable sur une installation neuve.
    // ---------------------------------------------------------------------

    public static function canView()
    {
        return Config::currentUserCanAccess();
    }

    public static function canCreate()
    {
        return Config::currentUserCanAccess();
    }

    public static function canUpdate()
    {
        return Config::currentUserCanAccess();
    }

    public static function canDelete()
    {
        return Config::currentUserCanAccess();
    }

    public static function canPurge()
    {
        return Config::currentUserCanAccess();
    }

    /**
     * Restreint les lignes visibles dans le moteur Search de GLPI.
     *
     * - Le service RH (et l'administrateur ayant le droit « config ») voit
     *   toutes les saisies, tous utilisateurs confondus.
     * - Tout autre utilisateur ne voit que ses propres saisies (colonne
     *   users_id). Cela inclut les saisies créées pour lui par le RH, qui
     *   portent son users_id : il voit donc « ses » actions et celles du RH
     *   le concernant, mais jamais celles des autres utilisateurs.
     *
     * @return string Fragment SQL ajouté à la clause WHERE.
     */
    public static function addDefaultWhere()
    {
        // Un membre RH consultant un utilisateur précis ne voit que les saisies
        // de celui-ci (contexte partagé par toutes les pages du plugin).
        $viewed = Config::getViewedUser();
        if ($viewed !== null) {
            return self::getTable() . ".users_id = " . $viewed;
        }

        if (Session::haveRight('config', UPDATE) || Config::currentUserIsRh()) {
            return '';
        }

        $uid = (int) Session::getLoginUserID();
        return self::getTable() . ".users_id = " . $uid;
    }

    // ---------------------------------------------------------------------
    // Hooks sur les tâches de ticket -> temps de production.
    // ---------------------------------------------------------------------

    /**
     * Une tâche de ticket a été ajoutée : on crée l'écriture de production.
     */
    public static function onTaskAdd(CommonDBTM $task): void
    {
        self::syncFromTask($task);
    }

    /**
     * Une tâche de ticket a été modifiée : on met à jour l'écriture liée.
     */
    public static function onTaskUpdate(CommonDBTM $task): void
    {
        self::syncFromTask($task);
    }

    /**
     * Une tâche de ticket est purgée : on supprime l'écriture liée.
     */
    public static function onTaskPurge(CommonDBTM $task): void
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['tickettasks_id' => (int) $task->getID()],
        ]);
        foreach ($iterator as $row) {
            $entry = new self();
            $entry->delete(['id' => $row['id']], true);
            Journal::log('TimeEntry', (int) $row['id'], 'time_delete', [
                'source'   => self::SOURCE_TASK,
                'duration' => (int) $row['duration'],
            ], (int) ($row['users_id'] ?? 0));
        }
    }

    /**
     * Vrai si la tâche de ticket est « planifiée », c'est-à-dire associée à un
     * créneau de planning (champs begin/end renseignés). Une telle tâche est du
     * temps prévu, pas du temps effectué : elle ne doit pas générer d'écriture.
     *
     * @param array<string,mixed> $fields Champs de la tâche.
     */
    private static function taskIsPlanned(array $fields): bool
    {
        $isSet = static function ($v): bool {
            return !empty($v)
                && $v !== '0000-00-00 00:00:00'
                && strtotime((string) $v) !== false;
        };

        return $isSet($fields['begin'] ?? null) || $isSet($fields['end'] ?? null);
    }

    /**
     * Vrai si le temps de ce ticket compte en non-production (liste de titres
     * paramétrée dans la configuration du plugin).
     */
    public static function ticketIsNonProd(int $tickets_id): bool
    {
        global $DB;

        if ($tickets_id <= 0 || empty(Config::getNonProdTicketNames())) {
            return false;
        }

        $row = $DB->request([
            'SELECT' => 'name',
            'FROM'   => 'glpi_tickets',
            'WHERE'  => ['id' => $tickets_id],
            'LIMIT'  => 1,
        ])->current();

        return $row ? Config::isTicketNameNonProd($row['name'] ?? null) : false;
    }

    /**
     * Reclasse les écritures déjà enregistrées selon la liste des tickets
     * comptés en non-production. Appelée à l'installation/mise à jour pour
     * appliquer la règle au passé, dans les deux sens : un ticket retiré de la
     * liste repasse en production.
     *
     * @return int Nombre d'écritures reclassées.
     */
    public static function reclassifyNonProdTickets(): int
    {
        global $DB;

        $changed  = 0;
        $iterator = $DB->request([
            'SELECT'    => [
                'glpi_plugin_gestiontemps_timeentries.id AS te_id',
                'glpi_plugin_gestiontemps_timeentries.type AS te_type',
                'glpi_tickets.name AS ticket_name',
            ],
            'FROM'      => 'glpi_plugin_gestiontemps_timeentries',
            'INNER JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        'glpi_tickets' => 'id',
                        'glpi_plugin_gestiontemps_timeentries' => 'tickets_id',
                    ],
                ],
            ],
        ]);
        foreach ($iterator as $row) {
            $expected = Config::isTicketNameNonProd($row['ticket_name'] ?? null)
                ? self::TYPE_MANUAL
                : self::TYPE_PRODUCTION;
            if ((string) $row['te_type'] === $expected) {
                continue;
            }
            // Mise à jour directe : normalizeInput() forcerait le type à
            // « production » du seul fait qu'un ticket est rattaché.
            $DB->update(
                self::getTable(),
                ['type' => $expected, 'date_mod' => Compat::now()],
                ['id' => (int) $row['te_id']]
            );
            $changed++;
        }

        return $changed;
    }

    /**
     * Utilisateur à qui imputer le temps d'une tâche de ticket.
     *
     * GLPI ne renseigne `users_id_tech` que si un technicien est explicitement
     * choisi dans le formulaire de tâche — champ souvent laissé vide. Dans ce
     * cas le temps revient à l'auteur de la tâche (`users_id`), qui est bien la
     * personne ayant effectué le travail qu'elle décrit.
     *
     * @param array<string,mixed> $fields Champs de la tâche.
     */
    private static function taskTechnician(array $fields): int
    {
        $tech = (int) ($fields['users_id_tech'] ?? 0);
        if ($tech > 0) {
            return $tech;
        }
        return (int) ($fields['users_id'] ?? 0);
    }

    /**
     * Début réel d'une tâche de ticket.
     *
     * Le champ `date` d'une tâche est l'horodatage de la saisie, faite une fois
     * le travail terminé : il correspond donc à la FIN de l'intervention. Le
     * début est obtenu en retranchant la durée, sans quoi le temps serait
     * comptabilisé sur un créneau postérieur au travail réel.
     *
     * Le calcul repart toujours de la tâche, jamais de la valeur déjà stockée :
     * il est donc idempotent, même si la tâche est modifiée plusieurs fois.
     *
     * @param string|null $task_date  Champ `date` de la tâche.
     * @param int         $duration   Durée en secondes.
     */
    private static function taskStartDate(?string $task_date, int $duration): string
    {
        $ts = $task_date ? strtotime($task_date) : false;
        if ($ts === false) {
            return Compat::now();
        }
        return date('Y-m-d H:i:s', $ts - max(0, $duration));
    }

    /**
     * Rattrapage : crée les écritures de production pour les tâches de ticket
     * déjà présentes en base avant l'installation du plugin (les hooks ne
     * s'appliquent qu'aux tâches créées ou modifiées ensuite).
     *
     * Applique exactement les mêmes règles que syncFromTask() : tâche non
     * planifiée, durée > 0, technicien renseigné. Les tâches déjà rattachées à
     * une écriture sont ignorées, donc l'import est rejouable sans doublon.
     *
     * @param string|null $since Date « Y-m-d » ; null = tout l'historique.
     * @return int Nombre d'écritures créées.
     */
    public static function importExistingTasks(?string $since = null): int
    {
        global $DB;

        // Tâches déjà rattachées à une écriture -> à ne pas réimporter.
        $known = [];
        foreach ($DB->request([
            'SELECT' => 'tickettasks_id',
            'FROM'   => self::getTable(),
            'WHERE'  => ['NOT' => ['tickettasks_id' => null]],
        ]) as $row) {
            $known[(int) $row['tickettasks_id']] = true;
        }

        // Le technicien peut être vide : on retombe alors sur l'auteur de la
        // tâche (cf. taskTechnician()), le filtre se fait donc en PHP.
        $where = [
            'glpi_tickettasks.actiontime' => ['>', 0],
        ];
        if ($since !== null && $since !== '') {
            $where[] = ['glpi_tickettasks.date' => ['>=', $since . ' 00:00:00']];
        }

        $iterator = $DB->request([
            'SELECT'    => [
                'glpi_tickettasks.*',
                'glpi_tickets.entities_id AS ticket_entities_id',
                'glpi_tickets.name AS ticket_name',
            ],
            'FROM'      => 'glpi_tickettasks',
            'LEFT JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        'glpi_tickets'     => 'id',
                        'glpi_tickettasks' => 'tickets_id',
                    ],
                ],
            ],
            'WHERE'     => $where,
        ]);

        $imported = 0;
        foreach ($iterator as $t) {
            $task_id = (int) $t['id'];
            $tech    = self::taskTechnician($t);
            if (isset($known[$task_id]) || self::taskIsPlanned($t) || $tech <= 0) {
                continue;
            }

            $entry = new self();
            $newid = (int) $entry->add([
                'users_id'       => $tech,
                'tickets_id'     => (int) $t['tickets_id'],
                'tickettasks_id' => $task_id,
                'entities_id'    => (int) ($t['ticket_entities_id'] ?? 0),
                'date_start'     => self::taskStartDate($t['date'] ?? null, (int) $t['actiontime']),
                'duration'       => (int) $t['actiontime'],
                'type'           => self::TYPE_PRODUCTION,
                'source'         => self::SOURCE_TASK,
                'comment'        => \Toolbox::substr(
                    trim(strip_tags(html_entity_decode($t['content'] ?? '', ENT_QUOTES | ENT_HTML5))),
                    0,
                    250
                ),
                'date_creation'  => Compat::now(),
                'date_mod'       => Compat::now(),
            ]);
            if ($newid > 0) {
                $imported++;
            }
        }

        Journal::log('TimeEntry', 0, 'tasks_import', [
            'since'    => $since,
            'imported' => $imported,
        ]);

        return $imported;
    }

    /**
     * Crée ou met à jour l'écriture de production correspondant à une tâche.
     */
    private static function syncFromTask(CommonDBTM $task): void
    {
        global $DB;

        $fields    = $task->fields;
        $actiontime = (int) ($fields['actiontime'] ?? 0);
        $tech       = self::taskTechnician($fields);
        $tickets_id = (int) ($fields['tickets_id'] ?? 0);
        $task_id    = (int) $task->getID();

        // Recherche d'une écriture existante liée à cette tâche.
        $existing = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['tickettasks_id' => $task_id],
            'LIMIT' => 1,
        ])->current();

        // Une tâche planifiée (créneau de planning : begin/end renseignés) ne
        // représente pas du temps réellement effectué : on l'exclut du calcul
        // et de l'affichage. Toute écriture déjà liée est retirée.
        // Idem s'il n'y a ni durée ni technicien.
        if (self::taskIsPlanned($fields) || $actiontime <= 0 || $tech <= 0) {
            if ($existing) {
                $entry = new self();
                $entry->delete(['id' => $existing['id']], true);
            }
            return;
        }

        $entities_id = (int) ($fields['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0));
        $date_start  = self::taskStartDate($fields['date'] ?? null, $actiontime);

        $data = [
            'users_id'       => $tech,
            'tickets_id'     => $tickets_id,
            'tickettasks_id' => $task_id,
            'entities_id'    => $entities_id,
            'date_start'     => $date_start,
            'duration'       => $actiontime,
            'type'           => self::TYPE_PRODUCTION,
            'source'         => self::SOURCE_TASK,
            'comment'        => \Toolbox::substr(
                trim(strip_tags(html_entity_decode($fields['content'] ?? '', ENT_QUOTES | ENT_HTML5))),
                0,
                250
            ),
            'date_mod'       => Compat::now(),
        ];

        $entry = new self();
        if ($existing) {
            $data['id'] = $existing['id'];
            $entry->update($data);
            Journal::log('TimeEntry', (int) $existing['id'], 'time_update', [
                'source'   => self::SOURCE_TASK,
                'duration' => $actiontime,
                'tickets_id' => $tickets_id,
            ], $tech);
        } else {
            $data['date_creation'] = Compat::now();
            $newid = (int) $entry->add($data);
            Journal::log('TimeEntry', $newid, 'time_add', [
                'source'   => self::SOURCE_TASK,
                'duration' => $actiontime,
                'tickets_id' => $tickets_id,
            ], $tech);
        }
    }

    // ---------------------------------------------------------------------
    // CRUD manuel + journalisation.
    // ---------------------------------------------------------------------

    /**
     * Prépare et normalise l'entrée avant ajout.
     */
    public function prepareInputForAdd($input)
    {
        $input = $this->normalizeInput($input);
        $input['date_creation'] = Compat::now();
        $input['date_mod']      = Compat::now();
        return $input;
    }

    /**
     * Prépare et normalise l'entrée avant mise à jour.
     */
    public function prepareInputForUpdate($input)
    {
        $input = $this->normalizeInput($input);
        $input['date_mod'] = Compat::now();
        return $input;
    }

    /**
     * Normalise le type/durée et applique l'arrondi configuré.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalizeInput(array $input): array
    {
        // Durée : le formulaire fournit des minutes -> secondes.
        if (isset($input['duration_minutes'])) {
            $input['duration'] = Time::minutesToSeconds((int) $input['duration_minutes']);
            unset($input['duration_minutes']);
        }

        // Un temps lié à un ticket est de la production... sauf si ce ticket
        // figure dans la liste des titres comptés en non-production (tickets
        // fourre-tout : saisie administrative, réunions…). Le temps reste
        // enregistré et rattaché au ticket, seule sa nature change.
        if (!empty($input['tickets_id'])) {
            $input['type'] = self::ticketIsNonProd((int) $input['tickets_id'])
                ? self::TYPE_MANUAL
                : self::TYPE_PRODUCTION;
            if (empty($input['source'])) {
                $input['source'] = self::SOURCE_MANUAL;
            }
        } else {
            $input['type'] = self::TYPE_MANUAL;
            // On préserve les sources « pause » et « coupure » ; sinon manuel.
            if (!in_array($input['source'] ?? '', [self::SOURCE_PAUSE, self::SOURCE_BREAK], true)) {
                $input['source'] = self::SOURCE_MANUAL;
            }
            $input['tickets_id'] = null;
        }

        // Rattachement à l'utilisateur courant si non précisé.
        if (empty($input['users_id'])) {
            $input['users_id'] = (int) Session::getLoginUserID();
        }

        // Arrondi éventuel.
        $rounding = (int) (Config::current()['rounding_minutes'] ?? 0);
        if ($rounding > 0 && !empty($input['duration'])) {
            $step = $rounding * 60;
            $input['duration'] = (int) (round($input['duration'] / $step) * $step);
        }

        return $input;
    }

    public function post_addItem()
    {
        Journal::log('TimeEntry', (int) $this->getID(), 'time_add', [
            'source'     => $this->fields['source'] ?? self::SOURCE_MANUAL,
            'type'       => $this->fields['type'] ?? self::TYPE_MANUAL,
            'duration'   => (int) ($this->fields['duration'] ?? 0),
            'tickets_id' => $this->fields['tickets_id'] ?? null,
        ], (int) ($this->fields['users_id'] ?? 0));
    }

    public function post_updateItem($history = 1)
    {
        Journal::log('TimeEntry', (int) $this->getID(), 'time_update', [
            'duration'   => (int) ($this->fields['duration'] ?? 0),
            'tickets_id' => $this->fields['tickets_id'] ?? null,
        ], (int) ($this->fields['users_id'] ?? 0));
    }

    public function post_purgeItem()
    {
        Journal::log('TimeEntry', (int) $this->getID(), 'time_delete', [
            'duration' => (int) ($this->fields['duration'] ?? 0),
        ], (int) ($this->fields['users_id'] ?? 0));
    }

    // ---------------------------------------------------------------------
    // Agrégations (utilisées par le dashboard et le calcul heures supp).
    // ---------------------------------------------------------------------

    /**
     * Somme des durées (secondes) par nature sur une période.
     *
     * @return array{production:int, manual:int, total:int}
     */
    public static function sumByType(?int $users_id, string $from, string $to): array
    {
        global $DB;

        $where = [
            'date_start' => ['>=', $from . ' 00:00:00'],
        ];
        $where[] = ['date_start' => ['<=', $to . ' 23:59:59']];
        if ($users_id !== null) {
            $where['users_id'] = $users_id;
        }

        $result = ['production' => 0, 'manual' => 0, 'total' => 0];

        // Somme calculée en PHP (aucune dépendance à QueryExpression, dont
        // l'emplacement diffère entre GLPI 10 et 11).
        $iterator = $DB->request([
            'SELECT' => ['type', 'duration', 'source'],
            'FROM'   => self::getTable(),
            'WHERE'  => $where,
        ]);
        foreach ($iterator as $row) {
            // Une coupure n'est pas du temps de travail : hors ratio production.
            if ((string) $row['source'] === self::SOURCE_BREAK) {
                continue;
            }
            $val = (int) $row['duration'];
            if ($row['type'] === self::TYPE_PRODUCTION) {
                $result['production'] += $val;
            } else {
                $result['manual'] += $val;
            }
        }
        $result['total'] = $result['production'] + $result['manual'];
        return $result;
    }

    // ---------------------------------------------------------------------
    // Onglets sur Ticket et User.
    // ---------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!Config::currentUserCanAccess()) {
            return '';
        }
        if ($item instanceof \Ticket || $item instanceof \User) {
            $count = self::countForItem($item);
            return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Ticket) {
            self::showForTicket((int) $item->getID());
        } elseif ($item instanceof \User) {
            self::showForUser((int) $item->getID());
        }
        return true;
    }

    private static function countForItem(CommonGLPI $item): int
    {
        if ($item instanceof \Ticket) {
            return countElementsInTable(self::getTable(), ['tickets_id' => $item->getID()]);
        }
        if ($item instanceof \User) {
            return countElementsInTable(self::getTable(), ['users_id' => $item->getID()]);
        }
        return 0;
    }

    /**
     * Récapitulatif des temps d'un ticket.
     */
    public static function showForTicket(int $tickets_id): void
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['tickets_id' => $tickets_id],
            'ORDER' => 'date_start DESC',
        ]);

        echo "<div class='table-responsive'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th>" . __('Date', 'gestiontemps') . "</th>";
        echo "<th>" . __('Technicien', 'gestiontemps') . "</th>";
        echo "<th>" . __('Durée', 'gestiontemps') . "</th>";
        echo "<th>" . __('Nature', 'gestiontemps') . "</th></tr>";

        $total = 0;
        foreach ($iterator as $row) {
            $total += (int) $row['duration'];
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . Html::convDateTime($row['date_start']) . "</td>";
            echo "<td>" . getUserName($row['users_id']) . "</td>";
            echo "<td>" . Time::human((int) $row['duration']) . "</td>";
            echo "<td>" . self::typeLabel($row['type']) . "</td>";
            echo "</tr>";
        }
        echo "<tr class='tab_bg_2'><td colspan='2'><b>" . __('Total', 'gestiontemps') . "</b></td>";
        echo "<td colspan='2'><b>" . Time::human($total) . "</b></td></tr>";
        echo "</table></div>";
    }

    /**
     * Récapitulatif des temps d'un utilisateur.
     */
    public static function showForUser(int $users_id): void
    {
        $from = date('Y-m-01');
        $to   = date('Y-m-t');
        $sum  = self::sumByType($users_id, $from, $to);

        echo "<div class='card'><div class='card-body'>";
        echo "<h4>" . sprintf(__('Temps du mois (%s → %s)', 'gestiontemps'), $from, $to) . "</h4>";
        echo "<ul>";
        echo "<li>" . __('Production', 'gestiontemps') . " : <b>" . Time::human($sum['production']) . "</b></li>";
        echo "<li>" . __('Non-production', 'gestiontemps') . " : <b>" . Time::human($sum['manual']) . "</b></li>";
        echo "<li>" . __('Total', 'gestiontemps') . " : <b>" . Time::human($sum['total']) . "</b> ";
        echo "(" . Time::percent($sum['production'], $sum['total']) . " % " . __('production', 'gestiontemps') . ")</li>";
        echo "</ul>";
        echo "</div></div>";
    }

    /**
     * Libellé lisible d'une nature de temps.
     */
    public static function typeLabel(string $type): string
    {
        return $type === self::TYPE_PRODUCTION
            ? __('Production', 'gestiontemps')
            : __('Non-production', 'gestiontemps');
    }

    /**
     * Options de recherche pour le moteur Search de GLPI.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rawSearchOptions()
    {
        $opts = [];
        $opts[] = ['id' => 'common', 'name' => self::getTypeName()];

        $opts[] = [
            'id' => 1, 'table' => self::getTable(), 'field' => 'id',
            'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false,
        ];
        $opts[] = [
            'id' => 2, 'table' => self::getTable(), 'field' => 'date_start',
            'name' => __('Date', 'gestiontemps'), 'datatype' => 'datetime',
        ];
        $opts[] = [
            'id' => 3, 'table' => 'glpi_users', 'field' => 'name',
            'linkfield' => 'users_id', 'name' => __('Utilisateur', 'gestiontemps'),
            'datatype' => 'dropdown',
        ];
        $opts[] = [
            'id' => 4, 'table' => self::getTable(), 'field' => 'duration',
            'name' => __('Durée', 'gestiontemps'), 'datatype' => 'timestamp',
        ];
        $opts[] = [
            'id' => 5, 'table' => self::getTable(), 'field' => 'type',
            'name' => __('Nature', 'gestiontemps'), 'datatype' => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];
        $opts[] = [
            'id' => 6, 'table' => 'glpi_tickets', 'field' => 'name',
            'linkfield' => 'tickets_id', 'name' => __('Ticket', 'gestiontemps'),
            'datatype' => 'dropdown',
        ];
        $opts[] = [
            'id' => 7, 'table' => self::getTable(), 'field' => 'comment',
            'name' => __('Commentaire', 'gestiontemps'), 'datatype' => 'text',
        ];

        return $opts;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'type') {
            return self::typeLabel($values['type']);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Formulaire d'ajout / édition d'une saisie manuelle.
     */
    public function showForm($ID, $options = [])
    {
        if ((int) $ID > 0) {
            $this->getFromDB((int) $ID);
        } else {
            $this->getEmpty();
        }

        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Utilisateur', 'gestiontemps') . "</td><td>";
        \User::dropdown([
            'name'   => 'users_id',
            'value'  => $this->fields['users_id'] ?: Session::getLoginUserID(),
            'right'  => 'all',
        ]);
        echo "</td>";
        echo "<td>" . __('Date', 'gestiontemps') . "</td><td>";
        Html::showDateTimeField('date_start', [
            'value' => $this->fields['date_start'] ?: Compat::now(),
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Durée', 'gestiontemps') . "</td><td>";
        // Sélecteur natif GLPI « XhYm » (valeur postée en secondes).
        \Dropdown::showTimeStamp('duration', [
            'value'           => (int) ($this->fields['duration'] ?? 0),
            'min'             => 0,
            'max'             => 24 * HOUR_TIMESTAMP,
            'step'            => 5 * MINUTE_TIMESTAMP,
            'addfirstminutes' => true,
        ]);
        echo "</td>";
        echo "<td></td><td class='text-muted'>"
            . __('Saisie manuelle = non-production', 'gestiontemps') . "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Commentaire', 'gestiontemps') . "</td>";
        echo "<td colspan='3'>";
        echo "<textarea name='comment' class='form-control' rows='2'>"
            . Html::cleanInputText($this->fields['comment'] ?? '') . "</textarea>";
        echo "</td></tr>";

        $this->showFormButtons($options);
        return true;
    }
}
