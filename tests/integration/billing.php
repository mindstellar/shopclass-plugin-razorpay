<?php
/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The gateway against core's real billing layer and a scratch database, with Razorpay faked:
 * a webhook credits once, a replay credits nothing, a wrong amount is refused, the return
 * path and the daily check settle, and a refund followed by its webhook takes credits once.
 *
 * Needs core's scratch MySQL (see core's tests/lib/scratchdb.php). Usage:
 *   SHOPCLASS_CORE=/path/to/core php tests/integration/billing.php
 */

$core = rtrim(getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 3) . '/osclass', '/');
require_once $core . '/tests/lib/scratchdb.php';
require_once $core . '/tests/lib/harness.php';

$admin = scratchdb_session('osc_razorpay_plugin');

if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
function osc_plugins_path()
{
    return PLUGINS_PATH;
}
function osc_register_render_target($id, $path)
{
}
function osc_base_url($with_index = false)
{
    return WEB_PATH . ($with_index ? 'index.php' : '');
}
function _m($key)
{
    return $key;
}
require_once $core . '/tests/lib/stubs.php';
function osc_route_url($id, $args = array())
{
    return WEB_PATH . 'index.php?page=route&route=' . $id . '&' . http_build_query($args);
}
function osc_core_url($name, $args = array())
{
    return \mindstellar\routing\CoreRoutes::url($name, $args);
}
function osc_add_flash_error_message($msg, $section = 'pubMessages')
{
}
$GLOBALS['mails'] = array();
if (!function_exists('osc_mail_admin')) {
    function osc_mail_admin(string $subject, array $lines): bool
    {
        $GLOBALS['mails'][] = array($subject, $lines);

        return true;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hKv.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\razorpay\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
require_once __DIR__ . '/../lib/fakes.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Wallet;
use mindstellar\razorpay\CoreOrderStore;
use mindstellar\razorpay\Plugin;
use mindstellar\razorpay\RazorpayGateway;
use mindstellar\razorpay\Signature;

$status = static fn (Order $order): string => OrderStore::find($order->getId())->getStatus();
$ledger = static function (Order $order) use ($admin): int {
    return (int) $admin->query(
        'SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . "t_billing_ledger WHERE s_ref_type = 'order' AND i_ref_id = " . $order->getId()
    )->fetch_assoc()['c'];
};

harness_section('settings page declaration');

$threw = null;
try {
    osc_register_settings_page(Plugin::PAGE, require __DIR__ . '/../../settings.php');
} catch (InvalidArgumentException $e) {
    $threw = $e->getMessage();
}
pin('core accepts the declaration', null, $threw);
pin('test mode until an admin picks live', 'test', osc_settings_value(Plugin::PAGE, 'mode'));
pin('no keys until an admin adds them', '', (string) osc_settings_value(Plugin::PAGE, 'test_key_secret'));
$fields = \mindstellar\settings\SettingsPageRegistry::getInstance()->fields(Plugin::PAGE);
check('a key id with the wrong prefix is refused', osc_settings_validate($fields['test_key_id'], 'rzp_live_123') !== null);
check('a currency Razorpay does not take is refused', osc_settings_validate($fields['currencies'], 'INR, XYZ') !== null);
osc_set_preference('test_key_secret', 'stored_secret_1', Plugin::PAGE);
osc_reset_preferences();
pin('the settings form never reads a stored secret back', '', osc_settings_value(Plugin::PAGE, 'test_key_secret'));
pin('but the gateway\'s config does', 'stored_secret_1', \mindstellar\razorpay\Config::fromSettings()->keySecret);
Plugin::uninstall();
pin('uninstall removes it', 0, (int) $admin->query('SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . "t_preference WHERE s_section = 'razorpay'")->fetch_assoc()['c']);

harness_section('fixtures');

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

$http    = new FakeHttp();
$store   = new CoreOrderStore();
$queued  = array();
$gateway = new RazorpayGateway(
    fake_config(),
    $http,
    $store,
    static fn (): array => $GLOBALS['input'],
    null, // the real Billing::handleCallback
    static function (string $id) use (&$queued): void {
        $queued[] = $id;
    },
    static function (int $orderId, string $paymentId, string $reason) use (&$alerts): void {
        $alerts[] = array($orderId, $paymentId, $reason);
    }
);
$alerts = array();
PaymentGatewayRegistry::getInstance()->register($gateway);
check('offered at checkout for INR', isset(PaymentGatewayRegistry::getInstance()->available('INR')[RazorpayGateway::ID]));

$buyer    = seed_user($admin, 'buyer', 'buyer@example.test');
$newOrder = static fn (int $amount = 499_000_000, string $currency = 'INR'): Order => OrderStore::create($buyer, RazorpayGateway::ID, $amount, $currency, 100);
$webhook  = static function (array $event, string $id = 'evt_1') use ($http): CallbackResult {
    deliver(serve($http, $event), WHSEC, $id);

    // What core's callback route does with a POST from Razorpay.
    return Billing::handleCallback(RazorpayGateway::ID, array());
};
$remote = static fn (Order $order): array => array('id' => 'order_Test' . $order->getId(), 'entity' => 'order', 'amount' => 49900, 'currency' => 'INR', 'status' => 'created');

harness_section('checkout');

$order = $newOrder();
$http->on('POST', 'orders', 200, $remote($order));
$intent = $gateway->createCheckout($order);
check('the buyer is sent to the pay page', $intent->isRedirect() && $intent->getPayload() === 'https://shop.test/razorpay/pay/' . $order->getId());
pin('the Razorpay order is stored on the order', 'order_Test' . $order->getId(), OrderStore::find($order->getId())->getExternalRef());
pin('which is still pending', Order::STATUS_PENDING, $status($order));
pin('and records the mode', false, OrderStore::find($order->getId())->meta(RazorpayGateway::META_LIVE));
pin('the pay page has Checkout\'s options', 'order_Test' . $order->getId(), $gateway->payOptions(OrderStore::find($order->getId()), $store->buyerEmail($buyer))['order_id'] ?? null);
pin('with the buyer\'s e-mail', 'buyer@example.test', $store->buyerEmail($buyer));

$other = $newOrder();
$intent = $gateway->createCheckout($other); // Razorpay answers with the first order's id
pin('core refuses a Razorpay order another order holds, so the buyer is not sent to pay', 'https://shop.test/buy', $intent->getPayload());
pin('that order is closed', Order::STATUS_FAILED, $status($other));

harness_section('webhook: paid once, replay credits nothing');

$paid = event('payment.captured', payment($order));
$r    = $webhook($paid);
check('the route answers 200', !$r->isRetryable());
pin('the credits land', 100, Wallet::balance($buyer));
pin('the order is paid', Order::STATUS_PAID, $status($order));
pin('the payment id is stored', 'pay_Test' . $order->getId(), OrderStore::find($order->getId())->getExternalRef());
pin('the admin order screen links to it in Razorpay', 'https://dashboard.razorpay.com/app/payments/pay_Test' . $order->getId(), Billing::dashboardUrl(OrderStore::find($order->getId())));

$webhook($paid, 'evt_2');
$webhook(event('order.paid', payment($order)), 'evt_3');
pin('a replay and order.paid credit nothing more', 100, Wallet::balance($buyer));
pin('one ledger row', 1, $ledger($order));

harness_section('webhook: refused');

$short = $newOrder();
$store->attachRef($short->getId(), 'order_Test' . $short->getId());
$webhook(event('payment.captured', payment($short, array('amount' => 100))));
pin('a payment for another amount is refused by core', Order::STATUS_PENDING, $status($short));
$webhook(event('payment.captured', payment($short, array('currency' => 'USD'))));
pin('so is another currency', Order::STATUS_PENDING, $status($short));
deliver(event('payment.captured', payment($short)), 'forged_secret');
Billing::handleCallback(RazorpayGateway::ID, array('raw' => event('payment.captured', payment($short))));
pin('so is a forged signature', Order::STATUS_PENDING, $status($short));
$webhook(event('payment.failed', payment($short, array('status' => 'failed'))));
pin('a failed attempt leaves the order open for another try', Order::STATUS_PENDING, $status($short));
pin('none credited anything', 100, Wallet::balance($buyer));

harness_section('return page');

$back = $newOrder();
$store->attachRef($back->getId(), 'order_Test' . $back->getId());
$http->on('GET', 'payments/pay_Test' . $back->getId(), 200, payment($back));
$sig = Signature::signCheckout('order_Test' . $back->getId(), 'pay_Test' . $back->getId(), KEY_SECRET);
check('Checkout\'s signature checks out', $gateway->verifyReturn(OrderStore::find($back->getId()), 'order_Test' . $back->getId(), 'pay_Test' . $back->getId(), $sig));
$gateway->processPayment('pay_Test' . $back->getId(), $back->getId());
pin('the fetched payment settles the order at once', Order::STATUS_PAID, $status($back));
pin('credits added', 200, Wallet::balance($buyer));
$webhook(event('payment.captured', payment($back)), 'evt_back');
pin('and the webhook that follows adds nothing', 200, Wallet::balance($buyer));

harness_section('daily check');

$stale = $newOrder();
$store->attachRef($stale->getId(), 'order_Test' . $stale->getId());
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_billing_order SET dt_date = NOW() - INTERVAL 2 HOUR WHERE pk_i_id = ' . $stale->getId());
$fresh = $newOrder();
$store->attachRef($fresh->getId(), 'order_Test' . $fresh->getId());
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_billing_order SET dt_date = NOW() - INTERVAL 2 HOUR WHERE pk_i_id = ' . $short->getId());
$due = array_map(static fn (Order $o): int => $o->getId(), $store->stalePending(3600, 100));
sort($due);
pin('only the stale orders are due', array($short->getId(), $stale->getId()), $due);
$http->on('GET', 'orders/order_Test' . $stale->getId() . '/payments', 200, array('items' => array(payment($stale))));
$http->on('GET', 'payments/pay_Test' . $stale->getId(), 200, payment($stale));
$http->on('GET', 'orders/order_Test' . $short->getId() . '/payments', 200, array('items' => array()));
pin('they are checked', 2, $gateway->reconcile());
pin('a payment Razorpay never reported is settled', Order::STATUS_PAID, $status($stale));
pin('an unpaid one under two days old stays open', Order::STATUS_PENDING, $status($short));
pin('the fresh one is left alone', Order::STATUS_PENDING, $status($fresh));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_billing_order SET dt_date = NOW() - INTERVAL 3 DAY WHERE pk_i_id = ' . $short->getId());
$gateway->reconcile();
pin('after two days unpaid it is closed', Order::STATUS_FAILED, $status($short));

$late = $short;
foreach (range(1, 3) as $n) {
    $store->attachRef($newOrder()->getId(), 'order_Busy' . $n);
}
$old = $newOrder();
$store->attachRef($old->getId(), 'order_Test' . $old->getId());
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_billing_order SET dt_date = NOW() - INTERVAL 5 HOUR WHERE pk_i_id = ' . $old->getId());
pin('with a limit, the oldest due order is checked first, however many fresh ones there are', array($old->getId()), array_map(static fn (Order $o): int => $o->getId(), $store->stalePending(3600, 1)));

harness_section('a payment that cannot be credited');

$alerts  = array();
$balance = Wallet::balance($buyer);
$webhook(event('payment.captured', payment($late)), 'evt_late');
pin('a late payment for an order closed unpaid credits nothing', array(Order::STATUS_FAILED, $balance), array($status($late), Wallet::balance($buyer)));
pin('and the admin is told', array(array($late->getId(), 'pay_Test' . $late->getId(), 'the order is failed')), $alerts);
check('the notice goes out once per payment', Plugin::strandedPayment($late->getId(), 'pay_Test' . $late->getId(), 'the order is failed'));
check('not twice', !Plugin::strandedPayment($late->getId(), 'pay_Test' . $late->getId(), 'the order is failed'));
pin('one admin mail for that payment', 1, count(array_filter($GLOBALS['mails'], static fn (array $m): bool => str_contains(implode(' ', $m[1]), 'pay_Test' . $late->getId()))));
check('another payment gets its own', Plugin::strandedPayment($late->getId(), 'pay_Other', 'the order is failed'));

harness_section('refund, then its webhook');

$http->on('POST', 'payments/pay_Test' . $order->getId() . '/refund', 200, array('id' => 'rfnd_1', 'status' => 'processed'));
$paidOrder = OrderStore::find($order->getId());
$r         = Billing::refundThroughGateway($paidOrder);
pin('Razorpay accepts the refund', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());
pin('core takes the credits back', 200, Wallet::balance($buyer));
pin('the order is refunded', Order::STATUS_REFUNDED, $status($order));

$refunded = payment($order, array('status' => 'refunded', 'amount_refunded' => 49900));
$webhook(event('refund.processed', $refunded), 'evt_refund');
pin('the refund.processed webhook that follows takes nothing more', 200, Wallet::balance($buyer));

$webhook(event('refund.processed', payment($back, array('status' => 'refunded', 'amount_refunded' => 49900))), 'evt_refund2');
pin('a refund made in the Razorpay dashboard is recorded too', Order::STATUS_REFUNDED, $status($back));
pin('and takes its credits back', 100, Wallet::balance($buyer));

pin('nothing needed the queue', array(), $queued);

exit(harness_result());
