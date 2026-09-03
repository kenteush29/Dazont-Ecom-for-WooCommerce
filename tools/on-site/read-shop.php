<?php
/**
 * Reads the real shop and prints what a diagnostic session needs — nothing
 * else. This exists because the coding session (no server access) and the
 * session that has one cannot message each other directly: this script, and
 * HANDOFF.md beside it, are the bridge. Run it, paste the WHOLE output back
 * to the owner for the coding session to read.
 *
 * ABSOLUTELY READ-ONLY: every query is a SELECT or a get_option(). It writes
 * nothing, ever — safe to run on the live shop or on a copy.
 *
 * Run it one of two ways, from anywhere:
 *   wp eval-file tools/on-site/read-shop.php
 *   php tools/on-site/read-shop.php        (finds wp-load.php on its own)
 *
 * CLI only — this prints raw revenue figures and is not something to expose
 * on the web.
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( "CLI only.\n" );
}

// wp eval-file already has the whole of WordPress loaded. A plain `php`
// invocation does not, so wp-load.php is found by walking up from both the
// current directory and this file's own location — this script may be run
// from a git checkout that sits next to the WordPress install, not inside it.
if ( ! function_exists( 'get_option' ) ) {
	$tries = [];
	foreach ( [ getcwd(), __DIR__ ] as $start ) {
		$dir = $start;
		for ( $i = 0; $i < 8 && $dir && '.' !== $dir; $i++ ) {
			$tries[] = $dir . '/wp-load.php';
			$parent  = dirname( $dir );
			$dir     = ( $parent === $dir ) ? '' : $parent;
		}
	}
	$found = '';
	foreach ( $tries as $candidate ) {
		if ( is_file( $candidate ) ) {
			$found = $candidate;
			break;
		}
	}
	if ( '' === $found ) {
		fwrite( STDERR, "Could not find wp-load.php near " . getcwd() . " or " . __DIR__ . ".\n"
			. "Run this with WP-CLI instead: wp eval-file tools/on-site/read-shop.php\n"
			. "or copy it into the WordPress root and run: php read-shop.php\n" );
		exit( 1 );
	}
	require $found;
}

global $wpdb;

/**
 * Whether this shop keeps its orders in the HPOS table.
 *
 * WooCommerce's own answer, never the table's. A shop that tried HPOS and
 * turned it off keeps an EMPTY wp_wc_orders behind it, and reading that table
 * finds no order for any line: every currency blank, every sale an orphan —
 * exactly the false signal this script exists to rule out. That is what the
 * first run of it printed.
 */
function hpos(): bool {
	$util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
	if ( class_exists( $util ) && method_exists( $util, 'custom_orders_table_usage_is_enabled' ) ) {
		return (bool) $util::custom_orders_table_usage_is_enabled();
	}
	return 'yes' === get_option( 'woocommerce_custom_orders_table_enabled', 'no' );
}

/**
 * A SELECT, or the reason there is nothing.
 *
 * $wpdb->get_results() returns [] on a SQL ERROR just as it does on a table
 * with nothing in it, and throws neither way — so a section can print an empty
 * heading and look like an answer. `COUNT(*) AS lines` did exactly that:
 * LINES is a reserved word in MariaDB.
 */
function rows( string $sql ): array {
	global $wpdb;
	$got = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore -- read-only diagnostic.
	if ( ! $got && $wpdb->last_error ) {
		echo "(the query failed: {$wpdb->last_error})\n";
		return [];
	}
	return (array) $got;
}

function section( string $title ): void {
	echo "\n== {$title} ==\n";
}

/** Never let one broken section blank out the rest. */
function safely( string $title, callable $fn ): void {
	section( $title );
	try {
		$fn();
	} catch ( \Throwable $e ) {
		echo "(failed: " . $e->getMessage() . ")\n";
	}
}

echo "Dazont Ecom — on-site read, " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";

