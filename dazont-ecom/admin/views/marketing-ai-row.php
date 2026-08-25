<?php
defined( 'ABSPATH' ) || exit;
/**
 * One (compact) suggestion row for the AI Marketing review table.
 *
 * @var array $sug
 */
$sug = wp_parse_args( $sug, [
	'id' => '', 'title' => '', 'percent' => 0, 'start_date' => '', 'end_date' => '',
	'languages' => [], 'rationale' => '', 'i18n' => [], 'timer' => false,
] );
$langs = implode( ', ', (array) $sug['languages'] );
?>
<tr class="dze-mai-row" data-id="<?php echo esc_attr( $sug['id'] ); ?>"
	data-title="<?php echo esc_attr( $sug['title'] ); ?>"
	data-percent="<?php echo esc_attr( (int) $sug['percent'] ); ?>"
	data-start="<?php echo esc_attr( $sug['start_date'] ); ?>"
	data-end="<?php echo esc_attr( $sug['end_date'] ); ?>"
	data-langs="<?php echo esc_attr( $langs ); ?>"
	data-timer="<?php echo empty( $sug['timer'] ) ? '0' : '1'; ?>"
	data-i18n="<?php echo esc_attr( (string) wp_json_encode( (array) $sug['i18n'] ) ); ?>">
	<td style="text-align:center;"><input type="checkbox" class="dze-mai-cb" /></td>
	<td>
		<input type="text" class="large-text dze-f-title" value="<?php echo esc_attr( $sug['title'] ); ?>" />
		<input type="hidden" class="dze-f-langs" value="<?php echo esc_attr( $langs ); ?>" />
		<?php if ( ! empty( $sug['rationale'] ) ) : ?>
			<div class="description" style="margin:3px 0 0;font-size:12px;"><?php echo esc_html( $sug['rationale'] ); ?></div>
		<?php endif; ?>
		<?php // A countdown is for the two or three moments of the year a
		// deadline really presses on; the calendar says which, you decide. ?>
		<label style="display:inline-block;margin:4px 0 0;font-size:12px;color:#646970;">
			<input type="checkbox" class="dze-f-timer" <?php checked( ! empty( $sug['timer'] ) ); ?> />
			&#9201; <?php esc_html_e( 'Countdown on the banner', 'dazont-ecom' ); ?>
		</label>
		<?php
		// The title as customers will read it in each language — already
		// written when the calendar was generated, so what is reviewed here is
		// the event as it will exist.
		$dze_row_langs = class_exists( 'DZE_Discounts' ) ? DZE_Discounts::promo_langs() : [];
		if ( $dze_row_langs ) :
			$dze_i18n = (array) $sug['i18n'];
			$dze_done = 0;
			foreach ( $dze_row_langs as $dze_c => $dze_n ) {
				$dze_done += empty( $dze_i18n[ $dze_c ] ) ? 0 : 1;
			}
			?>
			<details class="dze-mai-i18n" style="margin:4px 0 0;">
				<summary style="cursor:pointer;font-size:12px;color:#2271b1;">
					<?php
					printf(
						/* translators: 1: translated titles, 2: languages */
						esc_html__( 'Title in %1$d of %2$d other languages', 'dazont-ecom' ),
						(int) $dze_done,
						count( $dze_row_langs )
					);
					?>
				</summary>
				<?php foreach ( $dze_row_langs as $dze_c => $dze_n ) : ?>
					<p style="margin:4px 0 0;display:flex;align-items:center;gap:6px;">
						<label style="min-width:90px;font-size:11px;color:#646970;"><?php echo esc_html( $dze_n ); ?></label>
						<input type="text" class="large-text dze-f-i18n" data-lang="<?php echo esc_attr( $dze_c ); ?>" value="<?php echo esc_attr( (string) ( $dze_i18n[ $dze_c ] ?? '' ) ); ?>" style="font-size:12px;" />
					</p>
				<?php endforeach; ?>
			</details>
		<?php endif; ?>
	</td>
	<td><input type="number" min="1" max="90" class="dze-f-percent" style="width:56px;" value="<?php echo esc_attr( (int) $sug['percent'] ); ?>" />%</td>
	<td style="white-space:nowrap;">
		<input type="date" class="dze-f-start" value="<?php echo esc_attr( $sug['start_date'] ); ?>" style="width:135px;" />
		<span style="color:#999;">→</span>
		<input type="date" class="dze-f-end" value="<?php echo esc_attr( $sug['end_date'] ); ?>" style="width:135px;" />
	</td>
	<td style="white-space:nowrap;">
		<button type="button" class="button button-primary dze-mai-accept"><?php esc_html_e( 'Accept', 'dazont-ecom' ); ?></button>
		<button type="button" class="button dze-mai-modify"><?php esc_html_e( 'Accept & modify', 'dazont-ecom' ); ?></button>
		<button type="button" class="button-link dze-mai-refuse" style="color:#b32d2e;"><?php esc_html_e( 'Discard', 'dazont-ecom' ); ?></button>
		<div class="dze-mai-row-status" style="font-size:12px;margin-top:2px;"></div>
	</td>
</tr>
