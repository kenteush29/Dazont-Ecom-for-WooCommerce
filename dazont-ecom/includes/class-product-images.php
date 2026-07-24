<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI Product Images (Google Gemini 2.5 Flash Image).
 *
 * Adds an "AI Product Images" panel to the product edit screen to generate new
 * product-page images with Google's Gemini image model. Deliberately one product
 * at a time (never blind bulk): image situations are too varied. Situations:
 *
 *   - recolor   : recreate an existing image as another colour/variant (send a
 *                 reference image of the same product from the Media Library).
 *   - lifestyle : an "in-use" shot generated from the product's first images.
 *   - enhance   : re-render a low-quality image cleanly.
 *   - custom    : your own prompt.
 *
 * Each result can be Accepted (added to the product gallery, or set as the main
 * image), Discarded, or Regenerated. Prompts are simple templates (no Claude in
 * the loop) and editable under the settings page.
 *
 * The Gemini API key is read from the DZE_GEMINI_API_KEY constant when defined,
 * else a settings field. It is only ever sent to Google.
 */
final class DZE_Product_Images {

	public const OPT_SETTINGS = 'dze_img_settings';
	public const MENU_SLUG    = 'dazont-ecom-images';
	private const NONCE        = 'dze_img';
	private const META_TEMP    = '_dze_img_temp';
	private const DEFAULT_MODEL = 'gemini-2.5-flash-image';

	/** Default, editable prompt per situation. {title} = product name, {target} = your text. */
	public const DEFAULT_PROMPTS = [
		'recolor'   => "Recreate this exact product ({title}) as a clean e-commerce product photo, changing ONLY the colour / variant to: {target}. Keep the same product type, shape, material, details and framing as the reference image. Plain white studio background, soft even lighting, sharp, high resolution, no text or watermark.",
		'lifestyle' => "Create a realistic lifestyle photo of this product ({title}) shown in a natural real-world context of use. Keep the product IDENTICAL to the reference images: same design, colours, materials and proportions. Photorealistic, well-lit, believable scene, no text or watermark.",
		'enhance'   => "Re-render this product ({title}) as a higher-quality e-commerce photo: sharper, cleaner and well-lit on a plain white background. Keep the product identical to the reference image — do not change its design or colours. No text or watermark.",
		'custom'    => '',
	];

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
		// Settings are embedded on the central "AI Settings" page (no own submenu).
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'add_meta_boxes',        [ $this, 'add_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_dze_img_generate', [ $this, 'ajax_generate' ] );
		add_action( 'wp_ajax_dze_img_accept',   [ $this, 'ajax_accept' ] );
		add_action( 'wp_ajax_dze_img_discard',  [ $this, 'ajax_discard' ] );
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function get_settings(): array {
		$s = get_option( self::OPT_SETTINGS, [] );
		$s = is_array( $s ) ? $s : [];
		return wp_parse_args( $s, [
			'api_key' => '',
			'model'   => self::DEFAULT_MODEL,
			'prompts' => [],
		] );
	}

	public static function api_key(): string {
		if ( defined( 'DZE_GEMINI_API_KEY' ) && DZE_GEMINI_API_KEY ) {
			return (string) DZE_GEMINI_API_KEY;
		}
		return (string) ( self::get_settings()['api_key'] ?? '' );
	}

	private function model(): string {
		$m = trim( (string) ( self::get_settings()['model'] ?? '' ) );
		return $m !== '' ? $m : self::DEFAULT_MODEL;
	}

	public static function prompt_for( string $situation ): string {
		$custom = self::get_settings()['prompts'][ $situation ] ?? '';
		$custom = trim( (string) $custom );
		return $custom !== '' ? $custom : ( self::DEFAULT_PROMPTS[ $situation ] ?? '' );
	}

	public function register_settings(): void {
		register_setting( 'dze_img_options', self::OPT_SETTINGS, [ 'sanitize_callback' => [ $this, 'sanitize_settings' ], 'autoload' => false ] );
	}

	public function sanitize_settings( $value ): array {
		$in       = is_array( $value ) ? $value : [];
		$existing = self::get_settings();

		// The AI Settings page saves per tab ('keys' on General, 'prompts' on
		// Product images): only overwrite the fields the submitted section carries.
		$section = (string) ( $in['section'] ?? 'all' );

		$key = trim( (string) ( $in['api_key'] ?? '' ) );
		if ( $key === '' ) {
			$key = (string) $existing['api_key']; // keep saved key when blank.
		}
		$prompts = [];
		foreach ( array_keys( self::DEFAULT_PROMPTS ) as $sit ) {
			$prompts[ $sit ] = sanitize_textarea_field( (string) ( $in['prompts'][ $sit ] ?? '' ) );
		}

		if ( 'keys' === $section ) {
			return array_merge( $existing, [
				'api_key' => sanitize_text_field( $key ),
				'model'   => sanitize_text_field( (string) ( $in['model'] ?? self::DEFAULT_MODEL ) ) ?: self::DEFAULT_MODEL,
			] );
		}
		if ( 'prompts' === $section ) {
			return array_merge( $existing, [ 'prompts' => $prompts ] );
		}

		return [
			'api_key' => sanitize_text_field( $key ),
			'model'   => sanitize_text_field( (string) ( $in['model'] ?? self::DEFAULT_MODEL ) ) ?: self::DEFAULT_MODEL,
			'prompts' => $prompts,
		];
	}

