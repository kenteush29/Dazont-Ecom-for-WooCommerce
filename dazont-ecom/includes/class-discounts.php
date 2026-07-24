<?php
defined( 'ABSPATH' ) || exit;

/**
 * Rule storage and admin UI for both the "Marketing Events" page (sale type —
 * recurring, date-bound promotions, with the AI calendar) and the "Discounts"
 * page (evergreen cart-level rules, set up once). Same storage and save/delete/
 * toggle handlers; the two admin pages differ only in which rule types they
 * show and edit (see types_for_mode()).
 *
 * Rule types:
 *   - sale       : scheduled site-wide % sale (shown as a struck-through price
 *                  across catalog + product pages), with an optional promo
 *                  banner at chosen locations.
 *   - bulk       : "Bulk offer per item" — % off a product's line once its own
 *                  quantity reaches N (the "buy 2+ of the same product" offer).
 *                  Shown in the cart as a "Bundle" fee line.
 *   - bulk_order : "Bulk order" — tiered % off the in-scope cart, gated on an
 *                  optional minimum subtotal and/or minimum total quantity; the
 *                  highest reached quantity tier wins. Applied as the auto
 *                  "Wholesale" coupon.
 *   - autobest   : "Automatic product discount" — a % sale applied automatically
 *                  to a set of products chosen by a strategy (new arrivals, slow
 *                  movers, best-sellers or trending), refreshed weekly.
 *
 * Scope for every rule: whole store, specific categories, or specific products
 * (autobest picks its products automatically instead). Percentage-only.
 *
 * Front-end footprint: the pricing/cart/banner hooks are registered ONLY when
 * at least one rule is currently active (a single autoloaded option read).
 * Nothing is wired on the front end while no promotion is running.
 *
 * ---------------------------------------------------------------------------
 * DISCOUNT COMPATIBILITY RULES (how everything stacks — keep in sync with the
 * admin note on the Discounts page):
 *
 *  1. One marketing event at a time. Two scheduled sales may never overlap in
 *     time (enforced on save/enable).
 *
 *  2. Catalog discounts do not stack with each other. A product touched by both
 *     a scheduled sale AND an automatic discount gets the STRONGER of the two,
 *     never the sum (catalog_percent_for() = max). In practice the automatic
 *     discount also steps aside for any product already in the active event's
 *     scope, so the marketing event always wins (in_active_event_scope()).
 *
 *  3. Cart bulk coupons (Bundle / Wholesale) are computed AFTER catalog
 *     discounts, from the already-reduced price, and applied as auto coupons.
 *     They are the only intentional stacking: a wholesale/bundle incentive on
 *     top of a sale price. A line can never go below zero.
 *
 *  4. Classic (customer-typed) WooCommerce coupons keep working normally and
 *     coexist with our auto coupons (individual_use = false on ours). Our
 *     sale/automatic products register as "on sale", so a classic coupon set to
 *     "Exclude sale items" will correctly skip them — that is the lever to stop
 *     a customer coupon stacking on already-discounted products.
 *
 *  5. If a classic coupon is marked "Individual use only", WooCommerce removes
 *     all other coupons (including ours) while it is applied — standard Woo
 *     behaviour, left untouched.
 * ---------------------------------------------------------------------------
 */
final class DZE_Discounts {

	public const OPTION    = 'dze_discount_rules';
	public const MENU_SLUG        = 'dazont-ecom-discounts';       // Discounts page: bulk / bulk_order (set up once).
	public const MENU_SLUG_EVENTS = 'dazont-ecom-marketing-events'; // Marketing Events page: sale type (recurring, date-bound), + AI calendar.
	public const SAVE_NONCE = 'dze_discounts_save';

	/** Rule types shown on the "Marketing Events" page. */
	private const EVENT_TYPES = [ 'sale' ];
	/** Rule types shown on the "Discounts" page (cart-level, evergreen). */
	private const DISCOUNT_TYPES = [ 'bulk', 'bulk_order', 'autobest' ];

	/** Global "never discount" list, applied to EVERY promotion. */
	public const OPT_EXCLUSIONS = 'dze_discount_exclusions';

	// Sale-price materialisation (writes native _sale_price into product data so
	// weekly feeds/exports — e.g. GMC — pick promotions up).
	public const SYNC_HOOK        = 'dze_sale_sync';
	private const OPT_SYNC_QUEUE  = 'dze_sale_sync_queue';
	private const META_RULE       = '_dze_sale_rule';   // parent flag: which promo manages it.
	private const META_MANAGED    = '_dze_sale_managed'; // per (variation) row flag.
	private const META_PREV       = '_dze_sale_prev';   // original _sale_price to restore.
	private const META_PREV_PRICE = '_dze_price_prev';  // original _price to restore.
	private const META_PREV_FROM  = '_dze_sale_prev_from';
	private const META_PREV_TO    = '_dze_sale_prev_to';
	private const SYNC_CHUNK      = 60;                 // rows processed per background pass.

