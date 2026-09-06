<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use Session;

/**
 * The record of one procedure drafted from resolved tickets.
 *
 * A row per drafting run, written when the SOP is created and never updated.
 * It exists for one reason: an authored procedure is a *proposal*, and the
 * only honest way to say whether the proposals are any good is to look at what
 * happened to them afterwards.
 *
 * Two numbers fall out, and neither needs anybody to click anything extra:
 *
 *  - **Activation.** A drafted SOP arrives inactive. Somebody switching it on
 *    is the accept, and it is recorded by `sops.is_active` — which is where it
 *    would have been recorded anyway, so nothing here is instrumentation for
 *    its own sake.
 *  - **Steps kept.** `steps_created` is what the model proposed; counting the
 *    SOP's steps now says how much of it survived an author reading it. "Nine
 *    of twelve kept" is a statement about draft quality that an accept rate on
 *    its own cannot make.
 *
 * The ticket ids are kept because the interesting failure is a procedure
 * drafted from the wrong dozen tickets, and without the list there is no way
 * to look. They are ids rather than content: this table is a provenance note,
 * not a second copy of the evidence.
 */
final class Authoring
{
    public const TABLE = 'glpi_plugin_glpisop_authorings';

    /**
     * Record a drafting run.
     *
     * @param int[] $tickets_id the tickets the draft was built from
     */
    public static function record(
        int $sops_id,
        int $entities_id,
        int $itilcategories_id,
        array $tickets_id,
        int $steps_created,
        string $provider,
        string $model,
        string $confidence,
        string $gaps
    ): int {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->insert(self::TABLE, [
            'plugin_glpisop_sops_id' => $sops_id,
            'entities_id'            => $entities_id,
            'itilcategories_id'      => $itilcategories_id,
            'tickets_count'          => count($tickets_id),
            'ticket_ids'             => implode(',', array_map('intval', $tickets_id)),
            'steps_created'          => $steps_created,
            'provider'               => mb_substr($provider, 0, 64),
            'model'                  => mb_substr($model, 0, 128),
            'confidence'             => mb_substr($confidence, 0, 16),
            'gaps'                   => mb_substr($gaps, 0, 2000),
            'users_id'               => (int) Session::getLoginUserID(),
            'date_creation'          => date('Y-m-d H:i:s'),
        ]);

        return (int) $DB->insertId();
    }

    /**
     * What became of the procedures drafted here, newest first.
     *
     * Left-joined to the SOP rather than inner-joined: a drafted procedure an
     * author deleted outright is the strongest possible rejection, and an
     * inner join would quietly drop exactly those rows and flatter the
     * numbers.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function outcomes(int $limit = 20): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    self::TABLE . '.*',
                    Sop::getTable() . '.name AS sop_name',
                    Sop::getTable() . '.is_active AS sop_active',
                ],
                'FROM'      => self::TABLE,
                'LEFT JOIN' => [
                    Sop::getTable() => [
                        'ON' => [
                            self::TABLE     => 'plugin_glpisop_sops_id',
                            Sop::getTable() => 'id',
                        ],
                    ],
                ],
                // No `is_recursive` on this table, so no recursive flag: the
                // rows are visible in the entity they were drafted in.
                'WHERE'     => getEntitiesRestrictCriteria(self::TABLE, 'entities_id'),
                'ORDER'     => self::TABLE . '.id DESC',
                'LIMIT'     => $limit,
            ]) as $row
        ) {
            $row['deleted']    = $row['sop_name'] === null;
            $row['steps_now']  = $row['deleted']
                ? 0
                : countElementsInTable(Step::getTable(), [
                    'plugin_glpisop_sops_id' => (int) $row['plugin_glpisop_sops_id'],
                ]);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The two numbers, over everything visible.
     *
     * @return array{drafted:int,activated:int,deleted:int,steps_created:int,steps_kept:int}
     */
    public static function summary(): array
    {
        $out = [
            'drafted'       => 0,
            'activated'     => 0,
            'deleted'       => 0,
            'steps_created' => 0,
            'steps_kept'    => 0,
        ];

        foreach (self::outcomes(500) as $row) {
            $out['drafted']++;
            $out['steps_created'] += (int) $row['steps_created'];

            if ($row['deleted']) {
                $out['deleted']++;
                continue;
            }

            $out['steps_kept'] += (int) $row['steps_now'];
            if ((int) $row['sop_active'] === 1) {
                $out['activated']++;
            }
        }

        return $out;
    }
}
