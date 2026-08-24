<?php
/**
 * The API rejected a parameter.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Api;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP 422.
 *
 * An unknown currency code or a malformed parameter. Permanent: the identical request
 * cannot succeed, so this is a bug in the caller rather than a condition to wait out.
 *
 * The 422 body keys its errors by parameter name — `{"errors":{"currencies":[...]}}` —
 * and those field names are carried here so a log line says which parameter was refused
 * without the raw body being rendered anywhere.
 */
class ValidationException extends ApiException {

	/**
	 * Validation messages, keyed by the parameter name that was refused.
	 *
	 * @var array<string, array<int, string>>
	 */
	private $errors;

	/**
	 * Constructor.
	 *
	 * @param string                            $message        Human-readable description.
	 * @param int                               $status         HTTP status, normally 422.
	 * @param string                            $api_error_code The API's `error.code`, if any.
	 * @param array<string, array<int, string>> $errors         Messages keyed by parameter name.
	 * @param \Throwable|null                   $previous       Underlying failure, if any.
	 */
	public function __construct( $message, $status = 422, $api_error_code = '', array $errors = array(), ?\Throwable $previous = null ) {
		parent::__construct( $message, $status, $api_error_code, $previous );

		$this->errors = $errors;
	}

	/**
	 * The rejected parameters and their messages.
	 *
	 * @return array<string, array<int, string>> Messages keyed by parameter name.
	 */
	public function errors() {
		return $this->errors;
	}

	/**
	 * The names of the parameters the API refused.
	 *
	 * @return array<int, string> Parameter names.
	 */
	public function invalid_parameters() {
		return array_keys( $this->errors );
	}
}
