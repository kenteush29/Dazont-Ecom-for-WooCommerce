<?php
/**
 * WPML'S MENU SYNC, PRESSED FOR THE SHOP WHEN A TRANSLATION MADE HERE LANDS.
 *
 * « Est-ce possible d'utiliser la fonction de synchro du menu automatiquement,
 * de WPML, quand on traduit une nouvelle taxonomie ? Dans les menus on a
 * toujours des liens vers pages, ou liens vers catégories de produits. Pour
 * l'instant je l'ai fait manuellement. »
 *
 * WPML → WP Menus Sync compares every menu of the default language with its
 * translations and proposes the difference; « Apply changes » writes it. A
 * category translated here reached its menus only once somebody pressed it.
 * This presses it, with WPML's own code and in WPML's own two steps: the
 * preview (`render_items_tree_default()`, read back exactly as the browser
 * posts it) and the apply (`do_sync()`), fed the way its confirm screen feeds
 * it with every box ticked.
 *
 * TWO THINGS ARE NEVER DONE HERE, and stay a decision on WPML's screen:
 *   - REMOVING an item. WPML proposes it for any item of a translated menu
 *     with no counterpart in the original, and a link somebody added to the
 *     Russian menu on purpose looks exactly like that. An addition can be
 *     undone; a removal cannot.
 *   - CREATING a menu for a language that has none.
 *
 * Woken only by a translation of something that IS in a menu: a product in no
 * menu costs one query and nothing else. One run for everything written within
 * the minute, and never two at once — two runs reading the same preview would
 * both add the same item.
 *
 * @package DazontEcom
 */

defined( 'ABSPATH' ) || exit;

final class DZE_Menu_Sync {

	public const HOOK     = 'dze_menu_sync';
	public const OPT_LAST = 'dze_menu_sync_last';

	/** What a run applies: everything WPML proposes, except removals. */
	public const KINDS = [ 'add', 'mov', 'label_changed', 'url_changed', 'label_missing', 'url_missing', 'options_changed' ];

	/** Long enough for a batch landing object after object to make one run. */
	private const DELAY = 60;

	private const LOCK = 'dze_menu_sync';

	/** On unless the shop switched it off (Translations → Settings). */
	public static function enabled(): bool {
		$s = class_exists( 'DZE_Translate' ) ? DZE_Translate::get_settings() : [];
		return ! isset( $s['menus'] ) || ! empty( $s['menus'] );
	}

	/** A translation of $o has just been written: its menus may be short of it. */
	public static function wanted( array $o ): void {
		if ( ! self::enabled() || ! self::in_a_menu( $o ) ) {
			return;
		}
		self::schedule();
	}

