<?php
/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Loads index.php on a core without osc_mail_admin() and prints what it registered, as JSON.
 * tests/load.php runs it in its own process with RAZORPAY_TEST_OLD_CORE set.
 */

require __DIR__ . '/bootstrap.php';

$GLOBALS['__hooks']    = array();
$GLOBALS['__routes']   = array();
$GLOBALS['__settings'] = array();

function osc_plugin_path($file)
{
    return 'razorpay/index.php';
}
function osc_register_plugin($path, $fn)
{
}
function osc_add_hook($name, $fn, $priority = 10)
{
    $GLOBALS['__hooks'][$name][] = $fn;
}
function osc_register_settings_page($id, $spec)
{
    $GLOBALS['__settings'][$id] = true;
}
function osc_add_route_hook($id, $regexp, $url)
{
    $GLOBALS['__routes'][$id] = true;
}
function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

require __DIR__ . '/../../index.php';

ob_start();
foreach ($GLOBALS['__hooks']['admin_page_header'] ?? array() as $fn) {
    $fn();
}
echo json_encode(array(
    'mail_admin' => function_exists('osc_mail_admin'),
    'hooks'      => array_keys($GLOBALS['__hooks']),
    'routes'     => array_keys($GLOBALS['__routes']),
    'settings'   => array_keys($GLOBALS['__settings']),
    'notice'     => ob_get_clean(),
));
