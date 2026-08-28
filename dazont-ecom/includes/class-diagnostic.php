<?php
/**
 * The shop read against its own standards.
 *
 * @package Dazont_Ecom
 */

defined( 'ABSPATH' ) || exit;

/**
 * What is missing, product by product, category by category, article by article.
 *
 * The plugin can write a description, make a photograph, add a link. What it
 * could not do was answer the question that comes first: WHERE. A shop of a
 * thousand products has no memory of which of them is short of a paragraph and
 * which has one photograph in its gallery, and a catalogue audited once in a
 * spreadsheet is out of date the week after.
 *
 * So this reads the shop and says so. Nothing here writes, generates, spends
 * or decides: it is a to-do list, and every line of it points at the screen
 * that already knows how to fix that one thing.
 *
 * The criteria are not a list of this plugin's opinions. The structural ones
 * are the shop's — how many words a description needs, how many photographs a
 * gallery needs — and every other one is READ FROM THE PROMPT REGISTRY: each
 * prompt already declares what it writes and where, so "the custom block 2 is
 * empty on 340 products" is a question the shop can already answer about
 * itself. Add a prompt and its criterion appears; disable it and the criterion
 * goes with it. There is no second list to keep in step.
 *
 * The reading is done in cron and kept. A screen that counted a thousand
 * products on every load would be a screen nobody opens twice.
 */
final class DZE_Diagnostic {

	public const MENU_SLUG  = 'dazont-ecom-diagnostic';
	public const OPT        = 'dze_diagnostic';
	public const OPT_CENSUS = 'dze_diagnostic_census';
	public const OPT_LISTS  = 'dze_diagnostic_lists';
	private const NONCE     = 'dze_diag';
	private const CRON      = 'dze_diagnostic_scan';
	private const LOCK      = 'dze_diag_lock';

	/** Objects listed per criterion. The COUNT is always exact; the list is what a screen can show. */
	private const KEEP_IDS = 1000;

