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
use mindstellar\billing\Order;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\http\CurlHttpClient;
use mindstellar\job\Job;
use mindstellar\settings\SettingsPageRegistry;
use Params;
use Throwable;
use View;

/**
 * The wiring: registration, the pay and return pages, background jobs and admin notices.
 */
final class Plugin
{
    /** Settings page id, and the preference section its values live in. */
    public const PAGE = 'razorpay';

    /** Route of the page that opens Razorpay Checkout. */
    public const ROUTE_PAY = 'razorpay-pay';

    /** Route the pay page posts the signed result to. */
    public const ROUTE_RETURN = 'razorpay-return';

    /** Job that fetches one payment from Razorpay and settles it. */
    public const JOB_PAYMENT = 'razorpay.payment';

    /** Daily job that checks pending orders Razorpay never told us about. */
    public const JOB_RECONCILE = 'razorpay.reconcile';

    /** Script ids: Razorpay's checkout.js, and the pay page's own script. */
    public const SCRIPT_CHECKOUT = 'razorpay-checkout';
    public const SCRIPT_PAY      = 'razorpay-pay';

    public const CHECKOUT_JS = 'https://checkout.razorpay.com/v1/checkout.js';

    /** How long a notice about a payment that was not credited is remembered, in seconds. */
    public const STRANDED_TTL = 7776000;

    /** fn(): RazorpayGateway -- replaces the real gateway in tests. */
    public static ?Closure $factory = null;

    /**
     * Nothing to install: every setting has a declared default.
     *
     * @return void
     */
    public static function install(): void
    {
    }

    /**
     * Remove the stored settings.
     *
     * @return void
     */
    public static function uninstall(): void
    {
        foreach (array_keys(SettingsPageRegistry::getInstance()->fields(self::PAGE)) as $name) {
            osc_delete_preference($name, self::PAGE);
        }
    }

    /**
     * The gateway, built on the current settings.
     *
     * @return RazorpayGateway
     */
    public static function gateway(): RazorpayGateway
    {
        if (self::$factory !== null) {
            return (self::$factory)();
        }

        return new RazorpayGateway(Config::fromSettings(), new CurlHttpClient(), new CoreOrderStore());
    }

    /**
     * Register the gateway while billing is on, and hand it back.
     *
     * @param PaymentGatewayRegistry|null $registry Passed by core's register_payment_gateways hook
     *
     * @return RazorpayGateway|null
     */
    public static function register($registry = null): ?RazorpayGateway
    {
        if (!osc_billing_enabled()) {
            return null;
        }
        $gateway  = self::gateway();
        $registry = $registry instanceof PaymentGatewayRegistry ? $registry : PaymentGatewayRegistry::getInstance();
        $registry->register($gateway);

        return $gateway;
    }

    /**
     * The URL to paste into Razorpay as the webhook endpoint.
     *
     * @return string
     */
    public static function webhookUrl(): string
    {
        return osc_base_url(true) . '?page=billing&action=callback&gateway=' . RazorpayGateway::ID;
    }

    /**
     * The pay page: the order, and a button that opens Razorpay Checkout.
     *
     * @return void
     */
    public static function payPage(): void
    {
        if (!osc_is_web_user_logged_in()) {
            osc_redirect_to(osc_user_login_url());
        }

        $orders = new CoreOrderStore();
        $view   = self::payView(self::register(), $orders, Params::getParamInt('order'), (int) osc_logged_user_id());
        if ($view === null) {
            osc_redirect_to(osc_billing_enabled() ? osc_billing_orders_url() : osc_base_url());

            return;
        }

        self::enqueueScripts();
        View::getInstance()->_exportVariableToView('razorpayPay', $view);
        osc_gui_view('', dirname(__DIR__) . '/views/pay.php', array(
            'heading' => __('Pay with Razorpay', 'razorpay'),
            'title'   => __('Pay with Razorpay', 'razorpay') . ' - ' . osc_page_title(),
        ));
    }

    /**
     * What the pay page shows, or null after a flash message when there is nothing to pay.
     *
     * @param RazorpayGateway|null $gateway Null while billing is off
     * @param OrderStore           $orders
     * @param int                  $orderId
     * @param int                  $userId  The signed-in buyer
     *
     * @return array{order:Order,options:array<string,mixed>,returnUrl:string}|null
     */
    public static function payView(?RazorpayGateway $gateway, OrderStore $orders, int $orderId, int $userId): ?array
    {
        $order = $orders->find($orderId);
        if ($gateway === null
            || $order === null
            || $order->getUserId() !== $userId
            || $order->getGateway() !== RazorpayGateway::ID
        ) {
            osc_add_flash_error_message(__('That order is not available.', 'razorpay'));

            return null;
        }

        $options = $order->isPending() ? $gateway->payOptions($order, $orders->buyerEmail($userId)) : null;
        if ($options === null) {
            self::flashOutcome($order->getId(), $order->getStatus(), $order->getStatus(), method_exists($order, 'isGoods') && $order->isGoods());

            return null;
        }

        return array('order' => $order, 'options' => $options, 'returnUrl' => (string) osc_route_url(self::ROUTE_RETURN));
    }

