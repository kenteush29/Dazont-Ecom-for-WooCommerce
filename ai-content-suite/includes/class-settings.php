<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page.
 * Stores: API key (plaintext, server-side only), default model, per-task model overrides,
 * preview toggle, cost guardrails, editable prompt templates, and the test-connection button.
 */
final class AICS_Settings {

	private const MENU_SLUG    = 'aics-settings';
	private const OPTION_GROUP = 'aics_options';

	public const OPT_API_KEY         = 'aics_anthropic_api_key';
	public const OPT_DEFAULT_MODEL   = 'aics_default_model';
	public const OPT_MODEL_OVERRIDES = 'aics_model_overrides';
	public const OPT_PREVIEW_MODE    = 'aics_preview_mode';
	public const OPT_MAX_CALLS_HOUR  = 'aics_max_calls_hour';
	public const OPT_MAX_CALLS_DAY   = 'aics_max_calls_day';
	public const OPT_CALLS_COUNT     = 'aics_calls_count';
	public const OPT_PROMPTS         = 'aics_prompts';
	public const OPT_STORE_CONTEXT   = 'aics_store_context';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_init',            [ $this, 'handle_reset_prompts' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_aics_test_connection', [ $this, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_aics_clear_log',       [ $this, 'ajax_clear_log' ] );
	}

	// -------------------------------------------------------------------------
	// Accessors
	// -------------------------------------------------------------------------

	public static function get_api_key(): string {
		$value = (string) get_option( self::OPT_API_KEY, '' );
		// Reject old encrypted garbage — valid Anthropic keys always start with "sk-ant-".
		if ( $value !== '' && strpos( $value, 'sk-ant-' ) !== 0 ) {
			return '';
		}
		return $value;
	}

	public static function get_default_model(): string {
		return get_option( self::OPT_DEFAULT_MODEL, 'claude-haiku-4-5' );
	}

	public static function get_model_for_task( string $task ): string {
		$overrides = get_option( self::OPT_MODEL_OVERRIDES, [] );
		return ( ! empty( $overrides[ $task ] ) ) ? $overrides[ $task ] : self::get_default_model();
	}

	public static function get_store_context(): string {
		return (string) get_option( self::OPT_STORE_CONTEXT, '' );
	}

	public static function is_preview_mode(): bool {
		return (bool) get_option( self::OPT_PREVIEW_MODE, true );
	}

	/**
	 * Returns the stored prompts for a given task, falling back to built-in defaults.
	 * Returns [ 'system' => string, 'user_template' => string ]
	 */
	public static function get_prompt( string $task ): array {
		$stored        = get_option( self::OPT_PROMPTS, [] );
		$defaults      = self::default_prompts();
		$store_context = self::get_store_context();

		// Use the stored value only when it is a non-empty customisation,
		// otherwise always fall back to the (possibly updated) plugin default.
		$system = ! empty( $stored[ $task ]['system'] )
			? $stored[ $task ]['system']
			: ( $defaults[ $task ]['system'] ?? '' );

		$user_template = ! empty( $stored[ $task ]['user_template'] )
			? $stored[ $task ]['user_template']
			: ( $defaults[ $task ]['user_template'] ?? '' );

		$system = str_replace( '{{store_context}}', $store_context, $system );

		return [
			'system'        => $system,
			'user_template' => $user_template,
		];
	}

	public static function check_and_increment_call_count(): bool {
		$max_hour = (int) get_option( self::OPT_MAX_CALLS_HOUR, 0 );
		$max_day  = (int) get_option( self::OPT_MAX_CALLS_DAY, 0 );

		if ( $max_hour === 0 && $max_day === 0 ) {
			return true;
		}

		$counts = get_option( self::OPT_CALLS_COUNT, [
			'hour_ts'    => 0,
			'hour_count' => 0,
			'day_ts'     => 0,
			'day_count'  => 0,
		] );

		$now = time();

		if ( $now - $counts['hour_ts'] >= HOUR_IN_SECONDS ) {
			$counts['hour_ts']    = $now;
			$counts['hour_count'] = 0;
		}

		if ( $now - $counts['day_ts'] >= DAY_IN_SECONDS ) {
			$counts['day_ts']    = $now;
			$counts['day_count'] = 0;
		}

		if ( $max_hour > 0 && $counts['hour_count'] >= $max_hour ) {
			return false;
		}

		if ( $max_day > 0 && $counts['day_count'] >= $max_day ) {
			return false;
		}

		$counts['hour_count']++;
		$counts['day_count']++;
		update_option( self::OPT_CALLS_COUNT, $counts, false );

		return true;
	}

	// -------------------------------------------------------------------------
	// Default prompt templates
	// Available placeholders: {{product_name}}, {{supplier_data}}
	// -------------------------------------------------------------------------

