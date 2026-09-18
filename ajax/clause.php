<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Re-render the half of a trigger's criterion row that depends on the criterion.
 *
 * The renderer is {@see \GlpiPlugin\Glpisop\ClauseForm}, which is also what
 * paints the row the first time, so there is one implementation per fragment
 * rather than a server version and a JavaScript version that drift.
 *
 * A step's gate used to be served from here too, under a `gate` context. It is
 * not any more: the procedure editor builds a clause's subject and operator
 * from the canvas in front of the author — see public/js/sop-builder.js — and
 * asks ajax/builder.php for the one part it cannot, the value picker.
 *
 * GLPI 11 bootstraps the framework before executing plugin `ajax/` scripts, so
 * there is no includes.php to pull in here.
 *
 * jQuery's `.load()` sends these as POSTs, which is why everything is read from
 * $_REQUEST rather than $_GET. CSRF is already handled: GLPI's global ajaxSend
 * hook puts the token in X-Glpi-Csrf-Token for every POST it makes, and the
 * kernel listener checks that header for XHR requests.
 */

use GlpiPlugin\Glpisop\ClauseForm;
use GlpiPlugin\Glpisop\Trigger;

header('Content-Type: text/html; charset=UTF-8');
Html::header_nocache();

Session::checkRight(Trigger::$rightname, UPDATE);

// ------------------------------------------------------------------ triggers

if ((string) ($_REQUEST['context'] ?? '') === 'trigger') {
    $criterion = (string) ($_REQUEST['criterion'] ?? '');
    if (!isset(Trigger::criteria()[$criterion])) {
        // Not an error page: the select is the only thing that produces this
        // value, so an unknown one means a stale tab. An empty fragment is
        // recoverable — the author picks again — where a die() would leave the
        // row permanently blank.
        return;
    }

    ClauseForm::triggerTail($criterion);
    return;
}
