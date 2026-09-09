<?php
/**
 * The category panel, and the four controls in its button row.
 *
 * Run before every release:  php tools/test-category.php dazont-ecom
 *
 * This screen is the one that had no gate at all, and it shows: for months
 * "✎ questions" and "✎ linking" were buttons with nothing behind them. The
 * panel is served by AJAX, and `DZE_Prompts::button()` asks for its popup by
 * hooking `admin_footer` — which never fires in an AJAX request. The buttons
 * arrived on a page holding neither the popup nor the handler that opens it,
 * so pressing them did nothing and said nothing. Nothing here could see that:
 * a grep found the buttons, `php -l` found no error, and the screen looked
 * finished.
 *
 * So this renders the panel FOR REAL and reads what came out — and its
 * companion, tools/js/category-panel.mjs, presses the buttons in a browser on
 * both jQuery builds, on a page assembled the way the plugin assembles it.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'DZE_VERSION', 'test' );
define( 'DZE_FILE', __FILE__ );

// --- WordPress, as much of it as the graph touches -------------------------
function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_trim_words( $s, $n = 55, $more = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', (string) $s ), 0, $n ) ) . $more; }
function wp_list_pluck( $rows, $field ) { return array_map( static fn( $r ) => $r[ $field ] ?? null, (array) $rows ); }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function trailingslashit( $s ) { return untrailingslashit( $s ) . '/'; }
function wp_parse_url( $url, $c = -1 ) { return parse_url( (string) $url, $c ); }
function home_url( $p = '/' ) { return 'https://kula.test' . $p; }
function admin_url( $p = '' ) { return 'https://kula.test/wp-admin/' . $p; }
function plugins_url( $p = '', $f = '' ) { return 'https://kula.test/wp-content/plugins/' . $p; }
function current_time( $t ) { return '2026-01-01 00:00:00'; }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function number_format_i18n( $n ) { return (string) $n; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function do_action( ...$a ) {}
/**
 * WPML, answering the way WPML answers.
 *
 * `wpml_element_language_details` is asked with WPML'S OWN name for the kind
 * and WPML's own id: 'post_page' with a post id, 'tax_product_cat' with a
 * TERM TAXONOMY id. Asked by any other name it answers NOTHING — which is
 * exactly what happened, and "nothing" fell through to "the default
 * language", so every category in every language passed for an English one.
 */
function apply_filters( $tag, $value = null, ...$a ) {
	if ( 'wpml_default_language' === $tag ) {
		return $GLOBALS['deflang'] ?? '';
	}
	if ( 'wpml_element_language_details' === $tag ) {
		$ask  = (array) ( $a[0] ?? [] );
		$type = (string) ( $ask['element_type'] ?? '' );
		$id   = (int) ( $ask['element_id'] ?? 0 );
		$GLOBALS['asked_wpml'][] = $type . ':' . $id;
		$lang = $GLOBALS['langof'][ $type ][ $id ] ?? '';
		return '' !== $lang ? [ 'language_code' => $lang ] : null;
	}
	return $value;
}
function wp_next_scheduled( $h ) { return false; }
function wp_schedule_event( ...$a ) {}
function wp_unschedule_event( ...$a ) {}
function is_admin() { return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_kses_post( $s ) { return (string) $s; }
function absint( $v ) { return abs( (int) $v ); }
function wp_unslash( $v ) { return $v; }
function current_user_can( $c ) { return true; }
function wp_enqueue_script( ...$a ) {}
function wp_localize_script( ...$a ) {}
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function get_locale() { return 'en_US'; }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function checked( $a, $b = true, $echo = true ) { return $a == $b ? " checked='checked'" : ''; }
function selected( $a, $b = true, $echo = true ) { return $a == $b ? " selected='selected'" : ''; }

class WP_Error { public function __construct( ...$a ) {} }
function is_wp_error( $t ) { return $t instanceof WP_Error; }

$GLOBALS['tr']   = [];
$GLOBALS['opts'] = [];
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); return true; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $auto = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }

