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

use mindstellar\razorpay\Http\HttpClient;
use mindstellar\razorpay\Http\HttpException;

/**
 * The few Razorpay REST calls the gateway makes, with Basic auth. Every answer is decoded JSON.
 */
final class RazorpayApi
{
    public const BASE = 'https://api.razorpay.com/v1/';

    public function __construct(
        private HttpClient $http,
        private string $keyId,
        private string $keySecret
    ) {
    }

    /**
     * @param array<string,mixed> $params amount, currency, receipt, notes
     *
     * @return array<string,mixed>
     */
    public function createOrder(array $params): array
    {
        return $this->call('POST', 'orders', $params);
    }

    /**
     * @param string $id order_...
     *
     * @return array<string,mixed>
     */
    public function order(string $id): array
    {
        return $this->call('GET', 'orders/' . rawurlencode($id));
    }

    /**
     * The payments made against a Razorpay order.
     *
     * @param string $id order_...
     *
     * @return array<int,array<string,mixed>>
     */
    public function orderPayments(string $id): array
    {
        $items = $this->call('GET', 'orders/' . rawurlencode($id) . '/payments')['items'] ?? array();

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : array();
    }

    /**
     * @param string $id pay_...
     *
     * @return array<string,mixed>
     */
    public function payment(string $id): array
    {
        return $this->call('GET', 'payments/' . rawurlencode($id));
    }

    /**
     * Capture an authorized payment.
     *
     * @param string $id       pay_...
     * @param int    $amount   Smallest units
     * @param string $currency
     *
     * @return array<string,mixed> the payment
     */
    public function capture(string $id, int $amount, string $currency): array
    {
        return $this->call('POST', 'payments/' . rawurlencode($id) . '/capture', array('amount' => $amount, 'currency' => $currency));
    }

    /**
     * Refund a payment. Razorpay refuses a second refund with the same receipt.
     *
     * @param string              $id     pay_...
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed> the refund
     */
    public function refund(string $id, array $params): array
    {
        return $this->call('POST', 'payments/' . rawurlencode($id) . '/refund', $params);
    }

    /**
     * @param string              $method
     * @param string              $path
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     * @throws HttpException on a transport error, an error status or a body that is not JSON
     */
    private function call(string $method, string $path, array $params = array()): array
    {
        if ($this->keyId === '' || $this->keySecret === '') {
            throw new HttpException('No Razorpay API key is set');
        }

        $headers = array(
            'Authorization' => 'Basic ' . base64_encode($this->keyId . ':' . $this->keySecret),
            'Accept'        => 'application/json',
        );
        $body = null;
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/json';
            $body                    = (string) json_encode($params === array() ? new \stdClass() : $params, JSON_UNESCAPED_SLASHES);
        }

        $response = $this->http->send($method, self::BASE . $path, $headers, $body);
        $decoded  = json_decode($response['body'], true);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = is_array($decoded) && is_array($decoded['error'] ?? null) ? (string) ($decoded['error']['description'] ?? '') : '';
            throw new HttpException('Razorpay answered ' . $response['status'] . ($message !== '' ? ': ' . $message : ''), $response['status']);
        }
        if (!is_array($decoded)) {
            throw new HttpException('Razorpay sent a response that is not JSON');
        }

        return $decoded;
    }
}
