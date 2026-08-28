<?php
define( 'ABSPATH', '/wp/' );
define( 'DZE_URL', 'http://x/' );
define( 'DZE_VERSION', '4.202.0' );
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_js( $s ) { return addslashes( (string) $s ); }
function esc_textarea( $s ) { return esc_html( $s ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function checked( $a, $b, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $e ) { echo $r; } return $r; }
function selected( $a, $b, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $e ) { echo $r; } return $r; }
function current_user_can( $c ) { return true; }
function wp_style_is( $h, $l = 'enqueued' ) { return false; }
function wp_enqueue_style( ...$a ) { echo "<!-- enqueued {$a[0]} -->\n"; }
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $g ) . '" />'; }
function submit_button( $t = null ) { echo '<p class="submit"><input type="submit" class="button button-primary" value="Save Changes" /></p>'; }
function get_option( $k, $d = false ) { return $d; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_title( $k ) { return preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( (string) $k ) ); }
function sanitize_text_field( $k ) { return trim( strip_tags( (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function add_query_arg( ...$a ) { return 'http://x/'; }
function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; }
function human_time_diff( $a, $b = 0 ) { return '1 hour'; }
function wp_next_scheduled( $h ) { return false; }
function wp_unschedule_event( ...$a ) {}
function wp_schedule_event( ...$a ) {}
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function register_setting( ...$a ) {}
function apply_filters( $t, $v, ...$r ) { return $v; }
function do_action( ...$a ) {}
function delete_transient( $k ) {}
function get_transient( $k ) { return false; }
function set_transient( ...$a ) { return true; }
function absint( $v ) { return abs( (int) $v ); }
final class DZE_Wpml {
	public static function is_active(): bool { return ! empty( getenv('DZE_WPML') ); }
	public static function default_language(): string { return self::is_active() ? 'en' : ''; }
	public static function current_language(): string { return self::is_active() ? 'fr' : ''; }
	public static function get_active_languages(): array { return [ [ 'code'=>'en','english_name'=>'English','native_name'=>'English' ] ]; }
}
if ( ! function_exists( 'post_type_exists' ) ) { function post_type_exists( $t ) { return 'category' !== $t; } }
if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = [], $out = 'names' ) {
		$mk = function ( $n, $l ) { $o = new stdClass(); $o->name = $n; $o->labels = new stdClass(); $o->labels->name = $l; return $o; };
		$types = [ 'post'=>'Posts','page'=>'Pages','product'=>'Products','attachment'=>'Media',
			'wffn_landing'=>'My Templates','wffn_sales'=>'Sales','wffn_ty'=>'Thank You Pages','wffn_optin'=>'Optin Pages',
			'wffn_oc'=>'Optin Confirmation Pages','wffn_sb'=>'Site Builder','wffn_checkout'=>'Checkout',
			'wffn_funnel'=>'Funnels','wffn_offer'=>'Offers' ];
		$r = [];
		foreach ( $types as $n => $l ) { $r[ $n ] = $mk( $n, $l ); }
		return $r;
	}
}
class DZE_Diag_Wpdb {
	public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts';
	public function prepare( $q, ...$a ) { return $q; }
	public function esc_like( $t ) { return $t; }
	public function get_col( $q ) {
		return [ '_bloc_image_1', '_bloc_image_2', '_bloc_text_1', '_bloc_text_2', '_price', '_sku', '_stock', 'rank_math_description' ];
	}
	public function get_results( $q, $m = null ) { return []; }
}
$GLOBALS['wpdb'] = new DZE_Diag_Wpdb();
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
require __DIR__ . '/../../dazont-ecom/includes/class-diagnostic.php';
ob_start();
DZE_Diagnostic::render_settings();
$html = ob_get_clean();
echo $html;

