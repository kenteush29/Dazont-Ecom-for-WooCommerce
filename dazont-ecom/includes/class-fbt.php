<?php
defined( 'ABSPATH' ) || exit;

/**
 * Frequently Bought Together.
 *
 * Shows an automatic "goes well with" block on the single product page. Unlike
 * order-history recommenders, this needs NO sales at all — it recommends from
 * the product's own attributes and categories, so it works from day one:
 *
 *   Tier 1 (best) — same shared attribute value AND a complementary category.
 *                   e.g. viewing a MULTICAM JACKET → MULTICAM PANTS.
 *   Tier 2        — same shared attribute value (e.g. same camo), any type.
 *   Tier 3        — a complementary category (e.g. jacket → pants), any pattern.
 *   Tier 4        — same category (thematic fallback).
 *
 * "Shared attribute" = the global product attribute(s) you mark as matching
 * (e.g. Camo / Pattern). "Complementary category" = the category pairings you
 * define (e.g. Jackets ↔ Pants). Both are set on the Recommendations screen,
 * along with placement, heading and how many products to show.
 *
 * Performance: the recommended IDs for a product are computed at most once per
 * day (keyed by product + a hash of the settings) and cached in a transient, so
 * ordinary views run no queries; each tier is a bounded, index-friendly
 * WP_Query (ids only, no_found_rows); it only loads on product pages and adds
 * no front-end JavaScript.
 */
final class DZE_Fbt {

