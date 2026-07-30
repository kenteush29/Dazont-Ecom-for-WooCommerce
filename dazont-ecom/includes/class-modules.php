<?php
defined( 'ABSPATH' ) || exit;

/**
 * Module manager — the single catalog of every plugin function, grouped by
 * type, each with a mandatory one-line description and an on/off switch
 * (Dazont Ecom → Modules). The plugin boots ONLY the enabled modules; the
 * manager itself, the updater and the API-key helper are always on.
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
	// Catalog — id => class, group, label, one-line description. Boot order.
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
				'desc'  => __( 'Out-of-stock backlog ranked by lifetime sales, recalculated weekly. Also hosts the Dazont admin menu.', 'dazont-ecom' ),
			],
			'dashboard' => [
				'class' => 'DZE_Dashboard',
				'group' => 'tech',
				'label' => __( 'Dashboard', 'dazont-ecom' ),
				'desc'  => __( 'The Dazont dashboard page: modules overview, quick links, monthly AI usage.', 'dazont-ecom' ),
			],
			'trending' => [
				'class' => 'DZE_Trending',
				'group' => 'catalog',
				'label' => __( 'Trending Products', 'dazont-ecom' ),
				'desc'  => __( 'The [time_bestsellers] shortcode: best-sellers grid with native WooCommerce pagination.', 'dazont-ecom' ),
			],
			'discounts' => [
				'class' => 'DZE_Discounts',
				'group' => 'marketing',
				'label' => __( 'Discounts & Marketing events', 'dazont-ecom' ),
				'desc'  => __( 'Evergreen bulk-cart coupons, automatic product discounts with real sale prices, scheduled sale events.', 'dazont-ecom' ),
			],
			'gmc' => [
				'class' => 'DZE_Gmc',
				'group' => 'marketing',
				'label' => __( 'Google Merchant Center', 'dazont-ecom' ),
				'desc'  => __( 'GMC sync: product feed and scheduled updates towards Google Shopping.', 'dazont-ecom' ),
			],
			'marketing_ai' => [
				'class' => 'DZE_Marketing_Ai',
				'group' => 'marketing',
				'label' => __( 'AI Marketing Assistant', 'dazont-ecom' ),
				'desc'  => __( 'AI marketing calendar and insights — also hosts the AI Settings page (keys, models, monthly budget).', 'dazont-ecom' ),
			],
			'explorer' => [
				'class' => 'DZE_Explorer',
				'group' => 'sourcing',
				'label' => __( 'Product Explorer', 'dazont-ecom' ),
				'desc'  => __( 'Full-screen catalogue browser plus the AI sourcing report (all products, sales-aware).', 'dazont-ecom' ),
			],
			'keywords' => [
				'class' => 'DZE_Keywords',
				'group' => 'sourcing',
				'label' => __( 'Sourcing keywords', 'dazont-ecom' ),
				'desc'  => __( 'Keyword import, AI matching against the catalogue and analysis for the Sourcing Assistant.', 'dazont-ecom' ),
			],
			'product_images' => [
				'class' => 'DZE_Product_Images',
				'group' => 'product',
				'label' => __( 'AI Product Images (Gemini)', 'dazont-ecom' ),
				'desc'  => __( 'Google Gemini image generation box on the product page.', 'dazont-ecom' ),
			],
			'content' => [
				'class' => 'DZE_Content',
				'group' => 'product',
				'label' => __( 'AI Content', 'dazont-ecom' ),
				'desc'  => __( 'Prompt registry, Automatic edition toolbox (texts, images, price), bulk generation, price table.', 'dazont-ecom' ),
			],
			'pod' => [
				'class' => 'DZE_Pod',
				'group' => 'product',
				'label' => __( 'POD image', 'dazont-ecom' ),
				'desc'  => __( 'Print on demand: a per-product design printed on your stored base mockup (fal.ai key required).', 'dazont-ecom' ),
			],
			'variation_split' => [
				'class' => 'DZE_Variation_Split',
				'group' => 'product',
				'label' => __( 'Variation Split (prototype)', 'dazont-ecom' ),
				'desc'  => __( 'Split a variable product\'s variations into simple DRAFT products.', 'dazont-ecom' ),
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
			if ( self::enabled( $id ) && class_exists( $m['class'] ) ) {
				$m['class']::instance();
			}
		}
	}

	// =========================================================================
	// Menu — takes over the top-level Dazont menu when Restock is off.
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

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$groups = self::groups();
		$by     = [];
		foreach ( self::catalog() as $id => $m ) {
			$by[ $m['group'] ][ $id ] = $m;
		}
		?>
		<div class="wrap dze-wrap dze-admin">
			<h1><?php esc_html_e( 'Dazont Ecom — Modules', 'dazont-ecom' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Switch any function on or off. A change takes effect on the next page load.', 'dazont-ecom' ); ?></p>
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
								<strong><?php echo esc_html( $m['label'] ); ?></strong>
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
		</div>
		<style>
		.dze-mod-groups { display: grid; grid-template-columns: repeat(auto-fill, minmax(430px, 1fr)); gap: 16px; margin-top: 14px; max-width: 1400px; }
		.dze-mod-card { background: #fff; border: 1px solid #e2e4e7; border-radius: 8px; padding: 16px 20px; }
		.dze-mod-card h2 { margin: 0 0 6px; font-size: 14px; text-transform: uppercase; letter-spacing: .4px; color: #50575e; }
		.dze-mod-row { display: flex; align-items: flex-start; gap: 12px; padding: 10px 0; border-top: 1px solid #f0f0f1; }
		.dze-mod-row:first-of-type { border-top: none; }
		.dze-mod-info strong { display: block; font-size: 13px; }
		.dze-mod-desc { display: block; color: #646970; font-size: 12px; margin-top: 2px; }
		.dze-switch { position: relative; display: inline-block; width: 36px; height: 20px; flex: 0 0 36px; margin-top: 2px; }
		.dze-switch input { opacity: 0; width: 0; height: 0; }
		.dze-switch-slider { position: absolute; inset: 0; background: #c3c4c7; border-radius: 999px; transition: background .15s; cursor: pointer; }
		.dze-switch-slider::before { content: ""; position: absolute; width: 16px; height: 16px; left: 2px; top: 2px; background: #fff; border-radius: 50%; transition: transform .15s; }
		.dze-switch input:checked + .dze-switch-slider { background: #00794b; }
		.dze-switch input:checked + .dze-switch-slider::before { transform: translateX(16px); }
		.dze-switch input:disabled + .dze-switch-slider { opacity: .5; cursor: wait; }
		</style>
		<script>
		jQuery( function ( $ ) {
			$( '.dze-mod-toggle' ).on( 'change', function () {
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
