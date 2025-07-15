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
class PayneteasypaymentConfirmationModuleFrontController extends ModuleFrontController {
	public function initContent() {
		parent::initContent();
		$cart_id = Tools::getValue('cart_id');
		$secure_key = Tools::getValue('secure_key');
		$cart = new Cart((int) $cart_id);
		$customer = new Customer((int) $cart->id_customer);

		$payment_status_approved = Configuration::get('PS_OS_PAYMENT');
		$payment_status_error = Configuration::get('PS_OS_ERROR');
		$payment_status_processing = Configuration::get('PAYNETEASY_PAYMENT_STATE_WAITING');
		$currency_id = (int) Context::getContext()->currency->id;

		$integration_method = Configuration::get('PAYNETEASY_PAYMENT_INTEGRATION_METHOD');
		$endpoint = Configuration::get('PAYNETEASY_PAYMENT_END_POINT');

		$action_url = Configuration::get('PAYNETEASY_PAYMENT_TEST_MODE')
			? Configuration::get('PAYNETEASY_PAYMENT_SANDBOX_DOMAIN_CHECKOUT')
			: Configuration::get('PAYNETEASY_PAYMENT_LIVE_DOMAIN_CHECKOUT');

		$return = Tools::getAllValues();

		if (isset($return['status'])) { // METHOD FORM
			if ($return['status'] == 'declined' || $return['status'] == 'error' || $return['status'] == 'filtered') {
				$this->module->validateOrder($cart_id, $payment_status_error, $cart->getOrderTotal(), $this->module->displayName, null, array(), $currency_id, false, $secure_key);
				$order_id = Order::getOrderByCartId((int) $cart->id);
				$module_id = $this->module->id;
				Tools::redirect($this->context->link->getModuleLink('payneteasypayment', 'error', ['cart_id'=>$cart->id, 'secure_key'=>$cart->secure_key], true));
			}

			if ($return['status'] == 'approved') {
				$this->module->validateOrder($cart_id, $payment_status_approved, $cart->getOrderTotal(), $this->module->displayName, null, array(), $currency_id, false, $secure_key);
				$order_id = Order::getOrderByCartId((int) $cart->id);
				$module_id = $this->module->id;
				Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart_id . '&id_module=' . $module_id . '&id_order=' . $order_id . '&key=' . $secure_key);
			}
		}
		else { // METHOD DIRECT
			$data = $this->prepareData($cart);
			$client   = $this->initClient();
			$response = $client->status($data, $integration_method, $action_url, $endpoint);
			$order_id = Order::getOrderByCartId((int) $cart->id);
			$module_id = $this->module->id;

			if (trim($response["status"]) == 'declined' || trim($response["status"]) == 'error' || trim($response["status"]) == 'filtered') {
				$this->module->validateOrder($cart_id, $payment_status_error, $cart->getOrderTotal(), $this->module->displayName, null, array(), $currency_id, false, $secure_key);
				Tools::redirect($this->context->link->getModuleLink('payneteasypayment', 'error', ['cart_id'=>$cart->id, 'secure_key'=>$cart->secure_key], true));
			}
			elseif (trim($response["status"]) == 'approved') {
				$this->module->validateOrder($cart_id, $payment_status_approved, $cart->getOrderTotal(), $this->module->displayName, null, array(), $currency_id, false, $secure_key);
				Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart_id . '&id_module=' . $module_id . '&id_order=' . $order_id . '&key=' . $secure_key);
			}
			else {
				echo $response['html'];
				$this->setTemplate('module:payneteasypayment/views/templates/front/3ds.tpl');
			}
		}
	}

	private function signString($s)
		{ return sha1($s); }

	private function signStatusRequest($requestFields, $login, $merchantControl)
		{ return $this->signString($login .$requestFields['client_orderid'] .$requestFields['orderid'] .$merchantControl); }

	private function initClient() {
		$login = Configuration::get('PAYNETEASY_PAYMENT_LOGIN');
		$pass = Configuration::get('PAYNETEASY_PAYMENT_CONTROL_KEY');
		$endpoint = Configuration::get('PAYNETEASY_PAYMENT_END_POINT');
		$integration_method = Configuration::get('PAYNETEASY_PAYMENT_INTEGRATION_METHOD');

		return new Client($login, $pass, $endpoint, $integration_method);
	}

	private function prepareData($cart) {
		$merchantControl = Configuration::get('PAYNETEASY_PAYMENT_CONTROL_KEY');
		$endpointId = Configuration::get('PAYNETEASY_PAYMENT_END_POINT');
		$login = Configuration::get('PAYNETEASY_PAYMENT_LOGIN');

		$paynet_order_id = Db::getInstance()->getValue(
			'SELECT paynet_order_id FROM `'._DB_PREFIX_.'payneteasy_payments` WHERE merchant_order_id = '.$cart->id);

		$data = [
			'login' => $login,
			'client_orderid' => (string)$cart->id,
			'orderid' => $paynet_order_id
		];

		$data['control'] = $this->signStatusRequest($data, $login, $merchantControl);

		return $data;
	}
}
