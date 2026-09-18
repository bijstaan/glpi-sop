<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use Ajax;
use Dropdown;

/**
 * The half of a trigger's criterion row that depends on the criterion.
 *
 * The tab used to ask the question in two stages — pick the thing being tested,
 * press a button, get the page back with the conditions and the value control
 * that belong to it. The reasoning was that the value control is a select2
 * dropdown, a priority list, an approval status or a free-text box, and that
 * rebuilding four shapes client-side was not worth it for a form filled in a
 * handful of times.
 *
 * Two things were wrong with that. The small one is that it reads as an
 * unfinished form: a row of controls that do not match the thing named beside
 * them until a second button is pressed. The large one is that the trigger tab
 * is rendered *inside a tab*, and a GLPI tab is fetched by
 * ajax/common.tabs.php with a fixed parameter list — so the `criterion` the
 * button put in the page URL never reached the code that read it, and "Change
 * criterion" was a live-looking button that did nothing at all.
 *
 * So the control is rebuilt, but on the server, where the four shapes are
 * already implemented once: the select reloads this fragment over AJAX. There
 * is exactly one renderer per fragment and both the first paint and every
 * reload go through it, which is what keeps the two from drifting.
 *
 * A step's gate used to be rendered here as well, by the same two-stage
 * machinery. It is not any more — the procedure editor builds a clause from the
 * canvas in front of the author, and only its value picker still comes from the
 * server. See {@see \GlpiPlugin\Glpisop\Builder}.
 *
 * @see \GlpiPlugin\Glpisop\Trigger::renderValueControl() the shared value control
 */
final class ClauseForm
{
    /** The endpoint that re-renders these fragments. */
    public static function url(): string
    {
        return Url::to('ajax/clause.php');
    }

    // ------------------------------------------------------------- triggers

    /**
     * A trigger's condition and value, for one criterion.
     *
     * Emitted as a `row` of its own so that the container it lands in is a
     * plain column: the first paint and the AJAX reload then produce byte-identical
     * markup, and a fragment that only ever replaces the inside of one element
     * cannot leave the grid half-rewritten.
     */
    public static function triggerTail(string $criterion): void
    {
        $definition = Trigger::criteria()[$criterion] ?? null;
        if ($definition === null) {
            return;
        }

        echo "<div class='row g-2 align-items-end'>";

        echo "<div class='col-md-5'><label class='form-label'>"
           . __s('Condition', 'glpisop') . '</label>';
        Dropdown::showFromArray('condition', Trigger::conditionsFor($criterion), [
            'width' => '100%',
        ]);
        echo '</div>';

        echo "<div class='col-md-7'><label class='form-label'>" . __s('Value') . '</label>';
        Trigger::renderValueControl($definition);
        echo '</div>';

        echo '</div>';
    }

    /**
     * Wire a select to the fragment it rebuilds.
     *
     * A thin wrapper so that the endpoint and the parameter shape are named in
     * one place, and so that "which select drives which container" is one line
     * at the call site rather than an Ajax incantation.
     *
     * @param array<string,mixed> $parameters
     */
    public static function cascade(string $select_id, string $container_id, array $parameters): void
    {
        Ajax::updateItemOnSelectEvent(
            $select_id,
            $container_id,
            self::url(),
            $parameters
        );
    }
}
