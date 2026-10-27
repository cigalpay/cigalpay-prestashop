{**
 * @author    CigalPay <support@cigalpay.com>
 * @copyright CigalPay
 * @license   MIT
 *}
{if $cigalpay_paid}
  <p>{l s='Your crypto payment is confirmed. Thank you!' d='Modules.Cigalpay.Shop'}</p>
{elseif $cigalpay_waiting}
  {if isset($smarty.get.cigalpay_error)}
    <div class="alert alert-warning">{l s='Crypto payment was unavailable for a moment. Your order is saved: you can pay it now.' d='Modules.Cigalpay.Shop'}</div>
  {else}
    <p>{l s='Your crypto payment is being confirmed on the network. This usually takes a few minutes, and you will get an email once your order is confirmed.' d='Modules.Cigalpay.Shop'}</p>
  {/if}
  <p><a class="btn btn-primary" href="{$cigalpay_pay_url|escape:'htmlall':'UTF-8'}">{l s='Not paid yet? Go to the payment page' d='Modules.Cigalpay.Shop'}</a></p>
{/if}
