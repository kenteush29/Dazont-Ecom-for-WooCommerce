<?php
/**
 * Une seule adresse de retour OAuth pour tout le plugin.
 *
 * A lancer avant chaque release :  php tools/test-oauth.php dazont-ecom
 *
 * « Pourquoi ne pas utiliser un seul url de redirection standardisé pour tout
 * le plugin ? » Il n y avait aucune bonne raison. Ce qui compte ici, c est que
 * l aiguillage rende la main au BON module, et a aucun quand il ne sait pas.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'DZE_VERSION', 'test' );
define( 'DZE_DIR', __DIR__ . '/../' . $dir . '/' );

function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return (string) $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function add_action( ...$a ) { $GLOBALS['hooks'][] = (string) $a[0]; }
function admin_url( $p = '' ) { return 'https://shop.test/wp-admin/' . $p; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function wp_create_nonce( $a ) { return 'nonce-' . $a; }
function current_user_can( $c ) { return ! empty( $GLOBALS['can'] ); }
class DZE_Died extends Exception {}
function wp_die( $m = '' ) { throw new DZE_Died( (string) $m ); }

/** Les deux modules, reduits a « on m a appele ». */
class DZE_Gmc {
	public static function instance() { return new self(); }
	public function handle_oauth_callback() { $GLOBALS['called'] = 'gmc:' . ( $_GET['state'] ?? '' ); }
}
class DZE_Netlinking {
	public static function instance() { return new self(); }
	public function handle_oauth_callback() { $GLOBALS['called'] = 'nl:' . ( $_GET['state'] ?? '' ); }
}
$GLOBALS['hooks'] = [];
$GLOBALS['can']   = true;

require __DIR__ . '/../' . $dir . '/includes/class-oauth.php';

$ran = 0; $fails = 0;
function ok( string $what, $got, $want ): void {
	global $ran, $fails;
	$ran++;
	if ( $got === $want ) { echo "  ok   $what\n"; return; }
	$fails++;
	printf( "  FAIL %s\n       got  %s\n       want %s\n", $what,
		var_export( $got, true ), var_export( $want, true ) );
}

echo "UNE ADRESSE, ET UNE SEULE\n";
ok( 'l adresse ne nomme aucun module',
	DZE_Oauth::redirect_uri(), 'https://shop.test/wp-admin/admin-post.php?action=dze_oauth' );
// ELLE NE BOUGE PAS QUAND UN MODULE S AJOUTE : c est tout l interet. Une
// adresse qui change est une ligne a recoller dans la console Google.
ok( 'et elle ne contient le nom d aucun module',
	false !== strpos( DZE_Oauth::redirect_uri(), 'gmc' ) || false !== strpos( DZE_Oauth::redirect_uri(), '_nl_' ), false );

echo "\nLE STATE PORTE QUI DEMANDE, ET SON JETON\n";
// OAuth a prevu ce parametre pour ca : il fait l aller-retour sans etre
// touche. Le jeton reste celui du module, avec son propre nom d action, pour
// que la verification a l arrivee soit celle qui existait avant.
ok( 'le demandeur puis le jeton', DZE_Oauth::state( 'nl', 'dze_nl_oauth' ), 'nl:nonce-dze_nl_oauth' );
ok( 'et chaque module garde le sien', DZE_Oauth::state( 'gmc', 'dze_gmc_oauth' ), 'gmc:nonce-dze_gmc_oauth' );

echo "\nL AIGUILLAGE REND LA MAIN AU BON MODULE\n";
$_GET = [ 'state' => 'nl:nonce-dze_nl_oauth', 'code' => 'abc' ];
$GLOBALS['called'] = '';
DZE_Oauth::route();
ok( 'le maillage externe est appele', $GLOBALS['called'], 'nl:nonce-dze_nl_oauth' );
// ET LE JETON LUI EST RENDU NU : le module verifie exactement ce qu il
// verifiait avant que cette adresse commune existe.
ok( 'et le state lui revient sans le prefixe',
	false !== strpos( (string) $GLOBALS['called'], 'nl:' ) && false === strpos( (string) $_GET['state'], 'nl:' ), true );

$_GET = [ 'state' => 'gmc:nonce-dze_gmc_oauth', 'code' => 'abc' ];
$GLOBALS['called'] = '';
DZE_Oauth::route();
ok( 'le merchant center aussi', $GLOBALS['called'], 'gmc:nonce-dze_gmc_oauth' );

echo "\nCE QU ON NE SAIT PAS ATTRIBUER N EST DONNE A PERSONNE\n";
// Appeler un module au hasard reviendrait a lui faire traiter le consentement
// d un autre — donc un jeton d un compte Google range sous le mauvais nom.
foreach ( [ 'pirate:xxx', 'nonce-sans-prefixe', '' ] as $bad ) {
	$_GET = [ 'state' => $bad, 'code' => 'abc' ];
	$GLOBALS['called'] = '';
	$died = false;
	try { DZE_Oauth::route(); } catch ( DZE_Died $e ) { $died = true; }
	ok( "un state « $bad » n appelle personne", $GLOBALS['called'] . ( $died ? '|refuse' : '|passe' ), '|refuse' );
}

echo "\nET IL FAUT LE DROIT D ETRE LA\n";
$GLOBALS['can'] = false;
$_GET = [ 'state' => 'nl:nonce-dze_nl_oauth' ];
$GLOBALS['called'] = '';
$died = false;
try { DZE_Oauth::route(); } catch ( DZE_Died $e ) { $died = true; }
ok( 'sans droit, personne n est appele', $GLOBALS['called'] . ( $died ? '|refuse' : '|passe' ), '|refuse' );
$GLOBALS['can'] = true;

echo "\nLES DEUX MODULES PASSENT BIEN PAR ELLE\n";
foreach ( [ 'class-gmc.php', 'class-netlinking.php' ] as $file ) {
	$src = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/' . $file );
	ok( "$file demande l adresse commune",
		false !== strpos( $src, 'DZE_Oauth::redirect_uri()' ), true );
	ok( "$file annonce qui il est",
		false !== strpos( $src, 'DZE_Oauth::state(' ), true );
	// L ANCIENNE ADRESSE RESTE BRANCHEE : une autorisation partie avant la mise
	// a jour revient sur l ancienne, et doit atterrir quand meme.
	ok( "$file garde son ancienne porte",
		false !== strpos( $src, 'admin_post_dze_' ), true );
}

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
