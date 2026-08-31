<?php
/**
 * The body → Klaviyo blocks splitter, fed the shapes a model actually writes.
 *
 * Run before every release:  php tools/test-blocks.php dazont-ecom
 *
 * "As sent" showed an email of pictures and product cards with not a written
 * word on it. The splitter was right for the tidy shape — headings and
 * paragraphs as SIBLINGS of the product rows — and silently destroyed the
 * other one, where the model wraps the whole email in a single table: a node
 * holding cards became its card rows and nothing else, and every word inside
 * that node vanished. The draft in Klaviyo, built from the same blocks, went
 * out wordless too. A model's formatting is not a contract; the splitter has
 * to survive every shape that renders the same email.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }

require __DIR__ . '/../' . $dir . '/includes/class-klaviyo-blocks.php';

$fails = 0;
$ran   = 0;
function ok( string $what, $got, $want ) {
	global $fails, $ran;
	$ran++;
	if ( $got === $want ) { printf( "  ok    %s\n", $what ); return; }
	$fails++;
	printf( "  FAIL  %s\n          got  %s\n          want %s\n", $what, var_export( $got, true ), var_export( $want, true ) );
}

$t = [
	'head' => 'A', 'body' => 'B', 'ink' => '#111', 'link' => '#0a0', 'size' => 16,
	'btn_bg' => '#0a0', 'btn_ink' => '#fff', 'card' => '#fff', 'border' => '#eee',
	'radius' => 4, 'sale' => '#900', 'strike' => '#999',
];

/** One product card as place_products really wraps it. */
$card = static function ( int $n ): string {
	return '<div class="dze-card" style="display:inline-block;">'
		. '<table role="presentation"><tr><td><a href="https://s/p' . $n . '">'
		. '<img src="https://s/i' . $n . '.jpg" alt="P' . $n . '" /><div>Product ' . $n . '</div></a></td></tr></table></div>';
};

/** Every block of every row, flattened in order, as "type:text-inside". */
function flat( array $rows ): array {
	$out = [];
	foreach ( $rows as $row ) {
		foreach ( (array) ( $row['columns'] ?? [] ) as $col ) {
			foreach ( (array) ( $col['blocks'] ?? [] ) as $b ) {
				$type = (string) ( $b['type'] ?? '?' );
				$body = 'text' === $type ? trim( strip_tags( (string) ( $b['data']['content'] ?? '' ) ) ) : '';
				$out[] = $type . ( '' !== $body ? ':' . $body : '' );
			}
		}
	}
	return $out;
}
function types( array $rows ): array {
	$n = [];
	foreach ( flat( $rows ) as $one ) {
		$k = (string) strtok( $one, ':' );
		$n[ $k ] = ( $n[ $k ] ?? 0 ) + 1;
	}
	ksort( $n );
	return $n;
}

echo "The tidy shape: siblings, as the shop's own rows are written\n";
$flat_html = '<p><img src="https://k/pic.jpg" /></p>'
	. '<h1>Sale is live</h1><p>Everything 10% off until Sunday.</p>'
	. '<div>' . $card( 1 ) . $card( 2 ) . '</div>'
	. '<p>Last words.</p>';
$rows = DZE_Klaviyo_Blocks::rows( $flat_html, $t );
ok( 'every word survives', types( $rows ), [ 'image' => 3, 'text' => 5 ] );

echo "The wrapped shape: the model puts ONE table around the whole email\n";
$wrapped = '<p><img src="https://k/pic.jpg" /></p>'
	. '<table role="presentation" width="100%"><tr><td>'
	. '<h1>Sale is live</h1><p>Everything 10% off until Sunday.</p>'
	. '<div>' . $card( 1 ) . $card( 2 ) . '</div>'
	. '<p>Last words.</p>'
	. '</td></tr></table>';
