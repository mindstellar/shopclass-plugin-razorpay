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
 * Data the plugin trusts: a webhook event whose signature was checked, or a payment id
 * to fetch from Razorpay. An HTTP request cannot carry an object, so a forged callback never passes one.
 */
final class Verified
{
    public const EVENT   = 'event';
    public const PAYMENT = 'payment';

    /**
     * @param string              $kind      One of the constants
     * @param array<string,mixed> $event     The event, for EVENT
     * @param string              $paymentId The payment id, for PAYMENT
     * @param int|null            $orderId   The order the payment must belong to, if known
     */
    private function __construct(
        public string $kind,
        public array $event = array(),
        public string $paymentId = '',
        public ?int $orderId = null
    ) {
    }

    /**
     * @param array<string,mixed> $event
     *
     * @return self
     */
    public static function event(array $event): self
    {
        return new self(self::EVENT, $event);
    }

    /**
     * @param string   $paymentId pay_...
     * @param int|null $orderId
     *
     * @return self
     */
    public static function payment(string $paymentId, ?int $orderId = null): self
    {
        return new self(self::PAYMENT, array(), $paymentId, $orderId);
    }
}
