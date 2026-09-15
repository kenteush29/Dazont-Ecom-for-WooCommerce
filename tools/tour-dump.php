<?php
/**
 * Draws the pages no gate draws yet, for the tour.
 *
 *   php tools/tour-dump.php dazont-ecom <page>
 *   pages: dashboard shortcodes modules sourcing marketing rules gmc restock
 *
 * Not a gate: nothing here is asserted. A fake shop — a few categories, a
 * few products out of stock, one promotion, one Google connection — and each
 * page printed exactly as the plugin prints it, so it can be photographed and
 * read from the owner's chair. Every stub answers in the SHAPE the real
 * function answers with, so a page that dies here dies on the plugin.
 */
$dir  = $argv[1] ?? 'dazont-ecom';
$page = $argv[2] ?? 'dashboard';

define( 'ABSPATH', '/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MONTH_IN_SECONDS', 2592000 );
define( 'DZE_VERSION', '4.404.0' );
define( 'DZE_URL', 'http://kula.test/wp-content/plugins/dazont-ecom/' );
define( 'DZE_DIR', __DIR__ . '/../' . $dir . '/' );
define( 'DZE_FILE', DZE_DIR . 'dazont-ecom.php' );

// ---- WordPress, enough of it ----
function __( $s, $d = '' ) { return $s; }
function _x( $s, $c = '', $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return 1 === (int) $n ? $a : $b; }
function _e( $s, $d = '' ) { echo $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function esc_js( $s ) { return addslashes( (string) $s ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_kses_post( $s ) { return (string) $s; }
function wp_kses( $s, $a = [] ) { return (string) $s; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_title( $s ) { return sanitize_key( str_replace( ' ', '-', (string) $s ) ); }
function sanitize_email( $s ) { return (string) $s; }
function absint( $n ) { return abs( (int) $n ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_unslash( $v ) { return $v; }
function wp_parse_args( $a, $d = [] ) { return array_merge( (array) $d, (array) $a ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); }
function add_action( ...$a ) {} function add_filter( ...$a ) {} function do_action( ...$a ) {} function remove_filter( ...$a ) {} function remove_action( ...$a ) {}
function apply_filters( $t, $v = null, ...$r ) { return $v; }
function has_action( ...$a ) { return false; }
function is_admin() { return true; }
function current_user_can( ...$a ) { return true; }
function get_current_user_id() { return 1; }
function wp_get_current_user() { return (object) [ 'ID' => 1, 'display_name' => 'Quentin', 'user_email' => 'shop@kula.test' ]; }
function admin_url( $p = '' ) { return 'http://shop.test/wp-admin/' . $p; }
function home_url( $p = '/' ) { return 'https://kula-tactical.com' . $p; }
function site_url( $p = '/' ) { return home_url( $p ); }
function get_bloginfo( $k = '' ) { return 'name' === $k ? 'Kula Tactical' : 'https://kula-tactical.com'; }
function add_query_arg( $args, $url = '', $third = null ) {
	if ( ! is_array( $args ) ) { $args = [ (string) $args => $url ]; $url = (string) $third; }
	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args );
}
function remove_query_arg( $k, $u = '' ) { return (string) $u; }
function wp_nonce_url( $u, $a = '' ) { return $u . '&_wpnonce=n'; }
function wp_create_nonce( $a = '' ) { return 'n'; }
function wp_nonce_field( ...$a ) { echo '<input type="hidden" name="_wpnonce" value="n" />'; }
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $g ) . '" />'; }
function checked( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? " checked='checked'" : ''; if ( $e ) { echo $r; } return $r; }
function selected( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? " selected='selected'" : ''; if ( $e ) { echo $r; } return $r; }
function disabled( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? " disabled='disabled'" : ''; if ( $e ) { echo $r; } return $r; }
function human_time_diff( $from, $to = 0 ) { $m = max( 1, (int) round( abs( ( $to ?: time() ) - $from ) / 60 ) ); return $m < 60 ? "$m mins" : ( $m < 1440 ? floor( $m / 60 ) . ' hours' : floor( $m / 1440 ) . ' days' ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); }
function date_i18n( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); }
function mysql2date( $f, $d ) { return $d; }
function current_time( $t = 'timestamp' ) { return 'timestamp' === $t ? time() : gmdate( 'Y-m-d H:i:s' ); }
function current_datetime() { return new DateTimeImmutable(); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function get_current_screen() { return (object) [ 'id' => 'dazont-ecom_page_' . ( $GLOBALS['screen_slug'] ?? '' ), 'base' => 'admin', 'post_type' => '', 'taxonomy' => '' ]; }
function wp_enqueue_style( ...$a ) {} function wp_enqueue_script( ...$a ) {} function wp_register_script( ...$a ) {} function wp_localize_script( $h, $n, $d ) { $GLOBALS['loc'][ $n ] = $d; }
function wp_add_inline_style( ...$a ) {} function wp_add_inline_script( ...$a ) {} function wp_style_is( ...$a ) { return false; } function wp_script_is( ...$a ) { return false; }
function wp_enqueue_media( ...$a ) {} function wp_enqueue_editor( ...$a ) {}
function wp_next_scheduled( ...$a ) { return time() + 3600; } function wp_schedule_event( ...$a ) {} function wp_clear_scheduled_hook( ...$a ) {}
function _get_cron_array() { return []; }
function plugin_basename( $f ) { return 'dazont-ecom/dazont-ecom.php'; }
function get_site_option( $k, $d = false ) { return $d; }
function wp_remote_get( ...$a ) { return new WP_Error( 'off', 'No network in the tour.' ); }
function wp_remote_post( ...$a ) { return new WP_Error( 'off', 'No network in the tour.' ); }
function wp_remote_request( ...$a ) { return new WP_Error( 'off', 'No network in the tour.' ); }
function wp_remote_retrieve_body( $r ) { return ''; } function wp_remote_retrieve_response_code( $r ) { return 0; }
function get_edit_post_link( $id, $c = '' ) { return 'http://shop.test/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
function get_edit_term_link( $id, $t = '' ) { return 'http://shop.test/wp-admin/term.php?tag_ID=' . (int) $id; }
function get_permalink( $id ) { return 'https://kula-tactical.com/product/' . (int) $id . '/'; }
function get_term_link( $t ) { return 'https://kula-tactical.com/category/' . ( is_object( $t ) ? $t->slug : $t ) . '/'; }
function wp_get_attachment_image_url( $id, $s = '' ) { return ''; }
function wp_get_attachment_image_src( $id, $s = '' ) { return false; }
function wp_get_attachment_url( $id ) { return ''; }
function wp_attachment_is_image( $id ) { return false; }
function get_post_thumbnail_id( $id ) { return 0; }
function get_the_ID() { return 0; }
function wc_price( $n ) { return '$' . number_format( (float) $n, 2 ); }
function get_woocommerce_currency() { return 'USD'; }
function get_woocommerce_currency_symbol() { return '$'; }
function wc_get_product( $id ) { return null; }
function wc_get_products( ...$a ) { return []; }
function wc_get_order( $id ) { return null; }
function wc_attribute_label( $n ) { return ucfirst( str_replace( 'pa_', '', (string) $n ) ); }
function wc_get_attribute_taxonomies() { return []; }
function get_posts( ...$a ) { return []; }
function get_post( $id ) { return null; }
function get_post_meta( $id, $k = '', $s = false ) { return $s ? '' : []; }
function get_term_meta( $id, $k = '', $s = false ) { return $s ? '' : []; }
function get_post_type( $id = 0 ) { return 'product'; }
function get_post_types( ...$a ) { return [ 'post' => 'post', 'page' => 'page', 'product' => 'product' ]; }
function post_type_exists( $t ) { return true; }
function taxonomy_exists( $t ) { return true; }
function get_taxonomies( ...$a ) { return [ 'product_cat' => 'product_cat', 'product_tag' => 'product_tag' ]; }
function wp_count_posts( ...$a ) { return (object) [ 'publish' => 2104 ]; }
function get_terms( $args = [] ) { return $GLOBALS['terms']; }
function get_term( $id, $t = '' ) { foreach ( $GLOBALS['terms'] as $x ) { if ( (int) $x->term_id === (int) $id ) { return $x; } } return null; }
function get_term_by( $f, $v, $t = '' ) { foreach ( $GLOBALS['terms'] as $x ) { if ( (string) $x->$f === (string) $v ) { return $x; } } return false; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_die( $m = '' ) { throw new RuntimeException( 'wp_die: ' . $m ); }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['trans'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; }
function delete_transient( $k ) { unset( $GLOBALS['trans'][ $k ] ); return true; }
function wp_cache_get( ...$a ) { return false; } function wp_cache_set( ...$a ) { return true; } function wp_cache_delete( ...$a ) {}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function register_setting( ...$a ) {} function add_settings_error( ...$a ) {}
function paginate_links( $a = [] ) { return ''; }
function convert_to_screen( $s ) { return get_current_screen(); }
function get_admin_page_title() { return ''; }
function submit_button( $t = 'Save Changes', $type = 'primary', $n = 'submit', $wrap = true ) { echo '<p class="submit"><button class="button button-primary">' . esc_html( $t ) . '</button></p>'; }
function wp_dropdown_categories( $a = [] ) { echo '<select><option>All categories</option></select>'; }
function selected_helper() {}
function get_user_by( $f, $v ) { return (object) [ 'ID' => 1, 'display_name' => 'Quentin' ]; }
function get_userdata( $id ) { return (object) [ 'ID' => 1, 'display_name' => 'Quentin' ]; }
function size_format( $b ) { return round( $b / 1024 ) . ' KB'; }
function wp_normalize_path( $p ) { return $p; }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function wp_upload_dir() { return [ 'basedir' => '/tmp', 'baseurl' => 'http://kula.test/wp-content/uploads' ]; }
function get_locale() { return 'en_US'; }
function is_multisite() { return false; }
function wp_is_json_request() { return false; }
function shortcode_exists( $t ) { return false; }
function add_shortcode( ...$a ) {} function remove_shortcode( ...$a ) {} function do_shortcode( $s ) { return (string) $s; }
function has_shortcode( ...$a ) { return false; }
function get_pages( ...$a ) { return []; }
function wp_list_pluck( $l, $f, $i = null ) { $o = []; foreach ( (array) $l as $x ) { $o[] = is_object( $x ) ? $x->$f : $x[ $f ]; } return $o; }
class WP_Error { private $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } public function get_error_code() { return 'off'; } }
class WP_Term { public $term_id; public $name; public $slug; public $parent = 0; public $count = 0; public $description = ''; public $taxonomy = 'product_cat'; public $term_taxonomy_id;
	public function __construct( $id, $name, $count = 0, $parent = 0 ) { $this->term_id = $id; $this->name = $name; $this->slug = sanitize_title( $name ); $this->count = $count; $this->parent = $parent; $this->term_taxonomy_id = 1000 + $id; } }
class WP_Post { public $ID = 0; public $post_title = ''; public $post_content = ''; public $post_excerpt = ''; public $post_type = 'product'; public $post_status = 'publish'; public $post_parent = 0; public $post_modified_gmt = '2026-09-01 00:00:00'; public $post_date_gmt = '2026-09-01 00:00:00'; }
class WP_List_Table {
	public $items = []; protected $_args = []; protected $_column_headers = [];
	public function __construct( $a = [] ) { $this->_args = $a; }
	public function get_columns() { return []; }
	public function prepare_items() {}
	public function display() { echo '<table class="widefat striped"><tbody><tr><td>' . count( $this->items ) . ' rows</td></tr></tbody></table>'; }
	public function search_box( $t, $id ) { echo '<p class="search-box"><input type="search" placeholder="' . esc_attr( $t ) . '" /> <button class="button">' . esc_html( $t ) . '</button></p>'; }
	protected function set_pagination_args( $a ) {}
	public function get_pagenum() { return 1; }
	public function display_tablenav( $w ) {}
	protected function get_sortable_columns() { return []; }
	public function views() {}
}
// The database, answering empty for everything it is asked.
$GLOBALS['wpdb'] = new class {
	public $prefix = 'wp_'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $terms = 'wp_terms'; public $term_taxonomy = 'wp_term_taxonomy'; public $term_relationships = 'wp_term_relationships'; public $options = 'wp_options'; public $comments = 'wp_comments'; public $users = 'wp_users'; public $termmeta = 'wp_termmeta';
	public function prepare( $q, ...$a ) { return (string) $q; }
	public function esc_like( $t ) { return $t; }
	public function get_var( $q = '' ) { return null; }
	public function get_results( $q = '', $o = OBJECT ) { return []; }
	public function get_col( $q = '' ) { return []; }
	public function get_row( $q = '', $o = OBJECT ) { return null; }
	public function query( $q = '' ) { return 0; }
	public function insert( ...$a ) { return 1; } public function update( ...$a ) { return 1; } public function delete( ...$a ) { return 1; }
	public $insert_id = 1;
};
define( 'OBJECT', 'OBJECT' ); define( 'ARRAY_A', 'ARRAY_A' );

// ---- The shop ----
$GLOBALS['opts']  = [];
$GLOBALS['trans'] = [];
$GLOBALS['loc']   = [];
$GLOBALS['terms'] = [
	new WP_Term( 12, 'Tactical vests', 48 ),
	new WP_Term( 13, 'Plate carriers', 21, 12 ),
	new WP_Term( 14, 'Balaclavas', 33 ),
	new WP_Term( 15, 'Camouflage clothing', 210 ),
];
class WooCommerce {}
class DZE_Wpml {
	public static function is_active() { return true; }
	public static function default_language() { return 'en'; }
	public static function get_active_languages() { return [
		'en' => [ 'code' => 'en', 'native_name' => 'English', 'translated_name' => 'English', 'default_locale' => 'en_US' ],
		'fr' => [ 'code' => 'fr', 'native_name' => 'Français', 'translated_name' => 'French', 'default_locale' => 'fr_FR' ],
		'de' => [ 'code' => 'de', 'native_name' => 'Deutsch', 'translated_name' => 'German', 'default_locale' => 'de_DE' ],
	]; }
	public static function post_language( $id, $t = '' ) { return 'en'; }
	public static function flag_html( $code ) { return '<span class="dze-lang">' . strtoupper( (string) $code ) . '</span>'; }
	public static function ids_in_language( ...$a ) { return null; }
	public static function translated_id( ...$a ) { return 0; }
}
class DZE_Cleanup { const OPT_ON_UNINSTALL = 'dze_erase_on_uninstall'; const NONCE = 'dze_cleanup'; public static function measure( $id ) { return [ 'declared' => true, 'options' => 2, 'meta' => 0, 'rows' => 0, 'bytes' => 4096, 'said' => '2 options' ]; } public static function map() { return []; } public static function undeclared() { return []; } public static function human_size( $b ) { return round( (int) $b / 1024 ) . ' KB'; } public static function retired_hooks() { return []; } public static function retire_hooks() {} }
class DZE_Keywords { public static function counts_by_term() { return []; } }
class DZE_Site { public static function is_copy() { return false; } public static function render_line() { echo '<p class="dze-site">This install — the shop itself.</p>'; } public static function says() { return 'the shop itself'; } }
class DZE_Health { public static function log( ...$a ) {} public static function page_url( $t = '' ) { return DZE_Screens::url( 'logs', $t ); } public static function state() { return []; } }
class DZE_Ai_Usage {
	public static function over_budget() { return false; }
	public static function month_totals() { return []; }
	public static function render_graph() { echo '<div id="dze-usage-graph">(usage graph)</div>'; }
	public static function unit( ...$a ) {} public static function about( ...$a ) {}
}
class DZE_Prompts { public static function the_button( $id, $l = '' ) { echo '<button type="button" class="dze-prompt-peek" data-prompt="' . esc_attr( $id ) . '">&#9998; Prompt</button>'; } public static function print_assets() {} public static function the_data( $id ) {} public static function catalog() { return []; } }
class DZE_Prompt_Defaults { public static function control( ...$a ) {} public static function pick( string $id, string $shipped ): string { return $shipped; } public static function control_html( ...$a ) { return ''; } }

require DZE_DIR . 'includes/class-screens.php';
require DZE_DIR . 'includes/class-hub.php';
require DZE_DIR . 'includes/class-modules.php';

ob_start();
try {
	switch ( $page ) {
		case 'shortcodes':
			require DZE_DIR . 'includes/class-shortcodes.php';
			DZE_Shortcodes::render();
			break;
		case 'modules':
			DZE_Modules::instance()->render_page();
			break;
		case 'dashboard':
			class DZE_Restock { const MENU_SLUG = 'dazont-ecom'; const LAST_RECALC = 'dze_last_recalc'; public static function get_line_index() { return []; } public static function get_line_sales( $id ) { return 0; } }
			class DZE_Explorer { const MENU_SLUG = 'dazont-ecom-explorer'; const META_RESEARCHED = '_dze_researched'; }
			class DZE_Marketing_Ai { const MENU_SLUG = 'dazont-ecom-ai'; public static function pending_count() { return 0; } public static function get_settings() { return []; } public static function get_suggestions() { return []; } }
			class DZE_Discounts { const MENU_SLUG = 'dazont-ecom-discounts'; const MENU_SLUG_EVENTS = 'dazont-ecom-marketing-events'; public static function get_rules() { return []; } }
			require DZE_DIR . 'includes/class-dashboard.php';
			DZE_Dashboard::instance()->render_page();
			break;
		case 'sourcing':
			class DZE_Restock { const MENU_SLUG = 'dazont-ecom'; }
			require DZE_DIR . 'includes/class-explorer.php';
			DZE_Explorer::instance()->render_page();
			break;
		case 'marketing':
		case 'rules':
		case 'gmc':
			class DZE_Restock { const MENU_SLUG = 'dazont-ecom'; }
			class DZE_Marketing_Ai { const MENU_SLUG = 'dazont-ecom-ai'; public static function pending_count() { return 2; } public static function get_settings() { return []; } public static function get_suggestions() { return []; } public static function tab_links() { return []; }
				public static function instance() { return new self(); }
				public function render_calendar_panel() { echo '<div class="dze-cal-panel" style="border:1px dashed #c3c4c7;padding:14px;color:#646970;">(the marketing calendar panel — drawn by its own module)</div>'; } }
			require DZE_DIR . 'includes/class-api-keys.php';
			require DZE_DIR . 'includes/class-gmc.php';
			require DZE_DIR . 'includes/class-discounts.php';
			$GLOBALS['opts']['dze_gmc_oauth']      = [ 'client_id' => 'x.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-x' ];
			$GLOBALS['opts']['dze_gmc_connection'] = [ 'refresh_token' => 'r', 'email' => 'shop@kula.test', 'connected' => time() - 3 * DAY_IN_SECONDS ];
			$_GET = [ 'page' => 'gmc' === $page ? DZE_Discounts::MENU_SLUG_EVENTS : ( 'rules' === $page ? DZE_Discounts::MENU_SLUG : DZE_Discounts::MENU_SLUG_EVENTS ), 'tab' => 'gmc' === $page ? 'gmc' : 'events' ];
			if ( 'rules' === $page ) {
				DZE_Discounts::instance()->render_discounts_page();
			} else {
				DZE_Discounts::instance()->render_events_page();
			}
			break;
		case 'restock':
			require DZE_DIR . 'includes/class-restock.php';
			DZE_Restock::instance()->render_page();
			break;
		case 'reviews':
		case 'lab':
			// THE SETTINGS TABS NO GATE DRAWS. Each body is printed the way the
			// Settings page prints it — under the page title and its own tab —
			// so what is wrong on the picture is wrong on the shop.
			class DZE_Marketing_Ai { const MENU_SLUG = 'dazont-ecom-ai'; public static function get_settings() { return [ 'api_key' => 'k', 'model' => 'claude-opus-4-8' ]; } public static function tab_links() { return []; } public static function instance() { return new self(); } }
			class DZE_Content { const OPTION = 'dze_content_settings'; public static function fal_key() { return 'fake-fal-key'; } public static function instance() { return new self(); } public static function get_settings() { return []; } public static function site_language() { return 'English'; } }
			$dze_labels = [ 'reviews' => 'Review generator', 'lab' => 'Image lab' ];
			echo '<div class="wrap dze-wrap"><h1>' . esc_html( DZE_Screens::label( 'settings' ) ) . '</h1><h2 class="nav-tab-wrapper"><a class="nav-tab nav-tab-active" href="#">' . esc_html( $dze_labels[ $page ] ) . '</a></h2>';
			if ( 'reviews' === $page ) {
				require DZE_DIR . 'includes/class-reviews.php';
				DZE_Reviews::instance()->render_settings();
			} else {
				require DZE_DIR . 'includes/class-image-lab.php';
				DZE_Image_Lab::instance()->render();
			}
			echo '</div>';
			break;
		default:
			throw new RuntimeException( 'unknown page ' . $page );
	}
} catch ( Throwable $e ) {
	$out = (string) ob_get_clean();
	fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" );
	echo $out;
	exit( 1 );
}
echo (string) ob_get_clean();