safely( 'Where this ran', function () {
	echo 'Site: ' . home_url() . "\n";
	echo 'WordPress: ' . ( function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '?' ) . "\n";
	echo 'WooCommerce: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . "\n";
	echo 'Dazont Ecom: ' . ( defined( 'DZE_VERSION' ) ? DZE_VERSION : 'not active' ) . "\n";
	global $wpdb;
	echo 'Order storage: ' . ( hpos() ? 'HPOS (wc_orders)' : 'posts table' ) . "\n";
} );

safely( 'Currency — what is actually stored', function () {
	echo 'WooCommerce base currency: ' . get_option( 'woocommerce_currency', '?' ) . "\n";
	$wcml = get_option( '_wcml_settings', [] );
	echo "WooCommerce Multilingual currency_options (raw, as WCML stores it):\n";
	var_export( is_array( $wcml ) ? ( $wcml['currency_options'] ?? 'not set' ) : 'no _wcml_settings option' );
	echo "\n";
	foreach ( [ 'woocs_currency_data', 'woocommerce_aelia_currencyswitcher_exchange_rates' ] as $key ) {
		$val = get_option( $key, null );
		if ( null !== $val ) {
			echo "\n{$key} (raw):\n";
			var_export( $val );
			echo "\n";
		}
	}
} );

safely( 'Revenue exactly as the plugin reads it today (24-month window, grouped by currency)', function () {
	global $wpdb;
	$table = $wpdb->prefix . 'wc_order_product_lookup';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		echo "(no {$table} — this WooCommerce has no analytics lookup table)\n";
		return;
	}
	$orders = $wpdb->prefix . 'wc_orders';
	$hpos   = hpos();
	$since  = gmdate( 'Y-m-d H:i:s', time() - 24 * 30 * DAY_IN_SECONDS );
	$sql    = $hpos
		? "SELECT UPPER( COALESCE( o.currency, '' ) ) AS cur, COUNT(*) AS nb,
				SUM( l.product_qty ) AS qty, SUM( l.product_net_revenue ) AS raw_total
			FROM {$table} l LEFT JOIN {$orders} o ON o.id = l.order_id
			WHERE l.date_created >= %s GROUP BY cur ORDER BY raw_total DESC"
		: "SELECT UPPER( COALESCE( m.meta_value, '' ) ) AS cur, COUNT(*) AS nb,
				SUM( l.product_qty ) AS qty, SUM( l.product_net_revenue ) AS raw_total
			FROM {$table} l LEFT JOIN {$wpdb->postmeta} m ON m.post_id = l.order_id AND m.meta_key = '_order_currency'
			WHERE l.date_created >= %s GROUP BY cur ORDER BY raw_total DESC";
	$rows = rows( $wpdb->prepare( $sql, $since ) );
	printf( "%-10s %8s %10s %18s %14s\n", 'Currency', 'Lines', 'Qty', 'Raw total', 'Per line' );
	foreach ( (array) $rows as $r ) {
		$cur = '' !== $r['cur'] ? $r['cur'] : '[empty]';
		$n   = max( 1, (int) $r['nb'] );
		printf( "%-10s %8d %10d %18.2f %14.2f\n", $cur, (int) $r['nb'], (int) $r['qty'], (float) $r['raw_total'], (float) $r['raw_total'] / $n );
	}
} );

