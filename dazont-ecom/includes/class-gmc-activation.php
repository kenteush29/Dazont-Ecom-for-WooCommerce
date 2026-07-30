<?php
defined( 'ABSPATH' ) || exit;

/**
 * GMC product activation — decides WHICH products/variations are pushed to
 * Google Merchant Center, via the `_merchant_center_activation` meta ('yes'
 * or absent), the same key the shop already uses.
 *
 * Three layers, from automatic to manual (the historical spreadsheet process,
 * brought into WordPress):
 *   1. Auto-mark (whole catalogue or one product): simple products and
 *      variable parents are enabled; variations with an ORIGINAL image are
 *      enabled (first of each distinct image, duplicates skipped); when no
 *      variation has an image, the first variation of each value of a
 *      fallback attribute (default colour) is enabled.
 *   2. Per-product strategy: enable ALL variations (e.g. "version" attributes
 *      where every variation is unique), or the FIRST of each value of a
 *      chosen attribute (e.g. one per colour when sizes share the photo).
 *   3. Manual: a compact variation picker on the product page (thumbnail +
 *      attributes + checkbox) for the tricky cases (e.g. rugs), plus the
 *      classic checkboxes in the product Advanced tab and variation panels.
 *
 * A "GMC" column on the products list shows ✔/✘ with the active-variation
 * count for variable products.
 */
final class DZE_Gmc_Activation {

	private const OPT   = 'dze_gmca_settings';
	private const NONCE = 'dze_gmca';

	/** Same meta key the shop's existing GMC flow reads. */
	public const META = '_merchant_center_activation';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		// Products list column.
		add_filter( 'manage_edit-product_columns', [ $this, 'add_column' ], 20 );
		add_action( 'manage_product_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
		// Product Advanced tab checkbox (simple + variable parent).
		add_action( 'woocommerce_product_options_advanced', [ $this, 'product_checkbox' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_checkbox' ] );
		// Per-variation checkbox in the Variations panel.
		add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'variation_checkbox' ], 10, 3 );
		add_action( 'woocommerce_save_product_variation', [ $this, 'save_variation_checkbox' ], 10, 2 );
		// Product-page picker + strategies (popup opened from the Dazont hub).
		add_action( 'admin_footer', [ $this, 'footer_modal' ] );
		add_action( 'wp_ajax_dze_gmca_save', [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_dze_gmca_strategy', [ $this, 'ajax_strategy' ] );
		// Catalogue-wide auto-mark (batched).
		add_action( 'wp_ajax_dze_gmca_run', [ $this, 'ajax_run' ] );
	}

	// =========================================================================
	// Settings (fallback attribute for the no-image case)
	// =========================================================================

	public static function get_settings(): array {
		$s = get_option( self::OPT, [] );
		return is_array( $s ) ? $s : [];
	}

	public function register_settings(): void {
		register_setting( 'dze_gmca_options', self::OPT, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize' ],
			'default'           => [],
		] );
	}

	public function sanitize( $in ): array {
		$in  = is_array( $in ) ? $in : [];
		$out = self::get_settings();
		if ( isset( $in['fallback_attr'] ) ) {
			$out['fallback_attr'] = sanitize_title( (string) $in['fallback_attr'] );
		}
		return $out;
	}

	/** Attribute used to pick "one variation per value" when no variation has an image. */
	public static function fallback_attr(): string {
		$a = (string) ( self::get_settings()['fallback_attr'] ?? '' );
		return '' !== $a ? $a : 'pa_color';
	}

	// =========================================================================
	// Meta helpers
	// =========================================================================

	private static function is_on( int $post_id ): bool {
		return 'yes' === get_post_meta( $post_id, self::META, true );
	}

	private static function set_on( int $post_id, bool $on ): void {
		if ( $on ) {
			update_post_meta( $post_id, self::META, 'yes' );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}

	/** ['active' => bool, 'active_variations' => int] for the list column. */
	private static function status_data( WC_Product $product ): array {
		$result = [ 'active' => self::is_on( $product->get_id() ), 'active_variations' => 0 ];
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				if ( self::is_on( (int) $variation_id ) ) {
					$result['active_variations']++;
				}
			}
			if ( $result['active_variations'] > 0 ) {
				$result['active'] = true;
			}
		}
		return $result;
	}

