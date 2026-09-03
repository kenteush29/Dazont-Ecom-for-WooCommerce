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
/** What each product brought in, already in the shop's own currency. */
class DZE_Sales {
	const MONTHS = 24;
	public static function revenue( $ids = [] ) {
		$rev = [];
		foreach ( (array) ( $GLOBALS['dze_rev'] ?? [] ) as $pid => $amount ) {
			if ( ! $ids || in_array( (int) $pid, array_map( 'intval', $ids ), true ) ) { $rev[ (int) $pid ] = (float) $amount; }
		}
		return [
			'rev'     => $rev,
			'qty'     => [],
			'missing' => (array) ( $GLOBALS['dze_missing'] ?? [] ),
			'by'      => (array) ( $GLOBALS['dze_by'] ?? [] ),
			'orphans' => (array) ( $GLOBALS['dze_orphans'] ?? [ 'lines' => 0, 'raw' => 0.0 ] ),
		];
	}
}
class DZE_Money {
	public static function base() { return 'USD'; }
	public static function say( $n ) { return '$' . number_format( (float) $n, 2 ); }
}

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
$GLOBALS['dze_rev']        = [];
$GLOBALS['dze_missing']    = [];
$GLOBALS['dze_orphans']    = [ 'lines' => 0, 'raw' => 0.0 ];
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
// One mark per column, always: four pale pairs on the ones that can be sorted
// and one solid arrow on the one in use. A screen sorted by nothing in
// particular used to carry no mark at all.
ok( 'every column carries a mark',
	substr_count( $hdr, 'Sort by this column' ), 5 );
ok( 'the one in use points down',       substr_count( $hdr, '&#9660;' ), 1 );
ok( 'and is the only one in bold',      substr_count( $hdr, 'font-weight:700;' ), 1 );
ok( 'the others say they can be sorted', substr_count( $hdr, '&#8645;' ), 4 );
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

// What each one BROUGHT IN, in the shop's own currency — the column the shop
// actually decides on: a product sold 250 times at 9.90 is not the product
// that brought the most in.
$GLOBALS['dze_rev'] = [ 101 => 99.50, 102 => 2475.00, 103 => 3960.00 ];
ok( 'sorted by what they brought in',   $order( $show( [ 'by' => 'rev', 'dir' => 'desc' ] ) ), [ 'Zulu pouch', 'Ancient cap', 'Balaclava' ] );
$html = $show( [ 'by' => 'rev', 'dir' => 'desc' ] );
ok( 'and the amount is on the row',     false !== strpos( $html, '3,960.00' ), true );
ok( 'in the shop\'s own currency',      false !== strpos( $html, '$3,960.00' ), true );
// A currency with no rate is LEFT OUT and said so: a short total that looks
// whole is a figure the shop sorts by and believes.
$GLOBALS['dze_missing'] = [ 'PLN', 'SEK' ];
$html = $show( [ 'by' => 'rev', 'dir' => 'desc' ] );
ok( 'what could not be converted is said', false !== strpos( $html, 'Orders paid in PLN, SEK are left out' ), true );
$GLOBALS['dze_missing'] = [];
ok( 'and nothing is said when all is known',
	false !== strpos( $show( [ 'by' => 'rev' ] ), 'are left out' ), false );

// Rows in WooCommerce's own sales table whose order no longer exists. This
// shop had 751 of them; the 67 inside the window carried 84% of everything
// Revenue reported. They are not counted and not hidden — the screen names
// them, the same treatment a currency with no rate already gets.
$GLOBALS['dze_orphans'] = [ 'lines' => 67, 'raw' => 1487416.92 ];
$html = $show( [ 'by' => 'rev', 'dir' => 'desc' ] );
ok( 'order lines with no order are named',
	false !== strpos( $html, '67 order lines are left out of Revenue' ), true );
ok( 'with the amount they carry',       false !== strpos( $html, '1,487,416.92' ), true );
ok( 'and they go with the figures too',
	false !== strpos( DZE_Diagnostic::figures_text( [], 'x', $GLOBALS['dze_orphans'] ), 'No order behind them — 67 lines' ), true );
$GLOBALS['dze_orphans'] = [ 'lines' => 0, 'raw' => 0.0 ];
ok( 'a clean shop is told nothing',
	false !== strpos( $show( [ 'by' => 'rev' ] ), 'no order behind' ), false );

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

