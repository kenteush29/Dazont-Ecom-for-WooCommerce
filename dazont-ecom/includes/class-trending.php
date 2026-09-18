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

	public const OPT_CACHE_VERSION  = 'dze_trending_cache_version';

	// Fixed cache lifetime — no longer a user setting.
	private const CACHE_HOURS    = 24;

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

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_dze_trending_clear_cache', [ $this, 'ajax_clear_cache' ] );
	}

	// -------------------------------------------------------------------------
	// Menu + settings + assets
	// -------------------------------------------------------------------------

	/**
	 * This module's entry on the Shortcodes screen.
	 *
	 * The module no longer owns a menu of its own: what it offers is a
	 * shortcode, and shortcodes are all documented in one place.
	 */
	public static function shortcode_card(): array {
		return [
			'tag'     => self::SHORTCODE,
			'title'   => __( 'Trending products', 'dazont-ecom' ),
			'summary' => __( 'Best sellers over the window you choose, rendered by WooCommerce itself.', 'dazont-ecom' ),
			'body'    => [ self::class, 'render_card' ],
		];
	}

	public static function render_card(): void {
		$table_exists = self::instance()->lookup_table_exists();
		require DZE_DIR . 'admin/views/trending-page.php';
	}

	public function enqueue_assets( string $hook ): void {
		// The card lives on the Shortcodes screen now; the cache button on it
		// still needs its handler.
		if ( ! class_exists( 'DZE_Shortcodes' ) || strpos( $hook, DZE_Shortcodes::MENU_SLUG ) === false ) {
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

	/**
	 * Thin wrapper over WooCommerce's own [products] shortcode: it computes the
	 * best-seller ranking for a time window and hands EVERYTHING else — limit,
	 * columns, paginate, orderby, order, category, … — straight through to
	 * [products], so all native WooCommerce behaviour (pagination included) works.
	 *
	 * Two attributes are ours: `time_period` (days; "all"/0/absent = all time)
	 * and `exclude_on_sale` (keep the discounted ones out).
	 * Ranking is preserved with orderby="post__in" unless the author sets orderby.
	 */
	public function render_shortcode( $atts ): string {
		$atts = array_change_key_case( (array) $atts, CASE_LOWER );

		// Our single attribute; the rest are forwarded verbatim to [products].
		$period_raw = isset( $atts['time_period'] ) ? strtolower( trim( (string) $atts['time_period'] ) ) : 'all';
		unset( $atts['time_period'] );
		$days = ( '' === $period_raw || 'all' === $period_raw ) ? 0 : absint( $period_raw );

		// OURS TOO, and it never reaches [products]: WooCommerce has `on_sale`
		// to ask FOR the discounted ones and nothing at all to ask against them.
		// A shop showing its promotions in one block does not want the same
		// products filling the block above it.
		$skip_sale = ! empty( $atts['exclude_on_sale'] ) && filter_var( $atts['exclude_on_sale'], FILTER_VALIDATE_BOOLEAN );
		unset( $atts['exclude_on_sale'] );

		$paginate = ! empty( $atts['paginate'] ) && filter_var( $atts['paginate'], FILTER_VALIDATE_BOOLEAN );
		$limit    = isset( $atts['limit'] ) ? absint( $atts['limit'] ) : 0;

		// Candidate pool. When paginating we need enough of the ranking to fill
		// several pages; otherwise a small over-fetch covers out-of-stock products
		// WooCommerce silently hides (best-sellers go out of stock often).
		$cap = $paginate
			? self::PAGINATE_CAP
			: min( self::CANDIDATE_CAP, max( 1, $limit ) * self::CANDIDATE_MULTIPLIER );
		// Asking for as many as we mean to show and then throwing some away
		// is how a block of twelve comes back with five. During a big sale most
		// of the best-sellers ARE discounted, so the pool is doubled — still
		// capped, because a ranking nobody will scroll to costs the same query.
		if ( $skip_sale ) {
			$cap = min( $paginate ? self::PAGINATE_CAP : self::CANDIDATE_CAP, $cap * 2 );
		}

		$product_ids = $this->get_trending_product_ids( $days, $cap );
		if ( empty( $product_ids ) ) {
			return '';
		}

		if ( $skip_sale && function_exists( 'wc_get_product_ids_on_sale' ) ) {
			// WooCommerce's own list, which on a shop running Dazont Ecom's
			// Discounts module also holds the products IT has marked down —
			// they are merged into this very function. One answer to "what is
			// on sale", so this block and the sale block can never disagree.
			$product_ids = self::prefer_not_on_sale(
				$product_ids,
				array_map( 'absint', (array) wc_get_product_ids_on_sale() )
			);
			if ( empty( $product_ids ) ) {
				return '';
			}
		}

		// Preserve the sales ranking unless the author explicitly reorders.
		if ( ! isset( $atts['orderby'] ) ) {
			$atts['orderby'] = 'post__in';
		}
		// A sensible default only when neither a limit nor pagination is requested.
		if ( ! isset( $atts['limit'] ) && ! $paginate ) {
			$atts['limit'] = '12';
		}
		$atts['ids'] = implode( ',', array_map( 'absint', $product_ids ) );

		$pairs = '';
		foreach ( $atts as $key => $value ) {
			$key = preg_replace( '/[^a-z0-9_]/', '', (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$pairs .= sprintf( ' %s="%s"', $key, esc_attr( str_replace( '"', '', (string) $value ) ) );
		}

		return do_shortcode( "[products{$pairs}]" );
	}

	/**
	 * UNE PRÉFÉRENCE, PAS UN COUPERET.
	 *
	 * « Trending Gear vide. Des soldes ont commencé avec le module dazont ecom
	 * marketing. Et voilà, le filtre du shortcode filtre tout. »
	 *
	 * `exclude_on_sale` existe pour qu'un bloc de meilleures ventes ne répète
	 * pas le bloc de promotions au-dessus de lui. C'est une préférence
	 * d'affichage, et elle était appliquée comme une règle absolue : pendant
	 * une promo générale 46 des 48 meilleures ventes sont remisées, le bloc
	 * prévu pour dix en montrait deux — et quand il n'en restait aucune, la
	 * fonction rendait une chaîne vide et la vitrine disparaissait. Un bloc
	 * qui s'efface le jour des soldes est exactement le contraire de ce que
	 * l'attribut cherchait à obtenir.
	 *
	 * Alors : les non-remisées d'abord, dans l'ordre du classement, puis les
	 * remisées derrière. La préférence est tenue quand elle peut l'être, et le
	 * bloc est toujours plein.
	 *
	 * ET ON RÉORDONNE, ON NE COUPE PAS. Première version de ce correctif :
	 * elle rendait exactement les dix demandés — et le bloc en affichait huit.
	 * `[products]` écarte au rendu ce qui est en rupture ou hors catalogue,
	 * et le module sur-tire exprès pour ça depuis toujours : « demander
	 * autant qu'on veut en montrer et en jeter ensuite, c'est comme ça qu'un
	 * bloc de douze revient avec cinq ». Couper la liste à dix supprimait ce
	 * coussin. La liste sort donc entière, dans le bon ordre, et c'est
	 * `limit` qui prend les dix premiers qui tiennent debout.
	 *
	 * @param int[] $ranked      Les identifiants, dans l'ordre des ventes.
	 * @param int[] $on_sale_ids Ce que WooCommerce dit être en promo.
	 * @return int[] Les mêmes identifiants, non-remisés en tête.
	 */
	public static function prefer_not_on_sale( array $ranked, array $on_sale_ids ): array {
		$sale = array_flip( array_map( 'absint', $on_sale_ids ) );
		$free = [];
		$held = [];
		foreach ( $ranked as $id ) {
			if ( isset( $sale[ (int) $id ] ) ) {
				$held[] = $id;
			} else {
				$free[] = $id;
			}
		}
		return array_merge( $free, $held );
	}

	// -------------------------------------------------------------------------
	// Data
	// -------------------------------------------------------------------------

	public function lookup_table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/** How many extra candidates to fetch per requested slot, and the hard caps. */
	private const CANDIDATE_MULTIPLIER = 5;
	private const CANDIDATE_CAP        = 300;
	private const PAGINATE_CAP         = 1000; // enough ranking to fill many pages.

	/**
	 * @param int $days            Look-back window in days; 0 = all time.
	 * @param int $candidate_limit How many ranked product IDs to return.
	 * @return int[] Product IDs ranked by units sold over the window, cached.
	 */
	/**
	 * THE RANKING, AND WHY IT IS ORDERED TWICE.
	 *
	 * `ORDER BY total_qty DESC` alone leaves products that sold the same
	 * number in no defined order at all, and MySQL is free to return them
	 * differently for a different LIMIT. So asking for 60 candidates instead
	 * of 30 could change which ten came out on top, on a block whose whole
	 * promise is "the best sellers". `product_id` breaks the tie and costs
	 * nothing: the same shop, the same window and the same cache answer the
	 * same thing every time, whatever the pool asked for.
	 */
	private function get_trending_product_ids( int $days, int $candidate_limit ): array {
		$candidate_limit = max( 1, $candidate_limit );

		$version   = (int) get_option( self::OPT_CACHE_VERSION, 1 );
		$cache_key = 'dze_trending_v' . $version . '_' . $days . '_' . $candidate_limit;

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// The WooCommerce Analytics lookup table may not exist or be empty on
		// stores where analytics hasn't synced — fail gracefully rather than
		// erroring on the live site.
		if ( ! $this->lookup_table_exists() ) {
			return [];
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';

		if ( $days > 0 ) {
			// Site-timezone-aware window, independent of the PHP default timezone.
			$now     = current_datetime();
			$start   = $now->modify( "-{$days} days" );
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT product_id, SUM(product_qty) AS total_qty
				 FROM {$table}
				 WHERE date_created BETWEEN %s AND %s
				 GROUP BY product_id
				 ORDER BY total_qty DESC, product_id ASC
				 LIMIT %d",
				$start->format( 'Y-m-d H:i:s' ),
				$now->format( 'Y-m-d H:i:s' ),
				$candidate_limit
			) );
		} else {
			// All-time ranking.
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT product_id, SUM(product_qty) AS total_qty
				 FROM {$table}
				 GROUP BY product_id
				 ORDER BY total_qty DESC, product_id ASC
				 LIMIT %d",
				$candidate_limit
			) );
		}

		$product_ids = array_map( 'absint', wp_list_pluck( $results, 'product_id' ) );

		set_transient( $cache_key, $product_ids, self::CACHE_HOURS * HOUR_IN_SECONDS );

		return $product_ids;
	}
}
