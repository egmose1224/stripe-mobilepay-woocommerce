# Stripe MobilePay for WooCommerce

Unofficial MobilePay payment method for WooCommerce on your existing Stripe account (through the official
WooCommerce Stripe Payment Gateway): reserved at checkout, captured when the order is completed.

**Requires the official [WooCommerce Stripe Payment Gateway](https://wordpress.org/plugins/woocommerce-gateway-stripe/)
plugin by WooCommerce**, installed and connected to your Stripe account. This plugin has no API keys of its own: each
time it calls Stripe, it reads the secret key for the current mode (test or live) from that plugin's settings.

> **Unofficial** — not affiliated with or endorsed by Stripe, Vipps MobilePay or WooCommerce/Automattic.

> **Provided "as is", without warranty of any kind.** This is payment software, and you use it entirely at your own
> risk. The authors and contributors accept no liability for any loss or damage arising from its use — including
> failed, missing, duplicated or incorrect payments, captures or refunds, lost revenue, fees or chargebacks. Test it
> thoroughly in Stripe's test mode before you take real payments, and keep an eye on your orders and your Stripe
> dashboard.

Used in production at [vooma.dk](https://vooma.dk).

## Why

Stripe supports MobilePay (Denmark and Finland), but the official WooCommerce Stripe Payment Gateway plugin doesn't
offer it, and it leaves out payment methods it doesn't know — so switching MobilePay on in the Stripe dashboard doesn't
bring it to your checkout. This plugin adds MobilePay as a WooCommerce payment method of its own on the same Stripe
account: it creates MobilePay PaymentIntents through Stripe's API, sends the customer to MobilePay, and keeps every
order in step with Stripe.

## How it works

- **Hold at checkout.** The PaymentIntent uses manual capture, so the amount is only reserved. The customer approves
  in the MobilePay app (on a computer, MobilePay's page pushes the request to the phone). Once approved, the order
  becomes **Processing** — like a card payment that is authorized but not yet captured.
- **Capture when Completed.** Marking the order **Completed** (shipped) captures the amount: never more than the hold,
  never more than the order still costs after refunds. A transient error is retried after 5 minutes, 30 minutes and
  2 hours; a capture that fails for good sets the order to **Failed** and e-mails the shop, saying whether the hold still
  lives (then marking the order Completed again captures it). A hold expires 7 days after the approval.
- **Refunds.** WooCommerce's refund button works before and after the capture. After it: a Stripe refund. Before it:
  the refund lowers the coming capture, and a full refund releases the hold. Refunds made in the Stripe dashboard are
  booked in WooCommerce too.
- **Cancellations.** A **Cancelled** order's hold is released — or, if the money was captured, it is refunded.
  **Refunded** set by hand releases a hold; captured money is not moved by the status alone (as for card orders).
- **Duplicate and late approvals.** Every attempt is its own PaymentIntent. Whichever attempt the customer approves pays
  the order; a second approval on a paid order is released (or refunded, if captured) at once, with an order note and
  an e-mail to the shop — never two holds, never two charges. So is a MobilePay approval on an order the customer paid
  another way in the meantime.
- **Disputes** are noted on the order and e-mailed to the shop; you answer them in the Stripe dashboard.

**Three paths into one state machine.** Stripe's answer reaches the shop three ways, and whichever comes first does the
work — the others find it done:

1. the customer's **return** from MobilePay (identified by the order key, so it works even in another browser),
2. the plugin's own **signed webhook** (one endpoint per mode; its signing secret is stored encrypted),
3. **reconciliation**: follow-ups 6 minutes, 20 minutes and 2 hours after each attempt, an hourly sweep, and a check
   right before WooCommerce cancels an unpaid order.

**What keeps the money right**

- Nothing from the browser or a webhook payload is trusted beyond an ID: each decision fetches the PaymentIntent from
  Stripe and checks that it belongs to this site, this order, this amount and this currency.
- Everything that moves money runs under a per-order database lock (MySQL `GET_LOCK`), so the return, the webhook and
  the sweep never act on the same order at the same time.
- Every Stripe request that creates or moves money carries its own idempotency key; retries and repeated events cannot
  take or refund money twice.
- The Stripe secret key is read from the official Stripe plugin's settings at call time, for its current test/live
  mode. This plugin never stores, logs or shows it.
- It stays out of the official Stripe plugin's way: the gateway ID is `smpw_mobilepay` (the Stripe plugin treats
  `stripe*` methods as its own), the PaymentIntents carry only `smpw*` metadata (never `order_id` or `signature`, which
  the Stripe plugin matches on), and the Stripe plugin is kept from settling payments on MobilePay orders.

## Requirements

- **The official [WooCommerce Stripe Payment Gateway](https://wordpress.org/plugins/woocommerce-gateway-stripe/)
  plugin by WooCommerce** — required, installed, active and connected to your Stripe account. This plugin has no API
  keys or Stripe connection of its own: at every call to Stripe it reads the secret key for the current mode from that
  plugin's settings, and MobilePay follows that plugin's test/live mode. WordPress won't activate this plugin without it
  (`Requires Plugins: woocommerce, woocommerce-gateway-stripe`). While MobilePay is switched on but the Stripe plugin is
  missing or not connected, WooCommerce's admin screens show a notice, and MobilePay isn't offered at checkout.
- A Stripe account where the **MobilePay** payment method is active (the `mobilepay_payments` capability).
- WordPress 6.5+, PHP 8.1+ (with the sodium extension), WooCommerce 8.0+ (tested up to 11.1). HPOS and the checkout
  block are supported, as is the classic checkout.
- The shop's currency is **DKK**. MobilePay is offered to customers with a billing address in **Denmark or Finland**.
- MySQL or MariaDB (named locks) and Action Scheduler (bundled with WooCommerce).

## Setup

1. **Install and connect the official Stripe plugin first.** Install
   [WooCommerce Stripe Payment Gateway](https://wordpress.org/plugins/woocommerce-gateway-stripe/) by WooCommerce,
   activate it, and connect it to your Stripe account in its settings. Make sure the Stripe account has the **MobilePay**
   capability — MobilePay must be active among the account's payment methods in the Stripe dashboard.
2. **Install and activate this plugin.** Clone this repository into `wp-content/plugins/stripe-mobilepay-woocommerce`,
   or upload it as a zip under Plugins → Add New Plugin → Upload.
3. **Create the webhook.** WooCommerce → Settings → Payments → MobilePay → **Create webhook in Stripe**, or with
   WP-CLI: `wp smpw webhook create --mode=live` (and `--mode=test` for Stripe's test mode). This adds an endpoint for
   `https://your-shop/?wc-api=smpw_webhook` to your Stripe account and keeps its signing secret. Without a webhook,
   orders still go through by the customer's return and the scheduled checks — only later, when the customer doesn't
   come back. If the site is behind HTTP authentication (a staging site, say), let Stripe's requests to that URL through.
4. **Enable MobilePay** on the same settings page, and adjust its title and description if you like.
5. **Test first** with the Stripe plugin in test mode: Stripe's test page lets you approve or fail each payment.

On activation, MobilePay is placed first among the payment methods, so the checkout block pre-selects it. Change the
order under WooCommerce → Settings → Payments if you prefer.

### The MobilePay logo

The MobilePay logo is a trademark of Vipps MobilePay and is not included. To show it at checkout, download the official
logo from Vipps MobilePay's brand resources and save it as `assets/img/mobilepay.svg` in the plugin's folder. Without
it, the checkout shows the payment method's title as text. Keep a copy: replacing the plugin's folder on an update
removes the file.

## WP-CLI

```sh
wp smpw status <order>        # the order's MobilePay state, and every attempt as Stripe sees it
wp smpw sync <order>          # sync the payment and its refunds with Stripe now
wp smpw reconcile             # run the hourly sweep now
wp smpw webhook status        # this site's webhook endpoint, per mode: create | status | delete [--mode=test|live]
```

## Hooks and filters

**`smpw_payment_captured`** (action) — a MobilePay payment has been captured and the capture is recorded on the order.

```php
do_action( 'smpw_payment_captured', WC_Order $order, int $amount_minor );
```

It fires once per captured PaymentIntent, however the capture came about: the order marked Completed, a capture
retry, the hourly sweep, or a capture found made in the Stripe dashboard. `$amount_minor` is the amount captured in
minor units (1/100 kr). It does not fire when a hold is released because nothing was due. The order may have any
status, and the plugin holds the order's lock while the action runs, so keep the work short. For example, to send an
invoice only once the money is taken:

```php
add_action(
	'smpw_payment_captured',
	function ( WC_Order $order, int $amount_minor ) {
		if ( $order->has_status( 'completed' ) ) {
			// Send the invoice.
		}
	},
	10,
	2
);
```

**`smpw_notification_email`** (filter) — who gets the plugin's e-mails to the shop (a failed capture, a hold that
couldn't be released, a dispute, …). Default: the site's admin e-mail address. Return `''` to send none.

```php
add_filter( 'smpw_notification_email', fn( string $to, WC_Order $order ) => 'payments@example.com', 10, 2 );
```

**`smpw_client`** (filter) — replace the Stripe client with another `SMPW_Client`; the WP-CLI integration test uses it
for a fake Stripe.

**`smpw_offered()`** (function) — whether MobilePay is switched on and can take payments right now, e.g. for a theme
that shows a MobilePay logo in its footer.

## Data

- Order meta `_smpw_*`: the attempts, the paying PaymentIntent, the hold, the capture and the refunds' bookkeeping. The
  order's transaction ID is the PaymentIntent ID. A WooCommerce refund paid back through the plugin carries its Stripe
  refund ID in `_smpw_refund_id`.
- Options: `woocommerce_smpw_mobilepay_settings`, `smpw_webhook_test`, `smpw_webhook_live`.
- Logs: WooCommerce → Status → Logs, source `stripe-mobilepay-woocommerce` — Stripe calls, webhooks and syncs, never
  keys or secrets.
- Deleting the plugin removes its options, the webhook secrets included; order data stays. Remove the webhook endpoints
  at Stripe first with `wp smpw webhook delete --mode=live` (and `--mode=test`).

## Translations

The source strings are English; a Danish translation is included in `languages/`, next to the template
`stripe-mobilepay-woocommerce.pot` for other languages.

## Testing

```sh
php tests/run.php         # unit tests: the pure logic
php tests/scenarios.php   # the state machine end to end, against an in-memory WooCommerce and a fake Stripe
```

Both run on plain PHP 8.1+ with no dependencies; CI runs them on PHP 8.1–8.4. Two WP-CLI scripts are meant for a
staging site with WooCommerce:

- `wp eval-file tests/wp-cli/integration.php` — real WooCommerce orders against a fake Stripe (no money, no network);
  it deletes its orders again.
- `wp eval-file tests/wp-cli/e2e-start.php [product id]` — run it on a staging site in Stripe test mode: it creates an
  order, starts MobilePay and prints Stripe's test page, where you approve or fail the payment.

## Limitations

- DKK only; billing country Denmark or Finland.
- No express checkout buttons, saved payment methods or subscriptions.
- A hold lasts 7 days: mark the order Completed before it expires. There is one capture per payment; capturing less
  than the hold releases the rest.
- Refunds after the capture go through WooCommerce's refund button or the Stripe dashboard; setting the status to
  Refunded doesn't move captured money.
- Disputes are reported only; you respond in the Stripe dashboard.
- Order notes and e-mails write amounts the Danish way (e.g. `1.234,50 kr`).
- MobilePay isn't offered for orders under 2.50 kr (Stripe's minimum for DKK).
- The official Stripe plugin must stay installed and connected.

## Trademarks

MobilePay is a trademark of Vipps MobilePay AS. Stripe is a trademark of Stripe, Inc. WooCommerce is a trademark of
Automattic Inc. The names are used only to say what this plugin works with. This is an unofficial project — not
affiliated with or endorsed by Stripe, Vipps MobilePay or WooCommerce/Automattic.

## License

[0BSD](LICENSE): use, copy, modify and distribute it for any purpose, with or without fee or attribution.

The software is provided **"as is"**, without warranty of any kind, express or implied, including the implied
warranties of merchantability and fitness for a particular purpose. In no event shall the authors or contributors be
liable for any claim, damages or other liability arising from, out of or in connection with the software or its use —
see [LICENSE](LICENSE).
