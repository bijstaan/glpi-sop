<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBTM;
use GlpiPlugin\Glpiai\Tool;

/**
 * Standard operating procedures, offered to glpi-ai's assistant as tools.
 *
 * The example in glpi-ai's own extension documentation, and it is the example
 * for a reason: a model that cannot read the procedure invents one. Asked how
 * to offboard a user it produces a competent, generic list — and the site's
 * actual procedure has a step about the shared mailbox that the generic list
 * has never heard of and that is the one somebody always forgets.
 *
 * Eight tools, in two halves.
 *
 * **Reading.** `sop_progress` reads the checklist running on an item — what is
 * done, what was skipped and what is still outstanding — and `sop_library`
 * reads the procedures themselves, for the case where nothing is attached yet
 * and the question is "is there a procedure for this at all".
 *
 * **Writing.** `sop_create`, `sop_add_steps`, `sop_update_step`,
 * `sop_delete_step`, `sop_update_section` and `sop_update` let a technician
 * dictate a procedure instead of filling in the editor eleven times — "write
 * this up as a procedure" after a nasty ticket is the moment procedures
 * actually get written, and it is exactly the moment nobody has twenty minutes
 * for the builder.
 *
 * Between them they do everything the builder does to a step: write it with any
 * type and the settings that type takes, reword it, retype it, move it between
 * headings and up and down the order, gate it, retire it, delete it, and rename
 * or remove the headings themselves. The one thing they deliberately do *not*
 * share with the builder is its payload model. The canvas posts the procedure
 * as it should now look and anything missing from it is deleted, which is safe
 * in front of somebody who can see the canvas and is not safe here: a model
 * that leaves a step out of an answer has written a shorter answer, not decided
 * to remove it. So every change here is named.
 *
 * A written procedure branches. A step carries a gate naming an earlier step
 * and the answer it waits for, so "if the mailbox is shared, hand it over"
 * becomes a question and a step that appears when it is answered one way —
 * rather than a label with "if applicable" in it, which asks the person
 * following the procedure to make the judgement it exists to save them.
 * {@see StepWriter} owns that, and holds it to one {@see Condition} source:
 * an earlier answer in the same procedure. Gates on the ticket's own fields or
 * its approvals are written in ids a model does not know, and stay in the
 * builder where there is a picker.
 *
 * Four rules hold across all four writers, and the first three are the ones
 * that hold for a procedure drafted from tickets:
 *
 *  - **Nothing is switched on.** A created procedure is inactive and does not
 *    attach itself, and no tool can change either flag. Somebody switching it
 *    on is this feature's only accept signal, and a model that could do it
 *    would erase the one measurement there is. `is_active` is also the whole
 *    of the blast radius: an inactive procedure is a document, an active
 *    enforcing one can hold a queue's tickets open.
 *  - **Deleting is its own tool, and it asks first.** Retiring a step keeps it
 *    and every answer recorded against it, reversibly, and is what nearly every
 *    "get rid of that step" actually wants; `sop_delete_step` is the other
 *    thing, needs PURGE rather than UPDATE, and refuses a step that has been
 *    answered until it is told the technician was shown the count. Changing a
 *    step's *type* asks the same question for the same reason: the answers stay
 *    behind in the old shape. There is still no tool that deletes a procedure.
 *  - **The steps go through {@see StepWriter}**, so a type nobody implements
 *    costs one dropped step and a note, exactly as it does in drafting.
 *  - **A gate that cannot hold is dropped, not stored.** A branch waiting on
 *    an option the parent step does not offer would save cleanly and never
 *    open, which is indistinguishable from a procedure that has no branch.
 *    Dropped means the step is asked unconditionally — a spare question rather
 *    than a missing one — and the technician is told.
 *
 * **Permissions.** The tools are gated differently on purpose, mirroring the
 * plugin's own split: reading a run needs `plugin_glpisop_run`, reading the
 * library needs `plugin_glpisop_sop`. Neither is enough on its own for the run
 * tool, which additionally re-derives the item the run belongs to and calls
 * `canViewItem()` — the runs table is never entity-filtered in queries, and
 * every other read path in this plugin scopes by access to the *item* rather
 * than by `runs.entities_id`. A tool that trusted the run id alone would be
 * the one place that did it differently.
 *
 * The writers need `plugin_glpisop_sop` at **CREATE** or **UPDATE** rather
 * than READ. `right_level` defaults to READ, and taking that default here
 * would hand authoring to everybody who can read the library — which, on this
 * plugin's own install defaults, is every technician who can update a ticket.
 * They are also `mutates: true`, so they do nothing at all until an
 * administrator turns write tools on in glpi-ai.
 *
 * Holding the right is necessary and not sufficient: each writer re-loads the
 * SOP and calls `canUpdateItem()`, which is what applies the entity
 * restriction. An id is an integer a model can arrive at by counting, and the
 * right on its own says nothing about *whose* procedure this is.
 *
 * Deliberately *not* gated on `Settings::inCentralInterface()`, which every
 * on-page reader here checks. That gate asks "is a technician looking at a
 * form", and a tool call has no form; copying it would make these return
 * nothing whenever the assistant runs from anywhere but a page.
 *
 * Nothing on the *reading* side writes, and one thing about that is easy to
 * get wrong: `Run::recount()` looks like the natural way to total a run up and
 * is a writer — it updates the counters, can flip the run's status and can
 * post a followup — so progress is computed with `Visibility`, which is pure.
 *
 *
 * **The writers are not pinned; the readers are.** Past glpi-ai's tool-search
 * threshold a request declares the pinned tools and leaves the rest to
 * `find_tools`, and these four are the right side of that line: their schemas
 * are the largest this plugin has — `sop_create` alone is two kilobytes of
 * step shape — and they are wanted on the rare turn where somebody is writing
 * a procedure, not on the hundred where somebody is fixing a laptop. Paying
 * five kilobytes of every prompt for them would be paying it mostly to say
 * they exist.
 *
 * That is safe here in a way it would not be for an MCP server an
 * administrator has just configured, because `sop_progress` and `sop_library`
 * *are* pinned: the model always knows this site has procedures, which is what
 * makes it go looking for how to write one. `find_tools` searches every
 * registered tool, pinned or not, and matches on name and description — "write
 * a procedure" reaches `sop_create` on both.
 *
 * Registered unconditionally from setup.php: only glpi-ai reads that hook, so
 * an instance without it never loads this class.
 */
