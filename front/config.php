<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpisop\Settings;

Session::checkRight('config', READ);

if (!empty($_POST['update'])) {
    // No explicit Session::checkCSRF: GLPI 11's CheckCsrfListener validated
    // and consumed the token before this page ran, so a second check always
    // fails. The hidden field in the form is what matters.
    Session::checkRight('config', UPDATE);

    Settings::save([
        'itemtypes'            => implode(',', (array) ($_POST['itemtypes'] ?? [])),
        'auto_attach'          => !empty($_POST['auto_attach']) ? '1' : '0',
        'attach_on_update'     => !empty($_POST['attach_on_update']) ? '1' : '0',
        'enforce_enabled'      => !empty($_POST['enforce_enabled']) ? '1' : '0',
        'followup_on_complete' => !empty($_POST['followup_on_complete']) ? '1' : '0',
        'followup_private'     => !empty($_POST['followup_private']) ? '1' : '0',
        'allow_skip'           => !empty($_POST['allow_skip']) ? '1' : '0',
        'require_skip_reason'  => !empty($_POST['require_skip_reason']) ? '1' : '0',
        'lock_on_complete'     => !empty($_POST['lock_on_complete']) ? '1' : '0',
        'lock_after_days'      => (int) ($_POST['lock_after_days'] ?? 0),
        'authoring_enabled'     => !empty($_POST['authoring_enabled']) ? '1' : '0',
        'authoring_window_days' => (int) ($_POST['authoring_window_days'] ?? 180),
        'authoring_max_tickets' => (int) ($_POST['authoring_max_tickets'] ?? 12),
        'authoring_min_tickets' => (int) ($_POST['authoring_min_tickets'] ?? 4),
    ]);

    Session::addMessageAfterRedirect(__s('Settings saved.', 'glpisop'));
    Html::back();
}

