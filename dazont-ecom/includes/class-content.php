<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI Product Content — turns the raw imported product data (title, description,
 * attributes, cost) into every published field with Claude, generates images
 * with fal.ai from prompt templates, and recalculates the price from a
 * cost-multiplier table (the current/import price is treated as COGS).
 *
 * Workflow: product imported from a platform (AliExpress, eBay, Etsy, CJ,
 * Printify…) → its COMPLETE data is the generation context → each text field
 * (Title, Description incl. technical bullets, Short description, Attributes,
 * SEO title/description, Custom blocs 1-2) is generated from its stored prompt
 * and written to its MAPPED destination (post field / SEO plugin meta / custom
 * meta / real WooCommerce attributes) → images from templates (scene,
 * additional, remake-main) → price recalculated → ready to publish.
 *
 * Single-product: a small side box opens the toolbox modal (Text/Image/Price).
 * Bulk: a "Generate AI content" bulk action on the products list opens a bulk
 * screen with large thumbnails, per-row image choice and a task runner.
 *
 * A validation gate ("prompts validated") keeps everything preview-only until
 * the prompts have been reviewed and accepted in the settings.
 *
 * Keys: text on the shared Anthropic key (DZE_Marketing_Ai::complete()), images
 * on a fal.ai key (DZE_FAL_API_KEY constant or the General-tab field). Each key
 * is only ever sent to its own provider.
 */
final class DZE_Content {

	public const OPT_SETTINGS = 'dze_content_settings';
	private const NONCE       = 'dze_content';

	private const FAL_ENDPOINT = 'https://fal.run/fal-ai/nano-banana-2/edit';

