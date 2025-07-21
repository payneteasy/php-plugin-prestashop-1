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

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
include_once(_PS_MODULE_DIR_.'payneteasypayment'.DIRECTORY_SEPARATOR.'classes'.DIRECTORY_SEPARATOR.'Client.php');
class Payneteasypayment extends PaymentModule {
	public function __construct() {
		$this->name = 'payneteasypayment';
		$this->tab = 'payments_gateways';
		$this->version = '1.0.0';
		$this->author = 'R-D';
		$this->need_instance = 0;
		$this->bootstrap = true;
		$this->module_key = '';
		$this->_postErrors = array();

		parent::__construct();
        
		$this->displayName = $this->l('Payneteasy Payment');
		$this->description = $this->l('Accept online payments with Payneteasy');
		$this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);
	}
    
	public function install() {
		if (extension_loaded('curl') == false) {
			$this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
			return false;
		}

		$ow_status = Configuration::get('PAYNETEASY_PAYMENT_STATE_WAITING');
		$orderState = $ow_status === false
			? new OrderState()
			: new OrderState((int)$ow_status);
        
		$orderState->name = array();
        
		foreach (Language::getLanguages() as $language) {
			if (Tools::strtolower($language['iso_code']) == 'ru')
				$orderState->name[$language['id_lang']] = 'Ожидание завершения оплаты (Payneteasy)';
			else
				$orderState->name[$language['id_lang']] = 'Awaiting for payment (Payneteasy)';
		}
        
		$orderState->send_email = false;
		$orderState->color = '#4169E1';
		$orderState->hidden = false;
		$orderState->module_name = $this->name;
		$orderState->delivery = false;
		$orderState->logable = false;
		$orderState->invoice = false;
		$orderState->unremovable = false;
		$orderState->save();
        
		Configuration::updateValue('PAYNETEASY_PAYMENT_STATE_WAITING', (int)$orderState->id);
		Configuration::updateValue('PAYNETEASY_PAYMENT_LIVE_DOMAIN_CHECKOUT', 'https://gate.payneteasy.com/');
		Configuration::updateValue('PAYNETEASY_PAYMENT_SANDBOX_DOMAIN_CHECKOUT', 'https://sandbox.payneteasy.com/');
		Configuration::updateValue('PAYNETEASY_PAYMENT_ACTIVE_MODE', true);

		copy(_PS_MODULE_DIR_ . 'payneteasypayment/views/img/logo.gif', _PS_IMG_DIR_ . 'os/' . (int)$orderState->id . '.gif');

		Db::getInstance()->Execute('
			CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'payneteasy_payments` (
				`serial_number` text NOT NULL,
				`paynet_order_id` int(20) unsigned NOT NULL,
				`merchant_order_id` int(20) unsigned,
				PRIMARY KEY (`paynet_order_id`),
				INDEX (`serial_number`),
				INDEX (`merchant_order_id`)
			) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8');
        
		return parent::install()
			&& $this->registerHook('actionOrderStatusPostUpdate')
			&& $this->registerHook('paymentOptions')
			&& $this->registerHook('displayOrderConfirmation')
			&& $this->registerHook('displayPaymentReturn');
	}
    
	public function uninstall() {
		Configuration::deleteByName('PAYNETEASY_PAYMENT_ACTIVE_MODE');
		Configuration::deleteByName('PAYNETEASY_PAYMENT_END_POINT');
		Configuration::deleteByName('PAYNETEASY_PAYMENT_CANCEL_STATE');
		Configuration::deleteByName('PAYNETEASY_PAYMENT_LOGIN');
		Configuration::deleteByName('PAYNETEASY_PAYMENT_SHOP_PASS');
		Configuration::deleteByName('PAYNETEASY_PAYMENT_LIVE_DOMAIN_CHECKOUT');
		Configuration::deleteByName('PAYNETEASY_PAYMENT_INTEGRATION_METHOD');
		Configuration::deleteByName('PAYNETEASY_PAYMENT_SANDBOX_DOMAIN_CHECKOUT');
		Configuration::deleteByName('PAYNETEASY_PAYMENT_TEST_MODE');
		Configuration::deleteByName('PAYNETEASY_PAYMENT_STATE_WAITING');

		$orderStateId = Configuration::get('PAYNETEASY_PAYMENT_STATE_WAITING');

		if ($orderStateId) {
			$orderState = new OrderState();
			$orderState->id = $orderStateId;
			$orderState->delete();
			unlink(_PS_IMG_DIR_ . 'os/' . (int)$orderState->id . '.gif');
		}

		return $this->unregisterHook('paymentOptions')
			&& $this->unregisterHook('displayPaymentReturn')
			&& $this->unregisterHook('actionOrderStatusPostUpdate')
			&& parent::uninstall();
	}
    
	public function isSandboxMode()
		{ return Configuration::get('PAYNETEASY_PAYMENT_TEST_MODE'); }

	public function getContent() {
		$output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

		if (((bool)Tools::isSubmit('submitPayneteasypaymentModule')) == true) {
			$this->_postValidation();

			if (!count($this->_postErrors)) {
				$this->postProcess();
				$output .= $this->displayConfirmation($this->l('Settings updated'));
			}
			else {
				foreach ($this->_postErrors as $err)
					$output .= $this->displayError($err);
			}
		}
		else
			$output .= '<br />';

		$this->context->smarty->assign('module_dir', $this->_path);

		return $output.$this->renderForm();
	}

	public function renderForm() {
		$helper = new HelperForm();

		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$helper->module = $this;
		$helper->default_form_language = $this->context->language->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

		$helper->identifier = $this->identifier;
		$helper->submit_action = 'submitPayneteasypaymentModule';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
			. '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');

		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFieldsValues(),
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id
		);

		return $helper->generateForm(array($this->getConfigForm()));
	}

	public function getConfigForm() {
		$orderState = new OrderState();
		$orderStates = $orderState->getOrderStates($this->context->language->id);
		$default_order_state = array([
			'id_order_state' => '0',
			'name' => $this->l('Select'),
		]);
		$orderStates = array_merge($default_order_state,$orderStates);

		return array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('Settings'),
					'icon' => 'icon-cogs'),
				'input' => array(
					array(
						'type' => 'switch',
						'label' => $this->l('Active'),
						'name' => 'PAYNETEASY_PAYMENT_ACTIVE_MODE',
						'is_bool' => true,
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => 1,
								'label' => $this->l('Enabled')),
							array(
								'id' => 'active_off',
								'value' => 0,
								'label' => $this->l('Disabled'))
						),
					),
					array(
						'type' => 'text',
						'label' => $this->l('Gateway url (LIVE)'),
						'name' => 'PAYNETEASY_PAYMENT_LIVE_DOMAIN_CHECKOUT',
						'desc' => $this->l('https://gate.payneteasy.com/ etc.')),
					array(
						'type' => 'text',
						'label' => $this->l('Gateway url (SANDBOX)'),
						'name' => 'PAYNETEASY_PAYMENT_SANDBOX_DOMAIN_CHECKOUT',
						'desc' => $this->l('https://sandbox.payneteasy.com/ etc.')),
					array(
						'type' => 'switch',
						'label' => $this->l('Sandbox mode'),
						'name' => 'PAYNETEASY_PAYMENT_TEST_MODE',
						'desc' => $this->l('Test mode ON or OFF'),
						'values' => array(
								array(
									'id' => 'test_on',
									'value' => 1,
									'label' => $this->l('Test')),
								array(
									'id' => 'test_off',
									'value' => 0,
									'label' => $this->l('Live'))
						)
					),
					array(
						'type' => 'select',
						'label' => $this->l('Integration method'),
						'name' => 'PAYNETEASY_PAYMENT_INTEGRATION_METHOD',
						'desc' => $this->l('Select integration method (Direct or Form)'),
						'id' => 'PAYNETEASY_PAYMENT_INTEGRATION_METHOD',
						'options' => array(
							'query' => array(
								array('id' => 'direct', 'name' => $this->l('DIRECT')),
								array('id' => 'form', 'name' => $this->l('FORM'))),
							'name' => 'name',
							'id' => 'id'
						)
					),
					array(
						'type' => 'text',
						'label' => $this->l('End point ID'),
						'name' => 'PAYNETEASY_PAYMENT_END_POINT',
						'desc' => $this->l('End point ID (for single currency integration)'),
						'required' => true),
					array(
						'type' => 'text',
						'label' => $this->l('Login'),
						'name' => 'PAYNETEASY_PAYMENT_LOGIN',
						'required' => true),
					array(
						'type' => 'text',
						'label' => $this->l('Control Key'),
						'name' => 'PAYNETEASY_PAYMENT_CONTROL_KEY',
						'required' => true,),
					array(
						'type' => 'select',
						'label' => $this->l('Cancel order state for refund'),
						'name' => 'PAYNETEASY_PAYMENT_CANCEL_STATE',
						'desc' => $this->l('Select the order status for automatic refund'),
						'id' => 'PAYNETEASY_PAYMENT_CANCEL_STATE',
						'options' => array(
						'query' => $orderStates,
								'name' => 'name',
								'id' => 'id_order_state')
					),
				),
				'submit' => array(
					'title' => $this->l('Save')
				)
			)
		);
	}

	public function getConfigFieldsValues() {
		return array(
			'PAYNETEASY_PAYMENT_ACTIVE_MODE' => Configuration::get('PAYNETEASY_PAYMENT_ACTIVE_MODE'),
			'PAYNETEASY_PAYMENT_END_POINT' => Configuration::get('PAYNETEASY_PAYMENT_END_POINT'),
			'PAYNETEASY_PAYMENT_END_POINT_GROUP' => Configuration::get('PAYNETEASY_PAYMENT_END_POINT_GROUP'),
			'PAYNETEASY_PAYMENT_CANCEL_STATE' => Configuration::get('PAYNETEASY_PAYMENT_CANCEL_STATE'),
			'PAYNETEASY_PAYMENT_LOGIN' => Configuration::get('PAYNETEASY_PAYMENT_LOGIN'),
			'PAYNETEASY_PAYMENT_CONTROL_KEY' => Configuration::get('PAYNETEASY_PAYMENT_CONTROL_KEY'),
			'PAYNETEASY_PAYMENT_LIVE_DOMAIN_CHECKOUT' => Configuration::get('PAYNETEASY_PAYMENT_LIVE_DOMAIN_CHECKOUT'),
			'PAYNETEASY_PAYMENT_INTEGRATION_METHOD' => Configuration::get('PAYNETEASY_PAYMENT_INTEGRATION_METHOD'),
			'PAYNETEASY_PAYMENT_SANDBOX_DOMAIN_CHECKOUT' => Configuration::get('PAYNETEASY_PAYMENT_SANDBOX_DOMAIN_CHECKOUT'),
			'PAYNETEASY_PAYMENT_TEST_MODE' => Configuration::get('PAYNETEASY_PAYMENT_TEST_MODE'),
		);
	}

	protected function _postValidation() {
		if (Tools::isSubmit('submitPayneteasypaymentModule')) {
			if (!Tools::getValue('PAYNETEASY_PAYMENT_LOGIN'))
				$this->_postErrors[] = $this->l('Login incorrect');
			elseif (!Tools::getValue('PAYNETEASY_PAYMENT_CONTROL_KEY'))
				$this->_postErrors[] = $this->l('Control key incorrect');
			elseif (!Tools::getValue('PAYNETEASY_PAYMENT_END_POINT'))
				$this->_postErrors[] = $this->l('End point incorrect');
			
		}
	}

	protected function postProcess() {
		$form_values = $this->getConfigFieldsValues();
		foreach (array_keys($form_values) as $key)
			Configuration::updateValue($key, Tools::getValue($key));
	}

	public function hookPaymentOptions($params) {
		$cart = $params['cart'];

		if (false === Validate::isLoadedObject($cart) || false === $this->checkCurrency($cart))
			return [];

		if (Configuration::get('PAYNETEASY_PAYMENT_INTEGRATION_METHOD') == 'form') {
			$option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
			$option->setCallToActionText($this->l('Bank card payment (Payneteasy)'))
				->setAction($this->context->link->getModuleLink($this->name, 'redirect', array(), true));
			return [$option];
		}
		else {
			$paymentOptions = [];
			$paymentOptions[] = $this->getEmbeddedPaymentOption();
			return $paymentOptions;
		}
	}

	private function generateEmbeddedForm() {
		$card_number_value          = '';
		$printed_name_value         = '';
		$expiry_month_value         = '';
		$expiry_year_value          = '';
		$cvv2_value                 = '';

		if($this->isSandboxMode()) {
			$card_number_value = 4444555566661111;
			$printed_name_value = 'Test Name';
			$expiry_month_value = 12;
			$current_year = (int)date('y');
			$expiry_year_value = 2030;
			$cvv2_value = 123; # no 3D value
		}

		$this->context->smarty->assign([
			'card_number_value'     => $card_number_value,
			'printed_name_value'    => $printed_name_value,
			'expiry_month_value'    => $expiry_month_value,
			'expiry_year_value'     => $expiry_year_value,
			'cvv2_value'            => $cvv2_value,
			'cvv_image'             => Media::getMediaPath( _PS_MODULE_DIR_ . $this->name . '/views/img/cvv-caption_new.png')]);

		$this->context->smarty->assign([
			'action' => $this->context->link->getModuleLink($this->name, 'redirect', ['option' => 'embedded'], true) ]);

		return $this->context->smarty->fetch('module:payneteasypayment/views/templates/front/paymentOptionEmbeddedForm.tpl');
	}

	private function getEmbeddedPaymentOption() {
		$embeddedOption = new PaymentOption();
		$embeddedOption->setModuleName($this->name);
		$embeddedOption->setCallToActionText($this->l('Bank card payment (Payneteasy)'));
		$embeddedOption->setForm($this->generateEmbeddedForm());
		$embeddedOption->setAdditionalInformation($this->context->smarty->fetch('module:payneteasypayment/views/templates/front/paymentOptionEmbedded.tpl'));

		return $embeddedOption;
	}

	private function checkCurrency(Cart $cart) {
		$currency_order = new Currency($cart->id_currency);
		/** @var array $currencies_module */
		$currencies_module = $this->getCurrency($cart->id_currency);

		if (empty($currencies_module))
			return false;

		foreach ($currencies_module as $currency_module) {
			if ($currency_order->id == $currency_module['id_currency'])
				return true;
		}

		return false;
	}

	public function hookDisplayPaymentReturn($params) {
		if (empty($params['order']))
			return '';

		/** @var Order $order */
		$order = $params['order'];

		if (false === Validate::isLoadedObject($order) || $order->module !== $this->name)
			return '';
		
		$transaction = '';

		if ($order->getOrderPaymentCollection()->count()) {
			/** @var OrderPayment $orderPayment */
			$orderPayment = $order->getOrderPaymentCollection()->getFirst();
			$transaction = $orderPayment->transaction_id;
		}

		$this->context->smarty->assign([
			'moduleName' => $this->name,
			'transaction' => $transaction,
			'transactionsLink' => $this->context->link->getModuleLink(
				$this->name,
				'account'),
		]);

		return $this->display(__FILE__, 'views/templates/hook/displayPaymentReturn.tpl');
	}

	public function hookActionOrderStatusPostUpdate($params) {
		$order = new Order($params['id_order']);
		$integration_method = Configuration::get('PAYNETEASY_PAYMENT_INTEGRATION_METHOD');
		$login = Configuration::get('PAYNETEASY_PAYMENT_LOGIN');
		$merchantControl = Configuration::get('PAYNETEASY_PAYMENT_CONTROL_KEY');
		$endpointId = Configuration::get('PAYNETEASY_PAYMENT_END_POINT');

		$action_url = Configuration::get('PAYNETEASY_PAYMENT_TEST_MODE')
			? Configuration::get('PAYNETEASY_PAYMENT_SANDBOX_DOMAIN_CHECKOUT')
			: Configuration::get('PAYNETEASY_PAYMENT_LIVE_DOMAIN_CHECKOUT');

		if ($order->payment == $this->displayName) {
			$order_state = $params['newOrderStatus'];
			$cancel_order_state = Configuration::get('PAYNETEASY_PAYMENT_CANCEL_STATE');

			if ($order_state->id == $cancel_order_state) {
				$client = $this->initClient();

				$paynet_order_id = Db::getInstance()->getValue(
					'SELECT paynet_order_id FROM `' . _DB_PREFIX_ . 'payneteasy_payments` WHERE merchant_order_id = ' . (int)$params['cart']->id);

				$data = [
					'login' => $login,
					'client_orderid' => $params['cart']->id,
					'orderid' => $paynet_order_id,
					'comment' => $this->l('Order cancel ')
				];

				$data['control'] = $this->signPaymentRequest($data, $endpointId, $merchantControl);

				$response = $client->return($data, $integration_method, $action_url, $endpointId);

			}
		}
	}

	private function initClient() {
		$login = Configuration::get('PAYNETEASY_PAYMENT_LOGIN');
		$pass = Configuration::get('PAYNETEASY_PAYMENT_CONTROL_KEY');
		$endpoint = Configuration::get('PAYNETEASY_PAYMENT_END_POINT');
		$integration_method = Configuration::get('PAYNETEASY_PAYMENT_INTEGRATION_METHOD');

		return new Client($login, $pass, $endpoint, $integration_method);
	}

	private function signString($s)
		{ return sha1($s); }

	private function signPaymentRequest($data, $endpointId, $merchantControl)
		{ return $this->signString($endpointId .$data['client_orderid'] .(string)($data['amount'] * 100) . $data['email'] .$merchantControl); }

	public function hookDisplayOrderConfirmation(array $params) {
		if ($this->active == false)
			return false;

		$order = $params['order'];
		$currency = new Currency($order->id_currency);

		if (strcasecmp($order->module, 'payneteasypayment') != 0)
			return false;

		if ($order->getCurrentOrderState()->id != (int)Configuration::get('PS_OS_ERROR'))
			$this->context->smarty->assign('status', 'ok');

		$this->context->smarty->assign(
			array(
				'id_order' => $order->id,
				'params' => $params,
				'total' => $this->displayPrice($order->getOrdersTotalPaid(), $currency, false))
		);

		return $this->context->smarty->fetch('module:payneteasypayment/views/templates/hook/displayOrderConfirmation.tpl');
	}

	private function displayPrice($price, $currency) {
		if (method_exists('Tools','displayPrice'))
			return Tools::displayPrice($price, $currency);

		return $this->context->getCurrentLocale()->formatPrice($price, $currency->iso_code);
	}
}
