<?php
/**
 * Is this the shop, or a copy of it?
 *
 * A staging site is made by copying the shop — files, database and all. The
 * copy therefore carries the SAME Klaviyo key, the same Google service
 * account, the same fal key and the same scheduled hooks as the real shop, and
 * nothing anywhere says which of the two is which. A cron tick on the copy is
 * enough: campaigns written into the real Klaviyo account, feeds pushed to the
 * real Merchant Center, an email scheduled to real contacts. Nobody presses
 * anything, and the damage is done on the live shop.
 *
 * So the plugin remembers the address it was set up on, and compares. When
 * they differ it is a copy, and on a copy:
 *
 *   - READING stays open. Every screen still shows what Klaviyo and Google
 *     hold, which is most of what a test site is for.
 *   - WRITING outward is refused, in one place per service, with a sentence
 *     saying why rather than a failure that looks like a bug.
 *   - The autopilot does not run at all. Scheduled and unattended is exactly
 *     the case nobody would catch.
 *
 * Local work — writing text, generating images, filling the queue — is left
 * alone: that is what a test site is for, and none of it leaves the site.
 *
 * Two ways out, both one click: "This is now the shop" adopts the address, and
 * "This site is a copy" declares one when the address did not change (a clone
 * restored over itself, a shop moved back). Never a silent state.
 *
 * Footprint: two options, neither autoloaded, read only in the admin and on
 * the outward calls themselves. No front hook.
 *
 * @package Dazont_Ecom
 */

defined( 'ABSPATH' ) || exit;

final class DZE_Site {

	private const NONCE = 'dze_site';

