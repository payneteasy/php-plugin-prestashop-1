<?php
/**
 *  @author    Payneteasy
 *  @copyright 2007-2026 Payneteasy
 *  @license   Property of Payneteasy
 */

if (!defined('_PS_VERSION_'))
	exit;

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

define('_PAYNETEASY_LIB_', true);
include_once('lib'.DIRECTORY_SEPARATOR.'Api.php');
use Payneteasy\lib;

class Payneteasypayment extends PaymentModule {
	private const _CFG = 'PAYNETEASY_PAYMENT_';
	private const _CFG_KEYS = [ 'END_POINT','LOGIN','CONTROL_KEY','IS_MULTICURR','LIVE_DOMAIN_CHECKOUT','INTEGRATION_METHOD','SANDBOX_DOMAIN_CHECKOUT','IS_SANDBOX','CANCEL_STATE',
		'GITHUB_VERSION_CHECK','DEBUG_TRACE','DEBUG_FAKE' ];
	private const _CFG_KEYS_HIDDEN = [ 'STATE_WAITING' ];
	private const REPO = 'payneteasy/php-plugin-prestashop-1';

	private static $_Api, $_cfg;

	private array $_postErrors = [];

	public function __construct() {
		$this->name = 'payneteasypayment';
		$this->tab = 'payments_gateways';
		$this->version = '1.4.1';
		$this->author = 'Payneteasy';
		$this->module_key = '';
		$this->ps_versions_compliancy = [ 'min' => '1.7.0.0', 'max' => '9.0.2' ];
		$this->bootstrap = true;

		parent::__construct();

		$this->displayName = $this->l('Payneteasy Payment');
		$this->description = $this->l('Accept card for online payment with Payneteasy');
		$this->confirmUninstall = $this->l('Are you sure you want to uninstall plugin?');

		try {
			if (@Context::getContext()->controller->controller_type == 'admin'
					&& Payneteasy\lib\Api::got_upgrade(self::REPO, $this->version, self::__GITHUB_VERSION_CHECK(), fn($upd) => self::GITHUB_VERSION_CHECK($upd)))
				$this->warning = 'New version is available for download.';
		}
		catch (Payneteasy\lib\ApiException $E)
			{ $this->warning = $E->getMessage().', check server error log.'; }
	}

	# service config access wrap
	# returns config entry expanded (with prefix) name, value, or sets it
	# silently removes prefix (i.e. "PAYNETEASY_PAYMENT_") from name (useful for loops)
	# self::LOGIN() - returns "LOGIN"'s name -> "PAYNETEASY_PAYMENT_LOGIN"
	# self::__LOGIN() - return "LOGIN"'s value
	# self::LOGIN($newval) - sets "LOGIN"'s value
	public static function __callStatic(string $key, array $arg=null) {
		$key = str_replace('__', '', str_replace(self::_CFG, '', $key), $want_value);
		if (!in_array($key, self::_CFG_KEYS) && !in_array($key, self::_CFG_KEYS_HIDDEN))
			throw new Exception("invalid config key; '$key'");

		if (!isset(self::$_cfg)) {
			$cfg = Configuration::getMultiple(array_map(fn($k) => self::_CFG.$k, array_merge(self::_CFG_KEYS, self::_CFG_KEYS_HIDDEN)));

			foreach ($cfg as $k => $v)
				self::$_cfg[ str_replace(self::_CFG, '', $k) ] = $v;
		}

		if (!empty($arg))
			Configuration::updateValue(self::_CFG.$key, self::$_cfg[$key] = $arg[0]);

		return $want_value ? self::$_cfg[$key] : self::_CFG.$key;
	}

	public static function Api() {
		if (!isset(self::$_Api)) {
			self::$_Api = new Payneteasy\lib\Api(
				self::__IS_SANDBOX() ? self::__SANDBOX_DOMAIN_CHECKOUT() : self::__LIVE_DOMAIN_CHECKOUT(),
				self::__LOGIN(),
				self::__CONTROL_KEY(),
				self::__END_POINT(),
				self::__INTEGRATION_METHOD() == 'direct',
				self::__IS_MULTICURR() == 1,
				self::__DEBUG_TRACE() + self::__DEBUG_FAKE());
		}

		return self::$_Api;
	}

