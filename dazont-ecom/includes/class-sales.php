<?php
defined( 'ABSPATH' ) || exit;

/**
 * What the shop sold, read order by order: one reading for every module.
 *
 * Two modules count sales. Internal linking ranks categories by what their
 * products sold; Google Ads sets what the ads cost beside what they brought.
 * Both must count the same orders the same way, so the reading lives here.
 * It was written for netlinking (4.498.1) and moved here unchanged:
 * - the lines come from the table WooCommerce Analytics fills
 *   (wc_order_product_lookup), but only for orders that still exist and are
 *   sales. Deleted orders keep their lines there: on Kula, 28 lines out of
 *   1,529 over 90 days weighed 1.48 million;
 * - not a sale: pending, failed, cancelled, checkout-draft, trash, drafts —
 *   what WooCommerce Analytics leaves out. A refund follows its order;
 * - money is converted to the shop's currency: the order's own exchange rate
 *   when the payment kept one, else WCML's current rate, else not added.
 *
 * WHERE AN ORDER CAME FROM is read from WooCommerce's own order attribution
 * (WooCommerce 8.5 and later) and from the address the visitor landed on.
 * That address keeps Google's click id when the tags were lost: on Kula, 14
 * orders over 90 days were « typed in » although they came through an ad.
 */
final class DZE_Sales {

