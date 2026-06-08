<?php
/**
 *  @author    Payneteasy
 *  @copyright 2007-2026 Payneteasy
 *  @license   Property of Payneteasy
 */

declare(strict_types=1);

namespace Payneteasy\lib;

defined('_PAYNETEASY_LIB_') or die('Restricted access');

function trace(mixed $arg, string $prefix=''): void {
	if (is_object($arg))
		$arg = get_object_vars($arg);

	ksort($arg);
	foreach ($arg as $key => $value) {
		if ($key == 'cvv2')
			$value = str_repeat('*', strlen($value));
		elseif ($key == 'credit_card_number')
			$value = str_repeat('*', strlen($value)-4) .substr($value, -4);

		error_log("$prefix'$key' => '$value'");
	}
}

include_once('ApiException.php');

class Api {
	private const URL = 'paynet/api/v2/';
	private const USERAGENT = 'Payneteasy-Client/2.0';

	private const DEBUG_MODE = false; # this is used to show admin controls (or do SetEnv DEBUG_MODE 1)

	# these are debug mode flags in admin section
	public const DEBUG_TRACE_REQUESTS = 0b01;
	public const DEBUG_FAKE_REQUESTS = 0b10;

	private string $gate, $login, $control_key, $endpoint;
	private bool $is_direct, $is_multicurr;
	private int $debug_flags;

	public function __construct(...$args)
		{ [ $this->gate, $this->login, $this->control_key, $this->endpoint, $this->is_direct, $this->is_multicurr, $this->debug_flags ] = $args; }

	public static function is_debug_mode(): bool
		{ return self::DEBUG_MODE || ($_SERVER['DEBUG_MODE'] ?? false); }

	public static function got_upgrade(string $repo, string $curr_ver, string $stored_ver_date, callable $upd): bool {
		if ($stored_ver_date == $curr_ver .' ' .date('Y-m-d')) # check is daily
			return false;

		[ $stored_ver ] = explode(' ', $stored_ver_date ?: '0');

		if ($stored_ver && $stored_ver != $curr_ver)
			return true;

		$Curl = curl_init($url = sprintf('https://api.github.com/repos/%s/releases/latest', $repo));
		curl_setopt_array($Curl, [ CURLOPT_USERAGENT => self::USERAGENT, CURLOPT_RETURNTRANSFER => 1, CURLOPT_CONNECTTIMEOUT => 10 ]);

		$response = curl_exec($Curl);

		if ($err = curl_error($Curl))
			$errmsg = "Version request error, CURL message: $err";
		elseif (($err = curl_getinfo($Curl, CURLINFO_HTTP_CODE)) != 200)
			$errmsg = "Version request error, HTTP code: '{$err}'";

		if (!empty($errmsg))
			throw new ApiException($errmsg);
		elseif (empty($response))
			throw new ApiException('Version response is empty');

		curl_close($Curl);

		if (!preg_match('/\bv?(?:\d+\.){1,2}\d+$/', ($tag = array_reverse(preg_split('/ +/', (json_decode($response, true)['name'])))[0]), $match))
			throw new ApiException("Version tag is malformed: '$tag'");

		$upd($match[0] .' ' .date('Y-m-d'));

		return $curr_ver != $match[0];
	}

	public static function check_config_input(string $gate, string $sandbox, string $login, string $control_key, string $endpoint): array {
		return array_reduce([
			[ 'Gateway URL', $gate, '|^https?://(?:\\w+(?:-\\w+)*\\.)+\\w+/$|' ],
			[ 'Sandbox URL', $sandbox, '|^https?://(?:\\w+(?:-\\w+)*\\.)+\\w+/$|' ],
			[ 'Login', $login, '/^[a-z][\\w-]*\\w$/i' ],
			[ 'Control key', $control_key, '/^[\da-f]{8}(?:-[\da-f]{4}){3}-[\da-f]{12}$/i' ],
			[ 'End point Id', $endpoint, '/^\d+$/' ]],
			function($iter, $entry){ if (!preg_match($entry[2], $entry[1])) $iter[] = "{$entry[0]} has invalid format"; return $iter; },
			[]);
	}

	public function is_auth_valid(): bool {
		$test = $this->status([ 'client_orderid' => 1, 'orderid' => 1 ]);
		return $test['status'] == 'approved';
	}

	public function is_direct(): bool
		{ return $this->is_direct; }

	public function sale(array $data): array
		{ return $this->execute($this->is_direct ? 'sale' : 'sale-form', $this->signed($data)); }

	public function return(array $data): array
		{ return $this->execute('return', $this->signed($data, null, true)); }

	public function status(array $data): array
		{ return $this->execute('status', $this->signed($data, $this->login .$data['client_orderid'] .$data['orderid'] .$this->control_key)); }

	private function signed(array $data, string $str=null, bool $add_login=false): array {
		if (isset($str) || $add_login)
			$data['login'] = $this->login;

		$data['control'] = sha1($str ?? $this->endpoint .$data['client_orderid'] .($data['amount'] * 100) .$data['email'] .$this->control_key);
		return $data;
	}

	private function execute(string $action, array $data): array {
		if ($this->debug_flags & self::DEBUG_TRACE_REQUESTS) {
			trace([ 'REQUEST' => $action ], ' -- ');
			trace($data, ' -> ');
		}

		if ($this->debug_flags & self::DEBUG_FAKE_REQUESTS) {
			trigger_error('DEBUG_MODE, gate requests/responses are fake', E_USER_WARNING);

			$fake = [
				'sale' => [ 'type' => 'async-response' ],
				'sale-form' => [ 'type' => 'async-response' ],
				'status' => [ 'status' => 'approved' ],
				'return' => [ 'status' => 'approved' ] ];

			return array_merge($fake[$action], [ 'merchant-order-id' => $data['client_orderid'], 'paynet-order-id' => time(), 'serial-number' => '00000000-0000-0000-0000-000000000000' ]);
		}

		$Curl = curl_init($this->gate .self::URL .$action .($this->is_multicurr ? '/group/' : '/') .$this->endpoint);
		curl_setopt_array($Curl, [
			CURLOPT_HEADER					=> 0,
			CURLOPT_USERAGENT				=> self::USERAGENT,
			CURLOPT_SSL_VERIFYHOST	=> 0,
			CURLOPT_SSL_VERIFYPEER	=> 0,
			CURLOPT_POST						=> 1,
			CURLOPT_RETURNTRANSFER	=> 1,
			CURLOPT_POSTFIELDS			=> http_build_query($data) ]);

		if ($this->is_debug_mode())
			curl_setopt($Curl, CURLOPT_CONNECTTIMEOUT, 10);

		$response = curl_exec($Curl);

		if ($err = curl_error($Curl))
			$errmsg = "Card processing error, CURL error: '$err'";
		elseif (($err = curl_getinfo($Curl, CURLINFO_HTTP_CODE)) != 200)
			$errmsg = "Card processing error, HTTP code: '$err'";

		curl_close($Curl);

		if (!empty($errmsg))
			throw new ApiException($errmsg, $data);
		elseif (empty($response))
			throw new ApiException('Card processing response is empty', $data);

		parse_str($response, $result);
		array_walk($result, fn(&$v) => $v = rtrim($v));

		if ($this->debug_flags & self::DEBUG_TRACE_REQUESTS) {
			trace([ 'RESULT' => $action ], ' -- ');
			trace($result, ' <- ');
		}
		
		if ($result['type'] == 'validation-error')
			throw new ApiException("Card processing returned error: '{$result['error-message']}'", $data, $result);

		return $result;
	}
}