	public function install() {
		if (!extension_loaded('curl')) {
			$this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
			return false;
		}

		$OrderState = new OrderState(self::__STATE_WAITING());
		$OrderState->name = [];

		foreach (Language::getLanguages() as $language)
			$OrderState->name[$language['id_lang']] = 'Awaiting for payment';

		$OrderState->send_email = false;
		$OrderState->color = '#4169E1';
		$OrderState->hidden = false;
		$OrderState->module_name = $this->name;
		$OrderState->delivery = false;
		$OrderState->logable = false;
		$OrderState->invoice = false;
		$OrderState->unremovable = false;
		$OrderState->save();

		self::STATE_WAITING($OrderState->id);

		if (Payneteasy\lib\Api::is_debug_mode()) {
			self::LIVE_DOMAIN_CHECKOUT('https://gate.payneteasy.com/');
			self::SANDBOX_DOMAIN_CHECKOUT('https://sandbox.payneteasy.com/');
		}

		self::DEBUG_TRACE(0);
		self::DEBUG_FAKE(0);

		copy(_PS_MODULE_DIR_ .'payneteasypayment/views/img/status-pending.gif', _PS_IMG_DIR_ ."os/{$OrderState->id}.gif");

		Db::getInstance()->Execute('
			CREATE TABLE IF NOT EXISTS `' ._DB_PREFIX_ .'payneteasy_payments` (
				`serial_number` varchar(255) NOT NULL,
				`paynet_order_id` int(20) unsigned NOT NULL,
				`merchant_order_id` int(20) unsigned,
				PRIMARY KEY (`paynet_order_id`),
				INDEX (`serial_number`),
				INDEX (`merchant_order_id`)
			) ENGINE=' ._MYSQL_ENGINE_ .' DEFAULT CHARSET=utf8');

		return parent::install()
			&& $this->registerHook('paymentOptions')
			&& $this->registerHook('actionOrderStatusPostUpdate')
			&& $this->registerHook('displayOrderConfirmation')
			&& $this->registerHook('displayPaymentReturn');
	}

	public function uninstall() {
		$OrderState = new OrderState(self::__STATE_WAITING());
		unlink(_PS_IMG_DIR_ ."os/{$OrderState->id}.gif");
		$OrderState->delete();

		foreach (array_keys(self::$_cfg) as $key)
			Configuration::deleteByName(self::_CFG.$key);

		return $this->unregisterHook('paymentOptions')
			&& $this->unregisterHook('actionOrderStatusPostUpdate')
			&& $this->unregisterHook('displayOrderConfirmation')
			&& $this->unregisterHook('displayPaymentReturn')
			&& parent::uninstall();
	}

	public function getContent() {
		$output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

		if (Tools::isSubmit('submitPayneteasypaymentModule')) {
			$this->_postValidation();

			if (!count($this->_postErrors)) {
				$this->postProcess();
				$output .= $this->displayConfirmation($this->l('Settings updated'));
			}
			else
				foreach ($this->_postErrors as $err)
					$output .= $this->displayError($err);
		}
		else
			$output .= '<br />';

		$this->context->smarty->assign('module_dir', $this->_path);

		return $output.$this->renderForm();
	}

	public function renderForm() {
		$Helper = new HelperForm();

		$Helper->show_toolbar = false;
		$Helper->table = $this->table;
		$Helper->module = $this;
		$Helper->default_form_language = $this->context->language->id;
		$Helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

		$Helper->identifier = $this->identifier;
		$Helper->submit_action = 'submitPayneteasypaymentModule';
		$Helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) ."&configure={$this->name}&tab_module={$this->tab}&module_name={$this->name}";
		$Helper->token = Tools::getAdminTokenLite('AdminModules');

		$Helper->tpl_vars = [
			'fields_value' => $this->getConfigFieldsValues(),
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id ];

		return $Helper->generateForm([ $this->getConfigForm() ]);
	}

