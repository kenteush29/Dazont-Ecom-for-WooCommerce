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
	<?php esc_html_e( 'The AI Marketing Assistant generates a promotional calendar for your shop. It reads your shop and languages automatically — nothing to describe by hand. Configure, per language, which countries it should consider below. The API key and model live on the General tab.', 'dazont-ecom' ); ?>
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
				<?php if ( $key_locked ) : ?>
					<div class="notice notice-info inline"><p><?php esc_html_e( 'Provided by the DZE_ANTHROPIC_API_KEY constant (wp-config.php).', 'dazont-ecom' ); ?></p></div>
				<?php else : ?>
					<input type="password" id="dze-mai-key" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[api_key]' ); ?>" value="" class="regular-text" autocomplete="new-password" placeholder="sk-ant-…" />
					<p class="description">
						<?php echo $has_key
							? '<span style="color:#0a7040;">&#10003; ' . esc_html__( 'A key is saved. Leave blank to keep it.', 'dazont-ecom' ) . '</span>'
							: esc_html__( 'No key set yet.', 'dazont-ecom' ); ?>
						<br />
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

	<h2 class="title"><?php esc_html_e( 'Target countries per language', 'dazont-ecom' ); ?></h2>
	<p class="description" style="max-width:820px;">
		<?php esc_html_e( 'Each language starts with a sensible pool of countries where it is naturally spoken/sold. Uncheck any you don\'t sell in, or add more. This is independent from Google Merchant Center — it only guides the AI toward realistic, locale-appropriate events.', 'dazont-ecom' ); ?>
	</p>
	<table class="form-table" role="presentation">
		<?php foreach ( $languages as $lang ) :
			$code    = $lang['code'];
			$saved   = $settings['country_pools'][ $code ] ?? null;
			$default = DZE_Marketing_Ai::country_pool_for( $code );
			// Union of default pool + anything previously saved, so earlier custom additions still show as checkboxes.
			$pool    = array_values( array_unique( array_merge( DZE_Marketing_Ai::LANGUAGE_COUNTRY_POOLS[ $code ] ?? [], is_array( $saved ) ? $saved : [] ) ) );
			sort( $pool );
			$checked = is_array( $saved ) ? $saved : $default; // first time: defaults are pre-checked.
		?>
			<tr>
				<th scope="row">
					<?php if ( ! empty( $lang['flag'] ) ) : ?><img src="<?php echo esc_url( $lang['flag'] ); ?>" alt="" style="width:18px;height:12px;vertical-align:middle;margin-right:4px;" /><?php endif; ?>
					<?php echo esc_html( $lang['native_name'] ); ?>
				</th>
				<td>
					<?php if ( empty( $pool ) ) : ?>
						<p class="description" style="margin-top:0;"><?php esc_html_e( 'No default pool for this language — add country codes below.', 'dazont-ecom' ); ?></p>
					<?php else : foreach ( $pool as $c ) : ?>
						<label style="display:inline-block;margin:0 12px 6px 0;">
							<input type="checkbox" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[country_pools][' . $code . '][]' ); ?>" value="<?php echo esc_attr( $c ); ?>" <?php checked( in_array( $c, $checked, true ) ); ?> />
							<?php echo esc_html( $c ); ?>
						</label>
					<?php endforeach; endif; ?>
					<br />
					<label class="description">
						<?php esc_html_e( 'Add more (comma-separated ISO codes):', 'dazont-ecom' ); ?>
						<input type="text" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[country_pools_extra][' . $code . ']' ); ?>" class="regular-text" placeholder="e.g. PT, GR" />
					</label>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>

	<h2 class="title"><?php esc_html_e( 'Shop catalog context', 'dazont-ecom' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Use catalog & sales data', 'dazont-ecom' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[use_catalog]' ); ?>" value="1" <?php checked( ! empty( $settings['use_catalog'] ) ); ?> />
					<?php esc_html_e( 'Include my product categories and best-sellers in the AI context', 'dazont-ecom' ); ?>
				</label>
				<p class="description" style="max-width:820px;"><?php esc_html_e( 'The marketing calendar is driven by real commercial moments (Black Friday, seasonal sales, holidays…), which rarely depend on your exact catalog. If suggestions feel off, turn this off so the AI focuses purely on the calendar and target market.', 'dazont-ecom' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="dze-mai-tone"><?php esc_html_e( 'Brand tone / voice', 'dazont-ecom' ); ?></label></th>
			<td>
				<input type="text" id="dze-mai-tone" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[tone]' ); ?>" value="<?php echo esc_attr( $settings['tone'] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. bold, tactical, no-nonsense', 'dazont-ecom' ); ?>" />
				<p class="description" style="max-width:820px;"><?php esc_html_e( 'How your brand speaks. Used lightly for the calendar today, and reused by the upcoming content tools (product descriptions, etc.).', 'dazont-ecom' ); ?></p>
			</td>
		</tr>
	</table>

	<h2 class="title"><?php esc_html_e( 'Marketing calendar prompt', 'dazont-ecom' ); ?></h2>
	<p class="description" style="max-width:820px;">
		<?php esc_html_e( 'The strategy the AI follows when building your calendar. Edit it to steer what kinds of events it proposes. The shop context, chosen language, date range and output format are always added automatically — you only write the strategy.', 'dazont-ecom' ); ?>
	</p>
	<?php
	$prompt_value   = trim( (string) ( $settings['events_prompt'] ?? '' ) );
	$prompt_display = $prompt_value !== '' ? $prompt_value : DZE_Marketing_Ai::DEFAULT_EVENTS_PROMPT;
	?>
	<textarea id="dze-mai-prompt" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[events_prompt]' ); ?>" rows="10" class="large-text code" style="max-width:820px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;"><?php echo esc_textarea( $prompt_display ); ?></textarea>
	<p>
		<button type="button" class="button" id="dze-mai-prompt-reset"><?php esc_html_e( '↺ Reset to default', 'dazont-ecom' ); ?></button>
		<span class="description"><?php echo $prompt_value !== '' ? esc_html__( 'Customised.', 'dazont-ecom' ) : esc_html__( 'Using the default strategy.', 'dazont-ecom' ); ?></span>
	</p>
	<script>
	(function () {
		var def = <?php echo wp_json_encode( DZE_Marketing_Ai::DEFAULT_EVENTS_PROMPT ); ?>;
		var btn = document.getElementById('dze-mai-prompt-reset');
		var ta  = document.getElementById('dze-mai-prompt');
		if ( btn && ta ) { btn.addEventListener('click', function () { ta.value = def; ta.focus(); }); }
	}());
	</script>
	<?php endif; // $show_events ?>

	<?php submit_button( __( 'Save configuration', 'dazont-ecom' ) ); ?>
