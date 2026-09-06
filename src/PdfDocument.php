<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBTM;
use CommonITILObject;
use GlpiPlugin\Glpipdf\Doc;
use Html;

/**
 * SOPs as documents, for glpi-pdf.
 *
 * Three offers, and they answer three different questions:
 *
 *  - **the procedure** — the authored SOP, as the controlled document somebody
 *    signs off, prints for a wall, or hands an auditor when asked "what is your
 *    process for this". It is the template, so it carries no answers.
 *  - **a run** — one filled-in checklist, as evidence. Who did what, when, what
 *    was skipped and why. This is the artefact that matters at audit.
 *  - **an appendix** — the same run, folded into the ticket's own document, so
 *    a technician exporting a ticket gets the procedure it was worked under in
 *    the same file rather than as a second one.
 *
 * The appendix is the reason this file is here rather than in glpi-pdf. Only
 * this plugin knows that a run's visibility is computed rather than stored, that
 * a hidden step is *not applicable* rather than outstanding, and that a skipped
 * step's note is the reason it was skipped. A generic exporter reading these
 * tables would get all three wrong, and would get them wrong quietly.
 *
 * Registered unconditionally in setup.php: only glpi-pdf reads the hook, so an
 * instance without it pays one array assignment and never loads this class.
 */
