<?php defined( 'ABSPATH' ) || exit; ?>

<div id="aics-metabox-wrap">

	<?php if ( empty( $mapping['source_supplier_data'] ) && empty( $mapping['source_product_title'] ) ) : ?>
		<div class="notice notice-warning inline" style="margin: 0 0 12px;">
			<p>
				<?php esc_html_e( 'No source slots are mapped.', 'ai-content-suite' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=aics-field-mapping' ) ); ?>">
					<?php esc_html_e( 'Configure Field Mapping →', 'ai-content-suite' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<table class="aics-gen-table widefat" style="border: none;">
		<thead>
			<tr>
				<th style="width:22%"><?php esc_html_e( 'Field', 'ai-content-suite' ); ?></th>
				<th><?php esc_html_e( 'Generated content', 'ai-content-suite' ); ?></th>
				<th style="width:120px"></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $dest_slots as $slot => $label ) :
			$is_mapped = isset( $mapping[ $slot ] );
			$target    = $dom_targets[ $slot ] ?? [ 'id' => '', 'type' => 'none' ];
		?>
			<tr class="aics-gen-row"
				data-slot="<?php echo esc_attr( $slot ); ?>"
				data-target-id="<?php echo esc_attr( $target['id'] ); ?>"
				data-target-type="<?php echo esc_attr( $target['type'] ); ?>">
				<td>
					<strong><?php echo esc_html( $label ); ?></strong>
					<?php if ( ! $is_mapped ) : ?>
						<br><span style="color:#999;font-size:11px"><?php esc_html_e( 'not mapped', 'ai-content-suite' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<textarea
						class="aics-preview-area widefat"
						rows="3"
						placeholder="<?php esc_attr_e( 'Click Generate to produce content…', 'ai-content-suite' ); ?>"
						style="display:none; resize:vertical;"
					></textarea>
					<span class="aics-gen-status" style="color:#999;font-size:12px"></span>
				</td>
				<td style="vertical-align:middle; text-align:right;">
					<button
						type="button"
						class="button aics-btn-generate"
						<?php disabled( ! $is_mapped ); ?>
						title="<?php echo $is_mapped ? '' : esc_attr__( 'Map this slot first in Field Mapping.', 'ai-content-suite' ); ?>"
					>
						<?php esc_html_e( 'Generate', 'ai-content-suite' ); ?>
					</button>
					<button
						type="button"
						class="button button-primary aics-btn-apply"
						style="display:none; margin-top:4px;"
					>
						<?php esc_html_e( 'Apply', 'ai-content-suite' ); ?>
					</button>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<p style="margin-top:8px; color:#666; font-size:12px;">
		<?php
		printf(
			/* translators: link to settings page */
			esc_html__( 'Model and cost guardrails are configured in %s.', 'ai-content-suite' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=aics-settings' ) ) . '">' . esc_html__( 'Settings', 'ai-content-suite' ) . '</a>'
		);
		?>
	</p>

	<?php if ( AICS_Wpml_Translate::is_wpml_active() ) : ?>
	<p style="margin-top:12px; color:#666; font-size:12px;">
		<?php
		printf(
			/* translators: link to products list */
			esc_html__( 'To translate this product into other languages, use the %s action in the Products list.', 'ai-content-suite' ),
			'<a href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">' . esc_html__( 'AI: Translate', 'ai-content-suite' ) . '</a>'
		);
		?>
	</p>
	<?php endif; ?>

</div>
