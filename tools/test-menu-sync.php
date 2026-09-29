<?php
/**
 * WPML's menu sync, pressed for the shop after a translation lands.
 *
 * Run before every release:  php tools/test-menu-sync.php dazont-ecom
 *
 * « Est-ce possible d'utiliser la fonction de synchro du menu automatiquement,
 * de WPML, quand on traduit une nouvelle taxonomie ? » — WPML's own code does
 * the work; what is tested here is everything around it: the preview read
 * back exactly as WPML's confirm screen posts it, the removals never passed
 * on, one run for a burst of translations, nothing woken for an object in no
 * menu, and the switch.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function number_format_i18n( $n ) { return (string) $n; }
function human_time_diff( $t ) { return '2 mins'; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function wp_next_scheduled( $h ) { return $GLOBALS['wp_next'][ $h ] ?? false; }
function wp_schedule_single_event( $t, $h ) { $GLOBALS['wp_next'][ $h ] = $t; $GLOBALS['wp_booked'][] = $h; return true; }
function as_get_scheduled_actions( $q, $r = 'ids' ) {
	$GLOBALS['as_asked'][] = $q;
	return ! empty( $GLOBALS['as_pending'][ $q['hook'] ?? '' ] ) ? [ 1 ] : [];
}
function as_schedule_single_action( $t, $h, $args = [], $g = '' ) {
	$GLOBALS['as_booked'][]        = [ $h, $t - time(), $g ];
	$GLOBALS['as_pending'][ $h ]   = true;
	return 1;
}

class DZE_Translate {
	public static function get_settings(): array { return (array) ( $GLOBALS['tr_settings'] ?? [] ); }
}

/** Three meta rows and a post: the only question asked of the database here. */
class Dze_Fake_Wpdb {
	public $prefix   = 'wp_';
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public $asked    = [];
	public $lock     = '1';
	public $released = 0;
	public function prepare( $q, ...$a ) {
		$a = ( 1 === count( $a ) && is_array( $a[0] ) ) ? $a[0] : $a;
		foreach ( $a as $v ) {
			$q = preg_replace( '/%[sd]/', is_int( $v ) ? (string) $v : "'" . $v . "'", $q, 1 );
		}
		return $q;
	}
	public function get_var( $q ) {
		$this->asked[] = $q;
		if ( false !== strpos( $q, 'GET_LOCK' ) ) {
			return $this->lock;
		}
		foreach ( (array) ( $GLOBALS['in_menus'] ?? [] ) as $one ) {
			if ( false !== strpos( $q, "'" . $one[0] . "'" ) && false !== strpos( $q, "'" . $one[1] . "'" ) && false !== strpos( $q, "= '" . $one[2] . "'" ) ) {
				return '1';
			}
		}
		return null;
	}
	public function query( $q ) {
		if ( false !== strpos( $q, 'RELEASE_LOCK' ) ) {
			$this->released++;
		}
		return 1;
	}
}
$GLOBALS['wpdb'] = new Dze_Fake_Wpdb();

require __DIR__ . '/../' . $dir . '/includes/class-menu-sync.php';

$ran = 0; $bad = 0;
function ok( string $what, $got, $want ): void {
	global $ran, $bad;
	$ran++;
	if ( $got === $want ) {
		echo "  ok   $what\n";
		return;
	}
	$bad++;
	echo "  WRONG $what\n       got  " . var_export( $got, true ) . "\n       want " . var_export( $want, true ) . "\n";
}
$fresh = static function (): void {
	$GLOBALS['opts']        = [];
	$GLOBALS['as_pending']  = [];
	$GLOBALS['as_booked']   = [];
	$GLOBALS['as_asked']    = [];
	$GLOBALS['wp_next']     = [];
	$GLOBALS['wp_booked']   = [];
	$GLOBALS['tr_settings'] = [];
	$GLOBALS['in_menus']    = [];
	$GLOBALS['wpdb']->asked = [];
	$GLOBALS['wpdb']->lock  = '1';
};

