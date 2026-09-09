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
