<?php
/**
 * The guardrails on image spending, and the bill said BEFORE the press.
 *
 * Run before every release:  php tools/test-spend.php dazont-ecom
 *
 * "J'ai dépensé hier 40$ en génération d'images. Ce n'est pas normal que
 * autant ait été dépensé. Il faut une limite par post, d'appel par heure. 10
 * semble bien." — and, the next morning: "sur fal j'ai vu 24 images générées
 * pour le même produit le fsb patch. Ca m'étonnerait que ce soit ma colab qui
 * en ait fait autant."
 *
 * Twenty-four is what the bulk screen asks for on its own: three prompt rows
 * at four attempts each is twelve images per product, run twice. So there are
 * two halves to this, and this gate holds both:
 *
 *   1. A CEILING that stops a run that has gone round in circles — counted on
 *      the ATTEMPT, per clock hour, per product and for the whole shop, and
 *      asked at the ONE funnel every image request passes through.
 *   2. A PRESS THAT SAYS WHAT IT IS ABOUT TO SPEND. Every figure was already
 *      on the bulk screen — the rows, the attempts, the ticked products, the
 *      price per image — and none of them had ever been multiplied together.
 *      The button read "Generate (30)", which is a count of products and reads
 *      like a count of the work.
 *
 * The monthly budget was no guard at all here: `over_budget()` is `$cap > 0`,
 * so a shop that never set one had nothing in its way.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'DZE_VERSION', 'test' );
define( 'DZE_URL', 'http://kula.test/wp-content/plugins/dazont-ecom/' );
define( 'DZE_DIR', __DIR__ . '/../' . $dir . '/' );
define( 'DZE_FILE', DZE_DIR . 'dazont-ecom.php' );

function __( $s, $d = '' ) { return $s; }
function _n( $o, $m, $n, $d = '' ) { return 1 === (int) $n ? $o : $m; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function apply_filters( $t, $v = null, ...$a ) { return $v; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function current_time( $t = '', $g = 0 ) { return 'timestamp' === $t ? time() : gmdate( 'Y-m-d H:i:s' ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }

// REAL TRANSIENTS. Stubbed to `false`/no-op — as the other harnesses stub them
// — every counter reads nought for ever and the ceiling can never be reached:
// the gate would go green on a guard that does nothing, which is the exact
// failure it exists to catch.
$GLOBALS['tr'] = [];
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['tr'][ $k ] = $v; $GLOBALS['ttl'][ $k ] = (int) $ttl; return true; }
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); return true; }

$GLOBALS['opts'] = [];
$GLOBALS['mai']  = [];
class DZE_Marketing_Ai {
	const MENU_SLUG = 'dazont-ecom-ai';
	public static function get_settings() { return $GLOBALS['mai']; }
	public static function api_key() { return 'k'; }
}

require __DIR__ . '/../' . $dir . '/includes/class-ai-usage.php';

$ran = 0; $fails = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok   %s\n", $what ); return; }
	$fails++;
	printf( "  WRONG %s\n       got  %s\n       want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
function reset_counters(): void { $GLOBALS['tr'] = []; $GLOBALS['ttl'] = []; }

echo "\nThe ceilings the shop ships with\n";
$GLOBALS['mai'] = [];
ok( 'ten images of one product an hour', DZE_Ai_Usage::fal_post_cap(), 10 );
ok( 'sixty for the whole shop',          DZE_Ai_Usage::fal_hour_cap(), 60 );
$GLOBALS['mai'] = [ 'fal_cap_post' => 3, 'fal_cap_hour' => 5 ];
ok( 'and the shop can say otherwise',    DZE_Ai_Usage::fal_post_cap(), 3 );
// A ceiling set to nothing is OFF, not a ceiling of zero: a shop that typed 0
// to mean "no limit" would otherwise be unable to make a single photograph.
$GLOBALS['mai'] = [ 'fal_cap_post' => 0, 'fal_cap_hour' => 0 ];
reset_counters();
for ( $i = 0; $i < 50; $i++ ) { DZE_Ai_Usage::fal_attempt( 7 ); }
ok( 'nought means no ceiling, not a ceiling of nought', DZE_Ai_Usage::fal_blocked( 7 ), '' );

echo "\nThe per-product ceiling — the fsb patch, twenty-four times\n";
$GLOBALS['mai'] = [ 'fal_cap_post' => 10, 'fal_cap_hour' => 60 ];
reset_counters();
$made = 0;
for ( $i = 0; $i < 24; $i++ ) {
	if ( '' !== DZE_Ai_Usage::fal_blocked( 7 ) ) { break; }
	DZE_Ai_Usage::fal_attempt( 7 );
	$made++;
}
ok( 'a run asking twenty-four times gets ten', $made, 10 );
ok( 'and the eleventh is refused in words',
	false !== strpos( DZE_Ai_Usage::fal_blocked( 7 ), 'already had 10 images' ), true );
// The ceiling is on ONE product: the next one starts from nothing. A ceiling
// that spilled between products would stop an ordinary bulk run dead.
ok( 'another product is untouched by it', DZE_Ai_Usage::fal_blocked( 8 ), '' );
ok( 'and the shop-wide count did move',   DZE_Ai_Usage::fal_used( 8 )['hour'], 10 );

echo "\nThe shop-wide ceiling\n";
$GLOBALS['mai'] = [ 'fal_cap_post' => 10, 'fal_cap_hour' => 25 ];
reset_counters();
$made = 0;
for ( $p = 7; $p < 20; $p++ ) {
	for ( $i = 0; $i < 10; $i++ ) {
		if ( '' !== DZE_Ai_Usage::fal_blocked( $p ) ) { break 2; }
		DZE_Ai_Usage::fal_attempt( $p );
		$made++;
	}
}
ok( 'a loop over products stops at the hour ceiling', $made, 25 );
// AND IT SAYS WHICH CEILING STOPPED IT, in the figure it actually counted. It
// used to read "made 25 images in the past hour", which is what the ceiling
// counts only on a shop where every request came back.
ok( 'and it says which ceiling stopped it',
	false !== strpos( DZE_Ai_Usage::fal_blocked( 99 ), 'sent 25 requests to fal.ai' ), true );

echo "\nCounted on the ATTEMPT, not on what came back\n";
// A run failing in a loop reaches fal exactly as often as one succeeding. If
// only the successes were counted, the broken run — the expensive one — would
// never be stopped.
$GLOBALS['mai'] = [ 'fal_cap_post' => 10, 'fal_cap_hour' => 60 ];
reset_counters();
DZE_Ai_Usage::fal_attempt( 7 );
ok( 'one attempt, one counted for the product', DZE_Ai_Usage::fal_used( 7 )['post'], 1 );
ok( 'and one for the shop',                     DZE_Ai_Usage::fal_used( 7 )['hour'], 1 );
ok( 'the counter outlives the window it counts',
	( $GLOBALS['ttl'][ 'dze_fal_h_' . gmdate( 'YmdH' ) ] ?? 0 ) >= HOUR_IN_SECONDS, true );

echo "\nThe ceiling is asked at the ONE funnel every request passes\n";
// Six places make an image and all six call fal_generate(). Asking the ceiling
// anywhere else is a list somebody has to keep, and the one forgotten is the
// bug.
$src = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content.php' );
$fn  = strstr( $src, 'function fal_generate(' );
$fn  = false === $fn ? '' : substr( $fn, 0, 2000 );
ok( 'fal_generate asks before it sends',   false !== strpos( $fn, 'fal_blocked' ), true );
ok( 'and counts the attempt before it sends', false !== strpos( $fn, 'fal_attempt' ), true );
ok( 'it is told WHICH product it is for',  false !== strpos( $fn, 'int $pid' ), true );
// THE PRODUCT LANE NAMES ITS PRODUCT. The per-product ceiling counts nothing
// for a call that did not say which product it is for, and the whole of the
// product image work — the toolbox, the bulk screen, every automatic pass —
// goes through class-content-ajax.php. The three other callers (a promotion's
// hero, a discount banner, the image lab) have no product to name and are
// stopped by the shop-wide ceiling alone, which is the right answer for them.
$lane  = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content-ajax.php' );
preg_match_all( '/fal_generate\((.*)\);/', $lane, $m );
$calls = (array) ( $m[1] ?? [] );
$blind = 0;
foreach ( $calls as $args ) {
	// The product is NAMED in the call — wherever in the argument list it
	// sits. Pinned to the last position, this check went red the day the
	// trace gained what the photographs are, which is a true statement about
	// argument order and nothing at all about the ceiling it exists for.
	if ( ! preg_match( '/,\s*\$pid\s*(?:,|$)/', trim( $args ) ) ) { $blind++; }
}
ok( 'the product lane really does make images', count( $calls ) >= 2, true );
ok( 'and every one of its calls names its product', $blind, 0 );
// And nothing reaches the provider around it: one endpoint, one funnel, one
// place the ceiling has to be asked.
// UN SEUL APPEL, MEME AVEC DEUX PORTES. Le modele a une adresse de retouche
// et une adresse texte -> image ; le choix se fait DANS l appel, pas dans un
// second wp_remote_post pose a cote — sinon le plafond serait a redemander la.
ok( 'and the provider is reached from exactly one place',
	preg_match_all( '/wp_remote_post\([^;]*FAL_QUEUE/', $src ), 1 );
// ET LA PORTE LIBRE EXISTE. « Image lab exige une image entrante. Règles ça.
// Ça devrait être libre. » `/edit` refuse une requete sans image_urls : sans
// photographie de depart, c est l adresse sans suffixe qu il faut pousser.
ok( 'la porte texte vers image existe',
	false !== strpos( $src, "queue.fal.run/fal-ai/nano-banana-2'" ), true );
// ET LE CHAMP VIDE NE PART PAS AVEC : un image_urls vide envoye a une porte
// qui ne l attend pas est un refus de plus.
ok( 'et un image_urls vide n est jamais envoye',
	(bool) preg_match( '/if \( ! \$dze_fresh \) \{\s*\$dze_body\[.image_urls.\]/', $src ), true );
// AND NEVER BY THE ENDPOINT THAT HOLDS THE SOCKET OPEN. `fal.run` answers on
// the same connection, so a picture slower than this site's patience is one
// fal finishes, bills, and nobody collects — the shop paying for nothing,
// which is what "50% of requests fail and eat my credit" was made of.
ok( 'and never by the one that loses a slow picture',
	false !== strpos( $src, "'https://fal.run/" ), false );

echo "\nWhat the press is about to spend, said before the press\n";
// The figures the two screens multiply together are handed over by PHP. A
// screen missing one of them silently draws nothing, which is the state this
// shipped in for its whole life.
$js = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content-bulk.js' );
ok( 'the bulk screen has somewhere to say it', false !== strpos( $src, 'id="dze-cb-spend"' ), true );
ok( 'and something that writes into it',       false !== strpos( $js, "$('#dze-cb-spend')" ), true );
$tool = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content.js' );
ok( 'the toolbox has one too',                 false !== strpos( $tool, 'dze-cx-willspend' ), true );
// The price and the ceiling travel to BOTH screens: named for one and not the
// other, the second says "360 photographs" and never what they cost.
foreach ( [ 'imageCost', 'falPostCap' ] as $key ) {
	ok( 'both screens are handed ' . $key, substr_count( $src, "'" . $key . "'" ) >= 2, true );
}
foreach ( [ 'willCost', 'willMake', 'overCap' ] as $key ) {
	ok( 'both screens are handed the word ' . $key, substr_count( $src, "'" . $key . "'" ) >= 2, true );
	ok( 'and both read it: ' . $key, ( false !== strpos( $js, 'i18n.' . $key ) ) && ( false !== strpos( $tool, 'i18n.' . $key ) ), true );
}
// AND THE CEILING IS NAMED WHERE IT WOULD BE HIT. Twelve photographs a product
// on a ceiling of ten is a run that stops two short on every line; saying so
// before the press is the difference between a guardrail and a refusal
// halfway through.
ok( 'the bulk screen warns when the order is over the ceiling',
	false !== strpos( $js, 'i18n.overCap' ), true );
ok( 'and so does the toolbox',  false !== strpos( $tool, 'i18n.overCap' ), true );

echo "\nA CALL THAT FAILED IS STILL A CALL\n";
// "Dans logs, je vois la quantite d'appels nanobanana fal qui est a 48. Hors,
// le module generation d'image est bloque pour limite atteinte de 100 appels
// par heure. WTF"
//
// Both figures were right and neither could be checked against the other. The
// ceiling counts the ATTEMPT — deliberately, it is what stops a run going
// round in circles — and `record()` was called only where an image came back,
// so fifty-two calls that failed were counted by the guard and by nothing the
// owner can read. The screen then told him the shop had MADE a hundred images.
$GLOBALS['opts'] = [];
$GLOBALS['mai']  = [ 'fal_cap_post' => 0, 'fal_cap_hour' => 0 ];
DZE_Ai_Usage::record( 'fal', 0, 0, 'nano-banana-2', 0.04 );
DZE_Ai_Usage::record( 'fal', 0, 0, 'nano-banana-2', 0.04, true );
DZE_Ai_Usage::record( 'fal', 0, 0, 'nano-banana-2', 0.0,  true );
$row = DZE_Ai_Usage::model_report()[0] ?? [];
ok( 'every call is counted, whatever came back', $row['calls'] ?? 0, 3 );
ok( 'and the ones that failed are counted too',  $row['ko'] ?? -1, 2 );
// A REQUEST THE PROVIDER ANSWERED IS PAID FOR EVEN WHEN THE ANSWER IS
// UNUSABLE. fal returns 200 with no image and bills for it; that cost was
// dropped on the floor, so the spend under-reported every failed picture.
ok( 'and a failure the provider billed is in the spend',
	round( (float) ( $row['cost'] ?? 0 ), 4 ), 0.08 );

echo "\nThe ceiling says what actually happened\n";
// "The shop has made 100 images in the past hour" over a log holding 48 is a
// sentence the shop can check and find wrong. What it may say is what it
// counted: how many went out, and how many came back.
reset_counters();
$GLOBALS['mai'] = [ 'fal_cap_post' => 0, 'fal_cap_hour' => 4 ];
for ( $i = 0; $i < 4; $i++ ) { DZE_Ai_Usage::fal_attempt( 7 ); }
DZE_Ai_Usage::fal_made( 7 );
ok( 'what came back is counted on its own',  DZE_Ai_Usage::fal_used( 7 )['made'], 1 );
ok( 'and what went out is still the ceiling', DZE_Ai_Usage::fal_used( 7 )['hour'], 4 );
$said = DZE_Ai_Usage::fal_blocked( 7 );
ok( 'the wall says how many requests went out', false !== strpos( $said, '4 requests' ), true );
ok( 'and how many came back as a photograph',   false !== strpos( $said, '1 came back' ), true );
ok( 'it never claims images it did not make',   false !== strpos( $said, 'made 4 images' ), false );
// A SHOP WHERE NOTHING FAILED IS NOT TOLD ABOUT FAILURES. The second half of
// the sentence is news, and news every hour is noise.
reset_counters();
for ( $i = 0; $i < 4; $i++ ) { DZE_Ai_Usage::fal_attempt( 8 ); DZE_Ai_Usage::fal_made( 8 ); }
ok( 'all four came back, so nothing is said about failures',
	false !== strpos( DZE_Ai_Usage::fal_blocked( 8 ), 'came back' ), false );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
