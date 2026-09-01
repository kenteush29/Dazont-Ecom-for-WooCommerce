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
		$out = [ 'rev' => [], 'qty' => [], 'missing' => [] ];
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$hpos = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders ) ) === $orders;

		// One row per product per currency. The currency comes from the order
		// itself — HPOS keeps it in a column, the older shops in a meta row.
		$sql = $hpos
			? "SELECT l.product_id AS pid, UPPER( COALESCE( o.currency, '' ) ) AS cur,
					SUM( l.product_qty ) AS qty, SUM( l.product_net_revenue ) AS rev
				FROM {$table} l
				LEFT JOIN {$orders} o ON o.id = l.order_id
				{$where}
				GROUP BY l.product_id, cur"
			: "SELECT l.product_id AS pid, UPPER( COALESCE( m.meta_value, '' ) ) AS cur,
					SUM( l.product_qty ) AS qty, SUM( l.product_net_revenue ) AS rev
				FROM {$table} l
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = l.order_id AND m.meta_key = '_order_currency'
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
		$out['missing'] = array_keys( $missing );
		return $out;
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
