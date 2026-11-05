=== sallanet Pay for WooCommerce ===
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 10.3
Stable tag: 0.2.1
License: GPLv2 or later

Accept Pi and ST in WooCommerce through the sallanet Pay hosted checkout.

== How it works ==

1. On "Place order" the plugin creates a sallanet Pay checkout (extapi-create-checkout) and sends the buyer to the secure payment page. The link is valid for 5 minutes; if it expires the buyer can pay again from the order page.
2. After payment the buyer returns to the order received page. The plugin checks the transaction (extapi-transaction-status) and marks the order Processing.
3. sallanet Pay also sends a signed webhook (X-Salla-Signature, HMAC-SHA256 of the raw body) to /wp-json/sallanet-pay/v1/webhook. Events: payment.success, payment.confirmed, payment.refunded, webhook.test. Deliveries are idempotent.
4. The WooCommerce Refund button calls extapi-refund. sallanet Pay allows one refund per order (full or partial), at the coin rate the buyer paid.
5. Payments are held in escrow until the buyer confirms or the hold period ends. Stores with instant settlement can release escrow when the order is marked Completed.

Works with the classic checkout and the block checkout, and with HPOS.

== Setup ==

1. Upload the zip in Plugins > Add New > Upload, then activate.
2. WooCommerce > Settings > Payments > sallanet Pay.
3. Paste your API key (and App ID if your key has several apps).
4. Copy the webhook URL shown under "Webhook secret" into your sallanet Pay dashboard, and paste the dashboard webhook secret here.
5. Choose the coin (Pi or ST) and price conversion:
   * Store currency is the same coin: set the store currency to Pi or ST (added by this plugin).
   * USD store with live Pi price.
   * Fixed rate for any other currency (for example 1 Pi = 50 EGP).

The webhook needs a public https address. On a local test site only the return page check runs.
