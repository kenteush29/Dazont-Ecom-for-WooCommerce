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
// The shop waits nearly two minutes for a picture; a gate proving what happens
// when that runs out waits one second.
define( 'DZE_FAL_WAIT', 1 );

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
// The footer hooks are RECORDED, not thrown away: a button drawn on a screen
// whose popup is never printed is a button that does nothing and says nothing,
// and that has shipped here before. The gate asserts the screen ASKED for it,
// and then runs what it asked for to get the markup the browser presses.
function add_action( $hook, $fn = null, ...$rest ) {
	if ( 0 === strpos( (string) $hook, 'admin_footer' ) && is_callable( $fn ) ) {
		$GLOBALS['dze_footer'][] = $fn;
	}
}
function add_filter( ...$a ) {}
function remove_filter( ...$a ) {}
function apply_filters( $t, $v = null, ...$a ) { return $v; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
// REAL TRANSIENTS from here down. Stubbed to false/no-op, the hourly image
// counters read nought for ever: every check on them would pass on a guard
// that does nothing. The store starts empty, which is what a fresh hour is.
$GLOBALS['tr'] = [];
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; }

// THE PROVIDER, ANSWERING WHATEVER THIS GATE NEEDS IT TO. fal_generate() is
// where an image request meets the outside world, and nothing had ever run it:
// its failure paths were read by eye.
//
// IT SPEAKS THE QUEUE PROTOCOL, because that is what the shop speaks now: a
// submit that answers with an id, a status that is polled, and a result that
// is fetched. The whole reason for the move is here — a job fal ACCEPTED is
// billed whether or not this site waits long enough to see the picture.
$GLOBALS['fal_say'] = [
	'code'   => 200,
	'body'   => '{"request_id":"req-1","status_url":"https://queue.fal.run/fal-ai/nano-banana-2/edit/requests/req-1/status","response_url":"https://queue.fal.run/fal-ai/nano-banana-2/edit/requests/req-1"}',
	'status' => 'COMPLETED',
	'result' => '{"images":[{"url":"https://fal.media/x.jpg"}]}',
	'units'  => '1',
];
$GLOBALS['fal_sent'] = [];
$GLOBALS['fal_got']  = [];
function wp_remote_post( $url, $args = [] ) {
	$GLOBALS['fal_sent'][] = [ 'url' => $url ] + $args;
	if ( ! empty( $GLOBALS['fal_say']['wp_error'] ) ) { return new WP_Error( 'http', $GLOBALS['fal_say']['wp_error'] ); }
	return [ 'response' => [ 'code' => $GLOBALS['fal_say']['code'] ], 'body' => $GLOBALS['fal_say']['body'] ];
}
function wp_remote_get( $url, $args = [] ) {
	$GLOBALS['fal_got'][] = (string) $url;
	// The status call and the result call are two different questions asked of
	// two different addresses, and a stub that answers both the same way could
	// never be red on a job that is still running.
	if ( '/status' === substr( (string) $url, -7 ) ) {
		return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'status' => $GLOBALS['fal_say']['status'] ?? 'COMPLETED' ] ) ];
	}
	return [ 'response' => [ 'code' => 200 ], 'body' => (string) ( $GLOBALS['fal_say']['result'] ?? '' ) ];
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_header( $r, $h ) { return 'x-fal-billable-units' === $h ? ( $GLOBALS['fal_say']['units'] ?? '' ) : ''; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
if ( ! function_exists( 'taxonomy_exists' ) ) { function taxonomy_exists( $t ) { return false; } }
class WP_Error {
	private $msg;
	public function __construct( $c = '', $m = '' ) { $this->msg = (string) $m; }
	public function get_error_message() { return $this->msg; }
}
class DZE_Health { public static function log( ...$a ) {} }
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
function esc_js( $s ) { return addslashes( (string) $s ); }
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $g ) . '" />'; }
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
	public function get_gallery_image_ids() { return $GLOBALS['gallery'][ $this->id ] ?? []; }
	public function get_description() { return $GLOBALS['wc_desc'][ $this->id ] ?? ''; }
	public function get_short_description() { return ''; }
}
function wc_get_product( $id ) { return new WC_Product( $id ); }
function wc_placeholder_img_src() { return 'http://shop.test/ph.png'; }
// A REAL META STORE, because a stub that always answers the same cannot be red
// on a store that is supposed to shrink. Empty by default, so every check
// written against the old blind stub reads exactly what it read before.
$GLOBALS['dze_meta'] = [];
function get_post_meta( $id, $key = '', $single = false ) {
	$v = $GLOBALS['dze_meta'][ (int) $id ][ (string) $key ] ?? null;
	if ( null === $v ) { return $single ? '' : []; }
	return $single ? $v : [ $v ];
}
function update_post_meta( $id, $key, $v ) { $GLOBALS['dze_meta'][ (int) $id ][ (string) $key ] = $v; return true; }
$GLOBALS['mai'] = [];
class DZE_Marketing_Ai { const MENU_SLUG = 'dazont-ecom-ai'; public static function get_settings() { return $GLOBALS['mai']; } public static function api_key() { return 'k'; } public static function shop_profile() { return 'Online shop selling tactical gear.'; } public static function tab_links() { return []; }
	// Claude reading one picture: what the reader (read_picture()) asks, recorded, and what it answers.
	public static function complete_with_images( ...$a ) { $GLOBALS['mai_vision'][] = $a; if ( ! empty( $GLOBALS['mai_view_fail'] ) ) { throw new RuntimeException( 'down' ); } return (string) ( $GLOBALS['mai_view'] ?? '' ); } }
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
function delete_post_meta( $id, $key = '', $v = '' ) {
	$GLOBALS['dze_dropped'][] = (int) $id;
	unset( $GLOBALS['dze_meta'][ (int) $id ][ (string) $key ] );
	return true;
}
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); return true; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function get_posts( ...$a ) { return []; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function get_the_title( $id ) { return 'P' . (int) $id; }
function wp_get_attachment_image_url( ...$a ) { return ''; }
function get_post_thumbnail_id( ...$a ) { return (int) ( $GLOBALS['thumbs'][ (int) ( $a[0] ?? 0 ) ] ?? 0 ); }
function get_the_post_thumbnail_url( ...$a ) { return ''; }
$GLOBALS['wpdb'] = new class {
	public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts'; public $prefix = 'wp_'; public $termmeta = 'wp_termmeta';
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
function wp_slash( $v ) { return $v; }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }
function absint( $v ) { return abs( (int) $v ); }
/** The popup behind every "✎ Prompt" drawn in JavaScript on that screen. */
class DZE_Prompts {
	public static $printed = 0;
	public static function print_assets() { self::$printed++; }
	public static function the_button( $id, $label = '' ) { printf( '<button class="dze-prompt-peek" data-prompt="%s">%s</button>', $id, $label ?: 'Prompt' ); }
	public static function the_data( $id ) {}
	public static function card_open( ...$a ) {}
	public static function card_close( ...$a ) {}
	// The trace files every call under the prompts it recognises. Missing, the
	// first real call dies inside `trace()` and the failure reads as a fault in
	// the code under test.
	public static function ids_in( $text ) { return []; }
}
/**
 * The screen that may host the product bulk work — switched on, or off — and
 * the reading that belongs to a product, which three screens now print.
 */
class DZE_Diagnostic {
	const NONCE = 'dze_diag';
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
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_parse_args( $a, $d = [] ) { return array_merge( (array) $d, (array) $a ); }
$GLOBALS['opts'] = [];

// The class is split across a trait; both halves are the shipped files, never
// a copy of the function under test written into this one.
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
require __DIR__ . '/../' . $dir . '/includes/class-hub.php';
require __DIR__ . '/../' . $dir . '/includes/class-ai-usage.php';
require __DIR__ . '/../' . $dir . '/includes/class-content-ajax.php';
// The real key helper: the hint under the fal.ai field is what is being
// tested, and a stub of the thing under test is a gate that proves nothing.
require __DIR__ . '/../' . $dir . '/includes/class-api-keys.php';
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
if ( in_array( '--dump-settings', (array) $argv, true ) ) {
	// The settings intro names the bulk screen from the catalogue.
	if ( ! class_exists( 'DZE_Screens' ) ) { require __DIR__ . '/../' . $dir . '/includes/class-screens.php'; }
	if ( ! function_exists( 'wp_get_attachment_image' ) ) { function wp_get_attachment_image( $id, $s = 'thumbnail', $icon = false, $attr = [] ) { return '<img class="' . esc_attr( $attr['class'] ?? '' ) . '" src="" alt="scene ' . (int) $id . '" width="64" height="64" />'; } }
	if ( ! function_exists( 'wp_get_attachment_image_url' ) ) { function wp_get_attachment_image_url( $id, $s = 'thumbnail' ) { return 'http://dze.test/scene-' . (int) $id . '.jpg'; } }
	if ( ! function_exists( 'wp_enqueue_media' ) ) { function wp_enqueue_media( ...$a ) {} }
	if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = 'Save Changes', $type = 'primary', $n = 'submit', $wrap = true ) { echo '<p class="submit"><button class="button button-primary">' . esc_html( $t ) . '</button></p>'; } }
	// THE SHOP CONTENT TAB, FOR THE EYE: the prompt registry and the scenes,
	// drawn by the real renderer over the same fake shop the bulk screen uses.
	$GLOBALS['opts']['dze_content_settings'] = [
		'fal_key'  => 'fake-fal-key',
		'scenes'   => [
			[ 'name' => 'Studio backdrop', 'image' => 90, 'prompt' => '', 'default' => true ],
			[ 'name' => 'Slate', 'image' => 91, 'prompt' => '', 'default' => false ],
		],
		'registry' => [
			[ 'id' => 'main1', 'name' => 'Pack shot', 'type' => 'image', 'output' => 'main', 'prompt' => 'P', 'tokens' => 400, 'enabled' => 1, 'valid' => 1, 'scene' => 'Studio backdrop' ],
			[ 'id' => 'ugc', 'name' => 'Customer photo', 'type' => 'image', 'output' => 'gallery', 'prompt' => 'P', 'tokens' => 400, 'enabled' => 1, 'valid' => 1, 'scene' => '' ],
			[ 'id' => 'desc', 'name' => 'Description', 'type' => 'text', 'output' => 'post_content', 'prompt' => 'P', 'tokens' => 400, 'enabled' => 1, 'valid' => 1 ],
		],
	];
	ob_start();
	DZE_Content::instance()->render_settings_section();
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
	$GLOBALS['loc']        = [];
	$GLOBALS['dze_footer'] = [];
	ob_start();
	DZE_Content::instance()->bulk_body( 'http://dze.test/screen' );
	$dze_html = (string) ob_get_clean();
	// EVERYTHING THE SCREEN ASKED THE FOOTER FOR — the prompt popup among it.
	// Run here rather than pasted into the harness by hand: a gate that prints
	// the popup itself proves the popup works and nothing about whether the
	// screen ever asks for it.
	ob_start();
	foreach ( (array) $GLOBALS['dze_footer'] as $dze_fn ) { call_user_func( $dze_fn ); }
	$dze_html .= (string) ob_get_clean();
	echo wp_json_encode( [ 'html' => $dze_html, 'cfg' => $GLOBALS['loc']['dzeContentBulk'] ?? [] ] );
	exit( 0 );
}

echo "\nWHAT IS SAID ABOUT THE PHOTOGRAPHS IS THE SHOP'S, NOT OURS\n";
// « Tu as encore caché du texte : IMAGES 1 TO 5 ARE ONE SINGLE PRODUCT… J'aurais
// mis : Les images fournies représentent un seul et même produit. L'image 1 est
// l'image principale… Les photos font foi devant la description produit. »
// Every note is a setting now, with the owner's own wording as the default of
// the ordinary run, and his words replace ours wherever he writes them.
$dze_cat = DZE_Content::photo_note_catalog();
$plain   = DZE_Content::sources_instruction( 3, null, 0, 0, false );
ok( 'several photographs: the owner\'s own words, by default', trim( $plain ), $dze_cat['many']['default'] );
ok( 'which name image 1 as the main image',          false !== strpos( $plain, 'Image 1 is the main image' ), true );
ok( 'and let the photographs win over the description',
	false !== strpos( $plain, 'The photographs take precedence over the product description' ), true );
ok( 'and warn against moving a detail',              false !== strpos( $plain, 'never to move a detail' ), true );
$dze_one = DZE_Content::sources_instruction( 1, null, 0, 0, false, true );
ok( 'one photograph has a note of its own',          trim( $dze_one ), $dze_cat['one']['default'] );
// « J'aimerais re-générer des images basées sur l'image en pièce jointe » — a
// supplier's « Detailed introduction » sheet, eight panels and their captions.
// Told « keep the same view, never turn the product », the model could only
// copy the sheet. What to make is the prompt's to say; the note says what the
// picture IS, and that its captions are not part of the product.
ok( 'it no longer forbids the prompt a new view',    false !== strpos( $dze_one, 'may NOT turn' ), false );
ok( 'and leaves out the text laid over a supplier sheet',
	false !== strpos( $dze_one, 'text, captions, arrows or several pictures laid out side by side' ), true );
ok( 'a product with a single photograph is read the same way',
	DZE_Content::sources_instruction( 1, null, 0, 0, false ), $dze_one );
ok( 'a picture being retouched has its own note',
	trim( DZE_Content::sources_instruction( 1, null, 0, 0, true ) ), $dze_cat['edit']['default'] );
// THE SHOP'S OWN WORDS REPLACE OURS — and an emptied note is not an empty
// message to the model: it goes back to the default.
$dze_keep_settings = $GLOBALS['opts']['dze_content_settings'] ?? null;
$GLOBALS['opts']['dze_content_settings']['photo_notes'] = [ 'many' => 'MY OWN WORDS ABOUT THESE PHOTOGRAPHS.', 'one' => '   ' ];
ok( 'the shop\'s words replace ours',
	trim( DZE_Content::sources_instruction( 3, null, 0, 0, false ) ), 'MY OWN WORDS ABOUT THESE PHOTOGRAPHS.' );
ok( 'and an emptied note is the default again',
	trim( DZE_Content::sources_instruction( 1, null, 0, 0, false ) ), $dze_cat['one']['default'] );
// {images} WORKS IN EVERY NOTE, as the settings screen says — these three
// were appended raw, token and all.
$GLOBALS['opts']['dze_content_settings']['photo_notes'] = [ 'many' => 'MY {images} NOTE.', 'one' => 'ONLY {images}.', 'edit' => 'WORK ON {images}.' ];
ok( '{images} in the note for several photographs',
	trim( DZE_Content::sources_instruction( 3, null, 0, 0, false ) ), 'MY Images 1 to 3 NOTE.' );
ok( '{images} in the note for one photograph',
	trim( DZE_Content::sources_instruction( 1, null, 0, 0, false ) ), 'ONLY Image 1.' );
ok( '{images} in the note for a retouch',
	trim( DZE_Content::sources_instruction( 1, null, 0, 0, true ) ), 'WORK ON Image 1.' );
// THE VARIATION SENTENCES ARE THE SHOP'S TOO, and they name the image that
// really shows the variation — a pasted one travels after the product's own.
$GLOBALS['opts']['dze_content_settings']['photo_notes'] = [];
ok( 'a variation shown by image 3 is said to be image 3',
	false !== strpos( DZE_Content::variation_instruction( 'pa_color', 'olive-drab', 3 ), 'Image 3 already shows that variation' ), true );
ok( 'its own photograph first is image 1',
	false !== strpos( DZE_Content::variation_instruction( 'pa_color', 'olive-drab', true ), 'Image 1 already shows that variation' ), true );
ok( 'no photograph of it: only the colour changes, and it is named',
	false !== strpos( DZE_Content::variation_instruction( 'pa_color', 'olive-drab', 0 ), 'only the colour becomes Olive drab' ), true );
$GLOBALS['opts']['dze_content_settings']['photo_notes'] = [ 'variation_recolour' => 'MAKE IT {variation} ({attribute}).' ];
ok( 'and the shop\'s own words are the ones sent',
	trim( DZE_Content::variation_instruction( 'pa_color', 'olive-drab', 0 ) ), 'MAKE IT Olive drab (pa_color).' );
