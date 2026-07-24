<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI API usage tracker.
 *
 * Every AI call in the plugin (Anthropic for the marketing calendar and
 * category insights, Google Gemini for product images) records itself here:
 * calls + tokens, bucketed per month per provider. Stored in a single
 * autoload-off option, pruned to 18 months. Rendered as a small bar graph on
 * the AI Settings page and the Dazont dashboard.
 */
final class DZE_Ai_Usage {

	private const OPT = 'dze_ai_usage';

	/** provider key => human label. */
	public static function providers(): array {
		return [
			'anthropic' => __( 'Anthropic (Claude)', 'dazont-ecom' ),
			'gemini'    => __( 'Google Gemini', 'dazont-ecom' ),
		];
	}

	/**
	 * Rough $ / million tokens (input, output) per model family — only used for
	 * the budget guard and the usage graph, not for billing.
	 */
	private static function price( string $model ): array {
		$m = strtolower( $model );
		if ( strpos( $m, 'haiku' ) !== false ) {
			return [ 1.0, 5.0 ];
		}
		if ( strpos( $m, 'sonnet' ) !== false ) {
			return [ 3.0, 15.0 ];
		}
		if ( strpos( $m, 'opus' ) !== false || strpos( $m, 'fable' ) !== false ) {
			return [ 15.0, 75.0 ];
		}
		if ( strpos( $m, 'gemini' ) !== false ) {
			return [ 0.3, 30.0 ]; // image output tokens are expensive.
		}
		return [ 3.0, 15.0 ];
	}

	public static function record( string $provider, int $tokens_in = 0, int $tokens_out = 0, string $model = '' ): void {
		$data = get_option( self::OPT, [] );
		$data = is_array( $data ) ? $data : [];
		$m    = gmdate( 'Y-m' );
		if ( ! isset( $data[ $m ][ $provider ] ) ) {
			$data[ $m ][ $provider ] = [ 'calls' => 0, 'in' => 0, 'out' => 0, 'cost' => 0.0 ];
		}
		[ $p_in, $p_out ]              = self::price( $model ?: $provider );
		$data[ $m ][ $provider ]['calls']++;
		$data[ $m ][ $provider ]['in']   += max( 0, $tokens_in );
		$data[ $m ][ $provider ]['out']  += max( 0, $tokens_out );
		$data[ $m ][ $provider ]['cost']  = round( (float) ( $data[ $m ][ $provider ]['cost'] ?? 0 ) + ( $tokens_in * $p_in + $tokens_out * $p_out ) / 1000000, 4 );
		krsort( $data );
		$data = array_slice( $data, 0, 18, true ); // keep 18 months max.
		update_option( self::OPT, $data, false );
	}

	/** Estimated spend (USD) recorded for the current month, all providers. */
	public static function month_cost(): float {
		$data = get_option( self::OPT, [] );
		$rows = is_array( $data ) ? ( $data[ gmdate( 'Y-m' ) ] ?? [] ) : [];
		$sum  = 0.0;
		foreach ( (array) $rows as $r ) {
			$sum += (float) ( $r['cost'] ?? 0 );
		}
		return $sum;
	}

	/**
	 * True when the monthly AI budget (AI Settings → General) is set and the
	 * estimated month spend reached it. Every AI call site checks this first.
	 */
	public static function over_budget(): bool {
		$cap = 0.0;
		if ( class_exists( 'DZE_Marketing_Ai' ) ) {
			$cap = (float) ( DZE_Marketing_Ai::get_settings()['budget_month'] ?? 0 );
		}
		return $cap > 0 && self::month_cost() >= $cap;
	}

	/** Standard error message for a blocked call. */
	public static function budget_message(): string {
		return sprintf(
			/* translators: %s: estimated spend this month */
			__( 'Monthly AI budget reached (~%s spent). Raise the cap under AI Settings → General to continue.', 'dazont-ecom' ),
			'$' . number_format_i18n( self::month_cost(), 2 )
		);
	}

	/** Last N months, oldest first: [ 'YYYY-MM' => [ provider => {calls,in,out} ] ]. */
	public static function months( int $limit = 12 ): array {
		$data = get_option( self::OPT, [] );
		$data = is_array( $data ) ? $data : [];
		krsort( $data );
		$data = array_slice( $data, 0, $limit, true );
		ksort( $data );
		return $data;
	}

	/** Small dependency-free horizontal bar graph (calls per month per provider). */
	public static function render_graph( int $limit = 12 ): void {
		$months = self::months( $limit );
		if ( empty( $months ) ) {
			echo '<p class="description">' . esc_html__( 'No AI calls recorded yet. Usage will appear here as soon as a feature calls its API.', 'dazont-ecom' ) . '</p>';
			return;
		}
		$colors = [ 'anthropic' => '#7c5cff', 'gemini' => '#1a9c6e' ];
		$max    = 1;
		foreach ( $months as $rows ) {
			foreach ( $rows as $r ) {
				$max = max( $max, (int) ( $r['calls'] ?? 0 ) );
			}
		}
		echo '<div style="max-width:720px;">';
		// Legend.
		echo '<p style="margin:0 0 8px;">';
		foreach ( self::providers() as $key => $label ) {
			printf(
				'<span style="display:inline-flex;align-items:center;gap:6px;margin-right:16px;font-size:12px;color:#50575e;"><span style="width:10px;height:10px;border-radius:3px;background:%1$s;display:inline-block;"></span>%2$s</span>',
				esc_attr( $colors[ $key ] ?? '#999' ),
				esc_html( $label )
			);
		}
		echo '</p>';
		foreach ( $months as $month => $rows ) {
			echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">';
			echo '<span style="flex:0 0 64px;font-size:12px;color:#50575e;font-variant-numeric:tabular-nums;">' . esc_html( $month ) . '</span>';
			echo '<span style="flex:1;display:flex;flex-direction:column;gap:3px;">';
			foreach ( self::providers() as $key => $label ) {
				$r = $rows[ $key ] ?? null;
				if ( ! $r ) {
					continue;
				}
				$calls = (int) ( $r['calls'] ?? 0 );
				$pct   = max( 2, (int) round( 100 * $calls / $max ) );
				printf(
					'<span style="display:flex;align-items:center;gap:8px;"><span style="width:%1$d%%;max-width:100%%;height:12px;border-radius:3px;background:%2$s;"></span><span style="font-size:11px;color:#646970;white-space:nowrap;">%3$s</span></span>',
					$pct,
					esc_attr( $colors[ $key ] ?? '#999' ),
					esc_html( sprintf(
						/* translators: 1: number of API calls, 2: input tokens, 3: output tokens, 4: estimated cost */
						__( '%1$s calls · %2$s in / %3$s out tokens · ~$%4$s', 'dazont-ecom' ),
						number_format_i18n( $calls ),
						number_format_i18n( (int) ( $r['in'] ?? 0 ) ),
						number_format_i18n( (int) ( $r['out'] ?? 0 ) ),
						number_format_i18n( (float) ( $r['cost'] ?? 0 ), 2 )
					) )
				);
			}
			echo '</span></div>';
		}
		echo '</div>';
	}
}
