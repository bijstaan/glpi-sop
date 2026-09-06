<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Create, edit, reorder or remove one step of an SOP.
 *
 * The step editor is a full page rather than a row in the builder table
 * because a step's definition is genuinely large — its type, that type's own
 * options, whether it is required, and the branch it hangs off. Everything
 * returns to the SOP's Steps tab, which is where the author is working.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpisop\Answer;
use GlpiPlugin\Glpisop\Approval;
use GlpiPlugin\Glpisop\ChildTicket;
use GlpiPlugin\Glpisop\ClauseForm;
use GlpiPlugin\Glpisop\Condition;
use GlpiPlugin\Glpisop\Renderer;
use GlpiPlugin\Glpisop\Section;
use GlpiPlugin\Glpisop\Sop;
use GlpiPlugin\Glpisop\SopBuilderTab;
use GlpiPlugin\Glpisop\Step;
use GlpiPlugin\Glpisop\StepType;
use GlpiPlugin\Glpisop\Url;

Session::checkRight(Step::$rightname, READ);

$step = new Step();

/** Back to the Steps tab of the SOP being edited. */
$back = static function (int $sops_id): never {
    $sop = new Sop();
    Html::redirect(
        $sop->getFormURLWithID($sops_id) . '&forcetab=' . urlencode(SopBuilderTab::class . '$1')
    );
};

/**
 * Fold the type-specific fields of the form into the JSON config column.
 *
 * Only the keys belonging to the submitted type are kept, so switching a step
 * from "number" to "choice" does not leave a stale min/max behind to confuse
 * whoever reads the row next.
 */
$collect_config = static function (string $type, array $post): string {
    $config = [];

    switch ($type) {
        case StepType::TEXT:
        case StepType::TEXTAREA:
            $config['placeholder'] = trim((string) ($post['cfg_placeholder'] ?? ''));
            $config['pattern']     = trim((string) ($post['cfg_pattern'] ?? ''));
            break;

        case StepType::NUMBER:
            $config['min']  = trim((string) ($post['cfg_min'] ?? ''));
            $config['max']  = trim((string) ($post['cfg_max'] ?? ''));
            $config['unit'] = trim((string) ($post['cfg_unit'] ?? ''));
            break;

        case StepType::CHOICE:
        case StepType::MULTICHOICE:
            $options = [];
            foreach (preg_split('/\R/', (string) ($post['cfg_options'] ?? '')) as $line) {
                $line = trim((string) $line);
                if ($line !== '' && !in_array($line, $options, true)) {
                    $options[] = $line;
                }
            }
            $config['options'] = $options;
            break;

        case StepType::ASSET:
            $config['itemtypes'] = array_values(array_filter(
                array_map('strval', (array) ($post['cfg_itemtypes'] ?? [])),
                static fn(string $itemtype): bool => class_exists($itemtype)
            ));
            break;

        case StepType::APPROVAL:
            $config['require_status'] = (int) ($post['cfg_require_status'] ?? 0);
            $config['users_id']       = (int) ($post['cfg_users_id'] ?? 0);
            $config['groups_id']      = (int) ($post['cfg_groups_id'] ?? 0);
            break;

        case StepType::TICKET:
            $config['title']             = trim((string) ($post['cfg_title'] ?? ''));
            $config['content']           = trim((string) ($post['cfg_content'] ?? ''));
            $config['itilcategories_id'] = (int) ($post['cfg_itilcategories_id'] ?? 0);
            $config['groups_id_assign']  = (int) ($post['cfg_groups_id_assign'] ?? 0);
            $config['type']              = (int) ($post['cfg_type'] ?? Ticket::DEMAND_TYPE);
            $config['link_as']           = (string) ($post['cfg_link_as'] ?? ChildTicket::LINK_SON)
                === ChildTicket::LINK_PLAIN ? ChildTicket::LINK_PLAIN : ChildTicket::LINK_SON;
            $config['complete_on']       = (string) ($post['cfg_complete_on'] ?? ChildTicket::ON_CREATED)
                === ChildTicket::ON_CLOSED ? ChildTicket::ON_CLOSED : ChildTicket::ON_CREATED;
            break;
    }

    return json_encode($config, JSON_UNESCAPED_UNICODE);
};

// ------------------------------------------------------------------ actions