	/**
	 * Renders the Gemini settings form, embedded on the central AI Settings page.
	 *
	 * @param string $dze_section 'all', 'keys' (API key + model) or 'prompts'.
	 */
	public function render_settings_section( string $dze_section = 'all' ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings   = self::get_settings();
		$key_locked = defined( 'DZE_GEMINI_API_KEY' );
		require DZE_DIR . 'admin/views/product-images-settings.php';
	}

	// =========================================================================
	// Product edit meta box
	// =========================================================================

	public function add_meta_box(): void {
		add_meta_box( 'dze-ai-images', __( 'AI Product Images', 'dazont-ecom' ), [ $this, 'render_meta_box' ], 'product', 'normal', 'default' );
	}

	public function render_meta_box( $post ): void {
		$has_key    = self::api_key() !== '';
		$settings_slug = class_exists( 'DZE_Marketing_Ai' ) ? DZE_Marketing_Ai::MENU_SLUG : self::MENU_SLUG;
		$settings_url  = add_query_arg( [ 'page' => $settings_slug ], admin_url( 'admin.php' ) );
		require DZE_DIR . 'admin/views/product-images-metabox.php';
	}

	public function enqueue_assets( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_product = $screen && 'product' === $screen->id;
		if ( $on_product ) {
			wp_enqueue_media();
			wp_enqueue_script( 'dze-product-images', DZE_URL . 'admin/js/product-images.js', [ 'jquery' ], DZE_VERSION, true );
			wp_localize_script( 'dze-product-images', 'dzeImg', [
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => [
					'generating' => __( 'Generating… (can take up to a minute)', 'dazont-ecom' ),
					'error'      => __( 'Generation failed', 'dazont-ecom' ),
					'pickRef'    => __( 'Choose reference image(s)', 'dazont-ecom' ),
					'needRef'    => __( 'Add at least one reference image first.', 'dazont-ecom' ),
					'needTarget' => __( 'Describe the target colour/variant first.', 'dazont-ecom' ),
					'accepted'   => __( 'Added to the product.', 'dazont-ecom' ),
				],
			] );
		}
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	public function ajax_generate(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		if ( self::api_key() === '' ) {
			wp_send_json_error( [ 'message' => __( 'Add your Google Gemini API key under AI Settings first.', 'dazont-ecom' ) ] );
		}
		$product_id = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			wp_send_json_error( [ 'message' => __( 'Unknown product.', 'dazont-ecom' ) ] );
		}
		$situation = isset( $_POST['situation'] ) ? sanitize_key( wp_unslash( $_POST['situation'] ) ) : 'lifestyle';
		if ( ! array_key_exists( $situation, self::DEFAULT_PROMPTS ) ) {
			$situation = 'lifestyle';
		}
		$target = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );
		$custom = sanitize_textarea_field( wp_unslash( $_POST['custom'] ?? '' ) );
		$ref_ids = array_values( array_filter( array_map( 'absint', (array) ( $_POST['refs'] ?? [] ) ) ) );

		// Reference images: explicit selection, or auto (product's first images).
		if ( empty( $ref_ids ) ) {
			$ref_ids = $this->product_image_ids( $product, 3 );
		}
		$inline = [];
		foreach ( array_slice( $ref_ids, 0, 4 ) as $id ) {
			$img = $this->image_inline( (int) $id );
			if ( $img ) {
				$inline[] = $img;
			}
		}

		// Build the prompt.
		$template = 'custom' === $situation ? $custom : self::prompt_for( $situation );
		if ( trim( $template ) === '' ) {
			wp_send_json_error( [ 'message' => __( 'This situation has no prompt. Write one in settings (or use Custom).', 'dazont-ecom' ) ] );
		}
		$prompt = strtr( $template, [ '{title}' => $product->get_name(), '{target}' => $target ] );

