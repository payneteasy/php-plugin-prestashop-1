<?php
/**
 *  @author    Payneteasy
 *  @copyright 2007-2026 Payneteasy
 *  @license   Property of Payneteasy
 */

declare(strict_types=1);

namespace Payneteasy {
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

	class PneException extends \Exception {
		public function __construct(string $message, mixed $a1=null, mixed $a2=null) {
			parent::__construct($message, 0, null);

			error_log($this->message.' in '.$this->file.':'.$this->line);

			if (isset($a1)) {
				trace($a1, isset($a2) ? ' --> ' : ' -- ');

				if (isset($a2)) {
					trace($a2, ' <-- ');
				}
			}
		}
	}

	class PneConfigException extends \Exception {
		public function __construct(string $message, bool $verbose) {
			parent::__construct($message, 0, null);

			if ($verbose)
				error_log($this->message.' in '.$this->file.':'.$this->line);
		}
	}

	class PneConfig {
		private const NAME = 'PAYNETEASY_CFG';

		private const INNER = true; # these values are not shown in admin form

		private string $form_name_prefix;
		private bool $changed = false;
		private $on_save, $on_uninstall, $cfg = [
			'LIVE_URL' => [ '', '|^https?://(?:\\w+(?:-\\w+)*\\.)+\\w+/$|', 'Gateway URL' ],
			'SANDBOX_URL' => [ '', '|^https?://(?:\\w+(?:-\\w+)*\\.)+\\w+/$|', 'Sandbox URL' ],
			'END_POINT' => [ '', '/^\d+$/', 'End point Id' ],
			'LOGIN' => [ '', '/^[a-z][\\w-]*\\w$/i', 'Login' ],
			'CONTROL_KEY' => [ '', '/^[\da-f]{8}(?:-[\da-f]{4}){3}-[\da-f]{12}$/i', 'Control key' ],
			'IS_MULTICURR' => [ '' ],
			'IS_SANDBOX' => [ '' ],
			'INTEGRATION' => [ '' ],
			'DEBUG_TRACE' => [ '' ],
			'DEBUG_FAKE' => [ '' ] ];

		public function __construct(callable $on_load, callable $on_save, callable $on_uninstall, array $extra=[], array $extra_inner=[], string $form_prefix='PAYNETEASY_') {
			[ $this->on_save, $this->on_uninstall, $this->form_name_prefix ]  = [ $on_save, $on_uninstall, $form_prefix ];

			foreach ($extra as $k)
				$this->cfg[ $this->allowed_key($k, true) ] = [ '' ];

			foreach ($extra_inner as $k)
				$this->cfg[ $this->allowed_key($k, true) ] = [ '', null, null, self::INNER ];

			$loaded = @unserialize($on_load(self::NAME) ?: '');
			if (is_array($loaded))
				foreach ($loaded as $k => $v)
					$this->cfg[$k][0] = $v;
		}

		public function __get(string $k)
			{ return $this->cfg[ $this->allowed_key($k) ][0] ?? ''; }

		public function __set(string $k, string $v) {
			if ($force_save = strpos($k, '_save'))
				$k = str_replace('_save', '', $k);

			if (null != ($re = ($this->cfg[ $this->allowed_key($k) ][1] ?? null)))
				if (!preg_match($re, $v))
					throw new PneConfigException(($this->cfg[$k][2]) .' has invalid format', false);

			if ($this->cfg[$k][0] != (string)$v)
				[ $this->cfg[$k][0], $this->changed ] = [ (string)$v, true ];

			if ($force_save)
				$this->save();
		}

		private function allowed_key(string $k, bool $check_dup=false): string {
			if ($check_dup == isset($this->cfg[$k]))
				throw new PneConfigException(($check_dup ? 'duplicate' : 'inallowed') ." config key '$k'", true);

			return $k;
		}

		public function save(): bool {
			if (!$this->changed)
				return false;

			foreach ($this->cfg as $k => $v)
				$saving[$k] = $v[0];

			($this->on_save)(self::NAME, serialize($saving));

			$this->changed = false;

			return true;
		}

		public function uninstall(): void
			{ ($this->on_uninstall)(self::NAME); }

		public function form_field_name($k): string
			{ return $this->form_name_prefix .$this->allowed_key($k); }

		public function form_values(): array
			{ return array_reduce(array_map(fn($k) => ($this->cfg[$k][3] ?? false) ? [] : [ $this->form_name_prefix .$k => $this->cfg[$k][0] ], array_keys($this->cfg)), 'array_merge', []); }

		public function form_save(callable $on_fetch, &$has_changes): array {
			$errors = [];

			foreach ($this->cfg as $k => $v)
				if (!($v[3] ?? false))
					try
						{ $this->$k = ($on_fetch( $this->form_field_name($k) ) ?: ''); }
					catch (PneConfigException $E)
						{ $errors[] = $E->getMessage(); }

			$has_changes = $this->save();

			return $errors;
		}
	}

	class PneApi {
		private const URL = 'paynet/api/v2/';
		private const USERAGENT = 'Payneteasy-Client/2.0';

		private const DEBUG_MODE = false; # this is used to show admin controls (or do SetEnv DEBUG_MODE 1)

		# these are debug mode flags in admin section
		public const DEBUG_TRACE_REQUESTS = 0b01;
		public const DEBUG_FAKE_REQUESTS = 0b10;

		private string $gate, $login, $control_key, $endpoint;
		private bool $is_direct, $is_multicurr;
		private int $debug_flags;

		public function __construct(PneConfig $Cfg) {
			[ $this->gate, $this->debug_flags ] = [ $Cfg->IS_SANDBOX ? $Cfg->SANDBOX_URL : $Cfg->LIVE_URL, self::is_debug_mode() ? ((int)$Cfg->DEBUG_TRACE + (int)$Cfg->DEBUG_FAKE) : 0 ];
			[ $this->login, $this->control_key, $this->endpoint, $this->is_direct, $this->is_multicurr ]
				= [ $Cfg->LOGIN, $Cfg->CONTROL_KEY, $Cfg->END_POINT, $Cfg->INTEGRATION == 'direct', $Cfg->IS_MULTICURR == 1 ];
		}

		public static function is_debug_mode(): bool
			{ return self::DEBUG_MODE || ($_SERVER['DEBUG_MODE'] ?? false); }

		public static function got_upgrade(string $repo, string $curr_ver, string $stored_ver_date, callable $on_upd): bool {
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
				throw new PneException($errmsg);
			elseif (empty($response))
				throw new PneException('Version response is empty');

			curl_close($Curl);

			if (!preg_match('/\bv?(?:\d+\.){1,2}\d+$/', ($tag = array_reverse(preg_split('/ +/', (json_decode($response, true)['name'])))[0]), $match))
				throw new PneException("Version tag is malformed: '$tag'");

			$on_upd($match[0] .' ' .date('Y-m-d'));

			return $curr_ver != $match[0];
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
				throw new PneException($errmsg, $data);
			elseif (empty($response))
				throw new PneException('Card processing response is empty', $data);

			parse_str($response, $result);
			array_walk($result, fn(&$v) => $v = rtrim($v));

			if ($this->debug_flags & self::DEBUG_TRACE_REQUESTS) {
				trace([ 'RESULT' => $action ], ' -- ');
				trace($result, ' <- ');
			}

			if ($result['type'] == 'validation-error')
				throw new PneException("Card processing returned error: '{$result['error-message']}'", $data, $result);

			return $result;
		}
	}
}
