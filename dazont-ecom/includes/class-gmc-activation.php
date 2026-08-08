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
		// Products list: the GMC icon opens the same panel in a popup.
		add_action( 'wp_ajax_dze_gmca_panel', [ $this, 'ajax_panel' ] );
		add_action( 'admin_footer-edit.php', [ $this, 'list_modal' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/** The popup needs the shared modal styles — enqueued from the right hook. */
	public function enqueue( string $hook ): void {
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_list = $screen && 'product' === $screen->post_type && 'edit' === $screen->base;
		if ( ! $on_list && false === strpos( (string) $hook, 'dazont' ) ) {
			return;
		}
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		wp_enqueue_script( 'dze-hzoom', DZE_URL . 'admin/js/hzoom.js', [ 'jquery' ], DZE_VERSION, true );
	}

	// =========================================================================
	// WPML helpers — the activation flag is a PRODUCT decision, not a
	// language decision: every write is mirrored to all translations, and the
	// catalogue run walks original-language products only.
	// =========================================================================

	private static function wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	/** Original-language published product ids (all products when WPML is absent). */
	private static function original_product_ids( int $offset, int $limit ): array {
		if ( self::wpml_active() ) {
			global $wpdb;
			return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->prefix}icl_translations t
				 ON t.element_id = p.ID AND t.element_type = 'post_product'
				 WHERE p.post_type = 'product' AND p.post_status = 'publish'
				 AND t.source_language_code IS NULL
				 ORDER BY p.ID ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			) ) );
		}
		return get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'posts_per_page' => $limit,
			'offset'         => $offset,
		] );
	}

	/** How many products the catalogue run will process (originals only). */
	public static function original_count(): int {
		if ( self::wpml_active() ) {
			global $wpdb;
			return (int) $wpdb->get_var(
				"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->prefix}icl_translations t
				 ON t.element_id = p.ID AND t.element_type = 'post_product'
				 WHERE p.post_type = 'product' AND p.post_status = 'publish'
				 AND t.source_language_code IS NULL"
			);
		}
		$counts = wp_count_posts( 'product' );
		return (int) ( $counts->publish ?? 0 );
	}

	// =========================================================================
	// Meta helpers
	// =========================================================================

	private static function is_on( int $post_id ): bool {
		return 'yes' === get_post_meta( $post_id, self::META, true );
	}

	private static function write_flag( int $post_id, bool $on ): void {
		if ( $on ) {
			update_post_meta( $post_id, self::META, 'yes' );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}

	/** Sets the flag on a product/variation AND on every WPML translation of it. */
	private static function set_on( int $post_id, bool $on ): void {
		self::write_flag( $post_id, $on );
		$langs = apply_filters( 'wpml_active_languages', null );
		if ( empty( $langs ) || ! is_array( $langs ) ) {
			return;
		}
		$type = get_post_type( $post_id ) ?: 'product';
		foreach ( $langs as $lang ) {
			$code = (string) ( $lang['code'] ?? '' );
			if ( '' === $code ) {
				continue;
			}
			$tid = apply_filters( 'wpml_object_id', $post_id, $type, false, $code );
			if ( $tid && (int) $tid !== $post_id ) {
				self::write_flag( (int) $tid, $on );
			}
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
		$data  = self::status_data( $product );
		$label = $data['active']
			? '<span style="color:#16a34a;font-size:16px;font-weight:bold;">&#10004;</span>'
				. ( $product->is_type( 'variable' ) && $data['active_variations'] > 0 ? ' <span>(' . (int) $data['active_variations'] . ')</span>' : '' )
			: '<span style="color:#dc2626;font-size:16px;font-weight:bold;">&#10008;</span>';
		// Clickable: opens the activation panel for this product without leaving the list.
		printf(
			'<button type="button" class="dze-gmca-open" data-id="%1$d" title="%2$s">%3$s<span class="dze-caret">&#9662;</span></button>',
			(int) $post_id,
			esc_attr__( 'Click to choose what is sent to Merchant Center', 'dazont-ecom' ),
			$label // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above.
		);
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
	 * The colour-like variation attribute of THIS product (else its first
	 * variation attribute) — used to keep one variation per value when no
	 * variation has an image. Auto-detected, nothing to configure.
	 */
	private static function pick_fallback_attr( WC_Product $product ): string {
		$attrs = array_keys( (array) $product->get_variation_attributes() );
		if ( empty( $attrs ) ) {
			return '';
		}
		foreach ( $attrs as $a ) {
			$slug = sanitize_title( (string) $a );
			if ( preg_match( '/colou?r|couleur/', $slug ) ) {
				return $slug;
			}
		}
		return sanitize_title( (string) $attrs[0] );
	}

	/**
	 * Applies the automatic rules to ONE product and returns the enabled ids.
	 * Simple: the product itself. Variable: parent + the picked variations —
	 * first of each distinct variation image; when NO variation has its own
	 * image, first of each value of the product's colour attribute (or its
	 * first variation attribute). Every other variation is switched off.
	 */
	public static function auto_mark( WC_Product $product ): array {
		$fallback_attr = self::pick_fallback_attr( $product );
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
			// No variation image at all → one per value of the colour-like attribute.
			$by_value = [];
			foreach ( $children as $vid ) {
				$val = '' !== $fallback_attr ? (string) get_post_meta( $vid, 'attribute_' . $fallback_attr, true ) : '';
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
	// The activation panel — one renderer used by the product page (hub popup)
	// and by the products list (clickable GMC icon).
	// =========================================================================

	/**
	 * Full panel for ONE product: automatic choices at the top, manual
	 * variation picking below. Works for simple and variable products.
	 */
	public function render_panel( int $post_id ): void {
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}
		$variable = $product->is_type( 'variable' );
		$rows     = [];
		$on_count = 0;
		if ( $variable ) {
			foreach ( $product->get_children() as $vid ) {
				$v = wc_get_product( (int) $vid );
				if ( ! $v ) {
					continue;
				}
				$img    = (int) get_post_meta( (int) $vid, '_thumbnail_id', true );
				$is_on  = self::is_on( (int) $vid );
				$on_count += $is_on ? 1 : 0;
				$rows[] = [
					'id'    => (int) $vid,
					'on'    => $is_on,
					'thumb' => $img ? (string) wp_get_attachment_image_url( $img, 'thumbnail' ) : '',
					'full'  => $img ? (string) wp_get_attachment_image_url( $img, 'large' ) : '',
					'label' => wc_get_formatted_variation( $v, true, false ),
				];
			}
		}
		$attrs = $variable ? array_keys( (array) $product->get_variation_attributes() ) : [];
		?>
		<div class="dze-admin dze-gmca-box" data-post="<?php echo (int) $post_id; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
			<p class="dze-gmca-intro">
				<?php esc_html_e( 'What is sent to Google Merchant Center for this product. The rule: one entry per real photo — never the same photo twice. The parent product is always sent.', 'dazont-ecom' ); ?>
			</p>

			<?php if ( ! $variable ) : ?>
				<label class="dze-gmca-main">
					<input type="checkbox" class="dze-gmca-parent" <?php checked( self::is_on( $post_id ) ); ?> />
					<strong><?php esc_html_e( 'Send this product to Merchant Center', 'dazont-ecom' ); ?></strong>
				</label>
				<p><button type="button" class="button button-primary button-small dze-gmca-savesel"><?php esc_html_e( 'Save', 'dazont-ecom' ); ?></button>
				<span class="dze-gmca-status"></span></p>
			<?php else : ?>

				<p class="dze-gmca-sum">
					<?php
					printf(
						/* translators: 1: active variations, 2: total variations */
						esc_html__( 'Sent right now: %1$s of %2$s variations', 'dazont-ecom' ),
						'<strong class="dze-gmca-count">' . (int) $on_count . '</strong>',
						(int) count( $rows )
					);
					?>
				</p>

				<div class="dze-gmca-sec">
					<h4><?php esc_html_e( '1. Let the plugin choose', 'dazont-ecom' ); ?></h4>

					<div class="dze-gmca-choice">
						<button type="button" class="button button-primary button-small dze-gmca-strat" data-mode="auto"><?php esc_html_e( 'One per photo', 'dazont-ecom' ); ?></button>
						<span class="dze-gmca-help"><?php esc_html_e( 'Recommended. Keeps one variation for each different photo; variations repeating a photo are dropped. If no variation has its own photo, keeps one per colour.', 'dazont-ecom' ); ?></span>
					</div>

					<div class="dze-gmca-choice">
						<button type="button" class="button button-small dze-gmca-strat" data-mode="all"><?php esc_html_e( 'Every variation', 'dazont-ecom' ); ?></button>
						<span class="dze-gmca-help"><?php esc_html_e( 'For products where each variation is really a different item with its own photo (e.g. a "version" or "model" attribute).', 'dazont-ecom' ); ?></span>
					</div>

					<?php if ( $attrs ) : ?>
					<div class="dze-gmca-choice">
						<button type="button" class="button button-small dze-gmca-strat" data-mode="first_attr"><?php esc_html_e( 'One per value of…', 'dazont-ecom' ); ?></button>
						<select class="dze-gmca-attr">
							<?php foreach ( $attrs as $a ) : ?>
								<option value="<?php echo esc_attr( sanitize_title( (string) $a ) ); ?>"><?php echo esc_html( wc_attribute_label( (string) $a ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="dze-gmca-help">
							<?php esc_html_e( 'Keeps the first variation of each value of the chosen product attribute. Example: attribute "Colour" on a t-shirt in 3 colours × 5 sizes → 3 entries, one per colour.', 'dazont-ecom' ); ?>
						</span>
					</div>
					<?php endif; ?>

					<div class="dze-gmca-choice">
						<button type="button" class="button button-small dze-gmca-strat" data-mode="none"><?php esc_html_e( 'Nothing', 'dazont-ecom' ); ?></button>
						<span class="dze-gmca-help"><?php esc_html_e( 'Removes this product and all its variations from Merchant Center.', 'dazont-ecom' ); ?></span>
					</div>
					<p class="dze-gmca-help" style="margin:10px 0 0;"><?php esc_html_e( 'These choices are rules applied to your data — no AI involved, same result every time.', 'dazont-ecom' ); ?></p>
				</div>

				<div class="dze-gmca-sec">
					<h4><?php esc_html_e( '2. Or pick them by hand', 'dazont-ecom' ); ?></h4>
					<p class="dze-gmca-help" style="margin:0 0 8px;"><?php esc_html_e( 'For special cases (e.g. a rug where only one size matches the photo). Hover a thumbnail to enlarge it.', 'dazont-ecom' ); ?></p>
					<div class="dze-gmca-list">
						<?php foreach ( $rows as $r ) : ?>
							<label class="dze-gmca-row">
								<input type="checkbox" class="dze-gmca-v" value="<?php echo (int) $r['id']; ?>" <?php checked( $r['on'] ); ?> />
								<?php if ( $r['thumb'] ) : ?>
									<img class="dze-hzoom" src="<?php echo esc_url( $r['thumb'] ); ?>" data-full="<?php echo esc_url( $r['full'] ?: $r['thumb'] ); ?>" alt="" />
								<?php else : ?>
									<span class="dze-gmca-noimg" title="<?php esc_attr_e( 'This variation has no photo of its own', 'dazont-ecom' ); ?>">—</span>
								<?php endif; ?>
								<span class="dze-gmca-lbl"><?php echo esc_html( $r['label'] ?: ( '#' . $r['id'] ) ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<label class="dze-gmca-main" style="margin-top:8px;">
						<input type="checkbox" class="dze-gmca-parent" <?php checked( self::is_on( $post_id ) ); ?> />
						<?php esc_html_e( 'Also send the parent product', 'dazont-ecom' ); ?>
					</label>
					<p>
						<button type="button" class="button button-primary button-small dze-gmca-savesel"><?php esc_html_e( 'Save selection', 'dazont-ecom' ); ?></button>
						<span class="dze-gmca-status"></span>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Styles + delegated handlers, printed once per screen (AJAX-loaded panels included). */
	public static function print_panel_assets(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
		<style>
		.dze-gmca-box { font-size: 13px; }
		.dze-gmca-intro { margin: 0 0 10px; color: #50575e; }
		.dze-gmca-sum { margin: 0 0 12px; padding: 6px 10px; background: #f6f7f7; border-radius: 4px; }
		.dze-gmca-sec { border: 1px solid #e2e4e7; border-radius: 6px; padding: 12px 14px; margin-bottom: 12px; }
		.dze-gmca-sec h4 { margin: 0 0 10px; font-size: 13px; }
		.dze-gmca-choice { display: grid; grid-template-columns: auto 1fr; gap: 4px 10px; align-items: start; margin-bottom: 10px; }
		.dze-gmca-choice select { grid-column: 1; margin-top: 4px; max-width: 160px; }
		.dze-gmca-choice .dze-gmca-help { grid-column: 2; }
		.dze-gmca-help { color: #646970; font-size: 12px; line-height: 1.5; }
		.dze-gmca-main { display: block; margin: 6px 0; }
		.dze-gmca-list { max-height: 260px; overflow: auto; border: 1px solid #e2e4e7; border-radius: 4px; padding: 4px 8px; }
		.dze-gmca-row { display: flex; align-items: center; gap: 8px; padding: 4px 0; border-top: 1px solid #f0f0f1; cursor: pointer; }
		.dze-gmca-row:first-child { border-top: none; }
		.dze-gmca-row img { width: 28px; height: 28px; object-fit: cover; border-radius: 3px; border: 1px solid #dcdcde; }
		.dze-gmca-noimg { width: 28px; height: 28px; line-height: 28px; text-align: center; color: #a7aaad; background: #f6f7f7; border-radius: 3px; }
		.dze-gmca-lbl { font-size: 12px; }
		.dze-gmca-status { font-size: 12px; margin-left: 6px; }
		.dze-gmca-open, .dze-rev-open {
			display: inline-flex; align-items: center; gap: 5px; cursor: pointer;
			border: 1px solid #dcdcde; border-radius: 4px; background: #fff;
			padding: 2px 8px; line-height: 1.8; font-size: 13px;
		}
		.dze-gmca-open:hover, .dze-rev-open:hover { border-color: #2271b1; background: #f0f6fc; box-shadow: 0 0 0 1px #2271b1; }
		.dze-gmca-open .dze-caret, .dze-rev-open .dze-caret { color: #a7aaad; font-size: 10px; }
		.dze-gmca-open:hover .dze-caret, .dze-rev-open:hover .dze-caret { color: #2271b1; }
		</style>
		<script>
		jQuery( function ( $ ) {
			function box( el ) { return $( el ).closest( '.dze-gmca-box' ); }
			function note( $b, ok ) {
				$b.find( '.dze-gmca-status' ).css( 'color', ok ? '#0a7040' : '#b32d2e' ).text( ok ? '✓' : '✗' );
				setTimeout( function () { $b.find( '.dze-gmca-status' ).text( '' ); }, 1800 );
			}
			function refresh( $b, state ) {
				if ( ! state ) { return; }
				$b.find( '.dze-gmca-parent' ).prop( 'checked', !! state.parent );
				var n = 0;
				$b.find( '.dze-gmca-v' ).each( function () {
					var on = !! state.v[ $( this ).val() ];
					$( this ).prop( 'checked', on );
					if ( on ) { n++; }
				} );
				$b.find( '.dze-gmca-count' ).text( n );
			}
			$( document ).on( 'click', '.dze-gmca-savesel', function () {
				var $b = box( this ), v = {};
				$b.find( '.dze-gmca-v' ).each( function () { v[ $( this ).val() ] = $( this ).is( ':checked' ) ? 1 : 0; } );
				$.post( window.ajaxurl, {
					action: 'dze_gmca_save', nonce: $b.data( 'nonce' ), post: $b.data( 'post' ),
					parent_on: $b.find( '.dze-gmca-parent' ).is( ':checked' ) ? 1 : 0, v: v
				} )
					.done( function ( res ) { if ( res && res.success ) { refresh( $b, res.data ); } note( $b, res && res.success ); } )
					.fail( function () { note( $b, false ); } );
			} );
			$( document ).on( 'click', '.dze-gmca-strat', function () {
				var $b = box( this );
				$.post( window.ajaxurl, {
					action: 'dze_gmca_strategy', nonce: $b.data( 'nonce' ), post: $b.data( 'post' ),
					mode: $( this ).data( 'mode' ), attr: $b.find( '.dze-gmca-attr' ).val() || ''
				} )
					.done( function ( res ) { if ( res && res.success ) { refresh( $b, res.data ); note( $b, true ); } else { note( $b, false ); } } )
					.fail( function () { note( $b, false ); } );
			} );
		} );
		</script>
		<?php
	}

	/** Popup shell printed in the footer of product screens; opened from the hub. */
	public function footer_modal(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}
		global $post;
		if ( ! $post || ! wc_get_product( $post->ID ) ) {
			return;
		}
		echo '<div class="dze-cx-modal" id="dze-gmca-modal"><div class="dze-cx-dialog" style="width:min(600px,94vw);">';
		echo '<div class="dze-cx-head"><h2>' . esc_html__( 'GMC activation', 'dazont-ecom' ) . '</h2><button type="button" class="button dze-hub-close" style="margin-left:auto;">' . esc_html__( 'Close', 'dazont-ecom' ) . '</button></div>';
		echo '<div class="dze-cx-body">';
		$this->render_panel( (int) $post->ID );
		echo '</div></div></div>';
		self::print_panel_assets();
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

	/** Panel HTML for one product — feeds the products-list popup. */
	public function ajax_panel(): void {
		$this->guard();
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		if ( ! $pid || ! wc_get_product( $pid ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		ob_start();
		$this->render_panel( $pid );
		wp_send_json_success( [ 'html' => ob_get_clean(), 'title' => get_the_title( $pid ) ] );
	}

	/** Popup shell on the products list; filled by ajax_panel on icon click. */
	public function list_modal(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}
		self::print_panel_assets(); // styles + delegated save/strategy handlers.
		?>
		<div class="dze-cx-modal" id="dze-gmca-listmodal"><div class="dze-cx-dialog" style="width:min(600px,94vw);">
			<div class="dze-cx-head"><h2 id="dze-gmca-listtitle"><?php esc_html_e( 'GMC activation', 'dazont-ecom' ); ?></h2>
				<button type="button" class="button dze-hub-close" style="margin-left:auto;"><?php esc_html_e( 'Close', 'dazont-ecom' ); ?></button></div>
			<div class="dze-cx-body" id="dze-gmca-listbody"></div>
		</div></div>
		<script>
		jQuery( function ( $ ) {
			var $m = $( '#dze-gmca-listmodal' );
			$( document ).on( 'click', '.dze-gmca-open', function () {
				var id = $( this ).data( 'id' );
				$( '#dze-gmca-listbody' ).html( '<p><span class="dze-cx-spin"></span></p>' );
				$m.addClass( 'is-open' );
				$.post( window.ajaxurl, { action: 'dze_gmca_panel', nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>', post: id } )
					.done( function ( res ) {
						if ( res && res.success ) {
							$( '#dze-gmca-listtitle' ).text( res.data.title );
							$( '#dze-gmca-listbody' ).html( res.data.html );
						} else {
							$( '#dze-gmca-listbody' ).text( ( res && res.data && res.data.message ) || 'Error' );
						}
					} )
					.fail( function () { $( '#dze-gmca-listbody' ).text( 'Error' ); } );
			} );
			$( document ).on( 'click', '.dze-hub-close', function () { $( this ).closest( '.dze-cx-modal' ).removeClass( 'is-open' ); } );
			$m.on( 'click', function ( e ) { if ( e.target === this ) { $m.removeClass( 'is-open' ); } } );
			// Hover zoom on the variation thumbnails: admin/js/hzoom.js, shared
			// with every other screen that shows thumbnails.
		} );
		</script>
		<?php
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
		// WPML: originals only — every flag write is mirrored to translations.
		$ids = self::original_product_ids( $offset, $limit );
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
		$total = self::original_count();
		?>
		<div class="dze-admin">
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Decides which products and variations are sent to Google Merchant Center. The goal: ONE entry per real product picture — never the same photo twice.', 'dazont-ecom' ); ?>
		</p>
		<ul style="max-width:880px;list-style:disc;padding-left:20px;">
			<li><?php esc_html_e( 'Simple products and variable parents: always sent.', 'dazont-ecom' ); ?></li>
			<li><?php esc_html_e( 'Variations with their own photo: sent once per distinct photo (duplicates skipped).', 'dazont-ecom' ); ?></li>
			<li><?php esc_html_e( 'Variations without any photo: one per colour (the product\'s colour attribute is detected automatically; its first attribute is used when there is no colour).', 'dazont-ecom' ); ?></li>
		</ul>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Special cases (e.g. a rug where only one size matches the photo) are refined product by product: "GMC activation" button in the Dazont Ecom box on the product page.', 'dazont-ecom' ); ?>
			<?php if ( self::wpml_active() ) : ?>
				<br /><strong>WPML:</strong> <?php esc_html_e( 'the run walks original-language products only, and every choice (automatic or manual) is copied to all translations — one decision per product, whatever the language.', 'dazont-ecom' ); ?>
			<?php endif; ?>
		</p>
		<hr />
		<h2><?php esc_html_e( 'Mark the whole catalogue', 'dazont-ecom' ); ?></h2>
		<p class="description"><?php printf( /* translators: %s: product count */ esc_html__( 'Applies the rules above to the %s products of the catalogue. Existing manual variation choices are overwritten — refine the tricky ones afterwards.', 'dazont-ecom' ), number_format_i18n( $total ) ); ?></p>
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
