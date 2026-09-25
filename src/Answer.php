<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBTM;

/**
 * One technician's answer to one step of one run.
 *
 * Rows are created lazily: a run starts with no answers at all, and a step
 * gains one the first time somebody touches it. An absent row and a row in
 * state `pending` therefore mean the same thing, which is why every read goes
 * through {@see self::forRun()} and fills the gaps rather than assuming the
 * set is complete.
 */
class Answer extends CommonDBTM
{
    public const PENDING = 'pending';
    public const DONE    = 'done';
    public const SKIPPED = 'skipped';

    public static string $rightname = 'plugin_glpisop_run';

    public static function getTypeName($nb = 0)
    {
        return _n('Answer', 'Answers', $nb, 'glpisop');
    }

    /** The empty answer, as a step that has never been touched looks. */
    public static function blank(int $steps_id): array
    {
        return [
            'id'                     => 0,
            'plugin_glpisop_steps_id' => $steps_id,
            'state'                  => self::PENDING,
            'value'                  => null,
            'value_itemtype'         => null,
            'value_items_id'         => 0,
            'documents_id'           => 0,
            'note'                   => null,
            'users_id'               => 0,
            'date_mod'               => null,
        ];
    }

    /**
     * Every answer of a run, keyed by step, with blanks filled in for the
     * steps nobody has touched.
     *
     * @param int[] $steps_ids
     * @return array<int,array<string,mixed>>
     */
    public static function forRun(int $runs_id, array $steps_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach ($steps_ids as $steps_id) {
            $out[(int) $steps_id] = self::blank((int) $steps_id);
        }

        if ($runs_id <= 0 || $steps_ids === []) {
            return $out;
        }

        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => [
                    'plugin_glpisop_runs_id'  => $runs_id,
                    'plugin_glpisop_steps_id' => $steps_ids,
                ],
            ]) as $row
        ) {
            $out[(int) $row['plugin_glpisop_steps_id']] = $row;
        }

        return $out;
    }

    /** A single answer, blank if the step has never been touched. */
    public static function one(int $runs_id, int $steps_id): array
    {
        return self::forRun($runs_id, [$steps_id])[$steps_id] ?? self::blank($steps_id);
    }

    /**
     * Write an answer, replacing whatever was there.
     *
     * Upsert rather than add-or-update-by-id: the unique key is (run, step),
     * and two technicians answering the same step in the same second is a
     * normal thing on a busy ticket rather than a race worth failing over.
     */
    public static function put(int $runs_id, int $steps_id, array $columns, int $users_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now = date('Y-m-d H:i:s');
        $row = [
            'plugin_glpisop_runs_id'  => $runs_id,
            'plugin_glpisop_steps_id' => $steps_id,
            'state'                   => (string) ($columns['state'] ?? self::PENDING),
            'value'                   => $columns['value'] ?? null,
            'value_itemtype'          => $columns['value_itemtype'] ?? null,
            'value_items_id'          => (int) ($columns['value_items_id'] ?? 0),
            'documents_id'            => (int) ($columns['documents_id'] ?? 0),
            'note'                    => $columns['note'] ?? null,
            'users_id'                => $users_id,
            'date_mod'                => $now,
        ];

        $existing = self::one($runs_id, $steps_id);
        if ((int) $existing['id'] > 0) {
            $DB->update(self::getTable(), $row, ['id' => (int) $existing['id']]);
            return;
        }

        $row['date_creation'] = $now;
        $DB->insert(self::getTable(), $row);
    }

    /**
     * Blank a step back out.
     *
     * The row is deleted rather than set to `pending` so that "never answered"
     * and "answered then cleared" are not two states the rest of the code has
     * to keep straight. The run log is where the clearing is recorded.
     */
    public static function clear(int $runs_id, int $steps_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), [
            'plugin_glpisop_runs_id'  => $runs_id,
            'plugin_glpisop_steps_id' => $steps_id,
        ]);
    }

    public static function isAnswered(array $answer): bool
    {
        return in_array((string) ($answer['state'] ?? self::PENDING), [self::DONE, self::SKIPPED], true);
    }
}
