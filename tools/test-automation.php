<?php
/**
 * The automatic pass: what it takes, what it queues, and what stops it.
 *
 * Run before every release:  php tools/test-automation.php dazont-ecom
 *
 * This module had NO gate at all, which is remarkable for the one part of the
 * plugin that runs with nobody watching, writes to the shop and spends money.
 * Everything it does happens in cron: no screen shows it as it happens, no
 * click reveals it, and a fault here is discovered weeks later by its damage.
 *
 * The internal linking task is the one the shop runs, and the one the others
 * will be modelled on — "on va se baser sur son fonctionnement pour gérer le
 * reste" — so it is held to its whole contract here, end to end:
 *
 *   - it takes its work from the link GRAPH and carries the addresses the
 *     graph chose, so the pass writes THOSE links and not whatever the page
 *     would have picked on its own;
 *   - the job it queues is the pass that already writes that kind of page —
 *     `cat_links` for a category, `post_links` for an article — because there
 *     is no third linking engine and there must never be one;
 *   - it goes slowly on purpose: one item per tick, a figure per day, and
 *     never the same page twice within a month;
 *   - and it stops for the things that must stop it — the module switched
 *     off, the writing queue switched off, a copy of the shop, the monthly
 *     budget — before it touches anything at all.
 *
 * Two faults it is RED on, both found by reading the pass it is written for:
 *
 *   - A PASS THAT WAS NEVER QUEUED MUST NOT COUNT AS A PASS. The object was
 *     marked as worked on BEFORE the queue was asked, so a queue that refused
 *     the job — the row already waiting, the table gone — left the page
 *     stamped and locked out for three days having had nothing whatever done
 *     to it. Silent, and visible only as a page that never gets its links.
 *   - A TASK THAT HANDS ITS WORK TO THE QUEUE NEEDS THE QUEUE. `task_ready()`
 *     asked for it only when the row NAMES its job kind, and the linking task
 *     cannot name one — its kind depends on the page it lands on. So with the
 *     writing queue switched off the task read as ready, the screen offered
 *     it, and pressing Run answered "that category is already waiting in the
 *     queue", which is a sentence about a queue that is not there.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'DZE_VERSION', 'test' );
define( 'DZE_URL', 'https://kula.test/wp-content/plugins/dazont-ecom/' );
define( 'DZE_FILE', __FILE__ );

// --- WordPress, as much of it as the pass touches --------------------------
function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function esc_js( $s ) { return (string) $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_trim_words( $s, $n = 55, $more = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', (string) $s ), 0, $n ) ) . $more; }
function wp_list_pluck( $rows, $field ) { return array_map( static fn( $r ) => $r[ $field ] ?? null, (array) $rows ); }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function trailingslashit( $s ) { return untrailingslashit( $s ) . '/'; }
function wp_parse_url( $url, $c = -1 ) { return parse_url( (string) $url, $c ); }
function home_url( $p = '/' ) { return 'https://kula.test' . $p; }
function admin_url( $p = '' ) { return 'https://kula.test/wp-admin/' . $p; }
function plugins_url( $p = '', $f = '' ) { return 'https://kula.test/wp-content/plugins/' . $p; }
function current_time( $t ) { return 'Y-m-d' === $t ? gmdate( 'Y-m-d' ) : gmdate( 'Y-m-d H:i:s' ); }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function date_i18n( $f, $t = 0 ) { return gmdate( (string) $f, (int) $t ); }
function number_format_i18n( $n ) { return (string) $n; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function do_action( ...$a ) {}
function apply_filters( $tag, $value = null, ...$a ) { return $value; }
function wp_next_scheduled( $h ) { return time() + 1800; }
function wp_schedule_event( ...$a ) {}
function wp_clear_scheduled_hook( ...$a ) {}
function is_admin() { return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_kses_post( $s ) { return (string) $s; }
function absint( $v ) { return abs( (int) $v ); }
function wp_unslash( $v ) { return $v; }
function current_user_can( $c ) { return true; }
function check_ajax_referer( $a, $b = '', $die = true ) { return true; }
// The answer an AJAX action ends the request with, caught rather than exited:
// a handler that ends the request cannot be tested otherwise.
class DZE_Json_Sent extends Exception { public $payload; public $ok;
	public function __construct( $p, $ok ) { parent::__construct( 'sent' ); $this->payload = $p; $this->ok = $ok; } }
function wp_send_json_success( $d = null ) { throw new DZE_Json_Sent( $d, true ); }
function wp_send_json_error( $d = null, $c = 0 ) { throw new DZE_Json_Sent( $d, false ); }
function wp_enqueue_script( ...$a ) {}
// A BODY THAT MOVES TAKES ITS ASSETS WITH IT: the screen is drawn, and what
// it asked for is read back. Called through a page hook somewhere else, the
// one forgotten is the screen that comes out unstyled.
function wp_enqueue_style( ...$a ) { $GLOBALS['styles'][] = (string) ( $a[0] ?? '' ); }
function wp_localize_script( ...$a ) {}
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function get_locale() { return 'en_US'; }
function register_setting( ...$a ) {}
function settings_fields( $g ) {}
function submit_button( $t = '' ) { echo '<button>' . esc_html( $t ) . '</button>'; }
function wc_get_page_id( $w ) { return 0; }
function add_query_arg( $args, $url = '' ) {
	$q = [];
	foreach ( (array) $args as $k => $v ) { $q[] = $k . '=' . rawurlencode( (string) $v ); }
	return ( '' !== $url ? $url : 'https://kula.test/wp-admin/admin.php' ) . '?' . implode( '&', $q );
}
$GLOBALS['menu'] = [];
function add_submenu_page( $parent, $title, $label, $cap, $slug, $cb = null ) {
	$GLOBALS['menu'][] = [ 'parent' => $parent, 'label' => $label, 'slug' => $slug ];
	return $slug;
}
// Where the Linking tab lives. Without it the sentence that names that screen
// prints no link at all — and a message that names a screen and leaves you to
// find it is half an answer.
class DZE_Diagnostic { public const MENU_SLUG = 'dze-content'; }
class DZE_Restock { const MENU_SLUG = 'dazont-ecom-restock'; }
// checked() and disabled() answer what WordPress answers: stubbed to '' they
// hide the very questions the screen is drawn to answer.
function checked( $a, $b = true, $echo = true ) { return $a == $b ? " checked='checked'" : ''; }
function selected( $a, $b = true, $echo = true ) { return $a == $b ? " selected='selected'" : ''; }
function disabled( $a, $b = true, $echo = true ) { return $a == $b ? " disabled='disabled'" : ''; }

/**
 * How many products sit behind a category — the figure the size of its
 * description is judged against. It answers `found_posts`, which is the shape
 * the reader reads: a stub answering nothing would make every category read as
 * empty and the whole ranking meaningless.
 */
class WP_Query {
	public $found_posts = 0;
	public function __construct( $args = [] ) {
		$term = (int) ( $args['tax_query'][0]['terms'] ?? 0 );
		$this->found_posts = (int) ( $GLOBALS['behind'][ $term ] ?? 12 );
	}
}
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
// Two branches of categories and two articles, all long enough to carry a
// sentence — plus a page laid out by a page builder, which is a TARGET and
// never a source: its text is not in the post, so a link written into it
// would be stored and never appear.
$GLOBALS['terms'] = [
	10 => [ 'name' => 'Tactical bags',      'slug' => 'tactical-bags',      'parent' => 0,  'description' => '<p>Bags for the field. ' . str_repeat( 'a word about bags ', 40 ) . '</p>' ],
	11 => [ 'name' => 'Tactical backpacks', 'slug' => 'tactical-backpacks', 'parent' => 10, 'description' => '<p>Backpacks. ' . str_repeat( 'a word about backpacks ', 40 ) . '<a href="https://kula.test/category/tactical-bags/">Tactical bags</a></p>' ],
	12 => [ 'name' => 'Boonie hats',        'slug' => 'boonie-hats',        'parent' => 0,  'description' => '<p>Hats for the sun. ' . str_repeat( 'a word about hats ', 40 ) . '</p>' ],
	13 => [ 'name' => 'Tactical gloves',    'slug' => 'tactical-gloves',    'parent' => 0,  'description' => '<p>' . str_repeat( 'a word about gloves ', 40 ) . '</p>' ],
];
$GLOBALS['posts'] = [
	20 => [ 'type' => 'post', 'title' => 'How to choose a tactical backpack', 'content' => '<p>' . str_repeat( 'a backpack word ', 90 ) . '</p>' ],
	21 => [ 'type' => 'post', 'title' => 'Boonie hat sizing',                 'content' => '<p>' . str_repeat( 'a hat word ', 90 ) . '</p>' ],
	23 => [ 'type' => 'page', 'title' => 'Workshop history',                  'content' => '' ],
];

// THE FAKE SHOP AS DECLARED. A section that empties it to test an empty
// screen must not leave the next one asserting against nothing — that is how
// a check passes on a list it never saw.
$GLOBALS['shop0'] = [ 'terms' => $GLOBALS['terms'], 'posts' => $GLOBALS['posts'] ];
$GLOBALS['pmeta'] = [ 23 => [ '_elementor_data' => '[{"elType":"widget","settings":{"title":"Since 1998"}}]' ] ];
$GLOBALS['tmeta'] = [];

function get_terms( $args = [] ) {
	$out = [];
	foreach ( $GLOBALS['terms'] as $id => $t ) {
		if ( isset( $args['parent'] ) && (int) $t['parent'] !== (int) $args['parent'] ) { continue; }
		if ( isset( $args['child_of'] ) && (int) $t['parent'] !== (int) $args['child_of'] ) { continue; }
		if ( ! empty( $args['exclude'] ) && in_array( $id, (array) $args['exclude'], true ) ) { continue; }
		$out[] = get_term( $id, 'product_cat' );
	}
	return $out;
}
function get_term( $id, $tax = '' ) {
	$t = $GLOBALS['terms'][ (int) $id ] ?? null;
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
function get_term_children( $id, $tax = '' ) {
	$out = [];
	foreach ( $GLOBALS['terms'] as $tid => $t ) {
		if ( (int) $t['parent'] === (int) $id ) { $out[] = $tid; }
	}
	return $out;
}
function get_edit_term_link( $id, $tax = '' ) { return 'https://kula.test/wp-admin/term.php?tag_ID=' . (int) $id; }
function get_edit_post_link( $id, $ctx = '' ) { return 'https://kula.test/wp-admin/post.php?post=' . (int) $id; }
function get_post( $id ) {
	$p = $GLOBALS['posts'][ (int) $id ] ?? null;
	return $p ? (object) [ 'ID' => (int) $id, 'post_title' => $p['title'], 'post_content' => $p['content'], 'post_type' => $p['type'] ] : null;
}
function get_post_type( $id ) { return $GLOBALS['posts'][ (int) $id ]['type'] ?? ''; }
// A page that has no address is a real answer, not a missing stub: a control
// that cannot act must not be drawn.
function get_permalink( $id ) {
	if ( isset( $GLOBALS['permalinks'][ (int) $id ] ) ) { return (string) $GLOBALS['permalinks'][ (int) $id ]; }
	return 'https://kula.test/' . ( 'page' === get_post_type( $id ) ? '' : 'blog/' ) . $id . '/';
}

// The two registers the pass writes on the object itself: when each task last
// worked on it, and the text one that saves straight to the shop replaced.
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['pmeta'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $v ) { $GLOBALS['pmeta'][ (int) $id ][ $key ] = $v; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['pmeta'][ (int) $id ][ $key ] ); return true; }
function get_term_meta( $id, $key = '', $single = false ) { return $GLOBALS['tmeta'][ (int) $id ][ $key ] ?? ''; }
function update_term_meta( $id, $key, $v ) { $GLOBALS['tmeta'][ (int) $id ][ $key ] = $v; return true; }
function delete_term_meta( $id, $key ) { unset( $GLOBALS['tmeta'][ (int) $id ][ $key ] ); return true; }
function delete_metadata( ...$a ) { return true; }
function wp_update_term( $id, $tax, $args ) {
	$GLOBALS['terms'][ (int) $id ]['description'] = (string) ( $args['description'] ?? '' );
	return [ 'term_id' => (int) $id ];
}
function wp_update_post( $args, $err = false ) {
	$GLOBALS['posts'][ (int) $args['ID'] ]['content'] = (string) ( $args['post_content'] ?? '' );
	return (int) $args['ID'];
}

/** The posts table, and the mesh's own. */
class DZE_Auto_Wpdb {
	public $prefix = 'wp_';
	public $posts  = 'wp_posts';
	public $rows   = [];
	public function get_charset_collate() { return ''; }
	public function prepare( $q, ...$a ) {
		foreach ( $a as $one ) {
			$q = preg_replace( '/%[dsf]/', is_int( $one ) ? (string) $one : "'" . $one . "'", (string) $q, 1 );
		}
		return $q;
	}
	public function query( $sql ) {
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
		if ( false !== stripos( $sql, 'from_kind, from_id, to_kind, to_id FROM' ) ) { return $this->rows; }
		if ( false !== stripos( $sql, 'WHERE to_kind' ) ) { return $this->rows; }
		return [];
	}
	public function get_var( $sql ) { return false !== stripos( $sql, 'COUNT(*)' ) ? count( $this->rows ) : null; }
	public function insert( $t, $row ) { return true; }
}
$GLOBALS['wpdb'] = new DZE_Auto_Wpdb();
$wpdb            = $GLOBALS['wpdb'];

