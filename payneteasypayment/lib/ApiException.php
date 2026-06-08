<?php
/**
 *  @author    Payneteasy
 *  @copyright 2007-2026 Payneteasy
 *  @license   Property of Payneteasy
 */

declare(strict_types=1);

namespace Payneteasy\lib;

defined('_PAYNETEASY_LIB_') or die('Restricted access');

class ApiException extends \Exception {
	private array $context = [];

	public function __construct(string $message, mixed $a1=null, mixed $a2=null, int $code=0, \Throwable $previous=null) {
		parent::__construct($message, $code, $previous);

		error_log($this->message.' in '.$this->file.':'.$this->line);

		if (isset($a1)) {
			trace($a1, isset($a2) ? ' --> ' : ' -- ');

			if (isset($a2)) {
				trace($a2, ' <-- ');
			}
		}
	}
}
