/*
 * This file is part of the Razorpay Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

(function () {
    'use strict';

    function start() {
        var button = document.getElementById('razorpay-pay');
        var form = document.getElementById('razorpay-return');
        var status = document.getElementById('razorpay-status');
        if (!button || !form || !status) {
            return;
        }

        var say = function (text) {
            status.textContent = text;
        };
        if (typeof window.Razorpay !== 'function') {
            say(button.getAttribute('data-unavailable'));
            return;
        }

        var options;
        try {
            options = JSON.parse(button.getAttribute('data-options'));
        } catch (e) {
            return;
        }

        options.handler = function (response) {
            form.elements.razorpay_order_id.value = response.razorpay_order_id || '';
            form.elements.razorpay_payment_id.value = response.razorpay_payment_id || '';
            form.elements.razorpay_signature.value = response.razorpay_signature || '';
            button.disabled = true;
            say(button.getAttribute('data-confirming'));
            form.submit();
        };
        options.modal = {
            ondismiss: function () {
                button.disabled = false;
            }
        };

        var checkout = new window.Razorpay(options);
        checkout.on('payment.failed', function (response) {
            var detail = response && response.error && response.error.description ? ' ' + response.error.description : '';
            say(button.getAttribute('data-failed') + detail);
        });

        button.disabled = false;
        button.addEventListener('click', function (event) {
            event.preventDefault();
            button.disabled = true;
            say('');
            checkout.open();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
