<?php
/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Micros to Razorpay's smallest units and back, for two-, zero- and three-decimal currencies.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\razorpay\Money;

harness_section('two decimals');
pin('499 INR is 49900 paise', 49900, Money::toMinor(499_000_000, 'INR'));
pin('lower case works', 49900, Money::toMinor(499_000_000, 'inr'));
pin('49900 paise is 499 INR in micros', 499_000_000, Money::toMicros(49900, 'INR'));
pin('9.99 USD is 999 cents', 999, Money::toMinor(9_990_000, 'USD'));
pin('a fraction of a paisa cannot be charged', null, Money::toMinor(9_995_000, 'INR'));
pin('a negative amount is refused', null, Money::toMinor(-1_000_000, 'INR'));

harness_section('zero decimals');
pin('JPY has none', 0, Money::decimals('JPY'));
pin('1000 JPY is 1000', 1000, Money::toMinor(1_000_000_000, 'JPY'));
pin('and back', 1_000_000_000, Money::toMicros(1000, 'jpy'));
pin('half a yen cannot be charged', null, Money::toMinor(500_000, 'JPY'));
foreach (array('BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF') as $code) {
    pin($code . ': 5 units is 5', array(0, 5, 5_000_000), array(Money::decimals($code), Money::toMinor(5_000_000, $code), Money::toMicros(5, $code)));
}
pin('the zero-decimal list is exactly these', 16, count(Money::ZERO_DECIMAL));

harness_section('three decimals');
foreach (array('BHD', 'IQD', 'JOD', 'KWD', 'OMR', 'TND') as $code) {
    pin($code . ': 99.999 is 99999', array(3, 99999, 99_999_000), array(Money::decimals($code), Money::toMinor(99_999_000, $code), Money::toMicros(99999, $code)));
}
pin('the three-decimal list is exactly these', 6, count(Money::THREE_DECIMAL));
pin('a ten-thousandth cannot be charged', null, Money::toMinor(1_250_500, 'KWD'));

harness_section('lists');
check('INR is supported', in_array('INR', Money::SUPPORTED, true));
check('every supported code is three letters', array_filter(Money::SUPPORTED, static fn ($c) => !preg_match('/^[A-Z]{3}$/', $c)) === array());
check('no code is listed twice', count(array_unique(Money::SUPPORTED)) === count(Money::SUPPORTED));
check('every special-case code is supported', array_diff(array_merge(Money::ZERO_DECIMAL, Money::THREE_DECIMAL), Money::SUPPORTED) === array());
check('no code is both zero- and three-decimal', array_intersect(Money::ZERO_DECIMAL, Money::THREE_DECIMAL) === array());

exit(harness_result());
