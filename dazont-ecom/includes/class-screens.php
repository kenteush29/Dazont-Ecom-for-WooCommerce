<?php
defined( 'ABSPATH' ) || exit;

/**
 * Every screen this plugin has, named once.
 *
 * "Dazont Ecom: Google Merchant Center is not answering. See what it said →
 * J'ai été redirigé sur la page du dessus. En fait c'est comme d'habitude, pas
 * pensé pour un utilisateur final. Je suis perdu et pas redirigé au bon
 * endroit."
 *
 * Three things had drifted apart, each kept by hand in its own file: the name
 * a menu entry shows, the name a sentence uses to send somebody there, and the
 * address behind the link. The Google sentence named "Marketing events", a
 * screen that had been renamed "Marketing" months before; the notice linked to
 * a settings tab that had moved to the Logs; and the only linker knew the
 * settings tabs, so "Dazont Ecom → Content to review" stayed plain text on
 * every screen that printed it.
 *
 * This is the one list. The menu reads its labels from it, the tab strips
 * read theirs from it, the sentences are BUILT from it (`name()`), the links
 * are resolved through it (`links()`), and `tools/test-trace.php` refuses any
 * sentence in the plugin naming a screen that is not here. A page renamed
 * here is renamed on the menu, in every sentence and behind every link in the
 * same edit, and cannot be renamed anywhere else.
 *
 * It hooks nothing and reads nothing: no option, no query, no count. The
 * counts that ride on a menu label or a tab are each page's own business, and
 * a catalogue that had to compute them would cost every admin page a query.
 *
 * A SCREEN WHOSE MODULE IS OFF IS NOT OFFERED — its name resolves to nothing
 * and no link is drawn to it — because a link to a page that is not there is
 * worse than no link. A screen HOSTED by another (Content to review is a tab
 * of Content while the diagnostic is on) keeps its own name and answers with
 * the host's address, so a sentence written for the page still lands.
 */
final class DZE_Screens {

	/** The top-level menu every page hangs from. */
	public const PARENT = 'dazont-ecom';