if (isset($_POST['add'])) {
    Session::checkRight(Step::$rightname, UPDATE);

    $sops_id = (int) ($_POST['plugin_glpisop_sops_id'] ?? 0);
    $sop     = new Sop();
    if ($sops_id <= 0 || !$sop->getFromDB($sops_id)) {
        Html::displayErrorAndDie(__('Unknown SOP.', 'glpisop'));
    }
    $sop->check($sops_id, UPDATE);

    $type        = (string) ($_POST['step_type'] ?? StepType::CHECK);
    $sections_id = (int) ($_POST['plugin_glpisop_sections_id'] ?? 0);

    $step->add([
        'plugin_glpisop_sops_id'     => $sops_id,
        'plugin_glpisop_sections_id' => $sections_id,
        'label'                      => (string) ($_POST['label'] ?? ''),
        'step_type'                  => StepType::exists($type) ? $type : StepType::CHECK,
        'config_json'                => '{}',
        'rank_order'                 => Step::nextRank($sops_id, $sections_id),
        'is_required'                => 0,
        'is_active'                  => 1,
        'date_creation'              => date('Y-m-d H:i:s'),
    ]);

    $back($sops_id);
}

if (isset($_POST['update'])) {
    Session::checkRight(Step::$rightname, UPDATE);

    $steps_id = (int) ($_POST['id'] ?? 0);
    if (!$step->getFromDB($steps_id)) {
        Html::displayErrorAndDie(__('Unknown step.', 'glpisop'));
    }

    $sops_id = (int) $step->fields['plugin_glpisop_sops_id'];
    $sop     = new Sop();
    $sop->getFromDB($sops_id);
    $sop->check($sops_id, UPDATE);

    $type = (string) ($_POST['step_type'] ?? $step->fields['step_type']);
    if (!StepType::exists($type)) {
        $type = (string) $step->fields['step_type'];
    }

    // Refiling a step under another heading gives it a fresh rank at the
    // bottom of that section. Carrying its old rank across would land it in an
    // arbitrary place among its new neighbours — or on top of one of them,
    // which the flat reorder then has to break a tie for.
    $sections_id = (int) ($_POST['plugin_glpisop_sections_id'] ?? 0);
    $rank        = (int) $step->fields['rank_order'];
    if ($sections_id !== (int) $step->fields['plugin_glpisop_sections_id']) {
        $rank = Step::nextRank($sops_id, $sections_id);
    }

    $mode = (string) ($_POST['depends_mode'] ?? Condition::MODE_ALL);

    $step->update([
        'id'                         => $steps_id,
        'plugin_glpisop_sections_id' => $sections_id,
        'rank_order'                 => $rank,
        'label'                      => (string) ($_POST['label'] ?? ''),
        'help'                       => (string) ($_POST['help'] ?? ''),
        'step_type'                  => $type,
        'config_json'                => $collect_config($type, $_POST),
        'is_required'                => !empty($_POST['is_required']) ? 1 : 0,
        'is_active'                  => !empty($_POST['is_active']) ? 1 : 0,
        'depends_mode'               => $mode === Condition::MODE_ANY
            ? Condition::MODE_ANY
            : Condition::MODE_ALL,
    ]);

    // Every run of this SOP is re-derived from Step::post_updateItem(), which
    // is where it belongs — the reordering branch below and the builder's
    // add-step form go through the same model methods.

    $back($sops_id);
}

if (isset($_POST['purge'])) {
    Session::checkRight(Step::$rightname, UPDATE);

    $steps_id = (int) ($_POST['id'] ?? 0);
    if (!$step->getFromDB($steps_id)) {
        Html::displayErrorAndDie(__('Unknown step.', 'glpisop'));
    }

    $sops_id = (int) $step->fields['plugin_glpisop_sops_id'];
    $sop     = new Sop();
    $sop->getFromDB($sops_id);
    $sop->check($sops_id, UPDATE);

    $step->delete(['id' => $steps_id], true);

    $back($sops_id);
}

if (isset($_POST['move'])) {
    Session::checkRight(Step::$rightname, UPDATE);

    $steps_id = (int) ($_POST['id'] ?? 0);
    if (!$step->getFromDB($steps_id)) {
        Html::displayErrorAndDie(__('Unknown step.', 'glpisop'));
    }

    $sops_id = (int) $step->fields['plugin_glpisop_sops_id'];
    $sop     = new Sop();
    $sop->getFromDB($sops_id);
    $sop->check($sops_id, UPDATE);

    Step::reorder($sops_id, $steps_id, (string) $_POST['move'] === 'up' ? 'up' : 'down');

    $back($sops_id);
}

