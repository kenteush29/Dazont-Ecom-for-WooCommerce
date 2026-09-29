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
	public static function language_codes() { return array_map( 'strval', array_keys( (array) ( $GLOBALS['langs'] ?? [] ) ) ); }
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

// LA BOUTIQUE ET SON NOM : domains() lit l adresse enregistree, et le nom
// sert a reconnaitre les requetes de marque.
$GLOBALS['opts']['home']     = 'https://kula-tactical.com';
$GLOBALS['opts']['blogname'] = 'Kula Tactical';

/** Une page de categorie telle que Search Console la rend. */
function cat( string $slug, float $pos, float $impr, float $ctr, array $terms = [], string $host = 'kula-tactical.com' ): array {
	return [ 'url' => 'https://' . $host . '/' . $slug, 'clicks' => $impr * $ctr, 'impr' => $impr, 'ctr' => $ctr, 'pos' => $pos, 'terms' => $terms ];
}
/** La carte des slugs, telle que slug_map() la fabrique : une clef par langue, plus la clef nue. */
function smap( array $slugs ): array {
	$out = [];
	foreach ( $slugs as $slug => $tid ) {
		$out[ 'en|' . $slug ] = $tid;
		$out[ '|' . $slug ]   = $tid;
	}
	return $out;
}
/** Le statut d une seule categorie, lue seule. */
function status_of( array $page, array $sales = [], array $meta = [] ): string {
	$slug = trim( (string) parse_url( $page['url'], PHP_URL_PATH ), '/' );
	$rows = DZE_Netlinking::rank( [ $page ], $sales, smap( [ $slug => 7 ] ), $meta );
	return (string) ( $rows[0]['status'] ?? 'none' );
}

echo "LES CATEGORIES, ET RIEN D AUTRE\n";
// « Tu as intégré des recommandations de liens au niveau produit, ce qui est
// faux. […] Data Google mise en relation seulement niveau catégories. »
$m1   = smap( [ 'bottes' => 11 ] );
$fiche = cat( 'botte-cuir-noire', 14.0, 4000, 0.004 ); // une fiche produit : aucun slug de categorie
$blog  = cat( 'blog/comment-choisir', 14.0, 4000, 0.004 );
$vrai  = cat( 'bottes', 14.0, 4000, 0.004 );
$r0    = DZE_Netlinking::rank( [ $fiche, $blog, $vrai ], [], $m1 );
ok( 'une fiche produit n est plus jamais une cible', count( $r0 ), 1 );
ok( 'seule la categorie reste',                     (int) $r0[0]['tid'], 11 );
ok( 'et le code des produits est parti',
	[ method_exists( 'DZE_Netlinking', 'sales_by_product' ), method_exists( 'DZE_Netlinking', 'product_of_url' ), method_exists( 'DZE_Netlinking', 'warm_products' ) ],
	[ false, false, false ] );
ok( 'comme le quota par langue, inutile avec un filtre',
	method_exists( 'DZE_Netlinking', 'share_out' ), false );

echo "\nUNE LIGNE PAR CATEGORIE\n";
// Deux adresses de la meme categorie — avec et sans barre finale — font une
// seule ligne : les impressions s additionnent, la position se pondere.
$a = cat( 'bottes', 10.0, 3000, 0.01 );
$b = cat( 'bottes/', 20.0, 1000, 0.002 );
$b['url'] = 'https://kula-tactical.com/bottes/';
$one = DZE_Netlinking::rank( [ $a, $b ], [], $m1 );
ok( 'une seule ligne',                       count( $one ), 1 );
ok( 'les impressions s additionnent',        (int) $one[0]['impr'], 4000 );
ok( 'la position est ponderee',              round( $one[0]['pos'], 2 ), 12.5 );
ok( 'l adresse gardee est la plus vue',      $one[0]['url'], 'https://kula-tactical.com/bottes' );