	// =========================================================================
	// Products list column
	// =========================================================================

	public function add_column( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'name' === $key ) {
				$new['gmc_status'] = __( 'GMC', 'dazont-ecom' );
			}
		}
		return $new;
	}

	public function render_column( string $column, int $post_id ): void {
		if ( 'gmc_status' !== $column ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			echo '—';
			return;
		}
		$data = self::status_data( $product );
		if ( $data['active'] ) {
			echo '<span style="color:#16a34a;font-size:16px;font-weight:bold;">&#10004;</span>';
			if ( $product->is_type( 'variable' ) && $data['active_variations'] > 0 ) {
				echo ' <span>(' . (int) $data['active_variations'] . ')</span>';
			}
		} else {
			echo '<span style="color:#dc2626;font-size:16px;font-weight:bold;">&#10008;</span>';
		}
	}

	// =========================================================================
	// Classic checkboxes (Advanced tab + variation panels)
	// =========================================================================

	public function product_checkbox(): void {
		global $post;
		woocommerce_wp_checkbox( [
			'id'      => self::META,
			'label'   => __( 'GMC Activation', 'dazont-ecom' ),
			'value'   => get_post_meta( $post->ID, self::META, true ),
			'cbvalue' => 'yes',
		] );
	}

	public function save_product_checkbox( $post_id ): void {
		self::set_on( (int) $post_id, ! empty( $_POST[ self::META ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce nonce-checked save.
	}

	public function variation_checkbox( $loop, $variation_data, $variation ): void {
		woocommerce_wp_checkbox( [
			'id'      => self::META . "[{$loop}]",
			'label'   => __( 'GMC Activation', 'dazont-ecom' ),
			'value'   => get_post_meta( $variation->ID, self::META, true ),
			'cbvalue' => 'yes',
		] );
	}

	public function save_variation_checkbox( $variation_id, $i ): void {
		self::set_on( (int) $variation_id, ! empty( $_POST[ self::META ][ $i ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce nonce-checked save.
	}

	// =========================================================================
	// Auto-mark logic (the spreadsheet script, on live data)
	// =========================================================================

	/**
	 * Applies the automatic rules to ONE product and returns the enabled ids.
	 * Simple: the product itself. Variable: parent + the picked variations —
	 * first of each distinct variation image; when NO variation has its own
	 * image, first of each value of the fallback attribute (or the very first
	 * variation when that attribute is absent). Every other variation is
	 * switched off.
	 */
	public static function auto_mark( WC_Product $product, string $fallback_attr = '' ): array {
		$fallback_attr = $fallback_attr ?: self::fallback_attr();
		self::set_on( $product->get_id(), true );
		if ( ! $product->is_type( 'variable' ) ) {
			return [ $product->get_id() ];
		}
		$children = array_map( 'intval', $product->get_children() );
		$by_image = [];
		foreach ( $children as $vid ) {
			$img = (int) get_post_meta( $vid, '_thumbnail_id', true );
			$by_image[ $img ][] = $vid;
		}
		$keep = [];
		foreach ( $by_image as $img => $vids ) {
			if ( $img > 0 ) {
				sort( $vids );
				$keep[] = $vids[0]; // first of each ORIGINAL image; duplicates skipped.
			}
		}
		if ( empty( $keep ) && ! empty( $children ) ) {
			// No variation image at all → one per fallback-attribute value.
			$by_value = [];
			foreach ( $children as $vid ) {
				$val = (string) get_post_meta( $vid, 'attribute_' . $fallback_attr, true );
				$by_value[ $val ][] = $vid;
			}
			foreach ( $by_value as $vids ) {
				sort( $vids );
				$keep[] = $vids[0];
			}
		}
		foreach ( $children as $vid ) {
			self::set_on( $vid, in_array( $vid, $keep, true ) );
		}
		return array_merge( [ $product->get_id() ], $keep );
	}

	/** First variation of each value of $attr gets enabled; the rest disabled. */
	private static function mark_first_per_attr( WC_Product_Variable $product, string $attr ): void {
		self::set_on( $product->get_id(), true );
		$by_value = [];
		$children = array_map( 'intval', $product->get_children() );
		foreach ( $children as $vid ) {
			$val = (string) get_post_meta( $vid, 'attribute_' . $attr, true );
			$by_value[ $val ][] = $vid;
		}
		$keep = [];
		foreach ( $by_value as $vids ) {
			sort( $vids );
			$keep[] = $vids[0];
		}
		foreach ( $children as $vid ) {
			self::set_on( $vid, in_array( $vid, $keep, true ) );
		}
	}

	// =========================================================================
	// Product-page picker (side box)
	// =========================================================================

	/** Compact toggle for SIMPLE products, rendered inside the Dazont hub box. */
	public function render_simple_toggle( int $post_id ): void {
		$nonce = wp_create_nonce( self::NONCE );
		?>
		<p style="margin:8px 0 0;">
			<label><input type="checkbox" id="dze-gmca-simple" data-id="<?php echo (int) $post_id; ?>" <?php checked( self::is_on( $post_id ) ); ?> />
			<?php esc_html_e( 'Send to Merchant Center', 'dazont-ecom' ); ?></label>
			<span id="dze-gmca-simplestatus" class="dze-cx-note"></span>
		</p>
		<script>
		jQuery( function ( $ ) {
			$( '#dze-gmca-simple' ).on( 'change', function () {
				var $c = $( this );
				$.post( window.ajaxurl, { action: 'dze_gmca_save', nonce: '<?php echo esc_js( $nonce ); ?>', post: $c.data( 'id' ), parent_on: $c.is( ':checked' ) ? 1 : 0 } )
					.done( function ( res ) { $( '#dze-gmca-simplestatus' ).text( res && res.success ? '✓' : '✗' ); setTimeout( function () { $( '#dze-gmca-simplestatus' ).text( '' ); }, 1500 ); } );
			} );
		} );
		</script>
		<?php
	}

	/** Popup shell for VARIABLE products, printed in the footer; opened from the hub. */
	public function footer_modal(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}
		global $post;
		$product = $post ? wc_get_product( $post->ID ) : null;
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return;
		}
		echo '<div class="dze-cx-modal" id="dze-gmca-modal"><div class="dze-cx-dialog" style="width:min(520px,94vw);">';
		echo '<div class="dze-cx-head"><h2>' . esc_html__( 'GMC activation', 'dazont-ecom' ) . '</h2><button type="button" class="button dze-hub-close" style="margin-left:auto;">' . esc_html__( 'Close', 'dazont-ecom' ) . '</button></div>';
		echo '<div class="dze-cx-body">';
		$this->render_panel( $post );
		echo '</div></div></div>';
	}

	public function render_panel( $post ): void {
		$product = wc_get_product( $post->ID );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return;
		}
		$nonce = wp_create_nonce( self::NONCE );

		// Variable product: variation picker + strategies.
		$attrs = array_keys( $product->get_variation_attributes() ); // e.g. pa_color, pa_size
		$rows  = [];
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( (int) $vid );
			if ( ! $v ) {
				continue;
			}
			$img    = (int) get_post_meta( (int) $vid, '_thumbnail_id', true );
			$rows[] = [
				'id'    => (int) $vid,
				'on'    => self::is_on( (int) $vid ),
				'thumb' => $img ? (string) wp_get_attachment_image_url( $img, 'thumbnail' ) : '',
				'full'  => $img ? (string) wp_get_attachment_image_url( $img, 'large' ) : '',
				'label' => wc_get_formatted_variation( $v, true, false ),
			];
		}
		?>
		<div class="dze-admin dze-gmca-box">
			<p style="margin-top:0;">
				<label><input type="checkbox" id="dze-gmca-parent" <?php checked( self::is_on( $post->ID ) ); ?> /> <strong><?php esc_html_e( 'Parent product', 'dazont-ecom' ); ?></strong></label>
			</p>
			<div class="dze-gmca-list">
				<?php foreach ( $rows as $r ) : ?>
					<label class="dze-gmca-row">
						<input type="checkbox" class="dze-gmca-v" value="<?php echo (int) $r['id']; ?>" <?php checked( $r['on'] ); ?> />
						<?php if ( $r['thumb'] ) : ?><img class="dze-hzoom" src="<?php echo esc_url( $r['thumb'] ); ?>" data-full="<?php echo esc_url( $r['full'] ?: $r['thumb'] ); ?>" alt="" /><?php else : ?><span class="dze-gmca-noimg" title="<?php esc_attr_e( 'No own image', 'dazont-ecom' ); ?>">—</span><?php endif; ?>
						<span class="dze-gmca-lbl"><?php echo esc_html( $r['label'] ?: ( '#' . $r['id'] ) ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<p>
				<button type="button" class="button button-primary button-small" id="dze-gmca-savesel"><?php esc_html_e( 'Save selection', 'dazont-ecom' ); ?></button>
				<span id="dze-gmca-status" class="dze-cx-note"></span>
			</p>
			<hr />
			<p class="dze-cx-note" style="margin:6px 0;"><?php esc_html_e( 'Quick strategies:', 'dazont-ecom' ); ?></p>
			<p>
				<button type="button" class="button button-small dze-gmca-strat" data-mode="auto" title="<?php esc_attr_e( 'First variation of each distinct image; one per attribute value when no variation has an image.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Auto (by image)', 'dazont-ecom' ); ?></button>
				<button type="button" class="button button-small dze-gmca-strat" data-mode="all" title="<?php esc_attr_e( 'Every variation is unique (e.g. versions) — send them all.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'All variations', 'dazont-ecom' ); ?></button>
				<button type="button" class="button button-small dze-gmca-strat" data-mode="none"><?php esc_html_e( 'None', 'dazont-ecom' ); ?></button>
			</p>
			<?php if ( $attrs ) : ?>
			<p>
				<button type="button" class="button button-small dze-gmca-strat" data-mode="first_attr"><?php esc_html_e( 'First of each', 'dazont-ecom' ); ?></button>
				<select id="dze-gmca-attr" style="max-width:120px;">
					<?php foreach ( $attrs as $a ) : ?>
						<option value="<?php echo esc_attr( sanitize_title( $a ) ); ?>"><?php echo esc_html( wc_attribute_label( $a ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php endif; ?>
		</div>
		<script>
		jQuery( function ( $ ) {
			var pid = <?php echo (int) $post->ID; ?>, nonce = '<?php echo esc_js( $nonce ); ?>';
			function refresh( state ) {
				if ( ! state ) { return; }
				$( '#dze-gmca-parent' ).prop( 'checked', !! state.parent );
				$( '.dze-gmca-v' ).each( function () {
					$( this ).prop( 'checked', !! state.v[ $( this ).val() ] );
				} );
			}
			function note( ok ) {
				$( '#dze-gmca-status' ).text( ok ? '✓ <?php echo esc_js( __( 'Saved', 'dazont-ecom' ) ); ?>' : '✗' );
				setTimeout( function () { $( '#dze-gmca-status' ).text( '' ); }, 1800 );
			}
			$( '#dze-gmca-savesel' ).on( 'click', function () {
				var v = {};
				$( '.dze-gmca-v' ).each( function () { v[ $( this ).val() ] = $( this ).is( ':checked' ) ? 1 : 0; } );
				$.post( window.ajaxurl, { action: 'dze_gmca_save', nonce: nonce, post: pid, parent_on: $( '#dze-gmca-parent' ).is( ':checked' ) ? 1 : 0, v: v } )
					.done( function ( res ) { note( res && res.success ); } )
					.fail( function () { note( false ); } );
			} );
			$( '.dze-gmca-strat' ).on( 'click', function () {
				$.post( window.ajaxurl, { action: 'dze_gmca_strategy', nonce: nonce, post: pid, mode: $( this ).data( 'mode' ), attr: $( '#dze-gmca-attr' ).val() || '' } )
					.done( function ( res ) { if ( res && res.success ) { refresh( res.data ); note( true ); } else { note( false ); } } )
					.fail( function () { note( false ); } );
			} );
		} );
		</script>
		<style>
		.dze-gmca-list { max-height: 260px; overflow: auto; border: 1px solid #e2e4e7; border-radius: 4px; padding: 4px 8px; }
		.dze-gmca-row { display: flex; align-items: center; gap: 8px; padding: 4px 0; border-top: 1px solid #f0f0f1; cursor: pointer; }
		.dze-gmca-row:first-child { border-top: none; }
		.dze-gmca-row img { width: 28px; height: 28px; object-fit: cover; border-radius: 3px; border: 1px solid #dcdcde; }
		.dze-gmca-noimg { width: 28px; height: 28px; line-height: 28px; text-align: center; color: #a7aaad; background: #f6f7f7; border-radius: 3px; }
		.dze-gmca-lbl { font-size: 12px; }
		</style>
		<?php
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	private function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
	}

	/** Current activation state of a product, for refreshing the picker UI. */
	private static function state( WC_Product $product ): array {
		$v = [];
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				$v[ (int) $vid ] = self::is_on( (int) $vid );
			}
		}
		return [ 'parent' => self::is_on( $product->get_id() ), 'v' => $v ];
	}

	public function ajax_save(): void {
		$this->guard();
		$pid     = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		self::set_on( $pid, ! empty( $_POST['parent_on'] ) );
		if ( isset( $_POST['v'] ) && is_array( $_POST['v'] ) && $product->is_type( 'variable' ) ) {
			$children = array_map( 'intval', $product->get_children() );
			foreach ( wp_unslash( $_POST['v'] ) as $vid => $on ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast below.
				$vid = absint( $vid );
				if ( in_array( $vid, $children, true ) ) {
					self::set_on( $vid, ! empty( $on ) );
				}
			}
		}
		wp_send_json_success( self::state( $product ) );
	}

	public function ajax_strategy(): void {
		$this->guard();
		$pid     = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$attr    = isset( $_POST['attr'] ) ? sanitize_title( wp_unslash( $_POST['attr'] ) ) : '';
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		$children = array_map( 'intval', $product->get_children() );
		switch ( $mode ) {
			case 'auto':
				self::auto_mark( $product );
				break;
			case 'all':
				self::set_on( $pid, true );
				foreach ( $children as $vid ) {
					self::set_on( $vid, true );
				}
				break;
			case 'none':
				self::set_on( $pid, false );
				foreach ( $children as $vid ) {
					self::set_on( $vid, false );
				}
				break;
			case 'first_attr':
				if ( '' === $attr ) {
					wp_send_json_error( [ 'message' => __( 'Pick an attribute first.', 'dazont-ecom' ) ] );
				}
				self::mark_first_per_attr( $product, $attr );
				break;
			default:
				wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( self::state( $product ) );
	}

	/** Catalogue-wide auto-mark, batched (offset/limit) for large catalogues. */
	public function ajax_run(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$limit  = 40;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$ids = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'suppress_filters' => false, // WPML: current-language set; translations follow their own run.
		] );
		$marked = 0;
		foreach ( $ids as $pid ) {
			$product = wc_get_product( (int) $pid );
			if ( $product ) {
				self::auto_mark( $product );
				$marked++;
			}
		}
		wp_send_json_success( [
			'processed' => count( $ids ),
			'marked'    => $marked,
			'offset'    => $offset + count( $ids ),
			'done'      => count( $ids ) < $limit,
		] );
	}

	// =========================================================================
	// Settings tab (invoked from the Settings page)
	// =========================================================================

	public function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$counts = wp_count_posts( 'product' );
		$total  = (int) ( $counts->publish ?? 0 );
		?>
		<div class="dze-admin">
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Marks which products and variations are sent to Google Merchant Center (the "_merchant_center_activation" flag your feed already uses). Automatic rules: every simple product and variable parent is enabled; variations with an ORIGINAL image are enabled (first of each distinct image — duplicates skipped); when no variation has its own image, the first variation of each value of the fallback attribute below is enabled. Fine-tune per product from the "GMC activation" box on the product page.', 'dazont-ecom' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_gmca_options' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dze-gmca-fattr"><?php esc_html_e( 'Fallback attribute (no-image case)', 'dazont-ecom' ); ?></label></th>
					<td>
						<input type="text" id="dze-gmca-fattr" name="<?php echo esc_attr( self::OPT ); ?>[fallback_attr]" value="<?php echo esc_attr( self::fallback_attr() ); ?>" class="regular-text" placeholder="pa_color" />
						<p class="description"><?php esc_html_e( 'Attribute slug used to keep one variation per value when no variation has an image (default: pa_color — one per colour).', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save GMC activation settings', 'dazont-ecom' ) ); ?>
		</form>
		<hr />
		<h2><?php esc_html_e( 'Mark the whole catalogue', 'dazont-ecom' ); ?></h2>
		<p class="description"><?php printf( /* translators: %s: product count */ esc_html__( 'Applies the automatic rules to all %s published products (existing manual choices on variations are overwritten — refine the tricky ones afterwards from their product page).', 'dazont-ecom' ), number_format_i18n( $total ) ); ?></p>
		<p>
			<button type="button" class="button button-primary" id="dze-gmca-runall"><?php esc_html_e( 'Run automatic marking', 'dazont-ecom' ); ?></button>
		</p>
		<div id="dze-gmca-bar" style="display:none;max-width:480px;height:10px;background:#e2e4e7;border-radius:5px;overflow:hidden;"><div id="dze-gmca-fill" style="height:100%;width:0;background:#2271b1;transition:width .3s;"></div></div>
		<p id="dze-gmca-progress" class="description"></p>
		<script>
		jQuery( function ( $ ) {
			var total = <?php echo (int) $total; ?>;
			$( '#dze-gmca-runall' ).on( 'click', function () {
				if ( ! window.confirm( '<?php echo esc_js( __( 'Apply the automatic marking to the whole catalogue? Manual variation choices will be overwritten.', 'dazont-ecom' ) ); ?>' ) ) { return; }
				var $btn = $( this ).prop( 'disabled', true );
				$( '#dze-gmca-bar' ).show();
				var done = 0;
				function step( offset ) {
					$.post( window.ajaxurl, { action: 'dze_gmca_run', nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>', offset: offset } )
						.done( function ( res ) {
							if ( ! res || ! res.success ) {
								$( '#dze-gmca-progress' ).text( ( res && res.data && res.data.message ) || 'Error' );
								$btn.prop( 'disabled', false );
								return;
							}
							done += res.data.processed;
							$( '#dze-gmca-fill' ).css( 'width', Math.min( 100, total ? Math.round( 100 * done / total ) : 100 ) + '%' );
							$( '#dze-gmca-progress' ).text( done + ' / ' + total );
							if ( res.data.done ) {
								$( '#dze-gmca-fill' ).css( 'width', '100%' );
								$( '#dze-gmca-progress' ).text( '✓ ' + done + ' <?php echo esc_js( __( 'products marked.', 'dazont-ecom' ) ); ?>' );
								$btn.prop( 'disabled', false );
							} else {
								step( res.data.offset );
							}
						} )
						.fail( function () { $( '#dze-gmca-progress' ).text( 'Error' ); $btn.prop( 'disabled', false ); } );
				}
				step( 0 );
			} );
		} );
		</script>
		</div>
		<?php
	}
}
