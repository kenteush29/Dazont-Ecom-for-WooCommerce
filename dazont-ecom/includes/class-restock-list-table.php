<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists out-of-stock product-lines (simple products + variable parents),
 * one row per line. Variable parents expose an AJAX-loaded sub-table of their
 * out-of-stock variations.
 */
final class DZE_Restock_List_Table extends WP_List_Table {

	private const PER_PAGE_DEFAULT = 30;
	private const PER_PAGE_MAX     = 200;

	public const PER_PAGE_CHOICES = [ 20, 30, 50, 100, 200 ];

	/** term_id => how many out-of-stock lines of THIS list the category holds. */
	private array $cat_counts = [];

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
			'cb'       => '<input type="checkbox" />',
			'thumb'    => __( 'Image', 'dazont-ecom' ),
			'title'    => __( 'Product', 'dazont-ecom' ),
			'category' => __( 'Category', 'dazont-ecom' ),
			'price'    => __( 'Price', 'dazont-ecom' ),
			'oos'      => __( 'OOS variations', 'dazont-ecom' ),
			'sales'    => __( 'Total Sales', 'dazont-ecom' )
				. ' <span class="dashicons dashicons-editor-help" title="'
				. esc_attr__( 'Total units ordered across ALL orders (including refunded, cancelled and failed), never reduced by refunds, aggregated across all WPML languages. For a variable product this is the sum of ALL its variations, not only the out-of-stock ones.', 'dazont-ecom' )
				. '" style="font-size:16px;width:16px;height:16px;color:#999;cursor:help;"></span>',
			'restock'  => __( 'Restock', 'dazont-ecom' ),
		];
	}

	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" class="dze-cb" name="ids[]" value="%d" />',
			(int) $item['id']
		);
	}

	public function column_thumb( $item ): string {
		return DZE_Restock::thumb_html( get_post_thumbnail_id( $item['id'] ) );
	}

	public function column_restock( $item ): string {
		// Variable products: restock is done per-variation — this button opens
		// the variations panel so the user can pick which ones to restock.
		if ( $item['type'] === 'variable' ) {
			return sprintf(
				'<button type="button" class="button dze-restock-expand" data-parent="%d">%s</button>',
				(int) $item['id'],
				esc_html__( 'Choose…', 'dazont-ecom' )
			);
		}
		return sprintf(
			'<button type="button" class="button dze-restock-btn" data-id="%d">%s</button>',
			(int) $item['id'],
			esc_html__( 'Restock', 'dazont-ecom' )
		);
	}

	protected function get_sortable_columns(): array {
		return [
			'title' => [ 'title', false ],
			'oos'   => [ 'oos', true ],
			'sales' => [ 'sales', true ],
		];
	}

	public function no_items(): void {
		esc_html_e( 'No out-of-stock products. 🎉', 'dazont-ecom' );
	}

	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		$lines = DZE_Restock::get_line_index();
		$ids   = array_keys( $lines );

		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}

		// ---- What each category holds OF THIS LIST ----
		//
		// The menu said « (48) » beside a category — every product filed in it —
		// and choosing it showed three lines, or none. A filter says what it will
		// show before it is chosen: the out-of-stock lines, counted in one query,
		// before the list is narrowed.
		$this->cat_counts = self::count_by_category( $ids );

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
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		if ( $search !== '' && $ids ) {
			$ids = array_values( array_filter( $ids, static function ( $id ) use ( $search ) {
				return stripos( get_the_title( $id ), $search ) !== false;
			} ) );
		}

		// ---- Lightweight sortable rows ----
		$rows = [];
		foreach ( $ids as $id ) {
			$line   = $lines[ $id ];
			$rows[] = [
				'id'    => $id,
				'type'  => $line['type'],
				'oos'   => count( $line['oos'] ),
				'total' => $line['total'],
				'sales' => DZE_Restock::get_line_sales( $id ),
				'title' => get_the_title( $id ),
			];
		}

		// ---- Sort ----
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'sales';
		$order   = ( isset( $_GET['order'] ) && strtolower( (string) wp_unslash( $_GET['order'] ) ) === 'asc' ) ? 'asc' : 'desc';
		if ( ! isset( $_GET['orderby'] ) ) {
			$order = 'desc';
		}
		usort( $rows, static function ( $a, $b ) use ( $orderby, $order ) {
			if ( 'title' === $orderby ) {
				$cmp = strcasecmp( $a['title'], $b['title'] );
			} elseif ( 'oos' === $orderby ) {
				$cmp = $a['oos'] <=> $b['oos'];
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

	// ---- Row rendering ----

	public function single_row( $item ): void {
		printf(
			'<tr id="restock-line-%1$d" class="dze-row dze-%2$s" data-id="%1$d" data-type="%2$s">',
			(int) $item['id'],
			esc_attr( $item['type'] )
		);
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	public function column_title( $item ): string {
		$edit  = get_edit_post_link( $item['id'] );
		$title = $item['title'] !== '' ? $item['title'] : sprintf( '#%d', $item['id'] );
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
		return sprintf(
			'<button type="button" class="dze-toggle button-link" data-parent="%1$d" aria-expanded="false">▸</button> '
			. '<span class="dze-oos-badge">%2$d/%3$d</span>',
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

	protected function extra_tablenav( $which ): void {
		// Bulk restock button: shown both above and below the table.
		echo '<div class="alignleft actions dze-bulk-actions">';
		printf(
			'<button type="button" class="button button-primary dze-bulk-restock">%s</button> ',
			esc_html__( 'Bulk restock', 'dazont-ecom' )
		);
		echo '<span class="dze-bulk-status" style="margin-left:6px;color:#666;"></span>';
		echo '</div>';

		// Category / per-page filters: only above the table.
		if ( $which !== 'top' ) {
			return;
		}
		$current = isset( $_GET['product_cat'] ) ? sanitize_key( wp_unslash( $_GET['product_cat'] ) ) : '';
		// ONLY THE CATEGORIES THAT HOLD A LINE OF THIS LIST, with that count — and
		// the one already chosen, so the menu says what the screen shows.
		$terms = $this->cat_counts
			? get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'include' => array_map( 'intval', array_keys( $this->cat_counts ) ) ] )
			: [];
		if ( ! is_wp_error( $terms ) ) {
			// The one already chosen stays in the menu even when it holds nothing
			// any more — restocked since — or the menu would say « All categories »
			// over a filtered, empty list.
			if ( '' !== $current && ! in_array( $current, array_map( static fn( $t ) => (string) $t->slug, $terms ), true ) ) {
				$chosen = get_term_by( 'slug', $current, 'product_cat' );
				if ( $chosen instanceof WP_Term ) {
					$terms[] = $chosen;
				}
			}
			usort( $terms, static fn( $a, $b ) => strcasecmp( (string) $a->name, (string) $b->name ) );
		}

		echo '<div class="alignleft actions">';

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			echo '<select name="product_cat">';
			echo '<option value="">' . esc_html__( 'All categories', 'dazont-ecom' ) . '</option>';
			foreach ( $terms as $term ) {
				printf(
					'<option value="%s" %s>%s (%d)</option>',
					esc_attr( $term->slug ),
					selected( $current, $term->slug, false ),
					esc_html( html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ) ),
					(int) ( $this->cat_counts[ (int) $term->term_id ] ?? 0 )
				);
			}
			echo '</select>';
		}

		$current_pp = self::current_per_page();
		echo '<select name="per_page" style="margin-left:6px;">';
		foreach ( self::PER_PAGE_CHOICES as $choice ) {
			printf(
				'<option value="%1$d" %2$s>%1$d %3$s</option>',
				(int) $choice,
				selected( $current_pp, $choice, false ),
				esc_html__( '/ page', 'dazont-ecom' )
			);
		}
		echo '</select>';

		submit_button( __( 'Filter', 'dazont-ecom' ), '', 'filter_action', false );
		// « CLEAR FILTERS », comme la barre des traductions — seulement quand
		// une categorie est choisie.
		if ( '' !== $current ) {
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			echo ' <a class="dze-trd-clear" style="margin-left:6px;line-height:30px;" href="' . esc_url( add_query_arg( [ 'page' => $page ], admin_url( 'admin.php' ) ) ) . '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span>' . esc_html__( 'Clear filters', 'dazont-ecom' ) . '</a>';
		}
		echo '</div>';
	}
	/**
	 * How many of these lines each category holds, in one query.
	 *
	 * Read from the relationships table rather than term by term: a list of
	 * three hundred lines would otherwise cost three hundred reads.
	 *
	 * @param int[] $ids Product-line ids.
	 * @return array<int,int> term_id => lines.
	 */
	private static function count_by_category( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! $wpdb ) {
			return [];
		}
		$out = [];
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
			foreach ( (array) $wpdb->get_results( "SELECT tt.term_id AS tid, COUNT( DISTINCT tr.object_id ) AS n FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat' WHERE tr.object_id IN ( {$in} ) GROUP BY tt.term_id", ARRAY_A ) as $r ) {
				$out[ (int) $r['tid'] ] = (int) ( $out[ (int) $r['tid'] ] ?? 0 ) + (int) $r['n'];
			}
		}
		return array_filter( $out );
	}
}
