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
use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\DashboardLinkGateway;
use mindstellar\billing\Order;
use mindstellar\billing\RefundableGateway;
use mindstellar\http\HttpClient;
use mindstellar\http\HttpException;
use Throwable;

/**
 * Razorpay Standard Checkout as a Shopclass payment gateway. Orders settle only from signed
 * webhooks or payments fetched from Razorpay, always through Billing::handleCallback() so core's checks apply.
 */
final class RazorpayGateway implements RefundableGateway, DashboardLinkGateway
{
    /** Gateway id stored on every order. */
    public const ID = 'razorpay';

    /** Order meta key: whether the order was opened with the live keys. */
    public const META_LIVE = 'razorpay_live';

    /** Order meta key set once a payment for an order already closed has been refunded. */
    public const META_CLOSED_REFUND = 'razorpay_closed_refund';

    /** Request key holding a Verified object. */
    public const TRUSTED = 'razorpay_verified';

    /** Events the webhook acts on. Select these in the Razorpay dashboard. */
    public const EVENTS = array(
        'payment.authorized',
        'payment.captured',
        'order.paid',
        'payment.failed',
        'refund.processed',
    );

    /** A pending order with no payment after this many seconds is closed by the daily check. */
    public const UNPAID_AFTER = 172800;

    private RazorpayApi $api;

    /** fn(): array{0:string,1:string,2:string} -- the raw body, X-Razorpay-Signature and X-Razorpay-Event-Id. */
    private Closure $input;

    /** fn(array $request): CallbackResult -- settle a verified request through core. */
    private Closure $settle;

    /** fn(string $paymentId, string $eventId): void -- queue a payment for a later retry. */
    private Closure $queue;

    /** fn(int $orderId, string $paymentId, string $reason): void -- tell the admin about money that was not credited. */
    private Closure $alert;

