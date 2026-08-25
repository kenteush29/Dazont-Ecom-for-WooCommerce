<?php
defined( 'ABSPATH' ) || exit;
/**
 * AI calendar generator + suggestions review — embedded at the top of the
 * Marketing Events page.
 *
 * @var bool   $has_key
 * @var array  $suggestions
 * @var array  $languages   Active site languages ([code, native_name, flag]).
 * @var string $primary     Primary language code (pre-selected).
 * @var bool   $gmc_on      Whether Google Merchant Center is configured.
 */
$ai_settings_url = add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG ], admin_url( 'admin.php' ) );
?>
<div class="dze-mai-block" style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:16px 18px;margin-bottom:20px;">
	<h2 class="title" style="margin-top:0;display:flex;align-items:center;gap:12px;">
		<?php esc_html_e( 'AI Marketing Assistant — generate a calendar', 'dazont-ecom' ); ?>
		<button type="button" class="button dze-mai-new-event" style="font-weight:400;"><?php esc_html_e( '+ New event', 'dazont-ecom' ); ?></button>
	</h2>

	<?php if ( ! $has_key ) : ?>
		<p class="description">
			<?php esc_html_e( 'Add your Anthropic API key to enable this.', 'dazont-ecom' ); ?>
			<a href="<?php echo esc_url( $ai_settings_url ); ?>"><?php esc_html_e( 'Configure it in Settings →', 'dazont-ecom' ); ?></a>
		</p>
	<?php else : ?>
		<p>
			<label><?php esc_html_e( 'From', 'dazont-ecom' ); ?> <input type="date" id="dze-mai-start" /></label>
			&nbsp;
			<label><?php esc_html_e( 'To', 'dazont-ecom' ); ?> <input type="date" id="dze-mai-end" /></label>
			&nbsp;
			<button type="button" id="dze-mai-generate" class="button button-primary"><?php esc_html_e( 'Generate suggestions', 'dazont-ecom' ); ?></button>
			<?php if ( class_exists( 'DZE_Prompts' ) ) { DZE_Prompts::the_button( 'events' ); } ?>
			<span id="dze-mai-gen-status" style="margin-left:8px;font-size:13px;"></span>
		</p>
		<p class="description">
			<?php esc_html_e( 'Suggestions are made for the shop as a whole — one calendar, every language, every market. Which moments matter for your customers comes from what the shop says about itself; name your main markets there if it changes the answer.', 'dazont-ecom' ); ?>
			<a href="<?php echo esc_url( add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG, 'tab' => 'general' ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Settings → General → About this shop ↗', 'dazont-ecom' ); ?></a>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $suggestions ) ) : ?>
		<h3 style="margin-bottom:4px;"><?php esc_html_e( 'Suggested events — review before adding', 'dazont-ecom' ); ?></h3>
		<p class="description" style="margin-top:0;"><?php esc_html_e( 'Tick rows and use the bulk buttons, or Accept/Discard one at a time. Accepted events go live on the calendar below — switch off the ones you want to hold back.', 'dazont-ecom' ); ?></p>
		<p>
			<button type="button" class="button dze-mai-bulk-accept"><?php esc_html_e( 'Accept selected', 'dazont-ecom' ); ?></button>
			<button type="button" class="button dze-mai-bulk-refuse"><?php esc_html_e( 'Discard selected', 'dazont-ecom' ); ?></button>
			<span id="dze-mai-bulk-status" style="margin-left:8px;font-size:13px;color:#666;"></span>
		</p>
		<table class="widefat striped" id="dze-mai-suggestions">
			<thead>
				<tr>
					<th style="width:28px;text-align:center;"><input type="checkbox" id="dze-mai-check-all" /></th>
					<th><?php esc_html_e( 'Event', 'dazont-ecom' ); ?></th>
					<th style="width:72px;"><?php esc_html_e( 'Discount', 'dazont-ecom' ); ?></th>
					<th style="width:300px;"><?php esc_html_e( 'Dates', 'dazont-ecom' ); ?></th>
					<th style="width:220px;"><?php esc_html_e( 'Actions', 'dazont-ecom' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $suggestions as $sug ) :
					require DZE_DIR . 'admin/views/marketing-ai-row.php';
				endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<?php // ---- Event editor popup (Accept & modify / New event) ---- ?>
