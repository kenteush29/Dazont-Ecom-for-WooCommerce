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
function get_current_user_id() { return (int) ( $GLOBALS['uid'] ?? 1 ); }
function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['umeta'][ (int) $u ][ $k ] ?? ( $single ? '' : [] ); }
function update_user_meta( $u, $k, $v ) { $GLOBALS['umeta'][ (int) $u ][ $k ] = $v; return true; }
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . $p; }
function is_admin() { return true; }
function wp_unslash( $v ) { return $v; }
function wp_style_is( $h, $l = 'enqueued' ) { return true; }
function wp_enqueue_style( ...$a ) {}
function wp_enqueue_script( ...$a ) {}
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function wp_date( $f, $t = null ) { return date( 'Y-m-d H:i', (int) $t ); }
function human_time_diff( $a, $b = 0 ) { return '1 hour'; }
// WordPress's own behaviour, not a blank. Stubbed to '' these two hid every
// question they exist to answer: a box that never comes back ticked and a
// menu that never reopens on the choice that was made both look perfect.
function checked( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? " checked='checked'" : ''; if ( $e ) { echo $r; } return $r; }
function selected( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? " selected='selected'" : ''; if ( $e ) { echo $r; } return $r; }
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
function get_post( $id = 0 ) { return $GLOBALS['dze_posts'][ (int) $id ] ?? null; }
// The list, read in one go the way WordPress reads it — in the order asked
// for, and only the ids that still exist.
function get_posts( $args = [] ) {
	$GLOBALS['dze_read_calls'][] = (array) ( $args['post__in'] ?? [] );
	$out = [];
	foreach ( (array) ( $args['post__in'] ?? [] ) as $id ) {
		if ( isset( $GLOBALS['dze_posts'][ (int) $id ] ) ) { $out[] = $GLOBALS['dze_posts'][ (int) $id ]; }
	}
	return $out;
}
function update_meta_cache( $type, $ids ) { return true; }
function wp_kses_post( $s ) { return (string) $s; }
function wc_price( $n ) { return '<span class="amount">$' . number_format( (float) $n, 2 ) . '</span>'; }
function paginate_links( $a = [] ) { return ''; }
class WP_Post { public $ID = 0; public $post_title = ''; public $post_content = ''; public $post_excerpt = '';
	public $post_modified_gmt = '2026-01-01 00:00:00'; public $post_date_gmt = '2026-01-01 00:00:00'; public $comment_count = 0; }
class WP_Error {}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

