<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data cleanup — what each module writes to the database, and how to erase it.
 *
 * Every module declares its footprint here, in one place, so the admin can see
 * what it costs and wipe it on its own. Three rules hold this together:
 *
 *  1. Deactivating a module NEVER deletes anything. Switching a function off
 *     and throwing its data away are two different decisions, and the second
 *     one is always explicit.
 *  2. Each module is erased separately. "Erase everything" is only a loop over
 *     the same descriptors — there is no second, divergent code path.
 *  3. Only keys this plugin owns are listed. WooCommerce data a module writes
 *     into (a sale price, a product image) is never touched: erasing our own
 *     footprint must not damage the shop.
 *
 * A new module with no entry here shows up as undeclared in the interface,
 * which is the reminder to come and declare it.
 */
final class DZE_Cleanup {

	public const NONCE       = 'dze_cleanup';
	public const OPT_ON_UNINSTALL = 'dze_purge_on_uninstall';

	/**
	 * Footprint per module id (ids come from DZE_Modules::catalog()).
	 *
	 * options    — whole option rows.
	 * post_meta  — meta keys on products/orders.
	 * term_meta  — meta keys on categories.
	 * user_meta  — meta keys on users (dismissed notices and the like).
	 * transients — exact names, or prefixes ending in '_'.
	 * tables     — table names without the WordPress prefix.
	 * comment_meta — meta key marking comments this plugin created; the
	 *                comments carrying it are deleted with it.
	 */
	public static function map(): array {
		return [
			'restock' => [
				'post_meta'  => [ '_dze_total_sales_cached' ],
				'transients' => [ 'dze_all_saleable_ids' ],
			],
			'dashboard' => [
				'transients' => [ 'dze_dash_topcats' ],
			],
			'trending' => [
				'options'    => [ 'dze_trending_cache_version' ],
				'transients' => [ 'dze_trending_' ],
			],
			'discounts' => [
				'options'    => [ 'dze_discount_rules', 'dze_discount_exclusions', 'dze_sale_sync_queue' ],
				'post_meta'  => [ '_dze_sale_rule', '_dze_sale_managed', '_dze_sale_prev', '_dze_price_prev', '_dze_sale_prev_from', '_dze_sale_prev_to' ],
				'transients' => [ 'dze_discount_notice' ],
			],
			'gmc' => [
				'options'    => [ 'dze_gmc_credentials', 'dze_gmc_accounts', 'dze_gmc_oauth', 'dze_gmc_advanced', 'dze_gmc_connection', 'dze_gmc_datasources', 'dze_gmc_ads_only' ],
				'transients' => [ 'dze_gmc_oauth_token', 'dze_gmc_ads_' ],
			],
			'gmc_activation' => [
				'post_meta' => [ '_merchant_center_activation' ],
			],
			'marketing_ai' => [
				'options'    => [ 'dze_mai_settings', 'dze_mai_suggestions', 'dze_ai_usage' ],
				'transients' => [ 'dze_mai_shop_context', 'dze_mai_models' ],
			],
			'sourcing' => [
				'tables'     => [ 'dze_keywords' ],
				'options'    => [ 'dze_kw_schema', 'dze_kw_job' ],
				'term_meta'  => [ '_dze_insights' ],
				'post_meta'  => [ '_dze_researched' ],
				'transients' => [ 'dze_kw_up_', 'dze_x_cat_sales_v2' ],
			],
			'category_content' => [
				'options'    => [ 'dze_catcontent_settings' ],
				'term_meta'  => [ '_dze_desc_generated' ],
				'user_meta'  => [ 'dze_cc_sitemap_notice_off' ],
				'transients' => [ 'dze_cc_sitemap_v8', 'dze_cc_sitemap_lock', 'dze_cc_pcount_' ],
			],
			'content' => [
				'options'    => [ 'dze_content_settings', 'dze_content_log' ],
				'post_meta'  => [ '_dze_feature_shots', '_dze_pending_review', '_dze_variation_notes', '_dze_prompt' ],
				'user_meta'  => [ '_dze_content_bulk' ],
				'transients' => [ 'dze_content_bulk_', 'dze_product_meta_keys', 'dze_pending_count', 'dze_rfr_' ],
			],
			'translate' => [
				'options'   => [ 'dze_translate_settings' ],
				'post_meta' => [ '_dze_tr_hash', '_dze_tr_by' ],
			],
			// A module that no longer ships. Its descriptors stay so an install
			// that once used it can still be erased of what it left behind.
			'pod' => [
				'options'   => [ 'dze_pod_settings' ],
				'post_meta' => [ '_dze_pod_design_id' ],
			],
			'reviews' => [
				'options'      => [ 'dze_reviews_settings', 'dze_reviews_mailfix' ],
				'transients'   => [ 'dze_reviews_queue_' ],
				'comment_meta' => '_dze_generated',
			],
			'queue' => [
				'tables'  => [ 'dze_queue' ],
				'options' => [ 'dze_queue_schema' ],
			],
			// The lab keeps nothing of its own: what it produces is a media
			// library entry, which is WordPress's data and not ours to erase.
			'image_lab' => [],
			'variation_split' => [],
		];
	}

