<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

/**
 * Steps arriving from somewhere that is not the procedure editor.
 *
 * Two callers so far and they are further apart than they look: {@see Author}
 * drafts a whole procedure from resolved tickets, and {@see AiTools} lets a
 * technician dictate one to the assistant. Both hand over the same thing — a
 * flat list of proposed steps from a language model — and both need the same
 * three answers: which types may be asked for, whether what came back is one
 * of them, and how it becomes rows.
 *
 * Keeping that in one place is not tidiness. The list of types offered to a
 * model is also the allowlist its answer is checked against, and two copies of
 * that would drift into a model being told about a type the validator drops —
 * which costs a silently missing step rather than an error.
 *
 * **Flat, with the section as a string on each step.** Not steps nested inside
 * sections: glpi-ai's portability rule is plain types and plain nesting, three
 * schema dialects sit behind it, and one array of flat objects is the shape all
 * three agree on. Runs of equal section names become one section here, which is
 * two lines of code rather than one more thing for a provider to reject.
 *
 * ## Every type, and the two lists
 *
 * Anything the builder offers can be written here, because a technician saying
 * "and then it needs Karen to approve it" is describing an approval step, and a
 * tool that could not write one would leave them to open the builder anyway —
 * which is the twenty minutes this exists to save.
 *
 * {@see draftable()} is the shorter list, and it is about {@see Author} rather
 * than about the types. Drafting reads resolved tickets and proposes a
 * procedure nobody asked for; an approval step proposed from ticket prose is an
 * organisational fact invented from a sample, and a ticket step is a model
 * deciding another team gets work. Dictation has a technician in the room
 * saying so, which is the difference.
 *
 * ## Config
 *
 * A step type's own settings — a number's bounds, a choice's options, what a
 * ticket step raises — are collected by {@see StepOptions::collect()}, the same
 * function the builder's save runs through. Arriving as key/value pairs rather
 * than as a property per setting: sixteen settings across seven types would be
 * sixteen keys on every step, and two of the three provider dialects require
 * every declared property on every object.
 *
 * Settings that are ids in the database are written here as names — "Hardware",
 * "Service Desk" — and resolved by {@see Lookup}, for the reason given there: a
 * guessed id is not detectably wrong.
 *
 * ## Branches
 *
 * A step may carry a gate. {@see GateWriter} owns that end to end; what this
 * class does is name the steps a gate can point at. Steps are named by `ref`,
 * not by position, because position is a reference into a list this class is
 * allowed to drop steps out of — so a validation failure three steps up would
 * silently re-point a gate at the wrong question, the one failure mode here
 * that produces a procedure which looks right and asks the wrong things.
 */
final class StepWriter
{
    /** Steps taken from one answer. Past this it is a manual, not a procedure. */
    public const MAX_STEPS = 25;

    /**
     * Every step type that may be written, and when each is right.
     *
     * @return array<string,string> type => when to use it, for the prompt
     */
    public static function types(): array
    {
        return [
            StepType::CHECK       => 'something the technician does, and ticks when done',
            StepType::YESNO       => 'a question with a real negative answer, where "no" matters '
                                   . 'and a later step should react to it',
            StepType::TEXT        => 'a short value to record: a hostname, a reference, a name',
            StepType::TEXTAREA    => 'a longer note: what an error said, what a check returned',
            StepType::NUMBER      => 'a measured number: a count, a size, a duration',
            StepType::CHOICE      => 'one of a fixed set of outcomes; supply the options',
            StepType::MULTICHOICE => 'several of a fixed set; supply the options',
            StepType::DATE        => 'a date to record: when a licence runs out, when it was '
                                   . 'collected',
            StepType::DATETIME    => 'a date and a time, where the time of day genuinely matters',
            StepType::USER        => 'a person, chosen from GLPI: who it was handed to, who '
                                   . 'confirmed it',
            StepType::ASSET       => 'the machine or device the work was done on',
            StepType::DOCUMENT    => 'a file that has to be attached: a signed form, a photograph '
                                   . 'of a label',
            StepType::TICKET      => 'raises a linked ticket when the run reaches it — for work '
                                   . 'another team does, not for the technician following this',
            StepType::APPROVAL    => 'answered by GLPI\'s own approval on the item, so nobody can '
                                   . 'tick it on somebody else\'s behalf',
        ];
    }