	/** Rows on one page of a list. */
	private const PER_PAGE = 50;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( self::CRON, [ __CLASS__, 'scan' ] );
		if ( ! is_admin() ) {
			// A customer never pays for this: it is an admin screen and a
			// nightly reading, and neither belongs in a shop page's request.
			return;
		}
		add_action( 'admin_menu', [ $this, 'register_menu' ], 12 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ __CLASS__, 'schedule' ] );
		add_action( 'wp_ajax_dze_diag_scan', [ __CLASS__, 'ajax_scan' ] );
	}

	/** Once a day, and never twice. */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Switched off, it stands its nightly reading down.
	 *
	 * A module that stops being booted still leaves its cron event behind, and
	 * an event firing into the void is a trace a disabled module has no
	 * business leaving.
	 */
	public static function disable(): void {
		$ts = wp_next_scheduled( self::CRON );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON );
			$ts = wp_next_scheduled( self::CRON );
		}
	}

	// =========================================================================
	// The standards
	// =========================================================================

	public static function settings(): array {
		$s = get_option( self::OPT, [] );
		return is_array( $s ) ? $s : [];
	}

	/**
	 * What the shop can be read against, and what each answer IS.
	 *
	 * One menu rather than two: "Product · description" says the scope and the
	 * field in the words the owner already uses, and nothing has to be kept in
	 * step between them. A field answers with TEXT or with a COUNT, and that
	 * is what decides which rules can be asked of it.
	 *
	 * @return array<string,array{scope:string,label:string,kind:string,key?:bool}>
	 */
	public static function fields(): array {
		return [
			'product.title'             => [ 'scope' => 'product',  'kind' => 'text',  'label' => __( 'Product · title', 'dazont-ecom' ) ],
			'product.description'       => [ 'scope' => 'product',  'kind' => 'text',  'label' => __( 'Product · description', 'dazont-ecom' ) ],
			'product.short_description' => [ 'scope' => 'product',  'kind' => 'text',  'label' => __( 'Product · short description', 'dazont-ecom' ) ],
			'product.seo_title'         => [ 'scope' => 'product',  'kind' => 'text',  'label' => __( 'Product · SEO title', 'dazont-ecom' ) ],
			'product.seo_desc'          => [ 'scope' => 'product',  'kind' => 'text',  'label' => __( 'Product · SEO description', 'dazont-ecom' ) ],
			'product.meta'              => [ 'scope' => 'product',  'kind' => 'text',  'label' => __( 'Product · custom field', 'dazont-ecom' ), 'key' => true ],
			'product.main_image'        => [ 'scope' => 'product',  'kind' => 'count', 'label' => __( 'Product · main photograph', 'dazont-ecom' ) ],
			'product.gallery'           => [ 'scope' => 'product',  'kind' => 'count', 'label' => __( 'Product · gallery photographs', 'dazont-ecom' ) ],
			'product.image_meta'        => [ 'scope' => 'product',  'kind' => 'count', 'label' => __( 'Product · photograph in a custom field', 'dazont-ecom' ), 'key' => true ],
			'product.links'             => [ 'scope' => 'product',  'kind' => 'count', 'label' => __( 'Product · links in the description', 'dazont-ecom' ) ],
			'category.description'      => [ 'scope' => 'category', 'kind' => 'text',  'label' => __( 'Category · description', 'dazont-ecom' ) ],
			'category.links'            => [ 'scope' => 'category', 'kind' => 'count', 'label' => __( 'Category · internal links', 'dazont-ecom' ) ],
			'post.links'                => [ 'scope' => 'post',     'kind' => 'count', 'label' => __( 'Article or page · internal links', 'dazont-ecom' ) ],
		];
	}

	/** The three rules a field can be held to. */
	public static function tests(): array {
		return [
			'empty'     => __( 'is empty', 'dazont-ecom' ),
			'min_words' => __( 'holds fewer than N words', 'dazont-ecom' ),
			'min_count' => __( 'there are fewer than N', 'dazont-ecom' ),
		];
	}

	/**
	 * The criteria as they ship.
	 *
	 * Rows, not code — the same shape the shop edits, so "restore the default"
	 * is putting these back and nothing else. What is shipped is what a shop
	 * of any kind would ask first; everything past that is the owner's.
	 */
	public static function default_rows(): array {
		return [
			[ 'id' => 'prod_desc',    'label' => __( 'Description too short', 'dazont-ecom' ),        'field' => 'product.description',       'test' => 'min_words', 'value' => 120, 'key' => '', 'on' => 1 ],
			[ 'id' => 'prod_short',   'label' => __( 'No short description', 'dazont-ecom' ),          'field' => 'product.short_description', 'test' => 'empty',     'value' => 0,   'key' => '', 'on' => 1 ],
			[ 'id' => 'prod_main',    'label' => __( 'No main photograph', 'dazont-ecom' ),            'field' => 'product.main_image',        'test' => 'empty',     'value' => 0,   'key' => '', 'on' => 1 ],
			[ 'id' => 'prod_gallery', 'label' => __( 'Gallery too thin', 'dazont-ecom' ),              'field' => 'product.gallery',           'test' => 'min_count', 'value' => 3,   'key' => '', 'on' => 1 ],
			[ 'id' => 'cat_desc',     'label' => __( 'Category description too short', 'dazont-ecom' ),'field' => 'category.description',      'test' => 'min_words', 'value' => 150, 'key' => '', 'on' => 1 ],
			[ 'id' => 'cat_links',    'label' => __( 'Category points at too little', 'dazont-ecom' ), 'field' => 'category.links',            'test' => 'min_count', 'value' => 2,   'key' => '', 'on' => 1 ],
			[ 'id' => 'post_links',   'label' => __( 'Article under its link target', 'dazont-ecom' ), 'field' => 'post.links',                'test' => 'min_count', 'value' => 0,   'key' => '', 'on' => 1 ],
		];
	}

	/** The criteria in force: the shop's own, or the shipped ones. */
	public static function rows(): array {
		$saved = self::settings()['rows'] ?? null;
		$rows  = is_array( $saved ) ? self::clean_rows( $saved ) : [];
		return $rows ?: self::default_rows();
	}

	/** Rows as a form posted them, made safe and complete. */
	public static function clean_rows( array $in ): array {
		$fields = self::fields();
		$tests  = self::tests();
		$out    = [];
		$seen   = [];
		foreach ( $in as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = trim( sanitize_text_field( (string) ( $row['label'] ?? '' ) ) );
			$field = (string) ( $row['field'] ?? '' );
			if ( '' === $label || ! isset( $fields[ $field ] ) ) {
				continue; // a criterion with no name, or reading nothing, is not one.
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = sanitize_key( sanitize_title( $label ) ) ?: ( 'c' . ( count( $out ) + 1 ) );
			}
			while ( isset( $seen[ $id ] ) ) {
				$id .= '2';
			}
			$seen[ $id ] = true;
			$test = (string) ( $row['test'] ?? '' );
			$out[] = [
				'id'    => $id,
				'label' => mb_substr( $label, 0, 80 ),
				'field' => $field,
				'test'  => isset( $tests[ $test ] ) ? $test : 'empty',
				'value' => max( 0, min( 5000, (int) ( $row['value'] ?? 0 ) ) ),
				'key'   => ! empty( $fields[ $field ]['key'] ) ? sanitize_text_field( (string) ( $row['key'] ?? '' ) ) : '',
				'on'    => empty( $row['on'] ) ? 0 : 1,
			];
		}
		return $out;
	}

	/**
	 * Every criterion the shop is read against: its own, then the ones its
	 * PROMPTS answer for.
	 *
	 * @return array<string,array{scope:string,label:string,why:string,fix:string}>
	 */
	public static function checks(): array {
		$fields = self::fields();
		$out    = [];
		foreach ( self::rows() as $row ) {
			if ( empty( $row['on'] ) ) {
				continue;
			}
			$field = $fields[ $row['field'] ];
			$out[ (string) $row['id'] ] = [
				'scope' => (string) $field['scope'],
				'label' => (string) $row['label'],
				'why'   => self::rule_said( $row, $field ),
				'fix'   => '',
				'row'   => $row,
			];
		}

		// The shop's own prompts, each answering for what it writes. Only the
		// ones that write somewhere a page can be EMPTY: a prompt that writes
		// the description is already answered by a criterion above, and
		// counting it twice would make one product look like two jobs.
		if ( class_exists( 'DZE_Content' ) && DZE_Modules::enabled( 'content' ) ) {
			foreach ( DZE_Content::registry() as $prompt ) {
				$id = (string) ( $prompt['id'] ?? '' );
				if ( '' === $id || empty( $prompt['enabled'] ) || 'text' !== ( $prompt['type'] ?? 'text' ) ) {
					continue;
				}
				$dest = DZE_Content::dest_for( $id );
				$name = (string) ( $prompt['name'] ?? $id );
				if ( in_array( (string) $dest['type'], [ 'meta', 'seo_title', 'seo_desc' ], true ) ) {
					$key = 'meta' === $dest['type']
						? (string) ( $dest['key'] ?? '' )
						: (string) ( DZE_Content::seo_keys()[ 'seo_title' === $dest['type'] ? 'title' : 'desc' ] ?? '' );
					$out[ 'field_' . $id ] = [
						'scope' => 'product',
						'label' => sprintf(
							/* translators: %s: the name of one of the shop's own prompts */
							__( '%s: empty', 'dazont-ecom' ),
							$name
						),
						'why'   => __( 'One of your own prompts writes here, and this product has nothing in it.', 'dazont-ecom' ),
						'fix'   => $name,
						'row'   => [ 'field' => 'product.meta', 'test' => 'empty', 'value' => 0, 'key' => $key ],
					];
				}
				$img = DZE_Content::companion_meta( $id );
				if ( '' !== $img ) {
					$out[ 'shot_' . $id ] = [
						'scope' => 'product',
						'label' => sprintf(
							/* translators: %s: the name of one of the shop's own prompts */
							__( '%s: no photograph', 'dazont-ecom' ),
							$name
						),
						'why'   => __( 'That block is written against a photograph, and there is none on this product.', 'dazont-ecom' ),
						'fix'   => $name,
						'row'   => [ 'field' => 'product.image_meta', 'test' => 'empty', 'value' => 0, 'key' => $img ],
					];
				}
			}
		}
		return $out;
	}

	/** One criterion said in words, for the screen. */
	private static function rule_said( array $row, array $field ): string {
		$what = (string) $field['label'];
		if ( 'min_words' === $row['test'] ) {
			return sprintf(
				/* translators: 1: what is read, 2: how many words */
				__( '%1$s holds fewer than %2$d words.', 'dazont-ecom' ),
				$what,
				(int) $row['value']
			);
		}
		if ( 'min_count' === $row['test'] ) {
			if ( 0 === (int) $row['value'] && 'post.links' === $row['field'] ) {
				return __( 'The article carries fewer links than its own length calls for — the figure the linking pass works out, not one set here.', 'dazont-ecom' );
			}
			return sprintf(
				/* translators: 1: what is counted, 2: how many there should be */
				__( '%1$s: fewer than %2$d.', 'dazont-ecom' ),
				$what,
				(int) $row['value']
			);
		}
		return sprintf(
			/* translators: %s: what is read */
			__( '%s is empty.', 'dazont-ecom' ),
			$what
		);
	}

	// =========================================================================
	// The reading
	// =========================================================================

	/**
	 * The last reading: when, how much was read, and how many fall short.
	 *
	 * Deliberately small. The menu badge reads it on every admin page, and a
	 * row holding eleven thousand ids is not something to load to print one
	 * number — the lists live in an option of their own, read only by the
	 * screen that shows them.
	 */
	public static function census(): array {
		$c = get_option( self::OPT_CENSUS, [] );
		return is_array( $c ) && isset( $c['checks'] ) ? $c : [ 'at' => 0, 'seen' => [], 'checks' => [] ];
	}

	/** The objects behind the counts. Heavy, and asked for only on the list screen. */
	public static function lists(): array {
		$l = get_option( self::OPT_LISTS, [] );
		return is_array( $l ) ? $l : [];
	}

	/**
	 * Reads the whole shop once.
	 *
	 * In cron, or on an explicit click. Never on a page somebody is waiting
	 * for otherwise: a thousand products is a thousand descriptions to weigh,
	 * and that is a job, not a page load.
	 */
	public static function scan(): array {
		if ( get_transient( self::LOCK ) ) {
			return self::census();
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$checks = self::checks();
		$hits   = [];
		$seen   = [ 'product' => 0, 'category' => 0, 'post' => 0 ];
		try {
			self::scan_products( $checks, $hits, $seen );
			self::scan_categories( $checks, $hits, $seen );
			self::scan_posts( $checks, $hits, $seen );
		} finally {
			delete_transient( self::LOCK );
		}
		$out   = [ 'at' => time(), 'seen' => $seen, 'checks' => [] ];
		$lists = [];
		foreach ( $checks as $id => $meta ) {
			$ids                  = array_values( array_unique( (array) ( $hits[ $id ] ?? [] ) ) );
			$out['checks'][ $id ] = count( $ids );
			$lists[ $id ]         = array_slice( $ids, 0, self::KEEP_IDS );
		}
		update_option( self::OPT_CENSUS, $out, false );
		update_option( self::OPT_LISTS, $lists, false );
		return $out;
	}

	/** @param array<string,int[]> $hits */
	private static function scan_products( array $checks, array &$hits, array &$seen ): void {
		$wanted = array_filter( $checks, static fn( array $c ): bool => 'product' === $c['scope'] );
		if ( ! $wanted || ! post_type_exists( 'product' ) ) {
			return;
		}
		$page = 1;
		do {
			$q = new WP_Query( [
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => 200,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => true,
				'suppress_filters'       => true,
			] );
			foreach ( $q->posts as $post ) {
				$seen['product']++;
				foreach ( $wanted as $id => $check ) {
					if ( self::fails( (array) $check['row'], 'product', $post ) ) {
						$hits[ $id ][] = (int) $post->ID;
					}
				}
			}
			$page++;
		} while ( $q->post_count > 0 );
	}

	/**
	 * How many words a text holds.
	 *
	 * Counted on letters rather than with str_word_count(), which reads a
	 * French description as a shorter English one: "matériel léger" is two
	 * words, and the C function counts the accented halves as their own.
	 */
	public static function words( string $html ): int {
		return (int) preg_match_all( '/\p{L}+/u', wp_strip_all_tags( $html ) );
	}

	/**
	 * What one page actually holds, for one field.
	 *
	 * @param mixed $object A WP_Post, a term, or the linking pass's own row.
	 * @return array{text:string,count:int}
	 */
	private static function measure( string $field, string $scope, $object, string $key ): array {
		$out = [ 'text' => '', 'count' => 0 ];
		if ( 'product' === $scope && $object instanceof WP_Post ) {
			$pid = (int) $object->ID;
			switch ( $field ) {
				case 'product.title':
					$out['text'] = (string) $object->post_title;
					break;
				case 'product.description':
					$out['text'] = (string) $object->post_content;
					break;
				case 'product.short_description':
					$out['text'] = (string) $object->post_excerpt;
					break;
				case 'product.seo_title':
				case 'product.seo_desc':
					$keys        = class_exists( 'DZE_Content' ) ? DZE_Content::seo_keys() : [];
					$seo         = (string) ( $keys[ 'product.seo_title' === $field ? 'title' : 'desc' ] ?? '' );
					$out['text'] = '' !== $seo ? (string) get_post_meta( $pid, $seo, true ) : '';
					break;
				case 'product.meta':
					$out['text'] = '' !== $key ? (string) get_post_meta( $pid, $key, true ) : '';
					break;
				case 'product.image_meta':
					$out['count'] = ( '' !== $key && (int) get_post_meta( $pid, $key, true ) > 0 ) ? 1 : 0;
					break;
				case 'product.main_image':
					$out['count'] = (int) get_post_thumbnail_id( $pid ) ? 1 : 0;
					break;
				case 'product.gallery':
					$gal          = array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $pid, '_product_image_gallery', true ) ) ) );
					$out['count'] = count( $gal );
					break;
				case 'product.links':
					$out['count'] = (int) preg_match_all( '/<a\s[^>]*href=/i', (string) $object->post_content );
					break;
			}
			return $out;
		}
		if ( 'category' === $scope && is_object( $object ) ) {
			$text = (string) ( $object->description ?? '' );
			if ( 'category.links' === $field ) {
				$out['count'] = (int) preg_match_all( '/<a\s[^>]*href=/i', $text );
			} else {
				$out['text'] = $text;
			}
			return $out;
		}
		if ( 'post' === $scope && is_array( $object ) ) {
			$out['count'] = (int) ( $object['out'] ?? 0 );
			$out['text']  = '';
		}
		return $out;
	}

	/** Whether a field answers with text or with a number. */
	private static function kind_of( string $field ): string {
		return (string) ( self::fields()[ $field ]['kind'] ?? 'text' );
	}

	/**
	 * Whether one page falls short of one criterion.
	 *
	 * @param mixed $object
	 */
	private static function fails( array $row, string $scope, $object ): bool {
		$field = (string) ( $row['field'] ?? '' );
		$test  = (string) ( $row['test'] ?? 'empty' );
		$want  = (int) ( $row['value'] ?? 0 );
		$m     = self::measure( $field, $scope, $object, (string) ( $row['key'] ?? '' ) );

		if ( 'min_words' === $test ) {
			return self::words( $m['text'] ) < $want;
		}
		if ( 'min_count' === $test ) {
			// An article is held to the figure the linking pass works out from
			// its own length — asked here a second way, the two screens would
			// disagree about the same article. A number typed in the row wins
			// when there is one.
			if ( 'post.links' === $field && $want <= 0 ) {
				$target = is_array( $object ) ? (int) ( $object['target'] ?? 0 ) : 0;
				return $target > 0 && $m['count'] < $target;
			}
			return $m['count'] < $want;
		}
		return 'count' === self::kind_of( $field )
			? $m['count'] <= 0
			: '' === trim( wp_strip_all_tags( $m['text'] ) );
	}

	/** @param array<string,int[]> $hits */
	private static function scan_categories( array $checks, array &$hits, array &$seen ): void {
		$wanted = array_filter( $checks, static fn( array $c ): bool => 'category' === $c['scope'] );
		if ( ! $wanted ) {
			return;
		}
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			$seen['category']++;
			foreach ( $wanted as $id => $check ) {
				if ( self::fails( (array) $check['row'], 'category', $term ) ) {
					$hits[ $id ][] = (int) $term->term_id;
				}
			}
		}
	}

	/**
	 * Articles and pages, read by the pass that already knows how.
	 *
	 * Its own census answers what a text that long may carry and what it
	 * carries today — asking that question a second way here is how two
	 * screens end up disagreeing about the same article.
	 *
	 * @param array<string,int[]> $hits
	 */
	private static function scan_posts( array $checks, array &$hits, array &$seen ): void {
		$wanted = array_filter( $checks, static fn( array $c ): bool => 'post' === $c['scope'] );
		if ( ! $wanted || ! class_exists( 'DZE_Post_Links' ) ) {
			return;
		}
		foreach ( DZE_Post_Links::census( true ) as $pid => $row ) {
			$seen['post']++;
			foreach ( $wanted as $id => $check ) {
				if ( self::fails( (array) $check['row'], 'post', $row ) ) {
					$hits[ $id ][] = (int) $pid;
				}
			}
		}
	}

	// =========================================================================
	// The screen
	// =========================================================================

	public function register_menu(): void {
		$waiting = self::waiting();
		$label   = __( 'Diagnostic', 'dazont-ecom' );
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			$label,
			$waiting
				? $label . ' <span class="update-plugins count-' . (int) $waiting . '"><span class="plugin-count">' . (int) $waiting . '</span></span>'
				: $label,
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/** How many things the shop is short of, all criteria together. */
	public static function waiting(): int {
		$n = 0;
		foreach ( (array) ( self::census()['checks'] ?? [] ) as $n_one ) {
			$n += (int) $n_one;
		}
		return $n;
	}

	public function register_settings(): void {
		register_setting( 'dze_diagnostic_options', self::OPT, [
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
			'autoload'          => false,
		] );
	}

	/**
	 * The standards, saved.
	 *
	 * WordPress calls a sanitizer with null when the page did not carry this
	 * option at all: that is another form being saved, and the answer is what
	 * is stored, never the defaults.
	 */
	public static function sanitize( $in ): array {
		if ( ! is_array( $in ) ) {
			return self::settings();
		}
		$out = self::settings();
		// The form carries a marker even when every criterion was deleted, or
		// emptying the list would read as "this form was not about criteria"
		// and never take — the same trap the emails of a promotion fell into.
		if ( ! empty( $in['rows_shown'] ) ) {
			$out['rows'] = self::clean_rows( (array) ( $in['rows'] ?? [] ) );
		}
		return $out;
	}

	public static function ajax_scan(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$c = self::scan();
		wp_send_json_success( [
			'waiting' => self::waiting(),
			'at'      => (int) ( $c['at'] ?? 0 ),
		] );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$check = isset( $_GET['check'] ) ? sanitize_key( wp_unslash( $_GET['check'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		echo '<div class="wrap dze-wrap">';
		if ( '' !== $check && isset( self::checks()[ $check ] ) ) {
			$this->render_list( $check );
		} else {
			$this->render_overview();
		}
		echo '</div>';
	}

	private function render_overview(): void {
		$census = self::census();
		$checks = self::checks();
		$seen   = (array) ( $census['seen'] ?? [] );
		$at     = (int) ( $census['at'] ?? 0 );

		echo '<h1>' . esc_html__( 'Diagnostic', 'dazont-ecom' ) . '</h1>';
		echo '<p class="description" style="max-width:760px;">'
			. esc_html__( 'What the shop is short of, read against your own standards. Nothing here writes anything or spends anything: each line points at the screen that fixes that one thing.', 'dazont-ecom' )
			. '</p>';

		echo '<p style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">';
		echo '<button type="button" class="button button-primary" id="dze-diag-scan">' . esc_html__( 'Read the shop again', 'dazont-ecom' ) . '</button>';
		echo '<span id="dze-diag-msg" class="description">';
		if ( $at ) {
			printf(
				/* translators: 1: how long ago, 2: products, 3: categories, 4: articles */
				esc_html__( 'Read %1$s ago — %2$d products, %3$d categories, %4$d articles and pages.', 'dazont-ecom' ),
				esc_html( human_time_diff( $at ) ),
				(int) ( $seen['product'] ?? 0 ),
				(int) ( $seen['category'] ?? 0 ),
				(int) ( $seen['post'] ?? 0 )
			);
		} else {
			esc_html_e( 'Never read yet — press the button, or wait for tonight.', 'dazont-ecom' );
		}
		echo '</span></p>';

		echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr>'
			. '<th>' . esc_html__( 'What is missing', 'dazont-ecom' ) . '</th>'
			. '<th style="width:120px;">' . esc_html__( 'Where', 'dazont-ecom' ) . '</th>'
			. '<th style="width:110px;text-align:right;">' . esc_html__( 'How many', 'dazont-ecom' ) . '</th>'
			. '<th style="width:90px;"></th>'
			. '</tr></thead><tbody>';
		$where = [
			'product'  => __( 'Products', 'dazont-ecom' ),
			'category' => __( 'Categories', 'dazont-ecom' ),
			'post'     => __( 'Articles', 'dazont-ecom' ),
		];
		foreach ( $checks as $id => $check ) {
			$n     = (int) ( $census['checks'][ $id ] ?? 0 );
			$total = (int) ( $seen[ $check['scope'] ] ?? 0 );
			echo '<tr>';
			echo '<td><strong>' . esc_html( $check['label'] ) . '</strong><br />'
				. '<span class="description">'
				. esc_html( trim( (string) $check['why'] . ' ' . (string) ( $check['fix'] ?? '' ) ) )
				. '</span></td>';
			echo '<td>' . esc_html( $where[ $check['scope'] ] ?? $check['scope'] ) . '</td>';
			echo '<td style="text-align:right;font-size:15px;">' . ( $n ? '<strong>' . (int) $n . '</strong>' : '—' );
			if ( $n && $total > 0 ) {
				echo '<br /><span class="description">' . esc_html( sprintf( '%d%%', (int) round( $n / $total * 100 ) ) ) . '</span>';
			}
			echo '</td>';
			echo '<td>';
			if ( $n ) {
				printf(
					'<a class="button button-small" href="%s">%s</a>',
					esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'check' => $id ], admin_url( 'admin.php' ) ) ),
					esc_html__( 'The list', 'dazont-ecom' )
				);
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description" style="margin-top:14px;max-width:760px;">'
			. esc_html__( 'The criteria themselves — what is looked at, the figure it has to reach, and the ones you would rather not be counted against — are under Settings → Diagnostic. Your own prompts add their lines here by themselves.', 'dazont-ecom' )
			. '</p>';
		$this->print_script();
	}

	private function render_list( string $id ): void {
		$check  = self::checks()[ $id ];
		$census = self::census();
		$lists  = self::lists();
		$ids    = (array) ( $lists[ $id ] ?? [] );
		$n      = (int) ( $census['checks'][ $id ] ?? 0 );
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$slice  = array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

		printf(
			'<h1>%s <a class="page-title-action" href="%s">%s</a></h1>',
			esc_html( $check['label'] ),
			esc_url( add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) ) ),
			esc_html__( 'Back to the diagnostic', 'dazont-ecom' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html( sprintf(
				/* translators: 1: how many fall short, 2: how many are listed */
				__( '%1$d fall short. %2$d listed here — the count is exact whatever the list can show.', 'dazont-ecom' ),
				$n,
				count( $ids )
			) )
		);

		// What ELSE each one is short of: read from the same reading, so a
		// product that needs four things is opened once and not four times.
		$also = [];
		foreach ( $lists as $other => $other_ids ) {
			if ( $other === $id ) {
				continue;
			}
			foreach ( array_intersect( (array) $other_ids, $slice ) as $oid ) {
				$also[ (int) $oid ][] = (string) ( self::checks()[ $other ]['label'] ?? $other );
			}
		}

		echo '<table class="widefat striped" style="max-width:1100px;"><tbody>';
		foreach ( $slice as $oid ) {
			$oid = (int) $oid;
			[ $name, $link ] = self::object_link( (string) $check['scope'], $oid );
			if ( '' === $name ) {
				continue;
			}
			echo '<tr><td>';
			printf( '<a href="%s"><strong>%s</strong></a>', esc_url( $link ), esc_html( $name ) );
			if ( ! empty( $also[ $oid ] ) ) {
				echo '<br /><span class="description">' . esc_html__( 'also:', 'dazont-ecom' ) . ' '
					. esc_html( implode( ' · ', array_slice( $also[ $oid ], 0, 4 ) ) ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( count( $ids ) / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<p style="margin-top:12px;">' . wp_kses_post( paginate_links( [
				'base'      => add_query_arg( [ 'page' => self::MENU_SLUG, 'check' => $id, 'paged' => '%#%' ], admin_url( 'admin.php' ) ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => '‹',
				'next_text' => '›',
			] ) ) . '</p>';
		}
	}

	/** What one object is called and where it is edited. @return array{0:string,1:string} */
	private static function object_link( string $scope, int $id ): array {
		if ( 'category' === $scope ) {
			$term = get_term( $id, 'product_cat' );
			return ( $term && ! is_wp_error( $term ) )
				? [ (string) $term->name, (string) get_edit_term_link( $id, 'product_cat' ) ]
				: [ '', '' ];
		}
		$post = get_post( $id );
		return $post ? [ (string) $post->post_title, (string) get_edit_post_link( $id, 'raw' ) ] : [ '', '' ];
	}

	private function print_script(): void {
		$nonce = wp_create_nonce( self::NONCE );
		?>
		<script>
		jQuery(function ($) {
			$('#dze-diag-scan').on('click', function () {
				var $b = $(this), $m = $('#dze-diag-msg');
				$b.prop('disabled', true);
				$m.text(<?php echo wp_json_encode( __( 'Reading the shop…', 'dazont-ecom' ) ); ?>);
				$.post(ajaxurl, { action: 'dze_diag_scan', nonce: <?php echo wp_json_encode( $nonce ); ?> })
					.done(function () { window.location.reload(); })
					.fail(function () {
						$b.prop('disabled', false);
						$m.text(<?php echo wp_json_encode( __( 'The reading did not finish — try again.', 'dazont-ecom' ) ); ?>);
					});
			});
		});
		</script>
		<?php
	}

	/** The three scopes, in the order the shop thinks about them. */
	private static function scopes(): array {
		return [
			'product'  => __( 'Products', 'dazont-ecom' ),
			'category' => __( 'Product categories', 'dazont-ecom' ),
			'post'     => __( 'Articles and pages', 'dazont-ecom' ),
		];
	}

	/**
	 * The rule, as a sentence, with its figure in it.
	 *
	 * The dropdown says "holds fewer than N words" because a dropdown cannot
	 * know the figure yet; a shut card can, and "holds fewer than 120 words" is
	 * the whole criterion read at a glance, which is the point of shutting it.
	 */
	private static function test_said( string $test, int $value, string $field = '' ): string {
		if ( 'post.links' === $field && 'min_count' === $test && 0 === $value ) {
			return __( 'holds fewer links than its own length calls for', 'dazont-ecom' );
		}
		switch ( $test ) {
			case 'min_words':
				/* translators: %d: a number of words */
				return sprintf( __( 'holds fewer than %d words', 'dazont-ecom' ), $value );
			case 'min_count':
				/* translators: %d: how many there have to be */
				return sprintf( __( 'there are fewer than %d', 'dazont-ecom' ), $value );
			default:
				return __( 'is empty', 'dazont-ecom' );
		}
	}

	/**
	 * One criterion, as the card the prompts are edited in.
	 *
	 * The same card as the prompt library, on purpose: shut, it is a name and
	 * the rule in words; open, it is the two dropdowns that make it. A table of
	 * seven rows of dropdowns was three screens tall, and the button that adds
	 * one was below all of it.
	 *
	 * @param array  $row   The criterion.
	 * @param string $index What the field names are numbered with — an integer
	 *                      for a saved row, __I__ for the blank one JavaScript
	 *                      clones, so the markup has one source and not two.
	 */
	private static function card( array $row, string $index ): string {
		$opt    = self::OPT;
		$fields = self::fields();
		$field  = (string) ( $row['field'] ?? '' );
		$test   = (string) ( $row['test'] ?? 'empty' );
		$value  = (int) ( $row['value'] ?? 0 );
		$name   = static fn( string $key ): string => esc_attr( $opt . '[rows][' . $index . '][' . $key . ']' );

		$out  = '<div class="dze-prb dze-diag-card">';
		$out .= '<div class="dze-prb-head">';
		$out .= '<label class="dze-switch dze-prb-on" title="' . esc_attr__( 'Count this criterion', 'dazont-ecom' ) . '">'
			. '<input type="checkbox" name="' . $name( 'on' ) . '" value="1"' . checked( 1, (int) ( $row['on'] ?? 1 ), false ) . ' />'
			. '<span class="dze-switch-slider"></span></label>';
		$out .= '<input type="hidden" name="' . $name( 'id' ) . '" value="' . esc_attr( (string) ( $row['id'] ?? '' ) ) . '" />';
		$out .= '<input type="text" class="dze-prb-name" name="' . $name( 'label' ) . '" value="' . esc_attr( (string) ( $row['label'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Name this criterion', 'dazont-ecom' ) . '" />';
		$out .= '<span class="dze-prb-dest dze-diag-said">'
			. esc_html( (string) ( $fields[ $field ]['label'] ?? '' ) ) . ' &mdash; ' . esc_html( self::test_said( $test, $value, $field ) )
			. '</span>';
		$out .= '<button type="button" class="dze-prb-toggle dze-diag-toggle" aria-expanded="false">' . esc_html__( 'Edit', 'dazont-ecom' ) . ' <span class="dze-prb-caret">&#9656;</span></button>';
		$out .= '<button type="button" class="dze-pr-del dze-diag-drop" title="' . esc_attr__( 'Remove this criterion', 'dazont-ecom' ) . '">&#10005;</button>';
		$out .= '</div>';

		$out .= '<div class="dze-prb-body" style="display:none;"><p class="dze-prb-line">';
		$out .= '<label><span>' . esc_html__( 'Looks at', 'dazont-ecom' ) . '</span>'
			. '<select class="dze-diag-field" name="' . $name( 'field' ) . '">';
		foreach ( $fields as $fid => $meta ) {
			$out .= '<option value="' . esc_attr( $fid ) . '"' . selected( $fid, $field, false ) . '>' . esc_html( (string) $meta['label'] ) . '</option>';
		}
		$out .= '</select></label>';
		$out .= '<input type="text" class="dze-diag-key" name="' . $name( 'key' ) . '" value="' . esc_attr( (string) ( $row['key'] ?? '' ) ) . '"'
			. ' placeholder="' . esc_attr__( 'meta key', 'dazont-ecom' ) . '" style="width:170px;'
			. ( empty( $fields[ $field ]['key'] ) ? 'display:none;' : '' ) . '" />';
		$out .= '<label><span>' . esc_html__( 'Falls short when', 'dazont-ecom' ) . '</span>'
			. '<select class="dze-diag-test" name="' . $name( 'test' ) . '">';
		foreach ( self::tests() as $tid => $label ) {
			$out .= '<option value="' . esc_attr( $tid ) . '"' . selected( $tid, $test, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$out .= '</select></label>';
		$out .= '<input type="number" class="dze-diag-value" min="0" step="1" style="width:90px;' . ( 'empty' === $test ? 'display:none;' : '' ) . '"'
			. ' name="' . $name( 'value' ) . '" value="' . esc_attr( (string) $value ) . '" />';
		$out .= '</p>';
		$out .= '<p class="description dze-diag-hint">' . esc_html__( 'An article held to "there are fewer than 0" is held to the figure its own length calls for.', 'dazont-ecom' ) . '</p>';
		$out .= '</div></div>';
		return $out;
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// The card styling belongs to the prompt library's stylesheet, and this
		// screen is the same list in the same clothes.
		if ( ! wp_style_is( 'dze-content', 'enqueued' ) ) {
			wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		}
		$opt    = self::OPT;
		$fields = self::fields();
		$rows   = self::rows();

		echo '<form method="post" action="options.php">';
		settings_fields( 'dze_diagnostic_options' );
		echo '<h2 class="title">' . esc_html__( 'Criteria', 'dazont-ecom' ) . '</h2>';
		echo '<p class="description" style="max-width:900px;">'
			. esc_html__( 'What the Diagnostic screen reads the shop against. A name, what is looked at, and the rule it has to pass — add your own, change a figure, remove one you do not care about. Every criterion you switch off simply stops being counted as work waiting to be done; nothing on the shop changes.', 'dazont-ecom' )
			. '</p>';
		echo '<p class="description" style="max-width:900px;">'
			. esc_html__( 'Your PROMPTS answer for themselves and are not in this list: each one already says what it writes and where, so "Custom bloc text 2: empty" appears on the diagnostic by itself and follows the prompt when you rename, move or disable it.', 'dazont-ecom' )
			. '</p>';

		// The button that adds one sits ABOVE the list as well as below it: the
		// list is the length of the shop's own standards, and a control you have
		// to scroll past seven criteria to find is a control nobody finds.
		echo '<p style="margin:14px 0 10px;">'
			. '<button type="button" class="button button-secondary" id="dze-diag-add">&#43; ' . esc_html__( 'Add a criterion', 'dazont-ecom' ) . '</button>'
			. '<button type="button" class="button" id="dze-diag-reset" style="margin-left:8px;">&#8634; ' . esc_html__( 'Restore the shipped criteria', 'dazont-ecom' ) . '</button>'
			. '</p>';

		echo '<div id="dze-diag-lib" style="max-width:900px;">';
		$i = 0;
		foreach ( self::scopes() as $scope => $label ) {
			echo '<h3 class="dze-pr-grouphead">' . esc_html( $label ) . '</h3>';
			echo '<div class="dze-prlist" data-scope="' . esc_attr( $scope ) . '">';
			foreach ( $rows as $row ) {
				if ( $scope !== ( $fields[ $row['field'] ]['scope'] ?? '' ) ) {
					continue;
				}
				echo self::card( $row, (string) $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with per-value escaping in card().
				$i++;
			}
			echo '</div>';
		}
		echo '<div class="dze-prlist dze-prlist-new" id="dze-diag-new" style="margin-top:8px;"></div>';
		echo '</div>';

		// Even a list emptied to nothing has to reach the sanitizer as a
		// deliberate emptiness, or it reads as a form that was about something
		// else and the criteria come back on the next page load.
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[rows_shown]" value="1" />';
		self::print_rows_script();
		submit_button();
		echo '</form>';
	}

	/** The card list's own behaviour: open one, add one, drop one, put them back. */
	private static function print_rows_script(): void {
		$keys = [];
		foreach ( self::fields() as $fid => $meta ) {
			$keys[ $fid ] = [ 'label' => (string) $meta['label'], 'key' => ! empty( $meta['key'] ), 'scope' => (string) $meta['scope'] ];
		}
		$blank = [ 'id' => '', 'label' => '', 'field' => 'product.description', 'test' => 'min_words', 'value' => 120, 'key' => '', 'on' => 1 ];
		?>
		<script type="text/template" id="dze-diag-tpl"><?php echo self::card( $blank, '__I__' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with per-value escaping in card(). ?></script>
		<script>
		jQuery( function ( $ ) {
			var fields = <?php echo wp_json_encode( $keys ); ?>,
				said = <?php echo wp_json_encode( [
					'empty'     => __( 'is empty', 'dazont-ecom' ),
					'min_words' => __( 'holds fewer than %d words', 'dazont-ecom' ),
					'min_count' => __( 'there are fewer than %d', 'dazont-ecom' ),
					'target'    => __( 'holds fewer links than its own length calls for', 'dazont-ecom' ),
				] ); ?>,
				shipped = <?php echo wp_json_encode( array_values( self::default_rows() ) ); ?>;

			// The card's own number. New cards are given one past every card on
			// the page, so two added in a row never post into the same slot.
			function nextIndex() {
				var max = 0;
				$( '#dze-diag-lib [name^="<?php echo esc_js( self::OPT ); ?>[rows]["]' ).each( function () {
					var m = /\[rows\]\[(\d+)\]/.exec( this.name );
					if ( m ) { max = Math.max( max, parseInt( m[1], 10 ) ); }
				} );
				return max + 1;
			}

			// What a shut card says, kept true the moment a dropdown moves.
			function retell( $card ) {
				var f = $card.find( '.dze-diag-field' ).val(),
					t = $card.find( '.dze-diag-test' ).val(),
					v = parseInt( $card.find( '.dze-diag-value' ).val(), 10 ) || 0,
					meta = fields[ f ] || { label: '', key: false },
					rule = ( 'post.links' === f && 'min_count' === t && 0 === v )
						? said.target
						: ( said[ t ] || '' ).replace( '%d', String( v ) );
				$card.find( '.dze-diag-said' ).text( meta.label + ' — ' + rule );
				$card.find( '.dze-diag-key' ).toggle( !! meta.key );
				$card.find( '.dze-diag-value' ).toggle( 'empty' !== t );
			}

			function add( row ) {
				var html = $( '#dze-diag-tpl' ).html().replace( /__I__/g, String( nextIndex() ) ),
					$card = $( html );
				if ( row ) {
					$card.find( '.dze-prb-name' ).val( row.label || '' );
					$card.find( 'input[name$="[id]"]' ).val( row.id || '' );
					$card.find( '.dze-diag-field' ).val( row.field );
					$card.find( '.dze-diag-test' ).val( row.test );
					$card.find( '.dze-diag-value' ).val( row.value );
					$card.find( '.dze-diag-key' ).val( row.key || '' );
					$card.find( '.dze-switch input' ).prop( 'checked', 0 !== row.on );
				}
				var scope = ( fields[ $card.find( '.dze-diag-field' ).val() ] || {} ).scope,
					$list = row ? $( '#dze-diag-lib .dze-prlist[data-scope="' + scope + '"]' ) : $();
				( $list.length ? $list : $( '#dze-diag-new' ) ).append( $card );
				retell( $card );
				return $card;
			}

			// One card open at a time — the same gesture as the prompt library.
			$( document ).on( 'click', '.dze-diag-toggle', function () {
				var $c = $( this ).closest( '.dze-diag-card' ), open = ! $c.hasClass( 'is-open' );
				$( '.dze-diag-card' ).removeClass( 'is-open' ).find( '.dze-prb-body' ).hide()
					.end().find( '.dze-diag-toggle' ).attr( 'aria-expanded', 'false' ).find( '.dze-prb-caret' ).text( '▸' );
				if ( open ) {
					$c.addClass( 'is-open' ).find( '.dze-prb-body' ).show();
					$c.find( '.dze-diag-toggle' ).attr( 'aria-expanded', 'true' ).find( '.dze-prb-caret' ).text( '▾' );
				}
			} );
			$( document ).on( 'change keyup', '.dze-diag-field, .dze-diag-test, .dze-diag-value', function () {
				retell( $( this ).closest( '.dze-diag-card' ) );
			} );
			$( document ).on( 'click', '.dze-diag-drop', function () {
				$( this ).closest( '.dze-diag-card' ).remove();
			} );
			$( '#dze-diag-add' ).on( 'click', function () {
				add( null ).addClass( 'is-open' ).find( '.dze-prb-body' ).show().end()
					.find( '.dze-prb-name' ).trigger( 'focus' );
			} );
			$( '#dze-diag-reset' ).on( 'click', function () {
				$( '#dze-diag-lib .dze-prb' ).remove();
				$.each( shipped, function ( n, r ) { add( r ); } );
			} );
		} );
		</script>
		<?php
	}
}
