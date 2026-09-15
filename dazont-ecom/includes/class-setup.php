<?php
/**
 * Setup — what is configured, what is not, and where to go and do it.
 *
 * "Il va me falloir un tableau de bord de setup du plugin. Avec invitation à
 * paramétrer tout : clés api, gmc, intégrations en tout genre, check des
 * paramètres des modules." And, asked whether it should be a wizard one walks
 * through once or a screen that keeps answering: "le 2e oui, avec message
 * d'avertissement quand le setup n'a jamais été fait."
 *
 * That decides the whole design. A wizard would be ticked once and start lying
 * the next day — Google revokes an authorisation on a Tuesday, a key is
 * rotated, a module is switched on months later and wants a key nobody has
 * given it. So NOTHING here is stored. Every line is READ, at the moment the
 * screen is drawn, from whoever owns that answer:
 *
 *   - a key, from the module that sends it;
 *   - a connection, from `DZE_Health`'s last reading — never asked again here,
 *     because that reaches four providers over HTTP and an admin page waiting
 *     on that is a shop returning 504s;
 *   - an outside condition (WooCommerce, WPML, the scheduler), from the thing
 *     itself.
 *
 * WHAT IS REQUIRED IS WHAT AN ENABLED MODULE NEEDS. A shop that does not use
 * Klaviyo is not missing a Klaviyo key: it is a shop without Klaviyo. So every
 * step names the module it belongs to, and a step whose module is off is not
 * counted, not warned about, and not shown as a shortfall — which is the only
 * way the notice can be honest enough to leave switched on for ever.
 *
 * THE NOTICE IS NOT A NAG. It appears while something an enabled module needs
 * is missing, it says how many and where, and it goes on its own the moment
 * the last one is done. There is no "do not show again", because the thing it
 * hides would still be broken.
 */

defined( 'ABSPATH' ) || exit;

final class DZE_Setup {

	public const MENU_SLUG = 'dze-setup';

	/** Answered once per request: the notice asks on every admin page. */
	private static ?array $memo = null;