	public static function default_prompts(): array {
		return [
			'seo_title' => [
				'system'        => 'Write a Google SERP meta description of maximum 155 characters for a product. {{store_context}} Originality level 7/10. Use a captivating, original sentence structure to increase CTR. English. Return only the meta description, nothing else.',
				'user_template' => "Product name: {{product_name}}\nSupplier data:\n{{supplier_data}}\n\nWrite a meta description (maximum 155 characters).",
			],
			'short_description' => [
				'system'        => 'Write a short product description of approximately 20 words for a product page visitor. {{store_context}} Originality level 8/10. Focus on what makes this product stand out from others in its category. Use customer language with usage examples or specific selling points. No generic marketing phrases. English. Return only the description, nothing else.',
				'user_template' => "Product name: {{product_name}}\nSupplier data:\n{{supplier_data}}\n\nWrite a short product description (approximately 20 words).",
			],
			'long_description' => [
				'system'        => 'Write a product description for a product page visitor. {{store_context}} Instructions: Include one H2 subtitle highlighting the main product characteristic (format: <h2>subtitle</h2>). The H2 must be creative — never use "ultimate versatility". Total length: approximately 50 words. Adapt to the product: use case, season, activity, terrain, color range. Do not mention sizes. Do not cite unknown suppliers. Write like a human: integrate cognitive biases and emotions, no repetitive AI patterns. English, informative and expert tone. Return only the description, nothing else.',
				'user_template' => "Product name: {{product_name}}\nSupplier data:\n{{supplier_data}}\n\nWrite a detailed product description with one H2 subtitle.",
			],
			'custom_1' => [
				'system'        => 'Write an additional product description of 30-40 words for a product page visitor. {{store_context}} Instructions: Include one H2 subtitle that develops a secondary key aspect of the product (format: <h2>subtitle</h2>). The text below the H2 must expand on that H2 topic. Do not repeat the product title. Do not repeat content from the main product description. English, informative tone. Return only the content, nothing else.',
				'user_template' => "Product name: {{product_name}}\nSupplier data:\n{{supplier_data}}\n\nWrite a secondary product description (30-40 words) with one H2 subtitle.",
			],
			'custom_2' => [
				'system'        => 'Write a bullet list of product characteristics for a product page. {{store_context}} Instructions: Format as HTML <ul><li>point</li></ul>. Use <strong> sparingly for the most important elements. No italic, no numbered list. Focus on what makes this product unique and different from others. Include quantified data (materials, specs) when available. Do not mention sizes, unknown brand names, dropshipping info, or China origin. Improve or invent characteristics if supplier data is poor quality. No repetitive AI patterns. Return only the bullet list, nothing else.',
				'user_template' => "Product name: {{product_name}}\nSupplier data:\n{{supplier_data}}\n\nWrite a bullet list of product characteristics as HTML <ul><li> list.",
			],
		];
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public function register_menu(): void {
		add_menu_page(
			__( 'AI Content Suite', 'ai-content-suite' ),
			__( 'AI Content Suite', 'ai-content-suite' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_settings_page' ],
			'dashicons-rest-api',
			58
		);
	}

	// -------------------------------------------------------------------------
	// Settings API registration
	// -------------------------------------------------------------------------

	public function register_settings(): void {
		register_setting( self::OPTION_GROUP, self::OPT_API_KEY, [
			'sanitize_callback' => [ $this, 'sanitize_api_key' ],
		] );
		register_setting( self::OPTION_GROUP, self::OPT_DEFAULT_MODEL, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( self::OPTION_GROUP, self::OPT_MODEL_OVERRIDES, [
			'sanitize_callback' => [ $this, 'sanitize_model_overrides' ],
		] );
		register_setting( self::OPTION_GROUP, self::OPT_PREVIEW_MODE, [
			'sanitize_callback' => 'absint',
		] );
		register_setting( self::OPTION_GROUP, self::OPT_MAX_CALLS_HOUR, [
			'sanitize_callback' => 'absint',
		] );
		register_setting( self::OPTION_GROUP, self::OPT_MAX_CALLS_DAY, [
			'sanitize_callback' => 'absint',
		] );
		register_setting( self::OPTION_GROUP, self::OPT_PROMPTS, [
			'sanitize_callback' => [ $this, 'sanitize_prompts' ],
		] );
		register_setting( self::OPTION_GROUP, self::OPT_STORE_CONTEXT, [
			'sanitize_callback' => 'sanitize_textarea_field',
		] );
	}

	public function sanitize_api_key( $value ): string {
		$value = sanitize_text_field( trim( (string) $value ) );
		if ( empty( $value ) ) {
			return (string) get_option( self::OPT_API_KEY, '' );
		}
		return $value;
	}

	public function sanitize_model_overrides( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$clean = [];
		foreach ( $value as $task => $model ) {
			$clean[ sanitize_key( $task ) ] = sanitize_text_field( $model );
		}
		return $clean;
	}

	public function sanitize_prompts( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$clean    = [];
		$defaults = self::default_prompts();
		foreach ( $defaults as $task => $default ) {
			$sys = sanitize_textarea_field( wp_unslash( $value[ $task ]['system'] ?? '' ) );
			$usr = sanitize_textarea_field( wp_unslash( $value[ $task ]['user_template'] ?? '' ) );

			// Persist a value only when it is a real customisation that differs
			// from the built-in default. Otherwise store empty so future default
			// changes keep propagating (and "reset" is the natural state).
			$default_sys = sanitize_textarea_field( $default['system'] );
			$default_usr = sanitize_textarea_field( $default['user_template'] );

			$clean[ $task ] = [
				'system'        => ( $sys !== '' && $sys !== $default_sys ) ? $sys : '',
				'user_template' => ( $usr !== '' && $usr !== $default_usr ) ? $usr : '',
			];
		}
		return $clean;
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'aics' ) === false ) {
			return;
		}

		wp_enqueue_style( 'aics-admin', AICS_URL . 'admin/css/admin.css', [], AICS_VERSION );

		wp_enqueue_script( 'aics-settings', AICS_URL . 'admin/js/settings.js', [ 'jquery' ], AICS_VERSION, true );

		wp_localize_script( 'aics-settings', 'aicsSettings', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'aics_admin' ),
			'i18n'    => [
				'testing'  => __( 'Testing…', 'ai-content-suite' ),
				'success'  => __( 'Connection successful!', 'ai-content-suite' ),
				'error'    => __( 'Connection failed:', 'ai-content-suite' ),
				'clearing' => __( 'Clearing…', 'ai-content-suite' ),
				'cleared'  => __( 'Log cleared.', 'ai-content-suite' ),
				'resetConfirm' => __( 'Reset all prompt templates to the built-in defaults? Your custom edits will be lost.', 'ai-content-suite' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public function ajax_test_connection(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-suite' ) ], 403 );
		}

		$api_key = self::get_api_key();
		if ( empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'No API key configured.', 'ai-content-suite' ) ] );
		}

		try {
			$client = new AICS_Api_Client( $api_key, self::get_default_model() );
			$result = $client->generate(
				'Reply with exactly: "AI Content Suite connection OK."',
				'You are a brief responder.',
				null,
				[ 'prompt_slug' => 'connection_test', 'product_id' => 0 ]
			);
			wp_send_json_success( [
				'message' => $result['text'],
				'model'   => $result['model'],
				'usage'   => $result['usage'],
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_clear_log(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		AICS_Logger::instance()->clear();
		wp_send_json_success();
	}

	/**
	 * Handles the GET-param reset link (no JS dependency).
	 */
	public function handle_reset_prompts(): void {
		if ( ! isset( $_GET['aics_action'] ) || $_GET['aics_action'] !== 'reset_prompts' ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'aics_reset_prompts' );
		delete_option( self::OPT_PROMPTS );
		wp_redirect( admin_url( 'admin.php?page=aics-settings&aics_reset=1' ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Settings page view
	// -------------------------------------------------------------------------

	public function render_settings_page(): void {
		$api_key_set     = ! empty( self::get_api_key() );
		$default_model   = self::get_default_model();
		$model_overrides = get_option( self::OPT_MODEL_OVERRIDES, [] );
		$preview_mode    = self::is_preview_mode();
		$max_hour        = get_option( self::OPT_MAX_CALLS_HOUR, 0 );
		$max_day         = get_option( self::OPT_MAX_CALLS_DAY, 0 );
		$log             = AICS_Logger::instance()->get_log();
		$stored_prompts  = get_option( self::OPT_PROMPTS, [] );
		$default_prompts = self::default_prompts();

		$available_models = [
			'claude-haiku-4-5'  => 'Claude Haiku 4.5 (fast / cheap)',
			'claude-sonnet-4-6' => 'Claude Sonnet 4.6 (balanced)',
			'claude-opus-4-8'   => 'Claude Opus 4.8 (best quality)',
		];

		$task_labels = [
			'seo_title'         => __( 'SEO meta description (155 chars)', 'ai-content-suite' ),
			'short_description' => __( 'Short description (~20 words)', 'ai-content-suite' ),
			'long_description'  => __( 'Long description (H2 + ~50 words)', 'ai-content-suite' ),
			'custom_1'          => __( 'Branding description (H2 + 30-40 words)', 'ai-content-suite' ),
			'custom_2'          => __( 'Characteristics bullet list (HTML)', 'ai-content-suite' ),
		];

		$store_context = self::get_store_context();

		require AICS_DIR . 'admin/views/settings.php';
	}
}
