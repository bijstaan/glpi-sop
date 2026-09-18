<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use Html;
use Session;

/**
 * The procedure editor: one canvas, one Save.
 *
 * This replaces a table of steps whose every edit was a page. Adding a step was
 * a form post and a reload; opening it was a second page; setting its type
 * options was a third post back to the table; gating it was a fourth page and
 * one post per clause; moving it was a post per press. A twenty-step procedure
 * with a couple of branches cost something like a hundred page loads, and the
 * author never saw the procedure and the thing they were editing at the same
 * time.
 *
 * So the shape is GLPI 11's own form editor, because an author who has built a
 * service-catalogue form already knows it: a column of cards, the active one
 * open and the rest collapsed to a line, a toolbar that adds a card where you
 * are looking, and a single Save at the bottom that writes the lot. Nothing
 * here navigates. Adding, deleting, duplicating, reordering, retyping and
 * gating are all edits to the page in front of the author, and the server sees
 * them once.
 *
 * ## What is rendered here and what is not
 *
 * Everything that exists when the page is drawn is drawn by PHP — including
 * each step's type options and each gate clause's value control, because those
 * can be a GLPI entity picker whose label only the server knows. What the
 * browser builds on its own is everything that is a choice among things already
 * on the page: the list of steps a clause may point at, the operators that go
 * with a subject, the answers a choice step offers. Those are read from the DOM
 * as it stands, so renaming a step renames it in the clause that points at it,
 * without a round trip and without the two drifting.
 *
 * Two fragments are still fetched, and only on a deliberate change: the options
 * panel when an author switches a step's type, and the value control when a
 * clause is pointed at a category, a user or a group. Both are rendered by the
 * same code that painted them the first time — {@see StepOptions::render()} and
 * {@see Trigger::renderValueControl()} — so there is no second implementation
 * to drift.
 *
 * ## The contract with public/js/sop-builder.js
 *
 * - a block is `[data-sop-section]` or `[data-sop-step]`, identified by
 *   `data-sop-key`: `s<id>`/`t<id>` for something already in the database,
 *   `n<counter>` for something added in the browser;
 * - a scalar field is `[data-sop-field=<name>]`, read from the element itself;
 * - a type option is a wrapper carrying `data-sop-cfg` and `data-sop-cfg-name`
 *   — see {@see StepOptions};
 * - order is DOM order, and the section a step belongs to is the section it
 *   sits inside. Nothing carries a rank; rank is position.
 *
 * The serialiser therefore knows nothing about steps, types or clauses. Adding
 * a field is adding an attribute.
 */
final class Builder
{
    /** The key of the implicit heading that holds steps nobody filed. */
    public const UNFILED = 's0';

