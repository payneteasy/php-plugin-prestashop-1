<?php
/**
 *  @author    Payneteasy
 *  @copyright 2007-2026 Payneteasy
 *  @license   Property of Payneteasy
 */

if (!defined('_PS_VERSION_'))
	exit;

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
include_once('lib'.DIRECTORY_SEPARATOR.'Api.php');

use Payneteasy\lib;
class Payneteasypayment extends PaymentModule {
	private const _CFG = 'PAYNETEASY_PAYMENT_';
	private const _CFG_KEYS = [ 'END_POINT','LOGIN','CONTROL_KEY','LIVE_DOMAIN_CHECKOUT','INTEGRATION_METHOD','SANDBOX_DOMAIN_CHECKOUT','TEST_MODE','CANCEL_STATE', 'GITHUB_VERSION_CHECK' ];
	private const _CFG_KEYS_HIDDEN = [ 'STATE_WAITING' ];

	private const GITHUB_REPO = 'payneteasy/php-plugin-prestashop-1';

	private static $_Api, $_cfg;

	public function __construct() {
		$this->name = 'payneteasypayment';
		$this->tab = 'payments_gateways';
		$this->version = '1.3';
		$this->author = 'Payneteasy';
		$this->module_key = '';
		$this->_postErrors = [];
		$this->ps_versions_compliancy = [ 'min' => '1.7.0.0', 'max' => '9.0.2' ];
		$this->bootstrap = true;

		parent::__construct();

		$this->displayName = $this->l('Payneteasy Payment');
		$this->description = $this->l('Accept card for online payment with Payneteasy');
		$this->confirmUninstall = $this->l('Are you sure you want to uninstall plugin?');

		if (!is_null(Context::getContext()->controller) && Context::getContext()->controller->controller_type == 'admin') {
			if (!self::Api()->is_configured())
				$this->warning = 'Configuring is required. ';

			if (($stored = self::__GITHUB_VERSION_CHECK()) != ($current = $this->version.' '.($current_date = date('Y-m-d')))) {
				[ $stored_ver, $stored_date ] = explode(' ', $stored ?: '0 0');

				if ($stored_date != $current_date) { # fetch & store once per day
					try {
						$stored_ver = self::Api()->fetch_github_version(self::GITHUB_REPO);
						self::GITHUB_VERSION_CHECK("$stored_ver $current_date");
					}
					catch (Exception $E) {
						$this->warning .= $E->getMessage().', check server error log. ';
						$stored_ver = $this->version;
					}
				}

				if (strcmp($stored_ver, $this->version))
					$this->warning .= 'New version is available for download';
			}
		}
	}

	# service config access wrap
	# returns config entry expanded (i.e. with prefix) name, value, or sets it
	# silently removes prefix from name (useful for loops)
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

	private static function _load_config(): void
		{ self::LOGIN(); }

	public static function Api() {
		if (!isset(self::$_Api)) {
			self::$_Api = new Payneteasy\lib\Api(
				self::__TEST_MODE() ? self::__SANDBOX_DOMAIN_CHECKOUT() : self::__LIVE_DOMAIN_CHECKOUT(),
				self::__LOGIN(),
				self::__CONTROL_KEY(),
				self::__END_POINT(),
				self::__INTEGRATION_METHOD() == 'direct');
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
		self::LIVE_DOMAIN_CHECKOUT('https://gate.payneteasy.com/');
		self::SANDBOX_DOMAIN_CHECKOUT('https://sandbox.payneteasy.com/');

		copy(_PS_MODULE_DIR_ .'payneteasypayment/views/img/logo.gif', _PS_IMG_DIR_ ."os/{$OrderState->id}.gif");

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

		return [
			'form' => [
				'legend' => [ 'title' => $this->l('Settings'), 'icon' => 'icon-cogs' ],
				'input' => [
					[ 'type' => 'text',
						'label' => $this->l('Gateway url (LIVE)'),
						'name' => self::LIVE_DOMAIN_CHECKOUT(),
						'desc' => $this->l('https://gate.payneteasy.com/ etc.') ],
					[ 'type' => 'text',
						'label' => $this->l('Gateway url (SANDBOX)'),
						'name' => self::SANDBOX_DOMAIN_CHECKOUT(),
						'desc' => $this->l('https://sandbox.payneteasy.com/ etc.') ],
					[ 'type' => 'switch',
						'label' => $this->l('Sandbox mode'),
						'name' => self::TEST_MODE(),
						'desc' => $this->l('Test mode ON or OFF'),
						'values' => [
								[ 'id' => 'test_on', 'value' => 1, 'label' => $this->l('Test') ],
								[ 'id' => 'test_off', 'value' => 0, 'label' => $this->l('Live') ] ] ],
					[ 'type' => 'select',
						'label' => $this->l('Integration method'),
						'name' => self::INTEGRATION_METHOD(),
						'desc' => $this->l('Select integration method (Direct or Form)'),
						'id' => self::INTEGRATION_METHOD(),
						'options' => [
							'name' => 'name',
							'id' => 'id',
							'query' => [
								['id' => 'direct', 'name' => $this->l('DIRECT') ],
								['id' => 'form', 'name' => $this->l('FORM') ] ] ] ],
					[ 'type' => 'text',
						'label' => $this->l('End point ID'),
						'name' => self::END_POINT(),
						'desc' => $this->l('End point ID (for single currency integration)'),
						'required' => true ],
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
	}

	public function getConfigFieldsValues(): array {
		self::_load_config();
		return array_reduce(array_map(fn($k) => [ self::_CFG.$k => self::$_cfg[$k] ], self::_CFG_KEYS), 'array_merge', []);
	}

	protected function _postValidation() { # TODO regexps
		if (Tools::isSubmit('submitPayneteasypaymentModule')) {
			[$url1, $url2, $login, $ckey, $endpoint ] = array_map(
					function($key){ return Tools::getValue(self::__callStatic($key)); },
					[ 'LIVE_DOMAIN_CHECKOUT','SANDBOX_DOMAIN_CHECKOUT','LOGIN','CONTROL_KEY','END_POINT' ]);
			if ($errors = self::Api()->check_config_input($url1, $url2, $login, $ckey, $endpoint))
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
		[ $card_number_value, $printed_name_value, $expiry_month_value, $expiry_year_value, $cvv2_value ]
			= self::__TEST_MODE()
				? [4444555566661111, 'Test Name', 12, date('Y')+2, 123]
				: ['', '', '', '', ''];

		$this->context->smarty->assign([
			'card_number_value'     => $card_number_value,
			'printed_name_value'    => $printed_name_value,
			'expiry_month_value'    => $expiry_month_value,
			'expiry_year_value'     => $expiry_year_value,
			'cvv2_value'            => $cvv2_value,
			'cvv_image'             => Media::getMediaPath( _PS_MODULE_DIR_ ."{$this->name}/views/img/cvv-caption_new.png")]);

		$this->context->smarty->assign([ 'action' => $this->context->link->getModuleLink($this->name, 'redirect', ['option' => 'embedded'], true) ]);

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