safely( 'Order lines with no order behind them', function () {
	// The finding that settled it the first time, kept as a permanent reading:
	// rows in WooCommerce's sales table whose order_id resolves to nothing.
	// They are not sales — 67 of them carried 84% of this shop's reported
	// revenue — and the plugin now leaves them out and names them on screen.
	// If this section grows, an import has run again.
	global $wpdb;
	$table = $wpdb->prefix . 'wc_order_product_lookup';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		echo "(no {$table})\n";
		return;
	}
	$join = hpos()
		? "LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id = l.order_id"
		: "LEFT JOIN {$wpdb->posts} p ON p.ID = l.order_id AND p.post_type = 'shop_order'";
	$key   = hpos() ? 'o.id' : 'p.ID';
	$since = gmdate( 'Y-m-d H:i:s', time() - 24 * 30 * DAY_IN_SECONDS );
	$sql   = "SELECT COUNT(*) AS nb, SUM( l.product_net_revenue ) AS raw_total,
				SUM( CASE WHEN l.date_created >= %s THEN 1 ELSE 0 END ) AS nb_window,
				SUM( CASE WHEN l.date_created >= %s THEN l.product_net_revenue ELSE 0 END ) AS raw_window
			FROM {$table} l {$join} WHERE {$key} IS NULL";
	$got = rows( $wpdb->prepare( $sql, $since, $since ) );
	$r   = (array) ( $got[0] ?? [] );
	printf( "Orphan lines, all time: %d, carrying %.2f\n", (int) ( $r['nb'] ?? 0 ), (float) ( $r['raw_total'] ?? 0 ) );
	printf( "Inside the 24-month window: %d, carrying %.2f\n", (int) ( $r['nb_window'] ?? 0 ), (float) ( $r['raw_window'] ?? 0 ) );
	echo "(the plugin leaves these out of Revenue and says so on the diagnostic screen)\n";
} );

safely( 'The 20 biggest order lines ever recorded — no date limit, no grouping', function () {
	// The line-by-line truth: which order, when, in what currency FIELD
	// (blank included, on purpose — a blank is read as the shop's own
	// currency with no conversion, and that is a common way an old order
	// ends up counted as something it never was), and for which product.
	global $wpdb;
	$table = $wpdb->prefix . 'wc_order_product_lookup';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		echo "(no {$table})\n";
		return;
	}
	$orders = $wpdb->prefix . 'wc_orders';
	$hpos   = hpos();
	$sql    = $hpos
		? "SELECT l.order_id, l.product_id, l.product_qty AS qty, l.product_net_revenue AS rev,
				l.date_created, UPPER( COALESCE( o.currency, '' ) ) AS cur
			FROM {$table} l LEFT JOIN {$orders} o ON o.id = l.order_id
			ORDER BY l.product_net_revenue DESC LIMIT 20"
		: "SELECT l.order_id, l.product_id, l.product_qty AS qty, l.product_net_revenue AS rev,
				l.date_created, UPPER( COALESCE( m.meta_value, '' ) ) AS cur
			FROM {$table} l LEFT JOIN {$wpdb->postmeta} m ON m.post_id = l.order_id AND m.meta_key = '_order_currency'
			ORDER BY l.product_net_revenue DESC LIMIT 20";
	$rows = rows( $sql );
	printf( "%-10s %-10s %6s %12s %-12s %-8s  %s\n", 'Order', 'Product', 'Qty', 'Revenue', 'Date', 'Currency', 'Product name' );
	foreach ( (array) $rows as $r ) {
		$name = function_exists( 'get_the_title' ) ? wp_strip_all_tags( (string) get_the_title( (int) $r['product_id'] ) ) : '';
		$cur  = '' !== $r['cur'] ? $r['cur'] : '[empty]';
		printf(
			"%-10d %-10d %6d %12.2f %-12s %-8s  %s\n",
			(int) $r['order_id'],
			(int) $r['product_id'],
			(int) $r['qty'],
			(float) $r['rev'],
			substr( (string) $r['date_created'], 0, 10 ),
			$cur,
			mb_substr( $name, 0, 40 )
		);
	}
} );

safely( "The shop's own image prompts (Settings → Product content)", function () {
	if ( ! class_exists( 'DZE_Content' ) ) {
		echo "(DZE_Content is not loaded — is the Product content module enabled?)\n";
		return;
	}
	$rows = DZE_Content::image_templates();
	if ( ! $rows ) {
		echo "(no image prompts configured)\n";
		return;
	}
	foreach ( $rows as $i => $tpl ) {
		printf(
			"[%d] id=%s target=%-10s name=%s\n",
			$i,
			(string) ( $tpl['id'] ?? '' ),
			(string) ( $tpl['target'] ?? 'gallery' ),
			(string) ( $tpl['name'] ?? '' )
		);
	}
} );

echo "\n== Done — paste everything above this line back to the owner ==\n";
