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
	/**
	 * WHAT THIS MODULE REPLACED, so it can be put back.
	 *
	 * WordPress's own revisions cover almost nothing here: a PRODUCT has no
	 * revision support at all, a custom field written with `update_post_meta()`
	 * leaves no history, and neither does a term written with `wp_update_term()`.
	 * On a shop of 2,000 products that is the whole of the work with no way
	 * back — which is not something to find out after a batch of nine thousand
	 * writes has gone wrong.
	 *
	 * One step back, deliberately: the words a translation held immediately
	 * before this module last wrote to it. Two steps would be a version
	 * history, which is WordPress's job and not this one's.
	 */
	public const META_PREV = '_dze_tr_prev';

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
		add_action( 'admin_enqueue_scripts', [ $this, 'screen_assets' ] );
		// The button inside WPML's own Language box, on every edit screen of a
		// thing WPML will link. It is the ONLY thing this module puts on a post
		// screen: there is one translation screen per object and it lives under
		// Dazont Ecom → WPML Translations, exactly where WPML keeps its own.
		add_action( 'admin_enqueue_scripts', [ $this, 'box_assets' ] );
		// The module's own screen, and the three presses on it.
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'wp_ajax_dze_tr_batch', [ $this, 'ajax_batch' ] );
		add_action( 'wp_ajax_dze_tr_decide', [ $this, 'ajax_decide' ] );
		// WPML'S OWN BUTTONS, doing this module's work. The + and the pencil in
		// the Languages column are where a shop already goes to translate one
		// thing; a second button somewhere else is a second habit to learn.
		add_filter( 'wpml_link_to_translation', [ $this, 'wpml_link' ], 20, 6 );
		add_filter( 'wpml_text_to_translation', [ $this, 'wpml_text' ], 20, 6 );
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
		if ( isset( $in['create'] ) ) {
			$out['create'] = ! empty( $in['create'] ) ? 1 : 0;
		}
		// Sent by the section that owns it, ticked or not, so unticking it can
		// actually be saved — a checkbox posts nothing when it is off.
		if ( ! empty( $in['buttons_sent'] ) ) {
			$out['buttons'] = ! empty( $in['buttons'] ) ? 1 : 0;
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

	/**
	 * TRUE WHEN THIS MODULE ANSWERS WPML'S OWN BUTTONS.
	 *
	 * On by default, and a switch rather than a fact: WPML's editor is paid
	 * for, and taking it over with no way back is not a decision a plugin
	 * makes for a shop. Unticked, the + and the pencil go back to WPML the
	 * moment the page is reloaded.
	 */
	public static function owns_buttons(): bool {
		$s = self::get_settings();
		return ! isset( $s['buttons'] ) || ! empty( $s['buttons'] );
	}

	/** The object behind a row of WPML's Languages column, or [] when we pass. */
	private static function button_obj( int $post_id ): array {
		if ( ! $post_id || ! self::owns_buttons() ) {
			return [];
		}
		$type = (string) get_post_type( $post_id );
		if ( '' === $type ) {
			return [];
		}
		// Only what this shop has ticked. Anything else is WPML's to handle,
		// and sending it to a screen with nothing to show would be worse than
		// leaving the button alone.
		if ( ! isset( self::picked_scope()[ 'post:' . $type ] ) ) {
			return [];
		}
		return [ 'kind' => 'post', 'id' => $post_id, 'type' => $type ];
	}

	/**
	 * Where WPML's + and pencil go. Same icon, same column, same colours —
	 * only the destination changes, so nothing new has to be learnt.
	 *
	 * @param string $link    Where WPML was going to send it.
	 * @param int    $post_id The row.
	 * @param string $lang    The language of the flag pressed.
	 * @return string
	 */
	public function wpml_link( $link, $post_id, $lang = '', $trid = 0, $css_class = '', $status = null ) {
		if ( ! self::ours_to_answer( (int) $post_id, (string) $lang, $status ) ) {
			return $link;
		}
		return self::editor_url( self::button_obj( (int) $post_id ), (string) $lang );
	}

	/**
	 * WHICH OF WPML'S BUTTONS THIS MODULE ANSWERS, and which it keeps its
	 * hands off.
	 *
	 * Only the ones that mean TRANSLATE: the + of a language with nothing in
	 * it, and the arrows of one the original has moved past. The green pencil
	 * of a finished translation means EDIT THIS POST, and taking that over
	 * left the shop with no way at all to open a translated product — which is
	 * exactly what happened the first afternoon this shipped.
	 *
	 * @param int|string|null $status WPML's own status for that language.
	 */
	private static function ours_to_answer( int $post_id, string $lang, $status ): bool {
		if ( '' === $lang ) {
			return false;
		}
		$o = self::button_obj( $post_id );
		if ( ! $o || ! isset( self::obj_targets( $o )[ $lang ] ) ) {
			return false;
		}
		// Unknown status: WPML did not say, so it keeps its own link. Guessing
		// here is how the pencil got taken over in the first place.
		if ( null === $status || '' === $status ) {
			return false;
		}
		$missing = defined( 'ICL_TM_NOT_TRANSLATED' ) ? (int) ICL_TM_NOT_TRANSLATED : 0;
		$behind  = defined( 'ICL_TM_NEEDS_UPDATE' ) ? (int) ICL_TM_NEEDS_UPDATE : 3;
		return in_array( (int) $status, [ $missing, $behind ], true );
	}

	/** The tooltip, saying what will actually happen rather than WPML's words. */
	public function wpml_text( $text, $post_id, $lang = '', $trid = 0, $css_class = '', $status = null ) {
		if ( ! self::ours_to_answer( (int) $post_id, (string) $lang, $status ) ) {
			return $text;
		}
		$behind = defined( 'ICL_TM_NEEDS_UPDATE' ) ? (int) ICL_TM_NEEDS_UPDATE : 3;
		// WPML's own two words, plus whose they are. One label for three
		// different buttons said nothing about which one was being pressed.
		return (int) $status === $behind
			? __( 'Update this translation with Dazont Ecom', 'dazont-ecom' )
			: __( 'Add this translation with Dazont Ecom', 'dazont-ecom' );
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
	 * What the plugin sends WITH this prompt, listed for the card that shows
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
	public static function fields( string $kind = 'post', string $type = '' ): array {
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
			// WooCommerce calls a product's excerpt its short description; on an
			// article or a page WordPress calls it the excerpt. The label follows
			// the kind, or a page appears to have a "short description".
			'excerpt'  => [ 'label' => ( '' === $type || 'product' === $type ) ? __( 'Short description', 'dazont-ecom' ) : __( 'Excerpt', 'dazont-ecom' ), 'type' => 'post', 'key' => 'post_excerpt', 'html' => true ],
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
	 * WHICH FIELDS ARE SENT: all of them, and it is not a setting.
	 *
	 * It was a row of tick boxes on the settings page, and the owner threw it
	 * out in one sentence: "le plugin doit traduire tout ce que wpml exige de
	 * traduire pour avoir une traduction complète du post." A half-translated
	 * page is not a choice anybody makes on purpose, and a box that produces
	 * one is a trap. What a field is FOR is still read — `obj_write()` leaves
	 * alone anything WPML is set to copy — and what that comes to, per kind of
	 * content, is stated on the Translations dashboard (`field_report()`).
	 *
	 * The old `fields` key is no longer read and no longer written. It stays
	 * declared in DZE_Cleanup with the rest of this option so a shop that has
	 * one can wipe it.
	 */
	public static function active_fields( string $kind = 'post' ): array {
		return self::fields( $kind );
	}

	// -------------------------------------------------------------------------
	// WPML'S OWN "TRANSLATE" CUSTOM FIELDS — sent when they hold text
	//
	// "Il est annoncé toute sorte de meta field qui n'ont aucun intérêt à
	// traduire pour certaines pages, voire n'existent même pas." The screen
	// listed every custom field WPML is set to translate, for every kind of
	// content, as a GAP this module did not send — so a page was told about a
	// product's fields, and both were told about keys no object on the site
	// carries. And the rule the owner set is the opposite of a gap: "le plugin
	// doit traduire tout ce que WPML exige de traduire pour avoir une
	// traduction complète du post."
	//
	// So a custom field WPML says "Translate" IS a field of the object — when
	// the object actually holds TEXT in it. WPML's map is global (one list for
	// every post type), so what makes a key a field is the object, never the
	// map alone: a key holding a number, a serialized array, a builder's JSON
	// or a field reference is plumbing WPML happens to have been told to
	// translate, and translating it is how a page breaks.
	// -------------------------------------------------------------------------

	/**
	 * The custom fields WPML is set to TRANSLATE that this module has no fixed
	 * row for. The fixed rows keep their own labels and their own keys.
	 *
	 * @return string[]
	 */
	public static function wpml_text_keys(): array {
		if ( ! class_exists( 'DZE_Wpml' ) ) {
			return [];
		}
		$map  = (array) ( DZE_Wpml::settings()['translation-management']['custom_fields_translation'] ?? [] );
		$mine = [];
		foreach ( self::fields( 'post' ) as $fid => $f ) {
			if ( 'meta' === ( $f['type'] ?? '' ) ) {
				$k = self::meta_key_for( $fid );
				if ( '' !== $k ) {
					$mine[ $k ] = true;
				}
			}
		}
		$out = [];
		foreach ( $map as $key => $mode ) {
			$key = (string) $key;
			if ( '' === $key || 2 !== (int) $mode || isset( $mine[ $key ] ) ) {
				continue;
			}
			// A field of this plugin's own is never customer copy.
			if ( 0 === strpos( $key, '_dze_' ) ) {
				continue;
			}
			$out[] = $key;
		}
		return $out;
	}

	/**
	 * THE SAME QUESTION, ASKED FOR A TERM.
	 *
	 * WPML keeps two lists, not one: `custom_fields_translation` for posts and
	 * `custom_term_fields_translation` for terms. This module read the first
	 * and had no idea the second existed, so a category's SEO title and
	 * description — which WPML is explicitly set to translate on this shop —
	 * were never once offered. "Pourquoi pas de rank math seo title et
	 * description traduis ? Tu as encore oublié plein de champs." Because the
	 * list was half read.
	 *
	 * @return string[] The term meta keys WPML says to translate.
	 */
	public static function wpml_term_text_keys(): array {
		if ( ! class_exists( 'DZE_Wpml' ) ) {
			return [];
		}
		$map = (array) ( DZE_Wpml::settings()['translation-management']['custom_term_fields_translation'] ?? [] );
		$out = [];
		foreach ( $map as $key => $mode ) {
			$key = (string) $key;
			if ( '' === $key || 2 !== (int) $mode ) {
				continue;
			}
			// A field of this plugin's own is never customer copy.
			if ( 0 === strpos( $key, '_dze_' ) ) {
				continue;
			}
			$out[] = $key;
		}
		return $out;
	}

	/**
	 * IS THIS VALUE WORDS? A custom field is not always a line of text: it can
	 * be a number, a date, a URL, a serialized array, a page builder's JSON or
	 * an ACF field reference — none of which has a translation.
	 */
	public static function is_text( $v ): bool {
		if ( ! is_string( $v ) ) {
			return false;
		}
		$v = trim( $v );
		if ( '' === $v ) {
			return false;
		}
		if ( preg_match( '/^[aOsibdN]:\d*[:;{]/', $v ) ) {
			return false; // serialized.
		}
		if ( '{' === $v[0] || '[' === $v[0] ) {
			return false; // JSON — a builder's layout, never prose.
		}
		if ( preg_match( '/^field_[0-9a-f]+$/i', $v ) ) {
			return false; // an ACF reference to the field's own definition.
		}
		if ( preg_match( '#^(https?:)?//\S+$#i', $v ) ) {
			return false; // an address.
		}
		if ( preg_match( '/^[\d\s.,:\/-]+$/', $v ) ) {
			return false; // a figure or a date.
		}
		// Words: three letters in a row somewhere in it.
		return 1 === preg_match( '/\p{L}{3,}/u', $v );
	}

	/** What to call a custom field on screen: "_theme_subtitle" reads "Theme subtitle". */
	public static function key_label( string $key ): string {
		$w = trim( str_replace( [ '_', '-' ], ' ', ltrim( $key, '_' ) ) );
		return '' !== $w ? ucfirst( $w ) : $key;
	}

	/** Does this text carry markup, so it is written as HTML rather than as a line? */
	public static function looks_html( string $v ): bool {
		return false !== strpos( $v, '<' ) || false !== strpos( $v, "\n" );
	}

	/**
	 * THE WPML "TRANSLATE" FIELDS THIS OBJECT ACTUALLY HOLDS TEXT IN — as
	 * fields of the object, keyed `meta:<key>`, so everything downstream (what
	 * is read, what is stale, what is sent, what waits, what the register
	 * claims, what is written) works unchanged.
	 *
	 * @return array<string,array{label:string,key:string,html:bool}>
	 */
	public static function extra_fields( array $o ): array {
		$out = [];
		if ( ! $o ) {
			return $out;
		}
		// A TERM HAS CUSTOM FIELDS TOO, and WPML keeps a separate list for them.
		if ( 'term' === ( $o['kind'] ?? '' ) ) {
			$keys = self::wpml_term_text_keys();
			if ( ! $keys ) {
				return $out;
			}
			$all = (array) get_term_meta( (int) $o['id'] );
			foreach ( $keys as $key ) {
				$raw = $all[ $key ] ?? null;
				$v   = is_array( $raw ) ? reset( $raw ) : $raw;
				if ( ! self::is_text( $v ) ) {
					continue;
				}
				$out[ 'meta:' . $key ] = [
					'label' => self::key_label( $key ),
					'key'   => $key,
					'html'  => self::looks_html( (string) $v ),
				];
			}
			return $out;
		}
		if ( 'post' !== ( $o['kind'] ?? '' ) ) {
			return $out;
		}
		$keys = self::wpml_text_keys();
		if ( ! $keys ) {
			return $out;
		}
		// One read of every key on the object, never one per key.
		$all = (array) get_post_meta( (int) $o['id'] );
		foreach ( $keys as $key ) {
			$raw = $all[ $key ] ?? null;
			$v   = is_array( $raw ) ? reset( $raw ) : $raw;
			if ( ! self::is_text( $v ) ) {
				continue;
			}
			$out[ 'meta:' . $key ] = [
				'label' => self::key_label( $key ),
				'key'   => $key,
				'html'  => self::looks_html( (string) $v ),
			];
		}
		return $out;
	}

	/**
	 * WHICH OF THOSE KEYS HOLD TEXT ON THIS KIND OF CONTENT, AND ON HOW MANY —
	 * the reading the dashboard prints per kind, in one query, kept an hour.
	 *
	 * A key no object of this kind holds text in is not listed at all: "a
	 * field that does not exist here" is not a line somebody has to read.
	 *
	 * @return array<string,int> key => how many objects of this type hold text in it
	 */
	public static function kind_text_keys( string $type ): array {
		global $wpdb;
		$keys = self::wpml_text_keys();
		if ( ! $keys || ! $wpdb || '' === $type ) {
			return [];
		}
		$ck  = self::KEYS_CACHE . md5( $type . '|' . implode( ',', $keys ) );
		$got = get_transient( $ck );
		if ( is_array( $got ) ) {
			return $got;
		}
		$in = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- keys escaped above, the type prepared.
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.meta_key, COUNT(*) AS n
			   FROM {$wpdb->postmeta} pm
			   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE p.post_type = %s AND p.post_status IN ('publish','draft','pending','private')
			    AND pm.meta_key IN ( {$in} )
			    AND pm.meta_value <> ''
			    AND pm.meta_value NOT LIKE 'a:%' AND pm.meta_value NOT LIKE 'O:%'
			    AND pm.meta_value NOT LIKE '{%' AND pm.meta_value NOT LIKE '[%'
			    AND pm.meta_value NOT LIKE 'field\\_%'
			    AND pm.meta_value NOT REGEXP '^[0-9., :/-]+$'
			  GROUP BY pm.meta_key",
			$type
		), ARRAY_A );
		// phpcs:enable
		$out = [];
		foreach ( $rows as $r ) {
			$k = (string) ( $r['meta_key'] ?? '' );
			$n = (int) ( $r['n'] ?? 0 );
			if ( '' !== $k && $n > 0 ) {
				$out[ $k ] = $n;
			}
		}
		set_transient( $ck, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/** The per-kind reading above is kept an hour under this prefix (declared in DZE_Cleanup). */
	public const KEYS_CACHE = 'dze_tr_keys_';

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

	/**
	 * THE VARIATIONS' OWN WORDS, AS FIELDS OF THE PRODUCT THAT OWNS THEM.
	 *
	 * "Produits : grosse lacune, les attributs ne sont pas gérés, les
	 * variations non plus. Testé sur produit avec trad en français." A variable
	 * product keeps a description per variation — "the olive one has a black
	 * zip" — in the variation post's own excerpt, and none of it was ever sent:
	 * a French product page showed its colours described in English with
	 * nothing anywhere saying why.
	 *
	 * A variation is not a thing anybody browses, so it is not a scope of its
	 * own: it is a FIELD of the product, `var:<id>`. Everything downstream —
	 * what is stale, what is sent, what waits for a decision, what the register
	 * claims — then works unchanged, because there is still only one object.
	 *
	 * Only variations that actually hold words appear: an empty one is a line
	 * somebody has to decide about every time they read the screen.
	 *
	 * @return array<string,array{label:string,vid:int}> field id => the variation
	 */
	public static function variation_fields( array $o ): array {
		// A VARIATION'S DESCRIPTION IS NOT THIS MODULE'S BUSINESS.
		//
		// "On ne veut pas traduire les descriptions de variation, normalement
		// c'est réglé comme ça dans WPML." It is: WPML decides, per field,
		// what travels to a translation, and a shop that has told it to leave
		// variation descriptions alone has already answered the question. This
		// module offering them anyway put ten empty boxes on the screen, each
		// labelled "words have moved since the last translation", for text
		// nobody intends to write.
		//
		// The variations are still COUNTED — see `variation_tally()` — so the
		// screen can say they exist and that there is nothing of theirs to do.
		// Saying nothing is what made a working module look broken.
		return [];
	}

	/**
	 * THE ATTRIBUTE VALUES THIS PRODUCT WEARS, as objects of their own.
	 *
	 * A size or a colour is a TERM, shared by every product wearing it, so it
	 * is not a field of the product: translating one from here changes it
	 * everywhere it is used, and the screen says so rather than pretending
	 * otherwise. Until now the only way in was the batch list — hunting one
	 * term among a taxonomy's hundreds, with nothing saying which product
	 * needed it — so a product could read "up to date" while the words a
	 * customer actually picks from were still in English.
	 *
	 * Read with a plain query on purpose: `get_terms()` and `wp_get_post_terms()`
	 * are filtered by WPML to the CURRENT language, which on this screen is the
	 * wrong one — it would answer with the translations and never the source.
	 *
	 * Local attributes (free text in `_product_attributes`) are deliberately
	 * absent: WooCommerce keeps those on the product itself, where WPML copies
	 * them with the rest of it.
	 *
	 * @return array<string,array{obj:array,tax:string,tax_label:string,name:string}>
	 */
	public static function attribute_objects( array $o ): array {
		$out = [];
		if ( ! $o || 'post' !== ( $o['kind'] ?? '' ) || 'product' !== ( $o['type'] ?? '' ) ) {
			return $out;
		}
		// The taxonomies to ask about are the site's OWN attribute list, taken
		// from the scope. Asking for `pa_%` instead would be a guess at a naming
		// convention, and would sweep in an attribute this site has chosen not
		// to translate.
		$scope = self::scope();
		$taxes = [];
		foreach ( $scope as $row ) {
			if ( 'term' === ( $row['kind'] ?? '' ) && ! empty( $row['attr'] ) ) {
				$taxes[] = (string) $row['type'];
			}
		}
		if ( ! $taxes ) {
			return $out;
		}
		global $wpdb;
		$slots = implode( ', ', array_fill( 0, count( $taxes ), '%s' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $slots is a list of placeholders.
				"SELECT tt.taxonomy, t.term_id, t.name
				   FROM {$wpdb->term_relationships} tr
				   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				   JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				  WHERE tr.object_id = %d AND tt.taxonomy IN ( {$slots} )
				  ORDER BY tt.taxonomy, t.name",
				array_merge( [ (int) $o['id'] ], $taxes )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $row ) {
			$tax = (string) $row->taxonomy;
			if ( ! isset( $scope[ 'term:' . $tax ] ) ) {
				continue; // a taxonomy this site does not translate is not a decision.
			}
			$tid = (int) $row->term_id;
			$out[ 'term:' . $tax . ':' . $tid ] = [
				'obj'       => [ 'kind' => 'term', 'id' => $tid, 'type' => $tax ],
				'tax'       => $tax,
				'tax_label' => (string) ( $scope[ 'term:' . $tax ]['label'] ?? $tax ),
				'name'      => (string) $row->name,
			];
		}
		return $out;
	}

	/**
	 * HOW MANY VARIATIONS THIS PRODUCT HAS, and how many hold words.
	 *
	 * `variation_fields()` answers with the ones that hold a description,
	 * which is the only thing about a variation there is to translate. On a
	 * product whose variations carry none it therefore answers with nothing —
	 * correct, and indistinguishable on screen from a module that forgot
	 * them. This is what lets the screen say which of the two it is.
	 *
	 * @return array{total:int,with_text:int}
	 */
	/**
	 * THE VARIATIONS, ONE ROW EACH, AND WHETHER THE TRANSLATION HAS THEM.
	 *
	 * "Je n'aime pas trop comment tu as fait c'est caché dans un petit texte.
	 * On ne lit jamais ces textes." Right, and WPML does not do that either:
	 * its own editor gives variations a section of their own, one group per
	 * variation. A sentence is what you write when you have nothing to show.
	 *
	 * There IS something to show, and it is the thing that broke: a translated
	 * variable product whose variations were never built has no options to
	 * pick from, so its page says the product is unavailable while every
	 * screen in the admin says the translation is finished. That question —
	 * is this variation there, in this language — is what the rows answer.
	 *
	 * @return array<int,array{id:int,label:string,target:int,price:string}>
	 */
	public static function variation_rows( array $o, string $lang ): array {
		$out = [];
		if ( ! $o || 'post' !== ( $o['kind'] ?? '' ) || 'product' !== ( $o['type'] ?? '' ) ) {
			return $out;
		}
		if ( ! function_exists( 'get_children' ) || ! function_exists( 'wc_get_product' ) ) {
			return $out;
		}
		foreach ( (array) get_children( [
			'post_parent' => (int) $o['id'],
			'post_type'   => 'product_variation',
			'post_status' => [ 'publish', 'private' ],
			'numberposts' => 200,
			'orderby'     => 'menu_order',
			'order'       => 'ASC',
		] ) as $kid ) {
			$vid = (int) ( is_object( $kid ) ? $kid->ID : 0 );
			if ( ! $vid ) {
				continue;
			}
			$tid = '' !== $lang
				? (int) apply_filters( 'wpml_object_id', $vid, 'product_variation', false, $lang )
				: 0;
			// `wc_get_product()` answers false for a product that is not there,
			// and a filter may hand back something else entirely. A price is
			// asked for only where there is something to ask.
			$got = ( $tid && $tid !== $vid ) ? wc_get_product( $tid ) : null;
			$has = is_object( $got ) && method_exists( $got, 'get_price' );
			$out[] = [
				'id'     => $vid,
				'label'  => self::variation_label( $vid ),
				'target' => ( $tid && $tid !== $vid ) ? $tid : 0,
				'price'  => $has ? (string) $got->get_price() : '',
			];
		}
		return $out;
	}

	/**
	 * WHAT WPML DOES WITH A VARIATION'S OWN DESCRIPTION, in its own words.
	 *
	 * 0 = leave it alone, 1 = copy it across, 2 = translate it. Read rather
	 * than assumed: this screen has no business asserting a setting that lives
	 * one plugin over, and a shop that changes it should see the change here.
	 */
	public static function variation_desc_rule(): int {
		$s = get_option( 'icl_sitepress_settings' );
		$f = $s['translation-management']['custom_fields_translation'] ?? [];
		return isset( $f['_variation_description'] ) ? (int) $f['_variation_description'] : 0;
	}
	public static function variation_tally( array $o ): array {
		$out = [ 'total' => 0, 'with_text' => 0 ];
		if ( ! $o || 'post' !== ( $o['kind'] ?? '' ) || 'product' !== ( $o['type'] ?? '' ) ) {
			return $out;
		}
		$out['total'] = self::variation_count( (int) $o['id'] );
		if ( ! $out['total'] || ! function_exists( 'get_children' ) ) {
			return $out;
		}
		// Counted here rather than taken from `variation_fields()`, which now
		// answers with nothing on purpose. The figure is only ever said on
		// screen, never acted on.
		foreach ( (array) get_children( [
			'post_parent' => (int) $o['id'],
			'post_type'   => 'product_variation',
			'post_status' => [ 'publish', 'private' ],
			'numberposts' => 200,
		] ) as $kid ) {
			if ( is_object( $kid ) && '' !== trim( (string) $kid->post_excerpt ) ) {
				$out['with_text']++;
			}
		}
		return $out;
	}

	/**
	 * WHAT TO CALL A VARIATION ON SCREEN: the colour and the size, not "#4182".
	 *
	 * Read from the variation's own `attribute_*` meta, which is where
	 * WooCommerce keeps the combination. A term slug is turned back into the
	 * term's name where the taxonomy answers, because "olive-drab" is not what
	 * anybody calls it.
	 */
	public static function variation_label( int $vid ): string {
		$bits = [];
		foreach ( (array) get_post_meta( $vid ) as $key => $vals ) {
			if ( 0 !== strpos( (string) $key, 'attribute_' ) ) {
				continue;
			}
			$slug = is_array( $vals ) ? (string) reset( $vals ) : (string) $vals;
			if ( '' === $slug ) {
				continue;
			}
			$tax  = substr( (string) $key, strlen( 'attribute_' ) );
			$term = taxonomy_exists( $tax ) ? get_term_by( 'slug', $slug, $tax ) : null;
			$bits[] = ( $term && ! is_wp_error( $term ) ) ? (string) $term->name : $slug;
		}
		$said = implode( ' · ', array_filter( $bits ) );
		/* translators: %s: the variation, named by its attributes */
		return '' !== $said
			? sprintf( __( 'Variation — %s', 'dazont-ecom' ), $said )
			: sprintf( __( 'Variation #%d', 'dazont-ecom' ), $vid );
	}

	/**
	 * EVERY FIELD OF AN OBJECT BY NAME, variations included.
	 *
	 * One function, because the labels travel to three screens and a field the
	 * screen cannot name is printed as `var:4182`.
	 *
	 * @return array<string,string>
	 */
	public static function labels_for( array $o ): array {
		$out = [];
		foreach ( self::fields( (string) ( $o['kind'] ?? 'post' ), (string) ( $o['type'] ?? '' ) ) as $fid => $f ) {
			$out[ $fid ] = (string) $f['label'];
		}
		foreach ( self::extra_fields( $o ) as $fid => $f ) {
			$out[ $fid ] = (string) $f['label'];
		}
		foreach ( self::elementor_fields( $o ) as $fid => $f ) {
			$out[ $fid ] = (string) $f['label'];
		}
		foreach ( self::variation_fields( $o ) as $fid => $f ) {
			$out[ $fid ] = (string) $f['label'];
		}
		return $out;
	}

	/**
	 * WHICH PANEL A FIELD BELONGS IN.
	 *
	 * "La tienne est très brute. Regardes peut être comment WPML présente ça."
	 * WPML's own editor does not print one long table: it prints WordPress
	 * panels, one per kind of thing, and that is why it reads as part of the
	 * admin rather than as a plugin's own furniture. Twelve rows of equal
	 * weight say nothing about what matters; four named panels do.
	 *
	 * The order of the panels is the order a shop thinks in — what the
	 * customer reads first, then what Google reads, then the rest.
	 *
	 * @return array<string,array{label:string,fields:string[]}>
	 */
	public static function field_groups(): array {
		return [
			'main' => [
				'label'  => __( 'What the customer reads', 'dazont-ecom' ),
				'fields' => [ 'title', 'content', 'excerpt', 'name', 'description' ],
			],
			'seo'  => [
				'label'  => __( 'Search engines', 'dazont-ecom' ),
				'fields' => [ 'seo_title', 'seo_desc' ],
			],
			'block' => [
				'label'  => __( 'Content blocks', 'dazont-ecom' ),
				'fields' => [ 'block_text_1', 'block_text_2' ],
			],
		];
	}

	/** The panel a field sits in, 'other' for anything the list does not name. */
	public static function field_group( string $fid ): string {
		foreach ( self::field_groups() as $key => $g ) {
			if ( in_array( $fid, (array) $g['fields'], true ) ) {
				return $key;
			}
		}
		return 'other';
	}

	/**
	 * THE WIDGET SETTINGS ELEMENTOR ACTUALLY SHOWS, as fields of their own.
	 *
	 * A page built with Elementor does not display `post_content`: it displays
	 * `_elementor_data`, a tree of widgets whose words live in their settings.
	 * The post content beside it is a flattened copy kept for search engines.
	 * So translating `content` on such a page translated something nobody
	 * reads, and the page itself came out in English — which is exactly what a
	 * shop sees when it presses Translate on a landing page and nothing
	 * happens.
	 *
	 * Only the settings that ARE words are offered: a colour, a size, an id or
	 * a URL is not a translation. `is_text()` decides, as everywhere else.
	 *
	 * @return array<string,array{label:string,path:string,html:bool}>
	 */
	public static function elementor_fields( array $o ): array {
		$out = [];
		if ( ! $o || 'post' !== ( $o['kind'] ?? '' ) ) {
			return $out;
		}
		$raw = get_post_meta( (int) $o['id'], '_elementor_data', true );
		$tree = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $tree ) || ! $tree ) {
			return $out;
		}
		// The settings a widget keeps its words in. Written down rather than
		// guessed: a widget has dozens of settings and all but a handful are
		// numbers, colours and switches.
		$words = [ 'title', 'editor', 'text', 'description', 'heading_title', 'sub_title',
			'button_text', 'caption', 'alt_text', 'placeholder', 'before_text', 'after_text',
			'highlighted_text', 'rotating_text', 'shortcode', 'tab_title', 'tab_content' ];
		$walk = static function ( array $els, string $trail ) use ( &$walk, &$out, $words ): void {
			foreach ( $els as $i => $el ) {
				$id = (string) ( $el['id'] ?? $i );
				$s  = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : [];
				foreach ( $words as $key ) {
					if ( ! isset( $s[ $key ] ) || ! is_string( $s[ $key ] ) ) {
						continue;
					}
					$v = trim( $s[ $key ] );
					// A shortcode is a call, not a sentence: translating
					// `[products on_sale="true"]` breaks the page it sits on.
					if ( '' === $v || 'shortcode' === $key || ! self::is_text( $v ) ) {
						continue;
					}
					$out[ 'el:' . $id . ':' . $key ] = [
						'label' => sprintf(
							/* translators: 1: the widget, 2: the setting */
							__( 'Elementor · %1$s · %2$s', 'dazont-ecom' ),
							(string) ( $el['widgetType'] ?? $el['elType'] ?? 'element' ),
							self::key_label( $key )
						),
						'path' => $id . ':' . $key,
						'html' => self::looks_html( $v ),
					];
				}
				// Repeaters: the lists a widget repeats, whose rows hold words.
				foreach ( [ 'icon_list', 'tabs', 'slides', 'items' ] as $rep ) {
					if ( empty( $s[ $rep ] ) || ! is_array( $s[ $rep ] ) ) {
						continue;
					}
					foreach ( $s[ $rep ] as $n => $row ) {
						if ( ! is_array( $row ) ) {
							continue;
						}
						foreach ( $words as $key ) {
							if ( ! isset( $row[ $key ] ) || ! is_string( $row[ $key ] ) ) {
								continue;
							}
							$v = trim( $row[ $key ] );
							if ( '' === $v || ! self::is_text( $v ) ) {
								continue;
							}
							$out[ 'el:' . $id . ':' . $rep . '.' . $n . '.' . $key ] = [
								'label' => sprintf(
									/* translators: 1: the widget, 2: the row, 3: the setting */
									__( 'Elementor · %1$s · row %2$d · %3$s', 'dazont-ecom' ),
									(string) ( $el['widgetType'] ?? 'list' ),
									(int) $n + 1,
									self::key_label( $key )
								),
								'path' => $id . ':' . $rep . '.' . $n . '.' . $key,
								'html' => self::looks_html( $v ),
							];
						}
					}
				}
				if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
					$walk( $el['elements'], $trail );
				}
			}
		};
		$walk( $tree, '' );
		return $out;
	}

	/** One widget setting, read from the tree. */
	public static function elementor_get( int $pid, string $path ): string {
		$raw  = get_post_meta( $pid, '_elementor_data', true );
		$tree = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $tree ) ) {
			return '';
		}
		$found = '';
		$walk  = static function ( array $els ) use ( &$walk, $path, &$found ): void {
			[ $want, $key ] = array_pad( explode( ':', $path, 2 ), 2, '' );
			foreach ( $els as $el ) {
				if ( (string) ( $el['id'] ?? '' ) === $want ) {
					$s = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : [];
					if ( false === strpos( $key, '.' ) ) {
						$found = isset( $s[ $key ] ) && is_string( $s[ $key ] ) ? $s[ $key ] : '';
					} else {
						[ $rep, $n, $sub ] = array_pad( explode( '.', $key, 3 ), 3, '' );
						$found = isset( $s[ $rep ][ (int) $n ][ $sub ] ) && is_string( $s[ $rep ][ (int) $n ][ $sub ] )
							? $s[ $rep ][ (int) $n ][ $sub ] : '';
					}
					return;
				}
				if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
					$walk( $el['elements'] );
					if ( '' !== $found ) {
						return;
					}
				}
			}
		};
		$walk( $tree );
		return $found;
	}

	/**
	 * Writes widget settings back into a translation's own Elementor tree.
	 *
	 * @param array<string,string> $values path => text
	 */
	public static function elementor_put( int $pid, array $values ): bool {
		if ( ! $pid || ! $values ) {
			return false;
		}
		$raw  = get_post_meta( $pid, '_elementor_data', true );
		$tree = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $tree ) || ! $tree ) {
			return false;
		}
		$done = 0;
		$walk = static function ( array &$els ) use ( &$walk, $values, &$done ): void {
			foreach ( $els as &$el ) {
				$id = (string) ( $el['id'] ?? '' );
				foreach ( $values as $path => $text ) {
					[ $want, $key ] = array_pad( explode( ':', (string) $path, 2 ), 2, '' );
					if ( $want !== $id || '' === $key ) {
						continue;
					}
					if ( false === strpos( $key, '.' ) ) {
						$el['settings'][ $key ] = (string) $text;
					} else {
						[ $rep, $n, $sub ] = array_pad( explode( '.', $key, 3 ), 3, '' );
						if ( isset( $el['settings'][ $rep ][ (int) $n ] ) ) {
							$el['settings'][ $rep ][ (int) $n ][ $sub ] = (string) $text;
						}
					}
					$done++;
				}
				if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
					$walk( $el['elements'] );
				}
			}
		};
		$walk( $tree );
		if ( ! $done ) {
			return false;
		}
		update_post_meta( $pid, '_elementor_data', wp_slash( (string) wp_json_encode( $tree ) ) );
		// Elementor serves a cached copy of the HTML it built; without this the
		// page keeps showing the old words however correct the data now is.
		delete_post_meta( $pid, '_elementor_element_cache' );
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			$css = new \Elementor\Core\Files\CSS\Post( $pid );
			$css->delete();
			$css->update();
		}
		return true;
	}

	/**
	 * A TERM AS THE DATABASE HOLDS IT, with no language filter over it.
	 *
	 * @return array{name:string,description:string,slug:string}|null
	 */
	public static function term_row( int $term_id ): ?array {
		if ( $term_id <= 0 ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.name, t.slug, tt.description
				   FROM {$wpdb->terms} t
				   JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				  WHERE t.term_id = %d
				  LIMIT 1",
				$term_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,string> field id => text, empty fields dropped. */
	public static function obj_read( array $o ): array {
		if ( ! $o ) {
			return [];
		}
		$out = [];
		if ( 'term' === $o['kind'] ) {
			// READ FROM THE TABLES, NOT THROUGH `get_term()`.
			//
			// WPML filters `get_term` to the CURRENT language: asking for term
			// 7552 answers with term 2563, its English original. So this screen
			// showed the source in the translation's place, `obj_stale()`
			// compared the source against itself, and an undo would have put
			// English back over a finished translation. Four term pairs tested,
			// four wrong answers. The same trap as `get_terms()` on attributes.
			$row = self::term_row( (int) $o['id'] );
			if ( ! $row ) {
				return [];
			}
			foreach ( self::active_fields( 'term' ) as $fid => $f ) {
				$v = trim( (string) ( $row[ $f['key'] ] ?? '' ) );
				if ( '' !== $v ) {
					$out[ $fid ] = $v;
				}
			}
			// AND THE TERM'S OWN CUSTOM FIELDS, which WPML keeps in a list of
			// their own and this module never opened.
			foreach ( self::extra_fields( $o ) as $fid => $f ) {
				$v = trim( (string) get_term_meta( (int) $o['id'], (string) $f['key'], true ) );
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
		// AND EVERY CUSTOM FIELD WPML SAYS TO TRANSLATE, where it holds text.
		foreach ( self::extra_fields( $o ) as $fid => $f ) {
			$v = trim( (string) get_post_meta( (int) $o['id'], (string) $f['key'], true ) );
			if ( '' !== $v ) {
				$out[ $fid ] = $v;
			}
		}
		// AND THE WORDS ELEMENTOR SHOWS, which are not in the post content at
		// all. A page built with it displays its own tree; what sits in
		// `post_content` beside it is a copy kept for search engines.
		foreach ( self::elementor_fields( $o ) as $fid => $f ) {
			$v = trim( self::elementor_get( (int) $o['id'], (string) $f['path'] ) );
			if ( '' !== $v ) {
				$out[ $fid ] = $v;
			}
		}
		// AND THE WORDS THE VARIATIONS CARRY. A variable product's colours are
		// described one by one in the variation's own excerpt, and none of it
		// was ever sent: a French product page showed them in English.
		foreach ( self::variation_fields( $o ) as $fid => $f ) {
			$v = trim( (string) get_post_field( 'post_excerpt', (int) $f['vid'] ) );
			if ( '' !== $v ) {
				$out[ $fid ] = $v;
			}
		}
		return $out;
	}

	/**
	 * The existing translation of an object in one language, or 0.
	 *
	 * ASKED OF THE TABLE WHEN THE FILTER SAYS NOTHING, and 0 when the answer
	 * is the object itself. `wpml_object_id` is a filter: where WPML's hooks
	 * are not loaded — admin-ajax, cron — `apply_filters` hands back the id it
	 * was GIVEN, so "there is no translation" and "here is the translation"
	 * came out as the same number. Every screen then showed the ENGLISH text
	 * under "The translation today" and reported the translation as existing.
	 */
	public static function obj_translation( array $o, string $lang ): int {
		if ( ! $o || ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			return 0;
		}
		$id  = (int) $o['id'];
		$got = 'term' === ( $o['kind'] ?? 'post' )
			? DZE_Wpml::translated_term( $id, (string) $o['type'], $lang )
			: DZE_Wpml::translated_id( $id, (string) $o['type'], $lang );
		return ( $got && $got !== $id ) ? $got : 0;
	}

	/**
	 * PUTS BACK WHAT THE LAST WRITE REPLACED.
	 *
	 * @return array<string,string> The fields restored, empty when there was
	 *                              nothing to put back.
	 */
	public static function obj_undo( array $o, int $target_id ): array {
		if ( ! $o || ! $target_id ) {
			return [];
		}
		$raw  = self::meta_read( $o, $target_id, self::META_PREV );
		$prev = ( is_string( $raw ) && '' !== $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $prev ) || ! $prev ) {
			return [];
		}
		self::obj_write( $o, $target_id, $prev, false );
		// Spent. An undo offered twice would put the words back a second time
		// and say it had done something, which is a lie the second time.
		self::meta_write( $o, $target_id, self::META_PREV, '' );
		return $prev;
	}

	/** True when this translation still holds something to go back to. */
	public static function can_undo( array $o, int $target_id ): bool {
		if ( ! $o || ! $target_id ) {
			return false;
		}
		$raw = self::meta_read( $o, $target_id, self::META_PREV );
		return is_string( $raw ) && '' !== $raw && is_array( json_decode( $raw, true ) );
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
	public static function obj_write( array $o, int $target_id, array $texts, bool $remember = true ): void {
		if ( ! $o || ! $target_id ) {
			return;
		}
		// WHAT IS ABOUT TO BE REPLACED, read from the translation as it stands
		// right now and kept whole. `$remember` is false only when an undo is
		// putting those very words back: remembering then would file the text
		// being thrown away as the thing to restore.
		if ( $remember && $texts ) {
			$was  = self::obj_read( array_merge( $o, [ 'id' => $target_id ] ) );
			$prev = [];
			foreach ( array_keys( $texts ) as $fid ) {
				// A field the translation did not hold is remembered as empty,
				// not skipped: putting it back must empty it again, or an undo
				// leaves half of what it undid.
				$prev[ (string) $fid ] = (string) ( $was[ $fid ] ?? '' );
			}
			self::meta_write( $o, $target_id, self::META_PREV, (string) wp_json_encode( $prev ) );
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
			// THE TERM'S OWN CUSTOM FIELDS, from WPML's term list — the SEO
			// title and description a category carries. Written only while
			// WPML still says to translate them: a key switched to "Copy"
			// since the batch was made is a key the next sync overwrites.
			foreach ( $texts as $fid => $text ) {
				if ( 0 !== strpos( (string) $fid, 'meta:' ) ) {
					continue;
				}
				$key = substr( (string) $fid, 5 );
				if ( '' === $key || ! in_array( $key, self::wpml_term_text_keys(), true ) ) {
					continue;
				}
				$text = (string) $text;
				update_term_meta( $target_id, $key, self::looks_html( $text ) ? wp_kses_post( $text ) : sanitize_textarea_field( $text ) );
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
		// THE CUSTOM FIELDS WPML SAYS TO TRANSLATE. Written only while WPML
		// still says so: a key switched to "Copy" since the batch was made is
		// a key the next sync would overwrite, and words written there are
		// words lost.
		foreach ( $texts as $fid => $text ) {
			if ( 0 !== strpos( (string) $fid, 'meta:' ) ) {
				continue;
			}
			$key = substr( (string) $fid, 5 );
			if ( '' === $key || 2 !== DZE_Wpml::custom_field_mode( $key ) ) {
				continue;
			}
			$text = (string) $text;
			update_post_meta( $target_id, $key, self::looks_html( $text ) ? wp_kses_post( $text ) : sanitize_textarea_field( $text ) );
		}
		// THE ELEMENTOR TREE, written in one pass rather than one call per
		// setting: the tree is decoded, walked and saved once.
		$el = [];
		foreach ( $texts as $fid => $text ) {
			if ( 0 === strpos( (string) $fid, 'el:' ) ) {
				$el[ substr( (string) $fid, 3 ) ] = (string) $text;
			}
		}
		if ( $el ) {
			self::elementor_put( $target_id, $el );
		}
		if ( $post ) {
			$post['ID'] = $target_id;
			wp_update_post( $post );
		}
		// THE VARIATIONS' OWN WORDS, onto the variations WooCommerce
		// Multilingual made. We never CREATE one: linking a variation is WCML's
		// job, and doing it a second way here is how two plugins start fighting
		// over the same row. A variation WCML has not made yet is left alone,
		// and that field simply stays owed until it has.
		$lang = self::lang_of_translation( $o, $target_id );
		foreach ( $texts as $fid => $text ) {
			if ( '' === $lang || 0 !== strpos( (string) $fid, 'var:' ) ) {
				continue;
			}
			$vid  = (int) substr( (string) $fid, 4 );
			$mate = $vid ? DZE_Wpml::translated_id( $vid, 'product_variation', $lang ) : 0;
			if ( ! $mate ) {
				continue;
			}
			wp_update_post( [ 'ID' => $mate, 'post_excerpt' => wp_kses_post( (string) $text ) ] );
		}
	}

	/**
	 * WHICH LANGUAGE A TRANSLATION IS IN — asked, never guessed.
	 *
	 * `obj_write()` is handed the translated post and not the language, and
	 * the variations have to be found in that same language. Reading it back
	 * off the object is one question; carrying it down through four callers
	 * would be four places to keep in step.
	 */
	public static function lang_of_translation( array $o, int $target_id ): string {
		if ( ! $target_id || ! class_exists( 'DZE_Wpml' ) ) {
			return '';
		}
		return 'term' === ( $o['kind'] ?? 'post' )
			? (string) self::obj_language( [ 'kind' => 'term', 'id' => $target_id, 'type' => (string) $o['type'] ] )
			: (string) DZE_Wpml::post_language( $target_id, (string) ( $o['type'] ?? 'post' ) );
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

	/**
	 * WHAT IS TRANSLATED ON ONE KIND OF CONTENT, AND WHAT IS NOT — WPML's
	 * answer, never ours.
	 *
	 * The settings page used to carry a row of TICK BOXES headed "Which
	 * fields", and it was wrong twice: it was a decision the shop should not
	 * have to take — "le plugin doit traduire tout ce que wpml exige de
	 * traduire pour avoir une traduction complète du post" — and it listed a
	 * product's fields whatever kind of content was being looked at, so an
	 * article appeared to have a short description and no SEO title.
	 *
	 * This is a READING instead, per kind, and it says both halves: the fields
	 * this module sends, and the fields WPML is set to translate that it does
	 * NOT send. The second half is the one worth having — a custom field WPML
	 * wants translated and nobody translates is a page that comes out half in
	 * English with nothing anywhere saying why.
	 *
	 * @return array<int,array{label:string,key:string,said:string,tone:string}>
	 */
	public static function field_report( string $kind, string $type ): array {
		$out  = [];
		$wpml = class_exists( 'DZE_Wpml' );
		foreach ( self::fields( $kind, $type ) as $fid => $f ) {
			$key  = ( 'meta' === ( $f['type'] ?? '' ) ) ? self::meta_key_for( $fid ) : (string) ( $f['key'] ?? '' );
			$mode = ( 'meta' === ( $f['type'] ?? '' ) && '' !== $key && $wpml ) ? DZE_Wpml::custom_field_mode( $key ) : -1;
			// THE SEO PAIR IS THE ONE FIELD WHOSE KEY DEPENDS ON A PLUGIN
			// BEING THERE. With no SEO plugin installed the key falls back to
			// one of our own that nothing on the site reads, and "translated"
			// printed over it is a screen promising work nobody will ever see.
			if ( '' === $key || 0 === strpos( $key, '_dze_seo' ) ) {
				$out[] = [
					'label' => $f['label'],
					'key'   => $key,
					'tone'  => 'off',
					'said'  => __( 'no SEO plugin was found on this site, so nothing reads this field and it is not sent.', 'dazont-ecom' ),
				];
				continue;
			}
			if ( in_array( $mode, [ 1, 3 ], true ) ) {
				// A FIELD WPML COPIES IS NEVER WRITTEN HERE: the next sync puts
				// the original's value straight back and the words are lost
				// with nothing saying so.
				$out[] = [
					'label' => $f['label'],
					'key'   => $key,
					'tone'  => 'warn',
					'said'  => __( 'WPML is set to COPY this field from the original, so it is left alone. Set it to "Translate" in WPML → Settings → Custom Fields Translation.', 'dazont-ecom' ),
				];
				continue;
			}
			$out[] = [ 'label' => $f['label'], 'key' => $key, 'tone' => 'ok', 'said' => __( 'translated', 'dazont-ecom' ) ];
		}
		// AND EVERY CUSTOM FIELD WPML WANTS TRANSLATED — as it stands ON THIS
		// KIND. WPML's map is one list for every post type, so read whole it
		// told a page about a product's fields and both about keys nothing on
		// the site carries: "des meta fields qui n'ont aucun intérêt à
		// traduire pour certaines pages, voire n'existent même pas". Only a
		// key that holds text on objects of this kind is a line here, and it
		// says on how many. They are SENT — the rule is a complete
		// translation, not a list of what was left out.
		if ( 'post' === $kind && $wpml ) {
			foreach ( self::kind_text_keys( $type ) as $key => $n ) {
				$out[] = [
					'label' => self::key_label( $key ),
					'key'   => $key,
					'tone'  => 'ok',
					'said'  => sprintf(
						/* translators: %s: how many objects of this kind hold text in the field */
						_n( 'translated where it holds text — %s of this kind does.', 'translated where it holds text — %s of this kind do.', $n, 'dazont-ecom' ),
						number_format_i18n( $n )
					),
				];
			}
		}
		// A PRODUCT IS MORE THAN ITS OWN FIVE FIELDS. Its variations carry
		// words of their own, and the terms it is sold by are objects shared
		// with every other product that carries them — said here, because a
		// reader who cannot see where they are assumes nobody translates them.
		if ( 'post' === $kind && 'product' === $type ) {
			$out[] = [
				'label' => __( 'Variation descriptions', 'dazont-ecom' ),
				'key'   => 'post_excerpt',
				'tone'  => 'ok',
				'said'  => __( 'sent with the product, and written onto the variations WooCommerce Multilingual made. A variation WCML has not created yet stays owed rather than being invented here.', 'dazont-ecom' ),
			];
			$out[] = [
				'label' => __( 'Attribute terms', 'dazont-ecom' ),
				'key'   => '',
				'tone'  => 'ok',
				'said'  => __( 'translated as objects of their own — one term serves every product that carries it, so it is paid for once. They have their own rows on this screen.', 'dazont-ecom' ),
			];
		}
		return $out;
	}

	// =========================================================================
	// Settings screen (a tab of the shared Settings page, never its own menu)
	// =========================================================================

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$s    = self::get_settings();
		$wpml = class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active();
		?>
		<div class="dze-admin">
		<?php if ( ! $wpml ) : ?>
			<div class="notice notice-warning inline" style="margin:12px 0;"><p>
				<?php esc_html_e( 'WPML is not running: there is no second language to translate into, and nothing here will do anything.', 'dazont-ecom' ); ?>
			</p></div>
		<?php endif; ?>
		<p class="description" style="max-width:900px;">
			<?php
			// The sentence used to describe the module as it was two versions
			// ago — "a product", a button in the Dazont Ecom box — and sent the
			// shop looking for a screen that no longer exists. The way in is
			// read from the catalogue.
			printf(
				/* translators: %s: link to the Translations screen */
				esc_html__( 'Translates products, articles, pages and the taxonomies WPML translates into the site\'s other languages, and holds every translation for a yes or a no. The work is under %s.', 'dazont-ecom' ),
				'<a href="' . esc_url( DZE_Screens::url( 'translations' ) ) . '">' . esc_html( DZE_Screens::name( 'translations' ) ) . '</a>'
			);
			?>
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
					<th scope="row"><label for="dze-tr-model"><?php esc_html_e( 'Model', 'dazont-ecom' ); ?></label></th>
					<td>
						<?php
						// THE SAME LIST THE GENERAL TAB OFFERS — read from the
						// account, with the one saved here kept selectable even
						// when the account no longer lists it.
						$dze_models = is_callable( [ 'DZE_Marketing_Ai', 'available_models' ] ) ? (array) DZE_Marketing_Ai::available_models() : (array) DZE_Marketing_Ai::MODELS;
						$dze_model  = (string) ( $s['model'] ?? '' );
						if ( '' !== $dze_model && ! isset( $dze_models[ $dze_model ] ) ) {
							$dze_models = [ $dze_model => $dze_model ] + $dze_models;
						}
						?>
						<select id="dze-tr-model" name="<?php echo esc_attr( self::OPT ); ?>[model]">
							<option value=""><?php esc_html_e( 'The model chosen on the General tab', 'dazont-ecom' ); ?></option>
							<?php foreach ( $dze_models as $mid => $mlabel ) : ?>
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
						<p class="description"><?php esc_html_e( 'The new translation is linked to the original, and WPML is asked to copy the fields it owns from it — on a product its price, stock and dimensions. Switch this off to work only on translations WPML has already created.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'WPML\'s own buttons', 'dazont-ecom' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( self::OPT ); ?>[buttons_sent]" value="1" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[buttons]" value="1" <?php checked( self::owns_buttons() ); ?> />
							<?php esc_html_e( 'The + and the pencil in the Languages column open this module', 'dazont-ecom' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'One habit instead of two: you keep pressing where you already press, and this module answers instead of WPML\'s editor — on the kinds of content ticked above, and on nothing else. Switch it off and the buttons go back to WPML on the next page load.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Changes', 'dazont-ecom' ) ); ?>
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
	 * in it — never a second reading beside `obj_targets()`, or the screen and
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
	public static function produce( array $o, array $langs, bool $all = false ): array {
		$out     = [ 'langs' => [], 'skipped' => [], 'errors' => [], 'cost' => false ];
		if ( ! $o ) {
			return $out;
		}
		$targets = self::obj_targets( $o );
		$source  = [];
		foreach ( $langs as $lang ) {
			$lang = sanitize_key( (string) $lang );
			if ( '' === $lang || ! isset( $targets[ $lang ] ) ) {
				// IT FAILS LOUDLY RATHER THAN QUIETLY. A language asked for and
				// not recognised used to be skipped in silence — so when
				// `wpml_active_languages` answered nothing in admin-ajax, every
				// language fell through here, the job came back with no langs,
				// no skipped and no errors, and the screen concluded "nothing
				// had moved on any of them" on a shop that had translated
				// nothing at all. An answer nobody asked for is worse than an
				// error nobody wanted.
				$out['errors'][ $lang ?: '?' ] = sprintf(
					/* translators: %s: the language code that was asked for */
					__( 'WPML does not offer %s as a translation of this one. Check that the language is active in WPML.', 'dazont-ecom' ),
					strtoupper( (string) $lang )
				);
				continue;
			}
			// ONLY WHAT ACTUALLY MOVED IS PAID FOR — the whole reason this
			// module exists beside WPML's own automatic translation. A mark
			// raised because a category was renamed sends nothing at all, and
			// is closed on the spot.
			// $all IS FOR JUDGING THE TRANSLATOR, NOT THE TEXT. Changing the
			// model, the instructions or the glossary changes nothing about the
			// ORIGINAL, so the register is right to say nothing moved and the
			// screen would answer "nothing was sent" for ever. Asked for
			// everything, it sends everything and pays for everything — which
			// is why it is a second button and never the default.
			$texts = $all ? self::obj_read( $o ) : self::obj_stale( $o, $lang );
			if ( ! $texts ) {
				// Nothing to send. Only the ordinary run may call that settled:
				// an empty answer to "translate everything" means the original
				// holds no text at all, which settles nothing.
				if ( ! $all ) {
					self::obj_settle( $o, $lang );
				}
				$out['skipped'][] = $lang;
				continue;
			}
			try {
				$new = self::translate( $texts, $lang, (string) $o['kind'], self::labels_for( $o ) );
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
		$out = [ 'written' => [], 'errors' => [], 'warnings' => [] ];
		if ( ! $o || ! $keep ) {
			return $out;
		}
		$held    = self::waiting( $o );
		$source  = (array) ( $held['src'] ?? [] );
		$targets = self::obj_targets( $o );
		// EVERY FIELD OF THIS OBJECT, variations included. Narrowed to
		// `fields()` the `var:` rows were silently dropped on accept, so the
		// words each variation carries were read, paid for, shown on screen —
		// and never written.
		$allowed = self::labels_for( $o );
		$src_now = null;
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
				// A VARIABLE PRODUCT WHOSE VARIATIONS COULD NOT BE BUILT is a
				// page WooCommerce renders as "out of stock and unavailable",
				// with no price and no buy button. The text was written and is
				// worth keeping — so this is a warning and not an error — but a
				// screen that says nothing about it is a screen that ships a
				// hundred and sixty unbuyable pages without a word.
			}
			// THE PLUMBING IS NOT NARRATED, IT IS DONE. A translated variable
			// product whose axes and variations WooCommerce Multilingual has not
			// built renders as unavailable — and on a translation those fields
			// are read-only, so nobody can mend one by hand. So every write asks
			// WCML for that sync, whether the translation was just created or has
			// been there for a year. It is a consequence of saving, never a
			// button somebody has to find, and never a panel explaining itself.
			if ( self::needs_variations( $o ) && ! self::synced_variations( $o, $target ) ) {
				self::sync_product( (int) $o['id'], $target, $lang );
				if ( ! self::synced_variations( $o, $target ) ) {
					$out['warnings'][ $lang ] = __( 'The text was written, but this product\'s variations are still missing on the translation, so its page shows as unavailable.', 'dazont-ecom' );
				}
			}
			self::obj_write( $o, $target, array_map( 'strval', $texts ) );
			self::meta_write( $o, $target, self::META_MINE, '1' );
			// THE REGISTER IS WRITTEN AGAINST WHAT WAS SENT, never against the
			// source as it stands now: accepting a batch a week later must not
			// claim a field is current when somebody has edited it since.
			// A FIELD TYPED BY HAND was sent nowhere, so nothing was kept for
			// it — and left out of the register it stayed "words have moved"
			// for ever and the next batch paid to translate it again. The
			// person typing it read the original on the screen in front of
			// them, so THAT is the source it was made from.
			$reg     = array_intersect_key( $source, $texts );
			$untyped = array_diff_key( $texts, $reg );
			if ( $untyped ) {
				$src_now = $src_now ?? self::obj_read( $o );
				$reg    += array_intersect_key( $src_now, $untyped );
			}
			self::remember( $target, $reg, $o );
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

	/**
	 * WHAT IS WAITING, PER KIND, in two queries.
	 *
	 * "Le post n'est pas passé automatiquement dans 'to review'. Sur la ligne
	 * des posts, aucune mention 'x to review'." A batch that finishes and
	 * leaves the screen exactly as it was is a button nobody can tell worked.
	 * The dashboard row the batch was sent from says what came back on it, in
	 * WPML's own naming so the row and the count cannot drift.
	 *
	 * @return array<string,int> 'post_product' / 'tax_product_cat' => how many
	 */
	public static function review_counts(): array {
		global $wpdb;
		$out = [];
		if ( ! $wpdb ) {
			return $out;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own meta key, one query per kind.
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT p.post_type AS t, COUNT(*) AS n
			   FROM {$wpdb->postmeta} m
			   INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			  WHERE m.meta_key = %s
			  GROUP BY p.post_type",
			self::META_WAIT
		), ARRAY_A );
		foreach ( $rows as $r ) {
			$out[ 'post_' . (string) $r['t'] ] = (int) $r['n'];
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT tt.taxonomy AS t, COUNT(*) AS n
			   FROM {$wpdb->termmeta} m
			   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = m.term_id
			  WHERE m.meta_key = %s
			  GROUP BY tt.taxonomy",
			self::META_WAIT
		), ARRAY_A );
		// phpcs:enable
		foreach ( $rows as $r ) {
			$out[ 'tax_' . (string) $r['t'] ] = (int) $r['n'];
		}
		return $out;
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
	 * A long text is translated in pieces and put back together.
	 *
	 * The model answers in ONE reply, and that reply has a ceiling. A product
	 * description of 3,000 words asks for more room than the ceiling allows, so
	 * the JSON came back cut in half and the whole object failed with "the
	 * model did not answer with the expected format" — the longest texts, the
	 * ones worth the most, were the ones that never translated.
	 *
	 * So the work is cut to a size the model can finish. Short fields still
	 * travel together, because a title and the description under it have to
	 * choose the same words. A field too long to travel with anything is cut on
	 * paragraph boundaries — never inside a tag — and its pieces are rejoined
	 * in order, which gives back the original text exactly when nothing is
	 * translated at all.
	 */
	private const CHUNK = 5000;

	/** How a piece of a field is named while it travels. */
	private const PART = '~p';

	public static function translate( array $texts, string $lang_code, string $kind = 'post', array $names = [] ): array {
		if ( ! $texts ) {
			return [];
		}
		if ( ! class_exists( 'DZE_Marketing_Ai' ) ) {
			throw new RuntimeException( __( 'The Marketing Assistant module holds the Anthropic key — switch it back on.', 'dazont-ecom' ) );
		}
		// EVERY FIELD IS NAMED FOR THE MODEL, the custom ones and the
		// variations included: handed `meta:_theme_subtitle` and nothing else
		// it has to guess what kind of text it is looking at.
		if ( ! $names ) {
			foreach ( self::fields( $kind ) as $fid => $f ) {
				$names[ $fid ] = (string) $f['label'];
			}
		}

		$jobs  = [];
		$parts = [];
		$batch = [];
		$len   = 0;
		foreach ( $texts as $fid => $v ) {
			$v = (string) $v;
			$l = mb_strlen( $v );
			if ( $l > self::CHUNK ) {
				if ( $batch ) {
					$jobs[] = $batch;
					$batch  = [];
					$len    = 0;
				}
				$pieces        = self::split_text( $v, self::CHUNK );
				$parts[ $fid ] = count( $pieces );
				foreach ( $pieces as $n => $piece ) {
					$key           = $fid . self::PART . $n;
					$names[ $key ] = sprintf(
						/* translators: 1: the field's name, 2: which piece, 3: how many pieces */
						__( '%1$s — part %2$d of %3$d', 'dazont-ecom' ),
						$names[ $fid ] ?? $fid,
						$n + 1,
						count( $pieces )
					);
					$jobs[] = [ $key => $piece ];
				}
				continue;
			}
			if ( $batch && $len + $l > self::CHUNK ) {
				$jobs[] = $batch;
				$batch  = [];
				$len    = 0;
			}
			$batch[ $fid ] = $v;
			$len          += $l;
		}
		if ( $batch ) {
			$jobs[] = $batch;
		}

		$bag = [];
		foreach ( $jobs as $job ) {
			foreach ( self::translate_batch( $job, $lang_code, $names ) as $k => $v ) {
				$bag[ $k ] = $v;
			}
		}
		// A PIECE THAT DID NOT COME BACK IS ASKED FOR AGAIN, once. Half a
		// description is worse than none: the rest of the object translates and
		// this one field stays as it was, which the screen already shows.
		foreach ( $parts as $fid => $n ) {
			for ( $k = 0; $k < $n; $k++ ) {
				$key = $fid . self::PART . $k;
				if ( '' !== trim( (string) ( $bag[ $key ] ?? '' ) ) ) {
					continue;
				}
				$piece = self::split_text( (string) $texts[ $fid ], self::CHUNK )[ $k ] ?? '';
				if ( '' === $piece ) {
					continue;
				}
				$again = self::translate_batch( [ $key => $piece ], $lang_code, $names );
				if ( isset( $again[ $key ] ) ) {
					$bag[ $key ] = $again[ $key ];
				}
			}
		}
		DZE_Ai_Usage::finished( 'translate' );

		$out = [];
		foreach ( $texts as $fid => $_ ) {
			if ( isset( $parts[ $fid ] ) ) {
				$whole = '';
				for ( $k = 0; $k < $parts[ $fid ]; $k++ ) {
					$piece = (string) ( $bag[ $fid . self::PART . $k ] ?? '' );
					if ( '' === trim( $piece ) ) {
						$whole = '';
						break;
					}
					$whole .= $piece;
				}
				if ( '' !== trim( $whole ) ) {
					$out[ $fid ] = $whole;
				}
				continue;
			}
			$v = isset( $bag[ $fid ] ) ? (string) $bag[ $fid ] : '';
			if ( '' !== trim( $v ) ) {
				$out[ $fid ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Cuts a text into pieces of at most $max characters.
	 *
	 * The cuts land after a closing block, a block comment or a blank line, so
	 * a piece is never half a tag and Gutenberg's own `<!-- wp:… -->` markers
	 * stay whole. The pieces put end to end are the text that came in, to the
	 * character — that is what lets the translation be rejoined.
	 *
	 * @return array<int,string>
	 */
	public static function split_text( string $text, int $max ): array {
		if ( $max < 1 || mb_strlen( $text ) <= $max ) {
			return [ $text ];
		}
		$bits = preg_split(
			'#(?<=</p>|</h1>|</h2>|</h3>|</h4>|</h5>|</h6>|</ul>|</ol>|</li>|</table>|</blockquote>|</div>|-->|\n\n)#u',
			$text,
			-1,
			PREG_SPLIT_NO_EMPTY
		);
		if ( ! $bits ) {
			$bits = [ $text ];
		}
		// A single paragraph longer than the ceiling is cut at a full stop, and
		// failing that at the ceiling itself: a text that never breathes still
		// has to travel.
		$fine = [];
		foreach ( $bits as $bit ) {
			if ( mb_strlen( $bit ) <= $max ) {
				$fine[] = $bit;
				continue;
			}
			// A ZERO-WIDTH CUT. Splitting ON the space between sentences ate it,
			// and the pieces no longer added up to the text that came in.
			$sent = preg_split( '/(?<=[.!?])(?=\s)/u', $bit, -1, PREG_SPLIT_NO_EMPTY );
			$run  = '';
			foreach ( (array) $sent as $s ) {
				if ( '' !== $run && mb_strlen( $run ) + mb_strlen( $s ) > $max ) {
					$fine[] = $run;
					$run    = '';
				}
				$run .= $s;
				while ( mb_strlen( $run ) > $max ) {
					$fine[] = mb_substr( $run, 0, $max );
					$run    = mb_substr( $run, $max );
				}
			}
			if ( '' !== $run ) {
				$fine[] = $run;
			}
		}
		$out = [];
		$run = '';
		foreach ( $fine as $bit ) {
			if ( '' !== $run && mb_strlen( $run ) + mb_strlen( $bit ) > $max ) {
				$out[] = $run;
				$run   = '';
			}
			$run .= $bit;
		}
		if ( '' !== $run ) {
			$out[] = $run;
		}
		return $out ? $out : [ $text ];
	}

	/**
	 * One call: every field it is given, in the same request.
	 *
	 * Field by field would multiply the calls and lose the consistency between
	 * a title and the description under it — the same words have to be chosen
	 * in both.
	 *
	 * @param array<string,string> $texts
	 * @param array<string,string> $names
	 * @return array<string,string>
	 */
	private static function translate_batch( array $texts, string $lang_code, array $names ): array {
		if ( ! $texts ) {
			return [];
		}
		$lines = [];
		$cut   = false;
		foreach ( $texts as $fid => $v ) {
			$lines[] = '### ' . $fid . ' (' . ( $names[ $fid ] ?? $fid ) . ")\n" . $v;
			$cut     = $cut || false !== strpos( (string) $fid, self::PART );
		}
		$glossary = self::glossary();
		$system   = self::prompt()
			. "\n\nTarget language: " . self::language_name( $lang_code ) . '.'
			. ( $glossary ? "\n\nNever translate these terms, reproduce them exactly:\n- " . implode( "\n- ", $glossary ) : '' )
			// A PIECE IS TRANSLATED AS A PIECE. It may open mid-thought and
			// stop mid-thought; finishing it off, or opening it with a fresh
			// introduction, is what breaks a text back into shape wrongly when
			// the pieces are put end to end.
			. ( $cut ? "\n\nA field marked \"part N of M\" is one slice of a longer text. Translate exactly what is there: do not finish an unfinished sentence, do not add an opening or a conclusion, do not repeat anything. Keep the leading and trailing spaces and line breaks as they are." : '' )
			. "\n\nAnswer with STRICT JSON only: an object whose keys are the field ids given to you"
			. ' and whose values are the translated texts. No commentary, no code fence.';

		$user = "Translate every field below.\n\n" . implode( "\n\n", $lines );
		// ROOM FOR THE ANSWER, WITH ROOM TO SPARE.
		//
		// "About half a token per character" is true of English prose and of
		// almost nothing else this shop sends. HTML tokenises badly, French
		// runs a tenth longer than the English it comes from, and the JSON
		// around it escapes every quote and every line break. A 5,800-character
		// piece came back at 6,450 characters against a ceiling of 3,689 — it
		// fit by a hair, and a page of 63 fields did not fit at all: "the answer
		// was cut off before it was finished", and nothing was translated.
		// A character a token, plus the JSON, is wrong the safe way round:
		// unused room costs nothing, a ceiling reached costs the whole call.
		$max = (int) min( 8000, max( 1000, ( mb_strlen( implode( '', $texts ) ) * 1.2 ) + 800 ) );

		DZE_Ai_Usage::unit( 'translate' );
		try {
			$raw = DZE_Marketing_Ai::complete( $system, $user, self::model(), $max, 180 );
		} finally {
			DZE_Ai_Usage::unit();
		}

		$json = trim( (string) preg_replace( '/^```(?:json)?|```$/m', '', $raw ) );
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( __( 'The model did not answer with the expected format.', 'dazont-ecom' ) );
		}
		// THE KEY IS THE FIELD ID, WHATEVER SHAPE IT COMES BACK IN.
		//
		// Each field travels as `### post:content (Article body)` — the id for
		// us, the name for the model. Handed that, a model answers now and
		// again with the WHOLE line as the key, parenthesis and all, and every
		// word it translated was thrown away for a mismatch it could not know
		// it had made: a 1,600-word article came back in four pieces, three
		// matched, one did not, and the field was dropped whole.
		$by = [];
		foreach ( (array) $rows as $k => $v ) {
			if ( ! is_string( $v ) ) {
				continue;
			}
			$by[ (string) $k ] = $v;
			$bare = trim( (string) preg_replace( '/\s*\(.*$/s', '', (string) $k ) );
			if ( '' !== $bare && ! isset( $by[ $bare ] ) ) {
				$by[ $bare ] = $v;
			}
		}
		$out = [];
		foreach ( $texts as $fid => $_ ) {
			$v = isset( $by[ $fid ] ) ? (string) $by[ $fid ] : '';
			// One field asked for, one text back: there is nothing to confuse
			// it with, whatever the key says.
			if ( '' === trim( $v ) && 1 === count( $texts ) && 1 === count( (array) $rows ) ) {
				$v = (string) reset( $by );
			}
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

		// A VARIABLE PRODUCT WITHOUT ITS VARIATIONS IS NOT A PRODUCT.
		// `wp_insert_post()` + the taxonomies gives a post of type `product`
		// carrying the term `variable` and NOTHING under it — no axes, no
		// variations, no price — and WooCommerce renders that as "currently out
		// of stock and unavailable", with no price and no buy button. On this
		// catalogue 163 of the 277 untranslated products are variable, so the
		// module was one press away from publishing a hundred and sixty
		// unbuyable pages in every language.
		//
		// Creating variations ourselves is exactly the second code path this
		// plugin may not have: WooCommerce Multilingual owns that job and does
		// it properly — the attribute slugs, the SKUs, the images, the sync
		// hash. So we ASK IT, and when it is not there we say so rather than
		// leaving a broken product behind.
		self::sync_product( $pid, $new_id, $lang );

		return $new_id;
	}

	/** Is this object a variable product, i.e. one that has variations at all? */
	public static function needs_variations( array $o ): bool {
		if ( 'post' !== ( $o['kind'] ?? '' ) || 'product' !== ( $o['type'] ?? '' ) || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$p = wc_get_product( (int) $o['id'] );
		return $p && is_callable( [ $p, 'is_type' ] ) && $p->is_type( 'variable' );
	}

	/**
	 * Did the translation actually END UP with variations?
	 *
	 * Read from the translation itself rather than from what `sync_product()`
	 * returned: WCML can be asked and still build nothing, and the only answer
	 * worth putting on a screen is the state the shop is in.
	 */
	public static function synced_variations( array $o, int $target ): bool {
		if ( ! $target || ! function_exists( 'get_children' ) ) {
			return false;
		}
		return (bool) get_children( [
			'post_parent' => $target,
			'post_type'   => 'product_variation',
			'post_status' => [ 'publish', 'private' ],
			'numberposts' => 1,
		] );
	}

	/**
	 * ASK WOOCOMMERCE MULTILINGUAL TO MAKE THE TRANSLATION A REAL PRODUCT.
	 *
	 * "Les attributs produits et les variations ne sont toujours pas là sur le
	 * produit traduit." They were not, and the first attempt at this bridge is
	 * why: it called `sync_product_variations()` with an EMPTY fourth argument.
	 * That argument is the ORIGINAL PRODUCT'S ATTRIBUTES — the axes the
	 * variations are built along — so with `[]` there was nothing to build
	 * against and WCML did exactly what it was asked: nothing.
	 *
	 * The order below is WCML's own translation editor's, and it has to be kept:
	 *
	 *   1. `attributes->sync_product_attr()` writes `_product_attributes` onto
	 *      the translation with the terms translated, and RETURNS the original's
	 *      attributes. Nothing else writes that key — WPML holds it on "Don't
	 *      translate" on this shop, so `wpml_sync_all_custom_fields` skips it and
	 *      the translation has no axes at all;
	 *   2. `sync_variations_data->sync_product_variations()` builds the
	 *      variations along THOSE axes.
	 *
	 * `sync_product_data->sync_product_data()` is the whole job in one call and
	 * is used when this WCML exposes it, because one call that WCML maintains is
	 * worth more than two we have to keep in step with it.
	 *
	 * Building any of this ourselves is the second code path this plugin may not
	 * have: WCML owns the attribute slugs, the SKUs, the images and its own sync
	 * hash, and two plugins writing one row is how a catalogue breaks quietly.
	 *
	 * @return string What was actually done: '' when WCML could not be asked.
	 */
	/**
	 * WooCommerce Multilingual, however this installation hands it over.
	 *
	 * `wcml_get_woocommerce_wpml()` IS NOT THERE on every WCML — it is absent
	 * from the one running on this shop — and the whole of `sync_product()`
	 * used to sit behind a `function_exists()` on it. So it returned '' every
	 * single time, on every product, and the careful work below it had never
	 * once run: a translation was born with its text and no variations, and
	 * its page said the product was unavailable.
	 *
	 * The global is what WCML has always set, so it is asked first and the
	 * function is the fallback, not the gate.
	 */
	private static function wcml(): ?object {
		global $woocommerce_wpml;
		if ( is_object( $woocommerce_wpml ) ) {
			return $woocommerce_wpml;
		}
		if ( function_exists( 'wcml_get_woocommerce_wpml' ) ) {
			$got = wcml_get_woocommerce_wpml();
			if ( is_object( $got ) ) {
				return $got;
			}
		}
		return null;
	}

	public static function sync_product( int $pid, int $new_id, string $lang ): string {
		if ( ! $pid || ! $new_id || '' === $lang ) {
			return '';
		}
		$wcml = self::wcml();
		if ( ! is_object( $wcml ) ) {
			return '';
		}
		// THE WHOLE JOB IN ONE CALL, where this WCML has it.
		$whole = $wcml->sync_product_data ?? null;
		if ( is_object( $whole ) && is_callable( [ $whole, 'sync_product_data' ] ) ) {
			$whole->sync_product_data( $pid, $new_id, $lang );
			return 'sync_product_data';
		}
		$did = [];
		// THE AXES FIRST, AND THEY ARE WHAT THE VARIATIONS ARE BUILT ALONG.
		$attrs = [];
		$ab    = $wcml->attributes ?? null;
		if ( is_object( $ab ) && is_callable( [ $ab, 'sync_product_attr' ] ) ) {
			$attrs = (array) $ab->sync_product_attr( $pid, $new_id, $lang );
			$did[] = 'attributes';
		}
		$vb = $wcml->sync_variations_data ?? null;
		if ( is_object( $vb ) && is_callable( [ $vb, 'sync_product_variations' ] ) ) {
			// The original's attributes, NEVER an empty array: that fourth
			// argument is the axes, and without them WCML builds nothing.
			$vb->sync_product_variations( $pid, $new_id, $lang, $attrs ?: self::product_attributes( $pid ) );
			$did[] = 'variations';
		}
		return implode( '+', $did );
	}

	/**
	 * The axes a product is sold along, as WooCommerce stores them.
	 *
	 * Read straight off the original when WCML's own attribute step is not
	 * there to hand them over — the fourth argument of
	 * `sync_product_variations()` must never be empty.
	 *
	 * @return array<string,mixed>
	 */
	public static function product_attributes( int $pid ): array {
		$raw = $pid ? get_post_meta( $pid, '_product_attributes', true ) : '';
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * How many variations a product actually holds. The only answer worth
	 * putting on a screen: WCML can be asked and still build nothing.
	 */
	public static function variation_count( int $pid ): int {
		if ( ! $pid || ! function_exists( 'get_children' ) ) {
			return 0;
		}
		return count( (array) get_children( [
			'post_parent' => $pid,
			'post_type'   => 'product_variation',
			'post_status' => [ 'publish', 'private' ],
			'numberposts' => 200,
		] ) );
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- screen_guard() checked it.
		$all  = ! empty( $_POST['all'] );
		$made = self::produce( $o, $langs, $all );
		wp_send_json_success( [
			'label'   => self::obj_label( $o ),
			'done'    => array_keys( $made['langs'] ),
			// WHAT IT ACTUALLY WROTE, so the screen that asked can show it
			// without a second round trip.
			'texts'   => $made['langs'],
			// A LANGUAGE THAT COST NOTHING SAYS SO. "Nothing was sent" and
			// "something went wrong" must never wear the same words.
			'skipped' => $made['skipped'],
			'errors'  => $made['errors'],
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
			// WHAT WAS WRITTEN, AND WHAT IS STILL WRONG WITH IT. A variable
			// product whose variations WCML has not built shows as unavailable,
			// and the screen that just said "Written ✓" must say so too.
			'warnings'=> (array) ( $done['warnings'] ?? [] ),
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
		DZE_Assets::admin_css();
		wp_enqueue_editor();
		DZE_Assets::admin_js( 'dze-translate-screen', 'admin/js/translate-screen.js' );
		wp_localize_script( 'dze-translate-screen', 'dzeTrScreen', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			// THE WAY TO WHAT CAME BACK, not the name of the tab it is on.
			'reviewUrl' => self::url( [ 'tab' => 'review' ] ),
			'doneIcon'  => self::state_icon( 'done' ),
			'i18n'    => [
				'tickFirst'  => __( 'Tick what you want translated first.', 'dazont-ecom' ),
				// ASKED BEFORE IT IS SPENT. "Translate everything again" pays
				// for fields that had not moved, on purpose, and a button that
				// costs money without saying so is a button pressed by mistake.
				'confirmAll' => __( 'Send every field again, including the ones that have not changed? This costs a full translation. Use it to compare one model, prompt or glossary against another.', 'dazont-ecom' ),
				// The copy button never replaces words already written without asking.
				'overwrite'  => __( 'Replace what is in the box with the original?', 'dazont-ecom' ),
				'langFirst'  => __( 'Tick at least one language.', 'dazont-ecom' ),
				/* translators: 1: objects done, 2: objects in the batch */
				'stepN'      => __( '%1$s of %2$s', 'dazont-ecom' ),
				'sending'    => __( 'Translating…', 'dazont-ecom' ),
				/* translators: %s: number of objects now waiting to be read */
				'sent'       => __( 'Done — %s waiting to be read. Open the "To review" tab.', 'dazont-ecom' ),
				'nothingNew' => __( 'Nothing had moved on any of them: not one word was sent, nothing was spent, and WPML has been told they are up to date.', 'dazont-ecom' ),
				// ONE OBJECT is not "any of them".
				'nothingNewOne' => __( 'Nothing has moved on this one: not one word was sent, nothing was spent, and WPML has been told it is up to date.', 'dazont-ecom' ),
				// A RUN THAT FAILED IS NOT A RUN THAT HAD NOTHING TO DO. With
				// no key or WPML silent, every row failed and the screen said
				// "nothing was spent, they are up to date".
				/* translators: %s: how many rows failed */
				'allFailed'  => __( 'Nothing was translated: %s row(s) failed — the reason is on each row.', 'dazont-ecom' ),
				/* translators: %s: how many rows failed */
				'someFailed' => __( '%s row(s) failed — the reason is on each row.', 'dazont-ecom' ),
				'goReview'   => __( 'Read what came back', 'dazont-ecom' ),
				// ON THE ROW ITSELF, so a finished batch is visible line by
				// line rather than in one sentence at the bottom.
				'rowHeld'    => __( 'waiting to be read', 'dazont-ecom' ),
				'rowNothing' => __( 'nothing moved — closed with WPML', 'dazont-ecom' ),
				'error'      => __( 'Something went wrong.', 'dazont-ecom' ),
				'confirmNo'  => __( 'Throw this translation away? It cannot be recovered; the object stays exactly as it is.', 'dazont-ecom' ),
				'saved'      => __( 'Written ✓', 'dazont-ecom' ),
				'saving'     => __( 'Writing…', 'dazont-ecom' ),
				// THE CHIP AFTER A SAVE says what the lists say: up to date.
				'stateDone'  => self::state_said( 'done' ),
				// THE WORD ON THE ROW once something waits on it.
				'review'     => __( 'Review', 'dazont-ecom' ),
				// THE EDITOR'S OWN THREE PRESSES.
				/* translators: %s: number of fields filled in */
				'filled'     => __( '%s field(s) filled in below — nothing is written until you save.', 'dazont-ecom' ),
				'nothingToSave' => __( 'Every field is empty. There is nothing to write.', 'dazont-ecom' ),
				'dropped'    => __( 'Thrown away. The translation is exactly as it was.', 'dazont-ecom' ),
				/* translators: %s: number of rows ticked */
				'nSelected'  => __( '%s selected', 'dazont-ecom' ),
				// WHAT THE PRESS IS ABOUT TO DO, beside the press: rows times
				// languages, which is the figure nobody had ever multiplied.
				/* translators: 1: rows, 2: languages, 3: jobs */
				'bill'       => __( '%1$s ticked × %2$s language(s) = up to %3$s translations to make — only words that moved are paid for', 'dazont-ecom' ),
				'billNone'   => __( 'Nothing ticked.', 'dazont-ecom' ),
				'stopped'    => __( 'Stopped.', 'dazont-ecom' ),
			],
		] );
	}

	// =========================================================================
	// "Translate with Dazont Ecom" — inside WPML's own Language box
	//
	// "Peut être ajouter directement une option par dessus wpml sur les blocs
	// wpml de traduction, comme une sorte de moding de wpml. Ce serait bien
	// évidemment l'idéal. 'Translate with Dazont Ecom'. Ce serait notre marque
	// de fabrique."
	//
	// The box WPML already prints on an edit screen is where somebody goes to
	// think about languages, so that is where the button belongs — never a
	// meta box of our own beside it. And it OPENS the work rather than running
	// it: the Translations screen, opened on this one object in the language
	// that needs work.
	// A control that spends money the moment it is pressed is the fault this
	// plugin has paid for twice.
	// =========================================================================

	/**
	 * The object whose edit screen we are on, or [].
	 *
	 * Checked against WPML: a type WPML will not link has no business showing
	 * a button that offers to translate it.
	 */
	public static function editing_object(): array {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return [];
		}
		if ( 'term' === (string) $screen->base ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen we are on.
			$tid = isset( $_GET['tag_ID'] ) ? absint( $_GET['tag_ID'] ) : 0;
			return $tid ? self::obj( 'term', $tid, (string) $screen->taxonomy ) : [];
		}
		if ( 'post' !== (string) $screen->base ) {
			return [];
		}
		$pid = (int) get_the_ID();
		return $pid ? self::obj( 'post', $pid, (string) $screen->post_type ) : [];
	}

	/**
	 * The button, planted in WPML's box by a script of eleven lines.
	 *
	 * WPML's markup is WPML's, so this asks for a few containers it is known
	 * by and gives up quietly rather than drawing something in the wrong
	 * place — except that giving up quietly is how a control disappears, so
	 * the last resort is WordPress's own Publish box, which every edit screen
	 * has.
	 */
	public function box_assets( string $hook = '' ): void {
		$o = self::editing_object();
		if ( ! $o || ! self::obj_targets( $o ) ) {
			return; // one language, or nothing WPML would link: no button.
		}
		// AND THE SHOP HAS TO HAVE SAID IT TRANSLATES THIS KIND. A button
		// whose destination is a screen that does not list this object is the
		// same broken control as one with no handler.
		if ( ! isset( self::picked_scope()[ ( $o['kind'] ?? 'post' ) . ':' . ( $o['type'] ?? '' ) ] ) ) {
			return;
		}
		DZE_Assets::admin_js( 'dze-translate-box', 'admin/js/translate-box.js' );
		wp_localize_script( 'dze-translate-box', 'dzeTrBox', [
			// ONE SCREEN FOR EVERY KIND OF OBJECT, and this is the way to it.
			// A product used to get a popup of its own here instead — a second
			// per-object surface, which is how two screens start disagreeing
			// about one object and one of them loses text.
			'url'   => self::editor_url( $o ),
			'label' => __( 'Translate with Dazont Ecom', 'dazont-ecom' ),
			'tip'   => __( 'Opens this one on the Dazont Ecom translation screen — nothing is sent until you press Translate there', 'dazont-ecom' ),
		] );
	}
}
