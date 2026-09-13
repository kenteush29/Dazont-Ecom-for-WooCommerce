<?php
/**
 * The automatic pass: what it takes, what it queues, and what stops it.
 *
 * Run before every release:  php tools/test-automation.php dazont-ecom
 *
 * This module had NO gate at all, which is remarkable for the one part of the
 * plugin that runs with nobody watching, writes to the shop and spends money.
 * Everything it does happens in cron: no screen shows it as it happens, no
 * click reveals it, and a fault here is discovered weeks later by its damage.
 *
 * The internal linking task is the one the shop runs, and the one the others
 * will be modelled on — "on va se baser sur son fonctionnement pour gérer le
 * reste" — so it is held to its whole contract here, end to end:
 *
 *   - it takes its work from the link GRAPH and carries the addresses the
 *     graph chose, so the pass writes THOSE links and not whatever the page
 *     would have picked on its own;
 *   - the job it queues is the pass that already writes that kind of page —
 *     `cat_links` for a category, `post_links` for an article — because there
 *     is no third linking engine and there must never be one;
 *   - it goes slowly on purpose: one item per tick, a figure per day, and
 *     never the same page twice within a month;
 *   - and it stops for the things that must stop it — the module switched
 *     off, the writing queue switched off, a copy of the shop, the monthly
 *     budget — before it touches anything at all.
 *
 * Two faults it is RED on, both found by reading the pass it is written for:
 *
 *   - A PASS THAT WAS NEVER QUEUED MUST NOT COUNT AS A PASS. The object was
 *     marked as worked on BEFORE the queue was asked, so a queue that refused
 *     the job — the row already waiting, the table gone — left the page
 *     stamped and locked out for three days having had nothing whatever done
 *     to it. Silent, and visible only as a page that never gets its links.
 *   - A TASK THAT HANDS ITS WORK TO THE QUEUE NEEDS THE QUEUE. `task_ready()`
 *     asked for it only when the row NAMES its job kind, and the linking task
 *     cannot name one — its kind depends on the page it lands on. So with the
 *     writing queue switched off the task read as ready, the screen offered
 *     it, and pressing Run answered "that category is already waiting in the
 *     queue", which is a sentence about a queue that is not there.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'DZE_VERSION', 'test' );
define( 'DZE_URL', 'https://kula.test/wp-content/plugins/dazont-ecom/' );
define( 'DZE_FILE', __FILE__ );

// --- WordPress, as much of it as the pass touches --------------------------
function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function esc_js( $s ) { return (string) $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_trim_words( $s, $n = 55, $more = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', (string) $s ), 0, $n ) ) . $more; }
function wp_list_pluck( $rows, $field ) { return array_map( static fn( $r ) => $r[ $field ] ?? null, (array) $rows ); }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function trailingslashit( $s ) { return untrailingslashit( $s ) . '/'; }
function wp_parse_url( $url, $c = -1 ) { return parse_url( (string) $url, $c ); }
function home_url( $p = '/' ) { return 'https://kula.test' . $p; }
function admin_url( $p = '' ) { return 'https://kula.test/wp-admin/' . $p; }
function plugins_url( $p = '', $f = '' ) { return 'https://kula.test/wp-content/plugins/' . $p; }
function current_time( $t ) { return 'Y-m-d' === $t ? gmdate( 'Y-m-d' ) : gmdate( 'Y-m-d H:i:s' ); }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function date_i18n( $f, $t = 0 ) { return gmdate( (string) $f, (int) $t ); }
function number_format_i18n( $n ) { return (string) $n; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function do_action( ...$a ) {}
function apply_filters( $tag, $value = null, ...$a ) { return $value; }
function wp_next_scheduled( $h ) { return time() + 1800; }
function wp_schedule_event( ...$a ) {}
function wp_clear_scheduled_hook( ...$a ) {}
function is_admin() { return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_kses_post( $s ) { return (string) $s; }
function absint( $v ) { return abs( (int) $v ); }
function wp_unslash( $v ) { return $v; }
function current_user_can( $c ) { return true; }
function check_ajax_referer( $a, $b = '', $die = true ) { return true; }
// The answer an AJAX action ends the request with, caught rather than exited:
// a handler that ends the request cannot be tested otherwise.
class DZE_Json_Sent extends Exception { public $payload; public $ok;
	public function __construct( $p, $ok ) { parent::__construct( 'sent' ); $this->payload = $p; $this->ok = $ok; } }
function wp_send_json_success( $d = null ) { throw new DZE_Json_Sent( $d, true ); }
function wp_send_json_error( $d = null, $c = 0 ) { throw new DZE_Json_Sent( $d, false ); }
function wp_enqueue_script( ...$a ) {}
// A BODY THAT MOVES TAKES ITS ASSETS WITH IT: the screen is drawn, and what
// it asked for is read back. Called through a page hook somewhere else, the
// one forgotten is the screen that comes out unstyled.
function wp_enqueue_style( ...$a ) { $GLOBALS['styles'][] = (string) ( $a[0] ?? '' ); }
function wp_localize_script( ...$a ) {}
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function get_locale() { return 'en_US'; }
function register_setting( ...$a ) {}
function settings_fields( $g ) {}
function submit_button( $t = '' ) { echo '<button>' . esc_html( $t ) . '</button>'; }
function wc_get_page_id( $w ) { return 0; }
function add_query_arg( $args, $url = '' ) {
	$q = [];
	foreach ( (array) $args as $k => $v ) { $q[] = $k . '=' . rawurlencode( (string) $v ); }
	return ( '' !== $url ? $url : 'https://kula.test/wp-admin/admin.php' ) . '?' . implode( '&', $q );
}
$GLOBALS['menu'] = [];
function add_submenu_page( $parent, $title, $label, $cap, $slug, $cb = null ) {
	$GLOBALS['menu'][] = [ 'parent' => $parent, 'label' => $label, 'slug' => $slug ];
	return $slug;
}
class DZE_Restock { const MENU_SLUG = 'dazont-ecom-restock'; }
// checked() and disabled() answer what WordPress answers: stubbed to '' they
// hide the very questions the screen is drawn to answer.
function checked( $a, $b = true, $echo = true ) { return $a == $b ? " checked='checked'" : ''; }
function selected( $a, $b = true, $echo = true ) { return $a == $b ? " selected='selected'" : ''; }
function disabled( $a, $b = true, $echo = true ) { return $a == $b ? " disabled='disabled'" : ''; }

/**
 * How many products sit behind a category — the figure the size of its
 * description is judged against. It answers `found_posts`, which is the shape
 * the reader reads: a stub answering nothing would make every category read as
 * empty and the whole ranking meaningless.
 */
