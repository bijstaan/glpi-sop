<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use Dropdown;
use Group;
use ITILCategory;
use Ticket;
use User;

/**
 * The authoring half of a step type: the options panel, and what it collects.
 *
 * Split out of {@see StepType}, which is about *running* a step — how it
 * renders on a ticket, what a submitted answer has to satisfy, what it reads as
 * afterwards. This is the other side: what an author is shown when they pick a
 * type, and how what they type comes back as `config_json`.
 *
 * One class rather than two copies because the panel is painted twice — once
 * when the builder first draws a step, and again over ajax when the author
 * changes its type. Those have to agree exactly: the second render is what the
 * author fills in, and the first is what their answers come back as. A renderer
 * per caller is the shape that drifts.
 *
 * ## The contract with the browser
 *
 * Every control is wrapped in an element carrying `data-sop-cfg` (the key it
 * writes into the config) and `data-sop-cfg-name` (the `name` of the control
 * inside it). The builder's serialiser reads exactly those two attributes, so
 * it never has to know which types exist or what their options are called —
 * a new type is a case in {@see render()} and a case in {@see collect()}, and
 * nothing in the JavaScript changes.
 *
 * Field *names* are deliberately not unique per step. Several step cards on one
 * page each hold a control called `sopcfg_min`, and that is fine: nothing here
 * is ever submitted by the browser natively — the builder serialises the DOM
 * itself — and the lookup is scoped to the wrapper, never to the document.
 * Select2 keys off element ids, which GLPI already randomises.
 */
final class StepOptions
{
    /** Help text held between {@see open()} and {@see close()}. */
    private static string $pending_help = '';

    /**
     * Types that have a panel at all.
     *
     * The rest — a checkbox, a date, a user picker — are complete as soon as
     * they are chosen. Asking the browser to fetch an empty panel for them is
     * a request that renders "This type has no options", which is a sentence
     * nobody needs to read.
     */
    public static function has(string $type): bool
    {
        return in_array($type, [
            StepType::TEXT,
            StepType::TEXTAREA,
            StepType::NUMBER,
            StepType::CHOICE,
            StepType::MULTICHOICE,
            StepType::ASSET,
            StepType::APPROVAL,
            StepType::TICKET,
        ], true);
    }

    /**
     * The options panel for one type, filled in from one step's config.
     *
     * `$sop` is only consulted to warn about an approval step on a procedure
     * written for something that has no approvals; it is optional so that the
     * ajax re-render can paint a panel before the step is saved anywhere.
     *
     * @param array<string,mixed> $config
     */
    public static function render(string $type, array $config, bool $canedit, ?Sop $sop = null): void
    {
        switch ($type) {
            case StepType::TEXT:
            case StepType::TEXTAREA:
                self::renderText($config, $canedit);
                return;

            case StepType::NUMBER:
                self::renderNumber($config, $canedit);
                return;

            case StepType::CHOICE:
            case StepType::MULTICHOICE:
                self::renderChoice($config, $canedit);
                return;

            case StepType::ASSET:
                self::renderAsset($config, $canedit);
                return;

            case StepType::APPROVAL:
                self::renderApproval($config, $canedit, $sop);
                return;

            case StepType::TICKET:
                self::renderTicket($config, $canedit);
                return;
        }
    }

