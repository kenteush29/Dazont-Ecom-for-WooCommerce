<?php
/**
 * What this plugin actually SENDS to Klaviyo.
 *
 * Run before every release:  php tools/test-klaviyo.php dazont-ecom
 *
 * The provider's own answers cannot be tested from here — a real call spends
 * money and writes to a live account — but everything up to that line can be,
 * and that is where the bugs have been. "Klaviyo refused (HTTP 404) — No valid
 * revisions found for method" was one header on six calls: three of them asked
 * for the beta revision the localisation endpoints need and three, written
 * later, did not. Nothing about that is visible in a file that parses.
 *
 * So the transport is stubbed and the REQUEST is read: its method, its URL,
 * its headers, its body.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'DZE_URL', 'http://example.test/' );
define( 'DZE_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DZE_KLAVIYO_API_KEY', 'pk_test_not_a_real_key' );

$GLOBALS['dze_sent'] = [];
$GLOBALS['dze_reply'] = [ 'code' => 200, 'body' => '{"data":{"id":"x"}}' ];
$GLOBALS['dze_opts'] = [];

function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return $n > 1 ? $b : $a; }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function esc_js( $s ) { return addslashes( (string) $s ); }
function esc_textarea( $s ) { return esc_html( $s ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function get_option( $k, $d = false ) { return $GLOBALS['dze_opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['dze_opts'][ $k ] = $v; return true; }
$GLOBALS['dze_transients'] = [];
function get_transient( $k ) { return $GLOBALS['dze_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['dze_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['dze_transients'][ $k ] ); return true; }
function add_action() {} function register_setting() {}
// HOSTILE on purpose, like the real one: WordPress's kses strips HTML
// comments and judges every URL protocol. A friendly stub here is how two
// real bugs shipped — the picture marker's dze: protocol, and the Outlook
// conditionals printed as text in an inbox. What clean_html() protects must
// survive THIS; what it does not protect must visibly die here first.
$GLOBALS['dze_protocols'] = [ 'http', 'https' ];
function wp_kses( $html, $allowed = [], $protocols = [] ) {
	$html = (string) preg_replace( '/<!--.*?-->/s', '', (string) $html );
	$html = str_replace( [ '<!--', '-->' ], [ '&lt;!--', '--&gt;' ], $html );
	$ok   = $GLOBALS['dze_protocols'];
	return (string) preg_replace_callback(
		'/\b(src|href)\s*=\s*"([a-z][a-z0-9+.\-]*):/i',
		static function ( array $m ) use ( $ok ): string {
			return in_array( strtolower( $m[2] ), $ok, true ) ? $m[0] : $m[1] . '="';
		},
		$html
	);
}
function add_filter( $tag, $cb = null ) { if ( 'kses_allowed_protocols' === $tag && $cb ) { $GLOBALS['dze_protocols'] = $cb( $GLOBALS['dze_protocols'] ); } }
function remove_filter( $tag, $cb = null ) { if ( 'kses_allowed_protocols' === $tag ) { $GLOBALS['dze_protocols'] = [ 'http', 'https' ]; } }
function wp_kses_post( $html ) { return (string) $html; }
function wp_unslash( $v ) { return $v; }
function apply_filters( $t, $v = null, ...$r ) {
	// WPML's translation lookup, answering the way WPML answers: the
	// translated post when there is one, the original otherwise.
	if ( 'wpml_object_id' === $t ) {
		$lang = (string) ( $r[2] ?? '' );
		$id   = (int) ( $GLOBALS['dze_trans'][ (int) $v ][ $lang ] ?? 0 );
		return $id ?: (int) $v;
	}
	return $v;
}
function get_permalink( $id ) { return $GLOBALS['dze_perma'][ (int) $id ] ?? ''; }
// The shop's product URLs do not resolve back into a post — which is exactly
// the state this shop is in, and why nothing may depend on it.
function url_to_postid( $url ) { $GLOBALS['dze_resolved'][] = $url; return 0; }
function do_action( $t, ...$a ) {}
function current_user_can( $c ) { return true; }
function check_ajax_referer( $a, $b = false, $die = true ) { return true; }
/** wp_send_json_* ends the request in WordPress; here it ends the call. */
class DZE_Json_Sent extends Exception {
	public $payload;
	public function __construct( $payload, $ok ) { parent::__construct( $ok ? 'success' : 'error' ); $this->payload = $payload; }
}
function wp_send_json_success( $d = null ) { throw new DZE_Json_Sent( $d, true ); }
function wp_send_json_error( $d = null, $code = 0 ) { throw new DZE_Json_Sent( $d, false ); }
function is_admin() { return true; }
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . $p; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function add_query_arg( ...$a ) { return is_array( $a[0] ?? null ) ? ( ( $a[1] ?? '' ) . '?' . http_build_query( $a[0] ) ) : ''; }
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function wp_next_scheduled( $h ) { return 0; }
function wp_schedule_event() {} function wp_unschedule_event() {}
function wp_remote_request( $url, $args = [] ) {
	$GLOBALS['dze_sent'][] = [ 'url' => $url ] + $args;
	// A reply per call when the test lists several, otherwise the same one.
	$queue = $GLOBALS['dze_queue'] ?? [];
	$r     = $queue ? array_shift( $GLOBALS['dze_queue'] ) : $GLOBALS['dze_reply'];
	return [ 'response' => [ 'code' => $r['code'] ], 'body' => $r['body'] ];
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
class WP_Error {
	private $msg;
	private $data;
	public function __construct( $c = '', $m = '', $d = null ) { $this->msg = $m; $this->data = $d; }
	public function get_error_message() { return $this->msg; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class DZE_Health { public static function log( ...$a ) {} }

// Enough of a shop to hold one promotion with one filed email.
$GLOBALS['dze_asked'] = [];
class DZE_Marketing_Ai {
	const MENU_SLUG = 'dazont-ecom-ai';
	public static function complete( $system, $user, $model = '', $max = 2000, $timeout = 90 ) {
		$GLOBALS['dze_asked'][] = [ 'model' => $model, 'system' => $system, 'user' => $user, 'timeout' => $timeout ];
		// A queued answer when the test staged one — the plan, a written email
		// — otherwise the translator's echo: every numbered piece, in order.
		if ( ! empty( $GLOBALS['dze_answers'] ) ) {
			return array_shift( $GLOBALS['dze_answers'] );
		}
		preg_match_all( '/^### (\d+)$/m', $user, $m );
		$out = [];
		foreach ( $m[1] as $n ) { $out[ $n ] = 'translated ' . $n; }
		return json_encode( $out );
	}
	public static function get_settings() { return []; }
	public static function instance() { return new self(); }
	public function shop_context_text() { return 'Kula Tactical: tactical and military equipment.'; }
}
class DZE_Discounts {
	const MENU_SLUG_EVENTS = 'dazont-ecom-marketing-events';
	public static function get_rules() { return $GLOBALS['dze_rules'] ?? [ 'promo' => [ 'title' => 'Summer' ] ]; }
}
class DZE_Modules { public static function enabled( $id ) { return true; } }
class DZE_Content {
	// The prompt registry asks the content module for its per-field prompts;
	// this bench has no product fields, and an empty registry is a real state.
	public static function registry() { return []; }
	public static function fal_key() { return 'fal_test_key'; }
	public static function instance() { return new self(); }
	public static function site_language() { return 'English'; }
	public static function last_image_cost() { return 0.05; }
	public function fal_source_data_uri( $id, $size = 'full' ) { return 'data:ref-' . (int) $id; }
	public function fal_generate( $prompt, $refs, $ratio = '' ) {
		$GLOBALS['dze_fal'][] = [ 'prompt' => $prompt, 'refs' => $refs, 'ratio' => $ratio ];
		return 'https://fal.test/made.jpg';
	}
}
class DZE_Ai_Usage {
	public static function over_budget() { return ! empty( $GLOBALS['dze_broke'] ); }
	public static function budget_message() { return 'budget spent'; }
	public static function unit( $u = '' ) {}
	public static function finished( $u = '' ) {}
	public static function last_for( $id ) { return $GLOBALS['dze_last'][ $id ] ?? []; }
}
$GLOBALS['dze_cron'] = [];
function wp_schedule_single_event( $ts, $hook, $args = [] ) { $GLOBALS['dze_cron'][] = [ $ts, $hook, $args ]; return true; }
function wp_clear_scheduled_hook( $hook ) {}
class DZE_Wpml {
	public static function is_active() { return true; }
	public static function default_language() { return 'en'; }
	public static function get_active_languages() {
		return [ [ 'code' => 'en' ], [ 'code' => 'fr' ], [ 'code' => 'de' ] ];
	}
	/** A language, drawn the way WPML draws one on this admin. */
	public static function flag_html( $code, $state = '', $title = '' ) {
		$code = strtolower( trim( (string) $code ) );
		if ( '' === $code ) { return ''; }
		return '<span class="dze-lang' . ( $state ? ' is-' . $state : '' ) . '">'
			. '<img src="https://kula.test/flags/' . $code . '.png" alt="" />'
			. '<span class="dze-lang-code">' . strtoupper( $code ) . '</span></span>';
	}
	public static function flags_html( $done, $todo = [] ) {
		$out = '';
		foreach ( (array) $done as $c ) { $out .= self::flag_html( $c, 'done' ); }
		foreach ( (array) $todo as $c ) { $out .= self::flag_html( $c, 'todo' ); }
		return $out ? '<span class="dze-langs">' . $out . '</span>' : '';
	}
	/**
	 * The original product behind a translation — WPML's own answer, and the
	 * one the sales table does NOT give: it holds the product that was bought.
	 */
	public static function canonical_id( $id, $type = 'product' ) {
		return (int) ( $GLOBALS['dze_origin'][ (int) $id ] ?? (int) $id );
	}
	/**
	 * One post, in one language — WPML's own answer, which is what the emails
	 * ask for now: the translation's SLUG and the place that language lives,
	 * both from WPML rather than half of each built here.
	 */
	public static function post_url_in_language( $post_id, $type, $lang, &$why = null ) {
		$tid = (int) ( $GLOBALS['dze_trans'][ (int) $post_id ][ $lang ] ?? 0 );
		if ( ! $tid || 'en' === $lang ) { $why = 'not-translated'; return ''; }
		$why = 'translation';
		return (string) ( $GLOBALS['dze_perma'][ $tid ] ?? '' );
	}
	/** A shop whose languages live in a directory, which is WPML's usual shape. */
	public static function url_in_language( $url, $lang, &$why = null ) {
		$url = (string) $url;
		if ( 0 !== strpos( $url, 'https://kula.test/' ) || 'en' === $lang ) {
			$why = 'not-ours';
			return $url;
		}
		$why = 'url-rule';
		return 'https://kula.test/' . $lang . '/' . substr( $url, strlen( 'https://kula.test/' ) );
	}
}

// ---------------------------------------------------------------------------
// Enough of a WooCommerce shop to answer "which products may this email show".
// Twenty products, all sold, so the pool is genuinely wider than one email.
// ---------------------------------------------------------------------------
function current_time( $t = 'timestamp', $gmt = 0 ) { return 'timestamp' === $t ? time() : gmdate( 'Y-m-d H:i:s' ); }
function wp_date( $f, $ts = null, $tz = null ) { return gmdate( $f, $ts ?? time() ); }
function home_url( $p = '/' ) { return 'https://kula.test' . $p; }
function get_bloginfo( $k = '' ) { return 'Kula'; }
function wc_price( $n ) { return '$' . number_format( (float) $n, 2 ); }
function wc_placeholder_img_src( $s = '' ) { return 'https://kula.test/placeholder.jpg'; }
function wp_get_attachment_image_url( $id, $size = '' ) { return $id ? 'https://kula.test/img/' . (int) $id . '.jpg' : ''; }
function wp_attachment_is_image( $id ) { return (int) $id > 0; }
function get_the_terms( $id, $tax ) { return [ (object) [ 'name' => 'Gear' ] ]; }
function has_term( $t, $tax, $id ) { return true; }
function wc_get_products( $a = [] ) {
	// The settings screen asks for one published product to show what its link
	// becomes in each language; every other caller here wants the empty shop.
	$ids = (array) ( $GLOBALS['dze_products'] ?? [] );
	if ( ! $ids || 1 !== (int) ( $a['limit'] ?? 0 ) ) { return []; }
	return [ new WC_Product( (int) $ids[0] ) ];
}
function wp_get_global_settings( ...$a ) { return []; }
function wp_get_global_styles( ...$a ) { return []; }
function get_theme_support( ...$a ) { return false; }
function get_theme_mod( $k, $d = false ) { return $d; }
function wp_get_theme() { return new class { public function get( $k ) { return ''; } public $stylesheet = 'x'; }; }
function get_stylesheet() { return 'x'; }
function current_theme_supports( ...$a ) { return false; }
function wp_enqueue_style( ...$a ) {} function wp_enqueue_script( ...$a ) {} function wp_localize_script( ...$a ) {}
// Enough of an admin page for a settings tab to be DRAWN, not merely loaded.
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $g ) . '" />'; }
function submit_button( $t = '', $c = '', $n = '', $w = true ) { echo '<button type="submit">' . esc_html( $t ) . '</button>'; }
function disabled( $a, $b = true, $echo = true ) { $r = ( $a == $b ) ? ' disabled' : ''; if ( $echo ) { echo $r; } return $r; }
function checked( $a, $b = true, $echo = true ) { $r = ( $a == $b ) ? ' checked' : ''; if ( $echo ) { echo $r; } return $r; }
function selected( $a, $b = true, $echo = true ) { $r = ( $a == $b ) ? ' selected' : ''; if ( $echo ) { echo $r; } return $r; }
function wp_nonce_field( ...$a ) { echo '<input type="hidden" name="_wpnonce" value="n" />'; }
function do_settings_sections( $p ) {}
function get_admin_page_title() { return 'Dazont'; }
function wp_get_attachment_image( $id, $size = '', $icon = false, $attr = [] ) { return '<img />'; }
class DZE_Api_Keys { public static function status_html( $w, $k, $l = false ) { return '<span>key</span>'; } }
function wp_style_is( ...$a ) { return true; }
function did_action( $a ) { return 0; }
class DZE_Prompt_Defaults {
	public static function knows( $id ) { return true; }
	public static function has( $id ) { return false; }
	public static function control( $id, $sel = '', $label = '' ) { echo '<button class="dze-pd">default</button>'; }
	public static function pick( $id, $def ) { return $def; }
}

class WC_Product {
	public $id;
	public function __construct( $id ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_name() { return 'Product ' . $this->id; }
	public function get_permalink() { return 'https://kula.test/p/' . $this->id; }
	public function get_image_id() { return 900 + $this->id; }
	public function get_regular_price() { return 20 + $this->id; }
	public function get_price_html() { return '$' . ( 20 + $this->id ); }
	public function get_variation_regular_price( $a = 'min', $b = true ) { return 20 + $this->id; }
	public function is_type( $t ) { return 'simple' === $t; }
	public function is_visible() { return true; }
}
function wc_get_product( $id ) { return (int) $id > 0 ? new WC_Product( (int) $id ) : null; }

class DZE_Test_Wpdb {
	public $prefix = 'wp_';
	public function prepare( $sql, ...$a ) { return $sql; }
	public function get_var( $sql ) { return 'wp_wc_order_product_lookup'; }
	public function get_col( $sql ) { return range( 1, 20 ); }
}
$GLOBALS['wpdb'] = new DZE_Test_Wpdb();

require __DIR__ . '/../' . $dir . '/includes/class-klaviyo.php';
require __DIR__ . '/../' . $dir . '/includes/class-klaviyo-auto.php';
require __DIR__ . '/../' . $dir . '/includes/class-klaviyo-blocks.php';
// The prompt registry too: the settings tab draws its prompts THROUGH it, and
// the block that says what each prompt is sent with is drawn by it. Loading
// the real one is the difference between "the tab renders" and "the tab
// renders what it is supposed to".
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
function last_sent(): array { return end( $GLOBALS['dze_sent'] ) ?: []; }
function header_of( string $name ) { return last_sent()['headers'][ $name ] ?? null; }

// The revisions, read off the wire. Reflection, because the constants are the
// thing under test and hard-coding them here would test nothing.
$r  = new ReflectionClass( 'DZE_Klaviyo' );
$stable = $r->getConstant( 'REV' );
$beta   = $r->getConstant( 'REV_B' );

echo "The API revision each endpoint is asked on\n";
ok( 'beta and stable are not the same', $stable !== $beta, true );
ok( 'the beta revision is a .pre one',  substr( (string) $beta, -4 ), '.pre' );

DZE_Klaviyo::request( 'GET', 'lists/' );
ok( 'an ordinary endpoint: stable',     header_of( 'revision' ), $stable );

DZE_Klaviyo::request( 'GET', 'segments/?fields[segment]=name' );
ok( 'segments too',                     header_of( 'revision' ), $stable );

// Every shape the translations calls are written in, including the ones that
// forgot to ask. This is the bug: a 404 that reads like a missing campaign.
foreach ( [
	[ 'GET',   'translations/campaign-variation::email::abc/?additional-fields%5Btranslation%5D=values' ],
	[ 'PATCH', 'translations/campaign-variation::email::abc/' ],
	[ 'POST',  'translations/' ],
	[ 'GET',   '/translations/abc' ],
] as [ $method, $path ] ) {
	DZE_Klaviyo::request( $method, $path, 'GET' === $method ? null : [ 'data' => [] ] );
	ok( sprintf( '%s %s: beta', $method, mb_substr( $path, 0, 26 ) ), header_of( 'revision' ), $beta );
}

// An explicit beta call still gets the beta revision, whatever the path.
DZE_Klaviyo::request( 'GET', 'campaigns/', null, 25, true );
ok( 'asked for beta, given beta',       header_of( 'revision' ), $beta );

echo "What else travels on every call\n";
ok( 'the key is sent as Klaviyo asks',  header_of( 'Authorization' ), 'Klaviyo-API-Key pk_test_not_a_real_key' );
ok( 'and only to Klaviyo',              0 === strpos( (string) ( last_sent()['url'] ?? '' ), 'https://a.klaviyo.com/api/' ), true );
ok( 'the JSON:API content type',        header_of( 'content-type' ), 'application/vnd.api+json' );

echo "What Klaviyo's refusal comes back as\n";
$GLOBALS['dze_reply'] = [ 'code' => 404, 'body' => '{"errors":[{"detail":"No valid revisions found for method"}]}' ];
$err = DZE_Klaviyo::request( 'PATCH', 'translations/abc/', [ 'data' => [] ] );
ok( 'a refusal is an error, not data',  $err instanceof WP_Error, true );
ok( "and it carries Klaviyo's own words", false !== strpos( $err->get_error_message(), 'No valid revisions found' ), true );
$GLOBALS['dze_reply'] = [ 'code' => 200, 'body' => '{"data":{"id":"x"}}' ];

echo "When Klaviyo moves the endpoint from beta to stable\n";
// The exact refusal the shop saw, answered on the beta revision this time:
// the call must be asked again on the stable one rather than given up on.
$no_rev = '{"errors":[{"detail":"No valid revisions found for method, please check our documentation"}]}';
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 404, 'body' => $no_rev ],
	[ 'code' => 200, 'body' => '{"data":{"id":"campaign-variation::email::abc"}}' ],
];
$got = DZE_Klaviyo::request( 'PATCH', 'translations/abc/', [ 'data' => [] ] );
ok( 'asked twice, not once',            count( $GLOBALS['dze_sent'] ), 2 );
ok( 'the beta revision first',          $GLOBALS['dze_sent'][0]['headers']['revision'] ?? '', $beta );
ok( 'the stable one after',             $GLOBALS['dze_sent'][1]['headers']['revision'] ?? '', $stable );
ok( 'and the answer comes back',        $got['data']['id'] ?? '', 'campaign-variation::email::abc' );
ok( 'the body travelled both times',    ( $GLOBALS['dze_sent'][1]['body'] ?? '' ) === ( $GLOBALS['dze_sent'][0]['body'] ?? '' ), true );

