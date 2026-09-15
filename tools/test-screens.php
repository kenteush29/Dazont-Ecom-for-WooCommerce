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
	'marketing'    => [ 'class-discounts.php', 'MENU_SLUG_EVENTS' ],
	'translations' => [ 'class-translate-screen.php', 'MENU_SLUG' ],
	'automation'   => [ 'class-automation.php', 'MENU_SLUG' ],
	'restock'      => [ 'class-restock.php', 'MENU_SLUG' ],
	'sourcing'     => [ 'class-explorer.php', 'MENU_SLUG' ],
	'review'       => [ 'class-queue.php', 'MENU_SLUG' ],
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
ok( 'a page under Products is under Products',
	false !== strpos( DZE_Screens::url( 'bulk' ), 'edit.php?post_type=product&page=dazont-content-bulk' )
	|| false !== strpos( DZE_Screens::url( 'bulk' ), 'page=dazont-ecom-diagnostic&tab=products' ), true );
ok( 'a screen not in the catalogue answers nothing', DZE_Screens::name( 'nowhere' ), '' );
ok( 'and so does a tab the page has not got', DZE_Screens::url( 'logs', 'nowhere' ), '' );

echo "\nA HOSTED PAGE KEEPS ITS NAME AND ANSWERS WITH ITS HOST'S ADDRESS\n";
// Content to review is a tab of Content while the diagnostic hosts it. A
// sentence written for the page — there are several — must still land.
ok( 'the review list is hosted by Content',   DZE_Screens::hosted_by( 'review' ), [ 'content', 'review' ] );
ok( 'its name is its own',                    DZE_Screens::name( 'review' ), 'Dazont Ecom → Content to review' );
ok( 'and its address is the tab',
	DZE_Screens::url( 'review' ), 'http://shop.test/wp-admin/admin.php?page=dazont-ecom-diagnostic&tab=review' );
$GLOBALS['off'] = [ 'diagnostic' ];
ok( 'with the host off it stands alone',      DZE_Screens::hosted_by( 'review' ), null );
ok( 'at its own address',
	DZE_Screens::url( 'review' ), 'http://shop.test/wp-admin/admin.php?page=dazont-ecom-queue' );
ok( 'and the host itself is not offered',     DZE_Screens::offered( 'content' ), false );
ok( 'nor named',                              DZE_Screens::name( 'content' ), 'Dazont Ecom → Content' );
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
	array_keys( DZE_Screens::tabs_of( 'logs' ) ), [ 'calls', 'spend' ] );
$GLOBALS['off'] = [];

echo "\nTHE LINKS ARE EVERY PHRASE, AND NOTHING ELSE\n";
$dze_links = DZE_Screens::links();
ok( 'a page phrase is there',             $dze_links['Dazont Ecom → Logs'] ?? '', 'http://shop.test/wp-admin/admin.php?page=dazont-ecom-logs' );
ok( 'a tab phrase is there',              isset( $dze_links['Dazont Ecom → Content → Linking'] ), true );
ok( 'a settings phrase is there',         isset( $dze_links['Settings → Email campaigns'] ), true );
ok( 'a hosted page is there by its own name', isset( $dze_links['Dazont Ecom → Content to review'] ), true );
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
	'class-queue.php'            => 'review',
	'class-content.php'          => 'bulk',
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

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
