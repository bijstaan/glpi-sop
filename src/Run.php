<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBTM;
use CommonITILObject;
use ITILFollowup;

/**
 * One SOP in progress on one item.
 *
 * A run is created by {@see Attacher} and lives until the item is purged. Its
 * counters are denormalised — `done_required` / `total_required` and their
 * visible-step equivalents live on the row rather than being derived on read —
 * because they are wanted in three places where a full recomputation would be
 * wasteful: the fields-panel reminder on every ticket render, the enforcement
 * check on every solve, and the overview page across every run in the instance.
 *
 * They are recomputed, never incremented. An answer can change which steps are
 * visible, which changes what "required" even means for that run, so the only
 * correct update is to re-derive the whole thing from the current answers.
 */
class Run extends CommonDBTM
{
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED   = 'completed';
    public const ABANDONED   = 'abandoned';

    // How the run arrived. Kept because "why is this procedure on my ticket"
    // is the first question a technician asks about an SOP they did not expect.
    public const ORIGIN_TRIGGER  = 'trigger';
    public const ORIGIN_TEMPLATE = 'template';
    public const ORIGIN_RULE     = 'rule';
    /**
     * A technician accepted a suggestion from glpi-ai.
     *
     * Its own origin rather than reusing 'rule', because the label is shown to
     * whoever reads the run later and "assigned by a business rule" would be
     * untrue. Note what it says: a person accepted it. Nothing in this plugin
     * lets a model attach a procedure by itself.
     */
    public const ORIGIN_AI       = 'ai';

    public static $rightname = 'plugin_glpisop_run';

    public static function getTypeName($nb = 0)
    {
        return _n('SOP run', 'SOP runs', $nb, 'glpisop');
    }

    /** @return array<string,string> */
    public static function originLabels(): array
    {
        return [
            self::ORIGIN_TRIGGER  => __('matched this item', 'glpisop'),
            self::ORIGIN_TEMPLATE => __('came from the ITIL template', 'glpisop'),
            self::ORIGIN_RULE     => __('assigned by a business rule', 'glpisop'),
            self::ORIGIN_AI       => __('suggested by AI, accepted by a technician', 'glpisop'),
        ];
    }

    /**
     * Start an SOP on an item, unless it is already there.
     *
     * Idempotent by unique key rather than by a prior SELECT: attachment runs
     * from item_add *and* item_update, and two hooks racing on the same ticket
     * is ordinary rather than exceptional.
     *
     * A run that was abandoned is not resurrected. Someone decided the
     * procedure did not apply, and having it reappear on the next edit of the
     * ticket would make that decision impossible to express.
     */
    public static function attach(Sop $sop, CommonDBTM $item, string $origin): ?int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $itemtype = $item::getType();
        $items_id = (int) $item->getID();

        if ($items_id <= 0 || !$sop->appliesTo($itemtype)) {
            return null;
        }

