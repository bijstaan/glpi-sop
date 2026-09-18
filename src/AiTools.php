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
 * Six tools, in two halves.
 *
 * **Reading.** `sop_progress` reads the checklist running on an item — what is
 * done, what was skipped and what is still outstanding — and `sop_library`
 * reads the procedures themselves, for the case where nothing is attached yet
 * and the question is "is there a procedure for this at all".
 *
 * **Writing.** `sop_create`, `sop_add_steps`, `sop_update_step` and
 * `sop_update` let a technician dictate a procedure instead of filling in the
 * editor eleven times — "write this up as a procedure" after a nasty ticket
 * is the moment procedures actually get written, and it is exactly the moment
 * nobody has twenty minutes for the builder.
 *
 * Three rules hold across all four, and they are the same three that hold for
 * a procedure drafted from tickets:
 *
 *  - **Nothing is switched on.** A created procedure is inactive and does not
 *    attach itself, and no tool can change either flag. Somebody switching it
 *    on is this feature's only accept signal, and a model that could do it
 *    would erase the one measurement there is. `is_active` is also the whole
 *    of the blast radius: an inactive procedure is a document, an active
 *    enforcing one can hold a queue's tickets open.
 *  - **Nothing is deleted.** A step can be deactivated, which is reversible
 *    and visible in the builder; there is no tool that removes a step, a
 *    section or a procedure. Deleting a step takes its answers with it, on
 *    every run that ever recorded one.
 *  - **The steps go through {@see StepWriter}**, so a type nobody implements
 *    costs one dropped step and a note, exactly as it does in drafting.
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
                $entry['steps'] = array_map(
                    static fn(array $s): array => [
                        'label'    => (string) $s['label'],
                        'required' => (bool) $s['is_required'],
                        'help'     => self::text($s['help'] ?? ''),
                    ],
                    Step::allFor((int) $sop['id'])
                );
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
                . 'better than a second one beside it. ' . self::INACTIVE_NOTE,
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

        $written = StepWriter::write($sops_id, $steps);

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
            'steps'     => count($written),
            'is_active' => false,
            'notes'     => $notes,
            'note'      => 'Created and switched off. It attaches to nothing and blocks nothing '
                . 'until somebody opens it, reads the steps and activates it. Give the '
                . 'technician the link.',
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
                . 'nothing existing is changed or removed.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'sops_id' => [
                        'type'        => 'integer',
                        'description' => 'The procedure to add to, as returned by sop_library.',
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

        $written = StepWriter::write((int) $sop->getID(), $steps);

        return [
            'procedure' => self::reference((int) $sop->getID(), (string) $sop->fields['name']),
            'added'     => count($written),
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
                . 'is required, or whether it is used at all. Use it to fix a step that asks for '
                . 'the wrong thing, or to retire one that no longer applies — deactivating keeps '
                . 'the step and the answers already recorded against it, which deleting would '
                . 'not. There is no tool that deletes a step; say so if asked.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'steps_id'  => [
                        'type'        => 'integer',
                        'description' => 'The step to change. sop_library returns a procedure\'s '
                            . 'steps with their ids.',
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
                            . 'procedure but keeps its history. Omit to leave it alone.',
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
        $steps_id = (int) ($arguments['steps_id'] ?? 0);

        $step = new Step();
        if ($steps_id <= 0 || !$step->getFromDB($steps_id)) {
            return ['error' => sprintf('There is no step %d.', $steps_id)];
        }

        // The step carries no entity of its own — its procedure does, and that
        // is the thing to ask about.
        $sop = self::writable((int) $step->fields['plugin_glpisop_sops_id']);
        if (is_string($sop)) {
            return ['error' => $sop];
        }

        $input   = ['id' => $steps_id];
        $changed = [];

        $label = trim((string) ($arguments['label'] ?? ''));
        if ($label !== '') {
            $input['label'] = mb_substr($label, 0, 250);
            $changed[]      = 'label';
        }

        if (array_key_exists('help', $arguments)) {
            $input['help'] = mb_substr(trim((string) $arguments['help']), 0, 1000);
            $changed[]     = 'help';
        }

        $required = StepWriter::yes($arguments['required'] ?? null);
        if ($required !== null) {
            $input['is_required'] = $required ? 1 : 0;
            $changed[]            = 'required';
        }

        $active = StepWriter::yes($arguments['is_active'] ?? null);
        if ($active !== null) {
            $input['is_active'] = $active ? 1 : 0;
            $changed[]          = $active ? 'reinstated' : 'retired';
        }

        if ($changed === []) {
            return ['error' => 'Nothing was asked for, so nothing changed.'];
        }

        // The step type is deliberately not changeable here. Changing it
        // rewrites what an answer means, and every answer already recorded
        // against the step stays behind in the old shape — a yes/no step turned
        // into a number leaves "yes" where a number should be, on every run
        // that ever answered it.
        if (!$step->update($input)) {
            return ['error' => 'That step could not be changed.'];
        }

        return [
            'procedure' => self::reference((int) $sop->getID(), (string) $sop->fields['name']),
            'step'      => (string) ($input['label'] ?? $step->fields['label']),
            'changed'   => $changed,
            'note'      => 'The step type cannot be changed by a tool — a type change would '
                . 'leave every answer already recorded in the old shape. Ask the technician to '
                . 'do that in the editor if they need it.',
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