// Any OTHER refusal is about the request, not the revision: sending it again
// would only fail again, and would write to the account twice on a POST.
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 400, 'body' => '{"errors":[{"detail":"Invalid input"}]}' ],
	[ 'code' => 200, 'body' => '{"data":{"id":"never"}}' ],
];
$err = DZE_Klaviyo::request( 'POST', 'translations/', [ 'data' => [] ] );
ok( 'a bad request is sent once',       count( $GLOBALS['dze_sent'] ), 1 );
ok( 'and comes back as the error',      $err instanceof WP_Error, true );

// An ordinary endpoint has one revision and is never asked twice.
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [ [ 'code' => 404, 'body' => $no_rev ], [ 'code' => 200, 'body' => '{}' ] ];
DZE_Klaviyo::request( 'GET', 'lists/' );
ok( 'lists/ is asked once and no more', count( $GLOBALS['dze_sent'] ), 1 );
$GLOBALS['dze_queue'] = [];

echo "Writing one email in several languages\n";
// The collection Klaviyo answers with, and the email the shop has filed.
$values = json_encode( [ 'data' => [ 'attributes' => [ 'values' => [
	[ 'id' => 'x::subject', 'source_value' => 'Summer sale' ],
	[ 'id' => 'y::data.content', 'source_value' => '<p>Everything must go</p>' ],
	[ 'id' => 'z::data.attributes.href', 'source_value' => 'https://kula.test/shop' ],
	[ 'id' => 'i::data.attributes.src', 'source_value' => 'https://cdn.klaviyo.test/hero.jpg' ],
	[ 'id' => '01ABC::from_label', 'source_value' => 'Kula Tactical' ],
] ] ] ] );
$copy = ( new ReflectionClass( 'DZE_Klaviyo' ) )->getConstant( 'OPT_COPY' );
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'mail1' => [
	'kind' => 'launch', 'subject' => 'Summer sale',
	'draft' => [ 'campaign' => 'C1', 'message' => '01ABC', 'langs' => [ 'fr', 'de' ] ],
] ] ] ];
$GLOBALS['dze_opts']['dze_klaviyo'] = [];

$GLOBALS['dze_sent'] = [];
$GLOBALS['dze_asked'] = [];
$GLOBALS['dze_reply'] = [ 'code' => 200, 'body' => $values ];
$n = DZE_Klaviyo::translate_language( 'promo', 'mail1', 'fr' );
ok( 'only the words are translated',    $n, 2 );
ok( 'a link is never sent to be translated', false === strpos( $GLOBALS['dze_asked'][0]['user'] ?? '', 'kula.test' ), true );
ok( 'and nothing is written yet',       count( array_filter( $GLOBALS['dze_sent'], fn( $c ) => 'PATCH' === ( $c['method'] ?? '' ) ) ), 0 );
ok( 'the fast model does the writing',  false !== strpos( (string) ( $GLOBALS['dze_asked'][0]['model'] ?? '' ), 'haiku' ), true );

DZE_Klaviyo::translate_language( 'promo', 'mail1', 'de' );
ok( 'the second language too',          count( $GLOBALS['dze_asked'] ), 2 );

$GLOBALS['dze_sent'] = [];
$got = DZE_Klaviyo::save_translations( 'promo', 'mail1' );
$patches = array_values( array_filter( $GLOBALS['dze_sent'], fn( $c ) => 'PATCH' === ( $c['method'] ?? '' ) ) );
ok( 'one write, not one per language',  count( $patches ), 1 );
$body = json_decode( (string) ( $patches[0]['body'] ?? '' ), true );
$sent = [];
foreach ( (array) ( $body['data']['attributes']['values'] ?? [] ) as $v ) { $sent[ $v['id'] ] = array_keys( $v['translations'] ); }
ok( 'both languages in the same write', $sent['x::subject'] ?? [], [ 'fr', 'de' ] );
ok( 'the body names the campaign plainly', $body['data']['id'] ?? '', 'campaign-variation::email::01ABC' );
ok( 'the answer says what went',        $got['langs'] ?? [], [ 'fr', 'de' ] );

// The bug the shop found in his own account: every href empty in every
// language, so a German reader clicking a product landed on the English page.
// Klaviyo shows those fields in red too — "not translated" written across an
// email that was. The links are filled by RULE, in the same write.
$val = [];
foreach ( (array) ( $body['data']['attributes']['values'] ?? [] ) as $v ) { $val[ $v['id'] ] = $v['translations']; }
ok( 'a product link goes to the reader\'s own language',
	$val['z::data.attributes.href'] ?? [], [ 'fr' => 'https://kula.test/fr/shop', 'de' => 'https://kula.test/de/shop' ] );
ok( 'a photograph is carried over as it stands',
	$val['i::data.attributes.src'] ?? [], [ 'fr' => 'https://cdn.klaviyo.test/hero.jpg', 'de' => 'https://cdn.klaviyo.test/hero.jpg' ] );
ok( 'and the sender keeps his name',
	$val['01ABC::from_label'] ?? [], [ 'fr' => 'Kula Tactical', 'de' => 'Kula Tactical' ] );
ok( 'nothing Klaviyo offered is left empty', count( $sent ), 5 );
ok( 'the links were never sent to a model', count( $GLOBALS['dze_asked'] ), 2 );
// And the figure the row shows stays the number of TEXTS: filled links are
// not translations anybody wrote.
ok( 'the count is the writing, not the links', $got['done'] ?? 0, 2 );
ok( 'the links are counted on their own',      $got['links'] ?? 0, 3 );
ok( 'and the row is told they moved',
	$got['note'] ?? '', 'Links point at the FR, DE pages of the shop.' );
ok( 'which the email keeps for the next visit',
	( get_option( $copy )['promo']['emails']['mail1']['draft']['links_note'] ?? '' ),
	'Links point at the FR, DE pages of the shop.' );

// The shop's own case: WPML hands the same address back — the products are
// not translated, or the URL format is not what we think. The links were
// filled all the same, so Klaviyo looked right and the German reader still
// landed on kula-tactical.com. Silence is what made that last; the row says
// it now, in the same place, until it is fixed.
$stuck = ( new ReflectionMethod( 'DZE_Klaviyo', 'link_note' ) );
$stuck->setAccessible( true );
$note = $stuck->invoke( null, [
	[ 'id' => 'z::data.attributes.href', 'source_value' => 'https://kula.test/shop' ],
], [ 'z::data.attributes.href' => [ 'fr' => 'https://kula.test/shop', 'de' => 'https://kula.test/de/shop' ] ] );
ok( 'a link that did not move is SAID',  false !== strpos( $note, 'did NOT move for FR' ), true );
ok( 'with what to go and look at',       false !== strpos( $note, 'WPML' ), true );
// And an address that was never ours is not counted either way.
ok( 'a foreign address is nobody\'s failure',
	$stuck->invoke( null, [ [ 'id' => 'i::data.attributes.href', 'source_value' => 'https://cdn.klaviyo.test/x.jpg' ] ],
		[ 'i::data.attributes.href' => [ 'fr' => 'https://cdn.klaviyo.test/x.jpg' ] ] ), '' );

// What the email now SAYS about itself, read from what was stored.
$mail = ( get_option( $copy )['promo']['emails']['mail1'] ?? [] );
ok( 'the email records its languages',  $mail['draft']['done_langs'] ?? [], [ 'fr', 'de' ] );
ok( 'and how many texts',               $mail['draft']['texts'] ?? 0, 2 );
ok( "Klaviyo's own list is left alone", $mail['draft']['langs'] ?? [], [ 'fr', 'de' ] );

// A language that never came back must not stop the ones that did.
$GLOBALS['dze_opts'][ $copy ]['promo']['emails']['mail1']['draft']['done_langs'] = [];
DZE_Klaviyo::translate_language( 'promo', 'mail1', 'fr' );
$GLOBALS['dze_sent'] = [];
$got = DZE_Klaviyo::save_translations( 'promo', 'mail1' );
ok( 'one language alone still files',   $got['langs'] ?? [], [ 'fr' ] );

// And nothing at all is an error rather than an empty write.
$threw = '';
try { DZE_Klaviyo::save_translations( 'promo', 'mail1' ); } catch ( Throwable $e ) { $threw = $e->getMessage(); }
ok( 'nothing to file is said, not sent', false !== strpos( $threw, 'Nothing came back' ), true );

// A language that did not come back is a fact the row keeps: which one, what
// refused, and what to press. "Translated — 43 texts in FR, PL, ES · DE — The
// translation did not finish. (504) > Impossible de dire d'où ça vient."
$GLOBALS['dze_opts'][ $copy ]['promo']['emails']['mail1']['draft']['done_langs'] = [];
DZE_Klaviyo::translate_language( 'promo', 'mail1', 'fr' );
$got = DZE_Klaviyo::save_translations( 'promo', 'mail1', [ 'de' ], 'Writing DE did not finish (504)' );
$mail = ( get_option( $copy )['promo']['emails']['mail1'] ?? [] );
ok( 'what did not come back is filed',  $mail['draft']['i18n_fail'] ?? [], [ 'de' ] );
ok( 'with the reason it gave',          $mail['draft']['i18n_why'] ?? '', 'Writing DE did not finish (504)' );
ok( 'the answer carries the whole row',
	str_contains( (string) ( $got['state'] ?? '' ), 'dze-mail-langs' ), true );
ok( 'and the row names the language that is missing',
	str_contains( (string) ( $got['state'] ?? '' ), 'Not written in DE' ), true );
ok( 'with the reason behind the i',
	str_contains( (string) ( $got['state'] ?? '' ), 'title="Writing DE did not finish (504)"' ), true );

echo "A campaign Klaviyo has locked takes no writing\n";
// "Il est impossible d'éditer un email après l'avoir schedulé. Rendre donc
// indisponible les boutons de translate ou update." Klaviyo locks a scheduled
// campaign; a button that answers "no" when pressed should not be there.
$sched = [ 'kind' => 'launch', 'when' => '2026-09-20', 'subject' => 'S',
	'draft' => [ 'campaign' => 'C9', 'message' => 'M9', 'template' => 'T9', 'scheduled' => time(), 'goes' => '2026-09-20' ] ];
$cell = DZE_Klaviyo::state_cell( 'm9', $sched );
ok( 'no Update in Klaviyo on a scheduled email', str_contains( $cell, 'dze-mail-push' ), false );
ok( 'no Translate either',              str_contains( $cell, 'dze-mail-i18n' ), false );
ok( 'but it can still be unscheduled',  str_contains( $cell, 'data-undo="1"' ), true );
ok( 'and the row says why',             str_contains( $cell, 'unschedule it to change anything' ), true );
// One that has GONE OUT is history: not even a day to undo.
$sent_cell = DZE_Klaviyo::state_cell( 'm9', [ 'kind' => 'launch',
	'draft' => [ 'campaign' => 'C9', 'sent' => time() ] ] );
ok( 'a sent email offers nothing that writes', str_contains( $sent_cell, 'dze-mail-push' ), false );
ok( 'nor a day to schedule',            str_contains( $sent_cell, 'data-undo=' ), false );
ok( 'and says so plainly',              str_contains( $sent_cell, 'nothing here can change it any more' ), true );

// The screen is not the lock: the endpoints answer the same.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'lk' => $sched ] ] ];
ok( 'a scheduled email is locked',
	str_contains( DZE_Klaviyo::locked_reason( 'promo', 'lk' ), 'Unschedule' ), true );
$GLOBALS['dze_sent'] = [];
$_POST = [ 'rule' => 'promo', 'email' => 'lk', 'body' => '<p>Words.</p>' ];
$said = null;
try { DZE_Klaviyo::ajax_draft(); } catch ( DZE_Json_Sent $e ) { $said = $e->payload; }
ok( 'Update in Klaviyo is refused',     str_contains( (string) ( $said['message'] ?? '' ), 'scheduled in Klaviyo' ), true );
ok( 'and nothing was written there',    count( $GLOBALS['dze_sent'] ), 0 );
$said = null;
$GLOBALS['dze_asked'] = [];
$_POST = [ 'rule' => 'promo', 'email' => 'lk', 'lang' => 'fr' ];
try { DZE_Klaviyo::ajax_translate(); } catch ( DZE_Json_Sent $e ) { $said = $e->payload; }
ok( 'translating is refused too',       str_contains( (string) ( $said['message'] ?? '' ), 'scheduled in Klaviyo' ), true );
ok( 'and no model call was spent',      count( $GLOBALS['dze_asked'] ?? [] ), 0 );
// A draft is not locked, and the row keeps both buttons.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'dr' => [ 'kind' => 'launch',
	'draft' => [ 'campaign' => 'C9', 'message' => 'M9', 'template' => 'T9' ] ] ] ] ];
ok( 'a draft is not locked',            DZE_Klaviyo::locked_reason( 'promo', 'dr' ), '' );
$_POST = [];

echo "The day an email may go out\n";
$today    = gmdate( 'Y-m-d' );
$tomorrow = gmdate( 'Y-m-d', time() + 86400 );
$next     = gmdate( 'Y-m-d', time() + 5 * 86400 );
$gone     = gmdate( 'Y-m-d', time() - 5 * 86400 );
ok( 'the earliest day is tomorrow',     DZE_Klaviyo::earliest_day(), $tomorrow );
ok( 'today is moved to tomorrow',       DZE_Klaviyo::day_from_tomorrow( $today ), $tomorrow );
ok( 'a day gone by too',                DZE_Klaviyo::day_from_tomorrow( $gone ), $tomorrow );
ok( 'tomorrow is kept',                 DZE_Klaviyo::day_from_tomorrow( $tomorrow ), $tomorrow );
ok( 'and any day after it',             DZE_Klaviyo::day_from_tomorrow( $next ), $next );
ok( 'an hour is still cut off the day', DZE_Klaviyo::day_from_tomorrow( $next . ' 14:30' ), $next );
ok( 'nothing typed stays nothing',      DZE_Klaviyo::day_from_tomorrow( '' ), '' );
// Reading is not writing: the day an email actually went out on is history,
// and moving it would be this plugin rewriting the promotion's own record.
ok( 'reading a past day leaves it',     DZE_Klaviyo::just_day( $gone ), $gone );

// The helper being right is not the same as the SAVE using it. A day gone by,
// posted by the form, must come back as tomorrow — that is the path the shop
// actually travels.
$GLOBALS['dze_opts'][ $copy ] = [];
DZE_Klaviyo::save_copy( 'promo', [ 'title' => 'Summer' ], [
	'dze_email_shown' => 1,
	'dze_email' => [ 'mail9' => [
		'exists' => 1, 'kind' => 'launch', 'when' => $gone,
		'subject' => 'Hello', 'preview' => '', 'body' => '<p>Hi</p>',
	] ],
] );
$saved = get_option( $copy )['promo']['emails']['mail9'] ?? [];
ok( 'a past day cannot be saved',       $saved['when'] ?? '', $tomorrow );

DZE_Klaviyo::save_copy( 'promo', [ 'title' => 'Summer' ], [
	'dze_email_shown' => 1,
	'dze_email' => [ 'mail9' => [
		'exists' => 1, 'kind' => 'launch', 'when' => $next,
		'subject' => 'Hello', 'preview' => '', 'body' => '<p>Hi</p>',
	] ],
] );
ok( 'a real day is saved as it is',     get_option( $copy )['promo']['emails']['mail9']['when'] ?? '', $next );

echo "What the row says about a draft\n";
// The day Klaviyo KEPT is stored, and it is not always the day it was sent.
$row = new ReflectionMethod( 'DZE_Klaviyo', 'just_day' );
ok( 'a kept day is a day',              DZE_Klaviyo::just_day( '2026-09-04' ), '2026-09-04' );
// An email filed before this version carries no answer either way, and the
// screen must not invent one: only an explicit empty means "no date".
$says = static function ( array $draft ): bool {
	return array_key_exists( 'day', $draft ) && '' === (string) $draft['day'];
};
ok( 'an old draft says nothing',        $says( [ 'campaign' => 'C1' ] ), false );
ok( 'a draft Klaviyo dated says nothing', $says( [ 'campaign' => 'C1', 'day' => '2026-09-04' ] ), false );
ok( 'a draft with no date says so',     $says( [ 'campaign' => 'C1', 'day' => '' ] ), true );

echo "The day Klaviyo actually keeps\n";
$kept = new ReflectionMethod( 'DZE_Klaviyo', 'kept_day' );  $kept->setAccessible( true );
$dated = new ReflectionMethod( 'DZE_Klaviyo', 'dated_strategy' ); $dated->setAccessible( true );
$pin  = new ReflectionMethod( 'DZE_Klaviyo', 'pin_day' );   $pin->setAccessible( true );

// Both shapes read by one reader: smart-send carries `date`, static `datetime`.
ok( 'a smart-send day is read',   $kept->invoke( null, [ 'attributes' => [ 'send_strategy' => [ 'method' => 'smart_send_time', 'date' => '2026-09-04' ] ] ] ), '2026-09-04' );
ok( 'a static day is read too',   $kept->invoke( null, [ 'attributes' => [ 'send_strategy' => [ 'method' => 'static', 'datetime' => '2026-09-04T09:00:00+00:00' ] ] ] ), '2026-09-04' );
// This is the answer Klaviyo actually gives: 200, method kept, day gone.
ok( 'a dropped day reads as none', $kept->invoke( null, [ 'attributes' => [ 'send_strategy' => [ 'method' => 'smart_send_time', 'date' => null ] ] ] ), '' );

