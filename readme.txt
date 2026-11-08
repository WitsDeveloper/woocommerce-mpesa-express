=== WooCommerce Mpesa Express ===
Contributors: sammywaweru
Tags: woocommerce, mpesa, safaricom, payment gateway, kenya
Requires at least: 6.6
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 9.4
Stable tag: 2.1.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept Safaricom M-Pesa payments in WooCommerce via STK Push (M-Pesa Express) and manual PayBill (C2B), with automatic order confirmation via M-Pesa IPN callbacks.

== Description ==

WooCommerce Mpesa Express adds two independent M-Pesa payment methods to your WooCommerce checkout, backed by the Safaricom Daraja API:

**1. M-Pesa Express (STK Push)**

The customer enters their phone number at checkout, WooCommerce triggers an STK ("Sim Tool Kit") push, and the customer receives a prompt directly on their phone to enter their M-Pesa PIN and confirm. The order is placed **On hold** immediately and is moved to **Processing** (or **Completed**, if you choose) automatically once Safaricom sends the payment result to your store.

**2. M-Pesa PayBill (Manual / C2B)**

The customer is shown your PayBill number and a unique account reference (their order number) and pays manually from their own M-Pesa menu — useful as a fallback for customers without STK Push support, or as your only method if you don't yet have STK Push access. The order is placed **On hold**, and is confirmed and moved to **Processing** (or **Completed**) automatically when Safaricom sends the C2B Confirmation IPN for that account number.

Both gateways:

* Listen for Safaricom's IPN (Instant Payment Notification) callbacks and update the WooCommerce order status automatically — no manual "mark as paid" step required.
* Are protected by a per-site secret token embedded in the callback URL, so unauthenticated requests to your callback endpoints are rejected.
* Log requests and errors to the WooCommerce logs (**WooCommerce > Status > Logs**, source `mpesa-express`) for troubleshooting.
* Convert order totals to KES automatically if your store currency is not KES (configurable exchange rate).
* Are compatible with WooCommerce High-Performance Order Storage (HPOS).

Each gateway is configured, enabled, and used independently — enable one or both depending on what you need.

== Requirements ==

* A Safaricom Daraja account and app: https://developer.safaricom.co.ke/
* A PayBill (or Buy Goods) shortcode:
  * For **M-Pesa Express**, the shortcode must be provisioned for **Lipa na M-Pesa Online (STK Push)**, and you need the app's **Consumer Key**, **Consumer Secret**, and **Passkey**.
  * For **M-Pesa PayBill (C2B)**, the shortcode must be provisioned for **C2B**, and you need the app's **Consumer Key** and **Consumer Secret** only if you want to use the in-plugin "Register URLs" button (registration can also be done manually in the Daraja portal).
* Your site must be reachable over HTTPS at a public URL — Safaricom cannot call back to `localhost` or a site behind authentication.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/woocommerce-mpesa-express`, or install the zip via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **WooCommerce > Settings > Payments**. You will see two new methods: **M-Pesa Express (STK Push)** and **M-Pesa PayBill (Manual / C2B)**.
4. Click **Manage** on either method to configure it (see below).

== Setting up M-Pesa Express (STK Push) ==

1. In the Daraja portal, create (or open) an app with access to the **Lipa Na M-Pesa Online** (STK Push) API for your shortcode, and note its Consumer Key, Consumer Secret, and the Passkey issued for that shortcode.
2. Go to **WooCommerce > Settings > Payments > M-Pesa Express (STK Push) > Manage**.
3. Fill in:
   * **Environment** — `Sandbox` while testing, `Live` for real payments.
   * **PayBill Shortcode** — your STK Push shortcode.
   * **Passkey**, **Consumer Key**, **Consumer Secret** — from the Daraja app.
   * **Exchange Rate to KES** — only relevant if your store currency isn't KES.
4. Tick **Enable M-Pesa Express** and save.

No manual callback registration is needed for STK Push: the plugin sends its own callback URL (shown read-only under **IPN Callback URL** on the settings screen) with every push request, and Safaricom calls it directly for that transaction.

**Checkout flow:** the customer enters an M-Pesa phone number in the dedicated field added to checkout, places the order, and the order is set to **On hold**. WooCommerce sends the STK push; the customer confirms on their phone; Safaricom calls the plugin's IPN callback; the order moves to **Processing**/**Completed** and a receipt number is recorded (**visible in the order's Custom Fields/notes**). If the push is cancelled, declined, or times out, the order is moved to **Failed** automatically (this can be turned off in settings, in which case it stays On hold for manual review).

== Setting up M-Pesa PayBill (Manual / C2B) ==

1. In the Daraja portal, provision your shortcode for **C2B** (Confirmation/Validation URL registration).
2. Go to **WooCommerce > Settings > Payments > M-Pesa PayBill (Manual / C2B) > Manage**.
3. Fill in:
   * **PayBill Shortcode** — your C2B shortcode.
   * **Account Reference Prefix** *(optional)* — e.g. `INV` turns order #1024 into account number `INV1024`. Leave blank to use the plain order number.
   * **Use Validation URL** — only enable if Safaricom has enabled "Validation" for your shortcode; otherwise leave off (Confirmation alone is sufficient to credit orders).
   * **Consumer Key** / **Consumer Secret** — only needed to use the in-plugin URL registration button below.
4. Save your settings first, then scroll to **Callback URLs** and click **Register URLs with Safaricom** to register the Confirmation and Validation URLs shown there via the Daraja `registerurl` API. If it fails (or you prefer to do it yourself), copy the two URLs shown and add them manually under your app's C2B settings in the Daraja portal.
5. Tick **Enable M-Pesa PayBill (manual / C2B)** and save.

**Checkout flow:** the customer chooses "M-Pesa PayBill" at checkout, places the order, and is shown payment instructions (PayBill number, account number = order reference, amount) on the order-received page, on the order in **My Account > Orders**, and in the "On hold" order email. The customer pays manually from their M-Pesa menu. When Safaricom sends the C2B Confirmation IPN for that account number, the plugin matches it to the order, records the M-Pesa receipt number, and moves the order to **Processing**/**Completed**. If the amount paid is less than the order total, the order is left **On hold** with a note for manual review rather than being marked paid.

== IPN / Callback security ==

Every callback URL generated by this plugin includes a random per-site `token` query parameter (generated on activation and stored in the `wc_mpesa_ipn_secret` option). Incoming requests to any of the three callback endpoints are rejected with `403 Forbidden` unless the token matches, so the callback URLs are safe to register with Safaricom even though the endpoints themselves are otherwise unauthenticated (Safaricom does not sign or authenticate IPN requests).

The endpoints registered are:

* `https://yourstore.example/wc-api/wc_mpesa_express?token=...` — STK Push result callback (sent per-transaction, not registered in the Daraja portal).
* `https://yourstore.example/wc-api/wc_mpesa_c2b_confirmation?token=...` — C2B Confirmation URL (register in the Daraja portal or via the in-plugin button).
* `https://yourstore.example/wc-api/wc_mpesa_c2b_validation?token=...` — C2B Validation URL (register in the Daraja portal or via the in-plugin button; only called if Validation is enabled for your shortcode).

