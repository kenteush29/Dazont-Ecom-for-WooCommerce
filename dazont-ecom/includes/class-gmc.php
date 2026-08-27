<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Merchant Center sync for promotions (Merchant API).
 *
 * One Merchant Center account per language: each scheduled-sale promotion is
 * pushed as a GMC "promotion" to the account mapped to the language it is
 * active in, through the Merchant API promotions sub-API — the successor to
 * the Content API for Shopping, which Google shuts down on 18 August 2026.
 *
 * A promotion must be inserted into a promotion *data source*; the plugin
 * finds or creates one per target country/language automatically.
 *
 * Authentication uses either the connected Google account (OAuth) or a Google
 * service account (JWT → OAuth2 access token) — no bundled Google client
 * library. Both use the same 'content' scope the Merchant API requires, so the
 * existing connection keeps working without re-consent.
 *
 * Credentials are read from the DZE_GMC_SERVICE_ACCOUNT constant (a file path
 * or the raw JSON) when defined, otherwise from a settings field. They are
 * never committed to the repository.
 */
final class DZE_Gmc {

	public const MENU_SLUG   = 'dazont-ecom-gmc';
	public const NONCE       = 'dze_gmc';
	public const CRON_HOOK   = 'dze_gmc_sync';
	public const OPT_ACCOUNTS    = 'dze_gmc_accounts';
	public const OPT_CREDENTIALS = 'dze_gmc_credentials';
	public const OPT_OAUTH       = 'dze_gmc_oauth';        // OAuth client (id/secret) — form-managed.
	public const OPT_CONNECTION  = 'dze_gmc_connection';   // Connected account (refresh token/email) — flow-managed only.
	public const OPT_DATASOURCES = 'dze_gmc_datasources';  // Resolved promotion data source names, keyed by account|country|lang.
	public const OPT_ADVANCED    = 'dze_gmc_advanced';     // Advanced/parent (MCA) account ID — used for GCP developer registration.
	public const OPT_ADS_ONLY    = 'dze_gmc_ads_only';     // Push promotions only to accounts linked to Google Ads.
	public const OPT_AUTO        = 'dze_gmc_auto';         // Keep Google up to date without being asked.
	public const SYNC_ONE        = 'dze_gmc_sync_one';     // Background job: one promotion, sync or cancel.

