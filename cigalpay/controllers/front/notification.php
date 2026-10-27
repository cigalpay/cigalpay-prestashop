<?php
/**
 * CigalPay's payment notification (POST). Rejected unless the x-cigalpay-sig
 * header signs the body with the shop's notification signing secret.
 *
 * @author    CigalPay <support@cigalpay.com>
 * @copyright CigalPay
 * @license   MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use Cigalpay\CigalPay as CigalPaySdk;

class CigalpayNotificationModuleFrontController extends ModuleFrontController
{
    /** @var Cigalpay */
    public $module;

    public $ssl = true;

    public function postProcess()
    {
        $raw = (string) file_get_contents('php://input');
        $sig = isset($_SERVER['HTTP_X_CIGALPAY_SIG']) ? (string) $_SERVER['HTTP_X_CIGALPAY_SIG'] : '';

        if (!CigalPaySdk::verifyWebhook($raw, $sig, (string) Configuration::get(Cigalpay::CONF_IPN_SECRET))) {
            $this->module->log(3, 'Notification rejected: bad or missing signature. Check the notification signing secret in the module settings.');
            $this->respond(401);
        }
        $notification = json_decode($raw, true);
        if (!is_array($notification)) {
            $this->respond(400);
        }
        $this->module->applyNotification($notification);
        $this->respond(200);
    }

    private function respond($status)
    {
        http_response_code($status);
        header('Content-Type: text/plain');
        exit;
    }
}
