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
 * The "Triggers" tab on an SOP: when it puts itself on an item.
 *
 * The panel opens with what the current trigger set actually means, spelled
 * out as a sentence, because the single most expensive mistake here is an
 * author who believes they have written "category is Hardware AND urgency is
 * high" and has in fact written OR. The rest of the tab is the list and one
 * add row.
 */
class SopTriggerTab extends CommonGLPI
{
    public static string $rightname = 'plugin_glpisop_sop';

    public static function getTypeName($nb = 0)
    {
        return __('Triggers', 'glpisop');
    }

    public static function getIcon()
    {
        return 'ti ti-filter';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Sop) || $item->isNewItem()) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(),
            count(Trigger::allFor((int) $item->getID())),
            Sop::class,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Sop) || $item->isNewItem()) {
            return false;
        }

        $sops_id  = (int) $item->getID();
        $canedit  = Session::haveRight(self::$rightname, UPDATE);
        $triggers = Trigger::allFor($sops_id);
        $e        = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        // glpisop-surface: scope class for sop.css's dark-theme muted-text fixes.
        echo "<div class='p-3 glpisop-surface'>";

        self::renderSummary($item, $triggers);

        if ($triggers !== []) {
            echo "<table class='table table-sm'><tbody>";
            foreach ($triggers as $trigger) {
                echo '<tr>';
                echo '<td>' . $e(Trigger::describe($trigger)) . '</td>';
                if ($canedit) {
                    echo "<td class='text-end' style='width:4rem'>";
                    echo "<form method='post' action='" . $e(Url::to('front/trigger.form.php')) . "'>";
                    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                    echo Html::hidden('id', ['value' => (int) $trigger['id']]);
                    echo "<button type='submit' name='purge' value='1' class='btn btn-sm btn-ghost-danger'>"
                       . "<i class='ti ti-trash'></i></button>";
                    echo '</form></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        if ($canedit) {
            self::renderAddTrigger($item);
        }

        echo '</div>';

        return true;
    }

    /** @param array<int,array<string,mixed>> $triggers */
    private static function renderSummary(Sop $sop, array $triggers): void
    {
        $autoattach = (int) $sop->fields['is_autoattach'] === 1;
        $globally   = Settings::flag('auto_attach');

        if (!$globally) {
            echo "<div class='alert alert-warning'>"
               . __s('Automatic attachment is switched off for the whole plugin, so these triggers '
                   . 'are not being evaluated. The SOP can still arrive from an ITIL template or a '
                   . 'business rule.', 'glpisop')
               . '</div>';
        } elseif (!$autoattach) {
            echo "<div class='alert alert-warning'>"
               . __s('This SOP does not attach itself — see “Attaches itself” on the main tab. '
                   . 'These triggers are stored but not evaluated.', 'glpisop')
               . '</div>';
        } elseif ($triggers === []) {
            echo "<div class='alert alert-info'>"
               . __s('No triggers: this SOP attaches to every item of its itemtypes, in its entity. '
                   . 'That is a legitimate setting for a procedure that genuinely always applies — '
                   . 'add a trigger to narrow it.', 'glpisop')
               . '</div>';
        } else {
            $joiner = (int) $sop->fields['match_all'] === 1
                ? __('every one of these holds', 'glpisop')
                : __('any one of these holds', 'glpisop');

            echo "<div class='alert alert-info'>"
               . htmlspecialchars(
                   sprintf(
                       __('This SOP attaches itself to a matching %1$s when %2$s.', 'glpisop'),
                       implode(', ', array_map(
                           static fn(string $t): string => $t::getTypeName(1),
                           $sop->itemtypes()
                       )),
                       $joiner
                   ),
                   ENT_QUOTES,
                   'UTF-8'
               )
               . '</div>';
        }
    }

    /**
     * The add row: one row, one Add.
     *
     * Which conditions and which value control are offered both depend on the
     * criterion, and this used to be two forms — pick the criterion, press
     * "Change criterion", get the tab back rendered for it. That button could
     * not work *at all* from inside a tab: it put `criterion` in the page URL,
     * but a GLPI tab body is fetched separately by ajax/common.tabs.php with a
     * fixed parameter list, so the value never reached the code below and the
     * criterion silently stayed on the first one in the list.
     *
     * The dependent half is now rebuilt over AJAX by the select itself, from
     * the same renderer that paints it here — see {@see ClauseForm}.
     */
    private static function renderAddTrigger(Sop $sop): void
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        // Criteria that make sense for *any* of the SOP's itemtypes.
        $criteria = [];
        foreach ($sop->itemtypes() as $itemtype) {
            foreach (Trigger::criteriaFor($itemtype) as $key => $definition) {
                $criteria[$key] = (string) $definition['name'];
            }
        }

        if ($criteria === []) {
            return;
        }

        $chosen = (string) array_key_first($criteria);
        // A DOM id, not a token: GLPI's own Html:: helpers seed widget ids this
        // way so two of the same dropdown on one page do not collide. Predicting
        // it buys an attacker an element id already readable in the markup.
        $rand   = mt_rand();

        echo "<div class='mt-3 pt-3 border-top'>";
        echo '<h4>' . __s('Add a trigger', 'glpisop') . '</h4>';

        echo "<form method='post' action='" . $e(Url::to('front/trigger.form.php')) . "' "
           . "class='row g-2 align-items-end'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('plugin_glpisop_sops_id', ['value' => (int) $sop->getID()]);

        echo "<div class='col-md-3'><label class='form-label'>" . __s('Criterion', 'glpisop') . '</label>';
        \Dropdown::showFromArray('criterion', $criteria, [
            'value' => $chosen,
            'rand'  => $rand,
            'width' => '100%',
        ]);
        echo '</div>';

        echo "<div class='col-md-7' id='glpisop-trigger-tail$rand'>";
        ClauseForm::triggerTail($chosen);
        echo '</div>';

        echo "<div class='col-md-2'><button type='submit' name='add' value='1' class='btn btn-primary w-100'>"
           . __s('Add') . '</button></div>';

        echo '</form>';
        echo '</div>';

        ClauseForm::cascade(
            "dropdown_criterion$rand",
            "glpisop-trigger-tail$rand",
            ['context' => 'trigger', 'criterion' => '__VALUE__']
        );
    }
}