$want = $dated->invoke( null, [ 'datetime' => $next ], [] );
ok( 'the day that sticks is static', $want['method'] ?? '', 'static' );
ok( 'on the day that was asked for', substr( (string) ( $want['datetime'] ?? '' ), 0, 10 ), $next );
ok( "in each reader's own time zone", $want['options']['is_local'] ?? null, true );

// And the repair: the campaign is patched, and what it says afterwards is what
// is believed — never what we sent it.
$GLOBALS['dze_sent'] = [];
$GLOBALS['dze_reply'] = [ 'code' => 200, 'body' => json_encode( [ 'data' => [ 'id' => 'C1', 'attributes' => [
	'send_strategy' => [ 'method' => 'static', 'datetime' => $next . 'T09:00:00+00:00' ] ] ] ] ) ];
ok( 'the day is pinned and read back', $pin->invoke( null, 'C1', [ 'datetime' => $next ], [] ), $next );
$sent_body = json_decode( (string) ( last_sent()['body'] ?? '' ), true );
ok( 'by patching that campaign',      last_sent()['method'] ?? '', 'PATCH' );
ok( 'with a static strategy',         $sent_body['data']['attributes']['send_strategy']['method'] ?? '', 'static' );

// Klaviyo dropping it a second time is not a day: it must not be claimed.
$GLOBALS['dze_reply'] = [ 'code' => 200, 'body' => json_encode( [ 'data' => [ 'id' => 'C1', 'attributes' => [
	'send_strategy' => [ 'method' => 'static', 'datetime' => null ] ] ] ] ) ];
ok( 'a second refusal is still none',  $pin->invoke( null, 'C1', [ 'datetime' => $next ], [] ), '' );
$GLOBALS['dze_reply'] = [ 'code' => 200, 'body' => '{"data":{"id":"x"}}' ];

echo "Scheduling a draft from the plugin\n";
// Measured on the shop's own account: a send job on a campaign carrying a
// future date SCHEDULES it. These check what we send to get there.
$draft = static fn( string $status, string $when ): string => json_encode( [ 'data' => [ 'id' => 'C1', 'attributes' => [
	'status' => $status, 'send_strategy' => [ 'method' => 'static', 'datetime' => $when . 'T09:00:00+00:00' ],
	'send_time' => $when . 'T09:00:00+00:00' ] ] ] );

$GLOBALS['dze_sent'] = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => $draft( 'Draft', $next ) ],                       // read before
	[ 'code' => 200, 'body' => '{"data":{"id":"C1","attributes":{"status":"queued"}}}' ], // the job
	[ 'code' => 200, 'body' => $draft( 'Queued', $next ) ],                      // read after
];
[ $day, $said ] = DZE_Klaviyo::schedule( 'C1' );
ok( 'it schedules for the campaign day', $day, $next );
ok( 'and says nothing went wrong',       $said, '' );
$job = $GLOBALS['dze_sent'][1] ?? [];
ok( 'by creating a send job',            $job['method'] ?? '', 'POST' );
ok( 'on the send-jobs endpoint',         false !== strpos( (string) ( $job['url'] ?? '' ), 'campaign-send-jobs/' ), true );
ok( 'naming that campaign',              json_decode( (string) ( $job['body'] ?? '' ), true )['data']['id'] ?? '', 'C1' );
// The whole difference from "send now": the day must not be touched.
ok( 'and never rewriting the day',
	count( array_filter( $GLOBALS['dze_sent'], fn( $c ) => 'PATCH' === ( $c['method'] ?? '' ) ) ), 0 );

// A campaign with no day in Klaviyo cannot be scheduled, and is not tried.
$GLOBALS['dze_sent'] = [];
$GLOBALS['dze_queue'] = [ [ 'code' => 200, 'body' => json_encode( [ 'data' => [ 'id' => 'C1', 'attributes' => [
	'status' => 'Draft', 'send_strategy' => [ 'method' => 'smart_send_time', 'date' => null ] ] ] ] ) ] ];
[ $day, $said ] = DZE_Klaviyo::schedule( 'C1' );
ok( 'no day means no scheduling',        $day, '' );
ok( 'and it says why',                   false !== strpos( $said, 'no send day' ), true );
ok( 'without asking for a send job',     count( $GLOBALS['dze_sent'] ), 1 );

// Nor is anything already scheduled or sent put through a sender again.
$GLOBALS['dze_sent'] = [];
$GLOBALS['dze_queue'] = [ [ 'code' => 200, 'body' => $draft( 'Sent', $next ) ] ];
[ , $said ] = DZE_Klaviyo::schedule( 'C1' );
ok( 'a sent campaign is left alone',     count( $GLOBALS['dze_sent'] ), 1 );
ok( 'and the state is named',            false !== strpos( $said, 'Sent' ), true );

// Klaviyo taking the job and leaving a draft is not a scheduled campaign.
$GLOBALS['dze_sent'] = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => $draft( 'Draft', $next ) ],
	[ 'code' => 200, 'body' => '{"data":{"id":"C1","attributes":{"status":"queued"}}}' ],
	[ 'code' => 200, 'body' => $draft( 'Draft', $next ) ],
];
[ $day, $said ] = DZE_Klaviyo::schedule( 'C1' );
ok( 'a draft afterwards is not a claim', $day, '' );
ok( 'and it says so',                    false !== strpos( $said, 'still a draft' ), true );

echo "Putting it back to a draft\n";
$GLOBALS['dze_sent'] = [];
$GLOBALS['dze_queue'] = [ [ 'code' => 200, 'body' => '{"data":{"id":"C1"}}' ] ];
ok( 'unscheduling says nothing is wrong', DZE_Klaviyo::unschedule( 'C1' ), '' );
$rev = last_sent();
ok( 'it patches the send job',           $rev['method'] ?? '', 'PATCH' );
ok( 'asking to revert, never to cancel',
	json_decode( (string) ( $rev['body'] ?? '' ), true )['data']['attributes']['action'] ?? '', 'revert' );
$GLOBALS['dze_queue'] = [];

echo "The products ONE email of a promotion may show\n";
// The bug the shop reported, in figures. A promotion of three emails: the
// first two showed nine products between them, and the third was handed a
// shortlist of nine on which EVERY line read "ALREADY SHOWN by another email
// of this promotion — use only if you must". The instruction it was given
// (lean on other products) could not be obeyed, because there were no others:
// the pool was capped at nine, which is what a single email shows.
$promo = [ 'title' => 'Back to School', 'percent' => 10, 'start' => gmdate( 'Y-m-d' ), 'end' => gmdate( 'Y-m-d', time() + 12 * 86400 ) ];

$first = DZE_Klaviyo::material( $promo, 9 );
ok( 'the first email gets nine products', count( $first['cards'] ), 9 );
ok( 'and none of them is second-hand',   false !== strpos( $first['lines'], 'ALREADY SHOWN' ), false );
ok( 'each line carries its product',     count( $first['ids'] ), 9 );

// What those nine emails showed, as the neighbours' links are read back.
$shown = array_keys( $first['ids'] );
$third = DZE_Klaviyo::material( $promo, 9, $shown );
ok( 'the next email still gets nine',    count( $third['cards'] ), 9 );
ok( 'and every one of them is fresh',    false !== strpos( $third['lines'], 'ALREADY SHOWN' ), false );
ok( 'none of them was shown before',     array_intersect( array_keys( $third['ids'] ), $shown ), [] );

// Two emails' worth used up: the third still finds two more, and only says
// ALREADY SHOWN once it genuinely has to reuse one.
$more  = array_merge( $shown, array_keys( $third['ids'] ) );
$last  = DZE_Klaviyo::material( $promo, 9, $more );
ok( 'a third helping is found',          count( $last['cards'] ), 9 );
ok( 'fresh ones come first',             substr_count( substr( $last['lines'], 0, strpos( $last['lines'], "2." ) ), 'ALREADY SHOWN' ), 0 );

echo "The photographs the opening picture is built from\n";
$pics = new ReflectionMethod( 'DZE_Klaviyo', 'picture_products' );
$pics->setAccessible( true );
$mat  = DZE_Klaviyo::material( $promo, 9 );
$ids  = array_values( $mat['ids'] );

// No email written yet: its own shortlist, in its own order.
ok( 'it works from this email\'s shortlist', array_slice( $pics->invoke( null, $promo, [], $mat ), 0, 3 ), array_slice( $ids, 0, 3 ) );

// Written: the products the email ACTUALLY shows come first, so the picture
// at the top is a picture of what is inside. It used to be the promotion's
// top sellers whatever the email said, which is why every email of a
// promotion opened on the same four packshots.
$links = array_keys( $mat['ids'] );
$body  = '<p><a href="' . $links[7] . '">Seven</a> and <a href="' . $links[5] . '">Five</a></p>';
$order = $pics->invoke( null, $promo, [ 'body' => $body ], $mat );
$want  = [ $mat['ids'][ $links[5] ], $mat['ids'][ $links[7] ] ];
sort( $want );
$got   = array_slice( $order, 0, 2 );
sort( $got );
ok( 'a written email leads on its own products', $got, $want );
ok( 'and the rest of its shortlist follows',     count( $order ), count( $ids ) );

echo "What each moment is told to do with those products\n";
$kp = new ReflectionMethod( 'DZE_Klaviyo', 'kind_products' );
$kp->setAccessible( true );
ok( 'a warm-up teases and does not price',  false !== stripos( $kp->invoke( null, 'warm' ), 'never the discounted prices' ), true );
ok( 'a launch is the shop window',          false !== stripos( $kp->invoke( null, 'launch' ), 'shop window' ), true );
ok( 'a reminder leans on the unused ones',  false !== stripos( $kp->invoke( null, 'reminder' ), 'no other email' ), true );
ok( 'a last call shows one to three',       false !== stripos( $kp->invoke( null, 'last' ), 'one to three products' ), true );

echo "The brief a last-chance email is actually handed\n";
// Read end to end, because this is the block the shop pasted back: an email
// two days before the promotion CLOSED, briefed as the one that announces it.
// The launch email, with the nine products it really showed in its body —
// that is how the next email is told what this reader has already seen.
$launch_body = '<h1>Live</h1>';
foreach ( array_keys( $first['ids'] ) as $l ) {
	$launch_body .= '<a href="' . $l . '">Shop now</a>';
}
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [
	'm1' => [ 'kind' => 'launch', 'when' => gmdate( 'Y-m-d', time() + 86400 ), 'subject' => 'It is live', 'body' => $launch_body ],
	'm2' => [ 'kind' => 'last', 'when' => gmdate( 'Y-m-d', time() + 11 * 86400 ), 'subject' => '', 'body' => '' ],
] ] ];
$brief = DZE_Klaviyo::brief_for( 'promo', $promo, 'm2', DZE_Klaviyo::material_for( 'promo', $promo, 'm2' ), '' );
ok( 'it is told which moment it is',    false !== strpos( $brief, 'Type: Last chance' ), true );
ok( 'and that the promotion is closing', false !== stripos( $brief, 'goes out just before the promotion closes' ), true );
ok( 'never that it opens today',        false !== stripos( $brief, 'on the day it opens' ), false );
ok( 'its products are cut to the moment', false !== stripos( $brief, 'one to three products' ), true );
ok( 'and it is shown the launch email', false !== strpos( $brief, 'It is live' ), true );
// The nine the launch used are out of its way, and what it is offered is nine
// others — not the same nine with a warning label on every one of them.
ok( 'with fresh products to lean on',   false !== strpos( $brief, '[ALREADY SHOWN' ), false );
ok( 'and nine of them to choose from',  substr_count( $brief, '   link: ' ), 9 );


echo "The plan deals the products\n";
// The shop's complaint, before this existed: three emails drew on one shared
// nine-product list, so the third had nothing fresh left and every line it
// was handed read ALREADY SHOWN. The plan now DEALS the pool: each email is
// created with its own products, and the writing is handed exactly those.
$tomorrow2 = gmdate( 'Y-m-d', time() + 86400 );
$in5       = gmdate( 'Y-m-d', time() + 5 * 86400 );
$promo2    = [ 'title' => 'Back to School', 'percent' => 10, 'start' => $tomorrow2, 'end' => gmdate( 'Y-m-d', time() + 12 * 86400 ) ];

$GLOBALS['dze_opts'][ $copy ] = [];
$GLOBALS['dze_asked']   = [];
$GLOBALS['dze_answers'] = [ json_encode( [ 'emails' => [
	[ 'date' => $tomorrow2, 'angle' => 'Tease it',  'products' => [ 3, 1 ] ],
	[ 'date' => $in5,       'angle' => 'Launch it', 'products' => [ 1, 2, 4, 99 ] ],
	[ 'date' => '2020-01-01', 'angle' => 'Too late', 'products' => [ 5 ] ],
] ] ) ];
$planned = DZE_Klaviyo::plan_for( 'promo', $promo2 );
ok( 'the plan was handed the numbered pool',
	false !== strpos( $GLOBALS['dze_asked'][0]['user'] ?? '', 'THE PRODUCTS TO DEAL OUT' ), true );
ok( 'with real products on it',
	false !== strpos( $GLOBALS['dze_asked'][0]['user'] ?? '', '1. Product 1' ), true );
ok( 'a day already gone makes no email', count( $planned ), 2 );
$ids2  = array_keys( $planned );
ok( 'the first email keeps its deal',    $planned[ $ids2[0] ]['products'], [ 3, 1 ] );
ok( 'a product dealt twice stays with the first, an unknown number is dropped',
	$planned[ $ids2[1] ]['products'], [ 2, 4 ] );

// The writing is handed exactly the deal, in its order — nothing marked,
// nothing to step around.
$mat2 = DZE_Klaviyo::material_for( 'promo', $promo2, $ids2[0] );
ok( 'the material is the deal, in order',
	array_values( $mat2['ids'] ), [ 3, 1 ] );
ok( 'and none of it is second-hand',     false !== strpos( $mat2['lines'], 'ALREADY SHOWN' ), false );
$brief2 = DZE_Klaviyo::brief_for( 'promo', $promo2, $ids2[0], $mat2, '' );
ok( 'the brief says whose products they are',
	false !== strpos( $brief2, 'The campaign plan chose these products for THIS email' ), true );
ok( 'and hands over exactly those',      substr_count( $brief2, '   link: ' ), 2 );

// The deal survives the event's own Save, which never posts it.
DZE_Klaviyo::save_copy( 'promo', $promo2, [
	'dze_email_shown' => 1,
	'dze_email' => [ $ids2[0] => [ 'exists' => 1, 'kind' => 'launch', 'when' => $tomorrow2, 'subject' => 'Hey' ] ],
] );
ok( 'a form save keeps the deal',
	get_option( $copy )['promo']['emails'][ $ids2[0] ]['products'] ?? [], [ 3, 1 ] );

echo "The plan keeps the shop's own rhythm\n";
// The screen the owner sent back: a warm-up on the 7th, a launch on the 8th,
// a last chance on the 10th and a reminder on the 12th — every row carrying
// the warning that the one beside it is a day away. The rule was in the
// prompt and nowhere else, and a rule that lives only in a prompt is a rule
// the shop finds broken on its own screen.
$d = static fn( int $n ): string => gmdate( 'Y-m-d', time() + $n * 86400 );
$promo3 = [ 'title' => 'Back to School', 'percent' => 10, 'start' => $d( 6 ), 'end' => $d( 16 ) ];
$GLOBALS['dze_opts'][ $copy ] = [];
$GLOBALS['dze_rules'] = [ 'promo' => $promo3 ];
$GLOBALS['dze_asked']   = [];
$GLOBALS['dze_answers'] = [ json_encode( [ 'emails' => [
	[ 'date' => $d( 6 ), 'angle' => 'Tease',     'products' => [ 1 ] ],
	[ 'date' => $d( 7 ), 'angle' => 'Launch',    'products' => [ 2 ] ],
	[ 'date' => $d( 9 ), 'angle' => 'Last call', 'products' => [ 3 ] ],
	[ 'date' => $d( 11 ), 'angle' => 'Reminder', 'products' => [ 4 ] ],
] ] ) ];
$notes3  = [];
$planned3 = DZE_Klaviyo::plan_for( 'promo', $promo3, $notes3 );
$days3    = array_values( array_map( static fn( $m ) => (string) $m['when'], $planned3 ) );
ok( 'every email the plan asked for is kept', count( $days3 ), 4 );
ok( 'and none of them is closer than three days',
	( static function ( array $days ): bool {
		sort( $days );
		for ( $i = 1; $i < count( $days ); $i++ ) {
			if ( ( strtotime( $days[ $i ] ) - strtotime( $days[ $i - 1 ] ) ) < 3 * 86400 ) { return false; }
		}
		return true;
	} )( $days3 ), true );
ok( 'the first one is left where the plan put it', $days3[0], $d( 6 ) );
ok( 'and the screen is told what moved', ( (int) ( $notes3['moved'] ?? 0 ) ) > 0, true );
// The rule is in the ASK as well: a model told the rhythm plans it right the
// first time, and enforcing it afterwards is the net, not the plan.
ok( 'the model is told the minimum',
	false !== strpos( (string) ( $GLOBALS['dze_asked'][0]['user'] ?? '' ), 'Leave at least 3 days between two emails' ), true );

// And the days another promotion already holds are days this one stays off.
$GLOBALS['dze_opts'][ $copy ] = [ 'patriot' => [ 'emails' => [
	'w' => [ 'kind' => 'warm', 'when' => $d( 7 ), 'subject' => 'Coming' ],
] ] ];
$GLOBALS['dze_rules'] = [ 'promo' => $promo3, 'patriot' => [ 'title' => 'Patriot Day Sale' ] ];
$GLOBALS['dze_asked']   = [];
$GLOBALS['dze_answers'] = [ json_encode( [ 'emails' => [
	[ 'date' => $d( 6 ), 'angle' => 'Tease', 'products' => [ 1 ] ],
] ] ) ];
$notes4   = [];
$planned4 = DZE_Klaviyo::plan_for( 'promo', $promo3, $notes4 );
$days4    = array_values( array_map( static fn( $m ) => (string) $m['when'], $planned4 ) );
ok( 'a day beside another promotion is moved',
	in_array( $days4[0] ?? '', [ $d( 6 ), $d( 7 ) ], true ), false );
ok( 'the model was told which days are taken',
	false !== strpos( (string) ( $GLOBALS['dze_asked'][0]['user'] ?? '' ), $d( 7 ) ), true );
$GLOBALS['dze_rules'] = null;

