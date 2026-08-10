<?php
defined( 'ABSPATH' ) || exit;

/**
 * One screen for every shortcode the plugin publishes.
 *
 * A shortcode is only useful if you can find its name and its attributes when
 * you are building a page — and hunting for them across four module screens is
 * how a shortcode ends up unused. So they are all documented in one place, one
 * boxed card each, and a module that is switched off takes its card with it.
 *
 * The cards belong to the modules, not to this class: each one describes its
 * own shortcode, and a new shortcode appears here by declaring a card, never by
 * editing this file.
 */
final class DZE_Shortcodes {

	public const MENU_SLUG = 'dazont-ecom-shortcodes';

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', [ self::class, 'menu' ] );
	}

	/**
	 * The cards to draw, in reading order.
	 *
	 * A module that is disabled leaves no trace: it is asked for its card only
	 * when it is enabled, and `class_exists()` alone is never the test.
	 *
	 * @return array<int,array{tag:string,title:string,summary:string,body:callable}>
	 */
	public static function cards(): array {
		$on = static fn( string $id ): bool => ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( $id );
		$out = [];
		foreach ( [
			'trending'     => 'DZE_Trending',
			'marketing_ai' => 'DZE_Marketing_Ai',
			'discounts'    => 'DZE_Discounts',
		] as $module => $class ) {
			if ( ! class_exists( $class ) || ! $on( $module ) || ! is_callable( [ $class, 'shortcode_card' ] ) ) {
				continue;
			}
			$card = (array) call_user_func( [ $class, 'shortcode_card' ] );
			if ( ! empty( $card['tag'] ) && is_callable( $card['body'] ?? null ) ) {
				$out[] = $card;
			}
		}
		return $out;
	}

	public static function menu(): void {
		// No shortcode available means no menu entry at all, rather than a page
		// explaining that there is nothing to see.
		if ( ! self::cards() ) {
			return;
		}
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Shortcodes', 'dazont-ecom' ),
			__( 'Shortcodes', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$cards = self::cards();
		// The card styling lives in the shared admin stylesheet, which the
		// Content module may not be here to load.
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		echo '<div class="wrap dze-wrap dze-admin">';
		echo '<h1>' . esc_html__( 'Shortcodes', 'dazont-ecom' ) . '</h1>';
		echo '<p class="description" style="max-width:900px;">'
			. esc_html__( 'Everything this plugin can render inside a page, a post or a widget. Copy the tag, drop it where you want it, and use the attributes listed under it.', 'dazont-ecom' )
			. '</p>';

		foreach ( $cards as $card ) {
			printf(
				'<div class="dze-sc-card"><div class="dze-sc-head"><h2>%1$s</h2><code class="dze-sc-tag">[%2$s]</code>'
				. '<button type="button" class="button button-small dze-sc-copy" data-tag="%2$s">%3$s</button></div>',
				esc_html( (string) $card['title'] ),
				esc_attr( (string) $card['tag'] ),
				esc_html__( 'Copy', 'dazont-ecom' )
			);
			if ( ! empty( $card['summary'] ) ) {
				echo '<p class="description dze-sc-summary">' . esc_html( (string) $card['summary'] ) . '</p>';
			}
			echo '<div class="dze-sc-body">';
			call_user_func( $card['body'] );
			echo '</div></div>';
		}
		echo '</div>';
		self::copy_script();
	}

	/** The copy button, and nothing else: no library for one clipboard call. */
	private static function copy_script(): void {
		?>
		<script>
		jQuery( function ( $ ) {
			$( document ).on( 'click', '.dze-sc-copy', function () {
				var $b = $( this ), tag = '[' + $b.data( 'tag' ) + ']';
				var done = function () {
					var old = $b.text();
					$b.text( '<?php echo esc_js( __( 'Copied ✓', 'dazont-ecom' ) ); ?>' );
					window.setTimeout( function () { $b.text( old ); }, 1500 );
				};
				if ( window.navigator && navigator.clipboard ) {
					navigator.clipboard.writeText( tag ).then( done, function () {} );
					return;
				}
				// Older browsers, and any page served without a secure context.
				var $t = $( '<textarea>' ).val( tag ).css( { position: 'fixed', opacity: 0 } ).appendTo( 'body' );
				$t[ 0 ].select();
				try { document.execCommand( 'copy' ); done(); } catch ( e ) {}
				$t.remove();
			} );
		} );
		</script>
		<?php
	}
}
