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

define( 'ABSPATH', '/wp/' );
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
function get_term( $id, $tax = '' ) { return null; }
function get_post( $id = 0 ) { return null; }
function wp_kses_post( $s ) { return (string) $s; }
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
	public function get_row( $q, $m = null ) { $this->sent[] = (string) $q; return []; }
	public function update( ...$a ) { return 1; }
	public function insert( ...$a ) { return 1; }
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
foreach ( [ 'cat_links', 'post_links', 'cat_desc', 'events' ] as $id ) {
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
$GLOBALS['menu_added'] = [];
$GLOBALS['bulk_pending'] = 0;
DZE_Queue::instance()->menu();
$dze_menu = $GLOBALS['menu_added'][ DZE_Queue::MENU_SLUG ] ?? [];
ok( 'the screen is named for what it holds', (string) ( $dze_menu['title'] ?? '' ), 'Content to review' );
ok( 'and it hangs off the products menu',    (string) ( $dze_menu['parent'] ?? '' ), 'edit.php?post_type=product' );
// THE COUNT ON THE MENU COUNTS BOTH STORES. A menu saying one while the
// screen says four is the disagreement this merge exists to end.
$GLOBALS['menu_added'] = [];
$GLOBALS['bulk_pending'] = 3;
DZE_Queue::instance()->menu();
ok( 'products waiting are counted on the menu',
	false !== strpos( (string) ( $GLOBALS['menu_added'][ DZE_Queue::MENU_SLUG ]['menu'] ?? '' ), '>3<' ), true );
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
ok( 'and names the products waiting elsewhere',
	false !== strpos( $dze_page, '2 products are holding content nobody has decided on' ), true );
// A CONTROL IS TESTED ON ITS DESTINATION. It goes to the screen that owns
// that decision — never to a settings page, and never nowhere.
ok( 'with a way straight to them',
	false !== strpos( $dze_page, DZE_Content::bulk_url() ), true );
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

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