	public function getConfigForm(): array {
		$orderStates = (new OrderState())->getOrderStates($this->context->language->id);
		$default_order_state = [[ 'id_order_state' => '0', 'name' => $this->l('Select')]];
		$orderStates = array_merge($default_order_state, $orderStates);

		$arr = [
			'form' => [
				'legend' => [ 'title' => $this->l('Settings'), 'icon' => 'icon-cogs' ],
				'input' => [
					[ 'type' => 'text',
						'label' => $this->l('Gateway url (LIVE)'),
						'name' => self::LIVE_DOMAIN_CHECKOUT(),
						'desc' => $this->l('e.g. https://gate.payneteasy.com/') ],
					[ 'type' => 'text',
						'label' => $this->l('Gateway url (SANDBOX)'),
						'name' => self::SANDBOX_DOMAIN_CHECKOUT(),
						'desc' => $this->l('e.g. https://sandbox.payneteasy.com/') ],
					[ 'type' => 'switch',
						'label' => $this->l('Sandbox mode'),
						'name' => self::IS_SANDBOX(),
						'desc' => $this->l('Test mode ON or OFF'),
						'values' => [
								[ 'id' => 'sandbox_on', 'value' => 1, 'label' => $this->l('Sandbox') ],
								[ 'id' => 'sandbox_off', 'value' => 0, 'label' => $this->l('Live') ] ] ],
					[ 'type' => 'select',
						'label' => $this->l('Integration method'),
						'name' => self::INTEGRATION_METHOD(),
						'desc' => $this->l('Select integration method (Direct or Form)'),
						'id' => self::INTEGRATION_METHOD(),
						'options' => [
							'name' => 'name',
							'id' => 'id',
							'query' => [
								[ 'id' => 'direct', 'name' => $this->l('DIRECT') ],
								[ 'id' => 'form', 'name' => $this->l('FORM') ] ] ] ],
					[ 'type' => 'text',
						'label' => $this->l('End point Id'),
						'name' => self::END_POINT(),
						'desc' => $this->l('End point Id'),
						'required' => true ],
					[ 'type' => 'switch',
						'label' => $this->l('Multiple currencies'),
						'name' => self::IS_MULTICURR(),
						'desc' => $this->l('End point Id supports multiple currencies'),
						'values' => [
								[ 'id' => 'multicurr_on', 'value' => 1, 'label' => $this->l('On') ],
								[ 'id' => 'multicurr_off', 'value' => 0, 'label' => $this->l('Off') ] ] ],
					[ 'type' => 'text',
						'label' => $this->l('Login'),
						'name' => self::LOGIN(),
						'required' => true ],
					[ 'type' => 'text',
						'label' => $this->l('Control Key'),
						'name' => self::CONTROL_KEY(),
						'required' => true ],
					[ 'type' => 'select',
						'label' => $this->l('Cancel order state for refund'),
						'name' => self::CANCEL_STATE(),
						'desc' => $this->l('Select the order status for automatic refund'),
						'id' => self::CANCEL_STATE(),
						'options' => [
							'name' => 'name',
							'id' => 'id_order_state',
							'query' => $orderStates ] ] ],
				'submit' => [ 'title' => $this->l('Save') ]  ] ];

			if (Payneteasy\lib\Api::is_debug_mode()) {
				array_push($arr['form']['input'],
					[ 'type' => 'switch',
						'label' => $this->l('DEBUG: Trace requests'),
						'name' => self::DEBUG_TRACE(),
						'desc' => $this->l('Debug flag: write requests and responses trace to server errorlog'),
						'values' => [
								[ 'id' => 'trace_on', 'value' => Payneteasy\lib\Api::DEBUG_TRACE_REQUESTS, 'label' => $this->l('On') ],
								[ 'id' => 'trace_off', 'value' => 0, 'label' => $this->l('Off') ] ] ],
					[ 'type' => 'switch',
						'label' => $this->l('DEBUG: Fake requests'),
						'name' => self::DEBUG_FAKE(),
						'desc' => $this->l('Debug flag: imitate server requests and responses (countermands responses trace)'),
						'values' => [
								[ 'id' => 'fake_on', 'value' => Payneteasy\lib\Api::DEBUG_FAKE_REQUESTS, 'label' => $this->l('On') ],
								[ 'id' => 'fake_off', 'value' => 0, 'label' => $this->l('Off') ] ] ]);
			}

			return $arr;
	}

	public function getConfigFieldsValues(): array {
		self::LOGIN(); # to force load config
		return array_reduce(array_map(fn($k) => [ self::_CFG.$k => self::$_cfg[$k] ], self::_CFG_KEYS), 'array_merge', []);
	}

	protected function _postValidation() {
		if (Tools::isSubmit('submitPayneteasypaymentModule')) {
			[ $url1, $url2, $login, $ckey, $endpoint ] = array_map(
					function($key){ return Tools::getValue(self::__callStatic($key)); },
					[ 'LIVE_DOMAIN_CHECKOUT','SANDBOX_DOMAIN_CHECKOUT','LOGIN','CONTROL_KEY','END_POINT' ]);
			if ($errors = Payneteasy\lib\Api::check_config_input($url1, $url2, $login, $ckey, $endpoint))
				array_push($this->_postErrors, $errors);
		}
	}

	protected function postProcess() {
		foreach (array_keys($this->getConfigFieldsValues()) as $key)
			self::__callStatic($key, [ Tools::getValue($key) ]);
	}

