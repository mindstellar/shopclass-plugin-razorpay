<?php

/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\razorpay;

/**
 * Converts between core's micros (value x 1,000,000) and Razorpay's smallest units.
 */
final class Money
{
    /** Currencies Razorpay charges in whole units (exponent 0). */
    public const ZERO_DECIMAL = array(
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    );

    /** Currencies Razorpay charges in thousandths (exponent 3). */
    public const THREE_DECIMAL = array('BHD', 'IQD', 'JOD', 'KWD', 'OMR', 'TND');

    /** INR and Razorpay's international currencies. Any but INR needs international payments on the account. */
    public const SUPPORTED = array(
        'AED', 'ALL', 'AMD', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD',
        'BND', 'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BZD', 'CAD', 'CHF', 'CLP', 'CNY', 'COP', 'CRC',
        'CUP', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'GBP', 'GHS',
        'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK', 'HTG', 'HUF', 'IDR', 'ILS', 'INR',
        'IQD', 'ISK', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KRW', 'KWD', 'KYD', 'KZT',
        'LAK', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MUR', 'MVR',
        'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'OMR', 'PEN', 'PGK',
        'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SCR', 'SEK', 'SGD',
        'SLL', 'SOS', 'SVC', 'SZL', 'THB', 'TND', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD',
        'UYU', 'UZS', 'VND', 'VUV', 'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW',
    );

    /**
     * How many decimal places Razorpay's smallest unit has for $currency.
     *
     * @param string $currency ISO 4217, any case
     *
     * @return int
     */
    public static function decimals(string $currency): int
    {
        $currency = strtoupper($currency);
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return 0;
        }

        return in_array($currency, self::THREE_DECIMAL, true) ? 3 : 2;
    }

    /**
     * Micros to Razorpay's smallest units, or null when that exact amount cannot be charged.
     *
     * @param int    $micros
     * @param string $currency
     *
     * @return int|null
     */
    public static function toMinor(int $micros, string $currency): ?int
    {
        $divisor = 10 ** (6 - self::decimals($currency));
        if ($micros < 0 || $micros % $divisor !== 0) {
            return null;
        }

        return intdiv($micros, $divisor);
    }

    /**
     * Razorpay's smallest units to micros.
     *
     * @param int    $minor
     * @param string $currency
     *
     * @return int
     */
    public static function toMicros(int $minor, string $currency): int
    {
        return $minor * 10 ** (6 - self::decimals($currency));
    }
}
