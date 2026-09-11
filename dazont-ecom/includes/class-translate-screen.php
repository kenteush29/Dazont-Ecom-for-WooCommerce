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

	public static function dash_body(): void {
		$scope = self::scope();
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
					<th style="width:110px;text-align:right;">
						<?php echo wp_kses_post( DZE_Wpml::flag_html( (string) $l['code'] ) ); ?>
						<?php echo esc_html( strtoupper( (string) $l['code'] ) ); ?>
					</th>
				<?php endforeach; ?>
				<th style="width:120px;"></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $scope as $key => $one ) : ?>
				<?php
				$counts = self::counts_for( $one );
				$total  = (int) ( $counts[ $src ] ?? 0 );
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
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
					<td>
						<a class="button" href="<?php echo esc_url( self::url( [ 'tab' => 'dashboard', 'scope' => $key ] ) ); ?>"><?php esc_html_e( 'Choose a batch', 'dazont-ecom' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
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
		$per     = 25;
		[ $objects, $found ] = self::page_of( $scope, $src, $paged, $per );
		$pages   = (int) ceil( $found / $per );
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
					<label class="dze-cb-check"><input type="checkbox" class="dze-tr-lang" value="<?php echo esc_attr( $code ); ?>" checked />
						<span><?php echo wp_kses_post( DZE_Wpml::flag_html( $code ) ); ?> <?php echo esc_html( $name ); ?></span></label>
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
					<tr><td colspan="3"><?php esc_html_e( 'Nothing of this kind in the main language yet.', 'dazont-ecom' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $objects as $o ) : ?>
					<?php $state = self::state_of( $o, array_keys( $langs ) ); ?>
					<tr class="dze-tr-row" data-ref="<?php echo esc_attr( self::ref( $o ) ); ?>">
						<td class="check-column"><input type="checkbox" class="dze-tr-pickone" /></td>
						<td>
							<a href="<?php echo esc_url( self::obj_edit_url( $o ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( self::obj_label( $o ) ); ?></a>
						</td>
						<td class="dze-tr-state">
							<?php foreach ( $state as $code => $said ) : ?>
								<span class="dze-tr-chip is-<?php echo esc_attr( $said ); ?>" title="<?php echo esc_attr( self::state_said( $said ) ); ?>">
									<?php echo esc_html( strtoupper( $code ) ); ?> · <?php echo esc_html( self::state_said( $said ) ); ?>
								</span>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $pages > 1 ) : ?>
				<p class="tablenav-pages" style="margin:10px 0;">
					<?php
					echo wp_kses_post( paginate_links( [
						'base'    => esc_url_raw( self::url( [ 'tab' => 'dashboard', 'scope' => $key, 'paged' => '%#%' ] ) ),
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
	 * WHERE ONE OBJECT STANDS IN EACH LANGUAGE.
	 *
	 * Three answers and they are not the same question: nothing there at all,
	 * there but made from words that have moved since, or there and current.
	 * WPML's own mark cannot tell the last two apart — it is raised by a
	 * renamed category — which is the whole reason the register exists.
	 *
	 * @return array<string,string> language => 'missing'|'stale'|'done'
	 */
	public static function state_of( array $o, array $langs ): array {
		$out = [];
		foreach ( $langs as $code ) {
			$code = (string) $code;
			if ( ! self::obj_translation( $o, $code ) ) {
				$out[ $code ] = 'missing';
				continue;
			}
			$out[ $code ] = self::obj_stale( $o, $code ) ? 'stale' : 'done';
		}
		return $out;
	}

	/** Those three answers in words. In PHP: hard-coded in the JavaScript they were English on every shop. */
	public static function state_said( string $state ): string {
		$words = [
			'missing' => __( 'not translated', 'dazont-ecom' ),
			'stale'   => __( 'words have moved', 'dazont-ecom' ),
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
