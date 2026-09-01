<?php
defined( 'ABSPATH' ) || exit;

/**
 * Translates the WRITTEN content of a product into the site's other languages,
 * and hands the result to WPML as a real translation.
 *
 * Why not WPML's own automatic translation: it bills per word in credits, on a
 * catalogue of several hundred products in two languages that is the biggest
 * line of the shop's software budget. The same words through the Anthropic key
 * already configured here cost a fraction of that, and come out in the shop's
 * own voice because the prompt and the glossary are ours.
 *
 * What this module does NOT touch: price, stock, attributes, images, taxonomy
 * structure. Those are WooCommerce Multilingual's job and it does it well —
 * the module even asks WPML to run its own custom-field sync when it creates a
 * translation, so the numbers arrive from the original rather than from us.
 *
 * Nothing is written without being read first: every translation is produced,
 * shown next to what the translation holds today, edited if needed, and only
 * then applied. A translation this module did not write is never overwritten.
 */
final class DZE_Translate {

	public const OPT   = 'dze_translate_settings';
	public const NONCE = 'dze_translate';

	/** Marks a translation as ours, with the source fingerprint it came from. */
	private const META_HASH = '_dze_tr_hash';
	private const META_MINE = '_dze_tr_by';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Admin only, by nature: nothing here has any business on a shop page.
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'add_meta_boxes', [ $this, 'popup_hook' ] );
		add_action( 'wp_ajax_dze_tr_preview', [ $this, 'ajax_preview' ] );
		add_action( 'wp_ajax_dze_tr_apply', [ $this, 'ajax_apply' ] );
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function get_settings(): array {
		$s = get_option( self::OPT, [] );
		return is_array( $s ) ? $s : [];
	}

	public function register_settings(): void {
		register_setting( 'dze_translate_options', self::OPT, [
			'sanitize_callback' => [ $this, 'sanitize' ],
			'autoload'          => false, // no shop page ever reads this.
		] );
	}

	public function sanitize( $in ): array {
		// WordPress hands a sanitizer NULL when the submitted page did not
		// carry this option at all. That is not "the shop emptied it": it is
		// "another form was saved", and answering with defaults is how a
		// setting disappears after an update nobody connected to it.
		if ( null === $in ) {
			return self::get_settings();
		}

		$in  = is_array( $in ) ? $in : [];
		$out = self::get_settings();
		if ( isset( $in['prompt'] ) ) {
			$p = trim( sanitize_textarea_field( (string) $in['prompt'] ) );
			$out['prompt'] = ( $p === trim( self::default_prompt() ) ) ? '' : $p;
		}
		if ( isset( $in['glossary'] ) ) {
			$out['glossary'] = sanitize_textarea_field( (string) $in['glossary'] );
		}
		if ( isset( $in['model'] ) ) {
			$out['model'] = sanitize_text_field( (string) $in['model'] );
		}
		if ( isset( $in['fields'] ) ) {
			$out['fields'] = array_values( array_intersect(
				array_map( 'sanitize_key', (array) $in['fields'] ),
				array_keys( self::fields() )
			) );
		}
		if ( isset( $in['create'] ) ) {
			$out['create'] = ! empty( $in['create'] ) ? 1 : 0;
		}
		return $out;
	}

	/** The shipped instructions. Empty in settings = these. */
	public static function default_prompt(): string {
		$shipped = "You translate e-commerce product copy for an online shop.\n\n"
			. "- Translate the MEANING, not the words: the result must read as if it had been written by a native copywriter of that market, never as a translation.\n"
			. "- Keep the selling tone and the level of technical detail of the original. Do not add, remove or soften an argument.\n"
			. "- Keep the HTML structure EXACTLY as it is: same tags, same attributes, same order. Translate only the text between the tags.\n"
			. "- Keep measurements, sizes, references, model names and figures identical. Convert nothing.\n"
			. "- A meta description stays under 155 characters; a meta title under 60. Rewrite rather than truncate.\n"
			. "- Never translate a brand name, a product reference, or any term listed in the glossary.";
		return class_exists( 'DZE_Prompt_Defaults' )
			? DZE_Prompt_Defaults::pick( 'translate', $shipped )
			: $shipped;
	}

	/**
	 * What the plugin sends WITH this prompt, listed for the popup that shows
	 * it. Written beside the code that builds the call, so the list and the
	 * call are read and changed together.
	 *
	 * @return string[]
	 */
	public static function prompt_data( string $id = '' ): array {
		return [
			__( 'The fields to translate, each one named, in the original language.', 'dazont-ecom' ),
			__( 'The language to translate into.', 'dazont-ecom' ),
			__( 'The answer format — the same fields back, translated, nothing added.', 'dazont-ecom' ),
		];
	}

	public static function prompt(): string {
		$p = trim( (string) ( self::get_settings()['prompt'] ?? '' ) );
		return '' !== $p ? $p : self::default_prompt();
	}

	/** Terms that stay as they are, one per line. */
	public static function glossary(): array {
		$raw = (string) ( self::get_settings()['glossary'] ?? '' );
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ?: [] ) ) );
	}

	/**
	 * The text a product carries, and where each piece lives.
	 *
	 * Only written content: everything else belongs to WooCommerce Multilingual.
	 */
	public static function fields(): array {
		return [
			'title'    => [ 'label' => __( 'Product name', 'dazont-ecom' ),             'type' => 'post', 'key' => 'post_title',   'html' => false ],
			'content'  => [ 'label' => __( 'Description', 'dazont-ecom' ),              'type' => 'post', 'key' => 'post_content', 'html' => true ],
			'excerpt'  => [ 'label' => __( 'Product short description', 'dazont-ecom' ),'type' => 'post', 'key' => 'post_excerpt', 'html' => true ],
			'seo_title'=> [ 'label' => __( 'SEO title', 'dazont-ecom' ),                'type' => 'meta', 'key' => '',             'html' => false ],
			'seo_desc' => [ 'label' => __( 'SEO description', 'dazont-ecom' ),          'type' => 'meta', 'key' => '',             'html' => false ],
		];
	}

	/** Which fields are actually sent. Absent setting = all of them. */
	public static function active_fields(): array {
		$saved = (array) ( self::get_settings()['fields'] ?? [] );
		$all   = self::fields();
		if ( ! $saved ) {
			return $all;
		}
		return array_intersect_key( $all, array_flip( $saved ) );
	}

	/** The SEO plugin's meta keys, detected by the Content module's helper. */
	private static function seo_keys(): array {
		if ( class_exists( 'DZE_Content' ) && is_callable( [ 'DZE_Content', 'seo_keys' ] ) ) {
			return (array) DZE_Content::seo_keys();
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return [ 'title' => '_yoast_wpseo_title', 'desc' => '_yoast_wpseo_metadesc' ];
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return [ 'title' => 'rank_math_title', 'desc' => 'rank_math_description' ];
		}
		return [ 'title' => '_dze_seo_title', 'desc' => '_dze_seo_desc' ];
	}

	private static function meta_key_for( string $fid ): string {
		$seo = self::seo_keys();
		if ( 'seo_title' === $fid ) {
			return (string) $seo['title'];
		}
		if ( 'seo_desc' === $fid ) {
			return (string) $seo['desc'];
		}
		return '';
	}

	// =========================================================================
	// Settings screen (a tab of the shared Settings page, never its own menu)
	// =========================================================================

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$s      = self::get_settings();
		$active = array_keys( self::active_fields() );
		$wpml   = class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active();
		?>
		<div class="dze-admin">
		<?php if ( ! $wpml ) : ?>
			<div class="notice notice-warning inline" style="margin:12px 0;"><p>
				<?php esc_html_e( 'WPML is not running: there is no second language to translate into, and nothing here will do anything.', 'dazont-ecom' ); ?>
			</p></div>
		<?php endif; ?>
		<p class="description" style="max-width:900px;">
			<?php esc_html_e( 'Translates the written content of a product into the other languages of the site, through the Anthropic key on the General tab. Price, stock, attributes and images are left to WooCommerce Multilingual. Open a product and use "Translate" in the Dazont Ecom box.', 'dazont-ecom' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_translate_options' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'What gets translated', 'dazont-ecom' ); ?></th>
					<td>
						<?php foreach ( self::fields() as $fid => $f ) : ?>
							<label style="display:block;margin-bottom:3px;">
								<input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[fields][]" value="<?php echo esc_attr( $fid ); ?>" <?php checked( in_array( $fid, $active, true ) ); ?> />
								<?php echo esc_html( $f['label'] ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Written content only. Anything not listed here is WooCommerce Multilingual\'s business.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-tr-model"><?php esc_html_e( 'Model', 'dazont-ecom' ); ?></label></th>
					<td>
						<select id="dze-tr-model" name="<?php echo esc_attr( self::OPT ); ?>[model]">
							<option value=""><?php esc_html_e( 'The model chosen on the General tab', 'dazont-ecom' ); ?></option>
							<?php foreach ( DZE_Marketing_Ai::MODELS as $mid => $mlabel ) : ?>
								<option value="<?php echo esc_attr( $mid ); ?>" <?php selected( $mid, (string) ( $s['model'] ?? '' ) ); ?>><?php echo esc_html( $mlabel ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Translating is not writing: a mid-range model reads the original and renders it faithfully for a fraction of the price. Try one product with each before running the catalogue.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-tr-glossary"><?php esc_html_e( 'Never translate', 'dazont-ecom' ); ?></label></th>
					<td>
						<textarea id="dze-tr-glossary" name="<?php echo esc_attr( self::OPT ); ?>[glossary]" rows="5" class="large-text code" placeholder="Kula Tactical&#10;Jute Land&#10;MOLLE"><?php echo esc_textarea( (string) ( $s['glossary'] ?? '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One term per line: brand names, product references, technical names that must come out untouched in every language.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-tr-prompt"><?php esc_html_e( 'Translation prompt', 'dazont-ecom' ); ?></label></th>
					<td>
						<textarea id="dze-tr-prompt" name="<?php echo esc_attr( self::OPT ); ?>[prompt]" rows="10" class="large-text code"><?php echo esc_textarea( self::prompt() ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Empty = shipped default (shown greyed). The target language, the glossary and the answer format are added automatically.', 'dazont-ecom' ); ?>
							<button type="button" class="button-link" id="dze-tr-prompt-restore">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
							<?php if ( class_exists( 'DZE_Prompt_Defaults' ) ) { DZE_Prompt_Defaults::control( 'translate', '#dze-tr-prompt' ); } ?>
						</p>
						<?php if ( class_exists( 'DZE_Prompts' ) ) { DZE_Prompts::the_data( 'translate' ); } ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Missing translations', 'dazont-ecom' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[create]" value="1" <?php checked( ! isset( $s['create'] ) || ! empty( $s['create'] ) ); ?> />
							<?php esc_html_e( 'Create the translation when the language has none yet', 'dazont-ecom' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'The new product is linked to the original and WPML is asked to copy the fields it owns — price, stock, dimensions — from it. Switch this off to work only on translations WPML has already created.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save translation settings', 'dazont-ecom' ) ); ?>
		</form>
		</div>
		<script>
		jQuery( function ( $ ) {
			// The default of a prompt is the shop's own when it set one, and it
			// can be set from this very page without reloading it.
			function dzeDef( id, shipped ) {
				return window.dzeDefaultFor ? window.dzeDefaultFor( id, shipped ) : shipped;
			}
			$( '#dze-tr-prompt-restore' ).on( 'click', function () { $( '#dze-tr-prompt' ).val( dzeDef( 'translate', <?php echo wp_json_encode( self::default_prompt() ); ?> ) ); } );
		} );
		</script>
		<?php
	}

	// =========================================================================
	// Languages
	// =========================================================================

	/** The languages a product can be translated INTO, source language aside. */
	public static function targets( int $pid ): array {
		if ( ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			return [];
		}
		$src = DZE_Wpml::post_language( $pid, 'product' ) ?: DZE_Wpml::default_language();
		$out = [];
		foreach ( DZE_Wpml::get_active_languages() as $l ) {
			$code = (string) ( $l['code'] ?? '' );
			if ( '' === $code || $code === $src ) {
				continue;
			}
			$out[ $code ] = (string) ( $l['native_name'] ?? strtoupper( $code ) );
		}
		return $out;
	}

	/** The full language name to ask the model for, not a two-letter code. */
	private static function language_name( string $code ): string {
		foreach ( DZE_Wpml::get_active_languages() as $l ) {
			if ( ( $l['code'] ?? '' ) === $code ) {
				// English name first — a model reads "French" more reliably than
				// "fr" — with the native name behind it to lift any doubt.
				$en     = trim( (string) ( $l['english_name'] ?? '' ) );
				$native = trim( (string) ( $l['native_name'] ?? '' ) );
				if ( '' !== $en && '' !== $native && $en !== $native ) {
					return $en . ' (' . $native . ')';
				}
				if ( '' !== $en || '' !== $native ) {
					return '' !== $en ? $en : $native;
				}
			}
		}
		return $code;
	}

	// =========================================================================
	// Reading a product
	// =========================================================================

	/** @return array<string,string> field id => text, empty fields dropped. */
	public static function read( int $pid ): array {
		$post = get_post( $pid );
		if ( ! $post ) {
			return [];
		}
		$out = [];
		foreach ( self::active_fields() as $fid => $f ) {
			if ( 'post' === $f['type'] ) {
				$v = (string) ( $post->{$f['key']} ?? '' );
			} else {
				$key = self::meta_key_for( $fid );
				$v   = '' !== $key ? (string) get_post_meta( $pid, $key, true ) : '';
			}
			$v = trim( $v );
			if ( '' !== $v ) {
				$out[ $fid ] = $v;
			}
		}
		return $out;
	}

	/**
	 * The fingerprint of what was translated.
	 *
	 * Stored on the translation: when the original changes, the difference is
	 * visible without keeping a copy of the text anywhere.
	 */
	public static function hash( array $texts ): string {
		ksort( $texts );
		return md5( (string) wp_json_encode( $texts ) );
	}

	// =========================================================================
	// The call
	// =========================================================================

	/**
	 * One call per language, every field in the same request.
	 *
	 * Field by field would multiply the calls and lose the consistency between
	 * a title and the description under it — the same words have to be chosen
	 * in both.
	 *
	 * @param array<string,string> $texts
	 * @return array<string,string>
	 */
	public static function translate( array $texts, string $lang_code ): array {
		if ( ! $texts ) {
			return [];
		}
		if ( ! class_exists( 'DZE_Marketing_Ai' ) ) {
			throw new RuntimeException( __( 'The Marketing Assistant module holds the Anthropic key — switch it back on.', 'dazont-ecom' ) );
		}
		$labels = self::fields();
		$lines  = [];
		foreach ( $texts as $fid => $v ) {
			$lines[] = '### ' . $fid . ' (' . ( $labels[ $fid ]['label'] ?? $fid ) . ")\n" . $v;
		}
		$glossary = self::glossary();
		$system   = self::prompt()
			. "\n\nTarget language: " . self::language_name( $lang_code ) . '.'
			. ( $glossary ? "\n\nNever translate these terms, reproduce them exactly:\n- " . implode( "\n- ", $glossary ) : '' )
			. "\n\nAnswer with STRICT JSON only: an object whose keys are the field ids given to you"
			. ' and whose values are the translated texts. No commentary, no code fence.';

		$user = "Translate every field below.\n\n" . implode( "\n\n", $lines );
		// Room for the answer: the translated text is about the size of the
		// source, plus the JSON around it.
		$max = (int) min( 8000, max( 1000, ( mb_strlen( implode( '', $texts ) ) / 2 ) + 800 ) );

		DZE_Ai_Usage::unit( 'translate' );
		try {
			$raw = DZE_Marketing_Ai::complete( $system, $user, self::model(), $max, 180 );
		} finally {
			DZE_Ai_Usage::unit();
		}
		DZE_Ai_Usage::finished( 'translate' );

		$json = trim( (string) preg_replace( '/^```(?:json)?|```$/m', '', $raw ) );
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( __( 'The model did not answer with the expected format.', 'dazont-ecom' ) );
		}
		$out = [];
		foreach ( $texts as $fid => $_ ) {
			$v = isset( $rows[ $fid ] ) ? (string) $rows[ $fid ] : '';
			if ( '' !== trim( $v ) ) {
				$out[ $fid ] = $v;
			}
		}
		return $out;
	}

	private static function model(): string {
		return (string) ( self::get_settings()['model'] ?? '' );
	}

	// =========================================================================
	// Writing the translation, WPML's way
	// =========================================================================

	/**
	 * The existing translation of a product in one language, or 0.
	 *
	 * `wpml_object_id` with $return_original = false answers 0 rather than the
	 * original when the translation does not exist — which is the question.
	 */
	public static function translation_of( int $pid, string $lang ): int {
		if ( ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			return 0;
		}
		return (int) apply_filters( 'wpml_object_id', $pid, 'product', false, $lang );
	}

	/**
	 * Creates the translated product and hands it to WPML.
	 *
	 * The order matters: the post is created, linked to the original's
	 * translation group, given its terms, and only THEN does WPML copy the
	 * custom fields it is configured to copy — prices, stock, everything
	 * WooCommerce Multilingual owns — from the original. Our translated text is
	 * written after that, so a copied field cannot land on top of it.
	 */
	private function create_translation( int $pid, string $lang ): int {
		$src = get_post( $pid );
		if ( ! $src ) {
			throw new RuntimeException( __( 'Product not found.', 'dazont-ecom' ) );
		}
		$new_id = wp_insert_post( [
			'post_type'      => 'product',
			'post_status'    => $src->post_status,
			'post_title'     => $src->post_title,
			'post_content'   => $src->post_content,
			'post_excerpt'   => $src->post_excerpt,
			'post_author'    => $src->post_author,
			'comment_status' => $src->comment_status,
			'menu_order'     => $src->menu_order,
		], true );
		if ( is_wp_error( $new_id ) ) {
			throw new RuntimeException( $new_id->get_error_message() );
		}
		$new_id = (int) $new_id;

		$src_lang = DZE_Wpml::post_language( $pid, 'product' ) ?: DZE_Wpml::default_language();
		$trid     = apply_filters( 'wpml_element_trid', null, $pid, 'post_product' );
		do_action( 'wpml_set_element_language_details', [
			'element_id'           => $new_id,
			'element_type'         => 'post_product',
			'trid'                 => $trid,
			'language_code'        => $lang,
			'source_language_code' => $src_lang,
		] );

		// Product type, categories and tags in the target language. A product
		// without its type is not a product WooCommerce can render.
		foreach ( [ 'product_type', 'product_cat', 'product_tag' ] as $tax ) {
			$terms = wp_get_object_terms( $pid, $tax, [ 'fields' => 'ids' ] );
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}
			$mapped = [];
			foreach ( $terms as $tid ) {
				// product_type is not translated; the others are.
				$m = ( 'product_type' === $tax )
					? (int) $tid
					: (int) apply_filters( 'wpml_object_id', (int) $tid, $tax, true, $lang );
				if ( $m ) {
					$mapped[] = $m;
				}
			}
			if ( $mapped ) {
				wp_set_object_terms( $new_id, $mapped, $tax );
			}
		}
		// The photographs stay the originals' until WooCommerce Multilingual is
		// told otherwise: one image library, one set of files.
		foreach ( [ '_thumbnail_id', '_product_image_gallery' ] as $mk ) {
			$v = get_post_meta( $pid, $mk, true );
			if ( '' !== $v && [] !== $v ) {
				update_post_meta( $new_id, $mk, $v );
			}
		}
		// Prices, stock, dimensions, attributes: WPML copies what it is
		// configured to copy, from the original. We never compute them.
		do_action( 'wpml_sync_all_custom_fields', $pid );

		return $new_id;
	}

	/**
	 * Writes translated text onto a translation.
	 *
	 * @param array<string,string> $texts
	 */
	private function write( int $target_id, array $texts ): void {
		$post = [];
		foreach ( self::fields() as $fid => $f ) {
			if ( ! isset( $texts[ $fid ] ) ) {
				continue;
			}
			if ( 'post' === $f['type'] ) {
				$post[ $f['key'] ] = $f['html']
					? wp_kses_post( $texts[ $fid ] )
					: sanitize_text_field( $texts[ $fid ] );
				continue;
			}
			$key = self::meta_key_for( $fid );
			if ( '' !== $key ) {
				update_post_meta( $target_id, $key, sanitize_text_field( $texts[ $fid ] ) );
			}
		}
		if ( $post ) {
			$post['ID'] = $target_id;
			wp_update_post( $post );
		}
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

	/** Translate and show, next to what the translation holds today. */
	public function ajax_preview(): void {
		$this->guard();
		$pid  = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		if ( ! $pid || '' === $lang || ! isset( self::targets( $pid )[ $lang ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown product or language.', 'dazont-ecom' ) ] );
		}
		$texts = self::read( $pid );
		if ( ! $texts ) {
			wp_send_json_error( [ 'message' => __( 'This product has no text to translate.', 'dazont-ecom' ) ] );
		}
		try {
			$new = self::translate( $texts, $lang );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		if ( ! $new ) {
			wp_send_json_error( [ 'message' => __( 'Nothing came back.', 'dazont-ecom' ) ] );
		}

		$target  = self::translation_of( $pid, $lang );
		$current = $target ? self::read( $target ) : [];
		wp_send_json_success( [
			'lang'    => $lang,
			'exists'  => (bool) $target,
			// A translation somebody else wrote is worked on differently: it is
			// shown, never replaced without being asked twice.
			'mine'    => $target ? ( '1' === (string) get_post_meta( $target, self::META_MINE, true ) ) : true,
			'source'  => $texts,
			'texts'   => $new,
			'current' => $current,
			'labels'  => array_map( static fn( $f ) => $f['label'], self::fields() ),
			'edit'    => $target ? (string) get_edit_post_link( $target, '' ) : '',
		] );
	}

	/** Apply what was read and kept. */
	public function ajax_apply(): void {
		$this->guard();
		$pid   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$lang  = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$texts = isset( $_POST['texts'] ) ? (array) wp_unslash( $_POST['texts'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized in write() by its own kind.
		if ( ! $pid || '' === $lang || ! $texts || ! isset( self::targets( $pid )[ $lang ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown product or language.', 'dazont-ecom' ) ] );
		}
		$texts = array_intersect_key( $texts, self::fields() );

		$target = self::translation_of( $pid, $lang );
		if ( ! $target ) {
			// Absent setting means "yes": a first install translates without
			// having to find a checkbox first.
			$set = self::get_settings();
			if ( isset( $set['create'] ) && empty( $set['create'] ) ) {
				wp_send_json_error( [ 'message' => __( 'There is no translation to write into, and creating one is switched off in the settings.', 'dazont-ecom' ) ] );
			}
			try {
				$target = $this->create_translation( $pid, $lang );
			} catch ( \Throwable $e ) {
				wp_send_json_error( [ 'message' => $e->getMessage() ] );
			}
		}
		$this->write( $target, array_map( 'strval', $texts ) );
		update_post_meta( $target, self::META_MINE, '1' );
		update_post_meta( $target, self::META_HASH, self::hash( self::read( $pid ) ) );

		wp_send_json_success( [
			'target' => $target,
			'edit'   => (string) get_edit_post_link( $target, '' ),
		] );
	}

	// =========================================================================
	// The popup, printed from the product screen only
	// =========================================================================

	public function popup_hook(): void {
		if ( ! self::on_product_screen() ) {
			return;
		}
		add_action( 'admin_footer', [ $this, 'popup' ] );
	}

	private static function on_product_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'product' === $screen->post_type && 'post' === $screen->base;
	}

	/**
	 * Assets, at the hour WordPress expects them.
	 *
	 * The editor in particular has to be asked for before the footer is being
	 * printed, so this hangs off admin_enqueue_scripts and not off the popup.
	 */
	public function assets( string $hook = '' ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) || ! self::on_product_screen() ) {
			return;
		}
		$pid = (int) get_the_ID();
		if ( ! $pid || ! self::targets( $pid ) ) {
			return; // a single-language site pays nothing for this module.
		}
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		wp_enqueue_editor();
		wp_enqueue_script( 'dze-translate', DZE_URL . 'admin/js/translate.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-translate', 'dzeTranslate', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'postId'  => $pid,
			'i18n'    => [
				'working'   => __( 'Translating…', 'dazont-ecom' ),
				'error'     => __( 'Something went wrong.', 'dazont-ecom' ),
				'applying'  => __( 'Saving…', 'dazont-ecom' ),
				'applied'   => __( 'Saved ✓', 'dazont-ecom' ),
				'source'    => __( 'Original', 'dazont-ecom' ),
				'current'   => __( 'The translation today', 'dazont-ecom' ),
				'empty'     => __( '(empty)', 'dazont-ecom' ),
				'keepHelp'  => __( 'Untick to leave this field out — the rest is still written', 'dazont-ecom' ),
				'willCreate'=> __( 'This language has no translation of this product yet: applying creates it, links it to this one, and lets WooCommerce Multilingual bring the price and the stock over.', 'dazont-ecom' ),
				'notMine'   => __( 'This translation was not written here. Applying replaces its text — check the "translation today" column before you do.', 'dazont-ecom' ),
				'confirm'   => __( 'Write this text on the translation?', 'dazont-ecom' ),
			],
		] );
	}

	public function popup(): void {
		global $post;
		$pid = $post ? (int) $post->ID : 0;
		if ( ! $pid ) {
			return;
		}
		$targets = self::targets( $pid );
		?>
		<div class="dze-cx-modal" id="dze-tr-modal"><div class="dze-cx-dialog" style="width:min(1100px,96vw);">
			<div class="dze-cx-head">
				<h2><?php esc_html_e( 'Translate this product', 'dazont-ecom' ); ?></h2>
				<?php if ( class_exists( 'DZE_Prompts' ) ) { DZE_Prompts::the_button( 'translate' ); } ?>
				<button type="button" class="button dze-hub-close" style="margin-left:auto;"><?php esc_html_e( 'Close', 'dazont-ecom' ); ?></button>
			</div>
			<div class="dze-cx-body">
				<?php if ( ! $targets ) : ?>
					<p><?php esc_html_e( 'No other language is active on this site, or WPML is not running. Nothing to translate into.', 'dazont-ecom' ); ?></p>
				<?php else : ?>
					<p class="description" style="max-width:900px;">
						<?php esc_html_e( 'Written content only: name, description, short description and SEO fields. Price, stock, attributes and images stay with WooCommerce Multilingual, which syncs them from this product. Nothing is saved until you apply.', 'dazont-ecom' ); ?>
					</p>
					<p class="dze-tr-bar">
						<label><span><?php esc_html_e( 'Language', 'dazont-ecom' ); ?></span>
							<select id="dze-tr-lang">
								<?php foreach ( $targets as $code => $name ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name . ' (' . strtoupper( $code ) . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<button type="button" class="button button-primary" id="dze-tr-run"><?php esc_html_e( 'Translate', 'dazont-ecom' ); ?></button>
						<span class="dze-cx-state" id="dze-tr-state"></span>
					</p>
					<div id="dze-tr-result" class="dze-cx-result" style="display:none;">
						<div id="dze-tr-warn"></div>
						<div class="dze-cb-prev" id="dze-tr-drawers"></div>
						<p class="dze-cb-panelbar">
							<button type="button" class="button button-primary" id="dze-tr-apply"><?php esc_html_e( 'Apply to the translation', 'dazont-ecom' ); ?></button>
							<a href="#" class="button" id="dze-tr-open" target="_blank" rel="noopener" style="display:none;"><?php esc_html_e( 'Open the translation', 'dazont-ecom' ); ?></a>
							<span class="dze-cb-panelstate" id="dze-tr-applystate"></span>
						</p>
					</div>
				<?php endif; ?>
			</div>
		</div></div>
		<?php
	}
}
