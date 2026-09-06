<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBTM;

/**
 * An SOP bound to an ITIL template.
 *
 * The third attachment path, and the one that needs no matching at all: if a
 * ticket was raised from the "New starter" template, the new-starter procedure
 * belongs on it, and no set of criteria expresses that as directly as saying so.
 *
 * Bound from the template's side — TemplateTab puts the panel on
 * TicketTemplate / ChangeTemplate / ProblemTemplate — because that is where an
 * administrator is when they are thinking about what a template should carry.
 */
class TemplateBinding extends CommonDBTM
{
    public static $rightname = 'plugin_glpisop_sop';

    public static function getTypeName($nb = 0)
    {
        return _n('Template binding', 'Template bindings', $nb, 'glpisop');
    }

    /**
     * The column on an ITIL object naming the template it was raised from.
     *
     * Each ITIL type has its own, and there is no core accessor that maps one
     * to the other.
     */
    public static function templateFieldFor(string $itemtype): ?string
    {
        return match ($itemtype) {
            'Ticket'  => 'tickettemplates_id',
            'Change'  => 'changetemplates_id',
            'Problem' => 'problemtemplates_id',
            default   => null,
        };
    }

    public static function templateTypeFor(string $itemtype): ?string
    {
        return match ($itemtype) {
            'Ticket'  => 'TicketTemplate',
            'Change'  => 'ChangeTemplate',
            'Problem' => 'ProblemTemplate',
            default   => null,
        };
    }

    /**
     * SOP ids bound to a template.
     *
     * @return int[]
     */
    public static function sopsFor(string $template_itemtype, int $template_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($template_id <= 0) {
            return [];
        }

        $out = [];
        foreach (
            $DB->request([
                'SELECT' => ['plugin_glpisop_sops_id'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['itemtype' => $template_itemtype, 'items_id' => $template_id],
            ]) as $row
        ) {
            $out[] = (int) $row['plugin_glpisop_sops_id'];
        }

        return $out;
    }

    /**
     * Templates an SOP is bound to.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forSop(int $sops_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_glpisop_sops_id' => $sops_id],
                'ORDER' => ['itemtype', 'items_id'],
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    public static function bind(int $sops_id, string $template_itemtype, int $template_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($sops_id <= 0 || $template_id <= 0) {
            return;
        }

        try {
            $DB->insert(self::getTable(), [
                'plugin_glpisop_sops_id' => $sops_id,
                'itemtype'               => $template_itemtype,
                'items_id'               => $template_id,
                'date_creation'          => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Already bound. Binding twice is what a double-submit looks like,
            // not an error worth showing anyone.
        }
    }

    public static function unbind(int $sops_id, string $template_itemtype, int $template_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), [
            'plugin_glpisop_sops_id' => $sops_id,
            'itemtype'               => $template_itemtype,
            'items_id'               => $template_id,
        ]);
    }
}
