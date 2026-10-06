<?php
/**
 * One Google key for the whole plugin.
 *
 * Run before every release:  php tools/test-google.php dazont-ecom
 *
 * « Possible de faire passer toutes les fonctions google par le "Comptes de
 * service" ? » (06/10/2026). A service account has no consent screen, so no
 * app to publish, no brand to verify and no seven-day disconnection. What is
 * checked here: a pasted key is read before it is kept, the token is signed
 * the way Google checks it, and every screen says where the key must be added.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );

function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_js( $s ) { return addslashes( (string) $s ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function wp_kses_post( $s ) { return (string) $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_create_nonce( $a = '' ) { return 'n'; }
function add_action( ...$a ) {}
$GLOBALS['opts'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
$GLOBALS['trans'] = [];
function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['trans'][ $k ] = $v; return true; }
$GLOBALS['asked'] = [];
$GLOBALS['reply'] = [];
function wp_remote_post( $url, $args = [] ) { $GLOBALS['asked'][] = [ 'url' => $url, 'body' => $args['body'] ?? [] ]; return $GLOBALS['reply']; }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
function is_wp_error( $t ) { return false; }
$GLOBALS['mods'] = [ 'gmc' => true, 'netlinking' => true, 'google_ads' => true ];
class DZE_Modules { public static function enabled( $id ) { return ! empty( $GLOBALS['mods'][ $id ] ); } }
class DZE_Screens { public static function url( $id, $tab = '' ) { return "screen:$id/$tab"; } }

require __DIR__ . '/../' . $dir . '/includes/class-google.php';

$wrong = 0;
$same  = static function ( string $what, $got, $want ) use ( &$wrong ): void {
	$ok = $got === $want;
	if ( ! $ok ) {
		$wrong++;
	}
	printf( "  %s %s%s\n", $ok ? 'ok   ' : 'WRONG', $what, $ok ? '' : ' — got ' . var_export( $got, true ) . ', want ' . var_export( $want, true ) );
};

// A real key, made here: the token must be signed the way Google checks it.
$pair = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
openssl_pkey_export( $pair, $pem );
$pub  = openssl_pkey_get_details( $pair )['key'];
$file = [
	'type'         => 'service_account',
	'project_id'   => 'dazont-473008',
	'client_email' => 'dazont@dazont-473008.iam.gserviceaccount.com',
	'private_key'  => $pem,
	'token_uri'    => 'https://oauth2.googleapis.com/token',
];

echo "A key pasted by hand\n";
$p = DZE_Google::parse( json_encode( $file ) );
$same( 'the service account\'s JSON file is taken', $p['ok'], true );
$same( 'and its address read', $p['email'], 'dazont@dazont-473008.iam.gserviceaccount.com' );
$same( 'nothing pasted: said', DZE_Google::parse( '  ' )['ok'], false );
$same( 'a text that is not JSON: said', false !== strpos( DZE_Google::parse( 'dazont@x.iam.gserviceaccount.com' )['said'], 'not the JSON file' ), true );
$same( 'the OAuth client\'s JSON, the other file Google hands out: named', false !== strpos( DZE_Google::parse( json_encode( [ 'web' => [ 'client_id' => 'x' ] ] ) )['said'], 'OAuth client' ), true );
$same( 'a JSON without a private key: refused', DZE_Google::parse( json_encode( [ 'type' => 'service_account', 'client_email' => 'a@b' ] ) )['ok'], false );
$cut = $file;
$cut['private_key'] = substr( $pem, 0, 300 ) . "\n-----END PRIVATE KEY-----\n";
$same( 'a key cut on the way: refused', false !== strpos( DZE_Google::parse( json_encode( $cut ) )['said'], 'does not open' ), true );
$bare = $file;
unset( $bare['token_uri'] );
$same( 'no token_uri: Google\'s own is filled in', json_decode( DZE_Google::parse( json_encode( $bare ) )['json'], true )['token_uri'] ?? '', 'https://oauth2.googleapis.com/token' );

echo "No key yet\n";
$same( 'no address', DZE_Google::email(), '' );
$same( 'a console link opens no particular project', DZE_Google::console( 'iam-admin/serviceaccounts' ), 'https://console.cloud.google.com/iam-admin/serviceaccounts' );
$said = '';
try {
	DZE_Google::token( 'https://www.googleapis.com/auth/content' );
} catch ( RuntimeException $e ) {
	$said = $e->getMessage();
}
$same( 'asking for a token says there is no key', false !== strpos( $said, 'No Google service account key' ), true );
$html = DZE_Google::block();
$same( 'the block says how to make one', false !== strpos( $html, 'iam-admin/serviceaccounts' ) && false !== strpos( $html, 'Add key' ), true );
$same( 'and takes it', false !== strpos( $html, 'dze-google-json' ) && false !== strpos( $html, 'dze-google-save' ), true );
$same( 'with no <form> of its own (it may sit inside one)', false !== stripos( $html, '<form' ), false );
$same( 'its script is printed once per page', substr_count( $html . DZE_Google::block(), '<script>' ), 1 );

echo "The key in\n";
$GLOBALS['opts'][ DZE_Google::OPT ] = $p['json'];
$same( 'its address', DZE_Google::email(), 'dazont@dazont-473008.iam.gserviceaccount.com' );
$same( 'links open its own project', DZE_Google::console( 'apis/library/googleads.googleapis.com' ), 'https://console.cloud.google.com/apis/library/googleads.googleapis.com?project=dazont-473008' );
$same( 'even after a query string', DZE_Google::console( 'apis/x?a=1' ), 'https://console.cloud.google.com/apis/x?a=1&project=dazont-473008' );

$GLOBALS['reply'] = [ 'body' => json_encode( [ 'access_token' => 'ya29.T1', 'expires_in' => 3599 ] ) ];
$tok = DZE_Google::token( 'https://www.googleapis.com/auth/adwords' );
$same( 'a token comes back', $tok, 'ya29.T1' );
$sent = $GLOBALS['asked'][0]['body'] ?? [];
$same( 'asked of Google\'s token address', $GLOBALS['asked'][0]['url'] ?? '', 'https://oauth2.googleapis.com/token' );
$same( 'as a signed JWT', $sent['grant_type'] ?? '', 'urn:ietf:params:oauth:grant-type:jwt-bearer' );
[ $h, $c, $sig ] = array_pad( explode( '.', (string) ( $sent['assertion'] ?? '' ) ), 3, '' );
$claim = json_decode( base64_decode( strtr( $c, '-_', '+/' ) ), true );
$same( 'in the service account\'s name', $claim['iss'] ?? '', 'dazont@dazont-473008.iam.gserviceaccount.com' );
$same( 'for the API asked for', $claim['scope'] ?? '', 'https://www.googleapis.com/auth/adwords' );
$same( 'addressed to Google\'s token address', $claim['aud'] ?? '', 'https://oauth2.googleapis.com/token' );
$raw = base64_decode( strtr( $sig, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $sig ) % 4 ) % 4 ) );
$same( 'and the signature is the key\'s (RS256)', openssl_verify( $h . '.' . $c, $raw, $pub, OPENSSL_ALGO_SHA256 ), 1 );
$same( 'kept for the next call', DZE_Google::token( 'https://www.googleapis.com/auth/adwords' ) === 'ya29.T1' && 1 === count( $GLOBALS['asked'] ), true );
$GLOBALS['reply'] = [ 'body' => json_encode( [ 'access_token' => 'ya29.T2' ] ) ];
$same( 'another API has a token of its own', DZE_Google::token( 'https://www.googleapis.com/auth/webmasters.readonly' ), 'ya29.T2' );
$same( 'cached under the prefix the cleanup knows', isset( $GLOBALS['trans'][ 'dze_gmc_token_' . md5( 'dazont@dazont-473008.iam.gserviceaccount.com|https://www.googleapis.com/auth/webmasters.readonly' ) ] ), true );
$GLOBALS['reply'] = [ 'body' => json_encode( [ 'error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.' ] ) ];
$said = '';
try {
	DZE_Google::token( 'https://www.googleapis.com/auth/content' );
} catch ( RuntimeException $e ) {
	$said = $e->getMessage();
}
$same( 'a key Google refuses: Google\'s words, said', false !== strpos( $said, 'Invalid JWT Signature' ), true );

echo "Where the address must be added\n";
$ids = array_column( DZE_Google::places(), 'id' );
$same( 'every Google module on: three places', $ids, [ 'merchant', 'searchconsole', 'ads' ] );
$roles = array_column( DZE_Google::places(), 'role', 'id' );
$same( 'Search Console: Restricted is enough (read only)', $roles['searchconsole'], 'Restricted' );
$same( 'Google Ads: read only', $roles['ads'], 'Read only' );
$apis = array_column( DZE_Google::places(), 'api_url', 'id' );
$same( 'each API to switch on, in the key\'s project', $apis['searchconsole'], 'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com?project=dazont-473008' );
$GLOBALS['mods'] = [ 'gmc' => false, 'netlinking' => true, 'google_ads' => false ];
$same( 'Netlinking alone: Search Console only', array_column( DZE_Google::places(), 'id' ), [ 'searchconsole' ] );
$same( 'and the key is pasted on its own screen', DZE_Google::screen_url(), 'screen:netlinking/console' );
$GLOBALS['mods'] = [ 'gmc' => false, 'netlinking' => false, 'google_ads' => true ];
$same( 'Google Ads alone still needs Merchant Center (the listing)', array_column( DZE_Google::places(), 'id' ), [ 'merchant', 'ads' ] );
$same( 'and the key goes on its Connection tab', DZE_Google::screen_url(), 'screen:ads/connection' );
$GLOBALS['mods'] = [ 'gmc' => true, 'netlinking' => true, 'google_ads' => true ];
$html = DZE_Google::block();
$same( 'the block shows the address to copy', false !== strpos( $html, 'data-copy="dazont@dazont-473008.iam.gserviceaccount.com"' ), true );
$same( 'and every place, with its link', false !== strpos( $html, 'search.google.com/search-console' ) && false !== strpos( $html, 'ads.google.com/aw/accountaccess/users' ) && false !== strpos( $html, 'merchants.google.com' ), true );
$same( 'the key can be replaced', false !== strpos( $html, 'Replace the key' ), true );
$same( 'the private key never reaches the page', false !== strpos( $html, 'PRIVATE KEY' ) || false !== strpos( $html, substr( $pem, 40, 30 ) ), false );

echo "A key held in wp-config.php\n";
define( 'DZE_GMC_SERVICE_ACCOUNT', json_encode( array_merge( $file, [ 'client_email' => 'cfg@dazont-473008.iam.gserviceaccount.com' ] ) ) );
$same( 'wins over the saved one', DZE_Google::email(), 'cfg@dazont-473008.iam.gserviceaccount.com' );
$same( 'and is never offered for replacement', false !== strpos( DZE_Google::block(), 'Replace the key' ), false );

echo $wrong ? "\n$wrong wrong\n" : "\nall right\n";
