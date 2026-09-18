<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonITILObject;
use CommonITILValidation;
use Ticket;

/**
 * The id behind a name somebody wrote.
 *
 * Half of what an author picks in the builder is an id: a category, a group, a
 * user, an approval status, an urgency. The builder asks for those with a
 * dropdown, so the id is never typed and never wrong. Everything arriving from
 * a model is prose — "Hardware", "the service desk group", "approved" — and the
 * gap between the two is this class.
 *
 * Resolving here rather than making the model guess is not a convenience. An id
 * a model invented is not detectably wrong: `itilcategories_id = 7` saves, and
 * either gates a step on a category nobody meant or attaches a procedure to the
 * wrong queue. A name that matches nothing is an error that can be reported,
 * and a name that matches two things is a question worth asking.
 *
 * Three rules hold throughout:
 *
 *  - **A number is taken at its word.** A model that read an id out of
 *    `sop_library` or a ticket should not have it re-interpreted as a name.
 *  - **Exact first, then unique prefix, then nothing.** "Hardware" beats
 *    "Hardware > Laptops" when both exist; "Hard" resolves only while it names
 *    one thing. A near-match is never guessed at.
 *  - **Entity scope is GLPI's, not ours.** Every lookup goes through the
 *    session's own restriction, so a model cannot name its way to a group in
 *    an entity the technician driving it cannot see.
 */
final class Lookup
{
    /** What a lookup failed at, for the note that reports it. */
    public const NOTHING   = 'nothing';
    public const AMBIGUOUS = 'ambiguous';

    /**
     * The id of a row in a dropdown table, by name.
     *
     * Tree dropdowns — categories, locations, entities — are matched on
     * `completename` as well as `name`, because "Hardware > Laptops" is how
     * GLPI writes them everywhere a person would have read one.
     *
     * @param string $failure set to NOTHING or AMBIGUOUS when this returns 0
     */
    public static function inTable(string $table, string $value, string &$failure = ''): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $failure = '';
        $value   = trim($value);

        if ($value === '') {
            $failure = self::NOTHING;
            return 0;
        }

        // An id is an id. Checked for existence rather than trusted outright:
        // the reason for resolving names at all is that a wrong id is silent.
        if (ctype_digit($value)) {
            $id = (int) $value;
            if ($id > 0 && $DB->request(['FROM' => $table, 'WHERE' => ['id' => $id]])->count() > 0) {
                return $id;
            }

            $failure = self::NOTHING;
            return 0;
        }

        $itemtype = getItemTypeForTable($table);
        $tree     = class_exists($itemtype) && is_subclass_of($itemtype, \CommonTreeDropdown::class);
        $columns  = $tree ? ['completename', 'name'] : ['name'];

        $criteria = ['FROM' => $table, 'SELECT' => ['id', 'name']];
        if ($tree) {
            $criteria['SELECT'][] = 'completename';
        }

        // The session's own restriction, where the table has one. Nothing here
        // widens what the technician driving the conversation can see.
        if ($DB->fieldExists($table, 'entities_id')) {
            $criteria['WHERE'] = getEntitiesRestrictCriteria($table, '', '', true);
        }

        $rows = [];
        foreach ($DB->request($criteria) as $row) {
            $rows[] = $row;
        }

        foreach ($columns as $column) {
            $exact = [];
            foreach ($rows as $row) {
                if (mb_strtolower(trim((string) ($row[$column] ?? ''))) === mb_strtolower($value)) {
                    $exact[(int) $row['id']] = true;
                }
            }

            if (count($exact) === 1) {
                return (int) array_key_first($exact);
            }

            if (count($exact) > 1) {
                $failure = self::AMBIGUOUS;
                return 0;
            }
        }

