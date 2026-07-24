<?php defined( 'ABSPATH' ) || exit; ?>

<!-- ===================== GENERATE MODAL ===================== -->
<div class="aics-modal-overlay" id="aics-modal-generate" style="display:none;">
	<div class="aics-modal">
		<div class="aics-modal-head">
			<h2><?php esc_html_e( 'AI — Generate content', 'ai-content-suite' ); ?></h2>
			<button type="button" class="aics-modal-close" aria-label="Close">&times;</button>
		</div>
		<div class="aics-modal-body">
			<p class="aics-modal-target" id="aics-gen-target"></p>

			<fieldset class="aics-modal-fieldset">
				<legend><?php esc_html_e( 'Fields to generate', 'ai-content-suite' ); ?></legend>
				<?php foreach ( $slots as $slot => $label ) : ?>
					<label class="aics-modal-check">
						<input type="checkbox" class="aics-gen-slot" value="<?php echo esc_attr( $slot ); ?>" checked />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<div class="aics-modal-progress" style="display:none;">
				<div class="aics-progress-track"><div class="aics-progress-bar"></div></div>
				<p class="aics-progress-text"></p>
			</div>

			<div class="aics-modal-log" style="display:none;">
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Product', 'ai-content-suite' ); ?></th>
						<th><?php esc_html_e( 'Field', 'ai-content-suite' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ai-content-suite' ); ?></th>
						<th><?php esc_html_e( 'Info', 'ai-content-suite' ); ?></th>
					</tr></thead>
					<tbody></tbody>
				</table>
			</div>
		</div>
		<div class="aics-modal-foot">
			<button type="button" class="button button-primary aics-modal-start"><?php esc_html_e( 'Start', 'ai-content-suite' ); ?></button>
			<button type="button" class="button aics-modal-close"><?php esc_html_e( 'Close', 'ai-content-suite' ); ?></button>
		</div>
	</div>
</div>

<?php if ( $wpml ) : ?>
<!-- ===================== TRANSLATE MODAL ===================== -->
<div class="aics-modal-overlay" id="aics-modal-translate" style="display:none;">
	<div class="aics-modal">
		<div class="aics-modal-head">
			<h2><?php esc_html_e( 'AI — Translate content', 'ai-content-suite' ); ?></h2>
			<button type="button" class="aics-modal-close" aria-label="Close">&times;</button>
		</div>
		<div class="aics-modal-body">
			<p class="aics-modal-target" id="aics-tr-target"></p>

			<div class="aics-lang-switch">
				<label>
					<?php esc_html_e( 'From', 'ai-content-suite' ); ?>
					<select id="aics-tr-source">
						<?php foreach ( $languages as $lang ) : ?>
							<option value="<?php echo esc_attr( $lang['code'] ); ?>"><?php echo esc_html( $lang['native_name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<span class="aics-lang-arrow">→</span>
				<span class="aics-lang-targets">
					<strong><?php esc_html_e( 'To', 'ai-content-suite' ); ?>:</strong>
					<?php foreach ( $languages as $lang ) : ?>
						<label class="aics-modal-check">
							<input type="checkbox" class="aics-tr-target-lang" value="<?php echo esc_attr( $lang['code'] ); ?>" />
							<?php echo esc_html( $lang['native_name'] ); ?>
						</label>
					<?php endforeach; ?>
				</span>
			</div>

			<fieldset class="aics-modal-fieldset">
				<legend><?php esc_html_e( 'Fields to translate', 'ai-content-suite' ); ?></legend>
				<?php foreach ( $slots as $slot => $label ) : ?>
					<label class="aics-modal-check">
						<input type="checkbox" class="aics-tr-slot" value="<?php echo esc_attr( $slot ); ?>" checked />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<div class="aics-modal-progress" style="display:none;">
				<div class="aics-progress-track"><div class="aics-progress-bar"></div></div>
				<p class="aics-progress-text"></p>
			</div>

			<div class="aics-modal-log" style="display:none;">
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Product', 'ai-content-suite' ); ?></th>
						<th><?php esc_html_e( 'Lang', 'ai-content-suite' ); ?></th>
						<th><?php esc_html_e( 'Field', 'ai-content-suite' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ai-content-suite' ); ?></th>
						<th><?php esc_html_e( 'Info', 'ai-content-suite' ); ?></th>
					</tr></thead>
					<tbody></tbody>
				</table>
			</div>
		</div>
		<div class="aics-modal-foot">
			<button type="button" class="button button-primary aics-modal-start"><?php esc_html_e( 'Start', 'ai-content-suite' ); ?></button>
			<button type="button" class="button aics-modal-close"><?php esc_html_e( 'Close', 'ai-content-suite' ); ?></button>
		</div>
	</div>
</div>
<?php endif; ?>
