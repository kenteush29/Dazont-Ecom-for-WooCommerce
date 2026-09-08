<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Restock" admin module.
 *
 * Lists product-lines (simple products OR variable parents) that have at least
 * one out-of-stock element, ranked by total sales, so the shop owner can drive
 * the restock backlog. Sales are cached via WP-Cron for speed on large
 * catalogues and aggregated across all WPML languages.
 */
final class DZE_Restock {

	public const CRON_HOOK   = 'dze_recalc_sales';
	public const SALES_META  = '_dze_total_sales_cached';
	public const LAST_RECALC = 'dze_last_recalc';
	public const MENU_SLUG   = 'dazont-ecom';
	public const NONCE       = 'dze_admin';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Cron must be wired in every context — real WP-Cron runs are NOT is_admin().
		// This is intentionally the only footprint on front-end requests.
		add_action( 'init',          [ $this, 'maybe_schedule_cron' ] );
		add_action( self::CRON_HOOK, [ $this, 'recalc_sales' ] );

		// Admin-only UI: menu, assets and AJAX endpoints never load on the front end.
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_dze_variations', [ $this, 'ajax_variations' ] );
		add_action( 'wp_ajax_dze_recalc',     [ $this, 'ajax_recalc' ] );
		add_action( 'wp_ajax_dze_restock',    [ $this, 'ajax_restock' ] );
	}

	// -------------------------------------------------------------------------
	// Cron
	// -------------------------------------------------------------------------

	public function maybe_schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	// -------------------------------------------------------------------------
	// Menu + assets
	// -------------------------------------------------------------------------

	public function register_menu(): void {
		// Top-level "Dazont Ecom" menu; Restock is its first module. Future
		// modules register additional submenu pages under the same slug.
		add_menu_page(
			__( 'Dazont Ecom', 'dazont-ecom' ),
			__( 'Dazont Ecom', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ],
			'dashicons-cart',
			56
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Restock', 'dazont-ecom' ),
			__( 'Restock', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}
		wp_enqueue_style( 'dze-admin', DZE_URL . 'admin/css/admin.css', [], DZE_VERSION );
		// ONE image viewer for the whole plugin. This screen used to carry a
		// lightbox of its own — no arrows, no counter, and a blank box while
		// the photograph downloaded.
		wp_enqueue_style( 'dze-zoom', DZE_URL . 'admin/css/zoom.css', [], DZE_VERSION );
		wp_enqueue_script( 'dze-hzoom', DZE_URL . 'admin/js/hzoom.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-hzoom', 'dzeZoomI18n', [
			'zoom'   => __( 'See this image full size', 'dazont-ecom' ),
			'close'  => __( 'Close', 'dazont-ecom' ),
			'prev'   => __( 'Previous image', 'dazont-ecom' ),
			'next'   => __( 'Next image', 'dazont-ecom' ),
			'failed' => __( 'This image could not be loaded.', 'dazont-ecom' ),
		] );
		wp_enqueue_script( 'dze-admin', DZE_URL . 'admin/js/restock.js', [ 'jquery', 'dze-hzoom' ], DZE_VERSION, true );
		wp_localize_script( 'dze-admin', 'dzeRestock', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'loading'    => __( 'Loading variations…', 'dazont-ecom' ),
				'recalc'     => __( 'Recalculating sales… this may take a moment.', 'dazont-ecom' ),
				'error'      => __( 'Error', 'dazont-ecom' ),
				'noVar'      => __( 'No out-of-stock variations found.', 'dazont-ecom' ),
				'subNote'    => __( 'Only out-of-stock variations are listed. The product\'s Total Sales above covers all its variations (in stock included).', 'dazont-ecom' ),
				'restock'    => __( 'Restock', 'dazont-ecom' ),
				'restocking' => __( 'Restocking…', 'dazont-ecom' ),
				'noSelection'=> __( 'No product selected. Tick at least one checkbox.', 'dazont-ecom' ),
				'confirmBulk'=> __( 'Set the selected products back in stock (and clear their tracked quantity)?', 'dazont-ecom' ),
				'bulkDone'   => __( 'Restocked', 'dazont-ecom' ),
				'restockSelected' => __( 'Restock selected variations', 'dazont-ecom' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}

		require_once DZE_DIR . 'includes/class-restock-list-table.php';

		$table = new DZE_Restock_List_Table();
		$table->prepare_items();

		$last_recalc = (int) get_option( self::LAST_RECALC, 0 );

		require DZE_DIR . 'admin/views/restock-page.php';
	}

	// -------------------------------------------------------------------------
	// Data gathering
	// -------------------------------------------------------------------------

	/**
	 * @return array<int, array{id:int,type:string,oos:int[],total:int}>
	 */
	public static function get_line_index(): array {
		global $wpdb;

		// No user input in this query; static literals only.
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

		$default_lang = DZE_Wpml::default_language();

		$lines = [];
		foreach ( $rows as $row ) {
			if ( $default_lang ) {
				$lang = DZE_Wpml::post_language( (int) $row->ID, $row->post_type );
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

		$parents = [];
		foreach ( $lines as $line ) {
			if ( $line['type'] === 'variable' ) {
				$parents[] = (int) $line['id'];
			}
		}
		if ( $parents ) {
			// Ints only, sanitised via intval — safe to inline.
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

	public static function get_line_sales( int $id ): int {
		return (int) get_post_meta( $id, self::SALES_META, true );
	}

	/**
	 * Small clickable thumbnail (opens the full-size image in a lightbox).
	 * The full-size URL is loaded only when the user clicks — no front-end cost.
	 */
	public static function thumb_html( int $attachment_id ): string {
		if ( ! $attachment_id ) {
			return '<span class="dze-nothumb">—</span>';
		}
		$small = wp_get_attachment_image_url( $attachment_id, [ 48, 48 ] );
		$full  = wp_get_attachment_image_url( $attachment_id, 'full' );
		if ( ! $small ) {
			return '<span class="dze-nothumb">—</span>';
		}
		return sprintf(
			'<span class="dze-thumb-wrap"><img src="%1$s" class="dze-thumb" data-full="%2$s" width="48" height="48" loading="lazy" alt="" /><span class="dze-thumb-zoom dashicons dashicons-search" aria-hidden="true"></span></span>',
			esc_url( $small ),
			esc_url( $full ?: $small )
		);
	}

	// -------------------------------------------------------------------------
	// AJAX: lazy-load variations
	// -------------------------------------------------------------------------

	public function ajax_variations(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}

		$parent_id = isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0;
		if ( ! $parent_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product.', 'dazont-ecom' ) ] );
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

			// Variation image, falling back to the parent product image.
			$img_id = (int) $variation->get_image_id();
			if ( ! $img_id ) {
				$img_id = (int) get_post_thumbnail_id( $parent_id );
			}

			$rows_html .= '<tr>'
				. '<td class="check-column"><input type="checkbox" class="dze-var-cb" value="' . (int) $vid . '" checked /></td>'
				. '<td>' . self::thumb_html( $img_id ) . '</td>'
				. '<td>' . esc_html( $name ) . '</td>'
				. '<td>' . esc_html( $sku !== '' ? $sku : '—' ) . '</td>'
				. '<td>' . wp_kses_post( $price !== '' ? $price : '—' ) . '</td>'
				. '<td>' . esc_html( (string) $sales ) . '</td>'
				. '</tr>';
		}

		wp_send_json_success( [ 'rows' => $rows_html, 'count' => count( $variation_ids ) ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: manual recalculation
	// -------------------------------------------------------------------------

	public function ajax_recalc(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}

		$processed = $this->recalc_sales();

		wp_send_json_success( [
			'message'   => sprintf(
				/* translators: %d: number of product-lines processed */
				__( 'Done. %d product-lines processed.', 'dazont-ecom' ),
				$processed
			),
			'timestamp' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() ),
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: restock a product-line
	// -------------------------------------------------------------------------

	public function ajax_restock(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product.', 'dazont-ecom' ) ] );
		}

		$ok = $this->restock_line( $id );
		if ( ! $ok ) {
			wp_send_json_error( [ 'message' => __( 'Could not restock this product.', 'dazont-ecom' ) ] );
		}

		wp_send_json_success( [ 'id' => $id ] );
	}

	/**
	 * Sets a product-line back in stock. For a variable product, every
	 * out-of-stock variation is restocked and the parent status refreshed.
	 */
	private function restock_line( int $id ): bool {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return false;
		}

		// A single variation: restock it and re-sync its parent status.
		if ( $product->is_type( 'variation' ) ) {
			$this->set_in_stock( $product );
			$parent_id = $product->get_parent_id();
			if ( $parent_id && class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync_stock_status( $parent_id );
			}
			return true;
		}

		// A variable parent (used by bulk restock): restock every OOS variation.
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				$variation = wc_get_product( (int) $vid );
				if ( $variation && $variation->get_stock_status() === 'outofstock' ) {
					$this->set_in_stock( $variation );
				}
			}
			$this->set_in_stock( $product );
			return true;
		}

		$this->set_in_stock( $product );
		return true;
	}

	/**
	 * Puts a product/variation in stock WITHOUT numeric stock tracking:
	 * disables stock management and clears any tracked quantity, so the stock
	 * status is the single source of truth (no status/quantity conflict).
	 */
	private function set_in_stock( \WC_Product $product ): void {
		$product->set_manage_stock( false );
		$product->set_stock_quantity( null );
		$product->set_stock_status( 'instock' );
		$product->save();
	}

	// -------------------------------------------------------------------------
	// Sales aggregation worker
	// -------------------------------------------------------------------------

	/**
	 * Counted order statuses. This is a demand/interest signal, not revenue, so
	 * EVERY real order counts — including refunded, cancelled and failed — and
	 * refunds are never subtracted (ordered quantity is the signal).
	 */
	private function counted_statuses(): array {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			return array_map(
				static fn( string $s ): string => preg_replace( '/^wc-/', '', $s ),
				array_keys( wc_get_order_statuses() )
			);
		}
		return [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ];
	}

	/**
	 * Rebuilds cached sales from all orders via the WooCommerce CRUD API
	 * (HPOS-safe). Every ordered line item is folded onto its canonical,
	 * default-language product/variation. Returns product-lines written.
	 */
	public function recalc_sales(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$line_totals = [];
		$var_totals  = [];

		$statuses = $this->counted_statuses();
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
						$canon_parent = DZE_Wpml::canonical_id( $pid, 'product' );
						$canon_var    = DZE_Wpml::canonical_id( $vid, 'product_variation' );
						$line_totals[ $canon_parent ] = ( $line_totals[ $canon_parent ] ?? 0 ) + $qty;
						$var_totals[ $canon_var ]     = ( $var_totals[ $canon_var ] ?? 0 ) + $qty;
					} elseif ( $pid ) {
						$canon = DZE_Wpml::canonical_id( $pid, 'product' );
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
