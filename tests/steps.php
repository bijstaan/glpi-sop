<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * What a model asked for, against what the plugin can store.
 *
 * {@see GlpiPlugin\Glpisop\StepWriter::validate()} is where a written procedure
 * is decided, and it is the one part of the writing path that touches no
 * database: it takes the steps a model proposed and works out which of them are
 * real, and which of the branches between them can ever open. That makes it
 * both the piece most worth testing and the piece that can be tested with
 * nothing but PHP — the stubs below stand in for the three GLPI classes the
 * sources name, and nothing here reaches a connection.
 *
 * The failure this is chiefly about is a branch that saves cleanly and never
 * opens. A gate waiting for "Laptop" on a step whose option reads "laptop", or
 * for "Yes" on a checkbox that has no yes, is a valid row in the conditions
 * table and a step nobody is ever asked. In a run it is indistinguishable from
 * a procedure that simply has no branch there — so it is caught here, and the
 * step is asked unconditionally instead, which costs a spare question rather
 * than a missing one.
 *
 * The second is quieter. Steps are dropped during validation, so a gate that
 * named a step by position would re-point itself at whatever moved into that
 * position — a procedure that looks right and asks the wrong things. Gates name
 * refs; the check that a ref pointing at a dropped step is dropped rather than
 * resolved is the one that holds that property.
 *
 * Writing the rows needs GLPI. Usage, from the plugin directory:
 *   php tests/steps.php
 */

namespace {
    if (!function_exists('__')) {
        function __(string $text, string $domain = 'glpi'): string
        {
            return $text;
        }
    }

    if (!function_exists('_n')) {
        function _n(string $one, string $many, int $nb, string $domain = 'glpi'): string
        {
            return $nb === 1 ? $one : $many;
        }
    }

    // The plugin's models extend these. Nothing here calls into them: the
    // validation path is static and reads its arguments.
    if (!class_exists('CommonDBTM')) {
        class CommonDBTM
        {
        }
    }

    if (!class_exists('CommonDBChild')) {
        class CommonDBChild extends CommonDBTM
        {
        }
    }

    require __DIR__ . '/../src/StepType.php';
    require __DIR__ . '/../src/Step.php';
    require __DIR__ . '/../src/Trigger.php';
    require __DIR__ . '/../src/Condition.php';
    require __DIR__ . '/../src/GateWriter.php';
    require __DIR__ . '/../src/StepWriter.php';

    use GlpiPlugin\Glpisop\Condition;
    use GlpiPlugin\Glpisop\GateWriter;
    use GlpiPlugin\Glpisop\Step;
    use GlpiPlugin\Glpisop\Trigger;
    use GlpiPlugin\Glpisop\StepType;
    use GlpiPlugin\Glpisop\StepWriter;

    /** @var string[] $failures */
    $failures = [];

    function check(string $name, bool $ok, string $detail = ''): void
    {
        global $failures;
        echo ($ok ? "  \033[32mPASS\033[0m  " : "  \033[31mFAIL\033[0m  ") . $name
           . ($detail !== '' ? " :: $detail" : '') . "\n";
        if (!$ok) {
            $failures[] = $name;
        }
    }

    /**
     * One proposed step, with the schema's defaults filled in.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    function step(string $label, array $overrides = []): array
    {
        return $overrides + [
            'section'       => '',
            'ref'           => '',
            'label'         => $label,
            'help'          => '',
            'type'          => StepType::CHECK,
            'required'      => 'no',
            'options'       => [],
            'config'        => [],
            'ask_when'      => [],
            'ask_when_mode' => 'all',
        ];
    }

    /** The step of a validated list with this label. @return array<string,mixed> */
    function labelled(array $steps, string $label): array
    {
        foreach ($steps as $candidate) {
            if ($candidate['label'] === $label) {
                return $candidate;
            }
        }

        return [];
    }

    /** Did anything said about this draft mention it? */
    function mentions(array $notes, string $fragment): bool
    {
        foreach ($notes as $note) {
            if (str_contains($note, $fragment)) {
                return true;
            }
        }

        return false;
    }