echo "A criterion that can be MENDED says so, and by which pass\n";
// "Besoin d'etre en capacite d'utiliser les fonctionnalites dazont ecom a
// partir de la." A to-do list that only lists is a list read twice. A
// criterion now names the pass that repairs it — read from the FIELD, like
// its tool link, so a criterion the shop invents tomorrow arrives with its
// repair already attached and nothing is hard-wired to a criterion id.
$GLOBALS['tpls'] = [
	[ 'id' => 'main1',  'name' => 'Main image',     'target' => 'main' ],
	[ 'id' => 'sc',     'name' => 'Scene (in use)', 'target' => 'gallery' ],
	[ 'id' => 'angle1', 'name' => 'Another angle',  'target' => 'gallery' ],
];
$fix = new ReflectionMethod( 'DZE_Diagnostic', 'fix_for' );
$fix->setAccessible( true );
/** A criterion as the shop wrote it: the field, and its own routine. */
$rule = static fn( string $field, array $shots = [] ): array => [ 'field' => $field, 'shots' => $shots ];
// The shop's OWN prompts, by their ids, and its own numbers. 4.285 named a
// prompt in this file and the button answered with an error about a name
// nobody recognised; nothing here names a prompt.
$got = $fix->invoke( null, $rule( 'product.gallery', [ 'sc' => 2, 'angle1' => 3 ] ) );
ok( 'a thin gallery is mended by photographs', $got['kind'] ?? '', 'product_shot' );
// No label of its own: every repair is called Fix, on every screen. A
// different sentence per problem is a screen to be read rather than used.
ok( 'and carries no wording of its own', array_key_exists( 'label', $got ), false );
// Kept by the prompt's PLACE for shoot(), resolved from its id, because a
// place moves the day a prompt is added above it.
ok( 'each prompt with its own number',  $got['shots'] ?? [], [ 1 => 2, 2 => 3 ] );
ok( 'and it says so in the shop\'s words',
	DZE_Diagnostic::shots_said( $got['shots'] ), 'Scene (in use) ×2 + Another angle ×3' );
ok( 'five photographs a product',       DZE_Diagnostic::shots_each( $got['shots'] ), 5 );
// A prompt the shop has since deleted is dropped rather than silently
// becoming whichever prompt now sits in its place.
ok( 'a prompt that is gone is dropped',
	$fix->invoke( null, $rule( 'product.gallery', [ 'sc' => 2, 'deleted' => 3 ] ) )['shots'], [ 1 => 2 ] );
// A criterion nobody has answered for asks for nothing, and gets no button.
ok( 'nothing asked for is nothing run',  $fix->invoke( null, $rule( 'product.gallery' ) )['shots'], [] );
// Everything the plugin cannot mend by itself offers nothing rather than a
// button that would do the wrong thing.
ok( 'a price is not mended by a model',  $fix->invoke( null, $rule( 'product.price', [ 'sc' => 2 ] ) ), [] );
ok( 'nor is a stock level',              $fix->invoke( null, $rule( 'product.stock' ) ), [] );
ok( 'nor the number of reviews',         $fix->invoke( null, $rule( 'product.reviews' ) ), [] );

echo "One press sends the shortfall off, and commits the shop to nothing\n";
$GLOBALS['tpls'] = [
	[ 'id' => 'main1',  'name' => 'Main image',    'target' => 'main' ],
	[ 'id' => 'sc',     'name' => 'Scene (in use)', 'target' => 'gallery' ],
	[ 'id' => 'angle1', 'name' => 'Another angle', 'target' => 'gallery' ],
];
/** The press, and what it answered. */
$press = static function ( string $id ): array {
	$_POST = [ 'check' => $id, 'nonce' => 'n' ];
	$GLOBALS['queued'] = [];
	try { DZE_Diagnostic::ajax_fix(); } catch ( DZE_Json_Sent $e ) { return [ (array) $e->payload, $e->ok ]; }
	return [ [], false ];
};
[ $said, $ok ] = $press( 'nope' );
ok( 'an unknown criterion mends nothing', $ok, false );

