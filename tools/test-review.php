<?php
/**
 * Nothing reaches the shop unlooked at, and what did is remembered.
 *
 * Run before every release:  php tools/test-review.php dazont-ecom
 *
 * Two halves of one promise, and both were broken in ways no other gate can
 * see:
 *
 * 1. Three automation tasks SHIPPED with "save without review" ticked. A shop
 *    that switched the task on got text written straight onto its categories
 *    and articles, having chosen nothing — it was the default. "Aucune
 *    fonction deleguee a 100%" is the rule, and a default that breaks it is
 *    still breaking it.
 * 2. The queue's Clear button deleted every APPLIED row. That is the only
 *    place recording that a product was worked on, when, and that somebody
 *    said yes — so after a clear there was no way to check anything without
 *    reopening the product. Which is exactly why nothing here could be
 *    trusted.
 *
 * Neither is provable by reading the code: one is a value in an array, the
 * other is a word in an SQL string. Both are run here.
 */
$dir = $argv[1] ?? 'dazont-ecom';

// A WordPress root with just enough in it for the installer to be RUN rather
// than read: maybe_install() requires upgrade.php and calls dbDelta, and a
// schema nobody runs is a column nobody has.
$dze_root = sys_get_temp_dir() . '/dze-review-' . getmypid() . '/';
@mkdir( $dze_root . 'wp-admin/includes', 0777, true );
file_put_contents(
	$dze_root . 'wp-admin/includes/upgrade.php',
	'<?php function dbDelta( $sql ) { $GLOBALS["queue_schema"] = (string) $sql; return []; }'
);
register_shutdown_function( static function () use ( $dze_root ) {
	@unlink( $dze_root . 'wp-admin/includes/upgrade.php' );
	@rmdir( $dze_root . 'wp-admin/includes' );
	@rmdir( $dze_root . 'wp-admin' );
	@rmdir( $dze_root );
} );
define( 'ABSPATH', $dze_root );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DZE_URL', 'http://shop.test/wp-content/plugins/dazont-ecom/' );
define( 'DZE_VERSION', 'test' );

