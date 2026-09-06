<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Add or remove a trigger on an SOP.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpisop\Sop;
use GlpiPlugin\Glpisop\SopTriggerTab;
use GlpiPlugin\Glpisop\Trigger;

Session::checkRight(Trigger::$rightname, UPDATE);

$trigger = new Trigger();

/** Back to the Triggers tab of the SOP being edited. */
$back = static function (int $sops_id): never {
    $sop = new Sop();
    Html::redirect(
        $sop->getFormURLWithID($sops_id) . '&forcetab=' . urlencode(SopTriggerTab::class . '$1')
    );
};

if (isset($_POST['add'])) {
    $sops_id = (int) ($_POST['plugin_glpisop_sops_id'] ?? 0);

    $sop = new Sop();
    if ($sops_id <= 0 || !$sop->getFromDB($sops_id)) {
        Html::displayErrorAndDie(__('Unknown SOP.', 'glpisop'));
    }
    $sop->check($sops_id, UPDATE);

    $criterion = (string) ($_POST['criterion'] ?? '');
    $condition = (string) ($_POST['condition'] ?? '');
    $value     = trim((string) ($_POST['value'] ?? ''));

    // The criterion and condition are re-validated against the registry rather
    // than trusted from the form: a stored trigger nobody can express in the UI
    // is one nobody can debug either.
    if (!isset(Trigger::criteria()[$criterion])) {
        Session::addMessageAfterRedirect(__('Unknown criterion.', 'glpisop'), false, ERROR);
        $back($sops_id);
    }

    if (!isset(Trigger::conditionsFor($criterion)[$condition])) {
        Session::addMessageAfterRedirect(
            __('That condition does not apply to that criterion.', 'glpisop'),
            false,
            ERROR
        );
        $back($sops_id);
    }

    if ($value === '') {
        Session::addMessageAfterRedirect(__('A trigger needs a value.', 'glpisop'), false, ERROR);
        $back($sops_id);
    }

    // A regular expression that does not compile would make the trigger a
    // silent no-op for the rest of its life, so it is checked once here where
    // the author can still see the message.
    if ($condition === Trigger::REGEX) {
        if (@preg_match('/' . str_replace('/', '\/', $value) . '/i', '') === false) {
            Session::addMessageAfterRedirect(
                __('That is not a valid regular expression.', 'glpisop'),
                false,
                ERROR
            );
            $back($sops_id);
        }
    }

    $trigger->add([
        'plugin_glpisop_sops_id' => $sops_id,
        'criterion'              => $criterion,
        'match_condition'        => $condition,
        'value'                  => $value,
        'date_creation'          => date('Y-m-d H:i:s'),
    ]);

    $back($sops_id);
}

if (isset($_POST['purge'])) {
    $triggers_id = (int) ($_POST['id'] ?? 0);
    if ($triggers_id <= 0 || !$trigger->getFromDB($triggers_id)) {
        Html::displayErrorAndDie(__('Unknown trigger.', 'glpisop'));
    }

    $sops_id = (int) $trigger->fields['plugin_glpisop_sops_id'];
    $sop     = new Sop();
    $sop->getFromDB($sops_id);
    $sop->check($sops_id, UPDATE);

    $trigger->delete(['id' => $triggers_id], true);

    $back($sops_id);
}

Html::back();
