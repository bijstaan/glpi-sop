<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

use GlpiPlugin\Glpisop\Approval;
use GlpiPlugin\Glpisop\Attacher;
use GlpiPlugin\Glpisop\ChildTicket;
use GlpiPlugin\Glpisop\Condition;
use GlpiPlugin\Glpisop\Enforcement;
use GlpiPlugin\Glpisop\Renderer;
use GlpiPlugin\Glpisop\Run;
use GlpiPlugin\Glpisop\Section;
use GlpiPlugin\Glpisop\Settings;
use GlpiPlugin\Glpisop\Sop;
use GlpiPlugin\Glpisop\Step;
use GlpiPlugin\Glpisop\StepType;

/**
 * Install: the template tables, the runtime tables, rights, and one worked
 * example.
 *
 * Table notes:
 *  - `rank_order` and `match_condition` are so named because `RANK` and
 *    `CONDITION` are reserved words in MySQL 8. GLPI's query builder quotes
 *    identifiers, but a column that only works through one code path is a trap
 *    for whoever writes the next raw query against it.
 *  - Answers store a typed value across four columns rather than one blob:
 *    `value` for scalars, `value_itemtype`/`value_items_id` for the pickers,
 *    `documents_id` for uploads. A JSON blob would be tidier to write and
 *    impossible to report on, and reporting on answers is the point.
 *  - The unique key on runs (sop, itemtype, items_id) is load-bearing rather
 *    than defensive: attachment runs on create *and* on every update, and this
 *    constraint is the only thing standing between that and a duplicate run
 *    per edit.
 */
