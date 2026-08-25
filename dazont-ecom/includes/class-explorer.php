<?php
defined( 'ABSPATH' ) || exit;

/**
 * Product Explorer.
 *
 * A full-screen, storefront-like admin tool for browsing the catalogue at a
 * glance: big product images with titles, a category rail (image + recursive
 * count) and filters down the left, image zoom and a variations popup. A focus
 * mode hides the WordPress chrome to use the whole screen. Built for the daily
 * job of spotting gaps and deciding what to add next; the SEO gap finder plugs
 * in here later.
 *
 * Products load over AJAX in pages (server-rendered cards) so a large catalogue
 * stays responsive.
 */
final class DZE_Explorer {

	public const MENU_SLUG = 'dazont-ecom-explorer';
	private const NONCE     = 'dze_explorer';
	private const PER_PAGE  = 30;
	/** Photographs shown on one card: the main image plus a few gallery ones. */
	private const CARD_SHOTS = 6;

	/** Hard ceiling on products fed into one sourcing report (protects the prompt). */
	private const REPORT_PRODUCTS_MAX = 2000;

	/**
	 * Default sourcing-report instructions — the editable heart of the report
	 * prompt. Admins can override them in Settings → Sourcing Assistant; the
	 * data blocks (category, products, gap queries) and the JSON output format
	 * stay fixed so parsing never breaks.
	 */
	public const DEFAULT_REPORT_GUIDANCE = <<<'EOT'
You are a senior product-sourcing assistant for an e-commerce catalogue. You are concrete, practical and exhaustive when asked to be. Never invent facts about the shop.

Use the sales figures: the best-sellers show proven demand in this shop. Favour opportunities that are adjacent or complementary to what already sells, and call out categories that sell well but are poorly covered by the catalogue.

CRITICAL: the product list above is COMPLETE and EXHAUSTIVE — it is the entire catalogue of this category. Before proposing ANY product (in source_list or ideas), check it against that list: never propose something already covered, including close variants and spelling differences (e.g. 'USMC t-shirt' duplicates 'USMC Tshirt'; 'Barrett .50 cal shirt' duplicates 'M82 Barrett Tshirt'). Only genuinely missing subjects qualify.
EOT;

	/** Effective report instructions: the admin override when set, else the default. */
	public static function report_guidance(): string {
		if ( class_exists( 'DZE_Marketing_Ai' ) ) {
			$v = (string) ( DZE_Marketing_Ai::get_settings()['report_guidance'] ?? '' );
			if ( '' !== trim( $v ) ) {
				return $v;
			}
		}
		return self::DEFAULT_REPORT_GUIDANCE;
	}

