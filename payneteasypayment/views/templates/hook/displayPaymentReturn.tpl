{**
 * @author    Payneteasy
 * @copyright 2007-2026 Payneteasy
 * @license   Property of Payneteasy
 *}
<section id="{$moduleName}-displayPaymentReturn">
    {if !empty($transaction)}
        <p>{l s='Your transaction reference is %transaction%.' mod='payneteasypayment' sprintf=['%transaction%' => $transaction]}</p>
    {/if}
    {if $customer.is_logged && !$customer.is_guest}
        <p><a href="{$transactionsLink}">{l s='See all previous transactions in your account.' mod='payneteasypayment'}</a></p>
    {/if}
</section>
