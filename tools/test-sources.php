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
function wp_kses_post( $s ) { return (string) $s; }
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
// WordPress takes this BOTH ways — an array of pairs, or one key and its
// value — and the plugin uses both. A fake that knows only the first turns a
// real call into nonsense and the check reads as a bug in the code.
function add_query_arg( $args, $url = '', $third = null ) {
	if ( ! is_array( $args ) ) {
		$args = [ (string) $args => $url ];
		$url  = (string) $third;
	}
	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args );
}
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
function get_the_post_thumbnail_url( ...$a ) { return ''; }
$GLOBALS['wpdb'] = new class {
	public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts'; public $prefix = 'wp_';
	public function prepare( $q, ...$a ) { $GLOBALS['sql_args'] = $a; return $q; }
	public function get_var( $q ) { return 0; }
	public function get_results( $q, $m = null ) { return []; }
	// The one query the paste box makes: which of these ids are products.
	// It answers from what the fake shop HOLDS, so a paste that never reached
	// the query cannot pass by accident.
	public function get_col( $q ) {
		$shop = (array) ( $GLOBALS['dze_products'] ?? [] );
		return $shop ? array_values( array_intersect( (array) ( $GLOBALS['sql_args'] ?? [] ), $shop ) ) : [];
	}
};
// The AJAX answer is an exit: caught, so the handler can be run for real and
// its answer read — a control is tested on what it DOES.
class DZE_Json_Sent extends Exception {
	public function __construct( public $payload = null, public bool $ok = true ) { parent::__construct( 'json' ); }
}
function wp_send_json_success( $d = null ) { throw new DZE_Json_Sent( $d, true ); }
function wp_send_json_error( $d = null, $c = 0 ) { throw new DZE_Json_Sent( $d, false ); }
function check_ajax_referer( ...$a ) { return true; }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
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
		if ( in_array( $id, (array) ( $GLOBALS['dze_off'] ?? [] ), true ) ) { return false; }
		return 'diagnostic' === $id ? ! empty( $GLOBALS['dze_diag_class'] ) : true;
	}
}
function get_edit_term_link( $id, $tax = '' ) { return 'http://shop.test/term/' . (int) $id; }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); }
/**
 * The two OTHER halves of the common register, answering as they really do.
 *
 * The register is a READER over stores each module owns. Stubbed away, it
 * would be tested against nothing but its own first third — which is exactly
 * the state the screen was in when the shop said "je ne vois que les produits
 * modifiés par l'écran bulk".
 */