echo "What the autopilot does when nobody chose\n";
// The default is the safe one: a shop that never touched the setting gets
// its campaigns PREPARED — written, drafted, translated — and nothing is
// ever scheduled. An update must not start sending marketing emails on its
// own; going live is one explicit selection, made by a person.
$GLOBALS['dze_opts']['dze_klaviyo'] = [];
ok( 'never chosen means prepare, not send', DZE_Klaviyo_Auto::mode(), 'prepare' );
$GLOBALS['dze_opts']['dze_klaviyo'] = [ 'auto' => 'schedule' ];
ok( 'chosen, it goes all the way',          DZE_Klaviyo_Auto::mode(), 'schedule' );
$GLOBALS['dze_opts']['dze_klaviyo'] = [ 'auto' => '' ];
ok( 'and off means off',                    DZE_Klaviyo_Auto::mode(), '' );
$GLOBALS['dze_opts']['dze_klaviyo'] = [];

echo "The autopilot decides\n";
// The one function that says what a promotion still needs. Everything the
// pilot does hangs on these answers, so they are pinned one by one.
$today3    = gmdate( 'Y-m-d' );
$tomorrow3 = gmdate( 'Y-m-d', time() + 86400 );
$ctx = [ 'mode' => 'schedule', 'images' => true, 'langs' => [ 'fr', 'de' ], 'audience' => true,
	'frame' => true, 'key' => true, 'budget' => true, 'today' => $today3, 'tomorrow' => $tomorrow3 ];
$live_rule = [ 'type' => 'sale', 'enabled' => 1, 'start' => $today3, 'end' => gmdate( 'Y-m-d', time() + 10 * 86400 ) ];
$mk = static fn( array $over = [] ): array => array_merge( [
	'kind' => 'launch', 'when' => $tomorrow3, 'subject' => 'S', 'preview' => '', 'body' => '<p>B</p>',
	'picture' => 'https://cdn/pic.jpg', 'draft' => [ 'campaign' => 'C1', 'done_langs' => [ 'fr', 'de' ] ], 'products' => [],
], $over );
$next = static fn( array $rule, array $emails, array $auto = [], array $c = [] ): string =>
	DZE_Klaviyo_Auto::next_step( $rule, $emails, $auto, array_merge( $ctx, $c ) )['do'];

ok( 'off when the setting is off',       $next( $live_rule, [], [], [ 'mode' => '' ] ), 'off' );
ok( 'off on a disabled event',           $next( array_merge( $live_rule, [ 'enabled' => 0 ] ), [] ), 'off' );
ok( 'blocked without dates',             $next( [ 'type' => 'sale', 'enabled' => 1 ], [] ), 'blocked' );
ok( 'done once the promotion is over',   $next( array_merge( $live_rule, [ 'start' => '2020-01-01', 'end' => '2020-01-05' ] ), [] ), 'done' );
ok( 'blocked without a key',             $next( $live_rule, [], [], [ 'key' => false ] ), 'blocked' );
ok( 'blocked when the budget is spent',  $next( $live_rule, [], [], [ 'budget' => false ] ), 'blocked' );
ok( 'no emails yet: plan',               $next( $live_rule, [] ), 'plan' );
// The deadlock the owner hit, pinned for good: his event was seen WITH its
// old emails (so the pilot had a record on it), he deleted them all to start
// over, and a "planned once" flag answered every save with silence. Deleting
// every email of an event MEANS "start this campaign over".
ok( 'an emptied event is planned afresh', $next( $live_rule, [], [ 'planned' => 1, 'legacy' => 1, 'note' => 'x' ] ), 'plan' );
ok( 'an empty email is written',         $next( $live_rule, [ 'm1' => $mk( [ 'subject' => '', 'body' => '' ] ) ] ), 'write' );
ok( 'a half-written one is left alone',  $next( $live_rule, [ 'm1' => $mk( [ 'body' => '' ] ) ] ), 'done' );
ok( 'a marker still open wants its picture',
	$next( $live_rule, [ 'm1' => $mk( [ 'picture' => '', 'body' => '<img src="dze:picture" />' ] ) ] ), 'image' );
// The shop's report: three pilot emails, drafted and translated, none with
// an opening picture and nothing saying why. A pilot email whose writing
// left no marker still gets its picture — at the top, like the browser's
// own fallback; only a marker-less email a PERSON wrote is left alone.
ok( 'a pilot email without a marker still gets one',
	$next( $live_rule, [ 'm1' => $mk( [ 'picture' => '', 'auto_made' => 1 ] ) ] ), 'image' );
ok( 'a hand-made one without a marker is left alone',
	$next( $live_rule, [ 'm1' => $mk( [ 'picture' => '', 'draft' => [] ] ) ] ), 'draft' );
ok( 'pictures off: straight to the draft',
	$next( $live_rule, [ 'm1' => $mk( [ 'picture' => '', 'body' => '<img src="dze:picture" />', 'draft' => [] ] ) ], [], [ 'images' => false ] ), 'draft' );
ok( 'written and not in Klaviyo: draft', $next( $live_rule, [ 'm1' => $mk( [ 'draft' => [] ] ) ] ), 'draft' );
ok( 'unless the audience is not chosen', $next( $live_rule, [ 'm1' => $mk( [ 'draft' => [] ] ) ], [], [ 'audience' => false ] ), 'blocked' );
ok( 'drafted with a language missing: translate',
	$next( $live_rule, [ 'm1' => $mk( [ 'draft' => [ 'campaign' => 'C1', 'done_langs' => [ 'fr' ] ] ] ) ] ), 'translate' );
ok( 'prepare mode stops at translated drafts',
	$next( $live_rule, [ 'm1' => $mk() ], [], [ 'mode' => 'prepare' ] ), 'done' );
ok( 'schedule mode schedules a future day', $next( $live_rule, [ 'm1' => $mk() ] ), 'schedule' );
ok( 'never a day already here',
	$next( $live_rule, [ 'm1' => $mk( [ 'when' => $today3 ] ) ] ), 'done' );
ok( 'a sent one is left alone',
	$next( $live_rule, [ 'm1' => $mk( [ 'draft' => [ 'campaign' => 'C1', 'done_langs' => [ 'fr', 'de' ], 'sent' => 123 ] ] ) ] ), 'done' );
// The update guard, in one line: emails the pilot did not prepare are
// completed but never scheduled — an update must not start sending drafts
// that were filed under different rules.
ok( 'a legacy campaign is never scheduled',
	$next( $live_rule, [ 'm1' => $mk() ], [ 'planned' => 1, 'legacy' => 1 ] ), 'done' );
ok( 'its missing languages are still written',
	$next( $live_rule, [ 'm1' => $mk( [ 'draft' => [ 'campaign' => 'C1', 'done_langs' => [ 'fr' ] ] ] ) ], [ 'planned' => 1, 'legacy' => 1 ] ), 'translate' );
ok( 'the second email gets its turn',
	DZE_Klaviyo_Auto::next_step( $live_rule, [ 'm1' => $mk(), 'm2' => $mk( [ 'when' => gmdate( 'Y-m-d', time() + 8 * 86400 ), 'subject' => '', 'body' => '' ] ) ], [ 'planned' => 1 ], array_merge( $ctx, [ 'mode' => 'prepare' ] ) )['email'], 'm2' );

echo "The autopilot acts\n";
// One promotion, walked through its real steps — the same functions the
// buttons call, dispatched by the pilot, with what happened written down.
$GLOBALS['dze_rules'] = [ 'auto1' => [
	'type' => 'sale', 'enabled' => 1, 'title' => 'Autumn', 'percent' => 15,
	'start' => $tomorrow3, 'end' => gmdate( 'Y-m-d', time() + 9 * 86400 ),
] ];
$GLOBALS['dze_opts']['dze_klaviyo'] = [ 'included' => 'SEG1', 'shell' => 'frame', 'auto' => 'schedule' ];
$GLOBALS['dze_opts'][ $copy ]       = [];
$GLOBALS['dze_transients']          = [];

// A promotion that ALREADY holds an email when the pilot first sees it is
// marked as somebody else's work: completed, never scheduled.
$GLOBALS['dze_opts'][ $copy ] = [ 'auto1' => [ 'emails' => [ 'old1' => [
	'kind' => 'launch', 'when' => $tomorrow3, 'subject' => 'Old', 'body' => '<p>Old</p>',
	'draft' => [ 'campaign' => 'C8', 'done_langs' => [ 'fr', 'de' ] ],
] ] ] ];
$did = DZE_Klaviyo_Auto::step( 'auto1' );
ok( 'pre-existing emails are marked at first sight',
	(int) ( DZE_Klaviyo_Auto::auto_of( 'auto1' )['legacy'] ?? 0 ), 1 );
ok( 'and left unscheduled',              $did['do'], 'done' );
$line = DZE_Klaviyo_Auto::status_line( 'auto1', $GLOBALS['dze_rules']['auto1'] );
ok( 'with the reason on the event',      false !== strpos( $line, 'made before the autopilot' ), true );

// The owner deletes them and saves: the pilot replans, and the campaign is
// its own from here on.
$GLOBALS['dze_opts'][ $copy ] = [ 'auto1' => [ 'auto' => DZE_Klaviyo_Auto::auto_of( 'auto1' ), 'emails' => [] ] ];

// Step 1: it plans.
$GLOBALS['dze_answers'] = [ json_encode( [ 'emails' => [
	[ 'date' => $tomorrow3, 'angle' => 'Open it', 'products' => [ 1, 2, 3 ] ],
] ] ) ];
$did = DZE_Klaviyo_Auto::step( 'auto1' );
ok( 'first, the plan',                   $did['do'], 'plan' );
ok( 'a plan of its own ends the legacy mark',
	(int) ( DZE_Klaviyo_Auto::auto_of( 'auto1' )['legacy'] ?? 0 ), 0 );
ok( 'and it went through',               $did['error'], '' );
$made = DZE_Klaviyo::emails_for( 'auto1', $GLOBALS['dze_rules']['auto1'] );
ok( 'the campaign exists',               count( $made ), 1 );
$auto_mail = (string) array_key_first( $made );

// Step 2: it writes, through write_for, and keeps what came back.
$GLOBALS['dze_answers'] = [ json_encode( [
	'subject' => 'Autumn is here', 'preview' => '15% off', 'picture' => '',
	'body'    => '<h1>Autumn</h1><p>Everything 15% off.</p>[[PRODUCT 1]]',
] ) ];
$did = DZE_Klaviyo_Auto::step( 'auto1' );
ok( 'then the writing',                  $did['do'], 'write' );
ok( 'on the planned email',              $did['email'], $auto_mail );
$mail3 = DZE_Klaviyo::emails_for( 'auto1', $GLOBALS['dze_rules']['auto1'] )[ $auto_mail ];
ok( 'and the email is kept at once',     $mail3['subject'], 'Autumn is here' );
ok( 'body included',                     '' !== trim( (string) $mail3['body'] ), true );
// The human quality control: what the pilot wrote asks to be READ. The mark
// travels with the email, survives the event's Save, is counted on the
// status line, and only a person takes it off.
ok( 'and marked for a human to check',   ! empty( $mail3['auto_made'] ), true );
DZE_Klaviyo::save_copy( 'auto1', $GLOBALS['dze_rules']['auto1'], [
	'dze_email_shown' => 1,
	'dze_email' => [ $auto_mail => [ 'exists' => 1, 'kind' => 'launch', 'when' => $tomorrow3, 'subject' => 'Autumn is here' ] ],
] );
ok( 'the mark survives the form save',
	! empty( get_option( $copy )['auto1']['emails'][ $auto_mail ]['auto_made'] ), true );
$line = DZE_Klaviyo_Auto::status_line( 'auto1', $GLOBALS['dze_rules']['auto1'] );
ok( 'the status line counts the unread', false !== strpos( $line, '1 TO CHECK' ), true );
DZE_Klaviyo::put_email( 'auto1', $auto_mail, [ 'auto_made' => 0 ] );
ok( 'a person read it: the mark goes',
	! empty( DZE_Klaviyo::emails_for( 'auto1', $GLOBALS['dze_rules']['auto1'] )[ $auto_mail ]['auto_made'] ), false );

// Step 3 would file the draft in Klaviyo. The account is a stub with no real
// template behind it, so the step FAILS — which is the path worth proving:
// the failure is recorded on the event, retried only a few times, and the
// status line says what stopped rather than nothing.
$GLOBALS['dze_answers'] = [];
$did = DZE_Klaviyo_Auto::step( 'auto1' );
ok( 'next it tries the draft',           $did['do'], 'draft' );
ok( 'the miss is reported, not hidden',  '' !== $did['error'], true );
$autorow = DZE_Klaviyo_Auto::auto_of( 'auto1' );
ok( 'written on the event',              '' !== (string) ( $autorow['note'] ?? '' ), true );
ok( 'and counted',                       (int) ( $autorow['fails'] ?? 0 ), 1 );
DZE_Klaviyo_Auto::step( 'auto1' );
DZE_Klaviyo_Auto::step( 'auto1' );
$line = DZE_Klaviyo_Auto::status_line( 'auto1', $GLOBALS['dze_rules']['auto1'] );
ok( 'after three misses it says it stopped', false !== strpos( $line, 'stopped after several failed tries' ), true );
DZE_Klaviyo_Auto::follow( 'auto1' );
ok( 'a save clears the way',             (int) ( DZE_Klaviyo_Auto::auto_of( 'auto1' )['fails'] ?? 0 ), 0 );

// Scheduling, on an email that is fully ready: the pilot creates the send
// job and keeps what Klaviyo answered — the same path the button takes.
DZE_Klaviyo::put_email( 'auto1', $auto_mail, [ 'draft' => [
	'campaign' => 'C9', 'message' => '01X', 'done_langs' => [ 'fr', 'de' ], 'day' => $in5,
] ] );
$draft9 = static fn( string $status ): string => json_encode( [ 'data' => [ 'id' => 'C9', 'attributes' => [
	'status' => $status, 'send_strategy' => [ 'method' => 'static', 'datetime' => $in5 . 'T09:00:00+00:00' ],
	'send_time' => $in5 . 'T09:00:00+00:00' ] ] ] );
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => $draft9( 'Draft' ) ],
	[ 'code' => 200, 'body' => '{"data":{"id":"C9","attributes":{"status":"queued"}}}' ],
	[ 'code' => 200, 'body' => $draft9( 'Queued' ) ],
];
$did = DZE_Klaviyo_Auto::step( 'auto1' );
ok( 'ready and dated ahead: it schedules', $did['do'], 'schedule' );
ok( 'without a hitch',                     $did['error'], '' );
$mail9 = DZE_Klaviyo::emails_for( 'auto1', $GLOBALS['dze_rules']['auto1'] )[ $auto_mail ];
ok( 'and the event knows the day it goes', $mail9['draft']['goes'] ?? '', $in5 );

$line = DZE_Klaviyo_Auto::status_line( 'auto1', $GLOBALS['dze_rules']['auto1'] );
ok( 'the status line counts it all',
	false !== strpos( $line, 'written 1/1' ) && false !== strpos( $line, 'scheduled 1/1' ), true );
$GLOBALS['dze_rules'] = null;
$GLOBALS['dze_queue'] = [];


echo "Deleting an email takes its campaign out of Klaviyo\n";
// The gap the owner found by asking: the cross removed the row here and left
// the campaign there — clutter when it was a draft, a real send when it was
// scheduled. Now the campaign follows the email, and each case says itself.
$wipe = static function ( string $status ) use ( $copy ): void {
	$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'gone1' => [
		'kind' => 'launch', 'when' => gmdate( 'Y-m-d', time() + 3 * 86400 ), 'subject' => 'Bye', 'body' => '<p>B</p>',
		'draft' => [ 'campaign' => 'C7', 'template' => 'T7', 'status_seed' => $status ],
	] ] ] ];
};

// A plain draft: campaign deleted, template deleted, row gone.
$wipe( 'Draft' );
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => '{"data":{"id":"C7","attributes":{"status":"Draft"}}}' ],
	[ 'code' => 204, 'body' => '' ],
	[ 'code' => 204, 'body' => '' ],
];
$said = DZE_Klaviyo::forget_email( 'promo', 'gone1' );
ok( 'the row is gone here',           isset( get_option( $copy )['promo']['emails']['gone1'] ), false );
$dels = array_values( array_filter( $GLOBALS['dze_sent'], fn( $c ) => 'DELETE' === ( $c['method'] ?? '' ) ) );
ok( 'the draft is deleted over there', false !== strpos( (string) ( $dels[0]['url'] ?? '' ), 'campaigns/C7/' ), true );
ok( 'and its template with it',        false !== strpos( (string) ( $dels[1]['url'] ?? '' ), 'templates/T7/' ), true );
ok( 'and the screen is told',          false !== strpos( $said, 'deleted too' ), true );

// A SCHEDULED one: back to a draft FIRST, then deleted — nothing may go out
// for an email that no longer exists.
$wipe( 'Scheduled' );
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => '{"data":{"id":"C7","attributes":{"status":"Scheduled"}}}' ],
	[ 'code' => 200, 'body' => '{"data":{"id":"C7"}}' ],
	[ 'code' => 204, 'body' => '' ],
	[ 'code' => 204, 'body' => '' ],
];
DZE_Klaviyo::forget_email( 'promo', 'gone1' );
$revert = array_values( array_filter( $GLOBALS['dze_sent'], fn( $c ) => false !== strpos( (string) ( $c['url'] ?? '' ), 'campaign-send-jobs/' ) ) );
ok( 'a scheduled one is unscheduled first',
	json_decode( (string) ( $revert[0]['body'] ?? '' ), true )['data']['attributes']['action'] ?? '', 'revert' );
ok( 'then deleted',
	count( array_filter( $GLOBALS['dze_sent'], fn( $c ) => 'DELETE' === ( $c['method'] ?? '' ) ) ) >= 1, true );

// One that cannot be unscheduled is the dangerous case, and it SHOUTS.
$wipe( 'Scheduled' );
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => '{"data":{"id":"C7","attributes":{"status":"Scheduled"}}}' ],
	[ 'code' => 409, 'body' => '{"errors":[{"detail":"Job is running"}]}' ],
];
$said = DZE_Klaviyo::forget_email( 'promo', 'gone1' );
ok( 'a stuck schedule is said in capitals', false !== strpos( $said, 'WILL go out' ), true );

// A sent one is history, and history is left alone.
$wipe( 'Sent' );
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [ [ 'code' => 200, 'body' => '{"data":{"id":"C7","attributes":{"status":"Sent"}}}' ] ];
$said = DZE_Klaviyo::forget_email( 'promo', 'gone1' );
ok( 'a sent campaign is left in Klaviyo',
	count( array_filter( $GLOBALS['dze_sent'], fn( $c ) => 'DELETE' === ( $c['method'] ?? '' ) ) ), 0 );
ok( 'and named as history',            false !== strpos( $said, 'history' ), true );

// Klaviyo refusing the hard delete (a past send job) still ends safe.
$wipe( 'Draft' );
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => '{"data":{"id":"C7","attributes":{"status":"Draft"}}}' ],
	[ 'code' => 409, 'body' => '{"errors":[{"detail":"Cannot delete"}]}' ],
];
$said = DZE_Klaviyo::forget_email( 'promo', 'gone1' );
ok( 'a refused delete stays a harmless draft', false !== strpos( $said, 'harmless draft' ), true );

