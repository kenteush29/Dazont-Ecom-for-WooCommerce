<?php
/**
 * The update check, and the words it is allowed to say.
 *
 * Run before every release:  php tools/test-updater.php dazont-ecom
 *
 * "4.314.0 > Up to date. Bug." — and the plugin was right that time: three
 * releases had gone out as development builds only, and a shop on the stable
 * channel was genuinely current. What was NOT right is that the same four
 * words are said in a case where nothing was checked at all: a lookup that
 * could not reach GitHub cached a release carrying the SHOP'S OWN version, so
 * for the next half hour every check read that back and answered "Up to date"
 * with total confidence, having never spoken to anybody.
 *
 * NOT BEING ABLE TO LOOK AND BEING CURRENT MUST NEVER WEAR THE SAME WORDS.
 * That is what this file holds, along with the other half of the same evening:
 * the answer names the CHANNEL it looked at, because "up to date" is true of
 * the stable channel and says nothing about the builds sitting beside it.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DZE_VERSION', '4.314.0' );
define( 'DZE_FILE', '/wp/wp-content/plugins/dazont-ecom/dazont-ecom.php' );

function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function self_admin_url( $p = '' ) { return 'https://kula.test/wp-admin/' . $p; }
function admin_url( $p = '' ) { return 'https://kula.test/wp-admin/' . $p; }
function plugin_basename( $f ) { return 'dazont-ecom/dazont-ecom.php'; }
function add_filter( ...$a ) {}
function add_action( ...$a ) {}
function get_bloginfo( $w = '' ) { return '6.5'; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error { public function __construct( ...$a ) {} }
function current_user_can( $c ) { return true; }
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function check_ajax_referer( ...$a ) { return true; }
function wp_enqueue_script( ...$a ) {}
function wp_localize_script( ...$a ) {}
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
$GLOBALS['opts'] = [];

/** The site-wide cache, which is where the fault lived. */
$GLOBALS['site_tr'] = [];
function get_site_transient( $k ) { return $GLOBALS['site_tr'][ $k ] ?? false; }
function set_site_transient( $k, $v, $t = 0 ) { $GLOBALS['site_tr'][ $k ] = $v; return true; }
function delete_site_transient( $k ) { unset( $GLOBALS['site_tr'][ $k ] ); return true; }

/** GitHub, answering what this test tells it to. */
$GLOBALS['http'] = [];
function wp_remote_get( $url, $args = [] ) {
	$GLOBALS['asked'][] = $url;
	return $GLOBALS['http'];
}
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }

/** wp_send_json_* end the request; here they are caught and read. */
class DZE_Answered extends Exception {
	public array $data;
	public bool $ok;
	public function __construct( array $data, bool $ok ) { $this->data = $data; $this->ok = $ok; parent::__construct( 'answered' ); }
}
function wp_send_json_success( $d = [] ) { throw new DZE_Answered( (array) $d, true ); }
function wp_send_json_error( $d = [], $code = 0 ) { throw new DZE_Answered( (array) $d, false ); }

require __DIR__ . '/../' . $dir . '/includes/class-updater.php';

$ran = 0; $fails = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok   %s\n", $what ); return; }
	$fails++;
	printf( "  WRONG %s\n       got  %s\n       want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
/** The answer the "Check for updates" link puts on the screen. */
function checked(): array {
	try {
		DZE_Updater::instance()->ajax_check();
	} catch ( DZE_Answered $e ) {
		return [ 'ok' => $e->ok, 'data' => $e->data ];
	}
	return [ 'ok' => false, 'data' => [] ];
}
/** GitHub's own shape, for the releases this test needs. */
function releases( array $rows ): array {
	$out = [];
	foreach ( $rows as $r ) {
		$out[] = [
			'tag_name'   => $r[0],
			'name'       => $r[0],
			'draft'      => false,
			'prerelease' => (bool) $r[1],
			'html_url'   => 'https://github.test/' . $r[0],
			'published_at' => '2026-09-08T21:59:24Z',
			'body'       => '',
			'assets'     => [ [ 'name' => 'dazont-ecom-' . $r[0] . '.zip', 'browser_download_url' => 'https://github.test/' . $r[0] . '.zip' ] ],
		];
	}
	return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $out ) ];
}

