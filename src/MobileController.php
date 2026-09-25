<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBTM;
use Glpi\Api\HL\Controller\AbstractController;
use Glpi\Api\HL\Route;
use Glpi\Api\HL\RouteVersion;
use Glpi\Http\JSONResponse;
use Glpi\Http\Request;
use Glpi\Http\Response;
use Session;
use Ticket;

/**
 * Procedures on a phone.
 *
 * A standard operating procedure is a list of things somebody does *while
 * doing them*, and a fair proportion of that work happens away from a desk —
 * in a comms cabinet, in front of a rack, at somebody's desk with a laptop in
 * pieces. The web checklist is where a procedure is answered today, which
 * means it is answered afterwards, from memory, which is the failure mode the
 * plugin exists to remove. This is the same checklist, reachable from the
 * thing already in the technician's hand.
 *
 * Every route mirrors `ajax/run.php`, and mirrors it by calling the same
 * decisions rather than by repeating them: `Run::isEditable()` for whether an
 * answer may be written at all, `StepType::normalize()` for whether an answer
 * is valid, `Visibility::evaluate()` for which steps are even being asked.
 * The one authority that is genuinely restated is the item check — the run id
 * arrives from a client, so the run is loaded, the item it belongs to is
 * re-derived from the run, and the caller is checked against *that* item.
 *
 * Central interface only, like the web checklist: SOPs are internal workflow.
 */
#[Route(path: '/GlpiSop', tags: ['GlpiSop'])]
final class MobileController extends AbstractController
{
    protected static function getRawKnownSchemas(string $api_version = ''): array
    {
        return [];
    }

    /** Optional-parameter read: core's getParameter() warns on absent keys. */
    private static function param(Request $request, string $name, mixed $default = null): mixed
    {
        return $request->hasParameter($name) ? $request->getParameter($name) : $default;
    }

