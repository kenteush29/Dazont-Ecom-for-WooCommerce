<?php
/**
 * A page of this shop, in the reader's language — every shape it comes in.
 *
 * Run before every release:  php tools/test-links.php dazont-ecom
 *
 * Three releases in a row shipped a link that did not move, each time for a
 * different reason, each time discovered by the shop in Klaviyo instead of
 * here. So this does not test "the happy case": it walks the whole chain,
 * step by step, in every setup WPML actually ships — languages in a
 * directory, in a subdomain, in a parameter, in another domain — with the
 * products translated and untranslated, and with the lookups that do NOT
 * work where the emails are written.
 *
 * The one thing that must never be relied on again is url_to_postid(): it
 * walks the rewrite rules, admin-ajax has none loaded, and it answered 0 for
 * every WooCommerce product on the shop. It is defined here to answer 0
 * always, so anything that leans on it fails in this file rather than in an
 * inbox.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'ICL_SITEPRESS_VERSION', '4.6.0' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'OBJECT', 'OBJECT' );

function get_transient( $k ) { return false; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
/**
 * WPML's own settings, which is where the shape of a language address is
 * written — and the only place that answers the same in admin-ajax as on the
 * front. 2 = a domain per language, which is what this shop runs.
 */
function get_option( $k, $d = false ) {
	if ( 'icl_sitepress_settings' === $k ) {
		return $GLOBALS['icl'] ?? [];
	}
	return $GLOBALS['opts'][ $k ] ?? $d;
}
$GLOBALS['opts'] = [];
$GLOBALS['icl']  = [
	'language_negotiation_type' => 2,
	'default_language'          => 'en',
	'language_domains'          => [ 'de' => 'kula.de', 'fr' => 'kula.fr' ],
];
function set_transient( $k, $v, $t = 0 ) { return true; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function home_url( $p = '/' ) { return 'https://kula.test' . $p; }

// --- The shop -------------------------------------------------------------
// Slugs, because that is what an address carries and what the posts table
// holds. Two products (one translated, one not), a page, a category.
$GLOBALS['slugs'] = [
	'product' => [ 'spetsnaz-balaclava' => 11, 'punisher-balaclava' => 12 ],
	'page'    => [ 'about-us' => 20 ],
	'post'    => [ 'hello' => 30 ],
];
$GLOBALS['trans'] = [ 11 => [ 'de' => 111, 'fr' => 112 ], 20 => [ 'de' => 120 ] ];
$GLOBALS['perma'] = [
	11  => 'https://kula.test/spetsnaz-balaclava',
	12  => 'https://kula.test/punisher-balaclava',
	111 => 'https://kula.test/de/sturmhaube-spetsnaz',
	112 => 'https://kula.test/fr/cagoule-spetsnaz',
	20  => 'https://kula.test/about-us',
	120 => 'https://kula.test/de/ueber-uns',
];
$GLOBALS['types'] = [ 11 => 'product', 12 => 'product', 20 => 'page', 30 => 'post' ];

// WordPress's own: one type, or a LIST of them.
function get_page_by_path( $path, $output = OBJECT, $type = 'page' ) {
	$GLOBALS['asked_path'][] = [ $path, $type ];
	foreach ( (array) $type as $one ) {
		$id = (int) ( $GLOBALS['slugs'][ (string) $one ][ (string) $path ] ?? 0 );
		if ( $id ) { return (object) [ 'ID' => $id ]; }
	}
	return null;
}
function get_post_types( $args = [], $out = 'names' ) { return $GLOBALS['ptypes'] ?? [ 'post', 'page', 'product' ]; }
function get_post_type( $id ) { return $GLOBALS['types'][ (int) $id ] ?? ''; }
function get_permalink( $id ) { return $GLOBALS['perma'][ (int) $id ] ?? ''; }

// The lookup that does not work where the emails are written. Anything that
// calls it is counted, and the count must stay zero.
function url_to_postid( $url ) { $GLOBALS['resolved'][] = $url; return 0; }

/** WPML's filters. $GLOBALS['mode'] is how this shop writes its language URLs. */
function apply_filters( $tag, $value = null, ...$args ) {
	if ( 'wpml_default_language' === $tag ) { return 'en'; }
	if ( 'wpml_active_languages' === $tag ) { return $GLOBALS['langs'] ?? []; }
	if ( 'wpml_object_id' === $tag ) {
		// A FILTER, and a filter only answers where WPML's hooks are loaded.
		// On this shop they are not, in the request where the emails are
		// written: every product came back untranslated, which is what sent
		// every link through a host swap.
		if ( ! empty( $GLOBALS['no_filter'] ) ) {
			return $value;
		}
		[ , $return_original, $lang ] = $args;
		$id = (int) ( $GLOBALS['trans'][ (int) $value ][ $lang ] ?? 0 );
		return $id ?: ( $return_original ? (int) $value : null );
	}
	if ( 'wpml_permalink' === $tag ) {
		return dze_convert( (string) $value, (string) $args[0] );
	}
	return $value;
}
function dze_convert( string $url, string $lang ): string {
	$host = parse_url( $url, PHP_URL_HOST );
	if ( ! $host || 'kula.test' !== $host ) {
		// WPML's own converters leave another site's address alone... in most
		// modes. The subdomain one does not, which is why the caller checks
		// the host before it ever gets here.
		return 'sub' === ( $GLOBALS['mode'] ?? '' ) ? str_replace( '//' . $host, '//' . $lang . '.' . $host, $url ) : $url;
	}
	switch ( $GLOBALS['mode'] ?? 'dir' ) {
		case 'sub':   return str_replace( '//kula.test', '//' . $lang . '.kula.test', $url );
		case 'param': return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'lang=' . $lang;
		case 'domain':return str_replace( '//kula.test', '//kula.' . ( 'de' === $lang ? 'de' : 'fr' ), $url );
		case 'none':  return $url; // one shop, several languages, no URL of their own.
		default:      return str_replace( 'https://kula.test/', 'https://kula.test/' . $lang . '/', $url );
	}
}

// WPML's own table, which answers with no hooks at all: every translation of
// one thing shares a trid.
$GLOBALS['icl_table'] = true;
$GLOBALS['icl_rows']  = [];
class DZE_Links_Wpdb {
	public $prefix = 'wp_';
	public function prepare( $q, ...$a ) { return [ $q, $a ]; }
	public function get_var( $q ) {
		[ $sql, $args ] = is_array( $q ) ? $q : [ (string) $q, [] ];
		if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) ) {
			return ! empty( $GLOBALS['icl_table'] ) ? 'wp_icl_translations' : '';
		}
		if ( false !== strpos( $sql, 'icl_translations' ) ) {
			[ $lang, $type, $id ] = $args;
			return (int) ( $GLOBALS['icl_rows'][ (string) $type ][ (int) $id ][ (string) $lang ] ?? 0 );
		}
		return '';
	}
	public function get_col( $q ) { return []; }
}
$GLOBALS['wpdb'] = new DZE_Links_Wpdb();

