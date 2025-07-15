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
 *  @author    R-D <info@rus-design.com> @rus_design
 *  @copyright 2007-2024 Rus-Design
 *  @license   Property of Rus-Design
 */

if (!defined('_PS_VERSION_'))
	exit;

class Client {
	protected $url = 'paynet/api/v2/';
	protected $live_url = '';
	protected $sandbox_url = '';
	protected $login = '';
	protected $password = '';
	protected $endpoint = '';
	protected $integration_method = ''; // direct & form

	public function __construct($login, $password, $endpoint, $integration_method) {
		$this->login = $login;
		$this->password = $password;
		$this->endpoint = $endpoint;
		$this->integration_method = $integration_method;
	}

	public function saleDirect($data, $integration_method, $action_url, $endpoint)
		{ return $this->execute('sale/' . $endpoint, $data, 'POST', $integration_method, $action_url); }

	public function return($data, $integration_method, $action_url, $endpoint)
		{ return $this->execute('return/' . $endpoint, $data, 'POST', $integration_method, $action_url); }

	public function status($data, $integration_method, $action_url, $endpoint)
		{ return $this->execute('status/' . $endpoint, $data, 'POST', $integration_method, $action_url); }

	public function saleForm($data, $integration_method, $action_url, $endpoint)
		{ return $this->execute('sale-form/' . $endpoint, $data, 'POST', $integration_method, $action_url); }

	protected function execute($action, $data, $method, $integration_method, $action_url)
		{ return $this->curlRequestHandler($action, $data, $method, $integration_method, $action_url); }

	protected function curlRequestHandler($action, $data, $method, $integration_method, $action_url) {
		$curl = curl_init($action_url .$this->url .$action);

		curl_setopt_array($curl, array(
			CURLOPT_HEADER         => 0,
			CURLOPT_USERAGENT      => 'Payneteasy-Client/1.0',
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_SSL_VERIFYPEER => 0,
			CURLOPT_POST           => 1,
			CURLOPT_RETURNTRANSFER => 1 ));

		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));

		$response = curl_exec($curl);

		if(curl_errno($curl)) {
			$error_message  = 'Error occurred: ' . curl_error($curl);
			$error_code     = curl_errno($curl);
		}
		elseif(curl_getinfo($curl, CURLINFO_HTTP_CODE) != 200) {
			$error_code     = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			$error_message  = "Error occurred. HTTP code: '{$error_code}'";
		}

		curl_close($curl);

		if (!empty($error_message))
			throw new \PrestaShopException($error_message, $error_code);

		if (empty($response))
			throw new \PrestaShopException('Host response is empty');

		$responseFields = array();

		parse_str($response, $responseFields);

		return $responseFields;
	}

	protected function parseHeadersToArray($rawHeaders) {
		$lines = explode("\r\n", $rawHeaders);
		$headers = [];

		foreach($lines as $line) {
			if (strpos($line, ':') === false )
				continue;
			
			list($key, $value) = explode(': ', $line);
			$headers[$key] = $value;
		}
		return $headers;
	}

	protected function encode($data) {
		if (is_string($data))
			return $data;
		
		$result = json_encode($data);
		$error = json_last_error();

		if ($error != JSON_ERROR_NONE)
			throw new \PrestaShopException('JSON Error: ' . json_last_error_msg());
		
		return $result;
	}

	public static function prepareOrderId($orderId, $forUrl = false) {
		$orderId = str_replace(['/','#','?','|',' '], ['-'], $orderId);

		if ($forUrl)
			$orderId = urlencode($orderId);
		
		return $orderId;
	}
}