    /**
     * Load Razorpay's checkout.js and the pay page's script, on the pay page only.
     *
     * @return void
     */
    public static function enqueueScripts(): void
    {
        $url = osc_plugin_url(dirname(__DIR__) . '/index.php') . 'assets/pay.js';
        osc_register_script(self::SCRIPT_CHECKOUT, self::CHECKOUT_JS);
        osc_register_script(self::SCRIPT_PAY, osc_asset_url_versioned($url), array(self::SCRIPT_CHECKOUT));
        osc_enqueue_script(self::SCRIPT_PAY);
    }

    /**
     * The pay page posts here after Razorpay Checkout succeeds. Check the signature, then settle.
     *
     * @return void
     */
    public static function returnPage(): void
    {
        if (Params::getServerParam('REQUEST_METHOD') !== 'POST') {
            osc_redirect_to(osc_base_url());
        }
        osc_csrf_check();
        if (!osc_is_web_user_logged_in()) {
            osc_redirect_to(osc_user_login_url());
        }

        $status = self::settleReturn(
            self::register(),
            new CoreOrderStore(),
            Params::getParamInt('order'),
            (int) osc_logged_user_id(),
            Params::getParamString('razorpay_order_id'),
            Params::getParamString('razorpay_payment_id'),
            Params::getParamString('razorpay_signature')
        );
        if (!osc_billing_enabled()) {
            osc_redirect_to(osc_base_url());
        }
        $orderId = Params::getParamInt('order');
        osc_redirect_to($status !== '' && function_exists('osc_billing_order_return_url') ? osc_billing_order_return_url($orderId) : osc_billing_orders_url());
    }

    /**
     * The return page's work: check the buyer owns the order and Checkout's signature, settle, flash.
     * Razorpay is asked only for a pending order whose stored Razorpay order the signature covers.
     *
     * @param RazorpayGateway|null $gateway   Null while billing is off
     * @param OrderStore           $orders
     * @param int                  $orderId
     * @param int                  $userId    The signed-in buyer
     * @param string               $remoteId  razorpay_order_id
     * @param string               $paymentId razorpay_payment_id
     * @param string               $signature razorpay_signature
     *
     * @return string the order's status afterwards, or '' when it was refused
     */
    public static function settleReturn(
        ?RazorpayGateway $gateway,
        OrderStore $orders,
        int $orderId,
        int $userId,
        string $remoteId,
        string $paymentId,
        string $signature
    ): string {
        $order = $orders->find($orderId);
        if ($gateway === null
            || $order === null
            || $order->getUserId() !== $userId
            || $order->getGateway() !== RazorpayGateway::ID
        ) {
            osc_add_flash_error_message(__('That order is not available.', 'razorpay'));

            return '';
        }

        $before = $order->getStatus();
        if ($order->isPending() && $gateway->verifyReturn($order, $remoteId, $paymentId, $signature)) {
            try {
                $gateway->processPayment($paymentId, $order->getId());
            } catch (Throwable $e) {
                // Razorpay or the database did not answer. The webhook and the daily check settle it later.
                error_log('Razorpay: return page for order #' . $order->getId() . ': ' . $e->getMessage());
            }
        }

        $now   = $orders->find($order->getId());
        $after = $now === null ? '' : $now->getStatus();
        self::flashOutcome($order->getId(), $before, $after, method_exists($order, 'isGoods') && $order->isGoods());

        return $after;
    }

    /**
     * Tell the buyer where their order stands.
     *
     * @param int    $orderId
     * @param string $before Status before the page ran
     * @param string $after  Status now
     * @param bool   $goods  The order is for goods, not credits
     *
     * @return void
     */
    public static function flashOutcome(int $orderId, string $before, string $after, bool $goods = false): void
    {
        $settled = defined('mindstellar\\billing\\Order::SETTLED') ? Order::SETTLED : array(Order::STATUS_PAID);
        if (in_array($after, $settled, true)) {
            osc_add_flash_ok_message(in_array($before, $settled, true)
                ? sprintf(__('Order #%d is paid.', 'razorpay'), $orderId)
                : ($goods
                    ? sprintf(__('Payment received for order #%d.', 'razorpay'), $orderId)
                    : sprintf(__('Payment for order #%d received. Credits added.', 'razorpay'), $orderId)));
        } elseif ($after === Order::STATUS_PENDING) {
            osc_add_flash_info_message(sprintf(__('Payment for order #%d is processing. Credits are added once Razorpay confirms it.', 'razorpay'), $orderId));
        } else {
            osc_add_flash_warning_message(sprintf(__('Order #%d was not paid.', 'razorpay'), $orderId));
        }
    }

