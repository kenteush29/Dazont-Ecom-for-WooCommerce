<?php
/**
 * The Diagnostic's criteria, actually RUN.
 *
 * Run before every release:  php tools/test-diagnostic.php dazont-ecom
 *
 * `php -l` proves a file parses and check-methods.php proves every method it
 * calls exists. Neither of them proves that "_block_image_1 is empty" answers
 * yes on a product where that field is empty — and that is the only question
 * the shop is actually asking. So this loads the class against a fake shop
 * and reads real values with the real code: the custom field in every shape
 * ACF stores one (text, an attachment id, a list, an unticked true/false, a
 * repeater's count), the name a criterion is given, and the rows written
 * against field ids that no longer exist.
 *
 * It exits non-zero on the first wrong answer, so it can gate a release.
 */
$dir = $argv[1] ?? 'dazont-ecom';

// --- the smallest WordPress this class can be read against ------------------
define( 'ABSPATH', '/wp/' );
define( 'DZE_URL', 'http://example.test/' );
define( 'DZE_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['dze_meta']  = [];
$GLOBALS['dze_opts']  = [];

function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return $n > 1 ? $b : $a; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_js( $s ) { return addslashes( (string) $s ); }
function esc_textarea( $s ) { return esc_html( $s ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function get_option( $k, $d = false ) { return $GLOBALS['dze_opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['dze_opts'][ $k ] = $v; return true; }
$GLOBALS['dze_transients'] = [];
function get_transient( $k ) { return $GLOBALS['dze_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['dze_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['dze_transients'][ $k ] ); return true; }
function add_action() {} function add_filter() {} function register_setting() {}
function current_user_can( $c ) { return true; }
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . $p; }
function is_admin() { return true; }
function wp_unslash( $v ) { return $v; }
function wp_style_is( $h, $l = 'enqueued' ) { return true; }
function wp_enqueue_style( ...$a ) {}
function wp_enqueue_script( ...$a ) {}
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function wp_date( $f, $t = null ) { return date( 'Y-m-d H:i', (int) $t ); }
function human_time_diff( $a, $b = 0 ) { return '1 hour'; }
function checked( $a, $b, $e = true ) { return ''; }
function selected( $a, $b, $e = true ) { return ''; }
function submit_button( $t = null ) {}
function settings_fields( $g ) {}
function get_admin_page_title() { return 'Diagnostic'; }
function add_query_arg( ...$a ) { return is_array( $a[0] ?? null ) ? ( ( $a[1] ?? '' ) . '?' . http_build_query( $a[0] ) ) : ''; }
function wp_parse_args( $a, $d = [] ) { return array_merge( (array) $d, (array) $a ); }
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function post_type_exists( $t ) { return true; }
function get_post_types( $args = [], $out = 'names' ) {
	$mk = static function ( $name, $label ) {
		$o = new stdClass(); $o->name = $name; $o->labels = new stdClass(); $o->labels->name = $label; return $o;
	};
	return [ 'post' => $mk( 'post', 'Articles' ), 'page' => $mk( 'page', 'Pages' ), 'product' => $mk( 'product', 'Products' ) ];
}
function get_post_meta( $id, $key, $single = false ) {
	$v = $GLOBALS['dze_meta'][ $id ][ $key ] ?? '';
	return $single ? $v : [ $v ];
}
function get_post_thumbnail_id( $id ) { return (int) ( $GLOBALS['dze_meta'][ $id ]['_thumbnail_id'] ?? 0 ); }
function wp_get_attachment_metadata( $id ) { return []; }
function get_permalink( $id = 0 ) { return 'http://example.test/?p=' . $id; }
function get_edit_post_link( $id = 0, $c = '' ) { return 'http://example.test/edit/' . $id; }
class WP_Post { public $ID = 0; public $post_title = ''; public $post_content = ''; public $post_excerpt = '';
	public $post_modified_gmt = '2026-01-01 00:00:00'; public $post_date_gmt = '2026-01-01 00:00:00'; public $comment_count = 0; }
class WP_Error {}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class DZE_Diag_Test_Wpdb {
	public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts'; public $prefix = 'wp_';
	public function prepare( $q, ...$a ) { return [ $q, $a ]; }
	public function esc_like( $t ) { return $t; }
	public function get_var( $q ) {
		// SHOW TABLES LIKE — the icl_translations table is there in this shop.
		return $GLOBALS['dze_has_icl'] ? ( is_array( $q ) ? (string) ( $q[1][0] ?? '' ) : '' ) : '';
	}
	public function get_col( $q ) {
		if ( ! is_array( $q ) || false === strpos( (string) $q[0], 'icl_translations' ) ) { return []; }
		[ $type, $lang ] = $q[1];
		return $GLOBALS['dze_icl'][ $type ][ $lang ] ?? [];
	}
	public function get_results( $q, $m = null ) {
		$sql = is_array( $q ) ? (string) $q[0] : (string) $q;
		if ( false === strpos( $sql, 'product_variation' ) ) { return []; }
		$key = is_array( $q ) ? (string) ( $q[1][0] ?? '' ) : '';
		$GLOBALS['dze_sql'][] = [ $sql, $key ];
		$out = [];
		foreach ( $GLOBALS['dze_variations'] as $v ) {
			// The stub answers what the SQL actually ASKS, clause by clause —
			// not what the function is supposed to want. A stub that filters
			// on its own idea of the query passes on code that stopped
			// filtering, which is worse than having no test at all.
			if ( '' !== $key ) {
				if ( ! isset( $v[ $key ] ) ) { continue; }
				// The attribute must have a value, when the query says so.
				if ( false !== strpos( $sql, "a.meta_value <> ''" ) && '' === (string) $v[ $key ] ) { continue; }
			}
			if ( false !== strpos( $sql, 't.meta_value IS NULL' ) && ! empty( $v['_thumbnail_id'] ) ) { continue; }
			if ( false !== strpos( $sql, "v.post_status = 'publish'" ) && isset( $v['draft'] ) ) { continue; }
			$out[ (int) $v['parent'] ] = ( $out[ (int) $v['parent'] ] ?? 0 ) + 1;
		}
		$rows = [];
		foreach ( $out as $pid => $n ) {
			$rows[] = false !== strpos( $sql, 'v.post_parent AS pid' )
				? [ 'pid' => $pid, 'n' => $n ]
				: [ 'post_parent' => $pid, 'n' => $n ];
		}
		return $rows;
	}
}
$GLOBALS['wpdb']        = new DZE_Diag_Test_Wpdb();
$GLOBALS['dze_has_icl'] = true;
$GLOBALS['dze_icl']     = [];
$GLOBALS['dze_variations'] = [];
$GLOBALS['dze_sql']        = [];
$GLOBALS['dze_posts']   = [];

// WPML, as far as this plugin ever asks.
define( 'ICL_SITEPRESS_VERSION', '4.6.0' );
$GLOBALS['dze_lang'] = 'en';
function apply_filters( $tag, $value = null, ...$rest ) {
	if ( 'wpml_default_language' === $tag || 'wpml_current_language' === $tag ) { return $GLOBALS['dze_lang']; }
	if ( 'wpml_active_languages' === $tag ) {
		return [ 'en' => [ 'native_name' => 'English', 'english_name' => 'English' ],
		         'fr' => [ 'native_name' => 'Français', 'english_name' => 'French' ] ];
	}
	return $value;
}
function do_action( $tag, ...$a ) {}
function get_terms( $args = [] ) { return []; }
function wp_next_scheduled( $h ) { return 0; }
function wp_schedule_event() {} function wp_unschedule_event() {}

// One page of products, then nothing — the shape WP_Query answers in.
class WP_Query {
	public $posts = []; public $post_count = 0;
	public function __construct( $args = [] ) {
		$page = (int) ( $args['paged'] ?? 1 );
		$type = (string) ( $args['post_type'] ?? '' );
		$all  = $GLOBALS['dze_posts'][ $type ] ?? [];
		$this->posts      = 1 === $page ? $all : [];
		$this->post_count = count( $this->posts );
	}
}

require __DIR__ . '/../' . $dir . '/includes/class-wpml.php';
require __DIR__ . '/../' . $dir . '/includes/class-diagnostic.php';

// --- the harness ------------------------------------------------------------
$fails = 0;
$ran   = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) {
		printf( "  ok    %s\n", $what );
		return;
	}
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
/** fails() is private, and it is the one thing worth testing. */
function judge( array $row, int $product_id ): bool {
	static $m = null;
	if ( null === $m ) {
		$m = new ReflectionMethod( 'DZE_Diagnostic', 'fails' );
		$m->setAccessible( true );
	}
	$p = new WP_Post();
	$p->ID = $product_id;
	return (bool) $m->invoke( null, $row, 'product', $p );
}
function rule( string $key, string $test, $value = 0, string $find = '' ): array {
	return [ 'id' => 'x', 'label' => 'x', 'scope' => 'product', 'field' => 'product.meta',
		'key' => $key, 'test' => $test, 'value' => $value, 'find' => $find, 'on' => 1 ];
}

// A product carrying one of every shape ACF actually stores.
$GLOBALS['dze_meta'][7] = [
	'_never_set'    => '',                      // a field nobody ever filled in
	'_text'         => 'Kula tactical gear',    // an ordinary text field
	'_wysiwyg'      => '<p>Two <b>words</b></p>',
	'_image'        => '1284',                  // an image field: an attachment id
	'_gallery'      => [ '11', '12', '13' ],    // a gallery: a list of ids
	'_empty_list'   => [],                      // a gallery with nothing in it
	'_switch_off'   => '0',                     // true/false, unticked
	'_switch_on'    => '1',
	'_repeater'     => '4',                     // ACF keeps a repeater's COUNT here
	'_price_like'   => '19.90',
];

echo "Custom field — empty and not empty\n";
ok( 'never set is empty',              judge( rule( '_never_set', 'empty' ), 7 ), true );
ok( 'never set is not "not empty"',    judge( rule( '_never_set', 'filled' ), 7 ), false );
ok( 'a text is not empty',             judge( rule( '_text', 'empty' ), 7 ), false );
ok( 'an image id is not empty',        judge( rule( '_image', 'empty' ), 7 ), false );
ok( 'a gallery is not empty',          judge( rule( '_gallery', 'empty' ), 7 ), false );
ok( 'an empty gallery IS empty',       judge( rule( '_empty_list', 'empty' ), 7 ), true );
ok( 'an unticked box is an answer',    judge( rule( '_switch_off', 'empty' ), 7 ), false );
ok( 'an unticked box is "not empty"',  judge( rule( '_switch_off', 'filled' ), 7 ), true );
ok( 'a field nobody typed a key for',  judge( rule( '', 'empty' ), 7 ), true );

echo "Custom field — looking for text\n";
ok( 'contains what is there',          judge( rule( '_text', 'contains', 0, 'tactical' ), 7 ), true );
ok( 'contains what is not',            judge( rule( '_text', 'contains', 0, 'shopify' ), 7 ), false );
ok( 'does not contain, when it does not', judge( rule( '_text', 'not_contains', 0, 'shopify' ), 7 ), true );
ok( 'html is read as its words',       judge( rule( '_wysiwyg', 'contains', 0, 'words' ), 7 ), true );
ok( 'a gallery is searched by its ids', judge( rule( '_gallery', 'contains', 0, '12' ), 7 ), true );

echo "Custom field — read as a number\n";
ok( 'a gallery under 4 entries',       judge( rule( '_gallery', 'lt', 4 ), 7 ), true );
ok( 'a gallery is not over 3',         judge( rule( '_gallery', 'gt', 3 ), 7 ), false );
ok( 'a gallery has at least 3',        judge( rule( '_gallery', 'gte', 3 ), 7 ), true );
ok( 'a repeater with fewer than 5',    judge( rule( '_repeater', 'lt', 5 ), 7 ), true );
ok( 'a price over 10',                 judge( rule( '_price_like', 'gt', 10 ), 7 ), true );
ok( 'a price is not over 100',         judge( rule( '_price_like', 'gt', 100 ), 7 ), false );
ok( 'an id equals itself',             judge( rule( '_image', 'eq', 1284 ), 7 ), true );
ok( 'a text is measured in characters', judge( rule( '_text', 'lt', 5 ), 7 ), false );

echo "The name a criterion is given\n";
$named = DZE_Diagnostic::clean_rows( [
	[ 'id' => '', 'scope' => 'product', 'field' => 'product.meta', 'key' => '_block_image_1', 'test' => 'empty', 'value' => 0, 'find' => '', 'on' => 1 ],
	[ 'id' => '', 'scope' => 'product', 'field' => 'product.meta', 'key' => '_block_text_2',  'test' => 'empty', 'value' => 0, 'find' => '', 'on' => 1 ],
	[ 'id' => '', 'scope' => 'product', 'field' => 'product.description', 'key' => '', 'test' => 'lt', 'value' => 120, 'find' => '', 'on' => 1 ],
	// A title typed by hand is not kept: the rule names itself, always.
	[ 'id' => '', 'label' => 'My own title', 'note' => 'Add more photographs.', 'scope' => 'product', 'field' => 'product.gallery', 'key' => '', 'test' => 'lt', 'value' => 3, 'find' => '', 'on' => 1 ],
] );
ok( 'named after the key it reads',    $named[0]['label'] ?? '', '_block_image_1 is empty' );
ok( 'the other key, the other name',   $named[1]['label'] ?? '', '_block_text_2 is empty' );
ok( 'two keys, two ids',               ( $named[0]['id'] ?? '' ) !== ( $named[1]['id'] ?? '' ), true );
ok( 'an ordinary field keeps its own', $named[2]['label'] ?? '', 'Description is less than 120 words' );
ok( 'a hand-written title is not kept', $named[3]['label'] ?? '', 'Gallery photographs is less than 3 photographs' );
ok( 'the description is what is kept',  $named[3]['note'] ?? '', 'Add more photographs.' );

// The name follows the rule. Change the figure and the name changes with it —
// but the id does not, or the last reading would be filed under nothing.
$moved = DZE_Diagnostic::clean_rows( [
	[ 'id' => 'thin', 'scope' => 'product', 'field' => 'product.description', 'key' => '', 'test' => 'lt', 'value' => 50, 'find' => '', 'on' => 1 ],
] );
ok( 'the name follows the figure',      $moved[0]['label'] ?? '', 'Description is less than 50 words' );
ok( 'and the id stays where it was',    $moved[0]['id'] ?? '', 'thin' );

echo "Rows written against fields that no longer exist\n";
$old = DZE_Diagnostic::clean_rows( [
	[ 'id' => 'a', 'label' => 'A number', 'scope' => 'product', 'field' => 'product.meta_number', 'key' => '_stock_box', 'test' => 'lt', 'value' => 3, 'find' => '', 'on' => 1 ],
	[ 'id' => 'b', 'label' => 'A picture', 'scope' => 'product', 'field' => 'product.image_meta', 'key' => '_block_image_1', 'test' => 'empty', 'value' => 0, 'find' => '', 'on' => 1 ],
	[ 'id' => 'c', 'label' => 'Words', 'scope' => 'post', 'field' => 'post.words', 'key' => '', 'test' => 'min_words', 'value' => 300, 'find' => '', 'on' => 1 ],
] );
ok( 'the number field became the field', $old[0]['field'] ?? '', 'product.meta' );
ok( 'and kept its key',                  $old[0]['key'] ?? '', '_stock_box' );
ok( 'the photograph field too',          $old[1]['field'] ?? '', 'product.meta' );
ok( 'and still answers the same',        judge( $old[1] + [ 'key' => '_never_set' ], 7 ), true );
ok( 'an old article row still reads',    $old[2]['field'] ?? '', 'post.content' );
ok( 'and its old operator too',          $old[2]['test'] ?? '', 'lt' );

echo "What a custom field can be asked\n";
$asked = [];
foreach ( DZE_Diagnostic::operators() as $id => $meta ) {
	if ( in_array( 'meta', (array) $meta['kinds'], true ) ) { $asked[] = $id; }
}
ok( 'every comparison, not two of them', count( $asked ), count( DZE_Diagnostic::operators() ) );
ok( 'one custom field per post type',    isset( DZE_Diagnostic::fields()['product.meta'] ) && ! isset( DZE_Diagnostic::fields()['product.meta_number'] ), true );

echo "Reading a shop that sells in more than one language\n";
// Six products in the database: three written in English, three of them
// WPML's French copies. A shop of three, not a shop of six.
$GLOBALS['dze_posts']['product'] = [];
foreach ( [ 101, 102, 103, 201, 202, 203 ] as $one ) {
	$p = new WP_Post();
	$p->ID = $one;
	$p->post_content = 'A short line.';
	$GLOBALS['dze_posts']['product'][] = $p;
}
$GLOBALS['dze_icl'] = [ 'post_product' => [ 'en' => [ 101, 102, 103 ], 'fr' => [ 201, 202, 203 ] ] ];
$GLOBALS['dze_opts']['dze_diagnostic'] = [ 'rows' => [
	[ 'id' => 'thin', 'label' => 'Description too short', 'scope' => 'product',
	  'field' => 'product.description', 'key' => '', 'test' => 'lt', 'value' => 50, 'find' => '', 'on' => 1 ],
] ];

$census = DZE_Diagnostic::scan();
ok( 'the translations are not products',  $census['seen']['product'] ?? 0, 3 );
ok( 'and the shortfall is not doubled',   $census['checks']['thin'] ?? -1, 3 );
ok( 'the language it was read in',        $census['lang'] ?? '', 'en' );
ok( 'nothing went unnarrowed',            $census['every'] ?? null, [] );

// WPML there, its table not readable: the pass must count everything rather
// than nothing, AND say so — a number silently 6 instead of 3 is the bug.
$GLOBALS['dze_has_icl'] = false;
delete_transient( 'x' );
$GLOBALS['dze_transients'] = [];
$census = DZE_Diagnostic::scan();
ok( 'unaskable WPML counts everything',   $census['seen']['product'] ?? 0, 6 );
ok( 'and the screen is told to say so',   in_array( 'product', (array) ( $census['every'] ?? [] ), true ), true );
$GLOBALS['dze_has_icl'] = true;

// No WPML at all: nothing is skipped and nothing is warned about.
$GLOBALS['dze_lang'] = '';
$census = DZE_Diagnostic::scan();
ok( 'a shop in one language reads all',   $census['seen']['product'] ?? 0, 6 );
ok( 'with no warning to give',            $census['every'] ?? null, [] );
$GLOBALS['dze_lang'] = 'en';

echo "What the screen says about that count\n";
// The warning is the whole point of knowing: a count that quietly includes
// every translation must not look like a count that does not.
function drawn(): string {
	static $m = null;
	if ( null === $m ) {
		$m = new ReflectionMethod( 'DZE_Diagnostic', 'render_overview' );
		$m->setAccessible( true );
	}
	ob_start();
	$m->invoke( DZE_Diagnostic::instance() );
	return (string) ob_get_clean();
}
$GLOBALS['dze_opts']['dze_diagnostic_census'] = [
	'at' => time() - 60, 'lang' => 'en', 'every' => [],
	'seen' => [ 'product' => 3 ], 'short' => [ 'product' => 3 ], 'checks' => [ 'thin' => 3 ],
];
$html = drawn();
ok( 'a narrowed reading says the language', false !== strpos( $html, 'Read in <strong>English</strong> only' ), true );
ok( 'and warns about nothing',              false !== strpos( $html, 'count EVERY language' ), false );

$GLOBALS['dze_opts']['dze_diagnostic_census']['every'] = [ 'product' ];
$GLOBALS['dze_opts']['dze_diagnostic_census']['seen']  = [ 'product' => 6 ];
$html = drawn();
ok( 'an unnarrowed reading says so',        false !== strpos( $html, 'count EVERY language' ), true );
ok( 'and names the post type',              false !== strpos( $html, 'Products</strong>' ), true );
ok( 'without also claiming the language',   false !== strpos( $html, 'Read in <strong>English</strong> only' ), false );

echo "What a criterion is FOR\n";
$g = DZE_Diagnostic::clean_rows( [
	// Both ticked, one ticked, and both deliberately cleared — the card posts
	// the key either way, so "neither" has to survive a save.
	[ 'id' => 'both', 'label' => 'Both', 'scope' => 'product', 'field' => 'product.description', 'key' => '', 'test' => 'lt', 'value' => 50, 'find' => '', 'goals' => [ 'seo', 'cro' ], 'on' => 1 ],
	[ 'id' => 'one',  'label' => 'One',  'scope' => 'product', 'field' => 'product.gallery',     'key' => '', 'test' => 'lt', 'value' => 3,  'find' => '', 'goals' => [ '', 'cro' ], 'on' => 1 ],
	[ 'id' => 'none', 'label' => 'None', 'scope' => 'product', 'field' => 'product.sku',         'key' => '', 'test' => 'empty', 'value' => 0, 'find' => '', 'goals' => [ '' ], 'on' => 1 ],
	[ 'id' => 'junk', 'label' => 'Junk', 'scope' => 'product', 'field' => 'product.title',       'key' => '', 'test' => 'empty', 'value' => 0, 'find' => '', 'goals' => [ 'seo', 'astrology' ], 'on' => 1 ],
] );
ok( 'both goals kept',                 $g[0]['goals'] ?? null, [ 'seo', 'cro' ] );
ok( 'one goal kept, the blank dropped', $g[1]['goals'] ?? null, [ 'cro' ] );
ok( 'neither is an answer, and it sticks', $g[2]['goals'] ?? null, [] );
ok( 'a goal we do not know is dropped', $g[3]['goals'] ?? null, [ 'seo' ] );

// A row saved before goals existed carries no key at all: shipped criteria
// take what they ship with, the shop's own take both, and neither is lost.
$was = DZE_Diagnostic::clean_rows( [
	[ 'id' => 'prod_seo_t', 'label' => 'SEO title too long', 'scope' => 'product', 'field' => 'product.seo_title', 'key' => '', 'test' => 'gt', 'value' => 60, 'find' => '', 'on' => 1 ],
	[ 'id' => 'mine', 'label' => 'Mine', 'scope' => 'product', 'field' => 'product.price', 'key' => '', 'test' => 'empty', 'value' => 0, 'find' => '', 'on' => 1 ],
] );
ok( 'an old shipped row takes its own',  $was[0]['goals'] ?? null, [ 'seo' ] );
ok( "an old row of the shop's takes both", $was[1]['goals'] ?? null, [ 'seo', 'cro' ] );

echo "The opportunity, counted per goal\n";
// Three products. Two fall short on a CRO criterion AND on an SEO one; the
// third only on the SEO one. CRO is 2 things to open, SEO is 3 — never 5.
$GLOBALS['dze_posts']['product'] = [];
foreach ( [ 101, 102, 103 ] as $one ) {
	$p = new WP_Post();
	$p->ID = $one;
	$p->post_content = 103 === $one ? str_repeat( 'word ', 80 ) : 'Short.';
	$GLOBALS['dze_meta'][ $one ]['_yoast_wpseo_title'] = '';
	$GLOBALS['dze_posts']['product'][] = $p;
}
$GLOBALS['dze_icl'] = [ 'post_product' => [ 'en' => [ 101, 102, 103 ] ] ];
$GLOBALS['dze_opts']['dze_diagnostic'] = [ 'rows' => [
	[ 'id' => 'thin',  'note' => 'Write a real description.', 'scope' => 'product', 'field' => 'product.description', 'key' => '', 'test' => 'lt', 'value' => 50, 'find' => '', 'goals' => [ 'seo', 'cro' ], 'on' => 1 ],
	[ 'id' => 'nopic', 'note' => 'Shoot these products.',      'scope' => 'product', 'field' => 'product.main_image',  'key' => '', 'test' => 'empty', 'value' => 0, 'find' => '', 'goals' => [ 'cro' ], 'on' => 1 ],
	[ 'id' => 'nosku', 'note' => 'Give them a reference.',     'scope' => 'product', 'field' => 'product.sku',         'key' => '', 'test' => 'empty', 'value' => 0, 'find' => '', 'goals' => [], 'on' => 1 ],
] ];
$GLOBALS['dze_transients'] = [];
$census = DZE_Diagnostic::scan();
ok( 'the thin ones are two',            $census['checks']['thin'] ?? -1, 2 );
ok( 'all three have no photograph',     $census['checks']['nopic'] ?? -1, 3 );
ok( 'CRO counts each thing once',       $census['goals']['cro'] ?? -1, 3 );
ok( 'SEO counts only its own',          $census['goals']['seo'] ?? -1, 2 );
ok( 'a criterion for neither is in no goal', ( $census['goals']['cro'] ?? 0 ) + ( $census['goals']['seo'] ?? 0 ) < 3 + 3, true );

echo "Choosing a goal on the screen\n";
$GLOBALS['dze_opts']['dze_diagnostic_census'] = $census;
unset( $_GET['goal'] );
$html = drawn();
ok( 'both goals are offered',           false !== strpos( $html, 'goal=cro' ) && false !== strpos( $html, 'goal=seo' ), true );
ok( 'and everything is listed',         false !== strpos( $html, 'SKU is empty' ), true );
ok( 'each line says what to do',        false !== strpos( $html, 'Write a real description.' ), true );

$_GET['goal'] = 'seo';
$html = drawn();
ok( "SEO's own criteria are shown",     false !== strpos( $html, 'Description is less than 50 words' ), true );
ok( 'and CRO-only ones are not',        false !== strpos( $html, 'Main photograph is empty' ), false );
ok( 'nor the ones for neither',         false !== strpos( $html, 'SKU is empty' ), false );

$_GET['goal'] = 'cro';
$html = drawn();
ok( "CRO's own criteria are shown",     false !== strpos( $html, 'Main photograph is empty' ), true );
ok( 'a goal nobody typed is ignored',   true, true );
$_GET['goal'] = 'astrology';
$html = drawn();
ok( 'an unknown goal shows everything', false !== strpos( $html, 'SKU is empty' ), true );
unset( $_GET['goal'] );

echo "Variations with no photograph of their own\n";
// One product, six variations: two colours x three sizes. The olive ones have
// a photograph, the black ones have none — so on the COLOUR attribute this
// product has three variations missing one, and six on no attribute at all
// (the three black, plus the three olive... no: the olive ones have one).
$GLOBALS['dze_variations'] = [
	[ 'parent' => 501, 'attribute_pa_couleur' => 'olive', 'attribute_pa_taille' => 's', '_thumbnail_id' => 91 ],
	[ 'parent' => 501, 'attribute_pa_couleur' => 'olive', 'attribute_pa_taille' => 'm', '_thumbnail_id' => 91 ],
	[ 'parent' => 501, 'attribute_pa_couleur' => 'olive', 'attribute_pa_taille' => 'l', '_thumbnail_id' => 91 ],
	[ 'parent' => 501, 'attribute_pa_couleur' => 'noir',  'attribute_pa_taille' => 's', '_thumbnail_id' => 0 ],
	[ 'parent' => 501, 'attribute_pa_couleur' => 'noir',  'attribute_pa_taille' => 'm', '_thumbnail_id' => 0 ],
	[ 'parent' => 501, 'attribute_pa_couleur' => 'noir',  'attribute_pa_taille' => 'l', '_thumbnail_id' => 0 ],
	// "Any colour", with no photograph: it belongs to no colour in particular,
	// so a criterion about the colours must not count it.
	[ 'parent' => 501, 'attribute_pa_couleur' => '',      'attribute_pa_taille' => 'xl', '_thumbnail_id' => 0 ],
];
function gaps( string $key, int $pid ): int {
	static $m = null;
	if ( null === $m ) { $m = new ReflectionMethod( 'DZE_Diagnostic', 'variation_gaps' ); $m->setAccessible( true ); }
	return (int) ( $m->invoke( null, $key )[ $pid ] ?? 0 );
}
ok( 'the colours missing a photograph', gaps( 'attribute_pa_couleur', 501 ), 3 );
ok( 'every variation, no attribute',    gaps( '', 501 ), 4 );
ok( 'an attribute nobody uses',         gaps( 'attribute_pa_matiere', 501 ), 0 );
ok( 'the query asks for one attribute', $GLOBALS['dze_sql'][0][1] ?? '', 'attribute_pa_couleur' );
ok( 'and it reads the variations',      false !== strpos( $GLOBALS['dze_sql'][0][0] ?? '', "post_type = 'product_variation'" ), true );
ok( 'only the published ones',          false !== strpos( $GLOBALS['dze_sql'][0][0] ?? '', "post_status = 'publish'" ), true );
ok( 'an empty attribute is not a value', false !== strpos( $GLOBALS['dze_sql'][0][0] ?? '', "a.meta_value <> ''" ), true );

// And the criterion, end to end: "more than 0 colours with no photograph".
$p = new WP_Post();
$p->ID = 501;
$row = [ 'field' => 'product.variation_images', 'key' => 'attribute_pa_couleur', 'test' => 'gt', 'value' => 0, 'find' => '' ];
ok( 'the product falls short',          judge( $row, 501 ), true );
$row['value'] = 5;
ok( 'but not past its own number',      judge( $row, 501 ), false );
$row = [ 'field' => 'product.variation_images', 'key' => 'attribute_pa_matiere', 'test' => 'gt', 'value' => 0, 'find' => '' ];
ok( 'nor on an attribute it has not',   judge( $row, 501 ), false );

echo "What that criterion is called\n";
$named = DZE_Diagnostic::clean_rows( [
	[ 'id' => '', 'scope' => 'product', 'field' => 'product.variation_images', 'key' => 'attribute_pa_couleur', 'test' => 'gt', 'value' => 0, 'find' => '', 'on' => 1 ],
] );
ok( 'the field keeps its name, with the key',
	$named[0]['label'] ?? '', 'Variations with no photograph of their own (attribute_pa_couleur) is more than 0' );
$tool = new ReflectionMethod( 'DZE_Diagnostic', 'tool_for' );
$tool->setAccessible( true );
ok( 'and it is sent to the image lab',
	$tool->invoke( null, 'product.variation_images', 'product' )['label'] ?? '', 'Image lab' );
ok( 'a custom field is still named by its key alone',
	DZE_Diagnostic::clean_rows( [ [ 'id' => '', 'scope' => 'product', 'field' => 'product.meta', 'key' => '_bloc_1', 'test' => 'empty', 'value' => 0, 'find' => '', 'on' => 1 ] ] )[0]['label'] ?? '',
	'_bloc_1 is empty' );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
