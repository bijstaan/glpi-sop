<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBChild;
use CommonDBTM;
use CommonITILActor;
use CommonITILObject;

/**
 * One condition under which an SOP puts itself on an item.
 *
 * The criteria are deliberately a short, fixed list rather than a generic
 * field-picker over the ITIL tables. Everything offered here is something a
 * procedure is genuinely *about* — what kind of problem it is, who reported it,
 * where — and the columns that are not offered (dates, counters, internal ids)
 * would produce triggers that fire for reasons nobody can explain a month
 * later.
 *
 * Anyone who wants the general case already has one: GLPI's own business rules
 * can assign an SOP, with the full criteria set and the preview tool that comes
 * with them. See plugin_glpisop_getRuleActions() in hook.php.
 */
class Trigger extends CommonDBChild
{
    public static $rightname = 'plugin_glpisop_sop';

    public static $itemtype = Sop::class;
    public static $items_id = 'plugin_glpisop_sops_id';

    public const IS           = 'is';
    public const IS_NOT       = 'is_not';
    public const UNDER        = 'under';
    public const CONTAINS     = 'contains';
    public const NOT_CONTAINS = 'not_contains';
    public const REGEX        = 'regex';

    public static function getTypeName($nb = 0)
    {
        return _n('Trigger', 'Triggers', $nb, 'glpisop');
    }

    /**
     * The criteria an author may test, and how each is read off an item.
     *
     * `field` is the column on the ITIL object; `actor` marks the two that live
     * in link tables and have to be asked for rather than read.
     *
     * @return array<string,array{name:string,kind:string,table?:string,itemtypes?:string[]}>
     */
    public static function criteria(): array
    {
        return [
            'itilcategories_id' => [
                'name'  => __('Category'),
                'kind'  => 'dropdown',
                'table' => 'glpi_itilcategories',
            ],
            'type' => [
                // Incident vs request exists only on tickets; offering it on a
                // change would be a trigger that can never match.
                'name'      => __('Type'),
                'kind'      => 'tickettype',
                'itemtypes' => ['Ticket'],
            ],
            'urgency' => [
                'name' => __('Urgency'),
                'kind' => 'priority',
            ],
            'impact' => [
                'name' => __('Impact'),
                'kind' => 'priority',
            ],
            'priority' => [
                'name' => __('Priority'),
                'kind' => 'priority',
            ],
            'requesttypes_id' => [
                'name'      => __('Request source'),
                'kind'      => 'dropdown',
                'table'     => 'glpi_requesttypes',
                'itemtypes' => ['Ticket'],
            ],
            'locations_id' => [
                'name'  => __('Location'),
                'kind'  => 'dropdown',
                'table' => 'glpi_locations',
            ],
            'entities_id' => [
                'name'  => __('Entity'),
                'kind'  => 'dropdown',
                'table' => 'glpi_entities',
            ],
            'group_requester' => [
                'name'  => __('Requester group', 'glpisop'),
                'kind'  => 'actor_group',
                'table' => 'glpi_groups',
            ],
            'group_assign' => [
                'name'  => __('Assigned group', 'glpisop'),
                'kind'  => 'actor_group',
                'table' => 'glpi_groups',
            ],
            'name' => [
                'name' => __('Title'),
                'kind' => 'text',
            ],
            'content' => [
                'name' => __('Description'),
                'kind' => 'text',
            ],
        ];
    }

    /** Criteria that make sense for a given itemtype. */
    public static function criteriaFor(string $itemtype): array
    {
        $out = [];
        foreach (self::criteria() as $key => $definition) {
            $allowed = $definition['itemtypes'] ?? null;
            if ($allowed === null || in_array($itemtype, $allowed, true)) {
                $out[$key] = $definition;
            }
        }

        return $out;
    }

    /**
     * Conditions offered for a criterion.
     *
     * Text criteria get the substring and regex conditions; the rest get
     * equality, and tree dropdowns additionally get "under", which is the one
     * that makes a category trigger maintainable — an author writes it once
     * against "Hardware" instead of re-editing it every time a sub-category is
     * added.
     *
     * @return array<string,string>
     */
    public static function conditionsFor(string $criterion): array
    {
        $definition = self::criteria()[$criterion] ?? null;
        if ($definition === null) {
            return [];
        }

        if ($definition['kind'] === 'text') {
            return [
                self::CONTAINS     => __('contains', 'glpisop'),
                self::NOT_CONTAINS => __('does not contain', 'glpisop'),
                self::REGEX        => __('matches the regular expression', 'glpisop'),
            ];
        }

        $out = [
            self::IS     => __('is', 'glpisop'),
            self::IS_NOT => __('is not', 'glpisop'),
        ];

        if (in_array($criterion, ['itilcategories_id', 'locations_id', 'entities_id'], true)) {
            $out[self::UNDER] = __('is, or is under', 'glpisop');
        }

        return $out;
    }

