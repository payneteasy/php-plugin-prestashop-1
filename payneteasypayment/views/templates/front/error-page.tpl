{**
 * @author    Payneteasy
 * @copyright 2007-2026 Payneteasy
 * @license   Property of Payneteasy
 *}
{extends file=$layout}
{block name='content'}
    <section id="main">

        {block name='page_header_container'}
            {block name='page_title' hide}
                <header class="page-header">
                    <h1>{$smarty.block.child}</h1>
                </header>
            {/block}
        {/block}

        {block name='page_content_container'}
            <section id="content" class="page-content card card-block">
                {block name='page_content_top'}{/block}
                {block name='page_content'}
                    <div class="payment_error_payneteasypayment">
                        <h1 class="text-xs-center">{l s='Payment error.' mod='payneteasypayment'}</h1>
                        <h2 class="text-xs-center">{l s='Your order will be canceled.' mod='payneteasypayment'}</h2>
                    </div>
                {/block}
            </section>
        {/block}

        {block name='page_footer_container'}
            <footer class="page-footer">
                {block name='page_footer'}
                    <!-- Footer content -->
                {/block}
            </footer>
        {/block}

    </section>
{/block}
