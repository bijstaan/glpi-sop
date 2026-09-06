<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Draft a procedure from tickets that were already resolved by hand.
 *
 * Two steps, deliberately separated. Choosing a category and a window is a
 * GET, so the list of candidate tickets is a page an author can look at,
 * narrow, reload and link to; drafting is a POST that costs a provider call
 * and creates something. Collapsing the two into one button would mean the
 * first time anybody sees which tickets were used is after the bill.
 *
 * The tickets are checkboxes rather than a fixed list because the outlier is
 * the whole problem: eleven password resets and one "printer also broken"
 * produce a procedure with a printer step in it, and the author can see that
 * before spending anything.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpisop\Author;
use GlpiPlugin\Glpisop\Authoring;
use GlpiPlugin\Glpisop\Settings;
use GlpiPlugin\Glpisop\Sop;
use GlpiPlugin\Glpisop\SopBuilderTab;
use GlpiPlugin\Glpisop\Url;

Session::checkRight(Sop::$rightname, READ);

$e    = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$cfg  = Settings::all();

// READ opens this page; drafting creates an SOP, which is UPDATE. The two are
// separately grantable, so a profile can legitimately arrive here able to look
// at what was drafted before and not to draft anything new.
$can_draft = Session::haveRight(Sop::$rightname, UPDATE);

$entities_id       = isset($_REQUEST['entities_id'])
    ? (int) $_REQUEST['entities_id']
    : (int) Session::getActiveEntity();

// The entity rides in the form as a hidden field, so it is the client's to
// choose, and everything below — the candidate listing, the provider call it
// is billed against, the SOP that gets written — is scoped by it. Falling back
// rather than refusing: a stale bookmark should land on the session's own
// entity, not an error page. Author::materialise() checks CREATE again on the
// way past; this one saves the wasted model call.
if (!Session::haveAccessToEntity($entities_id)) {
    $entities_id = (int) Session::getActiveEntity();
}
$itilcategories_id = (int) ($_REQUEST['itilcategories_id'] ?? 0);
$days              = (int) ($_REQUEST['days'] ?? $cfg['authoring_window_days']);

if (!empty($_POST['draft'])) {
    Session::checkRight(Sop::$rightname, UPDATE);

    $picked = array_values(array_map('intval', array_keys((array) ($_POST['tickets'] ?? []))));

    $result = Author::draft($picked, $entities_id, $itilcategories_id);

    foreach ($result['notes'] as $note) {
        Session::addMessageAfterRedirect($e($note), false, WARNING);
    }

    if ($result['sops_id'] > 0) {
        Session::addMessageAfterRedirect(
            __s('Drafted, and switched off. Read it before you turn it on.', 'glpisop')
        );

        $sop = new Sop();
        Html::redirect(
            $sop->getFormURLWithID($result['sops_id'])
            . '&forcetab=' . urlencode(SopBuilderTab::class . '$1')
        );
    }

    Session::addMessageAfterRedirect($e($result['error']), false, ERROR);
    Html::back();
}

Html::header(
    __('Draft a procedure', 'glpisop'),
    $_SERVER['PHP_SELF'],
    'config',
    Sop::class
);

echo "<div class='container-fluid glpisop-config' style='max-width:960px'>";

// Status first, per the house convention: whether this can work at all is the
// first thing an author needs, and finding out after choosing a category and a
// window is finding out too late.
$blocked = Author::unavailableReason();
if ($blocked !== null) {
    echo "<div class='alert alert-warning'>" . $e($blocked) . '</div>';
}
if (!$can_draft) {
    echo "<div class='alert alert-info py-2'>"
       . __s('Read only: you can see what was drafted here but not draft anything new.', 'glpisop')
       . '</div>';
}

// --- What this is ---
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Draft from resolved tickets', 'glpisop') . '</h3></div>';
echo "<div class='card-body'>";
echo '<p class="text-muted">'
   . __s('A procedure is drafted from what people actually wrote on tickets that were resolved '
       . 'in one category — the followups, the tasks, the checks any procedure already recorded. '
       . 'It arrives switched off, does not attach to anything, and is yours to edit: this is the '
       . 'first draft of a procedure, not a procedure.', 'glpisop')
   . '</p>';

