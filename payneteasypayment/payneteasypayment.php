<?php
/**
 *  @author    Payneteasy
 *  @copyright 2007-2026 Payneteasy
 *  @license   Property of Payneteasy
 */

if (!defined('_PS_VERSION_'))
	exit;

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

include_once('Payneteasy.lib.php');
use Payneteasy\PneApi;
use Payneteasy\PneConfig;
use Payneteasy\PneException;

class Payneteasypayment extends PaymentModule {
	private const REPO = 'payneteasy/php-plugin-prestashop-1';
	private const CFG_NAME = 'PAYNETEASY_CFG';

	private static $_Api, $_Cfg;

	public function __construct() {
		$this->name = 'payneteasypayment';
		$this->tab = 'payments_gateways';
		$this->version = '1.5.0';
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
					&& PneApi::got_upgrade(self::REPO, $this->version, self::Cfg()->VER_CHECK, function($v){ self::Cfg()->VER_CHECK = $v; self::Cfg()->save(); }))
				$this->warning = 'New version is available for download.';
		}
		catch (PneException $E)
			{ $this->warning = $E->getMessage().', check server error log.'; }
	}

	public static function Cfg() {
		if (!isset(self::$_Cfg)) {
			self::$_Cfg = PneConfig::handler(
				fn() => Configuration::get(self::CFG_NAME),
				fn($v) => Configuration::updateValue(self::CFG_NAME, $v),
				fn($k) => Tools::getValue($k),
				fn() => Configuration::deleteByName(self::CFG_NAME),
				[ 'CANCEL_STATE' ], [ 'STATE_WAITING_ID', 'VER_CHECK' ]);
		}

		return self::$_Cfg;
	}

	public static function Api() {
		if (!isset(self::$_Api))
			self::$_Api = new PneApi(self::Cfg());

		return self::$_Api;
	}

	public function install() {
		if (!extension_loaded('curl')) {
			$this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
			return false;
		}

		$OrderState = new OrderState(self::Cfg()->STATE_WAITING_ID);
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

		self::Cfg()->STATE_WAITING_ID = $OrderState->id;

		if (PneApi::is_debug_mode())
			[ self::Cfg()->LIVE_URL, self::Cfg()->SANDBOX_URL ] = [ 'https://gate.payneteasy.com/', 'https://sandbox.payneteasy.com/' ];
		
		self::Cfg()->save();

		copy(_PS_MODULE_DIR_.'payneteasypayment/views/img/status-pending.gif', _PS_IMG_DIR_."os/{$OrderState->id}.gif");

		Db::getInstance()->Execute('
			CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'payneteasy_payments` (
				`serial_number` varchar(255) NOT NULL,
				`paynet_order_id` int(20) unsigned NOT NULL,
				`merchant_order_id` int(20) unsigned,
				PRIMARY KEY (`paynet_order_id`),
				INDEX (`serial_number`),
				INDEX (`merchant_order_id`)
			) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8');

		return parent::install()
			&& $this->registerHook('paymentOptions')
			&& $this->registerHook('actionOrderStatusPostUpdate')
			&& $this->registerHook('displayOrderConfirmation')
			&& $this->registerHook('displayPaymentReturn');
	}

	public function uninstall() {
		$OrderState = new OrderState(self::Cfg()->STATE_WAITING_ID);
		unlink(_PS_IMG_DIR_."os/{$OrderState->id}.gif");
		$OrderState->delete();

		self::Cfg()->uninstall();

		return $this->unregisterHook('paymentOptions')
			&& $this->unregisterHook('actionOrderStatusPostUpdate')
			&& $this->unregisterHook('displayOrderConfirmation')
			&& $this->unregisterHook('displayPaymentReturn')
			&& parent::uninstall();
	}

	public function getContent() {
		$output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

		if (Tools::isSubmit('submitPayneteasypaymentModule')) {
			if (count($errors = self::Cfg()->save_input($has_changes)))
				$output .= $this->displayError(join("<br>", $errors));
			elseif ($has_changes)
				$output .= $this->displayConfirmation($this->l('Settings updated'));
			else
				$output .= $this->displayWarning($this->l('No changes'));
		}
		else
			$output .= '<br />';

		$this->context->controller->addJS($this->_path . 'views/js/admin_settings.js');
		Media::addJsDef([ 'pneAdminSettings' => [ 'row_selector' => '.form-group' ] ]);

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
		$Helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)."&configure={$this->name}&tab_module={$this->tab}&module_name={$this->name}";
		$Helper->token = Tools::getAdminTokenLite('AdminModules');

		$Helper->tpl_vars = [
			'fields_value' => self::Cfg()->form_values(),
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id ];

		return $Helper->generateForm([ $this->getConfigForm() ]);
	}

	public function getConfigForm(): array {
		$orderStates = (new OrderState())->getOrderStates($this->context->language->id);
		$default_order_state = [[ 'id_order_state' => '0', 'name' => $this->l('Select')]];
		$orderStates = array_merge($default_order_state, $orderStates);

		$hidden[self::Cfg()->IS_LIVE ? 'SANDBOX' : 'LIVE'] = $hidden[self::Cfg()->IS_MULTICURR ? 'SINGLE' : 'MULTI'] = ' style="display:none"';
		$endpointid_label = self::Cfg()->IS_MULTICURR ? 'Endpoint Group ID' : 'Endpoint ID';

		$arr = [
			'form' => [
				'legend' => [ 'title' => $this->l('Settings'), 'icon' => 'icon-cogs' ],
				'input' => [
					[ 'type' => 'checkbox',
						'name' => 'IS',
						'label' => $this->l('Live mode'),
						'desc' => sprintf('<span id="pne_is_live_desc_off">%s</span><span id="pne_is_live_desc_on">%s</span>',
							$this->l('Sandbox mode is on'),
							$this->l('Live mode is on')),
						'values' => [ 'id' => 'id', 'name' => 'label',
							'query' => [ [ 'id' => 'LIVE', 'label' => $this->l('Enable live mode') ] ] ] ],
					[ 'type' => 'checkbox',
						'name' => 'IS',
						'label' => $this->l('Multiple currencies'),
						'desc' => sprintf('<span class="pne_is_multi_off">%s</span><span class="pne_is_multi_on">%s</span>',
							$this->l('Single currency mode'),
							$this->l('Multicurrency mode')),
						'values' => [ 'id' => 'id', 'name' => 'label',
							'query' => [ [ 'id' => 'MULTICURR', 'label' => $this->l('Support multiple currencies') ] ] ] ],
					[ 'type' => 'text',
						'name' => 'SANDBOX_URL',
						'label' => $this->l('Gateway URL (SANDBOX)'),
						'desc' => $this->l('Sandbox\'s API URL, e.g. https://sandbox.payneteasy.com/'),
						'placeholder' => $this->l('Enter sandbox url'),
						'required' => true ],
					[ 'type' => 'text',
						'name' => 'SANDBOX_END_POINT',
						'label' => sprintf('<span class="pne_is_multi pne_endpointid_label">%s</span>', $this->l($endpointid_label)),
						'desc' => sprintf('<span class="pne_endpointid_label">%s</span>', $this->l("Sandbox's $endpointid_label is required to call the API")),
						'placeholder' => $this->l('Enter sandbox Endpoint ID or Endpoint Group ID'),
						'required' => true ],
					[ 'type' => 'text',
						'name' => 'SANDBOX_LOGIN',
						'label' => $this->l('Login'),
						'desc' => $this->l('Sandbox\'s Login is required to call the API'),
						'placeholder' => $this->l('Enter sandbox Login'),
						'required' => true ],
					[ 'type' => 'text',
						'name' => 'SANDBOX_CONTROL_KEY',
						'label' => $this->l('Control key'),
						'desc' => $this->l('Sandbox\'s Control key is required to call the API'),
						'placeholder' => $this->l('Enter sandbox Control key'),
						'required' => true ],
					[ 'type' => 'text',
						'name' => 'LIVE_URL',
						'label' => $this->l('Gateway URL (LIVE)'),
						'desc' => $this->l('Merchant\'s API URL, e.g. https://gate.payneteasy.com/'),
						'placeholder' => $this->l('Enter live url'),
						'required' => true ],
					[ 'type' => 'text',
						'name' => 'LIVE_END_POINT',
						'label' => sprintf('<span class="pne_is_multi pne_endpointid_label">%s</span>', $this->l($endpointid_label)),
						'desc' => sprintf('<span class="pne_endpointid_label">%s</span>', $this->l("Merchant's $endpointid_label is required to call the API")),
						'placeholder' => $this->l('Enter live Endpoint ID or Endpoint Group ID'),
						'required' => true ],
					[ 'type' => 'text',
						'name' => 'LIVE_LOGIN',
						'label' => $this->l('Login'),
						'desc' => $this->l('Merchant\'s Login is required to call the API'),
						'placeholder' => $this->l('Enter live Login'),
						'required' => true ],
					[ 'type' => 'text',
						'name' => 'LIVE_CONTROL_KEY',
						'label' => $this->l('Control key'),
						'desc' => $this->l('Merchant\'s Control key is required to call the API'),
						'placeholder' => $this->l('Enter live Control key'),
						'required' => true ],
					[ 'type' => 'checkbox',
						'name' => 'IS',
						'label' => $this->l('Integration method'),
						'desc' => $this->l('Direct or Form'),
						'values' => [ 'id' => 'id', 'name' => 'label',
							'query' => [ [ 'id' => 'FORM', 'label' => $this->l('Form') ] ] ] ],
					[ 'type' => 'select',
						'name' => 'CANCEL_STATE',
						'id' => 'CANCEL_STATE',
						'label' => $this->l('Cancel order state for refund'),
						'desc' => $this->l('Select the order status for automatic refund'),
						'options' => [
							'name' => 'name',
							'id' => 'id_order_state',
							'query' => $orderStates ] ] ],
				'submit' => [ 'title' => $this->l('Save') ]  ] ];

			if (PneApi::is_debug_mode()) {
				array_push($arr['form']['input'],
					[ 'type' => 'switch',
						'name' => 'DEBUG_TRACE',
						'label' => $this->l('DEBUG: Trace requests'),
						'desc' => $this->l('Debug flag: write requests and responses trace to server errorlog'),
						'values' => [
								[ 'id' => 'trace_on', 'value' => PneApi::DEBUG_TRACE_REQUESTS, 'label' => $this->l('On') ],
								[ 'id' => 'trace_off', 'value' => 0, 'label' => $this->l('Off') ] ] ],
					[ 'type' => 'switch',
						'name' => 'DEBUG_FAKE',
						'label' => $this->l('DEBUG: Fake requests'),
						'desc' => $this->l('Debug flag: imitate server requests and responses (countermands responses trace)'),
						'values' => [
								[ 'id' => 'fake_on', 'value' => PneApi::DEBUG_FAKE_REQUESTS, 'label' => $this->l('On') ],
								[ 'id' => 'fake_off', 'value' => 0, 'label' => $this->l('Off') ] ] ]);
			}

			return $arr;
	}

	public function hookPaymentOptions($params) {
		$cart = $params['cart'];

		if (!Validate::isLoadedObject($cart) || !$this->checkCurrency($cart))
			return [];

		if (self::Cfg()->IS_FORM) {
			$Option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
			$Option->setCallToActionText($this->l('Bank card payment (Payneteasy)'))
				->setAction($this->context->link->getModuleLink($this->name, 'redirect', [], true));
			return [$Option];
		}
		else {
			$paymentOptions = [];
			$paymentOptions[] = $this->getEmbeddedPaymentOption();
			return $paymentOptions;
		}
	}

	private function generateEmbeddedForm() {
		[ $cc, $cvv, $year, $mon, $name ] = self::Cfg()->IS_LIVE
			? [ '', '', '', '', '' ]
			: [ 4444_5555_6666_1111, 123, date('Y')+2, 12, 'Test Name' ];

		$this->context->smarty->assign([
			'card_number_value'     => $cc,
			'cvv2_value'            => $cvv,
			'expiry_year_value'     => $year,
			'expiry_month_value'    => $mon,
			'printed_name_value'    => $name,
			'cvv_image'             => Media::getMediaPath(_PS_MODULE_DIR_."{$this->name}/views/img/cvv-image.png")]);

		$this->context->smarty->assign([ 'action' => $this->context->link->getModuleLink($this->name, 'redirect', [ 'option' => 'embedded' ], true) ]);

		return $this->context->smarty->fetch('module:payneteasypayment/views/templates/front/paymentOptionEmbeddedForm.tpl');
	}

	private function getEmbeddedPaymentOption(): PaymentOption {
		$Option = new PaymentOption();
		$Option->setModuleName($this->name);
		$Option->setCallToActionText($this->l('Bank card payment (Payneteasy)'));
		$Option->setForm($this->generateEmbeddedForm());
		$Option->setAdditionalInformation($this->context->smarty->fetch('module:payneteasypayment/views/templates/front/paymentOptionEmbedded.tpl'));

		return $Option;
	}

	private function checkCurrency(Cart $Cart): bool {
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

			if ($OrderState->id == self::Cfg()->CANCEL_STATE)
				$response = self::Api()->return([ 'client_orderid' => $Order->id, 'comment' => $this->l('Order cancel'),
					'orderid' => $Db::getInstance()->getValue('SELECT paynet_order_id FROM `'._DB_PREFIX_."payneteasy_payments` WHERE merchant_order_id={$Order->id}") ]);
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
				'total' => $this->displayPrice($Order->getOrdersTotalPaid(), new Currency($Order->id_currency), false) ]
		);

		return $this->context->smarty->fetch('module:payneteasypayment/views/templates/hook/displayOrderConfirmation.tpl');
	}

	private function displayPrice($price, Currency $Currency) {
		if (method_exists('Tools','displayPrice'))
			return Tools::displayPrice($price, $Currency);

		return $this->context->getCurrentLocale()->formatPrice($price, $Currency->iso_code);
	}
}

?>
