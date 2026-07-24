<?php
defined( 'ABSPATH' ) || exit;
/**
 * AI Product Images — Gemini settings, embedded on the AI Settings page.
 *
 * @var array  $settings    Current settings ( api_key, model, prompts ).
 * @var bool   $key_locked  Whether the API key is fixed by the DZE_GEMINI_API_KEY constant.
 * @var string $dze_section 'all', 'keys' (API key + model) or 'prompts'.
 */
$show_keys    = in_array( $dze_section, [ 'all', 'keys' ], true );
$show_prompts = in_array( $dze_section, [ 'all', 'prompts' ], true );

$labels = [
	'recolor'   => __( 'Recolor / variant', 'dazont-ecom' ),
	'lifestyle' => __( 'Lifestyle (in use)', 'dazont-ecom' ),
	'enhance'   => __( 'Enhance quality', 'dazont-ecom' ),
];
$hints = [
	'recolor'   => __( 'Used when you send a reference image and describe a target colour / variant.', 'dazont-ecom' ),
	'lifestyle' => __( 'Used to place the product in a real-world scene, from its first images.', 'dazont-ecom' ),
	'enhance'   => __( 'Used to re-render an existing image cleaner and sharper.', 'dazont-ecom' ),
];
?>
<div class="dze-img-settings">
	<form method="post" action="options.php">
		<?php settings_fields( 'dze_img_options' ); ?>
		<input type="hidden" name="<?php echo esc_attr( DZE_Product_Images::OPT_SETTINGS ); ?>[section]" value="<?php echo esc_attr( 'all' === $dze_section ? 'all' : ( $show_keys ? 'keys' : 'prompts' ) ); ?>" />

		<?php if ( $show_keys ) : ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dze-img-key"><?php esc_html_e( 'Google Gemini API key', 'dazont-ecom' ); ?></label></th>
					<td>
						<?php if ( $key_locked ) : ?>
							<input type="text" class="regular-text" value="<?php esc_attr_e( 'Set by the DZE_GEMINI_API_KEY constant', 'dazont-ecom' ); ?>" disabled />
							<p class="description"><?php esc_html_e( 'The key is defined in wp-config.php and cannot be edited here.', 'dazont-ecom' ); ?></p>
						<?php else : ?>
							<input type="password" id="dze-img-key" class="regular-text" name="<?php echo esc_attr( DZE_Product_Images::OPT_SETTINGS ); ?>[api_key]" value="" autocomplete="off" placeholder="<?php echo $settings['api_key'] !== '' ? esc_attr__( '•••••••••• (saved — leave blank to keep)', 'dazont-ecom' ) : 'AIza…'; ?>" />
							<p class="description"><?php esc_html_e( 'Powers the AI Product Images generator on the product edit screen. Stored in your database and only ever sent to Google. Leave blank to keep the saved key.', 'dazont-ecom' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-img-model"><?php esc_html_e( 'Model', 'dazont-ecom' ); ?></label></th>
					<td>
						<input type="text" id="dze-img-model" class="regular-text" name="<?php echo esc_attr( DZE_Product_Images::OPT_SETTINGS ); ?>[model]" value="<?php echo esc_attr( $settings['model'] ); ?>" />
						<p class="description"><?php printf( esc_html__( 'Default: %s (Google\'s image generation / editing model).', 'dazont-ecom' ), '<code>gemini-2.5-flash-image</code>' ); ?></p>
					</td>
				</tr>
			</table>
		<?php endif; ?>

		<?php if ( $show_prompts ) : ?>
			<p class="description" style="max-width:760px;">
				<?php
				printf(
					/* translators: 1: {title} placeholder, 2: {target} placeholder */
					esc_html__( 'Prompt templates for the AI Product Images box on the product edit screen. %1$s is replaced by the product name, %2$s by the text you type on the product page (target colour / variant). Leave one blank to fall back to the built-in default. The Custom situation always uses the text typed on the product page.', 'dazont-ecom' ),
					'<code>{title}</code>',
					'<code>{target}</code>'
				);
				?>
			</p>
			<table class="form-table" role="presentation">
				<?php foreach ( $labels as $sit => $label ) : ?>
					<tr>
						<th scope="row"><label for="dze-img-p-<?php echo esc_attr( $sit ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<textarea id="dze-img-p-<?php echo esc_attr( $sit ); ?>" class="large-text" rows="3" name="<?php echo esc_attr( DZE_Product_Images::OPT_SETTINGS ); ?>[prompts][<?php echo esc_attr( $sit ); ?>]" placeholder="<?php echo esc_attr( DZE_Product_Images::DEFAULT_PROMPTS[ $sit ] ); ?>"><?php echo esc_textarea( (string) ( $settings['prompts'][ $sit ] ?? '' ) ); ?></textarea>
							<p class="description"><?php echo esc_html( $hints[ $sit ] ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>

		<?php submit_button(); ?>
	</form>
</div>
