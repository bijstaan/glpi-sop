<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The checklist endpoint: one answer, one request.
 *
 * GLPI 11 bootstraps the framework before executing plugin `ajax/` scripts, so
 * there is no includes.php to pull in here.
 *
 * Everything arrives from the browser, including the run id, so nothing is
 * taken on trust. Each request re-derives the run, re-derives the item it
 * belongs to, and re-checks that the caller may update *that* item — without
 * which this endpoint would report, and let anyone edit, the procedure on any
 * ticket in the instance from a guessed id.
 *
 * The step id is checked against the run's own SOP for the same reason: a step
 * id from a different procedure would otherwise write an answer nobody can see
 * and nobody can clear.
 */

use GlpiPlugin\Glpisop\Answer;
use GlpiPlugin\Glpisop\ChildTicket;
use GlpiPlugin\Glpisop\Renderer;
use GlpiPlugin\Glpisop\Run;
use GlpiPlugin\Glpisop\RunLog;
use GlpiPlugin\Glpisop\Settings;
use GlpiPlugin\Glpisop\Sop;
use GlpiPlugin\Glpisop\Step;
use GlpiPlugin\Glpisop\StepType;
use GlpiPlugin\Glpisop\Visibility;

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

/** Emit a JSON payload and stop. */
$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
};

if ((int) Session::getLoginUserID() <= 0) {
    $respond(['ok' => false, 'error' => 'unauthenticated'], 401);
}

// SOPs are internal workflow. See Settings::inCentralInterface().
if (!Settings::inCentralInterface()) {
    $respond(['ok' => false, 'error' => 'forbidden'], 403);
}

// No explicit Session::checkCSRF: GLPI 11's CheckCsrfListener validated and
// consumed the token before this script ran, so a second check always fails.
// The token the browser sends is what matters.

$action  = (string) ($_POST['action'] ?? '');
$runs_id = (int) ($_POST['runs_id'] ?? 0);

$run = new Run();
if ($runs_id <= 0 || !$run->getFromDB($runs_id)) {
    $respond(['ok' => false, 'error' => 'not_found'], 404);
}

$run_row  = $run->fields;
$itemtype = (string) $run_row['itemtype'];
$items_id = (int) $run_row['items_id'];

if (!Settings::appliesTo($itemtype) || !class_exists($itemtype)) {
    $respond(['ok' => false, 'error' => 'invalid_item'], 400);
}

/** @var CommonDBTM $item */
$item = new $itemtype();
if (!$item->getFromDB($items_id) || !$item->canViewItem()) {
    $respond(['ok' => false, 'error' => 'not_found'], 404);
}

if (!Session::haveRight(Run::$rightname, READ)) {
    $respond(['ok' => false, 'error' => 'forbidden'], 403);
}

/** The current state of the run, as every mutating action returns it. */
$state = static function () use ($runs_id, $run_row, $item): array {
    $steps    = Step::allFor((int) $run_row['plugin_glpisop_sops_id']);
    $steps_ids = array_map(static fn($s): int => (int) $s['id'], $steps);
    $answers  = Answer::forRun($runs_id, $steps_ids);
    $visible  = Visibility::evaluate($steps, $answers, $item);
    $progress = Visibility::progress($steps, $answers, $visible);

    $fresh = new Run();
    $fresh->getFromDB($runs_id);

    $sop = new Sop();
    $enforcing = $sop->getFromDB((int) $run_row['plugin_glpisop_sops_id'])
        && (int) $sop->fields['enforce_on_solve'] === 1;

    $steps_state = [];
    foreach ($steps as $step) {
        $steps_id = (int) $step['id'];
        $answer   = $answers[$steps_id];

        $steps_state[$steps_id] = [
            'state'   => (string) $answer['state'],
            'note'    => (string) ($answer['note'] ?? ''),
            'meta'    => Renderer::stepMetaText($answer),
            'visible' => (bool) ($visible[$steps_id] ?? false),
        ];
    }

    $status      = (string) ($fresh->fields['status'] ?? $run_row['status']);
    $outstanding = $progress['total_required'] - $progress['done_required'];

    return [
        'ok'           => true,
        'status'       => $status,
        'status_label' => Renderer::statusLabel($status),
        'status_class' => Renderer::statusClass($status),
        // For the fields-panel reminder, which lives outside the checklist's
        // own DOM and would otherwise keep showing the count from page load.
        'panel_note'     => Renderer::panelNote($status, $outstanding, $enforcing && Settings::flag('enforce_enabled')),
        'panel_blocking' => $outstanding > 0 && $enforcing && Settings::flag('enforce_enabled'),
        'progress' => [
            'total'          => $progress['total'],
            'done'           => $progress['done'],
            'total_required' => $progress['total_required'],
            'done_required'  => $progress['done_required'],
            'percent'        => Renderer::percent($progress),
            // Composed server-side so the live update reads exactly like the
            // first render, plural forms and translation included.
            'text'           => Renderer::progressText($progress),
            'required_text'  => Renderer::requiredText($progress, $enforcing),
        ],
        'steps'    => $steps_state,
    ];
};

