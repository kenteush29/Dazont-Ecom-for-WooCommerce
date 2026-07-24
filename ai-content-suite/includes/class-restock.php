<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Restockage" admin module.
 *
 * Lists product-lines (simple products OR variable parents) that have at least
 * one out-of-stock element, so the shop owner can drive the restock backlog
 * without manual exports. Sales figures are cached via WP-Cron for speed on
 * large catalogues (600+ product-lines).
 *
 * See admin/views/restock-page.php + class-restock-list-table.php.
 */
final class AICS_Restock {

	public const CRON_HOOK   = 'aics_restock_recalc_sales';
	public const SALES_META  = '_kula_total_sales_cached';
	public const LAST_RECALC = 'aics_restock_last_recalc';
	public const MENU_SLUG   = 'aics-restockage';

	/**
	 * Order statuses counted for the "interest" metric.
	 *
	 * This indicator measures demand/interest in a product, NOT revenue, so it
	 * counts EVERY real order — including refunded, cancelled and failed ones
	 * (a refund caused by an out-of-stock item is still a strong buying signal).
	 * Refund line-items are never subtracted: the ordered quantity is what counts.
	 */
	private function get_counted_statuses(): array {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			// e.g. wc-pending, wc-processing, wc-completed, wc-refunded, wc-cancelled…
			$statuses = array_keys( wc_get_order_statuses() );
			return array_map(
				static fn( string $s ): string => preg_replace( '/^wc-/', '', $s ),
				$statuses
			);
		}
		return [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ];
	}

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_submenu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'init',                  [ $this, 'maybe_schedule_cron' ] );

		add_action( self::CRON_HOOK, [ $this, 'recalc_sales' ] );

		add_action( 'wp_ajax_aics_restock_variations', [ $this, 'ajax_variations' ] );
		add_action( 'wp_ajax_aics_restock_recalc',     [ $this, 'ajax_recalc' ] );
	}

	// -------------------------------------------------------------------------
	// Cron scheduling
	// -------------------------------------------------------------------------

	public function maybe_schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Weekly cadence — coherent with a monthly/quarterly restock rhythm.
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	public static function clear_cron(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// -------------------------------------------------------------------------
	// Menu + assets
	// -------------------------------------------------------------------------

	public function register_submenu(): void {
		add_submenu_page(
			'aics-settings',
			__( 'Restockage', 'ai-content-suite' ),
			__( 'Restockage', 'ai-content-suite' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	private function is_restock_screen( string $hook ): bool {
		return strpos( $hook, self::MENU_SLUG ) !== false;
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! $this->is_restock_screen( $hook ) ) {
			return;
		}
		wp_enqueue_style( 'aics-admin', AICS_URL . 'admin/css/admin.css', [], AICS_VERSION );
		wp_enqueue_script( 'aics-restock', AICS_URL . 'admin/js/restock.js', [ 'jquery' ], AICS_VERSION, true );
		wp_localize_script( 'aics-restock', 'aicsRestock', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'aics_admin' ),
			'i18n'    => [
				'loading'    => __( 'Loading variations…', 'ai-content-suite' ),
				'recalc'     => __( 'Recalculating sales… this may take a moment.', 'ai-content-suite' ),
				'recalcDone' => __( 'Sales cache updated.', 'ai-content-suite' ),
				'error'      => __( 'Error', 'ai-content-suite' ),
				'noVar'      => __( 'No out-of-stock variations found.', 'ai-content-suite' ),
				'subNote'    => __( 'Only out-of-stock variations are listed. The product\'s Sales total above covers all its variations (in stock included).', 'ai-content-suite' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-content-suite' ) );
		}

		require_once AICS_DIR . 'includes/class-restock-list-table.php';

		$table = new AICS_Restock_List_Table();
		$table->prepare_items();

		$last_recalc = (int) get_option( self::LAST_RECALC, 0 );

		require AICS_DIR . 'admin/views/restock-page.php';
	}

	// -------------------------------------------------------------------------
	// Data gathering (shared)
	// -------------------------------------------------------------------------

	/**
	 * Builds the index of out-of-stock product-lines.
	 *
	 * @return array<int, array{id:int,type:string,oos:int[],total:int}>
	 *         Keyed by line id (simple product id or variable parent id).
	 */
	public static function get_line_index(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_type, p.post_parent
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} sm
			     ON sm.post_id = p.ID
			     AND sm.meta_key = '_stock_status'
			     AND sm.meta_value = 'outofstock'
			 WHERE p.post_type IN ('product','product_variation')
			   AND p.post_status = 'publish'"
		);

		// WPML: keep only default-language product-lines. Sales are aggregated
		// across all languages separately (see recalc_sales()).
		$default_lang = AICS_Wpml_Translate::default_language();

		$lines = [];
		foreach ( $rows as $row ) {
			// Skip translations: only the default-language post represents the line.
			if ( $default_lang ) {
				$lang = AICS_Wpml_Translate::post_language( (int) $row->ID, $row->post_type );
				if ( $lang && $lang !== $default_lang ) {
					continue;
				}
			}

			if ( $row->post_type === 'product' ) {
				$id = (int) $row->ID;
				if ( ! isset( $lines[ $id ] ) ) {
					$lines[ $id ] = [ 'id' => $id, 'type' => 'simple', 'oos' => [], 'total' => 0 ];
				}
			} else {
				$parent = (int) $row->post_parent;
				if ( ! $parent ) {
					continue;
				}
				if ( ! isset( $lines[ $parent ] ) ) {
					$lines[ $parent ] = [ 'id' => $parent, 'type' => 'variable', 'oos' => [], 'total' => 0 ];
				}
				$lines[ $parent ]['type']  = 'variable';
				$lines[ $parent ]['oos'][] = (int) $row->ID;
			}
		}

		// Total variation count per variable parent (single grouped query).
		$parents = [];
		foreach ( $lines as $line ) {
			if ( $line['type'] === 'variable' ) {
				$parents[] = $line['id'];
			}
		}
		if ( $parents ) {
			$in     = implode( ',', array_map( 'intval', $parents ) );
			$counts = $wpdb->get_results(
				"SELECT post_parent, COUNT(*) AS c
				 FROM {$wpdb->posts}
				 WHERE post_type = 'product_variation'
				   AND post_status = 'publish'
				   AND post_parent IN ($in)
				 GROUP BY post_parent"
			);
			foreach ( $counts as $c ) {
				$pid = (int) $c->post_parent;
				if ( isset( $lines[ $pid ] ) ) {
					$lines[ $pid ]['total'] = (int) $c->c;
				}
			}
		}

		return $lines;
	}

	/**
	 * Returns the cached aggregated sales figure for a product-line.
	 * Same metric for simple and variable products: total units sold across all
	 * paid orders, aggregated across every WPML language version. Populated by
	 * recalc_sales(); 0 until the first recalculation runs.
	 */
	public static function get_line_sales( int $id ): int {
		return (int) get_post_meta( $id, self::SALES_META, true );
	}

	// -------------------------------------------------------------------------
	// AJAX: lazy-load variations sub-table
	// -------------------------------------------------------------------------

	public function ajax_variations(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}

		$parent_id = (int) ( $_POST['parent_id'] ?? 0 );
		if ( ! $parent_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product.', 'ai-content-suite' ) ] );
		}

		global $wpdb;
		$variation_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} sm
			     ON sm.post_id = p.ID AND sm.meta_key = '_stock_status' AND sm.meta_value = 'outofstock'
			 WHERE p.post_type = 'product_variation'
			   AND p.post_status = 'publish'
			   AND p.post_parent = %d
			 ORDER BY p.menu_order ASC, p.ID ASC",
			$parent_id
		) );

		$rows_html = '';

		foreach ( $variation_ids as $vid ) {
			$variation = wc_get_product( (int) $vid );
			if ( ! $variation ) {
				continue;
			}
			$name = wc_get_formatted_variation( $variation, true );
			if ( $name === '' ) {
				$name = $variation->get_name();
			}
			$sku   = $variation->get_sku();
			$price = $variation->get_price_html();
			$sales = (int) get_post_meta( (int) $vid, self::SALES_META, true );

			$rows_html .= '<tr>'
				. '<td>' . esc_html( $name ) . '</td>'
				. '<td>' . esc_html( $sku ?: '—' ) . '</td>'
				. '<td>' . wp_kses_post( $price ?: '—' ) . '</td>'
				. '<td>' . esc_html( (string) $sales ) . '</td>'
				. '</tr>';
		}

		wp_send_json_success( [ 'rows' => $rows_html, 'count' => count( $variation_ids ) ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: manual recalculation
	// -------------------------------------------------------------------------

	public function ajax_recalc(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}

		$processed = $this->recalc_sales();

		wp_send_json_success( [
			'message'   => sprintf(
				/* translators: %d: number of variations processed */
				__( 'Done. %d variations processed.', 'ai-content-suite' ),
				$processed
			),
			'timestamp' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() ),
		] );
	}

	// -------------------------------------------------------------------------
	// Sales aggregation worker (cron + manual)
	// -------------------------------------------------------------------------

	/**
	 * Re-computes lifetime sales from paid orders using the WooCommerce CRUD API
	 * (HPOS-safe). Every ordered line item is folded onto the canonical,
	 * default-language product / variation, so figures are aggregated across all
	 * WPML languages. Totals are cached on:
	 *   - each product-line (simple product or variable parent)  → main list
	 *   - each variation                                         → sub-table
	 * Returns the number of product-lines written.
	 */
	public function recalc_sales(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$line_totals = []; // canonical product/parent id => qty
		$var_totals  = []; // canonical variation id      => qty

		$statuses = $this->get_counted_statuses();
		$page     = 1;
		$limit    = 100;

		do {
			$orders = wc_get_orders( [
				'limit'   => $limit,
				'page'    => $page,
				'status'  => $statuses,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'objects',
			] );

			foreach ( $orders as $order ) {
				foreach ( $order->get_items() as $item ) {
					if ( ! $item instanceof WC_Order_Item_Product ) {
						continue;
					}
					$qty = (int) $item->get_quantity();
					if ( $qty <= 0 ) {
						continue;
					}

					$pid = (int) $item->get_product_id();
					$vid = (int) $item->get_variation_id();

					if ( $vid ) {
						// Variable line: attribute the sale to the parent + variation.
						$canon_parent = AICS_Wpml_Translate::canonical_id( $pid, 'product' );
						$canon_var    = AICS_Wpml_Translate::canonical_id( $vid, 'product_variation' );
						$line_totals[ $canon_parent ] = ( $line_totals[ $canon_parent ] ?? 0 ) + $qty;
						$var_totals[ $canon_var ]     = ( $var_totals[ $canon_var ] ?? 0 ) + $qty;
					} elseif ( $pid ) {
						// Simple line.
						$canon = AICS_Wpml_Translate::canonical_id( $pid, 'product' );
						$line_totals[ $canon ] = ( $line_totals[ $canon ] ?? 0 ) + $qty;
					}
				}
			}

			$page++;
		} while ( count( $orders ) === $limit );

		foreach ( $line_totals as $id => $qty ) {
			update_post_meta( (int) $id, self::SALES_META, (int) $qty );
		}
		foreach ( $var_totals as $id => $qty ) {
			update_post_meta( (int) $id, self::SALES_META, (int) $qty );
		}

		update_option( self::LAST_RECALC, time(), false );

		return count( $line_totals );
	}
}
