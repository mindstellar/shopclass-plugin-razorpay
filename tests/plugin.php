<?php
/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The pay page, the signed return, and the registered job handlers, run for real against fakes.
 */

require __DIR__ . '/lib/bootstrap.php';
foreach (array('container/ContainerInterface.php', 'container/NotFoundException.php', 'container/Container.php', 'base/Registry.php', 'currency/CurrencyCode.php', 'billing/PaymentGatewayRegistry.php', 'job/Job.php') as $file) {
    require_once core_path() . '/oc-includes/osclass/classes/' . $file;
}

use mindstellar\billing\Order;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\job\Job;
use mindstellar\razorpay\Plugin;
use mindstellar\razorpay\RazorpayGateway;
use mindstellar\razorpay\Signature;

$GLOBALS['billing'] = true;
$GLOBALS['jobs']    = array();
function osc_billing_enabled()
{
    return $GLOBALS['billing'];
}
function osc_job_register_handler($type, $handler)
{
    $GLOBALS['jobs'][$type] = $handler;
}
function osc_job_describe($type, $name, $detail = null)
{
}
function osc_run_hook($hook, ...$args)
{
}
function osc_route_url($id, $args = array())
{
    return 'https://shop.test/' . $id;
}

harness_section('the pay page');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = settling_gateway($http, $orders);
$order   = $orders->add(499_000_000, 'INR', 'order_Test1');
$orders->emails[7] = 'buyer@example.test';

$GLOBALS['flash'] = array();
pin('another user\'s order is refused', null, Plugin::payView($gateway, $orders, 1, 99));
pin('with an error message', 'error', $GLOBALS['flash'][0][0] ?? null);
pin('billing off is refused', null, Plugin::payView(null, $orders, 1, 7));
pin('an order that does not exist is refused', null, Plugin::payView($gateway, $orders, 999, 7));
$other = $orders->add(499_000_000, 'INR', 'order_X', 'pending', 'test');
pin('another gateway\'s order is refused', null, Plugin::payView($gateway, $orders, $other->getId(), 7));

$view = Plugin::payView($gateway, $orders, 1, 7);
pin('the owner gets Checkout\'s options for their order', array('order_Test1', 49900, 'buyer@example.test'), array($view['options']['order_id'], $view['options']['amount'], $view['options']['prefill']['email']));
pin('and the return route', 'https://shop.test/razorpay-return', $view['returnUrl']);
pin('nothing was asked of Razorpay', array(), $http->requests);

$paid = $orders->add(499_000_000, 'INR', 'pay_Done', 'paid');
$GLOBALS['flash'] = array();
pin('a paid order shows no pay button', null, Plugin::payView($gateway, $orders, $paid->getId(), 7));
pin('the buyer is told it is paid', array('ok', 'Order #' . $paid->getId() . ' is paid.'), $GLOBALS['flash'][0] ?? null);

harness_section('the signed return');

$http->on('GET', 'payments/pay_Test1', 200, payment($order));
$sig = Signature::signCheckout('order_Test1', 'pay_Test1', KEY_SECRET);

$GLOBALS['flash'] = array();
pin('another user\'s order is refused', '', Plugin::settleReturn($gateway, $orders, 1, 99, 'order_Test1', 'pay_Test1', $sig));
pin('billing off is refused', '', Plugin::settleReturn(null, $orders, 1, 7, 'order_Test1', 'pay_Test1', $sig));
pin('Razorpay is not asked', array(), $http->requests);

$GLOBALS['flash'] = array();
pin('a forged signature is not looked up', Order::STATUS_PENDING, Plugin::settleReturn($gateway, $orders, 1, 7, 'order_Test1', 'pay_Test1', str_repeat('0', 64)));
pin('a signature for another Razorpay order neither', Order::STATUS_PENDING, Plugin::settleReturn($gateway, $orders, 1, 7, 'order_X', 'pay_Test1', Signature::signCheckout('order_X', 'pay_Test1', KEY_SECRET)));
pin('Razorpay is not asked', array(), $http->requests);
pin('the buyer is told it is processing', 'info', $GLOBALS['flash'][0][0] ?? null);

$http->down('GET', 'payments/pay_Test1');
$GLOBALS['flash'] = array();
pin('Razorpay down is caught; the order stays pending', Order::STATUS_PENDING, Plugin::settleReturn($gateway, $orders, 1, 7, 'order_Test1', 'pay_Test1', $sig));
pin('and the buyer is told it is processing', 'info', $GLOBALS['flash'][0][0] ?? null);

$http->on('GET', 'payments/pay_Test1', 200, payment($order));
$GLOBALS['flash'] = array();
pin('a valid signature, then the payment fetched, ends paid', Order::STATUS_PAID, Plugin::settleReturn($gateway, $orders, 1, 7, 'order_Test1', 'pay_Test1', $sig));
pin('and the buyer sees it at once', array('ok', 'Payment for order #1 received. Credits added.'), $GLOBALS['flash'][0] ?? null);

$count = count($http->requests);
$GLOBALS['flash'] = array();
Plugin::settleReturn($gateway, $orders, 1, 7, 'order_Test1', 'pay_Test1', $sig);
pin('a resubmit for a paid order does not call Razorpay again', $count, count($http->requests));
pin('and just shows it paid', array('ok', 'Order #1 is paid.'), $GLOBALS['flash'][0] ?? null);

