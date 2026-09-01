<?php
/**
 * A Google connection that has been revoked.
 *
 * Run before every release:  php tools/test-gmc-token.php dazont-ecom
 *
 * The shop pressed Sync now and read this, five times over, once per feed:
 *   "EN · 711906774: Google token refresh failed: Token has been expired or
 *    revoked. | FR · … | DE · … | PL · … | ES · …"
 * Google's own words, repeated per market, and not one of them saying what to
 * do — while the screen that DOES the reconnecting went on showing a green
 * "Connected". Google says invalid_grant when the authorisation is gone: no
 * retry fixes it, so it is written down once, said in the shop's own words,
 * and shown where the reconnecting happens.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return $n > 1 ? $b : $a; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function esc_attr__( $s, $d = '' ) { return $s; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return (string) $s; }
function absint( $n ) { return abs( (int) $n ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_unslash( $v ) { return $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); }
function add_action() {} function add_filter() {} function do_action() {} function apply_filters( $t, $v = null, ...$r ) { return $v; }
function is_admin() { return false; } // the constructor's admin hooks are not the subject
function admin_url( $p = '' ) { return 'http://shop.test/wp-admin/' . $p; }
function home_url( $p = '/' ) { return 'https://kula.test' . $p; }
function wp_nonce_url( $u, $a = '' ) { return $u; }
function wp_create_nonce( $a = '' ) { return 'n'; }
function human_time_diff( $from, $to = 0 ) { return max( 1, (int) round( ( ( $to ?: time() ) - $from ) / 60 ) ) . ' mins'; }
function wp_next_scheduled( ...$a ) { return 0; }
function wp_schedule_event( ...$a ) {} function wp_schedule_single_event( ...$a ) { return true; }
function wp_clear_scheduled_hook( ...$a ) {} function wp_unschedule_hook( ...$a ) {}
function wp_cache_delete( ...$a ) {}
function current_time( $t = 'timestamp' ) { return time(); }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); }

$GLOBALS['opts'] = [];
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
$GLOBALS['trans'] = [];
function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['trans'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['trans'][ $k ] ); return true; }

/** Google, answering the way Google answers a dead refresh token. */
$GLOBALS['asked'] = [];
$GLOBALS['reply'] = [];
function wp_remote_post( $url, $args = [] ) {
	$GLOBALS['asked'][] = [ 'url' => $url, 'body' => $args['body'] ?? [] ];
	return $GLOBALS['reply'];
}
function wp_remote_get( $url, $args = [] ) { return $GLOBALS['reply']; }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 200 ); }
function is_wp_error( $t ) { return false; }
class DZE_Discounts { const MENU_SLUG_EVENTS = 'dazont-ecom-events'; public static function get_rules() { return []; } }
class DZE_Health { public static function log( ...$a ) {} }
class DZE_Modules { public static function enabled( $id ) { return true; } }

require __DIR__ . '/../' . $dir . '/includes/class-gmc.php';

$fails = 0;
$ran   = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok    %s\n", $what ); return; }
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
/** The private token call, which is the whole subject. */
function token(): array {
	$m = new ReflectionMethod( 'DZE_Gmc', 'get_access_token' );
	$m->setAccessible( true );
	try { return [ (string) $m->invoke( DZE_Gmc::instance() ), '' ]; }
	catch ( Throwable $e ) { return [ '', $e->getMessage() ]; }
}
function connected( array $extra = [] ): void {
	$GLOBALS['opts'][ DZE_Gmc::OPT_CONNECTION ] = [ 'refresh_token' => 'r-token', 'email' => 'shop@kula.test' ] + $extra;
	$GLOBALS['opts']['dze_gmc_oauth'] = [ 'client_id' => 'cid', 'client_secret' => 'secret' ];
	$GLOBALS['trans'] = [];
	$GLOBALS['asked'] = [];
}

echo "A connection Google still honours\n";
connected();
$GLOBALS['reply'] = [ 'body' => json_encode( [ 'access_token' => 'A1', 'expires_in' => 3600 ] ) ];
[ $tok, $err ] = token();
ok( 'the token comes back',             $tok, 'A1' );
ok( 'nothing is flagged',               DZE_Gmc::broken_since(), 0 );

echo "The connection this shop actually had\n";
connected();
$GLOBALS['reply'] = [ 'body' => json_encode( [
	'error'             => 'invalid_grant',
	'error_description' => 'Token has been expired or revoked.',
] ) ];
[ $tok, $err ] = token();
ok( 'no token, and a sentence instead',  $tok, '' );
ok( 'in the shop\'s words, not Google\'s', false !== strpos( $err, 'Google has revoked this connection' ), true );
// The path is the one that EXISTS: this sentence used to send the shop to
// "Settings → Google Merchant Center", which is not a place in this plugin.
ok( 'with the one thing to do',          false !== strpos( $err, 'Connect Google account again' ), true );
ok( 'and where that screen really is',   false !== strpos( $err, 'Marketing events' ), true );
ok( 'and it is written down',            DZE_Gmc::broken_since() > 0, true );
ok( 'the dead access token is dropped',  get_transient( 'dze_gmc_oauth_token' ), false );

echo "Five feeds, one refusal\n";
// The screenshot was the same error five times, one per market. Once it is
// known, Google is not asked again — the answer cannot be different.
$GLOBALS['asked'] = [];
$said = [];
for ( $i = 0; $i < 5; $i++ ) { $said[] = token()[1]; }
ok( 'Google is not asked again',         count( $GLOBALS['asked'] ), 0 );
ok( 'and every feed says the same thing', count( array_unique( $said ) ), 1 );
ok( 'which is the sentence, not the error',
	false !== strpos( $said[0], 'nothing will sync until it is reconnected' ), true );

echo "Reconnecting is the cure\n";
// What the OAuth callback writes when the shop authorises again.
$conn = DZE_Gmc::get_connection();
unset( $conn['broken'], $conn['broken_why'] );
$conn['refresh_token'] = 'fresh';
update_option( DZE_Gmc::OPT_CONNECTION, $conn, false );
ok( 'the flag goes with the new token',  DZE_Gmc::broken_since(), 0 );
$GLOBALS['reply'] = [ 'body' => json_encode( [ 'access_token' => 'A2', 'expires_in' => 3600 ] ) ];
ok( 'and syncing works again',           token()[0], 'A2' );

echo "A refusal that is NOT a revoked connection\n";
// A network hiccup or a client secret rotated by hand is a different thing
// and must not be filed as "revoked": the shop would be sent to reconnect an
// account that is perfectly connected.
connected();
$GLOBALS['reply'] = [ 'body' => json_encode( [ 'error' => 'invalid_client', 'error_description' => 'Unauthorized' ] ) ];
[ , $err ] = token();
ok( 'it is reported as it came',        false !== strpos( $err, 'Google token refresh failed: Unauthorized' ), true );
ok( 'and nothing is flagged',           DZE_Gmc::broken_since(), 0 );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
