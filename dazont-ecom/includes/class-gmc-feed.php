<?php
defined( 'ABSPATH' ) || exit;

/**
 * Merchant Center products: the listing Google reads, built by the shop itself.
 *
 * « Arrivé là un module api merchant center serait presque mieux. Plus direct,
 * plus efficace, plus léger. » Kula's five Merchant Center accounts read files
 * that WP All Export wrote. The files stopped being written in August, when the
 * scheduling subscription lapsed, so Google kept reading August's prices.
 * This module builds the same listing from the shop: one language per account,
 * to be sent through the Merchant API.
 *
 * BUILDS, NEVER SENDS — « On envoie rien au merchant center ». The listing is
 * compared with the file Google reads today (tools/on-site/gmc-feed-compare.php)
 * before anything is decided.
 *
 * ONE SET OF RULES FOR EVERY LANGUAGE. WP All Export had five templates that had
 * drifted apart:
 * - the German, Polish and Spanish ones sent one extra picture, the others all;
 * - the French one read the age from an attribute no product carries;
 * - the English one alone gave a size system.
 *
 * The rules:
 * - An offer is a published simple product, or an enabled variation of a
 *   published product, carrying the activation flag set by the GMC product
 *   activation module. A variable product is never an offer: its variations
 *   are.
 * - Its id is the product's own id in that language. That is the id Google
 *   already knows, so nothing Google has learnt about the offer is lost.
 * - item_group_id is the PARENT product's id for a variation, and nothing for
 *   a simple product. WP All Export made a hash of the title, for simple
 *   products too: « l'ID du produit parent, c'est le plus logique ».
 * - No brand, and identifier_exists = no.
 * - product_type keeps WP All Export's choice: the longest category path, ties
 *   to the first name. A Google Ads campaign may be divided by it, and a
 *   changed path would drop those offers into « everything else ».
 * - google_product_category comes from the product categories (term meta
 *   META_CATEGORY on the original-language category). A category without its
 *   own takes its nearest parent's. WP All Export read the first category with
 *   no children and nothing above it, which left 29 % of the English offers
 *   without one.
 * - Colour and material: the variation's own value, otherwise the product's,
 *   three at most, joined by « / » as Google asks. Gender and age group are
 *   translated into Google's own words; the age group is « adult » when the
 *   product does not say.
 * - A sale is sent only while it runs, with its end date when it has one.
 * - A product or category in quarantine (DZE_Ads) keeps its offers in the
 *   listing, marked « excluded_destination » for the ads.
 */
final class DZE_Gmc_Feed {

	/** Term meta on a product category: its Google product category id. */
	public const META_CATEGORY = '_dze_gmc_category';

	/** Offers read per page. */
	public const PAGE = 200;

	/** The columns of the listing, in the order a file prints them. */
	public const COLUMNS = [
		'id', 'title', 'description', 'link', 'image_link', 'additional_image_link',
		'availability', 'price', 'sale_price', 'sale_price_effective_date',
		'product_type', 'google_product_category', 'identifier_exists', 'condition',
		'age_group', 'color', 'gender', 'material', 'size', 'item_group_id',
		'shipping_length', 'shipping_width', 'shipping_height', 'excluded_destination',
	];

	/**
	 * Where an offer in quarantine (DZE_Ads) is taken out: Shopping ads, which
	 * Performance Max uses too, and display remarketing. The free listings stay.
	 */
	public const HELD = [ 'Shopping_ads', 'Display_ads' ];

	// Google's own ceilings.
	private const TITLE_MAX   = 150;
	private const DESC_MAX    = 5000;
	private const MORE_IMAGES = 10;
	private const MANY_VALUES = 3;

	/** Which of the shop's attributes answers each Google attribute, read from its name. */
	private const ATTRIBUTES = [
		'color'    => '/colou?r|couleur|farbe|kolor/',
		'size'     => '/size|taille|groesse|rozmiar/',
		'material' => '/material|matiere|stoff/',
		'gender'   => '/gender|genre|geschlecht|plec/',
		'age'      => '/(^|[-_])age([-_]|$)|age-?group/',
	];