class WP_Query {
	public $found_posts = 0;
	public function __construct( $args = [] ) {
		$term = (int) ( $args['tax_query'][0]['terms'] ?? 0 );
		$this->found_posts = (int) ( $GLOBALS['behind'][ $term ] ?? 12 );
	}
}
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
// Two branches of categories and two articles, all long enough to carry a
// sentence — plus a page laid out by a page builder, which is a TARGET and
// never a source: its text is not in the post, so a link written into it
// would be stored and never appear.
$GLOBALS['terms'] = [
	10 => [ 'name' => 'Tactical bags',      'slug' => 'tactical-bags',      'parent' => 0,  'description' => '<p>Bags for the field. ' . str_repeat( 'a word about bags ', 40 ) . '</p>' ],
	11 => [ 'name' => 'Tactical backpacks', 'slug' => 'tactical-backpacks', 'parent' => 10, 'description' => '<p>Backpacks. ' . str_repeat( 'a word about backpacks ', 40 ) . '<a href="https://kula.test/category/tactical-bags/">Tactical bags</a></p>' ],
	12 => [ 'name' => 'Boonie hats',        'slug' => 'boonie-hats',        'parent' => 0,  'description' => '<p>Hats for the sun. ' . str_repeat( 'a word about hats ', 40 ) . '</p>' ],
	13 => [ 'name' => 'Tactical gloves',    'slug' => 'tactical-gloves',    'parent' => 0,  'description' => '<p>' . str_repeat( 'a word about gloves ', 40 ) . '</p>' ],
];
$GLOBALS['posts'] = [
	20 => [ 'type' => 'post', 'title' => 'How to choose a tactical backpack', 'content' => '<p>' . str_repeat( 'a backpack word ', 90 ) . '</p>' ],
	21 => [ 'type' => 'post', 'title' => 'Boonie hat sizing',                 'content' => '<p>' . str_repeat( 'a hat word ', 90 ) . '</p>' ],
	23 => [ 'type' => 'page', 'title' => 'Workshop history',                  'content' => '' ],
];
$GLOBALS['pmeta'] = [ 23 => [ '_elementor_data' => '[{"elType":"widget","settings":{"title":"Since 1998"}}]' ] ];
$GLOBALS['tmeta'] = [];

function get_terms( $args = [] ) {
	$out = [];
	foreach ( $GLOBALS['terms'] as $id => $t ) {
		if ( isset( $args['parent'] ) && (int) $t['parent'] !== (int) $args['parent'] ) { continue; }
		if ( isset( $args['child_of'] ) && (int) $t['parent'] !== (int) $args['child_of'] ) { continue; }
		if ( ! empty( $args['exclude'] ) && in_array( $id, (array) $args['exclude'], true ) ) { continue; }
		$out[] = get_term( $id, 'product_cat' );
	}
	return $out;
}
function get_term( $id, $tax = '' ) {
	$t = $GLOBALS['terms'][ (int) $id ] ?? null;
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
function get_term_children( $id, $tax = '' ) {
	$out = [];
	foreach ( $GLOBALS['terms'] as $tid => $t ) {
		if ( (int) $t['parent'] === (int) $id ) { $out[] = $tid; }
	}
	return $out;
}
function get_edit_term_link( $id, $tax = '' ) { return 'https://kula.test/wp-admin/term.php?tag_ID=' . (int) $id; }
function get_edit_post_link( $id, $ctx = '' ) { return 'https://kula.test/wp-admin/post.php?post=' . (int) $id; }
function get_post( $id ) {
	$p = $GLOBALS['posts'][ (int) $id ] ?? null;
	return $p ? (object) [ 'ID' => (int) $id, 'post_title' => $p['title'], 'post_content' => $p['content'], 'post_type' => $p['type'] ] : null;
}
function get_post_type( $id ) { return $GLOBALS['posts'][ (int) $id ]['type'] ?? ''; }
function get_permalink( $id ) { return 'https://kula.test/' . ( 'page' === get_post_type( $id ) ? '' : 'blog/' ) . $id . '/'; }

// The two registers the pass writes on the object itself: when each task last
// worked on it, and the text one that saves straight to the shop replaced.
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['pmeta'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $v ) { $GLOBALS['pmeta'][ (int) $id ][ $key ] = $v; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['pmeta'][ (int) $id ][ $key ] ); return true; }
function get_term_meta( $id, $key = '', $single = false ) { return $GLOBALS['tmeta'][ (int) $id ][ $key ] ?? ''; }
function update_term_meta( $id, $key, $v ) { $GLOBALS['tmeta'][ (int) $id ][ $key ] = $v; return true; }
function delete_term_meta( $id, $key ) { unset( $GLOBALS['tmeta'][ (int) $id ][ $key ] ); return true; }
function delete_metadata( ...$a ) { return true; }
function wp_update_term( $id, $tax, $args ) {
	$GLOBALS['terms'][ (int) $id ]['description'] = (string) ( $args['description'] ?? '' );
	return [ 'term_id' => (int) $id ];
}
function wp_update_post( $args, $err = false ) {
	$GLOBALS['posts'][ (int) $args['ID'] ]['content'] = (string) ( $args['post_content'] ?? '' );
	return (int) $args['ID'];
}

/** The posts table, and the mesh's own. */
class DZE_Auto_Wpdb {
	public $prefix = 'wp_';
	public $posts  = 'wp_posts';
	public $rows   = [];
	public function get_charset_collate() { return ''; }
	public function prepare( $q, ...$a ) {
		foreach ( $a as $one ) {
			$q = preg_replace( '/%[dsf]/', is_int( $one ) ? (string) $one : "'" . $one . "'", (string) $q, 1 );
		}
		return $q;
	}
	public function query( $sql ) {
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
		if ( false !== stripos( $sql, 'from_kind, from_id, to_kind, to_id FROM' ) ) { return $this->rows; }
		if ( false !== stripos( $sql, 'WHERE to_kind' ) ) { return $this->rows; }
		return [];
	}
	public function get_var( $sql ) { return false !== stripos( $sql, 'COUNT(*)' ) ? count( $this->rows ) : null; }
	public function insert( $t, $row ) { return true; }
}
$GLOBALS['wpdb'] = new DZE_Auto_Wpdb();
$wpdb            = $GLOBALS['wpdb'];

