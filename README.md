# CigalPay for PrestaShop

Official PrestaShop module for [CigalPay](https://cigalpay.com): accept crypto payments (Bitcoin, USDT, Ethereum, Solana, Litecoin, Dogecoin and more) in your shop. Your prices stay in USD or EUR, and the customer picks the coin on the CigalPay checkout.

- Works with PrestaShop 8 and 9, and with the default checkout.
- No webhook to set up: the module sends its notification address with every payment.
- Each order is checked against the checkout created for it, at the order's price.

## Requirements

- PrestaShop 8.0+
- PHP 7.4+ with `curl`
- Shop currency: USD or EUR
- A CigalPay account ([sign up](https://cigalpay.com))

## Install

1. Download `cigalpay.zip` from the [latest release](https://github.com/cigalpay/cigalpay-prestashop/releases/latest).
2. In the back office, go to **Modules → Module Manager → Upload a module** and drop the zip.

## Set up

1. In your CigalPay dashboard, open **Settings → API credentials** and copy your **Account ID** and your **notification signing secret**.
2. In PrestaShop, open **Modules → Module Manager**, find CigalPay and click **Configure**.
3. Paste both values and save.

CigalPay then appears at checkout for carts in USD or EUR.

## How it works

1. The customer chooses crypto at checkout. The order is created in the **Awaiting crypto payment** status and the customer is sent to the CigalPay checkout.
2. They pick a coin and pay.
3. CigalPay notifies your shop each time the payment status changes, and the module updates the order:

| CigalPay status | Order |
| --- | --- |
| `WAITING`: coin chosen | Stays **Awaiting crypto payment**, with a private message |
| `CONFIRMING`: payment seen on the network | Stays **Awaiting crypto payment**, with a private message |
| `CONFIRMED` / `FINISHED` | **Payment accepted**. The CigalPay payment id is the transaction id, and the customer gets the usual email |
| `PARTIALLY_PAID` | **Payment error**, with a message showing the amount received and the amount expected |
| `EXPIRED` / `FAILED` | Unchanged, with a message |

The messages are private: you see them in the order's **Messages** block, the customer doesn't.

If the customer leaves without paying, their order confirmation page has a button to go back to the payment page. A payment made in another coin and converted automatically is marked paid the same way. If a customer pays twice, the order is paid once and the second payment is noted so you can refund it.

When PrestaShop splits a cart into several orders, they are paid together with one CigalPay payment.

## Security

Every notification is checked before it touches an order:

1. **Signature.** The `x-cigalpay-sig` header must match the body, signed with your notification signing secret. Otherwise the request is rejected with `401`.
2. **Checkout.** The payment must come from a checkout this shop created for that order. Anyone can create a CigalPay checkout with your Account ID and an order number, so the order number alone is never trusted.
3. **Amount.** The checkout's price must still match the order total. If the order was changed after checkout, it is set to **Payment error** with a message instead of being marked paid.

## Logs

Errors are logged in **Advanced Parameters → Logs** (search "CigalPay"). Turn on **Debug log** in the module settings to log every notification.

## FAQ

**Can I refund from PrestaShop?**
Not yet. Send crypto refunds from your CigalPay dashboard or your wallet.

**My shop isn't in USD or EUR.**
CigalPay is hidden at checkout for other currencies, and the settings page shows a warning.

**What does the module send to CigalPay?**
The order total and currency, the order reference, the shop name, the return and notification addresses, and the customer's language. No customer names, emails or addresses.

## For developers

To integrate CigalPay outside PrestaShop, use the [PHP SDK](https://github.com/cigalpay/cigalpay-php-sdk) or the [JavaScript SDK](https://github.com/cigalpay/cigalpay-js-sdk). There is also a [WooCommerce plugin](https://github.com/cigalpay/cigalpay-woocommerce).

## Support

- Docs: [cigalpay.com/developers](https://cigalpay.com/developers)
- Email: support@cigalpay.com
- Bugs and requests: [GitHub issues](https://github.com/cigalpay/cigalpay-prestashop/issues)

## License

MIT
