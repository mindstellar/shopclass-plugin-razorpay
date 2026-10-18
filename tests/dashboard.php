<?php
/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The "View payment" link on the admin order screen.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\DashboardLinkGateway;

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);

harness_section('dashboard link');

check('the gateway links to its dashboard', $gateway instanceof DashboardLinkGateway);
pin('a paid order', 'https://dashboard.razorpay.com/app/payments/pay_Abc123', $gateway->dashboardUrl($orders->add(499_000_000, 'INR', 'pay_Abc123', 'paid')));
pin('a refunded order links too', 'https://dashboard.razorpay.com/app/payments/pay_Abc123', $gateway->dashboardUrl($orders->add(499_000_000, 'INR', 'pay_Abc123', 'refunded')));
pin('a pending order has no payment yet', null, $gateway->dashboardUrl($orders->add(499_000_000, 'INR', 'order_Abc')));
pin('nor a failed one', null, $gateway->dashboardUrl($orders->add(499_000_000, 'INR', 'order_Abc', 'failed')));
pin('no ref, no link', null, $gateway->dashboardUrl($orders->add(499_000_000, 'INR', null, 'paid')));
foreach (array('pay_../../account', 'pay_x?y=1', 'pay_', 'order_Abc', 'javascript:alert(1)', 'pay_ab cd', "pay_ab\ncd") as $bad) {
    pin('a bad ref gives no link: ' . json_encode($bad), null, $gateway->dashboardUrl($orders->add(499_000_000, 'INR', $bad, 'paid')));
}
pin('no request reached Razorpay', array(), $http->requests);

harness_section('shipped and delivered orders');

$hasSettled = defined('mindstellar\\billing\\Order::SETTLED');
foreach (array('shipped', 'delivered') as $st) {
    pin('a ' . $st . ' order ' . ($hasSettled ? 'keeps its link' : 'has none on a core without SETTLED'), $hasSettled ? 'https://dashboard.razorpay.com/app/payments/pay_Abc123' : null, $gateway->dashboardUrl($orders->add(499_000_000, 'INR', 'pay_Abc123', $st)));
}

exit(harness_result());