</form>

<?php if ( $show_events ) : ?>
<hr />
<h2 class="title"><?php esc_html_e( 'What the AI sees about your shop', 'dazont-ecom' ); ?></h2>
<p class="description" style="max-width:820px;">
	<?php esc_html_e( 'The exact text sent to the AI as context. Kept deliberately short.', 'dazont-ecom' ); ?>
	<a href="<?php echo esc_url( add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG, 'tab' => 'events', 'dze_mai_refresh' => 1 ] , admin_url( 'admin.php' ) ) ); ?>">↻ <?php esc_html_e( 'Refresh', 'dazont-ecom' ); ?></a>
</p>
<?php if ( $context !== '' ) : ?>
	<pre style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:12px 16px;max-width:820px;white-space:pre-wrap;font-size:13px;"><?php echo esc_html( $context ); ?></pre>
<?php else : ?>
	<p class="description"><?php esc_html_e( 'Nothing detected yet — set a site tagline for better suggestions.', 'dazont-ecom' ); ?></p>
<?php endif; ?>
<p class="description" style="max-width:820px;">
	<?php
	printf(
		/* translators: %s: link to the WordPress General Settings screen */
		esc_html__( 'The tagline comes from your WordPress site tagline. %s', 'dazont-ecom' ),
		'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Edit it in Settings → General ↗', 'dazont-ecom' ) . '</a>'
	);
	?>
</p>

<hr />
<p class="description" style="max-width:820px;">
	<?php esc_html_e( 'To generate the calendar and review suggestions, go to Marketing Events.', 'dazont-ecom' ); ?>
	<a href="<?php echo esc_url( add_query_arg( [ 'page' => DZE_Discounts::MENU_SLUG_EVENTS ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open Marketing Events →', 'dazont-ecom' ); ?></a>
</p>
<?php endif; // $show_events ?>