	/**
	 * Whether a published menu item points at this object.
	 *
	 * Asked of the ORIGINAL: WPML's sync reads the default language's menus and
	 * finds each translation from there.
	 */
	public static function in_a_menu( array $o ): bool {
		global $wpdb;
		$id   = (int) ( $o['id'] ?? 0 );
		$type = (string) ( $o['type'] ?? '' );
		if ( $id < 1 || '' === $type || ! $wpdb ) {
			return false;
		}
		$kind = 'term' === (string) ( $o['kind'] ?? '' ) ? 'taxonomy' : 'post_type';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- menu items are posts; their target lives in three meta rows.
		$hit = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->postmeta} i
			 JOIN {$wpdb->posts} p ON p.ID = i.post_id AND p.post_type = 'nav_menu_item' AND p.post_status = 'publish'
			 JOIN {$wpdb->postmeta} k ON k.post_id = i.post_id AND k.meta_key = '_menu_item_type' AND k.meta_value = %s
			 JOIN {$wpdb->postmeta} t ON t.post_id = i.post_id AND t.meta_key = '_menu_item_object' AND t.meta_value = %s
			 WHERE i.meta_key = '_menu_item_object_id' AND i.meta_value = %s
			 LIMIT 1",
			$kind,
			$type,
			(string) $id
		) );
		// phpcs:enable
		return (bool) $hit;
	}

	/**
	 * One run, a minute from now — unless one is already waiting. Only a
	 * WAITING one counts: a run in progress read its preview before this
	 * translation existed, so it cannot be the one that adds it.
	 */
	private static function schedule(): void {
		$when = time() + self::DELAY;
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_get_scheduled_actions' ) ) {
			$waiting = as_get_scheduled_actions( [
				'hook'     => self::HOOK,
				'status'   => 'pending',
				'per_page' => 1,
			], 'ids' );
			if ( ! $waiting ) {
				as_schedule_single_action( $when, self::HOOK, [], 'dazont-ecom' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( $when, self::HOOK );
		}
	}

	/** The run itself: WPML's preview, then WPML's apply, removals left out. */
	public static function run(): void {
		if ( ! self::enabled() ) {
			return;
		}
		if ( ! self::take() ) {
			self::schedule(); // another run holds it: this one comes after.
			return;
		}
		$last = [ 'at' => time(), 'done' => [], 'langs' => [], 'left' => [], 'error' => '' ];
		try {
			$plan = self::plan();
			if ( null === $plan ) {
				$last['error'] = __( 'WPML\'s menu sync could not be loaded on this site.', 'dazont-ecom' );
			} else {
				$last['left'] = $plan['left'];
				if ( $plan['data'] ) {
					$plan['sync']->do_sync( $plan['data'] );
					$last['done']  = $plan['counts'];
					$last['langs'] = $plan['langs'];
				}
			}
		} catch ( \Throwable $ex ) {
			$last['error'] = $ex->getMessage();
		} finally {
			self::give();
		}
		update_option( self::OPT_LAST, $last, false );
	}

	/**
	 * WPML's own preview of every menu, read back as its confirm screen would
	 * post it with every box ticked except removals.
	 *
	 * @return array{data:array,counts:array<string,int>,langs:string[],left:array<string,int>,sync:object}|null
	 *         null when WPML's menu sync is not there to ask.
	 */
	public static function plan(): ?array {
		global $sitepress, $wpdb, $wpml_post_translations, $wpml_term_translations;
		if ( ! is_object( $sitepress ) || ! is_object( $wpml_post_translations ) || ! is_object( $wpml_term_translations ) || ! defined( 'WPML_PLUGIN_PATH' ) ) {
			return null;
		}
		if ( ! class_exists( 'ICLMenusSync' ) ) {
			$file = WPML_PLUGIN_PATH . '/inc/wp-nav-menus/menus-sync.php';
			if ( ! is_readable( $file ) ) {
				return null;
			}
			include_once $file;
		}
		if ( ! class_exists( 'ICLMenusSync' ) || ! method_exists( 'ICLMenusSync', 'render_items_tree_default' ) || ! method_exists( 'ICLMenusSync', 'do_sync' ) ) {
			return null;
		}
		$sync = new ICLMenusSync( $sitepress, $wpdb, $wpml_post_translations, $wpml_term_translations );
		// READ IN THE DEFAULT LANGUAGE, as WPML's own screen reads it — the
		// cron that runs this was fired by a visit to any language's domain.
		$sitepress->switch_lang( $sitepress->get_default_language() );
		$html = '';
		try {
			$sync->get_menus_tree();
			foreach ( array_keys( (array) $sync->menus ) as $menu_id ) {
				ob_start();
				try {
					$sync->render_items_tree_default( $menu_id );
				} finally {
					$html .= (string) ob_get_clean();
				}
			}
		} finally {
			$sitepress->switch_lang();
		}
		$plan         = self::read_preview( $html );
		$plan['sync'] = $sync;
		return $plan;
	}

	/**
	 * The preview's hidden fields, as the browser posts them, turned the way
	 * WPML's confirm screen turns them (WPML_Menu_Sync_Display):
	 * sync[kind][menu][item][lang] becomes sync[kind][menu][lang][item], and a
	 * move keeps its new position as one more key. Removals are counted in
	 * `left`, never passed on.
	 *
	 * @return array{data:array,counts:array<string,int>,langs:string[],left:array<string,int>}
	 */
	public static function read_preview( string $html ): array {
		$out = [ 'data' => [], 'counts' => [], 'langs' => [], 'left' => [] ];
		if ( ! preg_match_all( '/name="(sync\[[^"]+\])"\s+value="([^"]*)"/', $html, $found, PREG_SET_ORDER ) ) {
			return $out;
		}
		$pairs = [];
		foreach ( $found as $one ) {
			$pairs[] = rawurlencode( html_entity_decode( $one[1], ENT_QUOTES, 'UTF-8' ) ) . '=' . rawurlencode( html_entity_decode( $one[2], ENT_QUOTES, 'UTF-8' ) );
		}
		$posted = [];
		parse_str( implode( '&', $pairs ), $posted );
		$langs = [];
		foreach ( (array) ( $posted['sync'] ?? [] ) as $kind => $menus ) {
			$kind = (string) $kind;
			foreach ( (array) $menus as $menu_id => $items ) {
				foreach ( (array) $items as $item_id => $by_lang ) {
					foreach ( (array) $by_lang as $lang => $value ) {
						if ( ! in_array( $kind, self::KINDS, true ) ) {
							$out['left'][ $kind ] = (int) ( $out['left'][ $kind ] ?? 0 ) + 1;
							continue;
						}
						$out['data'][ $kind ][ $menu_id ][ $lang ][ $item_id ] = $value;
						$out['counts'][ $kind ] = (int) ( $out['counts'][ $kind ] ?? 0 ) + 1;
						$langs[ (string) $lang ] = true;
					}
				}
			}
		}
		$out['langs'] = array_keys( $langs );
		return $out;
	}

	/** What the last run did, in a sentence for the settings screen. */
	public static function last_said(): string {
		$last = get_option( self::OPT_LAST, [] );
		if ( ! is_array( $last ) || empty( $last['at'] ) ) {
			return __( 'It has not run yet on this site.', 'dazont-ecom' );
		}
		/* translators: %s: how long ago, e.g. "5 minutes" */
		$when = sprintf( __( 'Last run %s ago:', 'dazont-ecom' ), human_time_diff( (int) $last['at'] ) );
		if ( '' !== (string) ( $last['error'] ?? '' ) ) {
			return $when . ' ' . (string) $last['error'];
		}
		$done  = (array) ( $last['done'] ?? [] );
		$bits  = [];
		$added = (int) ( $done['add'] ?? 0 );
		if ( $added ) {
			/* translators: %s: how many menu items */
			$bits[] = sprintf( _n( '%s item added', '%s items added', $added, 'dazont-ecom' ), number_format_i18n( $added ) );
		}
		$other = array_sum( $done ) - $added;
		if ( $other > 0 ) {
			/* translators: %s: how many changes */
			$bits[] = sprintf( _n( '%s place or label brought in line', '%s places or labels brought in line', $other, 'dazont-ecom' ), number_format_i18n( $other ) );
		}
		$said = $bits
			? $when . ' ' . implode( ', ', $bits ) . ( ! empty( $last['langs'] ) ? ' (' . strtoupper( implode( ', ', (array) $last['langs'] ) ) . ').' : '.' )
			: $when . ' ' . __( 'the menus were already in line.', 'dazont-ecom' );
		$left = (int) array_sum( (array) ( $last['left'] ?? [] ) );
		if ( $left ) {
			$said .= ' ' . sprintf(
				/* translators: %s: how many removals WPML proposes */
				_n( 'WPML also proposes %s removal: that stays your decision, in WPML → WP Menus Sync.', 'WPML also proposes %s removals: that stays your decision, in WPML → WP Menus Sync.', $left, 'dazont-ecom' ),
				number_format_i18n( $left )
			);
		}
		return $said;
	}

	private static function take(): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $wpdb->prefix . self::LOCK ) );
	}

	private static function give(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $wpdb->prefix . self::LOCK ) );
	}
}
