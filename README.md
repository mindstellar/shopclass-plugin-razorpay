# Razorpay Payment

Take UPI, card, netbanking and wallet payments for shop orders and credit packages through [Razorpay Standard Checkout](https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/). Buyers pay in Razorpay's own window, so no card data reaches your site.

## Requirements

- Shopclass 7.0.0 or later, with billing turned on
- PHP 8.0 or later
- A Razorpay account with API keys
- Cron or core's auto-cron, for retries and the daily check of pending orders

## Install

1. Open **Plugins → Browse** (or upload the zip from the GitHub release).
2. Install.
3. Activate.

## Set up

1. Open **Settings → Billing → Payment methods** and choose **Set up** next to Razorpay Payment.
2. In the Razorpay dashboard, switch to test mode. Under **Accounts & Settings → API keys**, make a key. Copy the key id (`rzp_test_…`) into **Test key id** and the secret into **Test key secret**.
3. Under **Accounts & Settings → Webhooks**, add a webhook with the URL shown under **Webhook URL**:

   ```
   https://example.com/index.php?page=billing&action=callback&gateway=razorpay
   ```

   Set a secret and select `payment.authorized`, `payment.captured`, `order.paid`, `payment.failed` and `refund.processed`.
4. Type the same secret into **Test webhook secret** and save.
5. Turn on automatic capture under **Accounts & Settings → Payment capture**. If a payment still arrives only authorized, the plugin captures it when the amount matches the order.
6. To go live, make live keys, add a second webhook in live mode with the same URL and events, fill the **Live** fields, then set **Mode** to *Live*.

Saved secrets are never shown again. Leave a secret's box blank to keep it.

Optional: **Name at checkout** sets the label buyers see. **Currencies** takes codes such as `INR, USD`.

## How it works

- At checkout the plugin creates a Razorpay order for the exact amount and sends the buyer to a pay page on your site. Only that page loads Razorpay's `checkout.js`.
- On success the page posts Razorpay's signature back. The plugin checks it, fetches the payment from Razorpay and settles the order.
- The webhook settles it too, for a buyer who closes the tab. The plugin fetches the payment before acting, so an old retried webhook cannot pay a refunded order.
- A failed attempt leaves the order open so the buyer can try another method.
- A payment for an order already closed is refunded at Razorpay. If that refund is refused, the site's contact address gets one e-mail.
- Once a day, pending orders over an hour old are checked with Razorpay. Orders unpaid after two days are closed.
- To refund, open the order under **Billing → Orders** and press **Refund**. The whole payment is refunded and core takes the credits back. A full refund made in the Razorpay dashboard is recorded the same way.

## Notes / limits

- The plugin starts in test mode, and an admin notice says so. In test mode only a signed-in admin sees Razorpay at checkout. Use Razorpay's [test cards and UPI ids](https://razorpay.com/docs/payments/payments/test-card-upi-details/).
- Razorpay shows at checkout once the active mode has a key id and secret, and the billing currency is allowed.
- The webhook secret is optional in test mode and required in live mode.
- **Currencies** empty means the billing currency. A list without the billing currency hides Razorpay. Any currency but INR needs international payments on in your Razorpay account.
- Partial refunds change nothing on the site.
- The pay page needs JavaScript.
- Several sites can share one account. Give each site its own webhook.
- Uninstalling removes the settings, keys included. Orders and credits stay. Remove the webhook in Razorpay too.
- No tables of its own. Retries and the daily check use core's job queue.

## For developers

Registers the gateway on `register_payment_gateways` and listens to `register_jobs`, `cron_daily` and `job_gave_up`. It fires no hooks.

Translations: copy `languages/razorpay.pot` to `languages/<locale>/messages.po`, translate, and compile to `messages.mo`. Rebuild the template with `php tools/make-pot.php`.

Tests: `./tests/run.sh` (unit) and `php tests/integration/billing.php` (needs core's scratch MySQL on port 33061). Core is loaded from `SHOPCLASS_CORE` or a sibling `osclass` checkout.

## Support

[GitHub issues](https://github.com/mindstellar/shopclass-plugin-razorpay/issues)

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
