<?php
/**
 * Setup: what it says is configured, and above all what it refuses to say.
 *
 * Run before every release:  php tools/test-setup.php dazont-ecom
 *
 * "Il va me falloir un tableau de bord de setup du plugin… le 2e oui, avec
 * message d'avertissement quand le setup n'a jamais été fait."
 *
 * A screen that reports on how a shop is configured is the one screen where a
 * wrong answer is worse than none: told everything is fine, nobody looks
 * again. So the four things this holds are the four ways it goes wrong.
 *
 *  1. A step whose MODULE IS OFF is not a shortfall. A shop that does not use
 *     Klaviyo is not a shop missing a Klaviyo key, and a notice that says
 *     otherwise is a notice everybody learns to ignore.
 *  2. The EMAIL lines are Klaviyo's own — `setup_items()` — never a second
 *     list written beside it, and the module says which of its own items are
 *     required, because only it knows.
 *  3. The notice appears while something is missing, says how many, and GOES
 *     ON ITS OWN. There is no "do not show again": the thing it hides would
 *     still be missing.
 *  4. The PAGE IS DRAWN here, not its helpers called. A screen that dies takes
 *     the whole admin page white, before any of our own error handling.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'DZE_VERSION', 'test' );
define( 'DZE_URL', 'http://kula.test/wp-content/plugins/dazont-ecom/' );
define( 'DZE_DIR', __DIR__ . '/../' . $dir . '/' );

function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return 1 === (int) $n ? $a : $b; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function current_user_can( ...$a ) { return $GLOBALS['can'] ?? true; }
function admin_url( $p = '' ) { return 'http://shop.test/wp-admin/' . $p; }
function add_query_arg( $args, $url = '', $third = null ) {
	if ( ! is_array( $args ) ) { $args = [ (string) $args => $url ]; $url = (string) $third; }
	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args );
}
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( str_replace( ' ', '_', (string) $s ) ) ); }
function add_submenu_page( ...$a ) { $GLOBALS['menu'][] = $a; return 'dze_page_' . ( $a[4] ?? '' ); }
// A BODY THAT MOVES TAKES ITS ASSETS WITH IT: the screen is drawn and what it
// asked for is read back, because calling the helper proves the helper works.
function wp_enqueue_style( ...$a ) { $GLOBALS['styles'][] = (string) ( $a[0] ?? '' ); }
function get_current_screen() { return $GLOBALS['screen'] ?? null; }

$GLOBALS['opts'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }

// ---- The shop around it, switchable from the checks below ----
$GLOBALS['mods'] = [];
class DZE_Modules {
	public static function enabled( $id ) { return $GLOBALS['mods'][ $id ] ?? true; }
}
// The shop itself: almost nothing here works without it, so a harness without
// it tests a broken shop rather than a fresh one.
class WooCommerce {}
class DZE_Restock { public const MENU_SLUG = 'dazont-ecom'; }
class DZE_Discounts { public const MENU_SLUG_EVENTS = 'dze-events'; }
class DZE_Diagnostic {
	public const MENU_SLUG = 'dze-content';
	public static function rows() { return $GLOBALS['rules'] ?? []; }
}
class DZE_Marketing_Ai {
	public const MENU_SLUG = 'dazont-ecom-ai';
	public static function api_key() { return $GLOBALS['keys']['anthropic'] ?? ''; }
	// The words and the addresses come from ONE list: a row of this screen
	// naming a tab that does not exist is a button that goes nowhere.
	public static function tab_links() {
		$out = [];
		foreach ( [ 'General', 'Product content', 'Content rules', 'Translation', 'Modules' ] as $t ) {
			$out[ $t ] = 'http://shop.test/wp-admin/admin.php?page=dazont-ecom-ai&tab=' . sanitize_key( $t );
		}
		return $out;
	}
}
class DZE_Content { public static function fal_key() { return $GLOBALS['keys']['fal'] ?? ''; } }
class DZE_Ai_Usage {
	public static function fal_post_cap() { return $GLOBALS['caps'][0] ?? 10; }
	public static function fal_hour_cap() { return $GLOBALS['caps'][1] ?? 60; }
}
class DZE_Klaviyo {
	public static function key() { return $GLOBALS['keys']['klaviyo'] ?? ''; }
	public static function setup_items() {
		return [
			[ 'label' => 'Add your Klaviyo API key', 'url' => 'u1', 'need' => true,  'done' => '' !== self::key(), 'note' => 'n1' ],
			[ 'label' => 'Choose who the emails go to', 'url' => 'u2', 'need' => true,  'done' => ! empty( $GLOBALS['klav_inc'] ), 'note' => 'n2' ],
			[ 'label' => 'Leave out your recent buyers', 'url' => 'u3', 'need' => false, 'done' => false, 'note' => 'Recommended, not required' ],
			[ 'label' => 'Take the header and footer from Klaviyo', 'url' => 'u4', 'need' => true, 'done' => ! empty( $GLOBALS['klav_shell'] ), 'note' => 'n4' ],
		];
	}
}
class DZE_Gmc {
	public static function instance() { return new self(); }
	public function is_configured() { return ! empty( $GLOBALS['gmc_ok'] ); }
}
class DZE_Wpml {
	public static function get_active_languages() { return $GLOBALS['langs'] ?? []; }
}
class DZE_Site {
	public static function is_copy() { return ! empty( $GLOBALS['is_copy'] ); }
	public static function known() { return 'kula-tactical.com'; }
	public static function host() { return 'kula-tactical.com'; }
}
class DZE_Health {
	public static function page_url( $tab = '' ) { return 'http://shop.test/wp-admin/admin.php?page=dazont-ecom-logs&tab=' . $tab; }
}
class DZE_Mesh {
	public static function orphan_count() { return $GLOBALS['orph'] ?? null; }
	public static function read_said() { return 'Read 2 mins ago — 830 pages.'; }
}
class DZE_Prompts {
	public static function catalog() { return array_fill_keys( [ 'a', 'b', 'c', 'd' ], [] ); }
	public static function shipped_for( $id ) { return 'shipped ' . $id; }
	public static function text_for( $id ) { return in_array( $id, (array) ( $GLOBALS['mine'] ?? [] ), true ) ? 'mine' : 'shipped ' . $id; }
}

require DZE_DIR . 'includes/class-setup.php';

$ran = 0; $fails = 0;
function ok( string $what, $got, $want ): void {
	global $ran, $fails;
	$ran++;
	if ( $got === $want ) { echo "  ok   $what\n"; return; }
	$fails++;
	echo "  WRONG $what\n       got  " . var_export( $got, true ) . "\n       want " . var_export( $want, true ) . "\n";
}
/** The fake shop as a fresh install: nothing set up at all. */
function blank(): void {
	$GLOBALS['keys']  = [ 'anthropic' => '', 'fal' => '', 'klaviyo' => '' ];
	$GLOBALS['mods']  = [];
	$GLOBALS['gmc_ok'] = false;
	$GLOBALS['langs'] = [ 'en' => [] ];
	$GLOBALS['is_copy'] = false;
	$GLOBALS['caps']  = [ 10, 60 ];
	$GLOBALS['orph']  = null;
	$GLOBALS['rules'] = [];
	$GLOBALS['mine']  = [];
	$GLOBALS['klav_inc'] = false;
	$GLOBALS['klav_shell'] = false;
	$GLOBALS['styles'] = [];
	$GLOBALS['screen'] = null;
	DZE_Setup::forget();
}
/** Everything an enabled module asks for, answered. */
function setup_done(): void {
	blank();
	$GLOBALS['keys'] = [ 'anthropic' => 'sk-a', 'fal' => 'k-f', 'klaviyo' => 'pk-k' ];
	$GLOBALS['gmc_ok'] = true;
	$GLOBALS['langs'] = [ 'en' => [], 'fr' => [] ];
	$GLOBALS['klav_inc'] = true;
	$GLOBALS['klav_shell'] = true;
	DZE_Setup::forget();
}
// WPML, present. The row asks the TABLE for its languages, so a harness that
// answers two is a shop that runs in two.
class SitePress {}
/** One step, by id. */
function step( string $id ): array {
	foreach ( DZE_Setup::steps() as $s ) {
		if ( $id === $s['id'] ) { return $s; }
	}
	return [];
}

