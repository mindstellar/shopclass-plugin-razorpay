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

use mindstellar\billing\Order;
use mindstellar\billing\OrderStore as Orders;

/**
 * Orders through core's own order store.
 */
final class CoreOrderStore implements OrderStore
{
    public function find(int $id): ?Order
    {
        return Orders::find($id);
    }

    public function findByRef(string $ref): ?Order
    {
        return Orders::findByGatewayRef(RazorpayGateway::ID, $ref);
    }

    public function attachRef(int $orderId, string $ref): bool
    {
        return Orders::attachRef($orderId, RazorpayGateway::ID, $ref);
    }

    public function setMeta(int $orderId, string $key, $value): bool
    {
        return Orders::setMeta($orderId, $key, $value);
    }

    public function markFailed(int $orderId): void
    {
        Orders::settle($orderId, Order::STATUS_FAILED);
    }

    public function stalePending(int $seconds, int $limit): array
    {
        return Orders::pendingBefore(RazorpayGateway::ID, date('Y-m-d H:i:s', time() - $seconds + 1), $limit, 'order_');
    }

    public function buyerEmail(int $userId): ?string
    {
        $user  = osc_db_table(DB_TABLE_PREFIX . 't_user')->where('pk_i_id', $userId)->first();
        $email = is_array($user) ? (string) ($user['s_email'] ?? '') : '';

        return $email === '' ? null : $email;
    }
}
