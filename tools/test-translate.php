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
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_url_raw( $s ) { return (string) $s; }
function esc_sql( $s ) { return is_array( $s ) ? array_map( 'esc_sql', $s ) : addslashes( (string) $s ); }
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
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function wp_slash( $v ) { return is_string( $v ) ? addslashes( $v ) : $v; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function is_admin() { return true; }
function current_user_can( $c ) { return true; }
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function get_edit_post_link( $id, $x = '' ) { return '/wp-admin/post.php?post=' . (int) $id; }
function register_setting( ...$a ) {}
function checked( $a, $b = true, $e = true ) { $r = $a == $b ? " checked='checked'" : ''; if ( $e ) { echo $r; } return $r; }
function selected( $a, $b = true, $e = true ) { $r = $a == $b ? " selected='selected'" : ''; if ( $e ) { echo $r; } return $r; }
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
	// WITH NO KEY, WORDPRESS HANDS BACK EVERY KEY, each value in an array.
	// That is how a variation's `attribute_*` combination is read, and a
	// harness that answers '' for it hides the whole question.
	if ( '' === (string) $key ) {
		$out = [];
		foreach ( (array) ( $GLOBALS['meta'][ (int) $id ] ?? [] ) as $k => $v ) { $out[ $k ] = [ $v ]; }
		foreach ( (array) ( $GLOBALS['postmeta'][ (int) $id ] ?? [] ) as $k => $v ) { $out[ $k ] = [ $v ]; }
		return $out;
	}
	$v = $GLOBALS['meta'][ (int) $id ][ $key ] ?? ( $GLOBALS['postmeta'][ (int) $id ][ $key ] ?? '' );
	return $single ? $v : ( '' === $v ? [] : [ $v ] );
}
// COMME WORDPRESS : update_post_meta() passe la valeur a wp_unslash() avant
// de l'enregistrer. Un double qui garde la valeur telle quelle ne peut pas
// voir le defaut qui rendait tout le registre d'annulation illisible.
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ (int) $id ][ $key ] = is_string( $value ) ? stripslashes( $value ) : $value; return true; }

/**
 * WPML, answering the way WPML answers — including the one thing that matters
 * here: `wpml_tm_element_md5` is WPML'S OWN signature, and the module must
 * never invent one of its own in its place.
 */
$GLOBALS['wpml_md5']   = 'WPML-SIGNATURE';
$GLOBALS['translated'] = [];   // [ pid ][ lang ] => target id
function apply_filters( $tag, $value = null, ...$a ) {
	if ( 'wpml_default_language' === $tag ) { return 'en'; }
	if ( 'wpml_active_languages' === $tag && ! empty( $GLOBALS['langs_off'] ) ) {
		// A FILTER ONLY ANSWERS WHERE ITS PLUGIN'S HOOKS ARE LOADED. In
		// admin-ajax this one answered nothing, and a whole batch reported
		// "nothing had moved" on a shop that had translated nothing at all.
		return null;
	}
	if ( 'wpml_active_languages' === $tag ) {
		return [
			'en' => [ 'native_name' => 'English', 'english_name' => 'English' ],
			'fr' => [ 'native_name' => 'Français', 'english_name' => 'French' ],
			'de' => [ 'native_name' => 'Deutsch', 'english_name' => 'German' ],
		];
	}
	if ( 'wpml_object_id' === $tag ) {
		// A FILTER NOBODY REGISTERED HANDS BACK WHAT IT WAS GIVEN. That is the
		// state of WPML's hooks in admin-ajax and in cron, and it is what made
		// "there is no translation" and "here is the translation" the same
		// number — so the switch is here, and the reading has to survive it.
		if ( ! empty( $GLOBALS['wpml_filter_off'] ) ) {
			return $value;
		}
		$lang = (string) ( $a[2] ?? '' );
		return (int) ( $GLOBALS['translated'][ (int) $value ][ $lang ] ?? 0 );
	}
	if ( 'wpml_element_language_code' === $tag ) {
		$id = (int) ( $a[0]['element_id'] ?? 0 );
		return (string) ( $GLOBALS['post_lang'][ $id ] ?? 'en' );
	}
	if ( 'wpml_element_language_details' === $tag ) {
		// A TRANSLATION IS NOT IN THE SOURCE LANGUAGE. Answering 'en' for
		// everything made every object look like an original, and the
		// variations of a French product could never be found.
		$id = (int) ( $a[0]['element_id'] ?? 0 );
		return [ 'language_code' => (string) ( $GLOBALS['post_lang'][ $id ] ?? 'en' ) ];
	}
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
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $termmeta = 'wp_termmeta';
	public $terms         = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	/** The page of work the screen asks WPML's tables for. */
	public array $todo_ids = [];
	public array $todo_sql = [];
	/** What review_counts() answers, per kind. */
	public array $review_posts = [];
	public array $review_terms = [];
	/** WPML's own languages table, for the request where its filters are silent. */
	public array $langs = [];
	/** What the waiting-list queries answer, set by the checks that need them. */
	public array $waiting_posts = [];
	public array $waiting_terms = [];
	public array $rows = [];        // translation_id => element_id
	public array $written = [];     // every update() to icl_translation_status
	public function prepare( $q, ...$a ) {
		foreach ( $a as $one ) {
			$q = preg_replace( '/%[ds]/', is_int( $one ) ? (string) $one : "'" . $one . "'", (string) $q, 1 );
		}
		return $q;
	}
	/**
	 * ONE ROW. `term_row()` reads a term from the tables rather than through
	 * `get_term()`, because WPML filters that to the current language and
	 * answers with the ORIGINAL when asked for a translation. The double has to
	 * answer the same question, or the check passes on a reading the shop never
	 * makes.
	 */
	public function get_row( $q, $output = OBJECT ) {
		$sql = (string) $q;
		if ( false !== strpos( $sql, 'FROM wp_terms' ) && preg_match( '/t\.term_id = (\d+)/', $sql, $m ) ) {
			$id = (int) $m[1];
			$t  = $GLOBALS['terms'][ $id ] ?? null;
			if ( ! $t ) {
				return null;
			}
			$row = [
				'name'        => (string) ( $t['name'] ?? '' ),
				'slug'        => (string) ( $t['slug'] ?? '' ),
				'description' => (string) ( $t['description'] ?? '' ),
			];
			return ARRAY_A === $output ? $row : (object) $row;
		}
		return null;
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
		// ONE PAGE OF WORK: the count and the rows come out of the same query,
		// which is the whole point of the fix they exercise.
		if ( false !== stripos( $sql, 'dze_todo' ) ) {
			$this->todo_sql[] = $sql;
			return (string) count( $this->todo_ids );
		}
		// The badge: how many objects hold a translation nobody has decided on.
		if ( false !== stripos( $sql, 'COUNT(*)' ) && false !== stripos( $sql, 'wp_postmeta' ) ) {
			return (string) count( $this->waiting_posts );
		}
		if ( false !== stripos( $sql, 'COUNT(*)' ) && false !== stripos( $sql, 'wp_termmeta' ) ) {
			return (string) count( $this->waiting_terms );
		}
		if ( false !== stripos( $sql, 'FROM wp_icl_translations' ) ) {
			preg_match( '/element_id = (\d+)/', $sql, $m );
			$want = (int) ( $m[1] ?? 0 );
			foreach ( $this->rows as $row => $eid ) { if ( $eid === $want ) { return (string) $row; } }
			return '';
		}
		return '';
	}
	public function get_col( $q ) {
		$sql = (string) $q;
		// WHICH ROWS ALREADY HOLD SOMETHING WAITING — asked once for the page,
		// which is what makes a row say Review rather than Look.
		if ( false !== stripos( $sql, '_dze_tr_wait' ) ) {
			$ids = [];
			foreach ( $this->waiting_posts as $r ) { $ids[] = (int) $r['oid']; }
			foreach ( $this->waiting_terms as $r ) { $ids[] = (int) $r['oid']; }
			return $ids;
		}
		if ( false !== stripos( $sql, 'AS dze_id' ) ) {
			$this->todo_sql[] = $sql;
			return $this->todo_ids;
		}
		return [];
	}
	/** Which WPML "Translate" keys hold text on a kind, and on how many. */
	public array $text_keys = [];   // rows of [ meta_key, n ]
	/** WPML's own marks, as the screen asks for them. */
	public array $marks = [];   // rows of [ src, lang, needs ]
	public array $counts = [];  // rows of [ lang, n ]
	public array $due = [];     // rows of [ lang, n ] for needs_update = 1
	public function get_results( $q, $o = null ) {
		$sql = (string) $q;
		if ( false !== stripos( $sql, 'icl_languages_translations' ) ) {
			$out = [];
			foreach ( $this->langs as $l ) {
				$out[] = [ 'language_code' => $l['code'], 'name' => 'fr' === $l['code'] ? 'Français' : $l['english_name'] ];
			}
			return $out;
		}
		if ( false !== stripos( $sql, 'FROM wp_icl_languages' ) ) { return $this->langs; }
		// WHAT IS WAITING, PER KIND — told apart from the waiting LIST by what
		// it groups on, because both read the same two meta tables.
		if ( false !== stripos( $sql, 'GROUP BY pm.meta_key' ) ) { return $this->text_keys; }
		if ( false !== stripos( $sql, 'GROUP BY p.post_type' ) ) { return $this->review_posts; }
		if ( false !== stripos( $sql, 'GROUP BY tt.taxonomy' ) ) { return $this->review_terms; }
		if ( false !== stripos( $sql, 'wp_postmeta' ) ) { return $this->waiting_posts; }
		if ( false !== stripos( $sql, 'wp_termmeta' ) ) { return $this->waiting_terms; }
		// The three readings of WPML's tables, told apart by what they select.
		if ( false !== stripos( $sql, 'needs_update = 1' ) ) { return $this->due; }
		if ( false !== stripos( $sql, 'AS src' ) ) { return $this->marks; }
		if ( false !== stripos( $sql, 'icl_translations' ) ) { return $this->counts; }
		return [];
	}
	public function update( $table, $data, $where, $f = null, $wf = null ) {
		$this->written[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
		return 1;
	}
}
$GLOBALS['has_icl'] = true;
$GLOBALS['wpdb']    = new DZE_Tr_Test_Wpdb();

class DZE_Marketing_Ai {
	public static function api_key() { return 'k'; }
	const MENU_SLUG = 'dazont-ecom-ai';
	const MODELS = [ 'claude-haiku-4-5-20251001' => 'Haiku 4.5', 'claude-opus-5' => 'Opus 5' ];
	public static function complete( $sys, $user, $model = '', $max = 0, $t = 0 ) {
		$GLOBALS['calls'][] = $user;
		// By default nothing is ever paid for: a check that expects silence
		// must FAIL loudly if a call is made. The batch checks below set an
		// answer on purpose, and read back what was actually sent.
		// A CALL AT A TIME. Cutting a long text sends one request per piece,
		// and a double that answers the same thing every time cannot tell
		// whether the pieces were put back in the right order.
		if ( isset( $GLOBALS['model_answer_fn'] ) ) {
			return (string) call_user_func( $GLOBALS['model_answer_fn'], $user );
		}
		if ( ! isset( $GLOBALS['model_answer'] ) ) {
			throw new RuntimeException( 'The gate never pays a provider.' );
		}
		return (string) $GLOBALS['model_answer'];
	}
}
class DZE_Ai_Usage {
	public static function over_budget() { return false; }
	public static function budget_message() { return 'spent'; }
	public static function unit( $k = '' ) {}
	// A METHOD MISSING FROM THE HARNESS IS NOT A MODULE THAT REFUSED TO WORK.
	// Without this one, `finished()` threw an Error, `produce()` caught it as
	// a Throwable like any provider failure, and the gate read "the batch came
	// back with nothing" — which is a true sentence about a fault that only
	// ever existed in this file.
	public static function finished( $unit, $n = 1 ) {}
}
class DZE_Content {
	public static function seo_keys() {
		// A SHOP WITH NO SEO PLUGIN falls back to keys of the plugin's own that
		// nothing on the site reads — the case the report has to name rather
		// than call "translated".
		return empty( $GLOBALS['no_seo'] )
			? [ 'title' => 'rank_math_title', 'desc' => 'rank_math_description' ]
			: [ 'title' => '_dze_seo_title',  'desc' => '_dze_seo_desc' ];
	}
}
class DZE_Prompts { public static function the_data( $id ) {} public static function the_button( ...$a ) {} }

// --- TERMS. The module translates every taxonomy WPML translates, attributes
// --- included, so the fake shop has to hold terms as well as posts.
$GLOBALS['terms']     = [];   // term_id => [ name, description, taxonomy, parent, term_taxonomy_id ]
$GLOBALS['termmeta']  = [];
function get_term( $id, $tax = '' ) {
	$t = $GLOBALS['terms'][ (int) $id ] ?? null;
	if ( ! $t ) { return null; }
	if ( '' !== $tax && (string) $t['taxonomy'] !== (string) $tax ) { return null; }
	return (object) array_merge( [ 'term_id' => (int) $id ], $t );
}
function get_terms( $args = [] ) {
	$out = [];
	foreach ( $GLOBALS['terms'] as $id => $t ) {
		if ( ! empty( $args['taxonomy'] ) && $t['taxonomy'] !== $args['taxonomy'] ) { continue; }
		$out[] = get_term( $id );
	}
	return $out;
}
function wp_count_terms( $args = [] ) { return count( get_terms( $args ) ); }
function get_term_meta( $id, $key = '', $single = false ) {
	$v = $GLOBALS['termmeta'][ (int) $id ][ $key ] ?? '';
	return $single ? $v : ( '' === $v ? [] : [ $v ] );
}
// Fidele lui aussi : update_term_meta() deshabille comme son cousin.
function update_term_meta( $id, $key, $value ) { $GLOBALS['termmeta'][ (int) $id ][ $key ] = is_string( $value ) ? stripslashes( $value ) : $value; return true; }
function delete_term_meta( $id, $key ) { unset( $GLOBALS['termmeta'][ (int) $id ][ $key ] ); return true; }
function delete_post_meta( $id, $key, $v = '' ) { unset( $GLOBALS['meta'][ (int) $id ][ $key ] ); return true; }
function wp_insert_term( $name, $tax, $args = [] ) {
	$id = max( array_keys( $GLOBALS['terms'] ) ) + 1;
	$GLOBALS['terms'][ $id ] = [
		'name'             => (string) $name,
		'description'      => (string) ( $args['description'] ?? '' ),
		'taxonomy'         => (string) $tax,
		'parent'           => (int) ( $args['parent'] ?? 0 ),
		'term_taxonomy_id' => $id + 1000,
	];
	return [ 'term_id' => $id, 'term_taxonomy_id' => $id + 1000 ];
}
function wp_update_term( $id, $tax, $args = [] ) {
	foreach ( $args as $k => $v ) { $GLOBALS['terms'][ (int) $id ][ $k ] = $v; }
	return [ 'term_id' => (int) $id ];
}
function wp_update_post( $post ) { 
	$id = (int) ( $post['ID'] ?? 0 );
	foreach ( $post as $k => $v ) { if ( 'ID' !== $k ) { $GLOBALS['posts'][ $id ][ $k ] = $v; } }
	return $id;
}
$GLOBALS['product_type'] = [];
$GLOBALS['wcml_asked']   = [];
function wc_get_product( $id ) {
	$type = (string) ( $GLOBALS['product_type'][ (int) $id ] ?? 'simple' );
	return new class( $type ) {
		private string $t;
		public function __construct( string $t ) { $this->t = $t; }
		public function is_type( $want ) { return $this->t === $want; }
	};
}
// WooCommerce Multilingual, reached the way WCML publishes it. The module ASKS
// it to build the variations — it never builds one itself.
function wcml_get_woocommerce_wpml() {
	if ( ! empty( $GLOBALS['no_wcml'] ) ) { return null; }
	return new class {
		public $sync_variations_data;
		public $attributes;
		public $sync_product_data;
		public function __construct() {
			// THE WHOLE JOB IN ONE CALL, on the WCML builds that have it.
			if ( ! empty( $GLOBALS['wcml_whole'] ) ) {
				$this->sync_product_data = new class {
					public function sync_product_data( $pid, $tr, $lang ) {
						$GLOBALS['wcml_asked'][] = [ 'sync_product_data', (int) $pid, (int) $tr, (string) $lang ];
					}
				};
			}
			// THE AXES. Their absence is what the fallback has to survive.
			if ( empty( $GLOBALS['no_wcml_attrs'] ) ) {
				$this->attributes = new class {
					public function sync_product_attr( $pid, $tr, $lang ) {
						$GLOBALS['wcml_asked'][] = [ 'sync_product_attr', (int) $pid, (int) $tr, (string) $lang ];
						// WCML hands the ORIGINAL's attributes back, which is
						// what the variation step is then built along.
						return (array) ( $GLOBALS['meta'][ (int) $pid ]['_product_attributes'] ?? [] );
					}
				};
			}
			$this->sync_variations_data = new class {
				public function sync_product_variations( $pid, $tr, $lang, $args = [] ) {
					$GLOBALS['wcml_asked'][] = [ 'sync_product_variations', (int) $pid, (int) $tr, (string) $lang, $args ];
					// A build that happened puts a variation under the
					// translation, which is what the screen reads back.
					$GLOBALS['posts'][ 801 ] = $GLOBALS['posts'][ 801 ] ?? [ 'type' => 'product_variation', 'post_parent' => (int) $tr, 'post_title' => '', 'post_content' => '', 'post_excerpt' => '' ];
				}
			};
		}
	};
}
function get_taxonomy( $tax ) {
	$known = [
		'product_cat' => 'Categories', 'product_tag' => 'Tags',
		'pa_colour'   => 'Colour',     'post_tag'    => 'Post tags',
	];
	return isset( $known[ $tax ] ) ? (object) [ 'labels' => (object) [ 'name' => $known[ $tax ] ] ] : null;
}
function get_post_type_object( $type ) {
	// A type the shop could choose but is not one of the six: the fake shop
	// has to hold one, or "optional" cannot be exercised at all.
	$known = [ 'product' => 'Products', 'post' => 'Posts', 'page' => 'Pages', 'acme_doc' => 'Documents' ];
	return isset( $known[ $type ] ) ? (object) [ 'public' => true, 'labels' => (object) [ 'name' => $known[ $type ] ] ] : null;
}
function get_object_taxonomies( $type ) { return 'product' === $type ? [ 'product_cat', 'product_type', 'pa_colour' ] : [ 'post_tag' ]; }
// A VARIABLE PRODUCT'S CHILDREN. The variations carry words of their own, in
// their excerpt, and none of it was ever sent.
function get_children( $args = [] ) {
	$out = [];
	foreach ( (array) $GLOBALS['posts'] as $id => $p ) {
		if ( (int) ( $p['post_parent'] ?? 0 ) !== (int) ( $args['post_parent'] ?? 0 ) ) { continue; }
		if ( ( $p['type'] ?? '' ) !== ( $args['post_type'] ?? '' ) ) { continue; }
		$out[ $id ] = get_post( $id );
	}
	return $out;
}
function get_post_field( $field, $id, $ctx = 'display' ) {
	return (string) ( $GLOBALS['posts'][ (int) $id ][ (string) $field ] ?? '' );
}
function taxonomy_exists( $tax ) { return null !== get_taxonomy( $tax ); }
function get_term_by( $by, $value, $tax = '' ) {
	foreach ( (array) $GLOBALS['terms'] as $id => $t ) {
		if ( ( $t['taxonomy'] ?? '' ) !== $tax ) { continue; }
		if ( 'slug' === $by && sanitize_title( (string) $t['name'] ) === (string) $value ) { return get_term( $id ); }
	}
	return null;
}
function get_edit_term_link( $id, $tax ) { return '/wp-admin/term.php?taxonomy=' . $tax . '&tag_ID=' . (int) $id; }
function get_the_title( $id ) { return (string) ( $GLOBALS['posts'][ (int) $id ]['post_title'] ?? '' ); }
function wp_get_object_terms( $ids, $tax, $args = [] ) {
	$out = [];
	foreach ( (array) ( $GLOBALS['object_terms'][ (int) ( is_array( $ids ) ? reset( $ids ) : $ids ) ] ?? [] ) as $tid ) {
		$t = get_term( (int) $tid );
		if ( $t && ( $t->taxonomy ?? '' ) === $tax ) { $out[] = $t; }
	}
	return $out;
}
function wp_set_object_terms( ...$a ) { return true; }
function admin_url( $p = '' ) { return 'https://kula.test/wp-admin/' . $p; }
function add_query_arg( $args, $url = '' ) { return $url . '?' . http_build_query( (array) $args ); }
function add_submenu_page( ...$a ) { $GLOBALS['menu'][] = $a; return 'x'; }
// Enough of a settings page for the tab to be RENDERED, not only called: a
// settings tab that dies takes the whole page white, before any of our own
// error handling, and that has happened here for six versions running.
function remove_query_arg( $keys, $url = '' ) { return (string) $url; }
function settings_fields( $g ) {}
function submit_button( ...$a ) {}
function wp_die( $m = '' ) { throw new RuntimeException( (string) $m ); }
function disabled( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? " disabled='disabled'" : ''; if ( $e ) { echo $r; } return $r; }
$GLOBALS['enq'] = [];
$GLOBALS['loc'] = [];
function wp_enqueue_script( $h, ...$a ) { $GLOBALS['enq'][] = $h; }
function wp_enqueue_style( $h, ...$a ) { $GLOBALS['enq'][] = $h; }
function wp_enqueue_editor() { $GLOBALS['enq'][] = 'editor'; }
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['loc'][ (string) $name ] = $data; }
// WHICH SCREEN WE ARE ON. The button planted in WPML's Language box only
// exists on an edit screen of something WPML will link, so the harness has to
// be able to stand on one.
$GLOBALS['screen'] = null;
function get_current_screen() {
	return $GLOBALS['screen'] ?: (object) [ 'id' => 'toplevel_page_' . DZE_Translate::MENU_SLUG, 'post_type' => '', 'base' => '', 'taxonomy' => '' ];
}
function get_the_ID() { return (int) ( $GLOBALS['editing_id'] ?? 0 ); }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ); }
function paginate_links( $args = [] ) {
	// THE PAGER IS THE FIGURE THE SCREEN PROMISES. Returning '' hid the very
	// disagreement these checks exist for: "1 2 3 Next" over two rows.
	$GLOBALS['paginate'] = $args;
	return '<span class="dze-pager" data-total="' . (int) ( $args['total'] ?? 0 ) . '"></span>';
}
function wp_kses_post_x( $s ) { return $s; }
class WP_Query {
	public array $posts = [];
	public int $found_posts = 0;
	public function __construct( $args = [] ) {
		foreach ( $GLOBALS['posts'] as $id => $p ) {
			if ( ( $p['type'] ?? 'product' ) === ( $args['post_type'] ?? '' ) ) {
				$this->posts[] = (object) [ 'ID' => (int) $id ];
			}
		}
		$this->found_posts = count( $this->posts );
	}
}
function _n( $o, $m, $n, $d = '' ) { return 1 === (int) $n ? $o : $m; }
class DZE_Modules { public static function enabled( $id ) { return true; } }
class DZE_Restock { const MENU_SLUG = 'dazont-ecom'; }
class DZE_Prompt_Defaults { public static function pick( $id, $d ) { return $d; } public static function control( ...$a ) {} }

