=== Payzum Crypto & Stablecoin Donations for Give ===
Contributors: payzum
Tags: givewp, donations, cryptocurrency, stablecoin, payment gateway
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept crypto and stablecoin donations (USDC/USDT, multi-chain) in GiveWP with Payzum. Non-custodial — funds settle to your own wallet.

== Description ==

Payzum lets your GiveWP campaigns accept cryptocurrency and stablecoin donations (USDC/USDT and
more, across multiple chains). Donations are **non-custodial**: funds settle directly to your own
wallet — Payzum never takes custody.

The donor is sent to a secure Payzum hosted checkout (QR + deposit address, live status), or the
checkout can be embedded in your page as an overlay or inline. The donation is completed
automatically from a signed IPN webhook, so a closed browser tab never loses a settled donation.

This is an add-on gateway: it requires **GiveWP** to be installed and active, and it does not
change any of GiveWP's default behaviour. It targets GiveWP's next-gen payment gateway API
(GiveWP 3.x and later; verified against 4.16) and registers itself on version 3 donation forms.

Features:

* Crypto & stablecoin donations (USDC/USDT, multi-chain).
* Non-custodial — settles to your wallet.
* Hosted checkout — no card data or crypto handling on your server.
* Redirect, modal or inline render modes.
* Signed IPN webhooks (HMAC-SHA-512) verify every donation server-side.
* The settled amount and currency are checked against the donation before it is completed; a
  mismatch is held for review instead of being marked complete.
* Fiat pricing (Payzum converts) or crypto pricing (`pricing_mode: "direct"`).
* No chargebacks.

The donor picks the coin on the Payzum checkout, limited to the tokens your merchant account
accepts. There is no coin selector in the plugin, by design.

== Installation ==

1. Install and activate **GiveWP** first. This plugin does nothing without it and will show an
   admin notice if it is missing.
2. Upload the `payzum-give` folder to `/wp-content/plugins/`, or install the zip via
   Plugins → Add New → Upload.
3. Activate the plugin.
4. Go to Donations → Settings → Payment Gateways → **Payzum**.
5. Paste your **API key** and **Webhook secret** from your Payzum dashboard, and pick the
   **Environment** (Production, or Staging / sandbox — staging has separate API keys). The gateway
   stays hidden until both credentials are set.
6. Copy the **Your IPN URL** value shown on that screen into your Payzum webhook settings. The IPN
   signature header is fixed (`x-nowpayments-sig`) — no configuration needed.
7. Enable Payzum in GiveWP's enabled-gateways list. GiveWP keeps a **separate list for version 3
   forms** — make sure it is enabled there, or it will not appear on modern forms.
8. Save, then make a small test donation to confirm the flow.

Your site needs **PHP 8.1 or newer**. The plugin bundles the official `payzum/payzum-php` SDK,
which uses enums and named arguments.

== External services ==

This plugin connects to the Payzum API to create donation invoices and receive payment
notifications. It is required for the gateway to work.

* What it sends: when a donor chooses Payzum, the plugin sends the donation amount, currency,
  donation reference and your site's callback/return URLs to Payzum to create the invoice.
  Payment confirmations arrive as signed webhooks from Payzum; the plugin verifies their
  signature before updating the donation. No donor personal data is sent by the plugin.
* When: only when the gateway is enabled and a donor pays.
* Endpoints: `https://merchant.payzum.com` (production) or `https://staging.payzum.com`
  (staging), as selected in the plugin settings.
* Service provider: Payzum — [terms](https://payzum.com/terms), [privacy](https://payzum.com/privacy).

If your site sends a Content-Security-Policy, allow `merchant.payzum.com` in `script-src`,
`frame-src` and `connect-src` for the modal and inline render modes.

== Frequently Asked Questions ==

= Is it custodial? =
No. Funds settle directly to your own wallet.

= Which coins are supported? =
USDC/USDT and other crypto across supported chains. Which coins your charity accepts is configured
in your Payzum dashboard (Merchants → Settings → Accepted tokens); the donor picks one of those on
the Payzum checkout.

= The gateway does not appear on my donation form. =
Two things to check. GiveWP keeps a separate enabled-gateway list for version 3 forms, so enabling
Payzum only in the classic list leaves it invisible on modern forms. The gateway also hides itself
until both the API key and the webhook secret are filled in.

= What happens if the donor underpays? =
The donation is not completed. A `partially_paid` invoice, and a `finished` invoice whose settled
amount or currency does not match the donation, both leave it in GiveWP's **processing** state with
an explanatory note for you to review. Overpayment is fine and completes normally.

= Does it work with recurring donations? =
No. This gateway takes a single payment; it does not create a subscription.

= Do I need to write code? =
No. Enter your API key and webhook secret and you are live.

== Changelog ==

= 1.3.2 =
* Plugin URI now points at the plugin's own repository, so it differs from the Author URI as
  the plugin directory requires. No functional change.

= 1.3.1 =
* Messages shown to the donor when a payment cannot be started are now escaped on output.
* Plugin name changed to "Payzum Crypto & Stablecoin Donations for Give": the WordPress.org
  directory does not allow "wp" in a plugin name. The slug and the settings are unchanged.

= 1.3.0 =
* The settled amount and currency are verified against the donation before it is completed. A
  `finished` invoice that does not match is held in **processing** with a note instead of being
  marked complete.
* IPN deliveries are deduplicated by event id, and the whole transition runs under a per-donation
  lock so two simultaneous deliveries cannot both complete a donation.
* The gateway hides itself when it has no credentials, instead of offering a checkout that cannot
  settle.

= 1.2.0 =
* Rebuilt on the official `payzum/payzum-php` SDK (bundled in `vendor/`): HTTP client, webhook
  signature verification, decimal-exact amounts and the payment-status vocabulary now live in one
  tested library instead of plugin code.
* New Environment setting (production / staging) for end-to-end testing against the sandbox.
* Every invoice create carries an idempotency key, so a transport retry cannot mint a second
  invoice.
* Requires PHP 8.1 (the SDK's floor).

= 1.1.0 =
* Registered on GiveWP version 3 forms. Earlier releases were listed in the settings screen but
  filtered out of every modern form, and could not take a donation at all.
* The donor picks the coin on the Payzum hosted checkout (`pay_currency: "all"`); dropped the
  in-plugin settlement-currency setting and the IPN signature-header setting.
* Added modal and inline render modes, and a crypto currency mode (`pricing_mode: "direct"`).
* Zero-amount donations complete without an invoice; repeated IPNs no longer duplicate notes.

= 1.0.0 =
* Initial release: hosted-checkout donation gateway with signed IPN verification.
