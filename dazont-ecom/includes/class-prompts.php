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
				'save'    => static fn( string $t ): bool => DZE_Content::set_prompt_for( $row, $t ),
				'default' => DZE_Content::default_prompt_for( $row ),
			];
		}
		$map = [
			'quick_main' => [ 'DZE_Content', 'quick_prompt', 'default_quick_prompt' ],
			'feature_pick' => [ 'DZE_Content', 'feature_prompt', 'default_feature_prompt' ],
			'cat_desc'   => [ 'DZE_Category_Content', 'prompt', 'default_prompt' ],
			'cat_links'  => [ 'DZE_Category_Content', 'links_prompt', 'default_links_prompt' ],
			'cat_sift'   => [ 'DZE_Category_Content', 'sift_prompt', 'default_sift_prompt' ],
			'reviews'    => [ 'DZE_Reviews', 'prompt', 'default_prompt' ],
			'pod'        => [ 'DZE_Pod', 'prompt', 'default_prompt' ],
			'translate'  => [ 'DZE_Translate', 'prompt', 'default_prompt' ],
			'events'     => [ 'DZE_Marketing_Ai', 'events_prompt', '' ],
			'sourcing_report' => [ 'DZE_Explorer', 'report_guidance', '' ],
			'keyword_match'   => [ 'DZE_Keywords', 'match_rules', '' ],
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
			'quick_main'   => [ 'DZE_Content', 'quick_prompt' ],
			'feature_pick' => [ 'DZE_Content', 'feature_prompt' ],
			'cat_desc'     => [ 'DZE_Category_Content', 'prompt' ],
			'cat_links'    => [ 'DZE_Category_Content', 'links_prompt' ],
			'cat_sift'     => [ 'DZE_Category_Content', 'sift_prompt' ],
			'reviews'      => [ 'DZE_Reviews', 'prompt' ],
			'pod'          => [ 'DZE_Pod', 'prompt' ],
			'translate'    => [ 'DZE_Translate', 'prompt' ],
			'events'       => [ 'DZE_Marketing_Ai', 'events_prompt' ],
			'sourcing_report' => [ 'DZE_Marketing_Ai', 'report_guidance' ],
			'keyword_match'   => [ 'DZE_Marketing_Ai', 'match_rules' ],
		][ $id ];
		$default = ( '' !== $def && is_callable( [ $class, $def ] ) ) ? (string) call_user_func( [ $class, $def ] ) : '';
		return [
			'save'    => static function ( string $t ) use ( $where, $default ): bool {
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
	private static function write_option( string $class, string $key, string $value ): bool {
		$opts = [
			'DZE_Content'          => 'dze_content_settings',
			'DZE_Category_Content' => 'dze_catcontent_settings',
			'DZE_Reviews'          => 'dze_reviews_settings',
			'DZE_Pod'              => 'dze_pod_settings',
			'DZE_Translate'        => 'dze_translate_settings',
			'DZE_Marketing_Ai'     => 'dze_mai_settings',
		];
		$name = $opts[ $class ] ?? '';
		if ( '' === $name ) {
			return false;
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
		$check = get_option( $name, [] );
		return is_array( $check ) && (string) ( $check[ $key ] ?? '' ) === $value;
	}

	/** Saves a prompt from the editor, wherever it was opened. */
	public static function ajax_save(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
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
			$ok = (bool) call_user_func( $w['save'], $text );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		if ( ! $ok ) {
			wp_send_json_error( [ 'message' => __( 'The prompt was not saved.', 'dazont-ecom' ) ] );
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
			$out['quick_main'] = [
				'label' => __( 'Main image, made on the spot', 'dazont-ecom' ),
				'text'  => [ 'DZE_Content', 'quick_prompt' ],
				'tab'   => 'content',
				'frag'  => 'dze-ct-quick-prompt',
			];
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
		if ( class_exists( 'DZE_Translate' ) && self::module_on( 'translate' ) ) {
			$out['translate'] = [
				'label' => __( 'Product translation', 'dazont-ecom' ),
				'text'  => [ 'DZE_Translate', 'prompt' ],
				'tab'   => 'translate',
				'frag'  => 'dze-tr-prompt',
			];
		}
		if ( class_exists( 'DZE_Pod' ) && self::module_on( 'pod' ) ) {
			// POD lives inside Product content now: same tab, same section as
			// the other image recipes.
			$out['pod'] = [
				'label' => __( 'POD mockup', 'dazont-ecom' ),
				'text'  => [ 'DZE_Pod', 'prompt' ],
				'tab'   => 'content',
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
				} ).fail( function () {
					$( '#dze-prompt-text' ).val( '<?php echo esc_js( __( 'This prompt could not be read.', 'dazont-ecom' ) ); ?>' );
				} );
			} );
			$( document ).on( 'click', '#dze-prompt-reset', function () { $( '#dze-prompt-text' ).val( def ); } );
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
					$st.addClass( 'is-ko' ).text( ( r && r.data && r.data.message ) || '' );
				} ).fail( function () {
					$b.prop( 'disabled', false );
					$st.addClass( 'is-ko' ).text( '<?php echo esc_js( __( 'The prompt was not saved.', 'dazont-ecom' ) ); ?>' );
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
		wp_send_json_success( [
			'label'    => (string) $row['label'],
			'text'     => $text,
			'def'      => $w ? (string) $w['default'] : '',
			'editable' => (bool) $w,
			'url'      => self::url( $id ),
			'note'     => __( 'Saved here, this is what every run uses from now on. The product or category data, the shop context, the site language and the answer format are added around it when the call is made.', 'dazont-ecom' ),
		] );
	}
}
