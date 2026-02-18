{**
 * @author    Payneteasy
 * @copyright 2007-2026 Payneteasy
 * @license   Property of Payneteasy
 *}
{if not empty($errors)}
    <div class="text-xs-center">
        <h3>{l s='An error occurred' mod='payneteasypayment'}:</h3>
        <ul class="alert alert-danger">
            {foreach from=$errors item='error'}
                <li>{$error}</li>
            {/foreach}
        </ul>
    </div>
{/if}
