<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI API usage tracker.
 *
 * Every AI call in the plugin (Anthropic for the marketing calendar and
 * category insights, Google Gemini for product images) records itself here:
 * calls + tokens, bucketed per month per provider. Stored in a single
 * autoload-off option, pruned to 18 months. Rendered as a small bar graph on
 * the Settings page and the Dazont dashboard.
 */
final class DZE_Ai_Usage {

	private const OPT = 'dze_ai_usage';

	/** provider key => human label. */
	public static function providers(): array {
		return [
			'anthropic' => __( 'Anthropic (Claude)', 'dazont-ecom' ),
			'gemini'    => __( 'Google Gemini', 'dazont-ecom' ),
			'fal'       => __( 'fal.ai (images)', 'dazont-ecom' ),
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

	/**
	 * $flat_cost covers providers billed per unit rather than per token
	 * (fal.ai images): it is added to the month's cost as-is.
	 */
	public static function record( string $provider, int $tokens_in = 0, int $tokens_out = 0, string $model = '', float $flat_cost = 0.0 ): void {
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
		$cost                             = ( $tokens_in * $p_in + $tokens_out * $p_out ) / 1000000 + max( 0.0, $flat_cost );
		$data[ $m ][ $provider ]['cost']  = round( (float) ( $data[ $m ][ $provider ]['cost'] ?? 0 ) + $cost, 4 );

		// Same figures broken down by day and by model: a month total says the
		// budget is fine, a daily line says which run cost what.
		$day  = gmdate( 'Y-m-d' );
		$name = $model ?: $provider;
		$d    = (array) ( $data[ $m ]['_days'][ $day ][ $name ] ?? [ 'calls' => 0, 'in' => 0, 'out' => 0, 'cost' => 0.0 ] );
		$d['calls']++;
		$d['in']   += max( 0, $tokens_in );
		$d['out']  += max( 0, $tokens_out );
		$d['cost']  = round( (float) $d['cost'] + $cost, 4 );
		$data[ $m ]['_days'][ $day ][ $name ] = $d;
		krsort( $data );
		$data = array_slice( $data, 0, 18, true ); // keep 18 months max.
		update_option( self::OPT, $data, false );
	}

	/** Estimated spend (USD) recorded for the current month, all providers. */
	public static function month_cost(): float {
		$data = get_option( self::OPT, [] );
		$rows = is_array( $data ) ? ( $data[ gmdate( 'Y-m' ) ] ?? [] ) : [];
		$sum  = 0.0;
		foreach ( (array) $rows as $k => $r ) {
			if ( '_days' === $k ) {
				continue;
			}
			$sum += (float) ( $r['cost'] ?? 0 );
		}
		return $sum;
	}

	/**
	 * True when the monthly AI budget (Settings → General) is set and the
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
			__( 'Monthly AI budget reached (~%s spent). Raise the cap under Settings → General to continue.', 'dazont-ecom' ),
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
		$colors = [ 'anthropic' => '#7c5cff', 'gemini' => '#1a9c6e', 'fal' => '#d63384' ];
		$max    = 1;
		foreach ( $months as $rows ) {
			foreach ( $rows as $k => $r ) {
				if ( '_days' === $k ) {
					continue;
				}
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
				// fal is billed per image, not per token — one call = one image.
				$fmt = 'fal' === $key
					/* translators: 1: number of generated images, 4: estimated cost */
					? __( '%1$s images · ~$%4$s', 'dazont-ecom' )
					/* translators: 1: number of API calls, 2: input tokens, 3: output tokens, 4: estimated cost */
					: __( '%1$s calls · %2$s in / %3$s out tokens · ~$%4$s', 'dazont-ecom' );
				printf(
					'<span style="display:flex;align-items:center;gap:8px;"><span style="width:%1$d%%;max-width:100%%;height:12px;border-radius:3px;background:%2$s;"></span><span style="font-size:11px;color:#646970;white-space:nowrap;">%3$s</span></span>',
					$pct,
					esc_attr( $colors[ $key ] ?? '#999' ),
					esc_html( sprintf(
						$fmt,
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
		self::render_days();
	}

	/**
	 * The current month as a column per day: how much was spent today, and how
	 * today compares with the rest of the month, read in one glance. Days with
	 * nothing are drawn empty so the month reads like a calendar.
	 */
	public static function render_days( string $month = '' ): void {
		$data  = get_option( self::OPT, [] );
		$month = '' !== $month ? $month : gmdate( 'Y-m' );
		$rows  = is_array( $data ) ? (array) ( $data[ $month ]['_days'] ?? [] ) : [];

		$is_now = gmdate( 'Y-m' ) === $month;
		$last   = $is_now ? (int) gmdate( 'j' ) : (int) gmdate( 't', strtotime( $month . '-01' ) );
		$today  = gmdate( 'Y-m-d' );

		$cost  = [];
		$calls = [];
		$tips  = [];
		$max   = 0.0;
		$total = 0.0;
		for ( $d = 1; $d <= $last; $d++ ) {
			$key   = sprintf( '%s-%02d', $month, $d );
			$c     = 0.0;
			$n     = 0;
			$lines = [];
			foreach ( (array) ( $rows[ $key ] ?? [] ) as $name => $r ) {
				$c      += (float) ( $r['cost'] ?? 0 );
				$n      += (int) ( $r['calls'] ?? 0 );
				$lines[] = $name . ': ' . (int) ( $r['calls'] ?? 0 ) . ' calls, $' . number_format( (float) ( $r['cost'] ?? 0 ), 3 );
			}
			$cost[ $d ]  = $c;
			$calls[ $d ] = $n;
			$tips[ $d ]  = $lines ? implode( "\n", $lines ) : '';
			$max         = max( $max, $c );
			$total      += $c;
		}
		if ( $total <= 0 ) {
			echo '<p class="description">' . esc_html__( 'Nothing spent this month yet.', 'dazont-ecom' ) . '</p>';
			return;
		}
		$today_cost = (float) ( $cost[ (int) gmdate( 'j' ) ] ?? 0 );

		echo '<h3 style="margin:22px 0 4px;">' . sprintf(
			/* translators: %s: month, e.g. 2026-08 */
			esc_html__( 'Spend per day — %s', 'dazont-ecom' ),
			esc_html( $month )
		) . '</h3>';
		echo '<p style="margin:0 0 10px;font-size:13px;color:#50575e;">';
		if ( $is_now ) {
			printf(
				/* translators: 1: today's spend, 2: today's call count */
				esc_html__( 'Today: %1$s (%2$s calls)', 'dazont-ecom' ),
				'<strong>$' . esc_html( number_format_i18n( $today_cost, 2 ) ) . '</strong>',
				esc_html( number_format_i18n( (int) ( $calls[ (int) gmdate( 'j' ) ] ?? 0 ) ) )
			);
			echo ' &nbsp;·&nbsp; ';
		}
		printf(
			/* translators: %s: month total */
			esc_html__( 'This month: %s', 'dazont-ecom' ),
			'<strong>$' . esc_html( number_format_i18n( $total, 2 ) ) . '</strong>'
		);
		echo '</p>';

		// Columns. Height carries the cost, the tooltip carries the models.
		echo '<div style="display:flex;align-items:flex-end;gap:3px;height:120px;max-width:760px;border-bottom:1px solid #dcdcde;padding-bottom:2px;">';
		for ( $d = 1; $d <= $last; $d++ ) {
			$c   = (float) $cost[ $d ];
			$h   = $max > 0 ? max( 2, (int) round( 110 * $c / $max ) ) : 2;
			$key = sprintf( '%s-%02d', $month, $d );
			$is_today = $key === $today;
			$tip = $key . ( $c > 0 ? ' — $' . number_format( $c, 3 ) . ( $tips[ $d ] ? "\n" . $tips[ $d ] : '' ) : ' — ' . __( 'nothing', 'dazont-ecom' ) );
			printf(
				'<div title="%1$s" style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%%;"><span style="height:%2$dpx;border-radius:3px 3px 0 0;background:%3$s;display:block;"></span></div>',
				esc_attr( $tip ),
				$h,
				$c > 0 ? ( $is_today ? '#0a7040' : '#7c5cff' ) : '#f0f0f1'
			);
		}
		echo '</div>';
		// Day numbers, every other one when the month is long.
		echo '<div style="display:flex;gap:3px;max-width:760px;margin-bottom:14px;">';
		for ( $d = 1; $d <= $last; $d++ ) {
			printf(
				'<span style="flex:1;text-align:center;font-size:9px;color:%2$s;">%1$s</span>',
				esc_html( ( 1 === $d % 2 || $d === $last ) ? (string) $d : '' ),
				esc_attr( sprintf( '%s-%02d', $month, $d ) === $today ? '#0a7040' : '#a7aaad' )
			);
		}
		echo '</div>';

		// The same figures in full, for whoever wants the detail.
		echo '<details style="max-width:760px;"><summary style="cursor:pointer;color:#2271b1;">'
			. esc_html__( 'Detail per day and per model', 'dazont-ecom' ) . '</summary>';
		echo '<table class="wp-list-table widefat striped" style="margin-top:8px;"><thead><tr>'
			. '<th>' . esc_html__( 'Day', 'dazont-ecom' ) . '</th>'
			. '<th>' . esc_html__( 'Model', 'dazont-ecom' ) . '</th>'
			. '<th style="width:12%;">' . esc_html__( 'Calls', 'dazont-ecom' ) . '</th>'
			. '<th style="width:22%;">' . esc_html__( 'Tokens in / out', 'dazont-ecom' ) . '</th>'
			. '<th style="width:12%;">' . esc_html__( 'Cost', 'dazont-ecom' ) . '</th>'
			. '</tr></thead><tbody>';
		krsort( $rows );
		foreach ( $rows as $day => $models ) {
			$first = true;
			foreach ( (array) $models as $name => $r ) {
				echo '<tr>';
				echo '<td>' . ( $first ? '<strong>' . esc_html( (string) $day ) . '</strong>' : '' ) . '</td>';
				echo '<td><code style="font-size:11px;">' . esc_html( (string) $name ) . '</code></td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( $r['calls'] ?? 0 ) ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( $r['in'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $r['out'] ?? 0 ) ) ) . '</td>';
				echo '<td>$' . esc_html( number_format_i18n( (float) ( $r['cost'] ?? 0 ), 3 ) ) . '</td>';
				echo '</tr>';
				$first = false;
			}
		}
		echo '</tbody></table></details>';
	}
}