// ------------------------------------------------------------------- editor

$steps_id = (int) ($_GET['id'] ?? 0);
if ($steps_id <= 0 || !$step->getFromDB($steps_id)) {
    Html::displayErrorAndDie(__('Unknown step.', 'glpisop'));
}

$sops_id = (int) $step->fields['plugin_glpisop_sops_id'];
$sop     = new Sop();
if (!$sop->getFromDB($sops_id)) {
    Html::displayErrorAndDie(__('Unknown SOP.', 'glpisop'));
}
$sop->check($sops_id, READ);

$canedit = Session::haveRight(Step::$rightname, UPDATE) && $sop->can($sops_id, UPDATE);

Html::header(
    Sop::getTypeName(1),
    $_SERVER['PHP_SELF'],
    class_exists(\GlpiPlugin\Glpinav\Nav::class)
        ? \GlpiPlugin\Glpinav\Nav::sector(Sop::class, 'config')
        : 'config',
    Sop::class
);

$e      = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$config = Step::config($step->fields);
$type   = (string) $step->fields['step_type'];

// glpisop-surface: the scope class sop.css's dark-theme muted-text fixes key
// off — without it this page's .form-text/.text-muted fall back to core's
// failing dark-palette values.
echo "<div class='container-fluid glpisop-surface' style='max-width:900px'>";

echo "<div class='mb-3'>";
echo "<a href='" . $e($sop->getFormURLWithID($sops_id) . '&forcetab='
    . urlencode(SopBuilderTab::class . '$1')) . "'>"
   . "<i class='ti ti-arrow-left me-1'></i>" . $e($sop->fields['name']) . '</a>';
echo '</div>';

echo "<form method='post'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo Html::hidden('id', ['value' => $steps_id]);

// --- what it asks -----------------------------------------------------
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('The question', 'glpisop') . '</h3></div>';
echo "<div class='card-body'>";

echo "<div class='mb-3'><label class='form-label'>" . __s('Label', 'glpisop') . '</label>';
echo "<input type='text' class='form-control' name='label' required value='"
   . $e($step->fields['label']) . "'" . ($canedit ? '' : ' disabled') . '></div>';

echo "<div class='mb-3'><label class='form-label'>" . __s('Guidance', 'glpisop') . '</label>';
echo "<textarea class='form-control' rows='3' name='help'" . ($canedit ? '' : ' disabled') . '>'
   . $e($step->fields['help']) . '</textarea>';
echo "<div class='form-text'>"
   . __s('Shown under the label. Where a step needs a detail — which command, which register, '
       . 'who to call — this is where it goes, so it is in front of the technician instead of '
       . 'in a wiki.', 'glpisop')
   . '</div></div>';

echo "<div class='row'>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __s('Type', 'glpisop') . '</label>';
Dropdown::showFromArray('step_type', StepType::all(), [
    'value'    => $type,
    'readonly' => !$canedit,
]);
echo '</div>';

$section_options = [0 => __('— unfiled —', 'glpisop')];
foreach (Section::allFor($sops_id) as $section) {
    $section_options[(int) $section['id']] = (string) $section['name'];
}
echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __s('Section', 'glpisop') . '</label>';
Dropdown::showFromArray('plugin_glpisop_sections_id', $section_options, [
    'value'    => (int) $step->fields['plugin_glpisop_sections_id'],
    'readonly' => !$canedit,
]);
echo '</div>';
echo '</div>';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='is_required' value='1' "
   . ((int) $step->fields['is_required'] === 1 ? "checked='checked' " : '')
   . ($canedit ? '' : 'disabled ') . '>';
echo "<span class='form-check-label'>" . __s('Required', 'glpisop') . ' — '
   . __s('counts towards completion, and can hold the item open when the SOP enforces', 'glpisop')
   . '</span></label>';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='is_active' value='1' "
   . ((int) $step->fields['is_active'] === 1 ? "checked='checked' " : '')
   . ($canedit ? '' : 'disabled ') . '>';