If you ever need to rotate the secret, delete the `wc_mpesa_ipn_secret` option (e.g. via WP-CLI: `wp option delete wc_mpesa_ipn_secret`) — a new one is generated automatically on the next request, and you'll need to re-register the C2B URLs afterwards.

== Frequently Asked Questions ==

= Do I need both gateways? =

No. Enable only M-Pesa Express, only M-Pesa PayBill, or both — they work independently and customers pick one at checkout.

= The STK push is sent but the order never moves out of "On hold" =

Check **WooCommerce > Status > Logs** for a log source `mpesa-express`. Common causes: your site isn't publicly reachable over HTTPS, the callback URL is blocked by a firewall/security plugin, or the Daraja app doesn't have STK Push access for that shortcode/environment.

= C2B payments aren't confirming orders automatically =

Make sure the Confirmation URL has actually been registered with Safaricom (via the **Register URLs with Safaricom** button, or manually in the Daraja portal) — Safaricom will not call a URL that was never registered. Also confirm the customer entered the exact account number (order number, with your configured prefix if any) as the M-Pesa account number.

= Can I use a different currency than KES? =

Yes. Set **Exchange Rate to KES** in the gateway settings; the order total is multiplied by this rate before being sent to M-Pesa. Orders are still recorded in your store's own currency.

= Is sandbox testing supported? =

Yes, set **Environment** to `Sandbox` and use your Daraja sandbox app credentials and test shortcode/MSISDN provided by Safaricom.

== Changelog ==

= 2.1.1 =
* No "Order on hold" email to the customer while an STK payment is pending.

= 2.1.0 =
* STK Push: account reference is a configurable prefix + order number (default RES1024); amount paid is checked against the order total; Safaricom 01xx numbers accepted.
* Order-received "check your phone" screen: polls the order, reloads when paid, lets the customer resend the prompt (3 times, 30 s apart) and shows PayBill details as a fallback.
* STK Query (stkpushquery) settles an order when Safaricom's callback is late or lost (rate-limited per order).
* Callbacks are refused unless they come from Safaricom's callback ranges (196.201.212-214.x), in addition to the secret token; refused callbacks are logged whole.
* Credentials can come from wp-config.php: WC_MPESA_CONSUMER_KEY, WC_MPESA_CONSUMER_SECRET, WC_MPESA_PASSKEY, WC_MPESA_SHORTCODE.
* "Register URLs with Safaricom" is off unless WC_MPESA_ALLOW_REGISTER_URLS is true: on a PayBill shared with another system it would replace that system's C2B URLs.
* The M-Pesa phone field now sits in the payment box (pre-filled from the billing phone) instead of the billing form.
* Daraja access tokens are cached until they expire.

= 2.0.0 =
* Added M-Pesa PayBill (Manual / C2B) gateway with payment instructions and Confirmation/Validation IPN handling.
* Added a per-site secret token to all IPN callback URLs to reject unauthenticated requests.
* Added automatic "Failed" status on declined/cancelled/timed-out STK pushes (configurable).
* Added WooCommerce logging for callbacks and API errors.
* Added HPOS (High-Performance Order Storage) compatibility declaration.
* Added in-plugin button to register C2B Confirmation/Validation URLs with Safaricom.

= 1.2.0 =
* M-Pesa Express (STK Push) gateway.
