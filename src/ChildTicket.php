<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use Change_Ticket;
use CommonDBTM;
use CommonITILObject;
use Group_Ticket;
use Problem_Ticket;
use Ticket;
use Ticket_Ticket;

/**
 * The `ticket` step type: a step that raises a linked ticket and is answered
 * by that ticket rather than by a technician.
 *
 * Onboarding is what this exists for. "Do we have M365 licensing available?"
 * is not really a checkbox — when the answer is no, somebody has to go and buy
 * a licence from the CSP, and that is a piece of work with its own owner, its
 * own SLA and its own queue. Modelling it as another tick on the onboarding
 * checklist puts a purchase order inside a provisioning procedure and hides it
 * from everyone whose job it is.
 *
 * So the step raises a real ticket, links it to the item the procedure is
 * running on, and then either counts itself done immediately (`complete_on` =
 * created: the request has been *made*, which is all this procedure needed) or
 * waits for that ticket to be solved (`complete_on` = closed: provisioning
 * genuinely cannot continue until the licence exists).
 *
 * The waiting form is what makes an enforcing SOP hold the parent open until
 * the child is finished, without anybody having to remember to come back and
 * tick something.
 *
 * ### Why the child's state is polled rather than pushed
 *
 * A closing ticket does not know it satisfies a step. {@see self::sync()} is
 * therefore called from {@see Run::recount()} — which already runs on every
 * answer — and from the item_update hook on Ticket, which is what makes the
 * parent's checklist update the moment the child is solved. The hook is the
 * fast path and the recount is the backstop; neither alone is enough, because
 * a hook can be missed by a mass update or a direct database change and a
 * recount only happens when somebody touches the parent.
 */
final class ChildTicket
{
    /** When the step counts as answered. */
    public const ON_CREATED = 'created';
    public const ON_CLOSED  = 'closed';

    /** How the child is attached to the item the procedure is on. */
    public const LINK_SON  = 'son';
    public const LINK_PLAIN = 'link';

    /** @return array<string,string> */
    public static function completionModes(): array
    {
        return [
            self::ON_CREATED => __('as soon as the ticket is raised', 'glpisop'),
            self::ON_CLOSED  => __('only when that ticket is solved or closed', 'glpisop'),
        ];
    }

    /** @return array<string,string> */
    public static function linkModes(): array
    {
        return [
            self::LINK_SON   => __('as a child of this item', 'glpisop'),
            self::LINK_PLAIN => __('as a plain linked ticket', 'glpisop'),
        ];
    }

    public static function completionMode(array $config): string
    {
        return (string) ($config['complete_on'] ?? self::ON_CREATED) === self::ON_CLOSED
            ? self::ON_CLOSED
            : self::ON_CREATED;
    }

    // ------------------------------------------------------------- spawning

    /**
     * Raise the child ticket for one step.
     *
     * Returns the new ticket's id, or an `error` explaining why not. Nothing
     * partial is left behind: either there is a ticket and it is linked, or
     * there is neither.
     *
     * The new ticket is raised *as the technician*, in the item's entity, with
     * the item's requester carried over. That last part is not cosmetic — a
     * licensing request with no requester lands in a queue belonging to nobody,
     * and the person who needs to be told when it is done is the person the
     * onboarding was for.
     *
     * @param array<string,mixed> $config the step's own configuration
     * @return array{error?:string, tickets_id?:int}
     */
    public static function spawn(array $step, array $config, CommonDBTM $parent): array
    {
        if (!($parent instanceof CommonITILObject)) {
            return ['error' => __('This step can only raise a ticket from an ITIL object.', 'glpisop')];
        }

        $ticket = new Ticket();
        if (!$ticket->can(-1, CREATE)) {
            return ['error' => __('You cannot raise tickets.', 'glpisop')];
        }

        $input = [
            'entities_id' => (int) ($parent->fields['entities_id'] ?? 0),
            'name'        => self::renderTemplate(
                (string) ($config['title'] ?? ''),
                (string) $step['label'],
                $parent
            ),
            'content'     => self::renderTemplate(
                (string) ($config['content'] ?? ''),
                (string) ($step['help'] ?? $step['label']),
                $parent
            ),
            'type'        => (int) ($config['type'] ?? Ticket::DEMAND_TYPE),
            '_users_id_requester' => self::requesterOf($parent),
            // The technician who pressed the button owns having raised it.
            '_users_id_observer'  => (int) \Session::getLoginUserID(),
        ];

        $category = (int) ($config['itilcategories_id'] ?? 0);
        if ($category > 0) {
            $input['itilcategories_id'] = $category;
        }

        $group = (int) ($config['groups_id_assign'] ?? 0);
        if ($group > 0) {
            $input['_groups_id_assign'] = $group;
        }

        $tickets_id = (int) $ticket->add($input);
        if ($tickets_id <= 0) {
            return ['error' => __('The ticket could not be raised.', 'glpisop')];
        }

        self::link($parent, $tickets_id, (string) ($config['link_as'] ?? self::LINK_SON));

        return ['tickets_id' => $tickets_id];
    }

    /**
     * The requester to carry over.
     *
     * Falls back to whoever raised the parent, then to the technician, so the
     * child never lands without one. A ticket with no requester cannot be
     * notified, and the whole point of spawning rather than ticking is that
     * somebody else picks the work up and reports back.
     */
    private static function requesterOf(CommonITILObject $parent): int
    {
        foreach ($parent->getUsers(\CommonITILActor::REQUESTER) as $actor) {
            if ((int) $actor['users_id'] > 0) {
                return (int) $actor['users_id'];
            }
        }

        $fallback = (int) ($parent->fields['users_id_recipient'] ?? 0);

        return $fallback > 0 ? $fallback : (int) \Session::getLoginUserID();
    }

