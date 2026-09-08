<?php
/**
 * Internal linking — the SHOP'S OWN LINK GRAPH, and what it says.
 *
 * Before this there were three counters and no graph. DZE_Post_Links counted
 * the links going OUT of an article. DZE_Automation counted a category's
 * inbound links — but only those coming from another category's description,
 * so an article pointing at a category counted for nothing. The diagnostic
 * counted `<a href` occurrences per object, internal and external alike.
 *
 * None of them could answer the questions that actually decide a mesh:
 *
 *   - which pages does NOBODY point at?
 *   - which pages point at nothing?
 *   - who points at whom, and is it returned?
 *   - which two pages are plainly about the same thing and are NOT linked?
 *
 * So this reads the shop once and writes the edges down: one row per link,
 * from a page to a page, with the words the link was made of. Everything else
 * on this screen is a question asked of that table.
 *
 * WHAT IS IN THE MESH, and why nothing else is: product categories, articles
 * and pages. Products are deliberately out — "blog et pages catégories, c'est
 * tout" — and a product page already lists what it belongs to.
 *
 * PAGE BUILDERS. A page built with Elementor keeps its text in post meta and
 * leaves `post_content` empty or nearly so. Read from the post alone it looks
 * like a page with no links at all, which would put it at the top of every
 * "orphan" list for ever. Its links are read from the builder's own data as
 * well, and the page is MARKED as built: a page nobody can safely write into
 * is a page this module will offer to link FROM nowhere, and it says so rather
 * than failing later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DZE_Mesh {

	/** What can be linked, and what can be linked TO. */
	public const TYPES = [ 'product_cat', 'post', 'page' ];

	private const SCHEMA_OPT     = 'dze_mesh_schema';
	private const SCHEMA_VERSION = 1;
	private const CENSUS_OPT     = 'dze_mesh_census';
	private const LOCK           = 'dze_mesh_lock';
	public const CRON            = 'dze_mesh_scan';

	/** How many pages one reading walks. A shop is not a web crawler. */
	private const SCAN = 4000;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON, [ __CLASS__, 'scan' ] );
		if ( ! is_admin() ) {
			// A customer never pays for this: it is a reading of the shop for
			// the shop's owner, and it belongs to admin and cron alone.
			return;
		}
		add_action( 'admin_init', [ $this, 'maybe_install' ] );
		add_action( 'admin_init', [ __CLASS__, 'schedule' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'wp_ajax_dze_mesh_scan', [ __CLASS__, 'ajax_scan' ] );
		add_action( 'wp_ajax_dze_mesh_pairs', [ __CLASS__, 'ajax_pairs' ] );
		add_action( 'wp_ajax_dze_mesh_queue', [ __CLASS__, 'ajax_queue' ] );
		add_action( 'wp_ajax_dze_mesh_out', [ __CLASS__, 'ajax_out' ] );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/** Switched off, it stands its reading down: a cron firing into the void. */
	public static function disable(): void {
		$ts = wp_next_scheduled( self::CRON );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON );
			$ts = wp_next_scheduled( self::CRON );
		}
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dze_mesh';
	}

	public function maybe_install(): void {
		if ( (int) get_option( self::SCHEMA_OPT, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		// One row per LINK, not per page: "who points at whom" is the question
		// this module exists to answer, and a count per page cannot answer it.
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			from_kind VARCHAR(20) NOT NULL,
			from_id BIGINT UNSIGNED NOT NULL,
			to_kind VARCHAR(20) NOT NULL,
			to_id BIGINT UNSIGNED NOT NULL,
			anchor VARCHAR(190) NOT NULL DEFAULT '',
			seen DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY src (from_kind,from_id),
			KEY dst (to_kind,to_id)
		) {$charset};" );
		update_option( self::SCHEMA_OPT, self::SCHEMA_VERSION, false );
	}

	// =========================================================================
	// Reading the shop
	// =========================================================================

	/**
	 * Every page of the mesh: what it is, what it is called, where it lives.
	 *
	 * The whole site in its main language, not a recent slice: a page nobody
	 * has touched for two years is exactly as orphaned as it ever was, and it
	 * is precisely the one this module is for.
	 *
	 * @return array<string,array{kind:string,id:int,title:string,url:string,words:int,built:bool}>
	 *         keyed "kind:id".
	 */
	public static function pages( bool $force = false ): array {
		$cached = $force ? false : get_transient( 'dze_mesh_pages' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$lang = class_exists( 'DZE_Category_Content' ) ? DZE_Category_Content::default_lang() : '';
		if ( '' !== $lang ) {
			do_action( 'wpml_switch_language', $lang );
		}
		$out = [];
		foreach ( (array) get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] ) as $t ) {
			if ( is_wp_error( $t ) || 'uncategorized' === $t->slug ) {
				continue;
			}
			if ( '' !== $lang && class_exists( 'DZE_Category_Content' )
				&& DZE_Category_Content::lang_code( (int) $t->term_id ) !== $lang ) {
				continue;
			}
			$url = get_term_link( $t );
			$out[ 'product_cat:' . (int) $t->term_id ] = [
				'kind'  => 'product_cat',
				'id'    => (int) $t->term_id,
				'title' => (string) $t->name,
				'url'   => is_wp_error( $url ) ? '' : (string) $url,
				'words' => str_word_count( wp_strip_all_tags( (string) $t->description ) ),
				// A term is never built by a page builder: its description is
				// a field, and writing into it is safe.
				'built' => false,
			];
		}
		// The pages that exist for the checkout and not for a reader: linking
		// to a cart is a link nobody follows and nobody should be told to add.
		$skip = array_filter( [
			(int) get_option( 'page_for_posts' ),
			(int) get_option( 'wp_page_for_privacy_policy' ),
			function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'cart' ) : 0,
			function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0,
			function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'myaccount' ) : 0,
			function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'terms' ) : 0,
		] );
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			"SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
			 WHERE post_status = 'publish' AND post_type IN ('post','page')
			 ORDER BY ID DESC LIMIT " . (int) self::SCAN,
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			$id = (int) $r['ID'];
			if ( in_array( $id, $skip, true ) || '' === trim( (string) $r['post_title'] ) ) {
				continue;
			}
			if ( '' !== $lang && class_exists( 'DZE_Post_Links' )
				&& DZE_Post_Links::lang_of( $id, (string) $r['post_type'] ) !== $lang ) {
				continue;
			}
			$kind = (string) $r['post_type'];
			$out[ $kind . ':' . $id ] = [
				'kind'  => $kind,
				'id'    => $id,
				'title' => (string) $r['post_title'],
				'url'   => (string) get_permalink( $id ),
				'words' => str_word_count( wp_strip_all_tags( (string) $r['post_content'] ) ),
				'built' => self::built_with_builder( $id ),
			];
		}
		if ( '' !== $lang ) {
			do_action( 'wpml_switch_language', null );
		}
		set_transient( 'dze_mesh_pages', $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Is this page laid out by a page builder rather than by its own content?
	 *
	 * It decides two things, both of which go wrong silently otherwise: where
	 * its links are READ from, and whether anything may ever be WRITTEN into
	 * it. Elementor keeps the page in post meta and leaves `post_content`
	 * empty — a link added there would be stored, would never appear on the
	 * page, and every screen would call the job done.
	 */
	public static function built_with_builder( int $post_id ): bool {
		return '' !== trim( (string) get_post_meta( $post_id, '_elementor_data', true ) );
	}

	/**
	 * The HTML a page's links are actually in.
	 *
	 * The post's own content, plus the builder's data when there is one: a
	 * page built with Elementor carries its anchors inside a JSON blob, and
	 * reading the post alone reports it as pointing at nothing at all.
	 */
	public static function body_of( string $kind, int $id ): string {
		if ( 'product_cat' === $kind ) {
			$term = get_term( $id, 'product_cat' );
			return ( $term && ! is_wp_error( $term ) ) ? (string) $term->description : '';
		}
		$post = get_post( $id );
		$html = $post ? (string) $post->post_content : '';
		$built = (string) get_post_meta( $id, '_elementor_data', true );
		if ( '' !== $built ) {
			// The blob is JSON with escaped slashes; the hrefs are read from it
			// as text rather than by walking a structure this plugin does not
			// own and cannot be sure of.
			$html .= ' ' . str_replace( [ '\\/', '\\"' ], [ '/', '"' ], $built );
		}
		return $html;
	}

	/**
	 * The links inside a piece of HTML: the address, and the words it was
	 * made of.
	 *
	 * The anchor travels because a mesh is judged on it as much as on the
	 * count — "here" and "this page" are links that carry nothing, and the
	 * screen has to be able to show them.
	 *
	 * @return array<int,array{url:string,anchor:string}>
	 */
	public static function links_in( string $html ): array {
		if ( '' === trim( $html ) ) {
			return [];
		}
		$out = [];
		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $one ) {
				$out[] = [
					'url'    => html_entity_decode( trim( (string) $one[1] ), ENT_QUOTES ),
					'anchor' => trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $one[2] ) ) ),
				];
			}
		}
		// A builder's JSON carries its links as a plain "url" field, with no
		// anchor beside it: counted, because a reader can click it, and with
		// an empty anchor rather than an invented one.
		if ( preg_match_all( '/"url"\s*:\s*"(https?:[^"]+)"/i', $html, $m2 ) ) {
			foreach ( $m2[1] as $u ) {
				$out[] = [ 'url' => html_entity_decode( trim( (string) $u ), ENT_QUOTES ), 'anchor' => '' ];
			}
		}
		return $out;
	}

	/**
	 * An address, turned back into a page of this shop — or nothing.
	 *
	 * By its own address first, which is exact; then by asking the shop what
	 * lives at that path, which is what catches a link written before a slug
	 * changed. Anything that resolves to nothing is a link OUT of the site and
	 * is not part of the mesh.
	 *
	 * @param array<string,string> $byurl address (no trailing slash) => "kind:id"
	 * @return string "kind:id", or ''.
	 */
	public static function resolve( string $url, array $byurl ): string {
		$url = trim( $url );
		if ( '' === $url || 0 === strpos( $url, '#' ) || 0 === strpos( $url, 'mailto:' ) || 0 === strpos( $url, 'tel:' ) ) {
			return '';
		}
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' !== $host && strtolower( $host ) !== strtolower( $home ) ) {
			return ''; // another site: not ours to weave.
		}
		$key = untrailingslashit( strtok( $url, '#?' ) );
		if ( isset( $byurl[ $key ] ) ) {
			return $byurl[ $key ];
		}
		// Written before a slug moved: the shop's own resolver answers, and it
		// is asked once per address because it costs a query.
		if ( class_exists( 'DZE_Wpml' ) && method_exists( 'DZE_Wpml', 'post_of' ) ) {
			$pid = (int) DZE_Wpml::post_of( $url );
			if ( $pid ) {
				$type = (string) get_post_type( $pid );
				if ( in_array( $type, [ 'post', 'page' ], true ) ) {
					return $type . ':' . $pid;
				}
			}
		}
		return '';
	}

	/**
	 * Reads the whole shop and writes the graph down.
	 *
	 * One pass, in cron or behind a button, never on a page a customer waits
	 * for. The table is REPLACED rather than merged: a link deleted from a
	 * description has to disappear from the graph, and reconciling row by row
	 * is a second code path for the same answer.
	 *
	 * @return array{pages:int,links:int,orphans:int,ends:int}
	 */
	public static function scan(): array {
		if ( get_transient( self::LOCK ) ) {
			return (array) ( get_option( self::CENSUS_OPT, [] )['counts'] ?? [] );
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		global $wpdb;
		$pages = self::pages( true );
		$byurl = [];
		foreach ( $pages as $key => $p ) {
			if ( '' !== $p['url'] ) {
				$byurl[ untrailingslashit( (string) $p['url'] ) ] = $key;
			}
		}
		$rows = [];
		$now  = current_time( 'mysql' );
		foreach ( $pages as $key => $p ) {
			[ $kind, $id ] = explode( ':', $key, 2 );
			foreach ( self::links_in( self::body_of( $kind, (int) $id ) ) as $link ) {
				$to = self::resolve( $link['url'], $byurl );
				if ( '' === $to || $to === $key ) {
					continue; // outside the site, or a page pointing at itself.
				}
				[ $tk, $ti ] = explode( ':', $to, 2 );
				$rows[] = $wpdb->prepare(
					'(%s,%d,%s,%d,%s,%s)',
					$kind,
					(int) $id,
					$tk,
					(int) $ti,
					mb_substr( (string) $link['anchor'], 0, 190 ),
					$now
				);
			}
		}
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$wpdb->query(
				"INSERT INTO {$table} (from_kind,from_id,to_kind,to_id,anchor,seen) VALUES " . implode( ',', $chunk )
			);
		}
		// phpcs:enable
		delete_transient( self::LOCK );
		return self::rebuild_census( $pages );
	}

	/**
	 * What the graph SAYS, worked out once and kept.
	 *
	 * Per page: how many point at it, how many it points at. Plus the two
	 * figures the whole module is judged on — how many pages nobody points at,
	 * and how many point at nothing.
	 *
	 * @return array{pages:int,links:int,orphans:int,ends:int}
	 */
	private static function rebuild_census( array $pages ): array {
		global $wpdb;
		$table = self::table();
		$in    = [];
		$out   = [];
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		foreach ( (array) $wpdb->get_results( "SELECT to_kind, to_id, COUNT(*) AS n FROM {$table} GROUP BY to_kind, to_id", ARRAY_A ) as $r ) {
			$in[ $r['to_kind'] . ':' . (int) $r['to_id'] ] = (int) $r['n'];
		}
		foreach ( (array) $wpdb->get_results( "SELECT from_kind, from_id, COUNT(*) AS n FROM {$table} GROUP BY from_kind, from_id", ARRAY_A ) as $r ) {
			$out[ $r['from_kind'] . ':' . (int) $r['from_id'] ] = (int) $r['n'];
		}
		$links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable
		$orphans = 0;
		$short   = 0;
		$ends    = 0;
		$per     = [];
		foreach ( $pages as $key => $p ) {
			$i = (int) ( $in[ $key ] ?? 0 );
			$o = (int) ( $out[ $key ] ?? 0 );
			$per[ $key ] = [ 'in' => $i, 'out' => $o ];
			if ( 0 === $i ) {
				$orphans++;
			}
			// The figure the SCREEN is about, kept beside the one the reading
			// is about: the tab badge and the list under it counting different
			// things is a screen that disagrees with itself once a day.
			if ( $i < self::WANT_IN ) {
				$short++;
			}
			// A page built by a builder is not counted as a dead end: this
			// module does not write into one, so it is not work it can offer.
			if ( 0 === $o && empty( $p['built'] ) ) {
				$ends++;
			}
		}
		$counts = [ 'pages' => count( $pages ), 'links' => $links, 'orphans' => $orphans, 'short' => $short, 'ends' => $ends ];
		update_option( self::CENSUS_OPT, [ 'per' => $per, 'counts' => $counts, 'at' => time() ], false );
		return $counts;
	}

	/** The last reading: what it found, and when. */
	public static function census(): array {
		$c = get_option( self::CENSUS_OPT, [] );
		return is_array( $c ) ? $c : [];
	}

	/** Read the shop again, from the screen. */
	public static function ajax_scan(): void {
		check_ajax_referer( 'dze_mesh', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		wp_send_json_success( self::scan() );
	}

	// =========================================================================
	// Questions asked of the graph
	// =========================================================================

	/**
	 * The pages nobody points at, worst first.
	 *
	 * The first question a mesh has to answer, and the one no screen here
	 * could answer before: a page with no inbound link is a page a reader can
	 * only reach from a menu, and a search engine barely at all.
	 *
	 * @return array<int,array{kind:string,id:int,title:string,url:string,in:int,out:int,built:bool}>
	 */
	public static function orphans( int $limit = 200 ): array {
		return self::ranked( static fn( array $r ): bool => 0 === $r['in'], 'in', $limit );
	}

	/** The pages that point at nothing — half a mesh, the other way round. */
	public static function dead_ends( int $limit = 200 ): array {
		return self::ranked(
			static fn( array $r ): bool => 0 === $r['out'] && empty( $r['built'] ),
			'out',
			$limit
		);
	}

	/** One reader for both, so the two lists cannot answer differently. */
	private static function ranked( callable $keep, string $by, int $limit ): array {
		$census = self::census();
		$per    = (array) ( $census['per'] ?? [] );
		$out    = [];
		foreach ( self::pages() as $key => $p ) {
			$row = [
				'kind'  => (string) $p['kind'],
				'id'    => (int) $p['id'],
				'title' => (string) $p['title'],
				'url'   => (string) $p['url'],
				'words' => (int) $p['words'],
				'built' => ! empty( $p['built'] ),
				'in'    => (int) ( $per[ $key ]['in'] ?? 0 ),
				'out'   => (int) ( $per[ $key ]['out'] ?? 0 ),
			];
			if ( $keep( $row ) ) {
				$out[] = $row;
			}
		}
		usort(
			$out,
			static fn( array $a, array $b ): int => [ $a[ $by ], -$a['words'] ] <=> [ $b[ $by ], -$b['words'] ]
		);
		return array_slice( $out, 0, max( 1, $limit ) );
	}

	/** Everything that points at one page, with the words it was made of. */
	public static function inbound( string $kind, int $id ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
				'SELECT from_kind, from_id, anchor FROM ' . self::table() . ' WHERE to_kind = %s AND to_id = %d',
				$kind,
				$id
			),
			ARRAY_A
		);
	}

	// =========================================================================
	// What the graph is short of
	// =========================================================================

	/**
	 * How many other pages should point at one page.
	 *
	 * A figure, not a setting: "il est difficile pour moi de donner des
	 * chiffres exacts". Three is the smallest number that makes a page part of
	 * the site rather than a leaf hanging off one link — one link can be a
	 * menu, two can be a coincidence. It is written on the screen so nobody
	 * has to guess what the list is measuring against.
	 */
	public const WANT_IN = 3;

	/** How many candidates the cheap pass hands to the judgment. */
	private const SHORTLIST = 24;

	/** How many sources one page is offered at a time. */
	public const OFFER = 6;

	/** A text shorter than this has nowhere to put a link that reads well. */
	private const MIN_WORDS = 120;

	/** How long a judgment is kept. A site does not change its subject. */
	private const PICK_TTL = 30 * DAY_IN_SECONDS;

	/** What one page of the mesh is short of, in words. '' when it is fine. */
	public static function short_said( array $row ): string {
		$in = (int) ( $row['in'] ?? 0 );
		if ( $in >= self::WANT_IN ) {
			return '';
		}
		if ( 0 === $in ) {
			return __( 'nobody points at it', 'dazont-ecom' );
		}
		return sprintf(
			/* translators: 1: links it has, 2: links it should have */
			_n( '%1$d of %2$d pages point at it', '%1$d of %2$d pages point at it', $in, 'dazont-ecom' ),
			$in,
			self::WANT_IN
		);
	}

	/**
	 * Every page short of inbound links, worst first.
	 *
	 * This is the list the module exists for: "ne jamais délaisser une page
	 * entièrement". A page nobody points at is first, then the one page nobody
	 * but one points at, and among equals the longest text — the one with the
	 * most to lose by being unreachable.
	 */
	public static function needs( int $limit = 200 ): array {
		return self::ranked(
			static fn( array $r ): bool => $r['in'] < self::WANT_IN,
			'in',
			$limit
		);
	}

	// =========================================================================
	// Which page should point at which
	// =========================================================================

	/**
	 * How much each word says about what a page is about.
	 *
	 * A tactical shop calls half its pages "tactical", so two pages sharing
	 * that word share nothing: counting shared words flat is how "Tactical
	 * bags" and "Tactical gloves" came out as close relatives. A word carried
	 * by more than a quarter of the site says nothing and weighs nothing; a
	 * word on two pages weighs double.
	 *
	 * @return array{df:array<string,int>,total:int}
	 */
	public static function vocab( array $pages ): array {
		$df = [];
		foreach ( $pages as $p ) {
			foreach ( self::stems( (string) $p['title'] ) as $s ) {
				$df[ $s ] = ( $df[ $s ] ?? 0 ) + 1;
			}
		}
		return [ 'df' => $df, 'total' => max( 1, count( $pages ) ) ];
	}

	/** tokens with a plural trim — the shop's own, so there is one of them. */
	public static function stems( string $s ): array {
		return class_exists( 'DZE_Category_Content' )
			? DZE_Category_Content::stems( $s )
			: array_values( array_unique( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $s ), -1, PREG_SPLIT_NO_EMPTY ) ?: [] ) );
	}

	/** What two pages have in common, weighed word by word. */
	public static function weigh( array $a, array $b, array $vocab ): float {
		$score = 0.0;
		$total = (int) $vocab['total'];
		foreach ( array_intersect( $a, $b ) as $word ) {
			$seen = (int) ( $vocab['df'][ $word ] ?? 1 );
			if ( $seen > max( 2, (int) floor( $total / 4 ) ) ) {
				continue; // said everywhere, so it says nothing here.
			}
			$score += $seen <= 2 ? 2.0 : 1.0;
		}
		return $score;
	}

	/** Are these two categories on the same branch of the tree? */
	private static function same_branch( array $a, array $b ): bool {
		if ( 'product_cat' !== $a['kind'] || 'product_cat' !== $b['kind'] ) {
			return false;
		}
		$pa = (int) ( get_term( $a['id'], 'product_cat' )->parent ?? 0 );
		$pb = (int) ( get_term( $b['id'], 'product_cat' )->parent ?? 0 );
		return ( $pa && $pa === (int) $b['id'] ) || ( $pb && $pb === (int) $a['id'] ) || ( $pa && $pa === $pb );
	}

	/** The edges that already exist, as "from|to". */
	public static function edges(): array {
		global $wpdb;
		$out = [];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		foreach ( (array) $wpdb->get_results( 'SELECT from_kind, from_id, to_kind, to_id FROM ' . self::table(), ARRAY_A ) as $r ) {
			$out[ $r['from_kind'] . ':' . (int) $r['from_id'] . '|' . $r['to_kind'] . ':' . (int) $r['to_id'] ] = true;
		}
		return $out;
	}

	/**
	 * The pages that COULD point at one page, closest first — the cheap pass.
	 *
	 * Cheap on purpose: it reads titles, not texts, and it is generous. Its
	 * job is to hand the judgment two dozen candidates rather than the whole
	 * site; deciding which of them genuinely belong is the next step's.
	 *
	 * @return array<int,array{key:string,kind:string,id:int,title:string,url:string,score:float}>
	 */
	public static function shortlist( string $to_key, int $limit = self::SHORTLIST ): array {
		$pages = self::pages();
		$to    = $pages[ $to_key ] ?? null;
		if ( ! $to ) {
			return [];
		}
		$vocab = self::vocab( $pages );
		$want  = self::stems( (string) $to['title'] );
		$edges = self::edges();
		$out   = [];
		foreach ( $pages as $key => $p ) {
			if ( $key === $to_key ) {
				continue;
			}
			// A page laid out by a builder is not a page this module can write
			// into, so it is never offered as a source. It is still a perfectly
			// good TARGET, which is why it is only excluded here.
			if ( ! empty( $p['built'] ) ) {
				continue;
			}
			if ( (int) $p['words'] < self::MIN_WORDS ) {
				continue; // nowhere to put a sentence that reads.
			}
			if ( isset( $edges[ $key . '|' . $to_key ] ) ) {
				continue; // it already points there.
			}
			$score = self::weigh( self::stems( (string) $p['title'] ), $want, $vocab );
			if ( self::same_branch( $p, $to ) ) {
				$score += 2.0; // a parent and its child are related whatever they are called.
			}
			if ( $score <= 0 ) {
				continue;
			}
			$out[] = [
				'key'   => $key,
				'kind'  => (string) $p['kind'],
				'id'    => (int) $p['id'],
				'title' => (string) $p['title'],
				'url'   => (string) $p['url'],
				'score' => $score,
			];
		}
		usort( $out, static fn( array $a, array $b ): int => [ $b['score'], $a['title'] ] <=> [ $a['score'], $b['title'] ] );
		return array_slice( $out, 0, max( 1, $limit ) );
	}

	/** The instruction the judgment is made under. Not a setting: a format. */
	public static function pick_system(): string {
		return "You choose internal links for an online shop. You are given ONE target page and a list of candidate pages that could link to it.\n"
			. "Keep only the candidates whose subject genuinely overlaps the target's, so that a link would read as help rather than as advertising. Judge the subjects, not the wording: two pages can share a word and be about different things, and two pages can share none and be about the same thing.\n"
			. "Keep none rather than a weak one. Never keep more than asked.\n"
			. 'Answer with JSON only: [{"n":<candidate number>,"why":"<six words on what they have in common>"}], best first, no other text.';
	}

	/**
	 * Which pages SHOULD point at this one, and why.
	 *
	 * The shortlist is words; this is judgment. One cheap call per page, kept
	 * for a month, because what a page is about does not change on a Tuesday.
	 * With no key, no budget or no answer, the shortlist stands on its own and
	 * the screen says which of the two it is looking at.
	 *
	 * @return array{how:string,rows:array<int,array{key:string,title:string,url:string,kind:string,why:string}>}
	 */
	public static function pairs_for( string $to_key, int $limit = self::OFFER ): array {
		$short = self::shortlist( $to_key );
		$plain = [];
		foreach ( array_slice( $short, 0, $limit ) as $row ) {
			$plain[] = [ 'key' => $row['key'], 'title' => $row['title'], 'url' => $row['url'], 'kind' => $row['kind'], 'why' => '' ];
		}
		if ( ! $short ) {
			return [ 'how' => 'none', 'rows' => [] ];
		}
		$pages = self::pages();
		$to    = $pages[ $to_key ] ?? null;
		if ( ! $to ) {
			return [ 'how' => 'none', 'rows' => [] ];
		}
		$stamp = 'dze_mesh_pick_' . md5( $to_key . '|' . $limit . '|' . implode( ',', wp_list_pluck( $short, 'key' ) ) );
		$kept  = get_transient( $stamp );
		if ( is_array( $kept ) ) {
			return [ 'how' => 'read', 'rows' => self::rows_from( $kept, $short ) ];
		}
		if ( ! class_exists( 'DZE_Marketing_Ai' ) || '' === (string) DZE_Marketing_Ai::api_key() ) {
			return [ 'how' => 'words', 'rows' => $plain ];
		}
		$lines = [];
		foreach ( $short as $i => $row ) {
			$lines[] = ( $i + 1 ) . '. ' . $row['title'] . ' (' . self::kind_word( $row['kind'] ) . ')';
		}
		$user = 'TARGET PAGE: ' . $to['title'] . ' (' . self::kind_word( (string) $to['kind'] ) . ")\n"
			. 'KEEP AT MOST: ' . (int) $limit . "\n\nCANDIDATES:\n" . implode( "\n", $lines );
		try {
			$answer = DZE_Marketing_Ai::complete( self::pick_system(), $user, self::model(), 700, 45 );
		} catch ( Throwable $e ) {
			return [ 'how' => 'words', 'rows' => $plain ];
		}
		$picked = self::read_pick( $answer, $short, $limit );
		if ( ! $picked ) {
			return [ 'how' => 'words', 'rows' => $plain ];
		}
		if ( class_exists( 'DZE_Ai_Usage' ) ) {
			DZE_Ai_Usage::unit( 'mesh_pick' );
		}
		set_transient( $stamp, $picked, self::PICK_TTL );
		return [ 'how' => 'read', 'rows' => self::rows_from( $picked, $short ) ];
	}

	/** The cheap model, the same one the shop's other sifting pass uses. */
	private static function model(): string {
		return class_exists( 'DZE_Category_Content' ) && method_exists( 'DZE_Category_Content', 'sift_model' )
			? DZE_Category_Content::sift_model()
			: 'claude-haiku-4-5-20251001';
	}

	/** A kind, in the words WordPress and WooCommerce use for it. */
	public static function kind_word( string $kind ): string {
		if ( 'product_cat' === $kind ) {
			return __( 'product category', 'dazont-ecom' );
		}
		return 'post' === $kind ? __( 'post', 'dazont-ecom' ) : __( 'page', 'dazont-ecom' );
	}

	/**
	 * The model's answer, turned back into candidates.
	 *
	 * It answers with numbers into a list WE wrote, so a number outside it is
	 * dropped rather than guessed at, and an answer that is not JSON at all
	 * leaves the shortlist standing.
	 *
	 * @return array<int,array{key:string,why:string}>
	 */
	public static function read_pick( string $answer, array $short, int $limit ): array {
		$json = trim( $answer );
		if ( preg_match( '/\[.*\]/s', $json, $m ) ) {
			$json = $m[0];
		}
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) ) {
			return [];
		}
		$out  = [];
		$seen = [];
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$n = (int) ( $r['n'] ?? 0 );
			if ( $n < 1 || $n > count( $short ) || isset( $seen[ $n ] ) ) {
				continue;
			}
			$seen[ $n ] = true;
			$out[]      = [
				'key' => (string) $short[ $n - 1 ]['key'],
				'why' => trim( wp_strip_all_tags( (string) ( $r['why'] ?? '' ) ) ),
			];
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/** Kept choices, back to rows the screen can draw. */
	private static function rows_from( array $picked, array $short ): array {
		$by = [];
		foreach ( $short as $row ) {
			$by[ $row['key'] ] = $row;
		}
		$out = [];
		foreach ( $picked as $p ) {
			$row = $by[ $p['key'] ] ?? null;
			if ( $row ) {
				$out[] = [
					'key'   => (string) $row['key'],
					'title' => (string) $row['title'],
					'url'   => (string) $row['url'],
					'kind'  => (string) $row['kind'],
					'why'   => (string) $p['why'],
				];
			}
		}
		return $out;
	}

	// =========================================================================
	// Putting the link there
	// =========================================================================

	/**
	 * Sends one source page off to have a link written into it.
	 *
	 * There is no second linking engine here, and there must never be one: the
	 * text is written by the same pass the category screen and the automation
	 * already use, held for review like everything else this plugin writes.
	 * All this decides is WHICH page and WHICH target — which is the half that
	 * was missing.
	 *
	 * @return string '' when it was queued, else why it was not.
	 */
	public static function queue_link( string $from_key, array $to_urls ): string {
		if ( ! class_exists( 'DZE_Queue' ) ) {
			return __( 'The review queue is switched off.', 'dazont-ecom' );
		}
		$pages = self::pages();
		$from  = $pages[ $from_key ] ?? null;
		if ( ! $from ) {
			return __( 'That page is not part of the mesh.', 'dazont-ecom' );
		}
		if ( ! empty( $from['built'] ) ) {
			return __( 'That page is laid out by a page builder: its text is not in the post, so a link written there would never appear.', 'dazont-ecom' );
		}
		$urls = array_values( array_filter( array_map( 'esc_url_raw', $to_urls ) ) );
		if ( ! $urls ) {
			return __( 'Nothing was picked.', 'dazont-ecom' );
		}
		$kind  = 'product_cat' === $from['kind'] ? 'cat_links' : 'post_links';
		$added = DZE_Queue::add( $kind, [ (int) $from['id'] ], false, [ 'urls' => $urls ] );
		return $added ? '' : __( 'That page is already waiting in the queue.', 'dazont-ecom' );
	}

	/** One page of the mesh, found by its address. */
	public static function page_by_url( string $url ): array {
		$key = untrailingslashit( strtok( trim( $url ), '#?' ) );
		foreach ( self::pages() as $p ) {
			if ( '' !== $p['url'] && untrailingslashit( (string) $p['url'] ) === $key ) {
				return $p;
			}
		}
		return [];
	}

	// =========================================================================
	// The screen
	// =========================================================================

	/** The one sentence that says what the list is measured against. */
	public static function rule_said(): string {
		return sprintf(
			/* translators: %d: how many pages should point at each page */
			__( 'Every page should be pointed at by at least %d others.', 'dazont-ecom' ),
			self::WANT_IN
		);
	}

	/** What the last reading found, in words. */
	public static function read_said(): string {
		$c = self::census();
		if ( empty( $c['at'] ) ) {
			return __( 'The site has not been read yet.', 'dazont-ecom' );
		}
		$n = (array) ( $c['counts'] ?? [] );
		return sprintf(
			/* translators: 1: how long ago, 2: pages, 3: links */
			__( 'Read %1$s ago — %2$s pages, %3$s internal links.', 'dazont-ecom' ),
			human_time_diff( (int) $c['at'], time() ),
			number_format_i18n( (int) ( $n['pages'] ?? 0 ) ),
			number_format_i18n( (int) ( $n['links'] ?? 0 ) )
		);
	}

	/** Where one page of the mesh is edited. */
	public static function edit_url( string $kind, int $id ): string {
		return 'product_cat' === $kind
			? admin_url( 'term.php?taxonomy=product_cat&post_type=product&tag_ID=' . $id )
			: admin_url( 'post.php?post=' . $id . '&action=edit' );
	}

	public function assets( string $hook ): void {
		if ( ! class_exists( 'DZE_Diagnostic' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable
		if ( DZE_Diagnostic::MENU_SLUG !== $page || 'linking' !== $tab ) {
			return;
		}
		$v = defined( 'DZE_VERSION' ) ? DZE_VERSION : '1';
		wp_enqueue_script( 'dze-mesh', plugins_url( 'admin/js/mesh.js', DZE_FILE ), [ 'jquery' ], $v, true );
		wp_localize_script( 'dze-mesh', 'dzeMesh', [
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'dze_mesh' ),
			'reading'  => __( 'Reading the site…', 'dazont-ecom' ),
			'read'     => __( 'Read the site again', 'dazont-ecom' ),
			'looking'  => __( 'Looking for the pages that belong next to it…', 'dazont-ecom' ),
			'sending'  => __( 'Sending…', 'dazont-ecom' ),
			'sent'     => __( 'Sent to the writing queue — it comes back on the To review tab.', 'dazont-ecom' ),
			'nopick'   => __( 'Tick at least one page.', 'dazont-ecom' ),
			'none'     => __( 'No page on this site is close enough to link to it. It needs a page written about its subject.', 'dazont-ecom' ),
			'words'    => __( 'Chosen on wording alone — the writing key is not set, so nothing read these pages.', 'dazont-ecom' ),
			'failed'   => __( 'That did not go through. Try again.', 'dazont-ecom' ),
			'add'      => __( 'Send them to the writing queue', 'dazont-ecom' ),
		] );
	}

	/**
	 * The Linking tab.
	 *
	 * One reading, one rule, one list. The row says how far off it is in the
	 * same words the diagnostic uses, and its button OPENS what it is about to
	 * do rather than doing it: the pages that should point at it, each with
	 * what they have in common, all of it a suggestion until it is ticked.
	 */
	public function render_tab(): void {
		$census = self::census();
		$counts = (array) ( $census['counts'] ?? [] );
		$fresh  = ! empty( $census['at'] );
		?>
		<p style="margin:16px 0 4px;">
			<button type="button" class="button button-primary" id="dze-mesh-scan"><?php esc_html_e( 'Read the site again', 'dazont-ecom' ); ?></button>
			<span class="description" id="dze-mesh-state" style="margin-left:10px;"><?php echo esc_html( self::read_said() ); ?></span>
		</p>
		<?php if ( ! $fresh ) : ?>
			<p class="description"><?php esc_html_e( 'Nothing has been read yet, so there is nothing to show. It takes a minute.', 'dazont-ecom' ); ?></p>
			<?php
			return;
		endif;
		$needs = self::needs();
		$ends  = self::dead_ends( 50 );
		?>
		<p style="margin:14px 0 6px;"><strong><?php echo esc_html( self::rule_said() ); ?></strong></p>
		<?php if ( ! $needs ) : ?>
			<p class="description"><?php esc_html_e( 'Every page is pointed at from enough places. Nothing to do here.', 'dazont-ecom' ); ?></p>
		<?php else : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: how many pages */
					esc_html( _n( '%s page is short of that.', '%s pages are short of that.', count( $needs ), 'dazont-ecom' ) ),
					esc_html( number_format_i18n( count( $needs ) ) )
				);
				?>
			</p>
			<table class="wp-list-table widefat fixed striped" id="dze-mesh-needs">
				<thead><tr>
					<th style="width:44%;"><?php esc_html_e( 'Page', 'dazont-ecom' ); ?></th>
					<th style="width:18%;"><?php esc_html_e( 'What it is', 'dazont-ecom' ); ?></th>
					<th style="width:20%;"><?php esc_html_e( 'Links to it', 'dazont-ecom' ); ?></th>
					<th><?php esc_html_e( 'Action', 'dazont-ecom' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $needs as $row ) : ?>
					<tr data-key="<?php echo esc_attr( $row['kind'] . ':' . $row['id'] ); ?>">
						<td>
							<a href="<?php echo esc_url( self::edit_url( (string) $row['kind'], (int) $row['id'] ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
							<?php if ( ! empty( $row['url'] ) ) : ?>
								<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener" style="margin-left:6px;text-decoration:none;" title="<?php esc_attr_e( 'Open the page', 'dazont-ecom' ); ?>">&#8599;</a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( self::kind_word( (string) $row['kind'] ) ); ?></td>
						<td class="dze-mesh-short"><?php echo esc_html( self::short_said( $row ) ); ?></td>
						<td><button type="button" class="button button-small dze-mesh-pairs"><?php esc_html_e( 'Link to it', 'dazont-ecom' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2 style="margin-top:28px;"><?php esc_html_e( 'Pages that point at nothing', 'dazont-ecom' ); ?></h2>
		<?php if ( ! $ends ) : ?>
			<p class="description"><?php esc_html_e( 'Every page sends its reader somewhere. Nothing to do here.', 'dazont-ecom' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'A page that links to nothing keeps every reader it gets. Pages laid out by a page builder are left out: their text is not in the post, so nothing can be written into them.', 'dazont-ecom' ); ?></p>
			<table class="wp-list-table widefat fixed striped" id="dze-mesh-ends">
				<thead><tr>
					<th style="width:44%;"><?php esc_html_e( 'Page', 'dazont-ecom' ); ?></th>
					<th style="width:18%;"><?php esc_html_e( 'What it is', 'dazont-ecom' ); ?></th>
					<th style="width:20%;"><?php esc_html_e( 'Words', 'dazont-ecom' ); ?></th>
					<th><?php esc_html_e( 'Action', 'dazont-ecom' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $ends as $row ) : ?>
					<tr data-key="<?php echo esc_attr( $row['kind'] . ':' . $row['id'] ); ?>">
						<td><a href="<?php echo esc_url( self::edit_url( (string) $row['kind'], (int) $row['id'] ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a></td>
						<td><?php echo esc_html( self::kind_word( (string) $row['kind'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['words'] ) ); ?></td>
						<td><button type="button" class="button button-small dze-mesh-out"><?php esc_html_e( 'Add internal links', 'dazont-ecom' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/** The pages that should point at one page, for the row that asked. */
	public static function ajax_pairs(): void {
		check_ajax_referer( 'dze_mesh', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$res = self::pairs_for( $key );
		wp_send_json_success( $res );
	}

	/** Sends the ticked source pages off to have the link written. */
	public static function ajax_queue(): void {
		check_ajax_referer( 'dze_mesh', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$to    = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$from  = isset( $_POST['from'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['from'] ) ) : [];
		$pages = self::pages();
		$url   = (string) ( $pages[ $to ]['url'] ?? '' );
		if ( '' === $url || ! $from ) {
			wp_send_json_error( [ 'message' => __( 'Nothing was picked.', 'dazont-ecom' ) ] );
		}
		$sent = 0;
		$why  = '';
		foreach ( $from as $one ) {
			$said = self::queue_link( $one, [ $url ] );
			if ( '' === $said ) {
				$sent++;
			} elseif ( '' === $why ) {
				$why = $said;
			}
		}
		if ( ! $sent ) {
			wp_send_json_error( [ 'message' => $why ?: __( 'Nothing could be queued.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'sent' => $sent, 'why' => $why ] );
	}

	/** The ordinary linking pass on one page that points at nothing. */
	public static function ajax_out(): void {
		check_ajax_referer( 'dze_mesh', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$pages = self::pages();
		$page  = $pages[ $key ] ?? null;
		if ( ! $page || ! class_exists( 'DZE_Queue' ) ) {
			wp_send_json_error( [ 'message' => __( 'That page is not part of the mesh.', 'dazont-ecom' ) ] );
		}
		$kind  = 'product_cat' === $page['kind'] ? 'cat_links' : 'post_links';
		$added = DZE_Queue::add( $kind, [ (int) $page['id'] ] );
		if ( ! $added ) {
			wp_send_json_error( [ 'message' => __( 'That page is already waiting in the queue.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'sent' => 1 ] );
	}
}
