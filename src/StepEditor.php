<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

/**
 * Changing a step that already exists, and the headings it sits under.
 *
 * {@see StepWriter} writes steps; this is everything a person can do to one
 * afterwards in the builder — reword it, retype it, reconfigure it, move it,
 * re-gate it, retire it, delete it — and the headings, which they can rename,
 * reorder and remove.
 *
 * The builder does all of that with one payload: the canvas as it now stands,
 * with anything absent from it deleted. That model is safe in front of a person
 * who is looking at the canvas, and it is not safe here. A model that omits a
 * step has not decided to delete it; it has written a shorter answer. So every
 * change is named — this step, this field, this value — and anything not named
 * is left alone.
 *
 * ## Answers are the thing being protected
 *
 * Three of these operations lose recorded answers, and they are the three worth
 * being careful about:
 *
 *  - **Retyping** leaves every answer already given in the old shape. "yes" sits
 *    where a number should be, on every run that ever answered it, and nothing
 *    re-reads it.
 *  - **Deleting** takes the answers with it, on every run, and cannot be undone.
 *  - **Retiring** takes none, which is why it is what to reach for instead. The
 *    step drops out of the procedure, the history stays, and it is one flag
 *    back.
 *
 * The builder warns about the second in the browser and says nothing about the
 * first. Here both count the answers at risk and refuse without an explicit
 * acknowledgement, because the person being asked to confirm is not the one
 * typing — and a model that has just been told "tidy up that procedure" will
 * otherwise read a silent success as permission.
 */
final class StepEditor
{
    /** Where a step goes when it is moved to the top of its heading. */
    public const FIRST = 'first';