    /**
     * Fold a submitted options panel back into the config array.
     *
     * Only the keys belonging to `$type` are kept, so switching a step from
     * "number" to "choice" does not leave a stale min/max behind to confuse
     * whoever reads the row next.
     *
     * @param array<string,mixed> $cfg the `cfg` sub-array the builder posted
     * @return array<string,mixed>
     */
    public static function collect(string $type, array $cfg): array
    {
        $config = [];

        switch ($type) {
            case StepType::TEXT:
            case StepType::TEXTAREA:
                $config['placeholder'] = trim((string) ($cfg['placeholder'] ?? ''));
                $config['pattern']     = trim((string) ($cfg['pattern'] ?? ''));
                break;

            case StepType::NUMBER:
                $config['min']  = trim((string) ($cfg['min'] ?? ''));
                $config['max']  = trim((string) ($cfg['max'] ?? ''));
                $config['unit'] = trim((string) ($cfg['unit'] ?? ''));
                break;

            case StepType::CHOICE:
            case StepType::MULTICHOICE:
                $options = [];
                foreach (preg_split('/\R/', (string) ($cfg['options'] ?? '')) as $line) {
                    $line = trim((string) $line);
                    if ($line !== '' && !in_array($line, $options, true)) {
                        $options[] = $line;
                    }
                }
                $config['options'] = $options;
                break;

            case StepType::ASSET:
                $config['itemtypes'] = array_values(array_filter(
                    array_map('strval', (array) ($cfg['itemtypes'] ?? [])),
                    static fn(string $itemtype): bool => class_exists($itemtype)
                ));
                break;

            case StepType::APPROVAL:
                $config['require_status'] = (int) ($cfg['require_status'] ?? 0);
                $config['users_id']       = (int) ($cfg['users_id'] ?? 0);
                $config['groups_id']      = (int) ($cfg['groups_id'] ?? 0);
                break;

            case StepType::TICKET:
                $config['title']             = trim((string) ($cfg['title'] ?? ''));
                $config['content']           = trim((string) ($cfg['content'] ?? ''));
                $config['itilcategories_id'] = (int) ($cfg['itilcategories_id'] ?? 0);
                $config['groups_id_assign']  = (int) ($cfg['groups_id_assign'] ?? 0);
                $config['type']              = (int) ($cfg['type'] ?? Ticket::DEMAND_TYPE);
                $config['link_as']           = (string) ($cfg['link_as'] ?? ChildTicket::LINK_SON)
                    === ChildTicket::LINK_PLAIN ? ChildTicket::LINK_PLAIN : ChildTicket::LINK_SON;
                $config['complete_on']       = (string) ($cfg['complete_on'] ?? ChildTicket::ON_CREATED)
                    === ChildTicket::ON_CLOSED ? ChildTicket::ON_CLOSED : ChildTicket::ON_CREATED;
                break;
        }

        return $config;
    }

    // ------------------------------------------------------------- the panels

    private static function renderText(array $config, bool $canedit): void
    {
        echo "<div class='row g-3'>";

        self::open('placeholder', __('Placeholder', 'glpisop'), 'col-md-6');
        echo "<input type='text' class='form-control' name='sopcfg_placeholder' value='"
           . self::e($config['placeholder'] ?? '') . "'" . self::disabled($canedit) . '>';
        self::close();

        self::open(
            'pattern',
            __('Required format (regular expression)', 'glpisop'),
            'col-md-6',
            __('Optional. An answer that does not match is refused — useful for a serial number '
                . 'or an asset tag with a house format. Leave empty to accept anything.', 'glpisop')
        );
        echo "<input type='text' class='form-control font-monospace' name='sopcfg_pattern' value='"
           . self::e($config['pattern'] ?? '') . "'" . self::disabled($canedit) . '>';
        self::close();

        echo '</div>';
    }

    private static function renderNumber(array $config, bool $canedit): void
    {
        echo "<div class='row g-3'>";

        foreach (['min' => __('Minimum', 'glpisop'), 'max' => __('Maximum', 'glpisop')] as $key => $label) {
            self::open($key, $label, 'col-md-4');
            echo "<input type='number' step='any' class='form-control' name='sopcfg_$key' value='"
               . self::e($config[$key] ?? '') . "'" . self::disabled($canedit) . '>';
            self::close();
        }

        self::open('unit', __('Unit', 'glpisop'), 'col-md-4');
        echo "<input type='text' class='form-control' name='sopcfg_unit' value='"
           . self::e($config['unit'] ?? '') . "'" . self::disabled($canedit) . '>';
        self::close();

        echo '</div>';
    }