echo "<span class='form-check-label'>" . __s('Active') . ' — '
   . __s('unticking retires the step without deleting the answers already given to it', 'glpisop')
   . '</span></label>';

echo '</div></div>';

// --- type-specific ----------------------------------------------------
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Options for this type', 'glpisop') . '</h3></div>';
echo "<div class='card-body'>";
echo '<p class="text-muted">'
   . __s('Only the options belonging to the type above are stored. Changing the type discards '
       . 'the previous type’s options rather than leaving them behind.', 'glpisop')
   . '</p>';

switch ($type) {
    case StepType::TEXT:
    case StepType::TEXTAREA:
        echo "<div class='mb-3'><label class='form-label'>" . __s('Placeholder', 'glpisop') . '</label>';
        echo "<input type='text' class='form-control' name='cfg_placeholder' value='"
           . $e($config['placeholder'] ?? '') . "'" . ($canedit ? '' : ' disabled') . '></div>';

        echo "<div class='mb-3'><label class='form-label'>"
           . __s('Required format (regular expression)', 'glpisop') . '</label>';
        echo "<input type='text' class='form-control font-monospace' name='cfg_pattern' value='"
           . $e($config['pattern'] ?? '') . "'" . ($canedit ? '' : ' disabled') . '>';
        echo "<div class='form-text'>"
           . __s('Optional. An answer that does not match is refused — useful for a serial number '
               . 'or an asset tag with a house format. Leave empty to accept anything.', 'glpisop')
           . '</div></div>';
        break;

    case StepType::NUMBER:
        echo "<div class='row'>";
        foreach (['min' => __s('Minimum', 'glpisop'), 'max' => __s('Maximum', 'glpisop')] as $key => $label) {
            echo "<div class='col-md-4 mb-3'><label class='form-label'>$label</label>";
            echo "<input type='number' step='any' class='form-control' name='cfg_$key' value='"
               . $e($config[$key] ?? '') . "'" . ($canedit ? '' : ' disabled') . '></div>';
        }
        echo "<div class='col-md-4 mb-3'><label class='form-label'>" . __s('Unit', 'glpisop') . '</label>';
        echo "<input type='text' class='form-control' name='cfg_unit' value='"
           . $e($config['unit'] ?? '') . "'" . ($canedit ? '' : ' disabled') . '></div>';
        echo '</div>';
        break;

    case StepType::CHOICE:
    case StepType::MULTICHOICE:
        echo "<div class='mb-3'><label class='form-label'>" . __s('Options, one per line', 'glpisop') . '</label>';
        echo "<textarea class='form-control font-monospace' rows='5' name='cfg_options'"
           . ($canedit ? '' : ' disabled') . '>'
           . $e(implode("\n", StepType::options($config))) . '</textarea>';
        echo "<div class='form-text'>"
           . __s('These are also what a later step can be gated on. Renaming one does not rewrite '
               . 'branches that point at the old text, so rename with care once the SOP is in use.', 'glpisop')
           . '</div></div>';
        break;

    case StepType::ASSET:
        echo "<div class='mb-3'><label class='form-label'>" . __s('Allowed asset types', 'glpisop') . '</label>';
        echo '<div>';
        foreach ((array) ($CFG_GLPI['ticket_types'] ?? ['Computer']) as $itemtype) {
            if (!class_exists($itemtype)) {
                continue;
            }
            $checked = in_array($itemtype, (array) ($config['itemtypes'] ?? []), true);
            echo "<label class='form-check form-check-inline'>";
            echo "<input type='checkbox' class='form-check-input' name='cfg_itemtypes[]' value='"
               . $e($itemtype) . "'" . ($checked ? " checked='checked'" : '')
               . ($canedit ? '' : ' disabled') . '>';
            echo "<span class='form-check-label'>" . $e($itemtype::getTypeName(1)) . '</span></label>';
        }
        echo '</div>';
        echo "<div class='form-text'>"
           . __s('None ticked means every type GLPI allows on a ticket.', 'glpisop')
           . '</div></div>';
        break;

    case StepType::APPROVAL:
        echo "<div class='row'>";

        echo "<div class='col-md-4 mb-3'><label class='form-label'>"
           . __s('The item must be', 'glpisop') . '</label>';
        Dropdown::showFromArray('cfg_require_status', Approval::requirableStatuses(), [
            'value'    => Approval::requiredStatus($config),
            'readonly' => !$canedit,
        ]);
        echo '</div>';

        echo "<div class='col-md-4 mb-3'><label class='form-label'>"
           . __s('Approved by (optional)', 'glpisop') . '</label>';
        Dropdown::show(User::class, [
            'name'                => 'cfg_users_id',
            'value'               => (int) ($config['users_id'] ?? 0),
            'right'               => 'all',
            'display_emptychoice' => true,
            'width'               => '100%',
            'readonly'            => !$canedit,
        ]);
        echo '</div>';

        echo "<div class='col-md-4 mb-3'><label class='form-label'>"
           . __s('Or by a member of (optional)', 'glpisop') . '</label>';
        Dropdown::show(Group::class, [
            'name'                => 'cfg_groups_id',
            'value'               => (int) ($config['groups_id'] ?? 0),
            'display_emptychoice' => true,
            'width'               => '100%',
            'readonly'            => !$canedit,
        ]);
        echo '</div>';

        echo '</div>';

        echo "<div class='form-text'>"
           . __s('This step cannot be ticked. It is satisfied by the item’s own approval record '
               . 'and goes back to outstanding if that record changes — so an enforcing SOP holds '
               . 'the item open until the approval is really there, and reopens if it is '
               . 'withdrawn. Naming a person or a group narrows it further: the item must be '
               . 'approved, and approved by them.', 'glpisop')
           . '</div>';

        if (!in_array('Ticket', $sop->itemtypes(), true) && !in_array('Change', $sop->itemtypes(), true)) {
            echo "<div class='alert alert-warning mt-2'>"
               . __s('This SOP is not written for tickets or changes, and nothing else in GLPI has '
                   . 'approvals — this step can never be satisfied.', 'glpisop')
               . '</div>';
        }
        break;

    case StepType::TICKET:
        echo "<div class='mb-3'><label class='form-label'>" . __s('Ticket title', 'glpisop') . '</label>';
        echo "<input type='text' class='form-control' name='cfg_title' value='"
           . $e($config['title'] ?? '') . "'" . ($canedit ? '' : ' disabled') . '>';
        echo "<div class='form-text'>"
           . __s('%item% becomes “Ticket #482”, %title% the title of the item the procedure is '
               . 'running on. Left empty, the step’s own label is used.', 'glpisop')
           . '</div></div>';

        echo "<div class='mb-3'><label class='form-label'>" . __s('Ticket description', 'glpisop') . '</label>';
        echo "<textarea class='form-control' rows='3' name='cfg_content'"
           . ($canedit ? '' : ' disabled') . '>' . $e($config['content'] ?? '') . '</textarea>';
        echo "<div class='form-text'>"
           . __s('The same two placeholders. Left empty, the step’s guidance is used.', 'glpisop')
           . '</div></div>';

        echo "<div class='row'>";

        echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __s('Category') . '</label>';
        Dropdown::show(ITILCategory::class, [
            'name'     => 'cfg_itilcategories_id',
            'value'    => (int) ($config['itilcategories_id'] ?? 0),
            'width'    => '100%',
            'readonly' => !$canedit,
        ]);
        echo '</div>';

        echo "<div class='col-md-6 mb-3'><label class='form-label'>"
           . __s('Assign to group', 'glpisop') . '</label>';
        Dropdown::show(Group::class, [
            'name'      => 'cfg_groups_id_assign',
            'value'     => (int) ($config['groups_id_assign'] ?? 0),
            'width'     => '100%',
            'condition' => ['is_assign' => 1],
            'readonly'  => !$canedit,
        ]);
        echo '</div>';

        echo "<div class='col-md-4 mb-3'><label class='form-label'>" . __s('Type') . '</label>';
        Dropdown::showFromArray('cfg_type', [
            Ticket::INCIDENT_TYPE => Ticket::getTicketTypeName(Ticket::INCIDENT_TYPE),
            Ticket::DEMAND_TYPE   => Ticket::getTicketTypeName(Ticket::DEMAND_TYPE),
        ], [
            'value'    => (int) ($config['type'] ?? Ticket::DEMAND_TYPE),
            'readonly' => !$canedit,
        ]);
        echo '</div>';

        echo "<div class='col-md-4 mb-3'><label class='form-label'>" . __s('Link it', 'glpisop') . '</label>';
        Dropdown::showFromArray('cfg_link_as', ChildTicket::linkModes(), [
            'value'    => (string) ($config['link_as'] ?? ChildTicket::LINK_SON),
            'readonly' => !$canedit,
        ]);
        echo '</div>';

        echo "<div class='col-md-4 mb-3'><label class='form-label'>"
           . __s('This step is done', 'glpisop') . '</label>';
        Dropdown::showFromArray('cfg_complete_on', ChildTicket::completionModes(), [
            'value'    => ChildTicket::completionMode($config),
            'readonly' => !$canedit,
        ]);
        echo '</div>';

        echo '</div>';

        echo "<div class='form-text'>"
           . __s('“Only when that ticket is solved” is what lets an enforcing SOP hold this item '
               . 'open until the work it asked for is actually finished — the licence bought, the '
               . 'access granted — rather than until somebody remembered to ask.', 'glpisop')
           . '</div>';
        break;

    default:
        echo "<p class='text-muted mb-0'>" . __s('This type has no options.', 'glpisop') . '</p>';
}

