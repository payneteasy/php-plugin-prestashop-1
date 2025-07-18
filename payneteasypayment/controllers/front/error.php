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
class PayneteasypaymentErrorModuleFrontController extends ModuleFrontController {
	public function initContent() {
		parent::initContent();
		$this->setTemplate('module:payneteasypayment/views/templates/front/error-page.tpl');
	}
}
