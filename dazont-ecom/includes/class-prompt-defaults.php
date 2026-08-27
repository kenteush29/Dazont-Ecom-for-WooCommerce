<?php
defined( 'ABSPATH' ) || exit;

/**
 * The shop's own default text for a prompt.
 *
 * Every prompt ships with a default, and every prompt offers to put it back.
 * That default is ours, though — written for a shop in general, not for this
 * one. So a prompt reworked over weeks had nowhere to belong: kept as the
 * prompt itself it was one wrong click from being overwritten by "Restore
 * default", and the text restored was never the one the shop actually wanted.
 *
 * "Make this the default" closes that: the text in the box becomes what this
 * shop restores to, and what any block left empty generates from. The shipped
 * text is never lost — it is one click away, under its own name — and setting
 * the shipped text back as the default simply drops our row: what the shop
 * does not override, it does not store.
 *
 * One store for every editable prompt, read only in the admin: shop pages
 * never load it.
 */
final class DZE_Prompt_Defaults {

	public const OPT = 'dze_prompt_defaults';

	private const NONCE = 'dze_prompt_default';

	/** The store, read once per request. */
	private static ?array $cache = null;

	/** Inside shipped(): the shop's default must not answer for the shipped one. */
	private static bool $raw = false;

	/** The modal + its script go out once per screen. */
	private static bool $printed = false;

	public static function init(): void {
		add_action( 'wp_ajax_dze_prompt_default', [ self::class, 'ajax' ] );
	}

	// =========================================================================
	// The store
	// =========================================================================

	/** @return array<string,string> prompt id => the text this shop defaults to. */
	private static function all(): array {
		if ( null === self::$cache ) {
			$v           = get_option( self::OPT, [] );
			self::$cache = is_array( $v ) ? $v : [];
		}
		return self::$cache;
	}

	/** Has this shop set a default of its own for that prompt? */
	public static function has( string $id ): bool {
		return '' !== trim( (string) ( self::all()[ $id ] ?? '' ) );
	}

	/**
	 * The default to use for that prompt.
	 *
	 * Called by every shipped-default accessor, so one answer serves the
	 * "Restore default" buttons, the greyed placeholders and the generations
	 * that run on a prompt left empty — there is no second notion of default
	 * anywhere.
	 */
	public static function pick( string $id, string $shipped ): string {
		// Prompts are written, restored and run from the admin and from cron.
		// A shop page that happens to call a default accessor must not read an
		// option row for it: the shipped text answers there.
		if ( self::$raw || ( ! is_admin() && ! wp_doing_cron() ) ) {
			return $shipped;
		}
		$own = trim( (string) ( self::all()[ $id ] ?? '' ) );
		return '' !== $own ? $own : $shipped;
	}

	/** The text as the plugin ships it, whatever this shop has chosen since. */
	public static function shipped( string $id ): string {
		self::$raw = true;
		try {
			$text = self::ask( $id );
		} catch ( \Throwable $e ) {
			$text = '';
		} finally {
			self::$raw = false;
		}
		return $text;
	}

	/**
	 * Where each prompt's shipped text lives.
	 *
	 * The only place that knows: a prompt is written and stored by its own
	 * module, and this class holds none of them.
	 */
	private static function ask( string $id ): string {
		return class_exists( 'DZE_Prompts' ) ? DZE_Prompts::shipped_for( $id ) : '';
	}

	/** Is this an id we know how to answer for? */
	public static function knows( string $id ): bool {
		return '' !== $id && ( '' !== self::shipped( $id ) || self::has( $id ) );
	}

	/**
	 * Writes the shop's default for one prompt.
	 *
	 * Saving the shipped text as the default deletes our row instead of
	 * storing a copy of it: what the shop does not override, it does not keep.
	 */
	public static function set( string $id, string $text ): bool {
		$text = trim( $text );
		$all  = self::all();
		if ( '' === $text || $text === trim( self::shipped( $id ) ) ) {
			unset( $all[ $id ] );
		} else {
			$all[ $id ] = $text;
		}
		if ( $all ) {
			update_option( self::OPT, $all, false );
		} else {
			delete_option( self::OPT );
		}
		self::$cache = null;
		// Read back: a write that did not land must not be reported as saved.
		return self::has( $id ) === ( '' !== ( $all[ $id ] ?? '' ) );
	}

