<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI API usage tracker.
 *
 * Every AI call in the plugin records itself here — Anthropic for the writing,
 * fal.ai for the images: calls and tokens, bucketed per month per provider and
 * per day per model. Stored in a single autoload-off option, pruned to 18
 * months, and rendered as a summary, a day-by-day chart for the month being
 * looked at, and the months behind it.
 */
final class DZE_Ai_Usage {

	private const OPT = 'dze_ai_usage';

	/** provider key => human label. */
	public static function providers(): array {
		return [
			'anthropic' => __( 'Anthropic (Claude)', 'dazont-ecom' ),
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
		return [ 3.0, 15.0 ];
	}

	/**
	 * $flat_cost covers providers billed per unit rather than per token
	 * (fal.ai images): it is added to the month's cost as-is.
	 */
	/**
	 * What the current work IS, so a cost can be attributed to a job and not
	 * only to a provider.
	 *
	 * "$18 on Anthropic this month" does not tell you whether a category
	 * description is worth writing. "A category description costs $0.34" does.
	 * A module names its unit before it calls, and clears it after.
	 *
	 * @var string
	 */
	private static string $unit = '';

	/** Names the unit of work about to be charged ('' clears it). */
	public static function unit( string $unit = '' ): void {
		self::$unit = sanitize_key( $unit );
	}

	// =========================================================================
	// The trace: the last AI calls, wording included
	// =========================================================================

	/** Where the last calls are kept. Never autoloaded — read on one screen. */
	private const TRACE = 'dze_ai_trace';

	/** How many calls the trace holds before the oldest rolls off. */
	private const TRACE_KEEP = 12;

	/**
	 * One AI call, written down whole: which tool asked, on which model, WHAT
	 * WAS SENT and WHAT CAME BACK, and how long it took.
	 *
	 * This is the debugging the owner actually asked for, in his words: "je ne
	 * sais pas exactement ce qui se passe côté code". Every text call goes
	 * through complete() and every image through fal_generate(), so writing it
	 * down there covers every AI feature of the plugin at once — the emails,
	 * the pictures, the plans, the translations, the diagnostics to come. A
	 * failure is written down too, with the error where the answer would be,
	 * because the call that FAILED is the one worth reading.
	 */
	public static function trace( string $provider, string $model, string $sent, string $got, float $secs ): void {
		$rows   = get_option( self::TRACE, [] );
		$rows   = is_array( $rows ) ? $rows : [];
		$rows[] = [
			't'        => time(),
			'unit'     => '' !== self::$unit ? self::$unit : 'other',
			'provider' => sanitize_key( $provider ),
			'model'    => sanitize_text_field( $model ),
			'secs'     => round( max( 0, $secs ), 1 ),
			// Whole enough to read, bounded enough to store: a prompt is a few
			// KB of text; reference photographs travel as base64 and would be
			// megabytes of noise, so callers describe them in a line instead.
			'sent'     => mb_substr( $sent, 0, 20000 ),
			'got'      => mb_substr( $got, 0, 10000 ),
		];
		update_option( self::TRACE, array_slice( $rows, -self::TRACE_KEEP ), false );
	}

	/** The last calls, newest first. @return array[] */
	public static function trace_rows(): array {
		$rows = get_option( self::TRACE, [] );
		return array_reverse( is_array( $rows ) ? $rows : [] );
	}

	/**
	 * The trace, on screen: one line per call, the whole exchange behind a
	 * click. Everything the model was told and everything it answered, for
	 * the last dozen calls, whatever tool made them.
	 */
	public static function render_trace(): void {
		$rows = self::trace_rows();
		echo '<p class="description" style="max-width:880px;">'
			. esc_html__( 'The last calls this plugin made to a model — what was sent, what came back, how long it took. When a result is wrong (a preview too long, an invented detail on a picture), the cause is in here: open the call, read what was actually asked.', 'dazont-ecom' )
			. '</p>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No call recorded yet — the trace fills as the tools are used.', 'dazont-ecom' ) . '</p>';
			return;
		}
		$units = self::units();
		foreach ( $rows as $row ) {
			$unit = (string) ( $units[ (string) ( $row['unit'] ?? '' ) ] ?? $row['unit'] ?? '' );
			$ko   = 0 === strpos( (string) ( $row['got'] ?? '' ), 'ERROR' );
			printf(
				'<details style="max-width:1100px;margin:0 0 6px;border:1px solid %5$s;border-radius:4px;background:#fff;">'
				. '<summary style="cursor:pointer;padding:8px 12px;font-size:13px;%6$s">%1$s · <strong>%2$s</strong> · %3$s · %4$s</summary>',
				esc_html( human_time_diff( (int) ( $row['t'] ?? 0 ), time() ) . ' ' . __( 'ago', 'dazont-ecom' ) ),
				esc_html( $unit ),
				esc_html( (string) ( $row['model'] ?? '' ) ),
				esc_html( ( (float) ( $row['secs'] ?? 0 ) ) . ' s' ),
				$ko ? '#b32d2e' : '#dcdcde',
				$ko ? 'color:#b32d2e;' : ''
			);
			echo '<div style="padding:0 12px 10px;">';
			echo '<p style="margin:6px 0 4px;font-weight:600;font-size:12px;">' . esc_html__( 'Sent', 'dazont-ecom' ) . '</p>';
			echo '<pre style="max-height:320px;overflow:auto;white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:10px;font-size:12px;line-height:1.5;margin:0;">' . esc_html( (string) ( $row['sent'] ?? '' ) ) . '</pre>';
			echo '<p style="margin:10px 0 4px;font-weight:600;font-size:12px;">' . esc_html__( 'Answer', 'dazont-ecom' ) . '</p>';
			echo '<pre style="max-height:240px;overflow:auto;white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:10px;font-size:12px;line-height:1.5;margin:0;">' . esc_html( (string) ( $row['got'] ?? '' ) ) . '</pre>';
			echo '</div></details>';
		}
	}

	/** Human labels for the units the plugin charges to. */
	public static function units(): array {
		return [
			'cat_desc'    => __( 'Category description', 'dazont-ecom' ),
			'cat_links'   => __( 'Internal linking pass', 'dazont-ecom' ),
			'cat_sift'    => __( 'Buyer-question sifting', 'dazont-ecom' ),
			'product_text'=> __( 'Product texts (one run)', 'dazont-ecom' ),
			'product_img' => __( 'Product image', 'dazont-ecom' ),
			'feature_pick'=> __( 'Choosing the photograph of a block', 'dazont-ecom' ),
			'translate'   => __( 'Product translation (one language)', 'dazont-ecom' ),
			'calendar'    => __( 'Marketing calendar', 'dazont-ecom' ),
			'promo_i18n'  => __( 'Promotion translations (one event)', 'dazont-ecom' ),
			'promo_plan'  => __( 'Promotion campaign plan', 'dazont-ecom' ),
			'promo_email' => __( 'Promotion email', 'dazont-ecom' ),
			'promo_email_img' => __( 'Promotion email picture', 'dazont-ecom' ),
			'hero_image'  => __( 'Home page picture for an event', 'dazont-ecom' ),
			'prompt_draft'=> __( 'Drafting a prompt', 'dazont-ecom' ),
			'sourcing'    => __( 'Sourcing analysis', 'dazont-ecom' ),
			'other'       => __( 'Everything else', 'dazont-ecom' ),
		];
	}

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

		// Per unit of work: how many were made, and what they cost together.
		// Averaging those two is the answer to "what does one of these cost".
		$unit = self::$unit ?: 'other';
		$u    = (array) ( $data[ $m ]['_units'][ $unit ] ?? [ 'calls' => 0, 'runs' => 0, 'cost' => 0.0 ] );
		$u['calls']++;
		$u['cost'] = round( (float) $u['cost'] + $cost, 4 );
		$data[ $m ]['_units'][ $unit ] = $u;

		krsort( $data );
		$data = array_slice( $data, 0, 18, true ); // keep 18 months max.
		update_option( self::OPT, $data, false );
	}

	/**
	 * Counts one FINISHED unit of work, whatever number of calls it took.
	 *
	 * A category description is eight calls and one description; dividing its
	 * cost by the number of calls would answer a question nobody asked.
	 */
	public static function finished( string $unit, int $n = 1 ): void {
		$unit = sanitize_key( $unit );
		if ( '' === $unit ) {
			return;
		}
		$data = get_option( self::OPT, [] );
		$data = is_array( $data ) ? $data : [];
		$m    = gmdate( 'Y-m' );
		$u    = (array) ( $data[ $m ]['_units'][ $unit ] ?? [ 'calls' => 0, 'runs' => 0, 'cost' => 0.0 ] );
		$u['runs'] = (int) ( $u['runs'] ?? 0 ) + max( 1, $n );
		$data[ $m ]['_units'][ $unit ] = $u;
		update_option( self::OPT, $data, false );
	}

	/**
	 * What one of each thing costs this month.
	 *
	 * @return array<int,array{unit:string,label:string,runs:int,calls:int,cost:float,each:float}>
	 */
	public static function unit_report( string $month = '' ): array {
		$data  = get_option( self::OPT, [] );
		$month = $month ?: gmdate( 'Y-m' );
		$rows  = (array) ( $data[ $month ]['_units'] ?? [] );
		$labels = self::units();
		$out   = [];
		foreach ( $rows as $unit => $r ) {
			$runs  = (int) ( $r['runs'] ?? 0 );
			$calls = (int) ( $r['calls'] ?? 0 );
			$cost  = (float) ( $r['cost'] ?? 0 );
			// No finished run recorded (a one-call unit): the calls ARE the runs.
			$div   = $runs ?: $calls;
			$out[] = [
				'unit'  => (string) $unit,
				'label' => (string) ( $labels[ $unit ] ?? $unit ),
				'runs'  => $div,
				'calls' => $calls,
				'cost'  => round( $cost, 4 ),
				'each'  => $div ? round( $cost / $div, 4 ) : 0.0,
			];
		}
		usort( $out, static fn( $a, $b ) => $b['cost'] <=> $a['cost'] );
		return $out;
	}

	/** Estimated spend (USD) recorded for the current month, all providers. */
	public static function month_cost(): float {
		$data = get_option( self::OPT, [] );
		$rows = is_array( $data ) ? ( $data[ gmdate( 'Y-m' ) ] ?? [] ) : [];
		$sum  = 0.0;
		foreach ( (array) $rows as $k => $r ) {
			// The breakdown buckets hold the SAME money as the provider rows.
			if ( '_days' === $k || '_units' === $k ) {
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

	/** Cost of one month, all providers ('' = current month). */
	public static function month_total( string $month = '' ): float {
		$data  = get_option( self::OPT, [] );
		$month = '' !== $month ? $month : gmdate( 'Y-m' );
		$sum   = 0.0;
		foreach ( (array) ( is_array( $data ) ? ( $data[ $month ] ?? [] ) : [] ) as $k => $r ) {
			// The breakdown buckets hold the SAME money as the provider rows.
			if ( '_days' === $k || '_units' === $k ) {
				continue;
			}
			$sum += (float) ( $r['cost'] ?? 0 );
		}
		return $sum;
	}

	/** Cost of one day, all models. */
	public static function day_total( string $day = '' ): float {
		$day   = '' !== $day ? $day : gmdate( 'Y-m-d' );
		$data  = get_option( self::OPT, [] );
		$rows  = is_array( $data ) ? (array) ( $data[ substr( $day, 0, 7 ) ]['_days'][ $day ] ?? [] ) : [];
		$sum   = 0.0;
		foreach ( $rows as $r ) {
			$sum += (float) ( $r['cost'] ?? 0 );
		}
		return $sum;
	}

	/**
	 * The whole picture, in the order it is asked for: what today cost, what
	 * the month costs so far, then the month day by day, then the months
	 * behind it. $limit is how many months the history keeps.
	 */
	public static function render_graph( int $limit = 12 ): void {
		$data = get_option( self::OPT, [] );
		if ( ! is_array( $data ) || ! $data ) {
			echo '<p class="description">' . esc_html__( 'No AI call recorded yet. Usage appears here as soon as a feature calls its API.', 'dazont-ecom' ) . '</p>';
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only month navigation.
		$month = isset( $_GET['dze_month'] ) ? sanitize_text_field( wp_unslash( $_GET['dze_month'] ) ) : gmdate( 'Y-m' );
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$month = gmdate( 'Y-m' );
		}
		self::render_summary();
		self::render_units( $month );
		self::render_days( $month );
		self::render_months( $limit );
	}

	/**
	 * What one of each thing costs.
	 *
	 * The month total tells you whether the budget holds; this tells you
	 * whether a job is worth doing — the only figure that answers "should I
	 * rewrite two hundred categories".
	 */
	public static function render_units( string $month = '' ): void {
		$rows = self::unit_report( $month );
		if ( ! $rows ) {
			return;
		}
		echo '<h3 style="margin:22px 0 6px;">' . esc_html__( 'What one costs', 'dazont-ecom' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:760px;"><thead><tr>'
			. '<th>' . esc_html__( 'Unit of work', 'dazont-ecom' ) . '</th>'
			. '<th style="width:110px;text-align:right;">' . esc_html__( 'Each', 'dazont-ecom' ) . '</th>'
			. '<th style="width:90px;text-align:right;">' . esc_html__( 'Done', 'dazont-ecom' ) . '</th>'
			. '<th style="width:110px;text-align:right;">' . esc_html__( 'Total', 'dazont-ecom' ) . '</th>'
			. '<th style="width:90px;text-align:right;">' . esc_html__( 'Calls', 'dazont-ecom' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>%1$s</td><td style="text-align:right;"><strong>$%2$s</strong></td>'
					. '<td style="text-align:right;">%3$s</td><td style="text-align:right;">$%4$s</td>'
					. '<td style="text-align:right;color:#646970;">%5$s</td></tr>',
				esc_html( $r['label'] ),
				esc_html( number_format( $r['each'], $r['each'] < 0.01 ? 4 : 3 ) ),
				esc_html( number_format_i18n( $r['runs'] ) ),
				esc_html( number_format( $r['cost'], 2 ) ),
				esc_html( number_format_i18n( $r['calls'] ) )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description" style="max-width:760px;">'
			. esc_html__( 'Average over what was actually produced this month, at published token prices. A category description is one unit however many calls it takes; an image is one unit. "Calls" is there to show where a unit is expensive because it is long, rather than because it is frequent.', 'dazont-ecom' )
			. '</p>';
	}

	/** Today, this month, last month, and what is left of the budget. */
	public static function render_summary(): void {
		$today = self::day_total();
		$now   = self::month_total();
		$prev  = self::month_total( gmdate( 'Y-m', strtotime( 'first day of last month' ) ) );
		$cap   = class_exists( 'DZE_Marketing_Ai' ) ? (float) ( DZE_Marketing_Ai::get_settings()['budget_month'] ?? 0 ) : 0.0;

		$cards = [
			[ __( 'Today', 'dazont-ecom' ), '$' . number_format_i18n( $today, 2 ), '#0a7040' ],
			[ __( 'This month', 'dazont-ecom' ), '$' . number_format_i18n( $now, 2 ), '#2271b1' ],
			[ __( 'Last month', 'dazont-ecom' ), '$' . number_format_i18n( $prev, 2 ), '#646970' ],
		];
		if ( $cap > 0 ) {
			$left    = max( 0, $cap - $now );
			$cards[] = [
				__( 'Left this month', 'dazont-ecom' ),
				'$' . number_format_i18n( $left, 2 ),
				$left > 0 ? '#8a6d00' : '#b32d2e',
			];
		}
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:0 0 18px;">';
		foreach ( $cards as $c ) {
			printf(
				'<div style="flex:0 0 150px;border:1px solid #e2e4e7;border-radius:8px;padding:10px 14px;background:#fff;">
					<div style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#646970;">%1$s</div>
					<div style="font-size:20px;font-weight:600;color:%3$s;margin-top:2px;">%2$s</div>
				</div>',
				esc_html( $c[0] ),
				esc_html( $c[1] ),
				esc_attr( $c[2] )
			);
		}
		echo '</div>';
	}

	/**
	 * One column per day of the month being looked at, today in green, empty
	 * days drawn flat so the month reads like a calendar. Hovering a column
	 * gives that day's models with their calls and cost.
	 */
	public static function render_days( string $month = '' ): void {
		$data  = get_option( self::OPT, [] );
		$month = '' !== $month ? $month : gmdate( 'Y-m' );
		$rows  = is_array( $data ) ? (array) ( $data[ $month ]['_days'] ?? [] ) : [];
		$total = self::month_total( $month );

		$is_now = gmdate( 'Y-m' ) === $month;
		$last   = $is_now ? (int) gmdate( 'j' ) : (int) gmdate( 't', (int) strtotime( $month . '-01' ) );
		$today  = gmdate( 'Y-m-d' );

		// Month navigation, only towards months that hold something.
		$known = array_values( array_filter( array_keys( $data ), static fn( $k ) => preg_match( '/^\d{4}-\d{2}$/', (string) $k ) ) );
		sort( $known );
		$pos  = array_search( $month, $known, true );
		$base = remove_query_arg( 'dze_month' );
		echo '<h3 style="margin:0 0 8px;display:flex;align-items:center;gap:10px;">';
		echo esc_html( sprintf( /* translators: %s: month */ __( 'Day by day — %s', 'dazont-ecom' ), $month ) );
		if ( false !== $pos && $pos > 0 ) {
			echo ' <a class="button button-small" href="' . esc_url( add_query_arg( 'dze_month', $known[ $pos - 1 ], $base ) ) . '">&laquo; ' . esc_html( $known[ $pos - 1 ] ) . '</a>';
		}
		if ( false !== $pos && $pos < count( $known ) - 1 ) {
			echo ' <a class="button button-small" href="' . esc_url( add_query_arg( 'dze_month', $known[ $pos + 1 ], $base ) ) . '">' . esc_html( $known[ $pos + 1 ] ) . ' &raquo;</a>';
		}
		echo '</h3>';

		if ( ! $rows ) {
			// The month may well have cost money — the DAILY split simply was
			// not recorded before the plugin started keeping it.
			echo '<p class="description" style="margin-bottom:18px;">' . (
				$total > 0
					? sprintf(
						/* translators: %s: month total */
						esc_html__( 'This month cost %s, but it was recorded before the day-by-day breakdown existed, so there is nothing to plot. New calls appear here from now on.', 'dazont-ecom' ),
						'<strong>$' . esc_html( number_format_i18n( $total, 2 ) ) . '</strong>'
					)
					: esc_html__( 'Nothing spent in this month.', 'dazont-ecom' )
			) . '</p>';
			return;
		}

		$cost = [];
		$max  = 0.0;
		$tips = [];
		for ( $d = 1; $d <= $last; $d++ ) {
			$key   = sprintf( '%s-%02d', $month, $d );
			$c     = 0.0;
			$lines = [];
			foreach ( (array) ( $rows[ $key ] ?? [] ) as $name => $r ) {
				$c      += (float) ( $r['cost'] ?? 0 );
				$lines[] = $name . ': ' . (int) ( $r['calls'] ?? 0 ) . ' calls, $' . number_format( (float) ( $r['cost'] ?? 0 ), 3 );
			}
			$cost[ $d ] = $c;
			$tips[ $d ] = $lines ? implode( "\n", $lines ) : '';
			$max        = max( $max, $c );
		}

		echo '<div style="display:flex;align-items:flex-end;gap:3px;height:130px;max-width:820px;border-bottom:1px solid #dcdcde;padding-bottom:2px;">';
		for ( $d = 1; $d <= $last; $d++ ) {
			$c        = (float) $cost[ $d ];
			$h        = $max > 0 ? max( 2, (int) round( 118 * $c / $max ) ) : 2;
			$key      = sprintf( '%s-%02d', $month, $d );
			$is_today = $key === $today;
			$tip      = $key . ( $c > 0 ? ' — $' . number_format( $c, 3 ) . ( $tips[ $d ] ? "\n" . $tips[ $d ] : '' ) : ' — ' . __( 'nothing', 'dazont-ecom' ) );
			printf(
				'<div title="%1$s" style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%%;"><span style="height:%2$dpx;border-radius:3px 3px 0 0;background:%3$s;display:block;"></span></div>',
				esc_attr( $tip ),
				$h,
				$c > 0 ? ( $is_today ? '#0a7040' : '#7c5cff' ) : '#f0f0f1'
			);
		}
		echo '</div><div style="display:flex;gap:3px;max-width:820px;margin-bottom:16px;">';
		for ( $d = 1; $d <= $last; $d++ ) {
			printf(
				'<span style="flex:1;text-align:center;font-size:9px;color:%2$s;">%1$s</span>',
				esc_html( ( 1 === $d % 2 || $d === $last ) ? (string) $d : '' ),
				esc_attr( sprintf( '%s-%02d', $month, $d ) === $today ? '#0a7040' : '#a7aaad' )
			);
		}
		echo '</div>';

		echo '<details style="max-width:820px;margin-bottom:20px;"><summary style="cursor:pointer;color:#2271b1;">'
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
				echo '<tr><td>' . ( $first ? '<strong>' . esc_html( (string) $day ) . '</strong>' : '' ) . '</td>'
					. '<td><code style="font-size:11px;">' . esc_html( (string) $name ) . '</code></td>'
					. '<td>' . esc_html( number_format_i18n( (int) ( $r['calls'] ?? 0 ) ) ) . '</td>'
					. '<td>' . esc_html( number_format_i18n( (int) ( $r['in'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $r['out'] ?? 0 ) ) ) . '</td>'
					. '<td>$' . esc_html( number_format_i18n( (float) ( $r['cost'] ?? 0 ), 3 ) ) . '</td></tr>';
				$first = false;
			}
		}
		echo '</tbody></table></details>';
	}

	/** The months behind: one bar each, cost split by provider. */
	public static function render_months( int $limit = 12 ): void {
		$months = self::months( $limit );
		if ( ! $months ) {
			return;
		}
		$colors = [ 'anthropic' => '#7c5cff', 'fal' => '#d63384' ];
		$max    = 0.0;
		$totals = [];
		foreach ( $months as $m => $rows ) {
			$totals[ $m ] = self::month_total( (string) $m );
			$max          = max( $max, $totals[ $m ] );
		}
		echo '<h3 style="margin:0 0 8px;">' . esc_html__( 'Month by month', 'dazont-ecom' ) . '</h3>';
		echo '<div>';
		foreach ( array_reverse( $months, true ) as $m => $rows ) {
			$total = (float) $totals[ $m ];
			echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:5px;">';
			printf(
				'<a href="%2$s" style="flex:0 0 66px;font-size:12px;font-variant-numeric:tabular-nums;">%1$s</a>',
				esc_html( (string) $m ),
				esc_url( add_query_arg( 'dze_month', (string) $m, remove_query_arg( 'dze_month' ) ) )
			);
			echo '<span style="flex:1;display:flex;height:14px;border-radius:3px;overflow:hidden;background:#f0f0f1;">';
			foreach ( self::providers() as $key => $label ) {
				$c = (float) ( $rows[ $key ]['cost'] ?? 0 );
				if ( $c <= 0 || $max <= 0 ) {
					continue;
				}
				printf(
					'<span title="%3$s" style="width:%1$s%%;background:%2$s;display:block;"></span>',
					esc_attr( (string) round( 100 * $c / $max, 2 ) ),
					esc_attr( $colors[ $key ] ?? '#999' ),
					esc_attr( $label . ': $' . number_format( $c, 2 ) )
				);
			}
			echo '</span>';
			printf(
				'<span style="flex:0 0 70px;text-align:right;font-size:12px;color:#3c434a;">$%s</span>',
				esc_html( number_format_i18n( $total, 2 ) )
			);
			echo '</div>';
		}
		echo '</div><p style="margin:6px 0 0;">';
		foreach ( self::providers() as $key => $label ) {
			printf(
				'<span style="display:inline-flex;align-items:center;gap:6px;margin-right:16px;font-size:11px;color:#646970;"><span style="width:10px;height:10px;border-radius:3px;background:%1$s;display:inline-block;"></span>%2$s</span>',
				esc_attr( $colors[ $key ] ?? '#999' ),
				esc_html( $label )
			);
		}
		echo '</p>';
	}
}
