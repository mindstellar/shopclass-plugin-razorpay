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
 * Razorpay's two HMAC-SHA256 signatures: the one Checkout hands back after a payment,
 * and the X-Razorpay-Signature header on a webhook.
 */
final class Signature
{
    /**
     * Whether $signature is HMAC-SHA256("<order id>|<payment id>", key secret).
     *
     * @param string $orderId   order_...
     * @param string $paymentId pay_...
     * @param string $signature Hex, as Checkout returns it
     * @param string $keySecret The API key secret
     *
     * @return bool
     */
    public static function checkout(string $orderId, string $paymentId, string $signature, string $keySecret): bool
    {
        if ($keySecret === '' || $orderId === '' || $paymentId === '' || $signature === '') {
            return false;
        }

        return hash_equals(self::signCheckout($orderId, $paymentId, $keySecret), strtolower($signature));
    }

    /**
     * Whether $header is HMAC-SHA256 of the raw webhook body with the webhook secret.
     *
     * @param string $payload The raw request body, byte for byte
     * @param string $header  The X-Razorpay-Signature header
     * @param string $secret  The webhook secret set in the Razorpay dashboard
     *
     * @return bool
     */
    public static function webhook(string $payload, string $header, string $secret): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }

        return hash_equals(self::signWebhook($payload, $secret), strtolower(trim($header)));
    }

    /**
     * The signature Checkout would return. For tests.
     */
    public static function signCheckout(string $orderId, string $paymentId, string $keySecret): string
    {
        return hash_hmac('sha256', $orderId . '|' . $paymentId, $keySecret);
    }

    /**
     * The header Razorpay would send. For tests and local replays.
     */
    public static function signWebhook(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }
}
