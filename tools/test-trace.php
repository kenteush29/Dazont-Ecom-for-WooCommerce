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
// The settings view is a shipped FILE, and the section is rendered by drawing
// it: a harness that cannot find it can only ever test the helpers around it.
define( 'DZE_DIR', __DIR__ . '/../' . $dir . '/' );

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
// The image ceilings are counted in transients, so a fake that always answers
// "nothing" cannot ever reach one — and the message they produce is the whole
// point of the checks below.
function get_transient( $k ) { return $GLOBALS['dze_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['dze_transients'][ $k ] = $v; return true; }
function wp_next_scheduled( $h ) { return 0; }
function add_query_arg( $k, $v = null, $u = '' ) { return 'http://shop.test/usage?' . ( is_array( $k ) ? http_build_query( $k ) : $k . '=' . rawurlencode( (string) $v ) ); }
function admin_url( $p = '' ) { return 'http://shop.test/wp-admin/' . $p; }
function remove_query_arg( $k, $u = '' ) { return 'http://shop.test/usage'; }
function wp_schedule_event() {}
function add_shortcode( ...$a ) {}
function add_menu_page( ...$a ) { return ''; }
function add_submenu_page( ...$a ) { return ''; }
function remove_submenu_page( ...$a ) {}
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function get_locale() { return 'en_US'; }
function get_bloginfo( $w = '' ) { return 'Kula'; }
// Enough of WordPress to DRAW a settings section. Calling the helper proves the
// helper works; only rendering proves the screen asks for it, and which
// section it lands in.
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function esc_textarea( $s ) { return esc_html( $s ); }
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $g ) . '" />'; }
function submit_button( $t = 'Save Changes', ...$a ) { echo '<button type="submit">' . esc_html( $t ) . '</button>'; }
function selected( $a, $b = true, $e = true ) { return (string) $a === (string) $b ? ' selected=\'selected\'' : ''; }
function checked( $a, $b = true, $e = true ) { return (string) $a === (string) $b ? ' checked=\'checked\'' : ''; }
function current_user_can( ...$a ) { return true; }
function wp_die( $m = '' ) { throw new RuntimeException( (string) $m ); }
class DZE_Modules {
	public static function enabled( $id ) { return ! in_array( $id, (array) ( $GLOBALS['dze_off'] ?? [] ), true ); }
}
class DZE_Api_Keys {
	public static function status_html( $which, $key = '', $locked = false ) { return '<span class="dze-keystate">key</span>'; }
}
class DZE_Content {
	public static function instance() { return new self(); }
	public static function fal_image_cost() { return 0.08; }
	public function render_key_field() { echo '<p class="dze-falkey">fal.ai key</p>'; }
}

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
// The model menu is fetched from Anthropic and cached. A gate must not reach
// the network: the list comes back empty and the saved model stays selectable,
// which is exactly what a shop with no answer yet sees.
function wp_remote_get( $url, $args = [] ) { return [ 'response' => [ 'code' => 200 ], 'body' => '{"data":[]}' ]; }
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

echo "Every prompt keeps its OWN last call\n";
// The trace holds a dozen calls for the whole plugin, so the prompt being
// read is usually not in it — "data reçue par chaque prompt non visible".
// A call is filed under the prompt it was built from, found in the text that
// went out, so no call site has to declare anything and a prompt added
// tomorrow is recognised the first time it runs.
class DZE_Prompts {
	public static $texts = [
		'cat_desc'    => 'Write the category description for this shop, in its own words and at length.',
		'promo_email' => 'Write the email that announces this promotion — the words AND the layout.',
	];
	public static function ids_in( $sent ) {
		$out = [];
		foreach ( self::$texts as $id => $text ) {
			if ( false !== strpos( (string) $sent, substr( $text, 0, 60 ) ) ) { $out[] = $id; }
		}
		return $out;
	}
}
$GLOBALS['dze_http'] = [ 'code' => 200, 'body' => json_encode( [ 'content' => [ [ 'type' => 'text', 'text' => 'A description.' ] ], 'usage' => [] ] ) ];
DZE_Marketing_Ai::complete( 'S', DZE_Prompts::$texts['cat_desc'] . "\nThe category: Balaclavas." );
$one = DZE_Ai_Usage::last_for( 'cat_desc' );
ok( 'the prompt has its own call',      false !== strpos( (string) ( $one['sent'] ?? '' ), 'The category: Balaclavas.' ), true );
ok( 'with the answer beside it',        $one['got'] ?? '', 'A description.' );
ok( 'and a prompt never run has none',  DZE_Ai_Usage::last_for( 'promo_email' ), [] );