require __DIR__ . '/../' . $dir . '/includes/class-wpml.php';

$fails = 0;
$ran   = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok    %s\n", $what ); return; }
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}
/** The address, and the STEP that produced it. */
function to( string $url, string $lang ): array {
	$why = '';
	$out = DZE_Wpml::url_in_language( $url, $lang, $why );
	return [ $out, $why ];
}
function reset_caches(): void {
	// The helper remembers what it has looked up; each setup is a new shop.
	( new ReflectionMethod( 'DZE_Wpml', 'post_of' ) )->invoke( null, '' );
	$GLOBALS['resolved'] = [];
}

/** The shop as WPML has it set up: how the languages live in the address. */
function shop( int $type, array $domains = [] ): void {
	$GLOBALS['icl'] = [
		'language_negotiation_type' => $type,
		'default_language'          => 'en',
		'language_domains'          => $domains,
	];
	$GLOBALS['resolved'] = [];
}

shop( 1 );
echo "The page is found by its SLUG, never by walking the rewrite rules\n";
$GLOBALS['asked_path'] = [];
ok( 'a product address finds its product',
	DZE_Wpml::post_of( 'https://kula.test/spetsnaz-balaclava' ), 11 );
ok( 'products are asked for first',      $GLOBALS['asked_path'][0] ?? [], [ 'spetsnaz-balaclava', 'product' ] );
ok( 'a page is found by its whole path', DZE_Wpml::post_of( 'https://kula.test/about-us' ), 20 );
ok( 'a post too',                        DZE_Wpml::post_of( 'https://kula.test/hello' ), 30 );
ok( 'a trailing slash changes nothing',  DZE_Wpml::post_of( 'https://kula.test/spetsnaz-balaclava/' ), 11 );
ok( 'and a query string does not either',DZE_Wpml::post_of( 'https://kula.test/spetsnaz-balaclava?utm=x' ), 11 );
// A type this lookup does not name is still a page of the shop. It used to
// fall through as "not one of ours", and a page nobody recognises gets the
// language's URL RULE — the right domain carrying the English slug, which is
// the fake address the shop kept finding in its emails.
$GLOBALS['ptypes'] = [ 'post', 'page', 'product', 'dze_guide' ];
$GLOBALS['slugs']['dze_guide'] = [ 'how-to-fold-a-shemagh' => 44 ];
ok( 'a page of any public type is found',
	DZE_Wpml::post_of( 'https://kula.test/how-to-fold-a-shemagh' ), 44 );