<div class="dze-ev-modal" id="dze-ev-modal" style="display:none;">
	<div class="dze-ev-modal__inner">
		<h2 id="dze-ev-title" style="margin-top:0;"><?php esc_html_e( 'Event', 'dazont-ecom' ); ?></h2>
		<input type="hidden" id="dze-ev-id" value="" />
		<input type="hidden" id="dze-ev-langs" value="" />
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dze-ev-name"><?php esc_html_e( 'Title', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="text" id="dze-ev-name" class="large-text" />
					<?php
					$dze_promo_langs = class_exists( 'DZE_Discounts' ) ? DZE_Discounts::promo_langs() : [];
					if ( $dze_promo_langs ) :
					?>
					<p style="margin:6px 0 0;">
						<button type="button" class="button-link" id="dze-ev-translate">&#127760; <?php esc_html_e( 'Write it for my other markets', 'dazont-ecom' ); ?></button>
						<span id="dze-ev-tr-status" class="description" style="margin-left:8px;"></span>
					</p>
					<details id="dze-ev-i18n" style="margin:6px 0 0;">
						<summary style="cursor:pointer;font-size:12px;color:#2271b1;">
							<?php
							printf(
								/* translators: %d: number of other languages */
								esc_html( _n( 'The title in your %d other language', 'The title in your %d other languages', count( $dze_promo_langs ), 'dazont-ecom' ) ),
								count( $dze_promo_langs )
							);
							?>
						</summary>
						<?php foreach ( $dze_promo_langs as $dze_code => $dze_name ) : ?>
							<p style="margin:6px 0 0;display:flex;align-items:center;gap:8px;">
								<label style="min-width:110px;font-size:12px;color:#646970;"><?php echo esc_html( $dze_name ); ?></label>
								<input type="text" class="large-text dze-ev-i18n-field" data-lang="<?php echo esc_attr( $dze_code ); ?>" />
							</p>
						<?php endforeach; ?>
						<p class="description" style="margin:6px 0 0;">
							<?php esc_html_e( 'What customers read on the banner in each market — the line that shop would write, not a translation of yours. Left empty, the promotion does not run in that language.', 'dazont-ecom' ); ?>
						</p>
					</details>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-ev-percent"><?php esc_html_e( 'Discount (%)', 'dazont-ecom' ); ?></label></th>
				<td><input type="number" id="dze-ev-percent" min="1" max="90" class="small-text" value="10" /> %</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Dates', 'dazont-ecom' ); ?></th>
				<td>
					<label><?php esc_html_e( 'From', 'dazont-ecom' ); ?> <input type="date" id="dze-ev-start" /></label>
					&nbsp;
					<label><?php esc_html_e( 'To', 'dazont-ecom' ); ?> <input type="date" id="dze-ev-end" /></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Countdown', 'dazont-ecom' ); ?></th>
				<td>
					<label><input type="checkbox" id="dze-ev-timer" /> &#9201; <?php esc_html_e( 'Count the days down on the banner', 'dazont-ecom' ); ?></label>
					<p class="description"><?php esc_html_e( 'For the few moments a deadline really presses on. On an ordinary sale it is noise.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
		</table>
		<p>
			<button type="button" class="button button-primary dze-ev-save"><?php esc_html_e( 'Save', 'dazont-ecom' ); ?></button>
			<?php if ( $gmc_on ) : ?>
				<button type="button" class="button dze-ev-save-gmc"><?php esc_html_e( 'Save & Push to GMC', 'dazont-ecom' ); ?></button>
			<?php endif; ?>
			<button type="button" class="button-link dze-ev-cancel" style="margin-left:6px;"><?php esc_html_e( 'Cancel', 'dazont-ecom' ); ?></button>
			<span class="dze-ev-status" style="margin-left:8px;font-size:13px;"></span>
		</p>

	</div>
</div>
<style>
	.dze-ev-modal{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;padding:24px;}
	.dze-ev-modal__inner{background:#fff;border-radius:10px;width:min(640px,96vw);max-height:88vh;overflow:auto;padding:18px 24px;}
</style>