function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return $n > 1 ? $b : $a; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_js( $s ) { return addslashes( (string) $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_unslash( $v ) { return $v; }
function add_action() {} function add_filter() {} function register_setting() {}
function is_admin() { return true; }
function current_time( $t = 'mysql' ) {
	return 'timestamp' === $t ? strtotime( '2026-09-03 12:00:00' ) : '2026-09-03 12:00:00';
}
function admin_url( $p = '' ) { return 'http://shop.test/wp-admin/' . $p; }
function add_query_arg( ...$a ) { return 'http://shop.test/queue'; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function wp_next_scheduled( $h ) { return 0; }
function wp_schedule_event() {} function wp_clear_scheduled_hook( $h ) {}
function delete_metadata( ...$a ) { return true; }
function get_term( $id, $tax = '' ) {
	return isset( $GLOBALS['terms'][ (int) $id ] )
		? (object) [ 'term_id' => (int) $id, 'name' => 'Category ' . (int) $id, 'description' => (string) $GLOBALS['terms'][ (int) $id ] ]
		: null;
}
// A FIELD WRITTEN BY ONE PATH MUST BE READ BACK BY THE OTHER: these used to
// throw the write away, so the register that keeps a page from being worked on
// twice in a month could not be exercised at all.
function update_term_meta( $id, $k, $v ) { $GLOBALS['tmeta'][ (int) $id ][ $k ] = $v; return true; }
function get_term_meta( $id, $k = '', $single = false ) { return $GLOBALS['tmeta'][ (int) $id ][ $k ] ?? ''; }
function get_post_meta( $id, $k = '', $single = false ) { return $GLOBALS['pmeta'][ (int) $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['pmeta'][ (int) $id ][ $k ] = $v; return true; }
function wp_schedule_single_event( ...$a ) { return true; }
function wp_remote_post( ...$a ) { $GLOBALS['kicked'][] = 1; return []; }
function get_terms( $args = [] ) { return array_values( $GLOBALS['terms_all'] ?? [] ); }
function get_term_link( $t ) {
	$slug = strtolower( str_replace( ' ', '-', is_object( $t ) ? $t->name : (string) $t ) );
	return 'http://shop.test/category/' . $slug . '/';
}
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function get_post( $id = 0 ) { return $GLOBALS['posts_all'][ (int) $id ] ?? null; }
// A ROW NAMES ITS OBJECT, and an article's name comes from the post — the
// popup was dying here the moment it was asked about one.
function get_the_title( $id = 0 ) {
	$p = $GLOBALS['posts_all'][ (int) $id ] ?? null;
	return $p ? (string) $p->post_title : '';
}
function html_entity_decode_stub( $s ) { return $s; }
function wp_kses_post( $s ) { return (string) $s; }
// Accepting a category description WRITES it: the harness records the write
// rather than pretending it did not happen.
function wp_update_term( $id, $tax, $args = [] ) { $GLOBALS['wrote'][] = [ (int) $id, $args ]; return [ 'term_id' => (int) $id ]; }
function wp_update_post( $args = [], $err = false ) { $GLOBALS['wrote'][] = $args; return (int) ( $args['ID'] ?? 0 ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function get_edit_term_link( $id, $tax = '' ) { return 'http://shop.test/wp-admin/term.php?tag_ID=' . (int) $id; }
function get_edit_post_link( $id, $ctx = '' ) { return 'http://shop.test/wp-admin/post.php?post=' . (int) $id; }
function wp_list_pluck( $rows, $field ) { return array_map( static fn( $r ) => is_array( $r ) ? ( $r[ $field ] ?? null ) : ( $r->$field ?? null ), (array) $rows ); }
class WP_Error { public function get_error_message() { return 'error'; } }
function current_user_can( $c ) { return true; }
function check_ajax_referer( $a, $b = '', $die = true ) { return true; }
class DZE_Json_Sent extends Exception { public $payload; public $ok;
	public function __construct( $p, $ok ) { parent::__construct( 'sent' ); $this->payload = $p; $this->ok = $ok; } }
function wp_send_json_success( $d = null ) { throw new DZE_Json_Sent( $d, true ); }
function wp_send_json_error( $d = null, $c = 0 ) { throw new DZE_Json_Sent( $d, false ); }

$GLOBALS['opts'] = [];
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function mysql2date( $format, $date, $translate = true ) {
	$t = strtotime( (string) $date );
	return false === $t ? '' : gmdate( (string) $format, $t );
}
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
// A REAL TRANSIENT STORE, because the writer's lock is a transient and a
// harness answering `false` to every read cannot be red on a lock that is
// never let go.
function get_transient( $k ) {
	$r = $GLOBALS['tr'][ $k ] ?? null;
	if ( null === $r ) { return false; }
	if ( $r['until'] && $r['until'] < time() ) { unset( $GLOBALS['tr'][ $k ] ); return false; }
	return $r['v'];
}
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = [ 'v' => $v, 'until' => $t ? time() + (int) $t : 0 ]; return true; }
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); return true; }

class DZE_Modules { public static function enabled( $id ) { return ! in_array( $id, (array) ( $GLOBALS['off'] ?? [] ), true ); } }
class DZE_Restock { const MENU_SLUG = 'dazont-ecom'; }
/** The module that hosts the Content tabs — present unless a test says not. */
class DZE_Diagnostic { const MENU_SLUG = 'dazont-ecom-diagnostic'; }
function get_current_user_id() { return (int) ( $GLOBALS['uid'] ?? 0 ); }
function get_userdata( $id ) {
	$who = $GLOBALS['users'][ (int) $id ] ?? '';
	return $who ? (object) [ 'display_name' => $who ] : false;
}
function wp_safe_redirect( $to, $status = 302 ) { $GLOBALS['went'] = (string) $to; throw new DZE_Went( 'went' ); }
class DZE_Went extends Exception {}
/**
 * The two linking passes, standing in for the writing. What is asked of them
 * is the whole point: a job carries the pages that were PICKED, and a pass
 * that is not handed them writes whatever it would have written anyway.
 */
class DZE_Post_Links {
	public static array $asked = [];
	public static function add_links( int $post_id, array $only = [] ): string {
		self::$asked[] = [ 'id' => $post_id, 'only' => $only ];
		return '<p>linked</p>';
	}
}
/** The link graph, when the shop has read itself. */
/** The monthly budget guard: the one thing a press by hand never runs past. */
class DZE_Ai_Usage {
	public static function unit( string $u = '' ): void {}
	public static function about( int $id = 0 ): void {}
	public static function over_budget(): bool { return ! empty( $GLOBALS['over_budget'] ); }
}
class DZE_Mesh {
	public static function census(): array { return $GLOBALS['mesh_census'] ?? []; }
	public static function plan( int $limit = 5 ): array {
		return array_slice( $GLOBALS['mesh_plan'] ?? [], 0, $limit );
	}
	/** The second phase: pages under their own outgoing quota. */
	public static function thin( int $limit = 200 ): array {
		return array_slice( $GLOBALS['mesh_thin'] ?? [], 0, $limit );
	}
	/** Read again next time: a link was written, or the shop was re-read. */
	public static function forget_thin(): void { $GLOBALS['mesh_forgot'] = true; }
}
class DZE_Category_Content {
	public const GEN_META = '_dze_desc_generated';
	public static array $asked = [];
	public static function default_lang(): string { return ''; }
	public static function linked_urls( string $html ): array {
		preg_match_all( '/<a\s[^>]*href="([^"]+)"/i', $html, $m );
		return $m[1];
	}
	public static function add_links( int $term_id, string $html, array $only = [] ): array {
		// WHAT IT WAS ASKED TO LINK is half the question, and it was the half
		// nobody read back: the pass was handed the STORED description while
		// the person was looking at an editor holding something else.
		self::$asked[] = [ 'id' => $term_id, 'html' => $html, 'only' => $only ];
		return [ 'html' => '<p>linked</p>' ];
	}
}
/** The product half of "what is waiting for me", which lives in its own store. */
class DZE_Content {
	const BULK_SLUG = 'dazont-content-bulk';
	public static function pending_count() { return (int) ( $GLOBALS['bulk_pending'] ?? 0 ); }
	public static function bulk_url() { return 'http://shop.test/wp-admin/edit.php?post_type=product&page=dazont-content-bulk'; }
}
$GLOBALS['menu_added'] = [];
$GLOBALS['menu_gone']  = [];
function add_submenu_page( $parent, $title, $menu, $cap, $slug, $fn = null ) {
	$GLOBALS['menu_added'][ (string) $slug ] = [ 'parent' => $parent, 'title' => $title, 'menu' => $menu ];
	return 'hook_' . $slug;
}
function remove_submenu_page( $parent, $slug ) { $GLOBALS['menu_gone'][] = (string) $slug; return true; }
function wp_enqueue_style( ...$a ) {} function wp_enqueue_script( ...$a ) {}
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['loc'][ $name ] = $data; }
function wp_create_nonce( $a = '' ) { return 'n'; }
function wp_enqueue_editor() {}

/** Every statement the queue sends, kept so it can be read back. */
class DZE_Review_Wpdb {
	public $prefix = 'wp_';
	public $sent = [];
	public function prepare( $q, ...$a ) { return vsprintf( str_replace( [ '%s', '%d' ], [ "'%s'", '%d' ], $q ), $a ); }
	public function query( $q ) { $this->sent[] = (string) $q; return 3; }
	public function get_var( $q ) {
		$this->sent[] = (string) $q;
		// THE TABLE IS THERE. Answered `0`, every reading that checks for its
		// own table first bailed out before asking anything, and a check on
		// what it answers passes for the harness's reasons.
		if ( 0 === stripos( trim( (string) $q ), 'SHOW TABLES LIKE' ) ) {
			return 'wp_dze_queue';
		}
		// When the queue last moved, which is the only thing that can tell a
		// run in progress from a run that has stopped.
		if ( false !== stripos( (string) $q, 'MAX(updated)' ) ) {
			return $GLOBALS['q_last_moved'] ?? null;
		}
		// A queue that REFUSES: the row is already queued, running or waiting
		// for a decision. "A pass that was never queued is not a pass."
		if ( ! empty( $GLOBALS['queue_busy'] ) && false !== stripos( (string) $q, "status IN ('queued','running','review')" ) ) {
			return 1;
		}
		return 0;
	}
	public function get_results( $q, $m = null ) {
		$this->sent[] = (string) $q;
		if ( false !== stripos( (string) $q, 'GROUP BY status' ) ) {
			return $GLOBALS['status_counts'] ?? [];
		}
		// The runs the host killed mid-way, asked for by recover() alone. Kept
		// apart from the rows every other check reads, or a harness that hands
		// its whole fake queue back as "stale" clears the lock for the wrong
		// reason and the check goes green on broken code.
		if ( false !== stripos( (string) $q, "status = 'running' AND updated <" ) ) {
			return $GLOBALS['stale_rows'] ?? [];
		}
		// The rows a run called off is about to drop, read before the delete.
		if ( false !== stripos( (string) $q, "status IN ('queued','failed')" ) ) {
			return $GLOBALS['waiting_rows'] ?? [];
		}
		// WHAT WAS ACCEPTED on each object. Answered with the fake shop's
		// generic rows it hands back documents with no `updated` and no
		// `decided_by` — a stub must answer in the SHAPE the real function
		// answers with, and this one holds nothing unless a check says so.
		if ( false !== stripos( (string) $q, "status = 'applied' AND object_id IN" ) ) {
			// The rows a check laid out for this question, and NOTHING
			// otherwise: the fake shop's generic rows have no `updated` and no
			// `decided_by`, and a stub must answer in the shape the real
			// function answers with rather than in whatever shape is to hand.
			return $GLOBALS['applied_rows_sql'] ?? [];
		}
		// WHAT IS ALREADY WAITING ON AN OBJECT, answered from what the queue
		// was actually told to insert. Answered with nothing, the harness
		// claims no page is in hand — and the pass that decides whether to
		// queue a page a second time asks exactly this.
		if ( false !== stripos( (string) $q, "status IN ('queued','running','review')" ) ) {
			$out = [];
			foreach ( (array) ( $GLOBALS['queued'] ?? [] ) as $i => $row ) {
				$out[] = [
					'id'        => $i + 1,
					'kind'      => (string) $row['kind'],
					'object_id' => (int) $row['id'],
					'status'    => 'queued',
				];
			}
			return $out;
		}
		return $GLOBALS['rows'] ?? [];
	}
	public function get_col( $q ) { $this->sent[] = (string) $q; return []; }
	public function get_row( $q, $m = null ) { $this->sent[] = (string) $q; return $GLOBALS['rows'][0] ?? []; }
	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
	public function esc_like( $t ) { return addcslashes( (string) $t, '_%\\' ); }
	public $updates = [];
	public function update( $table, $data, $where, ...$rest ) {
		$this->updates[] = [ 'data' => (array) $data, 'where' => (array) $where ];
		return 1;
	}
	/** What the queue was actually asked to do. */
	public function insert( $table, $row ) {
		$GLOBALS['queued'][] = [
			'kind'    => (string) ( $row['kind'] ?? '' ),
			'id'      => (int) ( $row['object_id'] ?? 0 ),
			'payload' => json_decode( (string) ( $row['payload'] ?? '' ), true ) ?: [],
			// WHO ASKED FOR THE WORK, written at the insert.
			'made_by' => (int) ( $row['made_by'] ?? -1 ),
		];
		return 1;
	}
}
/**
 * Empties the fake queue — AND tells the queue its reading of what is waiting
 * is stale. `pending_map()` keeps that reading for the request, so a harness
 * that clears the table behind its back goes on reporting every page as
 * already in hand, and the press under test queues nothing for a reason that
 * exists only in the test.
 */
function dze_empty_queue(): void {
	$GLOBALS['queued'] = [];
	if ( class_exists( 'DZE_Queue' ) ) { DZE_Queue::forget_count(); }
}

$GLOBALS['wpdb'] = new DZE_Review_Wpdb();
$GLOBALS['rows'] = [];

require __DIR__ . '/../' . $dir . '/includes/class-automation.php';
require __DIR__ . '/../' . $dir . '/includes/class-blocks.php';
require __DIR__ . '/../' . $dir . '/includes/class-hub.php';
require __DIR__ . '/../' . $dir . '/includes/class-queue.php';
require_once __DIR__ . '/../' . $dir . '/includes/class-cleanup.php';

if ( in_array( '--dump-review', (array) $argv, true ) ) {
	$GLOBALS['loc']  = [];
	$GLOBALS['rows'] = [];
	ob_start();
	DZE_Queue::instance()->body();
	$dze_html = (string) ob_get_clean();
	// The review popup ON ITS OWN, from the one function that owns it: the
	// Automation screen borrows these rows and opens this same popup, and its
	// browser gate must press the real markup rather than a copy of it typed
	// into a harness.
	ob_start();
	DZE_Queue::review_assets();
	$dze_modal = (string) ob_get_clean();
	echo wp_json_encode( [
		'html'  => $dze_html,
		'modal' => $dze_modal,
		'cfg'   => $GLOBALS['loc']['dzeQueue'] ?? [],
	] );
	exit( 0 );
}

$fails = 0; $ran = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok    %s\n", $what ); return; }
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}

echo "No task writes to the shop without being looked at\n";
$tasks = DZE_Automation::tasks();
// ONE TASK FOR ONE PIECE OF WORK. Internal linking was two — one for
// categories, one for articles — each mending half a mesh from its own
// half-blind reading, and the shop had to switch on both and know why.
ok( 'internal linking is one task',      isset( $tasks['mesh_links'] ), true );
ok( 'not one per kind of page',
	isset( $tasks['cat_links'] ) || isset( $tasks['post_links'] ), false );
ok( 'and it belongs to the graph that ranks it',
	(string) ( $tasks['mesh_links']['module'] ?? '' ), 'mesh' );
foreach ( [ 'mesh_links', 'cat_desc', 'events' ] as $id ) {
	ok( sprintf( '"%s" is held for review', (string) ( $tasks[ $id ]['label'] ?? $id ) ),
		(int) ( $tasks[ $id ]['apply'] ?? 1 ), 0 );
}
// The box is still THERE — this is a default, not a removal. A shop that
// trusts the pass ticks it, which is the whole point of the switch.
ok( 'and the choice is still offered',  DZE_Automation::conf( 'cat_links' )['apply'], false );
$GLOBALS['opts']['dze_auto_settings'] = [ 'tasks' => [ 'cat_links' => [ 'on' => 1, 'per_day' => 3, 'apply' => 1 ] ] ];
ok( 'a shop that ticked it keeps it',   DZE_Automation::conf( 'cat_links' )['apply'], true );

echo "A shop that never chose it is put back to review, once\n";
// It was the default, so a shop with it ticked never chose it. Turned back
// ONCE and recorded, because overriding the shop's own answer every admin
// page load would be a setting nobody can keep.
$GLOBALS['opts']['dze_auto_settings'] = [ 'tasks' => [
	'cat_links'  => [ 'on' => 1, 'per_day' => 3, 'apply' => 1 ],
	'post_links' => [ 'on' => 1, 'per_day' => 2, 'apply' => 1 ],
] ];
DZE_Automation::migrate();
ok( 'the category pass now waits',      DZE_Automation::conf( 'cat_links' )['apply'], false );
ok( 'and the article pass too',         DZE_Automation::conf( 'post_links' )['apply'], false );
ok( 'the pass is written down',         (string) get_option( 'dze_auto_review_default', '' ), '1' );
// And it never touches the shop's answer again: ticked on purpose, it stays.
$GLOBALS['opts']['dze_auto_settings']['tasks']['cat_links']['apply'] = 1;
DZE_Automation::migrate();
ok( 'a second pass leaves it alone',    DZE_Automation::conf( 'cat_links' )['apply'], true );
// Declared, or it cannot be erased.
ok( 'the flag is declared for cleanup',
	in_array( 'dze_auto_review_default', (array) ( DZE_Cleanup::map()['automation']['options'] ?? [] ), true ), true );

echo "What was accepted is never thrown away by a Clear\n";
$GLOBALS['wpdb']->sent = [];
try { ( DZE_Queue::instance() )->ajax_clear(); } catch ( DZE_Json_Sent $e ) { /* it answers and stops */ }
$sql = implode( ' | ', $GLOBALS['wpdb']->sent );
ok( 'Clear removes what failed',        false !== strpos( $sql, "'failed'" ), true );
ok( 'and what was skipped',             false !== strpos( $sql, "'skipped'" ), true );
// THE LINE THIS FILE EXISTS FOR. An applied row is the record that a product
// was worked on and that somebody said yes to it.
ok( 'and NEVER what was applied',       false !== strpos( $sql, "'applied'" ), false );

echo "And it can be found again, per object\n";
$GLOBALS['applied_rows_sql'] = [
	[ 'id' => 91, 'kind' => 'product_shot', 'object_id' => 501, 'updated' => '2026-09-03 10:00:00', 'decided_by' => 0 ],
	[ 'id' => 90, 'kind' => 'product_shot', 'object_id' => 501, 'updated' => '2026-08-01 09:00:00', 'decided_by' => 0 ],
	[ 'id' => 89, 'kind' => 'cat_desc',     'object_id' => 77,  'updated' => '2026-07-01 09:00:00', 'decided_by' => 0 ],
];
$GLOBALS['wpdb']->sent = [];
$map = DZE_Queue::done_map( [ 501, 77, 999 ] );
ok( 'each object gets its own row',     array_keys( $map ), [ 501, 77 ] );
// The LAST thing done to it, not the first: the row is one line.
ok( 'and the most recent one',          $map[501]['id'], 91 );
ok( 'naming the job that did it',       $map[501]['kind'], 'product_shot' );
ok( 'an object with nothing done is absent', isset( $map[999] ), false );
// One query for a whole page: a lookup per row is fifty queries to draw a list.
ok( 'read in one query',                count( $GLOBALS['wpdb']->sent ), 1 );
$GLOBALS['applied_rows_sql'] = [];
ok( 'and only for applied rows',        false !== strpos( $GLOBALS['wpdb']->sent[0], "status = 'applied'" ), true );
ok( 'asking about nothing asks nothing', DZE_Queue::done_map( [] ), [] );

echo "Every job says what it IS and what it DOES\n";
// "Ce bouton lance une action obscure." A button named "Fix" tells the shop
// nothing about what is about to run; a job carries a verb for that, beside
// the noun the job list uses.
foreach ( DZE_Queue::kinds() as $dze_k => $dze_meta ) {
	ok( sprintf( '"%s" says what it does', (string) $dze_meta['label'] ),
		'' !== trim( (string) ( $dze_meta['does'] ?? '' ) ), true );
}
ok( 'and the linking pass says it plainly',
	(string) ( DZE_Queue::kinds()['cat_links']['does'] ?? '' ), 'Add internal links' );

echo "A linking pass is judged on its LINKS, not on its words\n";
// "Il est affiche la difference de mots avant/apres mais il manque la
// difference de quantite de liens." On a linking pass the word count is
// IDENTICAL by design — "1094 words → 1094 words" was the whole of what the
// screen said about a job whose entire purpose is the other figure.
$dze_links = new ReflectionMethod( 'DZE_Queue', 'links_in' );
$dze_links->setAccessible( true );
$dze_before = '<p>Some text with <a href="/a">one</a> and <a href=\'/b\'>two</a>.</p>';
$dze_after  = $dze_before . '<p>More with <a class="x" href="/c">three</a>.</p>';
ok( 'links are counted as the pass counts them', $dze_links->invoke( null, $dze_before ), 2 );
ok( 'and the new ones with them',       $dze_links->invoke( null, $dze_after ), 3 );
ok( 'a text with none has none',        $dze_links->invoke( null, '<p>Nothing here.</p>' ), 0 );
// An anchor that is not a link is not counted: <a name="…"> carries no href.
ok( 'an anchor with no href is not a link',
	$dze_links->invoke( null, '<p><a name="top"></a>Text</p>' ), 0 );
// The word count on a linking pass does not move, which is exactly why the
// other figure has to be there.
ok( 'the words do not move on a linking pass',
	str_word_count( strip_tags( $dze_before ) ) === str_word_count( strip_tags( $dze_after ) ), false );

echo "ONE screen answers 'what is waiting for me'\n";
// "Writing queue » / bulk produit > Pourquoi pas dans un onglet reuni
// (categorie + produits + blog) sous le nom Content to review ?" Two menus
// for one question is two places to remember and two counts that disagree.
// ONE ENTRY FOR ONE SUBJECT: reading what is wrong, doing something about it
// and deciding on what comes back is one piece of work, so this screen is a
// TAB of Content and registers no entry of its own.
$GLOBALS['off'] = [];
$GLOBALS['menu_added'] = [];
$GLOBALS['bulk_pending'] = 0;
DZE_Queue::instance()->menu();
ok( 'hosted, it takes no menu of its own',
	isset( $GLOBALS['menu_added'][ DZE_Queue::MENU_SLUG ] ), false );
ok( 'and it knows that it is',               DZE_Queue::hosted(), true );
// BUT SWITCHING THE HOST OFF MUST NOT TAKE THIS FUNCTION WITH IT. It goes
// back to a page of its own, under Dazont Ecom — never under Products, where
// a screen about everything the plugin writes does not belong.
$GLOBALS['off'] = [ 'diagnostic' ];
$GLOBALS['menu_added'] = [];
DZE_Queue::instance()->menu();
$dze_menu = $GLOBALS['menu_added'][ DZE_Queue::MENU_SLUG ] ?? [];
ok( 'with no host it keeps its own page',    (string) ( $dze_menu['title'] ?? '' ), 'Content to review' );
ok( 'and it hangs off Dazont Ecom',          (string) ( $dze_menu['parent'] ?? '' ), 'dazont-ecom' );
ok( 'and never under Products any more',
	false !== strpos( (string) ( $dze_menu['parent'] ?? '' ), 'post_type=product' ), false );
$GLOBALS['off'] = [];
// EVERY LINK EVER PRINTED AT IT STILL LANDS. A page no longer registered
// under Products does not answer "not found" — WordPress answers "you are not
// allowed to access this page", which reads as a permission the shop lost.
ok( 'the address it moved to',
	DZE_Queue::url(), 'http://shop.test/queue' );
$GLOBALS['pagenow'] = 'edit.php';
$_GET = [ 'post_type' => 'product', 'page' => DZE_Queue::MENU_SLUG, 'paged' => '3' ];
$GLOBALS['went'] = '';
try { DZE_Queue::instance()->moved(); } catch ( DZE_Went $e ) { /* it redirected, which is the point */ }
ok( 'an old bookmark is sent to the new one', '' !== $GLOBALS['went'], true );
// And nothing else is touched: another Products screen is not hijacked.
$_GET = [ 'post_type' => 'product', 'page' => 'dazont-content-bulk' ];
$GLOBALS['went'] = '';
DZE_Queue::instance()->moved();
ok( 'and another screen is left alone',      $GLOBALS['went'], '' );
$GLOBALS['pagenow'] = '';
$_GET = [];
// THE COUNT COUNTS BOTH STORES. A menu saying one while the screen says four
// is the disagreement this merge exists to end — on its own page, and on the
// Content entry that hosts it alike, since both read this figure.
$GLOBALS['off'] = [ 'diagnostic' ];
$GLOBALS['menu_added'] = [];
$GLOBALS['bulk_pending'] = 3;
DZE_Queue::instance()->menu();
ok( 'products waiting are counted on the menu',
	false !== strpos( (string) ( $GLOBALS['menu_added'][ DZE_Queue::MENU_SLUG ]['menu'] ?? '' ), '>3<' ), true );
$GLOBALS['off'] = [];
ok( 'and read from the store that owns them', DZE_Queue::bulk_waiting(), 3 );
$GLOBALS['bulk_pending'] = 0;
ok( 'nothing waiting there counts nothing',   DZE_Queue::bulk_waiting(), 0 );

// The screen SAYS SO and goes there. A count with no way to act on it is a
// number, not a screen.
$GLOBALS['bulk_pending'] = 2;
ob_start();
DZE_Queue::instance()->render();
$dze_page = (string) ob_get_clean();
ok( 'the page is named for what it holds',
	false !== strpos( $dze_page, '<h1>Content to review</h1>' ), true );
// AND IT DOES NOT SEND YOU LOOKING FOR THE SCREEN YOU ARE ON. A blue box
// inside this screen announced that products were waiting somewhere else and
// offered to take you there — read from the chair of somebody who came here
// asking "what is waiting for me?", that is the screen describing itself
// instead of showing the work. Products are a TAB of the Content diagnostic,
// beside this one, with their own count.
ok( 'nothing here points at another waiting list',
	false !== strpos( $dze_page, 'holding content nobody has decided on' ), false );
$GLOBALS['bulk_pending'] = 0;
ob_start();
DZE_Queue::instance()->render();
$dze_quiet = (string) ob_get_clean();
ok( 'and says nothing when nothing waits',
	false !== strpos( $dze_quiet, 'holding content nobody has decided on' ), false );

// AND ONE MENU. The product bulk screen takes its own entry out while this
// screen is the one that lists what is waiting — but keeps it the moment the
// module is off, because switching a module off must never hide a function
// that has nothing to do with it.
$GLOBALS['off'] = [];
ok( 'this screen owns the question',    DZE_Queue::owns_review(), true );
$GLOBALS['off'] = [ 'queue' ];
ok( 'switched off, it owns nothing',    DZE_Queue::owns_review(), false );
$GLOBALS['off'] = [];

echo "And WHO said yes or no is written down\n";
// The installer, RUN — not read. A column declared in a string nobody
// executes is a column the shop does not have.
delete_option( 'dze_queue_schema' );
DZE_Queue::instance()->maybe_install();
// "Rien n'enregistre QUI. Excellente suggestion." Once the work is handed to
// somebody else, "this page was dealt with" without "by whom" is the answer
// nobody can act on. The column is new, so the schema had to move with it.
ok( 'the table carries the decider',
	false !== strpos( $GLOBALS['queue_schema'] ?? '', 'decided_by' ), true );
ok( 'and the schema was bumped for it',
	(int) get_option( 'dze_queue_schema', 0 ) >= 2, true );

$GLOBALS['users'] = [ 7 => 'Marie', 9 => 'Paul' ];
// A NAME, never an id: "12" on a row is a number somebody has to look up.
ok( 'a decision names the person',      DZE_Queue::decided_by( 7 ), 'Marie' );
// An account deleted since keeps its decision — the work was still done.
ok( 'a deleted account still answers',
	false !== strpos( DZE_Queue::decided_by( 4242 ), '4242' ), true );
// Nobody is NOBODY: a scheduled pass that saved without review has no person
// behind it, and naming one would be a lie on the row.
ok( 'an automatic pass names nobody',   DZE_Queue::decided_by( 0 ), '' );

// The sentence the row prints, built in PHP so it is not English on every shop.
ok( 'an accepted job says who accepted it', DZE_Queue::said_by( 'applied', 7 ), 'Accepted by Marie' );
ok( 'a refused one says who refused it',    DZE_Queue::said_by( 'skipped', 9 ), 'Discarded by Paul' );
// A row still WAITING has nobody to name, whatever is in the column.
ok( 'a job still waiting names nobody',     DZE_Queue::said_by( 'review', 7 ), '' );
ok( 'and neither does an automatic one',    DZE_Queue::said_by( 'applied', 0 ), '' );

// IT IS WRITTEN ON EVERY DECISION, not only on one path. Accepting one job,
// refusing one, and the same two in bulk: four places, one column.
// THE OBJECT'S ID TRAVELS TO THE LIST. "Il manque l'ID produit sur ces pages !
// Très important." This list is drawn in the browser, so a figure the server
// never sends is a figure no screen can print, whatever the JavaScript says.
$GLOBALS['rows'] = [ [ 'id' => 5, 'kind' => 'cat_desc', 'object_id' => 3, 'status' => 'review', 'result' => '', 'payload' => '' ] ];
$dze_sent = [];
try { DZE_Queue::instance()->ajax_status(); } catch ( DZE_Json_Sent $e ) { $dze_sent = (array) $e->payload; }
ok( 'the review list carries each row\'s object id',
	(int) ( $dze_sent['rows'][0]['oid'] ?? -1 ), 3 );
// A TABLE WHOSE HEADING IS PRINTED HERE AND WHOSE CELLS ARE BUILT IN THE
// BROWSER IS THE TABLE MOST LIKELY TO END UP A COLUMN OUT OF STEP: the two
// halves are edited months apart and nothing errors when they disagree — every
// row simply prints its values under the wrong titles. So both halves are read
// here, for the two lists drawn that way, and the position is what is asserted.
$dze_head = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-queue.php' );
$dze_js   = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/queue.js' );
ok( 'the review table heads the id right after the item',
	(bool) preg_match( '/\x27Item\x27, \x27dazont-ecom\x27 \); \?><\/th>\s*<\?php echo wp_kses_post\( DZE_Hub::id_th\(\)/', $dze_head ), true );
ok( 'and its cell sits between that name and the job',
	(bool) preg_match( '/esc\(r\.label\)[\s\S]{0,400}?dze-objid-td[\s\S]{0,200}?esc\(r\.kind\)/', $dze_js ), true );
// The empty line spans the whole table: one short of the columns it sits under
// leaves a ragged row that reads as a broken screen.
ok( 'and an empty line spans every column of it',
	(bool) preg_match( '/colspan="8"/', $dze_head ) && (bool) preg_match( '/colspan="8"/', $dze_js ), true );
// WHEN IT WAS DONE, AND WHO ASKED FOR IT. "Dans To review il faudra
// impérativement une date affichée sur chaque action. Pour savoir quand ça a
// été fait. Aussi une colonne pour savoir qui a initié la génération de ce
// contenu." Two columns of the same table split across two files, so both
// halves are read here and the POSITION is what is asserted.
ok( 'the table heads who started it and when, after the job',
	(bool) preg_match( '/\x27Job\x27, \x27dazont-ecom\x27[\s\S]{0,400}?\x27Started by\x27[\s\S]{0,300}?\x27When\x27[\s\S]{0,300}?\x27Status\x27/', $dze_head ), true );
ok( 'and the cells sit in that same order',
	(bool) preg_match( '/esc\(r\.kind\)[\s\S]{0,600}?esc\(r\.from[\s\S]{0,300}?esc\(r\.when[\s\S]{0,300}?COLORS\[r\.status\]/', $dze_js ), true );
// The restock list is the same split the other way round: WordPress prints the
// cells and the browser builds the heading.
$dze_rs    = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-restock.php' );
$dze_rs_js = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/restock.js' );
ok( 'the restock variations head the id right after the variation',
	(bool) preg_match( '/<th>Variation<\/th><th class="dze-objid-th">ID<\/th><th>SKU<\/th>/', $dze_rs_js ), true );
ok( 'and its cell sits in that same place',
	(bool) preg_match( '/esc_html\( \$name \)[\s\S]{0,300}?DZE_Hub::id_td\([\s\S]{0,120}?\$sku/', $dze_rs ), true );

echo "When it was done, and who asked for it\n";
//
// "Dans To review il faudra impérativement une date affichée sur chaque
// action. Pour savoir quand ça a été fait. Aussi une colonne pour savoir qui a
// initié la génération de ce contenu." The decider was recorded and the person
// who ORDERED the work was not, so a row that came back wrong could be traced
// to whoever accepted it and never to whoever asked for it.
delete_option( 'dze_queue_schema' );
DZE_Queue::instance()->maybe_install();
ok( 'the table carries who asked for it',
	false !== strpos( $GLOBALS['queue_schema'] ?? '', 'made_by' ), true );
ok( 'and the schema moved with the column',
	(int) get_option( 'dze_queue_schema', 0 ) >= 3, true );
// It is read from the current user AT THE INSERT, so every path in the plugin
// fills it without being told — and cron, which has no user, answers 0.
$GLOBALS['uid']    = 9;
dze_empty_queue();
DZE_Queue::add( 'cat_desc', [ 12 ] );
ok( 'a job records who pressed it',     (int) ( $GLOBALS['queued'][0]['made_by'] ?? -1 ), 9 );
$GLOBALS['uid']    = 0;
dze_empty_queue();
DZE_Queue::add( 'cat_desc', [ 13 ] );
ok( 'and a scheduled pass records nobody', (int) ( $GLOBALS['queued'][0]['made_by'] ?? -1 ), 0 );
// An ORIGIN always has an answer — unlike a decision, which has nobody when a
// pass saved without review. Naming where a job came from is not inventing a
// person.
$GLOBALS['users'] = [ 7 => 'Marie', 9 => 'Paul' ];
ok( 'the column names the person',      DZE_Queue::started_by( 7 ), 'Marie' );
ok( 'and names the pass when there is none', DZE_Queue::started_by( 0 ), 'Automatic' );
// THE DATE, in the shop's own format, read as local time and never shifted a
// second time.
$GLOBALS['opts']['date_format'] = 'j M Y';
$GLOBALS['opts']['time_format'] = 'H:i';
ok( 'the row says when it last moved',  DZE_Queue::moment( '2026-09-13 16:45:00' ), '13 Sep 2026 16:45' );
ok( 'and an empty date says nothing at all', DZE_Queue::moment( '0000-00-00 00:00:00' ), '' );
ok( 'as does a row with no date yet',   DZE_Queue::moment( '' ), '' );
// AND BOTH TRAVEL: this list is drawn in the browser, so a figure the server
// never sends is a column no screen can print.
$GLOBALS['rows'] = [ [
	'id' => 5, 'kind' => 'cat_desc', 'object_id' => 3, 'status' => 'review',
	'result' => '', 'payload' => '', 'updated' => '2026-09-13 16:45:00',
	'decided_by' => 0, 'made_by' => 7,
] ];
$dze_sent = [];
try { DZE_Queue::instance()->ajax_status(); } catch ( DZE_Json_Sent $e ) { $dze_sent = (array) $e->payload; }
ok( 'the list carries who started each row',
	(string) ( $dze_sent['rows'][0]['from'] ?? '' ), 'Marie' );
ok( 'and when it last moved',
	(string) ( $dze_sent['rows'][0]['when'] ?? '' ), '13 Sep 2026 16:45' );
ok( 'and the row still says who decided',
	array_key_exists( 'who', (array) ( $dze_sent['rows'][0] ?? [] ) ), true );
// The row is READ with both columns in it, or the two are never answered.
ok( 'the reader asks for both columns',
	(bool) preg_match( '/SELECT[^;]*created[^;]*made_by FROM/', implode( ' ', $GLOBALS['wpdb']->sent ) ), true );

$GLOBALS['uid'] = 7;
$GLOBALS['wpdb']->sent = [];
$GLOBALS['wpdb']->updates = [];
$GLOBALS['rows'] = [ [ 'id' => 5, 'kind' => 'cat_desc', 'object_id' => 3, 'status' => 'review', 'result' => '<p>x</p>', 'payload' => '' ] ];
$_POST = [ 'id' => 5, 'accept' => 0 ];
try { DZE_Queue::instance()->ajax_decide(); } catch ( DZE_Json_Sent $e ) { /* it answers by exiting */ }
ok( 'refusing writes who refused',      (int) ( ( $GLOBALS['wpdb']->updates[0]['data']['decided_by'] ?? -1 ) ), 7 );
$GLOBALS['wpdb']->updates = [];
$_POST = [ 'id' => 5, 'accept' => 1 ];
try { DZE_Queue::instance()->ajax_decide(); } catch ( DZE_Json_Sent $e ) { /* the same */ }
ok( 'accepting writes who accepted',    (int) ( ( $GLOBALS['wpdb']->updates[0]['data']['decided_by'] ?? -1 ) ), 7 );
$GLOBALS['wpdb']->updates = [];
$_POST = [ 'do' => 'discard', 'ids' => [ 5 ] ];
try { DZE_Queue::instance()->ajax_bulk(); } catch ( DZE_Json_Sent $e ) { /* the same */ }
ok( 'and so does a bulk refusal',       (int) ( ( $GLOBALS['wpdb']->updates[0]['data']['decided_by'] ?? -1 ) ), 7 );
$GLOBALS['wpdb']->updates = [];
$_POST = [ 'do' => 'accept', 'ids' => [ 5 ] ];
try { DZE_Queue::instance()->ajax_bulk(); } catch ( DZE_Json_Sent $e ) { /* the same */ }
ok( 'and a bulk acceptance',            (int) ( ( $GLOBALS['wpdb']->updates[0]['data']['decided_by'] ?? -1 ) ), 7 );
$_POST = [];
$GLOBALS['uid'] = 0;

echo "A job carries the pages that were picked, and hands them on\n";
// THE SEAM THAT WAS BLIND. The Linking screen asks for the ONE link the mesh
// is short of; the job carried it and the pass was called without it, so the
// article was linked to whatever it would have chosen on its own and every
// screen said the work was done.
$dze_pay = [ 'urls' => [ 'https://shop.test/category/boonie-hats/' ] ];
DZE_Queue::produce( 'post_links', 77, $dze_pay );
ok( 'the article pass is given the picked page',
	DZE_Post_Links::$asked[0]['only'], [ 'https://shop.test/category/boonie-hats/' ] );
$dze_pay2 = [ 'urls' => [ 'https://shop.test/blog/12/' ] ];
$GLOBALS['terms'][88] = '<p>A description.</p>';
DZE_Queue::produce( 'cat_links', 88, $dze_pay2 );
ok( 'and so is the category pass',
	DZE_Category_Content::$asked[0]['only'], [ 'https://shop.test/blog/12/' ] );
// WHAT IS ON SCREEN IS WHAT TRAVELS. The panel writes into an editor and
// saves nothing until Update is pressed, so a pass reading the STORED
// description works on a text nobody is looking at — and on a category never
// saved, on nothing at all: "pour le netlinking je ne comprends pas, je ne
// vois pas le texte actuel", with the before/after reading 0 words · 0 links.
ok( 'the pass is given the stored text when the job carries none',
	DZE_Category_Content::$asked[0]['html'], '<p>A description.</p>' );
DZE_Category_Content::$asked = [];
$dze_pay3 = [ 'urls' => [ 'https://shop.test/blog/12/' ], 'html' => '<p>What the editor holds.</p>' ];
DZE_Queue::produce( 'cat_links', 88, $dze_pay3 );
ok( 'and the text the press was made on when it does',
	DZE_Category_Content::$asked[0]['html'], '<p>What the editor holds.</p>' );
// ABSENT means "as it stands"; an empty editor is not an instruction to link
// an empty string.
DZE_Category_Content::$asked = [];
$dze_pay4 = [ 'html' => '   ' ];
DZE_Queue::produce( 'cat_links', 88, $dze_pay4 );
ok( 'and an empty one falls back to what is stored',
	DZE_Category_Content::$asked[0]['html'], '<p>A description.</p>' );

// A job with nothing picked is the ordinary pass, not a pass with an empty
// list: those are different answers and only one of them writes anything.
$dze_none = [];
DZE_Queue::produce( 'post_links', 78, $dze_none );
ok( 'a job that picked nothing says nothing',
	DZE_Post_Links::$asked[1]['only'], [] );

echo "The pass that mends orphans reads the WHOLE site, not half of it\n";
// A category's inbound links were counted from other CATEGORY DESCRIPTIONS
// and nothing else, so an article sending its readers to an aisle counted for
// zero — and the automatic pass, which works on the least pointed-at category
// first, worked from a reading that could not see half the mesh.
$GLOBALS['terms_all'] = [
	31 => (object) [ 'term_id' => 31, 'name' => 'Tactical bags', 'description' => '<p>' . str_repeat( 'word ', 200 ) . '</p>' ],
	32 => (object) [ 'term_id' => 32, 'name' => 'Boonie hats', 'description' => '<p>' . str_repeat( 'word ', 200 ) . '<a href="http://shop.test/category/tactical-bags/">Tactical bags</a></p>' ],
];
$GLOBALS['mesh_census'] = [];
delete_transient( 'dze_auto_survey' );
$dze_rows = DZE_Automation::survey( true )['rows'];
ok( 'without the graph, only categories count',
	(int) ( $dze_rows[31]['in'] ?? -1 ), 1 );
ok( 'and an aisle nobody links reads as nought',
	(int) ( $dze_rows[32]['in'] ?? -1 ), 0 );
// With the graph, the articles count too — and the ranking changes with it.
$GLOBALS['mesh_census'] = [ 'per' => [
	'product_cat:31' => [ 'in' => 1, 'out' => 0 ],
	'product_cat:32' => [ 'in' => 6, 'out' => 1 ],
] ];
delete_transient( 'dze_auto_survey' );
$dze_rows = DZE_Automation::survey( true )['rows'];
ok( 'the graph answers for the whole site',  (int) ( $dze_rows[32]['in'] ?? -1 ), 6 );
ok( 'and the other keeps its own figure',    (int) ( $dze_rows[31]['in'] ?? -1 ), 1 );
// A GRAPH THAT HAS NOT BEEN READ IS NOT A GRAPH OF ZEROES. Answering "nobody
// points at anything" would send the pass at the wrong page every day.
$GLOBALS['mesh_census'] = [];
delete_transient( 'dze_auto_survey' );
ok( 'no reading yet, and the old count stands',
	(int) ( DZE_Automation::survey( true )['rows'][31]['in'] ?? -1 ), 1 );

echo "The linking task writes the links the GRAPH chose\n";
// A task that re-derives its own targets at run time is a task doing
// something other than what the screen showed. The row travels with the id.
$GLOBALS['mesh_plan'] = [
	[ 'key' => 'product_cat:31', 'kind' => 'product_cat', 'id' => 31, 'name' => 'Tactical bags',
		'urls' => [ 'http://shop.test/blog/12/', 'http://shop.test/category/boonie-hats/' ],
		'why'  => 'will link to 2 pages nothing points at' ],
	[ 'key' => 'post:12', 'kind' => 'post', 'id' => 12, 'name' => 'How to choose a backpack',
		'urls' => [ 'http://shop.test/category/tactical-bags/' ], 'why' => 'will link to one page nothing points at' ],
];
$GLOBALS['terms'][31] = '<p>' . str_repeat( 'word ', 200 ) . '</p>';
$GLOBALS['opts']['dze_auto_settings'] = [ 'tasks' => [ 'mesh_links' => [ 'on' => 1, 'per_day' => 3, 'apply' => 0 ] ] ];
dze_empty_queue();
$dze_pick = DZE_Automation::shortlist( 'mesh_links', 2 );
ok( 'the task takes its work from the graph', count( $dze_pick ), 2 );
ok( 'and each row says which way round it goes',
	(string) ( $dze_pick[0]['why'] ?? '' ), 'will link to 2 pages nothing points at' );
$dze_res = DZE_Automation::run( 'mesh_links', 31, $dze_pick[0] );
ok( 'a category goes to the category pass',  (string) ( $GLOBALS['queued'][0]['kind'] ?? '' ), 'cat_links' );
ok( 'on that category',                      (int) ( $GLOBALS['queued'][0]['id'] ?? 0 ), 31 );
ok( 'carrying the addresses the graph chose',
	(array) ( $GLOBALS['queued'][0]['payload']['urls'] ?? [] ),
	[ 'http://shop.test/blog/12/', 'http://shop.test/category/boonie-hats/' ] );
ok( 'and it says it queued something',       (int) $dze_res['queued'], 1 );
// AND THE REAL SEQUENCE, not the two halves called by hand: a tick picks the
// row and runs it, and what lands in the queue is what the graph chose. The
// test that only calls run() proves run() works and nothing about whether the
// decision survives the trip.
dze_empty_queue();
$GLOBALS['tmeta']  = [];
$GLOBALS['pmeta']  = [];
DZE_Automation::tick( 'mesh_links', true );
ok( 'a tick queues one page',                count( $GLOBALS['queued'] ), 1 );
ok( 'and the addresses survived the trip',
	(array) ( $GLOBALS['queued'][0]['payload']['urls'] ?? [] ),
	[ 'http://shop.test/blog/12/', 'http://shop.test/category/boonie-hats/' ] );

// The SAME task, on an article, goes to the article pass — one task, both
// kinds of page, no second engine.
dze_empty_queue();
$GLOBALS['posts_all'][12] = (object) [ 'ID' => 12, 'post_title' => 'How to choose a backpack', 'post_content' => '<p>' . str_repeat( 'word ', 200 ) . '</p>' ];
DZE_Automation::run( 'mesh_links', 12, $dze_pick[1] );
ok( 'an article goes to the article pass',   (string) ( $GLOBALS['queued'][0]['kind'] ?? '' ), 'post_links' );
// A ROW WITH NOTHING TO ADD QUEUES NOTHING, rather than falling through to
// "whatever this page would have linked to on its own".
dze_empty_queue();
$dze_none = DZE_Automation::run( 'mesh_links', 999, [ 'kind' => 'post', 'urls' => [] ] );
ok( 'nothing chosen, nothing queued',        $GLOBALS['queued'], [] );
ok( 'and it says why',                       (string) $dze_none['reason'], 'none' );


echo "\nThe second phase, and the whole site in one press\n";
//
// "Puis passe au travail traditionnel que je faisais moi-même à la main ?" and
// "j'aurais même bien aimé pouvoir lancer le maillage interne de tout le site
// en une fois, puis automatiser le maillage des nouvelles pages."
$GLOBALS['mesh_plan'] = [];
$GLOBALS['mesh_thin'] = [
	[ 'kind' => 'product_cat', 'id' => 31, 'title' => 'Tactical bags', 'url' => 'http://shop.test/category/tactical-bags/',
		'in' => 5, 'out' => 1, 'words' => 800, 'want' => 9, 'short' => 8 ],
	[ 'kind' => 'post', 'id' => 12, 'title' => 'How to choose a backpack', 'url' => 'http://shop.test/blog/12/',
		'in' => 4, 'out' => 0, 'words' => 300, 'want' => 6, 'short' => 6 ],
];
dze_empty_queue();
$GLOBALS['tmeta']  = [];
$GLOBALS['pmeta']  = [];
$dze_two = DZE_Automation::shortlist( 'mesh_links', 5 );
// THE ORPHANS ARE DONE, so the task moves to the other half rather than saying
// there is nothing to do.
ok( 'with no orphan left, there is still work', count( $dze_two ), 2 );
ok( 'the widest gap comes first',            (int) ( $dze_two[0]['tid'] ?? 0 ), 31 );
ok( 'and the row says how far off it is',
	(string) ( $dze_two[0]['why'] ?? '' ), '8 links short of what its length calls for' );
// NO ADDRESSES TRAVEL WITH IT — on purpose: the page's OWN pool decides, and
// on a shop that pool ranks product categories above articles.
ok( 'nothing is chosen for it in advance',   (array) ( $dze_two[0]['urls'] ?? [ 'x' ] ), [] );

dze_empty_queue();
$dze_res = DZE_Automation::run( 'mesh_links', 31, $dze_two[0] );
ok( 'it goes to the pass that writes that page', (string) ( $GLOBALS['queued'][0]['kind'] ?? '' ), 'cat_links' );
ok( 'and it is queued',                      (int) $dze_res['queued'], 1 );
// A PAYLOAD WITH AN EMPTY LIST OF ADDRESSES IS NOT THE SAME AS NO LIST: the
// pass reads `urls` and an empty one must mean "pick your own", which is what
// absent means everywhere else in this plugin.
ok( 'carrying no addresses at all',
	array_key_exists( 'urls', (array) ( $GLOBALS['queued'][0]['payload'] ?? [] ) ), false );

// THE WHOLE SITE IN ONE PRESS. It is the second phase over everything: the
// arithmetic is the census's, and the model call happens inside each job, one
// at a time, in the queue — never three hundred of them inside one press.
dze_empty_queue();
$GLOBALS['tmeta']  = [];
$GLOBALS['pmeta']  = [];
// The register as it stands before the press, so what it holds afterwards is
// the press and nothing carried in from the checks above.
unset( $GLOBALS['opts']['dze_auto_state'] );
$dze_all = DZE_Automation::catch_up( 'mesh_links' );
ok( 'the press queues every page short of links', (int) $dze_all['queued'], 2 );
ok( 'both kinds of page among them',
	array_unique( wp_list_pluck( $GLOBALS['queued'], 'kind' ) ), [ 'cat_links', 'post_links' ] );
ok( 'and it says there is nothing left',     (bool) $dze_all['more'], false );
ok( 'in words the shop can read',
	false !== strpos( DZE_Automation::catch_up_said( $dze_all ), 'every page that was short' ), true );
// A BULK PRESS DOES ITS BOOKKEEPING ONCE. Looping the single-page path read and
// rewrote the register for EVERY page — the day's figure, the log, the undo
// copies — so the log came back holding nothing but the press that filled it.
ok( 'the day counts one pass, not two',  DZE_Automation::done_today( 'mesh_links' ), 1 );

// PRESSED AGAIN, it does nothing rather than queueing the same pages twice.
// The rows are STILL THERE — which is what "pressed again" means on a shop.
$dze_again = DZE_Automation::catch_up( 'mesh_links' );
ok( 'pressed again it adds nothing',         (int) $dze_again['queued'], 0 );
// AND A PROMISE THE QUEUE NEVER KEPT DOES NOT HOLD THE PAGE. With the rows
// gone — called off, failed, cleared — nothing was written to any of them, so
// they are work again rather than locked out for three days.
dze_empty_queue();
$dze_freed = DZE_Automation::catch_up( 'mesh_links' );
ok( 'the rows gone, the pages are work again', (int) $dze_freed['queued'] > 0, true );
// A CEILING, so one press is not a day of writing nobody asked for, and the
// message says there is more rather than leaving the shop to guess.
$GLOBALS['tmeta'] = [];
$GLOBALS['pmeta'] = [];
dze_empty_queue();
$dze_cap = DZE_Automation::catch_up( 'mesh_links', 1 );
ok( 'the ceiling is kept',                   (int) $dze_cap['queued'], 1 );
ok( 'and it says there is more to do',       (bool) $dze_cap['more'], true );
ok( 'in words the shop can read',
	false !== strpos( DZE_Automation::catch_up_said( $dze_cap ), 'press again' ), true );
// AND NEVER PAST THE MONTHLY BUDGET. A copy of the shop is deliberately NOT on
// that list for this task: pressed by hand a staging site may try it, because
// everything these passes write stays on the site — what leaves it is refused
// where it would leave.
$GLOBALS['tmeta'] = [];
$GLOBALS['pmeta'] = [];
dze_empty_queue();
$GLOBALS['over_budget'] = true;
$dze_broke = DZE_Automation::catch_up( 'mesh_links' );
ok( 'over the budget it queues nothing',     [ (int) $dze_broke['queued'], (string) $dze_broke['reason'] ], [ 0, 'budget' ] );
$GLOBALS['over_budget'] = false;

// A PASS THAT WAS NEVER QUEUED IS NOT A PASS. A queue that refuses must leave
// the pages unmarked, or they are locked out for a month having had nothing
// done to them — the fault this task was already red on once.
$GLOBALS['tmeta'] = [];
$GLOBALS['pmeta'] = [];
dze_empty_queue();
$GLOBALS['queue_busy'] = true;
$dze_full = DZE_Automation::catch_up( 'mesh_links' );
ok( 'a queue that refuses queues nothing', (int) $dze_full['queued'], 0 );
ok( 'and nothing is stamped',              [ $GLOBALS['tmeta'], $GLOBALS['pmeta'] ], [ [], [] ] );
$GLOBALS['queue_busy'] = false;

echo "\nThe before of every before / after\n";
//
// "Encore une anomalie : pour les articles de blog, le avant/après est faux."
// The popup read `get_term( $id, 'product_cat' )` whatever the job was — so on
// an ARTICLE it fetched a term that does not exist, the before came back empty,
// and the screen printed "0 words → 1224 words · 0 links → 4 links" over a post
// holding twelve hundred words. Nothing errored, and the figure that was wrong
// is the one the whole screen exists for. Nothing gated `ajax_review` at all,
// which is why it shipped.
$GLOBALS['terms'][21]     = '<p>' . str_repeat( 'a category word ', 60 ) . '<a href="http://shop.test/a/">one</a></p>';
$GLOBALS['posts_all'][31] = (object) [
	'ID'           => 31,
	'post_title'   => 'The sniper role: why are they so feared?',
	'post_content' => '<p>' . str_repeat( 'an article word ', 400 ) . '<a href="http://shop.test/b/">two</a></p>',
];

// A CATEGORY: read from the term, as it always was.
ok( 'a category is read from its term',
	str_word_count( wp_strip_all_tags( DZE_Queue::holds_now( 'cat_links', 21 ) ) ), 181 );
// AN ARTICLE: read from the post — the half that was missing.
ok( 'an article is read from its post',
	str_word_count( wp_strip_all_tags( DZE_Queue::holds_now( 'post_links', 31 ) ) ), 1201 );
ok( 'and a product too',
	str_word_count( wp_strip_all_tags( DZE_Queue::holds_now( 'product_text', 31 ) ) ), 1201 );
// AN OBJECT THAT IS NOT THERE IS AN EMPTY BEFORE, never a warning and never a
// fatal: a job whose object was deleted since still opens.
ok( 'a missing object answers empty',    DZE_Queue::holds_now( 'post_links', 999999 ), '' );

// AND THE POPUP ITSELF, through the endpoint the screen actually posts to.
$GLOBALS['rows'] = [ [
	'id'        => 77,
	'kind'      => 'post_links',
	'object_id' => 31,
	'status'    => 'review',
	'result'    => '<p>' . str_repeat( 'an article word ', 400 )
		. '<a href="http://shop.test/b/">two</a> <a href="http://shop.test/c/">three</a></p>',
	'payload'   => '[]',
] ];
$_POST = [ 'id' => 77 ];
$dze_pop = [];
try { DZE_Queue::instance()->ajax_review(); } catch ( DZE_Json_Sent $e ) { $dze_pop = (array) $e->payload; }
ok( 'the popup carries the text it holds',
	false !== strpos( (string) ( $dze_pop['current'] ?? '' ), 'an article word' ), true );
// THE FIGURES ARE THE WHOLE POINT OF THE BLOCK, and a nought on the left reads
// as an article with nothing in it.
ok( 'the words before are not nought',   (int) ( $dze_pop['words'][0] ?? 0 ), 1201 );
ok( 'and the links before are counted',  (int) ( $dze_pop['links'][0] ?? 0 ), 1 );
ok( 'with the after beside them',        (int) ( $dze_pop['links'][1] ?? 0 ), 2 );
ok( 'and the article named, not a #id',
	(string) ( $dze_pop['title'] ?? '' ), 'The sniper role: why are they so feared?' );
$_POST = [];

// EVERY KIND THAT IS A DIFF ANSWERS SOMETHING. A kind added next year and
// forgotten here would silently print an empty before on its own screen.
$dze_blank = [];
foreach ( DZE_Queue::kinds() as $dze_k => $dze_meta ) {
	if ( ! empty( $dze_meta['image'] ) ) {
		continue; // a photograph is not a diff.
	}
	$dze_id = 0 === strpos( $dze_k, 'cat_' ) ? 21 : 31;
	if ( '' === DZE_Queue::holds_now( $dze_k, $dze_id ) ) {
		$dze_blank[] = $dze_k;
	}
}
ok( 'no kind is left without a before',  $dze_blank, [] );

echo "\nThe writer's lock, and the run nobody can restart\n";
//
// "c'est bloqué." Two hundred pages queued, the bar at 0%, nothing written and
// nothing said. work() takes a five-minute lock before it picks a job and lets
// it go at the end — but a run the host kills mid-call (a model answering
// slowly, a worker out of memory) never reaches the end, and recover() let the
// lock go ONLY where it had found a stale `running` row. The kinds this screen
// queues never pass through `running`: cat_links and post_links are written in
// one step, queued → review. So an abandoned lock barred every step of every
// job, and the one function able to clear it could not see it.
$GLOBALS['tr']         = [];
$GLOBALS['rows']       = [];
$GLOBALS['stale_rows'] = [];

// FREE, AND IT SAYS SO IN SECONDS RATHER THAN IN YES-OR-NO: a screen cannot
// tell a writer busy for two seconds from one abandoned ten minutes ago.
ok( 'an idle writer is held by nobody',  DZE_Queue::held_for(), 0 );

// A LOCK LEFT BEHIND BY A RUN THAT DIED, with not one row in `running`.
set_transient( 'dze_queue_lock', time() - 9 * 60, 0 );
ok( 'a lock that is standing is timed',  DZE_Queue::held_for() >= 9 * 60, true );
DZE_Queue::recover();
ok( 'and recover lets an old one go',    DZE_Queue::held_for(), 0 );

// AND IT DOES NOT LET GO OF ONE THAT IS WORKING. A step that has been running
// for four seconds is a step, and pulling its lock is two workers writing the
// same page.
set_transient( 'dze_queue_lock', time() - 4, 0 );
DZE_Queue::recover();
ok( 'a live lock is left alone',         DZE_Queue::held_for() > 0, true );
DZE_Queue::unlock();
ok( 'and it can be let go by hand',      DZE_Queue::held_for(), 0 );

// A LOCK WRITTEN BY AN EARLIER VERSION held the figure 1, which read as 1970
// and would make every writer look abandoned the moment this version landed.
set_transient( 'dze_queue_lock', 1, 0 );
ok( 'an old-shaped lock is not ancient', DZE_Queue::held_for() < 60, true );
DZE_Queue::unlock();

echo "\nA queue that is not moving can be asked how long for\n";
//
// The screen could not say "nothing has moved": it read queued, running and
// review, and a figure that does not change is the same markup as a figure
// that is about to. The database already knows — every row carries when it
// last moved.
$GLOBALS['tr'] = [];
// The shop's own clock, which is what the column is written with — read from
// the wall clock the reading would come out negative and every silence would
// look like none.
$GLOBALS['q_last_moved'] = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - 8 * 60 );
ok( 'the queue times its own silence',   DZE_Queue::idle_for( [ 'cat_links', 'post_links' ] ) >= 7 * 60, true );
// IT IS ASKED BY KIND, like every other figure on that screen: a photograph
// made from the bulk screen is in the queue and is not this page's work.
ok( 'and only about the kinds asked for',
	false !== strpos( (string) end( $GLOBALS['wpdb']->sent ), "'cat_links','post_links'" ), true );
