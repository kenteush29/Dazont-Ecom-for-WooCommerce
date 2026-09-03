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

safely( 'Order lines with no order behind them', function () {
	// Rows in WooCommerce's sales table whose order_id resolves to nothing.
	// This is why the plugin no longer shows money at all: 751 of these, the
	// 67 inside the window carrying 84% of what the Revenue column reported.
	// Correct arithmetic on an input like that is still a wrong answer.
	// Kept as a permanent reading — WooCommerce's own reports still count
	// them, so this says whether they are still there, and whether a fresh
	// import has brought more.
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
	echo "(WooCommerce's own Analytics still counts these; 'Regenerate order stats'\n"
		. " under WooCommerce -> Status -> Tools is what clears them at the source)\n";
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