echo "<form method='get'>";

echo "<div class='row g-3'>";

echo "<div class='col-md-4'><label class='form-label'>" . __s('Entity') . '</label>';
Entity::dropdown([
    'name'  => 'entities_id',
    'value' => $entities_id,
]);
echo "<div class='form-text'>"
   . __s('Tickets are read from this entity and the ones beneath it, and the procedure is '
       . 'created here.', 'glpisop')
   . '</div></div>';

echo "<div class='col-md-4'><label class='form-label'>" . __s('Category') . '</label>';
ITILCategory::dropdown([
    'name'   => 'itilcategories_id',
    'value'  => $itilcategories_id,
    'entity' => $entities_id,
]);
echo "<div class='form-text'>"
   . __s('The category is the cluster and the trigger both: a procedure drafted from these '
       . 'tickets is one that would attach to the next of them.', 'glpisop')
   . '</div></div>';

echo "<div class='col-md-4'><label class='form-label'>" . __s('Resolved within', 'glpisop') . '</label>';
echo "<select name='days' class='form-select'>";
foreach ([30, 90, 180, 365, 730] as $option) {
    echo "<option value='$option'" . ($days === $option ? " selected='selected'" : '') . '>'
       . sprintf(_n('%d day', '%d days', $option, 'glpisop'), $option) . '</option>';
}
echo '</select>';
echo "<div class='form-text'>"
   . __s('Long enough to catch a recurrence; short enough that the procedure describes how the '
       . 'work is done now.', 'glpisop')
   . '</div></div>';

echo '</div>';

echo "<div class='mt-3'>";
echo "<button type='submit' class='btn btn-outline-primary'>"
   . __s('Find tickets', 'glpisop') . '</button>';
echo '</div>';

echo '</form>';
echo '</div></div>';

