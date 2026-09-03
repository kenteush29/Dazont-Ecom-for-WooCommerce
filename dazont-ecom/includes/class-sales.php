<?php
defined( 'ABSPATH' ) || exit;

/**
 * What each product has actually BROUGHT IN, in the shop's own currency.
 *
 * WooCommerce keeps a row per order line in wc_order_product_lookup, with the
 * revenue in the currency the order was PAID in — and no currency column: the
 * currency lives on the order. So a shop selling in five markets cannot sum
 * that column, and this reads it grouped BY CURRENCY, converts each group
 * through DZE_Money, and adds up what it could convert.
 *
 * Both order tables are asked, because a shop is on one or the other: the
 * HPOS orders table on a modern WooCommerce, the posts table on an older one.
 *
 * Quantities live beside it and never go through a rate: four sold is four
 * sold, whatever the buyer paid in.
 */
final class DZE_Sales {

	/** How long a reading of the whole catalogue is kept. */
	private const TTL = 3 * HOUR_IN_SECONDS;

	/** How far back revenue looks. Older money was earned in another era. */
	public const MONTHS = 24;

	/**
	 * Revenue per product, in the shop's currency.
	 *
	 * @param int[] $ids Products asked about ([] = every product that sold).
	 * @return array{
	 *   rev:array<int,float>,
	 *   qty:array<int,int>,
	 *   missing:string[]
	 * } missing lists the currencies whose rate this shop cannot give, so a
	 *   screen can say the total is short rather than pretend it is whole.
	 */
	public static function revenue( array $ids = [] ): array {
		global $wpdb;
		$out = [ 'rev' => [], 'qty' => [], 'missing' => [], 'by' => [], 'orphans' => [ 'lines' => 0, 'raw' => 0.0 ] ];
		if ( ! $wpdb ) {
			return $out;
		}
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return $out;
		}
		$ids   = array_values( array_filter( array_map( 'absint', $ids ) ) );
		$parts = [];
		if ( $ids ) {
			$parts[] = 'l.product_id IN ( ' . implode( ',', array_map( 'intval', $ids ) ) . ' )';
		}
		// A HORIZON, and it is not a detail. This shop kept its books in
		// roubles before it kept them in dollars, and the lookup table holds
		// those old lines with no memory of what a rouble was worth that year:
		// converted at today's rate they came out as "$19,388.43" for a
		// product sold sixty times at $15.90, and a refund on the dollar side
		// of a product whose sales were all in the old currency came out
		// NEGATIVE. Revenue answers "what does this product bring in NOW",
		// so it reads the last two years and says so on the screen.
		// The date is one this code generates, not one anybody sends: written
		// straight in, so the clause is a string here as it is in WordPress.
		$parts[] = "l.date_created >= '" . gmdate( 'Y-m-d H:i:s', time() - self::MONTHS * 30 * DAY_IN_SECONDS ) . "'";
		$where = ' WHERE ' . implode( ' AND ', $parts ) . ' ';

		$orders = $wpdb->prefix . 'wc_orders';
		$hpos   = self::hpos();

