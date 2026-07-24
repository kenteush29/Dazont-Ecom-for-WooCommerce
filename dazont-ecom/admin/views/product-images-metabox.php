<?php
defined( 'ABSPATH' ) || exit;
/**
 * AI Product Images — product edit meta box.
 *
 * @var WP_Post $post         The product being edited.
 * @var bool    $has_key      Whether a Gemini API key is configured.
 * @var string  $settings_url URL of the module settings page.
 */
?>
<div class="dze-img-box" data-product="<?php echo esc_attr( (int) $post->ID ); ?>">

	<?php if ( ! $has_key ) : ?>
		<p class="description">
			<?php esc_html_e( 'Add your Google Gemini API key to generate images.', 'dazont-ecom' ); ?>
			<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open settings →', 'dazont-ecom' ); ?></a>
		</p>
	<?php else : ?>

		<p style="margin-top:0;">
			<label for="dze-img-situation"><strong><?php esc_html_e( 'Situation', 'dazont-ecom' ); ?></strong></label><br />
			<select id="dze-img-situation" class="widefat" style="max-width:420px;">
				<option value="lifestyle"><?php esc_html_e( 'Product not shown in use → generate a lifestyle photo', 'dazont-ecom' ); ?></option>
				<option value="recolor"><?php esc_html_e( 'Not enough images → recreate in another colour / variant', 'dazont-ecom' ); ?></option>
				<option value="enhance"><?php esc_html_e( 'Low-quality image → re-render it cleaner', 'dazont-ecom' ); ?></option>
				<option value="custom"><?php esc_html_e( 'Other → your own prompt', 'dazont-ecom' ); ?></option>
			</select>
		</p>

		<div class="dze-img-field dze-img-when-recolor" style="display:none;">
			<label for="dze-img-target"><strong><?php esc_html_e( 'Target colour / variant', 'dazont-ecom' ); ?></strong></label><br />
			<input type="text" id="dze-img-target" class="widefat" style="max-width:420px;" placeholder="<?php esc_attr_e( 'e.g. desert tan, matte black, olive green', 'dazont-ecom' ); ?>" />
		</div>

		<div class="dze-img-field dze-img-when-custom" style="display:none;margin-top:8px;">
			<label for="dze-img-custom"><strong><?php esc_html_e( 'Your prompt', 'dazont-ecom' ); ?></strong></label><br />
			<textarea id="dze-img-custom" class="widefat" rows="3" placeholder="<?php esc_attr_e( 'Describe exactly the image you want. The reference images below are sent to guide it.', 'dazont-ecom' ); ?>"></textarea>
		</div>

		<p style="margin-top:12px;">
			<strong><?php esc_html_e( 'Reference images', 'dazont-ecom' ); ?></strong><br />
			<span class="description"><?php esc_html_e( 'Sent to Gemini so the product stays identical. Leave empty to use the product\'s first images automatically.', 'dazont-ecom' ); ?></span>
		</p>
		<div id="dze-img-refs" class="dze-img-refs"></div>
		<p>
			<button type="button" class="button" id="dze-img-pick"><?php esc_html_e( 'Choose reference image(s)', 'dazont-ecom' ); ?></button>
			<button type="button" class="button-link dze-img-clear-refs" style="display:none;margin-left:8px;color:#b32d2e;"><?php esc_html_e( 'Clear', 'dazont-ecom' ); ?></button>
		</p>

		<p>
			<button type="button" class="button button-primary" id="dze-img-generate"><?php esc_html_e( 'Generate image', 'dazont-ecom' ); ?></button>
			<span id="dze-img-status" style="margin-left:8px;font-size:13px;color:#555;"></span>
		</p>

		<div id="dze-img-result" class="dze-img-result" style="display:none;">
			<div class="dze-img-preview"><img id="dze-img-preview-img" src="" alt="" /></div>
			<div class="dze-img-result-actions">
				<button type="button" class="button button-primary dze-img-accept" data-mode="gallery"><?php esc_html_e( 'Accept → add to gallery', 'dazont-ecom' ); ?></button>
				<button type="button" class="button dze-img-accept" data-mode="featured"><?php esc_html_e( 'Accept → set as main image', 'dazont-ecom' ); ?></button>
				<button type="button" class="button dze-img-regen"><?php esc_html_e( 'Regenerate', 'dazont-ecom' ); ?></button>
				<button type="button" class="button-link dze-img-discard" style="color:#b32d2e;"><?php esc_html_e( 'Discard', 'dazont-ecom' ); ?></button>
			</div>
			<p class="description dze-img-reload-note" style="display:none;">
				<?php esc_html_e( 'Added. Reload the page to see it in the product image / gallery boxes.', 'dazont-ecom' ); ?>
			</p>
		</div>

	<?php endif; ?>
</div>

<style>
.dze-img-refs { display:flex; flex-wrap:wrap; gap:8px; margin:4px 0; }
.dze-img-refs .dze-img-ref { position:relative; width:64px; height:64px; border:1px solid #dcdcde; border-radius:6px; overflow:hidden; background:#f6f7f7; }
.dze-img-refs .dze-img-ref img { width:100%; height:100%; object-fit:cover; }
.dze-img-refs .dze-img-ref button { position:absolute; top:0; right:0; border:0; background:rgba(0,0,0,.6); color:#fff; cursor:pointer; width:18px; height:18px; line-height:16px; padding:0; font-size:13px; }
.dze-img-result { margin-top:14px; padding-top:12px; border-top:1px solid #eee; }
.dze-img-preview img { max-width:100%; height:auto; border:1px solid #dcdcde; border-radius:8px; display:block; }
.dze-img-result-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:10px; }
</style>
