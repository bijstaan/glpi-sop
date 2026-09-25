<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBChild;

/**
 * One step of a procedure.
 *
 * A step carries three separable things: what it asks (label, help, type and
 * the type's own config), whether it has to be answered (is_required), and
 * when it is asked at all — a list of {@see Condition} clauses joined by
 * `depends_mode`.
 *
 * The gate used to be a single `depends_*` triple on this row, which could say
 * one thing only: that an earlier step in the same procedure was answered thus.
 * That is not enough to model work that begins somewhere else. Onboarding is
 * the case that broke it — half of what decides whether a step applies arrived
 * with the request (its category, who raised it) or lives in GLPI's approvals,
 * and neither is an answer anybody gave to this procedure.
 *
 * Clauses are still a flat list joined by one operator rather than an
 * expression tree. "A and (B or C)" is expressed by nesting, exactly as
 * "A and B" was before; what has changed is that the flat case, which is
 * nearly all of them, no longer needs the nesting.
 */
class Step extends CommonDBChild
{
    public static string $rightname = 'plugin_glpisop_sop';

    public static string $itemtype = Sop::class;
    public static string $items_id = 'plugin_glpisop_sops_id';

    // Branch operators. `checked` is "answered at all", which for a checkbox
    // step is the same statement and for every other type is the useful one.
    public const OP_ALWAYS    = '';
    public const OP_CHECKED   = 'checked';
    public const OP_UNCHECKED = 'unchecked';
    public const OP_EQ        = 'eq';
    public const OP_NE        = 'ne';
    public const OP_GT        = 'gt';
    public const OP_LT        = 'lt';

    public static function getTypeName($nb = 0)
    {
        return _n('Step', 'Steps', $nb, 'glpisop');
    }

    /** @return array<string,string> */
    public static function operators(): array
    {
        return [
            self::OP_CHECKED   => __('was answered', 'glpisop'),
            self::OP_UNCHECKED => __('was not answered', 'glpisop'),
            self::OP_EQ        => __('is', 'glpisop'),
            self::OP_NE        => __('is not', 'glpisop'),
            self::OP_GT        => __('is greater than', 'glpisop'),
            self::OP_LT        => __('is less than', 'glpisop'),
        ];
    }

    /** Operators that need a value alongside them. */
    public static function operatorNeedsValue(string $op): bool
    {
        return in_array($op, [self::OP_EQ, self::OP_NE, self::OP_GT, self::OP_LT], true);
    }