echo "\nA PORTEE, ET RIEN D AUTRE\n";
// DEJA EN HAUT : un lien n y changerait presque rien.
ok( 'la premiere place est deja forte',      status_of( cat( 'x', 1.4, 5000, 0.30 ) ), 'strong' );
ok( 'la troisieme aussi',                    status_of( cat( 'x', 3.2, 5000, 0.11 ) ), 'strong' );
// TROP LOIN : un lien seul ne remontera pas une page de la soixantieme place.
ok( 'au-dela de la trentieme, trop loin',    status_of( cat( 'x', 42.0, 5000, 0.001 ) ), 'far' );
ok( 'entre les deux, elle merite un lien',   status_of( cat( 'x', 14.0, 4000, 0.004 ) ), 'reach' );
// UN GAIN CALCULE SUR TROIS IMPRESSIONS EST UN CHIFFRE, PAS UNE INFORMATION.
ok( 'trop peu vue pour en juger',            status_of( cat( 'x', 12.0, 8, 0.0 ) ), 'far' );
ok( 'et le plancher est bien a vingt',       status_of( cat( 'x', 12.0, 20, 0.0 ) ), 'reach' );
// DEJA AU TAUX DE LA CINQUIEME PLACE : rien a gagner, pas de gain negatif.
ok( 'un taux de clic deja meilleur est fort', status_of( cat( 'x', 12.0, 4000, 0.40 ) ), 'strong' );
ok( 'et ne porte aucun gain',
	(float) DZE_Netlinking::rank( [ cat( 'x', 12.0, 4000, 0.40 ) ], [], smap( [ 'x' => 7 ] ) )[0]['gain'], 0.0 );

echo "\nCE QUI VEND SANS ETRE VU A SA LIGNE\n";
// Une categorie que Google n a montree a personne mais qui vend : c est une
// information, et souvent la plus utile — elle a sa ligne, sans chiffre Google.
$meta = [ 11 => [ 'name' => 'Bottes', 'lang' => 'en' ], 22 => [ 'name' => 'Casques', 'lang' => 'en' ] ];
$r1   = DZE_Netlinking::rank( [ $vrai ], [ 22 => [ 'units' => 9, 'revenue' => 400.0 ] ], $m1, $meta );
$uns  = array_values( array_filter( $r1, static fn( $r ) => 22 === (int) $r['tid'] ) );
ok( 'elle est la',                           count( $uns ), 1 );
ok( 'marquee comme jamais vue',              $uns[0]['status'] ?? '', 'unseen' );
ok( 'avec ses ventes',                       (int) ( $uns[0]['units'] ?? 0 ), 9 );
ok( 'et sa langue, lue sur la categorie',    $uns[0]['lang'] ?? '', 'en' );
ok( 'une categorie qui ne vend rien et qu on ne voit pas n encombre pas',
	count( DZE_Netlinking::rank( [], [ 22 => [ 'units' => 0 ] ], $m1, $meta ) ), 0 );

echo "\nJAMAIS UNE CIBLE : NOINDEX, VIDE, PAR DEFAUT\n";
foreach ( [ 'noindex', 'empty', 'default' ] as $why ) {
	$rr = DZE_Netlinking::rank( [ $vrai ], [ 11 => [ 'units' => 50 ] ], $m1, [ 11 => [ 'name' => 'Bottes', $why => true ] ] )[0];
	ok( "$why : laissee de cote",              [ $rr['status'], $rr['skip'] ], [ 'skip', $why ] );
	ok( "$why : sans priorite ni gain",         [ (float) $rr['worth'], (float) $rr['gain'] ], [ 0.0, 0.0 ] );
}

echo "\nUN TITRE AVANT UN LIEN\n";
// Bien placee et peu cliquee : un probleme d extrait, qu aucun lien ne regle.
$titre = DZE_Netlinking::rank( [ cat( 'x', 5.0, 3000, 0.01 ) ], [], smap( [ 'x' => 7 ] ) )[0];
ok( 'le taux de clic trop bas pour sa place est signale', $titre['ctr_low'], true );
$bien  = DZE_Netlinking::rank( [ cat( 'x', 5.0, 3000, 0.05 ) ], [], smap( [ 'x' => 7 ] ) )[0];
ok( 'pas quand il est normal',               $bien['ctr_low'], false );
$loin  = DZE_Netlinking::rank( [ cat( 'x', 18.0, 3000, 0.001 ) ], [], smap( [ 'x' => 7 ] ) )[0];
ok( 'ni en deuxieme page, ou c est la place qui manque', $loin['ctr_low'], false );

