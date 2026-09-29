<?php
defined( 'ABSPATH' ) || exit;

/**
 * The WPML Translation Module's own screen: Dazont Ecom → WPML Translations.
 *
 * « Je veux que ce soit une copie du dashboard WPML. Regardes le code, copies.
 * On peut garder tout de même notre style natif WordPress. Cette demande
 * implique aussi de dégager la batch list, qui ne sert à rien. »
 *
 * So the dashboard IS WPML's Translation Dashboard, read off its own code
 * (`vendor/wpml/wpml/public/js/dashboard.js`) and drawn in WordPress's own
 * furniture:
 *
 *   1. SELECT ITEMS FOR TRANSLATION — the global filters (source language,
 *      translated to, publication status, translation status, Filter, Clear
 *      filters, Select All), then one section per kind of content, each with
 *      its own title filter, taxonomy filter, table and pager. One icon per
 *      language on every row, WPML's own states: not translated, needs
 *      update, complete, in progress, needs review.
 *   2. TRANSLATE YOUR CONTENT — appears once something is ticked: one row per
 *      target language with its words to translate, its method and what it
 *      will cost; what to do with what is already translated; what to do
 *      when the translation comes back; one button.
 *   3. IN THE BACKGROUND — the queue translates; on the row, a wheel turns on
 *      each language that is on its way, and turns into its new state when it
 *      lands. Nobody has to go and look at a queue.
 *
 * The one screen per object (the editor) and the two lists — To review,
 * Translated — are unchanged.
 */
trait DZE_Translate_Screen {

	public const MENU_SLUG = 'dazont-ecom-translations';

	/** Les tailles de page offertes à chaque section, comme WPML. */
	public const PER_PAGE = [ 10, 20, 50, 100 ];

	/** Ce qu'une section montre par défaut. */
	public const PER_DEFAULT = 10;

	/** Les états de traduction que le filtre de WPML propose. */
	public const T_STATUSES = [ 'all', 'todo', 'update', 'progress', 'complete' ];