$rows = DZE_Klaviyo_Blocks::rows( $wrapped, $t );
ok( 'every word STILL survives', types( $rows ), [ 'image' => 3, 'text' => 5 ] );

// And in the order they were written: picture, heading+paragraph, the
// products, the closing line. An email whose parts are right but shuffled is
// not the email that was approved.
$order = array_values( array_filter( flat( $rows ), static fn( $b ) => 0 === strpos( $b, 'text:' ) || 'image' === $b ) );
ok( 'the picture opens',        $order[0], 'image' );
ok( 'the heading follows',      $order[1], 'text:Sale is live' );
ok( 'then the paragraph',       $order[2], 'text:Everything 10% off until Sunday.' );
ok( 'and the close CLOSES',     end( $order ), 'text:Last words.' );

echo "The cards still make their product rows\n";
$per   = 3; // DZE_Klaviyo absent: the splitter's own default.
$cardy = array_values( array_filter( $rows, static fn( $r ) => str_contains( (string) json_encode( $r ), '2-columns' ) ) );
ok( 'two cards share one row of two columns', count( $cardy ), 1 );
ok( 'each with its own column', count( $cardy[0]['columns'] ?? [] ), 2 );

// Six cards, deep inside the wrapper, with words between the groups.
$six = '<table><tr><td><h2>Top picks</h2>'
	. '<div>' . $card( 1 ) . $card( 2 ) . $card( 3 ) . '</div>'
	. '<p>And for the field:</p>'
	. '<div>' . $card( 4 ) . $card( 5 ) . $card( 6 ) . '</div>'
	. '</td></tr></table>';
$rows = DZE_Klaviyo_Blocks::rows( $six, $t );
ok( 'words between two product groups survive',
	in_array( 'text:And for the field:', flat( $rows ), true ), true );
$threes = array_values( array_filter( $rows, static fn( $r ) => str_contains( (string) json_encode( $r ), '3-columns' ) ) );
ok( 'and each group keeps its own row', count( $threes ), 2 );

echo "What must not confuse it\n";
$mso = '<table><tr><td><!--[if mso]><table><tr><![endif]-->'
	. $card( 1 )
	. '<!--[if mso]></tr></table><![endif]--><p>After the card.</p></td></tr></table>';
$rows = DZE_Klaviyo_Blocks::rows( $mso, $t );
ok( 'Outlook conditionals are passed over', in_array( 'text:After the card.', flat( $rows ), true ), true );
ok( 'and the card is still a card', str_contains( (string) json_encode( $rows ), '1-column' ), true );

// The shape the shop actually SAW in an inbox: the mso delimiters mangled
// into literal text between the cards by an earlier kses wash. They must
// vanish, and the cards must come back together side by side.
$mangled = '<p>Intro.</p><table><tr><td>'
	. '&lt;!--[if mso]&gt;' . $card( 1 ) . '&lt;![endif]--&gt;&lt;!--[if mso]&gt;' . $card( 2 ) . '&lt;![endif]--&gt;'
	. '</td></tr></table>';
$rows = DZE_Klaviyo_Blocks::rows( $mangled, $t );
$printed = implode( ' ', flat( $rows ) );
ok( 'mangled mso remains print nowhere', false === strpos( $printed, '[if mso]' ), true );
ok( 'and the cards come back together',  str_contains( (string) json_encode( $rows ), '2-columns' ), true );
ok( 'in one row, not two',
	count( array_filter( $rows, static fn( $r ) => str_contains( (string) json_encode( $r ), 'columns-equal' ) ) ), 1 );

ok( 'an empty body is no rows at all', DZE_Klaviyo_Blocks::rows( '  ', $t ), [] );
$rows = DZE_Klaviyo_Blocks::rows( 'Bare words with no tag at all', $t );
ok( 'bare text is still an email', types( $rows ), [ 'text' => 1 ] );

printf( "\n%d checks, %d wrong\n", $ran, $fails );
exit( $fails ? 1 : 0 );