echo "\nLES LIENS INTERNES D ABORD\n";
$in = DZE_Netlinking::rank( [ $vrai ], [], $m1, [ 11 => [ 'name' => 'Bottes', 'in' => 1 ] ] )[0];
ok( 'le maillage interne est repris',        $in['in'], 1 );
$nil = DZE_Netlinking::rank( [ $vrai ], [], $m1, [ 11 => [ 'name' => 'Bottes' ] ] )[0];
ok( 'et rien n est invente quand il ne sait pas', $nil['in'], null );

echo "\nL ORDRE EST CELUI DU GAIN, PAS CELUI DE LA POSITION\n";
// La mieux placee n est pas celle qui rapporte le plus : c est le VOLUME.
$gros  = cat( 'gros', 25.0, 9000, 0.001 );
$petit = cat( 'petit', 8.0, 200, 0.004 );
$rank  = DZE_Netlinking::rank( [ $petit, $gros ], [], smap( [ 'gros' => 1, 'petit' => 2 ] ) );
ok( 'le volume passe devant la place',       $rank[0]['url'], $gros['url'] );
ok( 'et les deux sont la',                   count( $rank ), 2 );

echo "\nLES ANCRES SONT LES REQUETES, SANS LA MARQUE\n";
$p = cat( 'bottes', 14.0, 4000, 0.004, [
	[ 'q' => 'petite', 'impr' => 10.0, 'pos' => 14.0 ],
	[ 'q' => 'grosse', 'impr' => 900.0, 'pos' => 12.0 ],
	[ 'q' => 'kula tactical bottes', 'impr' => 2000.0, 'pos' => 2.0 ],
	[ 'q' => 'moyenne', 'impr' => 300.0, 'pos' => 13.0 ],
] );
$one = DZE_Netlinking::rank( [ $p ], [], $m1 )[0];
ok( 'la plus vue en tete, la marque dehors', array_column( $one['terms'], 'q' ), [ 'grosse', 'moyenne', 'petite' ] );
ok( 'la marque se reconnait meme ecrite autrement', DZE_Netlinking::is_brand( 'KULA-TACTICAL gorka', DZE_Netlinking::brand_marks() ), true );
ok( 'et un mot commun n est pas la marque',  DZE_Netlinking::is_brand( 'tactical boots', DZE_Netlinking::brand_marks() ), false );
$many = [];
for ( $i = 1; $i <= 20; $i++ ) { $many[] = [ 'q' => 'q' . $i, 'impr' => (float) ( 100 - $i ), 'pos' => 14.0 ]; }
$cut = DZE_Netlinking::rank( [ cat( 'bottes', 14.0, 4000, 0.004, $many ) ], [], $m1 )[0];
ok( 'et on en garde cinq',                   count( $cut['terms'] ), 5 );

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
// passait devant une a 900 qui vend : du trafic pour du trafic.
$map = [ 'en|bottes' => 11, 'en|casques' => 22, 'fr|bottes' => 33, '|bottes' => 11, '|casques' => 22 ];
$vend  = [ 'url' => 'https://kula-tactical.com/bottes', 'clicks' => 10.0, 'impr' => 900.0, 'ctr' => 0.011, 'pos' => 12.0, 'terms' => [] ];
$creux = [ 'url' => 'https://kula-tactical.com/casques', 'clicks' => 10.0, 'impr' => 4000.0, 'ctr' => 0.0025, 'pos' => 12.0, 'terms' => [] ];
$sales = [ 11 => [ 'units' => 400 ], 22 => [ 'units' => 0 ] ];
$r = DZE_Netlinking::rank( [ $creux, $vend ], $sales, $map );
ok( 'la categorie qui vend passe devant', $r[0]['url'], $vend['url'] );
ok( 'et celle qui ne vend rien suit',     $r[1]['url'], $creux['url'] );
// SANS VENTES DU TOUT, on retombe sur les clics a gagner.
$r2 = DZE_Netlinking::rank( [ $vend, $creux ], [], $map );
ok( 'sans ventes, le trafic decide',      $r2[0]['url'], $creux['url'] );

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
// « Les ventes sont comptabilisées seulement sur la langue concernée. » Un
// zero sur la page allemande EST l information — ce catalogue ne vend pas encore.
ok( 'le report entre langues a disparu',
	method_exists( 'DZE_Netlinking', 'spread_across_languages' ), false );
