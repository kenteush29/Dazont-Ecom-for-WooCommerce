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
		// The background work, registered on every request: cron and Action
		// Scheduler are not admin screens.
		add_action( self::HOOK_RUN, [ __CLASS__, 'run_page' ], 10, 3 );
		add_action( self::HOOK_SWEEP, [ __CLASS__, 'sweep_page' ], 10, 2 );
		add_action( self::HOOK_DIRTY, [ __CLASS__, 'run_dirty' ] );
		add_action( self::HOOK_DAILY, [ __CLASS__, 'run_daily' ] );
		add_action( 'init', [ __CLASS__, 'schedule_daily' ] );
		// WHAT GOOGLE SHOWS FOLLOWS WHAT THE SHOP CHANGES: a product saved, a
		// stock level moved, a discount rule changed. Only switched accounts
		// pay for it (dirty() returns at once otherwise).
		add_action( 'woocommerce_update_product', [ __CLASS__, 'dirty' ] );
		add_action( 'woocommerce_update_product_variation', [ __CLASS__, 'dirty' ] );
		add_action( 'woocommerce_product_set_stock_status', [ __CLASS__, 'dirty' ] );
		add_action( 'woocommerce_variation_set_stock_status', [ __CLASS__, 'dirty' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'gone' ] );
		add_action( 'dze_discount_saved', [ __CLASS__, 'prices_moved' ] );
		add_action( 'dze_discount_deleted', [ __CLASS__, 'prices_moved' ] );
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

	// =========================================================================
	// Sending to Merchant Center
	//
	// « Arrivé là un module api merchant center serait presque mieux. » Each
	// account gets a data source of its own, « Dazont Ecom », with the same
	// language, feed label and countries as the file it read until now: Google
	// does not keep products apart by data source, so the same offer id, in
	// the same language and under the same label, IS the same product, and
	// Google keeps what it knows about it. The file's daily fetch is switched
	// off at the same moment: fetched again, August's file would overwrite
	// today's prices every morning.
	// =========================================================================

	/** Per language: the account's data sources, its label, its countries, where its sending stands. */
	public const OPT_STATE = 'dze_gmc_feed_state';

	/** Products changed since the last send: original product id => true. */
	public const OPT_DIRTY = 'dze_gmc_feed_dirty';

	/** On an offer: « hash|time|run » of what Merchant Center was last given. */
	public const META_SENT = '_dze_gmc_sent';

	public const HOOK_RUN   = 'dze_gmc_feed_run';
	public const HOOK_SWEEP = 'dze_gmc_feed_sweep';
	public const HOOK_DIRTY = 'dze_gmc_feed_dirty';
	public const HOOK_DAILY = 'dze_gmc_feed_daily';

	/** Offers sent per background step. */
	private const SEND_PAGE = 40;

	/** Google lets a product it has not heard about for 30 days expire: everything is sent again after 20. */
	private const REFRESH = 20 * 86400;

	private const PRODUCTS_API = 'https://merchantapi.googleapis.com/products/v1';
	private const SOURCES_API  = 'https://merchantapi.googleapis.com/datasources/v1';

	/** What Google calls the destinations a product in quarantine leaves. */
	private const DESTINATION = [ 'Shopping_ads' => 'SHOPPING_ADS', 'Display_ads' => 'DISPLAY_ADS' ];

	/**
	 * One row, as the Merchant API's ProductInput (products_v1).
	 *
	 * Pure: the rules are tested without Google (tools/test-gmc-feed.php).
	 */
	public static function to_api( array $row, string $lang, string $label ): array {
		$money = static function ( string $v ): ?array {
			if ( ! preg_match( '/^([0-9]+(?:\.[0-9]+)?)\s+([A-Za-z]{3})$/', trim( $v ), $m ) ) {
				return null;
			}
			return [ 'amountMicros' => (string) (int) round( (float) $m[1] * 1000000 ), 'currencyCode' => strtoupper( $m[2] ) ];
		};
		$cm = static function ( string $v ): ?array {
			return preg_match( '/^([0-9]+(?:\.[0-9]+)?)\s*cm$/', trim( $v ), $m ) ? [ 'value' => (float) $m[1], 'unit' => 'cm' ] : null;
		};
		$a = [
			'title'            => (string) ( $row['title'] ?? '' ),
			'description'      => (string) ( $row['description'] ?? '' ),
			'link'             => (string) ( $row['link'] ?? '' ),
			'imageLink'        => (string) ( $row['image_link'] ?? '' ),
			'availability'     => 'in_stock' === ( $row['availability'] ?? '' ) ? 'IN_STOCK' : 'OUT_OF_STOCK',
			'condition'        => 'NEW',
			'identifierExists' => false,
			'ageGroup'         => strtoupper( (string) ( $row['age_group'] ?? 'adult' ) ),
		];
		$more = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $row['additional_image_link'] ?? '' ) ) ) ) );
		if ( $more ) {
			$a['additionalImageLinks'] = $more;
		}
		if ( $p = $money( (string) ( $row['price'] ?? '' ) ) ) {
			$a['price'] = $p;
		}
		if ( $p = $money( (string) ( $row['sale_price'] ?? '' ) ) ) {
			$a['salePrice'] = $p;
			$when = explode( '/', (string) ( $row['sale_price_effective_date'] ?? '' ) );
			if ( 2 === count( $when ) && '' !== $when[0] && '' !== $when[1] ) {
				$a['salePriceEffectiveDate'] = [ 'startTime' => $when[0], 'endTime' => $when[1] ];
			}
		}
		foreach ( [ 'product_type' => 'productTypes' ] as $from => $to ) {
			if ( '' !== (string) ( $row[ $from ] ?? '' ) ) {
				$a[ $to ] = [ (string) $row[ $from ] ];
			}
		}
		foreach ( [ 'google_product_category' => 'googleProductCategory', 'color' => 'color', 'material' => 'material', 'size' => 'size', 'item_group_id' => 'itemGroupId' ] as $from => $to ) {
			if ( '' !== (string) ( $row[ $from ] ?? '' ) ) {
				$a[ $to ] = (string) $row[ $from ];
			}
		}
		if ( '' !== (string) ( $row['gender'] ?? '' ) ) {
			$a['gender'] = strtoupper( (string) $row['gender'] );
		}
		foreach ( [ 'length', 'width', 'height' ] as $side ) {
			if ( $d = $cm( (string) ( $row[ 'shipping_' . $side ] ?? '' ) ) ) {
				$a[ 'shipping' . ucfirst( $side ) ] = $d;
			}
		}
		$out = [];
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $row['excluded_destination'] ?? '' ) ) ) ) as $d ) {
			if ( isset( self::DESTINATION[ $d ] ) ) {
				$out[] = self::DESTINATION[ $d ];
			}
		}
		if ( $out ) {
			$a['excludedDestinations'] = $out;
		}
		return [
			'offerId'           => (string) ( $row['id'] ?? '' ),
			'contentLanguage'   => $lang,
			'feedLabel'         => $label,
			'productAttributes' => $a,
		];
	}

	/** The state of every account, by language. */
	public static function states(): array {
		$s = get_option( self::OPT_STATE, [] );
		return is_array( $s ) ? $s : [];
	}

	public static function state( string $lang ): array {
		return (array) ( self::states()[ $lang ] ?? [] );
	}

	private static function save_state( string $lang, array $changes ): array {
		$all          = self::states();
		$all[ $lang ] = array_merge( (array) ( $all[ $lang ] ?? [] ), $changes );
		update_option( self::OPT_STATE, $all, false );
		return $all[ $lang ];
	}

	/** The Merchant Center account of a language, '' when it has none. */
	public static function merchant( string $lang ): string {
		foreach ( DZE_Gmc::get_accounts() as $key => $acc ) {
			if ( (string) ( $acc['language'] ?? $key ) === $lang && '' !== (string) ( $acc['merchant_id'] ?? '' ) ) {
				return (string) $acc['merchant_id'];
			}
		}
		return '';
	}

	/** Has this account been switched to the Dazont listing? */
	public static function switched( string $lang ): bool {
		return ! empty( self::state( $lang )['switched'] );
	}

	/**
	 * What the account holds today: its primary data sources, ours among them.
	 *
	 * Read only. The label and countries the shop's listing must carry are
	 * the ones of the file Google reads now; when that file says nothing,
	 * the label of the products Google already holds.
	 *
	 * @return array{ours:string,file:string,file_name:string,fetch:bool,label:string,countries:string[],language:string}
	 */
	public static function inspect( string $lang ): array {
		$id = self::merchant( $lang );
		if ( '' === $id ) {
			throw new RuntimeException( __( 'This language has no Merchant Center account.', 'dazont-ecom' ) );
		}
		$g    = DZE_Gmc::instance();
		$list = $g->api( 'GET', self::SOURCES_API . '/accounts/' . $id . '/dataSources?pageSize=200' );
		$out  = [ 'ours' => '', 'file' => '', 'file_name' => '', 'fetch' => false, 'label' => '', 'countries' => [], 'language' => $lang ];
		foreach ( (array) ( $list['dataSources'] ?? [] ) as $ds ) {
			$p = $ds['primaryProductDataSource'] ?? null;
			if ( ! is_array( $p ) ) {
				continue;
			}
			if ( 'API' === ( $ds['input'] ?? '' ) && 0 === strpos( (string) ( $ds['displayName'] ?? '' ), 'Dazont Ecom' ) ) {
				$out['ours'] = (string) $ds['name'];
				continue;
			}
			if ( 'FILE' === ( $ds['input'] ?? '' ) && '' === $out['file'] ) {
				$out['file']      = (string) $ds['name'];
				$out['file_name'] = (string) ( $ds['displayName'] ?? '' );
				$out['fetch']     = ! empty( $ds['fileInput']['fetchSettings']['enabled'] );
				$out['label']     = (string) ( $p['feedLabel'] ?? '' );
				$out['countries'] = array_values( array_map( 'strval', (array) ( $p['countries'] ?? [] ) ) );
				if ( ! empty( $p['contentLanguage'] ) ) {
					$out['language'] = (string) $p['contentLanguage'];
				}
			}
		}
		if ( '' === $out['label'] ) {
			$one          = $g->api( 'GET', self::PRODUCTS_API . '/accounts/' . $id . '/products?pageSize=1' );
			$out['label'] = (string) ( $one['products'][0]['feedLabel'] ?? '' );
		}
		if ( '' === $out['label'] ) {
			$c            = DZE_Gmc::country_for_language( $lang );
			$out['label'] = (string) ( $c[0] ?? 'US' );
		}
		if ( ! $out['countries'] ) {
			$out['countries'] = [ $out['label'] ];
		}
		return $out;
	}

	/**
	 * Switches one account to the Dazont listing: our data source made (once),
	 * the file's daily fetch switched off, and the whole listing sent.
	 *
	 * @return array The account's state.
	 */
	public static function switch_account( string $lang ): array {
		$id  = self::merchant( $lang );
		$now = self::inspect( $lang );
		$g   = DZE_Gmc::instance();
		$ours = $now['ours'];
		if ( '' === $ours ) {
			$made = $g->api( 'POST', self::SOURCES_API . '/accounts/' . $id . '/dataSources', [
				'displayName'              => 'Dazont Ecom',
				'primaryProductDataSource' => [
					'contentLanguage' => $now['language'],
					'feedLabel'       => $now['label'],
					'countries'       => $now['countries'],
				],
			] );
			$ours = (string) ( $made['name'] ?? '' );
			if ( '' === $ours ) {
				throw new RuntimeException( __( 'Merchant Center did not make the data source.', 'dazont-ecom' ) );
			}
		}
		// THE FILE STOPS BEING FETCHED, or it overwrites today's data with
		// August's every morning. Asked of Google, then read back.
		if ( '' !== $now['file'] && $now['fetch'] ) {
			$g->api( 'PATCH', self::SOURCES_API . '/' . $now['file'] . '?updateMask=fileInput.fetchSettings.enabled', [
				'fileInput' => [ 'fetchSettings' => [ 'enabled' => false ] ],
			] );
			$back = $g->api( 'GET', self::SOURCES_API . '/' . $now['file'] );
			if ( ! empty( $back['fileInput']['fetchSettings']['enabled'] ) ) {
				throw new RuntimeException( __( 'Merchant Center still fetches the old file every day. Switch its fetch schedule off under Merchant Center → Settings → Data sources, then press again.', 'dazont-ecom' ) );
			}
		}
		$state = self::save_state( $lang, [
			'source'    => $ours,
			'file'      => $now['file'],
			'file_name' => $now['file_name'],
			'label'     => $now['label'],
			'countries' => $now['countries'],
			'language'  => $now['language'],
			'switched'  => (int) ( self::state( $lang )['switched'] ?? 0 ) ?: time(),
			'error'     => '',
		] );
		self::start( $lang );
		return $state;
	}

	/** Starts a full pass over one account: every offer compared, sent when it changed. */
	public static function start( string $lang ): void {
		if ( ! self::switched( $lang ) ) {
			return;
		}
		$run = (string) time();
		self::save_state( $lang, [ 'run' => $run, 'running' => 1, 'seen' => 0, 'sent' => 0, 'refused' => 0, 'refusals' => [], 'started' => time() ] );
		self::queue( self::HOOK_RUN, [ $lang, $run, 0 ] );
	}

	private static function queue( string $hook, array $args, int $delay = 0 ): void {
		if ( function_exists( 'as_enqueue_async_action' ) && 0 === $delay ) {
			as_enqueue_async_action( $hook, $args, 'dazont-ecom' );
		} elseif ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + max( 1, $delay ), $hook, $args, 'dazont-ecom' );
		} else {
			wp_schedule_single_event( time() + max( 1, $delay ), $hook, $args );
		}
	}

	/**
	 * One step of a full pass: a page of offers, each sent when what Google
	 * would read changed or has not been sent for 20 days.
	 */
	public static function run_page( $lang = '', $run = '', $after = 0 ): void {
		$lang  = (string) $lang;
		$state = self::state( $lang );
		if ( ! self::switched( $lang ) || (string) ( $state['run'] ?? '' ) !== (string) $run ) {
			return; // switched back, or a newer pass took over.
		}
		$ids = self::ids( $lang, (int) $after, self::SEND_PAGE );
		if ( $ids ) {
			$r = self::send( $lang, self::rows( $ids, $lang ), (string) $run );
			if ( 'quota' === $r['stop'] ) {
				self::queue( self::HOOK_RUN, [ $lang, (string) $run, (int) $after ], 15 * MINUTE_IN_SECONDS );
				return; // Google asked to slow down: the same page, a little later.
			}
		}
		if ( count( $ids ) === self::SEND_PAGE ) {
			self::queue( self::HOOK_RUN, [ $lang, (string) $run, (int) end( $ids ) ] );
			return;
		}
		// Every offer of the listing has been seen: what Google still holds and
		// the listing no longer has is taken off.
		self::queue( self::HOOK_SWEEP, [ $lang, (string) $run ] );
	}

	/**
	 * Sends some rows; reasons (left out) take the offer off when Google had it.
	 *
	 * @param array<int,array|string> $rows
	 * @return array{sent:int,refused:int,stop:string}
	 */
	private static function send( string $lang, array $rows, string $run ): array {
		$state  = self::state( $lang );
		$g      = DZE_Gmc::instance();
		$id     = self::merchant( $lang );
		$label  = (string) ( $state['label'] ?? 'US' );
		$source = (string) ( $state['source'] ?? '' );
		$out    = [ 'sent' => 0, 'refused' => 0, 'stop' => '' ];
		$refusals = (array) ( $state['refusals'] ?? [] );
		foreach ( $rows as $offer => $row ) {
			$offer = (int) $offer;
			$had   = explode( '|', (string) get_post_meta( $offer, self::META_SENT, true ) );
			if ( ! is_array( $row ) ) {
				if ( '' !== $had[0] ) {
					self::take_off( $lang, $offer );
				}
				continue;
			}
			$body = self::to_api( $row, (string) ( $state['language'] ?? $lang ), $label );
			$hash = md5( (string) wp_json_encode( $body ) );
			if ( $hash === $had[0] && (int) ( $had[1] ?? 0 ) > time() - self::REFRESH ) {
				update_post_meta( $offer, self::META_SENT, $hash . '|' . (int) $had[1] . '|' . $run );
				continue; // Google already has exactly this.
			}
			try {
				$g->api( 'POST', self::PRODUCTS_API . '/accounts/' . $id . '/productInputs:insert?dataSource=' . rawurlencode( $source ), $body );
				update_post_meta( $offer, self::META_SENT, $hash . '|' . time() . '|' . $run );
				$out['sent']++;
			} catch ( \Throwable $e ) {
				$msg = $e->getMessage();
				if ( preg_match( '/quota|rate|RESOURCE_EXHAUSTED|429/i', $msg ) ) {
					$out['stop'] = 'quota';
					break;
				}
				$out['refused']++;
				if ( count( $refusals ) < 20 ) {
					$refusals[] = [ 'offer' => $offer, 'said' => mb_substr( $msg, 0, 300 ) ];
				}
			}
		}
		self::save_state( $lang, [
			'seen'     => (int) ( $state['seen'] ?? 0 ) + count( $rows ),
			'sent'     => (int) ( $state['sent'] ?? 0 ) + $out['sent'],
			'refused'  => (int) ( $state['refused'] ?? 0 ) + $out['refused'],
			'refusals' => $refusals,
			'last'     => time(),
		] );
		return $out;
	}

	/** Takes one offer off Merchant Center (our data source only) and forgets it was sent. */
	private static function take_off( string $lang, int $offer ): void {
		$state = self::state( $lang );
		$name  = 'accounts/' . self::merchant( $lang ) . '/productInputs/' . rawurlencode( (string) ( $state['language'] ?? $lang ) . '~' . (string) ( $state['label'] ?? 'US' ) . '~' . $offer );
		try {
			DZE_Gmc::instance()->api( 'DELETE', self::PRODUCTS_API . '/' . $name . '?dataSource=' . rawurlencode( (string) ( $state['source'] ?? '' ) ) );
		} catch ( \Throwable $e ) {
			// Already gone is what was wanted; anything else is said by the next pass.
			unset( $e );
		}
		delete_post_meta( $offer, self::META_SENT );
	}

	/** The end of a full pass: offers Google holds from us that this pass did not see are taken off. */
	public static function sweep_page( $lang = '', $run = '' ): void {
		global $wpdb;
		$lang  = (string) $lang;
		$state = self::state( $lang );
		if ( (string) ( $state['run'] ?? '' ) !== (string) $run ) {
			return;
		}
		$icl  = $wpdb->prefix . 'icl_translations';
		$join = DZE_Wpml::is_active() && 'default' !== $lang && DZE_Wpml::has_table( $icl )
			? $wpdb->prepare( "INNER JOIN {$icl} t ON t.element_id = m.post_id AND t.element_type IN ( 'post_product', 'post_product_variation' ) AND t.language_code = %s", $lang )
			: '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- our own meta key; the join is prepared above.
		$stale = $wpdb->get_col( $wpdb->prepare( "SELECT m.post_id FROM {$wpdb->postmeta} m {$join} WHERE m.meta_key = %s AND m.meta_value NOT LIKE %s LIMIT %d", self::META_SENT, '%|' . $wpdb->esc_like( (string) $run ), self::SEND_PAGE ) );
		foreach ( (array) $stale as $offer ) {
			self::take_off( $lang, (int) $offer );
		}
		if ( count( (array) $stale ) === self::SEND_PAGE ) {
			self::queue( self::HOOK_SWEEP, [ $lang, (string) $run ] );
			return;
		}
		self::save_state( $lang, [ 'running' => 0, 'done' => time() ] );
		delete_transient( 'dze_gmc_issues_' . $lang );
	}

	/** A product changed: its offers are sent again within minutes, in every switched account. */
	public static function dirty( $product_id ): void {
		$product_id = (int) $product_id;
		if ( $product_id < 1 || ! array_filter( array_keys( self::states() ), [ __CLASS__, 'switched' ] ) ) {
			return;
		}
		$set                = (array) get_option( self::OPT_DIRTY, [] );
		$set[ $product_id ] = true;
		update_option( self::OPT_DIRTY, array_slice( $set, -2000, null, true ), false );
		if ( function_exists( 'as_next_scheduled_action' ) ? false === as_next_scheduled_action( self::HOOK_DIRTY ) : ! wp_next_scheduled( self::HOOK_DIRTY ) ) {
			self::queue( self::HOOK_DIRTY, [], 5 * MINUTE_IN_SECONDS );
		}
	}

	/** A deleted product leaves Merchant Center at once, rather than after its 30 days. */
	public static function gone( $post_id ): void {
		$post_id = (int) $post_id;
		$had     = (string) get_post_meta( $post_id, self::META_SENT, true );
		if ( '' === $had ) {
			return;
		}
		$lang = self::language_of( $post_id );
		$lang = '' !== $lang ? $lang : 'default';
		if ( self::switched( $lang ) ) {
			self::take_off( $lang, $post_id );
		}
	}

	/** A discount rule changed: prices move on many products at once, so every switched account is passed over. */
	public static function prices_moved(): void {
		foreach ( array_keys( self::states() ) as $lang ) {
			if ( self::switched( (string) $lang ) ) {
				self::start( (string) $lang );
			}
		}
	}

	/** The changed products, sent in every switched account. */
	public static function run_dirty(): void {
		$set = array_map( 'intval', array_keys( (array) get_option( self::OPT_DIRTY, [] ) ) );
		delete_option( self::OPT_DIRTY );
		if ( ! $set ) {
			return;
		}
		// The products and their variations, in every language.
		$all = [];
		foreach ( DZE_Ads::originals( $set ) as $orig ) {
			if ( $orig ) {
				$all[ $orig ] = true;
			}
		}
		foreach ( array_keys( self::states() ) as $lang ) {
			$lang = (string) $lang;
			if ( ! self::switched( $lang ) ) {
				continue;
			}
			$offers = self::offers_of( $lang, array_keys( $all ) );
			foreach ( array_chunk( $offers, self::SEND_PAGE ) as $chunk ) {
				$listed = self::ids_of_language( $lang, $chunk );
				$rows   = $listed ? self::rows( $listed, $lang ) : [];
				// An offer that left the listing (unpublished, unflagged) is a reason here.
				foreach ( array_diff( $chunk, $listed ) as $off ) {
					$rows[ (int) $off ] = 'not-listed';
				}
				self::send( $lang, $rows, (string) ( self::state( $lang )['run'] ?? '' ) );
			}
		}
	}

	/**
	 * Every offer (simple product or variation) of some original products, in one language.
	 *
	 * @param int[] $originals
	 * @return int[]
	 */
	private static function offers_of( string $lang, array $originals ): array {
		global $wpdb;
		$family = self::family( $originals );
		if ( ! $family ) {
			return [];
		}
		$in = implode( ',', array_map( 'intval', $family ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		$ids = (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ( ID IN ( {$in} ) AND post_type = 'product' ) OR ( post_parent IN ( {$in} ) AND post_type = 'product_variation' )" );
		$ids = array_map( 'intval', $ids );
		// Those of this language, and those Google had from us even if they left it.
		$mine = self::ids_of_language( $lang, $ids );
		foreach ( $ids as $one ) {
			if ( '' !== (string) get_post_meta( $one, self::META_SENT, true ) && ! in_array( $one, $mine, true ) && self::language_of( $one ) === $lang ) {
				$mine[] = $one;
			}
		}
		return $mine;
	}

	/** Of some ids, those the listing of this language holds today. @return int[] */
	private static function ids_of_language( string $lang, array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		[ $sql, $args ] = self::offers_sql( $lang, 'p.ID' );
		$sql .= ' AND p.ID IN ( ' . implode( ',', $ids ) . ' )';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- built by offers_sql(), integers joined above.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/** The language of one product or variation, '' without WPML. */
	private static function language_of( int $id ): string {
		$type = (string) get_post_type( $id );
		return '' !== $type ? DZE_Wpml::post_language( $id, $type ) : '';
	}

	/** Every night, a full pass over every switched account: only what changed is sent. */
	public static function run_daily(): void {
		foreach ( array_keys( self::states() ) as $lang ) {
			if ( self::switched( (string) $lang ) ) {
				self::start( (string) $lang );
			}
		}
	}

	public static function schedule_daily(): void {
		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			$at = strtotime( 'tomorrow 03:30', (int) current_time( 'timestamp' ) ) - ( (int) current_time( 'timestamp' ) - time() );
			wp_schedule_event( $at, 'daily', self::HOOK_DAILY );
		}
	}

	/** Leaves the shop: the night pass is no longer scheduled. */
	public static function clear_cron(): void {
		wp_clear_scheduled_hook( self::HOOK_DAILY );
	}

	/**
	 * What Google says about the products of one account: its remarks, the
	 * most common first. Read at most 5,000 products, kept six hours.
	 *
	 * @return array{products:int,with:int,issues:array<int,array{said:string,n:int,severity:string}>}
	 */
	public static function issues( string $lang, bool $fresh = false ): array {
		$key = 'dze_gmc_issues_' . $lang;
		$got = $fresh ? false : get_transient( $key );
		if ( is_array( $got ) ) {
			return $got;
		}
		$id    = self::merchant( $lang );
		$g     = DZE_Gmc::instance();
		$out   = [ 'products' => 0, 'with' => 0, 'issues' => [] ];
		$token = '';
		$count = [];
		for ( $page = 0; $page < 5; $page++ ) {
			$list = $g->api( 'GET', self::PRODUCTS_API . '/accounts/' . $id . '/products?pageSize=1000' . ( '' !== $token ? '&pageToken=' . rawurlencode( $token ) : '' ) );
			foreach ( (array) ( $list['products'] ?? [] ) as $p ) {
				$out['products']++;
				$issues = (array) ( $p['productStatus']['itemLevelIssues'] ?? [] );
				if ( $issues ) {
					$out['with']++;
				}
				foreach ( $issues as $i ) {
					$said = (string) ( $i['description'] ?? $i['code'] ?? '' );
					$k    = $said . '|' . (string) ( $i['severity'] ?? '' );
					$count[ $k ] = ( $count[ $k ] ?? 0 ) + 1;
				}
			}
			$token = (string) ( $list['nextPageToken'] ?? '' );
			if ( '' === $token ) {
				break;
			}
		}
		arsort( $count );
		foreach ( array_slice( $count, 0, 8, true ) as $k => $n ) {
			[ $said, $severity ] = array_pad( explode( '|', (string) $k, 2 ), 2, '' );
			$out['issues'][] = [ 'said' => $said, 'n' => (int) $n, 'severity' => $severity ];
		}
		set_transient( $key, $out, 6 * HOUR_IN_SECONDS );
		return $out;
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