	/** Admin only: nothing here is any business of a shop page. */
	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'learn' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
		add_action( 'admin_post_dze_site_state', [ __CLASS__, 'handle' ] );
	}

	/**
	 * What this install IS, in one sentence — the only one there is.
	 *
	 * The banner and the Health line both say it, so they cannot come to say
	 * two different things about the same state.
	 */
	public static function says(): string {
		$why = self::reason();
		if ( '' === $why ) {
			return __( 'the shop itself', 'dazont-ecom' );
		}
		if ( 'declared' === $why ) {
			return __( 'a copy of the shop, because you said so', 'dazont-ecom' );
		}
		return sprintf(
			/* translators: 1: the address the shop was set up on, 2: this address */
			__( 'a copy of %1$s, running on %2$s', 'dazont-ecom' ),
			self::known(),
			self::host()
		);
	}

	/** What a copy cannot do, said once. */
	public static function costs(): string {
		return __( 'Nothing is sent to Klaviyo or Google from here, and nothing runs on its own.', 'dazont-ecom' );
	}

	/** The banner a copy carries, on every screen, until it is dealt with. */
	public static function notice(): void {
		if ( ! self::is_copy() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// One sentence. This shows on every screen of a staging site, and a
		// paragraph nobody can dismiss stops being read on the second day.
		echo '<div class="notice notice-warning"><p><strong>';
		printf(
			/* translators: %s: what this install is */
			esc_html__( 'This install is %s.', 'dazont-ecom' ),
			esc_html( self::says() )
		);
		echo '</strong> ' . esc_html( self::costs() );
		echo ' <a class="button button-small" href="' . esc_url( self::action_url( 'adopt' ) ) . '">'
			. esc_html__( 'This is now the shop', 'dazont-ecom' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * The line on Settings → Health, on the shop as on a copy.
	 *
	 * A guard nobody can see is a guard nobody trusts — and the shop needs a
	 * way to SAY that an install is a copy when its address alone cannot tell:
	 * a clone restored on the shop's own domain, or a staging site that was
	 * already standing when this was released.
	 */
	public static function render_line(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$copy = self::is_copy();
		echo '<p style="margin:14px 0 0;"><strong>' . esc_html__( 'This install', 'dazont-ecom' ) . '</strong> — ';
		echo '<span style="color:' . ( $copy ? '#b32d2e' : '#0a7040' ) . ';">' . esc_html( self::says() ) . '</span>. ';
		// The consequence beside the control that causes it, and nothing else:
		// what a staging site IS does not need explaining to the person who
		// made one.
		echo $copy ? esc_html( self::costs() ) . ' ' : '';
		echo '<a class="button button-small" href="' . esc_url( self::action_url( $copy ? 'adopt' : 'copy' ) ) . '">'
			. esc_html( $copy ? __( 'This is now the shop', 'dazont-ecom' ) : __( 'This site is a copy', 'dazont-ecom' ) )
			. '</a></p>';
	}

	private static function action_url( string $what ): string {
		return wp_nonce_url(
			add_query_arg( [ 'action' => 'dze_site_state', 'do' => $what ], admin_url( 'admin-post.php' ) ),
			self::NONCE
		);
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		check_admin_referer( self::NONCE );
		if ( 'copy' === ( isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '' ) ) {
			self::declare_copy();
		} else {
			self::adopt();
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	/** The address this install was set up on. */
	public const OPT_HOME = 'dze_home';

	/** Declared a copy by hand, when the address alone cannot say so. */
	public const OPT_COPY = 'dze_copy';

	/** The methods that CHANGE something at the other end. */
	private const WRITES = [ 'POST', 'PUT', 'PATCH', 'DELETE' ];

	/** This site's address, as one comparable word. */
	public static function host(): string {
		$host = (string) wp_parse_url( (string) home_url(), PHP_URL_HOST );
		$host = strtolower( trim( $host ) );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/** The address it was set up on — '' when it has never been recorded. */
	public static function known(): string {
		return strtolower( trim( (string) get_option( self::OPT_HOME, '' ) ) );
	}

	/**
	 * Records the current address when none is recorded yet.
	 *
	 * Called on activation and once per admin load. A shop updating to this
	 * version adopts ITSELF, silently: nothing changes for it, and the copy
	 * made afterwards is the one that differs.
	 */
	public static function learn(): void {
		if ( '' === self::known() ) {
			update_option( self::OPT_HOME, self::host(), false );
		}
	}

	/** Somebody said, in as many words, that this install is a copy. */
	public static function declared(): bool {
		return (bool) get_option( self::OPT_COPY, 0 );
	}

	/**
	 * WHY this is a copy — '' when it is not one.
	 *
	 * The screen needs the reason, not the reckoning: a copy declared by hand
	 * has nothing to do with its address, and printing the address twice as
	 * though it were the evidence is how that line came to read like a bug.
	 *
	 * @return string 'declared', 'address', or '' for the shop itself.
	 */
	public static function reason(): string {
		if ( self::declared() ) {
			return 'declared';
		}
		$known = self::known();
		return ( '' !== $known && $known !== self::host() ) ? 'address' : '';
	}

	public static function is_copy(): bool {
		return '' !== self::reason();
	}

	/** "This is now the shop": the address is adopted and the flag cleared. */
	public static function adopt(): void {
		update_option( self::OPT_HOME, self::host(), false );
		update_option( self::OPT_COPY, 0, false );
	}

	/** "This site is a copy", said by hand. */
	public static function declare_copy(): void {
		update_option( self::OPT_COPY, 1, false );
	}

	/**
	 * Must this outward call be refused?
	 *
	 * Only the ones that change something at the other end. A GET on a copy is
	 * how its screens stay useful.
	 */
	public static function blocks( string $method ): bool {
		return self::is_copy() && in_array( strtoupper( trim( $method ) ), self::WRITES, true );
	}

	/**
	 * May the autopilot work here?
	 *
	 * The balance a test site needs. Stopping it outright would stop the shop
	 * testing the very thing the site was made for; leaving it alone means a
	 * cron tick at three in the morning spending the real budget on a site
	 * nobody is watching, and — before the guard below existed — writing into
	 * the real Klaviyo account. So a SCHEDULED pass does not run on a copy,
	 * and a pass somebody presses does.
	 */
	public static function autopilot_ok( bool $by_hand = false ): bool {
		return $by_hand || ! self::is_copy();
	}

	/**
	 * Why, in one sentence, naming both addresses.
	 *
	 * Never "permission denied": the whole point is that the shop reads this
	 * on a test site and understands immediately that it is being protected,
	 * not that something is broken.
	 */
	public static function why( string $service ): string {
		return sprintf(
			/* translators: 1: the service, 2: the address it was set up on, 3: this address */
			__( 'This site is a copy, so nothing was sent to %1$s. The shop was set up on %2$s and this is %3$s — a copy shares its keys with the real shop, and a write from here would land on the real account. Dazont Ecom → Settings → Health says how to change that.', 'dazont-ecom' ),
			$service,
			self::known() ?: __( 'another address', 'dazont-ecom' ),
			self::host()
		);
	}
}
