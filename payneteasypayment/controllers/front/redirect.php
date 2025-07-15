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


include_once(_PS_MODULE_DIR_.'payneteasypayment'.DIRECTORY_SEPARATOR.'classes'.DIRECTORY_SEPARATOR.'Client.php');
include_once(_PS_MODULE_DIR_.'payneteasypayment'.DIRECTORY_SEPARATOR.'payneteasypayment.php');
class PayneteasypaymentRedirectModuleFrontController extends ModuleFrontController {
	public function initContent() {
		parent::initContent();

		if ($id_cart = Tools::getValue('id_cart')) {
			$cart = new Cart($id_cart);

			if (!Validate::isLoadedObject($cart))
				$cart = $this->context->cart;
		}
		else
			$cart = $this->context->cart;
		

		$card_data = [
			'cvv2' => Tools::getValue('cvv2'),
			'expire_year' => (int)Tools::getValue('expire_year'),
			'expire_month' => preg_replace('/^(\d)$/', '0$1', Tools::getValue('expire_month')),
			'card_printed_name' => Tools::getValue('card_printed_name'),
			'credit_card_number' => Tools::getValue('credit_card_number'),
		];

		$data = $this->prepareData($cart, $card_data);
		$integration_method = Configuration::get('PAYNETEASY_PAYMENT_INTEGRATION_METHOD');
		$createPayment = $this->createPayment($data);

		$db = \Db::getInstance();
		$result = $db->insert('payneteasy_payments', [
			'merchant_order_id' => (int) $createPayment['merchant-order-id'],
			'serial_number' => pSQL($createPayment['serial-number']),
			'paynet_order_id' => (int) $createPayment['paynet-order-id'],
		]);

		if (isset($createPayment) && $createPayment['type'] == 'error')
			Tools::redirectLink($this->context->link->getPageLink('order', null, null, 'step=3'));
		elseif (isset($createPayment) && $createPayment['type'] == 'validation-error')
			Tools::redirectLink($this->context->link->getPageLink('order', null, null, 'step=3'));
		else {
			isset($createPayment['redirect-url'])
				? Tools::redirectLink($createPayment['redirect-url']) // редирект на платежную форму
				: Tools::redirectLink($this->context->link->getModuleLink('payneteasypayment', 'confirmation', ['cart_id'=>$cart->id, 'secure_key'=>$cart->secure_key], true));
		}

		if ($cart->id_customer == 0
				|| !$this->module->active
				|| Configuration::get('PAYNETEASY_PAYMENT_ACTIVE_MODE', false) === false)
			Tools::redirect('index.php?controller=order&step=1');
	}

	private function prepareDataStatus($cart) {
		$merchantControl = Configuration::get('PAYNETEASY_PAYMENT_CONTROL_KEY');
		$endpointId = Configuration::get('PAYNETEASY_PAYMENT_END_POINT');
		$login = Configuration::get('PAYNETEASY_PAYMENT_LOGIN');

		$paynet_order_id = Db::getInstance()->getValue(
			'SELECT paynet_order_id FROM `' . _DB_PREFIX_ . 'payneteasy_payments` WHERE merchant_order_id = ' . $cart->id);

		$data = [
			'login' => $login,
			'client_orderid' => (string)$cart->id,
			'orderid' => $paynet_order_id,
		];

		$data['control'] = $this->signStatusRequest($data, $login, $merchantControl);

		return $data;
	}
    
	private function signStatusRequest($requestFields, $login, $merchantControl)
		{ return $this->signString($login .$requestFields['client_orderid'] .$requestFields['orderid'] .$merchantControl); }

