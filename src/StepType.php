<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBTM;
use Document;
use Dropdown;
use Html;
use User;

/**
 * The catalogue of step types, and everything type-specific about a step.
 *
 * One registry rather than a class per type: a type is a small bundle of
 * decisions — how it renders, what a submitted value has to satisfy, what it
 * looks like written back out — and splitting eleven of those across eleven
 * files hides the fact that they are variations on one shape.
 *
 * Validation lives here and runs in the endpoint. The browser's own `min`,
 * `max` and `required` attributes are a courtesy that stops an obvious mistake
 * before it costs a round trip; they are not a check. A step marked done is a
 * compliance claim, and the only copy of the rules worth trusting is the one
 * the client cannot reach.
 */
final class StepType
{
    public const CHECK       = 'check';
    public const TEXT        = 'text';
    public const TEXTAREA    = 'textarea';
    public const NUMBER      = 'number';
    public const CHOICE      = 'choice';
    public const MULTICHOICE = 'multichoice';
    public const DATE        = 'date';
    public const DATETIME    = 'datetime';
    public const USER        = 'user';
    public const ASSET       = 'asset';
    public const DOCUMENT    = 'document';
    /**
     * An explicit Yes or No.
     *
     * Distinct from CHECK, and the distinction is the reason it exists. A
     * checkbox has two states — ticked and untouched — so "we do not have a
     * licence" is not something it can say: the only operator that reaches an
     * unticked box is `unchecked`, which means *unanswered* and is therefore
     * true from the moment the run starts. A procedure that branches on a
     * negative answer needs a control that can hold one, or its remediation
     * branch is open before anybody has looked.
     */
    public const YESNO       = 'yesno';
    /**
     * A step that raises a linked ticket. See {@see ChildTicket}.
     */
    public const TICKET      = 'ticket';
    /**
     * A step answered by the item's own approval record. See {@see Approval}.
     *
     * The step nobody can tick. "Manager has approved the hire" as a checkbox
     * is a technician asserting somebody else's decision — the tick is evidence
     * of nothing, and it is exactly the step that gets ticked to get on with
     * the day. This one is satisfied when GLPI says the approval is in the
     * required state, and goes back to outstanding if that changes.
     */
    public const APPROVAL    = 'approval';

    /** The canonical answers of a YESNO step, and what they read as. */
    public const YES = 'yes';
    public const NO  = 'no';

    /** Human labels, in the order they are offered to an author. */
    public static function all(): array
    {
        return [
            self::CHECK       => __('Checkbox — done or not done', 'glpisop'),
            self::YESNO       => __('Yes / No — an answer either way', 'glpisop'),
            self::TEXT        => __('Short text', 'glpisop'),
            self::TEXTAREA    => __('Long text', 'glpisop'),
            self::NUMBER      => __('Number', 'glpisop'),
            self::CHOICE      => __('Single choice', 'glpisop'),
            self::MULTICHOICE => __('Multiple choice', 'glpisop'),
            self::DATE        => __('Date', 'glpisop'),
            self::DATETIME    => __('Date and time', 'glpisop'),
            self::USER        => __('User', 'glpisop'),
            self::ASSET       => __('Asset', 'glpisop'),
            self::DOCUMENT    => __('Document (upload)', 'glpisop'),
            self::TICKET      => __('Raise a linked ticket', 'glpisop'),
            self::APPROVAL    => __('Approval on this item', 'glpisop'),
        ];
    }

    public static function label(string $type): string
    {
        return self::all()[$type] ?? $type;
    }