// A real press, on a real shortfall.
$GLOBALS['tpls'] = [
	[ 'id' => 'main1',  'name' => 'Main image',    'target' => 'main' ],
	[ 'id' => 'angle1', 'name' => 'Another angle', 'target' => 'gallery' ],
];
// The shop's routine, on the criterion: two of one prompt and one of another,
// which is what "detail shots plus a UGC one" looks like once written down.
$GLOBALS['dze_opts']['dze_diagnostic'] = [ 'rows' => [
	[ 'id' => 'thin', 'on' => 1, 'label' => 'Gallery under three', 'scope' => 'product',
	  'field' => 'product.gallery', 'test' => 'lt', 'value' => 3,
	  'shots' => [ 'angle1' => 2 ] ],
] ];
$GLOBALS['dze_opts']['dze_diagnostic_lists'] = [ 'thin' => [ 101, 102, 103 ] ];
[ $said, $ok ] = $press( 'thin' );
ok( 'it goes off',                       $ok, true );
$job = $GLOBALS['queued'][0] ?? [];
ok( 'as a photograph job',               $job['kind'] ?? '', 'product_shot' );
ok( 'for the products that are short',   $job['ids'] ?? [], [ 101, 102, 103 ] );
// The line the whole design rests on. It must never be true.
ok( 'and NEVER applied on its own',      $job['auto'] ?? true, false );
ok( 'with the shop\'s own prompt',        $job['payload']['template'] ?? -1, 1 );
// One job per photograph, so five on one product are five rows to look at and
// four can be thrown away without losing the fifth.
ok( 'one pass per photograph asked for', count( $GLOBALS['queued'] ), 2 );
ok( 'and the second asks for another framing',
	$GLOBALS['queued'][1]['payload']['attempt'] ?? -1, 1 );
ok( 'the answer says how many went',     $said['added'] ?? 0, 6 );
ok( 'and says nothing reaches a product until you accept',
	false !== strpos( (string) ( $said['message'] ?? '' ), 'until you accept' ), true );
ok( 'and links to where they wait',      $said['url'] ?? '', 'http://shop.test/wp-admin/queue' );

// A criterion nobody has written a routine for cannot be sent off, and is told
// where to write one rather than left with a button that fails.
$GLOBALS['dze_opts']['dze_diagnostic']['rows'][0]['shots'] = [];
[ $said, $ok ] = $press( 'thin' );
ok( 'no photographs asked for stops it', $ok, false );
ok( 'and says where to ask for them',
	false !== strpos( (string) ( $said['message'] ?? '' ), 'Settings → Diagnostic' ), true );
ok( 'having queued nothing',             $GLOBALS['queued'], [] );

// The queue switched off is not a silent failure either.
$GLOBALS['dze_opts']['dze_diagnostic']['rows'][0]['shots'] = [ 'angle1' => 2 ];
$GLOBALS['module_off']['queue'] = 1;
[ $said, $ok ] = $press( 'thin' );
ok( 'no queue, no pass',                 $ok, false );
ok( 'and it says where to switch it on',
	false !== strpos( (string) ( $said['message'] ?? '' ), 'Modules' ), true );
$GLOBALS['module_off'] = [];

echo "The last view of a list is the one it opens on\n";
// "La methode de tri n'est pas bonne il faudrait que ca reste sauvegarde, la
// derniere vue." Nine hundred products sorted by revenue, left, and come back
// to: it opened again on the order nobody chose, so the work was re-sorted by
// hand every time.
$GLOBALS['umeta'] = [];
ok( 'a list nobody has sorted has no memory', DZE_Diagnostic::kept_view( 'thin' ), [] );
DZE_Diagnostic::keep_view( 'thin', [ 'by' => 'rev', 'dir' => 'asc', 'show' => 'fixed' ] );
ok( 'what was chosen is remembered',
	DZE_Diagnostic::kept_view( 'thin' ), [ 'by' => 'rev', 'dir' => 'asc', 'show' => 'fixed' ] );
// Per criterion: the galleries are worked through by revenue and the thin
// descriptions alphabetically, and one is not the other.
ok( 'and another list keeps its own',   DZE_Diagnostic::kept_view( 'other' ), [] );
// On the PERSON: two people working through the same shop do not sort it the
// same way, and one of them re-sorting is not a change to the shop.
$GLOBALS['uid'] = 2;
ok( 'somebody else starts clean',       DZE_Diagnostic::kept_view( 'thin' ), [] );
$GLOBALS['uid'] = 1;
ok( 'and the first one still has his',  DZE_Diagnostic::kept_view( 'thin' )['by'] ?? '', 'rev' );
// A write on every page load is a write for nothing.
$GLOBALS['umeta_writes'] = 0;
DZE_Diagnostic::keep_view( 'thin', [ 'by' => 'rev', 'dir' => 'asc', 'show' => 'fixed' ] );
ok( 'an unchanged view writes nothing',
	DZE_Diagnostic::kept_view( 'thin' ), [ 'by' => 'rev', 'dir' => 'asc', 'show' => 'fixed' ] );

