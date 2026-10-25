<?php
/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Refunds asked for from the admin.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\RefundableGateway;

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);
$order   = $orders->add(499_000_000, 'INR', 'pay_Test1', 'paid');

harness_section('refund');

check('the gateway offers refunds', $gateway instanceof RefundableGateway);

$http->on('POST', 'payments/pay_Test1/refund', 200, array('id' => 'rfnd_1', 'entity' => 'refund', 'amount' => 49900, 'status' => 'processed'));
$r = $gateway->refund($order);
pin('a processed refund refunds the order', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());
pin('with the refund id', array(1, 'rfnd_1'), array($r->getOrderId(), $r->getExternalRef()));
pin('of the order\'s payment, in full', 'POST payments/pay_Test1/refund', $http->lastCall());
pin('with a receipt per order, so Razorpay refuses a second refund', array('receipt' => 'refund-order-1', 'notes' => array('shopclass_order' => '1')), $http->lastBody());

$http->on('POST', 'payments/pay_Test1/refund', 200, array('id' => 'rfnd_2', 'status' => 'pending'));
$r = $gateway->refund($order);
pin('a pending refund leaves the order for the webhook', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
pin('and says so', 'Razorpay is processing the refund; the order updates when it completes.', $r->getMessage());

$http->on('POST', 'payments/pay_Test1/refund', 200, array('id' => 'rfnd_3', 'status' => 'failed'));
pin('a failed refund changes nothing, and says why', array(CallbackResult::OUTCOME_IGNORED, 'Razorpay did not accept the refund (failed)'), array($gateway->refund($order)->getOutcome(), $gateway->refund($order)->getMessage()));

$http->on('POST', 'payments/pay_Test1/refund', 400, array('error' => array('code' => 'BAD_REQUEST_ERROR', 'description' => 'The payment has been fully refunded already')));
$r = $gateway->refund($order);
pin('a refusal is shown to the admin with Razorpay\'s reason', array(CallbackResult::OUTCOME_IGNORED, 'Razorpay answered 400: The payment has been fully refunded already (BAD_REQUEST_ERROR)'), array($r->getOutcome(), $r->getMessage()));

$http->down('POST', 'payments/pay_Test1/refund');
pin('Razorpay down is ignored with a reason', 'Could not reach api.razorpay.com: timeout', $gateway->refund($order)->getMessage());

$http->on('POST', 'payments/pay_Test1/refund', 200, array('status' => 'processed'));
pin('an answer without a refund id changes nothing', 'Razorpay did not accept the refund', $gateway->refund($order)->getMessage());

$old = $orders->add(499_000_000, 'INR', 'order_Old', 'paid');
$http->on('GET', 'orders/order_Old/payments', 200, array('entity' => 'collection', 'count' => 2, 'items' => array(
    array('id' => 'pay_Failed', 'status' => 'failed'),
    array('id' => 'pay_Good', 'status' => 'captured'),
)));
$http->on('POST', 'payments/pay_Good/refund', 200, array('id' => 'rfnd_4', 'status' => 'processed'));
$gateway->refund($old);
pin('an order holding only the Razorpay order is refunded through its captured payment', 'POST payments/pay_Good/refund', $http->lastCall());

$none  = $orders->add(499_000_000, 'INR', null, 'paid');
$count = count($http->requests);
pin('an order with no Razorpay payment is refused', 'no Razorpay payment found for this order', $gateway->refund($none)->getMessage());
pin('without calling Razorpay', $count, count($http->requests));

harness_section('the full error reaches the admin');
pin('code, reason, source, step and field', 'BAD_REQUEST_ERROR, reason input_validation_failed, source business, step payment_initiation, field amount', \mindstellar\razorpay\RazorpayApi::errorDetail(array('code' => 'BAD_REQUEST_ERROR', 'description' => 'x', 'reason' => 'input_validation_failed', 'source' => 'business', 'step' => 'payment_initiation', 'field' => 'amount')));
pin('"NA" and empty parts are left out', 'BAD_REQUEST_ERROR', \mindstellar\razorpay\RazorpayApi::errorDetail(array('code' => 'BAD_REQUEST_ERROR', 'reason' => 'NA', 'source' => 'NA', 'step' => '', 'metadata' => array())));
pin('nothing to add', '', \mindstellar\razorpay\RazorpayApi::errorDetail(array()));
$http->on('POST', 'payments/pay_Test9/refund', 400, array('error' => array('code' => 'BAD_REQUEST_ERROR', 'description' => 'invalid request sent', 'reason' => 'refund_not_allowed', 'step' => 'refund_initiation')));
$detailed = $orders->add(499_000_000, 'INR', 'pay_Test9', 'paid');
pin('the admin sees the reason and step too', 'Razorpay answered 400: invalid request sent (BAD_REQUEST_ERROR, reason refund_not_allowed, step refund_initiation)', $gateway->refund($detailed)->getMessage());

exit(harness_result());