        // One partial match is an answer; two is a question.
        $partial = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                if (mb_stripos((string) ($row[$column] ?? ''), $value) !== false) {
                    $partial[(int) $row['id']] = true;
                    break;
                }
            }
        }

        if (count($partial) === 1) {
            return (int) array_key_first($partial);
        }

        $failure = $partial === [] ? self::NOTHING : self::AMBIGUOUS;

        return 0;
    }

    /**
     * A user, by login or by the name they are displayed under.
     *
     * Separate from {@see inTable()} because `glpi_users.name` is the login:
     * "jsmith" resolves there, and "Jane Smith" — which is what a technician
     * says out loud and what a model will therefore write — does not appear in
     * that column at all.
     */
    public static function user(string $value, string &$failure = ''): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $failure = '';
        $value   = trim($value);

        if ($value === '') {
            $failure = self::NOTHING;
            return 0;
        }

        if (ctype_digit($value)) {
            $id = (int) $value;
            if ($id > 0 && $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => $id]])->count() > 0) {
                return $id;
            }

            $failure = self::NOTHING;
            return 0;
        }

        $matches = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'realname', 'firstname'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['is_deleted' => 0, 'is_active' => 1],
            ]) as $row
        ) {
            $names = [
                (string) $row['name'],
                trim((string) $row['firstname'] . ' ' . (string) $row['realname']),
                trim((string) $row['realname'] . ' ' . (string) $row['firstname']),
                trim((string) $row['realname']),
            ];

            foreach ($names as $name) {
                if ($name !== '' && mb_strtolower($name) === mb_strtolower($value)) {
                    $matches[(int) $row['id']] = true;
                    break;
                }
            }
        }

        if (count($matches) === 1) {
            return (int) array_key_first($matches);
        }

        $failure = $matches === [] ? self::NOTHING : self::AMBIGUOUS;

        return 0;
    }

    /**
     * One of a fixed list of ints, by its label or by the number itself.
     *
     * Urgency, impact, priority, ticket type and approval status are all the
     * same shape: a handful of ints GLPI has names for. A model writing "high"
     * means 4 here and would have to be told that; a model writing "4" has read
     * it off a ticket.
     *
     * @param array<int,string> $labels id => label, as GLPI names them
     */
    public static function inList(array $labels, string $value, string &$failure = ''): int
    {
        $failure = '';
        $value   = trim($value);

        if ($value === '') {
            $failure = self::NOTHING;
            return 0;
        }

        if (ctype_digit($value) || (str_starts_with($value, '-') && ctype_digit(substr($value, 1)))) {
            $id = (int) $value;
            if (array_key_exists($id, $labels)) {
                return $id;
            }

            $failure = self::NOTHING;
            return 0;
        }

        foreach ($labels as $id => $label) {
            if (mb_strtolower(trim($label)) === mb_strtolower($value)) {
                return (int) $id;
            }
        }

        foreach ($labels as $id => $label) {
            if (mb_stripos($label, $value) !== false) {
                return (int) $id;
            }
        }

        $failure = self::NOTHING;

        return 0;
    }

    /** Urgency, impact and priority, as GLPI names the five levels. */
    public static function priorities(): array
    {
        $out = [];
        for ($level = 1; $level <= 5; $level++) {
            $out[$level] = (string) CommonITILObject::getPriorityName($level);
        }

        return $out;
    }

    /** Incident or request. */
    public static function ticketTypes(): array
    {
        return [
            Ticket::INCIDENT_TYPE => (string) Ticket::getTicketTypeName(Ticket::INCIDENT_TYPE),
            Ticket::DEMAND_TYPE   => (string) Ticket::getTicketTypeName(Ticket::DEMAND_TYPE),
        ];
    }

    /** The four approval states, as {@see Approval} names them. */
    public static function validationStatuses(): array
    {
        $out = [];
        foreach (Approval::allStatuses() as $id => $label) {
            $out[(int) $id] = (string) $label;
        }

        return $out;
    }

    /**
     * Why a lookup came back empty, as a sentence fragment.
     *
     * The distinction is the useful part: nothing matched is a model that
     * invented a name, and several matched is a model that was not specific
     * enough. Those want different corrections from the technician.
     */
    public static function say(string $failure, string $what, string $value): string
    {
        if ($failure === self::AMBIGUOUS) {
            return sprintf(
                __('"%1$s" matches more than one %2$s, so it was left out. Name it exactly.',
                    'glpisop'),
                $value,
                $what
            );
        }

        return sprintf(
            __('"%1$s" does not name a %2$s here, so it was left out.', 'glpisop'),
            $value,
            $what
        );
    }

    /** Whether a status int is one the approval step type may require. */
    public static function requirableStatus(int $status): bool
    {
        return array_key_exists($status, Approval::requirableStatuses());
    }

    /** The accepted-by-default approval status, for a step that names none. */
    public static function defaultApprovalStatus(): int
    {
        return CommonITILValidation::ACCEPTED;
    }
}
