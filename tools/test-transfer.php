<?php
/**
 * Carrying one shop's prompts and criteria to another.
 *
 * Run before every release:  php tools/test-transfer.php dazont-ecom
 *
 * Everything this plugin writes is decided by two things the owner spent
 * months on, and both of them were trapped on the site they were typed into.
 * What is tested here is not that a JSON file comes out — that is a grep.
 * It is the four things that go wrong when settings travel:
 *
 *   - a bundle that is not one is REFUSED, and says why, before a single
 *     write; a paste box that writes on the press is a paste box nobody dares
 *     use;
 *   - what a bundle holds is SAID before anything is replaced, in the words
 *     each group is named by;
 *   - a group not ticked is not touched;
 *   - and the writing goes through the module that owns the data, so it
 *     cannot be quietly undone by a sanitizer shaped for form input — the
 *     trap this plugin has already been caught by.
 *
 * NO API KEY TRAVELS. That is the one thing a bundle must never carry: it is
 * pasted into chat windows, into tickets, into a box on somebody else's
 * screen. Asserted here, on the real bundle, with the keys set.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'DZE_VERSION', '4.316.0' );
define( 'DZE_URL', 'http://kula.test/wp-content/plugins/dazont-ecom/' );

function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_textarea( $s ) { return esc_html( $s ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_url( $s ) { return (string) $s; }
function home_url( $p = '/' ) { return 'https://kula.test' . $p; }
function admin_url( $p = '' ) { return 'https://kula.test/wp-admin/' . $p; }
function current_time( $t ) { return '2026-09-08 22:00:00'; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function is_admin() { return true; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function remove_filter( ...$a ) {}
function apply_filters( $t, $v = null, ...$a ) { return $v; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }

$GLOBALS['opts'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }

// --- The two modules that own the data ------------------------------------
// Each of them answers the way the real one does: a reader, and ONE writer
// that the transfer has to go through.
class DZE_Content {
	public static array $wrote = [];
	public static function registry(): array { return $GLOBALS['registry'] ?? []; }
	public static function write_setting( string $key, $value ): void {
		self::$wrote[] = $key;
		if ( ! empty( $GLOBALS['content_refuses'] ) ) {
			throw new RuntimeException( 'The setting could not be saved.' );
		}
		$GLOBALS['registry'] = $value;
	}
	public static function set_prompt_for( string $id, string $t ): bool { return true; }
	public static function default_prompt_for( string $id ): string { return ''; }
}
class DZE_Diagnostic {
	public static int $written = 0;
	public static function rows(): array { return $GLOBALS['rows'] ?? []; }
	public static function write_rows( array $rows ): int {
		if ( ! $rows ) { throw new RuntimeException( 'None of those criteria could be read.' ); }
		$GLOBALS['rows'] = $rows;
		self::$written++;
		return count( $rows );
	}
}
/** The prompt registry, answering for the handful of prompts a shop edits. */
class DZE_Prompts {
	public static array $saved = [];
	public static function catalog(): array {
		$out = [];
		foreach ( array_keys( $GLOBALS['single'] ?? [] ) as $id ) { $out[ $id ] = [ 'label' => $id ]; }
		// A product prompt, which is carried whole in the registry and must
		// NOT be carried a second time as a line of text.
		$out['content_post_content'] = [ 'label' => 'Description' ];
		return $out;
	}
	public static function text_for( string $id ): string {
		return (string) ( $GLOBALS['single'][ $id ] ?? ( 'content_post_content' === $id ? 'the product description prompt' : '' ) );
	}
	public static function save_text( string $id, string $text ): bool {
		if ( 'unknown' === $id ) { return false; }
		self::$saved[ $id ] = $text;
		return true;
	}
}

