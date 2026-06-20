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

class Payneteasypayment extends PaymentModule {
	private const REPO = 'payneteasy/php-plugin-prestashop-1';

	private static $_Api, $_Cfg;

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
					&& PneApi::got_upgrade(self::REPO, $this->version, self::Cfg()->VER_CHECK, fn($v) => self::Cfg()->VER_CHECK_save = $v))
				$this->warning = 'New version is available for download.';
		}
		catch (Payneteasy\PneException $E)
			{ $this->warning = $E->getMessage().', check server error log.'; }
	}

	public static function Cfg() {
		if (!isset(self::$_Cfg))
			self::$_Cfg = new Payneteasy\PneConfig(
				fn($n) => Configuration::get($n),
				fn($n, $v) => Configuration::updateValue($n, $v),
				fn($n) => Configuration::deleteByName($n),
				[ 'CANCEL_STATE' ], [ 'STATE_WAITING', 'VER_CHECK' ]);

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

		$OrderState = new OrderState(self::Cfg()->STATE_WAITING);
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

		self::Cfg()->STATE_WAITING_save = $OrderState->id;

		if (PneApi::is_debug_mode())
			[ self::Cfg()->LIVE_URL_save, self::Cfg()->SANDBOX_URL_save ] = [ 'https://gate.payneteasy.com/', 'https://sandbox.payneteasy.com/' ];

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
		$OrderState = new OrderState(self::Cfg()->STATE_WAITING);
		unlink(_PS_IMG_DIR_ ."os/{$OrderState->id}.gif");
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
			if (count($errors = self::Cfg()->form_save(fn($k) => Tools::getValue($k), $has_changes)))
				$output .= $this->displayError(join("<br>", $errors));
			elseif ($has_changes)
				$output .= $this->displayConfirmation($this->l('Settings updated'));
			else
				$output .= $this->displayWarning($this->l('No changes'));
		}
		else
			$output .= '<br />';

		$this->context->smarty->assign('module_dir', $this->_path);

		return $output .$this->renderForm();
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
			'fields_value' => self::Cfg()->form_values(),
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id ];

		return $Helper->generateForm([ $this->getConfigForm() ]);
	}

	public function getConfigForm(): array {
		$orderStates = (new OrderState())->getOrderStates($this->context->language->id);
		$default_order_state = [[ 'id_order_state' => '0', 'name' => $this->l('Select')]];
		$orderStates = array_merge($default_order_state, $orderStates);

		$Cfg = self::Cfg();
		$arr = [
			'form' => [
				'legend' => [ 'title' => $this->l('Settings'), 'icon' => 'icon-cogs' ],
				'input' => [
					[ 'type' => 'text',
						'label' => $this->l('Gateway URL (LIVE)'),
						'name' => $Cfg->form_field_name('LIVE_URL'),
						'desc' => $this->l('e.g. https://gate.payneteasy.com/') ],
					[ 'type' => 'text',
						'label' => $this->l('Gateway URL (SANDBOX)'),
						'name' => $Cfg->form_field_name('SANDBOX_URL'),
						'desc' => $this->l('e.g. https://sandbox.payneteasy.com/') ],
					[ 'type' => 'switch',
						'label' => $this->l('Sandbox mode'),
						'name' => $Cfg->form_field_name('IS_SANDBOX'),
						'desc' => $this->l('Test mode ON or OFF'),
						'values' => [
								[ 'id' => 'sandbox_on', 'value' => 1, 'label' => $this->l('Sandbox') ],
								[ 'id' => 'sandbox_off', 'value' => 0, 'label' => $this->l('Live') ] ] ],
					[ 'type' => 'select',
						'label' => $this->l('Integration method'),
						'name' => $Cfg->form_field_name('INTEGRATION'),
						'desc' => $this->l('Select integration method (Direct or Form)'),
						'id' => $Cfg->form_field_name('INTEGRATION'),
						'options' => [
							'name' => 'name',
							'id' => 'id',
							'query' => [
								[ 'id' => 'direct', 'name' => $this->l('DIRECT') ],
								[ 'id' => 'form', 'name' => $this->l('FORM') ] ] ] ],
					[ 'type' => 'text',
						'label' => $this->l('End point Id'),
						'name' => $Cfg->form_field_name('END_POINT'),
						'desc' => $this->l('End point Id'),
						'required' => true ],
					[ 'type' => 'switch',
						'label' => $this->l('Multiple currencies'),
						'name' => $Cfg->form_field_name('IS_MULTICURR'),
						'desc' => $this->l('End point Id supports multiple currencies'),
						'values' => [
								[ 'id' => 'multicurr_on', 'value' => 1, 'label' => $this->l('On') ],
								[ 'id' => 'multicurr_off', 'value' => 0, 'label' => $this->l('Off') ] ] ],
					[ 'type' => 'text',
						'label' => $this->l('Login'),
						'name' => $Cfg->form_field_name('LOGIN'),
						'required' => true ],
					[ 'type' => 'text',
						'label' => $this->l('Control key'),
						'name' => $Cfg->form_field_name('CONTROL_KEY'),
						'required' => true ],
					[ 'type' => 'select',
						'label' => $this->l('Cancel order state for refund'),
						'name' => $Cfg->form_field_name('CANCEL_STATE'),
						'desc' => $this->l('Select the order status for automatic refund'),
						'id' => $Cfg->form_field_name('CANCEL_STATE'),
						'options' => [
							'name' => 'name',
							'id' => 'id_order_state',
							'query' => $orderStates ] ] ],
				'submit' => [ 'title' => $this->l('Save') ]  ] ];

			if (PneApi::is_debug_mode()) {
				array_push($arr['form']['input'],
					[ 'type' => 'switch',
						'label' => $this->l('DEBUG: Trace requests'),
						'name' => $Cfg->form_field_name('DEBUG_TRACE'),
						'desc' => $this->l('Debug flag: write requests and responses trace to server errorlog'),
						'values' => [
								[ 'id' => 'trace_on', 'value' => PneApi::DEBUG_TRACE_REQUESTS, 'label' => $this->l('On') ],
								[ 'id' => 'trace_off', 'value' => 0, 'label' => $this->l('Off') ] ] ],
					[ 'type' => 'switch',
						'label' => $this->l('DEBUG: Fake requests'),
						'name' => $Cfg->form_field_name('DEBUG_FAKE'),
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

		if (self::Cfg()->INTEGRATION == 'form') {
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
		[ $cc, $cvv, $year, $mon, $name ] = self::Cfg()->IS_SANDBOX
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
