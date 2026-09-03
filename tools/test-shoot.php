<?php
/**
 * The one function that makes a product photograph.
 *
 * Run before every release:  php tools/test-shoot.php dazont-ecom
 *
 * It used to be the AJAX handler itself — three hundred lines reading $_POST
 * and ending in wp_send_json_* — so nothing could call it, and an automatic
 * pass had a choice between copying the whole prompt assembly (the sources,
 * the "not like this" references, the scene, the shop's notes, the ratio) or
 * doing without it. Two copies of a prompt this careful drift apart, and the
 * catalogue pays for it.
 *
 * This runs the real function against a fake shop and reads WHAT IT SENDS —
 * the prompt, the sources, the ratio — which is the only thing about it that
 * matters. A click and a queued job pass the same names and must get the same
 * picture from the same words.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
function __( $s, $d = '' ) { return $s; }
function esc_url_raw( $s ) { return (string) $s; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : stripslashes( (string) $v ); }
function wp_attachment_is_image( $id ) { return ! empty( $GLOBALS['images'][ (int) $id ] ); }
function wp_get_attachment_image_url( $id, $size = '' ) { return 'https://kula.test/wp-content/' . (int) $id . '.jpg'; }
function get_post_thumbnail_id( $pid ) { return (int) ( $GLOBALS['thumb'][ (int) $pid ] ?? 0 ); }
function wc_attribute_label( $a ) { return ucfirst( (string) $a ); }
function is_int_stub( $v ) { return is_int( $v ); }

class DZE_Ai_Usage {
	public static function over_budget() { return ! empty( $GLOBALS['over_budget'] ); }
	public static function budget_message() { return 'The monthly AI budget is spent.'; }
	public static function unit( $k = '' ) {}
	public static function finished( $k = '' ) {}
}
class DZE_Health { public static function log( ...$a ) { $GLOBALS['logged'][] = $a; } }
class DZE_Content { public static function clean_ratio( $r ) { return (string) $r; } }

require __DIR__ . '/../' . $dir . '/includes/class-content-ajax.php';

/**
 * The trait, in a host that answers everything shoot() asks of the shop.
 *
 * Stubs, deliberately: what is being tested is the ASSEMBLY — which sources go
 * in, in what order, with which prompt — not WordPress.
 */
final class DZE_Shoot_Host {
	use DZE_Content_Ajax;

	const MAX_PAYLOAD = 20000000;

	public static function fal_key() { return 'fal-key'; }
	public static function image_templates() { return $GLOBALS['tpls']; }
	public static function template_validated( $i ) { return ! empty( $GLOBALS['validated'] ); }
	public static function attach_target( $t ) {
		if ( 0 === strpos( (string) $t, 'variation:' ) ) {
			[ $a, $v ] = array_pad( explode( '::', substr( (string) $t, 10 ), 2 ), 2, '' );
			return ( '' !== $a && '' !== $v ) ? 'variation:' . $a . '::' . $v : 'gallery';
		}
		return in_array( $t, [ 'main', 'gallery_first' ], true ) ? $t : 'gallery';
	}
	public static function scenes() { return $GLOBALS['scenes']; }
	public static function default_scene() { return $GLOBALS['scene_idx']; }
	public static function is_fal_url( $u ) { return false !== strpos( (string) $u, 'fal.media' ); }
	public static function product_source_ids( ...$a ) { return $GLOBALS['sources_ids']; }
	public static function variation_ids( ...$a ) { return []; }
	public static function wants_variants( ...$a ) { return false; }
	public static function variation_group_name( ...$a ) { return 'Olive'; }
	public static function attribute_value_label( ...$a ) { return 'Olive'; }
	public static function variation_instruction( ...$a ) { return "\nVARIATION LINE."; }
	public static function variation_line( ...$a ) { return ''; }
	public static function avoid_sources( ...$a ) { return $GLOBALS['avoid'] ?? []; }
	public static function note_lines( ...$a ) { return "\nNOTE LINE."; }
	public static function payload_lines( ...$a ) { return 'A-Tacs FG Combat Uniform. Ripstop.'; }
	public static function store_context() { return 'Kula Tactical, tactical gear.'; }
	public static function sources_instruction( ...$a ) { $GLOBALS['told'] = $a; return "\nSOURCES LINE."; }
	public static function read_data_uris( $in, ...$rest ) { return array_map( static fn( $x ) => 'data:pasted', (array) $in ); }
	public static function registry_row( ...$a ) { return []; }
	public static function stash( $pid, $row ) { $GLOBALS['stashed'][] = $row; }
	public static function charge_product( ...$a ) {}
	public static function product_spend( ...$a ) { return [ 'label' => '$0.12' ]; }
	public static function last_image_cost() { return 0.04; }
	public function variant_images( ...$a ) { return []; }
	public function fal_source_data_uri( $id, $size = 'full' ) {
		if ( empty( $GLOBALS['images'][ (int) $id ] ) ) { throw new RuntimeException( 'Could not read the product image file.' ); }
		return 'data:image/jpeg;base64,IMG' . (int) $id . '/' . $size;
	}
	public function sideload_seo( $url, $pid, $target, $recipe = '', $keep = true ) {
		$GLOBALS['filed'] = [ 'url' => $url, 'pid' => $pid, 'target' => $target, 'recipe' => $recipe ];
		return 4242;
	}
	public function fal_generate( $prompt, $sources, $ratio = 'auto' ) {
		$GLOBALS['sent'] = [ 'prompt' => $prompt, 'sources' => $sources, 'ratio' => $ratio ];
		return 'https://fal.media/files/new-shot.jpg';
	}
	private function guard(): void {}
}

