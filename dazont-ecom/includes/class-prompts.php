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
		add_action( 'wp_ajax_dze_prompt_save', [ self::class, 'ajax_save' ] );
	}

	/**
	 * How to write one prompt back, and what its shipped text is.
	 *
	 * Reading a prompt from the screen that runs it, then being sent to a
	 * settings page to change a word, is one screen too many: the editor saves
	 * from where it was opened.
	 *
	 * @return array{save:callable,default:string}|null
	 */
	private static function writer( string $id ): ?array {
		// A product field or an image template: one registry row.
		if ( 0 === strpos( $id, 'content_' ) && class_exists( 'DZE_Content' ) ) {
			$row = substr( $id, strlen( 'content_' ) );
			return [
				'save'    => static fn( string $t ): string => DZE_Content::set_prompt_for( $row, $t )
					? ''
					: __( 'This prompt is not in the registry any more — reload the settings page.', 'dazont-ecom' ),
				'default' => DZE_Content::default_prompt_for( $row ),
			];
		}
		$map = [
			'cat_desc'   => [ 'DZE_Category_Content', 'prompt', 'default_prompt' ],
			'cat_links'  => [ 'DZE_Category_Content', 'links_prompt', 'default_links_prompt' ],
			'cat_sift'   => [ 'DZE_Category_Content', 'sift_prompt', 'default_sift_prompt' ],
			'reviews'    => [ 'DZE_Reviews', 'prompt', 'default_prompt' ],
			'translate'  => [ 'DZE_Translate', 'prompt', 'default_prompt' ],
			'events'     => [ 'DZE_Marketing_Ai', 'events_prompt', 'default_events_prompt' ],
			'promo_i18n' => [ 'DZE_Marketing_Ai', 'promo_i18n_prompt', 'default_promo_i18n_prompt' ],
			'promo_email' => [ 'DZE_Klaviyo', 'email_prompt', 'default_email_prompt' ],
			'promo_email_image' => [ 'DZE_Klaviyo', 'image_prompt', 'default_image_prompt' ],
			'sourcing_report' => [ 'DZE_Explorer', 'report_guidance', 'default_report_guidance' ],
			'keyword_match'   => [ 'DZE_Keywords', 'match_rules', 'default_match_rules' ],
		];
		if ( ! isset( $map[ $id ] ) ) {
			return null;
		}
		[ $class, , $def ] = $map[ $id ];
		if ( ! class_exists( $class ) ) {
			return null;
		}
		// Which option key each module keeps its prompt under.
		$where = [
			'cat_desc'     => [ 'DZE_Category_Content', 'prompt' ],
			'cat_links'    => [ 'DZE_Category_Content', 'links_prompt' ],
			'cat_sift'     => [ 'DZE_Category_Content', 'sift_prompt' ],
			'reviews'      => [ 'DZE_Reviews', 'prompt' ],
			'translate'    => [ 'DZE_Translate', 'prompt' ],
			'events'       => [ 'DZE_Marketing_Ai', 'events_prompt' ],
			'promo_i18n'   => [ 'DZE_Marketing_Ai', 'promo_i18n_prompt' ],
			'promo_email'  => [ 'DZE_Klaviyo', 'email_prompt' ],
			'promo_email_image' => [ 'DZE_Klaviyo', 'image_prompt' ],
			'sourcing_report' => [ 'DZE_Marketing_Ai', 'report_guidance' ],
			'keyword_match'   => [ 'DZE_Marketing_Ai', 'match_rules' ],
		][ $id ];
		$default = ( '' !== $def && is_callable( [ $class, $def ] ) ) ? (string) call_user_func( [ $class, $def ] ) : '';
		return [
			'save'    => static function ( string $t ) use ( $where, $default ): string {
				[ $owner, $key ] = $where;
				// Saved exactly as shipped means "no custom prompt".
				$value = ( '' !== $default && trim( $t ) === trim( $default ) ) ? '' : $t;
				return self::write_option( $owner, $key, $value );
			},
			'default' => $default,
		];
	}

	/**
	 * Writes one key of a module's settings row, around its form sanitizer.
	 *
	 * The registered sanitizers are shaped for FORM input and drop whatever the
	 * form did not post, so a one-key write has to step around them and read
	 * back to prove it landed.
	 */
	private static function write_option( string $class, string $key, string $value ): string {
		$opts = [
			'DZE_Content'          => 'dze_content_settings',
			'DZE_Category_Content' => 'dze_catcontent_settings',
			'DZE_Reviews'          => 'dze_reviews_settings',
			'DZE_Translate'        => 'dze_translate_settings',
			'DZE_Marketing_Ai'     => 'dze_mai_settings',
			'DZE_Klaviyo'          => 'dze_klaviyo',
		];
		$name = $opts[ $class ] ?? '';
		if ( '' === $name ) {
			/* translators: %s: the module that owns the prompt */
			return sprintf( __( 'No settings row is known for %s.', 'dazont-ecom' ), $class );
		}
		$row         = get_option( $name, [] );
		$row         = is_array( $row ) ? $row : [];
		$row[ $key ] = $value;
		$filter      = 'sanitize_option_' . $name;
		global $wp_filter;
		$saved = $wp_filter[ $filter ] ?? null;
		unset( $wp_filter[ $filter ] ); // the form sanitizer must not run here.
		update_option( $name, $row, false );
		if ( null !== $saved ) {
			$wp_filter[ $filter ] = $saved;
		}
		// Read back from the TABLE, not through get_option(): a plugin filtering
		// that option — WPML string translation is the usual one — would answer
		// with its own version, and a write that landed perfectly would be
		// reported as a failure. What is being checked is what is stored.
		global $wpdb;
		wp_cache_delete( $name, 'options' );
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		$check = maybe_unserialize( (string) $raw );
		if ( ! is_array( $check ) ) {
			/* translators: %s: option name */
			return sprintf( __( 'The settings row %s came back unreadable.', 'dazont-ecom' ), $name );
		}
		$now = (string) ( $check[ $key ] ?? '' );
		if ( $now === $value ) {
			return '';
		}
		// It landed as something else: say what, rather than "not saved". A
		// filter on the way through is the usual culprit, and a silent one is
		// what makes this kind of thing take an afternoon.
		return sprintf(
			/* translators: 1: option name, 2: key, 3: characters sent, 4: characters stored */
			__( 'Written to %1$s[%2$s], but read back different: %3$d characters sent, %4$d stored. Something is filtering that option.', 'dazont-ecom' ),
			$name,
			$key,
			mb_strlen( $value ),
			mb_strlen( $now )
		);
	}

	/** Saves a prompt from the editor, wherever it was opened. */
	public static function ajax_save(): void {
		// A token that expired because the screen stayed open all afternoon is
		// not "the prompt was not saved": it is a page to reload, and saying so
		// is the difference between one click and an afternoon.
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'This page has been open too long and its security token expired. Reload the page, then save again — your text is still in the box.', 'dazont-ecom' ) ], 403 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$id   = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$text = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		if ( '' === trim( $text ) ) {
			wp_send_json_error( [ 'message' => __( 'An empty prompt would generate nothing.', 'dazont-ecom' ) ] );
		}
		$w = self::writer( $id );
		if ( ! $w ) {
			wp_send_json_error( [ 'message' => __( 'This prompt cannot be saved from here.', 'dazont-ecom' ) ] );
		}
		try {
			$why = (string) call_user_func( $w['save'], $text );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		if ( '' !== $why ) {
			wp_send_json_error( [ 'message' => $why ] );
		}
		wp_send_json_success( [ 'saved' => true ] );
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
			$out['promo_i18n'] = [
				'label' => __( 'Promotion translations', 'dazont-ecom' ),
				'text'  => [ 'DZE_Marketing_Ai', 'promo_i18n_prompt' ],
				'tab'   => 'events',
				'frag'  => 'dze-mai-promo-i18n',
			];
		}
		if ( class_exists( 'DZE_Klaviyo' ) && self::module_on( 'klaviyo' ) ) {
			$out['promo_email'] = [
				'label' => __( 'Promotion email', 'dazont-ecom' ),
				'text'  => [ 'DZE_Klaviyo', 'email_prompt' ],
				'tab'   => 'email',
				'frag'  => 'dze-klav-prompt',
			];
			$out['promo_email_image'] = [
				'label' => __( 'Promotion email picture', 'dazont-ecom' ),
				'text'  => [ 'DZE_Klaviyo', 'image_prompt' ],
				'tab'   => 'email',
				'frag'  => 'dze-klav-img-prompt',
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
			// Neither the main-image recipe nor the photograph-picking rules are
			// listed here: both live on a registry row, and rows are added below.
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
		if ( class_exists( 'DZE_Translate' ) && self::module_on( 'translate' ) ) {
			$out['translate'] = [
				'label' => __( 'Product translation', 'dazont-ecom' ),
				'text'  => [ 'DZE_Translate', 'prompt' ],
				'tab'   => 'translate',
				'frag'  => 'dze-tr-prompt',
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

	/**
	 * The prompt editor.
	 *
	 * Not a viewer with a link to the settings: the whole point is to fix a
	 * word and run again without leaving the screen that runs it. Read, edit,
	 * save — the page never reloads. The link to the settings stays for the
	 * rest of that prompt's configuration (where it writes, its length).
	 */
	public static function render_modal(): void {
		?>
		<div class="dze-cx-modal" id="dze-prompt-modal"><div class="dze-cx-dialog" style="width:min(880px,94vw);">
			<div class="dze-cx-head">
				<h2 id="dze-prompt-title"><?php esc_html_e( 'Instructions sent to the model', 'dazont-ecom' ); ?></h2>
				<span id="dze-prompt-state" class="dze-cx-state" style="margin-left:auto;"></span>
				<button type="button" class="button dze-hub-close"><?php esc_html_e( 'Close', 'dazont-ecom' ); ?></button>
			</div>
			<div class="dze-cx-body">
				<textarea id="dze-prompt-text" rows="16" class="large-text code"></textarea>
				<p class="dze-prompt-bar">
					<button type="button" class="button button-primary" id="dze-prompt-save"><?php esc_html_e( 'Save the prompt', 'dazont-ecom' ); ?></button>
					<button type="button" class="button-link" id="dze-prompt-reset">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
					<?php
					// Which prompt this is only becomes known when one is opened,
					// so the control is printed empty and JavaScript names it.
					if ( class_exists( 'DZE_Prompt_Defaults' ) ) {
						DZE_Prompt_Defaults::control( '', '#dze-prompt-text' );
					}
					?>
					<a href="#" id="dze-prompt-edit" target="_blank" rel="noopener" class="dze-prompt-more"><?php esc_html_e( 'Its other settings →', 'dazont-ecom' ); ?></a>
				</p>
				<p class="description" id="dze-prompt-note"></p>
			</div>
		</div></div>
		<script>
		jQuery( function ( $ ) {
			var cur = '', def = '';
			$( document ).on( 'click', '.dze-prompt-peek', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				cur = $( this ).data( 'prompt' );
				$( '#dze-prompt-text' ).val( '…' ).prop( 'disabled', true );
				$( '#dze-prompt-state' ).text( '' ).removeClass( 'is-ko' );
				$( '#dze-prompt-modal' ).addClass( 'is-open' );
				$.post( window.ajaxurl, {
					action: 'dze_prompt_peek',
					nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>',
					id: cur
				} ).done( function ( r ) {
					if ( ! r || ! r.success ) {
						$( '#dze-prompt-text' ).val( '<?php echo esc_js( __( 'This prompt could not be read.', 'dazont-ecom' ) ); ?>' );
						return;
					}
					def = r.data.def || '';
					$( '#dze-prompt-title' ).text( r.data.label );
					$( '#dze-prompt-text' ).val( r.data.text ).prop( 'disabled', ! r.data.editable );
					$( '#dze-prompt-note' ).text( r.data.note );
					$( '#dze-prompt-save' ).toggle( !! r.data.editable );
					$( '#dze-prompt-reset' ).toggle( !! r.data.editable && !! def );
					$( '#dze-prompt-edit' ).attr( 'href', r.data.url ).toggle( !! r.data.url );
					// The default control now knows which prompt it acts on.
					$( '#dze-prompt-modal .dze-pd' ).attr( 'data-prompt', r.data.own ? cur : '' )
						.find( '.dze-pd-state' ).text( '' ).end()
						.find( '.dze-pd-set' ).toggle( !! r.data.editable && !! r.data.own ).end()
						.find( '.dze-pd-ship' ).toggle( !! r.data.mine );
				} ).fail( function () {
					$( '#dze-prompt-text' ).val( '<?php echo esc_js( __( 'This prompt could not be read.', 'dazont-ecom' ) ); ?>' );
				} );
			} );
			$( document ).on( 'click', '#dze-prompt-reset', function () { $( '#dze-prompt-text' ).val( def ); } );
			// A default just set from here is what "Restore default" restores to.
			$( document ).on( 'dze:prompt-default', function ( e, id, text ) {
				if ( id === cur ) { def = text; }
			} );
			// One place that puts a failure on screen, with the reason the server
			// gave and, when it gave none, the status it answered with.
			function say( message, status ) {
				var $st = $( '#dze-prompt-state' ).addClass( 'is-ko' );
				if ( message ) { $st.text( message ); return; }
				$st.text( status
					? '<?php echo esc_js( __( 'The prompt was not saved — the server answered', 'dazont-ecom' ) ); ?>' + ' ' + status + '.'
					: '<?php echo esc_js( __( 'The prompt was not saved — the server could not be reached.', 'dazont-ecom' ) ); ?>' );
			}
			$( document ).on( 'click', '#dze-prompt-save', function () {
				var $b = $( this ).prop( 'disabled', true );
				var $st = $( '#dze-prompt-state' ).removeClass( 'is-ko' ).text( '…' );
				$.post( window.ajaxurl, {
					action: 'dze_prompt_save',
					nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>',
					id: cur,
					text: $( '#dze-prompt-text' ).val()
				} ).done( function ( r ) {
					$b.prop( 'disabled', false );
					if ( r && r.success ) {
						$st.text( '<?php echo esc_js( __( 'Saved ✓', 'dazont-ecom' ) ); ?>' );
						window.setTimeout( function () { $st.text( '' ); }, 2000 );
						// The screens holding a copy of this prompt pick it up.
						$( document ).trigger( 'dze:prompt-saved', [ cur, $( '#dze-prompt-text' ).val() ] );
						return;
					}
					say( ( r && r.data && r.data.message ) || '' );
				} ).fail( function ( xhr ) {
					$b.prop( 'disabled', false );
					// The server refused or fell over: whatever it said is worth
					// more than one message for every possible failure.
					var d = xhr && xhr.responseJSON && xhr.responseJSON.data;
					say( ( d && d.message ) || '', xhr ? xhr.status : 0 );
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
		$w = self::writer( $id );
		// Whether this prompt can have a default of its own, and whether the
		// shop has already set one — the two states the control shows.
		$own  = class_exists( 'DZE_Prompt_Defaults' ) && DZE_Prompt_Defaults::knows( $id );
		wp_send_json_success( [
			'label'    => (string) $row['label'],
			'text'     => $text,
			'def'      => $w ? (string) $w['default'] : '',
			'editable' => (bool) $w,
			'own'      => $own,
			'mine'     => $own && DZE_Prompt_Defaults::has( $id ),
			'url'      => self::url( $id ),
			'note'     => __( 'Saved here, this is what every run uses from now on. The product or category data, the shop context, the site language and the answer format are added around it when the call is made.', 'dazont-ecom' ),
		] );
	}
}
