<?php
/**
 * What this plugin actually SENDS to Klaviyo.
 *
 * Run before every release:  php tools/test-klaviyo.php dazont-ecom
 *
 * The provider's own answers cannot be tested from here — a real call spends
 * money and writes to a live account — but everything up to that line can be,
 * and that is where the bugs have been. "Klaviyo refused (HTTP 404) — No valid
 * revisions found for method" was one header on six calls: three of them asked
 * for the beta revision the localisation endpoints need and three, written
 * later, did not. Nothing about that is visible in a file that parses.
 *
 * So the transport is stubbed and the REQUEST is read: its method, its URL,
 * its headers, its body.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'DZE_URL', 'http://example.test/' );
define( 'DZE_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DZE_KLAVIYO_API_KEY', 'pk_test_not_a_real_key' );

$GLOBALS['dze_sent'] = [];
$GLOBALS['dze_reply'] = [ 'code' => 200, 'body' => '{"data":{"id":"x"}}' ];
$GLOBALS['dze_opts'] = [];

function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return $n > 1 ? $b : $a; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function esc_js( $s ) { return addslashes( (string) $s ); }
function esc_textarea( $s ) { return esc_html( $s ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function get_option( $k, $d = false ) { return $GLOBALS['dze_opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['dze_opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function delete_transient( $k ) { return true; }
function add_action() {} function add_filter() {} function remove_filter() {} function register_setting() {}
function apply_filters( $t, $v = null, ...$r ) { return $v; }
function do_action( $t, ...$a ) {}
function current_user_can( $c ) { return true; }
function is_admin() { return true; }
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . $p; }
function add_query_arg( ...$a ) { return ''; }
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function wp_next_scheduled( $h ) { return 0; }
function wp_schedule_event() {} function wp_unschedule_event() {}
function wp_remote_request( $url, $args = [] ) {
	$GLOBALS['dze_sent'][] = [ 'url' => $url ] + $args;
	// A reply per call when the test lists several, otherwise the same one.
	$queue = $GLOBALS['dze_queue'] ?? [];
	$r     = $queue ? array_shift( $GLOBALS['dze_queue'] ) : $GLOBALS['dze_reply'];
	return [ 'response' => [ 'code' => $r['code'] ], 'body' => $r['body'] ];
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
class WP_Error {
	private $msg;
	public function __construct( $c = '', $m = '' ) { $this->msg = $m; }
	public function get_error_message() { return $this->msg; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class DZE_Health { public static function log( ...$a ) {} }

require __DIR__ . '/../' . $dir . '/includes/class-klaviyo.php';

$fails = 0;
$ran   = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok    %s\n", $what ); return; }
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
function last_sent(): array { return end( $GLOBALS['dze_sent'] ) ?: []; }
function header_of( string $name ) { return last_sent()['headers'][ $name ] ?? null; }

// The revisions, read off the wire. Reflection, because the constants are the
// thing under test and hard-coding them here would test nothing.
$r  = new ReflectionClass( 'DZE_Klaviyo' );
$stable = $r->getConstant( 'REV' );
$beta   = $r->getConstant( 'REV_B' );

echo "The API revision each endpoint is asked on\n";
ok( 'beta and stable are not the same', $stable !== $beta, true );
ok( 'the beta revision is a .pre one',  substr( (string) $beta, -4 ), '.pre' );

DZE_Klaviyo::request( 'GET', 'lists/' );
ok( 'an ordinary endpoint: stable',     header_of( 'revision' ), $stable );

DZE_Klaviyo::request( 'GET', 'segments/?fields[segment]=name' );
ok( 'segments too',                     header_of( 'revision' ), $stable );

// Every shape the translations calls are written in, including the ones that
// forgot to ask. This is the bug: a 404 that reads like a missing campaign.
foreach ( [
	[ 'GET',   'translations/campaign-variation::email::abc/?additional-fields%5Btranslation%5D=values' ],
	[ 'PATCH', 'translations/campaign-variation::email::abc/' ],
	[ 'POST',  'translations/' ],
	[ 'GET',   '/translations/abc' ],
] as [ $method, $path ] ) {
	DZE_Klaviyo::request( $method, $path, 'GET' === $method ? null : [ 'data' => [] ] );
	ok( sprintf( '%s %s: beta', $method, mb_substr( $path, 0, 26 ) ), header_of( 'revision' ), $beta );
}

// An explicit beta call still gets the beta revision, whatever the path.
DZE_Klaviyo::request( 'GET', 'campaigns/', null, 25, true );
ok( 'asked for beta, given beta',       header_of( 'revision' ), $beta );

echo "What else travels on every call\n";
ok( 'the key is sent as Klaviyo asks',  header_of( 'Authorization' ), 'Klaviyo-API-Key pk_test_not_a_real_key' );
ok( 'and only to Klaviyo',              0 === strpos( (string) ( last_sent()['url'] ?? '' ), 'https://a.klaviyo.com/api/' ), true );
ok( 'the JSON:API content type',        header_of( 'content-type' ), 'application/vnd.api+json' );

echo "What Klaviyo's refusal comes back as\n";
$GLOBALS['dze_reply'] = [ 'code' => 404, 'body' => '{"errors":[{"detail":"No valid revisions found for method"}]}' ];
$err = DZE_Klaviyo::request( 'PATCH', 'translations/abc/', [ 'data' => [] ] );
ok( 'a refusal is an error, not data',  $err instanceof WP_Error, true );
ok( "and it carries Klaviyo's own words", false !== strpos( $err->get_error_message(), 'No valid revisions found' ), true );
$GLOBALS['dze_reply'] = [ 'code' => 200, 'body' => '{"data":{"id":"x"}}' ];

echo "When Klaviyo moves the endpoint from beta to stable\n";
// The exact refusal the shop saw, answered on the beta revision this time:
// the call must be asked again on the stable one rather than given up on.
$no_rev = '{"errors":[{"detail":"No valid revisions found for method, please check our documentation"}]}';
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 404, 'body' => $no_rev ],
	[ 'code' => 200, 'body' => '{"data":{"id":"campaign-variation::email::abc"}}' ],
];
$got = DZE_Klaviyo::request( 'PATCH', 'translations/abc/', [ 'data' => [] ] );
ok( 'asked twice, not once',            count( $GLOBALS['dze_sent'] ), 2 );
ok( 'the beta revision first',          $GLOBALS['dze_sent'][0]['headers']['revision'] ?? '', $beta );
ok( 'the stable one after',             $GLOBALS['dze_sent'][1]['headers']['revision'] ?? '', $stable );
ok( 'and the answer comes back',        $got['data']['id'] ?? '', 'campaign-variation::email::abc' );
ok( 'the body travelled both times',    ( $GLOBALS['dze_sent'][1]['body'] ?? '' ) === ( $GLOBALS['dze_sent'][0]['body'] ?? '' ), true );

// Any OTHER refusal is about the request, not the revision: sending it again
// would only fail again, and would write to the account twice on a POST.
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 400, 'body' => '{"errors":[{"detail":"Invalid input"}]}' ],
	[ 'code' => 200, 'body' => '{"data":{"id":"never"}}' ],
];
$err = DZE_Klaviyo::request( 'POST', 'translations/', [ 'data' => [] ] );
ok( 'a bad request is sent once',       count( $GLOBALS['dze_sent'] ), 1 );
ok( 'and comes back as the error',      $err instanceof WP_Error, true );

// An ordinary endpoint has one revision and is never asked twice.
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [ [ 'code' => 404, 'body' => $no_rev ], [ 'code' => 200, 'body' => '{}' ] ];
DZE_Klaviyo::request( 'GET', 'lists/' );
ok( 'lists/ is asked once and no more', count( $GLOBALS['dze_sent'] ), 1 );
$GLOBALS['dze_queue'] = [];

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
