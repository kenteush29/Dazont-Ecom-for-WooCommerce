<?php
/**
 * The one list of screens, and everything that must agree with it.
 *
 * Run before every release:  php tools/test-screens.php dazont-ecom
 *
 * "Je suis perdu et pas redirigé au bon endroit." A menu label, the sentence
 * that names that screen and the address behind the link were three lists kept
 * by hand, and they had drifted: "Marketing events" in a sentence for a page
 * called "Marketing", a notice pointing at a tab that had moved. This gate
 * holds the catalogue to the code it describes — every slug it names is the
 * constant the page class really uses, every `'page' => X, 'tab' => 'y'` link
 * written anywhere in the plugin names a tab that page has — and holds its
 * own answers: what is offered, where it is, what it is called, and the order
 * the menu is read in.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
function __( $s, $d = '' ) { return $s; }
function admin_url( $p = '' ) { return 'http://shop.test/wp-admin/' . $p; }
function add_query_arg( $args, $url = '', $third = null ) {
	if ( ! is_array( $args ) ) { $args = [ (string) $args => $url ]; $url = (string) $third; }
	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args );
}
$GLOBALS['off'] = [];
class DZE_Modules {
	public static function enabled( $id ) { return ! in_array( $id, (array) ( $GLOBALS['off'] ?? [] ), true ); }
}

require __DIR__ . '/../' . $dir . '/includes/class-screens.php';

$ran = 0; $fails = 0;
function ok( string $what, $got, $want ): void {
	global $ran, $fails;
	$ran++;
	if ( $got === $want ) { echo "  ok    $what\n"; return; }
	$fails++;
	echo "  FAIL  $what\n          got  " . var_export( $got, true ) . "\n          want " . var_export( $want, true ) . "\n";
}
/** A class constant, read off the SOURCE: the page classes are too heavy to load here. */
function const_in( string $file, string $name ): string {
	$src = (string) file_get_contents( __DIR__ . '/../' . $GLOBALS['dir'] . '/includes/' . $file );
	return preg_match( '/const\s+' . preg_quote( $name, '/' ) . '\s*=\s*\'([^\']+)\'/', $src, $m ) ? $m[1] : '';
}
$GLOBALS['dir'] = $dir;

echo "EVERY SLUG IN THE CATALOGUE IS THE ONE ITS PAGE CLASS USES\n";
// A slug typed twice is two slugs the day either changes. The class keeps its
// constant — every link in that module is built from it — and the catalogue
// must say the same word.
$dze_where = [
	'dashboard'    => [ 'class-dashboard.php', 'MENU_SLUG' ],
	'content'      => [ 'class-diagnostic.php', 'MENU_SLUG' ],
	'lab'          => [ 'class-image-lab.php', 'MENU_SLUG' ],
	'linking'      => [ 'class-mesh.php', 'MENU_SLUG' ],
	'netlinking'   => [ 'class-netlinking.php', 'MENU_SLUG' ],
	'marketing'    => [ 'class-discounts.php', 'MENU_SLUG_EVENTS' ],
	'translations' => [ 'class-translate-screen.php', 'MENU_SLUG' ],
	'automation'   => [ 'class-automation.php', 'MENU_SLUG' ],
	'restock'      => [ 'class-restock.php', 'MENU_SLUG' ],
	'fbt'          => [ 'class-fbt.php', 'MENU_SLUG' ],
	'sourcing'     => [ 'class-explorer.php', 'MENU_SLUG' ],
	'bulk'         => [ 'class-content.php', 'BULK_SLUG' ],
	'shortcodes'   => [ 'class-shortcodes.php', 'MENU_SLUG' ],
	'setup'        => [ 'class-setup.php', 'MENU_SLUG' ],
	'logs'         => [ 'class-health.php', 'MENU_SLUG' ],
	'settings'     => [ 'class-marketing-ai.php', 'MENU_SLUG' ],
	'modules'      => [ 'class-modules.php', 'MENU_SLUG' ],
];
$dze_cat = DZE_Screens::catalog();
foreach ( $dze_where as $dze_id => [ $dze_file, $dze_const ] ) {
	ok( "$dze_id is the slug $dze_file uses", $dze_cat[ $dze_id ]['slug'] ?? '', const_in( $dze_file, $dze_const ) );
}
ok( 'the discount rules tab is the rules page', $dze_cat['marketing']['tabs']['discounts']['slug'] ?? '', const_in( 'class-discounts.php', 'MENU_SLUG' ) );
ok( 'and nothing in the catalogue is unaccounted for',
	array_values( array_diff( array_keys( $dze_cat ), array_keys( $dze_where ) ) ), [] );
ok( 'the parent is the top-level menu', DZE_Screens::PARENT, const_in( 'class-restock.php', 'MENU_SLUG' ) );

echo "\nEVERY LINK WRITTEN IN THE PLUGIN NAMES A TAB ITS PAGE HAS\n";
// `[ 'page' => DZE_X::MENU_SLUG, 'tab' => 'y' ]` is how every screen links to
// another, and a tab that moved leaves such a link landing on a redirect at
// best — the health notice pointed at a settings tab that had been a Logs tab
// for months. The constant is resolved to its slug and the slug to its page.
$dze_slug_to_page = [];
foreach ( $dze_cat as $dze_id => $dze_page ) {
	$dze_slug_to_page[ $dze_page['slug'] ] = $dze_id;
	foreach ( (array) ( $dze_page['tabs'] ?? [] ) as $dze_tab => $dze_one ) {
		if ( ! empty( $dze_one['slug'] ) ) { $dze_slug_to_page[ $dze_one['slug'] ] = $dze_id; }
	}
}
$dze_bad = [];
foreach ( glob( __DIR__ . '/../' . $dir . '/includes/*.php' ) as $dze_f ) {
	$dze_src = (string) file_get_contents( $dze_f );
	if ( ! preg_match_all( '/\'page\'\s*=>\s*(self|DZE_[A-Za-z_]+)::([A-Z_]+)\s*,\s*\'tab\'\s*=>\s*\'([a-z_]+)\'/', $dze_src, $dze_m, PREG_SET_ORDER ) ) {
		continue;
	}
	foreach ( $dze_m as [ , $dze_cls, $dze_const, $dze_tab ] ) {
		// The file that class lives in — this one for self::, the class's own
		// otherwise, named the way the autoloader names it.
		$dze_in = 'self' === $dze_cls
			? basename( $dze_f )
			: 'class-' . strtolower( str_replace( [ 'DZE_', '_' ], [ '', '-' ], $dze_cls ) ) . '.php';
		$dze_slug = const_in( $dze_in, $dze_const );
		$dze_pid  = $dze_slug_to_page[ $dze_slug ] ?? '';
		if ( '' === $dze_pid ) {
			continue; // a page this catalogue does not hold is not its verdict.
		}
		if ( ! array_key_exists( $dze_tab, (array) ( $dze_cat[ $dze_pid ]['tabs'] ?? [] ) ) ) {
			$dze_bad[] = basename( $dze_f ) . ": page $dze_slug has no tab '$dze_tab'";
		}
	}
}
ok( 'no link names a tab its page does not have', $dze_bad, [] );