ok( 'an address of nothing is nothing',  DZE_Wpml::post_of( 'https://kula.test/no-such-thing' ), 0 );
ok( 'the home page is not a post',       DZE_Wpml::post_of( 'https://kula.test/' ), 0 );
ok( 'url_to_postid() was never called',  $GLOBALS['resolved'] ?? [], [] );

echo "This shop: a domain per language, and a slug of its own\n";
// The case the shop sent, in his own words: kula-tactical.com/tactical-phone-
// pouch-laser-cut-molle exists as kula-tactical.de/taktische-handyhuelle-mit-
// lasergeschnittenem-molle-system. Both halves have to move — the domain AND
// the slug — and in admin-ajax get_permalink() gives the German slug on the
// ENGLISH domain, because WPML's front-end URL filters are not what is
// running there. The shop's own settings say where German lives.
shop( 2, [ 'de' => 'kula.de', 'fr' => 'kula.fr' ] );
[ $url, $why ] = to( 'https://kula.test/spetsnaz-balaclava', 'de' );
ok( 'the German slug, on the German domain', $url, 'https://kula.de/de/sturmhaube-spetsnaz' );
ok( 'and the step says translation',     $why, 'translation' );
// A link already written on a language domain is still this shop's link.
ok( 'a language domain is ours too',
	to( 'https://kula.de/sturmhaube-spetsnaz', 'fr' )[1] !== 'not-ours', true );

echo "A product nobody has translated: the page that EXISTS\n";
// "CA ARRIVE d'avoir un produit qui manque des traductions. Dans ce cas oui
// backup vers page d'origine." An invented German address is a 404, which is
// worse than a page in the wrong language.
foreach ( [ 1 => [], 2 => [ 'de' => 'kula.de' ], 3 => [] ] as $type => $domains ) {
	shop( $type, $domains );
	[ $url, $why ] = to( 'https://kula.test/punisher-balaclava', 'de' );
	ok( "the original page ($type)",     $url, 'https://kula.test/punisher-balaclava' );
	ok( "and it is named ($type)",       $why, 'not-translated' );
}

echo "Everything that is not a page of ours follows the language's shape\n";
shop( 1 );
ok( 'the home page, in a directory',     to( 'https://kula.test/', 'de' )[0], 'https://kula.test/de/' );
ok( 'a category, in a directory',        to( 'https://kula.test/balaclavas/', 'fr' )[0], 'https://kula.test/fr/balaclavas/' );
shop( 2, [ 'de' => 'kula.de' ] );
ok( 'the home page, on its own domain',  to( 'https://kula.test/', 'de' )[0], 'https://kula.de/' );
shop( 3 );
ok( 'and as a parameter',                to( 'https://kula.test/balaclavas/', 'fr' )[0], 'https://kula.test/balaclavas/?lang=fr' );

echo "The shipped translation always wins over the shape\n";
shop( 2, [ 'fr' => 'kula.fr' ] );
ok( 'the French page keeps its own slug', to( 'https://kula.test/spetsnaz-balaclava', 'fr' )[0], 'https://kula.fr/fr/cagoule-spetsnaz' );
// A translated PAGE, not only products.
shop( 1 );
ok( 'a translated page too',             to( 'https://kula.test/about-us', 'de' )[0], 'https://kula.test/de/ueber-uns' );

