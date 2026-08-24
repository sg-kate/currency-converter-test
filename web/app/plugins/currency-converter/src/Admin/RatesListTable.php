<?php
/**
 * The saved-rates table.
 *
 * @package Currency_Converter
 */

namespace Drozd\Currency\Admin;

use Drozd\Currency\Currencies;
use Drozd\Currency\Db\WpdbCurrencyRepository;
use Drozd\Currency\Db\WpdbRateRepository;
use Drozd\Currency\Domain\Currency;
use Drozd\Currency\Domain\Rate;

defined( 'ABSPATH' ) || exit;

/**
 * Every stored rate, paged, sorted and searchable.
 *
 * R7 asks for "all saved exchange rates", and collision C2 reads that as *reachable and
 * accounted for* rather than *simultaneously on screen* — an unpaginated table over the
 * 1,089 rows a paid plan would produce is the standard way an admin screen falls over. What
 * makes the reading honest is that **nothing is filtered unless a human asks for it**: the
 * screen loads with no base filter and no search term, so the "N items" the pager prints is
 * `SELECT COUNT(*)` on the whole table and can be checked against the database directly.
 * Paging hides rows behind a page number; a default filter would hide them behind nothing at
 * all.
 *
 * Sorting and searching arrive in the query string and are treated accordingly. `orderby`
 * never reaches SQL — `$wpdb->prepare()` binds values, not identifiers, so the repository
 * matches it against an allowlist and falls back to the default. The search term is bound,
 * and `esc_like()`d first so that a literal `%` in it is a per cent sign rather than a
 * wildcard.
 *
 * The class extends a core class marked `@access private`. That is the documented way to
 * build an admin table and there is no public alternative, but it means the file it lives in
 * has to be required by hand, at the right moment — see `Menu::load_rates_screen()`.
 */
final class RatesListTable extends \WP_List_Table {

	/**
	 * Rate storage.
	 *
	 * @var WpdbRateRepository
	 */
	private $rates;

	/**
	 * Currency metadata, read once in `prepare_items()` and keyed by code.
	 *
	 * The names and symbols come from `cc_currencies`, which the weekly sync fills. The
	 * table renders perfectly well before that has ever run — the hardcoded names in
	 * `Currencies::CODES` are the fallback — so an empty metadata table costs symbols, not
	 * the screen.
	 *
	 * @var array<string, Currency>
	 */
	private $metadata = array();

	/**
	 * Total rows stored, ignoring search. What "all saved rates" is checked against.
	 *
	 * @var int
	 */
	private $total_stored = 0;

