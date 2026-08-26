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

		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_dze_gmc_sync',      [ $this, 'ajax_sync' ] );
		add_action( 'wp_ajax_dze_gmc_test',      [ $this, 'ajax_test' ] );
		add_action( 'wp_ajax_dze_gmc_verify',    [ $this, 'ajax_verify' ] );
		add_action( 'wp_ajax_dze_gmc_register',  [ $this, 'ajax_register' ] );
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
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function get_accounts(): array {
		$a = get_option( self::OPT_ACCOUNTS, [] );
		return is_array( $a ) ? $a : [];
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
			$clean[ $key ] = [
				'merchant_id' => preg_replace( '/[^0-9]/', '', (string) ( $acc['merchant_id'] ?? '' ) ),
				'language'    => sanitize_key( $acc['language'] ?? $key ),
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
				'syncing'   => __( 'Syncing…', 'dazont-ecom' ),
				'testing'   => __( 'Testing…', 'dazont-ecom' ),
				'verifying' => __( 'Verifying…', 'dazont-ecom' ),
				'registering' => __( 'Registering…', 'dazont-ecom' ),
				'done'      => __( 'Done', 'dazont-ecom' ),
				'error'     => __( 'Error', 'dazont-ecom' ),
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
				$statuses[ $sk ] = [ 'status' => 'synced', 'message' => '', 'promotion_id' => $promotion['promotionId'], 'time' => time() ];
			} catch ( \Throwable $e ) {
				$statuses[ $sk ] = [ 'status' => 'error', 'message' => $e->getMessage(), 'time' => time() ];
			}
		}

		$rules[ $rule_id ]['gmc_sync'] = $statuses;
		update_option( DZE_Discounts::OPTION, $rules, false );

		return $statuses;
	}

	/**
	 * Cancels a rule's promotions in Google (called when the promo is deleted or
	 * disabled in the shop). The Merchant API has no delete for promotions, so we
	 * re-insert each previously-synced promotion with an end time in the past —
	 * Google then stops showing it. Best-effort and silent: a failure here must
	 * never block the shop-side delete.
	 */
	public function cancel_rule( array $rule ): void {
		if ( ( $rule['type'] ?? '' ) !== 'sale' ) {
			return;
		}
		$synced = (array) ( $rule['gmc_sync'] ?? [] );
		if ( empty( $synced ) ) {
			return; // nothing was ever pushed.
		}
		try {
			$token = $this->get_access_token();
		} catch ( \Throwable $e ) {
			return;
		}
		$now   = time();
		$start = gmdate( 'Y-m-d\TH:i:s\Z', $now - 120 );
		$end   = gmdate( 'Y-m-d\TH:i:s\Z', $now - 60 );

		foreach ( $this->sync_targets( $rule ) as $t ) {
			$sk = $t['key'] . '|' . $t['country'];
			if ( ( $synced[ $sk ]['status'] ?? '' ) !== 'synced' ) {
				continue;
			}
			try {
				$promotion = $this->build_promotion( $rule, $t['key'], $t['country'], $t['language'] );
				$promotion['attributes']['promotionEffectiveTimePeriod'] = [ 'startTime' => $start, 'endTime' => $end ];
				$data_source = $this->resolve_data_source( $t['merchant_id'], $t['country'], $t['language'], $token );
				$url = self::MERCHANT_API . '/' . self::PROMO_SUBAPI . '/accounts/' . $t['merchant_id'] . '/promotions:insert';
				$this->request( 'POST', $url, $token, [ 'promotion' => $promotion, 'dataSource' => $data_source ] );
			} catch ( \Throwable $e ) {
				// ignore — best effort.
			}
		}
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
	public function cron_sync_all(): void {
		foreach ( DZE_Discounts::get_rules() as $id => $rule ) {
			if ( ( $rule['type'] ?? '' ) === 'sale' && ! empty( $rule['enabled'] ) && ! empty( $rule['gmc_sync'] ) ) {
				$this->sync_rule( (string) $id );
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
		foreach ( $ids as $id ) {
			$results[ $id ] = $this->sync_rule( $id );
		}
		wp_send_json_success( [ 'results' => $results ] );
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