// --- The candidates ---
if ($itilcategories_id > 0) {
    $candidates = Author::candidates($entities_id, $itilcategories_id, $days);

    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
       . sprintf(
           _n('%d resolved ticket', '%d resolved tickets', count($candidates), 'glpisop'),
           count($candidates)
       )
       . '</h3></div>';
    echo "<div class='card-body'>";

    if ($candidates === []) {
        echo '<p class="text-muted mb-0">'
           . __s('Nothing resolved in that category inside that window. Try a longer one, or a '
               . 'category that sees more work.', 'glpisop')
           . '</p>';
    } else {
        echo '<p class="text-muted">'
           . sprintf(
               __s('Untick anything that is not really an example of the same problem — one '
                   . 'outlier is enough to put a step in the procedure that does not belong '
                   . 'there. At least %d are needed.', 'glpisop'),
               (int) $cfg['authoring_min_tickets']
           )
           . '</p>';

        echo "<form method='post'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('entities_id', ['value' => $entities_id]);
        echo Html::hidden('itilcategories_id', ['value' => $itilcategories_id]);
        echo Html::hidden('days', ['value' => $days]);

        echo "<table class='table table-hover'>";
        echo '<thead><tr>';
        echo "<th style='width:2rem'></th>";
        echo '<th>' . __s('Ticket') . '</th>';
        echo '<th>' . __s('Resolved') . '</th>';
        echo '<th>' . __s('What is written on it', 'glpisop') . '</th>';
        echo '</tr></thead><tbody>';

        $ticket  = new Ticket();
        $usable  = 0;
        $has_ai  = class_exists(\GlpiPlugin\Glpiai\Draft\Evidence::class);

        foreach ($candidates as $row) {
            // What each ticket actually contributes, rather than only that it
            // exists. A category where most tickets carry nothing but a title
            // is one nobody wrote anything down in, and that is worth seeing
            // before a draft comes back thin and unexplained.
            $thin    = false;
            $summary = '';
            if ($has_ai && $ticket->getFromDB($row['id'])) {
                $evidence = \GlpiPlugin\Glpiai\Draft\Evidence::forTicket($ticket);
                $thin     = \GlpiPlugin\Glpiai\Draft\Evidence::isThin($evidence);
                $summary  = \GlpiPlugin\Glpiai\Draft\Evidence::summarise($evidence);
            }

            if (!$thin) {
                $usable++;
            }

            echo '<tr>';
            echo "<td><input type='checkbox' class='form-check-input' name='tickets["
               . (int) $row['id'] . "]' value='1'"
               . ($thin ? '' : " checked='checked'") . '></td>';
            echo "<td><a href='" . $e(Ticket::getFormURLWithID($row['id'])) . "'>#"
               . (int) $row['id'] . ' — ' . $e($row['name']) . '</a></td>';
            echo '<td>' . $e(Html::convDateTime($row['solvedate'])) . '</td>';
            echo '<td>' . ($thin
                ? "<span class='text-muted'>"
                    . __s('Nothing but the title — unticked.', 'glpisop') . '</span>'
                : $e($summary)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($can_draft && $blocked === null) {
            echo "<div class='d-flex'>";
            echo "<button type='submit' name='draft' value='1' class='btn btn-primary ms-auto'"
               . ($usable < (int) $cfg['authoring_min_tickets'] ? " disabled='disabled'" : '')
               . '>' . __s('Draft a procedure from these', 'glpisop') . '</button>';
            echo '</div>';
        }

        echo '</form>';
    }

    echo '</div></div>';
}

// --- What became of the earlier drafts ---
$outcomes = Authoring::outcomes();
if ($outcomes !== []) {
    $totals = Authoring::summary();

    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
       . __s('Drafted before', 'glpisop') . '</h3></div>';
    echo "<div class='card-body'>";
    echo '<p class="text-muted">'
       . sprintf(
           __s('%1$d drafted, %2$d switched on, %3$d deleted. Of %4$d proposed steps, %5$d are '
               . 'still there. A draft nobody switched on is the only accurate review this '
               . 'feature gets.', 'glpisop'),
           $totals['drafted'],
           $totals['activated'],
           $totals['deleted'],
           $totals['steps_created'],
           $totals['steps_kept']
       )
       . '</p>';

    echo "<table class='table'>";
    echo '<thead><tr>';
    echo '<th>' . __s('Procedure', 'glpisop') . '</th>';
    echo '<th>' . __s('Drafted from', 'glpisop') . '</th>';
    echo '<th>' . __s('Steps', 'glpisop') . '</th>';
    echo '<th>' . __s('Status') . '</th>';
    echo '</tr></thead><tbody>';

    $sop = new Sop();
    foreach ($outcomes as $row) {
        echo '<tr>';

        if ($row['deleted']) {
            echo "<td><span class='text-muted'>" . __s('Deleted', 'glpisop') . '</span></td>';
        } else {
            echo "<td><a href='" . $e($sop->getFormURLWithID((int) $row['plugin_glpisop_sops_id']))
               . "'>" . $e($row['sop_name']) . '</a></td>';
        }

        echo '<td>' . sprintf(
            _n('%d ticket', '%d tickets', (int) $row['tickets_count'], 'glpisop'),
            (int) $row['tickets_count']
        ) . ' — ' . $e(Dropdown::getDropdownName(
            'glpi_itilcategories',
            (int) $row['itilcategories_id']
        )) . '</td>';

        echo '<td>' . ($row['deleted']
            ? '—'
            : (int) $row['steps_now'] . ' / ' . (int) $row['steps_created']) . '</td>';

        echo '<td>' . ($row['deleted']
            ? '—'
            : ((int) $row['sop_active'] === 1
                ? "<span class='badge bg-green-lt'>" . __s('Active', 'glpisop') . '</span>'
                : "<span class='badge bg-secondary-lt'>" . __s('Off', 'glpisop') . '</span>'))
           . '</td>';

        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div></div>';
}

echo "<div class='d-flex mb-4'>";
echo "<a href='" . $e(Url::to('front/sop.php')) . "' class='btn btn-outline-secondary'>"
   . __s('SOP library', 'glpisop') . '</a>';
echo '</div>';

echo '</div>';

Html::footer();