	/**
	 * Constructor.
	 *
	 * @param WpdbRateRepository|null $rates Rate storage; defaults to the real table.
	 */
	public function __construct( $rates = null ) {
		$this->rates = $rates instanceof WpdbRateRepository ? $rates : new WpdbRateRepository();

		parent::__construct(
			array(
				'singular' => 'rate',
				'plural'   => 'rates',
				// No AJAX paging: this screen is read-mostly and 33 rows deep, and the
				// _ajax_fetch_list_table plumbing is a second code path to keep correct for
				// no gain a reviewer would notice.
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns, in display order.
	 *
	 * @return array<string, string> Column slug to header label.
	 */
	public function get_columns() {
		return array(
			'target_code' => __( 'Code', 'currency-converter' ),
			'currency'    => __( 'Currency', 'currency-converter' ),
			'base_code'   => __( 'Base', 'currency-converter' ),
			'rate'        => __( 'Rate', 'currency-converter' ),
			'fetched_at'  => __( 'Last updated', 'currency-converter' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * Exactly the repository's allowlist and no more: a column offered here that the
	 * repository does not recognise would silently sort by something else, which looks like
	 * a broken sort rather than an unsupported one. `currency` is absent for a real reason —
	 * the name lives in the other table and sorting by it would mean a join whose result
	 * changes depending on whether the metadata sync has run.
	 *
	 * @return array<string, array{0: string, 1: bool}> Column slug to [orderby, is_desc_first].
	 */
	public function get_sortable_columns() {
		return array(
			'target_code' => array( 'target_code', false ),
			'base_code'   => array( 'base_code', false ),
			'rate'        => array( 'rate', true ),
			'fetched_at'  => array( 'fetched_at', true ),
		);
	}

	/**
	 * Query the rows for this page and tell the pager what it is paging.
	 *
	 * `$this->_column_headers` is set here and nowhere else. `WP_List_Table` does not
	 * populate it: `print_column_headers()` reads it directly, and if it is unset the screen
	 * dies with an undefined-index error the moment the header row renders.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( Menu::PER_PAGE_OPTION, Menu::DEFAULT_PER_PAGE );
		$search   = self::requested_search();

		$args = array(
			'search'   => $search,
			'orderby'  => self::requested( 'orderby' ),
			'order'    => self::requested( 'order' ),
			'per_page' => $per_page,
			'page'     => $this->get_pagenum(),
		);

		$this->items = $this->rates->all( $args );

		// Counted with the same filter the rows were read with, so the pager cannot promise
		// a page that has nothing on it. `$total_stored` is the unfiltered figure and is
		// what the screen prints above the table as the R7 check.
		$total              = $this->rates->count_matching( array( 'search' => $search ) );
		$this->total_stored = '' === $search ? $total : $this->rates->count();

		$this->metadata = self::load_metadata();

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
			)
		);

		$this->_column_headers = array(
			$this->get_columns(),
			get_hidden_columns( $this->screen ),
			$this->get_sortable_columns(),
			'target_code',
		);
	}

	/**
	 * Total rows in the table, regardless of the current search.
	 *
	 * @return int Row count.
	 */
	public function total_stored() {
		return $this->total_stored;
	}

	/**
	 * What to say when there is nothing to show.
	 *
	 * The two reasons are different problems with different fixes, so they get different
	 * sentences: an empty table means the sync has not run, an empty *result* means the
	 * search matched nothing.
	 *
	 * @return void
	 */
	public function no_items() {
		if ( '' !== self::requested_search() ) {
			esc_html_e( 'No stored rate matches that search.', 'currency-converter' );

			return;
		}

		echo wp_kses_post(
			sprintf(
				/* translators: %s: link to the settings screen, reading "settings screen". */
				__( 'No rates are stored yet. They arrive with the first sync — check the %s.', 'currency-converter' ),
				'<a href="' . esc_url( Menu::settings_url() ) . '">' . esc_html__( 'settings screen', 'currency-converter' ) . '</a>'
			)
		);
	}

	/**
	 * Fallback renderer for any column without its own method.
	 *
	 * @param Rate   $item        The rate being rendered.
	 * @param string $column_name Column slug.
	 * @return string Escaped cell content.
	 */
	public function column_default( $item, $column_name ) {
		unset( $column_name );

		return esc_html( $item->target_code() );
	}

	/**
	 * The target currency code — the primary column.
	 *
	 * @param Rate $item The rate.
	 * @return string Escaped cell content.
	 */
	public function column_target_code( $item ) {
		return '<strong>' . esc_html( $item->target_code() ) . '</strong>';
	}

	/**
	 * The base currency code.
	 *
	 * @param Rate $item The rate.
	 * @return string Escaped cell content.
	 */
	public function column_base_code( $item ) {
		return esc_html( $item->base_code() );
	}

	/**
	 * The currency's name and symbol.
	 *
	 * @param Rate $item The rate.
	 * @return string Escaped cell content.
	 */
	public function column_currency( $item ) {
		$code = $item->target_code();
		$name = isset( $this->metadata[ $code ] ) ? $this->metadata[ $code ]->name() : '';

		if ( '' === $name ) {
			$name = isset( Currencies::CODES[ $code ] ) ? Currencies::CODES[ $code ] : '';
		}

		$symbol = isset( $this->metadata[ $code ] ) ? $this->metadata[ $code ]->symbol() : '';

		if ( '' === $name ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">'
				. esc_html__( 'Name not known', 'currency-converter' ) . '</span>';
		}

		if ( '' === $symbol ) {
			return esc_html( $name );
		}

		return esc_html( $name ) . ' <span class="cc-symbol">' . esc_html( $symbol ) . '</span>';
	}

	/**
	 * The rate itself.
	 *
	 * @param Rate $item The rate.
	 * @return string Escaped cell content.
	 */
	public function column_rate( $item ) {
		return '<span class="cc-rate">' . esc_html( self::format_rate( $item->value() ) ) . '</span>';
	}

	/**
	 * When the rate was fetched.
	 *
	 * Rendered in the site's timezone with the site's format, because that is what an
	 * administrator's other screens show, and labelled with the UTC value in the title so
	 * the column can still be reconciled against `fetched_at` in the database.
	 *
	 * @param Rate $item The rate.
	 * @return string Escaped cell content.
	 */
	public function column_fetched_at( $item ) {
		$fetched_at = $item->fetched_at();

		if ( ! $fetched_at instanceof \DateTimeImmutable ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">'
				. esc_html__( 'Never', 'currency-converter' ) . '</span>';
		}

		$timestamp = $fetched_at->getTimestamp();

		return sprintf(
			'<span title="%s">%s</span>',
			esc_attr( $fetched_at->format( Rate::DATETIME_FORMAT ) . ' UTC' ),
			esc_html(
				sprintf(
					/* translators: %s: human-readable time difference, e.g. "2 hours". */
					__( '%s ago', 'currency-converter' ),
					human_time_diff( $timestamp )
				)
			)
		);
	}

	/**
	 * Currency metadata keyed by code.
	 *
	 * One query for the whole screen rather than one per row.
	 *
	 * @return array<string, Currency> Currencies keyed by upper-case code.
	 */
	private static function load_metadata() {
		$keyed = array();

		foreach ( ( new WpdbCurrencyRepository() )->all() as $currency ) {
			$keyed[ $currency->code() ] = $currency;
		}

		return $keyed;
	}

	/**
	 * Render a stored decimal for reading, without inventing or dropping digits.
	 *
	 * Rates are `DECIMAL(24,12)` and are read back as strings precisely so those digits
	 * survive; this is the one place they are allowed to become something else, and only for
	 * display. Trailing zeros are trimmed — `93.007100000000` reads as `93.0071` — and the
	 * result is grouped by `number_format_i18n()`, which is WordPress core and therefore
	 * present everywhere. `NumberFormatter` is *not* used and must not be: `intl` is in the
	 * WP-CLI image and not in the web image, so it would pass every `bin/wp` check and fatal
	 * on the first page load (collision C6).
	 *
	 * The formatting goes through a float, which cannot hold all 24 digits the column can.
	 * So the result is checked against the input and the raw string is printed instead when
	 * they disagree — an unreadable exact value beats a readable wrong one.
	 *
	 * @param string $value Stored decimal string.
	 * @return string The value, formatted for display.
	 */
	private static function format_rate( $value ) {
		$trimmed = (string) $value;

		if ( false !== strpos( $trimmed, '.' ) ) {
			$trimmed = rtrim( rtrim( $trimmed, '0' ), '.' );
		}

		$dot      = strpos( $trimmed, '.' );
		$decimals = false === $dot ? 0 : strlen( $trimmed ) - $dot - 1;
		$decimals = max( 2, min( Rate::SCALE, $decimals ) );

		// No `bcmath`, no round-trip check — and so no formatting either. The exact stored
		// string is the one answer that is never wrong, and this method's whole premise is
		// that an unreadable exact value beats a readable wrong one. Calling `bccomp()`
		// unguarded here white-screened the rates table on a host without the extension,
		// which the module otherwise reports properly: `CurrencyConverter` refuses to
		// construct and `wp currency doctor` fails the check by name.
		if ( ! function_exists( 'bccomp' ) ) {
			return (string) $value;
		}

		// The round-trip check, on decimal strings rather than on floats: render the float
		// back at the stored scale and compare it with what was stored. `%.12F` and not
		// `%.12f`, because the lower-case conversion is locale-formatted and would compare a
		// comma against a point on a `de_DE` site — the same trap as binding a rate with %f.
		$round_trip = sprintf( '%.' . Rate::SCALE . 'F', (float) $trimmed );

		if ( 0 !== bccomp( $round_trip, (string) $value, Rate::SCALE ) ) {
			return (string) $value;
		}

		return number_format_i18n( (float) $trimmed, $decimals );
	}

	/**
	 * The current search term.
	 *
	 * @return string Sanitised term, or an empty string.
	 */
	private static function requested_search() {
		return self::requested( 's' );
	}

	/**
	 * One value from the query string, sanitised.
	 *
	 * No nonce is checked and none is wanted. This is a `GET` listing screen: sorting,
	 * paging and searching change what is displayed and nothing else, a nonce on them would
	 * break every bookmark and every link in this class's own pagination, and the capability
	 * check that actually protects the screen has already run in `add_menu_page()`. The
	 * values are treated as untrusted regardless — sanitised here, allowlisted or bound in
	 * the repository.
	 *
	 * @param string $key Query argument name.
	 * @return string The sanitised value, or an empty string.
	 */
	private static function requested( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only listing arguments; see the docblock.
		if ( ! isset( $_REQUEST[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only listing arguments; see the docblock.
		return sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
	}
}