echo "WPML's own converter, when the settings say nothing\n";
class FakeSitePress {
	public $asked = [];
	public function convert_url( $url, $lang ) {
		$this->asked[] = [ $url, $lang ];
		return str_replace( 'https://kula.test/', 'https://kula.test/' . $lang . '/', (string) $url );
	}
}
shop( 0 ); // a shop whose shape we cannot read: the object answers instead.
$GLOBALS['sitepress'] = new FakeSitePress();
[ $url, $why ] = to( 'https://kula.test/balaclavas/', 'fr' );
ok( 'it answers for a listing page',     $url, 'https://kula.test/fr/balaclavas/' );
ok( 'and the step says which',           $why, 'url-rule' );
unset( $GLOBALS['sitepress'] );

echo "And what must never be touched\n";
foreach ( [ 1 => [], 2 => [ 'de' => 'kula.de' ], 3 => [] ] as $type => $domains ) {
	shop( $type, $domains );
	ok( "a photograph on another host ($type)",
		to( 'https://cdn.klaviyo.test/hero.jpg', 'de' )[0], 'https://cdn.klaviyo.test/hero.jpg' );
}
shop( 1 );
ok( "Klaviyo's own variable",           to( '{{ organization.url }}', 'de' )[0], '{{ organization.url }}' );
ok( 'the sender name, which is no URL', to( 'Kula Tactical', 'de' )[0], 'Kula Tactical' );
ok( 'an address with no scheme',        to( '/spetsnaz-balaclava', 'de' )[0], '/spetsnaz-balaclava' );
ok( 'the language the shop writes in',  to( 'https://kula.test/spetsnaz-balaclava', 'en' )[0], 'https://kula.test/spetsnaz-balaclava' );
ok( 'no language at all',               to( 'https://kula.test/spetsnaz-balaclava', '' )[0], 'https://kula.test/spetsnaz-balaclava' );
ok( 'an empty address',                 to( '', 'de' )[0], '' );

echo "Nothing in the chain ever leans on the rewrite rules\n";
ok( 'not once, in any of it',           $GLOBALS['resolved'] ?? [], [] );

echo "A language is drawn the way WPML draws one\n";
// The plugin printed "FR, DE, PL, ES" while the rest of this admin shows a
// flag and a state. One drawing now, from WPML's own data, used by the email
// rows, the Google feeds and the translation tables.
$GLOBALS['langs'] = [
	'en' => [ 'native_name' => 'English',  'country_flag_url' => 'https://kula.test/f/en.png' ],
	'de' => [ 'native_name' => 'Deutsch',  'country_flag_url' => 'https://kula.test/f/de.png' ],
	'fr' => [ 'native_name' => 'Français', 'country_flag_url' => 'https://kula.test/f/fr.png' ],
];
$de = DZE_Wpml::flag_html( 'de', 'done' );
ok( "it carries WPML's own flag",       false !== strpos( $de, 'f/de.png' ), true );
ok( 'the code, for a shop that reads codes', false !== strpos( $de, '>DE<' ), true );
ok( 'the language name is the title',   false !== strpos( $de, 'title="Deutsch"' ), true );
ok( 'and a tick when it is done',       false !== strpos( $de, 'is-done' ), true );
ok( 'a hollow one when it is owed',     false !== strpos( DZE_Wpml::flag_html( 'fr', 'todo' ), 'is-todo' ), true );
$row = DZE_Wpml::flags_html( [ 'de' ], [ 'fr' ] );
ok( 'a row holds both',                 substr_count( $row, 'dze-lang ' ) + substr_count( $row, 'dze-lang"' ), 2 );
ok( 'nothing at all draws nothing',     DZE_Wpml::flags_html( [], [] ), '' );
ok( 'and a language nobody has is not invented',
	false !== strpos( DZE_Wpml::flag_html( 'zz' ), '>ZZ<' ), true );