// --- A shop with a mesh in it ---------------------------------------------
// Four categories on two branches, two articles, one page laid out with a
// page builder. The builder page is the one everything used to get wrong.
$GLOBALS['terms'] = [
	10 => [ 'name' => 'Tactical bags',      'slug' => 'tactical-bags',      'parent' => 0, 'description' => '<p>Bags for the field.</p>' ],
	11 => [ 'name' => 'Tactical backpacks', 'slug' => 'tactical-backpacks', 'parent' => 10, 'description' => '<p>Backpacks, one hundred words about them, and <a href="https://kula.test/category/tactical-bags/">Tactical bags</a> above.</p>' ],
	12 => [ 'name' => 'Boonie hats',        'slug' => 'boonie-hats',        'parent' => 0, 'description' => '<p>Hats for the sun.</p>' ],
	13 => [ 'name' => 'Tactical gloves',    'slug' => 'tactical-gloves',    'parent' => 0, 'description' => '' ],
];
$GLOBALS['posts'] = [
	20 => [ 'type' => 'post', 'title' => 'How to choose a tactical backpack', 'content' => '<p>' . str_repeat( 'a backpack word ', 90 ) . '<a href="https://kula.test/category/tactical-backpacks/">Tactical backpacks</a></p>' ],
	21 => [ 'type' => 'post', 'title' => 'Boonie hat sizing',                 'content' => '<p>' . str_repeat( 'a hat word ', 90 ) . '</p>' ],
	22 => [ 'type' => 'page', 'title' => 'About the boonie workshop',         'content' => '' ],
	// A builder page that points at nothing: the one shape that used to sit at
	// the top of both lists at once, unlinkable and blamed for it.
	23 => [ 'type' => 'page', 'title' => 'Workshop history',                  'content' => '' ],
];
$GLOBALS['meta'] = [
	22 => [ '_elementor_data' => '[{"elType":"widget","settings":{"link":{"url":"https:\/\/kula.test\/category\/boonie-hats\/"}}}]' ],
	23 => [ '_elementor_data' => '[{"elType":"widget","settings":{"title":"Since 1998"}}]' ],
];

function get_terms( $args = [] ) {
	$out = [];
	foreach ( $GLOBALS['terms'] as $id => $t ) {
		if ( isset( $args['parent'] ) && (int) $t['parent'] !== (int) $args['parent'] ) { continue; }
		// child_of is the whole branch below a term, at any depth — NOT every
		// term there is. Answered as "everything", the pool called the entire
		// catalogue a sub-category, which is close by construction, and the
		// closeness gate this file exists to test never ran at all.
		if ( isset( $args['child_of'] ) && (int) $t['parent'] !== (int) $args['child_of'] ) { continue; }
		if ( ! empty( $args['exclude'] ) && in_array( $id, (array) $args['exclude'], true ) ) { continue; }
		$out[] = get_term( $id, 'product_cat' );
	}
	return $out;
}
function get_term( $id, $tax = '' ) {
	$t = $GLOBALS['terms'][ (int) $id ] ?? null;
	// A term taxonomy id that is NOT the term id, because on a real shop they
	// part company and WPML indexes by the second one.
	return $t ? (object) array_merge( $t, [
		'term_id'          => (int) $id,
		'term_taxonomy_id' => (int) $id + 500,
		'taxonomy'         => 'product_cat',
		'count'            => 5,
	] ) : null;
}
function get_term_link( $t ) {
	$id = is_object( $t ) ? (int) $t->term_id : (int) $t;
	return 'https://kula.test/category/' . ( $GLOBALS['terms'][ $id ]['slug'] ?? '' ) . '/';
}
// A real store, so a verdict KEPT on a category can be exercised: both model
// passes cache their answer there, and a cache that is never read in a test is
// a cache nobody has checked.
function get_term_meta( $id, $key = '', $single = false ) { return $GLOBALS['tmeta'][ (int) $id ][ (string) $key ] ?? ''; }
function update_term_meta( $id, $key, $val ) { $GLOBALS['tmeta'][ (int) $id ][ (string) $key ] = $val; return true; }
function get_post( $id ) {
	$p = $GLOBALS['posts'][ (int) $id ] ?? null;
	return $p ? (object) [ 'ID' => (int) $id, 'post_title' => $p['title'], 'post_content' => $p['content'], 'post_type' => $p['type'] ] : null;
}
function get_post_type( $id ) { return $GLOBALS['posts'][ (int) $id ]['type'] ?? ''; }
function get_permalink( $id ) { return 'https://kula.test/' . ( 'page' === get_post_type( $id ) ? '' : 'blog/' ) . $id . '/'; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['meta'][ (int) $id ][ $key ] ?? ''; }
function wc_get_page_id( $w ) { return 0; }

