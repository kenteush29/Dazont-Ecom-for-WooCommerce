<?php
/**
 * The shop's own link graph, and what it is asked.
 *
 * Run before every release:  php tools/test-mesh.php dazont-ecom
 *
 * Everything here exists because a counter is not a graph. Three places in
 * this plugin counted links and not one of them could say who pointed at
 * whom, so a page could be written, translated and forgotten with nothing
 * anywhere noticing that it was reachable from a menu and from nowhere else.
 *
 * The faults this file is red on, each of them real:
 *
 *   - a page laid out by a page builder reads as having no links at all, so
 *     it tops the orphan list for ever and is offered as a place to write
 *     one, where the link would be stored and never appear;
 *   - two shared words was a WALL: a category called "Tactical bags" and its
 *     neighbour "Tactical backpacks" can never share two, so the linking pass
 *     offered two targets on a shop with hundreds of pages;
 *   - counting shared words flat made "tactical" worth as much as "boonie" on
 *     a tactical shop, which is to say it ranked the whole catalogue equal;
 *   - a target picked by hand was dropped for not being in the pool the pass
 *     would have built on its own, so a press answered with nothing.
 */
$dir = $argv[1] ?? 'dazont-ecom';
// A DUMP IS THE SCREEN AND NOTHING ELSE. The tour photographs what this
// prints, and the checks printed above the markup came out as a page of
// "ok ok ok" over the Linking tab. In a dump mode the checks go to stderr.
$dze_dump = in_array( '--dump-tab', $argv, true ) || in_array( '--dump-unchosen', $argv, true );
if ( $dze_dump ) {
	ob_start();
}

define( 'ABSPATH', '/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'DZE_VERSION', 'test' );
define( 'DZE_FILE', __FILE__ );

// --- WordPress, as much of it as the graph touches -------------------------
function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_trim_words( $s, $n = 55, $more = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', (string) $s ), 0, $n ) ) . $more; }
function wp_list_pluck( $rows, $field ) { return array_map( static fn( $r ) => $r[ $field ] ?? null, (array) $rows ); }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function trailingslashit( $s ) { return untrailingslashit( $s ) . '/'; }
function wp_parse_url( $url, $c = -1 ) { return parse_url( (string) $url, $c ); }
function home_url( $p = '/' ) { return 'https://kula.test' . $p; }
function admin_url( $p = '' ) { return 'https://kula.test/wp-admin/' . $p; }
function add_query_arg( $args, $url = '' ) {
	$q = [];
	foreach ( (array) $args as $k => $v ) { $q[] = $k . '=' . rawurlencode( (string) $v ); }
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $q );
}
function plugins_url( $p = '', $f = '' ) { return 'https://kula.test/wp-content/plugins/' . $p; }
function current_time( $t ) { return '2026-01-01 00:00:00'; }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function number_format_i18n( $n ) { return (string) $n; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function do_action( ...$a ) {}
/**
 * WPML, answering the way WPML answers.
 *
 * `wpml_element_language_details` is asked with WPML'S OWN name for the kind
 * and WPML's own id: 'post_page' with a post id, 'tax_product_cat' with a
 * TERM TAXONOMY id. Asked by any other name it answers NOTHING — which is
 * exactly what happened, and "nothing" fell through to "the default
 * language", so every category in every language passed for an English one.
 */
function apply_filters( $tag, $value = null, ...$a ) {
	if ( 'wpml_default_language' === $tag ) {
		return $GLOBALS['deflang'] ?? '';
	}
	if ( 'wpml_element_language_details' === $tag ) {
		$ask  = (array) ( $a[0] ?? [] );
		$type = (string) ( $ask['element_type'] ?? '' );
		$id   = (int) ( $ask['element_id'] ?? 0 );
		$GLOBALS['asked_wpml'][] = $type . ':' . $id;
		$lang = $GLOBALS['langof'][ $type ][ $id ] ?? '';
		return '' !== $lang ? [ 'language_code' => $lang ] : null;
	}
	return $value;
}
function wp_next_scheduled( $h ) { return false; }
function wp_schedule_event( ...$a ) {}
function wp_unschedule_event( ...$a ) {}
function is_admin() { return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_kses_post( $s ) { return (string) $s; }
function absint( $v ) { return abs( (int) $v ); }
function wp_unslash( $v ) { return $v; }
function current_user_can( $c ) { return true; }
function wp_enqueue_script( ...$a ) {}
function wp_localize_script( ...$a ) {}
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function get_locale() { return 'en_US'; }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function checked( $a, $b = true, $echo = true ) { return $a == $b ? " checked='checked'" : ''; }
function selected( $a, $b = true, $echo = true ) { return $a == $b ? " selected='selected'" : ''; }

class WP_Error { public function __construct( ...$a ) {} }
function is_wp_error( $t ) { return $t instanceof WP_Error; }

$GLOBALS['tr']   = [];
$GLOBALS['opts'] = [];
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); return true; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $auto = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }

// --- A shop with a mesh in it ---------------------------------------------
// Four categories on two branches, two articles, one page laid out with a
// page builder. The builder page is the one everything used to get wrong.
$GLOBALS['terms'] = [
	// Long enough to carry a sentence: a page with nowhere to put a link that
	// reads is never offered as a place to write one.
	10 => [ 'name' => 'Tactical bags',      'slug' => 'tactical-bags',      'parent' => 0, 'description' => '<p>Bags for the field. ' . str_repeat( 'a word about bags ', 40 ) . '</p>' ],
	11 => [ 'name' => 'Tactical backpacks', 'slug' => 'tactical-backpacks', 'parent' => 10, 'description' => '<p>Backpacks, one hundred words about them, and <a href="https://kula.test/category/tactical-bags/">Tactical bags</a> above.</p>' ],
	12 => [ 'name' => 'Boonie hats',        'slug' => 'boonie-hats',        'parent' => 0, 'description' => '<p>Hats for the sun.</p>' ],
	// Long enough to be a source, and it points at an article — a pair that
	// shares no word and no branch, which is the only way to see reciprocity
	// on its own.
	13 => [ 'name' => 'Tactical gloves',    'slug' => 'tactical-gloves',    'parent' => 0, 'description' => '<p>' . str_repeat( 'a word about gloves ', 40 ) . '<a href="https://kula.test/blog/21/">Boonie hat sizing</a></p>' ],
	// "SMERSH VESTS — c'est une catégorie sans produits. Ça doit être filtré."
	// A dead shelf, and beside it the shape that a rule written on a term's
	// OWN count would destroy: a parent holding nothing itself whose child
	// holds the stock. Half the branches of a real shop look like that.
	14 => [ 'name' => 'Smersh vests',       'slug' => 'smersh-vests',       'parent' => 0, 'count' => 0, 'description' => '<p>' . str_repeat( 'a vest word ', 40 ) . '</p>' ],
	15 => [ 'name' => 'Tactical footwear',  'slug' => 'tactical-footwear',  'parent' => 0, 'count' => 0, 'description' => '<p>' . str_repeat( 'a footwear word ', 40 ) . '</p>' ],
	16 => [ 'name' => 'Combat boots',       'slug' => 'combat-boots',       'parent' => 15, 'count' => 7, 'description' => '<p>' . str_repeat( 'a boot word ', 40 ) . '</p>' ],
];
$GLOBALS['posts'] = [
	20 => [ 'type' => 'post', 'title' => 'How to choose a tactical backpack', 'content' => '<p>Choosing a pack is a matter of load and season. A boonie hat rides on the strap. ' . str_repeat( 'a backpack word ', 90 ) . '<a href="https://kula.test/category/tactical-backpacks/">Tactical backpacks</a></p>' ],
	21 => [ 'type' => 'post', 'title' => 'Boonie hat sizing',                 'content' => '<p>' . str_repeat( 'a hat word ', 90 ) . '</p>' ],
	22 => [ 'type' => 'page', 'title' => 'About the boonie workshop',         'content' => '' ],
	// A builder page that points at nothing: the one shape that used to sit at
	// the top of both lists at once, unlinkable and blamed for it.
	23 => [ 'type' => 'page', 'title' => 'Workshop history',                  'content' => '' ],
	// A builder page that ALSO keeps a copy of its words in the post — which is
	// what half the themes that use a builder do. It is long enough to owe
	// links by the shop's own rule, and writing them into post_content would
	// put them somewhere no reader ever sees: the only thing keeping it off
	// the list is that it is a builder page.
	30 => [ 'type' => 'page', 'title' => 'Workshop tour', 'content' => '<p>' . str_repeat( 'a word about the workshop ', 60 ) . '</p>' ],
	// "Les pages vides ou les brouillons sont à exclure sans discussion."
	// A published page holding nothing at all, and a draft holding plenty.
	25 => [ 'type' => 'page', 'title' => 'Nothing on it yet',   'content' => '' ],
	26 => [ 'type' => 'post', 'title' => 'A draft about hats',  'content' => '<p>' . str_repeat( 'a draft word ', 90 ) . '</p>', 'status' => 'draft' ],
];
$GLOBALS['meta'] = [
	22 => [ '_elementor_data' => '[{"elType":"widget","settings":{"link":{"url":"https:\/\/kula.test\/category\/boonie-hats\/"}}}]' ],
	23 => [ '_elementor_data' => '[{"elType":"widget","settings":{"title":"Since 1998"}}]' ],
	30 => [ '_elementor_data' => '[{"elType":"widget","settings":{"title":"Come and see"}}]' ],
];

function get_terms( $args = [] ) {
	$out = [];
	foreach ( $GLOBALS['terms'] as $id => $t ) {
		if ( isset( $args['parent'] ) && (int) $t['parent'] !== (int) $args['parent'] ) { continue; }
		// child_of is the whole branch below a term, at any depth — NOT every
		// term there is. Answered as "everything", the pool called the entire
		// catalogue a sub-category, which is close by construction, and the
		// closeness gate this file exists to test never ran at all.
		if ( isset( $args['child_of'] ) && (int) $t['parent'] !== (int) $args['child_of'] ) { continue; }
		if ( ! empty( $args['exclude'] ) && in_array( $id, (array) $args['exclude'], true ) ) { continue; }
		$out[] = get_term( $id, 'product_cat' );
	}
	return $out;
}
function get_term( $id, $tax = '' ) {
	$t = $GLOBALS['terms'][ (int) $id ] ?? null;
	// A term taxonomy id that is NOT the term id, because on a real shop they
	// part company and WPML indexes by the second one.
	return $t ? (object) array_merge( $t, [
		'term_id'          => (int) $id,
		'term_taxonomy_id' => (int) $id + 500,
		'taxonomy'         => 'product_cat',
		'count'            => array_key_exists( 'count', $t ) ? (int) $t['count'] : 5,
	] ) : null;
}
function get_term_link( $t ) {
	$id = is_object( $t ) ? (int) $t->term_id : (int) $t;
	return 'https://kula.test/category/' . ( $GLOBALS['terms'][ $id ]['slug'] ?? '' ) . '/';
}
// How many products sit behind a category — what the shop's own rule measures a
// category's link quota against. It answers `found_posts`, which is the shape
// the reader reads.
class WP_Query {
	public int $found_posts = 0;
	public function __construct( $args = [] ) {
		$this->found_posts = (int) ( $GLOBALS['products_behind'] ?? 12 );
	}
}
function get_term_children( $id, $tax = '' ) {
	$out = [];
	foreach ( $GLOBALS['terms'] as $tid => $t ) {
		if ( (int) $t['parent'] === (int) $id ) { $out[] = $tid; }
	}
	return $out;
}
function get_term_meta( ...$a ) { return ''; }
function update_term_meta( ...$a ) { return true; }
function get_post( $id ) {
	$p = $GLOBALS['posts'][ (int) $id ] ?? null;
	return $p ? (object) [ 'ID' => (int) $id, 'post_title' => $p['title'], 'post_content' => $p['content'], 'post_type' => $p['type'] ] : null;
}
function get_post_type( $id ) { return $GLOBALS['posts'][ (int) $id ]['type'] ?? ''; }
function get_permalink( $id ) { return 'https://kula.test/' . ( 'page' === get_post_type( $id ) ? '' : 'blog/' ) . $id . '/'; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['meta'][ (int) $id ][ $key ] ?? ''; }
function wc_get_page_id( $w ) { return 0; }

/** The posts table, and the mesh's own. */
class DZE_Mesh_Wpdb {
	public $prefix = 'wp_';
	public $posts  = 'wp_posts';
	public $rows   = [];
	public $queries = [];
	public $terms         = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	// LES TERMES, LUS EN TABLE. term_row() passe par ici plutot que par
	// get_term(), que WPML filtre sur la langue courante : $GLOBALS['terms']
	// tient lieu de wp_terms + wp_term_taxonomy pour ces epreuves.
	public function get_row( $q, $output = ARRAY_A ) {
		if ( preg_match( '/FROM .*terms .*term_id = (\d+)/is', (string) $q, $m ) ) {
			$t = $GLOBALS['terms'][ (int) $m[1] ] ?? null;
			if ( ! $t ) { return null; }
			$row = [
				'term_id'          => (int) $m[1],
				'name'             => (string) ( $t['name'] ?? '' ),
				'slug'             => (string) ( $t['slug'] ?? '' ),
				// La meme regle que la doublure get_term de ce fichier, sinon les
				// deux chemins de lecture divergent et l epreuve mesure la doublure.
				'term_taxonomy_id' => (int) ( $t['term_taxonomy_id'] ?? ( (int) $m[1] + 500 ) ),
				'taxonomy'         => (string) ( $t['taxonomy'] ?? 'product_cat' ),
				'parent'           => (int) ( $t['parent'] ?? 0 ),
				'count'            => (int) ( $t['count'] ?? 0 ),
				'description'      => (string) ( $t['description'] ?? '' ),
			];
			return ARRAY_A === $output ? $row : (object) $row;
		}
		return null;
	}
	public function get_charset_collate() { return ''; }	public function prepare( $q, ...$a ) {
		foreach ( $a as $one ) {
			$q = preg_replace( '/%[dsf]/', is_int( $one ) ? (string) $one : "'" . $one . "'", (string) $q, 1 );
		}
		return $q;
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( 0 === stripos( $sql, 'TRUNCATE' ) ) { $this->rows = []; return true; }
		if ( preg_match( '/INSERT INTO \S+ \(from_kind,from_id,to_kind,to_id,anchor,seen\) VALUES (.*)$/is', $sql, $m ) ) {
			preg_match_all( "/\('([^']*)',(\d+),'([^']*)',(\d+),'([^']*)','([^']*)'\)/", $m[1], $all, PREG_SET_ORDER );
			foreach ( $all as $one ) {
				$this->rows[] = [ 'from_kind' => $one[1], 'from_id' => (int) $one[2], 'to_kind' => $one[3], 'to_id' => (int) $one[4], 'anchor' => $one[5] ];
			}
		}
		return true;
	}
	public function get_results( $sql, $out = ARRAY_A ) {
		if ( false !== stripos( $sql, 'FROM wp_posts' ) ) {
			$rows = [];
			$only = false !== stripos( $sql, "post_status = 'publish'" );
			foreach ( $GLOBALS['posts'] as $id => $p ) {
				// THE STUB HONOURS post_status, or "a draft is never in the
				// graph" is a rule nothing here can be red on.
				if ( $only && 'publish' !== ( $p['status'] ?? 'publish' ) ) {
					continue;
				}
				$rows[] = [
					'ID'           => $id,
					'post_title'   => $p['title'],
					'post_name'    => strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $p['title'] ) ),
					'post_type'    => $p['type'],
					'post_content' => $p['content'],
				];
			}
			return $rows;
		}
		if ( false !== stripos( $sql, 'GROUP BY to_kind' ) ) {
			$n = [];
			foreach ( $this->rows as $r ) { $n[ $r['to_kind'] . '|' . $r['to_id'] ] = ( $n[ $r['to_kind'] . '|' . $r['to_id'] ] ?? 0 ) + 1; }
			$out = [];
			foreach ( $n as $k => $c ) { [ $kind, $id ] = explode( '|', $k ); $out[] = [ 'to_kind' => $kind, 'to_id' => $id, 'n' => $c ]; }
			return $out;
		}
		if ( false !== stripos( $sql, 'GROUP BY from_kind' ) ) {
			$n = [];
			foreach ( $this->rows as $r ) { $n[ $r['from_kind'] . '|' . $r['from_id'] ] = ( $n[ $r['from_kind'] . '|' . $r['from_id'] ] ?? 0 ) + 1; }
			$out = [];
			foreach ( $n as $k => $c ) { [ $kind, $id ] = explode( '|', $k ); $out[] = [ 'from_kind' => $kind, 'from_id' => $id, 'n' => $c ]; }
			return $out;
		}
		if ( false !== stripos( $sql, 'from_kind, from_id, to_kind, to_id FROM' ) ) {
			return $this->rows;
		}
		if ( false !== stripos( $sql, 'WHERE to_kind' ) ) {
			return $this->rows;
		}
		return [];
	}
	public function get_var( $sql ) {
		return false !== stripos( $sql, 'COUNT(*)' ) ? count( $this->rows ) : null;
	}
	public function insert( $t, $row ) { return true; }
}
$GLOBALS['wpdb'] = new DZE_Mesh_Wpdb();
$wpdb            = $GLOBALS['wpdb'];