ok( 'both are in the settings list', isset( $dze_cat['variation_own'], $dze_cat['variation_recolour'] ), true );
$GLOBALS['opts']['dze_content_settings']['photo_notes'] = [];
// SAVED LIKE EVERY OTHER SETTING HERE: only what the form carried, and a note
// left as the default stored as nothing, so the default can still improve.
$dze_saved = DZE_Content::instance()->sanitize( [ 'photo_notes' => [ 'many' => $dze_cat['many']['default'], 'one' => 'Mine.' ] ] );
ok( 'a note left as the default is stored as nothing',   (array) ( $dze_saved['photo_notes'] ?? [] ), [ 'one' => 'Mine.' ] );
$GLOBALS['opts']['dze_content_settings'] = $dze_saved;
$dze_other = DZE_Content::instance()->sanitize( [ 'store_context' => 'A tactical shop.' ] );
ok( 'and a form that did not carry the notes leaves them alone', (array) ( $dze_other['photo_notes'] ?? [] ), [ 'one' => 'Mine.' ] );
if ( null === $dze_keep_settings ) { unset( $GLOBALS['opts']['dze_content_settings'] ); } else { $GLOBALS['opts']['dze_content_settings'] = $dze_keep_settings; }
// EVERY PICTURE IS NUMBERED WHERE IT TRAVELS: the product, its other colours,
// the scene — in that order, whatever each note says. No picture already made
// travels any more: asked for one, the legend still names none.
$dze_all = DZE_Content::sources_instruction( 2, [ 'image' => 9, 'prompt' => 'Slate surface' ], 1, 1, false );
ok( 'another colour is numbered after the product',  false !== strpos( $dze_all, 'Image 3: the same product in another colour' ), true );
ok( 'no picture already made is ever named',        false !== strpos( $dze_all, 'already made' ), false );
ok( 'the scene last',                                false !== strpos( $dze_all, 'Image 4: the scene' ), true );
ok( 'with the shop\'s own words for that scene after it',
	strpos( $dze_all, 'Slate surface' ) > strpos( $dze_all, 'Image 4: the scene' ), true );
ok( 'several pictures of a kind are numbered as a range',
	false !== strpos( DZE_Content::sources_instruction( 2, null, 0, 2, false ), 'Images 3 to 4: the same product in another colour' ), true );

echo "\nNOTHING APPENDED MAY OVERRULE THE PROMPT\n";
// "Tu as encore ajouté des instructions custom par dessus le prompt ? Ça
// t'est interdit. Le prompt est le gagnant. Il est bien rédigé, et aucune
// autre instruction cachée ne devrait exister."
//
// Two sentences were doing exactly that, and one of them was worse than the
// other: with a scene chosen, the plugin told the model to "ignore any
// background described in words above" — the appended text disregarding the
// instructions it is appended to.
$scened = DZE_Content::sources_instruction( 3, [ 'image' => 9, 'prompt' => 'Slate surface' ], 0, 0, false );
ok( 'nothing tells the model to ignore the prompt',
	false !== strpos( $scened, 'ignore any background described in words above' ), false );
ok( 'and nothing else says "ignore"',   substr_count( $scened, 'ignore' ), 0 );
// The scene still does its own mechanical work: it IS the background, and the
// shop's own words for that scene travel with it.
ok( 'the scene is still named',         false !== strpos( $scened, 'Image 4: the scene' ), true );
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
	$shown, trim( DZE_Content::sources_instruction( DZE_Content::source_cap(), null, 0, 0, false ) ) );
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
// LETABLI NEST PLUS HEBERGE : il a son entree. Comme onglet du diagnostic,
// son adresse dependait de linterrupteur dun autre module, et lecran qui ecrit
// tous les textes produits portait le nom dun ecran qui lit tout le site.
ok( 'letabli nest heberge par personne', DZE_Content::bulk_hosted(), false );
ok( 'et tout lien mene a son ecran',
	false !== strpos( DZE_Content::bulk_url(), 'admin.php?page=dazont-content-bulk' ), true );
// A BOOKMARK STILL LANDS. The decision is split from the request, because a
// handler that ends the request cannot be tested.
// ET LANCIENNE ADRESSE REPOND TOUJOURS, sans redirection : elle est enregistree
// puis retiree de son menu, donc un signet atterrit sur la page elle-meme.
ok( 'lancienne adresse nest plus redirigee',
	DZE_Content::bulk_redirect( [ 'page' => DZE_Content::BULK_SLUG ] ), '' );
ok( 'and every other page is left alone',
	DZE_Content::bulk_redirect( [ 'page' => 'edit.php' ] ), '' );
// WITH NOTHING TO HOST IT, the screen is its own page again — and keeps its
// menu entry, or the shop has a function it cannot reach from anywhere. That
// is the state it shipped in.
$GLOBALS['dze_diag_class'] = false;
ok( 'nothing hosting it, it stands alone', DZE_Content::bulk_hosted(), false );
ok( 'and links point at its own page',
	DZE_Content::bulk_url(), 'http://shop.test/wp-admin/admin.php?page=dazont-content-bulk' );
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
		[ 'id' => 'old', 'name' => 'Pack shot', 'type' => 'image', 'output' => 'main', 'prompt' => 'P', 'tokens' => 400, 'enabled' => 1, 'valid' => 1, 'ratio' => '1:1' ],
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
// THE SHAPE SET ON THE PROMPT TRAVELS WITH IT. It was saved and never read
// back into this list: every image went out as « auto ».
ok( 'the prompt\'s shape is in the list every screen reads', $dze_t['old']['ratio'] ?? 'missing', '1:1' );
ok( 'and a prompt with none says none',                      $dze_t['ugc']['ratio'] ?? 'missing', '' );

echo "\nWHAT IS SENT WITH A PROMPT IS READ FROM THE ROW, NEVER WRITTEN BESIDE IT\n";
// "Impossible de decocher la description produit a envoyer pour le contexte
// sur cet ecran." Two faults in one report. The block was a READING on every
// screen but one, while the tick boxes lived on a single settings tab — and
// the reading itself was three sentences somebody had typed: on an image
// prompt it said only the product's NAME travelled, while shoot() sends
// whatever `inputs` holds, which ships as the title AND THE DESCRIPTION. That
// description is the paragraph of straps and buckles the appended sources
// block then spends a sentence arguing with.
( new ReflectionProperty( 'DZE_Content', 'registry_cache' ) )->setValue( null, null );
$dze_said = DZE_Content::prompt_data( 'content_old' );
ok( 'an image prompt names the fields it is really sent',
	in_array( 'Description', $dze_said, true ), true );
ok( 'and its title too',                in_array( 'Product title', $dze_said, true ), true );
// It is the SAME list the run reads, so the two cannot drift.
ok( 'the row answers what the run asks for',
	DZE_Content::prompt_inputs( 'content_old' ), [ 'title', 'description' ] );
// A ROW THAT ANSWERED IS NOT OVERRULED. This one says: nothing about the
// product at all, which is the answer somebody unticking every box gives.
ok( 'a row that was answered keeps its answer',
	DZE_Content::set_prompt_inputs( 'content_old', [] ), true );
( new ReflectionProperty( 'DZE_Content', 'registry_cache' ) )->setValue( null, null );
ok( 'nothing ticked is an answer',      DZE_Content::prompt_inputs( 'content_old' ), [] );
$dze_said = DZE_Content::prompt_data( 'content_old' );
ok( 'and the list says WHICH empty it is',
	in_array( 'Nothing about the product — your instructions alone.', $dze_said, true ), true );
ok( 'while the description is gone from it',
	in_array( 'Description', $dze_said, true ), false );
// Put it back, and only what was asked for: a save of this list writes that
// key and nothing else on the row.
ok( 'one box back on',                  DZE_Content::set_prompt_inputs( 'content_old', [ 'title' ] ), true );
( new ReflectionProperty( 'DZE_Content', 'registry_cache' ) )->setValue( null, null );
ok( 'and it is what the run is sent',   DZE_Content::prompt_inputs( 'content_old' ), [ 'title' ] );
ok( 'the prompt itself is untouched',
	(string) ( DZE_Content::prompt_row_of( 'content_old' )['prompt'] ?? '' ), 'P' );
// A PROMPT THAT IS NOT THE PRODUCT REGISTRY'S HAS NO SUCH LIST, and a control
// that cannot act must not be drawn: null is not an empty list.
ok( 'a prompt of another module answers with no list',
	DZE_Content::prompt_inputs( 'cat_desc' ), null );
ok( 'and a row that does not exist either',
	DZE_Content::prompt_inputs( 'content_nosuchrow' ), null );
ok( 'so nothing can be written onto one',
	DZE_Content::set_prompt_inputs( 'content_nosuchrow', [ 'title' ] ), false );

echo "\nAND A SAVE THAT HAD NOTHING TO DO WITH THE SCENE LEAVES IT ALONE\n";
// "Un truc change toujours Scene en standard background. C'est chiant."
//
// Two writers could reach a prompt's background without anybody asking them
// to, and both did it the way this plugin forbids by name: `$r['scene'] ?? ''`
// on a key that was not submitted. A form whose menu was not drawn for a row,
// and a canonical save that copied only what it cared about, both wrote "No
// scene" — an ANSWER, and the owner's — over a background he had chosen. The
// rule is the same one every other setting here is held to: ABSENT means "as
// it stands", never "none".
$dze_san = new ReflectionMethod( 'DZE_Content', 'sanitize' );
$dze_san->setAccessible( true );
/** One canonical save, and what the prompts hold afterwards. */
$dze_after = static function ( array $rows ) use ( $dze_san ): array {
	$out = $dze_san->invoke( DZE_Content::instance(), [ 'registry' => $rows ] );
	$by  = [];
	foreach ( (array) ( $out['registry'] ?? [] ) as $r ) { $by[ (string) $r['id'] ] = $r; }
	return $by;
};
$dze_held = (array) $GLOBALS['opts']['dze_content_settings']['registry'];
$dze_now  = $dze_after( $dze_held );
ok( 'a save that carries the scene keeps it',
	$dze_now['slate']['scene'] ?? 'missing', 'Slate' );
ok( 'and "no scene" survives it too',   $dze_now['ugc']['scene'] ?? 'missing', '' );
// THE ROW THAT CARRIES NO KEY MUST COME BACK CARRYING NO KEY. Written as ''
// it reads as "No scene" for ever after; and there is no way back, because
// nothing on any screen can tell an answer from a save that invented one.
ok( 'a row with no scene key is not given one',
	array_key_exists( 'scene', (array) ( $dze_now['old'] ?? [] ) ), false );
ok( 'so it still reads as the shop default',
	DZE_Content::scene_index( DZE_Content::prompt_scene( (array) $dze_now['old'] ) ), 0 );
// AND A SAVE BY A CALLER THAT COPIED ONLY WHAT IT CARED ABOUT — switching a
// prompt on from the toolbox is exactly that shape.
$dze_thin = [];
foreach ( $dze_held as $r ) {
	unset( $r['scene'] );
	$dze_thin[] = $r;
}
$dze_now = $dze_after( $dze_thin );
foreach ( [ 'old', 'ugc', 'slate', 'gone' ] as $dze_id ) {
	ok( 'no key in, no key out — ' . $dze_id,
		array_key_exists( 'scene', (array) ( $dze_now[ $dze_id ] ?? [] ) ), false );
}
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
echo "\nA PICTURE THE MODEL MADE IS NOT A PHOTOGRAPH OF THE PRODUCT\n";
// An accepted picture joins the gallery, and the gallery was sent to every
// later run as « photographs of the product » that « take precedence »: an
// invented view became the reference for the next one.
if ( ! function_exists( 'wp_attachment_is_image' ) ) { function wp_attachment_is_image( $id ) { return true; } }
$GLOBALS['thumbs']  = [ 70 => 71 ];
$GLOBALS['gallery'] = [ 70 => [ 72, 73 ] ];
$GLOBALS['dze_meta'][72][ DZE_Content::META_RECIPE ] = 'img_another_angle';
ok( 'only the real photographs travel', DZE_Content::product_source_ids( 70 ), [ 71, 73 ] );
$GLOBALS['dze_meta'][71][ DZE_Content::META_RECIPE ] = 'img_main';
$GLOBALS['dze_meta'][73][ DZE_Content::META_RECIPE ] = 'img_scene';
ok( 'a product with nothing else still sends what it has', DZE_Content::product_source_ids( 70 ), [ 71, 72, 73 ] );
unset( $GLOBALS['dze_meta'][71], $GLOBALS['dze_meta'][72], $GLOBALS['dze_meta'][73], $GLOBALS['thumbs'], $GLOBALS['gallery'] );

// CET HEBERGEUR DESACTIVE shell_exec. Une fatale ici arretait le fichier au
// milieu, et la suite annoncait « 0 wrong » sur des portes qui navaient jamais
// tourne — le pire des deux mondes. La partie qui a besoin dun second
// processus est annoncee comme sautee, le reste du fichier continue.
// SEULES LES VERIFICATIONS QUI ONT BESOIN D UN SECOND PROCESSUS SONT SAUTEES.
// Le fichier s arretait ici tout entier : sur cet hebergeur, tout ce qui suit
// — fal_generate() compris — n avait jamais tourne, et le compte final disait
// « 0 wrong » de portes jamais essayees.
if ( ! function_exists( 'shell_exec' ) ) {
	echo "  (saute : shell_exec est desactive sur cet hebergeur — les trois verifications du journal seulement)\n";
} else {
	$dze_log = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $dir ) . ' --dump-log 2>/dev/null' );
	ok( 'the Done tab has the same column',
		substr_count( $dze_log, 'class="dze-objid-th"' ), 1 );
	ok( 'right after what it names',
		(bool) preg_match( '/What<\/th>\s*<th class="dze-objid-th">ID</s', $dze_log ), true );
	ok( 'with a cell under it carrying the row\'s own id',
		(bool) preg_match( '/<tr data-id="(\d+)">[\s\S]*?<\/td>\s*<td class="dze-objid-td"><code[^>]*>\1</', $dze_log ), true );
}

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

echo "\nA CALL THAT FAILED IS STILL A CALL\n";
// "Dans logs, je vois la quantité d'appels nanobanana fal qui est à 48. Hors,
// le module génération d'image est bloqué pour limite atteinte de 100 appels
// par heure. WTF"
//
// Both figures were right and neither could be checked against the other. The
// ceiling counts what REACHES fal — deliberately, it is the thing that stops a
// run going round in circles — while `record()` sat behind `if ( $url )`, so
// the fifty-two that failed were counted by the guard and by nothing a person
// can read. `fal_generate()` had never been RUN by any gate: its three failure
// paths were read by eye, which is why the most expensive of them — an answer
// fal billed for and that held no picture — dropped its cost on the floor.
$GLOBALS['opts'] = [];
$GLOBALS['tr']   = [];
$dze_fal = static function ( $say ) {
	// The defaults are a job fal ACCEPTED and finished: a case names only what
	// it changes, and a default that wiped the submit answer would make every
	// case fail for the harness's reasons rather than the plugin's.
	$GLOBALS['fal_say'] = $say + [
		'code'   => 200,
		'body'   => '{"request_id":"req-1","status_url":"https://queue.fal.run/fal-ai/nano-banana-2/edit/requests/req-1/status","response_url":"https://queue.fal.run/fal-ai/nano-banana-2/edit/requests/req-1"}',
		'status' => 'COMPLETED',
		'result' => '{"images":[{"url":"https://fal.media/x.jpg"}]}',
		'units'  => '1',
	];
	try {
		return (string) DZE_Content::instance()->fal_generate( 'Shoot it.', [], 'auto', 7 );
	} catch ( Throwable $e ) {
		return 'THREW: ' . $e->getMessage();
	}
};
$dze_model = static function () {
	foreach ( DZE_Ai_Usage::model_report() as $r ) {
		if ( 'nano-banana-2' === $r['model'] ) { return $r; }
	}
	return [];
};

// 1. It worked.
ok( 'a photograph that came back is the url',
	$dze_fal( [ 'result' => '{"images":[{"url":"https://fal.media/ok.jpg"}]}' ] ), 'https://fal.media/ok.jpg' );
// AND IT WAS ASKED THE WAY A SLOW JOB SHOULD BE ASKED. `fal.run` holds the
// socket open until the picture is made; when this site gives up first, fal
// finishes it and bills for it, and the shop paid for nothing.
ok( 'the order goes to the queue endpoint',
	false !== strpos( (string) $GLOBALS['fal_sent'][0]['url'], 'queue.fal.run' ), true );
ok( 'and the submit does not sit on a two-minute socket',
	(int) $GLOBALS['fal_sent'][0]['timeout'] <= 30, true );
ok( 'one call recorded',            ( $dze_model()['calls'] ?? 0 ), 1 );
ok( 'and none of them failed',      ( $dze_model()['ko'] ?? -1 ), 0 );
ok( 'and it is counted as come back', DZE_Ai_Usage::fal_used( 7 )['made'], 1 );

// 2. fal answered, and the answer held no picture. THIS ONE IS BILLED.
$dze_was = (float) ( $dze_model()['cost'] ?? 0 );
ok( 'an answer with no picture in it fails loudly',
	$dze_fal( [ 'result' => '{"images":[]}' ] ), 'THREW: fal.ai returned no image.' );
ok( 'it is counted as a call',   ( $dze_model()['calls'] ?? 0 ), 2 );
ok( 'and as one that failed',    ( $dze_model()['ko'] ?? 0 ), 1 );
// The whole of the money half: fal charged for it, so the month must hold it.
ok( 'and what the provider billed for it is in the spend',
	( (float) ( $dze_model()['cost'] ?? 0 ) ) > $dze_was, true );
ok( 'it is not counted as a photograph that came back',
	DZE_Ai_Usage::fal_used( 7 )['made'], 1 );

// 3. fal refused the request: it arrived, it was not carried out.
$dze_was = (float) ( $dze_model()['cost'] ?? 0 );
ok( 'a refusal is passed on in words',
	false !== strpos( $dze_fal( [ 'code' => 422, 'body' => '{"detail":"bad prompt"}' ] ), 'bad prompt' ), true );
