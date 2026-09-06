<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The SOP library.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpisop\Sop;

Session::checkRight(Sop::$rightname, READ);

Html::header(
    Sop::getTypeName(2),
    $_SERVER['PHP_SELF'],
    class_exists(\GlpiPlugin\Glpinav\Nav::class)
        ? \GlpiPlugin\Glpinav\Nav::sector(Sop::class, 'config')
        : 'config',
    Sop::class
);

Search::show(Sop::class);

Html::footer();