// THE MARKUP THE BROWSER GATE MEASURES, handed over before a single check has
// printed — a dump with the run's own output in front of it is a dump nothing
// can parse. Drawn on a fresh install, which is the state carrying the most
// rows and the longest sentences in them.
if ( in_array( '--dump-setup', (array) $argv, true ) ) {
	blank();
	ob_start();
	DZE_Setup::render_page();
	echo (string) ob_get_clean();
	exit( 0 );
}

echo "\nA fresh install: nothing is set up, and it says so\n";
blank();
$score = DZE_Setup::score();
// A FRESH INSTALL IS NOT A BROKEN ONE: WooCommerce is there and the scheduler
// runs. What it has none of is anything this plugin has to be TOLD.
ok( 'the shop itself is fine',          step( 'woocommerce' )['state'], 'done' );
ok( 'and the scheduler too',            step( 'cron' )['state'], 'done' );
ok( 'but the keys are all missing',
	[ step( 'anthropic' )['state'], step( 'fal' )['state'] ], [ 'todo', 'todo' ] );
ok( 'and it asks for several things',   $score['need'] > 3, true );
ok( 'each one named',                   count( $score['todo'] ), $score['need'] - $score['done'] );
ok( 'the Anthropic key is one of them', step( 'anthropic' )['state'], 'todo' );
ok( 'and it says which empty it is',    step( 'anthropic' )['said'], 'No key saved.' );
// A ROW SENDS YOU WHERE THE SETTING IS MADE — never to a second settings
// surface beside the real one.
ok( 'with the way to the setting',
	false !== strpos( step( 'anthropic' )['url'], 'page=dazont-ecom-ai&tab=general' ), true );

