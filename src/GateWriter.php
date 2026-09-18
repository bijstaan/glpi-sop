<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

/**
 * Gates arriving from somewhere that is not the procedure editor.
 *
 * A step is asked when its clauses hold. The editor builds those from a canvas
 * the author is looking at, with a picker for every value; this builds the same
 * rows from prose, and the difference between the two is everything below.
 *
 * ## The subject is one string
 *
 * `step:q1`, `field:itilcategories_id`, `approval:global_validation` — the same
 * `source:target` encoding {@see Condition::splitSubject()} already reads off
 * the canvas. One string rather than three mutually exclusive keys because the
 * three dialects behind glpi-ai's portability rule all handle a string, and
 * only some of them handle "exactly one of these three may be set".
 *
 * All three of {@see Condition}'s sources are offered. An earlier answer is the
 * common one and the only one a procedure can be sure of; the other two are how
 * an author says "this step is only for hardware requests" or "only once the
 * manager has approved it", and a model that cannot write them writes a step
 * saying "if this is a hardware request…" instead, which is a procedure asking
 * the reader to evaluate its conditions by hand.
 *
 * ## Names, not ids
 *
 * A field clause compares against a category, a group, a location, an urgency —
 * all of them ids. A model does not know ids and would have to guess, and a
 * guessed id is not detectably wrong: it saves, and it gates a step on the
 * wrong category for as long as nobody re-reads the procedure. So a clause is
 * written in names and {@see Lookup} resolves them, which turns an invention
 * into a reportable error and an ambiguity into a question.
 *
 * ## What is refused
 *
 * A clause that could never hold is dropped rather than stored, with the reason
 * handed back. The editor cannot produce one — it offers the parent's own
 * options and the criteria that belong to this procedure's itemtypes — so this
 * has no counterpart in {@see BuilderSave}, and it is the whole value of this
 * class: a gate waiting for an option that does not exist saves cleanly and
 * never opens, which in a run is indistinguishable from a procedure that has no
 * branch there. Dropped means the step is asked every time — a spare question
 * rather than a missing one, the same direction {@see Condition::satisfied()}
 * fails in.
 */
final class GateWriter
{
    /**
     * Clauses one written gate may carry.
     *
     * The builder has no such cap and does not need one — an author adding a
     * ninth clause has looked at the eight above it. This is a guard against a
     * model that has decided the way to express "usually" is thirty conditions,
     * and it is set well above any gate a person writes so that it is a
     * runaway stop rather than a limit anybody meets. Hitting it drops the
     * clauses past it, which widens the gate rather than narrowing it, so the
     * note that says so is the part that matters.
     */
    public const MAX_CONDITIONS = 8;

    /** The approval questions, as a subject reads them. */
    public const APPROVAL_STATUS = 'global_validation';

    /**
     * The operators a written gate may use on an earlier answer.
     *
     * {@see Step::operators()}, described for a model rather than labelled for
     * the builder's dropdown, and ordered for one rather than for a dropdown:
     * `eq` first, because a branch is nearly always "answered this way", and a
     * model reads an enum as a list of preferences as well as a list of values.
     *
     * The tokens are the plugin's own. A friendlier vocabulary here would be a
     * translation layer between the prompt and the column, and the first time
     * the two disagreed the symptom would be a branch that never opens.
     *
     * @return array<string,string> operator => what it means
     */
    public static function stepOperators(): array
    {
        return [
            Step::OP_EQ        => 'the earlier answer is exactly this value',
            Step::OP_NE        => 'the earlier step was answered, and not with this value',
            Step::OP_CHECKED   => 'the earlier step was answered at all; leave value empty',
            Step::OP_UNCHECKED => 'the earlier step was skipped or never reached; leave value empty',
            Step::OP_GT        => 'a number step answered with more than this',
            Step::OP_LT        => 'a number step answered with less than this',
        ];
    }