// An email that never reached Klaviyo says nothing at all.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'gone2' => [
	'kind' => 'launch', 'when' => gmdate( 'Y-m-d', time() + 3 * 86400 ), 'subject' => '', 'body' => '',
] ] ] ];
$GLOBALS['dze_sent'] = [];
ok( 'no campaign, no words',           DZE_Klaviyo::forget_email( 'promo', 'gone2' ), '' );
ok( 'and no call to Klaviyo',          count( $GLOBALS['dze_sent'] ), 0 );
$GLOBALS['dze_queue'] = [];


echo "The pilot makes the picture, and the email carries it\n";
// One pilot email, written without a marker, not yet in Klaviyo: the image
// step runs the real chain — the email's own products as references, fal,
// hosting by Klaviyo — and the photograph opens the body.
$GLOBALS['dze_rules'] = [ 'auto2' => [
	'type' => 'sale', 'enabled' => 1, 'title' => 'Winter', 'percent' => 12,
	'start' => $tomorrow3, 'end' => gmdate( 'Y-m-d', time() + 8 * 86400 ),
] ];
$GLOBALS['dze_opts'][ $copy ]['auto2'] = [ 'auto' => [ 'legacy' => 0 ], 'emails' => [ 'p1' => [
	'kind' => 'launch', 'when' => $tomorrow3, 'subject' => 'Winter is here', 'preview' => '',
	'body' => '<h1>Winter</h1><p>12% off.</p>', 'picture' => '', 'auto_made' => 1,
	'products' => [ 4, 7 ], 'draft' => [],
] ] ];
$GLOBALS['dze_fal']   = [];
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [ [ 'code' => 200, 'body' => json_encode( [ 'data' => [ 'attributes' => [
	'image_url' => 'https://klaviyo.img/hosted.jpg' ] ] ] ) ] ];
$did = DZE_Klaviyo_Auto::step( 'auto2' );
ok( 'the missing picture is the next step', $did['do'], 'image' );
ok( 'and it is made without a hitch',       $did['error'], '' );
$shot = $GLOBALS['dze_fal'][0] ?? [];
ok( 'from the products of THIS email',      $shot['refs'] ?? [], [ 'data:ref-904', 'data:ref-907' ] );
ok( 'as a wide banner',                     $shot['ratio'] ?? '', '3:2' );
ok( 'the brief asks for the moment, not a line-up',
	false !== stripos( (string) ( $shot['prompt'] ?? '' ), 'photograph one or two of them somewhere real' ), true );
$p1 = DZE_Klaviyo::emails_for( 'auto2', $GLOBALS['dze_rules']['auto2'] )['p1'];
ok( 'the email keeps the hosted address',   $p1['picture'], 'https://klaviyo.img/hosted.jpg' );
ok( 'and OPENS on it, marker or none',
	0 === strpos( (string) $p1['body'], '<p style="margin:0 0 14px;"><img src="https://klaviyo.img/hosted.jpg"' ), true );

// The same, when the draft was already filed: the picture arriving late owes
// Klaviyo a refile and the translations a second pass — both are set in
// motion, and what is stored says so.
$GLOBALS['dze_opts'][ $copy ]['auto2']['emails']['p1'] = [
	'kind' => 'launch', 'when' => $tomorrow3, 'subject' => 'Winter is here', 'preview' => '',
	'body' => '<h1>Winter</h1><p>12% off.</p>', 'picture' => '', 'auto_made' => 1,
	'products' => [ 4 ], 'draft' => [ 'campaign' => 'C5', 'message' => '01M', 'done_langs' => [ 'fr', 'de' ] ],
];
$GLOBALS['dze_queue'] = [ [ 'code' => 200, 'body' => json_encode( [ 'data' => [ 'attributes' => [
	'image_url' => 'https://klaviyo.img/hosted2.jpg' ] ] ] ) ] ];
$did = DZE_Klaviyo_Auto::step( 'auto2' );
$p1  = DZE_Klaviyo::emails_for( 'auto2', $GLOBALS['dze_rules']['auto2'] )['p1'];
ok( 'a late picture is still kept',         $p1['picture'], 'https://klaviyo.img/hosted2.jpg' );
ok( 'and the translations are owed again',  $p1['draft']['done_langs'] ?? null, [] );
$GLOBALS['dze_rules'] = null;
$GLOBALS['dze_queue'] = [];


echo "A preview an inbox can show\n";
// The owner's screenshot, twice. First: "Best-sellers across headwear,
// balaclavas and pouches, now 10% off the whole shop." — thirteen words
// against a prompt that asks for six, and eighty characters of which an inbox
// shows about forty. It passed a ninety-character cap, which is how it reached
// an inbox at all. Sixty, cut on a word.
ok( 'a short preview is left alone',
	DZE_Klaviyo::tight_preview( 'Everything 10% off until Sunday' ), 'Everything 10% off until Sunday' );
$seen = 'Best-sellers across headwear, balaclavas and pouches, now 10% off the whole shop.';
ok( 'the one he was sent is cut down',  mb_strlen( DZE_Klaviyo::tight_preview( $seen ) ) <= 60, true );
$long = 'Best-sellers across headwear, balaclavas and pouches, now 10% off the whole shop until Sunday night only';
$cut  = DZE_Klaviyo::tight_preview( $long );
ok( 'a rambling one is cut',            mb_strlen( $cut ) <= 60, true );
ok( 'on a word, never inside one',      preg_match( '/\w$/u', $cut ) === 1 && str_starts_with( $long, $cut ), true );
$GLOBALS['dze_answers'] = [ json_encode( [ 'subject' => 'S', 'preview' => $long, 'body' => '<p>Short.</p>' ] ) ];
$made = DZE_Klaviyo::write_for( 'promo', $promo2, $ids2[0] );
ok( 'and the writing path really uses it', mb_strlen( (string) $made['preview'] ) <= 60, true );
// The rule is in what the model is told, not only in what is done to its
// answer: a cap alone gives a truncated sentence rather than a short one.
ok( 'and the model is asked for six words',
	false !== strpos( (string) ( $GLOBALS['dze_asked'][ count( $GLOBALS['dze_asked'] ) - 1 ]['user'] ?? '' ), 'SIX words at the very most' ), true );


echo "From the model's answer to Klaviyo blocks, end to end\n";
// The whole real path in one breath — the model's JSON, the hostile kses
// wash, the product cards dropped in, storage, and the block splitter — so
// no friendly stub can ever again hide what an inbox will actually show.
$GLOBALS['dze_answers'] = [ json_encode( [
	'subject' => 'End to end', 'preview' => 'Short.', 'picture' => '',
	'body'    => '<table role="presentation"><tr><td><h1>The words</h1><p>Must arrive.</p>[[PRODUCT 1]][[PRODUCT 2]]<p>Every one of them.</p></td></tr></table>',
] ) ];
$made  = DZE_Klaviyo::write_for( 'promo', $promo2, $ids2[1] );
$rows9 = DZE_Klaviyo_Blocks::rows( (string) $made['body'], [ 'head' => 'A', 'body' => 'B', 'ink' => '#111', 'link' => '#0a0', 'size' => 16, 'btn_bg' => '#0a0', 'btn_ink' => '#fff', 'card' => '#fff', 'border' => '#eee', 'radius' => 4, 'sale' => '#900', 'strike' => '#999' ] );
$flat9 = wp_strip_all_tags( (string) json_encode( $rows9 ) );
ok( 'the heading reaches the blocks',     false !== strpos( $flat9, 'The words' ), true );
ok( 'the paragraphs too',                 false !== strpos( $flat9, 'Must arrive.' ) && false !== strpos( $flat9, 'Every one of them.' ), true );
ok( 'the products sit side by side',      false !== strpos( (string) json_encode( $rows9 ), '2-columns' ), true );
ok( 'and no Outlook plumbing prints as text', false === strpos( $flat9, '[if mso]' ), true );

echo "What must survive the kses wash\n";
// The stub kses above is hostile like WordPress's own — comments stripped,
// unknown protocols cut. What clean_html() promises to protect has to come
// through it whole, wash after wash, because a body is washed on every save.
$plumbed = '<p>Words.</p><!--[if mso]><table><tr><![endif]--><div>x</div><!--[if mso]></tr></table><![endif]-->';
$once  = DZE_Klaviyo::clean_html( $plumbed );
ok( 'Outlook plumbing survives the wash', substr_count( $once, '<!--[if mso]>' ), 2 );
ok( 'closings too',                       substr_count( $once, '<![endif]-->' ), 2 );
ok( 'twice over, unchanged',              DZE_Klaviyo::clean_html( $once ), $once );
ok( 'the picture marker survives it too',
	false !== strpos( DZE_Klaviyo::clean_html( '<img src="dze:picture" />' ), 'dze:picture' ), true );
// And a body mangled by an EARLIER version — the literal "&lt;!--[if
// mso]&gt;" the shop saw printed in an inbox — is mended on its next wash.
$mangled2 = '<p>Words.</p>&lt;!--[if mso]&gt;<div>x</div>&lt;![endif]--&gt;';
$mended   = DZE_Klaviyo::clean_html( $mangled2 );
ok( 'an earlier mangling is mended',      substr_count( $mended, '<!--[if mso]>' ), 1 );
ok( 'and prints nowhere as text',         false === strpos( wp_strip_all_tags( $mended ), '[if mso]' ), true );


echo "The template a campaign actually SENDS is the one rewritten\n";
// The week-long bug, finally caught on the owner's own account: opening a
// campaign for translation makes Klaviyo CLONE the assigned template
// ("Clone of …") and re-point the message at the clone. The plugin kept
// rewriting the id it remembered — a template nobody sends — while every
// send came from the clone, frozen at the broken state of the day it was
// cloned. The rewrite must ASK the message which template it reads.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'rw1' => [
	'kind' => 'launch', 'when' => gmdate( 'Y-m-d', time() + 4 * 86400 ),
	'subject' => 'Rewrite me', 'preview' => 'P', 'body' => '<h1>Words</h1><p>That must send.</p>',
	'picture' => '', 'products' => [],
	'draft' => [ 'campaign' => 'C1', 'message' => '01MSG', 'template' => 'T-OURS' ],
] ] ] ];
$GLOBALS['dze_opts']['dze_klaviyo'] = [ 'included' => 'SEG1', 'shell' => 'frame', 'frame_id' => 'FRAME' ];
// The owner's frame, as Klaviyo would hand it back: one empty section in the
// middle for the email to go into.
$frame_def = json_encode( [ 'data' => [ 'id' => 'FRAME', 'attributes' => [
	'editor_type' => 'SYSTEM_DRAGGABLE',
	'definition'  => [ 'body' => [ 'properties' => [ 'id' => 'root' ], 'styles' => [ 'width' => 600 ], 'sections' => [
		[ 'content_type' => 'section', 'type' => 'section', 'data' => [ 'properties' => [], 'display_options' => [], 'styles' => [] ],
			'rows' => [ [ 'data' => [ 'styles' => [ 'column_layout' => '1-column-full-width' ] ],
				'columns' => [ [ 'data' => [], 'blocks' => [] ] ] ] ] ],
	] ], 'styles' => [] ],
] ] ] );
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	// Is it still a draft (cheap) BEFORE the shop's own header template is
	// read: an email nobody has touched must cost one question, not a parse.
	[ 'code' => 200, 'body' => '{"data":{"id":"C1","attributes":{"status":"Draft"}}}' ],           // draft_open
	[ 'code' => 200, 'body' => $frame_def ],                                                       // the frame, read fresh
	[ 'code' => 200, 'body' => '{"data":{"type":"template","id":"T-CLONE"}}' ],                    // what the message READS
	[ 'code' => 200, 'body' => '{"data":{"id":"T-CLONE","attributes":{"editor_type":"SYSTEM_DRAGGABLE"}}}' ], // editor_of
	[ 'code' => 200, 'body' => '{"data":{"id":"T-CLONE"}}' ],                                      // PATCH the clone
	[ 'code' => 200, 'body' => json_encode( [ 'data' => [ 'id' => 'C1', 'attributes' => [
		'send_strategy' => [ 'method' => 'static', 'datetime' => gmdate( 'Y-m-d', time() + 4 * 86400 ) . 'T09:00:00+00:00' ] ] ] ] ) ], // campaign PATCH
	[ 'code' => 200, 'body' => '{"data":{"id":"01MSG"}}' ],                                        // message PATCH
	[ 'code' => 200, 'body' => '{"data":[]}' ],                                                    // tag lookup…
	[ 'code' => 200, 'body' => '{"data":{"id":"tag1"}}' ],
	[ 'code' => 200, 'body' => '{}' ],
	[ 'code' => 200, 'body' => '{"data":{"id":"C1","attributes":{"status":"Draft"}}}' ],
];
try {
	DZE_Klaviyo::draft( 'promo', 'rw1' );
} catch ( Throwable $e ) {
	// The tail of draft() (frame, translations…) may want more of the account
	// than this bench stages; what matters was sent before it gave up.
	if ( getenv( 'DZE_DEBUG' ) ) { echo 'draft threw: ', $e->getMessage(), "\n"; }
}
if ( getenv( 'DZE_DEBUG' ) ) { foreach ( $GLOBALS['dze_sent'] as $c ) { echo ( $c['method'] ?? 'GET' ), ' ', $c['url'], "\n"; } }
$asked_tpl = array_values( array_filter( $GLOBALS['dze_sent'], static fn( $c ) =>
	false !== strpos( (string) ( $c['url'] ?? '' ), 'campaign-messages/01MSG/relationships/template' ) ) );
ok( 'the message is asked what it reads', count( $asked_tpl ), 1 );
$patched = array_values( array_filter( $GLOBALS['dze_sent'], static fn( $c ) =>
	'PATCH' === ( $c['method'] ?? '' ) && false !== strpos( (string) ( $c['url'] ?? '' ), 'templates/' ) ) );
ok( 'and THAT template is the one rewritten',
	false !== strpos( (string) ( $patched[0]['url'] ?? '' ), 'templates/T-CLONE/' ), true );
ok( 'never the remembered one',
	0 === count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) =>
		false !== strpos( (string) ( $c['url'] ?? '' ), 'templates/T-OURS' ) ) ), true );
$GLOBALS['dze_queue'] = [];

echo "An email nobody has touched is not filed again\n";
// "Put them all in Klaviyo > Semble répéter la syncro des emails déjà
// présents sur klaviyo !!!!!!!!!" It did: every press rewrote every
// template, whether or not a comma had changed. What is about to be filed is
// fingerprinted and compared with what WAS filed.
$again_mail = [
	'kind' => 'launch', 'when' => gmdate( 'Y-m-d', time() + 4 * 86400 ),
	'subject' => 'Same as ever', 'preview' => 'P', 'picture' => '',
	'body' => '<p>Nothing has changed here.</p>',
	'draft' => [ 'campaign' => 'C9', 'message' => 'M9', 'template' => 'T9' ],
];
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'sk1' => $again_mail ] ] ];
// Everything one press of "put it in Klaviyo" asks the account, in order.
$press = static function () use ( $frame_def ): array {
	return [
		// The order a press really asks in: is it still a draft (cheap), and
		// only then the shop's own header template.
		[ 'code' => 200, 'body' => '{"data":{"id":"C9","attributes":{"status":"Draft"}}}' ],
		[ 'code' => 200, 'body' => $frame_def ],
		[ 'code' => 200, 'body' => '{"data":{"type":"template","id":"T9"}}' ],
		[ 'code' => 200, 'body' => '{"data":{"id":"T9","attributes":{"editor_type":"SYSTEM_DRAGGABLE"}}}' ],
		[ 'code' => 200, 'body' => '{"data":{"id":"T9"}}' ],
		[ 'code' => 200, 'body' => json_encode( [ 'data' => [ 'id' => 'C9', 'attributes' => [
			'send_strategy' => [ 'method' => 'static', 'datetime' => gmdate( 'Y-m-d', time() + 4 * 86400 ) . 'T09:00:00+00:00' ] ] ] ] ) ],
		[ 'code' => 200, 'body' => '{"data":{"id":"M9"}}' ],
		[ 'code' => 200, 'body' => '{"data":[]}' ],
		[ 'code' => 200, 'body' => '{"data":{"id":"tag1"}}' ],
		[ 'code' => 200, 'body' => '{}' ],
		[ 'code' => 200, 'body' => '{"data":{"id":"C9","attributes":{"status":"Draft"}}}' ],
	];
};
// First press: it goes, and the fingerprint is written down.
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = $press();
try { DZE_Klaviyo::draft( 'promo', 'sk1' ); } catch ( Throwable $e ) { if ( getenv( 'DZE_DEBUG' ) ) { echo $e->getMessage(), "\n"; } }
$stamp = get_option( $copy )['promo']['emails']['sk1']['draft']['stamp'] ?? '';
ok( 'the first press files it',         '' !== $stamp, true );

// Second press, nothing changed: one cheap question to Klaviyo (is it still a
// draft?) and no template rewritten.
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [ [ 'code' => 200, 'body' => '{"data":{"id":"C9","attributes":{"status":"Draft"}}}' ] ];
$made = DZE_Klaviyo::draft( 'promo', 'sk1' );
ok( 'the second press files nothing',   ! empty( $made['skipped'] ), true );
ok( 'and rewrites no template',
	count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) => 'PATCH' === ( $c['method'] ?? '' ) ) ), 0 );
// One cheap question — "is it still a draft?" — and nothing else: the shop's
// own header template is not even read, let alone parsed.
ok( 'it asks the account once and stops', count( $GLOBALS['dze_sent'] ), 1 );
ok( 'the row still points at the draft', $made['url'] ?? '', 'https://www.klaviyo.com/campaign/C9/wizard' );
ok( 'and it carries its state cell',    str_contains( (string) ( $made['state'] ?? '' ), 'dze-mail-does' ), true );

// Asked for BY NAME, it goes whatever the fingerprint says: that is what
// pressing a button on one row means.
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = $press();
try { DZE_Klaviyo::draft( 'promo', 'sk1', [ 'force' => true ] ); } catch ( Throwable $e ) { if ( getenv( 'DZE_DEBUG' ) ) { echo $e->getMessage(), "\n"; } }
ok( 'forcing rewrites it all the same',
	count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) =>
		'PATCH' === ( $c['method'] ?? '' ) && false !== strpos( (string) $c['url'], 'templates/T9' ) ) ), 1 );

// A CHANGED email is filed again without being asked twice.
$GLOBALS['dze_opts'][ $copy ]['promo']['emails']['sk1']['body'] = '<p>Something else entirely.</p>';
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = $press();
try { DZE_Klaviyo::draft( 'promo', 'sk1' ); } catch ( Throwable $e ) { if ( getenv( 'DZE_DEBUG' ) ) { echo $e->getMessage(), "\n"; } }
ok( 'a changed email goes on its own',
	count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) =>
		'PATCH' === ( $c['method'] ?? '' ) && false !== strpos( (string) $c['url'], 'templates/T9' ) ) ), 1 );