    /**
     * Triggers of an SOP, in author order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function allFor(int $sops_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_glpisop_sops_id' => $sops_id],
                'ORDER' => ['id'],
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Does this trigger hold for the item?
     *
     * A criterion the itemtype does not have is *not* a match. That is the
     * conservative reading, and the right one: a request-source trigger on an
     * SOP that also covers changes should stop the SOP attaching to changes,
     * not attach to all of them.
     */
    public static function matches(array $trigger, CommonDBTM $item): bool
    {
        $criterion  = (string) $trigger['criterion'];
        $condition  = (string) $trigger['match_condition'];
        $expected   = (string) $trigger['value'];
        $definition = self::criteria()[$criterion] ?? null;

        if ($definition === null) {
            return false;
        }

        $allowed = $definition['itemtypes'] ?? null;
        if ($allowed !== null && !in_array($item::getType(), $allowed, true)) {
            return false;
        }

        if ($definition['kind'] === 'actor_group') {
            return self::matchesActorGroup($criterion, $condition, (int) $expected, $item);
        }

        if (!array_key_exists($criterion, $item->fields)) {
            return false;
        }

        $actual = (string) $item->fields[$criterion];

        switch ($condition) {
            case self::IS:
                return $actual === $expected;

            case self::IS_NOT:
                return $actual !== $expected;

            case self::UNDER:
                $table = (string) ($definition['table'] ?? '');
                if ($table === '' || $expected === '') {
                    return false;
                }
                return $actual === $expected
                    || in_array((int) $actual, getSonsOf($table, (int) $expected), true);

            case self::CONTAINS:
                return $expected !== '' && mb_stripos($actual, $expected) !== false;

            case self::NOT_CONTAINS:
                return $expected !== '' && mb_stripos($actual, $expected) === false;

            case self::REGEX:
                // An author's regex is not trusted to be valid. A pattern that
                // fails to compile must make the trigger not match, never emit
                // a PHP warning into the middle of a ticket being created.
                $pattern = '/' . str_replace('/', '\/', $expected) . '/i';
                return @preg_match($pattern, $actual) === 1;
        }

        return false;
    }

    /**
     * Requester and assignee groups live in link tables, so they are asked for
     * rather than read off the row.
     */
    private static function matchesActorGroup(
        string $criterion,
        string $condition,
        int $groups_id,
        CommonDBTM $item
    ): bool {
        if (!($item instanceof CommonITILObject) || $groups_id <= 0) {
            return false;
        }

        $actor_type = $criterion === 'group_assign' ? CommonITILActor::ASSIGN : CommonITILActor::REQUESTER;

        $present = false;
        foreach ($item->getGroups($actor_type) as $group) {
            if ((int) $group['groups_id'] === $groups_id) {
                $present = true;
                break;
            }
        }

        return $condition === self::IS_NOT ? !$present : $present;
    }

    /**
     * The control an author picks a value with, for one criterion.
     *
     * Lives here rather than on the trigger tab because the step editor asks
     * the same question — {@see Condition} shares this whole vocabulary — and
     * two copies of "which control does a category need" is exactly the kind of
     * divergence that ends with one screen offering a free-text box for a
     * dropdown field.
     *
     * @param array{name:string,kind:string,table?:string} $definition
     */
    public static function renderValueControl(
        array $definition,
        string $name = 'value',
        string $value = ''
    ): void {
        switch ($definition['kind']) {
            case 'dropdown':
            case 'actor_group':
                // display_emptychoice is off, but GLPI still renders a blank
                // row when the dropdown has nothing to offer — an instance with
                // no categories, a group tree that is empty in this entity. So
                // whoever consumes the value has to refuse 0 rather than assume
                // one was picked; see front/condition.form.php.
                \Dropdown::show(getItemTypeForTable((string) $definition['table']), [
                    'name'                => $name,
                    'value'               => (int) $value,
                    'display_emptychoice' => false,
                    'width'               => '100%',
                ]);
                return;

            case 'priority':
                \Dropdown::showFromArray($name, [
                    1 => CommonITILObject::getPriorityName(1),
                    2 => CommonITILObject::getPriorityName(2),
                    3 => CommonITILObject::getPriorityName(3),
                    4 => CommonITILObject::getPriorityName(4),
                    5 => CommonITILObject::getPriorityName(5),
                ], ['value' => $value !== '' ? (int) $value : 3, 'width' => '100%']);
                return;

            case 'tickettype':
                \Dropdown::showFromArray($name, [
                    \Ticket::INCIDENT_TYPE => \Ticket::getTicketTypeName(\Ticket::INCIDENT_TYPE),
                    \Ticket::DEMAND_TYPE   => \Ticket::getTicketTypeName(\Ticket::DEMAND_TYPE),
                ], [
                    'value' => $value !== '' ? (int) $value : \Ticket::INCIDENT_TYPE,
                    'width' => '100%',
                ]);
                return;

            case 'validation_status':
                \Dropdown::showFromArray($name, Condition::validationStatuses(), [
                    'value' => $value !== '' ? (int) $value : \CommonITILValidation::ACCEPTED,
                    'width' => '100%',
                ]);
                return;
        }

        echo "<input type='text' class='form-control' name='" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
           . "' value='" . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . "' required>";
    }

    /**
     * A trigger written out for the authoring table.
     *
     * Reads as a sentence — "Category is, or is under: Hardware" — because the
     * failure mode of a criteria list is an author who cannot tell at a glance
     * what they built.
     */
    public static function describe(array $trigger): string
    {
        $criterion  = (string) $trigger['criterion'];
        $definition = self::criteria()[$criterion] ?? null;
        $conditions = self::conditionsFor($criterion);

        $name      = $definition['name'] ?? $criterion;
        $condition = $conditions[(string) $trigger['match_condition']] ?? (string) $trigger['match_condition'];
        $value     = self::describeValue($trigger);

        return sprintf('%s %s: %s', $name, $condition, $value);
    }

    private static function describeValue(array $trigger): string
    {
        $criterion  = (string) $trigger['criterion'];
        $definition = self::criteria()[$criterion] ?? null;
        $raw        = (string) $trigger['value'];

        if ($definition === null) {
            return $raw;
        }

        switch ($definition['kind']) {
            case 'dropdown':
            case 'actor_group':
                $label = \Dropdown::getDropdownName((string) $definition['table'], (int) $raw);
                return $label !== '' && $label !== '&nbsp;' ? $label : $raw;

            case 'priority':
                return CommonITILObject::getPriorityName((int) $raw);

            case 'tickettype':
                return \Ticket::getTicketTypeName((int) $raw);
        }

        return $raw;
    }
}
