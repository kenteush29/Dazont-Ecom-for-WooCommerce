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
		$label   = DZE_Screens::label( 'translations' );
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

	/**
	 * THREE VIEWS, and each one is a different question.
	 *
	 * Where the site stands · the batch you are choosing · what came back and
	 * wants a yes or a no. The batch used to unfold UNDER the dashboard table,
	 * so choosing what to send meant reading the figures again every time:
	 * "Send a batch — incomplet et pas bon pour l'UI. Ici je verrais plutôt une
	 * liste séparée comme avec les produits."
	 *
	 * The batch tab carries no figure: it is a workbench, not a store, and a
	 * number there would have to answer "for which kind?" — a question the tab
	 * bar cannot ask.
	 */
	public static function tabs(): array {
		// Named by the catalogue; the figures are this screen's own.
		$names = DZE_Screens::tabs_of( 'translations' );
		return [
			// A BADGE MEANS "ACT ON ME", NEVER "HERE IS A NUMBER": this one
			// counted the kinds of content in scope — five, for ever.
			'dashboard' => [ 'label' => (string) ( $names['dashboard'] ?? '' ), 'n' => null ],
			'batch'     => [ 'label' => (string) ( $names['batch'] ?? '' ), 'n' => null ],
			'review'    => [ 'label' => (string) ( $names['review'] ?? '' ), 'n' => self::review_count() ],
			'done'      => [ 'label' => (string) ( $names['done'] ?? '' ), 'n' => null ],
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
		echo '<h1>' . esc_html( DZE_Screens::label( 'translations' ) ) . '</h1>';
		// THE SWITCH FIRST, ON THE SCREEN IT IS ABOUT — AND ONLY THERE.
		//
		// "Pourquoi il n'est pas placé en haut ce bloc ? C'est l'équivalent de
		// la cerise sur le gâteau, pas l'assiette en bas de page." So it is a
		// thin bar under the title. But printed BEFORE the tab strip it stood
		// on every tab, including the editor of ONE product — "attention ces
		// blocs sont aussi visibles sur des pages hors sujet comme le batch
		// onglet". A switch for a nightly pass, over a product somebody is
		// translating by hand, is furniture in the way. The Discounts screen
		// already scoped its own the same way, to the events side only.
		//
		// The dashboard is where the module is looked at as a whole; that is
		// where the decision about the whole belongs.
		if ( 'dashboard' === $tab && class_exists( 'DZE_Automation' ) ) {
			DZE_Automation::panel_form( [ 'translate' ], __( 'Runs by itself', 'dazont-ecom' ) );
		}
		$strip = [];
		foreach ( $tabs as $id => $one ) {
			$strip[ (string) $id ] = [
				'label' => (string) $one['label'],
				'url'   => self::url( [ 'tab' => $id ] ),
				'n'     => $one['n'],
			];
		}
		echo wp_kses_post( DZE_Screens::strip( $strip, $tab ) );
		// AND THE INSTRUCTIONS UNDER THE TABS, on every one of them: a bad
		// translation is noticed while reading one, not while browsing
		// preferences two menus away.
		DZE_Translate::instructions_panel();
		if ( ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			// ONE SENTENCE OF WARNING IS THE WHOLE OF IT, and it says the one
			// thing to do rather than explaining a mechanism.
			echo '<div class="notice notice-warning inline" style="margin:16px 0;"><p>'
				. esc_html__( 'WPML is not running. This module reads its languages, its translation groups and its settings from WPML — with WPML off there is no second language to translate into.', 'dazont-ecom' )
				. '</p></div></div>';
			return;
		}
		if ( 'done' === $tab ) {
			self::done_body();
		} elseif ( 'review' === $tab ) {
			self::review_body();
		} elseif ( 'batch' === $tab ) {
			// A REF IS AN OBJECT, AND AN OBJECT HAS ONE SCREEN. The list and
			// the editor are the same tab because they are the same subject:
			// what to translate, and translating it.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
			if ( ! empty( $_GET['ref'] ) ) {
				self::editor_body();
			} else {
				self::batch_body();
			}
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
			<a href="<?php echo esc_url( DZE_Screens::url( 'settings', 'translate' ) ); ?>"><?php esc_html_e( 'The prompt and the model are under Settings → Translation.', 'dazont-ecom' ); ?></a>
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
						<a class="button" href="<?php echo esc_url( self::url( [ 'tab' => 'batch', 'scope' => $key ] ) ); ?>"><?php esc_html_e( 'Choose a batch', 'dazont-ecom' ); ?></a>
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
	}

	// =========================================================================
	// THE TRANSLATION EDITOR — one screen per object, and it is WPML's own
	//
	// "Je veux un seul écran pour chaque type de post. Comme le fait wpml ! Il
	// ne nous dit pas qu'il copie les variations ou je ne sais quoi. wpml c'est
	// 1/ post non traduit ou traduction pas à jour 2/ envoi en trad 3/ trad
	// automatique ou sur écran de trad spécial individuel de tous les champs
	// 4/ publication."
	//
	// That is four steps and THREE screens, and this is the third. What stood
	// here before was a popup of ours on the product page, with a block
	// reporting whether WooCommerce Multilingual had copied the variations and
	// a button to make it try again: the plugin narrating its own plumbing on
	// the owner's screen. WPML never does that, and neither does this now — the
	// sync is a consequence of saving, said only when it fails.
	//
	// Reached from every one of the three lists and from WPML's own Language
	// box, and there is no second per-object surface anywhere: two surfaces for
	// one job drift apart, and one of them silently loses text.
	// =========================================================================

	/** The object this screen is about, and the language it is being read in. */
	private static function editor_args(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$o = self::from_ref( isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : '' );
		if ( ! $o ) {
			return [ [], '' ];
		}
		$targets = self::obj_targets( $o );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$want = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		if ( isset( $targets[ $want ] ) ) {
			return [ $o, $want ];
		}
		// OPENED WITHOUT A LANGUAGE, IT OPENS ON THE ONE THAT NEEDS WORK. A
		// screen that opens on a language already finished is a screen that
		// asks a question nobody came with.
		$marks = self::page_marks( [ $o ] );
		$state = self::state_of( $o, array_keys( $targets ), $marks );
		foreach ( $state as $code => $said ) {
			if ( 'done' !== $said ) {
				return [ $o, (string) $code ];
			}
		}
		return [ $o, (string) ( array_key_first( $targets ) ?? '' ) ];
	}

	/** Where this screen lives, for one object in one language. */
	public static function editor_url( array $o, string $lang = '' ): string {
		$args = [ 'tab' => 'batch', 'ref' => self::ref( $o ) ];
		if ( '' !== $lang ) {
			$args['lang'] = $lang;
		}
		return self::url( $args );
	}

	public static function editor_body(): void {
		[ $o, $lang ] = self::editor_args();
		if ( ! $o || '' === $lang ) {
			echo '<div class="notice notice-warning inline" style="margin:16px 0;"><p>'
				. esc_html__( 'That is not something WPML translates on this site, or it has no other language to be translated into.', 'dazont-ecom' )
				. '</p></div>';
			return;
		}
		$targets = self::obj_targets( $o );
		// Every language's state at once: the one being edited wears it on its
		// chip, and the others wear theirs on the way to their own editor.
		$states  = self::state_of( $o, array_keys( $targets ), self::page_marks( [ $o ] ) );
		$state   = (string) ( $states[ $lang ] ?? 'missing' );
		$source  = self::obj_read( $o );
		$target  = self::obj_translation( $o, $lang );
		$current = $target ? self::obj_read( array_merge( $o, [ 'id' => $target ] ) ) : [];
		$made    = (array) ( self::waiting( $o )['langs'][ $lang ] ?? [] );
		$labels  = self::labels_for( $o );
		// WHICH FIELDS MOVED — the module's whole value, and "Translate
		// automatically" sends only those. Unsaid, a field it did not refill
		// read as a field it forgot.
		// HAS THIS TRANSLATION EVER BEEN MADE HERE? Without the register this
		// module writes when it saves, there is nothing to compare against —
		// and every field came back marked "words have moved since the last
		// translation" on a product where nothing had moved at all. Not
		// knowing and having changed are two different things and must not
		// wear the same sentence.
		$known   = $target ? (bool) DZE_Translate::src_map( $target, $o ) : false;
		$moved   = ( $target && 'missing' !== $state && $known ) ? self::obj_stale( $o, $lang ) : [];
		// WHOSE TRANSLATION THIS IS. One made elsewhere — a spreadsheet, a
		// hand — is replaced by a save, and the sentence saying so had been
		// registered for months and printed nowhere.
		$mine    = ! $target || '1' === self::meta_read( $o, $target, self::META_MINE );
		?>
		<p style="margin:14px 0 6px;">
			<a href="<?php echo esc_url( self::url( [ 'tab' => 'batch' ] ) ); ?>">&larr; <?php esc_html_e( 'Back to the list', 'dazont-ecom' ); ?></a>
		</p>
		<h2 style="margin:0 0 4px;">
			<?php echo esc_html( self::obj_label( $o ) ); ?>
			<?php echo wp_kses_post( DZE_Hub::obj_id( (int) $o['id'] ) ); ?>
			<a href="<?php echo esc_url( self::obj_edit_url( $o ) ); ?>" target="_blank" rel="noopener" style="font-size:13px;font-weight:400;"><?php esc_html_e( 'open it', 'dazont-ecom' ); ?> &rarr;</a>
		</h2>
		<!-- 1. WHERE THIS ONE STANDS, in one line: not translated, out of date,
		     or up to date. The same four words the lists use, from the same
		     function, so two screens can never disagree about one object. -->
		<p class="dze-tr-editstate">
			<span class="dze-tr-chip is-<?php echo esc_attr( $state ); ?>">
				<?php echo wp_kses_post( DZE_Wpml::flag_html( $lang ) ); ?>
				<span class="dashicons <?php echo esc_attr( self::state_icon( $state ) ); ?>" aria-hidden="true"></span>
				<?php echo esc_html( self::state_said( $state ) ); ?>
			</span>
			<?php if ( count( $targets ) > 1 ) : ?>
				<span class="dze-tr-langjump">
					<?php foreach ( $targets as $dze_code => $dze_name ) : ?>
						<?php if ( (string) $dze_code === $lang ) { continue; } ?>
						<?php
						// THE SAME SHAPE AS THE CHIP BESIDE IT. A bare "DE" link next
						// to "FR · up to date" was two forms for one thing; each other
						// language is a chip too, saying where it stands, and pressing
						// it opens that language's editor.
						$dze_st = (string) ( $states[ (string) $dze_code ] ?? 'missing' );
						?>
						<a class="dze-tr-chip is-<?php echo esc_attr( $dze_st ); ?>" href="<?php echo esc_url( self::editor_url( $o, (string) $dze_code ) ); ?>" title="<?php echo esc_attr( $dze_name ); ?>">
							<?php echo wp_kses_post( DZE_Wpml::flag_html( (string) $dze_code ) ); ?>
							<span class="dashicons <?php echo esc_attr( self::state_icon( $dze_st ) ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( self::state_said( $dze_st ) ); ?>
						</a>
					<?php endforeach; ?>
				</span>
			<?php endif; ?>
		</p>

		<?php if ( $target && ! $known && $current ) : ?>
			<p class="description" style="color:#50575e;"><?php esc_html_e( 'This translation was not made here, so there is no record of the words it was made from. Nothing below is marked as having moved — translating will simply send every field.', 'dazont-ecom' ); ?></p>
		<?php endif; ?>
		<?php if ( $target && ! $mine && $current ) : ?>
			<p class="description dze-tr-notmine" style="color:#8a6d00;"><?php esc_html_e( 'This translation was not written here. Saving replaces its text — read the right-hand column first.', 'dazont-ecom' ); ?></p>
		<?php endif; ?>
		<div class="dze-tr-editor" data-ref="<?php echo esc_attr( self::ref( $o ) ); ?>" data-lang="<?php echo esc_attr( $lang ); ?>">
			<!-- 2. TRANSLATE IT, or write it by hand. One button, and it says
			     what it will do rather than what it costs us to do it. -->
			<p class="dze-cb-actions">
				<button type="button" class="button button-primary" id="dze-tr-auto"
					title="<?php esc_attr_e( 'Translates the fields whose words have changed since the last time, and fills them in below. Nothing is written until you save.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Translate automatically', 'dazont-ecom' ); ?></button>
				<?php
				// THE SECOND BUTTON IS FOR JUDGING THE TRANSLATOR, not the text.
				// Changing the model or the instructions changes
				// nothing about the ORIGINAL, so the ordinary run is right to
				// answer "nothing moved" and would do so for ever. This one
				// sends every field and pays for every field, which is exactly
				// why it is second, quiet, and says so before it is pressed.
				?>
				<button type="button" class="button" id="dze-tr-auto-all"
					title="<?php esc_attr_e( 'Sends EVERY field again, even the ones that have not changed — for comparing one model or prompt against another. It costs a full translation. Nothing is written until you save.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Translate everything again', 'dazont-ecom' ); ?></button>
				<span id="dze-tr-autostate" class="description"></span>
			</p>

			<?php
			// 3. EVERY FIELD, IN PANELS RATHER THAN IN ONE LONG TABLE.
			//
			// "La tienne est très brute. Regardes peut être comment WPML
			// présente ça." WPML's own editor prints WordPress panels — the
			// same `postbox` a metabox wears — one per kind of thing, and that
			// is why it reads as part of the admin instead of as a plugin's
			// own furniture. Twelve rows of equal weight say nothing about
			// what matters; named panels do.
			//
			// The original sits beside the translation with a copy button
			// between them, which is WPML's arrangement and the reason it can
			// be judged at a glance: a translation read without its source is
			// a guess.
			$dze_groups = DZE_Translate::field_groups();
			$dze_groups['other'] = [ 'label' => __( 'Other fields', 'dazont-ecom' ), 'fields' => [] ];
			// A FIELD WITH NOTHING IN IT IS STILL AN ANSWER, and it was thrown
			// away here: "a field the original does not hold is not a
			// decision". True, and it made an empty SEO pair look exactly like
			// an SEO pair this module cannot handle — "pourquoi pas de
			// traduction des champs seo ? j'ai l'impression qu'il manque plein
			// de choses ici". Nothing on the screen could tell the two apart,
			// because absence has only one appearance.
			//
			// So every field is listed, WPML-editor fashion, and the ones with
			// no words carry the reason instead of a text box. They are still
			// not decisions — they take no room, they cannot be typed in, and
			// they are counted apart on the panel.
			$dze_bin  = [];
			$dze_why  = [];
			$dze_full = 0;
			foreach ( $labels as $dze_fid => $dze_label ) {
				$dze_bin[ DZE_Translate::field_group( (string) $dze_fid ) ][ $dze_fid ] = $dze_label;
				if ( '' === trim( (string) ( $source[ $dze_fid ] ?? '' ) ) ) {
					$dze_why[ $dze_fid ] = DZE_Translate::absent_said(
						(string) $dze_fid,
						(string) ( $o['kind'] ?? 'post' ),
						(string) ( $o['type'] ?? '' )
					);
					continue;
				}
				$dze_full++;
			}
			?>
			<?php if ( ! $dze_full ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'This one holds no text to translate.', 'dazont-ecom' ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( $dze_groups as $dze_gkey => $dze_g ) : ?>
				<?php if ( empty( $dze_bin[ $dze_gkey ] ) ) { continue; } ?>
				<div class="postbox dze-tr-panel">
					<h2 class="hndle">
						<span><?php echo esc_html( (string) $dze_g['label'] ); ?></span>
						<span class="dze-tr-panelcount">
							<?php
							// TWO FIGURES, BECAUSE THEY MEAN DIFFERENT THINGS: what
							// there is to do, and what was looked at and found empty.
							// One number over a panel of six lines, four of them
							// blank, is a number that misleads.
							$dze_pe = count( array_intersect_key( $dze_why, $dze_bin[ $dze_gkey ] ) );
							$dze_pf = count( $dze_bin[ $dze_gkey ] ) - $dze_pe;
							printf(
								/* translators: %d: how many fields in this panel carry text */
								esc_html( _n( '%d field', '%d fields', $dze_pf, 'dazont-ecom' ) ),
								$dze_pf
							);
							if ( $dze_pe ) {
								printf(
									' · %s',
									esc_html( sprintf(
										/* translators: %d: how many fields in this panel are empty */
										_n( '%d with nothing in it', '%d with nothing in them', $dze_pe, 'dazont-ecom' ),
										$dze_pe
									) )
								);
							}
							?>
						</span>
					</h2>
					<div class="inside">
						<?php foreach ( $dze_bin[ $dze_gkey ] as $dze_fid => $dze_label ) : ?>
							<?php
							$dze_src = (string) ( $source[ $dze_fid ] ?? '' );
							$dze_val = (string) ( $made[ $dze_fid ] ?? ( $current[ $dze_fid ] ?? '' ) );
							$dze_rows = max( 4, min( 24, (int) ceil( strlen( $dze_src ) / 90 ) + 2 ) );
							?>
							<?php if ( isset( $dze_why[ $dze_fid ] ) ) : ?>
								<?php
								// LISTED, AND SAID. It takes one line, it cannot be
								// typed into, and it is the difference between "this
								// module does not do SEO" and "this product has no
								// SEO text on the English original".
								?>
								<div class="dze-tr-field is-absent" data-field="<?php echo esc_attr( $dze_fid ); ?>">
									<p class="dze-tr-fname">
										<strong><?php echo esc_html( $dze_label ); ?></strong>
										<span class="dze-tr-why is-<?php echo esc_attr( (string) $dze_why[ $dze_fid ]['tone'] ); ?>"><?php echo esc_html( (string) $dze_why[ $dze_fid ]['said'] ); ?></span>
										<?php if ( 'warn' === $dze_why[ $dze_fid ]['tone'] ) : ?>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=tm/menu/settings' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'WPML → Settings → Custom Fields Translation', 'dazont-ecom' ); ?> &rarr;</a>
										<?php endif; ?>
									</p>
								</div>
								<?php continue; ?>
							<?php endif; ?>
							<div class="dze-tr-field" data-field="<?php echo esc_attr( $dze_fid ); ?>">
								<p class="dze-tr-fname">
									<strong><?php echo esc_html( $dze_label ); ?></strong>
									<?php if ( isset( $made[ $dze_fid ] ) ) : ?>
										<span class="dze-tr-tag is-new"><?php esc_html_e( 'just translated', 'dazont-ecom' ); ?></span>
									<?php elseif ( isset( $moved[ $dze_fid ] ) ) : ?>
										<span class="dze-tr-tag dze-tr-moved"><?php esc_html_e( 'the original has changed since', 'dazont-ecom' ); ?></span>
									<?php endif; ?>
									<?php
									// ONE BLOCK, ON ITS OWN. "Pour un calibrage plus
									// facile il faut un bouton traduire par bloc."
									// Judging a change to the prompt
									// meant re-sending the whole object and paying for
									// every field of it, so it was done once and never
									// again. This sends this block and nothing else,
									// and it always sends it — a field is re-run
									// precisely when it has NOT moved.
									?>
									<button type="button" class="button button-small dze-tr-block"
										title="<?php esc_attr_e( 'Translates this block on its own, and fills the box on the right. Useful for judging a change to the instructions without paying for the whole page. Nothing is written until you save.', 'dazont-ecom' ); ?>"><span class="dashicons dashicons-translation" aria-hidden="true"></span><?php esc_html_e( 'Translate this block', 'dazont-ecom' ); ?></button>
									<span class="dze-tr-blockstate description"></span>
								</p>
								<div class="dze-tr-pair">
									<div class="dze-tr-side">
										<span class="dze-tr-sidelab"><?php esc_html_e( 'Original', 'dazont-ecom' ); ?></span>
										<div class="dze-cb-nowbody"><?php echo wp_kses_post( $dze_src ); ?></div>
									</div>
									<div class="dze-tr-mid">
										<?php
										// L ICONE PLUTOT QUE LA FLECHE. « A la place de tes
										// flèches il faut un symbole de fichiers copier
										// coller. Sur WPML c est quelque chose comme ça. »
										// Une fleche dit « va a droite » ; ce bouton COPIE,
										// et deux pages superposees le disent partout.
										?><button type="button" class="button dze-tr-copy" title="<?php esc_attr_e( 'Put the original in the box on the right, to work from it', 'dazont-ecom' ); ?>" aria-label="<?php esc_attr_e( 'Copy from the original', 'dazont-ecom' ); ?>"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button>
									</div>
									<div class="dze-tr-side">
										<span class="dze-tr-sidelab"><?php echo esc_html( sprintf( /* translators: %s: the language */ __( 'In %s', 'dazont-ecom' ), (string) ( $targets[ $lang ] ?? strtoupper( $lang ) ) ) ); ?></span>
										<!-- data-was is what the translation holds today: Cancel puts it back. -->
										<textarea class="dze-tr-new<?php echo DZE_Translate::looks_html( $dze_src ) ? ' dze-tr-html' : ''; ?>" data-was="<?php echo esc_attr( (string) ( $current[ $dze_fid ] ?? '' ) ); ?>" data-src="<?php echo esc_attr( $dze_src ); ?>" rows="<?php echo esc_attr( (string) $dze_rows ); ?>"><?php echo esc_textarea( $dze_val ); ?></textarea>
									</div>
								</div>
								<?php
								// CE QUI A ETE ENVOYE POUR CE BLOC. « J'aimerais voir les
								// appels à l'IA par bloc. » Tout est deja ecrit — chaque
								// appel passe par complete(), qui garde l'echange tel que
								// le modele l'a lu — mais c'etait range dans les journaux,
								// loin des mots que l'appel a produits. Replie, parce
								// qu'on l'ouvre pour comprendre une reponse etrange, pas
								// a chaque lecture.
								$dze_calls = DZE_Translate::calls_for( $o, (string) $dze_fid );
								?>
								<?php if ( $dze_calls ) : ?>
									<details class="dze-set dze-tr-calls">
										<summary><?php
											echo esc_html( sprintf(
												/* translators: %s: how many calls */
												_n( '%s call to the model for this block', '%s calls to the model for this block', count( $dze_calls ), 'dazont-ecom' ),
												number_format_i18n( count( $dze_calls ) )
											) );
										?></summary>
										<?php foreach ( $dze_calls as $dze_call ) : ?>
											<p class="dze-tr-callhead">
												<strong><?php echo esc_html( date_i18n( (string) get_option( 'date_format' ) . ' H:i:s', (int) $dze_call['t'] ) ); ?></strong>
												· <?php echo esc_html( (string) $dze_call['model'] ); ?>
												· <?php echo esc_html( sprintf( /* translators: %s: seconds */ __( '%ss', 'dazont-ecom' ), number_format_i18n( (float) $dze_call['secs'], 1 ) ) ); ?>
											</p>
											<p class="dze-tr-calllab"><?php esc_html_e( 'The instructions it was given', 'dazont-ecom' ); ?></p>
											<pre class="dze-tr-callpre"><?php echo esc_html( (string) $dze_call['system'] ); ?></pre>
											<p class="dze-tr-calllab"><?php esc_html_e( 'The text it was given', 'dazont-ecom' ); ?></p>
											<pre class="dze-tr-callpre"><?php echo esc_html( mb_substr( (string) $dze_call['user'], 0, 4000 ) ); ?></pre>
											<p class="dze-tr-calllab"><?php esc_html_e( 'What came back', 'dazont-ecom' ); ?></p>
											<pre class="dze-tr-callpre"><?php echo esc_html( mb_substr( (string) $dze_call['got'], 0, 2000 ) ); ?></pre>
										<?php endforeach; ?>
									</details>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<?php
			// 3b. WHAT THE PRODUCT IS SOLD ALONG, and what its variations hold.
			// Neither is a field of the product: an attribute value is a term
			// shared by every product wearing it, and a variation keeps its own
			// words. Both were invisible here, so a product could read "up to
			// date" while the words a customer picks from were still English.
			$dze_attrs = DZE_Translate::attribute_objects( $o );
			$dze_local = DZE_Translate::local_attributes( $o );
			$dze_vars  = DZE_Translate::variation_tally( $o );
			?>
			<?php
			// THE VARIATIONS, AS A PANEL. WPML's own editor gives them a
			// section — "Variations data", a group per variation — and it is
			// right to: a line of small print under a heading is read by
			// nobody. What is worth showing is not their descriptions, which
			// WPML is set to leave alone on this shop, but whether the
			// translation HAS them: a variable product whose variations were
			// never built offers nothing to pick and its page says the product
			// is unavailable, while every screen here calls it translated.
			$dze_vrows = DZE_Translate::variation_rows( $o, $lang );
			$dze_vmiss = 0;
			foreach ( $dze_vrows as $dze_v ) {
				if ( ! $dze_v['target'] ) {
					$dze_vmiss++;
				}
			}
			?>
			<?php if ( $dze_vrows ) : ?>
				<div class="postbox dze-tr-panel">
					<h2 class="hndle">
						<span><?php esc_html_e( 'Variations', 'dazont-ecom' ); ?></span>
						<span class="dze-tr-panelcount">
							<?php
							printf(
								/* translators: 1: variations in all, 2: the language */
								esc_html( _n( '%1$d variation · %2$s', '%1$d variations · %2$s', count( $dze_vrows ), 'dazont-ecom' ) ),
								count( $dze_vrows ),
								esc_html(
									$dze_vmiss
										? sprintf(
											/* translators: %d: variations the translation does not have */
											_n( '%d missing', '%d missing', $dze_vmiss, 'dazont-ecom' ),
											$dze_vmiss
										)
										: __( 'all present', 'dazont-ecom' )
								)
							);
							?>
						</span>
					</h2>
					<div class="inside">
						<?php if ( $dze_vmiss ) : ?>
							<div class="notice notice-error inline dze-tr-varwarn"><p>
								<?php esc_html_e( 'This translation is missing variations. A variable product with none offers nothing to choose from, and its page tells the customer it is unavailable. Saving the translation builds them.', 'dazont-ecom' ); ?>
							</p></div>
						<?php endif; ?>
						<table class="widefat striped dze-tr-shared">
							<thead><tr>
								<th><?php esc_html_e( 'Variation', 'dazont-ecom' ); ?></th>
								<th style="width:200px;"><?php echo esc_html( sprintf( /* translators: %s: the language */ __( 'In %s', 'dazont-ecom' ), (string) ( $targets[ $lang ] ?? strtoupper( $lang ) ) ) ); ?></th>
								<th style="width:140px;"><?php esc_html_e( 'Price', 'dazont-ecom' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $dze_vrows as $dze_v ) : ?>
								<tr>
									<td><strong><?php echo esc_html( (string) $dze_v['label'] ); ?></strong></td>
									<td>
										<?php if ( $dze_v['target'] ) : ?>
											<span class="dze-tr-chip is-done">
												<span class="dashicons <?php echo esc_attr( self::state_icon( 'done' ) ); ?>" aria-hidden="true"></span>
												<?php esc_html_e( 'built', 'dazont-ecom' ); ?>
											</span>
										<?php else : ?>
											<span class="dze-tr-chip is-missing">
												<span class="dashicons <?php echo esc_attr( self::state_icon( 'missing' ) ); ?>" aria-hidden="true"></span>
												<?php esc_html_e( 'missing', 'dazont-ecom' ); ?>
											</span>
										<?php endif; ?>
									</td>
									<td><?php echo '' !== $dze_v['price'] ? wp_kses_post( wc_price( (float) $dze_v['price'] ) ) : '—'; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description dze-tr-varnote">
							<?php
							$dze_rule = DZE_Translate::variation_desc_rule();
							if ( 2 === $dze_rule ) {
								esc_html_e( 'WPML is set to translate each variation\'s own description, so those are handled in its editor, not here.', 'dazont-ecom' );
							} elseif ( 1 === $dze_rule ) {
								esc_html_e( 'WPML is set to copy each variation\'s own description across, unchanged.', 'dazont-ecom' );
							} else {
								esc_html_e( 'WPML is set to leave each variation\'s own description alone, so there is nothing of theirs to write. What a customer picks from is the attribute values below.', 'dazont-ecom' );
							}
							?>
						</p>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( $dze_attrs || $dze_local || $dze_vars['total'] ) : ?>
				<h3 style="margin:22px 0 4px;"><?php esc_html_e( 'Attributes', 'dazont-ecom' ); ?></h3>
				<?php if ( $dze_attrs ) : ?>
					<p class="description" style="margin:0 0 6px;">
						<?php esc_html_e( 'An attribute value is shared by every product that wears it: translating one here changes it everywhere it is used.', 'dazont-ecom' ); ?>
					</p>
					<table class="widefat striped dze-tr-shared">
						<thead><tr>
							<th style="width:200px;"><?php esc_html_e( 'Attribute', 'dazont-ecom' ); ?></th>
							<th style="width:220px;"><?php esc_html_e( 'Value', 'dazont-ecom' ); ?></th>
							<th><?php esc_html_e( 'Where it stands', 'dazont-ecom' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $dze_attrs as $dze_key => $dze_a ) : ?>
							<?php
							$dze_obj = (array) $dze_a['obj'];
							$dze_st  = self::state_of( $dze_obj, array_keys( $targets ) );
							?>
							<tr>
								<td><?php echo esc_html( (string) $dze_a['tax_label'] ); ?></td>
								<td><strong><?php echo esc_html( (string) $dze_a['name'] ); ?></strong></td>
								<td>
									<span class="dze-tr-langjump">
									<?php foreach ( $targets as $dze_code => $dze_name ) : ?>
										<?php $dze_one = (string) ( $dze_st[ (string) $dze_code ] ?? 'missing' ); ?>
										<a class="dze-tr-chip is-<?php echo esc_attr( $dze_one ); ?>"
											href="<?php echo esc_url( self::editor_url( $dze_obj, (string) $dze_code ) ); ?>"
											title="<?php echo esc_attr( sprintf( /* translators: 1: the value, 2: the language */ __( 'Translate “%1$s” into %2$s', 'dazont-ecom' ), (string) $dze_a['name'], (string) $dze_name ) ); ?>">
											<?php echo wp_kses_post( DZE_Wpml::flag_html( (string) $dze_code ) ); ?>
											<span class="dashicons <?php echo esc_attr( self::state_icon( $dze_one ) ); ?>" aria-hidden="true"></span>
											<?php echo esc_html( self::state_said( $dze_one ) ); ?>
										</a>
									<?php endforeach; ?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php if ( $dze_local ) : ?>
					<?php
					// WRITTEN ON THE PRODUCT, SO NOT AN OBJECT ANYBODY CAN
					// TRANSLATE. And on this catalogue every one of them picks a
					// variation, which makes translating them worse than leaving
					// them: WooCommerce matches a variation by the value STRING.
					?>
					<p class="description" style="margin:10px 0 6px;">
						<?php esc_html_e( 'These are typed into the product itself rather than picked from a shared list, so there is no object for WPML to translate. They stay in the original language.', 'dazont-ecom' ); ?>
					</p>
					<table class="widefat striped dze-tr-shared">
						<thead><tr>
							<th style="width:200px;"><?php esc_html_e( 'Attribute', 'dazont-ecom' ); ?></th>
							<th style="width:220px;"><?php esc_html_e( 'Value', 'dazont-ecom' ); ?></th>
							<th><?php esc_html_e( 'Where it stands', 'dazont-ecom' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $dze_local as $dze_l ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $dze_l['name'] ); ?></td>
								<td><strong><?php echo esc_html( implode( ' · ', (array) $dze_l['values'] ) ); ?></strong></td>
								<td>
									<?php if ( $dze_l['is_variation'] ) : ?>
										<span class="dze-tr-why is-warn"><?php esc_html_e( 'The customer picks a variation with this. WooCommerce matches a variation to its product by the exact words, so translating them would stop the Add to cart working.', 'dazont-ecom' ); ?></span>
									<?php else : ?>
										<span class="dze-tr-why"><?php esc_html_e( 'Not translated: it belongs to this product alone.', 'dazont-ecom' ); ?></span>
									<?php endif; ?>
									<br />
									<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&page=product_attributes' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Make it a global attribute so WPML can translate it', 'dazont-ecom' ); ?> &rarr;</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
			<!-- 4. PUBLISH IT, or leave it alone. Accept and refuse side by
			     side, which is what every screen in this plugin ends with. -->
			<p class="dze-cb-panelbar">
				<button type="button" class="button button-primary button-hero" id="dze-tr-publish"
					title="<?php esc_attr_e( 'Writes what is on the right onto the translation, and tells WPML it is up to date', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Save the translation', 'dazont-ecom' ); ?></button>
				<button type="button" class="button" id="dze-tr-drop"
					title="<?php esc_attr_e( 'Throws away what was translated and leaves the translation exactly as it is', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Cancel', 'dazont-ecom' ); ?></button>
				<span class="dze-cb-panelstate" id="dze-tr-publishstate"></span>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// The BATCH — the Dazont Ecom shape, the one the bulk screen already wears
	//
	// "Send a batch — incomplet et pas bon pour l'UI. Ici je verrais plutôt une
	// liste séparée comme avec les produits… Utiliser le même type de dashboard
	// que pour les bulk content generation. Ce serait parfait, on y retrouverait
	// encore une forte cohérence générale dans le plugin."
	//
	// Read top to bottom, it is the same five parts as every other screen that
	// produces something:
	//   1. what this kind of content holds today, in one line, with the figures;
	//   2. "What to translate" — one BLOCK per kind of work, its switch in its
	//      own heading and its count beside it;
	//   3. ONE button that runs what is ticked, saying nothing is ticked rather
	//      than doing nothing;
	//   4. the list, each row saying where it stands and offering to be looked
	//      at — Look for the object as it is, Review once something waits;
	//   5. accept and refuse, side by side, on the panel the row opens.
	//
	// The parts come from `DZE_Hub`, so a change to the shape reaches this
	// screen and the product one together, and there is never a second builder.
	// =========================================================================

	/** Which kind of thing this batch is about, and the menu of the others. */
	private static function batch_scope(): array {
		$all = self::picked_scope();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$want = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : '';
		if ( isset( $all[ $want ] ) ) {
			return [ $want, $all[ $want ], $all ];
		}
		$first = (string) ( array_key_first( $all ) ?? '' );
		return [ $first, $all[ $first ] ?? [], $all ];
	}

	public static function batch_body(): void {
		[ $key, $scope, $all ] = self::batch_scope();
		if ( ! $scope ) {
			echo '<div class="notice notice-warning inline" style="margin:16px 0;"><p>'
				. esc_html__( 'WPML is not set to translate any post type or taxonomy on this site. Open WPML → Settings and say what should be translated; this screen follows that answer and never overrides it.', 'dazont-ecom' )
				. '</p></div>';
			return;
		}
		$src   = DZE_Wpml::default_language();
		$langs = [];
		foreach ( DZE_Wpml::get_active_languages() as $l ) {
			if ( (string) $l['code'] !== $src ) {
				$langs[ (string) $l['code'] ] = (string) $l['native_name'];
			}
		}
		if ( ! $langs ) {
			echo '<p>' . esc_html__( 'This site has one language. There is nothing to translate into.', 'dazont-ecom' ) . '</p>';
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$all_rows = ! empty( $_GET['all'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$only  = isset( $_GET['only'] ) ? self::from_ref( sanitize_text_field( wp_unslash( $_GET['only'] ) ) ) : [];
		$per   = 25;

		// ---- 1. WHAT THIS KIND HOLDS TODAY, in one line, with the figures ----
		$counts  = self::counts_for( $scope );
		$marked  = self::marked_for( $scope );
		$waiting = (int) ( self::review_counts()[ DZE_Wpml::element_name( (string) $scope['kind'], (string) $scope['type'] ) ] ?? 0 );
		$total   = (int) ( $counts[ $src ] ?? 0 );
		?>
		<form method="get" class="dze-tr-head">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
			<input type="hidden" name="tab" value="batch" />
			<label><strong><?php esc_html_e( 'Translate', 'dazont-ecom' ); ?></strong>
				<select name="scope" onchange="this.form.submit();">
					<?php foreach ( $all as $k => $one ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $key ); ?>><?php echo esc_html( $one['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<noscript><button type="submit" class="button"><?php esc_html_e( 'Go', 'dazont-ecom' ); ?></button></noscript>
		</form>
		<p class="description dze-tr-holds">
			<?php
			$dze_bits = [ sprintf( /* translators: 1: number, 2: language code */ __( '%1$s in %2$s', 'dazont-ecom' ), number_format_i18n( $total ), strtoupper( $src ) ) ];
			foreach ( $langs as $dze_c => $dze_n ) {
				$dze_have = (int) ( $counts[ $dze_c ] ?? 0 );
				$dze_due  = (int) ( $marked[ $dze_c ] ?? 0 );
				$dze_bits[] = sprintf(
					/* translators: 1: language code, 2: how many exist, 3: how many are missing, 4: how many WPML wants again */
					__( '%1$s: %2$s translated, %3$s missing, %4$s to update', 'dazont-ecom' ),
					strtoupper( (string) $dze_c ),
					number_format_i18n( $dze_have ),
					number_format_i18n( max( 0, $total - $dze_have ) ),
					number_format_i18n( $dze_due )
				);
			}
			echo esc_html( implode( ' · ', $dze_bits ) );
			?>
			<?php if ( $waiting ) : ?>
				&nbsp;<a href="<?php echo esc_url( self::url( [ 'tab' => 'review' ] ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: number waiting */ _n( '%s waiting to be read', '%s waiting to be read', $waiting, 'dazont-ecom' ), number_format_i18n( $waiting ) ) ); ?></a>
			<?php endif; ?>
		</p>

		<div class="dze-tr-pick dze-cb-controls" data-scope="<?php echo esc_attr( $key ); ?>">
			<h2><?php esc_html_e( 'What to translate', 'dazont-ecom' ); ?></h2>

			<?php
			// ---- 2. ONE BLOCK PER KIND OF WORK, its switch in its own title ----
			DZE_Hub::sec_open( 'langs', __( 'Languages', 'dazont-ecom' ), true, [
				'all' => true,
				'tip' => __( 'Tick every language', 'dazont-ecom' ),
			] );
			?>
				<div class="dze-cb-checks">
					<?php foreach ( $langs as $dze_code => $dze_name ) : ?>
						<label class="dze-cb-check dze-tr-langbox" title="<?php echo esc_attr( $dze_name ); ?>">
							<input type="checkbox" class="dze-tr-lang" value="<?php echo esc_attr( $dze_code ); ?>" checked />
							<span><?php echo wp_kses_post( DZE_Wpml::flag_html( (string) $dze_code ) ); ?>
								<em class="description"><?php echo esc_html( sprintf(
									/* translators: %s: how many of this kind are missing that language */
									__( '%s short', 'dazont-ecom' ),
									number_format_i18n( max( 0, $total - (int) ( $counts[ $dze_code ] ?? 0 ) ) + (int) ( $marked[ $dze_code ] ?? 0 ) )
								) ); ?></em>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php DZE_Hub::sec_close(); ?>

			<?php
			// A BLOCK'S OWN CONTROLS LIVE INSIDE IT. What is actually sent for
			// this kind of content is read from WPML's rules, and it belongs
			// here rather than on a settings page: it is the thing you check
			// before pressing, not a preference.
			DZE_Hub::sec_open( 'fields', __( 'What is sent with each one', 'dazont-ecom' ), false );
			?>
				<table class="widefat" style="border:0;">
					<tbody>
					<?php foreach ( DZE_Translate::field_report( (string) $scope['kind'], (string) $scope['type'] ) as $dze_r ) : ?>
						<tr>
							<td style="width:220px;"><strong><?php echo esc_html( $dze_r['label'] ); ?></strong></td>
							<td style="color:<?php echo esc_attr( 'ok' === $dze_r['tone'] ? '#0a7040' : ( 'warn' === $dze_r['tone'] ? '#b32d2e' : ( 'gap' === $dze_r['tone'] ? '#8a6d00' : '#646970' ) ) ); ?>;"><?php echo esc_html( $dze_r['said'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Read from WPML\'s own rules. The prompt is under Settings → Translation.', 'dazont-ecom' ); ?></p>
			<?php DZE_Hub::sec_close(); ?>

			<!-- ---- 3. ONE BUTTON THAT RUNS WHAT IS TICKED ---- -->
			<p class="dze-cb-actions">
				<button type="button" class="button button-primary button-hero" id="dze-tr-send"
					title="<?php esc_attr_e( 'Translates the ticked rows into the ticked languages. What comes back waits under "To review" — nothing is written to the site yet.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Translate', 'dazont-ecom' ); ?></button>
				<button type="button" class="button" id="dze-tr-stop" style="display:none;"><?php esc_html_e( 'Stop', 'dazont-ecom' ); ?></button>
				<!-- WHAT THE PRESS IS ABOUT TO DO, beside the press: rows times
				     languages, which is the figure nobody had ever multiplied. -->
				<span id="dze-tr-bill" class="description"></span>
			</p>
			<span id="dze-tr-sendstate" class="description"></span>
			<div class="dze-cx-prog" id="dze-tr-prog" style="display:none;">
				<div class="dze-cb-bar"><div class="dze-cb-fill"></div></div>
				<p><strong id="dze-tr-progcount"></strong> <span id="dze-tr-progstep" class="description"></span></p>
			</div>
		</div>
		<?php
		// ---- 4. THE LIST ----
		self::pick_body( $key, $scope, $langs, $src, $paged, $per, $all_rows, $only );
	}

	/**
	 * THE LIST: what is short, ticked, looked at, and sent.
	 *
	 * A page at a time, with the state of each object in each language read
	 * from WPML's mark first and from our register second — which is the only
	 * thing that can tell "translated and current" from "translated and the
	 * words have moved since". WPML's own mark cannot: it is raised by a
	 * renamed category.
	 *
	 * Every part of it is the one the product bulk screen already wears: the
	 * same bar, the same two words on the row (Look for the object as it
	 * stands, Review once something waits), the same panel, the same Apply and
	 * Cancel. What varies between the two screens is WHAT the blocks are, never
	 * the shape around them.
	 */
	public static function pick_body( string $key, array $scope, array $langs, string $src, int $paged = 1, int $per = 25, bool $all = false, array $only = [] ): void {
		// THE NARROWING HAPPENS IN THE QUERY THAT PAGES. Filtered after the
		// paging, the pager counted the whole catalogue and the page showed
		// two rows.
		if ( $only ) {
			// ARMED ON ONE OBJECT: "Translate with Dazont Ecom" in WPML's own
			// Language box opens this screen rather than running anything, so
			// the screen shows THAT object, ticked, with nothing in the way.
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
		// AND WHAT IS ALREADY WAITING ON THESE ONES, so a row says Review
		// rather than Look. One query for the page, like everything else here.
		$held    = self::page_waiting( $objects );
		?>
		<!-- Tick rows, then act on them: the same bar, the same words and the
		     same order as the product bulk screen, because it is the same
		     gesture. -->
		<p class="dze-cb-listbar">
			<span id="dze-tr-selcount" class="description"></span>
			<button type="button" class="button button-small" id="dze-tr-selall"><?php esc_html_e( 'Select all', 'dazont-ecom' ); ?></button>
			<button type="button" class="button button-small" id="dze-tr-selnone"><?php esc_html_e( 'Unselect all', 'dazont-ecom' ); ?></button>
			<span class="dze-cb-barsep"></span>
			<a class="button" href="<?php echo esc_url( self::url( [ 'tab' => 'review' ] ) ); ?>" title="<?php esc_attr_e( 'Everything that has come back and is waiting for a yes or a no, whatever kind of thing it is', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Read what came back', 'dazont-ecom' ); ?></a>
		</p>
		<table class="widefat striped dze-tr-table">
			<thead><tr>
				<td class="check-column"><input type="checkbox" id="dze-tr-all" title="<?php esc_attr_e( 'Select every row on this page', 'dazont-ecom' ); ?>" /></td>
				<th><?php esc_html_e( 'Name', 'dazont-ecom' ); ?></th>
				<?php echo wp_kses_post( DZE_Hub::id_th() ); ?>
				<th style="width:340px;" title="<?php esc_attr_e( 'A flag that is owed is a button: the plus makes the missing translation, the arrows bring an out-of-date one back.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Where it stands', 'dazont-ecom' ); ?></th>
				<th style="width:120px;"></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $objects ) : ?>
				<!-- AN EMPTY ANSWER SAYS WHICH EMPTY IT IS. "Nothing here" over
				     a catalogue of two thousand reads as a broken screen. -->
				<tr><td colspan="5">
					<?php if ( $exact && ! $all ) : ?>
						<?php esc_html_e( 'Nothing needs work here: every one of these is translated and WPML is satisfied with it.', 'dazont-ecom' ); ?>
						<a href="<?php echo esc_url( self::url( [ 'tab' => 'batch', 'scope' => $key, 'all' => 1 ] ) ); ?>"><?php esc_html_e( 'Show them all anyway', 'dazont-ecom' ); ?></a>
					<?php else : ?>
						<?php esc_html_e( 'Nothing of this kind in the main language yet.', 'dazont-ecom' ); ?>
					<?php endif; ?>
				</td></tr>
			<?php endif; ?>
			<?php foreach ( $objects as $o ) : ?>
				<?php
				$state   = self::state_of( $o, array_keys( $langs ), $marks );
				$dze_ref = self::ref( $o );
				$dze_has = ! empty( $held[ $dze_ref ] );
				?>
				<tr class="dze-tr-row" data-ref="<?php echo esc_attr( $dze_ref ); ?>">
					<td class="check-column"><input type="checkbox" class="dze-tr-pickone" <?php checked( (bool) $only ); ?> /></td>
					<td>
						<a href="<?php echo esc_url( self::obj_edit_url( $o ) ); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html( self::obj_label( $o ) ); ?></strong></a>
					</td>
					<?php echo wp_kses_post( DZE_Hub::id_td( (int) $o['id'] ) ); ?>
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
										/* translators: %s: the language */
										'missing' === $said
											? __( 'Translate this one into %s — it lands in "To review", nothing is written yet', 'dazont-ecom' )
											: __( 'Bring the %s translation up to date — it lands in "To review", nothing is written yet', 'dazont-ecom' ),
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
					<td>
						<!-- ONE SCREEN PER OBJECT, and this is the way to it.
						     It used to open a panel inside the row, and the
						     product page had a popup of its own beside it —
						     two surfaces for one job, which is how two screens
						     start disagreeing about one object. -->
						<a class="button button-small dze-tr-open" href="<?php echo esc_url( self::editor_url( $o ) ); ?>"
							title="<?php echo esc_attr( $dze_has
								? __( 'Read what came back beside the original, field by field, and save it or throw it away', 'dazont-ecom' )
								: __( 'Open this one: the original, what each translation holds today, and one button to translate it', 'dazont-ecom' ) ); ?>">
							<span class="dze-tr-openword"><?php echo esc_html( $dze_has ? __( 'Review', 'dazont-ecom' ) : __( 'Open', 'dazont-ecom' ) ); ?></span>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $only ) : ?>
			<p class="description">
				<?php esc_html_e( 'One object, opened from its own edit screen and already ticked. Nothing is sent until you press Translate.', 'dazont-ecom' ); ?>
				<a href="<?php echo esc_url( self::url( [ 'tab' => 'batch', 'scope' => $key ] ) ); ?>"><?php esc_html_e( 'Show everything that needs work', 'dazont-ecom' ); ?></a>
			</p>
		<?php elseif ( $exact && $objects ) : ?>
			<!-- WHAT THE LIST IS, said once, with the other reading one press
			     away. The count in the pager and the rows under it answer this
			     same sentence. -->
			<p class="description">
				<?php if ( $all ) : ?>
					<?php echo esc_html( sprintf( /* translators: %s: number of objects */ _n( '%s in the main language — everything, including what WPML is satisfied with.', '%s in the main language — everything, including what WPML is satisfied with.', $found, 'dazont-ecom' ), number_format_i18n( $found ) ) ); ?>
					<a href="<?php echo esc_url( self::url( [ 'tab' => 'batch', 'scope' => $key ] ) ); ?>"><?php esc_html_e( 'Only what needs work', 'dazont-ecom' ); ?></a>
				<?php else : ?>
					<?php echo esc_html( sprintf( /* translators: %s: number of objects */ _n( '%s needs work: a language missing, or WPML asking for it again.', '%s need work: a language missing, or WPML asking for it again.', $found, 'dazont-ecom' ), number_format_i18n( $found ) ) ); ?>
					<a href="<?php echo esc_url( self::url( [ 'tab' => 'batch', 'scope' => $key, 'all' => 1 ] ) ); ?>"><?php esc_html_e( 'Show them all', 'dazont-ecom' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php if ( $pages > 1 ) : ?>
			<p class="tablenav-pages" style="margin:10px 0;">
				<?php
				echo wp_kses_post( paginate_links( [
					'base'    => esc_url_raw( self::url( array_merge(
						[ 'tab' => 'batch', 'scope' => $key, 'paged' => '%#%' ],
						$all ? [ 'all' => 1 ] : []
					) ) ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				] ) ?: '' );
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * WHICH OF THESE ROWS ALREADY HOLD SOMETHING WAITING, in one query.
	 *
	 * A row that has work waiting says **Review**; one that has not says
	 * **Look**. Asked per row it would be a query per row, which is the rule
	 * this plugin breaks least often and pays for most.
	 *
	 * @return array<string,true> ref => true
	 */
	public static function page_waiting( array $objects ): array {
		global $wpdb;
		$out = [];
		if ( ! $objects || ! $wpdb ) {
			return $out;
		}
		$posts = [];
		$terms = [];
		foreach ( $objects as $o ) {
			if ( 'term' === ( $o['kind'] ?? 'post' ) ) {
				$terms[ (int) $o['id'] ] = self::ref( $o );
			} else {
				$posts[ (int) $o['id'] ] = self::ref( $o );
			}
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own meta key; ids cast to int.
		if ( $posts ) {
			$in   = implode( ',', array_map( 'intval', array_keys( $posts ) ) );
			$rows = (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ( {$in} )",
				DZE_Translate::META_WAIT
			) );
			foreach ( $rows as $id ) {
				if ( isset( $posts[ (int) $id ] ) ) {
					$out[ $posts[ (int) $id ] ] = true;
				}
			}
		}
		if ( $terms ) {
			$in   = implode( ',', array_map( 'intval', array_keys( $terms ) ) );
			$rows = (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND term_id IN ( {$in} )",
				DZE_Translate::META_WAIT
			) );
			foreach ( $rows as $id ) {
				if ( isset( $terms[ (int) $id ] ) ) {
					$out[ $terms[ (int) $id ] ] = true;
				}
			}
		}
		// phpcs:enable
		return $out;
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
	 *
	 * PUBLIC because the automatic pass asks the SAME question: "what does
	 * this shop still owe a translation of?" A second reading written beside
	 * this one is how a screen and the pass that feeds it start disagreeing
	 * about which objects are work — the automation would offer an object the
	 * screen does not list, and nobody could say which of the two was right.
	 */
	/**
	 * CE QUE LA BOUTIQUE CONSIDERE COMME DU. Separee de la requete parce qu une
	 * clause enfouie dans un SQL de quarante lignes ne s eprouve pas.
	 *
	 * @param int    $want Combien de langues sont attendues.
	 * @param string $need L expression qui dit « WPML l a marque perime ».
	 */
	public static function owed_clause( int $want, string $need ): string {
		$missing = "COUNT( DISTINCT t.language_code ) < {$want}";
		$when    = class_exists( 'DZE_Translate' ) ? DZE_Translate::when() : 'both';
		if ( 'new' === $when ) {
			return $missing;
		}
		if ( 'update' === $when ) {
			return $need;
		}
		return "{$missing} OR {$need}";
	}

	public static function todo_page( array $scope, string $src, array $targets, int $paged, int $per, bool $todo_only = true ): ?array {
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
			// ONLY WHAT THE SHOP ACTUALLY SHOWS. A draft is a page nobody has
			// published; translating one pays a model to write eight languages
			// of something that may never go out, and files eight drafts behind
			// it. "Tu autorises la traduction des pages brouillon. Erreur
			// grossière." A private page IS published — restricted, not unborn —
			// so it stays. Four queries in this module already read it this way;
			// three had kept draft and pending.
			: "INNER JOIN {$wpdb->posts} p ON p.ID = src.element_id
			      AND p.post_status IN ('publish','private')";
		// UN ATTRIBUT QUE RIEN N EMPLOIE N EST PAS UNE PAGE A TRADUIRE.
		//
		// « Pas besoin de traduire des attributs non utilises. » Sur cette
		// boutique 44 couleurs sur 218 ne sont portees par aucun produit : leur
		// archive est vide, aucun filtre ne les propose, et chacune coutait un
		// appel par langue. La valeur d attribut suit le produit ou elle ne part
		// pas du tout.
		//
		// Le test porte sur les LIGNES de rattachement et non sur tt.count, qui
		// est un cache et ment des qu une passe l a laisse en arriere.
		//
		// EXISTS et non une jointure : une jointure rendrait une ligne par
		// produit portant la valeur, que le GROUP BY replierait ensuite pour
		// rien — sur un catalogue de dix mille produits c est le genre de detail
		// qui transforme une liste en attente.
		$used = ( $term && 0 === strpos( (string) $scope['type'], 'pa_' ) )
			? " AND EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} dze_use
			                  WHERE dze_use.term_taxonomy_id = tt.term_taxonomy_id )"
			: '';
		$pick  = $term ? 'tt.term_id' : 'src.element_id';
		$order = $term ? 'tm.name' : 'p.post_title';
		$marks = $has_s ? "LEFT JOIN {$st} s ON s.translation_id = t.translation_id" : '';
		$need  = $has_s ? "MAX( COALESCE( s.needs_update, 0 ) ) = 1" : '0 = 1';
		$body  = "FROM {$tr} src
			{$join}
			LEFT JOIN {$tr} t ON t.trid = src.trid AND t.element_id <> src.element_id
			      AND t.element_type = src.element_type AND t.language_code IN ( {$in} )
			{$marks}
			WHERE src.element_type = %s AND src.language_code = %s {$used}
			GROUP BY src.element_id, {$pick}, {$order}"
			// "SHOW THEM TOO" IS THE SAME QUERY WITHOUT THE NARROWING, never a
			// second reading: two readings of one list is how a count and the
			// rows under it start disagreeing.
			// NOUVEAUX, MISES A JOUR, OU LES DEUX — et c est la MEME requete,
			// resserree. Deux lectures d une liste est la facon dont un compte et
			// les lignes en dessous se mettent a diverger.
			//
			// « Une langue manquante » et « WPML dit que c est perime » sont deux
			// travaux : remplir un trou du catalogue, ou entretenir l existant.
			. ( $todo_only ? ' HAVING ' . self::owed_clause( $want, $need ) : '' );
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
			'post_status'    => [ 'publish', 'private' ],
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

	/**
	 * WHAT HAS BEEN TRANSLATED, and where to go and look at it.
	 *
	 * "Comment voir le résultat des traductions, quelles pages ?" There was no
	 * answer: a translation never enters the writing queue — it waits on its
	 * source object and is decided here — so the log of automatic passes held
	 * nothing about it, and no screen listed a finished one.
	 */
	public static function done_body(): void {
		$rows = DZE_Translate::done_list( 200 );
		?>
		<p class="description" style="max-width:900px;margin:16px 0;">
			<?php esc_html_e( 'Every translation this module has written, newest first. The name opens it for editing; the arrow opens it on the site, as a reader sees it.', 'dazont-ecom' ); ?>
		</p>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'Nothing has been written yet. What is accepted from “To review” lands here.', 'dazont-ecom' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped" style="max-width:1000px;">
			<thead><tr>
				<th><?php esc_html_e( 'Name', 'dazont-ecom' ); ?></th>
				<th style="width:110px;"><?php esc_html_e( 'Language', 'dazont-ecom' ); ?></th>
				<th style="width:160px;"><?php esc_html_e( 'What', 'dazont-ecom' ); ?></th>
				<th style="width:170px;"><?php esc_html_e( 'When', 'dazont-ecom' ); ?></th>
				<?php // « Ne pas oublier d'afficher aussi par qui ça a été fait. Partout. » Ici c'est qui a dit oui. ?>
				<th style="width:130px;"><?php esc_html_e( 'Accepted by', 'dazont-ecom' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td>
						<strong><?php
						echo '' !== (string) $r['edit']
							? '<a href="' . esc_url( (string) $r['edit'] ) . '">' . esc_html( (string) $r['label'] ) . '</a>'
							: esc_html( (string) $r['label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in both branches.
						?></strong>
						<?php if ( '' !== (string) $r['view'] ) : ?>
							<a class="dze-hub-visit" href="<?php echo esc_url( (string) $r['view'] ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'See it on the site', 'dazont-ecom' ); ?>"><span class="dashicons dashicons-external"></span></a>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( '' !== (string) $r['lang'] ? (string) $r['lang'] : '—' ); ?></td>
					<td><span class="description"><?php echo esc_html( (string) $r['kind'] ); ?></span></td>
					<td><span class="description"><?php
						echo esc_html( '' !== (string) $r['when'] ? mysql2date( (string) get_option( 'date_format' ) . ' H:i', (string) $r['when'] ) : '—' );
					?></span></td>
					<td><?php
						// Rien plutôt qu'un nom inventé : une traduction écrite
						// avant que ce soit gardé a bien été acceptée par
						// quelqu'un, simplement personne ne l'a noté.
						echo null === ( $r['by'] ?? null )
							? '<span class="description">' . esc_html__( 'not recorded', 'dazont-ecom' ) . '</span>'
							: esc_html( (string) $r['by'] );
					?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

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
		<?php
		// TOUT ACCEPTER, EN UNE FOIS. « Je ne peux meme pas accepter en bulk.
		// C est ce que j aurais fait ici : tout accepter. Tout est bon. »
		// Dire oui a huit objets demandait huit ecrans, et un ecran par langue
		// dans chacun — le plugin qui marche bien coutait plus de clics que le
		// plugin qui marche mal. Rien n est retraduit : ce sont les textes deja
		// revenus qui sont ecrits, exactement comme l ecran de lecture le ferait.
		?>
		<p class="dze-cb-actions" style="max-width:980px;">
			<button type="button" class="button button-primary" id="dze-tr-acceptall"
				title="<?php esc_attr_e( 'Writes every translation waiting here, in every language, exactly as it came back. Nothing is translated again and nothing is paid for.', 'dazont-ecom' ); ?>"><?php
				echo esc_html( sprintf(
					/* translators: %s: how many objects are waiting */
					_n( 'Accept the %s waiting', 'Accept all %s waiting', count( $rows ), 'dazont-ecom' ),
					number_format_i18n( count( $rows ) )
				) );
			?></button>
			<button type="button" class="button" id="dze-tr-acceptsel" disabled
				title="<?php esc_attr_e( 'The same, for the ticked rows only.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Accept the ticked', 'dazont-ecom' ); ?></button>
			<?php
			// ET REFUSER EN GROUPE, PARCE QU ACCEPTER EN GROUPE EXISTE.
			//
			// « Il manque le bouton Discard. » La barre ne savait dire que oui :
			// on pouvait accepter sept lignes d une presse et il fallait sept
			// presses pour en refuser sept. Une liste ou l accord est groupe et
			// le refus ne l est pas pousse a tout accepter, ce qui est exactement
			// le contraire d une relecture.
			?>
			<button type="button" class="button dze-cb-no" id="dze-tr-dropsel" disabled
				title="<?php esc_attr_e( 'Throws away what came back for the ticked rows. The objects and their translations are not touched.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Discard the ticked', 'dazont-ecom' ); ?></button>
			<span class="description" id="dze-tr-allstate"></span>
		</p>
		<table class="widefat striped" style="max-width:980px;">
			<thead><tr>
				<td class="check-column" style="width:2.2em;"><input type="checkbox" id="dze-tr-wall" /></td>
				<th><?php esc_html_e( 'Name', 'dazont-ecom' ); ?></th>
				<?php echo wp_kses_post( DZE_Hub::id_th() ); ?>
				<th style="width:150px;"><?php esc_html_e( 'What', 'dazont-ecom' ); ?></th>
				<th style="width:200px;"><?php esc_html_e( 'Languages waiting', 'dazont-ecom' ); ?></th>
				<?php
				// QUAND. « Manque une colonne de date ! Je ne sais pas quand ces
				// trads ont été faites. » Une file d'attente sans date ne dit pas
				// si une ligne est arrivée il y a dix minutes ou il y a trois
				// semaines — et c'est la seule chose qui fait la différence
				// entre « je lis ça maintenant » et « l'original a bougé depuis,
				// autant la refaire ». Le moment est celui où la traduction a
				// été produite, qui est le seul que cette ligne connaisse.
				?>
				<th style="width:170px;"><?php esc_html_e( 'Translated', 'dazont-ecom' ); ?></th>
				<?php // « Ne pas oublier d'afficher aussi par qui ça a été fait. Partout, comme sur les bulk product. » ?>
				<th style="width:130px;"><?php esc_html_e( 'Started by', 'dazont-ecom' ); ?></th>
				<th style="width:180px;"></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr class="dze-tr-wrow dze-tr-row" data-ref="<?php echo esc_attr( self::ref( $r ) ); ?>">
					<th scope="row" class="check-column"><input type="checkbox" class="dze-tr-wpick" /></th>
					<td><strong><?php echo esc_html( $r['label'] ); ?></strong></td>
					<?php echo wp_kses_post( DZE_Hub::id_td( (int) $r['id'] ) ); ?>
					<td><span class="description"><?php echo esc_html( self::type_label( $r ) ); ?></span></td>
					<td>
						<?php foreach ( $r['langs'] as $code ) : ?>
							<!-- EACH LANGUAGE IS THE WAY INTO IT, because the
							     screen that reads a translation reads ONE
							     language at a time. -->
							<a class="dze-tr-chip is-stale" href="<?php echo esc_url( self::editor_url( $r, (string) $code ) ); ?>"><?php echo wp_kses_post( DZE_Wpml::flag_html( (string) $code ) ); ?></a>
						<?php endforeach; ?>
					</td>
					<td>
						<?php
						// LA DATE, ET « IL Y A COMBIEN » AVEC ELLE. Une date seule
						// oblige à compter dans sa tête ; « il y a 2 heures » seul
						// ne dit plus rien au bout d'une semaine. Les deux, et la
						// date exacte au survol pour lever toute ambiguïté.
						$dze_at = (int) ( $r['at'] ?? 0 );
						if ( $dze_at > 0 ) {
							printf(
								'<span title="%1$s">%2$s<br /><span class="description">%3$s</span></span>',
								esc_attr( date_i18n( 'Y-m-d H:i', $dze_at + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ),
								esc_html( date_i18n( (string) get_option( 'date_format' ), $dze_at + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ),
								esc_html( sprintf(
									/* translators: %s: how long ago, e.g. "2 hours" */
									__( '%s ago', 'dazont-ecom' ),
									human_time_diff( $dze_at, time() )
								) )
							);
						} else {
							// UNE LIGNE SANS MOMENT LE DIT. Les traductions mises en
							// attente avant que ce moment soit gardé n'en ont pas, et
							// inventer « aujourd'hui » serait pire que se taire.
							echo '<span class="description">' . esc_html__( 'not recorded', 'dazont-ecom' ) . '</span>';
						}
						?>
					</td>
					<td>
						<?php
						// TROIS REPONSES, ET ELLES NE SE CONFONDENT PAS : un nom,
						// « Automatic » pour la passe qui tourne seule, et rien du
						// tout quand personne ne l'a noté — une ligne mise en
						// attente avant que ce soit gardé a bien été demandée par
						// quelqu'un, et le nommer serait inventer.
						$dze_by = $r['by'] ?? null;
						if ( null === $dze_by ) {
							echo '<span class="description">' . esc_html__( 'not recorded', 'dazont-ecom' ) . '</span>';
						} else {
							echo esc_html( class_exists( 'DZE_Queue' ) ? DZE_Queue::started_by( (int) $dze_by ) : (string) $dze_by );
						}
						?>
					</td>
					<td>
						<?php
						// LIRE AVANT D OUVRIR. « Je veux pouvoir visualiser rapidement
						// les traductions comme sur WPML. » Huit resultats coutaient
						// huit ecrans, et un par langue dans chacun.
						?>
						<button type="button" class="button dze-tr-peek"><?php esc_html_e( 'Read it here', 'dazont-ecom' ); ?></button>
						<a class="button button-primary dze-tr-open" href="<?php echo esc_url( self::editor_url( $r ) ); ?>"><?php esc_html_e( 'Review', 'dazont-ecom' ); ?></a>
						<button type="button" class="button dze-tr-refuse" title="<?php esc_attr_e( 'Throws away what was translated. The object and its translations are not touched.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Discard', 'dazont-ecom' ); ?></button>
					</td>
				</tr>
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
