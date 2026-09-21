<?php
/**
 * Netlinking — quelles pages meritent un lien venu du dehors.
 *
 * A lancer avant chaque release :  php tools/test-netlinking.php dazont-ecom
 *
 * La regle est separee de l appel reseau expres : une decision enfouie dans
 * une requete HTTP ne s eprouve pas, et c est la decision qui compte.
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
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_html_e( $s, $d = '' ) { echo $s; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); return true; }
function wp_cache_delete( ...$a ) {}
function wp_next_scheduled( $h ) { return false; }
function wp_schedule_event( ...$a ) {}
function current_user_can( $c ) { return true; }
function is_admin() { return true; }
function home_url( $p = '' ) { return 'https://kula-tactical.com' . $p; }
function admin_url( $p = '' ) { return 'https://kula-tactical.com/wp-admin/' . $p; }
function add_query_arg( $args, $url = '' ) { return $url . '?' . http_build_query( (array) $args ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
$GLOBALS['opts'] = [];
$GLOBALS['tr']   = [];

require __DIR__ . '/../' . $dir . '/includes/class-netlinking.php';

$ran = 0; $fails = 0;
function ok( string $what, $got, $want ): void {
	global $ran, $fails;
	$ran++;
	if ( $got === $want ) { echo "  ok   $what\n"; return; }
	$fails++;
	printf( "  FAIL %s\n       got  %s\n       want %s\n", $what,
		var_export( $got, true ), var_export( $want, true ) );
}
/** Une page telle que Search Console la rend. */
function page( float $pos, float $impr, float $ctr, array $terms = [] ): array {
	return [ 'url' => 'https://x/p' . $pos . '-' . $impr, 'clicks' => $impr * $ctr, 'impr' => $impr, 'ctr' => $ctr, 'pos' => $pos, 'terms' => $terms ];
}
function urls( array $rows ): array { return array_column( $rows, 'url' ); }

echo "A PORTEE, ET RIEN D AUTRE\n";
// DEJA EN HAUT : un lien n y changerait presque rien, la page y est deja. Le
// module se tairait plutot que de proposer un travail sans effet.
ok( 'la premiere place est laissee tranquille', urls( DZE_Netlinking::rank( [ page( 1.4, 5000, 0.30 ) ] ) ), [] );
ok( 'la troisieme aussi',                       urls( DZE_Netlinking::rank( [ page( 3.2, 5000, 0.11 ) ] ) ), [] );
// TROP LOIN : un lien seul ne remontera pas une page de la soixantieme place,
// et le promettre fait perdre un mois.
ok( 'au-dela de la trentieme, on ne promet rien', urls( DZE_Netlinking::rank( [ page( 42.0, 5000, 0.001 ) ] ) ), [] );
// A PORTEE : entre les deux, c est exactement la page qu un lien fait basculer.
ok( 'entre les deux, la page est retenue', count( DZE_Netlinking::rank( [ page( 14.0, 4000, 0.004 ) ] ) ), 1 );

echo "\nUN GAIN CALCULE SUR TROIS IMPRESSIONS EST UN CHIFFRE, PAS UNE INFORMATION\n";
ok( 'une page que personne ne voit est ecartee', urls( DZE_Netlinking::rank( [ page( 12.0, 8, 0.0 ) ] ) ), [] );
ok( 'et le plancher est bien a vingt',           count( DZE_Netlinking::rank( [ page( 12.0, 20, 0.0 ) ] ) ), 1 );
// DEJA AU PLAFOND DE SA PLACE : une page qui clique mieux que ce qu on lui
// promettrait n a rien a gagner, et on ne lui invente pas un gain negatif.
ok( 'un taux de clic deja meilleur ne gagne rien',
	urls( DZE_Netlinking::rank( [ page( 12.0, 4000, 0.40 ) ] ) ), [] );

echo "\nL ORDRE EST CELUI DU GAIN, PAS CELUI DE LA POSITION\n";
// La page la mieux placee n est pas celle qui rapporte le plus : c est le
// VOLUME qui decide. Une page en 8e avec 200 impressions pese moins qu une
// page en 25e avec 9 000.
$gros  = page( 25.0, 9000, 0.001 );
$petit = page( 8.0, 200, 0.004 );
$rank  = DZE_Netlinking::rank( [ $petit, $gros ] );
ok( 'le volume passe devant la place',   $rank[0]['url'], $gros['url'] );
ok( 'et les deux sont la',               count( $rank ), 2 );

echo "\nLES ANCRES SONT LES REQUETES, DANS L ORDRE DE CE QU ELLES PESENT\n";
$p = page( 14.0, 4000, 0.004, [
	[ 'q' => 'petite', 'impr' => 10.0, 'pos' => 14.0 ],
	[ 'q' => 'grosse', 'impr' => 900.0, 'pos' => 12.0 ],
	[ 'q' => 'moyenne', 'impr' => 300.0, 'pos' => 13.0 ],
] );
$one = DZE_Netlinking::rank( [ $p ] )[0];
ok( 'la requete la plus vue vient en tete', array_column( $one['terms'], 'q' ), [ 'grosse', 'moyenne', 'petite' ] );
// SIX SUFFISENT : au-dela, ce n est plus une liste d ancres, c est un export.
$many = [];
for ( $i = 1; $i <= 20; $i++ ) { $many[] = [ 'q' => 'q' . $i, 'impr' => (float) ( 100 - $i ), 'pos' => 14.0 ]; }
$cut = DZE_Netlinking::rank( [ page( 14.0, 4000, 0.004, $many ) ] )[0];
ok( 'et on en garde six',                   count( $cut['terms'] ), 6 );

echo "\nCE MODULE EST PASSIF : IL LIT, IL N ECRIT RIEN\n";
// « Ce module netlinking doit être passif. » La portee demandee a Google est
// en LECTURE SEULE : meme si le code se trompait, Google refuserait d ecrire.
$src = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-netlinking.php' );
ok( 'la portee Google est en lecture seule',
	false !== strpos( $src, 'auth/webmasters.readonly' ), true );
ok( 'et jamais la portee qui ecrit',
	false !== strpos( $src, "auth/webmasters'" ), false );
// Aucun appel au modele : ce module ne coute rien.
ok( 'aucun appel au modele',
	false !== strpos( $src, 'DZE_Marketing_Ai::complete' ), false );
// Et il ne touche a rien de la boutique.
foreach ( [ 'wp_insert_post', 'wp_update_post', 'update_post_meta', 'wp_insert_term', 'wp_update_term' ] as $write ) {
	ok( "il n appelle pas $write", false !== strpos( $src, $write . '(' ), false );
}

echo "\nET IL NE PRETEND PAS CONNAITRE LES BACKLINKS\n";
// L API Search Console n en expose aucun : le rapport Liens vit a l ecran et
// nulle part ailleurs. Un module qui les listerait mentirait, donc l ecran le
// dit lui-meme plutot que de laisser croire.
ok( 'l ecran annonce ce que Google ne donne pas',
	false !== strpos( $src, 'What Search Console does not give' ), true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