/** The posts table, and the mesh's own. */
class DZE_Mesh_Wpdb {
	public $prefix = 'wp_';
	public $posts  = 'wp_posts';
	public $rows   = [];
	public $queries = [];
	public function get_charset_collate() { return ''; }
	public function prepare( $q, ...$a ) {
		foreach ( $a as $one ) {
			$q = preg_replace( '/%[dsf]/', is_int( $one ) ? (string) $one : "'" . $one . "'", (string) $q, 1 );
		}
		return $q;
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( 0 === stripos( $sql, 'TRUNCATE' ) ) { $this->rows = []; return true; }
		if ( preg_match( '/INSERT INTO \S+ \(from_kind,from_id,to_kind,to_id,anchor,seen\) VALUES (.*)$/is', $sql, $m ) ) {
			preg_match_all( "/\('([^']*)',(\d+),'([^']*)',(\d+),'([^']*)','([^']*)'\)/", $m[1], $all, PREG_SET_ORDER );
			foreach ( $all as $one ) {
				$this->rows[] = [ 'from_kind' => $one[1], 'from_id' => (int) $one[2], 'to_kind' => $one[3], 'to_id' => (int) $one[4], 'anchor' => $one[5] ];
			}
		}
		return true;
	}
	public function get_results( $sql, $out = ARRAY_A ) {
		if ( false !== stripos( $sql, 'FROM wp_posts' ) ) {
			$rows = [];
			foreach ( $GLOBALS['posts'] as $id => $p ) {
				$rows[] = [ 'ID' => $id, 'post_title' => $p['title'], 'post_type' => $p['type'], 'post_content' => $p['content'] ];
			}
			return $rows;
		}
		if ( false !== stripos( $sql, 'GROUP BY to_kind' ) ) {
			$n = [];
			foreach ( $this->rows as $r ) { $n[ $r['to_kind'] . '|' . $r['to_id'] ] = ( $n[ $r['to_kind'] . '|' . $r['to_id'] ] ?? 0 ) + 1; }
			$out = [];
			foreach ( $n as $k => $c ) { [ $kind, $id ] = explode( '|', $k ); $out[] = [ 'to_kind' => $kind, 'to_id' => $id, 'n' => $c ]; }
			return $out;
		}
		if ( false !== stripos( $sql, 'GROUP BY from_kind' ) ) {
			$n = [];
			foreach ( $this->rows as $r ) { $n[ $r['from_kind'] . '|' . $r['from_id'] ] = ( $n[ $r['from_kind'] . '|' . $r['from_id'] ] ?? 0 ) + 1; }
			$out = [];
			foreach ( $n as $k => $c ) { [ $kind, $id ] = explode( '|', $k ); $out[] = [ 'from_kind' => $kind, 'from_id' => $id, 'n' => $c ]; }
			return $out;
		}
		if ( false !== stripos( $sql, 'from_kind, from_id, to_kind, to_id FROM' ) ) {
			return $this->rows;
		}
		if ( false !== stripos( $sql, 'WHERE to_kind' ) ) {
			return $this->rows;
		}
		return [];
	}
	public function get_var( $sql ) {
		return false !== stripos( $sql, 'COUNT(*)' ) ? count( $this->rows ) : null;
	}
	public function insert( $t, $row ) { return true; }
}
$GLOBALS['wpdb'] = new DZE_Mesh_Wpdb();
$wpdb            = $GLOBALS['wpdb'];

