<?php
/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The webhook: the signature, what each event means for its order, replays, and what is refused.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\razorpay\RazorpayGateway;
use mindstellar\razorpay\Signature;
use mindstellar\razorpay\Verified;

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);
$order   = $orders->add(499_000_000, 'INR', 'order_Test1');

$decide = static fn (array $event): CallbackResult => $gateway->handleCallback(array(RazorpayGateway::TRUSTED => Verified::event(serve($http, $event))));

harness_section('event mapping');

$r = $decide(event('payment.captured', payment($order)));
pin('payment.captured is paid', CallbackResult::OUTCOME_PAID, $r->getOutcome());
pin('for the order, with Razorpay\'s figures and the payment id', array(1, 'pay_Test1', 499_000_000, 'INR'), array($r->getOrderId(), $r->getExternalRef(), $r->getAmount(), $r->getCurrency()));

$r = $decide(event('order.paid', payment($order), array('order' => array('entity' => array('id' => 'order_Test1', 'status' => 'paid')))));
pin('order.paid is paid', CallbackResult::OUTCOME_PAID, $r->getOutcome());

$r = $decide(event('payment.failed', payment($order, array('status' => 'failed'))));
pin('payment.failed leaves the order open for another try', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
pin('and says why', 'a payment attempt failed', $r->getMessage());

$http->on('POST', 'payments/pay_Test1/capture', 200, payment($order));
$r = $decide(event('payment.authorized', payment($order, array('status' => 'authorized', 'captured' => false))));
pin('payment.authorized is captured, then paid', array(CallbackResult::OUTCOME_PAID, 'POST payments/pay_Test1/capture'), array($r->getOutcome(), $http->lastCall()));

$orders->set(1, Order::STATUS_PAID, 'pay_Test1');
$count = count($http->requests);
$r     = $decide(event('payment.authorized', payment($order, array('status' => 'authorized'))));
pin('an authorized payment for an order already paid is only looked up, not captured', array('order already settled', $count + 1, 'GET payments/pay_Test1'), array($r->getMessage(), count($http->requests), $http->lastCall()));

$refunded = payment($order, array('status' => 'refunded', 'amount_refunded' => 49900, 'refund_status' => 'full'));
$r        = $decide(event('refund.processed', $refunded, array('refund' => array('entity' => array('id' => 'rfnd_1', 'amount' => 49900)))));
pin('a full refund refunds the order', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());
pin('found by the payment id once the order is paid', 1, $r->getOrderId());

$r = $decide(event('refund.processed', payment($order, array('amount_refunded' => 10000, 'refund_status' => 'partial'))));
check('a partial refund does not refund the order', $r->getOutcome() !== CallbackResult::OUTCOME_REFUNDED);

$r = $decide(event('refund.processed', array('amount' => 100, 'amount_refunded' => 100) + $refunded));
pin('a refund for another amount is refused', 'refund for another amount', $r->getMessage());

$r = $decide(array('event' => 'payment.captured', 'payload' => array()));
pin('an event without a payment is ignored', 'event has no payment', $r->getMessage());

harness_section('orders that are not ours');

$r = $decide(event('payment.captured', payment($order, array('order_id' => 'order_OtherSite', 'id' => 'pay_OtherSite'))));
pin('another site on the same Razorpay account', 'not an order of this site', $r->getMessage());
$other = $orders->add(499_000_000, 'INR', 'order_Other', 'pending', 'test');
$r     = $decide(event('payment.captured', payment($other, array('order_id' => 'order_Other'))));
pin('an order of another gateway', 'not an order of this site', $r->getMessage());
$r = $decide(event('payment.captured', array('id' => 'xyz') + payment($order)));
pin('something that is not a payment', 'not a payment', $r->getMessage());

harness_section('the event is only a trigger');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$order   = $orders->add(499_000_000, 'INR', 'order_Test1');
$gateway = fake_gateway($http, $orders);
$trigger = static fn (array $event): CallbackResult => $gateway->handleCallback(array(RazorpayGateway::TRUSTED => Verified::event($event)));

$http->on('GET', 'payments/pay_Test1', 200, payment($order, array('status' => 'refunded', 'amount_refunded' => 49900)));
$r = $trigger(event('payment.captured', payment($order)));
pin('the payment is fetched from Razorpay', 'GET payments/pay_Test1', $http->lastCall());
pin('a stale payment.captured for a payment refunded since does not pay the order', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());

$http->on('GET', 'payments/pay_Test1', 200, payment($order, array('status' => 'failed')));
pin('nor one for a payment Razorpay says failed', 'a payment attempt failed', $trigger(event('order.paid', payment($order)))->getMessage());

$http->on('GET', 'payments/pay_Test1', 200, payment($order, array('amount' => 100)));
pin('Razorpay\'s current amount is what core compares', 1_000_000, $trigger(event('payment.captured', payment($order)))->getAmount());

$count = count($http->requests);
pin('a malformed payment id in the event is not looked up', array('not a payment', $count), array($trigger(event('payment.captured', array('id' => 'pay_../x') + payment($order)))->getMessage(), count($http->requests)));

$http->down('GET', 'payments/pay_Test1');
$threw = false;
try {
    $trigger(event('payment.captured', payment($order)));
} catch (\mindstellar\razorpay\Http\HttpException $e) {
    $threw = true;
}
check('Razorpay down throws, so the webhook queues the payment', $threw);

harness_section('the webhook');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$order   = $orders->add(499_000_000, 'INR', 'order_Test1');
$gateway = settling_gateway($http, $orders);
$paid    = serve($http, event('payment.captured', payment($order)));

deliver($paid);
$r = $gateway->handleCallback(array());
pin('a signed event is settled', array('paid'), array_map(static fn ($x) => $x->getOutcome(), $GLOBALS['settled']));
pin('the order is paid with the payment id', array(Order::STATUS_PAID, 'pay_Test1'), array($orders->find(1)->getStatus(), $orders->find(1)->getExternalRef()));
check('and the route answers 200', !$r->isRetryable());
pin('the payment was fetched from Razorpay once', array('GET payments/pay_Test1'), $http->calls());

$GLOBALS['settled'] = array();
deliver($paid, WHSEC, 'evt_2');
$gateway->handleCallback(array());
deliver(event('order.paid', payment($order)), WHSEC, 'evt_3');
$gateway->handleCallback(array());
pin('a replay and the matching order.paid find the same order', array(1, 1), array_map(static fn ($x) => $x->getOrderId(), $GLOBALS['settled']));
pin('which stays paid once', Order::STATUS_PAID, $orders->find(1)->getStatus());

$GLOBALS['settled'] = array();
deliver($paid, 'wrong_secret');
$r = $gateway->handleCallback(array());
pin('a bad signature is ignored', 'bad signature', $r->getMessage());
check('and never retried', !$r->isRetryable());
pin('nothing settled', array(), $GLOBALS['settled']);

$body = deliver($paid);
$GLOBALS['input'][0] = str_replace('"amount":49900', '"amount":100', $body);
pin('a tampered body is ignored', 'bad signature', $gateway->handleCallback(array())->getMessage());

$GLOBALS['input'] = array(json_encode($paid), '', '');
pin('no signature is ignored, whatever the request claims', 'bad signature', $gateway->handleCallback(array('raw' => $paid, RazorpayGateway::TRUSTED => 'yes'))->getMessage());

deliver(array('entity' => 'collection') + $paid);
pin('a signed body that is not an event is ignored', 'malformed event', $gateway->handleCallback(array())->getMessage());

deliver(event('payment.dispute.created', payment($order)));
pin('an event type we do not handle is ignored', 'event not handled', $gateway->handleCallback(array())->getMessage());

$GLOBALS['input'] = array('not json', Signature::signWebhook('not json', WHSEC), 'evt_9');
pin('a signed body that is not JSON is ignored', 'malformed event', $gateway->handleCallback(array())->getMessage());

harness_section('when settling fails');

$orders = new MemoryOrders();
$order  = $orders->add(499_000_000, 'INR', 'order_Test1');
$broken = fake_gateway($http, $orders, array(), static function (array $request): CallbackResult {
    throw new RuntimeException('MySQL server has gone away');
});
deliver(event('payment.captured', payment($order)), WHSEC, 'evt_42');
$r = $broken->handleCallback(array());
check('Razorpay is told to retry', $r->isRetryable());
pin('and only the payment id and event id are queued', array(array('pay_Test1', 'evt_42')), $GLOBALS['queued']);

$noQueue = new RazorpayGateway(fake_config(), $http, $orders, static fn () => $GLOBALS['input'], static function (array $r): CallbackResult {
    throw new RuntimeException('db down');
}, static function (string $id, string $event): void {
    throw new RuntimeException('queue down too');
});
deliver(event('payment.captured', payment($order)));
check('even when the queue is down too, Razorpay is told to retry', $noQueue->handleCallback(array())->isRetryable());

harness_section('not configured');

$bare = fake_gateway($http, $orders, array('webhook' => ''));
deliver(event('payment.captured', payment($order)));
$r = $bare->handleCallback(array());
pin('without a webhook secret nothing is settled', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
check('but Razorpay keeps the event for later', $r->isRetryable());

exit(harness_result());