// The one place that versions this plugin's admin files; every screen
// class asks it rather than hanging DZE_VERSION on the same handle.
require __DIR__ . '/../' . $dir . '/includes/class-assets.php';
require __DIR__ . '/../' . $dir . '/includes/class-wpml.php';
// THE SHAPE IS BUILT IN ONE PLACE. The batch screen draws its blocks with
// DZE_Hub, exactly as the product bulk screen does, so the gate has to load the
// real one — a stub here would prove the screen calls something, and nothing
// about what it draws.
require __DIR__ . '/../' . $dir . '/includes/class-hub.php';
// The screen half is a trait of the same class — loaded by the autoloader on a
// real site, required by name here, like every other file this gate runs.
// The catalogue of screens: every page reads its name and its tabs from it.
require __DIR__ . '/../' . $dir . '/includes/class-screens.php';
require __DIR__ . '/../' . $dir . '/includes/class-translate-screen.php';
require __DIR__ . '/../' . $dir . '/includes/class-translate.php';

// =============================================================================
// THE SCREEN AS THE PLUGIN PRINTS IT, for the browser gate that presses it.
//
// Never a copy of the markup written into the test: what is pressed there has
// to be what ships, and the config it reads is `wp_localize_script`'s own, key
// by key — named `ajax` instead of `ajaxUrl` it would post to the page itself
// and the gate would prove nothing while looking green.
// =============================================================================
$dze_dump = '';
foreach ( (array) $argv as $one ) {
	if ( 0 === strpos( (string) $one, '--dump-screen' ) ) {
		$bits     = explode( '=', (string) $one, 2 );
		$dze_dump = $bits[1] ?? 'dashboard';
	}
}
if ( '' !== $dze_dump ) {
	$GLOBALS['opts']['icl_sitepress_settings'] = [
		'custom_posts_sync_option' => [ 'product' => 2, 'post' => 1, 'page' => 1, 'shop_order' => 0 ],
		'taxonomies_sync_option'   => [ 'product_cat' => 1, 'pa_colour' => 1, 'product_type' => 0 ],
	];
	$GLOBALS['terms'] = [
		7 => [ 'name' => 'Balaclavas', 'description' => 'Warm ones.', 'taxonomy' => 'product_cat', 'parent' => 0, 'term_taxonomy_id' => 1007 ],
		8 => [ 'name' => 'Plate carriers', 'description' => 'Heavy.', 'taxonomy' => 'product_cat', 'parent' => 0, 'term_taxonomy_id' => 1008 ],
	];
	// THE ONE TRANSLATION SCREEN, per object. It replaced a popup of ours on
	// the product page — a second per-object surface that narrated its own
	// plumbing: "cet écran c'est encore du custom. Je veux un seul écran pour
	// chaque type de post. Comme le fait wpml !"
	if ( 'settings' === $dze_dump ) {
		ob_start();
		DZE_Translate::render_settings();
		echo wp_json_encode( [ 'html' => (string) ob_get_clean() ] );
		exit( 0 );
	}
	if ( 'editor' === $dze_dump ) {
		$GLOBALS['posts'][700] = [ 'type' => 'product', 'post_title' => 'Field shirt', 'post_content' => '<p>A shirt for the field.</p>', 'post_excerpt' => '' ];
		$GLOBALS['posts'][800] = [ 'type' => 'product', 'post_title' => 'Chemise', 'post_content' => '', 'post_excerpt' => '' ];
		$GLOBALS['posts'][701] = [ 'type' => 'product_variation', 'post_parent' => 700, 'post_title' => '', 'post_content' => '', 'post_excerpt' => 'Olive, black zip.' ];
		$GLOBALS['postmeta'][701]['attribute_pa_colour'] = 'olive-drab';
		$GLOBALS['terms'][50] = [ 'name' => 'Olive Drab', 'description' => '', 'taxonomy' => 'pa_colour', 'parent' => 0, 'term_taxonomy_id' => 1050 ];
		$GLOBALS['product_type'][700] = 'variable';
		$GLOBALS['translated'][700]['fr'] = 800;
		$GLOBALS['post_lang'][800] = 'fr';
		$_GET = [ 'tab' => 'batch', 'ref' => 'post:700:product', 'lang' => 'fr' ];
		$GLOBALS['loc'] = [];
		DZE_Translate::instance()->screen_assets( 'toplevel_page_' . DZE_Translate::MENU_SLUG );
		ob_start();
		DZE_Translate::instance()->render_page();
		echo wp_json_encode( [ 'html' => (string) ob_get_clean(), 'cfg' => $GLOBALS['loc']['dzeTrScreen'] ?? [] ] );
		exit( 0 );
	}
	$_GET['tab'] = 'review' === $dze_dump ? 'review' : ( 'dashboard' === $dze_dump ? 'dashboard' : 'batch' );
	if ( 'review' === $dze_dump ) {
		$held = wp_json_encode( [
			'at'    => time(),
			'langs' => [ 'fr' => [ 'name' => 'Cagoules', 'description' => 'Des chaudes.' ] ],
			'src'   => [ 'name' => 'Balaclavas', 'description' => 'Warm ones.' ],
		] );
		$GLOBALS['termmeta'][7]['_dze_tr_wait'] = $held;
		$GLOBALS['wpdb']->waiting_terms = [ [ 'oid' => 7, 'v' => $held ] ];
	} else {
		$_GET['scope'] = 'term:product_cat';
		// THE LIST IS WHAT WPML'S TABLES ANSWER. The screen the browser gate
		// presses has to be the screen the shop gets, rows included.
		$GLOBALS['wpdb']->todo_ids = [ 7, 8 ];
		$GLOBALS['wpdb']->marks    = [
			[ 'src' => 1007, 'lang' => 'fr', 'needs' => 1 ],
		];
	}
	$GLOBALS['loc'] = [];
	DZE_Translate::instance()->screen_assets( 'toplevel_page_' . DZE_Translate::MENU_SLUG );
	ob_start();
	DZE_Translate::instance()->render_page();
	echo wp_json_encode( [ 'html' => (string) ob_get_clean(), 'cfg' => $GLOBALS['loc']['dzeTrScreen'] ?? [] ] );
	exit( 0 );
}

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

// =============================================================================
// WHAT MAY BE TRANSLATED IS WPML'S ANSWER, NEVER OURS
//
// A module that supplements WPML must not offer to translate something WPML
// will refuse to link: the words are written, the translation group is never
// made, and the shop is left with an orphan in another language and nothing
// anywhere saying why. The answer is read from WPML's own settings ROW rather
// than through `wpml_is_translated_post_type` — a filter only answers where
// its plugin's hooks are loaded, and this module reads in AJAX and in cron.
// That is the trap already paid for three times in this plugin.
// =============================================================================
echo "\nWhat WPML says may be translated\n";
$GLOBALS['opts']['icl_sitepress_settings'] = [
	'custom_posts_sync_option' => [
		'product'    => 2,
		'post'       => 1,
		'page'       => 1,
		// Switched OFF in WPML. Offering it would be an orphan page.
		'shop_order' => 0,
	],
	'taxonomies_sync_option' => [
		'product_cat'  => 1,
		'product_tag'  => 1,
		// A PRODUCT ATTRIBUTE IS A TAXONOMY LIKE ANY OTHER, and a shop selling
		// by colour in five markets needs its colours translated.
		'pa_colour'    => 1,
		'product_type' => 0,
	],
	'translation-management' => [
		'custom_fields_translation' => [
			// WPML COPIES this one from the original on every sync. Writing a
			// translation into it is words thrown away at the next save, with
			// nothing saying so.
			'rank_math_description' => 1,
			'rank_math_title'       => 2,
		],
	],
];
ok( 'a type WPML translates is translatable',   DZE_Wpml::is_translated_type( 'product' ), true );
ok( 'and one it does not is not',               DZE_Wpml::is_translated_type( 'shop_order' ), false );
ok( 'a type WPML has never been asked about is not assumed',
	DZE_Wpml::is_translated_type( 'acme_thing' ), false );
