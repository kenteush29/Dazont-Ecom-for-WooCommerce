<?php
/**
 * ONE GOOGLE KEY FOR THE WHOLE PLUGIN.
 *
 * « Possible de faire passer toutes les fonctions google par le "Comptes de
 * service" ? » (06/10/2026) — because « pour sortir de la zone test, il faut
 * bien plus que du code : un site, un nom, un logo ». An OAuth app has a
 * consent screen; left in « Testing », Google disconnects it every seven
 * days, and leaving « Testing » means a published app with a verified brand.
 * A service account has no consent screen at all. It is a user like any
 * other, added to Merchant Center, to Search Console and to Google Ads, and
 * its key never expires. So every Google function of the plugin goes through
 * it as soon as the shop has one.
 *
 * What it does NOT change is the Google Ads API access level, which belongs to
 * the Cloud project whatever signs in: see DZE_Ads::explain().
 *
 * The key stays in the row Merchant Center always used, so a shop that pasted
 * one there has nothing to paste again. It is pasted from whichever Google
 * screen the shop is on, through block(), and every one of them says the same
 * thing about it.
 *
 * @package Dazont_Ecom
 */

defined( 'ABSPATH' ) || exit;

final class DZE_Google {

	/** The JSON key — the row Merchant Center always used. */
	public const OPT = 'dze_gmc_credentials';

	/** wp-config.php may hold it instead: a file path or the raw JSON. */
	public const CONSTANT = 'DZE_GMC_SERVICE_ACCOUNT';

	/** Cached access tokens: one per key and per API, under this prefix. */
	public const TOKEN_PREFIX = 'dze_gmc_token_';

	private const NONCE     = 'dze_google_key';
	private const TOKEN_TTL = 3300;
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/** The scope a new key is tried with: any scope proves the key itself. */
	private const CHECK_SCOPE = 'https://www.googleapis.com/auth/content';

	private static bool $script_printed = false;

	public static function init(): void {
		add_action( 'wp_ajax_dze_google_key', [ self::class, 'ajax_save' ] );
	}

	/** Set in wp-config.php: the screens show it and never offer to replace it. */
	public static function locked(): bool {
		return defined( self::CONSTANT );
	}

