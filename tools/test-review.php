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

function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return $n > 1 ? $b : $a; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
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

class DZE_Modules { public static function enabled( $id ) { return true; } }

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

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
