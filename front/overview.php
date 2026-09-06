<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Adoption: what the published procedures are doing in practice.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpisop\Overview;
use GlpiPlugin\Glpisop\Sop;
use GlpiPlugin\Glpisop\Url;

Session::checkRight(Sop::$rightname, READ);

Html::header(
    __('SOP adoption', 'glpisop'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// glpisop-surface: the scope class sop.css's dark-theme muted-text fixes key
// off — without it this page's .text-muted falls back to core's failing
// dark-palette value.
echo "<div class='container-fluid glpisop-surface' style='max-width:1100px'>";

// ----------------------------------------------------------------- per SOP
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Procedures', 'glpisop') . '</h3></div>';
echo "<div class='card-body'>";

$rows = Overview::bySop();

if ($rows === []) {
    echo "<p class='text-muted mb-0'>" . __s('No SOPs are published yet.', 'glpisop') . '</p>';
} else {
    echo "<table class='table table-sm'>";
    echo '<thead><tr>';
    echo '<th>' . __s('SOP', 'glpisop') . '</th>';
    echo '<th class="text-end">' . __s('Attached', 'glpisop') . '</th>';
    echo '<th class="text-end">' . __s('Complete', 'glpisop') . '</th>';
    echo '<th class="text-end">' . __s('In progress', 'glpisop') . '</th>';
    echo '<th class="text-end">' . __s('Not applicable', 'glpisop') . '</th>';
    // ps-3 so the bar does not butt straight up against the right-aligned
    // count in the column before it.
    echo '<th class="ps-3" style="width:14rem">' . __s('Steps answered', 'glpisop') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $percent = $row['steps_total'] > 0
            ? (int) round(($row['steps_done'] / $row['steps_total']) * 100)
            : 0;

        echo '<tr>';
        echo '<td>';
        echo "<a href='" . $e(Url::to('front/sop.form.php?id=' . $row['id'])) . "'>"
           . $e($row['name']) . '</a>';
        if ($row['is_active'] !== 1) {
            echo " <span class='badge bg-secondary'>" . __s('inactive', 'glpisop') . '</span>';
        }
        if ($row['enforce'] === 1) {
            echo " <span class='badge bg-orange-lt'>" . __s('enforcing', 'glpisop') . '</span>';
        }
        echo '</td>';

        echo "<td class='text-end'>" . (int) $row['total'] . '</td>';
        echo "<td class='text-end'>" . (int) $row['completed'] . '</td>';
        echo "<td class='text-end'>" . (int) $row['in_progress'] . '</td>';
        echo "<td class='text-end'>" . (int) $row['abandoned'] . '</td>';

        echo '<td class="ps-3">';
        echo "<div class='progress' style='height:6px'>";
        echo "<div class='progress-bar' style='width:{$percent}%'></div>";
        echo '</div>';
        echo "<div class='small text-muted'>"
           . $e(sprintf('%d / %d', (int) $row['steps_done'], (int) $row['steps_total']))
           . '</div>';
        echo '</td>';

        echo '</tr>';
    }

    echo '</tbody></table>';
}

echo '</div></div>';

// -------------------------------------------------------- skipped steps
echo "<div class='card mb-4'><div class='card-header'><h3 class='card-title'>"
   . __s('Most-skipped steps', 'glpisop') . '</h3></div>';
echo "<div class='card-body'>";
echo '<p class="text-muted">'
   . __s('A step most technicians skip is usually not a discipline problem. It is a step that asks '
       . 'for something unavailable, unclear, or already done elsewhere — this is where to look '
       . 'first when a procedure is not being followed. The rate is over the runs where the step '
       . 'was actually asked, so a step behind a rarely-open branch is not flattered by a large '
       . 'denominator.', 'glpisop')
   . '</p>';

$skipped = Overview::skippedSteps();

if ($skipped === []) {
    echo "<p class='text-muted mb-0'>" . __s('Nothing has been skipped.', 'glpisop') . '</p>';
} else {
    echo "<table class='table table-sm'>";
    echo '<thead><tr>';
    echo '<th>' . __s('Step', 'glpisop') . '</th>';
    echo '<th>' . __s('SOP', 'glpisop') . '</th>';
    echo '<th class="text-end">' . __s('Skipped', 'glpisop') . '</th>';
    echo '<th class="text-end">' . __s('Of asked', 'glpisop') . '</th>';
    echo '<th class="text-end">' . __s('Rate', 'glpisop') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($skipped as $row) {
        echo '<tr>';
        echo '<td>' . $e($row['label']);
        if ((int) $row['required'] === 1) {
            echo " <span class='sop-required'>*</span>";
        }
        echo '</td>';
        echo "<td><a href='" . $e(Url::to('front/sop.form.php?id=' . $row['sops_id'])) . "'>"
           . $e($row['sop']) . '</a></td>';
        echo "<td class='text-end'>" . (int) $row['skipped'] . '</td>';
        echo "<td class='text-end'>" . (int) $row['answered'] . '</td>';
        echo "<td class='text-end'>" . (int) $row['rate'] . '%</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

echo '</div></div>';

echo '</div>';

Html::footer();