$GLOBALS['dze_queue'] = [];

echo "The row says whether it is in Klaviyo, whatever state it is in\n";
// "J'aimerai en plus dans l'UI une mention Synced with klaviyo peu importe le
// status de l'email, draft ou pas." It said "Draft in Klaviyo" and nothing
// else, so an email that had been scheduled — or sent — read as though it had
// never arrived.
$cell = DZE_Klaviyo::state_cell( 's1', [ 'kind' => 'launch', 'draft' => [ 'campaign' => 'C1' ] ] );
ok( 'a draft says it is synced',        str_contains( $cell, 'Synced with Klaviyo · draft' ), true );
$cell = DZE_Klaviyo::state_cell( 's1', [ 'kind' => 'launch', 'draft' => [ 'campaign' => 'C1', 'scheduled' => time(), 'goes' => '2026-09-20' ] ] );
ok( 'a scheduled one says so too',      str_contains( $cell, 'Synced with Klaviyo · scheduled' ), true );
$cell = DZE_Klaviyo::state_cell( 's1', [ 'kind' => 'launch', 'draft' => [ 'campaign' => 'C1', 'sent' => time() ] ] );
ok( 'and one that has gone out',        str_contains( $cell, 'Synced with Klaviyo · sent' ), true );
// And an email that is NOT there offers to go and look for it.
$cell = DZE_Klaviyo::state_cell( 's1', [ 'kind' => 'launch' ] );
ok( 'an unsynced row says that plainly', str_contains( $cell, 'Not in Klaviyo yet' ), true );
ok( 'and offers to find it',             str_contains( $cell, 'dze-mail-find' ), true );

echo "An email already in Klaviyo, linked back without writing a word\n";
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'f1' => [
	'kind' => 'launch', 'subject' => 'The Back to School Sale is live: 10% off everything',
] ] ] ];
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	// Not under the name this plugin would give it…
	[ 'code' => 200, 'body' => '{"data":[]}' ],
	// …but under its own SUBJECT, which is what a campaign made in Klaviyo is
	// called. This is the email the shop could not see.
	[ 'code' => 200, 'body' => json_encode( [
		'data'     => [ [ 'id' => 'C-BTS', 'attributes' => [ 'status' => 'Scheduled', 'created_at' => '2026-08-29T10:00:00Z' ],
			'relationships' => [ 'campaign-messages' => [ 'data' => [ [ 'id' => 'M-BTS' ] ] ] ] ] ],
		'included' => [ [ 'type' => 'campaign-message', 'id' => 'M-BTS' ] ],
	] ) ],
	[ 'code' => 200, 'body' => '{"data":{"type":"template","id":"T-BTS"}}' ],
];
$_POST = [ 'rule' => 'promo', 'email' => 'f1' ];
$said  = null;
try { DZE_Klaviyo::ajax_find(); } catch ( DZE_Json_Sent $e ) { $said = $e->payload; }
ok( 'it is found by its subject line',  $said['url'] ?? '', 'https://www.klaviyo.com/campaign/C-BTS/wizard' );
ok( 'and nothing was written there',
	count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) => in_array( ( $c['method'] ?? '' ), [ 'POST', 'PATCH', 'DELETE' ], true ) ) ), 0 );
$linked = get_option( $copy )['promo']['emails']['f1']['draft'] ?? [];
ok( 'the id is filed beside the email', $linked['campaign'] ?? '', 'C-BTS' );
ok( 'and it knows it is scheduled',     ( (int) ( $linked['scheduled'] ?? 0 ) ) > 0, true );
ok( 'the row now says it is synced',
	str_contains( (string) ( $said['state'] ?? '' ), 'Synced with Klaviyo' ), true );
$_POST = [];

echo "The campaigns as they are really named in this account\n";
// From the shop's own screen:
//   "Back to School Sale! -10% Off the entire shop — Launch"
//   "Back to School Sale! -10% Off the entire shop — Last chance"
//   "Patriot Day Sale! -15% on the entire store — Reminder"
// The promotion is titled "Back to School Sale" here now: the title was
// edited after the campaigns were made, so an exact-name search finds
// nothing. The TYPE never drifts, and the titles are compared as a reader
// compares them.
$same = new ReflectionMethod( 'DZE_Klaviyo', 'same_title' );
$same->setAccessible( true );
ok( 'a title with its discount is the same promotion',
	$same->invoke( null, 'Back to School Sale! -10% Off the entire shop', 'Back to School Sale' ), true );
ok( 'punctuation and case do not matter',
	$same->invoke( null, 'back to school sale', 'Back to School Sale!' ), true );
ok( 'but another promotion never matches',
	$same->invoke( null, 'Patriot Day Sale! -15% on the entire store', 'Back to School Sale' ), false );
ok( 'and one word in common is not a promotion',
	$same->invoke( null, 'Sale', 'Summer Sale' ), false );

$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'bts' => [
	'kind' => 'launch', 'subject' => 'Something else entirely',
] ] ] ];
$GLOBALS['dze_rules'] = [ 'promo' => [ 'title' => 'Back to School Sale' ] ];
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => '{"data":[]}' ],   // not under the name it would have today
	[ 'code' => 200, 'body' => '{"data":[]}' ],   // not under its subject either
	// By its type — and the account answers with all three campaigns.
	[ 'code' => 200, 'body' => json_encode( [
		'data' => [
			[ 'id' => 'C-PATRIOT', 'attributes' => [ 'name' => 'Patriot Day Sale! -15% on the entire store — Launch', 'status' => 'Draft', 'created_at' => '2026-09-01T18:08:00Z' ],
				'relationships' => [ 'campaign-messages' => [ 'data' => [ [ 'id' => 'M-P' ] ] ] ] ],
			[ 'id' => 'C-BTS', 'attributes' => [ 'name' => 'Back to School Sale! -10% Off the entire shop — Launch', 'status' => 'Scheduled', 'created_at' => '2026-08-31T14:36:00Z' ],
				'relationships' => [ 'campaign-messages' => [ 'data' => [ [ 'id' => 'M-BTS' ] ] ] ] ],
		],
		'included' => [ [ 'type' => 'campaign-message', 'id' => 'M-BTS' ] ],
	] ) ],
	[ 'code' => 200, 'body' => '{"data":{"type":"template","id":"T-BTS"}}' ],
];
$_POST = [ 'rule' => 'promo', 'email' => 'bts' ];
$said  = null;
try { DZE_Klaviyo::ajax_find(); } catch ( DZE_Json_Sent $e ) { $said = $e->payload; }
ok( 'the Back to School one is the one found',
	false !== strpos( (string) ( $said['url'] ?? '' ), 'C-BTS' ), true );
ok( 'not the Patriot Day one beside it',
	false !== strpos( (string) ( $said['url'] ?? '' ), 'C-PATRIOT' ), false );
ok( 'and nothing was written in the account',
	count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) => in_array( ( $c['method'] ?? '' ), [ 'POST', 'PATCH', 'DELETE' ], true ) ) ), 0 );
$_POST = [];
$GLOBALS['dze_rules'] = null;

echo "An ARCHIVED campaign is never the one taken\n";
// "des mails archivés aussi étaient utilisés pour les syncro. N'utiliser que
// des mails non archivés." Klaviyo hands archived campaigns back like any
// other unless it is told not to: this account held two archived drafts made
// TODAY in front of the real scheduled Back to School campaign, and
// newest-wins adopted one of those — a campaign the owner has put away and
// will never open again.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'bts' => [
	'kind' => 'launch', 'subject' => 'Something else entirely',
] ] ] ];
$GLOBALS['dze_rules'] = [ 'promo' => [ 'title' => 'Back to School Sale' ] ];
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => '{"data":[]}' ],
	[ 'code' => 200, 'body' => '{"data":[]}' ],
	// The account's real answer, archived flags and all.
	[ 'code' => 200, 'body' => json_encode( [
		'data' => [
			[ 'id' => 'C-ARCH', 'attributes' => [ 'name' => 'Back to School Sale! -10% Off the entire shop — Launch', 'status' => 'Draft', 'archived' => true, 'created_at' => '2026-09-01T13:07:18Z' ],
				'relationships' => [ 'campaign-messages' => [ 'data' => [ [ 'id' => 'M-ARCH' ] ] ] ] ],
			[ 'id' => 'C-BTS', 'attributes' => [ 'name' => 'Back to School Sale! -10% Off the entire shop — Launch', 'status' => 'Scheduled', 'archived' => false, 'created_at' => '2026-08-29T13:06:49Z' ],
				'relationships' => [ 'campaign-messages' => [ 'data' => [ [ 'id' => 'M-BTS' ] ] ] ] ],
		],
		'included' => [ [ 'type' => 'campaign-message', 'id' => 'M-BTS' ] ],
	] ) ],
	[ 'code' => 200, 'body' => '{"data":{"type":"template","id":"T-BTS"}}' ],
];
$_POST = [ 'rule' => 'promo', 'email' => 'bts' ];
$said  = null;
try { DZE_Klaviyo::ajax_find(); } catch ( DZE_Json_Sent $e ) { $said = $e->payload; }
ok( 'the live campaign is the one taken',
	false !== strpos( (string) ( $said['url'] ?? '' ), 'C-BTS' ), true );
ok( 'never the archived one, newer though it is',
	false !== strpos( (string) ( $said['url'] ?? '' ), 'C-ARCH' ), false );
$asked_live = array_values( array_filter( $GLOBALS['dze_sent'], static fn( $c ) =>
	false !== strpos( (string) ( $c['url'] ?? '' ), 'campaigns/?filter=' ) ) );
ok( 'three questions were asked of the account', count( $asked_live ), 3 );
ok( 'and every one of them asked for live campaigns only',
	count( array_filter( $asked_live, static fn( $c ) =>
		false !== strpos( rawurldecode( (string) $c['url'] ), 'equals(archived,false)' ) ) ), 3 );
$_POST = [];
$GLOBALS['dze_rules'] = null;

// The id already filed beside the email, when THAT campaign has been
// archived since: not ours to rewrite either — it is out of the owner's
// campaign list, so a rewrite would go where nobody looks.
$open = new ReflectionMethod( 'DZE_Klaviyo', 'draft_open' );
$open->setAccessible( true );
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [ [ 'code' => 200, 'body' => '{"data":{"id":"C-ARCH","attributes":{"status":"Draft","archived":true}}}' ] ];
$shut = '';
ok( 'an archived draft is not open for rewriting', $open->invokeArgs( null, [ 'C-ARCH', &$shut ] ), false );
ok( 'and it says which refusal it is',  $shut, 'archived' );
ok( 'the account was asked whether it is archived',
	false !== strpos( (string) ( $GLOBALS['dze_sent'][0]['url'] ?? '' ), 'archived' ), true );
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [ [ 'code' => 200, 'body' => '{"data":{"id":"C-D","attributes":{"status":"Draft","archived":false}}}' ] ];
$shut = '';
ok( 'a live draft still is',            $open->invokeArgs( null, [ 'C-D', &$shut ] ), true );
ok( 'with no refusal to report',        $shut, '' );

// End to end: the email is filed under an archived campaign, and pressing
// the button puts it in the account as a NEW one, saying so.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'ar1' => [
	'kind' => 'launch', 'when' => gmdate( 'Y-m-d', time() + 4 * 86400 ),
	'subject' => 'Back to School', 'preview' => 'P', 'body' => '<p>Words.</p>',
	'draft' => [ 'campaign' => 'C-ARCH', 'message' => 'M-ARCH', 'template' => 'T-ARCH' ],
] ] ] ];
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => '{"data":{"id":"C-ARCH","attributes":{"status":"Draft","archived":true}}}' ],
	[ 'code' => 200, 'body' => '{"data":[]}' ],   // nothing live under that name
	[ 'code' => 200, 'body' => '{"data":[]}' ],   // nor its subject
	[ 'code' => 200, 'body' => '{"data":[]}' ],   // nor its type
	[ 'code' => 200, 'body' => $frame_def ],
	[ 'code' => 200, 'body' => '{"data":{"id":"T-NEW"}}' ],
	// The new campaign, with the message Klaviyo names for it and the day it
	// kept — so the answer this test reads is the sentence about archiving
	// and not a complaint about something else.
	[ 'code' => 200, 'body' => json_encode( [ 'data' => [
		'id'            => 'C-NEW',
		'attributes'    => [ 'send_strategy' => [ 'method' => 'static', 'datetime' => gmdate( 'Y-m-d', time() + 4 * 86400 ) . 'T09:00:00+00:00' ] ],
		'relationships' => [ 'campaign-messages' => [ 'data' => [ [ 'id' => 'M-NEW' ] ] ] ],
	] ] ) ],
];
$was_reply = $GLOBALS['dze_reply'];
$GLOBALS['dze_reply'] = [ 'code' => 200, 'body' => '{"data":{"id":"C-NEW","attributes":{"status":"Draft","editor_type":"SYSTEM_DRAGGABLE"}}}' ];
$made = null;
try { $made = DZE_Klaviyo::draft( 'promo', 'ar1' ); } catch ( Throwable $e ) { if ( getenv( 'DZE_DEBUG' ) ) { echo $e->getMessage(), "\n"; } }
$GLOBALS['dze_reply'] = $was_reply;
ok( 'the archived template is not rewritten',
	count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) =>
		'PATCH' === ( $c['method'] ?? '' ) && false !== strpos( (string) $c['url'], 'T-ARCH' ) ) ), 0 );
ok( 'a campaign is created instead',
	count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) =>
		'POST' === ( $c['method'] ?? '' ) && preg_match( '#/api/campaigns/$#', (string) $c['url'] ) ) ), 1 );
ok( 'and the screen says why there are now two',
	false !== strpos( (string) ( $made['warning'] ?? '' ), 'archived in Klaviyo' ), true );
$GLOBALS['dze_queue'] = [];

echo "The rows are put back in line with what Klaviyo really holds\n";
// "Emails dazont toujours syncro avec des emails klaviyo draft, visiblement."
// They were: the rows pointed at drafts archived days before, while the real
// scheduled campaign sat beside them in the account. A row is drawn from what
// was filed here, and the account moves without us.
$GLOBALS['dze_rules'] = [ 'promo' => [ 'title' => 'Back to School Sale' ] ];

// 1. Archived there, and the live campaign of that name is claimed instead.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'l1' => [
	'kind' => 'launch', 'subject' => 'The Back to School Sale is live',
	'draft' => [ 'campaign' => 'C-ARCH', 'message' => 'M-ARCH', 'template' => 'T-ARCH' ],
] ] ] ];
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => '{"data":{"id":"C-ARCH","attributes":{"status":"Draft","archived":true}}}' ],
	[ 'code' => 200, 'body' => json_encode( [
		'data'     => [ [ 'id' => 'C-BTS', 'attributes' => [ 'status' => 'Scheduled', 'created_at' => '2026-08-29T13:06:49Z' ],
			'relationships' => [ 'campaign-messages' => [ 'data' => [ [ 'id' => 'M-BTS' ] ] ] ] ] ],
		'included' => [ [ 'type' => 'campaign-message', 'id' => 'M-BTS' ] ],
	] ) ],
	[ 'code' => 200, 'body' => '{"data":{"type":"template","id":"T-BTS"}}' ],
	// And what the campaign just claimed back holds: its state and its day.
	[ 'code' => 200, 'body' => '{"data":{"id":"C-BTS","attributes":{"status":"Scheduled","archived":false,"send_time":"2026-09-05T09:00:00+00:00"}}}' ],
];
$seen = DZE_Klaviyo::reconcile( 'promo', [ 'title' => 'Back to School Sale' ] );
$now  = get_option( $copy )['promo']['emails']['l1']['draft'] ?? [];
ok( 'the archived link is let go of',   $now['campaign'] ?? '', 'C-BTS' );
ok( 'and the live one is filed instead', ( (int) ( $now['scheduled'] ?? 0 ) ) > 0, true );
ok( 'with the day Klaviyo holds it for', $now['goes'] ?? '', '2026-09-05' );
ok( 'the row is handed back redrawn',
	str_contains( (string) ( $seen['rows']['l1'] ?? '' ), 'Synced with Klaviyo' ), true );
ok( 'and the screen is told what moved',
	false !== strpos( (string) $seen['message'], 'Launch' ), true );
ok( 'nothing was written in the account',
	count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) => in_array( ( $c['method'] ?? '' ), [ 'POST', 'PATCH', 'DELETE' ], true ) ) ), 0 );

// 2. Deleted there, and nothing of that name left: the row stops claiming it.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'l2' => [
	'kind' => 'launch', 'subject' => 'Gone from the account',
	'draft' => [ 'campaign' => 'C-DEAD', 'message' => 'M', 'template' => 'T' ],
] ] ] ];
$GLOBALS['dze_queue'] = [
	[ 'code' => 404, 'body' => '{"errors":[{"detail":"Campaign not found"}]}' ],
	[ 'code' => 200, 'body' => '{"data":[]}' ],
	[ 'code' => 200, 'body' => '{"data":[]}' ],
	[ 'code' => 200, 'body' => '{"data":[]}' ],
];
$seen = DZE_Klaviyo::reconcile( 'promo', [ 'title' => 'Back to School Sale' ] );
ok( 'a deleted campaign is not still claimed',
	( get_option( $copy )['promo']['emails']['l2']['draft']['campaign'] ?? '' ), '' );
ok( 'and the row says it is not there',
	str_contains( (string) ( $seen['rows']['l2'] ?? '' ), 'Not in Klaviyo yet' ), true );
ok( 'with what to do about it',
	false !== strpos( (string) $seen['message'], 'Put it in Klaviyo again' ), true );

// 3. Scheduled in Klaviyo itself: the row stops calling it a draft.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'l3' => [
	'kind' => 'launch', 'subject' => 'Scheduled over there',
	'draft' => [ 'campaign' => 'C9', 'message' => 'M9', 'template' => 'T9' ],
] ] ] ];
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => '{"data":{"id":"C9","attributes":{"status":"Scheduled","archived":false,"send_time":"2026-09-05T09:00:00+00:00"}}}' ],
];
$seen = DZE_Klaviyo::reconcile( 'promo', [ 'title' => 'Back to School Sale' ] );
$now  = get_option( $copy )['promo']['emails']['l3']['draft'] ?? [];
ok( 'a campaign scheduled in Klaviyo reads as scheduled here',
	( (int) ( $now['scheduled'] ?? 0 ) ) > 0, true );
