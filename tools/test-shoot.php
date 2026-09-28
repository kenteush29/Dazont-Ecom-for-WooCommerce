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

// WordPress defines these everywhere, and the queue's own constants are written
// in them: a harness without them dies the moment that class is first touched.
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

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
	// WHICH PRODUCT the run is about, recorded like the unit beside it: every
	// call made inside the scope is filed on that product's own record.
	public static function about( $oid = 0 ) { $GLOBALS['about'][] = (int) $oid; }
	public static function about_now() { return (int) end( $GLOBALS['about'] ); }
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
	const MAX_SOURCES = 10;

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
	// EVERY photograph of the product, not only the first ones an ordinary
	// run keeps: a picked one may be the last of the gallery.
	public static function product_own_image_ids( ...$a ) { return $GLOBALS['own_ids'] ?? $GLOBALS['sources_ids']; }
	// The product's own AND its colours' photographs: a picked colour shot is
	// still this product's.
	public static function product_image_ids( ...$a ) { return array_values( array_unique( array_merge( $GLOBALS['own_ids'] ?? $GLOBALS['sources_ids'], $GLOBALS['colour_ids'] ?? [] ) ) ); }
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
	public function fal_generate( $prompt, $sources, $ratio = 'auto', $pid = 0, $made_of = '' ) {
		// WHAT THE TRACE WILL SAY travels with the call: the fifth argument is
		// the only thing standing between "6 photographs sent" and an answer to
		// "which of them put that border on every rug".
		$GLOBALS['sent'] = [ 'prompt' => $prompt, 'sources' => $sources, 'ratio' => $ratio, 'made_of' => $made_of ];
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

echo "ONE PRODUCT, AND EVERY PHOTOGRAPH IN THE REQUEST IS OF IT\n";
// "Ces 2 fonctions n'ont rien a faire ici. Le 1, c'est evident, on travaille
// toujours a partir de l'image principale. Le 2, c'est evident, on envoie des
// images supplementaires qui apportent plus de detail sur le produit, et
// jamais rien d'autre."
//
// Two questions were asked on three screens — is the product the subject, and
// is what you added a setting or something to copy — and both had one answer
// all along. There is one lane now: the product's own photographs lead, main
// first, and whatever was handed in follows as more views of the SAME product.
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'pastes' => [ 'data:one' ] ] );
ok( 'the product leads, always',
	$GLOBALS['sent']['sources'], [
		'data:image/jpeg;base64,IMG11/full', 'data:image/jpeg;base64,IMG12/large', 'data:pasted' ] );
// Nothing in the request declares a subject: a run that photographs the
// product afresh has none, and saying it had one is what used to let a
// supplier's black shot become image 1 on a desert product.
ok( 'and nothing is called a subject of its own', $GLOBALS['told'][4] ?? null, false );
// EVERYTHING HANDED IN IS COUNTED AS THE PRODUCT. The appended paragraph is
// built from this figure, so a pasted photograph landing outside it is a
// photograph the brief does not know about.
ok( 'what was handed in is part of the product', $GLOBALS['told'][0] ?? 0, 3 );
// THE TWO ANSWERS THE SCREENS USED TO POST ARE GONE, and a request that still
// carries them is answered exactly as one that does not: no lane of their own
// survives anywhere for them to reach.
$before = $GLOBALS['sent']['sources'];
$said   = $GLOBALS['told'];
[ $out, $err ] = shoot( [
	'post' => 7, 'template' => 1, 'pastes' => [ 'data:one' ],
	'base_main' => 1, 'refs_use' => 'copy' ] );
ok( 'an old subject answer changes nothing', $GLOBALS['sent']['sources'], $before );
ok( 'and an old copy answer changes nothing', $GLOBALS['told'], $said );
// And nothing appended can still be asking for a setting or for a copy: those
// were sentences the plugin wrote over the owner's own prompt.
// ON VERIFIE CE QUI EST DIT, PAS COMBIEN DE CHOSES SONT DITES. Un nombre
// interdit aussi les reponses legitimes : « une seule photo, choisie
// expres » en est une, et elle a ete ajoutee. Les deux qui portaient « ce
// qui a ete depose » restent bannies, et c est ELLES qu on nomme.
ok( 'the brief is told the product and nothing else',
	in_array( 'copy', $GLOBALS['told'], true ) || in_array( 'setting', $GLOBALS['told'], true ), false );

