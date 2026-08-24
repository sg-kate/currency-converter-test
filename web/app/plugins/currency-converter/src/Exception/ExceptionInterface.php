<?php
/**
 * Marker interface for every exception this module throws on purpose.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by all of the module's domain exceptions.
 *
 * The three concrete types extend different SPL classes, because they mean different
 * things to a caller:
 *
 *     UnknownCurrencyException   extends InvalidArgumentException — the caller passed a
 *                                code the module does not recognise. A bug in the call.
 *     InvalidAmountException     extends InvalidArgumentException — the caller passed
 *                                something that is not a usable number. Also a bug.
 *     RatesUnavailableException  extends RuntimeException — the call was well-formed and
 *                                the currency is real, but the data needed to answer it is
 *                                not in the database yet. Nothing about the call is wrong.
 *
 * That split is deliberate: `catch ( \InvalidArgumentException $e )` around a conversion
 * catches the two programming errors and leaves the "sync has not run" case to be handled
 * as the operational condition it is. This interface exists so a caller that wants all
 * three — a WP-CLI command turning any of them into one clean error line — can catch them
 * in a single clause without also swallowing unrelated SPL exceptions thrown from below.
 *
 * The API layer has its own hierarchy rooted at `Api\ApiException`, and the two do not
 * overlap: this one is about currencies and amounts, that one is about HTTP.
 */
interface ExceptionInterface extends \Throwable {

}