ok( 'a taxonomy WPML translates is translatable', DZE_Wpml::is_translated_taxonomy( 'product_cat' ), true );
ok( 'a product attribute is one of them',       DZE_Wpml::is_translated_taxonomy( 'pa_colour' ), true );
ok( 'and product_type is not',                  DZE_Wpml::is_translated_taxonomy( 'product_type' ), false );
ok( 'a field WPML copies is named as copied',   DZE_Wpml::custom_field_mode( 'rank_math_description' ), 1 );
ok( 'one it translates is named as translated', DZE_Wpml::custom_field_mode( 'rank_math_title' ), 2 );
ok( 'and one it has no opinion on says so',     DZE_Wpml::custom_field_mode( '_price' ), -1 );
// WPML'S OWN NAME FOR A THING. One wrong string here has no symptom: it simply
// answers nothing and every reading falls through to "the shop's own language",
// which is what once reported 830 pages on a site holding a fifth of that.
ok( 'a post is named post_<type>',              DZE_Wpml::element_name( 'post', 'product' ), 'post_product' );
ok( 'a term is named tax_<taxonomy>',           DZE_Wpml::element_name( 'term', 'product_cat' ), 'tax_product_cat' );

echo "\nAnd the screen offers exactly that, and nothing else\n";
$scope = DZE_Translate::scope();
ok( 'products are offered',                     isset( $scope['post:product'] ), true );
ok( 'articles are offered',                     isset( $scope['post:post'] ), true );
ok( 'pages are offered',                        isset( $scope['post:page'] ), true );
ok( 'product categories are offered',           isset( $scope['term:product_cat'] ), true );
ok( 'the colour attribute is offered',          isset( $scope['term:pa_colour'] ), true );
ok( 'and marked as an attribute',               ! empty( $scope['term:pa_colour']['attr'] ), true );
ok( 'a type WPML will not link is NOT offered', isset( $scope['post:shop_order'] ), false );
ok( 'and neither is product_type',              isset( $scope['term:product_type'] ), false );

// =============================================================================
// A TERM IS AN OBJECT, with its own register in term meta
// =============================================================================
echo "\nA category is translated like anything else\n";
$GLOBALS['terms'] = [
	7 => [ 'name' => 'Balaclavas', 'description' => 'Warm ones.', 'taxonomy' => 'product_cat', 'parent' => 0, 'term_taxonomy_id' => 1007 ],
	8 => [ 'name' => 'Cagoules',   'description' => 'Chaudes.',   'taxonomy' => 'product_cat', 'parent' => 0, 'term_taxonomy_id' => 1008 ],
	9 => [ 'name' => 'Hidden',     'description' => '',           'taxonomy' => 'product_type', 'parent' => 0, 'term_taxonomy_id' => 1009 ],
];
$cat = DZE_Translate::obj( 'term', 7 );
ok( 'a category of a translated taxonomy is an object', $cat['type'] ?? '', 'product_cat' );
ok( 'a term of an untranslated one is not',     DZE_Translate::obj( 'term', 9 ), [] );
ok( 'its two fields are read',                  DZE_Translate::obj_read( $cat ), [ 'name' => 'Balaclavas', 'description' => 'Warm ones.' ] );
// WHICH FIELDS ARE SENT IS NOT A SETTING. It was a row of tick boxes, and a
// shop that had ticked two of them was quietly shipping half-translated pages:
// "le plugin doit traduire tout ce que wpml exige de traduire pour avoir une
// traduction complète du post." An old saved list is no longer read, and the
// sanitizer no longer writes one.
$GLOBALS['opts']['dze_translate_settings'] = [ 'fields' => [ 'title' ] ];
ok( 'a term is never narrowed by it',
	array_keys( DZE_Translate::fields( 'term' ) ), array_keys( DZE_Translate::active_fields( 'term' ) ) );
ok( 'and neither is a post any more',
	array_keys( DZE_Translate::active_fields( 'post' ) ), array_keys( DZE_Translate::fields( 'post' ) ) );
ok( 'and a form that posts one is not obeyed',
	(array) ( DZE_Translate::instance()->sanitize( [ 'fields' => [ 'content' ] ] )['fields'] ?? [] ), [ 'title' ] );
unset( $GLOBALS['opts']['dze_translate_settings'] );

// Everything is new when nothing is translated yet.
ok( 'an untranslated category is all stale',    array_keys( DZE_Translate::obj_stale( $cat, 'fr' ) ), [ 'name', 'description' ] );
// Once the register says the source is what it is, nothing is owed.
$GLOBALS['translated'][7]['fr'] = 8;
DZE_Translate::remember( 8, DZE_Translate::obj_read( $cat ), $cat );
ok( 'the register lives in TERM meta',          isset( $GLOBALS['termmeta'][8]['_dze_tr_src'] ), true );
ok( 'and then nothing about it is stale',       DZE_Translate::obj_stale( $cat, 'fr' ), [] );
$GLOBALS['terms'][7]['name'] = 'Balaclavas and hoods';
ok( 'a changed name is the only thing stale',   array_keys( DZE_Translate::obj_stale( $cat, 'fr' ) ), [ 'name' ] );

// A TERM IS NOT SIGNED WITH A SIGNATURE NOBODY COMPUTED. WPML signs a term its
// own way; inventing one is a translation nobody is ever told about again.
$GLOBALS['wpdb']->rows = [ 55 => 1008 ];
$GLOBALS['wpdb']->written = [];
$GLOBALS['asked_md5'] = [];
ok( 'settling a term clears its mark',          DZE_Translate::obj_settle( $cat, 'fr' ), true );
$last = end( $GLOBALS['wpdb']->written );
ok( 'status 10, needs_update 0',                [ $last['data']['status'], $last['data']['needs_update'] ], [ 10, 0 ] );
ok( 'and NO signature is invented for it',      array_key_exists( 'md5', $last['data'] ), false );

// =============================================================================
// THE WAITING LIST — a batch is read before it lands
// =============================================================================
echo "\nA batch waits to be read\n";
$GLOBALS['model_answer'] = wp_json_encode( [ 'name' => 'Cagoules et capuches', 'description' => 'Des chaudes.' ] );
$GLOBALS['calls'] = [];
$made = DZE_Translate::produce( $cat, [ 'fr' ] );
ok( 'only the field that moved was sent',       count( $GLOBALS['calls'] ), 1 );
ok( 'and it is the one that moved',             false !== strpos( $GLOBALS['calls'][0], 'Balaclavas and hoods' ), true );
ok( 'nothing failed on the way',                $made['errors'], [] );
ok( 'the answer is held, not written',          array_keys( $made['langs'] ), [ 'fr' ] );
ok( 'the category itself has not moved',        $GLOBALS['terms'][8]['name'], 'Cagoules' );
ok( 'and the wait is stored on the SOURCE',     isset( $GLOBALS['termmeta'][7]['_dze_tr_wait'] ), true );

$held = DZE_Translate::waiting( $cat );
ok( 'what waits can be read back',              array_keys( (array) $held['langs'] ), [ 'fr' ] );
// WHAT IT WAS TRANSLATED FROM, kept beside it: accepting a week later must
// write the register against the words that were SENT, not against a source
// somebody has edited since.
ok( 'and the source it came from with it',      ( $held['src']['name'] ?? '' ), 'Balaclavas and hoods' );

echo "\nAnd it is on the list, whichever kind of thing it is\n";
$GLOBALS['wpdb']->waiting_posts = [];
$GLOBALS['wpdb']->waiting_terms = [ [ 'oid' => 7, 'v' => $GLOBALS['termmeta'][7]['_dze_tr_wait'] ] ];
$list = DZE_Translate::review_list();
ok( 'the category is on the review list',       count( $list ), 1 );
ok( 'named for what it is',                     $list[0]['label'] ?? '', 'Balaclavas and hoods' );
ok( 'with the language waiting on it',          $list[0]['langs'] ?? [], [ 'fr' ] );
ok( 'and the count answers the same',           DZE_Translate::review_count(), 1 );

echo "\nAccepting writes it, and the register claims only what was written\n";
$GLOBALS['wpdb']->written = [];
// A BATCH IS ACCEPTED LATER, and the source can have moved in between — which
// is the whole reason the words that were SENT are kept beside the answer.
// Read from the source as it stands at the moment of accepting, the register
// would claim a field is current when nobody has translated the new words, and
// that field never gets retranslated again.
$GLOBALS['terms'][7]['name'] = 'Balaclavas, hoods and caps';
$done = DZE_Translate::accept( $cat, [ 'fr' => [ 'name' => 'Cagoules et capuches' ] ] );
ok( 'the translation was written',              $done['written']['fr'] ?? 0, 8 );
ok( 'the name landed on the translation',       $GLOBALS['terms'][8]['name'], 'Cagoules et capuches' );
// A FIELD LEFT OUT IS NOT CLAIMED. The description was not ticked, so the
// register must not say it was checked — or it never gets translated again.
$reg = json_decode( (string) $GLOBALS['termmeta'][8]['_dze_tr_src'], true );
ok( 'the name is claimed against what was sent', $reg['name'] ?? '', md5( 'Balaclavas and hoods' ) );
// And so the words typed since are seen as new, rather than swallowed.
ok( 'so the words typed since are still owed',  array_keys( DZE_Translate::obj_stale( $cat, 'fr' ) ), [ 'name' ] );
ok( 'and the untouched description still is not stale-free by accident',
	( $reg['description'] ?? '' ) === md5( 'Warm ones.' ), true );
ok( 'nothing is left waiting on it',            DZE_Translate::waiting( $cat ), [] );

echo "\nA language left undecided keeps the object on the list\n";
$GLOBALS['model_answer'] = wp_json_encode( [ 'name' => 'X' ] );
DZE_Translate::produce( $cat, [ 'fr', 'de' ] );
$held = DZE_Translate::waiting( $cat );
ok( 'two languages came back',                  count( (array) $held['langs'] ), 2 );
DZE_Translate::accept( $cat, [ 'fr' => [ 'name' => 'Cagoules, capuches et masques' ] ] );
$left = DZE_Translate::waiting( $cat );
ok( 'the one decided is gone',                  isset( $left['langs']['fr'] ), false );
ok( 'and the one nobody read is still there',   isset( $left['langs']['de'] ), true );

echo "\nRefusing throws it away and touches nothing\n";
$before = $GLOBALS['terms'][8]['name'];
DZE_Translate::drop_wait( $cat );
ok( 'nothing is waiting any more',              DZE_Translate::waiting( $cat ), [] );
ok( 'and the translation was left exactly as it was', $GLOBALS['terms'][8]['name'], $before );

echo "\nA TRANSLATION TYPED BY HAND IS RECORDED TOO\n";
// Nothing waiting, nothing sent: the description is typed on the editor and
// saved. It was written and NOT recorded — so it stayed "words have moved" for
// ever and the next batch paid to translate it again. The person typing it
// read the original in front of them: that is the source it was made from.
$GLOBALS['terms'][7]['description'] = 'Warm ones, for winter.';
$GLOBALS['wpdb']->written = [];
$dze_hand = DZE_Translate::accept( $cat, [ 'fr' => [ 'description' => 'Des chaudes, pour l\'hiver.' ] ] );
ok( 'the hand-typed field is written',          $GLOBALS['terms'][8]['description'] ?? '', 'Des chaudes, pour l\'hiver.' );
$dze_reg = json_decode( (string) $GLOBALS['termmeta'][8]['_dze_tr_src'], true );
ok( 'and recorded against the source as it stands',
	$dze_reg['description'] ?? '', md5( 'Warm ones, for winter.' ) );
ok( 'so it is not owed again',                  isset( DZE_Translate::obj_stale( $cat, 'fr' )['description'] ), false );

echo "\nA field WPML COPIES is never written here\n";
// The next custom-field sync puts the original's value straight back over it,
// so the words are lost and nothing on any screen says so.
$GLOBALS['posts'][40] = [ 'post_title' => 'Cap', 'post_content' => '', 'post_excerpt' => '', 'type' => 'product' ];
$prod = [ 'kind' => 'post', 'id' => 40, 'type' => 'product' ];
DZE_Translate::obj_write( $prod, 40, [ 'seo_title' => 'Titre', 'seo_desc' => 'Description' ] );
ok( 'the field WPML translates is written',     get_post_meta( 40, 'rank_math_title', true ), 'Titre' );
ok( 'the field WPML copies is left alone',      get_post_meta( 40, 'rank_math_description', true ), '' );

// =============================================================================
// THE THINGS NOTHING MAY EVER OFFER TO TRANSLATE
//
// WPML declares `attachment` translatable, and it means something quite
// different there than it does here: translating a media, for this module, is
// wp_insert_post() with the source type — a DUPLICATE attachment row per
// language. Three of this shop's sites had just been cleared of 24,531 such
// duplicates and 1.1 GB of database; one campaign over "Media" would have put
// every one of them back.
// =============================================================================
echo "\nMedia is never translated, whatever WPML says\n";
$GLOBALS['opts']['icl_sitepress_settings'] = [
	'custom_posts_sync_option' => [ 'product' => 2, 'post' => 1, 'page' => 1, 'attachment' => 1 ],
	'taxonomies_sync_option'   => [ 'product_cat' => 1, 'product_tag' => 1, 'pa_colour' => 1, 'translation_priority' => 1 ],
];
ok( 'WPML says media is translatable',
	(int) ( $GLOBALS['opts']['icl_sitepress_settings']['custom_posts_sync_option']['attachment'] ), 1 );
ok( 'and this module refuses anyway',     DZE_Wpml::is_translated_type( 'attachment' ), false );
ok( 'it is not in the list of types',     isset( DZE_Wpml::translatable_types()['attachment'] ), false );
ok( 'and never reaches the screen',       isset( DZE_Translate::scope()['post:attachment'] ), false );
// WPML's own internal taxonomy: translating the word "urgent" helps nobody.
ok( 'WPML priority is refused too',       DZE_Wpml::is_translated_taxonomy( 'translation_priority' ), false );
ok( 'and is off the screen',              isset( DZE_Translate::scope()['term:translation_priority'] ), false );
// The real ones are still there — a guard that took everything with it would
// be worse than the fault.
ok( 'products are still translated',      DZE_Wpml::is_translated_type( 'product' ), true );
ok( 'and the colour attribute too',       DZE_Wpml::is_translated_taxonomy( 'pa_colour' ), true );

