<?php
defined( 'ABSPATH' ) || exit;

/**
 * Review generator — a TESTING module: it writes native WooCommerce reviews
 * (comment_type "review" + rating/verified meta, so every review plugin reads
 * them) from Claude, in bulk from the products list.
 *
 * Built for staging/demo catalogues: publishing fabricated customer reviews on
 * a live shop is illegal in the EU (Omnibus directive) and under FTC rules.
 * Two safeguards follow from that: the module is OFF by default, and every
 * review it creates is tagged with the `_dze_generated` comment meta, shown as
 * such in the panel and removable in one click (per product or in bulk).
 *
 * Products list: a "Reviews" column shows the count and opens the per-product
 * panel — generate, read the drafts, push them or discard. The bulk action
 * runs straight on that list, a spinner in each product's cell, writing the
 * reviews as pending without a preview step: individual generation is where
 * the prompt gets calibrated, bulk is for volume once it is.
 */
final class DZE_Reviews {

	private const OPT      = 'dze_reviews_settings';
	private const NONCE    = 'dze_reviews';
	public const BULK_ACTION = 'dze_gen_reviews';

	/** Comment meta flagging a review created by this module. */
	public const GEN_META = '_dze_generated';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'clear_placeholder_emails' ] );
		// Products list: count column + popup.
		add_filter( 'manage_edit-product_columns', [ $this, 'add_column' ], 21 );
		add_action( 'manage_product_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
		add_action( 'admin_footer-edit.php', [ $this, 'list_modal' ] );
		// The same panel on the product screen itself: reviews are worked on
		// where the reviews are, not only from the list.
		add_action( 'admin_footer-post.php', [ $this, 'list_modal' ] );
		add_action( 'admin_footer-post-new.php', [ $this, 'list_modal' ] );
		// Bulk action → runs on the products list itself.
		add_filter( 'bulk_actions-edit-product', [ $this, 'register_bulk_action' ] );
		add_filter( 'handle_bulk_actions-edit-product', [ $this, 'handle_bulk_action' ], 10, 3 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		// AJAX.
		add_action( 'wp_ajax_dze_reviews_panel', [ $this, 'ajax_panel' ] );
		add_action( 'wp_ajax_dze_reviews_generate', [ $this, 'ajax_generate' ] );
		add_action( 'wp_ajax_dze_reviews_publish', [ $this, 'ajax_publish' ] );
		add_action( 'wp_ajax_dze_reviews_save_prompt', [ $this, 'ajax_save_prompt' ] );
		add_action( 'wp_ajax_dze_reviews_delete', [ $this, 'ajax_delete' ] );
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function get_settings(): array {
		$s = get_option( self::OPT, [] );
		return is_array( $s ) ? $s : [];
	}

	public function register_settings(): void {
		register_setting( 'dze_reviews_options', self::OPT, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize' ],
			'default'           => [],
			'autoload'          => false, // admin-only settings: never loaded on a shop page.
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
		if ( isset( $in['min_count'] ) ) {
			$out['min_count'] = max( 1, min( 20, (int) $in['min_count'] ) );
		}
		if ( isset( $in['max_count'] ) ) {
			$out['max_count'] = max( 1, min( 20, (int) $in['max_count'] ) );
		}
		if ( isset( $in['five_pct'] ) ) {
			$out['five_pct'] = max( 0, min( 100, (int) $in['five_pct'] ) );
		}
		if ( isset( $in['status'] ) ) {
			$out['status'] = ( 'approved' === $in['status'] ) ? 'approved' : 'pending';
		}
		if ( isset( $in['days'] ) ) {
			$out['days'] = max( 1, min( 1095, (int) $in['days'] ) );
		}
		if ( isset( $in['verified'] ) ) {
			$out['verified'] = ! empty( $in['verified'] ) ? 1 : 0;
		}
		if ( isset( $in['delivery'] ) ) {
			$out['delivery'] = sanitize_text_field( (string) $in['delivery'] );
		}
		if ( isset( $in['language'] ) ) {
			$out['language'] = sanitize_text_field( (string) $in['language'] );
		}
		if ( isset( $in['prompt'] ) ) {
			$p = trim( sanitize_textarea_field( (string) $in['prompt'] ) );
			// Saved exactly as shipped means "no custom prompt", so a better
			// default still reaches this install later.
			$out['prompt'] = ( $p === trim( self::default_prompt() ) ) ? '' : $p;
		}
		return $out;
	}

	public static function min_count(): int {
		return max( 1, (int) ( self::get_settings()['min_count'] ?? 2 ) );
	}

	public static function max_count(): int {
		return max( self::min_count(), (int) ( self::get_settings()['max_count'] ?? 6 ) );
	}

	/** A different, random number of reviews for each product. */
	public static function random_count(): int {
		return wp_rand( self::min_count(), self::max_count() );
	}

	/** Share of 5-star reviews (the rest is spread over 4 and 3). */
	public static function five_pct(): int {
		$v = self::get_settings()['five_pct'] ?? 70;
		return max( 0, min( 100, (int) $v ) );
	}

	/** New reviews wait in WooCommerce → Reviews unless set to approved. */
	public static function auto_approve(): bool {
		return 'approved' === ( self::get_settings()['status'] ?? 'pending' );
	}

	/**
	 * Ratings decided HERE, not by the model: the requested share of 5s, the
	 * rest mostly 4s with the odd 3, then shuffled — which is what kills the
	 * mechanical 5/4/5/4 alternation the model falls into on its own.
	 */
	public static function rating_plan( int $count ): array {
		$fives = (int) round( $count * self::five_pct() / 100 );
		$rest  = max( 0, $count - $fives );
		// One 3-star in roughly one batch out of six: on a whole catalogue that
		// reads naturally, while most products keep a clean 4/5 mix.
		$threes = ( $rest >= 1 && wp_rand( 1, 6 ) === 1 ) ? 1 : 0;
		$fours  = $rest - $threes;
		$plan   = array_merge(
			array_fill( 0, max( 0, $fives ), 5 ),
			array_fill( 0, max( 0, $fours ), 4 ),
			array_fill( 0, max( 0, $threes ), 3 )
		);
		if ( empty( $plan ) ) {
			$plan = array_fill( 0, $count, 5 );
		}
		shuffle( $plan );
		return $plan;
	}

	public static function days(): int {
		return max( 1, (int) ( self::get_settings()['days'] ?? 180 ) );
	}

	public static function verified(): bool {
		$s = self::get_settings();
		return ! isset( $s['verified'] ) || ! empty( $s['verified'] );
	}

	/** Real delivery time reviewers may mention; empty = never mention it. */
	public static function delivery(): string {
		return trim( (string) ( self::get_settings()['delivery'] ?? '' ) );
	}

	/**
	 * The language reviews are written in: the shop's MAIN language (WPML
	 * default language, else the WordPress locale), or the manual override.
	 * Returned as a human-readable name — that is what the model needs.
	 */
	public static function language(): string {
		$manual = trim( (string) ( self::get_settings()['language'] ?? '' ) );
		if ( '' !== $manual ) {
			return $manual;
		}
		$code = (string) apply_filters( 'wpml_default_language', '' );
		if ( '' === $code ) {
			$code = (string) get_locale(); // e.g. en_US, fr_FR.
		}
		$short = strtolower( substr( str_replace( '-', '_', $code ), 0, 2 ) );
		$names = [
			'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish',
			'it' => 'Italian', 'nl' => 'Dutch', 'pt' => 'Portuguese', 'pl' => 'Polish',
			'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian', 'fi' => 'Finnish',
			'cs' => 'Czech', 'ro' => 'Romanian', 'el' => 'Greek', 'ja' => 'Japanese',
		];
		if ( isset( $names[ $short ] ) ) {
			// US English gets its spelling stated explicitly.
			return ( 'en' === $short && false !== stripos( $code, 'US' ) ) ? 'English (US spelling)' : $names[ $short ];
		}
		return class_exists( 'Locale' ) ? (string) Locale::getDisplayLanguage( $code, 'en' ) : $code;
	}

	public static function default_prompt(): string {
		$shipped = <<<'PROMPT'
You write customer reviews for an online shop. They must be indistinguishable from real reviews left by buyers.

LENGTH — the most important point, and the one most often botched.
NEVER write reviews of uniform length. Out of 10 reviews:
- 3 or 4 are very short: 2 to 6 words ("Fits perfectly.", "Great, thanks", "Exactly as described").
- 3 or 4 are a single sentence.
- 2 run to two or three sentences.
- 1 at most is detailed (4 to 5 sentences).
A short review is a normal review: never pad it out to "do better".

STYLE — vary everything from one review to the next:
- Many mention ONE aspect only (the colour, the cut, the weight, the stitching).
- No recurring structure: do not go quality → use → delivery every time.
- Mixed register: casual, plain or careful. Natural punctuation; very short reviews may skip the capital or the full stop.
- No emoji. No empty superlatives. "I recommend" in one review at most.
- Do not repeat the full product name: a buyer says "the shirt", "the case", "it".
- Vary the opening: some start with the flaw, some with the use, some with no lead-in at all.

CONTENT — only what a buyer can actually observe:
- Fit, material, finish, colour, comfort, durability, real-life use.
- Invent NO figures: no price, no measurement, no percentage, no duration.
- 4-star reviews carry an honest, concrete reservation. 5-star ones stay sober: satisfied, not advertising copy.
- First name + last initial, plausible for the review language.
- A short title (2 to 5 words) matching the tone: telegraphic when the review is short.
PROMPT;
		return class_exists( 'DZE_Prompt_Defaults' )
			? DZE_Prompt_Defaults::pick( 'reviews', $shipped )
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
			__( 'The product: its title, its description and its attributes.', 'dazont-ecom' ),
			__( 'The shop context, and the shop\'s main language — which overrides the language your instructions are written in, reviewer names included.', 'dazont-ecom' ),
			__( 'The delivery rule, so a review never contradicts what the shop promises.', 'dazont-ecom' ),
			__( 'How many reviews to write, and the rating of each one, imposed in order.', 'dazont-ecom' ),
			__( 'The date range they must fall in, spread irregularly.', 'dazont-ecom' ),
			__( 'The answer format — name, rating, title, text, date.', 'dazont-ecom' ),
		];
	}

	public static function prompt(): string {
		$p = trim( (string) ( self::get_settings()['prompt'] ?? '' ) );
		return '' !== $p ? $p : self::default_prompt();
	}

	/**
	 * Earlier versions stored a made-up name@example.com on generated reviews.
	 * Wipes those addresses once — only on comments this module created.
	 */
	public function clear_placeholder_emails(): void {
		if ( get_option( 'dze_reviews_mailfix' ) ) {
			return;
		}
		update_option( 'dze_reviews_mailfix', 1, false );
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT c.comment_ID FROM {$wpdb->comments} c
			 INNER JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = '_dze_generated'
			 WHERE c.comment_author_email LIKE '%@example.com'"
		);
		foreach ( $ids as $cid ) {
			wp_update_comment( [ 'comment_ID' => (int) $cid, 'comment_author_email' => '' ] );
		}
	}

	// =========================================================================
	// Review data helpers
	// =========================================================================

	/** [ 'total' => int, 'generated' => int, 'avg' => float ] for a product. */
	public static function stats( int $pid ): array {
		$comments = get_comments( [
			'post_id' => $pid,
			'type'    => 'review',
			'status'  => 'all',
			'fields'  => 'ids',
			'number'  => 0,
		] );
		$gen     = 0;
		$pending = 0;
		$sum     = 0;
		$n       = 0;
		foreach ( $comments as $cid ) {
			if ( get_comment_meta( (int) $cid, self::GEN_META, true ) ) {
				$gen++;
			}
			if ( '1' !== (string) get_comment( (int) $cid )->comment_approved ) {
				$pending++;
			}
			$r = (int) get_comment_meta( (int) $cid, 'rating', true );
			if ( $r > 0 ) {
				$sum += $r;
				$n++;
			}
		}
		return [
			'total'     => count( $comments ),
			'generated' => $gen,
			'pending'   => $pending,
			'avg'       => $n ? round( $sum / $n, 1 ) : 0.0,
		];
	}

	/**
	 * A real YYYY-MM-DD date inside the configured window: the model's own date
	 * when usable, the legacy days_ago when present, a random day otherwise.
	 */
	private static function clean_date( array $r ): string {
		$raw = trim( (string) ( $r['date'] ?? '' ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			$ts = strtotime( $raw );
			if ( $ts && $ts <= time() && $ts >= strtotime( '-' . self::days() . ' days' ) ) {
				return $raw;
			}
		}
		if ( isset( $r['days_ago'] ) ) {
			return gmdate( 'Y-m-d', strtotime( '-' . max( 0, (int) $r['days_ago'] ) . ' days' ) );
		}
		return gmdate( 'Y-m-d', strtotime( '-' . wp_rand( 1, self::days() ) . ' days' ) );
	}

	/** Creates ONE native WooCommerce review; returns the comment id. */
	private static function insert_review( int $pid, array $r ): int {
		// A plausible hour of the day, so a batch does not land at 00:00.
		$date   = self::clean_date( $r ) . sprintf( ' %02d:%02d:00', wp_rand( 8, 22 ), wp_rand( 0, 59 ) );
		$name   = sanitize_text_field( (string) ( $r['name'] ?? 'Client' ) );
		$rating = max( 1, min( 5, (int) ( $r['rating'] ?? 5 ) ) );
		$title  = sanitize_text_field( (string) ( $r['title'] ?? '' ) );
		$text   = trim( (string) ( $r['text'] ?? '' ) );
		if ( '' === $text ) {
			return 0;
		}
		$cid = wp_insert_comment( [
			'comment_post_ID'      => $pid,
			'comment_author'       => $name,
			'comment_content'      => wp_kses_post( $text ),
			'comment_type'         => 'review',
			'comment_approved'     => self::auto_approve() ? 1 : 0,
			'comment_date'         => get_date_from_gmt( $date ),
			'comment_date_gmt'     => $date,
		] );
		if ( ! $cid ) {
			return 0;
		}
		add_comment_meta( (int) $cid, 'rating', $rating );
		add_comment_meta( (int) $cid, 'verified', self::verified() ? 1 : 0 );
		add_comment_meta( (int) $cid, self::GEN_META, 1 ); // tagged: removable in one click.
		// WooCommerce Photo Reviews compatibility: it reads the review title from
		// its own meta, and filters by language on multilingual shops.
		if ( '' !== $title ) {
			add_comment_meta( (int) $cid, 'wcpr_review_title', $title );
		}
		$lang = apply_filters( 'wpml_post_language_details', null, $pid );
		if ( is_array( $lang ) && ! empty( $lang['language_code'] ) ) {
			add_comment_meta( (int) $cid, 'wcpr_current_language', (string) $lang['language_code'] );
		}
		return (int) $cid;
	}

	/** Recomputes the product's rating counts/average after a batch. */
	private static function refresh( int $pid ): void {
		if ( class_exists( 'WC_Comments' ) ) {
			WC_Comments::clear_transients( $pid );
		}
	}

	// =========================================================================
	// Generation (Claude)
	// =========================================================================

	/**
	 * Shipping is a fact of the shop, not something to invent: the model is
	 * either given the real delivery time or forbidden to mention any.
	 */
	private static function delivery_rule(): string {
		$d = self::delivery();
		return '' !== $d
			? 'Délai de livraison réel de la boutique : ' . $d . '. Deux avis au maximum peuvent l\'évoquer, et jamais un autre délai que celui-ci.'
			: 'Ne mentionne JAMAIS le délai de livraison, l\'expédition ni le transporteur : aucun chiffre n\'est disponible.';
	}

	/** Asks the model for $count reviews about a product; returns draft rows. */
	public static function generate( int $pid, int $count, string $prompt_override = '' ): array {
		$product = wc_get_product( $pid );
		if ( ! $product ) {
			throw new RuntimeException( __( 'Product not found.', 'dazont-ecom' ) );
		}
		if ( ! class_exists( 'DZE_Marketing_Ai' ) ) {
			throw new RuntimeException( __( 'The Marketing Assistant module (Settings page) is required for the Anthropic key.', 'dazont-ecom' ) );
		}
		$count = max( 1, min( 20, $count ) );
		$plan  = self::rating_plan( $count ); // ratings decided here, not by the model.
		$desc  = wp_strip_all_tags( (string) $product->get_short_description() ?: (string) $product->get_description() );
		$attrs = '';
		foreach ( $product->get_attributes() as $a ) {
			if ( is_object( $a ) && method_exists( $a, 'get_name' ) ) {
				$vals   = is_callable( [ $a, 'get_options' ] ) ? $a->get_options() : [];
				$attrs .= wc_attribute_label( $a->get_name() ) . ': ' . ( is_array( $vals ) ? implode( ', ', array_map( 'strval', $vals ) ) : '' ) . "\n";
			}
		}
		$system = 'You write authentic customer reviews in ' . self::language() . '. ' . ( class_exists( 'DZE_Content' ) ? DZE_Content::store_context() : '' );
		$user   = "--- PRODUCT ---\nTitle: " . $product->get_name() . "\n"
			. ( $desc ? 'Description: ' . mb_substr( $desc, 0, 900 ) . "\n" : '' )
			. ( $attrs ? "Attributes:\n" . mb_substr( $attrs, 0, 400 ) . "\n" : '' )
			. "\n--- INSTRUCTIONS ---\n" . ( '' !== $prompt_override ? $prompt_override : self::prompt() )
			// Facts the model must never override — language first: the shop's
			// main language wins over the language of the instructions above.
			. "\n\n--- FACTS (never contradict these) ---\n"
			. 'LANGUAGE: write every review — title and text — in ' . self::language() . '. This overrides the language of the instructions above and of the product data. Reviewer names must be plausible for that language.' . "\n"
			. self::delivery_rule() . "\n"
			. "\nGenerate exactly {$count} reviews. The rating of each one is imposed, in this order: " . implode( ', ', $plan ) . " stars. Do not reorder them and do not alternate ratings mechanically — write the text that fits the rating you are given.\n"
			. 'Dates: real calendar dates between ' . gmdate( 'Y-m-d', strtotime( '-' . self::days() . ' days' ) ) . ' and ' . gmdate( 'Y-m-d' ) . ", irregularly spread (not one per week), format YYYY-MM-DD.\n"
			. "Re-read your {$count} reviews before answering: if two of them have a similar length or the same structure, rewrite one of them shorter.\n"
			. "OUTPUT (strict): a JSON array only, no prose, each item {\"name\":\"Firstname L.\",\"rating\":5,\"title\":\"…\",\"text\":\"…\",\"date\":\"YYYY-MM-DD\"}.";

		$raw = DZE_Marketing_Ai::complete( $system, $user, '', 220 * $count + 400, 180 );
		$json = trim( $raw );
		if ( preg_match( '/\[.*\]/s', $json, $m ) ) {
			$json = $m[0];
		}
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( __( 'The model returned an unreadable answer. Try again.', 'dazont-ecom' ) );
		}
		$out = [];
		$i   = 0;
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) || empty( $r['text'] ) ) {
				continue;
			}
			// The plan wins: the model's own rating is only a fallback.
			$rating = $plan[ $i ] ?? max( 1, min( 5, (int) ( $r['rating'] ?? 5 ) ) );
			$i++;
			$out[] = [
				'name'   => sanitize_text_field( (string) ( $r['name'] ?? 'Client' ) ),
				'rating' => $rating,
				'title'  => sanitize_text_field( (string) ( $r['title'] ?? '' ) ),
				'text'   => sanitize_textarea_field( (string) $r['text'] ),
				'date'   => self::clean_date( $r ),
			];
		}
		if ( empty( $out ) ) {
			throw new RuntimeException( __( 'No usable review in the answer.', 'dazont-ecom' ) );
		}
		return $out;
	}

	/**
	 * URL of the native WooCommerce → Reviews screen (WooCommerce 6.7+).
	 *
	 * Detection is by WooCommerce version / class, NOT by inspecting $submenu:
	 * the panel is rendered inside an admin-ajax request, where admin_menu
	 * never runs and $submenu is empty — which is what used to send this link
	 * to the WordPress comments screen instead.
	 */
	public static function reviews_url( string $status = '' ): string {
		$has_wc_page = class_exists( '\Automattic\WooCommerce\Internal\Admin\ProductReviews\ReviewsListTable' )
			|| ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '6.7', '>=' ) );
		$args = $status ? [ 'comment_status' => $status ] : [];
		return $has_wc_page
			? add_query_arg( array_merge( [ 'page' => 'product-reviews' ], $args ), admin_url( 'admin.php' ) )
			: add_query_arg( array_merge( [ 'comment_type' => 'review' ], $args ), admin_url( 'edit-comments.php' ) );
	}

	// =========================================================================
	// Products list column
	// =========================================================================

	public function add_column( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'gmc_status' === $key || ( ! isset( $columns['gmc_status'] ) && 'name' === $key ) ) {
				$new['dze_reviews'] = __( 'Reviews', 'dazont-ecom' );
			}
		}
		if ( ! isset( $new['dze_reviews'] ) ) {
			$new['dze_reviews'] = __( 'Reviews', 'dazont-ecom' );
		}
		return $new;
	}

	public function render_column( string $column, int $post_id ): void {
		if ( 'dze_reviews' !== $column ) {
			return;
		}
		$st    = self::stats( $post_id );
		$color = $st['total'] ? '#2271b1' : '#a7aaad';
		printf(
			'<button type="button" class="dze-rev-open" data-id="%1$d" title="%2$s"><span style="color:%3$s;font-weight:600;">%4$s</span>%5$s<span class="dze-caret">&#9662;</span></button>',
			(int) $post_id,
			esc_attr__( 'Click to generate or manage reviews for this product', 'dazont-ecom' ),
			esc_attr( $color ),
			(int) $st['total'],
			$st['avg'] ? ' <span style="color:#646970;font-size:11px;">★' . esc_html( number_format_i18n( $st['avg'], 1 ) ) . '</span>' : ''
		);
	}

	// =========================================================================
	// Per-product panel (popup on the products list)
	// =========================================================================

	public function render_panel( int $pid ): void {
		$st = self::stats( $pid );
		?>
		<div class="dze-rev-box" data-post="<?php echo (int) $pid; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
			<p class="description" style="margin-top:0;">
				<?php
				printf(
					/* translators: 1: total reviews, 2: pending, 3: generated */
					esc_html__( '%1$s reviews · %2$s awaiting moderation · %3$s generated here', 'dazont-ecom' ),
					'<strong>' . (int) $st['total'] . '</strong>',
					'<strong>' . (int) $st['pending'] . '</strong>',
					'<strong>' . (int) $st['generated'] . '</strong>'
				);
				?>
			</p>
			<p>
				<label for="dze-rev-count-<?php echo (int) $pid; ?>"><?php esc_html_e( 'How many', 'dazont-ecom' ); ?></label>
				<input type="number" id="dze-rev-count-<?php echo (int) $pid; ?>" class="small-text dze-rev-count" min="1" max="20" value="<?php echo (int) self::random_count(); ?>" />
				<button type="button" class="button button-primary dze-rev-gen"><?php esc_html_e( 'Generate', 'dazont-ecom' ); ?></button>
				<button type="button" class="dze-cx-icon dze-rev-ptoggle" title="<?php esc_attr_e( 'Edit the review prompt', 'dazont-ecom' ); ?>">&#9998;</button>
				<span class="dze-rev-status"></span>
			</p>
			<div class="dze-rev-pwrap" style="display:none;">
				<textarea rows="8" class="large-text code dze-rev-ptext"><?php echo esc_textarea( self::prompt() ); ?></textarea>
				<p class="description" style="margin:2px 0 10px;">
					<?php esc_html_e( 'Used for the next generation. Save to keep it.', 'dazont-ecom' ); ?>
					<button type="button" class="button-link dze-rev-psave">&#128190; <?php esc_html_e( 'Save prompt', 'dazont-ecom' ); ?></button>
					<button type="button" class="button-link dze-rev-prestore">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
				</p>
			</div>
			<div class="dze-rev-drafts"></div>
			<p class="dze-rev-actions" style="display:none;">
				<button type="button" class="button button-primary dze-rev-push"></button>
				<button type="button" class="button dze-rev-discard"><?php esc_html_e( 'Discard', 'dazont-ecom' ); ?></button>
			</p>
			<p class="description">
				<?php
				echo self::auto_approve()
					? esc_html__( 'Pushed reviews are published straight away (Settings → Reviews).', 'dazont-ecom' )
					: esc_html__( 'Pushed reviews wait for approval in WooCommerce → Reviews.', 'dazont-ecom' );
				?>
				<a href="<?php echo esc_url( self::reviews_url( 'moderated' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open Reviews', 'dazont-ecom' ); ?></a>
			</p>
			<?php if ( $st['generated'] ) : ?>
				<p><button type="button" class="button button-small dze-rev-del"><?php printf( /* translators: %d: count */ esc_html__( 'Delete the %d generated reviews', 'dazont-ecom' ), (int) $st['generated'] ); ?></button></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Popup shell on the products list. */
	public function list_modal(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}
		?>
		<div class="dze-cx-modal" id="dze-rev-modal"><div class="dze-cx-dialog" style="width:min(700px,94vw);">
			<div class="dze-cx-head"><h2 id="dze-rev-title"><?php esc_html_e( 'Reviews', 'dazont-ecom' ); ?></h2>
				<button type="button" class="button dze-hub-close" style="margin-left:auto;"><?php esc_html_e( 'Close', 'dazont-ecom' ); ?></button></div>
			<div class="dze-cx-body" id="dze-rev-body"></div>
		</div></div>
		<?php
	}

	// =========================================================================
	// Bulk screen
	// =========================================================================

	public function register_bulk_action( array $actions ): array {
		$actions[ self::BULK_ACTION ] = __( 'Generate reviews (Dazont)', 'dazont-ecom' );
		return $actions;
	}

	/**
	 * Queues the selection and returns to the products list: the run happens
	 * there, with a spinner in each product's Reviews cell — no extra screen.
	 */
	public function handle_bulk_action( string $redirect, string $action, array $ids ): string {
		if ( self::BULK_ACTION !== $action || empty( $ids ) ) {
			return $redirect;
		}
		set_transient( 'dze_reviews_queue_' . get_current_user_id(), array_map( 'intval', $ids ), 10 * MINUTE_IN_SECONDS );
		return add_query_arg( 'dze_reviews_run', count( $ids ), $redirect );
	}

	// =========================================================================
	// Assets
	// =========================================================================

	public function enqueue( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type || ! in_array( $screen->base, [ 'edit', 'post' ], true ) ) {
			return;
		}
		// A bulk action just queued a selection: hand it to the runner once.
		$queue = [];
		if ( isset( $_GET['dze_reviews_run'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only trigger.
			$key   = 'dze_reviews_queue_' . get_current_user_id();
			$saved = get_transient( $key );
			if ( is_array( $saved ) ) {
				$queue = array_map( 'intval', $saved );
				delete_transient( $key ); // one run per bulk action, never on refresh.
			}
		}
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		wp_enqueue_script( 'dze-reviews', DZE_URL . 'admin/js/reviews.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-reviews', 'dzeReviews', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::NONCE ),
			// On a product screen: the id of the product, so the button planted
			// in the Reviews box knows which product it is talking about.
			'postId'     => ( 'post' === $screen->base ) ? (int) get_the_ID() : 0,
			'plantLabel' => __( 'Write reviews with AI', 'dazont-ecom' ),
			'queue'      => $queue,
			'reviewsUrl' => self::reviews_url( 'moderated' ),
			'i18n'       => [
				'working'   => __( 'Writing…', 'dazont-ecom' ),
				'error'     => __( 'Something went wrong.', 'dazont-ecom' ),
				'published' => __( 'published', 'dazont-ecom' ),
				'pending'   => __( 'pending', 'dazont-ecom' ),
				'push'      => __( 'Push %s reviews', 'dazont-ecom' ),
				'drop'      => __( 'Remove this review', 'dazont-ecom' ),
				'savedPrompt'   => __( 'Prompt saved ✓', 'dazont-ecom' ),
				'savePrompt'    => __( 'Save prompt', 'dazont-ecom' ),
				'defaultPrompt' => self::default_prompt(),
				'confirmDel'=> __( 'Delete every review generated by this module on this product?', 'dazont-ecom' ),
				'deleted'   => __( 'Deleted', 'dazont-ecom' ),
				'queueRun'  => __( 'Writing reviews: %1$s / %2$s products…', 'dazont-ecom' ),
				'queueDone' => __( 'Done — %1$s reviews created for %2$s products.', 'dazont-ecom' ),
				'openList'  => __( 'Review them in WooCommerce → Reviews', 'dazont-ecom' ),
			],
		] );
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	private function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'moderate_comments' ) || ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
	}

	public function ajax_panel(): void {
		$this->guard();
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		ob_start();
		$this->render_panel( $pid );
		wp_send_json_success( [ 'html' => ob_get_clean(), 'title' => $product->get_name() ] );
	}

	public function ajax_generate(): void {
		$this->guard();
		$pid   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$count = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 0;
		$count = $count > 0 ? min( 20, $count ) : self::random_count();
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 200 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$override = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		try {
			$rows = self::generate( $pid, $count, $override );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		// The panel previews first (push or discard); the bulk screen writes directly.
		if ( empty( $_POST['direct'] ) ) {
			wp_send_json_success( [ 'reviews' => $rows, 'held' => ! self::auto_approve() ] );
		}
		wp_send_json_success( self::create_from( $pid, $rows ) );
	}

	/** Creates the given draft rows and returns the refreshed counters. */
	private static function create_from( int $pid, array $rows ): array {
		$made = 0;
		foreach ( $rows as $r ) {
			if ( is_array( $r ) && self::insert_review( $pid, $r ) ) {
				$made++;
			}
		}
		self::refresh( $pid );
		$st = self::stats( $pid );
		return [
			'created' => $made,
			'total'   => $st['total'],
			'pending' => $st['pending'],
			'held'    => ! self::auto_approve(),
		];
	}

	/** Pushes the reviewed drafts to the reviews screen (pending by default). */
	public function ajax_publish(): void {
		$this->guard();
		$pid  = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$rows = isset( $_POST['reviews'] ) && is_array( $_POST['reviews'] ) ? wp_unslash( $_POST['reviews'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in insert_review().
		if ( ! $pid || empty( $rows ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to push.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( self::create_from( $pid, $rows ) );
	}

	/** Saves the review prompt edited in the popup (read-back verified). */
	public function ajax_save_prompt(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		if ( '' === trim( $prompt ) ) {
			wp_send_json_error( [ 'message' => __( 'Empty prompt.', 'dazont-ecom' ) ] );
		}
		$s           = self::get_settings();
		$s['prompt'] = $prompt;
		update_option( self::OPT, $s, false );
		if ( self::prompt() !== $prompt ) {
			wp_send_json_error( [ 'message' => __( 'The prompt was not persisted — use the Settings page instead.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'saved' => true ] );
	}

	public function ajax_delete(): void {
		$this->guard();
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		$ids = get_comments( [ 'post_id' => $pid, 'type' => 'review', 'fields' => 'ids', 'number' => 0, 'status' => 'all' ] );
		$n   = 0;
		foreach ( $ids as $cid ) {
			if ( get_comment_meta( (int) $cid, self::GEN_META, true ) ) {
				wp_delete_comment( (int) $cid, true );
				$n++;
			}
		}
		self::refresh( $pid );
		$st = self::stats( $pid );
		wp_send_json_success( [ 'deleted' => $n, 'total' => $st['total'] ] );
	}

	// =========================================================================
	// Settings tab (Settings page)
	// =========================================================================

	public function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s = self::get_settings();
		?>
		<div class="dze-admin">
		<div class="notice notice-warning inline" style="margin:0 0 14px;"><p>
			<?php esc_html_e( 'Testing tool. Publishing fabricated customer reviews on a live shop is illegal in the EU (Omnibus directive) and under FTC rules — keep this module for staging/demo catalogues. Every review it creates is tagged and can be deleted in one click from the product panel.', 'dazont-ecom' ); ?>
		</p></div>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Reviews are written by Claude from the product data and saved as native WooCommerce reviews (rating + verified badge), so any review plugin displays them. Generate them from the Reviews column on the products list, or in bulk with the "Generate reviews (Dazont)" bulk action.', 'dazont-ecom' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_reviews_options' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Reviews per product', 'dazont-ecom' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( self::OPT ); ?>[min_count]" class="small-text" min="1" max="20" value="<?php echo (int) self::min_count(); ?>" />
						<span>–</span>
						<input type="number" name="<?php echo esc_attr( self::OPT ); ?>[max_count]" class="small-text" min="1" max="20" value="<?php echo (int) self::max_count(); ?>" />
						<p class="description"><?php esc_html_e( 'A random number in this range is drawn for each product, so the catalogue does not end up with the same count everywhere.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-rev-five"><?php esc_html_e( 'Share of 5-star reviews', 'dazont-ecom' ); ?></label></th>
					<td>
						<input type="number" id="dze-rev-five" name="<?php echo esc_attr( self::OPT ); ?>[five_pct]" class="small-text" min="0" max="100" value="<?php echo (int) self::five_pct(); ?>" /> %
						<p class="description"><?php esc_html_e( 'The rest is mostly 4 stars, with the occasional 3 on larger batches. Ratings are drawn and shuffled by the plugin, never alternated by the model.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'New reviews', 'dazont-ecom' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="<?php echo esc_attr( self::OPT ); ?>[status]" value="pending" <?php checked( ! self::auto_approve() ); ?> /> <?php esc_html_e( 'Wait for moderation in WooCommerce → Reviews', 'dazont-ecom' ); ?></label><br />
							<label><input type="radio" name="<?php echo esc_attr( self::OPT ); ?>[status]" value="approved" <?php checked( self::auto_approve() ); ?> /> <?php esc_html_e( 'Are published immediately', 'dazont-ecom' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-rev-days"><?php esc_html_e( 'Spread over the last', 'dazont-ecom' ); ?></label></th>
					<td><input type="number" id="dze-rev-days" name="<?php echo esc_attr( self::OPT ); ?>[days]" min="1" max="1095" value="<?php echo (int) self::days(); ?>" style="width:80px;" /> <?php esc_html_e( 'days', 'dazont-ecom' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-rev-language"><?php esc_html_e( 'Review language', 'dazont-ecom' ); ?></label></th>
					<td>
						<input type="text" id="dze-rev-language" name="<?php echo esc_attr( self::OPT ); ?>[language]" value="<?php echo esc_attr( (string) ( $s['language'] ?? '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( self::language() ); ?>" />
						<p class="description">
							<?php
							printf(
								/* translators: %s: detected language */
								esc_html__( 'Empty = the shop\'s main language, detected automatically (currently: %s). Fill it in only to force another one.', 'dazont-ecom' ),
								'<strong>' . esc_html( self::language() ) . '</strong>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-rev-delivery"><?php esc_html_e( 'Real delivery time', 'dazont-ecom' ); ?></label></th>
					<td>
						<input type="text" id="dze-rev-delivery" name="<?php echo esc_attr( self::OPT ); ?>[delivery]" value="<?php echo esc_attr( self::delivery() ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. 10 to 14 days', 'dazont-ecom' ); ?>" />
						<p class="description"><?php esc_html_e( 'Reviewers never invent shipping times: leave this empty and delivery is never mentioned at all; fill it in and at most two reviews may mention it, using this figure only.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Verified badge', 'dazont-ecom' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[verified]" value="1" <?php checked( self::verified() ); ?> /> <?php esc_html_e( 'Mark generated reviews as "verified owner"', 'dazont-ecom' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-rev-prompt"><?php esc_html_e( 'Review-writing prompt', 'dazont-ecom' ); ?></label></th>
					<td>
						<textarea id="dze-rev-prompt" name="<?php echo esc_attr( self::OPT ); ?>[prompt]" rows="10" class="large-text code"><?php echo esc_textarea( self::prompt() ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Empty = shipped default (shown greyed). The product data and the strict JSON output format are added automatically.', 'dazont-ecom' ); ?>
							<button type="button" class="button-link" id="dze-rev-restore">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
							<?php if ( class_exists( 'DZE_Prompt_Defaults' ) ) { DZE_Prompt_Defaults::control( 'reviews', '#dze-rev-prompt' ); } ?>
						</p>
						<?php if ( class_exists( 'DZE_Prompts' ) ) { DZE_Prompts::the_data( 'reviews' ); } ?>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save review settings', 'dazont-ecom' ) ); ?>
		</form>
		</div>
		<script>
		jQuery( function ( $ ) {
			// The default of a prompt is the shop's own when it set one, and it
			// can be set from this very page without reloading it.
			function dzeDef( id, shipped ) {
				return window.dzeDefaultFor ? window.dzeDefaultFor( id, shipped ) : shipped;
			}
			$( '#dze-rev-restore' ).on( 'click', function () { $( '#dze-rev-prompt' ).val( dzeDef( 'reviews', <?php echo wp_json_encode( self::default_prompt() ); ?> ) ); } );
		} );
		</script>
		<?php
	}
}
