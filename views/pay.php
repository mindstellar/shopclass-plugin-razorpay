<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\billing\Order;
use mindstellar\currency\Money;

/*
 * The pay page: content only, wrapped by the theme's or core's page chrome.
 */

$view = __get('razorpayPay');
if (!is_array($view) || !($view['order'] ?? null) instanceof Order) {
    return;
}

/** @var Order $order */
$order   = $view['order'];
$options = json_encode($view['options'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
?>
<div class="oe-bill">
    <div class="oe-panel oe-bill-card">
        <table class="oe-table">
            <tbody>
            <tr>
                <th scope="row"><?php echo osc_esc_html(__('Order', 'razorpay')); ?></th>
                <td>#<?php echo (int) $order->getId(); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php echo osc_esc_html(__('Amount', 'razorpay')); ?></th>
                <td><?php echo osc_esc_html(Money::format($order->getAmount(), $order->getCurrency())); ?></td>
            </tr>
            <?php if ($order->getCredits() > 0) { ?>
            <tr>
                <th scope="row"><?php echo osc_esc_html(__('Credits', 'razorpay')); ?></th>
                <td><?php echo number_format($order->getCredits()); ?></td>
            </tr>
            <?php } ?>
            </tbody>
        </table>

        <p class="oe-hint"><?php echo osc_esc_html(__('Pay by UPI, card, netbanking or wallet in Razorpay\'s secure window. Your card details never reach this site.', 'razorpay')); ?></p>

        <noscript>
            <p class="oe-bill-sub"><?php echo osc_esc_html(__('Razorpay needs JavaScript. Turn it on in your browser, then reload this page.', 'razorpay')); ?></p>
        </noscript>

        <div class="oe-actions">
            <button type="button" class="oe-btn" id="razorpay-pay" disabled
                    data-options="<?php echo osc_esc_html((string) $options); ?>"
                    data-unavailable="<?php echo osc_esc_html(__('Razorpay could not be loaded. Check your connection and reload this page.', 'razorpay')); ?>"
                    data-failed="<?php echo osc_esc_html(__('The payment did not go through. You can try again.', 'razorpay')); ?>"
                    data-confirming="<?php echo osc_esc_html(__('Payment received. Confirming it...', 'razorpay')); ?>">
                <?php echo osc_esc_html(__('Pay now', 'razorpay')); ?>
            </button>
        </div>
        <p class="oe-muted" id="razorpay-status" role="status" aria-live="polite"></p>

        <form method="post" action="<?php echo osc_esc_html($view['returnUrl']); ?>" id="razorpay-return" class="nocsrf" hidden>
            <?php echo osc_csrf_token_form(); ?>
            <input type="hidden" name="order" value="<?php echo (int) $order->getId(); ?>">
            <input type="hidden" name="razorpay_order_id" value="">
            <input type="hidden" name="razorpay_payment_id" value="">
            <input type="hidden" name="razorpay_signature" value="">
        </form>
    </div>

    <p class="oe-muted oe-bill-sub">
        <a href="<?php echo osc_esc_html(osc_billing_orders_url()); ?>"><?php echo osc_esc_html(__('Back to your orders', 'razorpay')); ?></a>
    </p>
</div>
<?php
// Core's own page shell prints no queued scripts, so print them here when it is the one in use.
if (!osc_theme_has_chrome()) {
    Scripts::getInstance()->printScripts();
}
