<?php

/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\razorpay\Money;
use mindstellar\razorpay\Plugin;
use mindstellar\razorpay\RazorpayGateway;

/*
 * The settings page declaration. Core renders the form, checks CSRF and the admin's
 * capability, validates each field and stores it under the "razorpay" section.
 */

$keyId = static function (string $name, string $label, string $prefix, string $help): array {
    return array(
        'type'        => 'text',
        'name'        => $name,
        'label'       => $label,
        'help'        => $help,
        'placeholder' => $prefix . '…',
        'maxlength'   => 64,
        'attrs'       => array('autocomplete' => 'off', 'spellcheck' => 'false'),
        'validate'    => static function ($value) use ($prefix) {
            $value = trim((string) $value);
            if ($value === '' || preg_match('/^' . preg_quote($prefix, '/') . '[A-Za-z0-9]{1,40}$/', $value)) {
                return null;
            }

            return sprintf(__('This should start with "%s"', 'razorpay'), $prefix);
        },
    );
};

$secret = static function (string $name, string $label, string $help): array {
    return array(
        'type'       => 'secret',
        'name'       => $name,
        'label'      => $label,
        'help'       => $help,
        'write_only' => true,
        'attrs'      => array('autocomplete' => 'new-password', 'spellcheck' => 'false'),
        // A blank box keeps the stored secret.
        'persist'    => static fn ($value) => trim((string) $value) === '' ? null : trim((string) $value),
        'validate'   => static function ($value) {
            $value = trim((string) $value);

            return $value === '' || preg_match('/^[\x21-\x7E]{8,128}$/', $value) ? null : __('This does not look like a Razorpay secret', 'razorpay');
        },
    );
};

$webhook = static function (): void {
    echo '<p><code>' . osc_esc_html(Plugin::webhookUrl()) . '</code></p>';
    echo '<p>' . osc_esc_html(__('In Razorpay, open Accounts & Settings > Webhooks, add a webhook with this URL, set a secret, and select these events:', 'razorpay')) . '</p><ul>';
    foreach (RazorpayGateway::EVENTS as $event) {
        echo '<li><code>' . osc_esc_html($event) . '</code></li>';
    }
    echo '</ul><p>' . osc_esc_html(__('Then type the same secret into the webhook secret field for that mode. Test and live mode each need their own webhook.', 'razorpay')) . '</p>';
};

$capture = static function (): void {
    echo '<p>' . osc_esc_html(__('Turn on automatic capture in Razorpay under Accounts & Settings > Payment capture. If a payment still arrives only authorized, this plugin captures it once the amount matches the order.', 'razorpay')) . '</p>';
};

return array(
    'title'  => __('Razorpay Payment', 'razorpay'),
    'menu'   => 'plugins',
    'intro'  => __('Take UPI, card, netbanking and wallet payments for credit packages through Razorpay Checkout. Buyers pay in a window hosted by Razorpay, so no card data reaches this site.', 'razorpay'),
    'groups' => array(
        array(
            'title'  => __('Account', 'razorpay'),
            'fields' => array(
                array(
                    'type'    => 'radio',
                    'name'    => 'mode',
                    'label'   => __('Mode', 'razorpay'),
                    'help'    => __('Test mode uses your test keys. No real money moves.', 'razorpay'),
                    'options' => array(
                        'test' => __('Test', 'razorpay'),
                        'live' => __('Live', 'razorpay'),
                    ),
                    'default' => 'test',
                ),
                $keyId('test_key_id', __('Test key id', 'razorpay'), 'rzp_test_', __('From Accounts & Settings > API keys, in test mode.', 'razorpay')),
                $secret('test_key_secret', __('Test key secret', 'razorpay'), __('Shown once by Razorpay when the key is made. Saved secrets are never shown again; leave blank to keep the saved one.', 'razorpay')),
                $secret('test_webhook_secret', __('Test webhook secret', 'razorpay'), __('The secret you set on your test-mode webhook. Leave blank to keep the saved one.', 'razorpay')),
                $keyId('live_key_id', __('Live key id', 'razorpay'), 'rzp_live_', __('From Accounts & Settings > API keys, in live mode.', 'razorpay')),
                $secret('live_key_secret', __('Live key secret', 'razorpay'), __('Leave blank to keep the saved one.', 'razorpay')),
                $secret('live_webhook_secret', __('Live webhook secret', 'razorpay'), __('The secret you set on your live-mode webhook. Leave blank to keep the saved one.', 'razorpay')),
            ),
        ),
        array(
            'title'  => __('Webhook', 'razorpay'),
            'fields' => array(
                array(
                    'type'   => 'custom',
                    'name'   => 'webhook_info',
                    'label'  => __('Webhook URL', 'razorpay'),
                    'render' => $webhook,
                ),
                array(
                    'type'   => 'custom',
                    'name'   => 'capture_info',
                    'label'  => __('Payment capture', 'razorpay'),
                    'render' => $capture,
                ),
            ),
        ),
        array(
            'title'  => __('Checkout', 'razorpay'),
            'fields' => array(
                array(
                    'type'      => 'text',
                    'name'      => 'name',
                    'label'     => __('Name at checkout', 'razorpay'),
                    'default'   => __('UPI, cards, netbanking (Razorpay)', 'razorpay'),
                    'maxlength' => 60,
                    'required'  => true,
                ),
                array(
                    'type'        => 'text',
                    'name'        => 'currencies',
                    'label'       => __('Currencies', 'razorpay'),
                    'placeholder' => 'INR',
                    'help'        => __('Comma-separated codes. Leave empty to use the billing currency. Any currency but INR needs international payments turned on in your Razorpay account.', 'razorpay'),
                    'sanitize'    => static function ($value) {
                        $codes = array_filter(array_map('trim', explode(',', strtoupper((string) $value))));

                        return implode(', ', array_unique($codes));
                    },
                    'validate'    => static function ($value) {
                        foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $code) {
                            if (!in_array($code, Money::SUPPORTED, true)) {
                                return sprintf(__('Razorpay does not take "%s"', 'razorpay'), $code);
                            }
                        }

                        return null;
                    },
                ),
            ),
        ),
    ),
);
