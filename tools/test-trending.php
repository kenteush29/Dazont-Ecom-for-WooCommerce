<?php
/**
 * Le bloc des meilleures ventes, et ce qu'il devient pendant les soldes.
 *
 * A lancer avant chaque release :  php tools/test-trending.php dazont-ecom
 *
 * « Trending Gear vide. Des soldes ont commencé avec le module dazont ecom
 * marketing. Et voilà, le filtre du shortcode filtre tout. » Sur cette
 * boutique, 2 055 produits sur 9 518 étaient remisés — et 46 des 48
 * meilleures ventes des trente derniers jours. Un bloc appelé avec
 * limit="10" exclude_on_sale="true" en affichait deux.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'DZE_VERSION', 'test' );
define( 'DZE_DIR', __DIR__ . '/../' . $dir . '/' );
define( 'DZE_FILE', __FILE__ );

function __( $s, $d = '' ) { return $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return esc_attr( $s ); }
function absint( $v ) { return abs( (int) $v ); }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_shortcode( ...$a ) {}
function do_shortcode( $s ) { return (string) $s; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function wp_next_scheduled( $h ) { return false; }
function current_user_can( $c ) { return true; }
function is_admin() { return true; }
$GLOBALS['opts'] = [];

require __DIR__ . '/../' . $dir . '/includes/class-trending.php';

$ran = 0; $fails = 0;
function ok( string $what, $got, $want ): void {
	global $ran, $fails;
	$ran++;
	if ( $got === $want ) { echo "  ok   $what\n"; return; }
	$fails++;
	printf( "  FAIL %s\n       got  %s\n       want %s\n", $what,
		var_export( $got, true ), var_export( $want, true ) );
}

echo "UNE PREFERENCE, PAS UN COUPERET\n";
// Le classement des ventes, et ce qui est remise dedans.
$rang = range( 1, 20 );

// RIEN EN PROMO : la preference ne coute rien, le classement passe entier et
// dans le meme ordre.
ok( 'sans promo, le classement est intact',
	DZE_Trending::prefer_not_on_sale( $rang, [] ), $rang );

// AVEC DES REMISES : elles passent derriere, elles ne disparaissent pas.
ok( 'les remises passent derriere, sans etre jetees',
	DZE_Trending::prefer_not_on_sale( $rang, [ 1, 2, 3 ] ),
	array_merge( range( 4, 20 ), [ 1, 2, 3 ] ) );

// LE CAS DE LA BOUTIQUE : presque tout est remise, deux rescapees seulement.
$sortie = DZE_Trending::prefer_not_on_sale( $rang, array_slice( $rang, 0, 18 ) );
ok( 'les deux rescapees passent devant', array_slice( $sortie, 0, 2 ), [ 19, 20 ] );
ok( 'et le reste suit le classement',    array_slice( $sortie, 2 ), range( 1, 18 ) );

// LE POINT QUI A FAIT MANQUER DES PRODUITS : on REORDONNE, on ne coupe pas.
//
// « Il manque des produits maintenant. » La premiere version de ce correctif
// rendait exactement les dix demandes — et le bloc en affichait huit.
// [products] ecarte au rendu ce qui est en rupture ou hors catalogue, et ce
// module sur-tire expres pour absorber ca : « demander autant qu on veut en
// montrer et en jeter ensuite, c est comme ca qu un bloc de douze revient
// avec cinq ». Couper la liste supprimait ce coussin.
ok( 'rien n est jete : autant d identifiants en sortie qu en entree',
	count( DZE_Trending::prefer_not_on_sale( $rang, array_slice( $rang, 0, 18 ) ) ), count( $rang ) );
ok( 'et ce sont exactement les memes',
	array_diff( $rang, DZE_Trending::prefer_not_on_sale( $rang, [ 5, 6 ] ) ), [] );

// TOUT EN PROMO : le classement sort tel quel, et le bloc vit.
ok( 'tout en promo rend le classement entier',
	DZE_Trending::prefer_not_on_sale( $rang, $rang ), $rang );

// ET LES BORDS.
ok( 'un classement vide rend le vide',
	DZE_Trending::prefer_not_on_sale( [], [ 1 ] ), [] );
ok( 'un classement plus court rend ce qu il a',
	DZE_Trending::prefer_not_on_sale( [ 1, 2, 3 ], [ 1, 2, 3 ] ), [ 1, 2, 3 ] );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