    public static function render(Sop $sop): void
    {
        $sops_id  = (int) $sop->getID();
        $canedit  = Session::haveRight(Sop::$rightname, UPDATE) && $sop->can($sops_id, UPDATE);
        $steps    = Step::allFor($sops_id, false);
        $sections = Section::allFor($sops_id);
        $numbers  = Renderer::numbering($steps);

        // Steps grouped by the heading they are filed under, in display order.
        //
        // A step whose heading is not one of this SOP's is filed under the
        // implicit one rather than dropped, and that is not defensiveness for
        // its own sake: this canvas saves what it shows and deletes what it
        // does not, so a step that failed to land in a bucket would vanish from
        // the screen and then be deleted — with the answers given to it in
        // every run — the next time anybody pressed Save. {@see Step::allFor()}
        // sorts such a step last for the same reason, which is the precedent
        // that says the case is real.
        $by_section = [0 => []];
        foreach (array_keys($sections) as $sections_id) {
            $by_section[(int) $sections_id] = [];
        }
        foreach ($steps as $step) {
            $filed = (int) $step['plugin_glpisop_sections_id'];
            $by_section[isset($by_section[$filed]) ? $filed : 0][] = $step;
        }

        // glpisop-surface: scope class for sop.css's dark-theme muted-text fixes.
        echo "<div class='glpisop-surface sop-builder" . ($canedit ? '' : ' sop-builder--readonly') . "'"
           . " data-sop-builder"
           . " data-sop-id='" . (int) $sops_id . "'"
           . " data-sop-editable='" . ($canedit ? '1' : '0') . "'"
           . " data-sop-endpoint='" . self::e(Url::to('ajax/builder.php')) . "'"
           . " data-sop-csrf='" . self::e(Session::getNewCSRFToken()) . "'>";

        self::renderBar($canedit);

        echo "<div class='sop-builder-blocks' data-sop-blocks>";
        foreach ($sections as $sections_id => $section) {
            self::renderSection(
                $sop,
                (int) $sections_id,
                $section,
                $by_section[(int) $sections_id],
                $numbers,
                $canedit
            );
        }
        // Unfiled last, because that is where Step::allFor() puts it — an
        // author who has started using headings has said where things belong,
        // and whatever is not filed yet is the tail of the procedure. Rendered
        // even when empty, and even when it is the only block: it is a real
        // place in the model, and a step with nowhere visible to land is a step
        // that disappears.
        self::renderSection(
            $sop,
            0,
            ['name' => '', 'content' => ''],
            $by_section[0],
            $numbers,
            $canedit,
            $sections === []
        );
        echo '</div>';

        if ($canedit) {
            self::renderTemplates($sop);
        }

        echo '</div>';

        self::boot($sop, $numbers);
    }

    // ------------------------------------------------------------- the frame