		try {
			[ $bytes, $mime ] = $this->generate_image( $prompt, $inline );
			$att_id = $this->save_temp_image( $bytes, $mime, $product_id );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'id'  => $att_id,
			'url' => wp_get_attachment_image_url( $att_id, 'large' ) ?: wp_get_attachment_url( $att_id ),
		] );
	}

	public function ajax_accept(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$att_id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$product_id = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$mode       = isset( $_POST['mode'] ) && 'featured' === $_POST['mode'] ? 'featured' : 'gallery';
		if ( ! $att_id || ! $product_id || get_post_meta( $att_id, self::META_TEMP, true ) !== '1' ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to accept.', 'dazont-ecom' ) ] );
		}
		delete_post_meta( $att_id, self::META_TEMP );
		wp_update_post( [ 'ID' => $att_id, 'post_parent' => $product_id ] );

		if ( 'featured' === $mode ) {
			set_post_thumbnail( $product_id, $att_id );
		} else {
			$gallery = array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $product_id, '_product_image_gallery', true ) ) ) );
			$gallery[] = $att_id;
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', array_unique( $gallery ) ) );
		}
		wp_send_json_success();
	}

	public function ajax_discard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$att_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $att_id && get_post_meta( $att_id, self::META_TEMP, true ) === '1' ) {
			wp_delete_attachment( $att_id, true );
		}
		wp_send_json_success();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/** Product's featured + gallery image IDs, capped. */
	private function product_image_ids( \WC_Product $product, int $limit ): array {
		$ids = [];
		if ( $product->get_image_id() ) {
			$ids[] = (int) $product->get_image_id();
		}
		foreach ( $product->get_gallery_image_ids() as $gid ) {
			$ids[] = (int) $gid;
		}
		return array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, $limit );
	}

	/** An attachment as Gemini inline_data (prefers the 'large' size to bound payload). */
	private function image_inline( int $att_id ): ?array {
		$path  = get_attached_file( $att_id );
		$large = image_get_intermediate_size( $att_id, 'large' );
		if ( $large && ! empty( $large['path'] ) ) {
			$up = wp_upload_dir();
			$lp = trailingslashit( $up['basedir'] ) . $large['path'];
			if ( file_exists( $lp ) ) {
				$path = $lp;
			}
		}
		if ( ! $path || ! file_exists( $path ) ) {
			return null;
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read.
		if ( false === $bytes ) {
			return null;
		}
		$mime = get_post_mime_type( $att_id ) ?: 'image/jpeg';
		return [ 'mime_type' => $mime, 'data' => base64_encode( $bytes ) ];
	}

	/**
	 * Calls Gemini generateContent and returns [ raw_bytes, mime ] of the first
	 * image part in the response.
	 */
	private function generate_image( string $prompt, array $inline_images ): array {
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			throw new RuntimeException( DZE_Ai_Usage::budget_message() );
		}
		$model = $this->model();
		$url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( self::api_key() );

		$parts = [ [ 'text' => $prompt ] ];
		foreach ( $inline_images as $img ) {
			$parts[] = [ 'inline_data' => $img ];
		}
		$body = [
			'contents'         => [ [ 'role' => 'user', 'parts' => $parts ] ],
			'generationConfig' => [ 'responseModalities' => [ 'TEXT', 'IMAGE' ] ],
		];

		$resp = wp_remote_post( $url, [
			'timeout' => 120,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $resp ) ) {
			throw new RuntimeException( $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			throw new RuntimeException( (string) ( $data['error']['message'] ?? ( 'Gemini HTTP ' . $code ) ) );
		}
		DZE_Ai_Usage::record( 'gemini', (int) ( $data['usageMetadata']['promptTokenCount'] ?? 0 ), (int) ( $data['usageMetadata']['candidatesTokenCount'] ?? 0 ), $model );
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? [] ) as $p ) {
			$inline = $p['inlineData'] ?? $p['inline_data'] ?? null;
			if ( $inline && ! empty( $inline['data'] ) ) {
				$raw = base64_decode( (string) $inline['data'], true );
				if ( false !== $raw && $raw !== '' ) {
					return [ $raw, (string) ( $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png' ) ];
				}
			}
		}
		throw new RuntimeException( __( 'Gemini returned no image. Try again or adjust the prompt.', 'dazont-ecom' ) );
	}

	/** Saves generated bytes to the Media Library as an unattached temp image. */
	private function save_temp_image( string $bytes, string $mime, int $product_id ): int {
		$ext      = 'image/png' === $mime ? 'png' : ( 'image/webp' === $mime ? 'webp' : 'jpg' );
		$filename = 'dze-ai-' . $product_id . '-' . time() . '.' . $ext;
		$upload   = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			throw new RuntimeException( (string) $upload['error'] );
		}
		$att_id = wp_insert_attachment( [
			'post_mime_type' => $mime,
			'post_title'     => sanitize_file_name( $filename ),
			'post_status'    => 'inherit',
		], $upload['file'] );
		if ( is_wp_error( $att_id ) || ! $att_id ) {
			throw new RuntimeException( __( 'Could not save the generated image.', 'dazont-ecom' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $upload['file'] ) );
		update_post_meta( $att_id, self::META_TEMP, '1' );
		return (int) $att_id;
	}
}