		// WHERE THE ORDER IS, and whether it is there at all. A row in the
		// lookup table whose order_id resolves to nothing is not a sale: this
		// shop had 751 of them, 67 inside the window, carrying 84% of
		// everything the screen reported — a $65 cap at 96,490.00, the same
		// figure again on a different cap in the same "order". They are the
		// wreckage of an import, and summed as dollars they put four figures
		// on the average order line of a shop that sells at $15 to $77.
		//
		// So the JOIN is INNER here: what a product earned is what it earned
		// on orders that exist. The orphans are counted separately, below,
		// and NAMED on the screen — the same treatment a currency with no
		// rate already gets, because dropping them in silence would bury a
		// real data problem in the shop's own database.
		$join = $hpos
			? "INNER JOIN {$orders} o ON o.id = l.order_id"
			: "INNER JOIN {$wpdb->posts} p ON p.ID = l.order_id AND p.post_type = 'shop_order'";
		// The same join held open, for the count that has to SEE the orphans.
		$open = $hpos
			? "LEFT JOIN {$orders} o ON o.id = l.order_id"
			: "LEFT JOIN {$wpdb->posts} p ON p.ID = l.order_id AND p.post_type = 'shop_order'";
		$key = $hpos ? 'o.id' : 'p.ID';
		// The currency: a column on HPOS, a meta row on the older shops. Read
		// through a subquery that holds ONE row per order, because this shop
		// carries 7,295 _order_currency rows against 6,546 orders — refunds
		// and translated duplicates share the key, and a plain join on it
		// would count those lines twice.
		$curcol = $hpos
			? "UPPER( COALESCE( o.currency, '' ) )"
			: "UPPER( COALESCE( m.cur, '' ) )";
		$meta = $hpos
			? ''
			: "LEFT JOIN ( SELECT post_id, MIN( meta_value ) AS cur FROM {$wpdb->postmeta}
					WHERE meta_key = '_order_currency' GROUP BY post_id ) m ON m.post_id = l.order_id";

		// What each product earned. Orphans cannot be here: the join drops
		// them.
		$sql = "SELECT l.product_id AS pid, {$curcol} AS cur,
					SUM( l.product_qty ) AS qty, SUM( l.product_net_revenue ) AS rev
				FROM {$table} l {$join} {$meta}
				{$where}
				GROUP BY l.product_id, cur";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables, ids cast to int above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		$base    = DZE_Money::base();
		$missing = [];
		foreach ( $rows as $row ) {
			$pid = (int) ( $row['pid'] ?? 0 );
			if ( ! $pid ) {
				continue;
			}
			$out['qty'][ $pid ] = ( $out['qty'][ $pid ] ?? 0 ) + (int) ( $row['qty'] ?? 0 );

			// An order with no currency written on it is an order in the
			// shop's own currency: that is what WooCommerce does when it has
			// never had a second one.
			$cur  = (string) ( $row['cur'] ?? '' );
			$cur  = '' !== $cur ? $cur : $base;
			$here = DZE_Money::to_base( (float) ( $row['rev'] ?? 0 ), $cur );
			if ( null === $here ) {
				$missing[ $cur ] = true;
				continue;
			}
			$out['rev'][ $pid ] = ( $out['rev'][ $pid ] ?? 0.0 ) + $here;
		}

		// The WORKINGS. A second reading of the same rows, grouped by currency
		// instead of by product: what was read in each, at what rate, and what
		// it became. It is a separate query because the first one groups by
		// product — counting ITS rows gave "2" for a currency carrying five
		// order lines, and the "per line" figure added to make a wrong total
		// obvious was itself two and a half times too big.
		//
		// `n`, not `lines`: LINES is a reserved word, and the query that used
		// it came back empty in silence.
		$tally = "SELECT {$curcol} AS cur,
					CASE WHEN {$key} IS NULL THEN 1 ELSE 0 END AS orphan,
					COUNT(*) AS n, SUM( l.product_qty ) AS qty, SUM( l.product_net_revenue ) AS rev
				FROM {$table} l {$open} {$meta}
				{$where}
				GROUP BY orphan, cur";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables, ids cast to int above.
		foreach ( (array) $wpdb->get_results( $tally, ARRAY_A ) as $row ) {
			$raw = (float) ( $row['rev'] ?? 0 );
			if ( ! empty( $row['orphan'] ) ) {
				$out['orphans']['lines'] += (int) ( $row['n'] ?? 0 );
				$out['orphans']['raw']   += $raw;
				continue;
			}
			$cur  = (string) ( $row['cur'] ?? '' );
			$cur  = '' !== $cur ? $cur : $base;
			$here = DZE_Money::to_base( $raw, $cur );
			if ( null === $here ) {
				$missing[ $cur ] = true;
				continue;
			}
			if ( ! isset( $out['by'][ $cur ] ) ) {
				$out['by'][ $cur ] = [ 'lines' => 0, 'raw' => 0.0, 'rate' => (float) DZE_Money::rate( $cur ), 'base' => 0.0 ];
			}
			$out['by'][ $cur ]['lines'] += (int) ( $row['n'] ?? 0 );
			$out['by'][ $cur ]['raw']   += $raw;
			$out['by'][ $cur ]['base']  += $here;
		}
		$out['missing'] = array_keys( $missing );
		return $out;
	}

	/**
	 * Whether this shop keeps its orders in the HPOS table.
	 *
	 * WooCommerce's own answer, because the TABLE is not one: a shop that tried
	 * HPOS and turned it off keeps an empty wc_orders behind it, and reading
	 * that table finds no order for any line — every sale an orphan, every
	 * currency blank. That is exactly the false signal this class was built to
	 * stop reporting. The table is only asked when WooCommerce is not loaded to
	 * answer (a test harness, an uninstall).
	 */
	private static function hpos(): bool {
		$util = '\Automattic\WooCommerce\Utilities\OrderUtil';
		if ( class_exists( $util ) && method_exists( $util, 'custom_orders_table_usage_is_enabled' ) ) {
			return (bool) $util::custom_orders_table_usage_is_enabled();
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wc_orders';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * The same reading for the whole catalogue, kept for a few hours.
	 *
	 * The sourcing report and the diagnostic both want it, and it is a full
	 * scan of the order lines: asked twice on one screen it would be read
	 * twice.
	 */
	public static function all(): array {
		$slot = 'dze_sales_rev_' . md5( DZE_Money::base() );
		$got  = get_transient( $slot );
		if ( is_array( $got ) ) {
			return $got;
		}
		$out = self::revenue();
		set_transient( $slot, $out, self::TTL );
		return $out;
	}
}