$vendu = [ 11 => [ 'units' => 400 ] ];
$fr    = [ 'url' => 'https://kula-tactical.fr/bottes', 'clicks' => 10.0, 'impr' => 900.0, 'ctr' => 0.011, 'pos' => 12.0, 'terms' => [] ];
$one2  = DZE_Netlinking::rank( [ $fr ], $vendu, $map )[0];
ok( 'la page francaise est bien reconnue',  (int) $one2['tid'], 33 );
ok( 'et ne recupere pas les ventes anglaises', (int) $one2['units'], 0 );

echo "\nLE DOMAINE PRINCIPAL NE DEPEND PAS DE LA VISITE\n";
// La lecture tourne en cron, declenche par une visite sur n importe quel
// domaine. WPML y reecrit home_url() dans la langue de la visite : sur le
// domaine francais, le domaine principal sortait de la liste.
$src_d = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-netlinking.php' );
ok( 'domains() lit l adresse enregistree',  false !== strpos( $src_d, "wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST )" ), true );
ok( 'et le principal reste le sien',         ( DZE_Netlinking::domains()['en'] ?? '' ), 'kula-tactical.com' );

// ET JAMAIS EN ARGENT : la table de WooCommerce garde chaque commande dans sa
// devise, et cette boutique en encaisse huit. La premiere mesure a rendu
// 677 120 pour trente unites — un melange de dollars et de livres turques.
$src2 = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-netlinking.php' );
// LA REGLE A CHANGE PARCE QU ON A TROUVE LE TAUX. WooPayments ecrit sur
// chaque commande le taux REEL du jour de l achat, donc la recette se compte
// — mais JAMAIS sans cette conversion, sinon on additionne des dollars et des
// livres turques comme la premiere fois.
ok( 'la recette est toujours convertie',
	false !== strpos( $src2, '_wcpay_multi_currency_stripe_exchange_rate' ), true );
ok( 'et jamais sommee sans son taux',
	1, substr_count( $src2, 'SUM( l.product_net_revenue' ) );
ok( 'la somme porte bien le taux',
	false !== strpos( $src2, 'SUM( l.product_net_revenue * COALESCE' ), true );

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
// Le retour anticipe : la liste vide, quel que soit le nom de sa variable.
$pos_vide   = strpos( $src4, 'if ( ! $all ) {' );
ok( 'le script est branche avant le retour anticipe',
	$pos_script !== false && $pos_vide !== false && $pos_script < $pos_vide, true );
// ET IL NE RESTE AUCUN <script> APRES CE RETOUR : c est le motif exact qui a
// casse, et il se reintroduit sans bruit.
$apres = $pos_vide !== false ? substr( $src4, $pos_vide ) : '';
ok( 'et plus aucun script ne vit apres lui', false !== strpos( $apres, '<script>' ), false );