echo "The list of problems mends them, from the list itself\n";
// "Sur l'ecran des problemes en particulier j'aimerais la possibilite de le
// regler directement. Ici y'a rien, juste une liste de produits." The button
// was on the summary — the screen you leave once you have decided to work.
$GLOBALS['umeta']   = [];
$GLOBALS['pending'] = [];
$GLOBALS['tpls']    = [ [ 'id' => 'angle1', 'name' => 'Another angle', 'target' => 'gallery' ] ];
// The shipped criteria, with a routine written on the one under test.
update_option( DZE_Diagnostic::OPT, [] );
$dze_rows = DZE_Diagnostic::rows();
foreach ( $dze_rows as $i => $r ) {
	if ( 'prod_gallery' === ( $r['id'] ?? '' ) ) { $dze_rows[ $i ]['shots'] = [ 'angle1' => 2 ]; }
}
update_option( DZE_Diagnostic::OPT, [ 'rows' => $dze_rows ] );
update_option( DZE_Diagnostic::OPT_LISTS, [ 'prod_gallery' => [ 101, 102, 103 ] ] );
update_option( DZE_Diagnostic::OPT_CENSUS, [ 'checks' => [ 'prod_gallery' => 3 ], 'read' => time() ] );
$page = $show( [] );
ok( 'the whole list can be sent off',   false !== strpos( $page, '>Fix (3)<' ), true );
ok( 'and each product on its own row',  substr_count( $page, 'data-check="prod_gallery" data-id=' ), 3 );
ok( 'each one carrying its own id',     false !== strpos( $page, 'data-id="101"' ), true );
ok( 'and it says nothing reaches a product first',
	false !== strpos( $page, 'until you accept it' ), true );
// A product already in the queue does not offer to be sent a second time: it
// says where it is.
$GLOBALS['pending'] = [ 101 => [ 'status' => 'review', 'id' => 9, 'kind' => 'product_shot' ] ];
$page = $show( [] );
ok( 'one already waiting says so',      false !== strpos( $page, 'Waiting for you' ), true );
ok( 'and is not offered again',         substr_count( $page, 'data-check="prod_gallery" data-id=' ), 2 );
$GLOBALS['pending'] = [ 102 => [ 'status' => 'queued', 'id' => 9, 'kind' => 'product_shot' ] ];
ok( 'one still being made says that instead',
	false !== strpos( $show( [] ), 'Being made' ), true );
// The queue switched off leaves the screen exactly as it was.
$GLOBALS['pending'] = [];
$GLOBALS['module_off']['queue'] = 1;
ok( 'no queue, no buttons',             false !== strpos( $show( [] ), 'data-check="prod_gallery"' ), false );
$GLOBALS['module_off'] = [];

// A criterion deleted while its list is open used to be a WHITE page: a fatal
// before any of our own error handling, carrying no message at all.
$_GET = [];
ob_start();
$render->invoke( DZE_Diagnostic::instance(), 'no_such_criterion' );
$gone = (string) ob_get_clean();
ok( 'a criterion that is gone says so', false !== strpos( $gone, 'That criterion is gone' ), true );
ok( 'and offers the way back',          false !== strpos( $gone, 'Back to the diagnostic' ), true );

echo "The buttons on the list have something behind them\n";
// "Mend this one > clique, 0 reaction." "Make the missing photographs (20) >
// idem, 0 reaction." The handlers were printed on the SUMMARY only, so every
// button on this screen made no request at all: no error, no message, nothing
// — the one failure that looks exactly like a broken plugin.
$GLOBALS['umeta']   = [];
$GLOBALS['pending'] = [];
$GLOBALS['module_off'] = [];
$page = $show( [] );
ok( 'the handler is on this screen',    false !== strpos( $page, "'dze_diag_fix'" ), true );
ok( 'and it is bound to the buttons',   false !== strpos( $page, ".dze-diag-fix'" ), true );
ok( 'with a nonce to send',             false !== strpos( $page, 'nonce:' ), true );

