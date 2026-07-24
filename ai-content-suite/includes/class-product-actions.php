<?php
defined( 'ABSPATH' ) || exit;

/**
 * Integrates AI actions directly into the WooCommerce products list:
 *   - "Generate content" and "Translate content" buttons above the table
 *   - Per-row "AI: Generate" / "AI: Translate" row actions
 *   - Two modal popups that process the selected (or single) products
 */
final class AICS_Product_Actions {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
		add_action( 'restrict_manage_posts', [ $this, 'render_toolbar_buttons' ] );
		add_filter( 'post_row_actions',      [ $this, 'add_row_actions' ], 10, 2 );

		add_action( 'wp_ajax_aics_pa_generate',  [ $this, 'ajax_generate' ] );
		add_action( 'wp_ajax_aics_pa_translate', [ $this, 'ajax_translate' ] );
	}

	/**
	 * The five destination slots offered for generation / translation.
	 */
	private function slots(): array {
		return [
			'dest_seo_title'         => __( 'SEO meta description', 'ai-content-suite' ),
			'dest_short_description' => __( 'Short description', 'ai-content-suite' ),
			'dest_long_description'  => __( 'Long description', 'ai-content-suite' ),
			'dest_custom_1'          => __( 'Branding description', 'ai-content-suite' ),
			'dest_custom_2'          => __( 'Characteristics list', 'ai-content-suite' ),
		];
	}

	private function is_product_list_screen(): bool {
		// Check screen object first (most reliable).
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen ) {
			return $screen->id === 'edit-product';
		}
		// Fallback: check URL globals (works even if screen isn't set yet).
		return isset( $_GET['post_type'] ) && $_GET['post_type'] === 'product'
			&& ( isset( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'edit.php' );
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets( string $hook ): void {
		// Accept edit.php or the WooCommerce variant; also check pagenow as fallback.
		$is_edit = $hook === 'edit.php' || ( isset( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'edit.php' );
		if ( ! $is_edit || ! $this->is_product_list_screen() ) {
			return;
		}

		wp_enqueue_style( 'aics-admin', AICS_URL . 'admin/css/admin.css', [], AICS_VERSION );
		wp_enqueue_script( 'aics-product-actions', AICS_URL . 'admin/js/product-actions.js', [ 'jquery' ], AICS_VERSION, true );

		// Pre-render modal HTML so JS can inject it even if admin_footer missed it.
		ob_start();
		$slots     = $this->slots();
		$languages = AICS_Wpml_Translate::get_active_languages();
		$wpml      = AICS_Wpml_Translate::is_wpml_active();
		require AICS_DIR . 'admin/views/product-actions-modals.php';
		$modal_html = ob_get_clean();

		wp_localize_script( 'aics-product-actions', 'aicsPA', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'aics_admin' ),
			'slots'      => $this->slots(),
			'wpmlActive' => AICS_Wpml_Translate::is_wpml_active(),
			'languages'  => AICS_Wpml_Translate::get_active_languages(),
			'modalHtml'  => $modal_html,
			'i18n'       => [
				'generate'        => __( 'Generate content', 'ai-content-suite' ),
				'translate'       => __( 'Translate content', 'ai-content-suite' ),
				'noProducts'      => __( 'No products selected. Tick the checkboxes in the list, or use the row action on a single product.', 'ai-content-suite' ),
				'noSlots'         => __( 'Select at least one field.', 'ai-content-suite' ),
				'noLangs'         => __( 'Select at least one target language.', 'ai-content-suite' ),
				'sameLang'        => __( 'Source and target language must differ.', 'ai-content-suite' ),
				'working'         => __( 'Working…', 'ai-content-suite' ),
				'done'            => __( 'Done', 'ai-content-suite' ),
				'error'           => __( 'Error', 'ai-content-suite' ),
				'start'           => __( 'Start', 'ai-content-suite' ),
				'close'           => __( 'Close', 'ai-content-suite' ),
				'allDone'         => __( 'All operations completed.', 'ai-content-suite' ),
				'productsTargeted'=> __( 'product(s) targeted', 'ai-content-suite' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// UI injection
	// -------------------------------------------------------------------------

	public function render_toolbar_buttons( string $post_type ): void {
		if ( $post_type !== 'product' ) {
			return;
		}
		// restrict_manage_posts runs for both top & bottom tablenav; emit once.
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
		<button type="button" class="button aics-pa-btn" id="aics-pa-open-generate" style="margin-left:6px;">
			<span class="dashicons dashicons-rest-api" style="vertical-align:text-top;"></span>
			<?php esc_html_e( 'Generate content', 'ai-content-suite' ); ?>
		</button>
		<?php if ( AICS_Wpml_Translate::is_wpml_active() ) : ?>
		<button type="button" class="button aics-pa-btn" id="aics-pa-open-translate">
			<span class="dashicons dashicons-translation" style="vertical-align:text-top;"></span>
			<?php esc_html_e( 'Translate content', 'ai-content-suite' ); ?>
		</button>
		<?php endif; ?>
		<?php
	}

	public function add_row_actions( array $actions, \WP_Post $post ): array {
		if ( $post->post_type !== 'product' ) {
			return $actions;
		}
		$actions['aics_generate'] = sprintf(
			'<a href="#" class="aics-pa-row-generate" data-id="%d">%s</a>',
			$post->ID,
			esc_html__( 'AI: Generate', 'ai-content-suite' )
		);
		if ( AICS_Wpml_Translate::is_wpml_active() ) {
			$actions['aics_translate'] = sprintf(
				'<a href="#" class="aics-pa-row-translate" data-id="%d">%s</a>',
				$post->ID,
				esc_html__( 'AI: Translate', 'ai-content-suite' )
			);
		}
		return $actions;
	}

	// -------------------------------------------------------------------------
	// AJAX: generate
	// -------------------------------------------------------------------------

	public function ajax_generate(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$slot    = sanitize_key( $_POST['slot'] ?? '' );

		if ( ! $post_id || ! $slot ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'ai-content-suite' ) ] );
		}

		try {
			$result = self::generate_for( $post_id, $slot );
			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Generates content for one product/slot and writes it through the field mapper.
	 *
	 * @throws RuntimeException on any failure.
	 */
	public static function generate_for( int $post_id, string $slot ): array {
		if ( ! AICS_Settings::check_and_increment_call_count() ) {
			throw new RuntimeException( __( 'API call limit reached. Check cost guardrails in Settings.', 'ai-content-suite' ) );
		}

		$api_key = AICS_Settings::get_api_key();
		if ( empty( $api_key ) ) {
			throw new RuntimeException( __( 'No API key configured.', 'ai-content-suite' ) );
		}

		$mapper        = AICS_Field_Mapper::instance();
		$supplier_data = $mapper->read( 'source_supplier_data', $post_id );
		$product_title = $mapper->read( 'source_product_title', $post_id );
		if ( empty( $product_title ) ) {
			$product_title = get_the_title( $post_id );
		}

		$task        = str_replace( 'dest_', '', $slot );
		$model       = AICS_Settings::get_model_for_task( $task );
		$prompt      = AICS_Settings::get_prompt( $task );
		$user_prompt = str_replace(
			[ '{{product_name}}', '{{supplier_data}}' ],
			[ $product_title, $supplier_data ],
			$prompt['user_template']
		);

		$client = new AICS_Api_Client( $api_key, $model );
		$result = $client->generate(
			$user_prompt,
			$prompt['system'],
			null,
			[ 'prompt_slug' => $task, 'product_id' => $post_id ]
		);

		if ( ! $mapper->write( $slot, $result['text'], $post_id ) ) {
			throw new RuntimeException( __( 'Generated but could not write to field (check Field Mapping).', 'ai-content-suite' ) );
		}

		return [
			'text'  => $result['text'],
			'model' => $result['model'],
			'usage' => $result['usage'],
		];
	}

	// -------------------------------------------------------------------------
	// AJAX: translate
	// -------------------------------------------------------------------------

	public function ajax_translate(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}

		$post_id     = (int) ( $_POST['post_id'] ?? 0 );
		$slot        = sanitize_key( $_POST['slot'] ?? '' );
		$source_lang = sanitize_key( $_POST['source_lang'] ?? '' );
		$target_lang = sanitize_key( $_POST['target_lang'] ?? '' );

		if ( ! $post_id || ! $slot || ! $source_lang || ! $target_lang ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'ai-content-suite' ) ] );
		}
		if ( $source_lang === $target_lang ) {
			wp_send_json_error( [ 'message' => __( 'Source and target language are identical.', 'ai-content-suite' ) ] );
		}

		if ( ! AICS_Settings::check_and_increment_call_count() ) {
			wp_send_json_error( [ 'message' => __( 'API call limit reached.', 'ai-content-suite' ) ] );
		}

		$api_key = AICS_Settings::get_api_key();
		if ( empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'No API key configured.', 'ai-content-suite' ) ] );
		}

		// Resolve the source-language product (fall back to original if missing).
		$source_id = AICS_Wpml_Translate::get_translated_post_id( $post_id, $source_lang, true );
		if ( ! $source_id ) {
			$source_id = $post_id;
		}

		$mapper      = AICS_Field_Mapper::instance();
		$source_text = $mapper->read( $slot, $source_id );
		if ( $source_text === '' ) {
			wp_send_json_error( [ 'message' => __( 'Source field is empty — nothing to translate.', 'ai-content-suite' ) ] );
		}

		// Resolve the target-language product (must already exist).
		$target_id = AICS_Wpml_Translate::get_translated_post_id( $post_id, $target_lang, false );
		if ( ! $target_id || $target_id === $source_id ) {
			wp_send_json_error( [ 'message' => sprintf(
				/* translators: language code */
				__( 'No %s translation exists for this product. Create it in WPML first.', 'ai-content-suite' ),
				strtoupper( $target_lang )
			) ] );
		}

		$language_name = $target_lang;
		foreach ( AICS_Wpml_Translate::get_active_languages() as $lang ) {
			if ( $lang['code'] === $target_lang ) {
				$language_name = $lang['native_name'];
				break;
			}
		}

		$model  = AICS_Settings::get_model_for_task( str_replace( 'dest_', '', $slot ) );
		$system = sprintf(
			'You are a professional e-commerce translator. Translate the following product content into %s. Preserve all HTML tags exactly as-is. Keep the same tone and marketing intent. Return only the translated text, nothing else.',
			$language_name
		);

		try {
			$client = new AICS_Api_Client( $api_key, $model );
			$result = $client->generate(
				$source_text,
				$system,
				null,
				[ 'prompt_slug' => 'translate_' . $target_lang, 'product_id' => $target_id ]
			);

			if ( ! $mapper->write( $slot, $result['text'], $target_id ) ) {
				wp_send_json_error( [ 'message' => __( 'Translated but could not write to the target product field.', 'ai-content-suite' ) ] );
			}

			wp_send_json_success( [
				'text'      => $result['text'],
				'model'     => $result['model'],
				'usage'     => $result['usage'],
				'target_id' => $target_id,
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}
}
