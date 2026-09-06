<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBTM;
use CommonITILValidation;

/**
 * Everything this plugin knows about GLPI's approvals — read, never written.
 *
 * Two callers, and they want the same facts for different purposes:
 *
 *  - {@see Condition} asks whether an approval clause holds, to decide whether
 *    a step is being asked at all;
 *  - {@see StepType::APPROVAL} makes an approval *the answer to a step*, so a
 *    procedure can require one rather than merely branch on one.
 *
 * The second is the one that matters for onboarding. "Manager has approved the
 * hire" as a checkbox is a technician asserting somebody else's decision — the
 * tick is evidence of nothing, and it is exactly the step that gets ticked to
 * get on with the day. An approval step cannot be ticked: it is satisfied when
 * GLPI's own approval record says it is, and it goes back to outstanding if
 * that record changes.
 *
 * Nothing here creates or answers an approval. Requesting one and granting one
 * are core's, with core's rights and core's notifications behind them, and a
 * plugin that offered a second way to grant an approval would be offering a way
 * around them.
 */
final class Approval
{
    /**
     * The three states a procedure can require.
     *
     * GLPI's `global_validation` has a fourth, `NONE` — "not subject to
     * approval" — which is what an item holds when nobody has asked for one.
     * It is a legitimate thing to *branch* on and a meaningless thing to
     * *require*: a step demanding that no approval was ever requested is
     * satisfied by doing nothing and unsatisfied by doing the right thing.
     * So it is offered to conditions and withheld from steps.
     *
     * @return array<int,string>
     */
    public static function requirableStatuses(): array
    {
        return [
            CommonITILValidation::ACCEPTED => __('approved', 'glpisop'),
            CommonITILValidation::WAITING  => __('waiting for approval', 'glpisop'),
            CommonITILValidation::REFUSED  => __('refused', 'glpisop'),
        ];
    }

    /**
     * Every state `global_validation` can hold, for the condition editor.
     *
     * @return array<int,string>
     */
    public static function allStatuses(): array
    {
        return [
            CommonITILValidation::NONE     => __('Not subject to approval'),
            CommonITILValidation::WAITING  => __('Waiting for approval'),
            CommonITILValidation::ACCEPTED => __('Granted'),
            CommonITILValidation::REFUSED  => _x('validation', 'Refused'),
        ];
    }

    public static function statusLabel(int $status): string
    {
        return (string) (self::allStatuses()[$status] ?? $status);
    }

    /**
     * Itemtypes that have approvals at all.
     *
     * Problems do not, so an approval step or clause on an SOP written for
     * problems is one that can never be satisfied. The editors say so rather
     * than offering it and letting the author find out from a run.
     */
    public static function supports(string $itemtype): bool
    {
        return self::table($itemtype) !== null;
    }

    /** @return array{table:string,fk:string}|null */
    public static function table(string $itemtype): ?array
    {
        return match ($itemtype) {
            'Ticket' => ['table' => 'glpi_ticketvalidations', 'fk' => 'tickets_id'],
            'Change' => ['table' => 'glpi_changevalidations', 'fk' => 'changes_id'],
            default  => null,
        };
    }

    /**
     * The item's overall approval state.
     *
     * Read off the item because that is where GLPI keeps it: `global_validation`
     * is the rolled-up state of every approval on the record, maintained by
     * core, and re-deriving it here would be a second opinion about a question
     * core has already answered.
     */
    public static function state(?CommonDBTM $item): int
    {
        if ($item === null || !self::supports($item::getType())) {
            return CommonITILValidation::NONE;
        }

        return (int) ($item->fields['global_validation'] ?? CommonITILValidation::NONE);
    }

