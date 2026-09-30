# Contributing

Pull requests are welcome — bug fixes, translations, documentation and new features alike. Thank you for helping!

## Before you start

- For a bigger change (new behaviour, a new setting, a different flow), please open an issue first, so we can agree
  on the approach before you put time into it.
- Small fixes and translations can go straight to a pull request.

## Making the change

- Code, comments and documentation are in English. User-facing text goes through the translation functions with the
  text domain `stripe-mobilepay-woocommerce`; when you add or change a string, update
  `languages/stripe-mobilepay-woocommerce.pot` (and the Danish `.po`/`.mo` if you can).
- Follow the WordPress coding style used in the files: tabs, `array()`, Yoda conditions, escaped output.
- Keep the plugin's safety rules intact: everything that moves money runs under the order lock, after a fresh fetch
  from Stripe, with its own idempotency key; PaymentIntents carry only `smpw*` metadata (never `order_id` or
  `signature`); the Stripe secret key is never stored, logged or shown.
- Any change to how money moves (capture, release, refunds, duplicates, disputes) needs a scenario in
  `tests/scenarios.php` that fails without your change.

## Testing

Both suites run on plain PHP 8.1+ with no dependencies:

```sh
php tests/run.php         # unit tests
php tests/scenarios.php   # the state machine end to end, with an in-memory WooCommerce and a fake Stripe
```

CI runs them on PHP 8.1–8.4 for every pull request. If you can, also try your change on a staging site in Stripe's
test mode (see `tests/wp-cli/` and the README's Testing section).

## License

By contributing, you agree that your contribution is licensed under the project's [0BSD license](LICENSE).