    /** The answer operators, described, for a system instruction. */
    public static function stepGuide(): string
    {
        $lines = [];
        foreach (self::stepOperators() as $operator => $means) {
            $lines[] = sprintf('  - %s: %s', $operator, $means);
        }

        return implode("\n", $lines);
    }

    /**
     * Every operator any subject accepts.
     *
     * The enum offered to a model is the union, because JSON Schema cannot say
     * "these operators if the subject is a step, those if it is a field". Which
     * of them actually applies is checked per clause — see {@see operatorFor()}
     * — and a mismatch is dropped and reported rather than stored, since an
     * operator the evaluator does not recognise reads as *satisfied* and would
     * open a branch unconditionally.
     *
     * @return string[]
     */
    public static function operators(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::stepOperators()),
            array_keys(Trigger::conditionsFor('itilcategories_id')),
            array_keys(Trigger::conditionsFor('name'))
        )));
    }

    /**
     * The subjects a clause may name, described.
     *
     * Written out rather than left to the model to infer, because two of the
     * three are plugin vocabulary: `field:` names a column of the ticket and
     * `approval:` a question about its approvals, and neither is guessable from
     * an example.
     */
    public static function subjectGuide(): string
    {
        $fields = [];
        foreach (Trigger::criteria() as $key => $definition) {
            $fields[] = $key;
        }

        return implode("\n", [
            '  - step:<ref or id> — an answer given earlier in this procedure. The ref you gave a',
            '    step in this call, or the numeric id sop_library returns for one already in it.',
            '  - field:<criterion> — something about the ticket itself, one of: '
                . implode(', ', $fields) . '.',
            '  - approval:' . self::APPROVAL_STATUS . ', approval:approved_by_user or',
            '    approval:approved_by_group — the state of the approvals on the ticket.',
        ]);
    }

    /**
     * The JSON Schema fragment for one step's gate.
     *
     * @return array<string,mixed>
     */
    public static function schema(): array
    {
        return [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'subject'  => [
                        'type'        => 'string',
                        'description' => 'What is tested: "step:<ref or id>" for an answer given '
                            . 'earlier in this procedure, "field:<criterion>" for something '
                            . 'about the ticket itself (' . implode(', ', array_keys(Trigger::criteria()))
                            . '), or "approval:' . self::APPROVAL_STATUS . '", '
                            . '"approval:approved_by_user", "approval:approved_by_group" for its '
                            . 'approvals.',
                    ],
                    'operator' => [
                        'type'        => 'string',
                        'enum'        => self::operators(),
                        'description' => 'How it is compared. An answer takes eq, ne, checked, '
                            . 'unchecked, gt or lt; a ticket field or an approval takes is or '
                            . 'is_not, a category, location or entity also takes under (is, or '
                            . 'is under), and the title and description take contains, '
                            . 'not_contains or regex.',
                    ],
                    'value'    => [
                        'type'        => 'string',
                        'description' => 'What it is compared against, written as a person would '
                            . 'say it: "yes" or "no" for a yes/no step, one of the options for a '
                            . 'choice step, a number for a number step, and for a ticket field '
                            . 'or an approval the name — "Hardware", "Service Desk", "High", '
                            . '"Granted". Empty for checked and unchecked.',
                    ],
                ],
                'required'   => ['subject', 'operator', 'value'],
            ],
            'description' => 'Ask this step only when these hold. Empty for a step that is always '
                . 'asked. Use this instead of writing "if applicable" into a label: the person '
                . 'following the procedure is the one who does not know whether it applies. A '
                . 'clause naming an earlier answer may only point at a step above this one.',
        ];
    }

    /**
     * Read a gate, without touching the database.
     *
     * Everything that can be settled from the answer alone is settled here: the
     * subject splits, the operator belongs to it, a step clause points backwards
     * and compares against something its parent can actually answer. What is
     * left — the id behind a name, whether a step id belongs to this procedure,
     * whether a criterion applies to what this procedure runs on — needs the
     * database and waits for {@see write()}.
     *
     * `$by_ref` and `$steps` describe the batch being written, and are empty
     * when a gate is being set on a step that already exists: a clause can then
     * only name other steps by id, which is what {@see StepEditor} passes.
     *
     * @param array<int,mixed>               $raw    the clauses as they arrived
     * @param array<int,array<string,mixed>> $steps  the batch, validated
     * @param array<string,int>              $by_ref ref => position in $steps
     * @param int                            $index  position of the step being gated
     * @param string[]                       $notes
     * @return array<int,array<string,mixed>> clauses to write
     */
    public static function plan(
        array $raw,
        array $steps,
        array $by_ref,
        int $index,
        string $label,
        array &$notes
    ): array {
        $clauses = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $subject  = trim((string) ($entry['subject'] ?? ''));
            $operator = mb_strtolower(trim((string) ($entry['operator'] ?? '')));
            $value    = trim((string) ($entry['value'] ?? ''));

            if ($subject === '') {
                $notes[] = sprintf(
                    __('A condition on “%s” names nothing, and was dropped.', 'glpisop'),
                    $label
                );
                continue;
            }

            // A bare ref is unambiguous — refs cannot contain a colon — and is
            // the shape a model reaches for first. Reading it as a step rather
            // than refusing it costs nothing and saves a retry.
            if (!str_contains($subject, ':')) {
                $subject = Condition::SRC_STEP . ':' . $subject;
            }

            [$source, $target] = Condition::splitSubject($subject);

            if (!array_key_exists($source, Condition::sources())) {
                $notes[] = sprintf(
                    __('The condition "%1$s" on “%2$s” is of a kind this version does not have, '
                        . 'and was dropped.', 'glpisop'),
                    $subject,
                    $label
                );
                continue;
            }

            $clause = $source === Condition::SRC_STEP
                ? self::planStep($target, $operator, $value, $steps, $by_ref, $index, $label, $notes)
                : self::planCriterion($source, $target, $operator, $value, $label, $notes);

            if ($clause !== null) {
                $clauses[] = $clause;
            }
        }

        if (count($clauses) > self::MAX_CONDITIONS) {
            $notes[] = sprintf(
                __('The gate on “%1$s” had %2$d conditions; the first %3$d were kept.', 'glpisop'),
                $label,
                count($clauses),
                self::MAX_CONDITIONS
            );
            $clauses = array_slice($clauses, 0, self::MAX_CONDITIONS);
        }

        return $clauses;
    }

    /**
     * A clause about an earlier answer.
     *
     * @param array<int,array<string,mixed>> $steps
     * @param array<string,int>              $by_ref
     * @param string[]                       $notes
     * @return array<string,mixed>|null
     */
    private static function planStep(
        string $target,
        string $operator,
        string $value,
        array $steps,
        array $by_ref,
        int $index,
        string $label,
        array &$notes
    ): ?array {
        if (!array_key_exists($operator, Step::operators())) {
            $notes[] = sprintf(
                __('The gate on “%s” compares an answer in a way this version does not have, and '
                    . 'was dropped.', 'glpisop'),
                $label
            );
            return null;
        }

        $clause = [
            'source'   => Condition::SRC_STEP,
            'target'   => '',
            'steps_id' => 0,
            'index'    => -1,
            'op'       => $operator,
            'value'    => $value,
        ];

        // A step already in the procedure. Checked against the database in
        // write(), which is where the SOP is known.
        if (ctype_digit($target)) {
            $clause['steps_id'] = (int) $target;
            return $clause;
        }

        $key = mb_strtolower($target);
        if (!isset($by_ref[$key])) {
            $notes[] = sprintf(
                __('The gate on “%1$s” waits on a step called "%2$s", which is not one of the '
                    . 'steps written, and was dropped.', 'glpisop'),
                $label,
                $target
            );
            return null;
        }

        $parent = $by_ref[$key];

        // Backwards only. A gate pointing down the list is a branch written
        // above the question that decides it, which reads as a procedure asking
        // people to answer in an order it then does not follow. Visibility
        // resolves the graph either way, so this costs a condition rather than
        // a run.
        if ($parent >= $index) {
            $notes[] = sprintf(
                __('The gate on “%s” waits on a step that comes after it, and was dropped.',
                    'glpisop'),
                $label
            );
            return null;
        }

        $fitted = self::fitAnswer(
            $operator,
            $value,
            (string) $steps[$parent]['type'],
            (array) $steps[$parent]['options'],
            $label,
            (string) $steps[$parent]['label'],
            $notes
        );

        if ($fitted === null) {
            return null;
        }

        $clause['index'] = $parent;
        $clause['value'] = $fitted;

        return $clause;
    }

    /**
     * A clause about the item or its approvals.
     *
     * The value is left as written; it is a name, and turning it into an id
     * needs the database. What is settled here is that the criterion exists and
     * that the operator belongs to it — both of which are the plugin's own
     * vocabulary and neither of which changes per instance.
     *
     * @param string[] $notes
     * @return array<string,mixed>|null
     */
    private static function planCriterion(
        string $source,
        string $target,
        string $operator,
        string $value,
        string $label,
        array &$notes
    ): ?array {
        if (Condition::definition($source, $target) === null) {
            $notes[] = sprintf(
                __('The condition "%1$s:%2$s" on “%3$s” tests something this version does not '
                    . 'understand, and was dropped.', 'glpisop'),
                $source,
                $target,
                $label
            );
            return null;
        }

        if (!array_key_exists($operator, Condition::operatorsFor($source, $target))) {
            $notes[] = sprintf(
                __('The gate on “%1$s” compares %2$s in a way that does not apply to it, and was '
                    . 'dropped.', 'glpisop'),
                $label,
                $target
            );
            return null;
        }

        if ($value === '') {
            $notes[] = sprintf(
                __('The gate on “%s” compares against nothing, and was dropped.', 'glpisop'),
                $label
            );
            return null;
        }

        return [
            'source'   => $source,
            'target'   => $target,
            'steps_id' => 0,
            'index'    => -1,
            'op'       => $operator,
            'value'    => $value,
        ];
    }

    /**
     * A clause's value, as the step it compares against actually stores answers.
     *
     * The builder cannot produce a mismatch here — it offers the parent's own
     * options in a dropdown — so this has no counterpart in {@see BuilderSave}.
     * A model writing "Laptop" against a choice step whose option reads
     * "laptop" produces a clause that is valid, saveable, and can never hold:
     * the branch simply never appears, which is indistinguishable from the
     * procedure not having one.
     *
     * Null means the clause cannot be made to hold and should be dropped, with
     * the reason already appended to `$notes`.
     *
     * @param string[] $options the parent's choices, where it has any
     * @param string[] $notes
     */
    public static function fitAnswer(
        string $operator,
        string $value,
        string $type,
        array $options,
        string $label,
        string $parent,
        array &$notes
    ): ?string {
        // The value belongs to the operator, not to the clause — the same rule
        // BuilderSave applies, so that a description never shows a comparison
        // that is not happening.
        if (!Step::operatorNeedsValue($operator)) {
            return '';
        }

        if ($value === '') {
            $notes[] = sprintf(
                __('The gate on “%s” compares an answer against nothing, and was dropped.',
                    'glpisop'),
                $label
            );
            return null;
        }

        if ($type === StepType::CHECK) {
            $notes[] = sprintf(
                __('The gate on “%1$s” compares “%2$s” against a value, but a checkbox is only '
                    . 'ticked or not — use checked or unchecked. It was dropped.', 'glpisop'),
                $label,
                $parent
            );
            return null;
        }

        if ($type === StepType::YESNO) {
            $answer = StepWriter::yes($value);
            if ($answer === null) {
                $notes[] = sprintf(
                    __('The gate on “%1$s” waits for “%2$s” to be answered "%3$s", which is not '
                        . 'yes or no, and was dropped.', 'glpisop'),
                    $label,
                    $parent,
                    $value
                );
                return null;
            }

            // The token, not the word: see StepType::YESNO.
            return $answer ? StepType::YES : StepType::NO;
        }

        if (StepType::isChoice($type)) {
            foreach ($options as $option) {
                if (mb_strtolower((string) $option) === mb_strtolower($value)) {
                    return (string) $option;
                }
            }

            $notes[] = sprintf(
                __('The gate on “%1$s” waits for “%2$s” to be answered "%3$s", which is not one '
                    . 'of its options, and was dropped.', 'glpisop'),
                $label,
                $parent,
                $value
            );
            return null;
        }

        if ($operator === Step::OP_GT || $operator === Step::OP_LT) {
            if ($type !== StepType::NUMBER || !is_numeric($value)) {
                $notes[] = sprintf(
                    __('The gate on “%1$s” compares “%2$s” as a number, which it does not answer '
                        . 'with, and was dropped.', 'glpisop'),
                    $label,
                    $parent
                );
                return null;
            }

            return $value;
        }

        // The column is a VARCHAR(255), and a value stored truncated is a
        // comparison that can never be equal — a branch that saves and never
        // opens, which is the failure this whole function exists to prevent.
        if (mb_strlen($value) > 255) {
            $notes[] = sprintf(
                __('The gate on “%s” compares against more text than a condition can hold, and '
                    . 'was dropped.', 'glpisop'),
                $label
            );
            return null;
        }

        // Steps answered with a record are answered with its id, so a clause
        // naming one in words is a comparison the answer can never satisfy.
        // A user can be named, because Lookup can find them; an asset or a
        // document cannot, because "the laptop" is not a search.
        if ($type === StepType::USER) {
            $failure = '';
            $users_id = Lookup::user($value, $failure);
            if ($users_id <= 0) {
                $notes[] = sprintf(
                    __('The gate on “%1$s” names %2$s', 'glpisop'),
                    $label,
                    Lookup::say($failure, __('user', 'glpisop'), $value)
                );
                return null;
            }

            return (string) $users_id;
        }

        if (in_array($type, [StepType::ASSET, StepType::DOCUMENT, StepType::TICKET], true)
            && !ctype_digit($value)
        ) {
            $notes[] = sprintf(
                __('The gate on “%1$s” compares “%2$s” against a name, but that step is answered '
                    . 'with a record. It was dropped.', 'glpisop'),
                $label,
                $parent
            );
            return null;
        }

        return $value;
    }

    // --------------------------------------------------------------- writing

    /**
     * Write a step's clauses, resolving everything that needed the database.
     *
     * `$ids` maps a position in the batch to the step id written for it, and is
     * empty when the gate is being set on a step that already exists.
     *
     * @param array<int,array<string,mixed>> $clauses as returned by plan()
     * @param array<int,int>                 $ids     position in the batch => step id
     * @param string[]                       $notes
     * @return int clauses written
     */
    public static function write(
        Sop $sop,
        int $steps_id,
        array $clauses,
        array $ids,
        string $label,
        array &$notes
    ): int {
        $written = 0;
        $rank    = 0;

        foreach ($clauses as $clause) {
            $source = (string) $clause['source'];
            $row    = [
                'plugin_glpisop_steps_id' => $steps_id,
                'source'                  => $source,
                'depends_steps_id'        => 0,
                'criterion'               => '',
                'match_condition'         => (string) $clause['op'],
                'value'                   => (string) $clause['value'],
            ];

            if ($source === Condition::SRC_STEP) {
                $resolved = self::resolveStep($sop, $steps_id, $clause, $ids, $label, $notes);
                if ($resolved === null) {
                    continue;
                }

                $row['depends_steps_id'] = $resolved['steps_id'];
                $row['value']            = $resolved['value'];
            } else {
                $value = self::resolveCriterion(
                    $sop,
                    $source,
                    (string) $clause['target'],
                    (string) $clause['value'],
                    $label,
                    $notes
                );

                if ($value === null) {
                    continue;
                }

                $row['criterion'] = (string) $clause['target'];
                $row['value']     = $value;
            }

            $row['rank_order']    = $rank + 1;
            $row['date_creation'] = date('Y-m-d H:i:s');

            if ((new Condition())->add($row) > 0) {
                $written++;
                $rank++;
            }
        }

        return $written;
    }

    /**
     * Replace the gate on a step with this one.
     *
     * Replaced rather than reconciled, for the reason {@see BuilderSave} gives:
     * a clause has no identity an author would recognise — it is three choices,
     * and changing any of them makes it a different clause.
     *
     * The delete happens only once something is known to be writable in its
     * place, so a gate is never cleared by a call that then fails validation
     * and leaves the step asked unconditionally. Clearing it *deliberately* is
     * an empty list, which is a different thing and reaches here as one.
     *
     * @param array<int,array<string,mixed>> $clauses
     * @param array<int,int>                 $ids
     * @param string[]                       $notes
     * @return int clauses written
     */
    public static function replace(
        Sop $sop,
        int $steps_id,
        array $clauses,
        array $ids,
        string $label,
        array &$notes
    ): int {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(Condition::getTable(), ['plugin_glpisop_steps_id' => $steps_id]);

        return self::write($sop, $steps_id, $clauses, $ids, $label, $notes);
    }

    /**
     * The step a clause waits on, and the value fitted to how it answers.
     *
     * An id in an answer is a claim, not a fact — the same reading
     * {@see BuilderSave::existingId()} takes of a key from the browser. A step
     * id belonging to another procedure would put this SOP's branch behind a
     * question nobody following it is ever asked.
     *
     * @param array<string,mixed> $clause
     * @param array<int,int>      $ids
     * @param string[]            $notes
     * @return array{steps_id:int,value:string}|null
     */
    private static function resolveStep(
        Sop $sop,
        int $steps_id,
        array $clause,
        array $ids,
        string $label,
        array &$notes
    ): ?array {
        $parent_id = (int) $clause['steps_id'];
        $operator  = (string) $clause['op'];
        $value     = (string) $clause['value'];

        if ($parent_id <= 0) {
            $parent_id = $ids[(int) $clause['index']] ?? 0;

            // The step it waits on failed to write. Saying so matters: the
            // branch is now unconditional, which is visible in the procedure
            // and invisible in the answer.
            if ($parent_id <= 0) {
                $notes[] = sprintf(
                    __('The gate on “%s” waits on a step that could not be written, and was '
                        . 'dropped.', 'glpisop'),
                    $label
                );
                return null;
            }

            // Already fitted in plan(), where the parent's type was known.
            return ['steps_id' => $parent_id, 'value' => $value];
        }

        if ($parent_id === $steps_id) {
            $notes[] = sprintf(
                __('The gate on “%s” waits on that same step, which it can never answer, and was '
                    . 'dropped.', 'glpisop'),
                $label
            );
            return null;
        }

        $parent = new Step();

        if (!$parent->getFromDB($parent_id)
            || (int) $parent->fields['plugin_glpisop_sops_id'] !== (int) $sop->getID()
        ) {
            $notes[] = sprintf(
                __('The gate on “%s” waits on a step that is not part of this procedure, and was '
                    . 'dropped.', 'glpisop'),
                $label
            );
            return null;
        }

        $config = Step::config($parent->fields);
        $fitted = self::fitAnswer(
            $operator,
            $value,
            (string) $parent->fields['step_type'],
            (array) ($config['options'] ?? []),
            $label,
            (string) $parent->fields['label'],
            $notes
        );

        if ($fitted === null) {
            return null;
        }

        return ['steps_id' => $parent_id, 'value' => $fitted];
    }

    /**
     * A field or approval clause's value, as the column holds it.
     *
     * Also where a criterion is checked against what this procedure runs on. A
     * clause on the ticket type written onto a procedure for changes is not
     * wrong so much as unanswerable: {@see Trigger::matches()} refuses a
     * criterion the itemtype does not have, and a step gated on one would never
     * be asked.
     *
     * @param string[] $notes
     */
    private static function resolveCriterion(
        Sop $sop,
        string $source,
        string $criterion,
        string $value,
        string $label,
        array &$notes
    ): ?string {
        $itemtypes = $sop->itemtypes();
        $offered   = Condition::criteriaFor($source, $itemtypes);

        if (!array_key_exists($criterion, $offered)) {
            $notes[] = $source === Condition::SRC_APPROVAL
                ? sprintf(
                    __('The gate on “%s” waits on an approval, which nothing this procedure runs '
                        . 'on has. It was dropped.', 'glpisop'),
                    $label
                )
                : sprintf(
                    __('The gate on “%1$s” tests %2$s, which is not something a %3$s has. It was '
                        . 'dropped.', 'glpisop'),
                    $label,
                    $criterion,
                    implode('/', $itemtypes ?: ['Ticket'])
                );

            return null;
        }

        $definition = Condition::definition($source, $criterion);
        $kind       = (string) ($definition['kind'] ?? 'text');
        $failure    = '';

        switch ($kind) {
            case 'dropdown':
            case 'actor_group':
                // "Approval granted by" is a dropdown over glpi_users, whose
                // `name` column is the login. Lookup::user() is the one that
                // also knows them by the name a technician would say.
                $id = (string) $definition['table'] === 'glpi_users'
                    ? Lookup::user($value, $failure)
                    : Lookup::inTable((string) $definition['table'], $value, $failure);
                if ($id <= 0) {
                    $notes[] = sprintf(
                        __('The gate on “%1$s” names %2$s', 'glpisop'),
                        $label,
                        Lookup::say($failure, mb_strtolower((string) $definition['name']), $value)
                    );
                    return null;
                }
                return (string) $id;

            case 'priority':
                $level = Lookup::inList(Lookup::priorities(), $value, $failure);
                if ($level <= 0) {
                    $notes[] = sprintf(
                        __('The gate on “%1$s” names %2$s', 'glpisop'),
                        $label,
                        Lookup::say($failure, mb_strtolower((string) $definition['name']), $value)
                    );
                    return null;
                }
                return (string) $level;

            case 'tickettype':
                $type = Lookup::inList(Lookup::ticketTypes(), $value, $failure);
                if ($type <= 0) {
                    $notes[] = sprintf(
                        __('The gate on “%1$s” names %2$s', 'glpisop'),
                        $label,
                        Lookup::say($failure, __('ticket type', 'glpisop'), $value)
                    );
                    return null;
                }
                return (string) $type;

            case 'validation_status':
                // NONE is 0 and legitimate here: "not subject to approval" is a
                // real state to branch on, so this cannot use 0 as its failure.
                $statuses = Lookup::validationStatuses();
                $status   = Lookup::inList($statuses, $value, $failure);
                if ($failure !== '' || !array_key_exists($status, $statuses)) {
                    $notes[] = sprintf(
                        __('The gate on “%1$s” names %2$s', 'glpisop'),
                        $label,
                        Lookup::say(Lookup::NOTHING, __('approval status', 'glpisop'), $value)
                    );
                    return null;
                }
                return (string) $status;
        }

        // Text: the title and the description, compared with contains, does not
        // contain, or a regular expression. Nothing to resolve; the column caps
        // it, and a value cut in half is a comparison that cannot be met.
        if (mb_strlen($value) > 255) {
            $notes[] = sprintf(
                __('The gate on “%s” compares against more text than a condition can hold, and '
                    . 'was dropped.', 'glpisop'),
                $label
            );
            return null;
        }

        return $value;
    }
}