	/** Google's words for gender and age group, from the words shops use. */
	private const GENDERS = [
		'male'   => [ 'male', 'men', 'man', 'mens', 'homme', 'hommes', 'herren', 'herr', 'mann', 'manner', 'hombre', 'hombres', 'meski', 'mezczyzna', 'мужской', 'мужчины' ],
		'female' => [ 'female', 'women', 'woman', 'womens', 'ladies', 'femme', 'femmes', 'damen', 'frau', 'frauen', 'mujer', 'mujeres', 'damski', 'kobieta', 'женский', 'женщины' ],
		'unisex' => [ 'unisex', 'uniseks', 'unisexe', 'унисекс' ],
	];
	private const AGES = [
		'adult'   => [ 'adult', 'adults', 'adulte', 'adultes', 'adulto', 'adultos', 'dorosly', 'dorosli', 'erwachsene', 'erwachsener', 'взрослый', 'взрослые' ],
		'kids'    => [ 'kids', 'kid', 'child', 'children', 'enfant', 'enfants', 'kinder', 'kind', 'nino', 'ninos', 'dzieci', 'dziecko', 'детский', 'дети' ],
		'toddler' => [ 'toddler', 'toddlers' ],
		'infant'  => [ 'infant', 'infants', 'baby', 'bebe' ],
		'newborn' => [ 'newborn', 'newborns' ],
	];

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Nothing hooked: this version builds when it is asked and sends nothing.
	}

	// =========================================================================
	// Which offers
	// =========================================================================

	/**
	 * The languages that have a Merchant Center account.
	 *
	 * @return string[] Language codes, or 'default' on a shop without WPML.
	 */
	public static function languages(): array {
		$out = [];
		foreach ( DZE_Gmc::get_accounts() as $key => $acc ) {
			if ( '' !== (string) ( $acc['merchant_id'] ?? '' ) ) {
				$out[] = (string) ( $acc['language'] ?? $key );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * One page of the offers of one language, by id.
	 *
	 * Read from WPML's own table rather than through a filtered query: WPML
	 * narrows queries with front-end filters, and this runs in cron and AJAX.
	 *
	 * @return int[]
	 */
	public static function ids( string $lang, int $after = 0, int $limit = self::PAGE ): array {
		global $wpdb;
		[ $sql, $args ] = self::offers_sql( $lang, 'p.ID' );
		$args[] = $after;
		$args[] = max( 1, $limit );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- built by offers_sql(), prepared here.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql . ' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d', $args ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * How many offers one language has; with $originals, how many belong to
	 * those original products (their translations and variations included).
	 *
	 * @param int[] $originals
	 */
	public static function count( string $lang, array $originals = [] ): int {
		global $wpdb;
		[ $sql, $args ] = self::offers_sql( $lang, 'COUNT(*)' );
		if ( $originals ) {
			$family = self::family( $originals );
			if ( ! $family ) {
				return 0;
			}
			$in   = implode( ',', $family );
			$sql .= " AND ( ( p.post_type = 'product' AND p.ID IN ( {$in} ) ) OR ( p.post_type = 'product_variation' AND p.post_parent IN ( {$in} ) ) )";
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- built by offers_sql(), integers joined above.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * The query every reading of the offers shares, and its arguments.
	 *
	 * @return array{0:string,1:array}
	 */
	private static function offers_sql( string $lang, string $fields ): array {
		global $wpdb;
		$join = '';
		$args = [];
		$icl  = $wpdb->prefix . 'icl_translations';
		if ( DZE_Wpml::is_active() && 'default' !== $lang && DZE_Wpml::has_table( $icl ) ) {
			$join   = "INNER JOIN {$icl} t ON t.element_id = p.ID AND t.element_type = CONCAT( 'post_', p.post_type ) AND t.language_code = %s";
			$args[] = $lang;
		}
		$args[] = DZE_Gmc_Activation::META;
		$sql    = "SELECT {$fields} FROM {$wpdb->posts} p
			   {$join}
			   INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = 'yes'
			   LEFT JOIN {$wpdb->posts} pa ON pa.ID = p.post_parent
			  WHERE p.post_type IN ( 'product', 'product_variation' )
			    AND p.post_status = 'publish'
			    AND ( p.post_type = 'product' OR pa.post_status = 'publish' )
			    AND NOT EXISTS (
			        SELECT 1 FROM {$wpdb->term_relationships} tr
			          INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
			          INNER JOIN {$wpdb->terms} te ON te.term_id = tt.term_id
			         WHERE tr.object_id = p.ID AND te.slug IN ( 'variable', 'grouped', 'external', 'variable-subscription' ) )";
		return [ $sql, $args ];
	}

	/**
	 * Original products with all their translations, from WPML's own table.
	 *
	 * @param int[] $originals
	 * @return int[]
	 */
	private static function family( array $originals ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $originals ) ) );
		if ( ! $ids ) {
			return [];
		}
		$icl = $wpdb->prefix . 'icl_translations';
		if ( ! DZE_Wpml::is_active() || ! DZE_Wpml::has_table( $icl ) ) {
			return $ids;
		}
		$out = [];
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only; WPML's own table.
			$got = (array) $wpdb->get_col( "SELECT t2.element_id FROM {$icl} t1 INNER JOIN {$icl} t2 ON t2.trid = t1.trid AND t2.element_type = 'post_product' WHERE t1.element_type = 'post_product' AND t1.element_id IN ( {$in} )" );
			$out = array_merge( $out, $chunk, array_map( 'intval', $got ) );
		}
		return array_values( array_unique( $out ) );
	}
	// =========================================================================
	// What is sent for each offer
	// =========================================================================

	/**
	 * The listing rows for some offers of one language.
	 *
	 * WPML is switched to that language for the time of the reading: the names
	 * of categories and attribute values come through WordPress's term
	 * functions, which WPML narrows to the current language.
	 *
	 * @param int[]                  $ids
	 * @param array<int,string>|null $map Google category per original category; the shop's own when null.
	 * @return array<int,array<string,string>|string> id => row, or the reason the offer is left out.
	 */
	public static function rows( array $ids, string $lang, ?array $map = null ): array {
		$map    = $map ?? self::category_map();
		$was    = DZE_Wpml::current_language();
		$switch = DZE_Wpml::is_active() && 'default' !== $lang && $was !== $lang;
		if ( $switch ) {
			do_action( 'wpml_switch_language', $lang );
		}
		$out = [];
		try {
			$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
			_prime_post_caches( $ids, true, true );
			// Products and categories in quarantine leave the ads, never the listing.
			$held = DZE_Ads::held_products();
			$orig = $held ? DZE_Ads::originals( $ids ) : [];
			foreach ( $ids as $id ) {
				$p = wc_get_product( $id );
				if ( ! $p ) {
					$out[ $id ] = 'gone';
					continue;
				}
				$f         = self::facts( $p, $lang );
				$f['held'] = isset( $held[ (int) ( $orig[ $id ] ?? 0 ) ] );
				$out[ $id ] = self::shape( $f, $map );
			}
		} finally {
			if ( $switch ) {
				do_action( 'wpml_switch_language', '' !== $was ? $was : null );
			}
		}
		return $out;
	}

	/**
	 * What the shop holds for one offer — read, nothing decided yet.
	 *
	 * Called with WPML in the offer's language (rows() does it).
	 */
	public static function facts( WC_Product $p, string $lang ): array {
		$parent = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : null;
		$main   = $parent ? $parent : $p;
		// The variation's picture, or its product's: WooCommerce's own fallback.
		$image = (int) $p->get_image_id();
		// A translation without a picture shows its original's on the page
		// (WCML's image filter), so the listing shows that one too. The French,
		// German, Polish and Spanish « PAVN jungle boots » have none of their own.
		if ( ! $image && DZE_Wpml::is_active() ) {
			$orig   = DZE_Wpml::canonical_id( $p->get_id(), $parent ? 'product_variation' : 'product' );
			$source = ( $orig && $orig !== $p->get_id() ) ? wc_get_product( $orig ) : null;
			$image  = $source ? (int) $source->get_image_id() : 0;
		}
		$gallery = [];
		foreach ( (array) $main->get_gallery_image_ids() as $gid ) {
			$url = (string) wp_get_attachment_url( (int) $gid );
			if ( '' !== $url ) {
				$gallery[ (int) $gid ] = $url;
			}
		}
		$from = $p->get_date_on_sale_from( 'edit' );
		$to   = $p->get_date_on_sale_to( 'edit' );
		// THE PRICES THE PAGE SHOWS, read through WooCommerce's getters. The
		// automatic discounts of DZE_Discounts are computed as the price is read
		// and never stored: on Kula a variation stored a $32.90 sale and its
		// page charged $36.90. Google compares the listing with the page.
		return [
			'id'                 => $p->get_id(),
			'type'               => $p->get_type(),
			'parent'             => $parent ? $parent->get_id() : 0,
			'name'               => (string) $p->get_name( 'edit' ),
			'description'        => (string) $p->get_description( 'edit' ),
			'parent_description' => $parent ? (string) $parent->get_description( 'edit' ) : '',
			'link'               => self::link( $p, $parent, $lang ),
			'image_id'           => $image,
			'image'              => $image ? (string) wp_get_attachment_url( $image ) : '',
			'gallery'            => $gallery,
			'in_stock'           => $p->is_in_stock(),
			'regular'            => (string) $p->get_regular_price(),
			'sale'               => (string) $p->get_sale_price(),
			'on_sale'            => $p->is_on_sale(),
			'sale_from'          => $from ? $from->getTimestamp() : 0,
			'sale_to'            => $to ? $to->getTimestamp() : 0,
			'currency'           => (string) get_option( 'woocommerce_currency', 'USD' ),
			'paths'              => self::category_paths( $main->get_id() ),
			'attributes'         => self::attribute_values( $p, $parent ),
			'dimensions'         => [ 'length' => (string) $p->get_length(), 'width' => (string) $p->get_width(), 'height' => (string) $p->get_height() ],
			'dimension_unit'     => (string) get_option( 'woocommerce_dimension_unit', 'cm' ),
			'now'                => time(),
		];
	}

	/**
	 * The row Google is sent for one offer, or why it is left out.
	 *
	 * Pure: everything it needs is in $f, so the rules are tested without a
	 * shop (tools/test-gmc-feed.php).
	 *
	 * @param array<int,string> $map Google category per original category.
	 * @return array<string,string>|string The row (empty values left out), or a reason:
	 *         'not-an-offer', 'no-price', 'no-image'.
	 */
	public static function shape( array $f, array $map ) {
		if ( in_array( (string) ( $f['type'] ?? '' ), [ 'variable', 'grouped', 'external', 'variable-subscription' ], true ) ) {
			return 'not-an-offer';
		}
		$regular = self::number( (string) ( $f['regular'] ?? '' ) );
		if ( $regular <= 0 ) {
			return 'no-price';
		}
		if ( '' === (string) ( $f['image'] ?? '' ) ) {
			return 'no-image';
		}
		$cur  = (string) ( $f['currency'] ?? 'USD' );
		$now  = (int) ( $f['now'] ?? time() );
		$attr = (array) ( $f['attributes'] ?? [] );
		$row  = [
			'id'          => (string) (int) $f['id'],
			'title'       => self::cut( self::plain( (string) ( $f['name'] ?? '' ) ), self::TITLE_MAX ),
			'description' => self::description( (string) ( $f['description'] ?? '' ), (string) ( $f['parent_description'] ?? '' ) ),
			'link'        => (string) ( $f['link'] ?? '' ),
			'image_link'  => (string) $f['image'],
		];
		$more = [];
		foreach ( (array) ( $f['gallery'] ?? [] ) as $gid => $url ) {
			if ( (int) $gid !== (int) ( $f['image_id'] ?? 0 ) && $url !== $f['image'] && ! in_array( $url, $more, true ) ) {
				$more[] = (string) $url;
			}
		}
		$row['additional_image_link'] = implode( ',', array_slice( $more, 0, self::MORE_IMAGES ) );
		$row['availability']          = ! empty( $f['in_stock'] ) ? 'in_stock' : 'out_of_stock';
		$row['price']                 = self::money( $regular, $cur );

		$sale = self::number( (string) ( $f['sale'] ?? '' ) );
		$from = (int) ( $f['sale_from'] ?? 0 );
		$to   = (int) ( $f['sale_to'] ?? 0 );
		// WooCommerce's own answer when it gave one: it knows the automatic
		// discounts, which carry no dates of their own.
		$runs = array_key_exists( 'on_sale', $f ) ? ! empty( $f['on_sale'] ) : ( $from <= $now && ( ! $to || $to > $now ) );
		if ( $sale > 0 && $sale < $regular && $runs ) {
			$row['sale_price'] = self::money( $sale, $cur );
			if ( $to > $now ) {
				$row['sale_price_effective_date'] = gmdate( 'Y-m-d\TH:i:s\Z', $from ? $from : $now ) . '/' . gmdate( 'Y-m-d\TH:i:s\Z', $to );
			}
		}

		[ $type, $google ]              = self::categories( (array) ( $f['paths'] ?? [] ), $map );
		$row['product_type']            = $type;
		$row['google_product_category'] = $google;
		$row['identifier_exists']       = 'no';
		$row['condition']               = 'new';
		$row['age_group']               = self::word_of( (array) ( $attr['age'] ?? [] ), self::AGES );
		$row['age_group']               = '' !== $row['age_group'] ? $row['age_group'] : 'adult';
		$row['color']                   = self::several( (array) ( $attr['color'] ?? [] ) );
		$row['gender']                  = self::gender( (array) ( $attr['gender'] ?? [] ) );
		$row['material']                = self::several( (array) ( $attr['material'] ?? [] ) );
		$row['size']                    = implode( ', ', array_map( [ self::class, 'plain' ], (array) ( $attr['size'] ?? [] ) ) );
		$row['item_group_id']           = ! empty( $f['parent'] ) ? (string) (int) $f['parent'] : '';
		foreach ( [ 'length', 'width', 'height' ] as $side ) {
			$row[ 'shipping_' . $side ] = self::dimension( (string) ( $f['dimensions'][ $side ] ?? '' ), (string) ( $f['dimension_unit'] ?? 'cm' ) );
		}
		$row['excluded_destination'] = ! empty( $f['held'] ) ? implode( ',', self::HELD ) : '';
		return array_filter( $row, static fn( $v ) => '' !== (string) $v );
	}

	/**
	 * Rows as the tab-separated file Merchant Center reads, header first.
	 *
	 * @param array<int,array<string,string>|string> $rows Reasons are skipped.
	 */
	public static function tsv( array $rows ): string {
		$lines = [ implode( "\t", self::COLUMNS ) ];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cells = [];
			foreach ( self::COLUMNS as $col ) {
				$cells[] = str_replace( [ "\t", "\r", "\n" ], ' ', (string) ( $row[ $col ] ?? '' ) );
			}
			$lines[] = implode( "\t", $cells );
		}
		return implode( "\n", $lines ) . "\n";
	}

	// =========================================================================
	// Google categories
	// =========================================================================

	/**
	 * The Google category the shop gave each product category.
	 *
	 * @return array<int,string> Original-language term id => Google category id.
	 */
	public static function category_map(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one read of one meta key.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value <> ''", self::META_CATEGORY ) );
		$out  = [];
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->term_id ] = trim( (string) $r->meta_value );
		}
		return $out;
	}

	/**
	 * product_type and google_product_category from the product's categories.
	 *
	 * @param array<int,array{names:string[],terms:int[]}> $paths In WordPress's order (by name).
	 * @return array{0:string,1:string}
	 */
	public static function categories( array $paths, array $map ): array {
		$longest = [];
		foreach ( $paths as $path ) {
			// Strictly longer: a tie keeps the first, as WP All Export did.
			if ( count( (array) $path['names'] ) > count( $longest ) ) {
				$longest = array_values( (array) $path['names'] );
			}
		}
		$type = implode( ' > ', array_map( [ self::class, 'plain' ], $longest ) );

		$by_depth = $paths;
		usort( $by_depth, static fn( $a, $b ) => count( (array) $b['terms'] ) <=> count( (array) $a['terms'] ) );
		$google = '';
		foreach ( $by_depth as $path ) {
			foreach ( array_reverse( (array) $path['terms'] ) as $term ) {
				if ( '' !== (string) ( $map[ (int) $term ] ?? '' ) ) {
					$google = (string) $map[ (int) $term ];
					break 2;
				}
			}
		}
		return [ $type, $google ];
	}

	// =========================================================================
	// Reading the shop
	// =========================================================================

	/**
	 * The offer's page in that language, with the variation's choices in it.
	 */
	private static function link( WC_Product $p, ?WC_Product $parent, string $lang ): string {
		$base = $parent ? $parent->get_id() : $p->get_id();
		$url  = '';
		if ( DZE_Wpml::is_active() && 'default' !== $lang && DZE_Wpml::default_language() !== $lang ) {
			$url = DZE_Wpml::post_url_in_language( DZE_Wpml::canonical_id( $base, 'product' ), 'product', $lang );
		}
		if ( '' === $url ) {
			$url = (string) get_permalink( $base );
		}
		if ( $parent && $p instanceof WC_Product_Variation ) {
			// As WooCommerce builds a variation's own address.
			$data = array_filter( (array) $p->get_variation_attributes(), 'wc_array_filter_default_attributes' );
			if ( $data ) {
				$url = add_query_arg( array_combine( array_map( 'urlencode', array_keys( $data ) ), array_map( 'urlencode', $data ) ), $url );
			}
		}
		return $url;
	}

	/**
	 * Every category path of a product, root first: names in the current
	 * language, terms as their original-language ids.
	 *
	 * @return array<int,array{names:string[],terms:int[]}>
	 */
	private static function category_paths( int $product_id ): array {
		$out = [];
		$ids = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
		if ( is_wp_error( $ids ) ) {
			return [];
		}
		$default = DZE_Wpml::default_language();
		foreach ( (array) $ids as $tid ) {
			$chain   = array_reverse( array_map( 'intval', get_ancestors( (int) $tid, 'product_cat', 'taxonomy' ) ) );
			$chain[] = (int) $tid;
			$names   = [];
			$terms   = [];
			foreach ( $chain as $one ) {
				$term = get_term( $one, 'product_cat' );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				$names[] = (string) $term->name;
				$orig    = '' !== $default ? DZE_Wpml::translated_term( $one, 'product_cat', $default ) : 0;
				$terms[] = $orig ? $orig : $one;
			}
			if ( $names ) {
				$out[] = [ 'names' => $names, 'terms' => $terms ];
			}
		}
		return $out;
	}

	/**
	 * The values of the attributes Google asks for: the variation's own when it
	 * varies on that attribute, otherwise all of the product's.
	 *
	 * @return array<string,string[]> 'color' / 'size' / 'material' / 'gender' / 'age' => values.
	 */
	private static function attribute_values( WC_Product $p, ?WC_Product $parent ): array {
		$out  = [];
		$main = $parent ? $parent : $p;
		foreach ( (array) $main->get_attributes() as $key => $attr ) {
			$name = $attr instanceof WC_Product_Attribute ? $attr->get_name() : (string) $key;
			$want = self::google_attribute( $name );
			if ( '' === $want || isset( $out[ $want ] ) ) {
				continue;
			}
			$values = [];
			if ( $parent ) {
				$own = (string) $p->get_attribute( $name );
				if ( '' !== $own ) {
					$values = [ $own ];
				}
			}
			if ( ! $values && $attr instanceof WC_Product_Attribute ) {
				$values = $attr->is_taxonomy()
					? wc_get_product_terms( $main->get_id(), $attr->get_name(), [ 'fields' => 'names' ] )
					: $attr->get_options();
			}
			$out[ $want ] = array_values( array_filter( array_map( static fn( $v ) => trim( (string) $v ), (array) $values ), 'strlen' ) );
		}
		return $out;
	}

	/** Which Google attribute a shop attribute answers, from its name ('' for none). */
	public static function google_attribute( string $name ): string {
		$slug = sanitize_title( $name );
		foreach ( self::ATTRIBUTES as $google => $pattern ) {
			if ( preg_match( $pattern, $slug ) ) {
				return $google;
			}
		}
		return '';
	}

	// =========================================================================
	// Words and figures
	// =========================================================================

	/** Text without tags, entities or runs of spaces. */
	public static function plain( string $s ): string {
		$s = html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{3000}]+/u', ' ', $s ) );
	}

	/**
	 * The variation's own description, otherwise its product's.
	 *
	 * The markup is kept, as WP All Export sent it for years without a
	 * refusal; shortcodes, comments and line breaks are not.
	 */
	public static function description( string $own, string $parent ): string {
		$pick = '' !== self::plain( $own ) ? $own : $parent;
		$pick = (string) preg_replace( '/<!--.*?-->/s', '', strip_shortcodes( $pick ) );
		$pick = trim( (string) preg_replace( '/\s+/u', ' ', $pick ) );
		if ( mb_strlen( $pick ) > self::DESC_MAX ) {
			$pick = self::cut( self::plain( $pick ), self::DESC_MAX );
		}
		return $pick;
	}

	/** At most $max characters, cut between two words. */
	public static function cut( string $s, int $max ): string {
		if ( mb_strlen( $s ) <= $max ) {
			return $s;
		}
		$s     = mb_substr( $s, 0, $max );
		$space = mb_strrpos( $s, ' ' );
		return rtrim( false !== $space && $space > $max * 0.6 ? mb_substr( $s, 0, $space ) : $s, " ,.;:-" );
	}

	/** Up to three values joined by « / », Google's separator for colours and materials. */
	public static function several( array $values ): string {
		$values = array_values( array_unique( array_filter( array_map( [ self::class, 'plain' ], $values ), 'strlen' ) ) );
		return implode( '/', array_slice( $values, 0, self::MANY_VALUES ) );
	}

	/** Google's gender: one word, « unisex » when the product names both. */
	public static function gender( array $values ): string {
		$found = [];
		foreach ( $values as $v ) {
			$w = self::word_of( [ $v ], self::GENDERS );
			if ( '' !== $w ) {
				$found[ $w ] = true;
			}
		}
		if ( isset( $found['unisex'] ) || ( isset( $found['male'] ) && isset( $found['female'] ) ) ) {
			return 'unisex';
		}
		return (string) array_key_first( $found );
	}

	/** The first of the values that is one of the known words, as Google's word. */
	public static function word_of( array $values, array $words ): string {
		foreach ( $values as $v ) {
			$key = mb_strtolower( remove_accents( self::plain( (string) $v ) ) );
			$key = trim( (string) preg_replace( '/[^\p{L}]+/u', ' ', $key ) );
			foreach ( $words as $google => $known ) {
				if ( in_array( $key, $known, true ) ) {
					return $google;
				}
			}
		}
		return '';
	}

	/** A price as Merchant Center reads it: « 1092.90 USD ». */
	public static function money( float $amount, string $currency ): string {
		return number_format( $amount, 2, '.', '' ) . ' ' . strtoupper( $currency );
	}

	/** A stored price, read as a number whatever its decimal sign. */
	public static function number( string $v ): float {
		$v = trim( str_replace( ',', '.', $v ) );
		return is_numeric( $v ) ? (float) $v : 0.0;
	}

	/** A shipping dimension in centimetres, '' when the product has none. */
	public static function dimension( string $value, string $unit ): string {
		$n = self::number( $value );
		if ( $n <= 0 ) {
			return '';
		}
		$cm = (float) wc_get_dimension( $n, 'cm', $unit );
		return rtrim( rtrim( number_format( $cm, 2, '.', '' ), '0' ), '.' ) . ' cm';
	}
}