final class PdfDocument
{
    /** @return array<int,array<string,mixed>> */
    public static function offers(): array
    {
        // Guarded because the hook is registered unconditionally — see the
        // class comment. Without glpi-pdf installed there is no Doc class to
        // build against, and returning offers that cannot be built would be a
        // fatal at export time rather than an absent button.
        if (!class_exists(Doc::class)) {
            return [];
        }

        $out = [
            [
                'key'      => 'glpisop.procedure',
                'itemtype' => Sop::class,
                'label'    => __('Procedure', 'glpisop'),
                'kind'     => 'document',
                'weight'   => 10,
                'parts'    => [
                    ['key' => 'sop.overview', 'label' => __('Overview and settings', 'glpisop'), 'default' => true],
                    ['key' => 'sop.steps',    'label' => __('The steps', 'glpisop'),             'default' => true],
                    ['key' => 'sop.gates',    'label' => __('Branch conditions', 'glpisop'),     'default' => true],
                ],
                'build'    => static fn(CommonDBTM $item, array $parts = []): ?Doc
                    => self::procedure($item, $parts),
            ],
        ];

        // One appendix per ITIL type the plugin is configured for, so a ticket's
        // document carries its checklists and a problem's does not carry
        // somebody else's.
        foreach (Settings::SUPPORTED_ITEMTYPES as $itemtype) {
            if (!Settings::appliesTo($itemtype) || !class_exists($itemtype)) {
                continue;
            }

            $out[] = [
                'key'      => 'glpisop.runs.' . strtolower($itemtype),
                'itemtype' => $itemtype,
                'label'    => __('SOP checklists', 'glpisop'),
                'kind'     => 'appendix',
                // After the record's own content. A procedure explains work
                // that has already been described above it.
                'weight'   => 300,
                'parts'    => [
                    ['key' => 'sop.run.answers', 'label' => __('Checklist answers', 'glpisop'), 'default' => true],
                    ['key' => 'sop.run.hidden',  'label' => __('Steps that did not apply', 'glpisop'), 'default' => true],
                ],
                'build'    => static fn(CommonDBTM $item, array $parts = []): ?Doc
                    => self::runsOn($item, $parts),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------- the procedure

    /**
     * The authored SOP.
     *
     * Steps are listed with their gates written out as sentences, because that
     * is the half of a branching procedure a reader cannot reconstruct from the
     * step list alone — and it is the half somebody reviewing the procedure is
     * actually reviewing.
     *
     * @param string[] $parts the sections the reader ticked
     */
    private static function procedure(CommonDBTM $item, array $parts = []): ?Doc
    {
        if (!($item instanceof Sop)) {
            return null;
        }

        // An empty selection is what a caller with no opinion passes — a plain
        // export link, a bulk action — and it means everything.
        $want = static fn(string $key): bool => $parts === [] || in_array($key, $parts, true);

        $sops_id = (int) $item->getID();
        $steps   = Step::allFor($sops_id, false);
        $numbers = Renderer::numbering($steps);

        $doc = Doc::make((string) $item->fields['name'])
            ->reference(sprintf('SOP r%d', (int) $item->fields['sop_version']))
            ->subtitle(__('Standard operating procedure', 'glpisop'))
            ->meta($want('sop.overview') ? [
                __('Entity')     => \Dropdown::getDropdownName('glpi_entities', (int) $item->fields['entities_id']),
                __('Applies to', 'glpisop') => implode(', ', array_map(
                    static fn(string $t): string => $t::getTypeName(1),
                    $item->itemtypes()
                )),
                __('Active')     => (int) $item->fields['is_active'] === 1 ? __('Yes') : __('No'),
                __('Enforcing', 'glpisop') => (int) $item->fields['enforce_on_solve'] === 1
                    ? __('blocks resolution while a required step is outstanding', 'glpisop')
                    : __('no', 'glpisop'),
                __('Last changed', 'glpisop') => self::when($item->fields['date_mod'] ?? null),
            ] : []);

        if ($want('sop.overview') && trim((string) ($item->fields['content'] ?? '')) !== '') {
            $doc->text((string) $item->fields['content']);
        }

        if (!$want('sop.steps')) {
            return $doc;
        }

        if ($steps === []) {
            return $doc->note(
                __('This procedure has no steps.', 'glpisop'),
                Doc::WARN
            );
        }

        $sections = Section::allFor($sops_id);
        $current  = null;

        foreach ($steps as $step) {
            $sections_id = (int) $step['plugin_glpisop_sections_id'];

            if ($sections_id !== $current) {
                $current = $sections_id;
                $section = $sections[$sections_id] ?? null;

                $doc->section($section !== null
                    ? (string) $section['name']
                    : __('Unfiled steps', 'glpisop'));

                if ($section !== null && trim((string) $section['content']) !== '') {
                    $doc->muted((string) $section['content']);
                }

                $rows = [];
            }

            $rows[] = self::procedureRow($step, $numbers, $want('sop.gates'));

            // Flushed per section rather than once at the end, so a section
            // heading is followed by its own table instead of by every step in
            // the procedure.
            $doc->blocks = self::replaceTrailingTable($doc, $rows);
        }

        return $doc;
    }

    /**
     * @param array<string,mixed> $step
     * @param array<int,string>   $numbers
     * @return array<int,string>
     */
    private static function procedureRow(array $step, array $numbers, bool $with_gates = true): array
    {
        $steps_id = (int) $step['id'];

        $label = (string) $step['label'];

        if (trim((string) ($step['help'] ?? '')) !== '') {
            $label .= "\n" . (string) $step['help'];
        }

        $gate = $with_gates
            ? Condition::describeAll(Step::conditions($step), Step::mode($step), $numbers)
            : '';

        if ($gate !== '') {
            $label .= "\n" . $gate;
        }

        return [
            (string) ($numbers[$steps_id] ?? ''),
            $label,
            StepType::label((string) $step['step_type']),
            (int) $step['is_required'] === 1 ? __('Yes') : __('No'),
        ];
    }

    /**
     * Replace the table block at the end of the document, or add one.
     *
     * The procedure is built section by section and a section's table grows a
     * row at a time, so each row rewrites the table it belongs to rather than
     * the document accumulating one block per row. Ugly in the small; it keeps
     * the section/table grouping in one loop instead of two.
     *
     * @param array<int,array<int,string>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function replaceTrailingTable(Doc $doc, array $rows): array
    {
        $blocks = $doc->blocks;
        $last   = count($blocks) - 1;

        $table = [
            'type'    => 'table',
            'headers' => [
                '#',
                __('Step', 'glpisop'),
                __('Type', 'glpisop'),
                __('Required', 'glpisop'),
            ],
            'rows'    => $rows,
            'widths'  => [7, 63, 18, 12],
        ];

        if ($last >= 0 && ($blocks[$last]['type'] ?? '') === 'table') {
            $blocks[$last] = $table;
        } else {
            $blocks[] = $table;
        }

        return $blocks;
    }

    // -------------------------------------------------------------- a run

    /**
     * Every run on an ITIL item, as one appendix.
     *
     * Returns null rather than an empty document when there are none, so a
     * ticket that never carried a procedure gets no empty "SOP checklists"
     * heading — see Registry::build(), which drops an empty Doc.
     */
    /** @param string[] $parts */
    private static function runsOn(CommonDBTM $item, array $parts = []): ?Doc
    {
        if (!($item instanceof CommonITILObject)) {
            return null;
        }

        $runs = Run::forItem($item::getType(), (int) $item->getID());
        if ($runs === []) {
            return null;
        }

        $doc = Doc::make(__('Procedures', 'glpisop'));

        foreach ($runs as $index => $run) {
            if ($index > 0) {
                $doc->spacer();
            }
            self::run($doc, $run, $parts);
        }

        return $doc;
    }

    /**
     * One run, written into a document.
     *
     * Visibility is recomputed rather than read: which steps a run is asking is
     * derived from the answers and from the item, and there is no stored copy
     * of it. A step that is not being asked is printed as *not applicable*
     * rather than left out entirely — leaving it out would make the procedure
     * on paper look shorter than the one on screen, and "why does the printed
     * copy have eleven steps and the ticket fourteen" is a question nobody
     * should have to answer at an audit.
     *
     * @param array<string,mixed> $run
     */
    private static function run(Doc $doc, array $run, array $parts = []): void
    {
        $want = static fn(string $key): bool => $parts === [] || in_array($key, $parts, true);

        $runs_id = (int) $run['id'];
        $sop     = (array) $run['sop'];

        $steps     = Step::allFor((int) $run['plugin_glpisop_sops_id']);
        $steps_ids = array_map(static fn(array $s): int => (int) $s['id'], $steps);
        $answers   = Answer::forRun($runs_id, $steps_ids);
        $visible   = Visibility::evaluate($steps, $answers, Run::item($run));
        $progress  = Visibility::progress($steps, $answers, $visible);
        $numbers   = Renderer::numbering($steps);

        $doc->subsection((string) $sop['name']);

        $doc->kv([
            __('Status')  => Renderer::statusLabel((string) $run['status']),
            __('Revision', 'glpisop') => 'r' . (int) $run['sop_version'],
            __('Progress', 'glpisop') => Renderer::progressText($progress),
            __('How it arrived', 'glpisop') => Run::originLabels()[(string) $run['origin']]
                ?? (string) $run['origin'],
            __('Started', 'glpisop')   => self::when($run['date_creation'] ?? null),
            __('Completed', 'glpisop') => self::when($run['completed_at'] ?? null),
        ]);

        $outstanding = (int) $run['total_required'] - (int) $run['done_required'];

        if ((string) $run['status'] === Run::ABANDONED) {
            $doc->note(
                __('This procedure was marked as not applying to this item. The answers already '
                   . 'given are kept and stop counting.', 'glpisop'),
                Doc::WARN
            );
        } elseif ($outstanding > 0) {
            $doc->note(
                sprintf(
                    _n(
                        '%d required step was still outstanding when this was exported.',
                        '%d required steps were still outstanding when this was exported.',
                        $outstanding,
                        'glpisop'
                    ),
                    $outstanding
                ),
                Doc::WARN
            );
        }

        if (!$want('sop.run.answers')) {
            return;
        }

        $items = [];
        foreach ($steps as $step) {
            $steps_id = (int) $step['id'];
            $answer   = $answers[$steps_id];
            $asked    = (bool) ($visible[$steps_id] ?? false);
            $state    = (string) $answer['state'];

            // A step the run never asked is normally printed and marked, so the
            // paper copy has the same number of steps as the screen. Unticking
            // it is for the reader who wants only what was actually done.
            if (!$asked && !$want('sop.run.hidden')) {
                continue;
            }

            $items[] = [
                'number' => $numbers[$steps_id] ?? '',
                'label'  => (string) $step['label']
                    . ((int) $step['is_required'] === 1 ? ' *' : ''),
                'help'   => $asked ? '' : __('not applicable to this item', 'glpisop'),
                'state'  => self::state($asked, $state),
                'answer' => $asked ? self::answerLine($step, $answer) : '',
                'meta'   => $asked ? Renderer::stepMetaText($answer) : '',
            ];
        }

        $doc->checklist($items);
        $doc->muted(__('* required', 'glpisop'));
    }

    /**
     * A step's answer as one line.
     *
     * A skipped step's note is its *reason*, and is labelled as one — the same
     * note field carries an ordinary annotation on an answered step, and
     * printing both as "Note:" would lose the distinction that matters at
     * audit.
     *
     * @param array<string,mixed> $step
     * @param array<string,mixed> $answer
     */
    private static function answerLine(array $step, array $answer): string
    {
        $state = (string) $answer['state'];
        $note  = trim((string) ($answer['note'] ?? ''));

        if ($state === Answer::SKIPPED) {
            return $note !== ''
                ? sprintf(__('Skipped: %s', 'glpisop'), $note)
                : __('Skipped, with no reason recorded.', 'glpisop');
        }

        $parts = [];

        $value = StepType::format((string) $step['step_type'], $answer);
        if ($value !== '' && $value !== __('done', 'glpisop')) {
            $parts[] = $value;
        }

        if ($note !== '') {
            $parts[] = sprintf(__('Note: %s', 'glpisop'), $note);
        }

        return implode(' — ', $parts);
    }

    private static function state(bool $asked, string $state): string
    {
        if (!$asked) {
            return Doc::NA;
        }

        return match ($state) {
            Answer::DONE    => Doc::DONE,
            Answer::SKIPPED => Doc::SKIPPED,
            default         => Doc::PENDING,
        };
    }

    private static function when(mixed $stamp): string
    {
        $stamp = (string) ($stamp ?? '');

        return $stamp === '' || str_starts_with($stamp, '0000')
            ? ''
            : (string) Html::convDateTime($stamp);
    }
}
