<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Bind or unbind an SOP to an ITIL template.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpisop\Sop;
use GlpiPlugin\Glpisop\TemplateBinding;

Session::checkRight(TemplateBinding::$rightname, UPDATE);

$sops_id  = (int) ($_POST['plugin_glpisop_sops_id'] ?? 0);
$itemtype = (string) ($_POST['itemtype'] ?? '');
$items_id = (int) ($_POST['items_id'] ?? 0);

if (!in_array($itemtype, ['TicketTemplate', 'ChangeTemplate', 'ProblemTemplate'], true)) {
    $error = new \Glpi\Exception\Http\BadRequestHttpException();
    $error->setMessageToDisplay(__('Unknown template type.', 'glpisop'));
    throw $error;
}

// The template is checked for update rights, not merely for existence: binding
// a procedure to a template changes what every item raised from it does, which
// is an edit of the template in everything but the column it touches.
$template = new $itemtype();
if ($items_id <= 0 || !$template->getFromDB($items_id)) {
    $error = new \Glpi\Exception\Http\BadRequestHttpException();
    $error->setMessageToDisplay(__('Unknown template.', 'glpisop'));
    throw $error;
}
$template->check($items_id, UPDATE);

$sop = new Sop();
if ($sops_id <= 0 || !$sop->getFromDB($sops_id)) {
    $error = new \Glpi\Exception\Http\BadRequestHttpException();
    $error->setMessageToDisplay(__('Unknown SOP.', 'glpisop'));
    throw $error;
}
$sop->check($sops_id, READ);

if (isset($_POST['bind'])) {
    TemplateBinding::bind($sops_id, $itemtype, $items_id);
} elseif (isset($_POST['unbind'])) {
    TemplateBinding::unbind($sops_id, $itemtype, $items_id);
}

Html::back();