    echo "\nSteps and types\n";

    $notes = [];
    $steps = StepWriter::validate([
        step('Check the mailbox', ['type' => StepType::CHECK]),
        step('', ['type' => StepType::CHECK]),
        step('Record the hostname', ['type' => 'telepathy']),
        step('Pick one', ['type' => StepType::CHOICE, 'options' => ['Only one']]),
    ], $notes);

    check('a step with no label is dropped', count($steps) === 2);
    check('a type this build does not have is dropped',
        labelled($steps, 'Record the hostname') === []);
    check('the drop is reported', mentions($notes, 'could not be used'));
    check('a choice with one option becomes a text step',
        labelled($steps, 'Pick one')['type'] === StepType::TEXT);

    echo "\nBranches that can hold\n";

    $notes = [];
    $steps = StepWriter::validate([
        step('Does this person have a shared mailbox?', [
            'ref'  => 'q1',
            'type' => StepType::YESNO,
        ]),
        step('Hand the shared mailbox to their manager', [
            'ask_when' => [['subject' => 'step:q1', 'operator' => Step::OP_EQ, 'value' => 'Yes']],
        ]),
        step('Nothing to hand over — carry on', [
            'ask_when'      => [['subject' => 'step:q1', 'operator' => Step::OP_EQ, 'value' => 'no']],
            'ask_when_mode' => 'any',
        ]),
    ], $notes);

    $yes = labelled($steps, 'Hand the shared mailbox to their manager');
    $no  = labelled($steps, 'Nothing to hand over — carry on');

    check('a gate resolves to the step above it',
        count($yes['conditions']) === 1 && $yes['conditions'][0]['index'] === 0);
    check('"Yes" is stored as the token the answer holds',
        $yes['conditions'][0]['value'] === StepType::YES,
        var_export($yes['conditions'][0]['value'] ?? null, true));
    check('"no" is stored as the token too', $no['conditions'][0]['value'] === StepType::NO);
    check('a gate carries the operator it was given',
        $yes['conditions'][0]['op'] === Step::OP_EQ);
    check('the join mode is kept where it was given', $no['mode'] === Condition::MODE_ANY);
    check('the join mode defaults to all', $yes['mode'] === Condition::MODE_ALL);
    check('nothing was said about a gate that holds', $notes === [], implode('; ', $notes));

    $notes = [];
    $steps = StepWriter::validate([
        step('What kind of device is it?', [
            'ref'     => 'kind',
            'type'    => StepType::CHOICE,
            'options' => ['Laptop', 'Desktop', 'Phone'],
        ]),
        step('Collect the laptop and its charger', [
            'ask_when' => [['subject' => 'step:KIND', 'operator' => Step::OP_EQ, 'value' => 'laptop']],
        ]),
    ], $notes);

    $collect = labelled($steps, 'Collect the laptop and its charger');
    check('a ref matches whatever case it was written in',
        count($collect['conditions']) === 1);
    check('a choice value is fitted to the option as the step spells it',
        ($collect['conditions'][0]['value'] ?? '') === 'Laptop',
        var_export($collect['conditions'][0]['value'] ?? null, true));

    $notes = [];
    $steps = StepWriter::validate([
        step('Was the machine returned?', ['ref' => 'r', 'type' => StepType::YESNO]),
        step('Note what the user said', [
            'ask_when' => [['subject' => 'step:r', 'operator' => Step::OP_CHECKED, 'value' => 'yes']],
        ]),
    ], $notes);

    check('an operator that takes no value is stored without one',
        labelled($steps, 'Note what the user said')['conditions'][0]['value'] === '');

    echo "\nBranches that could not\n";

    $notes = [];
    $steps = StepWriter::validate([
        step('What kind of device is it?', [
            'ref'     => 'kind',
            'type'    => StepType::CHOICE,
            'options' => ['Laptop', 'Desktop'],
        ]),
        step('Wipe the tablet', [
            'ask_when' => [['subject' => 'step:kind', 'operator' => Step::OP_EQ, 'value' => 'Tablet']],
        ]),
    ], $notes);