Html::header(
    __('SOP checklists', 'glpisop'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$cfg  = Settings::all();
$e    = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = Session::getNewCSRFToken();

/** One checkbox row, with the explanation that makes it decidable. */
$toggle = static function (string $key, string $label, string $help) use ($cfg, $e): void {
    echo "<label class='form-check'>";
    echo "<input type='checkbox' class='form-check-input' name='" . $e($key) . "' value='1' "
       . (((int) $cfg[$key]) === 1 ? "checked='checked'" : '') . '>';
    echo "<span class='form-check-label'>$label</span>";
    echo '</label>';
    echo "<div class='form-text mb-3'>$help</div>";
};

// Read-only visitors keep the page but lose the button.
//
// READ opens this page and UPDATE saves it, and the two are separately
// grantable — so a profile can legitimately arrive here unable to change
// anything. Rendering the form as though they could, and answering Save with an
// access-denied page, wastes the work they just did explaining nothing.
$can_edit = Session::haveRight('config', UPDATE);

// Helper text on the dark palette is handled by sop.css (see its
// "dark theme" section): the inline property override that used to live here
// was a measured no-op for `.text-muted` — core declares it with !important,
// which no property override beats — and its 28% formula measured under the
// 4.5:1 floor for `.form-text` on auror_dark anyway. sop.css redefines the
// `--tblr-muted` / `--tblr-secondary-color` VARIABLES inside the plugin's own
// scope classes instead, `.glpisop-config` among them.
echo "<div class='container-fluid glpisop-config' style='max-width:960px'>";

if (!$can_edit) {
    echo "<div class='alert alert-info py-2'>"
       . __s('Read only: you can see these settings but not change them.', 'glpisop')
       . '</div>';
}
echo "<form method='post'>";
echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);

// --- Scope ---
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Where SOPs apply', 'glpisop') . '</h3></div>';
echo "<div class='card-body'>";
echo '<p class="text-muted">'
   . __s('Procedures are technician workflow — they are shown in the central interface only, and '
       . 'requesters never see the answers.', 'glpisop')
   . '</p>';

$enabled = Settings::itemtypes();
foreach (Settings::SUPPORTED_ITEMTYPES as $itemtype) {
    if (!class_exists($itemtype)) {
        continue;
    }
    $checked = in_array($itemtype, $enabled, true) ? "checked='checked'" : '';
    echo "<label class='form-check form-check-inline'>";
    echo "<input type='checkbox' class='form-check-input' name='itemtypes[]' "
       . "value='" . $e($itemtype) . "' $checked>";
    echo "<span class='form-check-label'>" . $e($itemtype::getTypeName(2)) . '</span>';
    echo '</label>';
}
echo "<div class='form-text mt-2'>"
   . __s('Turning an itemtype off hides its procedures and stops new ones attaching. Runs already '
       . 'recorded are kept, and reappear if it is turned back on.', 'glpisop')
   . '</div>';
echo '</div></div>';

// --- Attachment ---
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('How SOPs arrive', 'glpisop') . '</h3></div>';
echo "<div class='card-body'>";
echo '<p class="text-muted">'
   . __s('Three paths, and they are additive: an SOP’s own triggers, a binding on the ITIL '
       . 'template the item was raised from, and GLPI’s business rules. The switches here only '
       . 'affect the first — a template binding or a rule always attaches.', 'glpisop')
   . '</p>';

$toggle(
    'auto_attach',
    __s('Evaluate SOP triggers', 'glpisop'),
    __s('The master switch for trigger matching. Off, no SOP attaches itself, whatever its own '
        . 'settings say.', 'glpisop')
);

$toggle(
    'attach_on_update',
    __s('Re-evaluate when an item changes', 'glpisop'),
    __s('Triage is where the category is still wrong, so this is where most real attachments '
        . 'happen. Off, an SOP only ever attaches at creation, and recategorising a ticket brings '
        . 'nothing with it.', 'glpisop')
);

echo '</div></div>';

// --- Enforcement ---
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Enforcement', 'glpisop') . '</h3></div>';
echo "<div class='card-body'>";
echo '<p class="text-muted">'
   . __s('An individual SOP decides whether it blocks resolution; this is the switch that lets it. '
       . 'It exists so that a misconfigured procedure holding up a queue is one setting to undo, '
       . 'not an audit of every SOP in the instance.', 'glpisop')
   . '</p>';

$toggle(
    'enforce_enabled',
    __s('Let SOPs block resolution', 'glpisop'),
    __s('When on, an item carrying an enforcing SOP with outstanding required steps cannot be '
        . 'moved to solved or closed, and a solution cannot be filed against it. The solve '
        . 'affordances are also greyed out while that is true, so nobody composes a solution '
        . 'only to have it refused.', 'glpisop')
);

$toggle(
    'allow_skip',
    __s('Let technicians skip a step', 'glpisop'),
    __s('A skip is recorded as an explicit “not done”, not as an answer — a later step gated on '
        . 'this one staying unopened. Off, a required step has exactly one way out.', 'glpisop')
);

$toggle(
    'require_skip_reason',
    __s('Require a reason to skip', 'glpisop'),
    __s('The reason is stored on the step and appears in the completion followup. Has no effect '
        . 'when skipping is switched off.', 'glpisop')
);

$toggle(
    'lock_on_complete',
    __s('Lock a run as soon as it completes', 'glpisop'),
    __s('The moment the last step is answered the procedure stops being a draft and becomes a '
        . 'record. Anyone who could answer it can unlock it again from the checklist, and both '
        . 'the unlock and the re-lock are written to the run’s history — the lock is a speed '
        . 'bump that makes editing a finished record deliberate and visible, not a permission.', 'glpisop')
);

echo "<div class='mb-2' style='max-width:340px'>";
echo "<label class='form-label'>"
   . __s('Freeze a completed run after (days)', 'glpisop') . '</label>';
echo "<input type='number' min='0' class='form-control' name='lock_after_days' "
   . "value='" . $e($cfg['lock_after_days']) . "'>";
echo "<div class='form-text'>"
   . __s('0 keeps runs editable forever. Any other value stops answers being changed once the run '
       . 'is that old — correcting a typo an hour later is maintenance, rewriting a procedure '
       . 'record six months later is not.', 'glpisop')
   . '</div>';
echo '</div>';

echo '</div></div>';

// --- Timeline ---
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('The record', 'glpisop') . '</h3></div>';
echo "<div class='card-body'>";
echo '<p class="text-muted">'
   . __s('The checklist is already a timeline entry, so the followup below is not about making '
       . 'the procedure visible — it is about the record outliving the plugin.', 'glpisop')
   . '</p>';

$toggle(
    'followup_on_complete',
    __s('Also transcribe the finished run into a followup', 'glpisop'),
    __s('Off by default, because it duplicates what the timeline already shows. Turn it on where '
        . 'the answers need to be plain ticket data: a followup survives this plugin being '
        . 'disabled or removed, and it reaches notification emails and printed exports, which a '
        . 'plugin-rendered timeline entry does not. It never changes the item’s status.', 'glpisop')
);

$toggle(
    'followup_private',
    __s('That followup is internal', 'glpisop'),
    __s('SOP answers are written for colleagues, not for the requester. Untick only if the '
        . 'procedure is something the requester is meant to read.', 'glpisop')
);

echo '</div></div>';

// --- Drafting ---
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Drafting a procedure from tickets', 'glpisop') . '</h3></div>';
echo "<div class='card-body'>";
echo '<p class="text-muted">'
   . __s('Where glpi-ai is installed and configured, a first draft of a procedure can be written '
       . 'from tickets in one category that were already resolved by hand — the followups, the '
       . 'tasks, and the checks any procedure recorded. A draft arrives switched off and '
       . 'attached to nothing.', 'glpisop')
   . '</p>';

if (!class_exists(\GlpiPlugin\Glpiai\Client::class)) {
    echo "<div class='alert alert-info py-2'>"
       . __s('glpi-ai is not installed, so these settings do nothing here yet.', 'glpisop')
       . '</div>';
}

$toggle(
    'authoring_enabled',
    __s('Allow procedures to be drafted from resolved tickets', 'glpisop'),
    __s('This sends the content of resolved tickets to whichever provider glpi-ai is configured '
        . 'with, so it is a decision about client data before it is a decision about a feature. '
        . 'glpi-ai’s own per-entity gate still applies on top of it and is not overridden here.',
        'glpisop')
);

echo "<div class='row g-3' style='max-width:720px'>";

echo "<div class='col-md-4'><label class='form-label'>"
   . __s('Look back (days)', 'glpisop') . '</label>';
echo "<input type='number' min='7' max='3650' class='form-control' name='authoring_window_days' "
   . "value='" . $e($cfg['authoring_window_days']) . "'>";
echo "<div class='form-text'>"
   . __s('Long enough to reach a recurrence, short enough that the draft describes how the work '
       . 'is done now.', 'glpisop')
   . '</div></div>';

echo "<div class='col-md-4'><label class='form-label'>"
   . __s('Read at most (tickets)', 'glpisop') . '</label>';
echo "<input type='number' min='2' max='30' class='form-control' name='authoring_max_tickets' "
   . "value='" . $e($cfg['authoring_max_tickets']) . "'>";
echo "<div class='form-text'>"
   . __s('Each ticket is compressed to fit alongside the others. Past a dozen the shared shape '
       . 'stops getting clearer while the prompt keeps growing.', 'glpisop')
   . '</div></div>';

echo "<div class='col-md-4'><label class='form-label'>"
   . __s('Refuse below (tickets)', 'glpisop') . '</label>';
echo "<input type='number' min='1' max='30' class='form-control' name='authoring_min_tickets' "
   . "value='" . $e($cfg['authoring_min_tickets']) . "'>";
echo "<div class='form-text'>"
   . __s('Below this there is no pattern to find, and what comes back is one ticket generalised '
       . '— which reads exactly like a procedure and is not one.', 'glpisop')
   . '</div></div>';

echo '</div>';

echo '</div></div>';

echo "<div class='d-flex mb-4'>";
echo "<a href='" . $e(GlpiPlugin\Glpisop\Url::to('front/sop.php')) . "' class='btn btn-outline-secondary'>"
   . __s('SOP library', 'glpisop') . '</a>';
echo "<a href='" . $e(GlpiPlugin\Glpisop\Url::to('front/overview.php'))
   . "' class='btn btn-outline-secondary ms-2'>" . __s('Adoption', 'glpisop') . '</a>';
if (class_exists(\GlpiPlugin\Glpiai\Client::class)) {
    echo "<a href='" . $e(GlpiPlugin\Glpisop\Url::to('front/author.php'))
       . "' class='btn btn-outline-secondary ms-2'>" . __s('Draft from tickets', 'glpisop') . '</a>';
}
if ($can_edit) {
    echo "<button type='submit' name='update' value='1' class='btn btn-primary ms-auto'>"
       . __s('Save') . '</button>';
}
echo '</div>';

echo '</form></div>';

Html::footer();
