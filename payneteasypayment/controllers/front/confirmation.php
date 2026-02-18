<?php
/**
 *  @author    Payneteasy
 *  @copyright 2007-2026 Payneteasy
 *  @license   Property of Payneteasy
 */

if (!defined('_PS_VERSION_'))
	exit;

include_once(_PS_MODULE_DIR_.'payneteasypayment'.DIRECTORY_SEPARATOR.'payneteasypayment.php');
class PayneteasypaymentConfirmationModuleFrontController extends ModuleFrontController {
	public function initContent() {
		parent::initContent();

		$Order = new Order( Tools::getValue('order_id') );
		$Cart = Cart::getCartByOrderId($Order->id);

		if (!$Cart->secure_key || Tools::getValue('secure_key') != $Cart->secure_key)
			throw new Exception('Invalid secure key');

		$result = Tools::getAllValues();

		if (!isset($result['status']))
			$result = Payneteasypayment::Api()->status([
				'client_orderid' => $Order->id,
				'orderid' => Db::getInstance()->getValue('SELECT paynet_order_id FROM `' ._DB_PREFIX_."payneteasy_payments` WHERE merchant_order_id={$Order->id}") ]);

		if ($result['status'] == 'approved') {
			$Order->setCurrentState( Configuration::get('PS_OS_PAYMENT') );
			Tools::redirect("index.php?controller=order-confirmation&id_cart={$Cart->id}&id_module={$this->module->id}&id_order={$Order->id}&key={$Cart->secure_key}");
		}
		elseif ($result['status'] == 'processing') {
			if (isset($result['html'])) {
				$this->context->smarty->assign([ 'PROCESSINGHTML' => $result['html'] ]);
				$this->setTemplate('module:payneteasypayment/views/templates/front/processing.tpl');
			}
			else
				$this->setTemplate('module:payneteasypayment/views/templates/front/3ds.tpl');
		}
		else { # declined / error / filtered / unknown
			$Order->setCurrentState( Configuration::get('PS_OS_ERROR') );
			Tools::redirect($this->context->link->getModuleLink('payneteasypayment', 'error', [ 'cart_id' => $Cart->id, 'secure_key' => $Cart->secure_key], true));
		}
	}
}

?>