$GLOBALS['q_last_moved'] = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - 3 );
ok( 'a queue that just moved is not idle', DZE_Queue::idle_for( [ 'cat_links' ] ) < 60, true );
// NOTHING THERE IS NOT A SILENCE. An empty queue answering "idle for ever"
// would put a stuck warning on every shop that has finished its work.
$GLOBALS['q_last_moved'] = null;
ok( 'an empty queue is not stuck',       DZE_Queue::idle_for( [ 'cat_links' ] ), 0 );

echo "\nStarting a stopped run again\n";
//
// A control that cannot act is a control nobody trusts, and the shop had none:
// a failed row could only be put back one at a time from another screen.
$GLOBALS['tr'] = [];
set_transient( 'dze_queue_lock', time() - 9 * 60, 0 );
$GLOBALS['wpdb']->updates = [];
$GLOBALS['wpdb']->sent    = [];
$dze_back = DZE_Queue::retry_failed( [ 'cat_links', 'post_links' ] );
ok( 'the failed rows are put back',      $dze_back, 3 );
// ONE WRITE FOR THE WHOLE PRESS, never a read-modify-write per row: two
// hundred of those inside one request is how a log comes back holding the last
// twelve lines of the press that filled it.
$dze_sql = implode( ' | ', $GLOBALS['wpdb']->sent );
ok( 'in one statement',                  substr_count( $dze_sql, "SET status = 'queued'" ), 1 );
ok( 'only the failed ones',              false !== strpos( $dze_sql, "status = 'failed'" ), true );
ok( 'and only the kinds asked for',      false !== strpos( $dze_sql, "'cat_links','post_links'" ), true );
// AND THE WRITER IS FREED BY THE SAME PRESS. Putting the rows back while the
// lock still stands is a press that answers "3 put back" and changes nothing.
ok( 'the writer is let go with them',    DZE_Queue::held_for(), 0 );