    /**
     * The types a procedure drafted from tickets may use.
     *
     * Four are absent and the reason is the caller, not the type. `ticket` and
     * `approval` are answered by somebody other than the technician — they
     * model workflow, and proposing "wait for manager approval" from ticket
     * prose is proposing an organisational fact a model cannot see. `document`
     * asks for an upload and `datetime` for a precision that prose never
     * establishes. All four are available to a technician dictating a
     * procedure, where a person is choosing them deliberately.
     *
     * @return array<string,string>
     */
    public static function draftable(): array
    {
        $out = [];
        foreach (self::types() as $type => $when) {
            if (in_array($type, [
                StepType::DATETIME,
                StepType::DOCUMENT,
                StepType::TICKET,
                StepType::APPROVAL,
            ], true)) {
                continue;
            }

            $out[$type] = $when;
        }

        return $out;
    }

    /**
     * The types, described, for a system instruction.
     *
     * @param array<string,string>|null $types defaults to everything writable
     */
    public static function typeGuide(?array $types = null): string
    {
        $lines = [];
        foreach ($types ?? self::types() as $type => $when) {
            $lines[] = sprintf('  - %s: %s', $type, $when);
        }

        return implode("\n", $lines);
    }

    /**
     * The settings each type takes, keyed by the name a model writes.
     *
     * The right-hand side is the key {@see StepOptions::collect()} reads, and
     * `id` marks the ones that are a record in the database rather than a
     * value: those arrive as names and are resolved before they are collected.
     *
     * Prefixed on purpose. `title` alone, on a flat list of settings shared by
     * every type, is a step's own label as often as it is the title of the
     * ticket the step raises.
     *
     * @return array<string,array<string,array{key:string,id?:string,list?:string}>>
     */
    public static function settings(): array
    {
        return [
            StepType::TEXT     => [
                'placeholder' => ['key' => 'placeholder'],
                'pattern'     => ['key' => 'pattern'],
            ],
            StepType::TEXTAREA => [
                'placeholder' => ['key' => 'placeholder'],
                'pattern'     => ['key' => 'pattern'],
            ],
            StepType::NUMBER   => [
                'min'  => ['key' => 'min'],
                'max'  => ['key' => 'max'],
                'unit' => ['key' => 'unit'],
            ],
            StepType::ASSET    => [
                'asset_itemtypes' => ['key' => 'itemtypes'],
            ],
            StepType::APPROVAL => [
                'approval_status' => ['key' => 'require_status', 'list' => 'approval'],
                'approval_user'   => ['key' => 'users_id',  'id' => 'glpi_users'],
                'approval_group'  => ['key' => 'groups_id', 'id' => 'glpi_groups'],
            ],
            StepType::TICKET   => [
                'ticket_title'        => ['key' => 'title'],
                'ticket_content'      => ['key' => 'content'],
                'ticket_category'     => ['key' => 'itilcategories_id', 'id' => 'glpi_itilcategories'],
                'ticket_assign_group' => ['key' => 'groups_id_assign',  'id' => 'glpi_groups'],
                'ticket_type'         => ['key' => 'type', 'list' => 'tickettype'],
                'ticket_link'         => ['key' => 'link_as'],
                'ticket_complete_on'  => ['key' => 'complete_on'],
            ],
        ];
    }

    /** The settings, described, for a tool's schema. */
    public static function settingGuide(): string
    {
        $lines = [];
        foreach (self::settings() as $type => $keys) {
            $lines[] = $type . ': ' . implode(', ', array_keys($keys));
        }

        return implode('; ', $lines);
    }

