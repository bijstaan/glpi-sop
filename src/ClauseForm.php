<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use Ajax;
use Dropdown;
use Html;

/**
 * The half of a criterion row that depends on the choice made in the other
 * half, for the two screens that ask an author to write one: the SOP's trigger
 * tab and a step's gate.
 *
 * Both screens used to ask the question in two stages — pick the thing being
 * tested, press a button, get the page back with the conditions and the value
 * control that belong to it. The reasoning was that the value control is a
 * select2 dropdown, a priority list, an approval status or a free-text box, and
 * that rebuilding four shapes client-side was not worth it for a form filled in
 * a handful of times.
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

    // ----------------------------------------------------------- step gates

    /**
     * A gate clause's condition and value, for one subject.
     *
     * `$subject` is the encoded "<source>:<what>" a single dropdown offers —
     * see {@see Condition::subjects()}. The operator list depends on it, and
     * the value control depends on both, so this fragment carries the wiring
     * for its own second cascade: choosing "was answered" takes the value box
     * away rather than leaving one that is quietly ignored.
     */
    public static function gateTail(int $steps_id, string $subject, ?string $operator = null): void
    {
        [$source, $target] = Condition::splitSubject($subject);

        $operators = $source === Condition::SRC_STEP
            ? Step::operators()
            : Condition::operatorsFor($source, $target);

        if ($operators === []) {
            return;
        }

        $operator = ($operator !== null && isset($operators[$operator]))
            ? $operator
            : (string) array_key_first($operators);

        $rand = mt_rand();

        echo "<div class='row g-2 align-items-end'>";

        echo "<div class='col-md-5'><label class='form-label'>"
           . __s('Condition', 'glpisop') . '</label>';
        Dropdown::showFromArray('match_condition', $operators, [
            'value' => $operator,
            'rand'  => $rand,
            'width' => '100%',
        ]);
        echo '</div>';

        echo "<div class='col-md-7' id='glpisop-gate-value$rand'>";
        self::gateValue($steps_id, $subject, $operator);
        echo '</div>';

        echo '</div>';

        Ajax::updateItemOnSelectEvent(
            "dropdown_match_condition$rand",
            "glpisop-gate-value$rand",
            self::url(),
            [
                'context'         => 'gate',
                'part'            => 'value',
                'steps_id'        => $steps_id,
                'subject'         => $subject,
                'match_condition' => '__VALUE__',
            ]
        );
    }

    /**
     * The value a gate clause is compared against.
     *
     * Two of the six step operators — "was answered" and "was not answered" —
     * are complete on their own, and {@see \GlpiPlugin\Glpisop\Condition} drops
     * the value when they are used. Showing an empty box beside them and a note
     * saying it is ignored is how an author ends up believing they wrote a
     * comparison they did not write.
     */
    public static function gateValue(int $steps_id, string $subject, string $operator): void
    {
        [$source, $target] = Condition::splitSubject($subject);

        echo "<label class='form-label'>" . __s('Value') . '</label>';

        if ($source === Condition::SRC_STEP && !Step::operatorNeedsValue($operator)) {
            echo "<div class='form-control-plaintext text-muted'>"
               . __s('Nothing to compare against.', 'glpisop') . '</div>';
            return;
        }

        if ($source === Condition::SRC_STEP) {
            // A step with a fixed set of answers — a choice, or a Yes/No —
            // offers its own answers rather than a free-text box: a branch
            // keyed on a typo is invisible until a run silently fails to open
            // it.
            $step = new Step();
            $parent = null;
            if ($step->getFromDB($steps_id)) {
                foreach (
                    Step::candidateParents(
                        (int) $step->fields['plugin_glpisop_sops_id'],
                        $steps_id
                    ) as $candidate
                ) {
                    if ((int) $candidate['id'] === (int) $target) {
                        $parent = $candidate;
                        break;
                    }
                }
            }

            $answers = $parent !== null ? StepType::branchOptions($parent) : [];
            if ($answers !== []) {
                Dropdown::showFromArray('value', $answers, ['width' => '100%']);
            } else {
                echo "<input type='text' class='form-control' name='value'>";
            }
            return;
        }

        $definition = Condition::definition($source, $target)
            ?? ['name' => $target, 'kind' => 'text'];
        Trigger::renderValueControl($definition);
    }

    /**
     * How a grouped authoring dropdown renders its *selection*, as opposed to
     * its list.
     *
     * GLPI's default prefixes the optgroup label, so a picked step reads "An
     * answer earlier in this procedure - 1. Manager has approved the hire".
     * That is right with the list open, where the groups are the navigation,
     * and wrong in the closed box: the prefix is identical for every option in
     * the group and pushes the half that identifies the choice out of the
     * visible width. Source and subject were merged into one dropdown so that
     * the choice is one question; the answer to it is the item, not the group
     * it came from.
     *
     * Emitted inline rather than added to public/js/sop.js, and that is not a
     * style choice: GLPI renders plugin scripts in the page *footer*, while
     * Dropdown::showFromArray() writes its select2 config inline where the
     * control is. A name defined in sop.js is therefore not defined yet when
     * that config runs, and select2 throws a ReferenceError into the middle of
     * the form.
     */
    public static function subjectSelectionScript(): void
    {
        echo Html::scriptBlock(<<<'JS'
            window.glpisopSubjectSelection = function (selection) {
                var text = selection && selection.element
                    ? selection.element.textContent
                    : (selection ? selection.text : '');

                return $('<span></span>').text(text);
            };
JS);
    }

    /**
     * Wire a select to the fragment it rebuilds.
     *
     * A thin wrapper so that the two callers name the same endpoint and the
     * same parameter shape, and so that "which select drives which container"
     * is one line at each call site rather than an Ajax incantation.
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