echo "\nA third of the product text was never sent\n";
// The theme keeps two written blocks in custom fields — 614,295 characters on
// this catalogue against 1,849,581 in post_content — and none of it travelled,
// so a French product page came out a third in English.
$dze_fields = DZE_Translate::fields( 'post' );
ok( 'the first content block is a field',  isset( $dze_fields['block_text_1'] ), true );
ok( 'and the second',                      isset( $dze_fields['block_text_2'] ), true );
ok( 'each one names its own custom field', [ $dze_fields['block_text_1']['key'], $dze_fields['block_text_2']['key'] ], [ 'block_text_1', 'block_text_2' ] );
ok( 'and they carry HTML, like a description', $dze_fields['block_text_1']['html'], true );
// DEAD FIELDS ARE NOT ADDED. `_purchase_note` and `_button_text` were in the
// original specification and are empty on all 2,105 English products here: a
// field on a screen is a decision somebody takes every time they read it.
ok( 'the purchase note is not offered',    isset( $dze_fields['_purchase_note'] ), false );
ok( 'nor the button text',                 isset( $dze_fields['_button_text'] ), false );
// And they are really READ off the product, not merely listed.
$GLOBALS['posts'][60] = [ 'post_title' => 'Cap', 'post_content' => 'Body.', 'post_excerpt' => '', 'type' => 'product' ];
$GLOBALS['meta'][60]['block_text_1'] = '<p>Made in Europe.</p>';
$dze_prod = [ 'kind' => 'post', 'id' => 60, 'type' => 'product' ];
ok( 'a block the product holds is read',
	( DZE_Translate::obj_read( $dze_prod )['block_text_1'] ?? '' ), '<p>Made in Europe.</p>' );
DZE_Translate::obj_write( $dze_prod, 60, [ 'block_text_1' => '<p>Fabriqué en Europe.</p>' ] );
ok( 'and written back onto the translation',
	get_post_meta( 60, 'block_text_1', true ), '<p>Fabriqué en Europe.</p>' );

echo "\nThe list is the shop's own, and six things are always in it\n";
$GLOBALS['opts']['dze_translate_settings'] = [];
$dze_pick = DZE_Translate::picked_scope();
foreach ( [ 'post:page', 'post:post', 'post:product', 'term:product_cat', 'term:product_tag' ] as $dze_k ) {
	ok( $dze_k . ' is always translated',   isset( $dze_pick[ $dze_k ] ), true );
}
// An attribute is in by the RULE, never by name: a shop adds one next month
// and a list written today would not have it.
ok( 'and every product attribute with them', isset( $dze_pick['term:pa_colour'] ), true );
ok( 'a new attribute is in by the rule too', DZE_Translate::is_always( 'term:pa_material' ), true );
// Everything else starts OUT and is a tick.
$GLOBALS['opts']['icl_sitepress_settings']['custom_posts_sync_option']['acme_doc'] = 1;
ok( 'an optional type is offered',        isset( DZE_Translate::scope()['post:acme_doc'] ), true );
ok( 'and is not translated until ticked', isset( DZE_Translate::picked_scope()['post:acme_doc'] ), false );
$GLOBALS['opts']['dze_translate_settings'] = [ 'scope' => [ 'post:acme_doc' ] ];
ok( 'ticked, it joins the list',          isset( DZE_Translate::picked_scope()['post:acme_doc'] ), true );
ok( 'and the six are still there',        isset( DZE_Translate::picked_scope()['post:product'] ), true );
// THE SETTING IS WRITTEN ONLY WHEN THE FORM CARRIED IT, or another tab's save
// empties the shop's list without anybody touching it.
$dze_tr = DZE_Translate::instance();
$dze_tr->sanitize( [ 'glossary' => 'MOLLE' ] );
ok( 'another form saving does not empty the list',
	DZE_Translate::get_settings()['scope'] ?? null, [ 'post:acme_doc' ] );
ok( 'and a sanitizer called with null keeps everything',
	( $dze_tr->sanitize( null )['scope'] ?? null ), [ 'post:acme_doc' ] );
// Unticking the last one IS an answer, and must be storable.
$dze_out = $dze_tr->sanitize( [ 'scope_sent' => 1 ] );
ok( 'unticking everything is kept',       $dze_out['scope'], [] );
$GLOBALS['opts']['dze_translate_settings'] = [];

echo "\nWhere an object stands is WPML's answer first\n";
// "Tout est marqué words have moved." It was: the register lives on the
// translation, and ten thousand translations made through a spreadsheet have
// none, so every field of every one of them looked new. True about our
// register, useless about the shop.
$GLOBALS['terms'] = [
	7 => [ 'name' => 'Balaclavas', 'description' => 'Warm.', 'taxonomy' => 'product_cat', 'parent' => 0, 'term_taxonomy_id' => 1007 ],
	8 => [ 'name' => 'Cagoules',   'description' => 'Chaud.', 'taxonomy' => 'product_cat', 'parent' => 0, 'term_taxonomy_id' => 1008 ],
];
$cat2 = DZE_Translate::obj( 'term', 7 );
$GLOBALS['translated'][7]['fr'] = 8;
// WPML is satisfied: nothing for this module to say, whatever our register holds.
$marks = [ 1007 => [ 'fr' => 'done' ] ];
ok( 'a translation WPML is happy with is up to date',
	DZE_Translate::state_of( $cat2, [ 'fr' ], $marks ), [ 'fr' => 'done' ] );
// A language WPML has no row for has no translation at all.
ok( 'a language with no row is missing',
	DZE_Translate::state_of( $cat2, [ 'de' ], $marks ), [ 'de' => 'missing' ] );
// WPML marked it AND the words really moved: this is work.
$marks = [ 1007 => [ 'fr' => 'marked' ] ];
ok( 'marked with words that moved is work',
	DZE_Translate::state_of( $cat2, [ 'fr' ], $marks ), [ 'fr' => 'stale' ] );
// WPML marked it and NOT ONE WORD changed — the module's whole point, and it
// costs nothing to close.
DZE_Translate::remember( 8, DZE_Translate::obj_read( $cat2 ), $cat2 );
ok( 'marked with nothing moved is named as such',
	DZE_Translate::state_of( $cat2, [ 'fr' ], $marks ), [ 'fr' => 'noise' ] );
ok( 'and it says so in words',            DZE_Translate::state_said( 'noise' ), 'marked, nothing moved' );
// WPML could not be asked at all: the honest answer is the one thing still
// knowable, never "everything has moved".
ok( 'with no answer from WPML, only existence is claimed',
	DZE_Translate::state_of( $cat2, [ 'fr', 'de' ] ), [ 'fr' => 'done', 'de' => 'missing' ] );
// WPML indexes a term by its TERM TAXONOMY id, never its term id: one wrong
// number here and every mark silently belongs to another object.
ok( 'a term is looked up by its term taxonomy id', DZE_Translate::element_id_of( $cat2 ), 1007 );
ok( 'and a post by its own id', DZE_Translate::element_id_of( $dze_prod ), 60 );

echo "\nAnd the screen draws exactly that\n";
// DRAW THE SCREEN, never call the helper: calling state_of() proves the
// reading and nothing about whether the screen asks for it — which is how a
// block added to a screen can ship never executed.
$GLOBALS['opts']['icl_sitepress_settings'] = [
	'custom_posts_sync_option' => [ 'product' => 2, 'post' => 1, 'page' => 1, 'attachment' => 1 ],
	'taxonomies_sync_option'   => [ 'product_cat' => 1, 'pa_colour' => 1, 'translation_priority' => 1 ],
];
$GLOBALS['terms'] = [
	7 => [ 'name' => 'Balaclavas',     'description' => 'Warm.',  'taxonomy' => 'product_cat', 'parent' => 0, 'term_taxonomy_id' => 1007 ],
	8 => [ 'name' => 'Plate carriers', 'description' => 'Heavy.', 'taxonomy' => 'product_cat', 'parent' => 0, 'term_taxonomy_id' => 1008 ],
];
// 7 is marked by WPML; 8 is one WPML is satisfied with.
$GLOBALS['wpdb']->marks  = [
	[ 'src' => 1007, 'lang' => 'fr', 'needs' => 1 ],
	[ 'src' => 1008, 'lang' => 'fr', 'needs' => 0 ],
	[ 'src' => 1008, 'lang' => 'de', 'needs' => 0 ],
];
$GLOBALS['wpdb']->counts = [ [ 'lang' => 'en', 'n' => 2 ], [ 'lang' => 'fr', 'n' => 2 ] ];
$GLOBALS['wpdb']->due    = [ [ 'lang' => 'fr', 'n' => 14 ] ];
// SIXTY MORE THE SHOP HOLDS AND THIS SCREEN HAS NOTHING TO SAY ABOUT. They are
// what the pager used to count: the list paged EVERY object of the kind and
// then dropped, row by row, the ones WPML is satisfied with — "1 2 3 Next »
// page 1 à 3 mais seulement 2 lignes sont visibles. C'est bugé ?"
for ( $i = 100; $i < 160; $i++ ) {
	$GLOBALS['terms'][ $i ] = [ 'name' => 'Filler ' . $i, 'description' => '', 'taxonomy' => 'product_cat', 'parent' => 0, 'term_taxonomy_id' => 2000 + $i ];
}
// Only Balaclavas needs work, and WPML's tables are the ones that say so.
$GLOBALS['wpdb']->todo_ids = [ 7 ];
$GLOBALS['wpdb']->todo_sql = [];
$GLOBALS['paginate']       = [];
$_GET = [ 'tab' => 'batch', 'scope' => 'term:product_cat' ];
ob_start(); DZE_Translate::instance()->render_page(); $dze_page = (string) ob_get_clean();

// A PAGE WPML IS SATISFIED WITH HAS NOTHING TO DO ON THIS SCREEN.
ok( 'the marked category is listed',      false !== strpos( $dze_page, 'Balaclavas' ), true );
ok( 'and the satisfied one is not',       false !== strpos( $dze_page, 'Plate carriers' ), false );
// A FIGURE AND THE ROWS UNDER IT ANSWER THE SAME QUESTION. One row of work in
// a taxonomy of sixty-two means one page, not three.
ok( 'the narrowing happens in the query that pages',
	false !== strpos( $dze_page, '1 needs work' ), true );
ok( 'and no pager is drawn over a single row',
	false !== strpos( $dze_page, 'dze-pager' ), false );
ok( 'the sixty the screen has nothing to say about are not counted',
	(int) ( $GLOBALS['paginate']['total'] ?? 0 ), 0 );
// AND THE QUESTION IT ASKED: the source language, the target languages, and
// the narrowing itself. A call that asks the wrong thing cannot pass this.
$dze_ask = implode( ' ', $GLOBALS['wpdb']->todo_sql );
ok( 'it asked WPML for the source language',  false !== strpos( $dze_ask, "language_code = 'en'" ), true );
ok( 'named the taxonomy the way WPML does',   false !== strpos( $dze_ask, "'tax_product_cat'" ), true );
ok( 'and narrowed to what is missing or marked',
	false !== strpos( $dze_ask, 'HAVING' ), true );
ok( 'the way to everything is offered',   false !== strpos( $dze_page, 'Show them all' ), true );
// WPML'S OWN COUNT, beside the missing one: two different piles of work.
ok( "WPML's count of what is owed is on the dashboard",
	false !== strpos( $dze_page, '14 to update' ), true );
// THE FLAG SAYS THE LANGUAGE, ONCE. It carries the code inside it already.
ok( 'the language is drawn as a flag',    false !== strpos( $dze_page, 'dze-lang-code' ), true );
ok( 'and its native name is not repeated beside it',
	false !== strpos( $dze_page, '</span> Français' ), false );
// AND MEDIA IS NOWHERE ON IT.
ok( 'media is not on the screen',         false !== stripos( $dze_page, 'attachment' ), false );

// Everything, when it is asked for — the SAME query without the narrowing,
// never a second reading: two readings of one list is how a count and the rows
// under it start disagreeing.
$_GET['all'] = 1;
$GLOBALS['wpdb']->todo_ids = [ 7, 8 ];
$GLOBALS['wpdb']->todo_sql = [];
ob_start(); DZE_Translate::instance()->render_page(); $dze_all = (string) ob_get_clean();
ok( 'asked for everything, the satisfied one is back',
	false !== strpos( $dze_all, 'Plate carriers' ), true );
ok( 'and the way back to the work is offered',
	false !== strpos( $dze_all, 'Only what needs work' ), true );
$dze_ask_all = implode( ' ', $GLOBALS['wpdb']->todo_sql );
ok( 'it is the same query with the narrowing dropped',
	[ false !== strpos( $dze_ask_all, 'tax_product_cat' ), false !== strpos( $dze_ask_all, 'HAVING' ) ],
	[ true, false ] );
$_GET = [];
foreach ( range( 100, 159 ) as $dze_i ) { unset( $GLOBALS['terms'][ $dze_i ] ); }

echo "\nWhat this site does about media is READ from WPML, never decided here\n";
// "Tu ne devrais rien faire toi-même mais utiliser les réglages natifs WPML.
// Toi tu fais juste le pont." The exclusion stays — this module has no code
// that can translate a media correctly — but what the SITE is set to do is
// WPML's answer and the screen reports it rather than asserting one.
unset( $GLOBALS['opts']['_wpml_media'] );
ok( 'with the add-on absent, the shop is told so',
	DZE_Wpml::media_translation(), [ 'known' => false, 'duplicate' => false, 'translate' => false ] );
$GLOBALS['opts']['_wpml_media'] = [ 'new_content_settings' => [ 'duplicate_media' => 0, 'duplicate_featured' => 0 ] ];
ok( 'installed and switched off reads as off',
	DZE_Wpml::media_translation()['duplicate'], false );
ok( 'and is KNOWN, which is a different answer from absent',
	DZE_Wpml::media_translation()['known'], true );
$GLOBALS['opts']['_wpml_media'] = [ 'new_content_settings' => [ 'duplicate_media' => 1 ] ];
ok( 'duplication switched on reads as on',
	DZE_Wpml::media_translation()['duplicate'], true );
// A setting WPML renames in a future version must leave this saying nothing,
// never inventing a "no" the shop would act on.
$GLOBALS['opts']['_wpml_media'] = [ 'something_wpml_renamed' => [ 'x' => 1 ] ];
ok( 'an unknown shape is read as known but claims nothing',
	[ DZE_Wpml::media_translation()['known'], DZE_Wpml::media_translation()['duplicate'] ], [ true, false ] );

// AND WHATEVER IT SAYS, MEDIA IS STILL NOT SOMETHING THIS MODULE WRITES. The
// reason is not a policy: `obj_create()` is wp_insert_post() plus text, which
// over an attachment makes a library entry with no file behind it.
$GLOBALS['opts']['_wpml_media'] = [ 'new_content_settings' => [ 'duplicate_media' => 1 ] ];
$GLOBALS['opts']['icl_sitepress_settings']['custom_posts_sync_option']['attachment'] = 1;
ok( 'media is still never offered',       isset( DZE_Translate::scope()['post:attachment'] ), false );
ok( 'nor accepted as an object',          DZE_Wpml::is_translated_type( 'attachment' ), false );

echo "\nAnd the settings screen says which of the three it is\n";
ob_start(); DZE_Translate::render_settings(); $dze_set = (string) ob_get_clean();
ok( 'the screen names where media is handled',
	false !== strpos( $dze_set, 'Media is WPML' ), true );
ok( 'and reports WPML is set to duplicate',
	false !== strpos( $dze_set, 'IS set to duplicate media' ), true );