    private static function renderBar(bool $canedit): void
    {
        echo "<div class='sop-builder-bar'>";

        echo "<div class='sop-builder-bar-summary'>";
        echo "<span data-sop-summary></span>";
        echo "<span class='sop-builder-dirty' data-sop-dirty hidden>"
           . "<i class='ti ti-point-filled'></i>" . __s('Unsaved changes', 'glpisop') . '</span>';
        echo '</div>';

        if (!$canedit) {
            echo "<span class='text-muted'>" . __s('Read only', 'glpisop') . '</span>';
            echo '</div>';
            return;
        }

        echo "<div class='sop-builder-bar-actions'>";
        echo "<button type='button' class='btn btn-sm btn-ghost-secondary' data-sop-action='add-section'>"
           . "<i class='ti ti-box-align-top me-1'></i>" . __s('Add a section', 'glpisop') . '</button>';
        echo "<button type='button' class='btn btn-sm btn-secondary' data-sop-action='add-step'>"
           . "<i class='ti ti-circle-plus me-1'></i>" . __s('Add a step', 'glpisop') . '</button>';
        echo "<button type='button' class='btn btn-sm btn-primary' data-sop-action='save'>"
           . "<i class='ti ti-device-floppy me-1'></i>" . __s('Save') . '</button>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * One heading and the steps under it.
     *
     * `$headless` is the simple case and worth the parameter: an SOP with no
     * headings at all should read as a list of steps, not as a list of steps
     * inside an empty box labelled "Unfiled". The block is still there — it is
     * where new steps land — it just does not announce itself until there is
     * something to distinguish it from.
     *
     * The head is rendered either way and hidden by a class, so that the moment
     * an author adds their first heading the browser can reveal it. Leaving it
     * out of the markup would put the new heading above a block of steps with
     * no name at all, which reads as if those steps belonged to it.
     *
     * @param array<string,mixed>            $section
     * @param array<int,array<string,mixed>> $steps
     * @param array<int,string>              $numbers
     */
    private static function renderSection(
        Sop $sop,
        int $sections_id,
        array $section,
        array $steps,
        array $numbers,
        bool $canedit,
        bool $headless = false
    ): void {
        $unfiled = $sections_id === 0;
        $key     = $unfiled ? self::UNFILED : 's' . $sections_id;

        echo "<section class='sop-block sop-section" . ($unfiled ? ' sop-section--unfiled' : '')
           . ($headless ? ' sop-section--bare' : '')
           . "' data-sop-section data-sop-key='" . self::e($key) . "'"
           . ($unfiled ? ' data-sop-unfiled="1"' : '') . '>';

        self::renderSectionHead($section, $unfiled, $canedit);

        echo "<div class='sop-section-steps' data-sop-steps>";
        foreach ($steps as $step) {
            self::renderStep($sop, $step, $numbers, $canedit);
        }
        echo '</div>';

        if ($canedit) {
            echo "<button type='button' class='sop-add-inline' data-sop-action='add-step-here'>"
               . "<i class='ti ti-circle-plus me-1'></i>" . __s('Add a step', 'glpisop') . '</button>';
        }

        echo '</section>';
    }

    /** @param array<string,mixed> $section */
    private static function renderSectionHead(array $section, bool $unfiled, bool $canedit): void
    {
        echo "<div class='sop-section-head'>";

        if ($canedit && !$unfiled) {
            echo "<i class='ti ti-grip-vertical sop-handle' data-sop-handle draggable='true' title='"
               . __s('Drag to reorder', 'glpisop') . "'></i>";
        }

        if ($unfiled) {
            echo "<span class='sop-section-name sop-section-name--fixed'>"
               . __s('Unfiled steps', 'glpisop') . '</span>';
        } else {
            echo "<input type='text' class='sop-title-input sop-section-name' data-sop-field='name' "
               . "maxlength='255' placeholder='" . __s('Section name', 'glpisop') . "' value='"
               . self::e($section['name'] ?? '') . "'" . self::disabled($canedit) . '>';
        }

        echo "<span class='badge bg-secondary-lt sop-section-count' data-sop-count></span>";

        echo "<div class='sop-block-actions'>";
        self::actionButton('collapse-section', 'ti-chevron-up', __('Collapse', 'glpisop'));
        if ($canedit && !$unfiled) {
            self::actionButton('move-up', 'ti-arrow-up', __('Move up', 'glpisop'));
            self::actionButton('move-down', 'ti-arrow-down', __('Move down', 'glpisop'));
            self::actionButton(
                'delete-section',
                'ti-trash',
                __('Delete this heading — its steps move to Unfiled', 'glpisop'),
                'btn-ghost-danger'
            );
        }
        echo '</div>';

        echo '</div>';

        if (!$unfiled) {
            // No maxlength: the column is TEXT, and a cap here would quietly
            // shorten a description written before this editor existed.
            echo "<input type='text' class='form-control form-control-sm sop-section-desc' "
               . "data-sop-field='content' placeholder='"
               . __s('Add a description to this section', 'glpisop') . "' value='"
               . self::e($section['content'] ?? '') . "'" . self::disabled($canedit) . '>';
        }
    }

    // -------------------------------------------------------------- one step

    /**
     * One step card, closed.
     *
     * Closed is the default and the reason the canvas scales: the line an
     * author reads is the number, the label, and chips saying what kind of step
     * it is, whether it is required and whether it is gated. That is the whole
     * of what the old table showed, in the same space, with the editor one
     * click away instead of one page away.
     *
     * @param array<string,mixed> $step
     * @param array<int,string>   $numbers
     */
    private static function renderStep(Sop $sop, array $step, array $numbers, bool $canedit): void
    {
        $steps_id = (int) $step['id'];
        $type     = (string) $step['step_type'];
        $config   = Step::config($step);

        echo "<article class='card sop-step' data-sop-step data-sop-key='t$steps_id'>";
        echo "<div class='card-body'>";

        // --- the line ------------------------------------------------------
        echo "<div class='sop-step-head'>";

        if ($canedit) {
            echo "<i class='ti ti-grip-vertical sop-handle' data-sop-handle draggable='true' title='"
               . __s('Drag to reorder', 'glpisop') . "'></i>";
        }

        echo "<span class='sop-step-number' data-sop-number>"
           . self::e($numbers[$steps_id] ?? '') . '</span>';

        echo "<input type='text' class='sop-title-input' data-sop-field='label' maxlength='255' "
           . "placeholder='" . __s('What the technician is asked to do', 'glpisop') . "' value='"
           . self::e($step['label']) . "'" . self::disabled($canedit) . '>';

        echo "<span class='sop-step-chips' data-sop-chips></span>";

        echo "<div class='sop-block-actions'>";
        if ($canedit) {
            self::actionButton('duplicate-step', 'ti-copy', __('Duplicate', 'glpisop'));
            self::actionButton('move-up', 'ti-arrow-up', __('Move up', 'glpisop'));
            self::actionButton('move-down', 'ti-arrow-down', __('Move down', 'glpisop'));
            self::actionButton('delete-step', 'ti-trash', __('Delete'), 'btn-ghost-danger');
        }
        self::actionButton('toggle-step', 'ti-chevron-down', __('Open', 'glpisop'));
        echo '</div>';

        echo '</div>';

        // --- the editor ----------------------------------------------------
        echo "<div class='sop-step-body' data-sop-body hidden>";

        echo "<textarea class='form-control form-control-sm sop-step-help' rows='2' "
           . "data-sop-field='help' placeholder='"
           . __s('Guidance — which command, which register, who to call', 'glpisop') . "'"
           . self::disabled($canedit) . '>' . self::e($step['help']) . '</textarea>';

        echo "<div class='sop-step-controls'>";
        self::typeSelect($type, $canedit);
        self::toggle('required', __('Required', 'glpisop'), (int) $step['is_required'] === 1, $canedit);
        self::toggle('active', __('Active'), (int) $step['is_active'] === 1, $canedit);
        echo '</div>';

        echo "<div class='sop-step-options" . (StepOptions::has($type) ? '' : ' d-none')
           . "' data-sop-options>";
        StepOptions::render($type, $config, $canedit, $sop);
        echo '</div>';

        self::renderGate($sop, $step, $numbers, $canedit);

        echo '</div>';

        echo '</div></article>';
    }

    private static function typeSelect(string $type, bool $canedit): void
    {
        echo "<label class='sop-step-type'>";
        echo "<span class='form-label mb-0'>" . __s('Type', 'glpisop') . '</span>';
        echo "<select class='form-select form-select-sm' data-sop-field='type'"
           . self::disabled($canedit) . '>';
        foreach (StepType::all() as $value => $label) {
            echo "<option value='" . self::e($value) . "'"
               . ($value === $type ? " selected='selected'" : '') . '>'
               . self::e($label) . '</option>';
        }
        echo '</select></label>';
    }

    private static function toggle(string $field, string $label, bool $on, bool $canedit): void
    {
        echo "<label class='form-check form-switch mb-0'>";
        echo "<input type='checkbox' class='form-check-input' data-sop-field='" . self::e($field) . "'"
           . ($on ? " checked='checked'" : '') . self::disabled($canedit) . '>';
        echo "<span class='form-check-label'>" . self::e($label) . '</span></label>';
    }

    // -------------------------------------------------------------- the gate

    /**
     * The clauses that decide whether this step is asked.
     *
     * Rendered inside the card rather than on a page of its own, which is the
     * change that matters most for a branching procedure: the branch and the
     * step it hangs off are now visible together, and the steps a clause may
     * point at are the cards above this one — so the author is choosing from
     * what they can see.
     *
     * @param array<string,mixed> $step
     * @param array<int,string>   $numbers
     */
    private static function renderGate(Sop $sop, array $step, array $numbers, bool $canedit): void
    {
        $conditions = Step::conditions($step);

        echo "<div class='sop-gate' data-sop-gate>";

        echo "<div class='sop-gate-head'>";
        echo "<span class='sop-gate-title'>" . __s('Ask this step only when…', 'glpisop') . '</span>';
        echo "<select class='form-select form-select-sm sop-gate-mode' data-sop-field='mode'"
           . self::disabled($canedit) . '>';
        foreach (Condition::modes() as $value => $label) {
            echo "<option value='" . self::e($value) . "'"
               . ($value === Step::mode($step) ? " selected='selected'" : '') . '>'
               . self::e($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo "<div class='sop-gate-rows' data-sop-conditions>";
        foreach ($conditions as $condition) {
            self::renderCondition($sop, $condition, $numbers, $canedit);
        }
        echo '</div>';

        echo "<p class='sop-gate-empty text-muted' data-sop-gate-empty>"
           . __s('No conditions — this step is asked in every run.', 'glpisop') . '</p>';

        if ($canedit) {
            echo "<button type='button' class='sop-add-inline sop-add-inline--sm' "
               . "data-sop-action='add-condition'>"
               . "<i class='ti ti-plus me-1'></i>" . __s('Add a condition', 'glpisop') . '</button>';
        }

        echo '</div>';
    }

    /**
     * One clause, as three controls.
     *
     * The subject and operator selects are plain `<select>` elements rather
     * than GLPI's select2: the browser rewrites both whenever the procedure
     * changes shape above this step, and a widget that has to be torn down and
     * rebuilt to change its options is the wrong tool for a list of six things.
     * The value is the exception — a category or a user is a picker only the
     * server can fill — so that one is rendered here and re-rendered over ajax.
     *
     * @param array<string,mixed> $condition
     * @param array<int,string>   $numbers
     */
    private static function renderCondition(
        Sop $sop,
        array $condition,
        array $numbers,
        bool $canedit
    ): void {
        $source  = (string) $condition['source'];
        $parent  = (int) $condition['depends_steps_id'];
        $subject = $source === Condition::SRC_STEP
            ? Condition::SRC_STEP . ':t' . $parent
            : $source . ':' . (string) $condition['criterion'];
        $operator = (string) $condition['match_condition'];

        echo "<div class='sop-condition' data-sop-condition>";

        echo "<select class='form-select form-select-sm sop-cond-subject' data-sop-cond='subject'"
           . self::disabled($canedit) . ">";
        // One option, holding what is stored. The browser rebuilds the list on
        // first pass from the cards actually above this one; rendering the
        // whole list twice would only give the two a chance to disagree.
        echo "<option value='" . self::e($subject) . "' selected='selected'>"
           . self::e(self::subjectLabel($sop, $condition, $numbers)) . '</option>';
        echo '</select>';

        echo "<select class='form-select form-select-sm sop-cond-op' data-sop-cond='op'"
           . self::disabled($canedit) . ">";
        echo "<option value='" . self::e($operator) . "' selected='selected'>"
           . self::e(self::operatorLabel($source, $condition)) . '</option>';
        echo '</select>';

        // The value control is the one part of a row that GLPI renders, and
        // Dropdown::show() takes no readonly flag that survives every kind it
        // paints. Wrapping the whole row in a disabled fieldset is what makes a
        // read-only canvas read-only all the way through, rather than a page of
        // dead controls with one live picker in it.
        echo "<span class='sop-cond-value' data-sop-cond-value data-sop-cond-value-name='sopcondvalue'"
           . ($canedit ? '' : " inert") . '>';
        self::renderConditionValue($sop, $condition, $canedit);
        echo '</span>';

        if ($canedit) {
            echo "<div class='sop-block-actions'>";
            self::actionButton(
                'delete-condition',
                'ti-trash',
                __('Remove this condition', 'glpisop'),
                'btn-ghost-danger'
            );
            echo '</div>';
        }

        echo '</div>';
    }

    /** @param array<string,mixed> $condition */
    private static function renderConditionValue(Sop $sop, array $condition, bool $canedit): void
    {
        $source   = (string) $condition['source'];
        $operator = (string) $condition['match_condition'];
        $value    = (string) $condition['value'];

        if ($source === Condition::SRC_STEP) {
            if (!Step::operatorNeedsValue($operator)) {
                echo "<span class='form-control-plaintext text-muted'>"
                   . __s('Nothing to compare against.', 'glpisop') . '</span>';
                return;
            }

            // A fixed-answer parent offers its own answers, and the browser
            // rebuilds that list from the parent card as it is edited. The
            // stored value is what is rendered here so that a clause written
            // against an option since renamed is visible rather than silently
            // reset to the first one.
            echo "<select class='form-select form-select-sm' name='sopcondvalue'"
               . self::disabled($canedit) . '>';
            echo "<option value='" . self::e($value) . "' selected='selected'>"
               . self::e($value) . '</option>';
            echo '</select>';
            return;
        }

        $definition = Condition::definition($source, (string) $condition['criterion'])
            ?? ['name' => (string) $condition['criterion'], 'kind' => 'text'];

        Trigger::renderValueControl($definition, 'sopcondvalue', $value);
    }

    /**
     * What a stored clause's subject reads as.
     *
     * A clause pointing at a step that has since been deleted, or at a
     * criterion this build no longer offers, still has to render as something
     * an author can see and remove — so this falls back to describing the row
     * rather than to an empty box.
     *
     * @param array<string,mixed> $condition
     * @param array<int,string>   $numbers
     */
    private static function subjectLabel(Sop $sop, array $condition, array $numbers): string
    {
        if ((string) $condition['source'] === Condition::SRC_STEP) {
            $parent_id = (int) $condition['depends_steps_id'];
            $step      = new Step();

            // Checked against this SOP, not merely against the steps table: a
            // clause naming a step of some other procedure is broken, and
            // reading that step's label would describe it as though it worked.
            if (
                $step->getFromDB($parent_id)
                && (int) $step->fields['plugin_glpisop_sops_id'] === (int) $sop->getID()
            ) {
                return sprintf(
                    '%s. %s',
                    $numbers[$parent_id] ?? '?',
                    (string) $step->fields['label']
                );
            }

            return __('(deleted step)', 'glpisop');
        }

        $definition = Condition::definition(
            (string) $condition['source'],
            (string) $condition['criterion']
        );

        return (string) ($definition['name'] ?? $condition['criterion']);
    }

    /** @param array<string,mixed> $condition */
    private static function operatorLabel(string $source, array $condition): string
    {
        $operator  = (string) $condition['match_condition'];
        $operators = $source === Condition::SRC_STEP
            ? Step::operators()
            : Condition::operatorsFor($source, (string) $condition['criterion']);

        return (string) ($operators[$operator] ?? $operator);
    }

    // ------------------------------------------------------------- templates

    /**
     * A blank step, a blank section and a blank clause, hidden.
     *
     * The same trick GLPI's form editor uses, and for the same reason: adding
     * a card is cloning one, so a new step is identical to a rendered one
     * without the browser holding a second description of what a step looks
     * like. The blanks are inert — they sit outside the blocks container, which
     * is what every query in the editor is scoped to — and the key a clone
     * carries is minted by the browser as it is inserted, never the placeholder
     * one here.
     */
    private static function renderTemplates(Sop $sop): void
    {
        echo "<div data-sop-templates hidden>";

        echo "<div data-sop-template='step'>";
        self::renderStep($sop, [
            'id'                         => 0,
            'label'                      => '',
            'help'                       => '',
            'step_type'                  => StepType::CHECK,
            'config_json'                => '{}',
            'is_required'                => 0,
            'is_active'                  => 1,
            'depends_mode'               => Condition::MODE_ALL,
            'plugin_glpisop_sections_id' => 0,
            'conditions'                 => [],
        ], [], true);
        echo '</div>';

        echo "<div data-sop-template='section'>";
        echo "<section class='sop-block sop-section' data-sop-section data-sop-key='__new__'>";
        self::renderSectionHead(['name' => '', 'content' => ''], false, true);
        echo "<div class='sop-section-steps' data-sop-steps></div>";
        echo "<button type='button' class='sop-add-inline' data-sop-action='add-step-here'>"
           . "<i class='ti ti-circle-plus me-1'></i>" . __s('Add a step', 'glpisop') . '</button>';
        echo '</section>';
        echo '</div>';

        echo "<div data-sop-template='condition'>";
        echo "<div class='sop-condition' data-sop-condition>";
        echo "<select class='form-select form-select-sm sop-cond-subject' data-sop-cond='subject'></select>";
        echo "<select class='form-select form-select-sm sop-cond-op' data-sop-cond='op'></select>";
        echo "<span class='sop-cond-value' data-sop-cond-value data-sop-cond-value-name='sopcondvalue'></span>";
        echo "<div class='sop-block-actions'>";
        self::actionButton(
            'delete-condition',
            'ti-trash',
            __('Remove this condition', 'glpisop'),
            'btn-ghost-danger'
        );
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    // ------------------------------------------------------------ the wiring

    /**
     * The vocabulary the browser needs, and the call that starts the editor.
     *
     * Everything here is a list the server already owns and the browser cannot
     * derive: the type names, the operators that go with each criterion, the
     * fixed option sets, and which criteria need a picker fetched rather than
     * built. It is emitted once per render rather than fetched, because all of
     * it is small and none of it changes while the page is open.
     *
     * @param array<int,string> $numbers
     */
    private static function boot(Sop $sop, array $numbers): void
    {
        $itemtypes = $sop->itemtypes();

        $subjects  = [];
        $operators = [];
        $values    = [];

        foreach ([Condition::SRC_FIELD, Condition::SRC_APPROVAL] as $source) {
            $group = $source === Condition::SRC_FIELD
                ? __('A field of the item', 'glpisop')
                : __('An approval on the item', 'glpisop');

            foreach (Condition::criteriaFor($source, $itemtypes) as $criterion => $name) {
                $key          = $source . ':' . $criterion;
                $subjects[]   = ['group' => $group, 'key' => $key, 'label' => $name];
                $operators[$key] = Condition::operatorsFor($source, $criterion);
                $values[$key]    = self::valueShape(
                    Condition::definition($source, $criterion) ?? ['kind' => 'text']
                );
            }
        }

        $type_options = [];
        foreach (array_keys(StepType::all()) as $type) {
            $type_options[$type] = StepOptions::has($type);
        }

        $catalogue = [
            'types'        => StepType::all(),
            'typeOptions'  => $type_options,
            'branchTypes'  => [StepType::YESNO, StepType::CHOICE, StepType::MULTICHOICE],
            'yesno'        => StepType::yesNoLabels(),
            'stepOps'      => Step::operators(),
            'stepOpsValue' => array_values(array_filter(
                array_keys(Step::operators()),
                static fn(string $op): bool => Step::operatorNeedsValue($op)
            )),
            'stepGroup'    => __('An answer earlier in this procedure', 'glpisop'),
            'subjects'     => $subjects,
            'operators'    => $operators,
            'values'       => $values,
            'defaultType'  => StepType::CHECK,
            'i18n'         => [
                'steps'          => __('%d steps', 'glpisop'),
                'oneStep'        => __('1 step', 'glpisop'),
                'noSteps'        => __('no steps', 'glpisop'),
                'required'       => __('required', 'glpisop'),
                'summary'        => __('%1$s · %2$d required', 'glpisop'),
                'gated'          => __('gated', 'glpisop'),
                'inactive'       => __('retired', 'glpisop'),
                'open'           => __('Open', 'glpisop'),
                'close'          => __('Close', 'glpisop'),
                'newStep'        => __('New step', 'glpisop'),
                'newSection'     => __('New section', 'glpisop'),
                'copySuffix'     => __('%s (copy)', 'glpisop'),
                'deletedStep'    => __('(deleted step)', 'glpisop'),
                'frozenClause'   => __('Kept as it was written. This build cannot offer this '
                    . 'subject for a new condition — because the SOP’s itemtypes changed, or '
                    . 'because it points further down the procedure — so it is saved back '
                    . 'unchanged. Remove it if it no longer applies.', 'glpisop'),
                'brokenClause'   => __('“%s” has a condition naming a step that is not in this '
                    . 'procedure. Remove it before saving.', 'glpisop'),
                'nothingToCompare' => __('Nothing to compare against.', 'glpisop'),
                'confirmStep'    => __('Delete this step? Its answers in existing runs go with it.', 'glpisop'),
                'confirmSection' => __('Delete this heading? Its steps move to Unfiled.', 'glpisop'),
                'leaveWarning'   => __('This procedure has unsaved changes.', 'glpisop'),
                'saved'          => __('Procedure saved.', 'glpisop'),
                'saveFailed'     => __('The procedure could not be saved.', 'glpisop'),
                'needsLabel'     => __('Every step needs a label.', 'glpisop'),
            ],
        ];

        // Queued rather than called, because the two ways GLPI can put this tab
        // on the page arrive in opposite orders: fetched over ajax the editor's
        // script is already in the page footer, rendered inline with the form it
        // is not. The queue is drained by whichever of the two happens second.
        echo Html::scriptBlock(
            '(window.glpisopBuilderQueue = window.glpisopBuilderQueue || []).push('
            . json_encode($catalogue, JSON_UNESCAPED_UNICODE) . ');'
            . 'if (window.glpisopBuilderStart) { window.glpisopBuilderStart(); }'
        );
    }

    /**
     * How the browser should build a clause's value box for one criterion.
     *
     * `options` where the answer is a fixed list the server can hand over in
     * full, `text` where it is a free box, and `remote` where it is a GLPI
     * entity picker — which is the only case that costs a request, and only
     * when the author points a clause at one.
     *
     * The option maps are cast to objects before they are encoded. PHP folds a
     * numeric string key back to an integer, so a list keyed 0..n — which
     * priorities are one renumbering away from being — would arrive in the
     * browser as a JSON array, and the editor would offer its indices as the
     * values to compare against.
     *
     * @param array{kind:string,table?:string} $definition
     * @return array<string,mixed>
     */
    private static function valueShape(array $definition): array
    {
        switch ($definition['kind']) {
            case 'priority':
                $options = [];
                for ($level = 1; $level <= 5; $level++) {
                    $options[(string) $level] = \CommonITILObject::getPriorityName($level);
                }
                return ['kind' => 'options', 'options' => (object) $options, 'default' => '3'];

            case 'tickettype':
                return [
                    'kind'    => 'options',
                    'options' => (object) [
                        (string) \Ticket::INCIDENT_TYPE => \Ticket::getTicketTypeName(\Ticket::INCIDENT_TYPE),
                        (string) \Ticket::DEMAND_TYPE   => \Ticket::getTicketTypeName(\Ticket::DEMAND_TYPE),
                    ],
                    'default' => (string) \Ticket::INCIDENT_TYPE,
                ];

            case 'validation_status':
                $options = [];
                foreach (Condition::validationStatuses() as $status => $label) {
                    $options[(string) $status] = $label;
                }
                return [
                    'kind'    => 'options',
                    'options' => (object) $options,
                    'default' => (string) \CommonITILValidation::ACCEPTED,
                ];

            case 'dropdown':
            case 'actor_group':
                return ['kind' => 'remote'];
        }

        return ['kind' => 'text'];
    }

    // -------------------------------------------------------------- plumbing

    private static function actionButton(
        string $action,
        string $icon,
        string $title,
        string $class = 'btn-ghost-secondary'
    ): void {
        echo "<button type='button' class='btn btn-sm $class sop-action' data-sop-action='"
           . self::e($action) . "' title='" . self::e($title) . "' aria-label='" . self::e($title)
           . "'><i class='ti " . self::e($icon) . "'></i></button>";
    }

    private static function disabled(bool $canedit): string
    {
        return $canedit ? '' : ' disabled';
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