	/** Au-delà, « Select All » s'arrête : une sélection doit rester lisible. */
	public const PICK_MAX = 2000;

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
	 * THREE VIEWS, like WPML's: the dashboard, what waits for a yes or a no,
	 * and what has been written. The batch list is gone — the dashboard is
	 * where things are chosen and sent, exactly as in WPML.
	 */
	public static function tabs(): array {
		// Named by the catalogue; the figures are this screen's own.
		$names = DZE_Screens::tabs_of( 'translations' );
		return [
			// A BADGE MEANS "ACT ON ME", NEVER "HERE IS A NUMBER".
			'dashboard' => [ 'label' => (string) ( $names['dashboard'] ?? '' ), 'n' => null ],
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$ref  = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : '';
		echo '<div class="wrap dze-wrap dze-admin">';
		echo '<h1>' . esc_html( DZE_Screens::label( 'translations' ) ) . '</h1>';
		// THE SWITCH FIRST, ON THE SCREEN IT IS ABOUT — AND ONLY THERE: the
		// dashboard, never the editor of one object somebody is translating.
		if ( 'dashboard' === $tab && '' === $ref && class_exists( 'DZE_Automation' ) ) {
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
			// ONE SENTENCE OF WARNING IS THE WHOLE OF IT.
			echo '<div class="notice notice-warning inline" style="margin:16px 0;"><p>'
				. esc_html__( 'WPML is not running. This module reads its languages, its translation groups and its settings from WPML — with WPML off there is no second language to translate into.', 'dazont-ecom' )
				. '</p></div></div>';
			return;
		}
		if ( 'done' === $tab ) {
			self::done_body();
		} elseif ( 'review' === $tab ) {
			self::review_body();
		} elseif ( '' !== $ref ) {
			// A REF IS AN OBJECT, AND AN OBJECT HAS ONE SCREEN: WPML's own
			// translation editor, reached from every icon of the dashboard.
			self::editor_body();
		} else {
			self::dash_body();
		}
		echo '</div>';
	}

	// =========================================================================
	// THE DASHBOARD — WPML's Translation Dashboard, in WordPress's own clothes
	// =========================================================================

	/**
	 * THE GLOBAL FILTERS, read from the address — the same reading on the page
	 * and in every answer the sections ask for, so a section paged in the
	 * browser never disagrees with the one printed.
	 *
	 * « Not completed » is the default, as it is in WPML: the dashboard opens
	 * on the work, not on the whole catalogue.
	 *
	 * @return array{flang:string,pstatus:string,tstatus:string}
	 */
	public static function filters( array $in ): array {
		$flang   = sanitize_key( (string) ( $in['flang'] ?? '' ) );
		$pstatus = sanitize_key( (string) ( $in['pstatus'] ?? '' ) );
		$tstatus = sanitize_key( (string) ( $in['tstatus'] ?? '' ) );
		return [
			'flang'   => in_array( $flang, self::target_codes(), true ) ? $flang : '',
			'pstatus' => in_array( $pstatus, [ 'publish', 'private' ], true ) ? $pstatus : '',
			'tstatus' => in_array( $tstatus, self::T_STATUSES, true ) ? $tstatus : 'todo',
		];
	}

	/**
	 * ONE SECTION'S OWN CONTROLS: its page, its size, its title filter, its
	 * taxonomy filter and its order.
	 *
	 * @return array{paged:int,per:int,search:string,term:int,orderby:string,order:string}
	 */
	public static function section_args( array $in ): array {
		$per     = absint( $in['per'] ?? self::PER_DEFAULT );
		$orderby = sanitize_key( (string) ( $in['orderby'] ?? 'title' ) );
		return [
			'paged'   => max( 1, absint( $in['paged'] ?? 1 ) ),
			'per'     => in_array( $per, self::PER_PAGE, true ) ? $per : self::PER_DEFAULT,
			'search'  => trim( sanitize_text_field( (string) ( $in['search'] ?? '' ) ) ),
			'term'    => absint( $in['term'] ?? 0 ),
			'orderby' => in_array( $orderby, [ 'title', 'date' ], true ) ? $orderby : 'title',
			'order'   => 'desc' === sanitize_key( (string) ( $in['order'] ?? '' ) ) ? 'desc' : 'asc',
		];
	}

	/**
	 * ONE PAGE OF ONE SECTION, as the filters ask — or every ref it holds, for
	 * « Select All ».
	 *
	 * @return array{0:array<int,array|string>,1:int} objects (refs when $refs_only), and how many match.
	 */
	public static function section_page( array $scope, array $f, array $a, bool $refs_only = false ): array {
		if ( 'progress' === $f['tstatus'] ) {
			return self::progress_page( $scope, $a, $refs_only );
		}
		$src     = DZE_Wpml::default_language();
		$targets = '' !== $f['flang'] ? [ $f['flang'] ] : self::target_codes();
		$page    = self::todo_page( $scope, $src, $targets, $a['paged'], $a['per'], true, [
			'mode'     => $f['tstatus'],
			'search'   => $a['search'],
			'term'     => $a['term'],
			'status'   => $f['pstatus'],
			'orderby'  => $a['orderby'],
			'order'    => $a['order'],
			'refs'     => $refs_only,
		] );
		if ( null !== $page ) {
			return $page;
		}
		// WPML's tables cannot be read: everything, paged — wrong about the
		// count, right about the rows.
		[ $objs, $found ] = self::page_of( $scope, $src, $a['paged'], $a['per'] );
		return [ $refs_only ? array_map( [ __CLASS__, 'ref' ], $objs ) : $objs, $found ];
	}

	/**
	 * « TRANSLATION IN PROGRESS » — what the queue holds for this kind.
	 *
	 * @return array{0:array<int,array|string>,1:int}
	 */
	public static function progress_page( array $scope, array $a, bool $refs_only = false ): array {
		$objs = [];
		foreach ( array_keys( self::queued_map() + self::running() ) as $ref ) {
			$bits = explode( ':', (string) $ref );
			if ( ( $bits[0] ?? '' ) !== (string) $scope['kind'] || ( $bits[2] ?? '' ) !== (string) $scope['type'] ) {
				continue;
			}
			$o = self::from_ref( (string) $ref );
			if ( ! $o ) {
				continue;
			}
			if ( '' !== $a['search'] && false === mb_stripos( self::obj_label( $o ), $a['search'] ) ) {
				continue;
			}
			$objs[] = $o;
		}
		$found = count( $objs );
		if ( $refs_only ) {
			return [ array_map( [ __CLASS__, 'ref' ], $objs ), $found ];
		}
		return [ array_slice( $objs, ( $a['paged'] - 1 ) * $a['per'], $a['per'] ), $found ];
	}

	/**
	 * WHERE EACH LANGUAGE OF EACH ROW STANDS.
	 *
	 * WPML's answer first — not translated, needs update, complete — then what
	 * this module knows and WPML cannot: on its way (the wheel), being
	 * translated right now, back and waiting for a yes or a no, or failed.
	 *
	 * `base` is WPML's reading, `show` is what the row wears. Words and cost
	 * are read from `base`; a language on its way costs nothing more.
	 *
	 * @param string[] $langs
	 * @return array<string,array<string,array{show:string,base:string,why:string}>> ref => lang => state
	 */
	public static function row_states( array $objects, array $langs ): array {
		$out = [];
		if ( ! $objects ) {
			return $out;
		}
		// WPML's marks are asked PER KIND, one query each: a post and a term
		// may share an id, and a single reading would mix them up.
		$groups = [];
		foreach ( $objects as $o ) {
			$groups[ ( $o['kind'] ?? 'post' ) . ':' . ( $o['type'] ?? '' ) ][] = $o;
		}
		$queued  = self::queued_map();
		$running = self::running();
		$held    = self::page_waiting_langs( $objects );
		$errors  = self::drain_errors();
		foreach ( $groups as $group ) {
			$marks = self::page_marks( $group );
			foreach ( $group as $o ) {
				$ref  = self::ref( $o );
				$base = self::state_of( $o, $langs, $marks );
				foreach ( $langs as $code ) {
					$code = (string) $code;
					$b    = (string) ( $base[ $code ] ?? 'missing' );
					$show = $b;
					$why  = '';
					if ( in_array( $code, (array) ( $running[ $ref ] ?? [] ), true ) ) {
						$show = 'running';
					} elseif ( isset( $queued[ $ref ][ $code ] ) ) {
						$show = 'queued';
					} elseif ( in_array( $code, (array) ( $held[ $ref ] ?? [] ), true ) ) {
						$show = 'review';
					} elseif ( 'done' !== $b && ( isset( $errors[ $ref ][ $code ] ) || isset( $errors[ $ref ][''] ) ) ) {
						$show = 'error';
						$why  = (string) ( $errors[ $ref ][ $code ] ?? $errors[ $ref ][''] );
					}
					$out[ $ref ][ $code ] = [ 'show' => $show, 'base' => $b, 'why' => $why ];
				}
			}
		}
		return $out;
	}

	/** Native names of the active languages, code => name. */
	public static function lang_names(): array {
		static $out = null;
		if ( null === $out ) {
			$out = [];
			foreach ( DZE_Wpml::get_active_languages() as $l ) {
				$out[ (string) $l['code'] ] = (string) ( $l['native_name'] ?? strtoupper( (string) $l['code'] ) );
			}
		}
		return $out;
	}

	/**
	 * A LANGUAGE AS WPML DRAWS IT IN A COLUMN HEAD: the flag alone, named on
	 * hover. The code stands in when WPML has no flag for it.
	 */
	public static function flag_only( string $code ): string {
		static $flags = null;
		if ( null === $flags ) {
			$flags = [];
			foreach ( DZE_Wpml::get_active_languages() as $l ) {
				$flags[ (string) $l['code'] ] = (string) ( $l['flag'] ?? '' );
			}
		}
		$name = (string) ( self::lang_names()[ $code ] ?? strtoupper( $code ) );
		$flag = (string) ( $flags[ $code ] ?? '' );
		return '' !== $flag
			? '<img class="dze-trd-flag" src="' . esc_url( $flag ) . '" alt="' . esc_attr( $name ) . '" title="' . esc_attr( $name ) . '" width="18" height="12" />'
			: '<span class="dze-trd-flag dze-trd-flagtxt" title="' . esc_attr( $name ) . '">' . esc_html( strtoupper( $code ) ) . '</span>';
	}

	/**
	 * ONE LANGUAGE OF ONE ROW: WPML's icon for where it stands.
	 *
	 * The wheel turns while it is on its way — « une petite roue tourne pendant
	 * que la trad est en cours, sur chaque langue concernée ». Pressed while it
	 * only waits, it takes that language back out of the queue before it costs
	 * anything. Every other icon opens the translation of that language.
	 *
	 * @param array{show:string,base:string,why:string} $st
	 */
	public static function lang_icon( array $o, string $code, array $st, string $name ): string {
		$show  = (string) ( $st['show'] ?? 'missing' );
		$title = sprintf(
			/* translators: 1: the language, 2: where it stands */
			__( '%1$s: %2$s', 'dazont-ecom' ),
			$name,
			self::state_said( $show )
		);
		if ( 'error' === $show && '' !== (string) ( $st['why'] ?? '' ) ) {
			$title .= ' — ' . (string) $st['why'];
		}
		$cls = 'dze-trd-ico is-' . $show;
		if ( 'queued' === $show ) {
			$title .= ' — ' . __( 'press to take it out of the queue', 'dazont-ecom' );
			return sprintf(
				'<button type="button" class="%1$s dze-trd-cancel" data-lang="%2$s" title="%3$s" aria-label="%3$s"><span class="dze-trd-spin" aria-hidden="true"></span></button>',
				esc_attr( $cls ),
				esc_attr( $code ),
				esc_attr( $title )
			);
		}
		if ( 'running' === $show ) {
			return sprintf(
				'<span class="%1$s" title="%2$s" aria-label="%2$s"><span class="dze-trd-spin" aria-hidden="true"></span></span>',
				esc_attr( $cls ),
				esc_attr( $title )
			);
		}
		return sprintf(
			'<a class="%1$s" href="%2$s" title="%3$s" aria-label="%3$s"><span class="dashicons %4$s" aria-hidden="true"></span></a>',
			esc_attr( $cls ),
			esc_url( self::editor_url( $o, $code ) ),
			esc_attr( $title ),
			esc_attr( self::state_icon( $show ) )
		);
	}

	/** Every language of one row, in the order of the column head. */
	public static function langs_cell( array $o, array $states, array $langs ): string {
		$ref   = self::ref( $o );
		$names = self::lang_names();
		$html  = '';
		foreach ( $langs as $code ) {
			$code  = (string) $code;
			$st    = $states[ $ref ][ $code ] ?? [ 'show' => 'missing', 'base' => 'missing', 'why' => '' ];
			$html .= '<span class="dze-trd-box" data-lang="' . esc_attr( $code ) . '" data-state="' . esc_attr( (string) $st['show'] ) . '">'
				. self::lang_icon( $o, $code, $st, (string) ( $names[ $code ] ?? strtoupper( $code ) ) )
				. '</span>';
		}
		return $html;
	}

	/** WordPress's own word for a publication status. */
	public static function status_label( string $status ): string {
		$obj = function_exists( 'get_post_status_object' ) ? get_post_status_object( $status ) : null;
		return $obj && ! empty( $obj->label ) ? (string) $obj->label : ucfirst( $status );
	}

	/** The rows of one page of one section. */
	public static function rows_html( array $scope, array $objects, array $langs, array $f, array $a ): string {
		$post = 'post' === (string) $scope['kind'];
		$cols = $post ? 5 : 4;
		if ( ! $objects ) {
			// AN EMPTY ANSWER SAYS WHICH EMPTY IT IS: "nothing" over a catalogue
			// of two thousand reads as a broken screen.
			$said = ( 'todo' === $f['tstatus'] && '' === $a['search'] && ! $a['term'] && '' === $f['pstatus'] )
				? __( 'Nothing to translate here: every one is translated and up to date.', 'dazont-ecom' )
				: __( 'Nothing matches these filters.', 'dazont-ecom' );
			return '<tr class="dze-trd-empty"><td colspan="' . (int) $cols . '">' . esc_html( $said ) . '</td></tr>';
		}
		$states = self::row_states( $objects, $langs );
		$html   = '';
		foreach ( $objects as $o ) {
			$ref   = self::ref( $o );
			$label = self::obj_label( $o );
			$html .= '<tr class="dze-trd-row" data-ref="' . esc_attr( $ref ) . '">';
			$html .= '<th scope="row" class="check-column"><input type="checkbox" class="dze-trd-pick" value="' . esc_attr( $ref ) . '" aria-label="' . esc_attr( sprintf(
				/* translators: %s: the name of the item */
				__( 'Select %s', 'dazont-ecom' ),
				$label
			) ) . '" /></th>';
			$html .= '<td class="dze-trd-title"><a href="' . esc_url( self::obj_edit_url( $o ) ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a></td>';
			$html .= DZE_Hub::id_td( (int) $o['id'] );
			$html .= '<td class="dze-trd-langs">' . self::langs_cell( $o, $states, $langs ) . '</td>';
			if ( $post ) {
				$p     = get_post( (int) $o['id'] );
				$html .= '<td class="dze-trd-date">'
					. ( $p ? esc_html( mysql2date( 'Y-m-d', (string) ( $p->post_date ?? '' ) ) ) . '<br /><span>' . esc_html( self::status_label( (string) ( $p->post_status ?? '' ) ) ) . '</span>' : '' )
					. '</td>';
			}
			$html .= '</tr>';
		}
		return $html;
	}

	/** The pager under one section: where it is, the way through, and the size. */
	public static function pager_html( int $found, array $a ): string {
		$pages = max( 1, (int) ceil( $found / max( 1, $a['per'] ) ) );
		$at    = min( max( 1, $a['paged'] ), $pages );
		$btn   = static function ( int $to, string $label, string $said, bool $off ): string {
			return sprintf(
				'<button type="button" class="button dze-trd-page" data-page="%1$d"%2$s aria-label="%3$s">%4$s</button>',
				$to,
				$off ? ' disabled="disabled"' : '',
				esc_attr( $said ),
				$label
			);
		};
		$html  = '<span class="displaying-num">' . esc_html( sprintf(
			/* translators: %s: how many items match */
			_n( '%s item', '%s items', $found, 'dazont-ecom' ),
			number_format_i18n( $found )
		) ) . '</span>';
		if ( $pages > 1 ) {
			$html .= '<span class="pagination-links">'
				. $btn( 1, '&laquo;', __( 'First page', 'dazont-ecom' ), $at <= 1 )
				. $btn( $at - 1, '&lsaquo;', __( 'Previous page', 'dazont-ecom' ), $at <= 1 )
				. '<span class="paging-input">' . esc_html( sprintf(
					/* translators: 1: this page, 2: how many pages */
					__( '%1$s of %2$s', 'dazont-ecom' ),
					number_format_i18n( $at ),
					number_format_i18n( $pages )
				) ) . '</span>'
				. $btn( $at + 1, '&rsaquo;', __( 'Next page', 'dazont-ecom' ), $at >= $pages )
				. $btn( $pages, '&raquo;', __( 'Last page', 'dazont-ecom' ), $at >= $pages )
				. '</span>';
		}
		$opts = '';
		foreach ( self::PER_PAGE as $n ) {
			$opts .= '<option value="' . (int) $n . '"' . ( (int) $n === (int) $a['per'] ? ' selected="selected"' : '' ) . '>' . esc_html( number_format_i18n( $n ) ) . '</option>';
		}
		$html .= '<label class="dze-trd-perlab">' . esc_html__( 'Items per page', 'dazont-ecom' ) . ' <select class="dze-trd-per">' . $opts . '</select></label>';
		return $html;
	}

	/**
	 * THE TAXONOMY A SECTION IS FILTERED BY — the kind's own categories, as
	 * WPML offers ("Taxonomy Term") for its post types.
	 */
	public static function filter_taxonomy( array $scope ): string {
		if ( 'post' !== (string) $scope['kind'] || ! function_exists( 'get_object_taxonomies' ) ) {
			return '';
		}
		$trees = [];
		foreach ( (array) get_object_taxonomies( (string) $scope['type'], 'objects' ) as $tax => $obj ) {
			if ( is_object( $obj ) && ! empty( $obj->hierarchical ) && ! empty( $obj->public ) && ! empty( $obj->show_ui ) ) {
				$trees[] = (string) $tax;
			}
		}
		// THE KIND'S OWN CATEGORIES FIRST. On Kula a product has « Brands »
		// before « Categories », with no brand in use: the filter offered an
		// empty list where the shop expected its categories. A tree with
		// nothing in it is no filter at all.
		usort( $trees, static function ( string $a, string $b ): int {
			$rank = static fn( string $t ): int => in_array( $t, [ 'product_cat', 'category' ], true ) ? 0 : 1;
			return $rank( $a ) <=> $rank( $b );
		} );
		foreach ( $trees as $tax ) {
			if ( self::filter_terms( $tax ) ) {
				return $tax;
			}
		}
		return '';
	}

	/**
	 * The terms of that taxonomy that carry something, in the SOURCE language
	 * only — a French category filters French posts, which this screen never
	 * lists — by their term taxonomy id, which is what the list is filtered on.
	 *
	 * @return array<int,string> term_taxonomy_id => name
	 */
	public static function filter_terms( string $tax ): array {
		global $wpdb;
		if ( '' === $tax || ! $wpdb ) {
			return [];
		}
		// Asked twice per section — which tree, then its terms — read once.
		static $seen = [];
		if ( isset( $seen[ $tax ] ) ) {
			return $seen[ $tax ];
		}
		$tr  = $wpdb->prefix . 'icl_translations';
		$src = DZE_Wpml::default_language();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WPML's table and WordPress's own; one query for the whole list.
		$rows = DZE_Wpml::has_table( $tr )
			? (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT tt.term_taxonomy_id AS id, t.name AS name
				   FROM {$wpdb->term_taxonomy} tt
				   INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				   INNER JOIN {$tr} i ON i.element_id = tt.term_taxonomy_id AND i.element_type = %s AND i.language_code = %s
				  WHERE tt.taxonomy = %s AND tt.count > 0
			   ORDER BY t.name ASC LIMIT 400",
				DZE_Wpml::element_name( 'term', $tax ),
				$src,
				$tax
			), ARRAY_A )
			: (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT tt.term_taxonomy_id AS id, t.name AS name
				   FROM {$wpdb->term_taxonomy} tt
				   INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				  WHERE tt.taxonomy = %s AND tt.count > 0
			   ORDER BY t.name ASC LIMIT 400",
				$tax
			), ARRAY_A );
		// phpcs:enable
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r['id'] ] = (string) $r['name'];
		}
		$seen[ $tax ] = $out;
		return $out;
	}

	/** One section: its head, its own filters, its table and its pager. */
	public static function section_html( string $key, array $scope, array $f, array $langs ): string {
		$a                = self::section_args( [] );
		[ $objs, $found ] = self::section_page( $scope, $f, $a );
		$post             = 'post' === (string) $scope['kind'];
		$tax              = self::filter_taxonomy( $scope );
		$terms            = '' !== $tax ? self::filter_terms( $tax ) : [];
		ob_start();
		?>
		<details class="dze-trd-sec" open data-scope="<?php echo esc_attr( $key ); ?>" data-paged="1" data-per="<?php echo (int) $a['per']; ?>" data-orderby="title" data-order="asc">
			<summary class="dze-trd-sechead">
				<span class="dze-trd-sectitle"><?php echo esc_html( (string) $scope['label'] ); ?></span>
				<span class="dze-trd-seccount">(<?php echo esc_html( number_format_i18n( $found ) ); ?>)</span>
				<?php if ( ! empty( $scope['attr'] ) ) : ?>
					<span class="dze-trd-secnote"><?php esc_html_e( 'product attribute', 'dazont-ecom' ); ?></span>
				<?php endif; ?>
			</summary>
			<div class="dze-trd-secbody">
				<div class="dze-trd-secfilters">
					<label><?php esc_html_e( 'Filter by:', 'dazont-ecom' ); ?>
						<input type="search" class="dze-trd-search" placeholder="<?php esc_attr_e( 'Title', 'dazont-ecom' ); ?>" value="" />
					</label>
					<?php if ( $terms ) : ?>
						<label><?php esc_html_e( 'Taxonomy Term:', 'dazont-ecom' ); ?>
							<select class="dze-trd-term">
								<option value="0"><?php esc_html_e( 'All taxonomies', 'dazont-ecom' ); ?></option>
								<?php foreach ( $terms as $dze_tid => $dze_tname ) : ?>
									<option value="<?php echo (int) $dze_tid; ?>"><?php echo esc_html( $dze_tname ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>
				</div>
				<table class="widefat striped dze-trd-table">
					<thead><tr>
						<td class="check-column"><input type="checkbox" class="dze-trd-pickpage" aria-label="<?php esc_attr_e( 'Select every row on this page', 'dazont-ecom' ); ?>" /></td>
						<th class="dze-trd-title"><button type="button" class="dze-trd-sort is-asc" data-orderby="title"><?php esc_html_e( 'Title', 'dazont-ecom' ); ?><span class="dashicons dashicons-sort" aria-hidden="true"></span></button></th>
						<?php echo wp_kses_post( DZE_Hub::id_th() ); ?>
						<th class="dze-trd-langs">
							<?php foreach ( $langs as $dze_code ) : ?>
								<span class="dze-trd-box"><?php echo wp_kses_post( self::flag_only( (string) $dze_code ) ); ?></span>
							<?php endforeach; ?>
						</th>
						<?php if ( $post ) : ?>
							<th class="dze-trd-date"><button type="button" class="dze-trd-sort" data-orderby="date"><?php esc_html_e( 'Date / Status', 'dazont-ecom' ); ?><span class="dashicons dashicons-sort" aria-hidden="true"></span></button></th>
						<?php endif; ?>
					</tr></thead>
					<tbody><?php echo self::rows_html( $scope, $objs, $langs, $f, $a ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piece by piece in rows_html(). ?></tbody>
				</table>
				<div class="tablenav bottom dze-trd-pager"><div class="tablenav-pages"><?php echo self::pager_html( $found, $a ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piece by piece in pager_html(). ?></div></div>
			</div>
		</details>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * WHAT IS ON ITS WAY, in one line at the top — never a queue somebody has
	 * to go and look for. « Pour l'utilisateur, si la trad est automatique,
	 * aucun intérêt d'y aller. » The rows show it language by language; this
	 * says how many, and offers the two things worth doing about it.
	 *
	 * @return array{n:int,busy:bool,sent:int,stop:string,errors:int,last:string,review:int}
	 */
	public static function queue_said(): array {
		$refs   = [];
		$sent   = 0;
		$langs  = 0;
		$landed = 0;
		$first  = 0;
		$cheap  = 0;
		foreach ( self::asked() as $e ) {
			$refs[ self::ref( $e ) ] = true;
			if ( 'batch' === (string) $e['lane'] ) {
				$cheap += count( array_diff( $e['langs'], array_keys( (array) $e['land'] ) ) );
			}
			$first = $first && (int) $e['at'] ? min( $first, (int) $e['at'] ) : max( $first, (int) $e['at'] );
			$sent   += count( array_intersect( $e['langs'], array_keys( (array) $e['sent'] ) ) );
			$langs  += count( $e['langs'] );
			$landed += count( array_intersect( $e['langs'], array_keys( (array) $e['land'] ) ) );
		}
		// WHERE THE BATCHES STAND, from Anthropic's own counts, read at every
		// poll. « À l'écran rien n'indique que ça avance. » The line said
		// « being translated » over 540 items for as long as the batches took,
		// word for word the same at minute one and at minute forty: a screen
		// that does not move reads as a queue that does not move. Anthropic
		// counts each request as it is answered, so the bar can move while a
		// batch is still open — and the last time it was asked is said too,
		// because that is what shows the page is not frozen.
		$reqs     = 0;
		$answered = 0;
		$open     = 0;
		$since    = 0;
		$polled   = 0;
		foreach ( self::batches() as $b ) {
			$st = (string) ( $b['status'] ?? '' );
			if ( ! in_array( $st, [ 'creating', 'in_progress', 'canceling', 'ended' ], true ) ) {
				continue;
			}
			$open++;
			$n    = (int) ( $b['n'] ?? 0 );
			$c    = (array) ( $b['counts'] ?? [] );
			$done = (int) ( $c['succeeded'] ?? 0 ) + (int) ( $c['errored'] ?? 0 ) + (int) ( $c['canceled'] ?? 0 ) + (int) ( $c['expired'] ?? 0 );
			$reqs     += $n;
			$answered += 'ended' === $st ? $n : min( $n, $done );
			$at        = (int) ( $b['at'] ?? 0 );
			$since     = $since && $at ? min( $since, $at ) : max( $since, $at );
			$polled    = max( $polled, (int) ( $b['polled'] ?? 0 ) );
		}
		$errs = self::drain_log();
		$stop = self::stop_said();
		return [
			// The translations still in the queue, one per object and language,
			// and those back from Anthropic that are being written now.
			'langs'    => $langs,
			'landed'   => $landed,
			// The batches with Anthropic: how many, how many requests, how many
			// answered, since when, and when this site last asked.
			'batches'  => $open,
			'reqs'     => $reqs,
			'answered' => $answered,
			'since'    => $since,
			'polled'   => $polled,
			'now'      => time(),
			// IMMÉDIAT : ce qui est fait depuis que la file s'est remplie, depuis
			// quand, et la dernière réponse — la barre en est faite.
			// La voie de ce qui attend : économique dès qu'une demande l'est, et
			// combien de traductions un clic ferait passer en rapide.
			'lane'     => $cheap ? 'batch' : 'direct',
			'cheap'    => $cheap,
			'done'     => (int) ( ( (array) self::fresh_option( self::OPT_RUN, [] ) )['done'] ?? 0 ),
			'last'     => (int) ( ( (array) self::fresh_option( self::OPT_RUN, [] ) )['last'] ?? 0 ),
			'first'    => $first,
			'n'      => count( $refs ),
			// A STEP IS RUNNING RIGHT NOW — read from the lock MySQL holds, so a
			// step that died no longer reads as busy for a quarter of an hour.
			'busy'   => self::held( 'tick' ),
			// How many languages are with Anthropic, being translated there.
			'sent'   => $sent,
			// Why the queue is paused, when no text is to blame — and only
			// while something is waiting: an empty queue is not a paused one.
			'stop'   => $refs ? (string) ( $stop['why'] ?? '' ) : '',
			'errors' => count( $errs ),
			'last'   => (string) ( $errs[0]['why'] ?? '' ),
			'review' => self::review_count(),
		];
	}

	/** The line itself, printed once and kept up to date by the page. */
	public static function progress_notice( array $q ): void {
		?>
		<div class="notice notice-info inline dze-trd-progress" data-q="<?php echo esc_attr( (string) wp_json_encode( $q ) ); ?>" id="dze-trd-progress"<?php echo $q['n'] ? '' : ' hidden'; ?>>
			<p>
				<span class="dze-trd-spin" aria-hidden="true"></span>
				<strong id="dze-trd-progn"><?php
					echo esc_html( sprintf(
						/* translators: %s: how many items are on their way */
						_n( '%s item is being translated in the background.', '%s items are being translated in the background.', (int) $q['n'], 'dazont-ecom' ),
						number_format_i18n( (int) $q['n'] )
					) );
				?></strong>
				<?php
				// WHERE THE WORK IS, AND HOW LONG IT TAKES. It is with Anthropic,
				// in one batch at half price — the page only sends it and comes
				// back for it, which is why nothing can cut it any more. While the
				// queue is paused the line says that instead: it must never promise
				// « within minutes » over a queue that sends nothing.
				?>
				<span id="dze-trd-progwhy"><?php
					echo esc_html( '' !== (string) ( $q['stop'] ?? '' )
						? __( 'Nothing is sent while the queue is paused.', 'dazont-ecom' )
						: ( 'direct' === ( $q['lane'] ?? '' )
							? __( 'Translated right away, a few at a time, in the background: each language appears on its row as soon as it is back. You can leave this page.', 'dazont-ecom' )
							: __( 'Anthropic translates it in batches, at half price. A large send can take up to an hour — 24 hours at most — and each language appears on its row as soon as its batch is back. You can leave this page.', 'dazont-ecom' ) ) );
				?></span>
				<a href="<?php echo esc_url( self::url( [ 'tstatus' => 'progress' ] ) ); ?>"><?php esc_html_e( 'Show them', 'dazont-ecom' ); ?></a>
				&middot;
				<button type="button" class="button-link dze-trd-cancelall" id="dze-trd-cancelall"><?php esc_html_e( 'Cancel all', 'dazont-ecom' ); ?></button>
				<?php // TOO SLOW AT HALF PRICE? One press sends the rest right away. ?>
				<button type="button" class="button button-small dze-trd-hurry" id="dze-trd-hurry"<?php echo ! empty( $q['cheap'] ) ? '' : ' hidden'; ?>><?php esc_html_e( 'Translate the rest right away — normal price', 'dazont-ecom' ); ?></button>
			</p>
			<?php
			// HOW FAR IT HAS GOT, AND THAT THE PAGE IS ALIVE: Anthropic's own count
			// of the requests it has answered, since when, and when this site
			// last asked. Filled by the page from the same figures on every poll.
			?>
			<div class="dze-trd-bar" id="dze-trd-bar" hidden><span class="dze-trd-barfill" id="dze-trd-barfill"></span></div>
			<p class="dze-trd-progdetail" id="dze-trd-progdetail" hidden></p>
		</div>
		<?php
		// PAUSED, AND WHY — when it is not the fault of any text: the month's
		// budget reached, the key missing, Anthropic refusing the batch.
		// Nothing waiting is counted against anyone, and it resumes by itself.
		?>
		<div class="notice notice-warning inline dze-trd-stop" id="dze-trd-stop"<?php echo '' !== (string) ( $q['stop'] ?? '' ) ? '' : ' hidden'; ?>>
			<p><strong><?php esc_html_e( 'The queue is paused.', 'dazont-ecom' ); ?></strong>
				<span id="dze-trd-stopsaid"><?php echo esc_html( (string) ( $q['stop'] ?? '' ) ); ?></span>
				<?php esc_html_e( 'Nothing waiting is lost or counted as a failure: it resumes by itself.', 'dazont-ecom' ); ?></p>
		</div>
		<div class="notice notice-error inline dze-trd-failed" id="dze-trd-failed"<?php echo $q['errors'] ? '' : ' hidden'; ?>>
			<p id="dze-trd-failedsaid"><?php
				echo esc_html( sprintf(
					/* translators: 1: how many failed, 2: the most recent reason */
					_n( '%1$s translation could not be finished after three tries. Last reason: %2$s', '%1$s translations could not be finished after three tries. Last reason: %2$s', (int) $q['errors'], 'dazont-ecom' ),
					number_format_i18n( (int) $q['errors'] ),
					(string) $q['last']
				) );
			?></p>
		</div>
		<?php
	}

	public static function dash_body(): void {
		$all = self::picked_scope();
		if ( ! $all ) {
			echo '<div class="notice notice-warning inline" style="margin:16px 0;"><p>'
				. esc_html__( 'WPML is not set to translate any post type or taxonomy on this site. Open WPML → Settings and say what should be translated; this screen follows that answer and never overrides it.', 'dazont-ecom' )
				. '</p></div>';
			return;
		}
		$langs = self::target_codes();
		if ( ! $langs ) {
			echo '<p>' . esc_html__( 'This site has one language. There is nothing to translate into.', 'dazont-ecom' ) . '</p>';
			return;
		}
		$src   = DZE_Wpml::default_language();
		$names = self::lang_names();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$f     = self::filters( wp_unslash( $_GET ) );
		$dirty = '' !== $f['flang'] || '' !== $f['pstatus'] || 'todo' !== $f['tstatus'];
		$q     = self::queue_said();
		$picked = self::picked_from_list();
		self::progress_notice( $q );
		?>
		<?php if ( $picked['n'] || $picked['skipped'] ) : ?>
			<div class="notice notice-success inline dze-trd-picked"><p>
				<?php
				echo esc_html( sprintf(
					/* translators: %s: how many items were picked on a WordPress list */
					_n( '%s item picked from the list. Choose the languages under “Translate your content”.', '%s items picked from the list. Choose the languages under “Translate your content”.', (int) $picked['n'], 'dazont-ecom' ),
					number_format_i18n( (int) $picked['n'] )
				) );
				if ( $picked['skipped'] ) {
					echo ' ' . esc_html( sprintf(
						/* translators: %s: how many were already translations */
						_n( '%s was a translation already and was left out: only the original is translated.', '%s were translations already and were left out: only the originals are translated.', (int) $picked['skipped'], 'dazont-ecom' ),
						number_format_i18n( (int) $picked['skipped'] )
					) );
				}
				?>
			</p></div>
		<?php endif; ?>
		<div class="dze-trd" id="dze-trd">
			<section class="dze-trd-step1">
				<div class="dze-trd-head">
					<span class="dze-trd-step"><?php esc_html_e( 'Step 1', 'dazont-ecom' ); ?></span>
					<h2><?php esc_html_e( 'Select items for translation', 'dazont-ecom' ); ?></h2>
				</div>
				<form method="get" class="dze-trd-global" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					<label for="dze-trd-src"><?php esc_html_e( 'Source language:', 'dazont-ecom' ); ?></label>
					<select id="dze-trd-src" disabled="disabled" title="<?php esc_attr_e( 'Only the site language is translated: a translation is never translated again.', 'dazont-ecom' ); ?>">
						<option><?php echo esc_html( (string) ( $names[ $src ] ?? strtoupper( $src ) ) ); ?></option>
					</select>
					<label for="dze-trd-flang"><?php esc_html_e( 'translated to:', 'dazont-ecom' ); ?></label>
					<select name="flang" id="dze-trd-flang">
						<option value=""><?php esc_html_e( 'All languages', 'dazont-ecom' ); ?></option>
						<?php foreach ( $langs as $dze_code ) : ?>
							<option value="<?php echo esc_attr( $dze_code ); ?>" <?php selected( $f['flang'], $dze_code ); ?>><?php echo esc_html( (string) ( $names[ $dze_code ] ?? strtoupper( $dze_code ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="pstatus" aria-label="<?php esc_attr_e( 'Filter by publication status', 'dazont-ecom' ); ?>">
						<option value=""><?php esc_html_e( 'All statuses', 'dazont-ecom' ); ?></option>
						<option value="publish" <?php selected( $f['pstatus'], 'publish' ); ?>><?php echo esc_html( self::status_label( 'publish' ) ); ?></option>
						<option value="private" <?php selected( $f['pstatus'], 'private' ); ?>><?php echo esc_html( self::status_label( 'private' ) ); ?></option>
					</select>
					<select name="tstatus" aria-label="<?php esc_attr_e( 'Filter by translation status', 'dazont-ecom' ); ?>">
						<option value="all" <?php selected( $f['tstatus'], 'all' ); ?>><?php esc_html_e( 'All translation statuses', 'dazont-ecom' ); ?></option>
						<option value="todo" <?php selected( $f['tstatus'], 'todo' ); ?>><?php esc_html_e( 'Not completed', 'dazont-ecom' ); ?></option>
						<option value="update" <?php selected( $f['tstatus'], 'update' ); ?>><?php esc_html_e( 'Needs updating', 'dazont-ecom' ); ?></option>
						<option value="progress" <?php selected( $f['tstatus'], 'progress' ); ?>><?php esc_html_e( 'Translation in progress', 'dazont-ecom' ); ?></option>
						<option value="complete" <?php selected( $f['tstatus'], 'complete' ); ?>><?php esc_html_e( 'Translation complete', 'dazont-ecom' ); ?></option>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'dazont-ecom' ); ?></button>
					<?php if ( $dirty ) : ?>
						<a class="dze-trd-clear" href="<?php echo esc_url( self::url() ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span><?php esc_html_e( 'Clear filters', 'dazont-ecom' ); ?></a>
					<?php endif; ?>
					<span class="dze-trd-grow"></span>
					<button type="button" class="button dze-trd-selectall" id="dze-trd-selectall" title="<?php esc_attr_e( 'Selects every item that matches these filters, in every section and on every page', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Select All', 'dazont-ecom' ); ?></button>
				</form>
				<?php
				foreach ( $all as $dze_key => $dze_scope ) {
					echo self::section_html( (string) $dze_key, $dze_scope, $f, $langs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piece by piece in section_html().
				}
				?>
				<div class="dze-trd-selbar" id="dze-trd-selbar" hidden>
					<span class="dze-trd-selcount" id="dze-trd-selcount"></span>
					<button type="button" class="button-link" id="dze-trd-clearsel"><?php esc_html_e( 'Clear selection', 'dazont-ecom' ); ?></button>
					<span class="dze-trd-grow"></span>
					<button type="button" class="button button-primary" id="dze-trd-goto"><?php esc_html_e( 'Translate your content', 'dazont-ecom' ); ?> <span class="dashicons dashicons-arrow-down-alt" aria-hidden="true"></span></button>
				</div>
			</section>

			<section class="dze-trd-step2" id="dze-trd-step2" hidden>
				<div class="dze-trd-head">
					<span class="dze-trd-step"><?php esc_html_e( 'Step 2', 'dazont-ecom' ); ?></span>
					<h2><?php esc_html_e( 'Translate your content', 'dazont-ecom' ); ?></h2>
				</div>
				<table class="widefat dze-trd-pairs">
					<thead><tr>
						<th><?php esc_html_e( 'Source language', 'dazont-ecom' ); ?></th>
						<th><?php esc_html_e( 'Target language', 'dazont-ecom' ); ?></th>
						<th class="dze-trd-num"><?php esc_html_e( 'Words to translate', 'dazont-ecom' ); ?></th>
						<th>
							<select id="dze-trd-setall" aria-label="<?php esc_attr_e( 'Set every language at once', 'dazont-ecom' ); ?>">
								<option value=""><?php esc_html_e( 'Set all languages to…', 'dazont-ecom' ); ?></option>
								<option value="auto"><?php esc_html_e( 'Translate automatically', 'dazont-ecom' ); ?></option>
								<option value="none"><?php esc_html_e( 'Do nothing', 'dazont-ecom' ); ?></option>
							</select>
						</th>
						<th class="dze-trd-num"><?php esc_html_e( 'Estimated cost', 'dazont-ecom' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( array_values( $langs ) as $dze_i => $dze_code ) : ?>
						<tr data-lang="<?php echo esc_attr( $dze_code ); ?>">
							<?php if ( 0 === $dze_i ) : ?>
								<td rowspan="<?php echo (int) count( $langs ); ?>" class="dze-trd-src"><?php echo wp_kses_post( self::flag_only( $src ) ); ?> <?php echo esc_html( (string) ( $names[ $src ] ?? strtoupper( $src ) ) ); ?></td>
							<?php endif; ?>
							<td><?php echo wp_kses_post( self::flag_only( $dze_code ) ); ?> <?php echo esc_html( (string) ( $names[ $dze_code ] ?? strtoupper( $dze_code ) ) ); ?></td>
							<td class="dze-trd-num dze-trd-words">–</td>
							<td>
								<select class="dze-trd-method" data-lang="<?php echo esc_attr( $dze_code ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: the language */ __( 'What to do for %s', 'dazont-ecom' ), (string) ( $names[ $dze_code ] ?? $dze_code ) ) ); ?>">
									<option value="none"><?php esc_html_e( 'Do nothing', 'dazont-ecom' ); ?></option>
									<option value="auto"><?php esc_html_e( 'Translate automatically', 'dazont-ecom' ); ?></option>
								</select>
							</td>
							<td class="dze-trd-num dze-trd-cost">–</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot><tr>
						<td colspan="5">
							<?php
							printf(
								/* translators: %s: the model that translates */
								esc_html__( 'Translation model: %s', 'dazont-ecom' ),
								'<strong>' . esc_html( self::model_label() ) . '</strong>'
							);
							?>
							<a href="<?php echo esc_url( DZE_Screens::url( 'settings', 'translate' ) ); ?>"><?php esc_html_e( 'Change', 'dazont-ecom' ); ?></a>
						</td>
					</tr></tfoot>
				</table>

				<div class="dze-trd-card" id="dze-trd-existing" hidden>
					<h4><?php esc_html_e( 'Some of the content you want to translate is already translated', 'dazont-ecom' ); ?></h4>
					<p><?php esc_html_e( 'What do you want to do with the content that\'s already translated?', 'dazont-ecom' ); ?></p>
					<label><input type="radio" name="dze-trd-existing" value="leave" checked="checked" /> <?php esc_html_e( 'Leave existing translations as they are', 'dazont-ecom' ); ?></label>
					<label><input type="radio" name="dze-trd-existing" value="overwrite" /> <?php esc_html_e( 'Overwrite existing translations', 'dazont-ecom' ); ?></label>
				</div>

				<div class="dze-trd-card dze-trd-reviewbox">
					<label for="dze-trd-review"><strong><?php esc_html_e( 'What would you like to do when the translation is finished?', 'dazont-ecom' ); ?></strong></label>
					<select id="dze-trd-review">
						<option value="review"><?php esc_html_e( 'Review before publishing', 'dazont-ecom' ); ?></option>
						<option value="publish"><?php esc_html_e( 'Publish without review', 'dazont-ecom' ); ?></option>
					</select>
					<span class="description" id="dze-trd-reviewsaid"></span>
				</div>

				<?php
				// RAPIDE OU ÉCONOMIQUE, À CHAQUE ENVOI. « On pourrait ici garder de la
				// flexibilité et donner le choix : traduction rapide ou traduction
				// cheap. » Le prix et l'attente côte à côte : le choix est entre les deux.
				$dze_lane = self::lane();
				?>
				<div class="dze-trd-card dze-trd-lanebox">
					<strong><?php esc_html_e( 'How fast?', 'dazont-ecom' ); ?></strong>
					<label style="display:block;margin-top:4px;"><input type="radio" name="dze-trd-lane" value="direct" <?php checked( 'direct', $dze_lane ); ?> /> <?php esc_html_e( 'Right away — each language is back within a minute or two. Normal price.', 'dazont-ecom' ); ?></label>
					<label style="display:block;"><input type="radio" name="dze-trd-lane" value="batch" <?php checked( 'batch', $dze_lane ); ?> /> <?php esc_html_e( 'Cheap — half price. Anthropic answers when it has room: often minutes, sometimes hours, 24 hours at most. You can switch to « right away » while it waits.', 'dazont-ecom' ); ?></label>
				</div>

				<div class="dze-trd-card dze-trd-summary">
					<strong><?php esc_html_e( 'Summary:', 'dazont-ecom' ); ?></strong>
					<span id="dze-trd-sum"></span>
				</div>

				<p class="dze-trd-sendrow">
					<button type="button" class="button button-primary button-hero" id="dze-trd-send" disabled="disabled"><?php esc_html_e( 'Translate content', 'dazont-ecom' ); ?></button>
					<span class="description" id="dze-trd-sendsaid"></span>
				</p>
			</section>
		</div>

		<?php
		// WHAT IS ACTUALLY TRANSLATED, PER KIND OF CONTENT — a reading of
		// WPML's own rules, folded away: it is checked once, not every day.
		?>
		<details class="dze-set dze-trd-fields">
			<summary><?php esc_html_e( 'What is sent for each kind of content', 'dazont-ecom' ); ?></summary>
			<p class="description"><?php esc_html_e( 'Read from WPML\'s own rules. A field WPML is set to copy from the original is left alone here — writing it would only be overwritten on the next sync.', 'dazont-ecom' ); ?></p>
			<?php foreach ( $all as $dze_one ) : ?>
				<h4><?php echo esc_html( (string) $dze_one['label'] ); ?></h4>
				<table class="widefat striped">
					<tbody>
					<?php foreach ( DZE_Translate::field_report( (string) $dze_one['kind'], (string) $dze_one['type'] ) as $dze_r ) : ?>
						<tr>
							<td style="width:220px;"><strong><?php echo esc_html( $dze_r['label'] ); ?></strong></td>
							<td class="dze-trd-rule is-<?php echo esc_attr( (string) $dze_r['tone'] ); ?>"><?php echo esc_html( $dze_r['said'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</details>
		<?php
	}

	/** The model that translates, by the name the settings give it. */
	public static function model_label(): string {
		$id = self::model();
		if ( '' === $id && method_exists( 'DZE_Marketing_Ai', 'chosen_model' ) ) {
			$id = (string) DZE_Marketing_Ai::chosen_model();
		}
		$names = defined( 'DZE_Marketing_Ai::MODELS' ) ? (array) DZE_Marketing_Ai::MODELS : [];
		$label = (string) ( $names[ $id ] ?? $id );
		// "Claude Haiku 4.5 — fastest, cheapest": the name, not the pitch.
		return trim( (string) preg_replace( '/\s+—.*$/u', '', $label ) );
	}

	/** The model id an estimate is priced at. */
	public static function model_id(): string {
		$id = self::model();
		if ( '' === $id && method_exists( 'DZE_Marketing_Ai', 'chosen_model' ) ) {
			$id = (string) DZE_Marketing_Ai::chosen_model();
		}
		return $id;
	}

	/**
	 * WHAT WAS PICKED ON A WORDPRESS LIST, handed over once.
	 *
	 * « Send to translation » on the Posts or Products list does not send
	 * anything any more: it opens this dashboard with those items ticked, so
	 * the languages and the cost are chosen and read here — one way of sending,
	 * and it always shows what it is about to spend.
	 *
	 * @return array{refs:string[],n:int,skipped:int}
	 */
	public static function picked_from_list(): array {
		// READ ONCE PER REQUEST: the scripts ask first, the page after, and the
		// hand-over is thrown away the moment it is read.
		static $got = null;
		static $for = null;
		$now = md5( (string) wp_json_encode( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		if ( null !== $got && $for === $now ) {
			return $got;
		}
		$for = $now;
		$got = [ 'refs' => [], 'n' => 0, 'skipped' => 0 ];
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only; nothing is written.
		if ( isset( $_GET['picked'] ) && function_exists( 'get_current_user_id' ) ) {
			$key  = 'dze_tr_pick_' . (int) get_current_user_id();
			$refs = get_transient( $key );
			delete_transient( $key );
			$got['refs']    = is_array( $refs ) ? array_values( array_map( 'strval', $refs ) ) : [];
			$got['skipped'] = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
		} elseif ( ! empty( $_GET['only'] ) ) {
			$o = self::from_ref( sanitize_text_field( wp_unslash( $_GET['only'] ) ) );
			$got['refs'] = $o ? [ self::ref( $o ) ] : [];
		}
		// phpcs:enable
		$got['n'] = count( $got['refs'] );
		return $got;
	}

	/** How many words a text carries, as a reader would count them. */
	public static function count_words( string $text ): int {
		$plain = wp_strip_all_tags( $text );
		return (int) preg_match_all( '/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $plain );
	}

	/**
	 * WHAT A TRANSLATION WILL COST, before it is sent — at the prices the cost
	 * screen charges once it is done.
	 *
	 * The instructions travel with every call, a field travels with its name,
	 * and a script like Cyrillic takes about twice as many tokens per character
	 * as a Latin one. It is an estimate and the screen says so.
	 */
	public static function cost_of( int $chars, string $lang ): float {
		if ( $chars <= 0 || ! class_exists( 'DZE_Ai_Usage' ) || ! method_exists( 'DZE_Ai_Usage', 'estimate' ) ) {
			return 0.0;
		}
		$calls = max( 1, (int) ceil( $chars / self::CHUNK ) );
		$lang  = strtolower( $lang );
		$dense = in_array( $lang, [ 'ru', 'uk', 'bg', 'be', 'sr', 'mk', 'kk', 'el', 'ar', 'he', 'fa', 'hi', 'th' ], true );
		$cjk   = in_array( $lang, [ 'ja', 'zh', 'zh-hans', 'zh-hant', 'ko' ], true );
		$in    = (int) ceil( ( $calls * ( mb_strlen( self::prompt() ) + 400 ) + $chars * 1.1 ) / 3.5 );
		$out   = (int) ceil( $chars * 1.15 / ( $cjk ? 1.0 : ( $dense ? 2.0 : 3.2 ) ) );
		// THE QUEUE SENDS IN BATCHES, billed at half the price of the same
		// calls made one by one: the figure on screen is what will be paid.
		return DZE_Ai_Usage::estimate( self::model_id(), $in, $out ) * self::BATCH_RATE;
	}

	/**
	 * THE WORDS AND THE COST OF A SELECTION, language by language.
	 *
	 * `o` is what « Leave existing translations as they are » sends — a missing
	 * language whole, an outdated one only for the fields whose words moved, a
	 * complete one nothing — and `a` is what « Overwrite » sends: everything. A
	 * language already on its way, or back and waiting for a decision, costs
	 * nothing more either way.
	 *
	 * @param string[] $refs
	 * @return array<string,array<string,array{s:string,o:int,a:int,co:float,ca:float}>>
	 */
	public static function words_for( array $refs ): array {
		$langs = self::target_codes();
		$objs  = [];
		foreach ( $refs as $ref ) {
			$o = self::from_ref( (string) $ref );
			if ( $o ) {
				$objs[ self::ref( $o ) ] = $o;
			}
		}
		$states = self::row_states( array_values( $objs ), $langs );
		$out    = [];
		foreach ( $objs as $ref => $o ) {
			$texts = self::obj_read( $o );
			$aw    = 0;
			$ac    = 0;
			foreach ( $texts as $t ) {
				$aw += self::count_words( (string) $t );
				$ac += mb_strlen( (string) $t );
			}
			foreach ( $langs as $code ) {
				$st   = $states[ $ref ][ $code ] ?? [ 'show' => 'missing', 'base' => 'missing', 'why' => '' ];
				$busy = in_array( $st['show'], [ 'queued', 'running', 'review' ], true );
				$ow   = 0;
				$oc   = 0;
				if ( ! $busy && 'missing' === $st['base'] ) {
					$ow = $aw;
					$oc = $ac;
				} elseif ( ! $busy && 'stale' === $st['base'] ) {
					foreach ( self::stale_from( $o, (string) $code, $texts ) as $t ) {
						$ow += self::count_words( (string) $t );
						$oc += mb_strlen( (string) $t );
					}
				}
				$out[ $ref ][ $code ] = [
					's'  => $busy ? (string) $st['show'] : (string) $st['base'],
					'o'  => $ow,
					'a'  => $busy ? 0 : $aw,
					'co' => round( self::cost_of( $oc, (string) $code ), 5 ),
					'ca' => $busy ? 0.0 : round( self::cost_of( $ac, (string) $code ), 5 ),
				];
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// The dashboard's own presses
	// -------------------------------------------------------------------------

	/** One page of one section — or every ref it holds, for « Select All ». */
	public function ajax_items(): void {
		$this->screen_guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- screen_guard() checked it.
		$in  = wp_unslash( $_POST );
		$key = sanitize_text_field( (string) ( $in['scope'] ?? '' ) );
		// phpcs:enable
		$all = self::picked_scope();
		if ( ! isset( $all[ $key ] ) ) {
			wp_send_json_error( [ 'message' => __( 'That is not something this site translates.', 'dazont-ecom' ) ] );
		}
		$f = self::filters( (array) $in );
		$a = self::section_args( (array) $in );
		if ( ! empty( $in['refs'] ) ) {
			$a['paged'] = 1;
			$a['per']   = self::PICK_MAX;
			[ $refs, $found ] = self::section_page( $all[ $key ], $f, $a, true );
			wp_send_json_success( [ 'refs' => array_values( array_map( 'strval', $refs ) ), 'found' => (int) $found ] );
		}
		[ $objs, $found ] = self::section_page( $all[ $key ], $f, $a );
		$langs = self::target_codes();
		wp_send_json_success( [
			'rows'  => self::rows_html( $all[ $key ], $objs, $langs, $f, $a ),
			'pager' => self::pager_html( (int) $found, $a ),
			'found' => (int) $found,
			'label' => number_format_i18n( (int) $found ),
		] );
	}

	/** The words and the cost of what is ticked, a slice at a time. */
	public function ajax_words(): void {
		$this->screen_guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- screen_guard() checked it.
		$refs = isset( $_POST['refs'] ) ? array_slice( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['refs'] ) ), 0, 60 ) : [];
		wp_send_json_success( [ 'items' => self::words_for( $refs ) ] );
	}

	/**
	 * WHERE THE ROWS STAND NOW — asked by the page every few seconds while a
	 * wheel turns, so a language lands on its row the moment it is done.
	 */
	public function ajax_status(): void {
		$this->screen_guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- screen_guard() checked it.
		$refs  = isset( $_POST['refs'] ) ? array_slice( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['refs'] ) ), 0, 300 ) : [];
		$objs  = [];
		foreach ( $refs as $ref ) {
			$o = self::from_ref( (string) $ref );
			if ( $o ) {
				$objs[] = $o;
			}
		}
		$langs  = self::target_codes();
		$states = self::row_states( $objs, $langs );
		$cells  = [];
		foreach ( $objs as $o ) {
			$cells[ self::ref( $o ) ] = self::langs_cell( $o, $states, $langs );
		}
		// A PASS THAT WAITS FOR NOBODY. The page asks for one when the queue
		// holds work and nothing is running — WordPress's scheduler only wakes
		// when somebody visits the site.
		$q = self::queue_said();
		if ( $q['n'] && ! $q['busy'] ) {
			self::kick_drain();
		}
		wp_send_json_success( [ 'cells' => $cells, 'queue' => $q ] );
	}

	/** Takes one language of one row — or the whole row — back out of the queue. */
	public function ajax_cancel(): void {
		$this->screen_guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- screen_guard() checked it.
		$o    = self::from_ref( isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '' );
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		// phpcs:enable
		if ( ! $o ) {
			wp_send_json_error( [ 'message' => __( 'Unknown object.', 'dazont-ecom' ) ] );
		}
		$ref    = self::ref( $o );
		$busy   = '' !== $lang && in_array( $lang, (array) ( self::running()[ $ref ] ?? [] ), true );
		$n      = self::cancel( $ref, $lang );
		$langs  = self::target_codes();
		$states = self::row_states( [ $o ], $langs );
		wp_send_json_success( [
			'cell'    => self::langs_cell( $o, $states, $langs ),
			'queue'   => self::queue_said(),
			'message' => $busy
				? __( 'It is already with Anthropic and cannot be stopped any more. It will arrive in « To review » and will not be published without your review.', 'dazont-ecom' )
				: ( $n ? __( 'Taken out of the queue. Nothing was spent on it.', 'dazont-ecom' ) : __( 'It was not in the queue any more.', 'dazont-ecom' ) ),
		] );
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
		// OPENED WITHOUT A LANGUAGE, IT OPENS ON ONE WAITING FOR A DECISION.
		// « J'ai été redirigé vers la page de traduction en russe avant de
		// pouvoir accepter » — Russian was the first language not done, while
		// the four that came back were Polish, French, German and Spanish.
		$held = (array) ( self::waiting( $o )['langs'] ?? [] );
		foreach ( array_keys( $targets ) as $code ) {
			if ( isset( $held[ $code ] ) ) {
				return [ $o, (string) $code ];
			}
		}
		// Then the one that needs work. A screen that opens on a language
		// already finished is a screen that asks a question nobody came with.
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
		$args = [ 'tab' => 'dashboard', 'ref' => self::ref( $o ) ];
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
			<a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'Back to the dashboard', 'dazont-ecom' ); ?></a>
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
		<div class="dze-tr-editor" data-ref="<?php echo esc_attr( self::ref( $o ) ); ?>" data-lang="<?php echo esc_attr( $lang ); ?>" data-waiting="<?php echo (int) count( (array) ( self::waiting( $o )['langs'] ?? [] ) ); ?>">
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
					title="<?php esc_attr_e( 'Throws away what was translated into this language, and only this one. The translation is left exactly as it is.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Discard this language', 'dazont-ecom' ); ?></button>
				<span class="dze-cb-panelstate" id="dze-tr-publishstate"></span>
			</p>
			<?php
			// WHAT IS STILL WAITING ON THIS OBJECT, and the way to it. Filled
			// after a decision: « accepté mais toujours là » was the other
			// languages, still waiting, with nothing on the page to say so.
			?>
			<div class="notice notice-info inline dze-tr-nextbox" id="dze-tr-nextbox" role="status" hidden><p></p></div>
		</div>
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
	 * WHICH LANGUAGES OF THESE ROWS CAME BACK AND WAIT FOR A DECISION, in one
	 * query per kind of table — the icon that says « needs review ».
	 *
	 * @return array<string,string[]> ref => languages waiting
	 */
	public static function page_waiting_langs( array $objects ): array {
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
		foreach ( [ [ $posts, $wpdb->postmeta, 'post_id' ], [ $terms, $wpdb->termmeta, 'term_id' ] ] as $dze_set ) {
			[ $ids, $table, $col ] = $dze_set;
			if ( ! $ids ) {
				continue;
			}
			$in   = implode( ',', array_map( 'intval', array_keys( $ids ) ) );
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT {$col} AS oid, meta_value AS v FROM {$table} WHERE meta_key = %s AND {$col} IN ( {$in} )",
				DZE_Translate::META_WAIT
			), ARRAY_A );
			foreach ( $rows as $r ) {
				$ref = $ids[ (int) ( $r['oid'] ?? 0 ) ] ?? '';
				$row = json_decode( (string) ( $r['v'] ?? '' ), true );
				if ( '' !== $ref && is_array( $row ) && ! empty( $row['langs'] ) ) {
					$out[ $ref ] = array_map( 'strval', array_keys( (array) $row['langs'] ) );
				}
			}
		}
		// phpcs:enable
		return $out;
	}

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

	/**
	 * ONE PAGE OF A KIND, asked of WPML's own tables — narrowed IN the query
	 * that pages, never after it.
	 *
	 * The list used to page EVERY object of the kind and then drop, row by
	 * row, the ones WPML is satisfied with — so the pager counted the whole
	 * catalogue while the page showed two lines: "page 1 à 3 mais seulement 2
	 * lignes sont visibles. C'est bugé ?" A figure and the rows under it answer
	 * the same question.
	 *
	 * Without options it answers the automatic pass's question — what this
	 * shop still owes, as Settings → Translation says (new, updates, or both).
	 * The dashboard passes WPML's own filters in `$opt`:
	 *   - mode: `todo` (not completed: a language missing or marked), `update`
	 *     (marked), `complete` (every language there and none marked), `all`;
	 *   - search: words in the title; term: a category's term taxonomy id;
	 *   - status: `publish` or `private`; orderby `title`|`date`, order;
	 *   - refs: every ref at once (up to `$per`), for « Select All ».
	 *
	 * @param string[] $targets The languages the question is about.
	 * @return array{0:array<int,array|string>,1:int}|null NULL when WPML's
	 *         tables cannot be read — the caller then pages everything instead.
	 *
	 * PUBLIC because the automatic pass asks the SAME question. A second
	 * reading written beside this one is how a screen and the pass that feeds
	 * it start disagreeing about which objects are work.
	 */
	public static function todo_page( array $scope, string $src, array $targets, int $paged, int $per, bool $todo_only = true, array $opt = [] ): ?array {
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
		$term  = ( 'term' === $scope['kind'] );
		$mode  = (string) ( $opt['mode'] ?? ( $todo_only ? 'owed' : 'all' ) );
		$refs  = ! empty( $opt['refs'] );
		// ONLY WHAT THE SHOP ACTUALLY SHOWS. A draft is a page nobody has
		// published; translating one pays a model to write eight languages of
		// something that may never go out. A private page IS published —
		// restricted, not unborn — so it stays, unless the filter asks for one.
		$status = (string) ( $opt['status'] ?? '' );
		$shown  = in_array( $status, [ 'publish', 'private' ], true )
			? "p.post_status = '" . esc_sql( $status ) . "'"
			: "p.post_status IN ('publish','private')";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WPML's own tables; language codes escaped above, everything else prepared.
		$join = $term
			? "INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = src.element_id
			   INNER JOIN {$wpdb->terms} tm ON tm.term_id = tt.term_id"
			: "INNER JOIN {$wpdb->posts} p ON p.ID = src.element_id AND {$shown}";
		// UN ATTRIBUT QUE RIEN N EMPLOIE N EST PAS UNE PAGE A TRADUIRE. « Pas
		// besoin de traduire des attributs non utilises. » Le test porte sur les
		// LIGNES de rattachement et non sur tt.count, qui est un cache et ment
		// des qu une passe l a laisse en arriere.
		$extra = ( $term && 0 === strpos( (string) $scope['type'], 'pa_' ) )
			? " AND EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} dze_use
			                  WHERE dze_use.term_taxonomy_id = tt.term_taxonomy_id )"
			: '';
		// THE FILTERS TRAVEL AS PLACEHOLDERS OF THE ONE QUERY, never prepared
		// apart and pasted in: a title holding « % » would be read as one.
		$args   = [ $name, $src ];
		$search = trim( (string) ( $opt['search'] ?? '' ) );
		if ( '' !== $search ) {
			$extra .= $term ? ' AND tm.name LIKE %s' : ' AND p.post_title LIKE %s';
			$args[] = '%' . ( method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $search ) : addcslashes( $search, '_%\\' ) ) . '%';
		}
		$tid = (int) ( $opt['term'] ?? 0 );
		if ( $tid > 0 && ! $term ) {
			$extra .= " AND EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} dze_in
			                WHERE dze_in.object_id = src.element_id AND dze_in.term_taxonomy_id = %d )";
			$args[]  = $tid;
		}
		$pick  = $term ? 'tt.term_id' : 'src.element_id';
		$by    = ( ! $term && 'date' === ( $opt['orderby'] ?? '' ) ) ? 'p.post_date' : ( $term ? 'tm.name' : 'p.post_title' );
		$dir   = 'desc' === ( $opt['order'] ?? '' ) ? 'DESC' : 'ASC';
		$group = $term ? 'src.element_id, tt.term_id, tm.name' : 'src.element_id, p.post_title, p.post_date';
		$marks = $has_s ? "LEFT JOIN {$st} s ON s.translation_id = t.translation_id" : '';
		$need  = $has_s ? 'MAX( COALESCE( s.needs_update, 0 ) ) = 1' : '0 = 1';
		$miss  = "COUNT( DISTINCT t.language_code ) < {$want}";
		if ( 'owed' === $mode ) {
			// NOUVEAUX, MISES A JOUR, OU LES DEUX — comme la boutique l'a dit.
			$having = ' HAVING ' . self::owed_clause( $want, $need );
		} elseif ( 'todo' === $mode ) {
			// « NOT COMPLETED », comme WPML : une langue manque, ou WPML la
			// veut à nouveau.
			$having = " HAVING {$miss} OR {$need}";
		} elseif ( 'update' === $mode ) {
			$having = " HAVING {$need}";
		} elseif ( 'complete' === $mode ) {
			$having = " HAVING NOT ( {$miss} ) AND NOT ( {$need} )";
		} else {
			// "SHOW THEM ALL" IS THE SAME QUERY WITHOUT THE NARROWING, never a
			// second reading.
			$having = '';
		}
		$body  = "FROM {$tr} src
			{$join}
			LEFT JOIN {$tr} t ON t.trid = src.trid AND t.element_id <> src.element_id
			      AND t.element_type = src.element_type AND t.language_code IN ( {$in} )
			{$marks}
			WHERE src.element_type = %s AND src.language_code = %s {$extra}
			GROUP BY {$group}{$having}";
		$found = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM ( SELECT src.element_id {$body} ) dze_todo",
			...$args
		) );
		$ids = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT {$pick} AS dze_id {$body} ORDER BY {$by} {$dir}, {$pick} ASC LIMIT %d OFFSET %d",
			...array_merge( $args, [ $per, max( 0, ( $paged - 1 ) * $per ) ] )
		) );
		// phpcs:enable
		if ( $refs ) {
			// EVERY REF AT ONCE, without building an object per id: the query
			// has already said what kind each one is, and in which language.
			$out = [];
			foreach ( $ids as $id ) {
				$out[] = ( $term ? 'term' : 'post' ) . ':' . (int) $id . ':' . (string) $scope['type'];
			}
			return [ $out, $found ];
		}
		if ( ! $term && $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_map( 'intval', $ids ), false, false );
		}
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
	 * WPML'S OWN SYMBOLS, as its dashboard draws them in every language column.
	 *
	 * « Utiliser les symboles wpml servant déjà à illustrer ces status. » Read
	 * off WPML's own code: an orange ⓘ for not translated, two turning arrows
	 * for needs update, a green tick for complete, an eye for needs review, a
	 * warning for a translation that failed — and the wheel, drawn by the
	 * dashboard itself, for one on its way. Drawn from Dashicons, which
	 * WordPress ships on every admin screen: WPML's icon font is loaded only
	 * where WPML enqueues it, and a symbol that renders as a blank square on
	 * half the screens is worse than no symbol at all.
	 */
	public static function state_icon( string $state ): string {
		$icons = [
			'missing' => 'dashicons-info-outline',
			'stale'   => 'dashicons-update',
			'noise'   => 'dashicons-update',
			'done'    => 'dashicons-yes-alt',
			'review'  => 'dashicons-visibility',
			'error'   => 'dashicons-warning',
			'queued'  => 'dashicons-clock',
			'running' => 'dashicons-clock',
		];
		return (string) ( $icons[ $state ] ?? 'dashicons-minus' );
	}

	/**
	 * Those states in words — WPML's own, and in PHP: hard-coded in the
	 * JavaScript they were English on every shop.
	 */
	public static function state_said( string $state ): string {
		$words = [
			'missing' => __( 'not translated', 'dazont-ecom' ),
			'stale'   => __( 'needs update', 'dazont-ecom' ),
			// THE MODULE'S WHOLE POINT: WPML wants this one done again and not
			// one word of it has changed. It costs nothing to close.
			'noise'   => __( 'needs update — no words changed, closing it costs nothing', 'dazont-ecom' ),
			'done'    => __( 'complete', 'dazont-ecom' ),
			'queued'  => __( 'waiting in the translation queue', 'dazont-ecom' ),
			'running' => __( 'being translated now', 'dazont-ecom' ),
			'review'  => __( 'translated — waiting for your review', 'dazont-ecom' ),
			'error'   => __( 'the last translation failed', 'dazont-ecom' ),
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
			<p><?php esc_html_e( 'Nothing is waiting. Send something to translation from the Dashboard with « Review before publishing », and what comes back lands here.', 'dazont-ecom' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<?php
		// DEUX BOUTONS, ET LE NOMBRE DE LIGNES COCHEES. « Accept (x) ou Discard
		// (x). Voila ce qu il doit y avoir, rien de plus. »
		//
		// Il y en avait trois : « tout accepter », « accepter les cochees », et
		// « refuser les cochees ». « Tout accepter » ne faisait rien que la case
		// d en-tete ne fasse deja — cocher tout, puis accepter — et deux boutons
		// qui acceptent obligent a lire lequel fait quoi avant chaque presse.
		//
		// Le compte est celui des cochees, pas celui de la liste : un bouton qui
		// annonce sept quand on en a coche deux ment sur ce qu il va emporter.
		?>
		<p class="dze-cb-actions" style="max-width:980px;">
			<button type="button" class="button button-primary" id="dze-tr-acceptsel" disabled
				title="<?php esc_attr_e( 'Writes the ticked translations, in every language, exactly as they came back. Nothing is translated again and nothing is paid for.', 'dazont-ecom' ); ?>"><?php
				/* translators: %s: how many rows are ticked */
				echo esc_html( sprintf( __( 'Accept (%s)', 'dazont-ecom' ), number_format_i18n( 0 ) ) );
			?></button>
			<button type="button" class="button" id="dze-tr-dropsel" disabled
				title="<?php esc_attr_e( 'Throws away what came back for the ticked rows. The objects and their translations are not touched.', 'dazont-ecom' ); ?>"><?php
				/* translators: %s: how many rows are ticked */
				echo esc_html( sprintf( __( 'Discard (%s)', 'dazont-ecom' ), number_format_i18n( 0 ) ) );
			?></button>
			<span class="description" id="dze-tr-allstate" role="status"></span>
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
						<?php
						// ONE PRESS FOR THE WHOLE ROW: every language waiting on it,
						// as it came. « Accepté mais toujours là » — Review opens ONE
						// language, and the others stayed.
						?>
						<button type="button" class="button button-primary dze-tr-acceptrow" title="<?php esc_attr_e( 'Writes every language waiting on this row, exactly as it came back. Nothing is translated again and nothing is paid for.', 'dazont-ecom' ); ?>"><?php
							/* translators: %s: how many languages are waiting on this row */
							echo esc_html( sprintf( _n( 'Accept (%s language)', 'Accept (%s languages)', count( (array) $r['langs'] ), 'dazont-ecom' ), number_format_i18n( count( (array) $r['langs'] ) ) ) );
						?></button>
						<a class="button dze-tr-open" href="<?php echo esc_url( self::editor_url( $r, (string) ( ( (array) $r['langs'] )[0] ?? '' ) ) ); ?>"><?php esc_html_e( 'Review', 'dazont-ecom' ); ?></a>
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
