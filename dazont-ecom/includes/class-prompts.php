<?php
defined( 'ABSPATH' ) || exit;

/**
 * Every prompt the plugin sends, in one register.
 *
 * A generation you did not write the instructions for is a generation you
 * cannot fix — you can only shrug at the result. So wherever the plugin is
 * about to call a model, it says which instructions it is about to send, lets
 * you read them on the spot, and takes you to the field where they are edited.
 *
 * This class holds no prompt of its own: it points at the module that owns
 * each one, so a prompt is written and stored in exactly one place.
 */
final class DZE_Prompts {

	private const NONCE = 'dze_prompt_peek';

	/** Ensures the shared modal + script are printed once per screen. */
	private static bool $printed = false;

	/** catalog() is asked once per button; built once per request. */
	private static ?array $catalog = null;

	public static function init(): void {
		add_action( 'wp_ajax_dze_prompt_peek', [ self::class, 'ajax_peek' ] );
	}

	/**
	 * id => how to reach that prompt.
	 *
	 * 'text' is a callable so nothing is resolved until somebody asks: a screen
	 * that never opens a prompt never reads the prompt option. 'frag' is the id
	 * of the field on the settings page, so "edit these instructions" lands on
	 * the textarea instead of the top of a long tab.
	 *
	 * @return array<string,array{label:string,text:callable,tab:string,frag:string}>
	 */
	public static function catalog(): array {
		if ( null !== self::$catalog ) {
			return self::$catalog;
		}
		$out = [];

		if ( class_exists( 'DZE_Marketing_Ai' ) ) {
			$out['events'] = [
				'label' => __( 'Marketing calendar', 'dazont-ecom' ),
				'text'  => [ 'DZE_Marketing_Ai', 'events_prompt' ],
				'tab'   => 'events',
				'frag'  => 'dze-mai-prompt',
			];
		}
		if ( class_exists( 'DZE_Explorer' ) && self::module_on( 'sourcing' ) ) {
			$out['sourcing_report'] = [
				'label' => __( 'Sourcing report', 'dazont-ecom' ),
				'text'  => [ 'DZE_Explorer', 'report_guidance' ],
				'tab'   => 'sourcing',
				'frag'  => 'dze-mai-report-guidance',
			];
		}
		if ( class_exists( 'DZE_Keywords' ) && self::module_on( 'sourcing' ) ) {
			$out['keyword_match'] = [
				'label' => __( 'Keyword matching', 'dazont-ecom' ),
				'text'  => [ 'DZE_Keywords', 'match_rules' ],
				'tab'   => 'sourcing',
				'frag'  => 'dze-mai-match-rules',
			];
		}
		if ( class_exists( 'DZE_Reviews' ) && self::module_on( 'reviews' ) ) {
			$out['reviews'] = [
				'label' => __( 'Customer reviews', 'dazont-ecom' ),
				'text'  => [ 'DZE_Reviews', 'prompt' ],
				'tab'   => 'reviews',
				'frag'  => 'dze-rev-prompt',
			];
		}
		if ( class_exists( 'DZE_Category_Content' ) && self::module_on( 'category_content' ) ) {
			$out['cat_desc'] = [
				'label' => __( 'Category description', 'dazont-ecom' ),
				'text'  => [ 'DZE_Category_Content', 'prompt' ],
				'tab'   => 'categories',
				'frag'  => 'dze-cc-prompt',
			];
			$out['cat_links'] = [
				'label' => __( 'Internal linking pass', 'dazont-ecom' ),
				'text'  => [ 'DZE_Category_Content', 'links_prompt' ],
				'tab'   => 'categories',
				'frag'  => 'dze-cc-links-prompt',
			];
			$out['cat_sift'] = [
				'label' => __( 'Buyer questions worth answering', 'dazont-ecom' ),
				'text'  => [ 'DZE_Category_Content', 'sift_prompt' ],
				'tab'   => 'categories',
				'frag'  => 'dze-cc-sift-prompt',
			];
		}
		if ( class_exists( 'DZE_Content' ) && self::module_on( 'content' ) ) {
			$out['feature_pick'] = [
				'label' => __( 'Choosing which photograph illustrates a block', 'dazont-ecom' ),
				'text'  => [ 'DZE_Content', 'feature_prompt' ],
				'tab'   => 'content',
				'frag'  => 'dze-ct-feature-prompt',
			];
			// One entry per product field / image template, so the toolbox and the
			// bulk screen can show the exact instructions behind each block.
			foreach ( DZE_Content::registry() as $row ) {
				$rid = (string) ( $row['id'] ?? '' );
				if ( '' === $rid ) {
					continue;
				}
				$out[ 'content_' . $rid ] = [
					'label' => (string) ( $row['name'] ?? $rid ),
					'text'  => static fn(): string => DZE_Content::prompt_for( $rid ),
					'tab'   => 'content',
					'frag'  => 'dze-pr-row-' . $rid,
				];
			}
		}
		if ( class_exists( 'DZE_Pod' ) && self::module_on( 'pod' ) ) {
			$out['pod'] = [
				'label' => __( 'POD mockup', 'dazont-ecom' ),
				'text'  => [ 'DZE_Pod', 'prompt' ],
				'tab'   => 'pod',
				'frag'  => 'dze-pod-prompt',
			];
		}

		self::$catalog = $out;
		return $out;
	}

	private static function module_on( string $id ): bool {
		return ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( $id );
	}

