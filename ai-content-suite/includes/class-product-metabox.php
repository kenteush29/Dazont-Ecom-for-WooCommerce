<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adds the "AI Content Generator" metabox on the WooCommerce product edit screen.
 * Provides AJAX endpoints for per-field generation and field writing.
 */
final class AICS_Product_Metabox {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes',          [ $this, 'register_metabox' ] );
		add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_aics_generate_field', [ $this, 'ajax_generate_field' ] );
		add_action( 'wp_ajax_aics_apply_field',    [ $this, 'ajax_apply_field' ] );
	}

	public function register_metabox(): void {
		add_meta_box(
			'aics-content-generator',
			__( 'AI Content Generator', 'ai-content-suite' ),
			[ $this, 'render' ],
			'product',
			'normal',
			'high'
		);
	}

	public function enqueue_assets( string $hook ): void {
		global $post;
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		if ( ! $post || $post->post_type !== 'product' ) {
			return;
		}

		wp_enqueue_script(
			'aics-metabox',
			AICS_URL . 'admin/js/metabox.js',
			[ 'jquery' ],
			AICS_VERSION,
			true
		);

		wp_localize_script( 'aics-metabox', 'aicsMetabox', [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'aics_admin' ),
			'postId'      => $post->ID,
			'previewMode' => AICS_Settings::is_preview_mode(),
			'i18n'        => [
				'generating' => __( 'Generating…', 'ai-content-suite' ),
				'generate'   => __( 'Generate', 'ai-content-suite' ),
				'applying'   => __( 'Applying…', 'ai-content-suite' ),
				'applied'    => __( 'Applied!', 'ai-content-suite' ),
				'apply'      => __( 'Apply to field', 'ai-content-suite' ),
				'error'      => __( 'Error:', 'ai-content-suite' ),
				'noMapping'  => __( 'This slot is not mapped. Configure it in Field Mapping.', 'ai-content-suite' ),
			],
		] );
	}

	public function render( \WP_Post $post ): void {
		$mapper  = AICS_Field_Mapper::instance();
		$mapping = $mapper->get_mapping();

		$dest_slots = [
			'dest_seo_title'          => __( 'SEO Title', 'ai-content-suite' ),
			'dest_short_description'  => __( 'Short Description', 'ai-content-suite' ),
			'dest_long_description'   => __( 'Long Description', 'ai-content-suite' ),
			'dest_custom_1'           => __( 'Custom Field 1', 'ai-content-suite' ),
			'dest_custom_2'           => __( 'Custom Field 2', 'ai-content-suite' ),
		];

		// Build DOM target info per slot so JS can live-update the page fields.
		$dom_targets = [];
		foreach ( $dest_slots as $slot => $_ ) {
			$dom_targets[ $slot ] = $this->resolve_dom_target( $mapping[ $slot ] ?? null );
		}

		require AICS_DIR . 'admin/views/metabox.php';
	}

	public function ajax_generate_field(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$slot    = sanitize_key( $_POST['slot'] ?? '' );

		if ( ! $post_id || ! $slot ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'ai-content-suite' ) ] );
		}

		if ( ! AICS_Settings::check_and_increment_call_count() ) {
			wp_send_json_error( [ 'message' => __( 'API call limit reached. Check cost guardrails in Settings.', 'ai-content-suite' ) ] );
		}

		$api_key = AICS_Settings::get_api_key();
		if ( empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'No API key configured.', 'ai-content-suite' ) ] );
		}

		$mapper         = AICS_Field_Mapper::instance();
		$supplier_data  = $mapper->read( 'source_supplier_data', $post_id );
		$product_title  = $mapper->read( 'source_product_title', $post_id );

		if ( empty( $product_title ) ) {
			$product_title = get_the_title( $post_id );
		}

		$task          = str_replace( 'dest_', '', $slot );
		$model         = AICS_Settings::get_model_for_task( $task );
		$prompt        = AICS_Settings::get_prompt( $task );
		$user_prompt   = $this->render_user_template( $prompt['user_template'], $product_title, $supplier_data );
		$system_prompt = $prompt['system'];

		try {
			$client = new AICS_Api_Client( $api_key, $model );
			$result = $client->generate(
				$user_prompt,
				$system_prompt,
				null,
				[ 'prompt_slug' => $task, 'product_id' => $post_id ]
			);

			wp_send_json_success( [
				'text'  => $result['text'],
				'model' => $result['model'],
				'usage' => $result['usage'],
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_apply_field(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$slot    = sanitize_key( $_POST['slot'] ?? '' );
		$value   = wp_kses_post( wp_unslash( $_POST['value'] ?? '' ) );

		if ( ! $post_id || ! $slot ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'ai-content-suite' ) ] );
		}

		$ok = AICS_Field_Mapper::instance()->write( $slot, $value, $post_id );

		if ( $ok ) {
			wp_send_json_success( [ 'message' => __( 'Field updated.', 'ai-content-suite' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Could not write to field. Check the Field Mapping configuration.', 'ai-content-suite' ) ] );
		}
	}

	private function render_user_template( string $template, string $title, string $supplier_data ): string {
		return str_replace(
			[ '{{product_name}}', '{{supplier_data}}' ],
			[ $title, $supplier_data ],
			$template
		);
	}

	/**
	 * Returns DOM target info for a mapped field so JS can live-update the page.
	 * [ 'id' => string, 'type' => 'input|tinymce|acf_text|rankmath|none' ]
	 */
	private function resolve_dom_target( ?array $dest ): array {
		if ( ! $dest ) {
			return [ 'id' => '', 'type' => 'none' ];
		}

		switch ( $dest['type'] ) {
			case 'woo_native':
				return match ( $dest['field'] ) {
					'post_title'   => [ 'id' => 'title',   'type' => 'input' ],
					'post_excerpt' => [ 'id' => 'excerpt', 'type' => 'tinymce' ],
					'post_content' => [ 'id' => 'content', 'type' => 'tinymce' ],
					default        => [ 'id' => '', 'type' => 'none' ],
				};

			case 'acf':
				// ACF renders inputs with id="acf-{field_key}" for text/textarea,
				// and a TinyMCE editor with id="{field_key}" for wysiwyg.
				$key = $dest['field_key'] ?? '';
				return [ 'id' => $key, 'type' => 'acf' ];

			case 'seo_meta':
				$selectors = [
					'rank_math_title'        => [ 'id' => 'rank-math-title',       'type' => 'input' ],
					'rank_math_description'  => [ 'id' => 'rank-math-description', 'type' => 'input' ],
					'rank_math_focus_keyword'=> [ 'id' => 'rank-math-focus-keyword','type' => 'input' ],
					'_yoast_wpseo_title'     => [ 'id' => 'yoast_wpseo_title',     'type' => 'input' ],
					'_yoast_wpseo_metadesc'  => [ 'id' => 'yoast_wpseo_metadesc',  'type' => 'input' ],
				];
				return $selectors[ $dest['meta_key'] ] ?? [ 'id' => '', 'type' => 'none' ];

			default:
				return [ 'id' => '', 'type' => 'none' ];
		}
	}
}
