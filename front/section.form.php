<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Add, reorder or remove a section heading on an SOP.
 *
 * There is no edit form: a section is a name and a sentence, and the builder
 * tab is where both are visible. Removing one keeps its steps — see
 * Section::cleanDBonPurge().
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpisop\Section;
use GlpiPlugin\Glpisop\Sop;
use GlpiPlugin\Glpisop\SopBuilderTab;

Session::checkRight(Section::$rightname, UPDATE);

$section = new Section();

/** Back to the Steps tab of the SOP being edited. */
$back = static function (int $sops_id): never {
    $sop = new Sop();
    Html::redirect(
        $sop->getFormURLWithID($sops_id) . '&forcetab=' . urlencode(SopBuilderTab::class . '$1')
    );
};

if (isset($_POST['add'])) {
    $sops_id = (int) ($_POST['plugin_glpisop_sops_id'] ?? 0);

    $sop = new Sop();
    if ($sops_id <= 0 || !$sop->getFromDB($sops_id)) {
        Html::displayErrorAndDie(__('Unknown SOP.', 'glpisop'));
    }
    $sop->check($sops_id, UPDATE);

    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        Session::addMessageAfterRedirect(__('A section needs a name.', 'glpisop'), false, ERROR);
        $back($sops_id);
    }

    $section->add([
        'plugin_glpisop_sops_id' => $sops_id,
        'name'                   => $name,
        'content'                => (string) ($_POST['content'] ?? ''),
        'rank_order'             => Section::nextRank($sops_id),
        'date_creation'          => date('Y-m-d H:i:s'),
    ]);

    $back($sops_id);
}

if (isset($_POST['move'])) {
    $sections_id = (int) ($_POST['id'] ?? 0);
    if ($sections_id <= 0 || !$section->getFromDB($sections_id)) {
        Html::displayErrorAndDie(__('Unknown section.', 'glpisop'));
    }

    $sops_id = (int) $section->fields['plugin_glpisop_sops_id'];
    $sop     = new Sop();
    $sop->getFromDB($sops_id);
    $sop->check($sops_id, UPDATE);

    Section::reorder($sections_id, (string) $_POST['move'] === 'up' ? 'up' : 'down');

    $back($sops_id);
}

if (isset($_POST['purge'])) {
    $sections_id = (int) ($_POST['id'] ?? 0);
    if ($sections_id <= 0 || !$section->getFromDB($sections_id)) {
        Html::displayErrorAndDie(__('Unknown section.', 'glpisop'));
    }

    $sops_id = (int) $section->fields['plugin_glpisop_sops_id'];
    $sop     = new Sop();
    $sop->getFromDB($sops_id);
    $sop->check($sops_id, UPDATE);

    $section->delete(['id' => $sections_id], true);

    $back($sops_id);
}

Html::back();