ok( 'counted as a call',      ( $dze_model()['calls'] ?? 0 ), 3 );
ok( 'and as one that failed', ( $dze_model()['ko'] ?? 0 ), 2 );
ok( 'and nothing was billed for it',
	round( (float) ( $dze_model()['cost'] ?? 0 ) - $dze_was, 4 ), 0.0 );

// 4. It never arrived.
$dze_was = (float) ( $dze_model()['cost'] ?? 0 );
ok( 'a transport failure is passed on',
	$dze_fal( [ 'wp_error' => 'Connection timed out' ] ), 'THREW: Connection timed out' );
ok( 'counted as a call',      ( $dze_model()['calls'] ?? 0 ), 4 );
ok( 'and as one that failed', ( $dze_model()['ko'] ?? 0 ), 3 );
ok( 'and nothing was billed for it',
	round( (float) ( $dze_model()['cost'] ?? 0 ) - $dze_was, 4 ), 0.0 );

// 5. FAL TOOK IT AND HAD NOT FINISHED. This is the one the shop was paying
// for and losing: the job is accepted, it is billed, and the old code threw
// the request away on a cURL timeout with the cost written down as nothing.
$dze_was  = (float) ( $dze_model()['cost'] ?? 0 );
$dze_sent = count( $GLOBALS['fal_sent'] );
$dze_left = $dze_fal( [ 'status' => 'IN_PROGRESS' ] );
ok( 'a job still running says it is kept, not lost',
	false !== strpos( $dze_left, 'collects it instead of ordering another' ), true );
ok( 'and what fal billed for it is in the spend',
	( (float) ( $dze_model()['cost'] ?? 0 ) ) > $dze_was, true );
// THE JOB IS KEPT ON THE PRODUCT, or there is nothing to collect it by.
ok( 'the product is owed a picture',
	(string) ( DZE_Content::fal_pending( 7 )['id'] ?? '' ), 'req-1' );
// 6. NEVER PAY TWICE. Ordering again while that job is still running is
// exactly how the credit went.
$dze_was2 = count( $GLOBALS['fal_sent'] );
$dze_again = $dze_fal( [ 'status' => 'IN_PROGRESS' ] );
ok( 'a second order is refused while one is owed',
	false !== strpos( $dze_again, 'already paid for' ), true );
ok( 'and nothing new was sent to fal', count( $GLOBALS['fal_sent'] ), $dze_was2 );
// 7. AND IT IS COLLECTED, for nothing, on the next run.
$dze_made = DZE_Ai_Usage::fal_used( 7 )['made'];
$dze_hour = DZE_Ai_Usage::fal_used( 7 )['hour'];
$dze_sent3 = count( $GLOBALS['fal_sent'] );
ok( 'the picture already paid for comes back',
	$dze_fal( [ 'status' => 'COMPLETED', 'result' => '{"images":[{"url":"https://fal.media/late.jpg"}]}' ] ),
	'https://fal.media/late.jpg' );
ok( 'without ordering anything',      count( $GLOBALS['fal_sent'] ), $dze_sent3 );
ok( 'without spending an attempt',    DZE_Ai_Usage::fal_used( 7 )['hour'], $dze_hour );
ok( 'it counts as a photograph made', DZE_Ai_Usage::fal_used( 7 )['made'], $dze_made + 1 );
ok( 'and the product owes nothing now', DZE_Content::fal_pending( 7 ), [] );

// EVERY FAILURE SAYS WHY, AND THE MONTH COUNTS THEM BY KIND. "Il me bouffe mon
// crédit pour 50% de requêtes qui échouent" — the register knew HOW MANY and
// nothing at all about WHY, so the one question worth asking had no answer.
$dze_why = [];
foreach ( DZE_Ai_Usage::fail_report() as $r ) { $dze_why[ $r['key'] ] = $r['n']; }
ok( 'the answer with no picture is named',   ( $dze_why['noimage'] ?? 0 ), 1 );
ok( 'the refusal is named',                  ( $dze_why['refused'] ?? 0 ), 1 );
ok( 'the one that never arrived is named',   ( $dze_why['network'] ?? 0 ), 1 );
// ONE, not two: the second order was refused before it was sent, so there is
// nothing to file. A failure written down for a request that never went out is
// a figure that would make the shop look worse than it is.
ok( 'the one left to be collected is named', ( $dze_why['abandoned'] ?? 0 ), 1 );
// A KIND OF FAILURE IS A THING TO ACT ON, so every one of them has words and a
// share — a key with no words would print a blank row on the one screen
// somebody opens when the credit is going down.
$dze_rows = DZE_Ai_Usage::fail_report();
$dze_dumb = 0;
foreach ( $dze_rows as $r ) {
	if ( '' === trim( (string) $r['label'] ) || '' === trim( (string) $r['said'] ) || '' === trim( (string) $r['share'] ) ) { $dze_dumb++; }
}
ok( 'every kind of failure has words and a share', $dze_dumb, 0 );
ok( 'the biggest is first',
	(int) $dze_rows[0]['n'] >= (int) $dze_rows[ count( $dze_rows ) - 1 ]['n'], true );
// A MONTH WITH NOTHING WRONG PRINTS NOTHING: news every hour is noise.
ok( 'a quiet month says nothing', DZE_Ai_Usage::fail_report( '1999-01' ), [] );

// AND THE CEILING COUNTED ALL FOUR. That is the whole point of it: a run that
// fails in a loop reaches the provider exactly as often as one that works.
ok( 'every order reached the provider',           count( $GLOBALS['fal_sent'] ), 5 );
ok( 'and the ceiling counted every one',          DZE_Ai_Usage::fal_used( 7 )['hour'], 5 );
ok( 'while two photographs came back',            DZE_Ai_Usage::fal_used( 7 )['made'], 2 );
// SO THE TWO FIGURES CAN BE READ AGAINST EACH OTHER, which is exactly what the
// shop could not do.
$GLOBALS['mai']['fal_cap_hour'] = 5;
$dze_wall = DZE_Ai_Usage::fal_blocked( 0 );
ok( 'the wall says what went out',   false !== strpos( $dze_wall, 'sent 5 requests' ), true );
ok( 'and what came back',            false !== strpos( $dze_wall, 'Only 2 came back' ), true );
// THREE, not four. The register counted four failures and one of them — the
// job fal had not finished — was COLLECTED afterwards, so of the five requests
// that went out, three never became a photograph. Two true figures answering
// two different questions, and the wall answers the one it is about.
ok( 'and how many never became a photograph',
	false !== strpos( $dze_wall, '3 failed' ), true );

echo "\nA SETTLED PHOTOGRAPH LEAVES THE WAITING LIST, AND \"NOT LIKE THIS\" FOLLOWS THE SLOT\n";
// "It literally generated 5 images… and I don't actually have 13 images
// anywhere", and "when it gives me bad option and I am trying to change it,
// mostly it gives me same exact image."
//
// Nothing ever told the waiting list that a photograph had been accepted, so
// it stayed there for ever: the product went on counting as waiting for a yes
// or no, and the "not like this" lane went on handing that same picture back.
$GLOBALS['dze_meta'][7]['_dze_pending_review'] = [
	'shots'   => [ 'https://fal.media/a.jpg', 'https://fal.media/b.jpg' ],
	'targets' => [ 'https://fal.media/a.jpg' => 'gallery', 'https://fal.media/b.jpg' => 'main' ],
	'recipes' => [ 'https://fal.media/a.jpg' => 'tpl_one', 'https://fal.media/b.jpg' => 'tpl_two' ],
];
ok( 'two photographs are waiting',
	count( (array) ( DZE_Content::pending( 7 )['shots'] ?? [] ) ), 2 );
ok( 'settling one takes one out',
	DZE_Content::settle_shots( 7, [ 'https://fal.media/a.jpg' ] ), 1 );
$dze_left = DZE_Content::pending( 7 );
ok( 'and the other is still there',   (array) $dze_left['shots'], [ 'https://fal.media/b.jpg' ] );
// WHAT IT WAS MADE FOR GOES WITH IT, or the row keeps answering for a
// photograph that is no longer in it.
ok( 'what it was made for goes too',
	isset( $dze_left['targets']['https://fal.media/a.jpg'] ), false );
ok( 'and which prompt made it',
	isset( $dze_left['recipes']['https://fal.media/a.jpg'] ), false );
// SETTLING THE LAST ONE EMPTIES THE ROW: a product holding nothing is not a
// product waiting for a decision.
DZE_Content::settle_shots( 7, [ 'https://fal.media/b.jpg' ] );
ok( 'the last one empties the row',   DZE_Content::pending( 7 ), [] );
ok( 'settling nothing does nothing',  DZE_Content::settle_shots( 7, [] ), 0 );

// NO PICTURE THE MODEL MADE GOES BACK IN AS « NOT LIKE THIS ». « Le slop
// commence à partir de la 2e image générée » : image 3 was built on image 2,
// which was built on image 1. The lane and the two functions that fed it are
// gone from the file, not merely unused.
ok( 'nothing reads what a prompt already made', method_exists( 'DZE_Content', 'made_already' ), false );
ok( 'nor hands it back',                         method_exists( 'DZE_Content', 'avoid_sources' ), false );

echo "\nNOTHING APPENDED CHOOSES WHAT THE PHOTOGRAPH SHOWS\n";
// "Image 1 : détails fake. C'est encore une fois un réel problème, ça arrive
// beaucoup trop souvent sur des produits avec détails fins." Two D-rings at
// the waist, a velcro panel with two press studs and a zipped pocket, on a
// pair of shorts that has one D-ring, a plain flap pocket and no zip.
//
// The plugin was asking for it. On the second attempt of a prompt it appended
// a HINT that chose the subject of the shot — "Come closer: a detail of the
// material, the stitching or the fastening, filling most of the frame" — on a
// product whose fastenings may never have been photographed. A model asked
// for a close-up of hardware it has never been shown paints plausible
// hardware, and on tactical gear that is immediately, obviously wrong.
//
// It is the plugin choosing the content of the photograph, which is the
// owner's decision and nobody else's: "évidemment que c'est à supprimer".
$dze_said = DZE_Content::variation_line( 7, 'tpl_one', 'gallery', 2 );
ok( 'nothing asks for a closer look',
	false !== stripos( $dze_said, 'Come closer' ), false );
ok( 'nor for another angle',      false !== stripos( $dze_said, 'three-quarter' ), false );
ok( 'nor for the product in its setting',
	false !== stripos( $dze_said, 'Step back' ), false );
ok( 'nor names a fastening at all',
	false !== stripos( $dze_said, 'fastening' ), false );
ok( 'and it appends nothing whatever',           $dze_said, '' );
// AND THE ANTI-REPEAT IS STILL THERE — SHOWN, NOT TOLD. The photograph
// already made travels with the request and the legend names it: a set of
// source images saying "make it different" beats a sentence saying so, which
// is why this line was a second way of saying what the images already say.
$dze_avoid = DZE_Content::sources_instruction( 2, null, 1, 0, false );
ok( 'no picture already made is named any more',
	false !== strpos( $dze_avoid, 'already made' ), false );
ok( 'nor asked to be different from one',
	false !== strpos( $dze_avoid, 'Make a clearly different one' ), false );
// AND THE SOURCE OF THE FAULT IS GONE FROM THE FILE, not merely unused: a
// sentence nothing sends is a sentence somebody wires back up next year.
// THE WHOLE APPENDED TEXT IS LOCKED, so nothing joins it by accident. Every
// sentence under the owner's prompt competes with it for the model's
// attention, and two of the three that ever went wrong here were added
// without anybody deciding to add them. A new one now turns this gate red and
// somebody has to put it in the lock on purpose — "adding a sentence to that
// note is a decision the shop takes, not one taken for it".
ok( 'the appended text is exactly what the shop reads in its settings',
	DZE_Content::sources_instruction( 3, null, 0, 0, false ), "\n\n" . DZE_Content::photo_note( 'many' ) );
