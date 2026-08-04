<?php
defined( 'ABSPATH' ) || exit;

/**
 * Category descriptions — buy-guide copy for product categories, written from
 * REAL data and wired into the internal linking.
 *
 * Three inputs, none of them guessed:
 *   1. The SEMrush keyword set already imported for that category by the
 *      Sourcing Assistant (same files, same table). Queries are split into
 *      secondary title candidates (same search intent, different wording) and
 *      real buyer questions, both ranked by search volume.
 *   2. The catalogue itself: parent, children and sibling categories plus the
 *      category's best sellers — the internal-link pool. An optional sitemap
 *      URL can widen it with pages the taxonomy does not cover.
 *   3. The category context: name, current description, product count, price
 *      range, sample product titles.
 *
 * The model may only link to URLs from that pool, so the internal linking is
 * always valid. Output is HTML with H2 headings built on the queries and the
 * questions; nothing is written before you press Apply.
 */
final class DZE_Category_Content {

	private const OPT   = 'dze_catcontent_settings';
	private const NONCE = 'dze_catcontent';
	public const GEN_META = '_dze_desc_generated';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		// Categories list: status column + popup.
		add_filter( 'manage_edit-product_cat_columns', [ $this, 'add_column' ] );
		add_filter( 'manage_product_cat_custom_column', [ $this, 'render_column' ], 10, 3 );
		add_action( 'admin_footer-edit-tags.php', [ $this, 'list_modal' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		// AJAX.
		add_action( 'wp_ajax_dze_cc_panel', [ $this, 'ajax_panel' ] );
		add_action( 'wp_ajax_dze_cc_generate', [ $this, 'ajax_generate' ] );
		add_action( 'wp_ajax_dze_cc_apply', [ $this, 'ajax_apply' ] );
		add_action( 'wp_ajax_dze_cc_save_prompt', [ $this, 'ajax_save_prompt' ] );
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function get_settings(): array {
		$s = get_option( self::OPT, [] );
		return is_array( $s ) ? $s : [];
	}

	public function register_settings(): void {
		register_setting( 'dze_catcontent_options', self::OPT, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize' ],
			'default'           => [],
		] );
	}

	public function sanitize( $in ): array {
		$in  = is_array( $in ) ? $in : [];
		$out = self::get_settings();
		if ( isset( $in['words'] ) ) {
			$out['words'] = max( 120, min( 1200, (int) $in['words'] ) );
		}
		if ( isset( $in['links'] ) ) {
			$out['links'] = max( 0, min( 12, (int) $in['links'] ) );
		}
		if ( isset( $in['sitemap'] ) ) {
			$out['sitemap'] = esc_url_raw( (string) $in['sitemap'] );
		}
		if ( isset( $in['prompt'] ) ) {
			$out['prompt'] = sanitize_textarea_field( (string) $in['prompt'] );
		}
		return $out;
	}

	public static function words(): int {
		return max( 120, (int) ( self::get_settings()['words'] ?? 350 ) );
	}

	public static function links(): int {
		$v = self::get_settings()['links'] ?? 5;
		return max( 0, min( 12, (int) $v ) );
	}

	public static function sitemap_url(): string {
		return trim( (string) ( self::get_settings()['sitemap'] ?? '' ) );
	}

	public static function default_prompt(): string {
		return <<<'PROMPT'
You write the description of a product category for an online shop. Think of a shop assistant standing in that aisle: concise, concrete, genuinely useful — a short buying guide, not marketing filler.

STRUCTURE
- Open with 2 or 3 sentences: what this category covers, who it is for, what matters most when choosing.
- Then 3 to 5 <h2> headings, built FROM THE SUPPLIED QUERIES AND QUESTIONS, reusing their wording as naturally as possible:
  · secondary queries (same search intent, different wording) become topic headings;
  · buyer questions become question headings, each answered concretely below.
- Under each heading: 2 to 4 sentences. Practical advice — materials, sizing, use cases, what to check, what to avoid. No fluff, no repetition of the intro.

RULES
- Concrete over generic: mention real criteria a buyer weighs, not "quality and style".
- Never invent figures: no prices, no measurements, no delivery times, no statistics.
- Do not promise stock, discounts or shipping conditions.
- Plain HTML only: <p>, <h2>, <ul>/<li>, <strong>, <a>. No inline styles, no <h1>.
- Insert the internal links from the supplied list only, anchored on natural wording inside sentences — never a bare "click here" and never a list of links at the end.
PROMPT;
	}

	public static function prompt(): string {
		$p = trim( (string) ( self::get_settings()['prompt'] ?? '' ) );
		return '' !== $p ? $p : self::default_prompt();
	}

	// =========================================================================
	// Real data: keywords, link pool, context
	// =========================================================================

	/** Interrogative queries, in the main European shop languages plus English. */
	private static function is_question( string $kw ): bool {
		return (bool) preg_match(
			'/^(comment|pourquoi|quel|quelle|quels|quelles|quand|où|est-ce|peut-on|faut-il|how|what|which|why|when|where|can|is|are|do|does|should)\b/iu',
			trim( $kw )
		) || false !== strpos( $kw, '?' );
	}

	/**
	 * The category's SEMrush queries, already imported by the Sourcing
	 * Assistant: [ 'titles' => [...], 'questions' => [...] ], volume-ranked.
	 */
	public static function keyword_pools( int $term_id, string $exclude = '' ): array {
		global $wpdb;
		$out = [ 'titles' => [], 'questions' => [], 'total' => 0 ];
		if ( ! class_exists( 'DZE_Keywords' ) ) {
			return $out;
		}
		$table = DZE_Keywords::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return $out;
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			"SELECT keyword, volume, intent FROM {$table}
			 WHERE term_id = %d AND status <> 'ignored'
			 ORDER BY volume DESC LIMIT 120",
			$term_id
		), ARRAY_A );
		if ( empty( $rows ) ) {
			return $out;
		}
		$out['total'] = count( $rows );
		$needle = mb_strtolower( trim( $exclude ) );
		foreach ( $rows as $r ) {
			$kw = trim( (string) $r['keyword'] );
			if ( '' === $kw ) {
				continue;
			}
			$line = $kw . ( (int) $r['volume'] ? ' (' . (int) $r['volume'] . '/mo)' : '' );
			if ( self::is_question( $kw ) ) {
				if ( count( $out['questions'] ) < 15 ) {
					$out['questions'][] = $line;
				}
				continue;
			}
			// The category's own name is not a "secondary" query.
			if ( '' !== $needle && mb_strtolower( $kw ) === $needle ) {
				continue;
			}
			if ( count( $out['titles'] ) < 20 ) {
				$out['titles'][] = $line;
			}
		}
		return $out;
	}

	/** How many links the category description currently contains. */
	public static function links_in_description( int $term_id ): int {
		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return 0;
		}
		return (int) preg_match_all( '/<a\s[^>]*href=/i', (string) $term->description );
	}

	/** Candidate pool grouped by kind, for the "what can I link to" line. */
	public static function link_pool_breakdown( int $term_id ): array {
		$out = [];
		foreach ( self::link_pool( $term_id ) as $l ) {
			$kind         = (string) $l['kind'];
			$out[ $kind ] = ( $out[ $kind ] ?? 0 ) + 1;
		}
		return $out;
	}

	/**
	 * Internal-link candidates: the category tree around this term plus its
	 * best sellers — i.e. what the sitemap holds for this branch, read straight
	 * from the catalogue so the URLs are always valid.
	 */
	public static function link_pool( int $term_id ): array {
		$pool = [];
		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return $pool;
		}
		$add = static function ( string $label, string $url, string $kind ) use ( &$pool ) {
			$url = (string) $url;
			if ( '' === $url || isset( $pool[ $url ] ) ) {
				return;
			}
			$pool[ $url ] = [ 'label' => $label, 'url' => $url, 'kind' => $kind ];
		};
		if ( $term->parent ) {
			$parent = get_term( $term->parent, 'product_cat' );
			if ( $parent && ! is_wp_error( $parent ) ) {
				$add( $parent->name, (string) get_term_link( $parent ), 'parent category' );
			}
		}
		foreach ( get_terms( [ 'taxonomy' => 'product_cat', 'parent' => $term_id, 'hide_empty' => true, 'number' => 12 ] ) as $child ) {
			if ( ! is_wp_error( $child ) ) {
				$add( $child->name, (string) get_term_link( $child ), 'sub-category' );
			}
		}
		foreach ( get_terms( [ 'taxonomy' => 'product_cat', 'parent' => (int) $term->parent, 'hide_empty' => true, 'number' => 12, 'exclude' => [ $term_id ] ] ) as $sib ) {
			if ( ! is_wp_error( $sib ) ) {
				$add( $sib->name, (string) get_term_link( $sib ), 'related category' );
			}
		}
		// Best sellers of the category — the products worth linking to.
		$products = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 8,
			'meta_key'       => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
			'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $term_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		] );
		foreach ( $products as $p ) {
			$add( get_the_title( $p ), (string) get_permalink( $p ), 'product' );
		}
		$pool = array_values( $pool );

		// Optional widening from the site's own sitemap (cached 12h).
		$extra = self::sitemap_links();
		foreach ( $extra as $e ) {
			if ( count( $pool ) >= 40 ) {
				break;
			}
			$pool[] = $e;
		}
		return $pool;
	}

	/** URLs pulled from the configured sitemap, cached — optional enrichment. */
	private static function sitemap_links(): array {
		$url = self::sitemap_url();
		if ( '' === $url ) {
			return [];
		}
		$cached = get_transient( 'dze_cc_sitemap' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out  = [];
		$resp = wp_remote_get( $url, [ 'timeout' => 20 ] );
		if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
			$body = (string) wp_remote_retrieve_body( $resp );
			if ( preg_match_all( '#<loc>\s*([^<]+?)\s*</loc>#i', $body, $m ) ) {
				foreach ( array_slice( $m[1], 0, 60 ) as $loc ) {
					$loc = esc_url_raw( trim( $loc ) );
					if ( '' === $loc || preg_match( '/\.xml($|\?)/i', $loc ) ) {
						continue; // sitemap index entries, not pages.
					}
					$slug  = trim( (string) wp_parse_url( $loc, PHP_URL_PATH ), '/' );
					$label = ucwords( str_replace( [ '-', '_', '/' ], ' ', $slug ) );
					$out[] = [ 'label' => $label ?: $loc, 'url' => $loc, 'kind' => 'page' ];
				}
			}
		}
		set_transient( 'dze_cc_sitemap', $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/** Language of the category (WPML), else the site language. */
	private static function language( int $term_id ): string {
		$details = apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $term_id, 'element_type' => 'product_cat' ] );
		$code    = is_array( $details ) ? (string) ( $details['language_code'] ?? '' ) : '';
		if ( '' === $code ) {
			$code = (string) apply_filters( 'wpml_default_language', '' );
		}
		if ( '' === $code ) {
			$code = (string) get_locale();
		}
		$names = [
			'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish',
			'it' => 'Italian', 'nl' => 'Dutch', 'pt' => 'Portuguese', 'pl' => 'Polish',
		];
		$short = strtolower( substr( str_replace( '-', '_', $code ), 0, 2 ) );
		return $names[ $short ] ?? $code;
	}

	// =========================================================================
	// Generation
	// =========================================================================

	public static function generate( int $term_id, string $prompt_override = '' ): string {
		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			throw new RuntimeException( __( 'Category not found.', 'dazont-ecom' ) );
		}
		if ( ! class_exists( 'DZE_Marketing_Ai' ) ) {
			throw new RuntimeException( __( 'The Marketing Assistant module is required for the Anthropic key.', 'dazont-ecom' ) );
		}
		$kw    = self::keyword_pools( $term_id, $term->name );
		$links = self::link_pool( $term_id );

		$samples = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 12,
			'fields'         => 'ids',
			'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $term_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		] );
		$titles = [];
		foreach ( $samples as $sid ) {
			$titles[] = get_the_title( (int) $sid );
		}

		$user = "--- CATEGORY ---\n"
			. 'Name: ' . $term->name . "\n"
			. 'Products in it: ' . (int) $term->count . "\n"
			. ( $titles ? "Example products:\n- " . implode( "\n- ", array_slice( $titles, 0, 12 ) ) . "\n" : '' )
			. ( $term->description ? "Current description (to replace):\n" . mb_substr( wp_strip_all_tags( $term->description ), 0, 600 ) . "\n" : '' );

		$user .= "\n--- REAL SEARCH DATA (from the shop's own SEMrush import) ---\n";
		$user .= $kw['titles']
			? "Secondary queries — same intent, different wording. Build <h2> headings on these, keeping their wording where it reads naturally:\n- " . implode( "\n- ", $kw['titles'] ) . "\n"
			: "No secondary query available: build the headings from the category itself.\n";
		$user .= $kw['questions']
			? "\nReal buyer questions — turn the most relevant ones into <h2> questions and answer them concretely:\n- " . implode( "\n- ", $kw['questions'] ) . "\n"
			: '';

		if ( $links && self::links() > 0 ) {
			$list = [];
			foreach ( $links as $l ) {
				$list[] = $l['label'] . ' [' . $l['kind'] . '] → ' . $l['url'];
			}
			$user .= "\n--- INTERNAL LINKS (use these URLs ONLY) ---\n- " . implode( "\n- ", $list ) . "\n";
			$user .= 'Insert ' . max( 1, self::links() - 2 ) . ' to ' . self::links() . " of them, anchored on natural wording inside the sentences.\n";
		}

		$user .= "\n--- INSTRUCTIONS ---\n" . ( '' !== $prompt_override ? $prompt_override : self::prompt() );
		$user .= "\n\n--- FACTS (never contradict these) ---\n"
			. 'LANGUAGE: write in ' . self::language( $term_id ) . ". This overrides the language of the instructions above.\n"
			. 'LENGTH: about ' . self::words() . " words in total.\n"
			. "OUTPUT: the HTML fragment only — no markdown, no code fence, no comment before or after.";

		$system = 'You are an e-commerce category copywriter. ' . ( class_exists( 'DZE_Content' ) ? DZE_Content::store_context() : '' );
		$html   = DZE_Marketing_Ai::complete( $system, $user, '', (int) ( self::words() * 3 ) + 600, 180 );
		$html   = trim( preg_replace( '/^```(?:html)?|```$/m', '', $html ) );
		if ( '' === $html ) {
			throw new RuntimeException( __( 'The model returned nothing usable.', 'dazont-ecom' ) );
		}
		return wp_kses_post( $html );
	}

	// =========================================================================
	// Categories list column
	// =========================================================================

	public function add_column( array $columns ): array {
		// NOT "Description": WooCommerce already has a Description column
		// holding the text itself.
		$columns['dze_desc'] = __( 'Word count', 'dazont-ecom' );
		return $columns;
	}

	public function render_column( $content, string $column, int $term_id ) {
		if ( 'dze_desc' !== $column ) {
			return $content;
		}
		$term  = get_term( $term_id, 'product_cat' );
		$words = ( $term && ! is_wp_error( $term ) ) ? str_word_count( wp_strip_all_tags( (string) $term->description ) ) : 0;
		$kw    = self::keyword_pools( $term_id );
		$label = $words
			? '<span style="color:#0a7040;font-weight:600;">' . (int) $words . ' ' . esc_html__( 'words', 'dazont-ecom' ) . '</span>'
			: '<span style="color:#b32d2e;font-weight:600;">' . esc_html__( 'empty', 'dazont-ecom' ) . '</span>';
		$meta = [];
		$in   = self::links_in_description( $term_id );
		if ( $in ) {
			/* translators: %d: links found in the description */
			$meta[] = sprintf( esc_html__( '%d links', 'dazont-ecom' ), (int) $in );
		}
		if ( $kw['total'] ) {
			/* translators: %d: number of imported keywords */
			$meta[] = sprintf( esc_html__( '%d kw', 'dazont-ecom' ), (int) $kw['total'] );
		}
		if ( $meta ) {
			$label .= ' <span style="color:#646970;font-size:11px;">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
		}
		return sprintf(
			'<button type="button" class="dze-cc-open" data-id="%1$d" title="%2$s">%3$s<span class="dze-caret">&#9662;</span></button>',
			(int) $term_id,
			esc_attr__( 'Click to write this category description', 'dazont-ecom' ),
			$label // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above.
		);
	}

	// =========================================================================
	// Panel
	// =========================================================================

	public function render_panel( int $term_id ): void {
		$term  = get_term( $term_id, 'product_cat' );
		$kw    = self::keyword_pools( $term_id, $term ? $term->name : '' );
		$links = self::link_pool( $term_id );
		$break = self::link_pool_breakdown( $term_id );
		$in    = self::links_in_description( $term_id );
		$words = ( $term && ! is_wp_error( $term ) ) ? str_word_count( wp_strip_all_tags( (string) $term->description ) ) : 0;
		?>
		<div class="dze-cc-box" data-term="<?php echo (int) $term_id; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
			<p class="description" style="margin-top:0;">
				<?php
				printf(
					/* translators: 1: word count, 2: links currently in the description */
					esc_html__( 'Current description: %1$s words, %2$s links', 'dazont-ecom' ),
					'<strong>' . (int) $words . '</strong>',
					'<strong>' . (int) $in . '</strong>'
				);
				?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: 1: secondary queries, 2: questions */
					esc_html__( 'Data available: %1$s secondary queries · %2$s buyer questions', 'dazont-ecom' ),
					'<strong>' . count( $kw['titles'] ) . '</strong>',
					'<strong>' . count( $kw['questions'] ) . '</strong>'
				);
				?>
				<br />
				<?php
				$parts = [];
				$names = [
					'parent category'  => __( 'parent', 'dazont-ecom' ),
					'sub-category'     => __( 'sub-categories', 'dazont-ecom' ),
					'related category' => __( 'sibling categories', 'dazont-ecom' ),
					'product'          => __( 'best sellers', 'dazont-ecom' ),
					'page'             => __( 'sitemap pages', 'dazont-ecom' ),
				];
				foreach ( $break as $kind => $n ) {
					$parts[] = (int) $n . ' ' . ( $names[ $kind ] ?? $kind );
				}
				printf(
					/* translators: 1: total link targets, 2: their breakdown, 3: max links inserted */
					esc_html__( 'Link targets it can choose from: %1$s (%2$s). At most %3$s are inserted.', 'dazont-ecom' ),
					'<strong>' . count( $links ) . '</strong>',
					esc_html( implode( ', ', $parts ) ),
					'<strong>' . (int) self::links() . '</strong>'
				);
				?>
			</p>
			<?php if ( ! $kw['total'] ) : ?>
				<p class="description" style="color:#8a6d00;">
					<?php esc_html_e( 'No keyword imported for this category yet — import its SEMrush file in the Sourcing Assistant to write from real queries instead of the catalogue alone.', 'dazont-ecom' ); ?>
				</p>
			<?php endif; ?>
			<p>
				<button type="button" class="button button-primary dze-cc-gen"><?php esc_html_e( 'Write the description', 'dazont-ecom' ); ?></button>
				<button type="button" class="dze-cx-icon dze-cc-ptoggle" title="<?php esc_attr_e( 'Edit the prompt', 'dazont-ecom' ); ?>">&#9998;</button>
				<button type="button" class="dze-cx-icon dze-cc-dtoggle" title="<?php esc_attr_e( 'See the queries and links used', 'dazont-ecom' ); ?>">&#9432;</button>
				<?php if ( class_exists( 'DZE_Keywords' ) && ( ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( 'sourcing' ) ) ) : ?>
					<button type="button" class="button button-small dze-cc-imtoggle"><?php esc_html_e( 'Import SEMrush file', 'dazont-ecom' ); ?></button>
				<?php endif; ?>
				<span class="dze-cc-status"></span>
			</p>
			<div class="dze-cc-data" style="display:none;">
				<?php if ( $kw['titles'] ) : ?>
					<p><strong><?php esc_html_e( 'Secondary queries', 'dazont-ecom' ); ?></strong><br /><span class="description"><?php echo esc_html( implode( ' · ', array_slice( $kw['titles'], 0, 12 ) ) ); ?></span></p>
				<?php endif; ?>
				<?php if ( $kw['questions'] ) : ?>
					<p><strong><?php esc_html_e( 'Buyer questions', 'dazont-ecom' ); ?></strong><br /><span class="description"><?php echo esc_html( implode( ' · ', array_slice( $kw['questions'], 0, 10 ) ) ); ?></span></p>
				<?php endif; ?>
				<?php if ( $links ) : ?>
					<p><strong><?php esc_html_e( 'Internal links', 'dazont-ecom' ); ?></strong><br /><span class="description"><?php echo esc_html( implode( ' · ', array_map( static fn( $l ) => $l['label'], array_slice( $links, 0, 14 ) ) ) ); ?></span></p>
				<?php endif; ?>
			</div>
			<div class="dze-cc-import" style="display:none;">
				<p class="description" style="margin:0 0 6px;">
					<?php esc_html_e( 'SEMrush export for this category (CSV). Existing keywords keep their status and only refresh their metrics; new ones are added.', 'dazont-ecom' ); ?>
				</p>
				<p>
					<input type="file" class="dze-cc-file" accept=".csv,text/csv,text/plain" />
					<span class="dze-cc-imstatus"></span>
				</p>
				<div class="dze-cc-map" style="display:none;">
					<p class="description" style="margin:0 0 4px;"><?php esc_html_e( 'Check the columns before importing:', 'dazont-ecom' ); ?></p>
					<p class="dze-cc-mapfields"></p>
					<p><button type="button" class="button button-primary dze-cc-doimport"><?php esc_html_e( 'Import', 'dazont-ecom' ); ?></button></p>
				</div>
			</div>
			<div class="dze-cc-pwrap" style="display:none;">
				<textarea rows="10" class="large-text code dze-cc-ptext"><?php echo esc_textarea( self::prompt() ); ?></textarea>
				<p class="description" style="margin:2px 0 10px;">
					<?php esc_html_e( 'Used for the next run. Save to keep it.', 'dazont-ecom' ); ?>
					<button type="button" class="button-link dze-cc-psave">&#128190; <?php esc_html_e( 'Save prompt', 'dazont-ecom' ); ?></button>
					<button type="button" class="button-link dze-cc-prestore">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
				</p>
			</div>
			<div class="dze-cc-result" style="display:none;">
				<div class="dze-cc-preview"></div>
				<p><button type="button" class="button-link dze-cc-htmltoggle">&lt;/&gt; <?php esc_html_e( 'Edit the HTML', 'dazont-ecom' ); ?></button></p>
				<textarea rows="12" class="large-text code dze-cc-html" style="display:none;"></textarea>
				<p>
					<button type="button" class="button button-primary dze-cc-apply"><?php esc_html_e( 'Apply to the category', 'dazont-ecom' ); ?></button>
					<button type="button" class="button dze-cc-discard"><?php esc_html_e( 'Discard', 'dazont-ecom' ); ?></button>
					<?php if ( $term && ! is_wp_error( $term ) ) : ?>
						<a class="button" href="<?php echo esc_url( (string) get_edit_term_link( $term_id, 'product_cat' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the category', 'dazont-ecom' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
		</div>
		<?php
	}

	public function list_modal(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product_cat' !== $screen->taxonomy ) {
			return;
		}
		?>
		<div class="dze-cx-modal" id="dze-cc-modal"><div class="dze-cx-dialog" style="width:min(760px,94vw);">
			<div class="dze-cx-head"><h2 id="dze-cc-title"><?php esc_html_e( 'Category description', 'dazont-ecom' ); ?></h2>
				<button type="button" class="button dze-hub-close" style="margin-left:auto;"><?php esc_html_e( 'Close', 'dazont-ecom' ); ?></button></div>
			<div class="dze-cx-body" id="dze-cc-body"></div>
		</div></div>
		<?php
	}

	public function enqueue( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_tax = $screen && 'product_cat' === ( $screen->taxonomy ?? '' );
		if ( ! $on_tax && false === strpos( (string) $hook, 'dazont' ) ) {
			return;
		}
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		wp_enqueue_script( 'dze-catcontent', DZE_URL . 'admin/js/category-content.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-catcontent', 'dzeCatContent', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'kwNonce' => class_exists( 'DZE_Keywords' ) ? DZE_Keywords::nonce() : '',
			'i18n'    => [
				'working'     => __( 'Writing — up to a minute…', 'dazont-ecom' ),
				'reading'     => __( 'Reading the file…', 'dazont-ecom' ),
				'importing'   => __( 'Importing…', 'dazont-ecom' ),
				'imported'    => __( '%1$s added · %2$s updated', 'dazont-ecom' ),
				'colKeyword'  => __( 'Keyword', 'dazont-ecom' ),
				'colVolume'   => __( 'Volume', 'dazont-ecom' ),
				'colKd'       => __( 'KD', 'dazont-ecom' ),
				'colCpc'      => __( 'CPC', 'dazont-ecom' ),
				'colIntent'   => __( 'Intent', 'dazont-ecom' ),
				'colNone'     => __( '— none —', 'dazont-ecom' ),
				'error'       => __( 'Something went wrong.', 'dazont-ecom' ),
				'applied'     => __( 'Applied ✓', 'dazont-ecom' ),
				'savedPrompt' => __( 'Prompt saved ✓', 'dazont-ecom' ),
				'savePrompt'  => __( 'Save prompt', 'dazont-ecom' ),
				'defaultPrompt' => self::default_prompt(),
			],
		] );
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	private function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
	}

	public function ajax_panel(): void {
		$this->guard();
		$tid  = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$term = $tid ? get_term( $tid, 'product_cat' ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		ob_start();
		$this->render_panel( $tid );
		wp_send_json_success( [ 'html' => ob_get_clean(), 'title' => $term->name ] );
	}

	public function ajax_generate(): void {
		$this->guard();
		$tid = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		if ( ! $tid ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}
		$override = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 200 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		try {
			$html = self::generate( $tid, $override );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'html' => $html ] );
	}

	public function ajax_apply(): void {
		$this->guard();
		$tid  = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$html = isset( $_POST['html'] ) ? wp_kses_post( wp_unslash( $_POST['html'] ) ) : '';
		if ( ! $tid || '' === trim( $html ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to apply.', 'dazont-ecom' ) ] );
		}
		$res = wp_update_term( $tid, 'product_cat', [ 'description' => $html ] );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message() ] );
		}
		update_term_meta( $tid, self::GEN_META, 1 );
		$term = get_term( $tid, 'product_cat' );
		wp_send_json_success( [
			'words' => ( $term && ! is_wp_error( $term ) ) ? str_word_count( wp_strip_all_tags( (string) $term->description ) ) : 0,
			'links' => self::links_in_description( $tid ),
		] );
	}

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
			wp_send_json_error( [ 'message' => __( 'The prompt was not persisted — use the Settings page instead.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'saved' => true ] );
	}

	// =========================================================================
	// Settings tab
	// =========================================================================

	public function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s = self::get_settings();
		?>
		<div class="dze-admin">
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Writes product category descriptions as short buying guides, from the SEMrush queries already imported for that category in the Sourcing Assistant: the secondary queries become H2 headings, the real buyer questions become answered H2 questions. Internal links are picked from the category tree and the category best sellers, so they always resolve. Write them from the Description column on Products → Categories.', 'dazont-ecom' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_catcontent_options' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dze-cc-words"><?php esc_html_e( 'Target length', 'dazont-ecom' ); ?></label></th>
					<td><input type="number" id="dze-cc-words" name="<?php echo esc_attr( self::OPT ); ?>[words]" class="small-text" min="120" max="1200" step="10" value="<?php echo (int) self::words(); ?>" /> <?php esc_html_e( 'words', 'dazont-ecom' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-cc-links"><?php esc_html_e( 'Internal links', 'dazont-ecom' ); ?></label></th>
					<td>
						<input type="number" id="dze-cc-links" name="<?php echo esc_attr( self::OPT ); ?>[links]" class="small-text" min="0" max="12" value="<?php echo (int) self::links(); ?>" />
						<p class="description"><?php esc_html_e( 'Maximum links inserted per description (0 disables internal linking). Only URLs from the category tree, the best sellers and the optional sitemap are allowed.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-cc-sitemap"><?php esc_html_e( 'Sitemap URL (optional)', 'dazont-ecom' ); ?></label></th>
					<td>
						<input type="url" id="dze-cc-sitemap" name="<?php echo esc_attr( self::OPT ); ?>[sitemap]" class="regular-text" value="<?php echo esc_attr( self::sitemap_url() ); ?>" placeholder="<?php echo esc_attr( home_url( '/wp-sitemap.xml' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Widens the link pool with pages the category tree does not cover (guides, landing pages). Cached 12 hours. Leave empty to link only to categories and products.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-cc-prompt"><?php esc_html_e( 'Writing prompt', 'dazont-ecom' ); ?></label></th>
					<td>
						<textarea id="dze-cc-prompt" name="<?php echo esc_attr( self::OPT ); ?>[prompt]" rows="12" class="large-text code" placeholder="<?php echo esc_attr( self::default_prompt() ); ?>"><?php echo esc_textarea( (string) ( $s['prompt'] ?? '' ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Empty = shipped default (shown greyed). The category data, the queries, the link list, the language and the length are added automatically.', 'dazont-ecom' ); ?>
							<button type="button" class="button-link" id="dze-cc-restore">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save category settings', 'dazont-ecom' ) ); ?>
		</form>
		</div>
		<script>
		jQuery( function ( $ ) { $( '#dze-cc-restore' ).on( 'click', function () { $( '#dze-cc-prompt' ).val( '' ); } ); } );
		</script>
		<?php
	}
}