    /**
     * Two tokens, and deliberately only two.
     *
     * `%item%` and `%title%` are what an author actually wants — "Order an M365
     * licence for #482: New starter — J. Okafor" — and a full templating
     * language over the parent's fields would be a second, worse copy of GLPI's
     * notification templates.
     */
    private static function renderTemplate(string $template, string $fallback, CommonITILObject $parent): string
    {
        $text = trim($template) !== '' ? $template : $fallback;

        return strtr($text, [
            '%item%'  => sprintf('%s #%d', $parent::getTypeName(1), (int) $parent->getID()),
            '%title%' => (string) ($parent->fields['name'] ?? ''),
        ]);
    }

    /**
     * Attach the child to the parent, by whichever relation the two types have.
     *
     * Only a ticket under a ticket can be a *son*: `SON_OF` is a Ticket_Ticket
     * notion, and GLPI's change and problem relations have no hierarchy. Asking
     * for one under a change therefore quietly becomes a plain link rather than
     * failing — the link is what matters, and refusing to raise a licensing
     * ticket because the procedure happened to be running on a change would be
     * a strange thing to do to somebody mid-onboarding.
     */
    private static function link(CommonITILObject $parent, int $tickets_id, string $mode): void
    {
        switch ($parent::getType()) {
            case 'Ticket':
                $relation = new Ticket_Ticket();
                $relation->add([
                    'tickets_id_1' => (int) $parent->getID(),
                    'tickets_id_2' => $tickets_id,
                    'link'         => $mode === self::LINK_SON
                        ? Ticket_Ticket::PARENT_OF
                        : Ticket_Ticket::LINK_TO,
                ]);
                return;

            case 'Change':
                $relation = new Change_Ticket();
                $relation->add(['changes_id' => (int) $parent->getID(), 'tickets_id' => $tickets_id]);
                return;

            case 'Problem':
                $relation = new Problem_Ticket();
                $relation->add(['problems_id' => (int) $parent->getID(), 'tickets_id' => $tickets_id]);
                return;
        }
    }

    // -------------------------------------------------------------- settling

    /** Has the child ticket reached a state that counts as finished? */
    public static function isSettled(int $tickets_id): bool
    {
        if ($tickets_id <= 0) {
            return false;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            // Purged out from under the step. Treating that as settled is the
            // only reading that does not leave the parent permanently blocked
            // by a ticket nobody can open.
            return true;
        }

        return in_array(
            (int) $ticket->fields['status'],
            [CommonITILObject::SOLVED, CommonITILObject::CLOSED],
            true
        );
    }

    /**
     * Bring every waiting `ticket` step of a run into line with its child.
     *
     * Writes only when the state actually changes, because this runs inside
     * {@see Run::recount()} — which runs on every answer — and a write per
     * render would fill the run log with entries nobody caused.
     *
     * @param array<int,array<string,mixed>> $steps
     * @param array<int,array<string,mixed>> $answers keyed by step id
     * @return bool whether anything changed
     */
    public static function sync(int $runs_id, array $steps, array $answers): bool
    {
        $changed = false;

        foreach ($steps as $step) {
            if ((string) $step['step_type'] !== StepType::TICKET) {
                continue;
            }

            $config = Step::config($step);
            if (self::completionMode($config) !== self::ON_CLOSED) {
                continue;
            }

            $steps_id   = (int) $step['id'];
            $answer     = $answers[$steps_id] ?? Answer::blank($steps_id);
            $tickets_id = (int) ($answer['value_items_id'] ?? 0);

            if ($tickets_id <= 0) {
                continue;
            }

            // A skipped step stays skipped. Somebody said this did not need
            // doing, and a child ticket closing is not a reason to overrule it.
            $state = (string) $answer['state'];
            if ($state === Answer::SKIPPED) {
                continue;
            }

            $settled = self::isSettled($tickets_id);
            $done    = $state === Answer::DONE;

            if ($settled === $done) {
                continue;
            }

            Answer::put($runs_id, $steps_id, [
                'state'          => $settled ? Answer::DONE : Answer::PENDING,
                'value_itemtype' => Ticket::class,
                'value_items_id' => $tickets_id,
                'note'           => $answer['note'] ?? null,
            ], (int) ($answer['users_id'] ?? 0));

            RunLog::add(
                $runs_id,
                $settled ? RunLog::ANSWERED : RunLog::CLEARED,
                $steps_id,
                sprintf(
                    $settled
                        ? __('linked ticket #%d was closed', 'glpisop')
                        : __('linked ticket #%d was reopened', 'glpisop'),
                    $tickets_id
                )
            );

            $changed = true;
        }

        return $changed;
    }

    /**
     * Every run holding a step whose child ticket is this one.
     *
     * @return int[] run ids
     */
    public static function runsWatching(int $tickets_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'SELECT'   => ['plugin_glpisop_runs_id'],
                'DISTINCT' => true,
                'FROM'     => Answer::getTable(),
                'WHERE'    => [
                    'value_itemtype' => Ticket::class,
                    'value_items_id' => $tickets_id,
                ],
            ]) as $row
        ) {
            $out[] = (int) $row['plugin_glpisop_runs_id'];
        }

        return $out;
    }

    // ------------------------------------------------------------- rendering

    /** The child ticket as a link, or an empty string if it has gone. */
    public static function describe(int $tickets_id): string
    {
        $ticket = new Ticket();
        if ($tickets_id <= 0 || !$ticket->getFromDB($tickets_id)) {
            return '';
        }

        return sprintf(
            '%s — %s',
            $ticket->getLink(),
            htmlspecialchars(
                Ticket::getStatus((int) $ticket->fields['status']),
                ENT_QUOTES,
                'UTF-8'
            )
        );
    }
}
