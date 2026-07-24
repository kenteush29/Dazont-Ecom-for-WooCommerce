<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Trending Products" module: a shortcode that renders WooCommerce
 * best-sellers over a configurable time window, using the WooCommerce
 * Analytics order-product lookup table.
 *
 * Front-end footprint: registering the shortcode is a plain add_shortcode()
 * call (no query, no assets). The DB query, caching and product rendering
 * only run on pages where the shortcode is actually used, and rendering is
 * fully delegated to WooCommerce's own [products] shortcode — no custom CSS
 * or markup of our own to maintain.
 */
final class DZE_Trending {

	public const SHORTCODE = 'time_bestsellers';
	public const NONCE     = 'dze_admin';
	public const MENU_SLUG        = 'dazont-ecom-trending';

	public const OPT_TIME_PERIOD    = 'dze_trending_time_period';
	public const OPT_LIMIT          = 'dze_trending_limit';
	public const OPT_COLUMNS        = 'dze_trending_columns';
	public const OPT_CACHE_HOURS    = 'dze_trending_cache_hours';
	public const OPT_CACHE_VERSION  = 'dze_trending_cache_version';

	private const OPTION_GROUP = 'dze_trending_options';

	private const MIN_LIMIT      = 1;
	private const MAX_LIMIT      = 100;
	private const MIN_COLUMNS    = 1;
	private const MAX_COLUMNS    = 6;
	private const MIN_DAYS       = 1;
	private const MAX_DAYS       = 365;
	private const MIN_CACHE_HRS  = 1;
	private const MAX_CACHE_HRS  = 168; // 7 days

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Shortcode registration must happen on every request (front included)
		// — it costs nothing until the shortcode is actually rendered.
		add_shortcode( self::SHORTCODE, [ $this, 'render_shortcode' ] );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_dze_trending_clear_cache', [ $this, 'ajax_clear_cache' ] );
	}

	// -------------------------------------------------------------------------
	// Menu + settings + assets
	// -------------------------------------------------------------------------

	public function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Trending Products', 'dazont-ecom' ),
			__( 'Trending Products', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( self::OPTION_GROUP, self::OPT_TIME_PERIOD, [
			'sanitize_callback' => [ $this, 'sanitize_days' ],
			'default'           => 30,
		] );
		register_setting( self::OPTION_GROUP, self::OPT_LIMIT, [
			'sanitize_callback' => [ $this, 'sanitize_limit' ],
			'default'           => 8,
		] );
		register_setting( self::OPTION_GROUP, self::OPT_COLUMNS, [
			'sanitize_callback' => [ $this, 'sanitize_columns' ],
			'default'           => 4,
		] );
		register_setting( self::OPTION_GROUP, self::OPT_CACHE_HOURS, [
			'sanitize_callback' => [ $this, 'sanitize_cache_hours' ],
			'default'           => 1,
		] );
	}

	public function sanitize_days( $value ): int {
		return min( self::MAX_DAYS, max( self::MIN_DAYS, absint( $value ) ) );
	}

	public function sanitize_limit( $value ): int {
		return min( self::MAX_LIMIT, max( self::MIN_LIMIT, absint( $value ) ) );
	}

	public function sanitize_columns( $value ): int {
		return min( self::MAX_COLUMNS, max( self::MIN_COLUMNS, absint( $value ) ) );
	}

	public function sanitize_cache_hours( $value ): int {
		return min( self::MAX_CACHE_HRS, max( self::MIN_CACHE_HRS, absint( $value ) ) );
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}
		wp_enqueue_script( 'dze-trending', DZE_URL . 'admin/js/trending.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-trending', 'dzeTrending', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'clearing' => __( 'Clearing…', 'dazont-ecom' ),
				'cleared'  => __( 'Cache cleared. New requests will recompute trending products.', 'dazont-ecom' ),
				'error'    => __( 'Error', 'dazont-ecom' ),
			],
		] );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}

		$time_period  = self::get_option_int( self::OPT_TIME_PERIOD, 30 );
		$limit        = self::get_option_int( self::OPT_LIMIT, 8 );
		$columns      = self::get_option_int( self::OPT_COLUMNS, 4 );
		$cache_hours  = self::get_option_int( self::OPT_CACHE_HOURS, 1 );
		$table_exists = $this->lookup_table_exists();

		require DZE_DIR . 'admin/views/trending-page.php';
	}

	// -------------------------------------------------------------------------
	// AJAX: clear cache
	// -------------------------------------------------------------------------

	public function ajax_clear_cache(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}

		// Bump the cache version instead of scanning/deleting transients:
		// every cached key embeds the version, so old entries are simply
		// orphaned and expire naturally via their own TTL.
		$version = (int) get_option( self::OPT_CACHE_VERSION, 1 );
		update_option( self::OPT_CACHE_VERSION, $version + 1, false );

		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts( [
			'time_period' => (string) self::get_option_int( self::OPT_TIME_PERIOD, 30 ),
			'limit'       => (string) self::get_option_int( self::OPT_LIMIT, 8 ),
			'columns'     => (string) self::get_option_int( self::OPT_COLUMNS, 4 ),
		], $atts, self::SHORTCODE );

		$time_period = min( self::MAX_DAYS, max( self::MIN_DAYS, absint( $atts['time_period'] ) ) );
		$limit       = min( self::MAX_LIMIT, max( self::MIN_LIMIT, absint( $atts['limit'] ) ) );
		$columns     = min( self::MAX_COLUMNS, max( self::MIN_COLUMNS, absint( $atts['columns'] ) ) );

		// Fetch a larger candidate pool than requested: WooCommerce's own
		// [products] shortcode silently drops out-of-stock products when the
		// store has "Hide out of stock items from the catalog" enabled — even
		// when we pass their IDs explicitly. Best-sellers are exactly the
		// products most likely to be out of stock, so without a buffer the
		// displayed count randomly falls short of the requested limit as
		// stock status changes. Overfetching lets WooCommerce's internal
		// filter drop some candidates while we still end up with `limit`
		// visible products (unless the whole pool genuinely has fewer).
		$product_ids = $this->get_trending_product_ids( $time_period, $limit );
		if ( empty( $product_ids ) ) {
			return '';
		}

		// orderby="post__in" preserves our sales ranking — without it the
		// native [products] shortcode falls back to its own default order
		// and the "trending" ranking would be lost.
		return do_shortcode( sprintf(
			'[products limit="%d" columns="%d" ids="%s" orderby="post__in"]',
			$limit,
			$columns,
			implode( ',', array_map( 'absint', $product_ids ) )
		) );
	}

	// -------------------------------------------------------------------------
	// Data
	// -------------------------------------------------------------------------

	private function lookup_table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/** How many extra candidates to fetch per requested slot, and the hard cap. */
	private const CANDIDATE_MULTIPLIER = 5;
	private const CANDIDATE_CAP        = 300;

	/**
	 * @return int[] Candidate product IDs ranked by units sold over the
	 *               period, cached. Returns more than $limit IDs on purpose
	 *               (see render_shortcode()) so WooCommerce's own visibility
	 *               filtering doesn't leave fewer than $limit visible.
	 */
	private function get_trending_product_ids( int $time_period, int $limit ): array {
		$candidate_limit = min( self::CANDIDATE_CAP, $limit * self::CANDIDATE_MULTIPLIER );

		$version   = (int) get_option( self::OPT_CACHE_VERSION, 1 );
		$cache_key = 'dze_trending_v' . $version . '_' . $time_period . '_' . $candidate_limit;

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';

		// The WooCommerce Analytics lookup table may not exist or be empty on
		// stores where analytics hasn't synced — fail gracefully rather than
		// erroring on the live site.
		if ( ! $this->lookup_table_exists() ) {
			return [];
		}

		// Site-timezone-aware window, independent of the PHP default timezone.
		$now   = current_datetime();
		$start = $now->modify( "-{$time_period} days" );

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT product_id, SUM(product_qty) AS total_qty
			 FROM {$table}
			 WHERE date_created BETWEEN %s AND %s
			 GROUP BY product_id
			 ORDER BY total_qty DESC
			 LIMIT %d",
			$start->format( 'Y-m-d H:i:s' ),
			$now->format( 'Y-m-d H:i:s' ),
			$candidate_limit
		) );

		$product_ids = array_map( 'absint', wp_list_pluck( $results, 'product_id' ) );

		$cache_hours = self::get_option_int( self::OPT_CACHE_HOURS, 1 );
		set_transient( $cache_key, $product_ids, $cache_hours * HOUR_IN_SECONDS );

		return $product_ids;
	}

	private static function get_option_int( string $key, int $default ): int {
		$value = get_option( $key, $default );
		return $value !== '' ? (int) $value : $default;
	}
}
