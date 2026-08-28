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

	/** Objects listed per criterion. The COUNT is always exact; the list is what a screen can show. */
	private const KEEP_IDS = 1000;

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
			'product.description'       => [ 'scope' => 'product',  'kind' => 'text',   'unit' => 'words',      'label' => __( 'description', 'dazont-ecom' ) ],
			'product.short_description' => [ 'scope' => 'product',  'kind' => 'text',   'unit' => 'words',      'label' => __( 'short description', 'dazont-ecom' ) ],
			'product.seo_title'         => [ 'scope' => 'product',  'kind' => 'text',   'unit' => 'characters', 'label' => __( 'SEO title', 'dazont-ecom' ) ],
			'product.seo_desc'          => [ 'scope' => 'product',  'kind' => 'text',   'unit' => 'characters', 'label' => __( 'SEO description', 'dazont-ecom' ) ],
			'product.sku'               => [ 'scope' => 'product',  'kind' => 'text',   'unit' => 'characters', 'label' => __( 'SKU', 'dazont-ecom' ) ],
			'product.meta'              => [ 'scope' => 'product',  'kind' => 'text',   'unit' => 'words',      'label' => __( 'custom field (text)', 'dazont-ecom' ), 'key' => true ],
			// --- Products, read as a number ---------------------------------
			'product.meta_number'       => [ 'scope' => 'product',  'kind' => 'number', 'unit' => '',           'label' => __( 'custom field (number)', 'dazont-ecom' ), 'key' => true ],
			'product.main_image'        => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'photographs','label' => __( 'main photograph', 'dazont-ecom' ) ],
			'product.main_image_width'  => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'px',         'label' => __( 'main photograph, width', 'dazont-ecom' ) ],
			'product.main_image_height' => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'px',         'label' => __( 'main photograph, height', 'dazont-ecom' ) ],
			'product.main_image_side'   => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'px',         'label' => __( 'main photograph, smallest side', 'dazont-ecom' ) ],
			'product.gallery'           => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'photographs','label' => __( 'gallery photographs', 'dazont-ecom' ) ],
			'product.image_meta'        => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'photographs','label' => __( 'photograph in a custom field', 'dazont-ecom' ), 'key' => true ],
			'product.links'             => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'links',      'label' => __( 'links in the description', 'dazont-ecom' ) ],
			'product.price'             => [ 'scope' => 'product',  'kind' => 'number', 'unit' => '',           'label' => __( 'price', 'dazont-ecom' ) ],
			'product.sale_price'        => [ 'scope' => 'product',  'kind' => 'number', 'unit' => '',           'label' => __( 'sale price', 'dazont-ecom' ) ],
			'product.stock'             => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'in stock',   'label' => __( 'stock', 'dazont-ecom' ) ],
			'product.weight'            => [ 'scope' => 'product',  'kind' => 'number', 'unit' => '',           'label' => __( 'weight', 'dazont-ecom' ) ],
			'product.categories'        => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'categories', 'label' => __( 'categories', 'dazont-ecom' ) ],
			'product.tags'              => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'tags',       'label' => __( 'tags', 'dazont-ecom' ) ],
			'product.attributes'        => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'attributes', 'label' => __( 'attributes', 'dazont-ecom' ) ],
			'product.variations'        => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'variations', 'label' => __( 'variations', 'dazont-ecom' ) ],
			'product.reviews'           => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'reviews',    'label' => __( 'reviews', 'dazont-ecom' ) ],
			'product.rating'            => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'stars',      'label' => __( 'average rating', 'dazont-ecom' ) ],
			'product.age'               => [ 'scope' => 'product',  'kind' => 'number', 'unit' => 'days',       'label' => __( 'days since published', 'dazont-ecom' ) ],
			// --- Categories and articles ------------------------------------
			'category.description'      => [ 'scope' => 'category', 'kind' => 'text',   'unit' => 'words',      'label' => __( 'description', 'dazont-ecom' ) ],
			'category.links'            => [ 'scope' => 'category', 'kind' => 'number', 'unit' => 'links',      'label' => __( 'internal links', 'dazont-ecom' ) ],
			'category.products'         => [ 'scope' => 'category', 'kind' => 'number', 'unit' => 'products',   'label' => __( 'products in it', 'dazont-ecom' ) ],
			'post.links'                => [ 'scope' => 'post',     'kind' => 'number', 'unit' => 'links',      'label' => __( 'internal links', 'dazont-ecom' ) ],
			'post.content'              => [ 'scope' => 'post',     'kind' => 'text',   'unit' => 'words',      'label' => __( 'the text itself', 'dazont-ecom' ) ],
			'post.title'                => [ 'scope' => 'post',     'kind' => 'text',   'unit' => 'characters', 'label' => __( 'title', 'dazont-ecom' ) ],
			'post.excerpt'              => [ 'scope' => 'post',     'kind' => 'text',   'unit' => 'words',      'label' => __( 'excerpt', 'dazont-ecom' ) ],
			'post.seo_title'            => [ 'scope' => 'post',     'kind' => 'text',   'unit' => 'characters', 'label' => __( 'SEO title', 'dazont-ecom' ) ],
			'post.seo_desc'             => [ 'scope' => 'post',     'kind' => 'text',   'unit' => 'characters', 'label' => __( 'SEO description', 'dazont-ecom' ) ],
			'post.meta'                 => [ 'scope' => 'post',     'kind' => 'text',   'unit' => 'words',      'label' => __( 'custom field', 'dazont-ecom' ), 'key' => true ],
			'post.updated'              => [ 'scope' => 'post',     'kind' => 'number', 'unit' => 'days ago',   'label' => __( 'last updated', 'dazont-ecom' ) ],
			'post.age'                  => [ 'scope' => 'post',     'kind' => 'number', 'unit' => 'days ago',   'label' => __( 'published', 'dazont-ecom' ) ],
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
			'empty'        => [ 'word' => __( 'is empty', 'dazont-ecom' ),              'sign' => __( 'is empty', 'dazont-ecom' ),     'takes' => '',       'kinds' => [ 'text', 'number' ] ],
			'filled'       => [ 'word' => __( 'is not empty', 'dazont-ecom' ),          'sign' => __( 'is not empty', 'dazont-ecom' ), 'takes' => '',       'kinds' => [ 'text', 'number' ] ],
			'lt'           => [ 'word' => __( 'is less than', 'dazont-ecom' ),          'sign' => '<',                                 'takes' => 'number', 'kinds' => [ 'text', 'number' ] ],
			'lte'          => [ 'word' => __( 'is at most', 'dazont-ecom' ),            'sign' => '≤',                                 'takes' => 'number', 'kinds' => [ 'text', 'number' ] ],
			'gt'           => [ 'word' => __( 'is more than', 'dazont-ecom' ),          'sign' => '>',                                 'takes' => 'number', 'kinds' => [ 'text', 'number' ] ],
			'gte'          => [ 'word' => __( 'is at least', 'dazont-ecom' ),           'sign' => '≥',                                 'takes' => 'number', 'kinds' => [ 'text', 'number' ] ],
			'eq'           => [ 'word' => __( 'equals', 'dazont-ecom' ),                'sign' => '=',                                 'takes' => 'number', 'kinds' => [ 'text', 'number' ] ],
			'neq'          => [ 'word' => __( 'does not equal', 'dazont-ecom' ),        'sign' => '≠',                                 'takes' => 'number', 'kinds' => [ 'text', 'number' ] ],
			'contains'     => [ 'word' => __( 'contains', 'dazont-ecom' ),              'sign' => '⊃',                                 'takes' => 'text',   'kinds' => [ 'text' ] ],
			'not_contains' => [ 'word' => __( 'does not contain', 'dazont-ecom' ),      'sign' => '⊅',                                 'takes' => 'text',   'kinds' => [ 'text' ] ],
		];
	}

	/** Whether the shop reads its comparisons as symbols rather than words. */
	public static function signs(): bool {
		return ! empty( self::settings()['signs'] );
	}

	/** One comparison, written the way this shop asked for it. */
	public static function op_label( string $op ): string {
		$row = self::operators()[ $op ] ?? null;
		if ( ! $row ) {
			return $op;
		}
		return self::signs() ? (string) $row['sign'] : (string) $row['word'];
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
	 * The criteria as they ship.
	 *
	 * Rows, not code — the same shape the shop edits, so "restore the default"
	 * is putting these back and nothing else. What is shipped is what a shop
	 * of any kind would ask first; everything past that is the owner's.
	 */
	public static function default_rows(): array {
		return [
			[ 'id' => 'prod_desc',    'scope' => 'product', 'label' => __( 'Description too short', 'dazont-ecom' ),           'field' => 'product.description',       'test' => 'lt',    'value' => 120, 'find' => '', 'key' => '', 'on' => 1 ],
			[ 'id' => 'prod_short',   'scope' => 'product', 'label' => __( 'No short description', 'dazont-ecom' ),            'field' => 'product.short_description', 'test' => 'empty', 'value' => 0,   'find' => '', 'key' => '', 'on' => 1 ],
			[ 'id' => 'prod_main',    'scope' => 'product', 'label' => __( 'No main photograph', 'dazont-ecom' ),              'field' => 'product.main_image',        'test' => 'empty', 'value' => 0,   'find' => '', 'key' => '', 'on' => 1 ],
			[ 'id' => 'prod_shot_px', 'scope' => 'product', 'label' => __( 'Main photograph too small', 'dazont-ecom' ),       'field' => 'product.main_image_side',   'test' => 'lt',    'value' => 800, 'find' => '', 'key' => '', 'on' => 1 ],
			[ 'id' => 'prod_gallery', 'scope' => 'product', 'label' => __( 'Gallery too thin', 'dazont-ecom' ),                'field' => 'product.gallery',           'test' => 'lt',    'value' => 3,   'find' => '', 'key' => '', 'on' => 1 ],
			[ 'id' => 'prod_seo_t',   'scope' => 'product', 'label' => __( 'SEO title too long', 'dazont-ecom' ),              'field' => 'product.seo_title',         'test' => 'gt',    'value' => 60,  'find' => '', 'key' => '', 'on' => 0 ],
			[ 'id' => 'cat_desc',     'scope' => 'category', 'label' => __( 'Category description too short', 'dazont-ecom' ),  'field' => 'category.description',      'test' => 'lt',    'value' => 150, 'find' => '', 'key' => '', 'on' => 1 ],
			[ 'id' => 'cat_links',    'scope' => 'category', 'label' => __( 'Category points at too little', 'dazont-ecom' ),   'field' => 'category.links',            'test' => 'lt',    'value' => 2,   'find' => '', 'key' => '', 'on' => 1 ],
			[ 'id' => 'post_links',   'scope' => 'post', 'label' => __( 'Article under its link target', 'dazont-ecom' ),   'field' => 'post.links',                'test' => 'lt',    'value' => 0,   'find' => '', 'key' => '', 'on' => 1 ],
			[ 'id' => 'post_stale',   'scope' => 'post', 'label' => __( 'Article going stale', 'dazont-ecom' ),            'field' => 'post.updated',              'test' => 'gt',    'value' => 365, 'find' => '', 'key' => '', 'on' => 1 ],
		];
	}

	/** The criteria in force: the shop's own, or the shipped ones. */
	public static function rows(): array {
		$saved = self::settings()['rows'] ?? null;
		$rows  = is_array( $saved ) ? self::clean_rows( $saved ) : [];
		return $rows ?: self::default_rows();
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
			$label = trim( sanitize_text_field( (string) ( $row['label'] ?? '' ) ) );
			// An article's length used to be a number of its own; it is the
			// length of its text, which the text field answers along with
			// everything else that can be asked of a text.
			$field = (string) ( $row['field'] ?? '' );
			$field = ( 'post.words' === $field ) ? 'post.content' : $field;
			// A criterion says which post type it is about. Written before
			// that was asked, it is about the one its field belongs to — and
			// an article criterion is about articles, which is what "post"
			// meant when it covered both.
			$scope = sanitize_key( (string) ( $row['scope'] ?? '' ) );
			$want  = (string) ( self::fields()[ $field ]['scope'] ?? 'product' );
			if ( '' === $scope || ! isset( self::scopes()[ $scope ] ) || self::family( $scope ) !== $want ) {
				$scope = ( 'post' === $want ) ? 'post' : $want;
			}
			if ( '' === $label || ! isset( $fields[ $field ] ) ) {
				continue; // a criterion with no name, or reading nothing, is not one.
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = sanitize_key( sanitize_title( $label ) ) ?: ( 'c' . ( count( $out ) + 1 ) );
			}
			while ( isset( $seen[ $id ] ) ) {
				$id .= '2';
			}
			$seen[ $id ] = true;
			$op = self::op_now( (string) ( $row['test'] ?? '' ) );
			// A comparison a field cannot answer is not saved as one: "contains"
			// asked of a count would be a criterion that never fires and never
			// says why.
			if ( ! isset( $ops[ $op ] ) || ! in_array( (string) $fields[ $field ]['kind'], (array) $ops[ $op ]['kinds'], true ) ) {
				$op = 'empty';
			}
			$out[] = [
				'id'    => $id,
				'label' => mb_substr( $label, 0, 80 ),
				'scope' => $scope,
				'field' => $field,
				'test'  => $op,
				'value' => max( 0, min( 100000, (int) ( $row['value'] ?? 0 ) ) ),
				'find'  => mb_substr( sanitize_text_field( (string) ( $row['find'] ?? '' ) ), 0, 120 ),
				'key'   => ! empty( $fields[ $field ]['key'] ) ? sanitize_text_field( (string) ( $row['key'] ?? '' ) ) : '',
				'on'    => empty( $row['on'] ) ? 0 : 1,
			];
		}
		return $out;
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
		// A photograph is never fixed by writing, and a paragraph is never
		// fixed in the image lab: the two halves of a product go to two
		// different screens.
		if ( false !== strpos( $field, 'image' ) || 'product.gallery' === $field ) {
			return [ 'label' => __( 'Image lab', 'dazont-ecom' ), 'url' => $tab( 'lab' ) ];
		}
		if ( in_array( $field, [ 'product.price', 'product.sale_price' ], true ) ) {
			return [ 'label' => __( 'Discounts', 'dazont-ecom' ), 'url' => $tab( 'discounts' ) ];
		}
		if ( in_array( $field, [ 'product.stock', 'product.sku', 'product.weight', 'product.categories', 'product.tags', 'product.attributes', 'product.variations', 'product.reviews', 'product.rating', 'product.age' ], true ) ) {
			return [ 'label' => __( 'Products', 'dazont-ecom' ), 'url' => admin_url( 'edit.php?post_type=product' ) ];
		}
		return [ 'label' => __( 'Bulk writing', 'dazont-ecom' ), 'url' => $bulk ];
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
				'row'   => $row,
			];
		}
		return $out;
	}

	/** One criterion said in words, for the screen. */
	private static function rule_said( array $row, array $field ): string {
		$scope = (string) ( $row['scope'] ?? '' );
		$named = trim( (string) ( self::scopes()[ $scope ] ?? '' ) . ' · ' . (string) $field['label'], ' ·' );
		return trim( $named . ' ' . self::rule_clause( $row ) ) . '.';
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
	private static function rule_clause( array $row ): string {
		$op = self::op_now( (string) ( $row['test'] ?? 'empty' ) );
		if ( 'post.links' === ( $row['field'] ?? '' ) && 'lt' === $op && 0 === (int) ( $row['value'] ?? 0 ) ) {
			return __( 'holds fewer links than its own length calls for', 'dazont-ecom' );
		}
		$takes = (string) ( self::operators()[ $op ]['takes'] ?? '' );
		if ( 'text' === $takes ) {
			return self::op_label( $op ) . ' "' . (string) ( $row['find'] ?? '' ) . '"';
		}
		if ( 'number' === $takes ) {
			// The unit is the field's own and can be blank (a price, a weight),
			// so the clause is assembled rather than templated: "is less than
			// 800 px" and "is more than 50" both have to come out clean.
			return trim( self::op_label( $op ) . ' ' . (int) ( $row['value'] ?? 0 ) . ' ' . self::unit_of( (string) ( $row['field'] ?? '' ) ) );
		}
		return self::op_label( $op );
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
				if ( 'product' === $scope ) {
					self::scan_products( $wanted, $scope, $hits, $seen );
				} elseif ( 'category' === $scope ) {
					self::scan_categories( $wanted, $scope, $hits, $seen );
				} else {
					self::scan_posts( $wanted, $scope, $hits, $seen );
				}
			}
		} finally {
			if ( '' !== $lang ) {
				do_action( 'wpml_switch_language', '' !== $back ? $back : null );
			}
			delete_transient( self::LOCK );
		}
		$out   = [ 'at' => time(), 'lang' => $lang, 'seen' => $seen, 'short' => array_fill_keys( array_keys( self::scopes() ), 0 ), 'checks' => [] ];
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
			$scope = (string) $meta['scope'];
			foreach ( $ids as $one ) {
				$short[ $scope ][ (int) $one ] = true;
			}
		}
		foreach ( $short as $scope => $set ) {
			$out['short'][ $scope ] = count( $set );
		}
		update_option( self::OPT_CENSUS, $out, false );
		update_option( self::OPT_LISTS, $lists, false );
		return $out;
	}

	/** @param array<string,int[]> $hits */
	private static function scan_products( array $wanted, string $scope, array &$hits, array &$seen ): void {
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
				return '' !== $key ? $meta( $key ) : '';
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
				return '' !== $key ? $meta( $key ) : '';
			case 'product.meta_number':
				return '' !== $key ? (float) $meta( $key ) : 0.0;
			case 'product.image_meta':
				return ( '' !== $key && (int) $meta( $key ) > 0 ) ? 1.0 : 0.0;
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
		$want  = (float) ( $row['value'] ?? 0 );
		$m     = self::measure( $field, $scope, $object, (string) ( $row['key'] ?? '' ) );

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

	private static function scan_categories( array $wanted, string $scope, array &$hits, array &$seen ): void {
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
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
	private static function scan_posts( array $wanted, string $scope, array &$hits, array &$seen ): void {
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
		$label   = __( 'Diagnostic', 'dazont-ecom' );
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

		echo '<h1>' . esc_html__( 'Diagnostic', 'dazont-ecom' ) . '</h1>';
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
			printf(
				/* translators: 1: how long ago, 2: products, 3: categories, 4: articles */
				esc_html__( 'Read %1$s ago — %2$d products, %3$d categories, %4$d articles and pages.', 'dazont-ecom' ),
				esc_html( human_time_diff( $at ) ),
				(int) ( $seen['product'] ?? 0 ),
				(int) ( $seen['category'] ?? 0 ),
				(int) ( $seen['post'] ?? 0 )
			);
		} else {
			esc_html_e( 'Never read yet — press the button, or wait for tonight.', 'dazont-ecom' );
		}
		echo '</span></p>';

		// A shop in five languages has to be told which one it is looking at,
		// or a thousand products against WooCommerce's five thousand reads as
		// a broken screen rather than a deliberate one.
		$lang = (string) ( $census['lang'] ?? self::main_language() );
		if ( '' !== $lang ) {
			echo '<p class="description" style="max-width:760px;margin-top:-6px;">';
			printf(
				/* translators: %s: the shop's main language, e.g. English */
				esc_html__( 'Read in %s only — the shop\'s main language. Translations are WPML\'s copies of these pages: they are translated, not written, so they are not counted here.', 'dazont-ecom' ),
				'<strong>' . esc_html( self::language_name( $lang ) ) . '</strong>'
			);
			echo '</p>';
		}

		// What is to be DONE, worst first, kept apart from what is already
		// right. Twenty lines reading "—" is a screen where the four that
		// matter are hard to find.
		$read  = (array) ( $census['checks'] ?? [] );
		$found = [];
		$clean = [];
		$fresh = [];
		foreach ( $checks as $id => $check ) {
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
			echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0 20px;">';
			foreach ( $where as $scope => $label ) {
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
			echo $at
				? esc_html__( 'Nothing falls short. Every criterion you have switched on is met, everywhere.', 'dazont-ecom' )
				: esc_html__( 'The shop has not been read yet — press "Read the shop again", or wait for tonight.', 'dazont-ecom' );
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
				echo '<td><strong>' . esc_html( $check['label'] ) . '</strong><br />'
					. '<span class="description">' . esc_html( (string) $check['why'] ) . '</span></td>';
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
						' <a class="button button-small button-primary" href="%s">%s &rarr;</a>',
						esc_url( (string) $tool['url'] ),
						esc_html( (string) $tool['label'] )
					);
				}
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

	private function render_list( string $id ): void {
		$check  = self::checks()[ $id ];
		$census = self::census();
		$lists  = self::lists();
		$ids    = (array) ( $lists[ $id ] ?? [] );
		$n      = (int) ( $census['checks'][ $id ] ?? 0 );
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$slice  = array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

		printf(
			'<h1>%s <a class="page-title-action" href="%s">%s</a></h1>',
			esc_html( $check['label'] ),
			esc_url( add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) ) ),
			esc_html__( 'Back to the diagnostic', 'dazont-ecom' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html( sprintf(
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

		echo '<table class="widefat striped" style="max-width:1100px;"><tbody>';
		foreach ( $slice as $oid ) {
			$oid = (int) $oid;
			[ $name, $link ] = self::object_link( (string) $check['scope'], $oid );
			if ( '' === $name ) {
				continue;
			}
			echo '<tr><td>';
			printf( '<a href="%s"><strong>%s</strong></a>', esc_url( $link ), esc_html( $name ) );
			if ( ! empty( $also[ $oid ] ) ) {
				echo '<br /><span class="description">' . esc_html__( 'also:', 'dazont-ecom' ) . ' '
					. esc_html( implode( ' · ', array_slice( $also[ $oid ], 0, 4 ) ) ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( count( $ids ) / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<p style="margin-top:12px;">' . wp_kses_post( paginate_links( [
				'base'      => add_query_arg( [ 'page' => self::MENU_SLUG, 'check' => $id, 'paged' => '%#%' ], admin_url( 'admin.php' ) ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => '‹',
				'next_text' => '›',
			] ) ) . '</p>';
		}
	}

	/** What one object is called and where it is edited. @return array{0:string,1:string} */
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
		$out .= '<input type="text" class="dze-prb-name" name="' . $name( 'label' ) . '" value="' . esc_attr( (string) ( $row['label'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Name this criterion', 'dazont-ecom' ) . '" />';
		$out .= '<span class="dze-prb-dest dze-diag-said">'
			. esc_html( trim( (string) ( self::scopes()[ $scope ] ?? $scope ) . ' · '
				. (string) ( $fields[ $field ]['label'] ?? '' ) . ' — ' . self::rule_clause( $row ) ) )
			. '</span>';
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
			. ' list="dze-diag-keys-' . esc_attr( $scope ) . '" placeholder="' . esc_attr__( 'choose a custom field', 'dazont-ecom' ) . '" style="width:230px;'
			. ( empty( $fields[ $field ]['key'] ) ? 'display:none;' : '' ) . '" />';
		$out .= '<label><span>' . esc_html__( 'Falls short when it', 'dazont-ecom' ) . '</span>'
			. '<select class="dze-diag-test" name="' . $name( 'test' ) . '">';
		foreach ( self::operators() as $oid => $meta ) {
			$out .= '<option value="' . esc_attr( $oid ) . '" data-takes="' . esc_attr( (string) $meta['takes'] ) . '"'
				. ' data-kinds="' . esc_attr( implode( ',', (array) $meta['kinds'] ) ) . '"'
				. selected( $oid, $op, false ) . '>' . esc_html( self::op_label( $oid ) ) . '</option>';
		}
		$out .= '</select></label>';
		$out .= '<input type="number" class="dze-diag-value" min="0" step="1" style="width:100px;' . ( 'number' === $takes ? '' : 'display:none;' ) . '"'
			. ' name="' . $name( 'value' ) . '" value="' . esc_attr( (string) (int) ( $row['value'] ?? 0 ) ) . '" />';
		$out .= '<input type="text" class="dze-diag-find" style="width:200px;' . ( 'text' === $takes ? '' : 'display:none;' ) . '"'
			. ' name="' . $name( 'find' ) . '" value="' . esc_attr( (string) ( $row['find'] ?? '' ) ) . '"'
			. ' placeholder="' . esc_attr__( 'text to look for', 'dazont-ecom' ) . '" />';
		$out .= '<span class="dze-diag-unit description">' . esc_html( self::unit_of( $field ) ) . '</span>';
		$out .= '</p>';
		$out .= '<p class="description dze-diag-hint">' . esc_html__( 'A text is compared by its length — words for a description, characters for a title. A custom field is named by its key, and the box offers the ones this shop writes into. An article held to "links is less than 0" is held to the figure its own length calls for.', 'dazont-ecom' ) . '</p>';
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
			. esc_html__( 'What the Diagnostic screen reads the shop against: a name, what is looked at, and the comparison it has to fail to be counted — the same vocabulary you filter an export with. Add your own, change a figure, remove one you do not care about. Every criterion you switch off simply stops being counted as work waiting to be done; nothing on the shop changes.', 'dazont-ecom' )
			. '</p>';
		echo '<p class="description" style="max-width:900px;">'
			. esc_html__( 'This list is the whole of it. Nothing else adds a line to the Diagnostic: what is on that screen is what is written here, in the order you wrote it.', 'dazont-ecom' )
			. '</p>';
		// The shop's own custom fields, offered where a key is typed. A
		// criterion on "_bloc_text_2" is then picked from a list instead of
		// remembered — and it is still an ordinary criterion, written here
		// like every other one.
		// One list of custom fields per post type, read from the database, so
		// the branding blocks this shop actually writes are in the menu
		// whatever wrote them.
		foreach ( array_keys( self::scopes() ) as $sid ) {
			$keys = self::meta_keys( $sid );
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

		echo '<div id="dze-diag-lib" style="max-width:900px;">';
		$i = 0;
		foreach ( self::scopes() as $scope => $label ) {
			echo '<h3 class="dze-pr-grouphead">' . esc_html( $label ) . '</h3>';
			echo '<div class="dze-prlist" data-scope="' . esc_attr( $scope ) . '">';
			foreach ( $rows as $row ) {
				if ( $scope !== ( $row['scope'] ?? '' ) ) {
					continue;
				}
				echo self::card( $row, (string) $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with per-value escaping in card().
				$i++;
			}
			echo '</div>';
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
		$blank = [ 'id' => '', 'label' => '', 'scope' => (string) array_key_first( self::scopes() ), 'field' => 'product.description', 'test' => 'lt', 'value' => 120, 'find' => '', 'key' => '', 'on' => 1 ];
		?>
		<script type="text/template" id="dze-diag-tpl"><?php echo self::card( $blank, '__I__' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with per-value escaping in card(). ?></script>
		<script>
		jQuery( function ( $ ) {
			var fields = <?php echo wp_json_encode( $keys ); ?>,
				scopes = <?php echo wp_json_encode( $by_scope ); ?>,
				ops = <?php echo wp_json_encode( $ops ); ?>,
				shipped = <?php echo wp_json_encode( array_values( self::default_rows() ) ); ?>,
				target = <?php echo wp_json_encode( __( 'holds fewer links than its own length calls for', 'dazont-ecom' ) ); ?>;

			function signs() { return $( '#dze-diag-signs' ).is( ':checked' ); }
			function opLabel( id ) {
				var o = ops[ id ]; if ( ! o ) { return id; }
				return signs() ? o.sign : o.word;
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
			}

			// What a shut card says, kept true the moment a dropdown moves.
			function retell( $card ) {
				fitFields( $card );
				var f = $card.find( '.dze-diag-field' ).val(),
					meta = fields[ f ] || { label: '', key: false, kind: 'text', unit: '' };
				fitOps( $card, meta.kind );
				var op = $card.find( '.dze-diag-test' ).val(),
					takes = ( ops[ op ] || {} ).takes || '',
					v = parseInt( $card.find( '.dze-diag-value' ).val(), 10 ) || 0,
					find = $card.find( '.dze-diag-find' ).val() || '',
					said;
				$card.find( '.dze-diag-key' ).toggle( !! meta.key );
				$card.find( '.dze-diag-value' ).toggle( 'number' === takes );
				$card.find( '.dze-diag-find' ).toggle( 'text' === takes );
				$card.find( '.dze-diag-unit' ).text( 'number' === takes ? meta.unit : '' );
				if ( 'post.links' === f && 'lt' === op && 0 === v ) {
					said = meta.label + ' — ' + target;
				} else if ( 'text' === takes ) {
					said = meta.label + ' — ' + opLabel( op ) + ' "' + find + '"';
				} else if ( 'number' === takes ) {
					said = ( meta.label + ' — ' + opLabel( op ) + ' ' + v + ' ' + meta.unit ).replace( /\s+$/, '' );
				} else {
					said = meta.label + ' — ' + opLabel( op );
				}
				$card.find( '.dze-diag-said' ).text(
					( ( scopes[ $card.find( '.dze-diag-scope' ).val() ] || {} ).label || '' ) + ' · ' + said
				);
			}

			function add( row ) {
				var html = $( '#dze-diag-tpl' ).html().replace( /__I__/g, String( nextIndex() ) ),
					$card = $( html );
				if ( row ) {
					$card.find( '.dze-prb-name' ).val( row.label || '' );
					$card.find( 'input[name$="[id]"]' ).val( row.id || '' );
					$card.find( '.dze-diag-scope' ).val( row.scope || 'product' );
					fitFields( $card );
					$card.find( '.dze-diag-field' ).val( row.field );
					$card.find( '.dze-diag-test' ).val( row.test );
					$card.find( '.dze-diag-value' ).val( row.value );
					$card.find( '.dze-diag-find' ).val( row.find || '' );
					$card.find( '.dze-diag-key' ).val( row.key || '' );
					$card.find( '.dze-switch input' ).prop( 'checked', 0 !== row.on );
				}
				var scope = $card.find( '.dze-diag-scope' ).val(),
					$list = row ? $( '#dze-diag-lib .dze-prlist[data-scope="' + scope + '"]' ) : $();
				( $list.length ? $list : $( '#dze-diag-new' ) ).append( $card );
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
			$( document ).on( 'change keyup', '.dze-diag-scope, .dze-diag-field, .dze-diag-test, .dze-diag-value, .dze-diag-find', function () {
				retell( $( this ).closest( '.dze-diag-card' ) );
			} );
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