echo "\nA module that is off asks for nothing\n";
//
// "Une boutique qui n'utilise pas Klaviyo n'est pas une boutique à qui il
// manque une clé Klaviyo." This is what makes the notice honest enough to be
// left switched on for ever.
blank();
$was = DZE_Setup::score()['need'];
$GLOBALS['mods'] = [ 'klaviyo' => false, 'gmc' => false ];
DZE_Setup::forget();
$now = DZE_Setup::score();
ok( 'switching two off asks for less',  $now['need'] < $was, true );
ok( 'and the row says which it is',     step( 'gmc' )['state'], 'off' );
// IT IS STILL A ROW. A shop wondering where Klaviyo went needs a screen that
// answers, not one it has vanished from.
ok( 'the row is still on the screen',   step( 'gmc' )['label'], 'Google Merchant Center' );
// AND THE WAY BACK IS THE MODULES LIST, not the setting it cannot reach.
ob_start();
DZE_Setup::render_page();
$screen = (string) ob_get_clean();
ok( 'a row that is off offers Modules', false !== strpos( $screen, 'tab=modules' ), true );

echo "\nThe email lines are Klaviyo's own\n";
//
// `setup_items()` had answered this for months with nothing drawing it: the
// one call site was guarded by `class_exists()` on a class that no longer
// existed. A second list written here would be a second account of one thing.
blank();
$labels = array_map( static fn( array $s ): string => (string) $s['label'], DZE_Setup::steps() );
ok( 'the key line is the module\'s own',
	in_array( 'Add your Klaviyo API key', $labels, true ), true );
ok( 'and so is the frame',
	in_array( 'Take the header and footer from Klaviyo', $labels, true ), true );
// A RECOMMENDATION IS NOT A SHORTFALL. Counting "leave out your recent
// buyers" as missing is how a notice never goes away.
$todo = DZE_Setup::score()['todo'];
ok( 'a required line is counted',
	in_array( 'Add your Klaviyo API key', $todo, true ), true );
ok( 'a recommended one is not',
	in_array( 'Leave out your recent buyers', $todo, true ), false );
// A SUGGESTION DOES NOT WEAR THE WORD "TO DO". The figure at the top counts
// what is asked of this shop; a block chip counting something else is one
// screen saying two things.
ok( 'and it is marked as a suggestion',
	step( 'klaviyo_leave_out_your_recent_buyers' )['state'], 'idea' );