// Twenty other calls must not push it out: the roll-off is per prompt, not
// shared with the twelve-row trace.
for ( $i = 0; $i < 20; $i++ ) { DZE_Marketing_Ai::complete( 'S', 'unrelated ' . $i ); }
ok( 'and it survives the calls after it',
	false !== strpos( (string) ( DZE_Ai_Usage::last_for( 'cat_desc' )['sent'] ?? '' ), 'The category: Balaclavas.' ), true );
ok( 'never autoloaded either',          $GLOBALS['dze_autoload']['dze_ai_last'] ?? null, false );

// Run again, and it is the LAST call that is kept.
DZE_Marketing_Ai::complete( 'S', DZE_Prompts::$texts['cat_desc'] . "\nThe category: Pouches." );
ok( 'the newest replaces the older',
	false !== strpos( (string) ( DZE_Ai_Usage::last_for( 'cat_desc' )['sent'] ?? '' ), 'The category: Pouches.' ), true );


// =============================================================================
// WHAT EACH MODEL COST — a reading of what is already stored
//
// "On a un registre des appels IA filtrable par modèle ?" There was one and
// there was not: every call has carried its model since the daily split was
// added, and the only way to read the figures was to hover thirty bars one at
// a time and add up. The trace above shows the model of the last twelve calls,
// which is a debugging tool and not an account of a month.
//
// So nothing new is recorded for this, and that is the thing to hold: the
// report is a READING. It is exercised through real complete() calls with only
// the transport stubbed, exactly like the trace beside it.
// =============================================================================
echo "\nWhat each model cost\n";
$GLOBALS['dze_opts']['dze_ai_usage'] = [];
$GLOBALS['dze_http'] = [ 'code' => 200, 'body' => json_encode( [
	'content' => [ [ 'type' => 'text', 'text' => 'ok' ] ],
	'usage'   => [ 'input_tokens' => 1000, 'output_tokens' => 500 ],
] ) ];
DZE_Marketing_Ai::complete( 'S', 'one',   'claude-opus-5' );
DZE_Marketing_Ai::complete( 'S', 'two',   'claude-opus-5' );
DZE_Marketing_Ai::complete( 'S', 'three', 'claude-haiku-4-5-20251001' );
// An image is billed per picture: a real row with no tokens at all.
DZE_Ai_Usage::record( 'fal', 0, 0, 'nano-banana-2', 0.08 );

$rep = DZE_Ai_Usage::model_report();
$by  = [];
foreach ( $rep as $r ) { $by[ $r['model'] ] = $r; }
ok( 'every model used is a line of its own', count( $rep ), 3 );
ok( 'and the expensive one is first',        $rep[0]['model'] ?? '', 'claude-opus-5' );
ok( 'two calls on it are counted as two',    $by['claude-opus-5']['calls'] ?? 0, 2 );
ok( 'with the tokens of both',               [ $by['claude-opus-5']['in'] ?? 0, $by['claude-opus-5']['out'] ?? 0 ], [ 2000, 1000 ] );
ok( 'at that model own price',               round( (float) ( $by['claude-opus-5']['cost'] ?? 0 ), 4 ), 0.105 );
// The cheap model is NOT folded into the expensive one: same provider, same
// month, two prices — which is the whole question being asked.
ok( 'the cheap model is its own line',       round( (float) ( $by['claude-haiku-4-5-20251001']['cost'] ?? 0 ), 4 ), 0.0035 );
ok( 'an image model is there too',           round( (float) ( $by['nano-banana-2']['cost'] ?? 0 ), 4 ), 0.08 );
ok( 'billed flat, it carries no tokens',     [ $by['nano-banana-2']['in'] ?? -1, $by['nano-banana-2']['out'] ?? -1 ], [ 0, 0 ] );
// THE SHARES MUST ADD UP TO THE MONTH, or the reader is left working out which
// figure to believe.
$sum = 0.0;
foreach ( $rep as $r ) { $sum += (float) $r['cost']; }
ok( 'the models account for the whole month', round( $sum, 4 ), round( DZE_Ai_Usage::month_total(), 4 ) );
// A cost that is real but tiny reads "<1%", never "0%": a figure saying the
// opposite of what it means.
ok( 'a real but tiny share is not nought',   DZE_Ai_Usage::share_said( 0.0004, 1.0 ), '<1%' );

