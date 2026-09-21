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
				'label'  => __( 'Overview', 'dazont-ecom' ),
				// WHAT PRESSING "DAZONT ECOM" OPENS IS THE FIRST SUBMENU, not the
				// parent's own page: wp-admin/menu-header.php builds that anchor
				// from $submenu_items[0][2]. So this screen opens the menu by being
				// FIRST in menu_order() — no address has to move for that, and the
				// one that did was a public address changed for nothing.
				'slug'   => 'dazont-ecom-dashboard',
				// GATED ON NO MODULE, like the Logs and the Setup. The screen
				// that answers "what needs me" must not vanish with a switch:
				// switched off, the shop lost the one place that says where to
				// give its attention — and the menu then opened on whatever
				// happened to be first.
			],
			'content'      => [
				// NAMED BY WHAT IT DOES, not by one of the things it reads.
				// "Content" named the module. "Products" was worse: this screen
				// reads the WHOLE site — 52 criteria, of which 15 are about
				// articles and 5 about categories — so the name lied about the
				// subject on the very first tile. It is the reading of the shop
				// against the shop's own standards, and it says where to go.
				'label'  => __( 'Diagnostic', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-diagnostic',
				'module' => 'diagnostic',
			],
			'marketing'    => [
				'label'  => __( 'Marketing', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-marketing-events',
				// GATED PER TAB, NOT ON ONE MODULE. Held to 'discounts' alone,
				// switching the discount rules off took the whole entry with it
				// — the marketing calendar (marketing_ai) and Merchant Center
				// (gmc) went too, which are other modules. A disabled module
				// must leave zero trace; it must not take its neighbours' work.
				// With no module of its own, the entry is offered while ANY of
				// its tabs is (see offered()).
				'tabs'   => [
					'events'    => [ 'label' => __( 'Events & calendar', 'dazont-ecom' ), 'module' => 'marketing_ai' ],
					// The rules have a page of their own — the tab has to open
					// on something — taken out of the menu, so it is a tab here.
					'discounts' => [ 'label' => __( 'Discount rules', 'dazont-ecom' ), 'slug' => 'dazont-ecom-discounts', 'module' => 'discounts' ],
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
					// CE QUI A ETE TRADUIT. « Comment voir le resultat des
					// traductions, quelles pages ? » On ne pouvait pas : rien ne
					// listait ce qui avait ete ecrit. Les traductions ne passent pas
					// par la file — elles attendent sur l objet source — donc le
					// journal des passes automatiques n en savait rien non plus.
					'done'      => [ 'label' => __( 'Translated', 'dazont-ecom' ) ],
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
			// A BENCH IS NOT A PREFERENCE. This one has a prompt box and a
			// Generate button, it makes images and files them in the media
			// library — that is work, and it lived inside Settings. "Image lab,
			// peut-être dans le menu directement, c'est un petit module séparé."
			'lab'          => [
				'label'  => __( 'Image lab', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-lab',
				'module' => 'image_lab',
			],
			// TROIS ONGLETS, PARCE QU'IL Y A TROIS MOMENTS.
			//
			// « Je ne comprends pas pourquoi sur la page internal linking il
			// n'y a pas d'onglet pour me montrer ce qui a été fait et un onglet
			// review pour ce qui est en attente de vérif ? Je suis encore perdu
			// face à l'UI. »
			//
			// La liste avait été retirée d'ici pour ne pas dessiner un
			// deuxième tableau avec un deuxième compte — ce qui reste juste. Ce
			// qui ne l'était pas, c'est de laisser l'écran SANS porte d'entrée :
			// une phrase de notice quand il y a quelque chose, rien du tout
			// sinon, et le travail fait nulle part. Les onglets ci-dessous
			// n'ajoutent aucun tableau : ils rappellent les deux mêmes
			// fonctions que les écrans centraux, réduites à ce module.
			'linking'      => [
				'label'  => __( 'Internal linking', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-linking',
				'module' => 'mesh',
				'tabs'   => [
					'work'   => [ 'label' => __( 'The work', 'dazont-ecom' ) ],
					'review' => [ 'label' => __( 'To review', 'dazont-ecom' ) ],
					'done'   => [ 'label' => __( 'Done', 'dazont-ecom' ) ],
				],
			],
			'restock'      => [
				'label'  => __( 'Restock', 'dazont-ecom' ),
				'slug'   => self::PARENT,
				'module' => 'restock',
			],
			// Recommendations (frequently bought together). It registered a menu
			// entry without being declared here, so it could not be named in a
			// sentence, linked to, ordered or gated — it simply appeared, after
			// everything the catalogue knew about.
			'fbt'          => [
				'label'  => __( 'Recommendations', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-fbt',
				'module' => 'fbt',
			],
			'sourcing'     => [
				'label'  => __( 'Sourcing Assistant', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-explorer',
				'module' => 'sourcing',
			],
			// A TAB OF CONTENT while the diagnostic hosts it, a page of its own
			// otherwise: switching one module off must never hide a function
			// that has nothing to do with it.
			// L ECRAN CENTRAL A DISPARU. « Dans ce cas on supprime le menu to
			// review. On simplifie plutot que de complexifier. »
			//
			// Il tenait la liste de tout ce qui attend une decision. A force,
			// chaque module a repris la sienne : le maillage a ses onglets, les
			// traductions ont toujours eu les leurs, le banc des produits
			// accepte sur la ligne du produit, et les descriptions de categorie
			// se relisent desormais la ou elles se fabriquent. Il ne restait
			// qu une entree de menu qui promettait tout et montrait presque
			// rien — et dont le compte additionnait des choses qu elle
			// n affichait pas, ce qui a coute deux corrections.
			//
			// DZE_Queue::review_url() dit maintenant ou chaque genre de travail
			// se relit, en un seul endroit.
			// ONE BENCH, ONE SUBJECT PER TAB. The categories had a menu entry of
			// their own that held three lines and a switch — "menu Categories
			// existant et vide, aucun sens" — while the products had a bench.
			// Same work, two shapes, two places. Here they are two tabs of one
			// bench, and the articles will be a third when they get one.
			'bulk'         => [
				'label'  => __( 'Bulk writing', 'dazont-ecom' ),
				'slug'   => 'dazont-content-bulk',
				'tabs'   => [
					'products'   => [ 'label' => __( 'Products', 'dazont-ecom' ), 'module' => 'content' ],
					'categories' => [ 'label' => __( 'Categories', 'dazont-ecom' ), 'module' => 'category_content' ],
				],
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
					// WHAT THE PLUGIN DID ON ITS OWN, and the undo for it. It was the
					// second tab of an Automation page that left the menu, so the one
					// place a shop could take back what a nightly pass published was
					// reachable from nothing at all. A record of what happened is a
					// log, and this is where the logs are.
					'past'   => [ 'label' => __( 'Automatic passes', 'dazont-ecom' ), 'module' => 'automation' ],
				],
			],
			'settings'     => [
				'label'  => __( 'Settings', 'dazont-ecom' ),
				'slug'   => 'dazont-ecom-ai',
				'module' => 'marketing_ai',
				'tabs'   => [
					'general'        => [ 'label' => __( 'General', 'dazont-ecom' ) ],
					'sourcing'       => [ 'label' => __( 'Sourcing preferences', 'dazont-ecom' ), 'module' => 'sourcing' ],
					'content'        => [ 'label' => __( 'Product content', 'dazont-ecom' ), 'module' => 'content' ],
					'categories'     => [ 'label' => __( 'Categories', 'dazont-ecom' ), 'module' => 'category_content' ],
					'reviews'        => [ 'label' => __( 'Reviews', 'dazont-ecom' ), 'module' => 'reviews' ],
					'translate'      => [ 'label' => __( 'Translation', 'dazont-ecom' ), 'module' => 'translate' ],
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
			// A PAGE WITH TABS BUT NO MODULE OF ITS OWN IS OFFERED WHILE ANY OF
			// THEM IS. Otherwise it would either always show — with every tab
			// gone and nothing under the title — or be held to one of its
			// tabs' modules and take the others down with it.
			if ( '' === (string) ( $page['module'] ?? '' ) && ! empty( $page['tabs'] ) ) {
				foreach ( $page['tabs'] as $one ) {
					if ( self::on( (string) ( $one['module'] ?? '' ) ) ) {
						return true;
					}
				}
				return false;
			}
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
		// AND A SENTENCE NEVER NAMES A SCREEN NOBODY CAN FIND.
		//
		// This checked the LABEL and nothing else, so a page registered and
		// then taken out of the menu kept being named all over the admin:
		// "Dazont Ecom → Content to review" was printed on screen after
		// screen while the entry it named was in no menu at all. A page
		// HOSTED by another is findable — it wears its host's address, which
		// is the invariant this catalogue is built on — so only a page that
		// is neither in the menu nor hosted is refused.
		if ( ! in_array( $id, self::menu_order(), true ) && null === self::hosted_by( $id ) ) {
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
			'bulk',
			'linking',
			'lab',
			'translations',
			'marketing',
			'restock',
			'fbt',
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

	/**
	 * THE TAB STRIP, PRINTED IN ONE PLACE.
	 *
	 * Six screens built their own, each a near-copy of the others: the same
	 * markup, the same classes, the same count span — and one of them, the
	 * linking screen's, was hand-built with keys the catalogue had never heard
	 * of, so its second view could not be named in a sentence or linked to
	 * from anywhere. Two builders is how two screens start behaving
	 * differently while looking the same, which is the rule `check-methods`
	 * already holds block builders to.
	 *
	 * @param array<string,array{label:string,url:string,n?:int|null}> $items
	 */
	public static function strip( array $items, string $now, string $style = 'margin:12px 0 0;' ): string {
		if ( ! $items ) {
			return '';
		}
		$out = '<h2 class="nav-tab-wrapper" style="' . esc_attr( $style ) . '">';
		foreach ( $items as $id => $one ) {
			$n = array_key_exists( 'n', $one ) ? $one['n'] : null;
			// A TAB THE PAGE'S OWN SCRIPT HAS TO FIND. The Diagnostic keeps its
			// two counts in step without reloading, so it needs a hook on the
			// anchor — and that was reason enough for it to build its whole
			// strip by hand, markup, classes and all. Two optional keys here
			// and it does not have to.
			$cls  = 'nav-tab' . ( (string) $id === $now ? ' nav-tab-active' : '' );
			$cls .= '' !== (string) ( $one['class'] ?? '' ) ? ' ' . (string) $one['class'] : '';
			$att  = '';
			foreach ( (array) ( $one['data'] ?? [] ) as $k => $v ) {
				$att .= ' data-' . sanitize_key( (string) $k ) . '="' . esc_attr( (string) $v ) . '"';
			}
			$out .= sprintf(
				'<a class="%1$s"%2$s href="%3$s">%4$s%5$s</a>',
				esc_attr( $cls ),
				$att, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped above.
				esc_url( (string) ( $one['url'] ?? '' ) ),
				esc_html( (string) ( $one['label'] ?? $id ) ),
				null === $n ? '' : ' <span class="dze-tab-n">' . esc_html( number_format_i18n( (int) $n ) ) . '</span>'
			);
		}
		return $out . '</h2>';
	}
	public static function reorder_menu(): void {
		global $submenu;
		if ( isset( $submenu[ self::PARENT ] ) && is_array( $submenu[ self::PARENT ] ) ) {
			$submenu[ self::PARENT ] = self::ordered( $submenu[ self::PARENT ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the one place the menu's order is set.
		}
	}
}
