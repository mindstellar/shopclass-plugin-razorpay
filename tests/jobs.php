<?php
/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The daily check of pending orders: missed webhooks, and orders left unpaid.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\Order;
use mindstellar\razorpay\Http\HttpException;
use mindstellar\razorpay\RazorpayGateway;

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = settling_gateway($http, $orders);
$hours   = static fn (int $h): string => date('Y-m-d H:i:s', time() - $h * 3600);

$stale  = $orders->add(499_000_000, 'INR', 'order_Stale', 'pending', 'razorpay', $hours(2));
$fresh  = $orders->add(499_000_000, 'INR', 'order_Fresh', 'pending', 'razorpay', date('Y-m-d H:i:s'));
$noRef  = $orders->add(499_000_000, 'INR', null, 'pending', 'razorpay', $hours(2));
$unpaid = $orders->add(499_000_000, 'INR', 'order_Unpaid', 'pending', 'razorpay', $hours(72));
$auth   = $orders->add(499_000_000, 'INR', 'order_Auth', 'pending', 'razorpay', $hours(3));
$live   = $orders->add(499_000_000, 'INR', 'order_Live', 'pending', 'razorpay', $hours(3), array(RazorpayGateway::META_LIVE => true));

$http->on('GET', 'orders/order_Stale/payments', 200, array('items' => array(
    array('id' => 'pay_Failed', 'status' => 'failed'),
    payment($stale, array('id' => 'pay_Stale', 'order_id' => 'order_Stale')),
)));
$http->on('GET', 'payments/pay_Stale', 200, payment($stale, array('id' => 'pay_Stale', 'order_id' => 'order_Stale')));
$http->on('GET', 'orders/order_Unpaid/payments', 200, array('items' => array()));
$http->on('GET', 'orders/order_Auth/payments', 200, array('items' => array(array('id' => 'pay_Auth', 'status' => 'authorized'))));
$http->on('GET', 'payments/pay_Auth', 200, payment($auth, array('id' => 'pay_Auth', 'order_id' => 'order_Auth', 'status' => 'authorized')));
$http->on('POST', 'payments/pay_Auth/capture', 200, payment($auth, array('id' => 'pay_Auth', 'order_id' => 'order_Auth')));

harness_section('the daily check');

pin('orders over an hour old with a Razorpay order, in this mode, are checked', 3, $gateway->reconcile());
pin('a captured payment Razorpay never reported settles its order', array(Order::STATUS_PAID, 'pay_Stale'), array($orders->find($stale->getId())->getStatus(), $orders->find($stale->getId())->getExternalRef()));
pin('an authorized one is captured and settled', Order::STATUS_PAID, $orders->find($auth->getId())->getStatus());
pin('an order unpaid for two days is closed', Order::STATUS_FAILED, $orders->find($unpaid->getId())->getStatus());
pin('a fresh order is left alone', Order::STATUS_PENDING, $orders->find($fresh->getId())->getStatus());
pin('an order without a Razorpay order is left alone', Order::STATUS_PENDING, $orders->find($noRef->getId())->getStatus());
pin('a live order is not looked up with test keys', Order::STATUS_PENDING, $orders->find($live->getId())->getStatus());
check('nor asked about', !in_array('GET orders/order_Live/payments', $http->calls(), true));

$young = $orders->add(499_000_000, 'INR', 'order_Young', 'pending', 'razorpay', $hours(5));
$http->on('GET', 'orders/order_Young/payments', 200, array('items' => array()));
$gateway->reconcile();
pin('an unpaid order younger than two days stays open', Order::STATUS_PENDING, $orders->find($young->getId())->getStatus());

$http->down('GET', 'orders/order_Young/payments');
$threw = false;
try {
    $gateway->reconcile();
} catch (HttpException $e) {
    $threw = str_contains($e->getMessage(), '#' . $young->getId());
}
check('a failed lookup throws naming the order, so the job retries', $threw);

exit(harness_result());
