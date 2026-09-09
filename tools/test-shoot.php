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
function get_the_title( $id ) { return (string) ( $GLOBALS['titles'][ (int) $id ] ?? '' ); }
function html_entity_decode_stub( $s ) { return $s; }
function current_time( $t = 'mysql' ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_term( ...$a ) { return null; }
function is_wp_error( $t ) { return false; }
function add_action( ...$a ) {}
function wp_kses_post( $s ) { return (string) $s; }
function get_option( $k, $d = false ) { return $d; }
function update_option( $k, $v, $a = null ) { return true; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return $s; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function add_query_arg( $a, $u = '' ) { return $u; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }

class DZE_Ai_Usage {
	public static function over_budget() { return ! empty( $GLOBALS['over_budget'] ); }
	public static function budget_message() { return 'The monthly AI budget is spent.'; }
	public static function unit( $k = '' ) {}
	public static function finished( $k = '' ) {}
}
class DZE_Health { public static function log( ...$a ) { $GLOBALS['logged'][] = $a; } }

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
	const MAX_PASTED  = 12;

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
	// The real signature: the product, the variation group, and the note typed
	// for THIS RUN. The gate reads back what was handed to it.
	public static function note_lines( ...$a ) { $GLOBALS['noted'] = $a; return "\nNOTE LINE." . ( '' !== (string) ( $a[2] ?? '' ) ? ' ' . $a[2] : '' ); }
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

echo "A NOTE IS FOR THE RUN IN FRONT OF YOU, NOT FOR EVER\n";
// "Ne mets pas de ruban sur le tshirt ! répètes le meme design, c'est tout !"
// — typed into a box that saved it on the product and sent it with every image
// made for that shirt from then on, invisibly: "la note est ponctuelle et n'a
// pas à être enregistrée pour plus tard."
shop();
$GLOBALS['noted'] = [];
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'note' => 'No ribbon on the shirt.' ] );
ok( "the run's own note reaches the prompt",
	(string) ( $GLOBALS['noted'][2] ?? '' ), 'No ribbon on the shirt.' );
ok( 'and it is in what goes out',
	false !== strpos( $GLOBALS['sent']['prompt'], 'No ribbon on the shirt.' ), true );
// AND NOTHING IS STORED. shoot() has no writer for it: the only place that
// note can come from is the request that carried it.
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1 ] );
ok( 'the next run carries none of it',  (string) ( $GLOBALS['noted'][2] ?? '' ), '' );
ok( 'and the words are gone from the prompt',
	false !== strpos( $GLOBALS['sent']['prompt'], 'No ribbon on the shirt.' ), false );

echo "THE BACKGROUND IS THE PROMPT'S OWN, never one answer for the whole shop\n";
// "Image ugc generee dans l'outil bulk. Invraisemblable." A prompt asking for
// a customer's own snapshot came back a white pack shot with the product
// floating in it, because the scene was ONE setting for the whole shop —
// default_scene() — attached to every prompt whatever it asked for. The
// appended sources block then tells the model, in capitals, that this image
// IS the surface, the background and the light of the photograph, so the
// studio backdrop won against the prompt every time.
shop();
$GLOBALS['scene_idx'] = 0;                 // the shop has a default backdrop…
$GLOBALS['tpls'][1]['scene_i'] = -1;       // …and this prompt asks for none.
$GLOBALS['tpls'][0]['scene_i'] = 0;
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1 ] );
ok( 'a prompt that wants no scene gets none',
	in_array( 'data:image/jpeg;base64,IMG90/full', $GLOBALS['sent']['sources'], true ), false );
ok( 'and nothing is said about a scene',
	null === ( $GLOBALS['told'][1] ?? null ), true );
// The prompt that DOES want one still gets it, on the same shop.
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 0 ] );
ok( 'a prompt that wants one gets it',
	in_array( 'data:image/jpeg;base64,IMG90/full', $GLOBALS['sent']['sources'], true ), true );
ok( 'and it is named as the scene',
	(string) ( $GLOBALS['told'][1]['name'] ?? '' ), 'Slate' );
// A screen that says otherwise still wins: the menu on the row is a one-off
// for the run about to be launched.
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'scene' => 0 ] );
ok( 'a scene asked for by the screen travels',
	in_array( 'data:image/jpeg;base64,IMG90/full', $GLOBALS['sent']['sources'], true ), true );
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 0, 'scene' => -1 ] );
ok( 'and "no scene" asked for by the screen is obeyed',
	in_array( 'data:image/jpeg;base64,IMG90/full', $GLOBALS['sent']['sources'], true ), false );
shop();

echo "WHICH photograph is the subject when one was added from outside\n";
// The regression this block exists for, in the owner's words: "images generees
// dans une autre couleur que le produit principal. Il me donne du kryptek noir
// plutot que du desert. Avant ca fonctionnait. J'ai ajoute des images externes
// en copier coller en kryptek noir pour un meilleur contexte."
//
// The picker that replaced the old checkbox reads "Main photograph" on its
// default and sent NOTHING on it, and a request carrying pasted photographs
// and no answer is read here as "the pasted one leads". So the supplier's
// black shot became image 1 and the product came back in its colour, with the
// screen still saying the product's own main image was the subject.
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'pastes' => [ 'data:one' ], 'base_main' => 1 ] );
ok( 'the product leads when the screen says so',
	$GLOBALS['sent']['sources'], [
		'data:image/jpeg;base64,IMG11/full', 'data:image/jpeg;base64,IMG12/large', 'data:pasted' ] );