    /**
     * The procedures attached to one ITIL object.
     *
     * Abandoned runs are included and marked, not hidden: "this procedure does
     * not apply here, and here is who said so" is a thing the technician
     * standing in front of the problem needs to know before starting it again.
     */
    #[Route(
        path: '/item/{itemtype}/{items_id}/runs',
        methods: ['GET'],
        requirements: ['itemtype' => '\w+', 'items_id' => '\d+']
    )]
    #[RouteVersion(introduced: '2.0')]
    public function itemRuns(Request $request): Response
    {
        $item = self::viewableItem(
            (string) $request->getAttribute('itemtype'),
            (int) $request->getAttribute('items_id')
        );
        if ($item instanceof Response) {
            return $item;
        }

        $runs = [];
        foreach (Run::forItem($item::getType(), (int) $item->getID()) as $row) {
            $runs[] = self::runRow($row);
        }

        return new JSONResponse(['runs' => $runs], 200);
    }

    /** One run: its steps, what has been answered, and what is still being asked. */
    #[Route(path: '/runs/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function getRun(Request $request): Response
    {
        $run = self::load($request);
        if ($run instanceof Response) {
            return $run;
        }

        return new JSONResponse(self::runDetail($run), 200);
    }

    /**
     * Answer one step. Body `{value}` — or `{value_itemtype, value_items_id}`
     * for an asset step, `{documents_id}` for a document one.
     *
     * The value is validated by `StepType::normalize()`, which is the only
     * copy of those rules worth trusting: a step marked done is a compliance
     * claim, and the client cannot be the thing that decides one is valid.
     * Its refusals come back as 422 with the message it wrote, because they
     * are all things the technician can fix — a number out of range, an
     * option that is not on the list.
     */
    #[Route(
        path: '/runs/{id}/steps/{steps_id}/answer',
        methods: ['POST'],
        requirements: ['id' => '\d+', 'steps_id' => '\d+']
    )]
    #[RouteVersion(introduced: '2.0')]
    public function answer(Request $request): Response
    {
        $step = self::editableStep($request);
        if ($step instanceof Response) {
            return $step;
        }
        [$run, $step_row] = $step;

        $runs_id  = (int) $run['id'];
        $steps_id = (int) $step_row['id'];
        $type     = (string) $step_row['step_type'];
        $config   = Step::config($step_row);

        $post = [
            'value'          => self::param($request, 'value'),
            'value_itemtype' => (string) self::param($request, 'value_itemtype', ''),
            'value_items_id' => (int) self::param($request, 'value_items_id', 0),
            'documents_id'   => (int) self::param($request, 'documents_id', 0),
        ];

        // A checkbox being unticked is a clear, not an answer — the same
        // decision ajax/run.php makes, kept on this side of the wire so a
        // client does not have to know which control means which action.
        if ($type === StepType::CHECK && !self::truthy($post['value'])) {
            Answer::clear($runs_id, $steps_id);
            RunLog::add($runs_id, RunLog::CLEARED, $steps_id);
            Run::recount($runs_id);

            return new JSONResponse(self::runDetail($run), 200);
        }

        $columns = StepType::normalize($type, $config, $post);
        if (isset($columns['error'])) {
            return new JSONResponse([
                'error'    => 'invalid',
                'message'  => (string) $columns['error'],
                'steps_id' => $steps_id,
            ], 422);
        }

        $existing         = Answer::one($runs_id, $steps_id);
        $columns['state'] = Answer::DONE;
        // Carried, not cleared: answering a step should not silently discard
        // what somebody wrote about it.
        $columns['note']  = $existing['note'] ?? null;

        Answer::put($runs_id, $steps_id, $columns, (int) Session::getLoginUserID());
        RunLog::add($runs_id, RunLog::ANSWERED, $steps_id, StepType::format($type, $columns));
        Run::recount($runs_id);

        return new JSONResponse(self::runDetail($run), 200);
    }

    /** Un-answer a step. */
    #[Route(
        path: '/runs/{id}/steps/{steps_id}/clear',
        methods: ['POST'],
        requirements: ['id' => '\d+', 'steps_id' => '\d+']
    )]
    #[RouteVersion(introduced: '2.0')]
    public function clear(Request $request): Response
    {
        $step = self::editableStep($request);
        if ($step instanceof Response) {
            return $step;
        }
        [$run, $step_row] = $step;

        Answer::clear((int) $run['id'], (int) $step_row['id']);
        RunLog::add((int) $run['id'], RunLog::CLEARED, (int) $step_row['id']);
        Run::recount((int) $run['id']);

        return new JSONResponse(self::runDetail($run), 200);
    }

    /**
     * Skip a step, with a reason. Body `{reason}`.
     *
     * Skipping an already-skipped step clears it, which is what the web
     * checklist's toggle does; the instance decides whether the reason is
     * compulsory, and refusing without one is a 422 rather than a silent
     * no-op.
     */
    #[Route(
        path: '/runs/{id}/steps/{steps_id}/skip',
        methods: ['POST'],
        requirements: ['id' => '\d+', 'steps_id' => '\d+']
    )]
    #[RouteVersion(introduced: '2.0')]
    public function skip(Request $request): Response
    {
        $step = self::editableStep($request);
        if ($step instanceof Response) {
            return $step;
        }
        [$run, $step_row] = $step;

        if (!Settings::flag('allow_skip')) {
            return new JSONResponse(['error' => 'skip_disabled'], 403);
        }

        $runs_id  = (int) $run['id'];
        $steps_id = (int) $step_row['id'];
        $existing = Answer::one($runs_id, $steps_id);

        if ((string) $existing['state'] === Answer::SKIPPED) {
            Answer::clear($runs_id, $steps_id);
            RunLog::add($runs_id, RunLog::CLEARED, $steps_id);
            Run::recount($runs_id);

            return new JSONResponse(self::runDetail($run), 200);
        }

        $reason = trim((string) self::param($request, 'reason', ''));
        if (Settings::flag('require_skip_reason') && $reason === '') {
            return new JSONResponse([
                'error'    => 'reason_required',
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

        return new JSONResponse(self::runDetail($run), 200);
    }

    /** Write (or erase) the note on a step. Body `{note}`. */
    #[Route(
        path: '/runs/{id}/steps/{steps_id}/note',
        methods: ['POST'],
        requirements: ['id' => '\d+', 'steps_id' => '\d+']
    )]
    #[RouteVersion(introduced: '2.0')]
    public function note(Request $request): Response
    {
        $step = self::editableStep($request);
        if ($step instanceof Response) {
            return $step;
        }
        [$run, $step_row] = $step;

        $runs_id  = (int) $run['id'];
        $steps_id = (int) $step_row['id'];
        $existing = Answer::one($runs_id, $steps_id);
        $note     = trim((string) self::param($request, 'note', ''));

        // The state is preserved rather than defaulted: a note on an untouched
        // step must not make it look answered.
        Answer::put($runs_id, $steps_id, [
            'state'          => (string) $existing['state'],
            'value'          => $existing['value'],
            'value_itemtype' => $existing['value_itemtype'],
            'value_items_id' => (int) $existing['value_items_id'],
            'documents_id'   => (int) $existing['documents_id'],
            'note'           => $note !== '' ? $note : null,
        ], (int) Session::getLoginUserID());
        Run::recount($runs_id);

        return new JSONResponse(self::runDetail($run), 200);
    }

    /**
     * Raise the ticket a ticket-step asks for.
     *
     * Refused rather than repeated when one already exists: a duplicate
     * licensing request is a real cost to whoever works that queue, and on a
     * phone a second tap is one bad connection away.
     */
    #[Route(
        path: '/runs/{id}/steps/{steps_id}/spawn',
        methods: ['POST'],
        requirements: ['id' => '\d+', 'steps_id' => '\d+']
    )]
    #[RouteVersion(introduced: '2.0')]
    public function spawn(Request $request): Response
    {
        $step = self::editableStep($request);
        if ($step instanceof Response) {
            return $step;
        }
        [$run, $step_row] = $step;

        if ((string) $step_row['step_type'] !== StepType::TICKET) {
            return new JSONResponse(['error' => 'not_a_ticket_step'], 400);
        }

        $runs_id  = (int) $run['id'];
        $steps_id = (int) $step_row['id'];
        $existing = Answer::one($runs_id, $steps_id);

        if ((int) $existing['value_items_id'] > 0) {
            return new JSONResponse([
                'error'      => 'already_raised',
                'message'    => __('A ticket has already been raised for this step.', 'glpisop'),
                'tickets_id' => (int) $existing['value_items_id'],
            ], 409);
        }

        $item = Run::item($run);
        if ($item === null) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        $config  = Step::config($step_row);
        $spawned = ChildTicket::spawn($step_row, $config, $item);
        if (isset($spawned['error'])) {
            return new JSONResponse([
                'error'   => 'spawn_failed',
                'message' => (string) $spawned['error'],
            ], 422);
        }

        $tickets_id = (int) $spawned['tickets_id'];
        $settles    = ChildTicket::completionMode($config) === ChildTicket::ON_CREATED;

        // Raising the ticket answers the step only when the procedure said that
        // making the request *was* the work. Otherwise the step stays
        // outstanding, which is what lets an enforcing SOP hold the parent open
        // until the child is finished.
        Answer::put($runs_id, $steps_id, [
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

        return new JSONResponse(['tickets_id' => $tickets_id] + self::runDetail($run), 200);
    }

    /**
     * The run's history: who answered what, and when.
     *
     * Reading it needs no write access — it is the audit trail of a record the
     * caller can already see.
     */
    #[Route(path: '/runs/{id}/log', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function log(Request $request): Response
    {
        $run = self::load($request);
        if ($run instanceof Response) {
            return $run;
        }

        $entries = [];
        foreach (RunLog::forRun((int) $run['id']) as $row) {
            $entries[] = [
                'at'     => (string) $row['date_creation'],
                'who'    => RunLog::actorName((int) $row['users_id']),
                'action' => (string) $row['action'],
                'label'  => RunLog::actionLabels()[(string) $row['action']] ?? (string) $row['action'],
                'step'   => Renderer::stepLabel((int) $row['plugin_glpisop_steps_id']),
                'detail' => (string) $row['detail'],
            ];
        }

        return new JSONResponse(['entries' => $entries], 200);
    }

    // -------------------------------------------------------------- helpers

    /** 401/403 unless a central-interface session holding the run right. */
    private static function requireCentral(int $level = READ): ?Response
    {
        if ((int) Session::getLoginUserID() <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }
        if (!Settings::inCentralInterface()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }
        if (!Session::haveRight(Run::$rightname, $level)) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        return null;
    }

    /** An ITIL object this caller may see, and this plugin applies to. */
    private static function viewableItem(string $itemtype, int $items_id): CommonDBTM|Response
    {
        $denied = self::requireCentral();
        if ($denied !== null) {
            return $denied;
        }

        if (!Settings::appliesTo($itemtype) || !class_exists($itemtype)) {
            return new JSONResponse(['error' => 'invalid_item'], 400);
        }

        /** @var CommonDBTM $item */
        $item = new $itemtype();
        if ($items_id <= 0 || !$item->getFromDB($items_id) || !$item->canViewItem()) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        return $item;
    }

    /**
     * The run behind `{id}`, readable by this caller.
     *
     * The item is re-derived from the run rather than taken from the request:
     * without that, this would report the procedure on any ticket in the
     * instance to anyone who can guess a run id.
     *
     * @return array<string,mixed>|Response
     */
    private static function load(Request $request): array|Response
    {
        $denied = self::requireCentral();
        if ($denied !== null) {
            return $denied;
        }

        $run = new Run();
        $runs_id = (int) $request->getAttribute('id');
        if ($runs_id <= 0 || !$run->getFromDB($runs_id)) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        $row      = $run->fields;
        $itemtype = (string) $row['itemtype'];

        if (!Settings::appliesTo($itemtype) || !class_exists($itemtype)) {
            return new JSONResponse(['error' => 'invalid_item'], 400);
        }

        /** @var CommonDBTM $item */
        $item = new $itemtype();
        if (!$item->getFromDB((int) $row['items_id']) || !$item->canViewItem()) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        return $row;
    }

    /**
     * The run and the step for a writing route, both checked.
     *
     * The step is checked against the run's own SOP for the same reason the
     * item is re-derived: a step id from another procedure would otherwise
     * write an answer nobody can see and nobody can clear.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}|Response
     */
    private static function editableStep(Request $request): array|Response
    {
        $run = self::load($request);
        if ($run instanceof Response) {
            return $run;
        }

        if (!Run::isEditable($run)) {
            // One status covers three reasons — no update right on the item, a
            // locked run, a run frozen by age — and the app says which from
            // the flags the detail payload already carries.
            return new JSONResponse(['error' => 'read_only'], 403);
        }

        $steps_id = (int) $request->getAttribute('steps_id');
        $step     = new Step();
        if (
            $steps_id <= 0
            || !$step->getFromDB($steps_id)
            || (int) $step->fields['plugin_glpisop_sops_id'] !== (int) $run['plugin_glpisop_sops_id']
        ) {
            return new JSONResponse(['error' => 'unknown_step'], 400);
        }

        return [$run, $step->fields];
    }

    /** JSON booleans, HTML checkbox strings and plain ints all mean the same here. */
    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * One run as a list row.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function runRow(array $row): array
    {
        $sop = $row['sop'] ?? [];

        return [
            'id'             => (int) $row['id'],
            'sops_id'        => (int) $row['plugin_glpisop_sops_id'],
            'name'           => (string) ($sop['name'] ?? ''),
            'status'         => (string) $row['status'],
            'status_label'   => Renderer::statusLabel((string) $row['status']),
            'itemtype'       => (string) $row['itemtype'],
            'items_id'       => (int) $row['items_id'],
            'origin'         => (string) $row['origin'],
            'total'          => (int) $row['total_visible'],
            'done'           => (int) $row['done_visible'],
            'total_required' => (int) $row['total_required'],
            'done_required'  => (int) $row['done_required'],
            'enforcing'      => Settings::flag('enforce_enabled')
                && (int) ($sop['enforce_on_solve'] ?? 0) === 1,
            'editable'       => Run::isEditable($row),
            'completed_at'   => (string) ($row['completed_at'] ?? ''),
        ];
    }

    /**
     * The whole run, recomputed.
     *
     * Visibility is evaluated here rather than stored, because it depends on
     * answers given seconds ago and on the item's own fields — and a step that
     * is not being asked is not outstanding, which is the rule the counters
     * downstream depend on.
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private static function runDetail(array $run): array
    {
        $runs_id = (int) $run['id'];
        $sops_id = (int) $run['plugin_glpisop_sops_id'];

        // Re-read: a write has just happened, and the counters and status on
        // the row this method was handed are the ones from before it.
        $fresh = new Run();
        $row   = $fresh->getFromDB($runs_id) ? $fresh->fields : $run;

        $sop = new Sop();
        $row['sop'] = $sop->getFromDB($sops_id) ? $sop->fields : [];

        $item      = Run::item($row);
        $sections  = Section::allFor($sops_id);
        $steps     = Step::allFor($sops_id);
        $steps_ids = array_map(static fn(array $s): int => (int) $s['id'], $steps);
        $answers   = Answer::forRun($runs_id, $steps_ids);
        $visible   = Visibility::evaluate($steps, $answers, $item);
        $progress  = Visibility::progress($steps, $answers, $visible);
        $numbers   = Renderer::numbering($steps);

        $out = self::runRow($row) + [
            'description'  => (string) ($row['sop']['content'] ?? ''),
            'version'      => (int) $row['sop_version'],
            'allow_skip'   => Settings::flag('allow_skip'),
            'skip_reason_required' => Settings::flag('require_skip_reason'),
            'locked'       => Run::isLocked($row),
            'frozen'       => Settings::isFrozen($row['completed_at'] ?? null),
            'progress'     => [
                'total'          => (int) $progress['total'],
                'done'           => (int) $progress['done'],
                'total_required' => (int) $progress['total_required'],
                'done_required'  => (int) $progress['done_required'],
                'percent'        => Renderer::percent($progress),
            ],
            'sections'     => [],
            'steps'        => [],
        ];

        foreach ($sections as $sections_id => $section) {
            $out['sections'][] = [
                'id'      => (int) $sections_id,
                'name'    => (string) $section['name'],
                'content' => (string) ($section['content'] ?? ''),
            ];
        }

        foreach ($steps as $step) {
            $steps_id = (int) $step['id'];
            $answer   = $answers[$steps_id];
            $type     = (string) $step['step_type'];
            $config   = Step::config($step);

            $out['steps'][] = [
                'id'          => $steps_id,
                'sections_id' => (int) $step['plugin_glpisop_sections_id'],
                'number'      => (string) ($numbers[$steps_id] ?? ''),
                'label'       => (string) $step['label'],
                'help'        => (string) ($step['help'] ?? ''),
                'type'        => $type,
                'type_label'  => StepType::label($type),
                'required'    => (int) $step['is_required'] === 1,
                'visible'     => (bool) ($visible[$steps_id] ?? false),
                'parent_id'   => Step::primaryParent($step),
                // Cast so a type with no options still encodes as `{}` rather
                // than as `[]`, which a typed client reads as the wrong shape.
                'config'      => (object) self::stepConfig($type, $config),
                'answer'      => [
                    'state'          => (string) $answer['state'],
                    'value'          => $answer['value'] !== null ? (string) $answer['value'] : null,
                    'value_itemtype' => (string) ($answer['value_itemtype'] ?? ''),
                    'value_items_id' => (int) ($answer['value_items_id'] ?? 0),
                    'documents_id'   => (int) ($answer['documents_id'] ?? 0),
                    'note'           => (string) ($answer['note'] ?? ''),
                    // The answer as words: "yes", "3.50", the user's name, the
                    // asset's name. Composed here because the formatter is
                    // here, and because an app rebuilding it would be a second
                    // copy of every step type's meaning.
                    'text'           => Answer::isAnswered($answer)
                        ? StepType::format($type, $answer)
                        : '',
                    'meta'           => Renderer::stepMetaText($answer),
                ],
            ];
        }

        return $out;
    }

    /**
     * What a client needs to render the control for a step type, and nothing
     * else. The authoring config carries more than that — trigger criteria,
     * ticket templates — and none of it is the phone's business.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function stepConfig(string $type, array $config): array
    {
        return match ($type) {
            StepType::CHOICE, StepType::MULTICHOICE => ['options' => StepType::options($config)],
            StepType::YESNO   => ['labels' => StepType::yesNoLabels()],
            StepType::NUMBER  => [
                'min' => isset($config['min']) && $config['min'] !== '' ? (string) $config['min'] : null,
                'max' => isset($config['max']) && $config['max'] !== '' ? (string) $config['max'] : null,
            ],
            StepType::TEXT, StepType::TEXTAREA => [
                'pattern' => (string) ($config['pattern'] ?? ''),
            ],
            StepType::ASSET   => ['itemtypes' => StepType::assetItemtypes($config)],
            default           => [],
        };
    }
}
