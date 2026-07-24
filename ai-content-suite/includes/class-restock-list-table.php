<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists out-of-stock product-lines (simple products + variable parents).
 * One row per product-line. Variable parents expose an expandable sub-table
 * (loaded via AJAX) with their out-of-stock variations.
 */
final class AICS_Restock_List_Table extends WP_List_Table {

	private const PER_PAGE_DEFAULT = 30;
	private const PER_PAGE_MAX     = 200;

	/** Allowed values for the "products per page" selector. */
	public const PER_PAGE_CHOICES = [ 20, 30, 50, 100, 200 ];

	public static function current_per_page(): int {
		$pp = isset( $_GET['per_page'] ) ? (int) $_GET['per_page'] : self::PER_PAGE_DEFAULT;
		if ( $pp < 1 ) {
			$pp = self::PER_PAGE_DEFAULT;
		}
		return min( self::PER_PAGE_MAX, $pp );
	}

	public function __construct() {
		parent::__construct( [
			'singular' => 'restock_line',
			'plural'   => 'restock_lines',
			'ajax'     => false,
		] );
	}

	public function get_columns(): array {
		return [
			'title'      => __( 'Product', 'ai-content-suite' ),
			'category'   => __( 'Category', 'ai-content-suite' ),
			'price'      => __( 'Price', 'ai-content-suite' ),
			'oos'        => __( 'OOS variations', 'ai-content-suite' ),
			'sales'      => __( 'Sales', 'ai-content-suite' )
				. ' <span class="dashicons dashicons-editor-help" title="'
				. esc_attr__( 'Demand signal: total units ordered across ALL orders (including refunded, cancelled and failed), never reduced by refunds, aggregated across all WPML languages. For a variable product this is the sum of ALL its variations, not only the out-of-stock ones.', 'ai-content-suite' )
				. '" style="font-size:16px;width:16px;height:16px;color:#999;cursor:help;"></span>',
		];
	}

	protected function get_sortable_columns(): array {
		return [
			'title' => [ 'title', false ],
			'sales' => [ 'sales', true ], // true => first click keeps desc default meaning
		];
	}

	public function no_items(): void {
		esc_html_e( 'No out-of-stock products. 🎉', 'ai-content-suite' );
	}

	// -------------------------------------------------------------------------
	// Data
	// -------------------------------------------------------------------------

	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		$lines = AICS_Restock::get_line_index();
		$ids   = array_keys( $lines );

