<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonGLPI;
use Html;
use Session;

/**
 * The "SOPs" tab on an ITIL template.
 *
 * Bound from the template's side rather than the SOP's, because the question
 * being answered is "what does this template carry?" — which is what an
 * administrator is thinking about while they are configuring the template, and
 * where they will look for it again.
 */
class TemplateTab extends CommonGLPI
{
    public static $rightname = 'plugin_glpisop_sop';

    public static function getTypeName($nb = 0)
    {
        return _n('SOP', 'SOPs', $nb, 'glpisop');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    /** The ITIL itemtype a given template type produces. */
    private static function itemtypeFor(string $template_itemtype): ?string
    {
        return match ($template_itemtype) {
            'TicketTemplate'  => 'Ticket',
            'ChangeTemplate'  => 'Change',
            'ProblemTemplate' => 'Problem',
            default           => null,
        };
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof \CommonDBTM) || $item->isNewItem()) {
            return '';
        }

        $itemtype = self::itemtypeFor($item::getType());
        if ($itemtype === null || !Settings::appliesTo($itemtype)) {
            return '';
        }

        if (!Session::haveRight(self::$rightname, READ)) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(2),
            count(TemplateBinding::sopsFor($item::getType(), (int) $item->getID())),
            $item::getType(),
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof \CommonDBTM) || $item->isNewItem()) {
            return false;
        }

        $template_type = $item::getType();
        $itemtype      = self::itemtypeFor($template_type);
        if ($itemtype === null) {
            return false;
        }

        $template_id = (int) $item->getID();
        $canedit     = Session::haveRight(self::$rightname, UPDATE);
        $bound       = TemplateBinding::sopsFor($template_type, $template_id);
        $e           = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        // glpisop-surface: scope class for sop.css's dark-theme muted-text fixes.
        echo "<div class='p-3 glpisop-surface'>";
        echo '<p class="text-muted">'
           . __s('Items raised from this template start these procedures, whatever the SOP’s own '
               . 'triggers say. Entity scoping still applies: an SOP published in one entity does '
               . 'not attach to items in another.', 'glpisop')
           . '</p>';

        // Candidates are the SOPs valid for the resulting itemtype in the
        // template's entity — the same set that could ever attach, so the
        // dropdown cannot offer a binding that would silently never fire.
        $candidates = [];
        foreach (Sop::activeFor($itemtype, (int) ($item->fields['entities_id'] ?? 0)) as $sop) {
            $candidates[(int) $sop['id']] = (string) $sop['name'];
        }

        if ($bound !== []) {
            echo "<table class='table table-sm'><tbody>";
            foreach ($bound as $sops_id) {
                $sop = new Sop();
                if (!$sop->getFromDB($sops_id)) {
                    continue;
                }

                echo '<tr><td>' . $sop->getLink() . '</td>';
                if ($canedit) {
                    echo "<td class='text-end' style='width:4rem'>";
                    echo "<form method='post' action='" . $e(Url::to('front/binding.form.php')) . "'>";
                    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                    echo Html::hidden('plugin_glpisop_sops_id', ['value' => $sops_id]);
                    echo Html::hidden('itemtype', ['value' => $template_type]);
                    echo Html::hidden('items_id', ['value' => $template_id]);
                    echo "<button type='submit' name='unbind' value='1' class='btn btn-sm btn-ghost-danger'>"
                       . "<i class='ti ti-trash'></i></button>";
                    echo '</form></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo "<p class='text-muted'>" . __s('Nothing bound yet.', 'glpisop') . '</p>';
        }

        if ($canedit) {
            $available = array_diff_key($candidates, array_flip($bound));

            if ($available === []) {
                echo "<p class='text-muted'>"
                   . __s('No further SOPs are published for this itemtype in this entity.', 'glpisop')
                   . '</p>';
            } else {
                echo "<form method='post' action='" . $e(Url::to('front/binding.form.php')) . "' "
                   . "class='row g-2 align-items-end'>";
                echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                echo Html::hidden('itemtype', ['value' => $template_type]);
                echo Html::hidden('items_id', ['value' => $template_id]);
                echo "<div class='col-md-6'>";
                \Dropdown::showFromArray('plugin_glpisop_sops_id', $available, []);
                echo '</div>';
                echo "<div class='col-md-2'><button type='submit' name='bind' value='1' "
                   . "class='btn btn-primary w-100'>" . __s('Bind') . '</button></div>';
                echo '</form>';
            }
        }

        echo '</div>';

        return true;
    }
}
