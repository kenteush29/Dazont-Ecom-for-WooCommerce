<?php
/**
 * Image lab — a bench for images that belong to no product yet.
 *
 * Everything else in this plugin makes an image FOR something: a product's
 * main image, one colour's photograph, a POD mockup. This makes an image for
 * nothing in particular: a blank mockup to shoot future products on, a
 * backdrop, a test of a prompt before it is trusted with a catalogue.
 *
 * It exists because that work was being done in a public chat with its own
 * limits, its own account and its own history — outside the shop, outside the
 * budget, and with the result to download and re-upload by hand. Here it uses
 * the fal key already configured, records its calls in the same usage report,
 * respects the same monthly budget, and puts what you keep straight into the
 * media library, named and with its alt text like every other image.
 *
 * Footprint: an admin screen and two AJAX actions. No front hook, no option,
 * no post meta. What it keeps, it keeps in the library — WordPress's own data.
 *
 * @package Dazont_Ecom
 */

defined( 'ABSPATH' ) || exit;

final class DZE_Image_Lab {

	private static ?self $instance = null;

	/** Source images accepted in one call. */
	private const MAX_SOURCES = 4;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_dze_lab_generate', [ $this, 'ajax_generate' ] );
		add_action( 'wp_ajax_dze_lab_keep', [ $this, 'ajax_keep' ] );
	}

	/** Only on its own tab: an admin screen pays for what it loads. */
	private function on_tab(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading which screen is being drawn.
		return isset( $_GET['page'], $_GET['tab'] )
			&& class_exists( 'DZE_Marketing_Ai' )
			&& DZE_Marketing_Ai::MENU_SLUG === $_GET['page']
			&& 'lab' === $_GET['tab'];
		// phpcs:enable
	}

	public function enqueue( string $hook ): void {
		if ( ! $this->on_tab() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		wp_enqueue_style( 'dze-zoom', DZE_URL . 'admin/css/zoom.css', [], DZE_VERSION );
		wp_enqueue_script( 'dze-hzoom', DZE_URL . 'admin/js/hzoom.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-hzoom', 'dzeZoomI18n', [
			'zoom'  => __( 'See this image full size', 'dazont-ecom' ),
			'close' => __( 'Close', 'dazont-ecom' ),
			'prev'  => __( 'Previous image', 'dazont-ecom' ),
			'next'  => __( 'Next image', 'dazont-ecom' ),
		] );
		wp_enqueue_script( 'dze-image-lab', DZE_URL . 'admin/js/image-lab.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-image-lab', 'dzeLab', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'dze_lab' ),
			'max'     => self::MAX_SOURCES,
			'i18n'    => [
				'drop'     => __( 'Paste an image here (Ctrl+V), drop a file, or', 'dazont-ecom' ),
				'browse'   => __( 'choose one on your computer', 'dazont-ecom' ),
				'library'  => __( 'or pick one from the library', 'dazont-ecom' ),
				'libTitle' => __( 'Choose an image to work from', 'dazont-ecom' ),
				'use'      => __( 'Use this one', 'dazont-ecom' ),
				'remove'   => __( 'Take this image out', 'dazont-ecom' ),
				'working'  => __( 'Generating — up to a minute…', 'dazont-ecom' ),
				'error'    => __( 'Something went wrong.', 'dazont-ecom' ),
				'keep'     => __( 'Save in the library', 'dazont-ecom' ),
				'name'     => __( 'Name', 'dazont-ecom' ),
				'namePh'   => __( 'e.g. Blank white tee mockup', 'dazont-ecom' ),
				'kept'     => __( 'Saved in the library ✓', 'dazont-ecom' ),
				'download' => __( 'Download', 'dazont-ecom' ),
				'again'    => __( 'Generate again', 'dazont-ecom' ),
				'noPrompt' => __( 'Write what you want first.', 'dazont-ecom' ),
				/* translators: %s: number of attempts on screen */
				'tries'    => __( '%s results — the newest first', 'dazont-ecom' ),
			],
		] );
	}

	/** The tab itself. */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$key = class_exists( 'DZE_Content' ) ? DZE_Content::fal_key() : '';
		?>
		<h2><?php esc_html_e( 'Image lab', 'dazont-ecom' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'A bench for images that belong to no product yet: a blank mockup to shoot future products on, a backdrop for the shelf, or simply a prompt tried before it is trusted with a catalogue. Same model and same key as the product images, the same monthly budget, and what you keep goes into the media library named and with its alt text.', 'dazont-ecom' ); ?>
		</p>
		<?php if ( '' === $key ) : ?>
			<div class="notice notice-error inline"><p>
				<?php esc_html_e( 'Add your fal.ai key under Settings → General first.', 'dazont-ecom' ); ?>
			</p></div>
			<?php return; ?>
		<?php endif; ?>

		<div class="dze-lab">
			<p class="dze-lab-q"><?php esc_html_e( 'What do you want?', 'dazont-ecom' ); ?></p>
			<textarea id="dze-lab-prompt" rows="6" class="large-text code" placeholder="<?php esc_attr_e( 'e.g. Remove the printed patch and give me the blank white t-shirt, same fabric, same light, same angle, on the same background.', 'dazont-ecom' ); ?>"></textarea>

			<p class="dze-lab-q"><?php esc_html_e( 'From which images?', 'dazont-ecom' ); ?>
				<span class="description"><?php
					printf(
						/* translators: %s: how many images can be sent at once */
						esc_html__( 'Up to %s. Nothing is stored on the site: they travel inside the request.', 'dazont-ecom' ),
						(int) self::MAX_SOURCES
					);
				?></span>
			</p>
			<div class="dze-lab-srcs" id="dze-lab-srcs"></div>
			<div class="dze-qm-drop" id="dze-lab-drop" tabindex="0">
				<span class="dze-qm-dropmsg"><?php esc_html_e( 'Paste an image here (Ctrl+V), drop a file, or', 'dazont-ecom' ); ?></span>
				<button type="button" class="button button-small dze-lab-browse"><?php esc_html_e( 'choose one on your computer', 'dazont-ecom' ); ?></button>
				<input type="file" accept="image/*" class="dze-lab-file" hidden multiple />
				<button type="button" class="button button-small dze-lab-lib"><?php esc_html_e( 'or pick one from the library', 'dazont-ecom' ); ?></button>
			</div>

			<p class="dze-lab-bar">
				<button type="button" class="button button-primary button-hero" id="dze-lab-run"><?php esc_html_e( 'Generate', 'dazont-ecom' ); ?></button>
				<span class="dze-lab-state" id="dze-lab-state"></span>
			</p>

			<div class="dze-lab-outwrap" id="dze-lab-outwrap" style="display:none;">
				<p class="dze-lab-q" id="dze-lab-outcap"></p>
				<div class="dze-lab-out dze-zoomgroup" id="dze-lab-out"></div>
			</div>
		</div>
		<?php
	}

	/** One call to the provider, with whatever was put on the bench. */
	public function ajax_generate(): void {
		check_ajax_referer( 'dze_lab', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		if ( ! class_exists( 'DZE_Content' ) ) {
			wp_send_json_error( [ 'message' => __( 'Product content is switched off.', 'dazont-ecom' ) ] );
		}
		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		if ( '' === trim( $prompt ) ) {
			wp_send_json_error( [ 'message' => __( 'Write what you want first.', 'dazont-ecom' ) ] );
		}
		if ( '' === DZE_Content::fal_key() ) {
			wp_send_json_error( [ 'message' => __( 'Add your fal.ai key under Settings → General first.', 'dazont-ecom' ) ] );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}
		$content = DZE_Content::instance();
		$sources = [];
		try {
			// Pasted, dropped or chosen from a folder: bytes in the request.
			foreach ( (array) ( $_POST['pasted'] ?? [] ) as $uri ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an image below.
				if ( count( $sources ) >= self::MAX_SOURCES ) {
					break;
				}
				$sources[] = DZE_Content::read_data_uri( (string) wp_unslash( $uri ) );
			}
			// Already in the library: read from disk, not fetched over HTTP.
			foreach ( (array) ( $_POST['ids'] ?? [] ) as $id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast below.
				if ( count( $sources ) >= self::MAX_SOURCES ) {
					break;
				}
				$id = absint( $id );
				if ( $id && wp_attachment_is_image( $id ) ) {
					$sources[] = $content->fal_source_data_uri( $id, 'full' );
				}
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		if ( ! $sources ) {
			wp_send_json_error( [ 'message' => __( 'Add at least one image to work from.', 'dazont-ecom' ) ] );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		try {
			DZE_Ai_Usage::unit( 'product_img' );
			$url = $content->fal_generate( $prompt, $sources );
			DZE_Ai_Usage::unit();
			DZE_Ai_Usage::finished( 'product_img' );
		} catch ( \Throwable $e ) {
			DZE_Ai_Usage::unit();
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'url' => $url ] );
	}

	/** Kept: into the library, by the same road as every other image. */
	public function ajax_keep(): void {
		check_ajax_referer( 'dze_lab', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$url  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( ! class_exists( 'DZE_Content' ) || '' === $url || ! DZE_Content::is_fal_url( $url ) ) {
			wp_send_json_error( [ 'message' => __( 'That image did not come from the generator.', 'dazont-ecom' ) ] );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			wp_send_json_error( [ 'message' => $tmp->get_error_message() ] );
		}
		// The name you chose becomes the file name, the attachment title and
		// the alt text — one word typed once, in the three places that matter.
		$title = '' !== trim( $name ) ? trim( $name ) : __( 'Image lab', 'dazont-ecom' );
		$title = mb_substr( $title, 0, 80 );
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		try {
			$att = DZE_Content::instance()->file_to_library(
				(string) $tmp,
				strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ),
				sanitize_title( $title ),
				$title
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'id'    => (int) $att,
			'edit'  => (string) get_edit_post_link( (int) $att, 'raw' ),
			'thumb' => (string) ( wp_get_attachment_image_url( (int) $att, 'thumbnail' ) ?: '' ),
		] );
	}
}
