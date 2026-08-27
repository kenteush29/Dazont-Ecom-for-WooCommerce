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
		<?php esc_html_e( 'The strategy the AI follows when building your calendar. Edit it to steer what kinds of events it proposes. The shop context, chosen language, date range and output format are always added automatically — you only write the strategy. What is added never argues with what you write here: an occasion that is not real is not proposed, the title names the occasion in the words your customers use for it, and a window with nothing worth a promotion in it comes back empty.', 'dazont-ecom' ); ?>
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
	<?php
	$dze_timer_rule = DZE_Marketing_Ai::timer_rule();
	$dze_opt        = DZE_Marketing_Ai::OPT_SETTINGS;
	?>
	<h2 class="title"><?php esc_html_e( 'The countdown', 'dazont-ecom' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'On generated events', 'dazont-ecom' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $dze_opt . '[timer_auto]' ); ?>" value="1" <?php checked( DZE_Marketing_Ai::timer_auto_on() ); ?> />
					<?php esc_html_e( 'Switch the countdown on by itself', 'dazont-ecom' ); ?>
				</label>
				<p style="margin:8px 0 0;">
					<label>
						<?php esc_html_e( 'from', 'dazont-ecom' ); ?>
						<input type="number" min="1" max="90" step="1" style="width:70px;" name="<?php echo esc_attr( $dze_opt . '[timer_min_percent]' ); ?>" value="<?php echo esc_attr( $dze_timer_rule[0] ); ?>" /> %
					</label>
					<label style="margin-left:16px;">
						<?php esc_html_e( 'running', 'dazont-ecom' ); ?>
						<input type="number" min="1" max="120" step="1" style="width:70px;" name="<?php echo esc_attr( $dze_opt . '[timer_max_days]' ); ?>" value="<?php echo esc_attr( $dze_timer_rule[1] ); ?>" />
						<?php esc_html_e( 'days at most', 'dazont-ecom' ); ?>
					</label>
				</p>
				<p class="description" style="max-width:820px;">
					<?php esc_html_e( 'An event that clears the bar is generated with the countdown on, one that does not is generated without it — either way it stays a tick box on the event itself.', 'dazont-ecom' ); ?>
					<br />
					<strong><?php esc_html_e( 'Recommended: 20% or more, over a week at most.', 'dazont-ecom' ); ?></strong>
					<?php esc_html_e( 'That is two or three moments a year — Black Friday, the last days before Christmas delivery, the end of an official sale period. A deadline three weeks away presses nobody, a discount the shop shows every month is not one either, and a banner that always counts down is a banner nobody hurries for: the countdown is worth what it is kept rare for.', 'dazont-ecom' ); ?>
				</p>
			</td>
		</tr>
	</table>

	<h2 class="title"><?php esc_html_e( 'The promo banner', 'dazont-ecom' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Style', 'dazont-ecom' ); ?></th>
			<td>
				<?php $dze_style = class_exists( 'DZE_Discounts' ) ? DZE_Discounts::banner_style() : [ 'bg' => '#111111', 'color' => '#ffffff' ]; ?>
				<?php
				// WordPress's own picker rather than the browser's: it takes a
				// hex typed by hand, which the native control does not, and a
				// shop with a brand colour written down somewhere has that
				// colour as six characters and not as a point on a gradient.
				?>
				<p style="margin:0 0 10px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
					<label><?php esc_html_e( 'Background', 'dazont-ecom' ); ?>
						<input type="text" class="dze-colour" data-target="dze-banner-demo" data-what="background"
							name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[banner_bg]' ); ?>"
							value="<?php echo esc_attr( $dze_style['bg'] ); ?>" />
					</label>
					<label><?php esc_html_e( 'Text', 'dazont-ecom' ); ?>
						<input type="text" class="dze-colour" data-target="dze-banner-demo" data-what="color"
							name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[banner_color]' ); ?>"
							value="<?php echo esc_attr( $dze_style['color'] ); ?>" />
					</label>
				</p>
				<p style="margin:0 0 10px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
					<label><?php esc_html_e( 'Text size', 'dazont-ecom' ); ?>
						<input type="number" id="dze-banner-size" min="0" max="40" step="1" class="small-text"
							name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[banner_size]' ); ?>"
							value="<?php echo esc_attr( (string) $dze_style['size'] ); ?>" />
						<?php esc_html_e( 'px — 0 leaves it to your theme', 'dazont-ecom' ); ?>
					</label>
					<label><?php esc_html_e( 'Padding', 'dazont-ecom' ); ?>
						<input type="number" id="dze-banner-pad" min="0" max="60" step="1" class="small-text"
							name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[banner_pad]' ); ?>"
							value="<?php echo esc_attr( (string) $dze_style['pad'] ); ?>" />
						<?php esc_html_e( 'px', 'dazont-ecom' ); ?>
					</label>
				</p>
				<p style="margin:0 0 10px;">
					<?php // The strip IS the banner: same colours, same size, same padding. ?>
					<span id="dze-banner-demo" style="display:inline-block;border-radius:4px;padding:<?php echo (int) $dze_style['pad']; ?>px;<?php echo $dze_style['size'] > 0 ? 'font-size:' . (int) $dze_style['size'] . 'px;' : ''; ?>background:<?php echo esc_attr( $dze_style['bg'] ); ?>;color:<?php echo esc_attr( $dze_style['color'] ); ?>;"><?php esc_html_e( 'Summer sale — 20% off', 'dazont-ecom' ); ?></span>
				</p>
				<p class="description" style="max-width:820px;">
					<?php esc_html_e( 'One style for every promotion, so the shop looks like itself whatever is running. Only the background and the text colour are set — the font comes from your theme.', 'dazont-ecom' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="dze-banner-where"><?php esc_html_e( 'Where it goes', 'dazont-ecom' ); ?></label></th>
			<td>
				<?php // Every promotion starts here — the one made by hand, the one the calendar proposes, the whole of a bulk creation — and any of them can still be moved on its own screen. ?>
				<select id="dze-banner-where" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[banner_location]' ); ?>">
					<?php foreach ( DZE_Discounts::locations() as $dze_k => $dze_label ) : ?>
						<option value="<?php echo esc_attr( $dze_k ); ?>" <?php selected( DZE_Discounts::default_location(), $dze_k ); ?>><?php echo esc_html( $dze_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<script>
				jQuery( function ( $ ) {
					if ( ! $.fn.wpColorPicker ) { return; }
					$( '#dze-banner-size, #dze-banner-pad' ).on( 'input change', function () {
						var $demo = $( '#dze-banner-demo' ),
							size = parseInt( $( '#dze-banner-size' ).val(), 10 ) || 0,
							pad = parseInt( $( '#dze-banner-pad' ).val(), 10 );
						$demo.css( 'padding', ( isNaN( pad ) ? 10 : pad ) + 'px' );
						$demo.css( 'font-size', size > 0 ? size + 'px' : '' );
					} );
					$( '.dze-colour' ).each( function () {
						var $f = $( this ),
							$demo = $( '#' + $f.data( 'target' ) ),
							what = $f.data( 'what' );
						$f.wpColorPicker( {
							// The strip beside the fields is what the banner
							// will look like, so it follows every change rather
							// than waiting for a save to be believed.
							change: function ( e, ui ) { $demo.css( what, ui.color.toString() ); },
							clear: function () { $demo.css( what, '' ); }
						} );
					} );
				} );
				</script>
			</td>
		</tr>
	</table>

	<h2 class="title"><?php esc_html_e( 'The promotion in your other markets', 'dazont-ecom' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Adapt promotions', 'dazont-ecom' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[promo_i18n_on]' ); ?>" value="1" <?php checked( DZE_Marketing_Ai::promo_i18n_on() ); ?> />
					<?php esc_html_e( 'Write the promotion title for my other markets', 'dazont-ecom' ); ?>
				</label>
				<p class="description" style="max-width:820px;">
					<?php esc_html_e( 'Not a translation: the line a shop in that market would write to announce the same offer. It runs when a calendar is generated — so the suggestions you review already carry their titles in every language — and again when an event is saved or switched on, for anything still missing. A promotion with nothing to say in a language does not run in that language at all, which is why this is on. It never overwrites a line you wrote yourself. Switched off, the fields stay yours to fill, and the button on an event still works.', 'dazont-ecom' ); ?>
				</p>
			</td>
		</tr>
	</table>
	<textarea id="dze-mai-promo-i18n" name="<?php echo esc_attr( DZE_Marketing_Ai::OPT_SETTINGS . '[promo_i18n_prompt]' ); ?>" rows="6" class="large-text code" style="max-width:820px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;"><?php echo esc_textarea( DZE_Marketing_Ai::promo_i18n_prompt() ); ?></textarea>
	<p>
		<button type="button" class="button-link" id="dze-mai-promo-i18n-reset">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
		<?php if ( class_exists( 'DZE_Prompt_Defaults' ) ) { DZE_Prompt_Defaults::control( 'promo_i18n', '#dze-mai-promo-i18n' ); } ?>
	</p>
	<script>
	(function () {
		var shipped = <?php echo wp_json_encode( DZE_Marketing_Ai::default_promo_i18n_prompt() ); ?>;
		var btn = document.getElementById('dze-mai-promo-i18n-reset');
		var ta  = document.getElementById('dze-mai-promo-i18n');
		if ( btn && ta ) {
			btn.addEventListener('click', function () {
				ta.value = window.dzeDefaultFor ? window.dzeDefaultFor( 'promo_i18n', shipped ) : shipped;
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