// Reading the history needs no write access; everything below it does.
if ($action === 'log') {
    $entries = [];
    foreach (RunLog::forRun($runs_id) as $row) {
        $entries[] = [
            'when'   => Html::convDateTime((string) $row['date_creation']),
            'who'    => RunLog::actorName((int) $row['users_id']),
            'action' => RunLog::actionLabels()[(string) $row['action']] ?? (string) $row['action'],
            'step'   => Renderer::stepLabel((int) $row['plugin_glpisop_steps_id']),
            'detail' => (string) $row['detail'],
        ];
    }
    $respond(['ok' => true, 'entries' => $entries]);
}

// Locking is handled before the editability gate, and deliberately so: a locked
// run is *not* editable, and if unlocking had to pass that gate the lock would
// have no key. It still requires everything editing requires apart from the
// lock itself — the right, update access to the item, and a run that has not
// aged out under `lock_after_days`.
if ($action === 'unlock' || $action === 'lock') {
    if (!Run::isPermitted($run_row) || Settings::isFrozen($run_row['completed_at'] ?? null)) {
        $respond(['ok' => false, 'error' => 'forbidden'], 403);
    }

    Run::setUnlocked($runs_id, $action === 'unlock');

    // Re-read: the run's editability has just changed, and the browser
    // re-renders the whole entry from what comes back.
    $respond(['reload' => true] + $state());
}

if (!Run::isEditable($run_row)) {
    $respond(['ok' => false, 'error' => 'read_only'], 403);
}

if ($action === 'abandon') {
    Run::abandon($runs_id, (string) ($_POST['reason'] ?? ''));
    $respond(['ok' => true, 'status' => Run::ABANDONED] + $state());
}

// ---------------------------------------------------------------- per-step
$steps_id = (int) ($_POST['steps_id'] ?? 0);

$step = new Step();
if (
    $steps_id <= 0
    || !$step->getFromDB($steps_id)
    || (int) $step->fields['plugin_glpisop_sops_id'] !== (int) $run_row['plugin_glpisop_sops_id']
) {
    $respond(['ok' => false, 'error' => 'unknown_step'], 400);
}

$step_row = $step->fields;
$type     = (string) $step_row['step_type'];
$config   = Step::config($step_row);