	/**
	 * Drops the defaults of prompts that no longer exist.
	 *
	 * Scoped by prefix, because the caller only knows its own prompts: the
	 * registry saying which product blocks remain says nothing about the
	 * category or review prompts, and must not erase them.
	 *
	 * @param string   $prefix Ids to consider, e.g. 'content_'.
	 * @param string[] $keep   The ids under that prefix that still exist.
	 */
	public static function forget( string $prefix, array $keep ): void {
		$all  = self::all();
		$kept = $all;
		foreach ( array_keys( $all ) as $id ) {
			if ( 0 === strpos( (string) $id, $prefix ) && ! in_array( (string) $id, $keep, true ) ) {
				unset( $kept[ $id ] );
			}
		}
		if ( count( $kept ) === count( $all ) ) {
			return;
		}
		if ( $kept ) {
			update_option( self::OPT, $kept, false );
		} else {
			delete_option( self::OPT );
		}
		self::$cache = null;
	}

	// =========================================================================
	// The control, next to "Restore default"
	// =========================================================================

	/**
	 * The two buttons that go with a prompt field.
	 *
	 * @param string $id     Prompt id, or '' for a field whose prompt is only
	 *                       known once the screen has opened one (the shared
	 *                       prompt editor); JavaScript fills it in then.
	 * @param string $target CSS selector of the textarea this acts on, looked
	 *                       up inside the closest `.dze-pd-scope` when the
	 *                       field is one row among many.
	 * @param string $label  Overrides the button's wording where "the default"
	 *                       would be read as narrower than it is — rules shared
	 *                       by every block, set from one of them.
	 */
	public static function control( string $id, string $target, string $label = '' ): void {
		if ( '' !== $id && ! self::knows( $id ) ) {
			return; // a custom prompt with no shipped text is its own default.
		}
		self::print_assets();
		$has = '' !== $id && self::has( $id );
		printf(
			'<span class="dze-pd" data-prompt="%1$s" data-fill="%2$s">'
			. '<button type="button" class="button-link dze-pd-set" title="%3$s"%4$s>&#9733; %5$s</button>'
			. '<button type="button" class="button-link dze-pd-ship" title="%6$s"%7$s>&#8634; %8$s</button>'
			. '<span class="dze-pd-state"></span></span>',
			esc_attr( $id ),
			esc_attr( $target ),
			esc_attr__( 'The text in the box becomes what this shop restores to, and what every run uses when this prompt is left empty', 'dazont-ecom' ),
			'' !== $id ? '' : ' style="display:none;"',
			'' !== $label ? esc_html( $label ) : esc_html__( 'Make this the default', 'dazont-ecom' ),
			esc_attr__( 'Put the text the plugin ships back in the box (save to keep it)', 'dazont-ecom' ),
			$has ? '' : ' style="display:none;"',
			esc_html__( 'Shipped text', 'dazont-ecom' )
		);
	}

	/** Same control, returned instead of printed. */
	public static function control_html( string $id, string $target ): string {
		ob_start();
		self::control( $id, $target );
		return (string) ob_get_clean();
	}

	private static function print_assets(): void {
		if ( self::$printed ) {
			return;
		}
		self::$printed = true;
		if ( ! wp_style_is( 'dze-content', 'enqueued' ) ) {
			wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		}
		// A control can be drawn from inside a popup printed in the footer, when
		// 'admin_footer' is already over; the screen's own footer hook runs last.
		$hook = 'admin_footer';
		if ( did_action( 'admin_footer' ) ) {
			global $hook_suffix;
			$hook = 'admin_footer-' . (string) $hook_suffix;
		}
		add_action( $hook, [ self::class, 'render_script' ], PHP_INT_MAX );
	}

