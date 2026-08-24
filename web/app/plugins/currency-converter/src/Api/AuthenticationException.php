<?php
/**
 * The key was refused, or the plan does not cover the request.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Api;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP 401 and 403.
 *
 * Both mean the request will never succeed as written, and both are configuration
 * errors rather than transient ones:
 *
 * - **401** — the key is missing, malformed or revoked. The API returns an identical
 *   body for a wrong key and no key at all, which is why the client refuses to send a
 *   request with an empty key: the two stay distinguishable in our own logs.
 * - **403** — the key is fine but the plan refuses the request. On the free plan this
 *   is what a non-USD `base_currency` returns, so it signals a design error on our side,
 *   not an account problem.
 *
 * Never retry, and never fall back to an unauthenticated request: a retry loop against
 * a revoked key is how the account gets blocked.
 */
class AuthenticationException extends ApiException {

	/**
	 * Whether this is a plan restriction (403) rather than a key problem (401).
	 *
	 * Worth branching on when reporting: a 401 asks the operator to check the key in
	 * `.env`, a 403 says the code asked for something the plan does not sell.
	 *
	 * @return bool True for 403.
	 */
	public function is_plan_restriction() {
		return 403 === $this->status();
	}
}