switch ($action) {
    case 'answer':
        $columns = StepType::normalize($type, $config, $_POST);
        if (isset($columns['error'])) {
            $respond(['ok' => false, 'error' => 'invalid', 'message' => $columns['error'],
                'steps_id' => $steps_id,
            ], 422);
        }

        // A checkbox being unticked is a clear, not an answer. Handling it here
        // rather than asking the browser to send a different action keeps the
        // "what does this control mean" decision on one side of the wire.
        if ($type === StepType::CHECK && (string) ($_POST['value'] ?? '') !== '1') {
            Answer::clear($runs_id, $steps_id);
            RunLog::add($runs_id, RunLog::CLEARED, $steps_id);
            Run::recount($runs_id);
            $respond($state());
        }

        $existing        = Answer::one($runs_id, $steps_id);
        $columns['state'] = Answer::DONE;
        // The note is carried, not cleared: answering a step should not silently
        // discard what someone wrote about it.
        $columns['note'] = $existing['note'] ?? null;

        Answer::put($runs_id, $steps_id, $columns, (int) Session::getLoginUserID());
        RunLog::add($runs_id, RunLog::ANSWERED, $steps_id, StepType::format($type, $columns));
        Run::recount($runs_id);
        $respond($state());

    case 'clear':
        Answer::clear($runs_id, $steps_id);
        RunLog::add($runs_id, RunLog::CLEARED, $steps_id);
        Run::recount($runs_id);
        $respond($state());

    case 'skip':
        if (!Settings::flag('allow_skip')) {
            $respond(['ok' => false, 'error' => 'skip_disabled'], 403);
        }

        $existing = Answer::one($runs_id, $steps_id);
        if ((string) $existing['state'] === Answer::SKIPPED) {
            Answer::clear($runs_id, $steps_id);
            RunLog::add($runs_id, RunLog::CLEARED, $steps_id);
            Run::recount($runs_id);
            $respond($state());
        }

        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (Settings::flag('require_skip_reason') && $reason === '') {
            $respond(['ok' => false, 'error' => 'reason_required',
                'message'  => __('Say why this step is being skipped.', 'glpisop'),
                'steps_id' => $steps_id,
            ], 422);
        }

        Answer::put($runs_id, $steps_id, [
            'state' => Answer::SKIPPED,
            'note'  => $reason !== '' ? $reason : ($existing['note'] ?? null),
        ], (int) Session::getLoginUserID());
        RunLog::add($runs_id, RunLog::SKIPPED, $steps_id, $reason);
        Run::recount($runs_id);
        $respond($state());

    case 'note':
        $existing = Answer::one($runs_id, $steps_id);
        $note     = trim((string) ($_POST['note'] ?? ''));

        // A note on an untouched step must not make it look answered, so the
        // state is preserved rather than defaulted.
        Answer::put($runs_id, $steps_id, [
            'state'          => (string) $existing['state'],
            'value'          => $existing['value'],
            'value_itemtype' => $existing['value_itemtype'],
            'value_items_id' => (int) $existing['value_items_id'],
            'documents_id'   => (int) $existing['documents_id'],
            'note'           => $note !== '' ? $note : null,
        ], (int) Session::getLoginUserID());

        Run::recount($runs_id);
        $respond($state());

    case 'spawn':
        if ($type !== StepType::TICKET) {
            $respond(['ok' => false, 'error' => 'not_a_ticket_step'], 400);
        }

        $existing = Answer::one($runs_id, $steps_id);
        if ((int) $existing['value_items_id'] > 0) {
            // Already raised. Refused rather than raised again: a duplicate
            // licensing request is a real cost to whoever works that queue,
            // and the button is only reachable at all through a stale page.
            $respond(['ok' => false, 'error' => 'already_raised',
                'message'  => __('A ticket has already been raised for this step.', 'glpisop'),
                'steps_id' => $steps_id,
            ], 409);
        }

        $spawned = ChildTicket::spawn($step_row, $config, $item);
        if (isset($spawned['error'])) {
            $respond(['ok' => false, 'error' => 'spawn_failed',
                'message'  => $spawned['error'],
                'steps_id' => $steps_id,
            ], 422);
        }

        $tickets_id = (int) $spawned['tickets_id'];
        $settles    = ChildTicket::completionMode($config) === ChildTicket::ON_CREATED;

        Answer::put($runs_id, $steps_id, [
            // Raising the ticket answers the step only when the procedure said
            // that making the request was the work. Otherwise the step stays
            // outstanding — which is the whole point of the waiting form, and
            // what lets an enforcing SOP hold the parent open until the child
            // is finished.
            'state'          => $settles ? Answer::DONE : Answer::PENDING,
            'value_itemtype' => Ticket::class,
            'value_items_id' => $tickets_id,
            'note'           => $existing['note'] ?? null,
        ], (int) Session::getLoginUserID());

        RunLog::add(
            $runs_id,
            RunLog::ANSWERED,
            $steps_id,
            sprintf(__('raised ticket #%d', 'glpisop'), $tickets_id)
        );
        Run::recount($runs_id);
        $respond(['tickets_id' => $tickets_id] + $state());

    case 'upload':
        if ($type !== StepType::DOCUMENT) {
            $respond(['ok' => false, 'error' => 'not_a_document_step'], 400);
        }

        $documents_id = plugin_glpisop_store_upload($item, $_FILES['file'] ?? null);
        if ($documents_id === null) {
            $respond(['ok' => false, 'error' => 'upload_failed',
                'message'  => __('The file could not be filed. Check the allowed document types.', 'glpisop'),
                'steps_id' => $steps_id,
            ], 422);
        }

        $existing = Answer::one($runs_id, $steps_id);
        Answer::put($runs_id, $steps_id, [
            'state'        => Answer::DONE,
            'documents_id' => $documents_id,
            'note'         => $existing['note'] ?? null,
        ], (int) Session::getLoginUserID());

        RunLog::add($runs_id, RunLog::ANSWERED, $steps_id, (string) ($_FILES['file']['name'] ?? ''));
        Run::recount($runs_id);
        $respond(['documents_id' => $documents_id] + $state());
}

$respond(['ok' => false, 'error' => 'unknown_action'], 400);

/**
 * File an uploaded document against the item the run lives on.
 *
 * The document is attached to the ticket as well as recorded on the step, so
 * the evidence is reachable from the ticket by anyone who has never heard of
 * this plugin.
 */
function plugin_glpisop_store_upload(CommonDBTM $item, ?array $file): ?int
{
    if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    if (!is_uploaded_file((string) $file['tmp_name'])) {
        return null;
    }

    // The prefix is what GLPI strips back off to recover the display name, and
    // it is also what stops two technicians uploading `screenshot.png` a second
    // apart from colliding in the upload directory.
    $prefix   = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '_';
    $basename = basename((string) $file['name']);
    $target   = GLPI_UPLOAD_DIR . '/' . $prefix . $basename;

    if (!@move_uploaded_file((string) $file['tmp_name'], $target)) {
        return null;
    }

    $document     = new Document();
    $documents_id = $document->add([
        'entities_id'            => (int) ($item->fields['entities_id'] ?? 0),
        'is_recursive'           => (int) ($item->fields['is_recursive'] ?? 0),
        'name'                   => $basename,
        'upload_file'            => $prefix . $basename,
        '_prefix_filename'       => [$prefix],
        '_only_if_upload_succeed' => true,
        'itemtype'               => $item::getType(),
        'items_id'               => (int) $item->getID(),
        // Attaching evidence is not a reply. Without this, filing a screenshot
        // reopens a ticket that was waiting on the requester.
        '_do_not_compute_status' => true,
    ]);

    if (!$documents_id) {
        // Document::add refused it — an extension GLPI does not allow, most
        // likely. The upload must not be left behind in the upload directory.
        @unlink($target);
        return null;
    }

    return (int) $documents_id;
}