    /**
     * A step's type-specific settings.
     *
     * Stored as JSON because the shape is genuinely per-type; a column per
     * option would be a dozen mostly-null columns that still would not cover
     * the next type added.
     *
     * @return array<string,mixed>
     */
    public static function config(array $step): array
    {
        $decoded = json_decode((string) ($step['config_json'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A step's gate clauses.
     *
     * Reads the hydrated list {@see self::allFor()} attached, and falls back to
     * a query for a caller holding a bare row, so that a row from either source
     * answers the same question.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function conditions(array $step): array
    {
        if (array_key_exists('conditions', $step) && is_array($step['conditions'])) {
            return $step['conditions'];
        }

        return Condition::forStep((int) ($step['id'] ?? 0));
    }

    /** How this step's clauses combine: all of them, or any one. */
    public static function mode(array $step): string
    {
        return (string) ($step['depends_mode'] ?? Condition::MODE_ALL) === Condition::MODE_ANY
            ? Condition::MODE_ANY
            : Condition::MODE_ALL;
    }

    /**
     * The earlier step this one reads as hanging off, for the display
     * numbering only.
     *
     * A gate can now name several steps and several ticket fields, but the
     * numbering is a tree — "3a" says *which* step 3a belongs under. The first
     * clause about an earlier step is the one an author wrote first, and is
     * therefore the one they think of it as being under.
     */
    public static function primaryParent(array $step): int
    {
        foreach (self::conditions($step) as $condition) {
            $parent = Condition::parentStep($condition);
            if ($parent > 0) {
                return $parent;
            }
        }

        return 0;
    }

    /**
     * Every active step of an SOP, in display order, with its gate.
     *
     * Ordering is by section rank first so that steps read in the order the
     * author laid them out, with unfiled steps (`sections_id` 0) last — an
     * author who has started using headings has said where things belong, and
     * whatever has not been filed yet is the tail of the procedure.
     *
     * Each row carries its clauses under `conditions`, fetched in one query for
     * the whole procedure. Every caller that evaluates visibility evaluates the
     * whole SOP at once, so reading them per step would put the clause count
     * into the query count of every ticket render.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function allFor(int $sops_id, bool $only_active = true): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $sections = Section::allFor($sops_id);
        $order    = [];
        $rank     = 0;
        foreach (array_keys($sections) as $sections_id) {
            $order[$sections_id] = ++$rank;
        }

        $where = ['plugin_glpisop_sops_id' => $sops_id];
        if ($only_active) {
            $where['is_active'] = 1;
        }

        $conditions = Condition::forSop($sops_id);

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => $where,
                'ORDER' => ['rank_order', 'id'],
            ]) as $row
        ) {
            $row['conditions'] = $conditions[(int) $row['id']] ?? [];
            $rows[]            = $row;
        }

        usort($rows, static function (array $a, array $b) use ($order): int {
            // PHP_INT_MAX rather than 0: unfiled steps sort after every named
            // section, not before the first one.
            $sa = $order[(int) $a['plugin_glpisop_sections_id']] ?? PHP_INT_MAX;
            $sb = $order[(int) $b['plugin_glpisop_sections_id']] ?? PHP_INT_MAX;

            return $sa <=> $sb
                ?: ((int) $a['rank_order'] <=> (int) $b['rank_order'])
                ?: ((int) $a['id'] <=> (int) $b['id']);
        });

        return $rows;
    }

    /** Next free rank within a section, so a new step lands at the bottom. */
    public static function nextRank(int $sops_id, int $sections_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['MAX' => 'rank_order AS max_rank'],
                'FROM'   => self::getTable(),
                'WHERE'  => [
                    'plugin_glpisop_sops_id'     => $sops_id,
                    'plugin_glpisop_sections_id' => $sections_id,
                ],
            ]) as $row
        ) {
            return ((int) $row['max_rank']) + 1;
        }

        return 1;
    }

    /**
     * Any structural change to a step re-derives every run of its SOP.
     *
     * This is not housekeeping. Adding a required step to a published SOP, or
     * marking an existing one required, changes what "complete" means for runs
     * that are already in flight — and the enforcement check reads the
     * denormalised counters on those runs, not the steps. Without this, a
     * ticket carrying a run that predates the new step could still be closed
     * with it outstanding, which is precisely the case enforcement exists for.
     *
     * Deactivating a step is the same problem in the other direction: it drops
     * out of the procedure, and a run left counting it would never complete.
     *
     * Through {@see Sop::structureChanged()} rather than inline, so that a
     * caller rewriting the whole procedure — the builder's save — can have the
     * work done once at the end instead of once per row.
     */
    public function post_addItem()
    {
        Sop::structureChanged((int) $this->fields['plugin_glpisop_sops_id']);
    }

    public function post_updateItem($history = true)
    {
        Sop::structureChanged((int) $this->fields['plugin_glpisop_sops_id']);
    }

    /**
     * Removing a step removes the answers given to it, its own gate, and every
     * clause elsewhere that was about it.
     *
     * That last deletion is the part that matters: a clause pointing at a step
     * that no longer exists can never be satisfied, so under `all` its children
     * would silently vanish from every run rather than becoming unconditional.
     * Deleting the clause rather than blanking it also keeps the "any" case
     * honest — a two-clause OR does not quietly become a one-clause OR that is
     * always true.
     */
    public function cleanDBonPurge()
    {
        /** @var \DBmysql $DB */
        global $DB;

        $steps_id = (int) $this->fields['id'];

        $DB->delete(Answer::getTable(), ['plugin_glpisop_steps_id' => $steps_id]);
        $DB->delete(Condition::getTable(), ['plugin_glpisop_steps_id' => $steps_id]);
        $DB->delete(Condition::getTable(), [
            'source'           => Condition::SRC_STEP,
            'depends_steps_id' => $steps_id,
        ]);

        // Every run of this SOP now has a different notion of "complete".
        Sop::structureChanged((int) $this->fields['plugin_glpisop_sops_id']);
    }
}
