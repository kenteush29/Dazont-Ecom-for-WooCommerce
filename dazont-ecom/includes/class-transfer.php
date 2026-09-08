<?php
/**
 * Carrying a shop's own writing from one site to another.
 *
 * Everything this plugin produces is decided by two things the owner spent
 * months on: the PROMPTS, and the CRITERIA the shop is read against. A second
 * site — a staging copy, another shop, the same shop rebuilt — starts with
 * the shipped defaults and no way to get them across but retyping, screen by
 * screen, with no way of telling afterwards which ones were missed.
 *
 * So: one page, two boxes. Copy what this shop holds; paste it on the other
 * one. What is pasted is READ before anything is written — it says where it
 * came from, when, and what it holds — and each group is replaced only if it
 * is ticked. Nothing is merged: a half-copied prompt set is worse than either
 * of the two it came from, and nobody could say which half they were looking
 * at.
 *
 * WHAT NEVER TRAVELS: API keys. They are the one thing a shop must not paste
 * into a box, and the one thing somebody would paste into a chat window
 * without thinking. The bundle does not carry them and the screen says so.
 */

defined( 'ABSPATH' ) || exit;

final class DZE_Transfer {

	public const NONCE = 'dze_transfer';

	/** What this file is. A bundle without it is not one. */
	private const MARK = 'dazont-ecom-transfer';

