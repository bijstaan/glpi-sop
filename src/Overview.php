<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

/**
 * Adoption reporting: what the procedures are actually doing once published.
 *
 * Two questions, and the second is the one worth having. "How many runs
 * completed" tells an administrator whether the SOP is being used. "Which
 * steps get skipped" tells them which step is wrong — a step that most
 * technicians skip is not evidence of an undisciplined team, it is a step that
 * asks for something unavailable, unclear, or already done elsewhere, and
 * without this table nobody ever finds out.
 */
final class Overview
{
    /**
     * Per-SOP counts.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function bySop(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => Sop::getTable(),
                'WHERE' => getEntitiesRestrictCriteria(Sop::getTable(), '', '', true),
                'ORDER' => ['name'],
            ]) as $sop
        ) {
            $rows[(int) $sop['id']] = [
                'id'          => (int) $sop['id'],
                'name'        => (string) $sop['name'],
                'is_active'   => (int) $sop['is_active'],
                'enforce'     => (int) $sop['enforce_on_solve'],
                'total'       => 0,
                'completed'   => 0,
                'in_progress' => 0,
                'abandoned'   => 0,
                'steps_done'  => 0,
                'steps_total' => 0,
            ];
        }

        if ($rows === []) {
            return [];
        }

        foreach (
            $DB->request([
                'SELECT' => [
                    'plugin_glpisop_sops_id',
                    'status',
                    'COUNT'  => 'id AS runs',
                    'SUM'    => ['done_visible AS done', 'total_visible AS total'],
                ],
                'FROM'    => Run::getTable(),
                'WHERE'   => ['plugin_glpisop_sops_id' => array_keys($rows)],
                'GROUPBY' => ['plugin_glpisop_sops_id', 'status'],
            ]) as $group
        ) {
            $sops_id = (int) $group['plugin_glpisop_sops_id'];
            if (!isset($rows[$sops_id])) {
                continue;
            }

            $count = (int) $group['runs'];
            $rows[$sops_id]['total']       += $count;
            $rows[$sops_id]['steps_done']  += (int) $group['done'];
            $rows[$sops_id]['steps_total'] += (int) $group['total'];

            switch ((string) $group['status']) {
                case Run::COMPLETED:
                    $rows[$sops_id]['completed'] += $count;
                    break;
                case Run::ABANDONED:
                    $rows[$sops_id]['abandoned'] += $count;
                    break;
                default:
                    $rows[$sops_id]['in_progress'] += $count;
            }
        }

        return array_values($rows);
    }

    /**
     * The steps technicians decline to do, most-skipped first.
     *
     * Reported as a rate rather than a count: a step skipped twelve times out
     * of two thousand runs is noise, and the same twelve out of fifteen is a
     * step that should not be in the procedure.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function skippedSteps(int $limit = 15): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        // How many runs each step has been *asked* in — the denominator. A step
        // behind a branch that rarely opens has a small one, which is exactly
        // right: it is only skippable when it is asked.
        $answered = [];
        $skipped  = [];

        // Joined through the run for its `entities_id`: answers and steps carry
        // no entity of their own, so aggregating the answers table on its own
        // counts every tenant's and then names their procedures and step
        // wordings in the table below. bySop() above is restricted; this has to
        // be too, and the run is the only row in the chain that knows where it
        // happened.
        foreach (
            $DB->request([
                'SELECT'     => [
                    Answer::getTable() . '.plugin_glpisop_steps_id AS plugin_glpisop_steps_id',
                    Answer::getTable() . '.state AS state',
                    'COUNT' => Answer::getTable() . '.id AS answers',
                ],
                'FROM'       => Answer::getTable(),
                'INNER JOIN' => [
                    Run::getTable() => [
                        'ON' => [
                            Answer::getTable() => 'plugin_glpisop_runs_id',
                            Run::getTable()    => 'id',
                        ],
                    ],
                ],
                'WHERE'      => getEntitiesRestrictCriteria(Run::getTable(), 'entities_id', '', false),
                'GROUPBY'    => [
                    Answer::getTable() . '.plugin_glpisop_steps_id',
                    Answer::getTable() . '.state',
                ],
            ]) as $group
        ) {
            $steps_id = (int) $group['plugin_glpisop_steps_id'];
            $count    = (int) $group['answers'];

            $answered[$steps_id] = ($answered[$steps_id] ?? 0) + $count;
            if ((string) $group['state'] === Answer::SKIPPED) {
                $skipped[$steps_id] = ($skipped[$steps_id] ?? 0) + $count;
            }
        }

        if ($skipped === []) {
            return [];
        }

        $out = [];
        foreach ($skipped as $steps_id => $count) {
            $step = new Step();
            if (!$step->getFromDB($steps_id)) {
                continue;
            }

            $sop = new Sop();
            if (
                !$sop->getFromDB((int) $step->fields['plugin_glpisop_sops_id'])
                || !$sop->canViewItem()
            ) {
                continue;
            }

            $total = max(1, $answered[$steps_id] ?? 1);
            $out[] = [
                'sops_id'  => (int) $sop->getID(),
                'sop'      => (string) $sop->fields['name'],
                'label'    => (string) $step->fields['label'],
                'required' => (int) $step->fields['is_required'],
                'skipped'  => $count,
                'answered' => $total,
                'rate'     => (int) round(($count / $total) * 100),
            ];
        }

        usort($out, static fn(array $a, array $b): int => $b['rate'] <=> $a['rate']
            ?: $b['skipped'] <=> $a['skipped']);

        return array_slice($out, 0, $limit);
    }
}
