# Changelog

## 0.1.3

### Changed
- The description and README say the plugin pays for shop orders as well as credit packages.
- The README covers install, settings, what the plugin stores and its hooks.
- The CI tests run against the core branch named by the `SHOPCLASS_CORE_REF` variable, `develop` by default.

## 0.1.2

### Fixed
- Shipped and delivered orders keep the payment link, a late payment for them is not reported as stuck, and goods orders are not told credits were added.

## 0.1.1

### Changed
- A shop order shows what was bought in Razorpay Checkout, not a credits count.

## 0.1.0

### New
- UPI, card, netbanking and wallet payments for credit packages through Razorpay Checkout; no card data on the site.
- The buyer sees the order paid as soon as Checkout closes: its signature is checked and the payment fetched from Razorpay.
- Webhook signatures are checked against the raw body before anything is acted on.
- Payments, refunds and failed attempts reported by webhook update the order; a replay credits nothing.
- A payment the site could not record is queued and fetched again; the admin is e-mailed if it still fails.
- A daily check settles pending orders Razorpay never reported and closes ones left unpaid for two days.
- Authorized payments are captured when the amount matches the order.
- The admin is e-mailed once when Razorpay took money core could not credit, such as a late payment for a closed order.
- Refunds from the admin order screen; full refunds made in Razorpay are recorded too.
- A "View payment" link on the admin order screen opens the payment in the Razorpay dashboard.
- Test and live mode with separate keys; test mode is offered only to signed-in admins.
- INR and Razorpay's international currencies, with zero- and three-decimal currencies charged in the right units.
- Translation template in `languages/razorpay.pot`.
