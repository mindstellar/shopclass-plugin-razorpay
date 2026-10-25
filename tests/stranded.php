<?php
/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * A captured payment core will not credit: the admin is told, once per payment.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\razorpay\Plugin;
use mindstellar\razorpay\RazorpayGateway;

$GLOBALS['kv'] = array();
$GLOBALS['kvDown'] = false;
function osc_kv_claim($group, $key, $ttl, $state = 'locked')
{
    if ($GLOBALS['kvDown']) {
        throw new RuntimeException('t_key_value is missing');
    }
    if (isset($GLOBALS['kv'][$group . '/' . $key])) {
        return false;
    }
    $GLOBALS['kv'][$group . '/' . $key] = array($state, $ttl);

    return true;
}

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);
$fetch   = static function (Order $order, array $over = array()) use ($http, $gateway): CallbackResult {
    $payment = payment($order, $over);
    $http->on('GET', 'payments/' . $payment['id'], 200, $payment);

    return $gateway->processPayment($payment['id']);
};

// An order whose ref is "order_Test<id>", or "pay_Test<id>" once paid, as payment() expects.
$add = static function (string $status = Order::STATUS_PENDING, string $prefix = 'order_Test') use ($orders): Order {
    $order = $orders->add(499_000_000, 'INR', null, $status);
    $orders->set($order->getId(), $status, $prefix . $order->getId());

    return $orders->find($order->getId());
};

harness_section('a payment for a closed order is refunded');

$failed = $add(Order::STATUS_FAILED);
$http->on('POST', 'payments/pay_Test1/refund', 200, array('id' => 'rfnd_Late1', 'status' => 'processed'));
$r = $fetch($failed);
pin('a captured payment for an order closed as failed is not reported as paid', array(CallbackResult::OUTCOME_IGNORED, 'payment for a closed order; refunded'), array($r->getOutcome(), $r->getMessage()));
pin('it is refunded at Razorpay at once, keyed on the order', array('POST payments/pay_Test1/refund', 'closed-order-1'), array($http->lastCall(), $http->lastBody()['receipt'] ?? null));
pin('the refund is noted on the order', 'rfnd_Late1', $orders->find(1)->meta(RazorpayGateway::META_CLOSED_REFUND));
pin('no admin notice is needed', array(), $GLOBALS['alerts']);
$calls = count($http->requests);
pin('a replay asks for no second refund', array('payment for a closed order was already handled', $calls + 1), array($fetch($orders->find(1))->getMessage(), count($http->requests)));

$cancelled = $add(Order::STATUS_CANCELLED);
$http->on('POST', 'payments/pay_Test2/refund', 200, array('id' => 'rfnd_Late2', 'status' => 'pending'));
pin('a cancelled order too', 'payment for a closed order; refunded', $fetch($cancelled)->getMessage());

$refused = $add(Order::STATUS_CANCELLED);
$http->on('POST', 'payments/pay_Test3/refund', 400, array('error' => array('code' => 'BAD_REQUEST_ERROR', 'description' => 'Refund not allowed')));
pin('a refund Razorpay refuses goes to the admin', array('payment for a closed order; refund refused', array(array(3, 'pay_Test3', 'the order is cancelled and Razorpay refused the refund: Razorpay answered 400: Refund not allowed'))), array($fetch($refused)->getMessage(), $GLOBALS['alerts']));

$down = $add(Order::STATUS_CANCELLED);
$http->down('POST', 'payments/pay_Test4/refund');
try {
    $fetch($down);
    $thrown = 'nothing';
} catch (Throwable $e) {
    $thrown = $e->getMessage();
}
pin('a refund that could not be asked for throws, so it is retried', 'Could not reach api.razorpay.com: timeout', $thrown);
pin('and nothing is noted', null, $orders->find(4)->meta(RazorpayGateway::META_CLOSED_REFUND));

harness_section('when the admin is told');

$GLOBALS['alerts'] = array();
$pendingShort = $add();
$fetch($pendingShort, array('amount' => 100));
pin('a payment for another amount', array(array($pendingShort->getId(), 'pay_Test' . $pendingShort->getId(), 'the amount or currency differs from the order')), $GLOBALS['alerts']);

$GLOBALS['alerts'] = array();
$usd = $add();
$fetch($usd, array('currency' => 'USD'));
pin('a payment of the same figure in another currency', array(array($usd->getId(), 'pay_Test' . $usd->getId(), 'the amount or currency differs from the order')), $GLOBALS['alerts']);

harness_section('when nothing is wrong');

$GLOBALS['alerts'] = array();
$results = array(
    $fetch($add()),
    $fetch($add(Order::STATUS_PAID, 'pay_Test')),
    $fetch($add(Order::STATUS_PAID)),
    $fetch($add(Order::STATUS_REFUNDED, 'pay_Test')),
);
pin('each was found and read', array('paid', 'paid', 'paid', 'paid'), array_map(static fn (CallbackResult $r): string => $r->getOutcome(), $results));
pin('a pending order, a replay for its paid order, a hand-marked paid order and a refunded one tell no one', array(), $GLOBALS['alerts']);

harness_section('the notice');

check('the first notice for a payment is sent', Plugin::strandedPayment(4, 'pay_Abc', 'the order is failed'));
pin('through core\'s admin mail', 'Razorpay: a payment needs checking', $GLOBALS['mails'][0][0] ?? null);
check('naming the payment and the order', str_contains(implode(' ', $GLOBALS['mails'][0][1]), 'pay_Abc') && str_contains(implode(' ', $GLOBALS['mails'][0][1]), '#4'));
check('the next for the same payment is not', !Plugin::strandedPayment(4, 'pay_Abc', 'the order is failed'));
pin('one mail', 1, count($GLOBALS['mails']));
check('another payment is told about', Plugin::strandedPayment(5, 'pay_Def', 'the order is failed'));
$GLOBALS['kvDown'] = true;
$mailCount = count($GLOBALS['mails']);
try {
    $sent = Plugin::strandedPayment(6, 'pay_Ghi', 'the order is failed');
} catch (Throwable $e) {
    $sent = $e->getMessage();
}
pin('when the flag cannot be stored, the notice still goes out', true, $sent);
pin('by mail', $mailCount + 1, count($GLOBALS['mails']));
$GLOBALS['kvDown'] = false;
pin('the flag is kept for 90 days', array('notified', 7776000), $GLOBALS['kv']['razorpay/stranded:pay_Abc'] ?? null);

harness_section('shipped and delivered orders');

if (defined('mindstellar\\billing\\Order::SETTLED')) {
    $GLOBALS['alerts'] = array();
    foreach (array('shipped', 'delivered') as $st) {
        $o = $add($st);
        $orders->set($o->getId(), $st, 'pay_Test' . $o->getId());
        $fetch($orders->find($o->getId()));
        $o2 = $add($st, 'order_Test');
        $fetch($o2);
    }
    pin('a late webhook for a shipped or delivered order is not stranded', array(), $GLOBALS['alerts']);
}

exit(harness_result());
