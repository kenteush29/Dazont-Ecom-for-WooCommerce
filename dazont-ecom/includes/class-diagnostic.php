<?php
/**
 * The shop read against its own standards.
 *
 * @package Dazont_Ecom
 */

defined( 'ABSPATH' ) || exit;

/**
 * What is missing, product by product, category by category, article by article.
 *
 * The plugin can write a description, make a photograph, add a link. What it
 * could not do was answer the question that comes first: WHERE. A shop of a
 * thousand products has no memory of which of them is short of a paragraph and
 * which has one photograph in its gallery, and a catalogue audited once in a
 * spreadsheet is out of date the week after.
 *
 * So this reads the shop and says so. Nothing here writes, generates, spends
 * or decides: it is a to-do list, and every line of it points at the screen
 * that already knows how to fix that one thing.
 *
 * The criteria are not a list of this plugin's opinions, and they are not two
 * lists either. ONE list, written by the shop: a criterion is a field, a
 * comparison and a figure — "the description holds fewer than 120 words", "the
 * custom field _bloc_text_2 is empty" — and it exists because somebody wrote
 * it. Nothing else adds a line. Criteria used to arrive from the prompt
 * registry as well, cleverly and invisibly: they could not be found, edited or
 * explained, and a screen that answers questions nobody asked is a screen
 * nobody trusts.
 *
 * The reading is done in cron and kept. A screen that counted a thousand
 * products on every load would be a screen nobody opens twice.
 */
final class DZE_Diagnostic {

	public const MENU_SLUG  = 'dazont-ecom-diagnostic';
	public const OPT        = 'dze_diagnostic';
	public const OPT_CENSUS = 'dze_diagnostic_census';
	public const OPT_LISTS  = 'dze_diagnostic_lists';
	private const NONCE     = 'dze_diag';
	private const CRON      = 'dze_diagnostic_scan';
	private const LOCK      = 'dze_diag_lock';

	/**
	 * Objects listed per criterion. The COUNT is always exact.
	 *
	 * It was a thousand, and a shop with 2,106 products short of one thing hit
	 * it and was told the list "can show" less than the count — true, and no
	 * help at all. The cap exists because these ids are stored, and the reason
	 * it was low is that every criterion's list sat in ONE option: twelve of
	 * them at five thousand ids would be read together, on every load of this
	 * screen, to show fifty rows. Each list is now its own option, read only
	 * when that list is opened, so the cap can be what a shop actually needs.
	 */
	private const KEEP_IDS = 20000;

