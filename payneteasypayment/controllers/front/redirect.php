<?php
/**
* 2007-2024 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    R-D <info@rus-design.com>
*  @copyright 2007-2024 Rus-Design
*  @license   Property of Rus-Design
*/

if (!defined('_PS_VERSION_'))
	exit;

include_once(_PS_MODULE_DIR_.'payneteasypayment'.DIRECTORY_SEPARATOR.'payneteasypayment.php');
class PayneteasypaymentRedirectModuleFrontController extends ModuleFrontController {
	public function initContent(): void {
		parent::initContent();

		$Cart = $this->context->cart;
		$this->module->validateOrder($Cart->id, Payneteasypayment::__STATE_WAITING(), $Cart->getOrderTotal(), $this->module->displayName, null, array(), $Cart->id_currency, false, $Cart->secure_key);

		$order_id = method_exists('Order', 'getIdByCartId') ? Order::getIdByCartId($Cart->id) : Order::getOrderByCartId($Cart->id);

		$card = [
			'cvv2' => Tools::getValue('cvv2'),
			'expire_year' => Tools::getValue('expire_year'),
			'expire_month' => sprintf('%02d', Tools::getValue('expire_month')),
			'card_printed_name' => Tools::getValue('card_printed_name'),
			'credit_card_number' => Tools::getValue('credit_card_number') ];

		$sale = Payneteasypayment::Api()->sale($this->saleData($order_id, $Cart, $card));

		$db = \Db::getInstance();
		$result = $db->insert('payneteasy_payments', [
			'merchant_order_id' => $sale['merchant-order-id'],
			'serial_number' => pSQL($sale['serial-number']),
			'paynet_order_id' => $sale['paynet-order-id'] ]);

		if (isset($sale) && in_array($sale['type'], ['error','validation-error']))
			$this->redirectLink($this->context->link->getPageLink('order', null, null, 'step=3'));
		else
			isset($sale['redirect-url'])
				? $this->redirectLink($sale['redirect-url'])
				: $this->redirectLink($this->context->link->getModuleLink('payneteasypayment', 'confirmation', ['order_id' => $order_id, 'secure_key' => $Cart->secure_key], true));

		if (!$Cart->id_customer || !$this->module->active)
			Tools::redirect('index.php?controller=order&step=1');
	}

	private function saleData(int $order_id, Cart $Cart, array $card): array {
		$Address = new Address($Cart->id_address_delivery);

		$_fn_Url = fn($type='confirmation') => $this->context->link->getModuleLink('payneteasypayment', $type, [ 'order_id' => $order_id, 'secure_key' => $Cart->secure_key ], true);
		
		$data = [
			'client_orderid' => $order_id,
			'order_desc' => $this->module->l('Order on ') . Configuration::get('PS_SHOP_NAME'),
			'amount' => $Cart->getOrderTotal(),
			'currency' => (new Currency($Cart->id_currency))->iso_code,
			'address1' => $Address->address1,
			'city' => $Address->city,
			'zip_code' => $Address->postcode ? $Address->postcode : '00000',
			'country' => Country::getIsoById($Address->id_country),
			'state' => $this->getStateIsoById($Address->id_state),
			'phone' => $Address->phone_mobile ? $Address->phone_mobile : $Address->phone,
			'email' => $this->context->customer->email,
			'ipaddress' => $_SERVER['REMOTE_ADDR'],
			'cvv2' => $card['cvv2'],
			'credit_card_number' => $card['credit_card_number'],
			'card_printed_name' => $card['card_printed_name'],
			'expire_month' => $card['expire_month'],
			'expire_year' => $card['expire_year'],
			'first_name' => $this->context->customer->firstname,
			'last_name' => $this->context->customer->lastname,
			'redirect_success_url' => $_fn_Url(),
			'redirect_fail_url' => $_fn_Url('error'),
			'redirect_url' => $_fn_Url(),
			'server_callback_url' => $_fn_Url() ];

		return $data;
	}

	private function redirectLink(string $where): void
		{ method_exists('Tools', 'redirect') ? Tools::redirect($where) : Tools::redirectLink($where); }

	private function getStateIsoById(string $idState): string
		{ return Db::getInstance()->getValue('SELECT `iso_code` FROM `'._DB_PREFIX_.'state` WHERE `id_state`='.(int)$idState); }
}

?>
