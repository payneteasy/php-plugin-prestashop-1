<?php

namespace Payneteasy\lib;

if (!defined('_PS_VERSION_'))
  exit;

include_once('ApiException.php');

class Api {
	private const URL = 'paynet/api/v2/';

	private string $login;
	private string $gate;
	private string $control_key;
	private string $endpoint;
	private bool $is_direct;

	public function __construct(string $gate, string $login, string $control_key, string $endpoint, bool $is_direct) {
		$this->gate = $gate;
		$this->login = $login;
		$this->control_key = $control_key;
		$this->endpoint = $endpoint;
		$this->is_direct = $is_direct;
	}

	public static function check_config_input(string $gate, string $sandbox, string $login, string $control_key, string $endpoint): array {
		return array_reduce([
			[ 'Gateway URL', $gate, '|^https?://(?:\\w+(?:-\\w+)*\\.)+(?:\\w+(?:-\\w+)*)/|' ],
			[ 'Sandbox URL', $sandbox, '|^https?://(?:\\w+(?:-\\w+)*\\.)+\\w+/|' ],
			[ 'Login', $login, '/^[a-z][\\w-]*\\w$/i' ],
			[ 'Control key', $control_key, '/^[\da-f]{8}(?:-[\da-f]{4}){3}-[\da-f]{12}$/i' ],
			[ 'End point Id', $endpoint, '/^\d+$/' ]],
			function($iter, $entry){ if (!preg_match($entry[2], $entry[1])) $iter[] = "{$entry[0]} is invalid"; return $iter; },
			[]);
	}

	private function signed(array $data, string $str=null, bool $add_login=false): array {
		if (isset($str) || $add_login)
			$data['login'] = $this->login;

		$data['control'] = sha1($str ?? $this->endpoint.$data['client_orderid'].($data['amount'] * 100).$data['email'].$this->control_key);
		return $data;
	}

	public function is_direct(): bool
		{ return $this->is_direct; }

	public function sale(array $data): array
		{ return $this->execute($this->is_direct ? 'sale' : 'sale-form', $this->signed($data)); }

	public function return(array $data): array
		{ return $this->execute('return', $this->signed($data, null, true)); }

	public function status(array $data): array
		{ return $this->execute('status', $this->signed($data, $this->login.$data['client_orderid'].$data['orderid'].$this->control_key)); }

	private function execute(string $action, array $data): array {
		$curl = curl_init($this->gate . self::URL . "$action/{$this->endpoint}");

		curl_setopt_array($curl, [
			CURLOPT_HEADER					=> 0,
			CURLOPT_USERAGENT				=> 'Payneteasy-Client/1.0',
			CURLOPT_SSL_VERIFYHOST	=> 0,
			CURLOPT_SSL_VERIFYPEER	=> 0,
			CURLOPT_POST						=> 1,
			CURLOPT_RETURNTRANSFER	=> 1,
			CURLOPT_POSTFIELDS			=> http_build_query($data) ]);

		$response = curl_exec($curl);

		if (curl_errno($curl))
			[ $error_code, $error_message ] = [ curl_errno($curl), 'Error occurred: ' .curl_error($curl) ];
		elseif (curl_getinfo($curl, CURLINFO_HTTP_CODE) != 200)
			[ $error_code, $error_message ] = [ curl_getinfo($curl, CURLINFO_HTTP_CODE), "Error occurred. HTTP code: '{$error_code}'" ];

		curl_close($curl);

		if (!empty($error_message))
			throw new ApiException("$error_message ($error_code)");

		if (empty($response))
			throw new ApiException('Card processing response is empty', [ 'response' => $response ]);

		parse_str($response, $result);
		array_walk($result, fn(&$v) => $v = rtrim($v));

		if ($result['type'] == 'validation-error')
			throw new ApiException("Card processing reports error: {$result['error-message']}", [ 'response' => $response ]);

		return $result;
	}
}

?>