echo "\nWHAT IS OFFERED, WHERE IT IS, AND WHAT IT IS CALLED\n";
$GLOBALS['off'] = [];
ok( 'a page is offered',                DZE_Screens::offered( 'logs' ), true );
ok( 'and its address is its own',
	DZE_Screens::url( 'logs', 'health' ), 'http://shop.test/wp-admin/admin.php?page=dazont-ecom-logs&tab=health' );
ok( 'named as the menu names it',       DZE_Screens::name( 'logs' ), 'Dazont Ecom → Logs' );
ok( 'and the tab after it',             DZE_Screens::name( 'logs', 'health' ), 'Dazont Ecom → Logs → Connections' );
// THE SETTINGS KEEP THE SHORT FORM every message in the plugin already uses.
ok( 'a settings tab keeps the short form', DZE_Screens::name( 'settings', 'general' ), 'Settings → General' );
ok( 'and points at that tab',
	DZE_Screens::url( 'settings', 'general' ), 'http://shop.test/wp-admin/admin.php?page=dazont-ecom-ai&tab=general' );
// THE SCREEN THE OWNER WAS SENT TO BY A SENTENCE THAT NAMED A PAGE RENAMED
// SINCE. Built from the catalogue it cannot say "Marketing events".
ok( 'the Google screen is named as the menu names it',
	DZE_Screens::name( 'marketing', 'gmc' ), 'Dazont Ecom → Marketing → Google Merchant Center' );
ok( 'and is where the connect button is',
	DZE_Screens::url( 'marketing', 'gmc' ), 'http://shop.test/wp-admin/admin.php?page=dazont-ecom-marketing-events&tab=gmc' );
// A TAB WITH A PAGE OF ITS OWN answers with that page.
ok( 'the discount rules tab is its own page',
	DZE_Screens::url( 'marketing', 'discounts' ), 'http://shop.test/wp-admin/admin.php?page=dazont-ecom-discounts' );
// LETABLI A SON ECRAN, sous Dazont Ecom, nomme pour ce quil traite.
ok( 'letabli a son adresse a lui',
	false !== strpos( DZE_Screens::url( 'bulk' ), 'page=dazont-content-bulk' ), true );
ok( 'a screen not in the catalogue answers nothing', DZE_Screens::name( 'nowhere' ), '' );
ok( 'and so does a tab the page has not got', DZE_Screens::url( 'logs', 'nowhere' ), '' );

echo "\nLA LISTE CENTRALE NEXISTE PLUS : CHAQUE MODULE RELIT SON TRAVAIL\n";
// « Dans ce cas on supprime le menu to review. On simplifie plutot que de
// complexifier. » Un ecran de plus a retenir pour une question que chaque
// module sait deja poser chez lui : le maillage a son onglet, le banc des
// categories a sa liste sous le banc, les traductions la leur.
//
// ELLE EST DONC ABSENTE DU CATALOGUE, et une page absente du catalogue
// na ni nom ni adresse — cest ce qui empeche une phrase ou un lien de
// la nommer encore quelque part sans que rien ne le dise.
ok( 'elle nest plus au catalogue',            isset( DZE_Screens::catalog()['review'] ), false );
ok( 'elle na plus de nom',                    DZE_Screens::name( 'review' ), '' );
ok( 'elle na plus dadresse',                  DZE_Screens::url( 'review' ), '' );
ok( 'et elle nest plus proposee',             DZE_Screens::offered( 'review' ), false );
$GLOBALS['off'] = [ 'diagnostic' ];
ok( 'et le diagnostic eteint ny change rien', DZE_Screens::url( 'review' ), '' );
ok( 'and the host itself is not offered',     DZE_Screens::offered( 'content' ), false );
ok( 'nor named',                              DZE_Screens::name( 'content' ), 'Dazont Ecom → Diagnostic' );
ok( 'but has no address',                     DZE_Screens::url( 'content' ), '' );
$GLOBALS['off'] = [];

echo "\nA SCREEN WHOSE MODULE IS OFF IS NOT OFFERED\n";
// A link to a page that is not there is worse than no link.
$GLOBALS['off'] = [ 'gmc' ];
ok( 'a tab whose module is off is gone from the strip',
	array_keys( DZE_Screens::tabs_of( 'marketing' ) ), [ 'events', 'discounts' ] );
ok( 'and has no address',                     DZE_Screens::url( 'marketing', 'gmc' ), '' );
ok( 'and is not among the links',
	isset( DZE_Screens::links()['Dazont Ecom → Marketing → Google Merchant Center'] ), false );
$GLOBALS['off'] = [ 'health' ];
ok( 'the Logs stay when the health module is off',  DZE_Screens::offered( 'logs' ), true );
ok( 'without their connections tab',
	array_keys( DZE_Screens::tabs_of( 'logs' ) ), [ 'calls', 'spend', 'past' ] );
// ET CE QUE LE PLUGIN A FAIT TOUT SEUL EST ICI, avec son annulation : cetait le
// second onglet dune page Automation sortie du menu, donc le seul endroit ou
// reprendre ce quune passe nocturne a publie netait atteignable de nulle part.
$GLOBALS['off'] = [ 'automation' ];
ok( 'et longlet des passes part avec son module',
	array_keys( DZE_Screens::tabs_of( 'logs' ) ), [ 'calls', 'spend', 'health' ] );
$GLOBALS['off'] = [];
ok( 'et il est la quand le module tourne',
	isset( DZE_Screens::tabs_of( 'logs' )['past'] ), true );
$GLOBALS['off'] = [];

