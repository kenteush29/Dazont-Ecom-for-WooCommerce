<?php
/**
 * The register that decides whether a translation is paid for at all.
 *
 * Run before every release:  php tools/test-translate.php dazont-ecom
 *
 * "Il suffit qu'une simple modification soit faite sur le produit ou sur la
 * catégorie du produit, et le produit est de nouveau marqué Update French
 * translation." WPML hashes the whole post — title, content, excerpt, tags,
 * CATEGORIES, custom fields, the list of variation ids — into one signature and
 * compares it on every save. A renamed category, a new variation or a moved
 * shipping rule re-marks a translation whose words nobody touched.
 *
 * Nothing here changes how WPML works, and nothing should: three catalogues
 * retranslated end to end cost about forty-four dollars, so a wrong mark is
 * worth a few cents and a patched WPML is worth a broken shop on the next
 * update. What the module does instead is keep its OWN register — one md5 of
 * the source text per field per language — and pay only for words that really
 * moved. This runs that against a fake shop and reads the answers.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DZE_VERSION', 'test' );
define( 'DZE_URL', 'https://kula.test/wp-content/plugins/dazont-ecom/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
// WPML is HERE. Without this every reader answers "no WPML" and the whole
// module falls through to "nothing is translated", which would make this gate
// green on code that never runs.
define( 'ICL_SITEPRESS_VERSION', '4.6.0' );

function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_textarea( $s ) { return esc_html( $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function wp_kses_post( $s ) { return (string) $s; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_unslash( $v ) { return $v; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function is_admin() { return true; }
function current_user_can( $c ) { return true; }
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function get_edit_post_link( $id, $x = '' ) { return '/wp-admin/post.php?post=' . (int) $id; }
function register_setting( ...$a ) {}
function checked( $a, $b = true, $e = true ) { return $a == $b ? " checked='checked'" : ''; }
function selected( $a, $b = true, $e = true ) { return $a == $b ? " selected='selected'" : ''; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function get_post_type( $id ) { return (string) ( $GLOBALS['posts'][ (int) $id ]['type'] ?? 'product' ); }
class WP_Error { public function __construct( ...$a ) {} }
function is_wp_error( $t ) { return $t instanceof WP_Error; }

$GLOBALS['opts'] = [];
$GLOBALS['tr']   = [];
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); return true; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }

// --- A shop with one product and its French translation --------------------
$GLOBALS['posts'] = [];
$GLOBALS['meta']  = [];
function get_post( $id = 0 ) {
	$p = $GLOBALS['posts'][ (int) $id ] ?? null;
	return $p ? (object) array_merge( [ 'ID' => (int) $id ], $p ) : null;
}
function get_post_meta( $id, $key = '', $single = false ) {
	$v = $GLOBALS['meta'][ (int) $id ][ $key ] ?? '';
	return $single ? $v : ( '' === $v ? [] : [ $v ] );
}
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ (int) $id ][ $key ] = $value; return true; }

/**
 * WPML, answering the way WPML answers — including the one thing that matters
 * here: `wpml_tm_element_md5` is WPML'S OWN signature, and the module must
 * never invent one of its own in its place.
 */
$GLOBALS['wpml_md5']   = 'WPML-SIGNATURE';
$GLOBALS['translated'] = [];   // [ pid ][ lang ] => target id
function apply_filters( $tag, $value = null, ...$a ) {
	if ( 'wpml_default_language' === $tag ) { return 'en'; }
	if ( 'wpml_object_id' === $tag ) {
		$lang = (string) ( $a[2] ?? '' );
		return (int) ( $GLOBALS['translated'][ (int) $value ][ $lang ] ?? 0 );
	}
	if ( 'wpml_element_language_details' === $tag ) { return [ 'language_code' => 'en' ]; }
	if ( 'wpml_tm_element_md5' === $tag ) {
		$GLOBALS['asked_md5'][] = is_object( $value ) ? (int) $value->ID : 0;
		return (string) $GLOBALS['wpml_md5'];
	}
	return $value;
}
function do_action( ...$a ) {}