/** The queue: what was asked of it, and nothing done. */
class DZE_Queue {
	public static array $added = [];
	/** What the queue holds, by "family:id" => status — the shape pending_for() answers with. */
	public static array $holds = [];
	public static function add( string $kind, array $ids, bool $auto = false, array $payload = [] ): int {
		self::$added[] = [ 'kind' => $kind, 'ids' => $ids, 'payload' => $payload ];
		return count( $ids );
	}
	public static function pending_for( int $object_id, string $family = 'cat_' ): array {
		$st = self::$holds[ $family . $object_id ] ?? '';
		return '' === $st ? [] : [ 'status' => $st, 'id' => 1, 'kind' => $family . 'links' ];
	}
	public static function url( array $args = [] ): string { return 'https://kula.test/wp-admin/admin.php?page=dazont-ecom-diagnostic&tab=review'; }
}
/** The writing service. It answers what the test tells it to. */
class DZE_Marketing_Ai {
	public static string $answer = '';
	public static array $sent = [];
	public static function api_key(): string { return $GLOBALS['key'] ?? ''; }
	public static function get_settings(): array { return []; }
	public static function complete( string $system, string $user, string $model = '', int $max = 0, int $t = 0 ): string {
		self::$sent[] = [ 'system' => $system, 'user' => $user, 'model' => $model ];
		if ( '' === self::$answer ) { throw new RuntimeException( 'no answer' ); }
		return self::$answer;
	}
}
class DZE_Ai_Usage { public static array $units = []; public static function unit( string $u = '' ): void { self::$units[] = $u; } }
/**
 * WPML's own table. `wpml_element_language_details` is a FILTER and only
 * answers where WPML's hooks are loaded; this reading runs in an AJAX action
 * and in cron, where they are not. The table answers everywhere.
 */
class DZE_Wpml {
	public static array $asked = [];
	public static function ids_in_language( string $element_type, string $language ): ?array {
		self::$asked[] = $element_type . ':' . $language;
		$rows = $GLOBALS['icl'][ $element_type ][ $language ] ?? null;
		return null === $rows ? null : array_fill_keys( array_map( 'intval', (array) $rows ), true );
	}
}
class DZE_Keywords_Absent {}
/** The prompt popup's button, as the real one prints it. */
class DZE_Prompts {
	public static function the_button( string $id, string $label = '' ): void {
		echo '<button type="button" class="dze-prompt-peek" data-prompt="' . $id . '">' . $label . '</button>';
	}
}
/** The automatic pass: what the shop set it to. */
class DZE_Automation {
	public static array $conf = [ 'on' => false, 'per_day' => 3, 'apply' => false ];
	public static function conf( string $id ): array { return self::$conf; }
}

require __DIR__ . '/../' . $dir . '/includes/class-blocks.php';
require __DIR__ . '/../' . $dir . '/includes/class-category-content.php';
require __DIR__ . '/../' . $dir . '/includes/class-post-links.php';
require __DIR__ . '/../' . $dir . '/includes/class-hub.php';
require __DIR__ . '/../' . $dir . '/includes/class-mesh.php';
// The catalogue of screens: every sentence naming one is built from it.
require __DIR__ . '/../' . $dir . '/includes/class-screens.php';