echo "\nThe notice, while something is missing\n";
blank();
ob_start();
DZE_Setup::notice();
$note = (string) ob_get_clean();
ok( 'it is there on a fresh install',   false !== strpos( $note, 'is not set up yet' ), true );
ok( 'it says how many are left',
	false !== strpos( $note, (string) count( DZE_Setup::score()['todo'] ) ), true );
ok( 'and names the first of them',
	false !== strpos( $note, DZE_Setup::score()['todo'][0] ), true );
ok( 'with the way to the screen',       false !== strpos( $note, 'page=dze-setup' ), true );
// NOT DISMISSIBLE: the thing it hides would still be missing.
ok( 'and no way to silence it',         false !== strpos( $note, 'is-dismissible' ), false );
// A SCREEN NEVER SENDS YOU LOOKING FOR THE SCREEN YOU ARE ON.
$GLOBALS['screen'] = (object) [ 'id' => 'dazont-ecom_page_dze-setup' ];
ob_start();
DZE_Setup::notice();
ok( 'never on the Setup page itself',   (string) ob_get_clean(), '' );
$GLOBALS['screen'] = null;
// IT GOES ON ITS OWN once the last one is done.
setup_done();
ob_start();
DZE_Setup::notice();
ok( 'everything done, no notice',       (string) ob_get_clean(), '' );
ok( 'and nothing is left to do',        DZE_Setup::score()['todo'], [] );
// AND NOBODY ELSE IS TOLD: it is a shop setting, and a subscriber has neither
// the page nor the power to act on it.
blank();
$GLOBALS['can'] = false;
ob_start();
DZE_Setup::notice();
ok( 'nor to somebody who cannot act',   (string) ob_get_clean(), '' );
$GLOBALS['can'] = true;

echo "\nThe page itself, drawn\n";
//
// A SCREEN THAT DIES TAKES THE PAGE WHITE, before any of our own error
// handling. Calling its helpers proves the helpers work.
blank();
ob_start();
DZE_Setup::render_page();
$screen = (string) ob_get_clean();
ok( 'the page is drawn',                false !== strpos( $screen, '<h1>Setup</h1>' ), true );
ok( 'it says how far it has got',       1 === preg_match( '/\d+ of \d+ set up/', $screen ), true );
ok( 'every block is there',             substr_count( $screen, 'class="dze-set dze-setup-block"' ), 3 );
// A BODY THAT MOVES TAKES ITS ASSETS WITH IT — asked for by the screen, never
// by a page hook somebody has to keep in step.
ok( 'and it asks for its own stylesheet', in_array( 'dze-content', $GLOBALS['styles'], true ), true );
// A BLOCK WITH SOMETHING LEFT IS OPEN, and one with nothing left is shut: the
// screen opens on what is missing rather than on everything at once.
ok( 'a block with work is open',        substr_count( $screen, 'dze-setup-block" id="dze-setup-keys" open' ), 1 );
setup_done();
ob_start();
DZE_Setup::render_page();
$screen = (string) ob_get_clean();
ok( 'everything done, the keys block is shut',
	false !== strpos( $screen, 'id="dze-setup-keys"><summary>' ), true );
ok( 'and the line says so',             false !== strpos( $screen, 'Everything this shop needs is set up' ), true );
// A CHIP IS SILENT WHEN IT HAS NOTHING TO SAY: "0 to do" reads as a problem.
ok( 'no nought on a finished block',    false !== strpos( $screen, '0 to do' ), false );
// A SETTING GIVEN IN wp-config CANNOT BE CHANGED FROM A SCREEN, so no button
// is offered for it: a control that cannot act is a control nobody trusts.
ok( 'a saved key still offers a change',
	false !== strpos( step( 'anthropic' )['do'], 'Change' ), true );

