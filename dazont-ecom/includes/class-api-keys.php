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

	/** First characters visible, fixed-length mask after (never leaks length). */
	public static function mask( string $key ): string {
		if ( '' === $key ) {
			return '';
		}
		$visible = min( 7, max( 3, (int) floor( strlen( $key ) / 4 ) ) );
		return substr( $key, 0, $visible ) . '••••••••••';
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
