<?php
/**
 * "What this prompt is sent with" — the block itself, rendered.
 *
 * Run before every release:  php tools/test-prompt-block.php dazont-ecom
 *
 * check-prompts.php proves the block is CALLED beside every prompt. That is
 * a grep, and a grep has never rendered anything: DZE_Klaviyo::sample_body()
 * was called from one settings tab and nowhere else, and that tab was a white
 * page for six versions while every other screen worked. This block was added
 * to eight screens at once, so it runs here — in both its states, with the
 * escaping that stands between a stored answer and the admin's own page.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'DAY_IN_SECONDS', 86400 );

function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return $n > 1 ? $b : $a; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr__( $s, $d = '' ) { return $s; }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function human_time_diff( $from, $to = 0 ) { return max( 1, (int) round( ( ( $to ?: time() ) - $from ) / 60 ) ) . ' mins'; }
function add_query_arg( $args, $url = '' ) { return $url . '?' . http_build_query( (array) $args ); }
function admin_url( $p = '' ) { return 'http://shop.test/wp-admin/' . $p; }
function add_action() {} function apply_filters( $t, $v = null ) { return $v; }
function wp_create_nonce( $a = '' ) { return 'n'; }
function wp_style_is( ...$a ) { return true; }
function wp_enqueue_style( ...$a ) {}
function did_action( $a ) { return 0; }

$GLOBALS['dze_opts'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['dze_opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['dze_opts'][ $k ] = $v; return true; }

/** Enough of the settings page for the block's link to have somewhere to go. */
class DZE_Marketing_Ai { const MENU_SLUG = 'dazont-ecom-ai'; }

/**
 * A module that owns a prompt, and appends words to it.
 *
 * "Aucune autre instruction cachée ne devrait exister." An image request
 * carries several photographs and something has to name them, which the
 * prompt cannot do — it does not know how many the run attaches. That note is
 * legitimate; it being invisible was not. So whatever a module appends, this
 * block prints it word for word.
 */
class DZE_Category_Content {
	public static function prompt_data( string $id ): array {
		return [ 'The category and what it holds.' ];
	}
	public static function prompt_note( string $id ): string {
		return "IMAGES 1 TO 3 ARE ONE SINGLE PRODUCT.\n<b>not markup</b>";
	}
	public static function prompt(): string { return ''; }
	public static function default_prompt(): string { return ''; }
}

require __DIR__ . '/../' . $dir . '/includes/class-ai-usage.php';
require __DIR__ . '/../' . $dir . '/includes/class-prompts.php';

$fails = 0;
$ran   = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok    %s\n", $what ); return; }
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
function block( string $id ): string {
	ob_start();
	DZE_Prompts::the_data( $id );
	return (string) ob_get_clean();
}

echo "A prompt that has never run\n";
$html = block( 'cat_desc' );
ok( 'the block is drawn all the same',  false !== strpos( $html, 'What this prompt is sent with' ), true );
ok( 'and says so plainly',              false !== strpos( $html, 'never run yet' ), true );
ok( 'with what to do about it',         false !== strpos( $html, 'Run this prompt once' ), true );
ok( 'shut until it is opened',          false !== strpos( $html, '<details' ), true );
// No empty Sent/Answer boxes — asked of those two boxes by name, because the
// panel now also prints what the plugin APPENDS to this prompt, which is
// worth reading before it has ever run and is not a call that never happened.
ok( 'and no empty Sent block',          false !== strpos( $html, '>Sent<' ), false );
ok( 'nor an empty Answer',              false !== strpos( $html, '>Answer<' ), false );

echo "A prompt that has run\n";
// Filed the way the trace files it: under the prompt found in what was sent.
DZE_Ai_Usage::trace( 'anthropic', 'claude-test-1', "Write the category description.\nThe category: Balaclavas.", '{"body":"Ein Text"}', 4.2 );
$GLOBALS['dze_opts']['dze_ai_last'] = [ 'cat_desc' => [
	't' => time() - 600, 'unit' => 'cat_desc', 'provider' => 'anthropic',
	'model' => 'claude-test-1', 'secs' => 4.2,
	'sent' => "Write the category description.\nThe category: Balaclavas.",
	'got'  => '{"body":"Ein Text"}',
] ];
$html = block( 'cat_desc' );
ok( 'the model is named on the summary', false !== strpos( $html, 'claude-test-1' ), true );
ok( 'with how long ago and how long it took',
	false !== strpos( $html, '10 mins ago' ) && false !== strpos( $html, '4.2s' ), true );
ok( 'what went out is shown whole',     false !== strpos( $html, 'The category: Balaclavas.' ), true );
ok( 'and what came back',               false !== strpos( $html, 'Ein Text' ), true );

echo "What the plugin appends is on the screen, word for word\n";
// A summary is not the text. This panel used to list "the product
// photographs, as real images" while several hundred characters of note went
// out under it, and the owner found them in a trace: "tu as encore ajouté des
// instructions custom par dessus le prompt ?"
$html = block( 'cat_desc' );
ok( 'what travels with it is still said in a sentence',
	false !== strpos( $html, 'The category and what it holds.' ), true );
ok( 'and what is appended is shown as it is sent',
	false !== strpos( $html, 'IMAGES 1 TO 3 ARE ONE SINGLE PRODUCT.' ), true );
ok( 'named for what it is',
	false !== strpos( $html, 'Appended after your instructions, word for word' ), true );
ok( 'and it says why the prompt cannot do it itself',
	false !== strpos( $html, 'do not know how many the run attaches' ), true );
// It is TEXT on that page, like everything else read back into it.
ok( 'the note reaches the page as text',  false !== strpos( $html, '&lt;b&gt;not markup&lt;/b&gt;' ), true );
ok( 'never as markup',                    false !== strpos( $html, '<b>not markup</b>' ), false );
// A prompt nothing is appended to prints no such block, rather than an empty
// heading over nothing.
ok( 'a prompt with no note has no block',
	false !== strpos( block( 'translate' ), 'Appended after your instructions' ), false );

echo "What a stored answer must never do to the page\n";
// The answer is a model's text and the prompt is the shop's: both reach this
// page as content, and neither may reach it as markup.
$GLOBALS['dze_opts']['dze_ai_last']['cat_desc']['got'] = '<script>alert(1)</script>';
$GLOBALS['dze_opts']['dze_ai_last']['cat_desc']['sent'] = '<img src=x onerror=1>';
$html = block( 'cat_desc' );
ok( 'no script tag reaches the admin',  false !== strpos( $html, '<script>alert' ), false );
ok( 'it is printed as text instead',    false !== strpos( $html, '&lt;script&gt;alert' ), true );
ok( 'and so is the prompt',             false !== strpos( $html, '&lt;img src=x' ), true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