echo "\nWhat it refuses to say\n";
//
// A COPY OF THE SHOP IS NOT A SHORTFALL, and it is not silence either: it is
// exactly right, completely invisible, and the reason nothing was ever sent.
blank();
$GLOBALS['is_copy'] = true;
DZE_Setup::forget();
ok( 'a copy is said, not counted',      step( 'site' )['state'], 'unknown' );
ok( 'and it says what that means',
	false !== strpos( step( 'site' )['said'], 'nothing is written to Klaviyo' ), true );
ok( 'it is never a thing to do',
	in_array( 'This site', DZE_Setup::score()['todo'], true ), false );
// THE LINK GRAPH NEVER READ IS "not read yet", never "nothing is orphaned".
blank();
ok( 'never read, said as never read',   step( 'mesh' )['said'], 'The site has not been read yet.' );
$GLOBALS['orph'] = 213;
DZE_Setup::forget();
ok( 'read, it says what it found',
	false !== strpos( step( 'mesh' )['said'], '830 pages' ), true );
// A PROMPT LEFT AT ITS SHIPPED DEFAULT IS NOT A FAULT — a shop running on all
// of them is a working shop.
blank();
ok( 'no prompt written, still fine',    step( 'prompts' )['state'], 'done' );
ok( 'and it says how many are yours',
	false !== strpos( step( 'prompts' )['said'], '0 of 4 written here' ), true );
$GLOBALS['mine'] = [ 'a', 'c' ];
DZE_Setup::forget();
ok( 'two written, two counted',
	false !== strpos( step( 'prompts' )['said'], '2 of 4 written here' ), true );
// NO CEILING IS NOT A CEILING OF NOUGHT. "J'ai dépensé hier 40$ en génération
// d'images" is what an unset ceiling costs.
blank();
$GLOBALS['caps'] = [ 0, 60 ];
DZE_Setup::forget();
ok( 'a ceiling at nought is flagged',   step( 'ceilings' )['state'], 'todo' );
ok( 'and said in the words it means',
	false !== strpos( step( 'ceilings' )['said'], 'no ceiling at all' ), true );
// AND IT IS ASKED FOR, because nought is no guard at all. Both ship at a real
// figure, so this is silent on every shop that has not touched them.
ok( 'and it is asked for',
	in_array( 'Image ceilings', DZE_Setup::score()['todo'], true ), true );
blank();
ok( 'shipped figures say nothing',      step( 'ceilings' )['state'], 'done' );

echo "\nThe same checklist, inline\n";
//
// "Une fonction qui a besoin de quelque chose réglé EN DEHORS du plugin le dit
// LÀ OÙ le réglage se fait." The promotion screen has been asking for this
// with nothing answering.
blank();
ob_start();
DZE_Setup::render( 'Emails are not set up yet', DZE_Klaviyo::setup_items() );
$inline = (string) ob_get_clean();
ok( 'it draws where it was asked for',  false !== strpos( $inline, 'Emails are not set up yet' ), true );
ok( 'naming what is missing',           false !== strpos( $inline, 'Add your Klaviyo API key' ), true );
ok( 'with the way to each one',         false !== strpos( $inline, 'href="u1"' ), true );
ok( 'and the way to the whole screen',  false !== strpos( $inline, 'page=dze-setup' ), true );
// A CHECKLIST WITH NOTHING LEFT ON IT IS A BOX IN THE WAY.
setup_done();
ob_start();
DZE_Setup::render( 'Emails', [
	[ 'label' => 'Done thing', 'url' => 'u', 'need' => true, 'done' => true, 'note' => '' ],
] );
ok( 'nothing left, nothing drawn',      (string) ob_get_clean(), '' );

echo "\nThe menu entry\n";
blank();
$GLOBALS['menu'] = [];
DZE_Setup::register_menu();
ok( 'one entry, under Dazont Ecom',     $GLOBALS['menu'][0][0] ?? '', 'dazont-ecom' );
ok( 'called Setup',                     $GLOBALS['menu'][0][1] ?? '', 'Setup' );
ok( 'and it is the page we draw',       $GLOBALS['menu'][0][4] ?? '', DZE_Setup::MENU_SLUG );
ok( 'for somebody who can act',         $GLOBALS['menu'][0][3] ?? '', 'manage_woocommerce' );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
