<?php
defined( 'ABSPATH' ) || exit;

/**
 * The WPML Translation Module's own screen: Dazont Ecom → WPML Translations.
 *
 * "Il devra être affiché dans le menu du plugin. Tu vas y créer un dashboard
 * avec les options. Et une liste d'attente un peu comme wpml pour relecture du
 * contenu traduit. Avant automatisation."
 *
 * WPML's own Translation Management is built exactly this way and the shop
 * already knows it: you look at where the site stands language by language,
 * you send a BATCH, and the batch waits to be read before it lands. This is
 * that, with the plugin's own machinery underneath — the Dazont Ecom line,
 * read top to bottom:
 *
 *   1. what the site holds today, in figures, per language;
 *   2. one block per kind of thing there is to translate — its switch in its
 *      own heading, its count beside it — which here is WHAT WPML SAYS IS
 *      TRANSLATABLE and nothing else;
 *   3. one button that runs what is ticked;
 *   4. before and after, both printed, field by field;
 *   5. accept and refuse, side by side.
 *
 * The settings stay where every setting in this plugin lives — Settings →
 * Translation. This screen is the WORK, not the preferences: a second settings
 * menu is the one thing the plugin may not have.
 */
trait DZE_Translate_Screen {

	public const MENU_SLUG = 'dazont-ecom-translations';

