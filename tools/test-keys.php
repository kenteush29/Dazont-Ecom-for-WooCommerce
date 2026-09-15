<?php
/**
 * Every field that asks for somebody else's key says which key, with which
 * rights, and where it is made.
 *
 * Run before every release:  php tools/test-keys.php dazont-ecom
 *
 * "Klaviyo par exemple encore une lacune : je ne sais pas quel type de clé
 * API il faut — all access ? ou pas ? Et le plugin pourrait largement inclure
 * un URL qui redirige vers le bon menu Klaviyo. C'est exactement de ce genre
 * d'attention au détail dont je parle." And on Google: "Aucun lien externe
 * pour un setup rapide et instinctif… C'est une opération que je dois refaire
 * assez souvent."
 *
 * The hint is ONE function, printed under the four fields; this holds what
 * each says, and — by reading the four screens — that each of them prints it
 * for its own provider. A hint that exists and is drawn nowhere is the fault
 * this plugin has shipped before.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_js( $s ) { return addslashes( (string) $s ); }
function add_action( ...$a ) {}
function wp_create_nonce( $a = '' ) { return 'n'; }

require __DIR__ . '/../' . $dir . '/includes/class-api-keys.php';

$ran = 0; $fails = 0;
function ok( string $what, $got, $want ): void {
	global $ran, $fails;
	$ran++;
	if ( $got === $want ) { echo "  ok    $what\n"; return; }
	$fails++;
	echo "  FAIL  $what\n          got  " . var_export( $got, true ) . "\n          want " . var_export( $want, true ) . "\n";
}

echo "EVERY PROVIDER'S HINT SAYS THE THREE THINGS\n";
foreach ( [ 'anthropic', 'fal', 'klaviyo', 'google' ] as $dze_p ) {
	$dze_h = DZE_Api_Keys::hint( $dze_p );
	ok( "$dze_p says what kind of key",   '' !== $dze_h['what'], true );
	ok( "$dze_p links to where it is made", 0 === strpos( $dze_h['url'], 'https://' ), true );
	ok( "$dze_p names the link",          '' !== $dze_h['label'], true );
	$dze_html = DZE_Api_Keys::hint_html( $dze_p );
	ok( "$dze_p is printed as one line",  substr_count( $dze_html, '<p class="description dze-key-hint">' ), 1 );
	ok( "$dze_p opens in a new tab",      false !== strpos( $dze_html, 'target="_blank" rel="noopener noreferrer"' ), true );
}
ok( 'an unknown provider prints nothing', DZE_Api_Keys::hint_html( 'nobody' ), '' );

echo "\nKLAVIYO: WHICH KEY, AND WHAT IT MUST BE ALLOWED TO DO\n";
// The scopes are READ OFF THE CALLS this plugin makes — accounts, campaigns,
// templates, tags, segments, images, metric aggregates — never guessed.
$dze_k = DZE_Api_Keys::hint( 'klaviyo' );
ok( 'a PRIVATE key, not a public one',   false !== strpos( $dze_k['what'], 'PRIVATE key' ), true );
ok( 'and it says how one is recognised', false !== strpos( $dze_k['what'], 'pk_' ), true );
ok( 'the simplest answer is named',      false !== strpos( $dze_k['what'], '"Full access" preset' ), true );
foreach ( [ 'Accounts', 'Campaigns', 'Images', 'Metrics', 'Segments', 'Tags', 'Templates' ] as $dze_scope ) {
	ok( "a custom key must reach $dze_scope", false !== strpos( $dze_k['what'], $dze_scope ), true );
}
ok( 'and it says what a read-only key cannot do', false !== strpos( $dze_k['what'], 'read-only key cannot create a campaign' ), true );
ok( 'the link goes to the API keys page', $dze_k['url'], 'https://www.klaviyo.com/settings/account/api-keys' );

echo "\nGOOGLE: THE THREE SCREENS, IN ORDER\n";
$dze_g = DZE_Api_Keys::hint( 'google' );
ok( 'three steps',                       count( $dze_g['steps'] ?? [] ), 3 );
ok( 'the client first',                  $dze_g['steps'][0][1] ?? '', 'https://console.cloud.google.com/apis/credentials' );
ok( 'publishing the app second',         $dze_g['steps'][1][1] ?? '', 'https://console.cloud.google.com/apis/credentials/consent' );
ok( 'the Merchant API third',            $dze_g['steps'][2][1] ?? '', 'https://console.cloud.google.com/apis/library/merchantapi.googleapis.com' );
ok( 'and the seven-day trap is said where the client is made',
	false !== strpos( $dze_g['what'], 'seven days' ), true );
$dze_gh = DZE_Api_Keys::hint_html( 'google' );
ok( 'printed numbered',                  false !== strpos( $dze_gh, '>1. Create the OAuth client ↗<' ), true );
ok( 'all three',                         substr_count( $dze_gh, ' ↗</a>' ), 3 );

echo "\nAND EACH SCREEN PRINTS THE HINT FOR ITS OWN PROVIDER\n";
// Read off the source rather than drawn: three of these screens need a whole
// shop stubbed to draw (their own gates do that); what is held here is that
// the call is THERE, on the right file, for the right provider.
$dze_calls = [
	'admin/views/marketing-ai-settings.php' => 'anthropic',
	'includes/class-content.php'            => 'fal',
	'includes/class-klaviyo.php'            => 'klaviyo',
	'admin/views/gmc-settings.php'          => 'google',
];
foreach ( $dze_calls as $dze_file => $dze_p ) {
	$dze_src = (string) file_get_contents( __DIR__ . '/../' . $dir . '/' . $dze_file );
	ok( "$dze_file prints the $dze_p hint",
		false !== strpos( $dze_src, "DZE_Api_Keys::hint_html( '$dze_p' )" ), true );
}
// AND NO SCREEN STILL TYPES THE PATH IN WORDS beside it: "Klaviyo → Settings →
// API keys, with campaigns, templates and lists enabled" was a sentence
// naming another product's screen with no way to it, and a scope list that
// was wrong.
$dze_kl = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-klaviyo.php' );
ok( 'the typed Klaviyo path is gone',    false !== strpos( $dze_kl, 'with campaigns, templates and lists' ), false );
$dze_gm = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/views/gmc-settings.php' );
ok( 'and the typed Google path too',     false !== strpos( $dze_gm, 'Google Cloud console → APIs & Services → OAuth consent screen' ), false );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
