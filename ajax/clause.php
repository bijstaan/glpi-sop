<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Re-render the part of a criterion row that depends on the choice above it.
 *
 * Two callers, one shape: the trigger tab's criterion select and the step
 * editor's gate. Both name {@see \GlpiPlugin\Glpisop\ClauseForm}, which is also
 * what paints the row the first time, so there is one renderer per fragment
 * rather than a server version and a JavaScript version that drift.
 *
 * GLPI 11 bootstraps the framework before executing plugin `ajax/` scripts, so
 * there is no includes.php to pull in here.
 *
 * jQuery's `.load()` sends these as POSTs, which is why everything is read from
 * $_REQUEST rather than $_GET. CSRF is already handled: GLPI's global ajaxSend
 * hook puts the token in X-Glpi-Csrf-Token for every POST it makes, and the
 * kernel listener checks that header for XHR requests.
 *
 * Nothing here writes, but everything is still re-derived and re-checked: the
 * step id arrives from the browser, so the SOP it belongs to is looked up and
 * checked for update rather than taken on trust. Otherwise this endpoint would
 * enumerate the steps, categories and approvers of any procedure in the
 * instance from a guessed id.
 */

use GlpiPlugin\Glpisop\ClauseForm;
use GlpiPlugin\Glpisop\Condition;
use GlpiPlugin\Glpisop\Renderer;
use GlpiPlugin\Glpisop\Sop;
use GlpiPlugin\Glpisop\Step;
use GlpiPlugin\Glpisop\Trigger;

header('Content-Type: text/html; charset=UTF-8');
Html::header_nocache();

Session::checkRight(Trigger::$rightname, UPDATE);

$context = (string) ($_REQUEST['context'] ?? '');

// ------------------------------------------------------------------ triggers

if ($context === 'trigger') {
    $criterion = (string) ($_REQUEST['criterion'] ?? '');
    if (!isset(Trigger::criteria()[$criterion])) {
        // Not an error page: the select is the only thing that produces this
        // value, so an unknown one means a stale tab. An empty fragment is
        // recoverable — the author picks again — where a die() would leave the
        // row permanently blank.
        return;
    }

    ClauseForm::triggerTail($criterion);
    return;
}

// ---------------------------------------------------------------- step gates

if ($context !== 'gate') {
    return;
}

$steps_id = (int) ($_REQUEST['steps_id'] ?? 0);

$step = new Step();
if ($steps_id <= 0 || !$step->getFromDB($steps_id)) {
    return;
}

$sops_id = (int) $step->fields['plugin_glpisop_sops_id'];
$sop     = new Sop();
if (!$sop->getFromDB($sops_id)) {
    return;
}
$sop->check($sops_id, UPDATE);

// The subject is re-validated against what this step may actually be gated on,
// not merely parsed. The select is rebuilt whenever the step changes, and a
// stale tab holding a step id that has since moved must not be able to write a
// forward reference the editor would never have offered.
$subjects = Condition::subjects(
    $sop,
    Step::candidateParents($sops_id, $steps_id),
    Renderer::numbering(Step::allFor($sops_id, false))
);

$subject = (string) ($_REQUEST['subject'] ?? '');
if (!Condition::subjectOffered($subjects, $subject)) {
    $subject = Condition::firstSubject($subjects);
}

if ($subject === '') {
    return;
}

if ((string) ($_REQUEST['part'] ?? '') === 'value') {
    ClauseForm::gateValue($steps_id, $subject, (string) ($_REQUEST['match_condition'] ?? ''));
    return;
}

ClauseForm::gateTail($steps_id, $subject);
