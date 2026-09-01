<?php
/**
 * The same page of the shop, in the reader's language.
 *
 * Run before every release:  php tools/test-links.php dazont-ecom
 *
 * A translated email went out with every link pointing at the English page.
 * The German reader read German and landed on English, on a shop that has
 * German pages — and Klaviyo, which asks for a value in every language for
 * every field, showed those links in red for months without anybody reading
 * the colour as "your links are wrong".
 *
 * The mapping is the part nothing else can check: WPML answers twice — the
 * TRANSLATION of a post, which has a slug of its own, and the language's URL
 * rule for everything that is not a post. This exercises both, and the two
 * cases where doing nothing is the right answer: an address that is not this
 * shop's, and a Klaviyo variable that only looks like one.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'ICL_SITEPRESS_VERSION', '4.6.0' );
define( 'DAY_IN_SECONDS', 86400 );

function home_url( $p = '/' ) { return 'https://kula.test' . $p; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

/**
 * The shop: two products with a German translation of their own, and a
 * category page WPML only knows how to prefix.
 */
$GLOBALS['posts'] = [
	'https://kula.test/spetsnaz-balaclava'  => 11,
	'https://kula.test/punisher-balaclava'  => 12,
];
$GLOBALS['trans'] = [
	// post id => lang => the translated post
	11 => [ 'de' => 111, 'fr' => 112 ],
	12 => [],
];
$GLOBALS['links'] = [
	11  => 'https://kula.test/spetsnaz-balaclava',
	12  => 'https://kula.test/punisher-balaclava',
	111 => 'https://kula.test/de/sturmhaube-spetsnaz',
	112 => 'https://kula.test/fr/cagoule-spetsnaz',
];
function url_to_postid( $url ) { return (int) ( $GLOBALS['posts'][ rtrim( (string) $url, '/' ) ] ?? 0 ); }
function get_post_type( $id ) { return 'product'; }
function get_permalink( $id ) { return $GLOBALS['links'][ (int) $id ] ?? ''; }

/** WPML's own two filters, answering the way WPML answers. */
function apply_filters( $tag, $value = null, ...$args ) {
	if ( 'wpml_default_language' === $tag ) {
		return 'en';
	}
	if ( 'wpml_object_id' === $tag ) {
		[ , $return_original, $lang ] = $args;
		$id = (int) ( $GLOBALS['trans'][ (int) $value ][ $lang ] ?? 0 );
		return $id ?: ( $return_original ? (int) $value : null );
	}
	if ( 'wpml_permalink' === $tag ) {
		// A shop whose languages are subdomains: WPML swaps the HOST. Handed an
		// address that is not the shop's, that same rule quietly invents
		// de.cdn.klaviyo.test — which is why the caller checks the host first.
		$lang = (string) $args[0];
		$host = parse_url( (string) $value, PHP_URL_HOST );
		return $host ? str_replace( '//' . $host, '//' . $lang . '.' . $host, (string) $value ) : (string) $value;
	}
	return $value;
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
function to( string $url, string $lang ): string { return DZE_Wpml::url_in_language( $url, $lang ); }

echo "A product the shop has translated\n";
ok( 'the German page, with its own slug',
	to( 'https://kula.test/spetsnaz-balaclava', 'de' ), 'https://kula.test/de/sturmhaube-spetsnaz' );
ok( 'and the French one',
	to( 'https://kula.test/spetsnaz-balaclava', 'fr' ), 'https://kula.test/fr/cagoule-spetsnaz' );

echo "A product it has NOT translated\n";
// This is the check that was written wrong, and it shipped the bug it was
// supposed to catch: WPML, asked for a translation with the original as its
// fallback, answers with the ORIGINAL post — so the code took that permalink
// for a success and never reached the language's URL rule. Every link in
// every German email stayed on kula-tactical.com, which is what the shop saw
// in Klaviyo: "c'est une copie des liens en .com". An untranslated product
// still has a German address, and WPML serves it.
ok( 'still goes to the German side of the shop',
	to( 'https://kula.test/punisher-balaclava', 'de' ), 'https://de.kula.test/punisher-balaclava' );

echo "Everything that is not a post\n";
ok( 'the home page follows the language rule',
	to( 'https://kula.test/', 'de' ), 'https://de.kula.test/' );
ok( 'so does a category',
	to( 'https://kula.test/balaclavas/', 'fr' ), 'https://fr.kula.test/balaclavas/' );

echo "WPML's own converter answers first\n";
// $sitepress->convert_url() is what WPML calls on its own links. When it is
// there it decides; the filter stays for the versions that have no $sitepress.
class FakeSitePress {
	public $asked = [];
	public function convert_url( $url, $lang ) {
		$this->asked[] = [ $url, $lang ];
		return str_replace( 'https://kula.test/', 'https://kula.test/' . $lang . '/', (string) $url );
	}
}
$GLOBALS['sitepress'] = new FakeSitePress();
ok( 'it is the answer that is used',
	to( 'https://kula.test/punisher-balaclava', 'de' ), 'https://kula.test/de/punisher-balaclava' );
ok( 'and it was asked for that language',
	$GLOBALS['sitepress']->asked[0] ?? [], [ 'https://kula.test/punisher-balaclava', 'de' ] );
ok( 'a translated post still beats it',
	to( 'https://kula.test/spetsnaz-balaclava', 'de' ), 'https://kula.test/de/sturmhaube-spetsnaz' );
unset( $GLOBALS['sitepress'] );

echo "And what must never be touched\n";
ok( 'a photograph on another host',
	to( 'https://cdn.klaviyo.test/hero.jpg', 'de' ), 'https://cdn.klaviyo.test/hero.jpg' );
ok( "Klaviyo's own variable",
	to( '{{ organization.url }}', 'de' ), '{{ organization.url }}' );
ok( 'the sender\'s name, which is not a URL',
	to( 'Kula Tactical', 'de' ), 'Kula Tactical' );
ok( 'the language the shop writes in',
	to( 'https://kula.test/spetsnaz-balaclava', 'en' ), 'https://kula.test/spetsnaz-balaclava' );
ok( 'and no language at all',
	to( 'https://kula.test/spetsnaz-balaclava', '' ), 'https://kula.test/spetsnaz-balaclava' );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
