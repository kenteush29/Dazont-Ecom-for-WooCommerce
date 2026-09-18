<?php
defined( 'ABSPATH' ) || exit;

/**
 * API key status + live test, shared by every provider field (Anthropic,
 * fal.ai). Renders a badge that makes a saved key obvious —
 * first characters visible, the rest masked — plus a "Test key" button that
 * checks the SAVED key against the provider with a free/cheap request.
 *
 * The full key is never sent back to the browser: only the masked preview.
 */
final class DZE_Api_Keys {

	private const NONCE = 'dze_test_key';

	/** Ensures the badge helper script is printed once per page. */
	private static bool $script_printed = false;

	public static function init(): void {
		add_action( 'wp_ajax_dze_test_key', [ self::class, 'ajax_test' ] );
	}

	/**
	 * WHICH KEY, WITH WHICH RIGHTS, AND WHERE IT IS MADE.
	 *
	 * "Klaviyo par exemple encore une lacune : je ne sais pas quel type de clé
	 * API il faut — all access ? ou pas ? Et le plugin pourrait largement
	 * inclure un URL qui redirige vers le bon menu Klaviyo. C'est exactement de
	 * ce genre d'attention au détail dont je parle."
	 *
	 * A field asking for a key from somebody else's service says three things
	 * or it sends the owner away to guess: what KIND of key (Klaviyo has
	 * private and public ones, and a private key has scopes), what it must be
	 * allowed to do — read off the calls this plugin actually makes, never
	 * guessed — and the page where it is created, as a link. One function,
	 * printed under every key field, so the four of them read the same way.
	 *
	 * @return array{what:string,url:string,label:string,steps?:array<int,array{0:string,1:string}>}
	 */
	public static function hint( string $provider ): array {
		switch ( $provider ) {
			case 'anthropic':
				return [
					'what'  => __( 'An API key from the Anthropic console. Any key works — there are no permission levels — and every call is billed to that console\'s account.', 'dazont-ecom' ),
					'url'   => 'https://console.anthropic.com/settings/keys',
					'label' => __( 'Create it in the Anthropic console', 'dazont-ecom' ),
				];
			case 'fal':
				return [
					'what'  => __( 'An API key from the fal.ai dashboard. Any key works, and every image is billed to that fal.ai account.', 'dazont-ecom' ),
					'url'   => 'https://fal.ai/dashboard/keys',
					'label' => __( 'Create it in the fal.ai dashboard', 'dazont-ecom' ),
				];
			case 'klaviyo':
				// The scopes are what the plugin CALLS: accounts (the check),
				// campaigns and their messages and send jobs, templates and
				// their renders, tags, segments, images, metric aggregates.
				return [
					'what'  => __( 'A PRIVATE key (it starts with pk_). Simplest: Klaviyo\'s "Full access" preset. A custom key must have read and write on Accounts, Campaigns, Images, Metrics, Segments, Tags and Templates — a read-only key cannot create a campaign.', 'dazont-ecom' ),
					'url'   => 'https://www.klaviyo.com/settings/account/api-keys',
					'label' => __( 'Create it in Klaviyo → Settings → API keys', 'dazont-ecom' ),
				];
			case 'google':
				return [
					'what'  => __( 'An OAuth client of type "Web application" in Google Cloud, with this site\'s redirect URI listed and the app PUBLISHED — in "Testing", Google drops the connection every seven days.', 'dazont-ecom' ),
					'url'   => 'https://console.cloud.google.com/apis/credentials',
					'label' => __( 'Create the OAuth client', 'dazont-ecom' ),
					'steps' => [
						[ __( 'Create the OAuth client', 'dazont-ecom' ), 'https://console.cloud.google.com/apis/credentials' ],
						[ __( 'Publish the app', 'dazont-ecom' ), 'https://console.cloud.google.com/apis/credentials/consent' ],
						[ __( 'Enable the Merchant API', 'dazont-ecom' ), 'https://console.cloud.google.com/apis/library/merchantapi.googleapis.com' ],
					],
				];
		}
		return [ 'what' => '', 'url' => '', 'label' => '' ];
	}