	// Merchant API (replaces Content API for Shopping v2.1). v1beta was
	// discontinued on 28 Feb 2026, so all sub-APIs are pinned to v1.
	private const MERCHANT_API    = 'https://merchantapi.googleapis.com';
	private const PROMO_SUBAPI    = 'promotions/v1';
	private const DS_SUBAPI       = 'datasources/v1';
	private const ACCOUNTS_SUBAPI = 'accounts/v1';
	private const SCOPE      = 'https://www.googleapis.com/auth/content';
	private const OAUTH_SCOPE = 'https://www.googleapis.com/auth/content https://www.googleapis.com/auth/userinfo.email';
	private const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
	private const TOKEN_TTL  = 3300; // seconds

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, [ $this, 'cron_sync_all' ] );
		add_action( 'init',          [ $this, 'maybe_schedule_cron' ] );
		// The background job, registered on every request: it is run by cron
		// and by Action Scheduler, neither of which is an admin screen.
		add_action( self::SYNC_ONE,  [ $this, 'run_queued' ], 10, 2 );

		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_dze_gmc_sync',      [ $this, 'ajax_sync' ] );
		add_action( 'wp_ajax_dze_gmc_test',      [ $this, 'ajax_test' ] );
		add_action( 'wp_ajax_dze_gmc_verify',    [ $this, 'ajax_verify' ] );
		add_action( 'wp_ajax_dze_gmc_register',  [ $this, 'ajax_register' ] );
		add_action( 'wp_ajax_dze_gmc_promotions', [ $this, 'ajax_promotions' ] );
		add_action( 'wp_ajax_dze_gmc_end_promo',  [ $this, 'ajax_end_promotion' ] );
		add_action( 'admin_post_dze_gmc_oauth',       [ $this, 'handle_oauth_callback' ] );
		add_action( 'admin_post_dze_gmc_disconnect',  [ $this, 'handle_disconnect' ] );
	}

	public function maybe_schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function clear_cron(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		// The queued one-promotion jobs carry arguments, which
		// wp_clear_scheduled_hook() cannot match: they need the hook cleared
		// whatever its arguments, or a switched-off module goes on pushing.
		wp_unschedule_hook( self::SYNC_ONE );
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function get_accounts(): array {
		$a = get_option( self::OPT_ACCOUNTS, [] );
		return is_array( $a ) ? $a : [];
	}

	/**
	 * What to call one Merchant Center account on screen.
	 *
	 * The name Google gave it when the account was verified, and only then the
	 * bare id. An account is a shop with a name; a fifteen-digit number is not
	 * something anybody matches to one of five markets under pressure.
	 */
	public function account_label( string $merchant_id ): string {
		foreach ( self::get_accounts() as $key => $acc ) {
			if ( (string) ( $acc['merchant_id'] ?? '' ) === $merchant_id ) {
				$name = trim( (string) ( $acc['name'] ?? '' ) );
				return '' !== $name ? $name : strtoupper( (string) $key ) . ' · ' . $merchant_id;
			}
		}
		return $merchant_id;
	}

	/**
	 * Writes Google's own name for an account beside its id.
	 *
	 * Through the option's own sanitizer, on a full array, so it stays the one
	 * shape the rest of the plugin reads — and only ever on an explicit click.
	 */
	private function remember_account_name( string $merchant_id, string $name ): void {
		$name = trim( $name );
		if ( '' === $name || $name === $merchant_id ) {
			return;
		}
		$accounts = self::get_accounts();
		$touched  = false;
		foreach ( $accounts as $key => $acc ) {
			if ( (string) ( $acc['merchant_id'] ?? '' ) === $merchant_id && ( $acc['name'] ?? '' ) !== $name ) {
				$accounts[ $key ]['name'] = $name;
				$touched = true;
			}
		}
		if ( $touched ) {
			update_option( self::OPT_ACCOUNTS, $accounts, false );
		}
	}

	/** Every configured account, by id, as it should be named on screen. */
	public function account_names(): array {
		$out = [];
		foreach ( self::get_accounts() as $acc ) {
			$id = (string) ( $acc['merchant_id'] ?? '' );
			if ( '' !== $id ) {
				$out[ $id ] = $this->account_label( $id );
			}
		}
		return $out;
	}

	/**
	 * The countries a Merchant Center account can actually run promotions in.
	 *
	 * Read from the account itself — the promotion data sources it holds, each
	 * of which targets one country — instead of being typed by hand into a
	 * field nobody can check. A shop that adds a country in Merchant Center
	 * sees it here; a code typed here that Merchant Center never accepted is
	 * gone with the field it was typed in.
	 *
	 * When the account has no promotion data source yet, its own business
	 * address answers for it — the sync creates the data source on the way.
	 * And when the account says nothing at all, the guess belongs to the
	 * LANGUAGE the account serves, not to the shop's own base country: five
	 * Merchant Center accounts, one per language, are not five American
	 * accounts because WooCommerce is configured in dollars. A French account
	 * with nothing set up targets France, not the United States.
	 *
	 * @param string $language Two-letter code of the account's language, when known.
	 *
	 * @return string[] Uppercase ISO codes.
	 */
	public function promo_countries( string $merchant_id, string $language = '' ): array {
		$known = $this->account_countries( $merchant_id );
		return $known ? $known : self::country_for_language( $language );
	}

	/**
	 * What the ACCOUNT itself says its promotions target — nothing guessed.
	 *
	 * @return string[] Uppercase ISO codes, empty when the account is silent.
	 */
	private function account_countries( string $merchant_id ): array {
		$merchant_id = preg_replace( '/[^0-9]/', '', $merchant_id );
		if ( '' === $merchant_id ) {
			return [];
		}
		$key    = 'dze_gmc_pc_' . $merchant_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out = [];
		try {
			$token = $this->get_access_token();
			$url   = self::MERCHANT_API . '/' . self::DS_SUBAPI . '/accounts/' . $merchant_id . '/dataSources?pageSize=200';
			$list  = $this->request( 'GET', $url, $token );
			foreach ( (array) ( $list['dataSources'] ?? [] ) as $ds ) {
				$c = strtoupper( (string) ( $ds['promotionDataSource']['targetCountry'] ?? '' ) );
				if ( 2 === strlen( $c ) ) {
					$out[ $c ] = $c;
				}
			}
			if ( ! $out ) {
				// No promotion data source yet: the account's own business
				// address answers for it. Its own failure is not the list's.
				try {
					$info = $this->request( 'GET', self::MERCHANT_API . '/' . self::ACCOUNTS_SUBAPI . '/accounts/' . $merchant_id . '/businessInfo', $token );
					$c    = strtoupper( (string) ( $info['address']['regionCode'] ?? '' ) );
					if ( 2 === strlen( $c ) ) {
						$out[ $c ] = $c;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		} catch ( \Throwable $e ) {
			// Unreadable: say nothing rather than something wrong, and ask
			// again shortly instead of holding a guess for six hours.
			set_transient( $key, [], 15 * MINUTE_IN_SECONDS );
			return [];
		}
		$out = array_values( $out );
		set_transient( $key, $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * The country a language sells to, when the account will not say.
	 *
	 * The pools are the ones the marketing calendar already uses, so the two
	 * screens cannot disagree about which country a language belongs to.
	 */
	public static function country_for_language( string $language ): array {
		$code = strtolower( substr( (string) $language, 0, 2 ) );
		if ( '' !== $code && class_exists( 'DZE_Marketing_Ai' ) ) {
			$pool = DZE_Marketing_Ai::LANGUAGE_COUNTRY_POOLS[ $code ] ?? [];
			if ( $pool ) {
				return [ strtoupper( (string) $pool[0] ) ];
			}
		}
		return self::shop_country();
	}

	/** The shop's own country, as WooCommerce holds it. */
	public static function shop_country(): array {
		$base = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : [];
		$c    = strtoupper( (string) ( $base['country'] ?? '' ) );
		return 2 === strlen( $c ) ? [ $c ] : [];
	}

	/** WPML active → language codes; otherwise a single 'default' account. */
	public static function account_keys(): array {
		if ( DZE_Wpml::is_active() ) {
			return array_map( static fn( $l ) => $l['code'], DZE_Wpml::get_active_languages() );
		}
		return [ 'default' ];
	}

	public function is_authenticated(): bool {
		$c = self::get_connection();
		return ! empty( $c['refresh_token'] ) || null !== $this->get_credentials();
	}

	public function is_configured(): bool {
		if ( ! $this->is_authenticated() ) {
			return false;
		}
		foreach ( self::get_accounts() as $acc ) {
			if ( ! empty( $acc['merchant_id'] ) ) {
				return true;
			}
		}
		return false;
	}

	private function get_credentials(): ?array {
		$raw = '';
		if ( defined( 'DZE_GMC_SERVICE_ACCOUNT' ) ) {
			$raw = DZE_GMC_SERVICE_ACCOUNT;
			if ( is_string( $raw ) && strlen( $raw ) < 512 && @is_readable( $raw ) ) {
				$raw = (string) file_get_contents( $raw );
			}
		}
		if ( ! $raw ) {
			$raw = (string) get_option( self::OPT_CREDENTIALS, '' );
		}
		$sa = json_decode( (string) $raw, true );
		if ( is_array( $sa ) && ! empty( $sa['client_email'] ) && ! empty( $sa['private_key'] ) && ! empty( $sa['token_uri'] ) ) {
			return $sa;
		}
		return null;
	}

	// No own submenu: rendered as the "Google Merchant Center" tab inside the
	// Marketing Events page (see DZE_Discounts::render_events_page()).

	public function register_settings(): void {
		register_setting( 'dze_gmc_options', self::OPT_CREDENTIALS, [ 'sanitize_callback' => [ $this, 'sanitize_credentials' ], 'autoload' => false ] );
		register_setting( 'dze_gmc_options', self::OPT_ACCOUNTS, [ 'sanitize_callback' => [ $this, 'sanitize_accounts' ], 'autoload' => false ] );
		register_setting( 'dze_gmc_options', self::OPT_OAUTH, [ 'sanitize_callback' => [ $this, 'sanitize_oauth' ], 'autoload' => false ] );
		register_setting( 'dze_gmc_options', self::OPT_ADVANCED, [ 'sanitize_callback' => [ $this, 'sanitize_advanced' ], 'autoload' => false ] );
		register_setting( 'dze_gmc_options', self::OPT_ADS_ONLY, [ 'sanitize_callback' => static fn( $v ) => empty( $v ) ? '' : '1', 'autoload' => false ] );
		register_setting( 'dze_gmc_options', self::OPT_AUTO, [ 'sanitize_callback' => static fn( $v ) => empty( $v ) ? '' : '1', 'autoload' => false ] );
	}

	/** Advanced (parent/MCA) account ID used for GCP developer registration. */
	public function sanitize_advanced( $value ): string {
		if ( null === $value ) {
			return (string) get_option( self::OPT_ADVANCED, '' ); // not on the submitted form.
		}
		return preg_replace( '/[^0-9]/', '', (string) $value );
	}

	/**
	 * Persist ONLY the OAuth client id/secret from the settings form.
	 *
	 * The connected account's refresh token/email live in a separate option
	 * (OPT_CONNECTION) that the settings form never touches. That is the whole
	 * point of the split: previously the token shared this option, so every
	 * "Save Changes" (e.g. after entering merchant IDs) re-wrote this row and
	 * could wipe the refresh token obtained through the Connect flow — the
	 * cause of the "oauth_refresh_token=missing, oauth_client=present" error.
	 */
	public function sanitize_oauth( $value ): array {
		$existing = self::get_oauth();
		$in       = is_array( $value ) ? $value : [];
		return [
			'client_id'     => sanitize_text_field( $in['client_id'] ?? ( $existing['client_id'] ?? '' ) ),
			'client_secret' => sanitize_text_field( $in['client_secret'] ?? ( $existing['client_secret'] ?? '' ) ),
		];
	}

	/** OAuth client credentials (id/secret) — managed by the settings form. */
	public static function get_oauth(): array {
		$o = get_option( self::OPT_OAUTH, [] );
		return is_array( $o ) ? $o : [];
	}

	/**
	 * Connected-account state (refresh_token/email), written only by the OAuth
	 * callback and the disconnect handler — never by the settings form.
	 *
	 * Forces a fresh DB read: this option is written by a redirect
	 * (admin-post.php) and read moments later by an AJAX request, which can
	 * land on a different PHP worker with a stale persistent object cache
	 * (Redis/Memcached). Non-autoloaded options are cached under their own key
	 * and, when absent, can also be shadowed by the 'alloptions' blob and the
	 * 'notoptions' set — clear all three so the read cannot serve a pre-connect
	 * (empty) snapshot.
	 *
	 * Also migrates a legacy token that older versions stored inside the
	 * OAuth-client option, so an existing working connection is not lost.
	 */
	public static function get_connection(): array {
		wp_cache_delete( self::OPT_CONNECTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$c = get_option( self::OPT_CONNECTION, null );

		if ( null === $c ) {
			// Migrate from the legacy shared option (<= v1.4.3) if present.
			$legacy = get_option( self::OPT_OAUTH, [] );
			$c      = [
				'refresh_token' => is_array( $legacy ) ? (string) ( $legacy['refresh_token'] ?? '' ) : '',
				'email'         => is_array( $legacy ) ? (string) ( $legacy['email'] ?? '' ) : '',
			];
			if ( $c['refresh_token'] !== '' ) {
				update_option( self::OPT_CONNECTION, $c, false );
			}
		}
		return is_array( $c ) ? $c : [];
	}

	public function oauth_redirect_uri(): string {
		return admin_url( 'admin-post.php?action=dze_gmc_oauth' );
	}

	public function oauth_authorize_url(): string {
		$o = self::get_oauth();
		return self::AUTH_URL . '?' . http_build_query( [
			'client_id'     => $o['client_id'] ?? '',
			'redirect_uri'  => $this->oauth_redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::OAUTH_SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => wp_create_nonce( 'dze_gmc_oauth' ),
		] );
	}

	public function handle_oauth_callback(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$settings_url = admin_url( 'admin.php?page=' . DZE_Discounts::MENU_SLUG_EVENTS . '&tab=gmc' );

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! wp_verify_nonce( $state, 'dze_gmc_oauth' ) ) {
			wp_safe_redirect( add_query_arg( 'gmc_error', rawurlencode( __( 'Security check failed.', 'dazont-ecom' ) ), $settings_url ) );
			exit;
		}
		if ( ! empty( $_GET['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'gmc_error', rawurlencode( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ), $settings_url ) );
			exit;
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$o    = self::get_oauth();
		if ( $code === '' || empty( $o['client_id'] ) || empty( $o['client_secret'] ) ) {
			wp_safe_redirect( add_query_arg( 'gmc_error', rawurlencode( __( 'Missing code or client credentials.', 'dazont-ecom' ) ), $settings_url ) );
			exit;
		}

		$response = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 25,
			'body'    => [
				'code'          => $code,
				'client_id'     => $o['client_id'],
				'client_secret' => $o['client_secret'],
				'redirect_uri'  => $this->oauth_redirect_uri(),
				'grant_type'    => 'authorization_code',
			],
		] );
		if ( is_wp_error( $response ) ) {
			wp_safe_redirect( add_query_arg( 'gmc_error', rawurlencode( $response->get_error_message() ), $settings_url ) );
			exit;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// A hard error from Google (bad code, redirect_uri mismatch, etc.) —
		// surface it verbatim so the exact cause is visible on the page.
		if ( ! empty( $data['error'] ) ) {
			$msg = $data['error_description'] ?? $data['error'];
			wp_safe_redirect( add_query_arg( 'gmc_error', rawurlencode( $msg ), $settings_url ) );
			exit;
		}

		// Google only returns a refresh_token when the app has not been
		// authorised before (or when prompt=consent is honoured). If none comes
		// back, keep any token we already stored rather than failing outright.
		$conn    = self::get_connection();
		$refresh = ! empty( $data['refresh_token'] ) ? (string) $data['refresh_token'] : (string) ( $conn['refresh_token'] ?? '' );
		if ( $refresh === '' ) {
			$msg = __( 'Google did not return a refresh token. Revoke this app at myaccount.google.com/permissions, then click Connect again.', 'dazont-ecom' );
			wp_safe_redirect( add_query_arg( 'gmc_error', rawurlencode( $msg ), $settings_url ) );
			exit;
		}

		$conn['refresh_token'] = $refresh;
		if ( ! empty( $data['access_token'] ) ) {
			$email = $this->fetch_account_email( $data['access_token'] );
			if ( $email !== '' ) {
				$conn['email'] = $email;
			}
		}
		update_option( self::OPT_CONNECTION, $conn, false );

		if ( ! empty( $data['access_token'] ) && ! empty( $data['expires_in'] ) ) {
			set_transient( 'dze_gmc_oauth_token', $data['access_token'], min( (int) $data['expires_in'] - 60, self::TOKEN_TTL ) );
		}

		wp_safe_redirect( add_query_arg( 'gmc_connected', '1', $settings_url ) );
		exit;
	}

	private function fetch_account_email( string $access_token ): string {
		if ( $access_token === '' ) {
			return '';
		}
		$r = wp_remote_get( 'https://www.googleapis.com/oauth2/v2/userinfo', [
			'timeout' => 15,
			'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
		] );
		if ( is_wp_error( $r ) ) {
			return '';
		}
		$d = json_decode( wp_remote_retrieve_body( $r ), true );
		return isset( $d['email'] ) ? sanitize_email( $d['email'] ) : '';
	}

	public function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'dze_gmc_disconnect' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		update_option( self::OPT_CONNECTION, [ 'refresh_token' => '', 'email' => '' ], false );
		delete_transient( 'dze_gmc_oauth_token' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . DZE_Discounts::MENU_SLUG_EVENTS . '&tab=gmc' ) );
		exit;
	}

	private function oauth_access_token(): string {
		$cached = get_transient( 'dze_gmc_oauth_token' );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}
		$o    = self::get_oauth();
		$conn = self::get_connection();
		if ( empty( $conn['refresh_token'] ) || empty( $o['client_id'] ) || empty( $o['client_secret'] ) ) {
			throw new RuntimeException( __( 'Google account is not connected.', 'dazont-ecom' ) );
		}
		$response = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 20,
			'body'    => [
				'client_id'     => $o['client_id'],
				'client_secret' => $o['client_secret'],
				'refresh_token' => $conn['refresh_token'],
				'grant_type'    => 'refresh_token',
			],
		] );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			$msg = $data['error_description'] ?? ( $data['error'] ?? 'Unknown refresh error' );
			throw new RuntimeException( sprintf( __( 'Google token refresh failed: %s', 'dazont-ecom' ), $msg ) );
		}
		set_transient( 'dze_gmc_oauth_token', $data['access_token'], min( (int) ( $data['expires_in'] ?? 3600 ) - 60, self::TOKEN_TTL ) );
		return $data['access_token'];
	}

	public function sanitize_credentials( $value ): string {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return (string) get_option( self::OPT_CREDENTIALS, '' ); // keep existing when left blank
		}
		$json = json_decode( $value, true );
		return is_array( $json ) ? wp_json_encode( $json ) : '';
	}

	public function sanitize_accounts( $value ): array {
		// Not posted at all: another form was saved. The shop's Merchant
		// Center accounts are not something to lose to a page that never
		// carried them.
		if ( ! is_array( $value ) ) {
			return self::get_accounts();
		}
		$clean = [];
		foreach ( $value as $key => $acc ) {
			$key = sanitize_key( $key );
			$was  = (array) ( self::get_accounts()[ $key ] ?? [] );
			$name = array_key_exists( 'name', (array) $acc ) ? (string) $acc['name'] : (string) ( $was['name'] ?? '' );
			$clean[ $key ] = [
				'merchant_id' => preg_replace( '/[^0-9]/', '', (string) ( $acc['merchant_id'] ?? '' ) ),
				'language'    => sanitize_key( $acc['language'] ?? $key ),
				// Learned from Google when the account is verified. The form
				// does not carry it, so it is kept from what is stored rather
				// than blanked by every save.
				'name'        => sanitize_text_field( $name ),
			];
		}
		return $clean;
	}

	public function enqueue_assets( string $hook ): void {
		// Load on the Settings page (GMC tab) and on the Marketing Events list (sync buttons).
		if ( strpos( $hook, DZE_Discounts::MENU_SLUG_EVENTS ) === false ) {
			return;
		}
		wp_enqueue_script( 'dze-gmc', DZE_URL . 'admin/js/gmc.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-gmc', 'dzeGmc', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'liveOn'  => __( 'Live on Merchant Center:', 'dazont-ecom' ),
				'syncing'   => __( 'Syncing…', 'dazont-ecom' ),
				'testing'   => __( 'Testing…', 'dazont-ecom' ),
				'verifying' => __( 'Verifying…', 'dazont-ecom' ),
				'registering' => __( 'Registering…', 'dazont-ecom' ),
				'done'      => __( 'Done', 'dazont-ecom' ),
				'error'     => __( 'Error', 'dazont-ecom' ),
				'reading'   => __( 'Asking Google…', 'dazont-ecom' ),
				'none'      => __( 'Nothing filed in this account.', 'dazont-ecom' ),
				'colTitle'  => __( 'Promotion', 'dazont-ecom' ),
				'colWhere'  => __( 'Market', 'dazont-ecom' ),
				'colEnds'   => __( 'Ends', 'dazont-ecom' ),
				'end'       => __( 'End it', 'dazont-ecom' ),
				'ending'    => __( 'Ending…', 'dazont-ecom' ),
				'ended'     => __( 'Ended', 'dazont-ecom' ),
				'sure'      => __( 'End this promotion in Merchant Center? Google stops serving it within a few hours.', 'dazont-ecom' ),
			],
		] );
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$accounts      = self::get_accounts();
		$keys          = self::account_keys();
		$languages     = DZE_Wpml::get_active_languages();
		$has_creds     = ( null !== $this->get_credentials() );
		$creds_locked  = defined( 'DZE_GMC_SERVICE_ACCOUNT' );
		$oauth         = self::get_oauth();
		$connection    = self::get_connection();
		$redirect_uri  = $this->oauth_redirect_uri();
		$oauth_ready   = ! empty( $oauth['client_id'] ) && ! empty( $oauth['client_secret'] );
		$connected     = ! empty( $connection['refresh_token'] );
		$authorize_url = $oauth_ready ? $this->oauth_authorize_url() : '';
		$advanced      = (string) get_option( self::OPT_ADVANCED, '' );
		$ads_only      = '' !== (string) get_option( self::OPT_ADS_ONLY, '' );
		// Which accounts a Google Ads campaign actually reads — read once for
		// the screen, from a six-hour cache.
		$ads_links = [];
		if ( $connected || $has_creds ) {
			foreach ( self::get_accounts() as $acc_key => $acc_row ) {
				if ( ! empty( $acc_row['merchant_id'] ) ) {
					$ads_links[ $acc_key ] = $this->ads_links_state( (string) $acc_row['merchant_id'] );
				}
			}
		}
		require DZE_DIR . 'admin/views/gmc-settings.php';
	}

	// =========================================================================
	// OAuth2 (service account)
	// =========================================================================

	private function get_access_token(): string {
		// Prefer the connected Google account (OAuth) — the natural in-plugin flow.
		$oauth = self::get_oauth();
		$conn  = self::get_connection();
		if ( ! empty( $conn['refresh_token'] ) ) {
			return $this->oauth_access_token();
		}

		// Fallback: service-account credentials (JWT).
		$sa = $this->get_credentials();
		if ( null === $sa ) {
			throw new RuntimeException( sprintf(
				/* translators: internal diagnostic state, not translated */
				__( 'No Google authentication configured. Connect your Google account above. (debug: oauth_refresh_token=%s, oauth_client=%s, service_account=%s)', 'dazont-ecom' ),
				empty( $conn['refresh_token'] ) ? 'missing' : 'present',
				( ! empty( $oauth['client_id'] ) && ! empty( $oauth['client_secret'] ) ) ? 'present' : 'missing',
				defined( 'DZE_GMC_SERVICE_ACCOUNT' ) ? 'constant' : ( get_option( self::OPT_CREDENTIALS, '' ) !== '' ? 'option-set-but-invalid' : 'none' )
			) );
		}

		$cache_key = 'dze_gmc_token_' . md5( $sa['client_email'] );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		if ( ! function_exists( 'openssl_sign' ) ) {
			throw new RuntimeException( __( 'PHP OpenSSL is required to sign the Google token request.', 'dazont-ecom' ) );
		}

		$now    = time();
		$header = $this->b64url( (string) wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
		$claim  = $this->b64url( (string) wp_json_encode( [
			'iss'   => $sa['client_email'],
			'scope' => self::SCOPE,
			'aud'   => $sa['token_uri'],
			'iat'   => $now,
			'exp'   => $now + 3600,
		] ) );

		$signature = '';
		if ( ! openssl_sign( $header . '.' . $claim, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			throw new RuntimeException( __( 'Could not sign the Google authentication request (bad private key?).', 'dazont-ecom' ) );
		}
		$jwt = $header . '.' . $claim . '.' . $this->b64url( $signature );

		$response = wp_remote_post( $sa['token_uri'], [
			'timeout' => 20,
			'body'    => [
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			],
		] );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			$msg = $data['error_description'] ?? ( $data['error'] ?? 'Unknown token error' );
			throw new RuntimeException( sprintf( __( 'Google token error: %s', 'dazont-ecom' ), $msg ) );
		}

		set_transient( $cache_key, $data['access_token'], self::TOKEN_TTL );
		return $data['access_token'];
	}

	private function b64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	// =========================================================================
	// Promotion sync
	// =========================================================================

	/**
	 * Pushes a sale rule as one Merchant Center promotion per configured target
	 * country (a GMC promotion always targets a single country). Returns
	 * [ "lang|COUNTRY" => [status,message,...] ] and stores it on the rule.
	 */
	public function sync_rule( string $rule_id ): array {
		$rules = DZE_Discounts::get_rules();
		if ( ! isset( $rules[ $rule_id ] ) ) {
			return [];
		}
		$rule = $rules[ $rule_id ];
		if ( ( $rule['type'] ?? '' ) !== 'sale' ) {
			return [];
		}

		$statuses = [];
		foreach ( $this->sync_targets( $rule ) as $t ) {
			$sk = $t['key'] . '|' . $t['country'];
			try {
				$token       = $this->get_access_token();
				$promotion   = $this->build_promotion( $rule, $t['key'], $t['country'], $t['language'] );
				$data_source = $this->resolve_data_source( $t['merchant_id'], $t['country'], $t['language'], $token );

				$url = self::MERCHANT_API . '/' . self::PROMO_SUBAPI . '/accounts/' . $t['merchant_id'] . '/promotions:insert';
				$this->request( 'POST', $url, $token, [
					'promotion'  => $promotion,
					'dataSource' => $data_source,
				] );
				// The merchant is written down with the result: cancelling later
				// must reach the account it was actually pushed to, even if the
				// settings have changed since.
				$statuses[ $sk ] = [
					'status'       => 'synced',
					'message'      => '',
					'promotion_id' => $promotion['promotionId'],
					'merchant_id'  => $t['merchant_id'],
					// The account by NAME, written down with the result. A line
					// reading "US: Promotion program not enabled for 5581970069"
					// names a country the shop did not choose and a number
					// nobody recognises; the account is what actually failed.
					'account'      => $this->account_label( $t['merchant_id'] ),
					'language'     => $t['language'],
					'country'      => $t['country'],
					'time'         => time(),
				];
			} catch ( \Throwable $e ) {
				$statuses[ $sk ] = [
					'status'      => 'error',
					'message'     => $e->getMessage(),
					'merchant_id' => $t['merchant_id'],
					'account'     => $this->account_label( $t['merchant_id'] ),
					'country'     => $t['country'],
					'time'        => time(),
				];
			}
		}

		$rules[ $rule_id ]['gmc_sync'] = $statuses;
		// What Google now holds, in one string. The automatic sync re-sends a
		// promotion when this changes and leaves it alone when it has not —
		// written only when something actually went through, because a
		// promotion no account accepted has to be tried again, not filed away
		// as delivered.
		foreach ( $statuses as $one ) {
			if ( ( $one['status'] ?? '' ) === 'synced' ) {
				$rules[ $rule_id ]['gmc_fp'] = $this->rule_fingerprint( $rule );
				break;
			}
		}
		update_option( DZE_Discounts::OPTION, $rules, false );

		return $statuses;
	}

	// =========================================================================
	// Automatic sync
	// =========================================================================

	/**
	 * Whether Google is kept up to date on its own. On unless switched off.
	 */
	public static function auto_on(): bool {
		return '' !== (string) get_option( self::OPT_AUTO, '1' );
	}

	/**
	 * What Google is holding, boiled down to one string.
	 *
	 * The automatic sync re-sends a promotion when this changes and leaves it
	 * alone when it has not — so everything Merchant Center actually reads goes
	 * in, and nothing else does: editing the emails of a promotion or the
	 * colour of its banner is not a change Google would ever see.
	 *
	 * The targets are NOT read here on purpose. sync_targets() asks Google what
	 * each account sells to, and asking that question every time we wonder
	 * whether there is a question to ask is how an hourly job turns into a
	 * pile of HTTP calls. The accounts themselves are the cheap stand-in: they
	 * change exactly when the targets do.
	 */
	public function rule_fingerprint( array $rule ): string {
		$parts = [
			(string) ( $rule['id'] ?? '' ),
			(string) ( $rule['title'] ?? '' ),
			(string) ( $rule['banner_text'] ?? '' ),
			wp_json_encode( (array) ( $rule['banner_text_i18n'] ?? [] ) ),
			(string) ( $rule['percent'] ?? '' ),
			(string) ( $rule['start'] ?? '' ),
			(string) ( $rule['end'] ?? '' ),
			empty( $rule['enabled'] ) ? '0' : '1',
			wp_json_encode( (array) ( $rule['languages'] ?? [] ) ),
			wp_json_encode( self::get_accounts() ),
			(string) get_option( self::OPT_ADS_ONLY, '' ),
		];
		return md5( implode( '|', $parts ) );
	}

	/** A promotion Google should be told about, and has not been told about yet. */
	public function needs_sync( array $rule ): bool {
		if ( ( $rule['type'] ?? '' ) !== 'sale' || empty( $rule['enabled'] ) ) {
			return false;
		}
		[ $start, $end ] = DZE_Discounts::instance()->window_ts( $rule );
		if ( PHP_INT_MIN === $start || PHP_INT_MAX === $end ) {
			return false; // a GMC promotion needs both dates; nothing to send.
		}
		if ( $end < time() ) {
			return false; // over — Google stopped it on its own endTime.
		}
		return $this->rule_fingerprint( $rule ) !== (string) ( $rule['gmc_fp'] ?? '' );
	}

	/** A promotion Google is running that the shop has switched off. */
	public function needs_cancel( array $rule ): bool {
		if ( ( $rule['type'] ?? '' ) !== 'sale' || ! empty( $rule['enabled'] ) ) {
			return false;
		}
		if ( 'ended' === (string) ( $rule['gmc_fp'] ?? '' ) ) {
			return false; // already taken down.
		}
		foreach ( (array) ( $rule['gmc_sync'] ?? [] ) as $one ) {
			if ( ( $one['status'] ?? '' ) === 'synced' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A promotion was saved, switched on or switched off: Google follows.
	 *
	 * Called from every path that changes a promotion, and it is the only
	 * thing those paths have to call — what to do, and whether there is
	 * anything to do at all, is decided here.
	 */
	public function on_rule_saved( string $rule_id ): void {
		if ( ! self::auto_on() ) {
			return;
		}
		$rules = DZE_Discounts::get_rules();
		$rule  = (array) ( $rules[ $rule_id ] ?? [] );
		if ( empty( $rule ) ) {
			return;
		}
		// What the promotion needs is worked out from the rule alone, before
		// anything asks the connection whether it exists: this runs once per
		// promotion on a screen that lists them all, and the connection is
		// read from the database each time it is asked.
		$what = $this->needs_cancel( $rule ) ? 'cancel' : ( $this->needs_sync( $rule ) ? 'sync' : '' );
		if ( '' === $what || ! $this->is_configured() ) {
			return;
		}
		$this->queue_rule( $rule_id, $what );
	}

	/**
	 * Hands the Google round-trip to the background.
	 *
	 * Never in the request that saved: one insert per market, each with a
	 * token and a data source behind it, is not something the owner should
	 * watch a Save button spin through.
	 */
	public function queue_rule( string $rule_id, string $what = 'sync' ): void {
		// Queued once. This is called from every screen that could notice the
		// work is due, including one that redraws a list of promotions, and a
		// queue is not a place to put the same job twenty times.
		$mark = 'dze_gmc_q_' . md5( $rule_id . '|' . $what );
		if ( get_transient( $mark ) ) {
			return;
		}
		set_transient( $mark, 1, 5 * MINUTE_IN_SECONDS );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::SYNC_ONE, [ $rule_id, $what ], 'dazont-ecom' );
			return;
		}
		if ( wp_next_scheduled( self::SYNC_ONE, [ $rule_id, $what ] ) ) {
			return;
		}
		wp_schedule_single_event( time() + 20, self::SYNC_ONE, [ $rule_id, $what ] );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron(); // non-blocking: WP's own fire-and-forget ping.
		}
	}

	/**
	 * The queued job. Also the hourly job's single step, so the two cannot
	 * drift apart, and locked so a double click and a cron tick landing
	 * together do not both push the same promotion.
	 *
	 * @param string $rule_id The promotion.
	 * @param string $what    'sync' or 'cancel'.
	 */
	public function run_queued( $rule_id = '', $what = 'sync' ): void {
		$rule_id = (string) $rule_id;
		$what    = ( 'cancel' === $what ) ? 'cancel' : 'sync';
		if ( '' === $rule_id || ! $this->is_configured() ) {
			return;
		}
		$lock = 'dze_gmc_run_' . md5( $rule_id );
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );
		try {
			$rules = DZE_Discounts::get_rules();
			$rule  = (array) ( $rules[ $rule_id ] ?? [] );
			if ( empty( $rule ) ) {
				return;
			}
			if ( 'cancel' === $what ) {
				if ( $this->needs_cancel( $rule ) ) {
					$this->cancel_rule( $rule );
					$this->remember_state( $rule_id, 'ended' );
				}
				return;
			}
			if ( $this->needs_sync( $rule ) ) {
				$this->sync_rule( $rule_id );
			}
		} finally {
			delete_transient( $lock );
		}
	}

	/** Writes down where a promotion stands with Google, and nothing else. */
	private function remember_state( string $rule_id, string $state ): void {
		$rules = DZE_Discounts::get_rules();
		if ( ! isset( $rules[ $rule_id ] ) ) {
			return;
		}
		$rules[ $rule_id ]['gmc_fp'] = $state;
		update_option( DZE_Discounts::OPTION, $rules, false );
	}

	/**
	 * Cancels a rule's promotions in Google (called when the promo is deleted or
	 * disabled in the shop). The Merchant API has no delete for promotions, so we
	 * re-insert each previously-synced promotion with an end time in the past —
	 * Google then stops showing it. Best-effort and silent: a failure here must
	 * never block the shop-side delete.
	 */
	public function cancel_rule( array $rule ): array {
		if ( ( $rule['type'] ?? '' ) !== 'sale' ) {
			return [];
		}
		$synced = (array) ( $rule['gmc_sync'] ?? [] );
		if ( empty( $synced ) ) {
			return []; // nothing was ever pushed.
		}
		try {
			$token = $this->get_access_token();
		} catch ( \Throwable $e ) {
			return [ [ 'ok' => false, 'where' => __( 'Google', 'dazont-ecom' ), 'message' => $e->getMessage() ] ];
		}
		$now = time();

		// Walked over what was actually PUSHED, not over the targets the
		// settings would produce today. An account since removed from the
		// "Google Ads only" filter, a country dropped from a data source, an
		// account renamed: each of those used to leave a live promotion in
		// Merchant Center that nothing would ever take down again.
		$accounts = self::get_accounts();
		$out      = [];
		foreach ( $synced as $sk => $record ) {
			if ( ( $record['status'] ?? '' ) !== 'synced' ) {
				continue;
			}
			$parts    = explode( '|', (string) $sk );
			$key      = (string) ( $parts[0] ?? '' );
			$country  = strtoupper( (string) ( $parts[1] ?? '' ) );
			$merchant = (string) ( $record['merchant_id'] ?? ( $accounts[ $key ]['merchant_id'] ?? '' ) );
			$language = (string) ( $record['language'] ?? strtolower( substr( $key, 0, 2 ) ) );
			if ( '' === $merchant || '' === $country ) {
				$out[] = [
					'ok'      => false,
					'where'   => $sk,
					/* translators: %s: the language/country the promotion was pushed to */
					'message' => __( 'The Merchant Center account it was pushed to is no longer configured, so it could not be taken down.', 'dazont-ecom' ),
				];
				continue;
			}
			try {
				$promotion = $this->build_promotion( $rule, $key, $country, $language );
				// The same promotion id, or Google files a second promotion
				// beside the live one instead of replacing it.
				if ( ! empty( $record['promotion_id'] ) ) {
					$promotion['promotionId'] = (string) $record['promotion_id'];
				}
				$promotion['attributes']['promotionEffectiveTimePeriod'] = self::ended_period(
					(string) ( $promotion['attributes']['promotionEffectiveTimePeriod']['startTime'] ?? '' ),
					$now
				);
				$data_source = $this->resolve_data_source( $merchant, $country, $language, $token );
				$url = self::MERCHANT_API . '/' . self::PROMO_SUBAPI . '/accounts/' . $merchant . '/promotions:insert';
				$this->request( 'POST', $url, $token, [ 'promotion' => $promotion, 'dataSource' => $data_source ] );
				$out[] = [ 'ok' => true, 'where' => $merchant . ' ' . $country, 'message' => '' ];
			} catch ( \Throwable $e ) {
				$out[] = [ 'ok' => false, 'where' => $merchant . ' ' . $country, 'message' => $e->getMessage() ];
			}
		}
		return $out;
	}

	/**
	 * The concrete sync targets for a rule: one entry per (language, country)
	 * that has a configured merchant account. Countries without a configured
	 * account are simply not offered — this is the single source of truth used
	 * by both the sync and the badges in the promos list.
	 *
	 * @return array<int,array{key:string,country:string,language:string,merchant_id:string}>
	 */
	private function sync_targets( array $rule ): array {
		$accounts = self::get_accounts();
		$targets  = [];
		foreach ( $this->target_language_keys( $rule ) as $key ) {
			$acc = $accounts[ $key ] ?? null;
			if ( empty( $acc['merchant_id'] ) ) {
				continue;
			}
			// Asked to work for Google Ads only: an account no campaign reads is
			// skipped rather than filled with promotions nobody will serve.
			if ( ! empty( get_option( self::OPT_ADS_ONLY, '' ) ) && ! $this->has_active_ads_link( (string) $acc['merchant_id'] ) ) {
				continue;
			}
			$language = ( $key !== 'default' ) ? $key : ( $acc['language'] ?: get_locale() );
			$language = strtolower( substr( (string) $language, 0, 2 ) );
			foreach ( $this->promo_countries( (string) $acc['merchant_id'], $language ) as $country ) {
				$targets[] = [
					'key'         => $key,
					'country'     => $country,
					'language'    => $language,
					'merchant_id' => (string) $acc['merchant_id'],
				];
			}
		}
		return $targets;
	}

	/** Language keys the promo targets (effective WPML languages, or 'default'). */
	private function target_language_keys( array $rule ): array {
		if ( DZE_Wpml::is_active() ) {
			$eff = DZE_Discounts::instance()->rule_effective_languages( $rule );
			return ! empty( $eff ) ? $eff : [ DZE_Wpml::default_language() ];
		}
		return [ 'default' ];
	}

	/** Builds a Merchant API Promotion resource for a rule/language/country. */
	private function build_promotion( array $rule, string $key, string $country, string $language ): array {
		[ $start_ts, $end_ts ] = DZE_Discounts::instance()->window_ts( $rule );
		if ( $start_ts === PHP_INT_MIN || $end_ts === PHP_INT_MAX ) {
			throw new RuntimeException( __( 'GMC promotions need both a start and an end date.', 'dazont-ecom' ) );
		}

		$language = strtolower( substr( $language, 0, 2 ) );
		$country  = strtoupper( substr( $country, 0, 2 ) );

		// Long title: translated banner text if available, else the promo title.
		$title = $rule['banner_text'] ?? '';
		$i18n  = (array) ( $rule['banner_text_i18n'] ?? [] );
		if ( ! empty( $i18n[ $key ] ) ) {
			$title = $i18n[ $key ];
		}
		if ( trim( (string) $title ) === '' ) {
			$title = $rule['title'] ?? 'Promotion';
		}
		$title = mb_substr( wp_strip_all_tags( (string) $title ), 0, 60 );

		$percent_int = (int) round( (float) ( $rule['percent'] ?? 0 ) );

		$promotion = [
			'promotionId'       => 'dze_' . preg_replace( '/[^A-Za-z0-9_]/', '', (string) $rule['id'] ),
			'targetCountry'     => $country,
			'contentLanguage'   => $language,
			'redemptionChannel' => [ 'ONLINE' ],
			'attributes'        => [
				'productApplicability'         => 'ALL_PRODUCTS',
				'offerType'                    => 'NO_CODE',
				'longTitle'                    => $title,
				'couponValueType'              => 'PERCENT_OFF',
				// Merchant API expects the percentage as a string (int64).
				'percentOff'                   => (string) $percent_int,
				'promotionEffectiveTimePeriod' => [
					'startTime' => gmdate( 'Y-m-d\TH:i:s\Z', $start_ts ),
					'endTime'   => gmdate( 'Y-m-d\TH:i:s\Z', $end_ts ),
				],
				'promotionDestinations'        => [ 'SHOPPING_ADS', 'FREE_LISTINGS' ],
			],
		];

		return $promotion;
	}

	/**
	 * Returns the promotion data source resource name for an account/country/
	 * language, creating one if none exists. Merchant API requires promotions
	 * to be inserted into a data source; the result is cached so we hit the
	 * list/create endpoints only once per target.
	 */
	private function resolve_data_source( string $merchant_id, string $country, string $language, string $token ): string {
		$cache = get_option( self::OPT_DATASOURCES, [] );
		$cache = is_array( $cache ) ? $cache : [];
		$ck    = $merchant_id . '|' . strtoupper( $country ) . '|' . strtolower( $language );
		if ( ! empty( $cache[ $ck ] ) ) {
			return $cache[ $ck ];
		}

		$base = self::MERCHANT_API . '/' . self::DS_SUBAPI . '/accounts/' . $merchant_id . '/dataSources';

		// Reuse an existing promotion data source for this country + language.
		$list = $this->request( 'GET', $base . '?pageSize=200', $token );
		foreach ( (array) ( $list['dataSources'] ?? [] ) as $ds ) {
			$pds = $ds['promotionDataSource'] ?? null;
			if ( is_array( $pds )
				&& strtoupper( (string) ( $pds['targetCountry'] ?? '' ) ) === strtoupper( $country )
				&& strtolower( (string) ( $pds['contentLanguage'] ?? '' ) ) === strtolower( $language )
				&& ! empty( $ds['name'] ) ) {
				$cache[ $ck ] = $ds['name'];
				update_option( self::OPT_DATASOURCES, $cache, false );
				return $ds['name'];
			}
		}

		// None found — create one.
		$created = $this->request( 'POST', $base, $token, [
			'displayName'         => 'Dazont Ecom promotions ' . strtoupper( $country ) . '/' . strtolower( $language ),
			'promotionDataSource' => [
				'targetCountry'   => strtoupper( $country ),
				'contentLanguage' => strtolower( $language ),
			],
		] );
		if ( empty( $created['name'] ) ) {
			throw new RuntimeException( __( 'Could not create a Google promotion data source.', 'dazont-ecom' ) );
		}
		$cache[ $ck ] = $created['name'];
		update_option( self::OPT_DATASOURCES, $cache, false );
		return $created['name'];
	}

	private function request( string $method, string $url, string $token, ?array $body = null ): array {
		$response = wp_remote_request( $url, [
			'method'  => $method,
			'timeout' => 25,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body'    => $body ? wp_json_encode( $body ) : null,
		] );
		$doing = $method . ' ' . (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( is_wp_error( $response ) ) {
			DZE_Health::log( 'gmc', $doing, $response->get_error_message() );
			throw new RuntimeException( $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
			DZE_Health::log( 'gmc', $doing, 'HTTP ' . $code . ' — ' . $msg );
			throw new RuntimeException( $msg );
		}
		return is_array( $data ) ? $data : [];
	}

	// =========================================================================
	// What Google is actually holding
	//
	// The plugin only ever knew about the promotions it had pushed and still
	// had a rule for. Delete the rule and it forgot — so a take-down that had
	// failed, or one that ran before the take-down was fixed, left a promotion
	// live in Merchant Center that nothing here could see, let alone end. This
	// asks Google what it holds and lets any of it be ended from this screen.
	// =========================================================================

	/**
	 * The promotions an account currently holds, soonest to end first.
	 *
	 * @return array<int,array{id:string,title:string,country:string,language:string,ends:string,ours:bool}>
	 */
	public function list_promotions( string $merchant_id ): array {
		$merchant_id = preg_replace( '/[^0-9]/', '', $merchant_id );
		if ( '' === $merchant_id ) {
			return [];
		}
		$token = $this->get_access_token();
		$url   = self::MERCHANT_API . '/' . self::PROMO_SUBAPI . '/accounts/' . $merchant_id . '/promotions?pageSize=100';
		$list  = $this->request( 'GET', $url, $token );
		$out   = [];
		foreach ( (array) ( $list['promotions'] ?? [] ) as $p ) {
			$id = (string) ( $p['promotionId'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$ends = (string) ( $p['attributes']['promotionEffectiveTimePeriod']['endTime'] ?? '' );
			$out[] = [
				'id'       => $id,
				'title'    => (string) ( $p['attributes']['longTitle'] ?? $id ),
				'country'  => strtoupper( (string) ( $p['targetCountry'] ?? '' ) ),
				'language' => strtolower( (string) ( $p['contentLanguage'] ?? '' ) ),
				'ends'     => $ends,
				// Ours carry the id the plugin builds; anything else was made
				// in Merchant Center by hand and is shown, not hidden.
				'ours'     => 0 === strpos( $id, 'dze_' ),
			];
		}
		usort( $out, static fn( array $a, array $b ): int => strcmp( $a['ends'], $b['ends'] ) );
		return $out;
	}

	/**
	 * Ends one promotion, whatever put it there.
	 *
	 * Google has no delete for promotions: a promotion is ended by filing it
	 * again, under the same id, with an effective period that is already over.
	 */
	public function end_promotion( string $merchant_id, string $promotion_id, string $country, string $language ): void {
		$merchant_id = preg_replace( '/[^0-9]/', '', $merchant_id );
		$country     = strtoupper( substr( $country, 0, 2 ) );
		$language    = strtolower( substr( $language, 0, 2 ) );
		if ( '' === $merchant_id || '' === $promotion_id || '' === $country || '' === $language ) {
			throw new RuntimeException( __( 'That promotion is missing the account, the country or the language it belongs to.', 'dazont-ecom' ) );
		}
		$token = $this->get_access_token();
		$now   = time();
		// Read first: everything Google requires on an insert has to come back
		// unchanged, or the promotion is rewritten into something it never was.
		$url   = self::MERCHANT_API . '/' . self::PROMO_SUBAPI . '/accounts/' . $merchant_id . '/promotions/' . rawurlencode( $promotion_id );
		$live  = $this->request( 'GET', $url, $token );
		$promotion = [
			'promotionId'       => $promotion_id,
			'targetCountry'     => $country,
			'contentLanguage'   => $language,
			'redemptionChannel' => (array) ( $live['redemptionChannel'] ?? [ 'ONLINE' ] ),
			'attributes'        => (array) ( $live['attributes'] ?? [] ),
		];
		$promotion['attributes']['promotionEffectiveTimePeriod'] = self::ended_period(
			(string) ( $live['attributes']['promotionEffectiveTimePeriod']['startTime'] ?? '' ),
			$now
		);
		if ( empty( $promotion['attributes']['longTitle'] ) ) {
			$promotion['attributes']['longTitle'] = $promotion_id;
		}
		$data_source = $this->resolve_data_source( $merchant_id, $country, $language, $token );
		$this->request(
			'POST',
			self::MERCHANT_API . '/' . self::PROMO_SUBAPI . '/accounts/' . $merchant_id . '/promotions:insert',
			$token,
			[ 'promotion' => $promotion, 'dataSource' => $data_source ]
		);
	}

	public function ajax_promotions(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$out = [];
		foreach ( self::get_accounts() as $key => $account ) {
			$merchant = (string) ( $account['merchant_id'] ?? '' );
			if ( '' === $merchant ) {
				continue;
			}
			try {
				$out[] = [
					'key'      => (string) $key,
					'merchant' => $merchant,
					'rows'     => $this->list_promotions( $merchant ),
					'error'    => '',
				];
			} catch ( \Throwable $e ) {
				$out[] = [ 'key' => (string) $key, 'merchant' => $merchant, 'rows' => [], 'error' => $e->getMessage() ];
			}
		}
		if ( ! $out ) {
			wp_send_json_error( [ 'message' => __( 'No Merchant Center account is configured.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'accounts' => $out ] );
	}

	public function ajax_end_promotion(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		try {
			$this->end_promotion(
				isset( $_POST['merchant'] ) ? sanitize_text_field( wp_unslash( $_POST['merchant'] ) ) : '',
				isset( $_POST['promotion'] ) ? sanitize_text_field( wp_unslash( $_POST['promotion'] ) ) : '',
				isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '',
				isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : ''
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'message' => __( 'Ended. Google stops serving it within a few hours.', 'dazont-ecom' ) ] );
	}

	/**
	 * Keeps the promotions Google already has in step with the shop.
	 *
	 * It used to push EVERY enabled sale, which meant a promotion reached
	 * Google the hour it was created — before anybody had looked at it. Since
	 * a marketing event is now active from the moment it is accepted, that
	 * turned "I saved an event" into "I published an ad", which is not the
	 * same decision and was never asked for.
	 *
	 * So the cron no longer PUBLISHES anything: it only refreshes what was
	 * already sent, from the events list or the event's own button. A
	 * promotion Google has never seen waits for somebody to press push.
	 */
	/**
	 * The period that ends a promotion, and still reads like something.
	 *
	 * Google has no delete: a promotion is ended by refiling it with a period
	 * already over. Written as "a minute ago to a minute ago", the Merchant
	 * Center row then showed a Christmas sale as running "26 August to 26
	 * August" — true of the take-down, meaningless about the promotion, and
	 * alarming to read.
	 *
	 * So a promotion that had already started keeps its real start date and is
	 * simply closed today: the row then says when it actually ran. Only one
	 * that never started gets the minute-long period, because a period cannot
	 * end before it begins.
	 *
	 * @param string $started Google's own startTime for the promotion, when known.
	 *
	 * @return array{startTime:string,endTime:string}
	 */
	private static function ended_period( string $started, int $now ): array {
		$start_ts = '' !== $started ? strtotime( $started ) : false;
		if ( $start_ts && $start_ts < $now - 120 ) {
			return [
				'startTime' => gmdate( 'Y-m-d\TH:i:s\Z', $start_ts ),
				'endTime'   => gmdate( 'Y-m-d\TH:i:s\Z', $now - 60 ),
			];
		}
		return [
			'startTime' => gmdate( 'Y-m-d\TH:i:s\Z', $now - 120 ),
			'endTime'   => gmdate( 'Y-m-d\TH:i:s\Z', $now - 60 ),
		];
	}

	/**
	 * The hourly round: every promotion, in whichever direction it needs.
	 *
	 * It used to refresh only promotions that had ALREADY been pushed by hand,
	 * which meant the one state that actually needs a machine — a promotion
	 * running in the shop that Google has never heard of — was the one state
	 * it ignored. It now sends what has not been sent, re-sends what changed,
	 * takes down what was switched off, and does nothing at all for the rest.
	 */
	public function cron_sync_all(): void {
		if ( ! self::auto_on() || ! $this->is_configured() ) {
			return;
		}
		foreach ( DZE_Discounts::get_rules() as $id => $rule ) {
			if ( ( $rule['type'] ?? '' ) !== 'sale' ) {
				continue;
			}
			if ( $this->needs_cancel( $rule ) ) {
				$this->run_queued( (string) $id, 'cancel' );
			} elseif ( $this->needs_sync( $rule ) ) {
				$this->run_queued( (string) $id, 'sync' );
			}
		}
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	public function ajax_sync(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['ids'] ) ) : [];
		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'No promotion selected.', 'dazont-ecom' ) ] );
		}

		$results = [];
		$badges  = [];
		$rules   = DZE_Discounts::get_rules();
		foreach ( $ids as $id ) {
			$results[ $id ] = $this->sync_rule( $id );
		}
		// The dots are drawn when the page loads and were never redrawn after
		// a sync, so a promotion could go live on Google and the screen went on
		// showing it as pending until somebody reloaded. They are sent back
		// with the result, built by the same function the page used, so the two
		// cannot say different things.
		$rules = DZE_Discounts::get_rules();
		foreach ( $ids as $id ) {
			if ( isset( $rules[ $id ] ) ) {
				$badges[ $id ] = $this->sync_badges_html( $rules[ $id ] );
			}
		}
		wp_send_json_success( [
			'results'  => $results,
			'badges'   => $badges,
			// Google's own name for each account, so a failure reads as the
			// shop that failed and not as a number nobody recognises.
			'accounts' => $this->account_names(),
		] );
	}

	/**
	 * Asks Google one cheap question and reports what it answered.
	 *
	 * The "Test the connection" button and the weekly checkup must agree on
	 * what "connected" means, so both go through here rather than each having
	 * its own idea of it.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function probe(): array {
		try {
			$token = $this->get_access_token();

			// Go one step further than "got a token": hit the Merchant API for a
			// configured account so the test also confirms the scope, account
			// access and that the Merchant API is enabled — the things that
			// actually make a promotion insert succeed.
			foreach ( self::get_accounts() as $account ) {
				if ( ! empty( $account['merchant_id'] ) ) {
					$url = self::MERCHANT_API . '/' . self::DS_SUBAPI . '/accounts/' . $account['merchant_id'] . '/dataSources?pageSize=1';
					$this->request( 'GET', $url, $token );
					return [
						'ok'      => true,
						'message' => sprintf(
							/* translators: %s: Merchant Center account ID */
							__( 'Merchant API reachable for account %s.', 'dazont-ecom' ),
							$account['merchant_id']
						),
					];
				}
			}

			// Authenticated, but no merchant account configured yet to test against.
			return [ 'ok' => true, 'message' => __( 'Authenticated with Google. Add a Merchant ID to fully test the Merchant API.', 'dazont-ecom' ) ];
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'message' => $e->getMessage() ];
		}
	}

	public function ajax_test(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$probe = $this->probe();
		if ( ! empty( $probe['ok'] ) ) {
			wp_send_json_success( [ 'message' => $probe['message'] ] );
		}
		wp_send_json_error( [ 'message' => $probe['message'] ] );
	}

	// =========================================================================
	// Display helpers (used by the Discounts list)
	// =========================================================================

	/**
	 * Per-target (language + country) sync badges for a rule, for the Discounts
	 * list. Only configured (account-backed) countries appear — countries
	 * without a Merchant account are never offered.
	 */
	public function sync_badges_html( array $rule ): string {
		$sync    = (array) ( $rule['gmc_sync'] ?? [] );
		$targets = $this->sync_targets( $rule );
		if ( empty( $targets ) ) {
			return '<span style="color:#999;" title="' . esc_attr__( 'No Merchant Center account configured.', 'dazont-ecom' ) . '">—</span>';
		}
		// One badge per LANGUAGE, not per country. An account that runs in
		// three countries printed the same letters three times, and the
		// country beside a language said nothing a reader could act on —
		// worse, it read as a claim ("this account is American") the shop
		// never made. The countries are still what the promotion is pushed
		// to, and they are on the badge's tooltip where they belong.
		$rank  = [ 'synced' => 0, 'pending' => 1, 'error' => 2 ];
		$langs = [];
		foreach ( $targets as $t ) {
			$sk    = $t['key'] . '|' . $t['country'];
			$state = (string) ( $sync[ $sk ]['status'] ?? 'pending' );
			$state = isset( $rank[ $state ] ) ? $state : 'pending';
			$key   = $t['key'];
			if ( ! isset( $langs[ $key ] ) ) {
				$langs[ $key ] = [ 'state' => $state, 'countries' => [], 'message' => '' ];
			}
			$langs[ $key ]['countries'][ $t['country'] ] = $t['country'];
			// The worst state of the countries behind it: one failure is a
			// failure, however many succeeded next to it.
			if ( $rank[ $state ] > $rank[ $langs[ $key ]['state'] ] ) {
				$langs[ $key ]['state'] = $state;
			}
			if ( 'error' === $state && '' === $langs[ $key ]['message'] ) {
				$langs[ $key ]['message'] = (string) ( $sync[ $sk ]['message'] ?? '' );
			}
		}

		$out = '';
		foreach ( $langs as $key => $one ) {
			$state = $one['state'];
			$label = ( 'default' === $key ) ? __( 'Shop', 'dazont-ecom' ) : strtoupper( $key );
			$color = 'synced' === $state ? '#0a7040' : ( 'error' === $state ? '#b32d2e' : '#999' );
			$dot   = 'synced' === $state ? '●' : ( 'error' === $state ? '✕' : '○' );
			$title = ucfirst( $state ) . ' · ' . implode( ', ', $one['countries'] );
			if ( '' !== $one['message'] ) {
				$title = $one['message'];
			}
			$out .= sprintf(
				'<span title="%s" style="color:%s;margin-right:8px;white-space:nowrap;">%s %s</span>',
				esc_attr( $label . ' — ' . $title ),
				esc_attr( $color ),
				esc_html( $dot ),
				esc_html( $label )
			);
		}

		// When it last went to Google, or that it never has. Five empty
		// circles look the same whether nothing was ever sent or the record
		// was lost, and the owner cannot act on a state he cannot tell apart.
		$last = 0;
		foreach ( $sync as $one ) {
			$last = max( $last, (int) ( $one['time'] ?? 0 ) );
		}
		$line = $last
			? sprintf(
				/* translators: %s: how long ago, e.g. "2 hours" */
				__( 'Sent %s ago', 'dazont-ecom' ),
				human_time_diff( $last )
			)
			: __( 'Never sent', 'dazont-ecom' );

		// Sending is automatic, so the row says what is ABOUT to happen too: a
		// promotion nothing has pushed yet must not read like a promotion
		// nothing will ever push.
		if ( self::auto_on() ) {
			if ( $this->needs_sync( $rule ) ) {
				$line = $last
					? sprintf(
						/* translators: %s: how long ago, e.g. "2 hours" */
						__( 'Sent %s ago · changed, going out again', 'dazont-ecom' ),
						human_time_diff( $last )
					)
					: __( 'Going out to Google shortly', 'dazont-ecom' );
			} elseif ( $this->needs_cancel( $rule ) ) {
				$line = __( 'Being taken down in Google', 'dazont-ecom' );
			}
		}

		$out .= '<div style="font-size:11px;color:#646970;margin-top:2px;">' . esc_html( $line ) . '</div>';
		return $out;
	}

	/**
	 * Is this Merchant Center account linked to a Google Ads account?
	 *
	 * Google models that link twice. In the Merchant API it is an account
	 * SERVICE — "campaigns management", provided by GOOGLE_ADS, carrying the
	 * Ads customer id as its external account id. In the older Content API it
	 * is the account's adsLinks list. Both are read, the modern one first,
	 * because which of the two answers depends on the account and on the APIs
	 * enabled in the Google Cloud project behind the connection.
	 *
	 * What is NOT readable either way is the spend: whether those campaigns
	 * run and what they cost belongs to the Google Ads API, behind its own
	 * developer token.
	 *
	 * The reason a read failed is kept with the answer, so a screen can say
	 * "could not read" instead of the very different "nothing linked".
	 *
	 * @return array{links:array<int,array{id:string,status:string}>,error:string}
	 */
	public function ads_links_state( string $merchant_id ): array {
		$merchant_id = preg_replace( '/[^0-9]/', '', $merchant_id );
		if ( '' === $merchant_id ) {
			return [ 'links' => [], 'error' => '' ];
		}
		$key    = 'dze_gmc_ads_' . $merchant_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['links'] ) ) {
			return $cached;
		}
		$links  = [];
		$error  = '';
		$token  = '';
		$answer = false; // Did an API actually answer, link or no link?
		try {
			$token = $this->get_access_token();
		} catch ( \Throwable $e ) {
			$state = [ 'links' => [], 'error' => $e->getMessage() ];
			set_transient( $key, $state, 15 * MINUTE_IN_SECONDS );
			return $state;
		}

		// 1. The Merchant API: the services another account provides to this one.
		try {
			$url  = self::MERCHANT_API . '/' . self::ACCOUNTS_SUBAPI . '/accounts/' . $merchant_id . '/services?pageSize=200';
			$data = $this->request( 'GET', $url, $token );
			foreach ( (array) ( $data['accountServices'] ?? $data['services'] ?? [] ) as $svc ) {
				$blob = wp_json_encode( $svc );
				if ( ! is_string( $blob ) || false === stripos( $blob, 'GOOGLE_ADS' ) ) {
					// Only the Ads link is of interest here; the other services
					// an account can be given say nothing about advertising.
					continue;
				}
				$id = (string) ( $svc['externalAccountId'] ?? $svc['provider']['externalAccountId'] ?? '' );
				if ( '' === $id && preg_match( '/"externalAccountId"\s*:\s*"?([0-9-]{6,})"?/', $blob, $m ) ) {
					$id = $m[1];
				}
				if ( '' === $id ) {
					continue;
				}
				// ESTABLISHED is the Merchant API's word for a link both sides
				// have agreed to — an active link, not a state worth printing.
				$state        = strtolower( (string) ( $svc['handshake']['approvalState'] ?? $svc['state'] ?? 'active' ) );
				$live         = in_array( $state, [ 'approved', 'established', 'active', 'enabled' ], true );
				$links[ $id ] = [ 'id' => $id, 'status' => $live ? 'active' : $state ];
			}
			// The account listed its services: an empty list here IS the answer,
			// "no Ads link", and no second API is asked to contradict it.
			$answer = true;
		} catch ( \Throwable $e ) {
			$error = $e->getMessage();
		}

		// 2. The Content API, only when the first one could not answer at all.
		if ( ! $answer ) {
			try {
				$parent = preg_replace( '/[^0-9]/', '', (string) get_option( self::OPT_ADVANCED, '' ) );
				$parent = '' !== $parent ? $parent : $merchant_id;
				$url    = 'https://shoppingcontent.googleapis.com/content/v2.1/' . $parent . '/accounts/' . $merchant_id;
				$data   = $this->request( 'GET', $url, $token );
				foreach ( (array) ( $data['adsLinks'] ?? [] ) as $link ) {
					$id = (string) ( $link['adsId'] ?? '' );
					if ( '' === $id ) {
						continue;
					}
					$links[ $id ] = [ 'id' => $id, 'status' => (string) ( $link['status'] ?? 'active' ) ];
				}
				$answer = true;
				$error  = '';
			} catch ( \Throwable $e ) {
				$error = '' !== $error ? $error : $e->getMessage();
			}
		}

		$state = [ 'links' => array_values( $links ), 'error' => $answer ? '' : $error ];
		// A failure is remembered briefly, an answer for six hours: an account
		// is not linked and unlinked twice a day.
		set_transient( $key, $state, $state['error'] ? 15 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS );
		return $state;
	}

	/** @return array<int,array{id:string,status:string}> */
	public function ads_links( string $merchant_id ): array {
		return $this->ads_links_state( $merchant_id )['links'];
	}

	/** Does this account have at least one ACTIVE Google Ads link? */
	public function has_active_ads_link( string $merchant_id ): bool {
		foreach ( $this->ads_links( $merchant_id ) as $link ) {
			if ( 'active' === strtolower( $link['status'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Verifies that a Merchant Center account ID is reachable with the current
	 * authentication (Merchant API enabled, account accessible). Returns the
	 * account's display name on success; throws on failure.
	 */
	public function verify_account( string $merchant_id ): string {
		$token = $this->get_access_token();
		$url   = self::MERCHANT_API . '/' . self::ACCOUNTS_SUBAPI . '/accounts/' . $merchant_id;
		$data  = $this->request( 'GET', $url, $token );
		return (string) ( $data['accountName'] ?? $data['name'] ?? $merchant_id );
	}

	public function ajax_verify(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$merchant_id = isset( $_POST['merchant_id'] ) ? preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['merchant_id'] ) ) : '';
		if ( $merchant_id === '' ) {
			wp_send_json_error( [ 'message' => __( 'Enter a Merchant ID first.', 'dazont-ecom' ) ] );
		}
		try {
			$name = $this->verify_account( $merchant_id );
			// Remembered against the id, so every screen that reports on this
			// account afterwards can name it. Verifying is the one moment
			// Google tells us what it is called.
			$this->remember_account_name( $merchant_id, $name );
			wp_send_json_success( [ 'message' => sprintf(
				/* translators: %s: Merchant Center account name */
				__( 'Reachable: %s', 'dazont-ecom' ),
				$name
			) ] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Registers the calling GCP project as a developer of the given Merchant
	 * Center account — the one-time step the Merchant API requires before it
	 * accepts direct API calls ("GCP project … is not registered with the
	 * merchant account"). Idempotent: registering an already-registered project
	 * simply succeeds.
	 */
	public function register_gcp( string $merchant_id ): array {
		$token = $this->get_access_token();
		$url   = self::MERCHANT_API . '/' . self::ACCOUNTS_SUBAPI . '/accounts/' . $merchant_id . '/developerRegistration:registerGcp';
		$conn  = self::get_connection();
		$body  = [];
		if ( ! empty( $conn['email'] ) ) {
			$body['developerEmail'] = $conn['email'];
		}
		return $this->request( 'POST', $url, $token, $body ?: null );
	}

	public function ajax_register(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$merchant_id = isset( $_POST['merchant_id'] ) ? preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['merchant_id'] ) ) : '';
		if ( $merchant_id === '' ) {
			wp_send_json_error( [ 'message' => __( 'Enter a Merchant ID first.', 'dazont-ecom' ) ] );
		}
		try {
			$data = $this->register_gcp( $merchant_id );
			$gcp  = ! empty( $data['gcpIds'] ) ? implode( ', ', (array) $data['gcpIds'] ) : '';
			wp_send_json_success( [ 'message' => $gcp !== ''
				/* translators: %s: registered GCP project id(s) */
				? sprintf( __( 'GCP project registered (%s). Wait ~5 min, then Sync.', 'dazont-ecom' ), $gcp )
				: __( 'GCP project registered. Wait ~5 min, then Sync.', 'dazont-ecom' )
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}
}