		// Prime the post + meta caches once so per-line lookups are in-memory.
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}

		// ---- Category filter ----
		$cat = isset( $_GET['product_cat'] ) ? sanitize_key( wp_unslash( $_GET['product_cat'] ) ) : '';
		if ( $cat && $ids ) {
			$in_cat = get_posts( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $cat ] ],
			] );
			$ids = array_values( array_intersect( $ids, $in_cat ) );
		}

		// ---- Search (product title) ----
		$search = isset( $_REQUEST['s'] ) ? trim( (string) wp_unslash( $_REQUEST['s'] ) ) : '';
		if ( $search !== '' && $ids ) {
			$ids = array_values( array_filter( $ids, static function ( $id ) use ( $search ) {
				return stripos( get_the_title( $id ), $search ) !== false;
			} ) );
		}

		// ---- Build lightweight sortable rows ----
		$rows = [];
		foreach ( $ids as $id ) {
			$line = $lines[ $id ];
			$rows[] = [
				'id'    => $id,
				'type'  => $line['type'],
				'oos'   => count( $line['oos'] ),
				'total' => $line['total'],
				'sales' => AICS_Restock::get_line_sales( $id ),
				'title' => get_the_title( $id ),
			];
		}

		// ---- Sort ----
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'sales';
		$order   = isset( $_GET['order'] ) && strtolower( (string) $_GET['order'] ) === 'asc' ? 'asc' : 'desc';
		if ( ! isset( $_GET['orderby'] ) ) {
			$order = 'desc'; // default: sales desc
		}

		usort( $rows, static function ( $a, $b ) use ( $orderby, $order ) {
			if ( $orderby === 'title' ) {
				$cmp = strcasecmp( $a['title'], $b['title'] );
			} else {
				$cmp = $a['sales'] <=> $b['sales'];
			}
			return $order === 'asc' ? $cmp : -$cmp;
		} );

		// ---- Paginate ----
		$total    = count( $rows );
		$per_page = self::current_per_page();
		$current  = $this->get_pagenum();
		$rows     = array_slice( $rows, ( $current - 1 ) * $per_page, $per_page );

		$this->items = $rows;

		$this->set_pagination_args( [
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		] );
	}

	// -------------------------------------------------------------------------
	// Row rendering
	// -------------------------------------------------------------------------

	public function single_row( $item ): void {
		printf(
			'<tr id="restock-line-%1$d" class="aics-restock-row aics-restock-%2$s" data-id="%1$d" data-type="%2$s">',
			(int) $item['id'],
			esc_attr( $item['type'] )
		);
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	public function column_title( $item ): string {
		$edit  = get_edit_post_link( $item['id'] );
		$title = $item['title'] ?: sprintf( '#%d', $item['id'] );

		return '<strong><a href="' . esc_url( $edit ) . '" target="_blank">' . esc_html( $title ) . '</a></strong>';
	}

	public function column_category( $item ): string {
		$list = wc_get_product_category_list( $item['id'], ', ' );
		return $list ? wp_kses_post( $list ) : '—';
	}

	public function column_price( $item ): string {
		$product = wc_get_product( $item['id'] );
		if ( ! $product ) {
			return '—';
		}
		$html = $product->get_price_html();
		return $html ? wp_kses_post( $html ) : '—';
	}

	public function column_oos( $item ): string {
		if ( $item['type'] === 'simple' ) {
			return '<span style="color:#999;">N/A</span>';
		}
		// The expand/collapse toggle sits next to the OOS count.
		return sprintf(
			'<button type="button" class="aics-restock-toggle button-link" data-parent="%1$d" aria-expanded="false">▸</button> '
			. '<span class="aics-oos-badge">%2$d/%3$d</span>',
			(int) $item['id'],
			(int) $item['oos'],
			(int) $item['total']
		);
	}

	public function column_sales( $item ): string {
		return '<strong>' . esc_html( number_format_i18n( $item['sales'] ) ) . '</strong>';
	}

	public function column_default( $item, $column_name ): string {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	// -------------------------------------------------------------------------
	// Category filter dropdown above the table
	// -------------------------------------------------------------------------

	protected function extra_tablenav( $which ): void {
		if ( $which !== 'top' ) {
			return;
		}
		$current = isset( $_GET['product_cat'] ) ? sanitize_key( wp_unslash( $_GET['product_cat'] ) ) : '';
		$terms   = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}
		echo '<div class="alignleft actions">';
		echo '<select name="product_cat">';
		echo '<option value="">' . esc_html__( 'All categories', 'ai-content-suite' ) . '</option>';
		foreach ( $terms as $term ) {
			printf(
				'<option value="%s" %s>%s (%d)</option>',
				esc_attr( $term->slug ),
				selected( $current, $term->slug, false ),
				esc_html( $term->name ),
				(int) $term->count
			);
		}
		echo '</select>';

		// Products-per-page selector (max 200).
		$current_pp = self::current_per_page();
		echo '<select name="per_page" style="margin-left:6px;">';
		foreach ( self::PER_PAGE_CHOICES as $choice ) {
			printf(
				'<option value="%1$d" %2$s>%1$d %3$s</option>',
				(int) $choice,
				selected( $current_pp, $choice, false ),
				esc_html__( '/ page', 'ai-content-suite' )
			);
		}
		echo '</select>';

		submit_button( __( 'Filter', 'ai-content-suite' ), '', 'filter_action', false );
		echo '</div>';
	}
}
