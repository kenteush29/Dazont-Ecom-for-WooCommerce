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
 * Products list: a "Reviews" column shows the count (0 in grey) and opens the
 * per-product panel — generate, review the drafts, edit them, publish.
 */
final class DZE_Reviews {

	private const OPT      = 'dze_reviews_settings';
	private const NONCE    = 'dze_reviews';
	public const BULK_SLUG   = 'dazont-reviews-bulk';
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
		// Products list: count column + popup.
		add_filter( 'manage_edit-product_columns', [ $this, 'add_column' ], 21 );
		add_action( 'manage_product_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
		add_action( 'admin_footer-edit.php', [ $this, 'list_modal' ] );
		// Bulk action → dedicated screen.
		add_filter( 'bulk_actions-edit-product', [ $this, 'register_bulk_action' ] );
		add_filter( 'handle_bulk_actions-edit-product', [ $this, 'handle_bulk_action' ], 10, 3 );
		add_action( 'admin_menu', [ $this, 'register_bulk_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		// AJAX.
		add_action( 'wp_ajax_dze_reviews_panel', [ $this, 'ajax_panel' ] );
		add_action( 'wp_ajax_dze_reviews_generate', [ $this, 'ajax_generate' ] );
		add_action( 'wp_ajax_dze_reviews_publish', [ $this, 'ajax_publish' ] );
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
		] );
	}

	public function sanitize( $in ): array {
		$in  = is_array( $in ) ? $in : [];
		$out = self::get_settings();
		if ( isset( $in['count'] ) ) {
			$out['count'] = max( 1, min( 20, (int) $in['count'] ) );
		}
		if ( isset( $in['min_rating'] ) ) {
			$out['min_rating'] = max( 1, min( 5, (int) $in['min_rating'] ) );
		}
		if ( isset( $in['days'] ) ) {
			$out['days'] = max( 1, min( 1095, (int) $in['days'] ) );
		}
		if ( isset( $in['verified'] ) ) {
			$out['verified'] = ! empty( $in['verified'] ) ? 1 : 0;
		}
		if ( isset( $in['prompt'] ) ) {
			$out['prompt'] = sanitize_textarea_field( (string) $in['prompt'] );
		}
		return $out;
	}

	public static function count_default(): int {
		return max( 1, (int) ( self::get_settings()['count'] ?? 5 ) );
	}

	public static function min_rating(): int {
		$r = (int) ( self::get_settings()['min_rating'] ?? 4 );
		return max( 1, min( 5, $r ) );
	}

	public static function days(): int {
		return max( 1, (int) ( self::get_settings()['days'] ?? 180 ) );
	}

	public static function verified(): bool {
		$s = self::get_settings();
		return ! isset( $s['verified'] ) || ! empty( $s['verified'] );
	}

	public static function default_prompt(): string {
		return <<<'PROMPT'
Tu écris des avis clients authentiques pour une boutique en ligne, à partir des informations du produit.

Règles :
- Chaque avis est écrit par une personne différente : varie le style, la longueur (1 à 4 phrases), le niveau de langue et le vocabulaire.
- Reste concret : parle de l'usage réel, de la qualité perçue, de la taille/matière/finition, du délai de livraison. Pas de langage marketing, pas de superlatifs creux.
- Les avis 4 étoiles contiennent une petite réserve honnête (taille un peu juste, couleur légèrement différente, livraison un peu longue…).
- Aucune mention de prix précis, de code promo, de concurrent, ni de nom de marque inventé.
- Prénom + initiale du nom, cohérents avec la langue de la boutique.
- Un titre court (3 à 6 mots) par avis, dans le même ton que le texte.
- Écris dans la même langue que la fiche produit.
PROMPT;
	}

	public static function prompt(): string {
		$p = trim( (string) ( self::get_settings()['prompt'] ?? '' ) );
		return '' !== $p ? $p : self::default_prompt();
	}

	// =========================================================================
	// Review data helpers
	// =========================================================================