	/** Where that prompt is edited, field included. */
	public static function url( string $id ): string {
		$row = self::catalog()[ $id ] ?? null;
		if ( ! $row || ! class_exists( 'DZE_Marketing_Ai' ) ) {
			return '';
		}
		$url = add_query_arg(
			[ 'page' => DZE_Marketing_Ai::MENU_SLUG, 'tab' => $row['tab'] ],
			admin_url( 'admin.php' )
		);
		return '' !== $row['frag'] ? $url . '#' . $row['frag'] : $url;
	}

	/**
	 * A discreet button opening that prompt.
	 *
	 * Deliberately quiet: you read the instructions when a result disappoints,
	 * not on every screen visit.
	 */
	public static function button( string $id, string $label = '' ): string {
		if ( ! isset( self::catalog()[ $id ] ) ) {
			return '';
		}
		self::print_assets();
		return sprintf(
			'<button type="button" class="dze-prompt-peek" data-prompt="%1$s" title="%2$s">%3$s</button>',
			esc_attr( $id ),
			esc_attr__( 'See the instructions sent to the model, and edit them', 'dazont-ecom' ),
			'' !== $label ? esc_html( $label ) : '&#9998; ' . esc_html__( 'prompt', 'dazont-ecom' )
		);
	}

	/** Same button, printed. */
	public static function the_button( string $id, string $label = '' ): void {
		echo self::button( $id, $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with per-value escaping above.
	}

	/**
	 * Makes the modal available to a screen whose buttons are drawn in
	 * JavaScript (the toolbox drawers, the bulk list). Same markup, no button.
	 */
	public static function print_assets(): void {
		if ( self::$printed ) {
			return;
		}
		self::$printed = true;
		// The modal reuses the shared popup styling, which a screen outside the
		// Content module does not necessarily load.
		if ( ! wp_style_is( 'dze-content', 'enqueued' ) ) {
			wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		}
		// A button can be drawn from inside a popup that is itself printed in the
		// footer, and 'admin_footer' may already be over by then. The screen's own
		// 'admin_footer-<screen>' runs last, so that is where the modal goes — at
		// the very end, never nested inside the popup that asked for it.
		$hook = 'admin_footer';
		if ( did_action( 'admin_footer' ) ) {
			global $hook_suffix;
			$hook = 'admin_footer-' . (string) $hook_suffix;
		}
		add_action( $hook, [ self::class, 'render_modal' ], PHP_INT_MAX );
	}

	public static function render_modal(): void {
		?>
		<div class="dze-cx-modal" id="dze-prompt-modal"><div class="dze-cx-dialog" style="width:min(820px,94vw);">
			<div class="dze-cx-head">
				<h2 id="dze-prompt-title"><?php esc_html_e( 'Instructions sent to the model', 'dazont-ecom' ); ?></h2>
				<a href="#" id="dze-prompt-edit" class="button button-primary" style="margin-left:auto;"><?php esc_html_e( 'Edit these instructions', 'dazont-ecom' ); ?></a>
				<button type="button" class="button dze-hub-close"><?php esc_html_e( 'Close', 'dazont-ecom' ); ?></button>
			</div>
			<div class="dze-cx-body">
				<pre class="dze-prompt-text" id="dze-prompt-text"></pre>
				<p class="description" id="dze-prompt-note"></p>
			</div>
		</div></div>
		<script>
		jQuery( function ( $ ) {
			$( document ).on( 'click', '.dze-prompt-peek', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				var id = $( this ).data( 'prompt' );
				$( '#dze-prompt-text' ).text( '…' );
				$( '#dze-prompt-note' ).text( '' );
				$( '#dze-prompt-modal' ).addClass( 'is-open' );
				$.post( window.ajaxurl, {
					action: 'dze_prompt_peek',
					nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>',
					id: id
				} ).done( function ( r ) {
					if ( ! r || ! r.success ) { $( '#dze-prompt-text' ).text( '<?php echo esc_js( __( 'This prompt could not be read.', 'dazont-ecom' ) ); ?>' ); return; }
					$( '#dze-prompt-title' ).text( r.data.label );
					$( '#dze-prompt-text' ).text( r.data.text );
					$( '#dze-prompt-note' ).text( r.data.note );
					$( '#dze-prompt-edit' ).attr( 'href', r.data.url ).toggle( !! r.data.url );
				} ).fail( function () {
					$( '#dze-prompt-text' ).text( '<?php echo esc_js( __( 'This prompt could not be read.', 'dazont-ecom' ) ); ?>' );
				} );
			} );
			$( document ).on( 'click', '#dze-prompt-modal', function ( e ) {
				if ( e.target === this ) { $( this ).removeClass( 'is-open' ); }
			} );
			$( document ).on( 'click', '#dze-prompt-modal .dze-hub-close', function () {
				$( '#dze-prompt-modal' ).removeClass( 'is-open' );
			} );
		} );
		</script>
		<?php
	}

	public static function ajax_peek(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$id  = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$row = self::catalog()[ $id ] ?? null;
		if ( ! $row || ! is_callable( $row['text'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown prompt.', 'dazont-ecom' ) ] );
		}
		$text = trim( (string) call_user_func( $row['text'] ) );
		wp_send_json_success( [
			'label' => (string) $row['label'],
			'text'  => '' !== $text ? $text : __( 'This block has no instructions of its own.', 'dazont-ecom' ),
			'url'   => self::url( $id ),
			'note'  => __( 'These are the instructions. The product or category data, the shop context, the site language and the answer format are added around them when the call is made.', 'dazont-ecom' ),
		] );
	}
}