    /**
     * The JSON Schema fragment for a list of steps.
     *
     * One definition shared by the drafting prompt and every tool that takes
     * steps, so a model is described the same shape wherever it meets it.
     *
     * Every property is required, including the ones that are usually empty.
     * Two of the three provider dialects behind this treat a partial object as
     * a schema violation rather than as a default, and "" and [] say the same
     * thing as an absent key at a cost of a few tokens.
     *
     * @param array<string,string>|null $types defaults to everything writable
     * @return array<string,mixed>
     */
    public static function schema(?array $types = null): array
    {
        $types ??= self::types();

        return [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'section'  => [
                        'type'        => 'string',
                        'description' => 'Heading this step falls under. Empty for none. Steps '
                            . 'sharing a heading are grouped under it, in order.',
                    ],
                    'ref'      => [
                        'type'        => 'string',
                        'description' => 'A short id for this step, unique in this call, e.g. '
                            . '"q1", with no colon in it. Steps that are only asked after a '
                            . 'particular answer here name it in ask_when. Empty when nothing '
                            . 'branches on this step.',
                    ],
                    'label'    => [
                        'type'        => 'string',
                        'description' => 'The step itself, as an instruction. One action per '
                            . 'step — a step that says "check X and then do Y" cannot be '
                            . 'answered.',
                    ],
                    'help'     => [
                        'type'        => 'string',
                        'description' => 'One sentence of why or how. Empty if obvious.',
                    ],
                    'type'     => [
                        'type'        => 'string',
                        'enum'        => array_keys($types),
                        'description' => 'How the step is answered.',
                    ],
                    'required' => [
                        'type'        => 'string',
                        'enum'        => ['yes', 'no'],
                        'description' => 'Required only where skipping it makes the rest '
                            . 'unreliable.',
                    ],
                    'options'  => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'The choices, for a choice or multichoice step. Empty '
                            . 'otherwise.',
                    ],
                    'config'   => self::configSchema(),
                    'ask_when' => GateWriter::schema(),
                    'ask_when_mode' => [
                        'type'        => 'string',
                        'enum'        => [Condition::MODE_ALL, Condition::MODE_ANY],
                        'description' => 'Whether every clause of ask_when has to hold, or any '
                            . 'one of them. "all" when there is one clause or none.',
                    ],
                ],
                'required'   => [
                    'section', 'ref', 'label', 'help', 'type', 'required', 'options', 'config',
                    'ask_when', 'ask_when_mode',
                ],
            ],
        ];
    }

    /**
     * The settings of one step, as key/value pairs.
     *
     * @return array<string,mixed>
     */
    public static function configSchema(): array
    {
        return [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'key'   => [
                        'type'        => 'string',
                        'description' => 'Which setting. By step type — ' . self::settingGuide()
                            . '. Anything else is ignored.',
                    ],
                    'value' => [
                        'type'        => 'string',
                        'description' => 'What it is set to, written as a person would say it: a '
                            . 'category, group or person by name; an approval_status of '
                            . 'approved, waiting for approval or refused; a ticket_type of '
                            . 'incident or request; a ticket_link of son (a child ticket, which '
                            . 'holds this one open) or link (a plain link); a ticket_complete_on '
                            . 'of created or closed; asset_itemtypes as a comma-separated list '
                            . 'of GLPI classes such as "Computer,Monitor".',
                    ],
                ],
                'required'   => ['key', 'value'],
            ],
            'description' => 'Settings belonging to this step\'s type. Empty for a type that '
                . 'takes none — a checkbox, a yes/no, a date, a user.',
        ];
    }

    /**
     * Check proposed steps against what this plugin can actually store.
     *
     * A step with an unknown type or no label is dropped rather than guessed
     * at. A choice with nothing to choose from becomes a short text step: the
     * intent was to record a value there and the options are what failed, so
     * keeping the step and losing the list is closer to what was meant than
     * losing both.
     *
     * Every correction is collected in `$notes` and handed back to whoever
     * asked. A draft that quietly lost three steps to validation and a draft
     * the model only wrote nine of look identical afterwards, and the first is
     * a prompt problem worth knowing about.
     *
     * Gates are planned in a second pass, once the surviving steps are known —
     * see {@see gates()}. Settings wait for {@see write()}, because the names
     * in them are ids that need the database.
     *
     * @param array<int,mixed>          $raw
     * @param string[]                  $notes
     * @param array<string,string>|null $types defaults to everything writable
     * @return array<int,array<string,mixed>>
     */
    public static function validate(array $raw, array &$notes, ?array $types = null): array
    {
        $allowed = array_keys($types ?? self::types());
        $steps   = [];
        $dropped = 0;

        foreach ($raw as $item) {
            if (!is_array($item)) {
                $dropped++;
                continue;
            }

            $label = trim((string) ($item['label'] ?? ''));
            $type  = trim((string) ($item['type'] ?? ''));

            if ($label === '' || !in_array($type, $allowed, true)) {
                $dropped++;
                continue;
            }

            $options = [];
            foreach ((array) ($item['options'] ?? []) as $option) {
                $option = trim((string) $option);
                if ($option !== '' && !in_array($option, $options, true)) {
                    $options[] = mb_substr($option, 0, 120);
                }
            }

            if (StepType::isChoice($type) && count($options) < 2) {
                $type    = StepType::TEXT;
                $options = [];
                $notes[] = sprintf(
                    __('"%s" was proposed as a choice with no options, and is a short text step '
                        . 'instead.', 'glpisop'),
                    $label
                );
            }

            $mode = mb_strtolower(trim((string) ($item['ask_when_mode'] ?? '')));

            // A ref with a colon in it would split as a subject — "step:q1:x"
            // — so the separator is taken out rather than the step refused.
            $ref = str_replace(':', '', trim((string) ($item['ref'] ?? '')));

            $steps[] = [
                'label'    => mb_substr($label, 0, 250),
                'help'     => mb_substr(trim((string) ($item['help'] ?? '')), 0, 1000),
                'section'  => mb_substr(trim((string) ($item['section'] ?? '')), 0, 250),
                'type'     => $type,
                'required' => self::yes($item['required'] ?? '') === true ? 1 : 0,
                'options'  => $options,
                'ref'      => mb_substr($ref, 0, 40),
                'mode'     => $mode === Condition::MODE_ANY
                    ? Condition::MODE_ANY
                    : Condition::MODE_ALL,
                'config'   => self::pairs($item['config'] ?? null),
                // Planned below, once it is known which steps survived.
                'gate'       => is_array($item['ask_when'] ?? null) ? $item['ask_when'] : [],
                'conditions' => [],
            ];
        }

        if ($dropped > 0) {
            $notes[] = sprintf(
                _n(
                    '%d proposed step could not be used and was left out.',
                    '%d proposed steps could not be used and were left out.',
                    $dropped,
                    'glpisop'
                ),
                $dropped
            );
        }

        if (count($steps) > self::MAX_STEPS) {
            $notes[] = sprintf(
                __('%1$d steps were proposed; the first %2$d were kept.', 'glpisop'),
                count($steps),
                self::MAX_STEPS
            );
            $steps = array_slice($steps, 0, self::MAX_STEPS);
        }

        return self::gates($steps, $notes);
    }

    /**
     * Key/value pairs as a map, last one winning.
     *
     * @return array<string,string>
     */
    public static function pairs(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $key = trim((string) ($pair['key'] ?? ''));
            if ($key !== '') {
                $out[mb_strtolower($key)] = trim((string) ($pair['value'] ?? ''));
            }
        }

        return $out;
    }

    /**
     * Plan each step's gate against the steps that survived validation.
     *
     * Runs after the whole list is known, because a gate is about another row:
     * resolving it inline would mean a clause could only ever name a step that
     * had already been read, which is the rule anyway — but it would also mean
     * a clause naming a *dropped* step resolving against whatever now sits at
     * that position.
     *
     * @param array<int,array<string,mixed>> $steps
     * @param string[]                       $notes
     * @return array<int,array<string,mixed>>
     */
    private static function gates(array $steps, array &$notes): array
    {
        $by_ref = [];
        foreach ($steps as $index => $step) {
            $ref = mb_strtolower((string) $step['ref']);
            if ($ref === '') {
                continue;
            }

            if (isset($by_ref[$ref])) {
                $notes[] = sprintf(
                    __('Two steps were given the id "%s"; anything waiting on it was read as '
                        . 'waiting on the first.', 'glpisop'),
                    (string) $step['ref']
                );
                continue;
            }

            $by_ref[$ref] = $index;
        }

        foreach ($steps as $index => $step) {
            $steps[$index]['conditions'] = GateWriter::plan(
                (array) $step['gate'],
                $steps,
                $by_ref,
                $index,
                (string) $step['label'],
                $notes
            );

            unset($steps[$index]['gate']);
        }

        return $steps;
    }

    /**
     * Write validated steps onto an SOP, with their settings and their gates.
     *
     * Appends: a heading that already exists on this procedure is reused and
     * the step joins the end of it, so this is the same call whether the SOP
     * was created a moment ago or a year ago. Comparison is on the trimmed,
     * case-folded name, so "Checks" and "checks" are one heading rather than
     * two.
     *
     * A step with no heading gets section 0, which is what "unfiled" means
     * everywhere else here — and those sort after every named section, so the
     * order a model gave is preserved only within a heading. That is the
     * existing display rule rather than a decision taken here.
     *
     * Two passes, for the reason {@see BuilderSave} has three: a gate names a
     * step that may not have an id yet when its own row is written. Both run
     * inside one {@see Sop::deferStructureChange()}, so a twenty-step
     * procedure re-derives its runs once rather than twenty times — which for
     * `sop_add_steps` on an active SOP is twenty recounts of every open ticket
     * carrying it.
     *
     * @param array<int,array<string,mixed>> $steps as returned by validate()
     * @param string[]                       $notes what could not be written exactly
     * @return array{steps:int[],gates:int} the step ids written, in order, and how
     *                                      many clauses were written across them
     */
    public static function write(Sop $sop, array $steps, array &$notes = []): array
    {
        $sops_id = (int) $sop->getID();
        $written = [];
        $gates   = 0;

        Sop::deferStructureChange(
            $sops_id,
            static function () use ($sop, $sops_id, $steps, &$written, &$gates, &$notes): void {
                $ids = self::writeSteps($sop, $steps, $written, $notes);

                foreach ($steps as $index => $step) {
                    $steps_id = $ids[$index] ?? 0;
                    $clauses  = (array) ($step['conditions'] ?? []);

                    if ($steps_id <= 0 || $clauses === []) {
                        continue;
                    }

                    $gates += GateWriter::write(
                        $sop,
                        $steps_id,
                        $clauses,
                        $ids,
                        (string) $step['label'],
                        $notes
                    );
                }
            }
        );

        return ['steps' => $written, 'gates' => $gates];
    }

    /**
     * The steps themselves.
     *
     * @param array<int,array<string,mixed>> $steps
     * @param int[]                          $written filled with the ids, in order
     * @param string[]                       $notes
     * @return array<int,int> position in $steps => step id
     */
    private static function writeSteps(Sop $sop, array $steps, array &$written, array &$notes): array
    {
        $sops_id  = (int) $sop->getID();
        $sections = self::sectionsOf($sops_id);
        $ids      = [];

        foreach ($steps as $index => $step) {
            $heading = (string) $step['section'];
            $key     = mb_strtolower(trim($heading));

            $sections_id = 0;
            if ($key !== '') {
                if (!isset($sections[$key])) {
                    $section        = new Section();
                    $sections[$key] = (int) $section->add([
                        'plugin_glpisop_sops_id' => $sops_id,
                        'name'                   => $heading,
                        'content'                => '',
                        'rank_order'             => Section::nextRank($sops_id),
                    ]);
                }
                $sections_id = $sections[$key];
            }

            $row = new Step();
            $id  = (int) $row->add([
                'plugin_glpisop_sops_id'     => $sops_id,
                'plugin_glpisop_sections_id' => $sections_id,
                'label'                      => $step['label'],
                'help'                       => $step['help'],
                'step_type'                  => $step['type'],
                'config_json'                => json_encode(
                    self::config(
                        $sop,
                        (string) $step['type'],
                        (array) $step['config'],
                        (array) $step['options'],
                        (string) $step['label'],
                        $notes
                    ),
                    JSON_UNESCAPED_UNICODE
                ),
                'is_required'                => (int) $step['required'],
                'is_active'                  => 1,
                'rank_order'                 => Step::nextRank($sops_id, $sections_id),
                'depends_mode'               => (string) ($step['mode'] ?? Condition::MODE_ALL),
            ]);

            if ($id > 0) {
                $written[]   = $id;
                $ids[$index] = $id;
            }
        }

        return $ids;
    }

    /**
     * One step's settings, resolved and collected.
     *
     * Goes through {@see StepOptions::collect()} rather than building the array
     * here, so that a step written by a model and a step saved from the builder
     * hold the same config for the same type. Anything the type does not take
     * is dropped by `collect()` itself, which is why a stale `min` cannot
     * survive a step being retyped.
     *
     * @param array<string,string> $config  as the model wrote it
     * @param string[]             $options the choices, from the step itself
     * @param string[]             $notes
     * @return array<string,mixed>
     */
    public static function config(
        Sop $sop,
        string $type,
        array $config,
        array $options,
        string $label,
        array &$notes
    ): array {
        $cfg = [];

        // The choices live on the step rather than in its settings, because
        // they are the one setting a model reaches for constantly and a
        // key/value pair is a poor place for a list. The builder posts them as
        // lines of a textarea, which is what collect() reads.
        if (StepType::isChoice($type) && $options !== []) {
            $cfg['options'] = implode("\n", $options);
        }

        $accepted = self::settings()[$type] ?? [];

        foreach ($config as $key => $value) {
            if (!isset($accepted[$key])) {
                // Not an error worth a note on its own: a model listing a
                // setting that belongs to another type has written something
                // harmless, and collect() would drop it anyway.
                continue;
            }

            $setting = $accepted[$key];
            $target  = (string) $setting['key'];

            if (isset($setting['id'])) {
                $resolved = self::resolveId((string) $setting['id'], $value, $key, $label, $notes);
                if ($resolved === null) {
                    continue;
                }

                $cfg[$target] = (string) $resolved;
                continue;
            }

            if (isset($setting['list'])) {
                $resolved = self::resolveList((string) $setting['list'], $value, $key, $label, $notes);
                if ($resolved === null) {
                    continue;
                }

                $cfg[$target] = (string) $resolved;
                continue;
            }

            // itemtypes is the one plain setting that is a list. Comma rather
            // than a nested array, for the same reason the settings are pairs.
            if ($target === 'itemtypes') {
                $cfg[$target] = array_values(array_filter(array_map(
                    'trim',
                    explode(',', $value)
                )));
                continue;
            }

            $cfg[$target] = $value;
        }

        // An approval step with no status named requires the one an author
        // means nine times in ten, rather than 0 — which reads as "not subject
        // to approval" and is satisfied the moment the run starts.
        if ($type === StepType::APPROVAL && !isset($cfg['require_status'])) {
            $cfg['require_status'] = (string) Lookup::defaultApprovalStatus();
        }

        // Any of them, not the first: a procedure written for both changes and
        // tickets has approvals wherever one of the two does, and warning off
        // the first itemtype in a list is warning off an ordering nobody chose.
        $approvable = false;
        foreach ($sop->itemtypes() ?: ['Ticket'] as $itemtype) {
            $approvable = $approvable || Approval::supports($itemtype);
        }

        if ($type === StepType::APPROVAL && !$approvable) {
            $notes[] = sprintf(
                __('“%s” is an approval step, and nothing this procedure runs on has approvals. '
                    . 'It will never be satisfied.', 'glpisop'),
                $label
            );
        }

        return StepOptions::collect($type, $cfg);
    }

    /**
     * A setting that is a record, by name.
     *
     * @param string[] $notes
     */
    private static function resolveId(
        string $table,
        string $value,
        string $key,
        string $label,
        array &$notes
    ): ?int {
        if (trim($value) === '') {
            return null;
        }

        $failure = '';
        $id      = $table === 'glpi_users'
            ? Lookup::user($value, $failure)
            : Lookup::inTable($table, $value, $failure);

        if ($id <= 0) {
            $notes[] = sprintf(
                __('The %1$s of “%2$s” names %3$s', 'glpisop'),
                $key,
                $label,
                Lookup::say($failure, str_replace('glpi_', '', $table), $value)
            );
            return null;
        }

        return $id;
    }

    /**
     * A setting that is one of a fixed list, by its label.
     *
     * @param string[] $notes
     */
    private static function resolveList(
        string $list,
        string $value,
        string $key,
        string $label,
        array &$notes
    ): ?int {
        if (trim($value) === '') {
            return null;
        }

        $labels = match ($list) {
            'approval'   => Approval::requirableStatuses(),
            'tickettype' => Lookup::ticketTypes(),
            default      => [],
        };

        $failure = '';
        $found   = Lookup::inList($labels, $value, $failure);

        if ($found <= 0) {
            $notes[] = sprintf(
                __('The %1$s of “%2$s” is "%3$s", which is not one of: %4$s. It was left unset.',
                    'glpisop'),
                $key,
                $label,
                $value,
                implode(', ', array_map('strval', $labels))
            );
            return null;
        }

        return $found;
    }

    /**
     * This SOP's existing headings, keyed by their folded name.
     *
     * @return array<string,int>
     */
    private static function sectionsOf(int $sops_id): array
    {
        $out = [];
        foreach (Section::allFor($sops_id) as $sections_id => $section) {
            $name = mb_strtolower(trim((string) ($section['name'] ?? '')));
            if ($name !== '') {
                $out[$name] = (int) $sections_id;
            }
        }

        return $out;
    }

    /**
     * The schema says yes or no; this says what that meant.
     *
     * Null for anything else, so a field that never arrived is distinguishable
     * from one that said no — which matters wherever a missing value must not
     * read as a refusal.
     */
    public static function yes(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (mb_strtolower(trim((string) $value))) {
            'yes', 'true', '1' => true,
            'no', 'false', '0' => false,
            default            => null,
        };
    }
}