	/**
	 * Order statuses that are not a sale (with and without WooCommerce's prefix):
	 * what WooCommerce Analytics leaves out itself, the bin and the drafts.
	 * Everything else counts, a shop's own statuses included. On Kula,
	 * « Shipped » alone held 659 lines over 90 days; a list of the statuses
	 * that DO count would have thrown them away.
	 */
	public const NOT_SOLD = [ 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-checkout-draft', 'pending', 'failed', 'cancelled', 'checkout-draft', 'trash', 'draft', 'auto-draft' ];

	/** Where an order came from: the channels the screens tell apart. */
	public const CHANNELS = [ 'ads', 'shopping', 'search', 'direct', 'email', 'referral', 'other' ];

	/**
	 * An amount in the shop's currency.
	 *
	 * @param float  $rate The order's own rate to the shop's currency, 0 when it kept none.
	 * @param array  $wcml WCML's rates, currency => units for one unit of the shop's currency.
	 * @return float|null Null when nothing tells how to convert it.
	 */
	public static function to_shop_currency( float $net, string $cur, float $rate, string $shop, array $wcml ): ?float {
		if ( $rate > 0 ) {
			return $net * $rate;
		}
		if ( '' === $cur || $cur === $shop ) {
			return $net;
		}
		$r = (float) ( $wcml[ $cur ] ?? 0 );
		return $r > 0 ? $net / $r : null;
	}

	/** WooCommerce Multilingual's current rates, currency => rate. */
	public static function wcml_rates(): array {
		$s   = get_option( '_wcml_settings', [] );
		$out = [];
		foreach ( (array) ( is_array( $s ) ? ( $s['currency_options'] ?? [] ) : [] ) as $code => $o ) {
			$r = (float) ( is_array( $o ) ? ( $o['rate'] ?? 0 ) : 0 );
			if ( $r > 0 ) {
				$out[ (string) $code ] = $r;
			}
		}
		return $out;
	}

	/** Whether this shop keeps its orders in the HPOS tables. WooCommerce's own answer, checked against the table. */
	public static function hpos(): bool {
		global $wpdb;
		static $known = null;
		if ( null !== $known ) {
			return $known;
		}
		return $known = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' )
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders' ) ) === $wpdb->prefix . 'wc_orders'; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * What each order is: whether it still exists, whether it is a sale, in
	 * which currency and at which rate.
	 *
	 * A refund counts what its order counts: its lines are negative, they are
	 * taken off when the order is a sale, and it takes its order's currency and
	 * rate when it has none of its own.
	 *
	 * @param int[] $ids
	 * @return array<int,array{ok:bool,cur:string,rate:float}>
	 */
	public static function order_facts( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', array_unique( $ids ) ) ) );
		if ( ! $ids || ! $wpdb ) {
			return [];
		}
		$hpos = self::hpos();
		$read = static function ( array $chunk ) use ( $wpdb, $hpos ): array {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only, joined above.
			if ( $hpos ) {
				$o = $wpdb->prefix . 'wc_orders';
				$m = $wpdb->prefix . 'wc_orders_meta';
				$sql = "SELECT o.id AS id, o.type AS type, o.status AS status, o.parent_order_id AS parent, o.currency AS cur,
				               ( SELECT meta_value FROM {$m} WHERE order_id = o.id AND meta_key = '_wcpay_multi_currency_stripe_exchange_rate' LIMIT 1 ) AS rate
				          FROM {$o} o WHERE o.id IN ( {$in} )";
			} else {
				$sql = "SELECT p.ID AS id, p.post_type AS type, p.post_status AS status, p.post_parent AS parent,
				               MAX( CASE WHEN pm.meta_key = '_order_currency' THEN pm.meta_value END ) AS cur,
				               MAX( CASE WHEN pm.meta_key = '_wcpay_multi_currency_stripe_exchange_rate' THEN pm.meta_value END ) AS rate
				          FROM {$wpdb->posts} p
				          LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ( '_order_currency', '_wcpay_multi_currency_stripe_exchange_rate' )
				         WHERE p.ID IN ( {$in} ) GROUP BY p.ID";
			}
			// phpcs:enable
			$out = [];
			foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) {
				$out[ (int) $r['id'] ] = $r;
			}
			return $out;
		};
		$raw = [];
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$raw += $read( $chunk );
		}
		// The orders of the refunds, read in turn.
		$parents = [];
		foreach ( $raw as $r ) {
			if ( 'shop_order_refund' === (string) $r['type'] && (int) $r['parent'] > 0 && ! isset( $raw[ (int) $r['parent'] ] ) ) {
				$parents[] = (int) $r['parent'];
			}
		}
		foreach ( array_chunk( array_values( array_unique( $parents ) ), 500 ) as $chunk ) {
			$raw += $read( $chunk );
		}
		$out = [];
		foreach ( $ids as $id ) {
			$r = $raw[ $id ] ?? null;
			if ( ! $r ) {
				$out[ $id ] = [ 'ok' => false, 'cur' => '', 'rate' => 0.0 ];
				continue;
			}
			$sale = $r;
			if ( 'shop_order_refund' === (string) $r['type'] ) {
				$sale = $raw[ (int) $r['parent'] ] ?? null;
			}
			$ok   = null !== $sale && ! in_array( (string) $sale['status'], self::NOT_SOLD, true );
			$cur  = (string) ( $r['cur'] ?? '' );
			$rate = (float) ( $r['rate'] ?? 0 );
			if ( null !== $sale && $sale !== $r ) {
				$cur  = '' !== $cur ? $cur : (string) ( $sale['cur'] ?? '' );
				$rate = $rate > 0 ? $rate : (float) ( $sale['rate'] ?? 0 );
			}
			$out[ $id ] = [ 'ok' => $ok, 'cur' => $cur, 'rate' => $rate ];
		}
		return $out;
	}