	/** @var array<string,array>|null Cached active rules for this request. */
	private ?array $active = null;
	/** @var array|null Cached exclusions for this request. */
	private ?array $exclusions = null;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_menu',            [ $this, 'register_menu' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			add_action( 'admin_post_dze_discount_save',   [ $this, 'handle_save' ] );
			add_action( 'admin_post_dze_discount_delete', [ $this, 'handle_delete' ] );
			add_action( 'admin_post_dze_discount_toggle', [ $this, 'handle_toggle' ] );
			add_action( 'admin_post_dze_discount_exclusions', [ $this, 'handle_exclusions_save' ] );
			add_action( 'admin_post_dze_sale_resync',     [ $this, 'handle_resync' ] );
			add_action( 'wp_ajax_dze_auto_count',         [ $this, 'ajax_auto_count' ] );
		}

		// The pricing engine must run on the front end, on cart AJAX and on the
		// Store API (REST) — everywhere WooCommerce computes prices/totals.
		add_action( 'init', [ $this, 'register_engine' ] );

		// Materialise sale prices into product data (for feeds/exports).
		add_action( self::SYNC_HOOK, [ $this, 'run_sale_sync' ] );
		add_action( 'init', [ $this, 'schedule_sale_sync' ] );

		add_shortcode( 'dze_promo_banner', [ $this, 'shortcode_banner' ] );
	}

	// =========================================================================
	// Rule storage
	// =========================================================================

	public static function get_rules(): array {
		$rules = get_option( self::OPTION, [] );
		return is_array( $rules ) ? $rules : [];
	}

	private static function save_rules( array $rules ): void {
		update_option( self::OPTION, $rules, false );
	}

	public static function type_labels(): array {
		return [
			'sale'       => __( 'Scheduled sale (site-wide %)', 'dazont-ecom' ),
			'bulk'       => __( 'Bulk offer per item', 'dazont-ecom' ),
			'bulk_order' => __( 'Bulk order', 'dazont-ecom' ),
			'autobest'   => __( 'Automatic product discount', 'dazont-ecom' ),
		];
	}

	/** Selectable strategies for the "Automatic product discount" type. */
	public static function auto_strategies(): array {
		return [
			'newest'      => __( 'New arrivals — recently published products', 'dazont-ecom' ),
			'slow'        => __( 'Slow movers — little or no recent sales', 'dazont-ecom' ),
			'bestsellers' => __( 'Best-sellers — current top sellers', 'dazont-ecom' ),
			'trending'    => __( 'Trending — sales accelerating lately', 'dazont-ecom' ),
		];
	}

	/**
	 * Tie-breaker order deciding WHICH products win when a strategy matches more
	 * than the cap. Only meaningful for "New arrivals" and "Slow movers" (the
	 * others are already ranked by sales); hidden for those in the editor.
	 */
	public static function auto_priorities(): array {
		return [
			'recent'    => __( 'Most recently added first', 'dazont-ecom' ),
			'oldest'    => __( 'Oldest products first', 'dazont-ecom' ),
			'top_sales' => __( 'Best all-time sellers first', 'dazont-ecom' ),
		];
	}

	/**
	 * Type labels for one admin page: 'events' (recurring, date-bound scheduled
	 * sales — with the AI calendar) or 'discounts' (evergreen cart-level rules,
	 * set up once). Restricts both the list and the Type dropdown on the edit
	 * screen so each page only ever shows/creates its own kind of rule.
	 */
	public static function types_for_mode( string $mode ): array {
		$all = self::type_labels();
		$keys = ( 'events' === $mode ) ? self::EVENT_TYPES : self::DISCOUNT_TYPES;
		return array_intersect_key( $all, array_flip( $keys ) );
	}

	/**
	 * Rules that are enabled and (for sales) within their schedule window.
	 */
	public function get_active_rules(): array {
		if ( null !== $this->active ) {
			return $this->active;
		}
		$now          = time();
		$current_lang = DZE_Wpml::current_language();
		$active       = [];
		foreach ( self::get_rules() as $id => $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			if ( ( $rule['type'] ?? '' ) === 'sale' ) {
				[ $start, $end ] = $this->window_ts( $rule );
				if ( $now < $start || $now > $end ) {
					continue;
				}
				// Per-language activation applies to scheduled marketing events
				// only: the default language is always eligible; a non-default
				// language is eligible only when the banner text is translated
				// for it (see rule_effective_languages). WPML only. Evergreen
				// discounts (bulk / bulk_order) are store-wide and never gated
				// by language.
				if ( $current_lang !== '' && ! in_array( $current_lang, $this->rule_effective_languages( $rule ), true ) ) {
					continue;
				}
			}
			$active[ $id ] = $rule;
		}
		$this->active = $active;
		return $active;
	}

	/**
	 * A rule's active window as [ start_ts, end_ts ] in the site timezone.
	 * Dates are day-granular: start snaps to 00:00:00, end to 23:59:59
	 * (inclusive). Missing bounds become ±infinity.
	 */
	public function window_ts( array $rule ): array {
		$tz    = wp_timezone();
		$start = PHP_INT_MIN;
		$end   = PHP_INT_MAX;
		try {
			if ( ! empty( $rule['start'] ) ) {
				$start = ( new DateTimeImmutable( $rule['start'] . ' 00:00:00', $tz ) )->getTimestamp();
			}
			if ( ! empty( $rule['end'] ) ) {
				$end = ( new DateTimeImmutable( $rule['end'] . ' 23:59:59', $tz ) )->getTimestamp();
			}
		} catch ( \Exception $e ) {
			return [ PHP_INT_MIN, PHP_INT_MAX ];
		}
		return [ $start, $end ];
	}

	/** Status label for a rule: active | scheduled | passed | inactive. */
	public function rule_status( array $rule ): string {
		if ( empty( $rule['enabled'] ) ) {
			return 'inactive';
		}
		if ( ( $rule['type'] ?? '' ) === 'sale' ) {
			[ $start, $end ] = $this->window_ts( $rule );
			$now = time();
			if ( $now < $start ) {
				return 'scheduled';
			}
			if ( $now > $end ) {
				return 'passed';
			}
		}
		return 'active';
	}

	private function rules_of_type( string $type ): array {
		return array_filter( $this->get_active_rules(), static fn( $r ) => ( $r['type'] ?? '' ) === $type );
	}

	/**
	 * Language codes a rule is effectively active in (WPML). The default
	 * language is always included; a non-default language is included only when
	 * the rule's banner text is translated for it. Empty when WPML is inactive.
	 */
	public function rule_effective_languages( array $rule ): array {
		if ( ! DZE_Wpml::is_active() ) {
			return [];
		}
		$default    = DZE_Wpml::default_language();
		$selected   = (array) ( $rule['languages'] ?? [] );
		$i18n       = (array) ( $rule['banner_text_i18n'] ?? [] );
		$has_banner = ! empty( $rule['banner_enabled'] ) && trim( (string) ( $rule['banner_text'] ?? '' ) ) !== '';

		$out = [];
		foreach ( DZE_Wpml::get_active_languages() as $lang ) {
			$code = $lang['code'];
			if ( ! empty( $selected ) && ! in_array( $code, $selected, true ) ) {
				continue; // not targeted by this rule.
			}
			if ( $code !== $default && $has_banner && empty( $i18n[ $code ] ) ) {
				continue; // non-default language requires a translated banner.
			}
			$out[] = $code;
		}
		return $out;
	}

	/**
	 * Standard product-page banner positions → [hook, priority].
	 */
	public static function product_positions(): array {
		return [
			'before_product'     => [ 'label' => __( 'Above the product (default)', 'dazont-ecom' ), 'hook' => 'woocommerce_before_single_product', 'prio' => 10 ],
			'before_title'       => [ 'label' => __( 'Before the title', 'dazont-ecom' ),            'hook' => 'woocommerce_single_product_summary', 'prio' => 4 ],
			'after_title'        => [ 'label' => __( 'After the title', 'dazont-ecom' ),             'hook' => 'woocommerce_single_product_summary', 'prio' => 6 ],
			'before_price'       => [ 'label' => __( 'Before the price', 'dazont-ecom' ),            'hook' => 'woocommerce_single_product_summary', 'prio' => 9 ],
			'before_add_to_cart' => [ 'label' => __( 'Before Add to cart', 'dazont-ecom' ),         'hook' => 'woocommerce_single_product_summary', 'prio' => 29 ],
			'after_add_to_cart'  => [ 'label' => __( 'After Add to cart', 'dazont-ecom' ),          'hook' => 'woocommerce_single_product_summary', 'prio' => 31 ],
		];
	}

	/** True when two sale windows overlap (open-ended = ±infinity). */
	private function sale_windows_overlap( array $a, array $b ): bool {
		[ $a_start, $a_end ] = $this->window_ts( $a );
		[ $b_start, $b_end ] = $this->window_ts( $b );
		return $a_start <= $b_end && $b_start <= $a_end;
	}

	/**
	 * Returns the title of an enabled sale whose window overlaps $rule, or ''
	 * (used to forbid two promotions running at the same time).
	 */
	private function conflicting_sale( array $rule ): string {
		if ( ( $rule['type'] ?? '' ) !== 'sale' ) {
			return '';
		}
		foreach ( self::get_rules() as $oid => $other ) {
			if ( $oid === ( $rule['id'] ?? '' ) ) {
				continue;
			}
			if ( ( $other['type'] ?? '' ) === 'sale' && ! empty( $other['enabled'] ) && $this->sale_windows_overlap( $rule, $other ) ) {
				return (string) ( $other['title'] !== '' ? $other['title'] : $oid );
			}
		}
		return '';
	}

	// =========================================================================
	// Front-end engine registration
	// =========================================================================

	public function register_engine(): void {
		// Skip pure admin screens (but keep cart AJAX and REST/Store API).
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$active = $this->get_active_rules();
		if ( empty( $active ) ) {
			return; // zero front-end footprint when no promo is running.
		}

		$has_sale = ! empty( $this->rules_of_type( 'sale' ) );
		// Best-seller boost: an automatic % sale on the current top sellers.
		$has_autobest = ! empty( $this->rules_of_type( 'autobest' ) );
		// Both bulk discount types apply as auto virtual coupons (see below), so
		// the saving shows as a real promo-code line in the cart and checkout.
		$has_bulk = ! empty( $this->rules_of_type( 'bulk' ) ) || ! empty( $this->rules_of_type( 'bulk_order' ) );

		// Catalog price filters power both scheduled sales and the best-seller
		// boost — a struck-through price on the affected products.
		if ( $has_sale || $has_autobest ) {
			add_filter( 'woocommerce_product_get_price',                 [ $this, 'filter_price' ], 20, 2 );
			add_filter( 'woocommerce_product_get_sale_price',            [ $this, 'filter_price' ], 20, 2 );
			add_filter( 'woocommerce_product_variation_get_price',       [ $this, 'filter_price' ], 20, 2 );
			add_filter( 'woocommerce_product_variation_get_sale_price',  [ $this, 'filter_price' ], 20, 2 );
			add_filter( 'woocommerce_variation_prices_price',            [ $this, 'filter_variation_price' ], 20, 3 );
			add_filter( 'woocommerce_variation_prices_sale_price',       [ $this, 'filter_variation_price' ], 20, 3 );
			add_filter( 'woocommerce_get_variation_prices_hash',        [ $this, 'filter_prices_hash' ], 20, 2 );

			// Coupons: make our dynamic sale honour the coupon "Exclude sale
			// items" setting (WooCommerce otherwise only knows native sales).
			add_filter( 'woocommerce_coupon_is_valid_for_product', [ $this, 'coupon_exclude_sale' ], 20, 4 );
		}

		if ( $has_sale ) {

			// Homepage / hero image swap for big events (auto-reverts after).
			if ( $this->build_hero_map() ) {
				add_filter( 'wp_get_attachment_image_src', [ $this, 'swap_image_src' ], 20, 4 );
				add_filter( 'wp_get_attachment_url',       [ $this, 'swap_image_url' ], 20, 2 );
			}

			// Banner: single location per rule (Top / Below header / Product).
			$positions = self::product_positions();
			add_action( 'wp_body_open', function () { $this->render_location( 'top' ); } );
			if ( defined( 'ASTRA_THEME_VERSION' ) || function_exists( 'astra_header_markup' ) ) {
				add_action( 'astra_header_after', function () { $this->render_location( 'below_header' ); } );
			}
			foreach ( $this->rules_of_type( 'sale' ) as $rule ) {
				if ( empty( $rule['banner_enabled'] ) ) {
					continue;
				}
				// Product-page banner at the chosen standard WooCommerce position.
				if ( ( $rule['banner_location'] ?? '' ) === 'product' ) {
					$pos = $positions[ $rule['product_position'] ?? 'before_product' ] ?? $positions['before_product'];
					add_action( $pos['hook'], function () use ( $rule ) { $this->render_single_banner( $rule ); }, $pos['prio'] );
				}
				// Optional user-defined hooks (free choice — any Astra hook).
				if ( ! empty( $rule['banner_hooks'] ) ) {
					foreach ( $this->parse_hooks( $rule['banner_hooks'] ) as $hook ) {
						add_action( $hook, function () use ( $rule ) { $this->render_single_banner( $rule ); } );
					}
				}
			}
		}

		if ( $has_bulk ) {
			// Apply the two bulk discounts as auto virtual coupons, so each shows
			// as a real "Coupon: Bundle / Wholesale" line in the cart and at
			// checkout — a promo-code simulation, no code for the customer to type.
			add_action( 'woocommerce_before_calculate_totals', [ $this, 'prepare_cart_coupons' ], 5, 1 );
			add_filter( 'woocommerce_get_shop_coupon_data',     [ $this, 'virtual_coupon_data' ], 10, 2 );
			add_filter( 'woocommerce_cart_totals_coupon_label', [ $this, 'coupon_label' ], 10, 2 );
			add_filter( 'woocommerce_cart_totals_coupon_html',  [ $this, 'coupon_html' ], 10, 3 );
			add_filter( 'woocommerce_coupon_message',           [ $this, 'silence_coupon_message' ], 10, 3 );
		}
	}

	// =========================================================================
	// Scope + pricing helpers
	// =========================================================================

	private function discounted( float $price, float $percent ): float {
		if ( $price <= 0 || $percent <= 0 ) {
			return $price;
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return round( $price * ( 1 - $percent / 100 ), $decimals );
	}

	private function product_in_scope( array $rule, int $product_id, int $parent_id = 0 ): bool {
		$scope = $rule['scope'] ?? 'all';
		if ( $scope === 'all' ) {
			return true;
		}
		$match_id = $parent_id ?: $product_id;
		if ( $scope === 'categories' ) {
			$cats = array_map( 'intval', $rule['category_ids'] ?? [] );
			return ! empty( $cats ) && has_term( $cats, 'product_cat', $match_id );
		}
		if ( $scope === 'products' ) {
			$ids = array_map( 'intval', $rule['product_ids'] ?? [] );
			return in_array( $match_id, $ids, true ) || in_array( $product_id, $ids, true );
		}
		return false;
	}

	private function sale_percent_for( \WC_Product $product ): float {
		$pid    = $product->get_id();
		$parent = $product->get_parent_id();
		$best   = 0.0;
		foreach ( $this->rules_of_type( 'sale' ) as $rule ) {
			if ( $this->product_in_scope( $rule, $pid, $parent ) ) {
				$best = max( $best, (float) ( $rule['percent'] ?? 0 ) );
			}
		}
		return $best;
	}

	/** Catalog discount % for a product: the strongest of any sale or best-seller boost. */
	private function catalog_percent_for( \WC_Product $product ): float {
		if ( $this->is_excluded( $product->get_id(), $product->get_parent_id() ) ) {
			return 0.0;
		}
		return max( $this->sale_percent_for( $product ), $this->autobest_percent_for( $product ) );
	}

	/** @var array<int,float>|null product_id => best-seller-boost %, this request. */
	private ?array $autobest_map = null;

	/** Best-seller-boost % for a product (0 if it isn't a currently-boosted top seller). */
	private function autobest_percent_for( \WC_Product $product ): float {
		$map  = $this->autobest_map();
		if ( empty( $map ) ) {
			return 0.0;
		}
		// The map is keyed by default-language product IDs (WPML dedupe), so also
		// check the viewed product's canonical translation — the discount then
		// applies on every language version of the product.
		$ids = [ $product->get_id(), $product->get_parent_id() ];
		if ( DZE_Wpml::is_active() ) {
			$ids[] = DZE_Wpml::canonical_id( $product->get_id(), 'product' );
			if ( $product->get_parent_id() ) {
				$ids[] = DZE_Wpml::canonical_id( $product->get_parent_id(), 'product' );
			}
		}
		$best = 0.0;
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			if ( isset( $map[ $id ] ) ) {
				$best = max( $best, $map[ $id ] );
			}
		}
		return $best;
	}

	/**
	 * Builds (and caches for the request) a map of product_id => discount % for
	 * every active "Automatic product discount" rule. Each rule's product list is
	 * cached one week so the ranking query runs at most once a week per rule.
	 *
	 * Compatibility rule (see the class docblock): products already covered by
	 * the active marketing event (scheduled sale) are removed here, so an event
	 * always takes priority over an automatic discount — they never fight over
	 * the same product.
	 */
	private function autobest_map(): array {
		if ( null !== $this->autobest_map ) {
			return $this->autobest_map;
		}
		$map = [];
		foreach ( $this->rules_of_type( 'autobest' ) as $id => $rule ) {
			$percent = (float) ( $rule['percent'] ?? 0 );
			if ( $percent <= 0 ) {
				continue;
			}
			$strategy = in_array( $rule['strategy'] ?? '', array_keys( self::auto_strategies() ), true ) ? $rule['strategy'] : 'bestsellers';
			$priority = in_array( $rule['priority'] ?? '', array_keys( self::auto_priorities() ), true ) ? $rule['priority'] : 'recent';
			$top_n    = max( 1, (int) ( $rule['top_n'] ?? 20 ) );
			$lookback = max( 1, (int) ( $rule['lookback_days'] ?? 30 ) );
			$key      = 'dze_auto_' . md5( $id . '|' . $strategy . '|' . $priority . '|' . $top_n . '|' . $lookback );
			$ids      = get_transient( $key );
			if ( ! is_array( $ids ) ) {
				$ids = $this->auto_product_ids( $strategy, $top_n, $lookback, $priority );
				set_transient( $key, $ids, WEEK_IN_SECONDS );
			}
			foreach ( $ids as $pid ) {
				if ( $this->in_active_event_scope( (int) $pid ) ) {
					continue; // event wins.
				}
				$map[ $pid ] = max( $map[ $pid ] ?? 0.0, $percent );
			}
		}
		return $this->autobest_map = $map;
	}

	/** True when an active scheduled-sale event already covers this product. */
	private function in_active_event_scope( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}
		$parent = (int) wp_get_post_parent_id( $product_id );
		foreach ( $this->rules_of_type( 'sale' ) as $rule ) {
			if ( $this->product_in_scope( $rule, $product_id, $parent ) ) {
				return true;
			}
		}
		return false;
	}

	/** The global "never discount" list: [ 'products' => int[], 'categories' => int[] ]. */
	public static function get_exclusions(): array {
		$e = get_option( self::OPT_EXCLUSIONS, [] );
		$e = is_array( $e ) ? $e : [];
		return [
			'products'   => array_values( array_unique( array_map( 'intval', (array) ( $e['products'] ?? [] ) ) ) ),
			'categories' => array_values( array_unique( array_map( 'intval', (array) ( $e['categories'] ?? [] ) ) ) ),
		];
	}

	private function exclusions(): array {
		if ( null === $this->exclusions ) {
			$this->exclusions = self::get_exclusions();
		}
		return $this->exclusions;
	}

	/** True when a product is on the global "never discount" list (by id or category). */
	private function is_excluded( int $product_id, int $parent_id = 0 ): bool {
		$ex = $this->exclusions();
		if ( empty( $ex['products'] ) && empty( $ex['categories'] ) ) {
			return false;
		}
		$ids = [ $product_id, $parent_id ];
		if ( DZE_Wpml::is_active() ) {
			$ids[] = DZE_Wpml::canonical_id( $product_id, 'product' );
			if ( $parent_id ) {
				$ids[] = DZE_Wpml::canonical_id( $parent_id, 'product' );
			}
		}
		foreach ( $ids as $id ) {
			if ( $id && in_array( (int) $id, $ex['products'], true ) ) {
				return true;
			}
		}
		if ( ! empty( $ex['categories'] ) ) {
			$match = $parent_id ?: $product_id;
			if ( has_term( $ex['categories'], 'product_cat', $match ) ) {
				return true;
			}
		}
		return false;
	}

	/** SQL AND-clause excluding the "never discount" products/categories (alias `p`). */
	private function exclusion_sql( string $alias = 'p' ): string {
		global $wpdb;
		$ex  = self::get_exclusions();
		$out = '';
		if ( ! empty( $ex['products'] ) ) {
			$ids  = implode( ',', array_map( 'intval', $ex['products'] ) );
			$out .= " AND {$alias}.ID NOT IN ({$ids}) ";
		}
		if ( ! empty( $ex['categories'] ) ) {
			$cids = implode( ',', array_map( 'intval', $ex['categories'] ) );
			$out .= " AND {$alias}.ID NOT IN ( SELECT tr.object_id FROM {$wpdb->term_relationships} tr
			         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			         WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN ({$cids}) ) ";
		}
		return $out;
	}

	/** Out-of-stock products are ALWAYS excluded from automatic discounts. */
	private function in_stock_sql( string $alias = 'p' ): string {
		global $wpdb;
		return " {$alias}.ID NOT IN ( SELECT pm.post_id FROM {$wpdb->postmeta} pm WHERE pm.meta_key = '_stock_status' AND pm.meta_value = 'outofstock' ) ";
	}

	/**
	 * Under WPML every product is duplicated once per language, which would count
	 * each product several times. This clause restricts a query to the default
	 * language's products only, so counts and selections match the real catalogue
	 * size. Empty when WPML (or its translations table) is absent.
	 */
	private function default_lang_sql( string $alias = 'p' ): string {
		global $wpdb;
		if ( ! DZE_Wpml::is_active() ) {
			return '';
		}
		$def = DZE_Wpml::default_language();
		if ( '' === $def ) {
			return '';
		}
		$table = $wpdb->prefix . 'icl_translations';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return '';
		}
		$def = esc_sql( $def );
		return " AND {$alias}.ID IN ( SELECT t.element_id FROM {$table} t WHERE t.element_type = 'post_product' AND t.language_code = '{$def}' ) ";
	}

	/**
	 * Priority ordering (posts aliased `p`), used to decide WHICH products win
	 * when a strategy matches more than the cap. Returns [ join_sql, order_sql ].
	 */
	private function priority_sql( string $priority ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		$has   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		if ( 'top_sales' === $priority && $has ) {
			return [
				" LEFT JOIN ( SELECT product_id, SUM(product_qty) tot FROM {$table} GROUP BY product_id ) sp ON sp.product_id = p.ID ",
				' ORDER BY COALESCE(sp.tot,0) DESC, p.post_date_gmt DESC ',
			];
		}
		if ( 'oldest' === $priority ) {
			return [ '', ' ORDER BY p.post_date_gmt ASC ' ];
		}
		return [ '', ' ORDER BY p.post_date_gmt DESC ' ]; // recent (default).
	}

	/**
	 * SQL (with %s/%d placeholders) selecting candidate `product_id`s for a
	 * strategy — without the final LIMIT — plus its params. Out-of-stock products
	 * are always excluded. 'newest'/'slow' honour the priority ordering;
	 * 'bestsellers'/'trending' keep their intrinsic sales ranking.
	 *
	 * @return array{0:string,1:array}|null  [ sql, params ] or null if analytics missing.
	 */
	private function auto_candidates_sql( string $strategy, int $lookback_days, string $priority ): ?array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - $lookback_days * DAY_IN_SECONDS );
		$oos   = $this->in_stock_sql( 'p' );
		$lang  = $this->default_lang_sql( 'p' ); // dedupe WPML translations.
		$excl  = $this->exclusion_sql( 'p' );    // global "never discount" list.
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		$has   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

		if ( 'newest' === $strategy ) {
			[ $join, $order ] = $this->priority_sql( $priority );
			$sql = "SELECT p.ID AS product_id FROM {$wpdb->posts} p {$join}
			        WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_date_gmt >= %s AND {$oos} {$lang} {$excl}
			        {$order}";
			return [ $sql, [ $since ] ];
		}

		if ( ! $has ) {
			return null; // the remaining strategies need WooCommerce Analytics.
		}

		if ( 'slow' === $strategy ) {
			[ $join, $order ] = $this->priority_sql( $priority );
			$sql = "SELECT p.ID AS product_id FROM {$wpdb->posts} p {$join}
			        WHERE p.post_type = 'product' AND p.post_status = 'publish' AND {$oos} {$lang} {$excl}
			          AND p.ID NOT IN ( SELECT DISTINCT l.product_id FROM {$table} l WHERE l.date_created >= %s )
			        {$order}";
			return [ $sql, [ $since ] ];
		}

		if ( 'trending' === $strategy ) {
			$mid  = gmdate( 'Y-m-d H:i:s', time() - (int) ceil( $lookback_days / 2 ) * DAY_IN_SECONDS );
			$sql  = "SELECT l.product_id FROM {$table} l
			         INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
			         WHERE p.post_status = 'publish' AND p.post_type = 'product' AND l.date_created >= %s AND {$oos} {$lang} {$excl}
			         GROUP BY l.product_id
			         HAVING SUM(CASE WHEN l.date_created >= %s THEN l.product_qty ELSE 0 END)
			              - SUM(CASE WHEN l.date_created <  %s THEN l.product_qty ELSE 0 END) > 0
			         ORDER BY SUM(CASE WHEN l.date_created >= %s THEN l.product_qty ELSE 0 END)
			                - SUM(CASE WHEN l.date_created <  %s THEN l.product_qty ELSE 0 END) DESC";
			return [ $sql, [ $since, $mid, $mid, $mid, $mid ] ];
		}

		// Best-sellers — most units sold in the window.
		$sql = "SELECT l.product_id FROM {$table} l
		        INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
		        WHERE p.post_status = 'publish' AND p.post_type = 'product' AND l.date_created >= %s AND {$oos} {$lang} {$excl}
		        GROUP BY l.product_id
		        ORDER BY SUM(l.product_qty) DESC";
		return [ $sql, [ $since ] ];
	}

	/** Capped list of product IDs for a strategy. */
	private function auto_product_ids( string $strategy, int $top_n, int $lookback_days, string $priority = 'recent' ): array {
		global $wpdb;
		$built = $this->auto_candidates_sql( $strategy, $lookback_days, $priority );
		if ( null === $built ) {
			return [];
		}
		[ $sql, $params ] = $built;
		$params[] = $top_n;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders in $sql are bound via prepare().
		$ids = $wpdb->get_col( $wpdb->prepare( $sql . ' LIMIT %d', $params ) );
		return array_map( 'intval', (array) $ids );
	}

	/** Hard safety ceiling for a single automatic-discount rule's product list. */
	private const AUTO_MAX = 100000;
	/** Max product names returned for the preview popup (payload guard). */
	private const AUTO_LIST_MAX = 300;

	/**
	 * How many products a strategy currently matches (before the cap), plus the
	 * actual list of products that would be discounted (capped for payload) —
	 * powers the "preview" counter and popup in the editor.
	 *
	 * @return array{total:int,applied:int,products:array<int,array{id:int,name:string}>,shown:int}
	 */
	public function auto_count( string $strategy, int $top_n, int $lookback_days, string $priority = 'recent' ): array {
		global $wpdb;
		$built = $this->auto_candidates_sql( $strategy, $lookback_days, $priority );
		if ( null === $built ) {
			return [ 'total' => 0, 'applied' => 0, 'products' => [], 'shown' => 0 ];
		}
		[ $sql, $params ] = $built;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders bound via prepare().
		$total   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( {$sql} ) t", $params ) );
		$applied = min( $total, $top_n );

		$list_ids = $this->auto_product_ids( $strategy, min( $applied, self::AUTO_LIST_MAX ), $lookback_days, $priority );
		$products = [];
		foreach ( $list_ids as $id ) {
			$p = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( $p ) {
				$products[] = [ 'id' => (int) $id, 'name' => $p->get_name() ];
			}
		}
		return [ 'total' => $total, 'applied' => $applied, 'products' => $products, 'shown' => count( $products ) ];
	}

	private function bulk_percent_for( \WC_Product $product, int $qty ): float {
		if ( $this->is_excluded( $product->get_id(), $product->get_parent_id() ) ) {
			return 0.0;
		}
		$pid    = $product->get_id();
		$parent = $product->get_parent_id();
		$best   = 0.0;
		foreach ( $this->rules_of_type( 'bulk' ) as $rule ) {
			$threshold = (int) ( $rule['threshold'] ?? 0 );
			if ( $threshold > 0 && $qty >= $threshold && $this->product_in_scope( $rule, $pid, $parent ) ) {
				$best = max( $best, (float) ( $rule['percent'] ?? 0 ) );
			}
		}
		return $best;
	}

	// =========================================================================
	// Price filters (sale)
	// =========================================================================

	public function filter_price( $price, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $price;
		}
		$percent = $this->catalog_percent_for( $product );
		if ( $percent <= 0 ) {
			return $price;
		}
		$regular = (float) $product->get_regular_price();
		if ( $regular <= 0 ) {
			return $price;
		}
		return $this->discounted( $regular, $percent );
	}

	public function filter_variation_price( $price, $variation, $product ) {
		if ( ! $variation instanceof \WC_Product ) {
			return $price;
		}
		$percent = $this->catalog_percent_for( $variation );
		if ( $percent <= 0 ) {
			return $price;
		}
		$regular = (float) $variation->get_regular_price();
		if ( $regular <= 0 ) {
			return $price;
		}
		return $this->discounted( $regular, $percent );
	}

	public function filter_prices_hash( $hash, $product ) {
		$sig = [];
		foreach ( $this->rules_of_type( 'sale' ) as $id => $rule ) {
			$sig[] = $id . ':' . ( $rule['percent'] ?? 0 );
		}
		foreach ( $this->rules_of_type( 'autobest' ) as $id => $rule ) {
			$sig[] = 'ab' . $id . ':' . ( $rule['percent'] ?? 0 );
		}
		if ( $sig ) {
			$hash['dze_sale'] = md5( implode( '|', $sig ) );
		}
		return $hash;
	}

	// =========================================================================
	// Cart discounts
	// =========================================================================

	/** Auto virtual-coupon codes (lower-case; WooCommerce normalises codes). */
	public const COUPON_BUNDLE    = 'dze_bundle';
	public const COUPON_WHOLESALE = 'dze_wholesale';

	/** @var array<string,float> Coupon code => discount amount, this request. */
	private array $coupon_amounts = [];

	/**
	 * Computes the two bulk discounts for the current cart and applies each, when
	 * positive, as an auto virtual coupon (Bundle = per-item offer, Wholesale =
	 * whole-order tier). Runs on the first totals pass; the codes then persist in
	 * the cart's applied_coupons for the rest of the request.
	 */
	public function prepare_cart_coupons( $cart ): void {
		if ( ! $cart instanceof \WC_Cart || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return; // already computed + applied on the first pass this request.
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		// Bundle: per-item bulk offer (same product, qty ≥ threshold).
		$bundle = 0.0;
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$qty = (int) $item['quantity'];
			$pct = $this->bulk_percent_for( $product, $qty );
			if ( $pct > 0 ) {
				$bundle += ( (float) $product->get_price() * $qty ) * ( $pct / 100 );
			}
		}

		// Wholesale: winning bulk-order tier applied to its in-scope subtotal.
		$wholesale = 0.0;
		[ $wholesale_rule, $wholesale_pct ] = $this->winning_bulk_order( $cart );
		if ( $wholesale_rule ) {
			$subtotal = 0.0;
			foreach ( $cart->get_cart() as $item ) {
				$product = $item['data'] ?? null;
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				if ( $this->is_excluded( $product->get_id(), $product->get_parent_id() ) ) {
					continue;
				}
				if ( $this->product_in_scope( $wholesale_rule, $product->get_id(), $product->get_parent_id() ) ) {
					$subtotal += (float) $product->get_price() * (int) $item['quantity'];
				}
			}
			$wholesale = $subtotal * ( $wholesale_pct / 100 );
		}

		$this->coupon_amounts = [
			self::COUPON_BUNDLE    => round( $bundle, $decimals ),
			self::COUPON_WHOLESALE => round( $wholesale, $decimals ),
		];

		// Add/remove each code by manipulating the array directly (calling
		// remove_coupon() here would recurse into calculate_totals()).
		foreach ( $this->coupon_amounts as $code => $amount ) {
			$has = in_array( $code, $cart->applied_coupons, true );
			if ( $amount > 0 && ! $has ) {
				$cart->applied_coupons[] = $code;
			} elseif ( $amount <= 0 && $has ) {
				$cart->applied_coupons = array_values( array_diff( $cart->applied_coupons, [ $code ] ) );
			}
		}
	}

	/** Supplies WooCommerce with on-the-fly data for our two virtual coupons. */
	public function virtual_coupon_data( $data, $code ) {
		$code = strtolower( (string) $code );
		if ( empty( $this->coupon_amounts[ $code ] ) || $this->coupon_amounts[ $code ] <= 0 ) {
			return $data;
		}
		return [
			'id'                         => -1,
			'amount'                     => $this->coupon_amounts[ $code ],
			'discount_type'              => 'fixed_cart',
			'individual_use'             => false,
			'usage_limit'                => '',
			'usage_limit_per_user'       => '',
			'limit_usage_to_x_items'     => '',
			'usage_count'                => '',
			'expiry_date'                => '',
			'free_shipping'              => false,
			'exclude_sale_items'         => false,
			'minimum_amount'             => '',
			'maximum_amount'             => '',
			'product_ids'                => [],
			'exclude_product_ids'        => [],
			'product_categories'         => [],
			'exclude_product_categories' => [],
		];
	}

	/** Friendly label shown in the cart totals instead of the raw coupon code. */
	public function coupon_label( $label, $coupon ) {
		$code = $coupon instanceof \WC_Coupon ? $coupon->get_code() : '';
		$map  = [
			self::COUPON_BUNDLE    => __( 'Bundle', 'dazont-ecom' ),
			self::COUPON_WHOLESALE => __( 'Wholesale', 'dazont-ecom' ),
		];
		return $map[ $code ] ?? $label;
	}

	/** Drops the "[Remove]" link for our auto coupons (the customer can't undo them). */
	public function coupon_html( $coupon_html, $coupon, $discount_amount_html ) {
		$code = $coupon instanceof \WC_Coupon ? $coupon->get_code() : '';
		if ( in_array( $code, [ self::COUPON_BUNDLE, self::COUPON_WHOLESALE ], true ) ) {
			return $discount_amount_html;
		}
		return $coupon_html;
	}

	/** Suppresses the "Coupon applied successfully" notice for our auto coupons. */
	public function silence_coupon_message( $msg, $msg_code, $coupon ) {
		$code = $coupon instanceof \WC_Coupon ? $coupon->get_code() : '';
		if ( in_array( $code, [ self::COUPON_BUNDLE, self::COUPON_WHOLESALE ], true ) ) {
			return '';
		}
		return $msg;
	}

	/**
	 * Winning bulk-order rule for the cart, as [ rule|null, percent ]. Each rule
	 * gates on an optional minimum subtotal and/or minimum total quantity (0 = no
	 * requirement; any set requirement must be met — AND). Within a rule the
	 * highest matching quantity tier wins; across rules the biggest total saving
	 * wins — always in the customer's favour.
	 */
	private function winning_bulk_order( \WC_Cart $cart ): array {
		$best_rule   = null;
		$best_pct    = 0.0;
		$best_amount = 0.0;
		foreach ( $this->rules_of_type( 'bulk_order' ) as $rule ) {
			$subtotal = 0.0;
			$qty      = 0;
			foreach ( $cart->get_cart() as $item ) {
				$product = $item['data'] ?? null;
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				if ( $this->is_excluded( $product->get_id(), $product->get_parent_id() ) ) {
					continue;
				}
				if ( ! $this->product_in_scope( $rule, $product->get_id(), $product->get_parent_id() ) ) {
					continue;
				}
				$subtotal += (float) $product->get_price() * (int) $item['quantity'];
				$qty      += (int) $item['quantity'];
			}
			if ( $subtotal <= 0 ) {
				continue;
			}

			$min_sub = (float) ( $rule['min_subtotal'] ?? 0 );
			$min_qty = (int) ( $rule['min_qty'] ?? 0 );
			if ( $min_sub > 0 && $subtotal < $min_sub ) {
				continue;
			}
			if ( $min_qty > 0 && $qty < $min_qty ) {
				continue;
			}

			// Highest tier whose quantity threshold is reached (strongest wins).
			$percent = 0.0;
			foreach ( (array) ( $rule['tiers'] ?? [] ) as $tier ) {
				$t_qty = (int) ( $tier['qty'] ?? 0 );
				$t_pct = (float) ( $tier['percent'] ?? 0 );
				if ( $qty >= $t_qty && $t_pct > $percent ) {
					$percent = $t_pct;
				}
			}
			if ( $percent <= 0 ) {
				continue;
			}

			$amount = $subtotal * ( $percent / 100 );
			if ( $amount > $best_amount ) {
				$best_amount = $amount;
				$best_pct    = $percent;
				$best_rule   = $rule;
			}
		}
		return [ $best_rule, $best_pct ];
	}

	// =========================================================================
	// Sale-price materialisation (AUTOMATIC product discounts only)
	//
	// The runtime filters above keep the storefront correct instantly. This
	// additionally writes WooCommerce's native _sale_price into the product data
	// for AUTOMATIC discounts (slow movers, best-sellers, …) so data-level
	// consumers — feed plugins, the weekly WPML/GMC export — see them. Marketing
	// events are intentionally excluded: they go to Google via the promotion API
	// over the regular feed price, so materialising them would double-count.
	// Products we touch are flagged and their original sale price/schedule is
	// stashed, so releasing a discount restores exactly what was there before.
	// Runs in the background, chunked, on every discount change and once a week.
	// =========================================================================

	/** Unschedules the weekly sync (called on plugin deactivation). */
	public static function clear_sale_sync(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::SYNC_HOOK );
		}
		$ts = wp_next_scheduled( self::SYNC_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::SYNC_HOOK );
		}
	}

	public function schedule_sale_sync(): void {
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_next_scheduled_action( self::SYNC_HOOK ) ) {
				as_schedule_recurring_action( time() + 300, WEEK_IN_SECONDS, self::SYNC_HOOK, [], 'dazont-ecom' );
			}
		} elseif ( ! wp_next_scheduled( self::SYNC_HOOK ) ) {
			wp_schedule_event( time() + 300, 'weekly', self::SYNC_HOOK );
		}
	}

	/** Rebuild the desired state and kick a background pass (called on rule change). */
	public function queue_sale_sync(): void {
		delete_option( self::OPT_SYNC_QUEUE ); // force a fresh diff on the next pass.
		$this->kick_sale_sync();
	}

	private function kick_sale_sync(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::SYNC_HOOK, [], 'dazont-ecom' );
		} else {
			// A single event (+15s) is far from the weekly recurring one, so WP's
			// duplicate guard won't confuse the two.
			wp_schedule_single_event( time() + 15, self::SYNC_HOOK );
		}
	}

	/** Background worker: applies/releases native sale prices, a chunk at a time. */
	public function run_sale_sync(): void {
		$queue = get_option( self::OPT_SYNC_QUEUE, null );
		if ( ! is_array( $queue ) ) {
			$desired = $this->materialize_desired();
			$managed = $this->managed_sale_ids();
			$set     = [];
			foreach ( $desired as $pid => $pct ) {
				$set[] = [ 'id' => (int) $pid, 'pct' => (float) $pct ];
			}
			$queue = [
				'set'     => $set,
				'release' => array_values( array_diff( $managed, array_keys( $desired ) ) ),
			];
		}

		$processed = 0;
		while ( $processed < self::SYNC_CHUNK && ! empty( $queue['release'] ) ) {
			$this->release_sale( (int) array_pop( $queue['release'] ) );
			$processed++;
		}
		while ( $processed < self::SYNC_CHUNK && ! empty( $queue['set'] ) ) {
			$this->apply_sale( (array) array_pop( $queue['set'] ) );
			$processed++;
		}

		if ( empty( $queue['set'] ) && empty( $queue['release'] ) ) {
			delete_option( self::OPT_SYNC_QUEUE );
		} else {
			update_option( self::OPT_SYNC_QUEUE, $queue, false );
			$this->kick_sale_sync();
		}
	}

	/**
	 * Desired sale state: [ product_id => pct ] for AUTOMATIC product discounts
	 * only (slow movers, best-sellers, new arrivals, trending). Marketing events
	 * are deliberately NOT materialised: they reach Google via the promotion API
	 * over the regular feed price, so writing their sale price into the feed too
	 * would double-count. Their live on-site display is handled by the runtime
	 * price filters.
	 */
	private function materialize_desired(): array {
		$map = [];
		foreach ( $this->autobest_map() as $pid => $pct ) {
			$pid = (int) $pid;
			$pct = (float) $pct;
			if ( $pid <= 0 || $pct <= 0 ) {
				continue;
			}
			// The selection is in the default language; write the sale to every
			// translation too, so each WPML language feed carries it.
			foreach ( $this->translations_of( $pid ) as $tid ) {
				if ( ! isset( $map[ $tid ] ) || $pct > $map[ $tid ] ) {
					$map[ $tid ] = $pct;
				}
			}
		}
		return $map;
	}

	/** A product's translation IDs (WPML), or just itself when WPML is inactive. */
	private function translations_of( int $pid ): array {
		if ( ! DZE_Wpml::is_active() ) {
			return [ $pid ];
		}
		$trid = apply_filters( 'wpml_element_trid', null, $pid, 'post_product' );
		if ( ! $trid ) {
			return [ $pid ];
		}
		$out = [];
		foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, 'post_product' ) as $t ) {
			if ( ! empty( $t->element_id ) ) {
				$out[] = (int) $t->element_id;
			}
		}
		return $out ?: [ $pid ];
	}

	/** Parent product IDs we currently manage a sale on. */
	private function managed_sale_ids(): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
			self::META_RULE
		) ) );
	}

	private function apply_sale( array $item ): void {
		$pid = (int) ( $item['id'] ?? 0 );
		if ( $pid <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( $pid );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$pct  = (float) $item['pct'];
		$rows = $product->is_type( 'variable' ) ? $product->get_children() : [ $pid ];
		foreach ( $rows as $cid ) {
			$this->set_row_sale( (int) $cid, $pct );
		}
		update_post_meta( $pid, self::META_RULE, 'auto' );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $pid );
		}
	}

	private function release_sale( int $pid ): void {
		if ( $pid <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( $pid );
		$rows    = ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) ? $product->get_children() : [ $pid ];
		foreach ( $rows as $cid ) {
			$this->restore_row( (int) $cid );
		}
		delete_post_meta( $pid, self::META_RULE );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $pid );
		}
	}

	/**
	 * Writes native sale meta on one product/variation row using RAW stored
	 * prices (never the getters — our own runtime filters would feed the sale
	 * price back into itself). Stashes the original sale/price once so it can be
	 * restored later.
	 */
	private function set_row_sale( int $id, float $pct ): void {
		$regular = (float) get_post_meta( $id, '_regular_price', true );
		if ( $regular <= 0 ) {
			return;
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$sale     = round( $regular * ( 1 - $pct / 100 ), $decimals );
		if ( $sale <= 0 || $sale >= $regular ) {
			return;
		}
		if ( get_post_meta( $id, self::META_MANAGED, true ) !== '1' ) {
			update_post_meta( $id, self::META_MANAGED, '1' );
			update_post_meta( $id, self::META_PREV, (string) get_post_meta( $id, '_sale_price', true ) );
			update_post_meta( $id, self::META_PREV_PRICE, (string) get_post_meta( $id, '_price', true ) );
			update_post_meta( $id, self::META_PREV_FROM, (string) get_post_meta( $id, '_sale_price_dates_from', true ) );
			update_post_meta( $id, self::META_PREV_TO, (string) get_post_meta( $id, '_sale_price_dates_to', true ) );
		}
		update_post_meta( $id, '_sale_price', (string) $sale );
		update_post_meta( $id, '_price', (string) $sale );
		// Our promos are active-now; clear any scheduled-sale dates while we manage it.
		delete_post_meta( $id, '_sale_price_dates_from' );
		delete_post_meta( $id, '_sale_price_dates_to' );
	}

	private function restore_row( int $id ): void {
		if ( get_post_meta( $id, self::META_MANAGED, true ) !== '1' ) {
			return;
		}
		$prev       = (string) get_post_meta( $id, self::META_PREV, true );
		$prev_price = (string) get_post_meta( $id, self::META_PREV_PRICE, true );
		$prev_from  = (string) get_post_meta( $id, self::META_PREV_FROM, true );
		$prev_to    = (string) get_post_meta( $id, self::META_PREV_TO, true );
		if ( $prev !== '' ) {
			update_post_meta( $id, '_sale_price', $prev );
		} else {
			delete_post_meta( $id, '_sale_price' );
		}
		if ( $prev_from !== '' ) {
			update_post_meta( $id, '_sale_price_dates_from', $prev_from );
		} else {
			delete_post_meta( $id, '_sale_price_dates_from' );
		}
		if ( $prev_to !== '' ) {
			update_post_meta( $id, '_sale_price_dates_to', $prev_to );
		} else {
			delete_post_meta( $id, '_sale_price_dates_to' );
		}
		$regular = (string) get_post_meta( $id, '_regular_price', true );
		update_post_meta( $id, '_price', $prev_price !== '' ? $prev_price : $regular );
		delete_post_meta( $id, self::META_MANAGED );
		delete_post_meta( $id, self::META_PREV );
		delete_post_meta( $id, self::META_PREV_PRICE );
		delete_post_meta( $id, self::META_PREV_FROM );
		delete_post_meta( $id, self::META_PREV_TO );
	}

	/** Admin: "Resync sale prices now" button. */
	public function handle_resync(): void {
		check_admin_referer( 'dze_sale_resync' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$this->queue_sale_sync();
		wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'resynced' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// =========================================================================
	// Banners
	// =========================================================================

	/** @var array<string,bool> Rule ids already rendered this request (dedupe). */
	private static array $rendered = [];
	private static bool $timer_script_done = false;

	public function shortcode_banner( $atts ): string {
		ob_start();
		foreach ( $this->rules_of_type( 'sale' ) as $rule ) {
			$this->render_single_banner( $rule );
		}
		return (string) ob_get_clean();
	}

	private function render_location( string $location ): void {
		foreach ( $this->rules_of_type( 'sale' ) as $rule ) {
			if ( ( $rule['banner_location'] ?? '' ) === $location ) {
				$this->render_single_banner( $rule );
			}
		}
	}

	private function render_single_banner( array $rule ): void {
		if ( empty( $rule['banner_enabled'] ) ) {
			return;
		}
		$id = (string) ( $rule['id'] ?? md5( (string) wp_json_encode( $rule ) ) );
		if ( isset( self::$rendered[ $id ] ) ) {
			return; // one banner per rule per page load.
		}

		// Banner text, translated in-plugin per WPML language.
		$text = trim( (string) ( $rule['banner_text'] ?? '' ) );
		if ( DZE_Wpml::is_active() ) {
			$lang = DZE_Wpml::current_language();
			$i18n = (array) ( $rule['banner_text_i18n'] ?? [] );
			if ( $lang !== '' && ! empty( $i18n[ $lang ] ) ) {
				$text = trim( (string) $i18n[ $lang ] );
			}
		}
		if ( $text === '' ) {
			return;
		}

		self::$rendered[ $id ] = true;

		$bg    = $rule['banner_bg'] ?? '#111111';
		$color = $rule['banner_color'] ?? '#ffffff';

		$timer = '';
		if ( ! empty( $rule['banner_timer'] ) && ! empty( $rule['end'] ) ) {
			[ , $end_ts ] = $this->window_ts( $rule );
			if ( $end_ts > time() ) {
				$timer = ' <span class="dze-timer" data-end="' . esc_attr( (string) $end_ts ) . '"></span>';
				$this->print_timer_script();
			}
		}

		// Typography is intentionally left to the theme (no font-size / weight
		// override) — only the background, colour and padding are set.
		printf(
			'<div class="dze-promo-banner" style="background:%1$s;color:%2$s;text-align:center;padding:10px 16px;">%3$s%4$s</div>',
			esc_attr( $bg ),
			esc_attr( $color ),
			esc_html( $text ),
			$timer // already-escaped markup built above.
		);
	}

	private function print_timer_script(): void {
		if ( self::$timer_script_done ) {
			return;
		}
		self::$timer_script_done = true;
		?>
<script>
(function(){function u(){var n=Date.now();document.querySelectorAll('.dze-timer').forEach(function(el){var e=parseInt(el.getAttribute('data-end'),10)*1000,d=Math.max(0,e-n),s=Math.floor(d/1000),dd=Math.floor(s/86400);s%=86400;var h=Math.floor(s/3600);s%=3600;var m=Math.floor(s/60);s%=60;el.textContent=(dd>0?dd+'d ':'')+h+'h '+m+'m '+s+'s';});}u();setInterval(u,1000);})();
</script>
		<?php
	}

	// =========================================================================
	// Coupons + hero image swap
	// =========================================================================

	public function coupon_exclude_sale( $valid, $product, $coupon, $values ) {
		if ( $product instanceof \WC_Product
			&& $coupon instanceof \WC_Coupon
			&& $coupon->get_exclude_sale_items()
			&& $this->sale_percent_for( $product ) > 0
		) {
			return false;
		}
		return $valid;
	}

	private ?array $hero_map = null;

	private function build_hero_map(): array {
		if ( null !== $this->hero_map ) {
			return $this->hero_map;
		}
		$map = [];
		foreach ( $this->rules_of_type( 'sale' ) as $rule ) {
			if ( ! empty( $rule['hero_swap_enabled'] ) && ! empty( $rule['hero_source_id'] ) && ! empty( $rule['hero_event_id'] ) ) {
				$map[ (int) $rule['hero_source_id'] ] = (int) $rule['hero_event_id'];
			}
		}
		return $this->hero_map = $map;
	}

	public function swap_image_src( $image, $attachment_id, $size, $icon ) {
		$map = $this->build_hero_map();
		if ( isset( $map[ (int) $attachment_id ] ) ) {
			$new = wp_get_attachment_image_src( $map[ (int) $attachment_id ], $size, $icon );
			if ( $new ) {
				return $new;
			}
		}
		return $image;
	}

	public function swap_image_url( $url, $attachment_id ) {
		$map = $this->build_hero_map();
		if ( isset( $map[ (int) $attachment_id ] ) ) {
			$new = wp_get_attachment_url( $map[ (int) $attachment_id ] );
			if ( $new ) {
				return $new;
			}
		}
		return $url;
	}

	// =========================================================================
	// Admin: menu + assets
	// =========================================================================

	public function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Marketing Events', 'dazont-ecom' ),
			__( 'Marketing Events', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG_EVENTS,
			[ $this, 'render_events_page' ]
		);
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Discounts', 'dazont-ecom' ),
			__( 'Discounts', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_discounts_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false && strpos( $hook, self::MENU_SLUG_EVENTS ) === false ) {
			return;
		}
		wp_enqueue_style( 'dze-admin', DZE_URL . 'admin/css/admin.css', [], DZE_VERSION );
		wp_enqueue_media(); // Media Library picker for the hero image fields.
		wp_enqueue_script( 'dze-discounts', DZE_URL . 'admin/js/discounts.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-discounts', 'dzeDiscounts', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::SAVE_NONCE ),
			'i18n'    => [
				'counting' => __( 'Counting…', 'dazont-ecom' ),
				'error'    => __( 'Could not count.', 'dazont-ecom' ),
				/* translators: 1: total matching, 2: number that will be discounted */
				'result'    => __( '%1$s products match — the top %2$s will be discounted.', 'dazont-ecom' ),
				/* translators: %s: number of products shown */
				'listTitle' => __( 'Products to be discounted (%s shown)', 'dazont-ecom' ),
			],
		] );
	}

	/**
	 * "Marketing Events" page. Two tabs: the events/calendar workspace (default)
	 * and the Google Merchant Center connection (promotions sync lives with the
	 * promotions themselves).
	 */
	public function render_events_page(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'events';
		if ( 'gmc' === $tab && class_exists( 'DZE_Gmc' ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
			}
			echo '<div class="wrap dze-wrap">';
			echo '<h1 class="wp-heading-inline">' . esc_html__( 'Marketing Events', 'dazont-ecom' ) . '</h1><hr class="wp-header-end" />';
			echo $this->events_tabs_html( 'gmc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_* internally.
			DZE_Gmc::instance()->render_settings_page();
			echo '</div>';
			return;
		}
		$this->render_page_for( 'events' );
	}

	/** Tab nav for the Marketing Events page (Events | Google Merchant Center). */
	public function events_tabs_html( string $active ): string {
		if ( ! class_exists( 'DZE_Gmc' ) ) {
			return '';
		}
		$events_url = add_query_arg( [ 'page' => self::MENU_SLUG_EVENTS ], admin_url( 'admin.php' ) );
		$gmc_url    = add_query_arg( [ 'page' => self::MENU_SLUG_EVENTS, 'tab' => 'gmc' ], admin_url( 'admin.php' ) );
		ob_start();
		?>
		<h2 class="nav-tab-wrapper" style="margin-bottom:16px;">
			<a href="<?php echo esc_url( $events_url ); ?>" class="nav-tab<?php echo 'gmc' !== $active ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Events & calendar', 'dazont-ecom' ); ?></a>
			<a href="<?php echo esc_url( $gmc_url ); ?>" class="nav-tab<?php echo 'gmc' === $active ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Google Merchant Center', 'dazont-ecom' ); ?></a>
		</h2>
		<?php
		return (string) ob_get_clean();
	}

	/** "Discounts" page: evergreen cart/bulk rules, set up once. */
	public function render_discounts_page(): void {
		$this->render_page_for( 'discounts' );
	}

	private function render_page_for( string $mode ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}

		$menu_slug   = ( 'events' === $mode ) ? self::MENU_SLUG_EVENTS : self::MENU_SLUG;
		$page_title  = ( 'events' === $mode ) ? __( 'Marketing Events', 'dazont-ecom' ) : __( 'Discounts', 'dazont-ecom' );
		$type_labels = self::types_for_mode( $mode );
		$rules       = array_filter( self::get_rules(), static fn( $r ) => array_key_exists( $r['type'] ?? '', $type_labels ) );
		$languages   = DZE_Wpml::get_active_languages();

		$edit_id = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
		$is_new  = isset( $_GET['new'] );
		$editing = ( $edit_id !== '' && isset( $rules[ $edit_id ] ) ) ? $rules[ $edit_id ] : null;

		// Edit / create screen is a separate page from the list.
		if ( $is_new || $editing ) {
			$categories       = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
			$product_positions = self::product_positions();
			require DZE_DIR . 'admin/views/discounts-edit.php';
			return;
		}

		$notice = get_transient( 'dze_discount_notice' );
		if ( $notice ) {
			delete_transient( 'dze_discount_notice' );
		}
		$events_tabs = ( 'events' === $mode ) ? $this->events_tabs_html( 'events' ) : '';
		require DZE_DIR . 'admin/views/discounts-page.php';
	}

	/** Saves the global "never discount" list. */
	public function handle_exclusions_save(): void {
		check_admin_referer( 'dze_discount_exclusions' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$products   = $this->parse_ids( wp_unslash( $_POST['excl_products'] ?? '' ) );
		$categories = array_values( array_filter( array_map( 'absint', (array) ( $_POST['excl_categories'] ?? [] ) ) ) );
		update_option( self::OPT_EXCLUSIONS, [ 'products' => $products, 'categories' => $categories ], false );

		wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'excl_saved' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/** AJAX: preview how many products an automatic-discount rule would cover. */
	public function ajax_auto_count(): void {
		check_ajax_referer( self::SAVE_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$strategy = in_array( $_POST['strategy'] ?? '', array_keys( self::auto_strategies() ), true ) ? sanitize_key( wp_unslash( $_POST['strategy'] ) ) : 'bestsellers';
		$priority = in_array( $_POST['priority'] ?? '', array_keys( self::auto_priorities() ), true ) ? sanitize_key( wp_unslash( $_POST['priority'] ) ) : 'recent';
		$top_n    = max( 1, min( self::AUTO_MAX, (int) ( $_POST['top_n'] ?? 20 ) ) );
		$lookback = min( 365, max( 1, (int) ( $_POST['lookback_days'] ?? 30 ) ) );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_send_json_success( $this->auto_count( $strategy, $top_n, $lookback, $priority ) );
	}

	/** Public helper for the list view: flags of languages a rule is active in. */
	public function rule_language_flags( array $rule ): array {
		if ( ! DZE_Wpml::is_active() ) {
			return [];
		}
		$codes = $this->rule_effective_languages( $rule );
		$flags = [];
		foreach ( DZE_Wpml::get_active_languages() as $lang ) {
			if ( in_array( $lang['code'], $codes, true ) ) {
				$flags[] = $lang;
			}
		}
		return $flags;
	}

	// =========================================================================
	// Admin: save / delete / toggle
	// =========================================================================

	public function handle_save(): void {
		check_admin_referer( self::SAVE_NONCE );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}

		$in    = wp_unslash( $_POST );
		$rules = self::get_rules();

		$id      = ! empty( $in['rule_id'] ) ? sanitize_key( $in['rule_id'] ) : 'r' . uniqid();
		$type    = in_array( $in['type'] ?? '', array_keys( self::type_labels() ), true ) ? $in['type'] : 'sale';
		$scope   = in_array( $in['scope'] ?? 'all', [ 'all', 'categories', 'products' ], true ) ? $in['scope'] : 'all';
		$b_loc   = in_array( $in['banner_location'] ?? '', [ 'top', 'below_header', 'product' ], true ) ? $in['banner_location'] : 'top';
		$b_pos   = array_key_exists( $in['product_position'] ?? '', self::product_positions() ) ? $in['product_position'] : 'before_product';
		$created = ( isset( $rules[ $id ]['created_at'] ) && $rules[ $id ]['created_at'] ) ? (int) $rules[ $id ]['created_at'] : time();

		$rule = [
			'id'            => $id,
			'created_at'    => $created,
			'title'         => sanitize_text_field( $in['title'] ?? '' ),
			'type'          => $type,
			'enabled'       => ! empty( $in['enabled'] ),
			'percent'       => min( 100, max( 0, (float) ( $in['percent'] ?? 0 ) ) ),
			'scope'         => $scope,
			'category_ids'  => array_map( 'absint', (array) ( $in['category_ids'] ?? [] ) ),
			'product_ids'   => $this->parse_ids( $in['product_ids'] ?? '' ),
			'start'         => $this->sanitize_dt( $in['start'] ?? '' ),
			'end'           => $this->sanitize_dt( $in['end'] ?? '' ),
			'threshold'     => max( 0, (float) ( $in['threshold'] ?? 0 ) ),
			// Bulk order (tiered) fields.
			'min_subtotal'  => max( 0, (float) ( $in['min_subtotal'] ?? 0 ) ),
			'min_qty'       => max( 0, (int) ( $in['min_qty'] ?? 0 ) ),
			'tiers'         => $this->sanitize_tiers( $in['tiers'] ?? [] ),
			// Automatic product discount fields.
			'strategy'      => in_array( $in['strategy'] ?? '', array_keys( self::auto_strategies() ), true ) ? $in['strategy'] : 'bestsellers',
			'priority'      => in_array( $in['priority'] ?? '', array_keys( self::auto_priorities() ), true ) ? $in['priority'] : 'recent',
			'top_n'         => max( 1, min( self::AUTO_MAX, (int) ( $in['top_n'] ?? 20 ) ) ),
			'lookback_days' => min( 365, max( 1, (int) ( $in['lookback_days'] ?? 30 ) ) ),
			'banner_enabled'   => ! empty( $in['banner_enabled'] ),
			'banner_text'      => sanitize_text_field( $in['banner_text'] ?? '' ),
			'banner_bg'        => $this->sanitize_hex( $in['banner_bg'] ?? '#111111' ),
			'banner_color'     => $this->sanitize_hex( $in['banner_color'] ?? '#ffffff' ),
			'banner_location'  => $b_loc,
			'product_position' => $b_pos,
			'banner_hooks'      => sanitize_text_field( $in['banner_hooks'] ?? '' ),
			'banner_timer'      => ! empty( $in['banner_timer'] ),
			'banner_text_i18n'  => $this->sanitize_i18n( $in['banner_text_i18n'] ?? [] ),
			'languages'         => array_values( array_filter( array_map( 'sanitize_key', (array) ( $in['languages'] ?? [] ) ) ) ),
			'hero_swap_enabled' => ! empty( $in['hero_swap_enabled'] ),
			'hero_source_id'    => absint( $in['hero_source_id'] ?? 0 ),
			'hero_event_id'     => absint( $in['hero_event_id'] ?? 0 ),
		];

		// Only one promotion (sale) may be active at a time: if this enabled sale
		// overlaps another enabled sale, save it disabled and report the clash.
		// Return to the page this rule type belongs to.
		$back_slug = in_array( $type, self::EVENT_TYPES, true ) ? self::MENU_SLUG_EVENTS : self::MENU_SLUG;
		$args = [ 'page' => $back_slug, 'saved' => 1 ];
		if ( $rule['enabled'] ) {
			$clash = $this->conflicting_sale( $rule );
			if ( $clash !== '' ) {
				$rule['enabled'] = false;
				set_transient( 'dze_discount_notice', sprintf(
					/* translators: %s: conflicting promotion title */
					__( 'Saved as disabled: its dates overlap the active promotion "%s". Only one promotion can run at a time.', 'dazont-ecom' ),
					$clash
				), 60 );
				$args['saved'] = 0;
			}
		}

		$rules[ $id ] = $rule;
		self::save_rules( $rules );
		$this->queue_sale_sync();

		// "Save & Push to GMC": sync straight after saving (all configured targets).
		if ( ! empty( $in['push_gmc'] ) && 'sale' === $type && class_exists( 'DZE_Gmc' ) && DZE_Gmc::instance()->is_configured() ) {
			$statuses = DZE_Gmc::instance()->sync_rule( $id );
			$errors   = array_filter( $statuses, static fn( $s ) => ( $s['status'] ?? '' ) === 'error' );
			set_transient( 'dze_discount_notice', empty( $errors )
				? __( 'Saved and pushed to Google Merchant Center.', 'dazont-ecom' )
				: __( 'Saved. Some Merchant Center targets reported an error — see the GMC sync column.', 'dazont-ecom' ), 60 );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_delete(): void {
		check_admin_referer( 'dze_discount_delete' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$id    = isset( $_GET['rule'] ) ? sanitize_key( wp_unslash( $_GET['rule'] ) ) : '';
		$rules = self::get_rules();

		// If this promo was pushed to Google Merchant Center, cancel it there too.
		if ( isset( $rules[ $id ] ) && ! empty( $rules[ $id ]['gmc_sync'] ) && class_exists( 'DZE_Gmc' ) ) {
			DZE_Gmc::instance()->cancel_rule( $rules[ $id ] );
		}

		$back = ( isset( $rules[ $id ]['type'] ) && in_array( $rules[ $id ]['type'], self::EVENT_TYPES, true ) ) ? self::MENU_SLUG_EVENTS : self::MENU_SLUG;
		unset( $rules[ $id ] );
		self::save_rules( $rules );
		$this->queue_sale_sync();
		wp_safe_redirect( add_query_arg( [ 'page' => $back, 'deleted' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_toggle(): void {
		check_admin_referer( 'dze_discount_toggle' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$id    = isset( $_GET['rule'] ) ? sanitize_key( wp_unslash( $_GET['rule'] ) ) : '';
		$rules = self::get_rules();
		if ( isset( $rules[ $id ] ) ) {
			$enabling = empty( $rules[ $id ]['enabled'] );
			// Enabling a sale that overlaps another active sale is forbidden.
			if ( $enabling ) {
				$clash = $this->conflicting_sale( $rules[ $id ] );
				if ( $clash !== '' ) {
					set_transient( 'dze_discount_notice', sprintf(
						/* translators: %s: conflicting promotion title */
						__( 'Cannot enable: its dates overlap the active promotion "%s". Only one promotion can run at a time.', 'dazont-ecom' ),
						$clash
					), 60 );
					wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) ) );
					exit;
				}
			}
			$rules[ $id ]['enabled'] = $enabling;
			self::save_rules( $rules );
			$this->queue_sale_sync();
		}
		wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ---- sanitizers ----

	private function sanitize_i18n( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$clean = [];
		foreach ( $value as $lang => $text ) {
			$lang = sanitize_key( $lang );
			$text = sanitize_text_field( $text );
			if ( $lang !== '' && $text !== '' ) {
				$clean[ $lang ] = $text;
			}
		}
		return $clean;
	}

	private function parse_hooks( $raw ): array {
		$parts = preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$hooks = [];
		foreach ( $parts as $p ) {
			$p = preg_replace( '/[^a-z0-9_]/', '', strtolower( $p ) );
			if ( $p !== '' ) {
				$hooks[] = $p;
			}
		}
		return array_values( array_unique( $hooks ) );
	}

	private function parse_ids( $raw ): array {
		$parts = preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_unique( array_map( 'absint', $parts ) ) );
	}

	private function sanitize_dt( string $value ): string {
		$value = trim( $value );
		// Day-granular schedule from <input type="date">: YYYY-MM-DD.
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/** Bulk-order quantity tiers: keep rows that have a discount, sorted by qty. */
	private function sanitize_tiers( $value ): array {
		$out = [];
		foreach ( (array) $value as $tier ) {
			$qty = max( 0, (int) ( $tier['qty'] ?? 0 ) );
			$pct = min( 100, max( 0, (float) ( $tier['percent'] ?? 0 ) ) );
			if ( $pct > 0 ) {
				$out[] = [ 'qty' => $qty, 'percent' => $pct ];
			}
		}
		usort( $out, static fn( $a, $b ) => $a['qty'] <=> $b['qty'] );
		return $out;
	}

	/** Default bulk-order tiers seeded on the create screen. */
	public static function default_tiers(): array {
		return [
			[ 'qty' => 1,  'percent' => 5 ],
			[ 'qty' => 6,  'percent' => 10 ],
			[ 'qty' => 11, 'percent' => 15 ],
			[ 'qty' => 21, 'percent' => 20 ],
		];
	}

	private function sanitize_hex( string $value ): string {
		$value = sanitize_hex_color( $value );
		return $value ?: '#111111';
	}
}
