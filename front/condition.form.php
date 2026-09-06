<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Add or remove one clause of a step's gate, or change how its clauses join.
 *
 * There is no edit form. A clause is three small choices — what is tested, how,
 * and against what — and each one decides which control the next one needs; an
 * editor for it would be the add row again with a delete button attached.
 * Removing and re-adding is the same number of clicks and leaves no
 * half-changed clause behind.
 *
 * Everything returns to the step editor, which is where the author is working.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpisop\Condition;
use GlpiPlugin\Glpisop\Run;
use GlpiPlugin\Glpisop\Sop;
use GlpiPlugin\Glpisop\Step;
use GlpiPlugin\Glpisop\Url;

Session::checkRight(Step::$rightname, UPDATE);

$condition = new Condition();

/** Back to the editor of the step whose gate this is. */
$back = static function (int $steps_id): never {
    Html::redirect(Url::to('front/step.form.php?id=' . $steps_id));
};

/**
 * The SOP a step belongs to, checked for update.
 *
 * Authority over a clause is authority over the procedure it is part of —
 * there is no separate right for gates, and there should not be: a condition
 * decides whether a required step is asked, which is the same power as adding
 * or removing the step.
 */
$authorise = static function (int $steps_id): array {
    $step = new Step();
    if ($steps_id <= 0 || !$step->getFromDB($steps_id)) {
        Html::displayErrorAndDie(__('Unknown step.', 'glpisop'));
    }

    $sops_id = (int) $step->fields['plugin_glpisop_sops_id'];
    $sop     = new Sop();
    if (!$sop->getFromDB($sops_id)) {
        Html::displayErrorAndDie(__('Unknown SOP.', 'glpisop'));
    }
    $sop->check($sops_id, UPDATE);

    return [$step, $sop];
};

// ------------------------------------------------------------------ actions

if (isset($_POST['add'])) {
    $steps_id      = (int) ($_POST['plugin_glpisop_steps_id'] ?? 0);
    [$step, $sop]  = $authorise($steps_id);
    $sops_id       = (int) $step->fields['plugin_glpisop_sops_id'];

    // The add row asks what is being tested as one choice — "step:21",
    // "field:urgency", "approval:global_validation" — because source and
    // subject are one question to the author. See Condition::subjects().
    [$source, $target] = Condition::splitSubject((string) ($_POST['subject'] ?? ''));

    if (!array_key_exists($source, Condition::sources())) {
        Session::addMessageAfterRedirect(
            __('That is not something a step can be gated on.', 'glpisop'),
            false,
            ERROR
        );
        $back($steps_id);
    }

    $row = [
        'plugin_glpisop_steps_id' => $steps_id,
        'source'                  => $source,
        'depends_steps_id'        => 0,
        'criterion'               => '',
        'match_condition'         => (string) ($_POST['match_condition'] ?? ''),
        'value'                   => (string) ($_POST['value'] ?? ''),
        'rank_order'              => Condition::nextRank($steps_id),
        'date_creation'           => date('Y-m-d H:i:s'),
    ];

    if ($source === Condition::SRC_STEP) {
        // A clause may only point at an *earlier* step. Anything else is either
        // a cycle — two steps each waiting on the other, both permanently
        // invisible — or a forward reference that can never be satisfied.
        $parent_id = (int) $target;
        $allowed   = array_map(
            static fn(array $candidate): int => (int) $candidate['id'],
            Step::candidateParents($sops_id, $steps_id)
        );

        if ($parent_id <= 0 || !in_array($parent_id, $allowed, true)) {
            Session::addMessageAfterRedirect(
                __('A step can only be gated on a step that comes before it.', 'glpisop'),
                false,
                ERROR
            );
            $back($steps_id);
        }

        if (!array_key_exists($row['match_condition'], Step::operators())) {
            Session::addMessageAfterRedirect(
                __('Pick a condition for that step’s answer.', 'glpisop'),
                false,
                ERROR
            );
            $back($steps_id);
        }

        $row['depends_steps_id'] = $parent_id;

        // The value belongs to the operator, not to the clause. Storing one
        // beside "was answered" would show up in the description as a
        // comparison that is not happening.
        if (!Step::operatorNeedsValue($row['match_condition'])) {
            $row['value'] = '';
        }
    } else {
        $criterion = $target;
        if (!array_key_exists($criterion, Condition::criteriaFor($source, $sop->itemtypes()))) {
            Session::addMessageAfterRedirect(
                __('That criterion does not apply to what this SOP is written for.', 'glpisop'),
                false,
                ERROR
            );
            $back($steps_id);
        }

        if (!array_key_exists($row['match_condition'], Condition::operatorsFor($source, $criterion))) {
            Session::addMessageAfterRedirect(
                __('Pick a condition for that criterion.', 'glpisop'),
                false,
                ERROR
            );
            $back($steps_id);
        }

        // An id-valued criterion needs a real id. The pickers can come up empty
        // — an instance with no categories yet offers only the blank row — and
        // a clause saved against 0 reads as "Category is: 0" and can never
        // hold, which is a worse outcome than being told to pick something.
        $definition = Condition::definition($source, $criterion) ?? ['kind' => 'text'];
        $blank      = in_array($definition['kind'], ['dropdown', 'actor_group'], true)
            ? (int) $row['value'] <= 0
            : trim($row['value']) === '';

        if ($blank) {
            Session::addMessageAfterRedirect(
                __('That condition needs a value.', 'glpisop'),
                false,
                ERROR
            );
            $back($steps_id);
        }

        $row['criterion'] = $criterion;
    }

    $condition->add($row);

    // The gate changed, so what every run of this SOP is being asked changed
    // with it. Bumping the version and recounting is the same work a structural
    // edit to the step itself does — see Step::post_updateItem().
    Sop::bumpVersion($sops_id);
    Run::recountAllFor($sops_id);

    $back($steps_id);
}

if (isset($_POST['mode'])) {
    $steps_id     = (int) ($_POST['plugin_glpisop_steps_id'] ?? 0);
    [$step, $sop] = $authorise($steps_id);

    $mode = (string) ($_POST['depends_mode'] ?? Condition::MODE_ALL);

    $step->update([
        'id'           => $steps_id,
        'depends_mode' => $mode === Condition::MODE_ANY ? Condition::MODE_ANY : Condition::MODE_ALL,
    ]);

    $back($steps_id);
}

if (isset($_POST['purge'])) {
    $conditions_id = (int) ($_POST['id'] ?? 0);
    if ($conditions_id <= 0 || !$condition->getFromDB($conditions_id)) {
        Html::displayErrorAndDie(__('Unknown condition.', 'glpisop'));
    }

    $steps_id     = (int) $condition->fields['plugin_glpisop_steps_id'];
    [$step, $sop] = $authorise($steps_id);
    $sops_id      = (int) $step->fields['plugin_glpisop_sops_id'];

    $condition->delete(['id' => $conditions_id], true);

    Sop::bumpVersion($sops_id);
    Run::recountAllFor($sops_id);

    $back($steps_id);
}

Html::back();