$GLOBALS['opts']['_wpml_media'] = [ 'new_content_settings' => [ 'duplicate_media' => 0 ] ];
ob_start(); DZE_Translate::render_settings(); $dze_set = (string) ob_get_clean();
ok( 'switched off, it says images stay single',
	false !== strpos( $dze_set, 'stay single and shared' ), true );
unset( $GLOBALS['opts']['_wpml_media'] );
ob_start(); DZE_Translate::render_settings(); $dze_set = (string) ob_get_clean();
ok( 'absent, it says the add-on is not installed',
	false !== strpos( $dze_set, 'not installed on this site' ), true );
unset( $GLOBALS['opts']['icl_sitepress_settings']['custom_posts_sync_option']['attachment'] );


echo "\nA FILTER THAT IS NOT LOADED IS NOT AN ANSWER\n";
// "Sur page produit : The translation today > Pas affiché, bugé."
// `wpml_object_id` is a filter, and where WPML's hooks are not loaded —
// admin-ajax, cron — apply_filters hands back the id it was GIVEN. Read
// straight, the module then believed the object was its own translation: it
// reported the translation as existing and printed the ENGLISH text under
// "The translation today".
$GLOBALS['posts'][ 900 ] = [ 'type' => 'product', 'post_title' => 'Chest rig', 'post_content' => '<p>English.</p>', 'post_excerpt' => '' ];
$dze_rig = DZE_Translate::obj( 'post', 900, 'product' );
$GLOBALS['wpdb']->rows   = [];
$GLOBALS['wpml_filter_off'] = true;
ok( 'the object is never its own translation',
	DZE_Translate::obj_translation( $dze_rig, 'fr' ), 0 );
ok( 'so every field of it is owed',
	array_keys( DZE_Translate::obj_stale( $dze_rig, 'fr' ) ), [ 'title', 'content' ] );
$GLOBALS['wpml_filter_off'] = false;
// And with WPML answering, the translation is found as before.
$GLOBALS['posts'][ 901 ] = [ 'type' => 'product', 'post_title' => 'Gilet', 'post_content' => '<p>Français.</p>', 'post_excerpt' => '' ];
$GLOBALS['translated'][900]['fr'] = 901;
ok( 'and with WPML answering, it is found',
	DZE_Translate::obj_translation( $dze_rig, 'fr' ), 901 );

echo "\nWPML'S OWN THREE MARKS\n";
// "Utiliser les symboles wpml servant déjà à ilustrer ces status." A plus for
// what does not exist, a pencil for what WPML is happy with, two arrows for
// what it wants again — the marks anybody who has used WPML already reads.
ok( 'not translated is a plus',      DZE_Translate::state_icon( 'missing' ), 'dashicons-plus-alt2' );
ok( 'up to date is a pencil',        DZE_Translate::state_icon( 'done' ),    'dashicons-edit' );
ok( 'asking for an update is arrows',DZE_Translate::state_icon( 'stale' ),   'dashicons-update' );
// A LONE ICON IS A SYMBOL YOU HAVE TO LEARN: the word stays beside it.
ok( 'and the word is still printed beside it',
	false !== strpos( $dze_page, 'dashicons-plus-alt2' ) && false !== strpos( $dze_page, 'not translated' ), true );

echo "\nWHAT CAME BACK, ON THE ROW IT WAS SENT FROM\n";
// "Le post n'est pas passé automatiquement dans 'to review'. Sur la ligne des
// posts, aucune mention 'x to review'."
$GLOBALS['wpdb']->review_posts = [ [ 't' => 'product', 'n' => 3 ] ];
$GLOBALS['wpdb']->review_terms = [ [ 't' => 'product_cat', 'n' => 2 ] ];
ok( 'what waits is counted per kind, in WPML\'s naming',
	DZE_Translate::review_counts(), [ 'post_product' => 3, 'tax_product_cat' => 2 ] );
$_GET = [ 'tab' => 'dashboard' ];
ob_start(); DZE_Translate::instance()->render_page(); $dze_dash = (string) ob_get_clean();
// The figure AND the way to it, in the same element: "tab=review" alone is on
// the page whatever happens — it is the tab bar — and a check that passes on
// broken code is worse than none.
ok( 'and the dashboard row says so, with the way to read it',
	(bool) preg_match( '/tab=review[^>]*>\s*2 to review/', $dze_dash ), true );
$GLOBALS['wpdb']->review_posts = [];
$GLOBALS['wpdb']->review_terms = [];

echo "\nWHAT GETS TRANSLATED, PER KIND — WPML'S RULES, NOT A TICK BOX\n";
// "Which fields > Incohérence, je ne sais pas ce que ça fait là... Je propose
// un bloc qui puisse résumer quels champs sont traductibles, et lesquels ne le
// sont pas, en fonction du type de post."
$GLOBALS['opts']['icl_sitepress_settings']['translation-management'] = [
	'custom_fields_translation' => [
		'block_text_1'     => 1,   // WPML COPIES it: writing it would be undone.
		'block_text_2'     => 2,
		'_theme_subtitle'  => 2,   // WPML translates it and this module does not send it.
		'_price'           => 0,
	],
];
$dze_rep  = DZE_Translate::field_report( 'post', 'product' );
$dze_said = [];
foreach ( $dze_rep as $dze_r ) { $dze_said[ $dze_r['label'] ] = $dze_r['tone']; }
ok( 'a field WPML copies is reported as left alone', $dze_said['Content block 1'] ?? '', 'warn' );
ok( 'a field WPML translates is reported as sent',   $dze_said['Content block 2'] ?? '', 'ok' );
// A CUSTOM FIELD WPML WANTS TRANSLATED IS SENT, WHERE IT HOLDS TEXT — and the
// reading says so PER KIND. "Il est annoncé toute sorte de meta field qui
// n'ont aucun intérêt à traduire pour certaines pages, voire n'existent même
// pas": the list used to print every key of WPML's global map, for every
// kind, as a gap. A key nothing of this kind holds is not a line.
ok( 'a key nothing of this kind holds is not listed at all',
	isset( $dze_said['_theme_subtitle'] ) || isset( $dze_said['Theme subtitle'] ), false );
$GLOBALS['wpdb']->text_keys = [ [ 'meta_key' => '_theme_subtitle', 'n' => 12 ] ];
$GLOBALS['tr'] = [];
$dze_said = [];
foreach ( DZE_Translate::field_report( 'post', 'product' ) as $dze_r ) { $dze_said[ $dze_r['label'] ] = $dze_r; }
ok( 'a key this kind holds text in is listed, named for people',
	$dze_said['Theme subtitle']['tone'] ?? '', 'ok' );
ok( 'and says on how many',
	false !== strpos( (string) ( $dze_said['Theme subtitle']['said'] ?? '' ), '12' ), true );
ok( 'never as a gap',
	false !== strpos( (string) ( $dze_said['Theme subtitle']['said'] ?? '' ), 'does not send' ), false );
// A PAGE IS NOT TOLD ABOUT A PRODUCT'S FIELDS: the reading is per kind.
$GLOBALS['wpdb']->text_keys = [];
$GLOBALS['tr'] = [];
$dze_page = [];
foreach ( DZE_Translate::field_report( 'post', 'page' ) as $dze_r ) { $dze_page[ $dze_r['label'] ] = $dze_r; }
ok( 'a page holding none of it is told nothing about it', isset( $dze_page['Theme subtitle'] ), false );
ok( 'and a page\'s excerpt is called an excerpt, not a short description',
	isset( $dze_page['Excerpt'] ) && ! isset( $dze_page['Short description'] ), true );
ok( 'a field WPML ignores is not on the list',       isset( $dze_said['_price'] ), false );
// AND THE OBJECT: the field is READ where it holds words, and only there.
$GLOBALS['posts'][61] = [ 'post_title' => 'Cap', 'post_content' => 'Body.', 'post_excerpt' => '', 'type' => 'product' ];
$GLOBALS['meta'][61]['_theme_subtitle'] = 'Built for the field';
$GLOBALS['meta'][61]['_price']          = '49.90';
$GLOBALS['posts'][62] = [ 'post_title' => 'Hat', 'post_content' => 'Body.', 'post_excerpt' => '', 'type' => 'product' ];
$GLOBALS['meta'][62]['_theme_subtitle'] = 'field_5f3a1b2c';
$dze_o61 = [ 'kind' => 'post', 'id' => 61, 'type' => 'product' ];
$dze_o62 = [ 'kind' => 'post', 'id' => 62, 'type' => 'product' ];
ok( 'a WPML-translate field holding words is a field of the object',
	DZE_Translate::obj_read( $dze_o61 )['meta:_theme_subtitle'] ?? '', 'Built for the field' );
ok( 'named for the screen and the model alike',
	DZE_Translate::labels_for( $dze_o61 )['meta:_theme_subtitle'] ?? '', 'Theme subtitle' );
ok( 'a field WPML ignores is never read',        isset( DZE_Translate::obj_read( $dze_o61 )['meta:_price'] ), false );
ok( 'and one holding a field reference, not words, is not a field',
	isset( DZE_Translate::obj_read( $dze_o62 )['meta:_theme_subtitle'] ), false );
foreach ( [ '12', '2024-05-01', 'https://kula.test/x', 'a:1:{i:0;s:1:"x";}', '{"a":1}', '' ] as $dze_nt ) {
	ok( 'not words: ' . ( '' === $dze_nt ? '(empty)' : $dze_nt ), DZE_Translate::is_text( $dze_nt ), false );
}
ok( 'words are words',                            DZE_Translate::is_text( 'Built for the field' ), true );
// AND WRITTEN on accept, onto the translation, as text.
DZE_Translate::obj_write( $dze_o61, 61, [ 'meta:_theme_subtitle' => 'Conçu pour le terrain' ] );
ok( 'and written onto the translation',
	get_post_meta( 61, '_theme_subtitle', true ), 'Conçu pour le terrain' );
// A key WPML has since switched to COPY is not written: the next sync would
// put the original straight back over it.
$GLOBALS['opts']['icl_sitepress_settings']['translation-management']['custom_fields_translation']['_theme_subtitle'] = 1;
DZE_Translate::obj_write( $dze_o61, 61, [ 'meta:_theme_subtitle' => 'Autre' ] );
ok( 'a key WPML copies since is left alone',      get_post_meta( 61, '_theme_subtitle', true ), 'Conçu pour le terrain' );
$GLOBALS['opts']['icl_sitepress_settings']['translation-management']['custom_fields_translation']['_theme_subtitle'] = 2;
// THE SEO PAIR IS THE ONE FIELD WHOSE KEY DEPENDS ON A PLUGIN BEING THERE.
// "Translated" printed over a key that does not exist is a screen promising
// work nobody does.
ok( 'with an SEO plugin, the SEO pair is sent', $dze_said['SEO title']['tone'] ?? '', 'ok' );
$GLOBALS['no_seo'] = true;
$dze_noseo = [];
foreach ( DZE_Translate::field_report( 'post', 'product' ) as $dze_r ) { $dze_noseo[ $dze_r['label'] ] = $dze_r['tone']; }
ok( 'and with none, it says so rather than claiming the work',
	$dze_noseo['SEO title'] ?? '', 'off' );
$GLOBALS['no_seo'] = false;
// A TERM IS ITS NAME AND ITS DESCRIPTION, and the product's fields are not
// listed over it — which is the incoherence that got the old block thrown out.
$dze_term_rep = array_column( DZE_Translate::field_report( 'term', 'product_cat' ), 'label' );
ok( 'a taxonomy is reported on its own two fields', $dze_term_rep, [ 'Name', 'Description' ] );
// AND THE SCREEN DRAWS IT — calling the helper proves the reading and nothing
// about whether the screen asks for it.
ok( 'the dashboard prints the reading',
	false !== strpos( $dze_dash, 'What gets translated' ), true );
ob_start(); DZE_Translate::render_settings(); $dze_set = (string) ob_get_clean();
ok( 'and the settings page no longer asks the shop to choose',
	false !== strpos( $dze_set, 'Which fields' ), false );
$_GET = [];


echo "\nA PRODUCT IS MORE THAN ITS OWN FIVE FIELDS\n";
// "Produits : grosse lacune, les attributs ne sont pas gérés, les variations
// non plus. Testé sur produit avec trad en français."
$GLOBALS['posts'][ 700 ] = [ 'type' => 'product', 'post_title' => 'Field shirt', 'post_content' => '<p>A shirt.</p>', 'post_excerpt' => '' ];
$GLOBALS['posts'][ 701 ] = [ 'type' => 'product_variation', 'post_parent' => 700, 'post_title' => '', 'post_content' => '', 'post_excerpt' => '<p>The olive one has a black zip.</p>' ];
$GLOBALS['posts'][ 702 ] = [ 'type' => 'product_variation', 'post_parent' => 700, 'post_title' => '', 'post_content' => '', 'post_excerpt' => '' ];
$GLOBALS['postmeta'][ 701 ]['attribute_pa_colour'] = 'olive-drab';
$GLOBALS['terms'][ 50 ] = [ 'name' => 'Olive Drab', 'description' => '', 'taxonomy' => 'pa_colour', 'parent' => 0, 'term_taxonomy_id' => 1050 ];
$dze_shirt = DZE_Translate::obj( 'post', 700, 'product' );

// WHAT CHANGED, AND WHY. "On ne veut pas traduire les descriptions de
// variation, normalement c'est réglé comme ça dans WPML." It is: WPML
// decides per field what travels, and a shop that has told it to leave
// variation descriptions alone has answered already. Offering them here put
// ten empty boxes on the screen, each marked "words have moved", for text
// nobody intends to write. They are COUNTED and said, never offered.
$dze_vf = DZE_Translate::variation_fields( $dze_shirt );
ok( 'a variation description is not a field of its product', $dze_vf, [] );
$dze_tally = DZE_Translate::variation_tally( $dze_shirt );
ok( 'but the variations are counted, so the screen can say they exist',
	$dze_tally['total'], 2 );
ok( 'and how many of them hold words of their own',
	$dze_tally['with_text'], 1 );
// A VARIATION IS NAMED BY WHAT IT IS, never by its id: "#4182" is not a colour.
ok( 'and it is named by its attributes', DZE_Translate::variation_label( 701 ), 'Variation — Olive Drab' );
// IT DOES NOT TRAVEL WITH THE PRODUCT, and no screen asks about it.
$dze_read = DZE_Translate::obj_read( $dze_shirt );
ok( 'a variation\'s words are not read with the product',
	isset( $dze_read['var:701'] ), false );
ok( 'nor owed when nothing is translated',
	in_array( 'var:701', array_keys( DZE_Translate::obj_stale( $dze_shirt, 'fr' ) ), true ), false );
ok( 'nor named on any screen', isset( DZE_Translate::labels_for( $dze_shirt )['var:701'] ), false );
// THE NAMING STILL WORKS, because the count line uses it.
ok( 'a variation is still named by what it is, for the line that counts them',
	DZE_Translate::variation_label( 701 ), 'Variation — Olive Drab' );