	/** The shape of the bundle, so an older one can be recognised as older. */
	private const VERSION = 1;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'wp_ajax_dze_transfer_read', [ __CLASS__, 'ajax_read' ] );
		add_action( 'wp_ajax_dze_transfer_apply', [ __CLASS__, 'ajax_apply' ] );
	}

	// =========================================================================
	// What travels
	// =========================================================================

	/**
	 * The groups this page can carry, and what each one is called.
	 *
	 * Two, and they are the two the owner would name himself: what the shop
	 * says, and what the shop is held to.
	 */
	public static function groups(): array {
		return [
			'prompts'    => __( 'Prompts', 'dazont-ecom' ),
			'diagnostic' => __( 'Content diagnostic criteria', 'dazont-ecom' ),
		];
	}

	/** Every prompt that is written as one line of text, by its id. */
	public static function single_prompts(): array {
		$out = [];
		if ( ! class_exists( 'DZE_Prompts' ) ) {
			return $out;
		}
		foreach ( array_keys( DZE_Prompts::catalog() ) as $id ) {
			$id = (string) $id;
			// The product prompts are rows of a registry, carried whole below;
			// carrying their text twice would be two answers to one question.
			if ( 0 === strpos( $id, 'content_' ) ) {
				continue;
			}
			$text = trim( DZE_Prompts::text_for( $id ) );
			if ( '' !== $text ) {
				$out[ $id ] = $text;
			}
		}
		return $out;
	}

	/**
	 * What this shop holds, ready to be pasted onto another one.
	 *
	 * @return array
	 */
	public static function bundle(): array {
		$out = [
			'dze'     => self::MARK,
			'v'       => self::VERSION,
			'from'    => (string) home_url( '/' ),
			'at'      => (string) current_time( 'mysql' ),
			'plugin'  => defined( 'DZE_VERSION' ) ? DZE_VERSION : '',
			'groups'  => [],
		];
		if ( class_exists( 'DZE_Content' ) ) {
			$out['groups']['prompts'] = [
				// The product prompts, whole: a prompt is its text AND where it
				// writes, what it is sent with, how long it may be. Carrying
				// the text alone would land it on a shop that sends it nothing.
				'registry' => array_values( DZE_Content::registry() ),
				'single'   => self::single_prompts(),
				// Which prompts the shop made its own default, so "Restore
				// default" means the same thing on both sites.
				'defaults' => (array) get_option( 'dze_prompt_defaults', [] ),
			];
		}
		if ( class_exists( 'DZE_Diagnostic' ) ) {
			$out['groups']['diagnostic'] = [ 'rows' => array_values( DZE_Diagnostic::rows() ) ];
		}
		return $out;
	}

	/** The bundle as text, ready to be copied. */
	public static function bundle_text(): string {
		return (string) wp_json_encode( self::bundle(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	// =========================================================================
	// Reading what was pasted
	// =========================================================================

	/**
	 * What a pasted bundle IS, before anything is written.
	 *
	 * A paste box that writes on the press is a paste box nobody dares use.
	 * This says where the text came from, when it was taken and what it holds,
	 * in the words each group is named by — and refuses anything it cannot
	 * recognise rather than half-reading it.
	 *
	 * @return array{from:string,at:string,plugin:string,holds:array<string,array{label:string,said:string,n:int}>}
	 */
	public static function read( string $json ): array {
		$in = json_decode( trim( $json ), true );
		if ( ! is_array( $in ) ) {
			throw new RuntimeException( __( 'That is not a settings file — paste the whole box, from the first { to the last }.', 'dazont-ecom' ) );
		}
		if ( self::MARK !== (string) ( $in['dze'] ?? '' ) ) {
			throw new RuntimeException( __( 'That file was not made by this plugin.', 'dazont-ecom' ) );
		}
		if ( (int) ( $in['v'] ?? 0 ) > self::VERSION ) {
			throw new RuntimeException( __( 'That file comes from a newer version of the plugin. Update this site first.', 'dazont-ecom' ) );
		}
		$holds = [];
		foreach ( self::groups() as $id => $label ) {
			$group = (array) ( $in['groups'][ $id ] ?? [] );
			if ( ! $group ) {
				continue;
			}
			$holds[ $id ] = [
				'label' => $label,
				'said'  => self::said( $id, $group ),
				'n'     => self::count_of( $id, $group ),
			];
		}
		if ( ! $holds ) {
			throw new RuntimeException( __( 'That file holds nothing this site can use.', 'dazont-ecom' ) );
		}
		return [
			'from'   => (string) ( $in['from'] ?? '' ),
			'at'     => (string) ( $in['at'] ?? '' ),
			'plugin' => (string) ( $in['plugin'] ?? '' ),
			'holds'  => $holds,
		];
	}

	/** How much of a thing a group holds — one figure, for the screen. */
	private static function count_of( string $id, array $group ): int {
		if ( 'diagnostic' === $id ) {
			return count( (array) ( $group['rows'] ?? [] ) );
		}
		return count( (array) ( $group['registry'] ?? [] ) ) + count( (array) ( $group['single'] ?? [] ) );
	}

	/** What a group holds, in words. */
	public static function said( string $id, array $group ): string {
		if ( 'diagnostic' === $id ) {
			$n = count( (array) ( $group['rows'] ?? [] ) );
			return sprintf(
				/* translators: %d: how many criteria */
				_n( '%d criterion', '%d criteria', $n, 'dazont-ecom' ),
				$n
			);
		}
		$reg = count( (array) ( $group['registry'] ?? [] ) );
		$one = count( (array) ( $group['single'] ?? [] ) );
		return sprintf(
			/* translators: 1: product prompts, 2: other prompts */
			__( '%1$d product prompts, %2$d others', 'dazont-ecom' ),
			$reg,
			$one
		);
	}

	// =========================================================================
	// Writing it
	// =========================================================================

	/**
	 * Replaces the ticked groups with what the bundle holds.
	 *
	 * Every write goes through the function the module that OWNS that data
	 * uses, filter removed and read back — never `update_option()` on a
	 * registered option, which re-runs a sanitizer shaped for form input and
	 * would quietly write something else.
	 *
	 * @param string[] $want The group ids to replace.
	 * @return array<string,string> group id => what happened, in words.
	 */
	public static function apply( string $json, array $want ): array {
		$in   = json_decode( trim( $json ), true );
		$read = self::read( $json ); // refuses anything unreadable before a single write.
		$done = [];
		foreach ( $want as $id ) {
			$id    = (string) $id;
			$group = (array) ( $in['groups'][ $id ] ?? [] );
			if ( ! isset( $read['holds'][ $id ] ) || ! $group ) {
				continue;
			}
			$done[ $id ] = 'diagnostic' === $id
				? self::write_diagnostic( $group )
				: self::write_prompts( $group );
		}
		if ( ! $done ) {
			throw new RuntimeException( __( 'Nothing was ticked.', 'dazont-ecom' ) );
		}
		return $done;
	}

	/** The prompts, replaced. */
	private static function write_prompts( array $group ): string {
		if ( ! class_exists( 'DZE_Content' ) ) {
			throw new RuntimeException( __( 'The Product content module is switched off — switch it on to take its prompts.', 'dazont-ecom' ) );
		}
		$rows = array_values( array_filter( (array) ( $group['registry'] ?? [] ), 'is_array' ) );
		if ( $rows ) {
			DZE_Content::write_setting( 'registry', $rows );
		}
		$one = 0;
		foreach ( (array) ( $group['single'] ?? [] ) as $id => $text ) {
			$text = trim( (string) $text );
			if ( '' === $text ) {
				continue;
			}
			// The module's own writer, one per prompt: the same call the prompt
			// popup makes when somebody saves one by hand.
			if ( class_exists( 'DZE_Prompts' ) && DZE_Prompts::save_text( (string) $id, $text ) ) {
				$one++;
			}
		}
		if ( isset( $group['defaults'] ) && is_array( $group['defaults'] ) ) {
			update_option( 'dze_prompt_defaults', $group['defaults'], false );
		}
		return sprintf(
			/* translators: 1: product prompts written, 2: other prompts written */
			__( '%1$d product prompts and %2$d others replaced.', 'dazont-ecom' ),
			count( $rows ),
			$one
		);
	}

	/** The criteria, replaced. */
	private static function write_diagnostic( array $group ): string {
		if ( ! class_exists( 'DZE_Diagnostic' ) ) {
			throw new RuntimeException( __( 'The Content diagnostic module is switched off — switch it on to take its criteria.', 'dazont-ecom' ) );
		}
		$n = DZE_Diagnostic::write_rows( array_values( array_filter( (array) ( $group['rows'] ?? [] ), 'is_array' ) ) );
		return sprintf(
			/* translators: %d: how many criteria */
			_n( '%d criterion replaced.', '%d criteria replaced.', $n, 'dazont-ecom' ),
			$n
		);
	}

	// =========================================================================
	// The screen
	// =========================================================================

	public static function ajax_read(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$json = isset( $_POST['bundle'] ) ? (string) wp_unslash( $_POST['bundle'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON, read and refused by read().
		try {
			wp_send_json_success( self::read( $json ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public static function ajax_apply(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$json = isset( $_POST['bundle'] ) ? (string) wp_unslash( $_POST['bundle'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON, read and refused by read().
		$want = isset( $_POST['groups'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['groups'] ) ) : [];
		try {
			wp_send_json_success( [ 'done' => self::apply( $json, $want ) ] );
		} catch ( Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/** The Transfer tab. */
	public static function render_tab(): void {
		$bundle = self::bundle();
		?>
		<h2><?php esc_html_e( 'Copy this shop', 'dazont-ecom' ); ?></h2>
		<p class="description" style="max-width:820px;">
			<?php esc_html_e( 'Everything below is what this shop writes with and what it is read against. Copy it, then paste it on the other site under the same tab. No API key is in it — those stay where they are.', 'dazont-ecom' ); ?>
		</p>
		<p>
			<?php
			$said = [];
			foreach ( self::groups() as $id => $label ) {
				if ( isset( $bundle['groups'][ $id ] ) ) {
					$said[] = $label . ' — ' . self::said( $id, (array) $bundle['groups'][ $id ] );
				}
			}
			echo esc_html( implode( ' · ', $said ) );
			?>
		</p>
		<p>
			<button type="button" class="button button-primary" id="dze-tr-copy"><?php esc_html_e( 'Copy to the clipboard', 'dazont-ecom' ); ?></button>
			<span class="description" id="dze-tr-copied" style="margin-left:8px;"></span>
		</p>
		<textarea id="dze-tr-out" rows="6" class="large-text code" readonly onclick="this.select();"><?php echo esc_textarea( self::bundle_text() ); ?></textarea>

		<hr style="margin:28px 0;" />

		<h2><?php esc_html_e( 'Take another shop\'s settings', 'dazont-ecom' ); ?></h2>
		<p class="description" style="max-width:820px;">
			<?php esc_html_e( 'Paste what you copied on the other site, then read it. Nothing is written until you say which groups to replace — and a group that is replaced is replaced whole, not merged.', 'dazont-ecom' ); ?>
		</p>
		<textarea id="dze-tr-in" rows="6" class="large-text code" placeholder="<?php esc_attr_e( 'Paste here', 'dazont-ecom' ); ?>"></textarea>
		<p>
			<button type="button" class="button" id="dze-tr-read"><?php esc_html_e( 'Read it', 'dazont-ecom' ); ?></button>
			<span class="description" id="dze-tr-state" style="margin-left:8px;"></span>
		</p>
		<div id="dze-tr-holds" style="display:none;border:1px solid #dcdcde;border-radius:4px;background:#fff;padding:12px;max-width:820px;">
			<p id="dze-tr-where" style="margin:0 0 8px;"></p>
			<div id="dze-tr-list"></div>
			<p style="margin:12px 0 0;">
				<button type="button" class="button button-primary" id="dze-tr-apply"><?php esc_html_e( 'Replace mine with these', 'dazont-ecom' ); ?></button>
				<span class="description" id="dze-tr-done" style="margin-left:8px;"></span>
			</p>
		</div>
		<script>
		jQuery( function ( $ ) {
			var L = <?php echo wp_json_encode( [
				'copied'  => __( 'Copied.', 'dazont-ecom' ),
				'nocopy'  => __( 'Select the text and copy it yourself.', 'dazont-ecom' ),
				'reading' => __( 'Reading…', 'dazont-ecom' ),
				'writing' => __( 'Writing…', 'dazont-ecom' ),
				'empty'   => __( 'Paste the settings first.', 'dazont-ecom' ),
				'nopick'  => __( 'Tick what you want replaced.', 'dazont-ecom' ),
				'failed'  => __( 'That did not go through.', 'dazont-ecom' ),
				'sure'    => __( 'This replaces what this shop holds. It cannot be undone from here.', 'dazont-ecom' ),
				/* translators: 1: the site it came from, 2: when, 3: plugin version */
				'where'   => __( 'From %1$s, taken %2$s, plugin %3$s.', 'dazont-ecom' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'ajax'    => admin_url( 'admin-ajax.php' ),
			] ); ?>;
			$( '#dze-tr-copy' ).on( 'click', function () {
				var el = document.getElementById( 'dze-tr-out' );
				el.select();
				var ok = false;
				try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
				if ( ! ok && navigator.clipboard ) {
					navigator.clipboard.writeText( el.value );
					ok = true;
				}
				$( '#dze-tr-copied' ).text( ok ? L.copied : L.nocopy );
			} );
			$( '#dze-tr-read' ).on( 'click', function () {
				var text = String( $( '#dze-tr-in' ).val() || '' ).replace( /^\s+|\s+$/g, '' );
				if ( ! text ) { $( '#dze-tr-state' ).text( L.empty ); return; }
				$( '#dze-tr-state' ).text( L.reading );
				$( '#dze-tr-holds' ).hide();
				$.post( L.ajax, { action: 'dze_transfer_read', nonce: L.nonce, bundle: text } )
					.done( function ( r ) {
						if ( ! r || ! r.success ) {
							$( '#dze-tr-state' ).text( ( r && r.data && r.data.message ) || L.failed );
							return;
						}
						$( '#dze-tr-state' ).text( '' );
						$( '#dze-tr-where' ).text(
							L.where.replace( '%1$s', r.data.from ).replace( '%2$s', r.data.at ).replace( '%3$s', r.data.plugin )
						);
						var $list = $( '#dze-tr-list' ).empty();
						$.each( r.data.holds, function ( id, one ) {
							$list.append(
								$( '<label style="display:block;margin:4px 0;"></label>' )
									.append( $( '<input type="checkbox" class="dze-tr-g" checked />' ).val( id ) )
									.append( $( '<strong></strong>' ).text( ' ' + one.label + ' ' ) )
									.append( $( '<span class="description"></span>' ).text( '— ' + one.said ) )
							);
						} );
						$( '#dze-tr-done' ).text( '' );
						$( '#dze-tr-holds' ).show();
					} )
					.fail( function () { $( '#dze-tr-state' ).text( L.failed ); } );
			} );
			$( '#dze-tr-apply' ).on( 'click', function () {
				var groups = $( '.dze-tr-g:checked' ).map( function () { return this.value; } ).get();
				if ( ! groups.length ) { $( '#dze-tr-done' ).text( L.nopick ); return; }
				if ( ! window.confirm( L.sure ) ) { return; }
				var $b = $( this ).prop( 'disabled', true );
				$( '#dze-tr-done' ).text( L.writing );
				$.post( L.ajax, {
					action: 'dze_transfer_apply', nonce: L.nonce,
					bundle: $( '#dze-tr-in' ).val() || '', groups: groups
				} )
					.done( function ( r ) {
						$b.prop( 'disabled', false );
						if ( ! r || ! r.success ) {
							$( '#dze-tr-done' ).text( ( r && r.data && r.data.message ) || L.failed );
							return;
						}
						var said = [];
						$.each( r.data.done, function ( id, line ) { said.push( line ); } );
						$( '#dze-tr-done' ).text( said.join( ' ' ) );
					} )
					.fail( function () { $b.prop( 'disabled', false ); $( '#dze-tr-done' ).text( L.failed ); } );
			} );
		} );
		</script>
		<?php
	}
}
