<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The procedure editor's only endpoint.
 *
 * Three things, and the split says what the editor does on its own and what it
 * cannot:
 *
 *  - `save`      the whole procedure, once, when the author presses Save;
 *  - `options`   the options panel for a step type, when the author changes it;
 *  - `condvalue` the value control for a gate clause, when the author points
 *                one at a category, a user or a group.
 *
 * The last two are fragments rather than data because what they render is a
 * GLPI picker — an ajax dropdown with its own inline configuration — and there
 * is no honest way to build one in the browser from JSON. Everything the
 * browser *can* build, it builds: which steps a clause may name, the operators
 * that go with a subject, the answers a choice step offers. Those are read from
 * the canvas as it stands rather than fetched, so an author renaming a step
 * sees the rename in the clause that points at it.
 *
 * GLPI 11 bootstraps the framework before executing plugin `ajax/` scripts, so
 * there is no includes.php to pull in here.
 *
 * CSRF: the browser sends the token in `X-Glpi-Csrf-Token` with
 * `X-Requested-With`, which is what GLPI's kernel listener checks for an XHR.
 * A body token would be consumed per request, and this editor makes several.
 *
 * Authority is re-derived on every call. The SOP id arrives from the browser,
 * so it is read and checked for update rather than taken on trust — otherwise
 * this would enumerate the steps, categories and approvers of any procedure in
 * the instance from a guessed id, and write to them.
 */

use GlpiPlugin\Glpisop\BuilderSave;
use GlpiPlugin\Glpisop\Condition;
use GlpiPlugin\Glpisop\Sop;
use GlpiPlugin\Glpisop\StepOptions;
use GlpiPlugin\Glpisop\StepType;
use GlpiPlugin\Glpisop\Trigger;

Session::checkRight(Sop::$rightname, UPDATE);

$sops_id = (int) ($_REQUEST['sops_id'] ?? 0);
$sop     = new Sop();

if ($sops_id <= 0 || !$sop->getFromDB($sops_id)) {
    http_response_code(404);
    return;
}

$sop->check($sops_id, UPDATE);

$action = (string) ($_REQUEST['action'] ?? '');

// ------------------------------------------------------------------- saving

if ($action === 'save') {
    header('Content-Type: application/json; charset=UTF-8');
    Html::header_nocache();

    $save   = new BuilderSave($sop);
    $errors = $save->save($_POST);

    // The key map is what lets the canvas stay open across a save. A step the
    // author added a moment ago is `n7` in the DOM and has no id anywhere; once
    // it is written it is `t42`, and without being told so the next save would
    // send `n7` again and create a second copy of it.
    //
    // Only on a clean save, though. When the server has dropped a clause the
    // canvas still shows, the two no longer agree about what the procedure is,
    // and the honest move is to let the canvas be redrawn from the database
    // rather than to patch it into looking right.
    echo json_encode([
        'ok'     => true,
        'errors' => $errors,
        'keys'   => $errors === [] ? $save->keys() : new \stdClass(),
    ], JSON_UNESCAPED_UNICODE);

    return;
}

// ---------------------------------------------------------------- fragments

header('Content-Type: text/html; charset=UTF-8');
Html::header_nocache();

if ($action === 'options') {
    $type = (string) ($_REQUEST['type'] ?? '');
    if (!StepType::exists($type) || !StepOptions::has($type)) {
        // Not an error page: the type select is the only thing that produces
        // this value, so an unknown one means a stale canvas. An empty
        // fragment is recoverable — the author picks again — where a die()
        // would leave the panel permanently blank.
        return;
    }

    // The config comes back with the request so that duplicating a step keeps
    // its options. A clone cannot simply copy the panel: half of these are
    // select2 widgets, and a copied one is a dead control with the right text
    // in it. So the copy is rebuilt from the original's values, server-side,
    // by the same renderer that drew the original.
    $cfg = is_array($_REQUEST['cfg'] ?? null) ? $_REQUEST['cfg'] : [];

    StepOptions::render($type, StepOptions::collect($type, $cfg), true, $sop);
    return;
}

if ($action === 'condvalue') {
    [$source, $criterion] = Condition::splitSubject((string) ($_REQUEST['subject'] ?? ''));

    // Re-validated against what this SOP may actually be gated on, not merely
    // parsed: a stale canvas must not be able to render a picker over a
    // criterion the procedure was never written for.
    if (!array_key_exists($criterion, Condition::criteriaFor($source, $sop->itemtypes()))) {
        return;
    }

    $definition = Condition::definition($source, $criterion);
    if ($definition === null) {
        return;
    }

    Trigger::renderValueControl($definition, 'sopcondvalue', (string) ($_REQUEST['value'] ?? ''));
    return;
}
