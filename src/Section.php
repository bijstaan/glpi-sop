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
    public static $rightname = 'plugin_glpisop_sop';

    public static $itemtype = Sop::class;
    public static $items_id = 'plugin_glpisop_sops_id';

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

    /**
     * Move a section one place up or down.
     *
     * Section order dominates step order — {@see Step::allFor()} sorts by it
     * first — so without this an author who created their headings in the wrong
     * order had no way to fix the shape of the procedure except by deleting a
     * heading and refiling every step under it. The steps travel with the
     * section, because that is what a heading means.
     *
     * Ranks are rewritten in full rather than swapped, so that sections seeded
     * or imported with equal ranks come out of the first move with a strict
     * order instead of a no-op.
     */
    public static function reorder(int $sections_id, string $direction): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $section = new self();
        if (!$section->getFromDB($sections_id)) {
            return false;
        }

        $sops_id  = (int) $section->fields['plugin_glpisop_sops_id'];
        $ordered  = array_values(self::allFor($sops_id));
        $position = null;
        foreach ($ordered as $index => $row) {
            if ((int) $row['id'] === $sections_id) {
                $position = $index;
                break;
            }
        }

        $target = $position === null
            ? null
            : ($direction === 'up' ? $position - 1 : $position + 1);

        if ($target === null || !isset($ordered[$target])) {
            return false;
        }

        $moved = $ordered[$position];
        array_splice($ordered, $position, 1);
        array_splice($ordered, $target, 0, [$moved]);

        foreach ($ordered as $index => $row) {
            $rank = $index + 1;
            if ($rank !== (int) $row['rank_order']) {
                $DB->update(self::getTable(), ['rank_order' => $rank], ['id' => (int) $row['id']]);
            }
        }

        Sop::bumpVersion($sops_id);

        return true;
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

    public function post_addItem()
    {
        Sop::bumpVersion((int) $this->fields['plugin_glpisop_sops_id']);
    }

    public function post_updateItem($history = true)
    {
        Sop::bumpVersion((int) $this->fields['plugin_glpisop_sops_id']);
    }
}