echo "\nAnd it is on the screen, drawn by the screen\n";
ob_start(); DZE_Ai_Usage::render_models(); $dze_html = (string) ob_get_clean();
ok( 'the table names the model',             false !== strpos( $dze_html, 'claude-opus-5' ), true );
ok( 'and the cheap one beside it',           false !== strpos( $dze_html, 'claude-haiku-4-5-20251001' ), true );
ok( 'and what it cost',                      false !== strpos( $dze_html, '$0.11' ), true );
ok( 'an image model shows a dash, not a nought',
	false !== strpos( $dze_html, '—' ), true );
ok( 'and the month closes the table',        false !== strpos( $dze_html, '100%' ), true );

// AND THE SCREEN ASKS FOR IT. Calling render_models() proves the table works
// and nothing at all about whether the usage screen shows it — which is how a
// block added to a screen can ship never executed. So the whole screen is
// drawn, and the heading looked for in what it printed.
ob_start(); DZE_Ai_Usage::render_graph(); $dze_screen = (string) ob_get_clean();
ok( 'the usage screen draws the model table',
	false !== strpos( $dze_screen, 'What each model costs' ), true );
ok( 'with the models in it',                 false !== strpos( $dze_screen, 'nano-banana-2' ), true );
ok( 'beside what each kind of work costs',
	false !== strpos( $dze_screen, 'What each kind of work costs' ), true );

// WHICH EMPTY IT IS. A month that really cost money and a month recorded before
// the split existed are two different answers; a blank table says neither.
$GLOBALS['dze_opts']['dze_ai_usage'] = [
	'2025-01' => [ 'anthropic' => [ 'calls' => 3, 'in' => 0, 'out' => 0, 'cost' => 4.2 ] ],
];
ob_start(); DZE_Ai_Usage::render_models( '2025-01' ); $dze_old = (string) ob_get_clean();
ok( 'a month older than the breakdown says so',
	false !== strpos( $dze_old, 'before the per-model breakdown existed' ), true );
ok( 'and still states what it cost',         false !== strpos( $dze_old, '4.20' ), true );
ob_start(); DZE_Ai_Usage::render_models( '2024-06' ); $dze_none = (string) ob_get_clean();
ok( 'and a month that spent nothing says that instead',
	false !== strpos( $dze_none, 'Nothing spent in this month' ), true );


echo "\nA MESSAGE THAT NAMES A SETTINGS TAB IS A WAY TO IT\n";
// "Ici tu vas aussi ajouter directement le lien vers settings. J'ai atteint la
// limite, mais c'est normal." The ceiling message was right and left the shop
// to go and find the page it named. The words and the address now come from
// one list, and what is gated here is that they cannot drift apart.
$dze_tabs = DZE_Marketing_Ai::tab_links();
ok( 'every tab has an address',         count( $dze_tabs ) > 5, true );
ok( 'and it points at that tab',
	false !== strpos( (string) ( $dze_tabs['General'] ?? '' ), 'tab=general' ), true );
ok( 'the health tab too',
	false !== strpos( (string) ( $dze_tabs['Health'] ?? '' ), 'tab=health' ), true );
ok( 'and each one at the settings page',
	count( array_filter( $dze_tabs, static fn( $u ) => false !== strpos( (string) $u, DZE_Marketing_Ai::MENU_SLUG ) ) ),
	count( $dze_tabs ) );

// THE CEILING MESSAGE THE SHOP ACTUALLY READ. Both of them name a tab, and the
// name they use has to be one this list can open.
$GLOBALS['dze_transients']['dze_fal_h_' . gmdate( 'YmdH' )] = 999;
$dze_msg = DZE_Ai_Usage::fal_blocked( 0 );
ok( 'the shop ceiling says which ceiling it is',
	false !== strpos( $dze_msg, 'which is the ceiling' ), true );
ok( 'and names a tab that can be opened',
	isset( $dze_tabs[ trim( (string) ( explode( '.', explode( 'Settings → ', $dze_msg )[1] ?? '' )[0] ?? '' ) ) ] ), true );