	public const BULK_SLUG   = 'dazont-content-bulk';
	private const BULK_ACTION = 'dze_ai_content';

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
		add_action( 'admin_menu',     [ $this, 'register_bulk_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );

		// Bulk action on the products list → bulk screen.
		add_filter( 'bulk_actions-edit-product',        [ $this, 'register_bulk_action' ] );
		add_filter( 'handle_bulk_actions-edit-product', [ $this, 'handle_bulk_action' ], 10, 3 );

		add_action( 'wp_ajax_dze_content_text',  [ $this, 'ajax_text' ] );
		add_action( 'wp_ajax_dze_content_apply', [ $this, 'ajax_apply' ] );
		add_action( 'wp_ajax_dze_content_image', [ $this, 'ajax_image' ] );
		add_action( 'wp_ajax_dze_content_price', [ $this, 'ajax_price' ] );
	}

	// =========================================================================
	// Field / template / price-table definitions
	// =========================================================================

	/** @return array<string,array{label:string,dest:string,tokens:int,prompt:string}> */
	public static function fields(): array {
		return [
			'title' => [
				'label'  => __( 'Title', 'dazont-ecom' ),
				'dest'   => 'post_title',
				'tokens' => 80,
				'prompt' => "Write an SEO-optimised product title (max ~70 characters). Natural, human, no ALL CAPS, no supplier gibberish. Output only the title.",
			],
			'description' => [
				'label'  => __( 'Description (+ technical bullets)', 'dazont-ecom' ),
				'dest'   => 'post_content',
				'tokens' => 700,
				'prompt' => "Write the product description for the storefront, English, expert & human tone.\n"
					. "- Open with an <h2> subtitle highlighting the main characteristic (be creative).\n"
					. "- ~50 words adapted to the product (use, season, activity, terrain, colours…).\n"
					. "- Then a <ul><li>…</li></ul> technical bullet list of key features/benefits — concise, figures where possible, <strong> on a few key items.\n"
					. "- Do NOT mention sizes, unknown suppliers, dropshipping/wholesale, Chinese origin, or absurd specs.\n"
					. "- No repetitive AI patterns. Output only HTML (h2 + paragraph + ul), nothing else.",
			],
			'short' => [
				'label'  => __( 'Short description', 'dazont-ecom' ),
				'dest'   => 'post_excerpt',
				'tokens' => 200,
				'prompt' => "Write a ~20-word short description, English, originality 8/10. Emphasise what makes this product different; concrete uses. Output only the text.",
			],
			'attributes' => [
				'label'  => __( 'Attributes', 'dazont-ecom' ),
				'dest'   => 'attributes',
				'tokens' => 200,
				'prompt' => "Extract clean WooCommerce attributes, one per line, as \"Name: value|value\" (e.g. Color: Black|Tan, Materials: Nylon|Polyester, Gender: male, Origin: PRC for China, Specifications: …). Capitalise first letters, no spaces around \"|\". Skip anything you cannot infer. Output only the lines.",
			],
			'seo_title' => [
				'label'  => __( 'SEO title', 'dazont-ecom' ),
				'dest'   => 'seo_title',
				'tokens' => 60,
				'prompt' => "Write an SEO meta title (max ~60 characters), compelling for Google SERP, English. Output only the title.",
			],
			'seo_description' => [
				'label'  => __( 'SEO description', 'dazont-ecom' ),
				'dest'   => 'seo_desc',
				'tokens' => 120,
				'prompt' => "Write a Google SERP meta description, 155 characters maximum, English, original structure, high-CTR. Output only the description.",
			],
			'bloc1' => [
				'label'  => __( 'Custom bloc text 1', 'dazont-ecom' ),
				'dest'   => 'meta',
				'tokens' => 200,
				'prompt' => "Write an extra branding paragraph (~30-40 words), English. Include a fitting <h2> subtitle then develop it. Do not repeat the title. Output <h2>…</h2> then the paragraph only.",
			],
			'bloc2' => [
				'label'  => __( 'Custom bloc text 2', 'dazont-ecom' ),
				'dest'   => 'meta',
				'tokens' => 200,
				'prompt' => "Write a second branding paragraph (~30-40 words), English, different angle from bloc 1 (reassurance, use-case, quality). Include a <h2> subtitle. Output <h2>…</h2> then the paragraph only.",
			],
		];
	}

	public static function default_image_templates(): array {
		return [
			[ 'name' => 'Scene (in use)', 'target' => 'gallery', 'prompt' => "Create a UGC-style photoshoot of this product in its favourite context of use. No text on the image. Careful with the exact product type (not everything is worn in the field). Realistic, with human imperfections." ],
			[ 'name' => 'Additional angle', 'target' => 'gallery', 'prompt' => "Create an additional clean product shot from a different angle, neutral background, e-commerce quality. No text. Keep the product identical." ],
			[ 'name' => 'Remake main (studio)', 'target' => 'main', 'prompt' => "Recreate a clean, well-lit studio main image of this exact product on a neutral background, sharp, e-commerce ready. No text, no props. Keep the product faithful." ],
		];
	}

	/** Default cost → regular-price multiplier table (from the shop's pricing sheet). */
	public static function default_price_table(): array {
		return [
			[ 'min' => 0,   'max' => 5,   'mult' => 4 ],
			[ 'min' => 5,   'max' => 15,  'mult' => 3 ],
			[ 'min' => 15,  'max' => 50,  'mult' => 2.7 ],
			[ 'min' => 50,  'max' => 200, 'mult' => 2.5 ],
			[ 'min' => 200, 'max' => 500, 'mult' => 2.2 ],
			[ 'min' => 500, 'max' => 0,   'mult' => 2 ], // max 0 = no upper bound.
		];
	}

	/** Selectable destinations for the field mapping. */
	public static function dest_options(): array {
		return [
			'default'      => __( 'Default destination', 'dazont-ecom' ),
			'post_title'   => __( 'Product title', 'dazont-ecom' ),
			'post_content' => __( 'Product description', 'dazont-ecom' ),
			'post_excerpt' => __( 'Short description', 'dazont-ecom' ),
			'seo_title'    => __( 'SEO title (SEO plugin meta)', 'dazont-ecom' ),
			'seo_desc'     => __( 'SEO description (SEO plugin meta)', 'dazont-ecom' ),
			'attributes'   => __( 'WooCommerce attributes', 'dazont-ecom' ),
			'meta'         => __( 'Custom field (meta key)', 'dazont-ecom' ),
		];
	}

	// =========================================================================
	// Settings storage + helpers
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

	/** Legacy global flag: true only when EVERY text-field prompt is validated. */
	public static function prompts_validated(): bool {
		foreach ( array_keys( self::fields() ) as $fid ) {
			if ( ! self::field_validated( $fid ) ) {
				return false;
			}
		}
		return true;
	}

	/** Per-prompt validation. Legacy installs that ticked the old global box count as validated. */
	public static function field_validated( string $field ): bool {
		$s = self::get_settings();
		if ( isset( $s['fv'] ) && is_array( $s['fv'] ) ) {
			return ! empty( $s['fv'][ $field ] );
		}
		return ! empty( $s['prompts_validated'] ); // legacy fallback.
	}

	/** Per image-template validation (index into image_templates()). */
	public static function template_validated( int $idx ): bool {
		$tpls = self::image_templates();
		if ( ! isset( $tpls[ $idx ] ) ) {
			return false;
		}
		if ( array_key_exists( 'valid', $tpls[ $idx ] ) ) {
			return ! empty( $tpls[ $idx ]['valid'] );
		}
		return ! empty( self::get_settings()['prompts_validated'] ); // legacy fallback.
	}

	/** [ validated, total ] across text prompts, for the side-box note. */
	public static function validated_counts(): array {
		$total = count( self::fields() );
		$ok    = 0;
		foreach ( array_keys( self::fields() ) as $fid ) {
			if ( self::field_validated( $fid ) ) {
				$ok++;
			}
		}
		return [ $ok, $total ];
	}

	public static function prompt_for( string $field ): string {
		$s = self::get_settings();
		if ( ! empty( $s[ 'prompt_' . $field ] ) ) {
			return (string) $s[ 'prompt_' . $field ];
		}
		return self::fields()[ $field ]['prompt'] ?? '';
	}

	public static function price_table(): array {
		$t = self::get_settings()['price_table'] ?? null;
		return ( is_array( $t ) && ! empty( $t ) ) ? $t : self::default_price_table();
	}

	public static function image_templates(): array {
		$t = self::get_settings()['image_templates'] ?? null;
		return ( is_array( $t ) && ! empty( $t ) ) ? $t : self::default_image_templates();
	}

	/** Multiplier for a given cost. */
	public static function mult_for_cost( float $cost ): float {
		foreach ( self::price_table() as $row ) {
			$min = (float) ( $row['min'] ?? 0 );
			$max = (float) ( $row['max'] ?? 0 );
			if ( $cost >= $min && ( $max <= 0 || $cost < $max ) ) {
				return (float) ( $row['mult'] ?? 1 );
			}
		}
		return 1.0;
	}

	/**
	 * Where a field's output goes: the mapped destination if set, else the
	 * field's default. @return array{type:string,key?:string}
	 */
	private static function dest_for( string $field ): array {
		$s   = self::get_settings();
		$sel = (string) ( $s[ 'dest_' . $field ] ?? 'default' );
		if ( 'default' === $sel || '' === $sel || ! array_key_exists( $sel, self::dest_options() ) ) {
			$sel = self::fields()[ $field ]['dest'] ?? 'meta';
		}
		if ( 'meta' === $sel ) {
			// metakey_<field>, legacy map_<field>, then a sane default.
			$key = (string) ( $s[ 'metakey_' . $field ] ?? '' );
			if ( '' === $key ) {
				$key = (string) ( $s[ 'map_' . $field ] ?? '' );
			}
			return [ 'type' => 'meta', 'key' => $key !== '' ? $key : '_dze_' . $field ];
		}
		return [ 'type' => $sel ];
	}

	/** SEO plugin meta keys, auto-detected (Yoast, Rank Math, fallback own). */
	private static function seo_keys(): array {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return [ 'title' => '_yoast_wpseo_title', 'desc' => '_yoast_wpseo_metadesc' ];
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return [ 'title' => 'rank_math_title', 'desc' => 'rank_math_description' ];
		}
		return [ 'title' => '_dze_seo_title', 'desc' => '_dze_seo_desc' ];
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

		if ( ! defined( 'DZE_FAL_API_KEY' ) && isset( $in['fal_key'] ) ) {
			$k = trim( (string) $in['fal_key'] );
			$out['fal_key'] = '' !== $k ? sanitize_text_field( $k ) : (string) ( $out['fal_key'] ?? '' );
		}
		// The General-tab mini form only posts fal_key: don't touch the rest then.
		if ( isset( $in['fal_key'] ) && count( $in ) <= 2 ) {
			return $out;
		}

		if ( isset( $in['store_context'] ) ) {
			$out['store_context'] = sanitize_textarea_field( (string) $in['store_context'] );
		}
		$dests = array_keys( self::dest_options() );
		$fv    = [];
		foreach ( array_keys( self::fields() ) as $fid ) {
			if ( isset( $in[ 'prompt_' . $fid ] ) ) {
				$out[ 'prompt_' . $fid ] = sanitize_textarea_field( (string) $in[ 'prompt_' . $fid ] );
			}
			if ( isset( $in[ 'dest_' . $fid ] ) && in_array( $in[ 'dest_' . $fid ], $dests, true ) ) {
				$out[ 'dest_' . $fid ] = (string) $in[ 'dest_' . $fid ];
			}
			if ( isset( $in[ 'metakey_' . $fid ] ) ) {
				$out[ 'metakey_' . $fid ] = sanitize_key( (string) $in[ 'metakey_' . $fid ] );
			}
			$fv[ $fid ] = ! empty( $in['fv'][ $fid ] ) ? 1 : 0;
		}
		$out['fv'] = $fv; // per-prompt validation (individual checkboxes).
		// Price table.
		if ( isset( $in['pt_min'] ) && is_array( $in['pt_min'] ) ) {
			$rows = [];
			$mins = array_map( 'floatval', $in['pt_min'] );
			$maxs = array_map( 'floatval', (array) ( $in['pt_max'] ?? [] ) );
			$muls = array_map( 'floatval', (array) ( $in['pt_mult'] ?? [] ) );
			foreach ( $mins as $i => $mn ) {
				$ml = (float) ( $muls[ $i ] ?? 0 );
				if ( $ml <= 0 ) {
					continue;
				}
				$rows[] = [ 'min' => (float) $mn, 'max' => (float) ( $maxs[ $i ] ?? 0 ), 'mult' => $ml ];
			}
			if ( $rows ) {
				$out['price_table'] = $rows;
			}
		}
		// Image templates (indexed so the per-row "validated" checkbox stays aligned).
		if ( isset( $in['it_name'] ) && is_array( $in['it_name'] ) ) {
			$tpls = [];
			foreach ( $in['it_name'] as $i => $nm ) {
				$nm = sanitize_text_field( (string) $nm );
				$pr = sanitize_textarea_field( (string) ( $in['it_prompt'][ $i ] ?? '' ) );
				$tg = ( ( $in['it_target'][ $i ] ?? 'gallery' ) === 'main' ) ? 'main' : 'gallery';
				if ( '' !== $nm && '' !== $pr ) {
					$tpls[] = [
						'name'   => $nm,
						'target' => $tg,
						'prompt' => $pr,
						'valid'  => ! empty( $in['it_valid'][ $i ] ) ? 1 : 0,
					];
				}
			}
			if ( $tpls ) {
				$out['image_templates'] = $tpls;
			}
		}
		unset( $out['prompts_validated'] ); // replaced by per-prompt validation.
		return $out;
	}