	/**
	 * @return array<string,array{label:string,slug:string,module?:string,parent?:string,hosted?:array{0:string,1:string},tabs?:array<string,array{label:string,module?:string,slug?:string}>}>
	 */
	public static function catalog(): array {
		return [
			'dashboard'    => [
				// NAMED BY THE QUESTION IT ANSWERS. "Je ne comprends pas la ou il
				// faut donner de l'attention": a screen called Dashboard does not
				// say it is the one that knows.
				'label'  => __( 'Waiting for you', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-dashboard',
				'module' => 'dashboard',
			],
			'content'      => [
				// "Content" names the module; "Products" names what is in there.
				'label'  => __( 'Products', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-diagnostic',
				'module' => 'diagnostic',
				'tabs'   => [
					'diagnostic' => [ 'label' => __( 'Diagnostic', 'dazont-ecom' ) ],
					'review'     => [ 'label' => __( 'To review', 'dazont-ecom' ), 'module' => 'queue' ],
					'products'   => [ 'label' => __( 'Products', 'dazont-ecom' ), 'module' => 'content' ],
				],
			],
			'marketing'    => [
				'label'  => __( 'Marketing', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-marketing-events',
				'module' => 'discounts',
				'tabs'   => [
					'events'    => [ 'label' => __( 'Events & calendar', 'dazont-ecom' ) ],
					// The rules have a page of their own — the tab has to open
					// on something — taken out of the menu, so it is a tab here.
					'discounts' => [ 'label' => __( 'Discount rules', 'dazont-ecom' ), 'slug' => 'dazont-ecom-discounts' ],
					'gmc'       => [ 'label' => __( 'Google Merchant Center', 'dazont-ecom' ), 'module' => 'gmc' ],
				],
			],
			'translations' => [
				'label'  => __( 'WPML Translations', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-translations',
				'module' => 'translate',
				'tabs'   => [
					'dashboard' => [ 'label' => __( 'Dashboard', 'dazont-ecom' ) ],
					'batch'     => [ 'label' => __( 'Batch', 'dazont-ecom' ) ],
					'review'    => [ 'label' => __( 'To review', 'dazont-ecom' ) ],
				],
			],
			'automation'   => [
				'label'  => __( 'Automation', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-automation',
				'module' => 'automation',
				'tabs'   => [
					'work' => [ 'label' => __( 'Tasks', 'dazont-ecom' ) ],
					'past' => [ 'label' => __( 'Past work', 'dazont-ecom' ) ],
				],
			],
			// A MENU OF ITS OWN. It runs by itself, like the translations, and
			// it was buried as the second tab of a screen called "Content" —
			// three clicks from the dashboard to look at the work of the day.
			'linking'      => [
				'label'  => __( 'Internal linking', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-linking',
				'module' => 'mesh',
			],
			'restock'      => [
				'label'  => __( 'Restock', 'dazont-ecom' ),
				'slug'   => self::PARENT,
				'module' => 'restock',
			],
			'sourcing'     => [
				'label'  => __( 'Sourcing Assistant', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-explorer',
				'module' => 'sourcing',
			],
			// A TAB OF CONTENT while the diagnostic hosts it, a page of its own
			// otherwise: switching one module off must never hide a function
			// that has nothing to do with it.
			'review'       => [
				'label'  => __( 'Content to review', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-queue',
				'module' => 'queue',
				'hosted' => [ 'content', 'review' ],
			],
			'bulk'         => [
				'label'  => __( 'Products AI bulk', 'dazont-ecom' ),
				'slug'   => 'dazont-content-bulk',
				'module' => 'content',
				'parent' => 'edit.php?post_type=product',
				'hosted' => [ 'content', 'products' ],
			],
			'shortcodes'   => [
				'label' => __( 'Shortcodes', 'dazont-ecom' ),
				'slug'  => 'dazont-ecom-shortcodes',
			],
			// THE PLUGIN'S OWN, gated on no module: the screen that says what is
			// not set up must not vanish with any module it reports on, and a
			// log is where you go when something is wrong.
			'setup'        => [
				'label' => __( 'Setup', 'dazont-ecom' ),
				'slug'  => 'dze-setup',
			],
			'logs'         => [
				'label' => __( 'Logs', 'dazont-ecom' ),
				'slug'  => 'dazont-ecom-logs',
				'tabs'  => [
					'calls'  => [ 'label' => __( 'AI calls', 'dazont-ecom' ) ],
					'spend'  => [ 'label' => __( 'Spend', 'dazont-ecom' ) ],
					'health' => [ 'label' => __( 'Connections', 'dazont-ecom' ), 'module' => 'health' ],
				],
			],
			'settings'     => [
				'label'  => __( 'Settings', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-ai',
				'module' => 'marketing_ai',
				'tabs'   => [
					'general'        => [ 'label' => __( 'General', 'dazont-ecom' ) ],
					'sourcing'       => [ 'label' => __( 'Sourcing Assistant', 'dazont-ecom' ), 'module' => 'sourcing' ],
					'content'        => [ 'label' => __( 'Product content', 'dazont-ecom' ), 'module' => 'content' ],
					'categories'     => [ 'label' => __( 'Categories', 'dazont-ecom' ), 'module' => 'category_content' ],
					'reviews'        => [ 'label' => __( 'Reviews', 'dazont-ecom' ), 'module' => 'reviews' ],
					'translate'      => [ 'label' => __( 'Translation', 'dazont-ecom' ), 'module' => 'translate' ],
					'lab'            => [ 'label' => __( 'Image lab', 'dazont-ecom' ), 'module' => 'image_lab' ],
					'discounts'      => [ 'label' => __( 'Discounts', 'dazont-ecom' ), 'module' => 'discounts' ],
					// The PREFERENCES of the marketing work — calendar languages,
					// countries, context, prompt. Not the work itself, which is
					// Dazont Ecom → Marketing: two screens, two names.
					'events'         => [ 'label' => __( 'Marketing events', 'dazont-ecom' ) ],
					'email'          => [ 'label' => __( 'Email campaigns', 'dazont-ecom' ), 'module' => 'klaviyo' ],
					// The STANDARDS the shop is read against, not the reading:
					// that is Dazont Ecom → Content.
					'diagnostic'     => [ 'label' => __( 'Content rules', 'dazont-ecom' ), 'module' => 'diagnostic' ],
					'transfer'       => [ 'label' => __( 'Transfer', 'dazont-ecom' ) ],
					'modules'        => [ 'label' => __( 'Modules', 'dazont-ecom' ) ],
				],
			],
			// A tab of Settings while that page is there, a page of its own
			// otherwise, so the modules can always be reached.
			'modules'      => [
				'label'  => __( 'Modules', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-modules',
				'hosted' => [ 'settings', 'modules' ],
			],
		];
	}

	/** Is a module on? A class file always exists, so this is the check. */
	private static function on( string $module ): bool {
		if ( '' === $module ) {
			return true;
		}
		return ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( $module );
	}

	/**
	 * Is that screen there to be sent to?
	 *
	 * A page whose module is off is not; a hosted page is, as long as its OWN
	 * module is on, since it then has an address of its own or its host's.
	 */
	public static function offered( string $id, string $tab = '' ): bool {
		$page = self::catalog()[ $id ] ?? null;
		if ( ! $page || ! self::on( (string) ( $page['module'] ?? '' ) ) ) {
			return false;
		}
		if ( '' === $tab ) {
			return true;
		}
		$one = $page['tabs'][ $tab ] ?? null;
		return null !== $one && self::on( (string) ( $one['module'] ?? '' ) );
	}

	/** The host this page is a tab of right now, or null when it stands alone. */
	public static function hosted_by( string $id ): ?array {
		$page = self::catalog()[ $id ] ?? null;
		if ( ! $page || empty( $page['hosted'] ) ) {
			return null;
		}
		[ $host, $tab ] = $page['hosted'];
		return self::offered( $host, $tab ) ? [ $host, $tab ] : null;
	}

	/** The name the menu shows. '' for a screen that is not in the catalogue. */
	public static function label( string $id, string $tab = '' ): string {
		$page = self::catalog()[ $id ] ?? null;
		if ( ! $page ) {
			return '';
		}
		if ( '' === $tab ) {
			return (string) $page['label'];
		}
		return (string) ( $page['tabs'][ $tab ]['label'] ?? '' );
	}

	/**
	 * The tabs of a page that are there to show, key => label.
	 *
	 * The tab strips read this, so a tab is named in one place and a tab whose
	 * module is off is not drawn.
	 *
	 * @return array<string,string>
	 */
	public static function tabs_of( string $id ): array {
		$out = [];
		foreach ( (array) ( self::catalog()[ $id ]['tabs'] ?? [] ) as $key => $one ) {
			if ( self::on( (string) ( $one['module'] ?? '' ) ) ) {
				$out[ (string) $key ] = (string) $one['label'];
			}
		}
		return $out;
	}

	/**
	 * Where that screen is. '' when it is not offered.
	 *
	 * A hosted page answers with its host's address — that is where the work
	 * is, and a sentence written for the page still lands on it.
	 */
	public static function url( string $id, string $tab = '' ): string {
		if ( ! self::offered( $id, $tab ) ) {
			return '';
		}
		$page = self::catalog()[ $id ];
		if ( '' === $tab ) {
			$host = self::hosted_by( $id );
			if ( null !== $host ) {
				return self::url( $host[0], $host[1] );
			}
		}
		$parent = (string) ( $page['parent'] ?? 'admin.php' );
		$args   = [ 'page' => (string) $page['slug'] ];
		if ( '' !== $tab ) {
			$own = (string) ( $page['tabs'][ $tab ]['slug'] ?? '' );
			if ( '' !== $own ) {
				$args = [ 'page' => $own ];
			} else {
				$args['tab'] = $tab;
			}
		}
		return add_query_arg( $args, admin_url( $parent ) );
	}

	/**
	 * The phrase a sentence uses to send somebody there.
	 *
	 * "Dazont Ecom → Logs → Connections" for the plugin's own pages; the
	 * settings tabs keep the shorter "Settings → General" every message in
	 * the plugin already uses. BUILT here, never typed: a sentence assembled
	 * from the catalogue cannot name a screen that was renamed.
	 */
	public static function name( string $id, string $tab = '' ): string {
		$label = self::label( $id );
		if ( '' === $label ) {
			return '';
		}
		if ( 'settings' === $id && '' !== $tab ) {
			/* translators: %s: the name of a settings tab */
			return sprintf( __( 'Settings → %s', 'dazont-ecom' ), self::label( $id, $tab ) );
		}
		/* translators: %s: the name of a screen in the Dazont Ecom menu */
		$out = sprintf( __( 'Dazont Ecom → %s', 'dazont-ecom' ), $label );
		if ( '' !== $tab ) {
			$out .= ' → ' . self::label( $id, $tab );
		}
		return $out;
	}

	/**
	 * Every phrase the plugin may write, and where it goes.
	 *
	 * The KEY is the phrase exactly as `name()` builds it, so the words a
	 * reader sees and the address behind them are one thing. Only screens
	 * that are there are listed.
	 *
	 * @return array<string,string> phrase => address.
	 */
	public static function links(): array {
		$out = [];
		foreach ( self::catalog() as $id => $page ) {
			if ( ! self::offered( $id ) ) {
				continue;
			}
			$out[ self::name( $id ) ] = self::url( $id );
			foreach ( array_keys( self::tabs_of( $id ) ) as $tab ) {
				$out[ self::name( $id, $tab ) ] = self::url( $id, $tab );
			}
		}
		return $out;
	}

	// =========================================================================
	// The menu, in the order it is read
	// =========================================================================

	/**
	 * The work first, the plumbing last.
	 *
	 * The menu used to come out in the order the modules happened to boot:
	 * Settings in the middle, Content after Translations, the Logs wherever.
	 * What a shop opens forty times a day is the work — what is short, what is
	 * waiting, what is planned — and what it opens when something is wrong is
	 * at the bottom, where it does not sit above the work every morning.
	 *
	 * @return string[] page ids, first to last.
	 */
	public static function menu_order(): array {
		return [
			'dashboard',
			'content',
			'linking',
			'review',
			'translations',
			'marketing',
			'restock',
			'sourcing',
			'shortcodes',
			'setup',
			'logs',
			'settings',
			'modules',
		];
	}

	/**
	 * Puts WordPress's submenu for this plugin in that order.
	 *
	 * Hooked late on `admin_menu`, after every module has registered its
	 * entry. Applied to the GLOBAL because that is the only place WordPress
	 * keeps a menu's order; an entry this catalogue does not know keeps its
	 * place among the work rather than being dropped.
	 *
	 * @param array<int,array> $items The submenu rows as WordPress holds them.
	 * @return array<int,array> The same rows, in reading order.
	 */
	public static function ordered( array $items ): array {
		$rank = [];
		foreach ( self::menu_order() as $i => $id ) {
			$rank[ (string) ( self::catalog()[ $id ]['slug'] ?? '' ) ] = $i;
		}
		// Unknown entries sit after the last piece of WORK and before the
		// plumbing, keeping their own relative order.
		$after = array_search( 'sourcing', self::menu_order(), true );
		$keyed = [];
		foreach ( array_values( $items ) as $n => $row ) {
			$slug = (string) ( $row[2] ?? '' );
			$r    = array_key_exists( $slug, $rank ) ? $rank[ $slug ] : (float) $after + 0.5;
			$keyed[] = [ $r, $n, $row ];
		}
		usort( $keyed, static fn( array $a, array $b ): int => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );
		return array_map( static fn( array $one ): array => $one[2], $keyed );
	}

	public static function reorder_menu(): void {
		global $submenu;
		if ( isset( $submenu[ self::PARENT ] ) && is_array( $submenu[ self::PARENT ] ) ) {
			$submenu[ self::PARENT ] = self::ordered( $submenu[ self::PARENT ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the one place the menu's order is set.
		}
	}
}