    public function __construct(
        private Config $config,
        HttpClient $http,
        private OrderStore $orders,
        ?Closure $input = null,
        ?Closure $settle = null,
        ?Closure $queue = null,
        ?Closure $alert = null
    ) {
        $this->api    = new RazorpayApi($http, $config->keyId, $config->keySecret);
        $this->input  = $input ?? static fn (): array => array(
            (string) file_get_contents('php://input'),
            (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? ''),
            (string) ($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? ''),
        );
        $this->settle = $settle ?? static fn (array $request): CallbackResult => Billing::handleCallback(self::ID, $request);
        $this->queue  = $queue ?? static function (string $paymentId, string $eventId): void {
            osc_job_enqueue(
                Plugin::JOB_PAYMENT,
                array('payment' => $paymentId, 'event' => $eventId),
                array('unique_key' => 'payment:' . $paymentId)
            );
        };
        $this->alert  = $alert ?? static function (int $orderId, string $paymentId, string $reason): void {
            Plugin::strandedPayment($orderId, $paymentId, $reason);
        };
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function getName(): string
    {
        return $this->config->name;
    }

    /** Where Settings > Billing links to set this method up. */
    public function settingsUrl(): ?string
    {
        return osc_settings_page_url(Plugin::PAGE);
    }

    /**
     * The currencies listed in settings, or the billing currency when none are.
     *
     * @return string[]
     */
    public function getSupportedCurrencies(): array
    {
        if ($this->config->currencies !== array()) {
            return $this->config->currencies;
        }

        return in_array($this->config->billingCurrency, Money::SUPPORTED, true) ? array($this->config->billingCurrency) : array();
    }

    /**
     * Offered at checkout once the active mode has its keys and Razorpay takes the billing currency.
     * In test mode only a signed-in admin sees it, so a live site cannot hand out free credits.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        if (!$this->hasKeys() || !in_array($this->config->billingCurrency, $this->getSupportedCurrencies(), true)) {
            return false;
        }

        return $this->config->live() || ($this->config->isAdmin !== null && ($this->config->isAdmin)());
    }

    /**
     * Whether the active mode has its key id and key secret, and in live mode a webhook secret too.
     * Test mode works without one: the buyer's return settles the payment.
     *
     * @return bool
     */
    public function hasKeys(): bool
    {
        return $this->config->keyId !== '' && $this->config->keySecret !== ''
            && ($this->config->webhookSecret !== '' || !$this->config->live());
    }

    /**
     * Create a Razorpay order for the order and send the buyer to the pay page.
     *
     * @param Order $order
     *
     * @return CheckoutIntent
     */
    public function createCheckout(Order $order): CheckoutIntent
    {
        $currency = strtoupper($order->getCurrency());
        $minor    = Money::toMinor($order->getAmount(), $currency);
        if ($minor === null || $minor < 1 || !in_array($currency, $this->getSupportedCurrencies(), true)) {
            return $this->checkoutFailed($order, 'amount cannot be charged in ' . $currency);
        }

        try {
            $remote = $this->api->createOrder(array(
                'amount'   => $minor,
                'currency' => $currency,
                'receipt'  => (string) $order->getId(),
                'notes'    => array(
                    'shopclass_order' => (string) $order->getId(),
                    'site'            => substr($this->config->siteName, 0, 250),
                ),
            ));
        } catch (HttpException $e) {
            return $this->checkoutFailed($order, $e->getMessage());
        }

        $id = (string) ($remote['id'] ?? '');
        if (!preg_match('/^order_[A-Za-z0-9]{1,40}$/', $id)
            || ($remote['amount'] ?? null) !== $minor
            || strtoupper((string) ($remote['currency'] ?? '')) !== $currency
        ) {
            return $this->checkoutFailed($order, 'unexpected Razorpay order');
        }

        // Without the Razorpay order on ours, no payment for it could ever be matched.
        if (!$this->orders->attachRef($order->getId(), $id)) {
            $stored = $this->orders->find($order->getId());
            if ($stored === null || !$stored->isPending() || $stored->getExternalRef() !== $id) {
                return $this->checkoutFailed($order, 'could not store ' . $id . ' on the order');
            }
        }
        $this->rememberMode($order->getId());

        return CheckoutIntent::redirect(($this->config->payUrl)($order->getId()));
    }

    /**
     * The options the pay page hands to Razorpay Checkout, or null when the order cannot be paid.
     * Only the key id goes to the browser, never the secret.
     *
     * @param Order       $order
     * @param string|null $email The buyer's e-mail, to fill in
     *
     * @return array<string,mixed>|null
     */
    public function payOptions(Order $order, ?string $email = null): ?array
    {
        $ref   = (string) $order->getExternalRef();
        $minor = Money::toMinor($order->getAmount(), $order->getCurrency());
        if (!$order->isPending() || !str_starts_with($ref, 'order_') || $minor === null || $this->config->keyId === '') {
            return null;
        }

        $options = array(
            'key'         => $this->config->keyId,
            'amount'      => $minor,
            'currency'    => strtoupper($order->getCurrency()),
            'name'        => $this->config->siteName,
            'description' => self::describe($order),
            'order_id'    => $ref,
            'notes'       => array('shopclass_order' => (string) $order->getId()),
        );
        if ($email !== null && $email !== '') {
            $options['prefill'] = array('email' => $email);
        }

        return $options;
    }

    /**
     * What the buyer sees they are paying for: a goods order's label on a core that has one, else the credits.
     *
     * @param Order $order
     *
     * @return string
     */
    private static function describe(Order $order): string
    {
        if (method_exists($order, 'isGoods') && $order->isGoods()) {
            return mb_substr($order->getLabel(), 0, 255);
        }

        return sprintf(__('%d credits', 'razorpay'), $order->getCredits());
    }

    /**
     * Whether Checkout's result is for this pending order's Razorpay order and carries Razorpay's signature.
     *
     * @param Order  $order
     * @param string $remoteId  razorpay_order_id as posted
     * @param string $paymentId razorpay_payment_id as posted
     * @param string $signature razorpay_signature as posted
     *
     * @return bool
     */
    public function verifyReturn(Order $order, string $remoteId, string $paymentId, string $signature): bool
    {
        $ref = (string) $order->getExternalRef();
        if (!$order->isPending() || !str_starts_with($ref, 'order_') || !hash_equals($ref, $remoteId)) {
            return false;
        }

        return Signature::checkout($ref, $paymentId, $signature, $this->config->keySecret);
    }

    /**
     * Read a callback: a signed webhook from Razorpay, or a Verified object from this plugin.
     *
     * @param array $request
     *
     * @return CallbackResult
     */
    public function handleCallback(array $request): CallbackResult
    {
        if (!$this->hasKeys()) {
            // Retryable: Razorpay sends the webhook again once the keys are in.
            return CallbackResult::ignored('razorpay is not configured', true);
        }

        $verified = $request[self::TRUSTED] ?? null;
        if ($verified instanceof Verified) {
            return $verified->kind === Verified::PAYMENT
                ? $this->fromPayment($this->api->payment($verified->paymentId), $verified->orderId)
                : $this->fromEvent($verified->event);
        }

        return $this->webhook();
    }

    /**
     * Refund a paid order in full.
     *
     * @param Order $order
     *
     * @return CallbackResult
     */
    public function refund(Order $order): CallbackResult
    {
        try {
            $payment = $this->paymentOf($order);
            if ($payment === null) {
                return CallbackResult::ignored('no Razorpay payment found for this order');
            }
            // The receipt makes Razorpay refuse a second refund for the same order.
            $refund = $this->api->refund($payment, array(
                'receipt' => 'refund-order-' . $order->getId(),
                'notes'   => array('shopclass_order' => (string) $order->getId()),
            ));
        } catch (HttpException $e) {
            return CallbackResult::ignored(self::short($e->getMessage()));
        }

        $id     = (string) ($refund['id'] ?? '');
        $status = (string) ($refund['status'] ?? '');
        if (!str_starts_with($id, 'rfnd_')) {
            return CallbackResult::ignored('Razorpay did not accept the refund');
        }
        if ($status === 'processed') {
            return CallbackResult::refunded($order->getId(), $id);
        }
        if ($status === 'pending') {
            // The refund.processed webhook settles the order once Razorpay finishes.
            return CallbackResult::ignored('Razorpay is processing the refund; the order updates when it completes.');
        }

        return CallbackResult::ignored('Razorpay did not accept the refund' . ($status !== '' ? ' (' . $status . ')' : ''));
    }

    /**
     * The order's payment in the Razorpay dashboard. The dashboard picks test or live itself.
     *
     * @param Order $order
     *
     * @return string|null
     */
    public function dashboardUrl(Order $order): ?string
    {
        $ref = (string) $order->getExternalRef();
        if (!preg_match('/^pay_[A-Za-z0-9]{1,40}$/', $ref)) {
            return null;
        }
        $settled = defined('mindstellar\\billing\\Order::SETTLED') ? Order::SETTLED : array(Order::STATUS_PAID);
        if (!in_array($order->getStatus(), array_merge($settled, array(Order::STATUS_REFUNDED)), true)) {
            return null;
        }

        return 'https://dashboard.razorpay.com/app/payments/' . $ref;
    }

    /**
     * Fetch a payment from Razorpay and settle its order. The return page and the queued job run this.
     *
     * @param string   $paymentId
     * @param int|null $orderId The order the payment must belong to, if known
     *
     * @return CallbackResult
     * @throws Throwable when Razorpay or the database fails, so the caller can retry
     */
    public function processPayment(string $paymentId, ?int $orderId = null): CallbackResult
    {
        if (!preg_match('/^pay_[A-Za-z0-9]{1,40}$/', $paymentId)) {
            return CallbackResult::ignored('malformed payment id');
        }

        return ($this->settle)(array(self::TRUSTED => Verified::payment($paymentId, $orderId)));
    }

    /**
     * Check pending orders Razorpay never told us about, and close ones left unpaid too long.
     *
     * @param int      $olderThan Seconds
     * @param int      $limit
     * @param int|null $now       Current time, for tests
     *
     * @return int how many were looked up
     * @throws HttpException when any lookup failed, so the job is retried
     */
    public function reconcile(int $olderThan = 3600, int $limit = 100, ?int $now = null): int
    {
        $now     = $now ?? time();
        $checked = 0;
        $errors  = array();
        foreach ($this->orders->stalePending($olderThan, $limit) as $order) {
            $live = $order->meta(self::META_LIVE);
            if (is_bool($live) && $live !== $this->config->live()) {
                // Opened in the other mode: this mode's keys cannot see it.
                continue;
            }
            try {
                $this->checkOrder($order, $now);
                $checked++;
            } catch (Throwable $e) {
                $errors[] = '#' . $order->getId() . ': ' . self::short($e->getMessage());
            }
        }
        if ($errors !== array()) {
            throw new HttpException('Some Razorpay orders could not be checked: ' . implode('; ', $errors));
        }

        return $checked;
    }

    /**
     * Settle one pending order from its Razorpay payments.
     *
     * @param Order $order
     * @param int   $now
     *
     * @return void
     */
    private function checkOrder(Order $order, int $now): void
    {
        $payments = $this->api->orderPayments((string) $order->getExternalRef());
        foreach (array('captured', 'authorized') as $wanted) {
            foreach ($payments as $payment) {
                if (($payment['status'] ?? '') === $wanted && is_string($payment['id'] ?? null)) {
                    $this->processPayment($payment['id'], $order->getId());

                    return;
                }
            }
        }

        if ($now - (int) strtotime($order->getDate()) > self::UNPAID_AFTER) {
            $this->orders->markFailed($order->getId());
        }
    }

    /**
     * The webhook: check the signature against the raw body, then settle the event.
     *
     * @return CallbackResult
     */
    private function webhook(): CallbackResult
    {
        if ($this->config->webhookSecret === '') {
            return CallbackResult::ignored('no webhook secret is set');
        }
        [$body, $header, $eventId] = ($this->input)() + array('', '', '');
        if (!Signature::webhook((string) $body, (string) $header, $this->config->webhookSecret)) {
            return CallbackResult::ignored('bad signature');
        }

        $event = json_decode((string) $body, true);
        if (!is_array($event) || ($event['entity'] ?? null) !== 'event' || !is_string($event['event'] ?? null)) {
            return CallbackResult::ignored('malformed event');
        }
        if (!in_array($event['event'], self::EVENTS, true)) {
            return CallbackResult::ignored('event not handled');
        }

        try {
            $result = ($this->settle)(array(self::TRUSTED => Verified::event($event)));
        } catch (Throwable $e) {
            $payment = self::paymentEntity($event);
            $id      = is_array($payment) ? (string) ($payment['id'] ?? '') : '';
            if (preg_match('/^pay_[A-Za-z0-9]{1,40}$/', $id)) {
                try {
                    ($this->queue)($id, substr((string) $eventId, 0, 100));
                } catch (Throwable $ignored) {
                    // The queue is down too. Razorpay still retries the webhook.
                }
            }

            return CallbackResult::ignored('could not settle, queued: ' . self::short($e->getMessage()), true);
        }

        return CallbackResult::ignored('settled as ' . $result->getOutcome());
    }

    /**
     * What one verified event means for its order.
     *
     * @param array<string,mixed> $event
     *
     * @return CallbackResult
     */
    private function fromEvent(array $event): CallbackResult
    {
        $payment = self::paymentEntity($event);
        if ($payment === null) {
            return CallbackResult::ignored('event has no payment');
        }

        switch ($event['event'] ?? '') {
            case 'payment.failed':
                // The buyer can try again in the same window, so the order stays open.
                return CallbackResult::ignored('a payment attempt failed');

            case 'payment.authorized':
            case 'payment.captured':
            case 'order.paid':
            case 'refund.processed':
                // The event is only a trigger: a signed retry can be days old, so act on the payment as it is now.
                $id = (string) ($payment['id'] ?? '');
                if (!preg_match('/^pay_[A-Za-z0-9]{1,40}$/', $id)) {
                    return CallbackResult::ignored('not a payment');
                }

                return $this->fromPayment($this->api->payment($id), null);

            default:
                return CallbackResult::ignored('event not handled');
        }
    }

    /**
     * A payment Razorpay vouches for: captured, refunded, waiting to be captured, or not paid.
     *
     * @param array<string,mixed> $payment
     * @param int|null            $expected The order the caller expects, if any
     *
     * @return CallbackResult
     */
    private function fromPayment(array $payment, ?int $expected): CallbackResult
    {
        $id = (string) ($payment['id'] ?? '');
        if (!str_starts_with($id, 'pay_')) {
            return CallbackResult::ignored('not a payment');
        }
        $order = $this->paymentOrder($payment);
        if ($order === null || ($expected !== null && $order->getId() !== $expected)) {
            return CallbackResult::ignored('not an order of this site');
        }

        $amount   = $payment['amount'] ?? null;
        $currency = strtoupper((string) ($payment['currency'] ?? ''));
        $refunded = $payment['amount_refunded'] ?? 0;
        if (($payment['status'] ?? '') === 'refunded' || (is_int($amount) && $amount > 0 && is_int($refunded) && $refunded >= $amount)) {
            if (!$this->sameMoney($order, $amount, $currency)) {
                return CallbackResult::ignored('refund for another amount');
            }

            return CallbackResult::refunded($order->getId(), $id);
        }

        if (($payment['status'] ?? '') === 'authorized') {
            if (!$order->isPending()) {
                return CallbackResult::ignored('order already settled');
            }
            if (!$this->sameMoney($order, $amount, $currency)) {
                // Capturing would take money core then refuses to credit.
                return CallbackResult::ignored('authorized amount differs from the order');
            }
            $payment = $this->capture($id, (int) $amount, $currency);
        }

        $status = (string) ($payment['status'] ?? '');
        if ($status === 'captured' && in_array($order->getStatus(), array(Order::STATUS_FAILED, Order::STATUS_CANCELLED), true)) {
            return $this->refundClosed($order, $id);
        }
        if ($status === 'captured') {
            $stranded = $this->strandedReason($order, $payment);
            if ($stranded !== null) {
                ($this->alert)($order->getId(), $id, $stranded);
            }
            // Razorpay's own figures; core refuses them when they differ from the order.
            return CallbackResult::paid(
                $order->getId(),
                $id,
                Money::toMicros((int) ($payment['amount'] ?? -1), (string) ($payment['currency'] ?? '')),
                strtoupper((string) ($payment['currency'] ?? ''))
            );
        }
        if ($status === 'failed') {
            return CallbackResult::ignored('a payment attempt failed');
        }

        return CallbackResult::ignored('not paid yet');
    }

    /**
     * Give back money Razorpay took for an order core had already closed. Done once per order; a
     * refund Razorpay could not be asked for throws, so it is retried. A refused one goes to the admin.
     *
     * @param Order  $order
     * @param string $paymentId
     *
     * @return CallbackResult
     * @throws HttpException when Razorpay cannot be reached or fails
     */
    private function refundClosed(Order $order, string $paymentId): CallbackResult
    {
        if ($order->meta(self::META_CLOSED_REFUND) !== null) {
            return CallbackResult::ignored('payment for a closed order was already handled');
        }
        try {
            // The receipt makes Razorpay refuse a second refund for the same order.
            $refund = $this->api->refund($paymentId, array(
                'receipt' => 'closed-order-' . $order->getId(),
                'notes'   => array('shopclass_order' => (string) $order->getId()),
            ));
        } catch (HttpException $e) {
            $code = $e->getCode();
            if ($code < 400 || $code >= 500 || $code === 401) {
                throw $e;
            }
            $this->rememberClosed($order->getId(), 'refused');
            ($this->alert)($order->getId(), $paymentId, 'the order is ' . $order->getStatus() . ' and Razorpay refused the refund: ' . self::short($e->getMessage()));

            return CallbackResult::ignored('payment for a closed order; refund refused');
        }

        $refundId = (string) ($refund['id'] ?? '');
        if (!str_starts_with($refundId, 'rfnd_')) {
            throw new HttpException('Razorpay did not refund payment ' . $paymentId);
        }
        $this->rememberClosed($order->getId(), $refundId);
        error_log('Razorpay: payment ' . $paymentId . ' for order #' . $order->getId() . ' arrived after the order was closed and was refunded.');

        return CallbackResult::ignored('payment for a closed order; refunded');
    }

    /**
     * Note on the order that its late payment was handled.
     *
     * @param int    $orderId
     * @param string $value
     *
     * @return void
     */
    private function rememberClosed(int $orderId, string $value): void
    {
        try {
            if (!$this->orders->setMeta($orderId, self::META_CLOSED_REFUND, $value)) {
                error_log('Razorpay: could not store the refund on order #' . $orderId);
            }
        } catch (Throwable $e) {
            error_log('Razorpay: could not store the refund on order #' . $orderId . ': ' . $e->getMessage());
        }
    }

    /**
     * Whether the order's status counts as paid (paid, shipped or delivered).
     *
     * @param Order $order
     *
     * @return bool
     */
    private function isSettled(Order $order): bool
    {
        return defined('mindstellar\\billing\\Order::SETTLED')
            ? in_array($order->getStatus(), Order::SETTLED, true)
            : $order->getStatus() === Order::STATUS_PAID;
    }

    /**
     * Why a captured payment will not be credited to its order, or null when it will be (or already was).
     *
     * @param Order               $order
     * @param array<string,mixed> $payment
     *
     * @return string|null
     */
    private function strandedReason(Order $order, array $payment): ?string
    {
        if (!$this->sameMoney($order, $payment['amount'] ?? null, $payment['currency'] ?? null)) {
            return 'the amount or currency differs from the order';
        }
        $ref = (string) $order->getExternalRef();
        if ($order->isPending() || $ref === (string) $payment['id']) {
            return null;
        }
        if ($this->isSettled($order) && $ref === (string) ($payment['order_id'] ?? '')) {
            return null;
        }

        return 'the order is ' . $order->getStatus();
    }

    /**
     * Capture an authorized payment. When Razorpay refuses, as it does for one already
     * captured, the payment is fetched again so the caller sees where it stands.
     *
     * @param string $id
     * @param int    $amount
     * @param string $currency
     *
     * @return array<string,mixed>
     */
    private function capture(string $id, int $amount, string $currency): array
    {
        try {
            return $this->api->capture($id, $amount, $currency);
        } catch (HttpException $e) {
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                return $this->api->payment($id);
            }
            throw $e;
        }
    }

