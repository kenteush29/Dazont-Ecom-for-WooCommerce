<?php
/**
 * What is said ABOUT the photographs sent with an image request.
 *
 * Run before every release:  php tools/test-sources.php dazont-ecom
 *
 * "J'ai malheureusement trop de AI slop sur les images. Toujours des détails
 * produit mal reproduits ou inventés... des attaches imaginaires rajoutées
 * devant, une sangle imaginaire rajoutée derrière."
 *
 * Every image request carries the product's own data — its title, its
 * description, its attributes — and a description describes the product IN
 * GENERAL: the family, the version with the straps, the options. The sentence
 * that settles which one wins when the words and the photographs disagree was
 * sent ONLY when a photograph had been pasted or picked, which is the one case
 * where the text was least likely to be believed anyway. On every ordinary
 * run — the toolbox, the bulk screen, every automatic pass — the text went in
 * with equal authority and nothing arbitrated.
 *
 * A generative model asked for a close-up of fastenings it has not been shown,
 * with a text that names fastenings, draws fastenings. That is the whole bug.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'DZE_VERSION', 'test' );
define( 'DZE_URL', 'http://kula.test/wp-content/plugins/dazont-ecom/' );
define( 'DZE_DIR', __DIR__ . '/../dazont-ecom/' );
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
function get_transient( $k ) { return false; }
function set_transient( ...$a ) { return true; }
function is_admin() { return true; }
function admin_url( $p = '' ) { return 'http://shop.test/wp-admin/' . $p; }
function add_query_arg( $args, $url = '' ) { return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args ); }
$GLOBALS['dze_diag_class'] = true;
// What a screen ASKS FOR, recorded: a body drawn somewhere new must take
// everything it needs with it, and the only way to know is to run it.
$GLOBALS['enq'] = [];
function wp_enqueue_script( $h, $src = '', $deps = [], $v = '', $f = false ) { $GLOBALS['enq'][] = [ $h, (array) $deps ]; }
function wp_enqueue_style( ...$a ) {}
// THE HARNESS CONFIG IS THE PLUGIN'S OWN, key by key. Retyped into the browser
// gate, one wrong name — `ajax` for `ajaxUrl` — sends every request to the page
// itself, every answer comes back as HTML, and the gate proves nothing while
// looking green.
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['loc'][ (string) $name ] = $data; }
function wp_enqueue_editor() { $GLOBALS['enq'][] = [ 'editor', [] ]; }
function wp_enqueue_media() { $GLOBALS['enq'][] = [ 'media', [] ]; }
function wp_create_nonce( $a = '' ) { return 'n'; }
function plugins_url( $p = '', $f = '' ) { return 'http://shop.test/' . $p; }
function wp_script_is( ...$a ) { return false; }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
// Enough of a screen for the BODY to be run, not only the method under it: a
// gate that calls the assets itself proves they enqueue, and nothing about
// whether the screen ever asks for them. That is how this shipped broken.
function current_user_can( ...$a ) { return true; }
function esc_textarea( $s ) { return esc_html( $s ); }
function checked( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? " checked='checked'" : ''; if ( $e ) { echo $r; } return $r; }
function disabled( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? " disabled='disabled'" : ''; if ( $e ) { echo $r; } return $r; }
function selected( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? " selected='selected'" : ''; if ( $e ) { echo $r; } return $r; }
function submit_button( ...$a ) {}
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function wp_nonce_field( ...$a ) {}
function _prime_post_caches( ...$a ) {}
function get_post_status( ...$a ) { return 'publish'; }
function get_edit_post_link( $id ) { return 'http://shop.test/edit/' . (int) $id; }
function get_permalink( $id ) { return 'http://shop.test/p/' . (int) $id; }
/** Enough of a product for the bulk screen to draw a row of it. */
class WC_Product {
	private $id;
	public function __construct( $id ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_name() { return 'Product ' . $this->id; }
	public function is_type( $t ) { return false; }
	public function get_children() { return []; }
	public function get_regular_price() { return '10'; }
}
function wc_get_product( $id ) { return new WC_Product( $id ); }
function wc_placeholder_img_src() { return 'http://shop.test/ph.png'; }
function get_post_meta( $id, $key = '', $single = false ) { return $single ? '' : []; }
class DZE_Marketing_Ai { const MENU_SLUG = 'dazont-ecom-ai'; public static function get_settings() { return []; } public static function api_key() { return 'k'; } }
function get_current_user_id() { return 1; }
function get_user_meta( ...$a ) { return $GLOBALS['dze_list'] ?? []; }
// The fake shop can really shorten its list, so a Discard that removes a
// product comes back as a legible FAIL rather than killing the run: a gate
// that dies on the fault it is about reports nothing at all.
function update_user_meta( $u, $k, $v ) { $GLOBALS['dze_list'] = (array) $v; return true; }
function delete_user_meta( $u, $k ) { $GLOBALS['dze_list'] = []; return true; }
// Refusing throws away what was waiting on a product, and nothing else: the
// line stays where it is. What the gate reads back is WHICH products were let
// go of.
function delete_post_meta( $id, $key = '', $v = '' ) { $GLOBALS['dze_dropped'][] = (int) $id; return true; }
function delete_transient( $k ) { return true; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function get_posts( ...$a ) { return []; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function get_the_title( $id ) { return 'P' . (int) $id; }
function wp_get_attachment_image_url( ...$a ) { return ''; }
function get_post_thumbnail_id( ...$a ) { return 0; }
$GLOBALS['wpdb'] = new class {
	public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts'; public $prefix = 'wp_';
	public function prepare( $q, ...$a ) { return $q; }
	public function get_var( $q ) { return 0; }
	public function get_results( $q, $m = null ) { return []; }
	public function get_col( $q ) { return []; }
};
/** The popup behind every "✎ Prompt" drawn in JavaScript on that screen. */
class DZE_Prompts {
	public static $printed = 0;
	public static function print_assets() { self::$printed++; }
	public static function the_button( $id, $label = '' ) { printf( '<button class="dze-prompt-peek" data-prompt="%s">%s</button>', $id, $label ?: 'Prompt' ); }
	public static function the_data( $id ) {}
	public static function card_open( ...$a ) {}
	public static function card_close( ...$a ) {}
}
/**
 * The screen that may host the product bulk work — switched on, or off — and
 * the reading that belongs to a product, which three screens now print.
 */
class DZE_Diagnostic {
	const MENU_SLUG = 'dazont-ecom-diagnostic';
	public static function todo( $pid ) {
		return array_map(
			static fn( $said ) => [ 'check' => 'c', 'said' => $said, 'want' => [] ],
			(array) ( $GLOBALS['dze_todo'][ (int) $pid ] ?? [] )
		);
	}
}
class DZE_Modules {
	public static function enabled( $id ) {
		return 'diagnostic' === $id ? ! empty( $GLOBALS['dze_diag_class'] ) : true;
	}
}
// Enough of WordPress for the prompt registry to answer, so the note the
// SCREEN shows is read from the registry the shop actually holds.
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_parse_args( $a, $d = [] ) { return array_merge( (array) $d, (array) $a ); }
$GLOBALS['opts'] = [];

// The class is split across a trait; both halves are the shipped files, never
// a copy of the function under test written into this one.
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
require __DIR__ . '/../' . $dir . '/includes/class-hub.php';
require __DIR__ . '/../' . $dir . '/includes/class-ai-usage.php';
require __DIR__ . '/../' . $dir . '/includes/class-content-ajax.php';
require __DIR__ . '/../' . $dir . '/includes/class-content.php';

$ran = 0; $fails = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok   %s\n", $what ); return; }
	$fails++;
	printf( "  WRONG %s\n       got  %s\n       want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
/** Does this instruction hand the argument to the photographs? */
function arbitrated( string $said ): bool {
	return false !== strpos( $said, 'THE PHOTOGRAPHS WIN' );
}

// The product bulk screen as the plugin prints it, for the browser gate that
// presses its buttons (tools/js/content-bulk.mjs). Never a copy of the markup
// written into the test: what is pressed there is what ships.
if ( in_array( '--dump-bulk', (array) $argv, true ) ) {
	$GLOBALS['opts']['dze_content_settings'] = [
		// A fake shop that can really make images: without a key the Images
		// block is drawn DISABLED, and a gate pressing a switched-off block
		// proves nothing about the screen the shop actually uses.
		'fal_key'  => 'fake-fal-key',
		'scenes'   => [
			[ 'name' => 'Studio backdrop', 'image' => 90, 'prompt' => '', 'default' => true ],
			[ 'name' => 'Slate', 'image' => 91, 'prompt' => '', 'default' => false ],
		],
		'registry' => [
			[ 'id' => 'main1', 'name' => 'Pack shot', 'type' => 'image', 'output' => 'main', 'prompt' => 'P', 'tokens' => 400, 'enabled' => 1, 'valid' => 1, 'scene' => 'Studio backdrop' ],
			[ 'id' => 'ugc', 'name' => 'Customer photo', 'type' => 'image', 'output' => 'gallery', 'prompt' => 'P', 'tokens' => 400, 'enabled' => 1, 'valid' => 1, 'scene' => '' ],
			[ 'id' => 'slate', 'name' => 'On slate', 'type' => 'image', 'output' => 'gallery', 'prompt' => 'P', 'tokens' => 400, 'enabled' => 1, 'valid' => 1, 'scene' => 'Slate' ],
			[ 'id' => 'desc', 'name' => 'Description', 'type' => 'text', 'output' => 'post_content', 'prompt' => 'P', 'tokens' => 400, 'enabled' => 1, 'valid' => 1 ],
			// A second text field, so the browser gate can open one the product
			// holds and one it does not: an empty field must SAY it is empty.
			[ 'id' => 'short', 'name' => 'Short description', 'type' => 'text', 'output' => 'post_excerpt', 'prompt' => 'P', 'tokens' => 400, 'enabled' => 1, 'valid' => 1 ],
		],
	];
	$GLOBALS['dze_list'] = [ 7, 8 ];
	$GLOBALS['dze_todo'] = [ 7 => [ 'Gallery photographs — 0 of 3' ], 8 => [] ];
	$GLOBALS['loc'] = [];
	ob_start();
	DZE_Content::instance()->bulk_body( 'http://dze.test/screen' );
	$dze_html = (string) ob_get_clean();
	echo wp_json_encode( [ 'html' => $dze_html, 'cfg' => $GLOBALS['loc']['dzeContentBulk'] ?? [] ] );
	exit( 0 );
}

echo "\nThe photographs win over the words — on EVERY run\n";
// The ordinary run: no photograph pasted, no photograph picked, the product's
// own images and the product's own data. This is the toolbox, the bulk
// screen, and every automatic pass — which is to say, nearly everything.
$plain = DZE_Content::sources_instruction( 3, null, 0, 0, false, 0 );
ok( 'an ordinary run is arbitrated',    arbitrated( $plain ), true );
ok( 'and it says what not to do with a word',
	false !== strpos( $plain, 'these photographs are the one being made' ), true );
// The run with a subject — a photograph pasted or picked — was the ONLY one
// that used to carry it.
ok( 'a run with a subject still is',    arbitrated( DZE_Content::sources_instruction( 3, null, 0, 0, true, 0 ) ), true );
// And a product with ONE photograph, which is where a description has the
// most room to talk the model into something.
ok( 'a single photograph is arbitrated too',
	arbitrated( DZE_Content::sources_instruction( 1, null, 0, 0, false, 0 ) ), true );

echo "\nAnd the rest of the brief is still there\n";
ok( 'several photographs are named as one product',
	false !== strpos( $plain, 'ARE ONE SINGLE PRODUCT' ), true );
ok( 'the fittings are named, so none is a surprise',
	false !== strpos( $plain, 'every buckle, strap, cord, zip, seam and marking' ), true );
ok( 'and an unreadable one is left out rather than painted',
	false !== strpos( $plain, 'an invented one is a fake' ), true );
// SAID ONCE. Four ways of saying one rule is not four times the rule, and
// every sentence here competes with the shop's own prompt for attention.
ok( 'the rule is not repeated four ways',
	substr_count( $plain, 'invent' ), 1 );
ok( 'and the whole brief stays short',  strlen( $plain ) < 700, true );
$subj = DZE_Content::sources_instruction( 3, null, 0, 0, true, 0 );
ok( 'a subject run names image 1 as the product',
	false !== strpos( $subj, 'IMAGE 1 IS THE PRODUCT TO WORK ON' ), true );
ok( 'and the others as shape only',     false !== strpos( $subj, 'for the shape and the construction only' ), true );
// References handed in are named for what they are, or they are read as the
// product and come back wearing its colours.
$refs = DZE_Content::sources_instruction( 2, null, 0, 0, true, 1 );
ok( 'a reference is named as the setting',
	false !== strpos( $refs, 'IS A REFERENCE YOU WERE HANDED' ), true );

echo "\nNOTHING APPENDED MAY OVERRULE THE PROMPT\n";
// "Tu as encore ajouté des instructions custom par dessus le prompt ? Ça
// t'est interdit. Le prompt est le gagnant. Il est bien rédigé, et aucune
// autre instruction cachée ne devrait exister."
//
// Two sentences were doing exactly that, and one of them was worse than the
// other: with a scene chosen, the plugin told the model to "ignore any
// background described in words above" — the appended text disregarding the
// instructions it is appended to.
$scened = DZE_Content::sources_instruction( 3, [ 'image' => 9, 'prompt' => 'Slate surface' ], 0, 0, false, 0 );
ok( 'nothing tells the model to ignore the prompt',
	false !== strpos( $scened, 'ignore any background described in words above' ), false );
ok( 'and nothing else says "ignore"',   substr_count( $scened, 'ignore' ), 0 );
// The scene still does its own mechanical work: it IS the background, and the
// shop's own words for that scene travel with it.
ok( 'the scene is still named',         false !== strpos( $scened, 'IS THE SCENE' ), true );
ok( "and the shop's own scene text travels", false !== strpos( $scened, 'Slate surface' ), true );
// And the sentence added on top of the prompt in 4.322 is gone: an ordinary
// run is told what the photographs ARE, not what to make of them.
ok( 'no instruction of ours about what to make',
	false !== strpos( $plain, 'Make a NEW photograph' ), false );

echo "\nAnd what IS appended is on the screen, from the same function\n";
// The panel used to say "the product photographs, as real images" — a summary
// — while several hundred characters went out under it. It prints the note
// itself now, and it must be READ from the sender, never written beside it,
// or the screen and the request drift apart on the next edit.
$shown = DZE_Content::prompt_note( 'content_img_main_image' );
ok( 'an image prompt says what is appended to it', '' !== $shown, true );
ok( 'word for word, from the one function that sends it',
	$shown, trim( DZE_Content::sources_instruction( DZE_Content::source_cap(), null, 0, 0, false, 0 ) ) );
ok( 'and a text prompt is appended none of it',
	DZE_Content::prompt_note( 'content_title' ), '' );
ok( 'nor is a prompt this module does not own',
	DZE_Content::prompt_note( 'cat_desc' ), '' );

echo "\nWHERE THE PRODUCT BULK SCREEN LIVES\n";
// "Products AI bulk > toujours caché, introuvable dans aucun menu. L'onglet
// Done ici est pourtant extrêmement utile. Products, dans Content diagnostic,
// redirige vers Products AI bulk. Démèles ce bordel."
//
// One decision answers three questions at once — is the tab a view or a door,
// where does every link to this work point, and does the screen keep a menu
// entry of its own — and they must never disagree.
$GLOBALS['dze_diag_class'] = true;
ok( 'hosted where the diagnostic is there', DZE_Content::bulk_hosted(), true );
ok( 'and every link goes to that tab',
	false !== strpos( DZE_Content::bulk_url(), 'page=dazont-ecom-diagnostic&tab=products' ), true );
// A BOOKMARK STILL LANDS. The decision is split from the request, because a
// handler that ends the request cannot be tested.
ok( 'the old address is sent to the tab',
	DZE_Content::bulk_redirect( [ 'page' => DZE_Content::BULK_SLUG ] ), DZE_Content::bulk_url() );
ok( 'and every other page is left alone',
	DZE_Content::bulk_redirect( [ 'page' => 'edit.php' ] ), '' );
// WITH NOTHING TO HOST IT, the screen is its own page again — and keeps its
// menu entry, or the shop has a function it cannot reach from anywhere. That
// is the state it shipped in.
$GLOBALS['dze_diag_class'] = false;
ok( 'nothing hosting it, it stands alone', DZE_Content::bulk_hosted(), false );
ok( 'and links point at its own page',
	DZE_Content::bulk_url(), DZE_Content::bulk_page_url() );
ok( 'which is where it has always been',
	false !== strpos( DZE_Content::bulk_page_url(), 'page=dazont-content-bulk' ), true );
ok( 'and nothing is redirected away from it',
	DZE_Content::bulk_redirect( [ 'page' => DZE_Content::BULK_SLUG ] ), '' );
$GLOBALS['dze_diag_class'] = true;

echo "\nA BODY THAT MOVES TAKES ITS ASSETS WITH IT\n";
// "Bugé, aucun prompt à choisir. 2 produits dans la liste. C'est pourtant
// presque le même écran que sur les produits, individuellement."
//
// The bulk screen's assets were gated on ONE page hook — the standalone page
// and nothing else. Drawn as a tab of Content diagnostic the hook is that
// page's, so content-bulk.js was never enqueued: the prompt rows are built by
// that script, so the block came up empty, and the checkboxes it ticks came up
// unticked. The screen arrived as dead markup and said nothing.
// THE SCREEN IS DRAWN, not its helper called: what is asserted is that the
// BODY asks for what it needs, wherever it is drawn.
// Two products on the list, one short of two things and one short of nothing.
$GLOBALS['dze_list'] = [ 7, 8 ];
$GLOBALS['dze_todo'] = [
	7 => [ 'Gallery photographs — 0 of 3', 'Description — 84 of 120 words' ],
	8 => [],
];
ob_start();
DZE_Content::instance()->bulk_body( 'http://shop.test/screen' );
$dze_screen = (string) ob_get_clean();
ok( 'the screen draws',                 strlen( $dze_screen ) > 200, true );
$dze_asked = [];
foreach ( (array) $GLOBALS['enq'] as $dze_one ) {
	$dze_asked[ (string) $dze_one[0] ] = (array) $dze_one[1];
}
ok( 'the screen asks for the script that builds it',
	isset( $dze_asked['dze-content-bulk'] ), true );
// A DEPENDENCY THAT WAS NEVER ENQUEUED SILENTLY DROPS THE SCRIPT THAT NEEDS
// IT: WordPress prints nothing and says nothing.
ok( 'naming what it is built on',
	$dze_asked['dze-content-bulk'] ?? [], [ 'jquery', 'dze-photos', 'dze-paste-box' ] );
ok( 'the editor the reviewed texts are edited in', isset( $dze_asked['editor'] ), true );
ok( 'the box photographs are pasted into',         isset( $dze_asked['dze-paste-box'] ), true );
ok( 'and the media modal the pickers open',        isset( $dze_asked['media'] ), true );
// A BUTTON DRAWN IN JAVASCRIPT NEEDS ITS POPUP PRINTED ON THAT SCREEN.
ok( 'and the popup behind every "Prompt" button',  DZE_Prompts::$printed > 0, true );

echo "\nWHAT EACH PRODUCT IS SHORT OF, ON ITS OWN ROW\n";
// "Sur l'écran bulk, tu vas ajouter le diagnostic qui le concerne. Pour qu'on
// sache facilement quoi générer." The reading belongs to the PRODUCT, so it is
// the same answer the toolbox and the problem list print — three screens can
// never say three different things about one product.
ok( 'a row says what that product needs',
	false !== strpos( $dze_screen, 'Gallery photographs — 0 of 3' ), true );
ok( 'all of it, not the first line',
	false !== strpos( $dze_screen, 'Description — 84 of 120 words' ), true );
ok( 'read from the product itself',
	substr_count( $dze_screen, 'class="dze-cb-short' ), 2 );
// A ROW WITH NOTHING MISSING SAYS SO. Left blank it reads as a reading that
// did not happen, which is the one thing it must not look like.
ok( 'and a product short of nothing says so',
	false !== strpos( $dze_screen, 'Nothing missing' ), true );

// A CROSS-MODULE SURFACE IS GATED ON THE MODULE, never on the class: a class
// file always exists. With the diagnostic off the line is not there at all.
$GLOBALS['dze_diag_class'] = false;
( new ReflectionProperty( 'DZE_Content', 'bulk_products_cache' ) )->setValue( DZE_Content::instance(), null );
ob_start();
DZE_Content::instance()->bulk_body( 'http://shop.test/screen' );
$dze_off = (string) ob_get_clean();
ok( 'the reading goes with its module',
	false !== strpos( $dze_off, 'class="dze-cb-short' ), false );
ok( 'and the rows are still drawn',
	false !== strpos( $dze_off, 'Product 7' ), true );
$GLOBALS['dze_diag_class'] = true;

echo "\nONE TICK PER BLOCK, IN THE BLOCK'S OWN TITLE\n";
// "Pas de coche pour activer/désactiver tout en même temps. Je t'avais
// pourtant dit de le faire." Seven text prompts and no way to take the lot.
$dze_sec = new ReflectionMethod( 'DZE_Content', 'sec_open' );
$dze_sec->setAccessible( true );
$dze_draw_sec = static function ( array $tick ) use ( $dze_sec ): string {
	ob_start();
	$dze_sec->invoke( null, 'text', 'Texts', true, $tick );
	return (string) ob_get_clean();
};
$dze_with = $dze_draw_sec( [ 'all' => true, 'tip' => 'Tick every prompt in this block' ] );
ok( 'the switch is in the heading',
	(bool) preg_match( '#<h3 class="dze-sec-head".*?dze-sec-all.*?</h3>#s', $dze_with ), true );
// The SAME class the product toolbox uses, so the one handler in photos.js
// drives both — never a second one to keep in step.
ok( 'wearing the class the one handler listens for',
	false !== strpos( $dze_with, 'class="dze-sec-all"' ), true );
ok( 'inside a label the head click knows to ignore',
	false !== strpos( $dze_with, 'class="dze-sec-tick"' ), true );
// AND NOWHERE ELSE. Over a block holding one checkbox it would be a second
// control saying what the first already says — which is exactly what was
// taken off the images and price blocks of the toolbox.
ok( 'a block with nothing to take all of has none',
	false !== strpos( $dze_draw_sec( [] ), 'dze-sec-all' ), false );
ok( 'and it is still an ordinary section',
	false !== strpos( $dze_draw_sec( [] ), 'class="dze-sec-head"' ), true );
// A BLOCK'S OWN SWITCH LIVES THERE TOO — the shape the product toolbox uses
// for its images and price blocks. Inside the body instead, `countSec()` finds
// no head tick and draws "0 / 2" over a block that is switched on with two
// prompts laid out under it.
$dze_own = $dze_draw_sec( [ 'id' => 'dze-cb-image', 'on' => true, 'tip' => 'Generate images' ] );
ok( "a block's own switch is in the heading too",
	(bool) preg_match( '#<h3 class="dze-sec-head".*?id="dze-cb-image".*?</h3>#s', $dze_own ), true );
ok( 'keeping the id the screen already speaks',
	false !== strpos( $dze_own, 'id="dze-cb-image"' ), true );
ok( 'and its state',                    false !== strpos( $dze_own, ' checked' ), true );
ok( 'and it is not the take-all one',   false !== strpos( $dze_own, 'dze-sec-all' ), false );
// A STEP THAT IS NOT POSSIBLE IS DISABLED AND SAYS WHAT IS MISSING.
$dze_lock = $dze_draw_sec( [ 'id' => 'dze-cb-image', 'disabled' => true, 'tip' => 'No fal.ai key is saved' ] );
ok( 'a switch that cannot be used is disabled',
	false !== strpos( $dze_lock, ' disabled' ), true );
ok( 'and says what is missing',         false !== strpos( $dze_lock, 'No fal.ai key is saved' ), true );

// AND ON THE SCREEN ITSELF. This fake shop has no validated image prompt and
// no reviews module, so those two blocks are not drawn at all here — what is
// asserted on the real screen is the one block this fixture produces, and the
// three shapes above are the contract all of them are built on.
ok( 'the screen puts the price switch in its heading',
	(bool) preg_match( '#<h3 class="dze-sec-head".*?id="dze-cb-price".*?</h3>#s', $dze_screen ), true );
ok( 'and never twice',                  substr_count( $dze_screen, 'id="dze-cb-price"' ), 1 );
// The body must not keep a second copy of a switch that moved to the heading:
// the price block is cut at its own body, and each half asked separately.
$dze_price = substr( $dze_screen, (int) strpos( $dze_screen, 'data-sec="price"' ) );
$dze_price = substr( $dze_price, 0, (int) strpos( $dze_price, '</section>' ) );
[ $dze_head, $dze_bodyhalf ] = array_pad( explode( '<div class="dze-sec-body"', $dze_price, 2 ), 2, '' );
ok( 'the switch is in that block\'s heading',
	false !== strpos( $dze_head, 'id="dze-cb-price"' ), true );
ok( 'and nowhere in its body',
	false !== strpos( $dze_bodyhalf, 'id="dze-cb-price"' ), false );

echo "\nREFUSING IS NOT REMOVING\n";
// "Le bouton discard sur l'écran bulk devrait refuser les changements et reset
// le status des produits comme si rien n'avait été généré. Actuellement ils
// sont supprimés de la page bulk." Discard called the REMOVE path: saying "not
// this photograph" took the product off the very screen it was being worked
// on, and getting it back meant hunting it down and adding it again.
$GLOBALS['dze_list']  = [ 7, 8, 9 ];
$GLOBALS['dze_dropped'] = [];
ok( 'the products stay on the list',    DZE_Content::discard_products( [ 8 ] ), [ 7, 8, 9 ] );
ok( 'and what was waiting on them is thrown away',
	$GLOBALS['dze_dropped'], [ 8 ] );
DZE_Content::discard_products( [ 7, 9 ] );
ok( 'refusing several at once refuses each',
	$GLOBALS['dze_dropped'], [ 8, 7, 9 ] );
// The decision is split from the request, or it cannot be exercised at all.
$dze_h = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content.php' );
$dze_d = substr( $dze_h, (int) strpos( $dze_h, 'function discard_products' ) );
$dze_d = substr( $dze_d, 0, (int) strpos( $dze_d, "\n\t}" ) );
ok( 'and it never ends the request',    false !== strpos( $dze_d, 'wp_send_json' ), false );

echo "\nTHE BACKGROUND IS A PROPERTY OF THE PROMPT\n";
// "Image ugc generee dans l'outil bulk. Invraisemblable. C'est a cause de tes
// reglages caches ?!" It was: the scene was ONE answer for the whole shop,
// chosen on no screen that runs a prompt and attached to every one of them.
// The sources block then declares that image to be the surface, the background
// and the light of the photograph — so a prompt asking for a customer's own
// snapshot came back a white pack shot.
$GLOBALS['opts'] = [];
$GLOBALS['opts']['dze_content_settings'] = [
	'scenes'   => [
		[ 'name' => 'Studio backdrop', 'image' => 90, 'prompt' => '', 'default' => true ],
		[ 'name' => 'Slate', 'image' => 91, 'prompt' => '', 'default' => false ],
	],
	'registry' => [
		// Written before the field existed: it keeps what it has been running
		// on all along, or a shop updating would silently lose its backdrops.
		[ 'id' => 'old', 'name' => 'Pack shot', 'type' => 'image', 'output' => 'main', 'prompt' => 'P', 'tokens' => 400, 'enabled' => 1, 'valid' => 1 ],
		// Answered: no scene. An EMPTY key is an answer and is not overruled.
		[ 'id' => 'ugc', 'name' => 'Customer photo', 'type' => 'image', 'output' => 'gallery', 'prompt' => 'P', 'tokens' => 400, 'enabled' => 1, 'valid' => 1, 'scene' => '' ],
		// Answered: that one, by name — reordering the list must not move a
		// prompt onto a different background in silence.
		[ 'id' => 'slate', 'name' => 'On slate', 'type' => 'image', 'output' => 'gallery', 'prompt' => 'P', 'tokens' => 400, 'enabled' => 1, 'valid' => 1, 'scene' => 'Slate' ],
		// A scene deleted since: read as none, never as "the first one".
		[ 'id' => 'gone', 'name' => 'On something gone', 'type' => 'image', 'output' => 'gallery', 'prompt' => 'P', 'tokens' => 400, 'enabled' => 1, 'valid' => 1, 'scene' => 'Sand' ],
	],
];
( new ReflectionProperty( 'DZE_Content', 'registry_cache' ) )->setValue( null, null );
$dze_t = [];
foreach ( DZE_Content::image_templates() as $dze_one ) { $dze_t[ $dze_one['id'] ] = $dze_one; }
ok( 'a prompt written before the field keeps the shop default',
	$dze_t['old']['scene_i'] ?? 'missing', 0 );
ok( 'and it is named, not numbered',    $dze_t['old']['scene'] ?? '', 'Studio backdrop' );
ok( '"no scene" is an answer, and it is kept',
	$dze_t['ugc']['scene_i'] ?? 'missing', -1 );
ok( 'a named scene answers with its place',
	$dze_t['slate']['scene_i'] ?? 'missing', 1 );
ok( 'a scene deleted since is no scene',
	$dze_t['gone']['scene_i'] ?? 'missing', -1 );
ok( 'and the name is not thrown away with it',
	$dze_t['gone']['scene'] ?? '', 'Sand' );
// THE SCREEN CARRIES IT TO THE ROW. Each prompt option says which background
// it is shot on, and the menu beside it opens on nothing of its own — it used
// to open pre-selected on the shop's default, whatever prompt the row held.
$GLOBALS['dze_list'] = [ 7 ];
( new ReflectionProperty( 'DZE_Content', 'bulk_products_cache' ) )->setValue( DZE_Content::instance(), null );
ob_start();
DZE_Content::instance()->bulk_body( 'http://shop.test/screen' );
$dze_bulk = (string) ob_get_clean();
ok( 'the row is told which scene a prompt wants',
	false !== strpos( $dze_bulk, 'data-scene="1"' ), true );
ok( 'and which wants none',              false !== strpos( $dze_bulk, 'data-scene="-1"' ), true );
ok( 'the scene menu picks nothing on its own',
	false !== strpos( $dze_bulk, "<option value=\"-1\" selected" ), false );
ok( 'and it is on the prompt card that it is set',
	substr_count( $dze_bulk, 'dze-tpl-scene' ) > 0, true );
$GLOBALS['opts'] = [];

echo "\nHow many photographs of the product go with a request\n";
// A close-up of the fastenings, asked of a five-photograph product with two
// photographs sent, is a close-up of something the model has never seen.
// A part it has been shown is a part it does not have to invent.
$GLOBALS['opts'] = [];
ok( 'the shop sends what it has, not two', DZE_Content::source_cap(), 10 );
ok( 'never more than the request can carry',
	DZE_Content::source_cap() <= DZE_Content::MAX_SOURCES, true );
// And it stays the shop's to move, in both directions.
$GLOBALS['opts']['dze_content_settings'] = [ 'img_sources' => 3 ];
ok( 'a shop that chose its own figure keeps it', DZE_Content::source_cap(), 3 );
$GLOBALS['opts']['dze_content_settings'] = [ 'img_sources' => 99 ];
ok( 'and a figure beyond the ceiling is brought back',
	DZE_Content::source_cap(), DZE_Content::MAX_SOURCES );

echo "\nWhich work eats the budget\n";
// "Rajouter des infos quant à l'utilisation des crédits IA par fonctions.
// Pour savoir quels travaux bouffent quel budget." The table said what one
// unit costs; what it could not say is whether that is the thing to look at.
ok( 'a third of the month reads as a third', DZE_Ai_Usage::share_said( 10.0, 30.0 ), '33%' );
ok( 'and all of it as all of it',            DZE_Ai_Usage::share_said( 30.0, 30.0 ), '100%' );
// A COST THAT EXISTS IS NEVER PRINTED AS NOTHING. Rounded to whole points a
// real but small spend would read "0%", which is a figure saying the opposite
// of what it means.
ok( 'a small real cost is not nought',       DZE_Ai_Usage::share_said( 0.001, 30.0 ), '<1%' );
ok( 'a month with nothing in it has no share', DZE_Ai_Usage::share_said( 0.0, 0.0 ), '—' );
ok( 'and neither has a unit that cost nothing', DZE_Ai_Usage::share_said( 0.0, 30.0 ), '—' );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
