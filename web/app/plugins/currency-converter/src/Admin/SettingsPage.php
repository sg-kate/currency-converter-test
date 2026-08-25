<?php
/**
 * The settings screen.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Admin;

use Drozd\Currency\Api\ApiKey;
use Drozd\Currency\Api\FreeCurrencyApiClient;
use Drozd\Currency\Currencies;
use Drozd\Currency\Db\WpdbRateRepository;
use Drozd\Currency\Domain\Rate;
use Drozd\Currency\Plugin;
use Drozd\Currency\Service\RateUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * One setting, and a status panel that answers "why is the table empty".
 *
 * The only setting is the API key, which is the whole of what the task contract allows:
 * no rounding preferences, no display formats, no per-currency toggles. Everything else on
 * the screen is read-only — when the last sync ran, when the next one is due, how much quota
 * the last authenticated response reported, how many rates are stored.
 *
 * **The stored key never reaches the page source.** The field is `type="password"` and its
 * `value` is always the empty string — not the key, not a masked stand-in of the right
 * length, nothing. A password input still carries its value in the HTML, so rendering the key
 * into one would put it in the page source, in the browser's cache, in a "view source" over
 * someone's shoulder, and in any screenshot of the DOM. What is shown instead is
 * `ApiKey::hint()`: eight bullets and the last four characters, which is enough to tell two
 * keys apart and not enough to use one.
 *
 * **An empty submission means "unchanged", not "delete".** That is the direct consequence of
 * the field being blank on every load: if empty meant empty, saving any other setting on the
 * screen would silently wipe the key. Deleting one is a separate, deliberate button that
 * posts to `admin-post.php` with its own nonce.
 *
 * **The environment wins.** Where `FREECURRENCYAPI_KEY` is set — it is, on this project —
 * the field is disabled and says so. Accepting a database key that the constant would
 * override is a setting that appears to work and does nothing, which is worse than one that
 * is honestly unavailable.
 */
final class SettingsPage {

	/**
	 * Settings group, section and page slug for the Settings API.
	 */
	const GROUP = 'currency_converter_settings';