echo "\nStopping a run that is under way\n";
//
// "Start it again > Il faut une option aussi pour annuler." Two hundred pages
// queued and the only control on the block put them BACK: a run started by
// mistake, or one whose pages keep coming back wrong, could be restarted for
// ever and never called off.
$GLOBALS['tr'] = [];
$GLOBALS['wpdb']->sent = [];
$GLOBALS['waiting_rows'] = [
	[ 'kind' => 'cat_links',  'object_id' => 21 ],
	[ 'kind' => 'cat_links',  'object_id' => 22 ],
	[ 'kind' => 'post_links', 'object_id' => 31 ],
];
$dze_gone = DZE_Queue::drop_waiting( [ 'cat_links', 'post_links' ] );
ok( 'what has not been written is dropped', $dze_gone, 3 );
$dze_sql = implode( ' | ', $GLOBALS['wpdb']->sent );
// ONE STATEMENT FOR THE WHOLE PRESS, like every other bulk write here.
ok( 'in one statement',                  substr_count( $dze_sql, 'DELETE FROM' ), 1 );
ok( 'and only the kinds asked for',      false !== strpos( $dze_sql, "'cat_links','post_links'" ), true );
// NOTHING WRITTEN IS EVER THROWN AWAY. A row waiting for a yes or no holds a
// finished text, and a row already accepted is the only record that the shop
// was worked on: dropping either would be this press destroying the work it
// was pressed to stop making.
ok( 'what is waiting for a decision stays',
	false === strpos( $dze_sql, 'review' ) || false !== strpos( $dze_sql, "IN ('queued','failed')" ), true );