    check('a gate waiting on an option that does not exist is dropped',
        labelled($steps, 'Wipe the tablet')['conditions'] === []);
    check('and the step survives it, asked every time',
        labelled($steps, 'Wipe the tablet') !== []);
    check('and it is reported', mentions($notes, 'not one of its options'));

    $notes = [];
    $steps = StepWriter::validate([
        step('Disable the account', ['ref' => 'c', 'type' => StepType::CHECK]),
        step('Tell their manager', [
            'ask_when' => [['subject' => 'step:c', 'operator' => Step::OP_EQ, 'value' => 'yes']],
        ]),
    ], $notes);

    check('a checkbox compared against a value is dropped',
        labelled($steps, 'Tell their manager')['conditions'] === []);
    check('and says to use checked instead', mentions($notes, 'checked or unchecked'));

    $notes = [];
    $steps = StepWriter::validate([
        step('Which region?', ['ref' => 'r', 'type' => StepType::TEXT]),
        step('Escalate', [
            'ask_when' => [['subject' => 'step:r', 'operator' => Step::OP_GT, 'value' => '10']],
        ]),
    ], $notes);

    check('greater-than on a step that does not answer with a number is dropped',
        labelled($steps, 'Escalate')['conditions'] === []);
    check('and it is reported', mentions($notes, 'as a number'));

    $notes = [];
    $steps = StepWriter::validate([
        step('How many mailboxes?', ['ref' => 'n', 'type' => StepType::NUMBER]),
        step('Ask Finance about the licences', [
            'ask_when' => [['subject' => 'step:n', 'operator' => Step::OP_GT, 'value' => '5']],
        ]),
    ], $notes);

    check('greater-than on a number step holds',
        labelled($steps, 'Ask Finance about the licences')['conditions'][0]['value'] === '5');

    $notes = [];
    $steps = StepWriter::validate([
        step('Is it urgent?', ['ref' => 'u', 'type' => StepType::YESNO]),
        step('Do the urgent thing', [
            'ask_when' => [['subject' => 'step:u', 'operator' => Step::OP_EQ, 'value' => 'maybe']],
        ]),
        step('Do the other thing', [
            'ask_when' => [['subject' => 'step:u', 'operator' => Step::OP_EQ, 'value' => '']],
        ]),
        step('Do a third thing', [
            'ask_when' => [['subject' => 'step:u', 'operator' => 'roughly', 'value' => 'yes']],
        ]),
    ], $notes);

    check('a yes/no gate waiting on neither yes nor no is dropped',
        labelled($steps, 'Do the urgent thing')['conditions'] === []);
    check('a gate comparing against nothing is dropped',
        labelled($steps, 'Do the other thing')['conditions'] === []);
    check('an operator this build does not have is dropped',
        labelled($steps, 'Do a third thing')['conditions'] === []);

    $notes = [];
    $steps = StepWriter::validate([
        step('What did the error say?', ['ref' => 'e', 'type' => StepType::TEXTAREA]),
        step('Escalate', [
            'ask_when' => [[
                'subject'  => 'step:e',
                'operator' => Step::OP_EQ,
                'value'    => str_repeat('a', 256),
            ]],
        ]),
    ], $notes);

    check('a value longer than the column can hold is dropped rather than truncated',
        labelled($steps, 'Escalate')['conditions'] === []);
    check('and it is reported', mentions($notes, 'more text than a condition can hold'));

    echo "\nRefs, and what they protect\n";

    $notes = [];
    $steps = StepWriter::validate([
        step('Read the ticket', ['ref' => 'a']),
        step('Act on the answer', [
            'ask_when' => [['subject' => 'step:b', 'operator' => Step::OP_CHECKED, 'value' => '']],
        ]),
    ], $notes);

    check('a gate on a step that was never written is dropped',
        labelled($steps, 'Act on the answer')['conditions'] === []);
    check('and names what it was looking for', mentions($notes, '"b"'));

