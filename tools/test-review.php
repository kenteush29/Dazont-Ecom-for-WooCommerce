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
function current_time( $t = 'mysql' ) { return '2026-09-03 12:00:00'; }
function admin_url( $p = '' ) { return 'http://shop.test/wp-admin/' . $p; }
function add_query_arg( ...$a ) { return 'http://shop.test/queue'; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function wp_next_scheduled( $h ) { return 0; }
function wp_schedule_event() {} function wp_clear_scheduled_hook( $h ) {}
function delete_metadata( ...$a ) { return true; }
function get_term( $id, $tax = '' ) {
	return isset( $GLOBALS['terms'][ (int) $id ] )
		? (object) [ 'term_id' => (int) $id, 'description' => (string) $GLOBALS['terms'][ (int) $id ] ]
		: null;
}
function update_term_meta( ...$a ) { return true; }
function get_term_meta( $id, $k = '', $single = false ) { return $GLOBALS['tmeta'][ (int) $id ][ $k ] ?? ''; }
function get_post_meta( $id, $k = '', $single = false ) { return $GLOBALS['pmeta'][ (int) $id ][ $k ] ?? ''; }
function update_post_meta( ...$a ) { return true; }
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
function wp_kses_post( $s ) { return (string) $s; }
// Accepting a category description WRITES it: the harness records the write
// rather than pretending it did not happen.
function wp_update_term( $id, $tax, $args = [] ) { $GLOBALS['wrote'][] = [ (int) $id, $args ]; return [ 'term_id' => (int) $id ]; }
function wp_update_post( $args = [], $err = false ) { $GLOBALS['wrote'][] = $args; return (int) ( $args['ID'] ?? 0 ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { public function get_error_message() { return 'error'; } }
function current_user_can( $c ) { return true; }
function check_ajax_referer( $a, $b = '', $die = true ) { return true; }
class DZE_Json_Sent extends Exception { public $payload; public $ok;
	public function __construct( $p, $ok ) { parent::__construct( 'sent' ); $this->payload = $p; $this->ok = $ok; } }
function wp_send_json_success( $d = null ) { throw new DZE_Json_Sent( $d, true ); }
function wp_send_json_error( $d = null, $c = 0 ) { throw new DZE_Json_Sent( $d, false ); }

$GLOBALS['opts'] = [];
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function delete_transient( $k ) { return true; }

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
class DZE_Mesh {
	public static function census(): array { return $GLOBALS['mesh_census'] ?? []; }
	public static function plan( int $limit = 5 ): array {
		return array_slice( $GLOBALS['mesh_plan'] ?? [], 0, $limit );
	}
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
function wp_localize_script( ...$a ) {} function wp_create_nonce( $a = '' ) { return 'n'; }
function wp_enqueue_editor() {}

/** Every statement the queue sends, kept so it can be read back. */
class DZE_Review_Wpdb {
	public $prefix = 'wp_';
	public $sent = [];
	public function prepare( $q, ...$a ) { return vsprintf( str_replace( [ '%s', '%d' ], [ "'%s'", '%d' ], $q ), $a ); }
	public function query( $q ) { $this->sent[] = (string) $q; return 3; }
	public function get_var( $q ) { $this->sent[] = (string) $q; return 0; }
	public function get_results( $q, $m = null ) { $this->sent[] = (string) $q; return $GLOBALS['rows'] ?? []; }
	public function get_col( $q ) { $this->sent[] = (string) $q; return []; }
	public function get_row( $q, $m = null ) { $this->sent[] = (string) $q; return $GLOBALS['rows'][0] ?? []; }
	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
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
		];
		return 1;
	}
}
$GLOBALS['wpdb'] = new DZE_Review_Wpdb();
$GLOBALS['rows'] = [];

require __DIR__ . '/../' . $dir . '/includes/class-automation.php';
require __DIR__ . '/../' . $dir . '/includes/class-queue.php';
require_once __DIR__ . '/../' . $dir . '/includes/class-cleanup.php';

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
$GLOBALS['rows'] = [
	[ 'id' => 91, 'kind' => 'product_shot', 'object_id' => 501, 'updated' => '2026-09-03 10:00:00' ],
	[ 'id' => 90, 'kind' => 'product_shot', 'object_id' => 501, 'updated' => '2026-08-01 09:00:00' ],
	[ 'id' => 89, 'kind' => 'cat_desc',     'object_id' => 77,  'updated' => '2026-07-01 09:00:00' ],
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
		'why'  => '2 pages short of links point here' ],
	[ 'key' => 'post:12', 'kind' => 'post', 'id' => 12, 'name' => 'How to choose a backpack',
		'urls' => [ 'http://shop.test/category/tactical-bags/' ], 'why' => 'one page short of links points here' ],
];
$GLOBALS['terms'][31] = '<p>' . str_repeat( 'word ', 200 ) . '</p>';
$GLOBALS['opts']['dze_auto_settings'] = [ 'tasks' => [ 'mesh_links' => [ 'on' => 1, 'per_day' => 3, 'apply' => 0 ] ] ];
$GLOBALS['queued'] = [];
$dze_pick = DZE_Automation::shortlist( 'mesh_links', 2 );
ok( 'the task takes its work from the graph', count( $dze_pick ), 2 );
ok( 'and each row says what it would gain',
	(string) ( $dze_pick[0]['why'] ?? '' ), '2 pages short of links point here' );
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
$GLOBALS['queued'] = [];
$GLOBALS['tmeta']  = [];
$GLOBALS['pmeta']  = [];
DZE_Automation::tick( 'mesh_links', true );
ok( 'a tick queues one page',                count( $GLOBALS['queued'] ), 1 );
ok( 'and the addresses survived the trip',
	(array) ( $GLOBALS['queued'][0]['payload']['urls'] ?? [] ),
	[ 'http://shop.test/blog/12/', 'http://shop.test/category/boonie-hats/' ] );

// The SAME task, on an article, goes to the article pass — one task, both
// kinds of page, no second engine.
$GLOBALS['queued'] = [];
$GLOBALS['posts_all'][12] = (object) [ 'ID' => 12, 'post_title' => 'How to choose a backpack', 'post_content' => '<p>' . str_repeat( 'word ', 200 ) . '</p>' ];
DZE_Automation::run( 'mesh_links', 12, $dze_pick[1] );
ok( 'an article goes to the article pass',   (string) ( $GLOBALS['queued'][0]['kind'] ?? '' ), 'post_links' );
// A ROW WITH NOTHING TO ADD QUEUES NOTHING, rather than falling through to
// "whatever this page would have linked to on its own".
$GLOBALS['queued'] = [];
$dze_none = DZE_Automation::run( 'mesh_links', 999, [ 'kind' => 'post', 'urls' => [] ] );
ok( 'nothing chosen, nothing queued',        $GLOBALS['queued'], [] );
ok( 'and it says why',                       (string) $dze_none['reason'], 'none' );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