ok( 'and it names the two it takes',     false !== strpos( $dze_sql, "IN ('queued','failed')" ), true );
ok( 'applied rows are never named',      false !== strpos( $dze_sql, 'applied' ), false );
// A PRESS THAT STOPS A RUN LETS THE WRITER GO TOO: a lock left standing would
// bar the next press for the whole of its own five minutes.
ok( 'the writer is let go with them',    DZE_Queue::held_for(), 0 );
// AND IT SAYS WHICH PAGES IT DROPPED. A page queued by the automatic pass is
// stamped as worked on so the daily pass does not do it twice; dropped, that
// stamp is a page locked out of the pass meant to mend it, having had nothing
// written to it. The register cannot let go of what it is never told about.
$dze_freed = DZE_Queue::dropped_rows();
ok( 'the rows it dropped are handed back', count( $dze_freed ), 3 );
ok( 'each naming its kind',              (string) ( $dze_freed[0]['kind'] ?? '' ), 'cat_links' );
ok( 'and the object it was about',       (int) ( $dze_freed[0]['object_id'] ?? 0 ) > 0, true );
// THEY ARE READ BEFORE THE DELETE, or there is nothing left to read.
ok( 'read before they are deleted',
	strpos( $dze_sql, 'SELECT' ) < strpos( $dze_sql, 'DELETE' ), true );
