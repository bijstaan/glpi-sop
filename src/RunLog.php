<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use User;

/**
 * An append-only record of what happened to a run.
 *
 * GLPI's own history is not usable here. `Log` follows an item's *fields*, and
 * an answer changing from "No" to "Yes" three hours later is not a field
 * change on the ticket — it is the substance of what the SOP is for. A
 * procedure that exists to demonstrate that something was done needs to be able
 * to show when it was done and by whom, including the answers that were
 * subsequently corrected.
 *
 * Nothing here is ever updated or deleted except with the run itself.
 */
final class RunLog
{
    public const TABLE = 'glpi_plugin_glpisop_runlogs';

    public const ATTACHED  = 'attached';
    public const ANSWERED  = 'answered';
    public const CLEARED   = 'cleared';
    public const SKIPPED   = 'skipped';
    public const COMPLETED = 'completed';
    public const REOPENED  = 'reopened';
    public const ABANDONED = 'abandoned';
    public const UNLOCKED  = 'unlocked';
    public const RELOCKED  = 'relocked';

    public static function add(int $runs_id, string $action, int $steps_id = 0, string $detail = ''): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->insert(self::TABLE, [
            'plugin_glpisop_runs_id'  => $runs_id,
            'plugin_glpisop_steps_id' => $steps_id,
            'users_id'                => (int) \Session::getLoginUserID(),
            'action'                  => $action,
            // Bounded: the detail is a human note, and an unbounded one turns
            // the audit trail into somewhere to store a file.
            'detail'                  => mb_substr($detail, 0, 1000),
            'date_creation'           => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The trail for a run, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forRun(int $runs_id, int $limit = 100): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['plugin_glpisop_runs_id' => $runs_id],
                'ORDER' => ['id DESC'],
                'LIMIT' => $limit,
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    /** @return array<string,string> */
    public static function actionLabels(): array
    {
        return [
            self::ATTACHED  => __('attached', 'glpisop'),
            self::ANSWERED  => __('answered', 'glpisop'),
            self::CLEARED   => __('cleared', 'glpisop'),
            self::SKIPPED   => __('skipped', 'glpisop'),
            self::COMPLETED => __('completed', 'glpisop'),
            self::REOPENED  => __('reopened', 'glpisop'),
            self::ABANDONED => __('abandoned', 'glpisop'),
            self::UNLOCKED  => __('unlocked for editing', 'glpisop'),
            self::RELOCKED  => __('locked again', 'glpisop'),
        ];
    }

    public static function actorName(int $users_id): string
    {
        if ($users_id <= 0) {
            return __('the system', 'glpisop');
        }

        $user = new User();

        return $user->getFromDB($users_id) ? $user->getFriendlyName() : ('#' . $users_id);
    }
}