	// =========================================================================
	// Settings rendering — General tab key field + Product-content tab
	// =========================================================================

	/** fal.ai key field, shown on the General tab next to the other API keys. */
	public function render_key_field(): void {
		$fal_locked = defined( 'DZE_FAL_API_KEY' );
		$has_fal    = self::fal_key() !== '';
		?>
		<div class="dze-admin">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dze-fal-key"><?php esc_html_e( 'fal.ai API key (images)', 'dazont-ecom' ); ?></label></th>
				<td>
					<?php echo DZE_Api_Keys::status_html( 'fal', self::fal_key(), $fal_locked ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?>
					<?php if ( ! $fal_locked ) : ?>
						<form method="post" action="options.php" style="display:inline;">
							<?php settings_fields( 'dze_content_options' ); ?>
							<input type="password" id="dze-fal-key" class="regular-text" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[fal_key]" value="" autocomplete="new-password" placeholder="<?php echo $has_fal ? esc_attr__( 'Leave blank to keep the saved key', 'dazont-ecom' ) : esc_attr__( 'Paste your fal.ai key', 'dazont-ecom' ); ?>" />
							<?php submit_button( __( 'Save fal.ai key', 'dazont-ecom' ), 'secondary', 'submit', false ); ?>
							<p class="description"><?php esc_html_e( 'Used for image generation (fal.ai nano-banana-2/edit). For production, define DZE_FAL_API_KEY in wp-config.php.', 'dazont-ecom' ); ?></p>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		</div>
		<?php
	}

	/** Full "Product content" tab: prompts + field mapping, images, prices, validation. */
	public function render_settings_section(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s     = self::get_settings();
		$opt   = self::OPT_SETTINGS;
		$seo   = self::seo_keys();
		$dests = self::dest_options();
		?>
		<div class="dze-admin">
		<p class="description" style="max-width:900px;">
			<?php esc_html_e( 'Generate every product field from the imported data, generate images from templates, and recalculate the price from cost. Text uses the Anthropic key (General tab); images use the fal.ai key (General tab). Tune the prompts and the field mapping, test on real products from the toolbox (preview mode), then tick "Prompts validated" to unlock applying.', 'dazont-ecom' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_content_options' ); ?>

			<h2 class="title"><?php esc_html_e( 'Store context', 'dazont-ecom' ); ?></h2>
			<textarea name="<?php echo esc_attr( $opt ); ?>[store_context]" rows="2" class="large-text"><?php echo esc_textarea( (string) ( $s['store_context'] ?? '' ) ); ?></textarea>
			<p class="description"><?php esc_html_e( 'Prepended to every generation, e.g. "Kula Tactical > Military / tactical clothing and gear > Tone: sharp, authoritative, informational".', 'dazont-ecom' ); ?></p>

			<h2 class="title"><?php esc_html_e( 'Text fields — prompt & field mapping', 'dazont-ecom' ); ?></h2>
			<p class="description"><?php printf( /* translators: 1: seo title meta key, 2: seo desc meta key */ esc_html__( 'Detected SEO plugin meta: %1$s / %2$s. "Default destination" uses the field\'s natural target.', 'dazont-ecom' ), '<code>' . esc_html( $seo['title'] ) . '</code>', '<code>' . esc_html( $seo['desc'] ) . '</code>' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( self::fields() as $fid => $f ) :
					$cur_dest = (string) ( $s[ 'dest_' . $fid ] ?? 'default' );
					$cur_key  = (string) ( $s[ 'metakey_' . $fid ] ?? ( $s[ 'map_' . $fid ] ?? '' ) );
					?>
					<tr>
						<th scope="row">
							<label for="dze-p-<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( $f['label'] ); ?></label>
							<p style="margin:8px 0 0;">
								<select name="<?php echo esc_attr( $opt ); ?>[dest_<?php echo esc_attr( $fid ); ?>]" style="max-width:220px;">
									<?php foreach ( $dests as $dk => $dl ) : ?>
										<option value="<?php echo esc_attr( $dk ); ?>" <?php selected( $dk, $cur_dest ); ?>><?php echo esc_html( $dl ); ?><?php echo 'default' === $dk ? ' (' . esc_html( $dests[ $f['dest'] ] ?? $f['dest'] ) . ')' : ''; ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p style="margin:6px 0 0;">
								<input type="text" name="<?php echo esc_attr( $opt ); ?>[metakey_<?php echo esc_attr( $fid ); ?>]" value="<?php echo esc_attr( $cur_key ); ?>" placeholder="_dze_<?php echo esc_attr( $fid ); ?>" style="max-width:220px;" />
								<span class="description"><?php esc_html_e( 'meta key (if custom field)', 'dazont-ecom' ); ?></span>
							</p>
						</th>
						<td>
						<textarea id="dze-p-<?php echo esc_attr( $fid ); ?>" name="<?php echo esc_attr( $opt ); ?>[prompt_<?php echo esc_attr( $fid ); ?>]" rows="4" class="large-text code"><?php echo esc_textarea( self::prompt_for( $fid ) ); ?></textarea>
						<label class="dze-fv<?php echo self::field_validated( $fid ) ? ' is-ok' : ''; ?>">
							<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[fv][<?php echo esc_attr( $fid ); ?>]" value="1" <?php checked( self::field_validated( $fid ) ); ?> />
							<?php esc_html_e( 'Prompt validated — unlocks applying this field to products', 'dazont-ecom' ); ?>
						</label>
					</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2 class="title"><?php esc_html_e( 'Images (fal.ai templates)', 'dazont-ecom' ); ?></h2>
			<table class="form-table dze-it-table" role="presentation">
				<tr><th><?php esc_html_e( 'Template name', 'dazont-ecom' ); ?></th><th><?php esc_html_e( 'Target', 'dazont-ecom' ); ?></th><th style="width:55%;"><?php esc_html_e( 'Prompt', 'dazont-ecom' ); ?></th><th><?php esc_html_e( 'Validated', 'dazont-ecom' ); ?></th></tr>
				<?php $dze_ti = 0; foreach ( self::image_templates() as $t ) : ?>
					<tr>
						<td><input type="text" name="<?php echo esc_attr( $opt ); ?>[it_name][<?php echo (int) $dze_ti; ?>]" value="<?php echo esc_attr( $t['name'] ); ?>" /></td>
						<td>
							<select name="<?php echo esc_attr( $opt ); ?>[it_target][<?php echo (int) $dze_ti; ?>]">
								<option value="gallery" <?php selected( 'gallery', $t['target'] ?? 'gallery' ); ?>><?php esc_html_e( 'Add to gallery', 'dazont-ecom' ); ?></option>
								<option value="main" <?php selected( 'main', $t['target'] ?? 'gallery' ); ?>><?php esc_html_e( 'Set as main image', 'dazont-ecom' ); ?></option>
							</select>
						</td>
						<td><textarea name="<?php echo esc_attr( $opt ); ?>[it_prompt][<?php echo (int) $dze_ti; ?>]" rows="2" class="large-text"><?php echo esc_textarea( $t['prompt'] ); ?></textarea></td>
						<td>
							<label class="dze-fv<?php echo ! empty( $t['valid'] ) ? ' is-ok' : ''; ?>">
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[it_valid][<?php echo (int) $dze_ti; ?>]" value="1" <?php checked( ! empty( $t['valid'] ) ); ?> />
								<?php esc_html_e( 'Validated', 'dazont-ecom' ); ?>
							</label>
						</td>
					</tr>
				<?php $dze_ti++; endforeach; ?>
				<tr>
					<td><input type="text" name="<?php echo esc_attr( $opt ); ?>[it_name][<?php echo (int) $dze_ti; ?>]" value="" placeholder="<?php esc_attr_e( 'New template…', 'dazont-ecom' ); ?>" /></td>
					<td><select name="<?php echo esc_attr( $opt ); ?>[it_target][<?php echo (int) $dze_ti; ?>]"><option value="gallery"><?php esc_html_e( 'Add to gallery', 'dazont-ecom' ); ?></option><option value="main"><?php esc_html_e( 'Set as main image', 'dazont-ecom' ); ?></option></select></td>
					<td><textarea name="<?php echo esc_attr( $opt ); ?>[it_prompt][<?php echo (int) $dze_ti; ?>]" rows="2" class="large-text"></textarea></td>
					<td></td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'Unvalidated templates run in PREVIEW: the image is generated and shown, but not attached to the product. Validate a template to attach its results (gallery or main image).', 'dazont-ecom' ); ?></p>

			<h2 class="title"><?php esc_html_e( 'Price table (cost × multiplier → regular price)', 'dazont-ecom' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The current/import price is treated as the cost (COGS, also written to WooCommerce\'s Cost of Goods field); the matching multiplier sets the regular price. Use 0 as the upper bound of the last range for "no limit".', 'dazont-ecom' ); ?></p>
			<table class="widefat striped dze-price-table" id="dze-pt">
				<thead>
					<tr><th><?php esc_html_e( 'Cost from', 'dazont-ecom' ); ?></th><th><?php esc_html_e( 'Cost to (0 = ∞)', 'dazont-ecom' ); ?></th><th><?php esc_html_e( 'Multiplier', 'dazont-ecom' ); ?></th><th style="width:40px;"></th></tr>
				</thead>
				<tbody>
					<?php foreach ( self::price_table() as $row ) : ?>
						<tr class="dze-pt-row">
							<td><span class="dze-pt-cur">$</span> <input type="number" step="0.01" name="<?php echo esc_attr( $opt ); ?>[pt_min][]" value="<?php echo esc_attr( $row['min'] ); ?>" /></td>
							<td><span class="dze-pt-cur">$</span> <input type="number" step="0.01" name="<?php echo esc_attr( $opt ); ?>[pt_max][]" value="<?php echo esc_attr( $row['max'] ); ?>" /></td>
							<td><span class="dze-pt-cur">×</span> <input type="number" step="0.01" name="<?php echo esc_attr( $opt ); ?>[pt_mult][]" value="<?php echo esc_attr( $row['mult'] ); ?>" /></td>
							<td><button type="button" class="button dze-pt-del" title="<?php esc_attr_e( 'Remove this range', 'dazont-ecom' ); ?>">&#10005;</button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button dze-pt-add" id="dze-pt-add">&#43; <?php esc_html_e( 'Add price range', 'dazont-ecom' ); ?></button></p>
			<script>
			jQuery( function ( $ ) {
				$( '#dze-pt-add' ).on( 'click', function () {
					var $rows = $( '#dze-pt tbody tr.dze-pt-row' );
					var $row  = $rows.last().clone();
					// New range starts where the previous one ends.
					var prevMax = $rows.last().find( 'input' ).eq( 1 ).val() || '';
					$row.find( 'input' ).val( '' );
					$row.find( 'input' ).eq( 0 ).val( prevMax );
					$( '#dze-pt tbody' ).append( $row );
				} );
				$( document ).on( 'click', '.dze-pt-del', function () {
					var $rows = $( '#dze-pt tbody tr.dze-pt-row' );
					if ( $rows.length > 1 ) { $( this ).closest( 'tr' ).remove(); }
					else { $( this ).closest( 'tr' ).find( 'input' ).val( '' ); }
				} );
			} );
			</script>

			<?php submit_button(); ?>
		</form>
		</div>
		<?php
	}

	// =========================================================================
	// Product-page side box
	// =========================================================================

	public function add_meta_box(): void {
		add_meta_box( 'dze-content-side', __( 'AI Content (Dazont)', 'dazont-ecom' ), [ $this, 'render_side_box' ], 'product', 'side', 'high' );
	}

	public function render_side_box( $post ): void {
		?>
		<div class="dze-content-side dze-admin">
			<p class="description"><?php esc_html_e( 'Open the AI toolbox to generate the product content and images.', 'dazont-ecom' ); ?></p>
			<button type="button" class="button button-primary" id="dze-cx-open-text" data-tab="text"><?php esc_html_e( 'Generate text', 'dazont-ecom' ); ?></button>
			<button type="button" class="button" id="dze-cx-open-image" data-tab="image"><?php esc_html_e( 'Generate image', 'dazont-ecom' ); ?></button>
			<?php [ $dze_ok, $dze_tot ] = self::validated_counts(); ?>
			<?php if ( $dze_ok < $dze_tot ) : ?>
				<p class="dze-cx-note"><?php printf( /* translators: 1: validated count, 2: total */ esc_html__( '%1$d/%2$d prompts validated — unvalidated fields stay in preview.', 'dazont-ecom' ), (int) $dze_ok, (int) $dze_tot ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	// =========================================================================
	// Bulk: products-list action + bulk screen
	// =========================================================================

	public function register_bulk_action( array $actions ): array {
		$actions[ self::BULK_ACTION ] = __( 'Generate AI content (Dazont)', 'dazont-ecom' );
		return $actions;
	}

	public function handle_bulk_action( string $redirect, string $action, array $ids ): string {
		if ( self::BULK_ACTION !== $action || empty( $ids ) ) {
			return $redirect;
		}
		set_transient( 'dze_content_bulk_' . get_current_user_id(), array_map( 'intval', $ids ), HOUR_IN_SECONDS );
		return add_query_arg( [ 'post_type' => 'product', 'page' => self::BULK_SLUG ], admin_url( 'edit.php' ) );
	}

	public function register_bulk_page(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'AI Content bulk', 'dazont-ecom' ),
			__( 'AI Content bulk', 'dazont-ecom' ),
			'edit_products',
			self::BULK_SLUG,
			[ $this, 'render_bulk_page' ]
		);
	}

	/** Products queued for the bulk screen (from the last bulk action). */
	private function bulk_products(): array {
		$ids = get_transient( 'dze_content_bulk_' . get_current_user_id() );
		$out = [];
		foreach ( array_map( 'intval', (array) $ids ) as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$thumb_id = get_post_thumbnail_id( $pid );
			$cost     = (string) get_post_meta( $pid, '_dze_cogs', true );
			if ( '' === $cost ) {
				$cost = (string) get_post_meta( $pid, '_cogs_value', true );
			}
			if ( '' === $cost ) {
				$cost = (string) $product->get_regular_price();
			}
			$out[] = [
				'id'    => $pid,
				'title' => $product->get_name(),
				'edit'  => get_edit_post_link( $pid, '' ),
				'thumb' => $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'medium' ) : (string) wc_placeholder_img_src(),
				'full'  => $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'full' ) : '',
				'cost'  => $cost,
			];
		}
		return $out;
	}

	public function render_bulk_page(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$products  = $this->bulk_products();
		[ $ok_n, $tot_n ] = self::validated_counts();
		$templates  = self::image_templates();
		$valid_tpls = [];
		foreach ( $templates as $ti => $t ) {
			if ( ! empty( $t['valid'] ) ) {
				$valid_tpls[ $ti ] = $t; // keep ORIGINAL index — ajax_image resolves by it.
			}
		}
		?>
		<div class="wrap dze-wrap dze-admin">
			<h1><?php esc_html_e( 'AI Content — bulk generation', 'dazont-ecom' ); ?></h1>

			<?php if ( $ok_n < $tot_n ) : ?>
				<div class="notice notice-warning"><p>
					<?php printf( /* translators: 1: validated, 2: total */ esc_html__( '%1$d/%2$d prompts validated — bulk applies directly, so only validated fields can be selected below.', 'dazont-ecom' ), (int) $ok_n, (int) $tot_n ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( empty( $products ) ) : ?>
				<p><?php esc_html_e( 'No products queued. Select products on the Products list and pick "Generate AI content (Dazont)" in the Bulk actions menu.', 'dazont-ecom' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<div class="dze-cb-controls">
				<h2 style="margin-top:0;"><?php esc_html_e( 'What to generate', 'dazont-ecom' ); ?></h2>
				<p>
					<?php foreach ( self::fields() as $fid => $f ) : $fok = self::field_validated( $fid ); ?>
						<label title="<?php echo $fok ? '' : esc_attr__( 'Prompt not validated — locked for bulk.', 'dazont-ecom' ); ?>">
							<input type="checkbox" class="dze-cb-field" value="<?php echo esc_attr( $fid ); ?>" <?php checked( $fok ); disabled( ! $fok ); ?> />
							<?php echo esc_html( $f['label'] ); ?><?php echo $fok ? '' : ' 🔒'; ?>
						</label>
					<?php endforeach; ?>
				</p>
				<p>
					<label><input type="checkbox" id="dze-cb-price" checked /> <strong><?php esc_html_e( 'Recalculate price', 'dazont-ecom' ); ?></strong> <span class="description"><?php esc_html_e( '(cost below × price table → regular price; cost saved as COGS)', 'dazont-ecom' ); ?></span></label>
				</p>
				<p>
					<?php if ( $valid_tpls ) : ?>
						<label><input type="checkbox" id="dze-cb-image" /> <strong><?php esc_html_e( 'Generate an image per product', 'dazont-ecom' ); ?></strong></label>
						<label style="margin-left:10px;"><?php esc_html_e( 'Default template:', 'dazont-ecom' ); ?>
							<select id="dze-cb-tpl">
								<?php foreach ( $valid_tpls as $i => $t ) : ?>
									<option value="<?php echo (int) $i; ?>"><?php echo esc_html( $t['name'] ); ?> (<?php echo esc_html( $t['target'] ?? 'gallery' ); ?>)</option>
								<?php endforeach; ?>
							</select>
						</label>
						<span class="description"><?php esc_html_e( 'Tick/untick and change the template per product in the table — this is the judgement call.', 'dazont-ecom' ); ?></span>
					<?php else : ?>
						<span class="description"><?php esc_html_e( 'No validated image template yet — validate one in AI Settings → Product content to enable images in bulk.', 'dazont-ecom' ); ?></span>
					<?php endif; ?>
				</p>
				<p>
					<button type="button" class="button button-primary button-hero" id="dze-cb-start" <?php disabled( 0 === $ok_n && empty( $valid_tpls ) ); ?>><?php esc_html_e( 'Start bulk generation', 'dazont-ecom' ); ?></button>
					<button type="button" class="button" id="dze-cb-stop" style="display:none;"><?php esc_html_e( 'Stop', 'dazont-ecom' ); ?></button>
				</p>
				<div class="dze-cb-bar" style="display:none;"><div class="dze-cb-fill"></div></div>
				<p id="dze-cb-progress" class="description"></p>
			</div>

			<table class="dze-cb-table">
				<tr>
					<th style="width:130px;"><?php esc_html_e( 'Image', 'dazont-ecom' ); ?></th>
					<th><?php esc_html_e( 'Product', 'dazont-ecom' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Cost (COGS)', 'dazont-ecom' ); ?></th>
					<th style="width:220px;"><?php esc_html_e( 'Image generation', 'dazont-ecom' ); ?></th>
					<th style="width:240px;"><?php esc_html_e( 'Status', 'dazont-ecom' ); ?></th>
				</tr>
				<?php foreach ( $products as $p ) : ?>
					<tr class="dze-cb-row" data-id="<?php echo (int) $p['id']; ?>">
						<td class="dze-cb-thumb">
							<?php if ( $p['full'] ) : ?><a href="<?php echo esc_url( $p['full'] ); ?>" target="_blank" rel="noopener"><?php endif; ?>
							<img src="<?php echo esc_url( $p['thumb'] ); ?>" alt="" />
							<?php if ( $p['full'] ) : ?></a><?php endif; ?>
						</td>
						<td><a href="<?php echo esc_url( $p['edit'] ); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html( $p['title'] ); ?></strong></a></td>
						<td><input type="number" step="0.01" class="dze-cb-cost" value="<?php echo esc_attr( $p['cost'] ); ?>" style="width:90px !important;" /></td>
						<td>
							<label><input type="checkbox" class="dze-cb-row-img" /> <?php esc_html_e( 'Image', 'dazont-ecom' ); ?></label>
							<select class="dze-cb-row-tpl" style="max-width:150px;">
								<?php foreach ( $valid_tpls as $i => $t ) : ?>
									<option value="<?php echo (int) $i; ?>"><?php echo esc_html( $t['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td class="dze-cb-status">—</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php
	}

	// =========================================================================
	// Assets
	// =========================================================================

	public function enqueue( string $hook ): void {
		$screen      = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_product  = $screen && 'product' === $screen->post_type && in_array( $hook, [ 'post.php', 'post-new.php' ], true );
		$on_bulk     = ( 'product_page_' . self::BULK_SLUG ) === $hook;
		$on_settings = false !== strpos( (string) $hook, 'dazont' );
		if ( ! $on_product && ! $on_bulk && ! $on_settings ) {
			return;
		}
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );

		if ( $on_bulk ) {
			wp_enqueue_script( 'dze-content-bulk', DZE_URL . 'admin/js/content-bulk.js', [ 'jquery' ], DZE_VERSION, true );
			wp_localize_script( 'dze-content-bulk', 'dzeContentBulk', [
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'validated' => true, // gating is per-field via disabled checkboxes.
				'fields'    => array_map( static fn( $f ) => $f['label'], self::fields() ),
				'i18n'      => [
					'working'  => __( 'Working…', 'dazont-ecom' ),
					'done'     => __( 'Done', 'dazont-ecom' ),
					'stopped'  => __( 'Stopped.', 'dazont-ecom' ),
					'error'    => __( 'error', 'dazont-ecom' ),
					'progress' => __( '%1$s / %2$s tasks — %3$s', 'dazont-ecom' ),
					'finished' => __( 'Finished: %1$s ok, %2$s errors.', 'dazont-ecom' ),
					'noFields' => __( 'Select at least one thing to generate.', 'dazont-ecom' ),
				],
			] );
			return;
		}
		if ( ! $on_product ) {
			return;
		}
		$pid = (int) get_the_ID();
		wp_enqueue_script( 'dze-content', DZE_URL . 'admin/js/content.js', [ 'jquery' ], DZE_VERSION, true );

		$labels = [];
		foreach ( self::fields() as $fid => $f ) {
			$labels[ $fid ] = $f['label'];
		}
		$fv = [];
		foreach ( array_keys( self::fields() ) as $fid ) {
			$fv[ $fid ] = self::field_validated( $fid );
		}
		$product = $pid ? wc_get_product( $pid ) : null;
		wp_localize_script( 'dze-content', 'dzeContent', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::NONCE ),
			'postId'     => $pid,
			'validated'  => $fv, // per-field map.
			'fields'     => $labels,
			'templates'  => array_map( static fn( $t ) => [ 'name' => $t['name'], 'target' => $t['target'] ?? 'gallery', 'valid' => ! empty( $t['valid'] ) ], self::image_templates() ),
			'product'    => [
				'title' => $product ? $product->get_name() : '',
				'desc'  => $product ? wp_strip_all_tags( (string) get_post_field( 'post_content', $pid ) ) : '',
				// Imported supplier attributes are already stored as standard product
				// attributes — pre-fill them so nobody retypes anything.
				'attr'  => $product ? self::attributes_summary( $product ) : '',
				'price' => $product ? (string) $product->get_regular_price() : '',
			],
			'i18n'       => [
				'toolbox'    => __( 'AI Content toolbox', 'dazont-ecom' ),
				'text'       => __( 'Text', 'dazont-ecom' ),
				'image'      => __( 'Image', 'dazont-ecom' ),
				'price'      => __( 'Price', 'dazont-ecom' ),
				'close'      => __( 'Close', 'dazont-ecom' ),
				'generating' => __( 'Generating…', 'dazont-ecom' ),
				'genAll'     => __( 'Generate all', 'dazont-ecom' ),
				'generate'   => __( 'Generate', 'dazont-ecom' ),
				'apply'      => __( 'Apply', 'dazont-ecom' ),
				'applied'    => __( 'Applied ✓', 'dazont-ecom' ),
				'error'      => __( 'Something went wrong.', 'dazont-ecom' ),
				'previewOnly'=> __( 'Preview only — validate the prompts to apply.', 'dazont-ecom' ),
				'productData'=> __( 'Complete product data (used as context)', 'dazont-ecom' ),
				'pTitle'     => __( 'Title', 'dazont-ecom' ),
				'pDesc'      => __( 'Supplier / current description', 'dazont-ecom' ),
				'pAttr'      => __( 'Supplier attributes / extra data', 'dazont-ecom' ),
				'template'   => __( 'Template', 'dazont-ecom' ),
				'genImage'   => __( 'Generate image', 'dazont-ecom' ),
				'imgWait'    => __( 'Rendering — up to a minute…', 'dazont-ecom' ),
				'imgAdded'   => __( 'Image added.', 'dazont-ecom' ),
				'imgPreview' => __( 'Preview only — template not validated, nothing attached. Validate it in AI Settings to attach results.', 'dazont-ecom' ),
				'notValid'   => __( 'not validated', 'dazont-ecom' ),
				'fieldLocked'=> __( 'This prompt is not validated yet (AI Settings → Product content).', 'dazont-ecom' ),
				'cost'       => __( 'Cost (COGS)', 'dazont-ecom' ),
				'recalc'     => __( 'Recalculate & apply', 'dazont-ecom' ),
				'newPrice'   => __( 'New regular price', 'dazont-ecom' ),
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

	private static function model(): string {
		if ( class_exists( 'DZE_Marketing_Ai' ) ) {
			$m = (string) ( DZE_Marketing_Ai::get_settings()['insights_model'] ?? '' );
			return '' !== $m ? $m : DZE_Marketing_Ai::chosen_model();
		}
		return '';
	}

	/** Plain-text summary of a product's current attributes ("Label: a | b"). */
	private static function attributes_summary( WC_Product $product ): string {
		$lines = [];
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! $attr instanceof WC_Product_Attribute ) {
				continue;
			}
			if ( $attr->is_taxonomy() ) {
				$terms = wc_get_product_terms( $product->get_id(), $attr->get_name(), [ 'fields' => 'names' ] );
				$vals  = implode( ' | ', array_map( 'strval', (array) $terms ) );
			} else {
				$vals = implode( ' | ', array_map( 'strval', (array) $attr->get_options() ) );
			}
			if ( '' !== $vals ) {
				$lines[] = wc_attribute_label( $attr->get_name() ) . ': ' . $vals;
			}
		}
		return implode( "\n", $lines );
	}

	public function ajax_text(): void {
		$this->guard();
		$field  = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$fields = self::fields();
		if ( ! isset( $fields[ $field ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown field.', 'dazont-ecom' ) ] );
		}
		$pid   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$desc  = isset( $_POST['desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['desc'] ) ) : '';
		$attr  = isset( $_POST['attr'] ) ? sanitize_textarea_field( wp_unslash( $_POST['attr'] ) ) : '';

		// Bulk (and lazy single) mode: pull the COMPLETE product data server-side.
		if ( '' === $title && '' === $desc && '' === $attr && $pid ) {
			$product = wc_get_product( $pid );
			if ( $product instanceof WC_Product ) {
				$title = $product->get_name();
				$desc  = mb_substr( wp_strip_all_tags( (string) get_post_field( 'post_content', $pid ) ), 0, 2500 );
				$attr  = self::attributes_summary( $product );
				$price = (string) $product->get_regular_price();
				if ( '' !== $price ) {
					$attr .= ( $attr ? "\n" : '' ) . 'Price: ' . $price;
				}
			}
		}
		if ( '' === $title && '' === $desc && '' === $attr ) {
			wp_send_json_error( [ 'message' => __( 'Fill in the product data first.', 'dazont-ecom' ) ] );
		}
		$system = 'You are an expert e-commerce copywriter. ' . self::store_context();
		$user   = self::prompt_for( $field ) . "\n\n--- PRODUCT DATA ---\n";
		if ( $title ) { $user .= "Title: {$title}\n"; }
		if ( $desc )  { $user .= "Description: {$desc}\n"; }
		if ( $attr )  { $user .= "Attributes / supplier data: {$attr}\n"; }
		try {
			$text = DZE_Marketing_Ai::complete( $system, $user, self::model(), (int) ( $fields[ $field ]['tokens'] ?? 400 ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'field' => $field, 'text' => $text ] );
	}

	public function ajax_apply(): void {
		$this->guard();
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$field  = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		if ( '' !== $field && ! self::field_validated( $field ) ) {
			wp_send_json_error( [ 'message' => __( 'This prompt is not validated yet — tick its "Prompt validated" box in AI Settings → Product content.', 'dazont-ecom' ) ] );
		}
		$value  = isset( $_POST['value'] ) ? wp_kses_post( wp_unslash( $_POST['value'] ) ) : '';
		$fields = self::fields();
		if ( ! $pid || ! isset( $fields[ $field ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		try {
			$note = $this->apply_value( $pid, $field, $value );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( $note ? [ 'note' => $note ] : [] );
	}

	/** Writes a generated value to its mapped destination. Returns an optional note. */
	private function apply_value( int $pid, string $field, string $value ): string {
		$dest = self::dest_for( $field );
		$seo  = self::seo_keys();
		switch ( $dest['type'] ) {
			case 'post_title':
				wp_update_post( [ 'ID' => $pid, 'post_title' => wp_strip_all_tags( $value ) ] );
				break;
			case 'post_content':
				wp_update_post( [ 'ID' => $pid, 'post_content' => $value ] );
				break;
			case 'post_excerpt':
				wp_update_post( [ 'ID' => $pid, 'post_excerpt' => $value ] );
				break;
			case 'seo_title':
				update_post_meta( $pid, $seo['title'], sanitize_text_field( wp_strip_all_tags( $value ) ) );
				break;
			case 'seo_desc':
				update_post_meta( $pid, $seo['desc'], sanitize_text_field( wp_strip_all_tags( $value ) ) );
				break;
			case 'attributes':
				return $this->apply_attributes( $pid, $value );
			case 'meta':
			default:
				update_post_meta( $pid, (string) ( $dest['key'] ?? '_dze_' . $field ), $value );
		}
		return '';
	}

	/**
	 * Parses "Name: value|value" lines into REAL WooCommerce attributes.
	 * Global attributes (a matching pa_* taxonomy exists) get their terms
	 * created/assigned so layered nav and filters work; the rest become local
	 * product attributes. Existing attributes are merged, and attributes used
	 * for variations are never touched (that would break the variations).
	 */
	private function apply_attributes( int $pid, string $text ): string {
		$product = wc_get_product( $pid );
		if ( ! $product instanceof WC_Product ) {
			throw new RuntimeException( __( 'Product not found.', 'dazont-ecom' ) );
		}
		$existing = $product->get_attributes();
		$position = count( $existing );
		$applied  = 0;
		$skipped  = 0;

		foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
			$line = trim( wp_strip_all_tags( (string) $line ), "- \t" );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			[ $name, $vals ] = array_map( 'trim', explode( ':', $line, 2 ) );
			$values          = array_values( array_filter( array_map( 'trim', explode( '|', $vals ) ) ) );
			if ( '' === $name || empty( $values ) ) {
				continue;
			}

			$tax    = 'pa_' . wc_sanitize_taxonomy_name( $name );
			$is_tax = taxonomy_exists( $tax );
			$key    = $is_tax ? $tax : sanitize_title( $name );

			// Never overwrite an attribute that drives variations.
			if ( isset( $existing[ $key ] ) && $existing[ $key ] instanceof WC_Product_Attribute && $existing[ $key ]->get_variation() ) {
				$skipped++;
				continue;
			}

			$attr = new WC_Product_Attribute();
			if ( $is_tax ) {
				$ids = [];
				foreach ( $values as $v ) {
					$term = get_term_by( 'name', $v, $tax );
					if ( ! $term ) {
						$r = wp_insert_term( $v, $tax );
						if ( ! is_wp_error( $r ) ) {
							$term = get_term( (int) $r['term_id'], $tax );
						}
					}
					if ( $term instanceof WP_Term ) {
						$ids[] = (int) $term->term_id;
					}
				}
				if ( empty( $ids ) ) {
					continue;
				}
				wp_set_object_terms( $pid, $ids, $tax, false );
				$attr->set_id( (int) wc_attribute_taxonomy_id_by_name( $tax ) );
				$attr->set_name( $tax );
				$attr->set_options( $ids );
			} else {
				$attr->set_name( $name );
				$attr->set_options( $values );
			}
			$attr->set_position( isset( $existing[ $key ] ) ? $existing[ $key ]->get_position() : $position++ );
			$attr->set_visible( true );
			$attr->set_variation( false );
			$existing[ $key ] = $attr;
			$applied++;
		}

		if ( $applied > 0 ) {
			$product->set_attributes( $existing );
			$product->save();
		}
		/* translators: 1: applied count, 2: skipped count */
		return sprintf( __( '%1$d attribute(s) applied%2$s.', 'dazont-ecom' ), $applied, $skipped ? ' ' . sprintf( /* translators: %d: skipped count */ __( '(%d variation attribute(s) left untouched)', 'dazont-ecom' ), $skipped ) : '' );
	}

	public function ajax_price(): void {
		$this->guard();
		$pid  = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$cost = isset( $_POST['cost'] ) ? (float) wp_unslash( $_POST['cost'] ) : 0;
		if ( ! $pid || $cost <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Enter a valid cost.', 'dazont-ecom' ) ] );
		}
		$mult    = self::mult_for_cost( $cost );
		$regular = round( $cost * $mult, (int) ( function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 ) );
		// Deterministic math on an explicit action — no prompt involved, applies directly.
		$product = wc_get_product( $pid );
		if ( $product instanceof WC_Product ) {
			update_post_meta( $pid, '_dze_cogs', $cost );
			update_post_meta( $pid, '_cogs_value', $cost ); // WooCommerce native Cost of Goods.
			$product->set_regular_price( (string) $regular );
			$product->save();
		}
		wp_send_json_success( [ 'mult' => $mult, 'regular' => $regular, 'applied' => true ] );
	}

	public function ajax_image(): void {
		$this->guard();
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$idx = isset( $_POST['template'] ) ? absint( $_POST['template'] ) : 0;
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Save the product first.', 'dazont-ecom' ) ] );
		}
		if ( '' === self::fal_key() ) {
			wp_send_json_error( [ 'message' => __( 'Add your fal.ai key under AI Settings → General first.', 'dazont-ecom' ) ] );
		}
		$templates = self::image_templates();
		$tpl       = $templates[ $idx ] ?? $templates[0] ?? null;
		if ( ! $tpl ) {
			wp_send_json_error( [ 'message' => __( 'No image template configured.', 'dazont-ecom' ) ] );
		}
		$thumb_id = get_post_thumbnail_id( $pid );
		if ( ! $thumb_id ) {
			wp_send_json_error( [ 'message' => __( 'Set a featured image on this product first.', 'dazont-ecom' ) ] );
		}
		$title  = get_the_title( $pid );
		$desc   = wp_strip_all_tags( (string) get_post_field( 'post_content', $pid ) );
		$desc   = mb_substr( trim( (string) preg_replace( '/\s+/', ' ', $desc ) ), 0, 600 );
		$ctx    = trim( self::store_context() . ' ' . $title . '. ' . $desc );
		$prompt = ( $ctx ? "Product context: {$ctx}\n\n" : '' ) . (string) $tpl['prompt'];

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$validated = self::template_validated( $idx );
		try {
			// The source is sent as a base64 data URI — fal cannot always fetch the
			// site's own URLs (staging auth, hotlink protection), which surfaced as
			// HTTP 422. Same approach as the validated Apps Script workflow.
			$image_url = $this->fal_generate( $prompt, [ $this->fal_source_data_uri( (int) $thumb_id ) ] );

			if ( ! $validated ) {
				// PREVIEW: show the result, attach nothing.
				wp_send_json_success( [ 'preview' => true, 'url' => $image_url, 'target' => $tpl['target'] ?? 'gallery' ] );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$att_id = media_sideload_image( $image_url, $pid, $title . ' — AI', 'id' );
			if ( is_wp_error( $att_id ) ) {
				throw new RuntimeException( $att_id->get_error_message() );
			}
			if ( 'main' === ( $tpl['target'] ?? 'gallery' ) ) {
				set_post_thumbnail( $pid, (int) $att_id );
			} else {
				$gallery = (string) get_post_meta( $pid, '_product_image_gallery', true );
				$ids     = array_filter( array_map( 'absint', explode( ',', $gallery ) ) );
				$ids[]   = (int) $att_id;
				update_post_meta( $pid, '_product_image_gallery', implode( ',', array_unique( $ids ) ) );
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'attachment' => (int) $att_id,
			'target'     => $tpl['target'] ?? 'gallery',
			'url'        => wp_get_attachment_image_url( (int) $att_id, 'medium' ),
		] );
	}

	/**
	 * Reads an attachment (preferring the 'large' size to bound the payload) and
	 * returns it as a base64 data URI, which fal decodes directly — removing every
	 * "fal cannot reach the source URL" failure (private staging, hotlink rules).
	 */
	private function fal_source_data_uri( int $attachment_id ): string {
		$path = '';
		$size = image_get_intermediate_size( $attachment_id, 'large' );
		if ( is_array( $size ) && ! empty( $size['path'] ) ) {
			$uploads = wp_get_upload_dir();
			$try     = trailingslashit( (string) $uploads['basedir'] ) . $size['path'];
			if ( file_exists( $try ) ) {
				$path = $try;
			}
		}
		if ( '' === $path ) {
			$try = (string) get_attached_file( $attachment_id );
			if ( $try && file_exists( $try ) ) {
				$path = $try;
			}
		}
		$bytes = '' !== $path ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( false === $bytes || '' === $bytes ) {
			// Last resort: fetch our own URL server-side (auth cookies not needed locally).
			$url  = (string) wp_get_attachment_image_url( $attachment_id, 'large' );
			$resp = $url ? wp_remote_get( $url, [ 'timeout' => 30 ] ) : null;
			if ( $resp && ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
				$bytes = wp_remote_retrieve_body( $resp );
			}
		}
		if ( false === $bytes || '' === $bytes ) {
			throw new RuntimeException( __( 'Could not read the product image file.', 'dazont-ecom' ) );
		}
		$mime = (string) ( get_post_mime_type( $attachment_id ) ?: 'image/jpeg' );
		return 'data:' . $mime . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI.
	}

	private function fal_generate( string $prompt, array $image_urls ): string {
		$resp = wp_remote_post( self::FAL_ENDPOINT, [
			'timeout' => 120,
			'headers' => [ 'Authorization' => 'Key ' . self::fal_key(), 'content-type' => 'application/json' ],
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
			$msg = 'HTTP ' . $code;
			if ( is_array( $body ) && isset( $body['detail'] ) ) {
				if ( is_string( $body['detail'] ) ) {
					$msg = $body['detail'];
				} elseif ( is_array( $body['detail'] ) ) {
					$parts = [];
					foreach ( $body['detail'] as $d ) {
						if ( is_array( $d ) && ! empty( $d['msg'] ) ) {
							$parts[] = (string) $d['msg'] . ( ! empty( $d['loc'] ) ? ' (' . implode( '.', array_map( 'strval', (array) $d['loc'] ) ) . ')' : '' );
						}
					}
					if ( $parts ) {
						$msg = 'HTTP ' . $code . ' — ' . implode( '; ', $parts );
					}
				}
			}
			throw new RuntimeException( sprintf( __( 'fal.ai error: %s', 'dazont-ecom' ), mb_substr( $msg, 0, 300 ) ) );
		}
		$url = $body['images'][0]['url'] ?? '';
		if ( ! $url ) {
			throw new RuntimeException( __( 'fal.ai returned no image.', 'dazont-ecom' ) );
		}
		return (string) $url;
	}
}