ok( 'and the day it goes out comes with it', $now['goes'] ?? '', '2026-09-05' );
ok( 'the row says so',
	str_contains( (string) ( $seen['rows']['l3'] ?? '' ), 'Synced with Klaviyo · scheduled' ), true );

// 4. The account cannot be reached. Nothing is touched: that is news about
//    the network, and a row rewritten on it would be a lie of ours.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'l4' => [
	'kind' => 'launch', 'subject' => 'Still fine',
	'draft' => [ 'campaign' => 'C7', 'message' => 'M7', 'template' => 'T7' ],
] ] ] ];
$GLOBALS['dze_queue'] = [ [ 'code' => 500, 'body' => '{"errors":[{"detail":"Server error"}]}' ] ];
$seen = DZE_Klaviyo::reconcile( 'promo', [ 'title' => 'Back to School Sale' ] );
ok( 'a shop offline keeps its link',
	( get_option( $copy )['promo']['emails']['l4']['draft']['campaign'] ?? '' ), 'C7' );
ok( 'and no row is redrawn on it',      count( $seen['rows'] ), 0 );
ok( 'and nothing is claimed about it',  $seen['message'], '' );

// 5. The screen asks once. A reload is a cheap gesture; the account is not.
$GLOBALS['dze_transients'] = [];
$GLOBALS['dze_queue'] = [ [ 'code' => 200, 'body' => '{"data":{"id":"C7","attributes":{"status":"Draft","archived":false}}}' ] ];
$GLOBALS['dze_sent']  = [];
$_POST = [ 'rule' => 'promo' ];
$said  = null;
try { DZE_Klaviyo::ajax_state(); } catch ( DZE_Json_Sent $e ) { $said = $e->payload; }
ok( 'the first look asks the account',  ! empty( $said['asked'] ), true );
$before = count( $GLOBALS['dze_sent'] );
try { DZE_Klaviyo::ajax_state(); } catch ( DZE_Json_Sent $e ) { $said = $e->payload; }
ok( 'the second look does not',         ! empty( $said['asked'] ), false );
ok( 'and asks Klaviyo nothing at all',  count( $GLOBALS['dze_sent'] ), $before );
$_POST = [];
$GLOBALS['dze_transients'] = [];
$GLOBALS['dze_queue'] = [];
$GLOBALS['dze_rules'] = null;

echo "An email whose campaign the shop already has is not made twice\n";
// "Des emails déjà syncro par le passé ne le sont plus, notamment celui de
// lancement du back to school." The campaign is still in the account; what
// was lost is the id beside the email. Before making a second one, the
// account is asked whether a DRAFT of exactly this name is already there.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'ad1' => [
	'kind' => 'launch', 'when' => gmdate( 'Y-m-d', time() + 4 * 86400 ),
	'subject' => 'Back to School', 'preview' => 'P', 'body' => '<p>Words.</p>',
] ] ] ]; // no draft: the id is gone
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	// The account, asked by name — and it HAS it.
	[ 'code' => 200, 'body' => json_encode( [
		'data'     => [ [ 'id' => 'C-OLD', 'relationships' => [ 'campaign-messages' => [ 'data' => [ [ 'id' => 'M-OLD' ] ] ] ] ] ],
		'included' => [ [ 'type' => 'campaign-message', 'id' => 'M-OLD' ] ],
	] ) ],
	[ 'code' => 200, 'body' => '{"data":{"type":"template","id":"T-OLD"}}' ],   // what that message reads
	[ 'code' => 200, 'body' => $frame_def ],                                    // the shop's own header
	[ 'code' => 200, 'body' => '{"data":{"type":"template","id":"T-OLD"}}' ],   // asked again by the rewrite
	[ 'code' => 200, 'body' => '{"data":{"id":"T-OLD","attributes":{"editor_type":"SYSTEM_DRAGGABLE"}}}' ],
	[ 'code' => 200, 'body' => '{"data":{"id":"T-OLD"}}' ],                     // PATCH
	[ 'code' => 200, 'body' => json_encode( [ 'data' => [ 'id' => 'C-OLD', 'attributes' => [
		'send_strategy' => [ 'method' => 'static', 'datetime' => gmdate( 'Y-m-d', time() + 4 * 86400 ) . 'T09:00:00+00:00' ] ] ] ] ) ],
	[ 'code' => 200, 'body' => '{"data":{"id":"M-OLD"}}' ],
	[ 'code' => 200, 'body' => '{"data":[]}' ],
	[ 'code' => 200, 'body' => '{"data":{"id":"tag1"}}' ],
	[ 'code' => 200, 'body' => '{}' ],
	[ 'code' => 200, 'body' => '{"data":{"id":"C-OLD","attributes":{"status":"Draft"}}}' ],
];
try { DZE_Klaviyo::draft( 'promo', 'ad1' ); } catch ( Throwable $e ) { if ( getenv( 'DZE_DEBUG' ) ) { echo $e->getMessage(), "\n"; } }
$asked_by_name = array_values( array_filter( $GLOBALS['dze_sent'], static fn( $c ) =>
	false !== strpos( (string) ( $c['url'] ?? '' ), 'campaigns/?filter=' ) ) );
ok( 'the account is asked by name',     count( $asked_by_name ), 1 );
// Every status, not only drafts: the email this shop lost was SCHEDULED,
// which is precisely the one that must not be duplicated.
ok( 'whatever status it is in',
	false !== strpos( rawurldecode( (string) $asked_by_name[0]['url'] ), 'equals(status' ), false );
ok( 'and by its exact name',
	false !== strpos( rawurldecode( (string) $asked_by_name[0]['url'] ), 'equals(name,"Summer — Launch")' ), true );
ok( 'no second campaign is created',
	count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) =>
		// The CREATE endpoint, not the tag relationship that also ends in
		// "campaigns/": a check that matches both proves nothing.
		'POST' === ( $c['method'] ?? '' ) && preg_match( '#/api/campaigns/$#', (string) $c['url'] ) ) ), 0 );
ok( 'the found draft is the one rewritten',
	count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) =>
		'PATCH' === ( $c['method'] ?? '' ) && false !== strpos( (string) $c['url'], 'templates/T-OLD' ) ) ), 1 );
$mail_now = get_option( $copy )['promo']['emails']['ad1']['draft'] ?? [];
ok( 'and the email keeps its id this time', $mail_now['campaign'] ?? '', 'C-OLD' );

// The case this shop actually had: the campaign is SCHEDULED. It is claimed
// back so the row stops calling it missing — and not one word of it is
// rewritten. A second campaign beside a scheduled one is a promotion sent
// twice.
$GLOBALS['dze_opts'][ $copy ]['promo']['emails']['ad1']['draft'] = [];
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => json_encode( [
		'data'     => [ [ 'id' => 'C-SCHED', 'attributes' => [ 'status' => 'Scheduled', 'created_at' => '2026-09-01T10:00:00Z' ],
			'relationships' => [ 'campaign-messages' => [ 'data' => [ [ 'id' => 'M-SCHED' ] ] ] ] ] ],
		'included' => [ [ 'type' => 'campaign-message', 'id' => 'M-SCHED' ] ],
	] ) ],
	[ 'code' => 200, 'body' => '{"data":{"type":"template","id":"T-SCHED"}}' ],
];
$made = DZE_Klaviyo::draft( 'promo', 'ad1' );
ok( 'a scheduled campaign is claimed back', $made['campaign'] ?? '', 'C-SCHED' );
ok( 'and nothing at all is rewritten',
	count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) => in_array( ( $c['method'] ?? '' ), [ 'PATCH', 'POST' ], true ) ) ), 0 );
ok( 'the row is told why it was left alone',
	false !== strpos( (string) ( $made['warning'] ?? '' ), 'Unschedule it in Klaviyo' ), true );
$kept = get_option( $copy )['promo']['emails']['ad1']['draft'] ?? [];
ok( 'the email keeps the id from now on',   $kept['campaign'] ?? '', 'C-SCHED' );
ok( 'and knows it is scheduled',            ( (int) ( $kept['scheduled'] ?? 0 ) ) > 0, true );

// A campaign that has GONE OUT is never adopted. "Si un email est envoyé sur
// klaviyo et n'est pas supprimé, puis regénéré sur dazont et resynchro avec
// l'ancien email klaviyo, on se retrouve avec 2 versions différentes": one
// version in people's inboxes and another this screen believes in. History is
// left alone and a new campaign is made beside it, said in words.
$GLOBALS['dze_opts'][ $copy ]['promo']['emails']['ad1']['draft'] = [];
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => json_encode( [
		'data'     => [ [ 'id' => 'C-GONE', 'attributes' => [ 'status' => 'Sent', 'created_at' => '2026-08-01T10:00:00Z' ],
			'relationships' => [ 'campaign-messages' => [ 'data' => [ [ 'id' => 'M-GONE' ] ] ] ] ] ],
		'included' => [ [ 'type' => 'campaign-message', 'id' => 'M-GONE' ] ],
	] ) ],
	[ 'code' => 200, 'body' => '{"data":{"type":"template","id":"T-GONE"}}' ],
	[ 'code' => 200, 'body' => $frame_def ],
	[ 'code' => 200, 'body' => '{"data":{"id":"T-NEW"}}' ],
	// The campaign, WITH the message Klaviyo names for it: a creation that
	// stops halfway raises a warning of its own and hides the one under test.
	[ 'code' => 200, 'body' => json_encode( [ 'data' => [ 'id' => 'C-NEW',
		'relationships' => [ 'campaign-messages' => [ 'data' => [ [ 'id' => 'M-NEW' ] ] ] ] ] ] ) ],
	[ 'code' => 200, 'body' => '{"data":{"id":"M-NEW"}}' ],
	[ 'code' => 200, 'body' => '{"data":[]}' ],
	[ 'code' => 200, 'body' => '{"data":{"id":"tag1"}}' ],
	[ 'code' => 200, 'body' => '{}' ],
	[ 'code' => 200, 'body' => '{"data":{"id":"C-NEW","attributes":{"status":"Draft"}}}' ],
];
$made = null;
try { $made = DZE_Klaviyo::draft( 'promo', 'ad1' ); } catch ( Throwable $e ) { if ( getenv( 'DZE_DEBUG' ) ) { echo $e->getMessage(), "\n"; } }
ok( 'a sent campaign is never rewritten',
	count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) =>
		'PATCH' === ( $c['method'] ?? '' ) && false !== strpos( (string) $c['url'], 'T-GONE' ) ) ), 0 );
ok( 'nor adopted as this email',
	get_option( $copy )['promo']['emails']['ad1']['draft']['campaign'] ?? '', 'C-NEW' );
ok( 'and the shop is told there are now two',
	false !== strpos( (string) ( $made['warning'] ?? '' ), 'already gone out' ), true );

// Nothing of that name in the account: the ordinary path, one campaign made.
$GLOBALS['dze_opts'][ $copy ]['promo']['emails']['ad1']['draft'] = [];
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => '{"data":[]}' ],   // asked by name, nothing there
	[ 'code' => 200, 'body' => '{"data":[]}' ],   // by its subject line either
	[ 'code' => 200, 'body' => '{"data":[]}' ],   // nor by its type, for a title edited since
	[ 'code' => 200, 'body' => $frame_def ],
	[ 'code' => 200, 'body' => '{"data":{"id":"T-NEW"}}' ],
	[ 'code' => 200, 'body' => '{"data":{"id":"C-NEW"}}' ],
];
try { DZE_Klaviyo::draft( 'promo', 'ad1' ); } catch ( Throwable $e ) { if ( getenv( 'DZE_DEBUG' ) ) { echo $e->getMessage(), "\n"; } }
ok( 'an email nobody has filed is filed afresh',
	count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) => 'POST' === ( $c['method'] ?? '' ) ) ) > 0, true );
$GLOBALS['dze_queue'] = [];

echo "Deleting the EVENT takes its campaigns out of Klaviyo\n";
// Where the account's look-alike orphans came from: the owner deleted or
// redid a promotion, the local rows vanished, and the drafts lived on in
// Klaviyo for him to open by mistake ever after.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [
	'd1' => [ 'kind' => 'launch', 'when' => gmdate( 'Y-m-d', time() + 86400 ), 'subject' => 'A', 'body' => '<p>a</p>',
		'draft' => [ 'campaign' => 'CA', 'template' => 'TA' ] ],
	'd2' => [ 'kind' => 'last', 'when' => gmdate( 'Y-m-d', time() + 6 * 86400 ), 'subject' => 'B', 'body' => '<p>b</p>',
		'draft' => [ 'campaign' => 'CB', 'template' => 'TB' ] ],
] ] ];
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => '{"data":{"id":"CA","attributes":{"status":"Draft"}}}' ],
	[ 'code' => 204, 'body' => '' ], [ 'code' => 204, 'body' => '' ],
	[ 'code' => 200, 'body' => '{"data":{"id":"CB","attributes":{"status":"Draft"}}}' ],
	[ 'code' => 204, 'body' => '' ], [ 'code' => 204, 'body' => '' ],
];
DZE_Klaviyo::forget( 'promo' );
$gone = array_values( array_filter( $GLOBALS['dze_sent'], static fn( $c ) => 'DELETE' === ( $c['method'] ?? '' ) ) );
ok( 'both campaigns are deleted over there', count( array_filter( $gone, static fn( $c ) =>
	str_contains( (string) $c['url'], 'campaigns/CA/' ) || str_contains( (string) $c['url'], 'campaigns/CB/' ) ) ), 2 );
ok( 'their templates too', count( array_filter( $gone, static fn( $c ) =>
	str_contains( (string) $c['url'], 'templates/TA/' ) || str_contains( (string) $c['url'], 'templates/TB/' ) ) ), 2 );
ok( 'and nothing is left locally', get_option( $copy )['promo'] ?? null, null );

echo "A row the form dropped takes its campaign with it\n";
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [
	'k1' => [ 'kind' => 'launch', 'when' => gmdate( 'Y-m-d', time() + 86400 ), 'subject' => 'Keep', 'body' => '<p>k</p>',
		'draft' => [ 'campaign' => 'CK', 'template' => 'TK' ] ],
	'k2' => [ 'kind' => 'last', 'when' => gmdate( 'Y-m-d', time() + 6 * 86400 ), 'subject' => 'Drop', 'body' => '<p>d</p>',
		'draft' => [ 'campaign' => 'CD', 'template' => 'TD' ] ],
] ] ];
$GLOBALS['dze_sent']  = [];
$GLOBALS['dze_queue'] = [
	[ 'code' => 200, 'body' => '{"data":{"id":"CD","attributes":{"status":"Draft"}}}' ],
	[ 'code' => 204, 'body' => '' ], [ 'code' => 204, 'body' => '' ],
];
DZE_Klaviyo::save_copy( 'promo', [ 'title' => 'Summer' ], [
	'dze_email_shown' => 1,
	'dze_email' => [ 'k1' => [ 'exists' => 1, 'kind' => 'launch', 'subject' => 'Keep' ] ],
] );
ok( 'the dropped row\'s campaign is withdrawn', count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) =>
	'DELETE' === ( $c['method'] ?? '' ) && str_contains( (string) $c['url'], 'campaigns/CD/' ) ) ), 1 );
ok( 'the kept row\'s campaign is untouched', count( array_filter( $GLOBALS['dze_sent'], static fn( $c ) =>
	str_contains( (string) $c['url'], 'campaigns/CK' ) ) ), 0 );
ok( 'and the kept email is still there', get_option( $copy )['promo']['emails']['k1']['subject'] ?? '', 'Keep' );
$GLOBALS['dze_queue'] = [];

echo "The email that is written is the one the screen asked for\n";
// "Création en manuel d'un mail et choix du type : last chance : toujours
// bugé, j'ai obtenu un email de lancement." A row added on the screen is not
// in the database until the event is saved, so the writing read the stored
// email, found nothing, and fell back to the first type in the list. The row
// says which type it is before a word is asked for.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'new1' => [] ] ] ];
$_POST = [ 'rule' => 'promo', 'email' => 'new1', 'kind' => 'last', 'when' => gmdate( 'Y-m-d', time() + 6 * 86400 ) ];
$GLOBALS['dze_answers'] = [ json_encode( [ 'subject' => 'Last call', 'preview' => 'P', 'body' => '<p>Ends tonight.</p>' ] ) ];
$GLOBALS['dze_asked']   = [];
try { DZE_Klaviyo::ajax_write(); } catch ( Throwable $e ) { /* wp_send_json_success stops the world in real life */ }
$kept = get_option( $copy )['promo']['emails']['new1'] ?? [];
ok( 'the type is kept before writing',  $kept['kind'] ?? '', 'last' );
ok( 'and the day with it',              $kept['when'] ?? '', $_POST['when'] );
ok( 'so the brief is the LAST CHANCE one',
	false !== strpos( (string) ( $GLOBALS['dze_asked'][0]['user'] ?? '' ), 'Last chance' ), true );
// A type nobody offers is not written into the email.
$_POST = [ 'rule' => 'promo', 'email' => 'new1', 'kind' => 'not-a-type' ];
$GLOBALS['dze_answers'] = [ json_encode( [ 'subject' => 'S', 'preview' => 'P', 'body' => '<p>B</p>' ] ) ];
try { DZE_Klaviyo::ajax_write(); } catch ( Throwable $e ) {}
ok( 'an unknown type is refused, not stored',
	get_option( $copy )['promo']['emails']['new1']['kind'] ?? '', 'last' );
$_POST = [];
$GLOBALS['dze_answers'] = [];

echo "The products of an email are the shop's own, not the buyer's\n";
// "Bug titre produit en français dans le template email: Cagoule noire 2
// trous" — in an English email. The sales table holds the product that was
// BOUGHT, and a French customer bought the French post: used as it stands,
// that id puts a French title and a French link in every language's email.
// Every id is brought back to the original product first.
$GLOBALS['dze_origin'] = [ 501 => 7, 502 => 7, 503 => 9 ];
ok( 'a translation is read as its original',
	DZE_Klaviyo::in_shop_language( [ 501, 503 ] ), [ 7, 9 ] );
ok( 'and two translations of one product are ONE product',
	DZE_Klaviyo::in_shop_language( [ 501, 502, 503 ] ), [ 7, 9 ] );
ok( 'an untranslated shop is left exactly as it is',
	DZE_Klaviyo::in_shop_language( [ 11, 12 ] ), [ 11, 12 ] );
$GLOBALS['dze_origin'] = [];