	/**
	 * Register the setting and its field.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
	}

	/**
	 * Declare the one setting, its sanitiser and its field.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			ApiKey::OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_api_key' ),

				/*
				 * No `default`, and that is load-bearing rather than an omission.
				 * `register_setting( … 'default' => '' )` registers a `default_option_…`
				 * filter, and `update_option()` compares its result against the current value:
				 *
				 *     if ( apply_filters( "default_option_{$option}", … ) === $old_value ) {
				 *         return add_option( $option, $value, '', $autoload );
				 *     }
				 *
				 * The option is pre-created empty to pin `autoload='no'`, so `$old_value` is
				 * `''` — identical to that default. Core therefore routed the save through
				 * `add_option()`, which rewrote the autoload column to its own default and put
				 * the key in `alloptions` on every page load: the exact thing `ApiKey` exists
				 * to prevent. With no registered default the comparison is `false === ''`,
				 * which is false, so the save takes the `UPDATE` branch and leaves the column
				 * alone.
				 *
				 * Nothing reads this default: every `get_option()` call for this option passes
				 * `''` explicitly.
				 */
				// The key is not REST-readable under any circumstances. `show_in_rest` on an
				// option is how a secret ends up served as JSON to anyone with the right
				// capability and a token.
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'currency_converter_api',
			__( 'API access', 'currency-converter' ),
			array( self::class, 'render_api_section' ),
			self::GROUP
		);

		add_settings_field(
			ApiKey::OPTION,
			__( 'API key', 'currency-converter' ),
			array( self::class, 'render_key_field' ),
			self::GROUP,
			'currency_converter_api',
			array( 'label_for' => 'currency-converter-api-key' )
		);
	}

	/**
	 * Sanitise a submitted key.
	 *
	 * Runs on `sanitize_option_{$option}` through the Settings API. Every path that does not
	 * store something returns the value already in the database, because this callback's
	 * return value *is* what gets written.
	 *
	 * @param mixed $value Whatever was submitted.
	 * @return string The value to store.
	 */
	public static function sanitize_api_key( $value ) {
		// Guarantees `autoload='no'` before the Settings API's own `update_option()` runs:
		// that call passes no autoload argument, and for an option that does not exist yet
		// WordPress would create it autoloaded. See `ApiKey`.
		ApiKey::ensure_option_exists();

		$existing  = (string) get_option( ApiKey::OPTION, '' );
		$submitted = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $submitted ) {
			/*
			 * Empty means unchanged. The field renders empty on every load, so treating this
			 * as a deletion would wipe the key every time the form is saved.
			 *
			 * It still has to say so. This page prints `settings_errors( 'currency_converter' )`
			 * and nothing else, while core registers its own "Settings saved." under the
			 * `general` slug — which this screen never renders, being under `admin.php`. So an
			 * administrator who did exactly what the field instructs ("leave blank to keep the
			 * current key") got no feedback whatsoever, and a successful save was
			 * indistinguishable from one that silently failed.
			 */
			add_settings_error(
				'currency_converter',
				'currency_converter_api_key_unchanged',
				ApiKey::is_from_environment()
					? __( 'Settings saved. The API key comes from the environment (FREECURRENCYAPI_KEY) and is not editable here.', 'currency-converter' )
					: (
						'' === $existing
							? __( 'Settings saved. No API key is stored yet — paste one above to add it.', 'currency-converter' )
							: __( 'Settings saved. The stored API key was left unchanged.', 'currency-converter' )
					),
				'info'
			);

			return $existing;
		}

		if ( ApiKey::is_from_environment() ) {
			add_settings_error(
				'currency_converter',
				'currency_converter_api_key_env',
				__( 'The API key comes from the environment (FREECURRENCYAPI_KEY) and cannot be changed here. Nothing was saved.', 'currency-converter' ),
				'error'
			);

			return $existing;
		}

		if ( ! ApiKey::is_well_formed( $submitted ) ) {
			add_settings_error(
				'currency_converter',
				'currency_converter_api_key_shape',
				__( 'That does not look like an API key — it should be a single run of letters, digits, hyphens or underscores. Nothing was saved.', 'currency-converter' ),
				'error'
			);

			return $existing;
		}

		add_settings_error(
			'currency_converter',
			'currency_converter_api_key_saved',
			__( 'API key saved.', 'currency-converter' ),
			'success'
		);

		return $submitted;
	}

	/**
	 * Prose for the API section.
	 *
	 * @return void
	 */
	public static function render_api_section() {
		?>
		<p class="description">
			<?php
			esc_html_e(
				'The free plan allows 5,000 requests a month. This module spends about 30 of them: one request a day for rates, and one a week for currency names.',
				'currency-converter'
			);
			?>
		</p>
		<?php
	}

	/**
	 * The key field.
	 *
	 * @return void
	 */
	public static function render_key_field() {
		$from_env = ApiKey::is_from_environment();
		$hint     = ApiKey::hint();

		?>
		<input
			type="password"
			id="currency-converter-api-key"
			name="<?php echo esc_attr( ApiKey::OPTION ); ?>"
			value=""
			class="regular-text"
			autocomplete="new-password"
			spellcheck="false"
			<?php disabled( $from_env ); ?>
			placeholder="<?php echo esc_attr( ApiKey::is_configured() ? __( 'Stored — leave blank to keep it', 'currency-converter' ) : __( 'fca_live_…', 'currency-converter' ) ); ?>"
		>
		<?php
		/*
		 * `value=""`, always. See the class docblock: a password input is masked on screen
		 * and plain text in the page source, so the only safe value is no value.
		 */
		?>

		<p class="description">
			<?php if ( $from_env ) : ?>
				<strong><?php esc_html_e( 'Set by the environment.', 'currency-converter' ); ?></strong>
				<?php
				printf(
					/* translators: %s: the masked tail of the key, e.g. ••••••••ab12. */
					esc_html__( 'The key comes from FREECURRENCYAPI_KEY in .env (%s) and cannot be changed from here.', 'currency-converter' ),
					esc_html( '' === $hint ? __( 'hidden', 'currency-converter' ) : $hint )
				);
				?>
			<?php elseif ( ApiKey::is_configured() ) : ?>
				<?php
				printf(
					/* translators: %s: the masked tail of the key, e.g. ••••••••ab12. */
					esc_html__( 'A key is stored (%s). Leave this field blank to keep it; type a new one to replace it.', 'currency-converter' ),
					esc_html( '' === $hint ? __( 'hidden', 'currency-converter' ) : $hint )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'No key is configured. Rates cannot be fetched until there is one.', 'currency-converter' ); ?>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'currency-converter' ) );
		}

		// Anything the admin-post handlers left behind, promoted to a notice for this render.
		UpdateAction::collect_notice();

		?>
		<div class="wrap currency-converter-settings">
			<h1><?php esc_html_e( 'Currency converter settings', 'currency-converter' ); ?></h1>

			<?php settings_errors( 'currency_converter' ); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::GROUP );
				submit_button( __( 'Save settings', 'currency-converter' ) );
				?>
			</form>

			<?php
			self::render_status();
			self::render_actions();
			?>
		</div>
		<?php
	}

	/**
	 * The read-only status panel.
	 *
	 * @return void
	 */
	private static function render_status() {
		$repository = new WpdbRateRepository();
		$stored     = $repository->count();
		$fetched_at = $repository->last_fetched_at();
		$quota      = FreeCurrencyApiClient::stored_quota();
		$next_run   = wp_next_scheduled( Plugin::CRON_HOOK_RATES );

		?>
		<h2><?php esc_html_e( 'Status', 'currency-converter' ); ?></h2>

		<table class="widefat striped cc-status">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Stored rates', 'currency-converter' ); ?></th>
					<td>
						<a href="<?php echo esc_url( Menu::rates_url() ); ?>">
							<?php echo esc_html( number_format_i18n( $stored ) ); ?>
						</a>
						<?php
						printf(
							/* translators: %d: number of currencies the module serves. */
							esc_html__( 'across %d currencies', 'currency-converter' ),
							(int) Currencies::count()
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Last rate sync', 'currency-converter' ); ?></th>
					<td>
						<?php if ( $fetched_at instanceof \DateTimeImmutable ) : ?>
							<?php
							printf(
								/* translators: 1: human-readable time difference, 2: UTC timestamp. */
								esc_html__( '%1$s ago (%2$s UTC)', 'currency-converter' ),
								esc_html( human_time_diff( $fetched_at->getTimestamp() ) ),
								esc_html( $fetched_at->format( Rate::DATETIME_FORMAT ) )
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'Never', 'currency-converter' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Next scheduled sync', 'currency-converter' ); ?></th>
					<td>
						<?php if ( is_int( $next_run ) && $next_run > 0 ) : ?>
							<?php
							printf(
								/* translators: %s: human-readable time difference, e.g. "23 hours". */
								esc_html__( 'in %s', 'currency-converter' ),
								esc_html( human_time_diff( $next_run ) )
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'Not scheduled — it is re-created on the next page load.', 'currency-converter' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Monthly quota', 'currency-converter' ); ?></th>
					<td>
						<?php if ( is_array( $quota ) && isset( $quota['remaining'] ) ) : ?>
							<?php
							printf(
								/* translators: 1: requests remaining, 2: monthly limit. */
								esc_html__( '%1$s of %2$s requests remaining', 'currency-converter' ),
								esc_html( number_format_i18n( (int) $quota['remaining'] ) ),
								esc_html( isset( $quota['limit'] ) && null !== $quota['limit'] ? number_format_i18n( (int) $quota['limit'] ) : '5,000' )
							);
							?>
						<?php else : ?>
							<?php
							// Not the same as a quota of zero, and must not be rendered as one.
							esc_html_e( 'Not known yet — no authenticated response has been seen.', 'currency-converter' );
							?>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
		unset( $repository );
	}

	/**
	 * The action buttons.
	 *
	 * Separate `<form>` elements, each posting to `admin-post.php` with its own nonce. They
	 * are outside the settings form on purpose: nesting forms is invalid HTML, and the
	 * browser resolves it by dropping one — usually not the one you meant.
	 *
	 * @return void
	 */
	private static function render_actions() {
		?>
		<h2><?php esc_html_e( 'Actions', 'currency-converter' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cc-action">
			<input type="hidden" name="action" value="<?php echo esc_attr( UpdateAction::UPDATE_ACTION ); ?>">
			<?php wp_nonce_field( UpdateAction::UPDATE_ACTION ); ?>
			<?php submit_button( __( 'Update now', 'currency-converter' ), 'secondary', 'submit', false ); ?>
			<span class="description">
				<?php
				printf(
					/* translators: %d: hours in the freshness window. */
					esc_html__( 'Fetches rates unless they were refreshed in the last %d hours, in which case it says so and spends no quota.', 'currency-converter' ),
					(int) ( RateUpdater::FRESHNESS_WINDOW / 3600 )
				);
				?>
			</span>
		</form>

		<?php if ( ApiKey::is_stored() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cc-action">
				<input type="hidden" name="action" value="<?php echo esc_attr( UpdateAction::FORGET_ACTION ); ?>">
				<?php wp_nonce_field( UpdateAction::FORGET_ACTION ); ?>
				<?php submit_button( __( 'Delete stored key', 'currency-converter' ), 'delete', 'submit', false ); ?>
				<span class="description">
					<?php
					if ( ApiKey::is_from_environment() ) {
						esc_html_e( 'A key is stored in the database but the environment overrides it, so it is doing nothing.', 'currency-converter' );
					} else {
						esc_html_e( 'Removes the key from the database. Rates stop refreshing until a new one is saved.', 'currency-converter' );
					}
					?>
				</span>
			</form>
		<?php endif; ?>
		<?php
	}
}