echo "The Revenue column can be taken apart\n";
// "Toujours des montants irreels." Until the shop can see WHAT was read and at
// WHAT rate, every conversation about this column is guesswork on both sides.
$GLOBALS['umeta'] = [];
$GLOBALS['dze_by'] = [
	'USD' => [ 'lines' => 12, 'raw' => 900.0, 'rate' => 1.0, 'base' => 900.0 ],
	'PLN' => [ 'lines' => 3,  'raw' => 400.0, 'rate' => 0.25, 'base' => 100.0 ],
];
$page = $show( [] );
ok( 'the workings are on the screen',   false !== strpos( $page, 'How Revenue was worked out' ), true );
ok( 'naming the currency read',         false !== strpos( $page, '<code>USD</code>' ), true );
ok( 'and where a wrong rate is corrected',
	false !== strpos( $page, 'corrected in your multi-currency plugin' ), true );
// The figure that makes a wrong total obvious: a catalogue selling at $15 to
// $77 cannot average $1,625 an order line, and no rate explains that.
ok( 'the average per order line is shown',
	false !== strpos( $page, 'Per line' ), true );
ok( 'and it is the read total over the lines',
	false !== strpos( $page, '133,33 PLN' ) || false !== strpos( $page, '133.33 PLN' ), true );
// One click, and the figures are text that can be handed over. Nobody can read
// this database from outside the shop.
ok( 'the figures can be copied',        false !== strpos( $page, 'Copy these figures' ), true );
ok( 'and they carry the per-line figure',
	false !== strpos( DZE_Diagnostic::figures_text( $GLOBALS['dze_by'], 'x' ), 'per line 133.33' ), true );
ok( 'with the shop\'s own currency named',
	false !== strpos( DZE_Diagnostic::figures_text( $GLOBALS['dze_by'], 'x' ), 'Shop currency: USD' ), true );
// The rate is printed as the shop's plugin publishes it, not rounded into
// something else: 0.25 is 0.25, and the shop's own currency has no rate.
ok( 'the rate used is printed',         false !== strpos( $page, '>0.25<' ), true );
ok( 'and the shop\'s own has none',      false !== strpos( $page, '>&mdash;<' ) || false !== strpos( $page, '>—<' ), true );
ok( 'each currency says how much was read',
	false !== strpos( $page, '400,00 PLN' ) || false !== strpos( $page, '400.00 PLN' ), true );

echo "The routine is written on the criterion, and survives being saved\n";
// "Typiquement ce que je fais sur un produit qui manque de photos : seance
// photo details produit + 1 a 2 photos type ugc." That is the shop's routine,
// and a routine belongs to the shop — so it is a field on the criterion, kept
// through a save like every other field.
$kept = DZE_Diagnostic::clean_rows( [ [
	'id' => 'thin', 'on' => 1, 'scope' => 'product', 'field' => 'product.gallery',
	'test' => 'lt', 'value' => 3, 'shots' => [ 'angle1' => '3', 'sc' => '2' ],
] ] );
ok( 'the routine is saved',             $kept[0]['shots'] ?? [], [ 'angle1' => 3, 'sc' => 2 ] );
// A number nobody would mean: five is the most, and a zero is not a prompt.
$kept = DZE_Diagnostic::clean_rows( [ [
	'id' => 'thin', 'scope' => 'product', 'field' => 'product.gallery', 'test' => 'lt', 'value' => 3,
	'shots' => [ 'angle1' => '99', 'sc' => '0', 'x' => '-4', '__none__' => '3' ],
] ] );
ok( 'five photographs is the most',     $kept[0]['shots'] ?? [], [ 'angle1' => 5 ] );
// The rule this plugin has paid for twice: a key the form did not carry is a
// key the shop KEEPS. The card always posts this section, so a row arriving
// without it was saved by another screen — and the shop's routine is not that
// screen's to erase.
$GLOBALS['dze_opts']['dze_diagnostic'] = [ 'rows' => [ [
	'id' => 'thin', 'on' => 1, 'scope' => 'product', 'field' => 'product.gallery',
	'test' => 'lt', 'value' => 3, 'shots' => [ 'angle1' => 4 ],
] ] ];
$kept = DZE_Diagnostic::clean_rows( [ [
	'id' => 'thin', 'scope' => 'product', 'field' => 'product.gallery', 'test' => 'lt', 'value' => 3,
] ] );
ok( 'a form without it keeps the routine', $kept[0]['shots'] ?? [], [ 'angle1' => 4 ] );
// And the card's own form, with every number cleared, really does mean none.
$kept = DZE_Diagnostic::clean_rows( [ [
	'id' => 'thin', 'scope' => 'product', 'field' => 'product.gallery', 'test' => 'lt', 'value' => 3,
	'shots' => [ '__none__' => '0', 'angle1' => '0' ],
] ] );
ok( 'and clearing them all means none',  $kept[0]['shots'] ?? [ 'x' ], [] );
// A criterion photographs cannot mend carries none, whatever is posted at it.
$kept = DZE_Diagnostic::clean_rows( [ [
	'id' => 'p', 'scope' => 'product', 'field' => 'product.price', 'test' => 'empty',
	'shots' => [ 'angle1' => 3 ],
] ] );
ok( 'a price criterion takes no photographs', $kept[0]['shots'] ?? [ 'x' ], [] );