	public static function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			DZE_Screens::label( 'setup' ),
			DZE_Screens::label( 'setup' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function page_url(): string {
		return add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) );
	}

	/** Is a module on? A class file always exists, so this is the check. */
	private static function on( string $id ): bool {
		return ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( $id );
	}

	/** Where one settings tab is, by the name the plugin calls it. */
	private static function tab( string $name ): string {
		if ( ! class_exists( 'DZE_Marketing_Ai' ) ) {
			return '';
		}
		return (string) ( DZE_Marketing_Ai::tab_links()[ $name ] ?? '' );
	}

	// =========================================================================
	// The readings
	// =========================================================================

	/**
	 * EVERY LINE OF THE SCREEN, read now.
	 *
	 * A step is: what it is called, which module wants it, whether it is
	 * NEEDED (nothing works without it) or worth a look, what state it is in,
	 * what that state means in one sentence, and the one place to go and
	 * change it.
	 *
	 * state: done | todo | unknown | off
	 *   done    — it is set up.
	 *   todo    — it is not, and an enabled module wants it.
	 *   unknown — it cannot be read from here. Never reported as a "no": a
	 *             shop acting on an invented answer is worse off than one told
	 *             plainly that we cannot tell.
	 *   off     — the module that wants it is switched off; the row says so
	 *             rather than vanishing, or a shop wondering where Klaviyo
	 *             went has no screen that answers.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function steps(): array {
		$out = [];

		// ---- KEYS: what the plugin cannot work without ----
		$out[] = self::key_step(
			'anthropic',
			__( 'Anthropic key', 'dazont-ecom' ),
			__( 'Every word this plugin writes goes through it.', 'dazont-ecom' ),
			'marketing_ai',
			class_exists( 'DZE_Marketing_Ai' ) ? DZE_Marketing_Ai::api_key() : '',
			'DZE_ANTHROPIC_API_KEY'
		);
		$out[] = self::key_step(
			'fal',
			__( 'fal.ai key', 'dazont-ecom' ),
			__( 'Every photograph this plugin makes goes through it.', 'dazont-ecom' ),
			'content',
			class_exists( 'DZE_Content' ) ? DZE_Content::fal_key() : '',
			'DZE_FAL_API_KEY'
		);
		foreach ( self::klaviyo_steps() as $one ) {
			$out[] = $one;
		}

		// GMC is not a key in a field: it is an authorisation Google can take
		// back, so it is read from the connection rather than from a setting.
		$gmc_on   = self::on( 'gmc' );
		$gmc_done = $gmc_on && class_exists( 'DZE_Gmc' ) && DZE_Gmc::instance()->is_configured();
		$out[]    = [
			'id'     => 'gmc',
			'group'  => 'keys',
			'label'  => __( 'Google Merchant Center', 'dazont-ecom' ),
			'why'    => __( 'Connects the shop to your Merchant Center so promotions can be pushed to it.', 'dazont-ecom' ),
			'module' => 'gmc',
			'need'   => true,
			'state'  => ! $gmc_on ? 'off' : ( $gmc_done ? 'done' : 'todo' ),
			'said'   => $gmc_done
				? __( 'Connected, with a merchant account chosen.', 'dazont-ecom' )
				: __( 'Not connected.', 'dazont-ecom' ),
			'url'    => class_exists( 'DZE_Discounts' )
				? admin_url( 'admin.php?page=' . DZE_Discounts::MENU_SLUG_EVENTS . '&tab=gmc' )
				: '',
			'do'     => $gmc_done ? __( 'Open', 'dazont-ecom' ) : __( 'Connect', 'dazont-ecom' ),
		];

		// ---- THE SHOP AROUND IT: conditions outside the plugin ----
		$woo   = class_exists( 'WooCommerce' );
		$out[] = [
			'id'     => 'woocommerce',
			'group'  => 'shop',
			'label'  => __( 'WooCommerce', 'dazont-ecom' ),
			'why'    => __( 'The products, the orders and the categories this plugin works on are WooCommerce\'s.', 'dazont-ecom' ),
			'module' => '',
			'need'   => true,
			'state'  => $woo ? 'done' : 'todo',
			'said'   => $woo ? __( 'Installed and running.', 'dazont-ecom' ) : __( 'Not running — almost nothing here works without it.', 'dazont-ecom' ),
			'url'    => admin_url( 'plugins.php' ),
			'do'     => __( 'Plugins', 'dazont-ecom' ),
		];

		$out[] = self::wpml_step();
		$out[] = self::cron_step();
		$out[] = self::copy_step();

		// ---- WORTH A LOOK: it works on the shipped answers, and yours are better ----
		$out[] = self::spend_step();
		$out[] = self::prompts_step();
		$out[] = self::rules_step();
		$out[] = self::mesh_step();

		// A SUGGESTION IS NOT A SHORTFALL, and it must not wear the same word.
		// Done in ONE pass rather than in each reader: the figure at the top
		// counts what is ASKED of this shop, and a block chip or a warning
		// mark counting something else is one screen saying two things.
		$out = array_values( array_filter( $out ) );
		foreach ( $out as $i => $step ) {
			if ( empty( $step['need'] ) && 'todo' === $step['state'] ) {
				$out[ $i ]['state'] = 'idea';
			}
		}
		return $out;
	}

	/**
	 * One key, read the way it is actually sent.
	 *
	 * A key given as a CONSTANT is set up and cannot be changed from a screen,
	 * so the row says where it lives instead of offering a field that would
	 * not be read — a control that cannot act is a control nobody trusts.
	 */
	private static function key_step( string $id, string $label, string $why, string $module, string $key, string $constant ): array {
		$on   = self::on( $module );
		$has  = '' !== trim( $key );
		$fixed = defined( $constant ) && '' !== trim( (string) constant( $constant ) );
		return [
			'id'     => $id,
			'group'  => 'keys',
			'label'  => $label,
			'why'    => $why,
			'module' => $module,
			'need'   => true,
			'state'  => ! $on ? 'off' : ( $has ? 'done' : 'todo' ),
			'said'   => $has
				? ( $fixed
					? __( 'Set in wp-config.php, where it cannot be read from a screen.', 'dazont-ecom' )
					: __( 'Saved.', 'dazont-ecom' ) )
				: __( 'No key saved.', 'dazont-ecom' ),
			'url'    => $fixed ? '' : self::tab( __( 'General', 'dazont-ecom' ) ),
			'do'     => $has ? __( 'Change', 'dazont-ecom' ) : __( 'Add the key', 'dazont-ecom' ),
		];
	}

	/**
	 * THE EMAIL LINES ARE KLAVIYO'S OWN.
	 *
	 * `DZE_Klaviyo::setup_items()` has answered this question for months and
	 * nothing ever drew it — the one call site was guarded by `class_exists()`
	 * on a class that had been deleted, so it silently did nothing. Writing a
	 * second list here would be a second account of one thing, and two
	 * accounts of one thing disagree. The module says which of its own items
	 * are REQUIRED, because only it knows: three of the four are what
	 * `ready()` tests, and the fourth is a recommendation that must never be
	 * counted as a shortfall.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function klaviyo_steps(): array {
		if ( ! class_exists( 'DZE_Klaviyo' ) ) {
			return [];
		}
		$on  = self::on( 'klaviyo' );
		$out = [];
		foreach ( DZE_Klaviyo::setup_items() as $item ) {
			$done  = ! empty( $item['done'] );
			$out[] = [
				'id'     => 'klaviyo_' . sanitize_key( (string) $item['label'] ),
				'group'  => 'keys',
				'label'  => (string) $item['label'],
				'why'    => (string) ( $item['note'] ?? '' ),
				'module' => 'klaviyo',
				'need'   => ! empty( $item['need'] ),
				'state'  => ! $on ? 'off' : ( $done ? 'done' : 'todo' ),
				'said'   => $done ? __( 'Done.', 'dazont-ecom' ) : __( 'Not done yet.', 'dazont-ecom' ),
				'url'    => (string) ( $item['url'] ?? '' ),
				'do'     => $done ? __( 'Change', 'dazont-ecom' ) : __( 'Set it up', 'dazont-ecom' ),
			];
		}
		return $out;
	}

	/**
	 * WPML, and the one thing it has to be told before this plugin can work.
	 *
	 * What may be translated is WPML's answer and never ours, so this reads
	 * WPML's own row rather than asserting anything.
	 */
	private static function wpml_step(): array {
		$on = self::on( 'translate' );
		if ( ! class_exists( 'DZE_Wpml' ) ) {
			return [];
		}
		// ASK THE TABLE, not a constant. `get_active_languages()` reads WPML's
		// own rows when its filters are not loaded, which is the only reading
		// that answers in every request — and it is the same answer the rest
		// of the plugin works from, so this row and the translation screen
		// can never disagree about how many languages the site has.
		$langs = count( (array) DZE_Wpml::get_active_languages() );
		$there = class_exists( 'SitePress' ) || $langs > 0;
		return [
			'id'     => 'wpml',
			'group'  => 'shop',
			'label'  => __( 'WPML', 'dazont-ecom' ),
			'why'    => __( 'The translation module is a bridge to WPML: what a site translates is WPML\'s own setting, read from its tables.', 'dazont-ecom' ),
			'module' => 'translate',
			'need'   => true,
			'state'  => ! $on ? 'off' : ( $there ? ( $langs > 1 ? 'done' : 'todo' ) : 'todo' ),
			'said'   => ! $there
				? __( 'Not installed — the translation module has nothing to bridge to.', 'dazont-ecom' )
				: ( $langs > 1
					? sprintf(
						/* translators: %s: how many languages the site runs in */
						_n( '%s language declared.', '%s languages declared.', $langs, 'dazont-ecom' ),
						number_format_i18n( $langs )
					)
					: __( 'Installed, but only one language is declared.', 'dazont-ecom' ) ),
			'url'    => self::tab( __( 'Translation', 'dazont-ecom' ) ),
			'do'     => __( 'Open', 'dazont-ecom' ),
		];
	}

	/**
	 * Scheduled work, because everything that runs on its own runs on it.
	 *
	 * WP-Cron disabled is the silent failure this plugin fears most: nothing
	 * errors, the screens all work, and no automatic pass ever happens again.
	 */
	private static function cron_step(): array {
		$off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$as  = class_exists( 'ActionScheduler' ) || function_exists( 'as_enqueue_async_action' );
		return [
			'id'     => 'cron',
			'group'  => 'shop',
			'label'  => __( 'Scheduled work', 'dazont-ecom' ),
			'why'    => __( 'The nightly readings, the automatic passes and the writing queue all run on it.', 'dazont-ecom' ),
			'module' => '',
			'need'   => true,
			'state'  => $off && ! $as ? 'todo' : 'done',
			'said'   => $off
				? ( $as
					? __( 'WP-Cron is switched off; WooCommerce\'s scheduler is there and takes the work.', 'dazont-ecom' )
					: __( 'WP-Cron is switched off in wp-config.php and there is no scheduler behind it — nothing will run on its own.', 'dazont-ecom' ) )
				: __( 'Running.', 'dazont-ecom' ),
			'url'    => class_exists( 'DZE_Health' ) ? DZE_Health::page_url( 'health' ) : '',
			'do'     => __( 'Connections', 'dazont-ecom' ),
		];
	}

	/**
	 * IS THIS THE REAL SHOP OR A COPY OF IT?
	 *
	 * A staging site carries the same keys and the same scheduled hooks, and
	 * on a copy every outward write is refused. That is exactly right and
	 * completely invisible until somebody wonders why nothing was sent — so it
	 * is a line of this screen, and it is never a shortfall.
	 */
	private static function copy_step(): array {
		if ( ! class_exists( 'DZE_Site' ) ) {
			return [];
		}
		$copy = DZE_Site::is_copy();
		return [
			'id'     => 'site',
			'group'  => 'shop',
			'label'  => __( 'This site', 'dazont-ecom' ),
			'why'    => __( 'A copy of the shop carries the same keys, so nothing is ever sent outward from one.', 'dazont-ecom' ),
			'module' => '',
			'need'   => false,
			'state'  => $copy ? 'unknown' : 'done',
			'said'   => $copy
				? __( 'Read as a COPY of the shop: reading works, and nothing is written to Klaviyo or Google from here.', 'dazont-ecom' )
				: sprintf(
					/* translators: %s: the address the shop was set up on */
					__( 'The shop itself, at %s.', 'dazont-ecom' ),
					DZE_Site::known() ?: DZE_Site::host()
				),
			'url'    => self::tab( __( 'General', 'dazont-ecom' ) ),
			'do'     => __( 'Open', 'dazont-ecom' ),
		];
	}

	/**
	 * WHAT A MISTAKE CAN COST, which is the one setting nobody misses twice.
	 *
	 * "J'ai dépensé hier 40$ en génération d'images." The ceilings are what
	 * stands between a loop and a bill, and nought means NO ceiling — so a
	 * shop that has never set them is told, without being told it is wrong.
	 */
	private static function spend_step(): array {
		if ( ! class_exists( 'DZE_Ai_Usage' ) || ! self::on( 'content' ) ) {
			return [];
		}
		$per  = (int) DZE_Ai_Usage::fal_post_cap();
		$hour = (int) DZE_Ai_Usage::fal_hour_cap();
		$set  = $per > 0 && $hour > 0;
		return [
			'id'     => 'ceilings',
			'group'  => 'check',
			'label'  => __( 'Image ceilings', 'dazont-ecom' ),
			'why'    => __( 'At most so many photographs of one product, and so many for the whole shop, per hour.', 'dazont-ecom' ),
			'module' => 'content',
			// ASKED FOR, not suggested. Both ship at a real figure (ten and
			// sixty), so this is silent on every shop that has not touched
			// them — and a shop that HAS put one at nought has no guard at
			// all, which is what a $40 night looks like. Wanting no ceiling
			// is setting a high one, not a nought.
			'need'   => true,
			'state'  => $set ? 'done' : 'todo',
			'said'   => $set
				? sprintf(
					/* translators: 1: per product per hour, 2: for the shop per hour */
					__( '%1$s per product and %2$s for the shop, each hour.', 'dazont-ecom' ),
					number_format_i18n( $per ),
					number_format_i18n( $hour )
				)
				: __( 'One of them is at nought, which means no ceiling at all.', 'dazont-ecom' ),
			'url'    => self::tab( __( 'General', 'dazont-ecom' ) ),
			'do'     => __( 'Set them', 'dazont-ecom' ),
		];
	}

	/**
	 * The prompts: it works on the shipped ones, and yours are what make it
	 * yours.
	 *
	 * Never a shortfall — a shop running entirely on the shipped prompts is a
	 * working shop — so this counts how many were written here and says the
	 * rest are the defaults.
	 */
	private static function prompts_step(): array {
		if ( ! class_exists( 'DZE_Prompts' ) ) {
			return [];
		}
		$all  = array_keys( (array) DZE_Prompts::catalog() );
		$mine = 0;
		foreach ( $all as $id ) {
			$id = (string) $id;
			if ( DZE_Prompts::shipped_for( $id ) !== DZE_Prompts::text_for( $id ) ) {
				$mine++;
			}
		}
		return [
			'id'     => 'prompts',
			'group'  => 'check',
			'label'  => __( 'Prompts', 'dazont-ecom' ),
			'why'    => __( 'What the shop asks the model for, in its own words. Every one of them ships with a default and can be put back.', 'dazont-ecom' ),
			'module' => '',
			'need'   => false,
			'state'  => 'done',
			'said'   => sprintf(
				/* translators: 1: how many prompts the shop has written, 2: how many there are */
				__( '%1$s of %2$s written here; the rest are the shipped defaults.', 'dazont-ecom' ),
				number_format_i18n( $mine ),
				number_format_i18n( count( $all ) )
			),
			'url'    => self::tab( __( 'Product content', 'dazont-ecom' ) ),
			'do'     => __( 'Open', 'dazont-ecom' ),
		];
	}

	/** The standards the shop's own content is judged against. */
	private static function rules_step(): array {
		if ( ! class_exists( 'DZE_Diagnostic' ) || ! self::on( 'diagnostic' ) ) {
			return [];
		}
		$rows = (array) DZE_Diagnostic::rows();
		$on   = 0;
		foreach ( $rows as $r ) {
			if ( ! empty( $r['on'] ) ) {
				$on++;
			}
		}
		return [
			'id'     => 'rules',
			'group'  => 'check',
			'label'  => __( 'Content rules', 'dazont-ecom' ),
			'why'    => __( 'What the shop asks of a product or a category — so many photographs, so many words — and what the problem list is read against.', 'dazont-ecom' ),
			'module' => 'diagnostic',
			'need'   => false,
			'state'  => $on > 0 ? 'done' : 'todo',
			'said'   => $on > 0
				? sprintf(
					/* translators: 1: rules switched on, 2: rules in all */
					__( '%1$s of %2$s switched on.', 'dazont-ecom' ),
					number_format_i18n( $on ),
					number_format_i18n( count( $rows ) )
				)
				: __( 'None switched on, so nothing is being judged.', 'dazont-ecom' ),
			'url'    => self::tab( __( 'Content rules', 'dazont-ecom' ) ),
			'do'     => __( 'Open', 'dazont-ecom' ),
		];
	}

	/** Has the site's link graph ever been read? Nothing works before it has. */
	private static function mesh_step(): array {
		if ( ! class_exists( 'DZE_Mesh' ) || ! self::on( 'mesh' ) ) {
			return [];
		}
		$read = DZE_Mesh::orphan_count();
		return [
			'id'     => 'mesh',
			'group'  => 'check',
			'label'  => __( 'The link graph', 'dazont-ecom' ),
			'why'    => __( 'The reading of every internal link on the site. The linking work has nothing to go on until it has been made once.', 'dazont-ecom' ),
			'module' => 'mesh',
			'need'   => false,
			'state'  => null === $read ? 'todo' : 'done',
			'said'   => null === $read
				? __( 'The site has not been read yet.', 'dazont-ecom' )
				: DZE_Mesh::read_said(),
			'url'    => class_exists( 'DZE_Diagnostic' )
				? add_query_arg( [ 'page' => DZE_Diagnostic::MENU_SLUG, 'tab' => 'linking' ], admin_url( 'admin.php' ) )
				: '',
			'do'     => null === $read ? __( 'Read the site', 'dazont-ecom' ) : __( 'Open', 'dazont-ecom' ),
		];
	}

	/**
	 * THE SAME CHECKLIST, INLINE, where somebody is trying to use the thing.
	 *
	 * "Une fonction qui a besoin de quelque chose réglé EN DEHORS du plugin le
	 * dit, là où le réglage se fait." The promotion screen asks for this so
	 * that a shop opening it before Klaviyo is connected reads what to do
	 * rather than an empty panel. It draws the module's OWN items, so this
	 * list and the Setup page can never say two different things.
	 *
	 * @param array<int,array<string,mixed>> $items
	 */
	public static function render( string $title, array $items ): void {
		$left = 0;
		foreach ( $items as $one ) {
			if ( empty( $one['done'] ) ) {
				$left++;
			}
		}
		if ( ! $left ) {
			return; // A checklist with nothing left on it is a box in the way.
		}
		echo '<div class="dze-setup-inline"><h3>' . esc_html( $title ) . '</h3><ul>';
		foreach ( $items as $one ) {
			$done = ! empty( $one['done'] );
			$url  = (string) ( $one['url'] ?? '' );
			echo '<li class="' . ( $done ? 'is-done' : 'is-todo' ) . '">';
			echo '<span class="dashicons dashicons-' . ( $done ? 'yes-alt' : 'warning' ) . '"></span> ';
			echo '<strong>' . ( '' !== $url && ! $done
				? '<a href="' . esc_url( $url ) . '">' . esc_html( (string) $one['label'] ) . '</a>'
				: esc_html( (string) $one['label'] ) ) . '</strong>';
			if ( ! empty( $one['note'] ) ) {
				echo '<br><span class="description">' . esc_html( (string) $one['note'] ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul><p class="description"><a href="' . esc_url( self::page_url() ) . '">'
			. esc_html__( 'Everything this plugin needs set up', 'dazont-ecom' ) . '</a></p></div>';
	}

	// =========================================================================
	// What it adds up to
	// =========================================================================

	/**
	 * How far the setup has got, counting only what is actually asked of this
	 * shop.
	 *
	 * A step whose module is off is not counted — a shop without Klaviyo is
	 * not a shop missing a Klaviyo key — and neither is a step that merely
	 * invites a look. A figure that can never reach its total is a figure
	 * nobody reads twice.
	 *
	 * @return array{done:int,need:int,todo:array<int,string>}
	 */
	public static function score(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$done = 0;
		$need = 0;
		$todo = [];
		foreach ( self::steps() as $step ) {
			if ( empty( $step['need'] ) || 'off' === $step['state'] ) {
				continue;
			}
			$need++;
			if ( 'done' === $step['state'] ) {
				$done++;
			} else {
				$todo[] = (string) $step['label'];
			}
		}
		self::$memo = [ 'done' => $done, 'need' => $need, 'todo' => $todo ];
		return self::$memo;
	}

	/** Read again: a screen that has just changed a setting must not remember. */
	public static function forget(): void {
		self::$memo = null;
	}

	// =========================================================================
	// The notice
	// =========================================================================

	/**
	 * ONE SENTENCE, WHILE SOMETHING AN ENABLED MODULE NEEDS IS MISSING.
	 *
	 * Not dismissible: the thing it hides would still be missing, and a notice
	 * you can silence is a notice that teaches you to silence it. It goes on
	 * its own the moment the last one is done — and never appears on the Setup
	 * page itself, which is a screen telling you to go to the screen you are
	 * already on.
	 */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, self::MENU_SLUG ) ) {
			return;
		}
		$score = self::score();
		if ( ! $score['todo'] ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s" class="button button-primary" style="margin-left:6px;">%s</a></p></div>',
			esc_html__( 'Dazont Ecom is not set up yet.', 'dazont-ecom' ),
			esc_html( sprintf(
				/* translators: 1: how many things are missing, 2: the first of them */
				_n( '%1$s thing is still missing, starting with %2$s.', '%1$s things are still missing, starting with %2$s.', count( $score['todo'] ), 'dazont-ecom' ),
				number_format_i18n( count( $score['todo'] ) ),
				(string) $score['todo'][0]
			) ),
			esc_url( self::page_url() ),
			esc_html__( 'Set it up', 'dazont-ecom' )
		);
	}

	// =========================================================================
	// The screen
	// =========================================================================

	/** The blocks, in the order they are read. */
	public static function groups(): array {
		return [
			'keys'  => __( 'Keys and accounts', 'dazont-ecom' ),
			'shop'  => __( 'The shop around it', 'dazont-ecom' ),
			'check' => __( 'Worth a look', 'dazont-ecom' ),
		];
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		$steps = self::steps();
		$score = self::score();
		echo '<div class="wrap dze-wrap dze-admin"><h1>' . esc_html( DZE_Screens::label( 'setup' ) ) . '</h1>';
		// WHAT THE THING HOLDS TODAY, in one line, with the figures — the
		// first part of the shape every screen of this plugin is built from.
		echo '<p class="dze-setup-said">' . esc_html( $score['todo']
			? sprintf(
				/* translators: 1: how many are set up, 2: how many are asked for */
				__( '%1$s of %2$s set up. What is left is open below.', 'dazont-ecom' ),
				number_format_i18n( $score['done'] ),
				number_format_i18n( $score['need'] )
			)
			: sprintf(
				/* translators: %s: how many things were asked for */
				__( 'Everything this shop needs is set up — all %s of them. The rest is worth a look, not a worry.', 'dazont-ecom' ),
				number_format_i18n( $score['need'] )
			) ) . '</p>';
		foreach ( self::groups() as $key => $label ) {
			$rows = array_values( array_filter( $steps, static fn( array $s ): bool => $key === $s['group'] ) );
			if ( ! $rows ) {
				continue;
			}
			self::render_group( $key, $label, $rows );
		}
		echo '</div>';
	}

	/**
	 * One block, SHUT when it has nothing left to do.
	 *
	 * The screen opens on what is missing rather than on everything at once —
	 * the same `details.dze-set` the rest of the plugin is folded with, so
	 * there is one idiom to learn and not two.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 */
	private static function render_group( string $key, string $label, array $rows ): void {
		$todo = 0;
		$done = 0;
		foreach ( $rows as $r ) {
			if ( 'todo' === $r['state'] ) {
				$todo++;
			} elseif ( 'done' === $r['state'] ) {
				$done++;
			}
		}
		printf(
			'<details class="dze-set dze-setup-block" id="dze-setup-%s"%s><summary>%s <span class="dze-setup-chips">%s</span></summary>',
			esc_attr( $key ),
			$todo ? ' open' : '',
			esc_html( $label ),
			// A CHIP IS SILENT WHEN IT HAS NOTHING TO SAY: "0 to do" is a
			// figure that reads as a problem.
			$todo
				? '<span class="dze-setup-chip is-todo">' . esc_html( sprintf(
					/* translators: %s: how many are still to do */
					_n( '%s to do', '%s to do', $todo, 'dazont-ecom' ),
					number_format_i18n( $todo )
				) ) . '</span>'
				: '<span class="dze-setup-chip is-done">' . esc_html__( 'Done', 'dazont-ecom' ) . '</span>'
		);
		echo '<table class="wp-list-table widefat fixed striped dze-setup-list"><tbody>';
		foreach ( $rows as $row ) {
			self::render_row( $row );
		}
		echo '</tbody></table></details>';
	}

	/** @param array<string,mixed> $row */
	private static function render_row( array $row ): void {
		$state = (string) $row['state'];
		$mark  = [
			'done'    => [ 'yes-alt', __( 'Set up', 'dazont-ecom' ) ],
			'todo'    => [ 'warning', __( 'To do', 'dazont-ecom' ) ],
			'unknown' => [ 'info-outline', __( 'Worth knowing', 'dazont-ecom' ) ],
			'idea'    => [ 'lightbulb', __( 'Worth doing', 'dazont-ecom' ) ],
			'off'     => [ 'marker', __( 'Module off', 'dazont-ecom' ) ],
		][ $state ] ?? [ 'marker', '' ];
		echo '<tr class="dze-setup-row is-' . esc_attr( $state ) . '">';
		echo '<td class="dze-setup-state"><span class="dashicons dashicons-' . esc_attr( $mark[0] ) . '" title="'
			. esc_attr( $mark[1] ) . '"></span></td>';
		echo '<td><strong>' . esc_html( (string) $row['label'] ) . '</strong>'
			// WHAT IT IS FOR, in a line — and the reason it is a line and not
			// a paragraph is that a paragraph explaining a control usually
			// means the control is wrong.
			. '<br><span class="description">' . esc_html( (string) $row['why'] ) . '</span></td>';
		echo '<td class="dze-setup-says">' . esc_html( (string) $row['said'] ) . '</td>';
		// THE ACTION GOES WHERE THE SETTING IS MADE — never a second settings
		// surface beside the real one, which is how two screens start
		// disagreeing about what the shop holds.
		$url = (string) $row['url'];
		echo '<td class="dze-setup-act">';
		if ( 'off' === $state ) {
			printf(
				'<a class="button button-small" href="%s">%s</a>',
				esc_url( self::tab( __( 'Modules', 'dazont-ecom' ) ) ),
				esc_html__( 'Modules', 'dazont-ecom' )
			);
		} elseif ( '' !== $url ) {
			printf(
				'<a class="button button-small%s" href="%s">%s</a>',
				'todo' === $state ? ' button-primary' : '',
				esc_url( $url ),
				esc_html( (string) $row['do'] )
			);
		}
		echo '</td></tr>';
	}
}
