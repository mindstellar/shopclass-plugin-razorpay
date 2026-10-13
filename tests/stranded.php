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

$GLOBALS['kv'] = array();
function osc_kv_claim($group, $key, $ttl, $state = 'locked')
{
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

harness_section('when the admin is told');

$failed = $add(Order::STATUS_FAILED);
$r      = $fetch($failed);
pin('a captured payment for an order closed as failed', array(array(1, 'pay_Test1', 'the order is failed')), $GLOBALS['alerts']);
pin('the result still goes to core, which refuses it', CallbackResult::OUTCOME_PAID, $r->getOutcome());

$GLOBALS['alerts'] = array();
$cancelled = $add(Order::STATUS_CANCELLED);
$fetch($cancelled);
pin('a cancelled order too', 'the order is cancelled', $GLOBALS['alerts'][0][2] ?? null);

$GLOBALS['alerts'] = array();
$pendingShort = $add();
$fetch($pendingShort, array('amount' => 100));
pin('a payment for another amount', array(array($pendingShort->getId(), 'pay_Test' . $pendingShort->getId(), 'the amount or currency differs from the order')), $GLOBALS['alerts']);

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
pin('the flag is kept for 90 days', array('notified', 7776000), $GLOBALS['kv']['razorpay/stranded:pay_Abc'] ?? null);

exit(harness_result());
