<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Ads: what the ads cost beside what they brought, and the means to cut.
 *
 * « Visualiser combien de dépenses sur les catégories et sur les produits +
 * chiffres clés. Comparer ventes google ads aux ventes hors google ads. Outil
 * pour couper la pub passé un certain seuil, et mettre le produit en
 * quarantaine. Visualiser dans quels pays le magasin fonctionne le mieux. »
 *
 * TWO SOURCES, NEITHER GUESSED.
 * - What the ads COST comes from Google Ads itself. A script the shop pastes
 *   once into its Google Ads account (script()) sends, every day, what each
 *   product cost and brought by country over the last 7, 30 and 90 days.
 *   It runs on Google's servers and needs no developer token. It only reads
 *   the account; it changes nothing there.
 * - What the shop SOLD comes from its own orders (DZE_Sales): WooCommerce's
 *   order attribution says which ones came through an ad.
 *
 * QUARANTINE, NOT A SWITCH. A product or a whole category taken out of the
 * ads stays out until somebody has looked at its page again: « amener
 * l'utilisateur à vérifier la page produit avant de le relancer en ads ».
 * It reaches Google through Merchant Center (excluded_destination on its
 * offers, DZE_Gmc_Feed), which every campaign type obeys, Performance Max
 * included, and which keeps the free listings. Nothing has to be set up in
 * the campaigns.
 */
final class DZE_Ads {