final class AiTools
{
    /** Procedures returned by one library call. */
    private const MAX_SOPS = 10;

    /** @return Tool[] */
    public static function all(): array
    {
        return [
            self::progress(),
            self::library(),
            self::create(),
            self::addSteps(),
            self::updateStep(),
            self::deleteStep(),
            self::updateSection(),
            self::update(),
        ];
    }

    // ------------------------------------------------------------- progress

    private static function progress(): Tool
    {
        return new Tool(
            name: 'sop_progress',
            description: 'Read the procedure checklists attached to a ticket, change or problem: '
                . 'which steps are done, which were skipped, what was answered, and which required '
                . 'steps are still outstanding. Use this before suggesting what to do next on a '
                . 'ticket that has a procedure running — the answer is usually "the next '
                . 'outstanding step", and the steps already answered say what has been tried.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'items_id' => [
                        'type'        => 'integer',
                        'description' => 'The id of the ticket, change or problem. Omit to use '
                            . 'the item the conversation is about.',
                    ],
                    'itemtype' => [
                        'type'        => 'string',
                        'description' => 'Ticket, Change or Problem. Defaults to Ticket.',
                    ],
                ],
            ],
            handler: [self::class, 'runProgress'],
            right: 'plugin_glpisop_run',
            source: 'glpisop'
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runProgress(array $arguments = [], mixed $context = null): array
    {
        $itemtype = trim((string) ($arguments['itemtype'] ?? ''));
        $items_id = (int) ($arguments['items_id'] ?? 0);

        if ($items_id <= 0 && $context instanceof \GlpiPlugin\Glpiai\ToolContext && $context->items_id > 0) {
            $itemtype = $itemtype !== '' ? $itemtype : (string) $context->itemtype;
            $items_id = (int) $context->items_id;
        }

        $itemtype = $itemtype !== '' ? $itemtype : 'Ticket';

        if (!in_array($itemtype, Settings::SUPPORTED_ITEMTYPES, true)) {
            return [
                'error' => sprintf(
                    'Procedures only run on %s.',
                    implode(', ', Settings::SUPPORTED_ITEMTYPES)
                ),
            ];
        }

        if ($items_id <= 0) {
            return ['error' => 'Name the id of the ticket, change or problem.'];
        }

        /** @var CommonDBTM $item */
        $item = new $itemtype();
        if (!$item->getFromDB($items_id) || !$item->canViewItem()) {
            return ['error' => sprintf('There is no %s %d that you can see.', $itemtype, $items_id)];
        }

        $runs = [];
        foreach (Run::forItem($itemtype, $items_id) as $run) {
            $runs[] = self::runDetail($run);
        }

        return [
            'item'      => ['itemtype' => $itemtype, 'id' => $items_id, 'title' => (string) $item->fields['name']],
            'runs'      => $runs,
            // Read from the denormalised counters, and it is the same answer
            // the solve gate itself uses — so "can this be closed" here and in
            // the form cannot disagree.
            'blocking'  => Run::blockers($itemtype, $items_id),
            'note'      => $runs === []
                ? 'No procedure is attached to this item. sop_library will say whether one exists '
                  . 'that could be.'
                : 'A skipped step is a deliberate act with a reason recorded, not an oversight. '
                  . '"blocking" lists procedures whose required steps must be answered before the '
                  . 'item can be solved.',
        ];
    }

    /**
     * One run, with its steps in display order.
     *
     * @param array<string,mixed> $run a runs row, carrying its 'sop' fields
     * @return array<string,mixed>
     */
    private static function runDetail(array $run): array
    {
        $runs_id = (int) $run['id'];
        $sop     = (array) ($run['sop'] ?? []);

        $steps = Step::allFor((int) $run['plugin_glpisop_sops_id']);
        $ids   = array_map(static fn(array $s): int => (int) $s['id'], $steps);

        $answers = Answer::forRun($runs_id, $ids);
        $visible = Visibility::evaluate($steps, $answers, Run::item($run));
        $totals  = Visibility::progress($steps, $answers, $visible);

        $out = [];
        foreach ($steps as $step) {
            $steps_id = (int) $step['id'];

            // A step whose branch condition is unmet is not "not done yet" —
            // it does not apply, and listing it as outstanding would send a
            // technician to do something the procedure says to skip.
            if (!($visible[$steps_id] ?? true)) {
                continue;
            }

            $answer = $answers[$steps_id] ?? Answer::blank($steps_id);
            $state  = (string) ($answer['state'] ?? Answer::PENDING);

            $entry = [
                'label'    => (string) $step['label'],
                'state'    => $state,
                'required' => (bool) $step['is_required'],
            ];

            if ($state === Answer::DONE) {
                $value = StepType::format((string) $step['step_type'], $answer);
                if ($value !== '') {
                    $entry['answer'] = $value;
                }
            }

            $note = trim((string) ($answer['note'] ?? ''));
            if ($note !== '') {
                $entry['note'] = $note;
            }

            $out[] = $entry;
        }

        return [
            'run_id'      => $runs_id,
            'procedure'   => (string) ($sop['name'] ?? ''),
            'status'      => (string) $run['status'],
            'why_attached' => Run::originLabels()[(string) $run['origin']] ?? (string) $run['origin'],
            'done'        => (int) $totals['done'],
            'total'       => (int) $totals['total'],
            'required_outstanding' => (int) $totals['total_required'] - (int) $totals['done_required'],
            'steps'       => $out,
        ];
    }

    // -------------------------------------------------------------- library

    private static function library(): Tool
    {
        return new Tool(
            name: 'sop_library',
            description: 'The standard operating procedures that exist for a kind of work, with '
                . 'their steps. Use this when no procedure is attached to the item but one may '
                . 'exist — "how do we normally do X here" — so the answer is the site\'s own '
                . 'procedure rather than a generic one. Each result says what would cause it to '
                . 'attach itself, which is how to tell whether it is the right one.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'Words from the procedure name, e.g. "offboarding" or '
                            . '"printer". Empty lists everything active.',
                    ],
                    'itemtype' => [
                        'type'        => 'string',
                        'description' => 'Ticket, Change or Problem — procedures are written for '
                            . 'one or more of these. Defaults to Ticket.',
                    ],
                    'include_steps' => [
                        'type'        => 'boolean',
                        'description' => 'Return each procedure\'s steps as well as its name. '
                            . 'Defaults to true.',
                    ],
                ],
            ],
            handler: [self::class, 'runLibrary'],
            right: 'plugin_glpisop_sop',
            source: 'glpisop'
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runLibrary(array $arguments = [], mixed $context = null): array
    {
        $itemtype = trim((string) ($arguments['itemtype'] ?? '')) ?: 'Ticket';

        if (!in_array($itemtype, Settings::SUPPORTED_ITEMTYPES, true)) {
            return [
                'error' => sprintf(
                    'Procedures only run on %s.',
                    implode(', ', Settings::SUPPORTED_ITEMTYPES)
                ),
            ];
        }

        // The conversation's entity, never an argument: activeFor() takes an
        // entity id and applies the recursive restriction to it, so letting a
        // prompt name one would be letting it read another tenant's procedures.
        $entities_id = $context instanceof \GlpiPlugin\Glpiai\ToolContext
            ? $context->entities_id
            : (int) ($_SESSION['glpiactive_entity'] ?? 0);

        $query = mb_strtolower(trim((string) ($arguments['query'] ?? '')));
        $steps = ($arguments['include_steps'] ?? true) !== false;

        $out = [];
        foreach (Sop::activeFor($itemtype, $entities_id) as $sop) {
            if ($query !== '' && !str_contains(mb_strtolower((string) $sop['name']), $query)) {
                continue;
            }

            $entry = [
                'id'          => (int) $sop['id'],
                'name'        => (string) $sop['name'],
                'description' => self::text($sop['content'] ?? ''),
                'attaches_when' => self::triggers((int) $sop['id'], (bool) $sop['match_all']),
                'blocks_solve'  => (bool) $sop['enforce_on_solve'],
            ];

            if ($steps) {
                $entry['headings'] = self::headings((int) $sop['id']);
                $entry['steps']    = self::steps((int) $sop['id']);
            }

            $out[] = $entry;

            if (count($out) >= self::MAX_SOPS) {
                break;
            }
        }

        return [
            'procedures' => $out,
            'note'       => $out === []
                ? sprintf('No active procedure is written for a %s here.', $itemtype)
                : 'These are the site\'s own procedures. Prefer their wording to a general one — '
                  . 'the steps that look unnecessary are usually the site-specific ones.',
        ];
    }

    /**
     * A procedure's steps, as the writers need to be able to name them.
     *
     * The id is the load-bearing part and used to be missing, which made
     * `sop_update_step` unreachable — its own description says to get the id
     * from here — and made `sop_add_steps` unable to hang a branch off a step
     * that already existed. A label is not an identifier: two procedures can
     * word a step the same way, and one procedure can word two steps the same
     * way.
     *
     * `asked_when` is included only where there is a gate, and reads as the
     * builder's own sentence. A model that cannot see the existing branching
     * writes a second copy of it.
     *
     * The clause descriptions name their step as `#id` rather than by position.
     * {@see Condition::describe()} takes display numbers for the builder, whose
     * left column shows "3a" — but here the ids are in the payload beside the
     * description, and "step 3" next to a step whose id is 7 is an invitation
     * to gate the next branch on the wrong one.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function steps(int $sops_id): array
    {
        $headings = Section::allFor($sops_id);

        $out = [];
        foreach (Step::allFor($sops_id) as $row) {
            $sections_id = (int) $row['plugin_glpisop_sections_id'];

            $step = [
                'id'       => (int) $row['id'],
                'label'    => (string) $row['label'],
                'type'     => (string) $row['step_type'],
                'required' => (bool) $row['is_required'],
                'help'     => self::text($row['help'] ?? ''),
                'heading'  => (string) ($headings[$sections_id]['name'] ?? ''),
            ];

            $config = Step::config($row);
            if (StepType::isChoice((string) $row['step_type'])) {
                $step['options'] = array_values(array_map('strval', (array) ($config['options'] ?? [])));
            }

            $gate = Condition::describeAll(Step::conditions($row), Step::mode($row));

            if ($gate !== '') {
                $step['asked_when'] = $gate;
            }

            $out[] = $step;
        }

        return $out;
    }

    /**
     * A procedure's headings, so they can be renamed and reordered by id.
     *
     * The unfiled group is not one of these and never has been — it is section
     * 0, where steps that were never filed live, and there is nothing to rename.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function headings(int $sops_id): array
    {
        $out = [];
        foreach (Section::allFor($sops_id) as $sections_id => $section) {
            $out[] = [
                'id'   => (int) $sections_id,
                'name' => (string) $section['name'],
            ];
        }

        return $out;
    }

    /**
     * What would make a procedure attach itself, in words.
     *
     * @return array<string,mixed>
     */
    private static function triggers(int $sops_id, bool $match_all): array
    {
        $described = [];
        foreach (Trigger::allFor($sops_id) as $trigger) {
            $described[] = Trigger::describe($trigger);
        }

        return [
            'conditions' => $described,
            'match'      => $match_all ? 'all' : 'any',
        ];
    }

    // ------------------------------------------------------------- writing

    /**
     * The shared preamble on every writer's description.
     *
     * Said in the description rather than only in the code because the
     * description is the only thing the model reads before deciding to call
     * something. A model that learns "created procedures are off" from the
     * result has already told the technician it published one.
     */
    private const INACTIVE_NOTE = 'The procedure is left switched off and attached to nothing; '
        . 'somebody has to read it and turn it on. Say so rather than implying it is live.';

    private static function create(): Tool
    {
        return new Tool(
            name: 'sop_create',
            description: 'Write a new standard operating procedure — a named checklist of typed '
                . 'steps — from what the technician tells you, or from what a ticket showed. '
                . 'Reach for this when somebody asks for a procedure, a checklist or a runbook '
                . 'to be written down, or says "next time, we should always...". Check '
                . 'sop_library first: adding steps to the procedure that already covers this is '
                . 'better than a second one beside it. Steps can branch: ask the deciding '
                . 'question as its own step, give it a ref, and gate the steps that follow from '
                . 'it with ask_when, so that whoever runs this is asked only what applies to '
                . 'them. ' . self::INACTIVE_NOTE,
            schema: [
                'type'       => 'object',
                'properties' => [
                    'name'    => [
                        'type'        => 'string',
                        'description' => 'What the procedure is called. Names the problem it is '
                            . 'for, not the fix.',
                    ],
                    'summary' => [
                        'type'        => 'string',
                        'description' => 'One or two sentences: when a technician should follow '
                            . 'this.',
                    ],
                    'itemtypes' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Which of Ticket, Change, Problem it applies to. '
                            . 'Defaults to Ticket.',
                    ],
                    'itilcategories_id' => [
                        'type'        => 'integer',
                        'description' => 'Optional. The category this procedure is for; a trigger '
                            . 'is written so it would attach itself to tickets in that category '
                            . 'once somebody activates it. Omit unless you know the id.',
                    ],
                    'steps'   => StepWriter::schema(),
                ],
                'required'   => ['name', 'steps'],
            ],
            handler: [self::class, 'runCreate'],
            right: 'plugin_glpisop_sop',
            mutates: true,
            right_level: CREATE,
            source: 'glpisop',
            // See the class comment: found by search rather than declared on
            // every request.
            pinned: false
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runCreate(array $arguments = [], mixed $context = null): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'A procedure needs a name.'];
        }

        $notes = [];
        $steps = StepWriter::validate((array) ($arguments['steps'] ?? []), $notes);
        if ($steps === []) {
            return [
                'error' => 'No usable steps were given, so nothing was created. Every step needs '
                    . 'a label and one of these types: ' . implode(', ', array_keys(StepWriter::types())),
                'notes' => $notes,
            ];
        }

        $itemtypes = [];
        foreach ((array) ($arguments['itemtypes'] ?? []) as $itemtype) {
            $itemtype = trim((string) $itemtype);
            if (in_array($itemtype, Settings::SUPPORTED_ITEMTYPES, true)) {
                $itemtypes[] = $itemtype;
            }
        }
        if ($itemtypes === []) {
            $itemtypes = ['Ticket'];
        }

        // The conversation's entity, never an argument — the same rule as
        // sop_library, and it matters more here: an entity id in a prompt
        // would be a way to write a procedure into another tenant's tree.
        $entities_id = $context instanceof \GlpiPlugin\Glpiai\ToolContext
            ? $context->entities_id
            : (int) ($_SESSION['glpiactive_entity'] ?? 0);

        $sop   = new Sop();
        $input = [
            'name'             => mb_substr($name, 0, 250),
            'content'          => self::provenance((string) ($arguments['summary'] ?? '')),
            'itemtypes'        => $itemtypes,
            'is_active'        => 0,
            'is_autoattach'    => 0,
            'match_all'        => 1,
            'enforce_on_solve' => 0,
            'entities_id'      => $entities_id,
            'is_recursive'     => 1,
        ];

        // Both halves again, and CREATE this time. The entity comes from the
        // conversation rather than the prompt, but a session can be active in
        // an entity its profile may not create in — the recursive flag and the
        // profile's own entity list are not the same list.
        if (!$sop->can(-1, CREATE, $input)) {
            return ['error' => 'You cannot create a procedure here.'];
        }

        $sops_id = (int) $sop->add($input);

        if ($sops_id <= 0) {
            return ['error' => 'The procedure could not be created.'];
        }

        // Re-read rather than trust what add() left behind: the steps about to
        // be written ask the SOP what it runs on, and that is a column
        // prepareInputForAdd() rewrote on the way past.
        $sop->getFromDB($sops_id);

        $result = StepWriter::write($sop, $steps, $notes);

        $categories_id = (int) ($arguments['itilcategories_id'] ?? 0);
        if ($categories_id > 0) {
            (new Trigger())->add([
                'plugin_glpisop_sops_id' => $sops_id,
                'criterion'              => 'itilcategories_id',
                'match_condition'        => Trigger::IS,
                'value'                  => (string) $categories_id,
            ]);
        }

        return [
            'created'   => self::reference($sops_id, $name),
            'steps'     => count($result['steps']),
            'branches'  => $result['gates'],
            'is_active' => false,
            'notes'     => $notes,
            'note'      => 'Created and switched off. It attaches to nothing and blocks nothing '
                . 'until somebody opens it, reads the steps and activates it. Give the '
                . 'technician the link.'
                . ($notes === []
                    ? ''
                    : ' Some of what was asked for could not be written exactly — the notes say '
                      . 'what, and a dropped branch means that step is now asked every time. '
                      . 'Tell the technician rather than summarising it as done.'),
        ];
    }

    // ------------------------------------------------------------ add steps

    private static function addSteps(): Tool
    {
        return new Tool(
            name: 'sop_add_steps',
            description: 'Append steps to a procedure that already exists. Use this rather than '
                . 'sop_create when the site already has a procedure for this kind of work and '
                . 'what is missing is a step or two — a check nobody had written down, the thing '
                . 'that went wrong this time. Steps are added at the end of their heading; '
                . 'nothing existing is changed or removed. New steps can branch off the '
                . 'procedure as it stands: an ask_when clause may name a step already in it by '
                . 'the numeric id sop_library gives, as well as a ref written in this call.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'sops_id' => [
                        'type'        => 'integer',
                        'description' => 'The procedure to add to, as returned by sop_library.',
                    ],
                    'after_step' => [
                        'type'        => 'integer',
                        'description' => 'Optional. Put the new steps directly after this step of '
                            . 'the procedure, under its heading, instead of at the end. Omit to '
                            . 'append.',
                    ],
                    'steps'   => StepWriter::schema(),
                ],
                'required'   => ['sops_id', 'steps'],
            ],
            handler: [self::class, 'runAddSteps'],
            right: 'plugin_glpisop_sop',
            mutates: true,
            right_level: UPDATE,
            source: 'glpisop',
            // See the class comment: found by search rather than declared on
            // every request.
            pinned: false
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runAddSteps(array $arguments = [], mixed $context = null): array
    {
        $sop = self::writable((int) ($arguments['sops_id'] ?? 0));
        if (is_string($sop)) {
            return ['error' => $sop];
        }

        $notes = [];
        $steps = StepWriter::validate((array) ($arguments['steps'] ?? []), $notes);
        if ($steps === []) {
            return ['error' => 'No usable steps were given, so nothing was added.', 'notes' => $notes];
        }

        $result = StepWriter::write($sop, $steps, $notes);

        $after = (int) ($arguments['after_step'] ?? 0);
        if ($after > 0 && $result['steps'] !== []) {
            StepEditor::placeAfter($sop, $result['steps'], $after, $notes);
        }

        return [
            'procedure' => self::reference((int) $sop->getID(), (string) $sop->fields['name']),
            'added'     => count($result['steps']),
            'branches'  => $result['gates'],
            'is_active' => (bool) $sop->fields['is_active'],
            'notes'     => $notes,
            'note'      => (bool) $sop->fields['is_active']
                // Worth saying plainly: this procedure is running on live items,
                // and a required step added to it becomes outstanding on every
                // run of it that is already open.
                ? 'This procedure is active, so the new steps are now part of it on every item '
                  . 'it is running on — including tickets already open. Say that.'
                : 'This procedure is switched off, so nothing changed for anybody yet.',
        ];
    }

    // ---------------------------------------------------------- update step

    private static function updateStep(): Tool
    {
        return new Tool(
            name: 'sop_update_step',
            description: 'Change one step of a procedure: its wording, its guidance, whether it '
                . 'is required, what type it is and the settings that go with the type, which '
                . 'heading it sits under, where in the order it comes, what it waits for before '
                . 'it is asked, or whether it is used at all. Everything is optional and '
                . 'anything not given is left exactly as it was. Retiring a step with is_active '
                . '"no" keeps it and every answer already recorded against it, which deleting '
                . 'would not — prefer it. sop_library gives the step ids.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'steps_id'  => [
                        'type'        => 'integer',
                        'description' => 'The step to change. sop_library returns a procedure\'s '
                            . 'step ids.',
                    ],
                    'label'     => [
                        'type'        => 'string',
                        'description' => 'New wording for the step. Omit to leave it alone.',
                    ],
                    'help'      => [
                        'type'        => 'string',
                        'description' => 'New guidance under the step. Omit to leave it alone.',
                    ],
                    'required'  => [
                        'type'        => 'string',
                        'enum'        => ['yes', 'no'],
                        'description' => 'Whether the step is required. Omit to leave it alone.',
                    ],
                    'is_active' => [
                        'type'        => 'string',
                        'enum'        => ['yes', 'no'],
                        'description' => '"no" retires the step: it stops being part of the '
                            . 'procedure and keeps every answer already recorded against it, '
                            . 'reversibly. Omit to leave it alone.',
                    ],
                    'type'      => [
                        'type'        => 'string',
                        'enum'        => array_keys(StepWriter::types()),
                        'description' => 'A different type for the step. Every answer already '
                            . 'recorded stays in the old shape, so this needs confirm_answers '
                            . 'when the step has any. Omit to leave it alone.',
                    ],
                    'options'   => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'The choices, for a choice or multichoice step. Omit to '
                            . 'leave them alone; giving them replaces the list.',
                    ],
                    'config'    => StepWriter::configSchema(),
                    'section'   => [
                        'type'        => 'string',
                        'description' => 'The heading this step sits under, by name; one that '
                            . 'does not exist yet is created. Empty string files it under no '
                            . 'heading. Omit to leave it alone.',
                    ],
                    'after_step' => [
                        'type'        => 'string',
                        'description' => 'Move the step: the id of the step it should come '
                            . 'directly after, or "first". It follows that step under its '
                            . 'heading. Omit to leave the order alone.',
                    ],
                    'ask_when'      => GateWriter::schema(),
                    'ask_when_mode' => [
                        'type'        => 'string',
                        'enum'        => [Condition::MODE_ALL, Condition::MODE_ANY],
                        'description' => 'Whether every clause of ask_when has to hold, or any '
                            . 'one of them.',
                    ],
                    'confirm_answers' => [
                        'type'        => 'string',
                        'enum'        => ['yes', 'no'],
                        'description' => 'Only for a type change on a step that has been answered '
                            . 'on live runs. "yes" means the technician has been told those '
                            . 'answers will be left in the old shape and wants it anyway.',
                    ],
                ],
                'required'   => ['steps_id'],
            ],
            handler: [self::class, 'runUpdateStep'],
            right: 'plugin_glpisop_sop',
            mutates: true,
            right_level: UPDATE,
            source: 'glpisop',
            // See the class comment: found by search rather than declared on
            // every request.
            pinned: false
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runUpdateStep(array $arguments = [], mixed $context = null): array
    {
        $step     = new Step();
        $steps_id = (int) ($arguments['steps_id'] ?? 0);

        if ($steps_id <= 0 || !$step->getFromDB($steps_id)) {
            return ['error' => sprintf('There is no step %d.', $steps_id)];
        }

        // The step carries no entity of its own — its procedure does, and that
        // is the thing to ask about.
        $sop = self::writable((int) $step->fields['plugin_glpisop_sops_id']);
        if (is_string($sop)) {
            return ['error' => $sop];
        }

        $notes  = [];
        $result = StepEditor::apply($sop, $step, $arguments, $notes);

        if ($result['error'] !== '') {
            return ['error' => $result['error'], 'notes' => $notes];
        }

        $out = [
            'procedure' => self::reference((int) $sop->getID(), (string) $sop->fields['name']),
            'step'      => (string) $step->fields['label'],
            'changed'   => $result['changed'],
            'notes'     => $notes,
        ];

        if (in_array('gate', $result['changed'], true)
            || in_array('gate cleared', $result['changed'], true)
        ) {
            $out['conditions'] = $result['gates'];
        }

        // Said every time the step has been answered, not only when something
        // about it was destructive. A step reworded on a procedure that is
        // running has changed the question under people who already answered
        // the old one, and that is worth a sentence to the technician.
        if ($result['answers'] > 0) {
            $out['answered_on_runs'] = $result['answers'];
            $out['note'] = sprintf(
                'This step has been answered on %d run%s already. Those answers are untouched, '
                . 'and where the wording or the type changed they now sit under a question that '
                . 'reads differently. Say so.',
                $result['answers'],
                $result['answers'] === 1 ? '' : 's'
            );
        }

        return $out;
    }

    // ----------------------------------------------------------- delete step

    private static function deleteStep(): Tool
    {
        return new Tool(
            name: 'sop_delete_step',
            description: 'Remove a step from a procedure for good, together with every answer '
                . 'recorded against it on every run, and every branch elsewhere that waited on '
                . 'it. This cannot be undone. Retiring the step instead — sop_update_step with '
                . 'is_active "no" — takes it out of the procedure and keeps the history, and is '
                . 'what almost every request for a step to "go away" actually wants; reach for '
                . 'this one only when somebody has asked for the step and its answers to be '
                . 'gone. It refuses on a step that has been answered unless confirm says the '
                . 'technician was told what would be lost.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'steps_id' => [
                        'type'        => 'integer',
                        'description' => 'The step to delete, as returned by sop_library.',
                    ],
                    'confirm'  => [
                        'type'        => 'string',
                        'enum'        => ['yes', 'no'],
                        'description' => '"yes" means the technician has been told how many '
                            . 'recorded answers this destroys and has said to go ahead. Send it '
                            . 'only after they have.',
                    ],
                ],
                'required'   => ['steps_id'],
            ],
            handler: [self::class, 'runDeleteStep'],
            right: 'plugin_glpisop_sop',
            mutates: true,
            // PURGE, not UPDATE. Deleting a step is not a stronger edit: it
            // destroys evidence on closed tickets, and the profile that may
            // rewrite a procedure is not automatically the one that may do
            // that.
            right_level: PURGE,
            source: 'glpisop',
            pinned: false
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runDeleteStep(array $arguments = [], mixed $context = null): array
    {
        $step     = new Step();
        $steps_id = (int) ($arguments['steps_id'] ?? 0);

        if ($steps_id <= 0 || !$step->getFromDB($steps_id)) {
            return ['error' => sprintf('There is no step %d.', $steps_id)];
        }

        $sop = self::writable((int) $step->fields['plugin_glpisop_sops_id']);
        if (is_string($sop)) {
            return ['error' => $sop];
        }

        $label   = (string) $step->fields['label'];
        $answers = StepEditor::answers($steps_id);

        if ($answers > 0 && StepWriter::yes($arguments['confirm'] ?? null) !== true) {
            return [
                'error' => sprintf(
                    'Not deleted. “%s” has %d recorded answer%s, on runs including ones that are '
                    . 'closed, and deleting it destroys them. Tell the technician that, offer to '
                    . 'retire it instead — which keeps the history — and call this again with '
                    . 'confirm "yes" only if they still want it gone.',
                    $label,
                    $answers,
                    $answers === 1 ? '' : 's'
                ),
                'answers' => $answers,
            ];
        }

        $result = StepEditor::delete($step);

        if (!$result['deleted']) {
            return ['error' => 'That step could not be deleted.'];
        }

        return [
            'procedure'        => self::reference((int) $sop->getID(), (string) $sop->fields['name']),
            'deleted'          => $label,
            'answers_lost'     => $result['answers'],
            'branches_dropped' => $result['gates'],
            'note'             => 'Gone, with everything recorded against it. Say how many '
                . 'answers that was, and say that any step which waited on this one is now '
                . 'asked every time.',
        ];
    }

    // -------------------------------------------------------------- headings

    private static function updateSection(): Tool
    {
        return new Tool(
            name: 'sop_update_section',
            description: 'Rename a heading in a procedure, give it a description, move it, or '
                . 'remove it. Removing a heading keeps its steps — they go back to the unfiled '
                . 'group at the end of the procedure — so it loses nothing but the name. '
                . 'sop_library returns the headings and their ids. New headings do not need this: '
                . 'naming one on a step creates it.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'sections_id'   => [
                        'type'        => 'integer',
                        'description' => 'The heading to change, as returned by sop_library.',
                    ],
                    'name'          => [
                        'type'        => 'string',
                        'description' => 'New name for the heading. Omit to leave it alone.',
                    ],
                    'description'   => [
                        'type'        => 'string',
                        'description' => 'A sentence shown under the heading. Omit to leave it '
                            . 'alone.',
                    ],
                    'after_section' => [
                        'type'        => 'string',
                        'description' => 'Move it: the id of the heading it should come after, or '
                            . '"first". Omit to leave the order alone.',
                    ],
                    'remove'        => [
                        'type'        => 'string',
                        'enum'        => ['yes', 'no'],
                        'description' => '"yes" removes the heading and unfiles its steps.',
                    ],
                ],
                'required'   => ['sections_id'],
            ],
            handler: [self::class, 'runUpdateSection'],
            right: 'plugin_glpisop_sop',
            mutates: true,
            right_level: UPDATE,
            source: 'glpisop',
            pinned: false
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runUpdateSection(array $arguments = [], mixed $context = null): array
    {
        $section     = new Section();
        $sections_id = (int) ($arguments['sections_id'] ?? 0);

        if ($sections_id <= 0 || !$section->getFromDB($sections_id)) {
            return ['error' => sprintf('There is no heading %d.', $sections_id)];
        }

        $sop = self::writable((int) $section->fields['plugin_glpisop_sops_id']);
        if (is_string($sop)) {
            return ['error' => $sop];
        }

        $was    = (string) $section->fields['name'];
        $result = StepEditor::section($sop, $section, $arguments);

        if ($result['error'] !== '') {
            return ['error' => $result['error']];
        }

        return [
            'procedure' => self::reference((int) $sop->getID(), (string) $sop->fields['name']),
            'heading'   => $was,
            'changed'   => $result['changed'],
        ];
    }

    // --------------------------------------------------------------- update

    private static function update(): Tool
    {
        return new Tool(
            name: 'sop_update',
            description: 'Rename a procedure, rewrite its description, or change which of '
                . 'Ticket, Change and Problem it applies to. It cannot activate a procedure, '
                . 'make one attach itself, or make one block resolution — those are decisions a '
                . 'person makes in the procedure\'s own form, and saying so is a better answer '
                . 'than trying.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'sops_id'   => ['type' => 'integer', 'description' => 'The procedure to change.'],
                    'name'      => [
                        'type'        => 'string',
                        'description' => 'New name. Omit to leave it alone.',
                    ],
                    'summary'   => [
                        'type'        => 'string',
                        'description' => 'New description — replaces what is there. Omit to '
                            . 'leave it alone.',
                    ],
                    'itemtypes' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Which of Ticket, Change, Problem it applies to. Omit '
                            . 'to leave it alone.',
                    ],
                ],
                'required'   => ['sops_id'],
            ],
            handler: [self::class, 'runUpdate'],
            right: 'plugin_glpisop_sop',
            mutates: true,
            right_level: UPDATE,
            source: 'glpisop',
            // See the class comment: found by search rather than declared on
            // every request.
            pinned: false
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runUpdate(array $arguments = [], mixed $context = null): array
    {
        $sop = self::writable((int) ($arguments['sops_id'] ?? 0));
        if (is_string($sop)) {
            return ['error' => $sop];
        }

        $input   = ['id' => (int) $sop->getID()];
        $changed = [];

        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name !== '') {
            $input['name'] = mb_substr($name, 0, 250);
            $changed[]     = 'name';
        }

        if (array_key_exists('summary', $arguments)) {
            $input['content'] = mb_substr(trim((string) $arguments['summary']), 0, 4000);
            $changed[]        = 'description';
        }

        if (array_key_exists('itemtypes', $arguments)) {
            $itemtypes = [];
            foreach ((array) $arguments['itemtypes'] as $itemtype) {
                $itemtype = trim((string) $itemtype);
                if (in_array($itemtype, Settings::SUPPORTED_ITEMTYPES, true)) {
                    $itemtypes[] = $itemtype;
                }
            }
            if ($itemtypes === []) {
                return [
                    'error' => 'A procedure has to apply to at least one of '
                        . implode(', ', Settings::SUPPORTED_ITEMTYPES) . '.',
                ];
            }
            // An array, because Sop::prepareInputForUpdate() validates and
            // joins it — handing it a string would skip the validation and
            // store whatever arrived.
            $input['itemtypes'] = $itemtypes;
            $changed[]          = 'itemtypes';
        }

        if ($changed === []) {
            return ['error' => 'Nothing was asked for, so nothing changed.'];
        }

        if (!$sop->update($input)) {
            return ['error' => 'That procedure could not be changed.'];
        }

        return [
            'procedure' => self::reference((int) $sop->getID(), (string) ($input['name'] ?? $sop->fields['name'])),
            'changed'   => $changed,
            'is_active' => (bool) $sop->fields['is_active'],
        ];
    }

    // --------------------------------------------------------------- shared

    /**
     * Load a procedure this session may actually write to, or say why not.
     *
     * `can($id, UPDATE)` rather than either half of it. GLPI splits the
     * question in two and both halves are needed here:
     *
     *  - `canUpdate()` is the *profile* right, which glpi-ai has already
     *    checked through `Tool::$right` before the handler ran — but only when
     *    the call arrived through the tool registry. This method is public
     *    code reachable from anywhere, and a guard that is only correct on one
     *    path is not a guard.
     *  - `canUpdateItem()` is the *item*, which is where the entity
     *    restriction lives. Without it a prompt could reach across tenants by
     *    naming an id, and ids are integers a model can arrive at by counting.
     *
     * `canUpdateItem()` on its own — the obvious-looking choice — checks only
     * the second, so a technician holding READ on procedures could edit any of
     * them.
     *
     * Returns the Sop, or a string to hand back to the model as the result —
     * a refusal it can read is what stops it trying the next id along.
     */
    private static function writable(int $sops_id): Sop|string
    {
        $sop = new Sop();

        if ($sops_id <= 0 || !$sop->getFromDB($sops_id)) {
            return sprintf('There is no procedure %d.', $sops_id);
        }

        if (!$sop->can($sops_id, UPDATE)) {
            return sprintf('You cannot change procedure %d.', $sops_id);
        }

        return $sop;
    }

    /** A procedure, as something the technician can be given. */
    private static function reference(int $sops_id, string $name): array
    {
        return [
            'id'   => $sops_id,
            'name' => $name,
            'url'  => Sop::getFormURLWithID($sops_id),
        ];
    }

    /**
     * The description of a procedure written through the assistant, and a note
     * saying so.
     *
     * The same provenance line drafting writes, for the same reason: whoever
     * opens this in six months and wonders why it says what it says deserves
     * to know it was dictated rather than written, and by whom.
     */
    private static function provenance(string $summary): string
    {
        $lines = [];

        if (trim($summary) !== '') {
            $lines[] = trim($summary);
            $lines[] = '';
        }

        $lines[] = sprintf(
            __('Written through the assistant by %1$s, %2$s. Read it before switching it on.',
                'glpisop'),
            \Session::getLoginUserID() > 0
                ? \getUserName((int) \Session::getLoginUserID())
                : __('an automated process', 'glpisop'),
            \Html::convDateTime(date('Y-m-d H:i:s'))
        );

        return implode("\n", $lines);
    }

    /** Rich text as plain text, capped. */
    private static function text(mixed $value): string
    {
        $plain = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = (string) preg_replace('/\s+/u', ' ', $plain);

        return mb_strlen($plain) > 500 ? mb_substr($plain, 0, 499) . '…' : $plain;
    }
}
