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

	/** One standard, with the shipped figure when the shop has not moved it. */
	public static function conf( string $key = '' ) {
		$s   = self::settings();
		$def = [
			'prod_desc_words'  => 120,
			'prod_gallery_min' => 3,
			'cat_desc_words'   => 150,
			'cat_links_min'    => 2,
		];
		$out = [];
		foreach ( $def as $k => $v ) {
			$out[ $k ] = isset( $s[ $k ] ) && '' !== $s[ $k ] ? max( 0, (int) $s[ $k ] ) : $v;
		}
		$out['off'] = (array) ( $s['off'] ?? [] );
		return '' === $key ? $out : ( $out[ $key ] ?? null );
	}

	/**
	 * Every criterion the shop is read against.
	 *
	 * @return array<string,array{scope:string,label:string,why:string,fix:string}>
	 */
	public static function checks(): array {
		$c   = self::conf();
		$out = [
			'prod_desc' => [
				'scope' => 'product',
				'label' => sprintf(
					/* translators: %d: the number of words a description should reach */
					__( 'Description under %d words', 'dazont-ecom' ),
					(int) $c['prod_desc_words']
				),
				'why'   => __( 'A product page with nothing to read ranks on nothing and answers no question a buyer has.', 'dazont-ecom' ),
				'fix'   => __( 'The description prompt, on the product screen or in bulk.', 'dazont-ecom' ),
			],
			'prod_short' => [
				'scope' => 'product',
				'label' => __( 'No short description', 'dazont-ecom' ),
				'why'   => __( 'It is the paragraph beside the price, and the one WooCommerce hands to the cart and to Merchant Center.', 'dazont-ecom' ),
				'fix'   => __( 'The short-description prompt.', 'dazont-ecom' ),
			],
			'prod_main' => [
				'scope' => 'product',
				'label' => __( 'No main photograph', 'dazont-ecom' ),
				'why'   => __( 'A product with no featured image is invisible in every grid of the shop and refused by Merchant Center.', 'dazont-ecom' ),
				'fix'   => __( 'The Main image lane on the product screen.', 'dazont-ecom' ),
			],
			'prod_gallery' => [
				'scope' => 'product',
				'label' => sprintf(
					/* translators: %d: how many photographs a gallery should hold */
					__( 'Fewer than %d photographs in the gallery', 'dazont-ecom' ),
					(int) $c['prod_gallery_min']
				),
				'why'   => __( 'One angle sells nothing and leaves the model nothing to work from either.', 'dazont-ecom' ),
				'fix'   => __( 'The image prompts on the product screen.', 'dazont-ecom' ),
			],
		];

		// The shop's own prompts, each answering for what it writes. Only the
		// ones that write somewhere a page can be EMPTY: a prompt that writes
		// the description is already answered by the criterion above it, and
		// counting it twice would make the same product look like two jobs.
		if ( class_exists( 'DZE_Content' ) && DZE_Modules::enabled( 'content' ) ) {
			foreach ( DZE_Content::registry() as $row ) {
				$id = (string) ( $row['id'] ?? '' );
				if ( '' === $id || empty( $row['enabled'] ) || 'text' !== ( $row['type'] ?? 'text' ) ) {
					continue;
				}
				$dest = DZE_Content::dest_for( $id );
				$name = (string) ( $row['name'] ?? $id );
				if ( in_array( (string) $dest['type'], [ 'meta', 'seo_title', 'seo_desc' ], true ) ) {
					$out[ 'field_' . $id ] = [
						'scope' => 'product',
						'label' => sprintf(
							/* translators: %s: the name of one of the shop's own prompts */
							__( '%s: empty', 'dazont-ecom' ),
							$name
						),
						'why'   => __( 'A prompt of yours writes here and this product has nothing in it.', 'dazont-ecom' ),
						'fix'   => $name,
						'meta'  => 'meta' === $dest['type']
							? (string) ( $dest['key'] ?? '' )
							: (string) ( DZE_Content::seo_keys()[ 'seo_title' === $dest['type'] ? 'title' : 'desc' ] ?? '' ),
					];
				}
				// The photograph a text block argues about: written by the same
				// prompt, missing on its own.
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
						'meta'  => $img,
					];
				}
			}
		}

		if ( class_exists( 'DZE_Category_Content' ) && DZE_Modules::enabled( 'category_content' ) ) {
			$out['cat_desc'] = [
				'scope' => 'category',
				'label' => sprintf(
					/* translators: %d: the number of words a category description should reach */
					__( 'Category description under %d words', 'dazont-ecom' ),
					(int) $c['cat_desc_words']
				),
				'why'   => __( 'A category page is the aisle: with no text it competes with nothing.', 'dazont-ecom' ),
				'fix'   => __( 'The category description prompt, or the nightly pass.', 'dazont-ecom' ),
			];
			$out['cat_links'] = [
				'scope' => 'category',
				'label' => sprintf(
					/* translators: %d: how many internal links a category should carry */
					__( 'Category carrying fewer than %d internal links', 'dazont-ecom' ),
					(int) $c['cat_links_min']
				),
				'why'   => __( 'A page that points at nothing passes nothing on to the rest of the shop.', 'dazont-ecom' ),
				'fix'   => __( 'The "Add internal links only" pass.', 'dazont-ecom' ),
			];
		}

		if ( class_exists( 'DZE_Post_Links' ) && DZE_Modules::enabled( 'category_content' ) ) {
			$out['post_links'] = [
				'scope' => 'post',
				'label' => __( 'Article or page under its own link target', 'dazont-ecom' ),
				'why'   => __( 'The target is worked out from the length of the text itself: a long article pointing nowhere is half a mesh.', 'dazont-ecom' ),
				'fix'   => __( 'The article linking pass.', 'dazont-ecom' ),
			];
		}

		// Switched off by the shop: read, but not counted against it.
		foreach ( (array) $c['off'] as $id ) {
			unset( $out[ (string) $id ] );
		}
		return $out;
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
		$c    = self::conf();
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
				$pid = (int) $post->ID;
				$seen['product']++;
				foreach ( $wanted as $id => $check ) {
					if ( self::product_fails( $id, $check, $post, $c ) ) {
						$hits[ $id ][] = $pid;
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

	/** Whether one product falls short of one criterion. */
	private static function product_fails( string $id, array $check, WP_Post $post, array $c ): bool {
		$pid = (int) $post->ID;
		if ( 'prod_desc' === $id ) {
			return self::words( (string) $post->post_content ) < (int) $c['prod_desc_words'];
		}
		if ( 'prod_short' === $id ) {
			return '' === trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
		}
		if ( 'prod_main' === $id ) {
			return ! (int) get_post_thumbnail_id( $pid );
		}
		if ( 'prod_gallery' === $id ) {
			$gal = array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $pid, '_product_image_gallery', true ) ) ) );
			return count( $gal ) < (int) $c['prod_gallery_min'];
		}
		$key = (string) ( $check['meta'] ?? '' );
		return '' !== $key && '' === trim( (string) get_post_meta( $pid, $key, true ) );
	}

	/** @param array<string,int[]> $hits */
	private static function scan_categories( array $checks, array &$hits, array &$seen ): void {
		if ( ! isset( $checks['cat_desc'] ) && ! isset( $checks['cat_links'] ) ) {
			return;
		}
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		$c = self::conf();
		foreach ( $terms as $term ) {
			$seen['category']++;
			$text = (string) $term->description;
			if ( isset( $checks['cat_desc'] ) && self::words( $text ) < (int) $c['cat_desc_words'] ) {
				$hits['cat_desc'][] = (int) $term->term_id;
			}
			if ( isset( $checks['cat_links'] ) && (int) preg_match_all( '/<a\s[^>]*href=/i', $text ) < (int) $c['cat_links_min'] ) {
				$hits['cat_links'][] = (int) $term->term_id;
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
		if ( ! isset( $checks['post_links'] ) || ! class_exists( 'DZE_Post_Links' ) ) {
			return;
		}
		foreach ( DZE_Post_Links::census( true ) as $pid => $row ) {
			$seen['post']++;
			if ( (int) ( $row['target'] ?? 0 ) > 0 && (int) ( $row['out'] ?? 0 ) < (int) $row['target'] ) {
				$hits['post_links'][] = (int) $pid;
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
		foreach ( [ 'prod_desc_words', 'prod_gallery_min', 'cat_desc_words', 'cat_links_min' ] as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$out[ $k ] = max( 0, min( 5000, (int) $in[ $k ] ) );
			}
		}
		// A list of switched-off criteria: the form carries the marker even
		// when every box is ticked, or unticking the last one would read as
		// "this form was not about criteria" and never take.
		if ( ! empty( $in['checks_shown'] ) ) {
			$out['off'] = array_values( array_map( 'sanitize_key', (array) ( $in['off'] ?? [] ) ) );
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
				. '<span class="description">' . esc_html( $check['why'] ) . ' ' . esc_html( $check['fix'] ) . '</span></td>';
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
			. esc_html__( 'The standards themselves — how many words, how many photographs — and the criteria you would rather not be counted against are under Settings → Diagnostic.', 'dazont-ecom' )
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

	// =========================================================================
	// Settings
	// =========================================================================

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$c   = self::conf();
		$opt = self::OPT;
		echo '<form method="post" action="options.php">';
		settings_fields( 'dze_diagnostic_options' );
		echo '<p class="description" style="max-width:760px;">'
			. esc_html__( 'The standards the Diagnostic screen reads the shop against. They are yours: a shop of accessories and a shop of jackets do not need the same description.', 'dazont-ecom' )
			. '</p>';
		echo '<table class="form-table" role="presentation">';
		$rows = [
			'prod_desc_words'  => __( 'Product description, in words', 'dazont-ecom' ),
			'prod_gallery_min' => __( 'Photographs in a product gallery', 'dazont-ecom' ),
			'cat_desc_words'   => __( 'Category description, in words', 'dazont-ecom' ),
			'cat_links_min'    => __( 'Internal links in a category description', 'dazont-ecom' ),
		];
		foreach ( $rows as $key => $label ) {
			printf(
				'<tr><th scope="row"><label for="dze-diag-%1$s">%2$s</label></th><td>'
				. '<input type="number" min="0" step="1" id="dze-diag-%1$s" name="%3$s[%1$s]" value="%4$d" style="width:100px;" /></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $opt ),
				(int) $c[ $key ]
			);
		}
		echo '</table>';

		echo '<h2>' . esc_html__( 'What is counted', 'dazont-ecom' ) . '</h2>';
		echo '<p class="description" style="max-width:760px;">'
			. esc_html__( 'Untick a line and it disappears from the diagnostic. Nothing is deleted and nothing changes on the shop — it stops being counted as work waiting to be done.', 'dazont-ecom' )
			. '</p>';
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[checks_shown]" value="1" />';
		// The list is drawn from the criteria as they stand WITH the switched
		// off ones put back, or a criterion could never be switched on again.
		$off = (array) $c['off'];
		$all = self::checks();
		foreach ( $off as $id ) {
			if ( ! isset( $all[ $id ] ) ) {
				$all[ (string) $id ] = [ 'label' => (string) $id, 'scope' => '', 'why' => '', 'fix' => '' ];
			}
		}
		echo '<ul style="margin:0 0 18px;">';
		foreach ( $all as $id => $check ) {
			printf(
				'<li><label><input type="checkbox" name="%1$s[off][]" value="%2$s"%3$s /> %4$s</label></li>',
				esc_attr( $opt ),
				esc_attr( $id ),
				in_array( (string) $id, array_map( 'strval', $off ), true ) ? ' checked="checked"' : '',
				esc_html( sprintf(
					/* translators: %s: the criterion */
					__( 'Do not count: %s', 'dazont-ecom' ),
					(string) $check['label']
				) )
			);
		}
		echo '</ul>';
		submit_button();
		echo '</form>';
	}
}