echo '</div></div>';

if ($canedit) {
    echo "<div class='d-flex mb-4'>";
    echo "<button type='submit' name='purge' value='1' class='btn btn-outline-danger'>"
       . __s('Delete this step', 'glpisop') . '</button>';
    echo "<button type='submit' name='update' value='1' class='btn btn-primary ms-auto'>"
       . __s('Save') . '</button>';
    echo '</div>';
}

echo '</form>';

// --- the gate ---------------------------------------------------------
//
// Deliberately *after* the step's own form closes, and that is structural
// rather than cosmetic. Each clause is added and removed by its own POST, so
// each needs its own <form> — and a <form> inside a <form> is dropped by every
// browser, which is precisely how a row of buttons ends up looking present and
// doing nothing. Two forms side by side, never nested.
//
// It also means a clause is saved on its own: an author adding a condition has
// not necessarily finished editing the label above it, and making them press
// Save twice to keep both is how half-written steps get saved.
render_gate($step, $sop, $canedit, $e);

/** The gate: what it currently says, how its clauses join, and one add row. */
function render_gate(Step $step, Sop $sop, bool $canedit, callable $e): void
{
    $steps_id   = (int) $step->fields['id'];
    $sops_id    = (int) $step->fields['plugin_glpisop_sops_id'];
    $conditions = Condition::forStep($steps_id);
    $all_steps  = Step::allFor($sops_id, false);
    $numbers    = Renderer::numbering($all_steps);
    $parents    = Step::candidateParents($sops_id, $steps_id);

    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
       . __s('Ask this step only when…', 'glpisop') . '</h3></div>';
    echo "<div class='card-body'>";

    echo '<p class="text-muted">'
       . __s('A step that is not being asked is not outstanding — it does not count towards '
           . 'completion and cannot hold the item open. That is what makes a branching procedure '
           . 'report an honest number.', 'glpisop')
       . '</p>';

    if ($conditions === []) {
        echo "<p class='text-muted'>"
           . __s('No conditions — this step is asked in every run.', 'glpisop') . '</p>';
    } else {
        echo "<table class='table table-sm'><tbody>";
        foreach ($conditions as $condition) {
            echo '<tr><td>' . $e(Condition::describe($condition, $numbers)) . '</td>';
            if ($canedit) {
                echo "<td class='text-end' style='width:4rem'>";
                echo "<form method='post' action='" . $e(Url::to('front/condition.form.php')) . "'>";
                echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                echo Html::hidden('id', ['value' => (int) $condition['id']]);
                echo "<button type='submit' name='purge' value='1' class='btn btn-sm btn-ghost-danger' "
                   . "title='" . __s('Remove this condition', 'glpisop') . "'>"
                   . "<i class='ti ti-trash'></i></button>";
                echo '</form></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        // The joiner is only a question once there is something to join, and a
        // one-clause gate offering "all of them / any of them" is a decision
        // an author has to read and discard.
        if (count($conditions) > 1 && $canedit) {
            echo "<form method='post' action='" . $e(Url::to('front/condition.form.php')) . "' "
               . "class='row g-2 align-items-end mb-3' style='max-width:34rem'>";
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo Html::hidden('plugin_glpisop_steps_id', ['value' => $steps_id]);
            echo "<div class='col-md-8'><label class='form-label'>"
               . __s('Ask it when', 'glpisop') . '</label>';
            Dropdown::showFromArray('depends_mode', Condition::modes(), [
                'value' => Step::mode($step->fields),
            ]);
            echo '</div>';
            echo "<div class='col-md-4'><button type='submit' name='mode' value='1' "
               . "class='btn btn-secondary w-100'>" . __s('Apply') . '</button></div>';
            echo "<div class='col-12'><div class='form-text'>"
               . __s('One level, no brackets. A mixed expression is written by nesting: gate this '
                   . 'step on one half, and the next step on the other.', 'glpisop')
               . '</div></div>';
            echo '</form>';
        }
    }

    if ($canedit) {
        render_add_condition($steps_id, $sop, $parents, $numbers, $e);
    }

    echo '</div></div>';
}

/**
 * The add row: one row, one Add.
 *
 * This was three questions and a reload — what kind of thing to test, then
 * which one, then press "Use this" to get the operators and the value control
 * that belong to it. Two of those were the same question. An author does not
 * decide to test "a field of the ticket" and then decide which field; they
 * decide to test the category. So source and subject are now one dropdown,
 * grouped by source — see {@see Condition::subjects()} — and what depends on
 * it is rebuilt in place rather than by reloading the page.
 *
 * The operator drives a second, smaller cascade of its own: picking "was
 * answered" takes the value box away instead of leaving one beside a note
 * saying it is ignored, which is how an author ends up believing they wrote a
 * comparison they did not write.
 */
function render_add_condition(int $steps_id, Sop $sop, array $parents, array $numbers, callable $e): void
{
    $subjects = Condition::subjects($sop, $parents, $numbers);

    if ($subjects === []) {
        echo "<p class='text-muted mb-0'>"
           . __s('This is the first step of the procedure and its SOP has nothing else to test, '
               . 'so there is no condition to gate it on.', 'glpisop')
           . '</p>';
        return;
    }

    $subject = Condition::firstSubject($subjects);
    $rand    = mt_rand();

    echo "<div class='mt-3 pt-3 border-top'>";
    echo '<h4>' . __s('Add a condition', 'glpisop') . '</h4>';

    echo "<form method='post' action='" . $e(Url::to('front/condition.form.php')) . "' "
       . "class='row g-2 align-items-end'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo Html::hidden('plugin_glpisop_steps_id', ['value' => $steps_id]);

    echo "<div class='col-md-5'><label class='form-label'>" . __s('What is tested', 'glpisop') . '</label>';
    ClauseForm::subjectSelectionScript();
    Dropdown::showFromArray('subject', $subjects, [
        'value' => $subject,
        'rand'  => $rand,
        'width' => '100%',
        // The optgroup label is navigation, not part of the answer — see
        // ClauseForm::subjectSelectionScript() just above.
        'templateSelection' => 'glpisopSubjectSelection',
    ]);
    echo '</div>';

    echo "<div class='col-md-5' id='glpisop-gate-tail$rand'>";
    ClauseForm::gateTail($steps_id, $subject);
    echo '</div>';

    echo "<div class='col-md-2'><button type='submit' name='add' value='1' class='btn btn-primary w-100'>"
       . __s('Add') . '</button></div>';

    echo '</form>';
    echo '</div>';

    ClauseForm::cascade(
        "dropdown_subject$rand",
        "glpisop-gate-tail$rand",
        ['context' => 'gate', 'steps_id' => $steps_id, 'subject' => '__VALUE__']
    );
}

// A step with answers already recorded is one an author should think twice
// about restructuring, so say so rather than letting them find out later.
$answered = countElementsInTable(Answer::getTable(), ['plugin_glpisop_steps_id' => $steps_id]);
if ($answered > 0) {
    echo "<div class='alert alert-info'>"
       . htmlspecialchars(
           sprintf(
               _n(
                   'This step has been answered in %d run. Deleting it deletes that answer.',
                   'This step has been answered in %d runs. Deleting it deletes those answers.',
                   $answered,
                   'glpisop'
               ),
               $answered
           ),
           ENT_QUOTES,
           'UTF-8'
       )
       . '</div>';
}

echo '</div>';

Html::footer();
