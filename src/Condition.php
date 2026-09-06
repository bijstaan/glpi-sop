<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBChild;
use CommonDBTM;
use CommonITILObject;

/**
 * One clause of the gate on a step: "ask this step only when…".
 *
 * Replaces the single `depends_steps_id`/`depends_op`/`depends_value` triple
 * that used to live on the step row. That triple could express exactly one
 * thing — "an earlier step in this procedure was answered thus" — and a real
 * procedure asks about more than its own answers. Employee onboarding is the
 * case that broke it: whether a step applies depends on what the request
 * arrived as (its category, who raised it) and on whether the manager actually
 * approved it in GLPI, neither of which is a checkbox somebody ticked.
 *
 * Three sources, and the split is by *where the fact lives* rather than by what
 * it means:
 *
 *  - `step`     — an earlier answer in this run. What the old triple did.
 *  - `field`    — a field of the item the run is on. Shares its whole
 *                 vocabulary with {@see Trigger}: the same criteria, the same
 *                 conditions, the same matcher. An author who has written a
 *                 trigger already knows how to write one of these, and there is
 *                 one implementation to be wrong rather than two.
 *  - `approval` — the item's ITIL approvals. Distinct from `field` because an
 *                 approval is not a column: it is a set of rows, and "the
 *                 manager approved this" is a question about who granted what,
 *                 not about a value on the ticket.
 *
 * Clauses combine under the step's `depends_mode`, which is `all` (AND) or
 * `any` (OR). One level, no parentheses — a step with a mixed expression is
 * expressed by nesting, exactly as the one-gate model expressed "A and B"
 * before. What has changed is that the flat case, which is nearly all of them,
 * no longer needs the nesting.
 *
 * A clause this build cannot evaluate — an unknown source, a field the itemtype
 * does not have, an approval on an item with no approval model — counts as
 * *satisfied*. That is the same reading {@see Visibility} takes of an
 * unrecognised operator, and for the same reason: showing a step asks a
 * question that may not have been needed, hiding one silently drops a question
 * that was.
 */
class Condition extends CommonDBChild
{
    public static $rightname = 'plugin_glpisop_sop';

    public static $itemtype = Step::class;
    public static $items_id = 'plugin_glpisop_steps_id';

    public const SRC_STEP     = 'step';
    public const SRC_FIELD    = 'field';
    public const SRC_APPROVAL = 'approval';

    /** How a step's clauses combine. */
    public const MODE_ALL = 'all';
    public const MODE_ANY = 'any';

    public static function getTypeName($nb = 0)
    {
        return _n('Condition', 'Conditions', $nb, 'glpisop');
    }

    /** @return array<string,string> */
    public static function sources(): array
    {
        return [
            self::SRC_STEP     => __('an answer earlier in this procedure', 'glpisop'),
            self::SRC_FIELD    => __('a field of the ticket itself', 'glpisop'),
            self::SRC_APPROVAL => __('an approval on the ticket', 'glpisop'),
        ];
    }

    /** @return array<string,string> */
    public static function modes(): array
    {
        return [
            self::MODE_ALL => __('every one of them holds', 'glpisop'),
            self::MODE_ANY => __('any one of them holds', 'glpisop'),
        ];
    }

    // ------------------------------------------------------------ approvals

    /**
     * What an author may ask about the item's approvals.
     *
     * Three questions, and they are the three a procedure actually turns on:
     * has this been approved at all, did a named person grant it, did anyone in
     * a named group grant it. Deliberately not a field-picker over the
     * validation table — `submission_date` and `itilvalidationtemplates_id` are
     * columns, not decisions.
     *
     * @return array<string,array{name:string,kind:string,table?:string}>
     */
    public static function approvalCriteria(): array
    {
        return [
            'global_validation' => [
                'name' => __('Approval status', 'glpisop'),
                'kind' => 'validation_status',
            ],
            'approved_by_user' => [
                'name'  => __('Approval granted by', 'glpisop'),
                'kind'  => 'dropdown',
                'table' => 'glpi_users',
            ],
            'approved_by_group' => [
                'name'  => __('Approval granted by a member of', 'glpisop'),
                'kind'  => 'dropdown',
                'table' => 'glpi_groups',
            ],
        ];
    }