        $existing = self::existing((int) $sop->getID(), $itemtype, $items_id);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        try {
            $DB->insert(self::getTable(), [
                'plugin_glpisop_sops_id' => (int) $sop->getID(),
                'itemtype'               => $itemtype,
                'items_id'               => $items_id,
                'entities_id'            => (int) ($item->fields['entities_id'] ?? 0),
                'sop_version'            => (int) ($sop->fields['sop_version'] ?? 1),
                'status'                 => self::IN_PROGRESS,
                'origin'                 => $origin,
                'users_id'               => (int) \Session::getLoginUserID(),
                'date_creation'          => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // The unique key is doing its job: another request attached the
            // same SOP first. Its row is the one that counts. DBmysql::insert()
            // throws rather than returning false, so this is the only place the
            // race can be observed.
            $existing = self::existing((int) $sop->getID(), $itemtype, $items_id);
            return $existing !== null ? (int) $existing['id'] : null;
        }

        $runs_id = (int) $DB->insertId();

        RunLog::add($runs_id, RunLog::ATTACHED, 0, self::originLabels()[$origin] ?? $origin);
        self::recount($runs_id);

        return $runs_id;
    }

    /**
     * The ITIL object a run lives on.
     *
     * Wanted by {@see Visibility::evaluate()}, whose clauses can now ask about
     * the item's own fields and approvals rather than only about answers given
     * to the procedure. Null when the row points at an item that has been
     * purged out from under the run; visibility treats that as "cannot
     * evaluate", which shows the step rather than hiding it.
     */
    public static function item(array $run): ?CommonDBTM
    {
        $itemtype = (string) ($run['itemtype'] ?? '');
        if ($itemtype === '' || !class_exists($itemtype)) {
            return null;
        }

        /** @var CommonDBTM $item */
        $item = new $itemtype();

        return $item->getFromDB((int) ($run['items_id'] ?? 0)) ? $item : null;
    }

    /** @return array<string,mixed>|null */
    public static function existing(int $sops_id, string $itemtype, int $items_id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => [
                    'plugin_glpisop_sops_id' => $sops_id,
                    'itemtype'               => $itemtype,
                    'items_id'               => $items_id,
                ],
                'LIMIT' => 1,
            ]) as $row
        ) {
            return $row;
        }

        return null;
    }

    /**
     * Runs on an item, oldest first, joined to their SOP.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forItem(string $itemtype, int $items_id, bool $include_abandoned = true): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = ['itemtype' => $itemtype, 'items_id' => $items_id];
        if (!$include_abandoned) {
            $where['status'] = [self::IN_PROGRESS, self::COMPLETED];
        }

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => $where,
                'ORDER' => ['id'],
            ]) as $row
        ) {
            $sop = new Sop();
            if (!$sop->getFromDB((int) $row['plugin_glpisop_sops_id'])) {
                // The SOP was purged without its runs; nothing sensible to
                // render, so it is skipped rather than shown as an empty
                // procedure claiming to be complete.
                continue;
            }
            $row['sop'] = $sop->fields;
            $out[]      = $row;
        }

        return $out;
    }

    /**
     * Recompute a run's counters from its current answers, and move its status
     * if that changed the picture.
     *
     * The status transition is two-way on purpose. Adding a step to a published
     * SOP, or answering "No" where "Yes" had closed a branch, reopens a run
     * that had completed — which is exactly right: the procedure now has an
     * outstanding question, and the enforcement check reads the same row.
     *
     * @return array{total:int,done:int,total_required:int,done_required:int,outstanding:int[]}
     */
    public static function recount(int $runs_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $run = new self();
        if (!$run->getFromDB($runs_id)) {
            return ['total' => 0, 'done' => 0, 'total_required' => 0, 'done_required' => 0, 'outstanding' => []];
        }

        $steps     = Step::allFor((int) $run->fields['plugin_glpisop_sops_id']);
        $steps_ids = array_map(static fn($s): int => (int) $s['id'], $steps);
        $answers   = Answer::forRun($runs_id, $steps_ids);

        $item = self::item($run->fields);

        if (self::syncExternal($runs_id, $steps, $answers, $item)) {
            $answers = Answer::forRun($runs_id, $steps_ids);
        }

        $visible  = Visibility::evaluate($steps, $answers, $item);
        $progress = Visibility::progress($steps, $answers, $visible);

        $was_complete = (string) $run->fields['status'] === self::COMPLETED;
        // A run with no visible steps at all is complete rather than stuck:
        // every branch of the procedure resolved to "not applicable".
        $is_complete  = $progress['total'] === 0 || $progress['done'] === $progress['total'];

        $update = [
            'total_visible'  => $progress['total'],
            'done_visible'   => $progress['done'],
            'total_required' => $progress['total_required'],
            'done_required'  => $progress['done_required'],
        ];

        if ((string) $run->fields['status'] !== self::ABANDONED) {
            if ($is_complete && !$was_complete) {
                $update['status']            = self::COMPLETED;
                $update['completed_at']      = date('Y-m-d H:i:s');
                $update['users_id_complete'] = (int) \Session::getLoginUserID();
                // Completing re-arms the lock. A run that was unlocked, edited
                // back into progress and then finished again is a finished run
                // like any other; leaving it unlocked because somebody opened
                // it an hour ago would make the lock depend on history nobody
                // can see.
                $update['is_unlocked']       = 0;
            } elseif (!$is_complete && $was_complete) {
                $update['status']            = self::IN_PROGRESS;
                $update['completed_at']      = null;
                $update['users_id_complete'] = 0;
            }
        }

        $DB->update(self::getTable(), $update, ['id' => $runs_id]);

        if (isset($update['status']) && $update['status'] === self::COMPLETED) {
            RunLog::add($runs_id, RunLog::COMPLETED);
            self::postCompletionFollowup($runs_id);
        } elseif (isset($update['status']) && $update['status'] === self::IN_PROGRESS) {
            RunLog::add($runs_id, RunLog::REOPENED);
        }

        return $progress;
    }

    /**
     * Bring the answers that nobody here gives into line with the record.
     *
     * Two step types are answered by something other than a technician: a
     * linked ticket closing, and an approval being granted. Neither of those
     * events happens on this run, so neither can write the answer at the time
     * it happens — the hooks in hook.php recount the watching runs, and this is
     * what those recounts actually do.
     *
     * Called from {@see self::recount()} *and* from the renderer, because the
     * two cover different gaps. The hooks miss what they cannot see — a mass
     * status change, a direct database edit, an approval granted while this
     * plugin was disabled — and a step that reads "waiting for approval" hours
     * after it was granted is worse than one that is slow to update: it is one
     * a technician stops believing. Both syncs write only when something has
     * actually changed, so the render path costs a read and no write in the
     * ordinary case.
     *
     * @param array<int,array<string,mixed>> $steps
     * @param array<int,array<string,mixed>> $answers keyed by step id
     */
    public static function syncExternal(
        int $runs_id,
        array $steps,
        array $answers,
        ?CommonDBTM $item
    ): bool {
        $changed = ChildTicket::sync($runs_id, $steps, $answers);

        return Approval::sync($runs_id, $steps, $answers, $item) || $changed;
    }

    /** Recompute every run of an SOP, after its structure changed. */
    public static function recountAllFor(int $sops_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['plugin_glpisop_sops_id' => $sops_id],
            ]) as $row
        ) {
            self::recount((int) $row['id']);
        }
    }

    /**
     * Required steps still outstanding across every enforcing run on an item.
     *
     * Reads the denormalised counters rather than recomputing: this is called
     * on the path of every solve and close in the instance, and the counters
     * are maintained by the only thing that can change them.
     *
     * @return array<int,array{sop:string,outstanding:int}>
     */
    public static function blockers(string $itemtype, int $items_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!Settings::flag('enforce_enabled')) {
            return [];
        }

        $out = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    Sop::getTable() . '.name AS sop_name',
                    self::getTable() . '.total_required',
                    self::getTable() . '.done_required',
                ],
                'FROM'      => self::getTable(),
                'INNER JOIN' => [
                    Sop::getTable() => [
                        'ON' => [
                            self::getTable() => 'plugin_glpisop_sops_id',
                            Sop::getTable()  => 'id',
                        ],
                    ],
                ],
                'WHERE'     => [
                    self::getTable() . '.itemtype' => $itemtype,
                    self::getTable() . '.items_id' => $items_id,
                    self::getTable() . '.status'   => self::IN_PROGRESS,
                    Sop::getTable() . '.enforce_on_solve' => 1,
                ],
            ]) as $row
        ) {
            $outstanding = (int) $row['total_required'] - (int) $row['done_required'];
            if ($outstanding > 0) {
                $out[] = ['sop' => (string) $row['sop_name'], 'outstanding' => $outstanding];
            }
        }

        return $out;
    }

    /**
     * Write the finished procedure into the item's timeline.
     *
     * The point is not notification — it is that six months later the ticket
     * itself carries what was checked, without the reader needing to know this
     * plugin exists or still be able to reach a tab it renders.
     */
    private static function postCompletionFollowup(int $runs_id): void
    {
        if (!Settings::flag('followup_on_complete')) {
            return;
        }

        $run = new self();
        if (!$run->getFromDB($runs_id)) {
            return;
        }

        $itemtype = (string) $run->fields['itemtype'];
        if (!is_a($itemtype, CommonITILObject::class, true)) {
            return;
        }

        $sop = new Sop();
        if (!$sop->getFromDB((int) $run->fields['plugin_glpisop_sops_id'])) {
            return;
        }

        $steps   = Step::allFor((int) $run->fields['plugin_glpisop_sops_id']);
        $answers = Answer::forRun($runs_id, array_map(static fn($s): int => (int) $s['id'], $steps));
        $visible = Visibility::evaluate($steps, $answers, self::item($run->fields));

        $lines = [
            sprintf(
                __('SOP completed: %1$s (r%2$d)', 'glpisop'),
                (string) $sop->fields['name'],
                (int) $run->fields['sop_version']
            ),
            '',
        ];

        foreach ($steps as $step) {
            $steps_id = (int) $step['id'];
            if (!($visible[$steps_id] ?? false)) {
                continue;
            }

            $answer = $answers[$steps_id];
            $state  = (string) $answer['state'];
            $value  = StepType::format((string) $step['step_type'], $answer);

            // A skipped step reads as skipped at a glance, from the mark alone.
            // Someone scanning this six months later is looking for the steps
            // that were *not* done.
            $line = ($state === Answer::SKIPPED ? '⊘ ' : '✓ ') . (string) $step['label'];

            if ($state === Answer::SKIPPED) {
                $line .= ' — ' . __('skipped', 'glpisop');
                if (trim((string) $answer['note']) !== '') {
                    $line .= ': ' . (string) $answer['note'];
                }
            } elseif ($value !== '' && $value !== __('done', 'glpisop')) {
                $line .= ': ' . $value;
            }

            $lines[] = $line;
        }

        $followup = new ITILFollowup();
        $followup->add([
            'itemtype'    => $itemtype,
            'items_id'    => (int) $run->fields['items_id'],
            // nl2br rather than <pre>: GLPI renders followups as rich text and
            // strips the whitespace a plain newline would have carried.
            'content'     => nl2br(htmlspecialchars(implode("\n", $lines), ENT_QUOTES, 'UTF-8')),
            'is_private'  => Settings::flag('followup_private') ? 1 : 0,
            'users_id'    => (int) \Session::getLoginUserID(),
            // The completion is a record, not a status change. Letting it move
            // the ticket would mean finishing a checklist silently reassigned
            // the work.
            '_do_not_compute_status' => true,
        ]);
    }

    /** Give up on a run without deleting the record of it. */
    public static function abandon(int $runs_id, string $reason = ''): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(
            self::getTable(),
            ['status' => self::ABANDONED, 'completed_at' => date('Y-m-d H:i:s')],
            ['id' => $runs_id]
        );

        RunLog::add($runs_id, RunLog::ABANDONED, 0, $reason);
    }

    public static function purgeRun(int $runs_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(Answer::getTable(), ['plugin_glpisop_runs_id' => $runs_id]);
        $DB->delete(RunLog::TABLE, ['plugin_glpisop_runs_id' => $runs_id]);
        $DB->delete(self::getTable(), ['id' => $runs_id]);
    }

    /** Everything this plugin holds about an item, for when it is purged. */
    public static function purgeForItem(string $itemtype, int $items_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $items_id],
            ]) as $row
        ) {
            self::purgeRun((int) $row['id']);
        }
    }

    /**
     * Is this run locked because it is finished?
     *
     * Distinct from {@see Settings::isFrozen()}, which is the age-based freeze.
     * This one takes effect the instant the last step is answered, so the
     * moment a procedure becomes a record it stops being a draft.
     */
    public static function isLocked(array $run): bool
    {
        return Settings::flag('lock_on_complete')
            && (string) $run['status'] === self::COMPLETED
            && (int) ($run['is_unlocked'] ?? 0) !== 1;
    }

    /**
     * May the current user *reach* this run's controls at all?
     *
     * The permission half of editability, without the lock. Unlocking has to
     * be possible on a run that is locked — otherwise the lock has no key —
     * so the two questions are asked separately.
     */
    public static function isPermitted(array $run): bool
    {
        if (!\Session::haveRight(self::$rightname, UPDATE)) {
            return false;
        }

        if ((string) $run['status'] === self::ABANDONED) {
            return false;
        }

        $itemtype = (string) $run['itemtype'];
        if (!class_exists($itemtype)) {
            return false;
        }

        /** @var CommonDBTM $item */
        $item = new $itemtype();

        return $item->getFromDB((int) $run['items_id']) && $item->canUpdateItem();
    }

    /**
     * May the current user answer steps on this run?
     *
     * Three questions, all of which have to hold: the SOP right is what makes
     * someone a technician who runs procedures, update access to the underlying
     * ticket is what makes them a technician on *this* one, and the run must
     * not be locked or frozen.
     */
    public static function isEditable(array $run): bool
    {
        return self::isPermitted($run)
            && !self::isLocked($run)
            && !Settings::isFrozen($run['completed_at'] ?? null);
    }

    /**
     * Reopen a finished run for editing, or close it again.
     *
     * Logged either way. The lock is not a permission — anyone who can answer
     * the procedure can unlock it — it is a speed bump that makes editing a
     * finished record a thing somebody chose to do, and the log is what makes
     * that choice visible afterwards.
     */
    public static function setUnlocked(int $runs_id, bool $unlocked): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(self::getTable(), ['is_unlocked' => $unlocked ? 1 : 0], ['id' => $runs_id]);

        RunLog::add($runs_id, $unlocked ? RunLog::UNLOCKED : RunLog::RELOCKED);
    }
}
