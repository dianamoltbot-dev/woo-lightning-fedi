# ⚡ WooCommerce Lightning Gateway (Fedi/LNURL)

Accept Bitcoin Lightning payments in your WooCommerce store via LNURL-pay or Lightning Address. Built for [Fedi](https://fedi.xyz) federations but works with any LNURL-pay compatible wallet.

## Features

- 🔌 **WooCommerce Payment Gateway** — shows "Pay with Lightning ⚡" at checkout
- 💱 **ARS → Sats conversion** — real-time exchange rate via Yadio API
- 📱 **QR Code** — scannable from any Lightning wallet
- ✅ **Auto-verification** — polls for payment confirmation
- 🏷️ **Lightning Address support** — use `user@domain.com` format
- 🔗 **LNURL-pay support** — raw LNURL strings also work
- 🌎 **Multi-currency** — works with ARS, USD, EUR, BRL, or any currency

## Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+
- A Lightning Address or LNURL-pay endpoint (Fedi, LNbits, Alby, etc.)

## Installation

1. Download or clone this repository
2. Upload the `woo-lightning-fedi` folder to `/wp-content/plugins/`
3. Activate the plugin in WordPress → Plugins
4. Go to WooCommerce → Settings → Payments → Lightning Network
5. Enter your Lightning Address (e.g., `cami@lacrypta.ar`)
6. Set your store currency (default: ARS)
7. Done! ⚡

## How It Works

1. Customer adds items to cart and proceeds to checkout
2. Selects "Pay with Lightning ⚡" as payment method
3. Plugin converts the order total from ARS (or your currency) to satoshis using real-time exchange rate
4. Generates a Lightning invoice via your LNURL-pay endpoint
5. Displays a QR code and "Open Wallet" button
6. Polls for payment confirmation
7. Once paid, order is marked as "Processing" automatically

## Configuration

| Setting | Description | Example |
|---------|-------------|---------|
| Lightning Address | Your LN address or LNURL | `cami@lacrypta.ar` |
| Store Currency | Your store's currency code | `ARS` |
| Order Prefix | Prefix for invoice descriptions | `Spark101` |
| Check Interval | Seconds between payment checks | `3` |
| Invoice Expiry | Minutes before invoice expires | `15` |

## Exchange Rate

Uses [Yadio API](https://yadio.io) for real-time BTC/fiat conversion. Falls back to cached rate if API is temporarily unavailable.

## License

MIT — Use it, fork it, improve it. ⚡

## Credits

Built by [Diana](https://github.com/dianamoltbot-dev) for [Spark101 Tech](https://www.spark101.tech).
Powered by the Bitcoin Lightning Network and Fedi/Fedimint.