/** WPML's two tables, and what was written to them. */
class DZE_Tr_Test_Wpdb {
	public $prefix = 'wp_';
	public array $rows = [];        // translation_id => element_id
	public array $written = [];     // every update() to icl_translation_status
	public function prepare( $q, ...$a ) {
		foreach ( $a as $one ) {
			$q = preg_replace( '/%[ds]/', is_int( $one ) ? (string) $one : "'" . $one . "'", (string) $q, 1 );
		}
		return $q;
	}
	public function get_var( $q ) {
		$sql = (string) $q;
		// SHOW TABLES LIKE — both of WPML's tables are here.
		// SHOW TABLES LIKE answers with the NAME, which is what has_table()
		// compares against. Answering 'yes' made every table look absent and
		// every reader fall silently through to "no WPML".
		if ( false !== stripos( $sql, 'SHOW TABLES' ) ) {
			preg_match( "/LIKE '([^']+)'/", $sql, $m );
			return $GLOBALS['has_icl'] ? (string) ( $m[1] ?? '' ) : '';
		}
		if ( false !== stripos( $sql, 'FROM wp_icl_translations' ) ) {
			preg_match( '/element_id = (\d+)/', $sql, $m );
			$want = (int) ( $m[1] ?? 0 );
			foreach ( $this->rows as $row => $eid ) { if ( $eid === $want ) { return (string) $row; } }
			return '';
		}
		return '';
	}
	public function get_col( $q ) { return []; }
	public function get_results( $q, $o = null ) { return []; }
	public function update( $table, $data, $where, $f = null, $wf = null ) {
		$this->written[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
		return 1;
	}
}
$GLOBALS['has_icl'] = true;
$GLOBALS['wpdb']    = new DZE_Tr_Test_Wpdb();

class DZE_Marketing_Ai {
	public static function api_key() { return 'k'; }
	public static function complete( $sys, $user, $model = '', $max = 0, $t = 0 ) {
		$GLOBALS['calls'][] = $user;
		throw new RuntimeException( 'The gate never pays a provider.' );
	}
}
class DZE_Ai_Usage {
	public static function over_budget() { return false; }
	public static function budget_message() { return 'spent'; }
	public static function unit( $k = '' ) {}
}
class DZE_Content {
	public static function seo_keys() { return [ 'title' => 'rank_math_title', 'desc' => 'rank_math_description' ]; }
}
class DZE_Prompts { public static function the_data( $id ) {} public static function the_button( ...$a ) {} }
class DZE_Prompt_Defaults { public static function pick( $id, $d ) { return $d; } public static function control( ...$a ) {} }

require __DIR__ . '/../' . $dir . '/includes/class-wpml.php';
require __DIR__ . '/../' . $dir . '/includes/class-translate.php';

$ran = 0; $fails = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok   %s\n", $what ); return; }
	$fails++;
	printf( "  WRONG %s\n       got  %s\n       want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}

/** A product, its French translation, and a clean register. */
function shop(): void {
	$GLOBALS['posts'] = [
		7  => [ 'type' => 'product', 'post_title' => 'Admin pouch', 'post_content' => '<p>A pouch for the field.</p>', 'post_excerpt' => 'Small and tough.' ],
		77 => [ 'type' => 'product', 'post_title' => 'Pochette admin', 'post_content' => '<p>Une pochette de terrain.</p>', 'post_excerpt' => 'Petite et solide.' ],
	];
	$GLOBALS['meta'] = [
		7  => [ 'rank_math_title' => 'Admin pouch | Kula', 'rank_math_description' => 'A pouch for the field.' ],
		77 => [],
	];
	$GLOBALS['translated'] = [ 7 => [ 'fr' => 77 ] ];
	$GLOBALS['wpdb']->rows    = [ 501 => 77 ];
	$GLOBALS['wpdb']->written = [];
	$GLOBALS['asked_md5']     = [];
	$GLOBALS['calls']         = [];
	$GLOBALS['has_icl']       = true;
	$GLOBALS['tr']            = [];   // has_table() keeps its answer for a day.
}

echo "\nA MARK IS NOT A CHANGE: what the register answers\n";
shop();
// Nothing translated yet: every field is new, and every one of them is paid for.
ok( 'with no register, everything is new',
	array_keys( DZE_Translate::stale( 7, 'fr' ) ),
	[ 'title', 'content', 'excerpt', 'seo_title', 'seo_desc' ] );

// Now the module records what it translated.
DZE_Translate::remember( 77, DZE_Translate::read( 7 ) );
ok( 'once recorded, nothing is stale',   DZE_Translate::stale( 7, 'fr' ), [] );

// THE WHOLE POINT. WPML re-marks the product because its category was renamed:
// not one word moved, and the module must send nothing at all.
ok( 'a category rename sends nothing',   DZE_Translate::stale( 7, 'fr' ), [] );

// One field edited: that field, and only that field.
$GLOBALS['posts'][7]['post_title'] = 'Admin pouch, MOLLE';
ok( 'a changed title sends the title',   array_keys( DZE_Translate::stale( 7, 'fr' ) ), [ 'title' ] );
ok( 'and not the description with it',
	isset( DZE_Translate::stale( 7, 'fr' )['content'] ), false );

echo "\nTHE REGISTER ONLY CLAIMS WHAT WAS WRITTEN\n";
// A run that sent the title alone must not mark the description as checked.
shop();
$src = DZE_Translate::read( 7 );
DZE_Translate::remember( 77, [ 'title' => $src['title'] ] );
ok( 'one field recorded, one field known',
	array_keys( DZE_Translate::src_map( 77 ) ), [ 'title' ] );
ok( 'the rest is still stale',
	array_keys( DZE_Translate::stale( 7, 'fr' ) ), [ 'content', 'excerpt', 'seo_title', 'seo_desc' ] );

echo "\nCLOSING WPML'S MARK — with WPML'S OWN SIGNATURE\n";
shop();
ok( 'the row is found by its element id', DZE_Wpml::translation_row( 77, 'post_product' ), 501 );
ok( 'and closing it reports success',     DZE_Translate::settle( 7, 'fr' ), true );
$w = $GLOBALS['wpdb']->written[0] ?? [];
ok( 'it writes to WPML\'s status table',  $w['table'] ?? '', 'wp_icl_translation_status' );
ok( 'on the row of THAT translation',     $w['where'] ?? [], [ 'translation_id' => 501 ] );
ok( 'marking it done',                    (int) ( $w['data']['status'] ?? 0 ), 10 );
ok( 'and no longer needing an update',    (int) ( $w['data']['needs_update'] ?? 1 ), 0 );
// THE SIGNATURE IS WPML'S. Ours would drift from theirs on the next WPML
// release and the translation would never be flagged again.
ok( 'with the signature WPML computes',   (string) ( $w['data']['md5'] ?? '' ), 'WPML-SIGNATURE' );
ok( 'asked of the ORIGINAL, not the translation', $GLOBALS['asked_md5'] ?? [], [ 7 ] );

// A SIGNATURE WPML CANNOT GIVE IS NOT ONE TO INVENT. In an AJAX action its
// translation-management hooks may not be loaded; a mark left standing is a
// nuisance, a wrong signature is a translation that is never flagged again.
shop();
$GLOBALS['wpml_md5'] = '';
ok( 'no signature, no write',            DZE_Translate::settle( 7, 'fr' ), false );
ok( 'and nothing was written',           $GLOBALS['wpdb']->written, [] );
$GLOBALS['wpml_md5'] = 'WPML-SIGNATURE';

// Nothing to settle when there is no translation at all.
shop();
$GLOBALS['translated'] = [];
ok( 'an untranslated product settles nothing', DZE_Translate::settle( 7, 'fr' ), false );

echo "\nADOPTING THE TEN THOUSAND MARKS ALREADY THERE\n";
// Translations made by hand through a spreadsheet in 2025, all of them marked.
// Adopting one records the source as it stands and closes the mark — and pays
// nobody: the gate's model throws if it is ever called.
shop();
ok( 'a translation with no register is all stale',
	count( DZE_Translate::stale( 7, 'fr' ) ), 5 );
ok( 'adopting it answers yes',           DZE_Translate::adopt( 7, 'fr' ), true );
ok( 'and then nothing is stale',         DZE_Translate::stale( 7, 'fr' ), [] );
ok( 'the mark was closed',               count( $GLOBALS['wpdb']->written ), 1 );
ok( 'and not one word was sent anywhere', $GLOBALS['calls'] ?? [], [] );
// Adopting what does not exist is not an answer.
shop();
$GLOBALS['translated'] = [];
ok( 'nothing to adopt is not adopted',   DZE_Translate::adopt( 7, 'fr' ), false );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