echo "\nTHE LINKS ARE EVERY PHRASE, AND NOTHING ELSE\n";
$dze_links = DZE_Screens::links();
ok( 'a page phrase is there',             $dze_links['Dazont Ecom → Logs'] ?? '', 'http://shop.test/wp-admin/admin.php?page=dazont-ecom-logs' );
ok( 'a tab phrase is there',              isset( $dze_links['Dazont Ecom → Marketing → Events & calendar'] ), true );
// Et le maillage est desormais une PAGE, plus un onglet : sa phrase le dit.
ok( 'le maillage est une page a lui',
	$dze_links['Dazont Ecom → Internal linking'] ?? '', 'http://shop.test/wp-admin/admin.php?page=dazont-ecom-linking' );
ok( 'a settings phrase is there',         isset( $dze_links['Settings → Email campaigns'] ), true );
// ET AUCUNE PHRASE NE LA NOMME PLUS. Les phrases sont ce que le moteur de
// recherche de ladmin propose : une entree vers une page supprimee est
// une impasse que personne ne signale.
ok( 'aucune phrase ne nomme la liste supprimee', isset( $dze_links['Dazont Ecom → To review'] ), false );
// Les deux onglets qui la remplacent, eux, sont bien la.
ok( 'le maillage a son onglet a relire',
	isset( $dze_links['Dazont Ecom → Internal linking → To review'] ), true );
ok( 'et les traductions le leur',
	isset( $dze_links['Dazont Ecom → WPML Translations → To review'] ), true );
ok( 'and every address is an admin one',
	count( array_filter( $dze_links, static fn( $u ) => 0 === strpos( (string) $u, 'http://shop.test/wp-admin/' ) ) ),
	count( $dze_links ) );
ok( 'and none is empty',                  in_array( '', $dze_links, true ), false );

echo "\nTHE MENU: THE WORK FIRST, THE PLUMBING LAST\n";
// Rows as WordPress holds them: [ label, cap, slug, title ].
$dze_rows = [
	[ 'Settings', 'c', 'dazont-ecom-ai' ],
	[ 'Logs', 'c', 'dazont-ecom-logs' ],
	[ 'Restock', 'c', 'dazont-ecom' ],
	[ 'Something new', 'c', 'dazont-ecom-new-thing' ],
	[ 'Content', 'c', 'dazont-ecom-diagnostic' ],
	[ 'Setup', 'c', 'dze-setup' ],
	[ 'Dashboard', 'c', 'dazont-ecom-dashboard' ],
	[ 'Marketing', 'c', 'dazont-ecom-marketing-events' ],
];
$dze_order = array_map( static fn( array $r ): string => $r[0], DZE_Screens::ordered( $dze_rows ) );
ok( 'the dashboard opens the menu',       $dze_order[0], 'Dashboard' );
// ET CEST CE QUI DECIDE DE LA PAGE DACCUEIL. wp-admin/menu-header.php batit
// lancre de lentree de premier niveau depuis $submenu_items[0][2] : cest le
// PREMIER sous-menu quon ouvre en pressant « Dazont Ecom », jamais la page du
// slug parent. Aucune adresse na donc a bouger pour changer ce quon ouvre —
// et celle qui avait bouge etait une adresse publique changee pour rien.
ok( 'et laccueil est le premier de lordre', DZE_Screens::menu_order()[0], 'dashboard' );
ok( 'the content work comes next',        $dze_order[1], 'Content' );
ok( 'then the marketing',                 $dze_order[2], 'Marketing' );
ok( 'the settings are last',              end( $dze_order ), 'Settings' );
ok( 'the logs just before them',          $dze_order[ count( $dze_order ) - 2 ], 'Logs' );
ok( 'the setup before the logs',          $dze_order[ count( $dze_order ) - 3 ], 'Setup' );
// A PAGE THIS CATALOGUE HAS NEVER HEARD OF is kept among the work, not
// dropped and not thrown to the bottom under the plumbing.
ok( 'an unknown entry keeps a place among the work',
	array_search( 'Something new', $dze_order, true ) < array_search( 'Setup', $dze_order, true ), true );
ok( 'and nothing was lost',               count( $dze_order ), count( $dze_rows ) );

// AND IT IS HOOKED where the plugin always boots, after every module has had
// its say: a reorder registered from a module would go with that module.
$dze_mod = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-modules.php' );
ok( 'the reorder is hooked from the module manager',
	false !== strpos( $dze_mod, "add_action( 'admin_menu', [ 'DZE_Screens', 'reorder_menu' ], 999 )" ), true );
