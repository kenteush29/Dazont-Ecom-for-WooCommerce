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
define( 'ARRAY_A', 'ARRAY_A' );

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
function apply_filters( $h, $v, ...$a ) { return 'wpml_active_languages' === $h ? ( $GLOBALS['langs'] ?? [] ) : $v; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
$GLOBALS['opts']  = [];
$GLOBALS['langs'] = [];
$GLOBALS['tr']   = [];

/**
 * WPML, reduit a ce que ce fichier interroge : quel groupe de traduction
 * porte quel terme, et dans quelle langue chaque terme est ecrit.
 */
class DZE_Wpml {
	public static function is_active() { return ! empty( $GLOBALS['wpml_terms'] ); }
	public static function has_table( $t ) { return true; }
}
class DZE_Category_Content {
	public static function default_lang() { return 'en'; }
	public static function lang_code( $tid ) { return $GLOBALS['wpml_lang'][ (int) $tid ] ?? ''; }
}
class dze_fake_wpdb {
	public $prefix = 'wp_';
	public $terms = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public function get_results( $q, $mode = null ) {
		// La seule requete que ces tests exercent : terme -> groupe de traduction.
		$out = [];
		foreach ( (array) ( $GLOBALS['wpml_terms'] ?? [] ) as $tid => $trid ) {
			$out[] = [ 'tid' => (string) $tid, 'trid' => (string) $trid ];
		}
		return $out;
	}
	public function get_var( $q ) { return null; }
	public function prepare( $q, ...$a ) { return $q; }
}
$GLOBALS['wpdb'] = new dze_fake_wpdb();
$GLOBALS['wpml_terms'] = [];
$GLOBALS['wpml_lang']  = [];

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

echo "\nLES VENTES DECIDENT, PAS LE TRAFIC\n";
// « La data GSC doit être recroisée avec les ventes au niveau des catégories
// produits. » Sans cela, une categorie a 4 000 impressions qui ne vend rien
// passait devant une a 800 qui vend : du trafic pour du trafic.
// La carte que slug_map() fabrique vraiment : une clef par langue, PLUS une
// clef nue qui sert de filet quand le domaine ne dit rien.
$map = [ 'en|bottes' => 11, 'en|casques' => 22, 'fr|bottes' => 33, '|bottes' => 11, '|casques' => 22 ];
// La page qui vend peu de clics mais beaucoup d argent doit passer devant.
$vend = [ 'url' => 'https://kula-tactical.com/bottes', 'clicks' => 10.0, 'impr' => 900.0, 'ctr' => 0.011, 'pos' => 12.0, 'terms' => [] ];
$creux = [ 'url' => 'https://kula-tactical.com/casques', 'clicks' => 10.0, 'impr' => 4000.0, 'ctr' => 0.0025, 'pos' => 12.0, 'terms' => [] ];
$sales = [ 11 => [ 'units' => 400 ], 22 => [ 'units' => 0 ] ];
$r = DZE_Netlinking::rank( [ $creux, $vend ], $sales, $map );
ok( 'la categorie qui vend passe devant', $r[0]['url'], $vend['url'] );
ok( 'et celle qui ne vend rien suit',     $r[1]['url'], $creux['url'] );
// SANS VENTES DU TOUT, on retombe sur les clics : un article n est pas jete.
$r2 = DZE_Netlinking::rank( [ $vend, $creux ], [], $map );
ok( 'sans ventes, le trafic decide',      $r2[0]['url'], $creux['url'] );

echo "\nUNE PAGE QUI N EST PAS UNE CATEGORIE LE DIT\n";
// Un article n a pas vendu zero : il ne vend pas. Le tableau met un tiret, et
// ca commence ici — le term_id vaut 0, et rien ne le confond avec une vente nulle.
$blog = [ 'url' => 'https://kula-tactical.com/blog/comment-choisir', 'clicks' => 10.0, 'impr' => 900.0, 'ctr' => 0.011, 'pos' => 12.0, 'terms' => [] ];
$one  = DZE_Netlinking::rank( [ $blog ], $sales, $map )[0];
ok( 'un article ne porte aucune categorie', (int) $one['tid'], 0 );
ok( 'et aucune vente inventee',             (float) $one['worth'], 0.0 );

echo "\nC EST WPML QUI DICTE, PAS NOUS\n";
// « C'est WPML et ses réglages qui doivent dicter la façon de fonctionner. »
// Trois manieres de separer les langues, et elles ne se lisent pas pareil.
// Rien n est devine : le reglage est lu, et tout en decoule.

// MODE 2 — UN DOMAINE PAR LANGUE. « Un multidomaine = une search console par
// domaine. »
$GLOBALS['opts']['icl_sitepress_settings'] = [
	'language_negotiation_type' => 2,
	'default_language'          => 'en',
	'language_domains'          => [ 'fr' => 'kula-tactical.fr' ],
];
ok( 'le mode est lu chez WPML',              DZE_Netlinking::negotiation(), 2 );
ok( 'le domaine francais donne le francais', DZE_Netlinking::lang_of_url( 'https://kula-tactical.fr/bottes' ), 'fr' );
ok( 'et le principal la langue par defaut',  DZE_Netlinking::lang_of_url( 'https://kula-tactical.com/bottes' ), 'en' );
ok( 'chaque domaine est une propriete',      count( DZE_Netlinking::domains() ), 2 );
ok( 'le slug francais rend le terme francais',
	DZE_Netlinking::term_of_url( 'https://kula-tactical.fr/bottes', $map ), 33 );
ok( 'et le slug anglais le terme anglais',
	DZE_Netlinking::term_of_url( 'https://kula-tactical.com/bottes', $map ), 11 );
// UN DOMAINE INCONNU N EST PAS UNE IMPASSE : le slug seul vaut mieux que rien.
ok( 'un domaine inconnu retombe sur le slug',
	DZE_Netlinking::term_of_url( 'https://ailleurs.test/casques', $map ), 22 );
ok( 'et une racine ne designe aucune categorie',
	DZE_Netlinking::term_of_url( 'https://kula-tactical.com/', $map ), 0 );

// MODE 1 — UN REPERTOIRE PAR LANGUE. Une seule propriete les contient toutes :
// en chercher cinq n aurait aucun sens.
$GLOBALS['opts']['icl_sitepress_settings'] = [ 'language_negotiation_type' => 1, 'default_language' => 'en' ];
$GLOBALS['langs'] = [ 'en' => [], 'fr' => [] ];
ok( 'un repertoire par langue, une seule propriete', count( DZE_Netlinking::domains() ), 1 );
ok( 'et le repertoire donne la langue',      DZE_Netlinking::lang_of_url( 'https://kula-tactical.com/fr/bottes' ), 'fr' );
ok( 'la racine reste la langue par defaut',  DZE_Netlinking::lang_of_url( 'https://kula-tactical.com/' ), 'en' );
// UN PREMIER MORCEAU QUI RESSEMBLE A UNE LANGUE SANS EN ETRE UNE reste une
// categorie : « /es/ » est l espagnol, « /escalade/ » ne l est pas.
ok( 'une categorie n est pas prise pour une langue',
	DZE_Netlinking::lang_of_url( 'https://kula-tactical.com/escalade/bottes' ), 'en' );
ok( 'et le slug reste le dernier morceau',
	DZE_Netlinking::term_of_url( 'https://kula-tactical.com/fr/bottes', $map ), 33 );

// MODE 3 — UN PARAMETRE.
$GLOBALS['opts']['icl_sitepress_settings'] = [ 'language_negotiation_type' => 3, 'default_language' => 'en' ];
ok( 'le parametre donne la langue',          DZE_Netlinking::lang_of_url( 'https://kula-tactical.com/bottes?lang=fr' ), 'fr' );
ok( 'sans parametre, la langue par defaut',  DZE_Netlinking::lang_of_url( 'https://kula-tactical.com/bottes' ), 'en' );
$GLOBALS['opts']['icl_sitepress_settings'] = [ 'language_negotiation_type' => 2, 'default_language' => 'en', 'language_domains' => [ 'fr' => 'kula-tactical.fr' ] ];

echo "\nCHAQUE LANGUE COMPTE SES PROPRES VENTES\n";
// « Les ventes sont comptabilisées seulement sur la langue concernée. » Une
// version precedente reportait les ventes sur tout le groupe de traduction :
// c etait decider a la place de la boutique. Un zero sur la page allemande EST
// l information — ce catalogue ne vend pas encore.
ok( 'le report entre langues a disparu',
	method_exists( 'DZE_Netlinking', 'spread_across_languages' ), false );
$vendu = [ 11 => [ 'units' => 400 ] ];
$fr    = [ 'url' => 'https://kula-tactical.fr/bottes', 'clicks' => 10.0, 'impr' => 900.0, 'ctr' => 0.011, 'pos' => 12.0, 'terms' => [] ];
$one2  = DZE_Netlinking::rank( [ $fr ], $vendu, $map )[0];
ok( 'la page francaise est bien reconnue',  (int) $one2['tid'], 33 );
ok( 'et ne recupere pas les ventes anglaises', (int) $one2['units'], 0 );

// ET JAMAIS EN ARGENT : la table de WooCommerce garde chaque commande dans sa
// devise, et cette boutique en encaisse huit. La premiere mesure a rendu
// 677 120 pour trente unites — un melange de dollars et de livres turques.
$src2 = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-netlinking.php' );
ok( 'la recette n est jamais additionnee', false !== strpos( $src2, 'product_net_revenue' ), false );
ok( 'et le tableau ne montre aucune devise', false !== strpos( $src2, 'get_woocommerce_currency_symbol' ), false );

echo "\nUNE CONSIGNE SANS ADRESSE EST UNE CONSIGNE QU ON NE PEUT PAS SUIVRE\n";
// « Il manque des explications. Url là ou il faut aller ? » L encart disait
// d ajouter une adresse a l application Google sans dire ou cette
// application se trouve. Et il manquait une etape entiere : sans l API
// activee, la connexion reussit et la premiere lecture est refusee.
$src3 = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-netlinking.php' );
ok( 'la page des identifiants est donnee',
	false !== strpos( $src3, 'console.cloud.google.com/apis/credentials' ), true );
ok( 'celle de l ecran de consentement aussi',
	false !== strpos( $src3, 'apis/credentials/consent' ), true );
ok( 'et l activation de l API Search Console',
	false !== strpos( $src3, 'apis/library/searchconsole.googleapis.com' ), true );

echo "\nUN REFUS DE GOOGLE SE LIT, ET PORTE SON REMEDE\n";
// « Google Search Console API has not been used in project 549418223064
// before or it is disabled. » Le message est juste et illisible : il arrive
// APRES une connexion reussie — tout a l air fait, rien ne marche — et il
// faut y pecher un numero de projet pour fabriquer soi-meme l adresse.
$brut = 'Google Search Console API has not been used in project 549418223064 before or it is disabled. Enable it by visiting https://x then retry.';
$dit  = DZE_Netlinking::said( $brut );
ok( 'l API eteinte est reconnue',      false !== strpos( $dit, 'not switched on' ), true );
ok( 'et le lien vise LE bon projet',  false !== strpos( $dit, 'project=549418223064' ), true );
// UN REFUS DE DROITS N EST PAS LA MEME CHOSE, et n appelle pas le meme geste.
$droits = DZE_Netlinking::said( 'Request had insufficient authentication scopes.' );
ok( 'un refus de droits parle de droits', false !== strpos( $droits, 'Users and permissions' ), true );
// ET CE QU ON NE RECONNAIT PAS EST RENDU TEL QUEL : deformer un message
// qu on ne comprend pas empeche de chercher ce qu il veut dire.
ok( 'un message inconnu passe intact',   DZE_Netlinking::said( 'Backend error 503' ), 'Backend error 503' );

echo "\nUN BOUTON ET SON ECOUTEUR NE SE SEPARENT PAS\n";
// « Il ne se passe rien. » Le script vivait a la FIN de la liste, apres le
// retour anticipe qui sert quand il n y a rien a montrer. Liste vide : le
// bouton dessine, plus personne pour l ecouter, un clic sans effet et sans
// message — donc on accuse Google.
$src4 = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-netlinking.php' );
$pos_script = strpos( $src4, 'self::render_script();' );
$pos_vide   = strpos( $src4, 'if ( ! $rows ) {' );
ok( 'le script est branche avant le retour anticipe',
	$pos_script !== false && $pos_vide !== false && $pos_script < $pos_vide, true );
// ET IL NE RESTE AUCUN <script> APRES CE RETOUR : c est le motif exact qui a
// casse, et il se reintroduit sans bruit.
$apres = $pos_vide !== false ? substr( $src4, $pos_vide ) : '';
ok( 'et plus aucun script ne vit apres lui', false !== strpos( $apres, '<script>' ), false );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
