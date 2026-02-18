{**
 * @author    Payneteasy
 * @copyright 2007-2026 Payneteasy
 * @license   Property of Payneteasy
 *}
{if (isset($status) == true) && ($status == 'ok')}
    <h3>{l s='Your order is confirmed.' mod='payneteasypayment'}</h3>
    <p>
        <br />{l s='Amount' mod='payneteasypayment'}: <span class="price"><strong>{$total}</strong></span>
        <br /><br />{l s='An email has been sent with this information.' mod='payneteasypayment'}
        <br /><br />{l s='If you have questions, comments or concerns, please contact our' mod='payneteasypayment'} <a href="{$link->getPageLink('contact', true)}">{l s='expert customer support team.' mod='payneteasypayment'}</a>
    </p>
{else}
    <h3>{l s='Your order has not been accepted.' mod='payneteasypayment'}</h3>
    <p>
        <br /><br />{l s='Please, try to order again.' mod='payneteasypayment'}
        <br /><br />{l s='If you have questions, comments or concerns, please contact our' mod='payneteasypayment'} <a href="{$link->getPageLink('contact', true)}">{l s='expert customer support team.' mod='payneteasypayment'}</a>
    </p>
{/if}
<hr />