class DZE_Diag_Test_Wpdb {
	public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts'; public $prefix = 'wp_';
	public function prepare( $q, ...$a ) { return [ $q, $a ]; }
	public function esc_like( $t ) { return $t; }
	public function get_var( $q ) {
		$sql = is_array( $q ) ? (string) $q[0] : (string) $q;
		// The last edit made to a list — what makes a kept reading die at the
		// right moment. Answered from the posts themselves, so a test that
		// mends a product the way the shop does (its post_modified moves) sees
		// the list judged again.
		if ( false !== strpos( $sql, 'MAX( post_modified_gmt )' ) ) {
			$max = '';
			foreach ( (array) $GLOBALS['dze_posts'] as $id => $post ) {
				if ( ! is_object( $post ) || false === strpos( $sql, (string) $id ) ) { continue; }
				$was = (string) ( $post->post_modified_gmt ?? '' );
				$max = $was > $max ? $was : $max;
			}
			return $max;
		}
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
		// The list's price / sales / last-edit reading: ONE query for the whole
		// list, so the shop can sort a thousand products by what they sold.
		if ( false !== strpos( $sql, 'total_sales' ) ) {
			$GLOBALS['dze_facts_sql'][] = $sql;
			$rows = [];
			foreach ( (array) ( $GLOBALS['dze_facts'] ?? [] ) as $pid => $one ) {
				if ( false === strpos( $sql, (string) $pid ) ) { continue; }
				$rows[] = (object) [
					'ID'            => $pid,
					'post_title'    => (string) ( $one['title'] ?? '' ),
					'post_modified' => (string) ( $one['edited'] ?? '' ),
					'sales'         => (string) ( $one['sales'] ?? '' ),
					'price'         => (string) ( $one['price'] ?? '' ),
				];
			}
			return $rows;
		}
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
$GLOBALS['dze_facts']      = [];
$GLOBALS['dze_facts_sql']  = [];
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

function check_ajax_referer( $a, $b = false, $die = true ) { return true; }
class DZE_Json_Sent extends Exception {
	public $payload; public $ok;
	public function __construct( $payload, $ok ) { parent::__construct( $ok ? 'success' : 'error' ); $this->payload = $payload; $this->ok = $ok; }
}
function wp_send_json_success( $d = null ) { throw new DZE_Json_Sent( $d, true ); }
function wp_send_json_error( $d = null, $c = 0 ) { throw new DZE_Json_Sent( $d, false ); }
class DZE_Modules { public static function enabled( $id ) { return empty( $GLOBALS['module_off'][ $id ] ); } }
class DZE_Restock { const MENU_SLUG = 'dazont-ecom'; }
$GLOBALS['dze_submenus'] = [];
function add_submenu_page( $parent, $title, $menu, $cap, $slug, $cb = null, $pos = null ) {
	$GLOBALS['dze_submenus'][] = [ 'title' => $title, 'menu' => $menu, 'slug' => $slug ];
	return $slug;
}
class DZE_Content {
	const BULK_SLUG = 'dazont-content-bulk';
	public static function image_templates() { return $GLOBALS['tpls'] ?? []; }
}
class DZE_Queue {
	public static function add( $kind, $ids, $auto = false, $payload = [] ) {
		$GLOBALS['queued'][] = [ 'kind' => $kind, 'ids' => $ids, 'auto' => $auto, 'payload' => $payload ];
		return count( (array) $ids );
	}
	public static function url( $a = [] ) { return 'http://shop.test/wp-admin/queue'; }
	public static function pending_map( $family = 'cat_' ) { return $GLOBALS['pending'] ?? []; }
	/** What was ACCEPTED on these objects — the record of what was done. */
	public static function done_map( $ids ) {
		$out = [];
		foreach ( (array) $ids as $id ) {
			if ( isset( $GLOBALS['done'][ (int) $id ] ) ) { $out[ (int) $id ] = $GLOBALS['done'][ (int) $id ]; }
		}
		return $out;
	}
	public static function label_for( $kind, $oid ) { return 'Product photograph'; }
	public static function pending_for( $oid, $family = 'cat_' ) { return ( $GLOBALS['pending'] ?? [] )[ (int) $oid ] ?? []; }
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
	// The real post when the fixture made one — a criterion on a TEXT is
	// judged on what that text says, and a bare object says nothing.
	$p = $GLOBALS['dze_posts'][ $product_id ] ?? null;
	if ( ! $p ) {
		$p = new WP_Post();
		$p->ID = $product_id;
	}
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
	$named[0]['label'] ?? '', 'Variations without an image (attribute_pa_couleur) is more than 0' );
// It COUNTS what is missing, so the name has to say "without": called
// "variation images" it would read as a count of the images that exist, and
// "variation images is empty" would look like the problem when it is the
// opposite. Both comparisons have to read true.
ok( 'and "is empty" reads as none missing',
	DZE_Diagnostic::clean_rows( [ [ 'id' => '', 'scope' => 'product', 'field' => 'product.variation_images', 'key' => '', 'test' => 'empty', 'value' => 0, 'find' => '', 'on' => 1 ] ] )[0]['label'] ?? '',
	'Variations without an image is empty' );
$tool = new ReflectionMethod( 'DZE_Diagnostic', 'tool_for' );
$tool->setAccessible( true );
// PHOTOGRAPHS HAVE NO SCREEN OF THEIR OWN HERE. The image lab is an
// experiment against fal.ai, finished and standing on its own; pointing other
// functions at it would make a bench into a dependency. A product's
// photographs are worked on where that product is opened, and the row already
// links there.
ok( 'a photograph criterion names no tool',
	$tool->invoke( null, 'product.variation_images', 'product' ), [] );
ok( 'nor does the gallery',             $tool->invoke( null, 'product.gallery', 'product' ), [] );
ok( 'but a description still does',
	$tool->invoke( null, 'product.description', 'product' )['label'] ?? '', 'Bulk writing' );
ok( 'and a category description too',
	$tool->invoke( null, 'category.description', 'category' )['label'] ?? '', 'Categories' );
ok( 'a custom field is still named by its key alone',
	DZE_Diagnostic::clean_rows( [ [ 'id' => '', 'scope' => 'product', 'field' => 'product.meta', 'key' => '_bloc_1', 'test' => 'empty', 'value' => 0, 'find' => '', 'on' => 1 ] ] )[0]['label'] ?? '',
	'_bloc_1 is empty' );

echo "A shop that never touched its criteria\n";
// The shipped criteria carry no label — a label is computed from the field,
// the operator and the figure. Handed over raw, every check on a fresh
// install had an empty name: a blank heading above its list and a blank line
// in the report. A shop that had edited its criteria once never saw it.
update_option( DZE_Diagnostic::OPT, [] );
$fresh = DZE_Diagnostic::checks();
ok( 'every shipped check has a name',
	count( array_filter( $fresh, static fn( $c ) => '' === trim( (string) ( $c['label'] ?? '' ) ) ) ), 0 );
ok( 'and the gallery one says what it means',
	$fresh['prod_gallery']['label'] ?? '', 'Gallery photographs is less than 3 photographs' );

echo "The list a shop actually works from\n";
// "Je veux une option de tri des produits par quantité de vente historique.
// Je veux aussi que les prix soient affichés. Et dernière date d'édition."
// The sort is over the WHOLE list, not the fifty rows on screen: a shop
// looking for its best-sellers is not looking for the best-sellers of page
// three. And it is one query for the lot, not one per row.
$GLOBALS['dze_facts'] = [
	101 => [ 'title' => 'Balaclava',  'edited' => '2026-08-01 10:00:00', 'sales' => '5',   'price' => '19.90' ],
	102 => [ 'title' => 'Ancient cap','edited' => '2025-02-03 10:00:00', 'sales' => '250', 'price' => '9.90' ],
	103 => [ 'title' => 'Zulu pouch', 'edited' => '2026-08-30 10:00:00', 'sales' => '40',  'price' => '99.00' ],
];
$GLOBALS['dze_posts'] = [];
foreach ( $GLOBALS['dze_facts'] as $pid => $one ) {
	$p = new WP_Post();
	$p->ID = $pid;
	$p->post_title = $one['title'];
	$GLOBALS['dze_posts'][ $pid ] = $p;
}
// Back to the shipped criteria, so the list under test is a real one.
update_option( DZE_Diagnostic::OPT, [] );
update_option( DZE_Diagnostic::OPT_LISTS, [ 'prod_gallery' => [ 101, 102, 103 ] ] );
update_option( DZE_Diagnostic::OPT_CENSUS, [ 'checks' => [ 'prod_gallery' => 3 ], 'read' => time() ] );

$render = new ReflectionMethod( 'DZE_Diagnostic', 'render_list' );
$render->setAccessible( true );
$show = static function ( array $args ) use ( $render ): string {
	$_GET = $args;
	if ( $args ) { $GLOBALS['umeta'] = []; } // an explicit ask is not a memory
	$GLOBALS['dze_facts_sql'] = [];
	ob_start();
	$render->invoke( DZE_Diagnostic::instance(), 'prod_gallery' );
	return (string) ob_get_clean();
};
/** The product names, in the order the table lists them. */
$order = static function ( string $html ): array {
	preg_match_all( '#<strong>([^<]+)</strong>#', $html, $m );
	return $m[1];
};

$html = $show( [ 'by' => 'sales', 'dir' => 'desc' ] );
ok( 'sorted by what they SOLD',         $order( $html ), [ 'Ancient cap', 'Zulu pouch', 'Balaclava' ] );
ok( 'and the other way round',          $order( $show( [ 'by' => 'sales', 'dir' => 'asc' ] ) ), [ 'Balaclava', 'Zulu pouch', 'Ancient cap' ] );
ok( 'by price',                         $order( $show( [ 'by' => 'price', 'dir' => 'desc' ] ) ), [ 'Zulu pouch', 'Balaclava', 'Ancient cap' ] );
ok( 'by when they were last edited',    $order( $show( [ 'by' => 'edited', 'dir' => 'desc' ] ) ), [ 'Zulu pouch', 'Balaclava', 'Ancient cap' ] );
ok( 'and by name',                      $order( $show( [ 'by' => 'name', 'dir' => 'asc' ] ) ), [ 'Ancient cap', 'Balaclava', 'Zulu pouch' ] );
// A person who has never sorted this list gets it as found. One who HAS gets
// it back the way he left it — the check that used to say "nothing asked means
// found order" was written before the screen had a memory, and a screen with a
// memory is the whole of what was asked for.
// "Aucune indication a l'ecran sur le fait qu'on peut trier, meme pas avec des
// petites fleches." There was one arrow, on the sorted column only — so a list
// nobody had sorted showed none at all and nothing said the titles were
// clickable. WordPress's own sortable header draws it: faint on hover for a
// column that can be sorted, solid on the one that is.
$GLOBALS['umeta'] = [];
$hdr = $show( [ 'by' => 'sales', 'dir' => 'desc' ] );
// One mark per column, always: Product, Price, Sold, Last edited — three pale
// pairs on the ones not in use and one solid arrow on the one that is. A
// screen sorted by nothing in particular used to carry no mark at all.
ok( 'every column carries a mark',
	substr_count( $hdr, 'Sort by this column' ), 4 );
ok( 'the one in use points down',       substr_count( $hdr, '&#9660;' ), 1 );
ok( 'and is the only one in bold',      substr_count( $hdr, 'font-weight:700;' ), 1 );
ok( 'the others say they can be sorted', substr_count( $hdr, '&#8645;' ), 3 );
// Clicking the sorted column turns it round rather than sorting it again the
// same way.
ok( 'the sorted one offers the other direction',
	false !== strpos( $hdr, 'by=sales&#038;dir=asc' ) || false !== strpos( $hdr, 'by=sales&dir=asc' ), true );
$GLOBALS['umeta'] = [];
ok( 'as found, for somebody who never sorted it', $order( $show( [] ) ), [ 'Balaclava', 'Ancient cap', 'Zulu pouch' ] );
$show( [ 'by' => 'price', 'dir' => 'desc' ] );
ok( 'and as he left it, coming back',   $order( $show( [] ) ), [ 'Zulu pouch', 'Balaclava', 'Ancient cap' ] );
ok( 'the memory is his own',            DZE_Diagnostic::kept_view( 'prod_gallery' )['by'] ?? '', 'price' );
$GLOBALS['umeta'] = [];

// MONEY IS GONE FROM THIS SCREEN, and must stay gone. It was read from
// wc_order_product_lookup, converted correctly through the shop's own rates,
// and still answered $6,792,487 against 23 units at $76.90 — because that
// table holds hundreds of rows whose order does not exist. Three releases
// went on defending a column nobody could believe. Units are what the shop
// decides on, and units are all this screen shows.
$html = $show( [] );
ok( 'no money column',                  false !== strpos( $html, 'Revenue' ), false );
ok( 'no currency conversion left',      class_exists( 'DZE_Money' ), false );
ok( 'and nothing reads the sales table for money', class_exists( 'DZE_Sales' ), false );
ok( 'units sold are still there',       false !== strpos( $html, 'Sold' ), true );
// Asking for the order that is gone must not leave the list in a random one:
// the saved view falls back to the order the scan found.
ok( 'a saved sort by revenue falls back',
	$order( $show( [ 'by' => 'rev', 'dir' => 'desc' ] ) ), [ 'Balaclava', 'Ancient cap', 'Zulu pouch' ] );
ok( 'and it is not offered any more',   DZE_Diagnostic::kept_view( 'prod_gallery' )['by'] ?? '', 'found' );
$GLOBALS['umeta'] = [];

// The work to do and the work DONE, on two tabs. "Pour mieux organiser le
// travail, il est préférable de créer 2 onglets : un qui reprend les posts à
// retravailler, un qui reprend ceux qui sont fixed." The whole list is
// re-judged against the shop as it stands — not against the photograph the
// scan took — so a product mended after the reading leaves the list the shop
// is working through.
$GLOBALS['dze_transients'] = [];
$GLOBALS['dze_meta'][102]['_product_image_gallery'] = '11,12,13';
$GLOBALS['dze_posts'][102]->post_modified_gmt = '2026-09-01 12:00:00'; // as a save moves it
$html = $show( [ 'by' => 'sales', 'dir' => 'desc' ] );
ok( 'the tab says how much work is left',  false !== strpos( $html, 'Issues (2)' ), true );
ok( 'and how much is done',                false !== strpos( $html, 'Fixed (1)' ), true );
ok( 'the mended one is off the work list',
	in_array( 'Ancient cap', $order( $html ), true ), false );
ok( 'and it is on the other tab',
	$order( $show( [ 'show' => 'fixed' ] ) ), [ 'Ancient cap' ] );
ok( 'which says what it is showing',
	false !== strpos( $show( [ 'show' => 'fixed' ] ), 'has been mended since the last reading' ), true );
ok( 'sorting stays on the tab it was asked on',
	false !== strpos( $show( [ 'show' => 'fixed' ] ), 'show=fixed&by=sales' ), true );

// A reading kept for a few minutes: paging and sorting a list must not read
// the whole shop again.
$GLOBALS['dze_read_calls'] = [];
$show( [ 'by' => 'price' ] );
$show( [ 'by' => 'name' ] );
ok( 'the list is not re-read on every look', count( $GLOBALS['dze_read_calls'] ), 0 );
// Until the shop moves. Then it is read again, and the row goes back where
// it belongs — which is the whole point of the two tabs.
unset( $GLOBALS['dze_meta'][102]['_product_image_gallery'] );
$GLOBALS['dze_posts'][102]->post_modified_gmt = '2026-09-02 08:00:00';
$html = $show( [ 'by' => 'sales', 'dir' => 'desc' ] );
ok( 'an edit makes it read again',      count( $GLOBALS['dze_read_calls'] ) > 0, true );
ok( 'and the row comes back to the work list', false !== strpos( $html, 'Issues (3)' ), true );
ok( 'with nothing left on the other tab',      false !== strpos( $html, 'Fixed (0)' ), true );

// A product deleted since the reading is nobody's work: it is on neither tab.
update_option( DZE_Diagnostic::OPT_LISTS, [ 'prod_gallery' => [ 101, 102, 103, 999 ] ] );
$GLOBALS['dze_transients'] = [];
$html = $show( [] );
ok( 'a deleted row is not work to do',  false !== strpos( $html, 'Issues (3)' ), true );
ok( 'and it is not "fixed" either',     false !== strpos( $html, 'Fixed (0)' ), true );
update_option( DZE_Diagnostic::OPT_LISTS, [ 'prod_gallery' => [ 101, 102, 103 ] ] );
$GLOBALS['dze_transients'] = [];

// A title carrying markup is a title, not markup: "<span> Military Patch
// </span> Russian Z" was printed at the shop, tags and all.
$GLOBALS['dze_posts'][101]->post_title = '<span> Military Patch </span> Russian Z';
$html = $show( [] );
ok( 'the tags are stripped from a title', false !== strpos( $html, '&lt;span&gt;' ), false );
ok( 'and the words are still there',      false !== strpos( $html, 'Military Patch' ), true );
$GLOBALS['dze_posts'][101]->post_title = 'Balaclava';

$html = $show( [ 'by' => 'sales', 'dir' => 'desc' ] );
ok( 'the figures are on the row',       false !== strpos( $html, '250' ), true );
ok( 'the price too',                    false !== strpos( $html, '9.90' ), true );
ok( 'and the day it was last edited',   false !== strpos( $html, '2025' ), true );
ok( 'read in ONE query for the list',   count( $GLOBALS['dze_facts_sql'] ), 1 );
// A made-up column is worse than none: an unknown sort falls back rather
// than ordering by nothing at all.
ok( 'an order nobody offers is ignored', $order( $show( [ 'by' => 'whatever' ] ) ), [ 'Balaclava', 'Ancient cap', 'Zulu pouch' ] );
$_GET = [];

echo "The last view of a list is the one it opens on\n";
// "La methode de tri n'est pas bonne il faudrait que ca reste sauvegarde, la
// derniere vue." Nine hundred products sorted by what sells, left, and come back
// to: it opened again on the order nobody chose, so the work was re-sorted by
// hand every time.
$GLOBALS['umeta'] = [];
ok( 'a list nobody has sorted has no memory', DZE_Diagnostic::kept_view( 'thin' ), [] );
DZE_Diagnostic::keep_view( 'thin', [ 'by' => 'sales', 'dir' => 'asc', 'show' => 'fixed' ] );
ok( 'what was chosen is remembered',
	DZE_Diagnostic::kept_view( 'thin' ), [ 'by' => 'sales', 'dir' => 'asc', 'show' => 'fixed' ] );
// Per criterion: the galleries are worked through by units sold and the thin
// descriptions alphabetically, and one is not the other.
ok( 'and another list keeps its own',   DZE_Diagnostic::kept_view( 'other' ), [] );
// On the PERSON: two people working through the same shop do not sort it the
// same way, and one of them re-sorting is not a change to the shop.
$GLOBALS['uid'] = 2;
ok( 'somebody else starts clean',       DZE_Diagnostic::kept_view( 'thin' ), [] );
$GLOBALS['uid'] = 1;
ok( 'and the first one still has his',  DZE_Diagnostic::kept_view( 'thin' )['by'] ?? '', 'sales' );
// A write on every page load is a write for nothing.
$GLOBALS['umeta_writes'] = 0;
DZE_Diagnostic::keep_view( 'thin', [ 'by' => 'sales', 'dir' => 'asc', 'show' => 'fixed' ] );
ok( 'an unchanged view writes nothing',
	DZE_Diagnostic::kept_view( 'thin' ), [ 'by' => 'sales', 'dir' => 'asc', 'show' => 'fixed' ] );

echo "A complete product is not the same product at every price\n";
// "Product price between x to y : at least x gallery images. Et ajouter
// d'autres conditions." Each line is a whole sentence: a range, a figure. The
// chained tiers this replaced could not be read a line at a time, and holding
// a $16.90 cap and a $90 plate carrier to the same "3" is what made the count
// on this screen a figure nobody believed.
$GLOBALS['dze_opts'] = [];
$GLOBALS['dze_meta'] = [];
$dze_gal = static function ( int $pid, int $photos, string $price = '' ): void {
	$GLOBALS['dze_meta'][ $pid ]['_product_image_gallery'] = $photos ? implode( ',', range( 1, $photos ) ) : '';
	if ( '' !== $price ) { $GLOBALS['dze_meta'][ $pid ]['_price'] = $price; }
};
$dze_band = [
	'id' => 'prod_gallery', 'label' => 'x', 'scope' => 'product', 'field' => 'product.gallery',
	'test' => 'lt', 'value' => 6, 'key' => '', 'find' => '', 'on' => 1, 'cond' => 1,
	'bands' => [
		[ 'field' => 'product.price', 'from' => 0,  'to' => 40, 'want' => 3 ],
		[ 'field' => 'product.price', 'from' => 40, 'to' => 80, 'want' => 4 ],
		[ 'field' => 'product.price', 'from' => 80, 'to' => 0,  'want' => 6 ],
	],
];
$dze_gal( 301, 3, '16.90' );
$dze_gal( 302, 3, '55.00' );
$dze_gal( 303, 3, '90.00' );
$dze_gal( 304, 6, '90.00' );
$dze_gal( 305, 4, '55.00' );
ok( 'three photographs is enough at 16.90',  judge( $dze_band, 301 ), false );
ok( 'but not at 55.00',                      judge( $dze_band, 302 ), true );
ok( 'and nowhere near enough at 90.00',      judge( $dze_band, 303 ), true );
ok( 'six is enough at 90.00',                judge( $dze_band, 304 ), false );
ok( 'and four is enough at 55.00',           judge( $dze_band, 305 ), false );
// Half open, always. 40 belongs to "40 and 80" and to nothing else — two
// ranges meeting at a number is the one place a reader would have to guess.
$dze_gal( 306, 3, '40.00' );
ok( 'a price on the boundary goes up',       judge( $dze_band, 306 ), true );
$dze_gal( 307, 3, '39.99' );
ok( 'and just under it stays down',          judge( $dze_band, 307 ), false );
// THE CONDITIONS ARE THE RULE. A product no condition covers is not judged at
// all — nothing was asked of it, so it cannot fall short. A product with no
// price is placed by nothing, and it is never dropped into the first range,
// which starts at 0 and would quietly excuse the products most likely to be
// broken.
$dze_gal( 308, 3 );
ok( 'no price, no condition, not judged',    judge( $dze_band, 308 ), false );
// Conditions can be written on different fields in the same list.
$dze_mixed = $dze_band;
$dze_mixed['bands'] = [
	[ 'field' => 'product.stock', 'from' => 100, 'to' => 0, 'want' => 1 ],
	[ 'field' => 'product.price', 'from' => 0,   'to' => 40, 'want' => 3 ],
];
$GLOBALS['dze_meta'][309]['_product_image_gallery'] = '1,2';
$GLOBALS['dze_meta'][309]['_price'] = '16.90';
$GLOBALS['dze_meta'][309]['_stock'] = '500';
ok( 'the first condition that fits wins, whatever it measures',
	judge( $dze_mixed, 309 ), false );
// And a criterion with NO conditions behaves exactly as it always did.
$dze_flat = $dze_band;
$dze_flat['bands'] = [];
$dze_flat['cond']  = 0;
$dze_flat['value'] = 3;
ok( 'no conditions, no change',              judge( $dze_flat, 303 ), false );
// THE SWITCH. Conditional is off unless the shop asked for it: a criterion is
// one rule and one figure until somebody ticks the box, and the conditions it
// keeps are not applied while it is unticked.
$dze_off = $dze_band;
$dze_off['cond'] = 0;
ok( 'unticked, the conditions do not apply', judge( $dze_off, 301 ), true );
ok( 'and every product takes the one figure', judge( $dze_off, 304 ), false );

echo "The conditions survive being saved, and refuse what they cannot judge\n";
$dze_saved = DZE_Diagnostic::clean_rows( [ $dze_band ] )[0];
ok( 'every condition is kept',          count( $dze_saved['bands'] ), 3 );
ok( 'each with the field it names',     $dze_saved['bands'][1]['field'], 'product.price' );
ok( 'and its own range',                [ $dze_saved['bands'][1]['from'], $dze_saved['bands'][1]['to'] ], [ 40, 80 ] );
// A range is measured on a NUMBER. A description answers words, not "between
// 40 and 80", and a field of another post type cannot be asked of a product.
$dze_bad = $dze_band;
$dze_bad['bands'] = [
	[ 'field' => 'product.description', 'from' => 0, 'to' => 40, 'want' => 3 ],
	[ 'field' => 'category.products',   'from' => 0, 'to' => 40, 'want' => 3 ],
	[ 'field' => 'product.price',       'from' => 0, 'to' => 40, 'want' => 3 ],
];
$dze_bad['cond'] = 1;
ok( 'a text and another type are dropped', count( DZE_Diagnostic::clean_rows( [ $dze_bad ] )[0]['bands'] ), 1 );
// A range typed the wrong way round is a slip. Kept as "and above" rather
// than thrown away, because losing the line would hide the mistake.
$dze_bad['bands'] = [ [ 'field' => 'product.price', 'from' => 80, 'to' => 40, 'want' => 6 ] ];
ok( 'a backwards range becomes open-ended', DZE_Diagnostic::clean_rows( [ $dze_bad ] )[0]['bands'][0]['to'], 0 );
// STANDARDISED: wherever the rule holds a figure, not wherever the field is a
// number. "Description is less than 120 words" is the same question as
// "gallery is less than 3 photographs", and the first version gave conditions
// to one and denied them to the other.
$dze_words = [
	'id' => 'thin_desc', 'label' => 'x', 'scope' => 'product', 'field' => 'product.description',
	'test' => 'lt', 'value' => 120, 'key' => '', 'find' => '', 'on' => 1, 'cond' => 1,
	'bands' => [ [ 'field' => 'product.price', 'from' => 80, 'to' => 0, 'want' => 300 ] ],
];
$GLOBALS['dze_posts'][310] = new WP_Post();
$GLOBALS['dze_posts'][310]->ID = 310;
$GLOBALS['dze_posts'][310]->post_content = str_repeat( 'word ', 200 );
$GLOBALS['dze_meta'][310]['_price'] = '90.00';
ok( 'a word count takes conditions too', judge( $dze_words, 310 ), true );
$GLOBALS['dze_meta'][310]['_price'] = '20.00';
ok( 'and a cheap product keeps the plain figure', judge( $dze_words, 310 ), false );
$dze_c = new ReflectionMethod( 'DZE_Diagnostic', 'card' );
$dze_c->setAccessible( true );
// SHOWN, not merely present: the block is always in the markup and hidden by
// style, so a check for the class alone passes on the very bug it is about.
ok( 'and the card offers them',
	false !== strpos( (string) $dze_c->invoke( null, $dze_words, '0' ), 'dze-diag-cond" style="margin:6px 0 0;">' ), true );
// A rule with NO figure has nothing to put in tiers.
$dze_empty = $dze_words;
$dze_empty['test'] = 'empty';
ok( 'a rule with no figure is shut',
	false !== strpos( (string) $dze_c->invoke( null, $dze_empty, '0' ), 'dze-diag-cond" style="margin:6px 0 0;display:none;' ), true );

// The switch is stored, and survives a form that did not carry it.
ok( 'the switch is kept',               $dze_saved['cond'], 1 );
$dze_card = new ReflectionMethod( 'DZE_Diagnostic', 'card' );
$dze_card->setAccessible( true );
$dze_html = (string) $dze_card->invoke( null, $dze_band, '0' );
ok( 'the card offers one switch',       substr_count( $dze_html, 'dze-diag-condon' ), 1 );
ok( 'ticked when the shop asked for it', false !== strpos( $dze_html, "dze-diag-condon\" value=\"1\" name=\"dze_diagnostic[rows][0][cond]\" checked" ), true );
// Unticked, the conditions are HIDDEN and not applied — but still there, so
// turning it back on does not mean typing them again.
$dze_off2 = $dze_band;
$dze_off2['cond'] = 0;
$dze_html = (string) $dze_card->invoke( null, $dze_off2, '0' );
ok( 'and shut by default',              false !== strpos( $dze_html, 'dze-diag-bands" style="margin:6px 0 0;display:none;' ), true );
ok( 'the conditions are still on the card', substr_count( $dze_html, '[bands][0][from]' ), 1 );
ok( 'and nothing says Fix any more',    false !== strpos( $dze_html, 'Fix it with' ), false );
// The trap this plugin has been bitten by twice: a form that never carried the
// conditions must not erase them.
update_option( DZE_Diagnostic::OPT, [ 'rows' => [ $dze_saved ] ] );
$dze_other = $dze_band;
unset( $dze_other['bands'], $dze_other['cond'] );
$dze_kept = DZE_Diagnostic::clean_rows( [ $dze_other ] )[0];
ok( 'a form without them changes nothing', count( $dze_kept['bands'] ), 3 );
ok( 'nor does it switch them off',      $dze_kept['cond'], 1 );
// A criterion held to conditions must not go on calling itself by ONE figure:
// "is less than 6 photographs" stopped being true the moment a $16.90 cap
// started being judged on 3.
ok( 'the name carries every figure',    $dze_saved['label'], 'Gallery photographs is less than 3/4/6 photographs' );
// A condition just added has no figure yet. It put "0/3" in the heading the
// moment it appeared, which reads as a criterion asking for nothing.
$dze_half = $dze_band;
$dze_half['value'] = 3;
$dze_half['bands'] = [ [ 'field' => 'product.price', 'from' => 0, 'to' => 0, 'want' => 0 ] ];
ok( 'a half-typed one is not a figure',
	DZE_Diagnostic::clean_rows( [ $dze_half ] )[0]['label'], 'Gallery photographs is less than 3 photographs' );
// And an unticked switch names none of them, however many are stored.
$dze_half = $dze_band;
$dze_half['cond'] = 0;
// With Conditional on there is no plain figure on the screen at all, so the
// name must not carry one: it would be naming something nothing is judged by.
ok( 'and the plain figure is off the card',
	false !== strpos( (string) $dze_c->invoke( null, $dze_band, '0' ), 'dze-diag-value" min="0" step="1" style="width:100px;display:none;' ), true );
ok( 'while a plain criterion still shows it',
	false !== strpos( (string) $dze_c->invoke( null, $dze_flat, '0' ), 'dze-diag-value" min="0" step="1" style="width:100px;"' ), true );
ok( 'unticked, the name is the one figure',
	DZE_Diagnostic::clean_rows( [ $dze_half ] )[0]['label'], 'Gallery photographs is less than 6 photographs' );
ok( 'and one figure stays one figure',
	DZE_Diagnostic::clean_rows( [ $dze_flat ] )[0]['label'], 'Gallery photographs is less than 3 photographs' );

// One name, everywhere it is shown. "Diagnostic" beside Restock reads as the
// shop's health — servers, keys, cron — and what this reads is the CONTENT.
$GLOBALS['dze_submenus'] = [];
DZE_Diagnostic::instance()->register_menu();
$dze_menu = (array) ( $GLOBALS['dze_submenus'][0] ?? [] );
ok( 'the left menu says what it looks at', (string) ( $dze_menu['title'] ?? '' ), 'Content diagnostic' );
ok( 'and it still points at the same page', (string) ( $dze_menu['slug'] ?? '' ), DZE_Diagnostic::MENU_SLUG );
$GLOBALS['dze_opts'] = [];
$GLOBALS['dze_meta'] = [];

echo "The problem list says WHICH condition put each product there\n";
// A product is on this list because ONE condition placed it. A list that does
// not say which is a list you have to work out, and the figure it was judged
// by is invisible.
$GLOBALS['dze_opts'] = [];
$GLOBALS['dze_meta'] = [];
$GLOBALS['dze_posts'] = [];
// Two in the cheapest band on purpose: with one product per band a heading
// printed on EVERY row still reads as one heading per band.
foreach ( [ [ 401, 'Cheap cap', '16.90', 1 ], [ 405, 'Other cap', '19.90', 1 ], [ 402, 'Mid rig', '55.00', 1 ], [ 403, 'Plate carrier', '90.00', 1 ], [ 404, 'No price', '', 1 ] ] as $dze_p ) {
	[ $pid, $title, $price, $photos ] = $dze_p;
	$post = new WP_Post();
	$post->ID = $pid;
	$post->post_title = $title;
	$GLOBALS['dze_posts'][ $pid ] = $post;
	$GLOBALS['dze_meta'][ $pid ]['_product_image_gallery'] = implode( ',', range( 1, $photos ) );
	if ( '' !== $price ) { $GLOBALS['dze_meta'][ $pid ]['_price'] = $price; }
}
$dze_rows = DZE_Diagnostic::rows();
foreach ( $dze_rows as $i => $r ) {
	if ( 'prod_gallery' === ( $r['id'] ?? '' ) ) {
		$dze_rows[ $i ]['cond']  = 1;
		$dze_rows[ $i ]['bands'] = [
			[ 'field' => 'product.price', 'from' => 0,  'to' => 40, 'want' => 3 ],
			[ 'field' => 'product.price', 'from' => 40, 'to' => 80, 'want' => 4 ],
			[ 'field' => 'product.price', 'from' => 80, 'to' => 0,  'want' => 6 ],
		];
	}
}
update_option( DZE_Diagnostic::OPT, [ 'rows' => $dze_rows ] );
// Stored in the WRONG order on purpose: sorted "as found" this list already
// came out by condition, so the sort was proving nothing.
update_option( DZE_Diagnostic::list_option( 'prod_gallery' ), [ 403, 402, 405, 404, 401 ], false );
update_option( DZE_Diagnostic::OPT_CENSUS, [ 'checks' => [ 'prod_gallery' => 5 ], 'read' => time() ] );
$GLOBALS['umeta'] = [];
$dze_page = $show( [ 'by' => 'found' ] );
// A COLUMN, sortable, not banners cut into the table: banners cannot be
// sorted, they break the striping, and a list of nine hundred reads as a
// stack of little tables.
ok( 'there is a column for it',         false !== strpos( $dze_page, '>Condition' ), true );
ok( 'and it can be sorted by',          false !== strpos( $dze_page, 'by=band' ), true );
ok( 'the cheapest condition is named on its row',
	false !== strpos( $dze_page, 'price 0 to 40 → at least 3' ), true );
ok( 'and the middle one on its own',    false !== strpos( $dze_page, 'price 40 to 80 → at least 4' ), true );
ok( 'and the open-ended one says so',   false !== strpos( $dze_page, 'price 80 and above → at least 6' ), true );
// A product no condition covers is not judged, so it is not on the list at all.
ok( 'nothing uncovered is listed',      false !== strpos( $dze_page, 'No price' ), false );
// One cell per product, so two products in one band say it twice.
ok( 'each product carries its own',     substr_count( $dze_page, 'price 0 to 40' ), 2 );
ok( 'and both are listed',              substr_count( $dze_page, 'cap</strong>' ), 2 );
// Sorting by it puts them in the order the conditions are written.
$GLOBALS['umeta'] = [];
ok( 'sorted by condition, cheapest first',
	$order( $show( [ 'by' => 'band', 'dir' => 'asc' ] ) ),
	// Within one condition the found order is kept: sorting by condition is
	// not sorting by anything else.
	[ 'Other cap', 'Cheap cap', 'Mid rig', 'Plate carrier' ] );
// And a criterion with no conditions has no sections at all.
foreach ( $dze_rows as $i => $r ) {
	if ( 'prod_gallery' === ( $r['id'] ?? '' ) ) { $dze_rows[ $i ]['cond'] = 0; }
}
update_option( DZE_Diagnostic::OPT, [ 'rows' => $dze_rows ] );
$GLOBALS['umeta'] = [];
ok( 'a plain criterion has no such column',
	false !== strpos( $show( [ 'by' => 'found' ] ), '>Condition' ), false );

// THE TOOL, ON THE LINE THAT NEEDS IT. Not a repair — a link to the screen
// that does this kind of work, so the walk back through three menus goes.
$dze_rows2 = DZE_Diagnostic::rows();
foreach ( $dze_rows2 as $i => $r ) {
	if ( 'prod_desc' === ( $r['id'] ?? '' ) ) { $dze_rows2[ $i ]['on'] = 1; }
}
update_option( DZE_Diagnostic::OPT, [ 'rows' => $dze_rows2 ] );
update_option( DZE_Diagnostic::OPT_LISTS, [ 'prod_desc' => [ 401 ] ] );
update_option( DZE_Diagnostic::OPT_CENSUS, [ 'checks' => [ 'prod_desc' => 1 ], 'read' => time() ] );
$GLOBALS['umeta'] = [];
$dze_render = new ReflectionMethod( 'DZE_Diagnostic', 'render_list' );
$dze_render->setAccessible( true );
$_GET = [];
ob_start();
$dze_render->invoke( DZE_Diagnostic::instance(), 'prod_desc' );
$dze_desc = (string) ob_get_clean();
ok( 'a description row opens its tool',  substr_count( $dze_desc, '>Open</a>' ), 1 );
ok( 'pointing at bulk writing',          false !== strpos( $dze_desc, 'dazont-content-bulk' ), true );
// A photograph criterion has no screen of its own, so it offers no button —
// the product's own link is already the whole of the answer.
$GLOBALS['umeta'] = [];
ok( 'a gallery row offers none',         false !== strpos( $show( [ 'by' => 'found' ] ), '>Open</a>' ), false );
$GLOBALS['dze_opts'] = [];
$GLOBALS['dze_meta'] = [];

echo "A count is an answer to the rule AS IT WAS READ\n";
// "Variations without an image is not empty > 2100+ produits. Is empty >
// donne toujours tous ces produits. Je ne comprends pas." Both, because the
// COUNT came from the reading — taken when the rule said something else —
// while the rows below were re-judged live against the rule as it stands.
// Two answers to two different questions on one screen, and nothing said so.
$GLOBALS['dze_opts'] = [];
$GLOBALS['dze_posts'] = [];
$dze_asked = [ 'id' => 'v', 'scope' => 'product', 'field' => 'product.variation_images',
	'test' => 'empty', 'value' => 0, 'find' => '', 'key' => '', 'on' => 1, 'cond' => 0, 'bands' => [] ];
update_option( DZE_Diagnostic::OPT_CENSUS, [
	'checks' => [ 'v' => 2106 ],
	'rules'  => [ 'v' => DZE_Diagnostic::rule_stamp( $dze_asked ) ],
	'read'   => time(),
] );
ok( 'the rule that was read is not stale', DZE_Diagnostic::rule_moved( 'v', $dze_asked ), false );
// Flip the comparison and the stored count is about the other question.
$dze_flipped = $dze_asked;
$dze_flipped['test'] = 'filled';
ok( 'flipping it makes the count stale',   DZE_Diagnostic::rule_moved( 'v', $dze_flipped ), true );
// So does a figure, a key, or a condition.
$dze_other = $dze_asked; $dze_other['value'] = 3;
ok( 'so does a different figure',          DZE_Diagnostic::rule_moved( 'v', $dze_other ), true );
$dze_other = $dze_asked; $dze_other['key'] = 'attribute_pa_couleur';
ok( 'and a different field key',           DZE_Diagnostic::rule_moved( 'v', $dze_other ), true );
$dze_other = $dze_asked; $dze_other['cond'] = 1;
$dze_other['bands'] = [ [ 'field' => 'product.price', 'from' => 0, 'to' => 40, 'want' => 2 ] ];
ok( 'and switching the conditions on',     DZE_Diagnostic::rule_moved( 'v', $dze_other ), true );
// But NOT renaming it or changing what it says to do about it: those decide
// nothing, and crying stale over them would train the shop to ignore it.
$dze_other = $dze_asked; $dze_other['note'] = 'Do this instead'; $dze_other['goals'] = [ 'seo' ];
ok( 'a note is not a question',            DZE_Diagnostic::rule_moved( 'v', $dze_other ), false );
// A reading taken before criteria carried a fingerprint says nothing rather
// than crying stale on every line the first time a shop updates.
update_option( DZE_Diagnostic::OPT_CENSUS, [ 'checks' => [ 'v' => 2106 ], 'read' => time() ] );
ok( 'an older reading is silent',          DZE_Diagnostic::rule_moved( 'v', $dze_flipped ), false );
$GLOBALS['dze_opts'] = [];

echo "A mended product says WHAT was done to it\n";
// "Apres un fix, le produit disparait des diagnostics." It should — it is not
// a problem any more. What must not disappear is the RECORD: what was
// accepted onto it, when, and a way back to it. Read from the queue, which is
// the only place that knows, never from a second store that could disagree.
$GLOBALS['dze_opts'] = [];
$GLOBALS['dze_posts'] = [];
$GLOBALS['dze_meta'] = [];
foreach ( [ 501, 502 ] as $dze_i ) {
	$post = new WP_Post();
	$post->ID = $dze_i;
	$post->post_title = 'Mended ' . $dze_i;
	$GLOBALS['dze_posts'][ $dze_i ] = $post;
	// Four photographs: they are no longer short of three.
	$GLOBALS['dze_meta'][ $dze_i ]['_product_image_gallery'] = '1,2,3,4';
}
$GLOBALS['done'] = [ 501 => [ 'kind' => 'product_shot', 'when' => '2026-09-03 10:00:00', 'id' => 77 ] ];
$dze_rows4 = DZE_Diagnostic::rows();
update_option( DZE_Diagnostic::OPT, [ 'rows' => $dze_rows4 ] );
update_option( DZE_Diagnostic::list_option( 'prod_gallery' ), [ 501, 502 ], false );
update_option( DZE_Diagnostic::OPT_CENSUS, [ 'checks' => [ 'prod_gallery' => 2 ], 'read' => time() ] );
$GLOBALS['umeta'] = [];
$dze_fixed = $show( [ 'show' => 'fixed' ] );
ok( 'the Fixed tab has a column for it',
	false !== strpos( $dze_fixed, 'What was done' ), true );
ok( 'and names the job that did it',    false !== strpos( $dze_fixed, 'Product photograph' ), true );
ok( 'with a way back to it',            false !== strpos( $dze_fixed, '>Review</a>' ), true );
// A product mended BY HAND has no row, and says so rather than claiming
// something was run on it.
ok( 'a hand edit says it was a hand edit',
	false !== strpos( $dze_fixed, 'Edited by hand' ), true );
// And the column belongs to that tab only: on the problem list the last
// column is the tool to open, not a history.
$GLOBALS['umeta'] = [];
ok( 'the problem list has no such column',
	false !== strpos( $show( [ 'show' => 'todo' ] ), 'What was done' ), false );
$GLOBALS['done'] = [];
$GLOBALS['dze_opts'] = [];
$GLOBALS['dze_meta'] = [];
$GLOBALS['dze_posts'] = [];

echo "The pagination is WordPress's own, in WordPress's own wrapper\n";
// "La pagination des listes diagnostique est horrible. Inutilisable." The
// links WERE WordPress's — printed bare in a paragraph, where the admin
// stylesheet does not reach them: outside .tablenav-pages they come out as a
// run of naked numbers.
$GLOBALS['dze_opts'] = [];
$GLOBALS['dze_posts'] = [];
$dze_ids = [];
for ( $i = 601; $i <= 720; $i++ ) {
	$post = new WP_Post();
	$post->ID = $i;
	$post->post_title = 'Product ' . $i;
	$GLOBALS['dze_posts'][ $i ] = $post;
	$GLOBALS['dze_meta'][ $i ]['_product_image_gallery'] = '1';
	$dze_ids[] = $i;
}
$dze_rows3 = DZE_Diagnostic::rows();
update_option( DZE_Diagnostic::OPT, [ 'rows' => $dze_rows3 ] );
update_option( DZE_Diagnostic::list_option( 'prod_gallery' ), $dze_ids, false );
update_option( DZE_Diagnostic::OPT_CENSUS, [ 'checks' => [ 'prod_gallery' => count( $dze_ids ) ], 'read' => time() ] );
$GLOBALS['umeta'] = [];
$dze_paged = $show( [ 'by' => 'found' ] );
ok( 'the pager is in the admin wrapper',
	false !== strpos( $dze_paged, 'class="tablenav-pages"' ), true );
ok( 'and says how many there are',      false !== strpos( $dze_paged, '120 items' ), true );
ok( 'with the links inside it',         false !== strpos( $dze_paged, 'pagination-links' ), true );
// One page of fifty, and no pager at all when everything fits.
update_option( DZE_Diagnostic::list_option( 'prod_gallery' ), array_slice( $dze_ids, 0, 10 ), false );
update_option( DZE_Diagnostic::OPT_CENSUS, [ 'checks' => [ 'prod_gallery' => 10 ], 'read' => time() ] );
$GLOBALS['umeta'] = [];
ok( 'nothing to page through, no pager',
	false !== strpos( $show( [ 'by' => 'found' ] ), 'tablenav-pages' ), false );
$GLOBALS['dze_opts'] = [];
$GLOBALS['dze_meta'] = [];
$GLOBALS['dze_posts'] = [];

echo "Each criterion's list is its own row, and big enough to hold the answer\n";
// "Si 2106 produits sont problematiques alors pourquoi seulement 1000 dans la
// liste ?" Because every criterion's list sat in ONE option, read in full to
// draw fifty rows of one of them, so the cap had to be small. One option per
// criterion, read when that list is opened, and the cap is what a shop needs.
$GLOBALS['dze_opts'] = [];
$dze_many = range( 1, 3000 );
update_option( DZE_Diagnostic::list_option( 'prod_gallery' ), $dze_many, false );
ok( 'a list of three thousand is kept whole',
	count( DZE_Diagnostic::list_of( 'prod_gallery' ) ), 3000 );
ok( 'and it is its own option',
	DZE_Diagnostic::list_option( 'prod_gallery' ), 'dze_diagnostic_lists_prod_gallery' );
ok( 'one criterion cannot read another\'s',
	DZE_Diagnostic::list_of( 'prod_desc' ), [] );
// A shop whose last reading predates the split still has its lists in the old
// option: the screen must not go empty because the storage moved.
$GLOBALS['dze_opts'] = [];
update_option( DZE_Diagnostic::OPT_LISTS, [ 'prod_gallery' => [ 7, 8, 9 ] ] );
ok( 'the old single option is still read',
	DZE_Diagnostic::list_of( 'prod_gallery' ), [ 7, 8, 9 ] );
// And the new one wins the moment it exists.
update_option( DZE_Diagnostic::list_option( 'prod_gallery' ), [ 11 ], false );
ok( 'and the new row wins once written',
	DZE_Diagnostic::list_of( 'prod_gallery' ), [ 11 ] );
// Declared, or it cannot be erased. A module missing from that map is flagged
// as undeclared in Settings -> Modules.
require_once __DIR__ . '/../' . $dir . '/includes/class-cleanup.php';
$dze_map = (array) ( DZE_Cleanup::map()['diagnostic']['options'] ?? [] );
ok( 'the option prefix is declared',    in_array( 'dze_diagnostic_lists_', $dze_map, true ), true );
// And the single option that held them all before the split, so a shop
// updating from an older version is erased whole.
ok( 'and the old single option too',    in_array( 'dze_diagnostic_lists', $dze_map, true ), true );
$GLOBALS['dze_opts'] = [];

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