	/** What belongs to no module in particular: the plugin's own plumbing. */
	public static function core_map(): array {
		return [
			'options' => [ 'dze_modules', 'dze_autoload_trimmed', 'dze_dev_channel', 'dze_price_rounding', self::OPT_ON_UNINSTALL ],
		];
	}

	/**
	 * Counts and weighs what a module holds, without touching it.
	 *
	 * @return array{rows:int,bytes:int,detail:array,declared:bool}
	 */
	public static function measure( string $id ): array {
		global $wpdb;
		$map = 'core' === $id ? self::core_map() : ( self::map()[ $id ] ?? null );
		if ( null === $map ) {
			return [ 'rows' => 0, 'bytes' => 0, 'detail' => [], 'declared' => false ];
		}
		// Counting is a dozen queries per module; the screen shows them all at
		// once, so the answer is held for ten minutes and dropped on any purge.
		$cache = get_transient( 'dze_cleanup_size' );
		$cache = is_array( $cache ) ? $cache : [];
		if ( isset( $cache[ $id ] ) ) {
			return $cache[ $id ];
		}
		$rows   = 0;
		$bytes  = 0;
		$detail = [];

		foreach ( (array) ( $map['tables'] ?? [] ) as $t ) {
			$table = $wpdb->prefix . $t;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}
			$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			$s = $wpdb->get_row( $wpdb->prepare(
				'SELECT data_length + index_length AS size FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s',
				$table
			), ARRAY_A );
			$rows  += $n;
			$bytes += (int) ( $s['size'] ?? 0 );
			if ( $n ) {
				/* translators: 1: row count, 2: table name */
				$detail[] = sprintf( __( '%1$s rows in %2$s', 'dazont-ecom' ), number_format_i18n( $n ), $t );
			}
		}

