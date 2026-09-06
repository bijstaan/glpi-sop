<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use Config;
use Session;

/**
 * Plugin settings, with defaults.
 *
 * Everything here is a *global gate*: it can only ever take capability away
 * from an individual SOP, never grant it. Enforcement is the clearest case —
 * an SOP that wants to block a solve does so only while `enforce_enabled` is
 * on, so an administrator who finds a misconfigured procedure holding up a
 * queue has one switch to reach for rather than an audit of every SOP.
 */
final class Settings
{
    /**
     * Itemtypes the plugin is ever willing to work with.
     *
     * Distinct from the `itemtypes` setting, which is the administrator's
     * subset of this. Registering tabs and hooks reads the constant so that
     * enabling Problems takes effect on the next request rather than on the
     * next time GLPI rebuilds its tab map.
     */
    public const SUPPORTED_ITEMTYPES = ['Ticket', 'Change', 'Problem'];

    public const DEFAULTS = [
        // Which ITIL types can carry an SOP at all.
        'itemtypes'            => 'Ticket,Change,Problem',

        // Match trigger criteria and attach automatically. With this off, SOPs
        // arrive only from ITIL templates and business rules.
        'auto_attach'          => 1,

        // Re-evaluate triggers when the item changes, not just when it is
        // created. Triage is exactly when the category is still wrong, so this
        // is where most real attachments happen.
        'attach_on_update'     => 1,

        // Master switch for the per-SOP "block solve until required steps are
        // done" flag. See the class comment.
        'enforce_enabled'      => 1,

        // Transcribe the finished procedure into a followup.
        //
        // Off by default, and that is a change of mind rather than an
        // oversight: the checklist is itself a timeline entry now, so the
        // followup no longer makes the procedure *visible* — it duplicates it.
        // What it still buys is durability, which is a different and narrower
        // want: a followup is plain ticket data, so it survives this plugin
        // being disabled or removed, and it reaches notification emails and
        // printed exports, which a plugin-rendered timeline entry does not.
        // Sites that need the record to outlive the plugin turn it on.
        'followup_on_complete' => 0,

        // That followup is internal: an SOP is technician workflow and its
        // answers are not written for the requester.
        'followup_private'     => 1,

        // Freeze a run the moment it completes, so the record of what was done
        // is not quietly editable afterwards. Reopening it is a deliberate,
        // logged act rather than a side effect of clicking in a field.
        'lock_on_complete'     => 1,

        // Let a technician skip a required step by giving a reason. Off means a
        // required step has exactly one way out, which is doing it.
        'allow_skip'           => 1,
        'require_skip_reason'  => 1,

        // Runs finished this long ago stop being editable. 0 disables the
        // freeze entirely.
        'lock_after_days'      => 0,

        // Drafting a procedure from resolved tickets, through glpi-ai. On by
        // default and still inert without glpi-ai configured and this entity
        // permitted by its tenant gate — this switch is here so a site that
        // has glpi-ai for other things can decline this use of it, which is a
        // decision about sending ticket history to a vendor rather than about
        // whether the feature works.
        'authoring_enabled'     => 1,

        // How far back a draft looks, and how many tickets it reads.
        //
        // Twelve is a judgement about prompts, not about statistics: each
        // ticket arrives compressed to ~1,800 characters, and past a dozen the
        // shared shape stops getting clearer while the prompt keeps growing.
        // Six months is long enough to reach a monthly recurrence and short
        // enough that the procedure describes how the work is done now.
        'authoring_window_days' => 180,
        'authoring_max_tickets' => 12,

        // Below this many usable tickets there is no pattern to find, and what
        // comes back is one ticket generalised into a procedure — which reads
        // exactly like a procedure and is not one.
        'authoring_min_tickets' => 4,
    ];

    /** @return array<string,int|string> */
    public static function all(): array
    {
        $stored = Config::getConfigurationValues(
            PLUGIN_GLPISOP_CONFIG_CONTEXT,
            array_keys(self::DEFAULTS)
        );

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $stored[$key] ?? null;
            if ($value === null || $value === '') {
                $out[$key] = $default;
                continue;
            }
            $out[$key] = is_int($default) ? (int) $value : (string) $value;
        }

        return self::reconcile($out);
    }

    public static function get(string $key): int|string
    {
        return self::all()[$key] ?? self::DEFAULTS[$key];
    }

    public static function flag(string $key): bool
    {
        return ((int) self::get($key)) === 1;
    }

    /**
     * Correct a stored config that contradicts itself, in memory, rather than
     * rejecting it — a bad value should make the plugin behave like the nearest
     * sane one, never take it down.
     */
    private static function reconcile(array $s): array
    {
        foreach (
            ['auto_attach', 'attach_on_update', 'enforce_enabled', 'followup_on_complete',
                'followup_private', 'allow_skip', 'require_skip_reason', 'lock_on_complete'] as $flag
        ) {
            $s[$flag] = ((int) $s[$flag]) === 1 ? 1 : 0;
        }

        // Asking for a reason for something nobody can do is not a setting.
        if ($s['allow_skip'] === 0) {
            $s['require_skip_reason'] = 0;
        }

        $s['lock_after_days'] = max(0, (int) $s['lock_after_days']);

        $s['authoring_enabled'] = ((int) $s['authoring_enabled']) === 1 ? 1 : 0;

        // Clamped rather than validated at the form: these three also arrive
        // from a config row somebody edited by hand, and a max of 0 would make
        // the feature look broken rather than misconfigured.
        $s['authoring_window_days'] = min(3650, max(7, (int) $s['authoring_window_days']));
        $s['authoring_max_tickets'] = min(30, max(2, (int) $s['authoring_max_tickets']));
        $s['authoring_min_tickets'] = min(
            (int) $s['authoring_max_tickets'],
            max(1, (int) $s['authoring_min_tickets'])
        );

        return $s;
    }

    /**
     * Itemtypes SOPs may be attached to: the administrator's choice, narrowed
     * to what the plugin supports and what actually exists in this install.
     *
     * @return string[]
     */
    public static function itemtypes(): array
    {
        $out = [];
        foreach (explode(',', (string) self::get('itemtypes')) as $type) {
            $type = trim($type);
            if (
                $type !== ''
                && in_array($type, self::SUPPORTED_ITEMTYPES, true)
                && class_exists($type)
            ) {
                $out[] = $type;
            }
        }

        return $out;
    }

    public static function appliesTo(string $itemtype): bool
    {
        return in_array($itemtype, self::itemtypes(), true);
    }

    /**
     * Whether a finished run is still editable.
     *
     * A completed procedure is a record of what was done. Once it is old enough
     * that nobody is plausibly still working the ticket, editing it is revision
     * of history rather than correction of a typo.
     */
    public static function isFrozen(?string $completed_at): bool
    {
        $days = (int) self::get('lock_after_days');
        if ($days <= 0 || $completed_at === null || $completed_at === '') {
            return false;
        }

        return strtotime($completed_at) < (time() - ($days * 86400));
    }

    public static function save(array $input): void
    {
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = $input[$key];
            }
        }
        if ($values !== []) {
            Config::setConfigurationValues(PLUGIN_GLPISOP_CONFIG_CONTEXT, $values);
        }
    }

    /** SOPs are technician workflow; the helpdesk interface never shows them. */
    public static function inCentralInterface(): bool
    {
        return Session::getCurrentInterface() === 'central';
    }
}