// A PICKED PHOTOGRAPH IS AN ANSWER TOO, and it never reached this function:
// the toolbox posted src_id and only the main-image lane ever read it, so
// picking one here changed nothing at all.
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'pastes' => [ 'data:one' ], 'src_id' => 12 ] );
// CE QUI EST DEPOSE A LA MAIN RESTE : c est un geste explicite, pas une
// accumulation automatique. On ne ferme que la porte de la galerie.
ok( 'a picked photograph is the only one of the product',
	$GLOBALS['sent']['sources'], [
		'data:image/jpeg;base64,IMG12/full', 'data:pasted' ] );
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'src_id' => 12 ] );
ok( 'and it goes out alone with nothing pasted',
	$GLOBALS['sent']['sources'], [ 'data:image/jpeg;base64,IMG12/full' ] );
// An id that answers for no image is dropped rather than sent: the product's
// own order stands.
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'src_id' => 999 ] );
ok( 'an id that answers for nothing is dropped',
	$GLOBALS['sent']['sources'], [
		'data:image/jpeg;base64,IMG11/full', 'data:image/jpeg;base64,IMG12/large' ] );

// SEVERAL PHOTOGRAPHS PICKED, IN THE ORDER THEY WERE PICKED. « J'aimerais
// re-générer des images basées sur l'image en pièce jointe » — a supplier's
// detail sheet, often the LAST picture of the gallery: picking it must work
// even when an ordinary run would not have sent it at all.
$GLOBALS['own_ids'] = [ 11, 12, 13 ];
$GLOBALS['images'][13] = true;
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'src_ids' => [ 13, 11 ] ] );
ok( 'the photographs picked travel, first picked first',
	$GLOBALS['sent']['sources'], [ 'data:image/jpeg;base64,IMG13/full', 'data:image/jpeg;base64,IMG11/large' ] );
ok( 'and the note is the one for several photographs', $GLOBALS['told'][0] ?? null, 2 );
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'src_ids' => [ 13 ] ] );
ok( 'one picked beyond what an ordinary run sends still goes, alone',
	$GLOBALS['sent']['sources'], [ 'data:image/jpeg;base64,IMG13/full' ] );
// A PHOTOGRAPH OF ANOTHER PRODUCT IS NEVER SENT, whatever the screen posts.
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'src_ids' => [ 555 ] ] );
ok( 'a picture that is not of this product is ignored',
	$GLOBALS['sent']['sources'], [ 'data:image/jpeg;base64,IMG11/full', 'data:image/jpeg;base64,IMG12/large' ] );
// A COLOUR'S OWN PHOTOGRAPH IS STILL THIS PRODUCT'S. The picker shows it, its
// colour written on the tile; dropping it in silence sent every photograph.
$GLOBALS['colour_ids'] = [ 14 ];
$GLOBALS['images'][14] = true;
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'src_ids' => [ 14 ] ] );
ok( 'a colour photograph picked is the one sent',
	$GLOBALS['sent']['sources'], [ 'data:image/jpeg;base64,IMG14/full' ] );
unset( $GLOBALS['own_ids'], $GLOBALS['colour_ids'] );

// WHAT WILL BE SENT, WITHOUT SENDING IT. « Je ne comprends toujours pas
// comment fonctionne la re-génération d'image. » The same order, stopped one
// step before the provider: nothing is asked of fal and nothing is paid.
$GLOBALS['sent'] = null;
[ $dry, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'dry' => 1 ] );
ok( 'a preview asks nothing of the provider',      $GLOBALS['sent'], [] );
ok( 'and hands back the words the model would read',
	false !== strpos( (string) ( $dry['prompt'] ?? '' ), 'SOURCES LINE.' ), true );
ok( 'and every picture, numbered in its order',
	array_map( static fn( $l ) => $l['n'] . ':' . $l['id'], (array) ( $dry['images'] ?? [] ) ), [ '1:11', '2:12' ] );
ok( 'each saying what it is', (string) ( $dry['images'][0]['what'] ?? '' ) !== '', true );

// THE ONE LANE THAT STILL HAS A SUBJECT is the arrow on a tile: "make this one
// again". That is the only request whose answer is allowed to look like its
// source, and it sends that image and nothing else.
[ $out, $err ] = shoot( [
	'post' => 7, 'template' => 1, 'src_url' => 'https://fal.media/files/old.jpg' ] );
ok( 'editing one image sends that image alone',
	$GLOBALS['sent']['sources'], [ 'https://fal.media/files/old.jpg' ] );
ok( 'and it is the subject',            $GLOBALS['told'][4] ?? null, true );

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
	// The one place that says what a row with no answer of its own is sent.
	// Stubbed from the REAL signature, never from the call being tested.
	public static function default_inputs( string $type ): array {
		return 'image' === $type
			? [ 'title', 'description' ]
			: [ 'title', 'description', 'attributes', 'price' ];
	}
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