    $notes = [];
    $steps = StepWriter::validate([
        step('Branch first', [
            'ask_when' => [['subject' => 'step:later', 'operator' => Step::OP_CHECKED, 'value' => '']],
        ]),
        step('Decide second', ['ref' => 'later', 'type' => StepType::YESNO]),
    ], $notes);

    check('a gate pointing down the procedure is dropped',
        labelled($steps, 'Branch first')['conditions'] === []);
    check('and it is reported', mentions($notes, 'comes after it'));

    // The property that makes refs worth the tokens: the dropped step shifts
    // every position below it, and a gate written against a position would
    // silently land on the wrong question.
    $notes = [];
    $steps = StepWriter::validate([
        step('Unusable', ['type' => 'telepathy', 'ref' => 'gone']),
        step('Is the mailbox shared?', ['ref' => 'shared', 'type' => StepType::YESNO]),
        step('Hand it over', [
            'ask_when' => [['subject' => 'step:gone', 'operator' => Step::OP_CHECKED, 'value' => '']],
        ]),
    ], $notes);

    check('a gate on a step that validation dropped is dropped, not re-pointed',
        labelled($steps, 'Hand it over')['conditions'] === []);

    $notes = [];
    $steps = StepWriter::validate([
        step('First question', ['ref' => 'q', 'type' => StepType::YESNO]),
        step('Second question', ['ref' => 'q', 'type' => StepType::YESNO]),
        step('Branch', [
            'ask_when' => [['subject' => 'step:q', 'operator' => Step::OP_EQ, 'value' => 'yes']],
        ]),
    ], $notes);

    check('a repeated ref is read as the first step that claimed it',
        labelled($steps, 'Branch')['conditions'][0]['index'] === 0);
    check('and the clash is reported', mentions($notes, 'Two steps were given the id'));

    echo "\nSteps that already exist\n";

    $notes = [];
    $steps = StepWriter::validate([
        step('Hand the mailbox over', [
            'ask_when' => [['subject' => 'step:412', 'operator' => Step::OP_EQ, 'value' => 'Yes']],
        ]),
    ], $notes);

    $clause = labelled($steps, 'Hand the mailbox over')['conditions'][0] ?? [];
    check('a numeric target is carried through for the writer to check',
        ($clause['steps_id'] ?? 0) === 412 && ($clause['index'] ?? 0) === -1);
    check('its value is left as written, since the step it names is not here',
        ($clause['value'] ?? '') === 'Yes');
    check('nothing is claimed about it yet', $notes === [], implode('; ', $notes));

    echo "\nCeilings\n";

    $notes = [];
    $many  = [step('Decide', ['ref' => 'd', 'type' => StepType::YESNO])];
    for ($i = 0; $i < StepWriter::MAX_STEPS + 5; $i++) {
        $many[] = step('Step ' . $i);
    }
    $steps = StepWriter::validate($many, $notes);
    check('a procedure is capped at MAX_STEPS', count($steps) === StepWriter::MAX_STEPS);
    check('and the cap is reported', mentions($notes, 'were kept'));

    $notes   = [];
    $clauses = [];
    for ($i = 0; $i < GateWriter::MAX_CONDITIONS + 3; $i++) {
        $clauses[] = ['subject' => 'step:d', 'operator' => Step::OP_EQ, 'value' => 'yes'];
    }
    $steps = StepWriter::validate([
        step('Decide', ['ref' => 'd', 'type' => StepType::YESNO]),
        step('Branch', ['ask_when' => $clauses]),
    ], $notes);

    check('a gate is capped at MAX_CONDITIONS',
        count(labelled($steps, 'Branch')['conditions']) === GateWriter::MAX_CONDITIONS);
    check('and that cap is reported too', mentions($notes, 'conditions; the first'));

    echo "\nGates on the ticket itself\n";