	public static function render_script(): void {
		?>
		<script>
		// A default set without leaving the page: every "Restore default" on
		// screen asks here first, so it restores what was just chosen instead
		// of what the page was drawn with.
		window.dzePromptDef = window.dzePromptDef || {};
		window.dzeDefaultFor = function ( id, shipped ) {
			var v = window.dzePromptDef[ id ];
			return ( v && String( v ).length ) ? v : shipped;
		};
		jQuery( function ( $ ) {
			var nonce = '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>';
			// The textarea this control acts on: inside its own row when the
			// screen holds many, anywhere on the page when it holds one.
			function field( $btn ) {
				var $w = $btn.closest( '.dze-pd' ), sel = $w.attr( 'data-fill' ) || '';
				if ( ! sel ) { return $(); }
				var $scope = $w.closest( '.dze-pd-scope' );
				return ( $scope.length ? $scope.find( sel ) : $( sel ) ).first();
			}
			function say( $w, text, ko ) {
				var $s = $w.find( '.dze-pd-state' ).text( text );
				$s.css( 'color', ko ? '#b32d2e' : '#0a7040' );
				if ( ! ko ) { window.setTimeout( function () { $s.text( '' ); }, 2500 ); }
			}
			$( document ).on( 'click', '.dze-pd-set', function ( e ) {
				e.preventDefault();
				var $b = $( this ), $w = $b.closest( '.dze-pd' ), $f = field( $b );
				var id = $w.attr( 'data-prompt' ) || '', text = $f.length ? String( $f.val() || '' ) : '';
				if ( ! id || ! $.trim( text ) ) {
					say( $w, '<?php echo esc_js( __( 'Nothing in the box.', 'dazont-ecom' ) ); ?>', true );
					return;
				}
				$b.prop( 'disabled', true );
				$.post( window.ajaxurl, { action: 'dze_prompt_default', nonce: nonce, op: 'set', id: id, text: text } )
					.done( function ( r ) {
						$b.prop( 'disabled', false );
						if ( ! r || ! r.success ) {
							say( $w, ( r && r.data && r.data.message ) || '<?php echo esc_js( __( 'Not saved.', 'dazont-ecom' ) ); ?>', true );
							return;
						}
						$w.find( '.dze-pd-ship' ).toggle( !! r.data.mine );
						window.dzePromptDef[ id ] = text;
						say( $w, r.data.mine
							? '<?php echo esc_js( __( 'Default set ✓', 'dazont-ecom' ) ); ?>'
							: '<?php echo esc_js( __( 'Back to the shipped text ✓', 'dazont-ecom' ) ); ?>' );
						// Screens holding a copy of this default follow it.
						$( document ).trigger( 'dze:prompt-default', [ id, text ] );
					} )
					.fail( function () {
						$b.prop( 'disabled', false );
						say( $w, '<?php echo esc_js( __( 'Not saved.', 'dazont-ecom' ) ); ?>', true );
					} );
			} );
			$( document ).on( 'click', '.dze-pd-ship', function ( e ) {
				e.preventDefault();
				var $b = $( this ), $w = $b.closest( '.dze-pd' ), $f = field( $b );
				var id = $w.attr( 'data-prompt' ) || '';
				if ( ! id || ! $f.length ) { return; }
				$b.prop( 'disabled', true );
				$.post( window.ajaxurl, { action: 'dze_prompt_default', nonce: nonce, op: 'shipped', id: id } )
					.done( function ( r ) {
						$b.prop( 'disabled', false );
						if ( r && r.success ) { $f.val( r.data.text ).trigger( 'change' ).focus(); }
					} )
					.fail( function () { $b.prop( 'disabled', false ); } );
			} );
		} );
		</script>
		<?php
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	public static function ajax(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$id = preg_replace( '/[^a-z0-9_]/', '', strtolower( $id ) );
		if ( ! self::knows( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown prompt.', 'dazont-ecom' ) ] );
		}
		if ( 'shipped' === $op ) {
			wp_send_json_success( [ 'text' => self::shipped( $id ) ] );
		}
		$text = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		if ( '' === trim( $text ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing in the box.', 'dazont-ecom' ) ] );
		}
		if ( ! self::set( $id, $text ) ) {
			wp_send_json_error( [ 'message' => __( 'Not saved.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'mine' => self::has( $id ) ] );
	}
}