class DZE_Queue {
	public static array $rows = [];
	public static function kinds() {
		return [
			'cat_desc'     => [ 'label' => 'Category description' ],
			'product_shot' => [ 'label' => 'Product photograph' ],
		];
	}
	public static function applied_rows( $limit = 200 ) { return self::$rows; }
	public static function label_for( $kind, $id ) { return ( 0 === strpos( $kind, 'cat_' ) ? 'Category ' : 'Product ' ) . (int) $id; }
	public static function decided_by( $uid ) { return $uid ? 'Marie' : ''; }
}
class DZE_Translate {
	public static array $rows = [];
	public static function log_entries() { return self::$rows; }
	public static function obj_edit_url( $o ) { return 'http://shop.test/tr/' . (int) ( $o['id'] ?? 0 ); }
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
// THE DONE TAB, DRAWN IN ITS OWN PROCESS. `bulk_mode()` keeps its answer in a
// static — one request draws one screen, which is right for the shop and means
// a gate cannot draw both. So the second tab is asked for the way the browser
// gates already ask for a screen: by running this file again.
if ( in_array( '--dump-log', (array) $argv, true ) ) {
	$_GET['dze_log'] = 1;
	// A register with something in it: a Done tab holding nothing draws no row
	// and would prove nothing about the column under its heading.
	$GLOBALS['opts']['dze_content_log'] = [
		[ 'id' => 7, 'texts' => 2, 'images' => 1, 'status' => 'applied', 'by' => 0, 'time' => time() ],
	];
	ob_start();
	DZE_Content::instance()->bulk_body( 'http://dze.test/screen' );
	echo (string) ob_get_clean();
	exit( 0 );
}
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
// THE OBJECT'S ID, ON EVERY LIST THAT NAMES OBJECTS. "Il manque l'ID produit
// sur ces pages ! Très important." Two products called "Tactical Backpack 45L"
// are told apart by nothing else, and every other tool the shop uses to talk
// about a product speaks in ids.
// A TABLE IS TESTED ON THE ORDER OF ITS COLUMNS, not only their presence: the
// heading is declared in one place and the cells in another, and out of step by
// one every row prints its value under the wrong title.
ok( 'there is an ID column, once',
	substr_count( $dze_screen, 'class="dze-objid-th"' ), 1 );
ok( 'and it comes right after the product it names',
	(bool) preg_match( '/Product<\/th>\s*<th class="dze-objid-th">ID</s', $dze_screen ), true );
ok( 'every row has a cell under it',
	substr_count( $dze_screen, 'class="dze-objid-td"' ), 2 );
ok( 'holding that row\'s own id',
	(bool) preg_match( '/data-id="7".*?dze-objid-td[^>]*><code[^>]*>7</s', $dze_screen ), true );
// IT IS NOT A LINK: the name beside it is already the way in, and a second
// link to the same place is a second thing to aim at.
ok( 'the id is not a second link to the same place',
	(bool) preg_match( '/<a[^>]*>\s*<code class="dze-objid"/', $dze_screen ), false );
// AND THE ROW STILL SPANS THE WHOLE TABLE: a colspan left behind is a panel
// that stops one column short and a table that looks broken.
ok( 'the panel row spans every column',
	false !== strpos( $dze_screen, 'colspan="6"' ), true );
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

// =============================================================================
// THE COMMON REGISTER
//
// "Products > Done > ici je ne vois que les produits modifiés par l'écran bulk.
// Qu'en est-il des produits modifiés individuellement ? Ce serait bien d'avoir
// un registre commun."
//
// Two faults in one report. The register was written by the JAVASCRIPT of four
// screens, so everything written anywhere else — the fast main-image lane, the
// reframe bench, the variation images — happened and left no trace at all. And
// what it held was products and nothing else, while the plugin also writes
// categories, articles and translations.
// =============================================================================
echo "\nEvery write records itself, and the counts only grow\n";
$GLOBALS['opts'][ DZE_Content::OPT_LOG ] = [];
DZE_Content::log_add( 42, 1, 0 );
DZE_Content::log_add( 42, 0, 1 );
DZE_Content::log_add( 42, 0, 1 );
$log = DZE_Content::log_entries();
ok( 'a product written to three times is one line', count( $log ), 1 );
// THEY USED TO BE REPLACED by whatever the last call claimed — right while one
// screen counted a whole run, and wrong the moment each write counts itself.
ok( 'and the line holds everything written to it',
	[ (int) $log[0]['texts'], (int) $log[0]['images'] ], [ 1, 2 ] );
// A decision taken afterwards stamps the row without inventing figures.
DZE_Content::log_add( 42, 0, 0, 'applied' );
$log = DZE_Content::log_entries();
ok( 'a decision does not add figures of its own',
	[ (int) $log[0]['texts'], (int) $log[0]['images'] ], [ 1, 2 ] );
ok( 'and it is still one line',          count( $log ), 1 );
// A refusal is a decision like any other and keeps what was written: both
// facts are true, and hiding either is half an answer.
DZE_Content::log_add( 42, 0, 0, 'dropped' );
$log = DZE_Content::log_entries();
ok( 'a refusal is recorded as the last decision', (string) $log[0]['status'], 'dropped' );
ok( 'and what had been written is not forgotten',
	false !== strpos( DZE_Content::wrote_said( 1, 2, 'dropped' ), 'refused' ), true );
ok( 'a product nothing was written to says so',
	DZE_Content::wrote_said( 0, 0 ), 'nothing written' );

echo "\nAnd the two funnels every write passes through record it\n";
// A text lands on a product in apply_value() and nowhere else; a photograph is
// placed in attach_file() and nowhere else. Hooking each SCREEN is a list
// somebody has to keep, and the one forgotten is always the bug.
$dze_src  = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content.php' );
$dze_text = strstr( $dze_src, 'private function apply_value(' );
$dze_text = false === $dze_text ? '' : substr( $dze_text, 0, 1200 );
ok( 'a text written records itself',      false !== strpos( $dze_text, 'log_add' ), true );
$dze_img  = strstr( $dze_src, 'public function attach_file(' );
$dze_img  = false === $dze_img ? '' : substr( $dze_img, 0, 4000 );
ok( 'a photograph placed records itself', false !== strpos( $dze_img, 'log_add' ), true );
// AND THE SCREENS NO LONGER COUNT IT TOO. Left in both places, every run would
// be counted twice — once as it was written, once as the screen claimed it.
foreach ( [ 'content.js', 'content-bulk.js' ] as $dze_f ) {
	$dze_js = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/' . $dze_f );
	preg_match_all( '/dze_content_logged[^}]*\}/', $dze_js, $dze_m );
	$dze_claims = 0;
	foreach ( (array) ( $dze_m[0] ?? [] ) as $dze_one ) {
		if ( preg_match( '/\btexts\s*:/', $dze_one ) || preg_match( '/\bimages\s*:/', $dze_one ) ) { $dze_claims++; }
	}
	ok( $dze_f . ' claims no counts of its own', $dze_claims, 0 );
}

echo "\nThe register holds everything the plugin writes, not only products\n";
$GLOBALS['opts'][ DZE_Content::OPT_LOG ] = [];
DZE_Content::log_add( 42, 2, 1 );
DZE_Queue::$rows = [
	[ 'kind' => 'cat_desc', 'object_id' => 7, 'when' => time() - 60, 'by' => 3 ],
];
DZE_Translate::$rows = [
	[ 'ref' => 'term:9:product_cat', 'kind' => 'term', 'type' => 'product_cat', 'id' => 9,
	  'langs' => [ 'fr', 'de' ], 'time' => time() - 30, 'by' => 0, 'title' => 'Balaclavas' ],
];
$reg = DZE_Content::register();
ok( 'a product is in it',      1, count( array_filter( $reg, static fn( $r ) => 'Product' === $r['what'] ) ) );
ok( 'a category is in it',     1, count( array_filter( $reg, static fn( $r ) => 'Category description' === $r['what'] ) ) );
ok( 'a translation is in it',  1, count( array_filter( $reg, static fn( $r ) => 'Translation' === $r['what'] ) ) );
// NEWEST FIRST, across all three: a register sorted per store is three lists
// printed one after another, which is what it was.
ok( 'and the newest is first', $reg[0]['what'], 'Product' );
ok( 'each says what was written',
	[ $reg[0]['said'], $reg[1]['said'], $reg[2]['said'] ],
	[ '2 texts · 1 image', 'FR · DE', 'Written to the page' ] );
// A LINE OPENS ON ITS OWN OBJECT — never on a settings page, never on nothing.
ok( 'the category line goes to the category', $reg[2]['url'], 'http://shop.test/term/7' );
ok( 'and only a product carries a panel',
	[ $reg[0]['pid'], $reg[1]['pid'], $reg[2]['pid'] ], [ 42, 0, 0 ] );
// WHO SAID YES, and an automatic pass has nobody to name.
ok( 'a decision carries its person', (int) $reg[2]['by'], 3 );
ok( 'and an automatic one carries nought', (int) $reg[1]['by'], 0 );

// A MODULE SWITCHED OFF CONTRIBUTES NOTHING, rather than erroring: the
// register is a question, and a question about a function the shop has not got
// has no answer.
$GLOBALS['dze_off'] = [ 'queue', 'translate' ];
$reg = DZE_Content::register();
ok( 'with those modules off, only products are left', count( $reg ), 1 );
ok( 'and nothing was raised asking',  $reg[0]['what'], 'Product' );
$GLOBALS['dze_off'] = [];

// AND THE TAB'S FIGURE IS THE LIST UNDER IT. Counting only the products, the
// badge would disagree with its own screen every day.
ok( 'the Done tab counts the whole register',
	(int) DZE_Content::screen_counts()['log'], count( DZE_Content::register() ) );
// AND THE DONE TAB CARRIES IT TOO — drawn, never only counted: a figure on a
// tab proves nothing about the rows under it.
$dze_log = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $dir ) . ' --dump-log 2>/dev/null' );
ok( 'the Done tab has the same column',
	substr_count( $dze_log, 'class="dze-objid-th"' ), 1 );