	/** [ 'total' => int, 'generated' => int, 'avg' => float ] for a product. */
	public static function stats( int $pid ): array {
		$comments = get_comments( [
			'post_id'   => $pid,
			'type'      => 'review',
			'status'    => 'approve',
			'fields'    => 'ids',
			'number'    => 0,
		] );
		$gen = 0;
		$sum = 0;
		$n   = 0;
		foreach ( $comments as $cid ) {
			if ( get_comment_meta( (int) $cid, self::GEN_META, true ) ) {
				$gen++;
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
			'avg'       => $n ? round( $sum / $n, 1 ) : 0.0,
		];
	}

	/** Creates ONE native WooCommerce review; returns the comment id. */
	private static function insert_review( int $pid, array $r ): int {
		$days   = max( 0, (int) ( $r['days_ago'] ?? wp_rand( 1, self::days() ) ) );
		$date   = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days', (int) current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
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
			'comment_author_email' => sanitize_email( sanitize_title( $name ) . '@example.com' ),
			'comment_content'      => wp_kses_post( $text ),
			'comment_type'         => 'review',
			'comment_approved'     => 1,
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

	/** Asks the model for $count reviews about a product; returns draft rows. */
	public static function generate( int $pid, int $count ): array {
		$product = wc_get_product( $pid );
		if ( ! $product ) {
			throw new RuntimeException( __( 'Product not found.', 'dazont-ecom' ) );
		}
		if ( ! class_exists( 'DZE_Marketing_Ai' ) ) {
			throw new RuntimeException( __( 'The Marketing Assistant module (Settings page) is required for the Anthropic key.', 'dazont-ecom' ) );
		}
		$count = max( 1, min( 20, $count ) );
		$desc  = wp_strip_all_tags( (string) $product->get_short_description() ?: (string) $product->get_description() );
		$attrs = '';
		foreach ( $product->get_attributes() as $a ) {
			if ( is_object( $a ) && method_exists( $a, 'get_name' ) ) {
				$vals   = is_callable( [ $a, 'get_options' ] ) ? $a->get_options() : [];
				$attrs .= wc_attribute_label( $a->get_name() ) . ': ' . ( is_array( $vals ) ? implode( ', ', array_map( 'strval', $vals ) ) : '' ) . "\n";
			}
		}
		$system = 'You write authentic customer reviews. ' . ( class_exists( 'DZE_Content' ) ? DZE_Content::store_context() : '' );
		$user   = "--- PRODUCT ---\nTitle: " . $product->get_name() . "\n"
			. ( $desc ? 'Description: ' . mb_substr( $desc, 0, 900 ) . "\n" : '' )
			. ( $attrs ? "Attributes:\n" . mb_substr( $attrs, 0, 400 ) . "\n" : '' )
			. "\n--- INSTRUCTIONS ---\n" . self::prompt()
			. "\n\nGenerate exactly {$count} reviews, ratings between " . self::min_rating() . ' and 5, spread over the last ' . self::days() . " days.\n"
			. "OUTPUT (strict): a JSON array only, no prose, each item {\"name\":\"Firstname L.\",\"rating\":5,\"title\":\"…\",\"text\":\"…\",\"days_ago\":42}.";

		$raw = DZE_Marketing_Ai::complete( $system, $user, '', 300 * $count + 300, 180 );
		$json = trim( $raw );
		if ( preg_match( '/\[.*\]/s', $json, $m ) ) {
			$json = $m[0];
		}
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( __( 'The model returned an unreadable answer. Try again.', 'dazont-ecom' ) );
		}
		$out = [];
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) || empty( $r['text'] ) ) {
				continue;
			}
			$out[] = [
				'name'     => sanitize_text_field( (string) ( $r['name'] ?? 'Client' ) ),
				'rating'   => max( 1, min( 5, (int) ( $r['rating'] ?? 5 ) ) ),
				'title'    => sanitize_text_field( (string) ( $r['title'] ?? '' ) ),
				'text'     => sanitize_textarea_field( (string) $r['text'] ),
				'days_ago' => max( 0, min( self::days(), (int) ( $r['days_ago'] ?? wp_rand( 1, self::days() ) ) ) ),
			];
		}
		if ( empty( $out ) ) {
			throw new RuntimeException( __( 'No usable review in the answer.', 'dazont-ecom' ) );
		}
		return $out;
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
		<div class="dze-admin dze-rev-box" data-post="<?php echo (int) $pid; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
			<p class="dze-rev-stats">
				<?php
				printf(
					/* translators: 1: total reviews, 2: average rating, 3: generated count */
					esc_html__( '%1$s reviews · average %2$s · %3$s generated by this module', 'dazont-ecom' ),
					'<strong>' . (int) $st['total'] . '</strong>',
					$st['avg'] ? '★' . esc_html( number_format_i18n( $st['avg'], 1 ) ) : '—',
					'<strong>' . (int) $st['generated'] . '</strong>'
				);
				?>
			</p>
			<p>
				<label><?php esc_html_e( 'How many', 'dazont-ecom' ); ?>
					<input type="number" class="dze-rev-count" min="1" max="20" value="<?php echo (int) self::count_default(); ?>" style="width:70px;" />
				</label>
				<button type="button" class="button button-primary dze-rev-gen"><?php esc_html_e( 'Generate', 'dazont-ecom' ); ?></button>
				<span class="dze-rev-status"></span>
			</p>
			<div class="dze-rev-drafts"></div>
			<p class="dze-rev-actions" style="display:none;">
				<button type="button" class="button button-primary dze-rev-publish"><?php esc_html_e( 'Publish these reviews', 'dazont-ecom' ); ?></button>
			</p>
			<?php if ( $st['generated'] ) : ?>
				<hr />
				<p><button type="button" class="button dze-rev-del"><?php printf( /* translators: %d: count */ esc_html__( 'Delete the %d generated reviews', 'dazont-ecom' ), (int) $st['generated'] ); ?></button></p>
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

	public function handle_bulk_action( string $redirect, string $action, array $ids ): string {
		if ( self::BULK_ACTION !== $action || empty( $ids ) ) {
			return $redirect;
		}
		set_transient( 'dze_reviews_bulk_' . get_current_user_id(), array_map( 'intval', $ids ), HOUR_IN_SECONDS );
		return add_query_arg( [ 'post_type' => 'product', 'page' => self::BULK_SLUG ], admin_url( 'edit.php' ) );
	}

	public function register_bulk_page(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Reviews bulk', 'dazont-ecom' ),
			__( 'Reviews bulk', 'dazont-ecom' ),
			'manage_woocommerce',
			self::BULK_SLUG,
			[ $this, 'render_bulk_page' ]
		);
	}

	public function render_bulk_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$ids = get_transient( 'dze_reviews_bulk_' . get_current_user_id() );
		$ids = is_array( $ids ) ? $ids : [];
		?>
		<div class="wrap dze-wrap dze-admin">
			<h1><?php esc_html_e( 'Reviews — bulk generation', 'dazont-ecom' ); ?></h1>
			<div class="notice notice-warning"><p>
				<?php esc_html_e( 'Testing tool. Publishing fabricated customer reviews on a live shop is illegal in the EU and under FTC rules — use this on staging/demo catalogues only. Every review created here is tagged and can be deleted in one click.', 'dazont-ecom' ); ?>
			</p></div>
			<?php if ( empty( $ids ) ) : ?>
				<p><?php esc_html_e( 'No product queued. Select products on the Products list and pick "Generate reviews (Dazont)" in the Bulk actions menu.', 'dazont-ecom' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<div class="dze-cb-controls" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
				<p>
					<label><?php esc_html_e( 'Reviews per product', 'dazont-ecom' ); ?>
						<input type="number" id="dze-rvb-count" min="1" max="20" value="<?php echo (int) self::count_default(); ?>" style="width:70px;" /></label>
					<label style="margin-left:16px;"><input type="radio" name="dze-rvb-mode" value="review" checked /> <?php esc_html_e( 'Review before publishing', 'dazont-ecom' ); ?></label>
					<label><input type="radio" name="dze-rvb-mode" value="direct" /> <?php esc_html_e( 'Publish immediately', 'dazont-ecom' ); ?></label>
				</p>
				<p>
					<button type="button" class="button button-primary button-hero" id="dze-rvb-start"><?php esc_html_e( 'Generate', 'dazont-ecom' ); ?></button>
					<button type="button" class="button" id="dze-rvb-stop" style="display:none;"><?php esc_html_e( 'Stop', 'dazont-ecom' ); ?></button>
					<button type="button" class="button button-primary" id="dze-rvb-publish" style="display:none;"><?php esc_html_e( 'Publish everything reviewed', 'dazont-ecom' ); ?></button>
				</p>
				<div class="dze-cb-bar" style="display:none;"><div class="dze-cb-fill"></div></div>
				<p id="dze-rvb-progress" class="description"></p>
			</div>

			<table class="dze-cb-table">
				<tr>
					<th style="width:130px;"><?php esc_html_e( 'Image', 'dazont-ecom' ); ?></th>
					<th><?php esc_html_e( 'Product', 'dazont-ecom' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Reviews', 'dazont-ecom' ); ?></th>
					<th style="width:220px;"><?php esc_html_e( 'Status', 'dazont-ecom' ); ?></th>
				</tr>
				<?php
				foreach ( $ids as $pid ) :
					$product = wc_get_product( (int) $pid );
					if ( ! $product ) {
						continue;
					}
					$st  = self::stats( (int) $pid );
					$img = (int) $product->get_image_id();
					?>
					<tr class="dze-rvb-row" data-id="<?php echo (int) $pid; ?>">
						<td class="dze-cb-thumb"><img class="dze-hzoom" src="<?php echo esc_url( $img ? (string) wp_get_attachment_image_url( $img, 'thumbnail' ) : wc_placeholder_img_src() ); ?>" data-full="<?php echo esc_url( $img ? (string) wp_get_attachment_image_url( $img, 'large' ) : '' ); ?>" alt="" /></td>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $pid ) ); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html( $product->get_name() ); ?></strong></a></td>
						<td class="dze-rvb-count"><?php echo (int) $st['total']; ?></td>
						<td class="dze-cb-status">—</td>
					</tr>
					<tr class="dze-rvb-preview" data-id="<?php echo (int) $pid; ?>" style="display:none;"><td colspan="4"></td></tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php
	}

	// =========================================================================
	// Assets
	// =========================================================================

	public function enqueue( string $hook ): void {
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_list = $screen && 'product' === $screen->post_type && 'edit' === $screen->base;
		$on_bulk = ( 'product_page_' . self::BULK_SLUG ) === $hook;
		if ( ! $on_list && ! $on_bulk ) {
			return;
		}
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		wp_enqueue_script( 'dze-reviews', DZE_URL . 'admin/js/reviews.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-reviews', 'dzeReviews', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'working'   => __( 'Writing…', 'dazont-ecom' ),
				'error'     => __( 'Something went wrong.', 'dazont-ecom' ),
				'published' => __( 'published', 'dazont-ecom' ),
				'ready'     => __( 'Drafts ready — edit them if needed, then publish.', 'dazont-ecom' ),
				'confirmDel'=> __( 'Delete every review generated by this module on this product?', 'dazont-ecom' ),
				'deleted'   => __( 'Deleted', 'dazont-ecom' ),
				'name'      => __( 'Name', 'dazont-ecom' ),
				'title'     => __( 'Title', 'dazont-ecom' ),
				'rating'    => __( 'Rating', 'dazont-ecom' ),
				'daysAgo'   => __( 'Days ago', 'dazont-ecom' ),
				'progress'  => __( '%1$s / %2$s products', 'dazont-ecom' ),
				'finished'  => __( 'Finished: %1$s reviews published, %2$s errors.', 'dazont-ecom' ),
				'stopped'   => __( 'Stopped.', 'dazont-ecom' ),
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
		$count = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : self::count_default();
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 200 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		try {
			$rows = self::generate( $pid, $count );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'reviews' => $rows ] );
	}

	public function ajax_publish(): void {
		$this->guard();
		$pid  = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$rows = isset( $_POST['reviews'] ) && is_array( $_POST['reviews'] ) ? wp_unslash( $_POST['reviews'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in insert_review().
		if ( ! $pid || empty( $rows ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to publish.', 'dazont-ecom' ) ] );
		}
		$n = 0;
		foreach ( $rows as $r ) {
			if ( is_array( $r ) && self::insert_review( $pid, $r ) ) {
				$n++;
			}
		}
		self::refresh( $pid );
		$st = self::stats( $pid );
		wp_send_json_success( [ 'published' => $n, 'total' => $st['total'] ] );
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
					<th scope="row"><label for="dze-rev-count"><?php esc_html_e( 'Reviews per product', 'dazont-ecom' ); ?></label></th>
					<td><input type="number" id="dze-rev-count" name="<?php echo esc_attr( self::OPT ); ?>[count]" min="1" max="20" value="<?php echo (int) self::count_default(); ?>" style="width:80px;" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-rev-min"><?php esc_html_e( 'Lowest rating allowed', 'dazont-ecom' ); ?></label></th>
					<td>
						<select id="dze-rev-min" name="<?php echo esc_attr( self::OPT ); ?>[min_rating]">
							<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
								<option value="<?php echo (int) $i; ?>" <?php selected( $i, self::min_rating() ); ?>><?php echo esc_html( $i . ' ★' ); ?></option>
							<?php endfor; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Ratings are spread between this value and 5.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-rev-days"><?php esc_html_e( 'Spread over the last', 'dazont-ecom' ); ?></label></th>
					<td><input type="number" id="dze-rev-days" name="<?php echo esc_attr( self::OPT ); ?>[days]" min="1" max="1095" value="<?php echo (int) self::days(); ?>" style="width:80px;" /> <?php esc_html_e( 'days', 'dazont-ecom' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Verified badge', 'dazont-ecom' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[verified]" value="1" <?php checked( self::verified() ); ?> /> <?php esc_html_e( 'Mark generated reviews as "verified owner"', 'dazont-ecom' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-rev-prompt"><?php esc_html_e( 'Review-writing prompt', 'dazont-ecom' ); ?></label></th>
					<td>
						<textarea id="dze-rev-prompt" name="<?php echo esc_attr( self::OPT ); ?>[prompt]" rows="10" class="large-text code" placeholder="<?php echo esc_attr( self::default_prompt() ); ?>"><?php echo esc_textarea( (string) ( $s['prompt'] ?? '' ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Empty = shipped default (shown greyed). The product data and the strict JSON output format are added automatically.', 'dazont-ecom' ); ?>
							<button type="button" class="button-link" id="dze-rev-restore">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save review settings', 'dazont-ecom' ) ); ?>
		</form>
		</div>
		<script>
		jQuery( function ( $ ) {
			$( '#dze-rev-restore' ).on( 'click', function () { $( '#dze-rev-prompt' ).val( '' ); } );
		} );
		</script>
		<?php
	}
}