$fails = 0;
$ran   = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok    %s\n", $what ); return; }
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
/** The run, and what it threw if it threw. */
function shoot( array $in ): array {
	$GLOBALS['sent'] = [];
	$GLOBALS['filed'] = [];
	try { return [ ( new DZE_Shoot_Host() )->shoot( $in ), '' ]; }
	catch ( Throwable $e ) { return [ [], $e->getMessage() ]; }
}
function shop(): void {
	$GLOBALS['images']      = [ 11 => 1, 12 => 1, 90 => 1 ];
	$GLOBALS['thumb']       = [ 7 => 11 ];
	$GLOBALS['sources_ids'] = [ 11, 12 ];
	$GLOBALS['scenes']      = [ [ 'image' => 90, 'name' => 'Slate' ] ];
	$GLOBALS['scene_idx']   = -1;
	$GLOBALS['validated']   = true;
	$GLOBALS['over_budget'] = false;
	$GLOBALS['avoid']       = [];
	$GLOBALS['tpls']        = [
		[ 'id' => 'main1',  'name' => 'Main image', 'target' => 'main',    'prompt' => 'MAIN PROMPT', 'ratio' => '1:1' ],
		[ 'id' => 'angle1', 'name' => 'Another angle', 'target' => 'gallery', 'prompt' => 'ANGLE PROMPT', 'ratio' => '4:5' ],
	];
}

echo "The function a click calls, called with no click at all\n";
// This is the whole point of the extraction: no nonce, no $_POST, no dying
// with JSON. A background job passes the same names and gets an answer back.
shop();
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1 ] );
ok( 'it does not fail',                 $err, '' );
ok( 'and it files the picture',         $out['attachment'] ?? 0, 4242 );
ok( 'on the gallery, as its recipe says', $GLOBALS['filed']['target'] ?? '', 'gallery' );
ok( 'crediting the recipe that made it', $GLOBALS['filed']['recipe'] ?? '', 'angle1' );
ok( 'from the address fal answered',    $GLOBALS['filed']['url'] ?? '', 'https://fal.media/files/new-shot.jpg' );

echo "What is actually sent to fal\n";
$sent = $GLOBALS['sent'];
ok( 'the recipe asked for is the one used',
	false !== strpos( $sent['prompt'], 'ANGLE PROMPT' ), true );