ok( 'right after what it names',
	(bool) preg_match( '/What<\/th>\s*<th class="dze-objid-th">ID</s', $dze_log ), true );
ok( 'with a cell under it carrying the row\'s own id',
	(bool) preg_match( '/<tr data-id="(\d+)">[\s\S]*?<\/td>\s*<td class="dze-objid-td"><code[^>]*>\1</', $dze_log ), true );

echo "\nTHE LIST HAS A CEILING, AND THE SCREEN SAYS SO\n";
// "Ici il faut limiter à x produits. Calcules toi même la capacité limite. Il
// faudra afficher ça quelque part." The figure is computed and written down on
// the constant itself; what is gated here is that it BINDS — on every way in,
// in one place — and that nothing is ever swallowed to keep it.
$dze_cap = DZE_Content::list_max();
ok( 'the list has a stated ceiling',    $dze_cap > 0, true );

// 1. THE WRITER KEEPS IT, because three ways in would otherwise be three
//    ceilings to keep in step.
$GLOBALS['dze_list'] = [];
$dze_many = range( 1000, 1000 + $dze_cap + 49 );   // fifty too many
$dze_over = DZE_Content::set_bulk_list( $dze_many );
ok( 'a list over the ceiling is cut to it',
	count( DZE_Content::bulk_list() ), $dze_cap );