// AND IT IS ASKED FOR NOTHING WHEN THERE IS NOTHING TO ASK.
ok( 'no kinds, no statement',            DZE_Queue::drop_waiting( [] ), 0 );
ok( 'and nothing is handed back',        DZE_Queue::dropped_rows(), [] );

echo "\nTHE WRITE IS THE LAST THING THAT CAN REFUSE, AND IT DOES\n";
// Three articles lost content on this shop in one day, and the guard that
// should have stopped two of them had already passed the text: the damage was
// done AFTER production, on the way back from the review popup's own visual
// editor. A guard that lives only where the text is made protects the
// automatic pass and nothing else — so the reading is asked again here, at the
// one place every path writes through.
$dze_art = "<!-- wp:paragraph -->\n<p>A sniper waits.</p>\n<!-- /wp:paragraph -->\n\n"
	. "<!-- wp:heading -->\n<h2>How good are snipers?</h2>\n<!-- /wp:heading -->\n\n"
	. "<!-- wp:paragraph -->\n<p>Very good indeed, for a long time.</p>\n<!-- /wp:paragraph -->";
$dze_post = new stdClass();
$dze_post->ID           = 987632358;
$dze_post->post_content = $dze_art;
$GLOBALS['posts_all'][ 987632358 ] = $dze_post;

