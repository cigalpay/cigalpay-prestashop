<?php

namespace Cigalpay;

use Cigalpay\Exceptions\CigalpayException;

/**
 * CigalPay PHP SDK - accept crypto payments in your app, SaaS or website.
 * Server-side only: it uses your account credentials, which must never reach a browser.
 *
 * Docs: https://cigalpay.com/developers
 */
class CigalPay
{
    const CHECKOUT_URL = 'https://coins-api.cigalpay.com';
    const API_URL = 'https://core-api.cigalpay.com';
    const MERCHANT_API_URL = 'https://merchant-api.cigalpay.com';

    /** Payment statuses sent in notifications. CONFIRMED and FINISHED mean paid. */
    const STATUS_WAITING = 'WAITING';
    const STATUS_CONFIRMING = 'CONFIRMING';
    const STATUS_CONFIRMED = 'CONFIRMED';
    const STATUS_SENDING = 'SENDING';
    const STATUS_FINISHED = 'FINISHED';
    const STATUS_PARTIALLY_PAID = 'PARTIALLY_PAID';
    const STATUS_EXPIRED = 'EXPIRED';
    const STATUS_FAILED = 'FAILED';

    /** @var string|null */
    protected $accountId;
    /** @var string|null */
    protected $apiKey;
    /** @var string */
    protected $checkoutUrl;
    /** @var string */
    protected $apiUrl;
    /** @var string */
    protected $merchantApiUrl;
    /** @var int */
    protected $timeout;

    /**
     * @param array $options accountId (hosted checkouts), apiKey (payment API),
     *                       checkoutUrl / apiUrl / merchantApiUrl (overrides), timeout (seconds, default 15)
     */
    public function __construct(array $options = [])
    {
        $this->accountId = isset($options['accountId']) ? $options['accountId'] : null;
        $this->apiKey = isset($options['apiKey']) ? $options['apiKey'] : null;
        $this->checkoutUrl = rtrim(isset($options['checkoutUrl']) ? $options['checkoutUrl'] : self::CHECKOUT_URL, '/');
        $this->apiUrl = rtrim(isset($options['apiUrl']) ? $options['apiUrl'] : self::API_URL, '/');
        $this->merchantApiUrl = rtrim(isset($options['merchantApiUrl']) ? $options['merchantApiUrl'] : self::MERCHANT_API_URL, '/');
        $this->timeout = isset($options['timeout']) ? (int) $options['timeout'] : 15;
    }

    // ── Hosted checkout (recommended) ─────────────────────────────────────

    /**
     * Create a payment priced in USD or EUR and get the checkout link to send
     * your customer to. They pick the coin on CigalPay's page; you get a
     * signed notification at notifyUrl when it's paid.
     *
     * @param array $p amount, currency ('USD'|'EUR'), orderId (sent back as extraId),
     *                 description, returnUrl, notifyUrl, lang ('en'|'fr'|'es'|'de', optional)
     * @return array ['id' => ..., 'chargeLink' => ..., ...]
     */
    public function createCheckout(array $p)
    {
        foreach (['amount', 'currency', 'orderId', 'description', 'returnUrl', 'notifyUrl'] as $k) {
            if (!isset($p[$k]) || $p[$k] === '') {
                throw new CigalpayException("CigalPay: createCheckout needs `$k`");
            }
        }
        return $this->request('POST', $this->checkoutUrl . '/api/pre-invoice', [
            'accountId' => $this->needs('accountId'),
            'amount' => $p['amount'],
            'currency' => $p['currency'],
            'description' => $p['description'],
            'extraId' => (string) $p['orderId'],
            'lang' => isset($p['lang']) ? $p['lang'] : 'en',
            'callback' => $p['returnUrl'],
            'ipn' => $p['notifyUrl'],
        ]);
    }

    // ── Payment API (you show the address yourself) ───────────────────────

    /**
     * Create a payment in a given coin (e.g. 'BTC', 'USDT_TRX') for an amount
     * in that coin. Prefer createCheckout() unless you build your own payment page.
     *
     * @param array $p amount, currency, orderId, notifyUrl, returnUrl
     */
    public function createPayment(array $p)
    {
        $body = [
            'amount' => isset($p['amount']) ? $p['amount'] : null,
            'currency' => isset($p['currency']) ? $p['currency'] : null,
        ];
        if (isset($p['orderId'])) $body['extraId'] = (string) $p['orderId'];
        if (isset($p['notifyUrl'])) $body['ipnCallbackUrl'] = $p['notifyUrl'];
        if (isset($p['returnUrl'])) $body['invoiceCallbackUrl'] = $p['returnUrl'];
        return $this->request('POST', $this->apiUrl . '/createPayment', $body, $this->apiHeaders());
    }

