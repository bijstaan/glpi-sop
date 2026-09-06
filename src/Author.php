<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use Dropdown;
use Html;
use Ticket;

/**
 * Drafting a procedure from the tickets that were already fixed by hand.
 *
 * The roadmap entry this answers asked for a procedure drafted "from clusters
 * of resolved tickets", and the word *cluster* was the hard part for a while:
 * finding groups by similarity meant an embedding index, and the one this
 * suite had was withdrawn for being too slow where people met it.
 *
 * **The cluster key is the ITIL category.** Not a fallback for the absent
 * index — a better key. An SOP attaches on category, so a procedure drafted
 * from "the last dozen tickets in Account lockout" is a procedure whose
 * trigger is the same thing that chose its evidence. A similarity score would
 * have produced groups that cut across the categories the procedure then has
 * to be attached by, which is a mismatch somebody has to resolve by hand every
 * time.
 *
 * What a distance metric cannot do is notice that a category is a dumping
 * ground, so that judgement is asked of the model instead: the schema has a
 * `usable` field, and "these are not one procedure" is an answer it is told to
 * give rather than a failure it has to be caught making.
 *
 * The evidence comes from glpi-ai's own gatherer — {@see
 * \GlpiPlugin\Glpiai\Draft\Evidence} — which already reads the timeline, the
 * tasks, the procedures worked through and, free of charge, glpi-osquery's
 * findings, since that plugin writes them into the timeline. The rendering
 * here is this plugin's own: drafting reads one ticket in full, and this reads
 * a dozen, so the same evidence has to arrive far more tightly.
 *
 * Nothing is published. The SOP is created inactive, with automatic attachment
 * off, and the category trigger it would need is written but does nothing
 * until an author turns both on. Same posture as the plugin's shipped example,
 * and the same as glpi-ai's unpublished articles: the draft is the beginning
 * of an author's work, not the end of it.
 */
final class Author
{
    /**
     * Output budget, sized for a reasoning model rather than for the answer.
     *
     * A procedure of a dozen steps with help text is genuinely long — call it
     * 800 tokens of answer — and a model that thinks first spends the budget
     * before writing any of it. glpi-ai's triage allows 1500 tokens for an
     * ~80-token answer for exactly this reason; the same multiple here is
     * 6000, and a model that does not think simply stops early and never
     * approaches it.
     */
    private const MAX_TOKENS = 6000;

    /** Per ticket. Enough for a real diagnosis, small enough that twelve fit. */
    private const MAX_TICKET_CHARS = 1800;

    /** The whole evidence block, across every ticket. */
    private const MAX_TOTAL_CHARS = 24000;

    /**
     * The step vocabulary a draft may use.
     *
     * It lives in {@see StepWriter}, because drafting is no longer the only
     * thing that turns a model's proposal into steps — the assistant's write
     * tools do it too, and the list a model is offered has to be the same list
     * its answer is checked against wherever that happens.
     *
     * @return array<string,string>
     */
    public static function types(): array
    {
        return StepWriter::types();
    }

    // ------------------------------------------------------------ readiness

    public static function available(): bool
    {
        return self::unavailableReason() === null;
    }

    /**
     * Why a procedure cannot be drafted here, in words, or null when it can.
     *
     * Every string lands in front of an administrator who just opened the
     * page, so each one names the thing to go and change.
     */
    public static function unavailableReason(): ?string
    {
        if (!class_exists(\GlpiPlugin\Glpiai\Client::class)) {
            return __('glpi-ai is not installed, so there is nothing to draft with.', 'glpisop');
        }

        if (!Settings::flag('authoring_enabled')) {
            return __('Drafting procedures from tickets is switched off on this plugin\'s '
                . 'settings page.', 'glpisop');
        }

        if (!\GlpiPlugin\Glpiai\Client::isReady()) {
            return __('glpi-ai has no configured provider, or is switched off.', 'glpisop');
        }

        return null;
    }

    // ------------------------------------------------------------ the cluster

    /**
     * Resolved tickets in one category, newest first.
     *
     * Solved and closed only. An open ticket has no ending, and a procedure
     * drafted from tickets that are still being worked would be a list of
     * things somebody tried, which is a different document.
     *
     * @return array<int,array{id:int,name:string,solvedate:string}>
     */
    public static function candidates(int $entities_id, int $itilcategories_id, int $days): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($itilcategories_id <= 0) {
            return [];
        }