/** The queue: what was asked of it, and nothing done. */
class DZE_Queue {
	public const NONCE = 'dze_queue';
	public static array $added = [];
	public static function add( string $kind, array $ids, bool $auto = false, array $payload = [] ): int {
		self::$added[] = [ 'kind' => $kind, 'ids' => $ids, 'payload' => $payload ];
		return count( $ids );
	}
	public static function waiting_for( ...$a ) { return []; }
	public static function pending_for( ...$a ) { return null; }
	public static function review_for( ...$a ) { return null; }
}
/** The writing service. It answers what the test tells it to. */
class DZE_Marketing_Ai {
	public static string $answer = '';
	public static array $sent = [];
	public static function api_key(): string { return $GLOBALS['key'] ?? ''; }
	public static function get_settings(): array { return []; }
	/** A stub that READS the request, so a call sending the wrong thing cannot pass. */
	public static $decide = null;
	public static function complete( string $system, string $user, string $model = '', int $max = 0, int $t = 0 ): string {
		self::$sent[] = [ 'system' => $system, 'user' => $user, 'model' => $model ];
		if ( is_callable( self::$decide ) ) { return (string) call_user_func( self::$decide, $user ); }
		if ( '' === self::$answer ) { throw new RuntimeException( 'no answer' ); }
		return self::$answer;
	}
}
class DZE_Ai_Usage {
	public static array $units = [];
	public static function unit( string $u = '' ): void { self::$units[] = $u; }
	public static function over_budget(): bool { return false; }
	public static function budget_message(): string { return ''; }
	public static function last_for( string $id ): array { return []; }
}
class DZE_Keywords_Absent {}