// THE BUG, in his own words: "kula-tactical.fr/hooded-combat-shirt devrait
// être kula-tactical.fr/chemise-tactique-a-capuche-yz". The right domain with
// the ENGLISH slug is what a host swap gives when the translation is not
// found; the right slug on the ENGLISH domain is what a permalink gives on a
// request that is not a front-end one. One function answers both halves now,
// and neither is built here.
shop( 2, [ 'de' => 'kula.de', 'fr' => 'kula.fr' ] );
$why = '';
$de  = DZE_Wpml::post_url_in_language( 11, 'product', 'de', $why );
ok( 'the post itself answers, slug and all', $de, 'https://kula.de/de/sturmhaube-spetsnaz' );
ok( 'and it says it is a translation',   $why, 'translation' );
ok( 'never the English slug on the German domain',
	false !== strpos( $de, 'spetsnaz-balaclava' ), false );
// Converted twice is how kula.de/de/de/ happened: an address that already
// carries the language is not handed to the converter again.
ok( 'the language is written once',      substr_count( $de, '/de/' ), 1 );
// A product nobody has translated answers with NOTHING, which is a real
// answer: the caller then leaves the original page alone rather than
// inventing an address in a language it was never written in.
$why = '';
ok( 'an untranslated product answers nothing',
	DZE_Wpml::post_url_in_language( 30, 'product', 'de', $why ), '' );
ok( 'and says so',                       $why, 'not-translated' );
ok( 'the shop language is not a translation',
	DZE_Wpml::post_url_in_language( 11, 'product', 'en' ), '' );

echo "When WPML's filter is not loaded, its own table answers\n";
// The shop's real state, read out of its own Klaviyo account: every product
// link in every language came back as the ENGLISH slug with the domain
// swapped — eight products, four languages, all at once. That is not eight
// untranslated products; it is a question that was never really asked.
// wpml_object_id is a filter, and where WPML's hooks are not loaded on the
// request it hands back the id it was given.
$GLOBALS['no_filter'] = true;
$GLOBALS['icl_rows']  = [ 'post_product' => [ 11 => [ 'de' => 111, 'fr' => 112 ] ] ];
shop( 2, [ 'de' => 'kula.de', 'fr' => 'kula.fr' ] );
ok( 'the translation is found in the table', DZE_Wpml::translated_id( 11, 'product', 'de' ), 111 );
ok( 'and the address is the German page',
	DZE_Wpml::post_url_in_language( 11, 'product', 'de' ), 'https://kula.de/de/sturmhaube-spetsnaz' );
ok( 'a product with no row is not translated',
	DZE_Wpml::translated_id( 12, 'product', 'de' ), 0 );
ok( 'and it invents no address for it',
	DZE_Wpml::post_url_in_language( 12, 'product', 'de' ), '' );
// No WPML table at all — a shop without WPML — answers nothing rather than
// dying on a query.
$GLOBALS['icl_table'] = false;
ok( 'no table, no answer',              DZE_Wpml::translated_id( 20, 'page', 'de' ), 0 );
$GLOBALS['icl_table'] = true;
$GLOBALS['no_filter'] = false;

ok( 'a refused one carries its own mark',
	false !== strpos( DZE_Wpml::flag_html( 'fr', 'ko' ), 'is-ko' ), true );

// WPML'S OWN ORDER, everywhere. The shop has EN, DE, FR in that order; a row
// showing what is written and what is owed must read in that order too, not
// the written ones first. Five languages that read differently on two rows of
// one screen make the eye start again on every row.
$order = static function ( string $html ): array {
	preg_match_all( '#<span class="dze-lang-code">([A-Z]+)</span>#', $html, $m );
	return $m[1];
};
ok( "the row follows WPML's order, not ours",
	$order( DZE_Wpml::flags_html( [ 'fr' ], [ 'de' ] ) ), [ 'DE', 'FR' ] );
ok( 'whichever of them is written',
	$order( DZE_Wpml::flags_html( [ 'de' ], [ 'fr' ] ) ), [ 'DE', 'FR' ] );
ok( 'and the states still travel with them',
	substr_count( DZE_Wpml::flags_html( [ 'de' ], [ 'fr' ] ), 'is-done' ), 1 );
// A language the shop has dropped is still a fact about an email written in
// it: it goes last rather than vanishing off the row.
ok( 'a language the shop no longer has is kept',
	$order( DZE_Wpml::flags_html( [ 'pl', 'de' ], [] ) ), [ 'DE', 'PL' ] );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
