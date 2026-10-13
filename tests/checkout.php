<?php
/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * isConfigured(), creating the Razorpay order, the pay page's options, and the return check.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\razorpay\Config;
use mindstellar\razorpay\Http\HttpException;
use mindstellar\razorpay\RazorpayGateway;
use mindstellar\razorpay\Signature;

harness_section('isConfigured');

$http   = new FakeHttp();
$orders = new MemoryOrders();
check('ready with a key id, key secret and webhook secret', fake_gateway($http, $orders)->isConfigured());
check('not without the key id', !fake_gateway($http, $orders, array('key' => ''))->isConfigured());
check('not without the key secret', !fake_gateway($http, $orders, array('secret' => ''))->isConfigured());
check('not without the webhook secret', !fake_gateway($http, $orders, array('webhook' => ''))->isConfigured());
check('not when the billing currency is not allowed', !fake_gateway($http, $orders, array('currencies' => array('USD')))->isConfigured());
check('yes when it is', fake_gateway($http, $orders, array('currencies' => array('USD', 'INR')))->isConfigured());
check('in test mode, not offered to a buyer who is not an admin', !fake_gateway($http, $orders, array('admin' => false))->isConfigured());
check('but webhooks and lookups still work for them', fake_gateway($http, $orders, array('admin' => false))->hasKeys());
check('in live mode, offered to everyone', fake_gateway($http, $orders, array('mode' => 'live', 'admin' => false))->isConfigured());
pin('the billing currency when none are listed', array('INR'), fake_gateway($http, $orders)->getSupportedCurrencies());
pin('nothing when Razorpay does not take the billing currency', array(), fake_gateway($http, $orders, array('billing' => 'XYZ'))->getSupportedCurrencies());
check('which leaves it unconfigured', !fake_gateway($http, $orders, array('billing' => 'XYZ'))->isConfigured());
pin('a list from settings keeps only codes Razorpay takes', array('INR', 'USD'), Config::parseCurrencies('inr, usd, nope, INR'));

harness_section('createCheckout');

$order = $orders->add(499_000_000, 'INR');
$http->on('POST', 'orders', 200, array('id' => 'order_Test1', 'entity' => 'order', 'amount' => 49900, 'currency' => 'INR', 'receipt' => '1', 'status' => 'created'));
$gateway = fake_gateway($http, $orders, array('currencies' => array('INR', 'JPY', 'KWD')));
$intent  = $gateway->createCheckout($order);

check('the buyer is sent to the pay page', $intent->isRedirect() && $intent->getPayload() === 'https://shop.test/razorpay/pay/1');
$req = end($http->requests);
pin('one POST to orders', array('POST', 'https://api.razorpay.com/v1/orders'), array($req['method'], $req['url']));
pin('with Basic auth of key id and secret', 'Basic ' . base64_encode(KEY_ID . ':' . KEY_SECRET), $req['headers']['Authorization']);
pin('as JSON', 'application/json', $req['headers']['Content-Type']);
pin('the amount in paise, the currency, the order id as receipt, and notes', array(
    'amount'   => 49900,
    'currency' => 'INR',
    'receipt'  => '1',
    'notes'    => array('shopclass_order' => '1', 'site' => 'Shop Test'),
), $http->lastBody());
pin('the Razorpay order is remembered on the order', 'order_Test1', $orders->find(1)->getExternalRef());
pin('which stays pending', Order::STATUS_PENDING, $orders->find(1)->getStatus());
pin('and records it was opened in test mode', false, $orders->find(1)->meta(RazorpayGateway::META_LIVE));
check('the key secret is in no request body', !str_contains((string) $req['body'], KEY_SECRET));

$jpy = $orders->add(1_000_000_000, 'JPY');
$http->on('POST', 'orders', 200, array('id' => 'order_Jpy', 'amount' => 1000, 'currency' => 'JPY'));
$gateway->createCheckout($jpy);
pin('yen go as whole yen', 1000, $http->lastBody()['amount']);

$kwd = $orders->add(1_255_000, 'KWD');
$http->on('POST', 'orders', 200, array('id' => 'order_Kwd', 'amount' => 1255, 'currency' => 'KWD'));
$gateway->createCheckout($kwd);
pin('dinars go in fils', 1255, $http->lastBody()['amount']);

harness_section('createCheckout when it cannot');

$GLOBALS['flash'] = array();
$down = $orders->add();
$http->down('POST', 'orders');
$intent = $gateway->createCheckout($down);
check('Razorpay down sends the buyer back to the buy page', $intent->isRedirect() && $intent->getPayload() === 'https://shop.test/buy');
pin('with an error message', 'error', $GLOBALS['flash'][0][0] ?? null);
pin('and the order is closed', Order::STATUS_FAILED, $orders->find($down->getId())->getStatus());

$http->on('POST', 'orders', 400, array('error' => array('code' => 'BAD_REQUEST_ERROR', 'description' => 'Currency is not supported')));
$refused = $orders->add();
$gateway->createCheckout($refused);
pin('an API error closes the order too', Order::STATUS_FAILED, $orders->find($refused->getId())->getStatus());

$http->on('POST', 'orders', 200, array('id' => 'order_Odd', 'amount' => 100, 'currency' => 'INR'));
$wrong = $orders->add();
$gateway->createCheckout($wrong);
pin('a Razorpay order for another amount is not used', Order::STATUS_FAILED, $orders->find($wrong->getId())->getStatus());

$count = count($http->requests);
$odd   = $orders->add(9_995_000, 'INR');
$gateway->createCheckout($odd);
pin('an amount Razorpay cannot charge never reaches Razorpay', $count, count($http->requests));
pin('and closes the order', Order::STATUS_FAILED, $orders->find($odd->getId())->getStatus());

