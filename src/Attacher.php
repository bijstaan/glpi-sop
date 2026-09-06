<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBTM;

/**
 * Decides which SOPs belong on an item, and puts them there.
 *
 * Three independent paths converge here — a trigger set that matched, an ITIL
 * template that carries the SOP, and a business rule that named it — and they
 * are deliberately additive rather than prioritised. Each answers a different
 * question ("what kind of work is this", "what was this raised as", "what did
 * the triage rules decide") and an item can be all three at once.
 *
 * Everything is idempotent. This runs on create *and* on every update, so the
 * only thing standing between it and a duplicate run on every ticket edit is
 * the unique key in Run::attach() — which is why that constraint is in the
 * schema rather than in a check here.
 */
final class Attacher
{
    /**
     * Attach whatever now applies to this item.
     *
     * @param bool $on_create false when called from an update, where the
     *                        administrator may have chosen not to re-evaluate
     * @return int[] ids of runs that were created
     */
    public static function evaluate(CommonDBTM $item, bool $on_create): array
    {
        $itemtype = $item::getType();
        if (!Settings::appliesTo($itemtype) || (int) $item->getID() <= 0) {
            return [];
        }

        if (!$on_create && !Settings::flag('attach_on_update')) {
            return [];
        }

        // The candidate set gates all three paths, not just the trigger one: an
        // SOP that is inactive, or published in an entity this item is not in,
        // does not attach however it was named. A business rule pointing at an
        // out-of-scope SOP is a misconfigured rule, and honouring it would let
        // rules quietly defeat the entity model.
        $entities_id = (int) ($item->fields['entities_id'] ?? 0);
        $candidates  = Sop::activeFor($itemtype, $entities_id);
        if ($candidates === []) {
            return [];
        }

        // Rule- and template-sourced ids are collected first so that an SOP
        // named by both a rule and its own triggers is attributed to the more
        // specific source. Origin is only ever a note to a reader, but a note
        // that says "matched this item" about a rule assignment is a wrong one.
        $named = self::fromRules($item) + self::fromTemplate($item);

        $attached = [];
        foreach ($candidates as $row) {
            $sops_id = (int) $row['id'];

            $origin = $named[$sops_id] ?? null;
            if ($origin === null) {
                if (!Settings::flag('auto_attach') || (int) $row['is_autoattach'] !== 1) {
                    continue;
                }
                if (!self::triggersMatch($row, $item)) {
                    continue;
                }
                $origin = Run::ORIGIN_TRIGGER;
            }

            $sop = new Sop();
            if (!$sop->getFromDB($sops_id)) {
                continue;
            }

            $runs_id = Run::attach($sop, $item, $origin);
            if ($runs_id !== null) {
                $attached[] = $runs_id;
            }
        }

        return $attached;
    }

    /**
     * Do this SOP's triggers hold for the item?
     *
     * An SOP with `is_autoattach` set but no triggers at all attaches to
     * everything of its itemtype. That is a real configuration — "every change
     * gets the change-control checklist" — and refusing it would force an
     * author to invent a criterion that is always true.
     */
    private static function triggersMatch(array $sop_row, CommonDBTM $item): bool
    {
        $triggers = Trigger::allFor((int) $sop_row['id']);
        if ($triggers === []) {
            return true;
        }

        $match_all = (int) ($sop_row['match_all'] ?? 1) === 1;

        foreach ($triggers as $trigger) {
            $matched = Trigger::matches($trigger, $item);

            if ($match_all && !$matched) {
                return false;
            }
            if (!$match_all && $matched) {
                return true;
            }
        }

        return $match_all;
    }

    /**
     * SOP ids named by GLPI's rules engine on this item.
     *
     * The ids ride in on `_plugin_glpisop_sops`, appended by the rule action
     * registered in hook.php. The underscore prefix is what keeps them in
     * `$item->input` after the write: GLPI filters input down to real columns
     * before it touches the table, and everything else survives untouched for
     * exactly this kind of post-write work.
     *
     * @return array<int,string> sops_id => origin
     */
    private static function fromRules(CommonDBTM $item): array
    {
        $raw = $item->input['_plugin_glpisop_sops'] ?? null;
        if ($raw === null) {
            return [];
        }

        $out = [];
        foreach ((array) $raw as $sops_id) {
            $sops_id = (int) $sops_id;
            if ($sops_id > 0) {
                $out[$sops_id] = Run::ORIGIN_RULE;
            }
        }

        return $out;
    }

    /**
     * SOP ids bound to the ITIL template this item was raised from.
     *
     * @return array<int,string> sops_id => origin
     */
    private static function fromTemplate(CommonDBTM $item): array
    {
        $itemtype = $item::getType();
        $field    = TemplateBinding::templateFieldFor($itemtype);
        $type     = TemplateBinding::templateTypeFor($itemtype);

        if ($field === null || $type === null) {
            return [];
        }

        $template_id = (int) ($item->fields[$field] ?? 0);
        if ($template_id <= 0) {
            return [];
        }

        $out = [];
        foreach (TemplateBinding::sopsFor($type, $template_id) as $sops_id) {
            $out[$sops_id] = Run::ORIGIN_TEMPLATE;
        }

        return $out;
    }
}