	/** Term meta: unix timestamp of the last manual "novelty search" for a category. */
	public const META_RESEARCHED = '_dze_researched';

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
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_dze_explorer_products',        [ $this, 'ajax_products' ] );
		add_action( 'wp_ajax_dze_explorer_variations',      [ $this, 'ajax_variations' ] );
		add_action( 'wp_ajax_dze_explorer_mark_researched', [ $this, 'ajax_mark_researched' ] );
		add_action( 'wp_ajax_dze_explorer_ai_insights',     [ $this, 'ajax_ai_insights' ] );
		add_action( 'wp_ajax_dze_explorer_all_opps',        [ $this, 'ajax_all_opps' ] );
		add_action( 'wp_ajax_dze_explorer_kw_stats',        [ $this, 'ajax_kw_stats' ] );
		// "+ Add product" from a category overlay: pre-tick that category on the new-product screen.
		add_action( 'admin_footer-post-new.php', static function () {
			$cat = isset( $_GET['dze_cat'] ) ? absint( $_GET['dze_cat'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- UI convenience only.
			if ( $cat && 'product' === ( $_GET['post_type'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				printf( '<script>jQuery(function($){$("#in-product_cat-%d").prop("checked",true);});</script>', $cat );
			}
		} );
	}

	public function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Sourcing Assistant', 'dazont-ecom' ),
			__( 'Sourcing Assistant', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	private function is_explorer_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && strpos( (string) $screen->id, self::MENU_SLUG ) !== false;
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}
		// This is a focused, full-screen tool — strip other plugins' admin
		// notices so nothing steals space or attention.
		add_action( 'in_admin_header', static function () {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
			remove_all_actions( 'user_admin_notices' );
		}, 999 );

		wp_enqueue_style( 'dze-explorer', DZE_URL . 'admin/css/explorer.css', [], DZE_VERSION );
		// The image viewer, exactly the one the rest of the plugin uses: a
		// photograph is judged the same way wherever it is met.
		wp_enqueue_style( 'dze-zoom', DZE_URL . 'admin/css/zoom.css', [], DZE_VERSION );
		wp_enqueue_script( 'dze-hzoom', DZE_URL . 'admin/js/hzoom.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-hzoom', 'dzeZoomI18n', [
			'zoom'  => __( 'See this image full size', 'dazont-ecom' ),
			'close' => __( 'Close', 'dazont-ecom' ),
			'prev'  => __( 'Previous image', 'dazont-ecom' ),
			'next'  => __( 'Next image', 'dazont-ecom' ),
		] );
		wp_enqueue_script( 'dze-explorer', DZE_URL . 'admin/js/explorer.js', [ 'jquery', 'dze-hzoom' ], DZE_VERSION, true );
		wp_enqueue_script( 'dze-keywords', DZE_URL . 'admin/js/keywords.js', [ 'jquery', 'dze-explorer' ], DZE_VERSION, true );
		wp_localize_script( 'dze-keywords', 'dzeKw', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => DZE_Keywords::nonce(),
			'i18n'    => [
				'keywords'   => __( 'Keywords', 'dazont-ecom' ),
				'products'   => __( 'Products', 'dazont-ecom' ),
				'loading'    => __( 'Loading…', 'dazont-ecom' ),
				'error'      => __( 'Something went wrong.', 'dazont-ecom' ),
				'empty'      => __( 'No keywords yet. Import a SEMrush CSV export for this category.', 'dazont-ecom' ),
				'noMatch'    => __( 'No keywords match these filters.', 'dazont-ecom' ),
				'imported'   => __( 'keywords imported.', 'dazont-ecom' ),
				'confirmImp' => __( 'Import will REPLACE the current keyword set of this category. Continue?', 'dazont-ecom' ),
				'confirmDel' => __( 'Delete the whole keyword set of this category?', 'dazont-ecom' ),
				'pickKw'     => __( 'Pick which column holds the keyword.', 'dazont-ecom' ),
				'mapTitle'   => __( 'Confirm column mapping', 'dazont-ecom' ),
				'mapHelp'    => __( 'Check that each field points to the right CSV column, then import.', 'dazont-ecom' ),
				'import'     => __( 'Import (replaces existing)', 'dazont-ecom' ),
				'rowsFound'  => __( 'rows found', 'dazont-ecom' ),
				'ignore'     => __( '— ignore —', 'dazont-ecom' ),
				'fKeyword'   => __( 'Keyword', 'dazont-ecom' ),
				'fVolume'    => __( 'Volume', 'dazont-ecom' ),
				'fKd'        => __( 'KD', 'dazont-ecom' ),
				'fCpc'       => __( 'CPC', 'dazont-ecom' ),
				'fIntent'    => __( 'Intent', 'dazont-ecom' ),
				'fStatus'    => __( 'Status', 'dazont-ecom' ),
				'stNone'     => __( '— none —', 'dazont-ecom' ),
				'stCovered'  => __( 'Covered', 'dazont-ecom' ),
				'stGap'      => __( 'Gap', 'dazont-ecom' ),
				'stUncertain'=> __( 'Uncertain', 'dazont-ecom' ),
				'stIgnored'  => __( 'Ignored', 'dazont-ecom' ),
				'anyStatus'  => __( 'Any status', 'dazont-ecom' ),
				'anyIntent'  => __( 'Any intent', 'dazont-ecom' ),
				'bulk'       => __( 'Set selected to…', 'dazont-ecom' ),
				'apply'      => __( 'Apply', 'dazont-ecom' ),
				'mVolume'    => __( 'Volume', 'dazont-ecom' ),
				'mWcpc'      => __( 'Weighted CPC', 'dazont-ecom' ),
				'mAvgKd'     => __( 'Avg KD', 'dazont-ecom' ),
				'mCompletion'=> __( 'Completion', 'dazont-ecom' ),
				'mGaps'      => __( 'gaps', 'dazont-ecom' ),
				'mAnalysed'  => __( 'Analysed', 'dazont-ecom' ),
				'mIgnored'   => __( 'ignored', 'dazont-ecom' ),
				'showMore'   => __( 'Show more', 'dazont-ecom' ),
				'stVariation'=> __( 'Variation only', 'dazont-ecom' ),
				'analysing'  => __( 'Analysing…', 'dazont-ecom' ),
				'remaining'  => __( 'remaining', 'dazont-ecom' ),
				'analyseDone'=> __( 'Analysis finished.', 'dazont-ecom' ),
				'titlesAdded'=> __( 'product titles added as covered long-tail keywords.', 'dazont-ecom' ),
				'kwCovered'  => __( 'Keywords covered by', 'dazont-ecom' ),
				'noKw'       => __( 'No covered keywords.', 'dazont-ecom' ),
				'stToSource' => __( 'To source 🛒', 'dazont-ecom' ),
				'updated'    => __( 'updated', 'dazont-ecom' ),
				'noOpps'     => __( 'No open opportunities. Import keyword sets and run the analysis.', 'dazont-ecom' ),
				'generatedOn'=> __( 'Report saved on', 'dazont-ecom' ),
				'regen'      => __( '↻ Regenerate', 'dazont-ecom' ),
				'reanalyse'  => __( 'Re-analyse all keywords from scratch (clears current verdicts)?', 'dazont-ecom' ),
				'opps'       => __( 'opportunities', 'dazont-ecom' ),
				'mAnalysed'  => __( 'Analysed', 'dazont-ecom' ),
				'confirmDelAdded' => __( 'Remove the auto-added product-title keywords?', 'dazont-ecom' ),
				'oppProduct' => __( 'Product to source', 'dazont-ecom' ),
				'oppQueries' => __( 'Queries covered', 'dazont-ecom' ),
				'oppCat'     => __( 'Category', 'dazont-ecom' ),
				'oppOpen'    => __( 'Open', 'dazont-ecom' ),
				'allOppsCompiled' => __( 'Compiled from %s category report(s).', 'dazont-ecom' ),
				'allOppsEmpty'    => __( 'No sourcing report generated yet. Open a category and generate its report, then it will be compiled here.', 'dazont-ecom' ),
				'missingReportsTitle' => __( 'These categories have opportunities but no sourcing report yet', 'dazont-ecom' ),
				'missingReportsHelp'  => __( 'Open each one and generate its report to add its opportunities to this list.', 'dazont-ecom' ),
				'gapsWord'   => __( 'opportunities', 'dazont-ecom' ),
				'genReport'  => __( 'Generate report', 'dazont-ecom' ),
				'bulkAllAnalysed' => __( 'All imported keywords are already analysed. Reset a single category from its own page if you want to re-run it.', 'dazont-ecom' ),
				'bulkNoFile' => __( 'Note: some categories have products but no keyword file imported yet. Import their SEMrush export first if you want them included.', 'dazont-ecom' ),
				'analysedWord' => __( 'analysed', 'dazont-ecom' ),
				'report'     => __( 'Report', 'dazont-ecom' ),
				'reportDone' => __( 'Report ✓', 'dazont-ecom' ),
			],
		] );
		wp_localize_script( 'dze-explorer', 'dzeExplorer', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'loading'    => __( 'Loading…', 'dazont-ecom' ),
				'loadMore'   => __( 'Load more', 'dazont-ecom' ),
				'noResults'  => __( 'No products match these filters.', 'dazont-ecom' ),
				'none'       => __( 'No variation images.', 'dazont-ecom' ),
				'error'      => __( 'Something went wrong.', 'dazont-ecom' ),
				'variations' => __( 'Variations', 'dazont-ecom' ),
				'focus'      => __( 'Focus mode', 'dazont-ecom' ),
				'exit'       => __( 'Exit focus', 'dazont-ecom' ),
				'all'        => __( 'All products', 'dazont-ecom' ),
				'units'      => __( 'sold', 'dazont-ecom' ),
				'never'      => __( 'never', 'dazont-ecom' ),
				'justNow'    => __( 'just now', 'dazont-ecom' ),
				'generatedOn'=> __( 'Report saved on', 'dazont-ecom' ),
				'regen'      => __( '↻ Regenerate', 'dazont-ecom' ),
				'opportunities' => __( 'opportunities', 'dazont-ecom' ),
				'lastSearchShort' => __( 'Last search', 'dazont-ecom' ),
				'sourcingOpps' => __( 'sourcing opportunities', 'dazont-ecom' ),
				'productToSource' => __( 'Product to source', 'dazont-ecom' ),
				'queriesCovered' => __( 'Queries covered', 'dazont-ecom' ),
				'fVolume'    => __( 'Volume', 'dazont-ecom' ),
				'ideasBeyond' => __( 'Ideas beyond the keyword data', 'dazont-ecom' ),
				'sold'       => __( 'sold', 'dazont-ecom' ),
				'products'   => __( 'products', 'dazont-ecom' ),
				'noCats'     => __( 'No categories match.', 'dazont-ecom' ),
				'aiThinking' => __( 'Analysing this category…', 'dazont-ecom' ),
				'sortBy'     => __( 'Sort by', 'dazont-ecom' ),
				'byId'       => __( 'ID', 'dazont-ecom' ),
				'confirmMark'  => __( 'Mark this category as searched today?', 'dazont-ecom' ),
				'expandAll'    => __( 'Expand all', 'dazont-ecom' ),
				'collapseAll'  => __( 'Collapse all', 'dazont-ecom' ),
				'noReportYet'  => __( 'No report generated yet for this category.', 'dazont-ecom' ),
				'genReport'    => __( 'Generate report', 'dazont-ecom' ),
				'close'        => __( 'Close', 'dazont-ecom' ),
				'reportDone'   => __( 'Report ✓', 'dazont-ecom' ),
				'aiWait'       => __( 'Writing the sourcing report — this can take a minute or two. You can keep browsing; it will appear here as soon as it is ready.', 'dazont-ecom' ),
				'basedOn'      => __( 'Report based on %s products (all of them).', 'dazont-ecom' ),
				'dupesRemoved' => __( 'suggestions removed (already in the catalogue)', 'dazont-ecom' ),
			],
		] );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$categories = $this->category_tree();
		require DZE_DIR . 'admin/views/explorer-page.php';
	}

	// =========================================================================
	// Category rail
	// =========================================================================

	/**
	 * Nested product-category tree with image, recursive product count and
	 * recursive sales (units + revenue) so the rail can be re-ordered by what
	 * actually sells.
	 */
	private function category_tree(): array {
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}
		$by_id    = [];
		$children = [];
		$parent   = [];
		foreach ( $terms as $t ) {
			$by_id[ $t->term_id ]     = $t;
			$children[ $t->parent ][] = $t->term_id;
			$parent[ $t->term_id ]    = (int) $t->parent;
		}
		$sales = $this->category_sales( $parent );
		$kwc   = class_exists( 'DZE_Keywords' ) ? DZE_Keywords::counts_by_term() : [];

		$rec_count = function ( int $id ) use ( &$rec_count, $children, $by_id ): int {
			$c = (int) ( $by_id[ $id ]->count ?? 0 );
			foreach ( $children[ $id ] ?? [] as $cid ) {
				$c += $rec_count( $cid );
			}
			return $c;
		};
		// Keyword figures rolled up over the whole subtree: container/parent
		// categories rarely get their own CSV, so they aggregate their children.
		$rec_kw = function ( int $id ) use ( &$rec_kw, $children, $kwc ): array {
			$k = $kwc[ $id ] ?? [];
			$sum = [
				'kw'       => (int) ( $k['kw'] ?? 0 ),
				'gaps'     => (int) ( $k['gaps'] ?? 0 ),
				'pending'  => (int) ( $k['pending'] ?? 0 ),
				'analysed' => (int) ( $k['analysed'] ?? 0 ),
			];
			foreach ( $children[ $id ] ?? [] as $cid ) {
				$c = $rec_kw( $cid );
				$sum['kw']       += $c['kw'];
				$sum['gaps']     += $c['gaps'];
				$sum['pending']  += $c['pending'];
				$sum['analysed'] += $c['analysed'];
			}
			return $sum;
		};
		$build = function ( int $parent_id ) use ( &$build, $children, $by_id, $rec_count, $rec_kw, $sales, $kwc ): array {
			$out = [];
			foreach ( $children[ $parent_id ] ?? [] as $cid ) {
				$t      = $by_id[ $cid ];
				$img_id = (int) get_term_meta( $cid, 'thumbnail_id', true );
				$out[]  = [
					'id'                => $cid,
					'name'              => $t->name,
					'count'             => $rec_count( $cid ),
					'count_direct'      => (int) ( $t->count ?? 0 ),
					'sales_qty'         => (float) ( $sales[ $cid ]['qty'] ?? 0 ),
					'sales_rev'         => (float) ( $sales[ $cid ]['rev'] ?? 0 ),
					'sales_qty_direct'  => (float) ( $sales[ $cid ]['qty_direct'] ?? 0 ),
					'sales_rev_direct'  => (float) ( $sales[ $cid ]['rev_direct'] ?? 0 ),
					'researched'        => (int) get_term_meta( $cid, self::META_RESEARCHED, true ),
					'kw'                => $rec_kw( $cid )['kw'],
					'gaps'              => $rec_kw( $cid )['gaps'],
					'pending'           => $rec_kw( $cid )['pending'],
					'analysed'          => $rec_kw( $cid )['analysed'],
					'own_kw'            => (int) ( $kwc[ $cid ]['kw'] ?? 0 ),
					'has_report'        => (bool) get_term_meta( $cid, '_dze_insights', true ),
					'image'             => $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
					'children'          => $build( $cid ),
				];
			}
			return $out;
		};
		return $build( 0 );
	}

	/**
	 * Rolled-up keyword stats for every product category, in the exact same
	 * shape the category list renders (subtree aggregation for kw/gaps/analysed,
	 * own_kw = the category's own set, has_report = a saved sourcing report).
	 * Used by the live-refresh endpoint so rows update after an analysis without
	 * a full page reload.
	 *
	 * @return array<int,array{kw:int,gaps:int,pending:int,analysed:int,own_kw:int,has_report:bool}>
	 */
	private function kw_stats_map(): array {
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}
		$children = [];
		foreach ( $terms as $t ) {
			$children[ (int) $t->parent ][] = (int) $t->term_id;
		}
		$kwc = class_exists( 'DZE_Keywords' ) ? DZE_Keywords::counts_by_term() : [];
		$rec_kw = function ( int $id ) use ( &$rec_kw, $children, $kwc ): array {
			$k   = $kwc[ $id ] ?? [];
			$sum = [
				'kw'       => (int) ( $k['kw'] ?? 0 ),
				'gaps'     => (int) ( $k['gaps'] ?? 0 ),
				'pending'  => (int) ( $k['pending'] ?? 0 ),
				'analysed' => (int) ( $k['analysed'] ?? 0 ),
			];
			foreach ( $children[ $id ] ?? [] as $cid ) {
				$c = $rec_kw( $cid );
				$sum['kw']       += $c['kw'];
				$sum['gaps']     += $c['gaps'];
				$sum['pending']  += $c['pending'];
				$sum['analysed'] += $c['analysed'];
			}
			return $sum;
		};
		$out = [];
		foreach ( $terms as $t ) {
			$id         = (int) $t->term_id;
			$out[ $id ] = $rec_kw( $id ) + [
				'own_kw'     => (int) ( $kwc[ $id ]['kw'] ?? 0 ),
				'has_report' => (bool) get_term_meta( $id, '_dze_insights', true ),
			];
		}
		return $out;
	}

	/** Live per-category keyword stats (for refreshing rows after an analysis). */
	public function ajax_kw_stats(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		wp_send_json_success( [ 'stats' => $this->kw_stats_map() ] );
	}

	/**
	 * Per-category sales from WooCommerce Analytics' order-product lookup table,
	 * in two flavours:
	 *   - qty / rev              : rolled up over the whole subtree (a product is
	 *                              counted once per ancestor category).
	 *   - qty_direct / rev_direct: only products directly assigned to that exact
	 *                              category (no rollup) — to see precisely what a
	 *                              single category sells, independently of children.
	 * Cached for a few hours because this drives ordering, not live figures.
	 *
	 * @param array<int,int> $parent term_id => parent term_id map.
	 * @return array<int,array{qty:float,rev:float,qty_direct:float,rev_direct:float}>
	 */
	/**
	 * Lifetime units sold per product (parent id; variations roll up) for the
	 * given product IDs, from WooCommerce Analytics' lookup table. Empty when the
	 * table is missing. Used to annotate the sourcing report with real demand.
	 *
	 * @param int[] $ids
	 * @return array<int,int> product_id => units sold
	 */
	private function product_sales_map( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return [];
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT product_id, SUM(product_qty) AS qty FROM {$table} WHERE product_id IN ($ph) GROUP BY product_id", $ids ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int placeholders + own table.
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['product_id'] ] = (int) round( (float) $r['qty'] );
		}
		return $out;
	}

	private function category_sales( array $parent ): array {
		$cached = get_transient( 'dze_x_cat_sales_v2' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}

		// Per-product totals (product_id is the parent product; variations roll up).
		$rows = $wpdb->get_results(
			"SELECT product_id, SUM(product_qty) AS qty, SUM(product_net_revenue) AS rev
			 FROM {$table} GROUP BY product_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			set_transient( 'dze_x_cat_sales_v2', [], 3 * HOUR_IN_SECONDS );
			return [];
		}
		$prod = [];
		foreach ( $rows as $r ) {
			$prod[ (int) $r['product_id'] ] = [ 'qty' => (float) $r['qty'], 'rev' => (float) $r['rev'] ];
		}

		// Product → assigned product_cat term ids.
		$pids         = array_keys( $prod );
		$placeholders = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
		$rel = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id, tt.term_id
				 FROM {$wpdb->term_relationships} tr
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE tt.taxonomy = 'product_cat' AND tr.object_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from ints.
				$pids
			),
			ARRAY_A
		);
		$prod_terms = [];
		foreach ( (array) $rel as $r ) {
			$prod_terms[ (int) $r['object_id'] ][] = (int) $r['term_id'];
		}

		$ancestors = static function ( int $id ) use ( $parent ): array {
			$chain = [];
			$seen  = [];
			while ( isset( $parent[ $id ] ) && $parent[ $id ] > 0 && empty( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$id          = $parent[ $id ];
				$chain[]     = $id;
			}
			return $chain;
		};

		$out = [];
		$bump = static function ( array &$out, int $tid, array $s, bool $direct ): void {
			if ( ! isset( $out[ $tid ] ) ) {
				$out[ $tid ] = [ 'qty' => 0.0, 'rev' => 0.0, 'qty_direct' => 0.0, 'rev_direct' => 0.0 ];
			}
			if ( $direct ) {
				$out[ $tid ]['qty_direct'] += $s['qty'];
				$out[ $tid ]['rev_direct'] += $s['rev'];
			} else {
				$out[ $tid ]['qty'] += $s['qty'];
				$out[ $tid ]['rev'] += $s['rev'];
			}
		};
		foreach ( $prod as $pid => $s ) {
			$tids = array_unique( $prod_terms[ $pid ] ?? [] );
			// Direct: only the categories the product is actually filed under.
			foreach ( $tids as $tid ) {
				$bump( $out, (int) $tid, $s, true );
			}
			// Rolled up: those categories plus every ancestor, once each.
			$targets = [];
			foreach ( $tids as $tid ) {
				$targets[ $tid ] = true;
				foreach ( $ancestors( (int) $tid ) as $a ) {
					$targets[ $a ] = true;
				}
			}
			foreach ( array_keys( $targets ) as $tid ) {
				$bump( $out, (int) $tid, $s, false );
			}
		}
		set_transient( 'dze_x_cat_sales_v2', $out, 3 * HOUR_IN_SECONDS );
		return $out;
	}

	// =========================================================================
	// AJAX: products page
	// =========================================================================

	public function ajax_products(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}

		$paged  = max( 1, (int) ( $_POST['paged'] ?? 1 ) );
		$search = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		$cat    = (int) ( $_POST['cat'] ?? 0 );
		$sort   = isset( $_POST['sort'] ) ? sanitize_key( wp_unslash( $_POST['sort'] ) ) : 'date_desc';
		$stock  = isset( $_POST['stock'] ) ? sanitize_key( wp_unslash( $_POST['stock'] ) ) : '';
		$attrs  = ( isset( $_POST['attr'] ) && is_array( $_POST['attr'] ) ) ? wp_unslash( $_POST['attr'] ) : [];

		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
		];
		switch ( $sort ) {
			case 'title_asc':  $args['orderby'] = 'title'; $args['order'] = 'ASC'; break;
			case 'title_desc': $args['orderby'] = 'title'; $args['order'] = 'DESC'; break;
			case 'date_asc':   $args['orderby'] = 'date';  $args['order'] = 'ASC'; break;
			default:           $args['orderby'] = 'date';  $args['order'] = 'DESC';
		}
		if ( $search !== '' ) {
			$args['s'] = $search;
		}
		$tax = [];
		if ( $cat > 0 ) {
			$tax[] = [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat, 'include_children' => true ];
		}
		foreach ( $attrs as $t => $slug ) {
			$t    = sanitize_key( $t );
			$slug = sanitize_text_field( $slug );
			if ( $slug !== '' && taxonomy_exists( $t ) ) {
				$tax[] = [ 'taxonomy' => $t, 'field' => 'slug', 'terms' => $slug ];
			}
		}
		if ( $tax ) {
			$args['tax_query'] = $tax;
		}
		if ( 'in' === $stock ) {
			$args['meta_query'] = [ [ 'key' => '_stock_status', 'value' => 'instock' ] ];
		} elseif ( 'out' === $stock ) {
			$args['meta_query'] = [ [ 'key' => '_stock_status', 'value' => 'outofstock' ] ];
		}

		$query = new WP_Query( $args );
		$kwcov = ( $cat > 0 && class_exists( 'DZE_Keywords' ) ) ? DZE_Keywords::coverage_counts( $cat ) : [];
		$html  = '';
		// Every card carries its gallery, so hovering one shows the product
		// from its other angles without a request per card. The attachments of
		// the WHOLE page are read in one go first: without this, twenty-four
		// cards asking for five image URLs each is a hundred and twenty
		// queries for one screen.
		$products = [];
		$shot_ids = [];
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$products[] = $product;
			$shot_ids   = array_merge( $shot_ids, $this->card_shot_ids( $product ) );
		}
		$shot_ids = array_values( array_unique( array_filter( $shot_ids ) ) );
		if ( $shot_ids ) {
			_prime_post_caches( $shot_ids, false, true );
		}
		foreach ( $products as $product ) {
			$html .= $this->card_html( $product, (int) ( $kwcov[ $product->get_id() ] ?? 0 ), $cat );
		}
		wp_send_json_success( [
			'html'    => $html,
			'found'   => (int) $query->found_posts,
			'hasMore' => $paged < (int) $query->max_num_pages,
		] );
	}

	/**
	 * The photographs a card shows: the main image, then the gallery.
	 *
	 * Capped, because a card is a card: past half a dozen angles nobody is
	 * still hovering, and every extra id is an attachment to read.
	 *
	 * @return int[]
	 */
	private function card_shot_ids( \WC_Product $product ): array {
		$ids = [ (int) $product->get_image_id() ];
		foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
			$ids[] = (int) $gid;
		}
		return array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, self::CARD_SHOTS );
	}

	private function card_html( \WC_Product $product, int $kwcov = 0, int $cat = 0 ): string {
		$img_id    = (int) $product->get_image_id();
		$thumb     = $img_id ? wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );
		$full      = $img_id ? wp_get_attachment_image_url( $img_id, 'full' ) : $thumb;
		// The whole set travels with the card: the strip the cursor walks, and
		// the list the viewer opens on. One JSON attribute rather than a dozen
		// hidden <img> the browser would download for nothing.
		$shots = [];
		foreach ( $this->card_shot_ids( $product ) as $sid ) {
			$s_thumb = wp_get_attachment_image_url( $sid, 'woocommerce_thumbnail' );
			$s_full  = wp_get_attachment_image_url( $sid, 'full' );
			if ( ! $s_thumb && ! $s_full ) {
				continue;
			}
			$shots[] = [ 't' => (string) ( $s_thumb ?: $s_full ), 'f' => (string) ( $s_full ?: $s_thumb ) ];
		}
		$is_var    = $product->is_type( 'variable' );
		$var_count = $is_var ? count( $product->get_children() ) : 0;
		$edit      = get_edit_post_link( $product->get_id() );
		$view      = get_permalink( $product->get_id() );
		$sales     = class_exists( 'DZE_Restock' ) ? DZE_Restock::get_line_sales( $product->get_id() ) : 0;

		ob_start();
		?>
		<div class="dze-x-card">
			<div class="dze-x-thumb dze-thumb-wrap<?php echo count( $shots ) > 1 ? ' has-shots' : ''; ?>"
				data-shots="<?php echo esc_attr( (string) wp_json_encode( $shots ) ); ?>">
				<img class="dze-thumb dze-x-img" src="<?php echo esc_url( $thumb ); ?>" data-full="<?php echo esc_url( $full ); ?>" alt=""
					title="<?php echo esc_attr( count( $shots ) > 1
						? __( 'Move across the photograph to see the others — click to open them full size.', 'dazont-ecom' )
						: __( 'Click to open this photograph full size.', 'dazont-ecom' ) ); ?>" loading="lazy" />
				<?php if ( count( $shots ) > 1 ) : ?>
					<!-- One mark per photograph: how many there are, and which one
					     the cursor is on. -->
					<span class="dze-x-dots"><?php foreach ( array_keys( $shots ) as $di ) : ?>
						<i class="<?php echo 0 === $di ? 'is-on' : ''; ?>"></i>
					<?php endforeach; ?></span>
				<?php endif; ?>
			</div>
			<div class="dze-x-name" title="<?php echo esc_attr( $product->get_name() ); ?>"><?php echo esc_html( $product->get_name() ); ?></div>
			<div class="dze-x-card-more">
				<div class="dze-x-meta">
					<span><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
					<span class="dze-x-id">#<?php echo (int) $product->get_id(); ?></span>
				</div>
				<div class="dze-x-sales"><?php
					/* translators: %s: number of units sold */
					echo esc_html( sprintf( _n( '%s sold', '%s sold', $sales, 'dazont-ecom' ), number_format_i18n( $sales ) ) );
					if ( $kwcov > 0 ) : ?>
						<button type="button" class="dze-x-kwprod" data-product="<?php echo (int) $product->get_id(); ?>" data-cat="<?php echo (int) $cat; ?>"><?php
							/* translators: %s: number of covered keywords */
							echo esc_html( sprintf( __( '🔑 %s kw covered', 'dazont-ecom' ), number_format_i18n( $kwcov ) ) );
						?></button>
					<?php endif; ?></div>
				<div class="dze-x-date"><?php
					/* translators: %s: product publication date */
					printf( esc_html__( 'Published: %s', 'dazont-ecom' ), esc_html( get_the_date( '', $product->get_id() ) ) );
				?></div>
				<div class="dze-x-actions">
					<?php if ( $edit ) : ?><a class="button button-small" href="<?php echo esc_url( $edit ); ?>" target="_blank" onclick="event.stopPropagation();"><?php esc_html_e( 'Edit', 'dazont-ecom' ); ?></a><?php endif; ?>
					<?php if ( $view ) : ?><a class="button button-small" href="<?php echo esc_url( $view ); ?>" target="_blank" onclick="event.stopPropagation();"><?php esc_html_e( 'View', 'dazont-ecom' ); ?></a><?php endif; ?>
					<?php if ( $is_var && $var_count > 0 ) : ?>
						<button type="button" class="button button-small dze-x-vars" data-product="<?php echo (int) $product->get_id(); ?>"><?php
							/* translators: %d: number of variations */
							echo esc_html( sprintf( __( 'Variations (%d)', 'dazont-ecom' ), $var_count ) );
						?></button>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// =========================================================================
	// AJAX: variation images (same shape as the Gallery module)
	// =========================================================================

	public function ajax_variations(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$product_id = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not a variable product.', 'dazont-ecom' ) ] );
		}
		$out   = [];
		$attrs = []; // attribute label => true, to expose the available sort keys.
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof \WC_Product ) {
				continue;
			}
			$img_id = (int) $variation->get_image_id() ?: (int) get_post_thumbnail_id( $product_id );
			$vattrs = [];
			foreach ( $variation->get_attributes() as $tax => $value ) {
				if ( '' === $value ) {
					continue;
				}
				$label = wc_attribute_label( $tax, $product );
				$term  = taxonomy_exists( $tax ) ? get_term_by( 'slug', $value, $tax ) : null;
				$vattrs[ $label ] = $term && ! is_wp_error( $term ) ? $term->name : (string) $value;
				$attrs[ $label ]  = true;
			}
			$out[] = [
				'id'    => (int) $variation_id,
				'title' => wp_strip_all_tags( wc_get_formatted_variation( $variation, true, true, false ) ),
				'thumb' => $img_id ? ( wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) ?: wp_get_attachment_image_url( $img_id, 'thumbnail' ) ) : '',
				'full'  => $img_id ? wp_get_attachment_image_url( $img_id, 'full' ) : '',
				'attrs' => $vattrs,
			];
		}
		usort( $out, static fn( $a, $b ) => $a['id'] <=> $b['id'] );
		wp_send_json_success( [ 'images' => $out, 'attributes' => array_keys( $attrs ) ] );
	}

	// =========================================================================
	// AJAX: mark a category as "novelty-searched" today
	// =========================================================================

	public function ajax_mark_researched(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$cat = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		if ( ! $cat || ! term_exists( $cat, 'product_cat' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown category.', 'dazont-ecom' ) ] );
		}
		$now = time();
		update_term_meta( $cat, self::META_RESEARCHED, $now );
		wp_send_json_success( [
			'ts'   => $now,
			'date' => date_i18n( get_option( 'date_format' ), $now ),
		] );
	}

	// =========================================================================
	// AJAX: short AI recap of the selected category + sourcing hints
	// =========================================================================

	public function ajax_ai_insights(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$cat  = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		$term = $cat ? get_term( $cat, 'product_cat' ) : null;
		if ( ! $term instanceof WP_Term ) {
			wp_send_json_error( [ 'message' => __( 'Unknown category.', 'dazont-ecom' ) ] );
		}
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'get';

		// Saved report first: reopening costs nothing.
		if ( 'get' === $mode ) {
			$saved = get_term_meta( $cat, '_dze_insights', true );
			if ( is_array( $saved ) && ! empty( $saved['data'] ) ) {
				wp_send_json_success( [ 'data' => $saved['data'], 'ts' => (int) ( $saved['ts'] ?? 0 ), 'saved' => true ] );
			}
			wp_send_json_success( [ 'saved' => false ] );
		}
		if ( ! class_exists( 'DZE_Marketing_Ai' ) || DZE_Marketing_Ai::api_key() === '' ) {
			wp_send_json_error( [ 'message' => __( 'Add your Anthropic API key under Settings first.', 'dazont-ecom' ) ] );
		}

		// Gap list from the keyword set (top by volume), for the exhaustive part.
		// Container categories rarely have their own set, so aggregate this
		// category AND all its descendants — the report rolls up too.
		$gaps = [];
		if ( class_exists( 'DZE_Keywords' ) ) {
			global $wpdb;
			$ktable = DZE_Keywords::table();
			$tids   = array_map( 'intval', (array) get_term_children( $cat, 'product_cat' ) );
			$tids[] = $cat;
			$tids   = array_values( array_unique( array_filter( $tids ) ) );
			$ph     = implode( ',', array_fill( 0, count( $tids ), '%d' ) );
			$gaps   = $wpdb->get_results(
				$wpdb->prepare( "SELECT keyword, volume FROM {$ktable} WHERE term_id IN ($ph) AND status IN ('gap','to_source') ORDER BY volume DESC LIMIT 150", $tids ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int placeholders.
				ARRAY_A
			);
		}

		if ( 'estimate' === $mode ) {
			$cq     = new WP_Query( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat, 'include_children' => true ] ],
			] );
			$pcount = min( (int) $cq->found_posts, self::REPORT_PRODUCTS_MAX );
			$in_tok = 1500 + count( $gaps ) * 12 + $pcount * 8;
			$cost   = ( $in_tok * 15 + 2500 * 75 ) / 1000000; // main-model pricing, upper bound.
			wp_send_json_success( [
				'message' => sprintf(
					/* translators: 1: gap count, 2: estimated cost */
					__( "AI insights report\n\n%1\$d gap keywords will be analysed and grouped, plus a qualitative recap of the category.\nEstimated cost: up to ~$%2\$s.\n\nGenerate?", 'dazont-ecom' ),
					count( $gaps ),
					number_format_i18n( max( 0.01, $cost ), 2 )
				),
			] );
		}

		// Category path (ancestors → current).
		$names = [];
		foreach ( array_reverse( get_ancestors( $cat, 'product_cat', 'taxonomy' ) ) as $aid ) {
			$anc = get_term( (int) $aid, 'product_cat' );
			if ( $anc instanceof WP_Term ) {
				$names[] = $anc->name;
			}
		}
		$names[] = $term->name;
		$path    = implode( ' > ', $names );

		// EVERY current product in the category (incl. sub-categories), annotated
		// with real lifetime units sold and ordered best-sellers first, so the AI
		// sees the whole catalogue and what actually performs — not a sample.
		$prod_ids = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => self::REPORT_PRODUCTS_MAX,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat, 'include_children' => true ] ],
		] );
		$prod_ids       = array_map( 'intval', (array) $prod_ids );
		$total_products = count( $prod_ids );
		$sales_map      = $this->product_sales_map( $prod_ids );
		usort( $prod_ids, static fn( $a, $b ) => ( $sales_map[ $b ] ?? 0 ) <=> ( $sales_map[ $a ] ?? 0 ) );
		$titles     = [];
		$raw_titles = [];
		foreach ( $prod_ids as $pid ) {
			$sold         = (int) ( $sales_map[ $pid ] ?? 0 );
			$name         = wp_strip_all_tags( get_the_title( $pid ) );
			$raw_titles[] = $name;
			$titles[]     = '- ' . $name . ' (' . $sold . ' sold)';
		}

		// The persona + analysis guidance is admin-editable (Sourcing Assistant tab).
		// The shop says what it is, once, for every module that writes for it:
		// a sourcing report for a tactical shop is not a sourcing report for a
		// kitchenware shop, and this report used to know nothing about that.
		$system = self::report_guidance();
		$shop   = class_exists( 'DZE_Content' ) ? trim( DZE_Content::store_context() ) : '';
		if ( '' !== $shop ) {
			$system .= "\n\nTHE SHOP THIS REPORT IS FOR:\n" . $shop;
		}
		$user = "Product category: {$path}\n\n";
		$user .= $titles
			? ( 'ALL ' . $total_products . " products currently in this category, with lifetime units sold, best-sellers first:\n"
				. implode( "\n", $titles ) . "\n\n" )
			: "This category currently has no products.\n\n";
		if ( $gaps ) {
			$glist = '';
			foreach ( $gaps as $g ) {
				$glist .= '- ' . $g['keyword'] . ' (vol ' . (int) $g['volume'] . ")\n";
			}
			$user .= "UNCOVERED search queries (gaps) from our keyword research:\n{$glist}\n";
		}
		$user .= "Return ONLY a JSON object, no prose, no code fences, with exactly these keys:\n"
			. "\"summary\": 2-3 sentences in the language of the product titles — what the category contains today, what sells best, and its biggest weaknesses.\n"
			. "\"source_list\": exhaustive array grouping EVERY uncovered query above into concrete products to source, each item {\"product\": \"name as you would search it on a supplier site\", \"queries\": [the exact queries it covers], \"volume\": cumulated integer}. Sorted by volume descending. No query may be dropped.\n"
			. "\"ideas\": array of 5-15 product ideas absent from BOTH the catalogue and the query list (missing famous models, variants, themes, POD lines shoppers would expect, and products complementary to the best-sellers), each {\"product\": \"...\", \"why\": \"max 10 words\"}.";

		// The report is a single long generation — give PHP room so the request
		// doesn't die at the default 30s and surface as a generic AJAX failure.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'admin' );

		try {
			$text = $this->call_claude( $system, $user );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		$data = $this->parse_report_json( $text );
		if ( ! is_array( $data ) || ( empty( $data['source_list'] ) && empty( $data['summary'] ) && empty( $data['ideas'] ) ) ) {
			wp_send_json_error( [ 'message' => __( 'The AI returned an unreadable report. Try again.', 'dazont-ecom' ) ] );
		}
		// Safety net: strip recommendations that duplicate an existing product.
		$data             = $this->drop_existing_recos( $data, $raw_titles );
		$data['products'] = $total_products; // shown in the report for verification.
		update_term_meta( $cat, '_dze_insights', [ 'data' => $data, 'ts' => time() ] );
		wp_send_json_success( [ 'data' => $data, 'ts' => time(), 'saved' => true ] );
	}

	/**
	 * Shop-wide opportunities: compiles the source_list of every SAVED category
	 * report into one volume-ranked list, and flags categories that have gaps
	 * but no report yet so the UI can offer to generate them.
	 */
	public function ajax_all_opps(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		global $wpdb;
		$reported = [];
		$opps     = [];
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s", '_dze_insights' ) );
		foreach ( array_map( 'intval', (array) $ids ) as $tid ) {
			$meta = get_term_meta( $tid, '_dze_insights', true );
			$data = is_array( $meta ) ? ( $meta['data'] ?? null ) : null;
			if ( ! is_array( $data ) || empty( $data['source_list'] ) ) {
				continue;
			}
			$term = get_term( $tid, 'product_cat' );
			$name = $term instanceof WP_Term ? $term->name : ( '#' . $tid );
			$reported[ $tid ] = true;
			foreach ( (array) $data['source_list'] as $row ) {
				$opps[] = [
					'cat'     => $tid,
					'catName' => $name,
					'product' => (string) ( $row['product'] ?? '' ),
					'queries' => array_map( 'strval', (array) ( $row['queries'] ?? [] ) ),
					'volume'  => (int) ( $row['volume'] ?? 0 ),
				];
			}
		}
		usort( $opps, static fn( $a, $b ) => $b['volume'] <=> $a['volume'] );
		$opps = array_slice( $opps, 0, 500 );

		// Categories with gaps but no saved report yet.
		$missing = [];
		if ( class_exists( 'DZE_Keywords' ) ) {
			foreach ( DZE_Keywords::counts_by_term() as $tid => $c ) {
				if ( ( $c['gaps'] ?? 0 ) > 0 && empty( $reported[ $tid ] ) ) {
					$term = get_term( (int) $tid, 'product_cat' );
					if ( $term instanceof WP_Term ) {
						$missing[] = [ 'cat' => (int) $tid, 'name' => $term->name, 'gaps' => (int) $c['gaps'] ];
					}
				}
			}
			usort( $missing, static fn( $a, $b ) => $b['gaps'] <=> $a['gaps'] );
		}
		wp_send_json_success( [ 'opps' => $opps, 'missing' => $missing, 'reports' => count( $reported ) ] );
	}

	/**
	 * Lowercased significant tokens of a product title (generic apparel words and
	 * punctuation stripped), for duplicate detection between recommendations and
	 * the existing catalogue.
	 */
	private function title_tokens( string $t ): array {
		$t = strtolower( remove_accents( $t ) );
		$t = (string) preg_replace( '/[^a-z0-9]+/', ' ', $t );
		$stop = [ 't', 'shirt', 'tshirt', 'tee', 'shirts', 'tees', 'the', 'a', 'of', 'for', 'with' ];
		$out  = [];
		foreach ( array_filter( explode( ' ', $t ) ) as $w ) {
			if ( ! in_array( $w, $stop, true ) ) {
				$out[] = $w;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Removes source_list/ideas entries that duplicate an existing product:
	 * either every significant token of the recommendation appears in one
	 * existing title, or an existing title (2+ tokens) is fully contained in the
	 * recommendation. Belt-and-braces on top of the prompt instruction.
	 */
	private function drop_existing_recos( array $data, array $titles ): array {
		$sets = [];
		foreach ( $titles as $t ) {
			$tok = $this->title_tokens( (string) $t );
			if ( $tok ) {
				$sets[] = $tok;
			}
		}
		$dropped = 0;
		foreach ( [ 'source_list', 'ideas' ] as $k ) {
			if ( empty( $data[ $k ] ) || ! is_array( $data[ $k ] ) ) {
				continue;
			}
			$keep = [];
			foreach ( $data[ $k ] as $row ) {
				$tok = $this->title_tokens( (string) ( $row['product'] ?? '' ) );
				$dup = false;
				if ( $tok ) {
					foreach ( $sets as $set ) {
						if ( ! array_diff( $tok, $set ) || ( count( $set ) >= 2 && ! array_diff( $set, $tok ) ) ) {
							$dup = true;
							break;
						}
					}
				}
				if ( $dup ) {
					$dropped++;
				} else {
					$keep[] = $row;
				}
			}
			$data[ $k ] = $keep;
		}
		$data['deduped'] = $dropped;
		return $data;
	}

	/**
	 * Tolerant JSON parse for the report: strips fences, grabs the outermost
	 * object, and if the model truncated mid-array, salvages what parsed by
	 * closing open brackets so a long list still renders instead of erroring.
	 */
	private function parse_report_json( string $text ): ?array {
		$text = trim( (string) preg_replace( '/^```(?:json)?\s*|\s*```\s*$/i', '', trim( $text ) ) );
		$start = strpos( $text, '{' );
		if ( false === $start ) {
			return null;
		}
		$text = substr( $text, $start );
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		// Truncated output: cut back to the last complete "}," entry and close.
		$cut = strrpos( $text, '},' );
		if ( false !== $cut ) {
			$repair = substr( $text, 0, $cut + 1 ) . ']}';
			$decoded = json_decode( $repair, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	/** Insights model: own setting, else the main Marketing AI model (never Haiku unless chosen). */
	private function insights_model(): string {
		$m = trim( (string) ( DZE_Marketing_Ai::get_settings()['insights_model'] ?? '' ) );
		return $m !== '' ? $m : DZE_Marketing_Ai::chosen_model();
	}

	/** Minimal Anthropic Messages call, reusing the Marketing AI key + model. */
	private function call_claude( string $system, string $user ): string {
		if ( DZE_Ai_Usage::over_budget() ) {
			throw new RuntimeException( DZE_Ai_Usage::budget_message() );
		}
		$resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'timeout' => 180,
			'headers' => [
				'x-api-key'         => DZE_Marketing_Ai::api_key(),
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model'      => $this->insights_model(),
				'max_tokens' => 8000,
				'system'     => $system,
				'messages'   => [ [ 'role' => 'user', 'content' => $user ] ],
			] ),
		] );
		if ( is_wp_error( $resp ) ) {
			throw new RuntimeException( $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			throw new RuntimeException( (string) ( $data['error']['message'] ?? ( 'HTTP ' . $code ) ) );
		}
		DZE_Ai_Usage::record( 'anthropic', (int) ( $data['usage']['input_tokens'] ?? 0 ), (int) ( $data['usage']['output_tokens'] ?? 0 ), $this->insights_model() );
		$text = '';
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		return $text;
	}
}