    /**
     * The four states GLPI's `global_validation` can hold.
     *
     * All four, including "not subject to approval": a clause decides whether a
     * step is *asked*, and "nobody requested an approval" is a legitimate thing
     * to branch on. {@see Approval::requirableStatuses()} is the shorter list,
     * for the approval step type, where it is not.
     */
    public static function validationStatuses(): array
    {
        return Approval::allStatuses();
    }

    // ------------------------------------------------------------ vocabulary

    /**
     * The criteria offered for one source, given what the SOP is written for.
     *
     * @param string[] $itemtypes
     * @return array<string,string> criterion => label
     */
    public static function criteriaFor(string $source, array $itemtypes): array
    {
        $out = [];

        if ($source === self::SRC_FIELD) {
            foreach ($itemtypes as $itemtype) {
                foreach (Trigger::criteriaFor($itemtype) as $key => $definition) {
                    $out[$key] = (string) $definition['name'];
                }
            }
            return $out;
        }

        if ($source === self::SRC_APPROVAL) {
            foreach ($itemtypes as $itemtype) {
                if (!Approval::supports($itemtype)) {
                    continue;
                }
                foreach (self::approvalCriteria() as $key => $definition) {
                    $out[$key] = (string) $definition['name'];
                }
                break;
            }
            return $out;
        }

        return $out;
    }

    /**
     * Everything this step can be gated on, as one list grouped by source.
     *
     * Source and subject were two dropdowns and a reload button, and they are
     * one question: an author does not decide "I want to test a field" and
     * then decide which field, they decide "I want to test the category". The
     * pair is encoded as `<source>:<what>` — `step:21`, `field:urgency`,
     * `approval:global_validation` — so the whole gate is one choice, and
     * {@see splitSubject()} takes it apart again on the way in.
     *
     * A source with nothing to offer is not a group. An SOP written only for
     * problems has no approvals to ask about, and the first step of a procedure
     * has no earlier answer; an empty optgroup would be an invitation to write
     * a clause that can never hold.
     *
     * @param array<int,array<string,mixed>> $parents earlier steps, from Step::candidateParents()
     * @param array<int,string>              $numbers step id => display number
     * @return array<string,array<string,string>> optgroup label => (key => label)
     */
    public static function subjects(Sop $sop, array $parents, array $numbers = []): array
    {
        $out = [];

        if ($parents !== []) {
            $group = [];
            foreach ($parents as $parent) {
                $group[self::SRC_STEP . ':' . (int) $parent['id']] = sprintf(
                    '%s. %s',
                    $numbers[(int) $parent['id']] ?? '?',
                    (string) $parent['label']
                );
            }
            $out[__('An answer earlier in this procedure', 'glpisop')] = $group;
        }

        $itemtypes = $sop->itemtypes();
        $groups    = [
            self::SRC_FIELD    => __('A field of the item', 'glpisop'),
            self::SRC_APPROVAL => __('An approval on the item', 'glpisop'),
        ];

        foreach ($groups as $source => $label) {
            $group = [];
            foreach (self::criteriaFor($source, $itemtypes) as $key => $name) {
                $group[$source . ':' . $key] = $name;
            }
            if ($group !== []) {
                $out[$label] = $group;
            }
        }

        return $out;
    }

    /**
     * The encoded subject back into the pair it names.
     *
     * @return array{0:string,1:string} [source, step id or criterion]
     */
    public static function splitSubject(string $subject): array
    {
        $split = explode(':', $subject, 2);

        return [$split[0] ?? '', $split[1] ?? ''];
    }