ok( 'the hints are gone from the plugin',
	substr_count( (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content.php' ),
		'filling most of the frame' ), 0 );

echo "\nTHE fal.ai KEY FIELD SAYS WHICH KEY AND WHERE IT IS MADE\n";
// DRAWN, not described: the field used to say "define DZE_FAL_API_KEY in
// wp-config.php" and nothing about where a key comes from.
ob_start();
DZE_Content::instance()->render_key_field();
$dze_keyf = (string) ob_get_clean();
if ( defined( 'DZE_FAL_API_KEY' ) ) {
	ok( 'locked in wp-config, no field and no hint', false !== strpos( $dze_keyf, 'dze-key-hint' ), false );
} else {
	ok( 'the field carries the hint',        false !== strpos( $dze_keyf, 'dze-key-hint' ), true );
	ok( 'which says which key',              false !== strpos( $dze_keyf, 'An API key from the fal.ai dashboard' ), true );
	ok( 'and links to where it is made',     false !== strpos( $dze_keyf, 'href="https://fal.ai/dashboard/keys"' ), true );
	ok( 'and no longer sends the owner to wp-config instead',
		false !== strpos( $dze_keyf, 'define DZE_FAL_API_KEY' ), false );
}

echo "\nLE MODELE D IMAGES EST UN CHOIX, ET CHACUN EST DEMANDE A SA FACON\n";
// « Ajoute-moi l'API GPT pour tester la génération d'images par leur API. Ou
// sinon le choix du modèle par FAL. Je continue d'avoir trop de slop sur les
// produits assez techniques. » fal porte aussi GPT Image d'OpenAI : une clé,
// une file, un registre — le modèle est un réglage, jamais un second chemin.
$dze_keep_img = $GLOBALS['opts']['dze_content_settings'] ?? null;
$dze_shoot = static function ( string $model, array $refs, string $ratio ) {
	$GLOBALS['opts']['dze_content_settings'] = array_merge( (array) ( $GLOBALS['opts']['dze_content_settings'] ?? [] ), [ 'img_model' => $model ] );
	$GLOBALS['fal_sent'] = [];
	// Le plafond horaire est celui des essais d au-dessus : chaque essai ici
	// part d une heure neuve, ce n est pas lui qu on eprouve.
	$GLOBALS['tr'] = [];
	$GLOBALS['fal_say']  = [
		'code'   => 200,
		'body'   => '{"request_id":"req-9","status_url":"https://queue.fal.run/x/requests/req-9/status","response_url":"https://queue.fal.run/x/requests/req-9"}',
		'status' => 'COMPLETED',
		'result' => '{"images":[{"url":"https://fal.media/m.jpg"}]}',
		'units'  => '1234',
	];
	try {
		$url = (string) DZE_Content::instance()->fal_generate( 'Shoot it.', $refs, $ratio, 0 );
	} catch ( Throwable $e ) {
		$url = 'THREW: ' . $e->getMessage();
	}
	$sent = $GLOBALS['fal_sent'][0] ?? [];
	return [ 'url' => $url, 'to' => (string) ( $sent['url'] ?? '' ), 'body' => (array) json_decode( (string) ( $sent['body'] ?? '' ), true ) ];
};
$dze_spent = static function ( string $model ): array {
	foreach ( DZE_Ai_Usage::model_report() as $r ) {
		if ( $model === $r['model'] ) { return $r; }
	}
	return [];
};
$dze_refs = array_map( static fn( $i ) => 'https://fal.media/ref' . $i . '.jpg', range( 1, 12 ) );
// LE DEFAUT NE CHANGE RIEN : Nano Banana 2, demande comme avant.
$GLOBALS['opts']['dze_content_settings'] = [];
ok( 'sans choix, c est Nano Banana 2', DZE_Content::image_model_key(), 'nano-banana-2' );
$r = $dze_shoot( 'nano-banana-2', array_slice( $dze_refs, 0, 2 ), '4:5' );
ok( 'il part a la porte de retouche de Nano Banana 2', $r['to'], 'https://queue.fal.run/fal-ai/nano-banana-2/edit' );
ok( 'avec son cadre en rapport',                    [ $r['body']['aspect_ratio'] ?? '', isset( $r['body']['image_size'] ) ], [ '4:5', false ] );
// GPT IMAGE 2.5 SUNBURST : la precision, demandee en image_size et en qualite haute.
$r = $dze_shoot( 'gpt-image-2.5-sunburst', array_slice( $dze_refs, 0, 3 ), '4:5' );
ok( 'GPT Image 2.5 Sunburst part chez OpenAI, par fal', $r['to'], 'https://queue.fal.run/openai/gpt-image-2.5/sunburst/edit' );
ok( 'son cadre est une taille, 4:5 en largeur et hauteur', $r['body']['image_size'] ?? null, [ 'width' => 1024, 'height' => 1280 ] );
ok( 'sans aspect_ratio qu il ne connait pas',       isset( $r['body']['aspect_ratio'] ), false );
ok( 'en qualite haute, en JPEG',                     [ $r['body']['quality'] ?? '', $r['body']['output_format'] ?? '' ], [ 'high', 'jpeg' ] );
ok( 'avec les photographies du produit',             count( (array) ( $r['body']['image_urls'] ?? [] ) ), 3 );
ok( 'et l image revient',                            $r['url'], 'https://fal.media/m.jpg' );
// FACTURE AU TOKEN : l unite de fal n est pas une image, l estimation compte.
ok( 'il est compte sous son nom',                    ( $dze_spent( 'gpt-image-2.5-sunburst' )['calls'] ?? 0 ), 1 );
ok( 'au prix estime avec les photographies envoyees, pas 1234 unites',
	round( (float) ( $dze_spent( 'gpt-image-2.5-sunburst' )['cost'] ?? 0 ), 4 ), round( 0.05 + 0.012 * 3, 4 ) );
// SANS PHOTOGRAPHIE : la porte texte vers image du meme modele.
$r = $dze_shoot( 'gpt-image-2.5-flare', [], '16:9' );
ok( 'sans photographie, la porte qui ecrit depuis les mots', $r['to'], 'https://queue.fal.run/openai/gpt-image-2.5/flare/text-to-image' );
ok( 'avec le nom de taille de fal',                  $r['body']['image_size'] ?? '', 'landscape_16_9' );
ok( 'et sans image_urls vide',                       isset( $r['body']['image_urls'] ), false );
// FLUX.2 PRO : neuf references au plus.
$r = $dze_shoot( 'flux-2-pro', $dze_refs, '1:1' );
ok( 'FLUX.2 Pro ne recoit que les neuf photographies qu il lit, la principale d abord',
	[ count( (array) ( $r['body']['image_urls'] ?? [] ) ), $r['body']['image_urls'][0] ?? '' ], [ 9, 'https://fal.media/ref1.jpg' ] );
ok( 'en carre HD',                                   $r['body']['image_size'] ?? '', 'square_hd' );
// NANO BANANA PRO : le rapport, et sa resolution.
$r = $dze_shoot( 'nano-banana-pro', array_slice( $dze_refs, 0, 2 ), '3:2' );
ok( 'Nano Banana Pro garde le rapport, en 1K',       [ $r['to'], $r['body']['aspect_ratio'] ?? '', $r['body']['resolution'] ?? '' ], [ 'https://queue.fal.run/fal-ai/nano-banana-pro/edit', '3:2', '1K' ] );
// UN MODELE INCONNU N EST PAS UNE PANNE : le defaut.
$GLOBALS['opts']['dze_content_settings'] = [ 'img_model' => 'midjourney' ];
ok( 'un reglage inconnu retombe sur le defaut',       DZE_Content::image_model_key(), 'nano-banana-2' );
// LE PRIX ANNONCE AVANT UN ENVOI SUIT LE MODELE.
$GLOBALS['opts']['dze_content_settings'] = [ 'img_model' => 'gpt-image-2.5-sunburst', 'img_sources' => 4 ];
ok( 'le prix annonce suit le modele et les photographies envoyees', DZE_Content::fal_image_cost(), round( 0.05 + 0.012 * 4, 4 ) );
$GLOBALS['opts']['dze_content_settings'] = [ 'fal_image_cost' => 0.09 ];
ok( 'et le prix saisi pour Nano Banana 2 reste le sien', DZE_Content::fal_image_cost(), 0.09 );
ok( 'sans s appliquer aux autres',                    DZE_Content::fal_image_cost( 0, 'nano-banana-pro' ), 0.15 );
// LE PRIX ANNONCE NOMME LE MODELE ET COMPTE LES PHOTOGRAPHIES ENVOYEES.
// « J'ai changé pour Sunburst, la data affichée est fausse » : la ligne disait
// le prix de Nano Banana 2 pour toute la page, sans dire quel modele tournait.
$GLOBALS['opts']['dze_content_settings'] = [ 'img_model' => 'gpt-image-2.5-sunburst', 'img_sources' => 10 ];
$dze_pc = DZE_Content::image_price_cfg();
ok( 'l ecran recoit le modele en vigueur, nomme',     $dze_pc['model'], 'GPT Image 2.5 Sunburst (OpenAI)' );
ok( 'et son prix en base plus chaque photographie',    [ $dze_pc['base'], $dze_pc['perRef'], $dze_pc['cap'] ], [ 0.05, 0.012, 16 ] );
$GLOBALS['opts']['dze_content_settings'] = [];
ok( 'Nano Banana 2 n a pas de prix par photographie',  [ DZE_Content::image_price_cfg()['base'], DZE_Content::image_price_cfg()['perRef'] ], [ 0.08, 0.0 ] );
$dze_js  = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content.js' );
$dze_jsb = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content-bulk.js' );
$dze_aj  = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content-ajax.php' );
ok( 'la boite a outils compte les photographies du produit, collees et scene comprises',
	false !== strpos( $dze_js, 'cost += k * perImage(refsFor(job.scene, job.target), cxPrice());' ) && false !== strpos( $dze_aj, "'sources' => count( self::product_source_ids( \$pid ) )," ), true );
ok( 'l ecran de masse aussi, produit par produit',      false !== strpos( $dze_jsb, 'perImage(refsOf(id, j), cbPrice())' ), true );
ok( 'et la phrase nomme le modele',                     false !== strpos( $dze_js, 'i18n.willCostWith' ) && false !== strpos( $dze_jsb, 'i18n.willCostWith' ), true );
// « This press: 1 photographs with $0.24 · about GPT Image 2.5 Sunburst » : le
// sprintf des ecrans remplissait %1$s %3$s %2$s dans l ordre d apparition, et
// le panneau d une fiche comptait seize photographies (le plafond du modele)
// avant d avoir lu les deux du produit, sans jamais se redessiner ensuite.
$dze_lab = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/image-lab.js' );
$dze_cc  = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content.php' );
$dze_pos = 'replace(/%(\d+)\$s|%s/g, function (m, n) { return n ? args[parseInt(n, 10) - 1] : args[i++]; });';
ok( 'les sprintf des ecrans d images respectent la position', [ substr_count( $dze_js, $dze_pos ), substr_count( $dze_jsb, $dze_pos ), substr_count( $dze_lab, $dze_pos ) ], [ 1, 1, 1 ] );
ok( 'le panneau d une fiche compte ce qu il envoie',      false !== strpos( $dze_js, "var said = willSay(n, n * perImage(oneRefs(), price), price ? price.model : '');" ), true );
ok( 'et se redessine : produit lu, photos choisies, photo collee', [
	(bool) preg_match( '/drawWillSpend\(\);\s*oneWillSpend\(\);/', $dze_js ),
	(bool) preg_match( '/oneClearPreview\(\);\s*\/\/ What is sent is what is billed[^\n]*\n\s*oneWillSpend\(\);/', $dze_js ),
	(bool) preg_match( '/charge them\.\s*oneWillSpend\(\);/', $dze_js ),
], [ true, true, true ] );
ok( 'avant la reponse, le chiffre de la boutique, jamais le plafond du modele',
	false !== strpos( $dze_js, ': (parseInt(cfg.sourceCap, 10) || 10);' ) && false === strpos( $dze_js, '(parseInt((cfg.imagePrice || {}).cap, 10) || 0)' ) && false === strpos( $dze_jsb, '(parseInt((cfg.imagePrice || {}).cap, 10) || 0)' ), true );
$dze_main = "if ('main' === target || 0 === target.indexOf('variation:')) { own = Math.min(own, parseInt(cfg.mainCap, 10) || 3); }";
ok( 'une image principale ou de variation part avec trois photographies', [ substr_count( $dze_js, $dze_main ), substr_count( $dze_jsb, $dze_main ) ], [ 1, 1 ] );
ok( 'l ecran de masse recoit les deux plafonds',          [ substr_count( $dze_cc, "'mainCap'   => self::MAIN_SOURCES," ), substr_count( $dze_cc, "'sourceCap' => self::source_cap()," ) ], [ 1, 1 ] );
ok( 'une photographie se dit au singulier, sur les deux ecrans', substr_count( $dze_cc, "'willCostWithOne' => __( 'This press: 1 photograph with %3\$s · about %2\$s', 'dazont-ecom' )," ), 2 );
// L AIDE DU CUMUL envoyait vers « Settings → Product content, next to the
// fal.ai key » et disait le prix « a regler » quel que soit le modele.
$GLOBALS['opts']['dze_content_settings'] = [ 'img_model' => 'gpt-image-2.5-sunburst' ];
$dze_tip = DZE_Content::spend_tip();
ok( 'l aide du cumul dit le bon ecran',                   [ false !== strpos( $dze_tip, 'Settings → General → fal.ai (image generation)' ), false !== strpos( $dze_tip, 'Product content' ) ], [ true, false ] );
ok( 'et le prix du modele en vigueur',                    false !== strpos( $dze_tip, 'GPT Image 2.5 Sunburst (OpenAI), $0.050 per image plus $0.012 per photograph sent.' ), true );
$GLOBALS['opts']['dze_content_settings'] = [ 'fal_image_cost' => 0.09 ];
ok( 'Nano Banana 2 : le prix saisi, par image',           false !== strpos( DZE_Content::spend_tip(), 'Nano Banana 2 (Google), $0.090 per image.' ), true );
ok( 'les deux ecrans lisent la meme aide',                substr_count( $dze_cc, '=> self::spend_tip(),' ), 2 );
// =====================================================================
// 4.505.0 — LA FICHE PRODUIT COMMANDE, PUIS REVIENT CHERCHER SON IMAGE
// =====================================================================
// « HTTP 504 error — see the log ↗ ne me donne rien. » Un appui tenait la
// requête ouverte le temps que fal finisse — 40 à 60 s avec GPT Image — et le
// proxy de Hostinger coupe à 60 : PHP mourait avec, et une image payée n'était
// notée nulle part. « Aucune nouvelle image n'a vu la précédente » : trois
// commandes, trois fois le même trois-quarts. Et « le bouton supprimer […] ne
// les supprime pas » : la copie gardée par le panneau les ramenait.
$GLOBALS['dze_meta'] = [];
$GLOBALS['tr']       = [];
$GLOBALS['fal_sent'] = [];
$GLOBALS['fal_got']  = [];
$GLOBALS['opts']['dze_content_settings'] = [ 'img_model' => 'nano-banana-2' ];
// Its own provider, whole: the tests before it leave other ids and other bills behind.
$GLOBALS['fal_say'] = [
	'code'   => 200,
	'body'   => '{"request_id":"req-1","status_url":"https://queue.fal.run/fal-ai/nano-banana-2/edit/requests/req-1/status","response_url":"https://queue.fal.run/fal-ai/nano-banana-2/edit/requests/req-1"}',
	'status' => 'IN_PROGRESS',
	'result' => '{"images":[{"url":"https://v3b.fal.media/files/b/x/new.jpg"}]}',
	'units'  => '1',
];
DZE_Content::$submit_only = true;
$dze_got = DZE_Content::instance()->fal_generate( 'p', [ 'data:image/jpeg;base64,AA' ], '1:1', 77, '' );
DZE_Content::$submit_only = false;
$dze_status_asked = count( array_filter( $GLOBALS['fal_got'], static fn( $u ) => '/status' === substr( (string) $u, -7 ) ) );
ok( 'commandée, la photo ne fait plus attendre : aucune question de statut', [ $dze_got, $dze_status_asked ], [ '', 0 ] );
ok( 'la commande revient avec ses mots et son heure de départ', [ DZE_Content::$submitted['id'] ?? '', isset( DZE_Content::$submitted['asked'] ), isset( DZE_Content::$submitted['t0'] ) ], [ 'req-1', true, true ] );
DZE_Content::job_add( 77, DZE_Content::$submitted + [ 'target' => 'gallery', 'recipe' => 'r1', 'stash' => 1 ] );
DZE_Content::$submitted = [];
ok( 'et elle est rangée sur le produit, où une page rouverte la retrouve', [ array_keys( DZE_Content::jobs( 77 ) ), DZE_Content::jobs_public( 77 )[0]['key'] ?? '' ], [ [ 'req-1' ], 'nano-banana-2' ] );
$dze_look = new ReflectionMethod( 'DZE_Content', 'job_look' );
$dze_look->setAccessible( true );
$GLOBALS['fal_got'] = [];
$dze_r = $dze_look->invoke( DZE_Content::instance(), 77, 'req-1' );
ok( 'tant que fal travaille : « en cours », en une seule question', [ $dze_r['running'] ?? 0, count( $GLOBALS['fal_got'] ), count( DZE_Content::jobs( 77 ) ) ], [ 1, 1, 1 ] );
$GLOBALS['fal_say']['status'] = 'COMPLETED';
$GLOBALS['mai_view']   = '{"part":"whole jacket","distance":"whole product","angle":"front three-quarter left","worn":false,"invented":[]}';
$GLOBALS['mai_vision'] = [];
$dze_r = $dze_look->invoke( DZE_Content::instance(), 77, 'req-1' );
$dze_u = 'https://v3b.fal.media/files/b/x/new.jpg';
$dze_w = DZE_Content::pending( 77 );
ok( 'finie : dans la liste d attente, avec sa cible, son prompt, son modele et son cadrage', [
	$dze_r['done'] ?? 0, $dze_r['url'] ?? '', $dze_r['key'] ?? '',
	$dze_w['shots'] ?? [], $dze_w['recipes'][ $dze_u ] ?? '', $dze_w['models'][ $dze_u ] ?? '', $dze_w['frames'][ $dze_u ] ?? '', $dze_w['flags'][ $dze_u ] ?? null,
], [ 1, $dze_u, 'nano-banana-2', [ $dze_u ], 'r1', 'nano-banana-2', 'whole jacket — whole product, front three-quarter left', [] ] );
ok( 'le cadrage est lu par le lecteur (Sonnet 5.5), sur la photo elle-meme, en forme imposee', [ count( $GLOBALS['mai_vision'] ), $GLOBALS['mai_vision'][0][3] ?? '', ( $GLOBALS['mai_vision'][0][2][0]['media'] ?? '' ), $GLOBALS['mai_vision'][0][6]['output_config']['format']['type'] ?? '', $GLOBALS['mai_vision'][0][6]['output_config']['effort'] ?? '', $GLOBALS['mai_vision'][0][6]['fallbacks'] ?? '' ], [ 1, 'claude-sonnet-5-5', 'image/jpeg', 'json_schema', 'low', 'default' ] );
ok( 'et la commande quitte la liste des travaux, payee une fois', [ DZE_Content::jobs( 77 ), (float) get_post_meta( 77, DZE_Content::META_SPEND, true ) > 0 ], [ [], true ] );
// UNE COMMANDE QUE FAL N'A PAS FINIE EN UN QUART D'HEURE est abandonnée, dite,
// et retirée — plus jamais redemandée.
DZE_Content::job_add( 77, [ 'id' => 'req-2', 'status' => 'https://queue.fal.run/fal-ai/x/requests/req-2/status', 'response' => '', 'model' => 'nano-banana-2', 'refs' => 1, 't' => time() - 1000 ] );
$GLOBALS['fal_say']['status'] = 'IN_QUEUE';
$dze_r = $dze_look->invoke( DZE_Content::instance(), 77, 'req-2' );
ok( 'abandonnee apres 15 minutes, dite sur sa brique, retiree des travaux', [ $dze_r['error'] ?? 0, $dze_r['gone'] ?? 0, isset( DZE_Content::jobs( 77 )['req-2'] ) ], [ 1, 1, false ] );
// Haiku muet : la photo est rangée quand meme, sans cadrage.
DZE_Content::job_add( 77, [ 'id' => 'req-3', 'status' => 'https://queue.fal.run/fal-ai/x/requests/req-3/status', 'response' => '', 'model' => 'nano-banana-2', 'refs' => 1, 'stash' => 1, 'recipe' => 'r1', 'target' => 'gallery' ] );
$GLOBALS['fal_say']['status'] = 'COMPLETED';
$GLOBALS['fal_say']['result'] = '{"images":[{"url":"https://v3b.fal.media/files/b/x/two.jpg"}]}';
$GLOBALS['mai_view_fail'] = 1;
$dze_r = $dze_look->invoke( DZE_Content::instance(), 77, 'req-3' );
unset( $GLOBALS['mai_view_fail'] );
ok( 'si Claude ne répond pas, la photo est rangée sans cadrage', [ $dze_r['done'] ?? 0, DZE_Content::pending( 77 )['frames']['https://v3b.fal.media/files/b/x/two.jpg'] ?? 'absent' ], [ 1, 'absent' ] );
// LA COMMANDE SUIVANTE EST PRÉVENUE — EN MOTS, JAMAIS EN IMAGES. two.jpg est
// arrivée sans cadrage (Claude muet) : elle est lue maintenant, une fois.
$GLOBALS['mai_view']   = '{"part":"chest zip","distance":"close-up","angle":"front","worn":false,"invented":[]}';
$GLOBALS['mai_vision'] = [];
$dze_ml = DZE_Content::made_lines( 77, 'r1' );
ok( 'la suivante est prevenue en mots de ce qui existe deja', [ false !== strpos( $dze_ml, 'ALREADY MADE' ), false !== strpos( $dze_ml, 'whole jacket — whole product, front three-quarter left' ), false !== strpos( $dze_ml, 'v3b.fal.media' ) ], [ true, true, false ] );
ok( 'une image en attente sans cadrage est lue avant la commande, et la ligne gardee', [ false !== strpos( $dze_ml, 'chest zip — close-up, front' ), DZE_Content::pending( 77 )['frames']['https://v3b.fal.media/files/b/x/two.jpg'] ?? '', count( $GLOBALS['mai_vision'] ) ], [ true, 'chest zip — close-up, front', 1 ] );
DZE_Content::made_lines( 77, 'r1' );
ok( 'une seule fois : la ligne gardee sert aux suivantes', count( $GLOBALS['mai_vision'] ), 1 );
ok( 'pas celle d un autre prompt', DZE_Content::made_lines( 77, 'r2' ), '' );
ok( 'et la seule facon de differer est de se rapprocher de ce qui est montre, jamais de tourner le produit', [ false !== strpos( $dze_ml, 'by coming closer to a part the photographs show' ), false !== strpos( $dze_ml, 'Never by turning the product round' ) ], [ true, true ] );
$dze_aj = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content-ajax.php' );
ok( 'seulement quand la fiche le demande, pour un prompt qui change de vue, apres les notes', false !== strpos( $dze_aj, "if ( ! empty( \$in['aware'] ) && ! empty( \$tpl['vary'] ) ) {\n\t\t\t\t\$prompt .= self::made_lines( \$pid, (string) ( \$tpl['id'] ?? '' ), \$dze_redo );" ), true );
// « La dernière image générée devait être UGC style. Le modèle textuel a demandé un zoom
// sur du détail. Ce n'est pas bon. » Seul un prompt fait pour montrer une autre vue
// entend les cadrages ; les autres choisissent leur sujet eux-mêmes.
ok( 'le prompt de detail change de vue, les autres non, sauf choix sur la carte', [
	DZE_Content::prompt_varies( [ 'id' => 'img_another_angle_of_the_same_product' ] ),
	DZE_Content::prompt_varies( [ 'id' => 'img_scene_in_use' ] ),
	DZE_Content::prompt_varies( [ 'id' => 'img_main_image' ] ),
	DZE_Content::prompt_varies( [ 'id' => 'img_scene_in_use', 'vary' => 1 ] ),
	DZE_Content::prompt_varies( [ 'id' => 'img_another_angle_of_the_same_product', 'vary' => 0 ] ),
], [ true, false, false, true, false ] );
$dze_cs = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content.php' );
ok( 'la carte du prompt le dit, et une carte dessinee avant ne l eteint pas', [
	false !== strpos( $dze_cs, "'vary'        => (int) self::prompt_varies( \$r )," ),
	substr_count( $dze_cs, "[pr_vary_seen]" ),
	false !== strpos( $dze_cs, "'vary'        => array_key_exists( \$i, (array) ( \$in['pr_vary_seen'] ?? [] ) )" ),
], [ true, 2, true ] );
ok( 'et aucune image faite ne repart vers le modele', false !== strpos( $dze_aj, '$avoid = 0;' ), true );
// JETEE, ELLE PART AVEC TOUT CE QUI LA DECRIT.
DZE_Content::settle_shots( 77, [ $dze_u ] );
$dze_w = DZE_Content::pending( 77 );
ok( 'jetee, elle part avec sa cible, son prompt, son modele, son cadrage et ses inventions', [ $dze_w['shots'] ?? [], isset( $dze_w['models'][ $dze_u ] ), isset( $dze_w['frames'][ $dze_u ] ), isset( $dze_w['flags'][ $dze_u ] ), isset( $dze_w['recipes'][ $dze_u ] ) ], [ [ 'https://v3b.fal.media/files/b/x/two.jpg' ], false, false, false, false ] );
$dze_ml = DZE_Content::made_lines( 77, 'r1' );
ok( 'son cadrage est libre a nouveau, celui des autres reste', [ false !== strpos( $dze_ml, 'whole jacket — whole product, front three-quarter left' ), false !== strpos( $dze_ml, 'chest zip — close-up, front' ) ], [ false, true ] );
// UNE PHOTO DEJA DUE NE BLOQUE PLUS une commande : elle rejoint les travaux suivis.
update_post_meta( 78, DZE_Content::FAL_PENDING_META, [ 'id' => 'old-1', 'status' => 'https://queue.fal.run/fal-ai/x/requests/old-1/status', 'response' => '', 'model' => 'nano-banana-2', 'refs' => 1 ] );
$GLOBALS['fal_say']['status'] = 'IN_PROGRESS';
$GLOBALS['tr'] = [];
DZE_Content::$submit_only = true;
$dze_got = DZE_Content::instance()->fal_generate( 'p', [ 'data:image/jpeg;base64,AA' ], '1:1', 78, '' );
DZE_Content::$submit_only = false;
ok( 'la photo due rejoint les travaux suivis, la nouvelle part', [ isset( DZE_Content::jobs( 78 )['old-1'] ), DZE_Content::fal_pending( 78 ), DZE_Content::$submitted['id'] ?? '' ], [ true, [], 'req-1' ] );
DZE_Content::$submitted = [];
// UN APPUI NOMME SON MODELE — le point de « 2.5 » compris.
DZE_Content::$model_override = 'gpt-image-2.5-sunburst';
ok( 'un appui peut nommer son modele, le point compris', DZE_Content::image_model_key(), 'gpt-image-2.5-sunburst' );
DZE_Content::$model_override = 'gpt-image-25-sunburst';
ok( 'un nom inconnu retombe sur le reglage', DZE_Content::image_model_key(), 'nano-banana-2' );
DZE_Content::$model_override = '';
ok( 'le nom est compare au catalogue tel quel, jamais passe par sanitize_key', [ false !== strpos( $dze_aj, "self::\$model_override = isset( self::image_models()[ \$dze_model ] ) ? \$dze_model : '';" ), false !== strpos( $dze_aj, "sanitize_key( wp_unslash( \$_POST['model'] ) )" ) ], [ true, false ] );
$GLOBALS['opts']['dze_content_settings'] = [ 'img_model' => 'gpt-image-2.5-sunburst' ];
$dze_mc = DZE_Content::image_models_cfg();
ok( 'la liste des modeles commence par celui de la boutique, chacun avec son prix', [ $dze_mc[0]['key'], $dze_mc[0]['own'], count( $dze_mc ), isset( $dze_mc[1]['base'], $dze_mc[1]['perRef'], $dze_mc[1]['model'] ) ], [ 'gpt-image-2.5-sunburst', true, count( DZE_Content::image_models() ), true ] );
$GLOBALS['opts']['dze_content_settings'] = [];
// L'ECRAN : il commande, il demande des nouvelles, il ne jette jamais tout.
$dze_cs = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content.php' );
$dze_js = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content.js' );
$dze_jsb = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content-bulk.js' );
ok( 'la page demande des nouvelles par un appel enregistre', false !== strpos( $dze_cs, "add_action( 'wp_ajax_dze_content_job', [ \$this, 'ajax_job' ] );" ), true );
ok( 'le bouton Generate commande, avec la memoire des cadrages et le modele choisi', [ false !== strpos( $dze_js, 'order.async = 1;' ), false !== strpos( $dze_js, 'order.aware = 1;' ), false !== strpos( $dze_js, "order.model = \$('#dze-one-model').val() || '';" ) ], [ true, true, true ] );
ok( 'une image a la fois, chacune prevenue de la precedente — popup et ecran de masse', [ false !== strpos( $dze_js, 'return shootAsync($.extend(imageRequest(tpl, scene), { aware: 1, model: cxModel() }))' ), false !== strpos( $dze_jsb, 'return shootAsync($.extend(imageRequest(id, review, tpl, scene, attempt), { aware: 1 }))' ) ], [ true, true ] );
ok( 'la page demande des nouvelles, jamais la requete ne reste ouverte', false !== strpos( $dze_js, "action: 'dze_content_job'" ), true );
ok( '✕ ne part jamais avec une liste vide (vide = tout jeter) — popup et ecran de masse', [ false !== strpos( $dze_js, "$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID, shots: [ url ] });" ), false !== strpos( $dze_jsb, "$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: id, shots: [ url ] });" ) ], [ true, true ] );
ok( 'le panneau relit le serveur a chaque ouverture, et ne refait plus sa bande d images', [ false !== strpos( $dze_js, "\t\tres.current = null;\n\t\toneBuild();" ), false !== strpos( $dze_js, "if ('image' !== mode) { oneRestore(mode, fid); }" ) ], [ true, true ] );
ok( 'les trois ↻ retirent l image qu ils remplacent', [ substr_count( $dze_js, 'THE ONE IT REPLACES LEAVES THE WAITING LIST TOO' ), substr_count( $dze_jsb, 'THE ONE IT REPLACES LEAVES THE WAITING LIST TOO' ), false !== strpos( $dze_js, 'var dzeOld = vars.made[varGroup($row)];' ) ], [ 1, 1, true ] );
$dze_cl = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-cleanup.php' );
ok( 'les deux nouvelles metas sont declarees au nettoyage', [ false !== strpos( $dze_cl, "'_dze_img_jobs'" ), false !== strpos( $dze_cl, "'_dze_view'" ) ], [ true, true ] );
$GLOBALS['fal_say']['status'] = 'COMPLETED';
$GLOBALS['fal_say']['result'] = '{"images":[{"url":"https://fal.media/x.jpg"}]}';
$GLOBALS['dze_meta'] = [];

// =====================================================================
// 4.506.0 — UNE SEULE POPUP, ✦ REFAIRE EN MIEUX ET HD SUR CHAQUE IMAGE
// =====================================================================
// « Doit être présent sur les images du module generate content seulement.
// On peut enlever les intégrations individuelles de chaque champ inutile, qui
// surcharge l'UI. Il faut juste la popup principale de generate content, sur
// les pages produits individuelles et celle sur l'écran bulk. L'option de
// remake image doit être dispo aussi sur les images copié collées externes. »
$dze_js  = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content.js' );
$dze_jsb = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content-bulk.js' );
$dze_ph  = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/photos.js' );
$dze_pb  = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/paste-box.js' );
$dze_cs  = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content.php' );
$dze_aj  = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content-ajax.php' );
ok( 'plus aucun ✦ plante sur les blocs de la fiche', [ false !== strpos( $dze_js, 'function plantButtons(' ), false !== strpos( $dze_js, 'dze-one-plant' ), false !== strpos( $dze_js, 'dze-hub-rest' ) ], [ false, false, false ] );
ok( 'ni dans le panneau Variations, ni le mur sous la galerie', [ false !== strpos( $dze_cs, "add_action( 'woocommerce_variable_product_before_variations'" ), false !== strpos( $dze_js, 'dze-bricks' ), false !== strpos( $dze_js, 'wallOrder' ) ], [ false, false, false ] );
ok( 'la popup commande ses images et les recupere, ↻ compris', [ false !== strpos( $dze_js, 'function shootAsync(req)' ), false !== strpos( $dze_js, "shootAsync(\$.extend(imageRequest(tpl, undefined, dest), { aware: 1, model: cxModel(), redo: String(url || '') }))" ) ], [ true, true ] );
ok( 'l ecran de masse aussi, ↻ et « une de plus » compris', [ substr_count( $dze_jsb, 'shootAsync($.extend(imageRequest(' ), false !== strpos( $dze_jsb, 'function pollJob(post, job)' ) ], [ 3, true ] );
ok( 'le serveur ne commande sans attendre que pour la liste d attente', false !== strpos( $dze_aj, "&& 'defer' === sanitize_key( (string) wp_unslash( \$_POST['mode'] ?? '' ) ) && ! empty( \$_POST['stash'] );" ), true );
ok( 'le modele se choisit a cote de Launch, et le cout le suit', [ false !== strpos( $dze_js, '<select id="dze-cx-model">' ), false !== strpos( $dze_js, "\$(document).on('change', '#dze-cx-model', function () { drawWillSpend(); remember(); });" ) ], [ true, true ] );
ok( '✦ et HD sur les nouvelles images, popup et masse', [ substr_count( $dze_js, 'dze-cb-shotmake' ), substr_count( $dze_jsb, 'dze-cb-shotmake' ), substr_count( $dze_js, 'dze-cb-shothd' ), substr_count( $dze_jsb, 'dze-cb-shothd' ) ], [ 2, 2, 3, 3 ] );
ok( 'sur les photos du produit, popup et masse', [ substr_count( $dze_js, 'remake: true,' ), substr_count( $dze_jsb, 'remake: true,' ), false !== strpos( $dze_ph, "(opts.remake ? \$('<span class=\"dze-nowacts\"></span>')" ) ], [ 1, 1, true ] );
ok( 'et sur les photos collees, popup et masse', [ substr_count( $dze_js, "{ cls: 'dze-pb-make', label: '✦'" ), substr_count( $dze_jsb, "{ cls: 'dze-pb-make', label: '✦'" ), false !== strpos( $dze_pb, "\$box.on('click', '.dze-pb-act', function (e) {" ) ], [ 1, 1, true ] );
ok( 'chaque ecran qui ecoute une photo est prevenu', [ false !== strpos( $dze_ph, 'on: function (name, fn) { (handlers[name] = handlers[name] || []).push(fn); }' ), false !== strpos( $dze_ph, "emit('ai', " ) ], [ true, true ] );
ok( 'la popup reprend les images encore en cours a sa reouverture', substr_count( $dze_js, 'resumeJobs(cur.jobs);' ), 2 );
// LE PROMPT « REMAKE » : une ligne comme les autres, jamais une recette.
ok( 'une destination « remake » parmi celles d une image', isset( DZE_Content::output_options( 'image' )['remake'] ), true );
$GLOBALS['opts']['dze_content_settings'] = [ 'registry' => [
	[ 'id' => 'img_a', 'name' => 'A', 'type' => 'image', 'prompt' => 'make A', 'output' => 'gallery', 'enabled' => 1, 'valid' => 1 ],
	[ 'id' => 'img_mine', 'name' => 'Mine', 'type' => 'image', 'prompt' => 'MY REMAKE WORDS', 'output' => 'remake', 'enabled' => 1, 'valid' => 1 ],
] ];
$dze_rc = new ReflectionProperty( 'DZE_Content', 'registry_cache' );
$dze_rc->setAccessible( true );
$dze_rc->setValue( null, null );
ok( 'le prompt remake n est jamais une recette', array_map( static fn( $t ) => $t['id'], DZE_Content::image_templates() ), [ 'img_a' ] );
ok( '✦ envoie les mots de la boutique, vers la liste d attente', [ DZE_Content::remake_template()['prompt'], DZE_Content::remake_template()['id'], DZE_Content::remake_template()['target'] ], [ 'MY REMAKE WORDS', 'img_mine', 'gallery' ] );
$GLOBALS['opts']['dze_content_settings'] = [ 'fal_key' => 'fake-fal-key', 'registry' => [ [ 'id' => 'img_a', 'name' => 'A', 'type' => 'image', 'prompt' => 'make A', 'output' => 'gallery', 'enabled' => 1, 'valid' => 1 ] ] ];
$dze_rc->setValue( null, null );
ok( 'sans ligne remake, les mots livres — jamais rien', [ DZE_Content::remake_template()['id'], DZE_Content::remake_template()['prompt'] === DZE_Content::default_remake_prompt() ], [ 'img_remake_better', true ] );
ok( 'la ligne livree n a pas de decor : l image refaite garde le sien', [ DZE_Content::remake_row_default()['output'], DZE_Content::remake_row_default()['scene'] ], [ 'remake', '' ] );
ok( 'elle rejoint les prompts une fois, a l admin', [ false !== strpos( $dze_cs, "add_action( 'admin_init',     [ \$this, 'seed_remake_recipe' ] );" ), false !== strpos( $dze_cs, "\$s['remake_seeded'] = 2;" ) ], [ true, true ] );
$dze_rc->setValue( null, null );
// ✦ SUR UNE PHOTO COLLEE : elle seule est envoyee, avec les mots du remake.
$GLOBALS['dze_meta'] = [];
$GLOBALS['tr']       = [];
$GLOBALS['fal_sent'] = [];
$GLOBALS['fal_say']  = [
	'code'   => 200,
	'body'   => '{"request_id":"rm-1","status_url":"https://queue.fal.run/x/requests/rm-1/status","response_url":"https://queue.fal.run/x/requests/rm-1"}',
	'status' => 'IN_PROGRESS',
	'result' => '{"images":[{"url":"https://v3b.fal.media/files/b/x/rm.jpg"}]}',
	'units'  => '1',
];
$dze_png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';
DZE_Content::$submit_only = true;
try {
	$dze_made = DZE_Content::instance()->shoot( [ 'post' => 77, 'mode' => 'defer', 'stash' => 1, 'remake' => 1, 'src_paste' => $dze_png, 'target' => 'gallery' ] );
} catch ( Throwable $e ) {
	$dze_made = [ 'error' => $e->getMessage() ];
}
DZE_Content::$submit_only = false;
$dze_sent = json_decode( (string) ( end( $GLOBALS['fal_sent'] )['body'] ?? '{}' ), true );
ok( '✦ sur une photo collee : commandee, rangee comme travail', [ $dze_made['job'] ?? ( $dze_made['error'] ?? '?' ), $dze_made['recipe'] ?? '' ], [ 'rm-1', 'img_remake_better' ] );
ok( 'sans photo principale, elle part seule, et c est elle qu on retouche', [ count( (array) ( $dze_sent['image_urls'] ?? [] ) ), ( $dze_sent['image_urls'][0] ?? '' ) === $dze_png ], [ 1, true ] );
ok( 'avec les mots du remake', false !== strpos( (string) ( $dze_sent['prompt'] ?? '' ), 'Remake the picture as a better photograph of this product' ), true );
DZE_Content::$submit_only = true;
try {
	DZE_Content::instance()->shoot( [ 'post' => 77, 'mode' => 'defer', 'stash' => 1, 'remake' => 1 ] );
	$dze_err = '';
} catch ( Throwable $e ) {
	$dze_err = $e->getMessage();
}
DZE_Content::$submit_only = false;
ok( '✦ sans image a refaire : dit, rien commande', $dze_err, 'Pick the picture to remake.' );
// LA PHOTO PRINCIPALE MENE. « Sur ce produit on est sur un camo kryptek
// mandrake, or je n'ai que des images d'autres camo sous la main pour les
// détails. Il faut je pense envoyer dans tous les cas l'image 1 principale comme
// référence. » Un vrai fichier pour la photo principale (501) du produit 77.
if ( ! function_exists( 'image_get_intermediate_size' ) ) { function image_get_intermediate_size( ...$a ) { return false; } }
if ( ! function_exists( 'get_attached_file' ) ) { function get_attached_file( $id ) { return (string) ( $GLOBALS['att_files'][ (int) $id ] ?? '' ); } }
if ( ! function_exists( 'get_post_mime_type' ) ) { function get_post_mime_type( $id ) { return 'image/png'; } }
if ( ! function_exists( 'trailingslashit' ) ) { function trailingslashit( $p ) { return rtrim( (string) $p, '/' ) . '/'; } }
if ( ! function_exists( 'wp_get_upload_dir' ) ) { function wp_get_upload_dir() { return [ 'basedir' => sys_get_temp_dir() ]; } }
$dze_mainfile = tempnam( sys_get_temp_dir(), 'dzemain' );
file_put_contents( $dze_mainfile, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ) );
$GLOBALS['att_files'][501] = $dze_mainfile;
$GLOBALS['thumbs'][77]     = 501;
$GLOBALS['fal_sent']       = [];
$GLOBALS['tr']             = [];
$GLOBALS['fal_say']['body'] = '{"request_id":"rm-2","status_url":"https://queue.fal.run/x/requests/rm-2/status","response_url":"https://queue.fal.run/x/requests/rm-2"}';
DZE_Content::$submit_only = true;
try {
	$dze_made = DZE_Content::instance()->shoot( [ 'post' => 77, 'mode' => 'defer', 'stash' => 1, 'remake' => 1, 'src_paste' => $dze_png, 'target' => 'gallery' ] );
} catch ( Throwable $e ) {
	$dze_made = [ 'error' => $e->getMessage() ];
}
DZE_Content::$submit_only = false;
$dze_sent = json_decode( (string) ( end( $GLOBALS['fal_sent'] )['body'] ?? '{}' ), true );
$dze_urls = (array) ( $dze_sent['image_urls'] ?? [] );
ok( '✦ envoie la photo principale d abord, puis l image a refaire', [ $dze_made['job'] ?? ( $dze_made['error'] ?? '?' ), count( $dze_urls ), 0 === strpos( (string) ( $dze_urls[0] ?? '' ), 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk' ), ( $dze_urls[1] ?? '' ) === $dze_png ], [ 'rm-2', 2, true, true ] );
ok( 'et dit ce qu est chacune : le produit, puis l image a refaire', [ false !== strpos( (string) ( $dze_sent['prompt'] ?? '' ), 'Image 1 is the product itself, as this shop sells it' ), false !== strpos( (string) ( $dze_sent['prompt'] ?? '' ), 'Image 1 is the picture to work on' ) ], [ true, false ] );
ok( 'la photo principale refaite elle-meme part seule', false !== strpos( $dze_aj, 'if ( $remake && $dze_main > 0 && $dze_main !== $src_att ) {' ), true );
unset( $GLOBALS['thumbs'][77], $GLOBALS['att_files'][501] );
@unlink( $dze_mainfile );
// L'ORDRE EST CELUI DU MODELE. Mesuré le 01/10/2026 sur un détail A-Tacs refait
// sur la page Kryptek : Nano retouche la PREMIERE image (il faut lui donner le
// produit d'abord), GPT Image prend la première pour sujet (il faut lui donner
// l'image à refaire d'abord).
ok( 'la photo principale en premier pour Nano', [ $dze_made['job'] ?? '?', count( $dze_urls ) ], [ 'rm-2', 2 ] );
ok( 'et la forme de l image refaite est demandee', ( $dze_sent['aspect_ratio'] ?? '' ), '1:1' );
$GLOBALS['att_files'][501] = $dze_mainfile = tempnam( sys_get_temp_dir(), 'dzemain' );
file_put_contents( $dze_mainfile, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ) );
$GLOBALS['thumbs'][77]      = 501;
$GLOBALS['fal_sent']        = [];
$GLOBALS['tr']              = [];
$GLOBALS['fal_say']['body'] = '{"request_id":"rm-3","status_url":"https://queue.fal.run/x/requests/rm-3/status","response_url":"https://queue.fal.run/x/requests/rm-3"}';
DZE_Content::$submit_only    = true;
DZE_Content::$model_override = 'gpt-image-2.5-sunburst';
try {
	$dze_made = DZE_Content::instance()->shoot( [ 'post' => 77, 'mode' => 'defer', 'stash' => 1, 'remake' => 1, 'src_paste' => $dze_png, 'target' => 'gallery' ] );
} catch ( Throwable $e ) {
	$dze_made = [ 'error' => $e->getMessage() ];
}
DZE_Content::$submit_only    = false;
DZE_Content::$model_override = '';
$dze_sent = json_decode( (string) ( end( $GLOBALS['fal_sent'] )['body'] ?? '{}' ), true );
$dze_urls = (array) ( $dze_sent['image_urls'] ?? [] );
ok( 'l image a refaire en premier pour GPT Image, la photo principale ensuite', [ $dze_made['job'] ?? ( $dze_made['error'] ?? '?' ), count( $dze_urls ), ( $dze_urls[0] ?? '' ) === $dze_png, 0 === strpos( (string) ( $dze_urls[1] ?? '' ), 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk' ) ], [ 'rm-3', 2, true, true ] );
ok( 'et la note nomme chacune a sa place', [ false !== strpos( (string) ( $dze_sent['prompt'] ?? '' ), 'Image 1 is the picture to remake' ), false !== strpos( (string) ( $dze_sent['prompt'] ?? '' ), 'Image 2 is the product itself' ), false !== strpos( (string) ( $dze_sent['prompt'] ?? '' ), '{picture}' ) ], [ true, true, false ] );
ok( 'GPT recoit la forme en taille d image', ( $dze_sent['image_size'] ?? '' ), 'square_hd' );
unset( $GLOBALS['thumbs'][77], $GLOBALS['att_files'][501] );
@unlink( $dze_mainfile );
ok( 'la forme la plus proche d une image', [ DZE_Content::nearest_ratio( 800, 532 ), DZE_Content::nearest_ratio( 1000, 1000 ), DZE_Content::nearest_ratio( 1080, 1350 ), DZE_Content::nearest_ratio( 1920, 1080 ), DZE_Content::nearest_ratio( 0, 5 ) ], [ '3:2', '1:1', '4:5', '16:9', '' ] );
ok( 'les deux Nano prennent la reference d abord, pas les autres', array_map( static fn( $m ) => ! empty( $m['remake_ref_first'] ), DZE_Content::image_models() ), [ 'nano-banana-2' => true, 'nano-banana-pro' => true, 'gpt-image-2.5-sunburst' => false, 'gpt-image-2.5-flare' => false, 'flux-2-pro' => false ] );
// LES MOTS LIVRES EN 4.506.0, JAMAIS TOUCHES, SONT MIS A JOUR ; CEUX DU PROPRIETAIRE JAMAIS.
$dze_v1 = ( new ReflectionMethod( 'DZE_Content', 'remake_prompt_v1' ) );
$dze_v1->setAccessible( true );
$dze_seed = static function ( string $words, $flag ) use ( $dze_rc ) {
	$GLOBALS['opts']['dze_content_settings'] = [ 'remake_seeded' => $flag, 'registry' => [
		[ 'id' => 'img_remake_better', 'name' => 'Remake better', 'type' => 'image', 'prompt' => $words, 'output' => 'remake', 'enabled' => 1, 'valid' => 1 ],
	] ];
	$dze_rc->setValue( null, null );
	DZE_Content::instance()->seed_remake_recipe();
	$dze_rc->setValue( null, null );
	return [ DZE_Content::registry()[0]['prompt'] ?? '', (int) ( $GLOBALS['opts']['dze_content_settings']['remake_seeded'] ?? 0 ) ];
};
ok( 'les mots livres jamais touches sont mis a jour', $dze_seed( $dze_v1->invoke( null ), 1 ), [ DZE_Content::default_remake_prompt(), 2 ] );
ok( 'les mots du proprietaire ne bougent pas', $dze_seed( 'MES MOTS A MOI', 1 ), [ 'MES MOTS A MOI', 2 ] );
$GLOBALS['opts']['dze_content_settings'] = [ 'remake_seeded' => 1, 'registry' => [] ];
$dze_rc->setValue( null, null );
DZE_Content::instance()->seed_remake_recipe();
$dze_rc->setValue( null, null );
ok( 'une ligne supprimee par le proprietaire ne revient pas', [ count( array_filter( DZE_Content::registry(), static fn( $r ) => 'remake' === ( $r['output'] ?? '' ) ) ), (int) $GLOBALS['opts']['dze_content_settings']['remake_seeded'] ], [ 0, 2 ] );
unset( $GLOBALS['opts']['dze_content_settings'] );
$dze_rc->setValue( null, null );
ok( 'une photo du produit est lue comme image, et seulement une des siennes', [ false !== strpos( $dze_aj, "if ( ! in_array( \$src_att, array_map( 'intval', self::product_image_ids( \$pid ) ), true ) ) {" ), false !== strpos( $dze_aj, "\$src       = \$this->fal_source_data_uri( \$src_att, 'full' );" ) ], [ true, true ] );
// HD : 2048 pixels, de ×1,5 a ×4, rien quand c est deja assez grand.
ok( 'HD amene le grand cote a 2048', [ DZE_Content::enlarge_factor( 800, 532 ), DZE_Content::enlarge_factor( 1600, 900 ), DZE_Content::enlarge_factor( 300, 300 ), DZE_Content::enlarge_factor( 2048, 1000 ), DZE_Content::enlarge_factor( 0, 0 ) ], [ 2.56, 1.5, 4.0, 0.0, 0.0 ] );
$GLOBALS['fal_say']['result'] = '{"image":{"url":"https://v3b.fal.media/files/b/x/hd.jpg","width":2048,"height":1362}}';
$GLOBALS['fal_say']['status'] = 'COMPLETED';
$dze_hd = DZE_Content::fal_fetch( [ 'id' => 'hd-1', 'status' => 'https://queue.fal.run/x/requests/hd-1/status', 'response' => 'https://queue.fal.run/x/requests/hd-1', 'model' => DZE_Content::UPSCALER, 'refs' => 0, 'mp' => 2.8 ], 77, '[enlarged]', microtime( true ) );
ok( 'un agrandissement repond d une seule image, payee au megapixel', [ $dze_hd, DZE_Content::last_image_cost() ], [ 'https://v3b.fal.media/files/b/x/hd.jpg', round( 0.0025 * 2048 * 1362 / 1000000, 4 ) ] );
ok( 'et ne se decrit pas : il garde le cadrage de son original', false !== strpos( $dze_aj, "\$read = 'enlarge' === (string) ( \$job['tool'] ?? '' ) ? null : self::read_picture(" ), true );
ok( 'une image generee agrandie reste generee', false !== strpos( $dze_aj, "\$recipe = '' !== \$dze_r ? \$dze_r : 'img_enlarged';" ), true );
$GLOBALS['fal_say']['status'] = 'COMPLETED';
$GLOBALS['fal_say']['result'] = '{"images":[{"url":"https://fal.media/x.jpg"}]}';
$GLOBALS['dze_meta'] = [];
unset( $GLOBALS['opts']['dze_content_settings'] );

// 4.506.3 — L'ECRAN DE MASSE : PLUS DE COMMANDE PAR PRODUIT, LE MODELE SUR L'ECRAN.
// « Images for this product follows the run · Give this product its own order
// […] A supprimer. Inutile. Et pour la génération d'images on va quand même pas
// changer de modèle dans les paramètres à chaque fois si ? Très désagréable. »
$dze_jsb = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content-bulk.js' );
$dze_js  = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content.js' );
$dze_cs  = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content.php' );
ok( 'plus de commande propre a un produit sur l ecran de masse', [ false !== strpos( $dze_jsb, 'dze-cb-ownwrap' ), false !== strpos( $dze_jsb, 'hasOwn(' ), false !== strpos( $dze_cs, "'ownMark'" ), false !== strpos( $dze_jsb, 'function jobsFor() { return tplJobs(); }' ) ], [ false, false, false, true ] );
ok( 'le modele se choisit en haut de l ecran de masse', [ false !== strpos( $dze_cs, '<select id="dze-cb-model">' ), false !== strpos( $dze_cs, "'imageModels' => self::image_models_cfg()," ) ], [ true, true ] );
ok( 'il part avec chaque image du lancement, ✦ compris', [ false !== strpos( $dze_jsb, "template: tpl, model: cbModel() };" ), false !== strpos( $dze_jsb, "remake: 1, target: 'gallery', model: cbModel() };" ) ], [ true, true ] );
ok( 'le cout annonce le suit', [ false !== strpos( $dze_jsb, 'perImage(refsOf(id, j), cbPrice())' ), false !== strpos( $dze_jsb, "\$(document).on('change', '#dze-cb-model', function () { drawPicked(); });" ) ], [ true, true ] );
ok( 'et ce navigateur s en souvient', [ false !== strpos( $dze_jsb, "m.bulkModel = \$('#dze-cb-model').val() || '';" ), false !== strpos( $dze_jsb, "\$('#dze-cb-model').val(m.bulkModel);" ) ], [ true, true ] );
// 4.506.4 — « Bouton Look ▾ sur page bulk cassé » : syncOldMainRow() lisait encore
// `own`, supprimé avec la commande par produit. ReferenceError à chaque ouverture
// d'un panneau. Aucun nom de cette commande ne doit plus être lu, hors commentaires.
$dze_code = preg_replace( '~^\s*//.*$~m', '', $dze_jsb );
ok( 'aucun nom de la commande par produit n est encore lu', [ false !== strpos( $dze_code, 'own ||' ), false !== strpos( $dze_code, 'kept[id]' ), (bool) preg_match( '~\\b(hasOwn|markOwn|ownState|buildOwn|shotsSaid)\\(~', $dze_code ), (bool) preg_match( '~(?<![\\w.$])own\\[~', $dze_code ) ], [ false, false, false, false ] );
ok( 'le panneau Look ne depend plus que des lignes du lancement', false !== strpos( $dze_jsb, "function syncOldMainRow() {\n\t\t// Any row of the run that writes a main image raises it" ), true );
ok( 'la popup aussi se souvient du dernier modele', [ false !== strpos( $dze_js, "model: cxModel()\n\t\t};" ), false !== strpos( $dze_js, "modelOptions(au.model)" ) ], [ true, true ] );

// 4.507.0 — LE « i » DE CHAQUE IMAGE FAITE PAR UN MODELE, ET ↻ QUI REFAIT SON CADRAGE.
// « tu vas rajouter un petit i pour info sur toutes les images générées par IA.
// au clic, un text doit apparaître pour dire quel prompt a été utilisé, et quel
// modèle d'ia, et quel prix. »
// « La fonction recommencer sur les images générées me semble comporter une
// lacune. Ces images relancées sont particulièrement sujettes au slop. »
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'get_post_type' ) ) { function get_post_type( $id ) { return $GLOBALS['dze_types'][ (int) $id ] ?? false; } }
require __DIR__ . '/../' . $dir . '/includes/class-ai-card.php';
$GLOBALS['opts']['dze_content_settings'] = [ 'registry' => [
	[ 'id' => 'rx', 'type' => 'image', 'name' => 'Details shoot', 'prompt' => 'Shoot the details.', 'output' => 'gallery', 'valid' => 1 ],
] ];
( new ReflectionProperty( 'DZE_Content', 'registry_cache' ) )->setValue( null, null );
ok( 'le nom d un prompt se lit par son id', [ DZE_Content::recipe_name( 'rx' ), DZE_Content::recipe_name( 'nope' ), DZE_Ai_Card::recipe_name( 'img_enlarged' ) ], [ 'Details shoot', '', 'HD enlargement' ] );
ok( 'un prix ne perd pas son centime', [ DZE_Ai_Card::money( 0.08 ), DZE_Ai_Card::money( 0.0125 ), DZE_Ai_Card::money( 1.5 ) ], [ '$0.08', '$0.013', '$1.50' ] );
// UNE IMAGE COMMANDEE PUIS RAMASSEE : sa fiche porte le modele, le prix, les mots envoyes, et le prompt qui l a faite.
$dze_cu = 'https://v3b.fal.media/files/b/x/card.jpg';
DZE_Content::job_add( 79, [ 'id' => 'req-c', 'status' => 'https://queue.fal.run/fal-ai/x/requests/req-c/status', 'response' => '', 'model' => 'nano-banana-2', 'refs' => 2, 'stash' => 1, 'recipe' => 'rx', 'target' => 'gallery', 'tool' => 'generate', 'asked' => "Shoot the details.\n\n[2 photograph(s) sent · aspect ratio 1:1 · Nano Banana 2]" ] );
$GLOBALS['fal_say']['status'] = 'COMPLETED';
$GLOBALS['fal_say']['result'] = '{"images":[{"url":"' . $dze_cu . '"}]}';
$GLOBALS['mai_view'] = '{"part":"left cuff","distance":"close-up","angle":"front three-quarter left","worn":false,"invented":[]}';
$dze_r = $dze_look->invoke( DZE_Content::instance(), 79, 'req-c' );
$dze_c = DZE_Ai_Card::get( $dze_cu );
ok( 'ramassee, sa fiche dit le modele, le prix, les mots et le prompt', [
	$dze_r['done'] ?? 0, $dze_c['model'] ?? '', (float) ( $dze_c['cost'] ?? 0 ) > 0, false !== strpos( (string) ( $dze_c['prompt'] ?? '' ), 'Shoot the details.' ),
	$dze_c['refs'] ?? -1, $dze_c['recipe'] ?? '', $dze_c['name'] ?? '', $dze_c['tool'] ?? '', $dze_c['pid'] ?? 0,
], [ 1, 'nano-banana-2', true, true, 2, 'rx', 'Details shoot', 'generate', 79 ] );
ok( 'la fiche attend dans une option a part, jamais chargee d office ni sur le produit', [ isset( $GLOBALS['opts'][ 'dze_aic_' . md5( $dze_cu ) ] ), isset( $GLOBALS['dze_meta'][79]['_dze_ai_card'] ) ], [ true, false ] );
// LE « i » D UNE IMAGE EN ATTENTE.
$_POST = [ 'post' => 79, 'url' => $dze_cu ];
try { DZE_Ai_Card::ajax(); $dze_j = null; } catch ( DZE_Json_Sent $e ) { $dze_j = $e; }
$_POST = [];
ok( 'le « i » d une image en attente : prompt, modele, prix, cadrage', [
	$dze_j ? $dze_j->ok : null, $dze_j->payload['known'] ?? null, $dze_j->payload['name'] ?? '', '' !== (string) ( $dze_j->payload['model'] ?? '' ),
	0 === strpos( (string) ( $dze_j->payload['cost'] ?? '' ), '$' ), $dze_j->payload['framing'] ?? '', false !== strpos( (string) ( $dze_j->payload['prompt'] ?? '' ), '[2 photograph(s) sent' ),
], [ true, true, 'Details shoot', true, true, 'left cuff — close-up, front three-quarter left', true ] );
$_POST = [ 'post' => 80, 'url' => $dze_cu ];
try { DZE_Ai_Card::ajax(); $dze_j = null; } catch ( DZE_Json_Sent $e ) { $dze_j = $e; }
$_POST = [];
ok( 'la fiche d un produit ne se lit pas par un autre', $dze_j->payload['known'] ?? null, false );
// RANGEE SUR LE PRODUIT : la fiche s'arrete avec l'attente, rien n'est ecrit sur
// la piece jointe. « Je veux juste l'info temporairement sur les images
// générées pas encore sur le produit. »
DZE_Ai_Card::file( $dze_cu, 5001, 'rx' );
ok( 'rangee, la fiche s arrete : rien sur la piece jointe, plus rien en attente', [ get_post_meta( 5001, '_dze_ai_card', true ), DZE_Ai_Card::get( $dze_cu ), isset( get_option( 'dze_aic_index', [] )[ 'dze_aic_' . md5( $dze_cu ) ] ) ], [ '', [], false ] );
$_POST = [ 'att' => 5001 ];
try { DZE_Ai_Card::ajax(); $dze_j = null; } catch ( DZE_Json_Sent $e ) { $dze_j = $e; }
$_POST = [];
ok( 'une image rangee n a plus de « i »', $dze_j ? $dze_j->ok : null, false );
// JETEE OU REMPLACEE : sa fiche part avec elle.
$dze_cu2 = 'https://v3b.fal.media/files/b/x/card2.jpg';
DZE_Ai_Card::put( 79, $dze_cu2, [ 'model' => 'nano-banana-2', 'cost' => 0.08, 'prompt' => 'p' ] );
DZE_Content::settle_shots( 79, [ $dze_cu2 ] );
ok( 'jetee, sa fiche part avec elle', [ DZE_Ai_Card::get( $dze_cu2 ), isset( $GLOBALS['opts'][ 'dze_aic_' . md5( $dze_cu2 ) ] ) ], [ [], false ] );
// LES FICHES TROP VIEILLES PARTENT : une adresse fal ne vit pas 45 jours.
$GLOBALS['opts']['dze_aic_index'] = [ 'dze_aic_old' => time() - 3888000 - 60 ];
$GLOBALS['opts']['dze_aic_old']   = [ 'model' => 'x' ];
DZE_Ai_Card::put( 79, 'https://v3b.fal.media/files/b/x/card3.jpg', [ 'model' => 'nano-banana-2' ] );
ok( 'les fiches de plus de 45 jours partent', [ isset( $GLOBALS['opts']['dze_aic_old'] ), isset( $GLOBALS['opts']['dze_aic_index']['dze_aic_old'] ), count( $GLOBALS['opts']['dze_aic_index'] ) ], [ false, false, 1 ] );
// CE QU UN ✦ OU UN HD REFAIT : une image faite ici, une vraie photo, une photo collee.
DZE_Ai_Card::put( 79, $dze_cu2, [ 'model' => 'gpt-image-2.5-sunburst', 'cost' => 0.06, 'recipe' => 'rx' ] );
update_post_meta( 5001, DZE_Content::META_RECIPE, 'rx' );
ok( 'la source d un ✦ ou d un HD est nommee', [ DZE_Ai_Card::source_of( 79, $dze_cu2, 0, false )['name'] ?? '', DZE_Ai_Card::source_of( 79, '', 5003, false ), DZE_Ai_Card::source_of( 79, '', 0, true ), DZE_Ai_Card::source_of( 79, '', 5001, false )['kind'] ?? '' ], [ 'Details shoot', [ 'kind' => 'photo' ], [ 'kind' => 'pasted' ], 'ai' ] );
$dze_v = DZE_Ai_Card::view( [ 'tool' => 'enlarge', 'model' => DZE_Content::UPSCALER, 'cost' => 0.0125, 'base' => [ 'kind' => 'photo' ] ], [], '' );
ok( 'un agrandissement dit ce qu il a agrandi', [ $dze_v['name'], $dze_v['cost'], $dze_v['from'] ], [ 'HD enlargement', '$0.013', 'a photograph of the product' ] );
// LE BRANCHEMENT, la ou chaque image passe.
$dze_cs = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content.php' );
$dze_aj = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content-ajax.php' );
$dze_ph = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/photos.js' );
$dze_js = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content.js' );
$dze_jb = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content-bulk.js' );
ok( 'la fiche s ecrit la ou le prix est connu, et suit l image rangee', [
	false !== strpos( $dze_cs, "DZE_Ai_Card::put( \$pid, (string) \$url, [\n\t\t\t\t'model'  => \$key,\n\t\t\t\t'cost'   => self::\$last_cost,\n\t\t\t\t'prompt' => \$asked," ),
	false !== strpos( $dze_cs, "DZE_Ai_Card::file( \$url, (int) \$att, \$recipe_id );" ),
	false !== strpos( $dze_aj, "DZE_Ai_Card::drop( \$urls );" ),
	false !== strpos( $dze_aj, "'tool'   => \$remake ? 'remake' : 'generate',\n\t\t\t\t\t'base'   => \$dze_base," ),
	false !== strpos( $dze_aj, 'DZE_Ai_Card::is_ai(' ),
], [ true, true, true, true, false ] );
ok( 'le « i » n est que sur les images en attente, jamais sur celles du produit', [
	false !== strpos( $dze_ph, 'aiButton(opts.post' ),
	false !== strpos( $dze_ph, "action: 'dze_ai_card'" ),
	false !== strpos( $dze_ph, "\$pop.find('.dze-ai-words').text(d.prompt || '');" ),
	false !== strpos( $dze_js, "\t\t\t\t\taiMark(url),\n" ),
	false !== strpos( $dze_js, "\t\t\t\t\t\taiMark(u, true),\n" ),
	false !== strpos( $dze_jb, "window.dzePhotos.aiButton(id, url, 0, false, (b.shotFlags || {})[url])" ),
	false !== strpos( $dze_cs, "'galleryAi'" ),
	false !== strpos( $dze_ph, 'function decorateBoxes' ),
	false !== strpos( (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-ai-card.php' ), 'attachment_fields_to_edit' ),
], [ false, true, true, true, true, true, false, false, false ] );
ok( 'ce que 4.507-4.508 avaient ecrit sur les pieces jointes part une fois', false !== strpos( $dze_cs, "foreach ( [ '_dze_ai_card', '_dze_flags', '_dze_stands_for' ] as \$k ) {\n\t\t\tdelete_post_meta_by_key( \$k );" ), true );
// ↻ NE S INTERDIT PLUS SON CADRAGE — ET NE SE L IMPOSE PLUS NON PLUS : refaire une image
// ratee parce que son cadrage etait faux redemandait ce cadrage (02/10/2026).
$GLOBALS['dze_meta'][81] = [];
DZE_Content::stash( 81, [ 'shot' => 'https://v3b.fal.media/files/b/x/one.jpg', 'recipe' => 'rx', 'frame' => 'V-ONE whole jacket, front three-quarter', 'flags' => [] ] );
DZE_Content::stash( 81, [ 'shot' => 'https://v3b.fal.media/files/b/x/two.jpg', 'recipe' => 'rx', 'frame' => 'V-TWO close-up of the cuff', 'flags' => [] ] );
$dze_ml = DZE_Content::made_lines( 81, 'rx' );
ok( 'sans ↻, rien ne change : tous les cadrages sont a eviter', [ false !== strpos( $dze_ml, 'V-ONE' ), false !== strpos( $dze_ml, 'V-TWO' ), false !== strpos( $dze_ml, 'Do not make any of them again' ) ], [ true, true, true ] );
$dze_ml = DZE_Content::made_lines( 81, 'rx', 'https://v3b.fal.media/files/b/x/two.jpg' );
ok( '↻ : son cadrage n est ni interdit ni impose ; les autres restent a eviter', [
	substr_count( $dze_ml, 'V-TWO' ), false !== strpos( $dze_ml, 'THE PHOTOGRAPH THIS ONE REPLACES' ),
	false !== strpos( $dze_ml, 'V-ONE whole jacket, front three-quarter' ), false !== strpos( $dze_ml, 'Do not make any of them again' ),
	array_key_exists( 'again', DZE_Content::$made_said ),
], [ 0, false, true, true, false ] );
$dze_ml = DZE_Content::made_lines( 81, 'rx', 'https://v3b.fal.media/files/b/x/elsewhere.jpg' );
ok( 'une adresse qui n attend pas sur ce produit ne change rien', [ false !== strpos( $dze_ml, 'V-TWO' ), false !== strpos( $dze_ml, 'Do not make any of them again' ) ], [ true, true ] );
ok( 'les deux ↻ envoient l image qu ils remplacent', [
	false !== strpos( $dze_js, "{ aware: 1, model: cxModel(), redo: String(url || '') }" ),
	false !== strpos( $dze_jb, "{ aware: 1, redo: String(url || '') }" ),
	false !== strpos( $dze_aj, "\$prompt .= self::made_lines( \$pid, (string) ( \$tpl['id'] ?? '' ), \$dze_redo );" ),
], [ true, true, true ] );
ok( 'la fiche reste dans la fenetre : dessus quand la place manque dessous, jamais plus haute que l ecran', [ false !== strpos( (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/photos.js' ), "var top = (h > below && above > below) ? r.top - h - 6 : r.bottom + 6;" ), false !== strpos( (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/photos.js' ), "\$pop.find('.dze-ai-full').on('toggle', function () { aiPlace(\$pop, \$b); });" ) ], [ true, true ] );
ok( 'la fiche reste ouverte quand on fait defiler le prompt qu elle montre', false !== strpos( (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/photos.js' ), "if (t && t.nodeType === 1 && \$(t).closest('.dze-ai-pop').length) { return; }" ), true );
// « Photographs from elsewhere — il faudrait les afficher dans la même taille que les images produit. »
$dze_css = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/css/content.css' );
// « Il faut que toutes les images soient de la même taille pour une meilleur lisibilité
// et un affichage plus standardisé. »
ok( 'toutes les images d un produit ont une seule taille', [ false !== strpos( $dze_css, '--dze-pic: 200px;' ), false !== strpos( $dze_css, '.dze-cb-nowshot { display: inline-block; width: var(--dze-pic); height: var(--dze-pic); }' ), false !== strpos( $dze_css, '.dze-pb-tile img { display: block; width: var(--dze-pic); height: var(--dze-pic);' ), false !== strpos( $dze_css, 'position: relative; width: var(--dze-pic); height: var(--dze-pic); flex: 0 0 var(--dze-pic);' ), false !== strpos( $dze_css, '.dze-cb-srcs .dze-one-srcpick { width: var(--dze-pic); height: var(--dze-pic); }' ), false !== strpos( $dze_css, '.dze-pb-list .dze-pb-tile img { max-height: none; margin: 0; }' ) ], [ true, true, true, true, true, true ] );
unset( $GLOBALS['mai_view'] );

// 4.508.0 — LE LECTEUR (Sonnet 5.5), LES PHOTOS DE LA PAGE, ET « REMPLACE CETTE PHOTO ».
// « Attention les instructions haiku sont parfois eux même du slop textuel » —
// « J'aimerai une option pour remplacer des images galerie avec des images
// fraichement générées. Par exemple, remake, ou hd ».
if ( ! function_exists( 'set_post_thumbnail' ) ) { function set_post_thumbnail( $p, $a ) { $GLOBALS['thumbs'][ (int) $p ] = (int) $a; return true; } }
if ( ! function_exists( 'delete_post_thumbnail' ) ) { function delete_post_thumbnail( $p ) { unset( $GLOBALS['thumbs'][ (int) $p ] ); return true; } }
if ( ! function_exists( 'clean_post_cache' ) ) { function clean_post_cache( $p ) {} }
if ( ! function_exists( 'wc_delete_product_transients' ) ) { function wc_delete_product_transients( $p ) {} }
$dze_jpg = sys_get_temp_dir() . '/dze-test-photo-' . getmypid() . '.jpg';
// A real JPEG, one pixel: what a photograph of the product is to the reader.
file_put_contents( $dze_jpg, base64_decode( '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=' ) );
if ( ! function_exists( 'get_attached_file' ) ) { function get_attached_file( $id ) { return (string) ( $GLOBALS['att_files'][ (int) $id ] ?? '' ); } }
$GLOBALS['att_files'] = [ 9001 => $dze_jpg, 9002 => $dze_jpg, 9003 => $dze_jpg, 9004 => $dze_jpg ];
// Product 90: a real main photograph (9001), a real gallery photograph (9002), a made one (9003).
$GLOBALS['thumbs'][90]  = 9001;
$GLOBALS['gallery'][90] = [ 9002, 9003 ];
$GLOBALS['dze_meta'][90] = [ '_product_image_gallery' => '9002,9003' ];
update_post_meta( 9003, DZE_Content::META_RECIPE, 'rx' );
$GLOBALS['dze_types'][9001] = 'attachment';
$GLOBALS['dze_types'][9002] = 'attachment';
$GLOBALS['dze_types'][9003] = 'attachment';

// LE LECTEUR : la forme imposée, et ce qu'une image invente.
ok( 'une ligne de cadrage ne se dit qu avec les mots permis', [
	DZE_Content::frame_line( [ 'part' => 'hood', 'distance' => 'close-up', 'angle' => 'side', 'worn' => true ] ),
	DZE_Content::frame_line( [ 'part' => 'hood', 'distance' => 'very close', 'angle' => 'side' ] ),
	DZE_Content::frame_line( [ 'part' => '', 'distance' => 'close-up', 'angle' => 'side' ] ),
], [ 'hood — close-up, side, worn', '', '' ] );
$GLOBALS['mai_vision'] = [];
$GLOBALS['mai_view']   = '{"part":"hood and collar","distance":"close-up","angle":"front three-quarter right","worn":false,"invented":["label with the text VETER inside the collar"]}';
$dze_rp = DZE_Content::read_picture( 'https://v3b.fal.media/files/b/x/veter.jpg', 90 );
$dze_a  = $GLOBALS['mai_vision'][0] ?? [];
ok( 'le lecteur rend un cadrage sans detail, et ce que l image invente', [ $dze_rp['frame'], $dze_rp['invented'] ], [ 'hood and collar — close-up, front three-quarter right', [ 'label with the text VETER inside the collar' ] ] );
ok( 'il voit l image faite ET les vraies photos du produit, la principale d abord', [ count( $dze_a[2] ?? [] ), $dze_a[3] ?? '', $dze_a[6]['output_config']['format']['schema']['required'] ?? [], in_array( 'server-side-fallback-2026-07-01', (array) ( $dze_a[6]['_betas'] ?? [] ), true ) ], [ 3, 'claude-sonnet-5-5', [ 'part', 'distance', 'angle', 'worn', 'invented' ], true ] );
ok( 'distances et angles sont des listes fermees', [ $dze_a[6]['output_config']['format']['schema']['properties']['distance']['enum'] ?? [], count( $dze_a[6]['output_config']['format']['schema']['properties']['angle']['enum'] ?? [] ) ], [ [ 'whole product', 'half', 'close-up', 'macro' ], 8 ] );
// « Ton outil parfois flag du contenu qui est bon pour le content ugc. » Le sac a dos du
// randonneur et les renforts de coudes que la description annonce etaient « inventes ».
$GLOBALS['mai_vision'] = [];
$GLOBALS['wc_desc'][90] = '<p>Reinforced Elbows and Shoulders. Hook and Loop Fields: for unit, flag and morale patches.</p>';
DZE_Content::read_picture( 'https://v3b.fal.media/files/b/x/veter.jpg', 90 );
$dze_a = $GLOBALS['mai_vision'][0] ?? [];
ok( 'le lecteur lit aussi la description, et la scene n est jamais le produit', [
	false !== strpos( (string) ( $dze_a[0] ?? '' ), 'the SCENE of a picture of the product in use — the person, their other clothes, what they carry or wear with it (a backpack' ),
	false !== strpos( (string) ( $dze_a[0] ?? '' ), 'a feature the description names' ),
	false !== strpos( (string) ( $dze_a[1] ?? '' ), "What the shop says about the product: Reinforced Elbows and Shoulders. Hook and Loop Fields: for unit, flag and morale patches." ),
], [ true, true, true ] );
unset( $GLOBALS['wc_desc'][90] );
// « Gallery photographs — 3 of 5 — ne semble pas être mis à jour. »
$dze_jbk = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content-bulk.js' );
$dze_csk = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content.php' );
ok( 'la ligne sous le nom est relue quand l ecran ecrit sur le produit ou l ouvre', [
	false !== strpos( $dze_jbk, "\$.post(cfg.ajaxUrl, { action: 'dze_diag_todo', nonce: cfg.diagNonce, post: id })" ),
	substr_count( $dze_jbk, 'refreshShort(' ),
	false !== strpos( $dze_csk, "'diagNonce' => class_exists( 'DZE_Diagnostic' ) ? wp_create_nonce( DZE_Diagnostic::NONCE ) : ''," ),
], [ true, 4, true ] );

// LES PHOTOS DE LA PAGE : lues une fois, en un appel, et dites a la commande.
$GLOBALS['mai_vision'] = [];
$GLOBALS['mai_view']   = '{"photos":[{"part":"whole jacket","distance":"whole product","angle":"front","worn":false},{"part":"left sleeve","distance":"half","angle":"side","worn":false},{"part":"hood","distance":"close-up","angle":"front","worn":false}]}';
$dze_pf = DZE_Content::page_frames( 90 );
ok( 'les photos de la page sont lues en un seul appel et gardees sur chacune', [ count( $GLOBALS['mai_vision'] ), count( $GLOBALS['mai_vision'][0][2] ?? [] ), get_post_meta( 9001, DZE_Content::META_FRAME, true ), get_post_meta( 9003, DZE_Content::META_FRAME, true ) ], [ 1, 3, 'whole jacket — whole product, front', 'hood — close-up, front' ] );
ok( 'seules les vraies photos sont « deja sur la page »', $dze_pf, [ 'whole jacket — whole product, front', 'left sleeve — half, side' ] );
DZE_Content::page_frames( 90 );
ok( 'lues une fois : la commande suivante ne paie rien', count( $GLOBALS['mai_vision'] ), 1 );
$GLOBALS['dze_meta'][90]['_dze_pending_review'] = [ 'shots' => [ 'https://v3b.fal.media/files/b/x/ok.jpg', 'https://v3b.fal.media/files/b/x/bad.jpg' ],
	'recipes' => [ 'https://v3b.fal.media/files/b/x/ok.jpg' => 'rx', 'https://v3b.fal.media/files/b/x/bad.jpg' => 'rx' ],
	'frames'  => [ 'https://v3b.fal.media/files/b/x/ok.jpg' => 'chest — close-up, front', 'https://v3b.fal.media/files/b/x/bad.jpg' => 'hood and collar — close-up, front' ],
	'flags'   => [ 'https://v3b.fal.media/files/b/x/ok.jpg' => [], 'https://v3b.fal.media/files/b/x/bad.jpg' => [ 'label VETER' ] ] ];
$dze_ml = DZE_Content::made_lines( 90, 'rx' );
ok( 'la commande entend la page, puis ce qui est deja fait — jamais une image qui invente', [
	false !== strpos( $dze_ml, "ON THE PRODUCT PAGE ALREADY — the product's own photographs, described in words:\n- whole jacket — whole product, front\n- left sleeve — half, side" ),
	false !== strpos( $dze_ml, '- chest — close-up, front' ), false !== strpos( $dze_ml, 'hood and collar' ), false !== strpos( $dze_ml, 'VETER' ),
	false !== strpos( $dze_ml, '- hood — close-up, front' ),
], [ true, true, false, false, true ] );
ok( 'et ce qu elle a entendu reste pour sa fiche', [ DZE_Content::$made_said['page'] ?? [], count( DZE_Content::$made_said['made'] ?? [] ) ], [ [ 'whole jacket — whole product, front', 'left sleeve — half, side' ], 2 ] );
ok( 'la premiere image d un prompt entend deja la page', false !== strpos( DZE_Content::made_lines( 90, 'other' ), 'ON THE PRODUCT PAGE ALREADY' ), true );

// LA FICHE : ce qu elle invente, ce qu on lui a dit d eviter.
$dze_v = DZE_Ai_Card::view( [ 'told' => [ 'page' => [ 'a — whole product, front' ], 'made' => [ 'b — close-up, side' ], 'again' => '' ], 'invented' => [ 'label VETER' ] ], [], '' );
ok( 'la fiche dit ce que l image invente et ce qu on lui a dit d eviter', [ $dze_v['invented'], $dze_v['avoid'], $dze_v['again'] ], [ [ 'label VETER' ], [ 'a — whole product, front', 'b — close-up, side' ], '' ] );
$dze_v = DZE_Ai_Card::view( [ 'prompt' => "x\n\nALREADY MADE FOR THIS PRODUCT — photographs that exist already, described in words (they are not sent):\n- old line one\n- old line two\nDo not make any of them again" ], [], '' );
ok( 'une fiche d avant 4.508.0 relit sa consigne dans ses propres mots', $dze_v['avoid'], [ 'old line one', 'old line two' ] );

// « REMPLACE CETTE PHOTO » : a sa place, et l originale reste la reference.
ok( 'la destination « remplace la photo » existe', [ DZE_Content::attach_target( 'replace:9002' ), DZE_Content::attach_target( 'replace:x' ), DZE_Content::attach_target( 'replace:0' ) ], [ 'replace:9002', 'gallery', 'gallery' ] );
// The new picture (9004, made by ✦) was filed at the end of the gallery, then replaces 9002.
update_post_meta( 9004, DZE_Content::META_RECIPE, 'img_remake' );
$GLOBALS['dze_types'][9004] = 'attachment';
$GLOBALS['dze_meta'][90]['_product_image_gallery'] = '9002,9003,9004';
DZE_Content::replace_in_place( 90, 9002, 9004 );
ok( 'elle prend la place exacte de la photo de galerie', get_post_meta( 90, '_product_image_gallery', true ), '9004,9003' );
$GLOBALS['gallery'][90] = [ 9004, 9003 ];
ok( 'la photo remplacee ne sert plus de reference : elle va etre supprimee', DZE_Content::product_source_ids( 90 ), [ 9001 ] );
// The main image, replaced by its HD (a real photograph stays real: no recipe).
$GLOBALS['dze_meta'][90]['_product_image_gallery'] = '9004,9003,9005';
$GLOBALS['dze_types'][9005] = 'attachment';
DZE_Content::replace_in_place( 90, 9001, 9005 );
ok( 'une image principale remplacee : la nouvelle prend sa place, l ancienne quitte la page', [ $GLOBALS['thumbs'][90] ?? 0, get_post_meta( 90, '_product_image_gallery', true ) ], [ 9005, '9004,9003' ] );
// « les images remplacées doivent être supprimées. Et si elles sont utilisées
// ailleurs […] le remplacement doit aussi être effectif pour la nouvelle image »
$dze_sw = new ReflectionMethod( 'DZE_Content', 'swap_picture' );
$dze_sw->setAccessible( true );
$dze_paths = [ '/wp-content/uploads/2026/10/old-300x300.jpg' => '/wp-content/uploads/2026/10/new-300x300.jpg', '/wp-content/uploads/2026/10/old.jpg' => '/wp-content/uploads/2026/10/new.jpg' ];
$dze_txt = '<img class="wp-image-9002 size-medium" src="https://ru.kula-tactical.com/wp-content/uploads/2026/10/old-300x300.jpg"> {"image":{"url":"https:\/\/kula-tactical.com\/wp-content\/uploads\/2026\/10\/old.jpg","id":9002}}';
ok( 'l ancienne image est remplacee partout : adresses de toutes les tailles, sur tous les domaines, et son id', $dze_sw->invoke( null, $dze_txt, 9002, 9004, $dze_paths ),
	'<img class="wp-image-9004 size-medium" src="https://ru.kula-tactical.com/wp-content/uploads/2026/10/new-300x300.jpg"> {"image":{"url":"https:\/\/kula-tactical.com\/wp-content\/uploads\/2026\/10\/new.jpg","id":9004}}' );
$dze_cs = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content.php' );
ok( 'le remplacement va partout : tout de suite ce qui s indexe, le reste en arriere-plan, puis la suppression', [
	false !== strpos( $dze_cs, "self::replace_everywhere( \$pid, \$old, \$new );" ),
	false !== strpos( $dze_cs, "as_enqueue_async_action( self::SWEEP_HOOK, [ \$old, \$new ], 'dazont-ecom' );" ),
	false !== strpos( $dze_cs, "add_action( self::SWEEP_HOOK, [ self::class, 'replace_sweep' ], 10, 2 );" ),
	// Les copies WPML partagent le fichier : aucune n est supprimee tant qu une seule est encore affichee.
	false !== strpos( $dze_cs, "if ( self::picture_shown( \$o ) ) {\n\t\t\t\treturn \$out;\n\t\t\t}\n\t\t}\n\t\tforeach ( \$family as \$o ) {\n\t\t\tif ( wp_delete_attachment( \$o, true ) ) {" ),
	false !== strpos( $dze_cs, 'META_STANDS' ),
], [ true, true, true, true, false ] );

// LE BRANCHEMENT.
$dze_cs = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content.php' );
$dze_aj = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-content-ajax.php' );
$dze_ma = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-marketing-ai.php' );
$dze_js = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content.js' );
$dze_jb = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/content-bulk.js' );
$dze_ph = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/js/photos.js' );
ok( 'plus aucune trace de l ancienne description libre', [ false !== strpos( $dze_cs, 'function describe_view' ), false !== strpos( $dze_aj, 'describe_view(' ), false !== strpos( $dze_cs, 'claude-haiku-4-5-20251001' ) ], [ false, false, false ] );
ok( 'l appel en images accepte la forme de la reponse', [ false !== strpos( $dze_ma, "int \$timeout = 120, array \$extra = [] ): string {" ), false !== strpos( $dze_ma, "\$dze_headers['anthropic-beta'] = implode( ',', array_map( 'strval', (array) \$extra['_betas'] ) );" ), false !== strpos( $dze_ma, "], \$extra ) ),\n" ) ], [ true, true, true ] );
ok( 'la description produit va jusqu a 3 000 caracteres', [ false !== strpos( $dze_aj, "preg_replace( '/\\s+/', ' ', \$pl ) ), 0, 3000 );" ), false !== strpos( $dze_aj, ", 0, 800 );" ) ], [ true, false ] );
ok( 'un ✦ ou un HD d une photo du produit vise sa place', [
	false !== strpos( $dze_aj, "\$target = ! empty( \$job['replaces'] ) ? 'replace:' . (int) \$job['replaces'] : (string) ( \$job['target'] ?? 'gallery' );" ),
	false !== strpos( $dze_aj, "'replaces' => ( \$att && in_array( \$att, array_map( 'intval', self::product_own_image_ids( \$pid ) ), true ) ) ? \$att : 0," ),
	false !== strpos( $dze_aj, "'replaces' => \$dze_replaces," ),
	false !== strpos( $dze_aj, "self::replace_in_place( \$pid, \$dze_old, (int) \$dze_aid );" ),
], [ true, true, true, true ] );
// 4.509.0 — « C'est maladroit, il faut reprendre l'outil. Je propose un affichage
// plus grand des images, et un bouton sur les miniatures pour remplacer. A partir
// de là, peut être ouvrir une popup pour choisir laquelle remplacer ? »
$dze_css = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/css/content.css' );
ok( 'un bouton ⇄ sur chaque image en attente, sur les deux ecrans', [
	false !== strpos( $dze_js, "\$('<button type=\"button\" class=\"dze-cb-shotswap\">⇄</button>').attr('title', i18n.shotSwap || '')" ),
	false !== strpos( $dze_jb, "\$('<button type=\"button\" class=\"dze-cb-shotswap\">⇄</button>').attr('title', i18n.shotSwap || '')" ),
	false !== strpos( $dze_js, "\$(document).on('click', '#dze-cx-shots .dze-cb-shotswap', function (e) {" ),
	false !== strpos( $dze_jb, "\$(document).on('click', '.dze-cb-shots .dze-cb-shotswap', function (e) {" ),
], [ true, true, true, true ] );
ok( 'il ouvre les photos du produit en grand, et la photo choisie se voit sur l image', [
	false !== strpos( $dze_ph, "function pickReplace(images, current, done) {" ),
	false !== strpos( $dze_ph, "pickReplace: pickReplace," ),
	false !== strpos( $dze_ph, "function replaceChip(images, id) {" ),
	false !== strpos( $dze_css, ".dze-rp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }" ),
], [ true, true, true, true ] );
ok( 'le cycle de destination redevient simple, et les images sont plus grandes', [
	substr_count( $dze_js . $dze_jb, "var order = [ 'gallery', 'gallery_first', 'main' ];" ),
	false !== strpos( $dze_js . $dze_jb, 'replacePick' ),
	false !== strpos( $dze_css, "position: relative; width: var(--dze-pic); height: var(--dze-pic); flex: 0 0 var(--dze-pic);" ),
	false !== strpos( $dze_js, "target: 'replace' === t ? 'gallery' : t" ),
], [ 2, false, true, true ] );
ok( 'une image qui invente a un « ! » rouge, sur les deux ecrans et dans la bande', [
	false !== strpos( $dze_ph, "\$b.addClass('is-flagged').text('!')" ),
	false !== strpos( $dze_js, "res.shotFlags[x.url] = x.invented || [];" ),
	false !== strpos( $dze_jb, "b.shotFlags[x.url] = x.invented || [];" ),
	false !== strpos( $dze_js, "res.shotFlags = waiting.flags || {};" ),
], [ true, true, true, true ] );
// LES PRIX D AUJOURD HUI (reference claude-api, 25/09/2026).
ok( 'les prix de chaque famille sont ceux d aujourd hui', [
	round( DZE_Ai_Usage::estimate( 'claude-sonnet-5-5', 1000000, 0 ), 2 ), round( DZE_Ai_Usage::estimate( 'claude-opus-5-5', 1000000, 0 ), 2 ),
	round( DZE_Ai_Usage::estimate( 'claude-opus-4-8', 1000000, 0 ), 2 ), round( DZE_Ai_Usage::estimate( 'claude-sonnet-4-6', 1000000, 0 ), 2 ),
	round( DZE_Ai_Usage::estimate( 'claude-haiku-4-5', 1000000, 0 ), 2 ), round( DZE_Ai_Usage::estimate( 'claude-fable-5-1', 1000000, 0 ), 2 ),
], [ 2.0, 4.0, 5.0, 3.0, 1.0, 10.0 ] );
@unlink( $dze_jpg );
unset( $GLOBALS['mai_view'] );

if ( null === $dze_keep_img ) { unset( $GLOBALS['opts']['dze_content_settings'] ); } else { $GLOBALS['opts']['dze_content_settings'] = $dze_keep_img; }
printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
