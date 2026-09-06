<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBTM;

/**
 * Which steps of a run are currently being asked.
 *
 * This is the one piece of logic the whole plugin turns on. A conditional
 * procedure has steps that do not apply, and a step that does not apply is not
 * outstanding — so every count of "how much is left", every decision about
 * whether a run is complete, and every refusal to close a ticket is downstream
 * of this function. Get it wrong in the permissive direction and enforcement
 * blocks on questions nobody was asked; get it wrong the other way and a
 * procedure reports itself complete with its own branch unanswered.
 *
 * A step's gate is a list of {@see Condition} clauses joined by its
 * `depends_mode` — all of them, or any one. A clause asks about an earlier
 * answer in this run, a field of the item, or the item's approvals; only the
 * first of those needs anything from this graph, which is why the recursion
 * lives here and the three ways of answering a clause live there.
 *
 * Visibility is inherited: a step gated on a step that is itself hidden is
 * hidden, however satisfied its own clause looks. Resolution is on demand
 * rather than in display order, because display order is not a property of the
 * branch graph — see {@see self::resolve()}.
 *
 * There is deliberately no second copy of this in the browser. Every answer
 * round-trips anyway — it has to, to be saved — so the endpoint returns the
 * recomputed visibility with each write and public/js/sop.js only applies it.
 * A client-side reimplementation would buy a few hundred milliseconds of
 * responsiveness in exchange for two sets of branch rules that can disagree
 * about what a procedure is asking, which is not a trade worth making for the
 * one piece of logic everything else depends on.
 */
final class Visibility
{
    /**
     * @param array<int,array<string,mixed>> $steps   as Step::allFor()
     * @param array<int,array<string,mixed>> $answers keyed by step id
     * @param ?CommonDBTM                    $item    the ITIL object the run is on
     * @return array<int,bool> keyed by step id
     */
    public static function evaluate(array $steps, array $answers, ?CommonDBTM $item = null): array
    {
        $byId = [];
        foreach ($steps as $step) {
            $byId[(int) $step['id']] = $step;
        }

        // Resolved on demand rather than in one forward pass over the given
        // order. A single pass would be enough *if* a parent always appeared
        // before its child, and the authoring UI does enforce that — but the
        // display order is not a property of the branch graph. Deleting a
        // section refiles its steps to the end of the procedure, which can put
        // a child ahead of the step it hangs off, and a forward pass would then
        // treat the child as ungated and quietly show it in every run. Making
        // resolution independent of the layout removes the coupling instead of
        // documenting it.
        $visible   = [];
        $resolving = [];

        foreach (array_keys($byId) as $steps_id) {
            self::resolve($steps_id, $byId, $answers, $item, $visible, $resolving);
        }

        return $visible;
    }

    /**
     * @param array<int,array<string,mixed>> $byId
     * @param array<int,array<string,mixed>> $answers
     * @param array<int,bool>                $visible
     * @param array<int,true>                $resolving
     */
    private static function resolve(
        int $steps_id,
        array $byId,
        array $answers,
        ?CommonDBTM $item,
        array &$visible,
        array &$resolving
    ): bool {
        if (isset($visible[$steps_id])) {
            return $visible[$steps_id];
        }

        // A cycle. Authoring cannot produce one — a clause may only reference an
        // earlier step — but a hand-edited row or a botched import can, and a
        // procedure that hangs the ticket form is a worse failure than one that
        // asks a question it should not.
        if (isset($resolving[$steps_id])) {
            return $visible[$steps_id] = true;
        }

        $conditions = Step::conditions($byId[$steps_id]);

        // No gate at all: asked in every run. Set before recursing so a clause
        // that reaches back to this step cannot spin.
        if ($conditions === []) {
            return $visible[$steps_id] = true;
        }

        $resolving[$steps_id] = true;

        // Clauses about earlier steps need those steps' own visibility, which
        // is the recursion. Everything else — ticket fields, approvals — is a
        // fact about the item and needs nothing from the graph.
        $resolve = function (int $parent_id) use ($byId, $answers, $item, &$visible, &$resolving): bool {
            return self::resolve($parent_id, $byId, $answers, $item, $visible, $resolving);
        };

        $mode      = Step::mode($byId[$steps_id]);
        $satisfied = $mode === Condition::MODE_ALL;

        foreach ($conditions as $condition) {
            $holds = Condition::satisfied($condition, $byId, $answers, $item, $resolve);

            if ($mode === Condition::MODE_ALL) {
                if (!$holds) {
                    $satisfied = false;
                    break;
                }
            } elseif ($holds) {
                $satisfied = true;
                break;
            }
        }

        unset($resolving[$steps_id]);

        return $visible[$steps_id] = $satisfied;
    }

    /**
     * Progress over the visible steps of a run.
     *
     * @param array<int,array<string,mixed>> $steps
     * @param array<int,array<string,mixed>> $answers
     * @param array<int,bool>                $visible
     * @return array{total:int,done:int,total_required:int,done_required:int,outstanding:int[]}
     */
    public static function progress(array $steps, array $answers, array $visible): array
    {
        $total = $done = $total_required = $done_required = 0;
        $outstanding = [];

        foreach ($steps as $step) {
            $steps_id = (int) $step['id'];
            if (!($visible[$steps_id] ?? false)) {
                continue;
            }

            $answer     = $answers[$steps_id] ?? Answer::blank($steps_id);
            $is_done    = Answer::isAnswered($answer);
            $required   = (int) ($step['is_required'] ?? 0) === 1;

            $total++;
            if ($is_done) {
                $done++;
            }

            if ($required) {
                $total_required++;
                if ($is_done) {
                    $done_required++;
                } else {
                    $outstanding[] = $steps_id;
                }
            }
        }

        return [
            'total'          => $total,
            'done'           => $done,
            'total_required' => $total_required,
            'done_required'  => $done_required,
            'outstanding'    => $outstanding,
        ];
    }
}
