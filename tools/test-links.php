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
// The English page is a page. Inventing /de/punisher-balaclava would be a
// link to nothing, which is worse than a link to the wrong language.
ok( 'falls back to the page that exists',
	to( 'https://kula.test/punisher-balaclava', 'de' ), 'https://kula.test/punisher-balaclava' );

echo "Everything that is not a post\n";
ok( 'the home page follows the language rule',
	to( 'https://kula.test/', 'de' ), 'https://de.kula.test/' );
ok( 'so does a category',
	to( 'https://kula.test/balaclavas/', 'fr' ), 'https://fr.kula.test/balaclavas/' );

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
