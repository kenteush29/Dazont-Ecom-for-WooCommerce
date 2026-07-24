<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI Product Content — turns the raw imported product data (title, description,
 * attributes, cost) into every published field with Claude, generates images
 * with fal.ai, and recalculates the price from a cost-multiplier table.
 *
 * Text fields (champs_ia.txt): Title, Description (incl. technical bullets),
 * Short description, Attributes, SEO title, SEO description, Custom bloc 1/2.
 * Each maps to a destination (post field / SEO meta / custom meta).
 *
 * Keys: text on the shared Anthropic key (DZE_Marketing_Ai); images on a fal.ai
 * key (DZE_FAL_API_KEY constant or a General-tab field). Never committed.
 *
 * Prices: the current/import price is treated as COGS and multiplied per the
 * editable price table to produce the regular price.
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
		add_action( 'wp_ajax_dze_content_apply',  [ $this, 'ajax_apply' ] );
		add_action( 'wp_ajax_dze_content_image', [ $this, 'ajax_image' ] );
		add_action( 'wp_ajax_dze_content_price', [ $this, 'ajax_price' ] );
	}

	// =========================================================================
	// Field + destination definitions
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
				'tokens' => 150,
				'prompt' => "Extract clean WooCommerce attributes as lines \"Name: value|value\" (colours, materials, gender male|female, origin PRC for China, specifications…). Capitalise first letters, no spaces around \"|\". Skip anything you cannot infer. Output only the lines.",
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

	// =========================================================================
	// Settings storage
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

	public static function prompts_validated(): bool {
		return ! empty( self::get_settings()['prompts_validated'] );
	}

	public static function prompt_for( string $field ): string {
		$s   = self::get_settings();
		$key = 'prompt_' . $field;
		if ( ! empty( $s[ $key ] ) ) {
			return (string) $s[ $key ];
		}
		$flds = self::fields();
		return $flds[ $field ]['prompt'] ?? '';
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

	private static function meta_key( string $field ): string {
		$s = self::get_settings();
		$k = (string) ( $s[ 'map_' . $field ] ?? '' );
		if ( '' !== $k ) {
			return $k;
		}
		return 'bloc2' === $field ? '_dze_bloc2' : '_dze_bloc1';
	}

	/** SEO plugin meta keys, auto-detected. */
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
		if ( isset( $in['store_context'] ) ) {
			$out['store_context'] = sanitize_textarea_field( (string) $in['store_context'] );
		}
		foreach ( array_keys( self::fields() ) as $fid ) {
			if ( isset( $in[ 'prompt_' . $fid ] ) ) {
				$out[ 'prompt_' . $fid ] = sanitize_textarea_field( (string) $in[ 'prompt_' . $fid ] );
			}
		}
		foreach ( [ 'bloc1', 'bloc2' ] as $fid ) {
			if ( isset( $in[ 'map_' . $fid ] ) ) {
				$out[ 'map_' . $fid ] = sanitize_key( $in[ 'map_' . $fid ] ) ?: ( '_dze_' . $fid );
			}
		}
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
		// Image templates.
		if ( isset( $in['it_name'] ) && is_array( $in['it_name'] ) ) {
			$tpls = [];
			foreach ( $in['it_name'] as $i => $nm ) {
				$nm = sanitize_text_field( (string) $nm );
				$pr = sanitize_textarea_field( (string) ( $in['it_prompt'][ $i ] ?? '' ) );
				$tg = ( ( $in['it_target'][ $i ] ?? 'gallery' ) === 'main' ) ? 'main' : 'gallery';
				if ( '' !== $nm && '' !== $pr ) {
					$tpls[] = [ 'name' => $nm, 'target' => $tg, 'prompt' => $pr ];
				}
			}
			if ( $tpls ) {
				$out['image_templates'] = $tpls;
			}
		}
		$out['prompts_validated'] = ! empty( $in['prompts_validated'] ) ? 1 : 0;
		return $out;
	}

	// =========================================================================
	// Settings — General tab (fal.ai key only) and Product-content tab
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
					<?php if ( $fal_locked ) : ?>
						<input type="text" class="regular-text" value="<?php esc_attr_e( 'Set via DZE_FAL_API_KEY constant', 'dazont-ecom' ); ?>" disabled />
					<?php else : ?>
						<form method="post" action="options.php" style="display:inline;">
							<?php settings_fields( 'dze_content_options' ); ?>
							<input type="password" id="dze-fal-key" class="regular-text" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[fal_key]" value="" autocomplete="new-password" placeholder="<?php echo $has_fal ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'dazont-ecom' ) : esc_attr__( 'Paste your fal.ai key', 'dazont-ecom' ); ?>" />
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

	/** Full "Product content" tab: text prompts, images, price table, preview + validation. */
	public function render_settings_section(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s   = self::get_settings();
		$opt = self::OPT_SETTINGS;
		$seo = self::seo_keys();
		?>
		<div class="dze-admin">
		<p class="description" style="max-width:900px;">
			<?php esc_html_e( 'Generate every product field from the imported data, generate images from templates, and recalculate the price from cost. Text uses the Anthropic key (General tab); images use the fal.ai key (General tab). Tune the prompts, then tick "Prompts validated" — the product toolbox stays in preview until you do.', 'dazont-ecom' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_content_options' ); ?>

			<h2 class="title"><?php esc_html_e( 'Store context', 'dazont-ecom' ); ?></h2>
			<textarea name="<?php echo esc_attr( $opt ); ?>[store_context]" rows="2" class="large-text"><?php echo esc_textarea( (string) ( $s['store_context'] ?? '' ) ); ?></textarea>
			<p class="description"><?php esc_html_e( 'Prepended to every generation, e.g. "Kula Tactical > Military / tactical clothing and gear > Tone: sharp, authoritative, informational".', 'dazont-ecom' ); ?></p>

			<h2 class="title"><?php esc_html_e( 'Text fields', 'dazont-ecom' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php foreach ( self::fields() as $fid => $f ) : ?>
					<tr>
						<th scope="row"><label for="dze-p-<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( $f['label'] ); ?></label>
							<p class="description"><?php
								if ( 'seo_title' === $fid ) { printf( esc_html__( '→ %s', 'dazont-ecom' ), esc_html( $seo['title'] ) ); }
								elseif ( 'seo_description' === $fid ) { printf( esc_html__( '→ %s', 'dazont-ecom' ), esc_html( $seo['desc'] ) ); }
								elseif ( 'attributes' === $fid ) { esc_html_e( '→ product attributes', 'dazont-ecom' ); }
								elseif ( in_array( $fid, [ 'bloc1', 'bloc2' ], true ) ) { esc_html_e( '→ custom meta:', 'dazont-ecom' ); }
								else { printf( esc_html__( '→ %s', 'dazont-ecom' ), esc_html( $f['dest'] ) ); }
							?></p>
							<?php if ( in_array( $fid, [ 'bloc1', 'bloc2' ], true ) ) : ?>
								<input type="text" name="<?php echo esc_attr( $opt ); ?>[map_<?php echo esc_attr( $fid ); ?>]" value="<?php echo esc_attr( self::meta_key( $fid ) ); ?>" class="regular-text" placeholder="_dze_<?php echo esc_attr( $fid ); ?>" />
							<?php endif; ?>
						</th>
						<td><textarea id="dze-p-<?php echo esc_attr( $fid ); ?>" name="<?php echo esc_attr( $opt ); ?>[prompt_<?php echo esc_attr( $fid ); ?>]" rows="4" class="large-text code"><?php echo esc_textarea( self::prompt_for( $fid ) ); ?></textarea></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2 class="title"><?php esc_html_e( 'Images (fal.ai templates)', 'dazont-ecom' ); ?></h2>
			<table class="form-table dze-it-table" role="presentation">
				<tr><th><?php esc_html_e( 'Template name', 'dazont-ecom' ); ?></th><th><?php esc_html_e( 'Target', 'dazont-ecom' ); ?></th><th><?php esc_html_e( 'Prompt', 'dazont-ecom' ); ?></th></tr>
				<?php foreach ( self::image_templates() as $t ) : ?>
					<tr>
						<td><input type="text" name="<?php echo esc_attr( $opt ); ?>[it_name][]" value="<?php echo esc_attr( $t['name'] ); ?>" /></td>
						<td>
							<select name="<?php echo esc_attr( $opt ); ?>[it_target][]">
								<option value="gallery" <?php selected( 'gallery', $t['target'] ?? 'gallery' ); ?>><?php esc_html_e( 'Add to gallery', 'dazont-ecom' ); ?></option>
								<option value="main" <?php selected( 'main', $t['target'] ?? 'gallery' ); ?>><?php esc_html_e( 'Set as main image', 'dazont-ecom' ); ?></option>
							</select>
						</td>
						<td><textarea name="<?php echo esc_attr( $opt ); ?>[it_prompt][]" rows="2" class="large-text"><?php echo esc_textarea( $t['prompt'] ); ?></textarea></td>
					</tr>
				<?php endforeach; ?>
				<!-- one empty row to add a template -->
				<tr>
					<td><input type="text" name="<?php echo esc_attr( $opt ); ?>[it_name][]" value="" placeholder="<?php esc_attr_e( 'New template…', 'dazont-ecom' ); ?>" /></td>
					<td><select name="<?php echo esc_attr( $opt ); ?>[it_target][]"><option value="gallery"><?php esc_html_e( 'Add to gallery', 'dazont-ecom' ); ?></option><option value="main"><?php esc_html_e( 'Set as main image', 'dazont-ecom' ); ?></option></select></td>
					<td><textarea name="<?php echo esc_attr( $opt ); ?>[it_prompt][]" rows="2" class="large-text"></textarea></td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Price table (cost × multiplier → regular price)', 'dazont-ecom' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The current/import price is treated as the cost (COGS); the matching multiplier sets the regular price. Leave the last row\'s upper bound at 0 for "no limit".', 'dazont-ecom' ); ?></p>
			<table class="form-table dze-price-table" role="presentation">
				<tr><th><?php esc_html_e( 'Cost from', 'dazont-ecom' ); ?></th><th><?php esc_html_e( 'Cost to (0 = ∞)', 'dazont-ecom' ); ?></th><th><?php esc_html_e( 'Multiplier', 'dazont-ecom' ); ?></th></tr>
				<?php foreach ( self::price_table() as $row ) : ?>
					<tr>
						<td><input type="number" step="0.01" name="<?php echo esc_attr( $opt ); ?>[pt_min][]" value="<?php echo esc_attr( $row['min'] ); ?>" /></td>
						<td><input type="number" step="0.01" name="<?php echo esc_attr( $opt ); ?>[pt_max][]" value="<?php echo esc_attr( $row['max'] ); ?>" /></td>
						<td><input type="number" step="0.01" name="<?php echo esc_attr( $opt ); ?>[pt_mult][]" value="<?php echo esc_attr( $row['mult'] ); ?>" /></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<td><input type="number" step="0.01" name="<?php echo esc_attr( $opt ); ?>[pt_min][]" value="" placeholder="<?php esc_attr_e( 'add…', 'dazont-ecom' ); ?>" /></td>
					<td><input type="number" step="0.01" name="<?php echo esc_attr( $opt ); ?>[pt_max][]" value="" /></td>
					<td><input type="number" step="0.01" name="<?php echo esc_attr( $opt ); ?>[pt_mult][]" value="" /></td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Validation', 'dazont-ecom' ); ?></h2>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[prompts_validated]" value="1" <?php checked( self::prompts_validated() ); ?> />
				<?php esc_html_e( 'I have reviewed and validated these prompts (unlocks direct apply on products; until then the toolbox works in preview only).', 'dazont-ecom' ); ?>
			</label>

			<?php submit_button(); ?>
		</form>
		</div>
		<?php
	}

	// =========================================================================
	// Product-page side box → toolbox modal
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
			<?php if ( ! self::prompts_validated() ) : ?>
				<p class="dze-cx-note"><?php esc_html_e( 'Preview mode — validate prompts in AI Settings → Product content to enable direct apply.', 'dazont-ecom' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function enqueue( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_product = $screen && 'product' === $screen->post_type && in_array( $hook, [ 'post.php', 'post-new.php' ], true );
		$on_settings = false !== strpos( (string) $hook, 'dazont' ); // our settings pages.
		if ( ! $on_product && ! $on_settings ) {
			return;
		}
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		if ( ! $on_product ) {
			return;
		}
		$pid = (int) get_the_ID();
		wp_enqueue_script( 'dze-content', DZE_URL . 'admin/js/content.js', [ 'jquery' ], DZE_VERSION, true );

		$labels = [];
		foreach ( self::fields() as $fid => $f ) {
			$labels[ $fid ] = $f['label'];
		}
		$product = $pid ? wc_get_product( $pid ) : null;
		wp_localize_script( 'dze-content', 'dzeContent', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::NONCE ),
			'postId'     => $pid,
			'validated'  => self::prompts_validated(),
			'fields'     => $labels,
			'templates'  => array_map( static fn( $t ) => [ 'name' => $t['name'], 'target' => $t['target'] ?? 'gallery' ], self::image_templates() ),
			'product'    => [
				'title'   => $product ? $product->get_name() : '',
				'desc'    => $product ? wp_strip_all_tags( (string) get_post_field( 'post_content', $pid ) ) : '',
				'price'   => $product ? (string) $product->get_regular_price() : '',
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

	public function ajax_text(): void {
		$this->guard();
		$field  = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$fields = self::fields();
		if ( ! isset( $fields[ $field ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown field.', 'dazont-ecom' ) ] );
		}
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$desc  = isset( $_POST['desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['desc'] ) ) : '';
		$attr  = isset( $_POST['attr'] ) ? sanitize_textarea_field( wp_unslash( $_POST['attr'] ) ) : '';
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
		if ( ! self::prompts_validated() ) {
			wp_send_json_error( [ 'message' => __( 'Validate the prompts in AI Settings first.', 'dazont-ecom' ) ] );
		}
		$pid   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$value = isset( $_POST['value'] ) ? wp_kses_post( wp_unslash( $_POST['value'] ) ) : '';
		$fields = self::fields();
		if ( ! $pid || ! isset( $fields[ $field ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		$dest = $fields[ $field ]['dest'];
		$seo  = self::seo_keys();
		switch ( $dest ) {
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
			case 'meta':
				update_post_meta( $pid, self::meta_key( $field ), $value );
				break;
			case 'attributes':
				// Stored for review; full WC attribute creation is a follow-up.
				update_post_meta( $pid, '_dze_attributes_draft', $value );
				wp_send_json_success( [ 'note' => __( 'Attributes saved as a draft note (review before mapping to WooCommerce attributes).', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [] );
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
		if ( self::prompts_validated() ) {
			$product = wc_get_product( $pid );
			if ( $product instanceof WC_Product ) {
				update_post_meta( $pid, '_dze_cogs', $cost );
				$product->set_regular_price( (string) $regular );
				$product->save();
			}
		}
		wp_send_json_success( [ 'mult' => $mult, 'regular' => $regular, 'applied' => self::prompts_validated() ] );
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
		$src      = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '';
		if ( ! $src ) {
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
		try {
			$image_url = $this->fal_generate( $prompt, [ $src ] );
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
			$msg = is_array( $body ) && isset( $body['detail'] ) && is_string( $body['detail'] ) ? $body['detail'] : ( 'HTTP ' . $code );
			throw new RuntimeException( sprintf( __( 'fal.ai error: %s', 'dazont-ecom' ), $msg ) );
		}
		$url = $body['images'][0]['url'] ?? '';
		if ( ! $url ) {
			throw new RuntimeException( __( 'fal.ai returned no image.', 'dazont-ecom' ) );
		}
		return (string) $url;
	}
}