		$pairs = [
			'post_meta'    => [ $wpdb->postmeta, 'meta_key', 'meta_value', __( 'product meta', 'dazont-ecom' ) ],
			'term_meta'    => [ $wpdb->termmeta, 'meta_key', 'meta_value', __( 'category meta', 'dazont-ecom' ) ],
			'user_meta'    => [ $wpdb->usermeta, 'meta_key', 'meta_value', __( 'user meta', 'dazont-ecom' ) ],
		];
		foreach ( $pairs as $key => $p ) {
			$keys = (array) ( $map[ $key ] ?? [] );
			if ( ! $keys ) {
				continue;
			}
			$in  = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$res = $wpdb->get_row( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb, keys are placeholders.
				"SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH({$p[2]})),0) AS b FROM {$p[0]} WHERE {$p[1]} IN ({$in})",
				$keys
			), ARRAY_A );
			$n = (int) ( $res['n'] ?? 0 );
			if ( $n ) {
				$rows  += $n;
				$bytes += (int) $res['b'] + $n * 60; // row overhead, roughly.
				$detail[] = sprintf( '%s %s', number_format_i18n( $n ), $p[3] );
			}
		}

		foreach ( (array) ( $map['options'] ?? [] ) as $o ) {
			$res = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)),0) AS b FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb.
				$o
			), ARRAY_A );
			$rows  += (int) ( $res['n'] ?? 0 );
			$bytes += (int) ( $res['b'] ?? 0 );
		}
		if ( ! empty( $map['options'] ) ) {
			$detail[] = sprintf(
				/* translators: %s: number of settings rows */
				__( '%s settings rows', 'dazont-ecom' ),
				number_format_i18n( count( (array) $map['options'] ) )
			);
		}

		$tn = 0;
		foreach ( (array) ( $map['transients'] ?? [] ) as $t ) {
			$like = $wpdb->esc_like( '_transient_' . $t ) . ( '_' === substr( $t, -1 ) ? '%' : '' );
			$res  = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)),0) AS b FROM {$wpdb->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb.
				$like
			), ARRAY_A );
			$tn    += (int) ( $res['n'] ?? 0 );
			$rows  += (int) ( $res['n'] ?? 0 );
			$bytes += (int) ( $res['b'] ?? 0 );
		}
		if ( $tn ) {
			/* translators: %s: number of cached entries */
			$detail[] = sprintf( __( '%s cached entries', 'dazont-ecom' ), number_format_i18n( $tn ) );
		}

		if ( ! empty( $map['comment_meta'] ) ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb.
				$map['comment_meta']
			) );
			if ( $n ) {
				$rows  += $n;
				$bytes += $n * 400; // a review plus its meta, roughly.
				/* translators: %s: number of generated reviews */
				$detail[] = sprintf( __( '%s generated reviews', 'dazont-ecom' ), number_format_i18n( $n ) );
			}
		}

		$out           = [ 'rows' => $rows, 'bytes' => $bytes, 'detail' => $detail, 'declared' => true ];
		$cache[ $id ] = $out;
		set_transient( 'dze_cleanup_size', $cache, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Erases one module's footprint. Deliberately dumb: it deletes exactly what
	 * map() declares, nothing inferred, nothing guessed.
	 *
	 * @return array{rows:int}
	 */
	public static function purge( string $id ): array {
		global $wpdb;
		$map = 'core' === $id ? self::core_map() : ( self::map()[ $id ] ?? null );
		if ( null === $map ) {
			return [ 'rows' => 0 ];
		}
		delete_transient( 'dze_cleanup_size' ); // the figures on screen are now stale.
		$done = 0;

		foreach ( (array) ( $map['tables'] ?? [] ) as $t ) {
			$table = $wpdb->prefix . $t;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$done += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
				$wpdb->query( "DROP TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			}
		}

		foreach ( (array) ( $map['post_meta'] ?? [] ) as $k ) {
			$done += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $k ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb.
		}
		foreach ( (array) ( $map['term_meta'] ?? [] ) as $k ) {
			$done += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = %s", $k ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb.
		}
		foreach ( (array) ( $map['user_meta'] ?? [] ) as $k ) {
			$done += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", $k ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb.
		}
		foreach ( (array) ( $map['options'] ?? [] ) as $o ) {
			if ( delete_option( $o ) ) {
				$done++;
			}
		}
		foreach ( (array) ( $map['transients'] ?? [] ) as $t ) {
			if ( '_' === substr( $t, -1 ) ) {
				$like = $wpdb->esc_like( '_transient_' . $t ) . '%';
				$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb.
				foreach ( $rows as $name ) {
					delete_transient( substr( $name, strlen( '_transient_' ) ) );
					$done++;
				}
				continue;
			}
			if ( delete_transient( $t ) ) {
				$done++;
			}
		}

		// Comments this plugin created — and only those.
		if ( ! empty( $map['comment_meta'] ) ) {
			$ids = (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb.
				$map['comment_meta']
			) );
			foreach ( $ids as $cid ) {
				wp_delete_comment( (int) $cid, true );
				$done++;
			}
			if ( $ids && class_exists( 'WC_Comments' ) ) {
				WC_Comments::clear_transients( 0 );
			}
		}

		return [ 'rows' => $done ];
	}

	/** Every module id plus 'core', for the "erase everything" pass. */
	public static function all_ids(): array {
		return array_merge( array_keys( self::map() ), [ 'core' ] );
	}

	public static function human_size( int $bytes ): string {
		if ( $bytes >= 1048576 ) {
			return number_format_i18n( $bytes / 1048576, 1 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return number_format_i18n( $bytes / 1024 ) . ' KB';
		}
		return number_format_i18n( $bytes ) . ' B';
	}
}
