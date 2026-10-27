<?php
/**
 * Places the order in "Awaiting crypto payment" and sends the customer to the
 * CigalPay checkout. With ?id_order=&key= (from the order confirmation page)
 * it makes a new checkout for an order that is still waiting.
 *
 * @author    CigalPay <support@cigalpay.com>
 * @copyright CigalPay
 * @license   MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use Cigalpay\Exceptions\CigalpayException;

class CigalpayValidationModuleFrontController extends ModuleFrontController
{
    /** @var Cigalpay */
    public $module;

    public function postProcess()
    {
        if (!$this->module->active || !$this->module->isConfigured()) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 3]));
        }

        $orderId = (int) Tools::getValue('id_order');
        if ($orderId) {
            $this->payAgain($orderId, (string) Tools::getValue('key'));

            return;
        }

        $cart = $this->context->cart;
        if (!$cart->id || !$cart->id_customer || !$cart->id_address_delivery || !$cart->id_address_invoice) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            $authorized = $authorized || $module['name'] === $this->module->name;
        }
        $customer = new Customer((int) $cart->id_customer);
        $currency = new Currency((int) $cart->id_currency);
        if (!$authorized || !Validate::isLoadedObject($customer) || !in_array(strtoupper($currency->iso_code), Cigalpay::CURRENCIES, true)) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 3]));
        }

        $this->module->validateOrder(
            (int) $cart->id,
            (int) Configuration::get(Cigalpay::CONF_OS_WAITING),
            (float) $cart->getOrderTotal(true, Cart::BOTH),
            $this->module->displayName,
            null,
            [],
            (int) $currency->id,
            false,
            $customer->secure_key
        );
        $order = new Order((int) $this->module->currentOrder);
        $this->redirectToCheckout($order, $customer);
    }

    private function payAgain($orderId, $key)
    {
        $order = new Order($orderId);
        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name || $key === '' || !hash_equals((string) $customer->secure_key, $key)
            || (int) $order->getCurrentState() !== (int) Configuration::get(Cigalpay::CONF_OS_WAITING)) {
            Tools::redirect($this->context->link->getPageLink('index', true));
        }
        $this->redirectToCheckout($order, $customer);
    }

    private function redirectToCheckout(Order $order, Customer $customer)
    {
        try {
            $link = $this->module->createCheckout($order, $customer);
        } catch (CigalpayException $e) {
            $this->module->log(3, sprintf('Order %d: checkout not created: %s', $order->id, $e->getMessage()), $order->id);
            // The order stays "Awaiting crypto payment": the confirmation page offers to try again.
            Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, [
                'id_cart' => (int) $order->id_cart,
                'id_module' => (int) $this->module->id,
                'id_order' => (int) $order->id,
                'key' => $customer->secure_key,
                'cigalpay_error' => 1,
            ]));

            return;
        }
        Tools::redirect($link);
    }
}
