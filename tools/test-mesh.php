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
	10 => [ 'name' => 'Tactical bags',      'slug' => 'tactical-bags',      'parent' => 0, 'description' => '<p>Bags for the field.</p>' ],
	11 => [ 'name' => 'Tactical backpacks', 'slug' => 'tactical-backpacks', 'parent' => 10, 'description' => '<p>Backpacks, one hundred words about them, and <a href="https://kula.test/category/tactical-bags/">Tactical bags</a> above.</p>' ],
	12 => [ 'name' => 'Boonie hats',        'slug' => 'boonie-hats',        'parent' => 0, 'description' => '<p>Hats for the sun.</p>' ],
	13 => [ 'name' => 'Tactical gloves',    'slug' => 'tactical-gloves',    'parent' => 0, 'description' => '' ],
];
$GLOBALS['posts'] = [
	20 => [ 'type' => 'post', 'title' => 'How to choose a tactical backpack', 'content' => '<p>' . str_repeat( 'a backpack word ', 90 ) . '<a href="https://kula.test/category/tactical-backpacks/">Tactical backpacks</a></p>' ],
	21 => [ 'type' => 'post', 'title' => 'Boonie hat sizing',                 'content' => '<p>' . str_repeat( 'a hat word ', 90 ) . '</p>' ],
	22 => [ 'type' => 'page', 'title' => 'About the boonie workshop',         'content' => '' ],
	// A builder page that points at nothing: the one shape that used to sit at
	// the top of both lists at once, unlinkable and blamed for it.
	23 => [ 'type' => 'page', 'title' => 'Workshop history',                  'content' => '' ],
];
$GLOBALS['meta'] = [
	22 => [ '_elementor_data' => '[{"elType":"widget","settings":{"link":{"url":"https:\/\/kula.test\/category\/boonie-hats\/"}}}]' ],
	23 => [ '_elementor_data' => '[{"elType":"widget","settings":{"title":"Since 1998"}}]' ],
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
		'count'            => 5,
	] ) : null;
}
function get_term_link( $t ) {
	$id = is_object( $t ) ? (int) $t->term_id : (int) $t;
	return 'https://kula.test/category/' . ( $GLOBALS['terms'][ $id ]['slug'] ?? '' ) . '/';
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
	public function get_charset_collate() { return ''; }
	public function prepare( $q, ...$a ) {
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
			foreach ( $GLOBALS['posts'] as $id => $p ) {
				$rows[] = [ 'ID' => $id, 'post_title' => $p['title'], 'post_type' => $p['type'], 'post_content' => $p['content'] ];
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
	public static function add( string $kind, array $ids, bool $auto = false, array $payload = [] ): int {
		self::$added[] = [ 'kind' => $kind, 'ids' => $ids, 'payload' => $payload ];
		return count( $ids );
	}
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
class DZE_Keywords_Absent {}

require __DIR__ . '/../' . $dir . '/includes/class-category-content.php';
require __DIR__ . '/../' . $dir . '/includes/class-post-links.php';
require __DIR__ . '/../' . $dir . '/includes/class-mesh.php';

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
ok( 'every page of the mesh is in it',  $counts['pages'], 8 );
ok( 'and every internal link',          $counts['links'], 3 );
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
$GLOBALS['tr']['dze_cc_cats'] = [];
foreach ( $GLOBALS['terms'] as $id => $t ) {
	$GLOBALS['tr']['dze_cc_cats'][] = [ 'id' => $id, 'name' => $t['name'], 'slug' => $t['slug'], 'url' => get_term_link( $id ) ];
	$GLOBALS['tr'][ 'dze_cc_pcount_' . $id ] = 5;
}
$GLOBALS['tr']['dze_cc_pages'] = [
	[ 'id' => 20, 'title' => 'How to choose a tactical backpack', 'slug' => 'choose-backpack', 'url' => get_permalink( 20 ), 'kind' => 'blog post' ],
	[ 'id' => 21, 'title' => 'Boonie hat sizing', 'slug' => 'boonie-sizing', 'url' => get_permalink( 21 ), 'kind' => 'blog post' ],
];
// The rest of the catalogue, which is where a shop's candidates actually come
// from: half of it is called "tactical", and one of them shares the word that
// says something.
foreach ( [ 'Tactical boots', 'Tactical helmets', 'Tactical vests', 'Bag rain covers' ] as $i => $name ) {
	$GLOBALS['tr']['dze_cc_cats'][] = [
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
ok( 'nothing is ticked beyond the ceiling', count( $ticked ) <= 12, true );

// A CHOSEN TARGET IS A TARGET. The pool would never have offered Boonie hats
// here — no shared word — and the whole Linking screen rests on being able to
// ask for exactly the link the mesh is short of.
$GLOBALS['tr']['dze_mesh_pages'] = DZE_Mesh::pages( true );
$picked = DZE_Category_Content::link_pool( 10 );
ok( 'the pool alone does not offer it', in_array( 'Boonie hats', wp_list_pluck( $picked, 'label' ), true ), false );
ok( 'and the mesh can still find it',   DZE_Mesh::page_by_url( 'https://kula.test/category/boonie-hats/' )['title'] ?? '', 'Boonie hats' );

echo "\nThe pass that writes the link is given the page that was picked\n";
// THE HALF THAT MAKES THE SCREEN WORK. The pool answers "what would this
// article link to on its own"; the Linking screen asks for the link the MESH
// is short of, and those are not the same question. Dropped for not being in
// the pool, a press on that screen answered with nothing at all.
$GLOBALS['tr']['dze_mesh_pages'] = DZE_Mesh::pages( true );
DZE_Marketing_Ai::$sent   = [];
DZE_Marketing_Ai::$answer = $GLOBALS['posts'][20]['content'];
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
	'post_page'       => [ 22 => 'en', 23 => 'en' ],
];
$GLOBALS['asked_wpml'] = [];
$titles = wp_list_pluck( DZE_Mesh::pages( true ), 'title' );
ok( 'the shop keeps its own pages',     in_array( 'Tactical bags', $titles, true ), true );
ok( 'a translated category is not a second page',
	in_array( 'Taktische Taschen', $titles, true ), false );
ok( 'nor is a translated article',      in_array( 'Wie wählt man einen Rucksack', $titles, true ), false );
ok( 'so the count is the shop, once',   count( $titles ), 8 );
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
	count( DZE_Mesh::pages( true ) ), 10 );
$GLOBALS['deflang'] = '';
unset( $GLOBALS['terms'][14], $GLOBALS['posts'][24] );
DZE_Mesh::pages( true );

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
// a row of objects has shipped pointing at a preferences screen.
ok( 'no row points at a settings page', preg_match( '#href="[^"]*page=dazont-ecom[^"]*"#', $screen ), 0 );

// The markup the browser gate presses, so no copy of it is ever written into
// a JavaScript file and left to drift.
if ( in_array( '--dump-tab', $argv, true ) ) {
	file_put_contents( 'php://stderr', sprintf( "\n%d checks, %d wrong\n", $ran, $fails ) );
	echo $screen;
	exit( $fails ? 1 : 0 );
}

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