// The instruction that goes with them says the same thing: the pasted one is
// a reference, not a second product.
ok( 'and what was added is a reference',   $GLOBALS['told'][5] ?? -1, 1 );
ok( 'the product is not "a subject" of its own', $GLOBALS['told'][4] ?? null, false );

// A PICKED PHOTOGRAPH IS AN ANSWER TOO, and it never reached this function:
// the toolbox posted src_id and only the main-image lane ever read it, so
// picking one here changed nothing at all.
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'pastes' => [ 'data:one' ], 'src_id' => 12 ] );
ok( 'a picked photograph leads them',
	$GLOBALS['sent']['sources'], [
		'data:image/jpeg;base64,IMG12/full', 'data:image/jpeg;base64,IMG11/large', 'data:pasted' ] );
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'src_id' => 12 ] );
ok( 'and leads them with nothing pasted at all',
	$GLOBALS['sent']['sources'], [
		'data:image/jpeg;base64,IMG12/full', 'data:image/jpeg;base64,IMG11/large' ] );
// An id that answers for no image is dropped rather than sent: the product's
// own order stands.
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'src_id' => 999 ] );
ok( 'an id that answers for nothing is dropped',
	$GLOBALS['sent']['sources'], [
		'data:image/jpeg;base64,IMG11/full', 'data:image/jpeg;base64,IMG12/large' ] );
// AND THE OTHER ANSWER STILL WORKS. "The photograph you added" is a choice on
// that same picker, and it means what pasting used to mean on its own: the
// pasted set is the subject and the product's own follow as context.
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'pastes' => [ 'data:one' ] ] );
ok( 'the pasted one leads when it is chosen',
	$GLOBALS['sent']['sources'], [
		'data:pasted', 'data:image/jpeg;base64,IMG11/large', 'data:image/jpeg;base64,IMG12/large' ] );
ok( 'and it is said to be the subject',  $GLOBALS['told'][4] ?? null, true );

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

echo "The queue makes the same picture, and files NOTHING until it is accepted\n";
// The whole promise of this pass, in three checks: the queued job calls the
// same function with the same names, the product is untouched while the
// picture waits, and accepting it is what puts it on the product.
class DZE_Content {
	public static function clean_ratio( $r ) { return (string) $r; }
	public static function instance() { return new DZE_Shoot_Host(); }
}
class DZE_Modules { public static function enabled( $id ) { return true; } }
class DZE_Post_Links { public static function add_links( $id ) { return ''; } }
class DZE_Category_Content { public static function generate( ...$a ) { return ''; } }
require __DIR__ . '/../' . $dir . '/includes/class-queue.php';

shop();
$job = [ 'template' => 1 ];
$make = new ReflectionMethod( 'DZE_Queue', 'produce' );
$make->setAccessible( true );
$GLOBALS['filed'] = [];
$url = $make->invokeArgs( null, [ 'product_shot', 7, &$job ] );
ok( 'the queue gets a picture back',    $url, 'https://fal.media/files/new-shot.jpg' );
ok( 'made from the recipe it asked for',
	false !== strpos( $GLOBALS['sent']['prompt'], 'ANGLE PROMPT' ), true );
// Nothing on the product. This is the line the shop is trusting.
ok( 'and NOTHING was put on the product', $GLOBALS['filed'], [] );
// Where it belongs is decided once, by the recipe, and travels with the job.
ok( 'the job remembers where it goes',  $job['target'] ?? '', 'gallery' );
ok( 'and which recipe made it',         $job['recipe'] ?? '', 'angle1' );

// Accepting is the only thing that touches the product.
ok( 'accepting files it',               DZE_Queue::apply( 'product_shot', 7, $url, $job ), true );
ok( 'on the product that asked',        $GLOBALS['filed']['pid'] ?? 0, 7 );
ok( 'at the place the recipe named',    $GLOBALS['filed']['target'] ?? '', 'gallery' );
ok( 'named after that recipe',          $GLOBALS['filed']['recipe'] ?? '', 'angle1' );
ok( 'and the address fal gave',         $GLOBALS['filed']['url'] ?? '', $url );
// Refusing files nothing at all.
$GLOBALS['filed'] = [];
ok( 'an empty result files nothing',    DZE_Queue::apply( 'product_shot', 7, '', $job ), false );
ok( 'and touches nothing',              $GLOBALS['filed'], [] );
// The row on the review screen says WHICH product, by its name.
$GLOBALS['titles'][7] = 'A-Tacs FG Combat Uniform';
ok( 'the waiting row names the product',
	DZE_Queue::label_for( 'product_shot', 7 ), 'A-Tacs FG Combat Uniform' );

echo "A photograph is reviewed as a photograph\n";
// The queue's review screen was written for text — two word counts and a diff.
// An image job says so, and the screen shows the picture instead. The kind is
// what carries that, so a future image job arrives already reviewable.
ok( 'the job says it is a picture',
	! empty( DZE_Queue::kinds()['product_shot']['image'] ), true );
ok( 'and the text jobs do not',
	empty( DZE_Queue::kinds()['cat_desc']['image'] ), true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
