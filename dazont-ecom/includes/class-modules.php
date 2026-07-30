<?php
defined( 'ABSPATH' ) || exit;

/**
 * Module manager — the single catalog of every plugin function, grouped by
 * type. Each entry carries a short one-line description and a longer detailed
 * one (shown in a popup). Lives as a tab of the Settings page; keeps a
 * fallback submenu/top-level menu so it stays reachable whatever is disabled.
 * The plugin boots ONLY the enabled modules; the manager itself, the updater
 * and the API-key helper are always on.
 */
final class DZE_Modules {

	private const OPT   = 'dze_modules';
	private const NONCE = 'dze_modules';
	public const MENU_SLUG = 'dazont-ecom-modules';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'fallback_menu' ], 9 );
		add_action( 'admin_menu', [ $this, 'submenu' ], 99 );
		add_action( 'wp_ajax_dze_modules_toggle', [ $this, 'ajax_toggle' ] );
	}

	// =========================================================================
	// Catalog — id => class, group, label, short desc, detailed popup text.
	// Array order = boot order (historical instantiation order).
	// =========================================================================

	/** group id => label. */
	public static function groups(): array {
		return [
			'product'   => __( 'Product page', 'dazont-ecom' ),
			'catalog'   => __( 'Shop & catalogue', 'dazont-ecom' ),
			'marketing' => __( 'Marketing', 'dazont-ecom' ),
			'sourcing'  => __( 'Sourcing', 'dazont-ecom' ),
			'tech'      => __( 'Technical', 'dazont-ecom' ),
		];
	}

	public static function catalog(): array {
		return [
			'restock' => [
				'class' => 'DZE_Restock',
				'group' => 'catalog',
				'label' => __( 'Restock', 'dazont-ecom' ),
				'desc'  => __( 'Out-of-stock backlog ranked by lifetime sales.', 'dazont-ecom' ),
				'more'  => __( 'Lists the product lines (simple products or variable parents) that have at least one out-of-stock element, ranked by total lifetime sales — so restocking always starts with proven sellers. Sales figures are cached by a weekly WP-Cron recalculation and aggregated across all WPML languages. This module also hosts the top-level Dazont menu; if you switch it off, the module manager takes the menu over so nothing gets lost.', 'dazont-ecom' ),
			],
			'dashboard' => [
				'class' => 'DZE_Dashboard',
				'group' => 'tech',
				'label' => __( 'Dashboard', 'dazont-ecom' ),
				'desc'  => __( 'The plugin home screen: stock, spend, calendar, categories.', 'dazont-ecom' ),
				'more'  => __( 'Four blocks: the top out-of-stock best-sellers waiting for restock, the monthly API spend per provider, the planned marketing calendar (current and upcoming events), and the top product categories of the last 3 months with their last novelty-search date. The same blocks are also registered as WordPress dashboard widgets on the WP home screen.', 'dazont-ecom' ),
			],
			'trending' => [
				'class' => 'DZE_Trending',
				'group' => 'catalog',
				'label' => __( 'Trending Products', 'dazont-ecom' ),
				'desc'  => __( 'The [time_bestsellers] shortcode: best-sellers grid.', 'dazont-ecom' ),
				'more'  => __( 'Computes the shop\'s best-sellers from the WooCommerce Analytics sales lookup table, then delegates the display to WooCommerce\'s own [products] shortcode — native grid, native columns, native pagination, zero custom markup to maintain. Results are cached 24 hours. Pages that don\'t use the shortcode pay no cost at all.', 'dazont-ecom' ),
			],
			'discounts' => [
				'class' => 'DZE_Discounts',
				'group' => 'marketing',
				'label' => __( 'Discounts & Marketing events', 'dazont-ecom' ),
				'desc'  => __( 'Scheduled sales, bulk offers, automatic discounts, banners.', 'dazont-ecom' ),
				'more'  => __( 'Four rule types: scheduled site-wide % sales with optional promo banners; "Bulk offer per item" (% off a product line once you buy N of the same product, shown as a Bundle line); tiered "Bulk order" discounts applied through an automatic Wholesale coupon; and automatic product discounts (new arrivals, slow movers, best-sellers or trending) refreshed weekly. Sale prices are written into the real product data, so on-sale pages, badges and Merchant Center all see them. Front-end hooks are only registered while at least one rule is active.', 'dazont-ecom' ),
			],
			'gmc' => [
				'class' => 'DZE_Gmc',
				'group' => 'marketing',
				'label' => __( 'Google Merchant Center', 'dazont-ecom' ),
				'desc'  => __( 'Pushes your scheduled sale promotions to Merchant Center.', 'dazont-ecom' ),
				'more'  => __( 'No product feed involved. Each scheduled sale from the Discounts module is inserted as a Merchant Center PROMOTION through Google\'s Merchant API (the successor of the Content API), into one GMC account per language; the promotion data sources are found or created automatically per country/language. Authentication uses your connected Google account or a service account. A cron keeps the promotions in sync with your events.', 'dazont-ecom' ),
			],
			'gmc_activation' => [
				'class' => 'DZE_Gmc_Activation',
				'group' => 'marketing',
				'label' => __( 'GMC product activation', 'dazont-ecom' ),
				'desc'  => __( 'Chooses which products/variations go to Merchant Center.', 'dazont-ecom' ),
				'more'  => __( 'Manages the "_merchant_center_activation" flag your Merchant Center feed reads, with a ✔/✘ GMC column on the products list. Automatic rules (whole catalogue or per product): simple products and variable parents on; variations with an ORIGINAL image on (first of each distinct image, duplicates skipped); when no variation has an image, first variation of each value of a fallback attribute (colour by default). Per-product quick strategies — all variations (unique versions), first of each chosen attribute (one per colour), none — plus a manual variation picker with thumbnails for the tricky cases (e.g. rugs), and the classic checkboxes in the product Advanced tab and variation panels.', 'dazont-ecom' ),
			],
			'marketing_ai' => [
				'class' => 'DZE_Marketing_Ai',
				'group' => 'marketing',
				'label' => __( 'Marketing Assistant', 'dazont-ecom' ),
				'desc'  => __( 'Suggests a promotion calendar; hosts the Settings page.', 'dazont-ecom' ),
				'more'  => __( 'Builds a proposed marketing calendar from your own shop data: site name, categories, sample products, price range, languages (WPML) and per-language target countries. Every suggestion is reviewed by you — accepting turns it into a real scheduled event in Marketing Events; a shortcode renders the final calendar on the front. This module also hosts the shared Settings page (API keys, model choices, monthly spend cap) that the other modules read their configuration from.', 'dazont-ecom' ),
			],
			'sourcing' => [
				'classes' => [ 'DZE_Explorer', 'DZE_Keywords' ],
				'group'   => 'sourcing',
				'label'   => __( 'Sourcing Assistant', 'dazont-ecom' ),
				'desc'    => __( 'Catalogue explorer, keyword workbench and the sourcing report.', 'dazont-ecom' ),
				'more'    => __( 'One assistant, three parts. The Product Explorer: a storefront-like full-screen view of the catalogue (big images, category rail, filters, zoom, focus mode). The keyword workbench: one SEMrush keyword set per category (tolerant CSV import, statuses, per-category metrics, keyword-to-product matching). And the sourcing report: ALL products ranked by real sales plus the keyword gaps are fed to the model, which returns product opportunities deduplicated against what the shop already sells.', 'dazont-ecom' ),
			],
			'content' => [
				'class' => 'DZE_Content',
				'group' => 'product',
				'label' => __( 'Product Content', 'dazont-ecom' ),
				'desc'  => __( 'Automatic edition of a product: texts, images, price.', 'dazont-ecom' ),
				'more'  => __( 'The full product pipeline. A universal prompt registry (your own prompts, with the product data they receive as input and the field each one writes to); the "Automatic edition" popup on the product page (tick what to generate, Launch, review everything in an editable preview, apply); image generation with a session gallery, native-style selection and SEO naming; price recalculation from cost (COGS × your price table); and the multi-product bulk screen reached from the Products list.', 'dazont-ecom' ),
			],
			'pod' => [
				'class' => 'DZE_Pod',
				'group' => 'product',
				'label' => __( 'POD image', 'dazont-ecom' ),
				'desc'  => __( 'Prints a per-product design on your base mockup.', 'dazont-ecom' ),
				'more'  => __( 'Print on demand only. Upload the design on the product (PNG with transparent background, ideally 4500×5400 px), store the photo of your blank product once under Settings → POD, and one dedicated editable prompt renders the printed product through fal.ai. You review the result, then set it as the main image (the previous main moves to the front of the gallery) or add it to the gallery — with the standard SEO naming.', 'dazont-ecom' ),
			],
			'variation_split' => [
				'class' => 'DZE_Variation_Split',
				'group' => 'product',
				'label' => __( 'Variation Split (prototype)', 'dazont-ecom' ),
				'desc'  => __( 'One variation attribute → standalone draft products.', 'dazont-ecom' ),
				'more'  => __( 'Splits a chosen variation attribute of a variable product (e.g. colour) into standalone products, one per term — each independently searchable and rankable in SEO. Deliberately conservative: the new products are created as DRAFTS and never published automatically, the source product is left untouched, and each copy takes the description, categories, gallery, the representative variation\'s price and image, and keeps the term as a fixed attribute.', 'dazont-ecom' ),
			],
		];
	}

	// =========================================================================
	// State + boot
	// =========================================================================

	private static function states(): array {
		$s = get_option( self::OPT, [] );
		return is_array( $s ) ? $s : [];
	}

	/** A module is ON unless explicitly switched off (new modules default on). */
	public static function enabled( string $id ): bool {
		$s = self::states();
		return ! isset( $s[ $id ] ) || ! empty( $s[ $id ] );
	}

	/** Instantiate every ENABLED module, in the catalog (historical) order. */
	public static function boot(): void {
		foreach ( self::catalog() as $id => $m ) {
			if ( ! self::enabled( $id ) ) {
				continue;
			}
			// A module entry may cover several classes (e.g. Sourcing Assistant).
			foreach ( (array) ( $m['classes'] ?? $m['class'] ?? [] ) as $cls ) {
				if ( class_exists( $cls ) ) {
					$cls::instance();
				}
			}
		}
	}

	// =========================================================================
	// Menu — normally a tab of the Settings page. Fallbacks keep it reachable:
	// an own submenu when the Settings host module is off, and the top-level
	// Dazont menu itself when Restock (its owner) is off.
	// =========================================================================

	public function fallback_menu(): void {
		if ( self::enabled( 'restock' ) ) {
			return;
		}
		add_menu_page(
			__( 'Dazont Ecom', 'dazont-ecom' ),
			__( 'Dazont Ecom', 'dazont-ecom' ),
			'manage_woocommerce',
			DZE_Restock::MENU_SLUG,
			[ $this, 'render_page' ],
			'dashicons-cart',
			56
		);
	}

	public function submenu(): void {
		if ( self::enabled( 'marketing_ai' ) ) {
			return; // reachable as the Modules tab of the Settings page.
		}
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Modules', 'dazont-ecom' ),
			__( 'Modules', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	// =========================================================================
	// Screen
	// =========================================================================

	/** Standalone page (fallback menus only). */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		echo '<div class="wrap dze-wrap dze-admin"><h1>' . esc_html__( 'Dazont Ecom — Modules', 'dazont-ecom' ) . '</h1>';
		$this->render_tab();
		echo '</div>';
	}

	/** Tab body (used by the Settings page tab AND the standalone page). */
	public function render_tab(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$groups = self::groups();
		$by     = [];
		foreach ( self::catalog() as $id => $m ) {
			$by[ $m['group'] ][ $id ] = $m;
		}
		?>
		<p class="description"><?php esc_html_e( 'Switch any function on or off. A change takes effect on the next page load. Click ? for the full description.', 'dazont-ecom' ); ?></p>
		<div class="dze-mod-groups">
		<?php foreach ( $groups as $gid => $glabel ) : ?>
			<?php if ( empty( $by[ $gid ] ) ) { continue; } ?>
			<div class="dze-mod-card">
				<h2><?php echo esc_html( $glabel ); ?></h2>
				<?php foreach ( $by[ $gid ] as $id => $m ) : $on = self::enabled( $id ); ?>
					<div class="dze-mod-row">
						<label class="dze-switch">
							<input type="checkbox" class="dze-mod-toggle" data-module="<?php echo esc_attr( $id ); ?>" <?php checked( $on ); ?> />
							<span class="dze-switch-slider"></span>
						</label>
						<div class="dze-mod-info">
							<strong><?php echo esc_html( $m['label'] ); ?>
								<button type="button" class="dze-mod-more" data-module="<?php echo esc_attr( $id ); ?>" title="<?php esc_attr_e( 'Full description', 'dazont-ecom' ); ?>">?</button>
							</strong>
							<span class="dze-mod-desc"><?php echo esc_html( $m['desc'] ); ?></span>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
		</div>
		<p id="dze-mod-note" class="description" style="display:none;">
			<?php esc_html_e( 'Saved ✓ — the change applies on the next page load.', 'dazont-ecom' ); ?>
			<a href="#" onclick="window.location.reload();return false;"><?php esc_html_e( 'Reload now', 'dazont-ecom' ); ?></a>
		</p>
		<div class="dze-mod-popup" id="dze-mod-popup">
			<div class="dze-mod-popup-box">
				<h3 id="dze-mod-popup-title"></h3>
				<p id="dze-mod-popup-text"></p>
				<p style="text-align:right;margin:14px 0 0;"><button type="button" class="button" id="dze-mod-popup-close"><?php esc_html_e( 'Close', 'dazont-ecom' ); ?></button></p>
			</div>
		</div>
		<style>
		.dze-mod-groups { display: grid; grid-template-columns: repeat(auto-fill, minmax(430px, 1fr)); gap: 16px; margin-top: 14px; max-width: 1400px; }
		.dze-mod-card { background: #fff; border: 1px solid #e2e4e7; border-radius: 8px; padding: 16px 20px; }
		.dze-mod-card h2 { margin: 0 0 6px; font-size: 14px; text-transform: uppercase; letter-spacing: .4px; color: #50575e; }
		.dze-mod-row { display: flex; align-items: flex-start; gap: 12px; padding: 10px 0; border-top: 1px solid #f0f0f1; }
		.dze-mod-row:first-of-type { border-top: none; }
		.dze-mod-info strong { display: block; font-size: 13px; }
		.dze-mod-desc { display: block; color: #646970; font-size: 12px; margin-top: 2px; }
		.dze-mod-more {
			display: inline-block; width: 16px; height: 16px; line-height: 14px; text-align: center; padding: 0;
			border: 1px solid #c3c4c7; border-radius: 50%; background: #f6f7f7; color: #646970;
			font-size: 10px; font-weight: 700; cursor: pointer; vertical-align: 1px; margin-left: 4px;
		}
		.dze-mod-more:hover { border-color: #2271b1; color: #2271b1; }
		.dze-switch { position: relative; display: inline-block; width: 36px; height: 20px; flex: 0 0 36px; margin-top: 2px; }
		.dze-switch input { opacity: 0; width: 0; height: 0; }
		.dze-switch-slider { position: absolute; inset: 0; background: #c3c4c7; border-radius: 999px; transition: background .15s; cursor: pointer; }
		.dze-switch-slider::before { content: ""; position: absolute; width: 16px; height: 16px; left: 2px; top: 2px; background: #fff; border-radius: 50%; transition: transform .15s; }
		.dze-switch input:checked + .dze-switch-slider { background: #00794b; }
		.dze-switch input:checked + .dze-switch-slider::before { transform: translateX(16px); }
		.dze-switch input:disabled + .dze-switch-slider { opacity: .5; cursor: wait; }
		.dze-mod-popup { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 100001; display: none; align-items: center; justify-content: center; }
		.dze-mod-popup.is-open { display: flex; }
		.dze-mod-popup-box { background: #fff; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,.3); max-width: 560px; width: 92vw; padding: 20px 24px; }
		.dze-mod-popup-box h3 { margin: 0 0 10px; }
		.dze-mod-popup-box p { margin: 0; line-height: 1.6; color: #3c434a; }
		</style>
		<script>
		jQuery( function ( $ ) {
			var moreTexts = <?php
				$pop = [];
				foreach ( self::catalog() as $mid => $mm ) {
					$pop[ $mid ] = [ 'title' => $mm['label'], 'text' => $mm['more'] ];
				}
				echo wp_json_encode( $pop );
			?>;
			$( document ).on( 'click', '.dze-mod-more', function () {
				var m = moreTexts[ $( this ).data( 'module' ) ];
				if ( ! m ) { return; }
				$( '#dze-mod-popup-title' ).text( m.title );
				$( '#dze-mod-popup-text' ).text( m.text );
				$( '#dze-mod-popup' ).addClass( 'is-open' );
			} );
			$( document ).on( 'click', '#dze-mod-popup-close', function () { $( '#dze-mod-popup' ).removeClass( 'is-open' ); } );
			$( document ).on( 'click', '#dze-mod-popup', function ( e ) { if ( e.target === this ) { $( this ).removeClass( 'is-open' ); } } );
			$( document ).on( 'change', '.dze-mod-toggle', function () {
				var $t = $( this ).prop( 'disabled', true );
				$.post( window.ajaxurl, {
					action: 'dze_modules_toggle',
					nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>',
					module: $t.data( 'module' ),
					on: $t.is( ':checked' ) ? 1 : 0
				} ).done( function ( res ) {
					$t.prop( 'disabled', false );
					if ( res && res.success ) { $( '#dze-mod-note' ).show(); }
					else {
						$t.prop( 'checked', ! $t.is( ':checked' ) );
						window.alert( ( res && res.data && res.data.message ) || '<?php echo esc_js( __( 'Something went wrong.', 'dazont-ecom' ) ); ?>' );
					}
				} ).fail( function () {
					$t.prop( 'disabled', false ).prop( 'checked', ! $t.is( ':checked' ) );
					window.alert( '<?php echo esc_js( __( 'Something went wrong.', 'dazont-ecom' ) ); ?>' );
				} );
			} );
		} );
		</script>
		<?php
	}

	public function ajax_toggle(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$id = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		if ( ! isset( self::catalog()[ $id ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown module.', 'dazont-ecom' ) ] );
		}
		$s        = self::states();
		$s[ $id ] = ! empty( $_POST['on'] ) ? 1 : 0;
		update_option( self::OPT, $s, false );
		wp_send_json_success( [ 'module' => $id, 'on' => (bool) $s[ $id ] ] );
	}
}
