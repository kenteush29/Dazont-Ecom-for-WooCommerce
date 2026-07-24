<?php
defined( 'ABSPATH' ) || exit;

final class AICS_Api_Client {

	private const ENDPOINT        = 'https://api.anthropic.com/v1/messages';
	private const MODELS_ENDPOINT = 'https://api.anthropic.com/v1/models';
	private const API_VERSION     = '2023-06-01';
	private const TIMEOUT         = 60;

	private array $retry_config = [ 'max_retries' => 3, 'base_delay' => 2.0 ];

	public function __construct(
		private readonly string $api_key,
		private readonly string $default_model = 'claude-haiku-4-5',
		private readonly int    $max_tokens    = 2000,
	) {}

	public function generate(
		string $user_prompt,
		string $system_prompt = '',
		?string $model = null,
		array $log_meta = []
	): array {
		if ( empty( $this->api_key ) ) {
			throw new RuntimeException( __( 'Anthropic API key is not configured.', 'ai-content-suite' ) );
		}
		$model = $model ?? $this->default_model;
		$body  = [
			'model'      => $model,
			'max_tokens' => $this->max_tokens,
			'messages'   => [ [ 'role' => 'user', 'content' => $user_prompt ] ],
		];
		if ( ! empty( $system_prompt ) ) {
			$body['system'] = $system_prompt;
		}
		$result = $this->request_with_retry( $body );
		$text   = '';
		foreach ( $result['content'] ?? [] as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$text .= $block['text'];
			}
		}
		$usage = $result['usage'] ?? [];
		AICS_Logger::instance()->log( array_merge( [
			'model'      => $model,
			'tokens_in'  => $usage['input_tokens'] ?? 0,
			'tokens_out' => $usage['output_tokens'] ?? 0,
			'cost_usd'   => $this->estimate_cost( $model, $usage ),
			'status'     => 'success',
			'message'    => '',
		], $log_meta ) );
		return [ 'text' => $text, 'usage' => $usage, 'model' => $result['model'] ?? $model ];
	}

	private function request_with_retry( array $body ): array {
		$delay = $this->retry_config['base_delay'];
		$attempt = 0;
		while ( true ) {
			$response = wp_remote_post( self::ENDPOINT, [
				'timeout' => self::TIMEOUT,
				'headers' => [
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				],
				'body' => wp_json_encode( $body ),
			] );
			if ( is_wp_error( $response ) ) {
				$this->log_error( 'timeout', $response->get_error_message(), $body );
				throw new RuntimeException(
					sprintf( __( 'API request failed: %s', 'ai-content-suite' ), $response->get_error_message() )
				);
			}
			$code = wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( $code === 200 ) {
				return $data;
			}
			if ( $code === 429 && $attempt < $this->retry_config['max_retries'] ) {
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				sleep( $retry_after > 0 ? $retry_after : (int) ceil( $delay ) );
				$delay *= 2;
				$attempt++;
				continue;
			}
			$error_msg = $data['error']['message'] ?? "HTTP $code";
			$this->log_error( (string) $code, $error_msg, $body );
			throw new RuntimeException(
				sprintf( __( 'Claude API error %1$s: %2$s', 'ai-content-suite' ), $code, $error_msg )
			);
		}
	}

	public function list_models(): array {
		if ( empty( $this->api_key ) ) { return []; }
		$response = wp_remote_get( self::MODELS_ENDPOINT, [
			'timeout' => 15,
			'headers' => [ 'x-api-key' => $this->api_key, 'anthropic-version' => self::API_VERSION ],
		] );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return [];
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$ids  = [];
		foreach ( $data['data'] ?? [] as $model ) {
			if ( ! empty( $model['id'] ) ) { $ids[] = $model['id']; }
		}
		return $ids;
	}

	private function estimate_cost( string $model, array $usage ): float {
		$pricing = [
			'claude-haiku-4-5'  => [ 'in' => 0.80,  'out' => 4.00  ],
			'claude-sonnet-4-6' => [ 'in' => 3.00,  'out' => 15.00 ],
			'claude-opus-4-8'   => [ 'in' => 15.00, 'out' => 75.00 ],
		];
		$p    = $pricing[ $model ] ?? [ 'in' => 3.0, 'out' => 15.0 ];
		$cost = ( ( $usage['input_tokens'] ?? 0 ) / 1_000_000 ) * $p['in']
			  + ( ( $usage['output_tokens'] ?? 0 ) / 1_000_000 ) * $p['out'];
		return round( $cost, 6 );
	}

	private function log_error( string $code, string $message, array $body ): void {
		AICS_Logger::instance()->log( [
			'model' => $body['model'] ?? '', 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0,
			'status' => "error_$code", 'message' => $message,
		] );
	}
}