// --- What the panel needs beyond the shop above -------------------------
define( 'DZE_URL', 'http://kula.test/wp-content/plugins/dazont-ecom/' );
define( 'DZE_DIR', __DIR__ . '/../dazont-ecom/' );
function esc_textarea( $s ) { return esc_html( $s ); }
function esc_js( $s ) { return addslashes( (string) $s ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function disabled( $a, $b = true, $echo = true ) { $out = $a == $b ? ' disabled' : ''; if ( $echo ) { echo $out; } return $out; }
function wp_editor( ...$a ) {}
function wp_enqueue_editor() {}
function wp_enqueue_style( ...$a ) {}
function wp_style_is( ...$a ) { return true; }
function did_action( $a ) { return 0; }
function submit_button( ...$a ) {}
function get_admin_page_title() { return ''; }
function wp_nonce_field( ...$a ) { return ''; }
function size_format( $n ) { return (string) $n; }
function get_term_children( $id, $tax = '' ) {
	$out = [];
	foreach ( $GLOBALS['terms'] as $tid => $t ) {
		if ( (int) $t['parent'] === (int) $id ) { $out[] = $tid; }
	}
	return $out;
}

/** The prompt catalog answers for the three category prompts. */
class DZE_Prompt_Defaults {
	public static function pick( $id, $shipped ) { return $shipped; }
	public static function control( $id, $sel ) { echo '<span class="dze-pd" data-prompt="' . esc_attr( (string) $id ) . '"></span>'; }
}
class DZE_Modules { public static function enabled( $id ) { return ! in_array( $id, (array) ( $GLOBALS['off'] ?? [] ), true ); } }

require __DIR__ . '/../' . $dir . '/includes/class-hub.php';
require __DIR__ . '/../' . $dir . '/includes/class-category-content.php';
require __DIR__ . '/../' . $dir . '/includes/class-post-links.php';
require __DIR__ . '/../' . $dir . '/includes/class-mesh.php';
require __DIR__ . '/../' . $dir . '/includes/class-prompts.php';

$ran   = 0;
$fails = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok   %s\n", $what ); return; }
	$fails++;
	printf( "  WRONG %s\n       got  %s\n       want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}

// The catalogue this category sits in, as the pool reads it.
$GLOBALS['tr']['dze_cc_cats'] = [];
foreach ( $GLOBALS['terms'] as $id => $t ) {
	$GLOBALS['tr']['dze_cc_cats'][] = [ 'id' => $id, 'name' => $t['name'], 'slug' => $t['slug'], 'url' => get_term_link( $id ) ];
	$GLOBALS['tr'][ 'dze_cc_pcount_' . $id ] = 5;
}
$GLOBALS['tr']['dze_cc_pages'] = [
	[ 'id' => 20, 'title' => 'How to choose a tactical backpack', 'slug' => 'choose-backpack', 'url' => get_permalink( 20 ), 'kind' => 'blog post' ],
];

echo "\nThe panel, rendered\n";
ob_start();
DZE_Category_Content::instance()->render_panel( 10 );
$panel = (string) ob_get_clean();
// ONE SHAPE FOR THE WHOLE SHOP. "Tu pourrais simplement utiliser le même
// style de popup que sur un produit. Avec le bloc What to generate. Je veux
// que cette méthode soit la seule méthode standardisée sur tout le shop."
// A block per kind of work, its switch in its own title, and ONE button that
// runs what is ticked — the shape the product screens already have, built by
// the same DZE_Hub.
ok( 'the panel says what it will generate',
	false !== strpos( $panel, 'What to generate' ), true );
ok( 'the description is a block',
	(bool) preg_match( '#<section class="dze-sec[^"]*" data-sec="cc-desc"#', $panel ), true );
ok( 'with its switch in its own title',
	(bool) preg_match( '#data-sec="cc-desc".*?<h3 class="dze-sec-head".*?id="dze-cc-do-desc".*?</h3>#s', $panel ), true );
ok( 'the links are a block too',
	(bool) preg_match( '#<section class="dze-sec[^"]*" data-sec="cc-links"#', $panel ), true );
ok( 'with its own switch',
	(bool) preg_match( '#data-sec="cc-links".*?<h3 class="dze-sec-head".*?id="dze-cc-do-links".*?</h3>#s', $panel ), true );
ok( 'and one button runs what is ticked',
	substr_count( $panel, 'dze-cc-run"' ), 1 );
// AND THE TWO GESTURES IT REPLACES ARE GONE: two ways of running one thing is
// two things to keep in step.
ok( 'no second way to write it',        false !== strpos( $panel, 'dze-cc-gen' ), false );
ok( 'nor to place the links',           false !== strpos( $panel, 'dze-cc-links"' ), false );
ok( 'nor a panel hidden behind a button of its own',
	false !== strpos( $panel, 'dze-cc-ltoggle-pick' ), false );

echo "\nONE PROMPT PER BLOCK, AND NOTHING ELSE ON THE ROW\n";
// "Write with AI / Add internal links only / ✎ prompt / ✎ questions / ✎ linking
// / ⓘ what it uses / Import SEMrush file" — seven controls of four different
// kinds, and three of them answering questions nobody had asked on this
// screen. It is the product screens' shape now: one prompt per block, named,
// with the one button every prompt in this plugin is read from.
$row = $panel;
preg_match_all( '/<button[^>]*class="([^"]*)"[^>]*>(.*?)<\/button>/s', $row, $btns, PREG_SET_ORDER );
$peeks = [];
foreach ( $btns as $b ) {
	if ( false !== strpos( $b[1], 'dze-prompt-peek' ) ) {
		$peeks[] = trim( html_entity_decode( wp_strip_all_tags( $b[2] ), ENT_QUOTES | ENT_HTML5 ) );
	}
}
ok( 'one prompt control per block',      count( $peeks ), 2 );
ok( 'and both read the same way',        array_values( array_unique( $peeks ) ), [ '✎ prompt' ] );
// A CONTROL WEARING THE PROMPT-POPUP CLASS WITH NO PROMPT BEHIND IT ANSWERS
// "This prompt could not be read." That is what "ⓘ what it uses" was: the
// popup's handler picked it up, found no id, and said so. It is gone rather
// than mended — what it showed is what the prompt popup already shows.
$dze_bad = 0;
foreach ( $btns as $b ) {
	if ( false !== strpos( $b[1], 'dze-prompt-peek' ) && false === strpos( $b[0], 'data-prompt="' ) ) {
		$dze_bad++;
	}
}
ok( 'no prompt button without a prompt',  $dze_bad, 0 );
// A LONE ICON IS A SYMBOL YOU HAVE TO LEARN.
ok( 'no lone icon is left',             false !== strpos( $panel, 'dze-cx-icon' ), false );
ok( 'and no second prompt editor',      false !== strpos( $panel, 'dze-cc-ptext' ), false );
// AND NO SECOND SURFACE FOR WHAT THE PROMPT POPUP ALREADY SAYS.
ok( '"what it uses" is gone with it',    false !== strpos( $panel, 'dze-cc-data' ), false );

echo "\nEach button names the prompt it opens\n";
preg_match_all( '/data-prompt="([^"]+)"/', $row, $ids );
sort( $ids[1] );
// cat_sift is NOT one of them: it is not a way of writing this page, it is the
// filter deciding WHICH imported questions reach the writer, and it is edited
// in Settings → Categories. "Je ne comprends pas ce que fait ce prompt ici."
ok( 'the two passes, each with its own', $ids[1], [ 'cat_desc', 'cat_links' ] );
ok( 'and the question filter is not one', in_array( 'cat_sift', $ids[1], true ), false );
// EACH IN THE BLOCK IT IS ABOUT.
$dze_d = substr( $panel, (int) strpos( $panel, 'data-sec="cc-desc"' ) );
$dze_d = substr( $dze_d, 0, (int) strpos( $dze_d, 'data-sec="cc-links"' ) );
ok( 'the writing prompt is in the description block',
	false !== strpos( $dze_d, 'data-prompt="cat_desc"' ), true );
ok( 'and the linking prompt is not',    false !== strpos( $dze_d, 'data-prompt="cat_links"' ), false );

echo "\nAN ADVISORY IS A LINE THAT OPENS, NOT A PLACARD\n";
// "Je pense que cette alerte devrait être plus discrète. Avec possibilité de
// l'ouverture de l'alerte pour voir la description et ajouter avec le bouton
// un fichier semrush."
ok( 'the SEMrush advisory folds',
	false !== strpos( $panel, '<details class="dze-cc-note">' ), true );
// AND IT CARRIES THE WAY OUT. Where the Sourcing Assistant answers that is
// the import button; where it is switched off the advisory says so rather
// than offering a control that cannot work. One of the two, never neither.
$dze_note = substr( $panel, (int) strpos( $panel, '<details class="dze-cc-note">' ) );
$dze_note = substr( $dze_note, 0, (int) strpos( $dze_note, '</details>' ) );
ok( 'and it carries the way out inside it',
	false !== strpos( $dze_note, 'dze-cc-imtoggle' )
		|| false !== strpos( $dze_note, 'Sourcing Assistant' ), true );
// AND IT IS NO LONGER A PLACARD. The advisory used to be a .dze-cc-warn box
// like the ones that mean "you cannot work here", which is what made a note
// about headings read as a wall.
ok( 'the advisory is not a warning box',
	false !== strpos( $dze_note, 'dze-cc-warn' ), false );

echo "\nWhat arrives ticked in the link picker\n";
// "Ils étaient tous présélectionnés" — thirty pages, Tactical Sunglasses and
// Tactical Balaclavas among them, because every page of a tactical shop
// carries the word "tactical".
preg_match_all( '/<input type="checkbox" class="dze-cc-pick" value="([^"]*)"([^>]*)>/', $panel, $picks, PREG_SET_ORDER );
$on = array_values( array_filter( $picks, static fn( array $p ): bool => false !== strpos( $p[2], 'checked' ) ) );
ok( 'the picker lists the candidates',  count( $picks ) > 0, true );
ok( 'and does not tick them all',       count( $on ) < count( $picks ), true );
ok( 'nothing beyond the ceiling',       count( $on ) <= 12, true );

// The markup the browser gate presses, assembled the way the plugin
// assembles it: the panel, and the popup the screen prints for it.
if ( in_array( '--dump-panel', $argv, true ) ) {
	ob_start();
	DZE_Prompts::render_modal();
	$modal = (string) ob_get_clean();
	file_put_contents( 'php://stderr', sprintf( "\n%d checks, %d wrong\n", $ran, $fails ) );
	echo $panel . "\n<!--MODAL-->\n" . $modal;
	exit( $fails ? 1 : 0 );
}


echo "\nA SHOP WHERE EVERY PAGE SHARES THE SAME TWO WORDS\n";
// "Pour Jute and cotton rugs > Beige jute rugs — not obviously related,
// Diamond jute rugs — not obviously related, Black jute rugs — not obviously
// related..." Twenty candidates, every one of them plainly the same subject,
// every one of them scored at nought.
//
// weigh() throws away any word carried by more than a quarter of the
// candidates. That is right on a shop where "tactical" is on every page and
// distinguishes nothing; on a shop whose every page is a jute rug it throws
// away "jute" and "rug" — which is to say, the whole of what they have in
// common. Counting words cannot tell those two shops apart.
$GLOBALS['terms'][29] = [ 'name' => 'Jute rugs',            'slug' => 'jute-rugs',      'parent' => 0,  'description' => '' ];
$GLOBALS['terms'][30] = [ 'name' => 'Jute and cotton rugs', 'slug' => 'jute-cotton',    'parent' => 29, 'description' => '' ];
foreach ( [ 31 => 'Beige jute rugs', 32 => 'Black jute rugs', 33 => 'Diamond jute rugs', 34 => 'Hemp and jute rugs', 35 => 'Red jute rugs', 36 => 'Cream jute rugs' ] as $dze_id => $dze_name ) {
	$GLOBALS['terms'][ $dze_id ] = [ 'name' => $dze_name, 'slug' => strtolower( str_replace( ' ', '-', $dze_name ) ), 'parent' => 29, 'description' => '' ];
}
$GLOBALS['tr']['dze_cc_cats'] = [];
foreach ( $GLOBALS['terms'] as $dze_id => $dze_t ) {
	$GLOBALS['tr']['dze_cc_cats'][] = [ 'id' => $dze_id, 'name' => $dze_t['name'], 'slug' => $dze_t['slug'], 'url' => get_term_link( $dze_id ) ];
	$GLOBALS['tr'][ 'dze_cc_pcount_' . $dze_id ] = 5;
}
/** The pool as the screen gets it, keyed by name. */
$dze_pool = static function ( int $tid ): array {
	$out = [];
	foreach ( DZE_Category_Content::link_pool( $tid ) as $row ) {
		$out[ (string) $row['label'] ] = $row;
	}
	return $out;
};

// 1. WITH NOBODY TO READ THEM, the wording decides — and the row says so.
$GLOBALS['tmeta'] = [];
DZE_Marketing_Ai::$decide = null;
DZE_Marketing_Ai::$answer = '';
$dze_by_word = $dze_pool( 30 );
ok( 'the branch is still ticked',        $dze_by_word['Jute rugs']['close'] ?? null, true );
// This is the bug, stated as arithmetic: "jute" and "rug" are carried by
// every candidate, so nothing is left to weigh and every sibling scores 0.
ok( 'and a sibling scores nothing at all', (float) ( $dze_by_word['Black jute rugs']['score'] ?? -1 ), 0.0 );
// It says WHICH of the two chose it, and never a verdict nobody gave. The
// screen used to print "not obviously related", which is a judgement about
// the subject that no part of this code ever made.
ok( 'a row chosen by the wording says so',
	(string) ( $dze_by_word['Jute rugs']['why'] ?? '' ), 'chosen on wording' );
ok( 'and an unticked one claims no reading',
	(string) ( $dze_by_word['Black jute rugs']['why'] ?? 'x' ), '' );

// 2. READ, the same list comes back judged — and the reasons are the reader's.
$GLOBALS['tmeta'] = [];
DZE_Marketing_Ai::$sent = [];
// The stub answers from the REQUEST: it reads the numbered list that was
// actually sent. A call that sends no list, or an unnumbered one, cannot pass
// this — which is the point of answering from the request rather than from a
// fixture written beside it.
DZE_Marketing_Ai::$decide = static function ( string $user ): string {
	preg_match_all( '/^(\d+)\. (.+)$/m', $user, $m, PREG_SET_ORDER );
	$keep = [];
	foreach ( $m as $one ) {
		if ( false !== stripos( $one[2], 'jute' ) ) {
			$keep[] = [ 'i' => (int) $one[1], 'why' => 'a jute rug like this one' ];
		}
	}
	return wp_json_encode( $keep );
};
$dze_read = $dze_pool( 30 );
ok( 'the reader was asked once',         count( DZE_Marketing_Ai::$sent ), 1 );
ok( 'a sibling the wording scored at nought is kept',
	$dze_read['Black jute rugs']['close'] ?? null, true );
ok( 'and so is the one beside it',       $dze_read['Diamond jute rugs']['close'] ?? null, true );
ok( 'each carrying what it is to this page',
	(string) ( $dze_read['Black jute rugs']['why'] ?? '' ), 'a jute rug like this one' );
// WHAT WAS SENT. The category, the numbered shortlist, the owner's own prompt
// and the ceiling this page can carry — asserted on the request itself.
$dze_ask = DZE_Marketing_Ai::$sent[0]['user'] ?? '';
ok( 'the ask names the category',        false !== strpos( $dze_ask, 'Jute and cotton rugs' ), true );
ok( 'and numbers the pages it offers',   false !== strpos( $dze_ask, '. Black jute rugs [related category]' ), true );
ok( "and carries the shop's own prompt",
	false !== strpos( $dze_ask, DZE_Category_Content::pick_prompt() ), true );
ok( 'and says how many links fit',       (bool) preg_match( '/at most \d+ links/', $dze_ask ), true );
ok( 'and it is billed as its own work',  in_array( 'cat_pick', DZE_Ai_Usage::$units, true ), true );

// 3. ASKED ONCE. The verdict is kept on the category until the candidates or
// the prompt change: a panel opened twice must not be paid for twice.
DZE_Marketing_Ai::$sent = [];
$dze_pool( 30 );
ok( 'a second look asks nobody',         count( DZE_Marketing_Ai::$sent ), 0 );
// AND AN EDITED PROMPT IS A NEW QUESTION. Showing yesterday's answer to a
// prompt changed since is the screen lying about what it did.
update_option( 'dze_catcontent_settings', array_merge(
	(array) get_option( 'dze_catcontent_settings', [] ),
	[ 'pick_prompt' => 'Keep only what a buyer would click.' ]
) );
$dze_pool( 30 );
ok( 'a changed prompt asks again',       count( DZE_Marketing_Ai::$sent ), 1 );
ok( 'with the new words in it',
	false !== strpos( DZE_Marketing_Ai::$sent[0]['user'] ?? '', 'Keep only what a buyer would click.' ), true );

// 4. THE CEILING IS THE PAGE'S OWN. The model is told it and the shop keeps
// it whatever comes back — a figure the screen states and then does not keep
// is worse than no figure.
$dze_room = (int) ( DZE_Category_Content::size_for( 30 )['links'] ?? 0 );
$dze_lots = 0;
foreach ( $dze_pool( 30 ) as $dze_row ) {
	if ( ! empty( $dze_row['close'] ) ) { $dze_lots++; }
}
ok( 'never more links than the page can carry', $dze_lots <= $dze_room, true );

// 5. A READER ANSWERING "NONE OF THEM" is more likely wrong than a shortlist
// the wording already agreed on: the pool goes through, and says who chose it.
$GLOBALS['tmeta'] = [];
DZE_Marketing_Ai::$decide = static fn( string $user ): string => '[]';
$dze_none = $dze_pool( 30 );
ok( 'an empty verdict does not empty the list',
	$dze_none['Jute rugs']['close'] ?? null, true );
ok( 'and the row says the wording chose it',
	(string) ( $dze_none['Jute rugs']['why'] ?? '' ), 'chosen on wording' );
// A refusal, a missing key, a broken answer: the same.
$GLOBALS['tmeta'] = [];
DZE_Marketing_Ai::$decide = static function ( string $user ): string { throw new RuntimeException( 'no key' ); };
$dze_dead = $dze_pool( 30 );
ok( 'a refusal loses no candidate',      $dze_dead['Jute rugs']['close'] ?? null, true );
ok( 'and claims no reading either',
	(string) ( $dze_dead['Jute rugs']['why'] ?? '' ), 'chosen on wording' );
DZE_Marketing_Ai::$decide = null;

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
