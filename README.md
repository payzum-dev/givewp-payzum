# Payzum for GiveWP — Accept Crypto & Stablecoin Donations (USDC, USDT)

Accept **cryptocurrency and stablecoin donations** (USDC, USDT and more, multi-chain) in
[GiveWP](https://givewp.com) through [Payzum](https://payzum.com) —
**non-custodial**: funds settle directly to your organization's own wallet,
Payzum never takes custody. No chargebacks, no card networks, no PCI surface.

- **Plugin:** `payzum-give` · **Version:** 1.3.0 · **License:** GPL-2.0-or-later
- **Requires:** WordPress ≥ 5.6, GiveWP 3.x+ (verified against 4.16), PHP ≥ 8.1
- Built on GiveWP's **next-gen payment gateway API**, with v3 (visual builder) form support

## How it works

1. The donor picks **Payzum** on the donation form and pays on a hosted
   checkout page (QR code + deposit address, live status) — or in an embedded
   widget, see render modes below — where they choose the coin and chain. No
   wallet or card data touches your server.
2. Crypto confirmation is **asynchronous**, so the donation is completed from
   Payzum's signed server-to-server IPN webhook, never from the donor's browser
   return — a closed tab never loses a settled donation.
3. Every webhook is verified with **HMAC-SHA-512 over the raw request bytes**
   (constant-time compare, replay window) before a single field of it is read.
   Redelivered webhooks are deduplicated and the settled amount and currency
   are re-checked against the donation before it completes, so a donation is
   never counted twice — or for the wrong amount.

## Features

- **Stablecoin-first**: USDC and USDT across multiple chains (Polygon, Ethereum,
  Arbitrum, Base, Optimism, Tron, Solana and more), plus major cryptocurrencies.
- **Non-custodial** — donations settle to the charity's own wallet.
- **Three render modes** — full redirect to the hosted checkout, a modal
  overlay, or an inline widget mounted in place of the donation form.
- **Signed IPN webhooks** (HMAC-SHA-512) complete donations server-side, with
  amount/currency verification and per-donation locking against double delivery
  (no duplicate receipts, no double-counted campaign totals).
- **Fiat or crypto pricing** — price in your site currency and let Payzum
  convert, or denominate directly in a stablecoin.
- **Modern v3 donation forms** supported via `window.givewp.gateways.register()`.
- **Zero chargebacks** — crypto donations are final.

## Installation

**From the release zip (recommended).** Download
[`payzum-give-1.3.2.zip`](https://github.com/payzum-dev/givewp-payzum/releases/latest), then in WordPress go to
**Plugins → Add New → Upload Plugin**, pick the zip and activate it (requires GiveWP). The archive unpacks to
`payzum-give/`, the folder name WordPress expects.

**From a clone.** This repository *is* the plugin, so its contents must land in a folder named
`payzum-give`:

```bash
git clone https://github.com/payzum-dev/givewp-payzum.git payzum-give
mv payzum-give /path/to/wp-content/plugins/
```

Do not drop the repository in as `givewp-payzum` — WordPress keys the plugin off the folder name.

The official [`payzum/payzum-php`](https://packagist.org/packages/payzum/payzum-php) SDK is
vendored, so no composer step is needed.

## Configuration

Go to **Donations → Settings → Payment Gateways → Payzum**:

| Setting | Meaning |
|---|---|
| API key | From your [Payzum merchant dashboard](https://merchant.payzum.com) |
| Webhook secret | Verifies incoming payment webhooks (IPN) |
| Currency mode | `fiat` (default — Payzum converts from your site currency) or `crypto` (denominate directly in a stablecoin) |
| Render mode | `redirect` (default), `modal`, or `inline` widget |
| Environment | Production or staging (staging needs its own API key) |
| Debug log | Writes to the WordPress debug log, prefixed `[payzum]` |

The settings screen shows your site's **IPN URL**
(`https://<your-site>/?give-listener=payzum`) read-only — paste it into the
Payzum dashboard webhook settings. The signature header is fixed; nothing to
configure.

Remember to enable Payzum for **v3 forms** too — GiveWP keeps a separate
enabled-gateway list for the visual form builder.

The donor chooses the coin on the Payzum checkout, limited to the allowlist you
configure in the Payzum dashboard (**Merchants → Settings → Accepted tokens**)
and enforced server-side.

## Donation status mapping

| Payzum payment status | GiveWP donation status |
|---|---|
| `finished` (amount and currency match the donation) | Complete |
| `finished` (settled amount or currency does not match) | Processing — flagged for review, not completed |
| `partially_paid` | Processing (underpaid — the donor sent less than the invoice) |
| `expired` | Abandoned |
| `failed` | Failed |
| anything else | Donation note only, status unchanged |

## FAQ

**Is Payzum custodial?**
No. Donations settle directly to your organization's own wallet — Payzum never
holds the funds.

**Can GiveWP accept USDT or USDC donations?**
Yes — with this plugin, donors give in USDC, USDT or other assets on the chains
you allow, and the donation completes automatically from a signed webhook.

**Do donors need an account or a specific wallet?**
No. They scan a QR or copy a deposit address from the Payzum checkout and pay
from any wallet.

**Does it work with the modern GiveWP visual form builder (v3 forms)?**
Yes. The gateway implements GiveWP's next-gen payment gateway API and registers
itself with v3 forms via `enqueueScript()`.

**Can donors stay on my site instead of being redirected?**
Yes. The `modal` and `inline` render modes mount the Payzum payment widget in
place; completion still happens exclusively from the signed IPN. If your site
sets a Content-Security-Policy, allow `merchant.payzum.com` in `script-src`,
`frame-src` and `connect-src`.

**What data is shared with Payzum?**
Only the donation amount, currency, a donation reference and your site's
callback URLs — no donor personal data. Endpoints: `https://merchant.payzum.com`
(production), `https://staging.payzum.com` (staging).

## Related Payzum integrations

Payzum ships official plugins for most major e-commerce, donation and billing
platforms — WooCommerce, Easy Digital Downloads, Paid Memberships Pro, Magento 2,
PrestaShop, Shopware 6, OpenCart, Zen Cart, nopCommerce, Ecwid, BigCommerce,
Shopify, Wix, Medusa, Vendure, Saleor, Sylius, WHMCS, Blesta, HostBill,
ClientExec, pretix, Frappe/ERPNext, Akaunting and django-payments — plus
official SDKs for PHP, Node.js/TypeScript, Python and Rust. Browse them all at
[github.com/payzum-dev](https://github.com/payzum-dev).

## About Payzum

[Payzum](https://payzum.com) is a non-custodial crypto payment gateway for
merchants and nonprofits: accept USDC, USDT and other digital assets with
settlement straight to your own wallet, optional auto-conversion to
stablecoins, and a single REST API. API docs:
[merchant.payzum.com/api/docs](https://merchant.payzum.com/api/docs).

## License

[GPL-2.0-or-later](LICENSE). Contributed and maintained by Payzum.