// WRITING: onto the variation WooCommerce Multilingual made, never one of ours.
$GLOBALS['posts'][ 800 ] = [ 'type' => 'product', 'post_title' => 'Chemise', 'post_content' => '', 'post_excerpt' => '' ];
$GLOBALS['posts'][ 801 ] = [ 'type' => 'product_variation', 'post_parent' => 800, 'post_title' => '', 'post_content' => '', 'post_excerpt' => '' ];
$GLOBALS['translated'][700]['fr'] = 800;
$GLOBALS['translated'][701]['fr'] = 801;
$GLOBALS['post_lang'][800] = 'fr';
DZE_Translate::obj_write( $dze_shirt, 800, [ 'title' => 'Chemise de terrain', 'var:701' => '<p>Le kaki a une fermeture noire.</p>' ] );
ok( 'the translated variation gets its own words',
	$GLOBALS['posts'][801]['post_excerpt'] ?? '', '<p>Le kaki a une fermeture noire.</p>' );
ok( 'and the product keeps its own',
	$GLOBALS['posts'][800]['post_title'] ?? '', 'Chemise de terrain' );
// A VARIATION WCML HAS NOT MADE IS LEFT, never invented here: linking one is
// WCML's job and doing it a second way is two plugins on one row.
$dze_was = count( $GLOBALS['posts'] );
DZE_Translate::obj_write( $dze_shirt, 800, [ 'var:702' => 'Rien' ] );
ok( 'a variation WCML has not made is not invented here', count( $GLOBALS['posts'] ), $dze_was );

echo "\nTRANSLATE WITH DAZONT ECOM, INSIDE WPML'S OWN LANGUAGE BOX\n";
// "Peut être ajouter directement une option par dessus wpml sur les blocs wpml
// de traduction... 'Translate with Dazont Ecom'. Ce serait notre marque de
// fabrique."
$GLOBALS['editing_id'] = 700;
$GLOBALS['screen'] = (object) [ 'id' => 'product', 'post_type' => 'product', 'base' => 'post', 'taxonomy' => '' ];
ok( 'the screen knows which object it is standing on',
	DZE_Translate::editing_object(), [ 'kind' => 'post', 'id' => 700, 'type' => 'product' ] );
$GLOBALS['loc'] = [];
DZE_Translate::instance()->box_assets( 'post.php' );
ok( 'the button is asked for on a product', isset( $GLOBALS['loc']['dzeTrBox'] ), true );
// ONE SCREEN FOR EVERY KIND OF OBJECT. A product used to get a popup of its own
// here — a second per-object surface beside the module's own screen: "cet écran
// c'est encore du custom. Je veux un seul écran pour chaque type de post."
ok( 'and a product is sent to that same one screen, like everything else',
	false !== strpos( (string) ( $GLOBALS['loc']['dzeTrBox']['url'] ?? '' ), 'ref=post%3A700%3Aproduct' ), true );
// A BUTTON ON ONE OBJECT OPENS THE FUNCTION, IT DOES NOT RUN ONE.
$GLOBALS['screen'] = (object) [ 'id' => 'term', 'post_type' => '', 'base' => 'term', 'taxonomy' => 'product_cat' ];
$_GET['tag_ID'] = 7;
$GLOBALS['loc'] = [];
DZE_Translate::instance()->box_assets( 'term.php' );
$dze_box = (array) ( $GLOBALS['loc']['dzeTrBox'] ?? [] );
ok( 'on a category it points at the screen that does this work',
	false !== strpos( (string) ( $dze_box['url'] ?? '' ), DZE_Translate::MENU_SLUG ), true );
// ARMED ON THAT OBJECT, or the destination is a list of nine hundred rows.
ok( 'opened on that one object',
	false !== strpos( (string) ( $dze_box['url'] ?? '' ), 'ref=term%3A7%3Aproduct_cat' ), true );
// A TYPE THE SHOP DOES NOT TRANSLATE GETS NO BUTTON: its destination would be
// a screen that does not list it.
$GLOBALS['screen'] = (object) [ 'id' => 'term', 'post_type' => '', 'base' => 'term', 'taxonomy' => 'product_type' ];
$GLOBALS['loc'] = [];
DZE_Translate::instance()->box_assets( 'term.php' );
ok( 'and a taxonomy WPML does not translate gets none',
	isset( $GLOBALS['loc']['dzeTrBox'] ), false );
unset( $_GET['tag_ID'] );
$GLOBALS['screen'] = null;

echo "\nAND THE SCREEN IT OPENS SHOWS THAT ONE OBJECT, TICKED\n";
$GLOBALS['wpdb']->marks = [ [ 'src' => 1007, 'lang' => 'fr', 'needs' => 1 ] ];
$_GET = [ 'tab' => 'batch', 'scope' => 'term:product_cat', 'only' => 'term:7:product_cat' ];
ob_start(); DZE_Translate::instance()->render_page(); $dze_one = (string) ob_get_clean();
ok( 'the object it was opened for is on the screen',
	false !== strpos( $dze_one, 'data-ref="term:7:product_cat"' ), true );
ok( 'and it is already ticked',
	(bool) preg_match( '/dze-tr-pickone[^>]*checked/', $dze_one ), true );
ok( 'with nothing else in the way',
	substr_count( $dze_one, 'class="dze-tr-row"' ), 1 );
ok( 'and the way back to the whole list',
	false !== strpos( $dze_one, 'Show everything that needs work' ), true );
// WPML'S OWN GESTURE, one language at a time.
ok( 'a language that is owed is a button, not a label',
	(bool) preg_match( '/<button[^>]*dze-tr-one[^>]*data-lang="fr"/', $dze_one ), true );
$_GET = [];


echo "\nTHE BATCH IS A SCREEN OF ITS OWN, IN THE SHAPE EVERY OTHER ONE WEARS\n";
// "Send a batch — incomplet et pas bon pour l'UI. Ici je verrais plutôt une
// liste séparée comme avec les produits… Utiliser le même type de dashboard que
// pour les bulk content generation."
$GLOBALS['wpdb']->todo_ids = [ 7, 8 ];
$GLOBALS['wpdb']->marks    = [ [ 'src' => 1007, 'lang' => 'fr', 'needs' => 1 ] ];
$_GET = [ 'tab' => 'batch', 'scope' => 'term:product_cat' ];
ob_start(); DZE_Translate::instance()->render_page(); $dze_b = (string) ob_get_clean();
// 1. WHAT IT HOLDS TODAY, in one line, with the figures.
ok( 'it opens with what this kind holds today',
	false !== strpos( $dze_b, 'dze-tr-holds' ), true );
// 2. ONE BLOCK PER KIND OF WORK, its switch in its own title — the SAME
// machinery as the product screen, which is why DZE_Hub draws it.
ok( 'the languages are a block with a take-all in its own heading',
	(bool) preg_match( '/data-sec="langs".*?dze-sec-tick.*?dze-sec-all/s', $dze_b ), true );
ok( 'and what is sent with each one is a block of its own',
	false !== strpos( $dze_b, 'data-sec="fields"' ), true );
ok( 'each language says how many are short of it',
	(bool) preg_match( '/dze-tr-lang[^>]*value="fr"/', $dze_b ), true );
// 3. ONE BUTTON THAT RUNS WHAT IS TICKED, with the bill beside it.
ok( 'one button runs what is ticked',   substr_count( $dze_b, 'id="dze-tr-send"' ), 1 );
ok( 'and it says what it is about to do', false !== strpos( $dze_b, 'id="dze-tr-bill"' ), true );
ok( 'a long run can be stopped',        false !== strpos( $dze_b, 'id="dze-tr-stop"' ), true );
// 4. THE LIST, with the bar the bulk screen wears and Look on every row.
ok( 'the bar is the bulk screen\'s own', false !== strpos( $dze_b, 'dze-cb-listbar' ), true );
ok( 'every row offers to be opened', substr_count( $dze_b, 'dze-tr-openword' ), 2 );
// THE OBJECT'S ID, IN A COLUMN OF ITS OWN, ON EVERY LIST THAT NAMES OBJECTS —
// the same column, from the same place, as the product bulk screens and the
// diagnostic. The heading is declared in one place and the cell in another, so
// BOTH are read here, in the order a reader meets them: a table a column out
// of step prints every value under the wrong title and raises nothing.
ok( 'every row carries its own id',   substr_count( $dze_b, 'class="dze-objid"' ), 2 );
ok( 'the id has a heading of its own, right after the name',
	(bool) preg_match( '#<th>Name</th>\s*<th class="dze-objid-th">ID</th>#', $dze_b ), true );
ok( 'and the cell under it is the row\'s own',
	(bool) preg_match( '#<tr class="dze-tr-row" data-ref="[a-z]+:(\d+):[^"]*">[\s\S]*?</td>\s*<td class="dze-objid-td"><code class="dze-objid"[^>]*>\1</code></td>#', $dze_b ), true );
ok( 'and says Open when nothing waits on it',
	false !== strpos( $dze_b, '>Open<' ), true );
// 5. AND IT IS A WAY TO THE ONE SCREEN, never a panel of its own: two
// per-object surfaces is how two screens start disagreeing about one object.
ok( 'the row is a way to the one translation screen',
	substr_count( $dze_b, 'tab=batch&ref=term%3A7%3Aproduct_cat' ), 1 );
ok( 'and no panel unfolds inside the list any more',
	false !== strpos( $dze_b, 'class="dze-tr-panel"' ), false );
// THE DASHBOARD NO LONGER UNFOLDS THE LIST UNDER ITSELF.
$_GET = [ 'tab' => 'dashboard', 'scope' => 'term:product_cat' ];
ob_start(); DZE_Translate::instance()->render_page(); $dze_d = (string) ob_get_clean();
ok( 'the dashboard no longer unfolds the batch under itself',
	false !== strpos( $dze_d, 'id="dze-tr-send"' ), false );
ok( 'it sends you to the batch screen instead',
	false !== strpos( $dze_d, 'tab=batch' ), true );
// A ROW THAT HOLDS SOMETHING SAYS **REVIEW**, never Look — the same two words
// the product bulk screen uses, read from what is actually stored.
$GLOBALS['termmeta'][7]['_dze_tr_wait'] = wp_json_encode( [ 'at' => time(), 'langs' => [ 'fr' => [ 'name' => 'Cagoules' ] ], 'src' => [ 'name' => 'Balaclavas' ] ] );
$GLOBALS['wpdb']->waiting_terms = [ [ 'oid' => 7, 'v' => $GLOBALS['termmeta'][7]['_dze_tr_wait'] ] ];
$_GET = [ 'tab' => 'batch', 'scope' => 'term:product_cat' ];
ob_start(); DZE_Translate::instance()->render_page(); $dze_b2 = (string) ob_get_clean();
ok( 'a row holding something says Review', false !== strpos( $dze_b2, '>Review<' ), true );
ok( 'and the one beside it still says Open', false !== strpos( $dze_b2, '>Open<' ), true );
unset( $GLOBALS['termmeta'][7]['_dze_tr_wait'] );
$GLOBALS['wpdb']->waiting_terms = [];
$_GET = [];

echo "\nA FILTER THAT ANSWERS NOTHING IS NOT AN ANSWER — sixth time\n";
// The site's own reading: "_dze_tr_wait : 0 ligne… Aucun article n'a de
// registre, donc obj_stale() doit renvoyer TOUS les champs comme périmés. Le
// module n'avait aucune raison de répondre nothing had moved."
// It had one: `wpml_active_languages` answers NOTHING in admin-ajax, so there
// were no targets at all and produce() skipped every language in silence.
$GLOBALS['langs_off'] = true;
$GLOBALS['wpdb']->langs = [
	[ 'code' => 'en', 'english_name' => 'English', 'default_locale' => 'en_US', 'tag' => 'en' ],
	[ 'code' => 'fr', 'english_name' => 'French',  'default_locale' => 'fr_FR', 'tag' => 'fr' ],
];
$dze_from_table = DZE_Wpml::get_active_languages();
ok( 'with the filter silent, the languages come from WPML\'s table',
	array_column( $dze_from_table, 'code' ), [ 'en', 'fr' ] );
ok( 'and they are named', $dze_from_table[1]['native_name'] ?? '', 'Français' );
// AND THE JOB THEN RUNS. This is the whole bug: an article with no register at
// all came back "nothing had moved".
$GLOBALS['posts'][ 910 ] = [ 'type' => 'post', 'post_title' => 'MOLLE pouches', 'post_content' => '<p>How to set one up.</p>', 'post_excerpt' => '' ];
$dze_art = DZE_Translate::obj( 'post', 910, 'post' );
ok( 'an article with no register is entirely owed',
	array_keys( DZE_Translate::obj_stale( $dze_art, 'fr' ) ), [ 'title', 'content' ] );
// The answer the model gives for THIS object, keyed by the fields that were
// actually sent — a fake that answers a fixed shape proves nothing about what
// travelled.
$GLOBALS['model_answer'] = wp_json_encode( [ 'title' => 'Poches MOLLE', 'content' => '<p>Comment en installer une.</p>' ] );
$dze_job = DZE_Translate::produce( $dze_art, [ 'fr' ] );
ok( 'and the job actually sends it', array_keys( (array) $dze_job['langs'] ), [ 'fr' ] );

// IT FAILS LOUDLY RATHER THAN QUIETLY: a language nobody can resolve is an
// error on screen, never a silent skip that reads as "nothing had moved".
$dze_bad = DZE_Translate::produce( $dze_art, [ 'zz' ] );
ok( 'a language WPML does not offer is an error, not a silence',
	array_keys( (array) $dze_bad['errors'] ), [ 'zz' ] );
ok( 'and nothing was sent for it', $dze_bad['langs'], [] );
unset( $GLOBALS['model_answer'] );
$GLOBALS['langs_off'] = false;
$GLOBALS['wpdb']->langs = [];

echo "\nA VARIABLE PRODUCT WITHOUT ITS VARIATIONS IS NOT A PRODUCT\n";
// The site's own reading: "Résultat d'une traduction faite par le module sur un
// produit variable : product_type = variable, aucun axe déclaré, zéro
// variation. En front : This product is currently out of stock and
// unavailable… 163 des 277 produits non traduits de la boutique sont
// variables."
$GLOBALS['product_type'][700] = 'variable';
ok( 'a variable product is known to need them',
	DZE_Translate::needs_variations( $dze_shirt ), true );
ok( 'and a simple one is not', DZE_Translate::needs_variations( $dze_art ), false );
// WCML OWNS THAT JOB and is ASKED for it — never a second implementation here.
// THE AXES FIRST, AND THEY ARE WHAT THE VARIATIONS ARE BUILT ALONG. The first
// version of this bridge called sync_product_variations() with an EMPTY fourth
// argument — and that argument IS the axes, so WCML built nothing and the
// product stayed unavailable: "les attributs produits et les variations ne sont
// toujours pas là sur le produit traduit."
$GLOBALS['meta'][700]['_product_attributes'] = [ 'pa_colour' => [ 'name' => 'pa_colour', 'is_variation' => 1 ] ];
$GLOBALS['wcml_asked'] = [];
ok( 'WooCommerce Multilingual is the one asked to build them',
	DZE_Translate::sync_product( 700, 800, 'fr' ), 'attributes+variations' );
ok( 'the attributes are copied before the variations are built',
	array_column( $GLOBALS['wcml_asked'], 0 ), [ 'sync_product_attr', 'sync_product_variations' ] );
ok( 'each with the original, the translation and the language',
	[ $GLOBALS['wcml_asked'][1][1], $GLOBALS['wcml_asked'][1][2], $GLOBALS['wcml_asked'][1][3] ],
	[ 700, 800, 'fr' ] );
