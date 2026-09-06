<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Create or edit an SOP.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpisop\Sop;

$sop = new Sop();

if (isset($_POST['add'])) {
    $sop->check(-1, CREATE, $_POST);
    if ($newID = $sop->add($_POST)) {
        // Straight to the steps tab: an SOP with no steps does nothing, and
        // the next thing the author wants is always to write one.
        Html::redirect($sop->getFormURLWithID($newID)
            . '&forcetab=' . urlencode(GlpiPlugin\Glpisop\SopBuilderTab::class . '$1'));
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $sop->check((int) $_POST['id'], UPDATE);
    $sop->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $sop->check((int) $_POST['id'], PURGE);
    $sop->delete($_POST, true);
    $sop->redirectToList();
}

Session::checkRight(Sop::$rightname, READ);

// Make `forcetab` work on this page.
//
// GLPI's request listener honours the parameter by deriving an itemtype from
// the URL path, and for a plugin it derives the legacy `pluginglpisopsop`
// form — while a namespaced plugin class registers its tabs under
// `GlpiPlugin\Glpisop\Sop`. The two never match, so the parameter is silently
// dropped and the page always opens on the main tab. That breaks the links
// GLPI generates for its own tab strip *and* every redirect in this plugin's
// authoring flow, which exists to put an author back on the tab they were
// editing. Core has the same problem where a file name differs from its class
// and fixes it the same way — see front/knowbaseitem.php.
if (isset($_GET['forcetab'])) {
    Session::setActiveTab(Sop::class, $_GET['forcetab']);
    unset($_GET['forcetab']);
}

Html::header(
    Sop::getTypeName(1),
    $_SERVER['PHP_SELF'],
    class_exists(\GlpiPlugin\Glpinav\Nav::class)
        ? \GlpiPlugin\Glpinav\Nav::sector(Sop::class, 'config')
        : 'config',
    Sop::class
);

// glpisop-surface: the main form is rendered by core's showFormHeader(), so
// nothing inside it carries a plugin class of its own — without this wrapper,
// sop.css's dark-theme muted-text fixes structurally cannot reach the form's
// .form-text/.text-muted helpers.
echo "<div class='glpisop-surface'>";
$sop->display(['id' => (int) ($_GET['id'] ?? -1)]);
echo '</div>';

Html::footer();