    /**
     * Is this one of the subjects actually on offer?
     *
     * Asked of the rendered list rather than of the vocabulary, because the
     * list is already narrowed to what this SOP and this step can test — a
     * clause gated on a *later* step is the one that produces a question
     * nobody can ever reach.
     *
     * @param array<string,array<string,string>> $subjects
     */
    public static function subjectOffered(array $subjects, string $subject): bool
    {
        foreach ($subjects as $group) {
            if (array_key_exists($subject, $group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The subject a freshly-opened add row starts on.
     *
     * @param array<string,array<string,string>> $subjects
     */
    public static function firstSubject(array $subjects): string
    {
        foreach ($subjects as $group) {
            foreach (array_keys($group) as $key) {
                return (string) $key;
            }
        }

        return '';
    }

    /** @return array{name:string,kind:string,table?:string}|null */
    public static function definition(string $source, string $criterion): ?array
    {
        return match ($source) {
            self::SRC_FIELD    => Trigger::criteria()[$criterion] ?? null,
            self::SRC_APPROVAL => self::approvalCriteria()[$criterion] ?? null,
            default            => null,
        };
    }

    /**
     * Operators offered for one clause.
     *
     * @return array<string,string>
     */
    public static function operatorsFor(string $source, string $criterion): array
    {
        return match ($source) {
            self::SRC_STEP     => Step::operators(),
            self::SRC_FIELD    => Trigger::conditionsFor($criterion),
            self::SRC_APPROVAL => [
                Trigger::IS     => __('is', 'glpisop'),
                Trigger::IS_NOT => __('is not', 'glpisop'),
            ],
            default => [],
        };
    }

    /** Does this clause need a value alongside its operator? */
    public static function needsValue(array $condition): bool
    {
        $source = (string) $condition['source'];

        if ($source === self::SRC_STEP) {
            return Step::operatorNeedsValue((string) $condition['match_condition']);
        }

        return true;
    }

    // ----------------------------------------------------------------- reads

    /**
     * Every clause of an SOP's steps, keyed by step, in author order.
     *
     * One query for the whole procedure rather than one per step: the run
     * renderer, the recount and the enforcement check all evaluate every step
     * of an SOP at once, and a per-step read would put the clause count into
     * the query count of every ticket render.
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function forSop(int $sops_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'SELECT'     => [self::getTable() . '.*'],
                'FROM'       => self::getTable(),
                'INNER JOIN' => [
                    Step::getTable() => [
                        'ON' => [
                            self::getTable() => 'plugin_glpisop_steps_id',
                            Step::getTable() => 'id',
                        ],
                    ],
                ],
                'WHERE'      => [Step::getTable() . '.plugin_glpisop_sops_id' => $sops_id],
                'ORDER'      => [self::getTable() . '.rank_order', self::getTable() . '.id'],
            ]) as $row
        ) {
            $out[(int) $row['plugin_glpisop_steps_id']][] = $row;
        }

        return $out;
    }

    /**
     * The clauses of one step, in author order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forStep(int $steps_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_glpisop_steps_id' => $steps_id],
                'ORDER' => ['rank_order', 'id'],
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    /** Next free rank, so a new clause lands at the bottom of the list. */
    public static function nextRank(int $steps_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['MAX' => 'rank_order AS max_rank'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['plugin_glpisop_steps_id' => $steps_id],
            ]) as $row
        ) {
            return ((int) $row['max_rank']) + 1;
        }

        return 1;
    }

    /**
     * The step a clause hangs off, for the display numbering and the editor's
     * "a gate may only point backwards" check.
     *
     * @return int 0 when the clause is not about an earlier step
     */
    public static function parentStep(array $condition): int
    {
        return (string) $condition['source'] === self::SRC_STEP
            ? (int) $condition['depends_steps_id']
            : 0;
    }

    // ------------------------------------------------------------ evaluation

    /**
     * Is this clause satisfied?
     *
     * `$item` is the ITIL object the run lives on, or null where the caller
     * genuinely has none — the step editor's preview, an SOP being read outside
     * a run. A clause that needs an item it was not given is *satisfied*, on
     * the same principle as an unknown operator: a step shown unnecessarily
     * costs a question, a step hidden wrongly costs the answer.
     *
     * @param array<int,array<string,mixed>> $byId    steps of this SOP, keyed by id
     * @param array<int,array<string,mixed>> $answers keyed by step id
     * @param callable(int):bool             $resolve visibility of an earlier step
     */
    public static function satisfied(
        array $condition,
        array $byId,
        array $answers,
        ?CommonDBTM $item,
        callable $resolve
    ): bool {
        switch ((string) $condition['source']) {
            case self::SRC_STEP:
                return self::stepSatisfied($condition, $byId, $answers, $resolve);

            case self::SRC_FIELD:
                if ($item === null) {
                    return true;
                }
                return Trigger::matches($condition, $item);

            case self::SRC_APPROVAL:
                if ($item === null) {
                    return true;
                }
                return self::approvalSatisfied($condition, $item);
        }

        return true;
    }

    /**
     * @param array<int,array<string,mixed>> $byId
     * @param array<int,array<string,mixed>> $answers
     * @param callable(int):bool             $resolve
     */
    private static function stepSatisfied(
        array $condition,
        array $byId,
        array $answers,
        callable $resolve
    ): bool {
        $parent_id = (int) $condition['depends_steps_id'];

        // A clause on a step that is not part of this procedure any more.
        // Step::cleanDBonPurge() deletes these outright, so reaching here means
        // the parent is merely inactive — and a clause about a retired step
        // must not silently drop the step it gates.
        if ($parent_id <= 0 || !isset($byId[$parent_id])) {
            return true;
        }

        // Visibility is inherited through the clause: a gate whose parent is
        // itself not being asked is not satisfied, however its own answer
        // looks. Without this an unasked question's *absence* would open the
        // branch below it.
        if (!$resolve($parent_id)) {
            return false;
        }

        return self::answerSatisfies(
            (string) $condition['match_condition'],
            (string) $condition['value'],
            $byId[$parent_id],
            $answers[$parent_id] ?? Answer::blank($parent_id)
        );
    }

    /**
     * Does the parent's answer satisfy the operator?
     *
     * `checked` deliberately counts a *skipped* step as unanswered. A skip is
     * an explicit "this was not done", and treating it as an answer would open
     * the remediation branch of a diagnosis nobody performed.
     */
    private static function answerSatisfies(
        string $op,
        string $expected,
        array $parent,
        array $answer
    ): bool {
        $type     = (string) $parent['step_type'];
        $answered = (string) ($answer['state'] ?? Answer::PENDING) === Answer::DONE;

        switch ($op) {
            case Step::OP_CHECKED:
                return $answered;

            case Step::OP_UNCHECKED:
                return !$answered;

            case Step::OP_EQ:
                return $answered && in_array($expected, StepType::comparable($type, $answer), true);

            case Step::OP_NE:
                // Requires an answer on purpose: "is not Yes" should not be
                // true of a question that has not been asked yet, or every
                // negative branch in the procedure opens the moment the run
                // starts.
                return $answered && !in_array($expected, StepType::comparable($type, $answer), true);

            case Step::OP_GT:
            case Step::OP_LT:
                if (!$answered) {
                    return false;
                }
                $values = StepType::comparable($type, $answer);
                $actual = $values[0] ?? '';
                if (!is_numeric($actual) || !is_numeric($expected)) {
                    return false;
                }
                return $op === Step::OP_GT
                    ? (float) $actual > (float) $expected
                    : (float) $actual < (float) $expected;
        }

        // An unrecognised operator means an author saved something this build
        // does not understand. Satisfied is the reading that loses no
        // information.
        return true;
    }

    /**
     * Approvals on the item.
     *
     * `global_validation` is read off the item because that is where GLPI keeps
     * it — it is the rolled-up state of every approval, maintained by core, and
     * re-deriving it here would be a second opinion about a question core has
     * already answered.
     *
     * The by-whom questions are asked of the validation rows. GLPI 11 targets
     * an approval at a User or a Group through `itemtype_target`/
     * `items_id_target`; `users_id_validate` is the older column and is still
     * populated, so both are matched. A group question additionally counts an
     * approval granted by a *member* of that group, because "approved by
     * Management" is a statement about who the person is, not about which of
     * the two ways the request happened to be addressed.
     */
    private static function approvalSatisfied(array $condition, CommonDBTM $item): bool
    {
        $criterion = (string) $condition['criterion'];
        $negated   = (string) $condition['match_condition'] === Trigger::IS_NOT;
        $expected  = (string) $condition['value'];

        if (!Approval::supports($item::getType())) {
            // The itemtype has no approvals. Not a match either way — the same
            // reading Trigger::matches() takes of a criterion the itemtype does
            // not have, so an approval clause cannot quietly hold for a problem.
            return false;
        }

        $holds = match ($criterion) {
            'global_validation' => Approval::state($item) === (int) $expected,
            'approved_by_user'  => Approval::grantedByUser($item, (int) $expected),
            'approved_by_group' => Approval::grantedByGroup($item, (int) $expected),
            default             => null,
        };

        if ($holds === null) {
            // A criterion this build does not understand. Satisfied is the
            // reading that loses no information.
            return true;
        }

        return $negated ? !$holds : $holds;
    }

    // ------------------------------------------------------------- describing

    /**
     * One clause written out as a sentence.
     *
     * "step 3 is “No”", "Category is, or is under: Hardware", "Approval status
     * is: Granted". An author checking their own work is reading the numbers in
     * the builder's left column and the names in GLPI's own dropdowns, not
     * column names and ids.
     *
     * @param array<int,string> $numbers step id => display number
     */
    public static function describe(array $condition, array $numbers = []): string
    {
        $source = (string) $condition['source'];

        if ($source === self::SRC_FIELD) {
            return Trigger::describe($condition);
        }

        if ($source === self::SRC_APPROVAL) {
            $definition = self::approvalCriteria()[(string) $condition['criterion']] ?? null;
            $name       = $definition['name'] ?? (string) $condition['criterion'];
            $operators  = self::operatorsFor($source, (string) $condition['criterion']);
            $op         = $operators[(string) $condition['match_condition']]
                ?? (string) $condition['match_condition'];

            return sprintf('%s %s: %s', $name, $op, self::approvalValueLabel($condition));
        }

        $parent_id = (int) $condition['depends_steps_id'];
        $op        = (string) $condition['match_condition'];
        $operators = Step::operators();
        $number    = $numbers[$parent_id] ?? ('#' . $parent_id);
        $label     = $operators[$op] ?? $op;

        if (!Step::operatorNeedsValue($op)) {
            return sprintf(__('step %1$s %2$s', 'glpisop'), $number, $label);
        }

        return sprintf(
            __('step %1$s %2$s “%3$s”', 'glpisop'),
            $number,
            $label,
            self::stepValueLabel($condition)
        );
    }

    /**
     * A step clause's value as the author typed or picked it.
     *
     * Yes/No steps store a canonical token rather than the word on the screen —
     * see {@see StepType::YESNO} — so the token is translated back here instead
     * of showing an author "step 2 is “no”" when the control they are looking
     * at says "No".
     */
    private static function stepValueLabel(array $condition): string
    {
        $raw = (string) $condition['value'];

        return StepType::yesNoLabels()[$raw] ?? $raw;
    }

    private static function approvalValueLabel(array $condition): string
    {
        $criterion = (string) $condition['criterion'];
        $raw       = (string) $condition['value'];

        if ($criterion === 'global_validation') {
            return Approval::statusLabel((int) $raw);
        }

        $definition = self::approvalCriteria()[$criterion] ?? null;
        if ($definition === null || !isset($definition['table'])) {
            return $raw;
        }

        $label = \Dropdown::getDropdownName((string) $definition['table'], (int) $raw);

        return $label !== '' && $label !== '&nbsp;' ? $label : $raw;
    }

    /**
     * The whole gate on one step, as a sentence.
     *
     * @param array<int,array<string,mixed>> $conditions
     * @param array<int,string>              $numbers
     */
    public static function describeAll(array $conditions, string $mode, array $numbers = []): string
    {
        if ($conditions === []) {
            return '';
        }

        $parts = [];
        foreach ($conditions as $condition) {
            $parts[] = self::describe($condition, $numbers);
        }

        if (count($parts) === 1) {
            return sprintf(__('only if %s', 'glpisop'), $parts[0]);
        }

        $joiner = $mode === self::MODE_ANY
            ? __(' or ', 'glpisop')
            : __(' and ', 'glpisop');

        return sprintf(__('only if %s', 'glpisop'), implode($joiner, $parts));
    }
}