// THE FOURTH ARGUMENT IS THE AXES, AND IT IS NEVER EMPTY.
ok( 'and the variations are built along the axes, never against nothing',
	array_keys( (array) $GLOBALS['wcml_asked'][1][4] ), [ 'pa_colour' ] );
// WITH WCML'S ATTRIBUTE STEP MISSING, the axes are read straight off the
// original rather than handing WCML an empty array.
$GLOBALS['no_wcml_attrs'] = true;
$GLOBALS['wcml_asked']    = [];
ok( 'without WCML\'s attribute step the axes come off the original',
	array_keys( (array) ( $GLOBALS['wcml_asked'][0][4] ?? ( DZE_Translate::sync_product( 700, 800, 'fr' ) ? $GLOBALS['wcml_asked'][0][4] : [] ) ) ),
	[ 'pa_colour' ] );
$GLOBALS['no_wcml_attrs'] = false;
// THE WHOLE JOB IN ONE CALL where this WCML has it: one call WCML maintains is
// worth more than two we have to keep in step with it.
$GLOBALS['wcml_whole'] = true;
$GLOBALS['wcml_asked'] = [];
ok( 'a WCML that does the whole job is asked once',
	DZE_Translate::sync_product( 700, 800, 'fr' ), 'sync_product_data' );
ok( 'and the pieces are not asked for twice',
	array_column( $GLOBALS['wcml_asked'], 0 ), [ 'sync_product_data' ] );
$GLOBALS['wcml_whole'] = false;
// AND THE ANSWER IS READ OFF THE SHOP, never off what the call returned: WCML
// can be asked and still build nothing.
ok( 'the translation is then known to hold variations',
	DZE_Translate::synced_variations( $dze_shirt, 800 ), true );
$GLOBALS['posts'][ 900 ] = [ 'type' => 'product', 'post_title' => 'Vide', 'post_content' => '', 'post_excerpt' => '' ];
ok( 'and one that holds none says so',
	DZE_Translate::synced_variations( $dze_shirt, 900 ), false );
// WCML ABSENT ANSWERS FALSE rather than leaving a half-built product behind.
$GLOBALS['no_wcml'] = true;
ok( 'with WooCommerce Multilingual gone, it says it could not ask',
	DZE_Translate::sync_product( 700, 800, 'fr' ), '' );
$GLOBALS['no_wcml'] = false;


echo "\nONE SCREEN PER OBJECT, AND IT IS WPML'S FOUR STEPS\n";
// "wpml c'est 1/ post non traduit ou traduction pas à jour 2/ envoi en trad
// 3/ trad automatique ou sur écran de trad spécial individuel de tous les
// champs 4/ publication." That is the shape, and this is the third screen.
$GLOBALS['posts'][700] = [ 'type' => 'product', 'post_title' => 'Field shirt', 'post_content' => '<p>A shirt.</p>', 'post_excerpt' => '' ];
$GLOBALS['posts'][800] = [ 'type' => 'product', 'post_title' => 'Chemise', 'post_content' => '', 'post_excerpt' => '' ];
$GLOBALS['posts'][701] = [ 'type' => 'product_variation', 'post_parent' => 700, 'post_title' => '', 'post_content' => '', 'post_excerpt' => 'Olive, black zip.' ];
$GLOBALS['postmeta'][701]['attribute_pa_colour'] = 'olive-drab';
$GLOBALS['terms'][50] = [ 'name' => 'Olive Drab', 'description' => '', 'taxonomy' => 'pa_colour', 'parent' => 0, 'term_taxonomy_id' => 1050 ];
$GLOBALS['product_type'][700] = 'variable';
$GLOBALS['translated'][700]['fr'] = 800;
$GLOBALS['post_lang'][800] = 'fr';
$_GET = [ 'tab' => 'batch', 'ref' => 'post:700:product', 'lang' => 'fr' ];
ob_start(); DZE_Translate::instance()->render_page(); $dze_ed = (string) ob_get_clean();
// 1. WHERE THIS ONE STANDS, in the same four words the lists use.
ok( 'it says where this one stands',   false !== strpos( $dze_ed, 'dze-tr-editstate' ), true );
// The head of a screen is a HEADING, not a table, so the id is beside the name
// here rather than in a column — the same element, from the same function.
ok( 'and names the object by its id',
	(bool) preg_match( '#<code class="dze-objid"[^>]*>700</code>#', $dze_ed ), true );
// 3. TRANSLATE IT, or write it by hand — one button.
ok( 'it offers to translate it',       substr_count( $dze_ed, 'id="dze-tr-auto"' ), 1 );
// EVERY FIELD, SIDE BY SIDE, variations included and named for what they are.
ok( 'every field of the object is a row',
	substr_count( $dze_ed, 'class="dze-tr-field"' ), 2 );
// A VARIATION IS NOT ONE OF THEM. Its description is WPML's to carry or to
// leave; offering it here filled the screen with boxes for text nobody
// writes. It is counted in a sentence, never offered as a field.
ok( 'a variation\'s own words are not a row',
	false !== strpos( $dze_ed, 'data-field="var:701"' ), false );
ok( 'and its words are not printed either',
	false !== strpos( $dze_ed, 'Olive, black zip.' ), false );
// A FIELD THE ORIGINAL DOES NOT HOLD IS STILL LISTED, AND SAYS WHY.
//
// It used to be dropped — "a field the original does not hold is not a
// decision" — which was true and unreadable: an empty SEO pair and an SEO
// pair this module cannot handle both showed as nothing at all. "Pourquoi pas
// de traduction des champs seo ? j'ai l'impression qu'il manque plein de
// choses ici." An owner who knows WPML expects its editor: every field on the
// list, whether or not it carries words.
ok( 'an empty field of the original is still listed',
	false !== strpos( $dze_ed, 'data-field="excerpt"' ), true );
ok( 'but it is marked as holding nothing',
	false !== strpos( $dze_ed, 'dze-tr-field is-absent' ), true );
ok( 'and it says why, rather than being blank',
	false !== strpos( $dze_ed, 'class="dze-tr-why' ), true );
// AND IT IS NOT A DECISION EITHER: no box, so nothing to type into and
// nothing for the save to carry.
ok( 'and it offers no box to type in',
	substr_count( $dze_ed, 'class="dze-tr-new' ), substr_count( $dze_ed, 'class="dze-tr-field"' ) );
echo "\nLES INSTRUCTIONS SONT A PORTEE, ET LE GLOSSAIRE SE COMPTE\n";
// « Il faut un accès plus facile pour la modification du prompt
// d'instructions. La qualité des traductions n'est pas bonne. » Elles etaient
// sous Reglages > Translation, deux menus plus loin : on remarque une
// mauvaise traduction en en lisant une, pas en parcourant des preferences.
ob_start();
DZE_Translate::instructions_panel( '/wp-admin/admin.php?page=dazont-ecom-translations' );
$dze_ip = (string) ob_get_clean();
ok( 'le panneau existe',            false !== strpos( $dze_ip, 'dze-tr-instr' ), true );
ok( 'il porte le prompt',           false !== strpos( $dze_ip, 'dze-tr-instr-prompt' ), true );
ok( 'et le glossaire avec lui',     false !== strpos( $dze_ip, 'dze-tr-instr-gloss' ), true );
ok( 'il est replie, pas etale',     false !== strpos( $dze_ip, '<details' ), true );
// LA REGLE QUI NE SERVAIT A RIEN. Le prompt dit depuis toujours « ne jamais
// traduire un terme du glossaire » et le glossaire de cette boutique etait
// VIDE — donc rien n'etait protege, et aucun ecran ne le disait. « Viper hood
// jacket », ou « viper hood » nomme un camouflage de sniper et non une marque
// de capuche, revenait en « Veste à capuche Viper ».
ok( 'un glossaire vide se voit sur le repli',
	false !== strpos( $dze_ip, 'no term protected' ), true );
ok( 'et il est explique, pas seulement compte',
	false !== strpos( $dze_ip, 'the glossary is empty' ), true );
// ET IL NE PEUT PAS EFFACER LE RESTE : le sanitiseur part des reglages
// gardes et n'ecrase que les cles recues, donc ces deux champs suffisent.
$dze_keep = DZE_Translate::get_settings();
$dze_keep['model'] = 'gardez-moi';
update_option( DZE_Translate::OPT, $dze_keep );
$dze_after = DZE_Translate::instance()->sanitize( [ 'prompt' => 'court', 'glossary' => "viper hood\nghillie" ] );
ok( 'enregistrer les instructions ne touche pas au reste',
	(string) ( $dze_after['model'] ?? '' ), 'gardez-moi' );
ok( 'et le glossaire est bien pris',
	false !== strpos( (string) ( $dze_after['glossary'] ?? '' ), 'viper hood' ), true );

echo "\nUN BOUTON PAR BLOC, POUR CALIBRER\n";
// « Pour un calibrage plus facile il faut un bouton traduire par bloc. »
// Juger un changement du prompt ou du glossaire obligeait a renvoyer l objet
// entier et a le payer en entier, donc on le faisait une fois et jamais plus.
ok( 'chaque bloc porte son bouton',
	substr_count( $dze_ed, 'dze-tr-block"' ), substr_count( $dze_ed, 'class="dze-tr-new' ) );
// ET IL NE VOLE PAS LE NOM D UN AUTRE. « dze-tr-one » est deja le bouton par
// langue des lignes de la liste : deux gestionnaires sur une meme classe, et
// chaque clic en declenche deux.
ok( 'et il ne reprend pas le nom du bouton par langue',
	false !== strpos( $dze_ed, 'button-link dze-tr-one"' ), false );

// UN SEUL CHAMP PART, ET UN SEUL REVIENT.
$GLOBALS['calls'] = [];
$GLOBALS['model_answer'] = wp_json_encode( [ 'title' => 'Veste viper hood' ] );
$dze_one = DZE_Translate::produce( $dze_art, [ 'fr' ], false, 'title' );
ok( 'un seul champ revient',
	array_keys( (array) ( $dze_one['langs']['fr'] ?? [] ) ), [ 'title' ] );
ok( 'et un seul a ete envoye',
	substr_count( (string) ( $GLOBALS['calls'][0] ?? '' ), '### ' ), 1 );
// IL PART MEME QUAND RIEN N A BOUGE : on rejoue un bloc precisement parce
// qu il n a pas bouge — c est le prompt qui a change, pas le texte.
ok( 'et il part meme si le texte n a pas bouge',
	(bool) ( $dze_one['cost'] ?? false ), true );
// UN CHAMP INCONNU NE PAIE RIEN ET LE DIT.
$GLOBALS['calls'] = [];
$dze_bad = DZE_Translate::produce( $dze_art, [ 'fr' ], false, 'pas-un-champ' );
ok( 'un champ inconnu ne paie rien',    count( $GLOBALS['calls'] ), 0 );
ok( 'et il le dit plutot que de se taire', ! empty( $dze_bad['errors'] ), true );
// ET IL NE SOLDE PAS LA LANGUE : dire « a jour » parce qu un bloc est revenu
// marquerait tout le reste comme fait.
$dze_src2 = (string) file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-translate.php' );
ok( 'une passe sur un bloc ne solde pas la langue',
	false !== strpos( $dze_src2, "if ( ! \$all && '' === \$only ) {" ), true );
// ET ELLE SE FOND DANS CE QUI ATTEND au lieu de l ecraser : sinon un bloc
// rejoue effacerait les autres champs deja traduits et en attente.
ok( 'et elle se fond dans ce qui attend deja',
	false !== strpos( $dze_src2, "\$keep[ \$lg ] = array_merge(" ), true );

// 4. PUBLISH IT, or throw it away — side by side.
ok( 'it ends with save and cancel, side by side',
	[ substr_count( $dze_ed, 'id="dze-tr-publish"' ), substr_count( $dze_ed, 'id="dze-tr-drop"' ) ], [ 1, 1 ] );
// WHICH FIELDS MOVED is on the row — the module's whole value, and the only
// way to understand why "Translate automatically" left a field alone.
//
// BUT NOT KNOWING IS NOT HAVING MOVED. Here the translation exists and holds
// no register, so there is NOTHING to compare against — and the screen used
// to mark every field "words have moved since the last translation" on a
// product where nothing had moved at all. It now says, once, that it has no
// record, and marks no row.
ok( 'with no register, no row claims to have moved',
	substr_count( $dze_ed, 'dze-tr-moved' ), 0 );
// THE VARIATIONS ARE A PANEL, NOT A SENTENCE. "Je n'aime pas trop comment tu as
// fait c'est caché dans un petit texte. On ne lit jamais ces textes." WPML gives
// them a section of their own and so does this: what is worth showing is not
// their descriptions — WPML is set to leave those alone — but whether the
// translation HAS them, because a variable product with none tells the customer
// it is unavailable while every admin screen calls it translated.
ok( 'the variations get a panel of their own',
	substr_count( $dze_ed, '>Variations<' ), 1 );
ok( 'each one is a row, named by what it is',
	false !== strpos( $dze_ed, 'Variation — Olive Drab' ), true );
ok( 'and a variation the translation does not have is called missing',
	substr_count( $dze_ed, 'is-missing' ) >= 1, true );
ok( 'and the screen says why, once',
	substr_count( $dze_ed, 'no record of the words it was made from' ), 1 );
// WHOSE TRANSLATION THIS IS: one not written here is replaced by a save, and
// the screen says so before the press rather than in a string nobody printed.
ok( 'a translation not written here is said so',
	substr_count( $dze_ed, 'dze-tr-notmine' ), 1 );
$GLOBALS['meta'][800]['_dze_tr_by'] = '1';
ob_start(); DZE_Translate::instance()->render_page(); $dze_ed_mine = (string) ob_get_clean();
ok( 'and one written here is not',
	substr_count( $dze_ed_mine, 'dze-tr-notmine' ), 0 );
unset( $GLOBALS['meta'][800]['_dze_tr_by'] );
// CANCEL PUTS BACK WHAT THE TRANSLATION HOLDS: each field carries it.
ok( 'every field carries what the translation holds today, for Cancel',
	(bool) preg_match( '/data-field="title"[\s\S]*?data-was="Chemise"/', $dze_ed ), true );
// AND NOT ONE WORD OF PLUMBING. "Il ne nous dit pas qu'il copie les variations
// ou je ne sais quoi." No sync reported, no WCML named, no repair button.
foreach ( [ 'WooCommerce Multilingual', 'Rebuild', 'variations —', 'Attribute terms' ] as $dze_leak ) {
	ok( 'it never narrates its own plumbing: ' . $dze_leak,
		false !== strpos( $dze_ed, $dze_leak ), false );
}
// AND THE WAY BACK, because a screen you can only leave by the browser button
// is a screen that traps you.
ok( 'there is a way back to the list', false !== strpos( $dze_ed, 'Back to the list' ), true );
// OPENED WITHOUT A LANGUAGE, IT OPENS ON THE ONE THAT NEEDS WORK.
$GLOBALS['wpdb']->marks = [];
$_GET = [ 'tab' => 'batch', 'ref' => 'post:700:product' ];
ob_start(); DZE_Translate::instance()->render_page(); $dze_ed2 = (string) ob_get_clean();
ok( 'opened with no language it picks one that needs work',
	false !== strpos( $dze_ed2, 'dze-tr-editstate' ), true );
