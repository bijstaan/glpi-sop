<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBChild;

/**
 * A named group of steps within an SOP.
 *
 * Purely presentational — a section neither gates nor is gated. Long
 * procedures are unreadable as one flat list, and headings are how an author
 * says "this part is diagnosis and this part is remediation" without inventing
 * a second kind of step.
 *
 * Every SOP has exactly one implicit section (id 0) holding steps that were
 * never filed under a heading, so an author is never forced to create one.
 */
class Section extends CommonDBChild
{
    public static string $rightname = 'plugin_glpisop_sop';

    public static string $itemtype = Sop::class;
    public static string $items_id = 'plugin_glpisop_sops_id';

    public static function getTypeName($nb = 0)
    {
        return _n('Section', 'Sections', $nb, 'glpisop');
    }

    /**
     * Sections of an SOP in display order, keyed by id.
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
                'ORDER' => ['rank_order', 'id'],
            ]) as $row
        ) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    /** Next free rank, so a new section lands at the bottom. */
    public static function nextRank(int $sops_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['MAX' => 'rank_order AS max_rank'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['plugin_glpisop_sops_id' => $sops_id],
            ]) as $row
        ) {
            return ((int) $row['max_rank']) + 1;
        }

        return 1;
    }

    /**
     * Deleting a section keeps its steps, moving them back to the unfiled
     * group. An author tidying headings should not lose the procedure.
     */
    public function cleanDBonPurge()
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(
            Step::getTable(),
            ['plugin_glpisop_sections_id' => 0],
            ['plugin_glpisop_sections_id' => (int) $this->fields['id']]
        );

        Sop::bumpVersion((int) $this->fields['plugin_glpisop_sops_id']);
    }

    /**
     * A heading is presentation, so its own edits move nothing in a run — but
     * they do change the order steps are read in, which is what the revision
     * marks. No recount: the set of steps and which are required is untouched.
     */
    public function post_addItem()
    {
        Sop::bumpVersion((int) $this->fields['plugin_glpisop_sops_id']);
    }

    public function post_updateItem($history = true)
    {
        Sop::bumpVersion((int) $this->fields['plugin_glpisop_sops_id']);
    }
}