    /**
     * Apply a named set of changes to one step.
     *
     * Every key is optional and absent means unchanged, with one exception:
     * `ask_when` present and empty clears the gate, because "asked every time"
     * is a thing an author asks for and cannot be said any other way.
     *
     * @param array<string,mixed> $changes
     * @param string[]            $notes
     * @return array{changed:string[],error:string,answers:int,gates:int}
     */
    public static function apply(Sop $sop, Step $step, array $changes, array &$notes): array
    {
        $steps_id = (int) $step->getID();
        $answers  = self::answers($steps_id);
        $out      = ['changed' => [], 'error' => '', 'answers' => $answers, 'gates' => 0];

        $input   = ['id' => $steps_id];
        $changed = [];

        $label = trim((string) ($changes['label'] ?? ''));
        if ($label !== '') {
            $input['label'] = mb_substr($label, 0, 250);
            $changed[]      = 'label';
        }

        if (array_key_exists('help', $changes)) {
            $input['help'] = mb_substr(trim((string) $changes['help']), 0, 1000);
            $changed[]     = 'help';
        }

        $required = StepWriter::yes($changes['required'] ?? null);
        if ($required !== null) {
            $input['is_required'] = $required ? 1 : 0;
            $changed[]            = 'required';
        }

        $active = StepWriter::yes($changes['is_active'] ?? null);
        if ($active !== null) {
            $input['is_active'] = $active ? 1 : 0;
            $changed[]          = $active ? 'reinstated' : 'retired';
        }

        // ---- type and settings

        $type    = trim((string) ($changes['type'] ?? ''));
        $retyped = $type !== '' && $type !== (string) $step->fields['step_type'];

        if ($type !== '' && !array_key_exists($type, StepWriter::types())) {
            $out['error'] = sprintf('There is no step type "%s".', $type);
            return $out;
        }

        if ($retyped && $answers > 0 && StepWriter::yes($changes['confirm_answers'] ?? null) !== true) {
            $out['error'] = sprintf(
                'That step has %d recorded answer%s, and changing its type would leave every one '
                . 'of them in the old shape on runs that are already open. Say so to the '
                . 'technician and call this again with confirm_answers "yes" if they want it '
                . 'anyway. Retiring the step and writing a new one keeps the history.',
                $answers,
                $answers === 1 ? '' : 's'
            );
            return $out;
        }

        $config  = StepWriter::pairs($changes['config'] ?? null);
        $options = [];
        foreach ((array) ($changes['options'] ?? []) as $option) {
            $option = trim((string) $option);
            if ($option !== '' && !in_array($option, $options, true)) {
                $options[] = mb_substr($option, 0, 120);
            }
        }

        $effective = $type !== '' ? $type : (string) $step->fields['step_type'];

        if ($retyped) {
            $input['step_type'] = $type;
            $changed[]          = 'type';
        }

        // Settings are collected whenever any arrived, and always when the type
        // changed: StepOptions::collect() keeps only the keys belonging to the
        // type, so a step retyped without new settings must still be recollected
        // or it keeps a min and a max that nothing reads.
        if ($config !== [] || $options !== [] || $retyped) {
            $existing = Step::config($step->fields);

            // The options a choice step already has, where none were given —
            // so "make this required" does not empty the list it offers.
            if ($options === [] && StepType::isChoice($effective)) {
                $options = array_values(array_map('strval', (array) ($existing['options'] ?? [])));
            }

            if (StepType::isChoice($effective) && count($options) < 2) {
                $out['error'] = 'A choice step needs at least two options. Give them in options.';
                return $out;
            }

            $input['config_json'] = json_encode(
                StepWriter::config(
                    $sop,
                    $effective,
                    $config,
                    $options,
                    (string) ($input['label'] ?? $step->fields['label']),
                    $notes
                ),
                JSON_UNESCAPED_UNICODE
            );

            $changed[] = 'settings';
        }

        // ---- the gate

        // Only `ask_when` rewrites the clauses. The join mode is a field on the
        // step and arrives on its own whenever somebody says "any of these
        // rather than all of them" — treating that as a gate rewrite would hand
        // GateWriter an empty list and clear the very clauses being rejoined.
        $regated = array_key_exists('ask_when', $changes);

        if (array_key_exists('ask_when_mode', $changes)) {
            $mode = mb_strtolower(trim((string) $changes['ask_when_mode']));
            $input['depends_mode'] = $mode === Condition::MODE_ANY
                ? Condition::MODE_ANY
                : Condition::MODE_ALL;

            if (!$regated) {
                $changed[] = 'gate joined with ' . $input['depends_mode'];
            }
        }

        // ---- placement

        $moved = self::placement($sop, $step, $changes, $input, $changed, $out);
        if ($out['error'] !== '') {
            return $out;
        }

        if ($changed === [] && !$regated && !$moved) {
            $out['error'] = 'Nothing was asked for, so nothing changed.';
            return $out;
        }

        // One recount for the whole edit, not one per field — and, more to the
        // point, not one between the step changing type and its gate being
        // rewritten to match.
        $failed = false;
        Sop::deferStructureChange(
            (int) $sop->getID(),
            static function () use ($sop, $step, $input, $changes, $regated, &$out, &$notes, &$failed): void {
                if (count($input) > 1 && !$step->update($input)) {
                    $failed = true;
                    return;
                }

                if (!$regated) {
                    return;
                }

                $clauses = GateWriter::plan(
                    (array) ($changes['ask_when'] ?? []),
                    [],
                    [],
                    0,
                    (string) $step->fields['label'],
                    $notes
                );

                $out['gates'] = GateWriter::replace(
                    $sop,
                    (int) $step->getID(),
                    $clauses,
                    [],
                    (string) $step->fields['label'],
                    $notes
                );
            }
        );

        if ($failed) {
            $out['error'] = 'That step could not be changed.';
            return $out;
        }

        if ($regated) {
            $changed[] = $out['gates'] > 0 ? 'gate' : 'gate cleared';
        }

        $out['changed'] = $changed;

        return $out;
    }

