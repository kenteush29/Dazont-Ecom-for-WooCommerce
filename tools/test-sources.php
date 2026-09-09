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
function wp_localize_script( ...$a ) {}
function wp_enqueue_editor() { $GLOBALS['enq'][] = [ 'editor', [] ]; }
function wp_enqueue_media() { $GLOBALS['enq'][] = [ 'media', [] ]; }
function wp_create_nonce( $a = '' ) { return 'n'; }
function plugins_url( $p = '', $f = '' ) { return 'http://shop.test/' . $p; }
function wp_script_is( ...$a ) { return false; }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
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
function wc_get_product( $id ) { return null; }
class DZE_Marketing_Ai { const MENU_SLUG = 'dazont-ecom-ai'; public static function get_settings() { return []; } public static function api_key() { return 'k'; } }
function get_current_user_id() { return 1; }
function get_user_meta( ...$a ) { return $GLOBALS['dze_list'] ?? []; }
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
}
/** The screen that may host the product bulk work — switched on, or off. */
class DZE_Diagnostic { const MENU_SLUG = 'dazont-ecom-diagnostic'; }
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

echo "\nONE TICK PER BLOCK, IN THE BLOCK'S OWN TITLE\n";
// "Pas de coche pour activer/désactiver tout en même temps. Je t'avais
// pourtant dit de le faire." Seven text prompts and no way to take the lot.
$dze_sec = new ReflectionMethod( 'DZE_Content', 'sec_open' );
$dze_sec->setAccessible( true );
$dze_draw_sec = static function ( bool $all ) use ( $dze_sec ): string {
	ob_start();
	$dze_sec->invoke( null, 'text', 'Texts', true, $all );
	return (string) ob_get_clean();
};
$dze_with = $dze_draw_sec( true );
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
	false !== strpos( $dze_draw_sec( false ), 'dze-sec-all' ), false );
ok( 'and it is still an ordinary section',
	false !== strpos( $dze_draw_sec( false ), 'class="dze-sec-head"' ), true );

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
