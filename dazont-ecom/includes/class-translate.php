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
 * own voice because the prompt is ours.
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
	/**
	 * THE ADDRESS IS STILL THE ORIGINAL'S, AND NOBODY HAS CHOSEN IT.
	 *
	 * A translation this module creates is born from the SOURCE title — the
	 * translated one does not exist yet at that moment — so WordPress makes it
	 * an English slug, and a German category was published for ever under an
	 * English address because nothing ever came back to it.
	 *
	 * This mark is set at creation and cleared the moment a translated title
	 * is written, which is the first moment a real slug CAN be made. Its
	 * absence is what says "this address is somebody's own": a slug a human
	 * wrote, or one a page has already been served under, is never rewritten
	 * here — that would 404 an address that is out in the world.
	 */
	private const META_SLUG = '_dze_tr_slug_todo';
	/**
	 * QUI A FAIT CE TRAVAIL — le compte, pas un drapeau.
	 *
	 * « Ne pas oublier d'afficher aussi par qui ça a été fait. Partout, comme
	 * sur les bulk product. »
	 *
	 * `_dze_tr_by` existe depuis longtemps et ne porte qu'un « 1 » : il dit que
	 * ce module a écrit là, jamais qui l'a demandé. Sur une boutique à
	 * plusieurs mains c'est la première question posée, et les requêtes qui
	 * lisent ce drapeau s'en servent comme d'une présence — y écrire un
	 * identifiant les casserait. D'où une clé à côté, qui porte le compte.
	 * Zéro est une réponse : c'est la passe qui tourne toute seule.
	 */
	private const META_WHO = '_dze_tr_who';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// LA FILE DEMANDEE SE VIDE HORS DE L ADMIN, donc son crochet se pose
		// AVANT le garde ci-dessous.
		//
		// WP-Cron ne tourne PAS en admin : declare plus bas, ce crochet
		// n existait tout simplement pas au moment ou le planificateur
		// l appelait, et la file se serait remplie sans jamais se vider — en
		// silence, ce qui est la pire des pannes.
		add_action( self::HOOK_DRAIN, [ __CLASS__, 'drain' ] );
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
		// L'ENVOI EN MASSE DÉPOSE ET REPART. Voir ajax_queue().
		add_action( 'wp_ajax_dze_tr_queue', [ $this, 'ajax_queue' ] );
		// LE PANNEAU DE LA FILE : la faire avancer, ou la vider.
		add_action( 'wp_ajax_dze_tr_runqueue', [ $this, 'ajax_runqueue' ] );
		add_action( 'wp_ajax_dze_tr_emptyqueue', [ $this, 'ajax_emptyqueue' ] );
		add_action( 'wp_ajax_dze_tr_hurry', [ $this, 'ajax_hurry' ] );
		// THE DASHBOARD, WPML'S WAY: a section paged, the words and the cost of
		// what is ticked, where the rows stand now, and one language taken back.
		add_action( 'wp_ajax_dze_tr_items', [ $this, 'ajax_items' ] );
		add_action( 'wp_ajax_dze_tr_words', [ $this, 'ajax_words' ] );
		add_action( 'wp_ajax_dze_tr_status', [ $this, 'ajax_status' ] );
		add_action( 'wp_ajax_dze_tr_cancel', [ $this, 'ajax_cancel' ] );
		// L'ACTION GROUPÉE DE WORDPRESS, sur ses propres listes. Voir ask().
		add_action( 'admin_init', [ $this, 'hook_bulk' ] );
		add_action( 'wp_ajax_dze_tr_decide', [ $this, 'ajax_decide' ] );
		add_action( 'wp_ajax_dze_tr_accept_all', [ $this, 'ajax_accept_all' ] );
		add_action( 'wp_ajax_dze_tr_peek', [ $this, 'ajax_peek' ] );
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
		// THE GLOSSARY IS GONE. A list of terms never to translate is worth
		// exactly what somebody keeps up to date, and nobody does: it stayed
		// empty on every shop it shipped to while the prompt kept pointing at
		// it, so the rule protected nothing. What must come out untouched is
		// named in the instructions now. Dropping the key here retires a list
		// saved before this, on the next save.
		unset( $out['glossary'] );
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
		if ( isset( $in['when'] ) ) {
			$w = sanitize_text_field( (string) $in['when'] );
			$out['when'] = in_array( $w, [ 'new', 'update', 'both' ], true ) ? $w : 'both';
		}
		if ( isset( $in['lane'] ) ) {
			$out['lane'] = 'batch' === sanitize_key( (string) $in['lane'] ) ? 'batch' : 'direct';
		}
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
			. "- Never translate a brand name, a product reference, or a trade term the shop uses untouched in every market — a camouflage pattern, a fitting standard, a material code: reproduce it exactly.";
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

	/**
	 * THE INSTRUCTIONS, WHERE THE TRANSLATIONS ARE READ.
	 *
	 * "Il faut un accès plus facile pour la modification du prompt
	 * d'instructions. La qualité des traductions n'est pas bonne." They were
	 * two menus away, under Settings → Translation, and the moment a shop
	 * notices a bad translation is the moment it is looking at one — not the
	 * moment it is browsing preferences. So they are here, folded, on the
	 * screen where the work is judged, and saving comes straight back to it.
	 *
	 * The sanitizer starts from the stored settings and overwrites only the
	 * keys it is handed, so this short form cannot blank the rest of them.
	 *
	 * @param string $back Where to return after saving. The current screen by
	 *                     default, so the shop lands back on what it was reading.
	 */
	public static function instructions_panel( string $back = '' ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s   = self::get_settings();
		$own = '' !== trim( (string) ( $s['prompt'] ?? '' ) );
		if ( '' === $back ) {
			$back = remove_query_arg( [ 'settings-updated' ], (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- used through esc_url below.
		}
		?>
		<details class="dze-set dze-tr-instr">
			<summary>
				<?php esc_html_e( 'The instructions the translator follows', 'dazont-ecom' ); ?>
				<span class="dze-tr-instrsaid">
					<?php echo esc_html( $own ? __( 'your own wording', 'dazont-ecom' ) : __( 'the wording shipped with the plugin', 'dazont-ecom' ) ); ?>
				</span>
			</summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( 'dze_translate_options' ); ?>
				<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $back ); ?>" />
				<p>
					<label for="dze-tr-instr-prompt"><strong><?php esc_html_e( 'What the translator is told', 'dazont-ecom' ); ?></strong></label><br />
					<textarea id="dze-tr-instr-prompt" name="<?php echo esc_attr( self::OPT ); ?>[prompt]" rows="9" class="large-text code"><?php echo esc_textarea( self::prompt() ); ?></textarea>
				</p>
				<p class="submit" style="margin:0;padding:0;">
					<?php submit_button( __( 'Save the instructions', 'dazont-ecom' ), 'primary', 'submit', false ); ?>
					<span class="description" style="margin-left:10px;">
						<?php
						printf(
							/* translators: %s: the model that does the translating */
							esc_html__( 'Translated by %s. A change applies to the next translation, not to what is already written.', 'dazont-ecom' ),
							esc_html( self::model() )
						);
						?>
					</span>
				</p>
			</form>
		</details>
		<?php
	}

	/**
	 * THE CALLS THAT CARRIED ONE FIELD, newest first.
	 *
	 * "J'aimerais voir les appels à l'IA par bloc." Everything a call was
	 * built from is written down already — `DZE_Ai_Usage::trace()` keeps the
	 * exchange as the model read it — but it was filed nowhere near the words
	 * it produced, so "pourquoi ce titre" had no answer on the screen showing
	 * the title. The batch names each field `### <id> (Label)`, and that is
	 * what picks a call out of the object's log.
	 *
	 * @return array<int,array{t:int,model:string,secs:float,system:string,user:string,got:string}>
	 */
	public static function calls_for( array $o, string $fid, int $keep = 3 ): array {
		if ( 'post' !== (string) ( $o['kind'] ?? '' ) || ! class_exists( 'DZE_Ai_Usage' ) ) {
			return [];
		}
		$out = [];
		foreach ( DZE_Ai_Usage::object_log( (int) ( $o['id'] ?? 0 ) ) as $row ) {
			$sent = (string) ( $row['sent'] ?? '' );
			if ( 'translate' !== (string) ( $row['unit'] ?? '' ) || false === strpos( $sent, '### ' . $fid . ' ' ) ) {
				continue;
			}
			// The exchange is stored as one string, "SYSTEM:…\n\nUSER:…", so a
			// reader can be shown the instructions apart from the text: the
			// instructions are what gets changed, the text is what does not.
			$cut  = strpos( $sent, "\n\nUSER:\n" );
			$out[] = [
				't'      => (int) ( $row['t'] ?? 0 ),
				'model'  => (string) ( $row['model'] ?? '' ),
				'secs'   => (float) ( $row['secs'] ?? 0 ),
				'system' => false === $cut ? '' : trim( substr( $sent, 8, $cut - 8 ) ),
				'user'   => false === $cut ? $sent : trim( substr( $sent, $cut + 8 ) ),
				'got'    => (string) ( $row['got'] ?? '' ),
			];
			if ( count( $out ) >= max( 1, $keep ) ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * THE OBJECT ONE OF OUR OWN ADDRESSES POINTS AT — id and kind, or null.
	 *
	 * Read without the language filter, because the answer is used to ASK for
	 * a translation: resolved through WPML it would already be the wrong one.
	 *
	 * @return array{0:string,1:int}|null [ 'post'|'term', id ]
	 */
	public static function object_at( string $url ): ?array {
		$home = (string) wp_parse_url( (string) home_url(), PHP_URL_HOST );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $home || ( '' !== $host && $host !== $home ) ) {
			return null;
		}
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return null;
		}
		global $wpdb;
		$slug_only = (string) substr( $path, (int) strrpos( '/' . $path, '/' ) );
		if ( '' !== $slug_only ) {
			$pid = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				  WHERE post_name = %s AND post_status IN ('publish','private')
				    AND post_type NOT IN ('attachment','revision')
				  ORDER BY ID ASC LIMIT 1",
				$slug_only
			) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $pid > 0 ) {
				return [ 'post', $pid ];
			}
		}
		if ( function_exists( 'url_to_postid' ) ) {
			$pid = (int) url_to_postid( $url );
			if ( $pid > 0 && 'attachment' !== get_post_type( $pid ) ) {
				return [ 'post', $pid ];
			}
		}
		// A CATEGORY IS NOT A POST. Its slug is the last segment on this shop,
		// and it is read from the tables: get_term_by() answers in the current
		// language, which would hand back a term of the wrong one.
		if ( '' === $slug_only ) {
			return null;
		}
		$tid = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT t.term_id FROM {$wpdb->terms} t
			   JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			  WHERE t.slug = %s AND tt.taxonomy = 'product_cat' LIMIT 1",
			$slug_only
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $tid ? [ 'term', $tid ] : null;
	}

	/** Combien d objets une acceptation repasse en revue. */
	public const RELINK_SWEEP = 40;

	/** Où s'écrit ce qu'on a demandé, en attendant la passe. */
	public const OPT_ASKED = 'dze_translate_asked';

	/** Ce qui a résisté, gardé pour l'écran plutôt que perdu en silence. */
	public const OPT_DRAIN_ERRORS = 'dze_translate_drain_errors';

	/**
	 * UNE DEMANDE, SOUS SA FORME D'AUJOURD'HUI.
	 *
	 * Elle porte ce qui a été choisi AU MOMENT de l'envoi — les langues, écrire
	 * sans relire, remplacer l'existant — parce qu'une demande déposée
	 * aujourd'hui doit être traitée comme on l'a voulue aujourd'hui, même si
	 * l'écran a changé depuis. Une demande d'avant, sans langues, valait pour
	 * toutes : c'est ce qu'elle voulait dire à l'époque.
	 *
	 * ET ELLE DIT OÙ EN EST CHAQUE LANGUE : partie dans un lot chez Anthropic
	 * (`sent`, l'identifiant du lot), revenue et en attente d'être écrite
	 * (`land`), à garder pour la relecture même si l'envoi disait « publier »
	 * (`keep` — annulée alors qu'elle était déjà partie), et combien de fois
	 * elle est revenue vide (`fails`).
	 *
	 * @return array{kind:string,id:int,type:string,langs:string[],accept:int,all:int,at:int,by:int,tries:int,sent:array<string,string>,land:array<string,int>,keep:array<string,int>,fails:array<string,int>}
	 */
	public static function entry( array $raw ): array {
		$langs = [];
		foreach ( (array) ( $raw['langs'] ?? [] ) as $one ) {
			$one = sanitize_key( (string) $one );
			if ( '' !== $one && ! in_array( $one, $langs, true ) ) {
				$langs[] = $one;
			}
		}
		$langs = $langs ? $langs : self::target_codes();
		// Une marque ne vaut que pour une langue encore demandée.
		$per = static function ( $map, bool $text ) use ( $langs ): array {
			$out = [];
			foreach ( (array) $map as $code => $v ) {
				$code = sanitize_key( (string) $code );
				if ( ! in_array( $code, $langs, true ) ) {
					continue;
				}
				if ( $text && '' !== (string) $v ) {
					$out[ $code ] = (string) $v;
				} elseif ( ! $text && (int) $v > 0 ) {
					$out[ $code ] = (int) $v;
				}
			}
			return $out;
		};
		return [
			'kind'   => 'term' === (string) ( $raw['kind'] ?? '' ) ? 'term' : 'post',
			'id'     => (int) ( $raw['id'] ?? 0 ),
			'type'   => (string) ( $raw['type'] ?? '' ),
			'langs'  => $langs,
			'accept' => empty( $raw['accept'] ) ? 0 : 1,
			'all'    => empty( $raw['all'] ) ? 0 : 1,
			// RAPIDE OU ÉCONOMIQUE, choisi à l'envoi. Une demande d'avant ce choix
			// était partie en lot : elle le reste.
			'lane'   => 'direct' === (string) ( $raw['lane'] ?? '' ) ? 'direct' : 'batch',
			'at'     => (int) ( $raw['at'] ?? 0 ),
			'by'     => (int) ( $raw['by'] ?? 0 ),
			'tries'  => (int) ( $raw['tries'] ?? 0 ),
			'sent'   => $per( $raw['sent'] ?? [], true ),
			'land'   => $per( $raw['land'] ?? [], false ),
			'keep'   => $per( $raw['keep'] ?? [], false ),
			'fails'  => $per( $raw['fails'] ?? [], false ),
		];
	}

	/** Les langues vers lesquelles cette boutique traduit. */
	public static function target_codes(): array {
		if ( ! class_exists( 'DZE_Wpml' ) ) {
			return [];
		}
		$src = (string) DZE_Wpml::default_language();
		$out = [];
		foreach ( DZE_Wpml::get_active_languages() as $l ) {
			$code = (string) ( $l['code'] ?? '' );
			if ( '' !== $code && $code !== $src ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/**
	 * DEUX DEMANDES SONT LA MÊME quand elles portent sur le même objet et
	 * qu'elles ont été faites de la même façon. Du russe à relire et du
	 * français à écrire sans relire, sur la même page, sont deux demandes.
	 */
	private static function entry_key( array $e ): string {
		return self::ref( $e ) . '|' . ( empty( $e['accept'] ) ? 0 : 1 ) . ( empty( $e['all'] ) ? 0 : 1 );
	}

	/**
	 * LA FILE NE JETTE JAMAIS RIEN EN SILENCE.
	 *
	 * Elle était coupée à ses deux mille dernières demandes : un envoi de plus
	 * faisait disparaître les plus anciens, sans un mot. Elle en tient
	 * maintenant dix mille, et ce qui ne tient pas est REFUSÉ à l'envoi — et
	 * l'écran le dit — au lieu d'effacer ce qui attendait déjà.
	 */
	public const QUEUE_MAX = 10000;

	/**
	 * CE QU'ON ENVOIE EN TRADUCTION ATTEND ICI, ET LA PASSE LE PREND.
	 *
	 * L'écran ne traduit pas sur place — trente pages dans une requête, c'est
	 * le délai dépassé et rien d'écrit. Il dépose, et rend la main : `drain()`
	 * travaille ensuite en arrière-plan, et chaque langue de chaque ligne tourne
	 * sur l'écran tant qu'elle n'est pas faite.
	 *
	 * DEMANDÉ DEUX FOIS RESTE DEMANDÉ UNE FOIS — mais une langue de plus n'est
	 * pas un doublon : elle rejoint la demande déjà en file au lieu d'être
	 * perdue en silence.
	 *
	 * @param array<int,array{kind:string,id:int,type:string}> $objets
	 * @param string[] $langs   Les langues choisies. Aucune veut dire toutes.
	 * @param bool     $all     Remplacer aussi les traductions déjà à jour.
	 * @param int|null $refused Combien d'objets n'ont pas trouvé de place.
	 * @return int combien d'objets ont été mis en file, ou ont reçu une langue de plus.
	 */
	public static function ask( array $objets, bool $accept = false, array $langs = [], bool $all = false, ?int &$refused = null, string $lane = '' ): int {
		$refused = 0;
		// La voie choisie à l'envoi, ou celle de la boutique quand rien n'est dit.
		$lane = in_array( $lane, [ 'direct', 'batch' ], true ) ? $lane : self::lane();
		// CE QUI PART EST LU AVANT DE PRENDRE LA FILE : WPML répond une question
		// par objet, et la file n'est pas tenue pendant qu'il répond.
		$neufs = [];
		foreach ( $objets as $o ) {
			$e = self::entry( [
				'kind'   => $o['kind'] ?? 'post',
				'id'     => $o['id'] ?? 0,
				'type'   => $o['type'] ?? '',
				'langs'  => $langs,
				'accept' => $accept,
				'all'    => $all,
				'lane'   => $lane,
				'at'     => time(),
				'by'     => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			] );
			if ( $e['id'] < 1 || ! $e['langs'] ) {
				continue;
			}
			// SEULEMENT DEPUIS LA LANGUE SOURCE, ET LE GARDE EST ICI.
			//
			// Un article ESPAGNOL a fini en attente de relecture « à traduire en
			// français » parce qu'il avait été déposé sans passer par un écran.
			// Deux gardes qui se ressemblent ne valent pas un garde à l'endroit
			// où tout passe.
			if ( class_exists( 'DZE_Wpml' ) ) {
				$langue = self::obj_language( $e );
				if ( '' !== $langue && $langue !== DZE_Wpml::default_language() ) {
					continue;
				}
			}
			$neufs[] = $e;
		}
		if ( ! $neufs ) {
			return 0;
		}
		$n    = 0;
		$refu = 0;
		self::with_queue( static function ( array $file ) use ( $neufs, &$n, &$refu ): array {
			$idx = [];
			foreach ( $file as $i => $e ) {
				$idx[ self::entry_key( $e ) ] = $i;
			}
			foreach ( $neufs as $e ) {
				$k = self::entry_key( $e );
				if ( isset( $idx[ $k ] ) ) {
					// DEMANDÉ À NOUVEAU, EN RAPIDE : ce qui n'est pas encore parti
					// part tout de suite. Jamais l'inverse : ralentir ce qu'on a
					// demandé vite n'est le souhait de personne.
					if ( 'direct' === $e['lane'] && 'direct' !== (string) ( $file[ $idx[ $k ] ]['lane'] ?? '' ) ) {
						$file[ $idx[ $k ] ]['lane'] = 'direct';
						$n++;
					}
					$was  = (array) $file[ $idx[ $k ] ]['langs'];
					$plus = array_values( array_diff( $e['langs'], $was ) );
					if ( ! $plus ) {
						continue; // demandé deux fois reste demandé une fois.
					}
					$file[ $idx[ $k ] ]['langs'] = array_values( array_merge( $was, $plus ) );
					$n++;
					continue;
				}
				if ( count( $file ) >= self::QUEUE_MAX ) {
					$refu++;
					continue;
				}
				$idx[ $k ] = count( $file );
				$file[]    = $e;
				$n++;
			}
			return $file;
		} );
		$refused = $refu;
		return $n;
	}

	/**
	 * Ce qui attend, débarrassé de ce qui n'a plus lieu d'être.
	 *
	 * @return array<int,array{kind:string,id:int,type:string,langs:string[],accept:int,all:int,at:int,by:int,tries:int,sent:array<string,string>,land:array<string,int>,keep:array<string,int>,fails:array<string,int>}>
	 */
	public static function asked(): array {
		return self::queue_rows( (array) self::fresh_option( self::OPT_ASKED, [] ) );
	}

	/** La file telle qu'écrite, mise au propre. */
	private static function queue_rows( array $raw ): array {
		// UNE REQUÊTE POUR TOUS LES ARTICLES DE LA FILE, pas une par demande :
		// l'écran relit la file toutes les huit secondes.
		$ids = [];
		foreach ( $raw as $un ) {
			if ( is_array( $un ) && 'term' !== (string) ( $un['kind'] ?? '' ) && (int) ( $un['id'] ?? 0 ) > 0 ) {
				$ids[] = (int) $un['id'];
			}
		}
		if ( $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_values( array_unique( $ids ) ), false, false );
		}
		$out = [];
		foreach ( $raw as $un ) {
			$e = self::entry( (array) $un );
			if ( $e['id'] < 1 || ! $e['langs'] ) {
				continue;
			}
			// UN OBJET DISPARU N'EST PAS DU TRAVAIL. Supprimé depuis, il ferait
			// tourner la passe à vide sur chaque tick.
			if ( 'post' === $e['kind'] && ! get_post( $e['id'] ) ) {
				continue;
			}
			$out[] = $e;
		}
		return $out;
	}

	/** Écrit la file. Une demande qui ne doit plus aucune langue en sort. */
	private static function save_asked( array $file ): void {
		$keep = [];
		foreach ( $file as $e ) {
			if ( (int) ( $e['id'] ?? 0 ) > 0 && ! empty( $e['langs'] ) ) {
				$keep[] = self::entry( (array) $e );
			}
		}
		update_option( self::OPT_ASKED, $keep, false );
		// UNE FILE VIDE N'EST PAS UNE FILE EN PAUSE : « la file est en pause »
		// restait affiché au-dessus d'une file vidée.
		if ( ! $keep ) {
			self::clear_stop();
			delete_option( self::OPT_RUN );
		}
	}

	/**
	 * TOUTE MODIFICATION DE LA FILE EST LUE DANS LA BASE ET ÉCRITE SOUS UN VERROU.
	 *
	 * WordPress garde une option en mémoire pour toute la requête dès qu'il l'a
	 * lue une fois — et le cache d'objets de LiteSpeed aussi. Une passe qui
	 * durait quatre minutes réécrivait donc, à la fin, la file qu'elle avait
	 * lue au début : l'annulation faite entre-temps depuis l'écran était
	 * défaite et la langue traduite et payée quand même, et un envoi fait
	 * pendant la passe disparaissait sans un mot.
	 *
	 * Chaque changement relit maintenant la file dans la base, la modifie, et
	 * l'écrit, sous un verrou que deux requêtes ne peuvent pas tenir ensemble.
	 *
	 * @param callable(array):array $change reçoit la file telle qu'écrite, rend ce qu'il faut écrire.
	 */
	private static function with_queue( callable $change ): void {
		$tenu = self::take( 'queue', 10 );
		try {
			$file = self::queue_rows( (array) self::fresh_option( self::OPT_ASKED, [] ) );
			self::save_asked( (array) $change( $file ) );
		} finally {
			if ( $tenu ) {
				self::give( 'queue' );
			}
		}
	}

	/**
	 * UNE OPTION LUE DANS LA BASE, JAMAIS DANS LE CACHE D'OBJETS.
	 *
	 * Le cache d'objets de LiteSpeed fait de wp_cache_add() un wp_cache_set() :
	 * « ajouter si absent » y écrase ce qui est déjà là. Une page qui relisait
	 * la file pendant qu'un passage l'écrivait y remettait donc l'ancienne —
	 * la base avait raison, et pendant une minute l'écran montrait « en route »
	 * trois catégories déjà traduites et publiées. Vider la clé avant de la
	 * relire ouvrait justement la fenêtre où cela arrive.
	 *
	 * Ce que la file, les lots et leur écran décident est donc lu dans la base,
	 * d'une requête, sans passer par le cache ni le toucher.
	 */
	private static function fresh_option( string $name, $default ) {
		global $wpdb;
		if ( ! $wpdb || ! isset( $wpdb->options ) ) {
			return get_option( $name, $default );
		}
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the object cache is exactly what must not answer.
		return null === $v ? $default : maybe_unserialize( $v );
	}

	/**
	 * UN VERROU QUE LA BASE DONNE À UNE SEULE REQUÊTE — et qu'elle reprend
	 * toute seule quand la requête meurt.
	 *
	 * Le verrou d'avant était un transitoire : lu, puis écrit, avec la file lue
	 * entre les deux — deux passes lancées ensemble le trouvaient libre toutes
	 * les deux et payaient le même travail deux fois. Et un passage tué en
	 * chemin le gardait quinze minutes. `GET_LOCK` de MySQL est pris d'un coup
	 * ou pas du tout, et rendu par MySQL dès que la connexion se ferme.
	 */
	private static function lock_name( string $what ): string {
		global $wpdb;
		$base = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . (string) ( $wpdb->prefix ?? '' );
		return 'dze_tr_' . $what . '_' . substr( md5( $base ), 0, 16 );
	}

	private static function take( string $what, int $wait = 0 ): bool {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', self::lock_name( $what ), max( 0, $wait ) ) );
		if ( null === $got && '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			// UNE BASE QUI REFUSE LES VERROUS NOMMÉS n'arrête pas la file : on
			// retombe sur un transitoire, qui vaut mieux que rien.
			if ( get_transient( 'dze_tr_lock_' . $what ) ) {
				return false;
			}
			set_transient( 'dze_tr_lock_' . $what, 1, 120 );
			return true;
		}
		return '1' === (string) $got;
	}

	private static function give( string $what ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::lock_name( $what ) ) );
		delete_transient( 'dze_tr_lock_' . $what );
	}

	/** Si quelqu'un tient ce verrou en ce moment. */
	private static function held( string $what ): bool {
		global $wpdb;
		$who = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK( %s )', self::lock_name( $what ) ) );
		return null !== $who || (bool) get_transient( 'dze_tr_lock_' . $what );
	}

	/**
	 * CE QUI TOURNE, LANGUE PAR LANGUE, pour que chaque ligne de l'écran le
	 * montre : « une petite roue tourne pendant que la trad est en cours, sur
	 * chaque langue concernée ».
	 *
	 * @return array<string,array<string,array{accept:int,all:int}>> ref => langue => comment elle a été demandée
	 */
	public static function queued_map(): array {
		$out = [];
		foreach ( self::asked() as $e ) {
			$ref = self::ref( $e );
			foreach ( $e['langs'] as $code ) {
				$out[ $ref ][ $code ] = [ 'accept' => $e['accept'], 'all' => $e['all'] ];
			}
		}
		return $out;
	}

	/**
	 * CE QUI EST PARTI ET NE PEUT PLUS ÊTRE REPRIS : les langues envoyées dans
	 * un lot chez Anthropic, et celles revenues qui attendent d'être écrites.
	 *
	 * @return array<string,string[]> ref => langues
	 */
	public static function running(): array {
		$out = [];
		foreach ( self::asked() as $e ) {
			foreach ( $e['langs'] as $code ) {
				if ( isset( $e['sent'][ $code ] ) || isset( $e['land'][ $code ] ) ) {
					$out[ self::ref( $e ) ][] = $code;
				}
			}
		}
		return $out;
	}

	/**
	 * RETIRE DE LA FILE une langue d'un objet, ou l'objet entier.
	 *
	 * « J'ai peur de payer pour rien. » Ce qui attend encore son lot sort de la
	 * file, et rien n'est dépensé. Ce qui est déjà parti chez Anthropic ne peut
	 * plus être repris : cela arrivera, mais dans « À relire », jamais publié
	 * sans relecture — et l'écran le dit. Ce qui est déjà revenu attend dans
	 * « À relire » : il sort de la file et n'est pas publié.
	 *
	 * @return int combien de langues ont quitté la file sans rien coûter.
	 */
	public static function cancel( string $ref, string $lang = '' ): int {
		$lang = sanitize_key( $lang );
		$n    = 0;
		self::with_queue( static function ( array $file ) use ( $ref, $lang, &$n ): array {
			foreach ( $file as $i => $e ) {
				if ( self::ref( $e ) !== $ref ) {
					continue;
				}
				$reste = [];
				foreach ( $e['langs'] as $code ) {
					if ( '' !== $lang && $code !== $lang ) {
						$reste[] = $code;
						continue;
					}
					if ( isset( $e['land'][ $code ] ) ) {
						continue; // revenue : elle attend dans « À relire », non publiée.
					}
					if ( isset( $e['sent'][ $code ] ) ) {
						// PARTIE : elle reviendra, pour être relue.
						$file[ $i ]['keep'][ $code ] = 1;
						$reste[]                     = $code;
						continue;
					}
					$n++;
				}
				$file[ $i ]['langs'] = $reste;
			}
			return $file;
		} );
		return $n;
	}

	/** Retire un objet de la file demandée, toutes langues confondues. */
	public static function unask( array $o ): void {
		self::cancel( self::ref( $o ) );
	}

	/**
	 * CE QUI VIENT D'ÊTRE ÉCRIT OU TRADUIT À LA MAIN n'est plus à la file.
	 *
	 * Envoyée « sans relecture », une langue revenait d'Anthropic et écrivait
	 * par-dessus la traduction que la boutique venait de corriger à la main dans
	 * l'intervalle. Ce qui attendait son lot sort ; ce qui est parti reviendra
	 * pour être relu, jamais publié.
	 *
	 * @param string[] $langs
	 */
	public static function forget_langs( string $ref, array $langs ): void {
		foreach ( $langs as $code ) {
			self::cancel( $ref, (string) $code );
		}
	}

	/**
	 * VIDE LA FILE, et arrête chez Anthropic ce qui n'a pas encore été traduit.
	 *
	 * « Tout annuler » veut dire que plus rien ne sera dépensé. Ce qui attendait
	 * son lot sort ; les lots en cours sont annulés — ce qu'Anthropic n'a pas
	 * encore traduit n'est pas facturé, et ce qu'il avait déjà fini arrive
	 * dans « À relire », jamais publié. Un lot en train de partir est annulé
	 * par le passage qui l'envoie, dès qu'il existe : plus personne ne le veut.
	 *
	 * @return array{removed:int,sent:int}
	 */
	public static function cancel_all(): array {
		$out = [ 'removed' => 0, 'sent' => 0 ];
		self::with_queue( static function ( array $file ) use ( &$out ): array {
			foreach ( $file as $e ) {
				foreach ( $e['langs'] as $code ) {
					if ( isset( $e['sent'][ $code ] ) || isset( $e['land'][ $code ] ) ) {
						$out['sent']++;
					} else {
						$out['removed']++;
					}
				}
			}
			return [];
		} );
		foreach ( self::batches() as $bid => $b ) {
			if ( 'in_progress' !== (string) ( $b['status'] ?? '' ) ) {
				continue;
			}
			try {
				DZE_Marketing_Ai::batch_cancel( (string) $bid );
				$b['status'] = 'canceling';
				self::batch_save( (string) $bid, $b );
			} catch ( \Throwable $e ) {
				// Il finira de lui-même, et arrivera dans « À relire ».
				continue;
			}
		}
		delete_option( self::OPT_DRAIN_ERRORS );
		self::clear_stop();
		return $out;
	}

	/**
	 * « TRADUIRE LE RESTE TOUT DE SUITE ». Envoyé en économique, un envoi peut
	 * attendre qu'Anthropic ait de la place — des heures, parfois. Un clic le
	 * fait passer en rapide : ce qui n'était pas encore parti part tout de
	 * suite, et un lot encore en attente est annulé au passage suivant — rien
	 * n'en est facturé, et ses langues repartent sans qu'aucun essai soit
	 * compté. Ce qu'Anthropic avait déjà traduit revient avec le lot, et n'est
	 * pas redemandé.
	 *
	 * @return int combien de traductions passent en rapide.
	 */
	public static function hurry(): int {
		$n = 0;
		self::with_queue( static function ( array $file ) use ( &$n ): array {
			foreach ( $file as $i => $e ) {
				if ( 'direct' === (string) $e['lane'] ) {
					continue;
				}
				$file[ $i ]['lane'] = 'direct';
				$n += count( array_diff( $e['langs'], array_keys( (array) $e['land'] ) ) );
			}
			return $file;
		} );
		foreach ( self::batches() as $bid => $b ) {
			if ( 'in_progress' === (string) ( $b['status'] ?? '' ) && empty( $b['hurried'] ) ) {
				$b['hurry']  = 1;
				$b['polled'] = 0;
				self::batch_save( (string) $bid, $b );
			}
		}
		delete_option( self::OPT_BACKOFF );
		self::kick_drain();
		return $n;
	}

	// =========================================================================
	// LA FILE PART CHEZ ANTHROPIC, EN LOTS
	//
	// « Fais comme WPML, ça ne coupe pas même avec des gros batch. »
	//
	// La passe d'avant traduisait DANS une requête du site : quatre minutes
	// d'appels au modèle, pendant que la page du tableau de bord attendait la
	// réponse. Chez Hostinger une requête aussi longue est coupée — et la passe
	// mourait en plein travail, sans rien enregistrer de ce qu'elle venait de
	// payer, en laissant un verrou qui bloquait tout un quart d'heure. Deux
	// fois de suite sur les catégories de Kula, au même endroit.
	//
	// WPML ne traduit jamais dans une requête du site : il envoie le travail à
	// son service, qui le fait chez lui, et le site vient chercher le résultat.
	// C'est ce que fait maintenant la file, avec l'API des lots d'Anthropic :
	//
	//   1. ENVOYER — tout ce qui attend part en UN lot : une requête de quelques
	//      secondes, quelle que soit la taille.
	//   2. RELEVER — de temps en temps, une requête d'une seconde demande où en
	//      est le lot. Quand il est fini, ses réponses sont lues d'un coup et
	//      déposées dans « À relire ».
	//   3. PUBLIER — ce qui a été envoyé « sans relecture » est écrit, par
	//      petites tranches.
	//
	// Aucune étape ne tient une requête plus de quelques secondes, et à moitié
	// prix : c'est le tarif des lots.
	//
	// ET UN LOT PAYÉ N'EST JAMAIS PERDU NI RACHETÉ. Il est écrit AVANT de
	// partir (« creating ») : un passage tué entre l'envoi et l'écriture, ou un
	// envoi dont on ne sait pas s'il a abouti, est retrouvé dans la liste des
	// lots d'Anthropic avant que quoi que ce soit reparte. Des réponses qu'on
	// n'arrive pas à lire sont relues plus tard — elles restent vingt-neuf jours
	// chez Anthropic — et jamais redemandées.
	// =========================================================================

	/** L'ancien verrou des passes longues. Relu seulement pour être effacé. */
	public const LOCK_DRAIN = 'dze_translate_draining';

	/** Le crochet que la file fait tourner toute seule. */
	public const HOOK_DRAIN = 'dze_translate_drain';

	/** Les lots partis, et ce que chacun porte. */
	public const OPT_BATCHES = 'dze_translate_batches';

	/** Pourquoi la file s'est arrêtée, quand ce n'est pas la faute d'un texte. */
	public const OPT_STOP = 'dze_translate_stop';

	/** Jusqu'à quand ne rien renvoyer après un envoi refusé. */
	public const OPT_BACKOFF = 'dze_translate_backoff';

	/** Sur l'objet : les mots envoyés, gardés jusqu'au retour de leur lot. */
	public const META_SENT = '_dze_tr_sent';

	/** Combien d'appels un lot porte au plus — assez pour tout un catalogue de catégories. */
	public const BATCH_MAX = 1000;

	/**
	 * Et combien d'octets de texte : la limite d'Anthropic est 256 Mo, mais le
	 * lot est construit en mémoire avant de partir, et la mémoire d'un
	 * hébergement mutualisé est comptée.
	 */
	public const BATCH_BYTES = 8388608;

	/** Toutes les combien de secondes on demande où en est un lot. */
	public const POLL_EVERY = 30;

	/** Combien de secondes un passage s'accorde pour écrire. */
	public const TICK_BUDGET = 25;

	/** Le prix d'un appel en lot, contre le prix d'un appel seul. */
	public const BATCH_RATE = 0.5;

	/**
	 * COMBIEN D'APPELS PARTENT ENSEMBLE, EN MODE IMMÉDIAT — une vague, envoyée
	 * en parallèle, qui revient dans le temps du plus long.
	 */
	public const DIRECT_WAVE = 16;

	/**
	 * ET UN LOT ÉCONOMIQUE EST PETIT. « La possibilité de diviser les lots en
	 * fonction de la quantité de travail à faire ? » Un lot ne revient qu'entier :
	 * mille demandes dans un seul, c'est rien pendant des heures puis tout d'un
	 * coup. Cent par lot, et ils reviennent chacun à leur tour.
	 */
	public const BATCH_CHUNK = 100;

	/** Combien de petits lots un passage envoie au plus. */
	public const BATCHES_PER_TICK = 10;

	/** Ce que les vagues immédiates ont fait depuis que la file s'est remplie. */
	public const OPT_RUN = 'dze_translate_run';

	/**
	 * LE PASSAGE EST DEMANDÉ PAR LA PAGE. Une vague immédiate n'y part jamais :
	 * une requête de page passe par le CDN d'Hostinger, qui la coupe sans un
	 * mot au-delà d'une demi-minute, et les réponses payées se perdraient avec
	 * elle. La page relève, publie et réveille le planificateur ; lui envoie.
	 */
	private static bool $from_page = false;

	/**
	 * IMMÉDIAT, OU EN LOTS À MOITIÉ PRIX.
	 *
	 * « Je n'attendrais en aucun cas 24h pour des traductions. WPML lui-même
	 * n'aurait même pas l'audace de demander autant. » Un lot coûte moitié prix
	 * parce qu'Anthropic le traite quand il a de la place : deux petits sont
	 * revenus en deux minutes, deux gros sont restés vingt minutes à zéro
	 * réponse. Immédiat est donc le défaut ; les lots restent un choix, pour un
	 * très gros envoi qui peut attendre.
	 */
	public static function lane(): string {
		$l = (string) ( self::get_settings()['lane'] ?? '' );
		return 'batch' === $l ? 'batch' : 'direct';
	}

	/** Combien de retours vides avant qu'une langue sorte de la file. */
	public const TRIES = 3;

	/**
	 * Au-delà, un lot qui ne se laisse plus suivre est abandonné : Anthropic
	 * termine tout lot en vingt-quatre heures au plus.
	 */
	public const BATCH_LOST_AFTER = 108000;

	/**
	 * UN PASSAGE : relever les lots, publier, envoyer. Quelques secondes.
	 *
	 * Lancé par le planificateur, ou par la page du tableau de bord tant
	 * qu'elle est ouverte. Un seul à la fois — le suivant rentre aussitôt — et
	 * s'il meurt, MySQL rend son verrou tout seul.
	 *
	 * @param int $budget Secondes pour écrire. La page en demande moins : elle
	 *                    attend la réponse.
	 */
	public static function drain( $budget = self::TICK_BUDGET ): void {
		if ( ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			return;
		}
		if ( ! self::take( 'tick', 0 ) ) {
			return; // un passage tourne : il reprendra rendez-vous lui-même.
		}
		$t0     = microtime( true );
		$budget = is_numeric( $budget ) ? max( 5, (int) $budget ) : self::TICK_BUDGET;
		try {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- l'hébergeur peut refuser.
			}
			// Le verrou des passes d'avant, s'il en reste un, ne veut plus rien dire.
			delete_transient( self::LOCK_DRAIN );
			// LES MARQUES D'ABORD, avant qu'un lot ne soit relevé : un lot lu
			// avant que ses langues ne soient rattachées laissait passer ses
			// échecs et ses réponses partielles.
			self::repair_marks();
			self::collect( $t0, $budget );
			self::publish( $t0, $budget );
			// IMMÉDIAT : des vagues tant que le temps le permet — une vague
			// revient dans le temps de son appel le plus long, et la suivante ne
			// part que s'il reste de quoi l'attendre. Ce qu'elles rapportent sans
			// relecture est publié dans le même passage.
			// ÉCONOMIQUE : plusieurs petits lots par passage — chacun revient seul,
			// et un lot lent ne retient plus tout l'envoi.
			$lots = 0;
			while ( $lots < self::BATCHES_PER_TICK && microtime( true ) - $t0 < $budget && self::dispatch( 'batch' ) ) {
				$lots++;
			}
			if ( self::dispatch( 'direct' ) ) {
				while ( microtime( true ) - $t0 < min( 10, $budget / 2 ) && self::dispatch( 'direct' ) ) {
					continue;
				}
				self::publish( $t0, $budget );
			}
		} finally {
			self::give( 'tick' );
		}
		self::schedule_next();
	}

	/** Le prochain passage : bientôt s'il y a à écrire ou à envoyer, sinon au prochain relevé. */
	private static function schedule_next(): void {
		$file = self::asked();
		$vite = false;
		foreach ( $file as $e ) {
			foreach ( $e['langs'] as $code ) {
				if ( isset( $e['land'][ $code ] ) || ! isset( $e['sent'][ $code ] ) ) {
					$vite = true;
					break 2;
				}
			}
		}
		$ouverts = false;
		foreach ( self::batches() as $b ) {
			if ( in_array( (string) ( $b['status'] ?? '' ), [ 'creating', 'in_progress', 'canceling', 'ended' ], true ) ) {
				$ouverts = true;
				break;
			}
		}
		if ( ! $file && ! $ouverts ) {
			return;
		}
		$attente = (int) self::fresh_option( self::OPT_BACKOFF, 0 ) - time();
		self::kick_drain( $vite && $attente <= 0 ? 5 : max( 60, $attente ) );
	}

	/**
	 * Réveille la file : maintenant, ou dans $delay secondes.
	 *
	 * Un rendez-vous déjà pris plus tôt suffit ; un rendez-vous plus tardif ne
	 * retarde pas celui qu'on demande.
	 */
	public static function kick_drain( int $delay = 0 ): void {
		$quand = time() + max( 0, $delay );
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_get_scheduled_actions' ) ) {
			// UN PASSAGE EN ATTENTE, prévu à temps, suffit — jamais celui qui
			// tourne en ce moment. Vu de l'intérieur d'un passage lancé par le
			// planificateur, « en cours », c'est lui-même : attendre après lui,
			// c'était ne plus jamais reprendre rendez-vous, et la file
			// s'arrêtait dès que la page était fermée.
			$deja = as_get_scheduled_actions( [
				'hook'         => self::HOOK_DRAIN,
				'status'       => 'pending',
				'date'         => $quand + 30,
				'date_compare' => '<=',
				'per_page'     => 1,
			], 'ids' );
			if ( $deja ) {
				return;
			}
			if ( $delay <= 0 && function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::HOOK_DRAIN, [], 'dazont-ecom' );
				return;
			}
			as_schedule_single_action( $quand, self::HOOK_DRAIN, [], 'dazont-ecom' );
			return;
		}
		$next = wp_next_scheduled( self::HOOK_DRAIN );
		if ( $next && $next <= $quand + 30 ) {
			return;
		}
		// WordPress refuses a second event of the same hook within ten
		// minutes of the first: a later booking is taken back before the
		// sooner one is made.
		if ( $next ) {
			wp_unschedule_event( $next, self::HOOK_DRAIN );
		}
		wp_schedule_single_event( max( time() + 5, $quand ), self::HOOK_DRAIN );
	}

	/** Les lots connus, du plus ancien au plus récent. */
	public static function batches(): array {
		$all = self::fresh_option( self::OPT_BATCHES, [] );
		return is_array( $all ) ? $all : [];
	}

	private static function batch_save( string $bid, ?array $b ): void {
		$all = self::batches();
		if ( null === $b ) {
			unset( $all[ $bid ] );
		} else {
			$all[ $bid ] = $b;
		}
		// UN LOT RELEVÉ DEPUIS UNE SEMAINE N'EST PLUS QU'UNE LIGNE D'HISTOIRE.
		foreach ( $all as $k => $one ) {
			// UNE VAGUE IMMÉDIATE N'EST PLUS RIEN une heure après : elle n'a rien à
			// relire chez personne, et elles se comptent par dizaines.
			$vieux = (int) ( $one['landed'] ?? 0 ) < time() - ( empty( $one['direct'] ) ? WEEK_IN_SECONDS : HOUR_IN_SECONDS );
			if ( in_array( (string) ( $one['status'] ?? '' ), [ 'landed', 'lost' ], true ) && $vieux ) {
				unset( $all[ $k ] );
			}
		}
		update_option( self::OPT_BATCHES, $all, false );
	}

	/**
	 * UNE MÉTA LUE DANS LA BASE — la même raison que fresh_option() : ce qui
	 * attend une décision et les mots envoyés décident de ce qui est écrit et
	 * payé, et le cache d'objets de LiteSpeed peut y remettre une copie périmée.
	 */
	private static function raw_meta( array $o, string $key ): string {
		global $wpdb;
		$term = 'term' === ( $o['kind'] ?? 'post' );
		if ( ! $wpdb || ! isset( $wpdb->termmeta, $wpdb->postmeta ) ) {
			return self::meta_read( $o, (int) $o['id'], $key );
		}
		$v = $wpdb->get_var( $wpdb->prepare(
			$term
				? "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1"
				: "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
			(int) $o['id'],
			$key
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see fresh_option().
		return null === $v ? '' : (string) maybe_unserialize( $v );
	}

	/** Les mots envoyés pour cet objet, par lot. */
	private static function sent_read( array $o ): array {
		$raw = self::raw_meta( $o, self::META_SENT );
		$row = '' !== $raw ? json_decode( $raw, true ) : [];
		return is_array( $row ) ? $row : [];
	}

	private static function sent_write( array $o, array $sent ): void {
		if ( ! $sent ) {
			if ( 'term' === $o['kind'] ) {
				delete_term_meta( (int) $o['id'], self::META_SENT );
			} else {
				delete_post_meta( (int) $o['id'], self::META_SENT );
			}
			return;
		}
		self::meta_write( $o, (int) $o['id'], self::META_SENT, (string) wp_json_encode( $sent ) );
	}

	/** Les mots gardés pour un lot, lâchés sur chaque objet qu'il portait. */
	private static function sent_forget( array $b ): void {
		$cle  = (string) ( $b['token'] ?? '' );
		$refs = [];
		foreach ( (array) ( $b['tasks'] ?? [] ) as $task ) {
			$refs[ (string) ( $task['ref'] ?? '' ) ] = true;
		}
		foreach ( array_keys( $refs ) as $ref ) {
			$o = self::from_ref( (string) $ref );
			if ( ! $o ) {
				continue;
			}
			$gardes = self::sent_read( $o );
			if ( isset( $gardes[ $cle ] ) ) {
				unset( $gardes[ $cle ] );
				self::sent_write( $o, $gardes );
			}
		}
	}

	/**
	 * POURQUOI LA FILE S'EST ARRÊTÉE, quand ce n'est la faute d'aucun texte :
	 * le budget du mois atteint, la clé absente, Anthropic qui refuse le lot.
	 *
	 * Rien de ce qui attend n'est compté comme un échec — trois arrêts de ce
	 * genre vidaient la file entière, comme si chaque texte était en cause —
	 * et la file reprend d'elle-même.
	 */
	private static function note_stop( string $why ): void {
		update_option( self::OPT_STOP, [ 'why' => mb_substr( $why, 0, 300 ), 'at' => time() ], false );
	}

	private static function clear_stop(): void {
		delete_option( self::OPT_STOP );
		delete_option( self::OPT_BACKOFF );
	}

	/** @return array{why:string,at:int}|array{} */
	public static function stop_said(): array {
		$s = self::fresh_option( self::OPT_STOP, [] );
		return is_array( $s ) && '' !== (string) ( $s['why'] ?? '' ) ? [ 'why' => (string) $s['why'], 'at' => (int) ( $s['at'] ?? 0 ) ] : [];
	}

	/** Le modèle qui traduit, résolu : celui de ce module, ou celui des réglages généraux. */
	private static function batch_model(): string {
		$m = self::model();
		if ( '' === $m && class_exists( 'DZE_Marketing_Ai' ) && method_exists( 'DZE_Marketing_Ai', 'chosen_model' ) ) {
			$m = (string) DZE_Marketing_Ai::chosen_model();
		}
		return $m;
	}

	/**
	 * CE QUE LES LOTS ENCORE DEHORS COÛTERONT. Un lot n'est compté qu'à son
	 * retour, jusqu'à un jour plus tard : sans cette réserve, deux gros envois
	 * partaient en entier sur un budget presque épuisé.
	 */
	private static function inflight_cost(): float {
		$sum = 0.0;
		foreach ( self::batches() as $b ) {
			if ( in_array( (string) ( $b['status'] ?? '' ), [ 'creating', 'in_progress', 'canceling', 'ended' ], true ) && empty( $b['booked'] ) ) {
				$sum += (float) ( $b['est'] ?? 0 );
			}
		}
		return $sum;
	}

	/** Ce qu'un appel du lot coûtera, estimé large — à ne jamais sous-estimer. */
	private static function ask_cost( string $model, array $ask, float $rate = self::BATCH_RATE ): float {
		if ( ! class_exists( 'DZE_Ai_Usage' ) || ! method_exists( 'DZE_Ai_Usage', 'estimate' ) ) {
			return 0.0;
		}
		$in  = (int) ceil( ( strlen( (string) $ask['system'] ) + strlen( (string) $ask['user'] ) ) / 3.0 );
		$out = (int) ( $ask['max'] ?? 1000 );
		return (float) DZE_Ai_Usage::estimate( $model, $in, $out ) * $rate;
	}

	/**
	 * 1. ENVOYER — tout ce qui attend, en un lot.
	 *
	 * Ce qui part est décidé exactement comme avant : seulement les champs dont
	 * les mots ont bougé, ou tout quand « Écraser » l'a demandé ; rien de ce qui
	 * attend déjà une décision ; rien qui ne soit pas l'original.
	 */
	private static function dispatch( string $lane = 'batch' ): bool {
		// CHAQUE DEMANDE PART PAR LA VOIE CHOISIE À SON ENVOI : rapide, ou
		// économique. Un passage traite l'une puis l'autre, jamais mélangées.
		$direct = 'direct' === $lane;
		// LA PAGE N'ENVOIE JAMAIS DE VAGUE : voir $from_page.
		if ( $direct && self::$from_page ) {
			return false;
		}
		if ( (int) self::fresh_option( self::OPT_BACKOFF, 0 ) > time() ) {
			return false;
		}
		// UN LOT DONT ON NE SAIT PAS S'IL EXISTE bloque tout nouvel envoi de ce
		// qu'il porte tant qu'on ne l'a pas retrouvé : renvoyer, ce serait
		// peut-être payer deux fois.
		$en_suspens = [];
		foreach ( self::batches() as $k => $b ) {
			if ( 'creating' === (string) ( $b['status'] ?? '' ) ) {
				$en_suspens[ (string) ( $b['token'] ?? $k ) ] = true;
			}
		}
		$rien = true;
		foreach ( self::asked() as $e ) {
			if ( (string) $e['lane'] !== $lane ) {
				continue;
			}
			foreach ( $e['langs'] as $code ) {
				if ( ! isset( $e['sent'][ $code ] ) && ! isset( $e['land'][ $code ] ) ) {
					$rien = false;
					break 2;
				}
			}
		}
		if ( $rien ) {
			return false;
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			// LE BUDGET DU MOIS EST ATTEINT : rien ne part, rien n'est compté,
			// et on ne redemande pas toutes les cinq secondes.
			self::note_stop( DZE_Ai_Usage::budget_message() );
			update_option( self::OPT_BACKOFF, time() + HOUR_IN_SECONDS, false );
			return false;
		}
		// CE QUI PART EST MARQUÉ AVANT DE PARTIR, sous le verrou de la file.
		//
		// Le lot se construit et s'envoie en quelques secondes. Une annulation
		// pressée pendant ces secondes-là disait « rien n'a été dépensé » d'une
		// langue qui partait quand même dans le lot. Marquée d'abord, elle se
		// lit « déjà en route » — et l'écran dit la vérité.
		//
		// Une marque laissée par un passage mort en chemin, sans lot en suspens
		// derrière elle, ne peut venir que d'un passage qui n'a rien envoyé :
		// celui-ci tient le verrou. Elle est reprise, et la langue repart.
		$jeton = 'pending-' . time() . '-' . substr( md5( uniqid( '', true ) ), 0, 8 );
		$todo  = [];
		self::with_queue( static function ( array $file ) use ( $jeton, $en_suspens, $lane, &$todo ): array {
			foreach ( $file as $i => $e ) {
				if ( (string) $e['lane'] !== $lane ) {
					continue;
				}
				foreach ( $e['langs'] as $code ) {
					$marque = (string) ( $e['sent'][ $code ] ?? '' );
					if ( isset( $e['land'][ $code ] ) || ! empty( $e['keep'][ $code ] ) ) {
						continue;
					}
					if ( '' !== $marque && ( 0 !== strpos( $marque, 'pending-' ) || isset( $en_suspens[ $marque ] ) ) ) {
						continue;
					}
					$file[ $i ]['sent'][ $code ] = $jeton;
					$todo[]                      = [ $file[ $i ], $code ];
				}
			}
			return $file;
		} );
		if ( ! $todo ) {
			return false;
		}
		$modele = self::batch_model();
		// LE BUDGET, BATCHES ENCORE DEHORS COMPTÉS : ce lot part avec ce qui
		// tient dans ce qui reste, et le reste attend.
		$reste_budget = class_exists( 'DZE_Ai_Usage' ) && method_exists( 'DZE_Ai_Usage', 'budget_left' ) ? DZE_Ai_Usage::budget_left() : null;
		if ( null !== $reste_budget ) {
			$reste_budget -= self::inflight_cost();
		}
		$toutes = self::target_codes();
		$source = class_exists( 'DZE_Wpml' ) ? (string) DZE_Wpml::default_language() : '';
		$asks   = [];
		$map    = [];
		$tasks  = [];
		$mots   = [];
		$objs   = [];
		$partis = [];
		$drop   = [];
		$poids  = 0;
		$est    = 0.0;
		$coupe  = false;
		foreach ( $todo as [ $e, $code ] ) {
			// UNE VAGUE IMMÉDIATE EST COURTE : le reste part à la suivante.
			if ( count( $asks ) >= ( $direct ? self::DIRECT_WAVE : self::BATCH_CHUNK ) || $poids >= self::BATCH_BYTES ) {
				break; // le reste part au lot suivant.
			}
			$ref = self::ref( $e );
			if ( ! array_key_exists( $ref, $objs ) ) {
				$objs[ $ref ] = self::obj( $e['kind'], $e['id'], $e['type'] );
			}
			$o = $objs[ $ref ];
			if ( ! $o || ! in_array( $code, $toutes, true ) ) {
				// PLUS RIEN À TRADUIRE : l'objet a disparu, ou WPML ne propose
				// plus cette langue. La demande sort plutôt que de tourner.
				$drop[] = [ $e, $code, '' ];
				continue;
			}
			// SEULEMENT L'ORIGINAL. Une traduction envoyée en traduction est
			// traduite depuis une traduction, et payée pour un mauvais texte.
			$langue = self::obj_language( $o );
			if ( '' !== $source && '' !== $langue && $langue !== $source ) {
				$drop[] = [ $e, $code, __( 'This is a translation, not an original: only the original is ever sent.', 'dazont-ecom' ) ];
				continue;
			}
			// CE QUI ATTEND DÉJÀ UN OUI OU UN NON N'EST PAS À REFAIRE : la
			// reproduire la paierait deux fois.
			$attend = self::waiting( $o );
			if ( isset( $attend['langs'][ $code ] ) ) {
				$drop[] = [ $e, $code, '' ];
				continue;
			}
			$tout  = ! empty( $e['all'] );
			$texts = $tout ? self::obj_read( $o ) : self::obj_stale( $o, $code );
			if ( ! $texts ) {
				// Rien à envoyer : réglé gratuitement. Seul l'envoi ordinaire le
				// dit réglé — « tout traduire » d'un original vide ne règle rien.
				if ( ! $tout ) {
					self::obj_settle( $o, $code );
				}
				$drop[] = [ $e, $code, '' ];
				continue;
			}
			$plan    = self::plan( $texts, self::labels_for( $o ) );
			$subject = sanitize_key( (string) ( $o['type'] ?? '' ) );
			$tk      = $ref . '|' . $code;
			$ici     = [];
			$ici_est = 0.0;
			$ici_pds = 0;
			foreach ( $plan['jobs'] as $i => $job ) {
				$ask            = self::batch_ask( $job, $code, $plan['names'] );
				$ici[ (int) $i ] = $ask;
				$ici_est       += self::ask_cost( $modele, $ask, $direct ? 1.0 : self::BATCH_RATE );
				$ici_pds       += strlen( $ask['system'] ) + strlen( $ask['user'] ) + 300;
			}
			if ( null !== $reste_budget && $est + $ici_est > $reste_budget ) {
				$coupe = true;
				break; // ce qui ne tient pas dans le budget attend.
			}
			$jobs = [];
			foreach ( $ici as $i => $ask ) {
				// L'ENVOI SIGNE CHAQUE DEMANDE : un lot qui ne serait pas le sien
				// — adopté par erreur — n'écrira jamais rien sur ces objets.
				$cid          = 'd' . substr( md5( $jeton ), 0, 10 ) . '_r' . count( $map );
				$asks[ $cid ] = $ask;
				$map[ $cid ]  = [ $tk, (int) $i, strlen( $ask['system'] ) + strlen( $ask['user'] ) ];
				$jobs[ $i ]   = array_map( 'strval', array_keys( $plan['jobs'][ $i ] ) );
			}
			$est                  += $ici_est;
			$poids                += $ici_pds;
			$tasks[ $tk ]          = [
				'ref'   => $ref,
				'lang'  => $code,
				'jobs'  => $jobs,
				'parts' => $plan['parts'],
				'keep'  => $plan['keep'],
				'unit'  => 'translate' . ( '' !== $subject ? ':' . $subject : '' ),
				'by'    => (int) $e['by'],
			];
			$mots[ $ref ][ $code ] = $texts;
			$partis[]              = [ $e, $code ];
		}
		if ( $drop ) {
			self::drop_now( $drop );
		}
		if ( ! $asks ) {
			self::unmark( $jeton );
			if ( $coupe && class_exists( 'DZE_Ai_Usage' ) ) {
				self::note_stop( DZE_Ai_Usage::budget_message() );
				update_option( self::OPT_BACKOFF, time() + HOUR_IN_SECONDS, false );
			}
			return false;
		}
		// LES MOTS ENVOYÉS RESTENT SUR L'OBJET jusqu'au retour : accepter dans
		// une semaine doit inscrire au registre les mots traduits, pas ceux
		// que quelqu'un aurait changés entre-temps. Écrits AVANT l'envoi.
		foreach ( $mots as $ref => $par_langue ) {
			$gardes           = self::sent_read( $objs[ $ref ] );
			$gardes[ $jeton ] = [ 'at' => time(), 'src' => $par_langue ];
			self::sent_write( $objs[ $ref ], $gardes );
		}
		// LE LOT EST ÉCRIT AVANT DE PARTIR. Un passage tué entre l'envoi et
		// l'écriture laissait un lot payé que la boutique ignorait, et le
		// passage suivant le rachetait.
		$fiche = [
			'token'  => $jeton,
			'at'     => time(),
			'model'  => $modele,
			'status' => 'creating',
			'n'      => count( $asks ),
			'est'    => $est,
			'map'    => $map,
			'tasks'  => $tasks,
		];
		if ( $direct ) {
			return self::direct_wave( $jeton, $fiche, $asks, $modele );
		}
		self::batch_save( $jeton, $fiche );
		try {
			$lot = DZE_Marketing_Ai::batch_create( $asks, $modele );
		} catch ( \Throwable $ex ) {
			$code_http = (int) DZE_Marketing_Ai::$batch_code;
			// Refusé avant même d'appeler (-1 : pas de clé, budget) ou refusé par
			// Anthropic (4xx) : rien n'est parti.
			if ( $code_http < 0 || ( $code_http >= 400 && $code_http < 500 ) ) {
				// REFUSÉ : rien n'est parti, rien n'est compté — les textes n'y
				// sont pour rien.
				self::batch_save( $jeton, null );
				self::sent_forget( $fiche );
				self::unmark( $jeton );
				self::note_stop( $ex->getMessage() );
				update_option( self::OPT_BACKOFF, time() + 10 * MINUTE_IN_SECONDS, false );
				return false;
			}
			// SANS RÉPONSE CLAIRE, personne ne sait si Anthropic l'a fait — et
			// facturé. Le lot reste « en suspens » : un passage suivant le
			// cherche dans la liste des lots avant que rien ne reparte.
			$fiche['unsure'] = $ex->getMessage();
			self::batch_save( $jeton, $fiche );
			self::note_stop( __( 'Anthropic did not say whether it received the last batch. Nothing is sent again until it has been found or ruled out.', 'dazont-ecom' ) );
			return false;
		}
		self::batch_adopt( $jeton, $fiche, $lot );
		self::clear_stop();
		return true;
	}

	/**
	 * UNE VAGUE IMMÉDIATE : envoyée, revenue, déposée — dans le même passage.
	 *
	 * Les mêmes demandes qu'un lot, parties tout de suite et en parallèle ; leurs
	 * réponses arrivent dans la forme d'un résultat de lot et sont lues par
	 * `land()`, exactement comme un lot revenu : mêmes contrôles, même relecture
	 * ou même publication, même compte — au plein tarif.
	 *
	 * @return bool true quand la vague est partie.
	 */
	private static function direct_wave( string $jeton, array $fiche, array $asks, string $modele ): bool {
		// ÉCRITE AVANT DE PARTIR, sous son jeton et à son propre statut : un
		// passage tué pendant la vague la laisse « direct », et le suivant rend ses
		// langues à la file — jamais cherchée parmi les lots d'Anthropic, où elle
		// n'est pas.
		$fiche['id']     = $jeton;
		$fiche['status'] = 'direct';
		$fiche['rate']   = 1.0;
		self::batch_save( $jeton, $fiche );
		try {
			$rows = DZE_Marketing_Ai::messages_now( $asks, $modele, 45 );
		} catch ( \Throwable $ex ) {
			// REFUSÉ AVANT TOUT APPEL — pas de clé, budget atteint : rien n'est
			// parti, rien n'est compté, et les textes n'y sont pour rien.
			self::batch_save( $jeton, null );
			self::sent_forget( $fiche );
			self::unmark( $jeton );
			self::note_stop( $ex->getMessage() );
			update_option( self::OPT_BACKOFF, time() + 10 * MINUTE_IN_SECONDS, false );
			return false;
		}
		self::clear_stop();
		self::land( $jeton, $fiche, $rows );
		return true;
	}

	/**
	 * LES MARQUES « EN ROUTE », VÉRIFIÉES AVANT TOUT ENVOI.
	 *
	 * Une marque ne vaut que si une fiche de lot la porte. Un passage tué
	 * pendant qu'il construisait son lot laissait ses langues « chez Anthropic »
	 * pour toujours ; un passage tué au milieu d'une adoption laissait des
	 * marques qu'on aurait reprises et rachetées pendant que le vrai lot
	 * revenait. Chaque marque est donc relue contre les fiches :
	 *   - un lot en suspens (« creating ») : elle attend qu'il soit retrouvé ;
	 *   - un lot connu sous son nom : l'adoption interrompue est terminée ;
	 *   - un lot déjà relevé : sa traduction attend en relecture, ou part être
	 *     publiée si c'était demandé ;
	 *   - rien derrière elle : rien n'est parti, la langue reprend sa place —
	 *     ou sort, si elle a été annulée entre-temps.
	 */
	private static function repair_marks(): void {
		$par_jeton = [];
		$par_id    = [];
		foreach ( self::batches() as $b ) {
			$tok = (string) ( $b['token'] ?? '' );
			$id  = (string) ( $b['id'] ?? '' );
			$st  = (string) ( $b['status'] ?? '' );
			if ( '' !== $tok && ( ! isset( $par_jeton[ $tok ] ) || '' !== $id ) ) {
				$par_jeton[ $tok ] = [ 'id' => $id, 'status' => $st ];
			}
			if ( '' !== $id ) {
				$par_id[ $id ] = $st;
			}
		}
		self::with_queue( static function ( array $file ) use ( $par_jeton, $par_id ): array {
			foreach ( $file as $i => $e ) {
				$reste = [];
				foreach ( $e['langs'] as $code ) {
					$m = (string) ( $e['sent'][ $code ] ?? '' );
					if ( '' === $m ) {
						$reste[] = $code;
						continue;
					}
					if ( 0 === strpos( $m, 'pending-' ) ) {
						$r = $par_jeton[ $m ] ?? null;
						if ( $r && '' === $r['id'] ) {
							$reste[] = $code; // en suspens : on le cherche.
							continue;
						}
						if ( $r ) {
							$m                          = $r['id'];
							$file[ $i ]['sent'][ $code ] = $m;
						} else {
							unset( $file[ $i ]['sent'][ $code ] );
							if ( empty( $e['keep'][ $code ] ) ) {
								$reste[] = $code;
							}
							continue;
						}
					}
					$st = $par_id[ $m ] ?? null;
					// UNE VAGUE IMMÉDIATE ENCORE « EN COURS » au début d'un passage est une
					// vague morte : une seule tourne à la fois, sous le verrou, et elle est
					// relevée dans le passage qui l'a envoyée. Ses langues reprennent leur
					// place.
					if ( null === $st || 'lost' === $st || 'direct' === $st ) {
						unset( $file[ $i ]['sent'][ $code ] );
						if ( empty( $e['keep'][ $code ] ) ) {
							$reste[] = $code;
						}
						continue;
					}
					if ( 'landed' === $st ) {
						unset( $file[ $i ]['sent'][ $code ] );
						if ( ! empty( $e['keep'][ $code ] ) ) {
							continue;
						}
						// RELEVÉ SANS ELLE : ce qui attend vraiment en relecture part
						// être publié si c'était demandé ; sinon elle reprend sa place
						// — jamais rayée en silence.
						$o   = self::obj( $e['kind'], $e['id'], $e['type'] );
						$att = $o ? self::waiting( $o ) : [];
						if ( isset( $att['langs'][ $code ] ) ) {
							if ( ! empty( $e['accept'] ) ) {
								$file[ $i ]['land'][ $code ] = 1;
								$reste[]                     = $code;
							}
							continue;
						}
						$reste[] = $code;
						continue;
					}
					$reste[] = $code;
				}
				$file[ $i ]['langs'] = $reste;
			}
			return $file;
		} );
		// ET LA FICHE D'UNE VAGUE MORTE S'EFFACE, avec les mots qu'elle gardait :
		// ses langues viennent de reprendre leur place.
		foreach ( self::batches() as $k => $fiche ) {
			if ( 'direct' === (string) ( $fiche['status'] ?? '' ) ) {
				self::sent_forget( $fiche );
				self::batch_save( (string) $k, null );
			}
		}
	}

	/**
	 * UN LOT ENVOYÉ DEVIENT SON LOT : la fiche passe sous son identifiant, les
	 * langues marquées « en route » portent son nom, ce qui n'y est pas entré
	 * reprend sa place — et s'il n'est plus voulu par personne, il est arrêté
	 * chez Anthropic à l'instant : ce qui n'est pas encore traduit n'est pas
	 * facturé.
	 */
	private static function batch_adopt( string $jeton, array $fiche, array $lot ): void {
		$bid = (string) ( $lot['id'] ?? '' );
		if ( '' === $bid ) {
			return;
		}
		$etat            = (string) ( $lot['processing_status'] ?? '' );
		$fiche['tried']  = array_values( array_unique( array_merge( (array) ( $fiche['tried'] ?? [] ), [ $bid ] ) ) );
		$fiche['id']     = $bid;
		$fiche['status'] = in_array( $etat, [ 'ended', 'canceling' ], true ) ? $etat : 'in_progress';
		$fiche['polled'] = time();
		unset( $fiche['unsure'] );
		// SOUS SON NOM D'ABORD, puis les marques, puis la fiche d'attente
		// effacée : tué entre deux, le passage suivant retrouve le même lot par
		// son jeton et termine la même chose — il ne le rachète jamais.
		self::batch_save( $bid, $fiche );
		$inclus = [];
		foreach ( (array) $fiche['tasks'] as $tk => $task ) {
			$inclus[ (string) $tk ] = true;
		}
		$voulus = 0;
		self::with_queue( static function ( array $file ) use ( $jeton, $bid, $inclus, &$voulus ): array {
			foreach ( $file as $i => $e ) {
				foreach ( (array) $e['sent'] as $code => $v ) {
					if ( $v !== $jeton ) {
						continue;
					}
					if ( isset( $inclus[ self::ref( $e ) . '|' . $code ] ) ) {
						$file[ $i ]['sent'][ $code ] = $bid;
						if ( empty( $e['keep'][ $code ] ) ) {
							$voulus++;
						}
						continue;
					}
					unset( $file[ $i ]['sent'][ $code ] );
					// ANNULÉE PENDANT L'ENVOI ET RESTÉE HORS DU LOT : elle sort.
					if ( ! empty( $e['keep'][ $code ] ) ) {
						$file[ $i ]['langs'] = array_values( array_diff( $file[ $i ]['langs'], [ $code ] ) );
					}
				}
			}
			return $file;
		} );
		self::batch_save( $jeton, null );
		if ( 0 === $voulus && 'in_progress' === $fiche['status'] ) {
			try {
				DZE_Marketing_Ai::batch_cancel( $bid );
				$fiche['status'] = 'canceling';
				self::batch_save( $bid, $fiche );
			} catch ( \Throwable $ex ) {
				// Il finira, et ce qu'il porte arrivera dans « À relire ».
				unset( $ex );
			}
		}
	}

	/**
	 * UN LOT EN SUSPENS, RETROUVÉ OU ÉCARTÉ.
	 *
	 * Écrit avant de partir, il a pu partir sans que ce site l'apprenne : un
	 * envoi resté sans réponse, un passage tué juste après. Il est cherché dans
	 * la liste des lots d'Anthropic — même taille, créé au même moment. Trouvé,
	 * il est suivi comme les autres ; absent de la liste, il n'est jamais
	 * parti, et ses langues reprennent leur place.
	 */
	private static function resolve_creating( string $jeton, array $fiche ): void {
		// UNE ADOPTION COUPÉE EN CHEMIN se termine : le lot est déjà écrit sous
		// son nom, et `repair_marks()` rattache ses langues.
		$connus = [];
		foreach ( self::batches() as $k => $b ) {
			$id = (string) ( $b['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$connus[ $id ] = true;
			if ( (string) ( $b['token'] ?? '' ) === $jeton ) {
				self::batch_save( $jeton, null );
				return;
			}
		}
		if ( time() - (int) ( $fiche['at'] ?? 0 ) < 60 ) {
			return; // le temps qu'il apparaisse dans la liste.
		}
		try {
			$liste = DZE_Marketing_Ai::batch_list( 50 );
		} catch ( \Throwable $ex ) {
			if ( time() - (int) ( $fiche['at'] ?? 0 ) < DAY_IN_SECONDS ) {
				return; // on redemandera.
			}
			$liste = [];
		}
		// UN SEUL CANDIDAT, jamais un lot déjà suivi, créé dans la fenêtre de
		// l'envoi — de juste avant à la fin du délai de la requête. Deux lots de
		// même taille partis au même moment ne se départagent pas : on attend.
		$cands = [];
		foreach ( $liste as $lot ) {
			$id    = (string) ( $lot['id'] ?? '' );
			$quand = strtotime( (string) ( $lot['created_at'] ?? '' ) );
			$total = array_sum( array_map( 'intval', (array) ( $lot['request_counts'] ?? [] ) ) );
			if ( '' === $id || isset( $connus[ $id ] ) || ! $quand || $total !== (int) $fiche['n'] ) {
				continue;
			}
			if ( $quand < (int) $fiche['at'] - 120 || $quand > (int) $fiche['at'] + 180 ) {
				continue;
			}
			$cands[] = $lot;
		}
		// PLUSIEURS POSSIBLES : le plus proche de l'envoi est essayé tout de
		// suite. Chaque demande porte la signature de son envoi : si ce n'était
		// pas lui, on le saura à son retour — rien ne sera écrit ni compté — et
		// le suivant sera essayé. Attendre un jour pour finalement racheter,
		// c'était payer deux fois.
		$deja = array_map( 'strval', (array) ( $fiche['tried'] ?? [] ) );
		$cands = array_values( array_filter( $cands, static fn( $l ) => ! in_array( (string) ( $l['id'] ?? '' ), $deja, true ) ) );
		usort( $cands, static fn( $x, $y ) => abs( (int) strtotime( (string) ( $x['created_at'] ?? '' ) ) - (int) $fiche['at'] ) <=> abs( (int) strtotime( (string) ( $y['created_at'] ?? '' ) ) - (int) $fiche['at'] ) );
		if ( $cands ) {
			$fiche['resolved'] = 1;
			self::batch_adopt( $jeton, $fiche, $cands[0] );
			self::clear_stop();
			return;
		}
		// JAMAIS PARTI : rien n'est facturé, et tout reprend sa place.
		self::batch_save( $jeton, null );
		self::sent_forget( $fiche );
		self::unmark( $jeton );
		self::clear_stop();
	}

	/** Ce qui était marqué « en route » par ce passage et n'est pas parti reprend sa place. */
	private static function unmark( string $jeton ): void {
		self::with_queue( static function ( array $file ) use ( $jeton ): array {
			foreach ( $file as $i => $e ) {
				foreach ( (array) $e['sent'] as $code => $v ) {
					if ( $v !== $jeton ) {
						continue;
					}
					unset( $file[ $i ]['sent'][ $code ] );
					// ANNULÉE PENDANT L'ENVOI, ET JAMAIS PARTIE : elle sort.
					if ( ! empty( $e['keep'][ $code ] ) ) {
						$file[ $i ]['langs'] = array_values( array_diff( $file[ $i ]['langs'], [ $code ] ) );
					}
				}
			}
			return $file;
		} );
	}

	/**
	 * CE QUI SORT DE LA FILE SANS PARTIR — rien à envoyer, ou plus lieu de
	 * l'être — et pourquoi, quand il y a une raison à dire.
	 *
	 * @param array<int,array{0:array,1:string,2:string}> $drop
	 */
	private static function drop_now( array $drop ): void {
		self::with_queue( static function ( array $file ) use ( $drop ): array {
			$idx = [];
			foreach ( $file as $i => $e ) {
				$idx[ self::entry_key( $e ) ] = $i;
			}
			foreach ( $drop as [ $e, $code ] ) {
				$i = $idx[ self::entry_key( $e ) ] ?? null;
				if ( null !== $i ) {
					$file[ $i ]['langs'] = array_values( array_diff( $file[ $i ]['langs'], [ $code ] ) );
				}
			}
			return $file;
		} );
		foreach ( $drop as [ $e, $code, $why ] ) {
			if ( '' !== $why ) {
				self::note_drain_error( $e, $code, $why );
			}
		}
	}

	/**
	 * 2. RELEVER — où en sont les lots, et lire ceux qui sont finis.
	 */
	private static function collect( float $t0, int $budget ): void {
		foreach ( self::batches() as $bid => $b ) {
			$bid    = (string) $bid;
			$statut = (string) ( $b['status'] ?? '' );
			if ( 'creating' === $statut ) {
				self::resolve_creating( (string) ( $b['token'] ?? $bid ), $b );
				continue;
			}
			// « IMMÉDIAT » : ce qui attend encore dans un lot est repris et part tout
			// de suite. Ce qu'Anthropic a déjà traduit revient avec le lot et n'est
			// pas redemandé ; le reste n'est pas facturé, et reprend sa place sans
			// qu'aucun essai soit compté (voir land()).
			if ( 'in_progress' === $statut && empty( $b['hurried'] ) && ! empty( $b['hurry'] ) ) {
				try {
					DZE_Marketing_Ai::batch_cancel( $bid );
					$b['hurried'] = 1;
					$b['status']  = 'canceling';
					$b['polled']  = 0;
					self::batch_save( $bid, $b );
					$statut = 'canceling';
				} catch ( \Throwable $ex ) {
					unset( $ex ); // redemandé au passage suivant.
				}
			}
			if ( in_array( $statut, [ 'in_progress', 'canceling' ], true ) ) {
				if ( time() - (int) ( $b['polled'] ?? 0 ) < self::POLL_EVERY ) {
					continue;
				}
				try {
					$etat = DZE_Marketing_Ai::batch_get( $bid );
				} catch ( \Throwable $ex ) {
					// UN LOT QUI NE SE LAISSE PLUS SUIVRE — introuvable, ou bien
					// plus vieux que les vingt-quatre heures qu'Anthropic se donne —
					// est abandonné : ses langues reprennent leur place.
					$b['poll_fail']    = (int) ( $b['poll_fail'] ?? 0 ) + 1;
					$b['poll_fail_at'] = (int) ( $b['poll_fail_at'] ?? 0 ) ?: time();
					$vieux             = time() - (int) ( $b['at'] ?? 0 ) > self::BATCH_LOST_AFTER
						&& $b['poll_fail'] >= 5
						&& time() - (int) $b['poll_fail_at'] > 3 * HOUR_IN_SECONDS;
					if ( 404 === (int) DZE_Marketing_Ai::$batch_code || $vieux ) {
						self::lose( $bid, $b, $ex->getMessage() );
						continue;
					}
					$b['polled'] = time();
					$b['err']    = $ex->getMessage();
					self::batch_save( $bid, $b );
					continue;
				}
				unset( $b['poll_fail'], $b['poll_fail_at'] );
				$b['polled'] = time();
				$b['counts'] = (array) ( $etat['request_counts'] ?? [] );
				$b['err']    = '';
				if ( 'ended' === (string) ( $etat['processing_status'] ?? '' ) ) {
					$b['status'] = 'ended';
				}
				self::batch_save( $bid, $b );
			}
			if ( 'ended' === (string) ( $b['status'] ?? '' ) ) {
				if ( (int) ( $b['read_next'] ?? 0 ) > time() ) {
					continue;
				}
				if ( microtime( true ) - $t0 > $budget ) {
					break; // le passage suivant le lira.
				}
				self::land( $bid, $b );
			}
		}
	}

	/**
	 * UN LOT ABANDONNÉ : ce qu'il portait reprend sa place, un essai compté.
	 */
	private static function lose( string $bid, array $b, string $why ): void {
		$notes = [];
		self::with_queue( static function ( array $file ) use ( $bid, &$notes ): array {
			foreach ( $file as $i => $e ) {
				$reste = [];
				foreach ( $e['langs'] as $code ) {
					if ( ( $e['sent'][ $code ] ?? '' ) !== $bid ) {
						$reste[] = $code;
						continue;
					}
					unset( $file[ $i ]['sent'][ $code ] );
					if ( ! empty( $e['keep'][ $code ] ) ) {
						continue;
					}
					$n = (int) ( $e['fails'][ $code ] ?? 0 ) + 1;
					if ( $n >= self::TRIES ) {
						$notes[] = [ $e, $code ];
						continue;
					}
					$file[ $i ]['fails'][ $code ] = $n;
					$reste[]                      = $code;
				}
				$file[ $i ]['langs'] = $reste;
			}
			return $file;
		} );
		foreach ( $notes as [ $e, $code ] ) {
			self::note_drain_error( $e, $code, $why );
		}
		self::sent_forget( $b );
		self::batch_save( $bid, [ 'id' => $bid, 'at' => (int) ( $b['at'] ?? 0 ), 'status' => 'lost', 'landed' => time(), 'n' => (int) ( $b['n'] ?? 0 ), 'err' => mb_substr( $why, 0, 200 ) ] );
	}

	/**
	 * LES RÉPONSES D'UN LOT FINI : lues, recollées, déposées.
	 *
	 * Chaque réponse est lue comme une réponse seule — le même format, les
	 * mêmes pièges, les mêmes morceaux recollés dans l'ordre. Ce qui revient
	 * attend dans « À relire », ou part être écrit s'il a été envoyé sans
	 * relecture. Ce qui revient vide retourne dans la file, et sort au bout de
	 * trois fois en disant pourquoi.
	 *
	 * DANS UN ORDRE QUI SURVIT À UN PASSAGE TUÉ : l'argent est compté une fois
	 * et la fiche le dit aussitôt ; les mots envoyés ne sont lâchés qu'une fois
	 * le lot noté « relevé ». Relu après une coupure, il n'est ni compté deux
	 * fois ni inscrit contre les mauvais mots.
	 */
	private static function land( string $bid, array $b, ?iterable $rows = null ): void {
		$reponses = [];
		$encore   = [];
		$ralentir = false;
		$echecs   = [];
		$usage    = [];
		$types    = [];
		$pourquoi = '';
		$lignes   = 0;
		$siennes  = 0;
		try {
			foreach ( ( null !== $rows ? $rows : DZE_Marketing_Ai::batch_results( $bid ) ) as $row ) {
				$lignes++;
				$cid = (string) ( $row['custom_id'] ?? '' );
				if ( ! isset( $b['map'][ $cid ] ) ) {
					continue;
				}
				$siennes++;
				[ $tk, $i, $taille ] = array_pad( (array) $b['map'][ $cid ], 3, 0 );
				$task = $b['tasks'][ $tk ] ?? null;
				if ( ! is_array( $task ) ) {
					continue;
				}
				$res  = (array) ( $row['result'] ?? [] );
				$type = (string) ( $res['type'] ?? '' );
				$types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
				// PAS LA FAUTE DU TEXTE : trop de demandes, un service surchargé, un
				// transport tombé — ou un lot repris pour partir tout de suite. La
				// langue reprend sa place, et aucun essai n'est compté contre elle.
				// ANNULÉE OU EXPIRÉE, ce n'est pas non plus la faute du texte : elle
				// repart si sa demande est encore dans la file.
				if ( in_array( $type, [ 'retry', 'canceled', 'expired' ], true ) ) {
					$encore[ $tk ] = true;
					$ralentir       = $ralentir || 'retry' === $type;
					continue;
				}
				if ( 'succeeded' !== $type ) {
					$echecs[ $tk ][ $i ] = self::batch_why( $type, $res );
					$pourquoi            = $echecs[ $tk ][ $i ];
					continue;
				}
				$msg  = (array) ( $res['message'] ?? [] );
				$text = '';
				foreach ( (array) ( $msg['content'] ?? [] ) as $bloc ) {
					if ( 'text' === (string) ( $bloc['type'] ?? '' ) ) {
						$text .= (string) ( $bloc['text'] ?? '' );
					}
				}
				$usage[] = [
					'u'   => (string) $task['unit'],
					'in'  => (int) ( $msg['usage']['input_tokens'] ?? 0 ),
					'out' => (int) ( $msg['usage']['output_tokens'] ?? 0 ),
					'ci'  => (int) $taille,
					'co'  => strlen( $text ),
					// LE MODÈLE QUI A RÉPONDU, et son prix : jamais celui d'un défaut.
					'm'   => (string) ( $msg['model'] ?? ( $b['model'] ?? '' ) ),
				];
				if ( 'max_tokens' === (string) ( $msg['stop_reason'] ?? '' ) ) {
					$echecs[ $tk ][ $i ] = __( 'The answer was cut off before it was finished — what was asked for is too long. Nothing was changed.', 'dazont-ecom' );
					continue;
				}
				try {
					$reponses[ $tk ][ $i ] = self::batch_read( $text, array_fill_keys( (array) ( $task['jobs'][ $i ] ?? [] ), '' ) );
				} catch ( \Throwable $ex ) {
					$echecs[ $tk ][ $i ] = $ex->getMessage();
				}
			}
		} catch ( \Throwable $ex ) {
			// LES RÉPONSES N'ONT PAS PU ÊTRE LUES. Elles sont payées, et restent
			// vingt-neuf jours chez Anthropic : on les relit plus tard, de plus
			// en plus espacé — jamais on ne les redemande.
			$b['read_fail'] = (int) ( $b['read_fail'] ?? 0 ) + 1;
			$b['read_next'] = time() + (int) min( 6 * HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** min( 10, (int) $b['read_fail'] ) ) );
			$b['err']       = $ex->getMessage();
			if ( time() - (int) ( $b['at'] ?? 0 ) > 28 * DAY_IN_SECONDS ) {
				self::lose( $bid, $b, $ex->getMessage() );
				return;
			}
			self::batch_save( $bid, $b );
			self::note_stop( sprintf(
				/* translators: %s: why the answers could not be read */
				__( 'The translations are done at Anthropic but could not be read yet (%s). They are kept there and read again later; nothing is ordered twice.', 'dazont-ecom' ),
				$ex->getMessage()
			) );
			return;
		}
		// PAS UNE RÉPONSE DE CET ENVOI : ce lot n'est pas le sien — adopté par
		// erreur. Rien n'est écrit, rien n'est compté, et ce qu'il devait porter
		// reprend sa place sans qu'aucun essai soit compté contre personne.
		if ( $lignes > 0 && 0 === $siennes && ! empty( $b['resolved'] ) && '' !== (string) ( $b['token'] ?? '' ) ) {
			// RETROUVÉ PAR ERREUR : ce n'était pas lui. La fiche redevient « en
			// suspens » sous son jeton, ce lot écarté, et le suivant sera essayé.
			$jeton = (string) $b['token'];
			$retour = $b;
			unset( $retour['id'], $retour['polled'], $retour['counts'], $retour['booked'], $retour['read_fail'], $retour['read_next'], $retour['poll_fail'], $retour['poll_fail_at'] );
			$retour['status'] = 'creating';
			self::batch_save( $jeton, $retour );
			self::with_queue( static function ( array $file ) use ( $bid, $jeton ): array {
				foreach ( $file as $i => $e ) {
					foreach ( (array) $e['sent'] as $code => $v ) {
						if ( $v === $bid ) {
							$file[ $i ]['sent'][ $code ] = $jeton;
						}
					}
				}
				return $file;
			} );
			self::batch_save( $bid, [ 'id' => $bid, 'at' => (int) ( $b['at'] ?? 0 ), 'status' => 'lost', 'landed' => time(), 'n' => (int) ( $b['n'] ?? 0 ), 'err' => 'not this site\'s batch' ] );
			return;
		}
		if ( $lignes > 0 && 0 === $siennes ) {
			self::with_queue( static function ( array $file ) use ( $bid ): array {
				foreach ( $file as $i => $e ) {
					foreach ( (array) $e['sent'] as $code => $v ) {
						if ( $v === $bid ) {
							unset( $file[ $i ]['sent'][ $code ] );
							if ( ! empty( $e['keep'][ $code ] ) ) {
								$file[ $i ]['langs'] = array_values( array_diff( $file[ $i ]['langs'], [ $code ] ) );
							}
						}
					}
				}
				return $file;
			} );
			self::sent_forget( $b );
			self::batch_save( $bid, [ 'id' => $bid, 'at' => (int) ( $b['at'] ?? 0 ), 'status' => 'lost', 'landed' => time(), 'n' => (int) ( $b['n'] ?? 0 ), 'err' => 'not this site\'s batch' ] );
			return;
		}
		// L'ARGENT, COMPTÉ UNE FOIS, AU PRIX DU LOT — et la fiche le dit aussitôt.
		if ( empty( $b['booked'] ) ) {
			if ( $usage && class_exists( 'DZE_Ai_Usage' ) && method_exists( 'DZE_Ai_Usage', 'record_many' ) ) {
				// AU PRIX DE LA VOIE : moitié pour un lot, plein pour une vague immédiate.
				DZE_Ai_Usage::record_many( 'anthropic', (string) ( $b['model'] ?? '' ), $usage, (float) ( $b['rate'] ?? self::BATCH_RATE ) );
			}
			$b['booked'] = 1;
			self::batch_save( $bid, $b );
		}
		// UN LOT QUI N'A RIEN RENDU DU TOUT, pour une seule et même raison, ne dit
		// rien de ses textes : Anthropic n'en a traduit aucun, et rien n'est
		// facturé. La file s'arrête, le dit, et renvoie plus tard sans rien
		// compter contre personne.
		$global  = ! $reponses && ! isset( $types['succeeded'] ) && ( $types['errored'] ?? 0 ) > 0;
		$faits   = [];
		$partiel = [];
		$rates   = [];
		foreach ( (array) ( $b['tasks'] ?? [] ) as $tk => $task ) {
			$champs  = self::assemble( (array) $task, (array) ( $reponses[ $tk ] ?? [] ) );
			$raisons = array_values( array_filter( (array) ( $echecs[ $tk ] ?? [] ) ) );
			if ( $champs ) {
				$faits[ $tk ] = $champs;
				// À MOITIÉ REVENU : ce qui est revenu attend la relecture — jamais
				// publié à moitié — et ce qui manque est dit. Un champ envoyé et
				// jamais revenu est un manque, même sans erreur pour le dire.
				$manque = array_diff( self::task_fields( (array) $task ), array_keys( $champs ) );
				if ( $raisons || $manque ) {
					$partiel[ $tk ] = $raisons
						? (string) $raisons[0]
						/* translators: %s: the fields that did not come back */
						: sprintf( __( 'these fields did not come back: %s', 'dazont-ecom' ), implode( ', ', $manque ) );
				}
				continue;
			}
			$rates[ $tk ] = $raisons ? (string) $raisons[0] : __( 'Nothing came back.', 'dazont-ecom' );
		}
		// À REDEMANDER EN ENTIER : une tâche dont un morceau est à renvoyer
		// repart tout entière, plutôt que d'arriver à moitié en relecture.
		foreach ( array_keys( $encore ) as $tk ) {
			unset( $faits[ $tk ], $partiel[ $tk ], $rates[ $tk ] );
		}
		// À MOITIÉ REVENUE, ELLE EST REDEMANDÉE — jamais montrée à moitié tant
		// qu'il reste un essai. « 6 translations came back with nothing » était
		// dit de textes presque entiers, rangés en relecture avec un trou. Seul le
		// dernier essai range ce qui est revenu, et le dit.
		$essais = [];
		foreach ( self::asked() as $e ) {
			foreach ( $e['langs'] as $c ) {
				$essais[ self::ref( $e ) . '|' . $c ] = (int) ( $e['fails'][ $c ] ?? 0 );
			}
		}
		foreach ( $partiel as $tk => $why ) {
			if ( isset( $essais[ $tk ] ) && $essais[ $tk ] + 1 < self::TRIES ) {
				$rates[ $tk ] = $why;
				unset( $faits[ $tk ], $partiel[ $tk ] );
			}
		}
		// CE QUI EST REVENU ATTEND UNE DÉCISION, contre les mots qui ont été
		// envoyés — et au nom de celui qui l'a demandé, pas de la passe.
		$par_objet = [];
		foreach ( $faits as $tk => $champs ) {
			[ $ref, $lang ]             = explode( '|', (string) $tk, 2 );
			$par_objet[ $ref ][ $lang ] = $champs;
		}
		$qui = [];
		foreach ( (array) ( $b['tasks'] ?? [] ) as $task ) {
			$qui[ (string) ( $task['ref'] ?? '' ) ] = (int) ( $task['by'] ?? -1 );
		}
		$cle = (string) ( $b['token'] ?? $bid );
		foreach ( $par_objet as $ref => $langs ) {
			$o = self::from_ref( (string) $ref );
			if ( ! $o ) {
				continue;
			}
			$gardes = self::sent_read( $o );
			self::hold_landed( $o, $langs, (array) ( $gardes[ $cle ]['src'] ?? [] ), (int) ( $qui[ $ref ] ?? -1 ) );
			self::clear_drain_errors( (string) $ref, array_keys( $langs ) );
		}
		$runs = [];
		foreach ( array_keys( $faits ) as $tk ) {
			$unit          = (string) ( $b['tasks'][ $tk ]['unit'] ?? 'translate' );
			$runs[ $unit ] = ( $runs[ $unit ] ?? 0 ) + 1;
		}
		foreach ( $runs as $unit => $n ) {
			if ( $n > 0 && class_exists( 'DZE_Ai_Usage' ) ) {
				DZE_Ai_Usage::finished( (string) $unit, (int) $n );
			}
		}
		// CE QUI EST FAIT DEPUIS QUE LA FILE S'EST REMPLIE, pour la barre de l'écran —
		// compté AVANT que la file ne se vide : vidée, elle efface ce compte.
		if ( $faits ) {
			$run         = (array) self::fresh_option( self::OPT_RUN, [] );
			$run['done'] = (int) ( $run['done'] ?? 0 ) + count( $faits );
			$run['last'] = time();
			update_option( self::OPT_RUN, $run, false );
		}
		// LA FILE : ce qui est fait sort (relecture) ou attend d'être écrit
		// (sans relecture) ; ce qui a échoué retourne dans la file, et sort au
		// bout de trois fois en disant pourquoi.
		$notes = [];
		$tok   = (string) ( $b['token'] ?? '' );
		self::with_queue( static function ( array $file ) use ( $bid, $tok, $faits, $partiel, $rates, $global, $encore, &$notes ): array {
			foreach ( $file as $i => $e ) {
				$ref   = self::ref( $e );
				$reste = [];
				foreach ( $e['langs'] as $code ) {
					$m = (string) ( $e['sent'][ $code ] ?? '' );
					if ( $m !== $bid && ( '' === $tok || $m !== $tok ) ) {
						$reste[] = $code;
						continue;
					}
					unset( $file[ $i ]['sent'][ $code ] );
					$tk = $ref . '|' . $code;
					if ( isset( $encore[ $tk ] ) && empty( $e['keep'][ $code ] ) ) {
						$reste[] = $code; // renvoyée, sans rien compter.
						continue;
					}
					if ( isset( $faits[ $tk ] ) ) {
						unset( $file[ $i ]['fails'][ $code ] );
						if ( ! empty( $e['accept'] ) && empty( $e['keep'][ $code ] ) && ! isset( $partiel[ $tk ] ) ) {
							$file[ $i ]['land'][ $code ] = 1;
							$reste[]                     = $code;
						}
						continue;
					}
					// ANNULÉE ALORS QU'ELLE ÉTAIT PARTIE : elle ne repart pas,
					// même après une panne générale.
					if ( ! empty( $e['keep'][ $code ] ) ) {
						continue;
					}
					if ( $global ) {
						$reste[] = $code; // renvoyée plus tard, sans compter.
						continue;
					}
					$n = (int) ( $e['fails'][ $code ] ?? 0 ) + 1;
					if ( $n >= self::TRIES ) {
						$notes[] = [ $e, $code, (string) ( $rates[ $tk ] ?? '' ) ];
						continue;
					}
					$file[ $i ]['fails'][ $code ] = $n;
					$reste[]                      = $code;
				}
				$file[ $i ]['langs'] = $reste;
			}
			return $file;
		} );
		foreach ( $notes as [ $e, $code, $why ] ) {
			self::note_drain_error(
				$e,
				$code,
				sprintf(
					/* translators: %s: the last reason the model gave */
					__( 'Left the queue after three tries that came back with nothing. Last reason: %s', 'dazont-ecom' ),
					'' !== $why ? $why : __( 'Nothing came back.', 'dazont-ecom' )
				)
			);
		}
		foreach ( $partiel as $tk => $why ) {
			[ $ref, $lang ] = explode( '|', (string) $tk, 2 );
			$o               = self::from_ref( (string) $ref );
			if ( ! $o ) {
				continue;
			}
			self::note_drain_error(
				$o,
				$lang,
				sprintf(
					/* translators: %s: why part of the text did not come back */
					__( 'Part of it still did not come back after three tries (%s). What came back waits in « To review », not published.', 'dazont-ecom' ),
					$why
				)
			);
		}
		if ( $global ) {
			self::note_stop( '' !== $pourquoi ? $pourquoi : __( 'Anthropic translated nothing in the last batch.', 'dazont-ecom' ) );
			update_option( self::OPT_BACKOFF, time() + 30 * MINUTE_IN_SECONDS, false );
		} elseif ( $ralentir ) {
			// TROP DE DEMANDES D'UN COUP : la vague suivante attend un peu.
			update_option( self::OPT_BACKOFF, max( (int) self::fresh_option( self::OPT_BACKOFF, 0 ), time() + 20 ), false );
		}
		self::batch_save( $bid, [
			'id'     => $bid,
			'token'  => (string) ( $b['token'] ?? '' ),
			'at'     => (int) ( $b['at'] ?? 0 ),
			'status' => 'landed',
			'landed' => time(),
			'n'      => (int) ( $b['n'] ?? 0 ),
			'done'   => count( $faits ),
			'failed' => count( $rates ),
			'booked' => 1,
			'direct' => null !== $rows ? 1 : 0,
		] );
		// LES MOTS ENVOYÉS SONT LÂCHÉS EN DERNIER, une fois le lot noté relevé.
		self::sent_forget( $b );
	}

	/** Pourquoi une réponse de lot n'est pas venue, dans les mots de l'écran. */
	private static function batch_why( string $type, array $res ): string {
		if ( 'expired' === $type ) {
			return __( 'Anthropic did not get to it within 24 hours. Nothing was billed for it.', 'dazont-ecom' );
		}
		if ( 'canceled' === $type ) {
			return __( 'Cancelled before it was translated. Nothing was billed for it.', 'dazont-ecom' );
		}
		$msg = (string) ( $res['error']['error']['message'] ?? ( $res['error']['message'] ?? '' ) );
		/* translators: %s: the provider's own message */
		return sprintf( __( 'Anthropic API error: %s', 'dazont-ecom' ), '' !== $msg ? $msg : $type );
	}

	/** Les champs qu'une tâche a envoyés, morceaux ramenés à leur champ. @return string[] */
	private static function task_fields( array $task ): array {
		$out = [];
		foreach ( (array) ( $task['jobs'] ?? [] ) as $cles ) {
			foreach ( (array) $cles as $k ) {
				$pos         = strpos( (string) $k, self::PART );
				$fid         = false === $pos ? (string) $k : substr( (string) $k, 0, $pos );
				$out[ $fid ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * LES MORCEAUX REMIS ENSEMBLE, dans l'ordre — la même règle que pour un
	 * appel seul : un champ dont un morceau manque n'est pas écrit à moitié.
	 *
	 * @param array{jobs:array<int,string[]>,parts:array<string,int>} $task
	 * @param array<int,array<string,string>> $reponses indice du morceau => champ => texte
	 * @return array<string,string>
	 */
	private static function assemble( array $task, array $reponses ): array {
		$sac = [];
		foreach ( $reponses as $got ) {
			foreach ( (array) $got as $k => $v ) {
				$sac[ (string) $k ] = (string) $v;
			}
		}
		$ordre = [];
		foreach ( (array) ( $task['jobs'] ?? [] ) as $cles ) {
			foreach ( (array) $cles as $k ) {
				$pos = strpos( (string) $k, self::PART );
				$fid = false === $pos ? (string) $k : substr( (string) $k, 0, $pos );
				$ordre[ $fid ] = true;
			}
		}
		$parts = (array) ( $task['parts'] ?? [] );
		$keep  = (array) ( $task['keep'] ?? [] );
		$out   = [];
		foreach ( array_keys( $ordre ) as $fid ) {
			if ( isset( $parts[ $fid ] ) ) {
				$tout = '';
				for ( $k = 0; $k < (int) $parts[ $fid ]; $k++ ) {
					$cle   = $fid . self::PART . $k;
					// A PIECE WITH NO WORD WAS NEVER SENT: it is put back as it was.
					$piece = isset( $keep[ $cle ] ) ? (string) $keep[ $cle ] : (string) ( $sac[ $cle ] ?? '' );
					if ( '' === trim( $piece ) && ! isset( $keep[ $cle ] ) ) {
						$tout = '';
						break;
					}
					$tout .= $piece;
				}
				if ( '' !== trim( $tout ) ) {
					$out[ $fid ] = $tout;
				}
				continue;
			}
			$v = (string) ( $sac[ $fid ] ?? '' );
			if ( '' !== trim( $v ) ) {
				$out[ $fid ] = $v;
			}
		}
		// A FIELD WITH NO WORD IN IT was never sent, and is the same in any language.
		foreach ( $keep as $cle => $v ) {
			if ( false === strpos( (string) $cle, self::PART ) && '' !== trim( (string) $v ) ) {
				$out[ (string) $cle ] = (string) $v;
			}
		}
		return $out;
	}

	/**
	 * CE QUI REVIENT REJOINT CE QUI ATTENDAIT DÉJÀ, langue par langue, chacune
	 * contre les mots qui ont été envoyés POUR ELLE.
	 *
	 * @param array<string,array<string,string>> $langs langue => champ => texte
	 * @param array<string,array<string,string>> $envoye langue => champ => mots envoyés
	 */
	private static function hold_landed( array $o, array $langs, array $envoye, int $by ): void {
		$held   = self::waiting( $o );
		$keep   = (array) ( $held['langs'] ?? [] );
		$srcl   = (array) ( $held['srcl'] ?? [] );
		$shared = (array) ( $held['src'] ?? [] );
		foreach ( $langs as $lang => $champs ) {
			$keep[ $lang ] = (array) $champs;
			$srcl[ $lang ] = (array) ( $envoye[ $lang ] ?? [] );
			$shared        = array_merge( $shared, $srcl[ $lang ] );
		}
		self::hold( $o, $keep, $shared, $by, $srcl );
	}

	/**
	 * 3. PUBLIER — ce qui a été envoyé « sans relecture », écrit par tranches.
	 *
	 * Par le même accept() qu'un oui donné à la main. Chaque objet est PRIS
	 * dans la file, relue dans la base, juste avant d'être écrit : une
	 * annulation faite pendant que le passage écrivait les précédents est vue,
	 * et respectée. Ce qui ne peut pas être écrit reste dans « À relire » avec
	 * sa raison.
	 */
	private static function publish( float $t0, int $budget ): void {
		$candidats = [];
		foreach ( self::asked() as $e ) {
			if ( array_intersect( $e['langs'], array_keys( (array) $e['land'] ) ) ) {
				$candidats[] = self::entry_key( $e );
			}
		}
		foreach ( $candidats as $k ) {
			if ( microtime( true ) - $t0 > $budget ) {
				break; // la suite au passage suivant.
			}
			// PRIS DANS LA FILE, relue : encore revenue, jamais annulée.
			$pris = [];
			$qui  = null;
			self::with_queue( static function ( array $file ) use ( $k, &$pris, &$qui ): array {
				foreach ( $file as $i => $e ) {
					if ( self::entry_key( $e ) !== $k ) {
						continue;
					}
					$reste = [];
					foreach ( $e['langs'] as $code ) {
						if ( isset( $e['land'][ $code ] ) ) {
							if ( empty( $e['keep'][ $code ] ) ) {
								$pris[] = $code;
							}
							continue;
						}
						$reste[] = $code;
					}
					$file[ $i ]['langs'] = $reste;
					$qui                 = $e;
				}
				return $file;
			} );
			if ( ! $pris || ! $qui ) {
				continue;
			}
			$o = self::obj( $qui['kind'], $qui['id'], $qui['type'] );
			if ( ! $o ) {
				continue;
			}
			$held  = (array) ( self::waiting( $o )['langs'] ?? [] );
			$ecrit = array_intersect_key( $held, array_flip( $pris ) );
			if ( ! $ecrit ) {
				continue; // acceptée ou jetée à la main entre-temps.
			}
			try {
				$w = self::accept( $o, $ecrit, (int) $qui['by'] );
			} catch ( \Throwable $ex ) {
				$w = [ 'errors' => array_fill_keys( array_keys( $ecrit ), $ex->getMessage() ) ];
			}
			foreach ( (array) ( $w['errors'] ?? [] ) as $lg => $why ) {
				self::note_drain_error( $qui, (string) $lg, (string) $why );
			}
		}
	}

	/**
	 * CE QUI A RÉSISTÉ, gardé pour l'écran plutôt que perdu en silence — sur
	 * l'objet et la langue, pour que la ligne le montre là où on le cherche.
	 */
	private static function note_drain_error( array $o, string $lang, string $why ): void {
		$log = array_values( array_filter( (array) self::fresh_option( self::OPT_DRAIN_ERRORS, [] ), 'is_array' ) );
		$ref = self::ref( $o );
		$lg  = sanitize_key( $lang );
		// UNE LIGNE PAR LANGUE QUI A ÉCHOUÉ, pas une par tentative : le bandeau
		// compte des langues, pas des essais.
		$log = array_values( array_filter( $log, static fn( $row ) => ! ( (string) ( $row['ref'] ?? '' ) === $ref && (string) ( $row['lang'] ?? '' ) === $lg ) ) );
		array_unshift( $log, [
			'ref'  => $ref,
			'lang' => $lg,
			'why'  => mb_substr( $why, 0, 200 ),
			'at'   => time(),
		] );
		update_option( self::OPT_DRAIN_ERRORS, array_slice( $log, 0, 30 ), false );
	}

	/** Une langue réussie efface ce qui avait été noté contre elle. */
	private static function clear_drain_errors( string $ref, array $langs ): void {
		$log  = array_values( array_filter( (array) self::fresh_option( self::OPT_DRAIN_ERRORS, [] ), 'is_array' ) );
		$keep = [];
		foreach ( $log as $row ) {
			$row = (array) $row;
			if ( (string) ( $row['ref'] ?? '' ) === $ref && ( '' === (string) ( $row['lang'] ?? '' ) || in_array( (string) $row['lang'], $langs, true ) ) ) {
				continue;
			}
			$keep[] = $row;
		}
		if ( count( $keep ) !== count( $log ) ) {
			update_option( self::OPT_DRAIN_ERRORS, $keep, false );
		}
	}

	/** Ce qui a résisté, tel qu'écrit — une ligne qui n'en est pas une est ignorée. */
	public static function drain_log(): array {
		return array_values( array_filter( (array) self::fresh_option( self::OPT_DRAIN_ERRORS, [] ), 'is_array' ) );
	}

	/**
	 * CE QUI A RÉSISTÉ, par objet et par langue.
	 *
	 * @return array<string,array<string,string>> ref => langue ('' pour l'objet entier) => pourquoi
	 */
	public static function drain_errors(): array {
		$out = [];
		foreach ( self::drain_log() as $row ) {
			$row  = (array) $row;
			$ref  = (string) ( $row['ref'] ?? '' );
			$lang = (string) ( $row['lang'] ?? '' );
			if ( '' !== $ref && ! isset( $out[ $ref ][ $lang ] ) ) {
				$out[ $ref ][ $lang ] = (string) ( $row['why'] ?? '' );
			}
		}
		return $out;
	}

	/**
	 * CE QU ON TRADUIT EN CE MOMENT, pour que la depense le dise.
	 *
	 * « Pour la traduction : x produits, x articles de blog, x taxonomies. »
	 * Tout tombait dans un seul seau « translate » : soixante-deux dollars
	 * sans savoir sur quoi. Le genre de l objet voyage donc avec l unite —
	 * « translate:product » — et le tableau des couts le detaille.
	 */
	private static string $subject = '';

	/**
	 * ET CE QUI A ETE TRADUIT AVANT QUE LA CIBLE NE LE SOIT.
	 *
	 * « A-t-on un systeme qui pourra mettre a jour ensuite l url cible ? Pour
	 * l instant la page cible n a pas encore ete traduite ! »
	 *
	 * relink() fait le bon travail AU MOMENT de la traduction : il repointe
	 * chaque lien vers la page de la langue d arrivee, et laisse tranquille
	 * celui dont la cible n existe pas encore — un lien anglais qui marche
	 * vaut mieux qu un 404. Mais rien ne revenait ENSUITE : une description
	 * traduite en janvier gardait l adresse anglaise de sa cible meme apres
	 * que celle-ci ait recu sa propre traduction. Quatre-vingts liens sur
	 * quatre-vingt-cinq etaient dans ce cas sur cette boutique.
	 *
	 * Accepter une traduction est le moment exact ou de nouvelles cibles
	 * deviennent atteignables. On repasse donc, borne, sur ce que cette
	 * langue porte deja. Rien n est marque comme fait : relink() rend le
	 * texte inchange quand il n y a rien a changer, donc repasser ne coute
	 * qu une lecture et ne peut pas deriver.
	 *
	 * @return int combien de descriptions ont ete reecrites.
	 */
	public static function relink_sweep( string $lang, int $max = self::RELINK_SWEEP ): int {
		global $wpdb;
		$lang = sanitize_key( $lang );
		if ( '' === $lang || ! $wpdb || ! class_exists( 'DZE_Category_Content' ) || ! class_exists( 'DZE_Queue' ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table de WPML.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT element_id FROM {$wpdb->prefix}icl_translations
			 WHERE element_type = 'tax_product_cat' AND language_code = %s AND source_language_code IS NOT NULL",
			$lang
		) );
		$faits = 0;
		foreach ( (array) $ids as $id ) {
			if ( $faits >= $max ) {
				break;
			}
			$row  = DZE_Category_Content::term_row( (int) $id );
			$html = (string) ( $row['description'] ?? '' );
			if ( '' === $html || false === stripos( $html, '<a ' ) ) {
				continue;
			}
			$neuf = self::relink( $html, $lang );
			if ( $neuf === $html ) {
				continue;
			}
			// EN COLONNE, jamais wp_update_term() : voir DZE_Queue.
			if ( DZE_Queue::write_description( (int) $id, $neuf ) ) {
				$faits++;
			}
		}
		return $faits;
	}

	/**
	 * THE LINKS INSIDE A TRANSLATION POINT AT THAT LANGUAGE'S PAGES.
	 *
	 * "Les nouveaux liens pour le maillage interne seront ils traduits ? Les
	 * pages de destination seront elles adaptées pour avoir le bon url de la
	 * page cible traduite ?" They were not. The prompt says "same attributes",
	 * which is right — a model must not invent an address — so every French
	 * description carried the ENGLISH href of its target. Measured on this
	 * shop: 67 internal links across the translated categories, 67 pointing at
	 * the English page, none at the French one.
	 *
	 * So the rewriting is done HERE, in code, after the model has answered:
	 * each of our own addresses is resolved to its object, WPML is asked for
	 * that object in the target language, and the href becomes its permalink.
	 *
	 * An address with no translation is LEFT ALONE. A working link to the
	 * English page is worth more to a reader than a 404 in his own language.
	 */
	public static function relink( string $html, string $lang ): string {
		$lang = sanitize_key( $lang );
		if ( '' === $lang || false === stripos( $html, '<a ' ) || ! function_exists( 'home_url' ) ) {
			return $html;
		}
		// DEUX TEMPS, PARCE QUE DEUX CONTEXTES DE LANGUE.
		//
		// Resoudre une adresse — url_to_postid(), le slug d un terme — se fait
		// dans la langue du texte de DEPART. Calculer l adresse de la cible se
		// fait dans la langue d ARRIVEE, sinon WPML rend le permalien sans son
		// prefixe et les deux adresses sortent identiques : c est ce que
		// get_term_link() faisait, et le lien francais pointait encore sur la
		// page anglaise.
		if ( ! preg_match_all( '#<a\b[^>]*\bhref="([^"]+)"#i', $html, $m ) ) {
			return $html;
		}
		$want = [];
		foreach ( array_unique( $m[1] ) as $raw ) {
			$url = html_entity_decode( (string) $raw );
			$at  = self::object_at( $url );
			if ( ! $at ) {
				continue;
			}
			[ $kind, $id ] = $at;
			$type = 'post' === $kind ? (string) ( get_post_type( $id ) ?: 'post' ) : 'product_cat';
			$to   = self::obj_translation( [ 'kind' => $kind, 'id' => $id, 'type' => $type ], $lang );
			if ( ! $to || $to === $id ) {
				continue; // no translation of its own: leave the link that works.
			}
			$want[ (string) $raw ] = [ $kind, $to, $type ];
		}
		if ( ! $want ) {
			return $html;
		}
		// LA LANGUE D ARRIVEE, LE TEMPS DE LIRE LES ADRESSES, puis rendue.
		$was = (string) apply_filters( 'wpml_current_language', '' );
		if ( $was !== $lang ) {
			do_action( 'wpml_switch_language', $lang );
		}
		$map = [];
		foreach ( $want as $raw => $one ) {
			[ $kind, $to, $type ] = $one;
			$new = 'post' === $kind ? (string) get_permalink( $to ) : (string) get_term_link( $to, $type );
			if ( '' !== $new && ! is_wp_error( $new ) ) {
				$map[ (string) $raw ] = esc_url( $new );
			}
		}
		if ( $was !== $lang ) {
			do_action( 'wpml_switch_language', $was );
		}
		if ( ! $map ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'#(<a\b[^>]*\bhref=")([^"]+)(")#i',
			static fn( array $mm ): string => isset( $map[ $mm[2] ] ) ? $mm[1] . $map[ $mm[2] ] . $mm[3] : $mm[0],
			$html
		);
	}
	public static function prompt(): string {
		$p = trim( (string) ( self::get_settings()['prompt'] ?? '' ) );
		return '' !== $p ? $p : self::default_prompt();
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
			  WHERE p.post_type = %s AND p.post_status IN ('publish','private')
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
			// L ID DEMANDE, JAMAIS CELUI QUE WPML REND. get_term( 6837 ) repond
			// le terme 7246 quand la session est en francais : obj() rendait donc
			// un objet de travail pointant sur la TRADUCTION, et tout le module
			// suivait — la source lue, la langue deduite, et l ecriture finale
			// par-dessus l original. L ironie est que obj_read() lit deja en
			// table sans filtre : il lisait fidelement le mauvais terme.
			$tax = trim( $type );
			if ( '' === $tax ) {
				global $wpdb;
				$tax = (string) $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d LIMIT 1", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			if ( '' === $tax ) {
				return [];
			}
			return DZE_Wpml::is_translated_taxonomy( $tax )
				? [ 'kind' => 'term', 'id' => $id, 'type' => $tax ]
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
	public static function by_default(): array {
		return [ 'post:page', 'post:post', 'post:product', 'term:product_cat', 'term:product_tag' ];
	}

	/**
	 * RIEN N EST IMPOSE : c est un defaut, pas une regle.
	 *
	 * « Il faudrait […] aussi choisir quels posts traduire automatiquement. »
	 * Ces cinq-la, et tous les attributs, etaient coches ET desactives : la
	 * liste disait « choisissez » et refusait la moitie du choix. Une boutique
	 * qui ne veut pas payer la traduction de ses attributs ne pouvait pas le
	 * dire — et c est justement ce qui remplissait la file d attributs que
	 * personne n emploie.
	 *
	 * Ce qui reste vrai est le DEFAUT : une boutique qui n a jamais repondu
	 * traduit ce qu elle traduisait avant que la question existe.
	 */
	public static function is_default( string $key ): bool {
		return in_array( $key, self::by_default(), true ) || 0 === strpos( $key, 'term:pa_' );
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
		$out    = [];
		// JAMAIS REPONDU N EST PAS REPONDU NON. Une boutique qui n a pas encore
		// vu cette liste continue de traduire ce qu elle traduisait avant que le
		// choix existe ; une boutique qui a repondu est prise au mot, y compris
		// quand elle a tout decoche.
		$never = ! is_array( $picked );
		$picked = $never ? [] : array_map( 'strval', $picked );
		foreach ( $all as $key => $one ) {
			if ( $never ? self::is_default( $key ) : in_array( $key, $picked, true ) ) {
				$out[ $key ] = $one;
			}
		}
		return $out;
	}

	/**
	 * NOUVEAUX, MISES A JOUR, OU LES DEUX.
	 *
	 * « Il faut la possibilite de choisir : traduction des nouveaux posts, mise
	 * a jour des anciens, les deux. » Les deux travaux n ont ni le meme cout ni
	 * la meme urgence : une page qui n existe dans aucune langue est un trou
	 * dans le catalogue, une page dont la source a bouge est un entretien.
	 *
	 * WPML repond deja aux deux questions — une langue manquante d un cote, son
	 * drapeau `needs_update` de l autre — donc c est un filtre, pas un calcul.
	 *
	 * @return string 'new', 'update' ou 'both'
	 */
	public static function when(): string {
		$w = (string) ( self::get_settings()['when'] ?? 'both' );
		return in_array( $w, [ 'new', 'update', 'both' ], true ) ? $w : 'both';
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
	/**
	 * THE ATTRIBUTES WRITTEN ON THE PRODUCT ITSELF, which nobody translates.
	 *
	 * `attribute_objects()` answers with TERMS — values picked from a shared
	 * list, which WPML translates as objects of their own. A product can also
	 * carry attributes typed into its own box: they are text in
	 * `_product_attributes`, they belong to no taxonomy, and WPML has nothing
	 * to translate them as. The screen listed the first kind and said nothing
	 * at all about the second — "même les attributs ne sont pas là" — so a
	 * product whose sizes and colours are local read as a product with no
	 * attributes.
	 *
	 * Nine products on this catalogue carry ten of them, and every one is a
	 * VARIATION AXIS. That matters more than the translation: WooCommerce
	 * matches a variation to its parent by the value STRING. Translate "CVC
	 * Black" on the parent while the variations still hold the English and
	 * nothing matches — the product stops being buyable. So these are
	 * reported, never sent, and the row says which ones would break.
	 *
	 * @return array<int,array{name:string,values:string[],is_variation:bool}>
	 */
	public static function local_attributes( array $o ): array {
		if ( 'post' !== ( $o['kind'] ?? '' ) || 'product' !== ( $o['type'] ?? '' ) ) {
			return [];
		}
		$raw = get_post_meta( (int) $o['id'], '_product_attributes', true );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $key => $one ) {
			if ( ! is_array( $one ) || ! empty( $one['is_taxonomy'] ) ) {
				continue;
			}
			$vals = array_values( array_filter( array_map( 'trim', explode( '|', (string) ( $one['value'] ?? '' ) ) ) ) );
			if ( ! $vals && '' === trim( (string) ( $one['name'] ?? '' ) ) ) {
				continue;
			}
			$out[] = [
				'name'         => (string) ( $one['name'] ?? $key ),
				'values'       => $vals,
				'is_variation' => ! empty( $one['is_variation'] ),
			];
		}
		return $out;
	}

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
	 * WHY A FIELD HAS NOTHING IN IT — said, rather than left blank.
	 *
	 * The editor printed only the fields that held text, so a field empty on
	 * the original and a field this module cannot handle looked exactly the
	 * same: absent. "Pourquoi pas de traduction des champs seo ? title et
	 * description… j'ai l'impression qu'il manque plein de choses ici." On the
	 * product that prompted it, the SEO pair and both content blocks were
	 * empty on the English original — the module was right and said nothing,
	 * which is the worst of both. An owner who knows WPML expects its editor:
	 * every field listed, whether or not it carries words.
	 *
	 * The order matters. A field WPML copies is worth saying even when it is
	 * empty, because that is the one the owner can act on.
	 *
	 * @param string $fid The field, as `fields()` names it.
	 * @return array{said:string,tone:string}
	 */
	public static function absent_said( string $fid, string $kind = 'post', string $type = '' ): array {
		$f = self::fields( $kind, $type )[ $fid ] ?? [];
		if ( ! $f ) {
			// Not a declared field: it was found ON the object (a WPML custom
			// field, an Elementor widget, a variation), so it cannot be here
			// and absent at the same time.
			return [ 'said' => __( 'nothing to translate.', 'dazont-ecom' ), 'tone' => 'quiet' ];
		}
		$key = ( 'meta' === ( $f['type'] ?? '' ) ) ? self::meta_key_for( $fid ) : (string) ( $f['key'] ?? '' );
		if ( in_array( $fid, [ 'seo_title', 'seo_desc' ], true ) && ( '' === $key || 0 === strpos( $key, '_dze_seo' ) ) ) {
			return [
				'said' => __( 'no SEO plugin was found on this site, so nothing reads this field.', 'dazont-ecom' ),
				'tone' => 'off',
			];
		}
		if ( 'meta' === ( $f['type'] ?? '' ) && '' !== $key && class_exists( 'DZE_Wpml' )
			&& in_array( DZE_Wpml::custom_field_mode( $key ), [ 1, 3 ], true ) ) {
			return [
				'said' => __( 'WPML is set to COPY this field from the original, so it is left alone.', 'dazont-ecom' ),
				'tone' => 'warn',
			];
		}
		// THE COMMON CASE, AND THE ONE THAT WAS MISSING. The field is
		// supported, it is sent whenever it holds words, and this object
		// simply has none.
		return [
			'said' => __( 'empty on the original — nothing to translate.', 'dazont-ecom' ),
			'tone' => 'quiet',
		];
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
		$el = self::elementor_fields( $o );
		foreach ( $el as $fid => $f ) {
			$v = trim( self::elementor_get( (int) $o['id'], (string) $f['path'] ) );
			if ( '' !== $v ) {
				$out[ $fid ] = $v;
			}
		}
		// AND THE FLATTENED COPY IS NOT SENT AT ALL.
		//
		// On a page Elementor builds, `post_content` is a dump of the RENDERED
		// page: SVG path data, thumbnail URLs, `data-src` attributes, the
		// reviews shortcode with its forty parameters. On the homepage that is
		// 27,000 characters against 5,600 of actual words — five calls out of
		// seven spent translating machine markup, the answers fragile enough
		// that one of them came back malformed and the page translated nothing
		// at all. Nobody reads it, Elementor overwrites it, and not one word of
		// it reaches a visitor.
		if ( $el ) {
			unset( $out['content'] );
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
		// AN UNDO NEVER BLANKS A PAGE.
		//
		// A post field or a meta key can legitimately have been empty before,
		// and putting it back means emptying it again. An Elementor setting
		// cannot: a heading, a paragraph or a tab title is only in the tree
		// because it holds something. An empty one remembered is a reading
		// that went wrong, and writing it back leaves the page blank with an
		// "undone" to show for it. Sixty-one of them, once.
		foreach ( array_keys( $prev ) as $fid ) {
			if ( 0 === strpos( (string) $fid, 'el:' ) && '' === trim( (string) $prev[ $fid ] ) ) {
				unset( $prev[ $fid ] );
			}
		}
		if ( ! $prev ) {
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

	/**
	 * WORDPRESS STRIPS THE BACKSLASHES OUT OF EVERY META VALUE IT SAVES.
	 *
	 * `update_post_meta()` runs `wp_unslash()` on what it is given, which is
	 * right for a form post and wrong for anything this module builds itself.
	 * JSON escapes every quote as `\"` and every slash as `\/`: stripped of its
	 * backslashes it stops being JSON at all.
	 *
	 * So the undo register — the words a translation held before we wrote over
	 * them — was saved whole and came back unreadable every single time the
	 * text held a quote or an address, which on a shop is every time. 7,271
	 * bytes on disk, nought fields on the way out, and an "Undo" button that
	 * quietly had nothing behind it. The words were all there; only the
	 * punctuation around them was gone.
	 *
	 * `wp_slash()` on the way in cancels that out exactly, the way Elementor's
	 * own tree is already written a few lines above.
	 */
	private static function meta_write( array $o, int $id, string $key, string $value ): void {
		$value = function_exists( 'wp_slash' ) ? wp_slash( $value ) : $value;
		if ( 'term' === ( $o['kind'] ?? 'post' ) ) {
			update_term_meta( $id, $key, $value );
			return;
		}
		update_post_meta( $id, $key, $value );
	}

	/**
	 * THE SLUG FOLLOWS WPML'S RULE, NOT ONE OF OURS.
	 *
	 * "Sur les réglages wpml, on peut choisir de traduire les slugs ou créer
	 * les slugs sur la base du nouveau titre du post. J'ai paramétré le
	 * second. Notre module doit suivre les mêmes réglages que wpml."
	 *
	 * So the setting is READ (`DZE_Wpml::slug_rule()`) rather than answered
	 * here, and there are two answers to honour:
	 *
	 *   auto-generate  — build it from the title, and leave alone a slug that
	 *                    somebody already chose. The mark is what tells those
	 *                    two apart: it exists only on a translation this
	 *                    module created and has never given a real address.
	 *   force-generate — build it from the title every time, over whatever is
	 *                    there, which is exactly what the WPML box says.
	 *
	 * THE LOCALE IS SWITCHED FIRST, and it is not a detail: `sanitize_title()`
	 * folds accents through `remove_accents()`, which reads the CURRENT locale.
	 * "Militärmäntel" comes out `militaermaentel` in German and
	 * `militarmantel` in anything else — and this runs from cron, where the
	 * locale is the site's default unless something switches it. The shop's
	 * existing German slugs are the `ae`/`ue` kind, so the wrong one would
	 * stand out as ours.
	 *
	 * A TERM GETS ITS OWN CALL, on purpose. `wp_update_term()` refuses a slug
	 * another term of the taxonomy holds, and refusing it inside the same call
	 * as the name would take the NAME down with it: the translation would keep
	 * its English title because its address was taken. Refused, the mark stays
	 * and the next write tries again.
	 */
	private static function slug_follow( array $o, int $target_id, string $title ): void {
		$title = trim( wp_strip_all_tags( $title ) );
		if ( '' === $title || ! $target_id ) {
			return;
		}
		$mine = '1' === (string) self::meta_read( $o, $target_id, self::META_SLUG );
		if ( 'force-generate' !== DZE_Wpml::slug_rule() && ! $mine ) {
			return;
		}
		$kind = (string) ( $o['kind'] ?? 'post' );
		$type = (string) ( $o['type'] ?? '' );
		$lang = 'term' === $kind
			? self::term_language( $target_id, $type )
			: DZE_Wpml::post_language( $target_id, $type );
		$was  = (string) apply_filters( 'wpml_current_language', '' );
		$hop  = '' !== $lang && $was !== $lang;
		if ( $hop ) {
			do_action( 'wpml_switch_language', $lang );
		}
		try {
			$slug = sanitize_title( $title );
			if ( '' === $slug ) {
				return;
			}
			if ( 'term' === $kind ) {
				// LE SLUG SEUL, ET RIEN D AUTRE. Par wp_update_term() le noyau
				// remettait le nom et la description de l ORIGINAL avec, parce
				// qu il relit le terme par get_term() que WPML filtre. Et
				// l unicite est verifiee ici : plus personne ne la verifie
				// pour nous sur ce chemin.
				$slug = self::free_slug( $slug, $type, $target_id );
				if ( ! self::term_write( $target_id, $type, [ 'slug' => $slug ] ) ) {
					if ( class_exists( 'DZE_Health' ) ) {
						DZE_Health::log( 'translate', 'slug_follow', sprintf(
							'le slug %s n a pas pu etre ecrit sur le terme %d', $slug, $target_id
						) );
					}
					return;
				}
			} else {
				wp_update_post( [ 'ID' => $target_id, 'post_name' => $slug ] );
			}
		} finally {
			if ( $hop ) {
				do_action( 'wpml_switch_language', $was );
			}
		}
		self::meta_write( $o, $target_id, self::META_SLUG, '' );
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
				// DANS LES TABLES, PAS PAR wp_update_term() : celui-ci relit le
				// terme par get_term(), que WPML rend dans la langue courante,
				// et réécrit tout ce qu'on ne lui a pas nommé — à commencer par
				// le slug de l'original, qu'il pose sur la traduction.
				self::term_write( $target_id, (string) $o['type'], $args );
			}
			if ( isset( $args['name'] ) ) {
				self::slug_follow( $o, $target_id, (string) $args['name'] );
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
		// THE POST FIELDS FIRST, THE ELEMENTOR TREE LAST.
		//
		// `wp_update_post()` on a translation is a save, and a save is when
		// WPML copies from the original everything it is set to copy —
		// `_elementor_data` included. Written before it, the whole translated
		// tree went back to English on the way out: 61 fields translated, paid
		// for, written, and identical to the source a second later. It only
		// showed on a page being created in the same pass, which is exactly
		// the run nobody watches.
		if ( $post ) {
			$post['ID'] = $target_id;
			wp_update_post( $post );
		}
		// AFTER the title is on the post, never before: the slug is made from
		// the title as it now stands, and a second save here is harmless —
		// WPML has already done its copying on the save just above, and the
		// Elementor tree is still written after both.
		if ( isset( $post['post_title'] ) ) {
			self::slug_follow( $o, $target_id, (string) $post['post_title'] );
		}
		if ( $el ) {
			// AND WHAT IS ABOUT TO BE REPLACED IS READ HERE, NOT AT THE TOP.
			//
			// On a translation created in this same pass the page did not hold
			// an Elementor tree yet when this function began: WPML copies it
			// during the `wp_update_post()` just above. Read too early, every
			// field was remembered as empty — and an undo then EMPTIED the
			// page instead of putting the original words back. Sixty-one
			// headings and paragraphs blanked, with an "undone" to show for it.
			if ( $remember ) {
				$prev_el = [];
				foreach ( array_keys( $el ) as $path ) {
					$prev_el[ 'el:' . $path ] = self::elementor_get( $target_id, (string) $path );
				}
				$held = json_decode( (string) self::meta_read( $o, $target_id, self::META_PREV ), true );
				self::meta_write(
					$o,
					$target_id,
					self::META_PREV,
					(string) wp_json_encode( array_merge( is_array( $held ) ? $held : [], $prev_el ) )
				);
			}
			self::elementor_put( $target_id, $el );
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
	/**
	 * Un slug que personne ne tient dans cette taxonomie.
	 *
	 * Lu dans LES TABLES, jamais par `get_term_by()` : WPML filtre celui-là
	 * sur la langue courante, donc il répond « libre » pour un slug que la
	 * traduction voisine occupe — et `wp_insert_term()` refuse ensuite, ce qui
	 * est exactement le mur qu'on essaie de contourner.
	 */
	/**
	 * ÉCRIT UN TERME DANS LES TABLES — jamais par `wp_update_term()`.
	 *
	 * C'est le même piège que partout ailleurs dans ce module, et il était
	 * entré par la porte de derrière. `wp_update_term()` commence par
	 * `$term = get_term( $term_id, $taxonomy )` puis fusionne : `$args =
	 * array_merge( $term, $args )`. Or WPML filtre `get_term()` sur la langue
	 * COURANTE et répond avec l'ORIGINAL quand on l'interroge sur une
	 * traduction. Tout champ qu'on ne lui passe pas explicitement est donc
	 * repris à l'anglais et réécrit par-dessus le français.
	 *
	 * Ce que ça donnait : `slug_follow()` demandait « change seulement le
	 * slug », et le noyau remettait le nom et la description anglais avec.
	 * https://kula-tactical.fr/etiquette-produit/rails-ak — slug français,
	 * nom anglais, description anglaise, et l'écran des traductions annonçant
	 * que tout s'était bien passé.
	 *
	 * Alors on écrit les colonnes, et rien d'autre : le nom et le slug dans
	 * `wp_terms`, la description dans `wp_term_taxonomy`. Le module lit déjà
	 * les termes de cette façon exacte, pour cette raison exacte.
	 *
	 * @param array<string,string> $fields name, slug, description.
	 */
	private static function term_write( int $term_id, string $taxonomy, array $fields ): bool {
		global $wpdb;
		if ( $term_id < 1 || ! $fields ) {
			return false;
		}
		$ok  = true;
		$row = array_intersect_key( $fields, [ 'name' => 1, 'slug' => 1 ] );
		if ( $row ) {
			$ok = false !== $wpdb->update( $wpdb->terms, $row, [ 'term_id' => $term_id ] );
		}
		if ( array_key_exists( 'description', $fields ) ) {
			$ok = ( false !== $wpdb->update(
				$wpdb->term_taxonomy,
				[ 'description' => (string) $fields['description'] ],
				[ 'term_id' => $term_id, 'taxonomy' => $taxonomy ]
			) ) && $ok;
		}
		if ( function_exists( 'clean_term_cache' ) ) {
			clean_term_cache( $term_id, $taxonomy );
		}
		return $ok;
	}

	private static function free_slug( string $base, string $taxonomy, int $except = 0 ): string {
		global $wpdb;
		$base = sanitize_title( $base );
		if ( '' === $base ) {
			$base = 'term';
		}
		$slug = $base;
		for ( $n = 2; $n < 100; $n++ ) {
			// LE TERME LUI-MEME NE SE FAIT PAS OBSTACLE. Repasser sur une
			// traduction qui porte deja ce slug lui collerait un « -2 » a
			// chaque fois, et l adresse changerait pour rien.
			$taken = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->terms} t
				   JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				  WHERE tt.taxonomy = %s AND t.slug = %s AND t.term_id <> %d",
				$taxonomy,
				$slug,
				$except
			) );
			if ( ! $taken ) {
				return $slug;
			}
			$slug = $base . '-' . $n;
		}
		// Cent pris d'affilée n'arrive pas ; si cela arrivait, un slug unique
		// vaut mieux qu'une boucle sans fin ou qu'un terme qui ne naît pas.
		return $base . '-' . substr( md5( $base . microtime( true ) ), 0, 6 );
	}

	private static function create_term( array $o, string $lang ): int {
		// LE TERME SOURCE, PAS CELUI DE LA LANGUE COURANTE. Ces valeurs partent
		// droit dans wp_insert_term : lues par get_term(), la nouvelle branche
		// naissait avec le nom, le texte et le slug d une traduction existante —
		// une categorie allemande publiee sous un slug francais, definitivement,
		// car obj_write() ne reecrit jamais le slug.
		$term = class_exists( 'DZE_Category_Content' )
			? DZE_Category_Content::term_row( (int) $o['id'], (string) $o['type'] )
			: null;
		if ( ! $term ) {
			throw new RuntimeException( __( 'Term not found.', 'dazont-ecom' ) );
		}
		$term = (object) $term;
		$args = [ 'description' => $term->description ];
		if ( (int) $term->parent ) {
			$parent = (int) apply_filters( 'wpml_object_id', (int) $term->parent, (string) $o['type'], false, $lang );
			if ( $parent ) {
				$args['parent'] = $parent;
			}
		}
		// UNE TAXONOMIE PLATE REFUSE UN NOM EN DOUBLE, et une traduction naît
		// avec le nom de son original.
		//
		// « A term with the name provided already exists in this taxonomy. »
		// Le slug n'était pas recopié — à raison, deux termes ne peuvent pas
		// le partager — mais rien n'était fourni à la place, et WordPress
		// refuse alors le NOM lui-même dès que la taxonomie est plate :
		// `wp_insert_term()` ne laisse passer un nom déjà pris que si on lui
		// donne un slug libre. `product_cat` est hiérarchique, donc le même
		// nom y passe sous un autre parent et les catégories marchaient ;
		// `product_tag` est plate, et aucune étiquette n'a jamais pu être
		// traduite.
		//
		// Le slug posé ici ne dure pas : `slug_follow()` le refait depuis le
		// nom traduit dès que celui-ci est écrit, quelques lignes plus loin.
		// C'est un laissez-passer, pas une adresse.
		$args['slug'] = self::free_slug( (string) $term->slug . '-' . $lang, (string) $o['type'] );
		$made = wp_insert_term( (string) $term->name, (string) $o['type'], $args );
		if ( is_wp_error( $made ) ) {
			throw new RuntimeException( $made->get_error_message() );
		}
		$new_id = (int) ( $made['term_id'] ?? 0 );
		if ( ! $new_id ) {
			throw new RuntimeException( __( 'The term could not be created.', 'dazont-ecom' ) );
		}
		// The slug WordPress just made comes from the ENGLISH name, because
		// that is the only name there is at this point. `slug_follow()` comes
		// back for it as soon as the translated name is written.
		update_term_meta( $new_id, self::META_SLUG, '1' );
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

	/**
	 * CE QUE CE MODULE NE TRADUIT PAS, ET QUI ATTEND DANS WPML.
	 *
	 * « La traduction de la base du site se fait sur WPML. » Deux chantiers
	 * portent le meme nom et ne sont pas le meme travail :
	 *
	 *   - LE CONTENU — produits, pages, articles, taxonomies — c est ici, et
	 *     c est ce que fait ce module, en continu, a chaque nouveau texte ;
	 *   - LES CHAINES — l en-tete, le pied de page, les boutons, le bandeau
	 *     de livraison, tout ce qu un theme ou une extension ecrit en dur —
	 *     c est WPML → Traduction de chaines, et c est une fois pour toutes.
	 *
	 * Une phrase seule se lit et s oublie. On compte donc, pour chaque langue,
	 * les chaines qu UNE AUTRE langue du site a deja traduites et que celle-ci
	 * n a pas : c est exactement ce que la boutique a juge digne d etre
	 * traduit, et ce qui manque ici. Sur cette boutique le russe en accusait
	 * six mille six cent cinquante quand le site paraissait pourtant pret.
	 *
	 * Les avis produits sont ecartes : WCML en enregistre onze mille, personne
	 * ne les traduit a la main, et les compter noierait le chiffre utile.
	 *
	 * @return array<string,int> code de langue => nombre de chaines manquantes.
	 */
	public static function strings_gap(): array {
		$cache = get_transient( 'dze_strings_gap' );
		if ( is_array( $cache ) ) {
			return $cache;
		}
		global $wpdb;
		$out = [];
		if ( ! $wpdb || ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			return $out;
		}
		$st = $wpdb->prefix . 'icl_string_translations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables de WPML.
		if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$st}'" ) ) {
			return $out; // String Translation n est pas installe : rien a dire.
		}
		$strings = $wpdb->prefix . 'icl_strings';
		foreach ( DZE_Wpml::get_active_languages() as $l ) {
			$code = (string) ( $l['code'] ?? '' );
			if ( '' === $code || $code === DZE_Wpml::default_language() ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables de WPML.
			$out[ $code ] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$strings} s
				 WHERE s.context NOT LIKE 'wcml-reviews%%'
				   AND EXISTS ( SELECT 1 FROM {$st} a WHERE a.string_id = s.id AND a.language <> %s AND a.value <> '' )
				   AND NOT EXISTS ( SELECT 1 FROM {$st} b WHERE b.string_id = s.id AND b.language = %s AND b.value <> '' )",
				$code,
				$code
			) );
		}
		// Une heure : le chiffre bouge quand on traduit, pas d une seconde a
		// l autre, et la requete traverse des dizaines de milliers de lignes.
		set_transient( 'dze_strings_gap', $out, HOUR_IN_SECONDS );
		return $out;
	}

	public static function strings_url(): string {
		return admin_url( 'admin.php?page=wpml-string-translation/menu/string-translation.php' );
	}

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
		<?php
		// LA MOITIE DU TRAVAIL QUI N EST PAS ICI. Voir strings_gap().
		$dze_gap = $wpml ? self::strings_gap() : [];
		$dze_due = array_filter( $dze_gap );
		if ( $wpml ) :
			?>
			<div class="notice notice-info inline" style="margin:12px 0;max-width:900px;">
				<p style="margin:8px 0 6px;"><strong><?php esc_html_e( 'The rest of the site is translated in WPML, not here.', 'dazont-ecom' ); ?></strong></p>
				<p class="description" style="margin:0 0 8px;">
					<?php esc_html_e( 'This module translates what the shop writes: products, articles, pages and taxonomies. The wording built into the theme and the plugins — the header, the footer, the buttons, the shipping banner — belongs to WPML → String Translation, and it is done once.', 'dazont-ecom' ); ?>
				</p>
				<?php if ( $dze_due ) : ?>
					<p style="margin:0 0 6px;">
						<?php esc_html_e( 'Wording another language of this site already has, and these do not:', 'dazont-ecom' ); ?>
					</p>
					<ul style="margin:0 0 8px 18px;list-style:disc;">
						<?php foreach ( $dze_due as $dze_code => $dze_n ) : ?>
							<li>
								<strong><?php echo esc_html( strtoupper( (string) $dze_code ) ); ?></strong> —
								<?php
								printf(
									/* translators: %s: how many strings are missing */
									esc_html( _n( '%s string missing', '%s strings missing', $dze_n, 'dazont-ecom' ) ),
									esc_html( number_format_i18n( $dze_n ) )
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>
					<p style="margin:0 0 8px;">
						<a class="button button-secondary" href="<?php echo esc_url( self::strings_url() ); ?>">
							<?php esc_html_e( 'Open WPML → String Translation', 'dazont-ecom' ); ?>
						</a>
					</p>
					<p class="description" style="margin:0 0 8px;">
						<?php // SON OUTIL, PAS LE NOTRE. « Pas besoin de module Dazont qui va
						// complexifier le plugin pour un apport tres faible. » WPML sait
						// deja reperer les chaines VUES PAR LE VISITEUR et ne proposer
						// que celles-la : c est exactement ce qu il faut, et c est chez lui. ?>
						<?php esc_html_e( 'WPML can find the wording a visitor actually sees and offer only that — which is what matters here. The admin-only strings are not worth translating.', 'dazont-ecom' ); ?>
					</p>
				<?php elseif ( $dze_gap ) : ?>
					<p class="description" style="margin:0 0 8px;">
						<?php esc_html_e( 'Every language of this site has the same wording as the others. Nothing is waiting there.', 'dazont-ecom' ); ?>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
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
							<?php // PLUS AUCUNE CASE GRISEE : la liste demandait de choisir et
								// refusait la moitie du choix. Ce qui etait « toujours traduit »
								// est desormais « coche par defaut », et se decoche. ?>
							<label style="display:block;margin-bottom:3px;">
								<input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[scope][]" value="<?php echo esc_attr( $dze_key ); ?>"
									<?php checked( in_array( $dze_key, $dze_picked, true ) ); ?> />
								<?php echo esc_html( $dze_one['label'] ); ?>
								<?php if ( ! empty( $dze_one['attr'] ) ) : ?>
									<span class="description"><?php esc_html_e( '· product attribute', 'dazont-ecom' ); ?></span>
								<?php endif; ?>
								<?php if ( self::is_default( $dze_key ) ) : ?>
									<span class="description"><?php esc_html_e( '· on by default', 'dazont-ecom' ); ?></span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
						<p class="description">
							<?php esc_html_e( 'An attribute is only ever translated when a product uses it — an attribute value nothing is tagged with is a page nobody can reach, and it is never sent.', 'dazont-ecom' ); ?>
						</p>
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
					<th scope="row"><?php esc_html_e( 'When it translates', 'dazont-ecom' ); ?></th>
					<td>
						<?php
						// DEUX TRAVAUX, PAS UN. « Il faut la possibilite de choisir :
						// traduction des nouveaux posts, mise a jour des anciens, les
						// deux. » Une page qui n existe dans aucune langue est un trou
						// dans le catalogue ; une page dont la source a bouge est de
						// l entretien. WPML repond deja aux deux questions, donc c est
						// un filtre sur sa reponse et jamais un calcul a nous.
						$dze_when = self::when();
						foreach ( [
							'both'   => __( 'Both — fill the gaps and keep them up to date', 'dazont-ecom' ),
							'new'    => __( 'Only what has never been translated into a language', 'dazont-ecom' ),
							'update' => __( 'Only translations WPML marks as out of date', 'dazont-ecom' ),
						] as $dze_k => $dze_lbl ) :
						?>
							<label style="display:block;margin-bottom:3px;">
								<input type="radio" name="<?php echo esc_attr( self::OPT ); ?>[when]" value="<?php echo esc_attr( $dze_k ); ?>" <?php checked( $dze_k, $dze_when ); ?> />
								<?php echo esc_html( $dze_lbl ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'This narrows what the automatic pass picks up and what the Translations screen lists as owed. It never changes what WPML itself considers translated.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'How it is sent', 'dazont-ecom' ); ?></th>
					<td>
						<?php
						// « Je n'attendrais en aucun cas 24h pour des traductions. »
						// The price and the wait, side by side: the choice is
						// between the two, and the screen says both.
						$dze_lane = self::lane();
						foreach ( [
							'direct' => __( 'Right away — sent in the background a few at a time; each language is back within a minute or two. Normal price.', 'dazont-ecom' ),
							'batch'  => __( 'In batches, at half price — Anthropic answers when it has room: often minutes, sometimes hours, 24 hours at most.', 'dazont-ecom' ),
						] as $dze_k => $dze_lbl ) :
						?>
							<label style="display:block;margin-bottom:3px;">
								<input type="radio" name="<?php echo esc_attr( self::OPT ); ?>[lane]" value="<?php echo esc_attr( $dze_k ); ?>" <?php checked( $dze_k, $dze_lane ); ?> />
								<?php echo esc_html( $dze_lbl ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'This is what the Translations screen opens on and what the automatic pass uses. Each send can choose for itself, and « Translate the rest right away » moves a slow cheap send over while it waits.', 'dazont-ecom' ); ?></p>
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
					<th scope="row"><label for="dze-tr-prompt"><?php esc_html_e( 'Translation prompt', 'dazont-ecom' ); ?></label></th>
					<td>
						<textarea id="dze-tr-prompt" name="<?php echo esc_attr( self::OPT ); ?>[prompt]" rows="10" class="large-text code"><?php echo esc_textarea( self::prompt() ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Empty = shipped default (shown greyed). The target language and the answer format are added automatically.', 'dazont-ecom' ); ?>
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
		return self::stale_from( $o, $lang, self::obj_read( $o ) );
	}

	/**
	 * The same question, asked of texts already read — so a screen weighing
	 * five languages of one object reads the object once, not five times.
	 *
	 * @param array<string,string> $texts What `obj_read()` gave for this object.
	 * @return array<string,string>
	 */
	public static function stale_from( array $o, string $lang, array $texts ): array {
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
		$raw = self::raw_meta( $o, self::META_WAIT );
		$row = '' !== $raw ? json_decode( $raw, true ) : [];
		return is_array( $row ) && ! empty( $row['langs'] ) ? $row : [];
	}

	/**
	 * Stores what came back, against the source it came from.
	 *
	 * @param int   $by       Who asked for it; -1 for the person pressing now.
	 *                        A translation that comes back from the queue was
	 *                        asked for by whoever sent it, not by « Automatic ».
	 * @param array $per_lang Language => the words sent FOR THAT language.
	 * @param int   $at       When it was produced; 0 for now.
	 */
	public static function hold( array $o, array $langs, array $source, int $by = -1, array $per_lang = [], int $at = 0 ): void {
		if ( ! $o || ! $langs ) {
			return;
		}
		self::meta_write( $o, (int) $o['id'], self::META_WAIT, (string) wp_json_encode( [
			'at'    => $at > 0 ? $at : time(),
			// ET QUI L A DEMANDE. Une file partagee qui ne nomme personne fait
			// relancer deux fois le meme objet par deux personnes, et l une des
			// deux traductions part a la poubelle apres avoir ete payee.
			'by'    => $by >= 0 ? $by : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ),
			'langs' => $langs,
			// WHAT IT WAS TRANSLATED FROM. Accepting a week later must write
			// the register against the words that were actually sent, not
			// against a source somebody has edited since — otherwise the
			// register claims a field is current when it is not.
			'src'   => $source,
			// AND FOR EACH LANGUAGE, THE WORDS SENT FOR IT. Languages arrive at
			// different times now; one shared source made an older language
			// register against words sent later, and the register then called
			// a translation current that had been made from the old text.
			'srcl'  => array_intersect_key( $per_lang, $langs ),
		] ) );
	}

	/**
	 * ONE LANGUAGE THROWN AWAY, the others kept.
	 *
	 * « Discard » on the page of one language used to throw away every
	 * language waiting on the object — five translations for one press.
	 */
	public static function drop_wait_lang( array $o, string $lang ): void {
		$held = self::waiting( $o );
		$left = (array) ( $held['langs'] ?? [] );
		unset( $left[ $lang ] );
		if ( ! $left ) {
			self::drop_wait( $o );
			return;
		}
		self::hold( $o, $left, (array) ( $held['src'] ?? [] ), (int) ( $held['by'] ?? -1 ), (array) ( $held['srcl'] ?? [] ), (int) ( $held['at'] ?? 0 ) );
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
	public static function produce( array $o, array $langs, bool $all = false, string $only = '' ): array {
		return self::produce_set( [ 'one' => [ 'o' => $o, 'langs' => $langs ] ], $all, $only )['one'];
	}

	/**
	 * SEVERAL OBJECTS, EVERY LANGUAGE ASKED, IN ONE WAVE OF CALLS.
	 *
	 * « Pourquoi prendre autant de temps quand on peut les traduire en même
	 * temps ? » Each object and each language used to wait for the one before.
	 * What each one SENDS is decided exactly as before — only the fields whose
	 * words moved, or every field when asked for it — and then every call
	 * leaves together through `translate_many()`.
	 *
	 * @param array<string,array{o:array,langs:string[],all?:bool}> $items `all`
	 *        on an item sends every field of that one, as `$all` does for all.
	 * @return array<string,array{langs:array<string,array<string,string>>,skipped:string[],errors:array<string,string>,cost:bool}>
	 */
	public static function produce_set( array $items, bool $all = false, string $only = '' ): array {
		// ONE FIELD AT A TIME, FOR CALIBRATING. "Pour un calibrage plus facile
		// il faut un bouton traduire par bloc." Asked for one field, this sends
		// that one and nothing else — and it reads it from the object rather
		// than from what has MOVED, because a field is re-run precisely when
		// it has not.
		$only  = sanitize_key( $only );
		$res   = [];
		$tasks = [];
		$whose = [];
		foreach ( $items as $ik => $it ) {
			$res[ $ik ] = [ 'langs' => [], 'skipped' => [], 'errors' => [], 'cost' => false ];
			$o          = (array) ( $it['o'] ?? [] );
			if ( ! $o ) {
				continue;
			}
			// $all IS FOR JUDGING THE TRANSLATOR, OR FOR REPLACING WHAT IS THERE.
			// Changing the model or the instructions changes nothing about the
			// ORIGINAL, so the register is right to say nothing moved. Asked for
			// everything, it sends everything and pays for everything.
			$every   = $all || ! empty( $it['all'] );
			$targets = self::obj_targets( $o );
			$read    = null;
			$labels  = null;
			foreach ( (array) ( $it['langs'] ?? [] ) as $lang ) {
				$lang = sanitize_key( (string) $lang );
				if ( '' === $lang || ! isset( $targets[ $lang ] ) ) {
					// IT FAILS LOUDLY RATHER THAN QUIETLY. A language asked for
					// and not recognised used to be skipped in silence — so when
					// `wpml_active_languages` answered nothing in admin-ajax, the
					// screen concluded "nothing had moved on any of them" on a
					// shop that had translated nothing at all.
					$res[ $ik ]['errors'][ $lang ?: '?' ] = sprintf(
						/* translators: %s: the language code that was asked for */
						__( 'WPML does not offer %s as a translation of this one. Check that the language is active in WPML.', 'dazont-ecom' ),
						strtoupper( (string) $lang )
					);
					continue;
				}
				// ONLY WHAT ACTUALLY MOVED IS PAID FOR — the whole reason this
				// module exists beside WPML's own automatic translation.
				if ( $every || '' !== $only ) {
					$read  = $read ?? self::obj_read( $o );
					$texts = $read;
				} else {
					$texts = self::obj_stale( $o, $lang );
				}
				if ( '' !== $only ) {
					$texts = array_intersect_key( $texts, [ $only => true ] );
					if ( ! $texts ) {
						$res[ $ik ]['errors'][ $lang ] = __( 'That field holds no text on the original, so there is nothing to send.', 'dazont-ecom' );
						continue;
					}
				}
				if ( ! $texts ) {
					// Nothing to send. Only the ordinary run may call that
					// settled: an empty answer to "translate everything" means
					// the original holds no text at all, which settles nothing.
					// A SINGLE FIELD NEVER SETTLES A LANGUAGE.
					if ( ! $every && '' === $only ) {
						self::obj_settle( $o, $lang );
					}
					$res[ $ik ]['skipped'][] = $lang;
					continue;
				}
				$labels = $labels ?? self::labels_for( $o );
				$tk     = $ik . '|' . $lang;
				// FILED ON THE PRODUCT, so the call can be read where the bad
				// translation is read — and on its KIND, so the cost screen can
				// say what the translation money went on.
				$tasks[ $tk ] = [
					'texts'   => $texts,
					'lang'    => $lang,
					'kind'    => (string) $o['kind'],
					'names'   => $labels,
					'subject' => sanitize_key( (string) ( $o['type'] ?? '' ) ),
					'about'   => 'post' === (string) ( $o['kind'] ?? '' ) ? (int) $o['id'] : 0,
				];
				$whose[ $tk ] = [ $ik, $lang ];
			}
		}
		if ( ! $tasks ) {
			return $res;
		}
		try {
			$got = self::translate_many( $tasks );
		} catch ( \Throwable $e ) {
			$got = [];
			foreach ( $whose as $tk => $w ) {
				$res[ $w[0] ]['errors'][ $w[1] ] = $e->getMessage();
			}
		}
		$source = [];
		$srcl   = [];
		foreach ( $whose as $tk => $w ) {
			[ $ik, $lang ] = $w;
			if ( isset( $res[ $ik ]['errors'][ $lang ] ) ) {
				continue;
			}
			$one = $got[ $tk ] ?? [ 'texts' => [], 'error' => '' ];
			if ( ! $one['texts'] ) {
				$res[ $ik ]['errors'][ $lang ] = '' !== (string) $one['error'] ? (string) $one['error'] : __( 'Nothing came back.', 'dazont-ecom' );
				continue;
			}
			$res[ $ik ]['cost']          = true;
			$res[ $ik ]['langs'][ $lang ] = $one['texts'];
			$source[ $ik ]               = ( $source[ $ik ] ?? [] ) + $tasks[ $tk ]['texts'];
			$srcl[ $ik ][ $lang ]        = $tasks[ $tk ]['texts'];
		}
		foreach ( $source as $ik => $src ) {
			$o    = (array) $items[ $ik ]['o'];
			// IT MERGES, IT DOES NOT REPLACE. Languages arrive one pass at a
			// time now, and a pass that overwrote what was waiting threw away
			// the languages before it: "De toutes les catégories que j'ai
			// envoyées, je ne vois que Alien patches DE sur la liste review."
			// A language that comes back again replaces ITS OWN fields — or,
			// for a one-field run, only that field.
			$held = self::waiting( $o );
			$keep = (array) ( $held['langs'] ?? [] );
			$par  = (array) ( $held['srcl'] ?? [] );
			foreach ( $res[ $ik ]['langs'] as $lg => $fields ) {
				$keep[ $lg ] = '' !== $only
					? array_merge( (array) ( $keep[ $lg ] ?? [] ), (array) $fields )
					: (array) $fields;
				$par[ $lg ]  = '' !== $only
					? array_merge( (array) ( $par[ $lg ] ?? [] ), (array) ( $srcl[ $ik ][ $lg ] ?? [] ) )
					: (array) ( $srcl[ $ik ][ $lg ] ?? [] );
			}
			self::hold( $o, $keep, array_merge( (array) ( $held['src'] ?? [] ), $src ), -1, $par );
		}
		return $res;
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
	public static function accept( array $o, array $keep, int $by = -1 ): array {
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
			// THE WORDS SENT FOR THIS LANGUAGE, when they were kept apart.
			$sent_for = isset( $held['srcl'][ $lang ] ) ? (array) $held['srcl'][ $lang ] : $source;
			// LES LIENS SUIVENT LA LANGUE. Juste avant l ecriture, parce que ce
			// qui est ecrit est ce qui compte : une correction faite plus tot
			// serait defaite par une relecture a la main, et le modele, lui, a
			// raison de ne pas inventer d adresse.
			foreach ( $texts as $fid => $val ) {
				if ( is_string( $val ) && false !== stripos( $val, '<a ' ) ) {
					$texts[ $fid ] = self::relink( $val, $lang );
				}
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
			// ET QUI A DIT OUI, a cote du drapeau plutot qu a sa place : les
			// requetes qui listent le travail fait se servent de ce drapeau
			// comme d une presence, et un identifiant a la place le casserait.
			self::meta_write( $o, $target, self::META_WHO, (string) ( $by >= 0 ? $by : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ) ) );
			// THE REGISTER IS WRITTEN AGAINST WHAT WAS SENT, never against the
			// source as it stands now: accepting a batch a week later must not
			// claim a field is current when somebody has edited it since.
			// A FIELD TYPED BY HAND was sent nowhere, so nothing was kept for
			// it — and left out of the register it stayed "words have moved"
			// for ever and the next batch paid to translate it again. The
			// person typing it read the original on the screen in front of
			// them, so THAT is the source it was made from.
			$reg     = array_intersect_key( $sent_for, $texts );
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
			// UNE CIBLE DE PLUS EST DISPONIBLE : voir relink_sweep().
			foreach ( array_keys( $out['written'] ) as $dze_l ) {
				self::relink_sweep( (string) $dze_l );
			}
		}
		// A language left out of the decision is still waiting; only a clean
		// sweep clears the object off the list.
		$left = array_diff_key( (array) ( $held['langs'] ?? [] ), $out['written'] );
		if ( $left ) {
			// WHAT IS STILL WAITING KEEPS ITS DATE AND ITS AUTHOR.
			self::hold( $o, $left, $source, (int) ( $held['by'] ?? -1 ), (array) ( $held['srcl'] ?? [] ), (int) ( $held['at'] ?? 0 ) );
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
	/**
	 * WHAT THIS MODULE HAS ACTUALLY WRITTEN, newest first.
	 *
	 * "Comment voir le résultat des traductions, quelles pages ?" You could
	 * not. A translation does not go through the writing queue — it waits on
	 * the source object and is decided on this screen — so the log of
	 * automatic passes knew nothing about it either, and nothing anywhere
	 * listed a finished translation.
	 *
	 * The mark is already on every translation this module wrote
	 * (`_dze_tr_by`), put there at the moment of writing. This reads it back.
	 *
	 * @return array<int,array{id:int,kind:string,label:string,lang:string,when:string,edit:string,view:string}>
	 */
	/** Language of a term, read by the key WPML indexes: its term_taxonomy_id. */
	public static function term_language( int $term_id, string $taxonomy ): string {
		if ( ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			return '';
		}
		$ttid = DZE_Wpml::term_element_id( $term_id, $taxonomy );
		if ( ! $ttid ) {
			return '';
		}
		$lang = apply_filters( 'wpml_element_language_code', null, [
			'element_id'   => $ttid,
			'element_type' => 'tax_' . $taxonomy,
		] );
		return is_string( $lang ) ? $lang : '';
	}

	public static function done_list( int $limit = 200 ): array {
		global $wpdb;
		$out = [];
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_type, p.post_title, p.post_modified
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			  WHERE p.post_status IN ('publish','private','draft')
			  ORDER BY p.post_modified DESC
			  LIMIT %d",
			self::META_MINE,
			max( 1, $limit )
		), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$id  = (int) $r['ID'];
			$out[] = [
				'id'    => $id,
				'kind'  => (string) $r['post_type'],
				'label' => (string) $r['post_title'],
				'lang'  => strtoupper( (string) DZE_Wpml::post_language( $id, (string) $r['post_type'] ) ),
				'when'  => (string) $r['post_modified'],
				'edit'  => (string) get_edit_post_link( $id, '' ),
				'view'  => (string) get_permalink( $id ),
				'by'    => self::who_wrote( [ 'kind' => 'post', 'id' => $id, 'type' => (string) $r['post_type'] ], $id ),
			];
		}
		// AND THE TERMS, which keep their mark in term meta and have no
		// post_modified to sort by — so they come after, newest id first.
		$tr = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT t.term_id, t.name, tt.taxonomy
			   FROM {$wpdb->terms} t
			   JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			   JOIN {$wpdb->termmeta} m ON m.term_id = t.term_id AND m.meta_key = %s
			  ORDER BY t.term_id DESC
			  LIMIT %d",
			self::META_MINE,
			max( 1, $limit )
		), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $tr as $r ) {
			$tid = (int) $r['term_id'];
			$tax = (string) $r['taxonomy'];
			$lnk = get_term_link( $tid, $tax );
			$out[] = [
				'id'    => $tid,
				'kind'  => $tax,
				'label' => (string) $r['name'],
				'lang'  => strtoupper( (string) self::term_language( $tid, $tax ) ),
				'when'  => '',
				'edit'  => (string) get_edit_term_link( $tid, $tax ),
				'view'  => is_wp_error( $lnk ) ? '' : (string) $lnk,
				'by'    => self::who_wrote( [ 'kind' => 'term', 'id' => $tid, 'type' => $tax ], $tid ),
			];
		}
		return $out;
	}

	/**
	 * QUI A ÉCRIT CETTE TRADUCTION, prêt à afficher — ou rien du tout.
	 *
	 * Trois réponses, et elles ne se confondent pas : un nom, « Automatic »
	 * quand c'est la passe qui tourne seule, et RIEN quand la clé n'existe
	 * pas — un objet traduit avant que ce soit gardé a bien été accepté par
	 * quelqu'un, simplement personne ne l'a écrit, et le nommer serait
	 * inventer. C'est la même règle que sur le banc des produits.
	 */
	private static function who_wrote( array $o, int $id ): ?string {
		$raw = self::meta_read( $o, $id, self::META_WHO );
		if ( '' === $raw ) {
			return null;
		}
		return class_exists( 'DZE_Queue' )
			? DZE_Queue::started_by( (int) $raw )
			: (string) $raw;
	}

	/**
	 * A TERM THE SHOP NEVER NAMED IS NOT COPY TO TRANSLATE.
	 *
	 * Every taxonomy carries a fallback term nobody chose — WordPress's
	 * default category, WooCommerce's default product category — and it is
	 * there to catch a post that was filed nowhere, not to be read by a
	 * customer. Sent for translation it costs a call per language, comes back
	 * as "Uncategorized" in five spellings, and fills the review list with
	 * rows whose only honest decision is to ignore them.
	 *
	 * The designated default is read from the options WordPress and
	 * WooCommerce keep for exactly this, never guessed from a slug: a slug is
	 * whatever the install's language made it, and matching on one would skip
	 * a real category on a shop that happens to sell filing cabinets.
	 */
	/**
	 * UNE CATÉGORIE VIDE N'EST PAS UNE PAGE À TRADUIRE.
	 *
	 * « Army rank patches → le plugin a encore traduit une catégorie avec 0
	 * produits. »
	 *
	 * Un terme qui ne porte rien n'a pas de page qu'un client atteindra : son
	 * archive est vide, elle ne se range dans aucun menu, et Google n'a aucune
	 * raison de l'indexer. La traduire coûte un appel par langue pour une page
	 * que personne ne verra jamais — sur cette boutique, 114 catégories
	 * produit sur 859 sont dans ce cas, soit 456 appels à ne pas passer.
	 *
	 * LA DESCENDANCE COMPTE. Une catégorie de tête ne porte souvent aucun
	 * produit elle-même et tout son rayon dessous : « Patches » est vide et
	 * ses six enfants ne le sont pas. Elle a donc bien une page, et un nom que
	 * le client lit dans le fil d'Ariane. On descend jusqu'à six niveaux, ce
	 * qui est déjà deux fois plus qu'aucune boutique n'en utilise.
	 *
	 * ET ON COMPTE LES RATTACHEMENTS, PAS LE COMPTEUR. `tt.count` est un cache
	 * que WooCommerce recalcule quand il y pense ; il reste à zéro sur une
	 * catégorie qu'on vient de remplir, et non nul sur une qu'on vient de
	 * vider. Un terme effacé de la liste sur la foi d'un compteur périmé est un
	 * terme qui ne sera jamais traduit et dont personne ne saura pourquoi.
	 */
	public static function is_empty_term( int $term_id, string $taxonomy ): bool {
		global $wpdb;
		if ( $term_id < 1 || ! $wpdb ) {
			return false;
		}
		$carries = static function ( array $ids ) use ( $wpdb ): int {
			if ( ! $ids ) {
				return 0;
			}
			$in = implode( ',', array_map( 'intval', $ids ) );
			return (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids cast to int just above.
				"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
				   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				  WHERE tt.term_id IN ({$in})"
			);
		};
		if ( $carries( [ $term_id ] ) > 0 ) {
			return false;
		}
		$level = [ $term_id ];
		for ( $deep = 0; $deep < 6; $deep++ ) {
			$in   = implode( ',', array_map( 'intval', $level ) );
			$kids = (array) $wpdb->get_col( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids cast to int just above.
				"SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND parent IN ({$in})",
				$taxonomy
			) );
			if ( ! $kids ) {
				return true;
			}
			if ( $carries( $kids ) > 0 ) {
				return false;
			}
			$level = $kids;
		}
		return true;
	}

	public static function is_default_term( int $term_id, string $taxonomy ): bool {
		if ( $term_id < 1 ) {
			return false;
		}
		$option = [
			'category'    => 'default_category',
			'product_cat' => 'default_product_cat',
		][ $taxonomy ] ?? '';
		return '' !== $option && $term_id === (int) get_option( $option );
	}

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
			// QUI L A DEMANDE. Absent sur une ligne mise en attente avant que
			// ce soit garde : la clé manque, et l'écran le dit plutôt que de
			// nommer quelqu'un au hasard.
			'by'    => array_key_exists( 'by', (array) $held ) ? (int) $held['by'] : null,
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

	/**
	 * COMBIEN D'APPELS PARTENT ENSEMBLE.
	 *
	 * « Pourquoi prendre autant de temps quand on peut les traduire en même
	 * temps ? » Assez pour qu'une page en cinq langues revienne dans le temps
	 * d'un seul appel ; pas assez pour qu'une boutique sur un petit forfait se
	 * fasse répondre « trop de demandes ». Un 429 n'est de toute façon pas un
	 * échec : il est redemandé seul, après la pause que le fournisseur indique.
	 */
	public const PARALLEL = 6;

	public static function translate( array $texts, string $lang_code, string $kind = 'post', array $names = [] ): array {
		if ( ! $texts ) {
			return [];
		}
		$got = self::translate_many( [
			'one' => [ 'texts' => $texts, 'lang' => $lang_code, 'kind' => $kind, 'names' => $names, 'subject' => self::$subject ],
		] );
		$one = $got['one'] ?? [ 'texts' => [], 'error' => '' ];
		// Nothing at all came back: the reason the last call gave is worth
		// more than an empty array the screen has to guess at.
		if ( ! $one['texts'] && '' !== $one['error'] ) {
			throw new RuntimeException( $one['error'] );
		}
		return $one['texts'];
	}

	/**
	 * HOW ONE TEXT IS CUT INTO THE CALLS THAT CARRY IT.
	 *
	 * Short fields travel together, because a title and the description under
	 * it have to choose the same words. A field too long to travel with
	 * anything is cut on paragraph boundaries and named by its piece.
	 *
	 * @param array<string,string> $texts
	 * @param array<string,string> $names
	 * @return array{jobs:array<int,array<string,string>>,parts:array<string,int>,names:array<string,string>}
	 */
	private static function plan( array $texts, array $names ): array {
		$jobs  = [];
		$parts = [];
		// WHAT HAS NO WORD IN IT IS NOT SENT: a piece that is only a line break
		// or an image, a field that is only a number. Sent alone, the model
		// answered « I don't see any fields to translate » and the whole
		// description was lost. It is kept as it is, in any language.
		$keep  = [];
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
					if ( ! self::has_words( $piece ) ) {
						$keep[ $key ] = $piece;
						continue;
					}
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
			if ( ! self::has_words( $v ) ) {
				$keep[ $fid ] = $v;
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
		return [ 'jobs' => $jobs, 'parts' => $parts, 'names' => $names, 'keep' => $keep ];
	}

	/** Does this text hold a single word to translate — a letter, not only tags, spaces or figures? */
	private static function has_words( string $v ): bool {
		$plain = html_entity_decode( wp_strip_all_tags( $v ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return (bool) preg_match( '/\p{L}/u', $plain );
	}

	/**
	 * SEVERAL TRANSLATIONS AT ONCE — the languages of one object, or the
	 * objects of one pass of the queue.
	 *
	 * « C'est trop long. Pourquoi prendre autant de temps quand on peut les
	 * traduire en même temps ? Ça n'a aucun sens. » It made none: a page in
	 * five languages was five calls of a minute each, one after the other.
	 *
	 * ROUND ONE sends every call of every task together, `PARALLEL` at a time,
	 * so they come back in the time of the slowest. What comes back unusable
	 * then takes THE CAREFUL ROAD, exactly as a single translation always has:
	 * a batch is halved rather than asked again whole — it was already asked
	 * once — and a piece of a long text that did not come back is asked for
	 * again, once. One road for one language or fifty: `translate()` is this
	 * function with a single task.
	 *
	 * @param array<string,array{texts:array<string,string>,lang:string,kind?:string,names?:array<string,string>,subject?:string,about?:int}> $tasks
	 * @return array<string,array{texts:array<string,string>,error:string}>
	 */
	public static function translate_many( array $tasks ): array {
		if ( ! class_exists( 'DZE_Marketing_Ai' ) ) {
			throw new RuntimeException( __( 'The Marketing Assistant module holds the Anthropic key — switch it back on.', 'dazont-ecom' ) );
		}
		$out   = [];
		$plans = [];
		foreach ( $tasks as $tk => $t ) {
			$texts = (array) ( $t['texts'] ?? [] );
			if ( ! $texts ) {
				$out[ $tk ] = [ 'texts' => [], 'error' => '' ];
				continue;
			}
			// EVERY FIELD IS NAMED FOR THE MODEL, the custom ones and the
			// variations included: handed `meta:_theme_subtitle` and nothing
			// else it has to guess what kind of text it is looking at.
			$names = (array) ( $t['names'] ?? [] );
			if ( ! $names ) {
				foreach ( self::fields( (string) ( $t['kind'] ?? 'post' ) ) as $fid => $f ) {
					$names[ $fid ] = (string) $f['label'];
				}
			}
			$subject      = sanitize_key( (string) ( $t['subject'] ?? '' ) );
			$plans[ $tk ] = self::plan( $texts, $names ) + [
				'lang'    => (string) ( $t['lang'] ?? '' ),
				'texts'   => $texts,
				'subject' => $subject,
				'unit'    => 'translate' . ( '' !== $subject ? ':' . $subject : '' ),
				'about'   => max( 0, (int) ( $t['about'] ?? 0 ) ),
			];
		}

		// ---- ROUND ONE: every call of every task, several at a time ----
		$first = [];   // task => job index => fields, or null when it failed whole
		$last  = [];   // task => the last reason a call gave
		$queue = [];
		foreach ( $plans as $tk => $p ) {
			foreach ( array_keys( $p['jobs'] ) as $i ) {
				$queue[] = [ $tk, $i ];
			}
		}
		if ( count( $queue ) > 1 && method_exists( 'DZE_Marketing_Ai', 'complete_many' ) ) {
			foreach ( array_chunk( $queue, self::PARALLEL ) as $wave ) {
				$req = [];
				foreach ( $wave as $n => $one ) {
					[ $tk, $i ] = $one;
					$p          = $plans[ $tk ];
					$req[ 'w' . $n ] = self::batch_ask( $p['jobs'][ $i ], $p['lang'], $p['names'] )
						+ [ 'unit' => $p['unit'], 'about' => $p['about'] ];
				}
				try {
					$answers = DZE_Marketing_Ai::complete_many( $req, self::model(), 180 );
				} catch ( \Throwable $e ) {
					// The budget reached, the key missing: every call of the
					// wave fails for that same reason, and says it.
					$answers = array_fill_keys( array_keys( $req ), $e );
				}
				foreach ( $wave as $n => $one ) {
					[ $tk, $i ] = $one;
					$a          = $answers[ 'w' . $n ] ?? null;
					if ( is_string( $a ) ) {
						try {
							$first[ $tk ][ $i ] = self::batch_read( $a, $plans[ $tk ]['jobs'][ $i ] );
							continue;
						} catch ( \Throwable $e ) {
							$a = $e;
						}
					}
					$last[ $tk ]        = $a instanceof \Throwable ? $a : new RuntimeException( __( 'Nothing came back.', 'dazont-ecom' ) );
					$first[ $tk ][ $i ] = null;
				}
			}
		}

		// ---- THE CAREFUL ROAD, then the pieces put back together ----
		foreach ( $plans as $tk => $p ) {
			$avant         = self::$subject;
			self::$subject = $p['subject'];
			if ( $p['about'] > 0 ) {
				DZE_Ai_Usage::about( $p['about'] );
			}
			try {
				$lang  = $p['lang'];
				$names = $p['names'];
				$texts = $p['texts'];
				$lst   = $last[ $tk ] ?? null;
				// A BATCH THAT COMES BACK UNUSABLE TAKES ITS OWN FIELDS DOWN,
				// NOT THE WHOLE OBJECT. An Elementor page of 63 fields once
				// translated nothing because its seventh call answered badly,
				// on a page whose first fifty-four fields were already paid for.
				$bag = (array) ( $p['keep'] ?? [] ); // what has no word in it comes back as it went.
				foreach ( $p['jobs'] as $i => $job ) {
					if ( isset( $first[ $tk ] ) && array_key_exists( $i, $first[ $tk ] ) ) {
						$got = $first[ $tk ][ $i ];
						if ( null === $got ) {
							// ASKED ONCE WHOLE ALREADY: straight to the halves,
							// never a second full-price attempt at the same thing.
							$got = count( $job ) < 2 ? [] : self::run_halves( $job, $lang, $names, $lst );
						}
					} else {
						$got = self::run_job( $job, $lang, $names, $lst );
					}
					foreach ( $got as $k => $v ) {
						$bag[ $k ] = $v;
					}
				}
				// A PIECE THAT DID NOT COME BACK IS ASKED FOR AGAIN, once. Half a
				// description is worse than none: the rest of the object
				// translates and this one field stays as it was.
				foreach ( $p['parts'] as $fid => $n ) {
					for ( $k = 0; $k < $n; $k++ ) {
						$key = $fid . self::PART . $k;
						if ( '' !== trim( (string) ( $bag[ $key ] ?? '' ) ) || isset( $p['keep'][ $key ] ) ) {
							continue;
						}
						$piece = self::split_text( (string) $texts[ $fid ], self::CHUNK )[ $k ] ?? '';
						if ( '' === $piece ) {
							continue;
						}
						// AND THIS ASK IS PROTECTED LIKE ANY OTHER: a piece that
						// came back badly TWICE must not throw past the fields
						// that translated perfectly well and were paid for.
						try {
							$again = self::translate_batch( [ $key => $piece ], $lang, $names );
						} catch ( \Throwable $e ) {
							$lst = $e;
							continue;
						}
						if ( isset( $again[ $key ] ) ) {
							$bag[ $key ] = $again[ $key ];
						}
					}
				}
				DZE_Ai_Usage::finished( 'translate' );
				$res = [];
				foreach ( $texts as $fid => $_ ) {
					if ( isset( $p['parts'][ $fid ] ) ) {
						$whole = '';
						for ( $k = 0; $k < $p['parts'][ $fid ]; $k++ ) {
							$piece = (string) ( $bag[ $fid . self::PART . $k ] ?? '' );
							if ( '' === trim( $piece ) && ! isset( $p['keep'][ $fid . self::PART . $k ] ) ) {
								$whole = '';
								break;
							}
							$whole .= $piece;
						}
						if ( '' !== trim( $whole ) ) {
							$res[ $fid ] = $whole;
						}
						continue;
					}
					$v = isset( $bag[ $fid ] ) ? (string) $bag[ $fid ] : '';
					if ( '' !== trim( $v ) ) {
						$res[ $fid ] = $v;
					}
				}
				$out[ $tk ] = [
					'texts' => $res,
					'error' => ( ! $res && $lst instanceof \Throwable ) ? $lst->getMessage() : '',
				];
			} finally {
				self::$subject = $avant;
				if ( $p['about'] > 0 ) {
					DZE_Ai_Usage::about();
				}
			}
		}
		// IN THE ORDER ASKED, every task answered.
		$sorted = [];
		foreach ( array_keys( $tasks ) as $tk ) {
			$sorted[ $tk ] = $out[ $tk ] ?? [ 'texts' => [], 'error' => '' ];
		}
		return $sorted;
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
	 * One batch, and what to do when it comes back unusable.
	 *
	 * A BAD ANSWER TAKES ITS OWN FIELDS DOWN, NOT THE WHOLE OBJECT. Cutting the
	 * work into pieces multiplies the chances that one comes back malformed, and
	 * an Elementor page of 63 fields translated nothing at all because the
	 * seventh call answered badly — on a page whose first fifty-four fields were
	 * already translated and paid for.
	 *
	 * It is halved rather than taken apart. Asking again field by field turns
	 * one bad answer into fifty-four calls, which is a bill the shop did not
	 * ask for; halving finds the field that will not translate in a handful.
	 *
	 * @param array<string,string> $job
	 * @param array<string,string> $names
	 * @return array<string,string>
	 */
	private static function run_job( array $job, string $lang_code, array $names, ?\Throwable &$last ): array {
		try {
			return self::translate_batch( $job, $lang_code, $names );
		} catch ( \Throwable $e ) {
			$last = $e;
		}
		if ( count( $job ) < 2 ) {
			// One field, asked twice, that still will not come back. It is left
			// as it was, which the screen already shows as untranslated.
			return [];
		}
		return self::run_halves( $job, $lang_code, $names, $last );
	}

	/** The two halves of a batch, each on the careful road of its own. */
	private static function run_halves( array $job, string $lang_code, array $names, ?\Throwable &$last ): array {
		$half = (int) ceil( count( $job ) / 2 );
		return self::run_job( array_slice( $job, 0, $half, true ), $lang_code, $names, $last )
			+ self::run_job( array_slice( $job, $half, null, true ), $lang_code, $names, $last );
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
	/**
	 * LA REPONSE DU MODELE, LUE COMME UNE TABLE — deux pieges, une seule fois.
	 *
	 *   - UNE PHRASE AVANT L OBJET N EST PAS UN ECHEC. Le modele encadre parfois
	 *     son JSON d une cloture de code ou d une politesse ; on prend ce qui
	 *     est entre la premiere accolade et la derniere.
	 *   - LA CLE EST L IDENTIFIANT, QUELLE QUE SOIT SA FORME. Chaque champ part
	 *     en « ### post:content (Corps de l article) » — l identifiant pour
	 *     nous, le nom pour le modele — et il revient de temps en temps avec la
	 *     LIGNE ENTIERE comme cle, parenthese comprise. Un article de mille six
	 *     cents mots est deja parti en quatre morceaux dont trois ont ete
	 *     gardes et le quatrieme jete pour une cle que le modele ne pouvait pas
	 *     savoir fausse. On garde donc les deux formes.
	 *
	 * @param int|null $nb Combien de lignes le modele a REELLEMENT rendues —
	 *                     pas la taille de la table, qui porte deux entrees par
	 *                     ligne quand la cle est venue habillee.
	 * @return array<string,string>
	 */
	private static function decode_map( string $raw, ?int &$nb = null, array $keys = [] ): array {
		$json = trim( (string) preg_replace( '/^```(?:json)?|```$/m', '', $raw ) );
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) ) {
			$a = strpos( $json, '{' );
			$b = strrpos( $json, '}' );
			if ( false !== $a && false !== $b && $b > $a ) {
				$rows = json_decode( substr( $json, $a, $b - $a + 1 ), true );
			}
		}
		// NOT STRICT JSON, BUT THE FIELDS ARE THERE — read by the keys we sent.
		// « „SSO" », « „taktisches Werkzeug" »: German, Polish and Russian
		// quotes close on a straight " the model does not escape, and one of
		// them threw away a whole translated description. Twenty answers of one
		// send were lost that way, and one more said « Wait, let me redo this »
		// between two answers. The keys are ours, so each value is cut between
		// its own key and the next one.
		if ( ! is_array( $rows ) && $keys ) {
			$rows = self::salvage_map( $json, $keys );
			$rows = $rows ? $rows : null;
		}
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( __( 'The model did not answer with the expected format.', 'dazont-ecom' ) );
		}
		$nb = count( $rows );
		$by = [];
		foreach ( $rows as $k => $v ) {
			if ( ! is_string( $v ) ) {
				continue;
			}
			$by[ (string) $k ] = $v;
			$bare = trim( (string) preg_replace( '/\s*\(.*$/s', '', (string) $k ) );
			if ( '' !== $bare && ! isset( $by[ $bare ] ) ) {
				$by[ $bare ] = $v;
			}
		}
		return $by;
	}
	/**
	 * AN ANSWER THAT IS NOT QUITE JSON, READ BY THE KEYS WE SENT.
	 *
	 * Each value runs from its key to the next key found after it — or to the
	 * last closing quote before the final brace — so an unescaped quote inside
	 * a translation is text, not the end of it. When the model answered twice,
	 * the LAST answer of each key is the one kept: it is the one it stood by.
	 *
	 * @param string[] $keys
	 * @return array<string,string>
	 */
	private static function salvage_map( string $json, array $keys ): array {
		$at = [];
		foreach ( $keys as $k ) {
			$pat = '/"' . preg_quote( (string) $k, '/' ) . '(?:\s*\([^"]*\))?"\s*:\s*"/u';
			if ( preg_match_all( $pat, $json, $m, PREG_OFFSET_CAPTURE ) ) {
				$last          = end( $m[0] );
				$at[ (string) $k ] = [ (int) $last[1], (int) $last[1] + strlen( (string) $last[0] ) ];
			}
		}
		if ( ! $at ) {
			return [];
		}
		uasort( $at, static fn( array $a, array $b ): int => $a[1] <=> $b[1] );
		$order = array_keys( $at );
		$out   = [];
		foreach ( $order as $i => $k ) {
			$from = $at[ $k ][1];
			$next = $order[ $i + 1 ] ?? null;
			if ( null !== $next ) {
				$seg = substr( $json, $from, $at[ $next ][0] - $from );
				$seg = (string) preg_replace( '/"\s*,\s*$/s', '', $seg );
			} else {
				$seg = substr( $json, $from );
				if ( ! preg_match( '/^(.*)"\s*}/s', $seg, $mm ) ) {
					continue;
				}
				$seg = $mm[1];
			}
			// The JSON escapes it did write are read as JSON; the quotes and line
			// breaks it did not escape are escaped first.
			$esc = (string) preg_replace( '/(?<!\\\\)"/', '\\"', $seg );
			$esc = str_replace( [ "\r\n", "\n", "\r", "\t" ], [ '\n', '\n', '\r', '\t' ], $esc );
			$val = json_decode( '"' . $esc . '"' );
			if ( ! is_string( $val ) ) {
				$val = stripcslashes( $seg );
			}
			if ( '' !== trim( $val ) ) {
				$out[ (string) $k ] = $val;
			}
		}
		return $out;
	}

	private static function translate_batch( array $texts, string $lang_code, array $names ): array {
		if ( ! $texts ) {
			return [];
		}
		$ask = self::batch_ask( $texts, $lang_code, $names );
		DZE_Ai_Usage::unit( 'translate' . ( '' !== self::$subject ? ':' . self::$subject : '' ) );
		try {
			$raw = DZE_Marketing_Ai::complete( $ask['system'], $ask['user'], self::model(), $ask['max'], 180 );
		} finally {
			DZE_Ai_Usage::unit();
		}
		return self::batch_read( (string) $raw, $texts );
	}

	/**
	 * WHAT ONE CALL ASKS: the instructions, the fields, and the room for the
	 * answer. Built in one place, whether the call leaves alone or in a wave.
	 *
	 * @return array{system:string,user:string,max:int}
	 */
	private static function batch_ask( array $texts, string $lang_code, array $names ): array {
		$lines = [];
		$cut   = false;
		foreach ( $texts as $fid => $v ) {
			$lines[] = '### ' . $fid . ' (' . ( $names[ $fid ] ?? $fid ) . ")\n" . $v;
			$cut     = $cut || false !== strpos( (string) $fid, self::PART );
		}
		$system = self::prompt()
			. "\n\nTarget language: " . self::language_name( $lang_code ) . '.'
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
		return [ 'system' => $system, 'user' => $user, 'max' => $max ];
	}

	/**
	 * WHAT ONE ANSWER GIVES BACK, field by field — read in one place too.
	 *
	 * @return array<string,string>
	 */
	private static function batch_read( string $raw, array $texts ): array {
		$nb = 0;
		$by = self::decode_map( $raw, $nb, array_map( 'strval', array_keys( $texts ) ) );
		$out = [];
		foreach ( $texts as $fid => $_ ) {
			$v = isset( $by[ $fid ] ) ? (string) $by[ $fid ] : '';
			// One field asked for, one text back: there is nothing to confuse
			// it with, whatever the key says.
			if ( '' === trim( $v ) && 1 === count( $texts ) && 1 === $nb ) {
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
		// Same as a term: the post was inserted with the ORIGINAL's title, so
		// WordPress derived the original's slug (suffixed, since the original
		// holds it). The mark says that address is nobody's choice yet.
		update_post_meta( $new_id, self::META_SLUG, '1' );

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
	/**
	 * « ENVOYER EN TRADUCTION » DANS LA LISTE DE WORDPRESS.
	 *
	 * Une entrée de plus dans le menu déroulant que WordPress dessine déjà, sur
	 * les types que la boutique a choisi de traduire — et sur la langue source
	 * seulement : envoyer une traduction se faire traduire n'a pas de sens.
	 */
	public function hook_bulk(): void {
		if ( ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		foreach ( self::picked_scope() as $one ) {
			if ( 'term' === (string) ( $one['kind'] ?? '' ) ) {
				continue; // les taxonomies ont leur propre écran, pas edit.php.
			}
			$type = (string) ( $one['type'] ?? '' );
			if ( '' === $type ) {
				continue;
			}
			$ecran = 'product' === $type ? 'edit-product' : 'edit-' . $type;
			add_filter( "bulk_actions-{$ecran}", [ $this, 'bulk_entry' ] );
			add_filter( "handle_bulk_actions-{$ecran}", [ $this, 'bulk_run' ], 10, 3 );
		}
	}

	/** @param array<string,string> $actions */
	public function bulk_entry( $actions ) {
		$actions['dze_translate'] = __( 'Translate with Dazont Ecom', 'dazont-ecom' );
		return $actions;
	}

	/**
	 * @param string          $redirect
	 * @param string          $action
	 * @param array<int,int>  $ids
	 * @return string
	 */
	public function bulk_run( $redirect, $action, $ids ) {
		if ( 'dze_translate' !== $action ) {
			return $redirect;
		}
		// IT OPENS THE DASHBOARD, IT DOES NOT SEND. The languages, what is
		// already translated and what it will cost are chosen and read there,
		// in Step 2 — one way of sending, and it always says what it spends.
		$src  = (string) DZE_Wpml::default_language();
		$refs = [];
		$hors = 0;
		foreach ( (array) $ids as $id ) {
			$id   = (int) $id;
			$type = (string) ( get_post_type( $id ) ?: '' );
			if ( '' === $type ) {
				continue;
			}
			// SEULEMENT DEPUIS LA LANGUE SOURCE : une traduction envoyée se
			// faire traduire produirait une deuxième version de la même page.
			$lang = (string) apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $id, 'element_type' => 'post_' . $type ] );
			if ( '' !== $lang && $lang !== $src ) {
				$hors++;
				continue;
			}
			$refs[] = self::ref( [ 'kind' => 'post', 'id' => $id, 'type' => $type ] );
		}
		set_transient( 'dze_tr_pick_' . (int) get_current_user_id(), array_slice( $refs, 0, self::PICK_MAX ), HOUR_IN_SECONDS );
		return self::url( [ 'picked' => count( $refs ), 'skipped' => $hors ] );
	}
	/**
	 * L'ENVOI EN MASSE : DÉPOSER, PAS ATTENDRE.
	 *
	 * « Les traductions en bulk devraient s'effectuer en background, je n'en
	 * suis pas sûr, je n'ai pas osé changer de page pendant le chargement. »
	 *
	 * L'écran faisait un aller-retour par objet et il fallait rester là. Une
	 * seule requête maintenant : elle range la sélection dans la file et rend
	 * la main tout de suite. Ce qui travaille ensuite est `drain()`, réveillé
	 * par le planificateur — on peut fermer l'onglet.
	 */
	/**
	 * FAIT AVANCER LA FILE D'UN CRAN, depuis la page ouverte.
	 *
	 * Un cran, c'est quelques secondes : envoyer ce qui attend, demander où en
	 * est le lot, écrire une tranche de ce qui est revenu. Jamais une
	 * traduction faite dans cette requête — c'est ce qui se faisait couper.
	 * Si un passage tourne déjà, rien n'est lancé en double.
	 */
	public function ajax_runqueue(): void {
		$this->screen_guard();
		ignore_user_abort( true );
		$occupe = self::held( 'tick' );
		if ( ! $occupe ) {
			// La page relève et publie ; les vagues partent du planificateur.
			self::$from_page = true;
			self::drain( 12 );
			self::$from_page = false;
			if ( 'direct' === self::lane() ) {
				self::kick_drain();
			}
		}
		$reste = count( self::asked() );
		wp_send_json_success( [
			'started' => ! $occupe,
			'running' => $occupe,
			'left'    => $reste,
			'queue'   => method_exists( __CLASS__, 'queue_said' ) ? self::queue_said() : [],
		] );
	}

	/** « Translate the rest right away » — the button on the progress line. */
	public function ajax_hurry(): void {
		$this->screen_guard();
		$n = self::hurry();
		wp_send_json_success( [
			'message' => $n
				? sprintf(
					/* translators: %s: how many translations go right away */
					_n( '%s translation goes right away, at the normal price. What Anthropic had already translated is kept, and nothing it had not started is billed.', '%s translations go right away, at the normal price. What Anthropic had already translated is kept, and nothing it had not started is billed.', $n, 'dazont-ecom' ),
					number_format_i18n( $n )
				)
				: __( 'Nothing was waiting at half price.', 'dazont-ecom' ),
			'queue'   => self::queue_said(),
		] );
	}

	/**
	 * VIDE LA FILE, sans rien traduire.
	 *
	 * « J ai envoye 2x toute la liste des categories. Ridicule. » — Un depot
	 * ne se double pas, la file ecarte ce qu elle a deja ; mais il faut
	 * pouvoir se raviser, et une file qu on ne peut pas vider est une file
	 * qui fait peur.
	 */
	public function ajax_emptyqueue(): void {
		$this->screen_guard();
		$r   = self::cancel_all();
		$msg = sprintf(
			/* translators: %s: how many languages were taken out before being sent */
			_n( '%s translation taken out of the queue before it was sent: nothing is spent on it.', '%s translations taken out of the queue before they were sent: nothing is spent on them.', (int) $r['removed'], 'dazont-ecom' ),
			number_format_i18n( (int) $r['removed'] )
		);
		if ( $r['sent'] ) {
			$msg .= ' ' . sprintf(
				/* translators: %s: how many languages were already with Anthropic */
				_n( '%s was already with Anthropic: it is stopped there, what was not translated yet is not billed, and what was already done arrives in « To review », never published.', '%s were already with Anthropic: they are stopped there, what was not translated yet is not billed, and what was already done arrives in « To review », never published.', (int) $r['sent'], 'dazont-ecom' ),
				number_format_i18n( (int) $r['sent'] )
			);
		}
		wp_send_json_success( [
			'left'    => 0,
			'message' => $msg,
			'queue'   => method_exists( __CLASS__, 'queue_said' ) ? self::queue_said() : [],
		] );
	}

	/**
	 * WHAT « TRANSLATE CONTENT » ACTUALLY DEPOSITS — split from the request
	 * so it can be exercised: which language of which item goes, and which
	 * does not.
	 *
	 * « Leave existing translations as they are » sends what is missing, what
	 * WPML wants again, and what it marked with no word changed (that closes
	 * for free); « Overwrite » sends every chosen language. Neither sends a
	 * language already on its way, or back and waiting for a decision: it
	 * would be paid for twice.
	 *
	 * @param array<int,array>  $objs
	 * @param string[]          $langs
	 * @return array{queued:int,sent:array<string,string[]>}
	 */
	public static function send( array $objs, array $langs, bool $accept, bool $all, string $lane = '' ): array {
		$states = self::row_states( $objs, $langs );
		$groups = [];
		$sent   = [];
		foreach ( $objs as $o ) {
			$ref  = self::ref( $o );
			$want = [];
			foreach ( $langs as $code ) {
				$st = $states[ $ref ][ $code ] ?? [ 'show' => 'missing', 'base' => 'missing' ];
				if ( in_array( $st['show'], [ 'queued', 'running', 'review' ], true ) ) {
					continue;
				}
				if ( $all || in_array( $st['base'], [ 'missing', 'stale', 'noise' ], true ) ) {
					$want[] = (string) $code;
				}
			}
			if ( $want ) {
				// ONE DEPOSIT PER SET OF LANGUAGES, not one per object: two
				// thousand writes of a two-thousand-row option is a request
				// that never finishes.
				$groups[ implode( ',', $want ) ][] = $o;
				$sent[ $ref ] = $want;
			}
		}
		$n    = 0;
		$refu = 0;
		foreach ( $groups as $codes => $list ) {
			$pas = 0;
			$n  += self::ask( $list, $accept, explode( ',', (string) $codes ), $all, $pas, $lane );
			$refu += (int) $pas;
		}
		if ( $n ) {
			self::kick_drain();
		}
		return [ 'queued' => $n, 'sent' => $sent, 'refused' => $refu ];
	}

	/**
	 * « TRANSLATE CONTENT » — Step 2's one button.
	 *
	 * It sends what the screen showed: the ticked items, into the languages
	 * set to « Translate automatically ». With « Leave existing translations
	 * as they are », a language that is complete is left alone — only what is
	 * missing, what WPML wants again, and what it marked with nothing moved
	 * (which closes for free) goes. With « Overwrite », every chosen language
	 * goes. A language already on its way, or back and waiting for a decision,
	 * is never sent twice: it would be paid for twice.
	 *
	 * Nothing is translated here. It is deposited, and `drain()` works in the
	 * background — the page shows each language turning until it lands.
	 */
	public function ajax_queue(): void {
		$this->screen_guard();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- l'hébergeur peut refuser.
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- screen_guard() l'a vérifié.
		$refs   = isset( $_POST['refs'] ) ? array_slice( (array) wp_unslash( $_POST['refs'] ), 0, self::PICK_MAX ) : [];
		$accept = ! empty( $_POST['accept'] );
		$all    = ! empty( $_POST['all'] );
		$asked  = isset( $_POST['langs'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['langs'] ) ) : [];
		// RAPIDE OU ÉCONOMIQUE, pour cet envoi-ci.
		$lane   = isset( $_POST['lane'] ) ? sanitize_key( wp_unslash( $_POST['lane'] ) ) : '';
		// phpcs:enable
		// LES LANGUES CHOISIES, ET AUCUNE AUTRE — jamais « toutes » par défaut :
		// un défaut qui dépense doit être un choix, jamais un oubli.
		$langs = array_values( array_intersect( self::target_codes(), $asked ) );
		if ( ! $langs ) {
			wp_send_json_error( [ 'message' => __( 'Choose at least one language to translate into.', 'dazont-ecom' ) ] );
		}
		$objs = [];
		foreach ( $refs as $ref ) {
			$o = self::from_ref( sanitize_text_field( (string) $ref ) );
			if ( $o ) {
				$objs[ self::ref( $o ) ] = $o;
			}
		}
		if ( ! $objs ) {
			wp_send_json_error( [ 'message' => __( 'Nothing was ticked that this site translates.', 'dazont-ecom' ) ] );
		}
		$done = self::send( array_values( $objs ), $langs, $accept, $all, $lane );
		$n    = (int) $done['queued'];
		$fast = 'direct' === ( in_array( $lane, [ 'direct', 'batch' ], true ) ? $lane : self::lane() );
		$msg  = $n
			? sprintf(
				/* translators: %s: how many items were sent */
				$fast
					? _n(
						'%s item sent to translation, right away: each language appears on its row as soon as it is back. You can leave this page.',
						'%s items sent to translation, right away: each language appears on its row as soon as it is back. You can leave this page.',
						$n,
						'dazont-ecom'
					)
					: _n(
						'%s item sent to translation at half price: Anthropic answers when it has room — often minutes, sometimes hours, 24 hours at most. You can leave this page, or press « Translate the rest right away » if it takes too long.',
						'%s items sent to translation at half price: Anthropic answers when it has room — often minutes, sometimes hours, 24 hours at most. You can leave this page, or press « Translate the rest right away » if it takes too long.',
						$n,
						'dazont-ecom'
					),
				number_format_i18n( $n )
			)
			: __( 'Nothing was sent: in these languages, everything ticked is already translated, on its way, or waiting for your review.', 'dazont-ecom' );
		if ( ! empty( $done['refused'] ) ) {
			$msg .= ' ' . sprintf(
				/* translators: 1: how many were refused, 2: how many the queue holds at most */
				_n( '%1$s item did not fit: the queue holds %2$s at most. Send it again once the queue has moved.', '%1$s items did not fit: the queue holds %2$s at most. Send them again once the queue has moved.', (int) $done['refused'], 'dazont-ecom' ),
				number_format_i18n( (int) $done['refused'] ),
				number_format_i18n( self::QUEUE_MAX )
			);
		}
		wp_send_json_success( [
			'queued'  => $n,
			'refused' => (int) ( $done['refused'] ?? 0 ),
			// THE PAGE DOES NOT START A SECOND ONE: the server already has.
			'kicked'  => $n > 0,
			'sent'    => $done['sent'],
			'queue'   => self::queue_said(),
			'message' => $msg,
		] );
	}
	public function ajax_batch(): void {
		$this->screen_guard();
		// UNE TRADUCTION PREND LE TEMPS QU ELLE PREND.
		//
		// « La traduction de la page FAQ ne fonctionne pas. Je ne comprends pas
		// pourquoi. » Le moteur, lui, la traduisait tres bien : quarante-neuf
		// champs, huit mille cinq cents caracteres, zero erreur — en
		// CINQUANTE-HUIT SECONDES. Cette requete-ci n avait aucune limite posee
		// et tournait donc sous celle du serveur web, qui l arretait en chemin.
		// Rien ne s affichait, rien ne disait pourquoi.
		//
		// Et le travail CONTINUE si le navigateur renonce : ce qui est paye au
		// modele est alors garde en attente au lieu d etre perdu. « Je n ai pas
		// ose changer de page pendant le chargement » — desormais on peut.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- l hebergeur peut refuser.
		}
		ignore_user_abort( true );
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- screen_guard() checked it.
		$only = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		// DÉJÀ CHEZ ANTHROPIC : traduire ici la même langue la paierait deux
		// fois. Elle arrivera dans « À relire ».
		$en_route = array_values( array_intersect( $langs, (array) ( self::running()[ self::ref( $o ) ] ?? [] ) ) );
		if ( $en_route ) {
			wp_send_json_error( [ 'message' => __( 'This language is already being translated in a batch sent from the dashboard: it will arrive in « To review ». Translating it here as well would pay for it twice.', 'dazont-ecom' ) ] );
		}
		// CE QUI EST TRADUIT ICI n'est plus à la file : un lot plus tardif ne
		// viendra pas le refaire, ni écrire par-dessus.
		self::forget_langs( self::ref( $o ), $langs );
		$made = self::produce( $o, $langs, $all, $only );
		// ACCEPTER SANS RELIRE, QUAND LA BOUTIQUE LE DEMANDE.
		//
		// « Pas de choix d acceptation automatique. Il faut toujours tout
		// review. Il faut ce choix. »
		//
		// La case vit sur l ecran d envoi et voyage avec la demande : c est le
		// geste qui decide, pas un reglage oublie ailleurs. Ce qui est ecrit
		// l est par le MEME chemin que l acceptation a la main — donc les
		// memes garde-fous, la meme reecriture des liens, le meme journal.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- screen_guard() l a verifie.
		$sans_relire = ! empty( $_POST['accept'] );
		$pose = [];
		if ( $sans_relire && ! empty( $made['langs'] ) ) {
			$ecrit = self::accept( $o, (array) $made['langs'] );
			$pose  = array_keys( (array) ( $ecrit['written'] ?? [] ) );
		}
		wp_send_json_success( [
			'label'   => self::obj_label( $o ),
			// CE QUI A ETE ECRIT SANS PASSER PAR LA RELECTURE : l ecran doit
			// pouvoir dire « ecrit » plutot que « en attente », sinon il envoie
			// le lecteur chercher une file vide.
			'written' => $pose,
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

	/**
	 * ACCEPT EVERYTHING WAITING ON THESE OBJECTS, every language, as it came.
	 *
	 * "Sur la page review je veux pouvoir visualiser rapidement les
	 * traductions… Et je ne peux même pas accepter en bulk. C'est ce que
	 * j'aurais fait ici : tout accepter. Tout est bon, le plugin fonctionne
	 * bien." Saying yes to eight objects meant eight screens, and one screen
	 * per language inside each — so the plugin working well cost more clicks
	 * than the plugin working badly.
	 *
	 * It writes exactly what the review screen would have written: the held
	 * texts, untouched. Nothing is re-translated and nothing is paid for.
	 */
	public function ajax_accept_all(): void {
		$this->screen_guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- screen_guard() checked it.
		$refs = isset( $_POST['refs'] ) ? (array) wp_unslash( $_POST['refs'] ) : [];
		$refs = array_values( array_filter( array_map( 'sanitize_text_field', $refs ) ) );
		if ( ! $refs ) {
			// No list: everything the review tab is showing.
			foreach ( self::review_list() as $row ) {
				$refs[] = self::ref( $row );
			}
		}
		$done  = 0;
		$objs  = 0;
		$errs  = [];
		$warn  = [];
		$still = [];
		foreach ( $refs as $ref ) {
			$o = self::from_ref( (string) $ref );
			if ( ! $o ) {
				continue;
			}
			$held = self::waiting( $o );
			$keep = (array) ( $held['langs'] ?? [] );
			if ( ! $keep ) {
				continue;
			}
			$res = self::accept( $o, $keep );
			$objs++;
			foreach ( (array) ( $res['written'] ?? [] ) as $n ) {
				$done += (int) $n;
			}
			foreach ( (array) ( $res['errors'] ?? [] ) as $lang => $why ) {
				$errs[] = self::obj_label( $o ) . ' (' . $lang . ') : ' . $why;
			}
			// WRITTEN, BUT WRONG ON THE SHOP: a product whose variations are
			// missing on the translation shows as unavailable. The editor said
			// so; the buttons that accept a whole row said « Written. »
			foreach ( (array) ( $res['warnings'] ?? [] ) as $lang => $why ) {
				$warn[] = self::obj_label( $o ) . ' (' . $lang . ') : ' . $why;
			}
			// AND WHAT IS STILL WAITING ON IT — a language that could not be
			// written stays, and its row must stay with it.
			$still[ (string) $ref ] = count( (array) ( self::waiting( $o )['langs'] ?? [] ) );
		}
		wp_send_json_success( [
			'objects'  => $objs,
			'fields'   => $done,
			'errors'   => array_slice( $errs, 0, 8 ),
			'warnings' => array_slice( $warn, 0, 8 ),
			'still'    => $still,
			'left'     => self::review_count(),
		] );
	}

	/**
	 * WHAT ONE ROW HOLDS, READ WITHOUT LEAVING THE LIST.
	 *
	 * "Sur la page review je veux pouvoir visualiser rapidement les
	 * traductions comme sur WPML (ils utilisent une popup pour les strings,
	 * mais ça peut marcher avec tout)." Reading eight results meant eight
	 * screens, and one screen per language inside each — so the answer to
	 * "is this any good?" cost more than the work itself.
	 *
	 * Every language, every field, original beside translation, in the row.
	 * Read-only: accepting is still a decision taken on a button, and a
	 * preview that could also write would be a second editor to keep in step.
	 */
	public function ajax_peek(): void {
		$this->screen_guard();
		$o = self::from_ref( isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '' );
		if ( ! $o ) {
			wp_send_json_error( [ 'message' => __( 'Unknown object.', 'dazont-ecom' ) ] );
		}
		$held   = self::waiting( $o );
		$made   = (array) ( $held['langs'] ?? [] );
		$source = self::obj_read( $o );
		$labels = self::labels_for( $o );
		$names  = [];
		foreach ( DZE_Wpml::get_active_languages() as $l ) {
			$names[ (string) $l['code'] ] = (string) $l['native_name'];
		}
		ob_start();
		if ( ! $made ) {
			echo '<p class="description">' . esc_html__( 'Nothing is being held for this one any more.', 'dazont-ecom' ) . '</p>';
		}
		foreach ( $made as $lang => $fields ) {
			echo '<div class="dze-tr-peeklang"><strong>'
				. esc_html( (string) ( $names[ $lang ] ?? strtoupper( (string) $lang ) ) )
				. '</strong></div>';
			echo '<table class="widefat striped dze-tr-peektable"><tbody>';
			foreach ( (array) $fields as $fid => $txt ) {
				$was = trim( wp_strip_all_tags( (string) ( $source[ $fid ] ?? '' ) ) );
				$now = trim( wp_strip_all_tags( (string) $txt ) );
				printf(
					'<tr><td style="width:150px;"><strong>%1$s</strong></td><td style="width:44%%;"><span class="description">%2$s</span></td><td>%3$s</td></tr>',
					esc_html( (string) ( $labels[ $fid ] ?? $fid ) ),
					esc_html( mb_substr( $was, 0, 400 ) . ( mb_strlen( $was ) > 400 ? '…' : '' ) ),
					esc_html( mb_substr( $now, 0, 400 ) . ( mb_strlen( $now ) > 400 ? '…' : '' ) )
				);
			}
			echo '</tbody></table>';
		}
		wp_send_json_success( [ 'html' => ob_get_clean() ] );
	}

	/** Accept what was kept, or refuse the lot. Both end the wait. */
	public function ajax_decide(): void {
		$this->screen_guard();
		$o = self::from_ref( isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '' );
		if ( ! $o ) {
			wp_send_json_error( [ 'message' => __( 'Unknown object.', 'dazont-ecom' ) ] );
		}
		$how  = isset( $_POST['how'] ) ? sanitize_key( wp_unslash( $_POST['how'] ) ) : '';
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		if ( 'refuse' === $how ) {
			// FROM THE PAGE OF ONE LANGUAGE, THAT LANGUAGE — the list's own
			// Discard, which names no language, throws the object's lot.
			if ( '' !== $lang ) {
				self::drop_wait_lang( $o, $lang );
			} else {
				self::drop_wait( $o );
			}
			$next = self::next_waiting( $o );
			wp_send_json_success( [ 'refused' => true, 'left' => count( $next ), 'next' => $next ] );
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
		// WRITTEN BY HAND IS FINAL: a batch still out for these languages
		// comes back to « To review », never over what was just written.
		self::forget_langs( self::ref( $o ), array_keys( (array) $done['written'] ) );
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
			// AND WHICH ONES, WITH THE WAY TO EACH. « Accepté mais toujours là »:
			// the Russian was written, four other languages were still waiting,
			// and nothing on the page said so or led to them.
			'next'    => self::next_waiting( $o ),
		] );
	}

	/**
	 * The languages of this object still waiting for a decision, in the
	 * shop's order, each with the page that reads it.
	 *
	 * @return array<int,array{lang:string,name:string,url:string}>
	 */
	public static function next_waiting( array $o ): array {
		$held  = (array) ( self::waiting( $o )['langs'] ?? [] );
		$names = method_exists( __CLASS__, 'lang_names' ) ? self::lang_names() : [];
		$out   = [];
		foreach ( self::target_codes() as $code ) {
			if ( ! isset( $held[ $code ] ) ) {
				continue;
			}
			$out[] = [
				'lang' => (string) $code,
				'name' => (string) ( $names[ $code ] ?? strtoupper( (string) $code ) ),
				'url'  => method_exists( __CLASS__, 'editor_url' ) ? self::editor_url( $o, (string) $code ) : '',
			];
		}
		return $out;
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
		$names = [];
		foreach ( self::target_codes() as $code ) {
			$names[ $code ] = (string) ( self::lang_names()[ $code ] ?? strtoupper( $code ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$f = self::filters( wp_unslash( $_GET ) );
		wp_localize_script( 'dze-translate-screen', 'dzeTrScreen', [
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE ),
			'reviewUrl' => self::url( [ 'tab' => 'review' ] ),
			'doneIcon'  => self::state_icon( 'done' ),
			// THE DASHBOARD'S OWN READING: the target languages in the order of
			// the columns, the filters every section is read through, what was
			// picked on a WordPress list, and how often a turning wheel asks.
			'langs'     => array_keys( $names ),
			'names'     => $names,
			'filters'   => $f,
			'picked'    => self::picked_from_list()['refs'],
			'poll'      => 8000,
			'pickMax'   => self::PICK_MAX,
			// RAPIDE OU ÉCONOMIQUE : le choix de la boutique ouvre l'écran, et le
			// coût affiché suit ce qui est choisi pour l'envoi.
			'lane'      => self::lane(),
			'batchRate' => self::BATCH_RATE,
			'i18n'      => [
				// ---- The dashboard ----
				'oneSelected'  => __( '1 item selected', 'dazont-ecom' ),
				/* translators: %s: how many items are ticked */
				'nSelected'    => __( '%s items selected', 'dazont-ecom' ),
				'counting'     => __( 'Counting the words…', 'dazont-ecom' ),
				'pickLang'     => __( 'Choose the languages: set at least one to “Translate automatically”.', 'dazont-ecom' ),
				'nothingOwed'  => __( 'In these languages, everything selected is already translated, on its way, or waiting for your review. Choose “Overwrite existing translations” to translate it again.', 'dazont-ecom' ),
				/* translators: 1: how many words, 2: the language */
				'sumLang'      => __( '%1$s words into %2$s', 'dazont-ecom' ),
				/* translators: %s: how many languages WPML marked with no words changed */
				'sumFree'      => __( '%s marked by WPML with no word changed: closed for free', 'dazont-ecom' ),
				/* translators: %s: the estimated cost */
				'sumCost'      => __( 'about %s in all (estimate)', 'dazont-ecom' ),
				'hurrying'     => __( 'Sending the rest right away…', 'dazont-ecom' ),
				/* translators: %s: an amount in dollars */
				'money'        => __( '$%s', 'dazont-ecom' ),
				'moneyTiny'    => __( 'under $0.01', 'dazont-ecom' ),
				'reviewSaid'   => __( 'Each translation waits under “To review” for your yes or no before anything is written.', 'dazont-ecom' ),
				'publishSaid'  => __( 'Each translation is written onto the site as soon as it comes back. The undo is the translation screen of each item.', 'dazont-ecom' ),
				'sending'      => __( 'Sending…', 'dazont-ecom' ),
				'selecting'    => __( 'Selecting…', 'dazont-ecom' ),
				/* translators: %s: the most items one selection holds */
				'capped'       => __( 'Only the first %s were selected: send them, then select the rest.', 'dazont-ecom' ),
				'cancelAsk'    => __( 'Take this language out of the queue? Nothing has been spent on it yet.', 'dazont-ecom' ),
				'cancelAllAsk' => __( 'Take everything out of the queue? Nothing more is spent: what is waiting is taken out, and what is already with Anthropic is stopped there — what it had already finished arrives in « To review », never published.', 'dazont-ecom' ),
				'oneProgress'  => __( '1 item is being translated in the background.', 'dazont-ecom' ),
				/* translators: %s: how many items are on their way */
				'nProgress'    => __( '%s items are being translated in the background.', 'dazont-ecom' ),
				/* translators: 1: how many failed, 2: the most recent reason */
				'failed'       => __( '%1$s translation(s) could not be finished after three tries. Last reason: %2$s', 'dazont-ecom' ),
				'error'        => __( 'Something went wrong.', 'dazont-ecom' ),
				// ---- The editor of one object ----
				// ASKED BEFORE IT IS SPENT. "Translate everything again" pays
				// for fields that had not moved, on purpose, and a button that
				// costs money without saying so is a button pressed by mistake.
				'confirmAll' => __( 'Send every field again, including the ones that have not changed? This costs a full translation. Use it to compare one model or prompt against another.', 'dazont-ecom' ),
				// The copy button never replaces words already written without asking.
				'overwrite'  => __( 'Replace what is in the box with the original?', 'dazont-ecom' ),
				'translating'=> __( 'Translating…', 'dazont-ecom' ),
				// ONE OBJECT is not "any of them".
				'nothingNewOne' => __( 'Nothing has moved on this one: not one word was sent, nothing was spent, and WPML has been told it is up to date.', 'dazont-ecom' ),
				'confirmNo'  => __( 'Throw this translation away? It cannot be recovered; the object stays exactly as it is.', 'dazont-ecom' ),
				'confirmNoLang' => __( 'Throw away what was translated into this language? The other languages waiting are kept, and the translation stays exactly as it is.', 'dazont-ecom' ),
				/* translators: %s: the languages still waiting, e.g. "Polski, Français" */
				'stillWaiting'  => __( 'Still waiting for your review on this item: %s.', 'dazont-ecom' ),
				/* translators: %s: the next language's name */
				'reviewNext'    => __( 'Review %s →', 'dazont-ecom' ),
				'acceptRestOne' => __( 'Accept the other one as it came', 'dazont-ecom' ),
				/* translators: %s: how many languages are still waiting */
				'acceptRest'    => __( 'Accept the %s others as they came', 'dazont-ecom' ),
				'nothingElse'   => __( 'Nothing else is waiting for your review on this item.', 'dazont-ecom' ),
				'progPaused'    => __( 'Nothing is sent while the queue is paused.', 'dazont-ecom' ),
				'progBatch'     => __( 'Anthropic translates it in batches, at half price. A large send can take up to an hour — 24 hours at most — and each language appears on its row as soon as its batch is back. You can leave this page.', 'dazont-ecom' ),
				/* translators: 1: requests answered, 2: requests sent */
				'progAnswered'  => __( '%1$s of %2$s answers back from Anthropic', 'dazont-ecom' ),
				/* translators: 1: how long ago, 2: how many batches */
				'progSentOne'   => __( 'sent %1$s ago in %2$s batch', 'dazont-ecom' ),
				/* translators: 1: how long ago, 2: how many batches */
				'progSentMany'  => __( 'sent %1$s ago in %2$s batches', 'dazont-ecom' ),
				/* translators: %s: how long ago */
				'progChecked'   => __( 'last checked %s ago', 'dazont-ecom' ),
				'progSending'   => __( 'Being put into a batch…', 'dazont-ecom' ),
				'progDirect'    => __( 'Translated right away, a few at a time, in the background: each language appears on its row as soon as it is back. You can leave this page.', 'dazont-ecom' ),
				/* translators: 1: translations done, 2: all of them */
				'progDone'      => __( '%1$s of %2$s translations done', 'dazont-ecom' ),
				/* translators: %s: how long ago */
				'progStarted'   => __( 'started %s ago', 'dazont-ecom' ),
				/* translators: %s: how long ago */
				'progLast'      => __( 'last answer %s ago', 'dazont-ecom' ),
				'progFirst'     => __( 'the first answers come back within a minute or two', 'dazont-ecom' ),
				/* translators: %s: how many translations are back */
				'progLanded'    => __( '%s translations back, being saved', 'dazont-ecom' ),
				/* translators: %s: seconds */
				'agoS'          => __( '%s s', 'dazont-ecom' ),
				/* translators: %s: minutes */
				'agoM'          => __( '%s min', 'dazont-ecom' ),
				/* translators: 1: hours, 2: minutes */
				'agoH'          => __( '%1$s h %2$s min', 'dazont-ecom' ),
				'allWritten'    => __( 'Every language of this item is decided.', 'dazont-ecom' ),
				'backToList'    => __( 'Back to « To review »', 'dazont-ecom' ),
				/* translators: %s: how many items are still waiting for review */
				'rowDone'       => __( 'Written. %s item(s) still waiting for review.', 'dazont-ecom' ),
				'saved'      => __( 'Written ✓', 'dazont-ecom' ),
				'saving'     => __( 'Writing…', 'dazont-ecom' ),
				// THE CHIP AFTER A SAVE says what the dashboard says: complete.
				'stateDone'  => self::state_said( 'done' ),
				/* translators: %s: number of fields filled in */
				'filled'     => __( '%s field(s) filled in below — nothing is written until you save.', 'dazont-ecom' ),
				'nothingToSave' => __( 'Every field is empty. There is nothing to write.', 'dazont-ecom' ),
				'oneSending' => __( 'Translating this block…', 'dazont-ecom' ),
				'oneDone'    => __( 'filled in — nothing is written until you save.', 'dazont-ecom' ),
				'oneNothing' => __( 'Nothing came back for this block.', 'dazont-ecom' ),
				'dropped'    => __( 'Thrown away. The translation is exactly as it was.', 'dazont-ecom' ),
				// ---- To review ----
				/* translators: %s: how many rows are ticked */
				'acceptAsk'  => __( 'Write the %s ticked translation(s), in every language, exactly as they came back?', 'dazont-ecom' ),
				'allSending' => __( 'Writing…', 'dazont-ecom' ),
				/* translators: 1: how many objects, 2: how many fields */
				'allDone'    => __( '%1$s written, %2$s field(s) in all.', 'dazont-ecom' ),
				'allNone'    => __( 'Nothing was waiting any more.', 'dazont-ecom' ),
				/* translators: %s: how many rows are ticked */
				'dropAsk'    => __( 'Throw away what came back for the %s ticked row(s)? It cannot be recovered; the objects stay exactly as they are.', 'dazont-ecom' ),
				'dropSending'=> __( 'Throwing away…', 'dazont-ecom' ),
				/* translators: %s: how many rows are ticked */
				'acceptN'    => __( 'Accept (%s)', 'dazont-ecom' ),
				/* translators: %s: how many rows are ticked */
				'discardN'   => __( 'Discard (%s)', 'dazont-ecom' ),
				/* translators: %s: how many were thrown away */
				'dropDone'   => __( '%s thrown away.', 'dazont-ecom' ),
				'peek'       => __( 'Read it here', 'dazont-ecom' ),
				'peekHide'   => __( 'Hide', 'dazont-ecom' ),
				'peekLoad'   => __( 'Reading…', 'dazont-ecom' ),
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