    /** A payment by its id. */
    public function getPayment($id)
    {
        return $this->request('GET', $this->apiUrl . '/getPaymentStatus?id=' . rawurlencode($id), null, $this->apiHeaders());
    }

    /** Every payment created for one of your order references. */
    public function getPaymentsByOrder($orderId)
    {
        return $this->request('GET', $this->apiUrl . '/getPaymentsByExtraId?extraId=' . rawurlencode($orderId), null, $this->apiHeaders());
    }

    /** Payments still waiting for funds. */
    public function getActivePayments()
    {
        return $this->request('GET', $this->apiUrl . '/activePayments', null, $this->apiHeaders());
    }

    /** Coins accepted directly. */
    public function getSupportedCoins()
    {
        return $this->request('GET', $this->apiUrl . '/supportedCoins');
    }

    // ── Refunds ───────────────────────────────────────────────────────────

    /**
     * Refund a paid payment. The amount and its network fee come out of your
     * CigalPay balance in the payment's coin; the customer gets a link to enter
     * the address to refund (by email when you pass customerEmail), then
     * CigalPay sends it. Needs `apiKey`.
     *
     * @param array $p paymentId, and amount (in the coin paid) or checkoutAmount
     *                 (in the checkout's USD / EUR, converted at the payment's rate);
     *                 customerEmail, reason (optional)
     * @return array ['refund' => [...], 'link' => customer link]
     */
    public function createRefund(array $p)
    {
        if (empty($p['paymentId'])) {
            throw new CigalpayException('CigalPay: createRefund needs `paymentId`');
        }
        $body = ['paymentId' => (string) $p['paymentId']];
        foreach (['amount', 'checkoutAmount'] as $k) {
            if (isset($p[$k])) $body[$k] = (float) $p[$k];
        }
        foreach (['customerEmail', 'reason'] as $k) {
            if (isset($p[$k]) && $p[$k] !== '') $body[$k] = (string) $p[$k];
        }
        return $this->request('POST', $this->merchantApiUrl . '/api/refunds', $body, $this->apiHeaders());
    }

    /** The refunds of a payment, newest first, with their status. */
    public function getRefunds($paymentId)
    {
        return $this->request('GET', $this->merchantApiUrl . '/api/refunds?paymentId=' . rawurlencode($paymentId), null, $this->apiHeaders());
    }

    // ── Webhooks ──────────────────────────────────────────────────────────

    /**
     * Check a payment notification's x-cigalpay-sig header. Pass the raw
     * request body (file_get_contents('php://input')), the header and your
     * notification signing secret (dashboard > Settings). Only trust a
     * notification when this returns true - and only ship on CONFIRMED or FINISHED.
     *
     * @param string $rawBody the body exactly as received (not re-encoded)
     */
    public static function verifyWebhook($rawBody, $signature, $secret)
    {
        if (!is_string($rawBody) || !is_string($signature) || $signature === '' || !is_string($secret) || $secret === '') {
            return false;
        }
        $signed = Webhook::canonical($rawBody);
        if ($signed === null) return false;
        return hash_equals(hash_hmac('sha512', $signed, $secret), $signature);
    }

    /** Decodes a verified notification body into an array. */
    public static function parseWebhook($rawBody)
    {
        $data = json_decode($rawBody, true);
        if (!is_array($data)) throw new CigalpayException('CigalPay: notification body is not JSON');
        return $data;
    }

    /** True when a notification's status means the order is paid. */
    public static function isPaid($status)
    {
        return $status === self::STATUS_CONFIRMED || $status === self::STATUS_FINISHED;
    }

    // ── HTTP ──────────────────────────────────────────────────────────────

    protected function needs($field)
    {
        if (empty($this->$field)) {
            throw new CigalpayException("CigalPay: pass `$field` to the constructor to use this method");
        }
        return $this->$field;
    }

    protected function apiHeaders()
    {
        return ['cigalpay-api-key: ' . $this->needs('apiKey')];
    }

    protected function request($method, $url, $body = null, array $headers = [])
    {
        $headers[] = 'Accept: application/json';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeout));
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $text = curl_exec($ch);
        if ($text === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new CigalpayException('CigalPay unreachable: ' . $error, 0);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = $text === '' ? null : json_decode($text, true);
        if ($text !== '' && json_last_error() !== JSON_ERROR_NONE) $data = $text;

        if ($status < 200 || $status >= 300) {
            $message = is_array($data) ? (isset($data['error']) ? $data['error'] : (isset($data['message']) ? $data['message'] : null)) : null;
            throw new CigalpayException('CigalPay: ' . (is_string($message) ? $message : "HTTP $status"), $status, $data);
        }
        return $data;
    }
}