	/**
	 * The service account's key, or null when the shop has none.
	 *
	 * @return array<string,string>|null
	 */
	public static function credentials(): ?array {
		$raw = '';
		if ( self::locked() ) {
			$raw = constant( self::CONSTANT );
			if ( is_string( $raw ) && strlen( $raw ) < 512 && @is_readable( $raw ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a path or the JSON itself.
				$raw = (string) file_get_contents( $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		}
		if ( ! $raw ) {
			$raw = (string) get_option( self::OPT, '' );
		}
		$sa = json_decode( (string) $raw, true );
		if ( is_array( $sa ) && ! empty( $sa['client_email'] ) && ! empty( $sa['private_key'] ) && ! empty( $sa['token_uri'] ) ) {
			return $sa;
		}
		return null;
	}

	/** The address the shop adds as a user at Google. '' when there is no key. */
	public static function email(): string {
		$sa = self::credentials();
		return null === $sa ? '' : (string) $sa['client_email'];
	}

	/** The Cloud project the key belongs to, so a link can open THAT project. */
	public static function project(): string {
		$sa = self::credentials();
		return null === $sa ? '' : (string) ( $sa['project_id'] ?? '' );
	}

	/**
	 * A page of the Google Cloud console, in the key's own project when known.
	 *
	 * A shop with several projects lands on whichever it used last, and an API
	 * switched on in the wrong one changes nothing.
	 */
	public static function console( string $path ): string {
		$url = 'https://console.cloud.google.com/' . ltrim( $path, '/' );
		$p   = self::project();
		return '' === $p ? $url : $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'project=' . rawurlencode( $p );
	}

	/**
	 * A key pasted by hand, read before it is kept.
	 *
	 * Pure (tools/test-google.php). Each mistake is said as itself: the OAuth
	 * client's JSON — the other file Google Cloud hands out —, a text that is
	 * not JSON, and a private key that does not open.
	 *
	 * @return array{ok:bool,said:string,json:string,email:string}
	 */
	public static function parse( string $raw ): array {
		$raw = trim( $raw );
		$no  = static fn( string $said ): array => [ 'ok' => false, 'said' => $said, 'json' => '', 'email' => '' ];
		if ( '' === $raw ) {
			return $no( __( 'Paste what the JSON file holds first.', 'dazont-ecom' ) );
		}
		$j = json_decode( $raw, true );
		if ( ! is_array( $j ) ) {
			return $no( __( 'This is not the JSON file. Open the downloaded .json file with a text editor and paste everything it holds, braces included.', 'dazont-ecom' ) );
		}
		if ( isset( $j['web'] ) || isset( $j['installed'] ) ) {
			return $no( __( 'This is the JSON of an OAuth client, not of a service account. The service account\'s key is made under IAM & Admin → Service accounts → the account → Keys → Add key → JSON.', 'dazont-ecom' ) );
		}
		if ( 'service_account' !== (string) ( $j['type'] ?? '' ) || empty( $j['client_email'] ) || empty( $j['private_key'] ) ) {
			return $no( __( 'This JSON is not a service account key: such a key says "type": "service_account" and holds a client_email and a private_key.', 'dazont-ecom' ) );
		}
		if ( function_exists( 'openssl_pkey_get_private' ) && false === @openssl_pkey_get_private( (string) $j['private_key'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $no( __( 'The private key in this file does not open: the text was cut or changed on the way. Paste the whole file again, or make a new key.', 'dazont-ecom' ) );
		}
		if ( empty( $j['token_uri'] ) ) {
			$j['token_uri'] = self::TOKEN_URL;
		}
		return [ 'ok' => true, 'said' => '', 'json' => (string) wp_json_encode( $j ), 'email' => (string) $j['client_email'] ];
	}

	/**
	 * An access token for one Google API, signed with the shop's key and kept
	 * for as long as Google lets it live.
	 *
	 * @param string $scope The API's scope (Merchant API: content; Google Ads:
	 *                      adwords; Search Console: webmasters.readonly).
	 */
	public static function token( string $scope ): string {
		$sa = self::credentials();
		if ( null === $sa ) {
			throw new RuntimeException( __( 'No Google service account key is set yet.', 'dazont-ecom' ) );
		}
		$key    = self::TOKEN_PREFIX . md5( $sa['client_email'] . '|' . $scope );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$token = self::sign_in( $sa, $scope );
		set_transient( $key, $token, self::TOKEN_TTL );
		return $token;
	}

	/** Asks Google for a token with this key — never from the cache. */
	private static function sign_in( array $sa, string $scope ): string {
		if ( ! function_exists( 'openssl_sign' ) ) {
			throw new RuntimeException( __( 'PHP OpenSSL is required to sign the Google token request.', 'dazont-ecom' ) );
		}
		$now    = time();
		$header = self::b64url( (string) wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
		$claim  = self::b64url( (string) wp_json_encode( [
			'iss'   => $sa['client_email'],
			'scope' => $scope,
			'aud'   => $sa['token_uri'],
			'iat'   => $now,
			'exp'   => $now + 3600,
		] ) );
		$signature = '';
		if ( ! @openssl_sign( $header . '.' . $claim, $signature, (string) $sa['private_key'], OPENSSL_ALGO_SHA256 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new RuntimeException( __( 'Could not sign the Google authentication request (bad private key?).', 'dazont-ecom' ) );
		}
		$response = wp_remote_post( (string) $sa['token_uri'], [
			'timeout' => 20,
			'body'    => [
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $header . '.' . $claim . '.' . self::b64url( $signature ),
			],
		] );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			$msg = (string) ( $data['error_description'] ?? ( $data['error'] ?? 'Unknown token error' ) );
			/* translators: %s: Google's own words */
			throw new RuntimeException( sprintf( __( 'Google token error: %s', 'dazont-ecom' ), $msg ) );
		}
		return (string) $data['access_token'];
	}

	private static function b64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * A new key: read, tried once against Google, then kept.
	 *
	 * Tried BEFORE it replaces the old one: a key Google refuses is said now,
	 * on the screen where it was pasted, and the one that worked stays.
	 */
	public static function ajax_save(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		if ( self::locked() ) {
			wp_send_json_error( [ 'message' => __( 'The key is set in wp-config.php (DZE_GMC_SERVICE_ACCOUNT): it is changed there.', 'dazont-ecom' ) ] );
		}
		$p = self::parse( isset( $_POST['key'] ) ? (string) wp_unslash( $_POST['key'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a JSON key, read by parse().
		if ( ! $p['ok'] ) {
			wp_send_json_error( [ 'message' => $p['said'] ] );
		}
		try {
			self::sign_in( (array) json_decode( $p['json'], true ), self::CHECK_SCOPE );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => sprintf(
				/* translators: %s: Google's answer */
				__( 'Google refused this key, so it was not saved: %s', 'dazont-ecom' ),
				$e->getMessage()
			) ] );
		}
		update_option( self::OPT, $p['json'], false );
		wp_send_json_success( [
			/* translators: %s: the service account's address */
			'message' => sprintf( __( 'Key saved: %s.', 'dazont-ecom' ), $p['email'] ),
			'reload'  => true,
		] );
	}

	/**
	 * WHERE THE SERVICE ACCOUNT MUST BE A USER, for the modules switched on.
	 *
	 * A key is only half of it: Google answers a service account only where it
	 * has been added, like a person. Each place says where the « add a user »
	 * button is, the least role that does the job, and the API to switch on
	 * in the key's project.
	 *
	 * @return array<int,array{id:string,what:string,where:string,role:string,url:string,api:string,api_url:string}>
	 */
	public static function places(): array {
		$on  = static fn( string $m ): bool => ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( $m );
		$out = [];
		if ( $on( 'gmc' ) || $on( 'google_ads' ) ) {
			$out[] = [
				'id'      => 'merchant',
				'what'    => __( 'Merchant Center', 'dazont-ecom' ),
				'where'   => __( 'In each account: Settings → People and access → Add person', 'dazont-ecom' ),
				'role'    => __( 'Standard', 'dazont-ecom' ),
				'url'     => 'https://merchants.google.com/',
				'api'     => __( 'Merchant API', 'dazont-ecom' ),
				'api_url' => self::console( 'apis/library/merchantapi.googleapis.com' ),
			];
		}
		if ( $on( 'netlinking' ) ) {
			$out[] = [
				'id'      => 'searchconsole',
				'what'    => __( 'Search Console', 'dazont-ecom' ),
				'where'   => __( 'In each property: Settings → Users and permissions → Add user', 'dazont-ecom' ),
				'role'    => __( 'Restricted', 'dazont-ecom' ),
				'url'     => 'https://search.google.com/search-console',
				'api'     => __( 'Google Search Console API', 'dazont-ecom' ),
				'api_url' => self::console( 'apis/library/searchconsole.googleapis.com' ),
			];
		}
		if ( $on( 'google_ads' ) ) {
			$out[] = [
				'id'      => 'ads',
				'what'    => __( 'Google Ads', 'dazont-ecom' ),
				'where'   => __( 'Admin → Access and security → the « + » button', 'dazont-ecom' ),
				'role'    => __( 'Read only', 'dazont-ecom' ),
				'url'     => 'https://ads.google.com/aw/accountaccess/users',
				'api'     => __( 'Google Ads API', 'dazont-ecom' ),
				'api_url' => self::console( 'apis/library/googleads.googleapis.com' ),
			];
		}
		return $out;
	}

	/** The screen a shop is sent to for the key: the first Google screen that is on. */
	public static function screen_url(): string {
		if ( ! class_exists( 'DZE_Screens' ) ) {
			return '';
		}
		$on = static fn( string $m ): bool => ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( $m );
		if ( $on( 'google_ads' ) ) {
			return DZE_Screens::url( 'ads', 'connection' );
		}
		if ( $on( 'gmc' ) ) {
			return DZE_Screens::url( 'marketing', 'gmc' );
		}
		return $on( 'netlinking' ) ? DZE_Screens::url( 'netlinking', 'console' ) : '';
	}

	/**
	 * THE KEY, ON EVERY SCREEN THAT NEEDS GOOGLE.
	 *
	 * The same block on Merchant Center, Google Ads and Netlinking: without a
	 * key it says how to make one and takes it; with one it shows its address
	 * and every place it must be added. A screen never sends the shop to
	 * another module to paste it — that module may be switched off.
	 */
	public static function block(): string {
		$email = self::email();
		$out   = '<div class="dze-google-key" style="max-width:900px;">';
		if ( '' === $email ) {
			$out .= '<p>' . esc_html__( 'One key for everything this plugin does at Google, and it never expires: no app to publish, no brand to verify, no seven-day disconnection.', 'dazont-ecom' ) . '</p><ol>';
			$out .= '<li>' . wp_kses_post( sprintf(
				/* translators: %s: link to Google Cloud service accounts */
				__( 'Open %s in the project of your Google app. Create a service account: a name is enough, it needs no role in the project.', 'dazont-ecom' ),
				'<a href="' . esc_url( self::console( 'iam-admin/serviceaccounts' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Google Cloud → Service accounts', 'dazont-ecom' ) . ' ↗</a>'
			) ) . '</li>';
			$out .= '<li>' . esc_html__( 'Open the account, then Keys → Add key → Create new key → JSON. A .json file downloads.', 'dazont-ecom' ) . '</li>';
			$out .= '<li>' . esc_html__( 'Paste everything that file holds here, then save. The address to add at Google appears in its place.', 'dazont-ecom' ) . '</li></ol>';
			$out .= self::form( false );
		} else {
			$out .= '<p><span style="color:#0a7040;font-weight:600;">&#10003; ' . esc_html__( 'Google is reached with this service account:', 'dazont-ecom' ) . '</span> '
				. '<code class="dze-google-email">' . esc_html( $email ) . '</code> '
				. '<button type="button" class="button button-small dze-google-copy" data-copy="' . esc_attr( $email ) . '">' . esc_html__( 'Copy the address', 'dazont-ecom' ) . '</button>'
				. ( self::locked() ? ' <em>(' . esc_html__( 'wp-config', 'dazont-ecom' ) . ')</em>' : '' ) . '</p>';
			$places = self::places();
			if ( $places ) {
				$out .= '<p>' . esc_html__( 'Google answers it only where it has been added, like a person, and only through the APIs switched on in its project:', 'dazont-ecom' ) . '</p>';
				$out .= '<table class="widefat striped" style="max-width:900px;"><thead><tr><th>' . esc_html__( 'Google service', 'dazont-ecom' ) . '</th><th>' . esc_html__( 'Add the address as a user', 'dazont-ecom' ) . '</th><th>' . esc_html__( 'Role', 'dazont-ecom' ) . '</th><th>' . esc_html__( 'API to switch on', 'dazont-ecom' ) . '</th></tr></thead><tbody>';
				foreach ( $places as $p ) {
					$out .= '<tr><td><strong>' . esc_html( $p['what'] ) . '</strong></td>'
						. '<td><a href="' . esc_url( $p['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $p['where'] ) . ' ↗</a></td>'
						. '<td>' . esc_html( $p['role'] ) . '</td>'
						. '<td><a href="' . esc_url( $p['api_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $p['api'] ) . ' ↗</a></td></tr>';
				}
				$out .= '</tbody></table>';
				$out .= '<p class="description">' . esc_html__( 'Google never says whether the address has been added: the first reading does.', 'dazont-ecom' ) . '</p>';
			}
			if ( ! self::locked() ) {
				$out .= '<details style="margin-top:8px;"><summary style="cursor:pointer;">' . esc_html__( 'Replace the key', 'dazont-ecom' ) . '</summary>' . self::form( true ) . '</details>';
			}
		}
		return $out . '</div>' . self::script();
	}

	/** The field and its button — no <form>: the block may sit inside another one. */
	private static function form( bool $replace ): string {
		return '<p><textarea class="large-text code dze-google-json" rows="6" spellcheck="false" autocomplete="off" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, &quot;client_email&quot;: &quot;…&quot;, &quot;private_key&quot;: &quot;…&quot; }"></textarea></p>'
			. '<p><button type="button" class="button button-primary dze-google-save" data-nonce="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '">'
			. ( $replace ? esc_html__( 'Save the new key', 'dazont-ecom' ) : esc_html__( 'Save the key', 'dazont-ecom' ) ) . '</button> '
			. '<span class="dze-google-said" role="status" aria-live="polite"></span></p>';
	}

	/** Printed once per page, whatever number of blocks the page holds. */
	private static function script(): string {
		if ( self::$script_printed ) {
			return '';
		}
		self::$script_printed = true;
		$working = esc_js( __( 'Asking Google…', 'dazont-ecom' ) );
		$failed  = esc_js( __( 'No answer — try again.', 'dazont-ecom' ) );
		$copied  = esc_js( __( 'Copied', 'dazont-ecom' ) );
		return '<script>jQuery(function($){'
			. '$(document).on("click",".dze-google-save",function(){'
			. 'var $b=$(this),$box=$b.closest(".dze-google-key"),$s=$b.siblings(".dze-google-said");'
			. 'var key=$b.closest("p").prev("p").find(".dze-google-json").val()||$box.find(".dze-google-json").val();'
			. '$b.prop("disabled",true);$s.text("' . $working . '").css("color","#646970");'
			. '$.post(window.ajaxurl,{action:"dze_google_key",nonce:$b.data("nonce"),key:key})'
			. '.done(function(r){var d=(r&&r.data)||{};'
			. 'if(r&&r.success){$s.text(d.message||"").css("color","#00794b");if(d.reload){setTimeout(function(){window.location.reload();},900);}}'
			. 'else{$b.prop("disabled",false);$s.text(d.message||"' . $failed . '").css("color","#b32d2e");}})'
			. '.fail(function(){$b.prop("disabled",false);$s.text("' . $failed . '").css("color","#b32d2e");});'
			. '});'
			. '$(document).on("click",".dze-google-copy",function(){var $b=$(this);'
			. 'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(String($b.data("copy"))).then(function(){$b.text("' . $copied . '");});}'
			. '});'
			. '});</script>';
	}
}