    public static function exists(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    /** Types whose answer is a value rather than merely a tick. */
    public static function hasValue(string $type): bool
    {
        return $type !== self::CHECK;
    }

    /** Types that offer a fixed option list, and can therefore gate branches. */
    public static function isChoice(string $type): bool
    {
        return $type === self::CHOICE || $type === self::MULTICHOICE;
    }

    /**
     * The stored answers of a Yes/No step, keyed by what goes in the database.
     *
     * The token is stored, not the word. A branch written against the visible
     * label would stop matching the moment the instance was read in another
     * language, and "the procedure works in English" is not a property anybody
     * would have thought to test.
     *
     * @return array<string,string>
     */
    public static function yesNoLabels(): array
    {
        return [
            self::YES => __('Yes'),
            self::NO  => __('No'),
        ];
    }

    /**
     * The fixed set of answers a step offers, for the condition editor's value
     * picker.
     *
     * Returning the options rather than a "does it have options" flag is what
     * lets the editor offer the *stored* value against the *displayed* label —
     * a distinction that only exists for Yes/No, and one a boolean could not
     * carry.
     *
     * @return array<string,string> stored value => label, empty when the type
     *                              has no fixed set
     */
    public static function branchOptions(array $step): array
    {
        $type = (string) ($step['step_type'] ?? '');

        if ($type === self::YESNO) {
            return self::yesNoLabels();
        }

        if (self::isChoice($type)) {
            $options = self::options(Step::config($step));

            return $options === [] ? [] : array_combine($options, $options);
        }

        return [];
    }

    /**
     * Options an authored choice step offers.
     *
     * @return string[]
     */
    public static function options(array $config): array
    {
        $out = [];
        foreach ((array) ($config['options'] ?? []) as $option) {
            $option = trim((string) $option);
            if ($option !== '') {
                $out[] = $option;
            }
        }

        return $out;
    }

    /**
     * Asset itemtypes an asset step will accept, defaulting to whatever GLPI
     * considers linkable to a ticket.
     *
     * @return string[]
     */
    public static function assetItemtypes(array $config): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $configured = [];
        foreach ((array) ($config['itemtypes'] ?? []) as $itemtype) {
            $itemtype = trim((string) $itemtype);
            if ($itemtype !== '' && class_exists($itemtype)) {
                $configured[] = $itemtype;
            }
        }

        if ($configured !== []) {
            return $configured;
        }

        $fallback = [];
        foreach ((array) ($CFG_GLPI['ticket_types'] ?? ['Computer']) as $itemtype) {
            if (class_exists($itemtype)) {
                $fallback[] = $itemtype;
            }
        }

        return $fallback !== [] ? $fallback : ['Computer'];
    }

