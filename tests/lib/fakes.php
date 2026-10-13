<?php
/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Stand-ins for Razorpay and for core's order table.
 */

use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\razorpay\Config;
use mindstellar\razorpay\Http\HttpClient;
use mindstellar\razorpay\Http\HttpException;
use mindstellar\razorpay\OrderStore;
use mindstellar\razorpay\RazorpayGateway;
use mindstellar\razorpay\Signature;

/** Answers from a script and records every request. */
final class FakeHttp implements HttpClient
{
    /** @var array<int,array{method:string,url:string,headers:array,body:?string}> */
    public array $requests = array();

    /** @var array<string,array{status:int,body:string}|HttpException> "METHOD path" => answer */
    public array $routes = array();

    public function on(string $method, string $path, int $status, array $body): void
    {
        $this->routes[$method . ' ' . $path] = array('status' => $status, 'body' => json_encode($body));
    }

    public function down(string $method, string $path): void
    {
        $this->routes[$method . ' ' . $path] = new HttpException('Razorpay could not be reached: timeout');
    }

    public function send(string $method, string $url, array $headers, ?string $body = null): array
    {
        $this->requests[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body);
        $path   = substr(strtok($url, '?'), strlen('https://api.razorpay.com/v1/'));
        $answer = $this->routes[$method . ' ' . $path] ?? array('status' => 400, 'body' => '{"error":{"code":"BAD_REQUEST_ERROR","description":"The id provided does not exist"}}');
        if ($answer instanceof HttpException) {
            throw $answer;
        }

        return $answer;
    }

    /** The last request's JSON body, decoded. */
    public function lastBody(): array
    {
        return json_decode((string) end($this->requests)['body'], true) ?? array();
    }

    /** "METHOD path" of the last request. */
    public function lastCall(): string
    {
        $calls = $this->calls();

        return (string) end($calls);
    }

    /** "METHOD path" of every request so far. */
    public function calls(): array
    {
        return array_map(static fn (array $r): string => $r['method'] . ' ' . substr($r['url'], strlen('https://api.razorpay.com/v1/')), $this->requests);
    }
}

/** Orders in an array. */
final class MemoryOrders implements OrderStore
{
    /** @var array<int,Order> */
    public array $orders = array();

    public array $emails = array();

    public function add(int $amount = 499_000_000, string $currency = 'INR', ?string $ref = null, string $status = Order::STATUS_PENDING, string $gateway = 'razorpay', string $date = '2026-01-01 00:00:00', array $meta = array()): Order
    {
        $id                = count($this->orders) + 1;
        $this->orders[$id] = new Order($id, 7, $gateway, $ref, $amount, $currency, 100, $status, $meta, $date);

        return $this->orders[$id];
    }

    public function find(int $id): ?Order
    {
        return $this->orders[$id] ?? null;
    }

    public function findByRef(string $ref): ?Order
    {
        foreach ($this->orders as $order) {
            if ($order->getExternalRef() === $ref && $order->getGateway() === 'razorpay') {
                return $order;
            }
        }

        return null;
    }

    /** As core's OrderStore::attachRef(): pending only, unique per gateway, false when nothing changed. */
    public function attachRef(int $orderId, string $ref): bool
    {
        $order = $this->orders[$orderId] ?? null;
        if ($order === null || !$order->isPending() || $order->getExternalRef() === $ref) {
            return false;
        }
        $owner = $this->findByRef($ref);
        if ($owner !== null && $owner->getId() !== $orderId) {
            return false;
        }
        $this->set($orderId, Order::STATUS_PENDING, $ref);

        return true;
    }

    public function setMeta(int $orderId, string $key, $value): bool
    {
        $o = $this->orders[$orderId] ?? null;
        if ($o === null) {
            return false;
        }
        $meta       = $o->getMeta();
        $meta[$key] = $value;
        $this->orders[$orderId] = new Order($o->getId(), $o->getUserId(), $o->getGateway(), $o->getExternalRef(), $o->getAmount(), $o->getCurrency(), $o->getCredits(), $o->getStatus(), $meta, $o->getDate());

        return true;
    }

    public function markFailed(int $orderId): void
    {
        if ($this->orders[$orderId]->isPending()) {
            $this->set($orderId, Order::STATUS_FAILED, null);
        }
    }

    /** As CoreOrderStore: filtered by age first, oldest first, then limited. */
    public function stalePending(int $seconds, int $limit): array
    {
        $due = array_values(array_filter($this->orders, static fn (Order $o): bool => $o->isPending()
            && str_starts_with((string) $o->getExternalRef(), 'order_')
            && strtotime($o->getDate()) <= time() - $seconds));
        usort($due, static fn (Order $a, Order $b): int => array($a->getDate(), $a->getId()) <=> array($b->getDate(), $b->getId()));

        return array_slice($due, 0, $limit);
    }

    public function buyerEmail(int $userId): ?string
    {
        return $this->emails[$userId] ?? null;
    }