// THE PRODUCT POPUP IS GONE, and so is everything it carried.
ok( 'the popup no longer exists at all', method_exists( 'DZE_Translate', 'popup' ), false );
ok( 'nor its two handlers', method_exists( 'DZE_Translate', 'ajax_preview' ), false );
ok( 'nor the panel the lists used to unfold', method_exists( 'DZE_Translate', 'ajax_panel' ), false );
$_GET = [];

echo "\nA TEXT TOO LONG FOR ONE REPLY IS CUT, TRANSLATED, AND PUT BACK TOGETHER\n";
//
// The model answers in ONE reply and that reply has a ceiling. A 1,600-word
// article asked for more room than the ceiling allows, so the JSON came back
// cut in half and the whole object failed with "the model did not answer with
// the expected format" — the longest texts, the ones worth the most, were the
// only ones that never translated.
$tr_art = '';
for ( $p = 1; $p <= 26; $p++ ) {
	$tr_art .= "<!-- wp:paragraph -->\n<p>Paragraph $p. " . str_repeat( 'Des bottes tactiques tiennent debout. ', 12 ) . "</p>\n<!-- /wp:paragraph -->\n\n";
}
$tr_cut = DZE_Translate::split_text( $tr_art, 5000 );
ok( 'a long text is cut in pieces',        count( $tr_cut ) > 1, true );
ok( 'each piece fits under the ceiling',   max( array_map( 'mb_strlen', $tr_cut ) ) <= 5000, true );
ok( 'and the pieces are the text again',   implode( '', $tr_cut ) === $tr_art, true );
ok( 'no piece holds half a tag',           (bool) array_filter( $tr_cut, static function ( $p ) {
	return substr_count( $p, '<' ) !== substr_count( $p, '>' );
} ), false );
ok( 'a short text is not cut at all',      DZE_Translate::split_text( 'Trois mots.', 5000 ), [ 'Trois mots.' ] );
// A paragraph longer than the ceiling on its own still has to travel.
$tr_wall = str_repeat( 'Un mur de texte sans respiration aucune. ', 400 );
ok( 'a wall of text is cut too',           count( DZE_Translate::split_text( $tr_wall, 5000 ) ) > 1, true );
ok( 'and is still the text it was',        implode( '', DZE_Translate::split_text( $tr_wall, 5000 ) ) === $tr_wall, true );
// Accents count as one character, not two: cutting on bytes cuts them in half.
$tr_acc = str_repeat( 'Des chaussures françaises très éprouvées. ', 300 );
ok( 'an accented text survives the cut',   implode( '', DZE_Translate::split_text( $tr_acc, 5000 ) ) === $tr_acc, true );

// EACH PIECE IS ITS OWN CALL, and the answers go back in order.
$GLOBALS['calls'] = [];
$GLOBALS['model_answer_fn'] = static function ( string $user ): string {
	preg_match( '/### (\S+) /', $user, $m );
	$fid = $m[1] ?? '?';
	// Answer with a marker per piece, so the order can be read off the result.
	return (string) wp_json_encode( [ $fid => '[' . $fid . ']' ] );
};
$tr_got = DZE_Translate::translate( [ 'post:content' => $tr_art ], 'fr', 'post', [ 'post:content' => 'Article body' ] );
ok( 'one call per piece', count( $GLOBALS['calls'] ), count( $tr_cut ) );
$tr_want = '';
foreach ( array_keys( $tr_cut ) as $i ) { $tr_want .= '[post:content~p' . $i . ']'; }
ok( 'the pieces come back in order', $tr_got['post:content'] ?? '', $tr_want );
ok( 'and each piece said which part it was',
	false !== strpos( (string) end( $GLOBALS['calls'] ), 'part ' . count( $tr_cut ) . ' of ' . count( $tr_cut ) ), true );

// THE KEY IS THE FIELD ID, WHATEVER SHAPE IT COMES BACK IN. Handed
// `### post:content (Article body)`, a model answers now and again with the
// whole line as the key — and every word it translated was thrown away.
$GLOBALS['calls'] = [];
$GLOBALS['model_answer_fn'] = static function ( string $user ): string {
	preg_match( '/### (\S+) \(([^)]*)\)/', $user, $m );
	return (string) wp_json_encode( [ ( $m[1] ?? '?' ) . ' (' . ( $m[2] ?? '' ) . ')' => 'Traduit' ] );
};
$tr_got = DZE_Translate::translate( [ 'post:title' => 'Boots' ], 'fr', 'post', [ 'post:title' => 'Title' ] );
ok( 'a key with the label stuck to it is still the field', $tr_got['post:title'] ?? '', 'Traduit' );

// AND A PIECE THAT NEVER CAME BACK LEAVES THE FIELD ALONE. Half a description
// in two languages is worse than a description that was not translated.
$GLOBALS['calls'] = [];
$GLOBALS['model_answer_fn'] = static function ( string $user ): string {
	preg_match( '/### (\S+) /', $user, $m );
	$fid = $m[1] ?? '?';
	return (string) wp_json_encode( [ $fid => '~p1' === substr( $fid, -3 ) ? '' : '[' . $fid . ']' ] );
};
$tr_got = DZE_Translate::translate( [ 'post:content' => $tr_art ], 'fr', 'post', [ 'post:content' => 'Article body' ] );
ok( 'a piece that never came back drops the whole field', isset( $tr_got['post:content'] ), false );
ok( 'and the missing piece was asked for once more',
	count( $GLOBALS['calls'] ), count( $tr_cut ) + 1 );
unset( $GLOBALS['model_answer_fn'] );

echo "\nONE BAD ANSWER TAKES ITS OWN FIELDS DOWN, NOT THE WHOLE OBJECT\n";
//
// An Elementor page of 63 fields translated nothing at all because the seventh
// call answered badly — on a page whose first fifty-four fields were already
// translated and paid for.
$tr_many = [];
for ( $i = 0; $i < 8; $i++ ) { $tr_many[ 'meta:_f' . $i ] = str_repeat( "Champ $i. ", 90 ); }
$GLOBALS['calls'] = [];
$GLOBALS['model_answer_fn'] = static function ( string $user ): string {
	preg_match_all( '/### (\S+) /', $user, $m );
	// The batch holding _f7 answers rubbish; asked one field at a time it is fine.
	if ( in_array( 'meta:_f7', $m[1], true ) && count( $m[1] ) > 1 ) {
		return 'Je ne peux pas faire ça.';
	}
	$o = [];
	foreach ( $m[1] as $fid ) { $o[ $fid ] = '[' . $fid . ']'; }
	return (string) wp_json_encode( $o );
};
$tr_got = DZE_Translate::translate( $tr_many, 'fr', 'post', array_combine( array_keys( $tr_many ), array_keys( $tr_many ) ) );
ok( 'every field still comes back', count( $tr_got ), count( $tr_many ) );
ok( 'including the one in the bad batch', $tr_got['meta:_f7'] ?? '', '[meta:_f7]' );

// AND A FIELD THAT WILL NOT COME BACK AT ALL IS LEFT AS IT WAS.
$GLOBALS['model_answer_fn'] = static function ( string $user ): string {
	preg_match_all( '/### (\S+) /', $user, $m );
	if ( in_array( 'meta:_f7', $m[1], true ) ) { return 'Non.'; }
	$o = [];
	foreach ( $m[1] as $fid ) { $o[ $fid ] = '[' . $fid . ']'; }
	return (string) wp_json_encode( $o );
};
$tr_got = DZE_Translate::translate( $tr_many, 'fr', 'post', array_combine( array_keys( $tr_many ), array_keys( $tr_many ) ) );
ok( 'the field that never came back is left alone', isset( $tr_got['meta:_f7'] ), false );
ok( 'and the others are still translated',          count( $tr_got ), count( $tr_many ) - 1 );

// UN MORCEAU QUI CASSE DEUX FOIS NEMPORTE PAS LE RESTE. La reprise dun
// morceau manquant nétait pas protégée : elle jetait hors de translate(),
// par-dessus les champs déjà traduits et payés. La page annonçait « 0 sur 63 »
// alors que six de ses dix lots avaient réussi.
$tr_mix = [ 'meta:_court' => 'Bottes', 'meta:_long' => $tr_art ];
$GLOBALS['model_answer_fn'] = static function ( string $user ): string {
	preg_match_all( '/### (\S+) /', $user, $m );
	foreach ( $m[1] as $fid ) { if ( false !== strpos( $fid, '_long' ) ) { return 'Non.'; } }
	$o = []; foreach ( $m[1] as $fid ) { $o[ $fid ] = '[' . $fid . ']'; }
	return (string) wp_json_encode( $o );
};
$tr_got = DZE_Translate::translate( $tr_mix, 'fr', 'post', array_combine( array_keys( $tr_mix ), array_keys( $tr_mix ) ) );
ok( 'le champ court survit au champ long qui casse', $tr_got['meta:_court'] ?? '', '[meta:_court]' );
ok( 'et le champ long est laisse tel quel',        isset( $tr_got['meta:_long'] ), false );

// NOTHING AT ALL BACK IS AN ERROR, NOT AN EMPTY ANSWER.
$GLOBALS['model_answer_fn'] = static function (): string { return 'Non.'; };
$tr_why = '';
try {
	DZE_Translate::translate( [ 'meta:_a' => 'Bottes' ], 'fr', 'post', [ 'meta:_a' => 'A' ] );
} catch ( Throwable $e ) { $tr_why = $e->getMessage(); }
ok( 'nothing back at all says why', false !== stripos( $tr_why, 'expected format' ), true );

// A SENTENCE AROUND THE JSON IS NOT A FAILED CALL.
$GLOBALS['model_answer_fn'] = static function ( string $user ): string {
	preg_match( '/### (\S+) /', $user, $m );
	return "Voici la traduction :\n" . (string) wp_json_encode( [ $m[1] => 'Bottes' ] ) . "\nBonne journée.";
};
$tr_got = DZE_Translate::translate( [ 'meta:_a' => 'Boots' ], 'fr', 'post', [ 'meta:_a' => 'A' ] );
ok( 'JSON with a greeting around it still reads', $tr_got['meta:_a'] ?? '', 'Bottes' );
unset( $GLOBALS['model_answer_fn'] );

echo "\nSUR UNE PAGE ELEMENTOR, LA COPIE APLATIE NEST PAS DU TEXTE\n";
// `post_content` y est un vidage de la page RENDUE : tracés SVG, URL de
// vignettes, le shortcode des avis et ses quarante paramètres. Sur laccueil,
// 27 000 caractères contre 5 600 de vrais mots — cinq appels sur sept passés
// à traduire du balisage machine, et la page ne traduisait rien du tout.
ok( 'elementor_fields sait reconnaitre une page Elementor',
	method_exists( 'DZE_Translate', 'elementor_fields' ), true );
$tr_src = file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-translate.php' );
ok( 'et la copie aplatie est retiree quand il y en a',
	false !== strpos( $tr_src, "if ( \$el ) {\n\t\t\tunset( \$out['content'] );" ), true );
ok( 'apres la lecture des champs Elementor, pas avant',
	strpos( $tr_src, "unset( \$out['content'] )" ) > strpos( $tr_src, '$el = self::elementor_fields' ), true );

echo "\nLARBRE ELEMENTOR EST ECRIT EN DERNIER\n";
// wp_update_post() sur une traduction est un enregistrement, et un
// enregistrement est le moment ou WPML recopie depuis loriginal tout ce
// quil doit recopier, _elementor_data compris. Ecrit avant lui, larbre
// traduit repassait en anglais en sortant : 61 champs traduits, payes,
// ecrits, et identiques a la source une seconde plus tard.
$tr_src = file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-translate.php' );
$tr_up  = strpos( $tr_src, 'wp_update_post( $post );' );
$tr_el  = strpos( $tr_src, 'self::elementor_put( $target_id, $el );' );
ok( 'les deux ecritures sont bien la', $tr_up > 0 && $tr_el > 0, true );
ok( 'et larbre passe apres lenregistrement', $tr_el > $tr_up, true );

echo "\nUNE ANNULATION NE VIDE JAMAIS UNE PAGE\n";
// Sur une traduction créée dans la même passe, la page ne portait pas encore
// d'arbre Elementor quand obj_write a commencé : WPML le recopie pendant le
// wp_update_post() qui suit. Lu trop tôt, chaque champ était retenu comme
// vide — et l'annulation VIDAIT la page au lieu d'y remettre les mots
// d'origine. Soixante et un titres et paragraphes effacés, avec un
// « annulé » pour tout résultat.
$tr_src = file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-translate.php' );
$tr_get = strpos( $tr_src, "\$prev_el[ 'el:' . \$path ] = self::elementor_get(" );
$tr_put = strpos( $tr_src, 'self::elementor_put( $target_id, $el );' );
ok( 'ce qui va etre remplace est relu',        $tr_get > 0, true );
ok( 'et relu AVANT le remplacement',           $tr_get < $tr_put, true );
$tr_upd = strpos( $tr_src, 'wp_update_post( $post );' );
ok( 'donc apres que WPML ait recopie l\'arbre', $tr_get > $tr_upd, true );
ok( 'et un champ Elementor vide n\'est jamais remis',
	false !== strpos( $tr_src, "0 === strpos( (string) \$fid, 'el:' ) && '' === trim( (string) \$prev[ \$fid ] )" ), true );

echo "\nCE QUI EST MIS EN MEMOIRE DOIT POUVOIR EN RESSORTIR\n";
// update_post_meta() passe par wp_unslash(). JSON echappe chaque guillemet en
// \" et chaque barre oblique en \/ : deshabille de ses antislashes, il cesse
// d'etre du JSON. Le registre d'annulation — les mots que portait une
// traduction avant qu'on ecrive par-dessus — etait donc enregistre entier et
// revenait illisible chaque fois que le texte contenait un guillemet ou une
// adresse, c'est-a-dire toujours sur une boutique. 7 271 octets sur le disque,
// zero champ a la relecture, et un bouton « Annuler » qui n'avait rien
// derriere lui.
$tr_w = new ReflectionMethod( 'DZE_Translate', 'meta_write' );
$tr_w->setAccessible( true );
$tr_r = new ReflectionMethod( 'DZE_Translate', 'meta_read' );
$tr_r->setAccessible( true );
$tr_o = [ 'kind' => 'post', 'id' => 4242, 'type' => 'page' ];
$tr_hard = [
	'title'          => 'Il a dit "bonjour"',
	'el:abc:title'   => 'Voir https://kula.test/bottes-tactiques/ pour la suite',
	'meta:_x'        => "Une ligne\nune autre, et un antislash \\ tout seul",
];
$tr_w->invoke( null, $tr_o, 4242, '_dze_tr_prev', (string) wp_json_encode( $tr_hard ) );
$tr_back = json_decode( $tr_r->invoke( null, $tr_o, 4242, '_dze_tr_prev' ), true );
ok( 'ce qui ressort est bien du JSON', is_array( $tr_back ), true );
ok( 'et tous les champs sont la',      is_array( $tr_back ) ? count( $tr_back ) : 0, 3 );
ok( 'les guillemets sont intacts',     $tr_back['title'] ?? '', 'Il a dit "bonjour"' );
ok( 'les adresses aussi',              $tr_back['el:abc:title'] ?? '', 'Voir https://kula.test/bottes-tactiques/ pour la suite' );
ok( 'et les antislashes aussi',        $tr_back['meta:_x'] ?? '', "Une ligne\nune autre, et un antislash \\ tout seul" );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