$GLOBALS['registry'] = [
	[ 'id' => 'post_content', 'type' => 'text', 'prompt' => 'Write the description.', 'output' => 'post_content', 'tokens' => 900 ],
	[ 'id' => 'detail', 'type' => 'image', 'prompt' => 'A close detail.', 'target' => 'gallery' ],
];
$GLOBALS['single'] = [ 'cat_desc' => 'Write the category.', 'cat_links' => 'Add the links.' ];
$GLOBALS['rows']   = [
	[ 'id' => 'gal3', 'scope' => 'product', 'field' => 'product.gallery', 'test' => 'lt', 'value' => 3, 'on' => 1 ],
];
$GLOBALS['opts']['dze_prompt_defaults'] = [ 'cat_desc' => 'mine' ];
// The keys, set, so their absence from the bundle is a real answer.
$GLOBALS['opts']['dze_content_settings'] = [ 'fal_key' => 'fal-SECRET-KEY' ];
$GLOBALS['opts']['dze_marketing_ai'] = [ 'api_key' => 'sk-ant-SECRET-KEY' ];

require __DIR__ . '/../' . $dir . '/includes/class-transfer.php';

$ran = 0; $fails = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok   %s\n", $what ); return; }
	$fails++;
	printf( "  WRONG %s\n       got  %s\n       want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}

echo "\nWhat leaves this shop\n";
$out = DZE_Transfer::bundle();
ok( 'it says what it is',               $out['dze'], 'dazont-ecom-transfer' );
ok( 'and where it came from',           $out['from'], 'https://kula.test/' );
ok( 'the product prompts travel whole',
	count( $out['groups']['prompts']['registry'] ), 2 );
ok( 'with everything that decides what one sends',
	(int) ( $out['groups']['prompts']['registry'][0]['tokens'] ?? 0 ), 900 );
ok( 'the other prompts travel as text',
	array_keys( $out['groups']['prompts']['single'] ), [ 'cat_desc', 'cat_links' ] );
// A PRODUCT PROMPT IS CARRIED ONCE. Its text is one field of a registry row;
// carrying it again as a line of text is two answers to one question, and the
// second one lands on a shop that sends it nothing.
ok( 'and never a second time as a line',
	isset( $out['groups']['prompts']['single']['content_post_content'] ), false );
ok( 'the criteria travel too',          count( $out['groups']['diagnostic']['rows'] ), 1 );
ok( 'and which prompts the shop made its own default',
	$out['groups']['prompts']['defaults'], [ 'cat_desc' => 'mine' ] );

echo "\nNO KEY TRAVELS\n";
// A bundle is pasted into chat windows and tickets. This is the one thing it
// must never carry, asserted on the real text with the keys set.
$text = DZE_Transfer::bundle_text();
ok( 'no fal key is in it',              false !== strpos( $text, 'fal-SECRET-KEY' ), false );
ok( 'no writing key either',            false !== strpos( $text, 'sk-ant-SECRET-KEY' ), false );
ok( 'and nothing that reads as a key',  (bool) preg_match( '/"[a-z_]*key[a-z_]*"\s*:/i', $text ), false );

echo "\nWhat is pasted is READ before anything is written\n";
$said = static function ( callable $fn ): string {
	try { $fn(); } catch ( Throwable $e ) { return $e->getMessage(); }
	return '';
};
ok( 'a scrap of text is refused',
	$said( static fn() => DZE_Transfer::read( 'hello' ) ),
	'That is not a settings file — paste the whole box, from the first { to the last }.' );
ok( 'so is somebody else\'s JSON',
	$said( static fn() => DZE_Transfer::read( '{"hello":1}' ) ),
	'That file was not made by this plugin.' );
ok( 'and a newer plugin\'s file, by name',
	$said( static fn() => DZE_Transfer::read( '{"dze":"dazont-ecom-transfer","v":99,"groups":{}}' ) ),
	'That file comes from a newer version of the plugin. Update this site first.' );
ok( 'an empty bundle is not one either',
	$said( static fn() => DZE_Transfer::read( '{"dze":"dazont-ecom-transfer","v":1,"groups":{}}' ) ),
	'That file holds nothing this site can use.' );
// AND NOT ONE OF THOSE WROTE ANYTHING.
ok( 'nothing was written by reading',   DZE_Content::$wrote, [] );
ok( 'nor any criterion',                DZE_Diagnostic::$written, 0 );

// AND A BAD BUNDLE HANDED STRAIGHT TO THE WRITING IS STILL REFUSED. The read
// is not a step somebody does first; it is the first thing the write does.
$GLOBALS['registry_was'] = $GLOBALS['registry'];
ok( 'a scrap of text writes nothing',
	$said( static fn() => DZE_Transfer::apply( 'hello', [ 'prompts', 'diagnostic' ] ) ),
	'That is not a settings file — paste the whole box, from the first { to the last }.' );
ok( 'and the registry is untouched',    $GLOBALS['registry'], $GLOBALS['registry_was'] );
ok( 'and no criterion was written',     DZE_Diagnostic::$written, 0 );

echo "\nAnd it says what it holds\n";
$read = DZE_Transfer::read( $text );
ok( 'where it came from',               $read['from'], 'https://kula.test/' );
ok( 'when it was taken',                $read['at'], '2026-09-08 22:00:00' );
ok( 'and which plugin wrote it',        $read['plugin'], '4.316.0' );
ok( 'both groups are named',            array_keys( $read['holds'] ), [ 'prompts', 'diagnostic' ] );
ok( 'the prompts are counted',          $read['holds']['prompts']['said'], '2 product prompts, 2 others' );
ok( 'and the criteria said as criteria', $read['holds']['diagnostic']['said'], '1 criterion' );

echo "\nOnly what is ticked is replaced\n";
// The other shop's settings, which must land whole and land nowhere else.
$other = json_decode( $text, true );
$other['from'] = 'https://other.test/';
$other['groups']['prompts']['registry'][0]['prompt'] = 'A different description prompt.';
$other['groups']['prompts']['single']['cat_desc']    = 'A different category prompt.';
$other['groups']['diagnostic']['rows'][] = [ 'id' => 'desc', 'scope' => 'product', 'field' => 'product.description', 'test' => 'lt', 'value' => 120, 'on' => 1 ];
$json = (string) json_encode( $other );

$before = $GLOBALS['rows'];
$done   = DZE_Transfer::apply( $json, [ 'prompts' ] );
ok( 'the prompts were replaced',        isset( $done['prompts'] ), true );
ok( 'and it says what happened',        $done['prompts'], '2 product prompts and 2 others replaced.' );
ok( 'the registry now holds the other shop\'s',
	(string) $GLOBALS['registry'][0]['prompt'], 'A different description prompt.' );
ok( 'and so does a single prompt',      DZE_Prompts::$saved['cat_desc'] ?? '', 'A different category prompt.' );
// A GROUP NOT TICKED IS NOT TOUCHED. Replacing everything because one thing
// was asked for is how a shop loses the half it meant to keep.
ok( 'the criteria were left alone',     $GLOBALS['rows'], $before );
ok( 'and never written at all',         DZE_Diagnostic::$written, 0 );

DZE_Transfer::apply( $json, [ 'diagnostic' ] );
ok( 'ticked, they are replaced',        count( $GLOBALS['rows'] ), 2 );
ok( 'ticking nothing is refused',
	$said( static fn() => DZE_Transfer::apply( $json, [] ) ), 'Nothing was ticked.' );
ok( 'and a group the file does not hold is not invented',
	$said( static fn() => DZE_Transfer::apply( $json, [ 'klaviyo' ] ) ), 'Nothing was ticked.' );

echo "\nA write that did not happen is not a success\n";
// `update_option()` on a registered option re-runs a sanitizer shaped for
// form input, which is how this plugin has already lost a setting in silence.
// The module's own writer reads back and throws; the transfer must not
// swallow it and report the work done.
$GLOBALS['content_refuses'] = true;
ok( 'the module\'s refusal travels up',
	$said( static fn() => DZE_Transfer::apply( $json, [ 'prompts' ] ) ), 'The setting could not be saved.' );
$GLOBALS['content_refuses'] = false;

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
