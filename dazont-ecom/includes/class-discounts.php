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

	/** What the corner badge says on a product page: 'saved' or 'sale'. */
	public const OPT_BADGE = 'dze_discount_badge';

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

	/** Background translation of one promotion. */
	public const I18N_HOOK = 'dze_promo_i18n';

	private function __construct() {
		if ( is_admin() ) {
			// Registered by the module itself: switched off, the setting is not
			// registered, its tab is not drawn, and nothing of it remains.
			add_action( 'admin_init', static function (): void {
				register_setting( 'dze_discount_display_options', self::OPT_BADGE, [
					'type'              => 'string',
					'sanitize_callback' => static fn( $v ): string => 'saved' === $v ? 'saved' : 'sale',
					// Autoloaded on purpose, like the price ending: the badge
					// filter reads it on a product page, and a non-autoloaded
					// row would cost an extra query there. It is five letters.
					'autoload'          => true,
					'default'           => 'saved',
				] );
			} );
			add_action( 'admin_menu',            [ $this, 'register_menu' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			add_action( 'admin_post_dze_discount_save',   [ $this, 'handle_save' ] );
			add_action( 'admin_post_dze_discount_delete', [ $this, 'handle_delete' ] );
			add_action( 'admin_post_dze_discount_toggle', [ $this, 'handle_toggle' ] );
			add_action( 'admin_post_dze_discount_bulk',   [ $this, 'handle_bulk' ] );
			add_action( 'admin_post_dze_discount_exclusions', [ $this, 'handle_exclusions_save' ] );
			add_action( 'admin_post_dze_sale_resync',     [ $this, 'handle_resync' ] );
			add_action( 'wp_ajax_dze_auto_count',         [ $this, 'ajax_auto_count' ] );
			add_action( 'wp_ajax_dze_hero_image',         [ $this, 'ajax_hero_image' ] );
		}
		// The home page's picture is read from the home page, so the reading
		// is thrown away when that page is saved — a shop that changes its
		// hero must not wait six hours for the plugin to notice.
		add_action( 'save_post', static function ( $post_id ): void {
			if ( (int) $post_id === (int) get_option( 'page_on_front' ) ) {
				self::forget_hero();
			}
		} );
		add_action( 'update_option_page_on_front', [ self::class, 'forget_hero' ] );

		// The pricing engine must run on the front end, on cart AJAX and on the
		// Store API (REST) — everywhere WooCommerce computes prices/totals.
		add_action( 'init', [ $this, 'register_engine' ] );

		// Materialise sale prices into product data (for feeds/exports).
		add_action( self::SYNC_HOOK, [ $this, 'run_sale_sync' ] );
		add_action( 'init', [ $this, 'schedule_sale_sync' ] );

		add_shortcode( 'dze_promo_banner', [ $this, 'shortcode_banner' ] );

		// Translating a promotion happens in the background, never inside the
		// request that saved it.
		add_action( self::I18N_HOOK, [ __CLASS__, 'fill_i18n' ] );
	}

	// =========================================================================
	// The promotion, in every language the shop sells in
	// =========================================================================

	/**
	 * Asks for the missing translations of a promotion, shortly, elsewhere.
	 *
	 * A promotion whose banner has no text in Polish does not RUN in Polish:
	 * rule_effective_languages() drops that language, because a sale nobody
	 * can read announced is worse than no sale. So the translations are not a
	 * finishing touch — they are what makes the event exist in a language.
	 *
	 * Nothing is translated inside the request that saved the event: the owner
	 * would wait on three or four model calls before his page came back.
	 */
	public static function schedule_i18n( string $rule_id ): void {
		if ( '' === $rule_id || ! self::missing_langs( $rule_id ) ) {
			return;
		}
		// The owner's switch, on the tab that holds the instructions.
		if ( class_exists( 'DZE_Marketing_Ai' ) && ! DZE_Marketing_Ai::promo_i18n_on() ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::I18N_HOOK, [ $rule_id ], 'dazont-ecom' );
			return;
		}
		if ( ! wp_next_scheduled( self::I18N_HOOK, [ $rule_id ] ) ) {
			wp_schedule_single_event( time() + 15, self::I18N_HOOK, [ $rule_id ] );
		}
	}

	/**
	 * The languages this promotion still has nothing to say in.
	 *
	 * @return array<string,string> code => native name
	 */
	public static function missing_langs( string $rule_id ): array {
		$rule = self::get_rules()[ $rule_id ] ?? null;
		if ( ! $rule || 'sale' !== ( $rule['type'] ?? '' ) || ! DZE_Wpml::is_active() ) {
			return [];
		}
		if ( '' === trim( (string) ( $rule['banner_text'] ?? $rule['title'] ?? '' ) ) ) {
			return [];
		}
		$default  = DZE_Wpml::default_language();
		$i18n     = (array) ( $rule['banner_text_i18n'] ?? [] );
		$selected = (array) ( $rule['languages'] ?? [] );
		$out      = [];
		foreach ( DZE_Wpml::get_active_languages() as $lang ) {
			$code = (string) $lang['code'];
			if ( $code === $default || '' !== trim( (string) ( $i18n[ $code ] ?? '' ) ) ) {
				continue;
			}
			// A rule restricted to some languages is not short of the others.
			if ( $selected && ! in_array( $code, $selected, true ) ) {
				continue;
			}
			$out[ $code ] = (string) ( $lang['native_name'] ?? $code );
		}
		return $out;
	}

	/**
	 * Writes the missing translations of one promotion.
	 *
	 * One call for every language at once: they are one line each, and asking
	 * four times costs four times as much for a worse result — the model sees
	 * the whole set and keeps them consistent.
	 */
	/**
	 * Translates one line into the given languages, or throws.
	 *
	 * One call for every language at once: they are one line each, and asking
	 * four times costs four times as much for a worse result — the model sees
	 * the whole set and keeps them consistent. The only place that knows how
	 * a promotion is translated; the screens and the background pass both come
	 * through here.
	 *
	 * @param array<string,string> $langs code => native name.
	 *
	 * @return array<string,string> code => translated line.
	 */
	public static function translate_line( string $line, array $langs ): array {
		$line = trim( $line );
		if ( '' === $line || ! $langs || ! class_exists( 'DZE_Marketing_Ai' ) ) {
			return [];
		}
		$names = [];
		foreach ( $langs as $code => $name ) {
			$names[] = $code . ' (' . $name . ')';
		}
		$user = "--- THE LINE TO ADAPT ---\n" . $line . "\n"
			. "\n--- MARKETS ---\n- " . implode( "\n- ", $names ) . "\n"
			. "\n--- INSTRUCTIONS ---\n" . DZE_Marketing_Ai::promo_i18n_prompt() . "\n"
			. "\n--- OUTPUT ---\nJSON only: an object whose keys are the language codes above and whose values are the adapted lines. No other key, no comment.";

		DZE_Ai_Usage::unit( 'promo_i18n' );
		try {
			$out = DZE_Marketing_Ai::complete(
				'You are the copywriter of an online shop in each market named. You write the promotional line that shop would use to announce the offer given — never a translation of it. You reply with JSON only.',
				$user,
				'',
				900,
				90
			);
		} finally {
			DZE_Ai_Usage::unit();
		}
		$json = json_decode( trim( preg_replace( '/^```(?:json)?|```$/m', '', (string) $out ) ), true );
		if ( ! is_array( $json ) ) {
			return [];
		}
		$clean = [];
		foreach ( $langs as $code => $name ) {
			$text = sanitize_text_field( (string) ( $json[ $code ] ?? '' ) );
			if ( '' !== $text ) {
				$clean[ $code ] = mb_substr( $text, 0, 120 );
			}
		}
		if ( $clean && class_exists( 'DZE_Ai_Usage' ) ) {
			DZE_Ai_Usage::finished( 'promo_i18n' );
		}
		return $clean;
	}

	/**
	 * Translates several lines at once, for a whole calendar.
	 *
	 * A generated calendar is eight titles; eight calls to say eight short
	 * sentences would cost eight times the tokens and come back in eight
	 * different tones. One call, numbered in and numbered out.
	 *
	 * @param string[]             $lines Titles, in order.
	 * @param array<string,string> $langs code => native name.
	 *
	 * @return array<int,array<string,string>> index => code => translated line.
	 */
	public static function translate_lines( array $lines, array $langs ): array {
		$lines = array_values( array_filter( array_map( 'trim', $lines ), static fn( $l ) => '' !== $l ) );
		if ( ! $lines || ! $langs || ! class_exists( 'DZE_Marketing_Ai' ) ) {
			return [];
		}
		$names = [];
		foreach ( $langs as $code => $name ) {
			$names[] = $code . ' (' . $name . ')';
		}
		$numbered = [];
		foreach ( $lines as $i => $line ) {
			$numbered[] = ( $i + 1 ) . '. ' . $line;
		}
		$user = "--- THE LINES TO ADAPT ---\n" . implode( "\n", $numbered ) . "\n"
			. "\n--- MARKETS ---\n- " . implode( "\n- ", $names ) . "\n"
			. "\n--- INSTRUCTIONS ---\n" . DZE_Marketing_Ai::promo_i18n_prompt() . "\n"
			. "\n--- OUTPUT ---\nJSON only: an object whose keys are the line numbers above, each holding an object of language code => adapted line. Every line, every language, no other key, no comment.";

		DZE_Ai_Usage::unit( 'promo_i18n' );
		try {
			$out = DZE_Marketing_Ai::complete(
				'You are the copywriter of an online shop in each market named. You write the promotional line that shop would use to announce the offer given — never a translation of it. You reply with JSON only.',
				$user,
				'',
				2400,
				120
			);
		} finally {
			DZE_Ai_Usage::unit();
		}
		$json = json_decode( trim( preg_replace( '/^```(?:json)?|```$/m', '', (string) $out ) ), true );
		if ( ! is_array( $json ) ) {
			return [];
		}
		$clean = [];
		foreach ( $lines as $i => $line ) {
			$row = (array) ( $json[ (string) ( $i + 1 ) ] ?? $json[ $i + 1 ] ?? [] );
			$one = [];
			foreach ( $langs as $code => $name ) {
				$text = sanitize_text_field( (string) ( $row[ $code ] ?? '' ) );
				if ( '' !== $text ) {
					$one[ $code ] = mb_substr( $text, 0, 120 );
				}
			}
			if ( $one ) {
				$clean[ $i ] = $one;
			}
		}
		if ( $clean && class_exists( 'DZE_Ai_Usage' ) ) {
			DZE_Ai_Usage::finished( 'promo_i18n' );
		}
		return $clean;
	}

	/**
	 * Writes the missing translations of one promotion, in the background.
	 */
	public static function fill_i18n( string $rule_id ): void {
		$missing = self::missing_langs( $rule_id );
		if ( ! $missing ) {
			return;
		}
		$rules  = self::get_rules();
		$rule   = $rules[ $rule_id ] ?? [];
		$source = trim( (string) ( $rule['banner_text'] ?? '' ) );
		if ( '' === $source ) {
			$source = trim( (string) ( $rule['title'] ?? '' ) );
		}
		try {
			$lines = self::translate_line( $source, $missing );
		} catch ( \Throwable $e ) {
			return; // the next save asks again; a promotion is not lost over this.
		}
		if ( ! $lines ) {
			return;
		}
		// Re-read: the rule may have been edited while the model was thinking.
		$rules = self::get_rules();
		if ( ! isset( $rules[ $rule_id ] ) ) {
			return;
		}
		$i18n = (array) ( $rules[ $rule_id ]['banner_text_i18n'] ?? [] );
		$got  = 0;
		foreach ( $lines as $code => $text ) {
			// Never overwrite a line written by hand in the meantime.
			if ( '' !== trim( (string) ( $i18n[ $code ] ?? '' ) ) ) {
				continue;
			}
			$i18n[ $code ] = $text;
			$got++;
		}
		if ( ! $got ) {
			return;
		}
		$rules[ $rule_id ]['banner_text_i18n'] = $i18n;
		self::save_rules( $rules );
	}

	/**
	 * The languages a promotion should speak, with their names.
	 *
	 * @return array<string,string> code => native name
	 */
	public static function promo_langs(): array {
		if ( ! DZE_Wpml::is_active() ) {
			return [];
		}
		$default = DZE_Wpml::default_language();
		$out     = [];
		foreach ( DZE_Wpml::get_active_languages() as $lang ) {
			$code = (string) $lang['code'];
			if ( $code !== $default ) {
				$out[ $code ] = (string) ( $lang['native_name'] ?? $code );
			}
		}
		return $out;
	}

	// =========================================================================
	// Rule storage
	// =========================================================================

	/**
	 * The banner's colours — the shop's, not one promotion's.
	 *
	 * A style chosen event by event is a shop whose banner looks different
	 * every month. Decided once, under Settings → Marketing events, and read
	 * here by everything that draws a banner: the colours, the text size and
	 * the padding. The face and the weight stay the theme's.
	 *
	 * @return array{bg:string,color:string,size:int,pad:int}
	 */
	public static function banner_style(): array {
		$s = class_exists( 'DZE_Marketing_Ai' ) ? DZE_Marketing_Ai::get_settings() : [];
		return [
			'bg'    => self::hex( $s['banner_bg'] ?? '', '#111111' ),
			'color' => self::hex( $s['banner_color'] ?? '', '#ffffff' ),
			// Zero means "the theme decides", which is what this did before
			// there was a field for it and what a shop that never touches it
			// keeps.
			'size'  => self::px( $s['banner_size'] ?? 0, 0, 40 ),
			'pad'   => self::px( $s['banner_pad'] ?? 10, 0, 60 ),
		];
	}

	/**
	 * Where a banner goes when nobody said otherwise.
	 *
	 * Chosen once, under Settings → Marketing events, and used by every
	 * promotion that does not name a place of its own — the one made by hand,
	 * the one the calendar proposes, the whole of a bulk creation. A default
	 * that lives in three files as the string 'top' is three places to change
	 * the day the shop's header moves.
	 */
	public static function default_location(): string {
		$s = class_exists( 'DZE_Marketing_Ai' ) ? DZE_Marketing_Ai::get_settings() : [];
		$v = (string) ( $s['banner_location'] ?? '' );
		return in_array( $v, [ 'top', 'below_header', 'product' ], true ) ? $v : 'below_header';
	}

	/** The places a banner can be put, named. */
	public static function locations(): array {
		return [
			'top'          => __( 'Top of site — above the header', 'dazont-ecom' ),
			'below_header' => __( 'Below the header — under the menu', 'dazont-ecom' ),
			'product'      => __( 'Product page', 'dazont-ecom' ),
		];
	}

	/** A pixel value the shop typed, kept inside what a banner can survive. */
	public static function px( $value, int $min, int $max ): int {
		// A plain cast rather than stripping non-digits: stripping turned
		// "-5" into 5, which is a bigger number than the one that was typed
		// and the opposite of what somebody meant by it.
		$n = (int) trim( (string) $value );
		return max( $min, min( $max, $n ) );
	}

	/**
	 * A colour the shop typed, made canonical — or the fallback.
	 *
	 * The fields are typed into now, not only clicked, so what arrives is
	 * whatever a person writes down: "fff", "#FFF", a stray space. Refusing
	 * those and quietly falling back to black is losing a setting the owner
	 * believes he made. Only something that is not a colour at all falls back.
	 *
	 * One rule, in one place: the sanitiser that writes it and the reader that
	 * uses it call the same function, so a colour cannot be accepted on the
	 * way in and rejected on the way out.
	 */
	public static function hex( $value, string $fallback ): string {
		$v = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		$v = ltrim( $v, '#' );
		if ( preg_match( '/^[0-9a-f]{3}$/', $v ) ) {
			return '#' . $v[0] . $v[0] . $v[1] . $v[1] . $v[2] . $v[2];
		}
		return preg_match( '/^[0-9a-f]{6}$/', $v ) ? '#' . $v : $fallback;
	}

	/**
	 * The title of the enabled promotion this one would collide with, or ''.
	 *
	 * Public because the calendar creates events too, and a rule written
	 * straight into the option would otherwise walk past the one guard that
	 * keeps two promotions from running at once.
	 */
	public function clash_for( array $rule, ?array $pool = null ): string {
		return $this->conflicting_sale( $rule, $pool );
	}

	public static function get_rules(): array {
		$rules = get_option( self::OPTION, [] );
		return is_array( $rules ) ? $rules : [];
	}

	private static function save_rules( array $rules ): void {
		update_option( self::OPTION, $rules, false );
		// Refresh our store-wide product cache so scope/exclusion changes are picked
		// up. WooCommerce's own on-sale transient is left intact — our filter merges
		// dynamic IDs into it on every read, so no forced cache-miss gap.
		delete_transient( 'dze_all_saleable_ids' );
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
	 *
	 * $pool is the set of rules to judge against. A batch being switched on
	 * passes the set it is building, so two promotions of the same batch
	 * cannot both come on behind each other's back; everything else lets it
	 * default to what the shop holds.
	 *
	 * @param array|null $pool Rules as they stand, or null to read the option.
	 */
	private function conflicting_sale( array $rule, ?array $pool = null ): string {
		if ( ( $rule['type'] ?? '' ) !== 'sale' ) {
			return '';
		}
		foreach ( ( null === $pool ? self::get_rules() : $pool ) as $oid => $other ) {
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
			// Make sure the struck-through "on sale" price + Sale! badge actually
			// render for our dynamic discount (WooCommerce only knows native sales).
			add_filter( 'woocommerce_product_is_on_sale',           [ $this, 'filter_is_on_sale' ], 20, 2 );
			// And that badge can say the only thing a shopper wants from it:
			// how much this product saves him today. "Sale!" is a claim; a
			// figure is a reason. Settings → Discounts → General decides, and
			// the filter is not even hung when it is set to leave the shop's
			// own badge alone.
			if ( 'saved' === self::badge_mode() ) {
				add_filter( 'woocommerce_sale_flash', [ $this, 'saved_flash' ], 20, 3 );
			}
			// Feed our discounted products into WooCommerce's on-sale product list so
			// [products on_sale="true"], the On-Sale page and widgets include them.
			add_filter( 'transient_wc_products_onsale',             [ $this, 'filter_onsale_ids' ] );

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
		// Rounded DOWN when charm rounding is on, so the price on the shelf is
		// never above the percentage announced next to it. Must stay identical
		// to set_row_sale() or the displayed price and the stored sale meta
		// would disagree.
		return DZE_Price::charm( $price * ( 1 - $percent / 100 ), 'down' );
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

	/** Force "on sale" when our dynamic discount applies, so the struck price + badge show. */
	public function filter_is_on_sale( $on_sale, $product ) {
		if ( $on_sale || ! $product instanceof \WC_Product ) {
			return $on_sale;
		}
		return $this->catalog_percent_for( $product ) > 0 ? true : $on_sale;
	}

	/**
	 * The corner badge, carrying the saving instead of the word "Sale".
	 *
	 * Same badge, same corner, same class: the theme places it, we only change
	 * what it says. The figure is the difference between the price that is
	 * struck through and the price being charged — whatever produced it, ours
	 * or a native WooCommerce sale — so the badge can never contradict the two
	 * prices printed underneath it.
	 *
	 * A variable product whose variations do not all save the same amount says
	 * "up to": one figure for a range would be true of one variation and wrong
	 * for the rest. Prices come from the objects the loop has already loaded
	 * and from WooCommerce's own variation-price cache — no query is added to
	 * a shop page.
	 *
	 * @param string     $html    The badge WooCommerce drew.
	 * @param mixed      $post    Unused, kept for the filter signature.
	 * @param mixed      $product The product the badge belongs to.
	 * @return string
	 */
	/**
	 * The general settings of everything this module does to the shop.
	 *
	 * One screen for the decisions that are not a promotion — what the corner
	 * badge says, and, beside it, the price ending every computed price lands
	 * on. They were scattered between a tab about API keys and nowhere at all;
	 * a shop looking for "how my discounts behave" now has one place to look.
	 */
	public static function render_general_settings(): void {
		$mode = self::badge_mode();
		?>
		<h2><?php esc_html_e( 'The badge on a product', 'dazont-ecom' ); ?></h2>
		<form method="post" action="options.php" class="dze-admin">
			<?php settings_fields( 'dze_discount_display_options' ); ?>
			<p class="description">
				<?php esc_html_e( 'What the corner badge says while a promotion is running. It is WooCommerce\'s own badge, in its own place, dressed by your theme — only the words change, and only on the page of the product itself: a category page keeps "Sale!", because thirty figures in a grid is a wall of numbers.', 'dazont-ecom' ); ?>
			</p>
			<p>
				<select name="<?php echo esc_attr( self::OPT_BADGE ); ?>">
					<option value="saved" <?php selected( 'saved', $mode ); ?>><?php esc_html_e( 'The amount saved — "Save $12.00"', 'dazont-ecom' ); ?></option>
					<option value="sale" <?php selected( 'sale', $mode ); ?>><?php esc_html_e( 'Leave the shop\'s own badge alone — "Sale!"', 'dazont-ecom' ); ?></option>
				</select>
				<?php submit_button( __( 'Save', 'dazont-ecom' ), 'secondary', 'submit', false ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'The figure is the difference between the price struck through and the price charged, in the shop\'s currency, so it can never contradict the two prices printed under it. A variable product whose variations do not all save the same says "Save up to".', 'dazont-ecom' ); ?>
			</p>
		</form>
		<?php
	}

	public static function badge_mode(): string {
		return 'saved' === (string) get_option( self::OPT_BADGE, 'saved' ) ? 'saved' : 'sale';
	}

	public function saved_flash( $html, $post, $product ) {
		if ( ! $product instanceof \WC_Product || ! function_exists( 'wc_price' ) ) {
			return $html;
		}
		// The product's OWN page, and only it. A category page is thirty tiles
		// and a figure on each of them is a wall of numbers — the badge there
		// says "on sale", which is all a list has to say. The related products
		// at the bottom of a product page are a list too, so the check is the
		// product being looked at, not the kind of page.
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $html;
		}
		if ( (int) $product->get_id() !== (int) get_queried_object_id() ) {
			return $html;
		}
		$spread = false;
		if ( $product->is_type( 'variable' ) ) {
			$regular = (float) $product->get_variation_regular_price( 'max', true );
			$now     = (float) $product->get_variation_price( 'max', true );
			$spread  = round( $regular - $now, 2 )
				!== round( (float) $product->get_variation_regular_price( 'min', true )
					- (float) $product->get_variation_price( 'min', true ), 2 );
		} else {
			$regular = (float) $product->get_regular_price();
			$now     = (float) $product->get_price();
		}
		$saved = round( $regular - $now, 2 );
		if ( $saved <= 0 ) {
			// On sale with nothing to show for it: leave the badge as the shop
			// drew it rather than print "Save $0".
			return $html;
		}
		$said = $spread
			/* translators: %s: amount saved, e.g. $12.00 */
			? sprintf( __( 'Save up to %s', 'dazont-ecom' ), wc_price( $saved ) )
			/* translators: %s: amount saved, e.g. $12.00 */
			: sprintf( __( 'Save %s', 'dazont-ecom' ), wc_price( $saved ) );
		// WooCommerce's own markup, to the letter: the theme dresses this badge
		// and it must dress ours identically. A class of our own would be a
		// second appearance to keep in step with the first.
		return '<span class="onsale">' . wp_kses_post( $said ) . '</span>';
	}

	/**
	 * Merge our dynamically-discounted product IDs into WooCommerce's on-sale list
	 * (wc_get_product_ids_on_sale) so on-sale queries — [products on_sale="true"],
	 * the On-Sale page, widgets — include them. Only touches the cached array; on a
	 * cache miss ($ids === false) we let WooCommerce rebuild its native list first.
	 */
	public function filter_onsale_ids( $ids ) {
		if ( ! is_array( $ids ) ) {
			return $ids;
		}
		$dyn = $this->dynamic_on_sale_ids();
		if ( empty( $dyn ) ) {
			return $ids;
		}
		return array_values( array_unique( array_merge( array_map( 'intval', $ids ), $dyn ) ) );
	}

	private ?array $on_sale_ids = null;

	/** Product IDs currently discounted by any active sale or automatic rule. */
	public function dynamic_on_sale_ids(): array {
		if ( null !== $this->on_sale_ids ) {
			return $this->on_sale_ids;
		}
		// "raw" = small lists that still need per-id exclusion checks (automatic
		// top-sellers, explicit product scope). "clean" = SQL results already
		// filtered for stock/exclusions, so we never has_term() over a huge list.
		$raw   = array_map( 'intval', array_keys( $this->autobest_map() ) );
		$clean = [];
		foreach ( $this->rules_of_type( 'sale' ) as $rule ) {
			if ( (float) ( $rule['percent'] ?? 0 ) <= 0 ) {
				continue;
			}
			$scope = $rule['scope'] ?? 'all';
			if ( 'products' === $scope ) {
				$raw = array_merge( $raw, array_map( 'intval', $rule['product_ids'] ?? [] ) );
			} elseif ( 'categories' === $scope ) {
				$clean = array_merge( $clean, $this->product_ids_in_categories( array_map( 'intval', $rule['category_ids'] ?? [] ) ) );
			} else {
				$clean = array_merge( $clean, $this->all_saleable_product_ids() );
			}
		}
		$raw = array_filter( array_unique( $raw ), function ( $id ) {
			return $id > 0 && ! $this->is_excluded( $id );
		} );
		$ids = array_values( array_unique( array_merge( array_map( 'intval', $raw ), array_map( 'intval', $clean ) ) ) );
		return $this->on_sale_ids = $ids;
	}

	/** Published, in-stock, non-excluded product IDs in the given categories (exact terms). */
	private function product_ids_in_categories( array $cats ): array {
		$cats = array_filter( array_map( 'intval', $cats ) );
		if ( empty( $cats ) ) {
			return [];
		}
		global $wpdb;
		$in  = implode( ',', $cats );
		$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
			  AND tt.taxonomy = 'product_cat' AND tt.term_id IN ({$in})
			  AND " . $this->in_stock_sql( 'p' ) . $this->exclusion_sql( 'p' ) . $this->default_lang_sql( 'p' );
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** All published, in-stock, non-excluded product IDs (store-wide sale). Cached 6h. */
	private function all_saleable_product_ids(): array {
		$cached = get_transient( 'dze_all_saleable_ids' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
			  AND " . $this->in_stock_sql( 'p' ) . $this->exclusion_sql( 'p' ) . $this->default_lang_sql( 'p' );
		$ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		set_transient( 'dze_all_saleable_ids', $ids, 6 * HOUR_IN_SECONDS );
		return $ids;
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

	/** Rebuild the desired state, write a first chunk now, then finish in background. */
	public function queue_sale_sync(): void {
		delete_option( self::OPT_SYNC_QUEUE ); // force a fresh diff.
		// Process the first chunk synchronously so the DB reflects the change right
		// away (feeds/GMC read real meta); the rest continues in the background — and
		// no longer silently stalls when WP-cron doesn't fire on a rule change alone.
		$this->run_sale_sync();
	}

	/** Drive the whole sync to completion in this request (bounded). */
	private function run_sale_sync_to_end(): void {
		delete_option( self::OPT_SYNC_QUEUE );
		$guard = 0;
		do {
			$this->run_sale_sync( false );
		} while ( null !== get_option( self::OPT_SYNC_QUEUE, null ) && ++$guard < 500 );
		if ( null !== get_option( self::OPT_SYNC_QUEUE, null ) ) {
			$this->kick_sale_sync(); // very large catalogue — let the background finish.
		}
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

	/**
	 * Worker: applies/releases native sale prices, a chunk at a time. Re-kicks a
	 * background pass while work remains, unless $kick is false (used when we drive
	 * the loop synchronously ourselves, e.g. the manual "Resync now" button).
	 */
	public function run_sale_sync( bool $kick = true ): void {
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
			if ( $kick ) {
				$this->kick_sale_sync();
			}
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

	/** Number of products currently carrying a materialised automatic sale price. */
	public function materialized_count(): int {
		return count( $this->managed_sale_ids() );
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
		$sale = DZE_Price::charm( $regular * ( 1 - $pct / 100 ), 'down' );
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
		$this->run_sale_sync_to_end();
		wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'resynced' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// =========================================================================
	// Banners
	// =========================================================================

	/** @var array<string,bool> Rule ids already rendered this request (dedupe). */
	private static array $rendered = [];
	private static bool $timer_script_done = false;

	/** This module's entry on the Shortcodes screen. */
	public static function shortcode_card(): array {
		return [
			'tag'     => 'dze_promo_banner',
			'title'   => __( 'Promotion banner', 'dazont-ecom' ),
			'summary' => __( 'The banner of every running sale, where you decide to put it.', 'dazont-ecom' ),
			'body'    => [ self::class, 'render_shortcode_card' ],
		];
	}

	public static function render_shortcode_card(): void {
		?>
		<p class="description"><?php esc_html_e( 'Prints the banner of each sale rule whose banner is switched on and whose dates are running. Rules already placed automatically (shop notice, above the products…) keep their own position — this shortcode is for putting one somewhere else, on a landing page for instance. A rule never prints twice on the same page.', 'dazont-ecom' ); ?></p>
		<p><?php esc_html_e( 'No attributes: what a banner says, and which rule it belongs to, is set on the rule itself.', 'dazont-ecom' ); ?>
			<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::MENU_SLUG_EVENTS ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Marketing Events →', 'dazont-ecom' ); ?></a>
		</p>
		<?php
	}

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
		$text = self::banner_line( $rule, $text );

		self::$rendered[ $id ] = true;

		$style = self::banner_style();
		$bg    = $style['bg'];
		$color = $style['color'];

		$timer = '';
		if ( ! empty( $rule['banner_timer'] ) && ! empty( $rule['end'] ) ) {
			[ , $end_ts ] = $this->window_ts( $rule );
			if ( $end_ts > time() ) {
				$timer = ' <span class="dze-timer" data-end="' . esc_attr( (string) $end_ts ) . '"></span>';
				$this->print_timer_script();
			}
		}

		// The weight and the face still come from the theme. The size is set
		// only when the shop asked for one: left at zero, the banner reads in
		// whatever the theme puts there, which is what it always did.
		printf(
			'<div class="dze-promo-banner" style="background:%1$s;color:%2$s;text-align:center;padding:%5$dpx;%6$s">%3$s%4$s</div>',
			esc_attr( $bg ),
			esc_attr( $color ),
			esc_html( $text ),
			$timer, // already-escaped markup built above.
			(int) $style['pad'],
			$style['size'] > 0 ? 'font-size:' . (int) $style['size'] . 'px;' : ''
		);
	}

	/**
	 * What the banner actually says, in the market it is being read in.
	 *
	 * It used to be the name and then "-10% OFF", stuck on here. That made the
	 * figure live — a percentage typed by hand goes on saying 15 the day the
	 * promotion is changed to 20 — but it also made every market read a French
	 * line with an English tail: "Offre de rentrée! -10% OFF". A sentence
	 * assembled from two languages cannot be written well in either.
	 *
	 * So the line is ONE sentence, written and translated as one — the
	 * discount announced inside it, in that market's own words and its own
	 * typography. The figure stays live all the same: whatever percentage the
	 * sentence carries is rewritten to the promotion's own before it is shown,
	 * so changing 10 to 20 changes every market at once and none of them can
	 * drift.
	 */
	public static function banner_line( array $rule, string $text ): string {
		return self::refresh_percent( $text, (float) ( $rule['percent'] ?? 0 ) );
	}

	/**
	 * Rewrites the percentage inside a sentence to the promotion's own.
	 *
	 * Only the digits change. The minus sign, the spacing and the position are
	 * the market's business — French writes "-10 %" with a space and English
	 * "10% off" without, and neither is corrected here.
	 */
	public static function refresh_percent( string $text, float $pct ): string {
		$text = trim( $text );
		if ( $pct <= 0 || '' === $text ) {
			return $text;
		}
		$figure = rtrim( rtrim( number_format( $pct, 2, '.', '' ), '0' ), '.' );
		return (string) preg_replace_callback(
			'/\d+(?:[.,]\d+)?(?=\s*%)/u',
			static fn(): string => $figure,
			$text
		);
	}

	/** True when a sentence names a percentage at all. */
	public static function says_percent( string $text ): bool {
		return (bool) preg_match( '/\d+(?:[.,]\d+)?\s*%/u', $text );
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
		$map    = [];
		$source = null;
		foreach ( $this->rules_of_type( 'sale' ) as $rule ) {
			if ( empty( $rule['hero_swap_enabled'] ) || empty( $rule['hero_event_id'] ) ) {
				continue;
			}
			// The picture the home page shows is the SHOP's, not the event's:
			// it is the same for every promotion, so it is answered once here
			// rather than asked again on every event screen. Read only when a
			// promotion actually swaps something — a shop with no swap running
			// must not pay for the question.
			if ( null === $source ) {
				$source = self::hero_source();
			}
			$from = $source ?: (int) ( $rule['hero_source_id'] ?? 0 ); // promotions saved before that rule.
			if ( $from ) {
				$map[ $from ] = (int) $rule['hero_event_id'];
			}
		}
		return $this->hero_map = $map;
	}

	/**
	 * The picture the home page opens on.
	 *
	 * Read FROM the home page, and from nowhere else. There was a field beside
	 * it to point at another one, and it was a second answer to a question
	 * that has one: the day the two disagreed, the shop would have been
	 * swapping a picture it had stopped showing months earlier. The home page
	 * is where that picture is changed, so the home page is what is read.
	 */
	public static function hero_source(): int {
		return self::detect_hero();
	}

	/**
	 * Which attachment the front page's main image is, worked out from the page
	 * itself: its featured image, failing that the first image in its content,
	 * failing that the first one a page builder named in its own data.
	 *
	 * Cached, because it costs a post read and the answer changes about once a
	 * year. NEVER returns an image a promotion uses as its EVENT picture: while
	 * a swap is running the home page shows that one, and mistaking it for the
	 * page's own would map it onto itself — the original would be replaced by
	 * nothing and lost the day the promotion ended.
	 */
	public static function detect_hero(): int {
		$cached = get_transient( 'dze_hero_src' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$id    = 0;
		$front = (int) get_option( 'page_on_front' );
		if ( $front ) {
			$id = (int) get_post_thumbnail_id( $front );
			if ( ! $id ) {
				$post = get_post( $front );
				$body = $post ? (string) $post->post_content : '';
				if ( preg_match( '/wp-image-(\d+)/', $body, $m ) ) {
					$id = (int) $m[1];
				} elseif ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $body, $m ) ) {
					$id = (int) attachment_url_to_postid( $m[1] );
				}
			}
			if ( ! $id ) {
				// A page built with a page builder keeps its content in meta,
				// not in the post: the first library image it names answers.
				// No builder is required and none is named — a page that has
				// no such meta simply matches nothing.
				$built = (string) get_post_meta( $front, '_elementor_data', true );
				if ( '' !== $built && preg_match( '~"url":"([^"]+/wp-content/uploads/[^"]+\.(?:jpe?g|png|webp))"~i', $built, $m ) ) {
					$id = (int) attachment_url_to_postid( str_replace( '\\/', '/', $m[1] ) );
				}
			}
		}
		foreach ( self::get_rules() as $rule ) {
			if ( $id && (int) ( $rule['hero_event_id'] ?? 0 ) === $id ) {
				$id = 0; // that is an event picture, not the page's own.
				break;
			}
		}
		set_transient( 'dze_hero_src', $id, 6 * HOUR_IN_SECONDS );
		return $id;
	}

	/** The home page changed: read it again rather than serve yesterday's answer. */
	public static function forget_hero(): void {
		delete_transient( 'dze_hero_src' );
	}

	/**
	 * Makes the picture that replaces the home page's own while an event runs.
	 *
	 * The instructions are the shop's, and they ship EMPTY: what that picture
	 * should look like is not something this plugin has an opinion about. What
	 * it does impose is the two facts no prompt should have to repeat — the
	 * promotion's title and the days it runs — and the home page's own picture
	 * as the image to work from, so what comes back fits the same place at the
	 * same shape.
	 *
	 * @return array{id:int,url:string}
	 * @throws RuntimeException When there is nothing to work from, or fal fails.
	 */
	public static function make_hero_image( array $rule, string $prompt = '' ): array {
		if ( ! class_exists( 'DZE_Content' ) ) {
			throw new RuntimeException( __( 'The product content module is off, and it is the one that talks to fal.ai.', 'dazont-ecom' ) );
		}
		$source = self::hero_source();
		if ( ! $source || ! wp_attachment_is_image( $source ) ) {
			throw new RuntimeException( __( 'The home page picture could not be read. Set it under Settings → Marketing events first.', 'dazont-ecom' ) );
		}
		$content = DZE_Content::instance();
		$fmt     = get_option( 'date_format' ) ?: 'Y-m-d';
		$day     = static function ( $ymd ) use ( $fmt ): string {
			$ts = $ymd ? strtotime( (string) $ymd . ' 00:00:00' ) : false;
			return $ts ? (string) wp_date( $fmt, $ts ) : '';
		};
		$title = trim( (string) ( $rule['title'] ?? '' ) );
		$text  = trim( $prompt );
		$text .= ( '' !== $text ? "\n\n" : '' ) . '--- THE PROMOTION ---' . "\n"
			. 'Title: ' . ( '' !== $title ? $title : __( 'Promotion', 'dazont-ecom' ) ) . "\n"
			. 'Runs: ' . $day( $rule['start'] ?? '' ) . ' → ' . $day( $rule['end'] ?? '' );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		DZE_Ai_Usage::unit( 'hero_image' );
		try {
			// The home page picture is the only reference, and the ratio is
			// its own: this image is going to sit exactly where that one sits.
			$url = $content->fal_generate( $text, [ $content->fal_source_data_uri( $source, 'full' ) ], 'auto' );
		} finally {
			DZE_Ai_Usage::unit();
		}
		DZE_Ai_Usage::finished( 'hero_image' );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			throw new RuntimeException( $tmp->get_error_message() );
		}
		$name = mb_substr( '' !== $title ? $title : __( 'Promotion', 'dazont-ecom' ), 0, 80 );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$att  = $content->file_to_library(
			(string) $tmp,
			strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ),
			sanitize_title( $name ),
			$name
		);
		if ( ! $att ) {
			throw new RuntimeException( __( 'The picture came back but could not be filed in the library.', 'dazont-ecom' ) );
		}
		return [
			'id'  => (int) $att,
			'url' => (string) ( wp_get_attachment_image_url( (int) $att, 'medium' ) ?: $url ),
		];
	}

	/** The button beside the event picture. */
	public function ajax_hero_image(): void {
		check_ajax_referer( self::SAVE_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		// Read from the FORM, not from storage: the picture is asked for while
		// the promotion is being written, and a title typed a minute ago has
		// not been saved yet.
		$rule = [
			'title' => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'start' => isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '',
			'end'   => isset( $_POST['end'] ) ? sanitize_text_field( wp_unslash( $_POST['end'] ) ) : '',
		];
		$prompt = class_exists( 'DZE_Marketing_Ai' ) ? DZE_Marketing_Ai::hero_prompt() : '';
		try {
			$made = self::make_hero_image( $rule, $prompt );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( $made );
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
		// The count rides on the menu label, as everywhere else in the plugin:
		// suggested events waiting for a yes or a no are visible without
		// opening the screen they wait on.
		$ev_label = __( 'Marketing Events', 'dazont-ecom' );
		$ev_wait  = ( class_exists( 'DZE_Marketing_Ai' ) && DZE_Modules::enabled( 'marketing_ai' ) )
			? DZE_Marketing_Ai::pending_count()
			: 0;
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			$ev_label,
			$ev_wait
				? $ev_label . ' <span class="update-plugins count-' . (int) $ev_wait . '"><span class="plugin-count">'
					. esc_html( number_format_i18n( $ev_wait ) ) . '</span></span>'
				: $ev_label,
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
			// The translate endpoint belongs to the Marketing module, and
			// checks its own nonce.
			'maiNonce' => class_exists( 'DZE_Marketing_Ai' ) ? wp_create_nonce( DZE_Marketing_Ai::NONCE ) : '',
			'i18n'    => [
				'counting' => __( 'Counting…', 'dazont-ecom' ),
				'titleFirst'  => __( 'Write the promotion\'s title first — it is what the banner says.', 'dazont-ecom' ),
				'translating' => __( 'Writing…', 'dazont-ecom' ),
				'translated'  => __( 'Written — check them, then save.', 'dazont-ecom' ),
				'trFailed'    => __( 'Could not write them.', 'dazont-ecom' ),
				'pickRows'    => __( 'Tick the promotions you mean first.', 'dazont-ecom' ),
				'heroMaking'  => __( 'Making the picture… this takes about a minute.', 'dazont-ecom' ),
				'heroDone'    => __( 'Made — check it, then save the event.', 'dazont-ecom' ),
				'heroFailed'  => __( 'The picture could not be made.', 'dazont-ecom' ),
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
		// Opening the list is also the moment to notice that Google is behind
		// — on a promotion saved before the automatic sync existed, or one a
		// failed account left half-sent. Nothing is fetched here: the work is
		// put in the queue and done in the background.
		self::gmc_follow_all();
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
		if ( in_array( $type, self::EVENT_TYPES, true ) ) {
			$scope = 'all'; // a marketing event is the whole shop on sale.
		}
		$b_loc   = in_array( $in['banner_location'] ?? '', [ 'top', 'below_header', 'product' ], true ) ? $in['banner_location'] : self::default_location();
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
			// What the banner says IS the title: one field, so the two can
			// never drift apart. The colours belong to the shop, not here.
			'banner_text'      => in_array( $type, self::EVENT_TYPES, true )
				? sanitize_text_field( $in['title'] ?? '' )
				: sanitize_text_field( $in['banner_text'] ?? '' ),
			'banner_location'  => $b_loc,
			'product_position' => $b_pos,
			'banner_hooks'      => sanitize_text_field( $in['banner_hooks'] ?? '' ),
			'banner_timer'      => ! empty( $in['banner_timer'] ),
			'banner_text_i18n'  => $this->sanitize_i18n( $in['banner_text_i18n'] ?? [] ),
			// The screen asks which languages to LEAVE OUT; what is stored is
			// which ones it runs in, because that is what every reader of this
			// rule already understands. Untouched when the form did not carry
			// the block at all — a rule type without languages must not lose
			// the ones it has.
			'languages'         => self::languages_from( $in, (array) ( $rules[ $id ]['languages'] ?? [] ) ),
			'hero_swap_enabled' => ! empty( $in['hero_swap_enabled'] ),
			// The image to replace is the shop's, not this promotion's: the
			// field is gone from this screen, so what an older promotion
			// recorded is kept rather than blanked by a save that never
			// carried it.
			'hero_source_id'    => array_key_exists( 'hero_source_id', $in )
				? absint( $in['hero_source_id'] )
				: absint( $rules[ $id ]['hero_source_id'] ?? 0 ),
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

		// MERGED onto what is stored, never substituted for it. This array is
		// built from the form, and a promotion carries things no form ever
		// shows: gmc_sync — the record of which Merchant Center accounts hold
		// this promotion and under which id — is the one that matters. Rebuilt
		// from scratch, every save of a promotion threw that record away, so
		// the sync dots went blank on a promotion Google was really running,
		// and cancel_rule(), which walks exactly those records to take a
		// promotion down, had nothing left to walk: deleting the promotion
		// stopped reaching Google at all. The form's fields win; everything
		// else stays.
		$rules[ $id ] = array_merge( (array) ( $rules[ $id ] ?? [] ), $rule );
		self::save_rules( $rules );
		$this->queue_sale_sync();
		self::schedule_i18n( $id );

		// One screen, one form, one Save button: what other modules planted on
		// this page is saved by this very submit, not by a save of their own.
		do_action( 'dze_discount_saved', $id, $rule, $in );

		self::gmc_follow( $id );

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * The same, for every promotion at once — used where the state of all of
	 * them is about to be shown.
	 */
	public static function gmc_follow_all(): void {
		foreach ( self::get_rules() as $id => $rule ) {
			if ( ( $rule['type'] ?? '' ) === 'sale' ) {
				self::gmc_follow( (string) $id );
			}
		}
	}

	/**
	 * Google is told what changed — from every path that changes a promotion.
	 *
	 * Saving one, switching one on, switching a batch off: each of them ends
	 * here, and what has to happen (send it, re-send it, take it down, or
	 * nothing at all) is decided in one place rather than three. The owner has
	 * nothing to press: a promotion that is on and dated reaches Merchant
	 * Center by itself, shortly after the save, and only when something Google
	 * would actually see has changed.
	 */
	public static function gmc_follow( string $id ): void {
		if ( '' === $id || ! class_exists( 'DZE_Modules' ) || ! DZE_Modules::enabled( 'gmc' ) || ! class_exists( 'DZE_Gmc' ) ) {
			return;
		}
		DZE_Gmc::instance()->on_rule_saved( $id );
	}

	/**
	 * Several promotions at once: enable, disable, delete.
	 *
	 * The same decisions the row links take one at a time, and the same
	 * guards: only one promotion may run at a time, so enabling a batch
	 * enables what does not clash and says how many it had to leave alone
	 * rather than silently switching one of them on.
	 */
	public function handle_bulk(): void {
		check_admin_referer( self::SAVE_NONCE );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$what  = isset( $_POST['bulk'] ) ? sanitize_key( wp_unslash( $_POST['bulk'] ) ) : '';
		$ids   = array_filter( array_map( 'sanitize_key', (array) ( $_POST['rules'] ?? [] ) ) );
		$mode  = isset( $_POST['mode'] ) && 'events' === $_POST['mode'] ? 'events' : 'discounts';
		$back  = 'events' === $mode ? self::MENU_SLUG_EVENTS : self::MENU_SLUG;
		$args  = [ 'page' => $back ];

		if ( ! $ids || ! in_array( $what, [ 'enable', 'disable', 'delete' ], true ) ) {
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}
		$rules        = self::get_rules();
		$done         = 0;
		$skipped      = 0;
		$gmc_failures = [];

		foreach ( $ids as $id ) {
			if ( ! isset( $rules[ $id ] ) ) {
				continue;
			}
			if ( 'delete' === $what ) {
				// Pushed to Merchant Center: cancelled there too, as one delete does.
				if ( ! empty( $rules[ $id ]['gmc_sync'] ) && class_exists( 'DZE_Gmc' ) ) {
					$gmc_failures = array_merge( $gmc_failures, DZE_Gmc::instance()->cancel_rule( $rules[ $id ] ) );
				}
				unset( $rules[ $id ] );
				do_action( 'dze_discount_deleted', $id );
				$done++;
				continue;
			}
			if ( 'disable' === $what ) {
				if ( ! empty( $rules[ $id ]['enabled'] ) ) {
					$rules[ $id ]['enabled'] = false;
					$done++;
				}
				continue;
			}
			// Enable: the overlap guard reads the rules as they stand in this
			// loop, so two promotions of the same batch cannot both come on.
			if ( ! empty( $rules[ $id ]['enabled'] ) ) {
				continue;
			}
			$clash = $this->conflicting_sale( $rules[ $id ], $rules );
			if ( '' !== $clash ) {
				$skipped++;
				continue;
			}
			$rules[ $id ]['enabled'] = true;
			$done++;
			self::schedule_i18n( $id );
		}

		self::save_rules( $rules );
		$this->queue_sale_sync();
		if ( 'delete' !== $what ) {
			foreach ( $ids as $id ) {
				self::gmc_follow( $id );
			}
		}

		if ( 'delete' === $what ) {
			$args['deleted'] = 1;
			self::report_cancel( $gmc_failures );
		} elseif ( $skipped ) {
			set_transient( 'dze_discount_notice', sprintf(
				/* translators: 1: number switched on, 2: number left off */
				_n(
					'%1$d promotion switched on. %2$d left off: its dates overlap one that is already running — only one promotion runs at a time.',
					'%1$d promotions switched on. %2$d left off: their dates overlap one that is already running — only one promotion runs at a time.',
					$skipped,
					'dazont-ecom'
				),
				$done,
				$skipped
			), 60 );
		} else {
			$args['saved'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Says what Merchant Center did with a take-down.
	 *
	 * Silence on success — a promotion removed everywhere it was pushed needs
	 * no announcement — and the account and Google's own words on failure,
	 * because that is a live advert the shop has to go and end by hand.
	 *
	 * @param array<int,array{ok:bool,where:string,message:string}> $results
	 */
	private static function report_cancel( array $results ): void {
		$bad = array_filter( $results, static fn( array $r ): bool => empty( $r['ok'] ) );
		if ( ! $bad ) {
			return;
		}
		$lines = [];
		foreach ( $bad as $one ) {
			$lines[] = $one['where'] . ' — ' . $one['message'];
		}
		set_transient( 'dze_discount_notice', sprintf(
			/* translators: %s: the accounts and what Google said */
			__( 'Deleted here, but Merchant Center did not take it down everywhere: %s. Those promotions are still live in Google.', 'dazont-ecom' ),
			implode( ' · ', $lines )
		), 120 );
	}

	/**
	 * The languages a promotion runs in, from the "do not run in" ticks.
	 *
	 * @param array $in     The submitted form.
	 * @param array $stored What the rule holds today.
	 *
	 * @return string[] Empty means every language, which is what every reader expects.
	 */
	private static function languages_from( array $in, array $stored ): array {
		if ( empty( $in['languages_form'] ) ) {
			return $stored; // the block was not on the screen: nothing to say about it.
		}
		$all = array_values( array_filter( array_map(
			static fn( array $l ): string => (string) ( $l['code'] ?? '' ),
			(array) ( class_exists( 'DZE_Wpml' ) ? DZE_Wpml::get_active_languages() : [] )
		) ) );
		if ( ! $all ) {
			return $stored;
		}
		$off = array_values( array_filter( array_map( 'sanitize_key', (array) ( $in['languages_off'] ?? [] ) ) ) );
		$on  = array_values( array_diff( $all, $off ) );
		if ( ! $on ) {
			// Every language ticked would store "runs in none", which reads as
			// "runs in all" everywhere else — a promotion that quietly runs
			// when it was meant to be off. The switch at the top of the page is
			// how a promotion is stopped.
			set_transient( 'dze_discount_notice', __( 'A promotion has to run in at least one language — the last one was kept. To stop it entirely, switch it off.', 'dazont-ecom' ), 60 );
			return $stored ?: [];
		}
		// All of them left on is "everywhere", which is stored as nothing.
		return count( $on ) === count( $all ) ? [] : $on;
	}

	public function handle_delete(): void {
		check_admin_referer( 'dze_discount_delete' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$id    = isset( $_GET['rule'] ) ? sanitize_key( wp_unslash( $_GET['rule'] ) ) : '';
		$rules = self::get_rules();

		// Pushed to Merchant Center: taken down there too, on every account it
		// actually reached — and if one of them refuses, that is said rather
		// than swallowed. A promotion left live in Google after the shop has
		// deleted it is an advert for a price nobody honours.
		if ( isset( $rules[ $id ] ) && ! empty( $rules[ $id ]['gmc_sync'] ) && class_exists( 'DZE_Gmc' ) ) {
			self::report_cancel( DZE_Gmc::instance()->cancel_rule( $rules[ $id ] ) );
		}

		$back = ( isset( $rules[ $id ]['type'] ) && in_array( $rules[ $id ]['type'], self::EVENT_TYPES, true ) ) ? self::MENU_SLUG_EVENTS : self::MENU_SLUG;
		unset( $rules[ $id ] );
		self::save_rules( $rules );
		// What other modules hang off this promotion goes with it.
		do_action( 'dze_discount_deleted', $id );
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
		// Back to the screen this rule belongs to: toggling an event used to
		// land on the Discounts page, which is not where the click happened.
		$back  = ( isset( $rules[ $id ]['type'] ) && in_array( $rules[ $id ]['type'], self::EVENT_TYPES, true ) )
			? self::MENU_SLUG_EVENTS
			: self::MENU_SLUG;
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
					wp_safe_redirect( add_query_arg( [ 'page' => $back ], admin_url( 'admin.php' ) ) );
					exit;
				}
			}
			$rules[ $id ]['enabled'] = $enabling;
			self::save_rules( $rules );
			$this->queue_sale_sync();
			self::gmc_follow( $id );
			if ( $enabling ) {
				self::schedule_i18n( $id );
			}
		}
		wp_safe_redirect( add_query_arg( [ 'page' => $back ], admin_url( 'admin.php' ) ) );
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