ok( 'and it says how many it refused',  $dze_over, 50 );
ok( 'what was already there is what stays',
	DZE_Content::bulk_list()[0], 1000 );
ok( 'and the overflow is what went',
	in_array( 1000 + $dze_cap + 49, DZE_Content::bulk_list(), true ), false );
// A list inside the ceiling is untouched and refuses nothing: a writer that
// reported a refusal on an ordinary save would put a warning on every screen.
$GLOBALS['dze_list'] = [];
ok( 'an ordinary list refuses nothing',  DZE_Content::set_bulk_list( [ 7, 8, 9 ] ), 0 );

// 2. A PASTED COLUMN IS ONE STRING. Sent as one form field per id it was cut
//    off at PHP's max_input_vars — a thousand, silently — which is the
//    swallowing this box promises never to do.
$dze_col = DZE_Content::paste_ids( "1024\n1025\r\n#1031, 1042\t1055\n\n1025" );
ok( 'a pasted column is read as ids',    $dze_col, [ 1024, 1025, 1031, 1042, 1055 ] );
ok( 'and a column of two thousand keeps every one of them',
	count( DZE_Content::paste_ids( implode( "\n", range( 5000, 6999 ) ) ) ), 2000 );

// 3. THE PASTE BOX, RUN FOR REAL: the handler, the query, the answer. A column
//    longer than the list adds what fits and NAMES what it could not take.
$GLOBALS['dze_list']     = [];
$GLOBALS['dze_products'] = range( 2000, 2000 + $dze_cap + 9 ); // ten too many
$_POST = [
	'do'      => 'add',
	'paste'   => implode( ' ', array_merge( range( 2000, 2000 + $dze_cap + 9 ), [ 4242 ] ) ),
	'replace' => 1,
];
$dze_ans = [];
try { DZE_Content::instance()->ajax_bulk_list(); } catch ( DZE_Json_Sent $e ) { $dze_ans = (array) $e->payload; }
ok( 'the paste fills the list to the ceiling',
	count( DZE_Content::bulk_list() ), $dze_cap );
ok( 'and says how many it could not take', (int) ( $dze_ans['over'] ?? -1 ), 10 );
ok( 'naming the ceiling itself',           (int) ( $dze_ans['overMax'] ?? 0 ), $dze_cap );
ok( 'what it added is what really landed', (int) ( $dze_ans['added'] ?? -1 ), $dze_cap );
ok( 'and an id that is not a product is still reported',
	(int) ( $dze_ans['unknownN'] ?? 0 ), 1 );
$_POST = [];
$GLOBALS['dze_products'] = [];