	/** Rows on one page of a list. */
	private const PER_PAGE = 50;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( self::CRON, [ __CLASS__, 'scan' ] );
		if ( ! is_admin() ) {
			// A customer never pays for this: it is an admin screen and a
			// nightly reading, and neither belongs in a shop page's request.
			return;
		}
		add_action( 'admin_menu', [ $this, 'register_menu' ], 12 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ __CLASS__, 'schedule' ] );
		add_action( 'wp_ajax_dze_diag_scan', [ __CLASS__, 'ajax_scan' ] );
		add_action( 'wp_ajax_dze_diag_keys', [ __CLASS__, 'ajax_keys' ] );
	}

	/** Once a day, and never twice. */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Switched off, it stands its nightly reading down.
	 *
	 * A module that stops being booted still leaves its cron event behind, and
	 * an event firing into the void is a trace a disabled module has no
	 * business leaving.
	 */
	public static function disable(): void {
		$ts = wp_next_scheduled( self::CRON );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON );
			$ts = wp_next_scheduled( self::CRON );
		}
	}

	// =========================================================================
	// The standards
	// =========================================================================

	public static function settings(): array {
		$s = get_option( self::OPT, [] );
		return is_array( $s ) ? $s : [];
	}

	/**
	 * What the shop can be read against, and what each answer IS.
	 *
	 * One menu rather than two: "Product · description" says the scope and the
	 * field in the words the owner already uses, and nothing has to be kept in
	 * step between them. A field answers with TEXT or with a COUNT, and that
	 * is what decides which rules can be asked of it.
	 *
	 * @return array<string,array{scope:string,label:string,kind:string,key?:bool}>
	 */
	public static function fields(): array {
		return [
			// --- Products, read as text -------------------------------------
			'product.title'             => [ 'scope' => 'product',  'kind' => 'text',   'unit' => 'characters', 'label' => __( 'title', 'dazont-ecom' ) ],
			'product.description'       => [ 'scope' => 'product',  'kind' => 'text',   'unit' => 'words',      'label' => __( 'description', 'dazont-ecom' ) , 'rule' => [ 'lt', 120 ] ],
			'product.short_description' => [ 'scope' => 'product',  'kind' => 'text',   'unit' => 'words',      'label' => __( 'short description', 'dazont-ecom' ) ],
			'product.seo_title'         => [ 'scope' => 'product',  'kind' => 'text',   'unit' => 'characters', 'label' => __( 'SEO title', 'dazont-ecom' ) , 'rule' => [ 'gt', 60 ] ],
			'product.seo_desc'          => [ 'scope' => 'product',  'kind' => 'text',   'unit' => 'characters', 'label' => __( 'SEO description', 'dazont-ecom' ) , 'rule' => [ 'gt', 160 ] ],
			'product.sku'               => [ 'scope' => 'product',  'kind' => 'text',   'unit' => 'characters', 'label' => __( 'SKU', 'dazont-ecom' ) ],
			// ONE custom field, whatever it holds. Splitting it into a text one
			// and a number one was this plugin deciding what ACF is allowed to
			// store: a shop has image fields, galleries, true/false, selects,
			// repeaters, and none of them is "text" or "number". The key is
			// typed, the value is read as it stands, and what the comparison
			// means is written under the row.
			'product.meta'              => [ 'scope' => 'product',  'kind' => 'meta',   'unit' => '',           'label' => __( 'custom field', 'dazont-ecom' ), 'key' => true , 'keyname' => true, 'keyhint' => 'e.g. _bloc_text_2' ],
			// --- Products, read as a number ---------------------------------
			'product.main_image'        => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'photographs','label' => __( 'main photograph', 'dazont-ecom' ) ],
			'product.main_image_width'  => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'px',         'label' => __( 'main photograph, width', 'dazont-ecom' ) , 'rule' => [ 'lt', 800 ] ],
			'product.main_image_height' => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'px',         'label' => __( 'main photograph, height', 'dazont-ecom' ) , 'rule' => [ 'lt', 800 ] ],
			'product.main_image_side'   => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'px',         'label' => __( 'main photograph, smallest side', 'dazont-ecom' ) , 'rule' => [ 'lt', 800 ] ],
			'product.gallery'           => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'photographs','label' => __( 'gallery photographs', 'dazont-ecom' ) , 'rule' => [ 'lt', 3 ] ],
			'product.links'             => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'links',      'label' => __( 'links in the description', 'dazont-ecom' ) ],
			'product.price'             => [ 'scope' => 'product',  'kind' => 'number', 'unit' => '',           'label' => __( 'price', 'dazont-ecom' ) ],
			'product.sale_price'        => [ 'scope' => 'product',  'kind' => 'number', 'unit' => '',           'label' => __( 'sale price', 'dazont-ecom' ) ],
			'product.stock'             => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'in stock',   'label' => __( 'stock', 'dazont-ecom' ) ],
			'product.weight'            => [ 'scope' => 'product',  'kind' => 'number', 'unit' => '',           'label' => __( 'weight', 'dazont-ecom' ) ],
			'product.categories'        => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'categories', 'label' => __( 'categories', 'dazont-ecom' ) ],
			'product.tags'              => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'tags',       'label' => __( 'tags', 'dazont-ecom' ) ],
			'product.attributes'        => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'attributes', 'label' => __( 'attributes', 'dazont-ecom' ) ],
			'product.variations'        => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'variations', 'label' => __( 'variations', 'dazont-ecom' ) ],
			// Variations of ONE attribute, so "the colours with no photograph"
			// is a criterion. A shop selling the same jacket in six colours
			// shows the same photograph six times until somebody notices, and
			// nobody notices from the product list.
			'product.variation_images'  => [ 'scope' => 'product',  'kind' => 'number', 'unit' => '',           'label' => __( 'variations without an image', 'dazont-ecom' ), 'key' => true, 'keyhint' => 'which ones — attribute_pa_couleur', 'rule' => [ 'gt', 0 ] ],
			'product.reviews'           => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'reviews',    'label' => __( 'reviews', 'dazont-ecom' ) ],
			'product.rating'            => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'stars',      'label' => __( 'average rating', 'dazont-ecom' ) , 'rule' => [ 'lt', 4 ] ],
			'product.age'               => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'days',       'label' => __( 'days since published', 'dazont-ecom' ) ],
			// --- Categories and articles ------------------------------------
			'category.description'      => [ 'scope' => 'category', 'kind' => 'text',   'unit' => 'words',      'label' => __( 'description', 'dazont-ecom' ) , 'rule' => [ 'lt', 150 ] ],
			'category.links'            => [ 'scope' => 'category', 'kind' => 'number', 'unit' => 'links',      'label' => __( 'internal links', 'dazont-ecom' ) , 'rule' => [ 'lt', 2 ] ],
			'category.products'         => [ 'scope' => 'category', 'kind' => 'number', 'unit' => 'products',   'label' => __( 'products in it', 'dazont-ecom' ) ],
			'post.links'                => [ 'scope' => 'post',     'kind' => 'number', 'unit' => 'links',      'label' => __( 'internal links', 'dazont-ecom' ) , 'rule' => [ 'lt', 0 ] ],
			'post.content'              => [ 'scope' => 'post',     'kind' => 'text',   'unit' => 'words',      'label' => __( 'the text itself', 'dazont-ecom' ) , 'rule' => [ 'lt', 300 ] ],
			'post.title'                => [ 'scope' => 'post',     'kind' => 'text',   'unit' => 'characters', 'label' => __( 'title', 'dazont-ecom' ) ],
			'post.excerpt'              => [ 'scope' => 'post',     'kind' => 'text',   'unit' => 'words',      'label' => __( 'excerpt', 'dazont-ecom' ) ],
			'post.seo_title'            => [ 'scope' => 'post',     'kind' => 'text',   'unit' => 'characters', 'label' => __( 'SEO title', 'dazont-ecom' ) , 'rule' => [ 'gt', 60 ] ],
			'post.seo_desc'             => [ 'scope' => 'post',     'kind' => 'text',   'unit' => 'characters', 'label' => __( 'SEO description', 'dazont-ecom' ) , 'rule' => [ 'gt', 160 ] ],
			'post.meta'                 => [ 'scope' => 'post',     'kind' => 'meta',   'unit' => '',           'label' => __( 'custom field', 'dazont-ecom' ), 'key' => true , 'keyname' => true, 'keyhint' => 'e.g. _bloc_text_2' ],
			'post.updated'              => [ 'scope' => 'post',     'kind' => 'number', 'unit' => 'days ago',   'label' => __( 'last updated', 'dazont-ecom' ) , 'rule' => [ 'gt', 365 ] ],
			'post.age'                  => [ 'scope' => 'post',     'kind' => 'number', 'unit' => 'days ago',   'label' => __( 'published', 'dazont-ecom' ) , 'rule' => [ 'gt', 365 ] ],
			'post.main_image'           => [ 'scope' => 'post',     'kind' => 'number', 'unit' => 'photographs','label' => __( 'featured image', 'dazont-ecom' ) ],
			'post.images'               => [ 'scope' => 'post',     'kind' => 'number', 'unit' => 'photographs','label' => __( 'images in the text', 'dazont-ecom' ) ],
			'post.headings'             => [ 'scope' => 'post',     'kind' => 'number', 'unit' => 'headings',   'label' => __( 'headings', 'dazont-ecom' ) ],
			'post.comments'             => [ 'scope' => 'post',     'kind' => 'number', 'unit' => 'comments',   'label' => __( 'comments', 'dazont-ecom' ) ],
		];
	}

	/**
	 * The comparisons a criterion can be written with.
	 *
	 * The same vocabulary the shop already uses to filter an export — equals,
	 * greater than, less than or equal, contains — rather than three canned
	 * rules. `sign` is the same comparison written as a symbol, for the shop
	 * that reads `< 120` faster than "fewer than 120": one preference, one
	 * list, no second table to keep in step.
	 *
	 * `takes` says what the comparison needs beside it: a NUMBER, a piece of
	 * TEXT to look for, or nothing at all.
	 *
	 * @return array<string,array{word:string,sign:string,takes:string,kinds:string[]}>
	 */
	public static function operators(): array {
		return [
			'empty'        => [ 'word' => __( 'is empty', 'dazont-ecom' ),              'sign' => __( 'is empty', 'dazont-ecom' ),     'takes' => '',       'kinds' => [ 'text', 'number', 'meta' ] ],
			'filled'       => [ 'word' => __( 'is not empty', 'dazont-ecom' ),          'sign' => __( 'is not empty', 'dazont-ecom' ), 'takes' => '',       'kinds' => [ 'text', 'number', 'meta' ] ],
			'lt'           => [ 'word' => __( 'is less than', 'dazont-ecom' ),          'sign' => '<',                                 'takes' => 'number', 'kinds' => [ 'text', 'number', 'meta' ] ],
			'lte'          => [ 'word' => __( 'is at most', 'dazont-ecom' ),            'sign' => '≤',                                 'takes' => 'number', 'kinds' => [ 'text', 'number', 'meta' ] ],
			'gt'           => [ 'word' => __( 'is more than', 'dazont-ecom' ),          'sign' => '>',                                 'takes' => 'number', 'kinds' => [ 'text', 'number', 'meta' ] ],
			'gte'          => [ 'word' => __( 'is at least', 'dazont-ecom' ),           'sign' => '≥',                                 'takes' => 'number', 'kinds' => [ 'text', 'number', 'meta' ] ],
			'eq'           => [ 'word' => __( 'equals', 'dazont-ecom' ),                'sign' => '=',                                 'takes' => 'number', 'kinds' => [ 'text', 'number', 'meta' ] ],
			'neq'          => [ 'word' => __( 'does not equal', 'dazont-ecom' ),        'sign' => '≠',                                 'takes' => 'number', 'kinds' => [ 'text', 'number', 'meta' ] ],
			'contains'     => [ 'word' => __( 'contains', 'dazont-ecom' ),              'sign' => '⊃',                                 'takes' => 'text',   'kinds' => [ 'text', 'meta' ] ],
			'not_contains' => [ 'word' => __( 'does not contain', 'dazont-ecom' ),      'sign' => '⊅',                                 'takes' => 'text',   'kinds' => [ 'text', 'meta' ] ],
		];
	}

	/**
	 * What a criterion is FOR.
	 *
	 * A shop is never working on everything at once. "Description too short"
	 * and "No main photograph" are both work, but one of them is why nobody
	 * finds the product and the other is why nobody buys it — and a shop whose
	 * quarter is about conversion needs to see its own half of the list
	 * without reading the other.
	 *
	 * Two, because two is what this shop actually separates. A third is one
	 * line here and nothing else: every screen reads this list rather than
	 * naming SEO and CRO of its own.
	 *
	 * @return array<string,array{label:string,what:string}>
	 */
	public static function goals(): array {
		return [
			'seo' => [
				'label' => __( 'SEO', 'dazont-ecom' ),
				'what'  => __( 'being found: what a search engine reads before anybody arrives.', 'dazont-ecom' ),
			],
			'cro' => [
				'label' => __( 'CRO', 'dazont-ecom' ),
				'what'  => __( 'turning a visit into an order: what somebody already on the page needs to decide.', 'dazont-ecom' ),
			],
		];
	}

	/** The goals of one row, only the ones this plugin knows. */
	public static function goals_of( array $row ): array {
		$known = array_keys( self::goals() );
		$has   = array_map( 'strval', (array) ( $row['goals'] ?? [] ) );
		return array_values( array_intersect( $known, $has ) );
	}

	/** Whether the shop reads its comparisons as symbols rather than words. */
	public static function signs(): bool {
		return ! empty( self::settings()['signs'] );
	}

	/**
	 * One comparison, written the way this shop asked for it.
	 *
	 * @param bool $words Force the words even where the shop reads symbols —
	 *                    for a criterion's own NAME, which is written once and
	 *                    must not read differently the week the setting is
	 *                    flipped.
	 */
	public static function op_label( string $op, bool $words = false ): string {
		$row = self::operators()[ $op ] ?? null;
		if ( ! $row ) {
			return $op;
		}
		return ( ! $words && self::signs() ) ? (string) $row['sign'] : (string) $row['word'];
	}

	/**
	 * What a text field's number MEANS.
	 *
	 * A description is judged in words and a SEO title in characters, because
	 * that is what each of them is actually short of. The unit is the field's,
	 * not the criterion's, so it cannot be set to something the field cannot
	 * answer.
	 */
	public static function unit_of( string $field ): string {
		return (string) ( self::fields()[ $field ]['unit'] ?? '' );
	}

	/**
	 * The question a field is asked when it is first chosen.
	 *
	 * Changing the field used to leave the comparison and the figure where the
	 * last field left them, which is how "variations with no photograph of
	 * their own is less than 120" gets built: a rule true of every product in
	 * the shop, that means nothing and finds everything. Each field says what
	 * it is normally asked; a field with no figure worth defaulting is asked
	 * whether it is empty, which is always a real question.
	 *
	 * @return array{0:string,1:int}
	 */
	public static function default_rule( string $field ): array {
		$meta = self::fields()[ $field ] ?? [];
		if ( ! empty( $meta['rule'] ) ) {
			return [ (string) $meta['rule'][0], (int) $meta['rule'][1] ];
		}
		return [ 'empty', 0 ];
	}

	/**
	 * The criteria as they ship.
	 *
	 * Rows, not code — the same shape the shop edits, so "restore the default"
	 * is putting these back and nothing else. What is shipped is what a shop
	 * of any kind would ask first; everything past that is the owner's.
	 */
	public static function default_rows(): array {
		return [
			[ 'id' => 'prod_desc',    'scope' => 'product', 'field' => 'product.description',       'test' => 'lt',    'value' => 120, 'note' => __( 'Write a real description on these products: it is what a search engine reads, and what a buyer reads before deciding.', 'dazont-ecom' ), 'find' => '', 'key' => '', 'goals' => [ 'seo', 'cro' ], 'on' => 1 ],
			[ 'id' => 'prod_short',   'scope' => 'product', 'field' => 'product.short_description', 'test' => 'empty', 'value' => 0,   'note' => __( 'Add the short description — it is the line beside the price, and the last thing read before Add to basket.', 'dazont-ecom' ), 'find' => '', 'key' => '', 'goals' => [ 'cro' ], 'on' => 1 ],
			[ 'id' => 'prod_main',    'scope' => 'product', 'field' => 'product.main_image',        'test' => 'empty', 'value' => 0,   'note' => __( 'Give these products a main photograph. A product with no picture is not bought and is not indexed.', 'dazont-ecom' ), 'find' => '', 'key' => '', 'goals' => [ 'cro', 'seo' ], 'on' => 1 ],
			[ 'id' => 'prod_shot_px', 'scope' => 'product', 'field' => 'product.main_image_side',   'test' => 'lt',    'value' => 800, 'note' => __( 'Replace these main photographs with larger ones: under 800 px they cannot be shown big anywhere.', 'dazont-ecom' ), 'find' => '', 'key' => '', 'goals' => [ 'cro' ], 'on' => 1 ],
			[ 'id' => 'prod_gallery', 'scope' => 'product', 'field' => 'product.gallery',           'test' => 'lt',    'value' => 3,   'note' => __( 'Add more photographs to these products, to improve the conversion rate.', 'dazont-ecom' ), 'find' => '', 'key' => '', 'goals' => [ 'cro' ], 'on' => 1 ],
			[ 'id' => 'prod_var_img', 'scope' => 'product', 'field' => 'product.variation_images', 'test' => 'gt', 'value' => 0, 'note' => __( 'Give these variations a photograph of their own — put the attribute in the box (attribute_pa_couleur, attribute_pa_version), then make them in the Image lab, one per colour.', 'dazont-ecom' ), 'find' => '', 'key' => '', 'goals' => [ 'cro' ], 'on' => 0 ],
			[ 'id' => 'prod_seo_t',   'scope' => 'product', 'field' => 'product.seo_title',         'test' => 'gt',    'value' => 60,  'note' => __( 'Shorten these SEO titles: past 60 characters Google cuts them off mid-sentence.', 'dazont-ecom' ), 'find' => '', 'key' => '', 'goals' => [ 'seo' ], 'on' => 0 ],
			[ 'id' => 'cat_desc',     'scope' => 'category', 'field' => 'category.description',      'test' => 'lt',    'value' => 150, 'note' => __( 'Write a real description on these categories — a category page with no text ranks for nothing.', 'dazont-ecom' ), 'find' => '', 'key' => '', 'goals' => [ 'seo' ], 'on' => 1 ],
			[ 'id' => 'cat_links',    'scope' => 'category', 'field' => 'category.links',            'test' => 'lt',    'value' => 2,   'note' => __( 'Point these categories at more of the shop, so a visitor and a crawler both have somewhere to go next.', 'dazont-ecom' ), 'find' => '', 'key' => '', 'goals' => [ 'seo' ], 'on' => 1 ],
			[ 'id' => 'post_links',   'scope' => 'post', 'field' => 'post.links',                'test' => 'lt',    'value' => 0,   'note' => __( 'Add internal links to these articles: a text that links nowhere passes nothing to the pages it is about.', 'dazont-ecom' ), 'find' => '', 'key' => '', 'goals' => [ 'seo' ], 'on' => 1 ],
			[ 'id' => 'post_stale',   'scope' => 'post', 'field' => 'post.updated',              'test' => 'gt',    'value' => 365, 'note' => __( 'Bring these articles up to date — a year-old page loses its ranking to the ones being kept.', 'dazont-ecom' ), 'find' => '', 'key' => '', 'goals' => [ 'seo' ], 'on' => 1 ],
		];
	}

	/** The criteria in force: the shop's own, or the shipped ones. */
	public static function rows(): array {
		$saved = self::settings()['rows'] ?? null;
		$rows  = is_array( $saved ) ? self::clean_rows( $saved ) : [];
		// The shipped rows go through the same cleaning as saved ones. They
		// carry no label of their own — a label is COMPUTED from the field, the
		// operator and the figure — so handing them over raw left every check
		// on a fresh install with an empty name: a blank heading on the list
		// screen, and a blank line in the report. A shop that had edited its
		// criteria once never saw it, which is why it lasted.
		return $rows ?: self::clean_rows( self::default_rows() );
	}

	/** Rows as a form posted them, made safe and complete. */
	public static function clean_rows( array $in ): array {
		$fields = self::fields();
		$ops    = self::operators();
		$out    = [];
		$seen   = [];
		foreach ( $in as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			// An article's length used to be a number of its own; it is the
			// length of its text, which the text field answers along with
			// everything else that can be asked of a text.
			$field = (string) ( $row['field'] ?? '' );
			$field = ( 'post.words' === $field ) ? 'post.content' : $field;
			// The custom field used to be three fields — a text one, a number
			// one and one that only looked for a photograph — which was this
			// plugin deciding what an ACF field is allowed to hold. One field
			// now, read as it is stored; the rows written against the old
			// three keep working and keep their key.
			$field = in_array( $field, [ 'product.meta_number', 'product.image_meta' ], true ) ? 'product.meta' : $field;
			// A criterion says which post type it is about. Written before
			// that was asked, it is about the one its field belongs to — and
			// an article criterion is about articles, which is what "post"
			// meant when it covered both.
			$scope = sanitize_key( (string) ( $row['scope'] ?? '' ) );
			$want  = (string) ( self::fields()[ $field ]['scope'] ?? 'product' );
			if ( '' === $scope || ! isset( self::scopes()[ $scope ] ) || self::family( $scope ) !== $want ) {
				$scope = ( 'post' === $want ) ? 'post' : $want;
			}
			if ( ! isset( $fields[ $field ] ) ) {
				continue; // a criterion reading nothing is not one.
			}
			$op = self::op_now( (string) ( $row['test'] ?? '' ) );
			// A comparison a field cannot answer is not saved as one: "contains"
			// asked of a count would be a criterion that never fires and never
			// says why.
			if ( ! isset( $ops[ $op ] ) || ! in_array( (string) $fields[ $field ]['kind'], (array) $ops[ $op ]['kinds'], true ) ) {
				$op = 'empty';
			}
			// A criterion is NAMED BY WHAT IT DOES, always, and there is no box
			// to type another name in. A hand-written title is one more thing
			// to keep in step — change the figure from 50 to 80 and "Description
			// too short" still says 50 to nobody — and it says nothing the rule
			// does not already say. What the shop writes instead is a
			// DESCRIPTION: what to do about it, which the rule cannot know.
			// The tiers are settled BEFORE the name, because the name has to
			// carry them: a criterion judged on 3, 4 or 6 by price cannot go
			// on calling itself "less than 6".
			$cond  = array_key_exists( 'cond', $row )
				? ( empty( $row['cond'] ) ? 0 : 1 )
				: (int) ! empty( self::stored_row( (string) ( $row['id'] ?? '' ) )['cond'] );
			$bands = self::bands_for( $row, $scope );
			$label = self::rule_named( [
				'field' => $field,
				'test'  => $op,
				'value' => (int) ( $row['value'] ?? 0 ),
				'find'  => (string) ( $row['find'] ?? '' ),
				'key'   => (string) ( $row['key'] ?? '' ),
				'cond'  => $cond,
				'bands' => $bands,
			], $fields[ $field ] );
			// The id is minted once and never again: the name follows the rule
			// and changes with it, and a reading filed under the old name must
			// not become a reading about nothing.
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = trim( sanitize_key( sanitize_title( $label ) ), '-_' ) ?: ( 'c' . ( count( $out ) + 1 ) );
			}
			while ( isset( $seen[ $id ] ) ) {
				$id .= '2';
			}
			$seen[ $id ] = true;
			$out[] = [
				'id'    => $id,
				'label' => mb_substr( $label, 0, 120 ),
				'note'  => mb_substr( sanitize_text_field( (string) ( $row['note'] ?? '' ) ), 0, 200 ),
				'scope' => $scope,
				'field' => $field,
				'test'  => $op,
				'value' => max( 0, min( 100000, (int) ( $row['value'] ?? 0 ) ) ),
				'find'  => mb_substr( sanitize_text_field( (string) ( $row['find'] ?? '' ) ), 0, 120 ),
				'key'   => ! empty( $fields[ $field ]['key'] ) ? sanitize_text_field( (string) ( $row['key'] ?? '' ) ) : '',
				'goals' => self::goals_for( $row, $id ),
				// The shop's own routine for this shortfall: which of its
				// image prompts, and how many of each. Written once here and
				// used on nine hundred products.
				// What a COMPLETE one looks like is not one number for the
				// whole catalogue. A cap at $16.90 and a plate carrier at $90
				// do not deserve the same gallery, and holding both to "3"
				// makes the count on the screen a figure nobody believes.
				'cond'  => $cond,
				'bands' => $bands,
				'on'    => empty( $row['on'] ) ? 0 : 1,
			];
		}
		return $out;
	}

	/**
	 * The conditions, in the order they are read — first match wins.
	 *
	 * "Product price between x to y : at least x gallery images. Et ajouter
	 * d'autres conditions." Each line is a whole sentence and means the same
	 * thing on its own as it does in the list: a range, a figure, done. The
	 * tiers this replaced were chained comparisons — "under 40", then "under
	 * 80" — where a line could not be read without reading the one above it,
	 * and that is exactly what made the screen unreadable.
	 *
	 * Every line carries its OWN field, so "price between 40 and 80" and "sold
	 * more than 20" can sit in the same list. Empty by default: a shop that
	 * sets none is held to one figure, as it always was.
	 *
	 * @return array<int,array{field:string,from:int,to:int,want:int}>
	 *         `to` is 0 for "and above" — there is no upper bound.
	 */
	private static function bands_for( array $row, string $scope ): array {
		if ( ! array_key_exists( 'bands', $row ) ) {
			// Absent from the form is not "none": the card always posts the
			// marker. A row arriving without it was saved by another screen,
			// and the shop's standard is not that screen's to erase.
			return (array) ( self::stored_row( (string) ( $row['id'] ?? '' ) )['bands'] ?? [] );
		}
		$fields = self::fields();
		$fam    = self::family( $scope );
		$out    = [];
		foreach ( (array) $row['bands'] as $band ) {
			if ( ! is_array( $band ) ) {
				continue;
			}
			$field = sanitize_text_field( (string) ( $band['field'] ?? '' ) );
			$known = $fields[ $field ] ?? null;
			// A condition is a RANGE, so it needs a number to measure. A text
			// answers "contains", not "between 40 and 80", and a field of
			// another post type cannot be asked of this object at all.
			if ( ! $known || 'number' !== (string) ( $known['kind'] ?? '' ) || $fam !== (string) ( $known['scope'] ?? '' ) ) {
				continue;
			}
			$from = max( 0, min( 100000, (int) ( $band['from'] ?? 0 ) ) );
			$to   = max( 0, min( 100000, (int) ( $band['to'] ?? 0 ) ) );
			// A range that ends before it starts is not a range. Kept as "and
			// above" rather than dropped, because a number typed the wrong way
			// round is a slip, and losing the whole line would hide it.
			if ( $to > 0 && $to <= $from ) {
				$to = 0;
			}
			$out[] = [
				'field' => $field,
				'from'  => $from,
				'to'    => $to,
				'want'  => max( 0, min( 100000, (int) ( $band['want'] ?? 0 ) ) ),
			];
			if ( count( $out ) >= 8 ) {
				break; // a standard nobody can read at a glance is not one.
			}
		}
		return $out;
	}

	/**
	 * One criterion as the shop HOLDS it, by id.
	 *
	 * Read from the stored option and never through rows(), which runs
	 * clean_rows() — asking it from inside clean_rows() is a loop with no way
	 * out. shots_for() learned that the hard way.
	 */
	private static function stored_row( string $id ): array {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return [];
		}
		foreach ( (array) ( self::settings()['rows'] ?? [] ) as $one ) {
			if ( is_array( $one ) && sanitize_key( (string) ( $one['id'] ?? '' ) ) === $id ) {
				return $one;
			}
		}
		return [];
	}

	/**
	 * WHICH condition placed this object, and what it asked of it.
	 *
	 * The grouping on the problem list is read from this and from nothing
	 * else, so a section heading can never disagree with the figure the
	 * product was actually judged by.
	 *
	 * @param mixed $object
	 * @return array{i:int,want:int,said:string} i is -1 for the plain rule and
	 *         -2 for an object no condition covers.
	 */
	public static function band_hit( array $row, string $scope, $object ): array {
		if ( empty( $row['cond'] ) ) {
			return [ 'i' => -1, 'want' => (int) ( $row['value'] ?? 0 ), 'said' => '' ];
		}
		foreach ( array_values( (array) ( $row['bands'] ?? [] ) ) as $i => $band ) {
			$m = self::measure( (string) ( $band['field'] ?? '' ), $scope, $object, '' );
			if ( ! is_numeric( $m ) ) {
				continue;
			}
			$m    = (float) $m;
			$from = (float) ( $band['from'] ?? 0 );
			$to   = (float) ( $band['to'] ?? 0 );
			// Half open, and zero places nothing: a product with no price is
			// not the cheapest product in the shop.
			if ( $m < $from || ( $to > 0 && $m >= $to ) || $m <= 0 ) {
				continue;
			}
			return [ 'i' => $i, 'want' => (int) ( $band['want'] ?? 0 ), 'said' => self::band_said( (array) $band ) ];
		}
		return [ 'i' => -2, 'want' => 0, 'said' => (string) __( 'No condition covers these', 'dazont-ecom' ) ];
	}

	/** One condition as a heading: "price 40 to 80". */
	public static function band_said( array $band ): string {
		$name = (string) ( self::fields()[ (string) ( $band['field'] ?? '' ) ]['label'] ?? '' );
		$from = (int) ( $band['from'] ?? 0 );
		$to   = (int) ( $band['to'] ?? 0 );
		return $to > 0
			/* translators: 1: field name, 2: lower bound, 3: upper bound */
			? sprintf( __( '%1$s %2$d to %3$d', 'dazont-ecom' ), $name, $from, $to )
			/* translators: 1: field name, 2: lower bound */
			: sprintf( __( '%1$s %2$d and above', 'dazont-ecom' ), $name, $from );
	}

	/**
	 * The figure THIS object is held to.
	 *
	 * With Conditional off, the criterion's own figure and nothing else. With
	 * it on, THE CONDITIONS ARE THE RULE: the first whose range contains the
	 * object wins, and an object none of them covers is not judged at all —
	 * null, and it never falls short. A figure sitting beside the conditions
	 * was a second rule nobody had written, and the screen could not say which
	 * of the two a product had been held to.
	 *
	 * It costs nothing: every field a condition can be written on is one the
	 * scan already loads.
	 *
	 * @param mixed $object The product, category or post being judged.
	 * @return float|null null when no condition covers it.
	 */
	private static function want_for( array $row, string $scope, $object ): ?float {
		$hit = self::band_hit( $row, $scope, $object );
		return -2 === $hit['i'] ? null : (float) $hit['want'];
	}

	/**
	 * A SECOND, conditional reading — off unless the shop asks for it.
	 *
	 * By default a criterion is one rule and one figure, and that is the whole
	 * of the screen. A shop that needs more ticks one box and gets a list of
	 * conditions, each a whole sentence: "price between 0 and 40 : at least 3
	 * photographs". Unticked, the box hides them and the criterion is judged
	 * on its plain figure — the conditions are kept, not thrown away, so
	 * turning it back on does not mean typing them again.
	 */
	private static function bands_block( array $row, string $opt, string $index, string $field, string $scope ): string {
		// Always drawn, shown only where it applies. Rendered conditionally it
		// appeared for a criterion already saved on a count and NEVER for one
		// switched to a count in the browser — a control the shop only
		// discovers after saving and reloading is a control it does not have.
		// Wherever the rule holds a FIGURE. It used to be "wherever the field
		// is a number", which gave conditions to the gallery and denied them
		// to "description is less than 120 words" — the same kind of question,
		// on a criterion that needs them just as much. A rule with no figure
		// ("is empty", "contains") has nothing to put in tiers.
		$counts = 'number' === (string) ( self::operators()[ self::op_now( (string) ( $row['test'] ?? '' ) ) ]['takes'] ?? '' );
		$bands  = (array) ( $row['bands'] ?? [] );
		$on     = ! empty( $row['cond'] );

		$out  = '<div class="dze-diag-cond" style="margin:6px 0 0;' . ( $counts ? '' : 'display:none;' ) . '">';
		// The marker goes first so the key always arrives: unticked, a checkbox
		// posts nothing at all, and "nothing" would be read as "another screen
		// saved this" — which is how a setting gets quietly given back.
		$out .= '<input type="hidden" name="' . esc_attr( $opt . '[rows][' . $index . '][cond]' ) . '" value="0" />';
		$out .= '<label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">'
			. '<input type="checkbox" class="dze-diag-condon" value="1"'
			. ' name="' . esc_attr( $opt . '[rows][' . $index . '][cond]' ) . '"' . checked( $on, true, false ) . '> '
			. esc_html__( 'Conditional', 'dazont-ecom' ) . '</label>';
		$out .= '<div class="dze-diag-bands" style="margin:6px 0 0;' . ( $on ? '' : 'display:none;' ) . '">';
		$out .= '<input type="hidden" name="' . esc_attr( $opt . '[rows][' . $index . '][bands][__none__][field]' ) . '" value="" />';
		$out .= '<div class="dze-diag-bandrows">';
		foreach ( array_values( $bands ) as $i => $band ) {
			$out .= self::band_row( $opt, $index, (string) $i, (array) $band, $field, $scope );
		}
		// The one a press copies, always present and always disabled — a
		// disabled field is not submitted, so it adds no condition of its own.
		// It is here rather than in the JavaScript because a condition's markup
		// must live in ONE place: a control added above and forgotten below is
		// a condition that silently loses a value.
		$out .= self::band_row( $opt, $index, '__B__', [], $field, $scope, true );
		$out .= '</div>';
		$out .= '<button type="button" class="button button-small dze-diag-bandadd">'
			. esc_html__( 'Add a condition', 'dazont-ecom' ) . '</button>';
		$out .= ' <span class="description">'
			. esc_html__( 'First one that fits wins. Anything no condition covers is not counted.', 'dazont-ecom' )
			. '</span>';
		$out .= '</div></div>';
		return $out;
	}

	/**
	 * What a condition's figure COUNTS, named as the shop names it.
	 *
	 * The unit — "photographs", "words", "px" — because that is what the rule
	 * above already says and the two must never disagree. The field's own name
	 * was tried and reads badly on half of them: "at least 200 description".
	 */
	private static function band_counts( string $field ): string {
		$unit = self::unit_of( $field );
		return '' !== $unit ? $unit : (string) ( self::fields()[ $field ]['label'] ?? '' );
	}

	/**
	 * One condition, as a sentence: price between 0 and 40 : at least 3.
	 *
	 * "and above" is an empty upper box rather than a second control, because
	 * the last range of a list is always open and a shop should not have to
	 * say so twice.
	 */
	private static function band_row( string $opt, string $index, string $i, array $band, string $field, string $scope, bool $proto = false ): string {
		$name = static fn( string $k ): string => esc_attr( $opt . '[rows][' . $index . '][bands][' . $i . '][' . $k . ']' );
		$off  = $proto ? ' disabled="disabled"' : '';
		$on   = (string) ( $band['field'] ?? '' );
		$fam  = self::family( $scope );

		$out  = '<div class="dze-diag-bandrow' . ( $proto ? ' dze-diag-bandproto' : '' ) . '"'
			. ' style="display:' . ( $proto ? 'none' : 'flex' ) . ';align-items:center;gap:6px;margin:0 0 4px;flex-wrap:wrap;">';
		// "If … → at least …". Without the two words the row read as though the
		// menu named the thing being counted: on a gallery criterion it showed
		// "main photograph between 0 and 0 : at least 0 photographs", and the
		// only sensible reading of that is the wrong one.
		$out .= '<span>' . esc_html__( 'If', 'dazont-ecom' ) . '</span>';
		$out .= '<select' . $off . ' class="dze-diag-bandfield" name="' . $name( 'field' ) . '" style="max-width:220px;">';
		$dze_all = self::fields();
		uasort( $dze_all, static fn( array $a, array $b ): int => strcasecmp( (string) ( $a['label'] ?? '' ), (string) ( $b['label'] ?? '' ) ) );
		foreach ( $dze_all as $fid => $meta ) {
			if ( 'number' !== (string) $meta['kind'] ) {
				continue;
			}
			$out .= '<option value="' . esc_attr( $fid ) . '" data-scope="' . esc_attr( (string) $meta['scope'] ) . '"'
				. ( $fid === $field ? ' data-self="1"' : '' )
				. ( ( $fid === $field || $fam !== (string) $meta['scope'] ) ? ' class="dze-diag-bandhide" disabled="disabled"' : '' )
				. selected( $fid, $on, false ) . '>' . esc_html( (string) $meta['label'] ) . '</option>';
		}
		$out .= '</select>';
		$out .= '<span>' . esc_html__( 'is between', 'dazont-ecom' ) . '</span>';
		$out .= '<input type="number"' . $off . ' min="0" step="1" style="width:80px;" name="' . $name( 'from' ) . '"'
			. ' value="' . esc_attr( (string) (int) ( $band['from'] ?? 0 ) ) . '" />';
		$out .= '<span>' . esc_html__( 'and', 'dazont-ecom' ) . '</span>';
		// Blank means there is no upper end. The word for that does not fit in
		// a number box, so the box stays a box and the hint sits on it.
		$out .= '<input type="number"' . $off . ' min="0" step="1" style="width:80px;" name="' . $name( 'to' ) . '"'
			. ' value="' . esc_attr( (int) ( $band['to'] ?? 0 ) > 0 ? (string) (int) $band['to'] : '' ) . '"'
			. ' title="' . esc_attr__( 'Leave empty for "and above"', 'dazont-ecom' ) . '"'
			. ' placeholder="&infin;" />';
		$out .= '<span>&rarr; ' . esc_html__( 'at least', 'dazont-ecom' ) . '</span>';
		$out .= '<input type="number"' . $off . ' min="0" step="1" style="width:80px;" name="' . $name( 'want' ) . '"'
			. ' value="' . esc_attr( (string) (int) ( $band['want'] ?? 0 ) ) . '" />';
		// The field being COUNTED, named — "at least 3 gallery photographs".
		// The unit alone ("photographs") left the sentence with two fields in
		// it and only one of them named.
		$out .= '<span class="dze-diag-bandunit description">' . esc_html( self::band_counts( $field ) ) . '</span>';
		$out .= '<button type="button" class="button-link dze-diag-banddel" style="color:#b32d2e;">'
			. esc_html__( 'Remove', 'dazont-ecom' ) . '</button>';
		$out .= '</div>';
		return $out;
	}

	/**
	 * What a row is FOR, taking a shop that has never been asked into account.
	 *
	 * The card always posts the key, so an empty answer means "neither" and is
	 * kept as one — some criteria really are neither, and being told so is
	 * better than being given a goal we made up. A row that arrives with no
	 * `goals` key at all is a row saved by a version that had none: a shipped
	 * criterion takes the goals it ships with, and a criterion the shop wrote
	 * itself takes both, because the alternative is a shop that reads its own
	 * list and finds half of it missing under either heading.
	 */
	private static function goals_for( array $row, string $id ): array {
		if ( array_key_exists( 'goals', $row ) ) {
			return self::goals_of( $row );
		}
		foreach ( self::default_rows() as $shipped ) {
			if ( $id === (string) $shipped['id'] ) {
				return self::goals_of( $shipped );
			}
		}
		return array_keys( self::goals() );
	}

	/**
	 * A criterion saved before the comparisons existed, read as one now.
	 *
	 * The three canned rules were "empty", "fewer than N words" and "fewer
	 * than N" — all three are `less than` against the field's own unit, and a
	 * shop that saved them keeps exactly the criteria it saved. Migration on
	 * READ, so nothing has to be rewritten in the database and a version put
	 * back does not find rows it cannot understand.
	 */
	private static function op_now( string $op ): string {
		$was = [ 'min_words' => 'lt', 'min_count' => 'lt' ];
		return (string) ( $was[ $op ] ?? $op );
	}

	/**
	 * The screen that FIXES one kind of shortfall.
	 *
	 * A to-do list that says what is wrong and not where to go is a list you
	 * read twice. Every line names its tool and links straight at it — and
	 * there is one map, read from the FIELD, so a criterion the shop invents
	 * tomorrow arrives with its tool already attached.
	 *
	 * @return array{label:string,url:string}
	 */
	private static function tool_for( string $field ): array {
		$tab = static fn( string $t ): string => add_query_arg(
			[ 'page' => class_exists( 'DZE_Marketing_Ai' ) ? DZE_Marketing_Ai::MENU_SLUG : 'dazont-ecom-ai', 'tab' => $t ],
			admin_url( 'admin.php' )
		);
		$bulk = add_query_arg(
			[ 'post_type' => 'product', 'page' => class_exists( 'DZE_Content' ) ? DZE_Content::BULK_SLUG : 'dazont-content-bulk' ],
			admin_url( 'edit.php' )
		);
		$scope = (string) ( self::fields()[ $field ]['scope'] ?? 'product' );
		if ( 'category' === $scope ) {
			return [ 'label' => __( 'Categories', 'dazont-ecom' ), 'url' => $tab( 'categories' ) ];
		}
		if ( 'post' === $scope ) {
			// Only the links have a tool of their own; the rest of an article
			// is written where articles are written.
			return 'post.links' === $field
				? [ 'label' => __( 'Automation', 'dazont-ecom' ), 'url' => $tab( 'automation' ) ]
				: [ 'label' => __( 'Articles', 'dazont-ecom' ), 'url' => admin_url( 'edit.php' ) ];
		}
		// PHOTOGRAPHS HAVE NO SCREEN OF THEIR OWN HERE. The image lab is an
		// experiment against fal.ai, finished and standing on its own; wiring
		// other functions into it would make a bench into a dependency. A
		// product's photographs are worked on where that product is opened, so
		// the row's own link is the whole of the answer and there is no second
		// button pointing at the same place.
		if ( false !== strpos( $field, 'image' ) || 'product.gallery' === $field ) {
			return [];
		}
		if ( in_array( $field, [ 'product.price', 'product.sale_price' ], true ) ) {
			return [ 'label' => __( 'Discounts', 'dazont-ecom' ), 'url' => $tab( 'discounts' ) ];
		}
		if ( in_array( $field, [ 'product.stock', 'product.sku', 'product.weight', 'product.categories', 'product.tags', 'product.attributes', 'product.variations', 'product.reviews', 'product.rating', 'product.age' ], true ) ) {
			return [ 'label' => __( 'Products', 'dazont-ecom' ), 'url' => admin_url( 'edit.php?post_type=product' ) ];
		}
		return [ 'label' => __( 'Bulk writing', 'dazont-ecom' ), 'url' => $bulk ];
	}

	/** Where each person's last view of each list is kept. */
	private const VIEW_META = '_dze_diag_view';

	/**
	 * How this person last looked at this list.
	 *
	 * On the USER and not in an option: two people working through the same
	 * shop do not sort it the same way, and one of them re-sorting it is not a
	 * change to the shop.
	 *
	 * @return array{by?:string,dir?:string,show?:string}
	 */
	public static function kept_view( string $id ): array {
		$all = get_user_meta( get_current_user_id(), self::VIEW_META, true );
		$all = is_array( $all ) ? $all : [];
		$one = $all[ $id ] ?? [];
		return is_array( $one ) ? $one : [];
	}

	/** Remembers it, and only when it has actually changed. */
	public static function keep_view( string $id, array $view ): void {
		$user = get_current_user_id();
		if ( ! $user || '' === $id ) {
			return;
		}
		$all = get_user_meta( $user, self::VIEW_META, true );
		$all = is_array( $all ) ? $all : [];
		$now = [
			'by'   => (string) ( $view['by'] ?? 'found' ),
			'dir'  => (string) ( $view['dir'] ?? 'desc' ),
			'show' => (string) ( $view['show'] ?? 'todo' ),
		];
		if ( ( $all[ $id ] ?? [] ) === $now ) {
			return; // a write on every page load is a write for nothing.
		}
		$all[ $id ] = $now;
		update_user_meta( $user, self::VIEW_META, $all );
	}

	/** Where the criteria themselves are edited. */
	public static function settings_url(): string {
		return add_query_arg(
			[ 'page' => class_exists( 'DZE_Marketing_Ai' ) ? DZE_Marketing_Ai::MENU_SLUG : 'dazont-ecom-ai', 'tab' => 'diagnostic' ],
			admin_url( 'admin.php' )
		);
	}

	public static function checks(): array {
		$fields = self::fields();
		$out    = [];
		foreach ( self::rows() as $row ) {
			if ( empty( $row['on'] ) ) {
				continue;
			}
			$out[ (string) $row['id'] ] = [
				'scope' => (string) $row['scope'],
				'label' => (string) $row['label'],
				'why'   => self::rule_said( $row, $fields[ $row['field'] ] ),
				'tool'  => self::tool_for( (string) $row['field'], (string) $row['scope'] ),
				'goals' => self::goals_of( $row ),
				'note'  => (string) ( $row['note'] ?? '' ),
				'row'   => $row,
			];
		}
		return $out;
	}

	/** One criterion said in words, for the screen. */
	private static function rule_said( array $row, array $field ): string {
		$scope = (string) ( $row['scope'] ?? '' );
		$named = trim( (string) ( self::scopes()[ $scope ] ?? '' ) . ' · ' . self::field_named( $row, $field ), ' ·' );
		return trim( $named . ' ' . self::rule_clause( $row ) ) . '.';
	}

	/**
	 * What the field is CALLED on this row.
	 *
	 * A criterion on a custom field is named after the key it reads, never
	 * after the words "custom field": two rules on two different keys read the
	 * same otherwise, on the screen and in the name they are given — and the
	 * one thing the owner needs to see on that line is WHICH field.
	 */
	private static function field_named( array $row, array $field ): string {
		$key   = trim( (string) ( $row['key'] ?? '' ) );
		$label = (string) ( $field['label'] ?? '' );
		if ( empty( $field['key'] ) || '' === $key ) {
			return $label;
		}
		// The custom field IS its key — there is nothing else to call it. Every
		// other keyed field keeps its own name and carries the key beside it:
		// "variations with no photograph of their own (attribute_pa_couleur)"
		// says which variations, where the key alone would say nothing.
		return ! empty( $field['keyname'] ) ? $key : $label . ' (' . $key . ')';
	}

	/**
	 * The comparison half of a criterion, without the field's name.
	 *
	 * One source for it, because the same words are printed twice: on the
	 * diagnostic ("Product · description is less than 120 words.") and on the
	 * shut card in the settings ("Product · description — is less than 120
	 * words"). Written twice they would drift, and the screen would explain
	 * one thing while the reading did another.
	 */
	private static function rule_clause( array $row, bool $words = false ): string {
		$op = self::op_now( (string) ( $row['test'] ?? 'empty' ) );
		if ( 'post.links' === ( $row['field'] ?? '' ) && 'lt' === $op && 0 === (int) ( $row['value'] ?? 0 ) ) {
			return __( 'holds fewer links than its own length calls for', 'dazont-ecom' );
		}
		$takes = (string) ( self::operators()[ $op ]['takes'] ?? '' );
		if ( 'text' === $takes ) {
			return self::op_label( $op, $words ) . ' "' . (string) ( $row['find'] ?? '' ) . '"';
		}
		if ( 'number' === $takes ) {
			// The unit is the field's own and can be blank (a price, a weight),
			// so the clause is assembled rather than templated: "is less than
			// 800 px" and "is more than 50" both have to come out clean.
			// Only the conditions actually in force, and only the ones with a
			// figure on them: a half-typed condition put "0/3" in the heading
			// the moment it was added, which reads as a criterion asking for
			// nothing.
			$figures = [];
			if ( ! empty( $row['cond'] ) ) {
				foreach ( (array) ( $row['bands'] ?? [] ) as $band ) {
					$n = (int) ( $band['want'] ?? 0 );
					if ( $n > 0 ) {
						$figures[] = $n;
					}
				}
			}
			// The plain figure only when it is the rule. With Conditional on it
			// is not one — it is not even on the screen — and a name carrying
			// it would be naming something nothing is judged by.
			if ( ! $figures ) {
				$figures[] = (int) ( $row['value'] ?? 0 );
			}
			// A criterion held to tiers must not go on calling itself by one
			// of them. "is less than 6 photographs" on a screen where a $16.90
			// cap is judged on 3 is a heading that stopped being true, and a
			// heading nobody can trust is worse than none.
			$said = count( $figures ) > 1
				? implode( '/', array_map( 'strval', $figures ) )
				: (string) $figures[0];
			return trim( self::op_label( $op, $words ) . ' ' . $said . ' ' . self::unit_of( (string) ( $row['field'] ?? '' ) ) );
		}
		return self::op_label( $op, $words );
	}

	/**
	 * A name for a criterion nobody named.
	 *
	 * The field and the comparison already say what the rule is, so the rule
	 * says its own name: "Price is empty", "Description is less than 120
	 * words". Not the post type — the card sits under its type's heading and
	 * the sentence beside the name repeats it. Renaming it is typing over it.
	 */
	private static function rule_named( array $row, array $field ): string {
		// In words, never in symbols: a name is written once and stored, and
		// "Price < 800 px" would read as somebody else's criterion the week
		// the symbols setting is flipped back.
		$said = trim( self::field_named( $row, $field ) . ' ' . self::rule_clause( $row, true ) );
		$said = mb_strtoupper( mb_substr( $said, 0, 1 ) ) . mb_substr( $said, 1 );
		return mb_substr( $said, 0, 120 ) ?: __( 'Criterion', 'dazont-ecom' );
	}

	// =========================================================================
	// The reading
	// =========================================================================

	/**
	 * The last reading: when, how much was read, and how many fall short.
	 *
	 * Deliberately small. The menu badge reads it on every admin page, and a
	 * row holding eleven thousand ids is not something to load to print one
	 * number — the lists live in an option of their own, read only by the
	 * screen that shows them.
	 */
	public static function census(): array {
		$c = get_option( self::OPT_CENSUS, [] );
		return is_array( $c ) && isset( $c['checks'] ) ? $c : [ 'at' => 0, 'seen' => [], 'checks' => [] ];
	}

	/** The objects behind the counts. Heavy, and asked for only on the list screen. */
	public static function lists(): array {
		$l = get_option( self::OPT_LISTS, [] );
		return is_array( $l ) ? $l : [];
	}

	/** Where one criterion's list of objects is kept. */
	public static function list_option( string $id ): string {
		return self::OPT_LISTS . '_' . sanitize_key( $id );
	}

	/**
	 * The objects one criterion found, and only that criterion's.
	 *
	 * Its own option, never autoloaded, read when its list is opened. The
	 * single option that held every list together was read in full to draw
	 * fifty rows of one of them.
	 *
	 * @return int[]
	 */
	public static function list_of( string $id ): array {
		$own = get_option( self::list_option( $id ), null );
		if ( is_array( $own ) ) {
			return array_values( array_filter( array_map( 'absint', $own ) ) );
		}
		// A shop whose last reading predates the split still has its lists in
		// the old option. Read from there until the next scan writes them out
		// properly — a screen must not go empty because the storage moved.
		$was = (array) ( self::lists()[ $id ] ?? [] );
		return array_values( array_filter( array_map( 'absint', $was ) ) );
	}

	/**
	 * Reads the whole shop once.
	 *
	 * In cron, or on an explicit click. Never on a page somebody is waiting
	 * for otherwise: a thousand products is a thousand descriptions to weigh,
	 * and that is a job, not a page load.
	 */
	/**
	 * The language the shop is read in, or '' when there is only one.
	 *
	 * WPML's own default language, asked through the plugin's own helper so
	 * there is one answer to this question on this site and not two.
	 */
	public static function main_language(): string {
		if ( ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			return '';
		}
		return DZE_Wpml::default_language();
	}

	/** That language written the way a person reads it ("English", not "en"). */
	private static function language_name( string $code ): string {
		if ( '' === $code || ! class_exists( 'DZE_Wpml' ) ) {
			return $code;
		}
		foreach ( DZE_Wpml::get_active_languages() as $one ) {
			if ( $code === ( $one['code'] ?? '' ) ) {
				return (string) ( $one['english_name'] ?: ( $one['native_name'] ?: $code ) );
			}
		}
		return $code;
	}

	public static function scan(): array {
		if ( get_transient( self::LOCK ) ) {
			return self::census();
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$checks = self::checks();
		$hits   = [];
		$unread = [];
		$seen   = array_fill_keys( array_keys( self::scopes() ), 0 );
		// The shop is read in its MAIN language and nowhere else. A translation
		// is not a product waiting to be written: it is WPML's copy of one, and
		// counting it would report five thousand products on a shop of a
		// thousand and send the owner to fix a French description that is meant
		// to be translated, not written. The linking pass already reads its
		// articles this way; products and categories now do the same, so the
		// two screens can never disagree about the same shop.
		$lang = self::main_language();
		$back = '' !== $lang ? DZE_Wpml::current_language() : '';
		if ( '' !== $lang ) {
			do_action( 'wpml_switch_language', $lang );
		}
		try {
			foreach ( array_keys( self::scopes() ) as $scope ) {
				$wanted = array_filter( $checks, static fn( array $c ): bool => $scope === ( $c['scope'] ?? '' ) );
				if ( ! $wanted ) {
					continue; // a post type no criterion asks about is a post type nobody reads.
				}
				// Which of this type's things are IN the main language. Asked
				// once per post type, and never trusted to WPML's query
				// filters: they are not in place in cron or in admin-ajax,
				// which is exactly where this pass runs.
				$mine = '' === $lang ? null : DZE_Wpml::ids_in_language( self::element_type( $scope ), $lang );
				if ( '' !== $lang && null === $mine ) {
					$unread[] = $scope;
				}
				if ( 'product' === $scope ) {
					self::scan_products( $wanted, $scope, $hits, $seen, $mine );
				} elseif ( 'category' === $scope ) {
					self::scan_categories( $wanted, $scope, $hits, $seen, $mine );
				} else {
					self::scan_posts( $wanted, $scope, $hits, $seen, $mine );
				}
			}
		} finally {
			if ( '' !== $lang ) {
				do_action( 'wpml_switch_language', '' !== $back ? $back : null );
			}
			delete_transient( self::LOCK );
		}
		// Whether the count really is the main language's. A number nobody can
		// account for is a number nobody believes, and this is the one thing
		// that decides it.
		$out   = [ 'at' => time(), 'lang' => $lang, 'every' => $unread, 'seen' => $seen, 'short' => array_fill_keys( array_keys( self::scopes() ), 0 ), 'checks' => [] ];
		$lists = [];
		// How many THINGS need work, not how many criteria fired: a product
		// short of four things is one product to open, and the sum of the
		// criteria says four. Counted here, where the full lists are still in
		// hand, rather than from the capped ones the screen reads.
		$short = array_fill_keys( array_keys( self::scopes() ), [] );
		foreach ( $checks as $id => $meta ) {
			$ids                  = array_values( array_unique( (array) ( $hits[ $id ] ?? [] ) ) );
			$out['checks'][ $id ] = count( $ids );
			$lists[ $id ]         = array_slice( $ids, 0, self::KEEP_IDS );
			// Its own row, and never autoloaded: a list of twenty thousand ids
			// read on every request of the whole site is how a plugin makes a
			// shop slow without doing anything.
			update_option( self::list_option( $id ), $lists[ $id ], false );
			$scope = (string) $meta['scope'];
			foreach ( $ids as $one ) {
				$short[ $scope ][ (int) $one ] = true;
			}
		}
		// The same count, asked per goal: how many THINGS need work for SEO,
		// how many for CRO. Deduplicated here, where the full lists are still
		// in hand — a product short of three CRO criteria is one product to
		// open, and adding the three counts up would say three.
		$by_goal = array_fill_keys( array_keys( self::goals() ), [] );
		foreach ( $checks as $id => $meta ) {
			foreach ( (array) ( $meta['goals'] ?? [] ) as $goal ) {
				if ( ! isset( $by_goal[ $goal ] ) ) {
					continue;
				}
				foreach ( (array) ( $hits[ $id ] ?? [] ) as $one ) {
					$by_goal[ $goal ][ (string) $meta['scope'] . ':' . (int) $one ] = true;
				}
			}
		}
		$out['goals'] = array_map( 'count', $by_goal );
		foreach ( $short as $scope => $set ) {
			$out['short'][ $scope ] = count( $set );
		}
		update_option( self::OPT_CENSUS, $out, false );
		update_option( self::OPT_LISTS, $lists, false );
		return $out;
	}

	/** @param array<string,int[]> $hits */
	private static function scan_products( array $wanted, string $scope, array &$hits, array &$seen, ?array $mine = null ): void {
		if ( ! post_type_exists( 'product' ) ) {
			return;
		}
		// What the criteria in force actually need loading. Categories and
		// tags cost a term query per page and photograph sizes cost a meta
		// query per page — neither is paid for by a shop that asks for
		// neither.
		$fields = [];
		foreach ( $wanted as $check ) {
			$fields[ (string) ( $check['row']['field'] ?? '' ) ] = true;
		}
		$needs_terms = isset( $fields['product.categories'] ) || isset( $fields['product.tags'] );
		$needs_size  = isset( $fields['product.main_image_width'] )
			|| isset( $fields['product.main_image_height'] )
			|| isset( $fields['product.main_image_side'] );

		$page = 1;
		do {
			$q = new WP_Query( [
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => 200,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => $needs_terms,
				'update_post_meta_cache' => true,
				// WPML narrows the query to the language the scan switched to,
				// and it does that through the very clauses `suppress_filters`
				// turns off. A shop without WPML keeps the query it had.
				'suppress_filters'       => '' === self::main_language(),
			] );
			if ( $needs_size ) {
				self::prime_thumbs( $q->posts );
			}
			foreach ( $q->posts as $post ) {
				// A translation is not a product waiting to be written. It is
				// skipped before it is counted, or the shop is told it has
				// nine thousand products and every figure on the screen is
				// out by the number of languages it sells in.
				if ( null !== $mine && ! isset( $mine[ (int) $post->ID ] ) ) {
					continue;
				}
				$seen[ $scope ]++;
				foreach ( $wanted as $id => $check ) {
					if ( self::fails( (array) $check['row'], 'product', $post ) ) {
						$hits[ $id ][] = (int) $post->ID;
					}
				}
			}
			$page++;
		} while ( $q->post_count > 0 );
	}

	/**
	 * How many words a text holds.
	 *
	 * Counted on letters rather than with str_word_count(), which reads a
	 * French description as a shorter English one: "matériel léger" is two
	 * words, and the C function counts the accented halves as their own.
	 */
	/**
	 * The metadata of a page of products' main photographs, in one query.
	 *
	 * Without this, asking for the photograph's size is one query per product
	 * — a thousand queries to answer one criterion, which is exactly the shape
	 * of thing that takes a shop down at the worst moment.
	 *
	 * @param WP_Post[] $posts
	 */
	private static function prime_thumbs( array $posts ): void {
		$ids = [];
		foreach ( $posts as $post ) {
			$id = (int) get_post_thumbnail_id( (int) $post->ID );
			if ( $id ) {
				$ids[ $id ] = true;
			}
		}
		if ( $ids ) {
			update_meta_cache( 'post', array_keys( $ids ) );
		}
	}

	public static function words( string $html ): int {
		return (int) preg_match_all( '/\p{L}+/u', wp_strip_all_tags( $html ) );
	}

	/**
	 * What one page actually holds, for one field.
	 *
	 * @param mixed $object A WP_Post, a term, or the linking pass's own row.
	 * @return array{text:string,count:int}
	 */
	/**
	 * What one field ANSWERS on one thing.
	 *
	 * A string for a field read as text, a number for a field read as a
	 * number — never both, so nothing downstream has to guess which half of
	 * the answer it is looking at.
	 *
	 * Everything here is answered from caches primed a page at a time: no
	 * query is made inside this function, because it is called once per
	 * criterion per product and the shop has thousands of them.
	 *
	 * @return string|float
	 */
	private static function measure( string $field, string $scope, $object, string $key ) {
		if ( 'product' === $scope && $object instanceof WP_Post ) {
			return self::measure_product( $field, $object, $key );
		}
		if ( 'category' === $scope && is_object( $object ) ) {
			$text = (string) ( $object->description ?? '' );
			if ( 'category.links' === $field ) {
				return (float) preg_match_all( '/<a\s[^>]*href=/i', $text );
			}
			if ( 'category.products' === $field ) {
				return (float) ( $object->count ?? 0 );
			}
			return $text;
		}
		if ( 'post' === $scope && $object instanceof WP_Post ) {
			return self::measure_post( $field, $object, $key );
		}
		return '';
	}

	/** @return string|float */
	private static function measure_post( string $field, WP_Post $post, string $key ) {
		$pid  = (int) $post->ID;
		$meta = static fn( string $k ): string => (string) get_post_meta( $pid, $k, true );
		$days = static function ( string $when ): float {
			$at = strtotime( $when ) ?: 0;
			return $at ? (float) floor( ( time() - $at ) / DAY_IN_SECONDS ) : 0.0;
		};
		switch ( $field ) {
			case 'post.title':
				return (string) $post->post_title;
			case 'post.content':
				return (string) $post->post_content;
			case 'post.excerpt':
				return (string) $post->post_excerpt;
			case 'post.seo_title':
			case 'post.seo_desc':
				$keys = class_exists( 'DZE_Content' ) ? DZE_Content::seo_keys() : [];
				$seo  = (string) ( $keys[ 'post.seo_title' === $field ? 'title' : 'desc' ] ?? '' );
				return '' !== $seo ? $meta( $seo ) : '';
			case 'post.meta':
				return '' !== $key ? get_post_meta( $pid, $key, true ) : '';
			case 'post.updated':
				return $days( (string) $post->post_modified_gmt );
			case 'post.age':
				return $days( (string) $post->post_date_gmt );
			case 'post.main_image':
				return get_post_thumbnail_id( $pid ) ? 1.0 : 0.0;
			case 'post.images':
				return (float) preg_match_all( '/<img\b/i', (string) $post->post_content );
			case 'post.headings':
				return (float) preg_match_all( '/<h[23]\b/i', (string) $post->post_content );
			case 'post.comments':
				return (float) $post->comment_count;
			case 'post.links':
				return (float) preg_match_all( '/<a\s[^>]*href=/i', (string) $post->post_content );
		}
		return '';
	}

	/** @return string|float */
	private static function measure_product( string $field, WP_Post $post, string $key ) {
		$pid  = (int) $post->ID;
		$meta = static fn( string $k ): string => (string) get_post_meta( $pid, $k, true );
		switch ( $field ) {
			case 'product.title':
				return (string) $post->post_title;
			case 'product.description':
				return (string) $post->post_content;
			case 'product.short_description':
				return (string) $post->post_excerpt;
			case 'product.seo_title':
			case 'product.seo_desc':
				$keys = class_exists( 'DZE_Content' ) ? DZE_Content::seo_keys() : [];
				$seo  = (string) ( $keys[ 'product.seo_title' === $field ? 'title' : 'desc' ] ?? '' );
				return '' !== $seo ? $meta( $seo ) : '';
			case 'product.sku':
				return $meta( '_sku' );
			case 'product.meta':
				// Raw, not cast: an image field holds an id, a gallery holds a
				// list, a true/false holds "1". Casting it here would decide
				// what the shop's fields are allowed to be.
				return '' !== $key ? get_post_meta( $pid, $key, true ) : '';
			case 'product.main_image':
				return get_post_thumbnail_id( $pid ) ? 1.0 : 0.0;
			case 'product.main_image_width':
			case 'product.main_image_height':
			case 'product.main_image_side':
				$size = self::thumb_size( $pid );
				if ( 'product.main_image_width' === $field ) {
					return (float) $size['w'];
				}
				if ( 'product.main_image_height' === $field ) {
					return (float) $size['h'];
				}
				// The smallest side is the one that decides whether a
				// photograph can be shown large anywhere: a 2000×400 banner is
				// not a 2000px product shot.
				return (float) min( $size['w'], $size['h'] );
			case 'product.gallery':
				return (float) count( array_filter( array_map( 'absint', explode( ',', $meta( '_product_image_gallery' ) ) ) ) );
			case 'product.links':
				return (float) preg_match_all( '/<a\s[^>]*href=/i', (string) $post->post_content );
			case 'product.price':
				return (float) $meta( '_price' );
			case 'product.sale_price':
				return (float) $meta( '_sale_price' );
			case 'product.stock':
				return (float) $meta( '_stock' );
			case 'product.weight':
				return (float) $meta( '_weight' );
			case 'product.reviews':
				return (float) $meta( '_wc_review_count' );
			case 'product.rating':
				return (float) $meta( '_wc_average_rating' );
			case 'product.attributes':
				$att = maybe_unserialize( $meta( '_product_attributes' ) );
				return (float) ( is_array( $att ) ? count( $att ) : 0 );
			case 'product.variations':
				return (float) ( self::variation_counts()[ $pid ] ?? 0 );
			case 'product.variation_images':
				return (float) ( self::variation_gaps( $key )[ $pid ] ?? 0 );
			case 'product.categories':
			case 'product.tags':
				$terms = get_the_terms( $pid, 'product.tags' === $field ? 'product_tag' : 'product_cat' );
				return (float) ( is_array( $terms ) ? count( $terms ) : 0 );
			case 'product.age':
				$at = strtotime( (string) $post->post_date_gmt ) ?: 0;
				return $at ? (float) floor( ( time() - $at ) / DAY_IN_SECONDS ) : 0.0;
		}
		return '';
	}

	/**
	 * The main photograph's pixel size.
	 *
	 * Read from the attachment's own metadata, which the scan primes one page
	 * of products at a time — asked product by product it would be one query
	 * per product, and the shop has thousands.
	 *
	 * @return array{w:int,h:int}
	 */
	private static function thumb_size( int $product_id ): array {
		$id = (int) get_post_thumbnail_id( $product_id );
		if ( ! $id ) {
			return [ 'w' => 0, 'h' => 0 ];
		}
		$meta = wp_get_attachment_metadata( $id );
		return [
			'w' => (int) ( $meta['width'] ?? 0 ),
			'h' => (int) ( $meta['height'] ?? 0 ),
		];
	}

	/**
	 * How many published variations each variable product has.
	 *
	 * One grouped query for the whole shop, held for the length of the scan.
	 * The alternative is a query per product, which is the same figure at a
	 * thousand times the cost.
	 *
	 * @return array<int,int>
	 */
	private static function variation_counts(): array {
		static $counts = null;
		if ( null !== $counts ) {
			return $counts;
		}
		global $wpdb;
		$counts = [];
		$rows   = (array) $wpdb->get_results(
			"SELECT post_parent, COUNT(*) AS n FROM {$wpdb->posts}
			 WHERE post_type = 'product_variation' AND post_status = 'publish'
			 GROUP BY post_parent",
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			$counts[ (int) $r['post_parent'] ] = (int) $r['n'];
		}
		return $counts;
	}

	/**
	 * How many of each product's variations have no photograph OF THEIR OWN.
	 *
	 * Not the one WooCommerce lends them from the parent: a colour showing the
	 * parent's photograph is exactly the gap this counts, and it is the gap the
	 * image lab was built to fill. Restricted to one attribute when a key is
	 * given — "the colours", "the versions" — and a variation set to "any
	 * colour" belongs to no colour in particular, so it is not counted as one
	 * missing a photograph.
	 *
	 * One grouped query for the whole shop, held for the length of the scan
	 * and keyed by the attribute, because a shop can have a criterion on the
	 * colours and another on the versions.
	 *
	 * @param string $key A variation attribute meta key, e.g. attribute_pa_couleur.
	 * @return array<int,int>
	 */
	private static function variation_gaps( string $key = '' ): array {
		static $done = [];
		$key = sanitize_key( $key );
		if ( isset( $done[ $key ] ) ) {
			return $done[ $key ];
		}
		global $wpdb;
		$where = '';
		$args  = [];
		if ( '' !== $key ) {
			// The attribute has to be ON the variation and have a value: an
			// empty one is "any colour", which is not a colour.
			$where  = " INNER JOIN {$wpdb->postmeta} a ON a.post_id = v.ID AND a.meta_key = %s AND a.meta_value <> '' ";
			$args[] = $key;
		}
		$sql = "SELECT v.post_parent AS pid, COUNT(*) AS n
			 FROM {$wpdb->posts} v
			 {$where}
			 LEFT JOIN {$wpdb->postmeta} t ON t.post_id = v.ID AND t.meta_key = '_thumbnail_id'
			 WHERE v.post_type = 'product_variation' AND v.post_status = 'publish'
			   AND ( t.meta_value IS NULL OR t.meta_value = '' OR t.meta_value = '0' )
			 GROUP BY v.post_parent";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results(
			$args ? $wpdb->prepare( $sql, ...$args ) : $sql,
			ARRAY_A
		);
		// phpcs:enable
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r['pid'] ] = (int) $r['n'];
		}
		$done[ $key ] = $out;
		return $out;
	}

	private static function kind_of( string $field ): string {
		return (string) ( self::fields()[ $field ]['kind'] ?? 'text' );
	}

	/**
	 * Whether one thing falls short of one criterion.
	 *
	 * A text field answers the comparisons with its LENGTH — in words or in
	 * characters, whichever that field is judged in — and answers "contains"
	 * with itself. A number field answers with its number. One place, so a
	 * criterion means the same thing on the overview, in the list and in the
	 * sentence the screen prints under it.
	 */
	private static function fails( array $row, string $scope, $object ): bool {
		$field = (string) ( $row['field'] ?? '' );
		$op    = self::op_now( (string) ( $row['test'] ?? 'empty' ) );
		$want  = self::want_for( $row, $scope, $object );
		if ( null === $want ) {
			return false; // no condition covers it, so nothing was asked of it.
		}
		$m     = self::measure( $field, $scope, $object, (string) ( $row['key'] ?? '' ) );

		if ( 'meta' === self::kind_of( $field ) ) {
			return self::fails_meta( $op, $m, $want, trim( (string) ( $row['find'] ?? '' ) ) );
		}

		if ( 'text' === self::kind_of( $field ) ) {
			$text = trim( wp_strip_all_tags( is_string( $m ) ? $m : '' ) );
			if ( 'empty' === $op ) {
				return '' === $text;
			}
			if ( 'filled' === $op ) {
				return '' !== $text;
			}
			$find = trim( (string) ( $row['find'] ?? '' ) );
			if ( 'contains' === $op ) {
				return '' !== $find && false !== mb_stripos( $text, $find );
			}
			if ( 'not_contains' === $op ) {
				return '' !== $find && false === mb_stripos( $text, $find );
			}
			$have = 'characters' === self::unit_of( $field ) ? (float) mb_strlen( $text ) : (float) self::words( $text );
			return self::compare( $op, $have, $want );
		}

		$have = is_numeric( $m ) ? (float) $m : 0.0;
		if ( 'empty' === $op ) {
			return $have <= 0;
		}
		if ( 'filled' === $op ) {
			return $have > 0;
		}
		// An article is held to the figure the linking pass works out from its
		// own length — asked here a second way, the two screens would disagree
		// about the same article. A number typed in the row wins when there is
		// one.
		if ( 'post.links' === $field && 'lt' === $op && $want <= 0 && $object instanceof WP_Post ) {
			$target = (float) self::link_target( $object, self::words( (string) $object->post_content ) );
			return $target > 0 && $have < $target;
		}
		return self::compare( $op, $have, $want );
	}

	/**
	 * A custom field, judged on what it actually holds.
	 *
	 * There is no telling in advance: the same shop keeps text in one ACF
	 * field, an attachment id in the next, a list of them in the one after,
	 * and "1" in a true/false. So the value decides how it is read, by one
	 * rule written under the row and nowhere else:
	 *
	 * - empty / not empty — nothing stored, an empty string, or an empty list.
	 *   A stored "0" is an ANSWER, not an absence: an unticked box was filled
	 *   in, and a field nobody ever touched was not.
	 * - contains — looked for in the text, a list read as its own values.
	 * - a number — the value if it IS a number (a price, an id, a repeater's
	 *   count), the length of the list if it is a list, and the number of
	 *   characters otherwise.
	 *
	 * @param mixed $raw What get_post_meta gave back, uncast.
	 */
	private static function fails_meta( string $op, $raw, float $want, string $find ): bool {
		$list = is_array( $raw ) ? array_filter( $raw, 'is_scalar' ) : null;
		$text = null === $list
			? ( is_scalar( $raw ) ? trim( wp_strip_all_tags( (string) $raw ) ) : '' )
			: trim( implode( ' ', array_map( 'strval', $list ) ) );
		$has  = null === $list ? '' !== $text : (bool) $list;

		if ( 'empty' === $op ) {
			return ! $has;
		}
		if ( 'filled' === $op ) {
			return $has;
		}
		if ( 'contains' === $op ) {
			return '' !== $find && false !== mb_stripos( $text, $find );
		}
		if ( 'not_contains' === $op ) {
			return '' !== $find && false === mb_stripos( $text, $find );
		}
		if ( null !== $list ) {
			$have = (float) count( $list );
		} else {
			$have = is_numeric( $text ) ? (float) $text : (float) mb_strlen( $text );
		}
		return self::compare( $op, $have, $want );
	}

	/** The six comparisons, in one place. */
	private static function compare( string $op, float $have, float $want ): bool {
		switch ( $op ) {
			case 'lt':
				return $have < $want;
			case 'lte':
				return $have <= $want;
			case 'gt':
				return $have > $want;
			case 'gte':
				return $have >= $want;
			case 'eq':
				return abs( $have - $want ) < 0.0001;
			case 'neq':
				return abs( $have - $want ) >= 0.0001;
		}
		return false;
	}

	private static function scan_categories( array $wanted, string $scope, array &$hits, array &$seen, ?array $mine = null ): void {
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			// WPML files a taxonomy under its TERM TAXONOMY id, not its term
			// id. Comparing the wrong one of the two would drop every
			// category on some shops and none on others.
			if ( null !== $mine && ! isset( $mine[ (int) ( $term->term_taxonomy_id ?? 0 ) ] ) ) {
				continue;
			}
			$seen[ $scope ]++;
			foreach ( $wanted as $id => $check ) {
				if ( self::fails( (array) $check['row'], 'category', $term ) ) {
					$hits[ $id ][] = (int) $term->term_id;
				}
			}
		}
	}

	/**
	 * Articles and pages, read by the pass that already knows how.
	 *
	 * Its own census answers what a text that long may carry and what it
	 * carries today — asking that question a second way here is how two
	 * screens end up disagreeing about the same article.
	 *
	 * @param array<string,int[]> $hits
	 */
	/**
	 * The articles and pages, read the way the products are.
	 *
	 * It used to walk the linking pass's own census, which is a cache of the
	 * FIVE HUNDRED most recently modified posts — so the articles this screen
	 * is most often asked about, the ones nobody has touched in a year, were
	 * exactly the ones it could not see. The census is still what answers for
	 * links: it is asked, per post, for the target IT works out, so the two
	 * screens cannot disagree about the same article; beyond its five hundred,
	 * the target is worked out by its own public function rather than by a
	 * second formula living here.
	 */
	private static function scan_posts( array $wanted, string $scope, array &$hits, array &$seen, ?array $mine = null ): void {
		if ( ! post_type_exists( $scope ) ) {
			return;
		}
		$page = 1;
		do {
			$q = new WP_Query( [
				'post_type'              => $scope,
				'post_status'            => 'publish',
				'posts_per_page'         => 200,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => true,
				'suppress_filters'       => '' === self::main_language(),
			] );
			foreach ( $q->posts as $post ) {
				if ( null !== $mine && ! isset( $mine[ (int) $post->ID ] ) ) {
					continue; // WPML's copy of an article, not another article.
				}
				$seen[ $scope ]++;
				foreach ( $wanted as $id => $check ) {
					if ( self::fails( (array) $check['row'], 'post', $post ) ) {
						$hits[ $id ][] = (int) $post->ID;
					}
				}
			}
			$page++;
		} while ( $q->post_count > 0 );
	}

	/**
	 * How many internal links this article ought to carry.
	 *
	 * The linking pass's figure, asked of the linking pass — from its census
	 * when it holds the article, from its own public formula when it does not.
	 */
	private static function link_target( WP_Post $post, int $words ): int {
		if ( ! class_exists( 'DZE_Post_Links' ) ) {
			return 0;
		}
		static $census = null;
		if ( null === $census ) {
			$census = (array) DZE_Post_Links::census();
		}
		$row = $census[ (int) $post->ID ] ?? null;
		if ( is_array( $row ) && isset( $row['target'] ) ) {
			return (int) $row['target'];
		}
		return (int) DZE_Post_Links::target_links( $words );
	}

	public function register_menu(): void {
		$waiting = self::waiting();
		// "Content diagnostic" and not "Diagnostic": beside Restock and the
		// rest of this menu, a bare "Diagnostic" reads as the shop's health —
		// servers, keys, cron. What it reads is the CONTENT of the pages.
		$label   = __( 'Content diagnostic', 'dazont-ecom' );
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			$label,
			$waiting
				? $label . ' <span class="update-plugins count-' . (int) $waiting . '"><span class="plugin-count">' . (int) $waiting . '</span></span>'
				: $label,
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/** How many things the shop is short of, all criteria together. */
	public static function waiting(): int {
		$n = 0;
		foreach ( (array) ( self::census()['checks'] ?? [] ) as $n_one ) {
			$n += (int) $n_one;
		}
		return $n;
	}

	public function register_settings(): void {
		register_setting( 'dze_diagnostic_options', self::OPT, [
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
			'autoload'          => false,
		] );
	}

	/**
	 * The standards, saved.
	 *
	 * WordPress calls a sanitizer with null when the page did not carry this
	 * option at all: that is another form being saved, and the answer is what
	 * is stored, never the defaults.
	 */
	public static function sanitize( $in ): array {
		if ( ! is_array( $in ) ) {
			return self::settings();
		}
		$out = self::settings();
		// The form carries a marker even when every criterion was deleted, or
		// emptying the list would read as "this form was not about criteria"
		// and never take — the same trap the emails of a promotion fell into.
		// Criteria used to arrive from two places: this list, and the prompt
		// library, which added lines of its own that could not be edited here.
		// One kind of thing now — a condition the shop wrote — so what was
		// stored for the other kind goes rather than sitting in the database
		// meaning nothing.
		unset( $out['off'] );
		if ( ! empty( $in['rows_shown'] ) ) {
			$out['rows'] = self::clean_rows( (array) ( $in['rows'] ?? [] ) );
			// A checkbox the submitted section owns: unticked it posts nothing,
			// so its absence IS the answer — but only in a form that carried
			// the criteria at all.
			$out['signs'] = empty( $in['signs'] ) ? 0 : 1;
		}
		return $out;
	}

	/**
	 * The custom fields of one post type, asked for when a card is switched
	 * to it.
	 *
	 * Behind a click, never on a page load: the shop that never writes a
	 * criterion about Funnels never pays for reading what a funnel carries.
	 */
	public static function ajax_keys(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$type = sanitize_key( (string) ( $_POST['type'] ?? '' ) );
		if ( ! isset( self::scopes()[ $type ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown post type.', 'dazont-ecom' ) ], 400 );
		}
		wp_send_json_success( [ 'keys' => self::meta_keys( $type ) ] );
	}

	public static function ajax_scan(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$c = self::scan();
		wp_send_json_success( [
			'waiting' => self::waiting(),
			'at'      => (int) ( $c['at'] ?? 0 ),
		] );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$check = isset( $_GET['check'] ) ? sanitize_key( wp_unslash( $_GET['check'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		echo '<div class="wrap dze-wrap">';
		if ( '' !== $check && isset( self::checks()[ $check ] ) ) {
			$this->render_list( $check );
		} else {
			$this->render_overview();
		}
		echo '</div>';
	}

	/**
	 * The to-do list.
	 *
	 * Read from the top and stop when you run out of afternoon: what needs
	 * doing, most of it first, grouped by the screen you would go and do it
	 * on. A criterion nothing falls short of is not work and is not a line —
	 * it is named under the table, so one that quietly stopped matching is
	 * still somewhere you can see it.
	 */
	private function render_overview(): void {
		$census = self::census();
		$checks = self::checks();
		$seen   = (array) ( $census['seen'] ?? [] );
		$short  = (array) ( $census['short'] ?? [] );
		$at     = (int) ( $census['at'] ?? 0 );
		$where  = self::scopes();

		echo '<h1>' . esc_html__( 'Content diagnostic', 'dazont-ecom' ) . '</h1>';
		echo '<p class="description" style="max-width:760px;">'
			. esc_html__( 'What the shop is short of, read against your own standards. Nothing here writes anything or spends anything: each line points at the screen that fixes that one thing.', 'dazont-ecom' )
			. '</p>';

		echo '<p style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">';
		echo '<button type="button" class="button button-primary" id="dze-diag-scan">' . esc_html__( 'Read the shop again', 'dazont-ecom' ) . '</button>';
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( self::settings_url() ),
			esc_html__( 'Criteria', 'dazont-ecom' )
		);
		echo '<span id="dze-diag-msg" class="description">';
		if ( $at ) {
			// What was read, by post type: the line named three of them when
			// the shop could have thirteen, and a shop that reads its Funnels
			// would have been told about articles it never asked for.
			$said = [];
			foreach ( $where as $scope => $label ) {
				if ( isset( $seen[ $scope ] ) ) {
					$said[] = sprintf( '%s %d', $label, (int) $seen[ $scope ] );
				}
			}
			printf(
				/* translators: 1: how long ago, 2: what was read, by post type */
				esc_html__( 'Read %1$s ago — %2$s.', 'dazont-ecom' ),
				esc_html( human_time_diff( $at ) ),
				esc_html( implode( ' · ', $said ) )
			);
		} else {
			esc_html_e( 'Never read yet — press the button, or wait for tonight.', 'dazont-ecom' );
		}
		echo '</span></p>';

		// A shop in five languages has to be told which one it is looking at,
		// or a thousand products against WooCommerce's five thousand reads as
		// a broken screen rather than a deliberate one.
		$lang  = (string) ( $census['lang'] ?? self::main_language() );
		$every = (array) ( $census['every'] ?? [] );
		if ( '' !== $lang && ! $every ) {
			echo '<p class="description" style="max-width:760px;margin-top:-6px;">';
			printf(
				/* translators: %s: the shop's main language, e.g. English */
				esc_html__( 'Read in %s only — the shop\'s main language. Translations are WPML\'s copies of these pages: they are translated, not written, so they are not counted here.', 'dazont-ecom' ),
				'<strong>' . esc_html( self::language_name( $lang ) ) . '</strong>'
			);
			echo '</p>';
		} elseif ( '' !== $lang ) {
			// The one thing that makes these numbers wrong, said where the
			// numbers are. A count that silently includes every translation
			// is a count the shop cannot act on, and cannot tell apart from a
			// count that does not.
			echo '<div class="notice notice-warning inline" style="max-width:1100px;margin:12px 0;"><p>';
			printf(
				/* translators: 1: the shop's main language, 2: the post types affected */
				esc_html__( 'These numbers count EVERY language, not %1$s alone: WPML could not be asked which %2$s are translations, so each one is counted once per language it exists in. The figures are too high by that much.', 'dazont-ecom' ),
				'<strong>' . esc_html( self::language_name( $lang ) ) . '</strong>',
				'<strong>' . esc_html( implode( ', ', array_map(
					static fn( string $one ): string => (string) ( self::scopes()[ $one ] ?? $one ),
					$every
				) ) ) . '</strong>'
			);
			echo '</p></div>';
		}

		// WHICH work. A shop is never doing SEO and CRO in the same month, and
		// a list holding both is a list read twice. One click, in the address
		// — so the shop that is on conversion this quarter bookmarks its own
		// half and finds it there tomorrow, with no setting to keep in step.
		$goal = isset( $_GET['goal'] ) ? sanitize_key( wp_unslash( $_GET['goal'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$goal = isset( self::goals()[ $goal ] ) ? $goal : '';
		$tally = (array) ( $census['goals'] ?? [] );
		if ( $at ) {
			echo '<p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:16px 0 4px;">';
			$tabs = [ '' => __( 'Everything', 'dazont-ecom' ) ] + array_map(
				static fn( array $g ): string => (string) $g['label'],
				self::goals()
			);
			foreach ( $tabs as $gid => $label ) {
				$on  = $gid === $goal;
				$url = add_query_arg(
					'' === $gid ? [ 'page' => self::MENU_SLUG ] : [ 'page' => self::MENU_SLUG, 'goal' => $gid ],
					admin_url( 'admin.php' )
				);
				// The number beside each one is the OPPORTUNITY: how many
				// things that goal has waiting, counted once each however many
				// criteria they fall short of.
				$n = '' === $gid ? array_sum( array_map( 'intval', $short ) ) : (int) ( $tally[ $gid ] ?? 0 );
				printf(
					'<a href="%1$s" class="button%2$s">%3$s <span style="opacity:.75;">%4$s</span></a>',
					esc_url( $url ),
					$on ? ' button-primary' : '',
					esc_html( $label ),
					esc_html( number_format_i18n( $n ) )
				);
			}
			echo '</p>';
			if ( '' !== $goal ) {
				echo '<p class="description" style="max-width:760px;margin:0 0 6px;">'
					. esc_html( (string) ( self::goals()[ $goal ]['what'] ?? '' ) ) . '</p>';
			}
		}

		// What is to be DONE, worst first, kept apart from what is already
		// right. Twenty lines reading "—" is a screen where the four that
		// matter are hard to find.
		$read  = (array) ( $census['checks'] ?? [] );
		$found = [];
		$clean = [];
		$fresh = [];
		foreach ( $checks as $id => $check ) {
			if ( '' !== $goal && ! in_array( $goal, (array) ( $check['goals'] ?? [] ), true ) ) {
				continue;
			}
			// A criterion the last reading did not cover is not a criterion
			// that found nothing — it is one nobody has looked at. Reported as
			// zero, a criterion added five minutes ago says the shop is fine
			// on a question it has never been asked.
			if ( ! array_key_exists( $id, $read ) ) {
				$fresh[ $id ] = $check;
				continue;
			}
			$n = (int) $read[ $id ];
			if ( $n > 0 ) {
				$found[ $id ] = $n;
			} else {
				$clean[ $id ] = $check;
			}
		}
		arsort( $found );

		if ( $fresh && $at ) {
			echo '<div class="notice notice-warning inline" style="max-width:1100px;margin:12px 0;"><p>';
			printf(
				/* translators: %s: the criteria that have never been read, by name */
				esc_html__( 'Not read yet: %s. Press "Read the shop again" to have them counted.', 'dazont-ecom' ),
				'<strong>' . esc_html( implode( ', ', array_map(
					static fn( array $c ): string => (string) $c['label'],
					$fresh
				) ) ) . '</strong>'
			);
			echo '</p></div>';
		}

		if ( $at && $short ) {
			// One tile per scope, saying how many THINGS need something rather
			// than how many criteria fired: a product short of four things is
			// one product to open, and it is the figure the afternoon is
			// planned against.
			$asked = [];
			foreach ( $checks as $check ) {
				$asked[ (string) $check['scope'] ] = true;
			}
			echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0 20px;">';
			foreach ( $where as $scope => $label ) {
				if ( empty( $asked[ $scope ] ) ) {
					continue; // a type nothing is asked about has no tile and no reading.
				}
				$n     = (int) ( $short[ $scope ] ?? 0 );
				$total = (int) ( $seen[ $scope ] ?? 0 );
				$pc    = $total > 0 ? (int) round( $n / $total * 100 ) : 0;
				printf(
					'<div style="flex:1 1 200px;min-width:180px;background:#fff;border:1px solid %1$s;border-left:4px solid %1$s;border-radius:4px;padding:10px 14px;">'
					. '<div style="font-size:22px;line-height:1.2;">%2$s</div>'
					. '<div class="description" style="margin-top:2px;">%3$s</div></div>',
					esc_attr( $n ? '#d63638' : '#00794b' ),
					$n
						? esc_html( sprintf( '%d / %d', $n, $total ) )
						: esc_html( sprintf( '%d', $total ) ),
					$n
						? esc_html( sprintf(
							/* translators: 1: what is counted (Products, Categories…), 2: a share of the shop */
							__( '%1$s need something — %2$d%%', 'dazont-ecom' ),
							$label,
							$pc
						) )
						: esc_html( sprintf(
							/* translators: %s: what is counted (Products, Categories…) */
							__( '%s — nothing to do', 'dazont-ecom' ),
							$label
						) )
				);
			}
			echo '</div>';
		}

		if ( ! $found ) {
			echo '<div class="notice notice-info inline" style="max-width:760px;margin:12px 0;"><p>';
			if ( ! $at ) {
				esc_html_e( 'The shop has not been read yet — press "Read the shop again", or wait for tonight.', 'dazont-ecom' );
			} elseif ( '' !== $goal ) {
				printf(
					/* translators: %s: a goal, e.g. CRO */
					esc_html__( 'Nothing waiting for %s. Every criterion you keep for it is met, everywhere.', 'dazont-ecom' ),
					'<strong>' . esc_html( (string) ( self::goals()[ $goal ]['label'] ?? $goal ) ) . '</strong>'
				);
			} else {
				esc_html_e( 'Nothing falls short. Every criterion you have switched on is met, everywhere.', 'dazont-ecom' );
			}
			echo '</p></div>';
		}

		// Grouped by where the work is done, because that is how it is done:
		// an afternoon on the products, another on the categories.
		foreach ( $where as $scope => $label ) {
			$rows = array_filter(
				array_keys( $found ),
				static fn( string $id ): bool => $scope === ( $checks[ $id ]['scope'] ?? '' )
			);
			if ( ! $rows ) {
				continue;
			}
			echo '<h2 style="margin:22px 0 6px;font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:#646970;">'
				. esc_html( $label ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:1100px;"><tbody>';
			foreach ( $rows as $id ) {
				$check = $checks[ $id ];
				$n     = (int) $found[ $id ];
				$total = (int) ( $seen[ $scope ] ?? 0 );
				$pc    = $total > 0 ? (int) round( $n / $total * 100 ) : 0;
				$tool = (array) ( $check['tool'] ?? [] );
				echo '<tr>';
				echo '<td><strong>' . esc_html( $check['label'] ) . '</strong>';
				// A line always says which goal it belongs to, so the number
				// on it is attributable without opening the criteria.
				foreach ( (array) ( $check['goals'] ?? [] ) as $gid ) {
					echo '<span style="display:inline-block;margin-left:6px;padding:1px 6px;border-radius:9px;background:#f0f0f1;color:#50575e;font-size:11px;font-weight:600;vertical-align:middle;">'
						. esc_html( (string) ( self::goals()[ $gid ]['label'] ?? $gid ) ) . '</span>';
				}
				if ( '' !== (string) ( $check['note'] ?? '' ) ) {
					echo '<br /><span>' . esc_html( (string) $check['note'] ) . '</span>';
				}
				echo '<br /><span class="description" style="font-size:12px;">' . esc_html( (string) $check['why'] ) . '</span></td>';
				// The share reads big, beside the bar it belongs to, and the two
				// counts read under it: "75%" over "153 of 203". The other way
				// round it said "75% of 203", which is not a sentence about
				// anything — 75% is not what 153 was out of.
				echo '<td style="width:280px;vertical-align:middle;">';
				printf(
					'<div style="display:flex;align-items:center;gap:12px;">'
					. '<strong style="font-size:17px;line-height:1;min-width:54px;text-align:right;">%1$d%%</strong>'
					. '<span style="flex:1 1 auto;">'
					. '<span style="display:block;background:#f0f0f1;border-radius:2px;height:6px;overflow:hidden;">'
					. '<span style="display:block;background:%2$s;height:6px;width:%3$d%%;"></span></span>'
					. '<span class="description" style="display:block;margin-top:3px;">%4$s</span>'
					. '</span></div>',
					$pc,
					esc_attr( $pc >= 50 ? '#d63638' : ( $pc >= 15 ? '#dba617' : '#8c8f94' ) ),
					max( 2, min( 100, $pc ) ),
					esc_html( sprintf(
						/* translators: 1: how many fall short, 2: how many were read */
						__( '%1$d of %2$d', 'dazont-ecom' ),
						$n,
						$total
					) )
				);
				echo '</td>';
				// The two things a line is for: seeing WHICH ones, and going to
				// the screen that mends them. Named rather than numbered — the
				// owner knows "Image lab", he does not know "check 4".
				echo '<td style="width:230px;text-align:right;white-space:nowrap;">';
				printf(
					'<a class="button button-small" href="%s">%s</a>',
					esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'check' => $id ], admin_url( 'admin.php' ) ) ),
					esc_html__( 'The list', 'dazont-ecom' )
				);
				if ( ! empty( $tool['url'] ) ) {
					printf(
						' <a class="button button-small" href="%s">%s &rarr;</a>',
						esc_url( (string) $tool['url'] ),
						esc_html( (string) $tool['label'] )
					);
				}
				// And the one that does the work from here. The count is on the
				// button BEFORE it is pressed: what a click is about to spend
				// is not something to discover afterwards.
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( $clean && $at ) {
			echo '<details style="margin-top:18px;max-width:1100px;"><summary style="cursor:pointer;color:#2271b1;">';
			printf(
				/* translators: %d: how many criteria nothing falls short of */
				esc_html( _n( '%d criterion found nothing', '%d criteria found nothing', count( $clean ), 'dazont-ecom' ) ),
				(int) count( $clean )
			);
			echo '</summary><p class="description" style="margin:8px 0 0;">';
			$said = [];
			foreach ( $clean as $check ) {
				$said[] = sprintf( '%s (%s)', (string) $check['label'], (string) ( $where[ $check['scope'] ] ?? $check['scope'] ) );
			}
			echo esc_html( implode( ' · ', $said ) );
			echo '</p></details>';
		}

		printf(
			'<p class="description" style="margin-top:14px;max-width:760px;">%s <a href="%s">%s</a></p>',
			esc_html__( 'Every line above is one of your criteria and nothing else. Change a figure, switch one off, add your own:', 'dazont-ecom' ),
			esc_url( self::settings_url() ),
			esc_html__( 'the criteria &rarr;', 'dazont-ecom' )
		);
		$this->print_script();
	}

	/**
	 * The list in two: what still falls short, and what has been MENDED.
	 *
	 * "Pour mieux organiser le travail, il est préférable de créer 2 onglets :
	 * un qui reprend les posts à retravailler, un qui reprend ceux qui sont
	 * fixed." The list is a photograph taken by the last reading, and a shop
	 * that mends a product finds it still sitting there — which reads as "the
	 * fix did not work". So the whole list is re-read against the criterion as
	 * the shop stands NOW, and the work to do is never mixed with the work
	 * done.
	 *
	 * Read in batches of two hundred, primed the way the scan primes them, and
	 * kept for a few minutes under a key that carries the criterion, the list
	 * and the last edit made to it: touch a product and the reading is redone,
	 * leave the screen alone and paging through it costs nothing.
	 *
	 * A category cannot be re-read — nothing here can ask a term whether it
	 * still falls short — so that list is handed back whole and says so.
	 *
	 * @param int[] $ids
	 * @return array{todo:int[],done:int[],live:bool}
	 */
	private static function split( string $id, array $check, array $ids ): array {
		$scope = (string) ( $check['scope'] ?? '' );
		$rule  = (array) ( self::rows_by_id()[ $id ] ?? [] );
		$ids   = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $rule || ! $ids || 'category' === $scope ) {
			return [ 'todo' => $ids, 'done' => [], 'live' => false ];
		}
		$slot = 'dze_diag_split_' . md5( $id . '|' . (string) wp_json_encode( $rule ) . '|' . implode( ',', $ids ) . '|' . self::touched( $ids ) );
		$got  = get_transient( $slot );
		if ( is_array( $got ) && isset( $got['todo'], $got['done'] ) ) {
			return $got;
		}
		$verdict = [];
		foreach ( array_chunk( $ids, 200 ) as $some ) {
			$posts = get_posts( [
				'post__in'               => $some,
				// The scope IS the post type here — 'product', 'post', 'page'
				// — and asking for that one rather than "any" keeps a list
				// honest when a shop has a post type WordPress leaves out of
				// "any".
				'post_type'              => $scope,
				'post_status'            => 'any',
				'posts_per_page'         => count( $some ),
				'orderby'                => 'post__in',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'suppress_filters'       => '' === self::main_language(),
			] );
			self::prime_thumbs( $posts );
			foreach ( $posts as $post ) {
				$verdict[ (int) $post->ID ] = (bool) self::fails( $rule, $scope, $post );
			}
		}
		$todo = [];
		$done = [];
		foreach ( $ids as $one ) {
			if ( ! array_key_exists( $one, $verdict ) ) {
				continue; // deleted since the reading: not work waiting for anybody.
			}
			if ( $verdict[ $one ] ) {
				$todo[] = $one;
			} else {
				$done[] = $one;
			}
		}
		$out = [ 'todo' => $todo, 'done' => $done, 'live' => true ];
		set_transient( $slot, $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * The most recent edit in a list of posts.
	 *
	 * What makes a kept reading die at the right moment: mend a product and
	 * its post_modified moves, so the key changes and the list is judged
	 * again. One query, over ids this class has already bounded.
	 *
	 * @param int[] $ids
	 */
	private static function touched( array $ids ): string {
		global $wpdb;
		if ( ! $wpdb || ! $ids ) {
			return '';
		}
		$in = implode( ',', array_map( 'intval', $ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids cast to int on the line above.
		return (string) $wpdb->get_var( "SELECT MAX( post_modified_gmt ) FROM {$wpdb->posts} WHERE ID IN ( {$in} )" );
	}

	private function render_list( string $id ): void {
		$check = self::checks()[ $id ] ?? [];
		if ( ! $check ) {
			// A criterion switched off or deleted while its list was open. It
			// used to be a white page — a fatal before any of our own error
			// handling, carrying no message at all.
			printf(
				'<h1>%s</h1><p>%s <a href="%s">%s</a></p>',
				esc_html__( 'That criterion is gone', 'dazont-ecom' ),
				esc_html__( 'It has been switched off or removed since this list was opened.', 'dazont-ecom' ),
				esc_url( add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) ) ),
				esc_html__( 'Back to the diagnostic', 'dazont-ecom' )
			);
			return;
		}
		$census = self::census();
		// THIS criterion's list, and no other. All of them were read together
		// to draw fifty rows of one.
		$all    = self::list_of( $id );
		$n      = (int) ( $census['checks'][ $id ] ?? 0 );
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.

		// The work to do, and the work done. Two lists, never mixed: what is
		// mended has nothing left to say to somebody working through the
		// other one.
		// How this list was last looked at, by THIS person and for THIS
		// criterion. A list of nine hundred products sorted by revenue, left
		// and come back to, used to open again on the order nobody chose —
		// so the work was re-sorted by hand every single time.
		$kept = self::kept_view( $id );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- choosing a tab and ordering a list are navigation.
		$show = isset( $_GET['show'] )
			? ( 'fixed' === $_GET['show'] ? 'fixed' : 'todo' )
			: (string) ( $kept['show'] ?? 'todo' );
		$split  = self::split( $id, $check, $all );
		$show   = $split['live'] ? $show : 'todo';
		$ids    = 'fixed' === $show ? $split['done'] : $split['todo'];

		$by  = isset( $_GET['by'] ) ? sanitize_key( wp_unslash( $_GET['by'] ) ) : (string) ( $kept['by'] ?? 'found' );
		$dir = isset( $_GET['dir'] )
			? ( 'asc' === $_GET['dir'] ? 'asc' : 'desc' )
			: ( 'asc' === (string) ( $kept['dir'] ?? 'desc' ) ? 'asc' : 'desc' );
		// phpcs:enable
		$by    = isset( self::orders()[ $by ] ) ? $by : 'found';
		self::keep_view( $id, [ 'by' => $by, 'dir' => $dir, 'show' => $show ] );
		$goods = 'product' === (string) $check['scope'];
		// Read once for the WHOLE list: what is sorted is the list, not the
		// page — a shop looking for its best-sellers is not looking for the
		// best-sellers of page three.
		$facts = $goods ? self::facts( $ids ) : [];
		if ( 'found' !== $by && $facts ) {
			usort( $ids, static function ( $a, $b ) use ( $facts, $by, $dir ) {
				$x = $facts[ (int) $a ] ?? [];
				$y = $facts[ (int) $b ] ?? [];
				switch ( $by ) {
					case 'sales':  $c = ( (int) ( $x['sales'] ?? 0 ) ) <=> ( (int) ( $y['sales'] ?? 0 ) ); break;
					case 'price':  $c = ( (float) ( $x['price'] ?? 0 ) ) <=> ( (float) ( $y['price'] ?? 0 ) ); break;
					case 'edited': $c = strcmp( (string) ( $x['edited'] ?? '' ), (string) ( $y['edited'] ?? '' ) ); break;
					default:       $c = strcasecmp( (string) ( $x['title'] ?? '' ), (string) ( $y['title'] ?? '' ) ); break;
				}
				return 'asc' === $dir ? $c : -$c;
			} );
		}
		$slice  = array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

		printf(
			'<h1>%s <a class="page-title-action" href="%s">%s</a></h1>',
			esc_html( $check['label'] ),
			esc_url( add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) ) ),
			esc_html__( 'Back to the diagnostic', 'dazont-ecom' )
		);
		// Mending from this screen went with the routine it depended on.
		$waiting = [];
		$mending = false;

		if ( $split['live'] ) {
			// WordPress's own tabs, because that is what every other screen of
			// this admin uses to say "the same list, seen two ways".
			echo '<h2 class="nav-tab-wrapper" style="margin:14px 0 0;">';
			foreach ( [
				'todo'  => sprintf(
					/* translators: %d: how many still fall short */
					__( 'Issues (%d)', 'dazont-ecom' ),
					count( $split['todo'] )
				),
				'fixed' => sprintf(
					/* translators: %d: how many have been mended since the reading */
					__( 'Fixed (%d)', 'dazont-ecom' ),
					count( $split['done'] )
				),
			] as $dze_tab => $dze_label ) {
				printf(
					'<a class="nav-tab%1$s" href="%2$s">%3$s</a>',
					$show === $dze_tab ? ' nav-tab-active' : '',
					esc_url( add_query_arg(
						[ 'page' => self::MENU_SLUG, 'check' => $id, 'show' => $dze_tab, 'by' => $by, 'dir' => $dir ],
						admin_url( 'admin.php' )
					) ),
					esc_html( $dze_label )
				);
			}
			echo '</h2>';
		}
		printf(
			'<p class="description" style="margin-top:10px;">%s</p>',
			esc_html( 'fixed' === $show
				? sprintf(
					/* translators: %d: how many have been mended */
					_n(
						'%d has been mended since the last reading. It leaves the list at the next one.',
						'%d have been mended since the last reading. They leave the list at the next one.',
						count( $ids ),
						'dazont-ecom'
					),
					count( $ids )
				)
				: sprintf(
					/* translators: 1: how many fall short, 2: how many are listed */
					__( '%1$d fall short. %2$d listed here — the count is exact whatever the list can show.', 'dazont-ecom' ),
					$n,
					count( $ids )
				) )
		);

		// What ELSE each one is short of: read from the same reading, so a
		// product that needs four things is opened once and not four times.
		$also = [];
		foreach ( $lists as $other => $other_ids ) {
			if ( $other === $id ) {
				continue;
			}
			foreach ( array_intersect( (array) $other_ids, $slice ) as $oid ) {
				$also[ (int) $oid ][] = (string) ( self::checks()[ $other ]['label'] ?? $other );
			}
		}

		// A sortable header, drawn the way WordPress draws every sortable
		// header in this admin: the whole <th>, with its own classes, so core
		// puts the arrow there — faint on hover for a column that CAN be
		// sorted, solid on the one that is. The version before this printed a
		// blue link and an arrow on the sorted column only, so a screen sorted
		// by nothing in particular showed no arrow anywhere and nothing said
		// the titles were clickable at all.
		// The arrow is DRAWN HERE, not borrowed. WordPress's own indicator is
		// styled under .wp-list-table and this table is not one, so the native
		// classes put a mark on the page that nothing painted: the screen
		// still said nothing about being sortable, and nothing about what it
		// was sorted by. One character, in the title, on every column: a pale
		// pair on the ones that can be sorted, a solid one on the one in use.
		$head = static function ( string $key, string $label, string $style = '' ) use ( $id, $by, $dir, $show ): string {
			$on   = $by === $key;
			$next = ( $on && 'desc' === $dir ) ? 'asc' : 'desc';
			$mark = $on
				? '<span style="color:#1d2327;">' . ( 'desc' === $dir ? '&#9660;' : '&#9650;' ) . '</span>'
				: '<span style="color:#a7aaad;">&#8645;</span>';
			return sprintf(
				'<th scope="col"%1$s><a href="%2$s" style="text-decoration:none;color:inherit;%3$s" title="%4$s">%5$s <span style="font-size:10px;">%6$s</span></a></th>',
				'' !== $style ? ' style="' . esc_attr( $style ) . '"' : '',
				esc_url( add_query_arg(
					[ 'page' => self::MENU_SLUG, 'check' => $id, 'show' => $show, 'by' => $key, 'dir' => $next ],
					admin_url( 'admin.php' )
				) ),
				$on ? 'font-weight:700;' : '',
				esc_attr__( 'Sort by this column', 'dazont-ecom' ),
				esc_html( $label ),
				$mark // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one entity, written here.
			);
		};

		echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr>';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- every cell is built escaped above.
		echo $goods
			? $head( 'name', __( 'Product', 'dazont-ecom' ) )
			: '<th>' . esc_html__( 'Name', 'dazont-ecom' ) . '</th>';
		if ( $goods ) {
			echo $head( 'price', __( 'Price', 'dazont-ecom' ), 'width:110px;text-align:right;' );
			echo $head( 'sales', __( 'Sold', 'dazont-ecom' ), 'width:90px;text-align:right;' );
			echo $head( 'edited', __( 'Last edited', 'dazont-ecom' ), 'width:140px;' );
		}
		// phpcs:enable
		$dze_tool = (array) ( $check['tool'] ?? [] );
		// On the FIXED tab the last column says what was DONE, not what to
		// open next: a product that has left the problem list is a product
		// nobody can check any more without reopening it.
		$dze_done = ( 'fixed' === $show && class_exists( 'DZE_Queue' ) )
			? DZE_Queue::done_map( $slice )
			: [];
		if ( 'fixed' === $show ) {
			echo '<th style="width:210px;">' . esc_html__( 'What was done', 'dazont-ecom' ) . '</th>';
		} elseif ( $dze_tool ) {
			echo '<th style="width:110px;"></th>';
		}
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		// phpcs:enable
		if ( $mending ) {
			echo '<th style="width:150px;"></th>';
		}
		echo '</tr></thead><tbody>';
		$fmt = get_option( 'date_format' ) ?: 'Y-m-d';

		// The rows of this page, in sections when the criterion is conditional:
		// a product is on this list because ONE condition placed it, and a
		// list that does not say which is a list you have to work out.
		$dze_row  = (array) ( $check['row'] ?? [] );
		$dze_cols = ( $goods ? 4 : 1 ) + ( ( 'fixed' === $show || $dze_tool ) ? 1 : 0 );
		$dze_seen = null;
		foreach ( $slice as $oid ) {
			$oid = (int) $oid;
			[ $name, $link ] = self::object_link( (string) $check['scope'], $oid );
			if ( '' === $name ) {
				continue;
			}
			if ( ! empty( $dze_row['cond'] ) ) {
				$hit = self::band_hit( $dze_row, (string) $check['scope'], self::object_for( (string) $check['scope'], $oid ) );
				if ( $dze_seen !== $hit['i'] ) {
					$dze_seen = $hit['i'];
					printf(
						'<tr><td colspan="%1$d" style="background:#f6f7f7;font-weight:600;">%2$s</td></tr>',
						(int) $dze_cols,
						esc_html( $hit['want'] > 0
							/* translators: 1: the condition, 2: what it asks for */
							? sprintf( __( '%1$s — at least %2$d', 'dazont-ecom' ), $hit['said'], (int) $hit['want'] )
							: $hit['said'] )
					);
				}
			}
			echo '<tr><td>';
			// A title with markup in it — "<span> Military Patch </span> Russian
			// Z" — is a title with markup in it: the tags are the shop's, not
			// something to print at it.
			printf( '<a href="%s"><strong>%s</strong></a>', esc_url( $link ), esc_html( wp_strip_all_tags( $name ) ) );
			if ( ! empty( $also[ $oid ] ) ) {
				echo '<br /><span class="description">' . esc_html__( 'also:', 'dazont-ecom' ) . ' '
					. esc_html( implode( ' · ', array_slice( $also[ $oid ], 0, 4 ) ) ) . '</span>';
			}
			echo '</td>';
			if ( $goods ) {
				$one   = $facts[ $oid ] ?? [];
				$price = (string) ( $one['price'] ?? '' );
				$when  = strtotime( (string) ( $one['edited'] ?? '' ) ) ?: 0;
				echo '<td style="text-align:right;">' . ( '' === $price
					? '<span class="description">&mdash;</span>'
					: wp_kses_post( function_exists( 'wc_price' ) ? wc_price( (float) $price ) : esc_html( $price ) ) ) . '</td>';
				// The figure the shop actually decides on: an hour spent on a
				// product nobody buys is an hour spent for nobody.
				echo '<td style="text-align:right;">' . esc_html( number_format_i18n( (int) ( $one['sales'] ?? 0 ) ) ) . '</td>';
				echo '<td>' . ( $when
					? esc_html( wp_date( $fmt, $when ) )
					: '<span class="description">&mdash;</span>' ) . '</td>';
			}
			if ( 'fixed' === $show ) {
				// READ FROM THE QUEUE, never from a second record: what was
				// accepted onto this object, when, and a way back to it. A
				// product mended by hand has no row and says so rather than
				// claiming something was run.
				$dze_was = (array) ( $dze_done[ $oid ] ?? [] );
				echo '<td>';
				if ( $dze_was ) {
					$dze_when = strtotime( (string) $dze_was['when'] ) ?: 0;
					printf(
						'%1$s<br /><a href="%2$s">%3$s</a> <span class="description">%4$s</span>',
						esc_html( DZE_Queue::label_for( (string) $dze_was['kind'], $oid ) ),
						esc_url( DZE_Queue::url() ),
						esc_html__( 'Review', 'dazont-ecom' ),
						esc_html( $dze_when ? wp_date( $fmt, $dze_when ) : '' )
					);
				} else {
					echo '<span class="description">' . esc_html__( 'Edited by hand', 'dazont-ecom' ) . '</span>';
				}
				echo '</td>';
			} elseif ( $dze_tool ) {
				// The screen that does this kind of work, opened from the line
				// that needs it. It generates nothing and decides nothing — it
				// saves the walk back through three menus.
				printf(
					'<td style="text-align:right;"><a class="button button-small" href="%1$s">%2$s</a></td>',
					esc_url( (string) $dze_tool['url'] ),
					esc_html__( 'Open', 'dazont-ecom' )
				);
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		// The handlers. They were printed on the summary only, so every button
		// added to THIS screen did nothing at all — no error, no message, no
		// request: the one failure that looks exactly like a broken plugin.
		$this->print_script();
		if ( ! $ids ) {
			printf(
				'<p style="max-width:1100px;color:%s;font-weight:600;">%s</p>',
				'fixed' === $show ? '#50575e' : '#00794b',
				esc_html( 'fixed' === $show
					? __( 'Nothing has been mended since the last reading.', 'dazont-ecom' )
					: __( 'Nothing falls short of this any more.', 'dazont-ecom' ) )
			);
		}

		// WordPress's OWN pagination, in WordPress's own wrapper. The links
		// were printed bare in a paragraph: paginate_links() returns anchors
		// that the admin stylesheet only dresses inside .tablenav-pages, so
		// outside it they came out as a run of naked numbers.
		$pages = (int) ceil( count( $ids ) / self::PER_PAGE );
		if ( $pages > 1 ) {
			$links = paginate_links( [
				'base'      => add_query_arg( [ 'page' => self::MENU_SLUG, 'check' => $id, 'show' => $show, 'by' => $by, 'dir' => $dir, 'paged' => '%#%' ], admin_url( 'admin.php' ) ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'type'      => 'plain',
				'end_size'  => 1,
				'mid_size'  => 2,
				'prev_text' => '&lsaquo;',
				'next_text' => '&rsaquo;',
			] );
			printf(
				'<div class="tablenav bottom"><div class="tablenav-pages">'
				. '<span class="displaying-num">%1$s</span>'
				. '<span class="pagination-links">%2$s</span>'
				. '</div><br class="clear" /></div>',
				esc_html( sprintf(
					/* translators: %s: how many objects are on this list */
					_n( '%s item', '%s items', count( $ids ), 'dazont-ecom' ),
					number_format_i18n( count( $ids ) )
				) ),
				wp_kses_post( (string) $links )
			);
		}
	}

	/**
	 * Price, sales and last edit for a whole list, in ONE query.
	 *
	 * The list is what the shop works from, so it has to be sortable by the
	 * figure that decides which product is worth an hour — what it has SOLD.
	 * Sorting means knowing the figure for every row of the list, not for the
	 * fifty on screen, and a query per row would be a thousand queries. One
	 * query answers all three columns for the whole list, bounded by the
	 * thousand ids a list keeps.
	 *
	 * @param int[] $ids
	 * @return array<int,array{title:string,edited:string,sales:int,price:string}>
	 */
	private static function facts( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		$ids = array_filter( $ids );
		if ( ! $ids || ! $wpdb ) {
			return [];
		}
		$in = implode( ',', array_map( 'intval', $ids ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are cast to int above; there is no core API that answers this in one query.
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_modified,
				MAX( CASE WHEN m.meta_key = 'total_sales' THEN m.meta_value END ) AS sales,
				MAX( CASE WHEN m.meta_key = '_price'      THEN m.meta_value END ) AS price
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ( 'total_sales', '_price' )
			WHERE p.ID IN ( {$in} )
			GROUP BY p.ID, p.post_title, p.post_modified"
		);
		// phpcs:enable
		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->ID ] = [
				'title'  => (string) $row->post_title,
				'edited' => (string) $row->post_modified,
				'sales'  => (int) $row->sales,
				'price'  => (string) $row->price,
			];
		}
		return $out;
	}

	/** The criteria, keyed by their id — for a screen that has one in hand. */
	private static function rows_by_id(): array {
		$out = [];
		foreach ( self::rows() as $row ) {
			$out[ (string) ( $row['id'] ?? '' ) ] = $row;
		}
		return $out;
	}

	/** How a list may be ordered, and what each column is called. */
	private static function orders(): array {
		return [
			'found'  => __( 'As found', 'dazont-ecom' ),
			'sales'  => __( 'Sales', 'dazont-ecom' ),
			'price'  => __( 'Price', 'dazont-ecom' ),
			'edited' => __( 'Last edited', 'dazont-ecom' ),
			'name'   => __( 'Name', 'dazont-ecom' ),
		];
	}

	/** What one object is called and where it is edited. @return array{0:string,1:string} */
	/**
	 * The thing itself, for a screen that has to ask it a question.
	 *
	 * The same object the scan judged — a post, or a term — so a section
	 * heading is read from exactly what the reading was read from.
	 *
	 * @return mixed|null
	 */
	private static function object_for( string $scope, int $id ) {
		if ( 'category' === $scope ) {
			$term = get_term( $id, 'product_cat' );
			return ( $term && ! is_wp_error( $term ) ) ? $term : null;
		}
		return get_post( $id );
	}

	private static function object_link( string $scope, int $id ): array {
		if ( 'category' === $scope ) {
			$term = get_term( $id, 'product_cat' );
			return ( $term && ! is_wp_error( $term ) )
				? [ (string) $term->name, (string) get_edit_term_link( $id, 'product_cat' ) ]
				: [ '', '' ];
		}
		$post = get_post( $id );
		return $post ? [ (string) $post->post_title, (string) get_edit_post_link( $id, 'raw' ) ] : [ '', '' ];
	}

	private function print_script(): void {
		$nonce = wp_create_nonce( self::NONCE );
		?>
		<script>
		jQuery(function ($) {
			$('#dze-diag-scan').on('click', function () {
				var $b = $(this), $m = $('#dze-diag-msg');
				$b.prop('disabled', true);
				$m.text(<?php echo wp_json_encode( __( 'Reading the shop…', 'dazont-ecom' ) ); ?>);
				$.post(ajaxurl, { action: 'dze_diag_scan', nonce: <?php echo wp_json_encode( $nonce ); ?> })
					.done(function () { window.location.reload(); })
					.fail(function () {
						$b.prop('disabled', false);
						$m.text(<?php echo wp_json_encode( __( 'The reading did not finish — try again.', 'dazont-ecom' ) ); ?>);
					});
			});
		});
		</script>
		<?php
	}

	/**
	 * What a criterion can be asked ABOUT: a post type, or the categories.
	 *
	 * Read from the site rather than listed here, so a shop that adds a custom
	 * post type finds it in the menu without anybody touching this file. The
	 * products come first because that is where the work is, and every other
	 * public type follows in WordPress's own order.
	 *
	 * @return array<string,string> scope id => what to call it
	 */
	public static function scopes(): array {
		static $done = null;
		if ( null !== $done ) {
			return $done;
		}
		$out = [];
		if ( post_type_exists( 'product' ) ) {
			$out['product'] = __( 'Products', 'dazont-ecom' );
		}
		$out['category'] = __( 'Product categories', 'dazont-ecom' );
		foreach ( (array) get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
			$name = (string) ( $type->name ?? '' );
			// Products have a scope of their own, with prices and a gallery in
			// it; attachments are not pages anybody writes.
			if ( '' === $name || in_array( $name, [ 'product', 'attachment' ], true ) ) {
				continue;
			}
			$out[ $name ] = (string) ( $type->labels->name ?? $name );
		}
		$done = $out;
		return $out;
	}

	/**
	 * What WPML calls this scope in its own table.
	 *
	 * One place, because getting it wrong is silent: 'tax_product_cat' asked
	 * as 'post_product_cat' answers nothing, and a scope narrowed to nothing
	 * reads on the screen as a shop with no categories at all.
	 */
	private static function element_type( string $scope ): string {
		return 'category' === $scope ? 'tax_product_cat' : 'post_' . $scope;
	}

	/** Which set of fields a scope is asked with. */
	public static function family( string $scope ): string {
		if ( 'product' === $scope || 'category' === $scope ) {
			return $scope;
		}
		return 'post';
	}

	/**
	 * Everything that can be read on ONE post type.
	 *
	 * The menu used to be every field of every scope in one list, so choosing
	 * "Product · description" meant scrolling past the categories and the
	 * articles. The scope is chosen first now, and this is what it leaves.
	 *
	 * @return array<string,array>
	 */
	public static function fields_for( string $scope ): array {
		$family = self::family( $scope );
		$out    = [];
		foreach ( self::fields() as $id => $meta ) {
			if ( $family === ( $meta['scope'] ?? '' ) ) {
				$out[ $id ] = $meta;
			}
		}
		// BY NAME. Thirty fields in the order they happened to be written is a
		// menu you read from the top every time; in alphabetical order it is a
		// menu you aim at. Sorted here, where the menus are built, and not in
		// fields() itself — that order is what a fresh criterion opens on.
		uasort( $out, static fn( array $a, array $b ): int => strcasecmp( (string) ( $a['label'] ?? '' ), (string) ( $b['label'] ?? '' ) ) );
		return $out;
	}

	/**
	 * The custom fields ONE post type actually carries.
	 *
	 * Read from the database, not from what this plugin happens to write:
	 * the shop's own branding blocks, whatever wrote them, are in the list.
	 * Cached for an hour — a DISTINCT over postmeta is not a query to run
	 * because a settings page was opened.
	 *
	 * @return string[]
	 */
	public static function meta_keys( string $scope ): array {
		if ( 'category' === self::family( $scope ) ) {
			return [];
		}
		$type  = ( 'product' === $scope ) ? 'product' : $scope;
		$slot  = 'dze_diag_keys_' . md5( $type );
		$cached = get_transient( $slot );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$keys = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type = %s AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s
				 ORDER BY pm.meta_key LIMIT 400",
				$type,
				$wpdb->esc_like( '_oembed' ) . '%',
				$wpdb->esc_like( '_edit_' ) . '%'
			)
		);
		$keys = array_values( array_map( 'strval', $keys ) );
		// A variation's attribute is a meta key too, but on the VARIATION, so
		// the products' own list never held it. Offered here, "the colours" is
		// picked from a list instead of remembered as attribute_pa_couleur.
		if ( 'product' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$attrs = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE p.post_type = 'product_variation' AND pm.meta_key LIKE %s
					 ORDER BY pm.meta_key LIMIT 60",
					$wpdb->esc_like( 'attribute_' ) . '%'
				)
			);
			$keys = array_values( array_unique( array_merge( $keys, array_map( 'strval', $attrs ) ) ) );
		}
		set_transient( $slot, $keys, HOUR_IN_SECONDS );
		return $keys;
	}

	/**
	 * One criterion, as the card the prompts are edited in.
	 *
	 * The same card as the prompt library, on purpose: shut, it is a name and
	 * the rule in words; open, it is the field, the comparison and the figure
	 * that make it.
	 *
	 * @param array  $row   The criterion.
	 * @param string $index What the field names are numbered with — an integer
	 *                      for a saved row, __I__ for the blank one JavaScript
	 *                      clones, so the markup has one source and not two.
	 */
	private static function card( array $row, string $index ): string {
		$opt    = self::OPT;
		$scope  = (string) ( $row['scope'] ?? 'product' );
		$fields = self::fields_for( $scope );
		$field  = (string) ( $row['field'] ?? '' );
		if ( ! isset( $fields[ $field ] ) ) {
			$field = (string) array_key_first( $fields );
		}
		$op     = self::op_now( (string) ( $row['test'] ?? 'empty' ) );
		$takes  = (string) ( self::operators()[ $op ]['takes'] ?? '' );
		$name   = static fn( string $key ): string => esc_attr( $opt . '[rows][' . $index . '][' . $key . ']' );

		$out  = '<div class="dze-prb dze-diag-card">';
		$out .= '<div class="dze-prb-head">';
		$out .= '<label class="dze-switch dze-prb-on" title="' . esc_attr__( 'Count this criterion', 'dazont-ecom' ) . '">'
			. '<input type="checkbox" name="' . $name( 'on' ) . '" value="1"' . checked( 1, (int) ( $row['on'] ?? 1 ), false ) . ' />'
			. '<span class="dze-switch-slider"></span></label>';
		$out .= '<input type="hidden" name="' . $name( 'id' ) . '" value="' . esc_attr( (string) ( $row['id'] ?? '' ) ) . '" />';
		// The rule's own name, printed rather than typed. There is no title
		// box: a hand-written one says nothing the rule does not, and it stops
		// being true the moment a figure is changed. The scope is not repeated
		// either — the card sits under its post type's heading.
		$out .= '<strong class="dze-prb-name dze-diag-name" style="flex:0 1 auto;max-width:46%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'
			. esc_html( self::rule_named(
				[ 'field' => $field, 'test' => $op, 'value' => (int) ( $row['value'] ?? 0 ), 'find' => (string) ( $row['find'] ?? '' ), 'key' => (string) ( $row['key'] ?? '' ) ],
				$fields[ $field ] ?? [ 'label' => '' ]
			) ) . '</strong>';
		// Beside it, the one thing the rule cannot say: what to DO about it.
		$out .= '<span class="dze-prb-dest dze-diag-said">' . esc_html( (string) ( $row['note'] ?? '' ) ) . '</span>';
		$out .= '<button type="button" class="dze-prb-toggle dze-diag-toggle" aria-expanded="false">' . esc_html__( 'Edit', 'dazont-ecom' ) . ' <span class="dze-prb-caret">&#9656;</span></button>';
		$out .= '<button type="button" class="dze-pr-del dze-diag-drop" title="' . esc_attr__( 'Remove this criterion', 'dazont-ecom' ) . '">&#10005;</button>';
		$out .= '</div>';

		$out .= '<div class="dze-prb-body" style="display:none;"><p class="dze-prb-line">';
		// The post type first: everything below is about it, and a menu of
		// every field of every type in one list is a menu you scroll rather
		// than read.
		$out .= '<label><span>' . esc_html__( 'On', 'dazont-ecom' ) . '</span>'
			. '<select class="dze-diag-scope" name="' . $name( 'scope' ) . '">';
		foreach ( self::scopes() as $sid => $label ) {
			$out .= '<option value="' . esc_attr( $sid ) . '"' . selected( $sid, $scope, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$out .= '</select></label>';
		$out .= '<label><span>' . esc_html__( 'Looks at', 'dazont-ecom' ) . '</span>'
			. '<select class="dze-diag-field" name="' . $name( 'field' ) . '">';
		foreach ( $fields as $fid => $meta ) {
			$out .= '<option value="' . esc_attr( $fid ) . '"' . selected( $fid, $field, false ) . '>' . esc_html( (string) $meta['label'] ) . '</option>';
		}
		$out .= '</select></label>';
		$out .= '<input type="text" class="dze-diag-key" name="' . $name( 'key' ) . '" value="' . esc_attr( (string) ( $row['key'] ?? '' ) ) . '"'
			. ' list="dze-diag-keys-' . esc_attr( $scope ) . '" placeholder="' . esc_attr( (string) ( $fields[ $field ]['keyhint'] ?? __( 'choose a custom field', 'dazont-ecom' ) ) ) . '" style="width:230px;'
			. ( empty( $fields[ $field ]['key'] ) ? 'display:none;' : '' ) . '" />';
		$out .= '<label><span>' . esc_html__( 'Falls short when it', 'dazont-ecom' ) . '</span>'
			. '<select class="dze-diag-test" name="' . $name( 'test' ) . '">';
		foreach ( self::operators() as $oid => $meta ) {
			$out .= '<option value="' . esc_attr( $oid ) . '" data-takes="' . esc_attr( (string) $meta['takes'] ) . '"'
				. ' data-kinds="' . esc_attr( implode( ',', (array) $meta['kinds'] ) ) . '"'
				. selected( $oid, $op, false ) . '>' . esc_html( self::op_label( $oid ) ) . '</option>';
		}
		$out .= '</select></label>';
		// Hidden — not removed — while Conditional is on: the conditions are
		// the rule then, and a figure sitting beside them was a second rule
		// nobody had written. It is kept in the form so unticking the box
		// gives the criterion back exactly the figure it had.
		$dze_flat = 'number' === $takes && empty( $row['cond'] );
		$out .= '<input type="number" class="dze-diag-value" min="0" step="1" style="width:100px;' . ( $dze_flat ? '' : 'display:none;' ) . '"'
			. ' name="' . $name( 'value' ) . '" value="' . esc_attr( (string) (int) ( $row['value'] ?? 0 ) ) . '" />';
		$out .= '<input type="text" class="dze-diag-find" style="width:200px;' . ( 'text' === $takes ? '' : 'display:none;' ) . '"'
			. ' name="' . $name( 'find' ) . '" value="' . esc_attr( (string) ( $row['find'] ?? '' ) ) . '"'
			. ' placeholder="' . esc_attr__( 'text to look for', 'dazont-ecom' ) . '" />';
		$out .= '<span class="dze-diag-unit description"' . ( $dze_flat ? '' : ' style="display:none;"' ) . '>'
			. esc_html( self::unit_of( $field ) ) . '</span>';
		$out .= '</p>';
		$out .= self::bands_block( $row, $opt, $index, $field, (string) ( $row['scope'] ?? 'product' ) );
		// What this criterion is FOR. The hidden field goes first so the key
		// always arrives: without it, a criterion with both boxes cleared
		// would post nothing at all, and "neither" would be indistinguishable
		// from a form that was never asked — which is how a setting gets
		// quietly given back a value nobody chose.
		$mine = self::goals_of( $row );
		$out .= '<p class="dze-prb-line" style="align-items:center;">';
		$out .= '<span style="margin-right:2px;">' . esc_html__( 'Worth doing for', 'dazont-ecom' ) . '</span>';
		$out .= '<input type="hidden" name="' . esc_attr( $opt . '[rows][' . $index . '][goals][]' ) . '" value="" />';
		foreach ( self::goals() as $gid => $goal ) {
			$out .= '<label style="display:inline-flex;align-items:center;gap:5px;margin-right:14px;font-weight:600;" title="' . esc_attr( (string) $goal['what'] ) . '">'
				. '<input type="checkbox" class="dze-diag-goal" value="' . esc_attr( $gid ) . '"'
				. ' name="' . esc_attr( $opt . '[rows][' . $index . '][goals][]' ) . '"'
				. checked( true, in_array( $gid, $mine, true ), false ) . ' /> '
				. esc_html( (string) $goal['label'] ) . '</label>';
		}
		$out .= '</p>';
		// The description: written by the shop, shown on the Diagnostic under
		// the rule, and the place where "why this matters" belongs — the rule
		// itself only knows what it measures.
		$out .= '<p class="dze-prb-line"><label style="flex:1 1 100%;"><span>' . esc_html__( 'What to do about it', 'dazont-ecom' ) . '</span>'
			. '<input type="text" class="dze-diag-note" style="width:100%;max-width:640px;" name="' . $name( 'note' ) . '"'
			. ' value="' . esc_attr( (string) ( $row['note'] ?? '' ) ) . '"'
			. ' placeholder="' . esc_attr__( 'Add more photographs to these products, to improve the conversion rate.', 'dazont-ecom' ) . '" /></label></p>';
		$out .= '</div></div>';
		return $out;
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// The card styling belongs to the prompt library's stylesheet, and this
		// screen is the same list in the same clothes.
		if ( ! wp_style_is( 'dze-content', 'enqueued' ) ) {
			wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		}
		$opt    = self::OPT;
		$fields = self::fields();
		$rows   = self::rows();

		echo '<form method="post" action="options.php">';
		settings_fields( 'dze_diagnostic_options' );
		echo '<h2 class="title">' . esc_html__( 'Criteria', 'dazont-ecom' ) . '</h2>';
		echo '<p class="description" style="max-width:900px;">'
			. esc_html__( 'What the Diagnostic reads the shop against. Switching one off stops it being counted; nothing on the shop changes.', 'dazont-ecom' )
			. '</p>';
		// The shop's own custom fields, offered where a key is typed. A
		// criterion on "_bloc_text_2" is then picked from a list instead of
		// remembered — and it is still an ordinary criterion, written here
		// like every other one.
		// The custom fields of a post type, read from the database, so the
		// branding blocks this shop writes are in the menu whatever wrote
		// them. Only for the types a criterion is actually about: a DISTINCT
		// over postmeta is not a query to run thirteen times because a site
		// has thirteen public post types and a settings page was opened. The
		// rest are fetched the moment a card is switched to them.
		$used = [];
		foreach ( $rows as $row ) {
			$used[ (string) ( $row['scope'] ?? '' ) ] = true;
		}
		foreach ( array_keys( $used ) as $sid ) {
			$keys = isset( self::scopes()[ $sid ] ) ? self::meta_keys( $sid ) : [];
			if ( ! $keys ) {
				continue;
			}
			echo '<datalist id="dze-diag-keys-' . esc_attr( $sid ) . '">';
			foreach ( $keys as $one ) {
				echo '<option value="' . esc_attr( $one ) . '"></option>';
			}
			echo '</datalist>';
		}
		$lang = self::main_language();
		if ( '' !== $lang ) {
			echo '<p class="description" style="max-width:900px;">';
			printf(
				/* translators: %s: the shop's main language, e.g. English */
				esc_html__( 'Every criterion is read in %s, the shop\'s main language. A translation is WPML\'s copy of a page and is never counted as work waiting to be done.', 'dazont-ecom' ),
				'<strong>' . esc_html( self::language_name( $lang ) ) . '</strong>'
			);
			echo '</p>';
		}

		echo '<p style="margin:14px 0 10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">'
			. '<button type="button" class="button button-secondary" id="dze-diag-add">&#43; ' . esc_html__( 'Add a criterion', 'dazont-ecom' ) . '</button>'
			. '<button type="button" class="button" id="dze-diag-reset">&#8634; ' . esc_html__( 'Restore the shipped criteria', 'dazont-ecom' ) . '</button>'
			. '<label style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;">'
			. '<input type="checkbox" id="dze-diag-signs" name="' . esc_attr( $opt ) . '[signs]" value="1"' . checked( true, self::signs(), false ) . ' /> '
			. esc_html__( 'Write the comparisons as symbols (<, ≤, =)', 'dazont-ecom' )
			. '</label>'
			. '</p>';

		// A site can declare a dozen public post types — a funnel plugin alone
		// brings eight. A heading for each, with nothing under it, is a page
		// of headings: only the types this shop has written a criterion about
		// get one. The others are still in the "On" menu, which is where a
		// type is chosen.
		$by = [];
		foreach ( $rows as $row ) {
			$by[ (string) ( $row['scope'] ?? '' ) ][] = $row;
		}
		echo '<div id="dze-diag-lib" style="max-width:900px;">';
		$i = 0;
		foreach ( self::scopes() as $scope => $label ) {
			if ( empty( $by[ $scope ] ) ) {
				continue;
			}
			echo '<h3 class="dze-pr-grouphead">' . esc_html( $label ) . '</h3>';
			echo '<div class="dze-prlist" data-scope="' . esc_attr( $scope ) . '">';
			foreach ( $by[ $scope ] as $row ) {
				echo self::card( $row, (string) $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with per-value escaping in card().
				$i++;
			}
			echo '</div>';
		}
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'No criteria yet. Add one — the shipped list is one click away too.', 'dazont-ecom' ) . '</p>';
		}
		echo '<div class="dze-prlist dze-prlist-new" id="dze-diag-new" style="margin-top:8px;"></div>';
		echo '</div>';

		// Even a list emptied to nothing has to reach the sanitizer as a
		// deliberate emptiness, or it reads as a form that was about something
		// else and the criteria come back on the next page load.
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[rows_shown]" value="1" />';
		self::print_rows_script();
		submit_button();
		echo '</form>';
	}

	/** The card list's own behaviour: open one, add one, drop one, put them back. */
	private static function print_rows_script(): void {
		$keys = [];
		foreach ( self::fields() as $fid => $meta ) {
			$keys[ $fid ] = [
				'label' => (string) $meta['label'],
				'key'   => ! empty( $meta['key'] ),
				'keyname' => ! empty( $meta['keyname'] ),
				'keyhint' => (string) ( $meta['keyhint'] ?? '' ),
				'rule'    => self::default_rule( $fid ),
				'scope' => (string) $meta['scope'],
				'kind'  => (string) $meta['kind'],
				'unit'  => (string) ( $meta['unit'] ?? '' ),
			];
		}
		// Which fields each post type leaves, and what to call it — the menu
		// is rebuilt from this the moment the type changes.
		$by_scope = [];
		foreach ( self::scopes() as $sid => $label ) {
			$by_scope[ $sid ] = [
				'label'  => $label,
				// Every custom post type is a "post" as far as the fields go:
				// the tier menu is cut by that family, not by the type's name.
				'family' => self::family( $sid ),
				'fields' => array_keys( self::fields_for( $sid ) ),
			];
		}
		$ops = [];
		foreach ( self::operators() as $oid => $meta ) {
			$ops[ $oid ] = [
				'word'  => (string) $meta['word'],
				'sign'  => (string) $meta['sign'],
				'takes' => (string) $meta['takes'],
				'kinds' => array_values( (array) $meta['kinds'] ),
			];
		}
		[ $b_op, $b_val ] = self::default_rule( 'product.description' );
		$blank = [ 'id' => '', 'note' => '', 'scope' => (string) array_key_first( self::scopes() ), 'field' => 'product.description', 'test' => $b_op, 'value' => $b_val, 'find' => '', 'key' => '', 'goals' => array_keys( self::goals() ), 'on' => 1 ];
		?>
		<script type="text/template" id="dze-diag-tpl"><?php echo self::card( $blank, '__I__' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with per-value escaping in card(). ?></script>
		<script>
		jQuery( function ( $ ) {
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>,
				fields = <?php echo wp_json_encode( $keys ); ?>,
				scopes = <?php echo wp_json_encode( $by_scope ); ?>,
				ops = <?php echo wp_json_encode( $ops ); ?>,
				shipped = <?php echo wp_json_encode( array_values( self::default_rows() ) ); ?>,
				target = <?php echo wp_json_encode( __( 'holds fewer links than its own length calls for', 'dazont-ecom' ) ); ?>,
				name0 = <?php echo wp_json_encode( __( 'A criterion', 'dazont-ecom' ) ); ?>;

			function signs() { return $( '#dze-diag-signs' ).is( ':checked' ); }
			function opLabel( id, words ) {
				var o = ops[ id ]; if ( ! o ) { return id; }
				return ( ! words && signs() ) ? o.sign : o.word;
			}

			// The comparison half of a criterion. One source for it: the shut
			// card reads it with whatever this shop writes comparisons in, the
			// name box offers the same rule in words.
			function clause( f, op, takes, v, find, meta, words, tiers ) {
				if ( 'post.links' === f && 'lt' === op && 0 === v ) { return target; }
				if ( 'text' === takes ) { return opLabel( op, words ) + ' "' + find + '"'; }
				if ( 'number' === takes ) {
					// Every tier, in the order they are read, then the figure
					// itself — the same sentence the server writes. A head
					// saying "less than 6" while a $16.90 cap is judged on 3
					// is a head that stopped being true.
					// The conditions when there are any; the plain figure only
					// when it is what the criterion is actually judged by.
					var said = ( tiers && tiers.length ) ? tiers.join( '/' ) : String( v );
					return ( opLabel( op, words ) + ' ' + said + ' ' + meta.unit ).replace( /\s+$/, '' );
				}
				return opLabel( op, words );
			}
			// The tiers as the card holds them right now, in order.
			function tiersOf( $card ) {
				if ( ! $card.find( '.dze-diag-condon' ).is( ':checked' ) ) { return []; }
				var out = [];
				$card.find( '.dze-diag-bandrow' ).not( '.dze-diag-bandproto' ).each( function () {
					// A condition with no figure yet is not a figure: it put
					// "0/3" in the heading the moment it was added.
					var n = parseInt( jQuery( this ).find( '[name*="[want]"]' ).val(), 10 ) || 0;
					if ( n > 0 ) { out.push( n ); }
				} );
				return out;
			}

			// The card's own number. New cards are given one past every card on
			// the page, so two added in a row never post into the same slot.
			function nextIndex() {
				var max = 0;
				$( '#dze-diag-lib [name^="<?php echo esc_js( self::OPT ); ?>[rows]["]' ).each( function () {
					var m = /\[rows\]\[(\d+)\]/.exec( this.name );
					if ( m ) { max = Math.max( max, parseInt( m[1], 10 ) ); }
				} );
				return max + 1;
			}

			// Only the comparisons this field can answer: "contains" asked of a
			// count is a criterion that never fires and never says why.
			function fitOps( $card, kind ) {
				var $sel = $card.find( '.dze-diag-test' ), keep = $sel.val(), ok = false;
				$sel.find( 'option' ).each( function () {
					var $o = $( this ),
						fits = ( $o.data( 'kinds' ) + '' ).split( ',' ).indexOf( kind ) > -1;
					$o.prop( 'disabled', ! fits ).toggle( fits ).text( opLabel( $o.attr( 'value' ) ) );
					if ( fits && $o.attr( 'value' ) === keep ) { ok = true; }
				} );
				if ( ! ok ) { $sel.val( 'empty' ); }
			}

			// The post type decides what can be read: the field menu is cut to
			// it, and the custom-field box offers that type's own keys.
			function fitFields( $card ) {
				var scope = $card.find( '.dze-diag-scope' ).val(),
					meta = scopes[ scope ] || { fields: [] },
					$sel = $card.find( '.dze-diag-field' ),
					keep = $sel.val();
				if ( meta.fields.indexOf( keep ) > -1 && $sel.find( 'option' ).length === meta.fields.length ) {
					return; // already the right menu, and the right field in it
				}
				$sel.empty();
				jQuery.each( meta.fields, function ( n, id ) {
					$sel.append( jQuery( '<option></option>' ).attr( 'value', id ).text( ( fields[ id ] || {} ).label || id ) );
				} );
				$sel.val( meta.fields.indexOf( keep ) > -1 ? keep : meta.fields[0] );
				$card.find( '.dze-diag-key' ).attr( 'list', 'dze-diag-keys-' + scope );
				loadKeys( scope );
			}

			// The custom fields of a post type nobody had a criterion about:
			// asked for once, when a card is switched to it, and never on a
			// page load.
			var asked = {};
			function loadKeys( scope ) {
				if ( ! scope || asked[ scope ] || jQuery( '#dze-diag-keys-' + scope ).length ) {
					return;
				}
				asked[ scope ] = true;
				jQuery.post( ajaxurl, { action: 'dze_diag_keys', nonce: nonce, type: scope } )
					.done( function ( r ) {
						var keys = ( r && r.data && r.data.keys ) || [],
							$list = jQuery( '<datalist></datalist>' ).attr( 'id', 'dze-diag-keys-' + scope );
						jQuery.each( keys, function ( n, k ) {
							$list.append( jQuery( '<option></option>' ).attr( 'value', k ) );
						} );
						jQuery( 'body' ).append( $list );
					} );
			}

			// What a shut card says, kept true the moment a dropdown moves.
			function retell( $card ) {
				fitFields( $card );
				var f = $card.find( '.dze-diag-field' ).val(),
					meta = fields[ f ] || { label: '', key: false, kind: 'text', unit: '' },
					// A criterion on a custom field is called by its key, on
					// the shut line and in the name it is given — the same
					// rule the server writes it under.
					k = meta.key ? String( $card.find( '.dze-diag-key' ).val() || '' ).trim() : '',
					named = ! k ? meta.label : ( meta.keyname ? k : meta.label + ' (' + k + ')' );
				fitOps( $card, meta.kind );
				var op = $card.find( '.dze-diag-test' ).val(),
					takes = ( ops[ op ] || {} ).takes || '',
					v = parseInt( $card.find( '.dze-diag-value' ).val(), 10 ) || 0,
					find = $card.find( '.dze-diag-find' ).val() || '';
				$card.find( '.dze-diag-key' ).toggle( !! meta.key )
					.attr( 'placeholder', meta.keyhint || '' );
				// The figure is the rule only while Conditional is off.
				var flat = 'number' === takes && ! $card.find( '.dze-diag-condon' ).is( ':checked' );
				$card.find( '.dze-diag-value' ).toggle( flat );
				$card.find( '.dze-diag-find' ).toggle( 'text' === takes );
				$card.find( '.dze-diag-unit' ).text( flat ? meta.unit : '' ).toggle( flat );
				// Tiers only make sense for a figure, and only on a field the
				// object being judged can actually answer.
				// Wherever the RULE holds a figure — a word count as much as a
				// number of photographs. Tied to the field's type instead, it
				// gave conditions to the gallery and denied them to "less than
				// 120 words", which is the same question.
				$card.find( '.dze-diag-cond' ).toggle( 'number' === takes );
				fitBands( $card, f );
				// The head IS the rule, written out — so it follows every menu
				// and every figure as they move, and there is never a title on
				// screen that stopped being true two edits ago.
				var auto = ( named + ' ' + clause( f, op, takes, v, find, meta, true, tiersOf( $card ) ) ).replace( /\s+/g, ' ' ).replace( /^ | $/g, '' );
				$card.find( '.dze-diag-name' ).text( auto ? auto.charAt( 0 ).toUpperCase() + auto.slice( 1 ) : name0 );
				$card.find( '.dze-diag-said' ).text( $card.find( '.dze-diag-note' ).val() || '' );
			}

			// The same rule the server writes, so the row never disagrees with
			// itself between a page load and a menu change.
			function bandCounts( id ) {
				var m = fields[ id ] || {};
				return m.unit || m.label || '';
			}

			// Each condition names the field it is measured on. Only the
			// numbers of THIS post type, and never the criterion's own field —
			// a gallery placed by the size of the gallery says nothing.
			function fitBands( $card, self ) {
				var scope = $card.find( '.dze-diag-scope' ).val(),
					fam = ( scopes[ scope ] || {} ).family || scope;
				$card.find( '.dze-diag-bandfield' ).each( function () {
					var $sel = jQuery( this ), keep = $sel.val(), first = '', prefer = '';
					$sel.find( 'option' ).each( function () {
						var $o = jQuery( this ), id = $o.attr( 'value' ),
							ok = id && id !== self && $o.attr( 'data-scope' ) === fam;
						$o.prop( 'disabled', ! ok ).toggle( !! ok );
						if ( ok && ! first ) { first = id; }
						// What a shop actually bands on. Left to the first
						// field in the list, a fresh condition opened on
						// "main photograph", which places nothing.
						if ( ok && ! prefer && /\.price$/.test( id ) ) { prefer = id; }
					} );
					// A condition left pointing at a field this post type
					// cannot answer would never fire and never say why.
					if ( ! keep || $sel.find( 'option:selected' ).prop( 'disabled' ) ) { $sel.val( prefer || first ); }
				} );
				$card.find( '.dze-diag-bandunit' ).text( bandCounts( self ) );
			}

			// Where a card belongs: under its own post type's heading when the
			// page has one, and otherwise at the foot with a heading of its
			// own, so it is never filed under somebody else's.
			function home( $card ) {
				var scope = $card.find( '.dze-diag-scope' ).val(),
					$list = $( '#dze-diag-lib .dze-prlist[data-scope="' + scope + '"]' );
				if ( $list.length ) {
					$list.append( $card );
					$( '#dze-diag-new' ).each( function () {
						var $n = $( this );
						if ( ! $n.children().length ) { $n.prev( '.dze-pr-grouphead.is-new' ).remove(); }
					} );
					return;
				}
				var label = ( scopes[ scope ] || {} ).label || '';
				var $head = $( '#dze-diag-new' ).prev( '.dze-pr-grouphead.is-new' );
				if ( ! $head.length ) {
					$head = $( '<h3 class="dze-pr-grouphead is-new"></h3>' ).insertBefore( $( '#dze-diag-new' ) );
				}
				$head.text( label );
				$( '#dze-diag-new' ).attr( 'data-scope', scope ).append( $card );
			}

			function add( row ) {
				var html = $( '#dze-diag-tpl' ).html().replace( /__I__/g, String( nextIndex() ) ),
					$card = $( html );
				if ( row ) {
					$card.find( '.dze-diag-note' ).val( row.note || '' );
					$card.find( 'input[name$="[id]"]' ).val( row.id || '' );
					$card.find( '.dze-diag-scope' ).val( row.scope || 'product' );
					fitFields( $card );
					$card.find( '.dze-diag-field' ).val( row.field );
					$card.find( '.dze-diag-test' ).val( row.test );
					$card.find( '.dze-diag-value' ).val( row.value );
					$card.find( '.dze-diag-find' ).val( row.find || '' );
					$card.find( '.dze-diag-key' ).val( row.key || '' );
					$card.find( '.dze-diag-goal' ).each( function () {
						this.checked = -1 !== ( row.goals || [] ).indexOf( this.value );
					} );
					$card.find( '.dze-switch input' ).prop( 'checked', 0 !== row.on );
				}
				home( $card );
				retell( $card );
				return $card;
			}

			// One card open at a time — the same gesture as the prompt library.
			$( document ).on( 'click', '.dze-diag-toggle', function () {
				var $c = $( this ).closest( '.dze-diag-card' ), open = ! $c.hasClass( 'is-open' );
				$( '.dze-diag-card' ).removeClass( 'is-open' ).find( '.dze-prb-body' ).hide()
					.end().find( '.dze-diag-toggle' ).attr( 'aria-expanded', 'false' ).find( '.dze-prb-caret' ).text( '▸' );
				if ( open ) {
					$c.addClass( 'is-open' ).find( '.dze-prb-body' ).show();
					$c.find( '.dze-diag-toggle' ).attr( 'aria-expanded', 'true' ).find( '.dze-prb-caret' ).text( '▾' );
				}
			} );
			// Choosing another field asks that field's OWN question. Keeping the
			// last one's comparison and figure is how "variations with no
			// photograph is less than 120" happens: a rule that finds every
			// product in the shop and says nothing about any of them.
			$( document ).on( 'change', '.dze-diag-field', function () {
				var $card = $( this ).closest( '.dze-diag-card' ),
					rule = ( fields[ $( this ).val() ] || {} ).rule || [ 'empty', 0 ];
				$card.find( '.dze-diag-test' ).val( rule[0] );
				$card.find( '.dze-diag-value' ).val( rule[1] );
				retell( $card );
			} );
			// A card says which post type it is about, so it belongs under that
			// type's heading — not at the foot of the page under whatever
			// happened to be last, which is where a new one used to land while
			// its own menu said "Products".
			$( document ).on( 'change', '.dze-diag-scope', function () {
				home( $( this ).closest( '.dze-diag-card' ) );
			} );
			$( document ).on( 'change keyup input', '.dze-diag-bands select, .dze-diag-bands input:not(.dze-diag-condon)', function () {
				retell( $( this ).closest( '.dze-diag-card' ) );
			} );
			$( document ).on( 'change keyup input', '.dze-diag-scope, .dze-diag-field, .dze-diag-test, .dze-diag-value, .dze-diag-find, .dze-diag-key, .dze-diag-note', function () {
				retell( $( this ).closest( '.dze-diag-card' ) );
			} );
			// THE SWITCH. A criterion is one rule and one figure until somebody
			// asks for more; ticking this opens the conditions with a first one
			// already there, because an empty list under a box just ticked is a
			// press that answered with nothing. Unticking hides them and stops
			// them counting — it never throws them away.
			$( document ).on( 'change', '.dze-diag-condon', function () {
				var $card = $( this ).closest( '.dze-diag-card' ),
					on = $( this ).is( ':checked' );
				$card.find( '.dze-diag-bands' ).toggle( on );
				if ( on && ! $card.find( '.dze-diag-bandrow' ).not( '.dze-diag-bandproto' ).length ) {
					$card.find( '.dze-diag-bandadd' ).trigger( 'click' );
				}
				retell( $card );
			} );

			// A condition is added by copying the one above it, so the markup
			// lives in ONE place — card() — and a field added there is never
			// forgotten here. The index is renumbered afterwards, because two
			// conditions posting under the same key are one condition.
			$( document ).on( 'click', '.dze-diag-bandadd', function () {
				var $rows = $( this ).closest( '.dze-diag-bands' ).find( '.dze-diag-bandrows' ),
					$one  = $rows.find( '.dze-diag-bandproto' ).clone();
				if ( ! $one.length ) { return; }
				$one.removeClass( 'dze-diag-bandproto' ).css( 'display', 'flex' )
					.find( '[disabled]' ).prop( 'disabled', false );
				// Nothing chosen yet, so the recut below picks the field a shop
				// actually bands on rather than whatever sits first in the list.
				$one.find( '.dze-diag-bandfield' ).val( '' );
				$rows.find( '.dze-diag-bandproto' ).before( $one );
				renumberBands( $rows );
				// The copy carries the menu the PROTOTYPE was drawn with, which
				// is the blank card's field and not this criterion's. Cut it to
				// the card it just landed on, or a fresh condition offers the
				// very field being judged.
				retell( $( this ).closest( '.dze-diag-card' ) );
			} );
			$( document ).on( 'click', '.dze-diag-banddel', function () {
				var $rows = $( this ).closest( '.dze-diag-bandrows' );
				var $card = $( this ).closest( '.dze-diag-card' );
				$( this ).closest( '.dze-diag-bandrow' ).remove();
				renumberBands( $rows );
				retell( $card );
			} );
			function renumberBands( $rows ) {
				$rows.find( '.dze-diag-bandrow' ).not( '.dze-diag-bandproto' ).each( function ( i ) {
					$( this ).find( '[name]' ).each( function () {
						this.name = this.name.replace( /\[bands\]\[[^\]]*\]/, '[bands][' + i + ']' );
					} );
				} );
			}

			$( document ).on( 'click', '.dze-diag-drop', function () {
				$( this ).closest( '.dze-diag-card' ).remove();
			} );
			// Symbols or words is a preference with a visible consequence, so
			// the list is rewritten the moment it is ticked rather than after
			// a save nobody would connect to it.
			$( '#dze-diag-signs' ).on( 'change', function () {
				$( '.dze-diag-card' ).each( function () { retell( $( this ) ); } );
			} );
			$( '#dze-diag-add' ).on( 'click', function () {
				add( null ).addClass( 'is-open' ).find( '.dze-prb-body' ).show().end()
					.find( '.dze-prb-name' ).trigger( 'focus' );
			} );
			$( '#dze-diag-reset' ).on( 'click', function () {
				$( '#dze-diag-lib .dze-prb' ).remove();
				$.each( shipped, function ( n, r ) { add( r ); } );
			} );
			$( '.dze-diag-card' ).each( function () { retell( $( this ) ); } );
		} );
		</script>
		<?php
	}
}