	public const OPT_SETTINGS = 'dze_fbt_settings';
	public const MENU_SLUG    = 'dazont-ecom-fbt';
	private const CACHE_PREFIX = 'dze_fbt_';
	private const PERF_TTL      = DAY_IN_SECONDS;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, 'register_menu' ] );
			add_action( 'admin_init', [ $this, 'register_settings' ] );
		}

		$s = self::get_settings();
		if ( empty( $s['enabled'] ) ) {
			return;
		}
		$placements = self::placements();
		$place      = $placements[ $s['position'] ] ?? $placements['below_summary'];
		add_action( $place['hook'], [ $this, 'render' ], $place['priority'] );
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function get_settings(): array {
		$s = get_option( self::OPT_SETTINGS, [] );
		$s = is_array( $s ) ? $s : [];
		return wp_parse_args( $s, [
			'enabled'          => true,
			'heading'          => __( 'Frequently bought together', 'dazont-ecom' ),
			'limit'            => 4,
			'position'         => 'below_summary',
			'match_attributes' => [],  // taxonomy slugs, e.g. [ 'pa_camo' ].
			'category_pairs'   => [],  // [ [ 'from' => term_id, 'to' => [ term_ids ] ], ... ].
		] );
	}

	/** Placement choices on the single product page: key => [label, hook, priority]. */
	public static function placements(): array {
		return [
			'below_summary'   => [ 'label' => __( 'Below the product summary (default)', 'dazont-ecom' ), 'hook' => 'woocommerce_after_single_product_summary', 'priority' => 15 ],
			'above_related'   => [ 'label' => __( 'Just above Related products', 'dazont-ecom' ),          'hook' => 'woocommerce_after_single_product_summary', 'priority' => 19 ],
			'under_cart'      => [ 'label' => __( 'Right under the Add to cart button', 'dazont-ecom' ),    'hook' => 'woocommerce_after_add_to_cart_form',       'priority' => 10 ],
			'after_meta'      => [ 'label' => __( 'After the product meta (SKU/categories)', 'dazont-ecom' ), 'hook' => 'woocommerce_single_product_summary',      'priority' => 45 ],
			'page_bottom'     => [ 'label' => __( 'At the very bottom of the product page', 'dazont-ecom' ), 'hook' => 'woocommerce_after_single_product',         'priority' => 5 ],
		];
	}

	public function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Recommendations', 'dazont-ecom' ),
			__( 'Recommendations', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'dze_fbt_options', self::OPT_SETTINGS, [ 'sanitize_callback' => [ $this, 'sanitize_settings' ], 'autoload' => true ] );
	}

	public function sanitize_settings( $value ): array {
		$in = is_array( $value ) ? $value : [];

		$position = array_key_exists( $in['position'] ?? '', self::placements() ) ? $in['position'] : 'below_summary';

		$attrs = [];
		foreach ( (array) ( $in['match_attributes'] ?? [] ) as $tax ) {
			$tax = sanitize_key( $tax );
			if ( $tax !== '' && taxonomy_exists( $tax ) ) {
				$attrs[] = $tax;
			}
		}

		$pairs = [];
		$rows  = (array) ( $in['category_pairs'] ?? [] );
		foreach ( $rows as $row ) {
			$from = absint( $row['from'] ?? 0 );
			$to   = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $row['to'] ?? [] ) ) ) ) );
			if ( $from && ! empty( $to ) ) {
				$pairs[] = [ 'from' => $from, 'to' => $to ];
			}
		}

		return [
			'enabled'          => ! empty( $in['enabled'] ),
			'heading'          => sanitize_text_field( (string) ( $in['heading'] ?? '' ) ),
			'limit'            => min( 12, max( 1, (int) ( $in['limit'] ?? 4 ) ) ),
			'position'         => $position,
			'match_attributes' => array_values( array_unique( $attrs ) ),
			'category_pairs'   => $pairs,
		];
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$settings   = self::get_settings();
		$placements = self::placements();
		$attributes = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : [];
		$categories = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 500 ] );
		require DZE_DIR . 'admin/views/fbt-settings.php';
	}

	// =========================================================================
	// Front-end render
	// =========================================================================

	public function render(): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$s   = self::get_settings();
		$ids = $this->recommendations( $product->get_id(), (int) $s['limit'] );
		if ( empty( $ids ) ) {
			return;
		}

		$heading = (string) $s['heading'];
		echo '<section class="dze-fbt">';
		if ( $heading !== '' ) {
			echo '<h2 class="dze-fbt__title">' . esc_html( $heading ) . '</h2>';
		}
		echo '<ul class="dze-fbt__grid">';
		foreach ( $ids as $pid ) {
			$p = wc_get_product( $pid );
			if ( $p instanceof \WC_Product && $p->is_visible() ) {
				$this->card( $p );
			}
		}
		echo '</ul></section>';
		$this->print_styles_once();
	}

	private function card( \WC_Product $p ): void {
		echo '<li class="dze-fbt__item">';
		printf(
			'<a class="dze-fbt__link" href="%s">%s<span class="dze-fbt__name">%s</span></a>',
			esc_url( get_permalink( $p->get_id() ) ),
			$p->get_image( 'woocommerce_thumbnail' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC image markup.
			esc_html( $p->get_name() )
		);
		echo '<span class="dze-fbt__price">' . $p->get_price_html() . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC price markup.

		$prev = $GLOBALS['product'] ?? null;
		$GLOBALS['product'] = $p;
		woocommerce_template_loop_add_to_cart();
		$GLOBALS['product'] = $prev;
		echo '</li>';
	}

	// =========================================================================
	// Recommendation engine (no order history needed)
	// =========================================================================

	public function recommendations( int $product_id, int $limit ): array {
		$s   = self::get_settings();
		$sig = substr( md5( wp_json_encode( [ $s['match_attributes'], $s['category_pairs'], $limit ] ) ), 0, 8 );
		$key = self::CACHE_PREFIX . $product_id . '_' . $sig;

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = $this->compute( $product_id, $limit, $s );
		set_transient( $key, $ids, self::PERF_TTL );
		return $ids;
	}

	private function compute( int $product_id, int $limit, array $s ): array {
		// The viewed product's matching-attribute term IDs, per taxonomy.
		$attr_terms = [];
		foreach ( (array) $s['match_attributes'] as $tax ) {
			$terms = wp_get_post_terms( $product_id, $tax, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$attr_terms[ $tax ] = array_map( 'intval', $terms );
			}
		}

		// Its categories, and the complementary categories they map to.
		$cats     = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
		$cats     = is_wp_error( $cats ) ? [] : array_map( 'intval', $cats );
		$comp_cats = $this->complementary_categories( $cats, (array) $s['category_pairs'] );

		$match_tq = [];
		if ( ! empty( $attr_terms ) ) {
			$match_tq = [ 'relation' => 'OR' ];
			foreach ( $attr_terms as $tax => $ids ) {
				$match_tq[] = [ 'taxonomy' => $tax, 'field' => 'term_id', 'terms' => $ids ];
			}
		}

		$found   = [];
		$exclude = [ $product_id ];
		// Respect the global "never discount" product list — don't recommend those
		// (e.g. a "Priority processing" upsell).
		if ( class_exists( 'DZE_Discounts' ) ) {
			$exclude = array_merge( $exclude, DZE_Discounts::get_exclusions()['products'] );
		}

		$collect = function ( array $tax_query ) use ( &$found, &$exclude, $limit ) {
			if ( count( $found ) >= $limit ) {
				return;
			}
			$need = $limit - count( $found );
			$new  = $this->query_ids( $tax_query, $need + count( $exclude ), $exclude );
			foreach ( $new as $id ) {
				if ( ! in_array( $id, $found, true ) ) {
					$found[]   = $id;
					$exclude[] = $id;
				}
			}
		};

		// Tier 1 — same attribute AND a complementary category (the sweet spot).
		if ( $match_tq && $comp_cats ) {
			$collect( [ 'relation' => 'AND', $match_tq, [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $comp_cats ] ] );
		}
		// Tier 2 — same attribute (e.g. same camo), any category.
		if ( $match_tq ) {
			$collect( $match_tq );
		}
		// Tier 3 — a complementary category (e.g. jacket → pants), any pattern.
		if ( $comp_cats ) {
			$collect( [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $comp_cats ] ] );
		}
		// Tier 4 — same category (thematic fallback so the block is never empty).
		if ( $cats ) {
			$collect( [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cats ] ] );
		}

		return array_slice( $found, 0, $limit );
	}

	/** Categories complementary to $cats, per the configured pairs (bidirectional). */
	private function complementary_categories( array $cats, array $pairs ): array {
		if ( empty( $cats ) || empty( $pairs ) ) {
			return [];
		}
		$out = [];
		foreach ( $pairs as $pair ) {
			$from = (int) ( $pair['from'] ?? 0 );
			$to   = array_map( 'intval', (array) ( $pair['to'] ?? [] ) );
			if ( in_array( $from, $cats, true ) ) {
				$out = array_merge( $out, $to );        // jacket → pants
			}
			if ( array_intersect( $to, $cats ) ) {
				$out[] = $from;                          // pants → jacket
			}
		}
		// Don't recommend the product's own categories as "complementary".
		return array_values( array_diff( array_unique( $out ), $cats ) );
	}

	private function query_ids( array $tax_query, int $limit, array $exclude ): array {
		$args = [
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, $limit ),
			'fields'              => 'ids',
			'orderby'             => 'date',
			'order'               => 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'post__not_in'        => $exclude,
			'tax_query'           => $tax_query,
			// Never recommend an out-of-stock product.
			'meta_query'          => [ [ 'key' => '_stock_status', 'value' => 'outofstock', 'compare' => '!=' ] ],
		];
		$q = new WP_Query( $args );
		return array_map( 'intval', $q->posts );
	}

	private static bool $styles_done = false;

	private function print_styles_once(): void {
		if ( self::$styles_done ) {
			return;
		}
		self::$styles_done = true;
		echo '<style>'
			. '.dze-fbt{margin:2.5em 0;clear:both;}'
			. '.dze-fbt__title{margin:0 0 1em;font-size:1.25em;}'
			. '.dze-fbt__grid{list-style:none;margin:0;padding:0;display:grid;gap:16px;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));}'
			. '.dze-fbt__item{display:flex;flex-direction:column;gap:6px;text-align:center;}'
			. '.dze-fbt__link{display:block;color:inherit;text-decoration:none;}'
			. '.dze-fbt__item img{width:100%;height:auto;border-radius:8px;}'
			. '.dze-fbt__name{display:block;margin-top:8px;font-size:.9em;line-height:1.3;}'
			. '.dze-fbt__price{font-weight:600;}'
			. '.dze-fbt__item .button{width:100%;box-sizing:border-box;}'
			. '</style>';
	}
}
