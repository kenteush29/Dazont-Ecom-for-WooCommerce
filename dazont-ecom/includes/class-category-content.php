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
 *   2. The category tree: parent, children, siblings and the main top-level
 *      categories — the internal-link pool. Category-to-category only, since
 *      the page already lists its own products.
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
	public const GEN_META  = '_dze_desc_generated';
	public const CRON_HOOK = 'dze_cc_sitemap_check';
	/** Cached verdict of the question sifting pass, per category. */
	private const Q_META   = '_dze_cc_questions';

	/**
	 * How many sitemap URLs are kept in cache. Each description uses a
	 * handful, picked by relevance, but the pool has to hold the whole site
	 * for that pick to mean anything.
	 */
	private const SITEMAP_KEEP = 2500;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'maybe_dismiss_sitemap_notice' ] );
		add_action( 'admin_notices', [ $this, 'sitemap_notice' ] );
		// Daily background read, so the admin never waits on the sitemap and the
		// status shown is a real one.
		add_action( 'admin_init', [ $this, 'schedule_sitemap_check' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'cron_sitemap_check' ] );
		// Categories list: status column + popup.
		add_filter( 'manage_edit-product_cat_columns', [ $this, 'add_column' ] );
		add_filter( 'manage_product_cat_custom_column', [ $this, 'render_column' ], 10, 3 );
		add_action( 'admin_footer-edit-tags.php', [ $this, 'list_modal' ] );
		// Same writer on the single-category screen, under its Description field.
		add_action( 'product_cat_edit_form', [ $this, 'edit_form_box' ] );
		// Bulk: send a selection to the writing queue instead of doing it here.
		add_filter( 'bulk_actions-edit-product_cat', [ $this, 'bulk_actions' ] );
		add_filter( 'handle_bulk_actions-edit-product_cat', [ $this, 'handle_bulk' ], 10, 3 );
		add_action( 'admin_notices', [ $this, 'bulk_notice' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		// AJAX.
		add_action( 'wp_ajax_dze_cc_panel', [ $this, 'ajax_panel' ] );
		add_action( 'wp_ajax_dze_cc_generate', [ $this, 'ajax_generate' ] );
		add_action( 'wp_ajax_dze_cc_links', [ $this, 'ajax_links' ] );
		add_action( 'wp_ajax_dze_cc_diff', [ $this, 'ajax_diff' ] );
		add_action( 'wp_ajax_dze_cc_apply', [ $this, 'ajax_apply' ] );
		add_action( 'wp_ajax_dze_cc_save_prompt', [ $this, 'ajax_save_prompt' ] );
		add_action( 'wp_ajax_dze_cc_sitemap_test', [ $this, 'ajax_sitemap_test' ] );
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
			'autoload'          => false, // admin-only settings: never loaded on a shop page.
		] );
	}

	public function sanitize( $in ): array {
		$in  = is_array( $in ) ? $in : [];
		$out = self::get_settings();
		// Empty (0) means "work it out from the category" — see size_for().
		if ( isset( $in['words'] ) ) {
			$v            = (int) $in['words'];
			$out['words'] = $v > 0 ? max( 120, min( 2500, $v ) ) : 0;
		}
		if ( isset( $in['links'] ) ) {
			$v            = (int) $in['links'];
			$out['links'] = $v > 0 ? min( 14, $v ) : 0;
		}
		if ( isset( $in['form'] ) ) {
			$out['links_off'] = empty( $in['links_off'] ) ? 0 : 1;
			$before           = ! empty( $out['sitemap_products'] );
			$out['sitemap_products'] = empty( $in['sitemap_products'] ) ? 0 : 1;
			if ( $before !== (bool) $out['sitemap_products'] ) {
				delete_transient( 'dze_cc_sitemap_v8' ); // different files to read.
			}
		}
		if ( isset( $in['sitemap'] ) ) {
			$url = esc_url_raw( trim( (string) $in['sitemap'] ) );
			if ( $url !== ( $out['sitemap'] ?? '' ) ) {
				delete_transient( 'dze_cc_sitemap_v8' ); // a new URL is re-read at once.
			}
			$out['sitemap'] = $url;
		}
		if ( isset( $in['model'] ) ) {
			$out['model'] = sanitize_text_field( (string) $in['model'] );
		}
		if ( isset( $in['prompt'] ) ) {
			$out['prompt'] = sanitize_textarea_field( (string) $in['prompt'] );
		}
		return $out;
	}

	/**
	 * How big a description this category deserves, and how many links it can
	 * carry — worked out from the category itself rather than from one number
	 * shared by the whole shop. A hub with 400 products and eight
	 * sub-categories has far more to say, and far more to point at, than a
	 * leaf with six products.
	 *
	 * @return array{words:int,links:int,products:int,subs:int,auto_words:bool,auto_links:bool}
	 */
	/**
	 * Products behind a category — the ones in it AND in every sub-category
	 * below it, at any depth. $term->count only holds what is attached to the
	 * term itself, which reads 0 on a parent whose products all live one level
	 * down. Cached six hours: it moves with the catalogue, not with the page.
	 */
	public static function products_behind( int $term_id ): int {
		$key    = 'dze_cc_pcount_' . $term_id;
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$q = new WP_Query( [
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $term_id,
					'include_children' => true, // the whole branch, however deep.
				],
			],
		] );
		$n = (int) $q->found_posts;
		set_transient( $key, $n, 6 * HOUR_IN_SECONDS );
		return $n;
	}

	public static function size_for( int $term_id ): array {
		$s        = self::get_settings();
		$products = $term_id ? self::products_behind( $term_id ) : 0;
		// Direct children are the link targets; the whole branch drives length.
		$subs     = $term_id ? (int) count( get_terms( [
			'taxonomy'   => 'product_cat',
			'parent'     => $term_id,
			'hide_empty' => false,
			'fields'     => 'ids',
		] ) ) : 0;
		$branch = $term_id ? (int) count( get_term_children( $term_id, 'product_cat' ) ) : 0;

		// Depth of catalogue behind the page, flattened: going from 10 to 100
		// products says a lot, going from 900 to 1000 says little. A page with
		// a whole branch under it is a guide, not a paragraph — tokens are
		// cheap, a thin page on a big branch is what actually costs us.
		$depth = (int) round( sqrt( max( 0, $products ) ) * 45 );
		$words = 450 + min( 1200, $depth ) + 60 * min( 12, $branch );
		$words = max( 600, min( 2500, (int) ( round( $words / 50 ) * 50 ) ) );

		// One link per ~150 words, and never fewer than there are sub-categories
		// to point at.
		$links = max( 3, min( 14, (int) round( $words / 150 ) ) );
		$links = max( $links, min( 14, max( $subs, $branch ) ) );

		$set_w = (int) ( $s['words'] ?? 0 );
		$set_l = (int) ( $s['links'] ?? 0 );
		if ( ! empty( $s['links_off'] ) ) {
			$set_l = 0;
			$links = 0;
		}
		return [
			'words'      => $set_w > 0 ? $set_w : $words,
			'links'      => ! empty( $s['links_off'] ) ? 0 : ( $set_l > 0 ? $set_l : $links ),
			'products'   => $products,
			'subs'       => $subs,
			'branch'     => $branch,
			'auto_words' => $set_w < 1,
			'auto_links' => empty( $s['links_off'] ) && $set_l < 1,
		];
	}

	/** Shop-wide fallback, used where no category is in play. */
	public static function words(): int {
		$v = (int) ( self::get_settings()['words'] ?? 0 );
		return $v > 0 ? max( 120, $v ) : 350;
	}

	/** Shop-wide fallback; per-category figures come from size_for(). */
	public static function links(): int {
		$s = self::get_settings();
		if ( ! empty( $s['links_off'] ) ) {
			return 0;
		}
		$v = (int) ( $s['links'] ?? 0 );
		return $v > 0 ? min( 14, $v ) : 5;
	}

	/** URL saved by hand in Settings → Categories, if any. */
	public static function sitemap_override(): string {
		return trim( (string) ( self::get_settings()['sitemap'] ?? '' ) );
	}

	/**
	 * Sitemap published by the site itself. Rank Math, Yoast, SEOPress, All in
	 * One SEO and WordPress core each expose their own address — no need to ask
	 * the owner for it.
	 *
	 * @return array{url:string,source:string}
	 */
	public static function detect_sitemap(): array {
		// Rank Math — its router knows the address even on a sub-directory install.
		if ( class_exists( '\RankMath\Sitemap\Router' ) && method_exists( '\RankMath\Sitemap\Router', 'get_base_url' ) ) {
			return [ 'url' => \RankMath\Sitemap\Router::get_base_url( 'sitemap_index.xml' ), 'source' => 'Rank Math' ];
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return [ 'url' => home_url( '/sitemap_index.xml' ), 'source' => 'Rank Math' ];
		}
		if ( class_exists( 'WPSEO_Sitemaps_Router' ) && method_exists( 'WPSEO_Sitemaps_Router', 'get_base_url' ) ) {
			return [ 'url' => \WPSEO_Sitemaps_Router::get_base_url( 'sitemap_index.xml' ), 'source' => 'Yoast SEO' ];
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return [ 'url' => home_url( '/sitemap_index.xml' ), 'source' => 'Yoast SEO' ];
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return [ 'url' => home_url( '/sitemap.xml' ), 'source' => 'SEOPress' ];
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return [ 'url' => home_url( '/sitemap.xml' ), 'source' => 'All in One SEO' ];
		}
		// WordPress has served its own sitemap since 5.5, unless it was disabled.
		if ( function_exists( 'wp_sitemaps_get_server' ) && apply_filters( 'wp_sitemaps_enabled', true ) ) {
			return [ 'url' => home_url( '/wp-sitemap.xml' ), 'source' => 'WordPress' ];
		}
		return [ 'url' => '', 'source' => '' ];
	}

	/**
	 * URL segments that mark a single product, taken from the shop's own
	 * permalink settings (/product/, /boutique/, whatever it was set to) plus
	 * the WooCommerce defaults. Used to recognise a product URL in a sitemap
	 * whatever file it was listed in.
	 */
	public static function product_bases(): array {
		$bases = [ 'product', 'produit' ];
		if ( function_exists( 'wc_get_permalink_structure' ) ) {
			$st = wc_get_permalink_structure();
			foreach ( [ 'product_rewrite_slug', 'product_base' ] as $k ) {
				$v = trim( (string) ( $st[ $k ] ?? '' ), '/' );
				// A base like "%product_cat%" is a placeholder, not a segment.
				if ( '' !== $v && false === strpos( $v, '%' ) ) {
					$bases[] = $v;
				}
			}
		}
		return array_values( array_unique( array_map( 'preg_quote', $bases ) ) );
	}

	/** The sitemap actually used: the saved URL, else the one detected. */
	public static function sitemap_url(): string {
		$own = self::sitemap_override();
		return '' !== $own ? $own : self::detect_sitemap()['url'];
	}

	/** Where that URL comes from — '' when it was typed by hand. */
	public static function sitemap_source(): string {
		return '' !== self::sitemap_override() ? '' : self::detect_sitemap()['source'];
	}

	/**
	 * Pages read from the sitemap in use, cached 12h. A sitemap INDEX is
	 * followed one level (that is what Rank Math, Yoast and wp-sitemap.xml
	 * serve), preferring the category/page sitemaps.
	 *
	 * @return array{urls:array,status:string,count:int,checked:int}
	 */
	public static function sitemap_pages( bool $force = false ): array {
		$url = self::sitemap_url();
		if ( '' === $url ) {
			return [ 'urls' => [], 'status' => 'off', 'count' => 0, 'checked' => 0 ];
		}
		$cached = $force ? null : self::sitemap_cached();
		if ( null !== $cached ) {
			return $cached;
		}
		$out        = self::read_sitemap( $url );
		$out['url'] = $url;
		set_transient( 'dze_cc_sitemap_v8', $out, 'ok' === $out['status'] ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * What the last read found, or null when there is nothing to go on.
	 *
	 * Everything that runs while somebody is waiting for a page uses THIS, not
	 * sitemap_pages(): reading a sitemap is an HTTP call to our own site, and
	 * one PHP worker blocked on another PHP worker is how a shop starts
	 * timing out. Only the daily cron and the Test button actually fetch.
	 */
	public static function sitemap_cached(): ?array {
		$cached = get_transient( 'dze_cc_sitemap_v8' );
		// A cache read for another address (SEO plugin swapped) is thrown away.
		return ( is_array( $cached ) && ( $cached['url'] ?? '' ) === self::sitemap_url() ) ? $cached : null;
	}

	/**
	 * Reads one sitemap URL right now — no cache, no settings. Used by
	 * sitemap_pages() and by the Test button (which checks the URL currently
	 * typed in the field, saved or not).
	 *
	 * @return array{urls:array,status:string,count:int,checked:int}
	 */
	public static function read_sitemap( string $url ): array {
		if ( '' === trim( $url ) ) {
			return [ 'urls' => [], 'status' => 'off', 'count' => 0, 'checked' => 0 ];
		}
		// Short timeouts on purpose: this is a loopback call to our own server,
		// so a slow answer means the site is already busy — waiting on it would
		// only make things worse.
		$fetch = static function ( string $u, int $timeout ): string {
			$r = wp_remote_get( $u, [ 'timeout' => $timeout, 'redirection' => 2 ] );
			return ( ! is_wp_error( $r ) && 200 === wp_remote_retrieve_response_code( $r ) )
				? (string) wp_remote_retrieve_body( $r )
				: '';
		};
		$body = $fetch( $url, 8 );
		if ( '' === $body ) {
			return [ 'urls' => [], 'status' => 'error', 'count' => 0, 'checked' => time() ];
		}
		$products = ! empty( self::get_settings()['sitemap_products'] );
		$locs     = [];
		preg_match_all( '#<loc>\s*([^<]+?)\s*</loc>#i', $body, $m );
		$first    = $m[1] ?? [];
		$children = 0;
		$read     = 0;
		$skipped  = 0;
		if ( false !== stripos( $body, '<sitemapindex' ) ) {
			// An index: every child is followed, not a handful of them. Rank Math
			// and Yoast split by post type AND by chunks of 200, so a shop easily
			// publishes a dozen — reading five of them was why the count came
			// back far short of what the sitemap actually holds.
			//
			// The product sitemaps are the exception, and they are skipped on
			// purpose: an individual product is never a link target here, the
			// category page already lists it, and they are also the longest.
			$queue    = [];
			$skiplist = [];
			$indexlist = [];
			foreach ( $first as $child ) {
				$name = basename( (string) wp_parse_url( $child, PHP_URL_PATH ) );
				// Products (unless asked for), and everything that is an archive
				// rather than a page someone would want to land on: tag,
				// attachment, author and media sitemaps. Linking a category
				// description to a tag archive helps nobody.
				$drop = ( ! $products && preg_match( '#product[-_]?sitemap|/product-sitemap#i', $child ) )
					|| preg_match( '#tag[-_]?sitemap|attachment[-_]?sitemap|author[-_]?sitemap|media[-_]?sitemap|brand[-_]?sitemap#i', $child );
				if ( $drop ) {
					++$skipped;
					$skiplist[]  = $name;
					$indexlist[] = $name . ' [skipped]';
					continue;
				}
				$queue[]     = $child;
				$indexlist[] = $name;
			}
			// Pages and categories first, then the blog: if the deadline below
			// cuts the run short, what was read is what matters most.
			$score = static function ( string $u ): int {
				if ( preg_match( '/categor|product.cat|page/i', $u ) ) {
					return 0;
				}
				return preg_match( '/post|blog|article|news/i', $u ) ? 1 : 2;
			};
			usort( $queue, static fn( $a, $b ) => $score( $a ) <=> $score( $b ) );
			$children = count( $queue );
			// Time budget rather than a fixed number of files: a slow server
			// stops the run instead of holding a worker for minutes, and the
			// next daily read picks up where this one stopped.
			$deadline = microtime( true ) + 25;
			$readlist = [];
			foreach ( $queue as $child ) {
				if ( microtime( true ) > $deadline ) {
					break;
				}
				$sub = $fetch( $child, 6 );
				++$read;
				$name = basename( (string) wp_parse_url( $child, PHP_URL_PATH ) );
				$n    = 0;
				if ( '' !== $sub && preg_match_all( '#<loc>\s*([^<]+?)\s*</loc>#i', $sub, $mm ) ) {
					$locs = array_merge( $locs, $mm[1] );
					$n    = count( $mm[1] );
				}
				$readlist[] = $name . ' (' . $n . ')';
			}
		} else {
			$locs = $first;
		}
		// Judge every URL on its own address, not on the name of the file it
		// came from: a sitemap called anything at all can still list products,
		// and a product URL has no business in a category's link pool.
		$bases = self::product_bases();
		$urls  = [];
		$found   = 0;
		$prod    = 0;
		$arch    = 0;
		$other   = 0;
		$deflang = self::default_lang();
		$seen  = [];
		foreach ( $locs as $loc ) {
			$loc = esc_url_raw( trim( $loc ) );
			if ( '' === $loc || preg_match( '/\.xml($|\?)/i', $loc ) ) {
				continue;
			}
			$slug = trim( (string) wp_parse_url( $loc, PHP_URL_PATH ), '/' );
			if ( isset( $seen[ $slug ] ) ) {
				continue; // the same page in the index twice.
			}
			$seen[ $slug ] = true;
			++$found;
			if ( ! $products && $bases && preg_match( '#(^|/)(' . implode( '|', $bases ) . ')/#i', '/' . $slug . '/' ) ) {
				++$prod;
				continue;
			}
			// An archive, a paginated page, a feed or a file: never something a
			// description should send a reader to.
			if ( preg_match( '#(^|/)(tag|product-tag|etiquette-produit|author|feed|page/\d+|comment-page-\d+|\d{4}/\d{2})(/|$)#i', $slug )
				|| preg_match( '#\.(jpe?g|png|gif|webp|svg|pdf|zip|mp4|avif)$#i', $slug ) ) {
				++$arch;
				continue;
			}
			// Only the main language is worked on: WPML lists the whole site once
			// per language, and the translations are WPML's job, not ours. They
			// are dropped here rather than filtered later, so the cache stays the
			// size of the site instead of the size of the site times nine.
			if ( '' !== $deflang && self::url_language( $loc ) !== $deflang ) {
				++$other;
				continue;
			}
			if ( count( $urls ) >= self::SITEMAP_KEEP ) {
				continue;
			}
			$urls[] = [
				'label' => $slug ? ucwords( str_replace( [ '-', '_', '/' ], ' ', $slug ) ) : $loc,
				'url'   => $loc,
				'kind'  => 'page',
			];
		}
		return [
			'urls'     => $urls,
			'status'   => $urls ? 'ok' : 'empty',
			'count'    => count( $urls ),
			'found'    => $found,
			'children' => $children,
			'read'     => $read,
			'skipped'  => $skipped,
			'index'     => count( $first ),
			'indexlist' => $indexlist ?? [],
			'products' => $prod,
			'archives' => $arch,
			'other'    => $other,
			'sample'   => array_slice( array_column( $urls, 'url' ), 0, 25 ),
			'readlist' => $readlist ?? [],
			'skiplist' => $skiplist ?? [],
			'checked'  => time(),
		];
	}

	public static function default_prompt(): string {
		return <<<'PROMPT'
You write the description of a product category for an online shop. Think of a shop assistant standing in that aisle: concise, concrete, genuinely useful — a short buying guide, not marketing filler.

STRUCTURE
- Open with 2 or 3 sentences: what this category covers, who it is for, what matters most when choosing.
- Then the <h2> headings asked for below, built FROM THE SUPPLIED QUERIES AND QUESTIONS, reusing their wording as naturally as possible:
  · secondary queries (same search intent, different wording) become topic headings;
  · buyer questions become question headings, each answered concretely below.
- Under each heading: enough to actually answer it — 2 to 3 sentences on a short page, a full paragraph or a short list on a long one. Practical advice: materials, sizing, use cases, what to check, what to avoid. No fluff, no repetition of the intro, no sentence that says nothing.

RULES
- Put the category's key phrasings in <strong>: its main query and the secondary queries you reused, the first time each appears. A handful in total, at most one per paragraph — bold what a buyer scans for, never whole sentences.
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
	 *
	 * Both modules read the same table, and a keyword set aside there is set
	 * aside here — with one exception. The sourcing matcher files every
	 * informational query as kw_type 'info' + status 'ignored', meaning "no
	 * product to source behind this". For a description those queries are the
	 * raw material, not noise: they are the buyer questions. So 'ignored' hides
	 * a keyword unless it was the matcher calling it informational.
	 */
	public static function keyword_pools( int $term_id, string $exclude = '', bool $with_questions = true ): array {
		global $wpdb;
		$out = [ 'titles' => [], 'questions' => [], 'total' => 0 ];
		if ( ! class_exists( 'DZE_Keywords' ) ) {
			return $out;
		}
		$table = DZE_Keywords::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return $out;
		}
		// Real size of the set, not the size of the slice read below.
		$out['total'] = (int) $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			"SELECT COUNT(*) FROM {$table} WHERE term_id = %d AND ( status <> 'ignored' OR kw_type = 'info' )",
			$term_id
		) );
		if ( ! $out['total'] ) {
			return $out;
		}

		$needle = mb_strtolower( trim( $exclude ) );
		$rows   = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			"SELECT keyword, volume FROM {$table}
			 WHERE term_id = %d AND ( status <> 'ignored' OR kw_type = 'info' )
			 ORDER BY volume DESC LIMIT 200",
			$term_id
		), ARRAY_A );
		foreach ( $rows as $r ) {
			$kw = trim( (string) $r['keyword'] );
			if ( '' === $kw || self::is_question( $kw ) ) {
				continue;
			}
			// The category's own name is not a "secondary" query.
			if ( '' !== $needle && mb_strtolower( $kw ) === $needle ) {
				continue;
			}
			$out['titles'][] = $kw . ( (int) $r['volume'] ? ' (' . (int) $r['volume'] . '/mo)' : '' );
			if ( count( $out['titles'] ) >= 20 ) {
				break;
			}
		}

		if ( $with_questions ) {
			$out['questions'] = self::question_pool( $term_id, $exclude );
		}
		return $out;
	}

	/**
	 * Buyer questions, searched across the WHOLE set instead of the top
	 * keywords: a question almost never ranks high on volume, so reading only
	 * the busiest rows finds none in an export of several thousand.
	 *
	 * A broad-match export also drags in questions that have nothing to do with
	 * the shop ("do military get free checked bags" under Tactical bags), so
	 * what comes back is ranked on how much it overlaps the category's own
	 * wording, not on volume alone.
	 */
	public static function question_pool( int $term_id, string $exclude = '' ): array {
		global $wpdb;
		$table = DZE_Keywords::table();
		$like  = [ "keyword LIKE '%?%'" ];
		foreach ( [
			'how', 'what', 'which', 'why', 'when', 'where', 'can', 'is', 'are', 'do', 'does', 'should',
			'comment', 'pourquoi', 'quel', 'quelle', 'quels', 'quelles', 'quand', 'est-ce', 'faut-il',
		] as $w ) {
			$like[] = $wpdb->prepare( 'keyword LIKE %s', $wpdb->esc_like( $w ) . ' %' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name, patterns prepared above.
			"SELECT keyword, volume FROM {$table}
			 WHERE term_id = %d AND ( status <> 'ignored' OR kw_type = 'info' ) AND ( " . implode( ' OR ', $like ) . " )
			 ORDER BY volume DESC LIMIT 200",
			$term_id
		), ARRAY_A );
		if ( ! $rows ) {
			return [];
		}
		// What this category is about, in words: its name plus its busiest
		// non-question queries.
		$top = (array) $wpdb->get_col( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			"SELECT keyword FROM {$table} WHERE term_id = %d AND ( status <> 'ignored' OR kw_type = 'info' ) ORDER BY volume DESC LIMIT 25",
			$term_id
		) );
		$term   = get_term( $term_id, 'product_cat' );
		$name   = ( $term && ! is_wp_error( $term ) ) ? $term->name : $exclude;
		$needle = self::stems( $name . ' ' . implode( ' ', $top ) );
		$floor  = count( self::stems( $name ) ) > 1 ? 2 : 1;

		$scored = [];
		foreach ( $rows as $r ) {
			$kw = trim( (string) $r['keyword'] );
			if ( '' === $kw ) {
				continue;
			}
			$hits = $needle ? count( array_intersect( $needle, self::stems( $kw ) ) ) : $floor;
			if ( $hits < $floor ) {
				continue; // Off-topic noise from a broad-match export.
			}
			$scored[] = [ 'kw' => $kw, 'v' => (int) $r['volume'], 'h' => $hits ];
		}
		usort( $scored, static fn( $a, $b ) => [ $b['h'], $b['v'] ] <=> [ $a['h'], $a['v'] ] );

		$out = [];
		foreach ( array_slice( $scored, 0, 40 ) as $q ) {
			$out[] = $q['kw'] . ( $q['v'] ? ' (' . $q['v'] . '/mo)' : '' );
		}
		return array_slice( self::keep_relevant( $term_id, (string) $name, $out ), 0, 15 );
	}

	/**
	 * Last sieve on the questions, and the only one that can tell subject from
	 * vocabulary.
	 *
	 * Word overlap cannot: "does spirit airlines charge military for bags"
	 * shares "military" and "bag" with a tactical-bag category and sails
	 * through, although it is about airline baggage allowance and a shop
	 * selling bags has nothing to say about it. So the shortlist is read once
	 * by the cheap model, which keeps what a customer of THIS shop would ask
	 * before buying, and the verdict is cached on the category until the
	 * candidate list itself changes.
	 *
	 * No key, no answer, any failure: the list goes through untouched rather
	 * than the writer losing its questions.
	 */
	public static function keep_relevant( int $term_id, string $name, array $questions ): array {
		if ( count( $questions ) < 4 || ! class_exists( 'DZE_Marketing_Ai' ) ) {
			return $questions;
		}
		$hash   = md5( $name . '|' . implode( '|', $questions ) );
		$cached = get_term_meta( $term_id, self::Q_META, true );
		if ( is_array( $cached ) && ( $cached['hash'] ?? '' ) === $hash ) {
			return (array) $cached['keep'];
		}

		$list = '';
		foreach ( $questions as $i => $q ) {
			$list .= $i . '. ' . $q . "\n";
		}
		$user = "SHOP CATEGORY: {$name}\n\n"
			. "QUESTIONS PULLED FROM SEARCH DATA:\n" . $list . "\n"
			. "Keep only the questions a customer would ask this shop before buying from this category — about the products themselves: material, size, use, care, choice, compatibility, quality.\n"
			. "Drop everything else, however many words it shares with the category: another industry's rules (airline baggage allowance, customs, shipping policies of other companies), another product entirely, a named brand or retailer, a job or a service.\n"
			. "Order what you keep by how useful the answer is to a buyer. Keep at most 15.\n"
			. 'OUTPUT: a JSON array of the kept numbers, nothing else. Example: [3,0,7]';

		try {
			DZE_Ai_Usage::unit( 'cat_sift' );
			$raw = DZE_Marketing_Ai::complete(
				'You sort search queries for an e-commerce category page. You are strict: a query that shares words with the category but is about another subject is dropped.',
				$user,
				self::sift_model(),
				400,
				30
			);
			DZE_Ai_Usage::unit();
		} catch ( \Throwable $e ) {
			DZE_Ai_Usage::unit();
			return $questions;
		}
		if ( ! preg_match( '/\[[^\]]*\]/s', $raw, $m ) ) {
			return $questions;
		}
		$idx = json_decode( $m[0], true );
		if ( ! is_array( $idx ) ) {
			return $questions;
		}
		$keep = [];
		foreach ( $idx as $i ) {
			if ( isset( $questions[ (int) $i ] ) ) {
				$keep[] = $questions[ (int) $i ];
			}
		}
		if ( ! $keep ) {
			return $questions; // A model answering "none" is more likely wrong than the data.
		}
		update_term_meta( $term_id, self::Q_META, [ 'hash' => $hash, 'keep' => $keep ] );
		return $keep;
	}

	/** Cheap model for the sifting pass; the matcher's model when set. */
	private static function sift_model(): string {
		$m = class_exists( 'DZE_Marketing_Ai' ) ? trim( (string) ( DZE_Marketing_Ai::get_settings()['match_model'] ?? '' ) ) : '';
		return '' !== $m ? $m : 'claude-haiku-4-5-20251001';
	}

	/** tokens(), with a crude plural trim so "bag" and "bags" are one word. */
	private static function stems( string $s ): array {
		$out = [];
		foreach ( self::tokens( $s ) as $t ) {
			$out[] = ( mb_strlen( $t ) > 3 && 's' === mb_substr( $t, -1 ) ) ? mb_substr( $t, 0, -1 ) : $t;
		}
		return array_values( array_unique( $out ) );
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

	/** Words too common to say anything about what a page is about. */
	private static function stop_words(): array {
		return [
			'the', 'and', 'for', 'with', 'your', 'you', 'our', 'from', 'that', 'this', 'are', 'how', 'what',
			'why', 'best', 'top', 'guide', 'all', 'about', 'into', 'out', 'not', 'can', 'les', 'des', 'une',
			'pour', 'avec', 'dans', 'sur', 'vos', 'votre', 'nos', 'notre', 'comment', 'pourquoi', 'meilleur',
		];
	}

	/** Meaningful words of a string, lower-cased and de-duplicated. */
	private static function tokens( string $s ): array {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $s ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		$stop  = self::stop_words();
		$out   = [];
		foreach ( $words as $w ) {
			if ( mb_strlen( $w ) > 2 && ! in_array( $w, $stop, true ) ) {
				$out[ $w ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Blog posts and pages worth linking to FROM this category, read from
	 * WordPress itself — so the anchor can use the real title — and ranked by
	 * how much their wording overlaps the category and its imported queries.
	 * That is how a camouflage category ends up pointing at the camo pages.
	 */
	public static function editorial_pool( int $term_id, int $limit = 10 ): array {
		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return [];
		}
		$kw    = self::keyword_pools( $term_id, $term->name, false );
		$needle = self::tokens( $term->name . ' ' . implode( ' ', array_slice( $kw['titles'], 0, 12 ) ) );
		if ( ! $needle ) {
			return [];
		}
		// Pages that exist for the checkout, not for the reader.
		$skip = array_filter( [
			(int) get_option( 'page_on_front' ),
			(int) get_option( 'page_for_posts' ),
			(int) get_option( 'wp_page_for_privacy_policy' ),
			function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'cart' ) : 0,
			function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0,
			function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'myaccount' ) : 0,
			function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'terms' ) : 0,
		] );

		// Same reason: the posts and pages offered are the main-language ones.
		$lang = self::default_lang();
		if ( '' !== $lang ) {
			do_action( 'wpml_switch_language', $lang );
		}
		$posts = get_posts( [
			'post_type'              => [ 'post', 'page' ],
			'post_status'            => 'publish',
			'posts_per_page'         => 300,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'exclude'                => $skip,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );

		$scored = [];
		foreach ( $posts as $p ) {
			$title = get_the_title( $p );
			$hits  = array_intersect( $needle, self::tokens( $title . ' ' . $p->post_name ) );
			if ( ! $hits ) {
				continue;
			}
			$scored[] = [
				'label' => $title,
				'url'   => (string) get_permalink( $p ),
				'kind'  => 'post' === $p->post_type ? 'blog post' : 'page',
				'score' => count( $hits ),
			];
		}
		if ( '' !== $lang ) {
			do_action( 'wpml_switch_language', null ); // back to the admin language.
		}
		usort( $scored, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		$out = [];
		foreach ( array_slice( $scored, 0, max( 0, $limit ) ) as $row ) {
			unset( $row['score'] );
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Internal-link candidates: other CATEGORIES — parent, children, siblings
	 * and the main top-level categories — plus the blog posts and pages that
	 * talk about the same thing. Individual products are deliberately excluded:
	 * the category page already lists them. URLs come straight from WordPress,
	 * so they always resolve.
	 */
	public static function link_pool( int $term_id ): array {
		$pool = [];
		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return $pool;
		}
		// Everything below is read in the site's main language: that is the one
		// the shop is written in, and the translations belong to WPML.
		$plang = self::default_lang();
		if ( '' !== $plang ) {
			do_action( 'wpml_switch_language', $plang );
		}
		// What this category is about, in stemmed words: used to tell a candidate
		// that belongs to it from one that merely exists.
		$kwt    = self::keyword_pools( $term_id, $term->name, false );
		$needle = self::stems( $term->name . ' ' . implode( ' ', array_slice( $kwt['titles'], 0, 12 ) ) );

		$add = static function ( string $label, string $url, string $kind ) use ( &$pool, $needle ) {
			$url = (string) $url;
			// Keyed without the trailing slash: the sitemap and get_permalink()
			// do not always agree on it, and that would double an entry.
			$key = untrailingslashit( $url );
			if ( '' === $url || isset( $pool[ $key ] ) ) {
				return;
			}
			// A sub-category IS part of this category, so it is always close. For
			// everything else, closeness is measured: two words in common with
			// the category and its queries. "Tactical backpack" earns it,
			// "motorcycle boots" does not, however tactical it is.
			$hits  = count( array_intersect( $needle, self::stems( $label ) ) );
			$close = in_array( $kind, [ 'sub-category', 'parent category' ], true ) || $hits >= 2;
			$pool[ $key ] = [
				'label' => $label,
				'url'   => $url,
				'kind'  => $kind,
				'score' => $hits,
				'close' => $close,
			];
		};
		if ( $term->parent ) {
			$parent = get_term( $term->parent, 'product_cat' );
			if ( $parent && ! is_wp_error( $parent ) ) {
				$add( $parent->name, (string) get_term_link( $parent ), 'parent category' );
			}
		}
		// Direct children first, then the level below them: a hub is expected to
		// point at its whole branch, not only at its immediate children.
		foreach ( get_terms( [ 'taxonomy' => 'product_cat', 'parent' => $term_id, 'hide_empty' => true, 'number' => 20 ] ) as $child ) {
			if ( ! is_wp_error( $child ) ) {
				$add( $child->name, (string) get_term_link( $child ), 'sub-category' );
			}
		}
		if ( count( $pool ) < 20 ) {
			foreach ( get_terms( [ 'taxonomy' => 'product_cat', 'child_of' => $term_id, 'hide_empty' => true, 'number' => 24 ] ) as $deep ) {
				if ( ! is_wp_error( $deep ) ) {
					$add( $deep->name, (string) get_term_link( $deep ), 'sub-category' );
				}
			}
		}
		foreach ( get_terms( [ 'taxonomy' => 'product_cat', 'parent' => (int) $term->parent, 'hide_empty' => true, 'number' => 12, 'exclude' => [ $term_id, (int) get_option( 'default_product_cat' ) ] ] ) as $sib ) {
			if ( ! is_wp_error( $sib ) && 'uncategorized' !== $sib->slug ) {
				$add( $sib->name, (string) get_term_link( $sib ), 'related category' );
			}
		}
		// Other top-level categories, so a leaf can also point sideways in the tree.
		foreach ( get_terms( [ 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => true, 'number' => 10, 'exclude' => [ $term_id, (int) get_option( 'default_product_cat' ) ] ] ) as $top ) {
			if ( ! is_wp_error( $top ) && 'uncategorized' !== $top->slug ) {
				$add( $top->name, (string) get_term_link( $top ), 'main category' );
			}
		}
		// No products here on purpose: the category page already lists them, so
		// linking to individual products from its description adds nothing.

		// The editorial side: blog posts and pages on the same subject. They
		// carry their real title, which makes for a far better anchor.
		foreach ( self::editorial_pool( $term_id ) as $row ) {
			$add( $row['label'], $row['url'], $row['kind'] );
		}

		// Last layer: anything else the sitemap knows about and WordPress does
		// not serve here (another site section, a plugin-made landing page).
		// Cache only — nobody waits on an HTTP call to build this list. A big
		// sitemap holds thousands of URLs, so only the ones whose address talks
		// about this category come in, best first.
		$cached = self::sitemap_cached()['urls'] ?? [];
		if ( $cached ) {
			$kw     = self::keyword_pools( $term_id, $term->name, false );
			$needle = self::tokens( $term->name . ' ' . implode( ' ', array_slice( $kw['titles'], 0, 12 ) ) );
			$ranked = [];
			foreach ( $cached as $page ) {
				$hits = $needle ? count( array_intersect( $needle, self::tokens( $page['url'] ) ) ) : 0;
				if ( $hits > 0 ) {
					$page['score'] = $hits;
					$ranked[]      = $page;
				}
			}
			usort( $ranked, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
			foreach ( array_slice( $ranked, 0, 8 ) as $page ) {
				if ( count( $pool ) >= 40 ) {
					break;
				}
				$add( $page['label'], $page['url'], 'sitemap page' );
			}
		}
		if ( '' !== $plang ) {
			do_action( 'wpml_switch_language', null );
		}
		$pool = array_values( $pool );
		// Closest first: sub-categories, then what shares the most wording.
		usort( $pool, static function ( $a, $b ) {
			$rank = static fn( $x ) => ( 'sub-category' === $x['kind'] || 'parent category' === $x['kind'] ) ? 2 : ( ! empty( $x['close'] ) ? 1 : 0 );
			return [ $rank( $b ), $b['score'] ?? 0 ] <=> [ $rank( $a ), $a['score'] ?? 0 ];
		} );
		return $pool;
	}

	/** The site's main language (WPML default), '' when not multilingual. */
	public static function default_lang(): string {
		return (string) apply_filters( 'wpml_default_language', '' );
	}

	/** WPML language code of a category, else the site's default. */
	public static function lang_code( int $term_id ): string {
		$details = apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $term_id, 'element_type' => 'product_cat' ] );
		$code    = is_array( $details ) ? (string) ( $details['language_code'] ?? '' ) : '';
		if ( '' === $code ) {
			$code = (string) apply_filters( 'wpml_default_language', '' );
		}
		return $code;
	}

	/**
	 * Base URL of each active language, longest first.
	 *
	 * WPML can put the language in a sub-directory (/fr/…), in its own domain,
	 * or in a query string, and a sitemap lists all of them together. Asking
	 * WPML itself for the home URL of each language covers the three modes
	 * without guessing at the shape of the URL.
	 *
	 * @return array<string,string> language code => base URL
	 */
	public static function language_bases(): array {
		static $bases = null;
		if ( null !== $bases ) {
			return $bases;
		}
		$bases  = [];
		$active = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
		if ( is_array( $active ) ) {
			foreach ( $active as $code => $l ) {
				$code = (string) ( $l['language_code'] ?? $code );
				$url  = (string) apply_filters( 'wpml_permalink', home_url( '/' ), $code );
				if ( '' !== $url ) {
					$bases[ $code ] = untrailingslashit( $url );
				}
			}
		}
		// Longest base first, so /fr wins over / when both match.
		uasort( $bases, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
		return $bases;
	}

	/**
	 * Which language a URL belongs to, '' when the site is not multilingual or
	 * the URL sits outside every language base (the default language, then).
	 */
	public static function url_language( string $url ): string {
		$bases = self::language_bases();
		if ( count( $bases ) < 2 ) {
			return '';
		}
		$url = untrailingslashit( $url );
		foreach ( $bases as $code => $base ) {
			if ( 0 === stripos( $url . '/', $base . '/' ) ) {
				return (string) $code;
			}
			// Query-string mode: ?lang=fr rather than a path.
			if ( preg_match( '/[?&]lang=([a-z-]{2,7})/i', $url, $m ) ) {
				return strtolower( $m[1] );
			}
		}
		return (string) ( array_key_first( array_slice( $bases, -1, 1, true ) ) ?? '' );
	}

	/**
	 * The language descriptions are written in: the site's main one, always.
	 * A translated category is WPML's business, not the writer's.
	 */
	private static function language( int $term_id ): string {
		$code = self::default_lang();
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

	/**
	 * The plan: the headings this description will have, and nothing else.
	 *
	 * Writing two thousand words in one call means one request of two to four
	 * minutes — too long for any host, and too slow to watch. So the work is
	 * cut up: this call is short (a list of headings), and each section is
	 * written on its own afterwards. Same material, same length, same prompt,
	 * but no single step long enough to be killed.
	 *
	 * @return array{intro:string,sections:array<int,string>,words:int}
	 */
	public static function plan( int $term_id, string $prompt_override = '' ): array {
		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			throw new RuntimeException( __( 'Category not found.', 'dazont-ecom' ) );
		}
		if ( ! class_exists( 'DZE_Marketing_Ai' ) ) {
			throw new RuntimeException( __( 'The Marketing Assistant module is required for the Anthropic key.', 'dazont-ecom' ) );
		}
		$size = self::size_for( $term_id );
		$kw   = self::keyword_pools( $term_id, $term->name );
		$n    = max( 3, min( 10, (int) round( $size['words'] / 220 ) ) );

		$user = "CATEGORY: {$term->name}\n"
			. 'Products behind it: ' . (int) $size['products'] . "\n\n"
			. ( $kw['titles'] ? "SECONDARY QUERIES (build headings on these, keeping their wording):\n- " . implode( "\n- ", $kw['titles'] ) . "\n\n" : '' )
			. ( $kw['questions'] ? "BUYER QUESTIONS (turn the best ones into question headings):\n- " . implode( "\n- ", $kw['questions'] ) . "\n\n" : '' )
			. "Plan a buying-guide description for this category: {$n} <h2> headings, in reading order, each one a topic a buyer needs before choosing. Reuse the wording of the queries above where it reads naturally, and do not repeat the same angle twice.\n"
			. 'LANGUAGE: ' . self::language( $term_id ) . ".\n"
			. 'OUTPUT: a JSON array of ' . $n . ' heading strings, nothing else.';

		DZE_Ai_Usage::unit( 'cat_desc' );
		$raw = DZE_Marketing_Ai::complete(
			'You plan e-commerce category pages. You answer with JSON and nothing else.',
			$user,
			self::writer_model(),
			800,
			60
		);
		DZE_Ai_Usage::unit();
		preg_match( '/\[.*\]/s', $raw, $m );
		$list = $m ? json_decode( $m[0], true ) : null;
		$out  = [];
		foreach ( (array) $list as $h ) {
			$h = trim( wp_strip_all_tags( (string) $h ) );
			if ( '' !== $h ) {
				$out[] = $h;
			}
		}
		if ( ! $out ) {
			throw new RuntimeException( __( 'The model did not return a usable plan.', 'dazont-ecom' ) );
		}
		return [
			'sections' => array_slice( $out, 0, $n ),
			'words'    => (int) $size['words'],
		];
	}

	/**
	 * Writes ONE piece: the opening when $index is -1, otherwise the section
	 * under heading $index. Each call is short enough to finish well inside any
	 * host's limit, and the pieces are assembled by the queue.
	 */
	public static function write_part( int $term_id, int $index, array $plan, string $prompt_override = '' ): string {
		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			throw new RuntimeException( __( 'Category not found.', 'dazont-ecom' ) );
		}
		$sections = (array) ( $plan['sections'] ?? [] );
		$total    = max( 1, count( $sections ) );
		$budget   = max( 90, (int) round( ( (int) ( $plan['words'] ?? 800 ) ) / ( $total + 1 ) ) );
		$kw       = self::keyword_pools( $term_id, $term->name );

		$context = "CATEGORY: {$term->name}\n"
			. ( $kw['titles'] ? 'Queries this page targets: ' . implode( ' · ', array_slice( $kw['titles'], 0, 12 ) ) . "\n" : '' )
			. 'Full plan of the page: ' . implode( ' | ', $sections ) . "\n\n";

		if ( $index < 0 ) {
			$task = "Write ONLY the opening: 2 or 3 sentences saying what this category covers, who it is for, and what matters most when choosing. No heading, no list.\n";
		} else {
			$h    = (string) ( $sections[ $index ] ?? '' );
			$task = "Write ONLY this section: <h2>{$h}</h2> followed by its body — about {$budget} words. Practical and concrete: materials, sizes, uses, what to check, what to avoid. Answer the heading, do not introduce the category again, do not cover the other sections of the plan.\n";
		}

		$user = $context . $task
			. "\n--- STYLE ---\n" . ( '' !== $prompt_override ? $prompt_override : self::prompt() )
			. "\n\n--- FACTS ---\n"
			. 'LANGUAGE: write in ' . self::language( $term_id ) . ". This overrides the language of the instructions above.\n"
			. "OUTPUT: the HTML fragment for this piece only — no markdown, no code fence, no comment, no <html> wrapper.";

		DZE_Ai_Usage::unit( 'cat_desc' );
		$html = DZE_Marketing_Ai::complete(
			'You are an e-commerce category copywriter. ' . ( class_exists( 'DZE_Content' ) ? DZE_Content::store_context() : '' ),
			$user,
			self::writer_model(),
			$budget * 3 + 400,
			90
		);
		DZE_Ai_Usage::unit();
		$html = trim( preg_replace( '/^```(?:html)?|```$/m', '', $html ) );
		return wp_kses_post( $html );
	}

	/** Model used for writing; the shop's chosen model unless one is set. */
	public static function writer_model(): string {
		$m = (string) ( self::get_settings()['model'] ?? '' );
		return '' !== trim( $m ) ? $m : '';
	}

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

		$size = self::size_for( $term_id );
		if ( $links && $size['links'] > 0 ) {
			$list = [];
			foreach ( $links as $l ) {
				$list[] = $l['label'] . ' [' . $l['kind'] . '] → ' . $l['url'];
			}
			$user .= "\n--- INTERNAL LINKS (use these URLs ONLY) ---\n- " . implode( "\n- ", $list ) . "\n";
			$user .= 'Insert ' . max( 1, $size['links'] - 2 ) . ' to ' . $size['links'] . " of them, the sub-categories first.\n";
			$user .= "ANCHOR RULE. The anchor must NAME the page it points to — a reader who sees only the anchor knows where it goes. Get as close to the target's own name as the sentence allows, then stop:\n"
				. "- A category: use its name as it stands. \"Jute rugs\" links to Jute rugs.\n"
				. "- An article or a page: use the SUBJECT of its title, not the title itself. Keep the words that identify it, drop the question mark, the verbs and the filler. \"Why Do Gel Blaster Players Prefer Tactical Gear for Outdoor Matches?\" is anchored on \"tactical gear for gel blaster games\" — never pasted whole.\n"
				. "- Two to six words. Always inside a sentence that would read perfectly well without the link: the link is woven into what you were saying anyway.\n"
				. "- Forbidden: a title dropped in as a quote, a sentence bolted on at the end (\"See X for more\", \"Read Y to find out\"), \"here\", \"this page\", \"learn more\", and any anchor that leaves the destination ambiguous.\n";
			$user .= "A target marked [blog post] or [page] already covers its subject in full: mention it in a sentence and link to it, do not explain the subject again here.\n";
		}

		$user .= "\n--- INSTRUCTIONS ---\n" . ( '' !== $prompt_override ? $prompt_override : self::prompt() );
		$user .= "\n\n--- FACTS (never contradict these) ---\n"
			. 'LANGUAGE: write in ' . self::language( $term_id ) . ". This overrides the language of the instructions above.\n"
			. 'LENGTH: about ' . $size['words'] . ' words in total, spread over about ' . max( 3, min( 10, (int) round( $size['words'] / 220 ) ) ) . " <h2> sections. Depth is expected: this page sits on a whole branch of the catalogue, so treat it as a buying guide, not a paragraph. Never pad — if a section has nothing concrete to say, cut it and give the room to one that does.\n"
			. "OUTPUT: the HTML fragment only — no markdown, no code fence, no comment before or after.";

		$system = 'You are an e-commerce category copywriter. ' . ( class_exists( 'DZE_Content' ) ? DZE_Content::store_context() : '' );
		// ~1.4 tokens per word, doubled so a long guide is never cut mid-sentence.
		$budget = min( 16000, (int) ( $size['words'] * 3 ) + 900 );
		DZE_Ai_Usage::unit( 'cat_desc' );
		$html   = DZE_Marketing_Ai::complete( $system, $user, '', $budget, 240 );
		DZE_Ai_Usage::unit();
		$html   = trim( preg_replace( '/^```(?:html)?|```$/m', '', $html ) );
		if ( '' === $html ) {
			throw new RuntimeException( __( 'The model returned nothing usable.', 'dazont-ecom' ) );
		}
		return wp_kses_post( $html );
	}

	/** URLs already linked inside a description. */
	public static function linked_urls( string $html ): array {
		preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $m );
		return array_values( array_unique( $m[1] ?? [] ) );
	}

	/**
	 * Internal linking pass on an EXISTING description: the text stays as it
	 * is, only links are added. The writer is allowed to re-word the few words
	 * carrying an anchor so it matches the target page — nothing else moves.
	 *
	 * @return array{html:string,added:int,before:int,after:int}
	 */
	public static function add_links( int $term_id, string $html, array $only = [] ): array {
		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			throw new RuntimeException( __( 'Category not found.', 'dazont-ecom' ) );
		}
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			throw new RuntimeException( __( 'This category has no description to work on yet.', 'dazont-ecom' ) );
		}
		if ( ! class_exists( 'DZE_Marketing_Ai' ) ) {
			throw new RuntimeException( __( 'The Marketing Assistant module is required for the Anthropic key.', 'dazont-ecom' ) );
		}
		$done  = self::linked_urls( $html );
		// $only: the targets ticked in the panel. Without it, the whole pool is
		// offered and the per-category figure decides how many are placed.
		$keys  = [];
		foreach ( $only as $u ) {
			$keys[ untrailingslashit( esc_url_raw( (string) $u ) ) ] = true;
		}
		$links = [];
		foreach ( self::link_pool( $term_id ) as $l ) {
			if ( in_array( $l['url'], $done, true ) ) {
				continue;
			}
			if ( $keys && ! isset( $keys[ untrailingslashit( $l['url'] ) ] ) ) {
				continue;
			}
			$links[] = $l;
		}
		if ( ! $links ) {
			throw new RuntimeException( $keys
				? __( 'The pages you picked are already linked in this text.', 'dazont-ecom' )
				: __( 'Every page this category can link to is already linked.', 'dazont-ecom' ) );
		}
		if ( $keys ) {
			// An explicit choice is a choice: place them all if a spot exists.
			$room = count( $links );
		} else {
			$max = self::size_for( $term_id )['links'];
			if ( $max < 1 ) {
				throw new RuntimeException( __( 'Internal linking is turned off in Settings → Categories.', 'dazont-ecom' ) );
			}
			$room = max( 1, $max - count( $done ) );
		}

		$list = [];
		foreach ( $links as $l ) {
			$list[] = $l['label'] . ' [' . $l['kind'] . '] → ' . $l['url'];
		}

		$user = "--- CATEGORY ---\nName: " . $term->name . "\n"
			. "\n--- LINK TARGETS (use these URLs ONLY) ---\n- " . implode( "\n- ", $list ) . "\n"
			. ( $done ? "\nAlready linked in the text, do not link again:\n- " . implode( "\n- ", $done ) . "\n" : '' )
			. "\n--- DESCRIPTION (HTML, to return with links added) ---\n" . $html . "\n"
			. "\n--- INSTRUCTIONS ---\n"
			. "This is an internal-linking pass, not a rewrite. Return the description exactly as it is, with internal links added.\n"
			. ( $keys
				? '- Add a link for EACH of the ' . $room . " targets above, on a different spot, unless the text truly offers no place for one.\n"
				: '- Add ' . max( 1, $room - 2 ) . ' to ' . $room . " links, each on a different target from the list above.\n" )
			. "- Place a link where the text already talks about that target, or comes close to it. If nothing in the text fits a target, leave that target out — a forced link is worse than no link.\n"
			. "- Targets marked [blog post] or [page] are the ones that help the reader most: link them wherever the text touches their subject, without explaining that subject any further here.\n"
			. "- ANCHOR RULE. The anchor must NAME the page it points to, as closely as the sentence allows. A category keeps its name as it stands; an article or a page is anchored on the SUBJECT of its title, not on the title itself — keep the identifying words, drop the question mark, the verbs and the filler, two to six words. Re-word the few words around it so it reads naturally.\n"
			. "- The sentence must still read perfectly well without the link. Never quote a title, never bolt a sentence on at the end (\"See X for more\", \"Read Y to find out\"), never anchor on \"here\", \"this page\", \"learn more\", never leave the destination ambiguous.\n"
			. "- Everything else stays byte-for-byte: same paragraphs, same headings, same order, same facts, same wording, same HTML structure. No sentence added, none removed, nothing reordered.\n"
			. "- Never link twice to the same URL, never link a whole sentence, never link inside a heading.\n"
			. "\n--- FACTS (never contradict these) ---\n"
			. 'LANGUAGE: the text is in ' . self::language( $term_id ) . " — keep it in that language.\n"
			. 'LINK FORMAT: <a href="URL">anchor</a>, using the URLs above verbatim.' . "\n"
			. 'OUTPUT: the full HTML fragment only — no markdown, no code fence, no comment before or after.';

		$system = 'You are an SEO editor doing internal linking on an existing e-commerce category page. You are conservative: you add links, you do not rewrite copy.';
		$words  = max( 120, str_word_count( wp_strip_all_tags( $html ) ) );
		DZE_Ai_Usage::unit( 'cat_links' );
		$out    = DZE_Marketing_Ai::complete( $system, $user, '', min( 16000, $words * 3 + 900 ), 240 );
		DZE_Ai_Usage::unit();
		$out    = trim( preg_replace( '/^```(?:html)?|```$/m', '', $out ) );
		if ( '' === $out ) {
			throw new RuntimeException( __( 'The model returned nothing usable.', 'dazont-ecom' ) );
		}
		$out = wp_kses_post( $out );

		// Safety net: a linking pass that lost a fifth of the text rewrote it.
		$kept = str_word_count( wp_strip_all_tags( $out ) );
		if ( $kept < $words * 0.8 ) {
			throw new RuntimeException( __( 'The text came back shortened instead of just linked — nothing was changed. Try again.', 'dazont-ecom' ) );
		}
		$before = count( $done );
		$after  = count( self::linked_urls( $out ) );
		return [
			'html'   => $out,
			'added'  => max( 0, $after - $before ),
			'before' => $before,
			'after'  => $after,
		];
	}

	/**
	 * Connection badge for the sitemap: off / connected / unreachable / empty.
	 * Pass a state array to describe a one-off read (the Test button).
	 */
	public static function sitemap_status_html( ?array $state = null ): string {
		$src = null === $state ? self::sitemap_source() : '';
		if ( null === $state ) {
			// Never fetch while a page is rendering: show what the last read
			// found, or say plainly that no read has happened yet.
			$state = self::sitemap_cached();
			if ( null === $state ) {
				return '' === self::sitemap_url()
					? '<span class="dze-key-badge is-missing">' . esc_html__( 'Sitemap: none connected — links stay inside the site pages listed above', 'dazont-ecom' ) . '</span>'
					: '<span class="dze-key-badge is-missing">' . esc_html__( 'Sitemap: not read yet — it is read once a day in the background, or now from Settings → Categories', 'dazont-ecom' ) . '</span>';
			}
		}
		$s = $state;
		if ( 'ok' === $s['status'] ) {
			$found = (int) ( $s['found'] ?? $s['count'] );
			$kept  = (int) $s['count'];
			$read  = (int) ( $s['read'] ?? 0 );
			$skip  = (int) ( $s['skipped'] ?? 0 );
			$index = (int) ( $s['index'] ?? 0 );
			$parts = [];

			if ( $index ) {
				// Say what the index holds, not only what was taken from it:
				// "10 read" alone reads like nine tenths went missing.
				$parts[] = sprintf(
					/* translators: 1: sub-sitemaps in the index, 2: sub-sitemaps read */
					esc_html__( '%1$s sub-sitemaps in the index, %2$s read', 'dazont-ecom' ),
					number_format_i18n( $index ),
					number_format_i18n( $read )
				);
				if ( $skip ) {
					$parts[] = sprintf(
						/* translators: %s: number of product sitemaps left out */
						esc_html__( '%s left out because they list products, which are not link targets here', 'dazont-ecom' ),
						number_format_i18n( $skip )
					);
				}
				$dropped = [];
				if ( ! empty( $s['products'] ) ) {
					/* translators: %s: number of product URLs dropped */
					$dropped[] = sprintf( esc_html__( '%s products', 'dazont-ecom' ), number_format_i18n( (int) $s['products'] ) );
				}
				if ( ! empty( $s['archives'] ) ) {
					/* translators: %s: number of archive URLs dropped */
					$dropped[] = sprintf( esc_html__( '%s tag/author/file URLs', 'dazont-ecom' ), number_format_i18n( (int) $s['archives'] ) );
				}
				if ( $dropped ) {
					$parts[] = sprintf(
						/* translators: %s: what was dropped, e.g. "1,204 products, 892 tag/author/file URLs" */
						esc_html__( 'dropped from the files read: %s', 'dazont-ecom' ),
						implode( ', ', $dropped )
					);
				}
				$pending = max( 0, (int) ( $s['children'] ?? 0 ) - $read );
				if ( $pending ) {
					$parts[] = sprintf(
						/* translators: %s: sub-sitemaps still to read */
						esc_html__( '%s still to read on the next daily check', 'dazont-ecom' ),
						number_format_i18n( $pending )
					);
				}
			}
			$parts[] = $kept < $found
				? sprintf(
					/* translators: 1: URLs found, 2: URLs kept */
					esc_html__( '%1$s URLs, %2$s kept as link candidates', 'dazont-ecom' ),
					number_format_i18n( $found ),
					number_format_i18n( $kept )
				)
				: sprintf(
					/* translators: %s: number of URLs */
					esc_html__( '%s URLs available for linking', 'dazont-ecom' ),
					number_format_i18n( $found )
				);
			if ( ! empty( $s['other'] ) ) {
				$parts[] = sprintf(
					/* translators: %s: number of translated URLs dropped */
					esc_html__( '%s URLs in other languages dropped — only the main language is worked on', 'dazont-ecom' ),
					number_format_i18n( (int) $s['other'] )
				);
			}
			$parts[] = sprintf(
				/* translators: %s: human time diff */
				esc_html__( 'read %s ago', 'dazont-ecom' ),
				esc_html( human_time_diff( (int) $s['checked'] ) )
			);

			$badge = '<span class="dze-key-badge is-set">&#10003; '
				. esc_html__( 'Sitemap connected', 'dazont-ecom' ) . ' — ' . implode( ' · ', $parts ) . '</span>';
			if ( '' !== $src ) {
				/* translators: %s: name of the plugin publishing the sitemap */
				$badge .= ' <span class="description">' . sprintf( esc_html__( 'found on its own from %s', 'dazont-ecom' ), esc_html( $src ) ) . '</span>';
			}
			return $badge;
		}
		if ( 'empty' === $s['status'] ) {
			return '<span class="dze-key-badge is-missing">' . esc_html__( 'Sitemap reachable, but no page URL found in it', 'dazont-ecom' ) . '</span>';
		}
		if ( 'error' === $s['status'] ) {
			return '<span class="dze-key-badge is-missing">' . esc_html__( 'Sitemap not reachable — check the URL', 'dazont-ecom' ) . '</span>';
		}
		return '<span class="dze-key-badge is-missing">' . esc_html__( 'No sitemap connected — categories only', 'dazont-ecom' ) . '</span>';
	}

	// =========================================================================
	// Sitemap notice
	// =========================================================================

	private const DISMISS_META = 'dze_cc_sitemap_notice_off';

	public function schedule_sitemap_check(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function cron_sitemap_check(): void {
		// One read at a time, whatever the cron does: two workers fetching our
		// own sitemap at once is exactly what we are trying to avoid.
		if ( get_transient( 'dze_cc_sitemap_lock' ) ) {
			return;
		}
		set_transient( 'dze_cc_sitemap_lock', 1, 5 * MINUTE_IN_SECONDS );
		self::sitemap_pages( true );
		delete_transient( 'dze_cc_sitemap_lock' );
	}

	/** "Not now" on the notice: silenced for that user until a reset. */
	public function maybe_dismiss_sitemap_notice(): void {
		if ( empty( $_GET['dze_cc_sitemap_off'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified right below.
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'dze_cc_sitemap_off' ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		wp_safe_redirect( remove_query_arg( [ 'dze_cc_sitemap_off', '_wpnonce' ] ) );
		exit;
	}

	/**
	 * Warns where it matters — the categories screen and the settings page —
	 * when the internal linking has no sitemap behind it, or when the one it
	 * has cannot be read. Never fetches: the cached state is enough.
	 */
	public function sitemap_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}
		// The settings tab lives on the Marketing Assistant page: no page, no
		// invitation to click through to it.
		if ( class_exists( 'DZE_Modules' ) && ! DZE_Modules::enabled( 'marketing_ai' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$here   = $screen && ( 'edit-product_cat' === $screen->id || false !== strpos( (string) $screen->id, DZE_Marketing_Ai::MENU_SLUG ) );
		if ( ! $here || self::links() < 1 ) {
			return;
		}
		$url   = self::sitemap_url();
		$state = (string) ( self::sitemap_cached()['status'] ?? '' );
		if ( '' !== $url && ( 'ok' === $state || '' === $state ) ) {
			return; // Connected, or not read yet — no reason to shout.
		}
		$tab   = add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG, 'tab' => 'categories' ], admin_url( 'admin.php' ) );
		$hide  = wp_nonce_url( add_query_arg( 'dze_cc_sitemap_off', 1 ), 'dze_cc_sitemap_off' );
		$body  = '' === $url
			? esc_html__( 'No sitemap found on this site, so category descriptions can only link to other categories. Point the plugin at your sitemap to let them link to your pages and blog posts too.', 'dazont-ecom' )
			: sprintf(
				/* translators: %s: sitemap URL */
				esc_html__( 'The sitemap at %s could not be read, so category descriptions are linking to other categories only.', 'dazont-ecom' ),
				'<code>' . esc_html( $url ) . '</code>'
			);
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Dazont Ecom — internal linking', 'dazont-ecom' ) . '</strong><br />'
			. $body // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
			. '</p><p><a class="button button-primary" href="' . esc_url( $tab ) . '">' . esc_html__( 'Connect the sitemap', 'dazont-ecom' ) . '</a> '
			. '<a class="button-link" href="' . esc_url( $hide ) . '">' . esc_html__( 'Not now', 'dazont-ecom' ) . '</a></p></div>';
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
		$kw    = self::keyword_pools( $term_id, '', false );
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
		// Something waiting on this category, said quietly but said.
		$job = class_exists( 'DZE_Queue' ) ? DZE_Queue::pending_for( $term_id ) : [];
		if ( $job ) {
			$note = 'review' === $job['status']
				? [ __( 'to review', 'dazont-ecom' ), '#8a6d00' ]
				: [ __( 'writing…', 'dazont-ecom' ), '#2271b1' ];
			$label .= '<br /><span style="color:' . esc_attr( $note[1] ) . ';font-size:11px;">&#9679; ' . esc_html( $note[0] ) . '</span>';
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

	/**
	 * The writer itself. $editor names the textarea the result is written to:
	 * empty for the popup (which brings its own), or 'description' on the
	 * category edit screen, where WordPress already shows one.
	 */
	public function render_panel( int $term_id, string $editor = '' ): void {
		$term  = get_term( $term_id, 'product_cat' );
		$kw    = self::keyword_pools( $term_id, $term ? $term->name : '' );
		$links = self::link_pool( $term_id );
		$break = self::link_pool_breakdown( $term_id );
		$in    = self::links_in_description( $term_id );
		$desc  = ( $term && ! is_wp_error( $term ) ) ? (string) $term->description : '';
		$words = str_word_count( wp_strip_all_tags( $desc ) );
		$has   = '' !== trim( $desc );
		$own   = '' === $editor; // popup: the panel owns its editor and saves itself.
		$imp   = class_exists( 'DZE_Keywords' ) && ( ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( 'sourcing' ) );
		$size  = self::size_for( $term_id );
		?>
		<?php
		// url => page name, so the link list can show what each link points to
		// and flag an anchor that does not name it.
		$names = [];
		foreach ( $links as $l ) {
			$names[ untrailingslashit( $l['url'] ) ] = $l['label'];
		}
		?>
		<div class="dze-cc-box" data-term="<?php echo (int) $term_id; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>" data-pool="<?php echo esc_attr( (string) wp_json_encode( $names ) ); ?>" data-qnonce="<?php echo esc_attr( class_exists( 'DZE_Queue' ) ? wp_create_nonce( DZE_Queue::NONCE ) : '' ); ?>"<?php echo $own ? '' : ' data-editor="' . esc_attr( $editor ) . '"'; ?>>
			<p class="description" style="margin-top:0;">
				<?php if ( $has ) : ?>
					<?php
					printf(
						/* translators: 1: word count, 2: links currently in the description */
						esc_html__( 'Current description: %1$s words, %2$s links. Edit it below, or rewrite it from scratch.', 'dazont-ecom' ),
						'<strong>' . (int) $words . '</strong>',
						'<strong>' . (int) $in . '</strong>'
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'This category has no description yet.', 'dazont-ecom' ); ?>
				<?php endif; ?>
				<button type="button" class="button-link dze-cc-ltoggle" style="display:none;"></button>
				<br />
				<?php
				printf(
					/* translators: 1: target word count, 2: target link count, 3: products in the whole branch, 4: direct sub-categories, 5: sub-categories at any depth */
					esc_html__( 'Target for this category: %1$s words and %2$s links — %3$s products behind it, %4$s sub-categories (%5$s counting every level).', 'dazont-ecom' ),
					'<strong>' . (int) $size['words'] . '</strong>' . ( $size['auto_words'] ? '' : '*' ),
					'<strong>' . (int) $size['links'] . '</strong>' . ( $size['auto_links'] ? '' : '*' ),
					'<strong>' . (int) $size['products'] . '</strong>',
					(int) $size['subs'],
					(int) $size['branch']
				);
				if ( ! $size['auto_words'] || ! $size['auto_links'] ) {
					echo ' <span class="description">' . esc_html__( '* fixed in the settings, not worked out from the category.', 'dazont-ecom' ) . '</span>';
				}
				?>
			</p>
			<?php // Filled from the editor content, so it always matches what is on screen. ?>
			<ul class="dze-cc-linklist" style="display:none;"></ul>

			<?php // Before / after, opened on its own once something has been generated. ?>
			<div class="dze-cc-diffwrap" style="display:none;">
				<p style="margin:0 0 6px;">
					<strong><?php esc_html_e( 'Before / after', 'dazont-ecom' ); ?></strong>
					<button type="button" class="button-link dze-cc-difftoggle"><?php esc_html_e( 'hide', 'dazont-ecom' ); ?></button>
					<span class="dze-cc-diffwords description"></span>
				</p>
				<div class="dze-cc-diff"></div>
			</div>

			<?php
			// A translation is not where a description is written: the original
			// is, and WPML carries it over.
			$deflang = self::default_lang();
			$mylang  = self::lang_code( $term_id );
			if ( '' !== $deflang && '' !== $mylang && $mylang !== $deflang ) :
				$origin = (int) apply_filters( 'wpml_object_id', $term_id, 'product_cat', false, $deflang );
				?>
				<div class="dze-cc-warn">
					<p><strong><?php
					printf(
						/* translators: %s: language code of the category */
						esc_html__( 'This is the %s translation of a category.', 'dazont-ecom' ),
						esc_html( strtoupper( $mylang ) )
					);
					?></strong></p>
					<p><?php esc_html_e( 'Descriptions are written on the main-language category and carried over by WPML, so the queries, the links and the text all stay in one language. Writing here would leave this translation on its own.', 'dazont-ecom' ); ?></p>
					<?php if ( $origin && $origin !== $term_id ) : ?>
						<p><a class="button button-small" href="<?php echo esc_url( (string) get_edit_term_link( $origin, 'product_cat' ) ); ?>"><?php esc_html_e( 'Open the main-language category', 'dazont-ecom' ); ?></a></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $kw['total'] ) : ?>
				<div class="dze-cc-warn">
					<p><strong><?php esc_html_e( 'No SEMrush file imported for this category.', 'dazont-ecom' ); ?></strong></p>
					<p><?php esc_html_e( 'The text can still be written, but its headings will come from the category name alone — no secondary query, no real buyer question. Import the export for this category to write on measured demand instead.', 'dazont-ecom' ); ?></p>
					<?php if ( $imp ) : ?>
						<p><button type="button" class="button button-small dze-cc-imtoggle"><?php esc_html_e( 'Import the SEMrush file', 'dazont-ecom' ); ?></button></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Enable the Sourcing Assistant module to import keyword files.', 'dazont-ecom' ); ?></p>
					<?php endif; ?>
				</div>
			<?php elseif ( ! $kw['questions'] ) : ?>
				<div class="dze-cc-warn">
					<p><?php
					printf(
						/* translators: %s: number of keywords in the set */
						esc_html__( '%s keywords in this set, but none of them reads as a question about this category — the description will have no buyer-question heading. Questions belonging to another subject are left out on purpose; a broad-match export usually carries some.', 'dazont-ecom' ),
						'<strong>' . (int) $kw['total'] . '</strong>'
					);
					?></p>
				</div>
			<?php endif; ?>

			<?php
			$pending = class_exists( 'DZE_Queue' ) ? DZE_Queue::pending_for( $term_id ) : [];
			if ( $pending && 'review' === $pending['status'] ) :
				?>
				<div class="dze-cc-warn" style="background:#eef6fc;border-left-color:#2271b1;">
					<p><strong><?php esc_html_e( 'A text is waiting for you on this category.', 'dazont-ecom' ); ?></strong>
					<?php echo esc_html( 'cat_links' === $pending['kind'] ? __( 'It is a linking pass.', 'dazont-ecom' ) : __( 'It is a description.', 'dazont-ecom' ) ); ?></p>
					<p>
						<button type="button" class="button button-small dze-cc-loadjob" data-job="<?php echo (int) $pending['id']; ?>">
							<?php esc_html_e( 'Load it here', 'dazont-ecom' ); ?>
						</button>
						<span class="description"><?php esc_html_e( 'It opens in the editor below with the before/after, and is only kept once you save.', 'dazont-ecom' ); ?></span>
					</p>
				</div>
			<?php elseif ( $pending ) : ?>
				<div class="dze-cc-warn" style="background:#eef6fc;border-left-color:#2271b1;">
					<p><?php esc_html_e( 'A run is under way on this category — it will appear here when it is done.', 'dazont-ecom' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			// Every run goes through the queue, so if that module is off the
			// buttons would do nothing at all. Say it instead of failing quietly.
			$q_on = class_exists( 'DZE_Queue' ) && ( ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( 'queue' ) );
			?>
			<?php if ( ! $q_on ) : ?>
				<div class="dze-cc-warn">
					<p><strong><?php esc_html_e( 'The Writing queue module is switched off.', 'dazont-ecom' ); ?></strong></p>
					<p><?php esc_html_e( 'Writing runs through it — in the background, so a long description cannot be cut off by the server. Switch it back on under Settings → Modules to generate anything here.', 'dazont-ecom' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<button type="button" class="button button-primary dze-cc-gen"<?php disabled( ! $q_on ); ?>>
					<?php echo $has ? esc_html__( 'Rewrite with AI', 'dazont-ecom' ) : esc_html__( 'Write the description', 'dazont-ecom' ); ?>
				</button>
				<?php if ( $has && $size['links'] > 0 ) : ?>
					<button type="button" class="button dze-cc-ltoggle-pick"<?php disabled( ! $q_on ); ?> title="<?php esc_attr_e( 'Keeps the text as it is and only adds internal links. Wording is touched only around an anchor, so it matches the page it points to.', 'dazont-ecom' ); ?>">
						<?php esc_html_e( 'Add internal links only', 'dazont-ecom' ); ?>
					</button>
				<?php endif; ?>
				<button type="button" class="dze-cx-icon dze-cc-ptoggle" title="<?php esc_attr_e( 'Edit the prompt', 'dazont-ecom' ); ?>">&#9998;</button>
				<button type="button" class="dze-cx-icon dze-cc-dtoggle" title="<?php esc_attr_e( 'See the queries and links used', 'dazont-ecom' ); ?>">&#9432;</button>
				<?php if ( $imp ) : ?>
					<button type="button" class="button button-small dze-cc-imtoggle"><?php esc_html_e( 'Import SEMrush file', 'dazont-ecom' ); ?></button>
				<?php endif; ?>
				<span class="dze-cc-status"></span>
			</p>

			<?php
			// Which links to place, decided before anything is written. Already
			// linked targets are shown ticked and disabled, the rest is ticked
			// down to the figure this category is worth.
			$linked = array_flip( array_map( 'untrailingslashit', self::linked_urls( $desc ) ) );
			?>
			<div class="dze-cc-picker" style="display:none;">
				<p class="description" style="margin:0 0 6px;">
					<?php esc_html_e( 'Ticked: the sub-categories, plus every page, category or article that actually talks about this one. The rest is listed but left unticked — tick it if you want it anyway. Nothing is written until you save.', 'dazont-ecom' ); ?>
				</p>
				<ul class="dze-cc-picklist">
					<?php foreach ( $links as $l ) :
						$key = untrailingslashit( $l['url'] );
						$got = isset( $linked[ $key ] );
						// Ticked when the candidate really belongs to this
						// category: its sub-categories always, and anything else
						// that shares its wording. The rest stays listed but
						// unticked — available, not assumed.
						$tick = ! $got && ! empty( $l['close'] );
						?>
						<li>
							<label>
								<input type="checkbox" class="dze-cc-pick" value="<?php echo esc_url( $l['url'] ); ?>"<?php checked( $got || $tick ); disabled( $got ); ?> />
								<span class="dze-cc-pick-name"><?php echo esc_html( $l['label'] ); ?></span>
								<span class="dze-cc-pick-kind"><?php echo esc_html( $l['kind'] ); ?></span>
								<?php if ( ! $got && empty( $l['close'] ) ) : ?>
									<span class="dze-cc-pick-loose"><?php esc_html_e( 'not obviously related', 'dazont-ecom' ); ?></span>
								<?php endif; ?>
								<?php if ( $got ) : ?>
									<span class="dze-cc-pick-done"><?php esc_html_e( 'already linked', 'dazont-ecom' ); ?></span>
								<?php endif; ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
				<p>
					<button type="button" class="button button-primary dze-cc-links"><?php esc_html_e( 'Place the selected links', 'dazont-ecom' ); ?></button>
					<button type="button" class="button-link dze-cc-pickall"><?php esc_html_e( 'Select all', 'dazont-ecom' ); ?></button>
					<button type="button" class="button-link dze-cc-picknone"><?php esc_html_e( 'Clear', 'dazont-ecom' ); ?></button>
					<span class="dze-cc-pickcount description"></span>
				</p>
			</div>

			<div class="dze-cc-data" style="display:none;">
				<p style="margin:0 0 4px;"><strong><?php esc_html_e( 'What this description will be built from', 'dazont-ecom' ); ?></strong></p>
				<p class="description" style="margin-top:0;">
					<?php
					printf(
						/* translators: 1: secondary queries, 2: questions */
						esc_html__( '%1$s secondary queries · %2$s buyer questions imported for this category.', 'dazont-ecom' ),
						'<strong>' . count( $kw['titles'] ) . '</strong>',
						'<strong>' . count( $kw['questions'] ) . '</strong>'
					);
					$parts = [];
					$names = [
						'parent category'  => __( 'parent', 'dazont-ecom' ),
						'sub-category'     => __( 'sub-categories', 'dazont-ecom' ),
						'related category' => __( 'sibling categories', 'dazont-ecom' ),
							'main category'    => __( 'main categories', 'dazont-ecom' ),
						'blog post'        => __( 'blog posts', 'dazont-ecom' ),
						'page'             => __( 'site pages', 'dazont-ecom' ),
						'sitemap page'     => __( 'other pages from the sitemap', 'dazont-ecom' ),
					];
					foreach ( $break as $kind => $n ) {
						$parts[] = (int) $n . ' ' . ( $names[ $kind ] ?? $kind );
					}
					echo '<br />';
					printf(
						/* translators: 1: number of pages it can link to, 2: breakdown, 3: max inserted */
						esc_html__( 'Link suggestions: %1$s URLs to choose from (%2$s); at most %3$s are inserted. The writer may only use URLs from that list — it never invents one.', 'dazont-ecom' ),
						'<strong>' . count( $links ) . '</strong>',
						esc_html( implode( ', ', $parts ) ),
						'<strong>' . (int) $size['links'] . '</strong>'
					);
					echo '<br />' . self::sitemap_status_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
					?>
				</p>
				<?php if ( $kw['titles'] ) : ?>
					<p><strong><?php esc_html_e( 'Secondary queries', 'dazont-ecom' ); ?></strong><br /><span class="description"><?php echo esc_html( implode( ' · ', array_slice( $kw['titles'], 0, 12 ) ) ); ?></span></p>
				<?php endif; ?>
				<?php if ( $kw['questions'] ) : ?>
					<p>
						<strong><?php esc_html_e( 'Buyer questions', 'dazont-ecom' ); ?></strong>
						<span class="description"><?php esc_html_e( '— read from the whole keyword set, then sifted so only what a buyer would ask this shop is kept', 'dazont-ecom' ); ?></span>
						<br /><span class="description"><?php echo esc_html( implode( ' · ', array_slice( $kw['questions'], 0, 10 ) ) ); ?></span>
					</p>
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

			<?php if ( $own ) : ?>
				<?php // The WordPress editor holds the description: existing text now, generated text after a run. ?>
				<textarea id="dze-cc-editor" class="dze-cc-editor"><?php echo esc_textarea( $desc ); ?></textarea>

				<p style="margin-top:10px;">
					<button type="button" class="button button-primary dze-cc-apply"><?php esc_html_e( 'Save the description', 'dazont-ecom' ); ?></button>
					<span class="description"><?php esc_html_e( 'Nothing is written to the category until you save. Close the window to leave it as it is.', 'dazont-ecom' ); ?></span>
				</p>
			<?php else : ?>
				<p style="margin-top:10px;">
					<span class="description"><?php esc_html_e( 'The result lands in the Description field above. Nothing is saved until you press Update.', 'dazont-ecom' ); ?></span>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Same writer, on the category edit screen, right under the Description
	 * field it feeds. Nothing is written to the term here: the generated text
	 * lands in that field and WooCommerce's own Update button saves it.
	 */
	public function edit_form_box( $term ): void {
		if ( ! $term || is_wp_error( $term ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="dze-cc-embed"><h2>' . esc_html__( 'Dazont Ecom — description writer', 'dazont-ecom' ) . '</h2>';
		$this->render_panel( (int) $term->term_id, 'description' );
		echo '</div>';
	}

	/**
	 * Bulk actions on Products → Categories. They queue, they never write: a
	 * batch of long descriptions is exactly what a browser request cannot wait
	 * for, and the queue exists for that.
	 */
	public function bulk_actions( array $actions ): array {
		if ( ! class_exists( 'DZE_Queue' ) ) {
			return $actions;
		}
		$actions['dze_cc_write'] = __( 'Dazont: write the description (review before saving)', 'dazont-ecom' );
		$actions['dze_cc_link']  = __( 'Dazont: add internal links (review before saving)', 'dazont-ecom' );
		return $actions;
	}

	public function handle_bulk( string $redirect, string $action, array $ids ): string {
		if ( ! class_exists( 'DZE_Queue' ) || ! in_array( $action, [ 'dze_cc_write', 'dze_cc_link' ], true ) ) {
			return $redirect;
		}
		$kind = 'dze_cc_write' === $action ? 'cat_desc' : 'cat_links';
		$n    = DZE_Queue::add( $kind, $ids, false );
		return add_query_arg( 'dze_cc_queued', $n, $redirect );
	}

	public function bulk_notice(): void {
		if ( ! isset( $_GET['dze_cc_queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			return;
		}
		$n   = absint( $_GET['dze_cc_queued'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$url = add_query_arg( [ 'page' => DZE_Queue::MENU_SLUG ], admin_url( 'admin.php' ) );
		echo '<div class="notice notice-success"><p>' . sprintf(
			/* translators: 1: number of categories queued, 2: link to the queue */
			esc_html( _n( '%1$s category sent to the writing queue. %2$s', '%1$s categories sent to the writing queue. %2$s', $n, 'dazont-ecom' ) ),
			(int) $n,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Follow it there', 'dazont-ecom' ) . '</a>'
		) . '</p></div>';
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
		wp_enqueue_editor(); // the panel edits the description in the WP editor.
		wp_enqueue_script( 'dze-catcontent', DZE_URL . 'admin/js/category-content.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-catcontent', 'dzeCatContent', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'kwNonce' => class_exists( 'DZE_Keywords' ) ? DZE_Keywords::nonce() : '',
			'home'    => home_url( '/' ),
			'i18n'    => [
				/* translators: %s: number of links in the description */
				'showLinks'   => __( '%s links', 'dazont-ecom' ),
				'external'    => __( 'external', 'dazont-ecom' ),
				'notNamed'    => __( 'The anchor does not name the page it points to.', 'dazont-ecom' ),
				/* translators: %s: number of pages ticked */
				'picked'      => __( '%s selected', 'dazont-ecom' ),
				'alreadyLinked' => __( 'already linked', 'dazont-ecom' ),
				'hide'        => __( 'hide', 'dazont-ecom' ),
				'before'      => __( 'Before', 'dazont-ecom' ),
				'after'       => __( 'After', 'dazont-ecom' ),
				/* translators: 1: word count, 2: link count */
				'wl'          => __( '%1$s words · %2$s links', 'dazont-ecom' ),
				'wasEmpty'    => __( 'This category had no description.', 'dazont-ecom' ),
				'show'        => __( 'show', 'dazont-ecom' ),
				/* translators: 1: words before, 2: words after */
				'diffWords'   => __( '%1$s words → %2$s words', 'dazont-ecom' ),
				'working'     => __( 'Writing', 'dazont-ecom' ),
				'linking'     => __( 'Placing the links…', 'dazont-ecom' ),
				/* translators: 1: links added, 2: links in the text now */
				'linked'      => __( '%1$s links added (%2$s in total) — check them, then save.', 'dazont-ecom' ),
				'linkedNone'  => __( 'No spot worth a link was found — the text is unchanged.', 'dazont-ecom' ),
				'reading'     => __( 'Reading the file…', 'dazont-ecom' ),
				'importing'   => __( 'Importing…', 'dazont-ecom' ),
				'imported'    => __( '%1$s added · %2$s updated', 'dazont-ecom' ),
				/* translators: 1: rows read from the file, 2: rows kept */
				'trimmed'     => __( '%1$s rows read, %2$s kept (every question, plus an even spread of the rest — never the top volumes only)', 'dazont-ecom' ),
				'colKeyword'  => __( 'Keyword', 'dazont-ecom' ),
				'colVolume'   => __( 'Volume', 'dazont-ecom' ),
				'colKd'       => __( 'KD', 'dazont-ecom' ),
				'colCpc'      => __( 'CPC', 'dazont-ecom' ),
				'colIntent'   => __( 'Intent', 'dazont-ecom' ),
				'colNone'     => __( '— none —', 'dazont-ecom' ),
				'error'       => __( 'Something went wrong.', 'dazont-ecom' ),
				'noAnswer'    => __( 'No answer from the server — the connection dropped before it replied.', 'dazont-ecom' ),
				/* translators: %s: HTTP status code */
				'timedOut'    => __( 'The server cut the request off (HTTP %s). A long description can take longer than your host allows: try again, or set a shorter Target length in Settings → Categories.', 'dazont-ecom' ),
				/* translators: %s: HTTP status code */
				'serverError' => __( 'The server answered with an error (HTTP %s). Look at your host\'s PHP error log for the reason.', 'dazont-ecom' ),
				'expired'     => __( 'This page has been open too long and the security token expired — reload it.', 'dazont-ecom' ),
				'queuedShort' => __( 'waiting for the writer…', 'dazont-ecom' ),
				'queued'      => __( 'Queued — it is being written in the background. %s', 'dazont-ecom' ),
				'queueLink'   => __( 'Follow it in the writing queue', 'dazont-ecom' ),
				'queueDup'    => __( 'This category is already waiting in the queue.', 'dazont-ecom' ),
				'tooLong'     => __( 'Given up after five minutes without an answer. The run may still have finished on the server: reopen this panel to see. If it keeps happening, lower the Target length in Settings → Categories.', 'dazont-ecom' ),
				'applied'     => __( 'Saved ✓', 'dazont-ecom' ),
				'review'      => __( 'Draft ready — edit it if needed, then save.', 'dazont-ecom' ),
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
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		try {
			$html = self::generate( $tid, $override );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'html' => $html ] );
	}

	/**
	 * Before and after, as two readable documents rather than a diff table.
	 *
	 * A word-level diff is precise and unreadable: what is wanted here is the
	 * old description on one side and the new one on the other, both rendered
	 * as they will appear, side by side.
	 */
	public function ajax_diff(): void {
		$this->guard();
		$tid  = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$html = isset( $_POST['html'] ) ? wp_kses_post( wp_unslash( $_POST['html'] ) ) : '';
		$term = $tid ? get_term( $tid, 'product_cat' ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		$old = (string) $term->description;
		wp_send_json_success( [
			'before' => '' !== trim( $old ) ? wp_kses_post( wpautop( $old ) ) : '',
			'after'  => wp_kses_post( wpautop( $html ) ),
			'words'  => [ str_word_count( wp_strip_all_tags( $old ) ), str_word_count( wp_strip_all_tags( $html ) ) ],
			'links'  => [ count( self::linked_urls( $old ) ), count( self::linked_urls( $html ) ) ],
		] );
	}

	/** Linking-only pass on the description currently in the editor. */
	public function ajax_links(): void {
		$this->guard();
		$tid  = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$html = isset( $_POST['html'] ) ? wp_kses_post( wp_unslash( $_POST['html'] ) ) : '';
		if ( ! $tid ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		if ( '' === trim( $html ) ) {
			// Nothing in the editor: fall back on what the category holds.
			$term = get_term( $tid, 'product_cat' );
			$html = ( $term && ! is_wp_error( $term ) ) ? (string) $term->description : '';
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$only = isset( $_POST['urls'] ) && is_array( $_POST['urls'] )
			? array_map( 'esc_url_raw', array_map( 'wp_unslash', $_POST['urls'] ) )
			: [];
		try {
			$res = self::add_links( $tid, $html, $only );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( $res );
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
		if ( class_exists( 'DZE_Queue' ) ) {
			DZE_Queue::settle( $tid ); // what was waiting for review has been dealt with.
		}
		$term = get_term( $tid, 'product_cat' );
		wp_send_json_success( [
			'words' => ( $term && ! is_wp_error( $term ) ) ? str_word_count( wp_strip_all_tags( (string) $term->description ) ) : 0,
			'links' => self::links_in_description( $tid ),
		] );
	}

	/**
	 * Reads the sitemap now and returns the fresh badge. The URL typed in the
	 * field is tested as it is, so the owner can check it before saving.
	 */
	public function ajax_sitemap_test(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a full index takes a moment.
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$url = esc_url_raw( trim( (string) wp_unslash( $_POST['url'] ?? '' ) ) );
		if ( '' !== $url && $url !== self::sitemap_url() ) {
			// Not the saved URL: read it once, without touching the cache.
			wp_send_json_success( [ 'html' => self::sitemap_status_html( self::read_sitemap( $url ) ) ] );
		}
		self::sitemap_pages( true ); // saved URL: refresh the cache too.
		wp_send_json_success( [ 'html' => self::sitemap_status_html() ] );
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
				<input type="hidden" name="<?php echo esc_attr( self::OPT ); ?>[form]" value="1" />
				<tr>
					<th scope="row"><label for="dze-cc-words"><?php esc_html_e( 'Target length', 'dazont-ecom' ); ?></label></th>
					<td>
						<input type="number" id="dze-cc-words" name="<?php echo esc_attr( self::OPT ); ?>[words]" class="small-text" min="0" max="2500" step="50" value="<?php echo (int) ( $s['words'] ?? 0 ) ?: ''; ?>" placeholder="<?php esc_attr_e( 'auto', 'dazont-ecom' ); ?>" /> <?php esc_html_e( 'words', 'dazont-ecom' ); ?>
						<p class="description"><?php esc_html_e( 'Leave empty and each category gets a length of its own, from 600 to 2500 words, worked out from the products in its whole branch — sub-categories of sub-categories included — and from how many sub-categories it has. A category page sitting on a large branch is a buying guide, not a paragraph. Fill it in to force the same length everywhere.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-cc-links"><?php esc_html_e( 'Internal links', 'dazont-ecom' ); ?></label></th>
					<td>
						<input type="number" id="dze-cc-links" name="<?php echo esc_attr( self::OPT ); ?>[links]" class="small-text" min="0" max="14" value="<?php echo (int) ( $s['links'] ?? 0 ) ?: ''; ?>" placeholder="<?php esc_attr_e( 'auto', 'dazont-ecom' ); ?>" />
						<label style="margin-left:12px;"><input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[links_off]" value="1" <?php checked( ! empty( $s['links_off'] ) ); ?> /> <?php esc_html_e( 'No internal linking at all', 'dazont-ecom' ); ?></label>
						<p class="description"><?php esc_html_e( 'Empty means one link per ~150 words, never fewer than there are sub-categories — a hub carries more links than a leaf. Individual products are never linked: the page already lists them.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-cc-model"><?php esc_html_e( 'Writing model', 'dazont-ecom' ); ?></label></th>
					<td>
						<?php
						$models = class_exists( 'DZE_Marketing_Ai' ) ? DZE_Marketing_Ai::available_models() : [];
						$cur    = (string) ( $s['model'] ?? '' );
						if ( '' !== $cur && ! array_key_exists( $cur, $models ) ) {
							$models = [ $cur => $cur ] + $models;
						}
						?>
						<select id="dze-cc-model" name="<?php echo esc_attr( self::OPT ); ?>[model]">
							<option value=""><?php esc_html_e( '— the shop default —', 'dazont-ecom' ); ?></option>
							<?php foreach ( $models as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $cur, $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'This is the speed lever, and it does not cost quality: a category description is buying-guide copy, which Sonnet writes about three times faster than Opus for the same result. Each section is one call, so the model choice shows directly in how long a run takes.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-cc-sitemap"><?php esc_html_e( 'Sitemap', 'dazont-ecom' ); ?></label></th>
					<td>
						<?php $auto = self::detect_sitemap(); ?>
						<input type="url" id="dze-cc-sitemap" name="<?php echo esc_attr( self::OPT ); ?>[sitemap]" class="regular-text" value="<?php echo esc_attr( self::sitemap_override() ); ?>" placeholder="<?php echo esc_attr( '' !== $auto['url'] ? $auto['url'] : home_url( '/wp-sitemap.xml' ) ); ?>" />
						<button type="button" class="button" id="dze-cc-sitemap-test"><?php esc_html_e( 'Test', 'dazont-ecom' ); ?></button>
						<p id="dze-cc-sitemap-status" style="margin:8px 0 0;"><?php echo self::sitemap_status_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?></p>
						<p style="margin:6px 0;">
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[sitemap_products]" value="1" <?php checked( ! empty( $s['sitemap_products'] ) ); ?> />
								<?php esc_html_e( 'Read the product sitemaps too', 'dazont-ecom' ); ?>
							</label>
							<span class="description"><?php esc_html_e( 'Off by default: a category page already lists its products, so linking to one from its description adds nothing — and those files are the bulk of a big sitemap.', 'dazont-ecom' ); ?></span>
						</p>
						<?php $st = self::sitemap_cached(); ?>
						<?php if ( is_array( $st ) && ( ! empty( $st['readlist'] ) || ! empty( $st['skiplist'] ) ) ) : ?>
							<details style="margin:6px 0;">
								<summary style="cursor:pointer;color:#2271b1;"><?php esc_html_e( 'What the sitemap contains, and what was taken from it', 'dazont-ecom' ); ?></summary>
								<p class="description" style="margin:6px 0 0;">
									<?php if ( ! empty( $st['indexlist'] ) ) : ?>
										<strong><?php esc_html_e( 'Every file in the index', 'dazont-ecom' ); ?></strong>
										<?php esc_html_e( '— those marked [skipped] were not downloaded.', 'dazont-ecom' ); ?><br />
										<?php echo esc_html( implode( ' · ', (array) $st['indexlist'] ) ); ?><br />
									<?php endif; ?>
									<?php if ( ! empty( $st['readlist'] ) ) : ?>
										<strong><?php esc_html_e( 'Read', 'dazont-ecom' ); ?></strong><br />
										<?php echo esc_html( implode( ' · ', (array) $st['readlist'] ) ); ?><br />
									<?php endif; ?>
									<?php if ( ! empty( $st['skiplist'] ) ) : ?>
										<strong><?php esc_html_e( 'Left out', 'dazont-ecom' ); ?></strong><br />
										<?php echo esc_html( implode( ' · ', (array) $st['skiplist'] ) ); ?><br />
									<?php endif; ?>
									<?php if ( ! empty( $st['sample'] ) ) : ?>
										<strong><?php esc_html_e( 'First URLs kept', 'dazont-ecom' ); ?></strong>
										<?php esc_html_e( '— check here what these pages actually are.', 'dazont-ecom' ); ?><br />
										<?php echo esc_html( implode( ' · ', array_map( static fn( $u ) => (string) wp_parse_url( $u, PHP_URL_PATH ), (array) $st['sample'] ) ) ); ?>
									<?php endif; ?>
								</p>
							</details>
						<?php endif; ?>
						<p class="description">
							<?php
							if ( '' !== $auto['url'] ) {
								printf(
									/* translators: 1: plugin publishing the sitemap, 2: sitemap URL */
									esc_html__( 'Leave this empty: the plugin picks up the sitemap %1$s publishes on its own (%2$s). Fill it in only to point somewhere else.', 'dazont-ecom' ),
									'<strong>' . esc_html( $auto['source'] ) . '</strong>',
									'<code>' . esc_html( $auto['url'] ) . '</code>'
								);
							} else {
								esc_html_e( 'No sitemap was found on this site — paste its address here.', 'dazont-ecom' );
							}
							?>
							<br /><?php esc_html_e( 'It adds your own pages (blog posts, guides, landing pages) to the link pool, on top of the categories. A sitemap index is followed one level, and it is re-read every 12 hours.', 'dazont-ecom' ); ?>
						</p>
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
		jQuery( function ( $ ) {
			$( '#dze-cc-restore' ).on( 'click', function () { $( '#dze-cc-prompt' ).val( '' ); } );
			$( '#dze-cc-sitemap-test' ).on( 'click', function () {
				var $b = $( this ).prop( 'disabled', true );
				$( '#dze-cc-sitemap-status' ).html( '<span class="dze-cx-spin"></span>' );
				$.post( window.ajaxurl, {
					action: 'dze_cc_sitemap_test',
					nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>',
					url: $( '#dze-cc-sitemap' ).val()
				} )
					.done( function ( res ) {
						$b.prop( 'disabled', false );
						$( '#dze-cc-sitemap-status' ).html( ( res && res.success ) ? res.data.html : '' );
					} )
					.fail( function () { $b.prop( 'disabled', false ); } );
			} );
		} );
		</script>
		<?php
	}
}