ok( 'and not its neighbour',            false !== strpos( $sent['prompt'], 'MAIN PROMPT' ), false );
ok( 'the shop and the product come first',
	0 === strpos( $sent['prompt'], 'Product context: Kula Tactical, tactical gear. A-Tacs FG' ), true );
ok( "the shop's own notes are appended", false !== strpos( $sent['prompt'], 'NOTE LINE.' ), true );
ok( 'and the sources are explained',    false !== strpos( $sent['prompt'], 'SOURCES LINE.' ), true );
ok( "the recipe's own ratio travels",   $sent['ratio'], '4:5' );
// The product's real photographs, as data URIs: fal cannot fetch a staging URL.
ok( 'the real photographs go with it',  $sent['sources'], [
	'data:image/jpeg;base64,IMG11/full', 'data:image/jpeg;base64,IMG12/large' ] );

echo "The recipe chosen is the recipe used\n";
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 0 ] );
ok( 'the first one, when asked for',    false !== strpos( $GLOBALS['sent']['prompt'], 'MAIN PROMPT' ), true );
ok( 'and it lands on the main image',   $GLOBALS['filed']['target'] ?? '', 'main' );
ok( 'with its own ratio',               $GLOBALS['sent']['ratio'], '1:1' );

echo "A destination named by the caller outranks the recipe's\n";
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 0, 'target' => 'gallery' ] );
ok( 'the caller decides where it goes', $GLOBALS['filed']['target'] ?? '', 'gallery' );

echo "Nothing is filed until somebody has looked, when that is asked for\n";
$GLOBALS['validated'] = false;
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1 ] );
ok( 'it comes back as a preview',       ! empty( $out['preview'] ), true );
ok( 'carrying the picture',             $out['url'] ?? '', 'https://fal.media/files/new-shot.jpg' );
ok( 'and nothing was filed',            $GLOBALS['filed'], [] );
$GLOBALS['validated'] = true;

echo "What it refuses, and in whose words\n";
[ , $err ] = shoot( [ 'template' => 1 ] );
ok( 'no product, no picture',           $err, 'Save the product first.' );
$GLOBALS['over_budget'] = true;
[ , $err ] = shoot( [ 'post' => 7, 'template' => 1 ] );
ok( 'the budget stops it',              $err, 'The monthly AI budget is spent.' );
$GLOBALS['over_budget'] = false;
$GLOBALS['sources_ids'] = [];
$GLOBALS['thumb']       = [];
[ , $err ] = shoot( [ 'post' => 7, 'template' => 1 ] );
ok( 'a product with no photograph says so',
	$err, 'Set a featured image on this product first.' );
$GLOBALS['sources_ids'] = [ 11, 12 ];
$GLOBALS['thumb']       = [ 7 => 11 ];
[ , $err ] = shoot( [ 'post' => 7, 'template' => 1, 'src_url' => 'https://elsewhere.test/x.jpg' ] );
ok( 'a source from anywhere else is refused', $err, 'Invalid source image.' );

echo "The words of a refusal reach the screen, not a shrug\n";
// ajax_image() is a wrapper now; what it must never do is swallow the sentence.
$src = file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content-ajax.php' );
$fn  = substr( $src, strpos( $src, 'public function ajax_image(): void {' ) );
$fn  = substr( $fn, 0, strpos( $fn, "\n\t}" ) );
ok( 'the wrapper checks the nonce',     false !== strpos( $fn, '$this->guard();' ), true );
ok( 'calls the one function',           false !== strpos( $fn, '$this->shoot(' ), true );
ok( 'and hands the sentence over',      false !== strpos( $fn, '$e->getMessage()' ), true );
// And shoot() itself must stay callable: a nonce check or a JSON exit put back
// inside it makes every automatic pass impossible again, silently.
$sh = substr( $src, strpos( $src, 'public function shoot( array $in ): array {' ) );
$sh = substr( $sh, 0, strpos( $sh, "\n\t}\n\n\t/**" ) );
ok( 'shoot() never ends the request',   false !== strpos( $sh, 'wp_send_json' ), false );
ok( 'and never asks for a nonce',       false !== strpos( $sh, 'guard()' ), false );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
