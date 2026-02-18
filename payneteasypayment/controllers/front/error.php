<?php
/**
 *  @author    Payneteasy
 *  @copyright 2007-2026 Payneteasy
 *  @license   Property of Payneteasy
 */

if (!defined('_PS_VERSION_'))
	exit;

class PayneteasypaymentErrorModuleFrontController extends ModuleFrontController {
	public function initContent() {
		parent::initContent();
		$this->setTemplate('module:payneteasypayment/views/templates/front/error-page.tpl');
	}
}