echo "A language that comes back empty is asked once more\n";
// "Translated — 46 texts in FR, PL, ES · DE — Nothing came back for DE." Four
// languages written and one empty is a model that hiccuped. Asking again is
// what a person would do, so it is done here rather than left to him.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'mail1' => [
	'kind' => 'launch', 'subject' => 'Summer sale',
	'draft' => [ 'campaign' => 'C1', 'message' => '01ABC', 'langs' => [ 'fr', 'de' ] ],
] ] ] ];
$GLOBALS['dze_reply']   = [ 'code' => 200, 'body' => $values ];
$GLOBALS['dze_asked']   = [];
$GLOBALS['dze_answers'] = [ 'not json at all', '{"1":"Sommerschlussverkauf","2":"<p>Alles muss raus</p>"}' ];
$n = DZE_Klaviyo::translate_language( 'promo', 'mail1', 'de' );
ok( 'the second answer is the one kept', $n, 2 );
ok( 'and it took exactly two calls',     count( $GLOBALS['dze_asked'] ), 2 );
// Twice and no more: a model that answers nothing twice is not going to on
// the fifth, and every try is paid for.
$GLOBALS['dze_asked']   = [];
$GLOBALS['dze_answers'] = [ 'no', 'still no', '{"1":"never reached"}' ];
$threw = '';
try { DZE_Klaviyo::translate_language( 'promo', 'mail1', 'de' ); } catch ( Throwable $e ) { $threw = $e->getMessage(); }
ok( 'it gives up after two',             count( $GLOBALS['dze_asked'] ), 2 );
ok( 'and says which language it was',    false !== strpos( $threw, 'DE' ), true );
$GLOBALS['dze_answers'] = [];

echo "A product link in each language, from the id the shop already has\n";
// "Sur les pages d'édition produit on voit les urls des autres langues." So
// does the plugin: it CHOSE these products and holds their ids, and
// WordPress holds their translations. Reading a URL back into a post was the
// complication — url_to_postid() does not answer for a WooCommerce product,
// so that lookup was dead on this shop and every link stayed English.
$GLOBALS['dze_trans']    = [ 7 => [ 'fr' => 77, 'de' => 78 ] ];
$GLOBALS['dze_origin']   = [];
$GLOBALS['dze_perma']    = [ 77 => 'https://kula.test/fr/cagoule', 78 => 'https://kula.test/de/sturmhaube' ];
$GLOBALS['dze_resolved'] = [];
$map = DZE_Klaviyo::link_map( [ 7 ], [ 'fr', 'de' ] );
ok( 'the French page of that product',  $map['https://kula.test/p/7']['fr'] ?? '', 'https://kula.test/fr/cagoule' );
ok( 'and the German one',               $map['https://kula.test/p/7']['de'] ?? '', 'https://kula.test/de/sturmhaube' );
// The half that was wrong on the shop's own emails: the FR link must carry the
// FRENCH slug, never the English one with a language bolted onto the address.
ok( 'the English slug is not on the French link',
	false !== strpos( (string) ( $map['https://kula.test/p/7']['fr'] ?? '' ), 'p/7' ), false );
ok( 'no URL was resolved back into a post', $GLOBALS['dze_resolved'], [] );

// And it is that map the translation write uses, not a guess.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [ 'mail1' => [
	'kind' => 'launch', 'subject' => 'Summer sale', 'products' => [ 7 ],
	'draft' => [ 'campaign' => 'C1', 'message' => '01ABC', 'langs' => [ 'fr', 'de' ] ],
] ] ] ];
$vals2 = json_encode( [ 'data' => [ 'attributes' => [ 'values' => [
	[ 'id' => 'x::subject', 'source_value' => 'Summer sale' ],
	[ 'id' => 'b::data.attributes.href', 'source_value' => 'https://kula.test/p/7' ],
] ] ] ] );
$GLOBALS['dze_reply'] = [ 'code' => 200, 'body' => $vals2 ];
DZE_Klaviyo::translate_language( 'promo', 'mail1', 'fr' );
$GLOBALS['dze_sent'] = [];
DZE_Klaviyo::save_translations( 'promo', 'mail1' );
$patch = array_values( array_filter( $GLOBALS['dze_sent'], fn( $c ) => 'PATCH' === ( $c['method'] ?? '' ) ) );
$sent2 = [];
foreach ( (array) ( json_decode( (string) ( $patch[0]['body'] ?? '' ), true )['data']['attributes']['values'] ?? [] ) as $v ) {
	$sent2[ $v['id'] ] = $v['translations'];
}
ok( 'the email really carries the French page',
	$sent2['b::data.attributes.href']['fr'] ?? '', 'https://kula.test/fr/cagoule' );
$GLOBALS['dze_reply'] = [ 'code' => 200, 'body' => $values ];

echo "The shop can see what a link becomes, before any email exists\n";
// The mapping depends on how WPML was set up on THIS shop, and no test here
// can know that. So the shop is shown the answer where its languages are
// listed: one real product, and what each language would actually receive.
$GLOBALS['dze_products'] = [ 7 ];
$smp = DZE_Klaviyo::link_sample();
ok( 'a real product is used',           $smp['url'] ?? '', 'https://kula.test/p/7' );
ok( 'and every language is answered',   array_keys( $smp['rows'] ?? [] ), [ 'fr', 'de' ] );
// The product's OWN German page, with its own slug — the address the product
// edit screen shows and the language switcher uses, not a rule applied to a
// string.
ok( 'each one on its own page',         $smp['rows']['de'] ?? '', 'https://kula.test/de/sturmhaube' );

echo "The row says everything it knows, the moment it knows it\n";
// "Schedule it / EN written, FR, DE, PL, ES open / Translate it > tout ça
// apparaît seulement après rafraichissement de la page." Putting an email in
// Klaviyo answered with a bare link, and the three things the row had just
// EARNED — its draft link, its Schedule button, its languages — waited for a
// reload. The cell is one function now, and the answer carries it.
$cell = DZE_Klaviyo::state_cell( 'm9', [
	'kind'  => 'launch',
	'draft' => [ 'campaign' => 'C9', 'message' => 'M9', 'langs' => [ 'fr', 'de' ] ],
] );
ok( 'the campaign is linked',           str_contains( $cell, 'Open in Klaviyo' ), true );
ok( 'and the row says it IS synced',    str_contains( $cell, 'Synced with Klaviyo' ), true );
ok( 'it can be scheduled from the row', str_contains( $cell, 'dze-mail-sched' ), true );
// The languages are drawn as WPML draws them everywhere else in this admin:
// a flag and a code per language, ticked when written, hollow when owed.
// The languages it OWES, drawn as WPML draws them — and not a sentence
// beside them saying the same thing in our own words.
ok( 'the languages it owes are shown',
	str_contains( $cell, 'dze-lang' ) && str_contains( $cell, '>FR<' ) && str_contains( $cell, '>DE<' ), true );
ok( 'each of them marked as owed',      substr_count( $cell, 'dze-lang is-todo' ), 2 );
ok( 'and nothing repeats it in prose',  str_contains( $cell, 'not translated yet' ), false );
ok( 'and it can be translated from the row', str_contains( $cell, 'dze-mail-i18n' ), true );
// The two buttons sit TOGETHER at the end of the cell. They used to be one
// above the other with sentences between them, which is the screen the shop
// called disordered: "les boutons sont désordonnés, le style cassé".
$does = strpos( $cell, 'dze-mail-does' );
ok( 'the buttons are in one row of their own', false !== $does, true );
ok( 'and both are inside it',
	$does < strpos( $cell, 'dze-mail-sched' ) && $does < strpos( $cell, 'dze-mail-i18n' ), true );
ok( 'the sentences come before them',
	strpos( $cell, 'dze-mail-langs' ) < $does, true );

// Already scheduled and already translated: the same cell, other words.
// The DAY is not repeated — it is on the row already, beside the title, and
// repeating it left a hole on every row whose day is not known.
$cell = DZE_Klaviyo::state_cell( 'm9', [
	'kind'  => 'launch', 'when' => '2026-09-20',
	'draft' => [ 'campaign' => 'C9', 'scheduled' => time(), 'goes' => '2026-09-20',
		'langs' => [ 'fr', 'de' ], 'done_langs' => [ 'fr', 'de' ], 'translated' => time(), 'texts' => 24 ],
] );
ok( 'the day is not said twice',        str_contains( $cell, '2026-09-20' ), false );
ok( 'and offers to undo it',            str_contains( $cell, 'Unschedule' ), true );
// A row claimed back from Klaviyo knows it is scheduled before it knows the
// day. It still offers to undo it: the button hung on knowing the day, so
// that row offered to SCHEDULE a campaign already on its way.
ok( 'scheduled with no day still undoes',
	str_contains( DZE_Klaviyo::state_cell( 'm9', [ 'kind' => 'launch',
		'draft' => [ 'campaign' => 'C9', 'scheduled' => time() ] ] ), 'Unschedule' ), true );
// The one thing the date beside the title cannot say: Klaviyo holding
// another day.
ok( 'a day that differs IS said',
	str_contains( DZE_Klaviyo::state_cell( 'm9', [ 'kind' => 'launch', 'when' => '2026-09-20',
		'draft' => [ 'campaign' => 'C9', 'scheduled' => time(), 'goes' => '2026-09-27' ] ] ),
		'Klaviyo has it on' ), true );
ok( 'and not when the two agree',
	str_contains( $cell, 'Klaviyo has it on' ), false );
ok( 'a translated one counts its texts', str_contains( $cell, 'Translated — 24 texts' ), true );
ok( 'with each language ticked',        substr_count( $cell, 'dze-lang is-done' ), 2 );

// An email that has never been to Klaviyo says THAT, and offers the one thing
// worth doing about it: an empty cell was a row that looked broken.
$cell = DZE_Klaviyo::state_cell( 'm9', [ 'kind' => 'launch' ] );
ok( 'an email with no campaign says so', str_contains( $cell, 'Not in Klaviyo yet' ), true );
ok( 'and nothing is claimed about it',   str_contains( $cell, 'Synced with Klaviyo' ), false );

echo "The row says the state, and keeps the standard behind the i\n";
// "Links point at each language ⓘ > inutile, c'est la norme dans notre setup
// multilingue. Comme Translated — 21 texts > on s'en fiche aussi." Both said
// that nothing had gone wrong, in two lines of prose per row. What is worth a
// row is WHICH languages are written, and the flags say it.
$cell = DZE_Klaviyo::state_cell( 'm9', [
	'kind' => 'launch', 'when' => '2026-09-20',
	'draft' => [ 'campaign' => 'C9', 'scheduled' => time(), 'goes' => '2026-09-20',
		'langs' => [ 'fr', 'de' ], 'done_langs' => [ 'fr', 'de' ], 'translated' => time(), 'texts' => 21,
		'links_note' => 'Links point at the FR, DE pages of the shop.' ],
] );
ok( 'the flags carry the state',        substr_count( $cell, 'dze-lang is-done' ), 2 );
ok( 'the counting is behind the i',     str_contains( $cell, 'Translated — 21 texts ·' ), true );
ok( 'and not on the row',               str_contains( strip_tags( $cell ), 'Translated' ), false );
ok( 'nor is a link note that says all is well',
	str_contains( strip_tags( $cell ), 'Links point at' ), false );

// A link that did NOT move is another matter: the email is translated and its
// readers land on the English page, and nothing else on this screen shows it.
$bad = DZE_Klaviyo::state_cell( 'm9', [
	'kind' => 'launch',
	'draft' => [ 'campaign' => 'C9', 'langs' => [ 'fr' ], 'done_langs' => [ 'fr' ], 'translated' => time(),
		'links_note' => 'Links did NOT move for FR — WPML gave the same address back.' ],
] );
ok( 'a broken link is still said out loud',
	str_contains( strip_tags( $bad ), 'Links did not move' ), true );

echo "The promotions list says how many emails are IN Klaviyo\n";
// "✉ 2 emails / 2 in Klaviyo > A changer en x/x emails in klaviyo." Two lines
// left the division to the reader.
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [
	'a' => [ 'kind' => 'launch', 'when' => gmdate( 'Y-m-d', time() + 86400 ), 'subject' => 'A', 'draft' => [ 'campaign' => 'C1' ] ],
	'b' => [ 'kind' => 'last', 'when' => gmdate( 'Y-m-d', time() + 6 * 86400 ), 'subject' => 'B' ],
] ] ];
ob_start();
DZE_Klaviyo::instance()->render_cell( 'promo', [ 'title' => 'Summer' ] );
$col = (string) ob_get_clean();
ok( 'one line, and it is the fraction', str_contains( $col, '1/2 emails in Klaviyo' ), true );
ok( 'the old second line is gone',      str_contains( $col, '1 in Klaviyo<' ), false );
$GLOBALS['dze_opts'][ $copy ] = [ 'promo' => [ 'emails' => [] ] ];
ob_start();
DZE_Klaviyo::instance()->render_cell( 'promo', [ 'title' => 'Summer' ] );
ok( 'a promotion with no emails says nothing', str_contains( (string) ob_get_clean(), '—' ), true );

echo "Two emails too close together, whichever promotion they belong to\n";
// "J'ai un email qui part le 05 pour l'offre du back to school. L'offre du
// patriot day suit juste derrière. Le warm-up est prévu le 06/09. Ce n'est pas
// bon. Il doit s'écouler au moins 3 jours entre différents emails."
ok( 'three days, shipped',              DZE_Klaviyo::gap(), 3 );
$GLOBALS['dze_opts'][ DZE_Klaviyo::OPT ]['gap'] = 5;
ok( 'and the shop may say otherwise',   DZE_Klaviyo::gap(), 5 );
unset( $GLOBALS['dze_opts'][ DZE_Klaviyo::OPT ]['gap'] );

// The calendar of the WHOLE shop: the clash is never inside one promotion,
// it is the last chance of one falling beside the warm-up of the next.
$GLOBALS['dze_rules'] = [
	'bts'     => [ 'title' => 'Back to School Sale' ],
	'patriot' => [ 'title' => 'Patriot Day Sale' ],
];
$GLOBALS['dze_opts'][ $copy ] = [
	'bts' => [ 'emails' => [
		'l' => [ 'kind' => 'last', 'when' => '2026-09-05', 'subject' => 'Two days left', 'preview' => 'Ends Sunday night' ],
	] ],
	'patriot' => [ 'emails' => [
		'w' => [ 'kind' => 'warm', 'when' => '2026-09-06', 'subject' => 'Coming' ],
		'x' => [ 'kind' => 'launch', 'subject' => 'No day yet' ],
	] ],
];
$cal = DZE_Klaviyo::calendar( 'bts' );
ok( 'the other promotion is in the calendar', count( $cal ), 1 );
ok( 'with the day it goes out',         $cal[0]['day'] ?? '', '2026-09-06' );
ok( 'and named as the shop names it',
	false !== strpos( (string) ( $cal[0]['label'] ?? '' ), 'Patriot Day Sale — ' ), true );
ok( 'an email with no day is not a date', count( DZE_Klaviyo::calendar( 'patriot' ) ), 1 );
ok( 'and the promotion asked about is left out — its rows are on the screen',
	implode( ',', array_column( DZE_Klaviyo::calendar( 'bts' ), 'rule' ) ), 'patriot' );

// The screen carries it: the check runs where the days are edited, and dies
// entirely if the page does not hand it the other promotions.
$GLOBALS['dze_asked'] = [];
ob_start();
DZE_Klaviyo::instance()->render_editor( 'bts', $GLOBALS['dze_rules']['bts'] );
$screen = (string) ob_get_clean();
ok( 'the screen carries the gap',       false !== strpos( $screen, 'data-gap="3"' ), true );
ok( 'and the other promotions\' emails',
	false !== strpos( $screen, 'data-calendar' ) && false !== strpos( $screen, 'Patriot Day Sale' ), true );
ok( 'with a place to say it on the row', false !== strpos( $screen, 'dze-mail-clash' ), true );
ok( 'and the day it would move to',     false !== strpos( $screen, 'dze-klav-e-free' ), true );
// The two lines an inbox shows: the subject, and the preview text under it.
// Judging them meant opening each email one at a time.
// Read where the ROW shows them, never where the form carries them: every
// field of every email travels in a hidden input, so a screen that lost the
// line still holds the words.
ok( 'each row shows its subject',
	false !== strpos( $screen, 'dze-mail-subject">Two days left' ), true );
ok( 'and its preview text',
	false !== strpos( $screen, 'dze-mail-preview">Ends Sunday night' ), true );
// The picture is PART of writing an email, so there is nothing to tick: not
// beside the button that writes them all, not on the email, not in the form.
// "Ça devrait pas exister et être toujours inclus dans la rédaction entière
// de l'email."
$GLOBALS['dze_opts'][ DZE_Klaviyo::OPT ]['images'] = 1;
ob_start();
DZE_Klaviyo::instance()->render_editor( 'bts', $GLOBALS['dze_rules']['bts'] );
$with = (string) ob_get_clean();
ok( 'nothing is asked beside the button',
	false !== strpos( $with, 'dze-mail-shots' ), false );
ok( 'nor on the email itself',          false !== strpos( $with, 'dze-klav-e-want' ), false );
ok( 'and the form carries no such field',
	false !== strpos( $with, 'want_picture' ), false );
// What the shop DOES decide is still there: the prompt, and the test that
// judges it without spending an email.
ok( 'the picture prompt is still tried here',
	false !== strpos( $with, 'dze-klav-e-shot' ), true );
// The setting is written only by a form that carried it, like every other
// one here: a screen saving something else must not take the shop's rhythm
// down with it.
$GLOBALS['dze_opts'][ DZE_Klaviyo::OPT ]['gap'] = 5;
$kept = DZE_Klaviyo::instance()->sanitize( [ 'form' => 1, 'days' => 30 ] );
ok( 'a form that did not carry it leaves it', $kept['gap'] ?? '', 5 );
$kept = DZE_Klaviyo::instance()->sanitize( [ 'form' => 1, 'gap' => 40 ] );
ok( 'and an impossible figure is brought back', $kept['gap'] ?? '', 30 );
unset( $GLOBALS['dze_opts'][ DZE_Klaviyo::OPT ]['gap'] );
$GLOBALS['dze_rules'] = null;

echo "The email settings screen actually draws\n";
// A class that loads is not a screen that works. This tab carries the
// prompts, their "what this prompt is sent with" blocks and the languages
// table with its link column — all added at once, none of them ever drawn
// until somebody opened the page. That is how a settings tab was a white
// page for six versions.
$GLOBALS['dze_products'] = [ 7 ];
$screen = '';
$boom   = '';
try {
	ob_start();
	DZE_Klaviyo::render_settings();
	$screen = (string) ob_get_clean();
} catch ( Throwable $e ) {
	$screen = (string) ob_get_clean();
	$boom   = $e->getMessage();
}
ok( 'it draws without dying',           $boom, '' );
ok( 'the languages table is there',     str_contains( $screen, 'A product link becomes' ), true );
ok( 'with the real link in it',         str_contains( $screen, 'https://kula.test/de/sturmhaube' ), true );
ok( 'the days between two emails can be set',
	str_contains( $screen, 'dze-klav-gap' ), true );
ok( 'and every prompt says what it is sent with',
	substr_count( $screen, 'What this prompt is sent with' ), 3 );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