    /**
     * The choice list.
     *
     * Marked `data-sop-branch-options` because the builder reads it live: a
     * later step gated on this one offers *these* answers in its value box, and
     * it offers them as they are being typed rather than as they were when the
     * page loaded. A branch keyed on a typo is invisible until a run silently
     * fails to open it, and the fix is to make the two lists the same list.
     */
    private static function renderChoice(array $config, bool $canedit): void
    {
        self::open(
            'options',
            __('Options, one per line', 'glpisop'),
            '',
            __('These are also what a later step can be gated on. Renaming one does not rewrite '
                . 'branches that point at the old text, so rename with care once the SOP is in '
                . 'use.', 'glpisop')
        );
        echo "<textarea class='form-control font-monospace' rows='5' name='sopcfg_options' "
           . "data-sop-branch-options" . self::disabled($canedit) . '>'
           . self::e(implode("\n", StepType::options($config))) . '</textarea>';
        self::close();
    }

    private static function renderAsset(array $config, bool $canedit): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        self::open(
            'itemtypes',
            __('Allowed asset types', 'glpisop'),
            '',
            __('None ticked means every type GLPI allows on a ticket.', 'glpisop'),
            true
        );

        echo '<div>';
        foreach ((array) ($CFG_GLPI['ticket_types'] ?? ['Computer']) as $itemtype) {
            if (!class_exists($itemtype)) {
                continue;
            }
            $checked = in_array($itemtype, (array) ($config['itemtypes'] ?? []), true);
            echo "<label class='form-check form-check-inline'>";
            echo "<input type='checkbox' class='form-check-input' name='sopcfg_itemtypes' value='"
               . self::e($itemtype) . "'" . ($checked ? " checked='checked'" : '')
               . self::disabled($canedit) . '>';
            echo "<span class='form-check-label'>" . self::e($itemtype::getTypeName(1)) . '</span></label>';
        }
        echo '</div>';

