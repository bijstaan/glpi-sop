<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */
/**
 * GLPI SOP — interactive, admin-authored standard operating procedures on ITIL
 * objects.
 *
 * An SOP is a versioned template of sections and steps that an administrator
 * writes once; a *run* is one instance of that template living on a particular
 * ticket, change or problem, carrying the answers a technician gave.
 *
 * Two things make it more than a checklist field:
 *
 *  - Steps are typed and answers are structured — a choice, a number, an asset,
 *    a document — so "was the serial recorded" is a query, not a reading
 *    exercise over free text.
 *  - Steps are conditional. A step can be gated on an earlier answer, and a
 *    step that is not visible is not required. Without that rule the completion
 *    count is a lie the moment a procedure has a branch in it.
 *
 * SOPs arrive on an item by themselves: matched from trigger criteria, bound to
 * an ITIL template, or assigned by GLPI's own business rules engine. Nothing
 * here asks a technician to go and find the right procedure.
 *
 * Central interface only. An SOP is internal workflow, and its answers are not
 * shown to requesters.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Glpisop\Settings;
use GlpiPlugin\Glpisop\Sop;
use GlpiPlugin\Glpisop\SopBuilderTab;
use GlpiPlugin\Glpisop\SopTriggerTab;
use GlpiPlugin\Glpisop\TemplateTab;

// Bumped for the procedure editor. No schema change goes with it — but this
// constant is also what GLPI appends to the plugin's script and style URLs
// (Plugin::getPluginFilesVersion()), so leaving it alone would serve every
// technician the cached 0.2.0 sop.css. The editor's layout rules are in that
// file, including the one that makes `hidden` win over Tabler's `display`, and
// without them every step card renders permanently open.
define('PLUGIN_GLPISOP_VERSION', '0.3.0');
define('PLUGIN_GLPISOP_MIN_GLPI', '12.0');

// Settings live under this config context.
define('PLUGIN_GLPISOP_CONFIG_CONTEXT', 'plugin:glpisop');

function plugin_init_glpisop()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpisop'] = true;

    // The plugin's rights, on Administration > Profiles.
    //
    // Core stores a plugin's rights and saves them back with its own, but
    // renders a form for its rights only — so without this tab the ones below
    // are enforced everywhere and grantable nowhere but SQL.
    Plugin::registerClass(\GlpiPlugin\Glpisop\Profile::class, ['addtabon' => ['Profile']]);

    $PLUGIN_HOOKS['config_page']['glpisop'] = 'front/config.php';

    // Authoring lives under Setup, next to the other things an administrator
    // configures rather than under Tools where technicians work.
    $PLUGIN_HOOKS['menu_toadd']['glpisop'] = [
        // The SOP list only. The settings page deliberately has no menu entry
        // of its own: Setup > Plugins already links it, and a second row in the
        // Setup menu pointing at the same page is clutter. The adoption report
        // is a button on that settings page.
        'config' => [Sop::class],
    ];

    // Registering the SOP puts it in the search engine, which is what gives the
    // library filtering, saved searches and CSV export for free.
    Plugin::registerClass(Sop::class, [
        'addtabon' => [Sop::class],
    ]);

    // Authoring tabs on the SOP itself: the steps, what triggers it, and which
    // ITIL templates carry it.
    Plugin::registerClass(SopBuilderTab::class, ['addtabon' => [Sop::class]]);
    Plugin::registerClass(SopTriggerTab::class, ['addtabon' => [Sop::class]]);
    Plugin::registerClass(TemplateTab::class, [
        'addtabon' => ['TicketTemplate', 'ChangeTemplate', 'ProblemTemplate'],
    ]);

    // There is deliberately no tab on the ITIL object. The checklist lives in
    // the timeline — see plugin_glpisop_timeline_items() — and a tab carrying a
    // second copy of it would be both a duplicate to keep in sync and an
    // invitation to go back to ignoring the thing. The run's history is reached
    // from the entry itself.

    // Assets are served from public/; paths are relative to the plugin root.
    //
    // The builder ships alongside the runtime rather than being loaded by the
    // tab that needs it: GLPI renders plugin scripts in the page footer, and
    // the Steps tab arrives over ajax afterwards. A script the tab asked for
    // would land after the inline call that starts the editor.
    $PLUGIN_HOOKS['add_javascript']['glpisop'] = ['js/sop.js', 'js/sop-builder.js'];
    $PLUGIN_HOOKS['add_css']['glpisop']        = 'css/sop.css';

    // Attach on create, and re-evaluate on update — a ticket recategorised from
    // "Other" to "Account lockout" should pick the lockout procedure up, which
    // is the case that matters most since triage is exactly when the category
    // is still wrong.
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['glpisop']    = [];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['glpisop'] = [];
    foreach (Settings::SUPPORTED_ITEMTYPES as $itemtype) {
        $PLUGIN_HOOKS[Hooks::ITEM_ADD]['glpisop'][$itemtype]    = 'plugin_glpisop_item_add';
        $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['glpisop'][$itemtype] = 'plugin_glpisop_item_update';

        // Enforcement: refuse a status change into solved/closed while a
        // required step is outstanding.
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['glpisop'][$itemtype] = 'plugin_glpisop_pre_item_update';
    }

    // Solving through the solution form never touches the parent's status
    // directly, so blocking the status update alone leaves the front door open.
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['glpisop'] = [
        'ITILSolution' => 'plugin_glpisop_pre_solution_add',
    ];

    // A purged ticket must not leave its answers behind.
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['glpisop'] = [];
    foreach (Settings::SUPPORTED_ITEMTYPES as $itemtype) {
        $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['glpisop'][$itemtype] = 'plugin_glpisop_item_purge';
    }

    // The checklist, as an entry in the item's own timeline — and a standing
    // one-line reminder in the fields panel, which does not scroll away with
    // it. See plugin_glpisop_timeline_items() and plugin_glpisop_pre_item_form().
    $PLUGIN_HOOKS[Hooks::TIMELINE_ITEMS]['glpisop']  = 'plugin_glpisop_timeline_items';
    // PRE_ITIL_INFO_SECTION, not PRE_ITEM_FORM: the latter renders inside the
    // "Ticket" accordion body, and core's fields_panel script strips `show`
    // from every section of that panel below 768px — a standing reminder that
    // is display:none on a phone is not a reminder.
    $PLUGIN_HOOKS[Hooks::PRE_ITIL_INFO_SECTION]['glpisop']   = 'plugin_glpisop_pre_item_form';

    // Let GLPI's own rules engine assign an SOP, for administrators who already
    // express their triage there and do not want a second matcher to maintain.
    $PLUGIN_HOOKS[Hooks::USE_RULES]['glpisop'] = ['RuleTicket', 'RuleChange', 'RuleProblem'];

    // The procedures themselves, and the checklists running on items, offered
    // to glpi-ai's assistant as read-only tools. A model that cannot read the
    // site's procedure invents a generic one, and the steps it invents are
    // never the site-specific ones somebody wrote the procedure for.
    //
    // Registered unconditionally: only glpi-ai reads this hook, so an instance
    // without it pays one array assignment and never loads the class. Guarding
    // on Plugin::isPluginActive('glpiai') would run a database lookup on every
    // request to avoid that assignment.
    $PLUGIN_HOOKS['glpiai_tools']['glpisop'] = [\GlpiPlugin\Glpisop\AiTools::class, 'all'];

    // The procedure as a controlled document, and a filled-in run as evidence,
    // offered to glpi-pdf. The run arrives as an *appendix* to the ticket's own
    // export rather than as a file of its own — a checklist without the ticket
    // it was worked on is half a record, and the person who needs both is the
    // auditor.
    //
    // Registered unconditionally, on the same reasoning as the line above: only
    // glpi-pdf reads this hook, and PdfDocument::offers() returns nothing when
    // that plugin is absent.
    $PLUGIN_HOOKS['glpipdf_documents']['glpisop'] = [\GlpiPlugin\Glpisop\PdfDocument::class, 'offers'];

    // The checklist, for the technician app. A procedure is a list of things
    // somebody does while doing them, and a good share of that work happens in
    // a comms cabinet rather than at a desk — so the phone has to be able to
    // answer it, or the answers get written up afterwards from memory.
    $PLUGIN_HOOKS['api_controllers']['glpisop'] = [\GlpiPlugin\Glpisop\MobileController::class];

    // Feature discovery for glpi-mobile's /capabilities endpoint, evaluated
    // per session by that plugin — rights checks only, nothing that touches
    // the database.
    $PLUGIN_HOOKS['glpimobile_capabilities']['glpisop'] = 'plugin_glpisop_mobile_capabilities';
}

function plugin_version_glpisop()
{
    return [
        'name'         => 'GLPI SOP',
        'version'      => PLUGIN_GLPISOP_VERSION,
        'author'       => 'Bijstaan',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/bijstaan/glpi-sop',
        'requirements' => ['glpi' => ['min' => PLUGIN_GLPISOP_MIN_GLPI]],
    ];
}

function plugin_glpisop_check_prerequisites()
{
    return true;
}

function plugin_glpisop_check_config($verbose = false)
{
    return true;
}

/**
 * What of this plugin the mobile app may show the calling user.
 *
 * `runs` is whether the checklist may be *seen*; `answer` is whether it may be
 * written to. They are separate because a read-only technician has a genuine
 * use for the first — knowing what the procedure asks, and what has already
 * been answered — and the app should show them that rather than nothing.
 *
 * @return array{version:string,features:array<string,bool>}
 */
function plugin_glpisop_mobile_capabilities(): array
{
    $central = Session::getCurrentInterface() === 'central';

    return [
        'version'  => PLUGIN_GLPISOP_VERSION,
        'features' => [
            'runs'   => $central && (bool) Session::haveRight(\GlpiPlugin\Glpisop\Run::$rightname, READ),
            'answer' => $central && (bool) Session::haveRight(\GlpiPlugin\Glpisop\Run::$rightname, UPDATE),
        ],
    ];
}