    public function set(int $id, string $status, ?string $ref): void
    {
        $o                 = $this->orders[$id];
        $this->orders[$id] = new Order($o->getId(), $o->getUserId(), $o->getGateway(), $ref ?? $o->getExternalRef(), $o->getAmount(), $o->getCurrency(), $o->getCredits(), $status, $o->getMeta(), $o->getDate());
    }
}

const KEY_ID     = 'rzp_test_AbC123';
const KEY_SECRET = 'test_key_secret_123';
const WHSEC      = 'test_webhook_secret';

function fake_config(array $over = array()): Config
{
    $v = $over + array(
        'mode'       => 'test',
        'key'        => KEY_ID,
        'secret'     => KEY_SECRET,
        'webhook'    => WHSEC,
        'currencies' => array(),
        'billing'    => 'INR',
        'admin'      => true,
    );
    $admin = $v['admin'];

    return new Config(
        $v['mode'],
        $v['key'],
        $v['secret'],
        $v['webhook'],
        'UPI, cards, netbanking (Razorpay)',
        $v['currencies'],
        $v['billing'],
        'Shop Test',
        static fn (int $id): string => 'https://shop.test/razorpay/pay/' . $id,
        'https://shop.test/buy',
        static fn (): bool => $admin
    );
}

/**
 * A gateway on fakes. $GLOBALS['settled'] records what reached the settle step, and
 * $GLOBALS['queued'] what was queued.
 */
function fake_gateway(FakeHttp $http, MemoryOrders $orders, array $config = array(), ?Closure $settle = null): RazorpayGateway
{
    $GLOBALS['settled'] = array();
    $GLOBALS['queued']  = array();
    $GLOBALS['input']   = array('', '', '');
    $GLOBALS['alerts']  = array();

    $gateway = null;
    $gateway = new RazorpayGateway(
        fake_config($config),
        $http,
        $orders,
        static fn (): array => $GLOBALS['input'],
        $settle ?? static function (array $request) use (&$gateway): CallbackResult {
            $result               = $gateway->handleCallback($request);
            $GLOBALS['settled'][] = $result;

            return $result;
        },
        static function (string $paymentId, string $eventId): void {
            $GLOBALS['queued'][] = array($paymentId, $eventId);
        },
        static function (int $orderId, string $paymentId, string $reason): void {
            $GLOBALS['alerts'][] = array($orderId, $paymentId, $reason);
        }
    );

    return $gateway;
}

/** A gateway whose settle step writes the outcome to the in-memory orders, as core would. */
function settling_gateway(FakeHttp $http, MemoryOrders $orders, array $config = array()): RazorpayGateway
{
    $gateway = null;
    $gateway = fake_gateway($http, $orders, $config, static function (array $request) use (&$gateway, $orders): CallbackResult {
        $result               = $gateway->handleCallback($request);
        $GLOBALS['settled'][] = $result;
        $id                   = $result->getOrderId();
        $order                = $id === null ? null : $orders->find($id);
        if ($order !== null && $order->isPending() && in_array($result->getOutcome(), array('paid', 'failed'), true)) {
            $orders->set($id, $result->getOutcome(), $result->getExternalRef());
        } elseif ($order !== null && $order->isPaid() && $result->getOutcome() === 'refunded') {
            $orders->set($id, 'refunded', null);
        }

        return $result;
    });

    return $gateway;
}

/** A payment as Razorpay returns it. */
function payment(Order $order, array $over = array()): array
{
    return $over + array(
        'id'              => 'pay_Test' . $order->getId(),
        'entity'          => 'payment',
        'amount'          => 49900,
        'currency'        => 'INR',
        'status'          => 'captured',
        'order_id'        => 'order_Test' . $order->getId(),
        'method'          => 'upi',
        'amount_refunded' => 0,
        'captured'        => true,
        'notes'           => array('shopclass_order' => (string) $order->getId()),
    );
}

/** A webhook event wrapping a payment. */
function event(string $type, array $payment, array $extra = array()): array
{
    return array(
        'entity'     => 'event',
        'account_id' => 'acc_Test',
        'event'      => $type,
        'contains'   => array('payment'),
        'payload'    => array('payment' => array('entity' => $payment)) + $extra,
        'created_at' => 1790000000,
    );
}

/** Have the fake API answer for the payment inside an event as the event says it is. */
function serve(FakeHttp $http, array $event): array
{
    $payment = $event['payload']['payment']['entity'] ?? null;
    if (is_array($payment) && isset($payment['id'])) {
        $http->on('GET', 'payments/' . $payment['id'], 200, $payment);
    }

    return $event;
}

/** Put a signed webhook on the fake input. */
function deliver(array $event, string $secret = WHSEC, string $eventId = 'evt_1'): string
{
    $body             = json_encode($event);
    $GLOBALS['input'] = array($body, Signature::signWebhook($body, $secret), $eventId);

    return $body;
}