// 4. A SELECTION HANDED OVER REDIRECTS, so the figure travels in the address
//    and the screen it lands on reads it.
$GLOBALS['dze_list'] = [];
$dze_to = DZE_Content::instance()->handle_bulk_action( 'back', 'dze_ai_content', range( 3000, 3000 + $dze_cap + 4 ) );
ok( 'a bulk action over the ceiling says so in its address',
	(bool) preg_match( '/dze_over=5(?:&|$)/', $dze_to ), true );
ok( 'and one within it says nothing',
	false !== strpos( DZE_Content::bulk_url( 0 ), 'dze_over' ), false );

// 5. AND THE SCREEN SAYS IT WHERE PRODUCTS ARE ADDED — before the paste, not
//    after it. A ceiling nobody is told about is a screen that refuses work
//    for reasons of its own.
$GLOBALS['dze_list'] = [ 7, 8 ];
$GLOBALS['dze_todo'] = [];
ok( 'the room left is the ceiling less the list',
	DZE_Content::list_room(), $dze_cap - 2 );
ob_start(); DZE_Content::instance()->bulk_body( 'http://dze.test/screen' ); $dze_cap_html = (string) ob_get_clean();
ok( 'the paste box states the room and the ceiling',
	false !== strpos( $dze_cap_html, 'Room for ' . number_format_i18n( $dze_cap - 2 ) . ' more products' ), true );
ok( 'and names the ceiling in the same line',
	(bool) preg_match( '/Room for [\d,]+ more products — this list holds ' . number_format_i18n( $dze_cap ) . ' at a time/', $dze_cap_html ), true );
// A full list says WHICH state it is in, and the button that cannot act is
// not left looking as though it could.
$GLOBALS['dze_list'] = range( 9000, 8999 + $dze_cap );
ob_start(); DZE_Content::instance()->bulk_body( 'http://dze.test/screen' ); $dze_full_html = (string) ob_get_clean();
ok( 'a full list says it is full',
	false !== strpos( $dze_full_html, 'The list is full at ' . number_format_i18n( $dze_cap ) . ' products' ), true );
ok( 'and the button that cannot act is disabled',
	(bool) preg_match( '/id="dze-cb-pasteadd"\s*disabled/', $dze_full_html ), true );
$GLOBALS['dze_list'] = [ 7, 8 ];

echo "\nA LIST OF WHAT WAS WRITTEN HOLDS WHAT WAS WRITTEN\n";
// "Nothing written sur les produits avec le module, c'est une raison pour ne
// pas afficher le produit dans la liste historique." A refusal, or a product
// taken off the list before anything was made, wrote not one word — and a
// register made mostly of those is one nobody reads to the end.
$GLOBALS['opts'][ DZE_Content::OPT_LOG ] = [];
DZE_Content::log_add( 42, 2, 1 );              // really written to
DZE_Content::log_add( 43, 0, 0, 'dropped' );   // refused: nothing written
DZE_Content::log_add( 44, 0, 0, 'applied' );   // decided, nothing written
DZE_Queue::$rows = [];
DZE_Translate::$rows = [
	[ 'ref' => 'term:9:product_cat', 'kind' => 'term', 'type' => 'product_cat', 'id' => 9,
	  'langs' => [], 'time' => time(), 'by' => 0, 'title' => 'Nothing translated' ],
];
$reg = DZE_Content::register();
ok( 'only what was written is listed',  count( $reg ), 1 );
ok( 'and it is the one that was',       (int) $reg[0]['pid'], 42 );
// THE ROW IS KEPT, never deleted: a decision is signed, and a refusal is still
// the record that somebody looked and said no.
ok( 'the quiet ones are counted, not thrown away', DZE_Content::register_quiet(), 3 );
ok( 'and asked for, they are all there',           count( DZE_Content::register( 200, true ) ), 4 );
// A row says whether it wrote by a FLAG, never by matching a sentence — that
// sentence is translated on half the shops that will read this screen.
ok( 'each row carries the answer as a flag',
	[ $reg[0]['wrote'], DZE_Content::register( 200, true )[1]['wrote'] ], [ true, false ] );
// And the tab still counts what is under it.
ok( 'the tab counts the shown list, not the hidden one',
	(int) DZE_Content::screen_counts()['log'], 1 );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
