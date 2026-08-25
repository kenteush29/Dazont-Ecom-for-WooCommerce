<?php
defined( 'ABSPATH' ) || exit;
/**
 * AI Marketing Assistant settings — embedded inside the Settings page (tab=ai).
 *
 * @var array  $settings
 * @var bool   $key_locked
 * @var bool   $has_key
 * @var array  $languages   Active site languages, from DZE_Marketing_Ai::active_languages().
 * @var string $context     Auto-detected shop context, as sent to the AI.
 * @var string $dze_section 'all', 'general' (key + model) or 'events' (the rest).
 */
$dze_section  = isset( $dze_section ) ? $dze_section : 'all';
$show_general = in_array( $dze_section, [ 'all', 'general' ], true );
$show_events  = in_array( $dze_section, [ 'all', 'events' ], true );
?>
<?php if ( $show_events ) : ?>
<p class="description" style="max-width:820px;">
	<?php esc_html_e( 'The AI Marketing Assistant generates a promotional calendar for your shop. It reads your shop and languages automatically — nothing to describe by hand. The API key and model live on the General tab.', 'dazont-ecom' ); ?>
</p>
<?php endif; ?>

<form method="post" action="options.php">
	<?php settings_fields( 'dze_mai_options' ); ?>
	<input type="hidden" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS ); ?>[section]" value="<?php echo esc_attr( $dze_section ); ?>" />

	<?php if ( $show_general ) : ?>
	<h2 class="title"><?php esc_html_e( 'Anthropic API key', 'dazont-ecom' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="dze-mai-key"><?php esc_html_e( 'API key', 'dazont-ecom' ); ?></label></th>
			<td>
				<?php echo DZE_Api_Keys::status_html( 'anthropic', DZE_Marketing_Ai::api_key(), $key_locked ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?>
				<?php if ( ! $key_locked ) : ?>
					<input type="password" id="dze-mai-key" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[api_key]' ); ?>" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_key ? esc_attr__( 'Leave blank to keep the saved key', 'dazont-ecom' ) : 'sk-ant-…'; ?>" />
					<p class="description">
						<a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get an API key from your Anthropic dashboard ↗', 'dazont-ecom' ); ?></a>
					</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>

	<h2 class="title"><?php esc_html_e( 'Claude model', 'dazont-ecom' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="dze-mai-model"><?php esc_html_e( 'Model', 'dazont-ecom' ); ?></label></th>
			<td>
				<?php
				$model_locked = defined( 'DZE_ANTHROPIC_MODEL' );
				$current      = DZE_Marketing_Ai::chosen_model();
				if ( $model_locked ) : ?>
					<div class="notice notice-info inline"><p><?php
						/* translators: %s: model identifier */
						printf( esc_html__( 'Fixed by the DZE_ANTHROPIC_MODEL constant (%s).', 'dazont-ecom' ), '<code>' . esc_html( $current ) . '</code>' );
					?></p></div>
				<?php else : ?>
					<?php $models = DZE_Marketing_Ai::available_models();
					if ( ! array_key_exists( $current, $models ) ) {
						$models = [ $current => $current ] + $models; // keep a saved-but-unlisted id selectable.
					} ?>
					<select id="dze-mai-model" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[model]' ); ?>">
						<?php foreach ( $models as $id => $label ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $current ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Pulled live from your Anthropic account (refreshed every 12h), so new Claude models appear automatically. Higher-quality models cost more per request.', 'dazont-ecom' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="dze-mai-budget"><?php esc_html_e( 'Monthly AI budget (USD)', 'dazont-ecom' ); ?></label></th>
			<td>
				<input type="number" id="dze-mai-budget" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[budget_month]' ); ?>" value="<?php echo esc_attr( (float) ( $settings['budget_month'] ?? 0 ) ?: '' ); ?>" min="0" step="0.5" style="width:100px;" placeholder="0" />
				<p class="description"><?php esc_html_e( 'Hard cap for ALL AI features combined (calendar, category insights, keyword matching, product images). When the estimated month spend reaches it, every AI call is blocked until next month. 0 or empty = no cap. Current month spend shows in the usage graph below.', 'dazont-ecom' ); ?></p>
			</td>
		</tr>
	</table>
	<?php endif; // $show_general ?>

	<?php if ( $show_events ) : ?>
	<h2 class="title"><?php esc_html_e( 'Languages detected', 'dazont-ecom' ); ?></h2>
	<p class="description" style="max-width:820px;">
		<?php echo class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active()
			? esc_html__( 'Read automatically from WPML — nothing to configure here.', 'dazont-ecom' )
			: esc_html__( 'WPML is not active, so the assistant uses your site\'s default language.', 'dazont-ecom' ); ?>
	</p>
	<p>
		<?php foreach ( $languages as $lang ) : ?>
			<span style="display:inline-flex;align-items:center;gap:5px;margin-right:14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:20px;padding:4px 12px;">
				<?php if ( ! empty( $lang['flag'] ) ) : ?><img src="<?php echo esc_url( $lang['flag'] ); ?>" alt="" style="width:16px;height:11px;" /><?php endif; ?>
				<?php echo esc_html( $lang['native_name'] ); ?> <code><?php echo esc_html( strtoupper( $lang['code'] ) ); ?></code>
			</span>
		<?php endforeach; ?>
	</p>

	<h2 class="title"><?php esc_html_e( 'Marketing calendar prompt', 'dazont-ecom' ); ?></h2>
	<p class="description" style="max-width:820px;">
		<?php esc_html_e( 'The strategy the AI follows when building your calendar. Edit it to steer what kinds of events it proposes. The shop context, chosen language, date range and output format are always added automatically — you only write the strategy.', 'dazont-ecom' ); ?>
	</p>
	<?php
	$prompt_value   = trim( (string) ( $settings['events_prompt'] ?? '' ) );
	$prompt_display = $prompt_value !== '' ? $prompt_value : DZE_Marketing_Ai::default_events_prompt();
	?>
	<textarea id="dze-mai-prompt" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[events_prompt]' ); ?>" rows="10" class="large-text code" style="max-width:820px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;"><?php echo esc_textarea( $prompt_display ); ?></textarea>
	<p>
		<button type="button" class="button-link" id="dze-mai-prompt-reset">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
		<?php if ( class_exists( 'DZE_Prompt_Defaults' ) ) { DZE_Prompt_Defaults::control( 'events', '#dze-mai-prompt' ); } ?>
		<span class="description"><?php echo $prompt_value !== '' ? esc_html__( 'Customised.', 'dazont-ecom' ) : esc_html__( 'Using the default strategy.', 'dazont-ecom' ); ?></span>
	</p>
	<script>
	(function () {
		var shipped = <?php echo wp_json_encode( DZE_Marketing_Ai::default_events_prompt() ); ?>;
		var btn = document.getElementById('dze-mai-prompt-reset');
		var ta  = document.getElementById('dze-mai-prompt');
		if ( btn && ta ) {
			btn.addEventListener('click', function () {
				ta.value = window.dzeDefaultFor ? window.dzeDefaultFor( 'events', shipped ) : shipped;
				ta.focus();
			});
		}
	}());
	</script>
	<?php endif; // $show_events ?>

	<?php submit_button( __( 'Save configuration', 'dazont-ecom' ) ); ?>
</form>

<?php if ( $show_events ) : ?>
<hr />
<!-- What is sent as context is the shop's own description, written once on the
     General tab. Reprinting it here — next to a checkbox adding category names
     on top of it — was the same text in two places and a second thing to keep
     in step with the first. -->
<p class="description" style="max-width:820px;">
	<?php
	printf(
		/* translators: %s: link to the General tab */
		esc_html__( 'Context sent with every generation: what you wrote under %s.', 'dazont-ecom' ),
		'<a href="' . esc_url( add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG, 'tab' => 'general' ], admin_url( 'admin.php' ) ) ) . '">'
			. esc_html__( 'Settings → General → About this shop ↗', 'dazont-ecom' ) . '</a>'
	);
	?>
</p>
<?php endif; ?>