	public const NONCE         = 'dze_ads';
	public const MENU_SLUG     = 'dazont-ecom-ads';
	public const OPT_SECRET    = 'dze_ads_secret';
	public const OPT_ACCOUNTS  = 'dze_ads_accounts';
	public const OPT_RULE      = 'dze_ads_rule';
	public const OPT_SCHEMA    = 'dze_ads_schema';
	public const META_HOLD     = '_dze_ads_quarantine';
	public const ROUTE_NS      = 'dazont/v1';
	public const ROUTE         = '/ads-report';
	public const SPANS         = [ 7, 30, 90 ];
	private const SCHEMA       = 1;
	private const MAX_BODY     = 25 * 1048576;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// The report comes from Google's servers: a REST route, not an admin page.
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init', [ $this, 'maybe_install' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ 'DZE_Ads_Screen', 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ 'DZE_Ads_Screen', 'assets' ] );
		add_action( 'wp_ajax_dze_ads_hold', [ 'DZE_Ads_Screen', 'ajax_hold' ] );
		add_action( 'wp_ajax_dze_ads_opened', [ 'DZE_Ads_Screen', 'ajax_opened' ] );
		add_action( 'wp_ajax_dze_ads_new_key', [ 'DZE_Ads_Screen', 'ajax_new_key' ] );
	}

	// =========================================================================
	// Storage
	// =========================================================================

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dze_ads_stats';
	}

	public function maybe_install(): void {
		if ( (int) get_option( self::OPT_SCHEMA, 0 ) >= self::SCHEMA ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		// ONE ROW PER OFFER, COUNTRY AND SPAN, replaced whole by every report.
		// No day column: the screens read the last 7, 30 or 90 days, and a row
		// per day would weigh ninety times as much for a question nobody asks.
		dbDelta( "CREATE TABLE {$table} (
			account VARCHAR(20) NOT NULL,
			span SMALLINT UNSIGNED NOT NULL,
			offer VARCHAR(64) NOT NULL,
			country CHAR(2) NOT NULL DEFAULT '',
			cost BIGINT NOT NULL DEFAULT 0,
			clicks INT UNSIGNED NOT NULL DEFAULT 0,
			impressions INT UNSIGNED NOT NULL DEFAULT 0,
			conversions DECIMAL(12,2) NOT NULL DEFAULT 0,
			value DECIMAL(14,2) NOT NULL DEFAULT 0,
			PRIMARY KEY  (account,span,offer,country),
			KEY offer (offer)
		) {$charset};" );
		update_option( self::OPT_SCHEMA, self::SCHEMA, false );
	}

	/**
	 * The key the script signs its reports with. Made the first time it is asked for.
	 */
	public static function secret(): string {
		$s = (string) get_option( self::OPT_SECRET, '' );
		if ( strlen( $s ) < 32 ) {
			$s = wp_generate_password( 48, false, false );
			update_option( self::OPT_SECRET, $s, false );
		}
		return $s;
	}

	/** A new key: the script pasted before stops being accepted. */
	public static function new_secret(): string {
		delete_option( self::OPT_SECRET );
		return self::secret();
	}

	/**
	 * The Google Ads accounts that have reported, by customer id.
	 *
	 * @return array<string,array{name:string,currency:string,until:string,received:int,rows:int}>
	 */
	public static function accounts(): array {
		$a = get_option( self::OPT_ACCOUNTS, [] );
		return is_array( $a ) ? $a : [];
	}

	// =========================================================================
	// The report, from Google's servers
	// =========================================================================

	public function routes(): void {
		register_rest_route( self::ROUTE_NS, self::ROUTE, [
			'methods'             => 'POST',
			'callback'            => [ $this, 'receive' ],
			// The signature IS the permission: the script holds the key, nobody else.
			'permission_callback' => '__return_true',
		] );
	}

	public static function endpoint(): string {
		// The main address, whatever domain a visit came in on (DZE_Wpml::site_host()).
		$home = untrailingslashit( (string) get_option( 'home' ) );
		return $home . '/wp-json/' . self::ROUTE_NS . self::ROUTE;
	}

	/**
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive( $req ) {
		$body = (string) $req->get_body();
		$sig  = strtolower( trim( (string) $req->get_header( 'x_dazont_signature' ) ) );
		if ( '' === $body || strlen( $body ) > self::MAX_BODY ) {
			return new WP_Error( 'dze_ads_size', 'Empty or too large.', [ 'status' => 400 ] );
		}
		if ( ! self::signed( $body, $sig ) ) {
			return new WP_Error( 'dze_ads_signature', 'The key does not match: paste the script again from Dazont Ecom.', [ 'status' => 403 ] );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'dze_ads_json', 'Unreadable report.', [ 'status' => 400 ] );
		}
		$saved = self::store( $data );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		self::forget_cache();
		$cut = self::apply_rule();
		return new WP_REST_Response( [ 'ok' => true, 'rows' => $saved, 'quarantined' => $cut ], 200 );
	}

	/** Whether $sig is the shop key's HMAC-SHA256 of $body. */
	public static function signed( string $body, string $sig ): bool {
		$want = hash_hmac( 'sha256', $body, self::secret() );
		return '' !== $sig && hash_equals( $want, $sig );
	}

	/**
	 * Writes one account's report: every span replaced whole.
	 *
	 * @return int|WP_Error Rows written.
	 */
	public static function store( array $data ) {
		global $wpdb;
		$account = preg_replace( '/[^0-9]/', '', (string) ( $data['account'] ?? '' ) );
		if ( '' === $account ) {
			return new WP_Error( 'dze_ads_account', 'No account id.', [ 'status' => 400 ] );
		}
		$countries = [];
		foreach ( (array) ( $data['countries'] ?? [] ) as $res => $code ) {
			$code = strtoupper( (string) $code );
			if ( 2 === strlen( $code ) ) {
				$countries[ (string) $res ] = $code;
			}
		}
		$written = 0;
		$table   = self::table();
		foreach ( self::SPANS as $span ) {
			if ( ! isset( $data['spans'][ (string) $span ] ) && ! isset( $data['spans'][ $span ] ) ) {
				continue;
			}
			$rows = (array) ( $data['spans'][ (string) $span ] ?? $data['spans'][ $span ] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, replaced whole.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE account = %s AND span = %d", $account, $span ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$merged = [];
			foreach ( $rows as $r ) {
				if ( ! is_array( $r ) || count( $r ) < 7 ) {
					continue;
				}
				$offer = substr( strtolower( trim( (string) $r[0] ) ), 0, 64 );
				if ( '' === $offer ) {
					continue;
				}
				$cc  = $countries[ (string) $r[1] ] ?? ( 2 === strlen( (string) $r[1] ) ? strtoupper( (string) $r[1] ) : '' );
				$key = $offer . '|' . $cc;
				$m   = $merged[ $key ] ?? [ $offer, $cc, 0, 0, 0, 0.0, 0.0 ];
				$m[2] += (int) $r[2];
				$m[3] += (int) $r[3];
				$m[4] += (int) $r[4];
				$m[5] += (float) $r[5];
				$m[6] += (float) $r[6];
				$merged[ $key ] = $m;
			}
			foreach ( array_chunk( array_values( $merged ), 400 ) as $chunk ) {
				$holes = [];
				$args  = [];
				foreach ( $chunk as $m ) {
					$holes[] = '(%s,%d,%s,%s,%d,%d,%d,%f,%f)';
					array_push( $args, $account, $span, $m[0], $m[1], $m[2], $m[3], $m[4], round( $m[5], 2 ), round( $m[6], 2 ) );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (account,span,offer,country,cost,clicks,impressions,conversions,value) VALUES " . implode( ',', $holes ), $args ) );
				$written += count( $chunk );
			}
		}
		$accounts             = self::accounts();
		$accounts[ $account ] = [
			'name'      => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'currency'  => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $data['currency'] ?? '' ) ), 0, 3 ) ),
			'until'     => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $data['until'] ?? '' ) ) ? (string) $data['until'] : '',
			'received'  => time(),
			'rows'      => $written,
			'campaigns' => self::clean_campaigns( (array) ( $data['campaigns'] ?? [] ) ),
		];
		update_option( self::OPT_ACCOUNTS, $accounts, false );
		return $written;
	}

	/** The campaigns' own totals, kept beside the account. */
	private static function clean_campaigns( array $in ): array {
		$out = [];
		foreach ( self::SPANS as $span ) {
			foreach ( (array) ( $in[ (string) $span ] ?? $in[ $span ] ?? [] ) as $c ) {
				if ( is_array( $c ) && count( $c ) >= 7 ) {
					$out[ $span ][] = [
						'id'    => preg_replace( '/[^0-9]/', '', (string) $c[0] ),
						'name'  => sanitize_text_field( (string) $c[1] ),
						'type'  => sanitize_key( (string) $c[2] ),
						'cost'  => (int) $c[3],
						'clicks' => (int) $c[4],
						'conversions' => (float) $c[5],
						'value' => (float) $c[6],
					];
				}
			}
		}
		return $out;
	}

	// =========================================================================
	// The figures, product by product
	// =========================================================================

	/** Cache key of one span's figures: a new report or a new day starts it again. */
	private static function cache_key( int $span ): string {
		$stamp = 0;
		foreach ( self::accounts() as $a ) {
			$stamp = max( $stamp, (int) ( $a['received'] ?? 0 ) );
		}
		return 'dze_ads_fig_' . $span . '_' . md5( $stamp . '|' . wp_date( 'Y-m-d' ) );
	}

	public static function forget_cache(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own transients, by prefix.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_dze\\_ads\\_fig\\_%' OR option_name LIKE '\\_transient\\_timeout\\_dze\\_ads\\_fig\\_%'" );
	}

	/**
	 * Every figure of one span, per original product, per category and per country.
	 *
	 * @return array{products:array<int,array>,categories:array<int,array>,countries:array<string,array>,currency:string,accounts:int,converted:bool}
	 */
	public static function figures( int $span ): array {
		$span = in_array( $span, self::SPANS, true ) ? $span : 30;
		$key  = self::cache_key( $span );
		$got  = get_transient( $key );
		if ( is_array( $got ) ) {
			return $got;
		}
		$shop     = (string) get_option( 'woocommerce_currency', 'USD' );
		$wcml     = DZE_Sales::wcml_rates();
		$accounts = self::accounts();
		$blank    = [ 'cost' => 0.0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0.0, 'value' => 0.0, 'ads_units' => 0, 'ads_revenue' => 0.0, 'ads_orders' => [], 'other_units' => 0, 'other_revenue' => 0.0, 'other_orders' => [] ];
		$products  = [];
		$countries = [];
		$converted = true;

		// What the ads cost and brought, per offer and country.
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT account, offer, country, cost, clicks, impressions, conversions, value FROM {$table} WHERE span = %d", $span ), ARRAY_A );
		$owners = self::originals( array_map( static fn( $r ) => (int) $r['offer'], $rows ) );
		foreach ( $rows as $r ) {
			$cur  = (string) ( $accounts[ (string) $r['account'] ]['currency'] ?? $shop );
			$cost = DZE_Sales::to_shop_currency( (int) $r['cost'] / 1000000, $cur, 0.0, $shop, $wcml );
			$val  = DZE_Sales::to_shop_currency( (float) $r['value'], $cur, 0.0, $shop, $wcml );
			if ( null === $cost ) {
				$converted = false;
				continue;
			}
			$pid = (int) ( $owners[ (int) $r['offer'] ] ?? 0 );
			$cc  = '' !== (string) $r['country'] ? (string) $r['country'] : '??';
			foreach ( [ 'p' => $pid, 'c' => $cc ] as $kind => $k ) {
				if ( 'p' === $kind && ! $k ) {
					continue;
				}
				if ( 'p' === $kind ) {
					$x = $products[ $k ] ?? $blank;
				} else {
					$x = $countries[ $k ] ?? $blank;
				}
				$x['cost']        += $cost;
				$x['clicks']      += (int) $r['clicks'];
				$x['impressions'] += (int) $r['impressions'];
				$x['conversions'] += (float) $r['conversions'];
				$x['value']       += (float) $val;
				if ( 'p' === $kind ) {
					$products[ $k ] = $x;
				} else {
					$countries[ $k ] = $x;
				}
			}
		}

		// What the shop sold, through an ad or not.
		$lines = DZE_Sales::lines( $span );
		$sold  = self::originals( array_map( static fn( $l ) => (int) $l['product'], $lines ) );
		foreach ( $lines as $l ) {
			$side = 'ads' === $l['channel'] ? 'ads' : 'other';
			$pid  = (int) ( $sold[ (int) $l['product'] ] ?? 0 );
			if ( $pid ) {
				$x = $products[ $pid ] ?? $blank;
				$x[ $side . '_units' ]   += (int) $l['units'];
				$x[ $side . '_revenue' ] += (float) $l['revenue'];
				$x[ $side . '_orders' ][ (int) $l['order'] ] = true;
				$products[ $pid ] = $x;
			}
			$cc = '' !== $l['country'] ? $l['country'] : '??';
			$x  = $countries[ $cc ] ?? $blank;
			$x[ $side . '_units' ]   += (int) $l['units'];
			$x[ $side . '_revenue' ] += (float) $l['revenue'];
			$x[ $side . '_orders' ][ (int) $l['order'] ] = true;
			$countries[ $cc ] = $x;
		}

		$categories = self::by_category( $products );
		$count      = static function ( array $set ): array {
			foreach ( $set as $k => $x ) {
				$set[ $k ]['ads_orders']   = count( $x['ads_orders'] );
				$set[ $k ]['other_orders'] = count( $x['other_orders'] );
			}
			return $set;
		};
		$out = [
			'products'   => $count( $products ),
			'categories' => $count( $categories ),
			'countries'  => $count( $countries ),
			'currency'   => $shop,
			'accounts'   => count( $accounts ),
			'converted'  => $converted,
		];
		set_transient( $key, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Each product's figures added to every category it is filed in and to
	 * their parents: a parent shows the work of all its children, and a product
	 * in two branches counts once in their common parent.
	 *
	 * @param array<int,array> $products Original product id => figures (orders as sets).
	 * @return array<int,array> Original category id => figures.
	 */
	public static function by_category( array $products ): array {
		$terms = self::product_terms( array_keys( $products ) );
		$out   = [];
		foreach ( $products as $pid => $x ) {
			$all = [];
			foreach ( (array) ( $terms[ $pid ] ?? [] ) as $tid ) {
				$all[ $tid ] = true;
				foreach ( self::ancestors( (int) $tid ) as $up ) {
					$all[ $up ] = true;
				}
			}
			foreach ( array_keys( $all ) as $tid ) {
				$c = $out[ $tid ] ?? [ 'cost' => 0.0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0.0, 'value' => 0.0, 'ads_units' => 0, 'ads_revenue' => 0.0, 'ads_orders' => [], 'other_units' => 0, 'other_revenue' => 0.0, 'other_orders' => [], 'products' => 0 ];
				foreach ( [ 'cost', 'clicks', 'impressions', 'conversions', 'value', 'ads_units', 'ads_revenue', 'other_units', 'other_revenue' ] as $f ) {
					$c[ $f ] += $x[ $f ];
				}
				$c['ads_orders']   += (array) $x['ads_orders'];
				$c['other_orders'] += (array) $x['other_orders'];
				$c['products']++;
				$out[ $tid ] = $c;
			}
		}
		return $out;
	}

	/** The categories of some original products, one query. @return array<int,int[]> */
	private static function product_terms( array $ids ): array {
		global $wpdb;
		$out = [];
		foreach ( array_chunk( array_values( array_filter( array_map( 'intval', $ids ) ) ), 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
			$rows = (array) $wpdb->get_results( "SELECT tr.object_id AS pid, tt.term_id AS tid FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
				WHERE tr.object_id IN ( {$in} )", ARRAY_A );
			// phpcs:enable
			foreach ( $rows as $r ) {
				$out[ (int) $r['pid'] ][] = (int) $r['tid'];
			}
		}
		return $out;
	}

	/** A category's parents, nearest first, read once per request. @return int[] */
	public static function ancestors( int $tid ): array {
		static $tree = null;
		if ( null === $tree ) {
			global $wpdb;
			$tree = [];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the whole tree, once.
			foreach ( (array) $wpdb->get_results( "SELECT term_id, parent FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_cat'", ARRAY_A ) as $r ) {
				$tree[ (int) $r['term_id'] ] = (int) $r['parent'];
			}
		}
		$out  = [];
		$seen = [];
		while ( ! empty( $tree[ $tid ] ) && ! isset( $seen[ $tree[ $tid ] ] ) ) {
			$tid          = $tree[ $tid ];
			$seen[ $tid ] = true;
			$out[]        = $tid;
		}
		return $out;
	}

	/**
	 * The original product behind any product or variation id, in any language.
	 *
	 * A variation answers with its product; a translation with its original.
	 * One query per 500 ids, against WPML's own table: this runs in cron and in
	 * REST requests, where WPML's filters are not the ones running.
	 *
	 * @param int[] $ids
	 * @return array<int,int> id => original product id (0 when it is no product).
	 */
	public static function originals( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = [];
		$icl = $wpdb->prefix . 'icl_translations';
		$wpml = DZE_Wpml::is_active() && DZE_Wpml::has_table( $icl );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only; WPML's own table.
			$rows = (array) $wpdb->get_results(
				$wpml
					? "SELECT p.ID AS id, COALESCE( o.element_id, pa.ID ) AS orig
					     FROM {$wpdb->posts} p
					     INNER JOIN {$wpdb->posts} pa ON pa.ID = IF( p.post_type = 'product_variation', p.post_parent, p.ID ) AND pa.post_type = 'product'
					     LEFT JOIN {$icl} t ON t.element_id = pa.ID AND t.element_type = 'post_product'
					     LEFT JOIN {$icl} o ON o.trid = t.trid AND o.element_type = 'post_product' AND o.source_language_code IS NULL
					    WHERE p.ID IN ( {$in} )"
					: "SELECT p.ID AS id, pa.ID AS orig
					     FROM {$wpdb->posts} p
					     INNER JOIN {$wpdb->posts} pa ON pa.ID = IF( p.post_type = 'product_variation', p.post_parent, p.ID ) AND pa.post_type = 'product'
					    WHERE p.ID IN ( {$in} )",
				ARRAY_A
			);
			// phpcs:enable
			foreach ( $rows as $r ) {
				$out[ (int) $r['id'] ] = (int) $r['orig'];
			}
		}
		return $out;
	}

	// =========================================================================
	// Signals and the rule
	// =========================================================================

	/**
	 * The threshold past which the ads are cut.
	 *
	 * @return array{auto:bool,spend:float,roas:float,span:int}
	 */
	public static function rule(): array {
		$r = get_option( self::OPT_RULE, [] );
		$r = is_array( $r ) ? $r : [];
		return [
			'auto'  => ! empty( $r['auto'] ),
			'spend' => max( 1.0, (float) ( $r['spend'] ?? 30 ) ),
			'roas'  => max( 0.0, (float) ( $r['roas'] ?? 1 ) ),
			'span'  => in_array( (int) ( $r['span'] ?? 30 ), [ 30, 90 ], true ) ? (int) $r['span'] : 30,
		];
	}

	public function register_settings(): void {
		register_setting( 'dze_ads_rule', self::OPT_RULE, [ 'sanitize_callback' => [ __CLASS__, 'sanitize_rule' ], 'autoload' => false ] );
	}

	/** Only what the form carried is written; WordPress's null keeps what is stored. */
	public static function sanitize_rule( $in ): array {
		$now = self::rule();
		if ( null === $in ) {
			return $now;
		}
		$in = is_array( $in ) ? $in : [];
		// The switch belongs to this form: unticked, it posts nothing.
		$now['auto'] = ! empty( $in['auto'] );
		if ( array_key_exists( 'spend', $in ) ) {
			$now['spend'] = max( 1.0, round( (float) str_replace( ',', '.', (string) $in['spend'] ), 2 ) );
		}
		if ( array_key_exists( 'roas', $in ) ) {
			$now['roas'] = max( 0.0, round( (float) str_replace( ',', '.', (string) $in['roas'] ), 2 ) );
		}
		if ( array_key_exists( 'span', $in ) ) {
			$now['span'] = in_array( (int) $in['span'], [ 30, 90 ], true ) ? (int) $in['span'] : 30;
		}
		return $now;
	}

	/**
	 * What the figures say about one product or category.
	 *
	 * - 'over': it has spent the threshold and brought back less than the
	 *   ROAS asked for. What it brought is the better of the shop's own ad
	 *   sales and the value Google counted: the benefit of the doubt.
	 * - 'unfunded': it sells without the ads (two orders or more) while the ads
	 *   spend less than a tenth of what those sales bring. Budget may be
	 *   missing there.
	 * - '' otherwise.
	 */
	public static function signal( array $x, array $rule ): string {
		$cost = (float) ( $x['cost'] ?? 0 );
		$back = max( (float) ( $x['ads_revenue'] ?? 0 ), (float) ( $x['value'] ?? 0 ) );
		if ( $cost >= (float) $rule['spend'] && $back < $cost * (float) $rule['roas'] ) {
			return 'over';
		}
		$other = (float) ( $x['other_revenue'] ?? 0 );
		$orders = is_array( $x['other_orders'] ?? null ) ? count( $x['other_orders'] ) : (int) ( $x['other_orders'] ?? 0 );
		if ( $orders >= 2 && $cost < 0.1 * $other ) {
			return 'unfunded';
		}
		return '';
	}

	/**
	 * Puts every product past the threshold in quarantine, when the shop asked
	 * for it. Run after each report. Never takes a product out of quarantine.
	 *
	 * @return int How many products were put in quarantine.
	 */
	public static function apply_rule(): int {
		$rule = self::rule();
		if ( ! $rule['auto'] ) {
			return 0;
		}
		$fig = self::figures( $rule['span'] );
		$n   = 0;
		foreach ( $fig['products'] as $pid => $x ) {
			if ( 'over' === self::signal( $x, $rule ) && ! self::hold_of( 'product', (int) $pid ) ) {
				self::hold( 'product', (int) $pid, 'rule', (float) $x['cost'] );
				$n++;
			}
		}
		return $n;
	}

	// =========================================================================
	// Quarantine
	// =========================================================================

	/**
	 * The quarantine of one product or category, or null.
	 *
	 * @return array{since:int,why:string,spent:float,by:int,opened:int}|null
	 */
	public static function hold_of( string $kind, int $id ): ?array {
		$v = 'category' === $kind ? get_term_meta( $id, self::META_HOLD, true ) : get_post_meta( $id, self::META_HOLD, true );
		return is_array( $v ) && ! empty( $v['since'] ) ? $v : null;
	}

	/** Takes an original product or category out of the ads. */
	public static function hold( string $kind, int $id, string $why, float $spent = 0.0 ): void {
		$v = [
			'since'  => time(),
			'why'    => in_array( $why, [ 'rule', 'hand' ], true ) ? $why : 'hand',
			'spent'  => round( $spent, 2 ),
			'by'     => get_current_user_id(),
			'opened' => 0,
		];
		if ( 'category' === $kind ) {
			update_term_meta( $id, self::META_HOLD, $v );
		} else {
			update_post_meta( $id, self::META_HOLD, $v );
		}
	}

	/** Records that the page was opened for review: the condition to bring it back. */
	public static function opened( string $kind, int $id ): bool {
		$v = self::hold_of( $kind, $id );
		if ( ! $v ) {
			return false;
		}
		$v['opened'] = time();
		if ( 'category' === $kind ) {
			update_term_meta( $id, self::META_HOLD, $v );
		} else {
			update_post_meta( $id, self::META_HOLD, $v );
		}
		return true;
	}

	/**
	 * Brings a product or category back into the ads — only once its page was
	 * opened since it was put in quarantine.
	 */
	public static function release( string $kind, int $id ): bool {
		$v = self::hold_of( $kind, $id );
		if ( ! $v || empty( $v['opened'] ) ) {
			return false;
		}
		if ( 'category' === $kind ) {
			delete_term_meta( $id, self::META_HOLD );
		} else {
			delete_post_meta( $id, self::META_HOLD );
		}
		return true;
	}

	/** Every quarantine, products and categories. @return array{product:array<int,array>,category:array<int,array>} */
	public static function holds(): array {
		global $wpdb;
		$out = [ 'product' => [], 'category' => [] ];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one meta key, read whole.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id AS id, meta_value AS v FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_HOLD ), ARRAY_A ) as $r ) {
			$v = maybe_unserialize( $r['v'] );
			if ( is_array( $v ) && ! empty( $v['since'] ) ) {
				$out['product'][ (int) $r['id'] ] = $v;
			}
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT term_id AS id, meta_value AS v FROM {$wpdb->termmeta} WHERE meta_key = %s", self::META_HOLD ), ARRAY_A ) as $r ) {
			$v = maybe_unserialize( $r['v'] );
			if ( is_array( $v ) && ! empty( $v['since'] ) ) {
				$out['category'][ (int) $r['id'] ] = $v;
			}
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * The original products out of the ads: their own quarantine, or a
	 * category of theirs (or one of its parents) in quarantine.
	 *
	 * @return array<int,true>
	 */
	public static function held_products(): array {
		$h   = self::holds();
		$out = [];
		foreach ( array_keys( $h['product'] ) as $pid ) {
			$out[ (int) $pid ] = true;
		}
		if ( $h['category'] ) {
			$all = [];
			foreach ( array_keys( $h['category'] ) as $tid ) {
				$all[ (int) $tid ] = true;
				foreach ( (array) get_term_children( (int) $tid, 'product_cat' ) as $child ) {
					$all[ (int) $child ] = true;
				}
			}
			$found = get_objects_in_term( array_keys( $all ), 'product_cat' );
			foreach ( self::originals( is_array( $found ) ? $found : [] ) as $pid ) {
				if ( $pid ) {
					$out[ (int) $pid ] = true;
				}
			}
		}
		return $out;
	}

	// =========================================================================
	// The script the shop pastes into Google Ads
	// =========================================================================

	/** The Google Ads script, ready to paste: the shop's address and key are in it. */
	public static function script(): string {
		$url = self::endpoint();
		$key = self::secret();
		return <<<JS
/**
 * Dazont Ecom: Google Ads to {$url}
 *
 * Every day, sends what each product cost and brought in this account, by
 * country, over the last 7, 30 and 90 days. It only reads: it changes nothing
 * in this account.
 */
var SHOP = '{$url}';
var KEY = '{$key}';

function main() {
  var account = AdsApp.currentAccount();
  var tz = account.getTimeZone();
  var day = 24 * 3600 * 1000;
  var end = new Date(Date.now() - day);
  var report = {
    account: account.getCustomerId(),
    name: account.getName(),
    currency: account.getCurrencyCode(),
    until: Utilities.formatDate(end, tz, 'yyyy-MM-dd'),
    spans: {},
    campaigns: {},
    countries: {}
  };
  var places = {};
  [7, 30, 90].forEach(function (n) {
    var from = Utilities.formatDate(new Date(end.getTime() - (n - 1) * day), tz, 'yyyy-MM-dd');
    var range = "segments.date BETWEEN '" + from + "' AND '" + report.until + "'";
    var rows = [];
    var it = AdsApp.search('SELECT segments.product_item_id, segments.product_country, metrics.cost_micros, metrics.clicks, ' +
      'metrics.impressions, metrics.conversions, metrics.conversions_value FROM shopping_performance_view WHERE ' + range +
      ' AND metrics.impressions > 0');
    while (it.hasNext()) {
      var r = it.next();
      var where = r.segments.productCountry || '';
      places[where] = true;
      rows.push([r.segments.productItemId, where, Number(r.metrics.costMicros || 0), Number(r.metrics.clicks || 0),
        Number(r.metrics.impressions || 0), Number(r.metrics.conversions || 0), Number(r.metrics.conversionsValue || 0)]);
    }
    report.spans[n] = rows;
    var list = [];
    var ci = AdsApp.search('SELECT campaign.id, campaign.name, campaign.advertising_channel_type, metrics.cost_micros, ' +
      'metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE ' + range + ' AND metrics.cost_micros > 0');
    while (ci.hasNext()) {
      var c = ci.next();
      list.push([String(c.campaign.id), c.campaign.name, c.campaign.advertisingChannelType, Number(c.metrics.costMicros || 0),
        Number(c.metrics.clicks || 0), Number(c.metrics.conversions || 0), Number(c.metrics.conversionsValue || 0)]);
    }
    report.campaigns[n] = list;
  });
  var names = Object.keys(places).filter(function (k) { return k; });
  if (names.length) {
    var gi = AdsApp.search("SELECT geo_target_constant.resource_name, geo_target_constant.country_code FROM geo_target_constant " +
      "WHERE geo_target_constant.resource_name IN ('" + names.join("','") + "')");
    while (gi.hasNext()) {
      var g = gi.next();
      report.countries[g.geoTargetConstant.resourceName] = g.geoTargetConstant.countryCode;
    }
  }
  var body = JSON.stringify(report);
  var sig = Utilities.computeHmacSha256Signature(body, KEY, Utilities.Charset.UTF_8).map(function (b) {
    return ('0' + (b & 255).toString(16)).slice(-2);
  }).join('');
  var res = UrlFetchApp.fetch(SHOP, {
    method: 'post', contentType: 'application/json', payload: body,
    headers: { 'X-Dazont-Signature': sig }, muteHttpExceptions: true
  });
  Logger.log(res.getResponseCode() + ' ' + res.getContentText().slice(0, 300));
  if (res.getResponseCode() !== 200) {
    throw new Error('The shop refused the report (' + res.getResponseCode() + '): ' + res.getContentText().slice(0, 300));
  }
}
JS;
	}
}