    /**
     * Where the step sits: under which heading, and after which step.
     *
     * A heading is named rather than numbered — the same string a written step
     * carries — and one that does not exist yet is created, so "put it under
     * Handback" works whether or not anybody has written that heading. An empty
     * string unfiles the step, which is what section 0 has always meant.
     *
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $input   the update being built, added to in place
     * @param string[]            $changed
     * @param array<string,mixed> $out     the report, for the error
     */
    private static function placement(
        Sop $sop,
        Step $step,
        array $changes,
        array &$input,
        array &$changed,
        array &$out
    ): bool {
        $sops_id = (int) $sop->getID();
        $moved   = false;

        $sections_id = (int) $step->fields['plugin_glpisop_sections_id'];

        if (array_key_exists('section', $changes)) {
            $sections_id = self::sectionFor($sops_id, trim((string) $changes['section']));
            if ($sections_id !== (int) $step->fields['plugin_glpisop_sections_id']) {
                $input['plugin_glpisop_sections_id'] = $sections_id;
                $changed[]                           = 'heading';
                $moved                               = true;
            }
        }

        if (!array_key_exists('after_step', $changes)) {
            return $moved;
        }

        $after = trim((string) $changes['after_step']);

        if ($after === self::FIRST || $after === '0' || $after === '') {
            $input['rank_order'] = 0;
            self::renumber($sops_id, $sections_id, (int) $step->getID(), 0);
            $changed[] = 'position';

            return true;
        }

        if (!ctype_digit($after)) {
            $out['error'] = 'after_step is the id of the step to put this one after, or "first".';
            return $moved;
        }

        $anchor = new Step();
        if ((int) $after === (int) $step->getID()
            || !$anchor->getFromDB((int) $after)
            || (int) $anchor->fields['plugin_glpisop_sops_id'] !== $sops_id
        ) {
            $out['error'] = sprintf('Step %s is not a step of this procedure.', $after);
            return $moved;
        }

        // A step follows the one it was put after, heading included: a move
        // that left it under its old heading would land it somewhere the
        // author did not point at, since headings dominate the order.
        $sections_id = (int) $anchor->fields['plugin_glpisop_sections_id'];
        if ($sections_id !== (int) $step->fields['plugin_glpisop_sections_id']) {
            $input['plugin_glpisop_sections_id'] = $sections_id;
        }

        $input['rank_order'] = (int) $anchor->fields['rank_order'];
        self::renumber($sops_id, $sections_id, (int) $step->getID(), (int) $anchor->fields['rank_order']);
        $changed[] = 'position';

        return true;
    }

