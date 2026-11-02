<?php
/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Part refunds asked for from the admin (Shopclass 7.1).
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\PartialRefundableGateway;

if (!interface_exists(PartialRefundableGateway::class)) {
    check('skipped: part refunds need Shopclass 7.1', true);
    exit(harness_result());
}

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);
$order   = $orders->add(499_000_000, 'INR', 'pay_Test1', 'paid');
$path    = 'payments/pay_Test1/refund';

harness_section('refund part');

check('the gateway offers part refunds', $gateway instanceof PartialRefundableGateway);

$http->on('POST', $path, 200, array('id' => 'rfnd_p1', 'entity' => 'refund', 'amount' => 10000, 'status' => 'processed'));
$r = $gateway->refundPart($order, 100_000_000, 'order:1:refund:1');
pin('a processed part refund is reported as refunded', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());
pin('with the refund id', array(1, 'rfnd_p1'), array($r->getOrderId(), $r->getExternalRef()));
pin('of the order\'s payment', 'POST ' . $path, $http->lastCall());
pin('for the amount in paise, with core\'s key as the receipt', array(
    'amount'  => 10000,
    'receipt' => 'order:1:refund:1',
    'notes'   => array('shopclass_order' => '1', 'shopclass_refund' => 'order:1:refund:1'),
), $http->lastBody());

$gateway->refundPart($order, 100_000_000, 'order:1:refund:2');
pin('the next part refund has its own receipt', 'order:1:refund:2', $http->lastBody()['receipt']);
pin('and sends it as the idempotency key', 'order:1:refund:2', $http->requests[count($http->requests) - 1]['headers']['X-Refund-Idempotency'] ?? null);

$http->on('POST', $path, 200, array('id' => 'rfnd_p2', 'status' => 'pending'));
$r = $gateway->refundPart($order, 1_000_000, 'order:1:refund:3');
pin('a pending part refund is accepted with its id', array(CallbackResult::OUTCOME_PENDING, 'rfnd_p2', 'Razorpay is processing the refund.'), array($r->getOutcome(), $r->getExternalRef(), $r->getMessage()));

$http->on('POST', $path, 200, array('id' => 'rfnd_p3', 'status' => 'failed'));
pin('a failed part refund changes nothing, and says why', array(CallbackResult::OUTCOME_IGNORED, 'Razorpay did not accept the refund (failed)'), array(
    $gateway->refundPart($order, 1_000_000, 'order:1:refund:4')->getOutcome(),
    $gateway->refundPart($order, 1_000_000, 'order:1:refund:4')->getMessage(),
));

$http->on('POST', $path, 400, array('error' => array('code' => 'BAD_REQUEST_ERROR', 'description' => 'The refund amount provided is greater than amount captured')));
pin('a refusal comes back with Razorpay\'s reason', 'Razorpay answered 400: The refund amount provided is greater than amount captured (BAD_REQUEST_ERROR)', $gateway->refundPart($order, 400_000_000, 'order:1:refund:5')->getMessage());

$throws = static function (callable $fn): bool {
    try {
        $fn();
    } catch (Throwable $e) {
        return true;
    }

    return false;
};
$http->down('POST', $path);
check('Razorpay down throws, so core keeps the key for the retry', $throws(static fn () => $gateway->refundPart($order, 1_000_000, 'order:1:refund:6')));
$http->on('POST', $path, 503, array('error' => array('code' => 'SERVER_ERROR', 'description' => 'Server error')));
check('a Razorpay 5xx throws too', $throws(static fn () => $gateway->refundPart($order, 1_000_000, 'order:1:refund:6')));

harness_section('refused before Razorpay is asked');

$http->on('POST', $path, 200, array('id' => 'rfnd_p9', 'status' => 'processed'));
$count = count($http->requests);
foreach (array(0, -1_000_000, 499_000_001) as $amount) {
    pin('amount ' . $amount . ' is refused', 'the refund must be more than 0 and at most the order total', $gateway->refundPart($order, $amount, 'order:1:refund:7')->getMessage());
}
pin('a part of a paisa is refused', 'amount cannot be refunded in INR', $gateway->refundPart($order, 1_234_567, 'order:1:refund:7')->getMessage());
foreach (array('', 'order 1', str_repeat('k', 41)) as $key) {
    pin('key "' . $key . '" is refused', 'malformed refund key', $gateway->refundPart($order, 1_000_000, $key)->getMessage());
}
$none = $orders->add(499_000_000, 'INR', null, 'paid');
pin('an order with no Razorpay payment is refused', 'no Razorpay payment found for this order', $gateway->refundPart($none, 1_000_000, 'order:2:refund:1')->getMessage());
pin('without calling Razorpay', $count, count($http->requests));

harness_section('orders holding the Razorpay order');

$old = $orders->add(499_000_000, 'INR', 'order_Old', 'paid');
$http->on('GET', 'orders/order_Old/payments', 200, array('entity' => 'collection', 'count' => 1, 'items' => array(
    array('id' => 'pay_Good', 'status' => 'captured'),
)));
$http->on('POST', 'payments/pay_Good/refund', 200, array('id' => 'rfnd_p4', 'status' => 'processed'));
$gateway->refundPart($old, 1_000_000, 'order:3:refund:1');
pin('are refunded through the captured payment', 'POST payments/pay_Good/refund', $http->lastCall());

exit(harness_result());
