<?php
defined( 'ABSPATH' ) || exit;

/**
 * Translates the WRITTEN content of a product into the site's other languages,
 * and hands the result to WPML as a real translation.
 *
 * Why not WPML's own automatic translation: it bills per word in credits, on a
 * catalogue of several hundred products in two languages that is the biggest
 * line of the shop's software budget. The same words through the Anthropic key
 * already configured here cost a fraction of that, and come out in the shop's
 * own voice because the prompt and the glossary are ours.
 *
 * What this module does NOT touch: price, stock, attributes, images, taxonomy
 * structure. Those are WooCommerce Multilingual's job and it does it well —
 * the module even asks WPML to run its own custom-field sync when it creates a
 * translation, so the numbers arrive from the original rather than from us.
 *
 * Nothing is written without being read first: every translation is produced,
 * shown next to what the translation holds today, edited if needed, and only
 * then applied. A translation this module did not write is never overwritten.
 */
final class DZE_Translate {

	// The screen this module now has of its own: Dazont Ecom → WPML
	// Translations. It lives in its own file because it is a screen and this
	// file is the machinery — the same split as DZE_Content and its AJAX half.
	use DZE_Translate_Screen;

	public const OPT   = 'dze_translate_settings';
	public const NONCE = 'dze_translate';

	/** Marks a translation as ours, with the source fingerprint it came from. */
	private const META_HASH = '_dze_tr_hash';
	private const META_MINE = '_dze_tr_by';
	/**
	 * WHAT WAS TRANSLATED, FIELD BY FIELD — the register that decides whether
	 * anything is paid for at all.
	 *
	 * One md5 of the SOURCE text per field, kept on the translation. When a
	 * product comes back round, the module compares field by field and sends
	 * only what actually moved: a changed title does not re-pay for fifteen
	 * hundred characters of description, and a product WPML re-marked because
	 * its category was renamed sends nothing at all.
	 *
	 * It lives on the translation rather than in a table of its own: there is
	 * nothing to create or migrate, WordPress throws it away with the
	 * translation, and the same key answers for a product, an article, a page
	 * and a term. Nothing ever asks it a question across the catalogue — the
	 * queue asks WPML which rows are marked, and this is consulted one object
	 * at a time.
	 */
	private const META_SRC  = '_dze_tr_src';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Admin only, by nature: nothing here has any business on a shop page.
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'screen_assets' ] );
		add_action( 'add_meta_boxes', [ $this, 'popup_hook' ] );
		add_action( 'wp_ajax_dze_tr_preview', [ $this, 'ajax_preview' ] );
		add_action( 'wp_ajax_dze_tr_apply', [ $this, 'ajax_apply' ] );
		// The module's own screen, and the three presses on it.
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'wp_ajax_dze_tr_batch', [ $this, 'ajax_batch' ] );
		add_action( 'wp_ajax_dze_tr_panel', [ $this, 'ajax_panel' ] );
		add_action( 'wp_ajax_dze_tr_decide', [ $this, 'ajax_decide' ] );
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function get_settings(): array {
		$s = get_option( self::OPT, [] );
		return is_array( $s ) ? $s : [];
	}

	public function register_settings(): void {
		register_setting( 'dze_translate_options', self::OPT, [
			'sanitize_callback' => [ $this, 'sanitize' ],
			'autoload'          => false, // no shop page ever reads this.
		] );
	}

	public function sanitize( $in ): array {
		// WordPress hands a sanitizer NULL when the submitted page did not
		// carry this option at all. That is not "the shop emptied it": it is
		// "another form was saved", and answering with defaults is how a
		// setting disappears after an update nobody connected to it.
		if ( null === $in ) {
			return self::get_settings();
		}

		$in  = is_array( $in ) ? $in : [];
		$out = self::get_settings();
		if ( isset( $in['prompt'] ) ) {
			$p = trim( sanitize_textarea_field( (string) $in['prompt'] ) );
			$out['prompt'] = ( $p === trim( self::default_prompt() ) ) ? '' : $p;
		}
		if ( isset( $in['glossary'] ) ) {
			$out['glossary'] = sanitize_textarea_field( (string) $in['glossary'] );
		}
		if ( isset( $in['model'] ) ) {
			$out['model'] = sanitize_text_field( (string) $in['model'] );
		}
		if ( isset( $in['fields'] ) ) {
			$out['fields'] = array_values( array_intersect(
				array_map( 'sanitize_key', (array) $in['fields'] ),
				array_keys( self::fields() )
			) );
		}
		if ( isset( $in['create'] ) ) {
			$out['create'] = ! empty( $in['create'] ) ? 1 : 0;
		}
		// The extra things this shop translates. The section that owns this
		// list posts `scope_sent` whether or not a single box is ticked —
		// without it, unticking the last one would leave the old list standing
		// for ever, which is a control that cannot be undone.
		if ( ! empty( $in['scope_sent'] ) ) {
			$out['scope'] = array_values( array_intersect(
				array_map( 'sanitize_text_field', (array) ( $in['scope'] ?? [] ) ),
				array_keys( self::scope() )
			) );
		}
		return $out;
	}

	/** The shipped instructions. Empty in settings = these. */
	public static function default_prompt(): string {
		$shipped = "You translate e-commerce product copy for an online shop.\n\n"
			. "- Translate the MEANING, not the words: the result must read as if it had been written by a native copywriter of that market, never as a translation.\n"
			. "- Keep the selling tone and the level of technical detail of the original. Do not add, remove or soften an argument.\n"
			. "- Keep the HTML structure EXACTLY as it is: same tags, same attributes, same order. Translate only the text between the tags.\n"
			. "- Keep measurements, sizes, references, model names and figures identical. Convert nothing.\n"
			. "- A meta description stays under 155 characters; a meta title under 60. Rewrite rather than truncate.\n"
			. "- Never translate a brand name, a product reference, or any term listed in the glossary.";
		return class_exists( 'DZE_Prompt_Defaults' )
			? DZE_Prompt_Defaults::pick( 'translate', $shipped )
			: $shipped;
	}

	/**
	 * What the plugin sends WITH this prompt, listed for the popup that shows
	 * it. Written beside the code that builds the call, so the list and the
	 * call are read and changed together.
	 *
	 * @return string[]
	 */
	public static function prompt_data( string $id = '' ): array {
		return [
			__( 'The fields to translate, each one named, in the original language.', 'dazont-ecom' ),
			__( 'The language to translate into.', 'dazont-ecom' ),
			__( 'The answer format — the same fields back, translated, nothing added.', 'dazont-ecom' ),
		];
	}

	public static function prompt(): string {
		$p = trim( (string) ( self::get_settings()['prompt'] ?? '' ) );
		return '' !== $p ? $p : self::default_prompt();
	}

	/** Terms that stay as they are, one per line. */
	public static function glossary(): array {
		$raw = (string) ( self::get_settings()['glossary'] ?? '' );
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ?: [] ) ) );
	}

	/**
	 * The text a product carries, and where each piece lives.
	 *
	 * Only written content: everything else belongs to WooCommerce Multilingual.
	 */
	public static function fields( string $kind = 'post' ): array {
		if ( 'term' === $kind ) {
			// A TERM IS ITS NAME AND ITS DESCRIPTION, and nothing else here.
			// Its SEO fields belong to the SEO plugin's own storage — Yoast
			// keeps a taxonomy's in one option of its own rather than in term
			// meta — and a field this module writes to the wrong place is a
			// field the shop believes is translated and nobody ever sees.
			return [
				'name'        => [ 'label' => __( 'Name', 'dazont-ecom' ),        'type' => 'term', 'key' => 'name',        'html' => false ],
				'description' => [ 'label' => __( 'Description', 'dazont-ecom' ), 'type' => 'term', 'key' => 'description', 'html' => true ],
			];
		}
		return [
			'title'    => [ 'label' => __( 'Title', 'dazont-ecom' ),                    'type' => 'post', 'key' => 'post_title',   'html' => false ],
			'content'  => [ 'label' => __( 'Description', 'dazont-ecom' ),              'type' => 'post', 'key' => 'post_content', 'html' => true ],
			'excerpt'  => [ 'label' => __( 'Short description', 'dazont-ecom' ),        'type' => 'post', 'key' => 'post_excerpt', 'html' => true ],
			'seo_title'=> [ 'label' => __( 'SEO title', 'dazont-ecom' ),                'type' => 'meta', 'key' => '',             'html' => false ],
			'seo_desc' => [ 'label' => __( 'SEO description', 'dazont-ecom' ),          'type' => 'meta', 'key' => '',             'html' => false ],
			// A THIRD OF THE SHOP'S PRODUCT TEXT LIVED OUTSIDE THESE FIVE
			// FIELDS. The theme keeps two written blocks in custom fields —
			// 614,295 characters across 1,525 and 566 products on this
			// catalogue, against 1,849,581 in post_content — and the module
			// translated none of it, so a French product page came out a third
			// in English with nothing anywhere saying why. It is customer copy,
			// not plumbing.
			//
			// `_purchase_note` and `_button_text` were considered and left out
			// on purpose: they are empty on all 2,105 English products here,
			// and a dead field on a screen is a field somebody has to decide
			// about every time.
			'block_text_1' => [ 'label' => __( 'Content block 1', 'dazont-ecom' ), 'type' => 'meta', 'key' => 'block_text_1', 'html' => true ],
			'block_text_2' => [ 'label' => __( 'Content block 2', 'dazont-ecom' ), 'type' => 'meta', 'key' => 'block_text_2', 'html' => true ],
		];
	}

	/**
	 * Which fields are actually sent. Absent setting = all of them.
	 *
	 * The setting names POST fields — it was written when this module only
	 * knew about products — so a term's two fields are never narrowed by it:
	 * a shop that ticked "title and description" for its products has not said
	 * anything about its categories, and reading it as though it had would
	 * silently stop translating them.
	 */
	public static function active_fields( string $kind = 'post' ): array {
		$all = self::fields( $kind );
		if ( 'post' !== $kind ) {
			return $all;
		}
		$saved = (array) ( self::get_settings()['fields'] ?? [] );
		if ( ! $saved ) {
			return $all;
		}
		return array_intersect_key( $all, array_flip( $saved ) );
	}

	// =========================================================================
	// WHAT CAN BE TRANSLATED: an OBJECT, not a product
	//
	// The module was built for products and every function took a product id.
	// A shop translates its articles, its pages, its aisles and every
	// attribute term it sells by — "ça doit concerner les articles de blog,
	// pages, produits, catégories produit" — and a second module beside this
	// one would be two prompts, two registers and two screens to keep in step.
	//
	// So there is ONE thing here: an object. A post of a type WPML translates,
	// or a term of a taxonomy WPML translates. Every function below takes one,
	// and the product path is the same path with `post`/`product` in it.
	// =========================================================================

	/**
	 * An object descriptor, checked against what WPML will actually link.
	 *
	 * @return array{kind:string,id:int,type:string}|array{} [] when the shop
	 *         has no business translating this.
	 */
	public static function obj( string $kind, int $id, string $type = '' ): array {
		$kind = 'term' === $kind ? 'term' : 'post';
		$id   = (int) $id;
		if ( $id <= 0 ) {
			return [];
		}
		if ( 'term' === $kind ) {
			$term = $type ? get_term( $id, $type ) : get_term( $id );
			if ( ! $term || is_wp_error( $term ) ) {
				return [];
			}
			$tax = (string) $term->taxonomy;
			return DZE_Wpml::is_translated_taxonomy( $tax )
				? [ 'kind' => 'term', 'id' => (int) $term->term_id, 'type' => $tax ]
				: [];
		}
		$pt = (string) get_post_type( $id );
		if ( '' === $pt ) {
			return [];
		}
		return DZE_Wpml::is_translated_type( $pt )
			? [ 'kind' => 'post', 'id' => $id, 'type' => $pt ]
			: [];
	}

	/** The same object as one string, so a form and a job can carry it. */
	public static function ref( array $o ): string {
		return ( $o['kind'] ?? 'post' ) . ':' . (int) ( $o['id'] ?? 0 ) . ':' . ( $o['type'] ?? '' );
	}

	/** And back — re-checked against WPML, never trusted as it arrives. */
	public static function from_ref( string $ref ): array {
		$bits = explode( ':', $ref );
		return self::obj(
			sanitize_key( $bits[0] ?? '' ),
			absint( $bits[1] ?? 0 ),
			sanitize_key( $bits[2] ?? '' )
		);
	}

	/**
	 * THE SIX THIS SHOP ALWAYS TRANSLATES, and everything else is a choice.
	 *
	 * "Je ne vais pas tout traduire. Donc dès le début, une liste créable
	 * manuellement des posts qu'on veut traduire automatiquement. Il va falloir
	 * inclure de facto les pages, posts, product category, products, product
	 * tags, et les attributs produits. Le reste c'est en option."
	 *
	 * Attributes are not named one by one — a shop adds one next month and it
	 * would be missing from a list written today — so every `pa_*` taxonomy is
	 * in by the same rule.
	 */
	public static function always(): array {
		return [ 'post:page', 'post:post', 'post:product', 'term:product_cat', 'term:product_tag' ];
	}

	/** Is this one of the things the shop never has to choose? */
	public static function is_always( string $key ): bool {
		return in_array( $key, self::always(), true ) || 0 === strpos( $key, 'term:pa_' );
	}

	/**
	 * WHAT THE SHOP HAS CHOSEN TO TRANSLATE — the six, plus whatever was
	 * ticked in Settings → Translation.
	 *
	 * An absent setting is not an empty choice: a shop updating to this
	 * version keeps translating what it always did, and the extras start
	 * unticked.
	 */
	public static function picked_scope(): array {
		$all    = self::scope();
		$picked = self::get_settings()['scope'] ?? null;
		$extra  = is_array( $picked ) ? array_map( 'strval', $picked ) : [];
		$out    = [];
		foreach ( $all as $key => $one ) {
			if ( self::is_always( $key ) || in_array( $key, $extra, true ) ) {
				$out[ $key ] = $one;
			}
		}
		return $out;
	}

	/**
	 * EVERYTHING THIS SHOP MAY TRANSLATE, as WPML has been set up — never a
	 * list of our own.
	 *
	 * A type or a taxonomy WPML does not translate is not offered: writing a
	 * translation WPML will not link leaves an orphan post in another language
	 * and nothing anywhere saying why. This is the MENU of what could be
	 * chosen; `picked_scope()` is what the shop actually works on.
	 *
	 * @return array<string,array{kind:string,type:string,label:string}>
	 */
	public static function scope(): array {
		$out = [];
		if ( ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			return $out;
		}
		foreach ( array_keys( DZE_Wpml::translatable_types() ) as $type ) {
			$obj = get_post_type_object( $type );
			if ( ! $obj || empty( $obj->public ) ) {
				continue; // a private type is plumbing, not something a shop reads.
			}
			$out[ 'post:' . $type ] = [
				'kind'  => 'post',
				'type'  => $type,
				'label' => (string) ( $obj->labels->name ?? $type ),
			];
		}
		foreach ( array_keys( DZE_Wpml::translatable_taxonomies() ) as $tax ) {
			$obj = get_taxonomy( $tax );
			if ( ! $obj ) {
				continue;
			}
			$out[ 'term:' . $tax ] = [
				'kind'  => 'term',
				'type'  => $tax,
				'label' => (string) ( $obj->labels->name ?? $tax ),
				// Product attributes are taxonomies like any other, and a shop
				// with fifteen of them wants to know which is which.
				'attr'  => 0 === strpos( $tax, 'pa_' ),
			];
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Reading and writing one object, whichever kind it is
	// -------------------------------------------------------------------------

	/** The language an object is written in. */
	public static function obj_language( array $o ): string {
		if ( ! $o || ! class_exists( 'DZE_Wpml' ) ) {
			return '';
		}
		if ( 'term' === $o['kind'] ) {
			$details = apply_filters( 'wpml_element_language_details', null, [
				'element_id'   => DZE_Wpml::term_element_id( (int) $o['id'], (string) $o['type'] ),
				'element_type' => DZE_Wpml::element_name( 'term', (string) $o['type'] ),
			] );
			$code = is_object( $details ) ? (string) ( $details->language_code ?? '' ) : '';
			return '' !== $code ? $code : DZE_Wpml::default_language();
		}
		return DZE_Wpml::post_language( (int) $o['id'], (string) $o['type'] ) ?: DZE_Wpml::default_language();
	}

	/** The languages this object can be translated INTO. */
	public static function obj_targets( array $o ): array {
		if ( ! $o || ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			return [];
		}
		$src = self::obj_language( $o );
		$out = [];
		foreach ( DZE_Wpml::get_active_languages() as $l ) {
			$code = (string) ( $l['code'] ?? '' );
			if ( '' === $code || $code === $src ) {
				continue;
			}
			$out[ $code ] = (string) ( $l['native_name'] ?? strtoupper( $code ) );
		}
		return $out;
	}

	/** @return array<string,string> field id => text, empty fields dropped. */
	public static function obj_read( array $o ): array {
		if ( ! $o ) {
			return [];
		}
		$out = [];
		if ( 'term' === $o['kind'] ) {
			$term = get_term( (int) $o['id'], (string) $o['type'] );
			if ( ! $term || is_wp_error( $term ) ) {
				return [];
			}
			foreach ( self::active_fields( 'term' ) as $fid => $f ) {
				$v = trim( (string) ( $term->{$f['key']} ?? '' ) );
				if ( '' !== $v ) {
					$out[ $fid ] = $v;
				}
			}
			return $out;
		}
		$post = get_post( (int) $o['id'] );
		if ( ! $post ) {
			return [];
		}
		foreach ( self::active_fields( 'post' ) as $fid => $f ) {
			if ( 'post' === $f['type'] ) {
				$v = (string) ( $post->{$f['key']} ?? '' );
			} else {
				$key = self::meta_key_for( $fid );
				$v   = '' !== $key ? (string) get_post_meta( (int) $o['id'], $key, true ) : '';
			}
			$v = trim( $v );
			if ( '' !== $v ) {
				$out[ $fid ] = $v;
			}
		}
		return $out;
	}

	/** The existing translation of an object in one language, or 0. */
	public static function obj_translation( array $o, string $lang ): int {
		if ( ! $o || ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			return 0;
		}
		// $return_original = false answers 0 rather than the original when the
		// translation does not exist — which is the question being asked.
		return (int) apply_filters( 'wpml_object_id', (int) $o['id'], (string) $o['type'], false, $lang );
	}

	/** The register lives on the translation: post meta, or term meta. */
	private static function meta_read( array $o, int $id, string $key ): string {
		return 'term' === ( $o['kind'] ?? 'post' )
			? (string) get_term_meta( $id, $key, true )
			: (string) get_post_meta( $id, $key, true );
	}

	private static function meta_write( array $o, int $id, string $key, string $value ): void {
		if ( 'term' === ( $o['kind'] ?? 'post' ) ) {
			update_term_meta( $id, $key, $value );
			return;
		}
		update_post_meta( $id, $key, $value );
	}

	/**
	 * Writes translated text onto a translation, of either kind.
	 *
	 * @param array<string,string> $texts
	 */
	public static function obj_write( array $o, int $target_id, array $texts ): void {
		if ( ! $o || ! $target_id ) {
			return;
		}
		if ( 'term' === $o['kind'] ) {
			$args = [];
			foreach ( self::fields( 'term' ) as $fid => $f ) {
				if ( ! isset( $texts[ $fid ] ) ) {
					continue;
				}
				$args[ $f['key'] ] = $f['html']
					? wp_kses_post( (string) $texts[ $fid ] )
					: sanitize_text_field( (string) $texts[ $fid ] );
			}
			if ( $args ) {
				wp_update_term( $target_id, (string) $o['type'], $args );
			}
			return;
		}
		$post = [];
		foreach ( self::fields( 'post' ) as $fid => $f ) {
			if ( ! isset( $texts[ $fid ] ) ) {
				continue;
			}
			if ( 'post' === $f['type'] ) {
				$post[ $f['key'] ] = $f['html']
					? wp_kses_post( (string) $texts[ $fid ] )
					: sanitize_text_field( (string) $texts[ $fid ] );
				continue;
			}
			$key = self::meta_key_for( $fid );
			if ( '' !== $key ) {
				// A FIELD WPML COPIES MUST NEVER BE WRITTEN BY US: the next
				// sync puts the original's value straight back over the
				// translation, and the words are lost with nothing saying so.
				if ( 1 === DZE_Wpml::custom_field_mode( $key ) || 3 === DZE_Wpml::custom_field_mode( $key ) ) {
					continue;
				}
				// A CUSTOM FIELD IS NOT ALWAYS A LINE OF TEXT. The SEO pair is,
				// and the theme's content blocks are paragraphs — several
				// hundred thousand characters of them. Flattened through
				// sanitize_text_field() the translation would arrive with its
				// markup stripped, which is not a translation of what was read.
				// The field says which it is, exactly like a post field.
				update_post_meta( $target_id, $key, $f['html']
					? wp_kses_post( (string) $texts[ $fid ] )
					: sanitize_text_field( (string) $texts[ $fid ] ) );
			}
		}
		if ( $post ) {
			$post['ID'] = $target_id;
			wp_update_post( $post );
		}
	}

	/**
	 * Creates the translation of a TERM and hands it to WPML.
	 *
	 * A term translation is a term of its own in the same taxonomy, linked by
	 * WPML's translation group on the TERM TAXONOMY id. Its parent is the
	 * translation of the original's parent when there is one, so an aisle
	 * keeps its place in the tree rather than landing at the root.
	 */
	private static function create_term( array $o, string $lang ): int {
		$term = get_term( (int) $o['id'], (string) $o['type'] );
		if ( ! $term || is_wp_error( $term ) ) {
			throw new RuntimeException( __( 'Term not found.', 'dazont-ecom' ) );
		}
		$args = [ 'description' => $term->description ];
		if ( (int) $term->parent ) {
			$parent = (int) apply_filters( 'wpml_object_id', (int) $term->parent, (string) $o['type'], false, $lang );
			if ( $parent ) {
				$args['parent'] = $parent;
			}
		}
		// A slug is NOT copied: two terms of one taxonomy cannot share one, and
		// WordPress makes a good one from the name it is given.
		$made = wp_insert_term( (string) $term->name, (string) $o['type'], $args );
		if ( is_wp_error( $made ) ) {
			throw new RuntimeException( $made->get_error_message() );
		}
		$new_id = (int) ( $made['term_id'] ?? 0 );
		if ( ! $new_id ) {
			throw new RuntimeException( __( 'The term could not be created.', 'dazont-ecom' ) );
		}
		$src_lang = self::obj_language( $o );
		$trid     = apply_filters(
			'wpml_element_trid',
			null,
			DZE_Wpml::term_element_id( (int) $o['id'], (string) $o['type'] ),
			DZE_Wpml::element_name( 'term', (string) $o['type'] )
		);
		do_action( 'wpml_set_element_language_details', [
			'element_id'           => DZE_Wpml::term_element_id( $new_id, (string) $o['type'] ),
			'element_type'         => DZE_Wpml::element_name( 'term', (string) $o['type'] ),
			'trid'                 => $trid,
			'language_code'        => $lang,
			'source_language_code' => $src_lang,
		] );
		return $new_id;
	}

	/** The SEO plugin's meta keys, detected by the Content module's helper. */
	private static function seo_keys(): array {
		if ( class_exists( 'DZE_Content' ) && is_callable( [ 'DZE_Content', 'seo_keys' ] ) ) {
			return (array) DZE_Content::seo_keys();
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return [ 'title' => '_yoast_wpseo_title', 'desc' => '_yoast_wpseo_metadesc' ];
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return [ 'title' => 'rank_math_title', 'desc' => 'rank_math_description' ];
		}
		return [ 'title' => '_dze_seo_title', 'desc' => '_dze_seo_desc' ];
	}

	private static function meta_key_for( string $fid ): string {
		$seo = self::seo_keys();
		if ( 'seo_title' === $fid ) {
			return (string) $seo['title'];
		}
		if ( 'seo_desc' === $fid ) {
			return (string) $seo['desc'];
		}
		// A field that names its own key answers with it. The SEO pair is the
		// exception because its key depends on which SEO plugin is installed.
		$f = self::fields( 'post' )[ $fid ] ?? [];
		return ( 'meta' === ( $f['type'] ?? '' ) ) ? (string) ( $f['key'] ?? '' ) : '';
	}

	// =========================================================================
	// Settings screen (a tab of the shared Settings page, never its own menu)
	// =========================================================================

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$s      = self::get_settings();
		$active = array_keys( self::active_fields() );
		$wpml   = class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active();
		?>
		<div class="dze-admin">
		<?php if ( ! $wpml ) : ?>
			<div class="notice notice-warning inline" style="margin:12px 0;"><p>
				<?php esc_html_e( 'WPML is not running: there is no second language to translate into, and nothing here will do anything.', 'dazont-ecom' ); ?>
			</p></div>
		<?php endif; ?>
		<p class="description" style="max-width:900px;">
			<?php esc_html_e( 'Translates the written content of a product into the other languages of the site, through the Anthropic key on the General tab. Price, stock, attributes and images are left to WooCommerce Multilingual. Open a product and use "Translate" in the Dazont Ecom box.', 'dazont-ecom' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_translate_options' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'What this shop translates', 'dazont-ecom' ); ?></th>
					<td>
						<?php
						// A LIST THE SHOP MAKES, not everything WPML allows.
						// "Je ne vais pas tout traduire... une liste créable
						// manuellement des posts qu'on veut traduire." Six
						// things are in whatever happens — pages, articles,
						// products, categories, tags and every product
						// attribute — and the rest is a tick.
						$dze_all    = self::scope();
						$dze_picked = array_keys( self::picked_scope() );
						?>
						<input type="hidden" name="<?php echo esc_attr( self::OPT ); ?>[scope_sent]" value="1" />
						<?php if ( ! $dze_all ) : ?>
							<p class="description"><?php esc_html_e( 'WPML is not set to translate any post type or taxonomy on this site. Open WPML → Settings and say what should be translated; this list follows that answer and never overrides it.', 'dazont-ecom' ); ?></p>
						<?php endif; ?>
						<?php foreach ( $dze_all as $dze_key => $dze_one ) : ?>
							<?php $dze_fixed = self::is_always( $dze_key ); ?>
							<label style="display:block;margin-bottom:3px;">
								<input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[scope][]" value="<?php echo esc_attr( $dze_key ); ?>"
									<?php checked( $dze_fixed || in_array( $dze_key, $dze_picked, true ) ); ?>
									<?php disabled( $dze_fixed ); ?> />
								<?php echo esc_html( $dze_one['label'] ); ?>
								<?php if ( ! empty( $dze_one['attr'] ) ) : ?>
									<span class="description"><?php esc_html_e( '· product attribute', 'dazont-ecom' ); ?></span>
								<?php endif; ?>
								<?php if ( $dze_fixed ) : ?>
									<span class="description"><?php esc_html_e( '· always translated', 'dazont-ecom' ); ?></span>
									<input type="hidden" name="<?php echo esc_attr( self::OPT ); ?>[scope][]" value="<?php echo esc_attr( $dze_key ); ?>" />
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Only what WPML is set to translate can appear here — this list follows WPML and never overrides it.', 'dazont-ecom' ); ?></p>
						<?php
						// WHERE THE BRIDGE ENDS, said once, with WPML's own
						// answer rather than ours. Media is not a document with
						// words in it: translating one here would make a
						// library entry with no file behind it, one per
						// language. WPML Media Translation does it properly and
						// is the only thing that should.
						$dze_media = class_exists( 'DZE_Wpml' ) ? DZE_Wpml::media_translation() : [ 'known' => false ];
						?>
						<p class="description">
							<strong><?php esc_html_e( 'Media is WPML\'s own.', 'dazont-ecom' ); ?></strong>
							<?php esc_html_e( 'An image has no text for a translator to work on beyond its title and its alt text, and those belong to WPML Media Translation, which keeps one file and translates around it. This module would make a library entry with no file behind it, so it never offers media.', 'dazont-ecom' ); ?>
							<?php if ( ! $dze_media['known'] ) : ?>
								<?php esc_html_e( 'WPML Media Translation is not installed on this site, so nothing duplicates your images.', 'dazont-ecom' ); ?>
							<?php elseif ( ! empty( $dze_media['duplicate'] ) ) : ?>
								<span style="color:#8a6d00;"><?php esc_html_e( 'On this site WPML IS set to duplicate media for translated content — that is WPML\'s setting, under WPML → Settings → Media Translation.', 'dazont-ecom' ); ?></span>
							<?php else : ?>
								<?php esc_html_e( 'On this site WPML is not set to duplicate media, so your images stay single and shared between languages.', 'dazont-ecom' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Which fields', 'dazont-ecom' ); ?></th>
					<td>
						<?php foreach ( self::fields() as $fid => $f ) : ?>
							<?php
							// A FIELD WPML COPIES CANNOT BE TRANSLATED HERE,
							// and the place to say so is beside the tick: the
							// next custom-field sync puts the original's value
							// straight back over the words, and no screen would
							// ever say why they vanished.
							$dze_key  = 'meta' === ( $f['type'] ?? '' ) ? self::meta_key_for( $fid ) : '';
							$dze_mode = ( '' !== $dze_key && class_exists( 'DZE_Wpml' ) ) ? DZE_Wpml::custom_field_mode( $dze_key ) : -1;
							$dze_copy = in_array( $dze_mode, [ 1, 3 ], true );
							?>
							<label style="display:block;margin-bottom:3px;">
								<input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[fields][]" value="<?php echo esc_attr( $fid ); ?>" <?php checked( in_array( $fid, $active, true ) ); ?> />
								<?php echo esc_html( $f['label'] ); ?>
								<?php if ( $dze_copy ) : ?>
									<span style="color:#b32d2e;"><?php
										printf(
											/* translators: %s: the custom field key */
											esc_html__( '· WPML is set to COPY %s from the original, so a translation written here is overwritten on the next sync. Set it to "Translate" in WPML → Settings → Custom Fields Translation.', 'dazont-ecom' ),
											esc_html( $dze_key )
										);
									?></span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Written content only. Anything not listed here is WooCommerce Multilingual\'s business. A category or a tag has a name and a description, and those are always sent.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-tr-model"><?php esc_html_e( 'Model', 'dazont-ecom' ); ?></label></th>
					<td>
						<select id="dze-tr-model" name="<?php echo esc_attr( self::OPT ); ?>[model]">
							<option value=""><?php esc_html_e( 'The model chosen on the General tab', 'dazont-ecom' ); ?></option>
							<?php foreach ( DZE_Marketing_Ai::MODELS as $mid => $mlabel ) : ?>
								<option value="<?php echo esc_attr( $mid ); ?>" <?php selected( $mid, (string) ( $s['model'] ?? '' ) ); ?>><?php echo esc_html( $mlabel ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Translating is not writing: a mid-range model reads the original and renders it faithfully for a fraction of the price. Try one product with each before running the catalogue.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-tr-glossary"><?php esc_html_e( 'Never translate', 'dazont-ecom' ); ?></label></th>
					<td>
						<textarea id="dze-tr-glossary" name="<?php echo esc_attr( self::OPT ); ?>[glossary]" rows="5" class="large-text code" placeholder="Kula Tactical&#10;Jute Land&#10;MOLLE"><?php echo esc_textarea( (string) ( $s['glossary'] ?? '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One term per line: brand names, product references, technical names that must come out untouched in every language.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-tr-prompt"><?php esc_html_e( 'Translation prompt', 'dazont-ecom' ); ?></label></th>
					<td>
						<textarea id="dze-tr-prompt" name="<?php echo esc_attr( self::OPT ); ?>[prompt]" rows="10" class="large-text code"><?php echo esc_textarea( self::prompt() ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Empty = shipped default (shown greyed). The target language, the glossary and the answer format are added automatically.', 'dazont-ecom' ); ?>
							<button type="button" class="button-link" id="dze-tr-prompt-restore">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
							<?php if ( class_exists( 'DZE_Prompt_Defaults' ) ) { DZE_Prompt_Defaults::control( 'translate', '#dze-tr-prompt' ); } ?>
						</p>
						<?php if ( class_exists( 'DZE_Prompts' ) ) { DZE_Prompts::the_data( 'translate' ); } ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Missing translations', 'dazont-ecom' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[create]" value="1" <?php checked( ! isset( $s['create'] ) || ! empty( $s['create'] ) ); ?> />
							<?php esc_html_e( 'Create the translation when the language has none yet', 'dazont-ecom' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'The new product is linked to the original and WPML is asked to copy the fields it owns — price, stock, dimensions — from it. Switch this off to work only on translations WPML has already created.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save translation settings', 'dazont-ecom' ) ); ?>
		</form>
		</div>
		<script>
		jQuery( function ( $ ) {
			// The default of a prompt is the shop's own when it set one, and it
			// can be set from this very page without reloading it.
			function dzeDef( id, shipped ) {
				return window.dzeDefaultFor ? window.dzeDefaultFor( id, shipped ) : shipped;
			}
			$( '#dze-tr-prompt-restore' ).on( 'click', function () { $( '#dze-tr-prompt' ).val( dzeDef( 'translate', <?php echo wp_json_encode( self::default_prompt() ); ?> ) ); } );
		} );
		</script>
		<?php
	}

	// =========================================================================
	// Languages
	// =========================================================================

	/**
	 * The languages a product can be translated INTO, source language aside.
	 *
	 * The product screen's own way in. It is the general answer with a product
	 * in it — never a second reading beside `obj_targets()`, or the popup and
	 * the batch screen start disagreeing about which languages exist.
	 */
	public static function targets( int $pid ): array {
		return self::obj_targets( [ 'kind' => 'post', 'id' => $pid, 'type' => 'product' ] );
	}

	/** The full language name to ask the model for, not a two-letter code. */
	private static function language_name( string $code ): string {
		foreach ( DZE_Wpml::get_active_languages() as $l ) {
			if ( ( $l['code'] ?? '' ) === $code ) {
				// English name first — a model reads "French" more reliably than
				// "fr" — with the native name behind it to lift any doubt.
				$en     = trim( (string) ( $l['english_name'] ?? '' ) );
				$native = trim( (string) ( $l['native_name'] ?? '' ) );
				if ( '' !== $en && '' !== $native && $en !== $native ) {
					return $en . ' (' . $native . ')';
				}
				if ( '' !== $en || '' !== $native ) {
					return '' !== $en ? $en : $native;
				}
			}
		}
		return $code;
	}

	// =========================================================================
	// Reading a product
	// =========================================================================

	/** @return array<string,string> field id => text, empty fields dropped. */
	public static function read( int $pid ): array {
		return self::obj_read( [ 'kind' => 'post', 'id' => $pid, 'type' => (string) ( get_post_type( $pid ) ?: 'product' ) ] );
	}

	/**
	 * The fingerprint of what was translated.
	 *
	 * Stored on the translation: when the original changes, the difference is
	 * visible without keeping a copy of the text anywhere.
	 */
	public static function hash( array $texts ): string {
		ksort( $texts );
		return md5( (string) wp_json_encode( $texts ) );
	}

	/**
	 * The register as this translation holds it: field id => md5 of the source.
	 *
	 * @return array<string,string>
	 */
	public static function src_map( int $target_id, array $o = [] ): array {
		$raw = $target_id ? self::meta_read( $o ?: [ 'kind' => 'post' ], $target_id, self::META_SRC ) : '';
		$map = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $map ) ) {
			return [];
		}
		$out = [];
		foreach ( $map as $fid => $sum ) {
			$out[ (string) $fid ] = (string) $sum;
		}
		return $out;
	}

	/**
	 * Writes the register for the fields that were just translated.
	 *
	 * Only the fields it was handed: a run that sent the title alone must not
	 * claim the description was checked too.
	 *
	 * @param array<string,string> $source The SOURCE texts those fields came from.
	 */
	public static function remember( int $target_id, array $source, array $o = [] ): void {
		if ( ! $target_id ) {
			return;
		}
		$o   = $o ?: [ 'kind' => 'post' ];
		$map = self::src_map( $target_id, $o );
		foreach ( $source as $fid => $text ) {
			$map[ (string) $fid ] = md5( (string) $text );
		}
		self::meta_write( $o, $target_id, self::META_SRC, (string) wp_json_encode( $map ) );
	}

	/**
	 * WHICH FIELDS ACTUALLY CHANGED since this translation was made.
	 *
	 * The whole point of the module: WPML re-marks a translation when a
	 * category is renamed, when a variation is added, when a shipping rule
	 * moves. None of that is text. This answers the only question worth paying
	 * for — which words are new — and an empty answer means the mark was noise
	 * and the translation can simply be closed again.
	 *
	 * A field with no entry in the register is stale: either it was never
	 * translated, or it was translated by something that is not this module.
	 *
	 * @return array<string,string> field id => the SOURCE text to send.
	 */
	public static function obj_stale( array $o, string $lang ): array {
		$texts  = self::obj_read( $o );
		$target = self::obj_translation( $o, $lang );
		if ( ! $target || $target === (int) ( $o['id'] ?? 0 ) ) {
			return $texts; // nothing translated yet: all of it is new.
		}
		$map = self::src_map( $target, $o );
		$out = [];
		foreach ( $texts as $fid => $text ) {
			if ( ( $map[ $fid ] ?? '' ) !== md5( (string) $text ) ) {
				$out[ $fid ] = $text;
			}
		}
		return $out;
	}

	/** The same question about a product, which is one kind of object. */
	public static function stale( int $pid, string $lang ): array {
		return self::obj_stale( [ 'kind' => 'post', 'id' => $pid, 'type' => (string) ( get_post_type( $pid ) ?: 'product' ) ], $lang );
	}

	/**
	 * Says "this one is dealt with" to WPML, for one language.
	 *
	 * Called after a translation is written AND after finding that nothing had
	 * changed — those are the same answer as far as the shop is concerned, and
	 * without this second case the mark comes back for ever and the promise of
	 * forgetting translations exist is not kept.
	 */
	public static function obj_settle( array $o, string $lang ): bool {
		if ( ! $o || ! class_exists( 'DZE_Wpml' ) ) {
			return false;
		}
		$target = self::obj_translation( $o, $lang );
		if ( ! $target || $target === (int) $o['id'] ) {
			return false;
		}
		if ( 'term' === $o['kind'] ) {
			$row = DZE_Wpml::translation_row(
				DZE_Wpml::term_element_id( $target, (string) $o['type'] ),
				DZE_Wpml::element_name( 'term', (string) $o['type'] )
			);
			// A TERM IS NOT SIGNED HERE. WPML computes a term's signature its
			// own way and a made-up one is a translation nobody is ever told
			// about again — the mark is cleared and the md5 left as WPML wrote
			// it. Raised again, the register answers "nothing moved" and
			// closes it for nothing, which is what the register is for.
			return $row ? DZE_Wpml::mark_term_done( $row ) : false;
		}
		$row = DZE_Wpml::translation_row( $target, DZE_Wpml::element_name( 'post', (string) get_post_type( $target ) ) );
		return $row ? DZE_Wpml::mark_done( (int) $o['id'], $row ) : false;
	}

	/** The same, for a product. */
	public static function settle( int $pid, string $lang ): bool {
		return self::obj_settle( [ 'kind' => 'post', 'id' => $pid, 'type' => (string) ( get_post_type( $pid ) ?: 'product' ) ], $lang );
	}

	/**
	 * Takes over a translation this module did not write, without paying.
	 *
	 * Three catalogues carry ten thousand marked translations, all of them made
	 * by hand through a spreadsheet in 2025. There is no way to know from here
	 * whether the words in them are current — the register that would say so is
	 * exactly what did not exist. So the shop is offered the only two honest
	 * answers, and this is the cheap one: record the source AS IT STANDS as
	 * what that translation was made from, and close the mark.
	 *
	 * The bet it makes is stated where it is offered: if the source really did
	 * move since, that field stays stale until somebody edits the source again
	 * — and THAT time the register sees it and it is retranslated. The other
	 * answer is to translate it, which costs money and is always available.
	 *
	 * @return bool Whether anything was adopted.
	 */
	public static function obj_adopt( array $o, string $lang ): bool {
		$target = self::obj_translation( $o, $lang );
		if ( ! $target || $target === (int) ( $o['id'] ?? 0 ) ) {
			return false;
		}
		self::remember( $target, self::obj_read( $o ), $o );
		self::obj_settle( $o, $lang );
		return true;
	}

	/** The same, for a product. */
	public static function adopt( int $pid, string $lang ): bool {
		return self::obj_adopt( [ 'kind' => 'post', 'id' => $pid, 'type' => (string) ( get_post_type( $pid ) ?: 'product' ) ], $lang );
	}

	// =========================================================================
	// THE WAITING LIST — a batch is translated, and READ before it lands
	//
	// "Le but est d'avoir la possibilité d'envoyer un petit lot produit en
	// traduction et de voir le résultat avant acceptation des traductions."
	//
	// What waits lives ON THE SOURCE OBJECT, in one meta key, exactly as the
	// product bulk screen keeps what it generated on the product: there is no
	// table to create or migrate, WordPress throws it away with the object, and
	// the same key answers for a product, an article, a page and a term.
	//
	// It is NOT put in DZE_Queue. That store is one document per row with one
	// accept; a translation is an object times N languages times M fields, each
	// field its own yes or no, and bending a table that works to carry a
	// different question is how two screens start disagreeing.
	// =========================================================================

	/** What a batch produced and nobody has decided on yet. */
	public const META_WAIT = '_dze_tr_wait';

	/**
	 * The waiting translation of one object, as it was stored.
	 *
	 * @return array{at:int,langs:array<string,array<string,string>>,src:array<string,string>}|array{}
	 */
	public static function waiting( array $o ): array {
		if ( ! $o ) {
			return [];
		}
		$raw = self::meta_read( $o, (int) $o['id'], self::META_WAIT );
		$row = '' !== $raw ? json_decode( $raw, true ) : [];
		return is_array( $row ) && ! empty( $row['langs'] ) ? $row : [];
	}

	/** Stores what came back, against the source it came from. */
	public static function hold( array $o, array $langs, array $source ): void {
		if ( ! $o || ! $langs ) {
			return;
		}
		self::meta_write( $o, (int) $o['id'], self::META_WAIT, (string) wp_json_encode( [
			'at'    => time(),
			'langs' => $langs,
			// WHAT IT WAS TRANSLATED FROM. Accepting a week later must write
			// the register against the words that were actually sent, not
			// against a source somebody has edited since — otherwise the
			// register claims a field is current when it is not.
			'src'   => $source,
		] ) );
	}

	/** Throws the waiting translation away. Refusing, and accepting, both end here. */
	public static function drop_wait( array $o ): void {
		if ( ! $o ) {
			return;
		}
		if ( 'term' === $o['kind'] ) {
			delete_term_meta( (int) $o['id'], self::META_WAIT );
			return;
		}
		delete_post_meta( (int) $o['id'], self::META_WAIT );
	}

	/**
	 * TRANSLATE ONE OBJECT INTO THE LANGUAGES ASKED FOR, and hold the answer.
	 *
	 * Split from every request that calls it — no nonce, no JSON exit — so the
	 * screen, a batch and an automatic pass all run the SAME function. A
	 * handler that ends the request cannot be tested, which is the rule
	 * `shoot()` is held to.
	 *
	 * @return array{langs:array<string,array<string,string>>,skipped:string[],errors:array<string,string>,cost:bool}
	 */
	public static function produce( array $o, array $langs ): array {
		$out     = [ 'langs' => [], 'skipped' => [], 'errors' => [], 'cost' => false ];
		if ( ! $o ) {
			return $out;
		}
		$targets = self::obj_targets( $o );
		$source  = [];
		foreach ( $langs as $lang ) {
			$lang = sanitize_key( (string) $lang );
			if ( '' === $lang || ! isset( $targets[ $lang ] ) ) {
				continue;
			}
			// ONLY WHAT ACTUALLY MOVED IS PAID FOR — the whole reason this
			// module exists beside WPML's own automatic translation. A mark
			// raised because a category was renamed sends nothing at all, and
			// is closed on the spot.
			$texts = self::obj_stale( $o, $lang );
			if ( ! $texts ) {
				self::obj_settle( $o, $lang );
				$out['skipped'][] = $lang;
				continue;
			}
			try {
				$new = self::translate( $texts, $lang, (string) $o['kind'] );
			} catch ( \Throwable $e ) {
				$out['errors'][ $lang ] = $e->getMessage();
				continue;
			}
			if ( ! $new ) {
				$out['errors'][ $lang ] = __( 'Nothing came back.', 'dazont-ecom' );
				continue;
			}
			$out['cost']          = true;
			$out['langs'][ $lang ] = $new;
			$source               += $texts;
		}
		if ( $out['langs'] ) {
			self::hold( $o, $out['langs'], $source );
		}
		return $out;
	}

	/**
	 * WRITES WHAT WAS KEPT onto the translations, language by language.
	 *
	 * Field by field: a block the reader unticked is left out, and the register
	 * then claims only the fields that were really written.
	 *
	 * @param array<string,array<string,string>> $keep language => field => text
	 * @return array{written:array<string,int>,errors:array<string,string>}
	 */
	public static function accept( array $o, array $keep ): array {
		$out = [ 'written' => [], 'errors' => [] ];
		if ( ! $o || ! $keep ) {
			return $out;
		}
		$held    = self::waiting( $o );
		$source  = (array) ( $held['src'] ?? [] );
		$targets = self::obj_targets( $o );
		$allowed = self::fields( (string) $o['kind'] );
		foreach ( $keep as $lang => $texts ) {
			$lang  = sanitize_key( (string) $lang );
			$texts = array_intersect_key( (array) $texts, $allowed );
			if ( '' === $lang || ! isset( $targets[ $lang ] ) || ! $texts ) {
				continue;
			}
			$target = self::obj_translation( $o, $lang );
			if ( ! $target ) {
				// Absent setting means "yes": a first install translates
				// without having to find a checkbox first.
				$set = self::get_settings();
				if ( isset( $set['create'] ) && empty( $set['create'] ) ) {
					$out['errors'][ $lang ] = __( 'There is no translation to write into, and creating one is switched off in the settings.', 'dazont-ecom' );
					continue;
				}
				try {
					$target = self::obj_create( $o, $lang );
				} catch ( \Throwable $e ) {
					$out['errors'][ $lang ] = $e->getMessage();
					continue;
				}
			}
			self::obj_write( $o, $target, array_map( 'strval', $texts ) );
			self::meta_write( $o, $target, self::META_MINE, '1' );
			// THE REGISTER IS WRITTEN AGAINST WHAT WAS SENT, never against the
			// source as it stands now: accepting a batch a week later must not
			// claim a field is current when somebody has edited it since.
			self::remember( $target, array_intersect_key( $source, $texts ), $o );
			self::obj_settle( $o, $lang );
			$out['written'][ $lang ] = $target;
		}
		// WRITTEN IS RECORDED. Without this the shop could see a translation
		// on a page and have no way of knowing it came from here, when, or
		// who said yes to it.
		if ( $out['written'] ) {
			self::log_add( $o, array_keys( $out['written'] ) );
		}
		// A language left out of the decision is still waiting; only a clean
		// sweep clears the object off the list.
		$left = array_diff_key( (array) ( $held['langs'] ?? [] ), $out['written'] );
		if ( $left ) {
			self::hold( $o, $left, $source );
		} else {
			self::drop_wait( $o );
		}
		return $out;
	}

	/**
	 * WHAT WAS TRANSLATED, AND WHEN — the module's own line in the register.
	 *
	 * A capped option, never autoloaded, read by one screen. It records the
	 * DECISION: this object, into these languages, on this day, by this person.
	 * The translation itself is on the object; this is the only thing that
	 * still knows a month later that it was done here rather than by hand.
	 */
	public const OPT_LOG = 'dze_translate_log';
	private const LOG_MAX = 120;

	public static function log_add( array $o, array $langs ): void {
		if ( ! $o || ! $langs ) {
			return;
		}
		$log = get_option( self::OPT_LOG, [] );
		$log = is_array( $log ) ? $log : [];
		$ref = self::ref( $o );
		$was = [];
		foreach ( $log as $i => $row ) {
			if ( (string) ( $row['ref'] ?? '' ) === $ref ) {
				// One object appears once, and the languages ACCUMULATE: a
				// category translated into French in March and German in June
				// has been translated into both, and a row that forgot the
				// first is a register that shrinks as it is used.
				$was = (array) ( $row['langs'] ?? [] );
				unset( $log[ $i ] );
			}
		}
		$log = array_values( $log );
		array_unshift( $log, [
			'ref'   => $ref,
			'kind'  => (string) $o['kind'],
			'type'  => (string) $o['type'],
			'id'    => (int) $o['id'],
			'langs' => array_values( array_unique( array_merge( $was, array_map( 'strval', $langs ) ) ) ),
			'time'  => time(),
			// WHO SAID YES. 0 is an automatic pass, which has nobody to name.
			'by'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'title' => self::obj_label( $o ),
		] );
		update_option( self::OPT_LOG, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	/** @return array<int,array<string,mixed>> newest first. */
	public static function log_entries(): array {
		$log = get_option( self::OPT_LOG, [] );
		return is_array( $log ) ? $log : [];
	}

	/**
	 * EVERY OBJECT HOLDING A TRANSLATION NOBODY HAS DECIDED ON.
	 *
	 * Two queries for the whole screen — one for posts, one for terms — never
	 * one per row: a list-table column that asks a question per line is how a
	 * screen with two thousand objects stops loading.
	 *
	 * @return array<int,array{kind:string,id:int,type:string,langs:string[],at:int}>
	 */
	public static function review_list( int $limit = 200 ): array {
		global $wpdb;
		$out = [];
		if ( ! $wpdb ) {
			return $out;
		}
		$limit = max( 1, min( 500, $limit ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own meta key, one query for the page.
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id AS oid, meta_value AS v FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT %d",
			self::META_WAIT,
			$limit
		), ARRAY_A );
		foreach ( $rows as $r ) {
			$one = self::row_from( 'post', (int) $r['oid'], (string) $r['v'] );
			if ( $one ) {
				$out[] = $one;
			}
		}
		$terms = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT term_id AS oid, meta_value AS v FROM {$wpdb->termmeta} WHERE meta_key = %s LIMIT %d",
			self::META_WAIT,
			$limit
		), ARRAY_A );
		// phpcs:enable
		foreach ( $terms as $r ) {
			$one = self::row_from( 'term', (int) $r['oid'], (string) $r['v'] );
			if ( $one ) {
				$out[] = $one;
			}
		}
		usort( $out, static fn( $a, $b ) => $b['at'] <=> $a['at'] );
		return $out;
	}

	/** One row of that list, or nothing when the object has gone since. */
	private static function row_from( string $kind, int $id, string $raw ): array {
		$held = json_decode( $raw, true );
		if ( ! is_array( $held ) || empty( $held['langs'] ) ) {
			return [];
		}
		$o = self::obj( $kind, $id );
		if ( ! $o ) {
			return [];
		}
		return [
			'kind'  => $o['kind'],
			'id'    => $o['id'],
			'type'  => $o['type'],
			'label' => self::obj_label( $o ),
			'langs' => array_keys( (array) $held['langs'] ),
			'at'    => (int) ( $held['at'] ?? 0 ),
		];
	}

	/** How many objects are waiting for a person. Cheap: it is the badge. */
	public static function review_count(): int {
		global $wpdb;
		if ( ! $wpdb ) {
			return 0;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own meta key.
		$n  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_WAIT ) );
		$n += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s", self::META_WAIT ) );
		// phpcs:enable
		return $n;
	}

	/** What to call an object on screen. */
	public static function obj_label( array $o ): string {
		if ( ! $o ) {
			return '';
		}
		if ( 'term' === $o['kind'] ) {
			$term = get_term( (int) $o['id'], (string) $o['type'] );
			return ( $term && ! is_wp_error( $term ) ) ? (string) $term->name : '#' . (int) $o['id'];
		}
		return (string) ( get_the_title( (int) $o['id'] ) ?: '#' . (int) $o['id'] );
	}

	/** Where to go to see the object itself — a new tab, never "#". */
	public static function obj_edit_url( array $o ): string {
		if ( ! $o ) {
			return '';
		}
		return 'term' === $o['kind']
			? (string) get_edit_term_link( (int) $o['id'], (string) $o['type'] )
			: (string) get_edit_post_link( (int) $o['id'], '' );
	}

	// =========================================================================
	// The call
	// =========================================================================

	/**
	 * One call per language, every field in the same request.
	 *
	 * Field by field would multiply the calls and lose the consistency between
	 * a title and the description under it — the same words have to be chosen
	 * in both.
	 *
	 * @param array<string,string> $texts
	 * @return array<string,string>
	 */
	public static function translate( array $texts, string $lang_code, string $kind = 'post' ): array {
		if ( ! $texts ) {
			return [];
		}
		if ( ! class_exists( 'DZE_Marketing_Ai' ) ) {
			throw new RuntimeException( __( 'The Marketing Assistant module holds the Anthropic key — switch it back on.', 'dazont-ecom' ) );
		}
		$labels = self::fields( $kind );
		$lines  = [];
		foreach ( $texts as $fid => $v ) {
			$lines[] = '### ' . $fid . ' (' . ( $labels[ $fid ]['label'] ?? $fid ) . ")\n" . $v;
		}
		$glossary = self::glossary();
		$system   = self::prompt()
			. "\n\nTarget language: " . self::language_name( $lang_code ) . '.'
			. ( $glossary ? "\n\nNever translate these terms, reproduce them exactly:\n- " . implode( "\n- ", $glossary ) : '' )
			. "\n\nAnswer with STRICT JSON only: an object whose keys are the field ids given to you"
			. ' and whose values are the translated texts. No commentary, no code fence.';

		$user = "Translate every field below.\n\n" . implode( "\n\n", $lines );
		// Room for the answer: the translated text is about the size of the
		// source, plus the JSON around it.
		$max = (int) min( 8000, max( 1000, ( mb_strlen( implode( '', $texts ) ) / 2 ) + 800 ) );

		DZE_Ai_Usage::unit( 'translate' );
		try {
			$raw = DZE_Marketing_Ai::complete( $system, $user, self::model(), $max, 180 );
		} finally {
			DZE_Ai_Usage::unit();
		}
		DZE_Ai_Usage::finished( 'translate' );

		$json = trim( (string) preg_replace( '/^```(?:json)?|```$/m', '', $raw ) );
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( __( 'The model did not answer with the expected format.', 'dazont-ecom' ) );
		}
		$out = [];
		foreach ( $texts as $fid => $_ ) {
			$v = isset( $rows[ $fid ] ) ? (string) $rows[ $fid ] : '';
			if ( '' !== trim( $v ) ) {
				$out[ $fid ] = $v;
			}
		}
		return $out;
	}

	private static function model(): string {
		return (string) ( self::get_settings()['model'] ?? '' );
	}

	// =========================================================================
	// Writing the translation, WPML's way
	// =========================================================================

	/**
	 * The existing translation of a product in one language, or 0.
	 *
	 * `wpml_object_id` with $return_original = false answers 0 rather than the
	 * original when the translation does not exist — which is the question.
	 */
	public static function translation_of( int $pid, string $lang ): int {
		return self::obj_translation( [ 'kind' => 'post', 'id' => $pid, 'type' => (string) ( get_post_type( $pid ) ?: 'product' ) ], $lang );
	}

	/**
	 * Creates the translated product and hands it to WPML.
	 *
	 * The order matters: the post is created, linked to the original's
	 * translation group, given its terms, and only THEN does WPML copy the
	 * custom fields it is configured to copy — prices, stock, everything
	 * WooCommerce Multilingual owns — from the original. Our translated text is
	 * written after that, so a copied field cannot land on top of it.
	 */
	public static function obj_create( array $o, string $lang ): int {
		if ( ! $o ) {
			throw new RuntimeException( __( 'Unknown object.', 'dazont-ecom' ) );
		}
		if ( 'term' === $o['kind'] ) {
			return self::create_term( $o, $lang );
		}
		return self::instance()->create_translation( (int) $o['id'], $lang, (string) $o['type'] );
	}

	private function create_translation( int $pid, string $lang, string $type = 'product' ): int {
		$src = get_post( $pid );
		if ( ! $src ) {
			throw new RuntimeException( __( 'The original could not be read.', 'dazont-ecom' ) );
		}
		$type = '' !== $type ? $type : (string) $src->post_type;
		$new_id = wp_insert_post( [
			'post_type'      => $type,
			'post_status'    => $src->post_status,
			'post_title'     => $src->post_title,
			'post_content'   => $src->post_content,
			'post_excerpt'   => $src->post_excerpt,
			'post_author'    => $src->post_author,
			'comment_status' => $src->comment_status,
			'menu_order'     => $src->menu_order,
		], true );
		if ( is_wp_error( $new_id ) ) {
			throw new RuntimeException( $new_id->get_error_message() );
		}
		$new_id = (int) $new_id;

		$src_lang = DZE_Wpml::post_language( $pid, $type ) ?: DZE_Wpml::default_language();
		$trid     = apply_filters( 'wpml_element_trid', null, $pid, DZE_Wpml::element_name( 'post', $type ) );
		do_action( 'wpml_set_element_language_details', [
			'element_id'           => $new_id,
			'element_type'         => DZE_Wpml::element_name( 'post', $type ),
			'trid'                 => $trid,
			'language_code'        => $lang,
			'source_language_code' => $src_lang,
		] );

		// EVERY TAXONOMY THIS TYPE HAS, and each one placed as WPML says it
		// should be: a translated taxonomy gets the term's translation, an
		// untranslated one gets the very same term. Hard-coding a product's
		// three was right for products and silently gave an article no
		// categories at all — and `product_type` is only one example of a
		// taxonomy that must be carried across untouched, since a product
		// without it is not a product WooCommerce can render.
		foreach ( (array) get_object_taxonomies( $type ) as $tax ) {
			$terms = wp_get_object_terms( $pid, $tax, [ 'fields' => 'ids' ] );
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}
			$translated = DZE_Wpml::is_translated_taxonomy( $tax );
			$mapped     = [];
			foreach ( $terms as $tid ) {
				$m = $translated
					? (int) apply_filters( 'wpml_object_id', (int) $tid, $tax, true, $lang )
					: (int) $tid;
				if ( $m ) {
					$mapped[] = $m;
				}
			}
			if ( $mapped ) {
				wp_set_object_terms( $new_id, $mapped, $tax );
			}
		}
		// The photographs stay the originals' until WooCommerce Multilingual is
		// told otherwise: one image library, one set of files.
		foreach ( [ '_thumbnail_id', '_product_image_gallery' ] as $mk ) {
			$v = get_post_meta( $pid, $mk, true );
			if ( '' !== $v && [] !== $v ) {
				update_post_meta( $new_id, $mk, $v );
			}
		}
		// Prices, stock, dimensions, attributes: WPML copies what it is
		// configured to copy, from the original. We never compute them.
		do_action( 'wpml_sync_all_custom_fields', $pid );

		return $new_id;
	}

	/**
	 * Writes translated text onto a translation.
	 *
	 * @param array<string,string> $texts
	 */
	private function write( int $target_id, array $texts ): void {
		self::obj_write( [ 'kind' => 'post', 'type' => (string) ( get_post_type( $target_id ) ?: 'product' ) ], $target_id, $texts );
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	private function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
	}

	/** Translate and show, next to what the translation holds today. */
	public function ajax_preview(): void {
		$this->guard();
		$pid  = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		if ( ! $pid || '' === $lang || ! isset( self::targets( $pid )[ $lang ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown product or language.', 'dazont-ecom' ) ] );
		}
		$all = self::read( $pid );
		if ( ! $all ) {
			wp_send_json_error( [ 'message' => __( 'This product has no text to translate.', 'dazont-ecom' ) ] );
		}
		// ONLY WHAT ACTUALLY CHANGED IS PAID FOR. A product WPML re-marked
		// because its category was renamed sends nothing at all — and the mark
		// is closed on the spot, which is the whole promise of the module.
		$texts = self::stale( $pid, $lang );
		if ( ! $texts ) {
			self::settle( $pid, $lang );
			wp_send_json_success( [
				'lang'     => $lang,
				'unchanged'=> true,
				'exists'   => true,
				'labels'   => array_map( static fn( $f ) => $f['label'], self::fields() ),
				'edit'     => (string) get_edit_post_link( self::translation_of( $pid, $lang ), '' ),
			] );
		}
		try {
			$new = self::translate( $texts, $lang );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		if ( ! $new ) {
			wp_send_json_error( [ 'message' => __( 'Nothing came back.', 'dazont-ecom' ) ] );
		}

		$target  = self::translation_of( $pid, $lang );
		$current = $target ? self::read( $target ) : [];
		wp_send_json_success( [
			'lang'    => $lang,
			'exists'  => (bool) $target,
			// A translation somebody else wrote is worked on differently: it is
			// shown, never replaced without being asked twice.
			'mine'    => $target ? ( '1' === (string) get_post_meta( $target, self::META_MINE, true ) ) : true,
			'source'  => $texts,
			'texts'   => $new,
			'current' => $current,
			// WHICH FIELDS THIS RUN IS ABOUT, and how many were left alone.
			// A screen that shows two fields out of five without saying why
			// reads as a run that half worked.
			'only'    => array_keys( $texts ),
			'kept'    => max( 0, count( $all ) - count( $texts ) ),
			'labels'  => array_map( static fn( $f ) => $f['label'], self::fields() ),
			'edit'    => $target ? (string) get_edit_post_link( $target, '' ) : '',
		] );
	}

	/** Apply what was read and kept. */
	public function ajax_apply(): void {
		$this->guard();
		$pid   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$lang  = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$texts = isset( $_POST['texts'] ) ? (array) wp_unslash( $_POST['texts'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized in write() by its own kind.
		if ( ! $pid || '' === $lang || ! $texts || ! isset( self::targets( $pid )[ $lang ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown product or language.', 'dazont-ecom' ) ] );
		}
		$texts = array_intersect_key( $texts, self::fields() );

		$target = self::translation_of( $pid, $lang );
		if ( ! $target ) {
			// Absent setting means "yes": a first install translates without
			// having to find a checkbox first.
			$set = self::get_settings();
			if ( isset( $set['create'] ) && empty( $set['create'] ) ) {
				wp_send_json_error( [ 'message' => __( 'There is no translation to write into, and creating one is switched off in the settings.', 'dazont-ecom' ) ] );
			}
			try {
				$target = $this->create_translation( $pid, $lang );
			} catch ( \Throwable $e ) {
				wp_send_json_error( [ 'message' => $e->getMessage() ] );
			}
		}
		$this->write( $target, array_map( 'strval', $texts ) );
		update_post_meta( $target, self::META_MINE, '1' );
		$source = self::read( $pid );
		update_post_meta( $target, self::META_HASH, self::hash( $source ) );
		// THE REGISTER, for the fields that were actually written — a field
		// left out of this apply is not claimed as translated.
		self::remember( $target, array_intersect_key( $source, $texts ) );
		// And WPML is told the translation is dealt with, so the mark goes.
		$settled = self::settle( $pid, $lang );

		wp_send_json_success( [
			'target'  => $target,
			'settled' => $settled,
			'edit'    => (string) get_edit_post_link( $target, '' ),
		] );
	}

	// =========================================================================
	// The screen's own presses
	// =========================================================================

	/** Every press on this screen is a shop decision, not a product edit. */
	private function screen_guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
	}

	/**
	 * ONE OBJECT OF THE BATCH. The browser walks the list and calls this per
	 * object, so a run of forty says where it is instead of hanging on one
	 * request that either works or times out.
	 */
	public function ajax_batch(): void {
		$this->screen_guard();
		$o = self::from_ref( isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '' );
		if ( ! $o ) {
			wp_send_json_error( [ 'message' => __( 'That is not something WPML translates on this site.', 'dazont-ecom' ) ] );
		}
		$langs = isset( $_POST['langs'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['langs'] ) ) : [];
		if ( ! $langs ) {
			wp_send_json_error( [ 'message' => __( 'No language was chosen.', 'dazont-ecom' ) ] );
		}
		$made = self::produce( $o, $langs );
		wp_send_json_success( [
			'label'   => self::obj_label( $o ),
			'done'    => array_keys( $made['langs'] ),
			// A LANGUAGE THAT COST NOTHING SAYS SO. "Nothing was sent" and
			// "something went wrong" must never wear the same words.
			'skipped' => $made['skipped'],
			'errors'  => $made['errors'],
		] );
	}

	/**
	 * The whole of one waiting object: the original, what came back, and what
	 * the translation holds today — per language, per field.
	 */
	public function ajax_panel(): void {
		$this->screen_guard();
		$o = self::from_ref( isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '' );
		if ( ! $o ) {
			wp_send_json_error( [ 'message' => __( 'Unknown object.', 'dazont-ecom' ) ] );
		}
		$held = self::waiting( $o );
		if ( ! $held ) {
			wp_send_json_error( [ 'message' => __( 'Nothing is waiting on this one any more — it may have been decided in another tab.', 'dazont-ecom' ) ] );
		}
		$langs = [];
		foreach ( (array) $held['langs'] as $code => $texts ) {
			$target = self::obj_translation( $o, (string) $code );
			$langs[ (string) $code ] = [
				'name'    => (string) ( self::obj_targets( $o )[ $code ] ?? strtoupper( (string) $code ) ),
				'texts'   => (array) $texts,
				// WHAT THE TRANSLATION HOLDS TODAY, so a reader can see what
				// is about to be replaced rather than trusting a button.
				'current' => $target ? self::obj_read( array_merge( $o, [ 'id' => $target ] ) ) : [],
				'exists'  => (bool) $target,
				'mine'    => $target ? ( '1' === self::meta_read( $o, $target, self::META_MINE ) ) : true,
				'edit'    => $target ? self::obj_edit_url( array_merge( $o, [ 'id' => $target ] ) ) : '',
			];
		}
		wp_send_json_success( [
			'label'  => self::obj_label( $o ),
			'edit'   => self::obj_edit_url( $o ),
			'source' => (array) ( $held['src'] ?? [] ),
			'labels' => array_map( static fn( $f ) => $f['label'], self::fields( (string) $o['kind'] ) ),
			'langs'  => $langs,
		] );
	}

	/** Accept what was kept, or refuse the lot. Both end the wait. */
	public function ajax_decide(): void {
		$this->screen_guard();
		$o = self::from_ref( isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '' );
		if ( ! $o ) {
			wp_send_json_error( [ 'message' => __( 'Unknown object.', 'dazont-ecom' ) ] );
		}
		$how = isset( $_POST['how'] ) ? sanitize_key( wp_unslash( $_POST['how'] ) ) : '';
		if ( 'refuse' === $how ) {
			self::drop_wait( $o );
			wp_send_json_success( [ 'refused' => true, 'left' => 0 ] );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized in obj_write() by its own kind.
		$keep = isset( $_POST['keep'] ) ? (array) wp_unslash( $_POST['keep'] ) : [];
		if ( ! $keep ) {
			wp_send_json_error( [ 'message' => __( 'Nothing was ticked, so nothing was written.', 'dazont-ecom' ) ] );
		}
		$done = self::accept( $o, $keep );
		if ( ! $done['written'] && $done['errors'] ) {
			wp_send_json_error( [ 'message' => implode( ' ', $done['errors'] ) ] );
		}
		wp_send_json_success( [
			'written' => $done['written'],
			'errors'  => $done['errors'],
			// Whether this object is off the list, or still holds a language
			// nobody decided on. A row that vanished on a half decision would
			// be a list that lies.
			'left'    => count( (array) ( self::waiting( $o )['langs'] ?? [] ) ),
		] );
	}

	/** The screen's own assets, asked for from inside the body that needs them. */
	public function screen_assets( string $hook = '' ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::MENU_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		wp_enqueue_editor();
		wp_enqueue_script( 'dze-translate-screen', DZE_URL . 'admin/js/translate-screen.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-translate-screen', 'dzeTrScreen', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'tickFirst'  => __( 'Tick what you want translated first.', 'dazont-ecom' ),
				'langFirst'  => __( 'Tick at least one language.', 'dazont-ecom' ),
				/* translators: 1: objects done, 2: objects in the batch */
				'stepN'      => __( '%1$s of %2$s', 'dazont-ecom' ),
				'sending'    => __( 'Translating…', 'dazont-ecom' ),
				/* translators: %s: number of objects now waiting to be read */
				'sent'       => __( 'Done — %s waiting to be read. Open the "To review" tab.', 'dazont-ecom' ),
				'nothingNew' => __( 'Nothing had moved on any of them: not one word was sent, nothing was spent, and WPML has been told they are up to date.', 'dazont-ecom' ),
				'error'      => __( 'Something went wrong.', 'dazont-ecom' ),
				'loading'    => __( 'Reading…', 'dazont-ecom' ),
				'source'     => __( 'Original', 'dazont-ecom' ),
				'current'    => __( 'The translation today', 'dazont-ecom' ),
				'empty'      => __( '(empty)', 'dazont-ecom' ),
				'keepHelp'   => __( 'Untick to leave this field out — the rest is still written', 'dazont-ecom' ),
				'willCreate' => __( 'This language has no translation of this one yet: accepting creates it and links it to the original.', 'dazont-ecom' ),
				'notMine'    => __( 'This translation was not written here. Accepting replaces its text — read the "translation today" column first.', 'dazont-ecom' ),
				'accept'     => __( 'Accept what is ticked', 'dazont-ecom' ),
				'refuse'     => __( 'Refuse', 'dazont-ecom' ),
				'open'       => __( 'Open the translation', 'dazont-ecom' ),
				'confirmNo'  => __( 'Throw this translation away? It cannot be recovered; the object stays exactly as it is.', 'dazont-ecom' ),
				'saved'      => __( 'Written ✓', 'dazont-ecom' ),
				'saving'     => __( 'Writing…', 'dazont-ecom' ),
				/* translators: %s: number of languages still waiting */
				'someLeft'   => __( 'Written. %s language(s) on this one are still waiting.', 'dazont-ecom' ),
			],
		] );
	}

	// =========================================================================
	// The popup, printed from the product screen only
	// =========================================================================

	public function popup_hook(): void {
		if ( ! self::on_product_screen() ) {
			return;
		}
		add_action( 'admin_footer', [ $this, 'popup' ] );
	}

	private static function on_product_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'product' === $screen->post_type && 'post' === $screen->base;
	}

	/**
	 * Assets, at the hour WordPress expects them.
	 *
	 * The editor in particular has to be asked for before the footer is being
	 * printed, so this hangs off admin_enqueue_scripts and not off the popup.
	 */
	public function assets( string $hook = '' ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) || ! self::on_product_screen() ) {
			return;
		}
		$pid = (int) get_the_ID();
		if ( ! $pid || ! self::targets( $pid ) ) {
			return; // a single-language site pays nothing for this module.
		}
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		wp_enqueue_editor();
		wp_enqueue_script( 'dze-translate', DZE_URL . 'admin/js/translate.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-translate', 'dzeTranslate', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'postId'  => $pid,
			'i18n'    => [
				'working'   => __( 'Translating…', 'dazont-ecom' ),
				'error'     => __( 'Something went wrong.', 'dazont-ecom' ),
				'applying'  => __( 'Saving…', 'dazont-ecom' ),
				'applied'   => __( 'Saved ✓', 'dazont-ecom' ),
				// WHAT THE REGISTER FOUND, in words. A run that spends nothing
				// has to say so, or it reads as a button that did nothing.
				'unchanged' => __( 'Not one word has changed since this translation was made — nothing was sent, nothing was spent, and WPML has been told it is up to date.', 'dazont-ecom' ),
				/* translators: %s: number of fields left alone */
				'onlyChanged' => __( 'Only what changed is below: %s other fields have not moved since the last translation and were left alone.', 'dazont-ecom' ),
				'source'    => __( 'Original', 'dazont-ecom' ),
				'current'   => __( 'The translation today', 'dazont-ecom' ),
				'empty'     => __( '(empty)', 'dazont-ecom' ),
				'keepHelp'  => __( 'Untick to leave this field out — the rest is still written', 'dazont-ecom' ),
				'willCreate'=> __( 'This language has no translation of this product yet: applying creates it, links it to this one, and lets WooCommerce Multilingual bring the price and the stock over.', 'dazont-ecom' ),
				'notMine'   => __( 'This translation was not written here. Applying replaces its text — check the "translation today" column before you do.', 'dazont-ecom' ),
				'confirm'   => __( 'Write this text on the translation?', 'dazont-ecom' ),
			],
		] );
	}

	public function popup(): void {
		global $post;
		$pid = $post ? (int) $post->ID : 0;
		if ( ! $pid ) {
			return;
		}
		$targets = self::targets( $pid );
		?>
		<div class="dze-cx-modal" id="dze-tr-modal"><div class="dze-cx-dialog" style="width:min(1100px,96vw);">
			<div class="dze-cx-head">
				<h2><?php esc_html_e( 'Translate this product', 'dazont-ecom' ); ?></h2>
				<?php if ( class_exists( 'DZE_Prompts' ) ) { DZE_Prompts::the_button( 'translate' ); } ?>
				<button type="button" class="button dze-hub-close" style="margin-left:auto;"><?php esc_html_e( 'Close', 'dazont-ecom' ); ?></button>
			</div>
			<div class="dze-cx-body">
				<?php if ( ! $targets ) : ?>
					<p><?php esc_html_e( 'No other language is active on this site, or WPML is not running. Nothing to translate into.', 'dazont-ecom' ); ?></p>
				<?php else : ?>
					<p class="description" style="max-width:900px;">
						<?php esc_html_e( 'Written content only: name, description, short description and SEO fields. Price, stock, attributes and images stay with WooCommerce Multilingual, which syncs them from this product. Nothing is saved until you apply.', 'dazont-ecom' ); ?>
					</p>
					<p class="dze-tr-bar">
						<label><span><?php esc_html_e( 'Language', 'dazont-ecom' ); ?></span>
							<select id="dze-tr-lang">
								<?php foreach ( $targets as $code => $name ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name . ' (' . strtoupper( $code ) . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<button type="button" class="button button-primary" id="dze-tr-run"><?php esc_html_e( 'Translate', 'dazont-ecom' ); ?></button>
						<span class="dze-cx-state" id="dze-tr-state"></span>
					</p>
					<div id="dze-tr-result" class="dze-cx-result" style="display:none;">
						<div id="dze-tr-warn"></div>
						<div class="dze-cb-prev" id="dze-tr-drawers"></div>
						<p class="dze-cb-panelbar">
							<button type="button" class="button button-primary" id="dze-tr-apply"><?php esc_html_e( 'Apply to the translation', 'dazont-ecom' ); ?></button>
							<a href="#" class="button" id="dze-tr-open" target="_blank" rel="noopener" style="display:none;"><?php esc_html_e( 'Open the translation', 'dazont-ecom' ); ?></a>
							<span class="dze-cb-panelstate" id="dze-tr-applystate"></span>
						</p>
					</div>
				<?php endif; ?>
			</div>
		</div></div>
		<?php
	}
}
