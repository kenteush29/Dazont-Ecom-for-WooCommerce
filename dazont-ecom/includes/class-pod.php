<?php
defined( 'ABSPATH' ) || exit;

/**
 * POD (print on demand) — a SEPARATE module for one specific case and nothing
 * else: the admin uploads a design (PNG) on a product, an optional base mockup
 * is stored once in the settings, and ONE dedicated prompt asks fal.ai to
 * print the design on the product. It only borrows the fal client from the
 * Content module (single implementation, single cost tracking); it never
 * touches the generic AI Content pipeline, registry or validation flow.
 */
final class DZE_Pod {

	private const OPT   = 'dze_pod_settings';
	private const NONCE = 'dze_pod';

	/** Product meta key holding the design attachment id. */
	public const DESIGN_META = '_dze_pod_design_id';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_dze_pod_design', [ $this, 'ajax_design' ] );
		add_action( 'wp_ajax_dze_pod_generate', [ $this, 'ajax_generate' ] );
		add_action( 'wp_ajax_dze_pod_save_prompt', [ $this, 'ajax_save_prompt' ] );
		add_action( 'wp_ajax_dze_pod_attach', [ $this, 'ajax_attach' ] );
	}

	// =========================================================================
	// Settings (own option, own tab on the Settings page)
	// =========================================================================

	public static function get_settings(): array {
		$s = get_option( self::OPT, [] );
		return is_array( $s ) ? $s : [];
	}

	public function register_settings(): void {
		register_setting( 'dze_pod_options', self::OPT, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize' ],
			'default'           => [],
		] );
	}

	public function sanitize( $in ): array {
		$in  = is_array( $in ) ? $in : [];
		$out = self::get_settings();
		if ( isset( $in['mockup_id'] ) ) {
			$out['mockup_id'] = absint( $in['mockup_id'] );
		}
		if ( isset( $in['prompt'] ) ) {
			$out['prompt'] = sanitize_textarea_field( (string) $in['prompt'] );
		}
		return $out;
	}

	/** Stored base mockup attachment id, 0 = none (product image used instead). */
	public static function mockup_id(): int {
		return absint( self::get_settings()['mockup_id'] ?? 0 );
	}

	public static function default_prompt(): string {
		return <<<'PROMPT'
Tu reçois deux images : (1) le produit de base (mockup) et (2) un design PNG. Applique fidèlement le design sur le produit : centré sur la zone d'impression, proportions réalistes, en respectant les plis, la matière et l'éclairage du support. Ne modifie JAMAIS les couleurs, les détails ni le texte du design. Rendu photo e-commerce professionnel, fond neutre uni, produit entier visible.
PROMPT;
	}

	/** The POD prompt (editable; empty = shipped default). */
	public static function prompt(): string {
		$p = trim( (string) ( self::get_settings()['prompt'] ?? '' ) );
		return '' !== $p ? $p : self::default_prompt();
	}

	/** Settings tab body (invoked from the Settings page). */
	public function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$mid   = self::mockup_id();
		$thumb = $mid ? wp_get_attachment_image_url( $mid, 'medium' ) : '';
		?>
		<div class="dze-admin dze-pod-settings">
		<p class="description" style="max-width:860px;">
			<?php esc_html_e( 'Print on demand: upload a design (PNG) on any product, then one dedicated prompt asks the AI to print it on your base product. Store your base mockup here once — when none is set, the product\'s own featured image is used as the base.', 'dazont-ecom' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_pod_options' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Base mockup', 'dazont-ecom' ); ?></th>
					<td>
						<div id="dze-pod-mockup-preview" style="margin-bottom:8px;<?php echo $thumb ? '' : 'display:none;'; ?>">
							<img src="<?php echo esc_url( $thumb ); ?>" alt="" style="max-width:180px;height:auto;border:1px solid #dcdcde;border-radius:4px;" />
						</div>
						<input type="hidden" id="dze-pod-mockup-id" name="<?php echo esc_attr( self::OPT ); ?>[mockup_id]" value="<?php echo (int) $mid; ?>" />
						<button type="button" class="button" id="dze-pod-mockup-pick"><?php esc_html_e( 'Choose mockup', 'dazont-ecom' ); ?></button>
						<button type="button" class="button" id="dze-pod-mockup-clear" <?php echo $mid ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'dazont-ecom' ); ?></button>
						<p class="description"><?php esc_html_e( 'A clean photo of the blank product (e.g. plain t-shirt), neutral background. Used as image #1 in every POD generation.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-pod-prompt"><?php esc_html_e( 'POD prompt', 'dazont-ecom' ); ?></label></th>
					<td>
						<textarea id="dze-pod-prompt" name="<?php echo esc_attr( self::OPT ); ?>[prompt]" rows="5" class="large-text code" placeholder="<?php echo esc_attr( self::default_prompt() ); ?>"><?php echo esc_textarea( (string) ( self::get_settings()['prompt'] ?? '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Leave empty to keep the shipped default (shown greyed). The design and the mockup are always attached as images; this prompt tells the AI how to print one on the other.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save POD settings', 'dazont-ecom' ) ); ?>
		</form>
		</div>
		<script>
		jQuery( function ( $ ) {
			var frame = null;
			$( '#dze-pod-mockup-pick' ).on( 'click', function ( e ) {
				e.preventDefault();
				if ( ! frame ) {
					frame = wp.media( { title: '<?php echo esc_js( __( 'Choose the base mockup', 'dazont-ecom' ) ); ?>', library: { type: 'image' }, multiple: false } );
					frame.on( 'select', function () {
						var att = frame.state().get( 'selection' ).first().toJSON();
						$( '#dze-pod-mockup-id' ).val( att.id );
						$( '#dze-pod-mockup-preview' ).show().find( 'img' ).attr( 'src', ( att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url ) );
						$( '#dze-pod-mockup-clear' ).show();
					} );
				}
				frame.open();
			} );
			$( '#dze-pod-mockup-clear' ).on( 'click', function () {
				$( '#dze-pod-mockup-id' ).val( 0 );
				$( '#dze-pod-mockup-preview' ).hide();
				$( this ).hide();
			} );
		} );
		</script>
		<?php
	}

	// =========================================================================
	// Product page: own side box
	// =========================================================================

	public function add_meta_box(): void {
		add_meta_box( 'dze-pod-side', __( 'POD image (Dazont)', 'dazont-ecom' ), [ $this, 'render_box' ], 'product', 'side', 'default' );
	}

	public function render_box( $post ): void {
		$design = absint( get_post_meta( $post->ID, self::DESIGN_META, true ) );
		$thumb  = $design ? wp_get_attachment_image_url( $design, 'thumbnail' ) : '';
		?>
		<div class="dze-admin dze-pod-box" id="dze-pod-box">
			<div id="dze-pod-design-preview" <?php echo $thumb ? '' : 'style="display:none;"'; ?>>
				<img src="<?php echo esc_url( $thumb ); ?>" alt="" />
			</div>
			<p>
				<button type="button" class="button" id="dze-pod-pick"><?php echo $design ? esc_html__( 'Change design', 'dazont-ecom' ) : esc_html__( 'Upload design', 'dazont-ecom' ); ?></button>
				<button type="button" class="button-link dze-pod-del" id="dze-pod-clear" <?php echo $design ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'dazont-ecom' ); ?></button>
			</p>
			<p class="dze-cx-note"><?php esc_html_e( 'PNG, transparent background, min 2000 px — ideally 4500×5400 px (print standard).', 'dazont-ecom' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="dze-pod-generate" <?php disabled( ! $design ); ?>><?php esc_html_e( 'Generate POD image', 'dazont-ecom' ); ?></button>
				<button type="button" class="dze-cx-icon" id="dze-pod-prompt-toggle" title="<?php esc_attr_e( 'Edit the POD prompt', 'dazont-ecom' ); ?>">✎</button>
			</p>
			<div id="dze-pod-pwrap" style="display:none;">
				<textarea id="dze-pod-prompt-live" rows="5" style="width:100%;box-sizing:border-box;"></textarea>
				<p style="margin:4px 0 0;"><button type="button" class="button-link" id="dze-pod-prompt-save">💾 <?php esc_html_e( 'Save prompt', 'dazont-ecom' ); ?></button></p>
			</div>
			<p id="dze-pod-status" class="dze-cx-note"></p>
			<div id="dze-pod-results" style="display:none;">
				<div class="dze-pod-grid"></div>
				<p class="dze-cx-note" style="margin:6px 0 0;"><?php esc_html_e( 'Click the image to keep, then add it to the product.', 'dazont-ecom' ); ?></p>
				<p style="margin:8px 0 0;">
					<label><select id="dze-pod-target" style="max-width:100%;">
						<option value="main"><?php esc_html_e( 'Use as main image', 'dazont-ecom' ); ?></option>
						<option value="gallery"><?php esc_html_e( 'Add to gallery', 'dazont-ecom' ); ?></option>
					</select></label>
					<button type="button" class="button button-primary" id="dze-pod-attach"><?php esc_html_e( 'Add to product', 'dazont-ecom' ); ?></button>
				</p>
				<?php if ( class_exists( 'DZE_Modules' ) && DZE_Modules::enabled( 'content' ) ) : ?>
				<p style="margin:8px 0 0;">
					<button type="button" class="button" id="dze-pod-tolab"><?php esc_html_e( 'Create more images from it (UGC, scenes…)', 'dazont-ecom' ); ?></button>
				</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function enqueue( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type || ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'dze-pod', DZE_URL . 'admin/js/pod.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-pod', 'dzePod', [
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE ),
			'postId'    => (int) get_the_ID(),
			'prompt'    => self::prompt(),
			'mockupSet' => self::mockup_id() > 0,
			'i18n'      => [
				'pickTitle'   => __( 'Choose the POD design (PNG)', 'dazont-ecom' ),
				'working'     => __( 'Rendering — up to a minute…', 'dazont-ecom' ),
				'ready'       => __( 'POD image ready — review it below.', 'dazont-ecom' ),
				'error'       => __( 'Something went wrong.', 'dazont-ecom' ),
				'attached'    => __( 'Added to the product ✓', 'dazont-ecom' ),
				'savedPrompt' => __( 'Prompt saved ✓', 'dazont-ecom' ),
				'savePrompt'  => __( 'Save prompt', 'dazont-ecom' ),
				'noMockup'    => __( 'No stored mockup — the product featured image is used as the base.', 'dazont-ecom' ),
				'change'      => __( 'Change design', 'dazont-ecom' ),
				'upload'      => __( 'Upload design', 'dazont-ecom' ),
			],
		] );
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

	/** Persist (or clear) the per-product design attachment. */
	public function ajax_design(): void {
		$this->guard();
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$att = isset( $_POST['attachment'] ) ? absint( $_POST['attachment'] ) : 0;
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Save the product first.', 'dazont-ecom' ) ] );
		}
		if ( 0 === $att ) {
			delete_post_meta( $pid, self::DESIGN_META );
			wp_send_json_success( [ 'cleared' => true ] );
		}
		if ( ! wp_attachment_is_image( $att ) ) {
			wp_send_json_error( [ 'message' => __( 'The design must be an image (PNG recommended).', 'dazont-ecom' ) ] );
		}
		update_post_meta( $pid, self::DESIGN_META, $att );
		wp_send_json_success( [ 'thumb' => (string) wp_get_attachment_image_url( $att, 'thumbnail' ) ] );
	}

	/** Design + base (stored mockup, else featured image) → fal.ai render. */
	public function ajax_generate(): void {
		$this->guard();
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$design = $pid ? absint( get_post_meta( $pid, self::DESIGN_META, true ) ) : 0;
		if ( ! $design ) {
			wp_send_json_error( [ 'message' => __( 'Upload a design on this product first.', 'dazont-ecom' ) ] );
		}
		if ( ! class_exists( 'DZE_Content' ) || '' === DZE_Content::fal_key() ) {
			wp_send_json_error( [ 'message' => __( 'Add your fal.ai key under Settings → General first.', 'dazont-ecom' ) ] );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}
		$custom = isset( $_POST['custom_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_prompt'] ) ) : '';
		$base   = self::mockup_id() ?: (int) get_post_thumbnail_id( $pid );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$content = DZE_Content::instance();
		try {
			$sources = [];
			if ( $base ) {
				$sources[] = $content->fal_source_data_uri( $base );
			}
			$sources[] = $content->fal_source_data_uri( $design );
			$prompt    = ( '' !== $custom ? $custom : self::prompt() )
				. "\n\nProduit : " . get_the_title( $pid )
				. ( $base ? '' : "\n(Aucun mockup fourni : génère le produit portant le design.)" );
			$url = $content->fal_generate( $prompt, $sources );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'url' => $url ] );
	}

	/** Persist the POD prompt (read-back verified, like every prompt save). */
	public function ajax_save_prompt(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		if ( '' === trim( $prompt ) ) {
			wp_send_json_error( [ 'message' => __( 'Empty prompt.', 'dazont-ecom' ) ] );
		}
		$s           = self::get_settings();
		$s['prompt'] = $prompt;
		update_option( self::OPT, $s, false );
		if ( self::prompt() !== $prompt ) {
			wp_send_json_error( [ 'message' => __( 'The prompt was not persisted — please save it from Settings instead.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'saved' => true ] );
	}

	/** Attach the generated image (SEO naming; main moves the old main to gallery). */
	public function ajax_attach(): void {
		$this->guard();
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$url    = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$target = ( isset( $_POST['target'] ) && 'main' === $_POST['target'] ) ? 'main' : 'gallery';
		if ( ! $pid || '' === $url || ! class_exists( 'DZE_Content' ) || ! DZE_Content::is_fal_url( $url ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		try {
			$att_id = DZE_Content::instance()->sideload_seo( $url, $pid, $target );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'attachment' => (int) $att_id ] );
	}
}