// And the card offers the shop's own prompts, with a number each — so the
// routine is written where the criterion is, not guessed at in code.
$GLOBALS['tpls'] = [
	[ 'id' => 'main1',  'name' => 'Main image',     'target' => 'main' ],
	[ 'id' => 'sc',     'name' => 'Scene (in use)', 'target' => 'gallery' ],
	[ 'id' => 'angle1', 'name' => 'Another angle',  'target' => 'gallery' ],
];
$card = new ReflectionMethod( 'DZE_Diagnostic', 'card' );
$card->setAccessible( true );
$html = (string) $card->invoke( null, [
	'id' => 'thin', 'scope' => 'product', 'field' => 'product.gallery', 'test' => 'lt',
	'value' => 3, 'shots' => [ 'angle1' => 2 ],
], '0' );
ok( 'the card asks what fixes it',      false !== strpos( $html, 'Fix it with' ), true );
ok( 'offering every image prompt',      substr_count( $html, '[shots][' ), 4 );
ok( 'each with a number',               substr_count( $html, 'type="number" min="0" max="5"' ), 3 );
ok( 'and the shop\'s own answer in it',  false !== strpos( $html, '[shots][angle1]" value="2"' ), true );
ok( 'the prompts are named',            false !== strpos( $html, 'Scene (in use)' ), true );
ok( 'and zero everywhere is a real answer',
	false !== strpos( $html, '[shots][__none__]' ), true );
// A criterion photographs cannot mend does not ask.
ok( 'a price criterion asks nothing',
	false !== strpos( (string) $card->invoke( null, [ 'id' => 'p', 'scope' => 'product', 'field' => 'product.price', 'test' => 'empty' ], '0' ), 'Fix it with' ), false );

echo "A criterion this plugin can mend, that nobody has told how, SAYS so\n";
// "Je ne comprends pas il n'y a plus aucun bouton pour regler le probleme."
// 4.287 made the routine the shop's to write, and every criterion started
// empty — so a screen that had a Fix button the day before simply lost it,
// with nothing anywhere about a field that had appeared somewhere else. A
// screen that goes quiet is worse than one that never offered anything.
$GLOBALS['umeta'] = [];
$GLOBALS['pending'] = [];
$GLOBALS['module_off'] = [];
update_option( DZE_Diagnostic::OPT, [] ); // back to the shipped criteria
update_option( DZE_Diagnostic::OPT_LISTS, [ 'prod_gallery' => [ 101, 102, 103 ] ] );
update_option( DZE_Diagnostic::OPT_CENSUS, [ 'checks' => [ 'prod_gallery' => 3 ], 'read' => time() ] );
$dze_rows = DZE_Diagnostic::rows();
foreach ( $dze_rows as $i => $r ) {
	if ( 'prod_gallery' === ( $r['id'] ?? '' ) ) { $dze_rows[ $i ]['shots'] = []; }
}
update_option( DZE_Diagnostic::OPT, [ 'rows' => $dze_rows ] );
$page = $show( [] );
ok( 'it offers to be set up',           false !== strpos( $page, 'Set up Fix' ), true );
ok( 'saying what that means',           false !== strpos( $page, 'listed but not mended' ), true );
ok( 'and offers no Fix it cannot do',   false !== strpos( $page, '>Fix (' ), false );
// With a routine on it, the Fix button is back and the invitation is gone.
foreach ( $dze_rows as $i => $r ) {
	if ( 'prod_gallery' === ( $r['id'] ?? '' ) ) { $dze_rows[ $i ]['shots'] = [ 'angle1' => 2 ]; }
}
update_option( DZE_Diagnostic::OPT, [ 'rows' => $dze_rows ] );
$page = $show( [] );
ok( 'a criterion that is set up presses', false !== strpos( $page, '>Fix (3)<' ), true );
ok( 'and is not asked to be set up again', false !== strpos( $page, 'Set up Fix' ), false );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