harness_section('a closed order keeps its Razorpay order but cannot be paid');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = settling_gateway($http, $orders);
$closed  = $orders->add(499_000_000, 'INR', 'order_Test1', Order::STATUS_FAILED);
$http->on('GET', 'payments/pay_Test1', 200, payment($closed));

$GLOBALS['flash'] = array();
pin('the pay page shows no pay button for a failed order', null, Plugin::payView($gateway, $orders, 1, 7));
pin('and says it was not paid', 'warning', $GLOBALS['flash'][0][0] ?? null);
$sig = Signature::signCheckout('order_Test1', 'pay_Test1', KEY_SECRET);
pin('a validly signed return leaves it failed', Order::STATUS_FAILED, Plugin::settleReturn($gateway, $orders, 1, 7, 'order_Test1', 'pay_Test1', $sig));
pin('without calling Razorpay', array(), $http->requests);

harness_section('the return settles only the order it was posted for');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = settling_gateway($http, $orders);
$mine    = $orders->add(499_000_000, 'INR', 'order_A');
$other   = $orders->add(499_000_000, 'INR', 'order_B');
$http->on('GET', 'payments/pay_X', 200, payment($other, array('id' => 'pay_X', 'order_id' => 'order_B')));
$sig = Signature::signCheckout('order_A', 'pay_X', KEY_SECRET);
pin('a payment that belongs to another order does not settle this one', Order::STATUS_PENDING, Plugin::settleReturn($gateway, $orders, $mine->getId(), 7, 'order_A', 'pay_X', $sig));
pin('nor the other one', Order::STATUS_PENDING, $orders->find($other->getId())->getStatus());

harness_section('the job handlers');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$order   = $orders->add(499_000_000, 'INR', 'order_Test1');
$gateway = settling_gateway($http, $orders);
$http->on('GET', 'payments/pay_Test1', 200, payment($order));
Plugin::$factory = static fn (): RazorpayGateway => $gateway;

Plugin::registerJobs();
check('both job types have a handler', isset($GLOBALS['jobs'][Plugin::JOB_PAYMENT], $GLOBALS['jobs'][Plugin::JOB_RECONCILE]));

$http->on('GET', 'payments/pay_Job9', 200, payment($order, array('id' => 'pay_Job9')));
($GLOBALS['jobs'][Plugin::JOB_PAYMENT])(new Job(array('pk_i_id' => 1), array('payment' => 'pay_Job9', 'event' => 'evt_1')));
pin('the payment job fetches the payment its payload names', array('GET payments/pay_Job9'), $http->calls());
pin('and settles its order', Order::STATUS_PAID, $orders->find(1)->getStatus());
check('the gateway was registered for the job', PaymentGatewayRegistry::getInstance()->get('razorpay') === $gateway);

$GLOBALS['billing'] = false;
$threw              = false;
try {
    ($GLOBALS['jobs'][Plugin::JOB_PAYMENT])(new Job(array('pk_i_id' => 2), array('payment' => 'pay_Test1')));
} catch (RuntimeException $e) {
    $threw = true;
}
check('with billing off the job throws, so it is retried later', $threw);

$count = count($http->requests);
($GLOBALS['jobs'][Plugin::JOB_RECONCILE])(new Job(array('pk_i_id' => 3), array()));
pin('and the daily check does nothing', $count, count($http->requests));

$GLOBALS['billing'] = true;
$stale = $orders->add(499_000_000, 'INR', 'order_Stale', 'pending', 'razorpay', date('Y-m-d H:i:s', time() - 7200));
$http->on('GET', 'orders/order_Stale/payments', 200, array('items' => array(array('id' => 'pay_Stale', 'status' => 'captured'))));
$http->on('GET', 'payments/pay_Stale', 200, payment($stale, array('id' => 'pay_Stale', 'order_id' => 'order_Stale')));
($GLOBALS['jobs'][Plugin::JOB_RECONCILE])(new Job(array('pk_i_id' => 4), array()));
pin('the daily check job settles a stale order', Order::STATUS_PAID, $orders->find($stale->getId())->getStatus());

harness_section('goods orders and settled statuses');

$GLOBALS['flash'] = array();
Plugin::flashOutcome(3, 'pending', 'paid', true);
pin('a goods order is not promised credits', array('ok', 'Payment received for order #3.'), $GLOBALS['flash'][0] ?? null);
$GLOBALS['flash'] = array();
Plugin::flashOutcome(3, 'pending', 'paid');
pin('a credits order still is', array('ok', 'Payment for order #3 received. Credits added.'), $GLOBALS['flash'][0] ?? null);
if (defined('mindstellar\\billing\\Order::SETTLED')) {
    $GLOBALS['flash'] = array();
    Plugin::flashOutcome(3, 'shipped', 'shipped', true);
    pin('a shipped order reads as paid', array('ok', 'Order #3 is paid.'), $GLOBALS['flash'][0] ?? null);
    $GLOBALS['flash'] = array();
    Plugin::flashOutcome(3, 'paid', 'delivered', true);
    pin('so does a delivered one', array('ok', 'Order #3 is paid.'), $GLOBALS['flash'][0] ?? null);
}

exit(harness_result());