	/**
	 * Where each order came from, and where it was sent.
	 *
	 * A refund answers for its order: it has no attribution of its own.
	 *
	 * @param int[] $ids Orders (a refund's order is looked up by the caller's facts).
	 * @return array<int,array{channel:string,country:string}>
	 */
	public static function origins( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', array_unique( $ids ) ) ) );
		if ( ! $ids || ! $wpdb ) {
			return [];
		}
		$keys = [ '_wc_order_attribution_source_type', '_wc_order_attribution_utm_source', '_wc_order_attribution_utm_medium', '_wc_order_attribution_session_entry' ];
		$hpos = self::hpos();
		$out  = [];
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in    = implode( ',', array_map( 'intval', $chunk ) );
			$klist = "'" . implode( "','", $keys ) . "'";
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers and constants only.
			if ( $hpos ) {
				$meta = (array) $wpdb->get_results( "SELECT order_id AS id, meta_key AS k, meta_value AS v FROM {$wpdb->prefix}wc_orders_meta WHERE order_id IN ( {$in} ) AND meta_key IN ( {$klist} )", ARRAY_A );
				$where = (array) $wpdb->get_results( "SELECT order_id AS id, address_type AS t, country AS c FROM {$wpdb->prefix}wc_order_addresses WHERE order_id IN ( {$in} )", ARRAY_A );
			} else {
				$meta  = (array) $wpdb->get_results( "SELECT post_id AS id, meta_key AS k, meta_value AS v FROM {$wpdb->postmeta} WHERE post_id IN ( {$in} ) AND meta_key IN ( {$klist}, '_shipping_country', '_billing_country' )", ARRAY_A );
				$where = [];
				foreach ( $meta as $m ) {
					if ( '_shipping_country' === $m['k'] || '_billing_country' === $m['k'] ) {
						$where[] = [ 'id' => $m['id'], 't' => '_shipping_country' === $m['k'] ? 'shipping' : 'billing', 'c' => $m['v'] ];
					}
				}
			}
			// phpcs:enable
			$a = [];
			foreach ( $meta as $m ) {
				$a[ (int) $m['id'] ][ (string) $m['k'] ] = (string) $m['v'];
			}
			$c = [];
			foreach ( $where as $w ) {
				$code = strtoupper( trim( (string) $w['c'] ) );
				if ( 2 === strlen( $code ) && ( 'shipping' === $w['t'] || ! isset( $c[ (int) $w['id'] ] ) ) ) {
					$c[ (int) $w['id'] ] = $code;
				}
			}
			foreach ( $chunk as $id ) {
				$x          = $a[ $id ] ?? [];
				$out[ $id ] = [
					'channel' => self::channel(
						(string) ( $x['_wc_order_attribution_source_type'] ?? '' ),
						(string) ( $x['_wc_order_attribution_utm_source'] ?? '' ),
						(string) ( $x['_wc_order_attribution_utm_medium'] ?? '' ),
						(string) ( $x['_wc_order_attribution_session_entry'] ?? '' )
					),
					'country' => (string) ( $c[ $id ] ?? '' ),
				];
			}
		}
		return $out;
	}

	/**
	 * The channel an order came through, from WooCommerce's attribution.
	 *
	 * Google's own click ids decide first, because they survive a lost tag:
	 * gclid, gbraid, wbraid and gad_source come with an ad; srsltid comes with
	 * a free product listing. Then the tags: Google with a paid medium is an
	 * ad, whatever the shop typed (« google / cpc », « Google Ads / CPC »,
	 * « Google+Ads »).
	 */
	public static function channel( string $type, string $source, string $medium, string $entry ): string {
		$query = (string) wp_parse_url( $entry, PHP_URL_QUERY );
		parse_str( $query, $q );
		foreach ( [ 'gclid', 'gbraid', 'wbraid', 'gad_source' ] as $click ) {
			if ( isset( $q[ $click ] ) && '' !== (string) $q[ $click ] ) {
				return 'ads';
			}
		}
		$src = strtolower( str_replace( [ '+', ' ', '_', '-' ], '', $source ) );
		$med = strtolower( trim( $medium ) );
		$paid = in_array( $med, [ 'cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'pmax', 'shopping', 'display' ], true );
		if ( ( 'google' === $src || 'googleads' === $src || 'adwords' === $src ) && ( $paid || 'googleads' === $src || 'adwords' === $src ) ) {
			return 'ads';
		}
		if ( isset( $q['srsltid'] ) ) {
			return 'shopping';
		}
		if ( 'klaviyo' === $src || in_array( $med, [ 'email', 'flow', 'newsletter' ], true ) ) {
			return 'email';
		}
		switch ( strtolower( trim( $type ) ) ) {
			case 'organic':
				return 'search';
			case 'typein':
				return 'direct';
			case 'referral':
				return 'referral';
		}
		return 'other';
	}

	/**
	 * Every sold line of the last $days full days, ready to add up.
	 *
	 * Lines of orders that are gone or are not sales are left out; money is in
	 * the shop's currency (a line nothing can convert keeps its units and
	 * brings no revenue).
	 *
	 * @return array<int,array{order:int,product:int,variation:int,units:int,revenue:float,channel:string,country:string}>
	 */
	public static function lines( int $days ): array {
		global $wpdb;
		$lookup = $wpdb->prefix . 'wc_order_product_lookup';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce's own table.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) ) !== $lookup ) {
			return [];
		}
		$start = gmdate( 'Y-m-d 00:00:00', (int) current_time( 'timestamp' ) - max( 1, $days ) * DAY_IN_SECONDS );
		$end   = gmdate( 'Y-m-d 00:00:00', (int) current_time( 'timestamp' ) );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT order_id AS oid, product_id AS pid, variation_id AS vid, product_qty AS qty, product_net_revenue AS net
			   FROM {$lookup}
			  WHERE date_created >= %s AND date_created < %s",
			$start,
			$end
		), ARRAY_A );
		// phpcs:enable
		if ( ! $rows ) {
			return [];
		}
		$orders = array_map( static fn( $r ) => (int) $r['oid'], $rows );
		$facts  = self::order_facts( $orders );
		// A refund's origin is its order's.
		$whose = [];
		foreach ( $orders as $oid ) {
			$whose[ $oid ] = $oid;
		}
		$parents = self::refund_parents( array_keys( $whose ) );
		foreach ( $parents as $refund => $order ) {
			$whose[ $refund ] = $order;
		}
		$origin = self::origins( array_values( array_unique( array_values( $whose ) ) ) );
		$shop   = (string) get_option( 'woocommerce_currency', '' );
		$wcml   = self::wcml_rates();
		$out    = [];
		foreach ( $rows as $r ) {
			$oid = (int) $r['oid'];
			$f   = $facts[ $oid ] ?? null;
			if ( ! $f || empty( $f['ok'] ) ) {
				continue;
			}
			$money = self::to_shop_currency( (float) $r['net'], (string) $f['cur'], (float) $f['rate'], $shop, $wcml );
			$o     = $origin[ $whose[ $oid ] ] ?? [ 'channel' => 'other', 'country' => '' ];
			$out[] = [
				'order'     => $whose[ $oid ],
				'product'   => (int) $r['pid'],
				'variation' => (int) $r['vid'],
				'units'     => (int) $r['qty'],
				'revenue'   => null === $money ? 0.0 : (float) $money,
				'channel'   => (string) $o['channel'],
				'country'   => (string) $o['country'],
			];
		}
		return $out;
	}

	/**
	 * The order each refund belongs to.
	 *
	 * @param int[] $ids
	 * @return array<int,int> refund id => order id
	 */
	private static function refund_parents( array $ids ): array {
		global $wpdb;
		$out = [];
		foreach ( array_chunk( array_values( array_filter( array_map( 'intval', $ids ) ) ), 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
			$rows = self::hpos()
				? (array) $wpdb->get_results( "SELECT id, parent_order_id AS parent FROM {$wpdb->prefix}wc_orders WHERE id IN ( {$in} ) AND type = 'shop_order_refund'", ARRAY_A )
				: (array) $wpdb->get_results( "SELECT ID AS id, post_parent AS parent FROM {$wpdb->posts} WHERE ID IN ( {$in} ) AND post_type = 'shop_order_refund'", ARRAY_A );
			// phpcs:enable
			foreach ( $rows as $r ) {
				if ( (int) $r['parent'] > 0 ) {
					$out[ (int) $r['id'] ] = (int) $r['parent'];
				}
			}
		}
		return $out;
	}
}
