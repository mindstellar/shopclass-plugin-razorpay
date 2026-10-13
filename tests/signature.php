<?php
/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Checkout's signature over "order_id|payment_id", and the webhook signature over the raw body.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\razorpay\Signature;

harness_section('checkout signature');

$secret = 'key_secret_abc';
$good   = hash_hmac('sha256', 'order_A1|pay_B2', $secret);
check('a valid signature passes', Signature::checkout('order_A1', 'pay_B2', $good, $secret));
check('the documented formula is used', Signature::signCheckout('order_A1', 'pay_B2', $secret) === $good);
check('upper-case hex passes too', Signature::checkout('order_A1', 'pay_B2', strtoupper($good), $secret));
check('a wrong secret fails', !Signature::checkout('order_A1', 'pay_B2', $good, 'other'));
check('another payment id fails', !Signature::checkout('order_A1', 'pay_B3', $good, $secret));
check('another order id fails', !Signature::checkout('order_A2', 'pay_B2', $good, $secret));
check('ids swapped fail', !Signature::checkout('pay_B2', 'order_A1', $good, $secret));
check('an empty signature fails', !Signature::checkout('order_A1', 'pay_B2', '', $secret));
check('an empty secret never passes', !Signature::checkout('order_A1', 'pay_B2', hash_hmac('sha256', 'order_A1|pay_B2', ''), ''));

harness_section('webhook signature');

$body   = '{"entity":"event","event":"payment.captured"}';
$header = Signature::signWebhook($body, 'whsec');
check('the documented formula is used', $header === hash_hmac('sha256', $body, 'whsec'));
check('a valid signature passes', Signature::webhook($body, $header, 'whsec'));
check('surrounding spaces in the header are tolerated', Signature::webhook($body, ' ' . $header . ' ', 'whsec'));
check('a wrong secret fails', !Signature::webhook($body, $header, 'other'));
check('a tampered body fails', !Signature::webhook(str_replace('captured', 'failed', $body), $header, 'whsec'));
check('one extra byte of whitespace fails', !Signature::webhook($body . ' ', $header, 'whsec'));
check('an empty header fails', !Signature::webhook($body, '', 'whsec'));
check('an empty secret never passes', !Signature::webhook($body, Signature::signWebhook($body, ''), ''));

exit(harness_result());
