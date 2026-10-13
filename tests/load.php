<?php
/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The plugin loads without a database and registers what it should, and nothing more.
 */

require __DIR__ . '/lib/bootstrap.php';

$GLOBALS['__hooks']    = array();
$GLOBALS['__routes']   = array();
$GLOBALS['__settings'] = array();
$GLOBALS['__install']  = null;

function osc_plugin_path($file)
{
    return 'razorpay/index.php';
}
function osc_register_plugin($path, $fn)
{
    $GLOBALS['__install'] = array($path, $fn);
}
function osc_add_hook($name, $fn, $priority = 10)
{
    $GLOBALS['__hooks'][$name][] = $fn;
}
function osc_register_settings_page($id, $spec)
{
    $GLOBALS['__settings'][$id] = $spec;
}
function osc_add_route_hook($id, $regexp, $url)
{
    $GLOBALS['__routes'][$id] = array($regexp, $url);
}

require __DIR__ . '/../index.php';

use mindstellar\razorpay\Plugin;

harness_section('what the plugin registers');

pin('install runs Plugin::install', array('razorpay/index.php', array(Plugin::class, 'install')), $GLOBALS['__install']);
pin('uninstall runs Plugin::uninstall', array(array(Plugin::class, 'uninstall')), $GLOBALS['__hooks']['razorpay/index.php_uninstall'] ?? null);
check('the configure link has a handler', isset($GLOBALS['__hooks']['razorpay/index.php_configure']));
pin('the gateway registers only when a page first asks for payment methods', array(array(Plugin::class, 'register')), $GLOBALS['__hooks']['register_payment_gateways'] ?? null);
pin('and not on every page', array(null, null, null), array($GLOBALS['__hooks']['init'] ?? null, $GLOBALS['__hooks']['init_billing_non_secure'] ?? null, $GLOBALS['__hooks']['header'] ?? null));
pin('no script is queued while the plugin loads', false, class_exists('Scripts', false));
pin('the settings page is declared under the plugin id', array('razorpay'), array_keys($GLOBALS['__settings']));

$fields = array();
foreach ($GLOBALS['__settings']['razorpay']['groups'] as $group) {
    foreach ($group['fields'] as $field) {
        $fields[$field['name']] = $field;
    }
}
pin('its fields', array('mode', 'test_key_id', 'test_key_secret', 'test_webhook_secret', 'live_key_id', 'live_key_secret', 'live_webhook_secret', 'webhook_info', 'capture_info', 'name', 'currencies'), array_keys($fields));
$secrets = array_filter($fields, static fn ($f) => $f['type'] === 'secret');
pin('the key and webhook secrets are secret fields', array('test_key_secret', 'test_webhook_secret', 'live_key_secret', 'live_webhook_secret'), array_keys($secrets));
check('every secret is write-only, so it is never drawn back', array_filter($secrets, static fn ($f) => $f['write_only'] !== true) === array());
check('and a blank box keeps the stored one', array_filter($secrets, static fn ($f) => ($f['persist'])('') !== null) === array());
pin('a typed secret is stored trimmed', 'abcdefgh12', ($fields['test_key_secret']['persist'])(' abcdefgh12 '));
pin('test mode until an admin picks live', 'test', $fields['mode']['default']);
pin('a test key id is accepted', null, ($fields['test_key_id']['validate'])('rzp_test_AbC123'));
check('a live key id in the test box is refused', ($fields['test_key_id']['validate'])('rzp_live_AbC123') !== null);
pin('a live key id in the live box is accepted', null, ($fields['live_key_id']['validate'])('rzp_live_AbC123'));
pin('a blank key id is fine', null, ($fields['live_key_id']['validate'])(''));
check('a currency Razorpay does not take is refused', ($fields['currencies']['validate'])('INR, XYZ') !== null);
pin('the currency list is cleaned', 'INR, USD', ($fields['currencies']['sanitize'])('inr, usd ,INR'));

pin('the pay route', array('razorpay/pay/([0-9]+)', 'razorpay/pay/{order}'), $GLOBALS['__routes'][Plugin::ROUTE_PAY] ?? null);
pin('answered by Plugin::payPage', array(array(Plugin::class, 'payPage')), $GLOBALS['__hooks'][Plugin::ROUTE_PAY] ?? null);
pin('the return route', array('razorpay/return', 'razorpay/return'), $GLOBALS['__routes'][Plugin::ROUTE_RETURN] ?? null);
pin('answered by Plugin::returnPage', array(array(Plugin::class, 'returnPage')), $GLOBALS['__hooks'][Plugin::ROUTE_RETURN] ?? null);
pin('jobs, the daily check and the give-up mail', array(
    array(array(Plugin::class, 'registerJobs')),
    array(array(Plugin::class, 'daily')),
    array(array(Plugin::class, 'jobGaveUp')),
), array($GLOBALS['__hooks']['register_jobs'] ?? null, $GLOBALS['__hooks']['cron_daily'] ?? null, $GLOBALS['__hooks']['job_gave_up'] ?? null));
pin('the test-mode notice', array(array(Plugin::class, 'adminNotice')), $GLOBALS['__hooks']['admin_page_header'] ?? null);
check('job types are namespaced', preg_match('/^[a-z0-9_]+\.[a-z0-9_.]+$/', Plugin::JOB_PAYMENT) && preg_match('/^[a-z0-9_]+\.[a-z0-9_.]+$/', Plugin::JOB_RECONCILE));

harness_section('the pay page script');

$js = (string) file_get_contents(__DIR__ . '/../assets/pay.js');
check('vanilla JavaScript, no jQuery', !preg_match('/jQuery|\$\(/', $js));
check('it reads the options from the page, not from inline script', str_contains($js, "getAttribute('data-options')"));
check('Checkout\'s result is posted back with the page\'s form', str_contains($js, 'form.submit()'));
check('error text from Razorpay is shown as text, never HTML', !str_contains($js, 'innerHTML'));

exit(harness_result());
