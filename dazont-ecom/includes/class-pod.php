<?php
defined( 'ABSPATH' ) || exit;

/**
 * POD (print on demand) — the DESIGN of a product, and its print file.
 *
 * What is specific to printing on demand is exactly this: a product carries an
 * artwork file, that file has to be big enough to print, and the supplier has
 * to be handed it when an order comes in. Everything else — making the image
 * of the printed product — is making an image of a product, which this shop
 * does in one place: the image workshop of Product content, where this design
 * appears as a source of its own next to the product's photographs.
 *
 * So this module has no settings screen, no prompt, no generator and no box on
 * the product page. It keeps the design (picked and enlarged from the
 * workshop), and the button on each order line that opens the print file.
 */
final class DZE_Pod {

	// The option this module used to keep — a stored mockup and a POD prompt
	// of its own — is gone: the blank products live on the shared shelf and the
	// prompt is an ordinary image prompt. The key stays declared in the cleanup
	// map so an old install can still be erased of it.
	private const NONCE = 'dze_pod';

	/** Product meta key holding the design attachment id. */
	public const DESIGN_META = '_dze_pod_design_id';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_dze_pod_design', [ $this, 'ajax_design' ] );
		add_action( 'wp_ajax_dze_pod_upscale', [ $this, 'ajax_upscale' ] );
		// Order fulfilment: a small button on each order line whose product has a
		// stored POD design opens the print file in a popup.
		add_action( 'woocommerce_after_order_itemmeta', [ $this, 'order_item_design' ], 10, 3 );
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	/** "1234 × 1234 px (~150 DPI on 30×40 cm)" for a design attachment, or ''. */
	public static function design_dims_note( int $design ): string {
		$meta = $design ? wp_get_attachment_metadata( $design ) : null;
		$w    = (int) ( $meta['width'] ?? 0 );
		$h    = (int) ( $meta['height'] ?? 0 );
		if ( ! $w || ! $h ) {
			return '';
		}
		// A print file is judged in DPI at the size it will be printed, not in
		// pixels: 1200 px is plenty on a badge and useless on a chest print.
		$dpi = (int) round( min( $w, $h ) / ( 30 / 2.54 ) );
		return sprintf(
			/* translators: 1: width, 2: height, 3: dots per inch at 30 cm */
			__( '%1$d × %2$d px (~%3$d DPI on 30 cm)', 'dazont-ecom' ),
			$w,
			$h,
			$dpi
		);
	}