// AN HONEST LINKING PASS STILL LANDS.
$GLOBALS['wrote'] = [];
$dze_linked = str_replace( 'A sniper waits.',
	'A <a href="https://kula.test/how-sniper-works">sniper</a> waits.', $dze_art );
ok( 'a linked article is written',
	DZE_Queue::apply( 'post_links', 987632358, $dze_linked ), true );
ok( 'and it is the linked text that lands',
	false !== strpos( (string) ( $GLOBALS['wrote'][0]['post_content'] ?? '' ), 'how-sniper-works' ), true );
ok( 'nothing was refused',               DZE_Queue::refusal(), '' );

// THE EDITOR'S OWN DAMAGE, which is what actually reached the shop: every
// delimiter wrapped in a paragraph. Same words, same links, more paragraphs —
// and every block in the editor invalid.
$GLOBALS['wrote'] = [];
$dze_autop = preg_replace( '#(<!--\s*/?wp:[^>]*-->)#', '<p>$1</p>', $dze_linked );
ok( 'a wrapped document is refused',
	DZE_Queue::apply( 'post_links', 987632358, $dze_autop ), false );
// AND NOTHING AT ALL IS WRITTEN. A refusal that has already saved is not one.
ok( 'and not one word reaches the post',  $GLOBALS['wrote'], [] );
// AND THE ROW SAYS WHY, in words. "Saving failed." on a row is the sentence
// that sent this shop looking in the wrong place for a day.
ok( 'the refusal says what it saw',
	false !== stripos( DZE_Queue::refusal(), 'block' ), true );

// THE TRUNCATION, which cost the first article its last quarter.
$GLOBALS['wrote'] = [];
ok( 'a document cut short is refused',
	DZE_Queue::apply( 'post_links', 987632358, substr( $dze_linked, 0, 120 ) ), false );
ok( 'and nothing reaches the post either', $GLOBALS['wrote'], [] );

// A CATEGORY DESCRIPTION IS PLAIN HTML AND IS NOT HELD TO ANY OF IT.
$GLOBALS['wrote'] = [];
ok( 'a plain description still saves',
	DZE_Queue::apply( 'cat_links', 44, '<p>Tidy <a href="https://kula.test/x">rugs</a>.</p>' ), true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