    /**
     * Register the job handlers. Cron asks for no payment methods, so each registers the gateway itself.
     *
     * @return void
     */
    public static function registerJobs(): void
    {
        osc_job_register_handler(self::JOB_PAYMENT, static function (Job $job): void {
            $gateway = self::register();
            if ($gateway === null) {
                throw new \RuntimeException('billing is switched off');
            }
            $gateway->processPayment((string) $job->get('payment', ''));
        });
        osc_job_register_handler(self::JOB_RECONCILE, static function (Job $job): void {
            $gateway = self::register();
            if ($gateway !== null && $gateway->hasKeys()) {
                $gateway->reconcile();
            }
        });

        osc_job_describe(self::JOB_PAYMENT, __('Record a Razorpay payment', 'razorpay'), static function (array $payload): string {
            return (string) ($payload['payment'] ?? '');
        });
        osc_job_describe(self::JOB_RECONCILE, __('Check pending Razorpay orders', 'razorpay'));
    }

    /**
     * Queue the daily check, once.
     *
     * @return void
     */
    public static function daily(): void
    {
        if (osc_billing_enabled()) {
            osc_job_ensure(self::JOB_RECONCILE);
        }
    }

    /**
     * Mail the site's contact address when a Razorpay job stops retrying.
     *
     * @param string $type
     * @param mixed  $payload
     * @param string $error
     * @param int    $id
     *
     * @return void
     */
    public static function jobGaveUp($type, $payload = array(), $error = '', $id = 0): void
    {
        if ($type !== self::JOB_PAYMENT && $type !== self::JOB_RECONCILE) {
            return;
        }

        $payment = is_array($payload) ? (string) ($payload['payment'] ?? '') : '';
        $lines   = array(
            __('A Razorpay payment could not be recorded after several tries.', 'razorpay'),
            $payment !== '' ? sprintf(__('Razorpay payment: %s', 'razorpay'), $payment) : '',
            sprintf(__('Error: %s', 'razorpay'), (string) $error),
            sprintf(__('Retry it under Tools > System info > Jobs (job #%d), and check the order in Billing > Orders.', 'razorpay'), (int) $id),
        );
        error_log('Razorpay: job #' . (int) $id . ' (' . $type . ') gave up: ' . $error);
        osc_mail_admin(__('Razorpay: a payment needs checking', 'razorpay'), array_values(array_filter($lines)));
    }

    /**
     * Tell the admin, once per payment, that Razorpay took money core did not credit.
     *
     * @param int    $orderId
     * @param string $paymentId
     * @param string $reason
     *
     * @return bool whether this call sent the notice
     */
    public static function strandedPayment(int $orderId, string $paymentId, string $reason): bool
    {
        error_log('Razorpay: payment ' . $paymentId . ' for order #' . $orderId . ' was captured but not credited: ' . $reason);
        try {
            if (!osc_kv_claim(self::PAGE, 'stranded:' . $paymentId, self::STRANDED_TTL, 'notified')) {
                return false;
            }
        } catch (Throwable $e) {
            error_log('Razorpay: could not record the notice for ' . $paymentId . ': ' . $e->getMessage());
        }

        osc_mail_admin(__('Razorpay: a payment needs checking', 'razorpay'), array(
            sprintf(__('Razorpay took a payment that was not credited, because %s.', 'razorpay'), $reason),
            sprintf(__('Razorpay payment: %s', 'razorpay'), $paymentId),
            sprintf(__('Check order #%d in Billing > Orders, and refund the payment in Razorpay or mark the order paid.', 'razorpay'), $orderId),
        ));

        return true;
    }

    /**
     * A notice on every admin page while test mode is on.
     *
     * @return void
     */
    public static function adminNotice(): void
    {
        if (!osc_billing_enabled() || osc_settings_value(self::PAGE, 'mode') === 'live') {
            return;
        }
        echo '<div class="flashmessage flashmessage-warning" role="status">'
            . osc_esc_html(__('Razorpay is in test mode: no real money is taken. Switch to live mode before the site takes payments.', 'razorpay'))
            . ' <a href="' . osc_esc_html(osc_settings_page_url(self::PAGE)) . '">'
            . osc_esc_html(__('Razorpay settings', 'razorpay')) . '</a></div>';
    }
}