    /**
     * The order a payment was made for, found by the Razorpay order id stored at checkout,
     * or by the payment id stored once paid. Another site's payment finds nothing here.
     *
     * @param array<string,mixed> $payment
     *
     * @return Order|null
     */
    private function paymentOrder(array $payment): ?Order
    {
        $remote = (string) ($payment['order_id'] ?? '');
        $order  = str_starts_with($remote, 'order_') ? $this->orders->findByRef($remote) : null;
        $order  = $order ?? $this->orders->findByRef((string) $payment['id']);

        return $order !== null && $order->getGateway() === self::ID ? $order : null;
    }

    /**
     * Whether Razorpay's amount and currency are the order's.
     *
     * @param Order $order
     * @param mixed $minor
     * @param mixed $currency
     *
     * @return bool
     */
    private function sameMoney(Order $order, $minor, $currency): bool
    {
        return is_int($minor)
            && is_string($currency)
            && strtoupper($currency) === strtoupper($order->getCurrency())
            && Money::toMicros($minor, $currency) === $order->getAmount();
    }

    /**
     * The captured payment behind a paid order.
     *
     * @param Order $order
     *
     * @return string|null
     */
    private function paymentOf(Order $order): ?string
    {
        $ref = (string) $order->getExternalRef();
        if (preg_match('/^pay_[A-Za-z0-9]{1,40}$/', $ref)) {
            return $ref;
        }
        if (str_starts_with($ref, 'order_')) {
            foreach ($this->api->orderPayments($ref) as $payment) {
                if (($payment['status'] ?? '') === 'captured' && is_string($payment['id'] ?? null)) {
                    return $payment['id'];
                }
            }
        }

        return null;
    }