$gbp = $orders->add(5_000_000, 'GBP');
$gateway->createCheckout($gbp);
pin('a currency the admin did not allow never reaches Razorpay', $count, count($http->requests));

$taken = $orders->add();
$http->on('POST', 'orders', 200, array('id' => 'order_Test1', 'amount' => 49900, 'currency' => 'INR'));
$intent = $gateway->createCheckout($taken);
pin('a Razorpay order another order holds is refused', 'https://shop.test/buy', $intent->getPayload());
pin('the other order keeps it', 'order_Test1', $orders->find(1)->getExternalRef());

harness_section('the pay page options');

$options = $gateway->payOptions($orders->find(1), 'buyer@example.test');
pin('everything Checkout needs, and no secret', array(
    'key'         => KEY_ID,
    'amount'      => 49900,
    'currency'    => 'INR',
    'name'        => 'Shop Test',
    'description' => '100 credits',
    'order_id'    => 'order_Test1',
    'notes'       => array('shopclass_order' => '1'),
    'prefill'     => array('email' => 'buyer@example.test'),
), $options);
check('no e-mail, no prefill', !isset($gateway->payOptions($orders->find(1))['prefill']));
pin('a paid order has nothing to pay', null, $gateway->payOptions($orders->add(499_000_000, 'INR', 'pay_X', 'paid')));
pin('nor an order without a Razorpay order', null, $gateway->payOptions($orders->add()));

harness_section('the signed return');

$o   = $orders->find(1);
$sig = Signature::signCheckout('order_Test1', 'pay_Test1', KEY_SECRET);
check('Checkout\'s signature for this order passes', $gateway->verifyReturn($o, 'order_Test1', 'pay_Test1', $sig));
check('a signature made with another secret fails', !$gateway->verifyReturn($o, 'order_Test1', 'pay_Test1', Signature::signCheckout('order_Test1', 'pay_Test1', 'guess')));
check('another payment id fails', !$gateway->verifyReturn($o, 'order_Test1', 'pay_Other', $sig));
$other = Signature::signCheckout('order_Jpy', 'pay_Test1', KEY_SECRET);
check('a valid signature for another Razorpay order fails', !$gateway->verifyReturn($o, 'order_Jpy', 'pay_Test1', $other));

harness_section('fetching the payment');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$order   = $orders->add(499_000_000, 'INR', 'order_Test1');
$gateway = fake_gateway($http, $orders);

$http->on('GET', 'payments/pay_Test1', 200, payment($order));
$r = $gateway->processPayment('pay_Test1', 1);
pin('a captured payment is paid', CallbackResult::OUTCOME_PAID, $r->getOutcome());
pin('it was fetched from Razorpay', 'GET payments/pay_Test1', $http->lastCall());
pin('with Razorpay\'s figures and the payment id', array(1, 'pay_Test1', 499_000_000, 'INR'), array($r->getOrderId(), $r->getExternalRef(), $r->getAmount(), $r->getCurrency()));

$r = $gateway->processPayment('pay_Test1', 2);
pin('a payment cannot pay an order it was not made for', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());

$http->on('GET', 'payments/pay_Test1', 200, payment($order, array('order_id' => 'order_Elsewhere')));
pin('a payment for a Razorpay order this site never made is ignored', 'not an order of this site', $gateway->processPayment('pay_Test1')->getMessage());

$http->on('GET', 'payments/pay_Test1', 200, payment($order, array('amount' => 100)));
pin('a different amount is still reported as Razorpay\'s own figure, for core to refuse', 1_000_000, $gateway->processPayment('pay_Test1', 1)->getAmount());

$http->on('GET', 'payments/pay_Test1', 200, payment($order, array('status' => 'authorized', 'captured' => false)));
$http->on('POST', 'payments/pay_Test1/capture', 200, payment($order));
$r = $gateway->processPayment('pay_Test1', 1);
pin('an authorized payment is captured', array('GET payments/pay_Test1', 'POST payments/pay_Test1/capture'), array_slice($http->calls(), -2));
pin('for the order\'s amount', array('amount' => 49900, 'currency' => 'INR'), $http->lastBody());
pin('and then paid', CallbackResult::OUTCOME_PAID, $r->getOutcome());

$http->on('POST', 'payments/pay_Test1/capture', 400, array('error' => array('description' => 'This payment has already been captured')));
$http->on('GET', 'payments/pay_Test1', 200, payment($order, array('status' => 'authorized', 'captured' => false)));
$r = $gateway->processPayment('pay_Test1', 1);
pin('a refused capture fetches the payment again', 'GET payments/pay_Test1', $http->lastCall());
pin('and waits when it is still not captured', 'not paid yet', $r->getMessage());

$count = count($http->requests);
$http->on('GET', 'payments/pay_Test1', 200, payment($order, array('status' => 'authorized', 'amount' => 100)));
pin('an authorized payment for another amount is not captured', 'authorized amount differs from the order', $gateway->processPayment('pay_Test1', 1)->getMessage());
pin('Razorpay is asked only for the payment', $count + 1, count($http->requests));

$http->on('GET', 'payments/pay_Test1', 200, payment($order, array('status' => 'failed')));
pin('a failed attempt leaves the order open', 'a payment attempt failed', $gateway->processPayment('pay_Test1', 1)->getMessage());

$count = count($http->requests);
pin('a malformed payment id is refused', 'malformed payment id', $gateway->processPayment('pay_../../x', 1)->getMessage());
pin('without asking Razorpay', $count, count($http->requests));

$http->down('GET', 'payments/pay_Test1');
$threw = false;
try {
    $gateway->processPayment('pay_Test1', 1);
} catch (HttpException $e) {
    $threw = true;
}
check('Razorpay down throws, so the caller can say "processing"', $threw);

exit(harness_result());