	/** The entry, under the plugin's own menu, with what waits beside it. */
	public function register_menu(): void {
		if ( class_exists( 'DZE_Modules' ) && ! DZE_Modules::enabled( 'translate' ) ) {
			return;
		}
		$waiting = self::review_count();
		$label   = __( 'WPML Translations', 'dazont-ecom' );
		add_submenu_page(
			class_exists( 'DZE_Restock' ) ? DZE_Restock::MENU_SLUG : 'dazont-ecom',
			$label,
			$waiting
				? $label . ' <span class="update-plugins count-' . (int) $waiting . '"><span class="plugin-count">' . (int) $waiting . '</span></span>'
				: $label,
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::MENU_SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	/** The two views, each with the figure it is about. */
	public static function tabs(): array {
		return [
			'dashboard' => [ 'label' => __( 'Dashboard', 'dazont-ecom' ), 'n' => count( self::scope() ) ],
			'review'    => [ 'label' => __( 'To review', 'dazont-ecom' ), 'n' => self::review_count() ],
		];
	}

	private static function tab_now(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$want = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return isset( self::tabs()[ $want ] ) ? $want : 'dashboard';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab  = self::tab_now();
		$tabs = self::tabs();
		echo '<div class="wrap dze-wrap dze-admin">';
		echo '<h1>' . esc_html__( 'WPML Translations', 'dazont-ecom' ) . '</h1>';
		echo '<h2 class="nav-tab-wrapper" style="margin:12px 0 0;">';
		foreach ( $tabs as $id => $one ) {
			printf(
				'<a class="nav-tab%1$s" href="%2$s">%3$s <span class="dze-tab-n">%4$s</span></a>',
				$tab === $id ? ' nav-tab-active' : '',
				esc_url( self::url( [ 'tab' => $id ] ) ),
				esc_html( $one['label'] ),
				esc_html( number_format_i18n( (int) $one['n'] ) )
			);
		}
		echo '</h2>';
		if ( ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			// ONE SENTENCE OF WARNING IS THE WHOLE OF IT, and it says the one
			// thing to do rather than explaining a mechanism.
			echo '<div class="notice notice-warning inline" style="margin:16px 0;"><p>'
				. esc_html__( 'WPML is not running. This module reads its languages, its translation groups and its settings from WPML — with WPML off there is no second language to translate into.', 'dazont-ecom' )
				. '</p></div></div>';
			return;
		}
		if ( 'review' === $tab ) {
			self::review_body();
		} else {
			self::dash_body();
		}
		echo '</div>';
	}

	// =========================================================================
	// Dashboard — where the site stands, and the batch that changes it
	// =========================================================================

	/**
	 * HOW MANY OBJECTS OF ONE KIND EXIST IN EACH LANGUAGE.
	 *
	 * One query per kind, asked of WPML's own table — never a filter, which
	 * only answers where WPML's hooks are loaded, and never a query per row.
	 *
	 * @return array<string,int> language code => count
	 */
	public static function counts_for( array $scope ): array {
		global $wpdb;
		if ( ! $wpdb || ! class_exists( 'DZE_Wpml' ) ) {
			return [];
		}
		$name  = DZE_Wpml::element_name( (string) $scope['kind'], (string) $scope['type'] );
		$table = $wpdb->prefix . 'icl_translations';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WPML's own table; one query for the whole row.
		if ( 'post' === $scope['kind'] ) {
			// A draft in the bin is not a page of this shop, and counting it
			// makes every figure on this screen wrong by however many the
			// shop has thrown away.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT t.language_code AS lang, COUNT(*) AS n
				   FROM {$table} t
				   INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id
				  WHERE t.element_type = %s AND p.post_status NOT IN ('auto-draft','trash','inherit')
			   GROUP BY t.language_code",
				$name
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT language_code AS lang, COUNT(*) AS n FROM {$table} WHERE element_type = %s GROUP BY language_code",
				$name
			), ARRAY_A );
		}
		// phpcs:enable
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['lang'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * HOW MANY WPML WANTS DONE AGAIN, per language, in one query.
	 *
	 * "Il faut aussi un compte des produits qui ont besoin d'une mise à jour
	 * des trads. Voir la data WPML pour ça." Missing and out-of-date are two
	 * different piles of work, and a screen showing only the first says the
	 * catalogue is finished when it is not.
	 *
	 * @return array<string,int> language code => count
	 */
	public static function marked_for( array $scope ): array {
		global $wpdb;
		if ( ! $wpdb || ! class_exists( 'DZE_Wpml' ) ) {
			return [];
		}
		$name = DZE_Wpml::element_name( (string) $scope['kind'], (string) $scope['type'] );
		$tr   = $wpdb->prefix . 'icl_translations';
		$st   = $wpdb->prefix . 'icl_translation_status';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WPML's own tables.
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT t.language_code AS lang, COUNT(*) AS n
			   FROM {$tr} t
			   INNER JOIN {$st} s ON s.translation_id = t.translation_id
			  WHERE t.element_type = %s AND s.needs_update = 1
		   GROUP BY t.language_code",
			$name
		), ARRAY_A );
		// phpcs:enable
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (string) $r['lang'] ] = (int) $r['n'];
		}
		return $out;
	}

	public static function dash_body(): void {
		$scope = self::picked_scope();
		$langs = DZE_Wpml::get_active_languages();
		$src   = DZE_Wpml::default_language();
		?>
		<p class="description" style="max-width:900px;margin:16px 0;">
			<?php esc_html_e( 'What this site holds in each language, read from WPML. Only the post types and the taxonomies WPML is set to translate appear here — a translation WPML would not link is one nobody would ever see.', 'dazont-ecom' ); ?>
			<a href="<?php echo esc_url( add_query_arg( [ 'page' => class_exists( 'DZE_Marketing_Ai' ) ? DZE_Marketing_Ai::MENU_SLUG : 'dazont-ecom-ai', 'tab' => 'translate' ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'The prompt, the glossary and the fields are under Settings → Translation.', 'dazont-ecom' ); ?></a>
		</p>
		<?php if ( ! $scope ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'WPML is not set to translate any post type or taxonomy on this site. Open WPML → Settings and say what should be translated; this screen follows that answer and never overrides it.', 'dazont-ecom' ); ?>
			</p></div>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped" style="max-width:980px;margin-bottom:24px;">
			<thead><tr>
				<th><?php esc_html_e( 'What', 'dazont-ecom' ); ?></th>
				<?php foreach ( $langs as $l ) : ?>
					<!-- THE FLAG SAYS THE LANGUAGE, and it already carries the
					     code inside it. Printed with the code again beside it
					     and the native name after that, one language was said
					     three times on one line: "n'afficher que le drapeau +
					     code sans code écrit en dur. Partout." -->
					<th style="width:120px;text-align:right;">
						<?php echo wp_kses_post( DZE_Wpml::flag_html( (string) $l['code'] ) ); ?>
					</th>
				<?php endforeach; ?>
				<th style="width:120px;"></th>
			</tr></thead>
			<tbody>
			<?php $waiting = self::review_counts(); ?>
			<?php foreach ( $scope as $key => $one ) : ?>
				<?php
				$counts = self::counts_for( $one );
				$marked = self::marked_for( $one );
				$total  = (int) ( $counts[ $src ] ?? 0 );
				$held   = (int) ( $waiting[ DZE_Wpml::element_name( (string) $one['kind'], (string) $one['type'] ) ] ?? 0 );
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $one['label'] ); ?></strong>
						<?php if ( ! empty( $one['attr'] ) ) : ?>
							<span class="description"><?php esc_html_e( '· product attribute', 'dazont-ecom' ); ?></span>
						<?php endif; ?>
						<br /><span class="description"><?php echo esc_html( sprintf( /* translators: %s: number of objects */ __( '%s in the main language', 'dazont-ecom' ), number_format_i18n( $total ) ) ); ?></span>
					</td>
					<?php foreach ( $langs as $l ) : ?>
						<?php
						$code = (string) $l['code'];
						$have = (int) ( $counts[ $code ] ?? 0 );
						$miss = max( 0, $total - $have );
						?>
						<td style="text-align:right;">
							<?php if ( $code === $src ) : ?>
								<span class="description"><?php esc_html_e( 'source', 'dazont-ecom' ); ?></span>
							<?php else : ?>
								<strong><?php echo esc_html( number_format_i18n( $have ) ); ?></strong>
								<?php if ( $miss ) : ?>
									<br /><span style="color:#b32d2e;"><?php echo esc_html( sprintf( /* translators: %s: number missing */ __( '%s missing', 'dazont-ecom' ), number_format_i18n( $miss ) ) ); ?></span>
								<?php endif; ?>
								<?php $due = (int) ( $marked[ $code ] ?? 0 ); ?>
								<?php if ( $due ) : ?>
									<!-- WPML'S OWN COUNT, and it is a different pile
									     from the missing one: these exist and WPML
									     wants them done again. -->
									<br /><span style="color:#8a6d00;"><?php echo esc_html( sprintf( /* translators: %s: number WPML marked */ __( '%s to update', 'dazont-ecom' ), number_format_i18n( $due ) ) ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
					<td>
						<a class="button" href="<?php echo esc_url( self::url( [ 'tab' => 'dashboard', 'scope' => $key ] ) ); ?>"><?php esc_html_e( 'Choose a batch', 'dazont-ecom' ); ?></a>
						<?php if ( $held ) : ?>
							<!-- WHAT CAME BACK, ON THE ROW IT WAS SENT FROM. A
							     batch that finishes and leaves the screen as it
							     was is a press nobody can tell worked. -->
							<br /><a href="<?php echo esc_url( self::url( [ 'tab' => 'review' ] ) ); ?>" style="display:inline-block;margin-top:6px;">
								<?php echo esc_html( sprintf( /* translators: %s: number waiting */ _n( '%s to review', '%s to review', $held, 'dazont-ecom' ), number_format_i18n( $held ) ) ); ?>
							</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<!-- WHAT IS ACTUALLY TRANSLATED, PER KIND OF CONTENT — and what is
		     not. This was a row of tick boxes on the settings page: a decision
		     nobody should have to take, listing a product's fields whatever
		     was being looked at. It is a reading now, and WPML's rules are
		     what it reads. -->
		<h2 style="margin-top:26px;"><?php esc_html_e( 'What gets translated', 'dazont-ecom' ); ?></h2>
		<p class="description" style="max-width:900px;">
			<?php esc_html_e( 'Per kind of content, field by field, read from WPML\'s own rules. A field WPML is set to copy from the original is left alone here — writing it would only be overwritten on the next sync.', 'dazont-ecom' ); ?>
		</p>
		<?php foreach ( $scope as $one ) : ?>
			<?php $rows = DZE_Translate::field_report( (string) $one['kind'], (string) $one['type'] ); ?>
			<details style="max-width:980px;margin-bottom:6px;border:1px solid #dcdcde;background:#fff;border-radius:3px;">
				<summary style="padding:8px 12px;cursor:pointer;font-weight:600;"><?php echo esc_html( $one['label'] ); ?></summary>
				<table class="widefat" style="border:0;border-top:1px solid #dcdcde;">
					<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td style="width:220px;"><strong><?php echo esc_html( $r['label'] ); ?></strong>
								<?php if ( '' !== $r['key'] && $r['label'] !== $r['key'] ) : ?>
									<br /><code style="font-size:11px;"><?php echo esc_html( $r['key'] ); ?></code>
								<?php endif; ?>
							</td>
							<td style="color:<?php echo esc_attr( 'ok' === $r['tone'] ? '#0a7040' : ( 'warn' === $r['tone'] ? '#b32d2e' : ( 'gap' === $r['tone'] ? '#8a6d00' : '#646970' ) ) ); ?>;">
								<?php echo esc_html( $r['said'] ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endforeach; ?>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$want = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : '';
		if ( isset( $scope[ $want ] ) ) {
			self::pick_body( $want, $scope[ $want ] );
		}
	}

	/**
	 * THE BATCH: what is short, ticked, and sent.
	 *
	 * A page at a time, with the state of each object in each language read
	 * from the register — which is the only thing that can tell "translated and
	 * current" from "translated and the words have moved since". WPML's own
	 * mark cannot: it is raised by a renamed category.
	 */
	public static function pick_body( string $key, array $scope ): void {
		$src   = DZE_Wpml::default_language();
		$langs = [];
		foreach ( DZE_Wpml::get_active_languages() as $l ) {
			if ( (string) $l['code'] !== $src ) {
				$langs[ (string) $l['code'] ] = (string) $l['native_name'];
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$paged   = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$all     = ! empty( $_GET['all'] );
		$per     = 25;
		// THE NARROWING HAPPENS IN THE QUERY THAT PAGES. Filtered after the
		// paging, the pager counted the whole catalogue and the page showed
		// two rows.
		// ARMED ON ONE OBJECT. "Translate with Dazont Ecom" in WPML's own
		// Language box opens this screen rather than running anything, so the
		// screen has to show THAT object — ticked, with nothing else in the
		// way. A button whose destination is a list of nine hundred rows is a
		// button that sent you looking.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$only    = isset( $_GET['only'] ) ? self::from_ref( sanitize_text_field( wp_unslash( $_GET['only'] ) ) ) : [];
		if ( $only ) {
			$objects = [ $only ];
			$found   = 1;
			$exact   = true;
			$pages   = 1;
		} else {
			$page    = self::todo_page( $scope, $src, array_keys( $langs ), $paged, $per, ! $all );
			$exact   = null !== $page;
			[ $objects, $found ] = $exact ? $page : self::page_of( $scope, $src, $paged, $per );
			$pages   = (int) ceil( $found / $per );
		}
		// WPML'S ANSWER FOR THE WHOLE PAGE, in one query rather than one a row.
		$marks   = self::page_marks( $objects );
		?>
		<h2 style="margin-top:28px;"><?php echo esc_html( sprintf( /* translators: %s: what is being translated */ __( 'Send a batch — %s', 'dazont-ecom' ), $scope['label'] ) ); ?></h2>
		<?php if ( ! $langs ) : ?>
			<p><?php esc_html_e( 'This site has one language. There is nothing to translate into.', 'dazont-ecom' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<div class="dze-tr-pick" data-scope="<?php echo esc_attr( $key ); ?>">
			<p class="dze-tr-langs">
				<strong><?php esc_html_e( 'Into', 'dazont-ecom' ); ?></strong>
				<?php foreach ( $langs as $code => $name ) : ?>
					<label class="dze-cb-check" title="<?php echo esc_attr( $name ); ?>"><input type="checkbox" class="dze-tr-lang" value="<?php echo esc_attr( $code ); ?>" checked />
						<span><?php echo wp_kses_post( DZE_Wpml::flag_html( $code ) ); ?></span></label>
				<?php endforeach; ?>
			</p>
			<table class="widefat striped" style="max-width:980px;">
				<thead><tr>
					<td class="check-column"><input type="checkbox" id="dze-tr-all" /></td>
					<th><?php esc_html_e( 'Name', 'dazont-ecom' ); ?></th>
					<th style="width:340px;"><?php esc_html_e( 'Where it stands', 'dazont-ecom' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $objects ) : ?>
					<!-- AN EMPTY ANSWER SAYS WHICH EMPTY IT IS. "Nothing here"
					     over a catalogue of two thousand reads as a broken
					     screen; nothing to do and nothing at all are two
					     different answers and the way out of each differs. -->
					<tr><td colspan="3">
						<?php if ( $exact && ! $all ) : ?>
							<?php esc_html_e( 'Nothing needs work here: every one of these is translated and WPML is satisfied with it.', 'dazont-ecom' ); ?>
							<a href="<?php echo esc_url( self::url( [ 'tab' => 'dashboard', 'scope' => $key, 'all' => 1 ] ) ); ?>"><?php esc_html_e( 'Show them all anyway', 'dazont-ecom' ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'Nothing of this kind in the main language yet.', 'dazont-ecom' ); ?>
						<?php endif; ?>
					</td></tr>
				<?php endif; ?>
				<?php foreach ( $objects as $o ) : ?>
					<?php $state = self::state_of( $o, array_keys( $langs ), $marks ); ?>
					<tr class="dze-tr-row" data-ref="<?php echo esc_attr( self::ref( $o ) ); ?>">
						<td class="check-column"><input type="checkbox" class="dze-tr-pickone" <?php checked( (bool) $only ); ?> /></td>
						<td>
							<a href="<?php echo esc_url( self::obj_edit_url( $o ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( self::obj_label( $o ) ); ?></a>
						</td>
						<td class="dze-tr-state">
							<?php foreach ( $state as $code => $said ) : ?>
								<?php
								// WPML'S OWN GESTURE: the plus makes the missing
								// translation, the arrows bring an out-of-date one
								// back. "Avec le bouton + pour créer une traduction
								// d'une langue précise. Le bouton actualiser pour
								// actualiser une traduction qui n'est plus à jour."
								// A chip WPML is satisfied with is not a button:
								// there is nothing to press it for.
								$dze_act = in_array( $said, [ 'missing', 'stale', 'noise' ], true );
								?>
								<?php if ( $dze_act ) : ?>
									<button type="button" class="dze-tr-chip is-<?php echo esc_attr( $said ); ?> dze-tr-one"
										data-lang="<?php echo esc_attr( (string) $code ); ?>"
										title="<?php echo esc_attr( sprintf(
											/* translators: 1: what the language needs, 2: the language */
											'missing' === $said
												? __( 'Translate this one into %2$s — it lands in "To review", nothing is written yet', 'dazont-ecom' )
												: __( 'Bring the %2$s translation up to date — it lands in "To review", nothing is written yet', 'dazont-ecom' ),
											self::state_said( $said ),
											strtoupper( (string) $code )
										) ); ?>">
										<?php echo wp_kses_post( DZE_Wpml::flag_html( (string) $code ) ); ?>
										<span class="dashicons <?php echo esc_attr( self::state_icon( $said ) ); ?>" aria-hidden="true"></span>
										<?php echo esc_html( self::state_said( $said ) ); ?>
									</button>
								<?php else : ?>
									<span class="dze-tr-chip is-<?php echo esc_attr( $said ); ?>" title="<?php echo esc_attr( self::state_said( $said ) ); ?>">
										<?php echo wp_kses_post( DZE_Wpml::flag_html( (string) $code ) ); ?>
										<span class="dashicons <?php echo esc_attr( self::state_icon( $said ) ); ?>" aria-hidden="true"></span>
										<?php echo esc_html( self::state_said( $said ) ); ?>
									</span>
								<?php endif; ?>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $only ) : ?>
				<p class="description">
					<?php esc_html_e( 'One object, opened from its own edit screen and already ticked. Nothing is sent until you press the button below.', 'dazont-ecom' ); ?>
					<a href="<?php echo esc_url( self::url( [ 'tab' => 'dashboard', 'scope' => $key ] ) ); ?>"><?php esc_html_e( 'Show everything that needs work', 'dazont-ecom' ); ?></a>
				</p>
			<?php elseif ( $exact && $objects ) : ?>
				<!-- WHAT THE LIST IS, said once, with the other reading one
				     press away. The count in the pager and the rows under it
				     answer this same sentence. -->
				<p class="description">
					<?php if ( $all ) : ?>
						<?php echo esc_html( sprintf( /* translators: %s: number of objects */ _n( '%s in the main language — everything, including what WPML is satisfied with.', '%s in the main language — everything, including what WPML is satisfied with.', $found, 'dazont-ecom' ), number_format_i18n( $found ) ) ); ?>
						<a href="<?php echo esc_url( self::url( [ 'tab' => 'dashboard', 'scope' => $key ] ) ); ?>"><?php esc_html_e( 'Only what needs work', 'dazont-ecom' ); ?></a>
					<?php else : ?>
						<?php echo esc_html( sprintf( /* translators: %s: number of objects */ _n( '%s needs work: a language missing, or WPML asking for it again.', '%s need work: a language missing, or WPML asking for it again.', $found, 'dazont-ecom' ), number_format_i18n( $found ) ) ); ?>
						<a href="<?php echo esc_url( self::url( [ 'tab' => 'dashboard', 'scope' => $key, 'all' => 1 ] ) ); ?>"><?php esc_html_e( 'Show them all', 'dazont-ecom' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<?php if ( $pages > 1 ) : ?>
				<p class="tablenav-pages" style="margin:10px 0;">
					<?php
					echo wp_kses_post( paginate_links( [
						'base'    => esc_url_raw( self::url( array_merge(
							[ 'tab' => 'dashboard', 'scope' => $key, 'paged' => '%#%' ],
							$all ? [ 'all' => 1 ] : []
						) ) ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					] ) ?: '' );
					?>
				</p>
			<?php endif; ?>
			<p class="dze-cb-actions">
				<button type="button" class="button button-primary button-hero" id="dze-tr-send"><?php esc_html_e( 'Translate the ticked', 'dazont-ecom' ); ?></button>
				<span id="dze-tr-sendstate" class="description"></span>
			</p>
			<div class="dze-cx-prog" id="dze-tr-prog" style="display:none;">
				<div class="dze-cb-bar"><div class="dze-cb-fill"></div></div>
				<p><strong id="dze-tr-progcount"></strong> <span id="dze-tr-progstep" class="description"></span></p>
			</div>
		</div>
		<?php
	}

	/**
	 * ONE PAGE OF WHAT NEEDS WORK, asked of WPML's own tables.
	 *
	 * The list used to page EVERY object of the kind and then drop, row by
	 * row, the ones WPML is satisfied with — so the pager counted the whole
	 * catalogue while the page showed two lines: "page 1 à 3 mais seulement 2
	 * lignes sont visibles. C'est bugé ?" It was. A figure and the rows under
	 * it answer the same question, so the narrowing happens IN the query that
	 * pages, never after it.
	 *
	 * Needs work = a target language with no row at all, or a row WPML has
	 * marked `needs_update`. Anything else is WPML being satisfied, and this
	 * module has nothing to say over it.
	 *
	 * @param string[] $targets The languages this screen is about to write.
	 * @return array{0:array<int,array>,1:int}|null NULL when WPML's tables
	 *         cannot be read — the caller then pages everything instead,
	 *         which is wrong about the count and right about the rows.
	 */
	private static function todo_page( array $scope, string $src, array $targets, int $paged, int $per, bool $todo_only = true ): ?array {
		global $wpdb;
		if ( ! $wpdb || ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() || ! $targets ) {
			return null;
		}
		$tr = $wpdb->prefix . 'icl_translations';
		$st = $wpdb->prefix . 'icl_translation_status';
		if ( ! DZE_Wpml::has_table( $tr ) ) {
			return null;
		}
		$name = DZE_Wpml::element_name( (string) $scope['kind'], (string) $scope['type'] );
		if ( '' === $name || '' === $src ) {
			return null;
		}
		$codes = [];
		foreach ( $targets as $code ) {
			$code = strtolower( trim( (string) $code ) );
			if ( '' !== $code ) {
				$codes[ $code ] = true;
			}
		}
		$codes = array_keys( $codes );
		$in    = "'" . implode( "','", array_map( static fn( $c ) => esc_sql( $c ), $codes ) ) . "'";
		$want  = count( $codes );
		$has_s = DZE_Wpml::has_table( $st );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WPML's own tables; language codes escaped above, everything else prepared.
		$term  = ( 'term' === $scope['kind'] );
		$join  = $term
			? "INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = src.element_id
			   INNER JOIN {$wpdb->terms} tm ON tm.term_id = tt.term_id"
			// A draft in the bin is not a page of this shop, and a trashed one
			// offered as work to do is work nobody wants.
			: "INNER JOIN {$wpdb->posts} p ON p.ID = src.element_id
			      AND p.post_status IN ('publish','draft','pending','private')";
		$pick  = $term ? 'tt.term_id' : 'src.element_id';
		$order = $term ? 'tm.name' : 'p.post_title';
		$marks = $has_s ? "LEFT JOIN {$st} s ON s.translation_id = t.translation_id" : '';
		$need  = $has_s ? "MAX( COALESCE( s.needs_update, 0 ) ) = 1" : '0 = 1';
		$body  = "FROM {$tr} src
			{$join}
			LEFT JOIN {$tr} t ON t.trid = src.trid AND t.element_id <> src.element_id
			      AND t.element_type = src.element_type AND t.language_code IN ( {$in} )
			{$marks}
			WHERE src.element_type = %s AND src.language_code = %s
			GROUP BY src.element_id, {$pick}, {$order}"
			// "SHOW THEM TOO" IS THE SAME QUERY WITHOUT THE NARROWING, never a
			// second reading: two readings of one list is how a count and the
			// rows under it start disagreeing.
			. ( $todo_only ? " HAVING COUNT( DISTINCT t.language_code ) < {$want} OR {$need}" : '' );
		$found = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM ( SELECT src.element_id {$body} ) dze_todo",
			$name,
			$src
		) );
		$ids = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT {$pick} AS dze_id {$body} ORDER BY {$order} ASC LIMIT %d OFFSET %d",
			$name,
			$src,
			$per,
			max( 0, ( $paged - 1 ) * $per )
		) );
		// phpcs:enable
		$out = [];
		foreach ( $ids as $id ) {
			$o = self::obj( $term ? 'term' : 'post', (int) $id, (string) $scope['type'] );
			if ( $o ) {
				$out[] = $o;
			}
		}
		return [ $out, $found ];
	}

	/** One page of objects of a kind, in the source language. */
	private static function page_of( array $scope, string $src, int $paged, int $per ): array {
		if ( 'term' === $scope['kind'] ) {
			$args  = [
				'taxonomy'   => $scope['type'],
				'hide_empty' => false,
				'number'     => $per,
				'offset'     => ( $paged - 1 ) * $per,
				'orderby'    => 'name',
			];
			$terms = get_terms( $args );
			$found = (int) wp_count_terms( [ 'taxonomy' => $scope['type'], 'hide_empty' => false ] );
			$out   = [];
			foreach ( ( is_array( $terms ) ? $terms : [] ) as $t ) {
				$o = self::obj( 'term', (int) $t->term_id, (string) $scope['type'] );
				// Only the ones written in the source language: a translation
				// offered as something to translate is a loop.
				if ( $o && self::obj_language( $o ) === $src ) {
					$out[] = $o;
				}
			}
			return [ $out, $found ];
		}
		$q = new WP_Query( [
			'post_type'      => $scope['type'],
			'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page' => $per,
			'paged'          => $paged,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => false,
			// WPML narrows this query itself on an admin screen, where its
			// hooks are loaded; the language is checked per row below in any
			// case, so a request where it does not is still right.
		] );
		$out = [];
		foreach ( $q->posts as $p ) {
			$o = self::obj( 'post', (int) $p->ID, (string) $scope['type'] );
			if ( $o && self::obj_language( $o ) === $src ) {
				$out[] = $o;
			}
		}
		return [ $out, (int) $q->found_posts ];
	}

	/**
	 * WHERE ONE OBJECT STANDS IN EACH LANGUAGE — WPML FIRST.
	 *
	 * "Tout est marqué 'words have moved'." It was, and the reading was ours
	 * alone: the register lives on the translation, and ten thousand
	 * translations made through a spreadsheet in 2025 have none, so every
	 * field of every one of them looked new. True about our register, useless
	 * about the shop.
	 *
	 * WPML is the one that knows whether a translation is owed. Our register
	 * answers the SECOND question, and it is the whole value of this module:
	 * of the ones WPML marked, which have words that really moved, and which
	 * were marked because somebody renamed a category — those cost nothing and
	 * can be closed on the spot.
	 *
	 * @param array<int,array<string,string>> $marks WPML's answer for this page,
	 *        read once in `page_marks()`; [] means "ask nothing, assume nothing".
	 * @return array<string,string> language => 'missing'|'stale'|'noise'|'done'
	 */
	public static function state_of( array $o, array $langs, array $marks = [] ): array {
		$out  = [];
		$mine = $marks[ self::element_id_of( $o ) ] ?? null;
		foreach ( $langs as $code ) {
			$code = (string) $code;
			if ( null !== $mine ) {
				// WPML has been asked. No row for that language means no
				// translation at all; a row that is not marked means WPML is
				// satisfied, and this module has nothing to say over it.
				if ( ! isset( $mine[ $code ] ) ) {
					$out[ $code ] = 'missing';
					continue;
				}
				if ( 'done' === $mine[ $code ] ) {
					$out[ $code ] = 'done';
					continue;
				}
				// Marked. Now the register: what actually moved.
				$out[ $code ] = self::obj_stale( $o, $code ) ? 'stale' : 'noise';
				continue;
			}
			// WPML could not be asked — no tables, or a shop without them.
			// Falling through to "everything is stale" is what made the screen
			// unreadable, so the honest answer is the one thing still knowable:
			// is there a translation at all.
			$out[ $code ] = self::obj_translation( $o, $code ) ? 'done' : 'missing';
		}
		return $out;
	}

	/** WPML indexes a post by its id and a term by its TERM TAXONOMY id. */
	public static function element_id_of( array $o ): int {
		return 'term' === ( $o['kind'] ?? 'post' )
			? DZE_Wpml::term_element_id( (int) $o['id'], (string) $o['type'] )
			: (int) $o['id'];
	}

	/** WPML's answer for a whole page of objects, in one query. */
	public static function page_marks( array $objects ): array {
		if ( ! $objects ) {
			return [];
		}
		$first = $objects[0];
		$ids   = array_map( [ __CLASS__, 'element_id_of' ], $objects );
		return DZE_Wpml::translation_marks(
			$ids,
			DZE_Wpml::element_name( (string) $first['kind'], (string) $first['type'] )
		);
	}

	/**
	 * Those four answers in words. In PHP: hard-coded in the JavaScript they
	 * were English on every shop.
	 */
	/**
	 * THE SAME THREE SYMBOLS WPML PUTS IN ITS OWN LANGUAGE COLUMNS.
	 *
	 * "Utiliser les symboles wpml servant déjà à ilustrer ces status." A plus
	 * for a translation that does not exist, a pencil for one WPML is happy
	 * with, two arrows for one it wants done again — the three marks anybody
	 * who has used WPML for a week already reads without thinking. They are
	 * drawn from Dashicons, which WordPress ships on every admin screen:
	 * WPML's own icon font is loaded only where WPML enqueues it, and a symbol
	 * that renders as a blank square on half the screens is worse than no
	 * symbol at all.
	 *
	 * The word stays beside it. A lone icon is a symbol you have to learn, and
	 * the fourth state is one WPML has no mark for: it asks for the update,
	 * and this module is the only thing on the site that can say not one word
	 * has moved.
	 */
	public static function state_icon( string $state ): string {
		$icons = [
			'missing' => 'dashicons-plus-alt2',
			'stale'   => 'dashicons-update',
			'noise'   => 'dashicons-update',
			'done'    => 'dashicons-edit',
		];
		return (string) ( $icons[ $state ] ?? 'dashicons-minus' );
	}

	public static function state_said( string $state ): string {
		$words = [
			'missing' => __( 'not translated', 'dazont-ecom' ),
			'stale'   => __( 'words have moved', 'dazont-ecom' ),
			// THE MODULE'S WHOLE POINT, said in three words: WPML wants this
			// one done again and not one word of it has changed. It costs
			// nothing to close, and the screen should say so rather than
			// charging for it.
			'noise'   => __( 'marked, nothing moved', 'dazont-ecom' ),
			'done'    => __( 'up to date', 'dazont-ecom' ),
		];
		return (string) ( $words[ $state ] ?? $state );
	}

	// =========================================================================
	// To review — what a batch produced, read before it lands
	// =========================================================================

	public static function review_body(): void {
		$rows = self::review_list();
		?>
		<p class="description" style="max-width:900px;margin:16px 0;">
			<?php esc_html_e( 'What the last batches produced. Nothing here has been written to the site yet: open one, read it beside the original and beside what the translation holds today, and accept or refuse it field by field.', 'dazont-ecom' ); ?>
		</p>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'Nothing is waiting. Send a batch from the Dashboard and what comes back lands here.', 'dazont-ecom' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped" style="max-width:980px;">
			<thead><tr>
				<th><?php esc_html_e( 'Name', 'dazont-ecom' ); ?></th>
				<th style="width:150px;"><?php esc_html_e( 'What', 'dazont-ecom' ); ?></th>
				<th style="width:200px;"><?php esc_html_e( 'Languages waiting', 'dazont-ecom' ); ?></th>
				<th style="width:180px;"></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr class="dze-tr-wrow" data-ref="<?php echo esc_attr( self::ref( $r ) ); ?>">
					<td><strong><?php echo esc_html( $r['label'] ); ?></strong></td>
					<td><span class="description"><?php echo esc_html( self::type_label( $r ) ); ?></span></td>
					<td>
						<?php foreach ( $r['langs'] as $code ) : ?>
							<span class="dze-tr-chip is-stale"><?php echo esc_html( strtoupper( (string) $code ) ); ?></span>
						<?php endforeach; ?>
					</td>
					<td>
						<button type="button" class="button button-primary dze-tr-open"><?php esc_html_e( 'Review', 'dazont-ecom' ); ?></button>
						<button type="button" class="button dze-tr-refuse"><?php esc_html_e( 'Refuse', 'dazont-ecom' ); ?></button>
					</td>
				</tr>
				<tr class="dze-tr-panel" data-ref="<?php echo esc_attr( self::ref( $r ) ); ?>" style="display:none;"><td colspan="4"></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** What kind of thing a waiting row is, in the shop's own words. */
	public static function type_label( array $o ): string {
		$scope = self::scope();
		$key   = ( $o['kind'] ?? 'post' ) . ':' . ( $o['type'] ?? '' );
		return (string) ( $scope[ $key ]['label'] ?? ( $o['type'] ?? '' ) );
	}
}