echo "\nWPML'S PREVIEW, READ BACK AS ITS CONFIRM SCREEN POSTS IT\n";
// The markup is WPML's own (ICLMenusSync::render_items_tree_default() and
// index_changed()): a hidden field per change, named
// sync[kind][menu][item][lang]. The confirm screen (WPML_Menu_Sync_Display)
// posts sync[kind][menu][lang][item], and do_sync() reads that order.
$html = '<tr><td>Tactical knives</td><td> - '
	. '<span class="icl_msync_item icl_msync_add">Ножи</span>'
	. '<input type="hidden" name="sync[add][5497][1234][ru]" value="Ножи &amp; клинки" />'
	. '</td></tr><tr><td>'
	. '<span class="icl_msync_item icl_msync_mov">Pouches</span>'
	. '<input type="hidden" name="sync[mov][5497][1235][ru][7]" value="Подсумки" />'
	. '</td></tr><tr><td>'
	. '<span class="icl_msync_item icl_msync_del">Old link</span>' . "\n"
	. '									<input type="hidden"' . "\n"
	. '										   name="sync[del][5497][ru][999]"' . "\n"
	. '										   value="Old link"/>'
	. '</td></tr><tr><td>'
	. '<span class="icl_msync_item icl_msync_label_changed">Devenir partenaire</span>'
	. '<input type="hidden" name="sync[label_changed][4956][987763854][fr]" value="Devenir partenaire" />'
	. '<span class="icl_msync_item icl_msync_options_changed">0</span>'
	. '<input type="hidden" name="sync[options_changed][4956][auto_add][fr]" value="" />'
	. '</td></tr>';
$plan = DZE_Menu_Sync::read_preview( $html );
ok( 'an addition, in the order do_sync() reads',     $plan['data']['add'][5497]['ru'][1234] ?? null, 'Ножи & клинки' );
ok( 'a move keeps its new place as one more key',    $plan['data']['mov'][5497]['ru'][1235] ?? null, [ 7 => 'Подсумки' ] );
ok( 'a label brought in line',                       $plan['data']['label_changed'][4956]['fr'][987763854] ?? null, 'Devenir partenaire' );
ok( 'a menu option, as sync_menu_options() reads it', $plan['data']['options_changed'][4956]['fr'] ?? null, [ 'auto_add' => '' ] );
// A REMOVAL IS NEVER PASSED ON: it stays WPML's screen's decision.
ok( 'a removal is not passed on',                    isset( $plan['data']['del'] ), false );
ok( 'but it is counted, to be said',                 $plan['left'], [ 'del' => 1 ] );
ok( 'what is done is counted by kind',               $plan['counts'], [ 'add' => 1, 'mov' => 1, 'label_changed' => 1, 'options_changed' => 1 ] );
ok( 'and the languages it touched',                  $plan['langs'], [ 'ru', 'fr' ] );
ok( 'no removal and no menu creation among what runs',
	array_values( array_intersect( DZE_Menu_Sync::KINDS, [ 'del', 'menu_translation', 'menu_translations' ] ) ), [] );
ok( 'a preview with nothing to do does nothing',     DZE_Menu_Sync::read_preview( '<p>Nothing Sync</p>' )['data'], [] );

echo "\nWOKEN ONLY FOR WHAT SITS IN A MENU\n";
$fresh();
$cat = [ 'kind' => 'term', 'id' => 5261, 'type' => 'product_cat' ];
$GLOBALS['in_menus'] = [ [ 'taxonomy', 'product_cat', '5261' ] ];
ok( 'a category in a menu is seen',                  DZE_Menu_Sync::in_a_menu( $cat ), true );
$q = (string) end( $GLOBALS['wpdb']->asked );
ok( 'asked of published menu items only',            false !== strpos( $q, "p.post_type = 'nav_menu_item' AND p.post_status = 'publish'" ), true );
ok( 'a page is asked as a post type',
	DZE_Menu_Sync::in_a_menu( [ 'kind' => 'post', 'id' => 77, 'type' => 'page' ] ), false );