    /**
     * Make room at a rank inside a heading.
     *
     * Everything at or after the target rank moves down one, excluding the step
     * being placed — which is then written at that rank by the caller's own
     * update. Ranks are not compacted afterwards: {@see Step::allFor()} orders
     * by rank and then by id, so gaps are invisible, and leaving them means a
     * move touches the rows below it rather than the whole procedure.
     */
    private static function renumber(int $sops_id, int $sections_id, int $steps_id, int $from): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['id', 'rank_order'],
                'FROM'   => Step::getTable(),
                'WHERE'  => [
                    'plugin_glpisop_sops_id'     => $sops_id,
                    'plugin_glpisop_sections_id' => $sections_id,
                    'id'                         => ['<>', $steps_id],
                    'rank_order'                 => ['>=', $from],
                ],
            ]) as $row
        ) {
            $DB->update(
                Step::getTable(),
                ['rank_order' => (int) $row['rank_order'] + 1],
                ['id' => (int) $row['id']]
            );
        }
    }

    /**
     * The heading of this name, created if it is new.
     *
     * Case-folded, like every other match on a heading here, so that "Checks"
     * and "checks" are one heading rather than two.
     */
    public static function sectionFor(int $sops_id, string $name): int
    {
        if (trim($name) === '') {
            return 0;
        }

        foreach (Section::allFor($sops_id) as $sections_id => $section) {
            if (mb_strtolower(trim((string) $section['name'])) === mb_strtolower(trim($name))) {
                return (int) $sections_id;
            }
        }

        return (int) (new Section())->add([
            'plugin_glpisop_sops_id' => $sops_id,
            'name'                   => mb_substr(trim($name), 0, 255),
            'content'                => '',
            'rank_order'             => Section::nextRank($sops_id),
        ]);
    }

    /**
     * Put written steps after a given step, in the order they were written.
     *
     * `sop_add_steps` appends, which is right for "and one more thing" and
     * wrong for "add the licence check before we disable the account". The
     * whole batch moves together and keeps its order, because a batch is one
     * thought.
     *
     * @param int[]    $steps_id the ids just written, in order
     * @param string[] $notes
     */
    public static function placeAfter(Sop $sop, array $steps_id, int $after_id, array &$notes): bool
    {
        $anchor = new Step();

        if ($after_id <= 0
            || !$anchor->getFromDB($after_id)
            || (int) $anchor->fields['plugin_glpisop_sops_id'] !== (int) $sop->getID()
        ) {
            $notes[] = sprintf(
                __('Step %d is not part of this procedure, so the new steps were added at the '
                    . 'end instead.', 'glpisop'),
                $after_id
            );
            return false;
        }

        $sops_id     = (int) $sop->getID();
        $sections_id = (int) $anchor->fields['plugin_glpisop_sections_id'];
        $rank        = (int) $anchor->fields['rank_order'];

        Sop::deferStructureChange($sops_id, static function () use (
            $sops_id,
            $sections_id,
            $steps_id,
            $rank
        ): void {
            $offset = 0;
            foreach ($steps_id as $id) {
                self::renumber($sops_id, $sections_id, (int) $id, $rank + $offset);
                (new Step())->update([
                    'id'                         => (int) $id,
                    'plugin_glpisop_sections_id' => $sections_id,
                    'rank_order'                 => $rank + $offset,
                ]);
                $offset++;
            }
        });

        return true;
    }

    /**
     * Delete a step outright, with everything recorded against it.
     *
     * Through the model rather than the table, so {@see Step::cleanDBonPurge()}
     * takes the answers, this step's own gate, and every clause elsewhere that
     * was about it — that last one being the part that matters, since a clause
     * pointing at a step that no longer exists would leave its children hidden
     * from every run under `all`.
     *
     * @return array{deleted:bool,answers:int,gates:int}
     */
    public static function delete(Step $step): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $steps_id = (int) $step->getID();
        $answers  = self::answers($steps_id);

        $gates = $DB->request([
            'FROM'  => Condition::getTable(),
            'WHERE' => ['source' => Condition::SRC_STEP, 'depends_steps_id' => $steps_id],
        ])->count();

        return [
            'deleted' => (bool) $step->delete(['id' => $steps_id], true),
            'answers' => $answers,
            'gates'   => $gates,
        ];
    }

    /** How many runs have recorded something against this step. */
    public static function answers(int $steps_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        return $DB->request([
            'FROM'  => Answer::getTable(),
            'WHERE' => ['plugin_glpisop_steps_id' => $steps_id],
        ])->count();
    }

    /**
     * Rename, describe, reorder or remove a heading.
     *
     * Removing one keeps its steps — {@see Section::cleanDBonPurge()} moves
     * them back to the unfiled group — so this is the one deletion here that
     * loses nothing but a name, and it does not ask for a confirmation it would
     * only be teaching the model to send.
     *
     * @param array<string,mixed> $changes
     * @return array{changed:string[],error:string}
     */
    public static function section(Sop $sop, Section $section, array $changes): array
    {
        $out     = ['changed' => [], 'error' => ''];
        $sops_id = (int) $sop->getID();

        if (StepWriter::yes($changes['remove'] ?? null) === true) {
            if (!$section->delete(['id' => (int) $section->getID()], true)) {
                $out['error'] = 'That heading could not be removed.';
                return $out;
            }

            $out['changed'] = ['removed'];
            return $out;
        }

        $input = ['id' => (int) $section->getID()];

        $name = trim((string) ($changes['name'] ?? ''));
        if ($name !== '') {
            $input['name']  = mb_substr($name, 0, 255);
            $out['changed'][] = 'name';
        }

        if (array_key_exists('description', $changes)) {
            $input['content'] = trim((string) $changes['description']);
            $out['changed'][] = 'description';
        }

        if (array_key_exists('after_section', $changes)) {
            $after = trim((string) $changes['after_section']);
            $rank  = 0;

            if ($after !== self::FIRST && $after !== '' && $after !== '0') {
                $anchor = new Section();
                if (!ctype_digit($after)
                    || !$anchor->getFromDB((int) $after)
                    || (int) $anchor->fields['plugin_glpisop_sops_id'] !== $sops_id
                ) {
                    $out['error'] = sprintf('Heading %s is not part of this procedure.', $after);
                    return $out;
                }

                $rank = (int) $anchor->fields['rank_order'];
            }

            self::renumberSections($sops_id, (int) $section->getID(), $rank);
            $input['rank_order'] = $rank;
            $out['changed'][]    = 'position';
        }

        if ($out['changed'] === []) {
            $out['error'] = 'Nothing was asked for, so nothing changed.';
            return $out;
        }

        if (count($input) > 1 && !$section->update($input)) {
            $out['error'] = 'That heading could not be changed.';
        }

        return $out;
    }

    /** Make room at a rank among the headings. */
    private static function renumberSections(int $sops_id, int $sections_id, int $from): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['id', 'rank_order'],
                'FROM'   => Section::getTable(),
                'WHERE'  => [
                    'plugin_glpisop_sops_id' => $sops_id,
                    'id'                     => ['<>', $sections_id],
                    'rank_order'             => ['>=', $from],
                ],
            ]) as $row
        ) {
            $DB->update(
                Section::getTable(),
                ['rank_order' => (int) $row['rank_order'] + 1],
                ['id' => (int) $row['id']]
            );
        }
    }
}