echo "\nUN RANG NE SE FAIT PAS PASSER POUR UNE PREVISION\n";
// Le premier calcul multipliait les clics a gagner par « unites vendues /
// clics Google » : /military-balaclava sortait a +171 unites pour +17 clics.
$maigre = [ 'url' => 'https://kula-tactical.com/bottes', 'clicks' => 5.0, 'impr' => 443.0, 'ctr' => 0.011, 'pos' => 29.5, 'terms' => [] ];
$r5 = DZE_Netlinking::rank( [ $maigre ], [ 11 => [ 'units' => 50 ] ], $map )[0];
ok( 'la priorite reste du meme ordre que les clics', $r5['worth'] < $r5['gain'] * 3, true );
$a1 = [ 'url' => 'https://kula-tactical.com/bottes',  'clicks' => 10.0, 'impr' => 900.0, 'ctr' => 0.011, 'pos' => 12.0, 'terms' => [] ];
$a2 = [ 'url' => 'https://kula-tactical.com/casques', 'clicks' => 10.0, 'impr' => 900.0, 'ctr' => 0.011, 'pos' => 12.0, 'terms' => [] ];
$r6 = DZE_Netlinking::rank( [ $a2, $a1 ], [ 11 => [ 'units' => 50 ] ], $map );
ok( 'celle qui vend passe devant a trafic egal', $r6[0]['url'], $a1['url'] );
$p50  = DZE_Netlinking::rank( [ $a1 ], [ 11 => [ 'units' => 50 ] ], $map )[0]['worth'];
$p500 = DZE_Netlinking::rank( [ $a1 ], [ 11 => [ 'units' => 500 ] ], $map )[0]['worth'];
ok( 'dix fois plus de ventes ne fait pas dix fois le rang', $p500 < $p50 * 2, true );
ok( 'mais il monte quand meme',                          $p500 > $p50, true );
// LA VALEUR DU CLIC. « Manque comptage de la valeur de chaque clic. »
$pc = DZE_Netlinking::rank( [ $a1 ], [ 11 => [ 'units' => 5, 'revenue' => 500.0 ] ], $map )[0];
ok( 'la valeur du clic est le chiffre de la categorie sur ses clics', round( $pc['per_click'], 2 ), 50.0 );

echo "\nLE MODULE EST BRANCHE COMME LES AUTRES\n";
$h = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-health.php' );
ok( 'il a son controle de sante',        false !== strpos( $h, 'function check_searchconsole' ), true );
ok( 'et le journal sait ou le reparer',  false !== strpos( $h, "case 'searchconsole'" ), true );
$dash = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-dashboard.php' );
ok( 'l accueil compte les categories a pousser, pas les lignes', false !== strpos( $dash, 'DZE_Netlinking::targets_count()' ), true );
$cl = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-cleanup.php' );
foreach ( [ 'dze_nl_connection', 'dze_nl_settings', 'dze_nl_targets', 'dze_nl_last_error', 'dze_nl_token' ] as $opt ) {
	ok( "la desinstallation emporte $opt", false !== strpos( $cl, $opt ), true );
}
$src5 = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-netlinking.php' );
ok( 'le cron passe par une enveloppe',   false !== strpos( $src5, 'function cron_refresh' ), true );
ok( 'qui retient ce qui a rate',         false !== strpos( $src5, 'OPT_LAST_ERROR' ), true );
ok( 'et le dit au journal de sante',     false !== strpos( $src5, 'DZE_Health::log' ), true );

echo "\nL ECRAN SUIT CELUI DES TRADUCTIONS\n";
// « Les menus du module traduction WPML devraient servir de modèle. »
$sc = (string) file_get_contents( __DIR__ . '/../' . $dir . '/includes/class-screens.php' );
ok( 'deux onglets, nommes au catalogue',  false !== strpos( $sc, "'categories' => [ 'label' => __( 'Categories'" ) && false !== strpos( $sc, "'console'    => [ 'label' => __( 'Search Console'" ), true );
ok( 'la barre de filtres est celle des traductions', false !== strpos( $src5, 'class="dze-trd-global dze-nl-filters"' ), true );
ok( 'la liste s ouvre sur le travail',    false !== strpos( $src5, "\$_GET['status'] ) ) : 'reach';" ), true );
ok( 'les en-tetes trient',                false !== strpos( $src5, 'function sort_now' ), true );
ok( 'et le tri voyage dans l adresse',    false !== strpos( $src5, "\$_GET['by']" ), true );
ok( 'la pagination est celle de WordPress', false !== strpos( $src5, 'tablenav-pages' ), true );
ok( 'les proprietes sont montrees',       false !== strpos( $src5, 'function render_props' ), true );
ok( 'et un domaine non couvert est nomme', false !== strpos( $src5, 'Not read:' ), true );
ok( 'une lecture d avant, qui melangeait les produits, ne se montre pas', false !== strpos( $src5, "2 !== (int) ( \$d['model'] ?? 0 )" ), true );
$css = (string) file_get_contents( __DIR__ . '/../' . $dir . '/admin/css/content.css' );
ok( 'le module a sa feuille de style',    false !== strpos( $css, '.dze-nl-table' ), true );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