	public function hookPaymentOptions($params) {
		$cart = $params['cart'];

		if (!Validate::isLoadedObject($cart) || !$this->_checkCurrency($cart))
			return [];

		if (self::__INTEGRATION_METHOD() == 'form') {
			$Option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
			$Option->setCallToActionText($this->l('Bank card payment (Payneteasy)'))
				->setAction($this->context->link->getModuleLink($this->name, 'redirect', [], true));
			return [$Option];
		}
		else {
			$paymentOptions = [];
			$paymentOptions[] = $this->_getEmbeddedPaymentOption();
			return $paymentOptions;
		}
	}

	private function _generateEmbeddedForm() {
		[ $cc, $cvv, $year, $mon, $name ] = self::__IS_SANDBOX()
			? [ 4444_5555_6666_1111, 123, date('Y')+2, 12, 'Test Name' ]
			: [ '', '', '', '', '' ];

		$this->context->smarty->assign([
			'card_number_value'     => $cc,
			'cvv2_value'            => $cvv,
			'expiry_year_value'     => $year,
			'expiry_month_value'    => $mon,
			'printed_name_value'    => $name,
			'cvv_image'             => Media::getMediaPath( _PS_MODULE_DIR_ ."{$this->name}/views/img/cvv-image.png")]);

		$this->context->smarty->assign([ 'action' => $this->context->link->getModuleLink($this->name, 'redirect', [ 'option' => 'embedded' ], true) ]);

		return $this->context->smarty->fetch('module:payneteasypayment/views/templates/front/paymentOptionEmbeddedForm.tpl');
	}

	private function _getEmbeddedPaymentOption(): PaymentOption {
		$Option = new PaymentOption();
		$Option->setModuleName($this->name);
		$Option->setCallToActionText($this->l('Bank card payment (Payneteasy)'));
		$Option->setForm($this->_generateEmbeddedForm());
		$Option->setAdditionalInformation($this->context->smarty->fetch('module:payneteasypayment/views/templates/front/paymentOptionEmbedded.tpl'));

		return $Option;
	}

	private function _checkCurrency(Cart $Cart): bool {
		$Currency = new Currency($Cart->id_currency);
		$currencies_module = $this->getCurrency($Cart->id_currency);

		if (empty($currencies_module))
			return false;

		foreach ($currencies_module as $currency_module)
			if ($Currency->id == $currency_module['id_currency'])
				return true;

		return false;
	}

	public function hookDisplayPaymentReturn(array $params): string {
		if (empty($params['order']))
			return '';

		$Order = $params['order'];

		if (!Validate::isLoadedObject($Order) || $Order->module != $this->name)
			return '';

		$transaction = '';

		if ($Order->getOrderPaymentCollection()->count()) {
			$OrderPayment = $Order->getOrderPaymentCollection()->getFirst();
			$transaction = $OrderPayment->transaction_id; }

		$this->context->smarty->assign([
			'moduleName' => $this->name,
			'transaction' => $transaction,
			'transactionsLink' => $this->context->link->getModuleLink( $this->name, 'account') ]);

		return $this->display(__FILE__, 'views/templates/hook/displayPaymentReturn.tpl');
	}

	public function hookActionOrderStatusPostUpdate($params) {
		$Order = new Order($params['id_order']);

		if ($Order->payment == $this->displayName) {
			$OrderState = $params['newOrderStatus'];

			if ($OrderState->id == self::__CANCEL_STATE())
				$response = self::Api()->return([ 'client_orderid' => $Order->id, 'comment' => $this->l('Order cancel'),
					'orderid' => $Db::getInstance()->getValue('SELECT paynet_order_id FROM `' ._DB_PREFIX_ ."payneteasy_payments` WHERE merchant_order_id={$Order->id}") ]);
		}
	}

	public function hookDisplayOrderConfirmation(array $params) {
		if (!$this->active)
			return false;

		$Order = $params['order'];

		if (strcasecmp($Order->module, 'payneteasypayment'))
			return false;

		if ($Order->getCurrentOrderState()->id != (int)Configuration::get('PS_OS_ERROR'))
			$this->context->smarty->assign('status', 'ok');

		$this->context->smarty->assign(
			[ 'id_order' => $Order->id,
				'params' => $params,
				'total' => $this->_displayPrice($Order->getOrdersTotalPaid(), new Currency($Order->id_currency), false) ]
		);

		return $this->context->smarty->fetch('module:payneteasypayment/views/templates/hook/displayOrderConfirmation.tpl');
	}

	private function _displayPrice($price, Currency $Currency) {
		if (method_exists('Tools','displayPrice'))
			return Tools::displayPrice($price, $Currency);

		return $this->context->getCurrentLocale()->formatPrice($price, $Currency->iso_code);
	}
}

?>
