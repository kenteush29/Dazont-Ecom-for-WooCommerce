<?php
/**
 * The promotion's line in the shop's other markets.
 *
 * It sits under the title, because the title IS the line: a market's version
 * of it belongs beside the sentence it renders, not three screens further
 * down beside the banner's colours, where somebody writing the promotion
 * never looks.
 *
 * @var array $editing The rule being edited.
 */
defined( 'ABSPATH' ) || exit;
			// The banner line in the other languages: one row that folds
			// away, not one row per language pushing everything else down.
			$dze_promo_langs = class_exists( 'DZE_Discounts' ) ? DZE_Discounts::promo_langs() : [];
			if ( $dze_promo_langs ) :
				$i18n    = (array) ( $editing['banner_text_i18n'] ?? [] );
				$dze_got = 0;
				foreach ( $dze_promo_langs as $dze_code => $dze_name ) {
					$dze_got += empty( $i18n[ $dze_code ] ) ? 0 : 1;
				}
				?>
			<tr>
				<th scope="row"><?php esc_html_e( 'The title in your other markets', 'dazont-ecom' ); ?></th>
				<td>
					<button type="button" class="button-link" id="dze-banner-translate">&#127760; <?php esc_html_e( 'Write it for my other markets', 'dazont-ecom' ); ?></button>
					<?php
					// The instructions this button follows, opened and edited
					// where it is pressed: a line that comes back wrong is
					// corrected and asked for again without leaving the event.
					if ( class_exists( 'DZE_Prompts' ) ) {
						DZE_Prompts::the_button( 'promo_i18n' );
					}
					?>
					<span id="dze-banner-tr-status" class="description" style="margin-left:8px;"></span>
					<details id="dze-banner-i18n" style="margin:6px 0 0;"<?php echo $dze_got ? '' : ' open'; ?>>
						<summary style="cursor:pointer;font-size:12px;color:#2271b1;">
							<?php
							printf(
								/* translators: 1: translations written, 2: languages to fill */
								esc_html__( 'The title in your other languages (%1$d of %2$d written)', 'dazont-ecom' ),
								(int) $dze_got,
								count( $dze_promo_langs )
							);
							?>
						</summary>
						<?php foreach ( $dze_promo_langs as $dze_code => $dze_name ) : ?>
							<p style="margin:6px 0 0;display:flex;align-items:center;gap:8px;">
								<label style="min-width:110px;font-size:12px;color:#646970;"><?php echo esc_html( $dze_name ); ?></label>
								<input type="text" name="banner_text_i18n[<?php echo esc_attr( $dze_code ); ?>]" class="large-text dze-banner-i18n-field" data-lang="<?php echo esc_attr( $dze_code ); ?>" value="<?php echo esc_attr( $i18n[ $dze_code ] ?? '' ); ?>" placeholder="<?php echo esc_attr( sprintf( __( 'Translation for %s', 'dazont-ecom' ), $dze_name ) ); ?>" />
							</p>
						<?php endforeach; ?>
						<p class="description" style="margin:6px 0 0;">
							<?php
							echo class_exists( 'DZE_Marketing_Ai' ) && DZE_Marketing_Ai::promo_i18n_on()
								? esc_html__( 'A language left empty is a language this promotion does not run in. They are written for you shortly after saving, and that pass never touches a line you typed. The button above is the other way round: it rewrites every language, so press it again after changing the title.', 'dazont-ecom' )
								: esc_html__( 'A language left empty is a language this promotion does not run in. "Translate on save" is off in Settings → Marketing events, so these are yours to fill — or use the button above, which rewrites every language each time it is pressed.', 'dazont-ecom' );
							?>
						</p>
					</details>
				</td>
			</tr>

			<?php endif; ?>
