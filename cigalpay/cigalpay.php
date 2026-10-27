<?php
/**
 * CigalPay for PrestaShop: accept crypto payments (Bitcoin, USDT, Ethereum,
 * Solana and more). The customer pays on the CigalPay hosted checkout; the
 * order is marked paid from CigalPay's signed notification.
 *
 * @author    CigalPay <support@cigalpay.com>
 * @copyright CigalPay
 * @license   MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

// The CigalPay PHP SDK, unless another module or Composer already loaded it.
if (!class_exists('\Cigalpay\CigalPay')) {
    require_once __DIR__ . '/vendor/cigalpay-php-sdk/Exceptions/CigalpayException.php';
    require_once __DIR__ . '/vendor/cigalpay-php-sdk/Webhook.php';
    require_once __DIR__ . '/vendor/cigalpay-php-sdk/CigalPay.php';
}

use Cigalpay\CigalPay as CigalPaySdk;
use Cigalpay\Exceptions\CigalpayException;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Cigalpay extends PaymentModule
{
    const CURRENCIES = ['USD', 'EUR'];
    const LANGS = ['en', 'fr', 'es', 'de'];

    /** Configuration keys. The *_URL ones are not in the form: tests point them at a local API. */
    const CONF_ACCOUNT_ID = 'CIGALPAY_ACCOUNT_ID';
    const CONF_IPN_SECRET = 'CIGALPAY_IPN_SECRET';
    const CONF_API_KEY = 'CIGALPAY_API_KEY';
    const CONF_REFUND_ON_SLIP = 'CIGALPAY_REFUND_ON_SLIP';
    const CONF_DEBUG = 'CIGALPAY_DEBUG';
    const CONF_OS_WAITING = 'CIGALPAY_OS_WAITING';
    const CONF_CHECKOUT_URL = 'CIGALPAY_CHECKOUT_URL';
    const CONF_MERCHANT_API_URL = 'CIGALPAY_MERCHANT_API_URL';

    public function __construct()
    {
        $this->name = 'cigalpay';
        $this->tab = 'payments_gateways';
        $this->version = '1.1.0';
        $this->author = 'CigalPay';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->controllers = ['validation', 'notification'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('CigalPay - Crypto payments', [], 'Modules.Cigalpay.Admin');
        $this->description = $this->trans('Accept Bitcoin, USDT, Ethereum, Solana and more. Prices stay in USD or EUR.', [], 'Modules.Cigalpay.Admin');
        $this->confirmUninstall = $this->trans('Remove the CigalPay settings? Orders and payment records are kept.', [], 'Modules.Cigalpay.Admin');
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    // ── Install ───────────────────────────────────────────────────────────

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('actionOrderSlipAdd')
            && Configuration::updateValue(self::CONF_REFUND_ON_SLIP, 1)
            && $this->installDb()
            && $this->installOrderState();
    }

    public function uninstall()
    {
        // Tables and the order state stay: orders reference them.
        foreach ([self::CONF_ACCOUNT_ID, self::CONF_IPN_SECRET, self::CONF_API_KEY, self::CONF_REFUND_ON_SLIP, self::CONF_DEBUG, self::CONF_CHECKOUT_URL, self::CONF_MERCHANT_API_URL] as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    public function installDb()
    {
        $p = _DB_PREFIX_;
        $engine = _MYSQL_ENGINE_;

        // Checkouts created for an order (one per payment attempt), and the
        // payment statuses already handled (notifications can repeat).
        return Db::getInstance()->execute("CREATE TABLE IF NOT EXISTS `{$p}cigalpay_checkout` (
                `id_cigalpay_checkout` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_order` INT UNSIGNED NOT NULL,
                `checkout_id` VARCHAR(255) NOT NULL,
                `link` VARCHAR(512) NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_cigalpay_checkout`),
                UNIQUE KEY `checkout_id` (`checkout_id`),
                KEY `id_order` (`id_order`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4")
            && Db::getInstance()->execute("CREATE TABLE IF NOT EXISTS `{$p}cigalpay_event` (
                `payment_id` VARCHAR(64) NOT NULL,
                `status` VARCHAR(32) NOT NULL,
                `id_order` INT UNSIGNED NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`payment_id`, `status`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4")
            // Credit slips refunded in crypto, so a slip is never refunded twice.
            && Db::getInstance()->execute("CREATE TABLE IF NOT EXISTS `{$p}cigalpay_refund` (
                `id_order_slip` INT UNSIGNED NOT NULL,
                `id_order` INT UNSIGNED NOT NULL,
                `refund_id` VARCHAR(64) NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_order_slip`),
                KEY `id_order` (`id_order`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4");
    }

    private function installOrderState()
    {
        $id = (int) Configuration::get(self::CONF_OS_WAITING);
        if ($id && Validate::isLoadedObject(new OrderState($id))) {
            return true;
        }
        $state = new OrderState();
        foreach (Language::getLanguages(false) as $lang) {
            $state->name[$lang['id_lang']] = $lang['iso_code'] === 'fr' ? 'En attente du paiement crypto' : 'Awaiting crypto payment';
        }
        $state->module_name = $this->name;
        $state->color = '#1E5FBA';
        $state->send_email = false;
        $state->logable = false;
        $state->paid = false;
        $state->unremovable = true;
        $state->hidden = false;
        if (!$state->add()) {
            return false;
        }

        return Configuration::updateValue(self::CONF_OS_WAITING, (int) $state->id);
    }

    // ── Settings ──────────────────────────────────────────────────────────

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitCigalpay')) {
            $accountId = trim((string) Tools::getValue(self::CONF_ACCOUNT_ID));
            $secret = trim((string) Tools::getValue(self::CONF_IPN_SECRET));
            $apiKey = trim((string) Tools::getValue(self::CONF_API_KEY));
            if ($accountId !== '' && !preg_match('/^[0-9a-f-]{36}$/i', $accountId)) {
                $output .= $this->displayError($this->trans('The Account ID looks wrong: copy it from Settings → API credentials in your CigalPay dashboard.', [], 'Modules.Cigalpay.Admin'));
            } else {
                Configuration::updateValue(self::CONF_ACCOUNT_ID, $accountId);
                // An empty password field keeps the saved secret.
                if ($secret !== '') {
                    Configuration::updateValue(self::CONF_IPN_SECRET, $secret);
                }
                if ($apiKey !== '') {
                    Configuration::updateValue(self::CONF_API_KEY, $apiKey);
                }
                Configuration::updateValue(self::CONF_REFUND_ON_SLIP, (int) Tools::getValue(self::CONF_REFUND_ON_SLIP));
                Configuration::updateValue(self::CONF_DEBUG, (int) Tools::getValue(self::CONF_DEBUG));
                $output .= $this->displayConfirmation($this->trans('Settings saved.', [], 'Modules.Cigalpay.Admin'));
            }
        }

        $hasCurrency = false;
        foreach (Currency::getCurrencies(false, true) as $currency) {
            $hasCurrency = $hasCurrency || in_array(strtoupper($currency['iso_code']), self::CURRENCIES, true);
        }
        if (!$hasCurrency) {
            $output .= $this->displayWarning($this->trans('CigalPay prices orders in USD or EUR. Enable one of them in International → Localization → Currencies.', [], 'Modules.Cigalpay.Admin'));
        }

        return $output . $this->renderForm() . $this->renderNotifyUrl();
    }

    private function renderForm()
    {
        $form = [
            'form' => [
                'legend' => ['title' => $this->trans('CigalPay settings', [], 'Modules.Cigalpay.Admin'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Account ID', [], 'Modules.Cigalpay.Admin'),
                        'name' => self::CONF_ACCOUNT_ID,
                        'desc' => $this->trans('CigalPay dashboard → Settings → API credentials.', [], 'Modules.Cigalpay.Admin'),
                        'required' => true,
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->trans('Notification signing secret', [], 'Modules.Cigalpay.Admin'),
                        'name' => self::CONF_IPN_SECRET,
                        'desc' => Configuration::get(self::CONF_IPN_SECRET)
                            ? $this->trans('Saved. Leave empty to keep it.', [], 'Modules.Cigalpay.Admin')
                            : $this->trans('Same page. Used to check that payment notifications really come from CigalPay.', [], 'Modules.Cigalpay.Admin'),
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->trans('API key', [], 'Modules.Cigalpay.Admin'),
                        'name' => self::CONF_API_KEY,
                        'desc' => Configuration::get(self::CONF_API_KEY)
                            ? $this->trans('Saved. Leave empty to keep it.', [], 'Modules.Cigalpay.Admin')
                            : $this->trans('Same page. Only needed to refund orders in crypto.', [], 'Modules.Cigalpay.Admin'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Refund in crypto', [], 'Modules.Cigalpay.Admin'),
                        'name' => self::CONF_REFUND_ON_SLIP,
                        'is_bool' => true,
                        'desc' => $this->trans('When you refund a CigalPay order with a credit slip, refund the customer in the coin they paid, from your CigalPay balance. They get an email to enter their address.', [], 'Modules.Cigalpay.Admin'),
                        'values' => [
                            ['id' => 'refund_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
                            ['id' => 'refund_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Debug log', [], 'Modules.Cigalpay.Admin'),
                        'name' => self::CONF_DEBUG,
                        'is_bool' => true,
                        'desc' => $this->trans('Log every notification in Advanced Parameters → Logs. Errors are always logged.', [], 'Modules.Cigalpay.Admin'),
                        'values' => [
                            ['id' => 'debug_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
                            ['id' => 'debug_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
                        ],
                    ],
                ],
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitCigalpay';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [
            self::CONF_ACCOUNT_ID => Tools::getValue(self::CONF_ACCOUNT_ID, Configuration::get(self::CONF_ACCOUNT_ID)),
            self::CONF_IPN_SECRET => '',
            self::CONF_API_KEY => '',
            self::CONF_REFUND_ON_SLIP => (int) Configuration::get(self::CONF_REFUND_ON_SLIP),
            self::CONF_DEBUG => (int) Configuration::get(self::CONF_DEBUG),
        ];

        return $helper->generateForm([$form]);
    }

    private function renderNotifyUrl()
    {
        $this->context->smarty->assign('cigalpay_notify_url', $this->notifyUrl());

        return $this->display(__FILE__, 'views/templates/admin/notify_url.tpl');
    }

    // ── Checkout ──────────────────────────────────────────────────────────

    public function isConfigured()
    {
        return trim((string) Configuration::get(self::CONF_ACCOUNT_ID)) !== '' && trim((string) Configuration::get(self::CONF_IPN_SECRET)) !== '';
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->isConfigured()) {
            return [];
        }
        $currency = new Currency((int) $params['cart']->id_currency);
        if (!in_array(strtoupper($currency->iso_code), self::CURRENCIES, true)) {
            return [];
        }

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->trans('Crypto (Bitcoin, USDT, Ethereum…)', [], 'Modules.Cigalpay.Shop'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/coins.png'))
            ->setAdditionalInformation($this->fetch('module:cigalpay/views/templates/hook/payment_info.tpl'));

        return [$option];
    }

    public function hookDisplayPaymentReturn($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$this->active || !$order instanceof Order || $order->module !== $this->name) {
            return '';
        }
        $customer = new Customer((int) $order->id_customer);
        $waiting = (int) $order->getCurrentState() === (int) Configuration::get(self::CONF_OS_WAITING);
        $this->context->smarty->assign([
            'cigalpay_paid' => $order->hasBeenPaid(),
            'cigalpay_waiting' => $waiting,
            'cigalpay_pay_url' => $waiting
                ? $this->context->link->getModuleLink($this->name, 'validation', ['id_order' => (int) $order->id, 'key' => $customer->secure_key], true)
                : '',
        ]);

        return $this->fetch('module:cigalpay/views/templates/hook/payment_return.tpl');
    }

    public function notifyUrl()
    {
        return $this->context->link->getModuleLink($this->name, 'notification', [], true);
    }

    /**
     * Creates a CigalPay checkout for an order (and the other orders of its
     * cart, which share its reference) and records it. Returns the link.
     *
     * @throws CigalpayException
     */
    public function createCheckout(Order $order, Customer $customer)
    {
        $currency = new Currency((int) $order->id_currency);
        $lang = strtolower((string) Language::getIsoById((int) $order->id_lang));
        $options = ['accountId' => trim((string) Configuration::get(self::CONF_ACCOUNT_ID))];
        if (Configuration::get(self::CONF_CHECKOUT_URL)) {
            $options['checkoutUrl'] = Configuration::get(self::CONF_CHECKOUT_URL);
        }
        $checkout = (new CigalPaySdk($options))->createCheckout([
            'amount' => round($this->referenceTotal($order), 2),
            'currency' => strtoupper($currency->iso_code),
            'orderId' => (string) $order->id,
            'description' => Tools::substr(sprintf('%s %s - %s', $this->trans('Order', [], 'Modules.Cigalpay.Shop'), $order->reference, Configuration::get('PS_SHOP_NAME')), 0, 200),
            'returnUrl' => $this->context->link->getPageLink('order-confirmation', true, (int) $order->id_lang, [
                'id_cart' => (int) $order->id_cart,
                'id_module' => (int) $this->id,
                'id_order' => (int) $order->id,
                'key' => $customer->secure_key,
            ]),
            'notifyUrl' => $this->notifyUrl(),
            'lang' => in_array($lang, self::LANGS, true) ? $lang : 'en',
        ]);
        if (empty($checkout['id']) || empty($checkout['chargeLink'])) {
            throw new CigalpayException('CigalPay: unexpected checkout reply');
        }
        Db::getInstance()->insert('cigalpay_checkout', [
            'id_order' => (int) $order->id,
            'checkout_id' => pSQL($checkout['id']),
            'link' => pSQL($checkout['chargeLink']),
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        return $checkout['chargeLink'];
    }

    // ── Notifications ─────────────────────────────────────────────────────

    /**
     * Applies a verified notification. Trusted only when the payment is for a
     * checkout this shop created for that order, at the order's price.
     */
    public function applyNotification(array $p)
    {
        $paymentId = isset($p['id']) ? (string) $p['id'] : '';
        $status = isset($p['status']) ? (string) $p['status'] : '';
        $orderId = isset($p['extraId']) && ctype_digit((string) $p['extraId']) ? (int) $p['extraId'] : 0;
        $this->log(1, sprintf('Notification: payment %s, order %d, status %s', $paymentId, $orderId, $status), $orderId);

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order) || $order->module !== $this->name || $paymentId === '') {
            $this->log(1, 'Ignored: no CigalPay order with this reference.', $orderId);

            return;
        }

        // Anyone can create a checkout with this shop's account and an order
        // number; only the checkouts this shop made count.
        $checkoutId = isset($p['checkoutId']) ? (string) $p['checkoutId'] : '';
        $known = $checkoutId !== '' && (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'cigalpay_checkout` WHERE `id_order` = ' . (int) $order->id . " AND `checkout_id` = '" . pSQL($checkoutId) . "'"
        );
        if (!$known) {
            $this->log(3, sprintf('Order %d: ignored payment %s, it is not for a checkout this shop created for the order.', $order->id, $paymentId), $order->id);

            return;
        }

        // Each payment status is handled once.
        $first = Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . "cigalpay_event` (`payment_id`, `status`, `id_order`, `date_add`) VALUES ('"
            . pSQL($paymentId) . "', '" . pSQL($status) . "', " . (int) $order->id . ", NOW())")
            && Db::getInstance()->Affected_Rows() > 0;
        if (!$first) {
            return;
        }

        $coin = $this->coinAmount(isset($p['amount']) ? $p['amount'] : '', $p);
        if (CigalPaySdk::isPaid($status)) {
            $this->onPaid($order, $p, $coin);

            return;
        }
        if ($order->hasBeenPaid()) {
            return;
        }
        switch ($status) {
            case CigalPaySdk::STATUS_WAITING:
                $this->note($order, sprintf('CigalPay: the customer chose to pay %s.', $coin));
                break;
            case CigalPaySdk::STATUS_CONFIRMING:
                $this->note($order, sprintf('CigalPay: payment of %s detected, waiting for network confirmations.', $coin));
                break;
            case CigalPaySdk::STATUS_PARTIALLY_PAID:
                $this->setState($order, (int) Configuration::get('PS_OS_ERROR'));
                $this->note($order, sprintf(
                    'CigalPay: the customer sent only %s of %s. Contact them before shipping.',
                    $this->coinAmount(isset($p['amountPaid']) ? $p['amountPaid'] : 0, $p),
                    $coin
                ));
                break;
            case CigalPaySdk::STATUS_EXPIRED:
            case CigalPaySdk::STATUS_FAILED:
                $this->note($order, sprintf('CigalPay: the payment of %s expired without being paid. The customer can pay again from their order confirmation page.', $coin));
                break;
        }
    }

    private function onPaid(Order $order, array $p, $coin)
    {
        $paymentId = (string) $p['id'];
        if ($order->hasBeenPaid()) {
            // CONFIRMED then FINISHED for the same payment is not a second payment.
            foreach ($order->getOrderPaymentCollection() as $payment) {
                if ($payment->transaction_id === Tools::substr($paymentId, 0, 254)) {
                    return;
                }
            }
            $this->note($order, sprintf('CigalPay: a second payment of %s was received for this already paid order (payment %s). Refund the customer if it was a mistake.', $coin, $paymentId));

            return;
        }

        $total = $this->referenceTotal($order);
        $currency = new Currency((int) $order->id_currency);
        $paidAmount = isset($p['checkoutAmount']) ? (float) $p['checkoutAmount'] : -1;
        $paidCurrency = isset($p['checkoutCurrency']) ? strtoupper((string) $p['checkoutCurrency']) : '';
        if ($paidCurrency !== strtoupper($currency->iso_code) || abs($paidAmount - $total) > 0.01) {
            $this->setState($order, (int) Configuration::get('PS_OS_ERROR'));
            $this->note($order, sprintf('CigalPay: payment received for %s %s, but the order total is now %s %s. Check before shipping.', $paidAmount, $paidCurrency, $total, strtoupper($currency->iso_code)));

            return;
        }

        $note = sprintf('CigalPay: paid %s (payment %s).', $coin, $paymentId);
        if (!empty($p['swapFromCurrency'])) {
            $note .= sprintf(' The customer paid in %s %s, converted automatically.', isset($p['swapFromAmount']) ? $p['swapFromAmount'] : '', $p['swapFromCurrency']);
        }
        foreach ($this->referenceOrders($order) as $o) {
            $this->setState($o, (int) Configuration::get('PS_OS_PAYMENT'));
            foreach ($o->getOrderPaymentCollection() as $payment) {
                if (!$payment->transaction_id) {
                    $payment->transaction_id = Tools::substr($paymentId, 0, 254);
                    $payment->update();
                }
            }
            $this->note($o, $note);
        }
    }

    // ── Refunds ───────────────────────────────────────────────────────────

    /**
     * A credit slip was created (partial or standard refund): refund its
     * amount in crypto, from the CigalPay balance. The slip can't be undone
     * from here, so a refund CigalPay refuses is reported in the order's
     * messages for the shop to handle.
     */
    public function hookActionOrderSlipAdd($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$order instanceof Order || $order->module !== $this->name || !Configuration::get(self::CONF_REFUND_ON_SLIP)) {
            return;
        }
        $p = _DB_PREFIX_;
        $slip = Db::getInstance()->getRow("SELECT s.`id_order_slip`, s.`total_products_tax_incl`, s.`total_shipping_tax_incl` FROM `{$p}order_slip` s
            LEFT JOIN `{$p}cigalpay_refund` r ON r.`id_order_slip` = s.`id_order_slip`
            WHERE s.`id_order` = " . (int) $order->id . ' AND r.`id_order_slip` IS NULL ORDER BY s.`id_order_slip` DESC');
        if (!$slip) {
            return;
        }
        $amount = round((float) $slip['total_products_tax_incl'] + (float) $slip['total_shipping_tax_incl'], 2);
        $currency = strtoupper((new Currency((int) $order->id_currency))->iso_code);

        $paymentId = '';
        foreach ($order->getOrderPaymentCollection() as $payment) {
            $paymentId = $paymentId ?: (string) $payment->transaction_id;
        }
        if (!$order->hasBeenPaid() || $paymentId === '' || $amount <= 0) {
            return;
        }
        // "Generate a voucher" in the refund form: the customer is refunded with a voucher, not in crypto too.
        $form = Tools::getValue('cancel_product');
        if (is_array($form) && !empty($form['voucher'])) {
            $this->note($order, sprintf('CigalPay: credit slip of %s %s refunded with a voucher, so not in crypto.', $amount, $currency));

            return;
        }
        $apiKey = trim((string) Configuration::get(self::CONF_API_KEY));
        if ($apiKey === '') {
            $this->note($order, sprintf('CigalPay: credit slip of %s %s not refunded in crypto: add your API key in the CigalPay module settings, or refund the customer from your CigalPay dashboard.', $amount, $currency));

            return;
        }

        $options = ['apiKey' => $apiKey];
        if (Configuration::get(self::CONF_MERCHANT_API_URL)) {
            $options['merchantApiUrl'] = Configuration::get(self::CONF_MERCHANT_API_URL);
        }
        $customer = new Customer((int) $order->id_customer);
        try {
            $res = (new CigalPaySdk($options))->createRefund([
                'paymentId' => $paymentId,
                'checkoutAmount' => $amount,
                'customerEmail' => $customer->email,
                'reason' => Tools::substr(sprintf('Order %s, credit slip %d', $order->reference, $slip['id_order_slip']), 0, 200),
            ]);
        } catch (CigalpayException $e) {
            $this->log(3, sprintf('Order %d: refund of %s %s refused: %s', $order->id, $amount, $currency, $e->getMessage()), $order->id);
            $this->note($order, sprintf('CigalPay: the credit slip of %s %s was not refunded in crypto: %s', $amount, $currency, $e->getMessage()));

            return;
        }
        $refund = isset($res['refund']) && is_array($res['refund']) ? $res['refund'] : [];
        Db::getInstance()->insert('cigalpay_refund', [
            'id_order_slip' => (int) $slip['id_order_slip'],
            'id_order' => (int) $order->id,
            'refund_id' => pSQL(isset($refund['id']) ? (string) $refund['id'] : ''),
            'date_add' => date('Y-m-d H:i:s'),
        ]);
        $this->note($order, sprintf(
            'CigalPay: refund of %s %s started (%s). The customer got an email to enter their address: %s. CigalPay sends it once reviewed.',
            $amount,
            $currency,
            $this->coinAmount(isset($refund['amount']) ? $refund['amount'] : '', ['currency' => isset($refund['currency']) ? $refund['currency'] : '']),
            isset($res['link']) ? $res['link'] : ''
        ));
    }

    /** The orders of the cart (PrestaShop splits some carts; they share a reference). */
    private function referenceOrders(Order $order)
    {
        $orders = [];
        foreach (Order::getByReference($order->reference) as $o) {
            if ($o->module === $this->name) {
                $orders[] = $o;
            }
        }

        return $orders ?: [$order];
    }

    private function referenceTotal(Order $order)
    {
        $total = 0.0;
        foreach ($this->referenceOrders($order) as $o) {
            $total += (float) $o->total_paid_tax_incl;
        }

        return round($total, 2);
    }

    private function setState(Order $order, $stateId)
    {
        if ((int) $order->getCurrentState() === (int) $stateId) {
            return;
        }
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState((int) $stateId, $order, true);
        $history->addWithemail(true);
    }

    /** A private message on the order, shown to the shop in the back office only. */
    private function note(Order $order, $text)
    {
        $thread = (int) CustomerThread::getIdCustomerThreadByEmailAndIdOrder((new Customer((int) $order->id_customer))->email, (int) $order->id);
        if (!$thread) {
            $customerThread = new CustomerThread();
            $customerThread->id_contact = 0;
            $customerThread->id_customer = (int) $order->id_customer;
            $customerThread->id_shop = (int) $order->id_shop;
            $customerThread->id_order = (int) $order->id;
            $customerThread->id_lang = (int) $order->id_lang;
            $customerThread->email = (new Customer((int) $order->id_customer))->email;
            $customerThread->status = 'open';
            $customerThread->token = Tools::passwdGen(12);
            $customerThread->add();
            $thread = (int) $customerThread->id;
        }
        $message = new CustomerMessage();
        $message->id_customer_thread = $thread;
        $message->id_employee = 0;
        $message->message = Tools::substr($text, 0, 1600);
        $message->private = 1;
        $message->add();
    }

    private function coinAmount($amount, array $p)
    {
        $coin = isset($p['currency']) ? (string) $p['currency'] : '';
        $coin = $coin === 'USDT_TRX' ? 'USDT (TRC-20)' : str_replace('_', ' ', $coin);

        return trim($amount . ' ' . $coin);
    }

    /** Severity 1 = info (only with debug on), 3 = error. */
    public function log($severity, $message, $orderId = null)
    {
        if ($severity < 3 && !Configuration::get(self::CONF_DEBUG)) {
            return;
        }
        PrestaShopLogger::addLog('CigalPay: ' . $message, $severity, null, $orderId ? 'Order' : null, $orderId ? (int) $orderId : null, true);
    }
}
