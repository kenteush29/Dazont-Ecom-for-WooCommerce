<?php
defined( 'ABSPATH' ) || exit;
/**
 * One (compact) suggestion row for the AI Marketing review table.
 *
 * @var array $sug
 */
$sug = wp_parse_args( $sug, [
	'id' => '', 'title' => '', 'percent' => 0, 'start_date' => '', 'end_date' => '',
	'languages' => [], 'email_subject' => '', 'rationale' => '', 'countries' => [],
] );
$langs     = implode( ', ', (array) $sug['languages'] );
$countries = implode( ', ', (array) $sug['countries'] );
?>
<tr class="dze-mai-row" data-id="<?php echo esc_attr( $sug['id'] ); ?>"
	data-title="<?php echo esc_attr( $sug['title'] ); ?>"
	data-percent="<?php echo esc_attr( (int) $sug['percent'] ); ?>"
	data-start="<?php echo esc_attr( $sug['start_date'] ); ?>"
	data-end="<?php echo esc_attr( $sug['end_date'] ); ?>"
	data-langs="<?php echo esc_attr( $langs ); ?>"
	data-subject="<?php echo esc_attr( $sug['email_subject'] ); ?>">
	<td style="text-align:center;"><input type="checkbox" class="dze-mai-cb" /></td>
	<td>
		<input type="text" class="large-text dze-f-title" value="<?php echo esc_attr( $sug['title'] ); ?>" />
		<input type="hidden" class="dze-f-subject" value="<?php echo esc_attr( $sug['email_subject'] ); ?>" />
		<input type="hidden" class="dze-f-langs" value="<?php echo esc_attr( $langs ); ?>" />
		<?php if ( ! empty( $sug['rationale'] ) ) : ?>
			<div class="description" style="margin:3px 0 0;font-size:12px;"><?php echo esc_html( $sug['rationale'] ); ?><?php if ( $countries ) : ?> · <code style="font-size:11px;"><?php echo esc_html( $countries ); ?></code><?php endif; ?></div>
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
