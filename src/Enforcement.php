<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBTM;
use CommonITILObject;
use ITILSolution;
use Session;

/**
 * Refuses to let an item be resolved while an enforcing SOP still has required
 * steps outstanding.
 *
 * Two doors have to be held, and holding only one is the same as holding
 * neither. A technician can move a ticket to *solved* by changing its status
 * directly, or by filing a solution — and the solution form is the one they
 * actually use, so blocking the status change alone would look like enforcement
 * while enforcing nothing.
 *
 * The refusal is a message, not an exception. GLPI's contract for a plugin that
 * wants to veto a write is to blank `$item->input`; anything thrown here
 * surfaces to the technician as a stack trace on a ticket they were trying to
 * close.
 */
final class Enforcement
{
    /**
     * Statuses that count as "the work is over".
     *
     * @return int[]
     */
    private static function terminalStatuses(): array
    {
        return [CommonITILObject::SOLVED, CommonITILObject::CLOSED];
    }

    /**
     * A status change on an ITIL object.
     *
     * Only a *transition* into a terminal status is checked. Re-saving a ticket
     * that is already closed — reassigning it, correcting a category — must
     * keep working, or an SOP added after the fact makes historical tickets
     * uneditable.
     */
    public static function guardStatusChange(CommonDBTM $item): void
    {
        if (!Settings::flag('enforce_enabled') || !is_array($item->input)) {
            return;
        }

        if (!array_key_exists('status', $item->input)) {
            return;
        }

        $new = (int) $item->input['status'];
        $old = (int) ($item->fields['status'] ?? 0);

        if (!in_array($new, self::terminalStatuses(), true) || in_array($old, self::terminalStatuses(), true)) {
            return;
        }

        $blockers = Run::blockers($item::getType(), (int) $item->getID());
        if ($blockers === []) {
            return;
        }

        self::refuse($blockers);
        $item->input = false;
    }

    /**
     * A solution being filed.
     *
     * Same check, different door. The solution's parent is the item that
     * carries the runs.
     */
    public static function guardSolution(CommonDBTM $solution): void
    {
        if (!Settings::flag('enforce_enabled') || !($solution instanceof ITILSolution)) {
            return;
        }

        if (!is_array($solution->input)) {
            return;
        }

        $itemtype = (string) ($solution->input['itemtype'] ?? '');
        $items_id = (int) ($solution->input['items_id'] ?? 0);

        if (!Settings::appliesTo($itemtype) || $items_id <= 0) {
            return;
        }

        $blockers = Run::blockers($itemtype, $items_id);
        if ($blockers === []) {
            return;
        }

        self::refuse($blockers);
        $solution->input = false;
    }

    /**
     * Say what is missing, by name.
     *
     * "Complete the SOP first" is not an actionable message — a ticket can be
     * carrying three procedures. Naming the SOP and the count is what turns the
     * refusal into an instruction.
     *
     * @param array<int,array{sop:string,outstanding:int}> $blockers
     */
    private static function refuse(array $blockers): void
    {
        foreach ($blockers as $blocker) {
            Session::addMessageAfterRedirect(
                htmlspecialchars(
                    sprintf(
                        _n(
                            '%1$s: %2$d required step is still outstanding.',
                            '%1$s: %2$d required steps are still outstanding.',
                            $blocker['outstanding'],
                            'glpisop'
                        ),
                        $blocker['sop'],
                        $blocker['outstanding']
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                ERROR
            );
        }
    }
}
