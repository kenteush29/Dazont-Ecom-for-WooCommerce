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

// RIEN EN PROMO : la preference ne coute rien, le classement passe entier.
ok( 'sans promo, le classement est intact',
	DZE_Trending::prefer_not_on_sale( $rang, [], 10 ), $rang );

// ASSEZ DE NON-REMISES : la preference est tenue, et seules elles sortent.
ok( 'avec assez de non-remises, les remises sont ecartees',
	DZE_Trending::prefer_not_on_sale( $rang, [ 1, 2, 3 ], 10 ),
	[ 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20 ] );

// LE CAS DE LA BOUTIQUE : presque tout est remise. Le bloc doit rester plein.
$presque_tout = array_slice( $rang, 0, 18 ); // 18 des 20 sont en promo
$sortie = DZE_Trending::prefer_not_on_sale( $rang, $presque_tout, 10 );
ok( 'pendant une promo generale, le bloc reste plein', count( $sortie ), 10 );
// ET LES DEUX RESCAPEES PASSENT DEVANT : la preference est tenue autant
// qu'elle peut l'etre, elle n'est pas abandonnee d'un bloc.
ok( 'les non-remises passent devant',      array_slice( $sortie, 0, 2 ), [ 19, 20 ] );
// ET LE COMPLEMENT SUIT L ORDRE DES VENTES, pas un ordre invente.
ok( 'et le complement suit le classement', array_slice( $sortie, 2 ), [ 1, 2, 3, 4, 5, 6, 7, 8 ] );

// TOUT EN PROMO : c'est le cas qui rendait une chaine vide et faisait
// disparaitre la vitrine. Le bloc montre les meilleures ventes, tout court.
$toutes = DZE_Trending::prefer_not_on_sale( $rang, $rang, 10 );
ok( 'tout en promo ne vide plus le bloc', count( $toutes ), 10 );
ok( 'et il montre bien les dix premieres', $toutes, array_slice( $rang, 0, 10 ) );

// UN CLASSEMENT PLUS COURT QUE DEMANDE rend ce qu'il a, pas des trous.
ok( 'un classement trop court rend ce qu il a',
	DZE_Trending::prefer_not_on_sale( [ 1, 2, 3 ], [ 1, 2, 3 ], 10 ), [ 1, 2, 3 ] );
ok( 'et un classement vide rend le vide',
	DZE_Trending::prefer_not_on_sale( [], [ 1 ], 10 ), [] );
// UN NOMBRE DEMANDE ABSURDE NE FABRIQUE PAS UNE LISTE VIDE.
ok( 'zero demande vaut au moins un',
	DZE_Trending::prefer_not_on_sale( [ 1, 2 ], [ 1, 2 ], 0 ), [ 1 ] );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