        self::close();
    }

    private static function renderApproval(array $config, bool $canedit, ?Sop $sop): void
    {
        echo "<div class='row g-3'>";

        self::open('require_status', __('The item must be', 'glpisop'), 'col-md-4');
        Dropdown::showFromArray('sopcfg_require_status', Approval::requirableStatuses(), [
            'value'    => Approval::requiredStatus($config),
            'readonly' => !$canedit,
            'width'    => '100%',
        ]);
        self::close();

        self::open('users_id', __('Approved by (optional)', 'glpisop'), 'col-md-4');
        Dropdown::show(User::class, [
            'name'                => 'sopcfg_users_id',
            'value'               => (int) ($config['users_id'] ?? 0),
            'right'               => 'all',
            'display_emptychoice' => true,
            'width'               => '100%',
            'readonly'            => !$canedit,
        ]);
        self::close();

        self::open('groups_id', __('Or by a member of (optional)', 'glpisop'), 'col-md-4');
        Dropdown::show(Group::class, [
            'name'                => 'sopcfg_groups_id',
            'value'               => (int) ($config['groups_id'] ?? 0),
            'display_emptychoice' => true,
            'width'               => '100%',
            'readonly'            => !$canedit,
        ]);
        self::close();

        echo '</div>';

        echo "<div class='form-text'>"
           . __s('This step cannot be ticked. It is satisfied by the item’s own approval record '
               . 'and goes back to outstanding if that record changes — so an enforcing SOP holds '
               . 'the item open until the approval is really there, and reopens if it is '
               . 'withdrawn. Naming a person or a group narrows it further: the item must be '
               . 'approved, and approved by them.', 'glpisop')
           . '</div>';

        if (
            $sop !== null
            && !in_array('Ticket', $sop->itemtypes(), true)
            && !in_array('Change', $sop->itemtypes(), true)
        ) {
            echo "<div class='alert alert-warning mt-2 mb-0'>"
               . __s('This SOP is not written for tickets or changes, and nothing else in GLPI has '
                   . 'approvals — this step can never be satisfied.', 'glpisop')
               . '</div>';
        }
    }

    private static function renderTicket(array $config, bool $canedit): void
    {
        self::open(
            'title',
            __('Ticket title', 'glpisop'),
            '',
            __('%item% becomes “Ticket #482”, %title% the title of the item the procedure is '
                . 'running on. Left empty, the step’s own label is used.', 'glpisop')
        );
        echo "<input type='text' class='form-control' name='sopcfg_title' value='"
           . self::e($config['title'] ?? '') . "'" . self::disabled($canedit) . '>';
        self::close();

        self::open(
            'content',
            __('Ticket description', 'glpisop'),
            '',
            __('The same two placeholders. Left empty, the step’s guidance is used.', 'glpisop')
        );
        echo "<textarea class='form-control' rows='3' name='sopcfg_content'"
           . self::disabled($canedit) . '>' . self::e($config['content'] ?? '') . '</textarea>';
        self::close();

        echo "<div class='row g-3'>";

        self::open('itilcategories_id', __('Category'), 'col-md-6');
        Dropdown::show(ITILCategory::class, [
            'name'     => 'sopcfg_itilcategories_id',
            'value'    => (int) ($config['itilcategories_id'] ?? 0),
            'width'    => '100%',
            'readonly' => !$canedit,
        ]);
        self::close();

        self::open('groups_id_assign', __('Assign to group', 'glpisop'), 'col-md-6');
        Dropdown::show(Group::class, [
            'name'      => 'sopcfg_groups_id_assign',
            'value'     => (int) ($config['groups_id_assign'] ?? 0),
            'width'     => '100%',
            'condition' => ['is_assign' => 1],
            'readonly'  => !$canedit,
        ]);
        self::close();

        self::open('type', __('Type'), 'col-md-4');
        Dropdown::showFromArray('sopcfg_type', [
            Ticket::INCIDENT_TYPE => Ticket::getTicketTypeName(Ticket::INCIDENT_TYPE),
            Ticket::DEMAND_TYPE   => Ticket::getTicketTypeName(Ticket::DEMAND_TYPE),
        ], [
            'value'    => (int) ($config['type'] ?? Ticket::DEMAND_TYPE),
            'readonly' => !$canedit,
            'width'    => '100%',
        ]);
        self::close();

        self::open('link_as', __('Link it', 'glpisop'), 'col-md-4');
        Dropdown::showFromArray('sopcfg_link_as', ChildTicket::linkModes(), [
            'value'    => (string) ($config['link_as'] ?? ChildTicket::LINK_SON),
            'readonly' => !$canedit,
            'width'    => '100%',
        ]);
        self::close();

        self::open(
            'complete_on',
            __('This step is done', 'glpisop'),
            'col-md-4',
            __('“Only when that ticket is solved” is what lets an enforcing SOP hold this item '
                . 'open until the work it asked for is actually finished — the licence bought, '
                . 'the access granted — rather than until somebody remembered to ask.', 'glpisop')
        );
        Dropdown::showFromArray('sopcfg_complete_on', ChildTicket::completionModes(), [
            'value'    => ChildTicket::completionMode($config),
            'readonly' => !$canedit,
            'width'    => '100%',
        ]);
        self::close();

        echo '</div>';
    }

    // -------------------------------------------------------------- plumbing

    /**
     * Open one option's wrapper.
     *
     * `$multi` marks a key whose control is a set of checkboxes rather than one
     * value; the serialiser collects every ticked box under it instead of the
     * first control it finds.
     */
    private static function open(
        string $key,
        string $label,
        string $column = '',
        string $help = '',
        bool $multi = false
    ): void {
        echo "<div class='" . ($column !== '' ? self::e($column) : 'mb-3')
           . "' data-sop-cfg='" . self::e($key) . "'"
           . " data-sop-cfg-name='sopcfg_" . self::e($key) . "'"
           . ($multi ? " data-sop-cfg-multi='1'" : '') . '>';
        echo "<label class='form-label'>" . self::e($label) . '</label>';

        // The help text is emitted after the control by close(); holding it
        // here keeps each call site to one pair of calls with the control
        // between them, which is what makes a missing close() obvious.
        self::$pending_help = $help;
    }

    private static function close(): void
    {
        if (self::$pending_help !== '') {
            echo "<div class='form-text'>" . self::e(self::$pending_help) . '</div>';
            self::$pending_help = '';
        }
        echo '</div>';
    }

    private static function disabled(bool $canedit): string
    {
        return $canedit ? '' : ' disabled';
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