echo "\nGitHub cannot be reached\n";
// THE FAULT. A lookup that failed cached a release carrying the shop's own
// version, so every check for the next half hour read it back and answered
// "Up to date" — on a shop that had never spoken to anybody.
$GLOBALS['http']  = new WP_Error();
$GLOBALS['asked'] = [];
$said = checked();
ok( 'the check says it failed',         $said['ok'], false );
ok( 'and says so in words',             (string) ( $said['data']['message'] ?? '' ), 'Could not reach GitHub. Try again in a moment.' );
// AND THE FAILURE IS NOT AN ANSWER. Asked again with GitHub still down, it
// must not have turned into "up to date" in the meantime.
$GLOBALS['asked'] = [];
$said = checked();
ok( 'asked again, it still says nothing else', $said['ok'], false );
ok( 'and it never says up to date',
	false !== strpos( (string) ( $said['data']['message'] ?? '' ), 'Up to date' ), false );

echo "\nBut the failure is remembered, so GitHub is not hammered\n";
// The manual check flushes on purpose — a person pressing a button wants a
// real answer — so the quiet path is the one that reads the cache.
$GLOBALS['asked'] = [];
$dze_ref = new ReflectionMethod( 'DZE_Updater', 'get_latest_release' );
$dze_ref->setAccessible( true );
ok( 'a remembered failure answers nothing', $dze_ref->invoke( DZE_Updater::instance() ), null );
ok( 'without asking again',             count( $GLOBALS['asked'] ), 0 );

echo "\nGitHub answers\n";
$GLOBALS['http'] = releases( [ [ '4.314.0', false ], [ '4.317.0', true ] ] );
$said = checked();
// A SHOP ON THE STABLE CHANNEL DOES NOT SEE DEVELOPMENT BUILDS, and that is
// right — but the words must say which channel was looked at, or three builds
// sitting beside it are invisible with no way to ask why.
ok( 'the stable channel finds the stable release', (string) ( $said['data']['version'] ?? '' ), '4.314.0' );
ok( 'and there is nothing to update to',  (bool) ( $said['data']['update'] ?? true ), false );
ok( 'the answer names the channel it looked at',
	false !== strpos( (string) ( $said['data']['html'] ?? '' ), 'on the stable channel' ), true );

echo "\nThe development channel, switched on\n";
$GLOBALS['opts']['dze_dev_channel'] = 1;
$said = checked();
ok( 'it sees the development build',    (string) ( $said['data']['version'] ?? '' ), '4.317.0' );
ok( 'and offers it',                    (bool) ( $said['data']['update'] ?? false ), true );
ok( 'naming the version',               false !== strpos( (string) ( $said['data']['html'] ?? '' ), '4.317.0' ), true );

echo "\nA newer stable release\n";
$GLOBALS['opts']['dze_dev_channel'] = 0;
$GLOBALS['http'] = releases( [ [ '4.314.0', false ], [ '4.317.0', false ] ] );
$said = checked();
ok( 'the stable channel offers it too', (string) ( $said['data']['version'] ?? '' ), '4.317.0' );
// GitHub's list is ordered by TAG NAME, so "4.14.0" sorts above "4.9.0" and
// taking the first row would miss newer releases. The comparison is semantic.
$GLOBALS['http'] = releases( [ [ '4.9.0', false ], [ '4.14.0', false ] ] );
$said = checked();
ok( 'and it compares versions, not strings', (string) ( $said['data']['version'] ?? '' ), '4.14.0' );

echo "\nWhat WordPress is told\n";
// The plugins screen reads this. A shop that cannot reach GitHub must not be
// told there is no update — it must be told nothing at all.
$GLOBALS['http'] = releases( [ [ '4.317.0', false ] ] );
DZE_Updater::flush();
$dze_tr = (object) [ 'checked' => [ 'dazont-ecom/dazont-ecom.php' => '4.314.0' ], 'response' => [], 'no_update' => [] ];
$dze_out = DZE_Updater::instance()->inject_update( $dze_tr );
ok( 'the update is offered to WordPress',
	(string) ( $dze_out->response['dazont-ecom/dazont-ecom.php']->new_version ?? '' ), '4.317.0' );
ok( 'with the zip to install',
	(string) ( $dze_out->response['dazont-ecom/dazont-ecom.php']->package ?? '' ), 'https://github.test/4.317.0.zip' );
$GLOBALS['http'] = new WP_Error();
DZE_Updater::flush();
$dze_tr  = (object) [ 'checked' => [ 'dazont-ecom/dazont-ecom.php' => '4.314.0' ], 'response' => [], 'no_update' => [] ];
$dze_out = DZE_Updater::instance()->inject_update( $dze_tr );
ok( 'unreachable, nothing is offered',  $dze_out->response, [] );
ok( 'and nothing is called up to date', $dze_out->no_update, [] );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