ok( 'with the right words',                          false !== strpos( (string) end( $GLOBALS['wpdb']->asked ), "'post_type'" ), true );
DZE_Menu_Sync::wanted( $cat );
ok( 'its translation books one run',                 count( $GLOBALS['as_booked'] ), 1 );
ok( 'a minute later, in the plugin\'s group',        [ $GLOBALS['as_booked'][0][0], $GLOBALS['as_booked'][0][1] >= 55, $GLOBALS['as_booked'][0][2] ], [ 'dze_menu_sync', true, 'dazont-ecom' ] );
DZE_Menu_Sync::wanted( $cat );
DZE_Menu_Sync::wanted( $cat );
ok( 'a burst of translations makes one run',         count( $GLOBALS['as_booked'] ), 1 );
ok( 'only a WAITING run counts as booked',           $GLOBALS['as_asked'][0]['status'] ?? '', 'pending' );
$fresh();
DZE_Menu_Sync::wanted( [ 'kind' => 'post', 'id' => 900, 'type' => 'product' ] );
ok( 'a product in no menu wakes nothing',            $GLOBALS['as_booked'], [] );

echo "\nTHE SWITCH\n";
$fresh();
ok( 'on by default',                                 DZE_Menu_Sync::enabled(), true );
$GLOBALS['tr_settings'] = [ 'menus' => 0 ];
$GLOBALS['in_menus']    = [ [ 'taxonomy', 'product_cat', '5261' ] ];
ok( 'off when the shop says so',                     DZE_Menu_Sync::enabled(), false );
DZE_Menu_Sync::wanted( $cat );
ok( 'and then nothing is booked',                    $GLOBALS['as_booked'], [] );
DZE_Menu_Sync::run();
ok( 'nor run',                                       isset( $GLOBALS['opts'][ DZE_Menu_Sync::OPT_LAST ] ), false );

echo "\nONE RUN AT A TIME\n";
$fresh();
$GLOBALS['wpdb']->lock = '0';
DZE_Menu_Sync::run();
ok( 'a run held elsewhere: this one comes after',    count( $GLOBALS['as_booked'] ), 1 );
ok( 'and writes nothing',                            isset( $GLOBALS['opts'][ DZE_Menu_Sync::OPT_LAST ] ), false );
$fresh();
$rel = $GLOBALS['wpdb']->released;
DZE_Menu_Sync::run(); // no WPML here: plan() answers null.
ok( 'without WPML it says so rather than nothing',   '' !== (string) ( $GLOBALS['opts'][ DZE_Menu_Sync::OPT_LAST ]['error'] ?? '' ), true );
ok( 'and gives the lock back',                       $GLOBALS['wpdb']->released, $rel + 1 );

echo "\nWHAT THE SETTINGS SCREEN SAYS\n";
$fresh();
ok( 'before any run',                                DZE_Menu_Sync::last_said(), 'It has not run yet on this site.' );
$GLOBALS['opts'][ DZE_Menu_Sync::OPT_LAST ] = [ 'at' => time() - 120, 'done' => [ 'add' => 3, 'mov' => 2 ], 'langs' => [ 'ru', 'pl' ], 'left' => [ 'del' => 1 ], 'error' => '' ];
$said = DZE_Menu_Sync::last_said();
ok( 'says what was added, and where',                false !== strpos( $said, '3 items added' ) && false !== strpos( $said, '(RU, PL)' ), true );
ok( 'and the removal left to the shop',              false !== strpos( $said, '1 removal' ) && false !== strpos( $said, 'WP Menus Sync' ), true );

echo "\nWIRED WHERE A TRANSLATION IS WRITTEN\n";
$tr = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-translate.php' );
ok( 'accept() asks for it once something is written', false !== strpos( $tr, 'DZE_Menu_Sync::wanted( $o );' ), true );
ok( 'the run is listened for on every request',     false !== strpos( $tr, "add_action( 'dze_menu_sync', [ 'DZE_Menu_Sync', 'run' ] );" ), true );
ok( 'under the name the class books',               DZE_Menu_Sync::HOOK, 'dze_menu_sync' );
ok( 'the switch can be unticked and saved',         false !== strpos( $tr, "if ( ! empty( \$in['menus_sent'] ) ) {" ), true );
$ms = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-menu-sync.php' );
ok( 'the preview is read in the default language',  false !== strpos( $ms, '$sitepress->switch_lang( $sitepress->get_default_language() );' ), true );
ok( 'and WPML\'s own apply does the writing',        false !== strpos( $ms, "\$plan['sync']->do_sync( \$plan['data'] );" ), true );

echo "\n$ran checks, $bad wrong\n";
exit( $bad ? 1 : 0 );
