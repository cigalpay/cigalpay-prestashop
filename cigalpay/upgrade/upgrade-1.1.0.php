<?php
/**
 * 1.1.0: refunds in crypto from a credit slip.
 *
 * @author    CigalPay <support@cigalpay.com>
 * @copyright CigalPay
 * @license   MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0($module)
{
    return $module->installDb()
        && $module->registerHook('actionOrderSlipAdd')
        && Configuration::updateValue(Cigalpay::CONF_REFUND_ON_SLIP, 1);
}