    /**
     * Has this user granted an approval on the item?
     *
     * GLPI 11 targets an approval at a User or a Group through
     * `itemtype_target`/`items_id_target`; `users_id_validate` is the older
     * column and is still populated, so both are matched.
     */
    public static function grantedByUser(CommonDBTM $item, int $users_id): bool
    {
        $validation = self::table($item::getType());
        if ($validation === null || $users_id <= 0) {
            return false;
        }

        return countElementsInTable($validation['table'], [
            $validation['fk']   => (int) $item->getID(),
            'status'            => CommonITILValidation::ACCEPTED,
            'OR'                => [
                ['itemtype_target' => 'User', 'items_id_target' => $users_id],
                ['users_id_validate' => $users_id],
            ],
        ]) > 0;
    }

    /**
     * Has anybody in this group granted an approval on the item?
     *
     * Counts an approval addressed to the group itself *and* one granted by a
     * member of it, because "approved by Management" is a statement about who
     * the person is, not about which of the two ways the request happened to be
     * addressed.
     */
    public static function grantedByGroup(CommonDBTM $item, int $groups_id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $validation = self::table($item::getType());
        if ($validation === null || $groups_id <= 0) {
            return false;
        }

        $items_id = (int) $item->getID();

        $direct = countElementsInTable($validation['table'], [
            $validation['fk']  => $items_id,
            'status'           => CommonITILValidation::ACCEPTED,
            'itemtype_target'  => 'Group',
            'items_id_target'  => $groups_id,
        ]);

        if ($direct > 0) {
            return true;
        }

        $members = [];
        foreach (
            $DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => 'glpi_groups_users',
                'WHERE'  => ['groups_id' => $groups_id],
            ]) as $row
        ) {
            $members[] = (int) $row['users_id'];
        }

        if ($members === []) {
            return false;
        }

        return countElementsInTable($validation['table'], [
            $validation['fk'] => $items_id,
            'status'          => CommonITILValidation::ACCEPTED,
            'OR'              => [
                ['itemtype_target' => 'User', 'items_id_target' => $members],
                ['users_id_validate' => $members],
            ],
        ]) > 0;
    }

    // ----------------------------------------------------- the approval step

    /** What an approval step requires, defaulted. */
    public static function requiredStatus(array $config): int
    {
        $wanted = (int) ($config['require_status'] ?? CommonITILValidation::ACCEPTED);

        return array_key_exists($wanted, self::requirableStatuses())
            ? $wanted
            : CommonITILValidation::ACCEPTED;
    }

    /**
     * Is the step's requirement met?
     *
     * Where the step also names a user or a group, *both* have to hold: the
     * item is in the required state, and the named person or group is the one
     * who put it there. Naming a group and accepting an approval from anybody
     * would make "approved by Management" a decoration.
     *
     * A null item — the run's ticket purged out from under it — is not
     * satisfied. This is a requirement, and the honest answer to "did the
     * manager approve it" when the record has gone is no.
     */
    public static function satisfied(array $config, ?CommonDBTM $item): bool
    {
        if ($item === null || !self::supports($item::getType())) {
            return false;
        }

        if (self::state($item) !== self::requiredStatus($config)) {
            return false;
        }

        $users_id  = (int) ($config['users_id'] ?? 0);
        $groups_id = (int) ($config['groups_id'] ?? 0);

        // "By whom" only means anything about an approval that was granted.
        // Asking who refused it, or who has not answered yet, is a different
        // question and not one the validation rows can answer.
        if (self::requiredStatus($config) !== CommonITILValidation::ACCEPTED) {
            return true;
        }

        if ($users_id > 0 && !self::grantedByUser($item, $users_id)) {
            return false;
        }

        if ($groups_id > 0 && !self::grantedByGroup($item, $groups_id)) {
            return false;
        }

        return true;
    }

    /** What the step is asking for, as a sentence for the technician. */
    public static function describeRequirement(array $config): string
    {
        $status = self::requirableStatuses()[self::requiredStatus($config)]
            ?? self::statusLabel(self::requiredStatus($config));

        $users_id  = (int) ($config['users_id'] ?? 0);
        $groups_id = (int) ($config['groups_id'] ?? 0);

        if ($users_id > 0) {
            return sprintf(
                __('This step needs the item %1$s by %2$s.', 'glpisop'),
                $status,
                \Dropdown::getDropdownName('glpi_users', $users_id)
            );
        }

        if ($groups_id > 0) {
            return sprintf(
                __('This step needs the item %1$s by a member of %2$s.', 'glpisop'),
                $status,
                \Dropdown::getDropdownName('glpi_groups', $groups_id)
            );
        }

        return sprintf(__('This step needs the item %s.', 'glpisop'), $status);
    }

    /**
     * Bring every approval step of a run into line with the record.
     *
     * Writes only when the state actually changes, because this runs inside
     * {@see Run::recount()} — which runs on every answer — and a write per
     * render would fill the run log with entries nobody caused.
     *
     * The answer is written *back to pending* when an approval is withdrawn or
     * refused after the fact, which is the half that makes this a requirement
     * rather than a one-way latch: an enforcing SOP reopens, and the ticket
     * stops being closable again.
     *
     * @param array<int,array<string,mixed>> $steps
     * @param array<int,array<string,mixed>> $answers keyed by step id
     * @return bool whether anything changed
     */
    public static function sync(int $runs_id, array $steps, array $answers, ?CommonDBTM $item): bool
    {
        $changed  = false;
        $observed = self::state($item);

        foreach ($steps as $step) {
            if ((string) $step['step_type'] !== StepType::APPROVAL) {
                continue;
            }

            $steps_id = (int) $step['id'];
            $answer   = $answers[$steps_id] ?? Answer::blank($steps_id);
            $state    = (string) $answer['state'];

            // A skipped step stays skipped. Somebody said this did not need
            // doing, and an approval landing later is not a reason to overrule
            // them without their knowing.
            if ($state === Answer::SKIPPED) {
                continue;
            }

            $met  = self::satisfied(Step::config($step), $item);
            $done = $state === Answer::DONE;
            $held = (int) ($answer['value'] ?? 0);

            if ($met === $done && $observed === $held) {
                continue;
            }

            // The observed status is carried on a *pending* row when the
            // requirement is not met, rather than the row being deleted. An
            // absent row and a pending row still mean the same thing to every
            // count — Answer::isAnswered() reads the state, not the value —
            // and this is what lets the step show "Currently: waiting for
            // approval" instead of showing nothing until it is satisfied.
            Answer::put($runs_id, $steps_id, [
                'state' => $met ? Answer::DONE : Answer::PENDING,
                'value' => (string) $observed,
                'note'  => $answer['note'] ?? null,
            ], 0);

            // Logged on the transition only. The status drifting from "not
            // subject to approval" to "waiting" while the step stays
            // outstanding is the display's business, not the audit trail's.
            if ($met !== $done) {
                RunLog::add(
                    $runs_id,
                    $met ? RunLog::ANSWERED : RunLog::CLEARED,
                    $steps_id,
                    self::statusLabel($observed)
                );
            }

            $changed = true;
        }

        return $changed;
    }

    /**
     * Every run holding an approval step on this item.
     *
     * @return int[] run ids
     */
    public static function runsOn(string $itemtype, int $items_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'SELECT'     => [Run::getTable() . '.id'],
                'DISTINCT'   => true,
                'FROM'       => Run::getTable(),
                'INNER JOIN' => [
                    Step::getTable() => [
                        'ON' => [
                            Run::getTable()  => 'plugin_glpisop_sops_id',
                            Step::getTable() => 'plugin_glpisop_sops_id',
                        ],
                    ],
                ],
                'WHERE'      => [
                    Run::getTable() . '.itemtype'  => $itemtype,
                    Run::getTable() . '.items_id'  => $items_id,
                    Step::getTable() . '.step_type' => StepType::APPROVAL,
                    Step::getTable() . '.is_active' => 1,
                ],
            ]) as $row
        ) {
            $out[] = (int) $row['id'];
        }

        return $out;
    }
}
