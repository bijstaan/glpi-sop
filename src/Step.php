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
    public static $rightname = 'plugin_glpisop_sop';

    public static $itemtype = Sop::class;
    public static $items_id = 'plugin_glpisop_sops_id';

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
     * a query for the callers that hold a bare row — the step editor, the ajax
     * endpoint — so that a row from either source answers the same question.
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

    /**
     * Move a step one place up or down the procedure.
     *
     * The move is over the *flat* order an author is looking at, not within a
     * section, and that is a fix rather than a preference. Reordering used to
     * swap ranks with the adjacent step in the same section, while the builder
     * decided whether to grey out an arrow from the step's position in the
     * whole list — so at every section boundary the arrow was offered and did
     * nothing at all. Two dead buttons per boundary, indistinguishable from a
     * broken page.
     *
     * One press does exactly one thing, and which one depends on what is next
     * to the step:
     *
     *  - the neighbour is under the same heading → they swap places;
     *  - the neighbour is under a different one  → the step joins that heading
     *    and stays exactly where it is on screen.
     *
     * Splitting the crossing out from the move is what makes the arrows a true
     * inverse of each other. Doing both at once looks tidier and is not
     * reversible: a step pushed down past a heading lands *below* its new
     * neighbour, so pressing up returns it to the top of the new section rather
     * than to the section it came from, and an author who overshot by one has
     * no way back that does not overshoot the other way. The builder's tooltip
     * says which of the two a press will do before it is pressed.
     *
     * Ranks are rewritten directly rather than through update(), and the
     * version is bumped once at the end. Going through the model would fire
     * post_updateItem() per row, and each of those recounts every run of the
     * SOP — a dozen full recomputations to move one step past another, for a
     * change that cannot alter any of the numbers being recomputed.
     */
    public static function reorder(int $sops_id, int $steps_id, string $direction): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $flat     = self::allFor($sops_id, false);
        $position = null;
        foreach ($flat as $index => $row) {
            if ((int) $row['id'] === $steps_id) {
                $position = $index;
                break;
            }
        }

        $target = $position === null
            ? null
            : ($direction === 'up' ? $position - 1 : $position + 1);

        if ($target === null || !isset($flat[$target])) {
            // Genuinely at one end of the procedure. The builder greys these
            // out; reaching here means a stale page.
            return false;
        }

        $mine   = (int) $flat[$position]['plugin_glpisop_sections_id'];
        $theirs = (int) $flat[$target]['plugin_glpisop_sections_id'];

        if ($mine === $theirs) {
            $moved = $flat[$position];
            array_splice($flat, $position, 1);
            array_splice($flat, $target, 0, [$moved]);
            $new_section = $mine;
        } else {
            // Crossing a heading. The flat order is left alone on purpose —
            // what changes is which heading the step is filed under, and the
            // re-ranking below is what keeps it on the same line while that
            // happens.
            $new_section = $theirs;
        }

        self::rerank($flat, $steps_id, $new_section);

        Sop::bumpVersion($sops_id);

        return true;
    }

    /**
     * Write ranks back so that reading the steps again reproduces `$flat`.
     *
     * Ranks are per section and section order dominates, so the only way to
     * make an arbitrary flat order survive a round trip through allFor() is to
     * number each section's members by their position in that order.
     *
     * @param array<int,array<string,mixed>> $flat the intended display order
     */
    private static function rerank(array $flat, int $moved_id, int $moved_section): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ranks = [];
        foreach ($flat as $row) {
            $section = (int) $row['id'] === $moved_id
                ? $moved_section
                : (int) $row['plugin_glpisop_sections_id'];

            $rank = $ranks[$section] = ($ranks[$section] ?? 0) + 1;

            if (
                $rank === (int) $row['rank_order']
                && $section === (int) $row['plugin_glpisop_sections_id']
            ) {
                continue;
            }

            $DB->update(
                self::getTable(),
                ['rank_order' => $rank, 'plugin_glpisop_sections_id' => $section],
                ['id' => (int) $row['id']]
            );
        }
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
     * Steps that could serve as the gate for `$steps_id`.
     *
     * Only steps *earlier* in the order are offered. A cycle here would not
     * merely be wrong, it would be unresolvable: two steps each waiting on the
     * other are both permanently invisible and both permanently required.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function candidateParents(int $sops_id, int $steps_id): array
    {
        $out = [];
        foreach (self::allFor($sops_id, false) as $step) {
            if ((int) $step['id'] === $steps_id) {
                break;
            }
            $out[] = $step;
        }

        return $out;
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
     */
    public function post_addItem()
    {
        Sop::bumpVersion((int) $this->fields['plugin_glpisop_sops_id']);
        Run::recountAllFor((int) $this->fields['plugin_glpisop_sops_id']);
    }

    public function post_updateItem($history = true)
    {
        Sop::bumpVersion((int) $this->fields['plugin_glpisop_sops_id']);
        Run::recountAllFor((int) $this->fields['plugin_glpisop_sops_id']);
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

        Sop::bumpVersion((int) $this->fields['plugin_glpisop_sops_id']);

        // Every run of this SOP now has a different notion of "complete".
        Run::recountAllFor((int) $this->fields['plugin_glpisop_sops_id']);
    }
}
