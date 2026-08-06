<?php
defined( 'ABSPATH' ) || exit;

/**
 * Variation Split (PROTOTYPE) — turns a chosen variation attribute of a variable
 * product into standalone products, one per term (e.g. split a t-shirt "by
 * colour" so each colour becomes its own product, independently searchable and
 * rankable in SEO).
 *
 * Deliberately conservative for a first version:
 *   - new products are created as DRAFT, never published automatically;
 *   - the source product is left untouched;
 *   - each new product copies the description, categories and gallery, takes the
 *     representative variation's price and image, and carries the term as a
 *     fixed (non-variation) attribute — assigned to the taxonomy too when the
 *     attribute is a global one, so layered nav / filters keep working.
 *
 * This is an ébauche to iterate on (SKU handling, stock, per-term overrides,
 * bulk mode… to come).
 */
final class DZE_Variation_Split {

	private const NONCE = 'dze_vsplit';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_dze_vsplit_preview', [ $this, 'ajax_preview' ] );
		add_action( 'wp_ajax_dze_vsplit_run',     [ $this, 'ajax_run' ] );
	}

	public function add_meta_box(): void {
		add_meta_box( 'dze-vsplit-box', __( 'Split by variation (prototype)', 'dazont-ecom' ), [ $this, 'render_meta_box' ], 'product', 'side', 'default' );
	}

	public function render_meta_box( $post ): void {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			echo '<p class="description">' . esc_html__( 'Available on variable products only. Add attributes used for variations, then reopen.', 'dazont-ecom' ) . '</p>';
			return;
		}
		$attrs = $product->get_variation_attributes();
		if ( empty( $attrs ) ) {
			echo '<p class="description">' . esc_html__( 'This product has no variation attributes.', 'dazont-ecom' ) . '</p>';
			return;
		}
		?>
		<p class="description"><?php esc_html_e( 'Create one standalone DRAFT product per value of a chosen attribute. The original product is not changed.', 'dazont-ecom' ); ?></p>
		<p>
			<label for="dze-vsplit-attr"><strong><?php esc_html_e( 'Split by', 'dazont-ecom' ); ?></strong></label><br />
			<select id="dze-vsplit-attr" style="width:100%;">
				<?php foreach ( array_keys( $attrs ) as $key ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( wc_attribute_label( $key ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<button type="button" class="button" id="dze-vsplit-preview"><?php esc_html_e( 'Preview', 'dazont-ecom' ); ?></button>
			<button type="button" class="button button-primary" id="dze-vsplit-run" style="display:none;"><?php esc_html_e( 'Create draft products', 'dazont-ecom' ); ?></button>
		</p>
		<div id="dze-vsplit-out"></div>
		<?php
	}

	public function enqueue( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type || ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		wp_enqueue_script( 'dze-vsplit', DZE_URL . 'admin/js/variation-split.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-vsplit', 'dzeVSplit', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'postId'  => (int) get_the_ID(),
			'i18n'    => [
				'working' => __( 'Working…', 'dazont-ecom' ),
				'error'   => __( 'Something went wrong.', 'dazont-ecom' ),
				'confirm' => __( 'Create %d draft product(s)?', 'dazont-ecom' ),
				'willMake'=> __( 'Will create these draft products:', 'dazont-ecom' ),
				'done'    => __( 'Created:', 'dazont-ecom' ),
			],
		] );
	}

	private function guard(): WC_Product_Variable {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$pid     = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product instanceof WC_Product_Variable ) {
			wp_send_json_error( [ 'message' => __( 'Not a variable product.', 'dazont-ecom' ) ] );
		}
		return $product;
	}

	private function attr_key(): string {
		return isset( $_POST['attr'] ) ? (string) wp_unslash( $_POST['attr'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran.
	}

	/** [ value => label ] for the chosen attribute, in the product's declared order. */
	private function values_for( WC_Product_Variable $product, string $attr_key ): array {
		$attrs = $product->get_variation_attributes();
		if ( ! isset( $attrs[ $attr_key ] ) ) {
			return [];
		}
		$is_tax = taxonomy_exists( $attr_key );
		$out    = [];
		foreach ( (array) $attrs[ $attr_key ] as $val ) {
			if ( '' === $val ) {
				continue;
			}
			if ( $is_tax ) {
				$term = get_term_by( 'slug', $val, $attr_key );
				$out[ $val ] = $term instanceof WP_Term ? $term->name : $val;
			} else {
				$out[ $val ] = $val;
			}
		}
		return $out;
	}

	public function ajax_preview(): void {
		$product = $this->guard();
		$key     = $this->attr_key();
		$values  = $this->values_for( $product, $key );
		if ( empty( $values ) ) {
			wp_send_json_error( [ 'message' => __( 'No values found for this attribute.', 'dazont-ecom' ) ] );
		}
		$base  = $product->get_name();
		$items = [];
		foreach ( $values as $label ) {
			$items[] = $base . ' - ' . $label;
		}
		wp_send_json_success( [ 'items' => $items ] );
	}

	public function ajax_run(): void {
		$product = $this->guard();
		$key     = $this->attr_key();
		$values  = $this->values_for( $product, $key );
		if ( empty( $values ) ) {
			wp_send_json_error( [ 'message' => __( 'No values found for this attribute.', 'dazont-ecom' ) ] );
		}

		// Representative variation per value (for price + image).
		$rep = [];
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v instanceof WC_Product_Variation ) {
				continue;
			}
			$va  = $v->get_attributes();
			$val = $va[ $key ] ?? '';
			if ( '' !== $val && ! isset( $rep[ $val ] ) ) {
				$rep[ $val ] = $v;
			}
		}

		$is_tax  = taxonomy_exists( $key );
		$tax_id  = $is_tax ? wc_attribute_taxonomy_id_by_name( $key ) : 0;
		$created = [];
		foreach ( $values as $val => $label ) {
			$v   = $rep[ $val ] ?? null;
			$new = new WC_Product_Simple();
			$new->set_name( $product->get_name() . ' - ' . $label );
			$new->set_status( 'draft' );
			$new->set_description( $product->get_description() );
			$new->set_short_description( $product->get_short_description() );
			$new->set_category_ids( $product->get_category_ids() );
			$new->set_tag_ids( $product->get_tag_ids() );

			$regular = $v ? ( $v->get_regular_price() ?: $v->get_price() ) : ( $product->get_regular_price() ?: $product->get_price() );
			if ( '' !== (string) $regular ) {
				$new->set_regular_price( (string) $regular );
			}
			$img = ( $v && $v->get_image_id() ) ? $v->get_image_id() : $product->get_image_id();
			if ( $img ) {
				$new->set_image_id( $img );
			}
			$new->set_gallery_image_ids( $product->get_gallery_image_ids() );

			// The term as a fixed, visible attribute (not a variation).
			$attribute = new WC_Product_Attribute();
			$term      = null;
			if ( $is_tax ) {
				$term = get_term_by( 'slug', $val, $key );
				$attribute->set_id( (int) $tax_id );
				$attribute->set_name( $key );
				$attribute->set_options( $term instanceof WP_Term ? [ $term->term_id ] : [] );
			} else {
				$attribute->set_name( $key );
				$attribute->set_options( [ $label ] );
			}
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$new->set_attributes( [ $attribute ] );

			$new_id = $new->save();
			if ( $new_id && $is_tax && $term instanceof WP_Term ) {
				wp_set_object_terms( (int) $new_id, [ (int) $term->term_id ], $key, false );
			}
			if ( $new_id ) {
				$created[] = [
					'id'    => (int) $new_id,
					'title' => get_the_title( (int) $new_id ),
					'edit'  => get_edit_post_link( (int) $new_id, '' ),
				];
			}
		}

		wp_send_json_success( [ 'created' => $created ] );
	}
}
