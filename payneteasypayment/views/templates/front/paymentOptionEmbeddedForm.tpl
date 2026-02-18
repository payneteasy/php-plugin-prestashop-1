{**
 * @author    Payneteasy
 * @copyright 2007-2026 Payneteasy
 * @license   Property of Payneteasy
 *}
<form action="{$action}" id="payment-form" class="form-horizontal">
  <input type="hidden" name="option" value="embedded">
  <p>{l s='Credit Card Payment' mod='payneteasypayment'}</p>

  <div class="form-group row">
    <label class="form-control-label required col-md-3" for="credit_card_number">{l s='Card number' mod='payneteasypayment'}</label>
    <div class="col-md-6">
      <input value="{$card_number_value}" type="text" name="credit_card_number" id="credit_card_number" class="form-control" autocomplete="cc-number" required>
    </div>
  </div>

  <div class="form-group row">
    <label class="form-control-label required col-md-3" for="card_printed_name">{l s='Printed name' mod='payneteasypayment'}</label>
    <div class="col-md-6">
      <input value="{$printed_name_value}" type="text" name="card_printed_name" id="card_printed_name" class="form-control" placeholder="{l s='Printed name' mod='payneteasypayment'}" autocomplete="cc-name" required>
    </div>
  </div>

  <div class="form-group row">
    <label class="form-control-label required col-md-3" for="expire_month">{l s='Expiry month' mod='payneteasypayment'}</label>
    <div class="col-md-3">
      <input class="form-control" minlength="2" maxlength="2" name="expire_month" id="expire_month" value="{$expiry_month_value}" type="text" autocomplete="off" placeholder="MM" required>
    </div>
    <label class="form-control-label required col-md-3" for="expire_year">{l s='Expiry year' mod='payneteasypayment'}</label>
    <div class="col-md-3">
      <input class="form-control" minlength="4" maxlength="4" name="expire_year" id="expire_year" value="{$expiry_year_value}" type="text" autocomplete="off" placeholder="YYYY" required>
    </div>
  </div>

  <div class="form-group row">
    <label class="form-control-label required col-md-3" for="cvv2">{l s='CVC' mod='payneteasypayment'}</label>
    <div class="col-md-3">
      <input type="text" name="cvv2" id="cvv2" value="{$cvv2_value}" class="form-control" autocomplete="cc-csc" required>
    </div>
    <div class="col-md-3" style="text-align: right">
      <img src="{$cvv_image}" style="height: 80px">
    </div>
  </div>

</form>