ok( 'and the dashboard no longer moves itself by hand',
	false !== strpos( (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-dashboard.php' ), 'array_unshift' ), false );

echo "\nEVERY PAGE IS CALLED WHAT THE MENU CALLS IT\n";
// "Dazont Ecom — Dashboard" over a menu entry reading "Dashboard", "Marketing
// Events" on one tab of a page called "Marketing", a bold line of its own on
// the Sourcing screen: the same screen named twice. The heading is read from
// the catalogue on every page that has one.
foreach ( [
	'class-dashboard.php'        => 'dashboard',
	'class-diagnostic.php'       => 'content',
	'class-discounts.php'        => 'marketing',
	'class-translate-screen.php' => 'translations',
	'class-automation.php'       => 'automation',
	'class-content.php'          => 'bulk',
	'class-netlinking.php'       => 'netlinking',
	'class-shortcodes.php'       => 'shortcodes',
	'class-setup.php'            => 'setup',
	'class-health.php'           => 'logs',
	'class-marketing-ai.php'     => 'settings',
	'class-modules.php'          => 'modules',
] as $dze_file => $dze_id ) {
	$dze_src = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/' . $dze_file );
	ok( "$dze_file heads its page with the catalogue's name",
		false !== strpos( $dze_src, "DZE_Screens::label( '$dze_id' )" ), true );
}
ok( 'and so does the Sourcing view',
	false !== strpos( (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/views/explorer-page.php' ), "DZE_Screens::label( 'sourcing' )" ), true );
ok( 'nothing is still called "Dazont Ecom — Something"',
	array_values( array_filter( glob( __DIR__ . '/../' . $dir . '/includes/*.php' ), static fn( $f ) => false !== strpos( (string) file_get_contents( $f ), "__( 'Dazont Ecom — " ) ) ), [] );

echo "\nTHE HOME SCREEN SAYS WHAT WAITS FOR A PERSON, FIRST\n";
// The Dashboard said what sold and what was spent and nothing about the three
// texts waiting for a yes, the translation nobody had read, the connection
// down since Monday or the key never entered — the questions somebody opens
// the plugin to answer. One block, first, one line per thing, read from the
// module that owns each answer; a nought is not printed, and nothing waiting
// says so in words. THE PAGE IS DRAWN, not the helper called: calling
// `waiting()` proves the reader works and nothing about whether the screen
// asks for it.
function __n_stub() {}
if ( ! function_exists( '_n' ) ) { function _n( $a, $b, $n, $d = '' ) { return 1 === (int) $n ? $a : $b; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = '' ) { return esc_html( $s ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return esc_html( $s ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); } }
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return true; } }
if ( ! function_exists( 'add_action' ) ) { function add_action( ...$a ) {} }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( ...$a ) { return true; } }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $m = '' ) { throw new RuntimeException( (string) $m ); } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['dze_trans'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { $GLOBALS['dze_trans'][ $k ] = $v; return true; } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t = 'timestamp' ) { return 'timestamp' === $t ? time() : gmdate( 'Y-m-d' ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'get_term_meta' ) ) { function get_term_meta( ...$a ) { return 0; } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( ...$a ) { return '1 hour'; } }
if ( ! function_exists( 'date_i18n' ) ) { function date_i18n( $f, $t = null ) { return gmdate( $f, $t ?? time() ); } }
if ( ! function_exists( 'wc_get_product' ) ) { function wc_get_product( $id ) { return null; } }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
$GLOBALS['wpdb'] = new class { public $prefix = 'wp_'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $termmeta = 'wp_termmeta'; public function prepare( $q, ...$a ) { return $q; } public function get_results( ...$a ) { return []; } public function get_var( ...$a ) { return null; } };
// The modules that own each answer, answering what the fake shop holds.
$GLOBALS['dze_wait'] = [ 'queue' => 3, 'mesh' => 2, 'bulk' => 2, 'tr' => 1, 'cal' => 0, 'down' => [], 'todo' => [] ];
// Fidele : la vraie file connait ses genres et sait les compter par famille,
// et la boite de reception demande les deux pour separer le maillage du reste.
class DZE_Queue      {
	public static function review_count() { return (int) $GLOBALS['dze_wait']['queue']; }
	public static function kinds() { return [ 'cat_desc' => [], 'cat_links' => [], 'post_links' => [], 'product_shot' => [] ]; }
	public static function counts_for( array $k ) {
		$mesh = array_intersect( $k, [ 'cat_links', 'post_links' ] );
		$n    = $mesh ? (int) ( $GLOBALS['dze_wait']['mesh'] ?? 0 ) : (int) $GLOBALS['dze_wait']['queue'];
		return [ 'review' => $n ];
	}
	// LA LISTE CENTRALE A ETE SUPPRIMEE : cest cette fonction qui la remplace,
	// et elle rend lecran du module qui relit ce genre de travail.
	public static function review_url( array $k = [] ) {
		return array_intersect( $k, [ 'cat_links', 'post_links' ] )
			? DZE_Screens::url( 'linking', 'review' )
			: DZE_Screens::url( 'bulk', 'categories' );
	}
}
class DZE_Mesh       { const KINDS = [ 'cat_links', 'post_links' ]; const MENU_SLUG = 'dazont-ecom-linking'; }
class DZE_Content    { public static function pending_count() { return (int) $GLOBALS['dze_wait']['bulk']; } }
class DZE_Translate  { public static function review_count() { return (int) $GLOBALS['dze_wait']['tr']; } }
class DZE_Marketing_Ai { const MENU_SLUG = 'dazont-ecom-ai'; public static function pending_count() { return (int) $GLOBALS['dze_wait']['cal']; } }
class DZE_Health     { public static function state() { $c = []; foreach ( (array) $GLOBALS['dze_wait']['down'] as $id ) { $c[ $id ] = [ 'state' => 'down' ]; } return [ 'checks' => $c ]; } public static function labels() { return [ 'gmc' => 'Google Merchant Center', 'fal' => 'fal.ai (the images)' ]; } }
class DZE_Setup      { public static function score() { return [ 'done' => 3, 'need' => 10, 'todo' => (array) $GLOBALS['dze_wait']['todo'] ]; } }
class DZE_Restock    { const MENU_SLUG = 'dazont-ecom'; public static function get_line_index() { return []; } public static function get_line_sales( $id ) { return 0; } }
class DZE_Explorer   { const MENU_SLUG = 'dazont-ecom-explorer'; const META_RESEARCHED = '_dze_researched'; }
class DZE_Discounts  { const MENU_SLUG = 'dazont-ecom-discounts'; const MENU_SLUG_EVENTS = 'dazont-ecom-marketing-events'; public static function get_rules() { return []; } }
class DZE_Ai_Usage   {
	public static function render_graph( ...$a ) { echo '<div id="dze-usage-graph"></div>'; }
	// Les quatre chiffres seuls : ce que la page daccueil montre desormais,
	// le rapport entier restant dans le journal des depenses.
	public static function render_summary( ...$a ) { echo '<div id="dze-usage-summary"></div>'; }
}
require __DIR__ . '/../' . $dir . '/includes/class-dashboard.php';
$GLOBALS['off'] = [];
ob_start(); DZE_Dashboard::instance()->render_page(); $dze_home = (string) ob_get_clean();
$dze_first = (int) strpos( $dze_home, 'Waiting for you' );
ok( 'the page draws the block',                  false !== strpos( $dze_home, 'Waiting for you' ), true );
// Et en PLEINE LARGEUR, avant la rangee compacte : cinq cartes de meme poids,
// tenant deux lignes dun cote et deux ecrans de tableaux de lautre, faisaient
// du bloc pour lequel la page existe une colonne etroite a cote dun rapport.
ok( 'and draws it FIRST',                        $dze_first > 0 && $dze_first < (int) strpos( $dze_home, 'The shop at a glance' ), true );
ok( 'the tables are gone from the home screen', false !== strpos( $dze_home, 'Top out-of-stock products' ), false );
ok( 'and the spend is the four figures, not the report',
	[ false !== strpos( $dze_home, 'dze-usage-summary' ), false !== strpos( $dze_home, 'dze-usage-graph' ) ], [ true, false ] );
ok( 'content waiting adds the queue and the bulk screen', false !== strpos( $dze_home, '5 pieces of content wait for your yes or no' ), true );
ok( 'and goes to the one list',                  false !== strpos( $dze_home, DZE_Screens::url( 'review' ) ), true );
ok( 'one translation, in the singular',          false !== strpos( $dze_home, '1 translation waits to be read' ), true );
ok( 'and goes to the Translations review tab',   false !== strpos( $dze_home, DZE_Screens::url( 'translations', 'review' ) ), true );
ok( 'a nought is not printed',                   false !== strpos( $dze_home, 'suggested by the calendar' ), false );
ok( 'nothing down, no line about connections',   false !== strpos( $dze_home, 'not answering' ), false );
ok( 'nothing to set up, no line about setup',    false !== strpos( $dze_home, 'still to set up' ), false );
ok( 'and the spend link goes to the Logs, not to Settings', false !== strpos( $dze_home, DZE_Screens::url( 'logs', 'spend' ) ) && false === strpos( $dze_home, 'Open Settings' ), true );
ok( 'the marketing link is named by the catalogue', false !== strpos( $dze_home, 'Open Marketing →' ), true );
// A connection down and a key missing are lines too, each on its own screen.
$GLOBALS['dze_wait'] = [ 'queue' => 0, 'mesh' => 0, 'bulk' => 0, 'tr' => 0, 'cal' => 2, 'down' => [ 'gmc' ], 'todo' => [ 'fal.ai key', 'Klaviyo key' ] ];
ob_start(); DZE_Dashboard::instance()->render_page(); $dze_home = (string) ob_get_clean();
ok( 'the calendar\'s suggestions',               false !== strpos( $dze_home, '2 promotions suggested by the calendar wait for an answer' ), true );
ok( 'named where they are answered',             false !== strpos( $dze_home, DZE_Screens::url( 'marketing', 'events' ) ), true );
ok( 'a connection down names the connection',    false !== strpos( $dze_home, 'Google Merchant Center is not answering' ), true );
ok( 'and goes to the Connections log',           false !== strpos( $dze_home, DZE_Screens::url( 'logs', 'health' ) ), true );
ok( 'what is not set up says how many and the first', false !== strpos( $dze_home, '2 things are still to set up, starting with fal.ai key' ), true );
ok( 'and goes to Setup',                         false !== strpos( $dze_home, DZE_Screens::url( 'setup' ) ), true );
ok( 'no content line when nothing waits',        false !== strpos( $dze_home, 'wait for your yes or no' ), false );
// A MODULE SWITCHED OFF CONTRIBUTES NOTHING — the translation module off is
// not a shop with no translations to read, it is a shop without the module.
$GLOBALS['dze_wait'] = [ 'queue' => 0, 'bulk' => 0, 'tr' => 4, 'cal' => 0, 'down' => [], 'todo' => [] ];
$GLOBALS['off'] = [ 'translate' ];
ob_start(); DZE_Dashboard::instance()->render_page(); $dze_home = (string) ob_get_clean();
ok( 'translations off: no translation line',     false !== strpos( $dze_home, 'translation' ), false );
ok( 'and nothing waiting says so in words',      false !== strpos( $dze_home, 'Nothing is waiting for you.' ), true );
$GLOBALS['off'] = [];

echo "\nCHAQUE ECRAN NE VOIT ET NE TOUCHE QUE SON PROPRE TRAVAIL\n";
// « Je dois souvent trop cliquer pour avoir acces a x ou y chose. » Une liste
// unique tenant les categories, les articles, les photographies et les passes
// de maillage est une liste que personne ne lit — et un « Vider » qui balaie
// une photographie que quelqu'un examine encore est un bouton qui fait plus
// que ce qu'il dit.
$sc_src = file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-queue.php' );
ok( 'la file sait se limiter a une famille',
	false !== strpos( $sc_src, 'public static function rows( int $limit = 200, array $kinds = [], bool $all = false ): array' ), true );
// ET CE QUI EST DECIDE QUITTE LA LISTE. Un ecran nomme « A traiter » tenant des
// lignes deja traitees est un ecran ou le travail restant se cherche.
ok( 'les lignes decidees quittent la liste',
	false !== strpos( $sc_src, "\$done  = [ 'applied', 'skipped' ];" ), true );
ok( 'et la liste dit combien elle en a range',
	false !== strpos( $sc_src, 'decisions have already been taken' ), true );
$sc_js2 = file_get_contents( __DIR__ . '/../dazont-ecom/admin/js/queue.js' );
ok( 'et le shift-clic coche une plage',
	false !== strpos( $sc_js2, 'e.shiftKey && lastPick !== null' ), true );
ok( 'la portee est nettoyee contre le catalogue',
	false !== strpos( $sc_src, '$kinds = self::clean_kinds( $kinds );' ), true );
ok( 'l\'ecran passe sa portee a son corps',
	false !== strpos( $sc_src, 'public function body( array $kinds = [] ): void' ), true );
ok( 'et elle voyage jusqu\'au navigateur',
	false !== strpos( $sc_src, "'kinds'   => array_values( self::clean_kinds( \$kinds ) )" ), true );
ok( 'le vidage est limite lui aussi',
	false !== strpos( $sc_src, '$scope = $kinds ? " AND kind IN' ), true );
$sc_js = file_get_contents( __DIR__ . '/../dazont-ecom/admin/js/queue.js' );
ok( 'le JS envoie la portee en lisant la liste',
	false !== strpos( $sc_js, "action: 'dze_q_status', nonce: cfg.nonce, kinds: cfg.kinds || []" ), true );
ok( 'et en vidant',
	false !== strpos( $sc_js, "action: 'dze_q_clear', nonce: cfg.nonce, kinds: cfg.kinds || []" ), true );
// Les deux hotes, chacun sa moitie, et la difference plutot qu'une liste.
$sc_mesh = file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-mesh.php' );
ok( 'le maillage possede ses deux genres',
	false !== strpos( $sc_mesh, "public const KINDS = [ 'cat_links', 'post_links' ];" ), true );
// IL REUTILISE LA LISTE, IL N EN DESSINE PAS UNE AUTRE.
//
// « Je ne comprends pas pourquoi sur la page internal linking il n'y a pas
// d'onglet pour me montrer ce qui a été fait et un onglet review pour ce qui
// est en attente de vérif ? Je suis encore perdu face à l'UI. »
//
// La regle d avant — « pas de seconde liste ici » — visait une seconde
// IMPLEMENTATION : une table de plus, avec un compte de plus, pouvant
// contredire les autres. Elle avait ete appliquee en retirant la liste tout
// court, ce qui laissait l ecran sans porte d entree : une phrase de notice
// quand il y avait quelque chose, rien du tout sinon, et le travail fait
// nulle part. Rappeler LA MEME fonction avec son perimetre ne fabrique ni
// table ni compte supplementaire.
ok( 'le maillage rappelle la liste centrale, reduite a lui',
	false !== strpos( $sc_mesh, 'DZE_Queue::instance()->body( self::KINDS )' ), true );
ok( 'et le journal central pour ce qui est fait',
	false !== strpos( $sc_mesh, "DZE_Automation::render_past( 'mesh_links' )" ), true );
// ET IL NE FABRIQUE PAS SA PROPRE FILE : l onglet du travail garde ses
// tables a lui — la liste des pages et celle des candidates — mais la file
// d attente et le journal ne sont jamais redessines ici.
ok( 'sans redessiner la file',
	false !== strpos( $sc_mesh, 'dze-q-table' ), false );
// LES TROIS ONGLETS SONT DECLARES AU CATALOGUE, comme ceux des autres ecrans :
// une seule source pour le nom, l adresse et l ordre. Demande au catalogue,
// pas au texte du fichier : c est ce que l ecran lira.
$sc_onglets = array_keys( (array) DZE_Screens::tabs_of( 'linking' ) );
ok( 'les trois onglets sont au catalogue', $sc_onglets, [ 'work', 'review', 'done' ] );
ok( 'et chacun a son adresse',
	false !== strpos( DZE_Screens::url( 'linking', 'done' ), 'tab=done' ), true );
// ET LE JOURNAL SAIT SE REDUIRE SANS PASSER PAR L ADRESSE : un ecran de module
// ne peut pas compter sur un ?task= qu il n a pas mis lui-meme.
$sc_auto = file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-automation.php' );
ok( 'le journal accepte une tache en argument',
	false !== strpos( $sc_auto, 'public static function render_past( string $only = \'\' ): void' ), true );
$sc_diag = file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-diagnostic.php' );
// PRODUITS NE DESSINE PLUS LA LISTE DU TOUT, et son compteur ne compte plus que
// ce qui attend sur son propre ecran : compter la file ici mettait sur ce menu un
// chiffre que son ecran ne pouvait pas montrer — le defaut que la boutique a vu.
ok( 'Produits ne dessine plus la liste',
	false !== strpos( $sc_diag, 'array_diff( array_keys( DZE_Queue::kinds() ), DZE_Mesh::KINDS )' ), false );
ok( 'et il ne porte plus aucun compteur',
	false !== strpos( $sc_diag, '$waiting = DZE_Queue::bulk_waiting();' ), false );
// LE BANC PORTE LE SIEN, la ou ces produits sont vraiment.
$sc_cont = file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-content.php' );
ok( 'le banc porte la pastille',
	false !== strpos( $sc_cont, 'DZE_Queue::bulk_waiting()' ) && false !== strpos( $sc_cont, '$menu = $waiting > 0' ), true );

echo "\nL'AUTOMATISME EST UNE PROPRIETE DU TRAVAIL, PAS UNE DESTINATION\n";
// « Dans automations en fait il n'y aura rien, c'etait peut-etre maladroit de
// faire ce module. C'est plutot une facon de faire pour automatiser differents
// modules. » Un reglage qui vit a trois menus de l'ecran sur lequel il agit est
// un reglage que personne ne trouve.
$au_src = file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-automation.php' );
ok( 'le panneau d\'une tache est reutilisable',
	false !== strpos( $au_src, 'public static function panel( string $id, bool $open = false ): void' ), true );
// ET IL SOUVRE QUAND IL EST SEUL. Replie, sur lecran du travail quil pilote,
// linterrupteur que la boutique voulait a portee de main est un interrupteur
// derriere un pli — soit exactement le clic dont elle se plaignait.
ok( 'le panneau souvre quand il est seul',
	false !== strpos( $au_src, '$open = 1 === count( $ids );' ), true );
ok( 'et le pli reste quand ils sont plusieurs',
	false !== strpos( $au_src, "echo \$open ? ' open' : '';" ), true );
ok( 'et son formulaire se pose n\'importe ou',
	false !== strpos( $au_src, 'public static function panel_form( array $ids, string $title' ), true );
// LE PIEGE : un formulaire ne portant qu'une tache effacerait les autres.
ok( 'les taches absentes de l\'ecran voyagent quand meme',
	false !== strpos( $au_src, 'EVERY TASK TRAVELS, NOT ONLY THE ONES ON SCREEN' ), true );
// ET IL DISPARAIT AVEC SON MODULE. class_exists() nest pas un controle de
// module — le fichier de classe est toujours la — donc ce panneau setait
// imprime sur quatre ecrans quel que soit letat du module, avec un Save et un
// Run morts une fois ses accroches parties.
ok( 'le panneau se ferme avec son module',
	false !== strpos( $au_src, "if ( class_exists( 'DZE_Modules' ) && ! DZE_Modules::enabled( 'automation' ) ) {" ), true );
ok( 'l\'entree quitte le menu',
	false !== strpos( $au_src, 'remove_submenu_page( DZE_Restock::MENU_SLUG, self::MENU_SLUG );' ), true );
ok( 'mais la page reste enregistree',
	false !== strpos( $au_src, "[ __CLASS__, 'render_page' ]" ), true );
ok( 'et l\'ordre du menu ne la nomme plus',
	in_array( 'automation', DZE_Screens::menu_order(), true ), false );
// CHAQUE MODULE PORTE SON PROPRE INTERRUPTEUR.
foreach ( [
	'class-mesh.php'            => 'mesh_links',
	'class-category-content.php' => 'cat_desc',
	'class-translate-screen.php' => 'translate',
	'class-discounts.php'       => 'events',
] as $au_file => $au_task ) {
	$au_one = file_get_contents( __DIR__ . '/../dazont-ecom/includes/' . $au_file );
	ok( "$au_file porte l'interrupteur de $au_task",
		false !== strpos( $au_one, "DZE_Automation::panel_form( [ '$au_task' ]" ), true );
}

echo "\nLA BOITE DE RECEPTION NOMME LES ECRANS, ET IL N'Y EN A QU'UNE\n";
// Une ligne disait « 14 pieces of content wait for your yes or no » et ouvrait
// un ecran tenant quatre travaux sans rapport. Un compte sur lequel on ne peut
// pas agir a un seul endroit est un compte qui envoie chercher.
$in_src = file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-dashboard.php' );
// CHAQUE LIGNE OUVRE LECRAN QUI RELIT SON PROPRE TRAVAIL. Elles ouvraient
// toutes la meme liste centrale, pre-filtree ; cette liste a ete supprimee
// — « on simplifie plutot que de complexifier » — et cest desormais le
// routeur qui dit ou, depuis les genres de travail eux-memes.
ok( 'le maillage a sa propre ligne',
	false !== strpos( $in_src, "DZE_Queue::review_url( \$mesh )" ), true );
ok( 'et le reste ouvre letabli, ou il se relit',
	false !== strpos( $in_src, "DZE_Screens::url( 'bulk' )" ), true );
ok( 'et plus rien ne nomme lecran supprime',
	false !== strpos( $in_src, "DZE_Screens::url( 'review' )" ), false );
ok( 'le reste est pris par difference',
	false !== strpos( $in_src, "array_diff( array_keys( DZE_Queue::kinds() ), \$mesh )" ), true );
ok( 'et plus rien ne pointe vers longlet disparu',
	false !== strpos( $in_src, "DZE_Screens::url( 'content', 'review' )" ), false );
// ET LA LISTE CENTRALE NA PLUS NI ENTREE, NI PAGE, NI BARRE DE FILTRES.
//
// « Dans ce cas on supprime le menu to review. On simplifie plutot que de
// complexifier. » Le retrait avait deja ete tente une fois puis annule,
// parce quil laissait trois listes de trois portees et AUCUNE vue de tout.
// Ce nest plus vrai : lApercu recense les trois sources et envoie sur
// lecran de chacune. La vue densemble existe, ailleurs, et mieux placee.
$in_q = file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-queue.php' );
ok( 'plus aucune entree de menu',
	false !== strpos( $in_q, 'add_submenu_page' ), false );
ok( 'plus de page autour de la table',
	false !== strpos( $in_q, 'public function render(): void' ), false );
ok( 'ni de barre de filtres a elle',
	false !== strpos( $in_q, 'public static function rail(' ), false );
// MAIS LA TABLE RESTE : cest elle que le maillage et le banc des categories
// rappellent avec leur propre portee. Ce qui disparait est lenveloppe.
ok( 'mais la table reste, portee par son hote',
	false !== strpos( $in_q, 'public function body( array $kinds = [] ): void' ), true );
ok( 'et un routeur dit ou chaque genre se relit',
	false !== strpos( $in_q, 'public static function review_url( array $kinds = [] ): string' ), true );
// ET LES ANCIENNES ADRESSES ATTERRISSENT ENCORE : une page qui nest plus
// declaree ne repond pas « introuvable », WordPress repond « vous navez
// pas lautorisation », ce qui se lit comme un droit perdu.
ok( 'et les vieux signets sont renvoyes',
	false !== strpos( $in_q, 'wp_safe_redirect( $to );' ), true );
ok( 'et lordre du menu ne la nomme plus',
	in_array( 'review', DZE_Screens::menu_order(), true ), false );
// LE MENU FINAL : le travail du jour en haut, la plomberie en bas.
ok( 'le menu est celui voulu', DZE_Screens::menu_order(), [
	'dashboard', 'content', 'bulk', 'linking', 'netlinking', 'lab', 'translations', 'marketing',
	'restock', 'fbt', 'sourcing', 'shortcodes', 'setup', 'logs', 'settings', 'modules',
] );

echo "\nUN SEUL ETABLI, UN ONGLET PAR SUJET\n";
// « Menu Categories existant et vide, aucun sens. Plutot regrouper dans bulk
// writing. Avec products. On pourra plus tard y mettre blog post aussi. »
$bl_tabs = DZE_Screens::tabs_of( 'bulk' );
ok( 'letabli a les deux sujets', array_keys( $bl_tabs ), [ 'products', 'categories' ] );
ok( 'et les categories nont plus dentree a elles',
	in_array( 'categories', DZE_Screens::menu_order(), true ), false );
// Ladresse dun onglet doit exister, sinon la barre envoie dans le vide.
ok( 'et chaque onglet a une adresse', [
	'' !== DZE_Screens::url( 'bulk', 'products' ),
	'' !== DZE_Screens::url( 'bulk', 'categories' ),
], [ true, true ] );
$bl_cat = (string) file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-category-content.php' );
ok( 'la classe nenregistre plus de menu',
	false === strpos( $bl_cat, 'add_submenu_page' ), true );
ok( 'elle imprime un corps, pas une page a elle',
	[ false !== strpos( $bl_cat, 'function render_bench(): void' ), false !== strpos( $bl_cat, "'wrap dze-admin'" ) ],
	[ true, false ] );
$bl_con = (string) file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-content.php' );
ok( 'et letabli sait ouvrir sur les categories',
	false !== strpos( $bl_con, "DZE_Category_Content::instance()->render_bench()" ), true );

echo "\nLES LISTES DU MAILLAGE SE REPLIENT\n";
// « Pages short of links — je t'ai dit d'utiliser le meme type d'affichage
// que Pages that take part in linking. La tout est ouvert et c'est tres
// bordelique. » Deux tables de deux cents lignes au-dessus du travail.
$bl_mesh = (string) file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-mesh.php' );
ok( 'les deux listes sont des replis', [
	false !== strpos( $bl_mesh, 'id="dze-mesh-needsbox"' ),
	false !== strpos( $bl_mesh, 'id="dze-mesh-endsbox"' ),
], [ true, true ] );
ok( 'et plus aucun titre ne les surmonte',
	substr_count( $bl_mesh, '<h2 style="margin-top:28px;">' ), 0 );
ok( 'le compte est sur le repli, pour ne pas avoir a louvrir',
	substr_count( $bl_mesh, 'Pages short of links — %s' ), 1 );

echo "\nLINTERRUPTEUR TROUVE JQUERY\n";
// « Runs by itself ne fonctionne pas. » Le script est imprime dans le corps
// de la page ; jQuery ny etait quen dependance dun script de pied de page.
$bl_aut = (string) file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-automation.php' );
ok( 'il imprime jquery la ou il en a besoin',
	false !== strpos( $bl_aut, "wp_print_scripts( 'jquery' )" ), true );
ok( 'et il le fait AVANT son propre script',
	strpos( $bl_aut, "wp_print_scripts( 'jquery' )" ) < strpos( $bl_aut, "jQuery( function ( \$ ) {" ), true );
ok( 'et il le dit plutot que de mourir en silence',
	false !== strpos( $bl_aut, "typeof jQuery === 'undefined'" ), true );

echo "\nLINTERRUPTEUR NEST QUE SUR LECRAN QUIL COMMANDE\n";
// « Attention ces blocs sont aussi visibles sur des pages hors sujet comme le
// batch onglet. » Imprime AVANT le bandeau donglets, il se tenait sur tous —
// y compris dans lediteur dUN produit quon traduit a la main. Un interrupteur
// de passe nocturne au-dessus de ce travail-la est du mobilier qui gene.
$sw_src = [
	'class-translate-screen.php' => "'dashboard' === \$tab && class_exists( 'DZE_Automation' )",
	// L ecran des promotions le faisait deja, cote evenements seulement.
	'class-discounts.php'        => "'events' === \$mode && class_exists( 'DZE_Automation' )",
];
foreach ( $sw_src as $sw_f => $sw_want ) {
	$sw_s = (string) file_get_contents( __DIR__ . '/../dazont-ecom/includes/' . $sw_f );
	ok( 'le bandeau de ' . $sw_f . ' est borne', false !== strpos( $sw_s, $sw_want ), true );
}
// ET LES DEUX ECRANS SANS ONGLETS LE GARDENT SANS CONDITION : il ny a quune
// vue, donc il ny a pas de hors-sujet possible.
$sw_mesh = (string) file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-mesh.php' );
ok( 'le maillage, qui na quune vue, le porte toujours',
	false !== strpos( $sw_mesh, "DZE_Automation::panel_form( [ 'mesh_links' ]" ), true );
// ET LETABLI NE LE MET QUE SUR LE SUJET QUI A UNE TACHE : les produits nen
// ont pas, les categories oui.
$sw_bulk = (string) file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-content.php' );
ok( 'letabli produits ne porte pas dinterrupteur',
	false !== strpos( $sw_bulk, 'DZE_Automation::panel_form' ), false );
$sw_cat = (string) file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-category-content.php' );
ok( 'mais le banc des categories, oui',
	false !== strpos( $sw_cat, "DZE_Automation::panel_form( [ 'cat_desc' ]" ), true );

echo "\nUNE SEULE BARRE DONGLETS, IMPRIMEE EN UN SEUL ENDROIT\n";
// Six ecrans batissaient la leur, chacune quasi-copie des autres — meme
// balisage, memes classes, meme compteur. Et lune delles, celle du maillage,
// etait batie avec des cles que le catalogue navait jamais vues, si bien que
// sa seconde vue ne pouvait etre ni nommee dans une phrase ni liee dailleurs.
ok( 'limprimeur existe', method_exists( 'DZE_Screens', 'strip' ), true );
$st_dir = __DIR__ . '/../dazont-ecom/includes/';
$st_bad = [];
foreach ( glob( $st_dir . '*.php' ) as $st_f ) {
	if ( 'class-screens.php' === basename( $st_f ) ) { continue; }
	$st_s = (string) file_get_contents( $st_f );
	if ( false !== strpos( $st_s, 'nav-tab-wrapper' ) ) { $st_bad[] = basename( $st_f ); }
}
// Plus aucune : la barre groupee des Reglages garde son groupement, qui est
// a elle, et confie le balisage a limprimeur comme les autres.
ok( 'les barres batties a la main sont comptees', count( $st_bad ), 0 );
ok( 'et les trois converties nen sont plus',
	array_intersect( [ 'class-health.php', 'class-translate-screen.php' ], $st_bad ), [] );

echo "\nLINTERRUPTEUR EST EN HAUT, DISCRET, ET DIT CE QUIL A LAISSE\n";
// « Run it by itself devrait etre en haut de page… Cest lequivalent de la
// cerise sur le gateau, pas lassiette en bas de page. Partout la ou il existe. »
$ab_src = file_get_contents( __DIR__ . '/../dazont-ecom/includes/class-automation.php' );
ok( 'la barre a sa classe a elle',
	false !== strpos( $ab_src, 'dze-admin dze-auto dze-auto-strip' ), true );
ok( 'elle compte ce qui attend',
	false !== strpos( $ab_src, 'results are waiting for your yes or no' ), true );
ok( 'et donne le chemin vers lecran qui relit ce travail',
	false !== strpos( $ab_src, 'DZE_Queue::review_url( array_unique( $kinds ) )' ), true );
$ab_css = file_get_contents( __DIR__ . '/../dazont-ecom/admin/css/content.css' );
ok( 'et elle a un style discret', false !== strpos( $ab_css, '.dze-auto-strip {' ), true );
// UN NOM POUR UNE CHOSE. « dze-auto-bar » est la barre de PROGRESSION
// dune passe, qui vit a linterieur de ce panneau : le panneau lavait
// pris, sa regle gagnait, et la barre de progression portait le cadre
// et le fond du panneau.
ok( 'et elle ne vole pas le nom de la barre de progression', [
	false !== strpos( $ab_css, '.dze-auto-bar > span' ),
	false !== strpos( $ab_src, 'dze-auto dze-auto-bar"' ),
], [ true, false ] );
// SUR CHAQUE ECRAN QUI EN PORTE UNE, avant le travail dont elle decide.
foreach ( [ 'class-mesh.php', 'class-category-content.php', 'class-translate-screen.php', 'class-discounts.php' ] as $ab_f ) {
	$ab_s = file_get_contents( __DIR__ . '/../dazont-ecom/includes/' . $ab_f );
	ok( "$ab_f porte la barre", false !== strpos( $ab_s, 'DZE_Automation::panel_form(' ), true );
}

echo "\nUNE CLASSE TAILLEE POUR UN SIGNE N HABILLE PAS UN BOUTON QUI PARLE\n";
// « Style cassé. Ils se chevauchent. » Le bouton « Discard (7) » portait
// `dze-cb-no`, qui est la petite croix des listes : width 26px, height 26px.
// Le texte debordait d un carre de vingt-six pixels et le message d etat
// passait par-dessus. Ces deux classes dessinent UN caractere ; un bouton qui
// porte des mots ne peut pas les porter.
$dze_sized = [];
foreach ( glob( __DIR__ . '/../' . $dir . '/includes/*.php' ) as $dze_f ) {
	$dze_src = (string) file_get_contents( $dze_f );
	if ( ! preg_match_all( '~<button\b[^>]*>(.*?)</button>~s', $dze_src, $m, PREG_SET_ORDER ) ) {
		continue;
	}
	foreach ( $m as $one ) {
		if ( ! preg_match( '~class="[^"]*\bdze-cb-(yes|no)\b~', $one[0] ) ) {
			continue;
		}
		// Le contenu attendu est une entite HTML seule : &#10003; ou &#10007;.
		$dze_in = trim( $one[1] );
		if ( ! preg_match( '~^&\#?[0-9a-zA-Z]+;$~', $dze_in ) ) {
			$dze_sized[] = basename( $dze_f ) . ': ' . mb_substr( $dze_in, 0, 40 );
		}
	}
}
ok( 'no worded button wears the one-glyph size', $dze_sized, [] );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