	private function prepareData($cart, $card_data) {
		$address = new Address($cart->id_address_delivery);
		$orderId = Order::getOrderByCartId($cart->id);

		$currency = new Currency((int)($cart->id_currency));
		$currency_code = trim($currency->iso_code);
		$country = Country::getIsoById((int)$address->id_country);
		$state = $this->getStateIsoById($address->id_state);
		$merchantControl = Configuration::get('PAYNETEASY_PAYMENT_CONTROL_KEY');
		$endpointId = Configuration::get('PAYNETEASY_PAYMENT_END_POINT');

		$data = [
			'client_orderid' => (string)$cart->id,
			'order_desc' => $this->module->l('Order on ') . Configuration::get('PS_SHOP_NAME'),
			'amount' => $cart->getOrderTotal(),
			'currency' => $currency_code,
			'address1' => $address->address1,
			'city' => $address->city,
			'zip_code' => $address->postcode?$address->postcode:'00000',
			'country' => $country,
			'state' => $state,
			'phone' => $address->phone_mobile?$address->phone_mobile:$address->phone,
			'email' => $this->context->customer->email,
			'ipaddress' => $_SERVER['REMOTE_ADDR'],
			'cvv2' => $card_data['cvv2'],
			'credit_card_number' => $card_data['credit_card_number'],
			'card_printed_name' => $card_data['card_printed_name'],
			'expire_month' => $card_data['expire_month'],
			'expire_year' => $card_data['expire_year'],
			'first_name' => $this->context->customer->firstname,
			'last_name' => $this->context->customer->lastname,
			'redirect_success_url' => $this->context->link->getModuleLink('payneteasypayment', 'confirmation', ['order_id' => $orderId, 'cart_id'=>$cart->id, 'secure_key'=>$cart->secure_key], true),
			'redirect_fail_url' => $this->context->link->getModuleLink('payneteasypayment', 'error', ['order_id' => $orderId, 'cart_id'=>$cart->id, 'secure_key'=>$cart->secure_key], true),
			'redirect_url' => $this->context->link->getModuleLink('payneteasypayment', 'confirmation', ['order_id' => $orderId, 'cart_id'=>$cart->id, 'secure_key'=>$cart->secure_key], true),
			'server_callback_url' => $this->context->link->getModuleLink('payneteasypayment', 'confirmation', ['order_id' => $orderId, 'cart_id'=>$cart->id, 'secure_key'=>$cart->secure_key], true),
		];

		$data['control'] = $this->signPaymentRequest($data, $endpointId, $merchantControl);

		return $data;
	}

	private function signString($s)
		{ return sha1($s); }

	private function signPaymentRequest($data, $endpointId, $merchantControl)
		{ return $this->signString($endpointId .$data['client_orderid'] .(int)($data['amount'] * 100) .$data['email'] .$merchantControl); }

	private function createPayment($data) {
		$client = $this->initClient();
		$integration_method = Configuration::get('PAYNETEASY_PAYMENT_INTEGRATION_METHOD'); // direct & form
		$endpoint = Configuration::get('PAYNETEASY_PAYMENT_END_POINT');

		$action_url = Configuration::get('PAYNETEASY_PAYMENT_TEST_MODE')
			? Configuration::get('PAYNETEASY_PAYMENT_SANDBOX_DOMAIN_CHECKOUT')
			: Configuration::get('PAYNETEASY_PAYMENT_LIVE_DOMAIN_CHECKOUT');

		return $integration_method == 'direct'
			? $client->saleDirect($data, $integration_method, $action_url, $endpoint)
			: $client->saleForm($data, $integration_method, $action_url, $endpoint);
	}

	private function initClient() {
		$login = Configuration::get('PAYNETEASY_PAYMENT_LOGIN');
		$pass = Configuration::get('PAYNETEASY_PAYMENT_CONTROL_KEY');
		$endpoint = Configuration::get('PAYNETEASY_PAYMENT_END_POINT');
		$integration_method = Configuration::get('PAYNETEASY_PAYMENT_INTEGRATION_METHOD');

		return new Client($login, $pass, $endpoint, $integration_method);
	}

		private function getStateIsoById($idState)
			{ return Db::getInstance()->getValue('SELECT `iso_code` FROM `'._DB_PREFIX_.'state` WHERE `id_state`='.(int)$idState); }
}