/** The writing queue: what was asked of it, and nothing done. */
class DZE_Queue {
	public static array $added = [];
	public static bool $refuse = false;
	public static function add( string $kind, array $ids, bool $auto = false, array $payload = [] ): int {
		if ( self::$refuse ) { return 0; }
		self::$added[] = [ 'kind' => $kind, 'ids' => $ids, 'auto' => $auto, 'payload' => $payload ];
		return count( $ids );
	}
	/** What is already waiting on an object, in the shape the real one answers. */
	public static function pending_for( int $object_id, string $family = 'cat_' ): array { return []; }
	/** How many finished jobs of these kinds are waiting for a decision. */
	public static function review_count_for( array $kinds ): int {
		$n = 0;
		foreach ( $kinds as $k ) { $n += (int) ( $GLOBALS['review_by_kind'][ $k ] ?? 0 ); }
		return $n;
	}
	/** And how many were accepted and written. */
	public static function applied_count_for( array $kinds ): int {
		$n = 0;
		foreach ( $kinds as $k ) { $n += (int) ( $GLOBALS['applied_by_kind'][ $k ] ?? 0 ); }
		return $n;
	}
	public static function url( array $args = [] ): string { return 'https://kula.test/wp-admin/admin.php?page=dazont-ecom-diagnostic&tab=review'; }
	/** WHICH jobs are waiting, in the shape the real reader answers with. */
	public static function review_rows_for( array $kinds, int $limit = 10 ): array {
		$out = [];
		foreach ( $kinds as $k ) {
			foreach ( (array) ( $GLOBALS['review_rows'][ $k ] ?? [] ) as $r ) { $out[] = $r + [ 'kind' => $k ]; }
		}
		return array_slice( $out, 0, max( 1, $limit ) );
	}
	public static function decide_words(): array {
		return [ 'accept' => 'Accept: save this text onto the page it was written for', 'refuse' => 'Refuse: throw this text away' ];
	}
	/** The popup those controls open. Recorded, not drawn: the browser gate
	 *  presses the REAL markup, dumped by test-review.php. */
	public static int $assets = 0;
	public static function review_assets(): void { self::$assets++; }
}
require __DIR__ . '/../' . $dir . '/includes/class-hub.php';
/** The module switches. A class file always exists; this is the real check. */
class DZE_Modules {
	public static function enabled( string $id ): bool { return (bool) ( $GLOBALS['mods'][ $id ] ?? true ); }
}
/** A copy of the shop runs nothing on its own; a button pressed by hand does. */
class DZE_Site {
	public static function autopilot_ok( bool $by_hand = false ): bool { return $by_hand || empty( $GLOBALS['is_copy'] ); }
}
class DZE_Ai_Usage {
	public static function unit( string $u = '' ): void {}
	public static function about( int $id = 0 ): void {}
	public static function over_budget(): bool { return ! empty( $GLOBALS['over_budget'] ); }
}
class DZE_Marketing_Ai {
	// The settings page's own slug: the address the automation screen used to
	// live at, and the one that must still land on it.
	const MENU_SLUG = 'dazont-ecom-ai';
	public static array $asked = [];
	public static function api_key(): string { return 'k'; }
	public static function get_settings(): array { return []; }
	public static function pending_count(): int { return (int) ( $GLOBALS['pending_events'] ?? 0 ); }
	public static function covered_until(): int { return 0; }
	public static function propose( string $from, string $to ): array { self::$asked[] = [ $from, $to ]; return [ 'added' => 2 ]; }
	public static function complete( string $s, string $u, string $m = '', int $x = 0, int $t = 0 ): string { return ''; }
}
class DZE_Wpml {
	public static function ids_in_language( string $type, string $lang ): ?array { return null; }
}

require __DIR__ . '/../' . $dir . '/includes/class-category-content.php';
require __DIR__ . '/../' . $dir . '/includes/class-post-links.php';
require __DIR__ . '/../' . $dir . '/includes/class-mesh.php';
require __DIR__ . '/../' . $dir . '/includes/class-automation.php';

if ( in_array( '--dump-automation', (array) $argv, true ) ) {
	// The screen as the plugin prints it, for the browser gate that reads it
	// folded: a shop with one task on, one off, work waiting and work written.
	$GLOBALS['opts']['dze_auto_settings'] = [ 'tasks' => [
		'mesh_links' => [ 'on' => 1, 'per_day' => 3, 'apply' => 0 ],
		'cat_desc'   => [ 'on' => 0, 'per_day' => 1, 'apply' => 0 ],
	] ];
	$GLOBALS['review_by_kind']  = [ 'cat_links' => 2, 'post_links' => 1 ];
	$GLOBALS['applied_by_kind'] = [ 'cat_links' => 9, 'post_links' => 5 ];
	// TWO of the three shown in place, so the block carries the rows AND the
	// way to the rest: both halves are on the screen the browser gate reads.
	$GLOBALS['review_rows'] = [
		'cat_links'  => [ [ 'id' => 41, 'oid' => 6223, 'label' => 'Tactical backpack covers',
			'job' => 'Category internal links', 'from' => 'Automatic', 'when' => '13/09/2026 18:34' ] ],
		'post_links' => [ [ 'id' => 42, 'oid' => 987632358, 'label' => 'The sniper role: why are they so feared?',
			'job' => 'Article internal links', 'from' => 'Automatic', 'when' => '13/09/2026 20:25' ] ],
	];
	DZE_Mesh::scan();
	ob_start();
	DZE_Automation::render_settings();
	// The figure the REAL census counted on this fake shop, handed over beside
	// the markup: the browser then asserts that what was counted is what the
	// chip prints, rather than a number typed into two files.
	echo wp_json_encode( [
		'html'    => (string) ob_get_clean(),
		'orphans' => DZE_Mesh::orphan_count(),
	] );
	exit( 0 );
}

