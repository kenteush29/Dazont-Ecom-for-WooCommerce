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
function apply_filters( $t, $v = null, ...$r ) { return $v; }
function do_action( $t, ...$a ) {}
function current_user_can( $c ) { return true; }
function is_admin() { return true; }
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . $p; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function add_query_arg( ...$a ) { return ''; }
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
	public function __construct( $c = '', $m = '' ) { $this->msg = $m; }
	public function get_error_message() { return $this->msg; }
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
class DZE_Discounts { public static function get_rules() { return $GLOBALS['dze_rules'] ?? [ 'promo' => [ 'title' => 'Summer' ] ]; } }
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
	/** A shop whose languages live in a directory, which is WPML's usual shape. */
	public static function url_in_language( $url, $lang ) {
		$url = (string) $url;
		if ( 0 !== strpos( $url, 'https://kula.test/' ) || 'en' === $lang ) {
			return $url;
		}
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
	[ 'code' => 200, 'body' => $frame_def ],                                                       // the frame, read fresh
	[ 'code' => 200, 'body' => '{"data":{"id":"C1","attributes":{"status":"Draft"}}}' ],           // draft_open
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

echo "The shop can see what a link becomes, before any email exists\n";
// The mapping depends on how WPML was set up on THIS shop, and no test here
// can know that. So the shop is shown the answer where its languages are
// listed: one real product, and what each language would actually receive.
$GLOBALS['dze_products'] = [ 7 ];
$smp = DZE_Klaviyo::link_sample();
ok( 'a real product is used',           $smp['url'] ?? '', 'https://kula.test/p/7' );
ok( 'and every language is answered',   array_keys( $smp['rows'] ?? [] ), [ 'fr', 'de' ] );
ok( 'each one on its own side of the shop',
	$smp['rows']['de'] ?? '', 'https://kula.test/de/p/7' );

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
ok( 'the draft is linked',              str_contains( $cell, 'Draft in Klaviyo' ), true );
ok( 'it can be scheduled from the row', str_contains( $cell, 'dze-mail-sched' ), true );
ok( 'it says what is written and what is open',
	str_contains( $cell, 'EN written, FR, DE open' ), true );
ok( 'and it can be translated from the row', str_contains( $cell, 'dze-mail-i18n' ), true );

// Already scheduled and already translated: the same cell, other words.
$cell = DZE_Klaviyo::state_cell( 'm9', [
	'kind'  => 'launch',
	'draft' => [ 'campaign' => 'C9', 'scheduled' => time(), 'goes' => '2026-09-20',
		'langs' => [ 'fr', 'de' ], 'done_langs' => [ 'fr', 'de' ], 'translated' => time(), 'texts' => 24 ],
] );
ok( 'a scheduled email says the day',   str_contains( $cell, 'Scheduled in Klaviyo for' ), true );
ok( 'and offers to undo it',            str_contains( $cell, 'Unschedule' ), true );
ok( 'a translated one counts its texts', str_contains( $cell, 'Translated — 24 texts in FR, DE' ), true );

// An email that has never been to Klaviyo has nothing to say and says nothing.
ok( 'an email with no campaign is a blank cell',
	trim( DZE_Klaviyo::state_cell( 'm9', [ 'kind' => 'launch' ] ) ), '' );

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
ok( 'with the real link in it',         str_contains( $screen, 'https://kula.test/de/p/7' ), true );
ok( 'and every prompt says what it is sent with',
	substr_count( $screen, 'What this prompt is sent with' ), 3 );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
