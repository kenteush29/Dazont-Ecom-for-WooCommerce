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
		<p class="description" style="margin-top:0;"><?php esc_html_e( 'Tick rows and use the bulk buttons, or Accept/Discard one at a time. Accepted events are added to the calendar below as disabled — enable the ones you want.', 'dazont-ecom' ); ?></p>
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
				<td><input type="text" id="dze-ev-name" class="large-text" /></td>
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
		</table>
		<p>
			<button type="button" class="button button-primary dze-ev-save"><?php esc_html_e( 'Save', 'dazont-ecom' ); ?></button>
			<?php if ( $gmc_on ) : ?>
				<button type="button" class="button dze-ev-save-gmc"><?php esc_html_e( 'Save & Push to GMC', 'dazont-ecom' ); ?></button>
			<?php endif; ?>
			<button type="button" class="button-link dze-ev-cancel" style="margin-left:6px;"><?php esc_html_e( 'Cancel', 'dazont-ecom' ); ?></button>
			<span class="dze-ev-status" style="margin-left:8px;font-size:13px;"></span>
		</p>
		<?php if ( class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active() ) : ?>
			<p class="description" style="margin:0;">
				<?php
				echo class_exists( 'DZE_Marketing_Ai' ) && DZE_Marketing_Ai::promo_i18n_on()
					? esc_html__( 'The title is what customers read on the banner. It is translated into your other languages on its own, shortly after saving — you can correct any of them on the event itself.', 'dazont-ecom' )
					: esc_html__( 'The title is what customers read on the banner. "Translate on save" is off, so this event will only run in the main language until you write the other lines on it.', 'dazont-ecom' );
				?>
			</p>
		<?php endif; ?>
	</div>
</div>
<style>
	.dze-ev-modal{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;padding:24px;}
	.dze-ev-modal__inner{background:#fff;border-radius:10px;width:min(640px,96vw);max-height:88vh;overflow:auto;padding:18px 24px;}
</style>