// EVERY SENTENCE IN THE PLUGIN, not only these two. A name that no longer
// matches a tab is a link that will never be drawn and a reader sent to a page
// that was renamed — the text form of the fault `test-diagnostic.php` catches
// when a row links to a settings page. Comments are not sentences: the source
// is TOKENISED, so only what is really a string is read.
$dze_names = array_keys( $dze_tabs );
usort( $dze_names, static fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
$dze_bad = [];
$dze_all = [];
$dze_it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/../' . $dir ) );
foreach ( $dze_it as $dze_f ) {
	if ( 'php' === strtolower( (string) $dze_f->getExtension() ) ) {
		$dze_all[] = (string) $dze_f->getPathname();
	}
}
sort( $dze_all );
foreach ( $dze_all as $dze_file ) {
	foreach ( token_get_all( (string) file_get_contents( $dze_file ) ) as $dze_tok ) {
		if ( ! is_array( $dze_tok ) || ! in_array( $dze_tok[0], [ T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ], true ) ) {
			continue;
		}
		$dze_str = (string) $dze_tok[1];
		$dze_at  = 0;
		while ( false !== ( $dze_at = strpos( $dze_str, 'Settings → ', $dze_at ) ) ) {
			$dze_rest = substr( $dze_str, $dze_at + strlen( 'Settings → ' ) );
			// "Klaviyo → Settings → API keys" names another product's screen.
			$dze_own  = ! ( $dze_at >= 4 && '→ ' === substr( $dze_str, $dze_at - 4, 4 ) );
			$dze_hit  = false;
			foreach ( $dze_names as $dze_name ) {
				if ( 0 === strpos( $dze_rest, $dze_name ) ) { $dze_hit = true; break; }
			}
			if ( $dze_own && ! $dze_hit ) {
				$dze_bad[] = basename( $dze_file ) . ': Settings → ' . substr( $dze_rest, 0, 30 );
			}
			$dze_at += 11;
		}
	}
}
ok( 'no sentence names a settings tab that is not there', $dze_bad, [] );


echo "\nTHE IMAGE CEILINGS BELONG TO THE PROVIDER THAT MAKES THE IMAGES\n";
// "Images per product, per hour / Images for the whole shop, per hour — sont
// dans la mauvaise section, Claude. Ça concerne pourtant FAL.AI." They were in
// the Anthropic table, under the Claude model and the monthly budget, counting
// pictures Anthropic never makes.
$dze_mai = DZE_Marketing_Ai::instance();
ob_start(); $dze_mai->render_settings_section( 'general' ); $dze_gen = (string) ob_get_clean();
ob_start(); $dze_mai->render_settings_section( 'fal' );     $dze_fal = (string) ob_get_clean();
ok( 'the Claude section no longer holds them',
	false !== strpos( $dze_gen, 'fal_cap_post' ), false );
ok( 'nor the shop-wide one',            false !== strpos( $dze_gen, 'fal_cap_hour' ), false );
ok( 'and it still holds the model and the budget',
	false !== strpos( $dze_gen, 'budget_month' ) && false !== strpos( $dze_gen, '[model]' ), true );
ok( 'the fal section holds both ceilings',
	false !== strpos( $dze_fal, 'fal_cap_post' ) && false !== strpos( $dze_fal, 'fal_cap_hour' ), true );
// ITS OWN FORM, saying which section it is: a form that posts a key its own
// sanitizer does not write is a setting saved by nobody.
ok( 'and says which section it is',
	false !== strpos( $dze_fal, 'value="fal"' ), true );
ok( 'with a way to save it',            false !== strpos( $dze_fal, '<button type="submit"' ), true );
// AND THE FIGURE IT STATES IS REAL. The sentence read "$0.00 in that hour"
// for both of them: `$dze_img_price` was assigned from itself and defined
// nowhere, so the cost that justifies the ceiling was nought.
ok( 'the cost of a full hour is a real figure',
	false !== strpos( $dze_fal, '$0.00' ), false );
ok( 'and it is the ceiling times the price of an image',
	false !== strpos( $dze_fal, '$' . number_format_i18n( DZE_Ai_Usage::fal_hour_cap() * DZE_Content::fal_image_cost(), 2 ) ), true );

// THE SANITIZER WRITES THEM, and that form is the only one that does.
$dze_before = [ 'fal_cap_post' => 10, 'fal_cap_hour' => 60, 'budget_month' => 25.0 ];
$GLOBALS['dze_opts'][ DZE_Marketing_Ai::OPT_SETTINGS ] = $dze_before;
$dze_saved = $dze_mai->sanitize_settings( [ 'section' => 'fal', 'fal_cap_post' => 4, 'fal_cap_hour' => 20 ] );
ok( 'saving the fal section writes the ceilings',
	[ (int) $dze_saved['fal_cap_post'], (int) $dze_saved['fal_cap_hour'] ], [ 4, 20 ] );
ok( 'and leaves the budget where it was', (float) $dze_saved['budget_month'], 25.0 );
// A form that never carried them cannot write them — the trap this plugin has
// paid for more than once.
$dze_other = $dze_mai->sanitize_settings( [ 'section' => 'general', 'model' => 'claude-x', 'budget_month' => 30 ] );
ok( 'and another form leaves them alone',
	[ (int) $dze_other['fal_cap_post'], (int) $dze_other['fal_cap_hour'] ], [ 10, 60 ] );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