function plugin_glpisop_install()
{
    /** @var DBmysql $DB */
    global $DB;

    $charset = 'utf8mb4';
    $collate = 'utf8mb4_unicode_ci';

    // ------------------------------------------------------------- the SOP
    if (!$DB->tableExists('glpi_plugin_glpisop_sops')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpisop_sops` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(255) NOT NULL,
                `content` TEXT NULL,
                `comment` TEXT NULL,
                `itemtypes` VARCHAR(255) NOT NULL DEFAULT 'Ticket',
                `is_active` TINYINT NOT NULL DEFAULT 1,
                `is_autoattach` TINYINT NOT NULL DEFAULT 1,
                `match_all` TINYINT NOT NULL DEFAULT 1,
                `enforce_on_solve` TINYINT NOT NULL DEFAULT 0,
                `sop_version` INT UNSIGNED NOT NULL DEFAULT 1,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_recursive` TINYINT NOT NULL DEFAULT 1,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `entities_id` (`entities_id`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    if (!$DB->tableExists('glpi_plugin_glpisop_sections')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpisop_sections` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpisop_sops_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `name` VARCHAR(255) NOT NULL,
                `content` TEXT NULL,
                `rank_order` INT NOT NULL DEFAULT 0,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `sop` (`plugin_glpisop_sops_id`,`rank_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // The gate itself lives in `glpi_plugin_glpisop_conditions`, one row per
    // clause; `depends_mode` is how this step's clauses are joined.
    if (!$DB->tableExists('glpi_plugin_glpisop_steps')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpisop_steps` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpisop_sops_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `plugin_glpisop_sections_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `label` VARCHAR(255) NOT NULL,
                `help` TEXT NULL,
                `step_type` VARCHAR(32) NOT NULL DEFAULT 'check',
                `config_json` TEXT NULL,
                `is_required` TINYINT NOT NULL DEFAULT 0,
                `is_active` TINYINT NOT NULL DEFAULT 1,
                `rank_order` INT NOT NULL DEFAULT 0,
                `depends_mode` VARCHAR(8) NOT NULL DEFAULT 'all',
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `sop` (`plugin_glpisop_sops_id`,`rank_order`),
                KEY `section` (`plugin_glpisop_sections_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // One clause of one step's gate.
    //
    // `criterion`/`match_condition`/`value` are named to match the trigger
    // table on purpose rather than by coincidence: a `field` clause is
    // evaluated by Trigger::matches() and described by Trigger::describe(),
    // both of which read exactly those three keys. Naming them anything else
    // would mean a translation layer between two tables that hold the same
    // thing.
    if (!$DB->tableExists('glpi_plugin_glpisop_conditions')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpisop_conditions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpisop_steps_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `source` VARCHAR(16) NOT NULL DEFAULT 'step',
                `depends_steps_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `criterion` VARCHAR(64) NOT NULL DEFAULT '',
                `match_condition` VARCHAR(32) NOT NULL DEFAULT '',
                `value` VARCHAR(255) NOT NULL DEFAULT '',
                `rank_order` INT NOT NULL DEFAULT 0,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `step` (`plugin_glpisop_steps_id`,`rank_order`),
                KEY `depends` (`depends_steps_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    if (!$DB->tableExists('glpi_plugin_glpisop_triggers')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpisop_triggers` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpisop_sops_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `criterion` VARCHAR(64) NOT NULL,
                `match_condition` VARCHAR(32) NOT NULL DEFAULT 'is',
                `value` VARCHAR(255) NOT NULL DEFAULT '',
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `sop` (`plugin_glpisop_sops_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    if (!$DB->tableExists('glpi_plugin_glpisop_templatebindings')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpisop_templatebindings` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpisop_sops_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `itemtype` VARCHAR(100) NOT NULL,
                `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `binding` (`plugin_glpisop_sops_id`,`itemtype`,`items_id`),
                KEY `template` (`itemtype`,`items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // ------------------------------------------------------- what was drafted
    //
    // One row per procedure drafted from resolved tickets. Never updated: what
    // happened to the draft afterwards is read from the SOP itself, and a
    // drafted procedure somebody deleted outright leaves this row orphaned on
    // purpose — deleting it is the strongest rejection there is, and a table
    // that forgot those would only ever report on the drafts people liked.
    if (!$DB->tableExists('glpi_plugin_glpisop_authorings')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpisop_authorings` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpisop_sops_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `itilcategories_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `tickets_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `ticket_ids` TEXT NULL,
                `steps_created` INT UNSIGNED NOT NULL DEFAULT 0,
                `provider` VARCHAR(64) NOT NULL DEFAULT '',
                `model` VARCHAR(128) NOT NULL DEFAULT '',
                `confidence` VARCHAR(16) NOT NULL DEFAULT '',
                `gaps` TEXT NULL,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `sop` (`plugin_glpisop_sops_id`),
                KEY `entities_id` (`entities_id`),
                KEY `category` (`itilcategories_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // ---------------------------------------------------------- the runtime
    if (!$DB->tableExists('glpi_plugin_glpisop_runs')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpisop_runs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpisop_sops_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `itemtype` VARCHAR(100) NOT NULL,
                `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `sop_version` INT UNSIGNED NOT NULL DEFAULT 1,
                `status` VARCHAR(16) NOT NULL DEFAULT 'in_progress',
                `origin` VARCHAR(16) NOT NULL DEFAULT 'trigger',
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `users_id_complete` INT UNSIGNED NOT NULL DEFAULT 0,
                `total_visible` INT UNSIGNED NOT NULL DEFAULT 0,
                `done_visible` INT UNSIGNED NOT NULL DEFAULT 0,
                `total_required` INT UNSIGNED NOT NULL DEFAULT 0,
                `done_required` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_unlocked` TINYINT NOT NULL DEFAULT 0,
                `completed_at` TIMESTAMP NULL DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `once_per_item` (`plugin_glpisop_sops_id`,`itemtype`,`items_id`),
                KEY `item` (`itemtype`,`items_id`),
                KEY `status` (`status`),
                KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    if (!$DB->tableExists('glpi_plugin_glpisop_answers')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpisop_answers` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpisop_runs_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `plugin_glpisop_steps_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `state` VARCHAR(16) NOT NULL DEFAULT 'pending',
                `value` TEXT NULL,
                `value_itemtype` VARCHAR(100) NULL,
                `value_items_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `documents_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `note` TEXT NULL,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `once_per_step` (`plugin_glpisop_runs_id`,`plugin_glpisop_steps_id`),
                KEY `step_state` (`plugin_glpisop_steps_id`,`state`),
                KEY `linked_item` (`value_itemtype`,`value_items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    if (!$DB->tableExists('glpi_plugin_glpisop_runlogs')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpisop_runlogs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpisop_runs_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `plugin_glpisop_steps_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `action` VARCHAR(16) NOT NULL,
                `detail` TEXT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `run` (`plugin_glpisop_runs_id`,`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    plugin_glpisop_migrate();
    plugin_glpisop_install_rights();
    plugin_glpisop_install_defaults();

    return true;
}

/**
 * Column additions for installations that predate them.
 *
 * GLPI calls the install hook on upgrade too, so this runs on both paths; each
 * change is guarded so it is safe to repeat.
 */
function plugin_glpisop_migrate()
{
    /** @var DBmysql $DB */
    global $DB;

    // Whether a completed, auto-locked run has been deliberately reopened for
    // editing. Not derivable from the status: a locked run and an unlocked one
    // are both `completed`, and the difference is a decision somebody made.
    if (
        $DB->tableExists('glpi_plugin_glpisop_runs')
        && !$DB->fieldExists('glpi_plugin_glpisop_runs', 'is_unlocked')
    ) {
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_glpisop_runs` ADD COLUMN `is_unlocked` TINYINT NOT NULL DEFAULT 0'
        );
    }

    // How a step's gate clauses combine. Added before the clauses are moved, so
    // a procedure that is mid-migration still reads as the AND it was.
    if (
        $DB->tableExists('glpi_plugin_glpisop_steps')
        && !$DB->fieldExists('glpi_plugin_glpisop_steps', 'depends_mode')
    ) {
        $DB->doQuery(
            "ALTER TABLE `glpi_plugin_glpisop_steps` "
            . "ADD COLUMN `depends_mode` VARCHAR(8) NOT NULL DEFAULT 'all'"
        );
    }

    // Answers are looked up *by the item they point at* whenever a ticket a
    // procedure is waiting on changes status — see plugin_glpisop_settle_child().
    // That runs on every ticket status change in the instance, so it is not a
    // query that may table-scan.
    if (
        $DB->tableExists('glpi_plugin_glpisop_answers')
        && !isIndex('glpi_plugin_glpisop_answers', 'linked_item')
    ) {
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_glpisop_answers` '
            . 'ADD KEY `linked_item` (`value_itemtype`,`value_items_id`)'
        );
    }

    plugin_glpisop_migrate_gates();
}

/**
 * Move the one-gate-per-step triple into the clause table.
 *
 * A step used to carry `depends_steps_id`/`depends_op`/`depends_value` on its
 * own row, which could express exactly one thing. Each of those becomes one
 * `step` clause, and the columns are then dropped rather than left behind: a
 * column nothing reads is a column the next person to write a query will
 * believe, and a gate that half-exists in two places is the one bug in this
 * plugin that would be invisible until a procedure quietly stopped asking a
 * question.
 *
 * Guarded on the columns still existing, so it is a no-op on every install
 * after the first — which matters, because GLPI runs the install hook on
 * upgrade as well.
 */
function plugin_glpisop_migrate_gates()
{
    /** @var DBmysql $DB */
    global $DB;

    if (
        !$DB->tableExists('glpi_plugin_glpisop_steps')
        || !$DB->tableExists('glpi_plugin_glpisop_conditions')
        || !$DB->fieldExists('glpi_plugin_glpisop_steps', 'depends_steps_id')
    ) {
        return;
    }

    $now = date('Y-m-d H:i:s');

    foreach (
        $DB->request([
            'SELECT' => ['id', 'depends_steps_id', 'depends_op', 'depends_value'],
            'FROM'   => 'glpi_plugin_glpisop_steps',
            'WHERE'  => ['depends_steps_id' => ['>', 0]],
        ]) as $row
    ) {
        // Idempotent within the run as well as across runs: a migration that
        // was interrupted after inserting some clauses but before dropping the
        // columns must not double them on the retry.
        $already = countElementsInTable('glpi_plugin_glpisop_conditions', [
            'plugin_glpisop_steps_id' => (int) $row['id'],
        ]);
        if ($already > 0) {
            continue;
        }

        $DB->insert('glpi_plugin_glpisop_conditions', [
            'plugin_glpisop_steps_id' => (int) $row['id'],
            'source'                  => 'step',
            'depends_steps_id'        => (int) $row['depends_steps_id'],
            'criterion'               => '',
            'match_condition'         => (string) $row['depends_op'],
            'value'                   => (string) $row['depends_value'],
            'rank_order'              => 1,
            'date_creation'           => $now,
        ]);
    }

    foreach (['depends_steps_id', 'depends_op', 'depends_value'] as $column) {
        if ($DB->fieldExists('glpi_plugin_glpisop_steps', $column)) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_glpisop_steps` DROP COLUMN `$column`");
        }
    }
}

/**
 * Authoring a procedure and running one are separate rights.
 *
 * Everyone on the service desk runs SOPs; deciding what the procedure *is* —
 * including whether it can hold a ticket open — belongs with whoever owns the
 * process. Handing both to the same profile by default would make the
 * distinction decorative.
 */
function plugin_glpisop_install_rights()
{
    /** @var DBmysql $DB */
    global $DB;

    $rights = [
        'plugin_glpisop_sop' => READ | CREATE | UPDATE | DELETE | PURGE,
        'plugin_glpisop_run' => READ | UPDATE,
    ];

    // Only add rights that are not already registered.
    //
    // GLPI runs the install hook on upgrade as well as on first install, and
    // ProfileRight::addProfileRights() inserts unconditionally — so calling it
    // for an existing right raises a duplicate-key error that aborts the whole
    // upgrade. Every plugin update would fail once the plugin had ever been
    // installed.
    $existing = [];
    foreach (
        $DB->request([
            'SELECT'   => ['name'],
            'DISTINCT' => true,
            'FROM'     => 'glpi_profilerights',
            'WHERE'    => ['name' => array_keys($rights)],
        ]) as $row
    ) {
        $existing[(string) $row['name']] = true;
    }

    foreach (array_keys($rights) as $right) {
        if (!isset($existing[$right])) {
            ProfileRight::addProfileRights([$right]);
        }
    }

    // Authoring goes to profiles that can already administer configuration.
    //
    // Keying off the installing user's session does not work: plugins are
    // routinely installed from the console (`bin/console glpi:plugin:install`),
    // where there is no active profile, and the plugin would then be installed
    // but usable by nobody.
    $authors = [];
    foreach (
        $DB->request([
            'SELECT' => ['profiles_id'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'config', 'rights' => ['&', UPDATE]],
        ]) as $row
    ) {
        $authors[] = (int) $row['profiles_id'];
    }

    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $authors[] = (int) $_SESSION['glpiactiveprofile']['id'];
    }

    foreach (array_unique($authors) as $profiles_id) {
        ProfileRight::updateProfileRights($profiles_id, [
            'plugin_glpisop_sop' => $rights['plugin_glpisop_sop'],
        ]);
    }

    // Running goes to every profile that can already update a ticket — which
    // is the definition of "works the queue". An SOP nobody can answer is
    // worse than no SOP, because it can be made to block resolution.
    $runners = [];
    foreach (
        $DB->request([
            'SELECT' => ['profiles_id'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'ticket', 'rights' => ['&', UPDATE]],
        ]) as $row
    ) {
        $runners[] = (int) $row['profiles_id'];
    }

    foreach (array_unique(array_merge($runners, $authors)) as $profiles_id) {
        ProfileRight::updateProfileRights($profiles_id, [
            'plugin_glpisop_run' => $rights['plugin_glpisop_run'],
        ]);
    }
}

/**
 * Seed settings and one worked example. Idempotent.
 *
 * Only the keys that are *missing* are written. GLPI runs this hook again on
 * every upgrade of the plugin, and `Config::setConfigurationValues()` updates a
 * key that already exists — so seeding the whole default set here would reset
 * an administrator's configuration to stock every time the plugin was updated,
 * silently and with nothing in the history to say why. Writing only what is
 * absent is also what lets a new setting arrive in an upgrade at all.
 */
function plugin_glpisop_install_defaults()
{
    $existing = Config::getConfigurationValues(
        PLUGIN_GLPISOP_CONFIG_CONTEXT,
        array_keys(Settings::DEFAULTS)
    );

    $missing = array_diff_key(Settings::DEFAULTS, $existing);
    if ($missing !== []) {
        Config::setConfigurationValues(PLUGIN_GLPISOP_CONFIG_CONTEXT, $missing);
    }

    plugin_glpisop_seed_example();
}

/**
 * One shipped SOP, with a branch in it.
 *
 * Not a starter pack — a worked example. The conditional step is the thing
 * about this plugin that is hard to describe and obvious to look at, and an
 * administrator who opens this procedure understands the model in less time
 * than reading about it takes.
 *
 * Seeded inactive and with automatic attachment off, so installing the plugin
 * never puts a procedure on anybody's tickets.
 */
function plugin_glpisop_seed_example()
{
    /** @var DBmysql $DB */
    global $DB;

    if (countElementsInTable(Sop::getTable()) > 0) {
        return;
    }

    $now = date('Y-m-d H:i:s');

    $DB->insert(Sop::getTable(), [
        'name'             => 'Example — account lockout',
        'content'          => 'A worked example, shipped inactive. Step 3 branches: the steps under '
                            . 'it are only asked when the reset did not resolve the lockout.',
        'itemtypes'        => 'Ticket',
        'is_active'        => 0,
        'is_autoattach'    => 0,
        'match_all'        => 1,
        'enforce_on_solve' => 0,
        'sop_version'      => 1,
        'entities_id'      => 0,
        'is_recursive'     => 1,
        'date_creation'    => $now,
        'date_mod'         => $now,
    ]);
    $sops_id = (int) $DB->insertId();

    $section = static function (string $name, string $content, int $rank) use ($DB, $sops_id, $now): int {
        $DB->insert(Section::getTable(), [
            'plugin_glpisop_sops_id' => $sops_id,
            'name'                   => $name,
            'content'                => $content,
            'rank_order'             => $rank,
            'date_creation'          => $now,
        ]);
        return (int) $DB->insertId();
    };

    $step = static function (array $fields) use ($DB, $sops_id, $now): int {
        $gate = $fields['_gate'] ?? null;
        unset($fields['_gate']);

        $DB->insert(Step::getTable(), $fields + [
            'plugin_glpisop_sops_id'     => $sops_id,
            'plugin_glpisop_sections_id' => 0,
            'help'                       => '',
            'config_json'                => '{}',
            'is_required'                => 0,
            'is_active'                  => 1,
            'depends_mode'               => Condition::MODE_ALL,
            'date_creation'              => $now,
            'date_mod'                   => $now,
        ]);
        $steps_id = (int) $DB->insertId();

        if ($gate !== null) {
            $DB->insert(Condition::getTable(), $gate + [
                'plugin_glpisop_steps_id' => $steps_id,
                'source'                  => Condition::SRC_STEP,
                'criterion'               => '',
                'rank_order'              => 1,
                'date_creation'           => $now,
            ]);
        }

        return $steps_id;
    };

    $verify   = $section('Verify', 'Before anything is changed.', 1);
    $resolve  = $section('Resolve', 'The work itself.', 2);

    $step([
        'plugin_glpisop_sections_id' => $verify,
        'label'                      => 'Confirm the caller’s identity',
        'help'                       => 'Two pieces of information not in the ticket. Do not proceed without this.',
        'step_type'                  => StepType::CHECK,
        'is_required'                => 1,
        'rank_order'                 => 1,
    ]);

    $step([
        'plugin_glpisop_sections_id' => $verify,
        'label'                      => 'Account name',
        'step_type'                  => StepType::TEXT,
        'is_required'                => 1,
        'rank_order'                 => 2,
    ]);

    $outcome = $step([
        'plugin_glpisop_sections_id' => $resolve,
        'label'                      => 'Did unlocking the account resolve it?',
        'step_type'                  => StepType::CHOICE,
        'config_json'                => json_encode(['options' => ['Yes', 'No']]),
        'is_required'                => 1,
        'rank_order'                 => 1,
    ]);

    // The branch. Both of these are required, and neither counts against the
    // run while the answer above is "Yes" — which is the whole point.
    $step([
        'plugin_glpisop_sections_id' => $resolve,
        'label'                      => 'What is the account still reporting?',
        'help'                       => 'The exact message, not a paraphrase.',
        'step_type'                  => StepType::TEXTAREA,
        'is_required'                => 1,
        'rank_order'                 => 2,
        '_gate'                      => [
            'depends_steps_id' => $outcome,
            'match_condition'  => Step::OP_EQ,
            'value'            => 'No',
        ],
    ]);

    $step([
        'plugin_glpisop_sections_id' => $resolve,
        'label'                      => 'Escalated to identity management',
        'step_type'                  => StepType::CHECK,
        'is_required'                => 1,
        'rank_order'                 => 3,
        '_gate'                      => [
            'depends_steps_id' => $outcome,
            'match_condition'  => Step::OP_EQ,
            'value'            => 'No',
        ],
    ]);
}

function plugin_glpisop_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    foreach (
        [
            'glpi_plugin_glpisop_authorings',
            'glpi_plugin_glpisop_runlogs',
            'glpi_plugin_glpisop_answers',
            'glpi_plugin_glpisop_runs',
            'glpi_plugin_glpisop_templatebindings',
            'glpi_plugin_glpisop_triggers',
            'glpi_plugin_glpisop_conditions',
            'glpi_plugin_glpisop_steps',
            'glpi_plugin_glpisop_sections',
            'glpi_plugin_glpisop_sops',
        ] as $table
    ) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    foreach (['plugin_glpisop_sop', 'plugin_glpisop_run'] as $right) {
        ProfileRight::deleteProfileRights([$right]);
    }

    Config::deleteConfigurationValues(
        PLUGIN_GLPISOP_CONFIG_CONTEXT,
        array_keys(Settings::DEFAULTS)
    );

    return true;
}

// ---------------------------------------------------------------- item hooks

/**
 * An ITIL object was created: attach whatever applies.
 *
 * Failure here must never take the item with it. A technician raising a ticket
 * is doing their job, and a broken trigger is not a reason to refuse it — so
 * everything is contained and logged rather than thrown.
 */
function plugin_glpisop_item_add($item)
{
    if (!($item instanceof CommonDBTM)) {
        return;
    }

    try {
        Attacher::evaluate($item, true);
    } catch (\Throwable $e) {
        trigger_error('glpisop: could not attach SOPs: ' . $e->getMessage(), E_USER_WARNING);
    }
}

/** An ITIL object changed: re-evaluate, since triage is where triggers land. */
function plugin_glpisop_item_update($item)
{
    if (!($item instanceof CommonDBTM)) {
        return;
    }

    try {
        Attacher::evaluate($item, false);
    } catch (\Throwable $e) {
        trigger_error('glpisop: could not attach SOPs: ' . $e->getMessage(), E_USER_WARNING);
    }

    try {
        plugin_glpisop_settle_child($item);
    } catch (\Throwable $e) {
        trigger_error('glpisop: could not settle a linked ticket: ' . $e->getMessage(), E_USER_WARNING);
    }

    try {
        plugin_glpisop_settle_approval($item);
    } catch (\Throwable $e) {
        trigger_error('glpisop: could not settle an approval step: ' . $e->getMessage(), E_USER_WARNING);
    }
}

/**
 * The item's approval state moved.
 *
 * An approval step is answered by this field and nothing else, so a run
 * carrying one has to be recounted the moment core rolls the state up — which
 * it does through the parent's own update(), so this hook is the right place to
 * see it. Run::recount() re-checks the same thing on every answer, which is the
 * backstop for whatever a hook misses.
 *
 * Guarded on the field having actually changed, because this fires on every
 * edit of every ticket in the instance and most of them are not about approval.
 */
function plugin_glpisop_settle_approval($item)
{
    if (
        !($item instanceof CommonITILObject)
        || !in_array('global_validation', (array) ($item->updates ?? []), true)
    ) {
        return;
    }

    foreach (Approval::runsOn($item::getType(), (int) $item->getID()) as $runs_id) {
        Run::recount($runs_id);
    }
}

/**
 * A ticket that some procedure is waiting on has changed.
 *
 * This is the fast path for {@see ChildTicket}: the step waiting on this ticket
 * lives on a *different* item, so nothing else in the request would ever look
 * at it, and without this the parent's checklist would stay outstanding until
 * somebody happened to touch it. Run::recount() re-checks the same thing on
 * every answer, which is the backstop for the cases a hook cannot see — a mass
 * status change, a direct database edit.
 *
 * Only status changes are acted on, because that is the only thing about the
 * child that can settle a step, and a ticket being recategorised should not
 * cost a recount of every procedure watching it.
 */
function plugin_glpisop_settle_child($item)
{
    if (!($item instanceof Ticket) || !in_array('status', (array) ($item->updates ?? []), true)) {
        return;
    }

    foreach (ChildTicket::runsWatching((int) $item->getID()) as $runs_id) {
        Run::recount($runs_id);
    }
}

/**
 * A status change is being saved.
 *
 * Unlike the attach hooks, a failure here is *not* swallowed into a warning:
 * this is the check that decides whether the item may be resolved, and an
 * exception that quietly became a no-op would turn enforcement off without
 * anybody noticing. Enforcement::guardStatusChange() therefore refuses by
 * blanking `$item->input`, and does not throw.
 */
function plugin_glpisop_pre_item_update($item)
{
    if ($item instanceof CommonDBTM) {
        Enforcement::guardStatusChange($item);
    }
}

/** A solution is being filed — the other way an item reaches "solved". */
function plugin_glpisop_pre_solution_add($item)
{
    if ($item instanceof CommonDBTM) {
        Enforcement::guardSolution($item);
    }
}

/** A purged item takes its runs, answers and history with it. */
function plugin_glpisop_item_purge($item)
{
    if (!($item instanceof CommonDBTM)) {
        return;
    }

    try {
        Run::purgeForItem($item::getType(), (int) $item->getID());
    } catch (\Throwable $e) {
        trigger_error('glpisop: could not purge SOP runs: ' . $e->getMessage(), E_USER_WARNING);
    }
}

// ------------------------------------------------------------------ timeline

/**
 * The standing reminder, at the top of the item's fields panel.
 *
 * Companion to the timeline entry rather than a duplicate of it: the timeline
 * is where the procedure is worked, and this is what stops it being forgotten
 * once thirty followups have pushed it off the screen. One line per run, and a
 * link straight to the checklist.
 */
function plugin_glpisop_pre_item_form($params)
{
    $item = $params['item'] ?? null;

    if (
        !($item instanceof CommonITILObject)
        || $item->isNewItem()
        || !Settings::appliesTo($item::getType())
        || !Settings::inCentralInterface()
        || !Session::haveRight(Run::$rightname, READ)
    ) {
        return;
    }

    Renderer::renderStatusPanel($item::getType(), (int) $item->getID());
}

/**
 * Put each run into the item's timeline, as a real entry.
 *
 * A procedure kept behind a tab is a procedure people stop opening. The
 * timeline is where a technician already is, so that is where the checklist
 * belongs — and it puts the answers into the same record as the followups they
 * explain, in the order they happened.
 *
 * No Twig template and no TIMELINE_ANSWER_ACTIONS registration. GLPI renders an
 * entry whose type it does not recognise by emitting `content` directly, so a
 * pre-rendered block is all that is needed; registering an answer-action would
 * additionally put "add an SOP" in the composer's dropdown, which is not a
 * thing a technician should be doing — procedures attach themselves.
 *
 * `is_content_safe` is set because this markup is ours and everything inside it
 * that came from a user has already been escaped by Renderer. Leaving it unset
 * would run the block through `safe_html`, which strips the data attributes the
 * checklist is driven by — the panel would render and then do nothing at all.
 */
function plugin_glpisop_timeline_items($params)
{
    $item = $params['item'] ?? null;
    if (!($item instanceof CommonITILObject) || !Settings::appliesTo($item::getType())) {
        return;
    }

    if (!Settings::inCentralInterface() || !Session::haveRight(Run::$rightname, READ)) {
        return;
    }

    foreach (Run::forItem($item::getType(), (int) $item->getID()) as $run) {
        $runs_id = (int) $run['id'];

        // Hydrated from the row already in hand rather than re-read: forItem()
        // has just selected it, and the timeline wants an object only so its
        // pre/post show hooks have something to pass other plugins.
        $run_object = new Run();
        $run_object->getFromResultSet($run);

        // A plain, backslash-free type: the timeline uses it as a CSS class and
        // as the entry's anchor id, and neither survives a namespaced class
        // name. Passing `object` alongside stops GLPI trying to resolve that
        // type back to a real itemtype.
        $params['timeline']['PluginGlpisopRun_' . $runs_id] = [
            'type'   => 'PluginGlpisopRun',
            'object' => $run_object,
            'item'   => [
                'id'                => $runs_id,
                'content'           => Renderer::runBlock($run),
                'is_content_safe'   => true,
                // Attributed to whoever the procedure landed on rather than to
                // nobody: an unattributed timeline card reads as system noise.
                'users_id'          => (int) $run['users_id'],
                'date'              => $run['date_creation'],
                'date_creation'     => $run['date_creation'],
                'date_mod'          => $run['date_creation'],
                'timeline_position' => CommonITILObject::TIMELINE_LEFT,
                // The card is interactive but not *editable* in GLPI's sense —
                // there is no followup body to rewrite, and offering the edit
                // and delete affordances would suggest the run can be removed
                // from the record.
                'can_edit'          => false,
                'can_promote'       => false,
            ],
        ];
    }
}

// ---------------------------------------------------------------- rule engine

/**
 * Add an "attach an SOP" action to GLPI's own ITIL rules.
 *
 * Registered through Hooks::USE_RULES in setup.php, which is what makes GLPI
 * call this from Rule::getAllActions().
 *
 * `append` rather than `assign`, so one rule can attach several procedures and
 * two matching rules do not overwrite each other. The values land in
 * `_plugin_glpisop_sops`, and the leading underscore is what carries them
 * through the write into `$item->input`, where Attacher reads them: GLPI
 * filters input down to real table columns before it touches the database and
 * leaves everything else alone. This is the same mechanism core uses for
 * `_projects_id`.
 */
function plugin_glpisop_getRuleActions($params = [])
{
    $itemtype = $params['rule_itemtype'] ?? '';
    if (!in_array($itemtype, ['RuleTicket', 'RuleChange', 'RuleProblem'], true)) {
        return [];
    }

    return [
        'plugin_glpisop_attach' => [
            'name'          => __('Attach an SOP', 'glpisop'),
            'type'          => 'dropdown',
            'table'         => Sop::getTable(),
            'force_actions' => ['append'],
            'permitseveral' => ['append'],
            'appendto'      => '_plugin_glpisop_sops',
        ],
    ];
}
