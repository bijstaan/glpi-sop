<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use Html;
use Session;
use User;

/**
 * Renders a run as an interactive checklist.
 *
 * Every step is rendered, including the ones that are not currently being
 * asked; hidden steps carry `hidden` rather than being left out. That is what
 * lets an answer open a branch by unsetting one attribute, instead of fetching
 * markup for a step that did not exist a moment ago — and it means the
 * technician's own partially-typed answers elsewhere on the page survive a
 * branch opening.
 *
 * Nothing here is a form. Each control posts itself to ajax/run.php as it
 * changes, because the way a long procedure actually gets interrupted is a
 * technician being called away halfway through, and a checklist that loses
 * eight answers to a closed tab will not be used twice.
 */
final class Renderer
{
    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * One run, as a self-contained block of HTML.
     *
     * Returned rather than echoed because its destination is a timeline entry:
     * GLPI builds the whole timeline as data and renders it in Twig, so a
     * renderer that writes to the output buffer would land wherever the buffer
     * happened to be rather than inside the card.
     *
     * Each block carries its own `.sop-container` with the endpoint and token
     * on it. That looks redundant when several runs sit on one page, and is
     * exactly what makes them independent: public/js/sop.js resolves both by
     * walking up from the control that changed, so a run can be rendered
     * anywhere, in any number, without knowing about the others.
     */
    public static function runBlock(array $run): string
    {
        ob_start();

        echo "<div class='sop-container' data-sop-endpoint='"
           . self::e(Url::to('ajax/run.php')) . "' data-sop-csrf='"
           . self::e(Session::getNewCSRFToken()) . "' data-sop-skip-reason='"
           . (Settings::flag('require_skip_reason') ? '1' : '0') . "'>";

        self::renderRun($run);

        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * A compact status block for the item's fields panel.
     *
     * The checklist lives in the timeline, which is the right place to *work*
     * it and the wrong place to be reminded of it: a ticket with thirty
     * followups scrolls the procedure out of sight, and the first a technician
     * then hears about an outstanding required step is a refusal when they try
     * to close. This block sits in the fields panel, which does not scroll with
     * the timeline, and says what is left in one line.
     *
     * The link is a plain fragment. GLPI gives every timeline entry an id of
     * `<type>_<id>`, and ours is no exception, so jumping to the checklist
     * needs no JavaScript at all.
     */
    public static function renderStatusPanel(string $itemtype, int $items_id): void
    {
        $runs = Run::forItem($itemtype, $items_id, false);
        if ($runs === []) {
            return;
        }

        echo "<div class='accordion-item sop-panel' data-sop-panel>";

        foreach ($runs as $run) {
            $runs_id     = (int) $run['id'];
            $status      = (string) $run['status'];
            $total       = (int) $run['total_visible'];
            $done        = (int) $run['done_visible'];
            $outstanding = (int) $run['total_required'] - (int) $run['done_required'];
            $enforcing   = (int) $run['sop']['enforce_on_solve'] === 1 && Settings::flag('enforce_enabled');
            $percent     = $total > 0 ? (int) round(($done / $total) * 100) : 0;

            $tone = $status === Run::COMPLETED
                ? 'sop-panel-row--done'
                : ($outstanding > 0 && $enforcing ? 'sop-panel-row--blocking' : '');

            echo "<a class='sop-panel-row " . self::e($tone) . "' "
               . "href='#PluginGlpisopRun_$runs_id' data-sop-panel-run='$runs_id'>";

            echo "<span class='sop-panel-title'>";
            echo "<i class='ti ti-list-check me-1'></i>";
            echo self::e($run['sop']['name']);
            echo '</span>';

            echo "<span class='sop-panel-count' data-sop-panel-count>"
               . self::e(sprintf('%d/%d', $done, $total)) . '</span>';

            echo "<span class='progress sop-panel-bar'>";
            echo "<span class='progress-bar' style='width:{$percent}%'></span>";
            echo '</span>';

            echo "<span class='sop-panel-note' data-sop-panel-note>"
               . self::e(self::panelNote($status, $outstanding, $enforcing)) . '</span>';

            echo '</a>';
        }

        echo '</div>';
    }

    /**
     * The one line under the bar.
     *
     * Says the consequence, not the state — "cannot be resolved yet" is what a
     * technician needs to know before they try, rather than after the save is
     * refused.
     */
    public static function panelNote(string $status, int $outstanding, bool $enforcing): string
    {
        if ($status === Run::COMPLETED) {
            return __('procedure complete', 'glpisop');
        }

        if ($outstanding <= 0) {
            return __('all required steps done', 'glpisop');
        }

        $text = sprintf(
            _n('%d required step outstanding', '%d required steps outstanding', $outstanding, 'glpisop'),
            $outstanding
        );

        return $enforcing
            ? $text . ' — ' . __('cannot be resolved yet', 'glpisop')
            : $text;
    }

    private static function renderRun(array $run): void
    {
        $runs_id   = (int) $run['id'];
        $sop       = $run['sop'];
        $steps     = Step::allFor((int) $run['plugin_glpisop_sops_id']);
        $steps_ids = array_map(static fn($s): int => (int) $s['id'], $steps);
        $answers   = Answer::forRun($runs_id, $steps_ids);
        $item      = Run::item($run);

        // A step answered by a linked ticket or by an approval can have changed
        // without anybody touching this run, and the hooks that normally notice
        // cannot see everything. Rendering is the last chance to be honest
        // about it — see Run::syncExternal(), which writes only on a change.
        if (Run::syncExternal($runs_id, $steps, $answers, $item)) {
            $answers = Answer::forRun($runs_id, $steps_ids);
            Run::recount($runs_id);

            // The counters the header and the fields-panel reminder read live
            // on the row, and it has just been rewritten underneath us.
            $fresh = new Run();
            if ($fresh->getFromDB($runs_id)) {
                $run = $fresh->fields + ['sop' => $sop];
            }
        }

        $visible  = Visibility::evaluate($steps, $answers, $item);
        $progress = Visibility::progress($steps, $answers, $visible);
        $editable = Run::isEditable($run);
        $numbers  = self::numbering($steps);
        $sections = Section::allFor((int) $run['plugin_glpisop_sops_id']);

        $status = (string) $run['status'];

        // No Bootstrap card wrapper. This block's home is a timeline entry,
        // which is already a card; nesting one inside another gives a doubled
        // border and a header bar that reads as a second, unrelated panel.
        echo "<div class='sop-run sop-run--$status' data-sop-run='$runs_id' "
           . "data-sop-editable='" . ($editable ? '1' : '0') . "'>";

        // ---------------------------------------------------------- header
        echo "<div class='sop-run-head d-flex align-items-center flex-wrap gap-2'>";
        echo "<h3 class='sop-run-title mb-0'>" . self::e($sop['name']) . '</h3>';
        echo "<span class='badge bg-secondary-lt' title='"
           . __s('The revision of the procedure this run started against', 'glpisop')
           . "'>r" . (int) $run['sop_version'] . '</span>';

        echo "<span class='sop-status-badge'>" . self::statusBadge($status) . '</span>';

        echo "<span class='ms-auto text-muted small'>";
        echo self::e(Run::originLabels()[(string) $run['origin']] ?? (string) $run['origin']);
        echo '</span>';
        echo '</div>';

        echo "<div class='sop-run-body'>";

        if (trim((string) ($sop['content'] ?? '')) !== '') {
            echo "<div class='sop-description mb-3'>"
               . nl2br(self::e($sop['content'])) . '</div>';
        }

        self::renderProgress($progress, (int) $sop['enforce_on_solve'] === 1);

        if (!$editable) {
            echo "<div class='alert alert-secondary py-2'>"
               . self::e(self::readOnlyReason($run)) . '</div>';
        }

        // ----------------------------------------------------------- steps
        echo "<div class='sop-steps'>";

        $current_section = null;
        foreach ($steps as $step) {
            $sections_id = (int) $step['plugin_glpisop_sections_id'];
            if ($sections_id !== $current_section) {
                $current_section = $sections_id;
                $section         = $sections[$sections_id] ?? null;
                if ($section !== null) {
                    echo "<div class='sop-section-head'>" . self::e($section['name']);
                    if (trim((string) ($section['content'] ?? '')) !== '') {
                        echo "<div class='sop-section-help'>" . self::e($section['content']) . '</div>';
                    }
                    echo '</div>';
                }
            }

            self::renderStep(
                $step,
                $answers[(int) $step['id']],
                $visible[(int) $step['id']] ?? true,
                $numbers[(int) $step['id']] ?? '',
                $editable
            );
        }

        echo '</div>';

        self::renderFooter($run, $editable);

        echo '</div></div>';
    }

    private static function renderProgress(array $progress, bool $enforcing): void
    {
        echo "<div class='sop-progress mb-3'>";
        echo "<div class='progress' style='height:6px'>";
        echo "<div class='progress-bar' role='progressbar' style='width:"
           . self::percent($progress) . "%'></div>";
        echo '</div>';
        echo "<div class='d-flex justify-content-between small text-muted mt-1'>";
        echo "<span class='sop-progress-text'>" . self::e(self::progressText($progress)) . '</span>';
        echo "<span class='sop-required-text'>" . self::e(self::requiredText($progress, $enforcing)) . '</span>';
        echo '</div></div>';
    }

    public static function percent(array $progress): int
    {
        $total = (int) $progress['total'];

        return $total > 0 ? (int) round(((int) $progress['done'] / $total) * 100) : 0;
    }

    /**
     * The two progress lines, composed here rather than in JavaScript.
     *
     * The endpoint returns these strings alongside the numbers so that the
     * live update and the first render are the same sentence — plural forms
     * and translations included. Formatting them in the browser would mean a
     * second set of message strings that only exists in English.
     */
    public static function progressText(array $progress): string
    {
        return sprintf(
            __('%1$d of %2$d steps', 'glpisop'),
            (int) $progress['done'],
            (int) $progress['total']
        );
    }

    public static function requiredText(array $progress, bool $enforcing): string
    {
        $outstanding = (int) $progress['total_required'] - (int) $progress['done_required'];

        if ($outstanding > 0) {
            $text = sprintf(
                _n('%d required step outstanding', '%d required steps outstanding', $outstanding, 'glpisop'),
                $outstanding
            );

            if ($enforcing && Settings::flag('enforce_enabled')) {
                $text .= ' — ' . __('this item cannot be resolved yet', 'glpisop');
            }

            return $text;
        }

        return (int) $progress['total_required'] > 0
            ? __('all required steps done', 'glpisop')
            : '';
    }

    private static function renderStep(
        array $step,
        array $answer,
        bool $visible,
        string $number,
        bool $editable
    ): void {
        $steps_id  = (int) $step['id'];
        $type      = (string) $step['step_type'];
        $required  = (int) $step['is_required'] === 1;
        $state     = (string) $answer['state'];
        $gated     = Step::conditions($step) !== [];

        $classes = 'sop-step';
        if ($gated) {
            $classes .= ' sop-step--nested';
        }
        if ($state === Answer::DONE) {
            $classes .= ' sop-step--done';
        } elseif ($state === Answer::SKIPPED) {
            $classes .= ' sop-step--skipped';
        }

        // No copy of the gate itself on the element. Visibility is decided
        // server-side and returned with every write — see Visibility — so
        // publishing the clauses here would put a second, unread description of
        // the branch rules in the page for somebody to later mistake for the
        // one that matters.
        echo "<div class='" . self::e($classes) . "'"
           . " data-sop-step-id='$steps_id'"
           . " data-sop-type='" . self::e($type) . "'"
           . " data-sop-required='" . ($required ? '1' : '0') . "'"
           . " data-sop-gated='" . ($gated ? '1' : '0') . "'"
           . ($visible ? '' : ' hidden')
           . '>';

        echo "<div class='sop-step-number'>" . self::e($number) . '</div>';

        echo "<div class='sop-step-body'>";

        // No step-type badge here. The control underneath already says what
        // the step wants — a checkbox looks like a checkbox — and "Checkbox —
        // done or not done" beside every line turns a procedure a technician
        // is meant to read into a form they have to parse. The type is
        // authoring metadata, and it has a column of its own in the builder.
        echo "<div class='sop-step-label'>";
        echo self::e($step['label']);
        if ($required) {
            echo "<span class='sop-required' title='" . __s('Required', 'glpisop') . "'>*</span>";
        }
        echo '</div>';

        if (trim((string) ($step['help'] ?? '')) !== '') {
            echo "<div class='sop-step-help'>" . nl2br(self::e($step['help'])) . '</div>';
        }

        echo "<div class='sop-step-input'>";
        StepType::renderInput($step, $answer, $editable && $state !== Answer::SKIPPED);
        echo '</div>';

        // Every string the browser might need to display is carried on the
        // element that needs it. The alternative — a message catalogue in the
        // JavaScript — is a second place translations have to be maintained,
        // and in practice the one that never gets translated.
        echo "<div class='sop-step-error alert alert-danger py-1 px-2 mt-2' data-sop-fallback='"
           . __s('Could not save that. Reload the item and try again.', 'glpisop') . "' hidden></div>";

        // A note is offered on every step, not only skipped ones. The reason a
        // step took two hours is worth more on the record than the tick is.
        $note = (string) ($answer['note'] ?? '');
        echo "<div class='sop-step-note mt-2'" . ($note === '' ? ' hidden' : '') . '>';
        echo "<textarea class='form-control form-control-sm' rows='2' data-sop-note='$steps_id' "
           . "placeholder='" . __s('Note', 'glpisop') . "'"
           . ($editable ? '' : ' disabled') . '>' . self::e($note) . '</textarea>';
        echo '</div>';

        echo self::stepMeta($answer);

        echo '</div>';

        // -------------------------------------------------------- actions
        echo "<div class='sop-step-actions'>";
        if ($editable) {
            echo "<button type='button' class='btn btn-sm btn-ghost-secondary' data-sop-action='note' "
               . "data-sop-target='$steps_id' title='" . __s('Add a note', 'glpisop') . "'>"
               . "<i class='ti ti-message-2'></i></button>";

            if (Settings::flag('allow_skip')) {
                $label = $state === Answer::SKIPPED
                    ? __s('Un-skip this step', 'glpisop')
                    : __s('Skip this step', 'glpisop');
                echo "<button type='button' class='btn btn-sm btn-ghost-secondary' data-sop-action='skip' "
                   . "data-sop-target='$steps_id' title='" . $label . "' "
                   . "data-sop-reason-prompt='" . __s('Why is this step being skipped?', 'glpisop') . "' "
                   . "data-sop-reason-message='"
                   . __s('Give a reason, then press skip again.', 'glpisop') . "'>"
                   . "<i class='ti ti-player-skip-forward'></i></button>";
            }

            if (Answer::isAnswered($answer)) {
                echo "<button type='button' class='btn btn-sm btn-ghost-secondary' data-sop-action='clear' "
                   . "data-sop-target='$steps_id' title='" . __s('Clear this answer', 'glpisop') . "'>"
                   . "<i class='ti ti-eraser'></i></button>";
            }
        }
        echo '</div>';

        echo '</div>';
    }

    /** Who answered, and when — the part that makes a checklist evidence. */
    private static function stepMeta(array $answer): string
    {
        return "<div class='sop-step-meta'>" . self::e(self::stepMetaText($answer)) . '</div>';
    }

    /**
     * The same line as plain text.
     *
     * The endpoint returns this so the browser can update the byline without
     * re-rendering the step, and it composes it here rather than in JavaScript
     * so that a name resolved from the database is not something the client is
     * trusted to look up.
     */
    public static function stepMetaText(array $answer): string
    {
        if (!Answer::isAnswered($answer)) {
            return '';
        }

        $user = new User();
        $who  = (int) $answer['users_id'] > 0 && $user->getFromDB((int) $answer['users_id'])
            ? $user->getFriendlyName()
            : __('the system', 'glpisop');

        $when = (string) ($answer['date_mod'] ?? '');

        return sprintf(
            (string) $answer['state'] === Answer::SKIPPED
                ? __('skipped by %1$s, %2$s', 'glpisop')
                : __('%1$s, %2$s', 'glpisop'),
            $who,
            $when !== '' ? Html::convDateTime($when) : ''
        );
    }

    /** A step's label for the history panel, where only its id is recorded. */
    public static function stepLabel(int $steps_id): string
    {
        if ($steps_id <= 0) {
            return '';
        }

        $step = new Step();

        return $step->getFromDB($steps_id) ? (string) $step->fields['label'] : ('#' . $steps_id);
    }

    private static function renderFooter(array $run, bool $editable): void
    {
        $runs_id = (int) $run['id'];

        echo "<div class='sop-run-footer d-flex align-items-center gap-2 mt-3'>";

        echo "<button type='button' class='btn btn-sm btn-ghost-secondary' data-sop-action='log' "
           . "data-sop-target='$runs_id' data-sop-loading='" . __s('Loading…', 'glpisop') . "'>"
           . "<i class='ti ti-history me-1'></i>" . __s('History', 'glpisop') . '</button>';

        // The key to the lock. Offered to anyone who could have answered the
        // procedure — the lock is a speed bump, not a permission — and every
        // turn of it is logged, which is what makes editing a finished record
        // visible after the fact.
        if (Run::isPermitted($run) && !Settings::isFrozen($run['completed_at'] ?? null)) {
            if (Run::isLocked($run)) {
                echo "<button type='button' class='btn btn-sm btn-ghost-secondary' "
                   . "data-sop-action='unlock' data-sop-target='$runs_id'>"
                   . "<i class='ti ti-lock-open me-1'></i>"
                   . __s('Unlock to edit', 'glpisop') . '</button>';
            } elseif ((string) $run['status'] === Run::COMPLETED && Settings::flag('lock_on_complete')) {
                echo "<button type='button' class='btn btn-sm btn-ghost-secondary' "
                   . "data-sop-action='lock' data-sop-target='$runs_id'>"
                   . "<i class='ti ti-lock me-1'></i>"
                   . __s('Lock', 'glpisop') . '</button>';
            }
        }

        if ($editable && (string) $run['status'] !== Run::ABANDONED) {
            echo "<button type='button' class='btn btn-sm btn-ghost-danger ms-auto' "
               . "data-sop-action='abandon' data-sop-target='$runs_id' data-sop-confirm='"
               . __s('Mark this procedure as not applying to this item? The answers already '
                   . 'given are kept, but it stops counting towards resolution.', 'glpisop') . "'>"
               . __s('This procedure does not apply', 'glpisop') . '</button>';
        }

        echo '</div>';

        echo "<div class='sop-run-log mt-3' hidden></div>";
    }

    /**
     * Display numbers for the steps.
     *
     * Top-level steps count 1, 2, 3; a conditional step is lettered under the
     * step it hangs off — 3a, 3b — so the shape of the procedure is legible
     * from the numbering alone. A gate can name several earlier steps now, so
     * "the step it hangs off" is the first one its clauses mention; see
     * {@see Step::primaryParent()}. A step gated only on the ticket's own
     * fields or its approvals hangs off nothing and numbers at the top level,
     * which is right — it is not a branch of another question. Numbers are assigned over *all* steps rather
     * than only the visible ones, so opening a branch never renumbers what a
     * technician has already worked through, and a step referred to in a
     * handover note still means the same step an hour later.
     *
     * @param array<int,array<string,mixed>> $steps
     * @return array<int,string>
     */
    public static function numbering(array $steps): array
    {
        $numbers  = [];
        $children = [];
        $root     = 0;

        foreach ($steps as $step) {
            $steps_id  = (int) $step['id'];
            $parent_id = Step::primaryParent($step);

            if ($parent_id <= 0 || !isset($numbers[$parent_id])) {
                $numbers[$steps_id] = (string) (++$root);
                continue;
            }

            $index = $children[$parent_id] = ($children[$parent_id] ?? 0) + 1;
            $numbers[$steps_id] = $numbers[$parent_id] . self::letter($index);
        }

        return $numbers;
    }

    /** 1 => a, 26 => z, 27 => aa. */
    private static function letter(int $index): string
    {
        $out = '';
        while ($index > 0) {
            $index--;
            $out   = chr(97 + ($index % 26)) . $out;
            $index = intdiv($index, 26);
        }

        return $out;
    }

    /**
     * The run's status, as a badge.
     *
     * Split into label and colour so the endpoint can hand the browser both
     * and public/js/sop.js can restyle the badge in place. Rendering the
     * markup here and the replacement text there would be two descriptions of
     * the same three states, and the JS copy would be the untranslated one.
     */
    private static function statusBadge(string $status): string
    {
        return "<span class='badge " . self::e(self::statusClass($status)) . "'>"
            . self::e(self::statusLabel($status)) . '</span>';
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            Run::COMPLETED => __('complete', 'glpisop'),
            Run::ABANDONED => __('not applicable', 'glpisop'),
            default        => __('in progress', 'glpisop'),
        };
    }

    /**
     * `text-white` is not redundant. Tabler's `bg-*` utilities set a
     * background and leave the foreground to whatever the badge inherits,
     * which on GLPI's default theme leaves dark text on a saturated blue —
     * legible enough to pass review and not legible enough to read.
     */
    public static function statusClass(string $status): string
    {
        return match ($status) {
            Run::COMPLETED => 'bg-green text-white',
            Run::ABANDONED => 'bg-secondary text-white',
            default        => 'bg-blue text-white',
        };
    }

    private static function readOnlyReason(array $run): string
    {
        if ((string) $run['status'] === Run::ABANDONED) {
            return __('This procedure was marked as not applying to this item.', 'glpisop');
        }

        if (Run::isLocked($run)) {
            return __('Completed and locked. Unlock it below to correct an answer — the '
                . 'unlock is recorded in the run’s history.', 'glpisop');
        }

        if (Settings::isFrozen($run['completed_at'] ?? null)) {
            return sprintf(
                __('Completed runs are frozen after %d days and can no longer be edited.', 'glpisop'),
                (int) Settings::get('lock_after_days')
            );
        }

        return __('You do not have permission to answer this procedure.', 'glpisop');
    }
}
