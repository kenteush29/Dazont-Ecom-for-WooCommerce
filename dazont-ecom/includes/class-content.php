<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI Product Content — generates product texts (descriptions, technical
 * bullet-list, SEO meta, short description, attributes/colours/materials) with
 * Claude, and "product in situation" images with fal.ai (image-to-image,
 * nano-banana-2/edit — the exact workflow validated by the shop owner).
 *
 * Text runs on the shared Anthropic key (DZE_Marketing_Ai). Images run on a
 * fal.ai key, read from the DZE_FAL_API_KEY constant (wp-config.php) when
 * defined, otherwise a settings field. Keys are only ever sent to their own
 * provider and never committed to the repository.
 *
 * The field prompts below are editable in the settings, pre-filled with the
 * approved defaults so a fresh install already behaves like the tested sheet.
 */
final class DZE_Content {

	public const OPT_SETTINGS = 'dze_content_settings';
	private const NONCE       = 'dze_content';

	private const FAL_ENDPOINT = 'https://fal.run/fal-ai/nano-banana-2/edit';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init',     [ $this, 'register_settings' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_dze_content_text',  [ $this, 'ajax_text' ] );
		add_action( 'wp_ajax_dze_content_image', [ $this, 'ajax_image' ] );
	}

	// =========================================================================
	// Field definitions (id => label + default prompt). Editable in settings.
	// =========================================================================

	/** @return array<string,array{label:string,prompt:string,tokens:int}> */
	public static function fields(): array {
		return [
			'description' => [
				'label'  => __( 'Main description', 'dazont-ecom' ),
				'tokens' => 500,
				'prompt' => "Write a product description for the storefront.\n"
					. "- Language: English. Tone: informative and expert.\n"
					. "- Open with an <h2> subtitle that highlights the product's main characteristic (be creative, never reuse the same catchphrase).\n"
					. "- Then ~50 words of description adapted to the product (use, season, activity, terrain, colour range…).\n"
					. "- Do NOT mention sizes, and do not name an unknown supplier.\n"
					. "- Write like a human: no repetitive AI patterns.\n"
					. "- Use HTML tags: <h2>subtitle</h2> then the paragraph. Output nothing but the description.",
			],
			'short' => [
				'label'  => __( 'Short description', 'dazont-ecom' ),
				'tokens' => 200,
				'prompt' => "Write a short product description of about 20 words.\n"
					. "- Language: English. Originality 8/10.\n"
					. "- Emphasise what makes this product different in its category; speak the customer's language with concrete uses.\n"
					. "- Output nothing but the description.",
			],
			'technical' => [
				'label'  => __( 'Technical (bullet list)', 'dazont-ecom' ),
				'tokens' => 400,
				'prompt' => "Write a bullet list of the product's key features.\n"
					. "- Language: English. Very concise — a feature or benefit per line, not full sentences.\n"
					. "- Do NOT mention sizes, the brand, dropshipping/wholesale, a Chinese origin, or absurd AliExpress specs.\n"
					. "- Where possible add figures (materials, etc.) to reinforce quality.\n"
					. "- You may add, omit or invent plausible features to improve quality.\n"
					. "- Output ONLY a <ul><li>…</li></ul> list, no title, no numbering, no italics. You may bold key items with <strong> (sparingly).",
			],
			'seo' => [
				'label'  => __( 'SEO meta description', 'dazont-ecom' ),
				'tokens' => 150,
				'prompt' => "Write a Google SERP meta description, 155 characters maximum.\n"
					. "- Language: English. Originality 7/10.\n"
					. "- Use an original, captivating sentence structure (this runs on many products — avoid duplicates) and proven CTR formulas.\n"
					. "- Output nothing but the description.",
			],
			'branding' => [
				'label'  => __( 'Branding paragraph', 'dazont-ecom' ),
				'tokens' => 200,
				'prompt' => "Write an extra product paragraph of about 30-40 words.\n"
					. "- Language: English. Tone: informative.\n"
					. "- Do not repeat the product title. Develop the idea of the given <h2> subtitle (or invent a fitting one) and include it.\n"
					. "- Format: <h2>subtitle</h2> then the paragraph. Output nothing else.",
			],
			'attributes' => [
				'label'  => __( 'Attributes', 'dazont-ecom' ),
				'tokens' => 120,
				'prompt' => "Extract clean WooCommerce product attributes from the supplier data.\n"
					. "- Separate values with \"|\" and no spaces. First letter capitalised.\n"
					. "- China origin must be \"PRC\"; gender must be male|female.\n"
					. "- If you cannot find the attribute, return an empty response. Output only the result.",
			],
			'colors' => [
				'label'  => __( 'Colours', 'dazont-ecom' ),
				'tokens' => 80,
				'prompt' => "Extract the product colours.\n"
					. "- Language: English. Format e.g. Beige|Gray|Black — separated by \"|\", no spaces, first letter capitalised.\n"
					. "- Clean them to the niche's norms. If none specified, use the niche's most common colours. If too little info, return empty.\n"
					. "- Output only the result.",
			],
			'materials' => [
				'label'  => __( 'Materials', 'dazont-ecom' ),
				'tokens' => 80,
				'prompt' => "Extract the product materials.\n"
					. "- Language: English. Format e.g. Cotton|Jute|Metal — separated by \"|\", no spaces, first letter capitalised.\n"
					. "- Clean them to the niche's norms. If none specified, use the niche's most common materials. If too little info, return empty.\n"
					. "- Output only the result.",
			],
		];
	}

	public static function default_image_prompt(): string {
		return "Create a visual of this product in its favourite context of use.\n"
			. "Rules:\n"
			. "- No text on the image.\n"
			. "- UGC photoshoot style.\n"
			. "- Pay attention to details so as not to upset professionals of the sector.\n"
			. "- Be careful about the product type: not all products are meant to be worn in the field.\n"
			. "- Image quality: realistic with human imperfections.";
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function get_settings(): array {
		$s = get_option( self::OPT_SETTINGS, [] );
		return is_array( $s ) ? $s : [];
	}

	public static function fal_key(): string {
		if ( defined( 'DZE_FAL_API_KEY' ) && DZE_FAL_API_KEY ) {
			return (string) DZE_FAL_API_KEY;
		}
		return (string) ( self::get_settings()['fal_key'] ?? '' );
	}

	public static function store_context(): string {
		return (string) ( self::get_settings()['store_context'] ?? '' );
	}

	public static function prompt_for( string $field ): string {
		$s    = self::get_settings();
		$flds = self::fields();
		$key  = 'prompt_' . $field;
		if ( ! empty( $s[ $key ] ) ) {
			return (string) $s[ $key ];
		}
		if ( 'image' === $field ) {
			return ! empty( $s['prompt_image'] ) ? (string) $s['prompt_image'] : self::default_image_prompt();
		}
		return $flds[ $field ]['prompt'] ?? '';
	}

	public function register_settings(): void {
		register_setting( 'dze_content_options', self::OPT_SETTINGS, [
			'sanitize_callback' => [ $this, 'sanitize' ],
			'autoload'          => false,
		] );
	}

	public function sanitize( $in ): array {
		$in  = is_array( $in ) ? $in : [];
		$out = self::get_settings();

		// fal.ai key: keep the stored one when left blank; ignored if the
		// constant is defined (the field is shown read-only then).
		if ( ! defined( 'DZE_FAL_API_KEY' ) ) {
			$key = trim( (string) ( $in['fal_key'] ?? '' ) );
			$out['fal_key'] = '' !== $key ? sanitize_text_field( $key ) : (string) ( $out['fal_key'] ?? '' );
		}
		$out['store_context'] = sanitize_textarea_field( (string) ( $in['store_context'] ?? ( $out['store_context'] ?? '' ) ) );

		foreach ( array_keys( self::fields() ) as $fid ) {
			$k = 'prompt_' . $fid;
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_textarea_field( (string) $in[ $k ] );
			}
		}
		if ( isset( $in['prompt_image'] ) ) {
			$out['prompt_image'] = sanitize_textarea_field( (string) $in['prompt_image'] );
		}
		return $out;
	}

	/** Rendered inside the AI Settings → "Product content" tab. */
	public function render_settings_section(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s          = self::get_settings();
		$opt        = self::OPT_SETTINGS;
		$fal_locked = defined( 'DZE_FAL_API_KEY' );
		$has_fal    = self::fal_key() !== '';
		?>
		<p class="description" style="max-width:860px;">
			<?php esc_html_e( 'Generate product texts with Claude and "product in situation" images with fal.ai, from each product edit screen. Texts use the Anthropic key set on the General tab; images use a fal.ai key. The prompts below are pre-filled with the validated defaults — tweak them to your brand voice.', 'dazont-ecom' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_content_options' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dze-fal-key"><?php esc_html_e( 'fal.ai API key', 'dazont-ecom' ); ?></label></th>
					<td>
						<?php if ( $fal_locked ) : ?>
							<input type="text" class="regular-text" value="<?php esc_attr_e( 'Set via DZE_FAL_API_KEY constant', 'dazont-ecom' ); ?>" disabled />
						<?php else : ?>
							<input type="password" id="dze-fal-key" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[fal_key]" value="" autocomplete="new-password" placeholder="<?php echo $has_fal ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'dazont-ecom' ) : esc_attr_e( 'Paste your fal.ai key', 'dazont-ecom' ); ?>" />
							<p class="description"><?php esc_html_e( 'From fal.ai/dashboard/keys. Used for image generation only (nano-banana-2/edit, ~$0.04/image). For production, define DZE_FAL_API_KEY in wp-config.php instead.', 'dazont-ecom' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-store-context"><?php esc_html_e( 'Store context', 'dazont-ecom' ); ?></label></th>
					<td>
						<textarea id="dze-store-context" name="<?php echo esc_attr( $opt ); ?>[store_context]" rows="2" class="large-text"><?php echo esc_textarea( (string) ( $s['store_context'] ?? '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Prepended to every generation, e.g. "Kula Tactical > Military / tactical clothing and gear > Tone: sharp, authoritative, informational".', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Field prompts', 'dazont-ecom' ); ?></h3>
			<table class="form-table" role="presentation">
				<?php foreach ( self::fields() as $fid => $f ) : ?>
					<tr>
						<th scope="row"><label for="dze-prompt-<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( $f['label'] ); ?></label></th>
						<td><textarea id="dze-prompt-<?php echo esc_attr( $fid ); ?>" name="<?php echo esc_attr( $opt ); ?>[prompt_<?php echo esc_attr( $fid ); ?>]" rows="4" class="large-text code"><?php echo esc_textarea( self::prompt_for( $fid ) ); ?></textarea></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="dze-prompt-image"><?php esc_html_e( 'Scene image prompt', 'dazont-ecom' ); ?></label></th>
					<td><textarea id="dze-prompt-image" name="<?php echo esc_attr( $opt ); ?>[prompt_image]" rows="4" class="large-text code"><?php echo esc_textarea( self::prompt_for( 'image' ) ); ?></textarea></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	// =========================================================================
	// Product metabox
	// =========================================================================

	public function add_meta_box(): void {
		add_meta_box( 'dze-content-box', __( 'AI Content (Dazont)', 'dazont-ecom' ), [ $this, 'render_meta_box' ], 'product', 'normal', 'high' );
	}

	public function render_meta_box( $post ): void {
		$fields = self::fields();
		?>
		<div class="dze-content-mb">
			<p class="description"><?php esc_html_e( 'Paste the raw supplier text, then generate each field. Review before saving — nothing is written to the product automatically except images you insert.', 'dazont-ecom' ); ?></p>
			<textarea id="dze-content-supplier" rows="4" style="width:100%;" placeholder="<?php esc_attr_e( 'Supplier data (raw description, specs…)', 'dazont-ecom' ); ?>"></textarea>
			<p style="margin:10px 0;">
				<?php foreach ( $fields as $fid => $f ) : ?>
					<button type="button" class="button dze-content-gen" data-field="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( $f['label'] ); ?></button>
				<?php endforeach; ?>
			</p>
			<div id="dze-content-out"></div>

			<hr />
			<h4 style="margin:6px 0;"><?php esc_html_e( 'Scene image (fal.ai)', 'dazont-ecom' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Uses the product\'s featured image as the source. The result is added to the product gallery.', 'dazont-ecom' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="dze-content-img"><?php esc_html_e( 'Generate scene image', 'dazont-ecom' ); ?></button>
				<span id="dze-content-img-status" style="margin-left:8px;"></span>
			</p>
			<div id="dze-content-img-out"></div>
		</div>
		<?php
	}

	public function enqueue( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_product_edit = $screen && 'product' === $screen->post_type && in_array( $hook, [ 'post.php', 'post-new.php' ], true );
		if ( ! $is_product_edit ) {
			return;
		}
		wp_enqueue_script( 'dze-content', DZE_URL . 'admin/js/content.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-content', 'dzeContent', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'postId'  => get_the_ID(),
			'i18n'    => [
				'generating' => __( 'Generating…', 'dazont-ecom' ),
				'error'      => __( 'Something went wrong.', 'dazont-ecom' ),
				'copy'       => __( 'Copy', 'dazont-ecom' ),
				'copied'     => __( 'Copied ✓', 'dazont-ecom' ),
				'insertDesc' => __( 'Insert into description', 'dazont-ecom' ),
				'insertShort'=> __( 'Insert into short description', 'dazont-ecom' ),
				'imgWait'    => __( 'Rendering the image — this can take up to a minute…', 'dazont-ecom' ),
				'noThumb'    => __( 'Set a featured image on this product first.', 'dazont-ecom' ),
				'imgAdded'   => __( 'Image added to the product gallery.', 'dazont-ecom' ),
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

	public function ajax_text(): void {
		$this->guard();
		$field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$fields = self::fields();
		if ( ! isset( $fields[ $field ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown field.', 'dazont-ecom' ) ] );
		}
		$pid      = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$supplier = isset( $_POST['supplier'] ) ? sanitize_textarea_field( wp_unslash( $_POST['supplier'] ) ) : '';
		$title    = $pid ? get_the_title( $pid ) : '';

		$system = 'You are an expert e-commerce copywriter. ' . self::store_context();
		$user   = self::prompt_for( $field ) . "\n\n";
		if ( $title ) {
			$user .= "Product title: {$title}\n";
		}
		if ( $supplier ) {
			$user .= "Supplier data:\n{$supplier}\n";
		}
		if ( ! $title && ! $supplier ) {
			wp_send_json_error( [ 'message' => __( 'Add a product title or paste supplier data first.', 'dazont-ecom' ) ] );
		}

		try {
			$text = DZE_Marketing_Ai::complete( $system, $user, self::model(), (int) ( $fields[ $field ]['tokens'] ?? 400 ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'text' => $text, 'field' => $field ] );
	}

	private static function model(): string {
		// Reuse the Sourcing "report" model if set, else the main model.
		if ( class_exists( 'DZE_Marketing_Ai' ) ) {
			$m = (string) ( DZE_Marketing_Ai::get_settings()['insights_model'] ?? '' );
			return $m !== '' ? $m : DZE_Marketing_Ai::chosen_model();
		}
		return '';
	}

	public function ajax_image(): void {
		$this->guard();
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Save the product first.', 'dazont-ecom' ) ] );
		}
		if ( '' === self::fal_key() ) {
			wp_send_json_error( [ 'message' => __( 'Add your fal.ai key under AI Settings → Product content first.', 'dazont-ecom' ) ] );
		}
		$thumb_id = get_post_thumbnail_id( $pid );
		$src       = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '';
		if ( ! $src ) {
			wp_send_json_error( [ 'message' => __( 'Set a featured image on this product first.', 'dazont-ecom' ) ] );
		}

		// Build the prompt: store context + product context + the scene rules.
		$title = get_the_title( $pid );
		$desc  = wp_strip_all_tags( (string) get_post_field( 'post_content', $pid ) );
		$desc  = mb_substr( trim( preg_replace( '/\s+/', ' ', $desc ) ), 0, 600 );
		$ctx   = trim( self::store_context() . ' ' . $title . '. ' . $desc );
		$prompt = ( $ctx ? "Product context: {$ctx}\n\n" : '' ) . self::prompt_for( 'image' );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		try {
			$image_url = $this->fal_generate( $prompt, [ $src ] );
			$att_id    = $this->sideload_to_gallery( $image_url, $pid );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'attachment' => $att_id,
			'url'        => wp_get_attachment_image_url( $att_id, 'medium' ),
		] );
	}

	/** Calls fal.ai nano-banana-2/edit (image-to-image) and returns the result URL. */
	private function fal_generate( string $prompt, array $image_urls ): string {
		$resp = wp_remote_post( self::FAL_ENDPOINT, [
			'timeout' => 120,
			'headers' => [
				'Authorization' => 'Key ' . self::fal_key(),
				'content-type'  => 'application/json',
			],
			'body'    => wp_json_encode( [
				'prompt'        => $prompt,
				'image_urls'    => array_values( $image_urls ),
				'num_images'    => 1,
				'aspect_ratio'  => 'auto',
				'output_format' => 'png',
			] ),
		] );
		if ( is_wp_error( $resp ) ) {
			throw new RuntimeException( $resp->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $body['detail'] ?? ( is_array( $body ) ? wp_json_encode( $body ) : ( 'HTTP ' . $code ) );
			throw new RuntimeException( sprintf( __( 'fal.ai error: %s', 'dazont-ecom' ), is_string( $msg ) ? $msg : 'HTTP ' . $code ) );
		}
		$url = $body['images'][0]['url'] ?? '';
		if ( ! $url ) {
			throw new RuntimeException( __( 'fal.ai returned no image.', 'dazont-ecom' ) );
		}
		return (string) $url;
	}

	/** Downloads a remote image into the media library and appends it to the product gallery. */
	private function sideload_to_gallery( string $url, int $pid ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$att_id = media_sideload_image( $url, $pid, get_the_title( $pid ) . ' — AI scene', 'id' );
		if ( is_wp_error( $att_id ) ) {
			throw new RuntimeException( $att_id->get_error_message() );
		}
		$gallery = (string) get_post_meta( $pid, '_product_image_gallery', true );
		$ids     = array_filter( array_map( 'absint', explode( ',', $gallery ) ) );
		$ids[]   = (int) $att_id;
		update_post_meta( $pid, '_product_image_gallery', implode( ',', array_unique( $ids ) ) );
		return (int) $att_id;
	}
}
