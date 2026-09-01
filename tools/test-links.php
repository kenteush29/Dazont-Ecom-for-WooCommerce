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

function get_page_by_path( $path, $output = OBJECT, $type = 'page' ) {
	$GLOBALS['asked_path'][] = [ $path, $type ];
	$id = (int) ( $GLOBALS['slugs'][ (string) $type ][ (string) $path ] ?? 0 );
	return $id ? (object) [ 'ID' => $id ] : null;
}
function get_post_type( $id ) { return $GLOBALS['types'][ (int) $id ] ?? ''; }
function get_permalink( $id ) { return $GLOBALS['perma'][ (int) $id ] ?? ''; }

// The lookup that does not work where the emails are written. Anything that
// calls it is counted, and the count must stay zero.
function url_to_postid( $url ) { $GLOBALS['resolved'][] = $url; return 0; }

/** WPML's filters. $GLOBALS['mode'] is how this shop writes its language URLs. */
function apply_filters( $tag, $value = null, ...$args ) {
	if ( 'wpml_default_language' === $tag ) { return 'en'; }
	if ( 'wpml_object_id' === $tag ) {
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

$GLOBALS['mode'] = 'dir';
echo "The page is found by its SLUG, never by walking the rewrite rules\n";
$GLOBALS['asked_path'] = [];
ok( 'a product address finds its product',
	DZE_Wpml::post_of( 'https://kula.test/spetsnaz-balaclava' ), 11 );
ok( 'products are asked for first',      $GLOBALS['asked_path'][0] ?? [], [ 'spetsnaz-balaclava', 'product' ] );
ok( 'a page is found by its whole path', DZE_Wpml::post_of( 'https://kula.test/about-us' ), 20 );
ok( 'a post too',                        DZE_Wpml::post_of( 'https://kula.test/hello' ), 30 );
ok( 'a trailing slash changes nothing',  DZE_Wpml::post_of( 'https://kula.test/spetsnaz-balaclava/' ), 11 );
ok( 'and a query string does not either',DZE_Wpml::post_of( 'https://kula.test/spetsnaz-balaclava?utm=x' ), 11 );
ok( 'an address of nothing is nothing',  DZE_Wpml::post_of( 'https://kula.test/no-such-thing' ), 0 );
ok( 'the home page is not a post',       DZE_Wpml::post_of( 'https://kula.test/' ), 0 );
ok( 'url_to_postid() was never called',  $GLOBALS['resolved'] ?? [], [] );

echo "A translated product: its OWN page, whatever the URL shape\n";
foreach ( [ 'dir', 'sub', 'param', 'domain', 'none' ] as $mode ) {
	$GLOBALS['mode'] = $mode;
	reset_caches();
	[ $url, $why ] = to( 'https://kula.test/spetsnaz-balaclava', 'de' );
	ok( "its German page ($mode)",       $url, 'https://kula.test/de/sturmhaube-spetsnaz' );
	ok( "and the step says so ($mode)",  $why, 'translation' );
}

echo "An UNtranslated product: the language's own URL rule\n";
$expect = [
	'dir'    => 'https://kula.test/de/punisher-balaclava',
	'sub'    => 'https://de.kula.test/punisher-balaclava',
	'param'  => 'https://kula.test/punisher-balaclava?lang=de',
	'domain' => 'https://kula.de/punisher-balaclava',
];
foreach ( $expect as $mode => $want ) {
	$GLOBALS['mode'] = $mode;
	reset_caches();
	[ $url, $why ] = to( 'https://kula.test/punisher-balaclava', 'de' );
	ok( "the German address ($mode)",    $url, $want );
	ok( "by the URL rule ($mode)",       $why, 'filter' );
}
// And the shop where a language simply has no address of its own: the link
// cannot move, and the screen must be able to SAY which step gave up.
$GLOBALS['mode'] = 'none';
reset_caches();
[ $url, $why ] = to( 'https://kula.test/punisher-balaclava', 'de' );
ok( 'nothing to move to is left alone', $url, 'https://kula.test/punisher-balaclava' );
ok( 'and it is named, not guessed',     $why, 'not-translated' );

echo "WPML's own converter, when the object is there\n";
class FakeSitePress {
	public $asked = [];
	public function convert_url( $url, $lang ) {
		$this->asked[] = [ $url, $lang ];
		return str_replace( 'https://kula.test/', 'https://kula.test/' . $lang . '/', (string) $url );
	}
}
$GLOBALS['mode'] = 'none'; // the filter would answer nothing; convert_url must.
$GLOBALS['sitepress'] = new FakeSitePress();
reset_caches();
[ $url, $why ] = to( 'https://kula.test/punisher-balaclava', 'fr' );
ok( 'it answers before the filter',     $url, 'https://kula.test/fr/punisher-balaclava' );
ok( 'and the step says which',          $why, 'url-rule' );
ok( 'a translation still beats it',     to( 'https://kula.test/spetsnaz-balaclava', 'fr' )[0], 'https://kula.test/fr/cagoule-spetsnaz' );
unset( $GLOBALS['sitepress'] );

echo "Pages and categories, not only products\n";
$GLOBALS['mode'] = 'dir';
reset_caches();
ok( 'a translated page goes to its own', to( 'https://kula.test/about-us', 'de' )[0], 'https://kula.test/de/ueber-uns' );
ok( 'the home page follows the rule',    to( 'https://kula.test/', 'de' )[0], 'https://kula.test/de/' );
ok( 'so does a category',                to( 'https://kula.test/balaclavas/', 'fr' )[0], 'https://kula.test/fr/balaclavas/' );

echo "And what must never be touched\n";
foreach ( [ 'dir', 'sub', 'param', 'domain' ] as $mode ) {
	$GLOBALS['mode'] = $mode;
	reset_caches();
	ok( "a photograph on another host ($mode)",
		to( 'https://cdn.klaviyo.test/hero.jpg', 'de' )[0], 'https://cdn.klaviyo.test/hero.jpg' );
}
$GLOBALS['mode'] = 'dir';
ok( "Klaviyo's own variable",           to( '{{ organization.url }}', 'de' )[0], '{{ organization.url }}' );
ok( 'the sender name, which is no URL', to( 'Kula Tactical', 'de' )[0], 'Kula Tactical' );
ok( 'an address with no scheme',        to( '/spetsnaz-balaclava', 'de' )[0], '/spetsnaz-balaclava' );
ok( 'the language the shop writes in',  to( 'https://kula.test/spetsnaz-balaclava', 'en' )[0], 'https://kula.test/spetsnaz-balaclava' );
ok( 'no language at all',               to( 'https://kula.test/spetsnaz-balaclava', '' )[0], 'https://kula.test/spetsnaz-balaclava' );
ok( 'an empty address',                 to( '', 'de' )[0], '' );

echo "Nothing in the chain ever leans on the rewrite rules\n";
ok( 'not once, in any of it',           $GLOBALS['resolved'] ?? [], [] );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