	/**
	 * The hint as it is printed, under the field. '' for a provider it does
	 * not know, so a screen never prints an empty line.
	 */
	public static function hint_html( string $provider ): string {
		$h = self::hint( $provider );
		if ( '' === $h['what'] ) {
			return '';
		}
		$links = [];
		foreach ( (array) ( $h['steps'] ?? [ [ $h['label'], $h['url'] ] ] ) as $i => [ $label, $url ] ) {
			$links[] = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s%3$s ↗</a>',
				esc_url( $url ),
				isset( $h['steps'] ) ? esc_html( (string) ( $i + 1 ) ) . '. ' : '',
				esc_html( $label )
			);
		}
		return '<p class="description dze-key-hint">' . esc_html( $h['what'] ) . ' ' . implode( ' · ', $links ) . '</p>';
	}

	/**
	 * ENOUGH OF THE KEY TO TELL IT FROM THE OTHER TWO.
	 *
	 * Seven characters showed "sk-ant-", which every Anthropic key on earth
	 * starts with: three shops, three keys, one preview. "J'ai une confusion
	 * au niveau organisationnel dans mon compte anthropic, avec 3 clés pour
	 * kula-tactical.com."
	 *
	 * So it wears the shape the provider's own console wears —
	 * `sk-ant-api03-EUk…QQAA` — because the point of a preview is to be
	 * matched against the list on the other screen, and a preview in a
	 * different shape cannot be. The middle is still hidden, and the mask is
	 * a fixed length so it never leaks how long the key is.
	 */
	public static function mask( string $key ): string {
		$key = trim( $key );
		if ( '' === $key ) {
			return '';
		}
		// Short enough that a tail would give away too much of it: old
		// behaviour, head only.
		if ( strlen( $key ) < 28 ) {
			$visible = min( 7, max( 3, (int) floor( strlen( $key ) / 4 ) ) );
			return substr( $key, 0, $visible ) . '••••••••••';
		}
		return substr( $key, 0, 16 ) . '••••••••' . substr( $key, -4 );
	}

	/**
	 * Status badge + test button for a provider ('anthropic'|'fal').
	 * $key is the currently saved key ('' = none), $locked = set via constant.
	 */
	public static function status_html( string $provider, string $key, bool $locked = false ): string {
		$out = '<p class="dze-key-status">';
		if ( '' !== $key ) {
			$out .= '<span class="dze-key-badge is-set" title="' . esc_attr__( 'A key is saved for this provider.', 'dazont-ecom' ) . '">'
				. '&#10003; ' . esc_html__( 'Key saved', 'dazont-ecom' )
				. ' <code>' . esc_html( self::mask( $key ) ) . '</code>'
				. ( $locked ? ' <em>(' . esc_html__( 'wp-config', 'dazont-ecom' ) . ')</em>' : '' )
				. '</span> '
				. '<button type="button" class="button button-small dze-key-test" data-provider="' . esc_attr( $provider ) . '" data-nonce="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '">'
				. esc_html__( 'Test key', 'dazont-ecom' ) . '</button>'
				. ' <span class="dze-key-test-out" role="status" aria-live="polite"></span>';
		} else {
			$out .= '<span class="dze-key-badge is-missing">&#9888; ' . esc_html__( 'No key saved yet', 'dazont-ecom' ) . '</span>';
		}
		$out .= '</p>';

		if ( ! self::$script_printed ) {
			self::$script_printed = true;
			$checking = esc_js( __( 'Checking…', 'dazont-ecom' ) );
			$error    = esc_js( __( 'Test failed — try again.', 'dazont-ecom' ) );
			$out     .= '<script>jQuery(document).on("click",".dze-key-test",function(){'
				. 'var $b=jQuery(this),$o=$b.nextAll(".dze-key-test-out").first();'
				. 'if($b.prop("disabled"))return;$b.prop("disabled",true);'
				. '$o.text(" ' . $checking . '").css("color","#646970");'
				. 'jQuery.post(ajaxurl,{action:"dze_test_key",nonce:$b.data("nonce"),provider:$b.data("provider")})'
				. '.done(function(r){$b.prop("disabled",false);'
				. 'if(r&&r.success){$o.text(" "+r.data.message).css("color","#00794b");}'
				. 'else{$o.text(" "+((r&&r.data&&r.data.message)||"' . $error . '")).css("color","#b32d2e");}})'
				. '.fail(function(){$b.prop("disabled",false);$o.text(" ' . $error . '").css("color","#b32d2e");});'
				. '});</script>';
		}
		return $out;
	}

	/** Tests the SAVED key of a provider with a request that costs nothing. */
	public static function ajax_test(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';

		switch ( $provider ) {
			case 'anthropic':
				$key = class_exists( 'DZE_Marketing_Ai' ) ? DZE_Marketing_Ai::api_key() : '';
				if ( '' === $key ) {
					wp_send_json_error( [ 'message' => __( 'No key saved.', 'dazont-ecom' ) ] );
				}
				$resp = wp_remote_get( 'https://api.anthropic.com/v1/models?limit=1', [
					'timeout' => 15,
					'headers' => [ 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ],
				] );
				self::respond( $resp, [ 200 ], __( 'Anthropic key is valid — model list reachable.', 'dazont-ecom' ) );
				break;

			case 'fal':
				$key = class_exists( 'DZE_Content' ) ? DZE_Content::fal_key() : '';
				if ( '' === $key ) {
					wp_send_json_error( [ 'message' => __( 'No key saved.', 'dazont-ecom' ) ] );
				}
				// Empty payload: a VALID key gets a 4xx validation error (nothing is
				// generated, nothing billed); a bad key gets 401/403.
				$resp = wp_remote_post( 'https://fal.run/fal-ai/nano-banana-2/edit', [
					'timeout' => 15,
					'headers' => [ 'Authorization' => 'Key ' . $key, 'content-type' => 'application/json' ],
					'body'    => '{}',
				] );
				self::respond( $resp, [ 200, 400, 422 ], __( 'fal.ai key is valid — endpoint reachable.', 'dazont-ecom' ), [ 401, 403 ] );
				break;

			case 'klaviyo':
				$key = class_exists( 'DZE_Klaviyo' ) ? DZE_Klaviyo::key() : '';
				if ( '' === $key ) {
					wp_send_json_error( [ 'message' => __( 'No key saved.', 'dazont-ecom' ) ] );
				}
				// The account endpoint costs nothing and answers with the shop's
				// own name, which is the proof worth showing.
				$resp = wp_remote_get( 'https://a.klaviyo.com/api/accounts/', [
					'timeout' => 15,
					'headers' => [
						'Authorization' => 'Klaviyo-API-Key ' . $key,
						'revision'      => '2025-07-15',
						'accept'        => 'application/vnd.api+json',
					],
				] );
				self::respond( $resp, [ 200 ], __( 'Klaviyo key is valid — the account answers.', 'dazont-ecom' ) );
				break;

			default:
				wp_send_json_error( [ 'message' => __( 'Unknown provider.', 'dazont-ecom' ) ] );
		}
	}

	/**
	 * Turns an HTTP response into the test verdict. $ok_codes pass; $bad_codes
	 * (default 401/403) mean an invalid key; anything else is reported as-is.
	 */
	private static function respond( $resp, array $ok_codes, string $ok_message, array $bad_codes = [ 401, 403 ] ): void {
		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( [ 'message' => $resp->get_error_message() ] );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( in_array( $code, $ok_codes, true ) ) {
			wp_send_json_success( [ 'message' => $ok_message ] );
		}
		if ( in_array( $code, $bad_codes, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid key — the provider refused it (HTTP ', 'dazont-ecom' ) . $code . ').' ] );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$msg  = '';
		if ( is_array( $body ) ) {
			$msg = (string) ( $body['error']['message'] ?? ( is_string( $body['detail'] ?? null ) ? $body['detail'] : '' ) );
		}
		wp_send_json_error( [ 'message' => 'HTTP ' . $code . ( $msg ? ' — ' . mb_substr( $msg, 0, 160 ) : '' ) ] );
	}
}
