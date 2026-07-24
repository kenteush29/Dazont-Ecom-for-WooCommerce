<?php
defined( 'ABSPATH' ) || exit;

/**
 * Dazont Ecom dashboard.
 *
 * A home screen for the plugin (first submenu entry) built from four blocks:
 *   - Top out-of-stock products (best sellers waiting for restock).
 *   - AI API consumption per provider, per month.
 *   - The planned marketing calendar (current + upcoming events).
 *   - Top product categories of the last 3 months, with their last "novelty
 *     search" date — a direct prompt to go source new products where money
 *     already flows.
 *
 * The same blocks are also registered as WordPress dashboard widgets, so the
 * WP home screen finally says something useful about the shop.
 */
final class DZE_Dashboard {

	public const MENU_SLUG = 'dazont-ecom-dashboard';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! is_admin() ) {
			return;
		}
		// Priority 20: after every module registered its submenu, so the
		// Dashboard entry can be moved to the top of the list.
		add_action( 'admin_menu',         [ $this, 'register_menu' ], 20 );
		add_action( 'wp_dashboard_setup', [ $this, 'register_widgets' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Dashboard', 'dazont-ecom' ),
			__( 'Dashboard', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
		// Move the Dashboard to the top of the Dazont Ecom submenu.
		global $submenu;
		if ( isset( $submenu[ DZE_Restock::MENU_SLUG ] ) ) {
			$items = $submenu[ DZE_Restock::MENU_SLUG ];
			foreach ( $items as $i => $item ) {
				if ( ( $item[2] ?? '' ) === self::MENU_SLUG ) {
					unset( $items[ $i ] );
					array_unshift( $items, $item );
					$submenu[ DZE_Restock::MENU_SLUG ] = array_values( $items ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- submenu reordering only.
					break;
				}
			}
		}
	}

	public function register_widgets(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'dze_dash_topcats', __( 'Dazont — Top categories (3 months)', 'dazont-ecom' ), [ $this, 'block_top_categories' ] );
		wp_add_dashboard_widget( 'dze_dash_oos',    __( 'Dazont — Top out-of-stock products', 'dazont-ecom' ), [ $this, 'block_out_of_stock' ] );
		wp_add_dashboard_widget( 'dze_dash_events', __( 'Dazont — Marketing calendar', 'dazont-ecom' ),        [ $this, 'block_events' ] );
		wp_add_dashboard_widget( 'dze_dash_ai',     __( 'Dazont — AI usage', 'dazont-ecom' ),                  [ $this, 'block_ai_usage' ] );
	}

	// =========================================================================
	// Page
	// =========================================================================

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Dazont Ecom — Dashboard', 'dazont-ecom' ) . '</h1>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(440px,1fr));gap:16px;margin-top:12px;">';
		$blocks = [
			__( 'Top categories — last 3 months', 'dazont-ecom' )   => 'block_top_categories',
			__( 'Top out-of-stock products', 'dazont-ecom' )        => 'block_out_of_stock',
			__( 'Marketing calendar', 'dazont-ecom' )               => 'block_events',
			__( 'AI usage per month', 'dazont-ecom' )               => 'block_ai_usage',
		];
		foreach ( $blocks as $title => $method ) {
			echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px 18px;">';
			echo '<h2 style="margin:0 0 10px;font-size:14px;">' . esc_html( $title ) . '</h2>';
			$this->$method();
			echo '</div>';
		}
		echo '</div></div>';
	}

	// =========================================================================
	// Blocks
	// =========================================================================

	/** Best-selling categories of the last 3 months + their last novelty search. */
	public function block_top_categories(): void {
		$rows = $this->top_categories();
		if ( empty( $rows ) ) {
			echo '<p class="description">' . esc_html__( 'No sales recorded in the last 3 months (or WooCommerce Analytics is still syncing).', 'dazont-ecom' ) . '</p>';
			return;
		}
		echo '<p class="description" style="margin-top:0;">' . esc_html__( 'Where the money flowed recently. Categories not searched for a while are prime candidates for your next sourcing session.', 'dazont-ecom' ) . '</p>';
		echo '<table class="widefat striped" style="border:0;"><thead><tr>';
		echo '<th>' . esc_html__( 'Category', 'dazont-ecom' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Units', 'dazont-ecom' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Products', 'dazont-ecom' ) . '</th>';
		echo '<th>' . esc_html__( 'Last search', 'dazont-ecom' ) . '</th>';
		echo '</tr></thead><tbody>';
		$explorer_url = add_query_arg( [ 'page' => DZE_Explorer::MENU_SLUG ], admin_url( 'admin.php' ) );
		foreach ( $rows as $r ) {
			$res = (int) get_term_meta( $r['id'], DZE_Explorer::META_RESEARCHED, true );
			echo '<tr>';
			echo '<td><a href="' . esc_url( $explorer_url ) . '">' . esc_html( $r['name'] ) . '</a></td>';
			echo '<td style="text-align:right;">' . esc_html( number_format_i18n( $r['qty'] ) ) . '</td>';
			echo '<td style="text-align:right;">' . esc_html( number_format_i18n( $r['count'] ) ) . '</td>';
			echo '<td>' . ( $res
				/* translators: %s: human time difference */
				? esc_html( sprintf( __( '%s ago', 'dazont-ecom' ), human_time_diff( $res ) ) )
				: '<span style="color:#b32d2e;">' . esc_html__( 'never', 'dazont-ecom' ) . '</span>' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p style="margin-bottom:0;"><a href="' . esc_url( $explorer_url ) . '">' . esc_html__( 'Open the Sourcing Assistant →', 'dazont-ecom' ) . '</a></p>';
	}

	/** Out-of-stock products ranked by their cached all-time sales (Restock data). */
	public function block_out_of_stock(): void {
		if ( ! class_exists( 'DZE_Restock' ) ) {
			return;
		}
		$lines = DZE_Restock::get_line_index();
		$rows  = [];
		foreach ( $lines as $line ) {
			$rows[] = [
				'id'    => (int) $line['id'],
				'sales' => DZE_Restock::get_line_sales( (int) $line['id'] ),
				'oos'   => is_countable( $line['oos'] ?? null ) ? count( $line['oos'] ) : 0,
			];
		}
		if ( empty( $rows ) ) {
			echo '<p class="description">' . esc_html__( 'Nothing is out of stock. 👌', 'dazont-ecom' ) . '</p>';
			return;
		}
		usort( $rows, static fn( $a, $b ) => $b['sales'] <=> $a['sales'] );
		$rows = array_slice( $rows, 0, 8 );
		echo '<table class="widefat striped" style="border:0;"><thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'dazont-ecom' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Total sales', 'dazont-ecom' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'OOS variations', 'dazont-ecom' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$edit = get_edit_post_link( $r['id'] );
			$name = get_the_title( $r['id'] ) ?: ( '#' . $r['id'] );
			echo '<tr>';
			echo '<td>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name ) ) . '</td>';
			echo '<td style="text-align:right;">' . esc_html( number_format_i18n( $r['sales'] ) ) . '</td>';
			echo '<td style="text-align:right;">' . esc_html( number_format_i18n( $r['oos'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p style="margin-bottom:0;"><a href="' . esc_url( add_query_arg( [ 'page' => DZE_Restock::MENU_SLUG ], admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Open Restock →', 'dazont-ecom' ) . '</a></p>';
	}

	/** Current + upcoming scheduled sales from the Marketing Events calendar. */
	public function block_events(): void {
		if ( ! class_exists( 'DZE_Discounts' ) ) {
			return;
		}
		$today = current_time( 'Y-m-d' );
		$rows  = [];
		foreach ( DZE_Discounts::get_rules() as $rule ) {
			if ( ( $rule['type'] ?? '' ) !== 'sale' ) {
				continue;
			}
			$end = (string) ( $rule['end'] ?? '' );
			if ( $end !== '' && $end < $today ) {
				continue; // finished.
			}
			$rows[] = [
				'title'   => (string) ( $rule['title'] ?? '' ),
				'percent' => (float) ( $rule['percent'] ?? 0 ),
				'start'   => (string) ( $rule['start'] ?? '' ),
				'end'     => $end,
				'enabled' => ! empty( $rule['enabled'] ),
			];
		}
		if ( empty( $rows ) ) {
			echo '<p class="description">' . esc_html__( 'No current or upcoming marketing events. Generate a calendar with the AI from the Marketing Events page.', 'dazont-ecom' ) . '</p>';
		} else {
			usort( $rows, static fn( $a, $b ) => strcmp( $a['start'], $b['start'] ) );
			$rows = array_slice( $rows, 0, 8 );
			$fmt  = get_option( 'date_format' );
			echo '<table class="widefat striped" style="border:0;"><thead><tr>';
			echo '<th>' . esc_html__( 'Event', 'dazont-ecom' ) . '</th>';
			echo '<th style="text-align:right;">%</th>';
			echo '<th>' . esc_html__( 'Dates', 'dazont-ecom' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'dazont-ecom' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$dates = trim(
					( $r['start'] ? date_i18n( $fmt, strtotime( $r['start'] ) ) : '…' )
					. ' → '
					. ( $r['end'] ? date_i18n( $fmt, strtotime( $r['end'] ) ) : '…' )
				);
				echo '<tr>';
				echo '<td>' . esc_html( $r['title'] !== '' ? $r['title'] : __( '(untitled)', 'dazont-ecom' ) ) . '</td>';
				echo '<td style="text-align:right;">' . esc_html( rtrim( rtrim( number_format_i18n( $r['percent'], 1 ), '0' ), ',.' ) ) . '%</td>';
				echo '<td>' . esc_html( $dates ) . '</td>';
				echo '<td>' . ( $r['enabled']
					? '<span style="color:#0a7040;">' . esc_html__( 'enabled', 'dazont-ecom' ) . '</span>'
					: '<span style="color:#996800;">' . esc_html__( 'disabled', 'dazont-ecom' ) . '</span>' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p style="margin-bottom:0;"><a href="' . esc_url( add_query_arg( [ 'page' => DZE_Discounts::MENU_SLUG_EVENTS ], admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Open Marketing Events →', 'dazont-ecom' ) . '</a></p>';
	}

	public function block_ai_usage(): void {
		DZE_Ai_Usage::render_graph( 6 );
		echo '<p style="margin-bottom:0;"><a href="' . esc_url( add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG ], admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Open AI Settings →', 'dazont-ecom' ) . '</a></p>';
	}

	// =========================================================================
	// Data
	// =========================================================================

	/**
	 * Top categories by units sold over the last 3 months (direct product
	 * assignments), from WooCommerce Analytics. Cached 6 hours.
	 *
	 * @return array<int,array{id:int,name:string,qty:int,count:int}>
	 */
	private function top_categories(): array {
		$cached = get_transient( 'dze_dash_topcats' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		$since = gmdate( 'Y-m-d', strtotime( '-3 months' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id, SUM(l.product_qty) AS qty
				 FROM {$table} l
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = l.product_id
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE tt.taxonomy = 'product_cat' AND l.date_created >= %s
				 GROUP BY tt.term_id ORDER BY qty DESC LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
				$since
			),
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $r ) {
			$term = get_term( (int) $r['term_id'], 'product_cat' );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$out[] = [
				'id'    => (int) $term->term_id,
				'name'  => $term->name,
				'qty'   => (int) $r['qty'],
				'count' => (int) $term->count,
			];
		}
		set_transient( 'dze_dash_topcats', $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}
}