    /**
     * Store the mode the order was opened in, so the daily check skips it after a switch.
     *
     * @param int $orderId
     *
     * @return void
     */
    private function rememberMode(int $orderId): void
    {
        try {
            if (!$this->orders->setMeta($orderId, self::META_LIVE, $this->config->live())) {
                error_log('Razorpay: could not store the mode on order #' . $orderId);
            }
        } catch (Throwable $e) {
            error_log('Razorpay: could not store the mode on order #' . $orderId . ': ' . $e->getMessage());
        }
    }

    /**
     * The payment inside a webhook event.
     *
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>|null
     */
    private static function paymentEntity(array $event): ?array
    {
        $payment = $event['payload']['payment']['entity'] ?? null;

        return is_array($payment) ? $payment : null;
    }

    /**
     * Close the order and send the buyer back to choose again.
     *
     * @param Order  $order
     * @param string $reason
     *
     * @return CheckoutIntent
     */
    private function checkoutFailed(Order $order, string $reason): CheckoutIntent
    {
        error_log('Razorpay: checkout for order #' . $order->getId() . ' failed: ' . self::short($reason));
        $this->orders->markFailed($order->getId());
        if (function_exists('osc_add_flash_error_message')) {
            osc_add_flash_error_message(__('Razorpay payment could not be started. Please try again later.', 'razorpay'));
        }

        return CheckoutIntent::redirect($this->config->cancelUrl);
    }

    /**
     * One line, at most 200 characters, for a log or the admin.
     *
     * @param string $message
     *
     * @return string
     */
    private static function short(string $message): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return strlen($message) > 200 ? substr($message, 0, 197) . '...' : $message;
    }
}