$ran   = 0;
$fails = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok   %s\n", $what ); return; }
	$fails++;
	printf( "  WRONG %s\n       got  %s\n       want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}

echo "\nThe links inside a page\n";
$plain = DZE_Mesh::links_in( '<p>See <a href="https://kula.test/a/">Tactical bags</a> and <a href="https://out.test/x">them</a>.</p>' );
ok( 'both anchors are read',            count( $plain ), 2 );
ok( 'with the words they were made of', $plain[0]['anchor'], 'Tactical bags' );
// THE PAGE BUILDER. Its text is not in the post: read from post_content alone
// this page points at nothing at all, and it is the top of every orphan list
// for ever.
$built = DZE_Mesh::links_in( DZE_Mesh::body_of( 'page', 22 ) );
ok( "a builder page's links are read",  count( $built ), 1 );
ok( 'and they point where they point',  $built[0]['url'], 'https://kula.test/category/boonie-hats/' );
ok( 'a page with builder data is marked', DZE_Mesh::built_with_builder( 22 ), true );
ok( 'and an ordinary one is not',       DZE_Mesh::built_with_builder( 20 ), false );

echo "\nAn address, back to a page of this shop\n";
$pages = DZE_Mesh::pages( true );
$byurl = [];
foreach ( $pages as $k => $p ) { if ( '' !== $p['url'] ) { $byurl[ untrailingslashit( $p['url'] ) ] = $k; } }
ok( 'a category address resolves',      DZE_Mesh::resolve( 'https://kula.test/category/boonie-hats/', $byurl ), 'product_cat:12' );
ok( 'a trailing slash changes nothing', DZE_Mesh::resolve( 'https://kula.test/category/boonie-hats', $byurl ), 'product_cat:12' );
ok( 'a fragment is not another page',   DZE_Mesh::resolve( 'https://kula.test/category/boonie-hats/#buy', $byurl ), 'product_cat:12' );
ok( 'another site is not ours to weave', DZE_Mesh::resolve( 'https://amazon.com/x', $byurl ), '' );
ok( 'an anchor is not a link at all',   DZE_Mesh::resolve( '#top', $byurl ), '' );
ok( 'nor is an address to write to',    DZE_Mesh::resolve( 'mailto:a@b.c', $byurl ), '' );

echo "\nThe shop, read once\n";
$counts = DZE_Mesh::scan();
ok( 'every page of the mesh is in it',  $counts['pages'], 11 );
ok( 'and every internal link',          $counts['links'], 4 );
$per = DZE_Mesh::census()['per'];
ok( 'a category knows who points at it', $per['product_cat:11']['in'], 1 );
ok( 'and what it points at',            $per['product_cat:11']['out'], 1 );
ok( "the builder page's link counts",   $per['product_cat:12']['in'], 1 );
// A PAGE NOBODY POINTS AT IS THE WHOLE POINT. Boonie hats is pointed at, by
// the builder page and by nothing else; Tactical gloves is pointed at by
// nobody at all.
$orphans = wp_list_pluck( DZE_Mesh::orphans(), 'title' );
ok( 'the orphans are named',            in_array( 'Tactical gloves', $orphans, true ), true );
ok( 'and a page that is pointed at is not', in_array( 'Boonie hats', $orphans, true ), false );
// A BUILDER PAGE IS NEVER A DEAD END: nothing here writes into one, so it is
// not work this module can offer, and a list of work you cannot do is noise.
$ends = wp_list_pluck( DZE_Mesh::dead_ends(), 'title' );
ok( 'a page that points nowhere is named', in_array( 'Boonie hat sizing', $ends, true ), true );
ok( 'a builder page is not a dead end', in_array( 'Workshop history', $ends, true ), false );
ok( 'and the count agrees with the list', $counts['ends'], count( $ends ) );
// THE TAB'S FIGURE AND THE LIST UNDER IT ARE THE SAME QUESTION. Counting the
// pages nobody points at while listing the pages short of three is a screen
// that disagrees with itself, every day, in front of somebody who has to
// decide which of the two to believe.
ok( 'and so does the one on the tab',   $counts['short'], count( DZE_Mesh::needs() ) );

echo "\nHow far off a page is, in words\n";
ok( 'nobody at all',                    DZE_Mesh::short_said( [ 'in' => 0 ] ), 'nobody points at it' );
ok( 'one of the three it wants',        DZE_Mesh::short_said( [ 'in' => 1 ] ), '1 of 3 pages point at it' );
ok( 'a page that has its share says nothing', DZE_Mesh::short_said( [ 'in' => 3 ] ), '' );

echo "\nWhich word says something about a page\n";
// THE WORD HALF THE SHOP USES. A tactical shop calls everything tactical, so
// counting shared words flat made every page a close relative of every other.
$vocab = DZE_Mesh::vocab( [
	[ 'title' => 'Tactical bags' ], [ 'title' => 'Tactical backpacks' ],
	[ 'title' => 'Tactical gloves' ], [ 'title' => 'Tactical boots' ],
	[ 'title' => 'Boonie hats' ], [ 'title' => 'Boonie hat sizing' ],
	[ 'title' => 'Camo netting' ], [ 'title' => 'Plate carriers' ],
] );
ok( 'a word half the pages use weighs nothing',
	DZE_Mesh::weigh( DZE_Mesh::stems( 'Tactical bags' ), DZE_Mesh::stems( 'Tactical gloves' ), $vocab ), 0.0 );
ok( 'a word two pages share weighs double',
	DZE_Mesh::weigh( DZE_Mesh::stems( 'Boonie hats' ), DZE_Mesh::stems( 'Boonie hat sizing' ), $vocab ), 4.0 );
ok( 'so a rare word outranks a common one',
	DZE_Mesh::weigh( DZE_Mesh::stems( 'Boonie hats' ), DZE_Mesh::stems( 'Boonie hat sizing' ), $vocab )
		> DZE_Mesh::weigh( DZE_Mesh::stems( 'Tactical bags' ), DZE_Mesh::stems( 'Tactical gloves' ), $vocab ), true );

echo "\nWhich pages should point at one\n";
$short = wp_list_pluck( DZE_Mesh::shortlist( 'product_cat:12' ), 'key' );
ok( 'the article on its subject is offered', in_array( 'post:21', $short, true ), true );
ok( 'the page it is already linked from is not', in_array( 'page:22', $short, true ), false );
ok( 'and neither is the page itself',   in_array( 'product_cat:12', $short, true ), false );
// A CATEGORY WITH NO DESCRIPTION HAS NOWHERE TO PUT A SENTENCE. It is a
// perfectly good target and never a source.
ok( 'an empty page is not offered as a source', in_array( 'product_cat:13', $short, true ), false );

// A LINK ALREADY GOING ONE WAY IS THE FIRST OFFERED TO COME BACK. Not a rule
// — a link is not owed back — but two pages, one of which already sends its
// readers to the other, were judged close once already by whoever wrote that
// link. Tactical backpacks points at Tactical bags; asked who should point at
// Tactical backpacks, the answer starts there.
$back = wp_list_pluck( DZE_Mesh::shortlist( 'product_cat:13' ), 'key' );
ok( 'the page it already points at comes first', $back[0] ?? '', 'post:21' );

echo "\nHow many pages nothing points at\n";
//
// "Ce serait bien d'avoir peut-être un système simplifié de comptage ne
// serait-ce que pour avoir un aperçu des pages sans liens entrants." It was
// counted at every scan and stored beside the others, and no screen ever
// answered it.
$dze_orph = DZE_Mesh::orphan_count();
$dze_byhand = 0;
foreach ( DZE_Mesh::pages() as $key => $pg ) {
	// COUNTED THE WAY THE CENSUS COUNTS. A page that takes no part in the
	// linking work is not an orphan waiting to be mended, so a hand count that
	// includes one is not the same question.
	if ( ! DZE_Mesh::in_work( (string) $pg['kind'], (int) $pg['id'] ) ) { continue; }
	if ( 0 === (int) ( DZE_Mesh::census()['per'][ $key ]['in'] ?? 0 ) ) { $dze_byhand++; }
}
ok( 'the figure is the census\'s own',   $dze_orph, $dze_byhand );
ok( 'and it is the same one orphans() lists', $dze_orph, count( DZE_Mesh::orphans( 500 ) ) );
// THE READING SAYS IT, where the reading is stated: one option read, so it
// costs nothing to answer wherever it belongs.
ok( 'the reading states it in words',
	false !== strpos( DZE_Mesh::read_said(), $dze_orph . " not linked from any page's text" ), true );
// A SITE NOT READ YET ANSWERS NULL, NEVER 0. "Nobody points at anything" and
// "nothing has been counted" are different answers, and a nought printed for
// the second is a figure nobody counted.
$GLOBALS['opts']['dze_mesh_census'] = [];
ok( 'never read, never a nought',        DZE_Mesh::orphan_count(), null );
ok( 'and the reading says which empty',  DZE_Mesh::read_said(), 'The site has not been read yet.' );
DZE_Mesh::scan();

echo "\nThe other half: pages under their own outgoing quota\n";
//
// "Puis passe au travail traditionnel que je faisais moi-même à la main ?
// C-à-d de générer des liens sur les pages qui n'ont pas atteint leur quota de
// liens sortants ?" It does now — the same task, a second phase.
DZE_Mesh::forget_thin();
$thin = DZE_Mesh::thin( 50 );
$byid = [];
foreach ( $thin as $r ) { $byid[ $r['kind'] . ':' . $r['id'] ] = $r; }

// THE QUOTA IS ASKED OF WHOEVER OWNS THE RULE, never computed a second time
// here: one link per fifty words is written down in the two modules that place
// them, and a third answer would drift from both.
ok( 'a category is measured by its own rule',
	DZE_Mesh::quota( [ 'kind' => 'product_cat', 'id' => 10, 'words' => 800 ] ),
	(int) DZE_Category_Content::size_for( 10 )['links'] );
ok( 'and an article by its own',
	DZE_Mesh::quota( [ 'kind' => 'post', 'id' => 20, 'words' => 500 ] ),
	(int) DZE_Post_Links::target_links( 500 ) );
// A TEXT TOO SHORT HAS NOWHERE TO PUT A LINK THAT READS WELL.
ok( 'a short text is asked for nothing',
	DZE_Mesh::quota( [ 'kind' => 'post', 'id' => 21, 'words' => 40 ] ), 0 );

// THE PAGES THAT FALL SHORT ARE THE WORK, and the widest gap comes first.
ok( 'the list is not empty',            count( $thin ) > 0, true );
$dze_gaps = wp_list_pluck( $thin, 'short' );
ok( 'every row is really short',        min( $dze_gaps ) >= 1, true );
ok( 'and the widest gap is first',      $dze_gaps[0], max( $dze_gaps ) );
ok( 'each row says how far off it is',
	(int) $thin[0]['short'], (int) $thin[0]['want'] - (int) $thin[0]['out'] );

// A PAGE A BUILDER OWNS IS NEVER A SOURCE — there is nowhere in it to put a
// sentence — and neither is a text too short to carry one.
ok( 'a builder page is not offered',    isset( $byid['page:23'] ), false );
// AND NOT EVEN ONE LONG ENOUGH TO OWE LINKS: page 24 keeps its words in the
// post as well as in the builder, so the rule says it is short — and writing
// into post_content would put those links where no reader ever sees them.
ok( 'nor a long one a builder owns',    isset( $byid['page:30'] ), false );
ok( 'nor an empty one',                 isset( $byid['page:22'] ), false );
// AND A PAGE THAT ALREADY CARRIES ITS SHARE IS NOT WORK.
$dze_full = true;
foreach ( DZE_Mesh::pages() as $key => $pg ) {
	$row  = [ 'kind' => $pg['kind'], 'id' => $pg['id'], 'words' => $pg['words'] ];
	$want = DZE_Mesh::quota( $row );
	$out  = (int) ( DZE_Mesh::census()['per'][ $key ]['out'] ?? 0 );
	if ( $out >= $want && isset( $byid[ $key ] ) ) { $dze_full = false; }
}
ok( 'a page at its quota is left alone', $dze_full, true );

// THE READING IS KEPT: a category's quota counts the products behind it and
// walks its branch, and this list is read on every draw of the screen once the
// orphans are done.
$GLOBALS['tr']['dze_mesh_thin'] = [ [ 'kind' => 'post', 'id' => 99, 'title' => 'kept', 'url' => '', 'in' => 0, 'out' => 0, 'words' => 300, 'want' => 6, 'short' => 6 ] ];
ok( 'and it is read from the cache',    (string) ( DZE_Mesh::thin( 5 )[0]['title'] ?? '' ), 'kept' );
DZE_Mesh::forget_thin();
ok( 'until something says to read again', (string) ( DZE_Mesh::thin( 5 )[0]['title'] ?? '' ) !== 'kept', true );

echo "\nThe day's work, and which way round it reads\n";
//
// "Donc le module commence par linker les pages qui ne sont linkées nulle part ?"
// Yes, and ONLY that — so the row has to say so in the direction the work
// actually goes. It named the page about to be WRITTEN INTO and then said "2
// pages short of links point here", which is the graph read backwards: nothing
// points at those pages, and this one is the neighbour that will point at them.
$GLOBALS['key'] = '';
$plan = DZE_Mesh::plan( 3 );
ok( 'the day has work to hand out',     count( $plan ) > 0, true );
$first = $plan[0] ?? [];
ok( 'the row names a page to write into', '' !== (string) ( $first['name'] ?? '' ), true );
ok( 'and carries what it will point at', count( (array) ( $first['urls'] ?? [] ) ) > 0, true );
// THE SENTENCE READS THE WAY THE WORK GOES.
ok( 'the row says which way round it is',
	(string) ( $first['why'] ?? '' ),
	1 === count( (array) $first['urls'] )
		? 'will link to one page nothing points at'
		: sprintf( 'will link to %d pages nothing points at', count( (array) $first['urls'] ) ) );
// AND WHAT IT POINTS AT IS AN ORPHAN, never a page the site already covers:
// that is the whole of what this pass does, and the only thing it does.
$dze_in = [];
foreach ( DZE_Mesh::needs( 500 ) as $r ) {
	$dze_in[ untrailingslashit( (string) $r['url'] ) ] = true;
}
$dze_all_short = true;
foreach ( $plan as $row ) {
	foreach ( (array) $row['urls'] as $u ) {
		if ( ! isset( $dze_in[ untrailingslashit( (string) $u ) ] ) ) { $dze_all_short = false; }
	}
}
ok( 'every target is a page short of links', $dze_all_short, true );
// A PAGE NEVER LINKS TO ITSELF.
$dze_self = false;
foreach ( $plan as $row ) {
	$mine = untrailingslashit( (string) ( DZE_Mesh::pages()[ $row['kind'] . ':' . $row['id'] ]['url'] ?? '' ) );
	foreach ( (array) $row['urls'] as $u ) {
		if ( untrailingslashit( (string) $u ) === $mine ) { $dze_self = true; }
	}
}
ok( 'and never at itself',              $dze_self, false );

echo "\nWithout a key, the wording stands on its own and says so\n";
$GLOBALS['key'] = '';
$res = DZE_Mesh::pairs_for( 'product_cat:12' );
ok( 'the shortlist is the answer',      $res['how'], 'words' );
ok( 'and it has something in it',       count( $res['rows'] ) > 0, true );

echo "\nWith a key, the shortlist is read\n";
$GLOBALS['key'] = 'sk-test';
DZE_Marketing_Ai::$answer = '[{"n":1,"why":"both about boonie hats"}]';
$res = DZE_Mesh::pairs_for( 'product_cat:12' );
ok( 'the judgment answered',            $res['how'], 'read' );
ok( 'and it kept one page',             count( $res['rows'] ), 1 );
ok( 'with what they have in common',    $res['rows'][0]['why'], 'both about boonie hats' );
ok( 'the reading is charged for',       in_array( 'mesh_pick', DZE_Ai_Usage::$units, true ), true );
ok( 'the target is named in the ask',   false !== strpos( DZE_Marketing_Ai::$sent[0]['user'], 'Boonie hats' ), true );
$asked = count( DZE_Marketing_Ai::$sent );
DZE_Mesh::pairs_for( 'product_cat:12' );
ok( 'and it is not asked twice',        count( DZE_Marketing_Ai::$sent ), $asked );

echo "\nAn answer that is not one\n";
$short = DZE_Mesh::shortlist( 'product_cat:12' );
ok( 'a number outside the list is dropped', DZE_Mesh::read_pick( '[{"n":99}]', $short, 6 ), [] );
ok( 'the same one twice counts once',   count( DZE_Mesh::read_pick( '[{"n":1},{"n":1}]', $short, 6 ) ), 1 );
ok( 'prose is not an answer',           DZE_Mesh::read_pick( 'I think page 1 works well', $short, 6 ), [] );
ok( 'and JSON wrapped in prose is',     count( DZE_Mesh::read_pick( 'Sure: [{"n":1,"why":"x"}] — hope that helps', $short, 6 ) ), 1 );

echo "\nWhat is sent to the writing queue\n";
DZE_Queue::$added = [];
ok( 'a category page is queued',        DZE_Mesh::queue_link( 'product_cat:10', [ 'https://kula.test/category/boonie-hats/' ] ), '' );
ok( 'as the category linking pass',     DZE_Queue::$added[0]['kind'], 'cat_links' );
ok( 'on that category',                 DZE_Queue::$added[0]['ids'], [ 10 ] );
ok( 'carrying the page it must link to', DZE_Queue::$added[0]['payload']['urls'], [ 'https://kula.test/category/boonie-hats/' ] );
DZE_Queue::$added = [];
DZE_Mesh::queue_link( 'post:20', [ 'https://kula.test/category/boonie-hats/' ] );
ok( 'an article goes to the article pass', DZE_Queue::$added[0]['kind'], 'post_links' );
// A BUILDER PAGE IS REFUSED, AND SAYS WHY. Written into, the link would be
// stored, would never appear on the page, and every screen would call it done.
DZE_Queue::$added = [];
$why = DZE_Mesh::queue_link( 'page:22', [ 'https://kula.test/category/boonie-hats/' ] );
ok( 'a builder page is refused',        '' !== $why, true );
ok( 'and nothing was queued',           DZE_Queue::$added, [] );
ok( 'a page that is not in the mesh is refused too',
	'' !== DZE_Mesh::queue_link( 'post:999', [ 'https://kula.test/x/' ] ), true );

echo "\nThe pool the linking pass is given\n";
// TWO SHARED WORDS WAS A WALL. "Tactical bags" and "Tactical backpacks" can
// never share two words, so everything but the branch was thrown away and the
// pass offered two targets on a shop with hundreds of pages.
// The two indexes are cached PER LANGUAGE now: one language's pool handed to
// another is exactly the fault this release is about, so the key carries the
// language it was read in. The harness seeds the key the code will ask for.
$dze_ck = 'dze_cc_cats_' . ( DZE_Category_Content::default_lang() ?: 'x' );
$dze_pk = 'dze_cc_pages_' . ( DZE_Category_Content::default_lang() ?: 'x' );
$GLOBALS['tr'][ $dze_ck ] = [];
foreach ( $GLOBALS['terms'] as $id => $t ) {
	$GLOBALS['tr'][ $dze_ck ][] = [ 'id' => $id, 'name' => $t['name'], 'slug' => $t['slug'], 'url' => get_term_link( $id ) ];
	$GLOBALS['tr'][ 'dze_cc_pcount_' . $id ] = 5;
}
$GLOBALS['tr'][ $dze_pk ] = [
	[ 'id' => 20, 'title' => 'How to choose a tactical backpack', 'slug' => 'choose-backpack', 'url' => get_permalink( 20 ), 'kind' => 'blog post' ],
	[ 'id' => 21, 'title' => 'Boonie hat sizing', 'slug' => 'boonie-sizing', 'url' => get_permalink( 21 ), 'kind' => 'blog post' ],
];
// The rest of the catalogue, which is where a shop's candidates actually come
// from: half of it is called "tactical", and one of them shares the word that
// says something.
foreach ( [ 'Tactical boots', 'Tactical helmets', 'Tactical vests', 'Bag rain covers' ] as $i => $name ) {
	$GLOBALS['tr'][ $dze_ck ][] = [
		'id'   => 30 + $i,
		'name' => $name,
		'slug' => sanitize_key( str_replace( ' ', '-', $name ) ),
		'url'  => 'https://kula.test/category/' . sanitize_key( str_replace( ' ', '-', $name ) ) . '/',
	];
	$GLOBALS['tr'][ 'dze_cc_pcount_' . ( 30 + $i ) ] = 5;
}
$pool  = DZE_Category_Content::link_pool( 10 );
$names = wp_list_pluck( $pool, 'label' );
ok( 'the branch is in the pool',        in_array( 'Tactical backpacks', $names, true ), true );
ok( 'and so is the article about it',   in_array( 'How to choose a tactical backpack', $names, true ), true );
ok( 'a neighbour sharing one word is a candidate', in_array( 'Tactical gloves', $names, true ), true );
ok( 'a category about something else is not', in_array( 'Boonie hats', $names, true ), false );
ok( 'and the pool is more than the two the branch gives', count( $pool ) > 2, true );
// WHICH shared word, not how many. Every candidate but one shares "tactical",
// which on this shop says nothing; the one sharing "bag" is what the category
// is actually about, and it comes first.
ok( 'the rare shared word wins',        $names[0], 'Bag rain covers' );
// LISTED IS NOT TICKED. A press on "Add internal links only" arrived with
// thirty pages ticked — "Tactical Sunglasses", "Tactical Balaclavas" and
// every other page of a tactical shop, because they all carry the word
// "tactical" and one shared word was enough to tick a box.
$ticked = array_values( array_filter( $pool, static fn( array $p ): bool => ! empty( $p['close'] ) ) );
$names_t = wp_list_pluck( $ticked, 'label' );
ok( 'the branch arrives ticked',        in_array( 'Tactical backpacks', $names_t, true ), true );
ok( 'and so does a page sharing a word that says something',
	in_array( 'Bag rain covers', $names_t, true ), true );
ok( 'a page sharing only the shop\'s own word is listed',
	in_array( 'Tactical helmets', $names, true ), true );
ok( 'and it is NOT ticked',             in_array( 'Tactical helmets', $names_t, true ), false );
// AND THE CEILING IS THE PAGE'S OWN. The panel writes it at the top —
// "Target for this category: 700 words, and up to 14 links (one per 50
// words)" — and the list under it ticked thirty. A figure a screen states and
// then does not keep is worse than no figure at all. Asked on a category with
// more candidates than it may carry, which is the shape the shop has.
for ( $i = 0; $i < 8; $i++ ) {
	$GLOBALS['terms'][ 40 + $i ] = [
		'name'        => 'Boonie hats ' . ( $i + 1 ),
		'slug'        => 'boonie-hats-' . ( $i + 1 ),
		'parent'      => 12,
		'description' => '',
	];
	$GLOBALS['tr'][ 'dze_cc_pcount_' . ( 40 + $i ) ] = 5;
}
$GLOBALS['opts']['dze_catcontent_settings'] = [ 'links' => 3 ];
$room = (int) DZE_Category_Content::size_for( 12 )['links'];
$deep = DZE_Category_Content::link_pool( 12 );
ok( 'the panel has a figure to keep',   $room, 3 );
ok( 'and more to choose from than it may carry', count( $deep ) > $room, true );
ok( 'yet nothing is ticked beyond it',
	count( array_filter( $deep, static fn( array $p ): bool => ! empty( $p['close'] ) ) ), $room );
$GLOBALS['opts']['dze_catcontent_settings'] = [];
for ( $i = 0; $i < 8; $i++ ) { unset( $GLOBALS['terms'][ 40 + $i ] ); }

// A CHOSEN TARGET IS A TARGET. The pool would never have offered Boonie hats
// here — no shared word — and the whole Linking screen rests on being able to
// ask for exactly the link the mesh is short of.
$GLOBALS['tr']['dze_mesh_pages'] = DZE_Mesh::pages( true );
$picked = DZE_Category_Content::link_pool( 10 );
ok( 'the pool alone does not offer it', in_array( 'Boonie hats', wp_list_pluck( $picked, 'label' ), true ), false );
ok( 'and the mesh can still find it',   DZE_Mesh::page_by_url( 'https://kula.test/category/boonie-hats/' )['title'] ?? '', 'Boonie hats' );

echo "\nUN COUPLE QUE LE TEXTE NE NOMME PAS NEST PAS PROPOSE\n";
// Un lien ne peut saccrocher quà des mots deja presents. Proposer a un
// article de pointer vers un sujet quil ne nomme jamais fabrique une tache
// qui ne peut pas aboutir : payee au modele, refusee, puis montree a la
// boutique en rouge sans quelle puisse rien y faire.
$GLOBALS['tr']['dze_mesh_pages'] = DZE_Mesh::pages( true );
$dze_plan2 = DZE_Mesh::plan( 5 );
$dze_mauvais = 0;
foreach ( $dze_plan2 as $r ) {
	$txt = DZE_Mesh::body_of( (string) $r['kind'], (int) $r['id'] );
	foreach ( (array) $r['urls'] as $u ) {
		$cible = DZE_Mesh::page_by_url( $u );
		$nom   = (string) ( $cible['title'] ?? '' );
		if ( '' !== $nom && ! DZE_Category_Content::mentions( $txt, $nom ) ) { $dze_mauvais++; }
	}
}
ok( 'le plan ne propose que des couples tenables', $dze_mauvais, 0 );
ok( 'et il propose quand meme du travail',       count( $dze_plan2 ) > 0, true );

echo "\nThe pass that writes the link is given the page that was picked\n";
// THE HALF THAT MAKES THE SCREEN WORK. The pool answers "what would this
// article link to on its own"; the Linking screen asks for the link the MESH
// is short of, and those are not the same question. Dropped for not being in
// the pool, a press on that screen answered with nothing at all.
$GLOBALS['tr']['dze_mesh_pages'] = DZE_Mesh::pages( true );
DZE_Marketing_Ai::$sent   = [];
// The pass asks for WORDS, not the document and not a sentence of HTML: the
// model names a run of words already in the text, and the link is written
// here. A double that hands a document back is answering a question nobody
// asks any more.
DZE_Marketing_Ai::$answer = (string) wp_json_encode( [ [
	'sentence' => 'Our boonie hats keep the sun off on the same kind of day.',
	'anchor'   => 'boonie hats',
	'url'      => 'https://kula.test/category/boonie-hats/',
] ] );
try {
	DZE_Post_Links::add_links( 20, [ 'https://kula.test/category/boonie-hats/' ] );
} catch ( Throwable $e ) {
	ok( 'the pass ran', $e->getMessage(), '' );
}
$asked = DZE_Marketing_Ai::$sent ? (string) end( DZE_Marketing_Ai::$sent )['user'] : '';
ok( 'the picked page travels to the model',
	false !== strpos( $asked, 'https://kula.test/category/boonie-hats/' ), true );
ok( 'named, so the anchor can be its title',
	false !== strpos( $asked, 'Boonie hats' ), true );
ok( 'and nothing else is offered beside it',
	false !== strpos( $asked, 'Tactical bags' ), false );

echo "\nA shop in five languages is still ONE shop\n";
// THE FAULT THE SHOP FOUND: "Read 2 seconds ago — 830 pages, 571 internal
// links" on a site holding a fifth of that. The mesh reads the posts table
// with its own SQL, which WPML cannot narrow, so every page of every language
// comes back and the language check is the ONLY thing standing between the
// reading and a count five times too big. That check asked WPML for a
// category by the wrong name — 'product_cat' where WPML holds
// 'tax_product_cat' — got nothing, and read "nothing" as "the shop's own
// language".
$GLOBALS['deflang'] = 'en';
$GLOBALS['terms'][14] = [ 'name' => 'Taktische Taschen', 'slug' => 'taktische-taschen', 'parent' => 0, 'description' => '' ];
$GLOBALS['posts'][24] = [ 'type' => 'post', 'title' => 'Wie wählt man einen Rucksack', 'content' => '<p>' . str_repeat( 'ein wort ', 90 ) . '</p>' ];
$GLOBALS['langof'] = [
	// Every English page, named the way WPML names it: a term by its TERM
	// TAXONOMY id under tax_product_cat, a post by its post id under post_*.
	'tax_product_cat' => [ 510 => 'en', 511 => 'en', 512 => 'en', 513 => 'en', 514 => 'de' ],
	'post_post'       => [ 20 => 'en', 21 => 'en', 24 => 'de' ],
	'post_page'       => [ 22 => 'en', 23 => 'en', 30 => 'en' ],
];
$GLOBALS['asked_wpml'] = [];
$titles = wp_list_pluck( DZE_Mesh::pages( true ), 'title' );
ok( 'the shop keeps its own pages',     in_array( 'Tactical bags', $titles, true ), true );
ok( 'a translated category is not a second page',
	in_array( 'Taktische Taschen', $titles, true ), false );
ok( 'nor is a translated article',      in_array( 'Wie wählt man einen Rucksack', $titles, true ), false );
ok( 'so the count is the shop, once',   count( $titles ), 11 );
// AND IT ASKED BY THE RIGHT NAME. Asked as 'product_cat' the answer is empty
// for every category alike, which reads on screen as a shop with no
// translations at all — the failure that has no symptom until somebody counts.
ok( 'a category is asked for as WPML holds it',
	in_array( 'tax_product_cat:514', $GLOBALS['asked_wpml'], true ), true );
ok( 'never by the taxonomy name alone',
	(bool) preg_grep( '/^product_cat:/', $GLOBALS['asked_wpml'] ), false );
// A shop with ONE language has no rows at all, and every page is its own.
$GLOBALS['langof'] = [];
ok( 'one language, and nothing is filtered out',
	count( DZE_Mesh::pages( true ) ), 13 );

// AND WHERE WPML'S FILTERS ANSWER NOTHING AT ALL. This is the request the
// reading actually runs in — an AJAX action, a cron tick — and it is where
// the shop counted 780 pages on a site holding a fifth of that: every filter
// came back empty, empty fell through to "the shop's own language", and every
// translation passed for an English page.
$GLOBALS['langof'] = [];          // the filters know nothing here.
$GLOBALS['icl'] = [
	// A term is indexed by its TERM TAXONOMY id, a post by its post id.
	'tax_product_cat' => [ 'en' => [ 510, 511, 512, 513 ] ],
	'post_post'       => [ 'en' => [ 20, 21 ] ],
	'post_page'       => [ 'en' => [ 22, 23, 30 ] ],
];
DZE_Wpml::$asked = [];
$titles = wp_list_pluck( DZE_Mesh::pages( true ), 'title' );
ok( 'the table answers where the filters do not', count( $titles ), 9 );
ok( 'the German category is left out',  in_array( 'Taktische Taschen', $titles, true ), false );
ok( 'and the German article too',       in_array( 'Wie wählt man einen Rucksack', $titles, true ), false );
ok( 'a category is asked for by WPML\'s own name',
	in_array( 'tax_product_cat:en', DZE_Wpml::$asked, true ), true );
ok( 'and posts and pages each by theirs',
	[ in_array( 'post_post:en', DZE_Wpml::$asked, true ), in_array( 'post_page:en', DZE_Wpml::$asked, true ) ],
	[ true, true ] );
// AND THE LINK POOL ASKS THE SAME TABLE. "Post allemand vu dans les
// recommandations de lien. Bizarre." The pool is built by page_index() and
// category_index(), and both of them narrowed by
// `wpml_element_language_details` — a FILTER, in a panel served by an AJAX
// action, where WPML's hooks are not loaded. It answered nothing, nothing fell
// through to "the shop's own language", and every translation was offered as a
// page to link to. Worse, the whole pool was then cached for six hours.
DZE_Wpml::$asked = [];
$GLOBALS['tr'] = array_diff_key( $GLOBALS['tr'], array_flip( array_filter(
	array_keys( $GLOBALS['tr'] ),
	static fn( $k ) => 0 === strpos( (string) $k, 'dze_cc_pages_' ) || 0 === strpos( (string) $k, 'dze_cc_cats_' )
) ) );
$dze_pages = wp_list_pluck( DZE_Category_Content::page_index( true ), 'title' );
ok( 'the pool asks the table for posts and pages',
	[ in_array( 'post_post:en', DZE_Wpml::$asked, true ), in_array( 'post_page:en', DZE_Wpml::$asked, true ) ],
	[ true, true ] );
ok( 'and no German article is offered as a target',
	in_array( 'Wie wählt man einen Rucksack', $dze_pages, true ), false );
ok( 'while the English ones still are',
	in_array( 'How to choose a tactical backpack', $dze_pages, true ), true );
$dze_cats = wp_list_pluck( DZE_Category_Content::category_index( true ), 'name' );
ok( 'the categories are asked for the same way',
	in_array( 'tax_product_cat:en', DZE_Wpml::$asked, true ), true );
ok( 'and no German category is offered either',
	in_array( 'Taktische Taschen', $dze_cats, true ), false );

// A TABLE THAT CANNOT BE ASKED NARROWS NOTHING. Null means "do not narrow",
// never "narrow to nothing": a shop with one language keeps every page it has.
$GLOBALS['icl'] = [];
ok( 'no table, and nothing is thrown away', count( DZE_Mesh::pages( true ) ), 13 );

$GLOBALS['deflang'] = '';
$GLOBALS['icl'] = [];
unset( $GLOBALS['terms'][14], $GLOBALS['posts'][24] );
DZE_Mesh::pages( true );

echo "\nA category with nothing in it is not a page of the mesh\n";
//
// "SMERSH VESTS — c'est une catégorie sans produits. Ça doit être filtré. Pas
// besoin de les linker celles-là. Peut-être des catégories mortes ou pas
// finies, peu importe." A shelf with nothing on it is nowhere to send a
// reader, exactly as an empty page is.
$GLOBALS['deflang'] = '';
$GLOBALS['icl']     = [];
delete_transient( 'dze_mesh_pages' );
$dze_cats = wp_list_pluck( DZE_Mesh::pages( true ), 'title' );
ok( 'a dead shelf is not in the graph',  in_array( 'Smersh vests', $dze_cats, true ), false );
// AND THE RULE IS COUNTED DOWN THE BRANCH, never on the term's own figure: a
// parent whose products all live in its children carries a count of nought and
// is a perfectly full aisle. Written on `count` alone this rule would take out
// the top of every branch on the shop, which is worse than the thing it mends.
ok( 'a parent whose child holds the stock stays',
	in_array( 'Tactical footwear', $dze_cats, true ), true );
ok( 'and the child itself of course',    in_array( 'Combat boots', $dze_cats, true ), true );
ok( 'a stocked category is untouched',   in_array( 'Tactical backpacks', $dze_cats, true ), true );
// IT IS NOWHERE, not merely not work: not a target the panel offers either.
delete_transient( 'dze_cc_cats_x' );
ok( 'the pool never offers it',
	in_array( 'Smersh vests', wp_list_pluck( DZE_Category_Content::category_index( true ), 'name' ), true ), false );

echo "\nEmpty pages and drafts are not pages of the mesh\n";
//
// "D'ailleurs les pages vides ou les brouillons sont à exclure sans
// discussion. Ça doit être filtré." Neither is somewhere to send a reader, and
// neither is somewhere to write a sentence — so they are not in the graph at
// all, exactly as the cart and the checkout are not.
$GLOBALS['deflang'] = '';
$GLOBALS['icl']     = [];
delete_transient( 'dze_mesh_pages' );
$dze_titles = wp_list_pluck( DZE_Mesh::pages( true ), 'title' );
ok( 'an empty page is not in the graph',
	in_array( 'Nothing on it yet', $dze_titles, true ), false );
ok( 'and a draft is not either',
	in_array( 'A draft about hats', $dze_titles, true ), false );
// A BUILDER PAGE IS EMPTY IN THE POST AND FULL ON THE SCREEN. Measured on
// `post_content` alone it would be dropped by the very rule meant to tidy the
// list — the builder trap, for the fourth time.
ok( 'a builder page with no post text stays',
	in_array( 'Workshop history', $dze_titles, true ), true );
ok( 'and a page with words stays',
	in_array( 'Boonie hat sizing', $dze_titles, true ), true );
// AND THE POOL THE PANEL OFFERS APPLIES THE SAME RULE. Offering an empty page
// as a link target is how a link gets written to a page with nothing on it.
delete_transient( 'dze_cc_pages_x' );
$dze_pool = wp_list_pluck( DZE_Category_Content::page_index( true ), 'title' );
ok( 'the pool never offers the empty page',
	in_array( 'Nothing on it yet', $dze_pool, true ), false );
ok( 'nor the draft',
	in_array( 'A draft about hats', $dze_pool, true ), false );
// A PAGE IS A TARGET ONCE IT IS CHOSEN — including a builder page, whose text
// is read from the builder's own data. Measured on `post_content` alone it
// would have been dropped as empty, which is the builder trap.
ok( 'no page is offered until it is chosen',
	in_array( 'Workshop history', $dze_pool, true ), false );
DZE_Mesh::choose_pages( [ 23 ], true );
ok( 'chosen, the builder page is a target',
	in_array( 'Workshop history', wp_list_pluck( DZE_Category_Content::page_index(), 'title' ), true ), true );
DZE_Mesh::choose_pages( [ 23 ], false );

echo "\nWhich pages take part in linking\n";
//
// "C'est mal foutu, très inconfortable… ou alors n'inclure que les posts et
// catégories par défaut, et l'option des pages, sélectionner les pages qu'on
// voudrait linker sur un tableau." A deny-list made you do two hundred rows of
// work to remove eight; the only kind where the answer is mixed is the PAGE.
$GLOBALS['deflang'] = '';
$GLOBALS['icl']     = [];
$GLOBALS['opts']['dze_mesh_pages'] = [];
delete_transient( 'dze_mesh_pages' );
DZE_Mesh::scan();

// AN ARTICLE AND A CATEGORY ALWAYS TAKE PART: they are what a shop writes to
// be read, and there is no control for them because nobody would use one.
ok( 'an article always takes part',      DZE_Mesh::in_work( 'post', 21 ), true );
ok( 'and a product category too',        DZE_Mesh::in_work( 'product_cat', 13 ), true );
// A PAGE DOES NOT, until it is chosen. On a fresh install nothing links to a
// refund policy by accident, which is the whole point of the default.
ok( 'a page does not, to begin with',    DZE_Mesh::in_work( 'page', 22 ), false );
ok( 'and nothing is chosen yet',         DZE_Mesh::chosen_pages(), [] );

$dze_orph0 = DZE_Mesh::orphan_count();
$dze_names = wp_list_pluck( DZE_Mesh::orphans( 500 ), 'title' );
ok( 'no page is on the orphan list',     in_array( 'Workshop history', $dze_names, true ), false );
ok( 'while the articles still are',      in_array( 'Tactical gloves', $dze_names, true ), true );
ok( 'the census says how many are out',  DZE_Mesh::census()['counts']['aside'] > 0, true );

// CHOOSING ONE PUTS IT BACK INTO THE WORK, and the figures move with it.
ok( 'choosing one moves one',            DZE_Mesh::choose_pages( [ 23 ], true ), 1 );
ok( 'it now takes part',                 DZE_Mesh::in_work( 'page', 23 ), true );
ok( 'and the figure moved',              DZE_Mesh::orphan_count(), $dze_orph0 + 1 );
ok( 'the list and the figure agree',     DZE_Mesh::orphan_count(), count( DZE_Mesh::orphans( 500 ) ) );
// A BULK PRESS DOES ITS BOOKKEEPING ONCE, and says how many it actually moved
// — a row already in that state is not a change.
ok( 'choosing it again moves nothing',   DZE_Mesh::choose_pages( [ 23 ], true ), 0 );
ok( 'and a whole run in one write',      DZE_Mesh::choose_pages( [ 22, 30 ], true ), 2 );
// AN ID THAT IS NOT A PAGE OF THE READING IS NOT A PAGE. A value typed into a
// request must not be able to put a row in this option nothing answers to.
ok( 'an unknown id is refused',          DZE_Mesh::choose_pages( [ 999999 ], true ), 0 );
ok( 'and an article id is not a page',   DZE_Mesh::choose_pages( [ 21 ], true ), 0 );
ok( 'nothing of either was written',
	[ isset( DZE_Mesh::chosen_pages()[999999] ), isset( DZE_Mesh::chosen_pages()[21] ) ], [ false, false ] );

// NOT WORK, IN EVERY LIST — one guard in ranked(), so a question added next
// year cannot forget it.
DZE_Mesh::choose_pages( [ 22, 23, 30 ], false );
DZE_Mesh::forget_thin();
ok( 'a page left out is not short of links',
	in_array( 'Workshop tour', wp_list_pluck( DZE_Mesh::needs( 500 ), 'title' ), true ), false );
ok( 'nor under its outgoing quota',
	in_array( 'Workshop tour', wp_list_pluck( DZE_Mesh::thin( 500 ), 'title' ), true ), false );
ok( 'nor a dead end',
	in_array( 'Workshop history', wp_list_pluck( DZE_Mesh::dead_ends( 500 ), 'title' ), true ), false );
// NOTHING IS SENT TO IT, AND NOTHING IS WRITTEN INTO IT.
ok( 'nothing is shortlisted to point at it', DZE_Mesh::shortlist( 'page:30' ), [] );
ok( 'the article is a source to begin with',
	in_array( 'post:21', wp_list_pluck( DZE_Mesh::shortlist( 'product_cat:12' ), 'key' ), true ), true );
// THE LINKS IT ALREADY CARRIES STILL COUNT. Page 22 points at Boonie hats
// through its builder data; dropping that edge would turn a category into an
// orphan it is not.
ok( "the pages it points at keep their inbound",
	(int) ( DZE_Mesh::census()['per']['product_cat:12']['in'] ?? 0 ) > 0, true );

// AND THE POOL THE PANEL OFFERS ASKS THE SAME RULE — never a second one of
// its own, and never from behind a six-hour cache.
delete_transient( 'dze_cc_pages_x' );
$dze_pool = wp_list_pluck( DZE_Category_Content::page_index( true ), 'title' );
ok( 'the pool offers the articles',      in_array( 'Boonie hat sizing', $dze_pool, true ), true );
ok( 'and no page nobody chose',          in_array( 'Workshop tour', $dze_pool, true ), false );
DZE_Mesh::choose_pages( [ 30 ], true );
ok( 'chosen, the pool offers it',
	in_array( 'Workshop tour', wp_list_pluck( DZE_Category_Content::page_index(), 'title' ), true ), true );
ok( 'without the cache having been thrown away',
	is_array( get_transient( 'dze_cc_pages_x' ) ), true );
DZE_Mesh::choose_pages( [ 30 ], false );

// THE CHOOSER ITSELF, DRAWN. A screen that dies takes the page white.
$GLOBALS['opts']['dze_mesh_pages'] = [ 23 ];
DZE_Mesh::recount();
ob_start();
DZE_Mesh::render_pages_box();
$box = (string) ob_get_clean();
ok( 'the box says where it stands',      false !== strpos( $box, 'Pages that take part in linking — 1 of 3' ), true );
ok( 'one tick per page',                 substr_count( $box, 'dze-mesh-cb' ), 3 );
ok( 'and a take-all in the heading',     substr_count( $box, 'dze-mesh-all' ), 1 );
// EVERY LIST THAT NAMES AN OBJECT PRINTS ITS ID, heading and cell together.
ok( 'the id has its own heading',        substr_count( $box, 'dze-objid-th' ), 1 );
ok( 'and one cell per row',              substr_count( $box, 'dze-objid-td' ), 3 );
// A ROW SAYS WHICH IT IS, in words and not only by a tick somewhere else.
ok( 'a chosen page says so',             substr_count( $box, 'Takes part' ), 1 );
ok( 'and the others say so too',         substr_count( $box, 'Left out' ), 2 );
// NO ARTICLE AND NO CATEGORY IS ON IT: they have no decision to take.
ok( 'no article on the chooser',         false !== strpos( $box, 'Boonie hat sizing' ), false );
ok( 'nor a category',                    false !== strpos( $box, 'Tactical gloves' ), false );
// ONE BAR, AND IT REFUSES TO ACT ON NOTHING.
ok( 'both bulk buttons are there',       substr_count( $box, 'class="button dze-mesh-pick"' ), 2 );
ok( 'and start out disabled',            substr_count( $box, 'dze-mesh-pick" data-on="1" disabled' ), 1 );
ok( 'the count and the message are their own',
	[ substr_count( $box, 'dze-mesh-count' ), substr_count( $box, 'dze-mesh-msg' ) ], [ 1, 1 ] );


echo "\nBefore the first reading, the lists answer nothing\n";
//
// With no census every page counted nought links in and nought out, so before
// the first scan every page read as an orphan AND a dead end: the Automation
// screen listed the whole site as next in line, "Run one now" queued work on
// it, and "Link the whole site" put two hundred pages in the queue — from a
// reading that did not exist. The count already answered null; the lists did
// not answer the same.
$dze_keep_census = $GLOBALS['opts']['dze_mesh_census'];
$GLOBALS['opts']['dze_mesh_census'] = [];
DZE_Mesh::forget_thin();
ok( 'no page is short of links',        DZE_Mesh::needs( 500 ), [] );
ok( 'no page is an orphan',             DZE_Mesh::orphans( 500 ), [] );
ok( 'no page is a dead end',            DZE_Mesh::dead_ends( 500 ), [] );
ok( 'no page is under its quota',       DZE_Mesh::thin( 500 ), [] );
ok( 'the day has no work to hand out',  DZE_Mesh::plan( 5 ), [] );
ok( 'and the chooser has no figure yet', DZE_Mesh::chosen_said(), null );
$GLOBALS['opts']['dze_mesh_census'] = $dze_keep_census;
DZE_Mesh::forget_thin();

echo "\nA text too short to carry a sentence is not a dead end\n";
//
// "Boonie hats" holds four words and pointed at nothing, so it was offered
// "Add internal links": a button whose press produces nothing. A text under
// the source minimum is a description to write, not a link to place.
ok( 'the four-word category is not listed',
	in_array( 'Boonie hats', wp_list_pluck( DZE_Mesh::dead_ends( 500 ), 'title' ), true ), false );
ok( 'a long one still is',
	in_array( 'Boonie hat sizing', wp_list_pluck( DZE_Mesh::dead_ends( 500 ), 'title' ), true ), true );
ok( 'and the census counts the same way',
	DZE_Mesh::census()['counts']['ends'], count( DZE_Mesh::dead_ends( 500 ) ) );

echo "\nA screen being drawn never calls the model\n";
//
// The Automation screen asked, on every open, which neighbour should point at
// each orphan not yet read — one model call per orphan, with somebody waiting
// on the page, spending on every draw. Asked NOT to judge, the pairs are the
// wording's own and no call goes out.
$GLOBALS['key'] = 'sk-test';
DZE_Marketing_Ai::$answer = '[{"n":1,"why":"read"}]';
$GLOBALS['tr'] = array_filter( $GLOBALS['tr'], static fn( $k ) => 0 !== strpos( (string) $k, 'dze_mesh_pick_' ), ARRAY_FILTER_USE_KEY );
$dze_calls = count( DZE_Marketing_Ai::$sent );
$res = DZE_Mesh::pairs_for( 'product_cat:12', 6, false );
ok( 'not judged, no call went out',     count( DZE_Marketing_Ai::$sent ), $dze_calls );
ok( 'and it says the wording chose',    $res['how'], 'unjudged' );
ok( 'with rows in it',                  count( $res['rows'] ) > 0, true );
DZE_Mesh::plan( 3, false );
ok( 'nor does the plan, unjudged',      count( DZE_Marketing_Ai::$sent ), $dze_calls );
// A VERDICT ALREADY KEPT IS READ, judged or not.
DZE_Mesh::pairs_for( 'product_cat:12' );
ok( 'judged, it is asked once',         count( DZE_Marketing_Ai::$sent ), $dze_calls + 1 );
ok( 'and the kept verdict serves the draw', DZE_Mesh::pairs_for( 'product_cat:12', 6, false )['how'], 'read' );

echo "\nThe key is set and the reading did not answer: said as what it is\n";
//
// The panel said "the writing key is not set" over rows chosen on wording
// because the model had thrown — a sentence about a key that was there.
$GLOBALS['tr'] = array_filter( $GLOBALS['tr'], static fn( $k ) => 0 !== strpos( (string) $k, 'dze_mesh_pick_' ), ARRAY_FILTER_USE_KEY );
DZE_Marketing_Ai::$answer = '';
ok( 'a reading that threw is "unjudged"', DZE_Mesh::pairs_for( 'product_cat:12' )['how'], 'unjudged' );
DZE_Marketing_Ai::$answer = 'no json here';
ok( 'and so is one nobody could read',  DZE_Mesh::pairs_for( 'product_cat:12' )['how'], 'unjudged' );
$GLOBALS['key'] = '';
ok( 'no key at all stays "words"',      DZE_Mesh::pairs_for( 'product_cat:12' )['how'], 'words' );
$GLOBALS['key'] = 'sk-test';
DZE_Marketing_Ai::$answer = '[{"n":1,"why":"read"}]';

echo "\nA page already in the writing queue is marked, not offered again\n";
DZE_Queue::$holds = [];
ok( 'nothing held, nothing said',       DZE_Mesh::busy_of( 'post', 21 ), '' );
DZE_Queue::$holds['post_21'] = 'queued';
ok( 'waiting its turn is "queued"',     DZE_Mesh::busy_of( 'post', 21 ), 'queued' );
DZE_Queue::$holds['post_21'] = 'running';
ok( 'and so is one being written',      DZE_Mesh::busy_of( 'post', 21 ), 'queued' );
DZE_Queue::$holds['cat_10'] = 'review';
ok( 'written and waiting is "review"',  DZE_Mesh::busy_of( 'product_cat', 10 ), 'review' );
ok( 'each state has its words',
	[ DZE_Mesh::busy_said( 'queued' ), DZE_Mesh::busy_said( 'review' ), DZE_Mesh::busy_said( '' ) ],
	[ 'In the writing queue', 'Written — waiting for your yes or no', '' ] );
$GLOBALS['tr'] = array_filter( $GLOBALS['tr'], static fn( $k ) => 0 !== strpos( (string) $k, 'dze_mesh_pick_' ), ARRAY_FILTER_USE_KEY );
$GLOBALS['key'] = '';
$dze_rows = [];
foreach ( DZE_Mesh::pairs_for( 'product_cat:12' )['rows'] as $r ) { $dze_rows[ $r['key'] ] = $r['busy'] ?? null; }
ok( 'the row says the queue holds it',  $dze_rows['post:21'] ?? null, 'In the writing queue' );
DZE_Queue::$holds = [];
$dze_rows = [];
foreach ( DZE_Mesh::pairs_for( 'product_cat:12' )['rows'] as $r ) { $dze_rows[ $r['key'] ] = $r['busy'] ?? null; }
ok( 'and a free row says nothing',      $dze_rows['post:21'] ?? null, '' );
DZE_Queue::$holds = [];
$GLOBALS['key'] = 'sk-test';

echo "\nA press that sent some says which\n";
ok( 'all sent: nothing to add',         DZE_Mesh::sent_said( 3, 3, '' ), '' );
ok( 'one of three: said, with the reason',
	DZE_Mesh::sent_said( 1, 3, 'That page is already waiting in the queue.' ),
	'1 of 3 sent — That page is already waiting in the queue.' );

echo "\nA reading under way is known\n";
ok( 'nothing running',                  DZE_Mesh::reading_now(), false );
$GLOBALS['tr']['dze_mesh_lock'] = 1;
ok( 'locked, it says so',               DZE_Mesh::reading_now(), true );
unset( $GLOBALS['tr']['dze_mesh_lock'] );

echo "\nThe figure over a list is the site's, not the list's\n";
ok( 'a list not cut is its own figure', DZE_Mesh::listed_said( 213, 9, 200, 'short' ), '9 pages are short of that.' );
ok( 'one page reads as one',            DZE_Mesh::listed_said( 1, 1, 200, 'short' ), '1 page is short of that.' );
ok( 'a list cut says how many it shows', DZE_Mesh::listed_said( 213, 200, 200, 'short' ), '213 pages are short of that, 200 of them listed here.' );
ok( 'and never a figure under the rows', DZE_Mesh::listed_said( 150, 200, 200, 'short' ), '200 pages are short of that.' );
ok( 'the other list the same way',      DZE_Mesh::listed_said( 80, 50, 50, 'ends' ), '80 pages point at nothing, 50 of them listed here.' );

echo "\nWhat runs by itself, said where the linking is looked at\n";
DZE_Automation::$conf = [ 'on' => false, 'per_day' => 3, 'apply' => false ];
$dze_auto = DZE_Mesh::auto_said();
ok( 'off, it says so',                  false !== strpos( $dze_auto['said'], 'Nothing links pages on its own yet' ), true );
// ET IL NE NOMME AUCUN AUTRE ECRAN : linterrupteur est en haut de cette page.
// Envoyer la boutique vers un menu — disparu — presser un controle quelle a
// deja sous les yeux, cest le clic que toute cette reorganisation visait.
ok( 'et il dit ou est linterrupteur', false !== strpos( $dze_auto['said'], 'Switch it on above' ), true );
ok( 'sans renvoyer ailleurs',        [ $dze_auto['url'], $dze_auto['name'] ], [ '', '' ] );
DZE_Automation::$conf = [ 'on' => true, 'per_day' => 3, 'apply' => false ];
ok( 'on, the rhythm and the review',    DZE_Mesh::auto_said()['said'], 'Dazont Ecom also links 3 pages a day on its own, held for your yes or no.' );
DZE_Automation::$conf = [ 'on' => true, 'per_day' => 1, 'apply' => true ];
ok( 'one a day, saved without review',  DZE_Mesh::auto_said()['said'], 'Dazont Ecom also links 1 page a day on its own, saved without review.' );
DZE_Automation::$conf = [ 'on' => false, 'per_day' => 3, 'apply' => false ];

echo "\nHow many pages were chosen, from the reading alone\n";
$GLOBALS['opts']['dze_mesh_pages'] = [ 23 ];
ok( 'the site has three pages, one chosen', DZE_Mesh::chosen_said(), [ 'pages' => 3, 'on' => 1 ] );
$GLOBALS['opts']['dze_mesh_pages'] = [];
ok( 'none chosen reads as nought',      DZE_Mesh::chosen_said(), [ 'pages' => 3, 'on' => 0 ] );
$GLOBALS['opts']['dze_mesh_pages'] = [ 23 ];

echo "\nThe tab itself, rendered\n";
// A SETTINGS TAB THAT DIES TAKES THE WHOLE PAGE WHITE, before any of our own
// error handling. Calling its pieces proves nothing: the tab is RUN here, and
// its markup is what the browser gate presses.
ob_start();
DZE_Mesh::instance()->render_tab();
$screen = (string) ob_get_clean();
ok( 'the reading is named on it',       false !== strpos( $screen, 'Read the site again' ), true );
ok( 'so is the rule it measures against', false !== strpos( $screen, 'at least 3 others' ), true );
ok( 'a page nobody points at is a row', false !== strpos( $screen, 'Tactical gloves' ), true );
ok( 'saying how far off it is',         false !== strpos( $screen, 'nobody points at it' ), true );
ok( 'with the button that opens the choice', false !== strpos( $screen, 'dze-mesh-pairs' ), true );
ok( 'and the other list has its own',   false !== strpos( $screen, 'dze-mesh-out' ), true );
// A ROW LINKS TO THE OBJECT, NEVER TO A SETTINGS PAGE. Twice now a control on
// a row of objects has shipped pointing at a preferences screen. The rows are
// the tables; the lines over them may name a screen.
preg_match_all( '#<tr data-key=.*?</tr>#s', $screen, $dze_trs );
ok( 'no row points at a settings page', preg_match( '#href="[^"]*page=dazont-ecom[^"]*"#', implode( '', $dze_trs[0] ) ), 0 );
// EVERY LIST THAT NAMES AN OBJECT PRINTS ITS ID, heading and cell together,
// on BOTH tables — and the way to the page as a reader sees it, from the one
// function that prints an object's name anywhere.
$dze_needs = substr( $screen, strpos( $screen, 'id="dze-mesh-needs"' ), strpos( $screen, 'id="dze-mesh-ends"' ) - strpos( $screen, 'id="dze-mesh-needs"' ) );
$dze_ends  = substr( $screen, strpos( $screen, 'id="dze-mesh-ends"' ), strpos( $screen, 'dze-mesh-pagesbox' ) - strpos( $screen, 'id="dze-mesh-ends"' ) );
ok( 'the short list has an id heading', substr_count( $dze_needs, 'dze-objid-th' ), 1 );
ok( 'and an id cell per row',           substr_count( $dze_needs, 'dze-objid-td' ), substr_count( $dze_needs, '<tr data-key=' ) );
ok( 'the dead-end list too',            [ substr_count( $dze_ends, 'dze-objid-th' ), substr_count( $dze_ends, 'dze-objid-td' ) === substr_count( $dze_ends, '<tr data-key=' ) ], [ 1, true ] );
ok( 'every short row offers the page',  substr_count( $dze_needs, 'dze-hub-visit' ), substr_count( $dze_needs, '<tr data-key=' ) );
ok( 'and every dead end does',          substr_count( $dze_ends, 'dze-hub-visit' ), substr_count( $dze_ends, '<tr data-key=' ) );
// THE HEADING AND THE CELL ARE ASSERTED TOGETHER, IN POSITION.
preg_match( '#<thead>.*?</thead>#s', $dze_needs, $dze_head );
// `<thead>` begins with "<th" too: the tag is matched whole.
preg_match_all( '#<th(?:\s[^>]*)?>#', (string) ( $dze_head[0] ?? '' ), $dze_ths );
ok( 'the id is the second column',      false !== strpos( (string) ( $dze_ths[0][1] ?? '' ), 'dze-objid-th' ), true );
preg_match( '#<tr data-key=.*?</tr>#s', $dze_needs, $dze_tr1 );
preg_match_all( '#<td[^>]*>#', (string) ( $dze_tr1[0] ?? '' ), $dze_tds );
ok( 'and so is its cell',               false !== strpos( (string) ( $dze_tds[0][1] ?? '' ), 'dze-objid-td' ), true );
// THE PROMPT BEHIND THE PASS, one press away, with the word every screen uses.
ok( 'the prompt is one press away',     false !== strpos( $screen, 'data-prompt="cat_links"' ), true );
ok( 'wearing the same word as everywhere', false !== strpos( $screen, '✎ prompt' ), true );
// THE FIGURE IS THE SITE'S. Nine short pages, none cut off.
ok( 'the sentence counts the site',     false !== strpos( $screen, ' pages are short of that.' ), true );
// WITH THE KEY SET, NOTHING STANDS IN THE WAY.
ok( 'no warning about the key',         false !== strpos( $screen, 'dze-mesh-nokey' ), false );
ok( 'and the buttons are live',         substr_count( $screen, 'dze-mesh-pairs" disabled' ), 0 );
// THE PASS THAT RUNS BY ITSELF IS NAMED, off or on.
ok( 'the automatic pass is said',       false !== strpos( $screen, 'dze-mesh-auto' ), true );
// SANS RENVOYER NULLE PART : linterrupteur est en haut de cette page.
ok( 'et lecran ne renvoie vers aucun menu', false !== strpos( $screen, '>Dazont Ecom → Automation</a>' ), false );
// A PAGE HAS BEEN CHOSEN, so nothing nags.
ok( 'no line about choosing pages',     false !== strpos( $screen, 'dze-mesh-unchosen' ), false );

echo "\nThe tab on a shop that never set it up\n";
// NO KEY: said at the top, and the buttons cannot send a job off to fail.
$GLOBALS['key'] = '';
ob_start(); DZE_Mesh::instance()->render_tab(); $dze_nokey = (string) ob_get_clean();
ok( 'the missing key is said first',    false !== strpos( $dze_nokey, 'dze-mesh-nokey' ), true );
ok( 'naming the tab that holds it',     false !== strpos( $dze_nokey, '>Settings → General</a>' ), true );
ok( 'every Link to it is disabled',     substr_count( $dze_nokey, 'dze-mesh-pairs" disabled' ), substr_count( $dze_nokey, 'dze-mesh-pairs' ) );
ok( 'saying why on hover',              false !== strpos( $dze_nokey, 'disabled title="The writing key is not set' ), true );
ok( 'and so is every Add internal links', substr_count( $dze_nokey, 'dze-mesh-out" disabled' ), substr_count( $dze_nokey, 'dze-mesh-out' ) );
$GLOBALS['key'] = 'sk-test';
// NO PAGE CHOSEN: the screen says so at the top and offers the chooser.
$GLOBALS['opts']['dze_mesh_pages'] = [];
DZE_Mesh::recount();
ob_start(); DZE_Mesh::instance()->render_tab(); $dze_unchosen = (string) ob_get_clean();
ok( 'no page chosen is said at the top', false !== strpos( $dze_unchosen, 'dze-mesh-unchosen' ), true );
ok( 'with the way to the chooser',      false !== strpos( $dze_unchosen, 'href="#dze-mesh-pages" class="dze-mesh-choose"' ), true );
ok( 'which carries that id',            false !== strpos( $dze_unchosen, 'id="dze-mesh-pages"' ), true );
$GLOBALS['opts']['dze_mesh_pages'] = [ 23 ];
DZE_Mesh::recount();
// A DEAD END ALREADY IN THE QUEUE SAYS SO, and offers no button.
DZE_Queue::$holds['post_21'] = 'review';
ob_start(); DZE_Mesh::instance()->render_tab(); $dze_busy = (string) ob_get_clean();
preg_match( '#<tr data-key="post:21">.*?</tr>#s', substr( $dze_busy, strpos( $dze_busy, 'id="dze-mesh-ends"' ) ), $dze_brow );
ok( 'the row says the text is waiting', false !== strpos( (string) ( $dze_brow[0] ?? '' ), 'Written — waiting for your yes or no' ), true );
ok( 'and offers the way to it',         false !== strpos( (string) ( $dze_brow[0] ?? '' ), 'tab=review' ), true );
ok( 'with no button beside it',         false !== strpos( (string) ( $dze_brow[0] ?? '' ), 'dze-mesh-out' ), false );
DZE_Queue::$holds['post_21'] = 'queued';
ob_start(); DZE_Mesh::instance()->render_tab(); $dze_busy = (string) ob_get_clean();
preg_match( '#<tr data-key="post:21">.*?</tr>#s', substr( $dze_busy, strpos( $dze_busy, 'id="dze-mesh-ends"' ) ), $dze_brow );
ok( 'waiting its turn is said too',     false !== strpos( (string) ( $dze_brow[0] ?? '' ), 'In the writing queue' ), true );
DZE_Queue::$holds = [];
// NEVER READ: the button says "Read the site", not "again", and nothing else is drawn.
$GLOBALS['opts']['dze_mesh_census'] = [];
ob_start(); DZE_Mesh::instance()->render_tab(); $dze_never = (string) ob_get_clean();
ok( 'the first reading is not "again"', [ false !== strpos( $dze_never, '>Read the site</button>' ), false !== strpos( $dze_never, 'Read the site again' ) ], [ true, false ] );
ok( 'and no list is drawn',             false !== strpos( $dze_never, 'dze-mesh-needs' ), false );
ok( 'nor a line about choosing pages, which the reading has not counted', false !== strpos( $dze_never, 'dze-mesh-unchosen' ), false );
$GLOBALS['opts']['dze_mesh_census'] = $dze_keep_census;
DZE_Mesh::recount();

// The markup the browser gate presses, so no copy of it is ever written into
// a JavaScript file and left to drift.
if ( $dze_dump ) {
	// The checks went into the buffer; the screen alone goes to stdout.
	file_put_contents( 'php://stderr', (string) ob_get_clean() . sprintf( "\n%d checks, %d wrong\n", $ran, $fails ) );
	echo in_array( '--dump-unchosen', $argv, true ) ? $dze_unchosen : $screen;
	exit( $fails ? 1 : 0 );
}


echo "\nLES MOTS SE TROUVENT COMME UN LECTEUR LES LIT\n";
// « Ta régression du module de maillage interne rend impossible le maillage
// sur certaines pages du fait du manque des mots dans le texte. »
// Le modele voit le TEXTE et repond avec les mots choisis ; il lit a travers
// les balises en ligne, parce que c est ce que la page montre. « giving rise
// to the <strong>bomber jacket</strong> » se lit « giving rise to the bomber
// jacket », et c est ce qui revenait. Cherche tel quel dans le HTML brut :
// introuvable, edit refuse, aucun lien ecrit.
$ml_u  = 'https://exemple.test/bomber';
// LE TITRE DE LA CIBLE VOYAGE AVEC SON ADRESSE, comme dans le vrai appel :
// l ancre est jugee contre lui — « les ancres, c est le titre du post vise ».
$ml_go = static function ( string $html, string $anchor, string $label = 'Bomber jacket' ) use ( $ml_u ): array {
	return DZE_Category_Content::apply_edits( $html, [ [ 'anchor' => $anchor, 'url' => $ml_u ] ], [ $ml_u ], [ $ml_u => $label ] );
};
$ml_txt = static fn( string $h ): string => preg_replace( '#</?a\b[^>]*>#i', '', $h );

$ml_real = 'These jackets were soon <strong>adopted by pilots</strong>, giving rise to the <strong>bomber jacket</strong>, a style.';
$ml_r = $ml_go( $ml_real, 'the bomber jacket' );
ok( 'lancre qui traverse une balise est posee', $ml_r['applied'], 1 );
ok( 'et le texte na pas bouge dun caractere', $ml_txt( $ml_real ), $ml_txt( (string) $ml_r['html'] ) );
ok( 'et le HTML reste valide, la balise fermee dedans',
	false !== strpos( (string) $ml_r['html'], '<strong>bomber jacket</strong></a>' ), true );

// LES TROIS AUTRES FACONS DONT UNE REPONSE NE RESSEMBLE PAS AU HTML.
ok( 'un saut de ligne vaut une espace',   $ml_go( "<p>a bomber\n  jacket</p>", 'bomber jacket' )['applied'], 1 );
ok( 'une espace insecable aussi',         $ml_go( '<p>a bomber&nbsp;jacket</p>', 'bomber jacket' )['applied'], 1 );
ok( 'une esperluette encodee aussi',      $ml_go( '<p>bags &amp; packs</p>', 'bags & packs', 'Bags & packs' )['applied'], 1 );
ok( 'une apostrophe courbe aussi',        $ml_go( '<p>the pilot’s coat</p>', "the pilot's coat", "Pilot's coat" )['applied'], 1 );
ok( 'et la casse ne compte pas',          $ml_go( '<p>The Bomber Jacket</p>', 'bomber jacket' )['applied'], 1 );

// CE QUI DOIT TOUJOURS ETRE REFUSE. La tolerance sur la FORME ne doit rien
// relacher sur le FOND : un lien dans un lien, un empan a cheval sur deux
// paragraphes, des mots presents deux fois.
ok( 'deux occurrences restent refusees',  $ml_go( '<p>bomber jacket et bomber jacket</p>', 'bomber jacket' )['applied'], 0 );
ok( 'un lien dans un lien reste refuse',  $ml_go( '<p><a href="/x">bomber jacket</a></p>', 'bomber jacket' )['applied'], 0 );
ok( 'a cheval sur deux paragraphes, refuse', $ml_go( '<p>a bomber</p><p>jacket</p>', 'bomber jacket' )['applied'], 0 );
ok( 'a cheval sur deux puces, refuse',    $ml_go( '<ul><li>bomber</li><li>jacket</li></ul>', 'bomber jacket' )['applied'], 0 );
ok( 'et des mots absents restent refuses',$ml_go( '<p>rien ici</p>', 'bomber jacket' )['applied'], 0 );

// ET LE MOTIF LUI-MEME DOIT COMPILER. Ecrit avec « # » pour delimiteur, la
// premiere entite numerique — &#160; — fermait le motif au milieu de lui-meme :
// PCRE repondait « Internal error », find_anchor() repondait null, et TOUT
// etait refuse. Une version pire du defaut qu on reparait.
ok( 'le motif compile, entites numeriques comprises',
	null !== DZE_Category_Content::find_anchor( '<p>a bomber&#160;jacket</p>', 'bomber jacket' ), true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
