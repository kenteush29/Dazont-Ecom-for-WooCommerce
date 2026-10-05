<?php
/**
 * Compares the listing Dazont builds with the file Merchant Center reads today.
 *
 * « Tu compareras notre flux actuel avec le flux dazont avant toute
 * approbation » (05/10/2026). The file is a WP All Export Google feed (tab
 * separated). For every offer in both, each field is put in one of four boxes:
 *   - same;
 *   - shop: the shop changed since the file was written, and Dazont gives
 *     what WP All Export would print today;
 *   - rule: Dazont's rule gives something else from the same data;
 *   - both: the shop changed AND the rule differs.
 * For the fields where WP All Export's own rule is not reproduced here (the
 * attributes, the group id), a difference is counted under « rule ».
 *
 * ABSOLUTELY READ-ONLY on the shop: SELECTs and get_option() only. It writes
 * three files into the folder it is given, and sends nothing anywhere.
 *
 *   php tools/on-site/gmc-feed-compare.php <lang> <feed file> <out dir> <export id>
 *
 * <export id> is the WP All Export export that writes that file: its Google
 * template and category mapping are read to know what it would print today.
 * The Google categories of the Dazont listing come from the export of the
 * shop's default language, as they would be imported.
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( "CLI only.\n" );
}
// Kept apart and read again once WordPress is loaded: its files run in this
// same global scope, and one of them leaves its own $file behind.
$dze_args = array_pad( $argv, 5, '' );
if ( '' === $dze_args[1] || ! is_file( $dze_args[2] ) || '' === $dze_args[3] || ! ctype_digit( (string) $dze_args[4] ) ) {
	fwrite( STDERR, "Usage: php gmc-feed-compare.php <lang> <feed file> <out dir> <export id>\n" );
	exit( 1 );
}

if ( ! function_exists( 'get_option' ) ) {
	$found = '';
	foreach ( [ getcwd(), __DIR__ ] as $start ) {
		for ( $dir = $start, $i = 0; $i < 8 && $dir && '' === $found; $i++, $dir = dirname( $dir ) === $dir ? '' : dirname( $dir ) ) {
			if ( is_file( $dir . '/wp-load.php' ) ) {
				$found = $dir . '/wp-load.php';
			}
		}
	}
	if ( '' === $found ) {
		fwrite( STDERR, "Could not find wp-load.php: run it from the WordPress folder.\n" );
		exit( 1 );
	}
	require $found;
}
[ , $lang, $file, $out_dir, $export ] = $dze_args;
// The module as it stands in this checkout, when the installed plugin does not have it yet.
if ( ! class_exists( 'DZE_Gmc_Feed' ) ) {
	require __DIR__ . '/../../dazont-ecom/includes/class-gmc-feed.php';
}
if ( ! is_dir( $out_dir ) && ! wp_mkdir_p( $out_dir ) ) {
	fwrite( STDERR, "Cannot write into $out_dir\n" );
	exit( 1 );
}

global $wpdb;
$started = microtime( true );

// --- WP All Export: the template behind the file, and the default language's mapping.
$template = static function ( int $id ) use ( $wpdb ): array {
	$o = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT options FROM {$wpdb->prefix}pmxe_exports WHERE id = %d", $id ) ) );
	return is_array( $o ) ? $o : [];
};
$mapping = static function ( array $o ): array {
	$out = [];
	foreach ( (array) ( $o['google_merchants_post_data']['productCategories']['catMappings'] ?? [] ) as $tid => $m ) {
		if ( is_array( $m ) && '' !== (string) ( $m['id'] ?? '' ) ) {
			$out[ (int) $tid ] = (string) $m['id'];
		}
	}
	return $out;
};
$tpl      = $template( (int) $export );
$gm       = (array) ( $tpl['google_merchants_post_data'] ?? [] );
$tpl_map  = $mapping( $tpl );
$default  = DZE_Wpml::default_language();
$main_map = [];
foreach ( (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}pmxe_exports" ) as $eid ) {
	$o = $template( (int) $eid );
	if ( 'XmlGoogleMerchants' === ( $o['xml_template_type'] ?? '' ) && $default === ( $o['wpml_lang'] ?? '' ) ) {
		$main_map = $mapping( $o );
		break;
	}
}
$gallery_all = 'customValue' === ( $gm['basicInformation']['additionalImageLink'] ?? '' );

// --- The file Merchant Center reads.
$written = (int) filemtime( $file );
$old     = [];
$broken  = 0;
$h       = fopen( $file, 'r' );
$head    = fgetcsv( $h, 0, "\t", '"', '' );
$head[0] = (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $head[0] );
while ( false !== ( $r = fgetcsv( $h, 0, "\t", '"', '' ) ) ) {
	if ( count( $r ) !== count( $head ) ) {
		$broken++;
		continue;
	}
	$x = array_combine( $head, $r );
	$old[ (int) $x['id'] ] = $x;
}
fclose( $h );

// --- The Dazont listing, page by page.
$new     = [];
$reasons = [];
$today   = [];
$after   = 0;
$was     = DZE_Wpml::current_language();
do_action( 'wpml_switch_language', $lang );
// WP All Export's attribute reading: the variation's own value, else its
// product's list (wp-content/uploads/wpallexport/functions.php).
$parent_attr = static function ( WC_Product $p, string $a ): string {
	$read = static function ( WC_Product $x ) use ( $a ): string {
		$v = (string) $x->get_attribute( wc_attribute_taxonomy_name( $a ) );
		return '' !== $v ? $v : (string) $x->get_attribute( $a );
	};
	$v = $read( $p );
	if ( '' === $v && $p->is_type( 'variation' ) ) {
		$parent = wc_get_product( $p->get_parent_id() );
		$v      = $parent ? $read( $parent ) : '';
	}
	return $v;
};
// One template value: a [my_get_parent_attr(...)] call, a {Product X} snippet, or a literal.
$template_value = static function ( string $expr, WC_Product $p ) use ( $parent_attr ): string {
	$expr = trim( $expr );
	if ( preg_match( '/^\[my_get_parent_attr\(\{ID\},\s*"([^"]+)"\)\]$/', $expr, $m ) ) {
		return $parent_attr( $p, $m[1] );
	}
	if ( preg_match( '/^\{Product (\w+)\}$/', $expr, $m ) ) {
		return $parent_attr( $p, 'pa_' . strtolower( $m[1] ) );
	}
	return ( false === strpos( $expr, '[' ) && false === strpos( $expr, '{' ) ) ? $expr : '';
};
$detail = (array) ( $gm['detailedInformation'] ?? [] );
$attribute_today = static function ( string $field, WC_Product $p ) use ( $detail, $template_value ): string {
	$mode = (string) ( $detail[ $field ] ?? '' );
	if ( 'customValue' === $mode ) {
		$v = $template_value( (string) ( $detail[ $field . 'CV' ] ?? '' ), $p );
	} elseif ( 'selectFromWooCommerceProductAttributes' === $mode ) {
		$v = $template_value( (string) ( $detail[ $field . 'Attribute' ] ?? '' ), $p );
		if ( 'color' === $field ) {
			$v = implode( '/', array_slice( explode( ',', $v ), 0, 3 ) );
		}
	} else {
		$v = '';
	}
	return $v;
};
$dimension_today = static function ( string $value ): string {
	if ( ! is_numeric( $value ) ) {
		return '';
	}
	$cm = wc_get_dimension( (float) $value, 'cm', (string) get_option( 'woocommerce_dimension_unit' ) );
	return $cm ? $cm . ' cm' : '';
};
$wpae_today = static function ( WC_Product $p ) use ( $tpl_map, $gallery_all, $attribute_today, $dimension_today ): array {
	$parent = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : null;
	$main   = $parent ? $parent : $p;
	$ids    = (array) $main->get_gallery_image_ids();
	$urls   = array_values( array_filter( array_map( static fn( $g ) => (string) wp_get_attachment_url( (int) $g ), $ids ) ) );
	// WP All Export's Google category: the first category with no children, its own mapping only.
	$google = '';
	$terms  = get_the_terms( $main->get_id(), 'product_cat' );
	if ( is_array( $terms ) && $terms ) {
		$pick = $terms[0];
		foreach ( $terms as $t ) {
			if ( ! get_categories( [ 'taxonomy' => 'product_cat', 'parent' => $t->term_id ] ) ) {
				$pick = $t;
				break;
			}
		}
		$google = (string) ( $tpl_map[ (int) $pick->term_id ] ?? '' );
	}
	$image = (int) $p->get_image_id();
	return [
		'title'                   => (string) get_post_field( 'post_title', $p->get_id(), 'raw' ),
		'description'             => (string) get_post_field( 'post_content', $main->get_id(), 'raw' ),
		'link'                    => (string) $p->get_permalink(),
		'image_link'              => $image ? (string) wp_get_attachment_url( $image ) : '',
		'additional_image_link'   => $gallery_all ? implode( ',', $urls ) : (string) ( $urls[0] ?? '' ),
		'availability'            => $p->is_in_stock() ? 'in_stock' : 'out_of_stock',
		'price'                   => (string) $p->get_regular_price(),
		'sale_price'              => (string) $p->get_sale_price(),
		'google_product_category' => $google,
		'color'                   => $attribute_today( 'color', $p ),
		'size'                    => $attribute_today( 'size', $p ),
		'material'                => $attribute_today( 'material', $p ),
		'gender'                  => $attribute_today( 'gender', $p ),
		'age_group'               => $attribute_today( 'ageGroup', $p ),
		'shipping_length'         => $dimension_today( (string) $p->get_length() ),
		'shipping_width'          => $dimension_today( (string) $p->get_width() ),
		'shipping_height'         => $dimension_today( (string) $p->get_height() ),
		'item_group_id'           => $parent ? substr( md5( $parent->get_id() . get_post_field( 'post_title', $parent->get_id(), 'raw' ) ), 0, 16 ) : substr( md5( $p->get_id() . get_post_field( 'post_title', $p->get_id(), 'raw' ) ), 0, 16 ),
	];
};
$flush = static function (): void {
	if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
		wp_cache_flush_runtime();
	} elseif ( ! wp_using_ext_object_cache() ) {
		wp_cache_flush();
	}
};
do {
	$ids = DZE_Gmc_Feed::ids( $lang, $after, 200 );
	if ( ! $ids ) {
		break;
	}
	$after = (int) end( $ids );
	foreach ( DZE_Gmc_Feed::rows( $ids, $lang, $main_map ) as $id => $row ) {
		if ( is_array( $row ) ) {
			$new[ $id ] = $row;
		} else {
			$reasons[ $id ] = $row;
		}
	}
	do_action( 'wpml_switch_language', $lang );
	foreach ( $ids as $id ) {
		if ( isset( $old[ $id ] ) && ( $p = wc_get_product( $id ) ) ) {
			$today[ $id ] = $wpae_today( $p );
		}
	}
	$flush();
} while ( count( $ids ) === 200 );
// Offers of the file that Dazont never read: what WP All Export would print today, when they still exist.
foreach ( array_diff_key( $old, $new, $reasons ) as $id => $unused ) {
	$p = wc_get_product( $id );
	if ( $p && ! $p->is_type( 'variable' ) ) {
		$today[ $id ] = $wpae_today( $p );
	}
}
do_action( 'wpml_switch_language', '' !== $was ? $was : null );

// --- Why an offer is on one side only.
$why_old = static function ( int $id ) use ( $wpdb, $reasons, $lang ): string {
	if ( isset( $reasons[ $id ] ) ) {
		return 'dazont leaves it out: ' . $reasons[ $id ];
	}
	$post = get_post( $id );
	if ( ! $post ) {
		return 'deleted since';
	}
	if ( 'publish' !== $post->post_status ) {
		return 'not published (' . $post->post_status . ')';
	}
	if ( $post->post_parent && 'publish' !== get_post_status( $post->post_parent ) ) {
		return 'its product is not published';
	}
	if ( 'yes' !== get_post_meta( $id, '_merchant_center_activation', true ) ) {
		return 'no longer selected for Merchant Center';
	}
	$p = wc_get_product( $id );
	if ( $p && in_array( $p->get_type(), [ 'variable', 'grouped', 'external' ], true ) ) {
		return $p->get_type() . ' product (its variations are the offers)';
	}
	$l = (string) apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $id, 'element_type' => 'post_' . $post->post_type ] );
	return $l !== $lang ? 'in another language (' . $l . ')' : 'other';
};
$why_new = static function ( int $id ) use ( $written ): string {
	$post = get_post( $id );
	if ( $post && strtotime( $post->post_date_gmt . ' UTC' ) > $written ) {
		return 'created since';
	}
	if ( $post && strtotime( $post->post_modified_gmt . ' UTC' ) > $written ) {
		return 'changed since (published or selected)';
	}
	return 'missing from the file';
};

// --- Field by field.
$norm = [
	'text'   => static fn( string $v ): string => DZE_Gmc_Feed::plain( $v ),
	'money'  => static function ( string $v ): string {
		$n = (float) str_replace( ',', '', (string) preg_replace( '/[^0-9.,]/', '', $v ) );
		return $n > 0 ? number_format( $n, 2, '.', '' ) : '';
	},
	'image'  => static fn( string $v ): string => (string) wp_parse_url( trim( $v ), PHP_URL_PATH ),
	'images' => static function ( string $v ): string {
		$p = array_filter( array_map( static fn( $u ) => (string) wp_parse_url( trim( $u ), PHP_URL_PATH ), explode( ',', $v ) ) );
		$p = array_values( array_unique( $p ) );
		sort( $p );
		return implode( ',', $p );
	},
	'lower'  => static fn( string $v ): string => strtolower( trim( $v ) ),
	'raw'    => static fn( string $v ): string => trim( $v ),
];
$fields = [
	'title' => 'text', 'description' => 'text', 'link' => 'raw', 'image_link' => 'image',
	'additional_image_link' => 'images', 'availability' => 'raw', 'price' => 'money', 'sale_price' => 'money',
	'sale_price_effective_date' => 'raw', 'product_type' => 'text', 'google_product_category' => 'raw',
	'brand' => 'raw', 'identifier_exists' => 'lower', 'condition' => 'lower', 'is_bundle' => 'lower',
	'age_group' => 'lower', 'color' => 'text', 'gender' => 'lower', 'material' => 'text', 'pattern' => 'text',
	'size' => 'text', 'item_group_id' => 'raw', 'shipping_length' => 'raw', 'shipping_width' => 'raw',
	'shipping_height' => 'raw', 'size_type' => 'raw', 'size_system' => 'raw', 'adult' => 'lower',
	'cost_of_goods_sold' => 'raw', 'custom_label_0' => 'raw', 'custom_label_1' => 'raw', 'custom_label_2' => 'raw',
	'custom_label_3' => 'raw', 'custom_label_4' => 'raw',
];
// A list of values written another way — « foam, Nylon » and « foam/Nylon » —
// is the same answer to Google, which reads three values at most.
$as_list = static function ( string $v ): string {
	$parts = array_map( static fn( $x ) => mb_strtolower( DZE_Gmc_Feed::plain( (string) $x ) ), (array) preg_split( '#[,/]#', $v ) );
	$parts = array_values( array_unique( array_filter( $parts, 'strlen' ) ) );
	return implode( '/', array_slice( $parts, 0, 3 ) );
};
$listy = [ 'color' => true, 'material' => true, 'size' => true ];
$stats = [];
$both  = array_intersect_key( $old, $new );
foreach ( $fields as $field => $kind ) {
	$n = $norm[ $kind ];
	$s = [ 'same' => 0, 'format' => 0, 'shop' => 0, 'rule' => 0, 'both' => 0, 'rule_kinds' => [], 'examples' => [ 'format' => [], 'shop' => [], 'rule' => [], 'both' => [] ] ];
	foreach ( $both as $id => $o ) {
		$a = $n( (string) ( $o[ $field ] ?? '' ) );
		$b = $n( (string) ( $new[ $id ][ $field ] ?? '' ) );
		if ( $a === $b ) {
			$s['same']++;
			continue;
		}
		$cmp = $n;
		if ( isset( $listy[ $field ] ) ) {
			$cmp = $as_list;
			$a   = $as_list( (string) ( $o[ $field ] ?? '' ) );
			$b   = $as_list( (string) ( $new[ $id ][ $field ] ?? '' ) );
		}
		if ( $a === $b ) {
			$box = 'format';
		} else {
			$box = 'rule';
			if ( isset( $today[ $id ] ) && array_key_exists( $field, $today[ $id ] ) ) {
				$t   = $cmp( (string) $today[ $id ][ $field ] );
				$box = $t === $a ? 'rule' : ( $t === $b ? 'shop' : 'both' );
			}
		}
		$s[ $box ]++;
		if ( 'rule' === $box ) {
			$k                     = '' === $a ? 'filled' : ( '' === $b ? 'emptied' : 'changed' );
			$s['rule_kinds'][ $k ] = ( $s['rule_kinds'][ $k ] ?? 0 ) + 1;
		}
		if ( count( $s['examples'][ $box ] ) < 8 ) {
			$s['examples'][ $box ][] = [ 'id' => $id, 'file' => mb_substr( (string) ( $o[ $field ] ?? '' ), 0, 300 ), 'dazont' => mb_substr( (string) ( $new[ $id ][ $field ] ?? '' ), 0, 300 ) ];
		}
	}
	$stats[ $field ] = $s;
}
$only_old = [];
foreach ( array_diff_key( $old, $new ) as $id => $o ) {
	$w = $why_old( (int) $id );
	$only_old[ $w ][] = (int) $id;
}
$only_new = [];
foreach ( array_diff_key( $new, $old ) as $id => $row ) {
	$w = $why_new( (int) $id );
	$only_new[ $w ][] = (int) $id;
}
// A selection of what the file still says and the shop no longer does: prices first.
$stale = [ 'price' => 0, 'sale_price' => 0, 'availability' => 0 ];
foreach ( $both as $id => $o ) {
	foreach ( $stale as $f => $c ) {
		if ( isset( $today[ $id ] ) ) {
			$k = 'availability' === $f ? 'raw' : 'money';
			if ( $norm[ $k ]( (string) ( $o[ $f ] ?? '' ) ) !== $norm[ $k ]( (string) $today[ $id ][ $f ] ) ) {
				$stale[ $f ]++;
			}
		}
	}
}
$report = [
	'language'      => $lang,
	'file'          => basename( $file ),
	'file_written'  => gmdate( 'Y-m-d H:i', $written ) . ' UTC',
	'file_offers'   => count( $old ),
	'file_broken'   => $broken,
	'dazont_offers' => count( $new ),
	'dazont_left'   => array_count_values( $reasons ),
	'in_both'       => count( $both ),
	'only_file'     => array_map( static fn( $ids ) => [ 'count' => count( $ids ), 'ids' => array_slice( $ids, 0, 12 ) ], $only_old ),
	'only_dazont'   => array_map( static fn( $ids ) => [ 'count' => count( $ids ), 'ids' => array_slice( $ids, 0, 12 ) ], $only_new ),
	'stale_in_file' => $stale,
	'fields'        => $stats,
	'seconds'       => round( microtime( true ) - $started ),
	'memory_mb'     => round( memory_get_peak_usage() / 1048576 ),
];
file_put_contents( rtrim( $out_dir, '/' ) . "/compare-$lang.json", wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
file_put_contents( rtrim( $out_dir, '/' ) . "/dazont-$lang.tsv", DZE_Gmc_Feed::tsv( $new ) );

// --- What a person reads.
printf( "%s — file %s written %s: %d offers (%d unreadable lines). Dazont: %d offers. In both: %d. %ds, %d MB.\n",
	strtoupper( $lang ), basename( $file ), $report['file_written'], count( $old ), $broken, count( $new ), count( $both ), $report['seconds'], $report['memory_mb'] );
printf( "  Stale in the file (the shop says otherwise today): price %d, sale price %d, availability %d\n", $stale['price'], $stale['sale_price'], $stale['availability'] );
foreach ( $report['dazont_left'] as $w => $c ) {
	echo "  Dazont leaves out: $w × $c\n";
}
foreach ( $report['only_file'] as $w => $x ) {
	echo "  Only in the file: $w × {$x['count']}\n";
}
foreach ( $report['only_dazont'] as $w => $x ) {
	echo "  Only in Dazont: $w × {$x['count']}\n";
}
foreach ( $stats as $field => $s ) {
	if ( $s['format'] + $s['shop'] + $s['rule'] + $s['both'] === 0 ) {
		continue;
	}
	$kinds = '';
	foreach ( $s['rule_kinds'] as $k => $c ) {
		$kinds .= " $k $c";
	}
	printf( "  %-26s same %5d | format %5d | shop %5d | rule %5d%s | both %5d\n", $field, $s['same'], $s['format'], $s['shop'], $s['rule'], '' !== $kinds ? ' (' . trim( $kinds ) . ')' : '', $s['both'] );
}