    /**
     * Turn a submitted answer into what goes in the row.
     *
     * Returns the answer columns on success, or an `error` describing what is
     * wrong with it. The caller never writes a partially valid answer: a step
     * either holds a value that satisfies its own definition or holds nothing.
     *
     * @param array<string,mixed> $post
     * @return array{error?:string, value:?string, value_itemtype:?string, value_items_id:int, documents_id:int}
     */
    public static function normalize(string $type, array $config, array $post): array
    {
        $blank = ['value' => null, 'value_itemtype' => null, 'value_items_id' => 0, 'documents_id' => 0];
        $raw   = $post['value'] ?? null;

        switch ($type) {
            case self::CHECK:
                return $blank;

            case self::YESNO:
                $picked = (string) $raw;
                if (!array_key_exists($picked, self::yesNoLabels())) {
                    return ['error' => __('Answer Yes or No.', 'glpisop')] + $blank;
                }
                return ['value' => $picked] + $blank;

            case self::TICKET:
                // Answered by raising the ticket, never by a posted value. A
                // step that could also be ticked by hand would let somebody
                // claim the licence was ordered without ordering it.
                return ['error' => __('Use the button to raise the ticket for this step.', 'glpisop')] + $blank;

            case self::APPROVAL:
                // Answered by the approval record and nothing else. This is the
                // whole point of the type — see the constant.
                return [
                    'error' => __('This step is answered by the item’s approval, not by hand.', 'glpisop'),
                ] + $blank;

            case self::TEXT:
            case self::TEXTAREA:
                $text = trim((string) $raw);
                if ($text === '') {
                    return ['error' => __('This step needs an answer.', 'glpisop')] + $blank;
                }
                $pattern = trim((string) ($config['pattern'] ?? ''));
                if ($pattern !== '' && @preg_match('/' . str_replace('/', '\/', $pattern) . '/', $text) !== 1) {
                    return [
                        'error' => sprintf(
                            __('The answer does not match the expected format (%s).', 'glpisop'),
                            $pattern
                        ),
                    ] + $blank;
                }
                // Long text is stored as-is and escaped at render time; short
                // text is additionally capped so a label-sized field cannot be
                // used to park a megabyte on a ticket.
                if ($type === self::TEXT && mb_strlen($text) > 255) {
                    $text = mb_substr($text, 0, 255);
                }
                return ['value' => $text] + $blank;

            case self::NUMBER:
                if (!is_numeric((string) $raw)) {
                    return ['error' => __('This step needs a number.', 'glpisop')] + $blank;
                }
                $number = (float) $raw;
                if (isset($config['min']) && $config['min'] !== '' && $number < (float) $config['min']) {
                    return [
                        'error' => sprintf(__('Must be at least %s.', 'glpisop'), (string) $config['min']),
                    ] + $blank;
                }
                if (isset($config['max']) && $config['max'] !== '' && $number > (float) $config['max']) {
                    return [
                        'error' => sprintf(__('Must be at most %s.', 'glpisop'), (string) $config['max']),
                    ] + $blank;
                }
                // Kept as the submitted string so "3.50" does not silently
                // become "3.5" in a reading a technician has to defend.
                return ['value' => (string) $raw] + $blank;

            case self::CHOICE:
                $options = self::options($config);
                $picked  = (string) $raw;
                if (!in_array($picked, $options, true)) {
                    return ['error' => __('Pick one of the offered options.', 'glpisop')] + $blank;
                }
                return ['value' => $picked] + $blank;

            case self::MULTICHOICE:
                $options = self::options($config);
                $picked  = [];
                foreach ((array) $raw as $one) {
                    $one = (string) $one;
                    if (in_array($one, $options, true) && !in_array($one, $picked, true)) {
                        $picked[] = $one;
                    }
                }
                if ($picked === []) {
                    return ['error' => __('Pick at least one option.', 'glpisop')] + $blank;
                }
                return ['value' => json_encode($picked, JSON_UNESCAPED_UNICODE)] + $blank;

            case self::DATE:
            case self::DATETIME:
                $format    = $type === self::DATE ? 'Y-m-d' : 'Y-m-d H:i:s';
                $submitted = trim((string) $raw);
                // Accept the browser's datetime-local shape as well as GLPI's.
                $submitted = str_replace('T', ' ', $submitted);
                if ($type === self::DATETIME && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $submitted)) {
                    $submitted .= ':00';
                }
                $stamp = $submitted !== '' ? strtotime($submitted) : false;
                if ($stamp === false) {
                    return ['error' => __('This step needs a valid date.', 'glpisop')] + $blank;
                }
                return ['value' => date($format, $stamp)] + $blank;

            case self::USER:
                $users_id = (int) $raw;
                if ($users_id <= 0) {
                    return ['error' => __('Pick a user.', 'glpisop')] + $blank;
                }
                $user = new User();
                if (!$user->getFromDB($users_id)) {
                    return ['error' => __('That user does not exist.', 'glpisop')] + $blank;
                }
                return ['value' => null, 'value_itemtype' => User::class, 'value_items_id' => $users_id,
                    'documents_id' => 0,
                ];

            case self::ASSET:
                $itemtype = (string) ($post['value_itemtype'] ?? '');
                $items_id = (int) ($post['value_items_id'] ?? 0);
                if (!in_array($itemtype, self::assetItemtypes($config), true)) {
                    return ['error' => __('Pick an asset of an allowed type.', 'glpisop')] + $blank;
                }
                /** @var CommonDBTM $asset */
                $asset = new $itemtype();
                // Re-read rather than trust the id: the picker is client-side,
                // and an answer must not become a way to confirm that asset
                // #1234 exists in an entity the technician cannot see.
                if ($items_id <= 0 || !$asset->getFromDB($items_id) || !$asset->canViewItem()) {
                    return ['error' => __('That asset does not exist, or you cannot see it.', 'glpisop')] + $blank;
                }
                return ['value' => null, 'value_itemtype' => $itemtype, 'value_items_id' => $items_id,
                    'documents_id' => 0,
                ];

            case self::DOCUMENT:
                $documents_id = (int) ($post['documents_id'] ?? 0);
                $document     = new Document();
                if ($documents_id <= 0 || !$document->getFromDB($documents_id)) {
                    return ['error' => __('Attach a file for this step.', 'glpisop')] + $blank;
                }
                return ['value' => null, 'value_itemtype' => null, 'value_items_id' => 0,
                    'documents_id' => $documents_id,
                ];
        }