    $notes = [];
    $steps = StepWriter::validate([
        step('Collect the laptop', [
            'ask_when' => [[
                'subject'  => 'field:itilcategories_id',
                'operator' => 'under',
                'value'    => 'Hardware',
            ]],
        ]),
    ], $notes);

    $clause = labelled($steps, 'Collect the laptop')['conditions'][0] ?? [];
    check('a field clause is planned against the criterion it names',
        ($clause['source'] ?? '') === Condition::SRC_FIELD
        && ($clause['target'] ?? '') === 'itilcategories_id');
    check('and its value is left as a name for the writer to resolve',
        ($clause['value'] ?? '') === 'Hardware');
    check('nothing was said about a field clause that holds', $notes === [], implode('; ', $notes));

    $notes = [];
    $steps = StepWriter::validate([
        step('One', ['ask_when' => [[
            'subject'  => 'field:itilcategories_id',
            'operator' => 'contains',
            'value'    => 'Hardware',
        ]]]),
        step('Two', ['ask_when' => [[
            'subject'  => 'field:name',
            'operator' => 'contains',
            'value'    => 'vpn',
        ]]]),
        step('Three', ['ask_when' => [[
            'subject'  => 'field:sunspots',
            'operator' => 'is',
            'value'    => 'many',
        ]]]),
        step('Four', ['ask_when' => [[
            'subject'  => 'weather:today',
            'operator' => 'is',
            'value'    => 'rain',
        ]]]),
        step('Five', ['ask_when' => [[
            'subject'  => 'field:urgency',
            'operator' => 'is',
            'value'    => '',
        ]]]),
    ], $notes);

    check('an operator that does not belong to the criterion is dropped',
        labelled($steps, 'One')['conditions'] === []);
    check('a text criterion takes contains', count(labelled($steps, 'Two')['conditions']) === 1);
    check('a criterion this version does not have is dropped',
        labelled($steps, 'Three')['conditions'] === []);
    check('a source this version does not have is dropped',
        labelled($steps, 'Four')['conditions'] === []);
    check('a field clause comparing against nothing is dropped',
        labelled($steps, 'Five')['conditions'] === []);

    $notes = [];
    $steps = StepWriter::validate([
        step('Hand over the laptop', ['ask_when' => [[
            'subject'  => 'approval:global_validation',
            'operator' => 'is',
            'value'    => 'Granted',
        ]]]),
        step('Chase the approval', ['ask_when' => [[
            'subject'  => 'approval:approved_by_group',
            'operator' => 'regex',
            'value'    => 'Management',
        ]]]),
    ], $notes);

    check('an approval clause is planned',
        (labelled($steps, 'Hand over the laptop')['conditions'][0]['source'] ?? '')
            === Condition::SRC_APPROVAL);
    check('an approval clause takes is and is_not and nothing else',
        labelled($steps, 'Chase the approval')['conditions'] === []);

    $notes = [];
    $steps = StepWriter::validate([
        step('Is it shared?', ['ref' => 'q1', 'type' => StepType::YESNO]),
        step('Hand it over', ['ask_when' => [[
            'subject'  => 'q1',
            'operator' => Step::OP_EQ,
            'value'    => 'yes',
        ]]]),
    ], $notes);

    check('a bare ref is read as a step, since a ref cannot hold a colon',
        (labelled($steps, 'Hand it over')['conditions'][0]['index'] ?? -1) === 0);

    $notes = [];
    $steps = StepWriter::validate([
        step('Decide', ['ref' => 'a:b', 'type' => StepType::YESNO]),
        step('Branch', ['ask_when' => [[
            'subject'  => 'step:ab',
            'operator' => Step::OP_EQ,
            'value'    => 'yes',
        ]]]),
    ], $notes);

    check('a colon in a ref is taken out rather than splitting the subject',
        count(labelled($steps, 'Branch')['conditions']) === 1);

    echo "\nTypes, and who may write them\n";

