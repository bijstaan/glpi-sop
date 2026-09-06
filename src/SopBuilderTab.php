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
 * The "Steps" tab on an SOP: the procedure itself.
 *
 * A flat, ordered table rather than a drag-and-drop canvas. The thing an
 * author needs to see at a glance is which steps are required and what gates
 * what — a canvas shows neither without hovering, and a procedure that has
 * grown a branch nobody remembers writing is the failure mode worth designing
 * against.
 *
 * Editing one step is a full page (front/step.form.php) because a step's
 * definition is genuinely large: type, type-specific configuration, and the
 * branch condition. Squeezing that into a table row produces a row nobody can
 * read and a form nobody can fill in.
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

        $sops_id  = (int) $item->getID();
        $canedit  = Session::haveRight(self::$rightname, UPDATE);
        $steps    = Step::allFor($sops_id, false);
        $sections = Section::allFor($sops_id);
        $numbers  = Renderer::numbering($steps);
        $e        = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        // glpisop-surface: scope class for sop.css's dark-theme muted-text fixes.
        echo "<div class='p-3 glpisop-surface'>";

        self::renderSections($sops_id, $sections, $canedit);

        echo "<h4 class='mt-4'>" . __s('Steps', 'glpisop') . '</h4>';

        if ($steps === []) {
            echo "<p class='text-muted'>"
               . __s('No steps yet. An SOP with no steps attaches and immediately reports itself complete.', 'glpisop')
               . '</p>';
        } else {
            echo "<table class='table table-sm sop-builder-steps'>";
            echo '<thead><tr>';
            echo '<th style="width:3rem">#</th>';
            echo '<th>' . __s('Step', 'glpisop') . '</th>';
            echo '<th>' . __s('Type', 'glpisop') . '</th>';
            echo '<th>' . __s('Section', 'glpisop') . '</th>';
            echo '<th class="text-center">' . __s('Required', 'glpisop') . '</th>';
            echo '<th class="text-center">' . __s('Active') . '</th>';
            if ($canedit) {
                echo '<th class="text-end">' . __s('Order', 'glpisop') . '</th>';
            }
            echo '</tr></thead><tbody>';

            $last_index = count($steps) - 1;
            foreach ($steps as $index => $step) {
                $steps_id = (int) $step['id'];
                $inactive = (int) $step['is_active'] !== 1;

                echo "<tr" . ($inactive ? " class='text-muted'" : '') . '>';
                echo "<td class='sop-builder-rank'>" . $e($numbers[$steps_id] ?? '') . '</td>';

                echo '<td>';
                echo "<a href='" . $e(Url::to('front/step.form.php?id=' . $steps_id)) . "'>"
                   . $e($step['label']) . '</a>';
                if (trim((string) $step['help']) !== '') {
                    echo "<div class='sop-builder-depends'>" . $e($step['help']) . '</div>';
                }
                $gate = Condition::describeAll(
                    Step::conditions($step),
                    Step::mode($step),
                    $numbers
                );
                if ($gate !== '') {
                    echo "<div class='sop-builder-depends'>"
                       . "<i class='ti ti-corner-down-right'></i> " . $e($gate) . '</div>';
                }
                echo '</td>';

                echo '<td>' . $e(StepType::label((string) $step['step_type'])) . '</td>';

                $sections_id = (int) $step['plugin_glpisop_sections_id'];
                echo '<td>' . $e($sections[$sections_id]['name'] ?? '—') . '</td>';

                echo "<td class='text-center'>"
                   . ((int) $step['is_required'] === 1
                       ? "<span class='sop-required'>*</span>"
                       : '')
                   . '</td>';

                echo "<td class='text-center'>"
                   . ($inactive ? "<i class='ti ti-eye-off'></i>" : "<i class='ti ti-check'></i>")
                   . '</td>';

                if ($canedit) {
                    echo "<td class='text-end'>";
                    echo self::moveButton(
                        $steps_id,
                        'up',
                        $index === 0,
                        self::crossesInto($steps, $sections, $index, -1)
                    );
                    echo self::moveButton(
                        $steps_id,
                        'down',
                        $index === $last_index,
                        self::crossesInto($steps, $sections, $index, 1)
                    );
                    echo '</td>';
                }

                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        if ($canedit) {
            self::renderAddStep($sops_id, $steps, $sections);
        }

        echo '</div>';

        return true;
    }

    /** The same arrows, for a heading. Its steps travel with it. */
    private static function sectionMoveButton(int $sections_id, string $direction, bool $disabled): string
    {
        $icon = "<i class='ti ti-arrow-" . ($direction === 'up' ? 'up' : 'down') . "'></i>";

        if ($disabled) {
            return "<span class='btn btn-sm btn-ghost-secondary disabled'>$icon</span>";
        }

        return "<form method='post' action='"
            . htmlspecialchars(Url::to('front/section.form.php'), ENT_QUOTES, 'UTF-8')
            . "' class='d-inline'>"
            . Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()])
            . Html::hidden('id', ['value' => $sections_id])
            . Html::hidden('move', ['value' => $direction])
            . "<button type='submit' class='btn btn-sm btn-ghost-secondary'>$icon</button>"
            . '</form>';
    }

    /**
     * The heading a step would be refiled under, or '' if it stays put.
     *
     * @param array<int,array<string,mixed>> $steps
     * @param array<int,array<string,mixed>> $sections
     */
    private static function crossesInto(array $steps, array $sections, int $index, int $delta): string
    {
        $target = $index + $delta;
        if (!isset($steps[$target])) {
            return '';
        }

        $mine   = (int) $steps[$index]['plugin_glpisop_sections_id'];
        $theirs = (int) $steps[$target]['plugin_glpisop_sections_id'];

        if ($mine === $theirs) {
            return '';
        }

        return (string) ($sections[$theirs]['name'] ?? __('no heading', 'glpisop'));
    }

    /**
     * One arrow.
     *
     * The arrows move a step through the *flat* list on screen, and at a
     * heading boundary they refile it instead — see {@see Step::reorder()}.
     * Only the true ends of the procedure are greyed out, and where a press
     * will refile rather than move, the tooltip names the heading, so the
     * change is something the author read beforehand rather than noticed
     * afterwards.
     */
    private static function moveButton(
        int $steps_id,
        string $direction,
        bool $disabled,
        string $lands_in = ''
    ): string {
        $icon = "<i class='ti ti-arrow-" . ($direction === 'up' ? 'up' : 'down') . "'></i>";

        if ($disabled) {
            return "<span class='btn btn-sm btn-ghost-secondary disabled'>$icon</span>";
        }

        // Naming the heading, not a direction, because at a boundary the press
        // refiles the step rather than moving it — see Step::reorder().
        $title = $lands_in !== ''
            ? sprintf(__('File it under “%s”', 'glpisop'), $lands_in)
            : ($direction === 'up' ? __('Move up', 'glpisop') : __('Move down', 'glpisop'));

        $url = Url::to('front/step.form.php');

        return "<form method='post' action='" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . "' class='d-inline'>"
            . Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()])
            . Html::hidden('id', ['value' => $steps_id])
            . Html::hidden('move', ['value' => $direction])
            . "<button type='submit' class='btn btn-sm btn-ghost-secondary' title='"
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "'>$icon</button>"
            . '</form>';
    }

    /** @param array<int,array<string,mixed>> $sections */
    private static function renderSections(int $sops_id, array $sections, bool $canedit): void
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        echo '<h4>' . __s('Sections', 'glpisop') . '</h4>';
        echo '<p class="text-muted">'
           . __s('Headings that group the steps. Optional — steps that are not filed under one '
               . 'appear at the end of the procedure.', 'glpisop')
           . '</p>';

        if ($sections !== []) {
            // Sections carry order too, and it outranks the steps' own — a
            // procedure whose headings are in the wrong order reads wrong
            // however carefully its steps are arranged. Without these arrows
            // the only fix was to delete a heading and refile every step under
            // it by hand.
            $ordered = array_values($sections);
            $last    = count($ordered) - 1;

            echo "<table class='table table-sm'><tbody>";
            foreach ($ordered as $index => $section) {
                $sections_id = (int) $section['id'];

                echo '<tr>';
                echo '<td>' . $e($section['name']);
                if (trim((string) $section['content']) !== '') {
                    echo "<div class='sop-builder-depends'>" . $e($section['content']) . '</div>';
                }
                echo '</td>';
                if ($canedit) {
                    echo "<td class='text-end' style='white-space:nowrap'>";
                    echo self::sectionMoveButton($sections_id, 'up', $index === 0);
                    echo self::sectionMoveButton($sections_id, 'down', $index === $last);
                    echo "<form method='post' action='" . $e(Url::to('front/section.form.php')) . "' "
                       . "class='d-inline'>";
                    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                    echo Html::hidden('id', ['value' => $sections_id]);
                    echo "<button type='submit' name='purge' value='1' class='btn btn-sm btn-ghost-danger' "
                       . "title='" . __s('Delete this heading — its steps are kept', 'glpisop') . "'>"
                       . "<i class='ti ti-trash'></i></button>";
                    echo '</form>';
                    echo '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        if (!$canedit) {
            return;
        }

        echo "<form method='post' action='" . $e(Url::to('front/section.form.php')) . "' "
           . "class='row g-2 align-items-end mb-3'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('plugin_glpisop_sops_id', ['value' => $sops_id]);
        echo "<div class='col-md-4'><label class='form-label'>" . __s('New section', 'glpisop') . '</label>';
        echo "<input type='text' class='form-control' name='name' required></div>";
        echo "<div class='col-md-6'><label class='form-label'>" . __s('Description') . '</label>';
        echo "<input type='text' class='form-control' name='content'></div>";
        echo "<div class='col-md-2'><button type='submit' name='add' value='1' class='btn btn-secondary w-100'>"
           . __s('Add') . '</button></div>';
        echo '</form>';
    }

    /**
     * @param array<int,array<string,mixed>> $steps
     * @param array<int,array<string,mixed>> $sections
     */
    private static function renderAddStep(int $sops_id, array $steps, array $sections): void
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $section_options = [0 => __('— unfiled —', 'glpisop')];
        foreach ($sections as $section) {
            $section_options[(int) $section['id']] = (string) $section['name'];
        }

        echo "<form method='post' action='" . $e(Url::to('front/step.form.php')) . "' "
           . "class='row g-2 align-items-end mt-3 pt-3 border-top'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('plugin_glpisop_sops_id', ['value' => $sops_id]);

        echo "<div class='col-md-5'><label class='form-label'>" . __s('New step', 'glpisop') . '</label>';
        echo "<input type='text' class='form-control' name='label' required "
           . "placeholder='" . __s('What the technician is asked to do', 'glpisop') . "'></div>";

        echo "<div class='col-md-3'><label class='form-label'>" . __s('Type', 'glpisop') . '</label>';
        \Dropdown::showFromArray('step_type', StepType::all(), ['value' => StepType::CHECK]);
        echo '</div>';

        echo "<div class='col-md-2'><label class='form-label'>" . __s('Section', 'glpisop') . '</label>';
        \Dropdown::showFromArray('plugin_glpisop_sections_id', $section_options, ['value' => 0]);
        echo '</div>';

        echo "<div class='col-md-2'><button type='submit' name='add' value='1' class='btn btn-primary w-100'>"
           . __s('Add') . '</button></div>';

        echo "<div class='col-12'><div class='form-text'>"
           . __s('The step is created with its defaults; open it to set options, mark it required, '
               . 'or gate it on an earlier answer.', 'glpisop')
           . '</div></div>';

        echo '</form>';
    }
}