        $since = date('Y-m-d H:i:s', time() - (max(1, $days) * 86400));

        // The subtree, narrowed to what this session may actually see.
        //
        // Not `getEntitiesRestrictCriteria(..., $is_recursive: true)`: that
        // flag means "the *item* can be recursive and has an is_recursive
        // column", which an SOP has and a ticket does not — passing it here
        // produces a query against a column that does not exist. Descending
        // the tree is `getSonsOf`, and intersecting with the active entities
        // is what keeps a chosen parent from reaching children the profile was
        // never given.
        $entities = array_values(array_intersect(
            getSonsOf('glpi_entities', $entities_id),
            $_SESSION['glpiactiveentities'] ?? []
        ));

        if ($entities === []) {
            return [];
        }

        $out = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'solvedate', 'closedate', 'date_mod'],
                'FROM'   => Ticket::getTable(),
                'WHERE'  => [
                    'itilcategories_id' => $itilcategories_id,
                    'is_deleted'        => 0,
                    'status'            => [Ticket::SOLVED, Ticket::CLOSED],
                    'OR'                => [
                        ['solvedate' => ['>=', $since]],
                        ['AND' => [['solvedate' => null], ['closedate' => ['>=', $since]]]],
                    ],
                ] + getEntitiesRestrictCriteria(Ticket::getTable(), 'entities_id', $entities),
                'ORDER'  => 'solvedate DESC',
                'LIMIT'  => (int) Settings::get('authoring_max_tickets'),
            ]) as $row
        ) {
            $out[] = [
                'id'        => (int) $row['id'],
                'name'      => (string) $row['name'],
                'solvedate' => (string) ($row['solvedate'] ?: $row['closedate'] ?: $row['date_mod']),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------- drafting

    /**
     * Draft one procedure from the given tickets.
     *
     * Never throws. Returns the new SOP's id, or 0 and a reason — the caller
     * is a page with an administrator waiting on it, and a stack trace is not
     * an answer to "why is there no procedure".
     *
     * @param int[] $tickets_id
     * @return array{sops_id:int,error:string,notes:string[]}
     */
    public static function draft(array $tickets_id, int $entities_id, int $itilcategories_id): array
    {
        $fail = static fn(string $why): array => ['sops_id' => 0, 'error' => $why, 'notes' => []];

        $reason = self::unavailableReason();
        if ($reason !== null) {
            return $fail($reason);
        }

        $minimum = (int) Settings::get('authoring_min_tickets');
        if (count($tickets_id) < $minimum) {
            return $fail(sprintf(
                _n(
                    'A procedure needs at least %d ticket to draft from.',
                    'A procedure needs at least %d tickets to draft from.',
                    $minimum,
                    'glpisop'
                ),
                $minimum
            ));
        }

        [$evidence, $used] = self::gather($tickets_id);

        if (count($used) < $minimum) {
            return $fail(__('Those tickets have nothing on them to draft from — no followups, no '
                . 'tasks, and no procedure worked through. A procedure cannot be written from '
                . 'titles.', 'glpisop'));
        }

        $category = Dropdown::getDropdownName('glpi_itilcategories', $itilcategories_id);

        try {
            $prompt = \GlpiPlugin\Glpiai\Prompt::make(
                self::userText($category, $evidence),
                self::instruction()
            )
                ->withTier(\GlpiPlugin\Glpiai\Prompt::TIER_QUALITY)
                ->withSchema(self::schema(), 'procedure')
                ->withMaxTokens(self::MAX_TOKENS);

            // The entity is passed explicitly, as glpi-ai requires: its tenant
            // gate is not ours to re-implement or to second-guess, and it
            // throws when the entity is not permitted.
            $completion = \GlpiPlugin\Glpiai\Client::complete($prompt, $entities_id);
        } catch (\Throwable $e) {
            return $fail($e->getMessage());
        }

        $data = $completion->data;
        if (!is_array($data)) {
            return $fail($completion->wasTruncated()
                ? sprintf(
                    __('The model used its whole %d-token budget before answering. That usually '
                        . 'means a reasoning model; either raise the ceiling or use one that '
                        . 'thinks less.', 'glpisop'),
                    self::MAX_TOKENS
                )
                : __('The provider did not return a usable procedure.', 'glpisop'));
        }

        // The refusal the schema asks for, and the reason it is worth asking:
        // a category that is a dumping ground produces a procedure that reads
        // well and describes nothing, and that is far harder to spot than an
        // error would have been.
        if (StepWriter::yes($data['usable'] ?? '') === false) {
            $why = trim((string) ($data['gaps'] ?? ''));

            return $fail($why !== ''
                ? sprintf(__('The model would not write one procedure from these tickets: %s',
                    'glpisop'), $why)
                : __('The model would not write one procedure from these tickets — they do not '
                    . 'look like variations of the same problem.', 'glpisop'));
        }

        return self::materialise($data, $used, $entities_id, $itilcategories_id, $category, $completion);
    }

    // ------------------------------------------------------------- evidence

    /**
     * The evidence block, and the tickets that actually contributed to it.
     *
     * A ticket with a title and nothing else is dropped rather than padded
     * out. Its absence is the honest signal — a category where most tickets
     * are empty is one nobody wrote anything down in, and a procedure drafted
     * from the two that had notes should say it came from two.
     *
     * @param int[] $tickets_id
     * @return array{0:string,1:int[]}
     */
    private static function gather(array $tickets_id): array
    {
        $blocks = [];
        $used   = [];
        $total  = 0;

        foreach ($tickets_id as $id) {
            $ticket = new Ticket();
            if (!$ticket->getFromDB((int) $id) || !$ticket->canViewItem()) {
                continue;
            }

            $evidence = \GlpiPlugin\Glpiai\Draft\Evidence::forTicket($ticket);
            if (\GlpiPlugin\Glpiai\Draft\Evidence::isThin($evidence)) {
                continue;
            }

            $block = self::renderTicket($ticket, $evidence);
            if ($total + mb_strlen($block) > self::MAX_TOTAL_CHARS) {
                break;
            }

            $blocks[] = $block;
            $used[]   = (int) $ticket->getID();
            $total   += mb_strlen($block);
        }

        return [implode("\n\n", $blocks), $used];
    }

    /**
     * One ticket, compressed.
     *
     * Drafting renders a single ticket at up to 12,000 characters because it
     * is writing that ticket up. This is writing a procedure *across* tickets,
     * where what matters is the shape they share — so each one gets a fixed,
     * much smaller share, and the solution is kept whole in preference to the
     * chatter, because the solution is the closest thing on a ticket to a
     * step somebody actually took.
     *
     * @param array<string,mixed> $evidence
     */
    private static function renderTicket(Ticket $ticket, array $evidence): string
    {
        $cut = static fn(string $text, int $max): string => mb_strlen($text) > $max
            ? mb_substr($text, 0, $max) . '…'
            : $text;

        $lines = [
            'TICKET #' . (int) $ticket->getID() . ': ' . (string) $ticket->fields['name'],
            'Reported: ' . $cut(trim((string) ($evidence['ticket']['reported'] ?? '')), 400),
        ];

        $solution = \GlpiPlugin\Glpiai\Draft\Evidence::existingSolution($ticket);
        if ($solution !== '') {
            $lines[] = 'How it was resolved: ' . $cut($solution, 600);
        }

        $room = self::MAX_TICKET_CHARS - mb_strlen(implode("\n", $lines));

        foreach ($evidence['procedures'] as $procedure) {
            $lines[] = 'A procedure was already followed (' . $procedure['name'] . '):';
            foreach ($procedure['steps'] as $step) {
                $line = '  - ' . $step['step'] . ': ' . $step['state']
                      . ($step['value'] !== '' ? ' = ' . $step['value'] : '');
                $room -= mb_strlen($line);
                if ($room < 0) {
                    break 2;
                }
                $lines[] = $line;
            }
        }

        foreach ($evidence['timeline'] as $entry) {
            $line = '  - ' . ($entry['kind'] === 'task' ? 'TASK' : 'NOTE') . ': '
                  . $cut($entry['text'], 300);
            $room -= mb_strlen($line);
            if ($room < 0) {
                break;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    // --------------------------------------------------------------- prompt

    private static function instruction(): string
    {
        return implode("\n", array_merge([
            'You write standard operating procedures for the service desk of a managed service',
            'provider. You are given several tickets that were all filed under one category and',
            'have all been resolved, with what people wrote on them and what any procedure they',
            'followed recorded.',
            '',
            'Write the checklist a technician should work through the next time one of these',
            'arrives. It is read by someone who has not seen any of these tickets.',
            '',
            'The rules that matter:',
            '',
            '  - Write only what these tickets support. If they never show how something was',
            '    verified, do not invent a verification step. A procedure with an invented step',
            '    in it is worse than a short one, because somebody will follow it.',
            '  - One action per step. "Check the licence and reassign it" is two steps, and as',
            '    one it cannot be answered.',
            '  - Order the steps the way the work actually goes: what to establish first, what to',
            '    do about it, what to confirm at the end.',
            '  - Prefer a step that records a value over one that is merely ticked, wherever the',
            '    tickets show somebody finding something out. A recorded value is evidence; a',
            '    tick is a claim.',
            '  - Do not write steps for what GLPI already does — assigning the ticket, setting a',
            '    priority, writing the solution, closing it.',
            '  - Say what a step is for in its help text, in one sentence, and only where it is',
            '    not obvious from the label. Leave it empty otherwise.',
            '  - Mark a step required only where skipping it would make the rest unreliable.',
            '  - Use sections only if the work genuinely falls into phases. Fewer than about six',
            '    steps never needs them. Leave the section empty on every step if not.',
            '  - Take out anything that belongs to one occurrence: a customer, their people, a',
            '    hostname, a ticket number. This procedure will be run on other tickets.',
            '  - If these tickets are not variations of one problem, set usable to "no" and say',
            '    so in gaps. Do not stitch two procedures together to have something to return.',
            '    A category that collects unrelated work is a real thing, and saying so is the',
            '    useful answer.',
            '  - In gaps, name what the tickets did not show — a step you suspect exists but',
            '    nobody wrote down, a check that was only done once. The author reads this before',
            '    publishing.',
            '',
            'Step types, and when each is right:',
        ], [StepWriter::typeGuide()]));
    }

    private static function userText(string $category, string $evidence): string
    {
        return 'These resolved tickets were all filed under the category: ' . $category . "\n\n"
             . $evidence;
    }

    /**
     * The answer shape.
     *
     * Flat on purpose. The sections are a string on each step rather than a
     * nesting of steps inside sections: glpi-ai's portability rule is plain
     * types and plain nesting, three schema dialects sit behind it, and one
     * array of flat objects is the shape all three agree on. Grouping runs of
     * equal section names back into sections is two lines here and one more
     * thing to be rejected by a provider there.
     *
     * @return array<string,mixed>
     */
    private static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'usable'  => [
                    'type'        => 'string',
                    'enum'        => ['yes', 'no'],
                    'description' => 'Whether these tickets are variations of one problem that '
                        . 'one procedure can cover.',
                ],
                'name'    => [
                    'type'        => 'string',
                    'description' => 'What the procedure is called. Names the problem, not the fix.',
                ],
                'summary' => [
                    'type'        => 'string',
                    'description' => 'One or two sentences: when a technician should follow this.',
                ],
                'steps'   => StepWriter::schema(),
                'gaps'    => [
                    'type'        => 'string',
                    'description' => 'What these tickets did not establish. Empty if nothing.',
                ],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            ],
            'required'   => ['usable', 'name', 'summary', 'steps', 'gaps', 'confidence'],
        ];
    }

    // ---------------------------------------------------------- the artefact

    /**
     * Turn a validated answer into an inactive SOP.
     *
     * Every correction made on the way is collected in `notes` and shown to
     * the author. A draft that quietly lost three steps to validation, and a
     * draft the model wrote as nine steps, look identical afterwards — and the
     * first is a prompt problem worth knowing about.
     *
     * @param array<string,mixed> $data
     * @param int[]               $tickets_id
     * @return array{sops_id:int,error:string,notes:string[]}
     */
    private static function materialise(
        array $data,
        array $tickets_id,
        int $entities_id,
        int $itilcategories_id,
        string $category,
        \GlpiPlugin\Glpiai\Completion $completion
    ): array {
        $notes = [];
        $steps = StepWriter::validate((array) ($data['steps'] ?? []), $notes);

        if ($steps === []) {
            return [
                'sops_id' => 0,
                'error'   => __('The model returned no steps that could be used.', 'glpisop'),
                'notes'   => $notes,
            ];
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $name = sprintf(__('Procedure — %s', 'glpisop'), $category);
        }

        $summary = trim((string) ($data['summary'] ?? ''));
        $gaps    = trim((string) ($data['gaps'] ?? ''));

        $sop   = new Sop();
        $input = [
            'name'             => mb_substr($name, 0, 250),
            'content'          => self::provenance($summary, $gaps, $tickets_id, $category, $completion),
            'itemtypes'        => ['Ticket'],
            // Inactive, and not attaching. Two switches rather than one because
            // they are two different decisions: whether this procedure is any
            // good, and whether it should turn up on tickets by itself.
            'is_active'        => 0,
            'is_autoattach'    => 0,
            'match_all'        => 1,
            'enforce_on_solve' => 0,
            'entities_id'      => $entities_id,
            'is_recursive'     => 1,
        ];

        // The entity came in on the request and `add()` authorises nothing, so
        // this is the only thing standing between a caller and a procedure
        // planted in somebody else's tenant. The UPDATE right that opened the
        // page is profile-wide and says nothing about *which* entity — and a
        // session can be active in an entity its profile may not create in.
        // Same guard, for the same reason, as AiTools::runCreate().
        if (!$sop->can(-1, CREATE, $input)) {
            return [
                'sops_id' => 0,
                'error'   => __('You cannot create a procedure in that entity.', 'glpisop'),
                'notes'   => $notes,
            ];
        }

        $sops_id = (int) $sop->add($input);

        if ($sops_id <= 0) {
            return [
                'sops_id' => 0,
                'error'   => __('The procedure could not be created.', 'glpisop'),
                'notes'   => $notes,
            ];
        }

        StepWriter::write($sops_id, $steps);

        // The trigger the cluster key already is. It cannot fire — the SOP is
        // inactive and does not auto-attach — but writing it means the author
        // turning the procedure on does not then have to work out for
        // themselves which category it came from.
        $trigger = new Trigger();
        $trigger->add([
            'plugin_glpisop_sops_id' => $sops_id,
            'criterion'              => 'itilcategories_id',
            'match_condition'        => Trigger::IS,
            'value'                  => (string) $itilcategories_id,
        ]);

        Authoring::record(
            $sops_id,
            $entities_id,
            $itilcategories_id,
            $tickets_id,
            count($steps),
            (string) $completion->provider,
            (string) $completion->model,
            in_array($data['confidence'] ?? '', ['high', 'medium', 'low'], true)
                ? (string) $data['confidence']
                : 'low',
            $gaps
        );

        return ['sops_id' => $sops_id, 'error' => '', 'notes' => $notes];
    }

    /**
     * The procedure's description, and where it came from.
     *
     * Written into the SOP itself rather than only into the authoring record,
     * because the person who needs it most is whoever opens this procedure in
     * six months and wonders why it says what it says. The ticket numbers are
     * the answer, and they are one search away from the tickets themselves.
     *
     * @param int[] $tickets_id
     */
    private static function provenance(
        string $summary,
        string $gaps,
        array $tickets_id,
        string $category,
        \GlpiPlugin\Glpiai\Completion $completion
    ): string {
        $lines = [];

        if ($summary !== '') {
            $lines[] = $summary;
            $lines[] = '';
        }

        $lines[] = sprintf(
            __('Drafted from %1$d resolved tickets in "%2$s" (#%3$s) by %4$s, %5$s. Read it '
                . 'before switching it on.', 'glpisop'),
            count($tickets_id),
            $category,
            implode(', #', $tickets_id),
            $completion->model !== '' ? $completion->model : $completion->provider,
            Html::convDateTime(date('Y-m-d H:i:s'))
        );

        if ($gaps !== '') {
            $lines[] = '';
            $lines[] = __('What the tickets did not establish: ', 'glpisop') . $gaps;
        }

        return implode("\n", $lines);
    }
}
