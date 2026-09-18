<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonGLPI;

/**
 * The "Steps" tab on an SOP: the procedure itself.
 *
 * A thin tab over {@see Builder}, which is where the editor lives. It used to
 * be a table of steps whose every edit was a page — open a step to set its
 * type, come back, open it again to gate it, press an arrow to move it and
 * reload — and the reasoning was that a step's definition is too large for a
 * row. It is; the mistake was making the row the only alternative to a page.
 *
 * What replaced it is GLPI 11's own form editor in shape: a column of cards,
 * one open at a time, everything edited in place, one Save.
 */
class SopBuilderTab extends CommonGLPI
{
    public static $rightname = 'plugin_glpisop_sop';

    public static function getTypeName($nb = 0)
    {
        return __('Steps', 'glpisop');
    }

    public static function getIcon()
    {
        return 'ti ti-list-numbers';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Sop) || $item->isNewItem()) {
            return '';
        }

        $count = count(Step::allFor((int) $item->getID(), false));

        return self::createTabEntry(self::getTypeName(), $count, Sop::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Sop) || $item->isNewItem()) {
            return false;
        }

        Builder::render($item);

        return true;
    }
}