/** The writing queue: what was asked of it, and nothing done. */
class DZE_Queue {
	public const NONCE = 'dze_queue';
	public static array $added = [];
	public static bool $refuse = false;
	public static function add( string $kind, array $ids, bool $auto = false, array $payload = [] ): int {
		if ( self::$refuse ) { return 0; }
		self::$added[] = [ 'kind' => $kind, 'ids' => $ids, 'auto' => $auto, 'payload' => $payload ];
		return count( $ids );
	}
	/** What is already waiting on an object, in the shape the real one answers. */
	public static bool $pending_all = false;
	public static function pending_for( int $object_id, string $family = 'cat_' ): array {
		return self::$pending_all ? [ [ 'id' => 1, 'status' => 'queued' ] ] : [];
	}
	// The queue's own figures, which is all the progress bar reads: nothing is
	// remembered in the browser, so a reload draws the same bar.
	public static array $counts = [ 'queued' => 0, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
	public static int $worked = 0;
	public static function counts(): array { return self::$counts; }
	// The figures for ONE set of kinds — what this screen's own tasks queue.
	// The fake shop answers a DIFFERENT set for anything else, so a bar that
	// reads the whole queue cannot pass.
	public static array $counts_other = [ 'queued' => 0, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
	public static array $asked_kinds = [];
	public static function counts_for( array $kinds ): array {
		self::$asked_kinds = $kinds;
		return self::$counts;
	}
	public static function work( int $only = 0 ): void { self::$worked++; }
	// HOW LONG SINCE ANYTHING OF THESE KINDS MOVED — the one reading that tells
	// a run in progress from a run that has stopped.
	public static int $idle = 0;
	public static array $idle_kinds = [];
	public static function idle_for( array $kinds ): int { self::$idle_kinds = $kinds; return self::$idle; }
	/** Why the last few could not be written. */
	public static function failures( array $kinds, int $limit = 3 ): array {
		return array_slice( (array) ( $GLOBALS['failures'] ?? [] ), 0, $limit );
	}
	public static int $unlocked = 0;
	public static array $retried = [];
	public static function unlock(): void { self::$unlocked++; }
	public static function retry_failed( array $kinds ): int {
		self::$retried = $kinds;
		return (int) ( self::$counts['failed'] ?? 0 );
	}
	/** What a run called off drops: what waits its turn and what failed. */
	public static array $dropped = [];
	public static array $drop_rows = [];
	public static function drop_waiting( array $kinds ): int {
		self::$dropped = $kinds;
		return (int) ( self::$counts['queued'] ?? 0 ) + (int) ( self::$counts['failed'] ?? 0 );
	}
	/** The rows it removed, so the register can let those pages go. */
	public static function dropped_rows(): array { return self::$drop_rows; }
	/**
	 * What was ACCEPTED on each of these objects — the REAL signature, which
	 * takes ids and nothing else. Shaped to the call instead, this stub went
	 * green on a call that would have been a fatal on the shop.
	 */
	public static array $applied_ids = [];
	public static function done_map( array $ids ): array {
		$out = [];
		foreach ( $ids as $id ) {
			if ( ! empty( self::$applied_ids[ (int) $id ] ) ) {
				$out[ (int) $id ] = [ 'kind' => 'cat_links', 'when' => '2026-09-14 09:00:00', 'id' => 7 ];
			}
		}
		return $out;
	}
	/** How many finished jobs of these kinds are waiting for a decision. */
	public static function review_count_for( array $kinds ): int {
		$n = 0;
		foreach ( $kinds as $k ) { $n += (int) ( $GLOBALS['review_by_kind'][ $k ] ?? 0 ); }
		return $n;
	}
	/** And how many were accepted and written. */
	public static function applied_count_for( array $kinds ): int {
		$n = 0;
		foreach ( $kinds as $k ) { $n += (int) ( $GLOBALS['applied_by_kind'][ $k ] ?? 0 ); }
		return $n;
	}
	public static function url( array $args = [] ): string { return 'https://kula.test/wp-admin/admin.php?page=dazont-ecom-diagnostic&tab=review'; }
	/** The durable record of what was written and accepted, per kind. */
	public static function applied_rows( int $limit = 200, array $kinds = [] ): array {
		$out = [];
		foreach ( (array) ( $GLOBALS['applied_rows'] ?? [] ) as $r ) {
			if ( $kinds && ! in_array( (string) $r['kind'], $kinds, true ) ) { continue; }
			$out[] = $r;
		}
		return array_slice( $out, 0, max( 1, $limit ) );
	}
	public static function kinds(): array {
		return [
			'cat_links'  => [ 'label' => 'Category internal links' ],
			'post_links' => [ 'label' => 'Article internal links' ],
			'cat_desc'   => [ 'label' => 'Category description' ],
		];
	}
	public static function label_for( string $kind, int $oid ): string { return (string) ( $GLOBALS['names'][ $oid ] ?? ( '#' . $oid ) ); }
	public static function started_by( int $u ): string { return $u ? 'Marie Dupont-Lefevre' : 'Automatic'; }
	public static function decided_by( int $u ): string { return $u ? 'Marie Dupont-Lefevre' : ''; }
	/** WHICH jobs are waiting, in the shape the real reader answers with. */
	public static function review_rows_for( array $kinds, int $limit = 10 ): array {
		$out = [];
		foreach ( $kinds as $k ) {
			foreach ( (array) ( $GLOBALS['review_rows'][ $k ] ?? [] ) as $r ) { $out[] = $r + [ 'kind' => $k ]; }
		}
		return array_slice( $out, 0, max( 1, $limit ) );
	}
	/** THE ROW BEING WRITTEN RIGHT NOW, in the shape the real reader answers with. */
	public static function in_flight( array $kinds ): array {
		foreach ( $kinds as $k ) {
			if ( ! empty( $GLOBALS['in_flight'][ $k ] ) ) {
				return (array) $GLOBALS['in_flight'][ $k ] + [ 'kind' => $k ];
			}
		}
		return [];
	}
	public static function decide_words(): array {
		return [ 'accept' => 'Accept: save this text onto the page it was written for', 'refuse' => 'Refuse: throw this text away' ];
	}
	/** The popup those controls open. Recorded, not drawn: the browser gate
	 *  presses the REAL markup, dumped by test-review.php. */
	public static int $assets = 0;
	public static function review_assets(): void { self::$assets++; }
}
require __DIR__ . '/../' . $dir . '/includes/class-hub.php';
/** The module switches. A class file always exists; this is the real check. */
class DZE_Modules {
	public static function enabled( string $id ): bool { return (bool) ( $GLOBALS['mods'][ $id ] ?? true ); }
}
/** A copy of the shop runs nothing on its own; a button pressed by hand does. */
class DZE_Site {
	public static function autopilot_ok( bool $by_hand = false ): bool { return $by_hand || empty( $GLOBALS['is_copy'] ); }
}
class DZE_Ai_Usage {
	public static function unit( string $u = '' ): void {}
	public static function about( int $id = 0 ): void {}
	public static function over_budget(): bool { return ! empty( $GLOBALS['over_budget'] ); }
}
class DZE_Marketing_Ai {
	// The settings page's own slug: the address the automation screen used to
	// live at, and the one that must still land on it.
	const MENU_SLUG = 'dazont-ecom-ai';
	public static array $asked = [];
	public static function api_key(): string { return 'k'; }
	public static function get_settings(): array { return []; }
	public static function pending_count(): int { return (int) ( $GLOBALS['pending_events'] ?? 0 ); }
	public static function covered_until(): int { return 0; }
	public static function propose( string $from, string $to ): array { self::$asked[] = [ $from, $to ]; return [ 'added' => 2 ]; }
	public static function complete( string $s, string $u, string $m = '', int $x = 0, int $t = 0 ): string { return ''; }
}
class DZE_Wpml {
	public static function ids_in_language( string $type, string $lang ): ?array { return null; }
}

require __DIR__ . '/../' . $dir . '/includes/class-category-content.php';
require __DIR__ . '/../' . $dir . '/includes/class-post-links.php';
require __DIR__ . '/../' . $dir . '/includes/class-mesh.php';
require __DIR__ . '/../' . $dir . '/includes/class-automation.php';

if ( in_array( '--dump-automation', (array) $argv, true ) ) {
	// The screen as the plugin prints it, for the browser gate that reads it
	// folded: a shop with one task on, one off, work waiting and work written.
	$GLOBALS['opts']['dze_auto_settings'] = [ 'tasks' => [
		'mesh_links' => [ 'on' => 1, 'per_day' => 3, 'apply' => 0 ],
		'cat_desc'   => [ 'on' => 0, 'per_day' => 1, 'apply' => 0 ],
	] ];
	// WORK IN FLIGHT, so the screen the browser gate reads carries a bar that
	// the SERVER drew — not one a later press put there.
	DZE_Queue::$counts = [ 'queued' => 3, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
	$GLOBALS['review_by_kind']  = [ 'cat_links' => 2, 'post_links' => 1 ];
	$GLOBALS['applied_by_kind'] = [ 'cat_links' => 9, 'post_links' => 5 ];
	// TWO of the three shown in place, so the block carries the rows AND the
	// way to the rest: both halves are on the screen the browser gate reads.
	$GLOBALS['review_rows'] = [
		'cat_links'  => [ [ 'id' => 41, 'oid' => 6223, 'label' => 'Tactical backpack covers',
			'job' => 'Category internal links', 'from' => 'Automatic', 'when' => '13/09/2026 18:34' ] ],
		'post_links' => [ [ 'id' => 42, 'oid' => 987632358, 'label' => 'The sniper role: why are they so feared?',
			'job' => 'Article internal links', 'from' => 'Automatic', 'when' => '13/09/2026 20:25' ] ],
	];
	DZE_Mesh::scan();
	ob_start();
	DZE_Automation::render_page();
	// The figure the REAL census counted on this fake shop, handed over beside
	// the markup: the browser then asserts that what was counted is what the
	// chip prints, rather than a number typed into two files.
	echo wp_json_encode( [
		'html'    => (string) ob_get_clean(),
		'orphans' => DZE_Mesh::orphan_count(),
	] );
	exit( 0 );
}

$ran   = 0;
$fails = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok   %s\n", $what ); return; }
	$fails++;
	printf( "  WRONG %s\n       got  %s\n       want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
/** The shop as it stands before a case: settings, state, registers, queue. */
function fresh( array $tasks = [] ): void {
	$GLOBALS['opts']['dze_auto_settings'] = [ 'tasks' => $tasks ];
	unset( $GLOBALS['opts']['dze_auto_state'] );
	$GLOBALS['tmeta'] = [];
	$GLOBALS['pmeta'] = [ 23 => [ '_elementor_data' => '[{"elType":"widget","settings":{"title":"Since 1998"}}]' ] ];
	$GLOBALS['mods']  = [];
	$GLOBALS['is_copy'] = false;
	$GLOBALS['pending_events'] = 0;
	$GLOBALS['over_budget'] = false;
	DZE_Queue::$added  = [];
	DZE_Queue::$assets = 0;
	DZE_Queue::$counts = [ 'queued' => 0, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
	DZE_Queue::$counts_other = [ 'queued' => 0, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
	DZE_Queue::$asked_kinds = [];
	DZE_Queue::$worked = 0;
	$GLOBALS['review_rows'] = [];
	$GLOBALS['applied_rows'] = [];
	$GLOBALS['styles']      = [];
	DZE_Hub::$more_printed  = false;
	DZE_Queue::$refuse = false;
	$GLOBALS['terms'] = $GLOBALS['shop0']['terms'];
	$GLOBALS['posts'] = $GLOBALS['shop0']['posts'];
	$GLOBALS['opts']['dze_mesh_skip'] = [];
	delete_transient( 'dze_mesh_pages' );
	delete_transient( 'dze_auto_survey' );
	DZE_Queue::$idle       = 0;
	DZE_Queue::$idle_kinds = [];
	DZE_Queue::$unlocked   = 0;
	DZE_Queue::$retried    = [];
	DZE_Queue::$dropped    = [];
	DZE_Queue::$pending_all = false;
	DZE_Queue::$drop_rows   = [];
	DZE_Queue::$applied_ids = [];
	$GLOBALS['failures']   = [
		[ 'kind' => 'cat_links', 'object_id' => 21, 'error' => 'The model refused: the description is empty.' ],
	];
}

/** What a handler sent, without ending the request. */
function sent_of( callable $fn ): array {
	try {
		$fn();
	} catch ( DZE_Json_Sent $e ) {
		return [ 'ok' => (bool) $e->ok, 'data' => (array) $e->payload ];
	}
	return [ 'ok' => false, 'data' => [] ];
}
$ON = [ 'mesh_links' => [ 'on' => 1, 'per_day' => 3, 'apply' => 0 ] ];

DZE_Mesh::scan(); // the graph is read once a day by its own cron; here, once.

echo "\nThe tasks it offers\n";
$tasks = DZE_Automation::tasks();
ok( 'three of them, and no more',      array_keys( $tasks ), [ 'mesh_links', 'cat_desc', 'events' ] );
// ONE TASK FOR ONE PIECE OF WORK. Linking was two tasks — one for categories,
// one for articles — each mending half a mesh from its own half-blind reading.
ok( 'linking is one task over one graph', $tasks['mesh_links']['scope'], 'mesh' );
ok( 'and it belongs to the mesh module',  $tasks['mesh_links']['module'], 'mesh' );

echo "\nWhat stops it, before it touches anything\n";
fresh();
ok( 'switched off, it says so',        DZE_Automation::why_not( 'mesh_links' ), 'off' );
fresh( $ON );
$GLOBALS['mods'] = [ 'mesh' => 0 ];
ok( 'its own module off, it is not ready', DZE_Automation::task_ready( 'mesh_links' ), false );
ok( 'and the reason names the modules', DZE_Automation::why_not( 'mesh_links' ), 'modules' );
// A TASK THAT HANDS ITS WORK TO THE QUEUE NEEDS THE QUEUE. The linking task
// cannot name its job kind — it depends on the page — so the check that asked
// for the queue only when a kind was named let this one through, and the
// screen offered work that could not be done.
fresh( $ON );
$GLOBALS['mods'] = [ 'queue' => 0 ];
ok( 'the writing queue off, it is not ready', DZE_Automation::task_ready( 'mesh_links' ), false );
ok( 'and it says which switch',        DZE_Automation::why_not( 'mesh_links' ), 'modules' );
fresh( $ON );
$GLOBALS['is_copy'] = true;
ok( 'a copy of the shop runs nothing', DZE_Automation::why_not( 'mesh_links' ), 'copy' );
ok( 'but a button pressed by hand does', DZE_Automation::why_not( 'mesh_links', true ), '' );
fresh( $ON );
$GLOBALS['over_budget'] = true;
ok( 'the budget spent stops it',       DZE_Automation::why_not( 'mesh_links' ), 'budget' );
ok( 'and a press cannot spend past it', DZE_Automation::why_not( 'mesh_links', true ), 'budget' );
fresh( $ON );
ok( 'on, ready, nothing in the way',   DZE_Automation::why_not( 'mesh_links' ), '' );

echo "\nWhat it is about to take\n";
fresh( $ON );
$next = DZE_Automation::shortlist( 'mesh_links', 5 );
ok( 'the graph gives it work',         count( $next ) > 0, true );
$first = $next[0];
ok( 'each row names the page',         '' !== (string) $first['name'], true );
ok( 'and says why it was chosen',      '' !== (string) $first['why'], true );
// THE ADDRESSES THE GRAPH CHOSE TRAVEL WITH THE ROW. Rebuilt from an id
// halfway through, the pass asks a different question — "what would this page
// link to on its own" — and answers it with different pages.
ok( 'and carries the addresses chosen', count( (array) $first['urls'] ) > 0, true );
$builder = false;
foreach ( $next as $row ) { if ( 23 === (int) $row['tid'] ) { $builder = true; } }
ok( 'a page builder page is never a source', $builder, false );

echo "\nOne tick, one job\n";
fresh( $ON );
$res = DZE_Automation::tick();
ok( 'the tick queues something',       $res['queued'], 1 );
ok( 'and says which task did it',      $res['task'], 'mesh_links' );
ok( 'exactly one job, never two',      count( DZE_Queue::$added ), 1 );
$job = DZE_Queue::$added[0];
ok( 'the job is the pass that writes that kind of page',
	in_array( $job['kind'], [ 'cat_links', 'post_links' ], true ), true );
ok( 'on the page the graph named',     $job['ids'], [ (int) $first['tid'] ] );
ok( 'carrying the addresses it chose', $job['payload']['urls'], (array) $first['urls'] );
// NOTHING WRITES TO THE SHOP WITHOUT BEING LOOKED AT: three tasks once
// shipped with "save without review" ticked, and a shop switching one on got
// text written straight onto its categories having chosen nothing.
ok( 'and held for review, not applied', $job['auto'], false );

echo "\nIt goes slowly, on purpose\n";
ok( 'the day is counted',              DZE_Automation::done_today( 'mesh_links' ), 1 );
ok( 'and the next tick waits its turn', DZE_Automation::why_not( 'mesh_links' ), 'early' );
// The same page is not offered again: it was worked on, and a month is the
// cooldown — a site whose fifty pages all change on one afternoon does not
// look like a site being looked after.
$again = DZE_Automation::shortlist( 'mesh_links', 5 );
$same  = false;
foreach ( $again as $row ) { if ( (int) $row['tid'] === (int) $first['tid'] ) { $same = true; } }
ok( 'the page just done is not offered again', $same, false );
// A press by hand skips the spacing — and still takes a different page.
$res2 = DZE_Automation::tick( 'mesh_links', true );
ok( 'a press by hand runs anyway',     $res2['queued'], 1 );
ok( 'on another page',                 DZE_Queue::$added[1]['ids'] !== DZE_Queue::$added[0]['ids'], true );
// The day's figure holds for the automatic pass.
$GLOBALS['opts']['dze_auto_settings'] = [ 'tasks' => [ 'mesh_links' => [ 'on' => 1, 'per_day' => 1, 'apply' => 0 ] ] ];
ok( "today's figure used up",          DZE_Automation::why_not( 'mesh_links' ), 'cap' );
// A DELIBERATE PRESS RUNS. The day's figure is the automatic rhythm, not a
// refusal to answer a button — but it is counted, so the automatic pass does
// that much less, and what protects the shop never yields to it.
ok( 'and a press still runs',          DZE_Automation::why_not( 'mesh_links', true ), '' );

echo "\n\"Nothing is short of anything\" must be TRUE of the shop\n";
//
// "Nothing is short of anything right now > résultat de Run one now. Alors que
// plein de pages sont encore sans liens. Je comprends pas." It was a true
// sentence about the REGISTER and a false one about the shop: the catch-up
// press stamps every page it queues, so the next press finds nothing NEW and
// announced that the site was finished. Three different states wore one word.
fresh( $ON );
// Everything the graph offers is already waiting in the writing queue.
DZE_Queue::$pending_all = true;
ok( 'held back, so nothing is offered', DZE_Automation::shortlist( 'mesh_links', 5 ), [] );
// The queue answers for the whole shop; the tally only says WHY.
DZE_Queue::$counts = [ 'queued' => 42, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
$dze_said = DZE_Automation::reason_text( 'none' );
ok( 'and the shop is not told it is finished',
	false !== stripos( $dze_said, 'every page has what its size calls for' ), false );
ok( 'it says where they are',           false !== stripos( $dze_said, 'writing queue' ), true );
ok( 'with the queue\'s own figure',      false !== strpos( $dze_said, '42' ), true );
DZE_Queue::$pending_all = false;

// WORKED ON RECENTLY is a different state from ALREADY QUEUED, and says so.
fresh( $ON );
DZE_Automation::tick( 'mesh_links', true );
$dze_after = DZE_Automation::shortlist( 'mesh_links', 50 );
DZE_Automation::shortlist( 'mesh_links', 1 );
$dze_held = DZE_Automation::held_now();
ok( 'the pass counts what it held back', ( (int) $dze_held['recent'] + (int) $dze_held['queued'] ) > 0, true );

// AND WHEN NOTHING REALLY IS SHORT, the old sentence is exactly right.
fresh( $ON );
DZE_Automation::shortlist( 'mesh_links', 50 ); // reads the graph, holds nothing back
DZE_Automation::held_reset();
ok( 'a finished site is still told so',
	false !== stripos( DZE_Automation::reason_text( 'none' ), 'every page has what its size calls for' ), true );

echo "\nONE question, ONE sentence, and no figure the reading cannot support\n";
//
// "Le module est complètement planté... Nothing new to work on: 6 pages are
// short of links but were worked on in the last few days. / Nothing is short
// of anything right now." TWO sentences, contradicting each other, printed one
// under the other — and the "6" beside a chip announcing 165 pages nothing
// points at. Both faults arrived in the release that was meant to mend this.
fresh( $ON );
DZE_Queue::$pending_all = true;
ob_start();
DZE_Automation::render_state( 'mesh_links' );
$dze_state = (string) ob_get_clean();
// ONE ANSWER. The empty case of "next in line" and the answer a press comes
// back with are the SAME question, so they cannot be two literals.
ok( 'the old sentence is not printed twice',
	substr_count( $dze_state, 'Nothing is short of anything right now' ), 0 );
ok( 'and the block says something',      '' !== trim( wp_strip_all_tags( $dze_state ) ), true );

// A FIGURE THE READING CANNOT SUPPORT IS WORSE THAN NO FIGURE. The shortlist
// walks a HANDFUL of candidates and stops as soon as it has enough, so its
// tally is however many it happened to look at — never what the shop holds.
fresh( $ON );
DZE_Queue::$pending_all = true;
DZE_Queue::$counts = [ 'queued' => 137, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
DZE_Automation::shortlist( 'mesh_links', 1 );
$dze_q = DZE_Automation::reason_text( 'none' );
ok( 'the queue figure is the queue\'s own',  false !== strpos( $dze_q, '137' ), true );
$dze_seen = (int) DZE_Automation::held_now()['queued'];
ok( 'never the handful it walked',        $dze_seen > 0 && $dze_seen < 137, true );
ok( 'and that fragment is not printed',   false !== strpos( $dze_q, (string) $dze_seen ), false );

// NOTHING IN THE QUEUE, everything in its wait: no invented figure at all.
fresh( $ON );
DZE_Queue::$counts = [ 'queued' => 0, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
DZE_Automation::tick( 'mesh_links', true );
DZE_Automation::shortlist( 'mesh_links', 1 );
$dze_wait = DZE_Automation::reason_text( 'none' );
ok( 'a wait is said without a figure',    1 === preg_match( '/\d/', $dze_wait ), false );
ok( 'and it says what it is waiting on',  false !== stripos( $dze_wait, 'last few days' ), true );

echo "\nA promise the queue never kept does not hold a page\n";
//
// The catch-up stamps every page it queues so the daily pass does not do it
// twice — with NO figures, because nothing has been written yet. Dropped,
// failed, or cleared, that stamp holds the page out of the pass meant to mend
// it for three days, having had nothing done to it at all.
fresh( $ON );
$dze_pick = DZE_Automation::shortlist( 'mesh_links', 1 );
$dze_oid  = (int) ( $dze_pick[0]['tid'] ?? 0 );
$dze_type = 'product_cat' === (string) ( $dze_pick[0]['kind'] ?? '' ) ? 'term' : 'post';
// A promise: queued, nothing written, nothing accepted, nothing in the queue.
$GLOBALS['tmeta'][ $dze_oid ]['_dze_auto_seen'] = [ 'mesh_links' => [ 't' => time(), 'w' => 0, 'l' => 0 ] ];
$GLOBALS['pmeta'][ $dze_oid ]['_dze_auto_seen'] = [ 'mesh_links' => [ 't' => time(), 'w' => 0, 'l' => 0 ] ];
$dze_back = DZE_Automation::shortlist( 'mesh_links', 5 );
$dze_has  = false;
foreach ( $dze_back as $r ) { if ( (int) $r['tid'] === $dze_oid ) { $dze_has = true; } }
ok( 'a promise nobody kept holds nothing', $dze_has, true );

// A PAGE REALLY WORKED ON IS STILL HELD. The stamp carries the figures the
// page had when the pass took it, so a written page is not a promise.
fresh( $ON );
$dze_pick = DZE_Automation::shortlist( 'mesh_links', 1 );
$dze_oid  = (int) ( $dze_pick[0]['tid'] ?? 0 );
DZE_Automation::tick( 'mesh_links', true );
$dze_back = DZE_Automation::shortlist( 'mesh_links', 50 );
$dze_has  = false;
foreach ( $dze_back as $r ) { if ( (int) $r['tid'] === $dze_oid ) { $dze_has = true; } }
ok( 'a page really worked on is held',    $dze_has, false );

// AND A PROMISE WHOSE WORK WAS ACCEPTED IS NOT OFFERED AGAIN — or the pass
// writes a second text over the one somebody just said yes to.
fresh( $ON );
$dze_pick = DZE_Automation::shortlist( 'mesh_links', 1 );
$dze_oid  = (int) ( $dze_pick[0]['tid'] ?? 0 );
$GLOBALS['tmeta'][ $dze_oid ]['_dze_auto_seen'] = [ 'mesh_links' => [ 't' => time(), 'w' => 0, 'l' => 0 ] ];
$GLOBALS['pmeta'][ $dze_oid ]['_dze_auto_seen'] = [ 'mesh_links' => [ 't' => time(), 'w' => 0, 'l' => 0 ] ];
DZE_Queue::$applied_ids = [ $dze_oid => true ];
$dze_back = DZE_Automation::shortlist( 'mesh_links', 50 );
$dze_has  = false;
foreach ( $dze_back as $r ) { if ( (int) $r['tid'] === $dze_oid ) { $dze_has = true; } }
ok( 'a promise already written is held',  $dze_has, false );
DZE_Queue::$applied_ids = [];

echo "\nCalling a run off frees the pages it drops\n";
//
// The catch-up stamps every page it queues so the daily pass does not do them
// twice. Stop then deleted those rows and left the stamps standing: two
// hundred pages marked as worked on, with nothing written to any of them, and
// locked out of the pass that was meant to mend them. That is the fault this
// plugin already refuses by name — "a page stamped by a queue that refused is
// a page locked out having had nothing done to it" — reintroduced by the
// button that calls a run off.
fresh( $ON );
$dze_pick = DZE_Automation::shortlist( 'mesh_links', 1 );
$dze_oid  = (int) ( $dze_pick[0]['tid'] ?? 0 );
$dze_kind = (string) ( $dze_pick[0]['kind'] ?? 'product_cat' );
DZE_Automation::tick( 'mesh_links', true );
ok( 'a queued page is stamped',         DZE_Automation::worked_on( $dze_oid, 'mesh_links', 'product_cat' === $dze_kind ? 'term' : 'post' ), true );
// The queue hands back the rows it dropped, and the register lets them go.
DZE_Automation::free_pages( [ [ 'kind' => 'product_cat' === $dze_kind ? 'cat_links' : 'post_links', 'object_id' => $dze_oid ] ] );
ok( 'and calling it off lets it go',    DZE_Automation::worked_on( $dze_oid, 'mesh_links', 'product_cat' === $dze_kind ? 'term' : 'post' ), false );
// A row of a kind no task queues is not ours to unstamp.
ok( 'a kind no task queues is left alone',
	DZE_Automation::free_pages( [ [ 'kind' => 'product_shot', 'object_id' => $dze_oid ] ] ), 0 );

echo "\nA pass that was never queued is not a pass\n";
//
// The object used to be stamped as worked on BEFORE the queue was asked, so a
// queue that refused — the row already waiting, the table gone — left the page
// marked and locked out for three days having had nothing done to it at all.
fresh( $ON );
DZE_Queue::$refuse = true;
$ref = DZE_Automation::tick( 'mesh_links' );
ok( 'a refused job is reported as refused', $ref['reason'], 'busy' );
ok( 'and nothing was queued',          count( DZE_Queue::$added ), 0 );
ok( 'the day is not counted for it',   DZE_Automation::done_today( 'mesh_links' ), 0 );
DZE_Queue::$refuse = false;
$after = DZE_Automation::shortlist( 'mesh_links', 5 );
$back  = false;
foreach ( $after as $row ) { if ( (int) $row['tid'] === (int) $first['tid'] ) { $back = true; } }
ok( 'and the page is still there to be done', $back, true );
ok( 'no register was written on it',   $GLOBALS['tmeta'], [] );

echo "\nWhat it saved, and putting it back\n";
// With "save it on the shop straight away" ticked, the text it replaced is
// kept — and one click puts it back.
fresh( [ 'mesh_links' => [ 'on' => 1, 'per_day' => 3, 'apply' => 1 ] ] );
$pick = DZE_Automation::shortlist( 'mesh_links', 1 )[0];
$was  = 'product_cat' === $pick['kind']
	? (string) $GLOBALS['terms'][ (int) $pick['tid'] ]['description']
	: (string) $GLOBALS['posts'][ (int) $pick['tid'] ]['content'];
DZE_Automation::tick();
ok( 'it goes to the shop when that is ticked', DZE_Queue::$added[0]['auto'], true );
$type = 'product_cat' === $pick['kind'] ? 'term' : 'post';
$kept = 'term' === $type
	? (string) get_term_meta( (int) $pick['tid'], '_dze_auto_prev', true )
	: (string) get_post_meta( (int) $pick['tid'], '_dze_auto_prev', true );
ok( 'the text it replaces is kept',    $kept, $was );
// The pass has written something since; the undo puts back what was there.
if ( 'term' === $type ) { $GLOBALS['terms'][ (int) $pick['tid'] ]['description'] = 'something else'; }
else { $GLOBALS['posts'][ (int) $pick['tid'] ]['content'] = 'something else'; }
ok( 'and one press puts it back',      DZE_Automation::undo( (int) $pick['tid'], $type ), true );
$now = 'term' === $type
	? (string) $GLOBALS['terms'][ (int) $pick['tid'] ]['description']
	: (string) $GLOBALS['posts'][ (int) $pick['tid'] ]['content'];
ok( 'the page holds what it held',     $now, $was );
ok( 'and there is nothing left to undo twice', DZE_Automation::undo( (int) $pick['tid'], $type ), false );

echo "\nThe screen it is read from\n";
fresh( $ON );
ob_start();
DZE_Automation::render_settings();
$html = (string) ob_get_clean();
ok( 'the tab draws',                   '' !== trim( $html ), true );
ok( 'every task is on it',             substr_count( $html, 'class="dze-auto-state"' ), 3 );
ok( 'each one can be run by hand',     substr_count( $html, 'dze-auto-run' ) >= 3, true );
// ONE SHUT BLOCK PER TASK, in the shape every other screen of this plugin
// wears — three tasks are three LINES, not three screens.
ok( 'each task is a block of its own', substr_count( $html, '<details class="dze-set dze-auto-task">' ), 3 );
ok( 'shut until it is opened',         false !== strpos( $html, 'dze-auto-task" open' ), false );
ok( 'and its figures are on the line', substr_count( $html, '<span class="dze-auto-chips"' ), 3 );
// WHAT IT HAS DONE IS NOT A FOLD UNDER THE WORK: "maintenant que To review est
// là, ce bloc est inutile". It is a view of its own.
ok( 'no diary folded under the work', false !== strpos( $html, 'dze-auto-log' ), false );
// THE WHOLE TOOL RESTS ON THIS LIST, so it is shown and not described: the
// pages it would take next, each with what it is short of.
ob_start();
DZE_Automation::render_state( 'mesh_links' );
$state = (string) ob_get_clean();
ok( 'the state names what it takes next', false !== strpos( $state, 'Next in line' ), true );
ok( 'and names a page of this shop',   false !== strpos( $state, (string) $first['name'] ), true );
ok( 'with a way to that page',         false !== strpos( $state, 'wp-admin' ), true );
// A task that cannot run says so on its own block rather than offering work
// that would fail.
$GLOBALS['mods'] = [ 'mesh' => 0 ];
ob_start();
DZE_Automation::render_settings();
$off = (string) ob_get_clean();
ok( 'a task whose module is off says so', false !== strpos( $off, 'is-blocked' ), true );
$GLOBALS['mods'] = [];

echo "\nWhat each task left for you to decide\n";
//
// "Peut-être afficher un msg sur chaque section automatisation qui nomme
// combien de jobs sont en attente de review pour chacun d'eux ?" A pass that
// runs on its own and says nothing about what it produced is a pass whose work
// is found by accident — and the list it lands on is two clicks away under
// another menu.
fresh( $ON );
$GLOBALS['review_by_kind'] = [];
ok( 'nothing waiting, nothing claimed',  DZE_Automation::waiting_for( 'mesh_links' )['n'], 0 );
// The linking task leaves BOTH kinds of job behind — a category pass and an
// article pass — and the figure on its block is the two together, or it counts
// half its own work.
$GLOBALS['review_by_kind'] = [ 'cat_links' => 2, 'post_links' => 3, 'cat_desc' => 4 ];
ok( 'the linking task counts both its passes', DZE_Automation::waiting_for( 'mesh_links' )['n'], 5 );
ok( 'and the writing task counts its own',     DZE_Automation::waiting_for( 'cat_desc' )['n'], 4 );
// A MESSAGE THAT NAMES A SCREEN IS A WAY TO THAT SCREEN.
ok( 'the figure is a link to the list',
	false !== strpos( DZE_Automation::waiting_for( 'mesh_links' )['url'], 'tab=review' ), true );
// The shop-wide task writes nothing to the queue: what it leaves is a pile of
// suggestions on the screen that owns them.
$GLOBALS['pending_events'] = 3;
ok( 'the calendar task counts its suggestions', DZE_Automation::waiting_for( 'events' )['n'], 3 );
ok( 'and points at the screen holding them',
	false !== strpos( DZE_Automation::waiting_for( 'events' )['url'], 'tab=events' ), true );
// The writing queue switched off answers nought rather than erroring: a class
// file always exists, and the figure is about a table that may not.
$GLOBALS['mods'] = [ 'queue' => 0 ];
ok( 'the queue off, nothing is claimed',  DZE_Automation::waiting_for( 'mesh_links' )['n'], 0 );
$GLOBALS['mods'] = [];
// WHAT IS WAITING IS NOT A SETTING. The block under the fold says what the
// pass is about to TAKE, and nothing about what it left: that is one list, on
// the page, where somebody looking for work to do will find it.
$GLOBALS['review_by_kind'] = [ 'cat_links' => 1, 'post_links' => 0 ];
ob_start();
DZE_Automation::render_state( 'mesh_links' );
$said = (string) ob_get_clean();
ok( 'the settings block holds no work list', false !== strpos( $said, 'dze-auto-job' ), false );
ok( 'and no way out of the page either',     false !== strpos( $said, 'tab=review' ), false );
// AND WHERE IT STANDS IS SAID ONCE, in the symbols on the line above: a
// sentence repeating them under the fold is the same answer twice, which is
// what made this screen unreadable.
ok( 'the rhythm is not said twice',      false !== strpos( $said, 'a day' ), false );

echo "\nThe line says it in symbols\n";
//
// "C'est très brutal, vulgaire, avec énormément de texte de partout… Si un
// module est bien fait, il n'est pas nécessaire d'ajouter du texte partout."
// Four figures, each one a question somebody actually asks — and each of them
// silent when it has nothing to say.
fresh( $ON );
$GLOBALS['review_by_kind']  = [ 'cat_links' => 2, 'post_links' => 1 ];
$GLOBALS['applied_by_kind'] = [ 'cat_links' => 9, 'post_links' => 5 ];
$chips = DZE_Automation::chips_html( 'mesh_links' );
ok( 'it says it is running',             false !== strpos( $chips, 'is-on' ), true );
ok( 'and at what rhythm',                false !== strpos( $chips, '3 a day' ), true );
ok( 'what waits for a person',
	false !== strpos( $chips, 'is-wait' ) && false !== strpos( $chips, '>3<' ), true );
ok( 'and what went through',
	false !== strpos( $chips, 'is-done' ) && false !== strpos( $chips, '>14<' ), true );
ok( 'with the moment it looks again',    false !== strpos( $chips, 'is-next' ), true );
ok( 'every figure carries its own word', substr_count( $chips, 'title="' ) >= 4, true );
// NOTHING TO SAY, NOTHING SAID: a nought on a chip reads as a task that failed.
$GLOBALS['review_by_kind']  = [];
$GLOBALS['applied_by_kind'] = [];
$quietchips = DZE_Automation::chips_html( 'mesh_links' );
ok( 'nothing waiting, no chip',          false !== strpos( $quietchips, 'is-wait' ), false );
ok( 'nothing written, no chip',          false !== strpos( $quietchips, 'is-done' ), false );
// SWITCHED OFF SAYS ONLY THAT: a rhythm nothing runs at is a figure about
// nothing, and a countdown on it is a promise nobody made.
fresh();
$off = DZE_Automation::chips_html( 'mesh_links' );
ok( 'switched off, it says so',          false !== strpos( $off, 'is-off' ), true );
ok( 'and promises no next run',          false !== strpos( $off, 'is-next' ), false );
// A TASK THAT CANNOT RUN SAYS SO ON ITS OWN LINE, rather than being read for.
fresh( $ON );
$GLOBALS['mods'] = [ 'mesh' => 0 ];
ok( 'a module switched off is on the line', false !== strpos( DZE_Automation::chips_html( 'mesh_links' ), 'is-blocked' ), true );
$GLOBALS['mods'] = [];

echo "\nIts own entry, in the WordPress menu\n";
//
// "Je ne vois pas de menu automation dans le plugin, côté gauche de wordpress.
// Déjà ici ça devrait être présent." This screen is not a settings page — it is
// the work: what the site is short of, what is next in line, what was done and
// the undo behind it. One tab among sixteen on Settings is where the one
// function that runs the shop by itself was hardest to find.
$GLOBALS['menu'] = [];
DZE_Automation::register_menu();
ok( 'one entry is registered',         count( $GLOBALS['menu'] ), 1 );
ok( 'under the Dazont Ecom menu',      $GLOBALS['menu'][0]['parent'], DZE_Restock::MENU_SLUG );
ok( 'and it is called Automation',     $GLOBALS['menu'][0]['label'], 'Automation' );
ok( 'on its own address',              $GLOBALS['menu'][0]['slug'], DZE_Automation::MENU_SLUG );
// THE PAGE DRAWS, and it is the same body the settings tab used to print —
// there is one screen, not a copy of it.
fresh( $ON );
ob_start();
DZE_Automation::render_page();
$page = (string) ob_get_clean();
ok( 'the page draws its own heading',  false !== strpos( $page, '<h1>Automation</h1>' ), true );
ok( 'with the tasks on it',            substr_count( $page, 'class="dze-auto-state"' ), 3 );
ok( 'with the strip of views on it',   substr_count( $page, '<a class="nav-tab' ), 2 );
ok( 'the work first, and it is the one showing',
	1 === preg_match( '/nav-tab nav-tab-active[^>]*>Tasks</', $page ), true );
ok( 'and the record beside it',        false !== strpos( $page, '>Past work<' ), true );
// AN ADDRESS THAT USED TO LAND STILL LANDS: a bookmark on the old settings tab
// must not end on a tab that no longer exists.
ok( 'the old settings address is sent here',
	DZE_Automation::moved( [ 'page' => 'dazont-ecom-ai', 'tab' => 'automation' ] ),
	DZE_Automation::page_url() );
ok( 'another settings tab is left alone',
	DZE_Automation::moved( [ 'page' => 'dazont-ecom-ai', 'tab' => 'general' ] ), '' );
ok( 'and so is another page entirely',
	DZE_Automation::moved( [ 'page' => 'dazont-ecom-diagnostic' ] ), '' );

echo "\nEvery reason it can give has words\n";
foreach ( [ 'queued', 'cap', 'none', 'budget', 'modules', 'busy', 'off', 'copy', 'early', 'failed' ] as $why ) {
	ok( 'the shop is told: ' . $why, '' !== DZE_Automation::reason_text( $why ), true );
}

echo "\nWhat is waiting is the work, in one list, on the page\n";
//
// "On devrait plutôt lister les tâches à review pour une meilleure UI, plutôt
// que de les lister dans les paramètres de l'automatisme." It was a fold
// inside each task's own controls, so reading what three passes had left meant
// opening three blocks of settings. Every list of things waiting for a
// decision is ONE list.
$ROWS = [
	'cat_links'  => [ [ 'id' => 41, 'oid' => 6223, 'label' => 'Tactical backpack covers',
		'job' => 'Category internal links', 'from' => 'Automatic', 'when' => '13/09/2026 18:34' ] ],
	'post_links' => [ [ 'id' => 42, 'oid' => 987632358, 'label' => 'The sniper role: why are they so feared?',
		'job' => 'Article internal links', 'from' => 'Automatic', 'when' => '13/09/2026 20:25' ] ],
	'cat_desc'   => [ [ 'id' => 43, 'oid' => 77, 'label' => 'Boonie hats',
		'job' => 'Category description', 'from' => 'Marie Dupont-Lefevre', 'when' => '13/09/2026 21:02' ] ],
];
/** The one list, drawn the way the screen draws it. */
function dze_waiting(): string {
	ob_start();
	DZE_Automation::render_waiting();
	return (string) ob_get_clean();
}

fresh( $ON );
$GLOBALS['review_rows']    = $ROWS;
$GLOBALS['review_by_kind'] = [ 'cat_links' => 1, 'post_links' => 1, 'cat_desc' => 1 ];
$list = dze_waiting();
// EVERY TASK'S WORK, IN ONE LIST — the linking task queues cat_links AND
// post_links, and the writing task sits in the same list beside them.
ok( 'one list holds every task\'s work', substr_count( $list, 'class="dze-auto-job"' ), 3 );
ok( 'the category the linking wrote',   false !== strpos( $list, 'Tactical backpack covers' ), true );
ok( 'the article beside it',            false !== strpos( $list, 'why are they so feared' ), true );
ok( 'and the description task too',     false !== strpos( $list, 'Boonie hats' ), true );
// OLDEST FIRST, whichever pass wrote it: what has waited longest is offered
// first, and the cap belongs to the LIST rather than to each task.
ok( 'oldest first, across the tasks',
	array_map( 'intval', array_column( array_map(
		static fn( $m ) => [ 'id' => $m ], explode( 'data-id="', $list ) ), 'id' ) )[1] ?? 0, 41 );

// EVERY LIST THAT NAMES AN OBJECT PRINTS ITS ID, and the row opens the object.
ok( 'the row prints the object id',     1 === preg_match( '/class="dze-objid"[^>]*>6223</', $list ), true );
ok( 'and the name opens the object',    false !== strpos( $list, 'term.php?tag_ID=6223' ), true );
ok( 'an article opens as a post',       false !== strpos( $list, 'post.php?post=987632358' ), true );
ok( 'the row says what was done to it', false !== strpos( $list, 'Category internal links' ), true );
ok( 'and when it last moved',           false !== strpos( $list, '13/09/2026 18:34' ), true );

// THE THREE CONTROLS ARE THE REVIEW LIST'S OWN — same classes, same popup,
// same endpoints. A second review surface beside it is two screens that start
// disagreeing about what is waiting.
ok( 'each row opens the one review popup',
	false !== strpos( $list, 'class="button button-small dze-q-open" data-id="41"' ), true );
ok( 'accepts on the line',              false !== strpos( $list, 'dze-q-yes" data-id="41"' ), true );
ok( 'refuses on the line',              false !== strpos( $list, 'dze-q-no" data-id="41"' ), true );
// A FINISHED TEXT IS WORTH A QUESTION: the cross must say it is a refusal, not
// an empty queued line dropped from the queue.
ok( 'and the cross knows it is a refusal', false !== strpos( $list, 'data-status="review"' ), true );
ok( 'in the words the list uses',
	false !== strpos( $list, DZE_Queue::decide_words()['accept'] ), true );

// WHAT IS NOT ON THE LIST, and only that.
ok( 'nothing left over, nothing said',  false !== strpos( $list, 'dze-auto-waiting' ), false );
$GLOBALS['review_by_kind'] = [ 'cat_links' => 8, 'post_links' => 4, 'cat_desc' => 1 ];
$more = dze_waiting();
ok( 'the rest points at the whole list', false !== strpos( $more, '10 more in Content to review' ), true );
ok( 'and it is a way to that screen',    false !== strpos( $more, 'tab=review' ), true );

// A TASK WHOSE WORK WAITS SOMEWHERE ELSE SAYS SO, AND NAMES WHERE: the
// calendar's suggestions are not queue rows and cannot be settled here.
fresh( $ON );
$GLOBALS['pending_events'] = 4;
$cal = dze_waiting();
ok( 'the calendar has no rows to settle', substr_count( $cal, 'class="dze-auto-job"' ), 0 );
ok( 'but it says what is waiting',        false !== strpos( $cal, '4 suggestions waiting · Marketing calendar' ), true );
ok( 'and points at the screen holding them', false !== strpos( $cal, 'tab=events' ), true );
$GLOBALS['pending_events'] = 0;

// AN EMPTY LIST SAYS WHICH EMPTY IT IS. This is the page's own work area: an
// empty space with nothing in it reads as a screen that failed to draw.
fresh( $ON );
ok( 'nothing waiting says so, once',
	false !== strpos( dze_waiting(), 'Nothing is waiting for your yes or no' ), true );

// A CLASS FILE ALWAYS EXISTS: the module is the check.
fresh( $ON );
$GLOBALS['review_rows']    = $ROWS;
$GLOBALS['review_by_kind'] = [ 'cat_links' => 1, 'post_links' => 1 ];
$GLOBALS['mods']['queue']  = false;
ok( 'the queue switched off shows no rows', DZE_Automation::todo( 'mesh_links' ), [] );
$GLOBALS['mods'] = [];

// A BODY THAT MOVES TAKES ITS ASSETS WITH IT. The screen is DRAWN and what it
// asked for is read back: calling the helper proves the helper works and
// nothing about whether the screen ever asks for it.
fresh( $ON );
$GLOBALS['review_rows']    = $ROWS;
$GLOBALS['review_by_kind'] = [ 'cat_links' => 1, 'post_links' => 1 ];
ob_start();
DZE_Automation::render_settings();
$screen = (string) ob_get_clean();
ok( 'the screen enqueues its own styles', in_array( 'dze-content', (array) $GLOBALS['styles'], true ), true );
ok( 'and asks for the popup it opens',    DZE_Queue::$assets, 1 );
ok( 'the work is on the page itself',     substr_count( $screen, 'class="dze-auto-job"' ), 2 );
ok( 'under one heading, not three folds', substr_count( $screen, 'id="dze-auto-waiting"' ), 1 );
ok( 'and it is not folded away',          false !== strpos( $screen, '<h2 class="dze-auto-h2">To review</h2>' ), true );
// AND ON A SCREEN WITH NOTHING WAITING, IT IS STILL THERE. This check used to
// assert the opposite — no popup where there is nothing to decide, to save the
// weight of an editor loaded for nobody — and that saving is what broke the
// button: "le bouton review ne fonctionne pas… seulement après
// rafraîchissement". An empty list is the state a run STARTS from, and the run
// is started on this very page.
fresh( $ON );
ob_start();
DZE_Automation::render_settings();
ob_end_clean();
ok( 'an empty list still carries what its rows will open', DZE_Queue::$assets, 1 );

echo "\nA line somebody can read, and the mechanism one press away\n";
//
// "Ça j'ai rien compris… J'aurais plutôt écrit un texte simple et compréhensif :
// déléguer à Dazont Ecom le maillage interne du site web. Avec une très courte
// description derrière de ce qu'il fait. On pourrait d'ailleurs comme dans les
// autres modules utiliser un bouton I qui charge plus d'info pour la curiosité."
fresh( $ON );
ob_start();
DZE_Automation::render_settings();
$screen = (string) ob_get_clean();
foreach ( DZE_Automation::tasks() as $tid => $task ) {
	// SHORT, or it is the wall of text he photographed. One sentence of what
	// is being handed over, one of what it does.
	ok( sprintf( '"%s" says it in a line', $task['label'] ),
		strlen( (string) $task['what'] ) <= 130, true );
	ok( 'and it says what is delegated',
		false !== stripos( (string) $task['what'], 'Dazont Ecom' ), true );
	// AND THE MECHANISM IS THERE FOR WHOEVER WANTS IT, never in the way.
	ok( 'the detail is one press away',
		strlen( (string) ( $task['more'] ?? '' ) ) > 200, true );
	ok( 'and the "?" is on its line',
		false !== strpos( $screen, 'dze-mod-more" data-module="' . $tid . '"' ), true );
	ok( 'with the long text behind it',
		false !== strpos( $screen, substr( wp_json_encode( (string) $task['more'] ), 1, 40 ) ), true );
	ok( 'under the task\'s own name',
		false !== strpos( $screen, '"' . $tid . '":{"title":"' . $task['label'] . '"' ), true );
}
// THE LONG TEXT IS NOT PRINTED AS PROSE — that is the whole point of the "?".
// It travels inside the popup's own data and appears only when pressed.
$dze_visible = strip_tags( (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $screen ) );
ok( 'the mechanism is not read out on the page',
	false !== strpos( $dze_visible, 'reads the whole site once' ), false );
ok( 'and the popup it opens is on the page',
	false !== strpos( $screen, 'id="dze-mod-popup"' ), true );

// A DECISION MOVES THE FIGURES AND THE LIST TOGETHER: they are one question,
// so they are re-read together rather than one of them guessing.
fresh( $ON );
$GLOBALS['review_rows']    = $ROWS;
$GLOBALS['review_by_kind'] = [ 'cat_links' => 1, 'post_links' => 1, 'cat_desc' => 1 ];
try {
	DZE_Automation::ajax_state();
	$sent = null;
} catch ( DZE_Json_Sent $e ) {
	$sent = $e->ok ? (array) $e->payload : null;
}
ok( 'the answer carries every task\'s line', array_keys( (array) ( $sent['chips'] ?? [] ) ), [ 'mesh_links', 'cat_desc', 'events' ] );
ok( 'each one its own',                  false !== strpos( (string) ( $sent['chips']['mesh_links'] ?? '' ), 'data-task="mesh_links"' ), true );
ok( 'and the one list beside them',      substr_count( (string) ( $sent['waiting'] ?? '' ), 'class="dze-auto-job"' ), 3 );
ok( 'and what became of the last pass',  array_key_exists( 'past', (array) $sent ), true );

echo "\nThe pages nothing points at, beside the pass that mends them\n";
//
// "Il est impératif d'inscrire l'info quelque part. Que pour lier les pages
// orphelines de liens entrant, seul le module d'automatisation peut faire le
// travail. Ce serait bien d'avoir un système simplifié de comptage."
fresh( $ON );
$GLOBALS['opts']['dze_mesh_census'] = [
	'per'    => [],
	'counts' => [ 'pages' => 830, 'links' => 2104, 'orphans' => 41, 'short' => 96, 'ends' => 12 ],
	'at'     => time() - 3600,
];
$chips = DZE_Automation::chips_html( 'mesh_links' );
ok( 'the figure is on the task that mends them',
	1 === preg_match( '/is-orphan[^>]*>.*?41</s', $chips ), true );
ok( 'and it says what it counts',
	false !== strpos( $chips, 'menus and breadcrumbs do not count' ), true );
// AND ON NO OTHER TASK: the writing task and the calendar do not touch the
// graph, and a figure on a line that cannot act on it is noise.
ok( 'never on the writing task',         false !== strpos( DZE_Automation::chips_html( 'cat_desc' ), 'is-orphan' ), false );
ok( 'nor on the calendar',               false !== strpos( DZE_Automation::chips_html( 'events' ), 'is-orphan' ), false );
// A CHIP IS SILENT WHEN IT HAS NOTHING TO SAY: a nought reads as a task that
// failed, and a site never read has counted nothing at all.
$GLOBALS['opts']['dze_mesh_census']['counts']['orphans'] = 0;
ok( 'nothing orphaned, nothing said',    false !== strpos( DZE_Automation::chips_html( 'mesh_links' ), 'is-orphan' ), false );
unset( $GLOBALS['opts']['dze_mesh_census'] );
ok( 'never read, nothing said either',   false !== strpos( DZE_Automation::chips_html( 'mesh_links' ), 'is-orphan' ), false );

// AND THE PRESS SAYS WHAT IT LEAVES TO THE DAILY PASS, on its own hover: it
// fills outgoing links and mends nothing that is orphaned.
fresh( $ON );
ob_start();
DZE_Automation::render_settings();
$screen = (string) ob_get_clean();
ok( 'the catch-up says what it fills',
	false !== strpos( $screen, 'Fills the outgoing links of every page under its own quota' ), true );
ok( 'and what it does not',
	false !== strpos( $screen, 'The pages no text links to are left to the daily pass' ), true );

echo "\nPast work: what these passes actually published\n";
//
// "Maintenant que To review est là, ce bloc est inutile. Sinon crée un nouvel
// onglet dans ce module automation, 'past work' ou un truc comme ça, pour
// recenser tous les travaux publiés gérés par le module automation."
fresh( $ON );
$GLOBALS['names'] = [ 6223 => 'Tactical backpack covers', 987632358 => 'The sniper role', 77 => 'Boonie hats' ];
$GLOBALS['applied_rows'] = [
	[ 'kind' => 'cat_links',  'object_id' => 6223,      'when' => time() - 3600, 'by' => 0, 'from' => 0 ],
	[ 'kind' => 'post_links', 'object_id' => 987632358, 'when' => time() - 7200, 'by' => 7, 'from' => 7 ],
	[ 'kind' => 'cat_desc',   'object_id' => 77,        'when' => time() - 9000, 'by' => 7, 'from' => 0 ],
	// Another pass's work entirely: a photograph is not this module's business.
	[ 'kind' => 'product_shot', 'object_id' => 12,      'when' => time() - 100,  'by' => 7, 'from' => 7 ],
];
$dze_past = DZE_Automation::past();
// IT IS THE QUEUE'S OWN RECORD, narrowed to the kinds these tasks queue —
// never a second store beside it.
ok( 'it holds what these passes wrote',  count( $dze_past ), 3 );
ok( 'and nothing another pass wrote',
	in_array( 'product_shot', array_column( $dze_past, 'kind' ), true ), false );

ob_start();
DZE_Automation::render_past();
$past = (string) ob_get_clean();
ok( 'every page it wrote is named',      false !== strpos( $past, 'Tactical backpack covers' ), true );
ok( 'the article among them',            false !== strpos( $past, 'The sniper role' ), true );
// EVERY LIST THAT NAMES AN OBJECT PRINTS ITS ID, in a column of its own, and
// the heading and the cell are asserted TOGETHER.
ok( 'the id has its own heading',        substr_count( $past, 'dze-objid-th' ), 1 );
ok( 'and its own cell per row',          substr_count( $past, 'dze-objid-td' ), 3 );
ok( 'carrying the object id',            1 === preg_match( '/class="dze-objid"[^>]*>6223</', $past ), true );
// WHO ASKED, AND WHO ACCEPTED: 0 asked for is the pass itself; 0 accepted by
// has nobody to name and stays silent.
ok( 'a pass names itself as the origin', false !== strpos( $past, 'Automatic' ), true );
ok( 'and a person is named',             false !== strpos( $past, 'Marie Dupont-Lefevre' ), true );
ok( 'the job is said in words',          false !== strpos( $past, 'Category internal links' ), true );

// THE UNDO IS OFFERED WHERE IT CAN ACT, and nowhere else: the pass keeps the
// text it replaced only for what it saved without review.
ok( 'no copy kept, no undo offered',     false !== strpos( $past, 'dze-auto-undo' ), false );
$GLOBALS['tmeta'][6223][ DZE_Automation::META_PREV ] = '<p>What was there before.</p>';
ob_start();
DZE_Automation::render_past();
$past2 = (string) ob_get_clean();
ok( 'a copy kept, the undo is there',    false !== strpos( $past2, 'dze-auto-undo" data-term="6223"' ), true );
ok( 'and only on that row',              substr_count( $past2, 'dze-auto-undo' ), 1 );

// AN EMPTY RECORD SAYS WHICH EMPTY IT IS.
$GLOBALS['applied_rows'] = [];
ob_start();
DZE_Automation::render_past();
ok( 'nothing published yet, said plainly',
	false !== strpos( (string) ob_get_clean(), 'Nothing has been written to the shop by these passes yet' ), true );
// A CLASS FILE ALWAYS EXISTS: the module is the check.
$GLOBALS['applied_rows'] = [ [ 'kind' => 'cat_links', 'object_id' => 6223, 'when' => time(), 'by' => 0, 'from' => 0 ] ];
$GLOBALS['mods']['queue'] = false;
ok( 'the queue switched off holds nothing', DZE_Automation::past(), [] );
$GLOBALS['mods'] = [];

// THE VIEW IS ASKED FOR BY NAME, and a name that is not a view answers with
// the one that is.
ok( 'the record is a view of its own',   DZE_Automation::tab_now( [ 'tab' => 'past' ] ), 'past' );
ok( 'nothing asked for is the work',     DZE_Automation::tab_now( [] ), 'work' );
ok( 'and neither is a view nobody has',  DZE_Automation::tab_now( [ 'tab' => 'nonsense' ] ), 'work' );
ok( 'each view has its own address',
	false !== strpos( DZE_Automation::page_url( 'past' ), 'tab=past' ), true );
ok( 'and the work keeps the plain one',  DZE_Automation::page_url( 'work' ), DZE_Automation::page_url() );

// THE SCREEN DRAWS THE RECORD when that is the view asked for.
$_GET['tab'] = 'past';
$GLOBALS['applied_rows'] = [ [ 'kind' => 'cat_links', 'object_id' => 6223, 'when' => time(), 'by' => 0, 'from' => 0 ] ];
ob_start();
DZE_Automation::render_page();
$page2 = (string) ob_get_clean();
unset( $_GET['tab'] );
ok( 'the record is what is drawn',       false !== strpos( $page2, 'dze-auto-past' ), true );
ok( 'and not the tasks beside it',       false !== strpos( $page2, 'dze-auto-task' ), false );
// ONE SCRIPT FOR THE SCREEN: the undo lives here and the run buttons on the
// other view, and a handler written twice is two handlers to keep in step.
ok( 'the screen still carries its script', false !== strpos( $page2, "'.dze-auto-undo'" ), true );

echo "\nThe pages behind the figure\n";
//
// "Il faut la possibilité de voir ces pages dans une liste." A count that
// cannot be opened is a count somebody argues with — and it answers the other
// half of the question at the same time: WHICH of them a page builder owns.
fresh( $ON );
DZE_Mesh::scan();
// PAGES TAKE PART ONLY WHEN THE SHOP CHOSE THEM, so a section about what the
// list SHOWS chooses them first — otherwise it asserts against a list holding
// no page at all and every check in it passes for the wrong reason.
$dze_pageids = [];
foreach ( DZE_Mesh::pages() as $dze_p ) {
	if ( 'page' === $dze_p['kind'] ) { $dze_pageids[] = (int) $dze_p['id']; }
}
DZE_Mesh::choose_pages( $dze_pageids, true );
$dze_want = DZE_Mesh::orphans( 200 );
$dze_built = 0;
foreach ( $dze_want as $r ) { if ( ! empty( $r['built'] ) ) { $dze_built++; } }
ok( 'the fake shop has pages to show', count( $dze_want ) > 0, true );
ok( 'a builder page among them',       $dze_built > 0, true );

$chips = DZE_Automation::chips_html( 'mesh_links' );
// THE FIGURE IS THE WAY IN, and it is a BUTTON: a chip inside a <summary>
// that folds the block under the hand that pressed it is not a way in.
ok( 'the figure is pressable',         false !== strpos( $chips, 'class="dze-auto-chip is-orphan dze-auto-orph"' ), true );
ok( 'and says what pressing it does',  false !== strpos( $chips, 'Press to see them' ), true );

ob_start();
DZE_Automation::render_orphans();
$list = (string) ob_get_clean();
$dze_named = true;
foreach ( $dze_want as $r ) {
	if ( false === strpos( $list, esc_html( (string) $r['title'] ) ) ) { $dze_named = false; }
}
ok( 'every one of them is named',      $dze_named, true );
// EVERY LIST THAT NAMES AN OBJECT PRINTS ITS ID, heading and cell together.
ok( 'the id has its own heading',      substr_count( $list, 'dze-objid-th' ), 1 );
ok( 'and one cell per row',            substr_count( $list, 'dze-objid-td' ), count( $dze_want ) );
ok( 'a category says what it is',      false !== strpos( $list, 'product category' ), true );
// AND WHICH OF THEM A BUILDER OWNS — the answer to "les données du page
// builder, qu'est-ce que ça vient faire là ?".
ok( 'each builder page is marked',     substr_count( $list, 'dze-auto-built' ), $dze_built );
ok( 'and the mark says what it means', false !== strpos( $list, 'read from the builder' ), true );
// ONE SENTENCE SAYS WHAT THE FIGURE COUNTS: it is why a large one is not a
// broken site.
ok( 'the list says what a link is here',
	false !== strpos( $list, 'A menu, a breadcrumb or a shop archive is not counted' ), true );
// WHAT IS NOT ON THE LIST IS SAID, rather than the screen quietly showing six
// of two hundred and thirteen.
ok( 'nothing left over, nothing said', false !== strpos( $list, 'more, not listed here' ), false );
$dze_census = $GLOBALS['opts']['dze_mesh_census'];
$dze_census['counts']['orphans'] = 213;
$GLOBALS['opts']['dze_mesh_census'] = $dze_census;
ob_start();
DZE_Automation::render_orphans();
ok( 'the rest is counted',
	false !== strpos( (string) ob_get_clean(), ( 213 - count( $dze_want ) ) . ' more, not listed here' ), true );

// AN EMPTY ANSWER SAYS WHICH EMPTY IT IS, and there are two of them here.
$GLOBALS['terms'] = [];
$GLOBALS['posts'] = [];
delete_transient( 'dze_mesh_pages' );
ob_start();
DZE_Automation::render_orphans();
ok( 'nothing orphaned, said plainly',
	false !== strpos( (string) ob_get_clean(), 'Every page is linked from the text of another one' ), true );
unset( $GLOBALS['opts']['dze_mesh_census'] );
ob_start();
DZE_Automation::render_orphans();
ok( 'never read, said differently',
	false !== strpos( (string) ob_get_clean(), 'The site has not been read yet' ), true );
// A CLASS FILE ALWAYS EXISTS: the module is the check.
$GLOBALS['mods']['mesh'] = false;
ob_start();
DZE_Automation::render_orphans();
ok( 'the graph switched off says so',
	false !== strpos( (string) ob_get_clean(), 'The link graph is switched off' ), true );
$GLOBALS['mods'] = [];

echo "\nDeciding a whole selection, not one row at a time\n";
//
// "Comme sur la page bulk product, des coches, la possibilité d'accepter ou de
// refuser en groupe." Ten lines each needing two presses is twenty presses,
// and the bulk screen beside this one has had ticks and a bar for months.
fresh( $ON );
$GLOBALS['review_by_kind'] = [ 'cat_links' => 2, 'post_links' => 1 ];
$GLOBALS['review_rows'] = [
	'cat_links'  => [
		[ 'id' => 41, 'oid' => 6223, 'label' => 'Tactical backpack covers', 'job' => 'Category internal links', 'from' => 'Automatic', 'when' => '13/09 18:34' ],
		[ 'id' => 43, 'oid' => 6224, 'label' => 'Tactical sling bags',      'job' => 'Category internal links', 'from' => 'Automatic', 'when' => '13/09 18:36' ],
	],
	'post_links' => [
		[ 'id' => 42, 'oid' => 987632358, 'label' => 'The sniper role', 'job' => 'Article internal links', 'from' => 'Automatic', 'when' => '13/09 20:25' ],
	],
];
ob_start();
DZE_Automation::render_waiting();
$dze_wait = (string) ob_get_clean();
ok( 'the bar is there',                 substr_count( $dze_wait, 'class="dze-auto-bulk"' ), 1 );
ok( 'its two readouts are their own',
	[ substr_count( $dze_wait, 'dze-auto-picked' ), substr_count( $dze_wait, 'dze-auto-decided' ) ], [ 1, 1 ] );
ok( 'a tick on every row',              substr_count( $dze_wait, 'dze-auto-cb"' ), 3 );
ok( 'and a take-all above them',        substr_count( $dze_wait, 'dze-auto-allcb' ), 1 );
// THE TWO WORDS THE PLUGIN ALREADY USES: Accept, and Cancel — never
// "Discard" beside "Delete", which read as two deletions.
ok( 'the bar accepts the lot',          false !== strpos( $dze_wait, 'dze-auto-yes' ), true );
ok( 'and cancels the lot',              false !== strpos( $dze_wait, 'dze-auto-no' ), true );
ok( 'the refusal wears the shop\'s word', false !== strpos( $dze_wait, '>Cancel<' ), true );
// A CONTROL THAT CANNOT ACT IS A CONTROL NOBODY TRUSTS: nothing ticked, so
// the bar starts asleep.
ok( 'both start disabled',              substr_count( $dze_wait, 'disabled title=' ), 2 );
// AND A TOOLTIP BELONGS IN EVERY STATE — the same words the single ✓ and ✗
// carry, from the one function that owns them.
$dze_words = DZE_Queue::decide_words();
ok( 'the bar says what Accept will do',
	false !== strpos( $dze_wait, esc_attr( (string) $dze_words['accept'] ) ), true );
ok( 'and what Cancel will do',
	false !== strpos( $dze_wait, esc_attr( (string) $dze_words['refuse'] ) ), true );
// THE ROW'S OWN CONTROLS STAY: a selection is the group form of the row
// button, never a second surface beside it.
ok( 'every row keeps its own three',    substr_count( $dze_wait, 'dze-q-open' ), 3 );

// NOTHING WAITING, NO BAR. A bar over an empty list is a control that cannot
// act, and it would be the only thing on the screen saying work exists.
fresh( $ON );
$GLOBALS['review_by_kind'] = [];
$GLOBALS['review_rows'] = [];
ob_start();
DZE_Automation::render_waiting();
$dze_none = (string) ob_get_clean();
ok( 'nothing waiting, no bar',          false !== strpos( $dze_none, 'dze-auto-bulk' ), false );
ok( 'and it says which empty it is',    false !== strpos( $dze_none, 'Nothing is waiting' ), true );

echo "\nThe bar counts what the list shows\n";
//
// The screen said "Done — 3 pages are written and waiting for your yes or no,
// below" over a list saying "Nothing is waiting for your yes or no." The bar
// read the WHOLE queue while the list under it read only the kinds these three
// tasks queue — so a photograph made from the bulk screen put a figure on this
// page over a list that could never show it.
fresh( $ON );
DZE_Queue::$counts = [ 'queued' => 2, 'running' => 0, 'review' => 1, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
DZE_Automation::run_state();
// IT ASKS FOR ITS OWN KINDS, and for every one of them: the linking task
// queues cat_links AND post_links, and asking for one of them counts half its
// own work.
ok( 'the bar asks the queue by kind',   ! empty( DZE_Queue::$asked_kinds ), true );
sort( DZE_Queue::$asked_kinds );
ok( 'and for every kind these tasks queue',
	DZE_Queue::$asked_kinds, [ 'cat_desc', 'cat_links', 'post_links' ] );

echo "\nWhat the orphan list does not count\n";
//
// "C'est mal foutu, très inconfortable." The per-row "Do not link" button is
// gone: articles and product categories always take part, a PAGE takes part
// only when the shop chose it, and that choice is made on one table on the
// Linking tab. What this popup owes is a SENTENCE — a site with pages showing
// none of them here reads as a reading that missed them.
fresh( $ON );
$GLOBALS['opts']['dze_mesh_pages'] = [];
delete_transient( 'dze_mesh_pages' );
DZE_Mesh::scan();
ob_start();
DZE_Automation::render_orphans();
$list = (string) ob_get_clean();
ok( 'no decision is taken on a row',   false !== strpos( $list, 'dze-auto-aside' ), false );
ok( 'the pages left out are counted',  1 === preg_match( '/\\d+ pages? of this site takes? no part in linking/', $list ), true );
ok( 'and it says which always do',     false !== strpos( $list, 'Every article and every product category does' ), true );
// A SENTENCE THAT NAMES A SCREEN IS A WAY TO THAT SCREEN.
ok( 'with the way to choose them',     false !== strpos( $list, 'tab=linking' ), true );
ok( 'and the word for it',             false !== strpos( $list, 'Choose them' ), true );
// NOTHING IS SAID WHEN THERE IS NOTHING TO SAY: a line reporting nought every
// day is a line nobody reads by the end of the week.
$dze_pageids = [];
foreach ( DZE_Mesh::pages() as $dze_p ) {
	if ( 'page' === $dze_p['kind'] ) { $dze_pageids[] = (int) $dze_p['id']; }
}
DZE_Mesh::choose_pages( $dze_pageids, true );
ob_start();
DZE_Automation::render_orphans();
ok( 'all chosen, nothing said',
	false !== strpos( (string) ob_get_clean(), 'take no part in linking' ), false );
DZE_Mesh::choose_pages( $dze_pageids, false );

echo "\nThe work this screen started, shown and stepped\n";
//
// "J'ai lancé run once et je suis perdu. Je fais quoi ensuite pour contrôler
// le travail ? Rien de nouveau n'apparaît dans To review même après
// actualisation. J'estime que quelque chose est cassé." A press answered
// "Queued" and the screen went silent: nothing said how many were in flight,
// nothing moved them, and nothing said when they were done.
fresh( $ON );

// NOTHING IN FLIGHT, NOTHING PRINTED. A bar at nought over an idle shop is a
// screen reporting a failure that never happened.
ob_start();
DZE_Automation::render_run();
ok( 'an idle queue draws nothing',      (string) ob_get_clean(), '' );

// TWELVE QUEUED, NONE WRITTEN: the work has started and the bar says so.
DZE_Queue::$counts = [ 'queued' => 11, 'running' => 1, 'review' => 0, 'applied' => 40, 'failed' => 0, 'skipped' => 0 ];
$dze_st = DZE_Automation::run_state();
ok( 'the figures are the queue\'s own',  [ $dze_st['left'], $dze_st['done'], $dze_st['total'] ], [ 12, 0, 12 ] );
// A JOB THAT HAS STARTED IS NOT NOUGHT PER CENT — a bar sitting flat for a
// whole run reads as a press that did nothing.
ok( 'and a run under way is never 0%',   $dze_st['pct'] > 0, true );
// WHAT IS ALREADY ACCEPTED IS NOT THIS RUN. Counting the forty applied rows
// would put the bar at 77% the moment the press landed.
ok( 'what was accepted long ago is out', $dze_st['total'], 12 );
ob_start();
DZE_Automation::render_run();
$dze_bar = (string) ob_get_clean();
ok( 'the bar is drawn',                 false !== strpos( $dze_bar, 'dze-auto-bar' ), true );
ok( 'and says it is still working',     false !== strpos( $dze_bar, 'is-working' ), true );
// "JE FAIS QUOI ENSUITE ?" is the question the screen has to answer.
ok( 'it says what to do with the page', false !== strpos( $dze_bar, 'Leave this screen open' ), true );
ok( 'and that closing it is safe too',  false !== strpos( $dze_bar, 'carries on in the background' ), true );

// HALF WAY.
DZE_Queue::$counts = [ 'queued' => 4, 'running' => 0, 'review' => 6, 'applied' => 40, 'failed' => 0, 'skipped' => 0 ];
$dze_st = DZE_Automation::run_state();
ok( 'six of ten written',               [ $dze_st['done'], $dze_st['total'], $dze_st['pct'] ], [ 6, 10, 60 ] );
ob_start();
DZE_Automation::render_run();
ok( 'the percentage is on the screen',  false !== strpos( (string) ob_get_clean(), '60% — 6 of 10 written' ), true );

// FINISHED — and it says where the work went, which is the other half of the
// question. The rows are on this same screen, under the bar.
DZE_Queue::$counts = [ 'queued' => 0, 'running' => 0, 'review' => 10, 'applied' => 40, 'failed' => 0, 'skipped' => 0 ];
$dze_st = DZE_Automation::run_state();
ok( 'nothing left to write',            $dze_st['left'], 0 );
ok( 'and the bar is full',              $dze_st['pct'], 100 );
ob_start();
DZE_Automation::render_run();
$dze_done = (string) ob_get_clean();
ok( 'it says the work is done',         false !== strpos( $dze_done, 'is-done' ), true );
ok( 'and where it is waiting',          false !== strpos( $dze_done, 'waiting for your yes or no, below' ), true );

// THE SCREEN THAT SHOWS THE WORK MOVES IT. `refresh()` in queue.js returns at
// once where there is no job table — right when this page had no running job
// to show, and the bug the moment it had one. A shop whose scheduler is wedged
// pressed the button, read "Queued", and waited for something that was never
// going to happen.
fresh( $ON );
DZE_Queue::$counts = [ 'queued' => 3, 'running' => 0, 'review' => 1, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
$_POST = [ 'step' => '1' ];
$dze_poll = sent_of( static function (): void { DZE_Automation::ajax_run_state(); } );
ok( 'the poll answers',                 (bool) ( $dze_poll['ok'] ?? false ), true );
ok( 'and it took a step of the queue',  DZE_Queue::$worked, 1 );
// EVERY ANSWER CARRIES EVERY FIGURE IT CAN MOVE — the bar, the rows waiting
// for a decision, and the chips on each task's line.
ok( 'the answer carries the bar',
	false !== strpos( (string) ( $dze_poll['data']['run']['mesh_links'] ?? '' ), 'dze-auto-bar' ), true );
ok( 'how much is left',                 (int) ( $dze_poll['data']['left'] ?? -1 ), 3 );
ok( 'the rows waiting beside it',       array_key_exists( 'waiting', (array) ( $dze_poll['data'] ?? [] ) ), true );
ok( 'and every task\'s own line',       array_keys( (array) ( $dze_poll['data']['chips'] ?? [] ) ), [ 'mesh_links', 'cat_desc', 'events' ] );
// A LOOK IS NOT A STEP. The first draw of the screen asks where things stand
// without touching the queue, or opening the page would spend a step.
DZE_Queue::$worked = 0;
$_POST = [];
$dze_look = sent_of( static function (): void { DZE_Automation::ajax_run_state(); } );
ok( 'a look moves nothing',             DZE_Queue::$worked, 0 );
ok( 'and still answers where it stands', (int) ( $dze_look['data']['left'] ?? -1 ), 3 );
$_POST = [];

// A CLASS FILE ALWAYS EXISTS: the module is the check.
$GLOBALS['mods']['queue'] = false;
ok( 'no queue, no bar',                 DZE_Automation::run_state()['total'], 0 );
$GLOBALS['mods'] = [];

// AND THE SCREEN ASKS FOR IT. Calling `render_run()` proves the renderer
// works and nothing about whether the page ever draws it — the first version
// of these checks stayed green with the block deleted from the screen, which
// is the exact fault they exist for.
fresh( $ON );
DZE_Queue::$counts = [ 'queued' => 2, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
ob_start();
DZE_Automation::render_settings();
$dze_screen = (string) ob_get_clean();
ok( 'the screen carries the bar',       false !== strpos( $dze_screen, 'class="dze-auto-live" data-task=' ), true );
ok( 'with the work drawn in it',        false !== strpos( $dze_screen, 'dze-auto-bar' ), true );
// AND IT IS ABOVE THE LIST IT FILLS: the result goes under the work that made
// it, never the other way round.
ok( 'the bar comes before what waits',
	strpos( $dze_screen, 'class="dze-auto-live" data-task=' ) < strpos( $dze_screen, 'id="dze-auto-waiting"' ), true );

echo "\nA run that has stopped says so, and can be started again\n";
//
// "c'est bloqué." Two hundred pages queued, the bar at 0%, nothing written,
// and the screen saying "Writing — 200 pages left. Leave this screen open and
// it keeps going" for as long as anybody cared to watch. Three silences in one
// screen: a failed row was counted nowhere, a queue that had stopped moving
// read exactly like one about to move, and there was no way to start it again.
fresh( $ON );

// A RUN NOBODY COULD WRITE. Counting review and queued alone, three hundred
// failures made `total` nought and the whole block VANISHED — the strongest
// possible statement that nothing is wrong.
DZE_Queue::$counts = [ 'queued' => 0, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 3, 'skipped' => 0 ];
$dze_st = DZE_Automation::run_state();
ok( 'what could not be written counts',  $dze_st['failed'], 3 );
ok( 'and it is part of the run',         $dze_st['total'], 3 );
ob_start();
DZE_Automation::render_run();
$dze_bad = (string) ob_get_clean();
ok( 'the block is drawn all the same',   false !== strpos( $dze_bad, 'dze-auto-bar' ), true );
ok( 'it says how many failed',           false !== strpos( $dze_bad, '3 could not be written' ), true );
// AND WHY. A figure with no reason beside it is a figure nobody can act on.
ok( 'with the reason it gave',           false !== strpos( $dze_bad, 'The model refused' ), true );
// A CONTROL THAT CAN ACT. There was none: a failed row could only be put back
// one at a time, from another screen.
ok( 'and one way to start it again',     substr_count( $dze_bad, 'dze-auto-again' ), 1 );

// A QUEUE THAT HAS STOPPED MOVING. The figures alone cannot say it — 200 left
// and nought written is what the first second of a run looks like too. The
// database knows: nothing of these kinds has moved for eight minutes.
fresh( $ON );
DZE_Queue::$counts = [ 'queued' => 200, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
DZE_Queue::$idle   = 8 * 60;
$dze_st = DZE_Automation::run_state();
ok( 'the screen can see the standstill', $dze_st['stuck'] > 0, true );
ob_start();
DZE_Automation::render_run();
$dze_stuck = (string) ob_get_clean();
ok( 'and says nothing has moved',        false !== strpos( $dze_stuck, 'Nothing has moved' ), true );
// IT IS ASKED BY KIND, like every other figure on this screen.
sort( DZE_Queue::$idle_kinds );
ok( 'asked of its own kinds only',       DZE_Queue::$idle_kinds, [ 'cat_desc', 'cat_links', 'post_links' ] );
// NEVER "leave this screen open and it keeps going" over a run that is going
// nowhere: that sentence is the whole of what made him wait.
ok( 'and not that it is under way',      false !== strpos( $dze_stuck, 'Leave this screen open' ), false );
ok( 'with the way to start it again',    substr_count( $dze_stuck, 'dze-auto-again' ), 1 );

// A RUN THAT IS MOVING SAYS NONE OF IT. A warning shown on an ordinary run is
// a warning nobody reads by the end of the week.
DZE_Queue::$idle = 4;
ob_start();
DZE_Automation::render_run();
$dze_live = (string) ob_get_clean();
ok( 'a working run is not called stuck', false !== strpos( $dze_live, 'Nothing has moved' ), false );
ok( 'and says what it is doing',         false !== strpos( $dze_live, 'Leave this screen open' ), true );
ok( 'with nothing to start again',       false !== strpos( $dze_live, 'dze-auto-again' ), false );

// THE PRESS ITSELF: it lets the writer go, puts back what failed, and answers
// with the screen redrawn — never a figure the page has to be reloaded to see.
fresh( $ON );
DZE_Queue::$counts   = [ 'queued' => 2, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 3, 'skipped' => 0 ];
DZE_Queue::$unlocked = 0;
DZE_Queue::$retried  = [];
$dze_again = sent_of( static function (): void { DZE_Automation::ajax_run_again(); } );
ok( 'the press answers',                 (bool) ( $dze_again['ok'] ?? false ), true );
ok( 'it lets the writer go',             DZE_Queue::$unlocked, 1 );
sort( DZE_Queue::$retried );
ok( 'and puts back its own kinds',       DZE_Queue::$retried, [ 'cat_desc', 'cat_links', 'post_links' ] );
ok( 'the answer carries the bar',        false !== strpos( (string) ( $dze_again['data']['run'] ?? '' ), 'dze-auto-bar' ), true );
ok( 'and says what it did',              false !== strpos( (string) ( $dze_again['data']['message'] ?? '' ), '3' ), true );

echo "\nCalling a run off\n";
//
// "Start it again > Il faut une option aussi pour annuler." The block had ONE
// control and it put the work back: two hundred pages queued by mistake could
// be restarted for ever and never stopped.
fresh( $ON );
DZE_Queue::$counts = [ 'queued' => 194, 'running' => 0, 'review' => 3, 'applied' => 0, 'failed' => 3, 'skipped' => 0 ];
DZE_Queue::$idle   = 4;
ob_start();
DZE_Automation::render_run();
$dze_run = (string) ob_get_clean();
ok( 'a run under way can be stopped',    substr_count( $dze_run, 'dze-auto-stop' ), 1 );
// IT SAYS WHAT IT WILL DROP AND WHAT IT KEEPS, on its own hover — a press that
// throws work away must never be a bare word.
ok( 'and its hover says what it keeps',
	false !== stripos( $dze_run, 'waiting for your yes or no' ), true );

// A CONTROL THAT CANNOT ACT IS NOT SHOWN. With nothing queued and nothing
// failed there is no run to call off.
DZE_Queue::$counts = [ 'queued' => 0, 'running' => 0, 'review' => 5, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
ob_start();
DZE_Automation::render_run();
ok( 'nothing to stop, no button',        false !== strpos( (string) ob_get_clean(), 'dze-auto-stop' ), false );
// AND WORK THAT ONLY FAILED IS STILL WORK TO CALL OFF: the failure line and
// the way to be rid of it belong together.
DZE_Queue::$counts = [ 'queued' => 0, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 3, 'skipped' => 0 ];
ob_start();
DZE_Automation::render_run();
$dze_bad = (string) ob_get_clean();
ok( 'failures alone can be cleared',     substr_count( $dze_bad, 'dze-auto-stop' ), 1 );
ok( 'beside the way to try them again',  substr_count( $dze_bad, 'dze-auto-again' ), 1 );

// THE PRESS ITSELF.
fresh( $ON );
DZE_Queue::$counts  = [ 'queued' => 194, 'running' => 0, 'review' => 3, 'applied' => 0, 'failed' => 3, 'skipped' => 0 ];
DZE_Queue::$dropped = [];
DZE_Queue::$drop_rows = [ [ 'kind' => 'cat_links', 'object_id' => 21 ] ];
$GLOBALS['tmeta'][21]['_dze_auto_seen'] = [ 'mesh_links' => [ 't' => time(), 'w' => 0, 'l' => 0 ] ];
$dze_stop = sent_of( static function (): void { DZE_Automation::ajax_run_stop(); } );
ok( 'the press answers',                 (bool) ( $dze_stop['ok'] ?? false ), true );
// AND THE PAGES IT DROPPED ARE FREED: a page whose row is gone had nothing
// written to it, and a stamp saying otherwise locks it out of the pass meant
// to mend it — the whole of "nothing is short of anything" over a site full of
// unlinked pages.
ok( 'and the pages it dropped are freed',
	DZE_Automation::worked_on( 21, 'mesh_links', 'term' ), false );
sort( DZE_Queue::$dropped );
ok( 'it drops its own kinds',            DZE_Queue::$dropped, [ 'cat_desc', 'cat_links', 'post_links' ] );
ok( 'and says how many it called off',   false !== strpos( (string) ( $dze_stop['data']['message'] ?? '' ), '197' ), true );
// EVERY FIGURE THE PRESS CAN MOVE COMES BACK WITH IT, or the screen answers
// for the page as it was opened.
ok( 'the answer carries the bar',        array_key_exists( 'run', (array) ( $dze_stop['data'] ?? [] ) ), true );
ok( 'and the rows still waiting',        array_key_exists( 'waiting', (array) ( $dze_stop['data'] ?? [] ) ), true );

echo "\nThe progress belongs to the press that started it\n";
//
// "Run one now > Ca devrait afficher la progression directement ici ! pareil
// pour les autres task quand c'est du one shot." The bar was ONE block under
// all three tasks, so pressing a button in the first block moved a figure
// somewhere else on the page — and a press that answers out of sight is a
// press nobody believes.
fresh( $ON );
DZE_Queue::$counts = [ 'queued' => 4, 'running' => 0, 'review' => 2, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
ob_start();
DZE_Automation::render_settings();
$dze_scr = (string) ob_get_clean();
// ONE BLOCK PER TASK, each naming the task it belongs to.
// EVERY task gets the wrapper — the poll has to have somewhere to put its
// answer — and a task that queues nothing gets an EMPTY one rather than none.
ok( 'every task carries its own place',
	substr_count( $dze_scr, 'class="dze-auto-live" data-task=' ), 3 );
ok( 'and the one that queues nothing is empty',
	false !== strpos( $dze_scr, '<div class="dze-auto-live" data-task="events"></div>' ), true );
ok( 'the linking task has one',
	false !== strpos( $dze_scr, 'class="dze-auto-live" data-task="mesh_links"' ), true );
// AND IT IS INSIDE THE BLOCK, under the button that starts the work — never
// below every task, which is where a figure answers for somebody else's press.
$dze_btn = strpos( $dze_scr, 'dze-auto-run" data-task="mesh_links"' );
$dze_end = strpos( $dze_scr, '</details>' );
ok( 'and it sits inside that task\'s own block', $dze_btn < $dze_end, true );
// THE PAGE-LEVEL ONE IS GONE: two accounts of one thing is what makes a screen
// disagree with itself.
ok( 'no second bar under the lot',      false !== strpos( $dze_scr, 'id="dze-auto-run"' ), false );

// A TASK'S BAR COUNTS ITS OWN KINDS AND NOBODY ELSE'S.
fresh( $ON );
DZE_Queue::$asked_kinds = [];
DZE_Automation::run_state( 'cat_desc' );
sort( DZE_Queue::$asked_kinds );
ok( 'one task asks for its own jobs',   DZE_Queue::$asked_kinds, [ 'cat_desc' ] );
DZE_Automation::run_state( 'mesh_links' );
sort( DZE_Queue::$asked_kinds );
ok( 'and the linking task for both',    DZE_Queue::$asked_kinds, [ 'cat_links', 'post_links' ] );

// A TASK THAT QUEUES NOTHING HAS NO BAR: a control that cannot act is not shown.
ob_start();
DZE_Automation::render_run( 'events' );
ok( 'a task with no queue rows has none', (string) ob_get_clean(), '' );

// EVERY FIGURE THE POLL CAN MOVE COMES BACK KEYED BY TASK, exactly as the chips
// already do — a single lump of markup could only be put in one place.
fresh( $ON );
DZE_Queue::$counts = [ 'queued' => 3, 'running' => 0, 'review' => 1, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
$_POST = [ 'step' => '1' ];
$dze_poll = sent_of( static function (): void { DZE_Automation::ajax_run_state(); } );
$_POST = [];
ok( 'the answer carries a bar per task',
	array_keys( (array) ( $dze_poll['data']['run'] ?? [] ) ), [ 'mesh_links', 'cat_desc', 'events' ] );
ok( 'and the linking one is drawn',
	false !== strpos( (string) ( $dze_poll['data']['run']['mesh_links'] ?? '' ), 'dze-auto-bar' ), true );

// AND A CONTROL ACTS ON THE BLOCK IT WAS PRESSED IN. Stop in one task's block
// must never drop another task's queue.
fresh( $ON );
DZE_Queue::$counts  = [ 'queued' => 5, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
DZE_Queue::$dropped = [];
$_POST = [ 'task' => 'cat_desc' ];
sent_of( static function (): void { DZE_Automation::ajax_run_stop(); } );
$_POST = [];
ok( 'Stop drops that task\'s kinds only', DZE_Queue::$dropped, [ 'cat_desc' ] );
DZE_Queue::$retried = [];
$_POST = [ 'task' => 'cat_desc' ];
sent_of( static function (): void { DZE_Automation::ajax_run_again(); } );
$_POST = [];
ok( 'and so does Start it again',       DZE_Queue::$retried, [ 'cat_desc' ] );

// AND THE SCREEN CARRIES THE POPUP THE CHIP OPENS: a button whose popup is not
// on the page does nothing and says nothing.
fresh( $ON );
$GLOBALS['opts']['dze_mesh_census'] = [ 'per' => [], 'counts' => [ 'orphans' => 4 ], 'at' => time() ];
ob_start();
DZE_Automation::render_settings();
$screen = (string) ob_get_clean();
ok( 'the popup is printed on the screen', false !== strpos( $screen, 'id="dze-auto-orphmodal"' ), true );
ok( 'with the body it fills',             false !== strpos( $screen, 'id="dze-auto-orphbody"' ), true );

echo "\nTHE SCREEN THAT MAKES THE ROWS CARRIES WHAT THEY OPEN\n";
// "Ici c'est cassé le bouton review ne fonctionne pas… seulement après
// rafraîchissement."
//
// The popup those rows open was printed only where something was ALREADY
// waiting when the page was drawn. But this screen is where the work is
// STARTED: press Run one now on an empty list and a minute later the block
// draws rows with a Review button, on a page that holds neither the popup nor
// the handler that opens it. Refresh and it works — which is exactly the shape
// of a button bound to markup that arrived after the script decided there was
// nothing to bind.
$GLOBALS['q_review'] = [];          // nothing waiting: the state a run starts from
DZE_Queue::$assets   = 0;
ob_start();
DZE_Automation::render_settings();
$dze_empty_screen = (string) ob_get_clean();
ok( 'nothing is waiting yet, so the block shows none',
	false !== strpos( $dze_empty_screen, 'dze-q-open' ), false );
ok( 'and the screen still carries what a row will open', DZE_Queue::$assets > 0, true );
// It is still the QUEUE'S own popup — never a second surface printed here,
// which is two places a decision is signed.
ok( 'from the module that owns that decision', DZE_Queue::$assets, 1 );
// AND WITH SOMETHING WAITING IT IS PRINTED ONCE, not twice.
$GLOBALS['q_review'] = [ [ 'id' => 7, 'kind' => 'post_links', 'object_id' => 501, 'status' => 'review' ] ];
DZE_Queue::$assets   = 0;
ob_start(); DZE_Automation::render_settings(); ob_end_clean();
ok( 'and exactly once when a row is there', DZE_Queue::$assets, 1 );
// THE MODULE IS STILL THE GATE. Switching the writing queue off must leave no
// trace of it on this screen — a class file always exists.
$GLOBALS['mods']['queue'] = false;
DZE_Queue::$assets = 0;
ob_start(); DZE_Automation::render_settings(); ob_end_clean();
ok( 'a switched-off queue prints none of it', DZE_Queue::$assets, 0 );
$GLOBALS['mods']['queue'] = true;

echo "\nWHILE IT IS WRITING, IT SAYS WHAT IT IS WRITING\n";
// "Ici je veux plus d'info sur le post qui est en cours de travail. Pendant que
// ça charge je veux savoir ce que ça charge." The bar said 3% and "1 of 200
// written" — a figure, and not one word about which of the two hundred pages
// was being worked on at that moment. The queue has always known.
fresh( $ON );
DZE_Queue::$counts = [ 'queued' => 4, 'running' => 1, 'review' => 1, 'failed' => 0 ];
$GLOBALS['in_flight']   = [ 'post_links' => [ 'oid' => 501, 'label' => 'How snipers work', 'job' => 'Article internal links', 'running' => true ] ];
$dze_st = DZE_Automation::run_state( 'mesh_links' );
ok( 'the run names the page in flight', (string) ( $dze_st['now']['label'] ?? '' ), 'How snipers work' );
ok( 'and what is being done to it',     (string) ( $dze_st['now']['job'] ?? '' ), 'Article internal links' );
ob_start(); DZE_Automation::render_run( 'mesh_links' ); $dze_bar = (string) ob_get_clean();
ok( 'and the bar prints it',            false !== strpos( $dze_bar, 'How snipers work' ), true );
ok( 'saying it is being written now',   false !== strpos( $dze_bar, 'Writing' ), true );
// A ROW ONLY WAITING ITS TURN IS NOT BEING WRITTEN. Saying "writing X" over a
// queue whose writer is idle is a screen describing work nobody is doing.
$GLOBALS['in_flight'] = [ 'post_links' => [ 'oid' => 502, 'label' => 'Military pants', 'job' => 'Article internal links', 'running' => false ] ];
ob_start(); DZE_Automation::render_run( 'mesh_links' ); $dze_bar2 = (string) ob_get_clean();
ok( 'a page waiting its turn says it is next', false !== strpos( $dze_bar2, 'Next' ), true );
ok( 'and names it',                            false !== strpos( $dze_bar2, 'Military pants' ), true );
// NOTHING IN FLIGHT, NOTHING SAID: a line about a page that is not being
// written is worse than no line.
$GLOBALS['in_flight'] = [];
ob_start(); DZE_Automation::render_run( 'mesh_links' ); $dze_bar3 = (string) ob_get_clean();
ok( 'and an empty queue names nothing', false !== strpos( $dze_bar3, 'dze-auto-now' ), false );

echo "\nNEXT IN LINE IS A LIST, NOT A PARAGRAPH\n";
// "Affichage maladroit, mauvais pour UI. Peut-être plutôt revenir à la ligne
// sur chaque post. Ou un bouton d'infos qui montre les posts à venir (les
// cacher par défaut ?)" Five titles with five parenthetical explanations run
// together with middle dots wrapped over four lines of unreadable prose.
fresh( $ON );
DZE_Queue::$counts = [];
$GLOBALS['in_flight']  = [];
ob_start(); DZE_Automation::render_state( 'mesh_links' ); $dze_next = (string) ob_get_clean();
ok( 'it is a fold, shut',            false !== strpos( $dze_next, '<details class="dze-auto-nextwrap"' ), true );
ok( 'and it is not open',            false !== strpos( $dze_next, '<details class="dze-auto-nextwrap" open' ), false );
preg_match( '/Next in line \((\d+)\)/', $dze_next, $dze_n );
$dze_lines = substr_count( $dze_next, '<li class="dze-auto-nextone">' );
ok( 'the summary says how many', (int) ( $dze_n[1] ?? 0 ) > 0, true );
// AND THE FIGURE IS THE LIST UNDER IT. A summary counting one thing over a
// list showing another is a screen that disagrees with itself.
ok( 'and it is the number of lines under it', (int) ( $dze_n[1] ?? 0 ), $dze_lines );
ok( 'one line per page, not a run-on sentence', $dze_lines > 1, true );
ok( 'and no middle dots gluing them together', false !== strpos( $dze_next, ' · ' ), false );

echo "\nA ROW NAMING A PAGE OFFERS THE WAY TO SEE IT\n";
// "Past work — aucun bouton pour voir la page côté utilisateur, il manque le
// petit symbole qui devrait rediriger on site." The name linked to the EDIT
// screen and nothing anywhere opened the page as a visitor sees it — which is
// the one thing you want after accepting a text written onto it.
$GLOBALS['applied_rows'] = [
	[ 'kind' => 'post_links', 'object_id' => 501, 'from' => 0, 'by' => 3, 'when' => time() ],
];
$GLOBALS['names'][501] = 'How snipers work';
ob_start(); DZE_Automation::render_past(); $dze_past = (string) ob_get_clean();
ok( 'the row offers the page on the site', false !== strpos( $dze_past, 'dze-hub-visit' ), true );
ok( 'at the address a visitor uses',       false !== strpos( $dze_past, 'https://kula.test/blog/501/' ), true );
ok( 'in a new tab, so nothing open here is lost',
	false !== strpos( $dze_past, 'target="_blank"' ), true );
ok( 'and the name still opens the editor', false !== strpos( $dze_past, 'post=501' ), true );
// A PAGE WITH NO ADDRESS OFFERS NOTHING rather than a link to "#".
$GLOBALS['permalinks'] = [ 501 => '' ];
ob_start(); DZE_Automation::render_past(); $dze_past2 = (string) ob_get_clean();
ok( 'no address, no symbol', false !== strpos( $dze_past2, 'dze-hub-visit' ), false );
$GLOBALS['permalinks'] = [];

echo "\nEVERY ROW OPENS THE PAGE IT NAMES — ITS EDITOR, AND THE PAGE ITSELF\n";
// "Ici manque de lien direct vers les pages. Je veux pouvoir aller dessus
// facilement avant, pour comparer ensuite l'après."
//
// Two faults in one line. The link was asked for with the TASK's scope rather
// than the ROW's — and this task works on categories AND articles alike, so
// four rows in five were handed `term.php?tag_ID=<a post id>`: not a missing
// link, a WRONG one, pointing at a term that does not exist. And there was no
// way at all to the page as a reader sees it, which is the whole of what he
// asked for: look at it before, compare after.
fresh( $ON );
ob_start(); DZE_Automation::render_state( 'mesh_links' ); $dze_nx = (string) ob_get_clean();
preg_match_all( '#<li class="dze-auto-nextone">(.*?)</li>#s', $dze_nx, $dze_rows );
$dze_rows = (array) ( $dze_rows[1] ?? [] );
ok( 'the block lists what is next', count( $dze_rows ) > 1, true );
$dze_bad = 0;
foreach ( $dze_rows as $one ) {
	if ( false === strpos( $one, '<a href=' ) ) { $dze_bad++; }
}
ok( 'every row is a way to the page',  $dze_bad, 0 );
// AN ARTICLE IS OPENED AS AN ARTICLE. The one that was silently wrong.
$dze_art = '';
foreach ( $dze_rows as $one ) {
	if ( false !== strpos( $one, 'How to choose a tactical backpack' ) ) { $dze_art = $one; }
}
ok( 'the article row is there',        '' !== $dze_art, true );
ok( 'and it opens the POST editor',    false !== strpos( $dze_art, 'post.php' ), true );
ok( 'never a term that does not exist', false !== strpos( $dze_art, 'tag_ID' ), false );
// AND A CATEGORY IS STILL OPENED AS A CATEGORY.
$dze_cat = '';
foreach ( $dze_rows as $one ) {
	if ( false !== strpos( $one, 'Tactical backpacks' ) ) { $dze_cat = $one; }
}
ok( 'a category still opens its own editor', false !== strpos( $dze_cat, 'tag_ID' ), true );
// THE PAGE AS A READER SEES IT, beside the name — the same symbol Past work
// wears, from the same one function.
foreach ( $dze_rows as $one ) {
	if ( false === strpos( $one, 'dze-hub-visit' ) ) { $dze_bad++; }
}
ok( 'and every row offers the page itself', $dze_bad, 0 );
ok( 'in a new tab',                    false !== strpos( $dze_nx, 'target="_blank"' ), true );
ok( 'at the address a reader uses',    false !== strpos( $dze_nx, 'https://kula.test/blog/20/' ), true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
