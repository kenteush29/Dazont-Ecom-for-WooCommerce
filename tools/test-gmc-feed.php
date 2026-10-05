<?php
/**
 * Merchant Center products: the rules of the listing, without a shop.
 *
 * Run before every release:  php tools/test-gmc-feed.php dazont-ecom
 *
 * « ID de groupe, ID du produit parent oui c'est le plus logique. Pas de
 * marque. » (05/10/2026). shape() is pure: it gets what the shop holds for one
 * offer and returns the row Google would be sent, or why the offer is left
 * out. This drives it through the real class.
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );

function add_action() {} function add_filter() {} function do_action() {}
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) ); }
function strip_shortcodes( $s ) { return (string) preg_replace( '/\[\/?[a-z_]+[^\]]*\]/i', '', (string) $s ); }
function sanitize_title( $s ) { return trim( (string) preg_replace( '/[^a-z0-9_]+/', '-', strtolower( (string) $s ) ), '-' ); }
function remove_accents( $s ) { return strtr( (string) $s, [ 'ę' => 'e', 'ł' => 'l', 'ó' => 'o', 'é' => 'e', 'è' => 'e', 'ñ' => 'n', 'ü' => 'u', 'ä' => 'a', 'ö' => 'o', 'ś' => 's', 'ż' => 'z', 'ą' => 'a', 'É' => 'E', 'Ł' => 'L' ] ); }
function wc_get_dimension( $v, $to, $from = '' ) { $in = [ 'mm' => 0.1, 'cm' => 1, 'm' => 100 ]; return (float) $v * ( $in[ $from ] ?? 1 ) / ( $in[ $to ] ?? 1 ); }

require "$dir/includes/class-gmc-feed.php";

$wrong = 0;
$same  = static function ( string $what, $got, $want ) use ( &$wrong ): void {
	$ok = $got === $want;
	if ( ! $ok ) {
		$wrong++;
	}
	printf( "  %s %s%s\n", $ok ? 'ok   ' : 'WRONG', $what, $ok ? '' : ' — got ' . var_export( $got, true ) . ', want ' . var_export( $want, true ) );
};

$now  = 1790000000;
$base = [
	'id'                 => 987595930,
	'type'               => 'variation',
	'parent'             => 987595929,
	'name'               => ' Body Armor Replica  Fort Defender 2 - Black',
	'description'        => '',
	'parent_description' => "<ul>\n <li>Faithful replica</li>\n <li>Universal size</li></ul><!-- wp:x -->[products ids=\"1\"]",
	'link'               => 'https://kula-tactical.com/defender-2-body-armor?attribute_pa_color=black',
	'image_id'           => 11,
	'image'              => 'https://kula-tactical.com/wp-content/uploads/Defender-2.jpg',
	'gallery'            => [ 12 => 'https://kula-tactical.com/wp-content/uploads/Olive.jpg', 11 => 'https://kula-tactical.com/wp-content/uploads/Defender-2.jpg', 13 => 'https://kula-tactical.com/wp-content/uploads/Black.jpg' ],
	'in_stock'           => true,
	'regular'            => '1092.9',
	'sale'               => '',
	'sale_from'          => 0,
	'sale_to'            => 0,
	'currency'           => 'USD',
	'paths'              => [
		[ 'names' => [ 'Military Gear', 'Tactical vests' ], 'terms' => [ 1, 2 ] ],
		[ 'names' => [ 'Military Gear', 'Tactical vests', 'Body Armors' ], 'terms' => [ 1, 2, 3 ] ],
	],
	'attributes'         => [ 'color' => [ 'Black' ], 'material' => [ 'foam', 'Nylon', 'polyester', 'steel' ], 'gender' => [ 'Unisex' ] ],
	'dimensions'         => [ 'length' => '300', 'width' => '25', 'height' => '' ],
	'dimension_unit'     => 'mm',
	'now'                => $now,
];
$map = [ 1 => '7460', 3 => '' ];

echo "A variation\n";
$row = DZE_Gmc_Feed::shape( $base, $map );
$same( 'its id is its own', $row['id'] ?? null, '987595930' );
$same( 'its group is its parent product', $row['item_group_id'] ?? null, '987595929' );
$same( 'the title loses its stray spaces', $row['title'] ?? null, 'Body Armor Replica Fort Defender 2 - Black' );
$same( 'no description of its own: its product\'s, markup kept, shortcode and comment gone', $row['description'] ?? null, '<ul> <li>Faithful replica</li> <li>Universal size</li></ul>' );
$same( 'more pictures: the gallery without the main one', $row['additional_image_link'] ?? null, 'https://kula-tactical.com/wp-content/uploads/Olive.jpg,https://kula-tactical.com/wp-content/uploads/Black.jpg' );
$same( 'the price, no thousands separator', $row['price'] ?? null, '1092.90 USD' );
$same( 'no sale', isset( $row['sale_price'] ), false );
$same( 'the longest category path', $row['product_type'] ?? null, 'Military Gear > Tactical vests > Body Armors' );
$same( 'the Google category of the nearest mapped parent', $row['google_product_category'] ?? null, '7460' );
$same( 'no brand at all', isset( $row['brand'] ), false );
$same( 'identifier_exists no', $row['identifier_exists'] ?? null, 'no' );
$same( 'age group adult when the product says nothing', $row['age_group'] ?? null, 'adult' );
$same( 'materials: three at most, joined by /', $row['material'] ?? null, 'foam/Nylon/polyester' );
$same( 'gender in Google\'s word', $row['gender'] ?? null, 'unisex' );
$same( 'length in centimetres', $row['shipping_length'] ?? null, '30 cm' );
$same( 'width in centimetres', $row['shipping_width'] ?? null, '2.5 cm' );
$same( 'no height: no column', isset( $row['shipping_height'] ), false );
$same( 'no size: no column', isset( $row['size'] ), false );

echo "A simple product\n";
$simple = array_merge( $base, [ 'type' => 'simple', 'parent' => 0, 'description' => '<p>Own words</p>', 'attributes' => [ 'color' => [ 'EMR Camo', 'Gray', 'Black', 'Olive' ], 'size' => [ 'XL' ], 'gender' => [ 'Homme', 'Femme' ], 'age' => [ 'Erwachsene' ] ] ] );
$row    = DZE_Gmc_Feed::shape( $simple, $map );
$same( 'no group', isset( $row['item_group_id'] ), false );
$same( 'its own description', $row['description'] ?? null, '<p>Own words</p>' );
$same( 'colours: three at most, joined by /', $row['color'] ?? null, 'EMR Camo/Gray/Black' );
$same( 'its size', $row['size'] ?? null, 'XL' );
$same( 'men and women: unisex', $row['gender'] ?? null, 'unisex' );
$same( 'a German age: adult', $row['age_group'] ?? null, 'adult' );

echo "Quarantine\n";
$row = DZE_Gmc_Feed::shape( array_merge( $base, [ 'held' => true ] ), $map );
$same( 'a product in quarantine leaves the ads, and stays in the listing', $row['excluded_destination'] ?? null, 'Shopping_ads,Display_ads' );
$same( 'and nothing else changes', $row['price'] ?? null, '1092.90 USD' );
$row = DZE_Gmc_Feed::shape( $base, $map );
$same( 'a product out of quarantine is in the ads', isset( $row['excluded_destination'] ), false );

echo "Left out\n";
$same( 'a variable product is not an offer', DZE_Gmc_Feed::shape( array_merge( $base, [ 'type' => 'variable' ] ), $map ), 'not-an-offer' );
$same( 'no price', DZE_Gmc_Feed::shape( array_merge( $base, [ 'regular' => '' ] ), $map ), 'no-price' );
$same( 'a zero price', DZE_Gmc_Feed::shape( array_merge( $base, [ 'regular' => '0' ] ), $map ), 'no-price' );
$same( 'no picture', DZE_Gmc_Feed::shape( array_merge( $base, [ 'image' => '' ] ), $map ), 'no-image' );

echo "Sales\n";
$row = DZE_Gmc_Feed::shape( array_merge( $base, [ 'sale' => '983.61' ] ), $map );
$same( 'a running sale without dates', [ $row['sale_price'] ?? null, isset( $row['sale_price_effective_date'] ) ], [ '983.61 USD', false ] );
$row = DZE_Gmc_Feed::shape( array_merge( $base, [ 'sale' => '983.61', 'sale_from' => $now - 86400, 'sale_to' => $now + 86400 ] ), $map );
$same( 'a running sale with its end', $row['sale_price_effective_date'] ?? null, gmdate( 'Y-m-d\TH:i:s\Z', $now - 86400 ) . '/' . gmdate( 'Y-m-d\TH:i:s\Z', $now + 86400 ) );
$row = DZE_Gmc_Feed::shape( array_merge( $base, [ 'sale' => '983.61', 'sale_from' => $now + 3600 ] ), $map );
$same( 'a sale that has not started is not sent', isset( $row['sale_price'] ), false );
$row = DZE_Gmc_Feed::shape( array_merge( $base, [ 'sale' => '983.61', 'sale_to' => $now - 1 ] ), $map );
$same( 'a sale that has ended is not sent', isset( $row['sale_price'] ), false );
$row = DZE_Gmc_Feed::shape( array_merge( $base, [ 'sale' => '1092.90' ] ), $map );
$same( 'a sale price that is not lower is not sent', isset( $row['sale_price'] ), false );
$row = DZE_Gmc_Feed::shape( array_merge( $base, [ 'sale' => '983.61', 'on_sale' => false ] ), $map );
$same( 'WooCommerce says the sale does not run: not sent', isset( $row['sale_price'] ), false );
$row = DZE_Gmc_Feed::shape( array_merge( $base, [ 'regular' => '41.9', 'sale' => '36.9', 'on_sale' => true ] ), $map );
$same( 'an automatic discount, as the page shows it', $row['sale_price'] ?? null, '36.90 USD' );

echo "Out of stock, many pictures\n";
$gallery = [];
for ( $i = 100; $i < 115; $i++ ) {
	$gallery[ $i ] = "https://x/$i.jpg";
}
$row = DZE_Gmc_Feed::shape( array_merge( $base, [ 'in_stock' => false, 'gallery' => $gallery ] ), $map );
$same( 'out of stock', $row['availability'] ?? null, 'out_of_stock' );
$same( 'ten more pictures at most', count( explode( ',', $row['additional_image_link'] ?? '' ) ), 10 );

echo "Categories\n";
$same( 'a tie keeps the first path', DZE_Gmc_Feed::categories( [ [ 'names' => [ 'A', 'B' ], 'terms' => [ 1, 2 ] ], [ 'names' => [ 'C', 'D' ], 'terms' => [ 3, 4 ] ] ], [] )[0], 'A > B' );
$same( 'the deepest mapped path wins', DZE_Gmc_Feed::categories( [ [ 'names' => [ 'Gifts' ], 'terms' => [ 9 ] ], [ 'names' => [ 'A', 'B', 'C' ], 'terms' => [ 1, 2, 3 ] ] ], [ 9 => '5000', 2 => '212' ] )[1], '212' );
$same( 'a shallower path when the deep one has nothing', DZE_Gmc_Feed::categories( [ [ 'names' => [ 'Gifts' ], 'terms' => [ 9 ] ], [ 'names' => [ 'A', 'B', 'C' ], 'terms' => [ 1, 2, 3 ] ] ], [ 9 => '5000' ] )[1], '5000' );
$same( 'nothing mapped: no Google category', DZE_Gmc_Feed::categories( [ [ 'names' => [ 'A' ], 'terms' => [ 1 ] ] ], [] )[1], '' );
$same( 'entities in a category name', DZE_Gmc_Feed::categories( [ [ 'names' => [ 'Hats &amp; Caps' ], 'terms' => [ 1 ] ] ], [] )[0], 'Hats & Caps' );

echo "Attributes and words\n";
$same( 'pa_color is the colour', DZE_Gmc_Feed::google_attribute( 'pa_color' ), 'color' );
$same( 'pa_materials is the material', DZE_Gmc_Feed::google_attribute( 'pa_materials' ), 'material' );
$same( 'pa_size is the size', DZE_Gmc_Feed::google_attribute( 'pa_size' ), 'size' );
$same( 'pa_gender is the gender', DZE_Gmc_Feed::google_attribute( 'pa_gender' ), 'gender' );
$same( 'pa_age is the age group', DZE_Gmc_Feed::google_attribute( 'pa_age' ), 'age' );
$same( 'pa_package is nothing', DZE_Gmc_Feed::google_attribute( 'pa_package' ), '' );
$same( 'Couleur is the colour', DZE_Gmc_Feed::google_attribute( 'Couleur' ), 'color' );
$same( 'Męski is male', DZE_Gmc_Feed::gender( [ 'Męski' ] ), 'male' );
$same( 'Herren is male', DZE_Gmc_Feed::gender( [ 'Herren' ] ), 'male' );
$same( 'Унисекс is unisex', DZE_Gmc_Feed::gender( [ 'Унисекс' ] ), 'unisex' );
$same( 'Damen is female', DZE_Gmc_Feed::gender( [ 'Damen' ] ), 'female' );
$same( 'an unknown word says nothing', DZE_Gmc_Feed::gender( [ 'Tactical' ] ), '' );
$same( 'Dorosły is adult', DZE_Gmc_Feed::word_of( [ 'Dorosły' ], [ 'adult' => [ 'dorosly' ] ] ), 'adult' );

echo "Figures and text\n";
$same( 'money', DZE_Gmc_Feed::money( 33.75, 'usd' ), '33.75 USD' );
$same( 'a price with a comma', DZE_Gmc_Feed::number( '12,5' ), 12.5 );
$same( 'a long title is cut between two words', DZE_Gmc_Feed::cut( str_repeat( 'abcd ', 40 ), 150 ), rtrim( str_repeat( 'abcd ', 30 ) ) );
$same( 'a non-breaking space is a space', DZE_Gmc_Feed::plain( "Gilet\u{00A0} tactique" ), 'Gilet tactique' );

echo "File\n";
$tsv   = DZE_Gmc_Feed::tsv( [ 1 => [ 'id' => '1', 'title' => "A\tB" ], 2 => 'no-price' ] );
$lines = explode( "\n", rtrim( $tsv, "\n" ) );
$same( 'a header and one line, the reason skipped', count( $lines ), 2 );
$same( 'a tab inside a value never splits a column', count( explode( "\t", $lines[1] ) ), count( DZE_Gmc_Feed::COLUMNS ) );

echo $wrong ? "\n$wrong wrong\n" : "\nall right\n";
