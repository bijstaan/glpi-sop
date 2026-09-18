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
 */
final class StepWriter
{
    /** Steps taken from one answer. Past this it is a manual, not a procedure. */
    public const MAX_STEPS = 25;

    /**
     * Step types that may be asked for, and nothing else.
     *
     * Four of the plugin's types are deliberately absent. `ticket` and
     * `approval` are answered by somebody other than the technician — they
     * model workflow, and proposing "wait for manager approval" from ticket
     * prose is proposing an organisational fact a model cannot see.
     * `document` asks for an upload, and `datetime` for a precision that
     * prose never establishes. All four remain available in the editor, where a
     * person is choosing them deliberately.
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
            StepType::ASSET       => 'the machine or device the work was done on',
        ];
    }

    /** The types, described, for a system instruction. */
    public static function typeGuide(): string
    {
        $lines = [];
        foreach (self::types() as $type => $when) {
            $lines[] = sprintf('  - %s: %s', $type, $when);
        }

        return implode("\n", $lines);
    }

    /**
     * The JSON Schema fragment for a list of steps.
     *
     * One definition shared by the drafting prompt and every tool that takes
     * steps, so a model is described the same shape wherever it meets it.
     *
     * @return array<string,mixed>
     */
    public static function schema(): array
    {
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
                        'enum'        => array_keys(self::types()),
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
                ],
                'required'   => ['section', 'label', 'help', 'type', 'required', 'options'],
            ],
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
     * @param array<int,mixed> $raw
     * @param string[]         $notes
     * @return array<int,array<string,mixed>>
     */
    public static function validate(array $raw, array &$notes): array
    {
        $allowed = array_keys(self::types());
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

            $steps[] = [
                'label'    => mb_substr($label, 0, 250),
                'help'     => mb_substr(trim((string) ($item['help'] ?? '')), 0, 1000),
                'section'  => mb_substr(trim((string) ($item['section'] ?? '')), 0, 250),
                'type'     => $type,
                'required' => self::yes($item['required'] ?? '') === true ? 1 : 0,
                'options'  => $options,
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

        return $steps;
    }

    /**
     * Write validated steps onto an SOP, creating sections as they are named.
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
     * @param array<int,array<string,mixed>> $steps as returned by validate()
     * @return int[] the ids written, in order
     */
    public static function write(int $sops_id, array $steps): array
    {
        $sections = self::sectionsOf($sops_id);
        $written  = [];

        foreach ($steps as $step) {
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

            $config = StepType::isChoice((string) $step['type'])
                ? ['options' => $step['options']]
                : [];

            $row = new Step();
            $id  = (int) $row->add([
                'plugin_glpisop_sops_id'     => $sops_id,
                'plugin_glpisop_sections_id' => $sections_id,
                'label'                      => $step['label'],
                'help'                       => $step['help'],
                'step_type'                  => $step['type'],
                'config_json'                => json_encode($config),
                'is_required'                => (int) $step['required'],
                'is_active'                  => 1,
                'rank_order'                 => Step::nextRank($sops_id, $sections_id),
                'depends_mode'               => Condition::MODE_ALL,
            ]);

            if ($id > 0) {
                $written[] = $id;
            }
        }

        return $written;
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
