<?php
/**
 * The AI trace: every call written down whole, and readable on screen.
 *
 * Run before every release:  php tools/test-trace.php dazont-ecom
 *
 * This is the owner's debug tool — "je ne sais pas exactement ce qui se passe
 * côté code" — so the thing to prove is the WIRING, not just the storage: a
 * real DZE_Marketing_Ai::complete() call, with only the HTTP transport
 * stubbed, must leave a row holding the words that were sent and the words
 * that came back, under the tool's own label. A failed call must leave one
 * too, because the call that failed is the one worth reading.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'DZE_ANTHROPIC_API_KEY', 'sk-test-not-real' );

function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return $n > 1 ? $b : $a; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_parse_args( $args, $defaults = [] ) { return array_merge( (array) $defaults, (array) $args ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_unslash( $v ) { return $v; }
function register_setting() {} function add_settings_error() {}
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function human_time_diff( $from, $to = 0 ) { return max( 1, (int) round( ( ( $to ?: time() ) - $from ) / 60 ) ) . ' mins'; }
function add_action() {} function add_filter() {} function do_action() {} function apply_filters( $t, $v = null ) { return $v; }
function is_admin() { return true; }
function current_time( $t = 'timestamp' ) { return 'timestamp' === $t ? time() : gmdate( 'Y-m-d H:i:s' ); }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function wp_next_scheduled( $h ) { return 0; }
function wp_schedule_event() {}

$GLOBALS['dze_opts'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['dze_opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['dze_opts'][ $k ] = $v; $GLOBALS['dze_autoload'][ $k ] = $a; return true; }

$GLOBALS['dze_http'] = [ 'code' => 200, 'body' => '' ];
function wp_remote_post( $url, $args = [] ) {
	$GLOBALS['dze_posted'][] = [ 'url' => $url ] + $args;
	return [ 'response' => [ 'code' => $GLOBALS['dze_http']['code'] ], 'body' => $GLOBALS['dze_http']['body'] ];
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function is_wp_error( $t ) { return false; }
class DZE_Health { public static function log( ...$a ) {} }
class DZE_Wpml { public static function is_active() { return false; } }

require __DIR__ . '/../' . $dir . '/includes/class-ai-usage.php';
require __DIR__ . '/../' . $dir . '/includes/class-marketing-ai.php';

$fails = 0;
$ran   = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok    %s\n", $what ); return; }
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
function rows(): array { return DZE_Ai_Usage::trace_rows(); }

echo "A real call leaves a readable row\n";
$GLOBALS['dze_http'] = [ 'code' => 200, 'body' => json_encode( [
	'content' => [ [ 'type' => 'text', 'text' => '{"subject":"Hello"}' ] ],
	'usage'   => [ 'input_tokens' => 100, 'output_tokens' => 20 ],
] ) ];
DZE_Ai_Usage::unit( 'promo_email' );
$out = DZE_Marketing_Ai::complete( 'You write emails.', 'Write the launch email.' );
DZE_Ai_Usage::unit();
ok( 'the call still answers',           $out, '{"subject":"Hello"}' );
$row = rows()[0] ?? [];
ok( 'one row was written',              count( rows() ), 1 );
ok( 'under the tool that asked',        $row['unit'] ?? '', 'promo_email' );
ok( 'holding the system prompt',        false !== strpos( (string) $row['sent'], 'You write emails.' ), true );
ok( 'and the user prompt',              false !== strpos( (string) $row['sent'], 'Write the launch email.' ), true );
ok( 'and the answer, verbatim',         $row['got'] ?? '', '{"subject":"Hello"}' );
ok( 'on the provider that served it',   $row['provider'] ?? '', 'anthropic' );

echo "A refused call is written down too\n";
$GLOBALS['dze_http'] = [ 'code' => 429, 'body' => json_encode( [ 'error' => [ 'message' => 'Rate limited' ] ] ) ];
$threw = '';
try { DZE_Marketing_Ai::complete( 'S', 'U' ); } catch ( Throwable $e ) { $threw = $e->getMessage(); }
ok( 'the caller still gets the error',  false !== strpos( $threw, 'Rate limited' ), true );
$row = rows()[0] ?? [];
ok( 'and the trace names it',           0 === strpos( (string) ( $row['got'] ?? '' ), 'ERROR' ), true );
ok( 'with the provider\'s own words',   false !== strpos( (string) $row['got'], 'Rate limited' ), true );

echo "The trace stays small\n";
$GLOBALS['dze_http'] = [ 'code' => 200, 'body' => json_encode( [ 'content' => [ [ 'type' => 'text', 'text' => 'ok' ] ], 'usage' => [] ] ) ];
for ( $i = 0; $i < 20; $i++ ) {
	DZE_Marketing_Ai::complete( 'S', 'call number ' . $i );
}
ok( 'a dozen rows and no more',         count( rows() ), 12 );
ok( 'the newest first',                 false !== strpos( (string) ( rows()[0]['sent'] ?? '' ), 'call number 19' ), true );
DZE_Ai_Usage::trace( 'anthropic', 'm', str_repeat( 'x', 60000 ), str_repeat( 'y', 60000 ), 1.0 );
ok( 'a huge prompt is bounded',         mb_strlen( (string) ( rows()[0]['sent'] ?? '' ) ), 20000 );
ok( 'a huge answer too',                mb_strlen( (string) ( rows()[0]['got'] ?? '' ) ), 10000 );
ok( 'and it is never autoloaded',       $GLOBALS['dze_autoload']['dze_ai_trace'] ?? null, false );
ok( 'stored under its own option',      is_array( $GLOBALS['dze_opts']['dze_ai_trace'] ?? null ), true );

echo "And it reads like a screen, not a dump\n";
ob_start();
DZE_Ai_Usage::render_trace();
$html = (string) ob_get_clean();
ok( 'the tool label is printed',        false !== strpos( $html, 'Promotion email' ) || false !== strpos( $html, 'Everything else' ), true );
ok( 'the exchange is behind a click',   substr_count( $html, '<details' ) >= 1, true );
ok( 'what was sent is shown escaped',   false !== strpos( $html, esc_html( 'xxx' ) ), true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