$ran   = 0;
$fails = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok   %s\n", $what ); return; }
	$fails++;
	printf( "  WRONG %s\n       got  %s\n       want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
/** The shop as it stands before a case: settings, state, registers, queue. */
function fresh( array $tasks = [] ): void {
	$GLOBALS['opts']['dze_auto_settings'] = [ 'tasks' => $tasks ];
	unset( $GLOBALS['opts']['dze_auto_state'] );
	$GLOBALS['tmeta'] = [];
	$GLOBALS['pmeta'] = [ 23 => [ '_elementor_data' => '[{"elType":"widget","settings":{"title":"Since 1998"}}]' ] ];
	$GLOBALS['mods']  = [];
	$GLOBALS['is_copy'] = false;
	$GLOBALS['pending_events'] = 0;
	$GLOBALS['over_budget'] = false;
	DZE_Queue::$added  = [];
	DZE_Queue::$assets = 0;
	$GLOBALS['review_rows'] = [];
	$GLOBALS['styles']      = [];
	DZE_Hub::$more_printed  = false;
	DZE_Queue::$refuse = false;
	delete_transient( 'dze_auto_survey' );
}
$ON = [ 'mesh_links' => [ 'on' => 1, 'per_day' => 3, 'apply' => 0 ] ];

DZE_Mesh::scan(); // the graph is read once a day by its own cron; here, once.

echo "\nThe tasks it offers\n";
$tasks = DZE_Automation::tasks();
ok( 'three of them, and no more',      array_keys( $tasks ), [ 'mesh_links', 'cat_desc', 'events' ] );
// ONE TASK FOR ONE PIECE OF WORK. Linking was two tasks — one for categories,
// one for articles — each mending half a mesh from its own half-blind reading.
ok( 'linking is one task over one graph', $tasks['mesh_links']['scope'], 'mesh' );
ok( 'and it belongs to the mesh module',  $tasks['mesh_links']['module'], 'mesh' );

echo "\nWhat stops it, before it touches anything\n";
fresh();
ok( 'switched off, it says so',        DZE_Automation::why_not( 'mesh_links' ), 'off' );
fresh( $ON );
$GLOBALS['mods'] = [ 'mesh' => 0 ];
ok( 'its own module off, it is not ready', DZE_Automation::task_ready( 'mesh_links' ), false );
ok( 'and the reason names the modules', DZE_Automation::why_not( 'mesh_links' ), 'modules' );
// A TASK THAT HANDS ITS WORK TO THE QUEUE NEEDS THE QUEUE. The linking task
// cannot name its job kind — it depends on the page — so the check that asked
// for the queue only when a kind was named let this one through, and the
// screen offered work that could not be done.
fresh( $ON );
$GLOBALS['mods'] = [ 'queue' => 0 ];
ok( 'the writing queue off, it is not ready', DZE_Automation::task_ready( 'mesh_links' ), false );
ok( 'and it says which switch',        DZE_Automation::why_not( 'mesh_links' ), 'modules' );
fresh( $ON );
$GLOBALS['is_copy'] = true;
ok( 'a copy of the shop runs nothing', DZE_Automation::why_not( 'mesh_links' ), 'copy' );
ok( 'but a button pressed by hand does', DZE_Automation::why_not( 'mesh_links', true ), '' );
fresh( $ON );
$GLOBALS['over_budget'] = true;
ok( 'the budget spent stops it',       DZE_Automation::why_not( 'mesh_links' ), 'budget' );
ok( 'and a press cannot spend past it', DZE_Automation::why_not( 'mesh_links', true ), 'budget' );
fresh( $ON );
ok( 'on, ready, nothing in the way',   DZE_Automation::why_not( 'mesh_links' ), '' );

echo "\nWhat it is about to take\n";
fresh( $ON );
$next = DZE_Automation::shortlist( 'mesh_links', 5 );
ok( 'the graph gives it work',         count( $next ) > 0, true );
$first = $next[0];
ok( 'each row names the page',         '' !== (string) $first['name'], true );
ok( 'and says why it was chosen',      '' !== (string) $first['why'], true );
// THE ADDRESSES THE GRAPH CHOSE TRAVEL WITH THE ROW. Rebuilt from an id
// halfway through, the pass asks a different question — "what would this page
// link to on its own" — and answers it with different pages.
ok( 'and carries the addresses chosen', count( (array) $first['urls'] ) > 0, true );
$builder = false;
foreach ( $next as $row ) { if ( 23 === (int) $row['tid'] ) { $builder = true; } }
ok( 'a page builder page is never a source', $builder, false );

echo "\nOne tick, one job\n";
fresh( $ON );
$res = DZE_Automation::tick();
ok( 'the tick queues something',       $res['queued'], 1 );
ok( 'and says which task did it',      $res['task'], 'mesh_links' );
ok( 'exactly one job, never two',      count( DZE_Queue::$added ), 1 );
$job = DZE_Queue::$added[0];
ok( 'the job is the pass that writes that kind of page',
	in_array( $job['kind'], [ 'cat_links', 'post_links' ], true ), true );
ok( 'on the page the graph named',     $job['ids'], [ (int) $first['tid'] ] );
ok( 'carrying the addresses it chose', $job['payload']['urls'], (array) $first['urls'] );
// NOTHING WRITES TO THE SHOP WITHOUT BEING LOOKED AT: three tasks once
// shipped with "save without review" ticked, and a shop switching one on got
// text written straight onto its categories having chosen nothing.
ok( 'and held for review, not applied', $job['auto'], false );

echo "\nIt goes slowly, on purpose\n";
ok( 'the day is counted',              DZE_Automation::done_today( 'mesh_links' ), 1 );
ok( 'and the next tick waits its turn', DZE_Automation::why_not( 'mesh_links' ), 'early' );
// The same page is not offered again: it was worked on, and a month is the
// cooldown — a site whose fifty pages all change on one afternoon does not
// look like a site being looked after.
$again = DZE_Automation::shortlist( 'mesh_links', 5 );
$same  = false;
foreach ( $again as $row ) { if ( (int) $row['tid'] === (int) $first['tid'] ) { $same = true; } }
ok( 'the page just done is not offered again', $same, false );
// A press by hand skips the spacing — and still takes a different page.
$res2 = DZE_Automation::tick( 'mesh_links', true );
ok( 'a press by hand runs anyway',     $res2['queued'], 1 );
ok( 'on another page',                 DZE_Queue::$added[1]['ids'] !== DZE_Queue::$added[0]['ids'], true );
// The day's figure holds for the automatic pass.
$GLOBALS['opts']['dze_auto_settings'] = [ 'tasks' => [ 'mesh_links' => [ 'on' => 1, 'per_day' => 1, 'apply' => 0 ] ] ];
ok( "today's figure used up",          DZE_Automation::why_not( 'mesh_links' ), 'cap' );
// A DELIBERATE PRESS RUNS. The day's figure is the automatic rhythm, not a
// refusal to answer a button — but it is counted, so the automatic pass does
// that much less, and what protects the shop never yields to it.
ok( 'and a press still runs',          DZE_Automation::why_not( 'mesh_links', true ), '' );

echo "\nA pass that was never queued is not a pass\n";
//
// The object used to be stamped as worked on BEFORE the queue was asked, so a
// queue that refused — the row already waiting, the table gone — left the page
// marked and locked out for three days having had nothing done to it at all.
fresh( $ON );
DZE_Queue::$refuse = true;
$ref = DZE_Automation::tick( 'mesh_links' );
ok( 'a refused job is reported as refused', $ref['reason'], 'busy' );
ok( 'and nothing was queued',          count( DZE_Queue::$added ), 0 );
ok( 'the day is not counted for it',   DZE_Automation::done_today( 'mesh_links' ), 0 );
DZE_Queue::$refuse = false;
$after = DZE_Automation::shortlist( 'mesh_links', 5 );
$back  = false;
foreach ( $after as $row ) { if ( (int) $row['tid'] === (int) $first['tid'] ) { $back = true; } }
ok( 'and the page is still there to be done', $back, true );
ok( 'no register was written on it',   $GLOBALS['tmeta'], [] );

echo "\nWhat it saved, and putting it back\n";
// With "save it on the shop straight away" ticked, the text it replaced is
// kept — and one click puts it back.
fresh( [ 'mesh_links' => [ 'on' => 1, 'per_day' => 3, 'apply' => 1 ] ] );
$pick = DZE_Automation::shortlist( 'mesh_links', 1 )[0];
$was  = 'product_cat' === $pick['kind']
	? (string) $GLOBALS['terms'][ (int) $pick['tid'] ]['description']
	: (string) $GLOBALS['posts'][ (int) $pick['tid'] ]['content'];
DZE_Automation::tick();
ok( 'it goes to the shop when that is ticked', DZE_Queue::$added[0]['auto'], true );
$type = 'product_cat' === $pick['kind'] ? 'term' : 'post';
$kept = 'term' === $type
	? (string) get_term_meta( (int) $pick['tid'], '_dze_auto_prev', true )
	: (string) get_post_meta( (int) $pick['tid'], '_dze_auto_prev', true );
ok( 'the text it replaces is kept',    $kept, $was );
// The pass has written something since; the undo puts back what was there.
if ( 'term' === $type ) { $GLOBALS['terms'][ (int) $pick['tid'] ]['description'] = 'something else'; }
else { $GLOBALS['posts'][ (int) $pick['tid'] ]['content'] = 'something else'; }
ok( 'and one press puts it back',      DZE_Automation::undo( (int) $pick['tid'], $type ), true );
$now = 'term' === $type
	? (string) $GLOBALS['terms'][ (int) $pick['tid'] ]['description']
	: (string) $GLOBALS['posts'][ (int) $pick['tid'] ]['content'];
ok( 'the page holds what it held',     $now, $was );
ok( 'and there is nothing left to undo twice', DZE_Automation::undo( (int) $pick['tid'], $type ), false );

echo "\nThe screen it is read from\n";
fresh( $ON );
ob_start();
DZE_Automation::render_settings();
$html = (string) ob_get_clean();
ok( 'the tab draws',                   '' !== trim( $html ), true );
ok( 'every task is on it',             substr_count( $html, 'class="dze-auto-state"' ), 3 );
ok( 'each one can be run by hand',     substr_count( $html, 'dze-auto-run' ) >= 3, true );
// ONE SHUT BLOCK PER TASK, in the shape every other screen of this plugin
// wears — three tasks are three LINES, not three screens.
ok( 'each task is a block of its own', substr_count( $html, '<details class="dze-set dze-auto-task">' ), 3 );
ok( 'shut until it is opened',         false !== strpos( $html, 'dze-auto-task" open' ), false );
ok( 'and its figures are on the line', substr_count( $html, '<span class="dze-auto-chips"' ), 3 );
// The log is a block too, and it is shut: it is the last thing anybody opens.
ok( 'what it has done is a block too', false !== strpos( $html, 'dze-auto-log' ), true );
// THE WHOLE TOOL RESTS ON THIS LIST, so it is shown and not described: the
// pages it would take next, each with what it is short of.
ob_start();
DZE_Automation::render_state( 'mesh_links' );
$state = (string) ob_get_clean();
ok( 'the state names what it takes next', false !== strpos( $state, 'Next in line' ), true );
ok( 'and names a page of this shop',   false !== strpos( $state, (string) $first['name'] ), true );
ok( 'with a way to that page',         false !== strpos( $state, 'wp-admin' ), true );
// A task that cannot run says so on its own block rather than offering work
// that would fail.
$GLOBALS['mods'] = [ 'mesh' => 0 ];
ob_start();
DZE_Automation::render_settings();
$off = (string) ob_get_clean();
ok( 'a task whose module is off says so', false !== strpos( $off, 'is-blocked' ), true );
$GLOBALS['mods'] = [];

echo "\nWhat each task left for you to decide\n";
//
// "Peut-être afficher un msg sur chaque section automatisation qui nomme
// combien de jobs sont en attente de review pour chacun d'eux ?" A pass that
// runs on its own and says nothing about what it produced is a pass whose work
// is found by accident — and the list it lands on is two clicks away under
// another menu.
fresh( $ON );
$GLOBALS['review_by_kind'] = [];
ok( 'nothing waiting, nothing claimed',  DZE_Automation::waiting_for( 'mesh_links' )['n'], 0 );
// The linking task leaves BOTH kinds of job behind — a category pass and an
// article pass — and the figure on its block is the two together, or it counts
// half its own work.
$GLOBALS['review_by_kind'] = [ 'cat_links' => 2, 'post_links' => 3, 'cat_desc' => 4 ];
ok( 'the linking task counts both its passes', DZE_Automation::waiting_for( 'mesh_links' )['n'], 5 );
ok( 'and the writing task counts its own',     DZE_Automation::waiting_for( 'cat_desc' )['n'], 4 );
// A MESSAGE THAT NAMES A SCREEN IS A WAY TO THAT SCREEN.
ok( 'the figure is a link to the list',
	false !== strpos( DZE_Automation::waiting_for( 'mesh_links' )['url'], 'tab=review' ), true );
// The shop-wide task writes nothing to the queue: what it leaves is a pile of
// suggestions on the screen that owns them.
$GLOBALS['pending_events'] = 3;
ok( 'the calendar task counts its suggestions', DZE_Automation::waiting_for( 'events' )['n'], 3 );
ok( 'and points at the screen holding them',
	false !== strpos( DZE_Automation::waiting_for( 'events' )['url'], 'tab=events' ), true );
// The writing queue switched off answers nought rather than erroring: a class
// file always exists, and the figure is about a table that may not.
$GLOBALS['mods'] = [ 'queue' => 0 ];
ok( 'the queue off, nothing is claimed',  DZE_Automation::waiting_for( 'mesh_links' )['n'], 0 );
$GLOBALS['mods'] = [];
// WHAT IS WAITING IS NOT A SETTING. The block under the fold says what the
// pass is about to TAKE, and nothing about what it left: that is one list, on
// the page, where somebody looking for work to do will find it.
$GLOBALS['review_by_kind'] = [ 'cat_links' => 1, 'post_links' => 0 ];
ob_start();
DZE_Automation::render_state( 'mesh_links' );
$said = (string) ob_get_clean();
ok( 'the settings block holds no work list', false !== strpos( $said, 'dze-auto-job' ), false );
ok( 'and no way out of the page either',     false !== strpos( $said, 'tab=review' ), false );
// AND WHERE IT STANDS IS SAID ONCE, in the symbols on the line above: a
// sentence repeating them under the fold is the same answer twice, which is
// what made this screen unreadable.
ok( 'the rhythm is not said twice',      false !== strpos( $said, 'a day' ), false );

echo "\nThe line says it in symbols\n";
//
// "C'est très brutal, vulgaire, avec énormément de texte de partout… Si un
// module est bien fait, il n'est pas nécessaire d'ajouter du texte partout."
// Four figures, each one a question somebody actually asks — and each of them
// silent when it has nothing to say.
fresh( $ON );
$GLOBALS['review_by_kind']  = [ 'cat_links' => 2, 'post_links' => 1 ];
$GLOBALS['applied_by_kind'] = [ 'cat_links' => 9, 'post_links' => 5 ];
$chips = DZE_Automation::chips_html( 'mesh_links' );
ok( 'it says it is running',             false !== strpos( $chips, 'is-on' ), true );
ok( 'and at what rhythm',                false !== strpos( $chips, '3 a day' ), true );
ok( 'what waits for a person',
	false !== strpos( $chips, 'is-wait' ) && false !== strpos( $chips, '>3<' ), true );
ok( 'and what went through',
	false !== strpos( $chips, 'is-done' ) && false !== strpos( $chips, '>14<' ), true );
ok( 'with the moment it looks again',    false !== strpos( $chips, 'is-next' ), true );
ok( 'every figure carries its own word', substr_count( $chips, 'title="' ) >= 4, true );
// NOTHING TO SAY, NOTHING SAID: a nought on a chip reads as a task that failed.
$GLOBALS['review_by_kind']  = [];
$GLOBALS['applied_by_kind'] = [];
$quietchips = DZE_Automation::chips_html( 'mesh_links' );
ok( 'nothing waiting, no chip',          false !== strpos( $quietchips, 'is-wait' ), false );
ok( 'nothing written, no chip',          false !== strpos( $quietchips, 'is-done' ), false );
// SWITCHED OFF SAYS ONLY THAT: a rhythm nothing runs at is a figure about
// nothing, and a countdown on it is a promise nobody made.
fresh();
$off = DZE_Automation::chips_html( 'mesh_links' );
ok( 'switched off, it says so',          false !== strpos( $off, 'is-off' ), true );
ok( 'and promises no next run',          false !== strpos( $off, 'is-next' ), false );
// A TASK THAT CANNOT RUN SAYS SO ON ITS OWN LINE, rather than being read for.
fresh( $ON );
$GLOBALS['mods'] = [ 'mesh' => 0 ];
ok( 'a module switched off is on the line', false !== strpos( DZE_Automation::chips_html( 'mesh_links' ), 'is-blocked' ), true );
$GLOBALS['mods'] = [];

echo "\nIts own entry, in the WordPress menu\n";
//
// "Je ne vois pas de menu automation dans le plugin, côté gauche de wordpress.
// Déjà ici ça devrait être présent." This screen is not a settings page — it is
// the work: what the site is short of, what is next in line, what was done and
// the undo behind it. One tab among sixteen on Settings is where the one
// function that runs the shop by itself was hardest to find.
$GLOBALS['menu'] = [];
DZE_Automation::register_menu();
ok( 'one entry is registered',         count( $GLOBALS['menu'] ), 1 );
ok( 'under the Dazont Ecom menu',      $GLOBALS['menu'][0]['parent'], DZE_Restock::MENU_SLUG );
ok( 'and it is called Automation',     $GLOBALS['menu'][0]['label'], 'Automation' );
ok( 'on its own address',              $GLOBALS['menu'][0]['slug'], DZE_Automation::MENU_SLUG );
// THE PAGE DRAWS, and it is the same body the settings tab used to print —
// there is one screen, not a copy of it.
fresh( $ON );
ob_start();
DZE_Automation::render_page();
$page = (string) ob_get_clean();
ok( 'the page draws its own heading',  false !== strpos( $page, '<h1>Automation</h1>' ), true );
ok( 'with the tasks on it',            substr_count( $page, 'class="dze-auto-state"' ), 3 );
ok( 'and what it has done',            false !== strpos( $page, 'What it has done' ), true );
// AN ADDRESS THAT USED TO LAND STILL LANDS: a bookmark on the old settings tab
// must not end on a tab that no longer exists.
ok( 'the old settings address is sent here',
	DZE_Automation::moved( [ 'page' => 'dazont-ecom-ai', 'tab' => 'automation' ] ),
	DZE_Automation::page_url() );
ok( 'another settings tab is left alone',
	DZE_Automation::moved( [ 'page' => 'dazont-ecom-ai', 'tab' => 'general' ] ), '' );
ok( 'and so is another page entirely',
	DZE_Automation::moved( [ 'page' => 'dazont-ecom-diagnostic' ] ), '' );

echo "\nEvery reason it can give has words\n";
foreach ( [ 'queued', 'cap', 'none', 'budget', 'modules', 'busy', 'off', 'copy', 'early', 'failed' ] as $why ) {
	ok( 'the shop is told: ' . $why, '' !== DZE_Automation::reason_text( $why ), true );
}

echo "\nWhat is waiting is the work, in one list, on the page\n";
//
// "On devrait plutôt lister les tâches à review pour une meilleure UI, plutôt
// que de les lister dans les paramètres de l'automatisme." It was a fold
// inside each task's own controls, so reading what three passes had left meant
// opening three blocks of settings. Every list of things waiting for a
// decision is ONE list.
$ROWS = [
	'cat_links'  => [ [ 'id' => 41, 'oid' => 6223, 'label' => 'Tactical backpack covers',
		'job' => 'Category internal links', 'from' => 'Automatic', 'when' => '13/09/2026 18:34' ] ],
	'post_links' => [ [ 'id' => 42, 'oid' => 987632358, 'label' => 'The sniper role: why are they so feared?',
		'job' => 'Article internal links', 'from' => 'Automatic', 'when' => '13/09/2026 20:25' ] ],
	'cat_desc'   => [ [ 'id' => 43, 'oid' => 77, 'label' => 'Boonie hats',
		'job' => 'Category description', 'from' => 'Marie Dupont-Lefevre', 'when' => '13/09/2026 21:02' ] ],
];
/** The one list, drawn the way the screen draws it. */
function dze_waiting(): string {
	ob_start();
	DZE_Automation::render_waiting();
	return (string) ob_get_clean();
}

fresh( $ON );
$GLOBALS['review_rows']    = $ROWS;
$GLOBALS['review_by_kind'] = [ 'cat_links' => 1, 'post_links' => 1, 'cat_desc' => 1 ];
$list = dze_waiting();
// EVERY TASK'S WORK, IN ONE LIST — the linking task queues cat_links AND
// post_links, and the writing task sits in the same list beside them.
ok( 'one list holds every task\'s work', substr_count( $list, 'class="dze-auto-job"' ), 3 );
ok( 'the category the linking wrote',   false !== strpos( $list, 'Tactical backpack covers' ), true );
ok( 'the article beside it',            false !== strpos( $list, 'why are they so feared' ), true );
ok( 'and the description task too',     false !== strpos( $list, 'Boonie hats' ), true );
// OLDEST FIRST, whichever pass wrote it: what has waited longest is offered
// first, and the cap belongs to the LIST rather than to each task.
ok( 'oldest first, across the tasks',
	array_map( 'intval', array_column( array_map(
		static fn( $m ) => [ 'id' => $m ], explode( 'data-id="', $list ) ), 'id' ) )[1] ?? 0, 41 );

// EVERY LIST THAT NAMES AN OBJECT PRINTS ITS ID, and the row opens the object.
ok( 'the row prints the object id',     1 === preg_match( '/class="dze-objid"[^>]*>6223</', $list ), true );
ok( 'and the name opens the object',    false !== strpos( $list, 'term.php?tag_ID=6223' ), true );
ok( 'an article opens as a post',       false !== strpos( $list, 'post.php?post=987632358' ), true );
ok( 'the row says what was done to it', false !== strpos( $list, 'Category internal links' ), true );
ok( 'and when it last moved',           false !== strpos( $list, '13/09/2026 18:34' ), true );

// THE THREE CONTROLS ARE THE REVIEW LIST'S OWN — same classes, same popup,
// same endpoints. A second review surface beside it is two screens that start
// disagreeing about what is waiting.
ok( 'each row opens the one review popup',
	false !== strpos( $list, 'class="button button-small dze-q-open" data-id="41"' ), true );
ok( 'accepts on the line',              false !== strpos( $list, 'dze-q-yes" data-id="41"' ), true );
ok( 'refuses on the line',              false !== strpos( $list, 'dze-q-no" data-id="41"' ), true );
// A FINISHED TEXT IS WORTH A QUESTION: the cross must say it is a refusal, not
// an empty queued line dropped from the queue.
ok( 'and the cross knows it is a refusal', false !== strpos( $list, 'data-status="review"' ), true );
ok( 'in the words the list uses',
	false !== strpos( $list, DZE_Queue::decide_words()['accept'] ), true );

// WHAT IS NOT ON THE LIST, and only that.
ok( 'nothing left over, nothing said',  false !== strpos( $list, 'dze-auto-waiting' ), false );
$GLOBALS['review_by_kind'] = [ 'cat_links' => 8, 'post_links' => 4, 'cat_desc' => 1 ];
$more = dze_waiting();
ok( 'the rest points at the whole list', false !== strpos( $more, '10 more in Content to review' ), true );
ok( 'and it is a way to that screen',    false !== strpos( $more, 'tab=review' ), true );

// A TASK WHOSE WORK WAITS SOMEWHERE ELSE SAYS SO, AND NAMES WHERE: the
// calendar's suggestions are not queue rows and cannot be settled here.
fresh( $ON );
$GLOBALS['pending_events'] = 4;
$cal = dze_waiting();
ok( 'the calendar has no rows to settle', substr_count( $cal, 'class="dze-auto-job"' ), 0 );
ok( 'but it says what is waiting',        false !== strpos( $cal, '4 suggestions waiting · Marketing calendar' ), true );
ok( 'and points at the screen holding them', false !== strpos( $cal, 'tab=events' ), true );
$GLOBALS['pending_events'] = 0;

// AN EMPTY LIST SAYS WHICH EMPTY IT IS. This is the page's own work area: an
// empty space with nothing in it reads as a screen that failed to draw.
fresh( $ON );
ok( 'nothing waiting says so, once',
	false !== strpos( dze_waiting(), 'Nothing is waiting for your yes or no' ), true );

// A CLASS FILE ALWAYS EXISTS: the module is the check.
fresh( $ON );
$GLOBALS['review_rows']    = $ROWS;
$GLOBALS['review_by_kind'] = [ 'cat_links' => 1, 'post_links' => 1 ];
$GLOBALS['mods']['queue']  = false;
ok( 'the queue switched off shows no rows', DZE_Automation::todo( 'mesh_links' ), [] );
$GLOBALS['mods'] = [];

// A BODY THAT MOVES TAKES ITS ASSETS WITH IT. The screen is DRAWN and what it
// asked for is read back: calling the helper proves the helper works and
// nothing about whether the screen ever asks for it.
fresh( $ON );
$GLOBALS['review_rows']    = $ROWS;
$GLOBALS['review_by_kind'] = [ 'cat_links' => 1, 'post_links' => 1 ];
ob_start();
DZE_Automation::render_settings();
$screen = (string) ob_get_clean();
ok( 'the screen enqueues its own styles', in_array( 'dze-content', (array) $GLOBALS['styles'], true ), true );
ok( 'and asks for the popup it opens',    DZE_Queue::$assets, 1 );
ok( 'the work is on the page itself',     substr_count( $screen, 'class="dze-auto-job"' ), 2 );
ok( 'under one heading, not three folds', substr_count( $screen, 'id="dze-auto-waiting"' ), 1 );
ok( 'and it is not folded away',          false !== strpos( $screen, '<h2 class="dze-auto-h2">To review</h2>' ), true );
// AND NOT A POPUP ON A SCREEN WITH NOTHING TO DECIDE: an editor loaded for
// nobody is weight on every page load.
fresh( $ON );
ob_start();
DZE_Automation::render_settings();
ob_end_clean();
ok( 'nothing waiting, no popup loaded',   DZE_Queue::$assets, 0 );

echo "\nA line somebody can read, and the mechanism one press away\n";
//
// "Ça j'ai rien compris… J'aurais plutôt écrit un texte simple et compréhensif :
// déléguer à Dazont Ecom le maillage interne du site web. Avec une très courte
// description derrière de ce qu'il fait. On pourrait d'ailleurs comme dans les
// autres modules utiliser un bouton I qui charge plus d'info pour la curiosité."
fresh( $ON );
ob_start();
DZE_Automation::render_settings();
$screen = (string) ob_get_clean();
foreach ( DZE_Automation::tasks() as $tid => $task ) {
	// SHORT, or it is the wall of text he photographed. One sentence of what
	// is being handed over, one of what it does.
	ok( sprintf( '"%s" says it in a line', $task['label'] ),
		strlen( (string) $task['what'] ) <= 130, true );
	ok( 'and it says what is delegated',
		false !== stripos( (string) $task['what'], 'Dazont Ecom' ), true );
	// AND THE MECHANISM IS THERE FOR WHOEVER WANTS IT, never in the way.
	ok( 'the detail is one press away',
		strlen( (string) ( $task['more'] ?? '' ) ) > 200, true );
	ok( 'and the "?" is on its line',
		false !== strpos( $screen, 'dze-mod-more" data-module="' . $tid . '"' ), true );
	ok( 'with the long text behind it',
		false !== strpos( $screen, substr( wp_json_encode( (string) $task['more'] ), 1, 40 ) ), true );
	ok( 'under the task\'s own name',
		false !== strpos( $screen, '"' . $tid . '":{"title":"' . $task['label'] . '"' ), true );
}
// THE LONG TEXT IS NOT PRINTED AS PROSE — that is the whole point of the "?".
// It travels inside the popup's own data and appears only when pressed.
$dze_visible = strip_tags( (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $screen ) );
ok( 'the mechanism is not read out on the page',
	false !== strpos( $dze_visible, 'reads the whole site once' ), false );
ok( 'and the popup it opens is on the page',
	false !== strpos( $screen, 'id="dze-mod-popup"' ), true );

// A DECISION MOVES THE FIGURES AND THE LIST TOGETHER: they are one question,
// so they are re-read together rather than one of them guessing.
fresh( $ON );
$GLOBALS['review_rows']    = $ROWS;
$GLOBALS['review_by_kind'] = [ 'cat_links' => 1, 'post_links' => 1, 'cat_desc' => 1 ];
try {
	DZE_Automation::ajax_state();
	$sent = null;
} catch ( DZE_Json_Sent $e ) {
	$sent = $e->ok ? (array) $e->payload : null;
}
ok( 'the answer carries every task\'s line', array_keys( (array) ( $sent['chips'] ?? [] ) ), [ 'mesh_links', 'cat_desc', 'events' ] );
ok( 'each one its own',                  false !== strpos( (string) ( $sent['chips']['mesh_links'] ?? '' ), 'data-task="mesh_links"' ), true );
ok( 'and the one list beside them',      substr_count( (string) ( $sent['waiting'] ?? '' ), 'class="dze-auto-job"' ), 3 );
ok( 'and what became of the last pass',  array_key_exists( 'log', (array) $sent ), true );

echo "\nThe pages nothing points at, beside the pass that mends them\n";
//
// "Il est impératif d'inscrire l'info quelque part. Que pour lier les pages
// orphelines de liens entrant, seul le module d'automatisation peut faire le
// travail. Ce serait bien d'avoir un système simplifié de comptage."
fresh( $ON );
$GLOBALS['opts']['dze_mesh_census'] = [
	'per'    => [],
	'counts' => [ 'pages' => 830, 'links' => 2104, 'orphans' => 41, 'short' => 96, 'ends' => 12 ],
	'at'     => time() - 3600,
];
$chips = DZE_Automation::chips_html( 'mesh_links' );
ok( 'the figure is on the task that mends them',
	1 === preg_match( '/is-orphan[^>]*>.*?41</s', $chips ), true );
ok( 'and it says so on its own hover',
	false !== strpos( $chips, 'the only pass that mends them' ), true );
// AND ON NO OTHER TASK: the writing task and the calendar do not touch the
// graph, and a figure on a line that cannot act on it is noise.
ok( 'never on the writing task',         false !== strpos( DZE_Automation::chips_html( 'cat_desc' ), 'is-orphan' ), false );
ok( 'nor on the calendar',               false !== strpos( DZE_Automation::chips_html( 'events' ), 'is-orphan' ), false );
// A CHIP IS SILENT WHEN IT HAS NOTHING TO SAY: a nought reads as a task that
// failed, and a site never read has counted nothing at all.
$GLOBALS['opts']['dze_mesh_census']['counts']['orphans'] = 0;
ok( 'nothing orphaned, nothing said',    false !== strpos( DZE_Automation::chips_html( 'mesh_links' ), 'is-orphan' ), false );
unset( $GLOBALS['opts']['dze_mesh_census'] );
ok( 'never read, nothing said either',   false !== strpos( DZE_Automation::chips_html( 'mesh_links' ), 'is-orphan' ), false );

// AND THE PRESS SAYS WHAT IT LEAVES TO THE DAILY PASS, on its own hover: it
// fills outgoing links and mends nothing that is orphaned.
fresh( $ON );
ob_start();
DZE_Automation::render_settings();
$screen = (string) ob_get_clean();
ok( 'the catch-up says what it fills',
	false !== strpos( $screen, 'Fills the outgoing links of every page under its own quota' ), true );
ok( 'and what it does not',
	false !== strpos( $screen, 'Pages nothing points at are mended by the daily pass instead' ), true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
