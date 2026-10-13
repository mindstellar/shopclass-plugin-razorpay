# Razorpay Payment

Take UPI, card, netbanking and wallet payments for ShopClass credit packages through
[Razorpay Standard Checkout](https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/).
Buyers pay in Razorpay's own window, so no card data reaches your site. Core adds the
credits once the payment is confirmed.

## Requirements

- ShopClass 7.0.0 or later, with billing turned on
- PHP 8.0 or later, with the curl extension
- Cron running, for retries and the daily check of pending orders

No shell, worker or Redis is needed. On a VPS with core's job worker, retries just run sooner.

## Setup

1. Install and activate the plugin, then open **Plugins → Razorpay Payment**.
2. In the Razorpay dashboard, switch to test mode, open **Accounts & Settings → API keys**
   and make a key. Copy the key id (`rzp_test_…`) and the key secret into the test fields.
3. Open **Accounts & Settings → Webhooks**, add a webhook with the URL the settings page
   shows:

   ```
   https://example.com/index.php?page=billing&action=callback&gateway=razorpay
   ```

   set a secret, and select these events:

   - `payment.authorized`
   - `payment.captured`
   - `order.paid`
   - `payment.failed`
   - `refund.processed`

4. Type the same webhook secret into *Test webhook secret* and save.
5. Turn on automatic capture under **Accounts & Settings → Payment capture**. If a payment
   still arrives only authorized, the plugin captures it when the amount matches the order.

Razorpay shows at checkout once the key id, key secret and webhook secret for the active
mode are set and the site's billing currency is one Razorpay takes. Any currency but INR
needs international payments turned on in your Razorpay account.

Saved secrets are never shown again. Leave a secret's box blank to keep it.

## Test mode

The plugin starts in test mode, and an admin notice says so. In test mode only a
signed-in admin sees Razorpay at checkout, so a live site cannot give credits away. Pay
with Razorpay's [test cards and UPI ids](https://razorpay.com/docs/payments/payments/test-card-upi-details/).

To go live, make live keys, add a second webhook in live mode with the same URL and events,
fill in the live fields, then switch *Mode* to *Live*.

## How an order is paid

- At checkout the plugin creates a Razorpay order for the exact amount and sends the buyer
  to a pay page on your site. That page alone loads Razorpay's `checkout.js`.
- When the payment succeeds, the page posts Razorpay's signature back. The plugin checks it,
  fetches the payment from Razorpay and settles the order, so the buyer sees "Paid" at once.
- The webhook settles it too, for a buyer who closes the tab.
- A failed attempt leaves the order open, so the buyer can try another method.
- If the site cannot record a payment, it is queued and fetched again later, and Razorpay
  resends the webhook. If it still fails, the site's contact address gets an e-mail.
- Once a day, pending Razorpay orders over an hour old are checked with Razorpay. One left
  unpaid for two days is closed.

Every path goes through core's billing checks: amount, currency and owner must match,
and a replayed webhook credits nothing.

The pay page needs JavaScript. Without it the buyer is told to turn it on.

## Refunds

Open the order under **Billing → Orders** and press **Refund**. The plugin refunds the
whole payment at Razorpay and core takes the credits back. If Razorpay is still processing
the refund, the order changes once the `refund.processed` webhook arrives. A full refund
made in the Razorpay dashboard is recorded the same way. Partial refunds change nothing on
the site.

## Several sites, one Razorpay account

Each site only acts on payments for Razorpay orders it created, so several sites can share
an account. Give each site its own webhook.

## Translations

Copy `languages/razorpay.pot` to `languages/<locale>/messages.po`, translate it, and compile
it to `messages.mo` in the same folder. After changing strings, run `php tools/make-pot.php`.

## Tests

```bash
./tests/run.sh                                  # unit tests, no network
php tests/integration/billing.php               # core's scratch MySQL on port 33061
```

Both load core from `SHOPCLASS_CORE`, or a sibling checkout named `osclass`.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