        return ['error' => __('Unknown step type.', 'glpisop')] + $blank;
    }

    /**
     * The answer as a plain string, for followups, exports and the run log.
     *
     * Never returns markup: callers escape, and a formatter that pre-escapes
     * makes double-escaped values inevitable somewhere downstream.
     */
    public static function format(string $type, array $answer): string
    {
        switch ($type) {
            case self::CHECK:
                return (string) $answer['state'] === Answer::DONE ? __('done', 'glpisop') : '';

            case self::YESNO:
                return (string) (self::yesNoLabels()[(string) ($answer['value'] ?? '')] ?? '');

            case self::TICKET:
                $tickets_id = (int) ($answer['value_items_id'] ?? 0);
                return $tickets_id > 0
                    ? sprintf(__('ticket #%d', 'glpisop'), $tickets_id)
                    : '';

            case self::APPROVAL:
                $status = (int) ($answer['value'] ?? 0);
                return $status > 0 ? Approval::statusLabel($status) : '';

            case self::MULTICHOICE:
                $decoded = json_decode((string) ($answer['value'] ?? ''), true);
                return is_array($decoded) ? implode(', ', $decoded) : (string) ($answer['value'] ?? '');

            case self::USER:
                $user = new User();
                return $user->getFromDB((int) $answer['value_items_id'])
                    ? $user->getFriendlyName()
                    : '';

            case self::ASSET:
                $itemtype = (string) ($answer['value_itemtype'] ?? '');
                if ($itemtype === '' || !class_exists($itemtype)) {
                    return '';
                }
                /** @var CommonDBTM $asset */
                $asset = new $itemtype();
                return $asset->getFromDB((int) $answer['value_items_id'])
                    ? $itemtype::getTypeName(1) . ' — ' . $asset->getFriendlyName()
                    : '';

            case self::DOCUMENT:
                $document = new Document();
                return $document->getFromDB((int) $answer['documents_id'])
                    ? (string) $document->fields['name']
                    : '';

            case self::DATETIME:
            case self::DATE:
                $value = (string) ($answer['value'] ?? '');
                return $value !== '' ? Html::convDateTime($value) : '';
        }

        return (string) ($answer['value'] ?? '');
    }

    /**
     * The comparable form of an answer, for branch conditions.
     *
     * Multichoice collapses to its picked list so `in` can ask whether one
     * option is among them; everything else is a single scalar.
     *
     * @return string[]
     */
    public static function comparable(string $type, array $answer): array
    {
        if ($type === self::MULTICHOICE) {
            $decoded = json_decode((string) ($answer['value'] ?? ''), true);
            return is_array($decoded) ? array_map('strval', $decoded) : [];
        }

        if ($type === self::USER || $type === self::ASSET || $type === self::TICKET) {
            return [(string) (int) ($answer['value_items_id'] ?? 0)];
        }

        if ($type === self::DOCUMENT) {
            return [(string) (int) ($answer['documents_id'] ?? 0)];
        }

        return [(string) ($answer['value'] ?? '')];
    }

    /**
     * Render the input for one step.
     *
     * The name attributes are irrelevant — nothing here is submitted as a form.
     * Every control carries `data-sop-step`, and public/js/sop.js posts each
     * change to the endpoint on its own. That is what makes the checklist
     * survive a technician closing the tab halfway through, which is the normal
     * way a long procedure gets interrupted.
     */
    public static function renderInput(array $step, array $answer, bool $editable): void
    {
        $type     = (string) $step['step_type'];
        $config   = Step::config($step);
        $steps_id = (int) $step['id'];
        $disabled = $editable ? '' : 'disabled';
        $e        = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $done     = (string) ($answer['state'] ?? Answer::PENDING) === Answer::DONE;

        switch ($type) {
            case self::CHECK:
                echo "<label class='form-check sop-check'>";
                echo "<input type='checkbox' class='form-check-input' data-sop-step='$steps_id' "
                   . ($done ? "checked='checked' " : '') . "$disabled>";
                echo "<span class='form-check-label'>" . __s('Done', 'glpisop') . '</span>';
                echo '</label>';
                return;

            case self::YESNO:
                // Radios rather than a select: the answer is the whole step,
                // and a two-option select hides which way it is set behind a
                // click. Both options carry their stored token, so a branch
                // written against "no" keeps matching in any language.
                $current = (string) ($answer['value'] ?? '');
                foreach (self::yesNoLabels() as $token => $label) {
                    echo "<label class='form-check form-check-inline'>";
                    echo "<input type='radio' class='form-check-input' data-sop-step='$steps_id' "
                       . "name='sop_step_$steps_id' value='" . $e($token) . "' "
                       . ($token === $current ? "checked='checked' " : '') . "$disabled>";
                    echo "<span class='form-check-label'>" . $e($label) . '</span>';
                    echo '</label>';
                }
                return;

            case self::TICKET:
                self::renderChildTicket($step, $answer, $editable);
                return;

            case self::APPROVAL:
                self::renderApproval($step, $answer);
                return;

            case self::TEXT:
                echo "<input type='text' class='form-control' data-sop-step='$steps_id' "
                   . "maxlength='255' placeholder='" . $e($config['placeholder'] ?? '') . "' "
                   . "value='" . $e($answer['value'] ?? '') . "' $disabled>";
                return;

            case self::TEXTAREA:
                echo "<textarea class='form-control' rows='3' data-sop-step='$steps_id' "
                   . "placeholder='" . $e($config['placeholder'] ?? '') . "' $disabled>"
                   . $e($answer['value'] ?? '') . '</textarea>';
                return;

            case self::NUMBER:
                $min  = isset($config['min']) && $config['min'] !== '' ? "min='" . $e($config['min']) . "'" : '';
                $max  = isset($config['max']) && $config['max'] !== '' ? "max='" . $e($config['max']) . "'" : '';
                $unit = trim((string) ($config['unit'] ?? ''));
                echo "<div class='input-group' style='max-width:260px'>";
                echo "<input type='number' step='any' class='form-control' data-sop-step='$steps_id' "
                   . "$min $max value='" . $e($answer['value'] ?? '') . "' $disabled>";
                if ($unit !== '') {
                    echo "<span class='input-group-text'>" . $e($unit) . '</span>';
                }
                echo '</div>';
                return;

            case self::CHOICE:
                $current = (string) ($answer['value'] ?? '');
                foreach (self::options($config) as $index => $option) {
                    echo "<label class='form-check form-check-inline'>";
                    echo "<input type='radio' class='form-check-input' data-sop-step='$steps_id' "
                       . "name='sop_step_$steps_id' value='" . $e($option) . "' "
                       . ($option === $current ? "checked='checked' " : '') . "$disabled>";
                    echo "<span class='form-check-label'>" . $e($option) . '</span>';
                    echo '</label>';
                }
                return;

            case self::MULTICHOICE:
                $decoded = json_decode((string) ($answer['value'] ?? ''), true);
                $current = is_array($decoded) ? $decoded : [];
                foreach (self::options($config) as $option) {
                    echo "<label class='form-check'>";
                    echo "<input type='checkbox' class='form-check-input' data-sop-step='$steps_id' "
                       . "data-sop-multi='1' value='" . $e($option) . "' "
                       . (in_array($option, $current, true) ? "checked='checked' " : '') . "$disabled>";
                    echo "<span class='form-check-label'>" . $e($option) . '</span>';
                    echo '</label>';
                }
                return;

            case self::DATE:
            case self::DATETIME:
                $input = $type === self::DATE ? 'date' : 'datetime-local';
                $value = (string) ($answer['value'] ?? '');
                if ($type === self::DATETIME && $value !== '') {
                    $value = str_replace(' ', 'T', substr($value, 0, 16));
                }
                echo "<input type='$input' class='form-control' style='max-width:260px' "
                   . "data-sop-step='$steps_id' value='" . $e($value) . "' $disabled>";
                return;

            case self::USER:
                // GLPI's own dropdown, so the entity restriction and the
                // right-to-see-this-user logic stay core's rather than ours.
                //
                // Bound by *name*, not by id: GLPI generates dropdown ids from
                // a random suffix, and the asset picker below regenerates its
                // second select over ajax with a fresh one. Names are the only
                // stable handle, so public/js/sop.js delegates on the enclosing
                // .sop-step container instead of addressing elements directly.
                User::dropdown([
                    'name'                => "sop_user_$steps_id",
                    'value'               => (int) ($answer['value_items_id'] ?? 0),
                    'right'               => 'all',
                    'entity'              => $_SESSION['glpiactiveentities'] ?? 0,
                    'display_emptychoice' => true,
                    'width'               => '100%',
                    'readonly'            => !$editable,
                ]);
                return;

            case self::ASSET:
                $itemtypes = self::assetItemtypes($config);
                $current   = (string) ($answer['value_itemtype'] ?? '');
                Dropdown::showSelectItemFromItemtypes([
                    'itemtype_name'    => "sop_asset_type_$steps_id",
                    'items_id_name'    => "sop_asset_id_$steps_id",
                    'itemtypes'        => $itemtypes,
                    'default_itemtype' => $current !== '' ? $current : ($itemtypes[0] ?? ''),
                    'default_items_id' => (int) ($answer['value_items_id'] ?? 0),
                    'entity_restrict'  => $_SESSION['glpiactiveentities'] ?? 0,
                    'checkright'       => true,
                    'width'            => '100%',
                ]);
                return;

            case self::DOCUMENT:
                $documents_id = (int) ($answer['documents_id'] ?? 0);
                if ($documents_id > 0) {
                    $document = new Document();
                    if ($document->getFromDB($documents_id)) {
                        echo "<div class='mb-2'>" . $document->getLink() . '</div>';
                    }
                }
                if ($editable) {
                    echo "<input type='file' class='form-control' style='max-width:420px' "
                       . "data-sop-upload='$steps_id'>";
                    echo "<div class='form-text'>"
                       . __s('The file is filed as a GLPI document and linked to this item.', 'glpisop')
                       . '</div>';
                }
                return;
        }
    }

    /**
     * The control for a `ticket` step: raise it, or look at the one already
     * raised.
     *
     * The button is the only way this step gets answered — see
     * {@see self::normalize()} — so once a ticket exists the button is gone
     * rather than disabled. A second "raise the ticket" affordance beside a
     * ticket that has been raised is an invitation to raise a duplicate.
     */
    private static function renderChildTicket(array $step, array $answer, bool $editable): void
    {
        $steps_id   = (int) $step['id'];
        $config     = Step::config($step);
        $tickets_id = (int) ($answer['value_items_id'] ?? 0);

        echo "<div class='sop-child-ticket'>";

        if ($tickets_id > 0) {
            // getLink() is core's markup and is already escaped.
            $described = ChildTicket::describe($tickets_id);
            echo $described !== ''
                ? "<div class='sop-child-ticket-link'>" . $described . '</div>'
                : "<div class='form-text'>"
                  . __s('The ticket this step raised has been deleted.', 'glpisop') . '</div>';

            if (
                ChildTicket::completionMode($config) === ChildTicket::ON_CLOSED
                && !ChildTicket::isSettled($tickets_id)
            ) {
                echo "<div class='form-text'>"
                   . __s('This step completes when that ticket is solved.', 'glpisop')
                   . '</div>';
            }

            echo '</div>';
            return;
        }

        if (!$editable) {
            echo "<div class='form-text'>"
               . __s('No ticket has been raised for this step.', 'glpisop') . '</div>';
            echo '</div>';
            return;
        }

        echo "<button type='button' class='btn btn-sm btn-outline-primary' "
           . "data-sop-action='spawn' data-sop-target='$steps_id' data-sop-confirm='"
           . __s('Raise a ticket for this step?', 'glpisop') . "'>"
           . "<i class='ti ti-ticket me-1'></i>"
           . __s('Raise the ticket', 'glpisop') . '</button>';

        echo '</div>';
    }

    /**
     * The control for an approval step — which is not a control.
     *
     * It says what the step needs and what the record currently says, and links
     * to the item's own Approvals tab, which is where an approval is requested
     * and granted. There is deliberately nothing here to click that would
     * change the answer: requesting and granting are core's, with core's rights
     * and notifications behind them, and a second way to grant an approval
     * would be a way around them.
     */
    private static function renderApproval(array $step, array $answer): void
    {
        $config = Step::config($step);
        $state  = (string) ($answer['state'] ?? Answer::PENDING);

        echo "<div class='sop-approval'>";

        echo "<div class='sop-approval-state'>";
        echo $state === Answer::DONE
            ? "<i class='ti ti-circle-check me-1'></i>"
            : "<i class='ti ti-clock me-1'></i>";
        // 0 means the run has never been recounted against the record yet;
        // NONE is what an item with no approval on it actually holds.
        $observed = (int) ($answer['value'] ?? 0) ?: \CommonITILValidation::NONE;
        echo __s('Currently', 'glpisop') . ': ' . self::e(Approval::statusLabel($observed));
        echo '</div>';

        echo "<div class='form-text'>"
           . self::e(Approval::describeRequirement($config))
           . '</div>';

        echo '</div>';
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
