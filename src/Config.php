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

use Closure;

/**
 * The settings the gateway runs on, read once per request. Tests build one by hand.
 */
final class Config
{
    /**
     * @param string       $mode            'test' or 'live'
     * @param string       $keyId           The active mode's key id (rzp_test_... or rzp_live_...)
     * @param string       $keySecret       The active mode's key secret
     * @param string       $webhookSecret   The active mode's webhook secret
     * @param string       $name            Name at checkout
     * @param string[]     $currencies      Codes the admin allows; empty means the billing currency
     * @param string       $billingCurrency The site's billing currency
     * @param string       $siteName        Shown at the top of the Razorpay window
     * @param Closure      $payUrl          fn(int $orderId): string, the pay page
     * @param string       $cancelUrl       Where a buyer who cannot pay lands
     * @param Closure|null $isAdmin         fn(): bool; test mode is offered only to signed-in admins
     */
    public function __construct(
        public string $mode,
        public string $keyId,
        public string $keySecret,
        public string $webhookSecret,
        public string $name,
        public array $currencies,
        public string $billingCurrency,
        public string $siteName,
        public Closure $payUrl,
        public string $cancelUrl,
        public ?Closure $isAdmin = null
    ) {
    }

    /**
     * Read the saved settings. Secrets are write-only fields, which osc_settings_value()
     * never reads back, so they come straight from the preference.
     *
     * @return self
     */
    public static function fromSettings(): self
    {
        $mode = self::value('mode') === 'live' ? 'live' : 'test';

        return new self(
            $mode,
            trim((string) self::value($mode . '_key_id')),
            trim((string) osc_get_preference($mode . '_key_secret', Plugin::PAGE)),
            trim((string) osc_get_preference($mode . '_webhook_secret', Plugin::PAGE)),
            (string) self::value('name'),
            self::parseCurrencies((string) self::value('currencies')),
            strtoupper((string) osc_billing_currency()),
            (string) osc_page_title(),
            static fn (int $orderId): string => (string) osc_route_url(Plugin::ROUTE_PAY, array('order' => $orderId)),
            (string) osc_billing_buy_url(),
            // Core 7.1 also sees an admin on the site's own API calls; older cores only on pages.
            static fn (): bool => function_exists('osc_admin_is_browsing')
                ? osc_admin_is_browsing()
                : (function_exists('osc_is_admin_user_logged_in') && (bool) osc_is_admin_user_logged_in())
        );
    }

    /**
     * Whether payments are real.
     *
     * @return bool
     */
    public function live(): bool
    {
        return $this->mode === 'live';
    }

    /**
     * Comma-separated codes to a clean list: upper case, ones Razorpay takes.
     *
     * @param string $list
     *
     * @return string[]
     */
    public static function parseCurrencies(string $list): array
    {
        $codes = array();
        foreach (explode(',', strtoupper($list)) as $code) {
            $code = trim($code);
            if (in_array($code, Money::SUPPORTED, true)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * One saved setting, or its declared default.
     *
     * @param string $name
     *
     * @return mixed
     */
    private static function value(string $name)
    {
        return osc_settings_value(Plugin::PAGE, $name);
    }
}
