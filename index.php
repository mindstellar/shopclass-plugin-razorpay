<?php
/*
Plugin Name: Razorpay Payment
Plugin URI: https://github.com/mindstellar/shopclass-plugin-razorpay
Description: Take UPI, card, netbanking and wallet payments for credit packages through Razorpay Checkout. Card data stays with Razorpay; a signed webhook marks the order paid. Refunds from the admin.
Version: 0.1.1
Author: Navjot Tomer (Mindstellar)
Author URI: https://mindstellar.com
Short Name: razorpay
Requires Shopclass: 7.0.0
Tested up to: 7.0
Requires PHP: 8.0
Support URI: https://github.com/mindstellar/shopclass-plugin-razorpay/issues
*/

/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\razorpay\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\razorpay\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

osc_register_plugin(osc_plugin_path(__FILE__), array(Plugin::class, 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array(Plugin::class, 'uninstall'));

// The plugin needs core's lazy gateway hook, HTTP client and admin mail, all in Shopclass 7.0.
if (!defined(PaymentGatewayRegistry::class . '::HOOK')
    || !interface_exists('mindstellar\\http\\HttpClient')
    || !function_exists('osc_mail_admin')
) {
    osc_add_hook('admin_page_header', static function (): void {
        echo '<div class="flashmessage flashmessage-warning" role="status">'
            . osc_esc_html(__('Razorpay Payment needs Shopclass 7.0 or later and is switched off. Update Shopclass to use it.', 'razorpay'))
            . '</div>';
    }, 10);

    return;
}

osc_register_settings_page(Plugin::PAGE, require __DIR__ . '/settings.php');
osc_add_hook(osc_plugin_path(__FILE__) . '_configure', static function () {
    osc_redirect_to(osc_settings_page_url(Plugin::PAGE));
});

// Built only the first time a page asks for payment methods.
osc_add_hook(PaymentGatewayRegistry::HOOK, array(Plugin::class, 'register'));

// The pay page that opens Razorpay Checkout, and the form it posts back to.
osc_add_route_hook(Plugin::ROUTE_PAY, 'razorpay/pay/([0-9]+)', 'razorpay/pay/{order}');
osc_add_hook(Plugin::ROUTE_PAY, array(Plugin::class, 'payPage'));
osc_add_route_hook(Plugin::ROUTE_RETURN, 'razorpay/return', 'razorpay/return');
osc_add_hook(Plugin::ROUTE_RETURN, array(Plugin::class, 'returnPage'));

// Retries for webhooks that could not be settled, and a daily check of pending orders.
osc_add_hook('register_jobs', array(Plugin::class, 'registerJobs'));
osc_add_hook('cron_daily', array(Plugin::class, 'daily'));
osc_add_hook('job_gave_up', array(Plugin::class, 'jobGaveUp'));

osc_add_hook('admin_page_header', array(Plugin::class, 'adminNotice'), 10);