    check('every writable type is a type the plugin has',
        array_diff(array_keys(StepWriter::types()), array_keys(StepType::all())) === []);
    check('every type the plugin has is writable',
        array_diff(array_keys(StepType::all()), array_keys(StepWriter::types())) === [],
        implode(',', array_diff(array_keys(StepType::all()), array_keys(StepWriter::types()))));

    $drafted = array_keys(StepWriter::draftable());
    check('drafting may not invent an approval or a linked ticket',
        !in_array(StepType::APPROVAL, $drafted, true)
        && !in_array(StepType::TICKET, $drafted, true)
        && !in_array(StepType::DOCUMENT, $drafted, true)
        && !in_array(StepType::DATETIME, $drafted, true));
    check('and everything else is drafted as before', count($drafted) === 10);

    $notes = [];
    $steps = StepWriter::validate(
        [step('Manager approves the hire', ['type' => StepType::APPROVAL])],
        $notes,
        StepWriter::draftable()
    );
    check('a type outside the caller\'s list is dropped', $steps === []);

    $notes = [];
    $steps = StepWriter::validate(
        [step('Manager approves the hire', ['type' => StepType::APPROVAL])],
        $notes
    );
    check('and kept when the caller allows it', count($steps) === 1);

    echo "\nSettings\n";

    $config = StepWriter::pairs([
        ['key' => 'Min', 'value' => '1'],
        ['key' => 'max', 'value' => '10'],
        ['key' => 'max', 'value' => '12'],
        ['key' => '', 'value' => 'ignored'],
        'not a pair',
    ]);

    check('settings fold into a map, case-folded', ($config['min'] ?? '') === '1');
    check('the last value for a key wins', ($config['max'] ?? '') === '12');
    check('a pair with no key is left out', count($config) === 2);

    $settings = StepWriter::settings();
    $known    = [];
    foreach ($settings as $type => $keys) {
        check('settings are declared for a real type: ' . $type, StepType::exists($type));
        foreach ($keys as $name => $setting) {
            $known[$name] = true;
            check('the setting ' . $name . ' names a config key', ($setting['key'] ?? '') !== '');
        }
    }

    // The guide in the schema is what a model reads to know these exist. A
    // setting missing from it is a setting nobody can ask for.
    $guide = StepWriter::settingGuide();
    $missing = [];
    foreach (array_keys($known) as $name) {
        if (!str_contains($guide, $name)) {
            $missing[] = $name;
        }
    }
    check('every setting is named in the guide the model reads',
        $missing === [], implode(',', $missing));

    echo "\nThe schema the model is given\n";

    $schema = StepWriter::schema();
    $item   = $schema['items'];

    check('every property of a step is required',
        array_keys($item['properties']) === $item['required'],
        implode(',', array_diff(array_keys($item['properties']), $item['required'])));
    check('a clause is three plain strings',
        $item['properties']['ask_when']['items']['required'] === ['subject', 'operator', 'value']);

    // Every operator the enum offers must be one some subject actually accepts.
    // One the model can ask for and nothing evaluates reads as *satisfied* in
    // Condition, which opens a branch unconditionally.
    $offered  = $item['properties']['ask_when']['items']['properties']['operator']['enum'];
    $evaluate = array_keys(Step::operators());
    foreach (array_keys(Trigger::criteria()) as $criterion) {
        $evaluate = array_merge($evaluate, array_keys(Trigger::conditionsFor($criterion)));
    }
    check('every operator offered is one some subject accepts',
        array_diff($offered, $evaluate) === [],
        implode(',', array_diff($offered, $evaluate)));
    check('every answer operator is offered',
        array_diff(array_keys(Step::operators()), $offered) === [],
        implode(',', array_diff(array_keys(Step::operators()), $offered)));
    check('the join modes offered are the ones a step can hold',
        $item['properties']['ask_when_mode']['enum']
            === [Condition::MODE_ALL, Condition::MODE_ANY]);

    echo "\n" . ($failures === []
        ? "\033[32mall checks passed\033[0m\n"
        : "\033[31mFAILED\033[0m: " . implode('; ', $failures) . "\n");

    exit($failures === [] ? 0 : 1);
}