echo "\nWHAT TRAVELLED IS NAMED, NOT COUNTED\n";
// "Ou voir le log pour la génération d'images ? Toutes mes images ont le style
// scalloped depuis 2 minutes." The trace said "6 reference photograph(s)
// attached" — a figure that cannot answer which picture did it. Every lane is
// already counted where it is filled; the same figures say what they are.
shop();
$GLOBALS['scene_idx'] = 0;
$GLOBALS['tpls'][0]['scene_i'] = 0;
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 0 ] );
ok( 'the run went through',             $err, '' );
ok( 'the trace names the product photographs',
	false !== strpos( (string) ( $GLOBALS['sent']['made_of'] ?? '' ), 'of the product' ), true );
// THE SCENE IS NAMED, which is the one that decides the background and the
// light — and the one a shop changes without thinking about it.
ok( 'and names the scene it was shot on',
	false !== strpos( (string) ( $GLOBALS['sent']['made_of'] ?? '' ), 'the scene "Slate"' ), true );
// A run with no scene says nothing about one rather than naming an empty one.
$GLOBALS['tpls'][0]['scene_i'] = -1;
$GLOBALS['scene_idx'] = -1;
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 0 ] );
ok( 'a run on no scene says nothing about one',
	false !== strpos( (string) ( $GLOBALS['sent']['made_of'] ?? '' ), 'the scene' ), false );
ok( 'and still says what the product sent',
	false !== strpos( (string) ( $GLOBALS['sent']['made_of'] ?? '' ), 'of the product' ), true );
// A PASTED PHOTOGRAPH IS ITS OWN LANE. Counted in with the product's own, the
// trace would say the product sent six pictures when four of them came from
// somewhere else entirely — which is exactly the question being asked.
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'pastes' => [ 'data:image/jpeg;base64,PASTED' ] ] );
ok( 'a pasted photograph is named as pasted',
	false !== strpos( (string) ( $GLOBALS['sent']['made_of'] ?? '' ), '1 pasted in' ), true );
ok( 'and is not counted as the product\'s own',
	false !== strpos( (string) ( $GLOBALS['sent']['made_of'] ?? '' ), '5 of the product' ), false );
// The sentence is built from a list, so a lane that sent nothing is not
// printed as "0 of something", which reads as a lane that failed.
ok( 'a lane that sent nothing is not printed',
	false !== strpos( (string) ( $GLOBALS['sent']['made_of'] ?? '' ), '0 ' ), false );


echo "\nA RUN SAYS WHICH PRODUCT IT IS ABOUT\n";
// "Peut être possible d'avoir un mini log par module ? Ici par exemple
// j'aimerai débuger ce produit les images sont bizarre." The shop's trace
// holds a dozen calls for everything, so the ones that made this product have
// rolled off by the time it looks wrong. The run declares its object the way
// it already declares its unit, and every call inside is filed on it — no call
// site has to be told, and none can forget.
shop();
$GLOBALS['about'] = [];
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1 ] );
ok( 'the run went through',             $err, '' );
ok( 'it names the product it is about', in_array( 7, (array) $GLOBALS['about'], true ), true );
// AND IT LETS GO. A scope left open files the NEXT run of the same request on
// the wrong product, which is worse than no log at all.
ok( 'and lets go of it when it is done', (int) end( $GLOBALS['about'] ), 0 );
// A run that THREW must let go too, or one failure poisons everything after.
$GLOBALS['about'] = [];
$GLOBALS['images'] = [];
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1 ] );
ok( 'a run that failed still let go',   (int) end( $GLOBALS['about'] ), 0 );

echo "\nLA PHOTO PRINCIPALE SEULEMENT, POUR TOUTE UNE SERIE\n";
// Le decor, remis : une section plus haut l a vide pour ses propres essais.
shop();
// « J utilise le bulk content pour mettre a jour des centaines de
// produits. » Choisir une photo produit par produit n a aucun sens a cette
// echelle — et c est pourtant la que le modele melange les vues, puisque
// chaque fiche envoie tout ce qu elle a. L ecran en masse n avait aucun
// selecteur du tout.
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1, 'only_main' => 1 ] );
ok( 'seule la photo principale part',
	$GLOBALS['sent']['sources'], [ 'data:image/jpeg;base64,IMG11/full' ] );
// ET LE MODELE SAIT QU IL N A QU UNE VUE : sans ca il tournerait l objet et
// inventerait la face qu il n a jamais vue.
ok( 'et il sait qu il n a qu une vue', $GLOBALS['told'][5] ?? null, true );
// SANS LA CASE, RIEN NE CHANGE : plusieurs photos aident sur un produit
// simple, elles perdent le modele sur un produit technique, et c est a la
// boutique de savoir lequel elle traite.
[ $out, $err ] = shoot( [ 'post' => 7, 'template' => 1 ] );
ok( 'sans la case, toutes partent',
	count( (array) $GLOBALS['sent']['sources'] ) > 1, true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
