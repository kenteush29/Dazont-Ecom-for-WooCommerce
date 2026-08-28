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
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function delete_transient( $k ) { return true; }
function add_action() {} function add_filter() {} function register_setting() {}
function current_user_can( $c ) { return true; }
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . $p; }
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
	public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts';
	public function prepare( $q, ...$a ) { return $q; }
	public function esc_like( $t ) { return $t; }
	public function get_col( $q ) { return []; }
	public function get_results( $q, $m = null ) { return []; }
}
$GLOBALS['wpdb'] = new DZE_Diag_Test_Wpdb();

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
	[ 'id' => '', 'label' => '', 'scope' => 'product', 'field' => 'product.meta', 'key' => '_block_image_1', 'test' => 'empty', 'value' => 0, 'find' => '', 'on' => 1 ],
	[ 'id' => '', 'label' => '', 'scope' => 'product', 'field' => 'product.meta', 'key' => '_block_text_2',  'test' => 'empty', 'value' => 0, 'find' => '', 'on' => 1 ],
	[ 'id' => '', 'label' => '', 'scope' => 'product', 'field' => 'product.description', 'key' => '', 'test' => 'lt', 'value' => 120, 'find' => '', 'on' => 1 ],
] );
ok( 'named after the key it reads',    $named[0]['label'] ?? '', '_block_image_1 is empty' );
ok( 'the other key, the other name',   $named[1]['label'] ?? '', '_block_text_2 is empty' );
ok( 'two keys, two ids',               ( $named[0]['id'] ?? '' ) !== ( $named[1]['id'] ?? '' ), true );
ok( 'an ordinary field keeps its own', $named[2]['label'] ?? '', 'Description is less than 120 words' );

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

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