	/** Persist (or clear) the per-product design attachment. */
	private function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
	}

	public function ajax_design(): void {
		$this->guard();
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$att = isset( $_POST['attachment'] ) ? absint( $_POST['attachment'] ) : 0;
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Save the product first.', 'dazont-ecom' ) ] );
		}
		if ( 0 === $att ) {
			delete_post_meta( $pid, self::DESIGN_META );
			wp_send_json_success( [ 'cleared' => true ] );
		}
		if ( ! wp_attachment_is_image( $att ) ) {
			wp_send_json_error( [ 'message' => __( 'The design must be an image (PNG recommended).', 'dazont-ecom' ) ] );
		}
		update_post_meta( $pid, self::DESIGN_META, $att );
		wp_send_json_success( [
			'id'    => $att,
			'thumb' => (string) wp_get_attachment_image_url( $att, 'thumbnail' ),
			'full'  => (string) wp_get_attachment_image_url( $att, 'full' ),
			'dims'  => self::design_dims_note( $att ),
		] );
	}

	/**
	 * Upscales the stored design ×4 through fal.ai ESRGAN and keeps the result
	 * as the new print file (the original stays in the media library). Meant
	 * for AI-generated designs (1024–2048 px) that are too small to print.
	 */
	public function ajax_upscale(): void {
		$this->guard();
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$design = $pid ? absint( get_post_meta( $pid, self::DESIGN_META, true ) ) : 0;
		if ( ! $design ) {
			wp_send_json_error( [ 'message' => __( 'Upload a design on this product first.', 'dazont-ecom' ) ] );
		}
		if ( ! class_exists( 'DZE_Content' ) || '' === DZE_Content::fal_key() ) {
			wp_send_json_error( [ 'message' => __( 'Add your fal.ai key under Settings → General first.', 'dazont-ecom' ) ] );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}
		$path = (string) get_attached_file( $design );
		if ( '' === $path || ! file_exists( $path ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not read the design file.', 'dazont-ecom' ) ] );
		}
		if ( filesize( $path ) > 15 * 1024 * 1024 ) {
			wp_send_json_error( [ 'message' => __( 'The design file is already very large — upscaling is meant for small AI-generated designs.', 'dazont-ecom' ) ] );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 240 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$mime = (string) ( get_post_mime_type( $design ) ?: 'image/png' );
		$src  = 'data:' . $mime . ';base64,' . base64_encode( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI.

		$resp = wp_remote_post( 'https://fal.run/fal-ai/esrgan', [
			'timeout' => 180,
			'headers' => [ 'Authorization' => 'Key ' . DZE_Content::fal_key(), 'content-type' => 'application/json' ],
			'body'    => wp_json_encode( [ 'image_url' => $src, 'scale' => 4 ] ),
		] );
		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( [ 'message' => $resp->get_error_message() ] );
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		$url  = (string) ( $body['image']['url'] ?? ( $body['images'][0]['url'] ?? '' ) );
		if ( $code < 200 || $code >= 300 || '' === $url ) {
			$msg = is_array( $body ) && isset( $body['detail'] ) ? ( is_string( $body['detail'] ) ? $body['detail'] : wp_json_encode( $body['detail'] ) ) : 'HTTP ' . $code;
			wp_send_json_error( [ 'message' => sprintf( __( 'fal.ai upscale error: %s', 'dazont-ecom' ), mb_substr( (string) $msg, 0, 300 ) ) ] );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) ) {
			DZE_Ai_Usage::record( 'fal', 0, 0, 'esrgan', 0.01 ); // rough per-upscale estimate.
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			wp_send_json_error( [ 'message' => $tmp->get_error_message() ] );
		}
		$slug   = sanitize_title( get_the_title( $pid ) ) ?: 'pod-design';
		$att_id = media_handle_sideload( [ 'name' => $slug . '-print.png', 'tmp_name' => $tmp ], $pid, get_the_title( $pid ) . ' — print file' );
		if ( is_wp_error( $att_id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			wp_send_json_error( [ 'message' => $att_id->get_error_message() ] );
		}
		update_post_meta( $pid, self::DESIGN_META, (int) $att_id );
		wp_send_json_success( [
			'thumb' => (string) wp_get_attachment_image_url( (int) $att_id, 'thumbnail' ),
			'dims'  => self::design_dims_note( (int) $att_id ),
		] );
	}

	// =========================================================================
	// Order page: view the print file of POD products in the order
	// =========================================================================

	private bool $order_popup_printed = false;

	/**
	 * Renders a "POD design" button under an order line item — ONLY when the
	 * ordered product (or its variable parent) has a stored design. Opens the
	 * full-size print file in a popup, with a link to the original for the
	 * supplier hand-off.
	 */
	public function order_item_design( $item_id, $item, $product ): void {
		if ( ! is_admin() || ! $product instanceof WC_Product ) {
			return;
		}
		$pid    = $product->get_parent_id() ?: $product->get_id();
		$design = absint( get_post_meta( $pid, self::DESIGN_META, true ) );
		$full   = $design ? wp_get_attachment_image_url( $design, 'full' ) : '';
		if ( ! $full ) {
			return;
		}
		printf(
			'<button type="button" class="button button-small dze-pod-order-view" data-src="%1$s">%2$s</button>',
			esc_url( $full ),
			esc_html__( '🎨 POD design', 'dazont-ecom' )
		);
		if ( ! $this->order_popup_printed ) {
			$this->order_popup_printed = true;
			add_action( 'admin_footer', [ $this, 'order_popup_markup' ] );
		}
	}

	/** Popup shell + behaviour, printed once per order screen. */
	public function order_popup_markup(): void {
		?>
		<div id="dze-pod-order-popup" style="position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:100002;display:none;align-items:center;justify-content:center;">
			<div style="background:#fff;border-radius:8px;padding:14px;max-width:min(92vw,700px);max-height:92vh;overflow:auto;text-align:center;">
				<img src="" alt="" style="max-width:100%;max-height:74vh;height:auto;background:repeating-conic-gradient(#f0f0f1 0 25%,#fff 0 50%) 0 0/16px 16px;border:1px solid #dcdcde;border-radius:4px;" />
				<p style="margin:10px 0 0;">
					<a href="#" target="_blank" rel="noopener" class="button button-primary" id="dze-pod-order-open"><?php esc_html_e( 'Open the print file (full size)', 'dazont-ecom' ); ?></a>
					<button type="button" class="button" id="dze-pod-order-close"><?php esc_html_e( 'Close', 'dazont-ecom' ); ?></button>
				</p>
			</div>
		</div>
		<script>
		jQuery( function ( $ ) {
			var $p = $( '#dze-pod-order-popup' );
			$( document ).on( 'click', '.dze-pod-order-view', function () {
				var src = $( this ).data( 'src' );
				$p.find( 'img' ).attr( 'src', src );
				$( '#dze-pod-order-open' ).attr( 'href', src );
				$p.css( 'display', 'flex' );
			} );
			$( document ).on( 'click', '#dze-pod-order-close', function () { $p.hide(); } );
			$p.on( 'click', function ( e ) { if ( e.target === this ) { $p.hide(); } } );
		} );
		</script>
		<?php
	}
}
