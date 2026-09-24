<?php
defined( 'ABSPATH' ) || exit;

/**
 * Automation — the shop's own functions, run on a schedule, a few a day.
 *
 * It adds no ability of its own. Everything it launches is a function that is
 * already on the site, with its own screen, its own prompt and its own button:
 * the "Add internal links only" pass — on a category as on an article — the
 * category writer, the marketing calendar. What this module brings is the part
 * a person cannot do at scale: looking at the WHOLE site, seeing where each
 * page falls short of what it should be, and working through the list a little
 * at a time.
 *
 * Three rules hold it together:
 *
 * 1. It judges from the figures the owner sees. The panel on a category says
 *    "1116 words, 0 links — target 750 words and 5 links". Those figures come
 *    from DZE_Category_Content::state(), and that is what this module reads.
 *    Never a second reckoning that could disagree with the screen.
 * 2. It runs nothing itself. Work is handed to the writing queue — one job at
 *    a time, in the background, with its recovery and the monthly budget cap.
 * 3. It goes slowly, on purpose. One item per pass, spread over its period, a
 *    figure per day per task, and never the same page twice in a month. A site
 *    whose fifty pages all change on the same afternoon does not look like a
 *    site being looked after.
 *
 * A task says what it is called, which module owns it, what it works on — a
 * category, an article, the shop as a whole — how it is run (a queue job, or a
 * call of its own), at what rhythm, and how a page short of something is
 * recognised. Adding one is adding a row to tasks().
 */
final class DZE_Automation {

	public const HOOK = 'dze_auto_tick';

	/**
	 * ITS OWN ENTRY UNDER DAZONT ECOM — "je ne vois pas de menu automation dans
	 * le plugin, côté gauche de wordpress. Déjà ici ça devrait être présent."
	 *
	 * This screen is not a settings page: it is the WORK — what the site is
	 * short of, which pages are next in line, what was done and the undo behind
	 * it, with a handful of switches on the side. Buried as one tab among
	 * sixteen on Settings, the one function that runs the shop by itself was
	 * the hardest one to find. It is registered by the module itself, so it
	 * goes when the module is switched off: unlike the Logs, this page IS the
	 * module.
	 */
	public const MENU_SLUG = 'dazont-ecom-automation';

	/** Form settings, saved by the Automation tab's own Save button. */
	private const OPT = 'dze_auto_settings';

	/** What it has done: counters and the last passes. Never a form. */
	private const STATE = 'dze_auto_state';

	/** On the term: when each task last worked on it, and what it replaced. */
	public const META_SEEN = '_dze_auto_seen';
	public const META_PREV = '_dze_auto_prev';

	private const NONCE = 'dze_auto';

	/** Days before the same category may be worked on again by the same task. */
	private const COOLDOWN = 30;

	/** Days before a pass that changed nothing is tried again. */
	private const RETRY = 3;

	/** How many passes the log keeps, and how many keep an undo. */
	private const KEEP = 12;

	/** How many candidates are looked at closely before giving up on a task. */
	private const LOOK = 25;
	/**
	 * JUSQU OU CHERCHER QUAND TOUT CE QU ON VOIT EST RETENU.
	 *
	 * Independant de ce qu on demande : une passe qui veut UNE page doit
	 * chercher aussi loin qu une qui en veut dix, sinon elle s arrete avant le
	 * travail disponible. Assez large pour passer par-dessus une centaine de
	 * pages en repos, assez etroit pour ne pas relire le site entier.
	 */
	private const LOOK_DEEP = 250;

	/**
	 * How long nothing may move before the screen calls the run stopped.
	 *
	 * Longer than any single step — the screen itself takes one every second
	 * and a half, and a model answering slowly is a minute — and short enough
	 * that nobody sits in front of a queue that has died.
	 */
	private const STOPPED_AFTER = 3 * MINUTE_IN_SECONDS;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// The tick runs from cron, where nothing is an admin screen.
		add_action( self::HOOK, [ __CLASS__, 'tick' ] );
		// AND SO DOES THE SCHEDULE THE TICK IS BOOKED ON. This was below the
		// `is_admin()` line, where `wp-cron.php` never sees it — and that
		// request is precisely the one that has to reschedule the event after
		// running it. `wp_reschedule_event()` looks the recurrence up in
		// `wp_get_schedules()`, does not find `dze_ten_minutes` there, and
		// gives up; core then unschedules the event it just ran. The recurring
		// tick disappeared on every cron run and only came back on the next
		// admin page load, where `schedule()` re-armed it — so the shop's
		// automations ran when somebody was looking at the shop, which is
		// exactly what "runs by itself" is supposed to stop being.
		add_filter( 'cron_schedules', [ __CLASS__, 'cron_schedules' ] );
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 12 );
		add_action( 'admin_init', [ __CLASS__, 'maybe_redirect' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'schedule' ] );
		add_action( 'admin_init', [ __CLASS__, 'migrate' ] );
		add_action( 'wp_ajax_dze_auto_run', [ __CLASS__, 'ajax_run' ] );
		add_action( 'wp_ajax_dze_auto_undo', [ __CLASS__, 'ajax_undo' ] );
		add_action( 'wp_ajax_dze_auto_state', [ __CLASS__, 'ajax_state' ] );
		add_action( 'wp_ajax_dze_auto_catchup', [ __CLASS__, 'ajax_catchup' ] );
		add_action( 'wp_ajax_dze_auto_orphans', [ __CLASS__, 'ajax_orphans' ] );
		add_action( 'wp_ajax_dze_auto_run_state', [ __CLASS__, 'ajax_run_state' ] );
		add_action( 'wp_ajax_dze_auto_run_again', [ __CLASS__, 'ajax_run_again' ] );
		add_action( 'wp_ajax_dze_auto_run_stop', [ __CLASS__, 'ajax_run_stop' ] );
	}

	public static function page_url( string $tab = '' ): string {
		$args = [ 'page' => self::MENU_SLUG ];
		if ( '' !== $tab && 'work' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * ONE SUBJECT, SEVERAL VIEWS, WORDPRESS'S OWN TABS.
	 *
	 * "Maintenant que To review est là, ce bloc est inutile. Sinon crée un
	 * nouvel onglet dans ce module automation, 'past work' ou un truc comme
	 * ça, pour recenser tous les travaux publiés gérés par le module."
	 *
	 * What runs and what it left is one view; what has actually gone onto the
	 * shop is another, and it is a record rather than a fold at the bottom of
	 * the work. Declared here, in one place, so the strip and the body can
	 * never disagree about which views exist.
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return DZE_Screens::tabs_of( 'automation' );
	}

	/** Which view is being asked for — always one that exists. */
	public static function tab_now( array $get ): string {
		$want = isset( $get['tab'] ) ? sanitize_key( (string) $get['tab'] ) : '';
		return isset( self::tabs()[ $want ] ) ? $want : 'work';
	}

	public static function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			DZE_Screens::label( 'automation' ),
			DZE_Screens::label( 'automation' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ]
		);
		// AND IT LEAVES THE MENU.
		//
		// "Dans automations en fait il n'y aura rien, c'était peut-être
		// maladroit de faire ce module. C'est plutôt une façon de faire pour
		// automatiser différents modules." Every switch it held is now on
		// the screen of the work it acts on, so the entry stood for nothing
		// but a second place to look.
		//
		// Registered, then taken out: the page keeps answering, so every
		// link, bookmark and redirect ever printed at it still lands — and
		// it is still where the whole day's work is read side by side.
		if ( function_exists( 'remove_submenu_page' ) ) {
			remove_submenu_page( DZE_Restock::MENU_SLUG, self::MENU_SLUG );
		}
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab navigation only.
		$now = self::tab_now( (array) $_GET );
		echo '<div class="wrap dze-wrap"><h1>' . esc_html( DZE_Screens::label( 'automation' ) ) . '</h1>';
		$strip = [];
		foreach ( self::tabs() as $key => $label ) {
			$strip[ (string) $key ] = [ 'label' => $label, 'url' => self::page_url( (string) $key ) ];
		}
		echo wp_kses_post( DZE_Screens::strip( $strip, $now, 'margin:12px 0 18px;' ) );
		if ( 'past' === $now ) {
			self::render_past();
		} else {
			self::render_settings();
		}
		// ONE SCRIPT FOR THE SCREEN, not one per body: the undo lives on the
		// record and the run buttons on the tasks, and a handler written twice
		// is two handlers to keep in step.
		self::render_assets();
		echo '</div>';
	}

	/**
	 * An address that used to land on the Settings page still lands.
	 *
	 * A bookmark, a link in a message: none of them may end on a tab that no
	 * longer exists. The decision is split from the redirect so it can be
	 * exercised — the same rule `DZE_Health::moved()` is held to.
	 */
	public static function moved( array $get ): string {
		$page = isset( $get['page'] ) ? (string) $get['page'] : '';
		$tab  = isset( $get['tab'] ) ? (string) $get['tab'] : '';
		$ai   = class_exists( 'DZE_Marketing_Ai' ) ? DZE_Marketing_Ai::MENU_SLUG : 'dazont-ecom-ai';
		return ( $page === $ai && 'automation' === $tab ) ? self::page_url() : '';
	}

	public static function maybe_redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$to = self::moved( (array) $_GET );
		if ( '' !== $to ) {
			wp_safe_redirect( $to );
			exit;
		}
	}

	/** Switched off in Settings → Modules: the hourly look goes with it. */
	public static function disable(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * A TEN-MINUTE LOOK, for a task that has been told to take everything.
	 *
	 * The hook was scheduled HOURLY, so "comes back every ten minutes" was a
	 * sentence about nothing: a task can only act when the hook fires, and the
	 * hook fired once an hour. A shop catching up on two hundred pages would
	 * have waited eight days for work it had asked to be done unattended.
	 *
	 * WordPress has no ten-minute schedule of its own, so this adds one — and
	 * only ever uses it while a task actually wants it.
	 */
	public static function cron_schedules( $schedules ) {
		$schedules = is_array( $schedules ) ? $schedules : [];
		$schedules['dze_ten_minutes'] = [
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every ten minutes (Dazont Ecom)', 'dazont-ecom' ),
		];
		return $schedules;
	}

	/** Does any task want the short look? */
	public static function wants_short_look(): bool {
		foreach ( array_keys( self::tasks() ) as $id ) {
			$conf = self::conf( (string) $id );
			if ( ! empty( $conf['on'] ) && self::takes_all( (string) $id ) ) {
				return true;
			}
		}
		return false;
	}

	public function schedule(): void {
		// THE RHYTHM FOLLOWS THE SETTING, and changes with it: a shop that
		// puts every task back on a daily ration should not keep a look that
		// runs six times an hour for nothing.
		$want = self::wants_short_look() ? 'dze_ten_minutes' : 'hourly';
		$next = wp_next_scheduled( self::HOOK );
		if ( $next ) {
			$ev = wp_get_scheduled_event( self::HOOK );
			$has = $ev && isset( $ev->schedule ) ? (string) $ev->schedule : '';
			if ( $has === $want ) {
				return;
			}
			wp_clear_scheduled_hook( self::HOOK );
		}
		wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, $want, self::HOOK );
	}

	/**
	 * 4.87 shipped this as a single-purpose "link mesh" module. Its two
	 * settings become the internal-links task, and its rows leave the database
	 * rather than sitting there undeclared.
	 */
	public static function migrate(): void {
		self::hold_for_review();
		$old = get_option( 'dze_mesh_settings', null );
		if ( null === $old ) {
			return;
		}
		$old = is_array( $old ) ? $old : [];
		$new = self::settings();
		if ( empty( $new['tasks']['mesh_links'] ) ) {
			$new['tasks']['mesh_links'] = [
				'on'      => empty( $old['on'] ) ? 0 : 1,
				'per_day' => max( 1, min( 20, (int) ( $old['per_day'] ?? 3 ) ) ),
				'apply'   => 0,
			];
			update_option( self::OPT, $new, false );
		}
		delete_option( 'dze_mesh_settings' );
		delete_option( 'dze_mesh_state' );
		delete_transient( 'dze_mesh_survey' );
		// The hourly tick this module used to run is retired in
		// `DZE_Cleanup::retire_hooks()`, on every admin load and whatever the
		// state of this option: it was cleared here, below an early return
		// that fires on any shop whose option is already gone, so the event
		// outlived the code for months.
		foreach ( [ '_dze_mesh_at', '_dze_mesh_out', '_dze_mesh_prev' ] as $key ) {
			delete_metadata( 'term', 0, $key, '', true );
		}
	}

	/**
	 * Once: the link passes stop saving straight to the shop.
	 *
	 * Three tasks shipped with "save without review" ticked, which is a
	 * function delegated whole — and no function here is. A shop that had them
	 * ticked never chose it: it was the default. Turned back to review ONCE,
	 * recorded so it never overrides the shop again, and the box is still
	 * there to tick when the shop trusts the pass.
	 */
	private static function hold_for_review(): void {
		if ( '1' === (string) get_option( 'dze_auto_review_default', '' ) ) {
			return;
		}
		update_option( 'dze_auto_review_default', '1', false );
		$s = self::settings();
		$touched = false;
		foreach ( [ 'mesh_links', 'cat_links', 'post_links' ] as $id ) {
			if ( isset( $s['tasks'][ $id ] ) && ! empty( $s['tasks'][ $id ]['apply'] ) ) {
				$s['tasks'][ $id ]['apply'] = 0;
				$touched = true;
			}
		}
		if ( $touched ) {
			update_option( self::OPT, $s, false );
		}
	}

	// =========================================================================
	// The tasks
	// =========================================================================

	/**
	 * What this module knows how to have done, and by whom.
	 *
	 * 'kind' is a writing-queue job: the work itself belongs to the module
	 * that owns the screen, and is reached through the same queue a click on
	 * that screen would use.
	 *
	 * @return array<string,array>
	 */
	/**
	 * How many waiting jobs a task shows in place before pointing at the list.
	 *
	 * "Ici ce serait bien de pouvoir review la task directement sans partir."
	 * A block under a fold is a to-do list, not a list table: past a handful
	 * it stops being readable and the full list — with its ticks, its bulk bar
	 * and its columns — is the screen for that. So the block shows the oldest
	 * few and says how many are left, rather than growing without end.
	 */
	private const TODO_MAX = 10;

	/**
	 * How many pages one press of the catch-up may put in the queue at once.
	 *
	 * The queue writes ONE page at a time and each one is a call to the model,
	 * a few seconds of it — so two hundred is already the best part of an hour
	 * of background work, and a thousand rows sitting in the queue is a table
	 * nobody can read and a day of writing nobody asked for in one go. The
	 * press says what is left and can be pressed again; a shop catching up
	 * from nothing does it in a handful of presses rather than one that never
	 * finishes.
	 */
	public const CATCHUP_MAX = 200;

	/**
	 * Did anything on this page draw the three review controls?
	 *
	 * The popup they open, its script and its words are printed only then: a
	 * screen with nothing waiting has no business loading an editor.
	 */
	private static bool $needs_review = false;

	public static function tasks(): array {
		return [
			// ONE TASK FOR ONE PIECE OF WORK. Internal linking was two tasks —
			// one for categories, one for articles — each mending half a mesh
			// from its own half-blind reading, and the shop had to switch on
			// both and know why. It is the link GRAPH that says which page is
			// short and who should point at it, whatever kind of page either
			// of them is: "une tâche automatisable qui va maintenir le site à
			// jour. On l'active et il fait le travail."
			'mesh_links' => [
				'label'   => __( 'Internal linking', 'dazont-ecom' ),
				// A LINE SOMEBODY CAN READ, and the mechanism one press away.
				// "J'aurais plutôt écrit un texte simple et compréhensif :
				// déléguer à Dazont Ecom le maillage interne du site web. Avec
				// une très courte description derrière de ce qu'il fait."
				'what'    => __( 'Hand the site\'s internal linking to Dazont Ecom. It links the pages nothing points at, then the pages short of their own links.', 'dazont-ecom' ),
				'more'    => __( 'Dazont Ecom reads the whole site once and writes down every internal link it finds — in categories, articles and pages alike, including the text a page builder keeps in its own data rather than in the post. From that map it works in two phases, in this order. FIRST, the holes: it takes the pages the site points at least (fewer than three links in) and writes the link into the pages closest to them in subject, so the page being edited is chosen for being an orphan\'s best neighbour. THEN, once nothing is orphaned, the work you would have done by hand: the pages carrying fewer links than their own length calls for — one per fifty words — each filling them from its own pool of related pages, which on a shop puts the product categories first. Products are left out of all of it: a product page already says what it belongs to. A page it has worked on is left alone for a month, so what runs each day is maintenance: the pages you have just added, and nothing else. "Link the whole site" does that SECOND phase for every page at once, a few hundred per press, when you are catching up from nothing — and only that phase: mending a page nothing points at needs the graph to choose which neighbour should point at it, which is work only the daily pass does. The figure beside the task name is how many such pages the last reading found; pressing it opens the list. What is never in that list, and never linked: a draft, a page with nothing written on it, the cart, the checkout and the account pages — and anything you mark "Do not link" on a row of that list, which is where the refund policy and the legal pages belong. Marking one means nothing is written into it and nothing is asked to point at it; the links it already carries still count for the pages they point at. Nothing reaches the shop until you accept it, unless you tick "Save without review".', 'dazont-ecom' ),
				'module'  => 'mesh',
				'scope'   => 'mesh',
				// The job kinds this task leaves waiting, so its own block can
				// say how many are there rather than sending somebody to go
				// and count on another screen.
				'jobs'    => [ 'cat_links', 'post_links' ],
				// TOUT, EN ARRIERE-PLAN. « Ce serait bien de ne pas avoir non plus
				// x par jour mais bien un module qui prenne tout en charge en
				// background. Le x par jour c est bien pour de la publication de
				// contenu. » Le maillage n est pas de la publication : c est de
				// l entretien, et un entretien qui s arrete a trois pages par
				// jour laisse le site en retard pour toujours.
				'pace'    => 'all',
				'per_day' => 3,
				'apply'   => 0,
			],
			'cat_desc'  => [
				'label'   => __( 'Category descriptions', 'dazont-ecom' ),
				'what'    => __( 'Hand category descriptions to Dazont Ecom. It writes one a day, emptiest category first.', 'dazont-ecom' ),
				'more'    => __( 'It looks at every product category and ranks them by how little description they hold — no text at all first, then the thinnest. The length it writes to is the category\'s own: a branch carrying hundreds of products is given more words than a shelf with four, and the links inside it follow the shop\'s rule of one per fifty words. A category already in the writing queue is skipped, and one it has worked on is left alone for a month. Tick "Only with a SEMrush file" to restrict it to the categories whose keyword file you have uploaded. Nothing reaches the shop until you accept it, unless you tick "Save without review".', 'dazont-ecom' ),
				'module'  => 'category_content',
				'scope'   => 'category',
				'kind'    => 'cat_desc',
				'jobs'    => [ 'cat_desc' ],
				'per_day' => 1,
				'apply'   => 0,
				'kw'      => true, // may be restricted to categories with their SEMrush file.
			],
			// THE ONE PASS THAT DOES NOT WRITE ONE WORD OF ITS OWN. Every
			// other task here asks a model to WRITE something; this one asks
			// it to say the same thing in another language, and what is
			// "owed" is not our judgement at all — WPML says it, object by
			// object, and this reads WPML's own answer.
			'translate' => [
				'label'   => __( 'Translations', 'dazont-ecom' ),
				'what'    => __( 'Hand the shop\'s translations to Dazont Ecom. It translates what WPML says is owed, a few objects a day.', 'dazont-ecom' ),
				'more'    => __( 'WPML already knows what this shop owes a translation of: an object with no translation in one of your languages, or one WPML has marked as needing an update. This pass takes those, oldest work first, and translates ONE object a day for each you allow — every language it is short of, in one go, because a product translated into French and not into German is a job half done. What is sent is only what really moved: the module keeps its own register of the words each translation was made from, so a product flagged because its category was renamed sends nothing at all and is simply marked up to date, for nothing. Which kinds of content take part is the shop\'s own list under Settings → Translation, and what is inside each object — which fields are translated, which WPML copies — is WPML\'s answer and never ours. An object it has worked on is left alone for a month, and one already holding a translation waiting for your yes or no is never sent twice. Nothing reaches the shop until you accept it: unlike the other tasks here, what it produces does not wait with the rest — a translation is one object times its languages times its fields, and it is decided on Dazont Ecom → WPML Translations, the screen built for it. Tick "Save without review" and each translation is written the moment it comes back.', 'dazont-ecom' ),
				'module'  => 'translate',
				'scope'   => 'translate',
				// No queue row: what waits lives on the source object, which
				// is why this task's own block names the screen that holds it
				// rather than listing rows it could never settle here.
				'jobs'    => [],
				// TOUT CE QUI EST CRITIQUE, SANS QUOTA ; le reste au goutte-a-
				// goutte. « Pour tout ce qui est critique comme les attributs,
				// categories etc oui, et la possibilite de traduire un peu chaque
				// jour pour les posts comme produits et articles de blog, c est
				// bien pour le SEO. » Les TERMES — attributs, categories — passent
				// sans compteur : une valeur d attribut non traduite casse une
				// page entiere. Les POSTS gardent leur per_day.
				'pace'    => 'all',
				'per_day' => 1,
				'apply'   => 0,
			],
			'events'    => [
				'label'   => __( 'Marketing calendar', 'dazont-ecom' ),
				'what'    => __( 'Hand the promotion calendar to Dazont Ecom. Once a month it proposes the moments worth a campaign.', 'dazont-ecom' ),
				'more'    => __( 'Once a month it looks at the quarter ahead and proposes the commercial moments worth a promotion on this shop — the ones your catalogue actually sells into, not a list of public holidays. Each suggestion waits on the marketing calendar for a yes or a no, and accepting one creates the event SWITCHED OFF, so nothing ever goes live on its own: you open it, set the discount and the dates, and publish it yourself. It stops asking once eight suggestions are waiting unanswered, and it writes nothing at all to the shop.', 'dazont-ecom' ),
				'module'  => 'marketing_ai',
				'scope'   => 'shop',
				'cadence' => 'month',
				'apply'   => 0,
			],
		];
	}

	/**
	 * WHAT THIS TASK LEFT FOR YOU, and the way to it.
	 *
	 * "Peut-être afficher un msg sur chaque section automatisation qui nomme
	 * combien de jobs sont en attente de review pour chacun d'eux ?" A pass
	 * that runs on its own and says nothing about what it produced is a pass
	 * whose work is found by accident — and the list it lands on is two clicks
	 * away under another menu. The figure is on the block that started it, and
	 * it is a LINK: a message that names a screen is a way to that screen.
	 *
	 * @return array{n:int,url:string}
	 */
	public static function waiting_for( string $id ): array {
		$task = self::task( $id );
		if ( ! $task ) {
			return [ 'n' => 0, 'url' => '' ];
		}
		// The shop-wide task writes nothing to the queue: what it leaves is a
		// pile of suggestions on the screen that owns them.
		if ( 'shop' === (string) ( $task['scope'] ?? '' ) ) {
			if ( ! class_exists( 'DZE_Marketing_Ai' ) ) {
				return [ 'n' => 0, 'url' => '' ];
			}
			return [
				'n'   => (int) DZE_Marketing_Ai::pending_count(),
				'url' => add_query_arg(
					[ 'page' => DZE_Marketing_Ai::MENU_SLUG, 'tab' => 'events' ],
					admin_url( 'admin.php' )
				),
			];
		}
		// A translation waits on the SOURCE OBJECT, on the screen built to
		// decide it — one object times its languages times its fields is not
		// a queue row, and the screen that owns that question owns the figure.
		if ( 'translate' === (string) ( $task['scope'] ?? '' ) ) {
			if ( ! class_exists( 'DZE_Translate' ) || ! DZE_Modules::enabled( 'translate' ) ) {
				return [ 'n' => 0, 'url' => '' ];
			}
			return [
				'n'   => (int) DZE_Translate::review_count(),
				'url' => class_exists( 'DZE_Screens' ) ? DZE_Screens::url( 'translations', 'review' ) : '',
			];
		}
		if ( ! class_exists( 'DZE_Queue' ) || ! DZE_Modules::enabled( 'queue' ) ) {
			return [ 'n' => 0, 'url' => '' ];
		}
		// CHAQUE TÂCHE RENVOIE SUR L'ÉCRAN QUI RELIT SON PROPRE TRAVAIL.
		// L'écran central a disparu — « on simplifie plutôt que de
		// complexifier » — et ce sont les genres de la tâche qui disent où.
		return [
			'n'   => DZE_Queue::review_count_for( (array) ( $task['jobs'] ?? [] ) ),
			'url' => DZE_Queue::review_url( (array) ( $task['jobs'] ?? [] ) ),
		];
	}

	/**
	 * WHICH pieces of work this task left waiting — not how many.
	 *
	 * Read from the queue by the task's own job kinds, so the linking task
	 * shows the categories AND the articles it wrote: counting one of them
	 * counts half its own work, and showing one of them shows half of it.
	 *
	 * The shop-wide task answers with nothing: it writes no queue row at all,
	 * and what it leaves is a pile of suggestions on the screen that owns
	 * them — the link to that screen is the whole of what can be offered here.
	 *
	 * @return array<int,array{id:int,kind:string,oid:int,label:string,job:string,from:string,when:string}>
	 */
	public static function todo( string $id, int $limit = self::TODO_MAX ): array {
		$task = self::task( $id );
		if ( ! $task || 'shop' === (string) ( $task['scope'] ?? '' ) ) {
			return [];
		}
		if ( ! class_exists( 'DZE_Queue' ) || ! DZE_Modules::enabled( 'queue' ) ) {
			return [];
		}
		return DZE_Queue::review_rows_for( (array) ( $task['jobs'] ?? [] ), $limit );
	}

	public static function task( string $id ): array {
		return self::tasks()[ $id ] ?? [];
	}

	/** Is this task switched on, and can it run at all? */
	public static function task_on( string $id ): bool {
		return ! empty( self::conf( $id )['on'] ) && self::task_ready( $id );
	}

	/** The modules a task leans on, all present and enabled. */
	public static function task_ready( string $id ): bool {
		$t = self::task( $id );
		if ( ! $t ) {
			return false;
		}
		if ( class_exists( 'DZE_Modules' ) && ! DZE_Modules::enabled( (string) $t['module'] ) ) {
			return false;
		}
		// A task that hands its work to the queue needs the queue; the only
		// one that does not is the shop-wide task, which makes its own call.
		//
		// It used to be asked for only when the row NAMES its job kind — and
		// the linking task cannot name one, since its kind depends on the page
		// it lands on. So with the writing queue switched off that task read as
		// ready, the screen offered it, and pressing Run answered "that
		// category is already waiting in the queue": a sentence about a queue
		// that is not there.
		// A TASK THAT KEEPS ITS OWN WAITING LIST DOES NOT NEED THE QUEUE, and
		// the test is the task's own declaration — the job kinds it leaves
		// behind — never a scope named here. Translations wait on the source
		// object, the calendar's suggestions wait on the calendar; neither has
		// a queue row, and demanding the writing queue for them would switch
		// off a function that has nothing to do with it.
		$queues = ! empty( $t['jobs'] ) || 'mesh' === ( $t['scope'] ?? '' );
		if ( $queues ) {
			if ( ! class_exists( 'DZE_Queue' ) ) {
				return false;
			}
			if ( class_exists( 'DZE_Modules' ) && ! DZE_Modules::enabled( 'queue' ) ) {
				return false;
			}
		}
		if ( 'translate' === ( $t['scope'] ?? '' ) ) {
			// Its own module is checked above; what it also needs is WPML,
			// because everything it does is read out of WPML's own tables.
			return class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active();
		}
		if ( $queues && ! class_exists( 'DZE_Category_Content' ) ) {
			return false;
		}
		if ( 'shop' === ( $t['scope'] ?? '' ) && ! class_exists( 'DZE_Marketing_Ai' ) ) {
			return false;
		}
		return true;
	}

	/** The saved settings of one task, filled in from its defaults. */
	public static function conf( string $id ): array {
		$t = self::task( $id );
		$c = (array) ( self::settings()['tasks'][ $id ] ?? [] );
		$m = 'month' === ( $t['cadence'] ?? 'day' );
		return [
			'cadence' => $m ? 'month' : 'day',
			'on'      => ! empty( $c['on'] ),
			'per_day' => $m ? 1 : max( 1, min( 20, (int) ( $c['per_day'] ?? $t['per_day'] ?? 1 ) ) ),
			'apply'   => array_key_exists( 'apply', $c ) ? ! empty( $c['apply'] ) : ! empty( $t['apply'] ),
			'kw_only' => array_key_exists( 'kw_only', $c ) ? ! empty( $c['kw_only'] ) : ! empty( $t['kw'] ),
			// LA CADENCE : ce que la tache fait par defaut, et ce que la boutique
			// en dit. Seules deux valeurs ont un sens — tout prendre en charge,
			// ou une ration quotidienne — et une tache mensuelle n en a aucune.
			'pace'    => $m ? 'month' : ( in_array( (string) ( $c['pace'] ?? '' ), [ 'all', 'daily' ], true ) ? (string) $c['pace'] : (string) ( $t['pace'] ?? 'daily' ) ),
			'scope'   => (string) ( $t['scope'] ?? 'category' ),
		];
	}

	// =========================================================================
	// Settings and state
	// =========================================================================

	public function register_settings(): void {
		register_setting( 'dze_auto_options', self::OPT, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
			'default'           => [],
			'autoload'          => false,
		] );
	}

	public static function sanitize( $in ): array {
		// WordPress hands a sanitizer NULL when the submitted page did not
		// carry this option at all. That is not "the shop emptied it": it is
		// "another form was saved", and answering with defaults is how a
		// setting disappears after an update nobody connected to it.
		if ( null === $in ) {
			return self::settings();
		}

		$in  = is_array( $in ) ? $in : [];
		$out = self::settings();
		if ( empty( $in['form'] ) ) {
			return $out; // not our form: leave what the shop holds.
		}
		$posted = (array) ( $in['tasks'] ?? [] );
		foreach ( self::tasks() as $id => $t ) {
			$row = (array) ( $posted[ $id ] ?? [] );
			$out['tasks'][ $id ] = [
				'on'      => empty( $row['on'] ) ? 0 : 1,
				'per_day' => max( 1, min( 20, (int) ( $row['per_day'] ?? $t['per_day'] ?? 1 ) ) ),
				'apply'   => empty( $row['apply'] ) ? 0 : 1,
				'kw_only' => empty( $row['kw_only'] ) ? 0 : 1,
			];
		}
		return $out;
	}

	public static function settings(): array {
		$s = get_option( self::OPT, [] );
		$s = is_array( $s ) ? $s : [];
		$s['tasks'] = (array) ( $s['tasks'] ?? [] );
		return $s;
	}

	private static function state(): array {
		$s = get_option( self::STATE, [] );
		return is_array( $s ) ? $s : [];
	}

	private static function save_state( array $s ): void {
		update_option( self::STATE, $s, false );
	}

	/**
	 * DOES THIS TASK TAKE EVERYTHING, OR A FEW A DAY?
	 *
	 * "Ne pas avoir non plus x par jour mais bien un module qui prenne tout en
	 * charge en background. Le x par jour c'est bien pour de la publication de
	 * contenu." Two paces, and the difference is what the work IS: writing a
	 * category description is publishing, and a shop wants that spread out.
	 * Linking pages and translating an attribute are maintenance, and
	 * maintenance held to three a day is a site permanently behind.
	 *
	 * A shop can still hold a task to a daily ration: the setting is per task.
	 */
	public static function takes_all( string $id ): bool {
		$conf = self::conf( $id );
		return 'all' === (string) ( $conf['pace'] ?? 'daily' );
	}

	/** The quiet time between two passes of one task. */
	public static function gap( string $id ): int {
		$conf = self::conf( $id );
		if ( 'month' === $conf['cadence'] ) {
			return 30 * DAY_IN_SECONDS;
		}
		// TAKING EVERYTHING MEANS COMING BACK SOON, not all at once: one pass
		// is one object and one model call, and the queue does the rest. Ten
		// minutes keeps it moving without ever being a burst.
		if ( self::takes_all( $id ) ) {
			return 10 * MINUTE_IN_SECONDS;
		}
		return (int) max( HOUR_IN_SECONDS, floor( DAY_IN_SECONDS / max( 1, $conf['per_day'] ) ) );
	}

	/** When this task last did something. */
	public static function last_run( string $id ): int {
		return (int) ( ( (array) ( self::state()['last'] ?? [] ) )[ $id ] ?? 0 );
	}

	/** Passes this task made today — the cap is a day's worth, not a run's. */
	public static function done_today( string $id ): int {
		$s = self::state();
		if ( (string) ( $s['day'] ?? '' ) !== current_time( 'Y-m-d' ) ) {
			return 0;
		}
		return (int) ( ( (array) ( $s['count'] ?? [] ) )[ $id ] ?? 0 );
	}

	/**
	 * May this task run right now?
	 *
	 * @return string '' when it may, otherwise why not.
	 */
	public static function why_not( string $id, bool $forced = false ): string {
		$conf = self::conf( $id );
		if ( ! self::task_ready( $id ) ) {
			return 'modules';
		}
		if ( ! $conf['on'] && ! $forced ) {
			return 'off';
		}
		// A copy of the shop does not work on its own. Pressed by hand it
		// does: a test site exists to be tried, and everything these tasks
		// write stays on the site — what leaves it is refused elsewhere.
		if ( class_exists( 'DZE_Site' ) && ! DZE_Site::autopilot_ok( $forced ) ) {
			return 'copy';
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			return 'budget';
		}
		if ( $forced ) {
			return '';
		}
		// LE PLAFOND DU JOUR NE TIENT PAS UNE TACHE QUI PREND TOUT EN CHARGE.
		// Ce qui l arrete alors : plus rien a faire, ou le plafond de depense.
		if ( 'day' === $conf['cadence'] && ! self::takes_all( $id ) && self::done_today( $id ) >= $conf['per_day'] ) {
			return 'cap';
		}
		// Spread over the period rather than run off at the start of it: three
		// a day is one every eight hours, once a month is once a month.
		if ( time() - self::last_run( $id ) < self::gap( $id ) ) {
			return 'early';
		}
		return '';
	}

	// =========================================================================
	// The census
	// =========================================================================

	/**
	 * Every category, cheaply: its length, what it links to, what links to it.
	 *
	 * One term query and nothing per category: this is read to RANK, and a
	 * ranking that costs a query per category is a ranking nobody can afford
	 * to run. The real figures — the targets a category is judged against —
	 * are asked of the handful at the top of that ranking only.
	 *
	 * @return array{time:int,rows:array<int,array{name:string,words:int,out:int,in:int}>}
	 */
	public static function survey( bool $force = false ): array {
		$cached = $force ? false : get_transient( 'dze_auto_survey' );
		if ( is_array( $cached ) && isset( $cached['rows'] ) ) {
			return $cached;
		}
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'exclude'    => [ (int) get_option( 'default_product_cat' ) ],
		] );
		$rows  = [];
		$byurl = [];
		if ( ! is_wp_error( $terms ) && class_exists( 'DZE_Category_Content' ) ) {
			$lang = DZE_Category_Content::default_lang();
			foreach ( $terms as $t ) {
				// Translations belong to WPML: the shop is written, and linked,
				// in its main language.
				if ( '' !== $lang && DZE_Category_Content::lang_code( (int) $t->term_id ) !== $lang ) {
					continue;
				}
				$url = get_term_link( $t );
				if ( ! is_wp_error( $url ) ) {
					$byurl[ untrailingslashit( (string) $url ) ] = (int) $t->term_id;
				}
				$rows[ (int) $t->term_id ] = [
					'name'  => (string) $t->name,
					'words' => str_word_count( wp_strip_all_tags( (string) $t->description ) ),
					'out'   => (int) preg_match_all( '/<a\s[^>]*href=/i', (string) $t->description ),
					'in'    => 0,
					'desc'  => (string) $t->description,
				];
			}
			// WHO POINTS AT A CATEGORY IS A QUESTION THE WHOLE SITE ANSWERS.
			// Counted here, it was other CATEGORY DESCRIPTIONS and nothing
			// else: an article sending its readers to an aisle counted for
			// zero, so the pass that mends orphans worked from a reading that
			// could not see half the mesh. The graph knows; ask it, and fall
			// back to the old count only where it is switched off.
			$graph = self::inbound_from_mesh( array_keys( $rows ) );
			foreach ( $rows as $tid => $row ) {
				if ( null !== $graph ) {
					$rows[ $tid ]['in'] = (int) ( $graph[ (int) $tid ] ?? 0 );
					continue;
				}
				foreach ( DZE_Category_Content::linked_urls( $row['desc'] ) as $u ) {
					$target = $byurl[ untrailingslashit( $u ) ] ?? 0;
					if ( $target && $target !== $tid && isset( $rows[ $target ] ) ) {
						$rows[ $target ]['in']++;
					}
				}
			}
		}
		foreach ( $rows as $tid => $row ) {
			unset( $rows[ $tid ]['desc'] ); // not worth carrying in a transient.
		}
		$out = [ 'time' => time(), 'rows' => $rows ];
		set_transient( 'dze_auto_survey', $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * How many pages of the WHOLE site point at each of these categories.
	 *
	 * @return array<int,int>|null null when the link graph is not there to be
	 *                            asked — never an array of zeroes, which would
	 *                            read as "nobody points at anything".
	 */
	private static function inbound_from_mesh( array $tids ): ?array {
		if ( ! class_exists( 'DZE_Mesh' ) || ( class_exists( 'DZE_Modules' ) && ! DZE_Modules::enabled( 'mesh' ) ) ) {
			return null;
		}
		$per = (array) ( DZE_Mesh::census()['per'] ?? [] );
		if ( ! $per ) {
			return null; // the site has not been read yet.
		}
		$out = [];
		foreach ( $tids as $tid ) {
			$out[ (int) $tid ] = (int) ( $per[ 'product_cat:' . (int) $tid ]['in'] ?? 0 );
		}
		return $out;
	}

	/**
	 * What a task may work on, worst first.
	 *
	 * Cheap ranking, then the real figures on the head of the list only. The
	 * screen shows the same list the tick takes from, so what the owner reads
	 * is what will happen.
	 *
	 * @return array<int,array{tid:int,name:string,why:string}>
	 */
	public static function shortlist( string $id, int $n = 5, bool $judge = true ): array {
		$task = self::task( $id );
		if ( ! $task || ! self::task_ready( $id ) ) {
			// Its module is off: the queue table may not even exist, and there
			// is nothing this task could be about to do anyway.
			return [];
		}
		$scope = (string) ( $task['scope'] ?? 'category' );
		if ( 'shop' === $scope ) {
			return self::shop_shortlist( $id );
		}
		if ( 'mesh' === $scope ) {
			return self::mesh_shortlist( $id, $n, '', $judge );
		}
		if ( 'translate' === $scope ) {
			return self::translate_shortlist( $id, $n );
		}
		return 'post' === $scope ? self::post_shortlist( $id, $n ) : self::cat_shortlist( $id, $n );
	}

	/**
	 * The pages the link graph says should carry a new link, and to what.
	 *
	 * The row keeps what the mesh decided — which targets, by their address —
	 * so the pass writes THOSE links and not whatever the page would have
	 * chosen on its own, which is a different question with a different
	 * answer. The plan is asked once here and carried, never rebuilt from an
	 * integer halfway through.
	 */
	private static function mesh_shortlist( string $id, int $n, string $only = '', bool $judge = true ): array {
		if ( ! class_exists( 'DZE_Mesh' ) ) {
			return [];
		}
		$cool = time() - self::COOLDOWN * DAY_IN_SECONDS;
		$out  = [];
		$seen = [];
		// WHY A CANDIDATE WAS PASSED OVER, counted as it happens. "Nothing is
		// short of anything right now > alors que plein de pages sont encore
		// sans liens": the answer was true of the REGISTER and false of the
		// shop, because a page held back is not a page that has what it needs.
		// Three states wore one word; each is counted here and named in the
		// sentence the press comes back with.
		self::held_reset();
		// A GRAPH THAT HAS NOT BEEN READ YET HAS NO WORK TO HAND OUT. Before
		// the first reading every page read as an orphan and a dead end, so
		// this listed the whole site as next in line and a press queued work
		// on it. The lists answer nothing now; this says WHICH nothing.
		if ( null === DZE_Mesh::orphan_count() ) {
			self::$held['unread'] = 1;
			return [];
		}
		$take = static function ( array $row ) use ( &$out, &$seen, $id, $cool ): bool {
			$type = 'product_cat' === $row['kind'] ? 'term' : 'post';
			$key  = $row['kind'] . ':' . (int) $row['tid'];
			if ( isset( $seen[ $key ] ) ) {
				return false;
			}
			// A page already in the writing queue is not work: it would come
			// back a second text for the same page. Asked BEFORE the wait,
			// because a page in the queue is where the shop should be sent to
			// look, whatever the register says about it.
			$busy = class_exists( 'DZE_Queue' ) && (
				'product_cat' === $row['kind']
					? DZE_Queue::pending_for( (int) $row['tid'] )
					: DZE_Queue::pending_for( (int) $row['tid'], 'post_' )
			);
			if ( $busy ) {
				self::$held['queued']++;
				$seen[ $key ] = true;
				return false;
			}
			if ( self::cooling( (int) $row['tid'], $id, $type, 0, 0, $cool ) && ! self::promise_broken( (int) $row['tid'], $id, $type, $row['kind'] ) ) {
				self::$held['recent']++;
				$seen[ $key ] = true;
				return false;
			}
			$seen[ $key ] = true;
			$out[]        = $row;
			return true;
		};

		// PHASE ONE — the holes in the mesh: the pages the site points at
		// least, mended from the pages closest to them. Always first: a page
		// nobody can reach is worth more than a page that reads a little thin.
		// LE VIVIER DOIT DEPASSER CE QUI EST DEJA RETENU.
		//
		// « Rien n a tourne depuis un long moment. » La passe automatique demande
		// UNE page, le vivier valait trois fois la demande — donc trois pages —
		// et les trois premieres attendaient la relecture de la boutique. Tout
		// etait ecarte, rien n etait propose, et cela se reproduisait toutes les
		// dix minutes sans laisser la moindre trace. Le travail reel, lui,
		// attendait juste derriere : demander deux pages en rendait deux.
		//
		// Le vivier s elargit donc tant que tout ce qu il contient est retenu,
		// et s arrete des que la liste ne rend plus rien de neuf ou que le
		// plafond est atteint : chercher sans fin coute autant que ne pas
		// chercher du tout.
		// LE PLAFOND NE DEPEND PLUS DE CE QU ON DEMANDE.
		//
		// Il valait `max( 60, $n * 30 )` : demander UNE page cherchait donc
		// moins loin que d en demander trois — l inverse du bon sens, et la
		// raison pour laquelle la passe automatique, qui demande toujours une
		// page, ne trouvait rien pendant que shortlist(3) rendait trois lignes.
		// Mesure sur la boutique : 92 pages en repos, donc les exploitables
		// commencent au-dela de la soixantieme.
		//
		// DEUX TENTATIVES ET PAS UNE CROISSANCE : chaque appel a plan() rejuge
		// les cibles qu il n a pas encore en cache, donc multiplier les appels
		// multiplie la depense. On tente petit — ce qui suffit presque toujours
		// — puis une seule fois large.
		foreach ( [ max( 1, $n ) * 3, self::LOOK_DEEP ] as $pool ) {
			if ( 'out' === $only ) {
				break;
			}
			$rows = DZE_Mesh::plan( $pool, $judge );
			foreach ( $rows as $row ) {
				$take( [
					'tid'   => (int) $row['id'],
					'name'  => (string) $row['name'],
					'why'   => (string) $row['why'],
					'kind'  => (string) $row['kind'],
					'urls'  => (array) $row['urls'],
					'phase' => 'in',
				] );
				if ( count( $out ) >= $n ) {
					return $out;
				}
			}
			// La liste a rendu moins qu on lui demandait : elle n a plus rien a
			// offrir, et une seconde tentative plus large ne rendrait pas plus.
			if ( count( $rows ) < $pool ) {
				break;
			}
		}

		// PHASE TWO — the work that used to be done by hand: the pages under
		// their own outgoing quota. No addresses travel with these: the page's
		// OWN pool decides, and on a shop that pool ranks product categories
		// above articles, which is the direction that earns the money.
		// MEME PIEGE, MEME REMEDE : la deuxieme liste se demandait elle aussi
		// trois fois la demande, et se retrouvait vide des que ses premieres
		// lignes etaient deja au travail.
		foreach ( [ max( 1, $n ) * 3, self::LOOK_DEEP ] as $pool ) {
			if ( 'in' === $only ) {
				break;
			}
			$rows = DZE_Mesh::thin( $pool );
			foreach ( $rows as $row ) {
				$take( [
					'tid'   => (int) $row['id'],
					'name'  => (string) $row['title'],
					'why'   => self::thin_said( (int) $row['short'] ),
					'kind'  => (string) $row['kind'],
					'urls'  => [],
					'phase' => 'out',
				] );
				if ( count( $out ) >= $n ) {
					return $out;
				}
			}
			if ( count( $rows ) < $pool ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * THE OBJECTS THIS SHOP STILL OWES A TRANSLATION OF.
	 *
	 * The list is WPML's own answer, read by the very function the
	 * Translations screen pages with — one reader, two callers, so the pass
	 * can never offer an object the screen does not list.
	 *
	 * Two things take an object out: one that is already holding a translation
	 * waiting for a yes or no (sending it again would make a second answer for
	 * the same words, and the second would quietly replace the first), and one
	 * worked on within the cooldown. Both are counted, because "nothing to do"
	 * has to say WHICH nothing.
	 *
	 * @return array<int,array{tid:int,name:string,why:string,kind:string,ref:string,langs:string[]}>
	 */
	private static function translate_shortlist( string $id, int $n ): array {
		if ( ! class_exists( 'DZE_Translate' ) || ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			return [];
		}
		self::held_reset();
		$src = (string) DZE_Wpml::default_language();
		$out = [];
		// LES TERMES D ABORD, ET SANS COMPTEUR.
		//
		// « Pour tout ce qui est critique comme les attributs, categories etc
		// oui, et la possibilite de traduire un peu chaque jour pour les posts
		// comme produits et articles de blog, c'est bien pour le SEO. »
		//
		// Un attribut non traduit casse une page entiere : le client voit
		// « Black » au milieu d un texte francais, ou pire, la variation ne se
		// choisit pas. Un produit non traduit est une page de moins, ce qui
		// est un manque, pas une casse. Les deux ne meritent donc pas la meme
		// hate — et c est exactement ce que la boutique a demande.
		$scopes = DZE_Translate::picked_scope();
		if ( self::takes_all( $id ) ) {
			$terms = [];
			$posts = [];
			foreach ( $scopes as $key => $one ) {
				if ( 'term' === (string) ( $one['kind'] ?? '' ) ) {
					$terms[ $key ] = $one;
				} else {
					$posts[ $key ] = $one;
				}
			}
			// Le goutte-a-goutte ne vaut que pour les posts : s il est epuise
			// pour aujourd hui, seuls les termes restent en lice.
			$conf = self::conf( $id );
			$out_today = self::done_today( $id . ':post' );
			$scopes = $out_today >= (int) $conf['per_day'] ? $terms : $terms + $posts;
		}
		foreach ( $scopes as $scope ) {
			if ( count( $out ) >= $n ) {
				break;
			}
			// The languages this kind is short of are the object's own, so the
			// page is asked for EVERY active language and each row is then
			// read for what it actually owes.
			$langs = [];
			foreach ( DZE_Wpml::get_active_languages() as $l ) {
				$code = (string) ( $l['code'] ?? '' );
				if ( '' !== $code && $code !== $src ) {
					$langs[] = $code;
				}
			}
			if ( ! $langs ) {
				return []; // one language: there is nothing here to translate into.
			}
			// ON TOURNE LES PAGES TANT QUE TOUT CE QU ELLES RENDENT EST RETENU.
			//
			// Une page de cinq fois la demande, et rien derriere : le jour ou ces
			// cinq-la attendent deja une relecture, cette portee ne rend plus rien
			// et le travail qui suit juste derriere reste invisible. C est le meme
			// piege que le maillage a connu — la passe demandait UNE page, en
			// regardait trois, et les trois etaient prises : elle a cesse de
			// travailler pendant vingt heures sans laisser de trace.
			//
			// On s arrete des que la liste rend moins qu on lui demande — elle n a
			// plus rien — ou au plafond : chercher sans fin coute autant que ne
			// pas chercher.
			$per   = max( 1, $n ) * 5;
			$paged = 0;
			$max   = 6; // six pages de cinq fois la demande, et pas le catalogue.
			while ( ++$paged <= $max && count( $out ) < $n ) {
				$page = DZE_Translate::todo_page( $scope, $src, $langs, $paged, $per );
				if ( null === $page ) {
					break; // WPML's tables cannot be read: this kind answers nothing.
				}
				foreach ( (array) $page[0] as $o ) {
					if ( count( $out ) >= $n ) {
						break;
					}
					$oid  = (int) ( $o['id'] ?? 0 );
					$type = 'term' === (string) ( $o['kind'] ?? '' ) ? 'term' : 'post';
					if ( $oid < 1 ) {
						continue;
					}
					// ALREADY WAITING FOR A DECISION IS NOT WORK. A second run
					// over the same object writes a second translation into the
					// same store, and the one somebody has not read yet is gone.
					if ( DZE_Translate::waiting( $o ) ) {
						self::$held['waiting']++;
						continue;
					}
					// LE FOURRE-TOUT D UNE TAXONOMIE N EST PAS DU TEXTE CLIENT.
					// « Uncategorized » part en cinq langues, revient en cinq
					// orthographes et remplit la liste a relire de lignes dont la
					// seule decision honnete est de les ignorer.
					if ( 'term' === $type && DZE_Translate::is_default_term( $oid, (string) ( $o['type'] ?? '' ) ) ) {
						continue;
					}
					// UNE CATEGORIE VIDE N EST PAS UNE PAGE A TRADUIRE. « Le plugin
					// a encore traduit une categorie avec 0 produits. » Son archive
					// est vide, aucun menu n y mene, et la traduire coute un appel
					// par langue pour une page que personne ne verra. La
					// descendance compte : une categorie de tete ne porte souvent
					// rien elle-meme et tout son rayon dessous.
					if ( 'term' === $type && DZE_Translate::is_empty_term( $oid, (string) ( $o['type'] ?? '' ) ) ) {
						continue;
					}
					if ( self::cooling( $oid, $id, $type, 0, 0, time() - self::COOLDOWN * DAY_IN_SECONDS ) ) {
						self::$held['recent']++;
						continue;
					}
					$owed = self::translate_owed( $o, $langs );
					if ( ! $owed ) {
						continue; // WPML is satisfied with every language of it.
					}
					// UN PRODUIT ENTRAINE SES PROPRES ATTRIBUTS, ET AVANT LUI.
					//
					// « Un produit avec attributs non traduits, a la traduction du
					// produit ca doit automatiquement traduire les attributs. » Une
					// fiche traduite dont la couleur et la taille sont restees en
					// anglais est une page a moitie faite — et c est la moitie que le
					// client lit pour choisir.
					//
					// Les attributs passent DEVANT et le produit repasse au tour
					// suivant : l ordre est la seule chose qui garantisse que la fiche
					// ne sorte jamais avant ce qu elle affiche.
					if ( 'post' === $type && 'product' === (string) ( $o['type'] ?? '' ) ) {
						$first = self::attrs_owed( $oid, $langs );
						if ( $first ) {
							foreach ( $first as $one ) {
								if ( count( $out ) >= $n ) {
									break;
								}
								$out[] = $one;
							}
							continue;
						}
					}
					$out[] = [
						'tid'   => $oid,
						'name'  => DZE_Translate::obj_label( $o ),
						'kind'  => 'term' === $type ? 'product_cat' : 'post',
						'ref'   => DZE_Translate::ref( $o ),
						'langs' => $owed,
						'why'   => sprintf(
							/* translators: %s: the languages it is short of, e.g. "FR, DE" */
							_n( 'owes %s', 'owes %s', count( $owed ), 'dazont-ecom' ),
							implode( ', ', array_map( 'strtoupper', $owed ) )
						),
					];
				}
				// Moins que demande : cette portee n a plus rien a offrir.
				if ( count( (array) $page[0] ) < $per ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * LES VALEURS D ATTRIBUT QUE CE PRODUIT PORTE ET QUI NE SONT PAS TRADUITES.
	 *
	 * Rendues dans la forme d une ligne de liste, pretes a passer devant le
	 * produit. Deux regles tiennent cette fonction :
	 *
	 * — une valeur qu aucun produit ne porte n arrive jamais ici, puisqu on
	 *   part du produit ; c est l autre moitie de « pas besoin de traduire des
	 *   attributs non utilises » ;
	 * — un attribut que la boutique a DECOCHE n est pas traduit non plus, meme
	 *   en dependance. Une dependance qui passe outre le reglage est un
	 *   reglage qui ne sert a rien.
	 *
	 * @param string[] $langs
	 * @return array<int,array<string,mixed>>
	 */
	private static function attrs_owed( int $pid, array $langs ): array {
		if ( $pid < 1 || ! class_exists( 'DZE_Translate' ) ) {
			return [];
		}
		$picked = DZE_Translate::picked_scope();
		$out    = [];
		foreach ( array_keys( $picked ) as $key ) {
			if ( 0 !== strpos( (string) $key, 'term:pa_' ) ) {
				continue;
			}
			$tax   = substr( (string) $key, strlen( 'term:' ) );
			$terms = wp_get_object_terms( $pid, $tax, [ 'fields' => 'ids' ] );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( (array) $terms as $tid ) {
				$tid = (int) $tid;
				$one = DZE_Translate::obj( 'term', $tid, $tax );
				if ( ! $one || DZE_Translate::waiting( $one ) ) {
					continue; // deja en attente d une decision : ne pas l ecrire deux fois.
				}
				$owed = self::translate_owed( $one, $langs );
				if ( ! $owed ) {
					continue;
				}
				$out[] = [
					'tid'   => $tid,
					'name'  => DZE_Translate::obj_label( $one ),
					'kind'  => 'product_cat',
					'ref'   => DZE_Translate::ref( $one ),
					'langs' => $owed,
					'why'   => sprintf(
						/* translators: %s: the languages it is short of, e.g. "FR, DE" */
						__( 'a product needs it — owes %s', 'dazont-ecom' ),
						implode( ', ', array_map( 'strtoupper', $owed ) )
					),
				];
			}
		}
		return $out;
	}

	/**
	 * WHICH LANGUAGES ONE OBJECT IS SHORT OF — WPML's mark, never our own.
	 *
	 * A language with no translation at all is owed; one WPML has marked as
	 * needing an update is owed; anything else is WPML being satisfied and is
	 * not this module's to send. Our own register decides what is SENT inside
	 * the job (`obj_stale()`), which is a different and later question.
	 *
	 * @param string[] $langs
	 * @return string[]
	 */
	private static function translate_owed( array $o, array $langs ): array {
		// WPML indexes a post by its id and a TERM by its term taxonomy id, so
		// the answer is asked for by the one function that knows the
		// difference — the same one the screen's own rows are drawn from.
		$marks = (array) DZE_Translate::page_marks( [ $o ] );
		$mine  = (array) ( $marks[ DZE_Translate::element_id_of( $o ) ] ?? [] );
		$out   = [];
		foreach ( $langs as $code ) {
			$one = (string) ( $mine[ $code ] ?? '' );
			// No row at all, or a row WPML has marked as needing an update.
			// 'done' is WPML being satisfied, and that is not ours to overrule.
			if ( '' === $one || 'marked' === $one ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/**
	 * THE WHOLE SITE IN ONE PRESS, and then the daily pass is maintenance.
	 *
	 * "J'aurais même bien aimé pouvoir lancer le maillage interne de tout le
	 * site en une fois, puis automatiser le maillage des nouvelles pages. Une
	 * sorte de maintenance."
	 *
	 * It is the SECOND phase over everything: every page under its own
	 * outgoing quota, each one filling its links from its own pool. That is
	 * the catch-up a shop does once, and it is deliberately not the first
	 * phase — building a plan for one orphan asks the model which of its
	 * neighbours should point at it, and doing that for three hundred pages
	 * inside one press is three hundred calls with somebody waiting on the
	 * answer. Phase two costs nothing to plan: the arithmetic is the census's,
	 * and the model call happens inside each job, one at a time, in the queue.
	 *
	 * Nothing is written to the shop by this: every page comes back to the
	 * review list, unless the task's own "Save without review" is ticked.
	 *
	 * @return array{queued:int,more:bool,task:string,reason:string}
	 */
	public static function catch_up( string $id, int $cap = self::CATCHUP_MAX ): array {
		$out = [ 'queued' => 0, 'more' => false, 'task' => $id, 'reason' => 'none' ];
		if ( 'mesh' !== (string) ( self::conf( $id )['scope'] ?? '' ) || ! class_exists( 'DZE_Mesh' ) ) {
			$out['reason'] = 'gone';
			return $out;
		}
		// A press by hand runs past the day's figure and the spacing — that is
		// what pressing it means — and never past a switched-off module, a
		// copy of the shop or the monthly budget.
		$why = self::why_not( $id, true );
		if ( '' !== $why ) {
			$out['reason'] = $why;
			return $out;
		}
		if ( null === DZE_Mesh::orphan_count() ) {
			$out['reason'] = 'unread';
			return $out;
		}
		$cap  = max( 1, min( self::CATCHUP_MAX, $cap ) );
		$conf = self::conf( $id );
		$rows = self::mesh_shortlist( $id, $cap, 'out' );

		// A BULK PRESS DOES ITS BOOKKEEPING ONCE. Looping the single-page path
		// read and rewrote the register — the day's figure, the log, the undo
		// copies — for EVERY page: two hundred read-modify-writes of one option
		// inside one request, and a log holding nothing but the last twelve
		// lines of the press that filled it. The pages are queued the way the
		// queue takes them, in one call per kind, and the register is written
		// once, for the press.
		$by = [];
		foreach ( $rows as $row ) {
			$job = 'product_cat' === (string) $row['kind'] ? 'cat_links' : 'post_links';
			$by[ $job ][] = (int) $row['tid'];
		}
		foreach ( $by as $job => $ids ) {
			$out['queued'] += (int) DZE_Queue::add( $job, $ids, (bool) $conf['apply'], [] );
		}
		// Marked only once the work is really under way: a page stamped by a
		// queue that refused is a page locked out for a month having had
		// nothing done to it.
		if ( $out['queued'] > 0 ) {
			foreach ( $rows as $row ) {
				self::mark( (int) $row['tid'], $id, 'product_cat' === (string) $row['kind'] ? 'term' : 'post', 0, 0 );
			}
			self::note(
				$id,
				0,
				sprintf(
					/* translators: %s: how many pages were put in the writing queue */
					_n( 'Link the whole site — %s page', 'Link the whole site — %s pages', $out['queued'], 'dazont-ecom' ),
					number_format_i18n( $out['queued'] )
				),
				'shop',
				0,
				$out['queued'],
				false
			);
			delete_transient( 'dze_auto_survey' );
			delete_transient( 'dze_pl_census' );
			DZE_Mesh::forget_thin();
		}
		// IS THERE MORE? Asked of the same reader, which already knows what is
		// in the queue — so the answer is "one more exists", never a second
		// count of the whole site that would disagree with the first.
		$out['more']   = (bool) self::mesh_shortlist( $id, 1, 'out' );
		$out['reason'] = $out['queued'] ? 'queued' : 'none';
		return $out;
	}

	/** What a page of the second phase is short of, in words. */
	public static function thin_said( int $gap ): string {
		return sprintf(
			/* translators: %d: how many links short of its own quota the page is */
			_n( 'one link short of what its length calls for', '%d links short of what its length calls for', max( 1, $gap ), 'dazont-ecom' ),
			max( 1, $gap )
		);
	}

	/** The one thing a shop-wide task can be short of. */
	private static function shop_shortlist( string $id ): array {
		if ( 'events' !== $id || ! class_exists( 'DZE_Marketing_Ai' ) ) {
			return [];
		}
		// A pile of suggestions nobody has answered is not a shortage of
		// suggestions: asking for more would only make the pile taller.
		if ( DZE_Marketing_Ai::pending_count() >= 8 ) {
			return [];
		}
		$until = DZE_Marketing_Ai::covered_until();
		$why   = $until > time()
			/* translators: %s: date the accepted calendar runs to */
			? sprintf( __( 'calendar runs to %s', 'dazont-ecom' ), date_i18n( (string) get_option( 'date_format' ), $until ) )
			: __( 'nothing planned', 'dazont-ecom' );
		return [ [ 'tid' => 0, 'name' => __( 'The coming quarter', 'dazont-ecom' ), 'why' => $why ] ];
	}

	/** Articles and pages carrying fewer links than their length calls for. */
	private static function post_shortlist( string $id, int $n ): array {
		if ( ! class_exists( 'DZE_Post_Links' ) ) {
			return [];
		}
		$cool = time() - self::COOLDOWN * DAY_IN_SECONDS;
		$pool = [];
		foreach ( DZE_Post_Links::census() as $pid => $row ) {
			$short = (int) $row['target'] - (int) $row['out'];
			if ( $short < 1 ) {
				continue;
			}
			if ( self::cooling( (int) $pid, $id, 'post', (int) $row['words'], (int) $row['out'], $cool ) ) {
				continue;
			}
			$pool[] = [ 'tid' => (int) $pid, 'name' => (string) $row['title'], 'short' => $short, 'out' => (int) $row['out'], 'target' => (int) $row['target'] ];
		}
		// The emptiest first: an article pointing nowhere before one that is
		// merely one link short.
		usort( $pool, static fn( $a, $b ) => [ $b['short'], $a['out'] ] <=> [ $a['short'], $b['out'] ] );
		$out = [];
		foreach ( array_slice( $pool, 0, self::LOOK ) as $row ) {
			if ( class_exists( 'DZE_Queue' ) && DZE_Queue::pending_for( (int) $row['tid'], 'post_' ) ) {
				continue; // already queued, running, or waiting to be reviewed.
			}
			$out[] = [
				'tid'  => (int) $row['tid'],
				'name' => (string) $row['name'],
				/* translators: 1: links in the article, 2: links its length calls for */
				'why'  => sprintf( __( '%1$d of %2$d links', 'dazont-ecom' ), (int) $row['out'], (int) $row['target'] ),
			];
			if ( count( $out ) >= max( 1, $n ) ) {
				break;
			}
		}
		return $out;
	}

	/** Categories, ranked on the census and then on their real targets. */
	private static function cat_shortlist( string $id, int $n ): array {
		$rows = self::survey()['rows'];
		if ( ! $rows ) {
			return [];
		}
		$cool = time() - self::COOLDOWN * DAY_IN_SECONDS;
		$pool = [];
		foreach ( $rows as $tid => $row ) {
			if ( self::cooling( (int) $tid, $id, 'term', (int) $row['words'], (int) $row['out'], $cool ) ) {
				continue;
			}
			$pool[] = [ 'tid' => (int) $tid, 'name' => (string) $row['name'], 'in' => (int) $row['in'], 'out' => (int) $row['out'], 'words' => (int) $row['words'] ];
		}
		// Emptiest first: no description at all, then the thinnest. Ranking
		// pages by how little the rest of the site points at them is not done
		// here any more — the link graph does it, for every kind of page at
		// once, and this list is the writing task's alone.
		usort( $pool, static fn( $a, $b ) => $a['words'] <=> $b['words'] );
		$conf = self::conf( $id );
		$out  = [];
		foreach ( array_slice( $pool, 0, self::LOOK ) as $row ) {
			$tid = (int) $row['tid'];
			if ( class_exists( 'DZE_Queue' ) && DZE_Queue::pending_for( $tid ) ) {
				continue; // already queued, running, or waiting to be reviewed.
			}
			$why = self::shortfall( $id, $tid, $conf );
			if ( '' === $why ) {
				continue;
			}
			$out[] = [ 'tid' => $tid, 'name' => (string) $row['name'], 'why' => $why ];
			if ( count( $out ) >= max( 1, $n ) ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Is this object still resting after its last pass?
	 *
	 * A pass that changed nothing — a failed job, or a model that found no
	 * room — must not lock the page out for a month: it comes back in days.
	 */
	/**
	 * What the last shortlist passed over, and why. Not a cache: a tally,
	 * emptied at the top of every reading, so it always answers for the
	 * question just asked.
	 *
	 * @var array{queued:int,recent:int}
	 */
	private static array $held = [ 'queued' => 0, 'recent' => 0, 'unread' => 0, 'waiting' => 0 ];

	public static function held_reset(): void {
		self::$held = [ 'queued' => 0, 'recent' => 0, 'unread' => 0, 'waiting' => 0 ];
	}

	/** @return array{queued:int,recent:int} */
	public static function held_now(): array {
		return self::$held;
	}

	/**
	 * "NOTHING TO DO" IS THREE DIFFERENT ANSWERS, and only one of them means
	 * the site is finished.
	 *
	 * A page already in the writing queue, and a page worked on a few days
	 * ago, are both short of links — saying "every page has what its size
	 * calls for" over a site full of unlinked pages is how a working screen
	 * reads as a broken one.
	 */
	public static function nothing_said( string $task = '' ): string {
		// ONE QUESTION, ONE SENTENCE — and the question is not the same one
		// for every task. "Every page has what its size calls for" is a true
		// answer about links and a meaningless one about languages, and it is
		// what this said on the Translations task before it had words of its
		// own.
		if ( 'translate' === (string) ( self::task( $task )['scope'] ?? '' ) ) {
			return self::translate_nothing_said();
		}
		if ( ! empty( self::$held['unread'] ) ) {
			return self::unread_said();
		}
		// A FIGURE THE READING CANNOT SUPPORT IS WORSE THAN NO FIGURE. The
		// tally is a REASON and never a total: the shortlist walks a handful of
		// candidates and stops the moment it has enough, so "6 pages are short
		// of links" was however many it happened to look at — printed beside a
		// chip announcing 165. Where a figure is given it is the QUEUE'S, which
		// counts the whole shop and knows exactly.
		$q = (int) self::$held['queued'];
		$r = (int) self::$held['recent'];
		if ( $q > 0 ) {
			$c    = class_exists( 'DZE_Queue' ) ? (array) DZE_Queue::counts_for( self::my_kinds() ) : [];
			$busy = (int) ( $c['queued'] ?? 0 ) + (int) ( $c['running'] ?? 0 );
			if ( $busy > 0 ) {
				return sprintf(
					/* translators: %s: how many pages are waiting in the writing queue */
					_n(
						'Nothing new to start: %s page is already in the writing queue.',
						'Nothing new to start: %s pages are already in the writing queue.',
						$busy,
						'dazont-ecom'
					),
					number_format_i18n( $busy )
				);
			}
			return __( 'Nothing new to start: the pages it looked at are already in the writing queue.', 'dazont-ecom' );
		}
		if ( $r > 0 ) {
			// No figure at all: this one is only knowable by reading the
			// register of every page on the site, which this is not.
			return __( 'Nothing new to start: the pages it looked at were all worked on in the last few days.', 'dazont-ecom' );
		}
		return __( 'Nothing is short of anything: every page has what its size calls for.', 'dazont-ecom' );
	}

	/**
	 * "Nothing to translate" is three answers, and only one means the shop is
	 * up to date in every language.
	 */
	private static function translate_nothing_said(): string {
		$w = (int) self::$held['waiting'];
		$r = (int) self::$held['recent'];
		if ( $w > 0 ) {
			// The figure is the SHOP'S, counted whole by the module that holds
			// them — never the tally, which is however many this reading
			// happened to walk past before it had enough.
			$n = class_exists( 'DZE_Translate' ) ? (int) DZE_Translate::review_count() : $w;
			return sprintf(
				/* translators: %s: how many objects hold a translation waiting for a decision */
				_n(
					'Nothing new to send: %s object is already holding a translation waiting for your yes or no.',
					'Nothing new to send: %s objects are already holding a translation waiting for your yes or no.',
					$n,
					'dazont-ecom'
				),
				number_format_i18n( $n )
			);
		}
		if ( $r > 0 ) {
			return __( 'Nothing new to send: the objects it looked at were all translated in the last few days.', 'dazont-ecom' );
		}
		return __( 'Nothing is owed a translation: WPML is satisfied with every language of everything this shop translates.', 'dazont-ecom' );
	}

	/** The site has not been read: the one sentence, and the way to the reading. */
	public static function unread_said(): string {
		$where = class_exists( 'DZE_Screens' ) ? DZE_Screens::name( 'linking' ) : '';
		return '' !== $where
			/* translators: %s: the screen where the site is read */
			? sprintf( __( 'The site has not been read yet, so there is nothing to link. Read it under %s.', 'dazont-ecom' ), $where )
			: __( 'The site has not been read yet, so there is nothing to link.', 'dazont-ecom' );
	}

	/**
	 * A PROMISE THE QUEUE NEVER KEPT DOES NOT HOLD A PAGE.
	 *
	 * "J'ai lancé le link everything bouton, maintenant plus rien ne fonctionne
	 * correctement. Je ne sais même pas ce qui a été fait ou non." The catch-up
	 * stamps every page it queues so the daily pass does not do it twice, and
	 * it stamps them with NO figures because nothing has been written yet —
	 * that stamp is a promise, not a record. Dropped, failed, or cleared, the
	 * promise went on holding the page out of the pass meant to mend it, and
	 * the only way out was to wait three days.
	 *
	 * Three things must all be true before it is let go, and the third is what
	 * stops this from writing a second text over one somebody just accepted:
	 * the stamp records no work at all, nothing of this page is in the queue,
	 * and nothing was ever written and accepted for it. An applied row is kept
	 * for ever — the queue's Clear may not delete one — so that answer does not
	 * expire.
	 */
	private static function promise_broken( int $oid, string $id, string $type, string $kind ): bool {
		$seen = self::seen( $oid, $id, $type );
		if ( ! $seen || (int) ( $seen['w'] ?? 0 ) || (int) ( $seen['l'] ?? 0 ) ) {
			return false; // it carries figures: something was really taken in hand.
		}
		if ( ! class_exists( 'DZE_Queue' ) ) {
			return false;
		}
		if ( DZE_Queue::pending_for( $oid, 'product_cat' === $kind ? 'cat_' : 'post_' ) ) {
			return false; // still waiting its turn: the promise is being kept.
		}
		// `done_map()` answers the most recent APPLIED row per object — the
		// record that this plugin wrote to that page and somebody said yes.
		$done = (array) DZE_Queue::done_map( [ $oid ] );
		return empty( $done[ $oid ] );
	}

	/** Does the register claim this object was worked on by this task? */
	public static function worked_on( int $oid, string $id, string $type = 'term' ): bool {
		return (bool) self::seen( $oid, $id, $type );
	}

	/**
	 * LETS GO OF PAGES THAT WERE PROMISED WORK AND NEVER GOT IT.
	 *
	 * The catch-up stamps every page it queues so the daily pass does not do
	 * them twice — right while the row is there, and a lie the moment the row
	 * is dropped. Calling a run off deleted the rows and left the stamps
	 * standing: two hundred pages marked as worked on, with nothing written to
	 * any of them, locked out of the very pass meant to mend them. That is the
	 * fault this plugin already refuses by name, arriving through the button
	 * that calls a run off.
	 *
	 * @param array<int,array{kind:string,object_id:int}> $rows The queue's own.
	 * @return int How many stamps were let go.
	 */
	public static function free_pages( array $rows ): int {
		$n = 0;
		foreach ( $rows as $row ) {
			$kind = (string) ( $row['kind'] ?? '' );
			$task = self::task_for_job( $kind );
			if ( '' === $task ) {
				continue; // not work any task here started.
			}
			$oid = (int) ( $row['object_id'] ?? 0 );
			if ( $oid < 1 ) {
				continue;
			}
			if ( self::unmark( $oid, $task, self::what_is( $kind ) ) ) {
				$n++;
			}
		}
		return $n;
	}

	/** Which task queues this kind of job — read from the tasks themselves. */
	public static function task_for_job( string $kind ): string {
		foreach ( self::tasks() as $id => $task ) {
			if ( in_array( $kind, (array) ( $task['jobs'] ?? [] ), true ) ) {
				return (string) $id;
			}
		}
		return '';
	}

	/** Takes one task's stamp off one object. */
	private static function unmark( int $oid, string $id, string $type ): bool {
		$all = 'post' === $type ? get_post_meta( $oid, self::META_SEEN, true ) : get_term_meta( $oid, self::META_SEEN, true );
		$all = is_array( $all ) ? $all : [];
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		unset( $all[ $id ] );
		if ( 'post' === $type ) {
			update_post_meta( $oid, self::META_SEEN, $all );
		} else {
			update_term_meta( $oid, self::META_SEEN, $all );
		}
		return true;
	}

	private static function cooling( int $oid, string $id, string $type, int $words, int $links, int $cool ): bool {
		$seen = self::seen( $oid, $id, $type );
		if ( ! $seen || (int) $seen['t'] <= $cool ) {
			return false;
		}
		$moved = $words !== (int) ( $seen['w'] ?? 0 ) || $links !== (int) ( $seen['l'] ?? 0 );
		return $moved || (int) $seen['t'] > time() - self::RETRY * DAY_IN_SECONDS;
	}

	/**
	 * What this category is short of for this task, said in the panel's own
	 * figures — or '' when it is not short of anything.
	 */
	public static function shortfall( string $id, int $tid, ?array $conf = null ): string {
		if ( ! class_exists( 'DZE_Category_Content' ) ) {
			return '';
		}
		$conf = $conf ?? self::conf( $id );
		$st   = DZE_Category_Content::state( $tid );
		if ( 'cat_desc' === $id ) {
			if ( ! empty( $conf['kw_only'] ) && $st['keywords'] < 1 ) {
				return ''; // no SEMrush file: headings would come from the name alone.
			}
			if ( ! $st['has_desc'] ) {
				return __( 'no description', 'dazont-ecom' );
			}
			// Only a real shortfall, not a rounding: two thirds of the target.
			if ( $st['words_target'] > 0 && $st['words'] < (int) round( $st['words_target'] * 0.66 ) ) {
				return sprintf(
					/* translators: 1: words written, 2: word target */
					__( '%1$d of %2$d words', 'dazont-ecom' ),
					(int) $st['words'],
					(int) $st['words_target']
				);
			}
			return '';
		}
		return '';
	}

	/** What a task last did to an object: when, and the figures it left. */
	private static function seen( int $oid, string $id, string $type = 'term' ): array {
		$all = 'post' === $type ? get_post_meta( $oid, self::META_SEEN, true ) : get_term_meta( $oid, self::META_SEEN, true );
		$all = is_array( $all ) ? $all : [];
		$row = (array) ( $all[ $id ] ?? [] );
		return isset( $row['t'] ) ? $row : [];
	}

	private static function mark( int $oid, string $id, string $type, int $words, int $links ): void {
		$all = 'post' === $type ? get_post_meta( $oid, self::META_SEEN, true ) : get_term_meta( $oid, self::META_SEEN, true );
		$all = is_array( $all ) ? $all : [];
		$all[ $id ] = [ 't' => time(), 'w' => $words, 'l' => $links ];
		if ( 'post' === $type ) {
			update_post_meta( $oid, self::META_SEEN, $all );
		} else {
			update_term_meta( $oid, self::META_SEEN, $all );
		}
	}

	/**
	 * The text one pass is about to replace, kept for the undo.
	 *
	 * SLASHED ON THE WAY IN. `update_post_meta()` hands its value to
	 * `wp_unslash()`, which is right for a form and wrong for a copy being
	 * filed away: every backslash in the article would be gone from the copy
	 * we put back. Prose rarely holds one, a code sample always does, and an
	 * undo that quietly returns a slightly different article is worse than one
	 * that refuses.
	 */
	private static function keep_copy( int $oid, string $type, string $html ): void {
		$html = function_exists( 'wp_slash' ) ? wp_slash( $html ) : $html;
		if ( 'post' === $type ) {
			update_post_meta( $oid, self::META_PREV, $html );
		} else {
			update_term_meta( $oid, self::META_PREV, $html );
		}
	}

	private static function copy_of( int $oid, string $type ): string {
		return (string) ( 'post' === $type ? get_post_meta( $oid, self::META_PREV, true ) : get_term_meta( $oid, self::META_PREV, true ) );
	}

	private static function drop_copy( int $oid, string $type ): void {
		if ( 'post' === $type ) {
			delete_post_meta( $oid, self::META_PREV );
		} else {
			delete_term_meta( $oid, self::META_PREV );
		}
	}

	/** The object a task works on, as WordPress holds it. */
	private static function subject( string $scope, int $oid ): array {
		if ( 'post' === $scope ) {
			$post = get_post( $oid );
			return $post
				? [ 'name' => (string) $post->post_title, 'html' => (string) $post->post_content, 'type' => 'post' ]
				: [];
		}
		// LE SUJET DE LA PASSE, lu en table. Par get_term(), la passe nocturne
		// prenait le nom ET le texte de la traduction pour travailler sur
		// l original : elle nommait une categorie et en ecrivait une autre.
		$row = class_exists( 'DZE_Category_Content' ) ? DZE_Category_Content::term_row( $oid ) : null;
		return $row
			? [ 'name' => (string) $row['name'], 'html' => (string) $row['description'], 'type' => 'term' ]
			: [];
	}

	// =========================================================================
	// The tick
	// =========================================================================

	/**
	 * One item, once an hour, task by task.
	 *
	 * @param string $only   Run this task alone (the button on its block).
	 * @param bool   $forced Asked for by hand. A deliberate press runs: neither
	 *                       the day's figure nor the spacing inside the period
	 *                       stops it, and it is COUNTED, so the automatic pass
	 *                       does that much less. What never yields is what
	 *                       protects the shop — a switched-off module, a copy
	 *                       of the shop, the monthly budget.
	 *
	 * @return array{queued:int,task:string,reason:string}
	 */
	public static function tick( string $only = '', bool $forced = false ): array {
		$ids    = '' !== $only ? [ $only ] : array_keys( self::tasks() );
		$reason = 'none';
		foreach ( $ids as $id ) {
			if ( ! self::task( $id ) ) {
				continue;
			}
			$why = self::why_not( $id, $forced );
			if ( '' !== $why ) {
				// « off » et « early » sont des etats normaux et attendus : les
				// ecrire a chaque passe remplirait le journal de bruit. Les autres
				// — budget depasse, module eteint, copie de boutique — sont des
				// empechements qu il faut pouvoir lire.
				if ( ! in_array( $why, [ 'off', 'early' ], true ) ) {
					self::note_nothing( $id, $why );
				}
				$reason = $why;
				continue;
			}
			$pick = self::shortlist( $id, 1 );
			if ( ! $pick ) {
				// RIEN TROUVE N EST PAS RIEN A DIRE.
				//
				// C est le defaut qui a coute le plus cher : une passe qui ne
				// trouve rien ne laissait AUCUNE trace, donc un module casse et un
				// module au repos se ressemblaient trait pour trait. On decouvrait
				// la panne vingt-sept heures plus tard, par hasard.
				//
				// Ce qui a ete regarde et pourquoi ca a ete ecarte est ecrit ici,
				// une fois par passe, et l ecran le dit.
				self::note_nothing( $id, 'none' );
				$reason = 'none';
				continue;
			}
			// The row travels with the id, carrying the addresses the graph
			// chose, so the run does not build the plan a second time. Pressed
			// by hand there is no row, and the run asks the SAME plan rather
			// than falling back on what the page would have linked to on its
			// own — which is a different question with a different answer.
			$res = self::run( $id, (int) $pick[0]['tid'], (array) $pick[0] );
			if ( $res['queued'] ) {
				return $res;
			}
			$reason = $res['reason'];
		}
		return [ 'queued' => 0, 'task' => $only, 'reason' => $reason ];
	}

	/**
	 * CE QUE LA DERNIERE PASSE N A PAS TROUVE, ET POURQUOI.
	 *
	 * Ecrit dans l etat, une ligne par tache, ecrasee a chaque fois : ce n est
	 * pas un journal, c est un dernier etat connu. Assez pour que l ecran
	 * puisse dire « la derniere passe a regarde et n a rien retenu, voici ce
	 * qui l a retenue » au lieu de ne rien dire du tout.
	 */
	private static function note_nothing( string $id, string $why ): void {
		$s = self::state();
		$s['idle'] = (array) ( $s['idle'] ?? [] );
		$s['idle'][ $id ] = [
			'at'   => time(),
			'why'  => $why,
			'held' => array_filter( (array) self::held_now() ),
		];
		self::save_state( $s );
	}

	/** Le dernier passage a vide d une tache, ou [] si elle a travaille depuis. */
	public static function idle_of( string $id ): array {
		$s = self::state();
		$one = (array) ( ( $s['idle'] ?? [] )[ $id ] ?? [] );
		// Une passe qui a PRODUIT depuis efface la plainte : sinon l ecran
		// garderait un vieux « rien trouve » au-dessus d un travail bien reel.
		$last = (int) ( ( $s['last'] ?? [] )[ $id ] ?? 0 );
		return ( $one && $last > (int) ( $one['at'] ?? 0 ) ) ? [] : $one;
	}

	/**
	 * CE QUE LA TACHE DIRAIT SI ON LUI DEMANDAIT POURQUOI ELLE NE FAIT RIEN.
	 *
	 * '' quand elle travaille normalement.
	 */
	public static function idle_said( string $id ): string {
		$one = self::idle_of( $id );
		if ( ! $one ) {
			return '';
		}
		$mots = [
			'budget'  => __( 'the monthly AI budget is spent', 'dazont-ecom' ),
			'copy'    => __( 'this is a copy of the shop, so nothing runs on its own', 'dazont-ecom' ),
			'modules' => __( 'a module it needs is switched off', 'dazont-ecom' ),
		];
		$why = (string) ( $one['why'] ?? '' );
		if ( isset( $mots[ $why ] ) ) {
			return sprintf(
				/* translators: 1: how long ago, 2: the reason */
				__( 'Nothing done for %1$s: %2$s.', 'dazont-ecom' ),
				human_time_diff( (int) $one['at'], time() ),
				$mots[ $why ]
			);
		}
		// RIEN TROUVE : on dit ce qui a ete ECARTE, qui est la seule chose
		// utile — « rien a faire » et « tout est en attente de votre relecture »
		// demandent deux gestes opposes.
		$held  = (array) ( $one['held'] ?? [] );
		$bouts = [];
		$noms  = [
			'queued'  => __( '%s already waiting in the writing queue', 'dazont-ecom' ),
			'recent'  => __( '%s done recently and resting', 'dazont-ecom' ),
			'waiting' => __( '%s waiting for your yes or no', 'dazont-ecom' ),
			'unread'  => __( 'the site has not been read yet', 'dazont-ecom' ),
		];
		foreach ( $noms as $k => $forme ) {
			$n = (int) ( $held[ $k ] ?? 0 );
			if ( $n < 1 ) {
				continue;
			}
			$bouts[] = 'unread' === $k ? $forme : sprintf( $forme, number_format_i18n( $n ) );
		}
		return $bouts
			? sprintf(
				/* translators: 1: how long ago, 2: a list of reasons */
				__( 'Nothing found for %1$s — %2$s.', 'dazont-ecom' ),
				human_time_diff( (int) $one['at'], time() ),
				implode( ', ', $bouts )
			)
			: sprintf(
				/* translators: %s: how long ago */
				__( 'Nothing left to do since %s.', 'dazont-ecom' ),
				human_time_diff( (int) $one['at'], time() )
			);
	}
	/** Sets one piece of work going, whatever kind of work it is. */
	public static function run( string $id, int $oid, array $row = [] ): array {
		$task = self::task( $id );
		$conf = self::conf( $id );
		$no   = static fn( string $why ): array => [ 'queued' => 0, 'task' => $id, 'reason' => $why ];
		if ( ! $task ) {
			return $no( 'gone' );
		}

		// The linking pass works on a page of either kind and carries the
		// addresses the graph chose. Pressed by hand with no row, it asks the
		// graph for that page's own work rather than guessing at it.
		if ( 'mesh' === $conf['scope'] ) {
			return self::run_mesh( $id, $oid, $row, $conf );
		}
		if ( 'translate' === $conf['scope'] ) {
			return self::run_translate( $id, $oid, $row, $conf );
		}

		// A shop-wide task has no object and no queue job: it is one call, made
		// here, whose result waits on a review screen of its own.
		if ( 'shop' === $conf['scope'] ) {
			if ( 'events' !== $id || ! class_exists( 'DZE_Marketing_Ai' ) ) {
				return $no( 'gone' );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- nobody is waiting on cron.
			}
			try {
				$res = DZE_Marketing_Ai::propose(
					gmdate( 'Y-m-d' ),
					gmdate( 'Y-m-d', time() + 92 * DAY_IN_SECONDS )
				);
			} catch ( \Throwable $e ) {
				return $no( 'failed' );
			}
			// A shop-wide pass has no words and no links: what it produced is
			// a number of suggestions, and that is what the log shows.
			self::note( $id, 0, __( 'Marketing calendar', 'dazont-ecom' ), 'shop', 0, (int) $res['added'], false );
			return [ 'queued' => 1, 'task' => $id, 'reason' => 'queued' ];
		}

		$sub = self::subject( $conf['scope'], $oid );
		if ( ! $sub ) {
			return $no( 'gone' );
		}
		$words = str_word_count( wp_strip_all_tags( $sub['html'] ) );
		$links = (int) preg_match_all( '/<a\s[^>]*href=/i', $sub['html'] );
		if ( ! DZE_Queue::add( (string) $task['kind'], [ $oid ], (bool) $conf['apply'] ) ) {
			return $no( 'busy' );
		}
		// A PASS THAT WAS NEVER QUEUED IS NOT A PASS. The register used to be
		// written before the queue was asked, so a queue that refused left the
		// page stamped as worked on and locked out for three days having had
		// nothing done to it.
		// What goes straight to the shop keeps the text it replaced; what waits
		// for review is undone from the review screen, which holds both.
		if ( $conf['apply'] ) {
			self::keep_copy( $oid, $sub['type'], $sub['html'] );
		}
		self::mark( $oid, $id, $sub['type'], $words, $links );
		self::note( $id, $oid, (string) $sub['name'], $sub['type'], $words, $links, (bool) $conf['apply'] );
		delete_transient( 'dze_auto_survey' ); // the shop is about to change.
		delete_transient( 'dze_pl_census' );
		return [ 'queued' => 1, 'task' => $id, 'reason' => 'queued' ];
	}

	/**
	 * One page of the mesh, sent off with the links the graph chose for it.
	 *
	 * @param array $row The shortlist row when there is one: it holds the
	 *                   addresses. Without it the plan is asked again for this
	 *                   page — never guessed at, and never the whole pool.
	 */
	private static function run_mesh( string $id, int $oid, array $row, array $conf ): array {
		$no = static fn( string $why ): array => [ 'queued' => 0, 'task' => $id, 'reason' => $why ];
		if ( ! class_exists( 'DZE_Mesh' ) || ! class_exists( 'DZE_Queue' ) ) {
			return $no( 'gone' );
		}
		$kind  = (string) ( $row['kind'] ?? '' );
		$urls  = array_values( array_filter( (array) ( $row['urls'] ?? [] ) ) );
		$phase = (string) ( $row['phase'] ?? '' );
		// Asked for a page and handed nothing about it — a press from another
		// screen, a row rebuilt from an id — the two phases are looked at in
		// the same order the day's work uses them.
		if ( ! $urls && 'out' !== $phase ) {
			foreach ( DZE_Mesh::plan( 20 ) as $one ) {
				if ( (int) $one['id'] === $oid ) {
					$kind  = (string) $one['kind'];
					$urls  = (array) $one['urls'];
					$phase = 'in';
					break;
				}
			}
		}
		if ( ! $urls && 'out' !== $phase ) {
			foreach ( DZE_Mesh::thin( 200 ) as $one ) {
				if ( (int) $one['id'] === $oid ) {
					$kind  = (string) $one['kind'];
					$phase = 'out';
					break;
				}
			}
		}
		// PHASE TWO CARRIES NO ADDRESSES ON PURPOSE: the page's own pool
		// decides where its links go. An empty list is the answer, not a
		// failure — but a phase-one row that lost its addresses IS one.
		if ( ! $urls && 'out' !== $phase ) {
			return $no( 'none' );
		}
		$sub = self::subject( 'product_cat' === $kind ? 'category' : 'post', $oid );
		if ( ! $sub ) {
			return $no( 'gone' );
		}
		$words = str_word_count( wp_strip_all_tags( $sub['html'] ) );
		$links = (int) preg_match_all( '/<a\s[^>]*href=/i', $sub['html'] );
		// The job is the pass that already writes this kind of page. There is
		// no third linking engine, and there must never be one.
		$job = 'product_cat' === $kind ? 'cat_links' : 'post_links';
		// LA RAISON VOYAGE AVEC LE TRAVAIL. La liste de départ sait pourquoi
		// elle a choisi cette page — « liera vers 2 pages que rien ne pointe »
		// — et cette phrase se perdait au moment de poser le travail. Elle est
		// la seule réponse à « je ne sais pas ce qui devait être fait ».
		$sac = $urls ? [ 'urls' => $urls ] : [];
		if ( '' !== (string) ( $row['why'] ?? '' ) ) {
			$sac['why'] = (string) $row['why'];
		}
		if ( ! DZE_Queue::add( $job, [ $oid ], (bool) $conf['apply'], $sac ) ) {
			return $no( 'busy' );
		}
		// Marked only once the work is really under way: see run().
		if ( $conf['apply'] ) {
			self::keep_copy( $oid, $sub['type'], $sub['html'] );
		}
		self::mark( $oid, $id, $sub['type'], $words, $links );
		self::note( $id, $oid, (string) $sub['name'], $sub['type'], $words, $links, (bool) $conf['apply'] );
		delete_transient( 'dze_auto_survey' );
		delete_transient( 'dze_pl_census' );
		DZE_Mesh::forget_thin();
		return [ 'queued' => 1, 'task' => $id, 'reason' => 'queued' ];
	}

	/**
	 * One object, translated into every language it is short of.
	 *
	 * There is no queue job and there must never be one: a translation is an
	 * object times N languages times M fields, each with its own yes or no,
	 * and the store that holds it is the source object's own — the same one
	 * the Translations screen reads. Bending the writing queue to carry that
	 * is how two screens start disagreeing about what is waiting.
	 *
	 * @param array $row The shortlist row when there is one: it carries the
	 *                   object's reference and the languages it owes. Pressed
	 *                   by hand there is none, and the same question is asked
	 *                   again rather than guessed at.
	 */
	private static function run_translate( string $id, int $oid, array $row, array $conf ): array {
		$no = static fn( string $why ): array => [ 'queued' => 0, 'task' => $id, 'reason' => $why ];
		if ( ! class_exists( 'DZE_Translate' ) || ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			return $no( 'gone' );
		}
		$o = '' !== (string) ( $row['ref'] ?? '' ) ? DZE_Translate::from_ref( (string) $row['ref'] ) : [];
		if ( ! $o ) {
			// No row: the object is rebuilt from its id, and the pass asks the
			// same question the day's work asks rather than translating
			// whatever happens to be there.
			foreach ( self::translate_shortlist( $id, 20 ) as $one ) {
				if ( (int) $one['tid'] === $oid ) {
					$o   = DZE_Translate::from_ref( (string) $one['ref'] );
					$row = $one;
					break;
				}
			}
		}
		if ( ! $o ) {
			return $no( 'gone' );
		}
		$langs = array_values( array_filter( array_map( 'strval', (array) ( $row['langs'] ?? [] ) ) ) );
		if ( ! $langs ) {
			$langs = array_keys( DZE_Translate::obj_targets( $o ) );
		}
		if ( ! $langs ) {
			return $no( 'none' );
		}
		// Nobody is waiting on cron, and a product with fifteen fields in five
		// languages is five model calls.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- cron and a deliberate press.
		}
		try {
			$res = DZE_Translate::produce( $o, $langs );
		} catch ( \Throwable $e ) {
			return $no( 'failed' );
		}
		// WHAT CAME BACK DECIDES WHAT THIS WAS. Nothing written and nothing
		// skipped is a failure; nothing written because every language was
		// already up to date is a real answer and not one.
		if ( ! $res['langs'] ) {
			if ( $res['skipped'] ) {
				// WPML had marked it and our register says not one word moved:
				// the mark is closed, for nothing, and that is the whole value
				// of this module. It counts as work done on the object, so it
				// is marked and not looked at again tomorrow.
				self::mark( $oid, $id, 'term' === (string) $o['kind'] ? 'term' : 'post', 0, 0 );
				self::note( $id, $oid, DZE_Translate::obj_label( $o ), 'term' === (string) $o['kind'] ? 'term' : 'post', 0, 0, true );
				return [ 'queued' => 1, 'task' => $id, 'reason' => 'queued' ];
			}
			return $no( 'failed' );
		}
		// SAVED WITHOUT REVIEW IS A DECISION THE SHOP TOOK, and it is taken
		// here rather than left for somebody to find waiting.
		if ( ! empty( $conf['apply'] ) ) {
			DZE_Translate::accept( $o, $res['langs'] );
		}
		$type = 'term' === (string) $o['kind'] ? 'term' : 'post';
		self::mark( $oid, $id, $type, 0, count( $res['langs'] ) );
		self::note( $id, $oid, DZE_Translate::obj_label( $o ), $type, 0, count( $res['langs'] ), (bool) $conf['apply'] );
		return [ 'queued' => 1, 'task' => $id, 'reason' => 'queued' ];
	}

	/** Files one pass in the log, and keeps the undo of the last ones only. */
	private static function note( string $id, int $oid, string $name, string $type, int $words, int $links, bool $applied ): void {
		$s     = self::state();
		$today = current_time( 'Y-m-d' );
		$fresh = (string) ( $s['day'] ?? '' ) === $today;
		$log   = (array) ( $s['log'] ?? [] );
		array_unshift( $log, [
			'task'  => $id,
			'tid'   => $oid,
			'what'  => $type,
			'name'  => $name,
			'at'    => time(),
			'words' => $words,
			'links' => $links,
			'auto'  => $applied ? 1 : 0,
		] );
		$kept = array_slice( $log, 0, self::KEEP );
		foreach ( array_slice( $log, self::KEEP ) as $old ) {
			// Out of the log, out of the database: a copy kept for an undo
			// nobody can reach any more is dead weight. Unless the same object
			// came back higher up — that copy is the live one.
			$still = false;
			foreach ( $kept as $row ) {
				if ( (int) $row['tid'] === (int) $old['tid'] && (string) ( $row['what'] ?? 'term' ) === (string) ( $old['what'] ?? 'term' ) ) {
					$still = true;
					break;
				}
			}
			if ( ! $still && (int) $old['tid'] ) {
				self::drop_copy( (int) $old['tid'], (string) ( $old['what'] ?? 'term' ) );
			}
		}
		$count = $fresh ? (array) ( $s['count'] ?? [] ) : [];
		$count[ $id ] = (int) ( $count[ $id ] ?? 0 ) + 1;
		// ET LA RATION DES POSTS, COMPTEE A PART. Une tache qui prend tout en
		// charge n a pas de plafond ; sa moitie « posts » en garde un, et il
		// lui faut donc son propre compteur. Un terme n en consomme pas.
		if ( self::takes_all( $id ) && 'term' !== $type ) {
			$count[ $id . ':post' ] = (int) ( $count[ $id . ':post' ] ?? 0 ) + 1;
		}
		$lastm = (array) ( $s['last'] ?? [] );
		$lastm[ $id ] = time();
		self::save_state( [ 'day' => $today, 'count' => $count, 'last' => $lastm, 'log' => $kept ] );
	}

	/** Puts back the text an automatic pass replaced. */
	public static function undo( int $oid, string $type = 'term' ): bool {
		$prev = self::copy_of( $oid, $type );
		if ( '' === trim( $prev ) ) {
			return false;
		}
		if ( 'post' === $type ) {
			$done = wp_update_post( [ 'ID' => $oid, 'post_content' => wp_kses_post( $prev ) ], true );
			if ( is_wp_error( $done ) ) {
				return false;
			}
		} else {
			// COLONNE PAR COLONNE, JAMAIS wp_update_term(). Celui-ci relit le
			// terme par get_term(), que WPML filtre sur la langue courante : il
			// rend alors l AUTRE terme du groupe, et le merge reecrit le nom et
			// le slug de l original par-dessus la traduction. Quarante-huit
			// termes ont ete abimes ainsi, et le bouton d annulation etait le
			// dernier chemin a passer encore par la.
			if ( ! class_exists( 'DZE_Queue' ) || ! DZE_Queue::write_description( $oid, wp_kses_post( $prev ) ) ) {
				return false;
			}
		}
		self::drop_copy( $oid, $type );
		$s   = self::state();
		$log = (array) ( $s['log'] ?? [] );
		foreach ( $log as $i => $row ) {
			if ( (int) ( $row['tid'] ?? 0 ) === $oid && (string) ( $row['what'] ?? 'term' ) === $type ) {
				$log[ $i ]['undone'] = 1;
			}
		}
		$s['log'] = $log;
		self::save_state( $s );
		delete_transient( 'dze_auto_survey' );
		delete_transient( 'dze_pl_census' );
		return true;
	}

	// =========================================================================
	// The Automation tab
	// =========================================================================

	/**
	 * WHAT EACH TASK IS DOING, IN SYMBOLS, ON ITS OWN LINE.
	 *
	 * "C'est très brutal, vulgaire, avec énormément de texte de partout. Je
	 * suis perdu et désorienté quand je vois ça… Si un module est bien fait,
	 * en général, il n'est pas nécessaire d'ajouter du texte partout. La
	 * simple présence d'un bouton doit parler d'elle-même."
	 *
	 * Four figures, each one a question somebody actually asks: is it on and
	 * how often, what is waiting for my yes or no, what has gone through, and
	 * when does it look again. They are read folded, so three tasks are three
	 * lines rather than three screens — and each carries its own word on hover
	 * rather than a paragraph underneath.
	 */
	public static function chips_html( string $id ): string {
		$conf  = self::conf( $id );
		$task  = self::task( $id );
		$out   = '';
		// A FIGURE NOBODY CAN OPEN IS A FIGURE NOBODY BELIEVES. That was
		// written here for the "unlinked" chip and applied to that one alone,
		// so "3 to review" and "14 written" looked exactly like buttons and
		// were plain text — "les boutons ne sont pas fonctionnels, je ne peux
		// pas voir quelles pages ont été retravaillées". A chip with a
		// destination is a link; one without stays a span, and looks like one.
		$chip  = static function ( string $class, string $icon, string $text, string $tip, string $url = '' ): string {
			$body = '<span class="dashicons dashicons-' . esc_attr( $icon ) . '"></span>' . esc_html( $text );
			return '' !== $url
				? '<a class="dze-auto-chip is-link ' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '" title="' . esc_attr( $tip ) . '">' . $body . '</a>'
				: '<span class="dze-auto-chip ' . esc_attr( $class ) . '" title="' . esc_attr( $tip ) . '">' . $body . '</span>';
		};
		// ON, and at what rhythm. Off says only that, because a rhythm nothing
		// runs at is a figure about nothing.
		if ( ! $conf['on'] ) {
			$out .= $chip( 'is-off', 'marker', __( 'Off', 'dazont-ecom' ), __( 'Nothing runs on its own', 'dazont-ecom' ) );
		} else {
			$out .= $chip(
				'is-on',
				'controls-play',
				// LA PASTILLE DIT L ALLURE REELLE. « Incoherence. Affiche 10 a day
				// dans la pastille » au-dessus d un reglage sans limite : elle lisait
				// le nombre sans regarder s il s applique.
				'month' === $conf['cadence']
					? __( 'Monthly', 'dazont-ecom' )
					: ( self::takes_all( $id )
						? __( 'Always on', 'dazont-ecom' )
						/* translators: %s: how many items a day */
						: sprintf( __( '%s a day', 'dazont-ecom' ), number_format_i18n( $conf['per_day'] ) ) ),
				( 'month' !== $conf['cadence'] && self::takes_all( $id ) )
					? __( 'Running on its own, with no daily limit', 'dazont-ecom' )
					: __( 'Running on its own, at this rhythm', 'dazont-ecom' )
			);
		}
		// PAGES NOTHING POINTS AT — and only this task mends them. "Il est
		// impératif d'inscrire l'info quelque part. Que pour lier les pages
		// orphelines de liens entrant, seul le module d'automatisation peut
		// faire le travail." The figure lives on the task that does that work,
		// so the count and the thing that acts on it are read in one glance.
		// NULL is "the site has not been read yet" and says nothing rather
		// than printing a nought nobody counted.
		if ( 'mesh' === (string) ( $task['scope'] ?? '' ) && class_exists( 'DZE_Mesh' ) ) {
			$orph = DZE_Mesh::orphan_count();
			if ( null !== $orph && $orph > 0 ) {
				// A FIGURE NOBODY CAN OPEN IS A FIGURE NOBODY BELIEVES — "WOW
				// c'est énorme, littéralement impossible… il faut voir ce qui
				// ne va pas". The chip is the way into the list it counts.
				$out .= '<button type="button" class="dze-auto-chip is-orphan dze-auto-orph" title="'
					. esc_attr__( 'Pages no other page links to in its text — menus and breadcrumbs do not count. Press to see them.', 'dazont-ecom' ) . '">'
					. '<span class="dashicons dashicons-editor-unlink"></span>'
					/* translators: %s: how many pages no text links to */
					. esc_html( sprintf( __( '%s unlinked', 'dazont-ecom' ), number_format_i18n( $orph ) ) ) . '</button>';
			}
		}
		// WAITING FOR A PERSON — the figure this screen exists to surface.
		$left = self::waiting_for( $id );
		if ( $left['n'] > 0 ) {
			// A NUMBER WITH NO UNIT IS A NUMBER NOBODY CAN READ. "5 · 3 · 14" on
			// one line, the words only on hover, is three figures to decode.
			/* translators: %s: how many pieces of work are waiting */
			$jobs  = array_values( array_filter( array_map( 'strval', (array) ( $task['jobs'] ?? [] ) ) ) );
			$where = ( $jobs && class_exists( 'DZE_Queue' ) )
				? DZE_Queue::review_url( $jobs )
				: '';
			$out .= $chip( 'is-wait', 'visibility', sprintf( __( '%s to review', 'dazont-ecom' ), number_format_i18n( $left['n'] ) ), __( 'Waiting for your yes or no — press to read them', 'dazont-ecom' ), $where );
		}
		// AND WHAT WENT THROUGH. A task that has never written anything says
		// nothing rather than a nought, which reads as a task that failed.
		$done = self::done_count( $id );
		if ( $done > 0 ) {
			/* translators: %s: how many pieces of work were accepted and written */
			$out .= $chip( 'is-done', 'yes', sprintf( __( '%s written', 'dazont-ecom' ), number_format_i18n( $done ) ), __( 'Written to the shop — press to see which pages', 'dazont-ecom' ), self::past_url( $id ) );
		}
		// WHEN IT LOOKS AGAIN, only while it is on: a countdown on a switched
		// off task is a promise nobody made.
		if ( $conf['on'] ) {
			$next = self::next_said( $id );
			if ( '' !== $next ) {
				$out .= $chip( 'is-next', 'clock', $next, __( 'When it next looks for work', 'dazont-ecom' ) );
			}
		}
		if ( ! self::task_ready( $id ) ) {
			$out .= $chip( 'is-blocked', 'warning', __( 'Needs a module', 'dazont-ecom' ), __( 'Its own module, or the writing queue, is switched off', 'dazont-ecom' ) );
		}
		return '<span class="dze-auto-chips" data-task="' . esc_attr( $id ) . '">' . $out . '</span>';
	}

	/** How many of this task's jobs were accepted and written. */
	public static function done_count( string $id ): int {
		$task = self::task( $id );
		// A task with no queue rows has no applied rows to count. The calendar
		// writes nothing to the shop on its own, and a translation is recorded
		// in the translation module's own register — a figure invented here
		// from a capped log would be a total that lies, and a chip is silent
		// when it has nothing it can say.
		if ( ! $task || empty( $task['jobs'] ) ) {
			return 0;
		}
		if ( ! class_exists( 'DZE_Queue' ) || ! DZE_Modules::enabled( 'queue' ) ) {
			return 0;
		}
		return DZE_Queue::applied_count_for( (array) ( $task['jobs'] ?? [] ) );
	}

	/** When it next looks, in the fewest words that answer it. */
	public static function next_said( string $id ): string {
		$conf = self::conf( $id );
		if ( 'month' === $conf['cadence'] ) {
			$last = self::last_run( $id );
			if ( $last && $last + self::gap( $id ) > time() ) {
				return human_time_diff( time(), $last + self::gap( $id ) );
			}
			return __( 'Due now', 'dazont-ecom' );
		}
		$next = wp_next_scheduled( self::HOOK );
		return $next ? human_time_diff( time(), (int) $next ) : '';
	}

	/**
	 * ONE TASK'S SWITCH, WHEREVER THE WORK IS.
	 *
	 * "Dans automations en fait il n'y aura rien, c'était peut-être maladroit
	 * de faire ce module. C'est plutôt une façon de faire pour automatiser
	 * différents modules." Exactly so: running by itself is a PROPERTY of a
	 * piece of work, not a destination of its own. A setting that lives three
	 * menus away from the screen it acts on is a setting nobody finds, and a
	 * menu holding nothing but other modules' switches is a menu that exists
	 * for the code's convenience rather than the shop's.
	 *
	 * So this block is printed by whoever owns that work. One block, many
	 * hosts, never two forms that have to be kept in step.
	 */
	public static function panel( string $id, bool $open = false ): void {
		$tasks = self::tasks();
		if ( ! isset( $tasks[ $id ] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$task = $tasks[ $id ];
		$conf = self::conf( $id );
		$name = self::OPT . '[tasks][' . $id . ']';
		self::panel_body( $id, $task, $conf, $name, $open );
	}

	/**
	 * The panels a screen is responsible for, in the form that saves them.
	 *
	 * Everything a host needs in one call: the styles, WordPress's own options
	 * form, the blocks, the button, and the scripts the blocks' buttons need.
	 * A host that had to remember four calls is a host where one of them is
	 * missing on one screen.
	 *
	 * @param array<int,string> $ids
	 */
	public static function panel_form( array $ids, string $title = '' ): void {
		// A DISABLED MODULE LEAVES ZERO TRACE. `class_exists()` is not a module
		// check — the class file is always there — so this panel was printed on
		// four screens whatever the state of the module, with a Save and a Run
		// that do nothing once its hooks are gone. CLAUDE.md: "every CROSS-module
		// surface must be gated with DZE_Modules::enabled( $id )".
		if ( class_exists( 'DZE_Modules' ) && ! DZE_Modules::enabled( 'automation' ) ) {
			return;
		}
		$tasks = self::tasks();
		$ids   = array_values( array_filter( $ids, static fn( $one ): bool => isset( $tasks[ (string) $one ] ) ) );
		if ( ! $ids || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( class_exists( 'DZE_Assets' ) ) {
			DZE_Assets::admin_css();
		}
		// SON PROPRE NOM. « dze-auto-bar » etait deja pris par la barre de
		// PROGRESSION d'une passe, qui vit a l'interieur de ce panneau. Ma
		// regle arrivant plus bas dans la feuille l'emportait, et la barre de
		// progression heritait du cadre, du fond degrade et du padding du
		// panneau. Deux choses differentes ne partagent pas un nom.
		// DISCREET, AND AT THE TOP. "Run it by itself devrait être en haut de
		// page… Tu peux le rendre discret, mais en haut de page obligatoirement,
		// partout là où il existe." So it is a thin bar, not a panel: the
		// switch is the cherry, not the plate — it must be within reach without
		// taking the room the work needs.
		echo '<div class="dze-admin dze-auto dze-auto-strip">';
		if ( '' !== $title ) {
			echo '<h2 class="dze-auto-h2">' . esc_html( $title ) . '</h2>';
		}
		// AND IT SAYS WHAT IT HAS LEFT FOR YOU, with the way there. A switch
		// that runs every night and never mentions the pile it is making is a
		// switch nobody can follow: "Run it by itself ne propose à aucun moment
		// une redirection vers To review… et pas de comptage de ce qui est en
		// attente."
		$kinds = [];
		foreach ( $ids as $one ) {
			foreach ( (array) ( $tasks[ (string) $one ]['jobs'] ?? [] ) as $k ) {
				$kinds[] = (string) $k;
			}
		}
		$waiting = ( $kinds && class_exists( 'DZE_Queue' ) )
			? (int) ( DZE_Queue::counts_for( $kinds )['review'] ?? 0 )
			: 0;
		if ( $waiting && class_exists( 'DZE_Screens' ) ) {
			printf(
				'<p class="dze-auto-waitline">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html( sprintf(
					/* translators: %s: how many results */
					_n( '%s result is waiting for your yes or no.', '%s results are waiting for your yes or no.', $waiting, 'dazont-ecom' ),
					number_format_i18n( $waiting )
				) ),
				esc_url( DZE_Queue::review_url( array_unique( $kinds ) ) ),
				esc_html__( 'Read them →', 'dazont-ecom' )
			);
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( 'dze_auto_options' );
		echo '<input type="hidden" name="' . esc_attr( self::OPT ) . '[form]" value="1" />';
		// EVERY TASK TRAVELS, NOT ONLY THE ONES ON SCREEN. `sanitize()` reads
		// the whole list and writes what it is given: a form carrying one task
		// would save that one and blank the others. So the tasks this screen
		// does not show ride along as hidden fields, exactly as they stand.
		foreach ( $tasks as $tid => $t ) {
			if ( in_array( (string) $tid, $ids, true ) ) {
				continue;
			}
			$other = self::conf( (string) $tid );
			$base  = self::OPT . '[tasks][' . $tid . ']';
			foreach ( [ 'on', 'per_day', 'apply', 'kw_only', 'pace' ] as $key ) {
				if ( ! isset( $other[ $key ] ) || ! $other[ $key ] ) {
					continue; // an unticked box sends nothing, here as anywhere.
				}
				printf(
					'<input type="hidden" name="%1$s[%2$s]" value="%3$s" />',
					esc_attr( $base ),
					esc_attr( $key ),
					esc_attr( (string) ( true === $other[ $key ] ? 1 : $other[ $key ] ) )
				);
			}
		}
		// A host screen shows the one task it is about: open. Several, and
		// they fold, because a wall of open blocks is not a screen.
		$open = 1 === count( $ids );
		foreach ( $ids as $one ) {
			self::panel( (string) $one, $open );
		}
		submit_button( __( 'Save Changes', 'dazont-ecom' ) );
		echo '</form>';
		echo '</div>';
		self::render_assets();
		$more = [];
		foreach ( $ids as $one ) {
			$more[ (string) $one ] = [
				'title' => (string) ( $tasks[ (string) $one ]['label'] ?? '' ),
				'text'  => (string) ( $tasks[ (string) $one ]['more'] ?? $tasks[ (string) $one ]['what'] ?? '' ),
			];
		}
		if ( class_exists( 'DZE_Hub' ) ) {
			DZE_Hub::more_assets( $more );
		}
	}

	/**
	 * @param array<string,mixed> $task
	 * @param array<string,mixed> $conf
	 */
	private static function panel_body( string $id, array $task, array $conf, string $name, bool $open = false ): void {
		$ready = self::task_ready( $id );
		?>
				<?php // OPEN WHERE IT IS THE ONLY ONE. Folded, on the screen of the
					// work it pilots, the switch the shop asked to have within
					// reach is a switch behind a fold — which is the click it was
					// complaining about. Folded still on the page that lists all
					// four side by side, where four open blocks is a wall. ?>
				<details class="dze-set dze-auto-task"<?php echo $open ? ' open' : ''; ?>>
					<summary>
						<span class="dze-auto-name"><?php echo esc_html( (string) $task['label'] ); ?><?php
							// HOW it works is for whoever wants it, one press
							// away — not a paragraph everybody has to read.
							echo wp_kses_post( DZE_Hub::more_button( $id ) );
						?></span>
						<?php echo wp_kses_post( self::chips_html( $id ) ); ?>
					</summary>
					<p class="description"><?php echo esc_html( (string) $task['what'] ); ?></p>
					<p class="dze-auto-controls">
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[on]" value="1" <?php checked( $conf['on'] ); ?> <?php disabled( ! $ready ); ?> />
							<?php esc_html_e( 'Run it', 'dazont-ecom' ); ?>
						</label>
						<?php if ( 'month' !== $conf['cadence'] ) : ?>
							<?php
							// DEUX ALLURES, ET LA DIFFERENCE EST CE QUE LE TRAVAIL EST.
							// « Le x par jour c'est bien pour de la publication de
							// contenu » — mais pas pour l'entretien, qui tenu a trois
							// pages par jour laisse le site en retard pour toujours.
							?>
							<?php
							// ET LES DEUX ALLURES SE LISENT SANS SURVOL. « Run it
							// everything it can / a few a day — WTF IS THIS ? Ça veut
							// dire quoi a few a day, incompréhension totale. » Les
							// deux etiquettes nommaient une cadence sans jamais dire
							// combien ni a quel rythme, et la seule explication etait
							// dans une infobulle que personne n ouvre. Elles disent
							// maintenant ce qu elles font, et la phrase sous la ligne
							// dit ce qui va se passer avec le reglage choisi.
							?>
							<?php
							// « "SANS LIMITE" MAIS DEMANDE QUELLE LIMITE DE POSTS. STUPIDE. »
							//
							// Les deux controles se contredisaient sur la meme ligne. Le
							// nombre n est pas mort en allure « tout ce qu il peut » : il
							// rationne les produits et les articles de la TRADUCTION, et
							// eux seuls — jamais les attributs ni les categories, parce
							// qu un attribut non traduit casse une page. Partout ailleurs,
							// dans cette allure, il ne fait rien du tout.
							//
							// Donc l option ne promet plus « sans limite » la ou une limite
							// existe, et le nombre n est montre que la ou il mord.
							$dze_all = 'all' === (string) $conf['pace'];
							$dze_tr  = 'translate' === (string) $conf['scope'];
							$dze_hid = ' style="display:none;"';
							?>
							<label>
								<select name="<?php echo esc_attr( $name ); ?>[pace]" class="dze-auto-pace" data-ration="<?php echo $dze_tr ? '1' : '0'; ?>">
									<option value="all" <?php selected( 'all', (string) $conf['pace'] ); ?>><?php
										// « Son nom est stupide. Ca devrait etre autre chose comme :
										// Always on. Et l autre daily limit. » Les deux etiquettes
										// decrivaient un fonctionnement ; elles nomment un ETAT.
										echo $dze_tr
											? esc_html__( 'Always on (posts capped below)', 'dazont-ecom' )
											: esc_html__( 'Always on', 'dazont-ecom' );
									?></option>
									<option value="daily" <?php selected( 'daily', (string) $conf['pace'] ); ?>><?php esc_html_e( 'Daily limit', 'dazont-ecom' ); ?></option>
								</select>
							</label>
							<?php // LES DEUX ETATS SONT ECRITS, UN SEUL EST MONTRE : le reglage
								// se lit au moment ou on le choisit, pas apres l avoir enregistre. ?>
							<label class="dze-auto-ration"<?php echo ( $dze_all && ! $dze_tr ) ? $dze_hid : ''; ?>>
								<input type="number" name="<?php echo esc_attr( $name ); ?>[per_day]" class="small-text" min="1" max="20" value="<?php echo (int) $conf['per_day']; ?>" />
								<span class="dze-auto-rat-all"<?php echo $dze_all ? '' : $dze_hid; ?>><?php esc_html_e( 'products or articles a day', 'dazont-ecom' ); ?></span>
								<span class="dze-auto-rat-day"<?php echo $dze_all ? $dze_hid : ''; ?>><?php esc_html_e( 'a day', 'dazont-ecom' ); ?></span>
							</label>
							<p class="description" style="margin:4px 0 0;">
								<span class="dze-auto-said-all"<?php echo $dze_all ? '' : $dze_hid; ?>><?php
									esc_html_e( 'It comes back every ten minutes and keeps going until there is nothing left to do. Only an empty list or the monthly AI budget stops it.', 'dazont-ecom' );
									if ( $dze_tr ) {
										echo ' ';
										esc_html_e( 'The number beside it caps the products and articles only — attributes and categories are never held back, because an untranslated attribute breaks a page.', 'dazont-ecom' );
									}
								?></span>
								<?php // LE NOMBRE N EST PLUS RECOPIE DANS LA PHRASE : il est dans
									// la case a cote, et une phrase qui le repete ment des qu on
									// le change sans recharger. ?>
								<span class="dze-auto-said-day"<?php echo $dze_all ? $dze_hid : ''; ?>><?php esc_html_e( 'It does the number beside it each day, spread across the day, then stops until tomorrow. Use this for work that is publishing rather than maintenance.', 'dazont-ecom' ); ?></span>
							</p>
						<?php endif; ?>
						<?php if ( 'shop' !== $conf['scope'] ) : ?>
							<?php // The consequence is on the hover, not in a paragraph under it. ?>
							<label title="<?php esc_attr_e( 'Ticked, what it writes goes live without being read first. Left as it is, it waits for your yes or no.', 'dazont-ecom' ); ?>">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[apply]" value="1" <?php checked( $conf['apply'] ); ?> />
								<?php esc_html_e( 'Save without review', 'dazont-ecom' ); ?>
							</label>
						<?php endif; ?>
						<?php if ( ! empty( $task['kw'] ) ) : ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[kw_only]" value="1" <?php checked( $conf['kw_only'] ); ?> />
								<?php esc_html_e( 'Only with a SEMrush file', 'dazont-ecom' ); ?>
							</label>
						<?php endif; ?>
						<button type="button" class="button dze-auto-run" data-task="<?php echo esc_attr( $id ); ?>" <?php disabled( ! $ready ); ?>><?php esc_html_e( 'Run one now', 'dazont-ecom' ); ?></button>
						<?php if ( 'mesh' === $conf['scope'] ) : ?>
							<?php // THE CATCH-UP, ONCE: every page that is short of its own links,
								// so the daily pass afterwards is maintenance and not a backlog. ?>
							<button type="button" class="button dze-auto-catchup" data-task="<?php echo esc_attr( $id ); ?>"
								title="<?php esc_attr_e( 'Fills the outgoing links of every page under its own quota, a few hundred per press. The pages no text links to are left to the daily pass. Nothing is saved to the shop until you accept it.', 'dazont-ecom' ); ?>"
								<?php disabled( ! $ready ); ?>><?php esc_html_e( 'Link the whole site', 'dazont-ecom' ); ?></button>
						<?php endif; ?>
						<span class="dze-auto-msg"></span>
					</p>
					<?php if ( ! $ready ) : ?>
						<p class="description dze-auto-blocked">
							<?php esc_html_e( 'Switch its module and the Writing queue back on: the work is theirs, this only decides which page gets it, and when.', 'dazont-ecom' ); ?>
						</p>
					<?php endif; ?>
					<div class="dze-auto-state" data-task="<?php echo esc_attr( $id ); ?>"><?php self::render_state( $id ); ?></div>
					<?php // THE PROGRESS BELONGS TO THE PRESS THAT STARTED IT. ?>
					<div class="dze-auto-live" data-task="<?php echo esc_attr( $id ); ?>"><?php self::render_run( $id ); ?></div>
				</details>
		<?php
	}
	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// A BODY THAT MOVES TAKES ITS ASSETS WITH IT. The chips, the folds and
		// the to-do rows are all drawn with these styles, and nothing else on
		// this page asks for them: enqueued from a page hook somewhere else,
		// the one forgotten is always the screen that comes out unstyled.
		DZE_Assets::admin_css();
		self::$needs_review = false;
		?>
		<div class="dze-admin dze-auto">
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_auto_options' ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::OPT ); ?>[form]" value="1" />
			<?php foreach ( array_keys( self::tasks() ) as $id ) : ?>
				<?php self::panel( (string) $id ); ?>
			<?php endforeach; ?>
			<?php submit_button( __( 'Save Changes', 'dazont-ecom' ) ); ?>
		</form>

		<?php
		// WHAT IS WAITING FOR YOU IS THE WORK, not a setting. It used to be
		// folded away inside each task's own controls — "plutôt que de les
		// lister dans les paramètres de l'automatisme" — so seeing what three
		// tasks had left meant opening three blocks. It is ONE list, open, on
		// the page: every list of things waiting for a decision is one list.
		?>
		<?php
		// THE SCREEN THAT STARTS THE WORK SHOWS THE WORK. "J'ai lancé run once
		// et je suis perdu. Je fais quoi ensuite ? Rien de nouveau n'apparaît
		// dans To review même après actualisation. J'estime que quelque chose
		// est cassé." A press answered "Queued" and the screen went silent:
		// nothing said how many were in flight, nothing moved them, and
		// nothing said when they were done.
		?>
		<h2 class="dze-auto-h2"><?php esc_html_e( 'To review', 'dazont-ecom' ); ?></h2>
		<div id="dze-auto-waiting"><?php self::render_waiting(); ?></div>
		<?php
		// The popup those three controls open, printed by the module that owns
		// it — ALWAYS, on this screen.
		//
		// "Ici c'est cassé, le bouton review ne fonctionne pas… seulement après
		// rafraîchissement." It used to be printed only where something was
		// already waiting when the page was drawn, to save the weight of an
		// editor loaded for nobody. But this is the screen where the work is
		// STARTED: an empty list is exactly the state a run begins from, and a
		// minute later the block redraws itself with rows carrying a Review
		// button, on a page holding neither the popup nor the handler that
		// opens it. A screen that can COME to hold something waiting carries
		// what that thing opens — the saving was real and it was paid for by
		// the one press that needed it.
		if ( class_exists( 'DZE_Queue' ) && DZE_Modules::enabled( 'queue' ) ) {
			DZE_Queue::review_assets();
		}
		// The panel of detail behind every "?" on this screen.
		$more = [];
		foreach ( self::tasks() as $tid => $t ) {
			$more[ $tid ] = [ 'title' => (string) $t['label'], 'text' => (string) ( $t['more'] ?? $t['what'] ) ];
		}
		DZE_Hub::more_assets( $more );
		?>
		<?php // WHAT THE FIGURE COUNTS, one press away — the plugin's own popup shell. ?>
		<div class="dze-cx-modal" id="dze-auto-orphmodal"><div class="dze-cx-dialog" style="width:min(860px,94vw);">
			<div class="dze-cx-head"><h2><?php esc_html_e( 'Not linked from any page\'s text', 'dazont-ecom' ); ?></h2>
				<button type="button" class="button dze-hub-close" style="margin-left:auto;"><?php esc_html_e( 'Close', 'dazont-ecom' ); ?></button></div>
			<div class="dze-cx-body" id="dze-auto-orphbody"></div>
		</div></div>
		</div>
		<?php
	}

	/**
	 * How many of the pages no text links to one press may show.
	 *
	 * The popup is read, not paged: past a couple of hundred lines nobody
	 * reads further, and the figure above it already says how many there are.
	 */
	private const ORPH_MAX = 200;

	/**
	 * THE PAGES BEHIND THE FIGURE, so it can be believed.
	 *
	 * "Il faut la possibilité de voir ces pages dans une liste." A count that
	 * cannot be opened is a count somebody argues with; opened, it answers
	 * every question at once — which pages, of what kind, and which of them a
	 * page builder owns, since those are the ones whose text is read out of
	 * the builder's own data rather than out of the post.
	 */
	public static function render_orphans(): void {
		if ( ! class_exists( 'DZE_Mesh' ) || ! DZE_Modules::enabled( 'mesh' ) ) {
			echo '<p class="description">' . esc_html__( 'The link graph is switched off.', 'dazont-ecom' ) . '</p>';
			return;
		}
		$all  = DZE_Mesh::orphan_count();
		$rows = DZE_Mesh::orphans( self::ORPH_MAX );
		if ( null === $all ) {
			echo '<p class="description">' . esc_html__( 'The site has not been read yet.', 'dazont-ecom' ) . '</p>';
			return;
		}
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'Every page is linked from the text of another one.', 'dazont-ecom' ) . '</p>';
			return;
		}
		// ONE sentence, because this is the whole of what the figure means and
		// it is the reason a large one is not a broken site.
		echo '<p class="description">' . esc_html__( 'A link here is one written in a text — a description, an article, a page. A menu, a breadcrumb or a shop archive is not counted.', 'dazont-ecom' ) . '</p>';
		self::orph_table( $rows );
		$rest = $all - count( $rows );
		if ( $rest > 0 ) {
			echo '<p class="description">' . esc_html( sprintf(
				/* translators: %s: how many more pages are not listed */
				_n( '%s more, not listed here.', '%s more, not listed here.', $rest, 'dazont-ecom' ),
				number_format_i18n( $rest )
			) ) . '</p>';
		}
		self::said_pages();
	}

	/**
	 * ONE TABLE, BOTH LISTS.
	 *
	 * The pages nothing points at and the pages the shop has set aside are the
	 * same rows read two ways, so they are drawn by one function with one
	 * column order — two tables written twice go a column out of step on the
	 * next edit, and then every row prints its values under the wrong titles.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 */
	private static function orph_table( array $rows ): void {
		echo '<table class="wp-list-table widefat fixed striped dze-auto-orphlist"><thead><tr>';
		echo '<th>' . esc_html__( 'Page', 'dazont-ecom' ) . '</th>';
		echo wp_kses_post( DZE_Hub::id_th() );
		echo '<th class="dze-auto-kindth">' . esc_html__( 'Kind', 'dazont-ecom' ) . '</th>';
		echo '<th class="dze-auto-outth">' . esc_html__( 'Links out', 'dazont-ecom' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$name = esc_html( (string) $row['title'] );
			$url  = (string) $row['url'];
			echo '<tr><td><strong>' . ( '' !== $url
				? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . $name . '</a>'
				: $name ) . '</strong>';
			// A BUILDER PAGE IS READ DIFFERENTLY, and that is worth saying on
			// the row: its text is not in the post, so its links are read from
			// the builder\'s own data and nothing may be written into it.
			if ( ! empty( $row['built'] ) ) {
				echo ' <span class="dze-auto-built" title="'
					. esc_attr__( 'Laid out by a page builder: its text is read from the builder\'s own data, and nothing is ever written into it.', 'dazont-ecom' )
					. '">' . esc_html__( 'page builder', 'dazont-ecom' ) . '</span>';
			}
			echo '</td>';
			echo wp_kses_post( DZE_Hub::id_td( (int) $row['id'] ) );
			echo '<td>' . esc_html( DZE_Mesh::kind_word( (string) $row['kind'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $row['out'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * WHAT IS NOT ON THIS LIST, and the one place it is decided.
	 *
	 * Articles and product categories always take part; a page takes part only
	 * when the shop chose it. That is why a site with thirty-five pages can
	 * show none of them here, and a screen that does not say so reads as a
	 * reading that missed them.
	 */
	private static function said_pages(): void {
		$all = 0;
		$on  = 0;
		foreach ( DZE_Mesh::pages() as $p ) {
			if ( 'page' !== $p['kind'] ) {
				continue;
			}
			$all++;
			if ( DZE_Mesh::in_work( 'page', (int) $p['id'] ) ) {
				$on++;
			}
		}
		$out = $all - $on;
		if ( $out < 1 ) {
			return;
		}
		$url = class_exists( 'DZE_Diagnostic' )
			? add_query_arg( [ 'page' => DZE_Mesh::MENU_SLUG ], admin_url( 'admin.php' ) )
			: '';
		echo '<p class="description dze-auto-orphnote">' . esc_html( sprintf(
			/* translators: %s: how many pages are not part of the linking work */
			_n(
				'%s page of this site takes no part in linking, so it is not counted here. Every article and every product category does.',
				'%s pages of this site take no part in linking, so they are not counted here. Every article and every product category does.',
				$out,
				'dazont-ecom'
			),
			number_format_i18n( $out )
		) );
		if ( '' !== $url ) {
			echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Choose them', 'dazont-ecom' ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * The one script this screen runs, whichever view is showing.
	 *
	 * Every handler is delegated, so it costs nothing on a tab where its
	 * control is not drawn — and there is ONE `post()` rather than a copy of
	 * it under each body, which is how two buttons that look the same start
	 * answering differently.
	 */
	public static function render_assets(): void {
		// JQUERY, ICI, MAINTENANT. "Runs by itself ne fonctionne pas." The
		// panel prints its script in the page BODY, and on these screens
		// jQuery is only a dependency of a FOOTER script — so WordPress
		// printed it after, the script threw on its first line, and every
		// control in the bar was dead with nothing on screen to say so.
		// wp_enqueue_script() at this point is too late to change anything:
		// the head is already sent, and an enqueue made now simply joins the
		// footer queue. wp_print_scripts() prints it HERE, and marks it done
		// so the footer does not print it a second time.
		if ( function_exists( 'wp_print_scripts' ) ) {
			wp_print_scripts( 'jquery' );
		}
		?>
		<script>
		// AND IT SAYS SO RATHER THAN DYING IN SILENCE. A control that does
		// nothing and explains nothing is the worst kind of broken.
		if ( typeof jQuery === 'undefined' ) {
			document.addEventListener( 'DOMContentLoaded', function () {
				var b = document.querySelector( '.dze-auto-strip' );
				if ( b ) { b.insertAdjacentHTML( 'beforeend', '<p class="notice notice-error inline" style="margin:8px 0 0;padding:6px 10px;"><?php echo esc_js( __( 'This panel needs jQuery, which this screen did not load. Its buttons will not answer.', 'dazont-ecom' ) ); ?></p>' ); }
			} );
		} else {
		jQuery( function ( $ ) {
			// A PRESS SAYS IT IS WORKING. "Il faut des roues de chargement quand
			// on fait quelque chose sur cette page." Put here, in the one
			// function every press already passes through, rather than on each
			// button: a list somebody keeps in step always has one forgotten
			// entry, and that one is the press that looks dead.
			function busy( $msg, on ) {
				if ( on ) {
					$msg.html( '<span class="dze-cx-spin"></span> ' )
						.append( document.createTextNode( '<?php echo esc_js( __( 'Working…', 'dazont-ecom' ) ); ?>' ) )
						.css( 'color', '#646970' );
				}
			}
			function post( action, extra, $btn, $msg ) {
				var data = $.extend( { action: action, nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>' }, extra || {} );
				$btn.prop( 'disabled', true ).addClass( 'is-busy' );
				busy( $msg, true );
				$.post( window.ajaxurl, data ).done( function ( r ) {
					$btn.prop( 'disabled', false ).removeClass( 'is-busy' );
					var d = ( r && r.data ) || {};
					if ( d.state && d.task ) {
						$( '.dze-auto-state[data-task="' + d.task + '"]' ).html( d.state );
					}
					// THE LINE ITSELF MOVES with what just happened: a figure
					// on a summary that answers for the page as it was opened
					// is a figure that lies from the first press.
					if ( d.chips && d.task ) {
						$( '.dze-auto-chips[data-task="' + d.task + '"]' ).replaceWith( d.chips );
					}
					// A RUN CAN MOVE WHAT IS WAITING — the calendar's own
					// suggestions are counted there — so the list is redrawn
					// with the line above it rather than left a page behind.
					if ( undefined !== d.waiting ) { $( '#dze-auto-waiting' ).html( d.waiting ); }
					if ( d.past ) { $( '#dze-auto-past' ).html( d.past ); }
					// Nothing happening is an answer too, and it says which one.
					if ( d.message ) { $msg.text( d.message ).css( 'color', d.queued ? '#0a7040' : '#646970' ); }
					// AND IF IT PUT SOMETHING IN THE QUEUE, the bar starts
					// moving at once. Announced here, where every press
					// already passes, rather than wired to each button.
					if ( d.queued ) { $( document ).trigger( 'dze:queued' ); }
				} ).fail( function () {
					$btn.prop( 'disabled', false ).removeClass( 'is-busy' );
					$msg.text( '<?php echo esc_js( __( 'That did not go through. Try again.', 'dazont-ecom' ) ); ?>' );
				} );
			}
			$( document ).on( 'click', '.dze-auto-catchup', function () {
				// A PRESS THAT SPENDS SAYS WHAT IT WILL DO before it does it.
				if ( ! window.confirm( '<?php echo esc_js( __( 'Put every page that is short of links into the writing queue? Each one is a pass of its own, and nothing is saved to the shop until you accept it.', 'dazont-ecom' ) ); ?>' ) ) { return; }
				var $b = $( this );
				post( 'dze_auto_catchup', { task: $b.data( 'task' ) }, $b, $b.siblings( '.dze-auto-msg' ) );
			} );
			// LE REGLAGE SE LIT AU MOMENT OU ON LE CHOISIT.
			//
			// « "Sans limite" mais demande quelle limite de posts. Stupide. » La
			// ligne portait les deux etats a la fois parce que seul un enregistrement
			// la redessinait. Les deux sont ecrits, un seul est montre, et le choix
			// bascule l affichage tout de suite.
			$( document ).on( 'change', '.dze-auto-pace', function () {
				var $s = $( this ),
					all = 'all' === $s.val(),
					// Le nombre ne sert, en allure « tout ce qu il peut », qu a la
					// traduction : ailleurs il ne rationne rien, donc il s efface.
					rations = '1' === String( $s.data( 'ration' ) ),
					$box = $s.closest( '.dze-auto-task' );
				$box.find( '.dze-auto-ration' ).toggle( ! all || rations );
				$box.find( '.dze-auto-rat-all' ).toggle( all );
				$box.find( '.dze-auto-rat-day' ).toggle( ! all );
				$box.find( '.dze-auto-said-all' ).toggle( all );
				$box.find( '.dze-auto-said-day' ).toggle( ! all );
			} );
			$( document ).on( 'click', '.dze-auto-run', function () {
				var $b = $( this );
				post( 'dze_auto_run', { task: $b.data( 'task' ) }, $b, $b.siblings( '.dze-auto-msg' ) );
			} );
			// A DECISION TAKEN ON A BORROWED ROW is announced by the queue's
			// own script, and each block answers for itself: the figures on
			// the line and the rows under it are one question, so they are
			// re-read together rather than one of them guessing.
			$( document ).on( 'dze:queue-decided', function () {
				$.post( window.ajaxurl, { action: 'dze_auto_state', nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>' } )
					.done( function ( r ) {
						var d = ( r && r.data ) || {};
						$.each( d.chips || {}, function ( id, html ) {
							$( '.dze-auto-chips[data-task="' + id + '"]' ).replaceWith( html );
						} );
						if ( undefined !== d.waiting ) { $( '#dze-auto-waiting' ).html( d.waiting ); }
						if ( d.past ) { $( '#dze-auto-past' ).html( d.past ); }
					} );
			} );
			// ---- THE WORK THIS SCREEN STARTED, WATCHED AND STEPPED ----
			// Nothing is remembered in the browser: every tick asks the queue
			// where it stands, so a reload picks the bar up exactly where it
			// was. That is the whole of "si j'actualise la page, ça reste
			// actuel avec la barre qui continue".
			var runBusy = false;
			var runTimer = null;
			var runFails = 0;
			// AN ANSWER THAT NEVER CAME IS NOT A REASON TO STOP WATCHING. This
			// function had one handler and three ways out of it — a request
			// that failed, an answer that was not a success, an answer with no
			// data — and every one of them left the watcher dead with the bar
			// frozen exactly where it stood. One 502 from a slow model call,
			// one nonce aged out overnight, and the screen read "Writing — 200
			// pages left" for as long as anybody cared to watch it: "c'est
			// bloqué." It keeps asking, more slowly each time, and says so.
			function runStumble( $said ) {
				runFails++;
				if ( runFails < 5 ) {
					$said.text( '<?php echo esc_js( __( 'The server did not answer. Trying again…', 'dazont-ecom' ) ); ?>' );
					runTimer = window.setTimeout( function () { runTick( true ); }, 1500 * runFails );
					return;
				}
				// NOTHING IS REMEMBERED IN THE BROWSER, so reloading really is
				// the way back: the bar is drawn from the queue every time.
				$said.text( '<?php echo esc_js( __( 'The server has stopped answering. Nothing is lost — reload the page to pick the run back up.', 'dazont-ecom' ) ); ?>' );
			}
			function runTick( step ) {
				if ( runBusy ) { return; }
				runBusy = true;
				$.post( window.ajaxurl, {
					action: 'dze_auto_run_state',
					nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>',
					step: step ? 1 : 0
				} ).done( function ( r ) {
					if ( ! r || ! r.success || ! r.data ) { runStumble( $( '.dze-auto-live .dze-auto-runsaid' ) ); return; }
					runFails = 0;
					// EACH ANSWER IN THE BLOCK IT BELONGS TO: one lump of
					// markup could only ever be put in one place, and the
					// press that produced it happened in one of them.
					$.each( r.data.run || {}, function ( id, html ) {
						$( '.dze-auto-live[data-task="' + id + '"]' ).html( html || '' );
					} );
					if ( r.data.waiting ) { $( '#dze-auto-waiting' ).html( r.data.waiting ); }
					$.each( r.data.chips || {}, function ( id, html ) {
						$( '.dze-auto-chips[data-task="' + id + '"]' ).replaceWith( html );
					} );
					runWatch( r.data.left > 0 );
				} ).fail( function () {
					runStumble( $( '.dze-auto-live .dze-auto-runsaid' ) );
				} ).always( function () { runBusy = false; } );
			}
			// CALLING A RUN OFF. It throws work away, so it asks first — and it
			// says in the question what is KEPT, which is the half somebody
			// hesitating actually needs.
			$( document ).on( 'click', '.dze-auto-stop', function () {
				if ( ! window.confirm( '<?php echo esc_js( __( 'Drop the pages still waiting their turn? What is already written and waiting for your yes or no is kept.', 'dazont-ecom' ) ); ?>' ) ) { return; }
				runOn( $( this ), 'dze_auto_run_stop', false );
			} );
			// The block a press was made in, so its answer goes back there.
			function runBlock( $b ) {
				var id = $b.closest( '.dze-auto-live' ).data( 'task' );
				return id ? $( '.dze-auto-live[data-task="' + id + '"]' ) : $( '.dze-auto-live' ).first();
			}
			// STARTING A STOPPED RUN AGAIN. Both presses REPLACE the block they
			// are drawn in, which is why neither goes through post(): the
			// redrawn block is the answer, and the figures in it move.
			$( document ).on( 'click', '.dze-auto-again', function () {
				runOn( $( this ), 'dze_auto_run_again', true );
			} );
			function runOn( $b, action, keepGoing ) {
				var $block = runBlock( $b );
				$b.prop( 'disabled', true );
				busy( $block.find( '.dze-auto-restarted' ), true );
				$.post( window.ajaxurl, {
					action: action,
					task: $b.data( 'task' ) || '',
					nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>'
				} ).done( function ( r ) {
					var d = ( r && r.data ) || {};
					if ( undefined !== d.run ) { $block.html( d.run ); }
					if ( undefined !== d.waiting ) { $( '#dze-auto-waiting' ).html( d.waiting ); }
					if ( d.message ) { $block.find( '.dze-auto-restarted' ).text( d.message ); }
					runFails = 0;
					// The watcher, not a step: a press whose answer is wiped
					// off the screen a hundredth of a second later has not
					// answered. The figures take over from the sentence. And a
					// run that was called off has nothing left to watch.
					runWatch( keepGoing );
				} ).fail( function () {
					$b.prop( 'disabled', false );
					$block.find( '.dze-auto-restarted' ).text( '<?php echo esc_js( __( 'That did not go through. Try again.', 'dazont-ecom' ) ); ?>' );
				} );
			}
			// While there is work left this page IS the engine — one step per
			// tick, never two at once. Once it is empty the watching stops:
			// polling an idle queue is a request a second for nothing.
			function runWatch( working ) {
				if ( runTimer ) { window.clearTimeout( runTimer ); runTimer = null; }
				if ( ! working ) { return; }
				runTimer = window.setTimeout( function () { runTick( true ); }, 1500 );
			}
			// On arrival, and after any press that queues something.
			if ( $( '.dze-auto-live .dze-auto-prog' ).length ) {
				runWatch( $( '.dze-auto-live .is-working, .dze-auto-live .is-stuck' ).length > 0 );
			}
			$( document ).on( 'dze:queued', function () { runTick( true ); } );

			// ---- ACCEPT OR CANCEL A WHOLE SELECTION ----
			// It presses the ROW'S OWN path, one row at a time, through the
			// same endpoint the single ✓ and ✗ use — never a second engine on
			// the server, which is how two ways of deciding start signing
			// decisions differently.
			function picked() { return $( '.dze-auto-todo .dze-auto-cb:checked' ).closest( '.dze-auto-job' ); }
			function bulkBar() {
				var n = picked().length;
				$( '.dze-auto-yes, .dze-auto-no' ).prop( 'disabled', ! n );
				$( '.dze-auto-picked' ).text( n
					? '<?php echo esc_js( __( 'picked', 'dazont-ecom' ) ); ?>'.replace( /^/, n + ' ' )
					: '' );
			}
			$( document ).on( 'change', '.dze-auto-cb', function () { bulkBar(); } );
			$( document ).on( 'change', '.dze-auto-allcb', function () {
				$( '.dze-auto-todo .dze-auto-cb' ).prop( 'checked', $( this ).prop( 'checked' ) );
				bulkBar();
			} );
			$( document ).on( 'click', '.dze-auto-yes, .dze-auto-no', function () {
				var accept = $( this ).hasClass( 'dze-auto-yes' );
				var $rows = picked();
				var ids = $rows.map( function () { return $( this ).data( 'id' ); } ).get();
				if ( ! ids.length ) { return; }
				var $msg = $( '.dze-auto-decided' );
				$( '.dze-auto-yes, .dze-auto-no, .dze-auto-allcb' ).prop( 'disabled', true );
				var done = 0;
				// ONE AT A TIME, and the line says how far it has got: a
				// selection of ten fired at once is ten writes racing for the
				// same rows.
				function step() {
					if ( ! ids.length ) {
						$msg.text( '' );
						$( '.dze-auto-allcb' ).prop( 'disabled', false ).prop( 'checked', false );
						$( document ).trigger( 'dze:queue-decided' );
						runTick( false );
						return;
					}
					var id = ids.shift();
					$msg.html( '<span class="dze-cx-spin"></span> ' ).append( document.createTextNode(
						'<?php echo esc_js( __( 'Deciding…', 'dazont-ecom' ) ); ?>' + ' ' + ( done + 1 ) + '/' + ( done + 1 + ids.length ) ) );
					$.post( window.ajaxurl, {
						action: 'dze_q_decide',
						nonce: '<?php echo esc_js( wp_create_nonce( DZE_Queue::NONCE ) ); ?>',
						id: id,
						accept: accept ? 1 : 0
					} ).always( function () {
						done++;
						$( '.dze-auto-job[data-id="' + id + '"]' ).remove();
						step();
					} );
				}
				step();
			} );

			$( document ).on( 'click', '.dze-auto-orph', function ( e ) {
				// A chip inside a <summary> must not fold the block under the
				// hand that pressed it.
				e.preventDefault();
				e.stopPropagation();
				$( '#dze-auto-orphbody' ).html( '<p><span class="dze-cx-spin"></span></p>' );
				$( '#dze-auto-orphmodal' ).addClass( 'is-open' );
				$.post( window.ajaxurl, { action: 'dze_auto_orphans', nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>' } )
					.done( function ( r ) {
						$( '#dze-auto-orphbody' ).html( ( r && r.data && r.data.html ) || '' );
					} )
					.fail( function () { $( '#dze-auto-orphbody' ).text( '<?php echo esc_js( __( 'Something went wrong.', 'dazont-ecom' ) ); ?>' ); } );
			} );
			$( document ).on( 'click', '.dze-hub-close', function () { $( this ).closest( '.dze-cx-modal' ).removeClass( 'is-open' ); } );
			$( document ).on( 'click', '#dze-auto-orphmodal', function ( e ) { if ( e.target === this ) { $( this ).removeClass( 'is-open' ); } } );
			$( document ).on( 'click', '.dze-auto-undo', function () {
				var $b = $( this );
				post( 'dze_auto_undo', { term: $b.data( 'term' ), what: $b.data( 'what' ) }, $b, $b.closest( 'li' ).find( '.dze-auto-msg' ) );
			} );
		} );
		}
		</script>
		<?php
	}

	/**
	 * WHAT IT IS ABOUT TO TAKE — the list, and the way to what it left you.
	 *
	 * Where it STANDS is said by the chips on the line above, in symbols: a
	 * sentence repeating them under the fold is the same answer twice, and
	 * twice is what made this screen unreadable.
	 */
	public static function render_state( string $id ): void {
		$conf = self::conf( $id );

		// DEPUIS COMBIEN DE TEMPS ELLE N A RIEN PRODUIT, dit avant tout le reste.
		//
		// « Le module maillage interne est ENCORE bugé. » Il l etait, et rien
		// sur cet ecran ne permettait de s en apercevoir : une tache cassee et
		// une tache au repos s y ressemblaient trait pour trait. On l a decouvert
		// vingt-sept heures plus tard, par hasard.
		//
		// Une tache allumee qui revient toutes les dix minutes et n a rien fait
		// depuis six heures n est pas au repos : elle est en panne, et c est ce
		// que cette ligne dit.
		if ( ! empty( $conf['on'] ) && 'month' !== ( $conf['cadence'] ?? '' ) ) {
			$last = (int) ( ( self::state()['last'] ?? [] )[ $id ] ?? 0 );
			$gap  = max( 600, self::gap( $id ) );
			if ( $last > 0 && time() - $last > max( 6 * HOUR_IN_SECONDS, $gap * 24 ) ) {
				printf(
					'<p class="dze-auto-stale" style="margin:0 0 8px;padding:6px 10px;border-left:4px solid #b32d2e;background:#fcf0f1;"><strong>%s</strong>%s</p>',
					esc_html( sprintf(
						/* translators: %s: how long ago */
						__( 'Nothing produced for %s, although it is switched on.', 'dazont-ecom' ),
						human_time_diff( $last, time() )
					) ),
					esc_html( ( $dze_said = self::idle_said( $id ) ) ? ' ' . $dze_said : '' )
				);
			} elseif ( '' !== ( $dze_said = self::idle_said( $id ) ) ) {
				// AU REPOS, ET ELLE DIT POURQUOI. « Rien trouve » et « tout attend
				// votre relecture » demandent deux gestes opposes.
				echo '<p class="description">' . esc_html( $dze_said ) . '</p>';
			}
		}


		// The whole tool rests on this list, so it is shown, not described —
		// and never JUDGED here: drawing this screen used to ask the model
		// which neighbour should point at each orphan not yet read, one call
		// per orphan with somebody waiting on the page, spending on every
		// open. The list is the wording's where no verdict is kept; the pass
		// reads before it writes.
		$next_up = self::shortlist( $id, 5, false );
		if ( ! $next_up ) {
			// ONE QUESTION, ONE SENTENCE. This literal and the answer a press
			// comes back with are the same question, and the screen printed
			// BOTH, one under the other, contradicting each other: "Nothing new
			// to work on: 6 pages…" over "Nothing is short of anything right
			// now." Two literals for one question is two answers that drift.
			echo '<p class="description">' . esc_html( self::nothing_said( $id ) ) . '</p>';
			return;
		}
		// A LIST, NOT A PARAGRAPH — and shut. "Affichage maladroit, mauvais
		// pour UI. Peut-être plutôt revenir à la ligne sur chaque post. Ou un
		// bouton d'infos qui montre les posts à venir (les cacher par défaut)."
		// Five titles, each with a parenthetical explanation, glued together
		// with middle dots wrapped over four lines of prose nobody reads. It is
		// both halves of what he asked for: folded away by default, and one
		// page per line inside — the plugin's own `details` idiom, the same one
		// every task block wears.
		printf(
			'<details class="dze-auto-nextwrap"><summary>%s</summary><ul class="dze-auto-nextlist">',
			esc_html( sprintf(
				/* translators: %s: how many pages are next in line */
				__( 'Next in line (%s)', 'dazont-ecom' ),
				number_format_i18n( count( $next_up ) )
			) )
		);
		foreach ( $next_up as $row ) {
			$name = (string) $row['name'];
			// THE ROW'S OWN KIND, never the task's scope: this task works on
			// categories and articles alike.
			$kind = (string) ( $row['kind'] ?? $conf['scope'] );
			$oid  = (int) $row['tid'];
			printf(
				'<li class="dze-auto-nextone">%1$s <span class="dze-auto-why">%2$s</span></li>',
				// The name, the way to change it and the way a READER sees it,
				// from the one function that prints an object's name anywhere.
				wp_kses_post( DZE_Hub::named( $name, self::edit_url( $kind, $oid ), self::view_url( $kind, $oid ) ) ),
				esc_html( (string) $row['why'] )
			);
		}
		echo '</ul></details>';
	}

	/**
	 * WHAT IS BEING WRITTEN RIGHT NOW, and how far along it is.
	 *
	 * Every figure here is the queue's OWN — nothing is remembered in the
	 * browser — so the bar is exactly where it was after a reload, which is
	 * the whole of "si j'actualise la page, ça reste actuel". A press that
	 * queues two hundred pages and a reload five minutes later read the same
	 * state, because the state is the table.
	 *
	 * The percentage is of the work IN PLAY: what has been written and is
	 * waiting for a yes or no, against that plus what is still to write.
	 * Nothing in flight and nothing waiting prints nothing at all — a bar at
	 * nought over an idle shop is a screen reporting a failure that never
	 * happened.
	 */
	public static function render_run( string $id = '' ): void {
		$c = self::run_state( $id );
		if ( $c['total'] < 1 ) {
			return;
		}
		$done  = $c['done'];
		$left  = $c['left'];
		$stuck = $c['stuck'] > 0;
		$bad   = $c['failed'];
		$state = $left > 0 ? ( $stuck ? ' is-stuck' : ' is-working' ) : ( $bad > 0 ? ' is-stuck' : ' is-done' );
		echo '<div class="dze-auto-prog' . esc_attr( $state ) . '">';
		// AND THE WAY TO WHAT IT ANNOUNCES. A line saying three pages wait for
		// a yes, on a screen with no list and no link, is a line that cannot
		// be acted on: "je ne peux pas voir quelles pages ont été retravaillées".
		$mine = self::my_kinds( $id );
		$to   = ( $done > 0 && $mine && class_exists( 'DZE_Queue' ) )
			? DZE_Queue::review_url( $mine )
			: '';
		echo '<p class="dze-auto-runsaid">' . esc_html( self::run_said( $c ) );
		if ( '' !== $to ) {
			echo ' <a href="' . esc_url( $to ) . '">' . esc_html__( 'Read them →', 'dazont-ecom' ) . '</a>';
		}
		echo '</p>';
		echo '<div class="dze-auto-bar"><span style="width:' . esc_attr( (string) $c['pct'] ) . '%"></span></div>';
		echo '<p class="description dze-auto-runfig">' . esc_html( sprintf(
			/* translators: 1: percentage done, 2: written, 3: in all */
			__( '%1$s%% — %2$s of %3$s written', 'dazont-ecom' ),
			number_format_i18n( $c['pct'] ),
			number_format_i18n( $done ),
			number_format_i18n( $c['total'] )
		) ) . '</p>';
		// THE PAGE IT IS ON, BY NAME. A figure and a percentage say how much is
		// left and nothing at all about what is happening; a run somebody is
		// standing in front of should be able to answer "what is it doing?".
		// Nothing in flight prints nothing: a line naming a page that is not
		// being worked on is worse than no line.
		$now = (array) ( $c['now'] ?? [] );
		if ( '' !== (string) ( $now['label'] ?? '' ) ) {
			printf(
				'<p class="description dze-auto-now">%1$s <strong>%2$s</strong> <span class="dze-auto-nowjob">%3$s</span></p>',
				esc_html( ! empty( $now['running'] )
					? __( 'Writing:', 'dazont-ecom' )
					: __( 'Next:', 'dazont-ecom' ) ),
				esc_html( (string) $now['label'] ),
				esc_html( (string) ( $now['job'] ?? '' ) )
			);
		}
		// WHAT COULD NOT BE WRITTEN, AND WHY. Counted nowhere before, so a run
		// where every job failed emptied the block off the screen entirely —
		// the strongest possible statement that nothing is wrong.
		if ( $bad > 0 ) {
			echo '<p class="dze-auto-runbad">' . esc_html( sprintf(
				/* translators: %s: how many could not be written */
				_n( '%s could not be written.', '%s could not be written.', $bad, 'dazont-ecom' ),
				number_format_i18n( $bad )
			) );
			// A figure with no reason beside it is a figure nobody can act on.
			$why = class_exists( 'DZE_Queue' ) ? (array) DZE_Queue::failures( self::my_kinds( $id ), 1 ) : [];
			$why = trim( (string) ( $why[0]['error'] ?? '' ) );
			if ( '' !== $why ) {
				echo ' <span class="description">' . esc_html( $why ) . '</span>';
			}
			echo '</p>';
		}
		// TWO CONTROLS, EACH SHOWN ONLY WHERE IT CAN ACT. "Start it again > Il
		// faut une option aussi pour annuler": the block had one, and it put
		// the work BACK — a run started by mistake could be restarted for ever
		// and never called off.
		$again = $stuck || $bad > 0;
		$off   = $left > 0 || $bad > 0;
		if ( $again || $off ) {
			echo '<p class="dze-auto-runact">';
			if ( $again ) {
				echo '<button type="button" class="button dze-auto-again" data-task="' . esc_attr( $id ) . '" title="' . esc_attr__( 'Lets the writer go, puts back what could not be written, and starts the queue again. Nothing is saved to the shop until you accept it.', 'dazont-ecom' ) . '">'
					. esc_html__( 'Start it again', 'dazont-ecom' ) . '</button> ';
			}
			if ( $off ) {
				// A PRESS THAT THROWS WORK AWAY IS NEVER A BARE WORD: its hover
				// says what goes and, above all, what stays.
				echo '<button type="button" class="button dze-auto-stop" data-task="' . esc_attr( $id ) . '" title="' . esc_attr__( 'Drops the pages still waiting their turn and the ones that could not be written. What is already written and waiting for your yes or no is kept, and so is everything you have accepted.', 'dazont-ecom' ) . '">'
					. esc_html__( 'Stop', 'dazont-ecom' ) . '</button> ';
			}
			echo '<span class="dze-auto-restarted"></span></p>';
		}
		echo '</div>';
	}

	/**
	 * The job kinds ONE task puts in the queue — or every task's, asked as one
	 * set when no task is named.
	 *
	 * "Ca devrait afficher la progression directement ici." A bar under all
	 * three tasks answers for whichever of them last queued something, so each
	 * one now carries its own and must count its own work: the linking task
	 * queues `cat_links` AND `post_links`, and asking for one counts half of it.
	 */
	public static function my_kinds( string $id = '' ): array {
		$mine = [];
		foreach ( self::tasks() as $tid => $task ) {
			if ( '' !== $id && $tid !== $id ) {
				continue;
			}
			foreach ( (array) ( $task['jobs'] ?? [] ) as $k ) {
				$mine[] = (string) $k;
			}
		}
		return array_values( array_unique( $mine ) );
	}

	/**
	 * The figures behind the bar, read from the queue and nowhere else.
	 *
	 * @return array{done:int,left:int,total:int,pct:int,running:int}
	 */
	public static function run_state( string $id = '' ): array {
		if ( ! class_exists( 'DZE_Queue' ) || ! DZE_Modules::enabled( 'queue' ) ) {
			return self::no_run();
		}
		// THE BAR AND THE LIST UNDER IT ANSWER THE SAME QUESTION. Reading the
		// whole queue here put "3 pages are written and waiting below" over a
		// list saying "nothing is waiting": a photograph made from the bulk
		// screen is in the queue and is not this page's work.
		$mine = self::my_kinds( $id );
		if ( ! $mine ) {
			return self::no_run();
		}
		$c       = (array) DZE_Queue::counts_for( $mine );
		$running = (int) ( $c['running'] ?? 0 );
		$left    = (int) ( $c['queued'] ?? 0 ) + $running;
		$done    = (int) ( $c['review'] ?? 0 );
		// WHAT COULD NOT BE WRITTEN IS PART OF THE RUN. Left out of the total,
		// a press whose every job failed made the whole block disappear: the
		// screen went from "200 pages left" to nothing at all, with no figure
		// anywhere saying the work had not happened.
		$failed  = (int) ( $c['failed'] ?? 0 );
		$total   = $left + $done + $failed;
		// A QUEUE THAT HAS STOPPED MOVING reads exactly like one about to move:
		// 200 left and nought written is also what the first second looks like.
		// The figures cannot tell them apart and the database can — every row
		// carries when it last moved.
		$idle    = $left > 0 ? (int) DZE_Queue::idle_for( $mine ) : 0;
		return [
			'done'    => $done,
			'left'    => $left,
			'failed'  => $failed,
			'total'   => $total,
			// A JOB THAT HAS STARTED IS NOT NOUGHT PER CENT. With one job in
			// the queue the bar would sit flat at 0 for the whole run, which
			// reads as a press that did nothing.
			'pct'     => $total > 0 ? max( $done > 0 || $running > 0 ? 3 : 0, (int) floor( $done * 100 / $total ) ) : 0,
			'running' => $running,
			'stuck'   => $idle >= self::STOPPED_AFTER ? $idle : 0,
			// WHAT IS BEING WRITTEN, by name. "Pendant que ça charge je veux
			// savoir ce que ça charge" — the bar gave a percentage and never
			// once said which of the two hundred pages it was on, while the
			// queue has known all along.
			'now'     => $left > 0 ? (array) DZE_Queue::in_flight( $mine ) : [],
		];
	}

	/** No queue, no run: the same shape, so no reader has to test for it. */
	private static function no_run(): array {
		return [ 'done' => 0, 'left' => 0, 'failed' => 0, 'total' => 0, 'pct' => 0, 'running' => 0, 'stuck' => 0, 'now' => [] ];
	}

	/**
	 * One line saying where the work stands — and what to do next.
	 *
	 * "Je fais quoi ensuite pour contrôler le travail ?" A screen that says
	 * "Queued" and nothing else has answered half a question.
	 */
	public static function run_said( array $c ): string {
		// NOTHING HAS MOVED. "c'est bloqué." The sentence this screen used to
		// print in that state — "Leave this screen open and it keeps going" —
		// is exactly what made him wait in front of a queue that was never
		// going to write anything.
		if ( $c['left'] > 0 && $c['stuck'] > 0 ) {
			return sprintf(
				/* translators: %s: how long nothing has moved, e.g. "8 minutes" */
				__( 'Nothing has moved for %s. The writer may be held by a run the server stopped.', 'dazont-ecom' ),
				human_time_diff( time() - (int) $c['stuck'], time() )
			);
		}
		if ( $c['left'] < 1 && $c['done'] < 1 && $c['failed'] > 0 ) {
			return __( 'This run wrote nothing.', 'dazont-ecom' );
		}
		if ( $c['left'] > 0 ) {
			return sprintf(
				/* translators: %s: how many are still to write */
				_n(
					'Writing — %s page left. Leave this screen open and it keeps going; close it and the queue carries on in the background.',
					'Writing — %s pages left. Leave this screen open and it keeps going; close it and the queue carries on in the background.',
					$c['left'],
					'dazont-ecom'
				),
				number_format_i18n( $c['left'] )
			);
		}
		// "BELOW" IS ONLY TRUE ON ONE SCREEN. This bar is printed on the
		// linking screen, on the translations screen and on the bench, where
		// there is no list under it at all — so the sentence pointed at
		// nothing and the work looked unreachable. It says WHERE instead, and
		// the caller turns that into the link.
		return sprintf(
			/* translators: %s: how many are waiting for a decision */
			_n(
				'Done — %s page is written and waiting for your yes or no.',
				'Done — %s pages are written and waiting for your yes or no.',
				$c['done'],
				'dazont-ecom'
			),
			number_format_i18n( $c['done'] )
		);
	}

	/**
	 * EVERYTHING WAITING FOR A YES OR A NO, in ONE list, on the page.
	 *
	 * "On devrait plutôt lister les tâches à review pour une meilleure UI,
	 * plutôt que de les lister dans les paramètres de l'automatisme."
	 *
	 * It was a fold inside each task's own controls, so reading what three
	 * passes had left meant opening three blocks of settings — and what is
	 * waiting for a person is not a setting. One list, open, in the order the
	 * work arrived; the chips on the lines above still say which task left
	 * what, because a figure belongs to the thing it is about.
	 *
	 * The three controls are the review list's OWN — the same popup, the same
	 * endpoints, the same record — so there is one place a decision is taken.
	 */
	public static function render_waiting(): void {
		$rows  = [];
		$queue = 0;   // waiting in the writing queue, across every task.
		$kinds = [];  // and of which kinds, so "the rest" knows where to send you.
		$aside = [];  // tasks whose work waits somewhere else entirely.
		foreach ( self::tasks() as $id => $task ) {
			$left = self::waiting_for( $id );
			if ( $left['n'] < 1 ) {
				continue;
			}
			// A TASK WHOSE WORK IS NOT A QUEUE ROW CANNOT BE SETTLED HERE, and
			// the test is what the task declares it leaves behind — never a
			// scope named in this line, which is a list somebody keeps in step
			// and forgets. The calendar's suggestions and a waiting
			// translation are both decided on the screen that owns them.
			if ( ! ( ( ! empty( $task['jobs'] ) || 'mesh' === (string) ( $task['scope'] ?? '' ) ) ) ) {
				$aside[] = [ 'label' => (string) $task['label'], 'n' => (int) $left['n'], 'url' => (string) $left['url'] ];
				continue;
			}
			$queue += (int) $left['n'];
			$kinds  = array_merge( $kinds, (array) ( $task['jobs'] ?? [] ) );
			foreach ( self::todo( $id, self::TODO_MAX ) as $row ) {
				$rows[] = $row;
			}
		}
		// Oldest first, whichever pass wrote it: what has waited longest is
		// what is offered first, and the cap is the LIST's, not each task's.
		usort( $rows, static fn( $a, $b ) => (int) $a['id'] <=> (int) $b['id'] );
		$rows = array_slice( $rows, 0, self::TODO_MAX );

		if ( ! $rows && ! $aside ) {
			echo '<p class="description">' . esc_html__( 'Nothing is waiting for your yes or no.', 'dazont-ecom' ) . '</p>';
			return;
		}
		$words = class_exists( 'DZE_Queue' ) ? DZE_Queue::decide_words() : [ 'accept' => '', 'refuse' => '' ];
		if ( $rows ) {
			self::$needs_review = true;
			// EVERY FUNCTION OF A SCREEN EXISTS ON ONE ROW AND ON THE WHOLE
			// LIST. Ten lines each needing two presses is twenty presses, and
			// the bulk screen beside this one has had ticks and a bar for
			// months: same gesture, same words, same place.
			echo '<div class="dze-auto-bulk">';
			echo '<label class="dze-auto-all"><input type="checkbox" class="dze-auto-allcb"> '
				. esc_html__( 'All', 'dazont-ecom' ) . '</label>';
			echo '<button type="button" class="button button-primary dze-auto-yes" disabled title="'
				. esc_attr( (string) $words['accept'] ) . '">' . esc_html__( 'Accept', 'dazont-ecom' ) . '</button>';
			echo '<button type="button" class="button dze-auto-no" disabled title="'
				. esc_attr( (string) $words['refuse'] ) . '">' . esc_html__( 'Cancel', 'dazont-ecom' ) . '</button>';
			echo '<span class="description dze-auto-picked"></span>';
			echo '<span class="description dze-auto-decided" role="status"></span>';
			echo '</div>';
			echo '<ul class="dze-auto-todo">';
			foreach ( $rows as $row ) {
				$jid = (int) $row['id'];
				$what = self::what_is( (string) $row['kind'] );
				$oid  = (int) $row['oid'];
				echo '<li class="dze-auto-job" data-id="' . esc_attr( (string) $jid ) . '">';
				echo '<input type="checkbox" class="dze-auto-cb" title="'
					. esc_attr__( 'Pick this one for Accept or Cancel below', 'dazont-ecom' ) . '"> ';
				// The name, the way to change it and the way to SEE it, from
				// the one function that prints an object's name anywhere.
				echo '<span class="dze-auto-jobname">' . wp_kses_post( DZE_Hub::named(
					(string) $row['label'],
					self::edit_url( 'term' === $what ? 'category' : 'post', $oid ),
					self::view_url( $what, $oid )
				) ) . '</span> ';
				// EVERY LIST THAT NAMES AN OBJECT PRINTS ITS ID.
				echo wp_kses_post( DZE_Hub::obj_id( (int) $row['oid'] ) );
				echo ' <span class="description">' . esc_html( (string) $row['job'] ) . ' · ' . esc_html( (string) $row['when'] ) . '</span>';
				echo '<span class="dze-auto-jobact">';
				echo '<button type="button" class="button button-small dze-q-open" data-id="' . esc_attr( (string) $jid ) . '">'
					. esc_html__( 'Review', 'dazont-ecom' ) . '</button> ';
				echo '<button type="button" class="dze-cb-yes dze-q-yes" data-id="' . esc_attr( (string) $jid ) . '" title="'
					. esc_attr( (string) $words['accept'] ) . '">&#10003;</button> ';
				echo '<button type="button" class="dze-cb-no dze-q-no" data-id="' . esc_attr( (string) $jid ) . '" data-status="review" title="'
					. esc_attr( (string) $words['refuse'] ) . '">&#10007;</button>';
				echo '</span></li>';
			}
			echo '</ul>';
		}
		// WHAT IS NOT ON THE LIST, and only that. Repeating the figure the rows
		// already show is the same answer twice on one screen.
		$rest = $queue - count( $rows );
		// ET IL NOMME L'ÉCRAN QUI LES TIENT. L'écran central a été supprimé,
		// donc « dans Content to review » désignait une page que WordPress
		// refuse : ce sont les genres en jeu qui disent où le reste attend.
		$more = ( $rest > 0 && class_exists( 'DZE_Queue' ) && DZE_Modules::enabled( 'queue' ) )
			? DZE_Queue::review_url( $kinds )
			: '';
		if ( '' !== $more ) {
			printf(
				'<p class="dze-auto-waiting"><a href="%1$s">%2$s</a></p>',
				esc_url( $more ),
				esc_html( sprintf(
					/* translators: %s: how many more pieces of work are waiting for a yes or a no */
					_n( '%s more waiting for your yes or no', '%s more waiting for your yes or no', $rest, 'dazont-ecom' ),
					number_format_i18n( $rest )
				) )
			);
		}
		// A task whose work waits somewhere else says so, and names where: the
		// calendar's suggestions are not queue rows and cannot be settled here.
		foreach ( $aside as $one ) {
			if ( '' === $one['url'] ) {
				continue;
			}
			printf(
				'<p class="dze-auto-waiting"><a href="%1$s">%2$s</a></p>',
				esc_url( $one['url'] ),
				esc_html( sprintf(
					/* translators: 1: how many suggestions, 2: the task they belong to */
					_n( '%1$s suggestion waiting · %2$s', '%1$s suggestions waiting · %2$s', $one['n'], 'dazont-ecom' ),
					number_format_i18n( $one['n'] ),
					$one['label']
				) )
			);
		}
	}

	/** Where one worked-on object is edited, whatever kind it is. */
	private static function edit_url( string $scope, int $oid ): string {
		if ( ! $oid ) {
			return '';
		}
		if ( 'term' === self::what_is( $scope ) ) {
			return (string) get_edit_term_link( $oid, 'product_cat' );
		}
		return (string) get_edit_post_link( $oid, '' );
	}

	/**
	 * IS THIS OBJECT A TERM OR A POST? Asked of the object's OWN kind, never of
	 * the task's scope.
	 *
	 * "Ici manque de lien direct vers les pages." The linking task works on
	 * categories AND articles alike, and its rows were linked with the TASK's
	 * scope — so four rows in five were handed `term.php?tag_ID=<a post id>`:
	 * not a missing link, a WRONG one, pointing at a term that does not exist.
	 *
	 * Everything that is not a product category is a post of some type, which
	 * is what the mesh itself already assumes.
	 *
	 * TWO VOCABULARIES, ONE QUESTION. A task names its scope ('term',
	 * 'category', 'product_cat') and a queue row names its job kind
	 * ('cat_links', 'post_links'), and both are asking exactly this. Merging
	 * the callers onto one function while it only understood ONE of the two
	 * quietly turned every category job into a post — which is how a page
	 * dropped from a run stopped being let go. It answers for both, or it is
	 * not the one answer it claims to be.
	 */
	private static function what_is( string $kind ): string {
		if ( 0 === strpos( $kind, 'cat_' ) ) {
			return 'term';
		}
		return in_array( $kind, [ 'term', 'category', 'product_cat' ], true ) ? 'term' : 'post';
	}


	/**
	 * What became of one pass, read from the shop as it stands now rather than
	 * from what the pass claimed: the queue may still be working on it.
	 */
	private static function outcome( array $row ): string {
		$id   = (string) ( $row['task'] ?? '' );
		$oid  = (int) ( $row['tid'] ?? 0 );
		$what = (string) ( $row['what'] ?? 'term' );
		if ( ! empty( $row['undone'] ) ) {
			return __( 'put back', 'dazont-ecom' );
		}
		if ( 'shop' === $what ) {
			$n = (int) ( $row['links'] ?? 0 );
			return $n
				/* translators: %d: how many suggestions were added */
				? sprintf( _n( '%d suggestion to review', '%d suggestions to review', $n, 'dazont-ecom' ), $n )
				: __( 'nothing new to suggest', 'dazont-ecom' );
		}
		if ( empty( $row['auto'] ) ) {
			return __( 'waiting for you to review it', 'dazont-ecom' );
		}
		$now = self::subject( 'post' === $what ? 'post' : 'category', $oid );
		if ( ! $now ) {
			return __( 'gone', 'dazont-ecom' );
		}
		$links = (int) preg_match_all( '/<a\s[^>]*href=/i', $now['html'] );
		$words = str_word_count( wp_strip_all_tags( $now['html'] ) );
		if ( in_array( $id, [ 'mesh_links', 'cat_links', 'post_links' ], true ) && $links > (int) ( $row['links'] ?? 0 ) ) {
			/* translators: 1: links before, 2: links now */
			return sprintf( __( '%1$d → %2$d links', 'dazont-ecom' ), (int) $row['links'], $links );
		}
		if ( 'cat_desc' === $id && $words !== (int) ( $row['words'] ?? 0 ) ) {
			/* translators: 1: words before, 2: words now */
			return sprintf( __( '%1$d → %2$d words', 'dazont-ecom' ), (int) $row['words'], $words );
		}
		return __( 'waiting for the queue', 'dazont-ecom' );
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	private static function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
	}

	/**
	 * Why a run did, or did not, queue anything. A button that answers nothing
	 * when it does nothing is a button that gets clicked five times.
	 */
	public static function reason_text( string $reason, string $task = '' ): string {
		switch ( $reason ) {
			case 'queued':
				return __( 'Queued — the writing queue does it in the background.', 'dazont-ecom' );
			case 'cap':
				return __( 'Today\'s figure is used up.', 'dazont-ecom' );
			case 'none':
				return self::nothing_said( $task );
			case 'budget':
				return __( 'The monthly AI budget is spent.', 'dazont-ecom' );
			case 'modules':
				return __( 'The module that owns this work, or the writing queue, is switched off.', 'dazont-ecom' );
			case 'busy':
				return __( 'That category is already waiting in the queue.', 'dazont-ecom' );
			case 'off':
				return __( 'This task is switched off.', 'dazont-ecom' );
			case 'copy':
				return __( 'This site is a copy of the shop, so nothing runs on its own here — press Run now to try a task.', 'dazont-ecom' );
			case 'early':
				return __( 'Too soon after the last one — it is spread on purpose.', 'dazont-ecom' );
			case 'failed':
				return __( 'The model could not be reached. It will be tried again.', 'dazont-ecom' );
			case 'unread':
				return self::unread_said();
		}
		return __( 'Nothing was queued.', 'dazont-ecom' );
	}

	private static function block( string $id ): string {
		ob_start();
		self::render_state( $id );
		return (string) ob_get_clean();
	}

	private static function waiting_html(): string {
		ob_start();
		self::render_waiting();
		return (string) ob_get_clean();
	}

	private static function past_html(): string {
		ob_start();
		self::render_past();
		return (string) ob_get_clean();
	}

	/**
	 * How many pieces of work these passes have PUBLISHED.
	 *
	 * Read from the queue's applied rows — the durable record that a page was
	 * written and somebody said yes — narrowed to the kinds these tasks queue.
	 * Never a store of our own beside it: two accounts of one thing disagree.
	 *
	 * @return array<int,array{kind:string,object_id:int,when:int,by:int,from:int}>
	 */
	public static function past( int $limit = 200, string $only = '' ): array {
		if ( ! class_exists( 'DZE_Queue' ) || ! DZE_Modules::enabled( 'queue' ) ) {
			return [];
		}
		// ONE TASK, WHEN ONE TASK IS ASKED FOR. "Je ne peux pas voir quelles
		// pages ont été retravaillées. Je voulais voir une liste des pages
		// avec maillage interne refait." The list held every task's work at
		// once, so the answer to "which pages did the linking touch" was in
		// there somewhere, mixed with the category descriptions.
		$tasks = self::tasks();
		if ( '' !== $only ) {
			$tasks = isset( $tasks[ $only ] ) ? [ $only => $tasks[ $only ] ] : [];
		}
		$kinds = [];
		foreach ( $tasks as $task ) {
			foreach ( (array) ( $task['jobs'] ?? [] ) as $k ) {
				$kinds[ (string) $k ] = true;
			}
		}
		return $kinds ? DZE_Queue::applied_rows( $limit, array_keys( $kinds ) ) : [];
	}

	/** Where the list of what a task has written lives, filtered to it. */
	public static function past_url( string $id = '' ): string {
		if ( ! class_exists( 'DZE_Screens' ) ) {
			return '';
		}
		$url = DZE_Screens::url( 'logs', 'past' );
		return ( '' === $url || '' === $id ) ? $url : add_query_arg( [ 'task' => $id ], $url );
	}

	/**
	 * THE RECORD OF WHAT WENT OUT — its own view, not a fold under the work.
	 *
	 * "Maintenant que To review est là, ce bloc est inutile. Sinon crée un
	 * nouvel onglet… pour recenser tous les travaux publiés gérés par le
	 * module automation."
	 *
	 * One line per page written, newest first, with the date, who asked for
	 * the work and who accepted it — and the undo where the pass still holds
	 * the text it replaced.
	 */
	/**
	 * Where a visitor reads this object — never the editor, which is what the
	 * name beside it already opens.
	 *
	 * An object with no address answers '' and the symbol is simply not shown:
	 * a link to "#" is a control that cannot act.
	 */
	private static function view_url( string $what, int $oid ): string {
		if ( $oid < 1 ) {
			return '';
		}
		if ( 'term' === self::what_is( $what ) ) {
			$link = get_term_link( $oid, 'product_cat' );
			return ( $link && ! is_wp_error( $link ) ) ? (string) $link : '';
		}
		return (string) get_permalink( $oid );
	}

	/**
	 * CE QU'UNE PASSE A POSÉ, ET CE QU'IL EN RESTE AUJOURD'HUI.
	 *
	 * « Pour cette page de desert tan combat boots, par exemple, je ne sais
	 * pas ce qui devait être fait et ce qui n'a pas été fait au final. »
	 *
	 * Le journal disait qu'une passe avait eu lieu, et rien d'autre. Sur
	 * /desert-tan-combat-boots il disait vrai — un lien avait bien été posé,
	 * « desert camouflage patterns » vers /military-camouflage-patterns/desert-camo
	 * — mais la description avait été retouchée à la main depuis, la phrase qui
	 * le portait était partie, et la page n'avait plus aucun lien. Les deux
	 * affirmations étaient justes et la ligne les faisait se contredire.
	 *
	 * Alors on relit ce que la passe a produit, on en extrait les liens, et on
	 * regarde lesquels sont encore dans le texte tel qu'il est maintenant. Rien
	 * n'est stocké : la comparaison se fait à la lecture, donc elle ne peut pas
	 * vieillir.
	 *
	 * @return array{posed:array<int,array{anchor:string,url:string,live:bool}>,live:int,lost:int}
	 */
	public static function what_it_did( int $job_id, string $kind, int $object_id ): array {
		$vide = [ 'rows' => [], 'added' => 0, 'kept' => 0, 'lost' => 0, 'total' => 0, 'sure' => false ];
		global $wpdb;
		if ( $job_id < 1 || ! $wpdb || ! class_exists( 'DZE_Queue' ) ) {
			return $vide;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			'SELECT result, payload FROM ' . DZE_Queue::table() . ' WHERE id = %d',
			$job_id
		), ARRAY_A );
		if ( ! $row ) {
			return $vide;
		}
		$res  = (string) ( $row['result'] ?? '' );
		$load = $row['payload'] ? (array) json_decode( (string) $row['payload'], true ) : [];

		// LE TEXTE D AUJOURD HUI fait foi pour « combien la page en porte ».
		// Rien n est stocke de ce cote : la comparaison se fait a la lecture,
		// donc elle ne peut pas vieillir.
		$now  = DZE_Queue::hrefs_in( self::text_now( $kind, $object_id ) );
		$in   = static fn( string $u, array $set ): bool => in_array( untrailingslashit( $u ), array_map( 'untrailingslashit', $set ), true );

		// QUI A POSE QUOI. Trois sources, de la plus sure a la moins sure.
		//
		// 1. Le releve pris par la passe elle-meme juste avant d ecrire : il
		//    dit exactement ce que la page portait, donc exactement ce qu elle
		//    n a PAS pose. Les lignes ecrites depuis 4.471.0 le portent.
		// 2. A defaut, les cibles demandees : le module ne vise jamais une page
		//    deja liee, donc une adresse de cette liste est forcement neuve.
		// 3. Sans ni l un ni l autre — de vieilles lignes — on ne sait pas, et
		//    on le DIT au lieu d inventer un partage.
		$was  = isset( $load[ DZE_Queue::WAS_LINKED ] ) ? (array) $load[ DZE_Queue::WAS_LINKED ] : null;
		$aims = array_values( array_filter( array_map( 'strval', (array) ( $load['urls'] ?? [] ) ) ) );
		$sure = ( null !== $was ) || (bool) $aims;

		$out = $vide;
		$out['sure']  = $sure;
		$out['total'] = count( $now );

		$seen = [];
		if ( preg_match_all( '#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', $res, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $one ) {
				$url = html_entity_decode( (string) $one[1], ENT_QUOTES, 'UTF-8' );
				if ( isset( $seen[ untrailingslashit( $url ) ] ) ) {
					continue; // le meme lien deux fois dans le texte reste UN lien.
				}
				$seen[ untrailingslashit( $url ) ] = true;
				$live = $in( $url, $now );
				if ( null !== $was ) {
					$neuf = ! $in( $url, $was );
				} elseif ( $aims ) {
					$neuf = $in( $url, $aims );
				} else {
					$neuf = null;
				}
				if ( null === $neuf ) {
					$etat = $live ? 'unknown' : 'unknown_gone';
				} elseif ( $neuf ) {
					$etat = $live ? 'new' : 'lost';
					$live ? $out['added']++ : $out['lost']++;
				} else {
					$etat = $live ? 'kept' : 'dropped';
					if ( $live ) { $out['kept']++; }
				}
				$out['rows'][] = [
					'anchor' => trim( wp_strip_all_tags( (string) $one[2] ) ),
					'url'    => $url,
					'state'  => $etat,
				];
			}
		}

		// CE QUE LA PAGE PORTE ET QUE CETTE PASSE N A PAS ECRIT. Sans eux la
		// liste ne fait pas le compte annonce, et une liste qui contredit son
		// propre total est exactement le defaut qu on repare ici.
		foreach ( $now as $url ) {
			if ( isset( $seen[ untrailingslashit( $url ) ] ) ) {
				continue;
			}
			$seen[ untrailingslashit( $url ) ] = true;
			$out['rows'][] = [ 'anchor' => self::anchor_of( $kind, $object_id, $url ), 'url' => $url, 'state' => 'since' ];
		}

		// UNE CIBLE DEMANDEE ET JAMAIS POSEE. La ligne disait « will link to 3
		// pages » et n en montrait que ce qui avait marche : la troisieme
		// disparaissait sans un mot.
		foreach ( $aims as $url ) {
			if ( isset( $seen[ untrailingslashit( $url ) ] ) ) {
				continue;
			}
			$seen[ untrailingslashit( $url ) ] = true;
			$out['rows'][] = [ 'anchor' => '', 'url' => $url, 'state' => 'missed' ];
		}
		return $out;
	}

	/** Les mots qui portent un lien dans le texte d aujourd hui. */
	private static function anchor_of( string $kind, int $object_id, string $url ): string {
		$html = self::text_now( $kind, $object_id );
		if ( '' === $html || ! preg_match( '#<a [^>]*href="' . preg_quote( $url, '#' ) . '"[^>]*>(.*?)</a>#is', $html, $m ) ) {
			return '';
		}
		return trim( wp_strip_all_tags( (string) $m[1] ) );
	}

	/** Le texte d'un objet tel qu'il est maintenant, lu dans les tables. */
	private static function text_now( string $kind, int $object_id ): string {
		$what = self::what_is( $kind );
		if ( 'term' === $what ) {
			$row = class_exists( 'DZE_Category_Content' )
				? DZE_Category_Content::term_row( $object_id )
				: null;
			return (string) ( $row['description'] ?? '' );
		}
		$p = get_post( $object_id );
		return $p ? (string) $p->post_content : '';
	}

	/**
	 * @param string $only Une tâche à montrer seule. Vide : celle que l'adresse
	 *                     demande, ou toutes. L'argument existe pour que l'écran
	 *                     d'un module puisse rappeler CETTE liste réduite à lui
	 *                     au lieu d'en dessiner une deuxième.
	 */
	public static function render_past( string $only = '' ): void {
		if ( '' === $only ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- narrowing a read-only list.
			$only = isset( $_GET['task'] ) ? sanitize_key( wp_unslash( $_GET['task'] ) ) : '';
		}
		$only = isset( self::tasks()[ $only ] ) ? $only : '';
		$rows = self::past( 200, $only );
		if ( '' !== $only ) {
			printf(
				'<p class="description" style="margin:0 0 10px;">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html( sprintf(
					/* translators: %s: the name of the task */
					__( 'Showing only what “%s” has written.', 'dazont-ecom' ),
					(string) ( self::tasks()[ $only ]['label'] ?? $only )
				) ),
				esc_url( self::past_url() ),
				esc_html__( 'Show every pass', 'dazont-ecom' )
			);
		}		if ( ! $rows ) {
			echo '<div class="dze-admin dze-auto"><p class="description">'
				. esc_html__( 'Nothing has been written to the shop by these passes yet.', 'dazont-ecom' )
				. '</p></div>';
			return;
		}
		$kinds = class_exists( 'DZE_Queue' ) ? DZE_Queue::kinds() : [];
		echo '<div class="dze-admin dze-auto"><table class="wp-list-table widefat fixed striped dze-auto-past">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Page', 'dazont-ecom' ) . '</th>';
		echo wp_kses_post( DZE_Hub::id_th() );
		echo '<th>' . esc_html__( 'Job', 'dazont-ecom' ) . '</th>';
		// CE QU'ELLE A FAIT, ET CE QU'IL EN RESTE. « Je ne sais pas ce qui
		// devait être fait et ce qui n'a pas été fait au final. »
		echo '<th style="width:30%;">' . esc_html__( 'What it did', 'dazont-ecom' ) . '</th>';
		echo '<th>' . esc_html__( 'Started by', 'dazont-ecom' ) . '</th>';
		echo '<th>' . esc_html__( 'Accepted by', 'dazont-ecom' ) . '</th>';
		echo '<th class="dze-auto-whenth">' . esc_html__( 'When', 'dazont-ecom' ) . '</th>';
		echo '<th class="dze-auto-actth">' . esc_html__( 'Action', 'dazont-ecom' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$kind = (string) $row['kind'];
			$oid  = (int) $row['object_id'];
			$what = self::what_is( $kind );
			$url  = self::edit_url( 'term' === $what ? 'category' : 'post', $oid );
			$name = class_exists( 'DZE_Queue' ) ? DZE_Queue::label_for( $kind, $oid ) : (string) $oid;
			// THE UNDO IS OFFERED WHERE IT CAN ACT: the pass keeps the text it
			// replaced only for what it saved without review, and only while
			// that pass is still in its own register.
			$can  = $oid && '' !== trim( self::copy_of( $oid, $what ) );
			echo '<tr>';
			// THE PAGE AS A VISITOR SEES IT. "Aucun bouton pour voir la page
			// côté utilisateur, il manque le petit symbole qui devrait
			// rediriger on site." The name opens the editor, which is where
			// you go to change it — and after accepting a text written onto a
			// page, the thing you actually want is to look at it.
			echo '<td><strong>' . wp_kses_post( DZE_Hub::named( $name, $url, self::view_url( $what, $oid ) ) ) . '</strong></td>';
			echo wp_kses_post( DZE_Hub::id_td( $oid ) );
			echo '<td>' . esc_html( (string) ( $kinds[ $kind ]['label'] ?? $kind ) ) . '</td>';
			// CE QU'ELLE A POSÉ, ET CE QUI TIENT ENCORE.
			//
			// La ligne disait qu'une passe avait eu lieu, et rien d'autre. Sur
			// /desert-tan-combat-boots elle disait vrai — un lien avait bien
			// été posé — mais la description avait été retouchée à la main
			// depuis, et la page n'en portait plus aucun. Les deux
			// affirmations étaient justes et la ligne les faisait se
			// contredire. On relit donc ce que la passe a produit et on le
			// confronte au texte d'aujourd'hui, à la lecture : rien n'est
			// stocké, donc rien ne peut vieillir.
			echo '<td>';
			$fait = self::what_it_did( (int) ( $row['id'] ?? 0 ), $kind, $oid );
			if ( '' !== (string) ( $row['why'] ?? '' ) ) {
				echo '<div class="description" style="margin:0 0 4px;">' . esc_html( (string) $row['why'] ) . '</div>';
			}
			if ( ! $fait['rows'] ) {
				// RIEN A MONTRER N EST PAS RIEN A DIRE : un travail d avant que
				// ce journal ne gardait pas, ou une passe qui a nettoye sans
				// rien ajouter. Inventer « 0 lien » serait pire.
				echo '<span class="description">' . esc_html__( 'not recorded', 'dazont-ecom' ) . '</span>';
			} else {
				// DEUX NOMBRES QUI DISENT DEUX CHOSES.
				//
				// « 3 links placed, 3 still there. Ca veut dire quoi ? Le meme
				// chiffre. C est buge ou quoi ? » Non : le second comptait ceux
				// des trois qui tenaient encore, donc il les repetait des que
				// rien n avait bouge — et la coche verte devant chaque lien le
				// disait deja. Le premier, lui, etait FAUX : il comptait les
				// liens du texte produit, y compris ceux que la page portait
				// avant la passe.
				//
				// On annonce donc ce qu on ajoute et ce que la page porte.
				if ( $fait['sure'] ) {
					$phrase = sprintf(
						/* translators: 1: links this pass added, 2: links on the page today */
						_n( '%1$s new link, %2$s on the page now', '%1$s new links, %2$s on the page now', $fait['added'], 'dazont-ecom' ),
						number_format_i18n( $fait['added'] ),
						number_format_i18n( $fait['total'] )
					);
				} else {
					// Une ligne d avant 4.471.0 : elle ne sait pas ce qu elle a
					// ajoute, et le dire vaut mieux que de l inventer.
					$phrase = sprintf(
						/* translators: %s: links on the page today */
						_n( '%s link on the page now', '%s links on the page now', $fait['total'], 'dazont-ecom' ),
						number_format_i18n( $fait['total'] )
					);
				}
				printf( '<strong>%s</strong>', esc_html( $phrase ) );
				if ( $fait['lost'] ) {
					printf(
						' <span style="color:#b32d2e;">%s</span>',
						esc_html( sprintf(
							/* translators: %s: how many links this pass placed that are gone */
							_n( '— %s has gone since', '— %s have gone since', $fait['lost'], 'dazont-ecom' ),
							number_format_i18n( $fait['lost'] )
						) )
					);
				}
				// CHAQUE LIEN AVEC SON ORIGINE, EN TOUTES LETTRES. « Montrer
				// quels liens etaient deja la avant et montrer les nouveaux
				// aussi. » Une couleur seule n est pas une etiquette.
				$dit = [
					'new'          => [ '#0a7040', '&#43;', __( 'new', 'dazont-ecom' ) ],
					'lost'         => [ '#b32d2e', '&#10007;', __( 'placed by this pass, gone since', 'dazont-ecom' ) ],
					'kept'         => [ '#646970', '&#8226;', __( 'already there', 'dazont-ecom' ) ],
					'dropped'      => [ '#b32d2e', '&#10007;', __( 'was there, taken out', 'dazont-ecom' ) ],
					'since'        => [ '#646970', '&#8226;', __( 'added since', 'dazont-ecom' ) ],
					'missed'       => [ '#996800', '&#33;', __( 'asked for, not placed', 'dazont-ecom' ) ],
					'unknown'      => [ '#646970', '&#8226;', __( 'on the page', 'dazont-ecom' ) ],
					'unknown_gone' => [ '#b32d2e', '&#10007;', __( 'gone since', 'dazont-ecom' ) ],
				];
				echo '<ul style="margin:4px 0 0;font-size:12px;">';
				foreach ( $fait['rows'] as $un ) {
					$say = $dit[ $un['state'] ] ?? $dit['unknown'];
					printf(
						'<li style="margin:0;color:%1$s;">%2$s %3$s%4$s <span class="description">(%5$s)</span></li>',
						esc_attr( $say[0] ),
						$say[1], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- une entite HTML litterale.
						'' !== $un['anchor']
							? sprintf(
								'<a href="%1$s" target="_blank" rel="noopener">%2$s</a> &rarr; ',
								esc_url( $un['url'] ),
								esc_html( $un['anchor'] )
							)
							: '',
						esc_html( (string) wp_parse_url( $un['url'], PHP_URL_PATH ) ),
						esc_html( $say[2] )
					);
				}
				echo '</ul>';
			}			echo '</td>';
			echo '<td>' . esc_html( class_exists( 'DZE_Queue' ) ? DZE_Queue::started_by( (int) $row['from'] ) : '' ) . '</td>';
			echo '<td>' . esc_html( class_exists( 'DZE_Queue' ) ? DZE_Queue::decided_by( (int) $row['by'] ) : '' ) . '</td>';
			echo '<td class="dze-auto-when">' . esc_html( date_i18n( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) $row['when'] ) ) . '</td>';
			echo '<td class="dze-auto-act">';
			if ( $can ) {
				echo '<button type="button" class="button button-small dze-auto-undo" data-term="' . esc_attr( (string) $oid )
					. '" data-what="' . esc_attr( $what ) . '">&#8634; ' . esc_html__( 'Undo', 'dazont-ecom' ) . '</button> ';
			}
			echo '<span class="dze-auto-msg"></span>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function ajax_run(): void {
		self::guard();
		$id = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		if ( ! self::task( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown task.', 'dazont-ecom' ) ] );
		}
		// The button is the tick, asked for by hand: same rules, same caps,
		// same code — a button that behaves differently tests nothing.
		$res = self::tick( $id, true );
		wp_send_json_success( [
			'queued'  => (int) $res['queued'],
			'task'    => $id,
			'message' => self::reason_text( (string) $res['reason'], (string) ( $res['task'] ?? '' ) ),
			'state'   => self::block( $id ),
			'chips'   => self::chips_html( $id ),
			'waiting' => self::waiting_html(),
			'past'    => self::past_html(),
		] );
	}

	/**
	 * WHERE THE WORK STANDS, and one step of it taken.
	 *
	 * THE JOB SOMEBODY IS WATCHING IS THE JOB THAT MOVES. This screen starts
	 * the work — "Run one now", "Link the whole site" — and until now it
	 * neither showed it nor moved it: `refresh()` in queue.js returns at once
	 * where there is no job table, which was right when this page had no
	 * running job to show and became the bug the moment it had. A shop whose
	 * scheduler is wedged pressed the button, read "Queued", and waited for
	 * something that was never going to happen.
	 *
	 * So while this screen is open it is the engine, exactly as the review
	 * screen is: one step per tick, never two at once. Closed, the queue's own
	 * cron carries on — which is why the line says so in words.
	 *
	 * The answer carries every figure the step can move: the bar, the rows
	 * waiting for a decision, and the chips on each task's line.
	 */
	/**
	 * STARTS A STOPPED RUN AGAIN — the one control on a screen that had none.
	 *
	 * It does the two things that stop a queue, together, because they arrive
	 * together: the writer's lock left behind by a run the host killed, and the
	 * rows that were called failed while it stood. Answering "3 put back" with
	 * the lock still standing is a press that changes nothing.
	 */
	public static function ajax_run_again(): void {
		self::guard();
		if ( ! class_exists( 'DZE_Queue' ) || ! DZE_Modules::enabled( 'queue' ) ) {
			wp_send_json_error( [ 'message' => __( 'The writing queue is switched off.', 'dazont-ecom' ) ] );
		}
		// THE BLOCK IT WAS PRESSED IN. Acting on every task would put back work
		// the reader never asked about, from a button in somebody else's block.
		$task = self::asked_task();
		DZE_Queue::unlock();
		$back = (int) DZE_Queue::retry_failed( self::my_kinds( $task ) );
		ob_start();
		self::render_run( $task );
		wp_send_json_success( [
			'task'    => $task,
			'run'     => (string) ob_get_clean(),
			'waiting' => self::waiting_html(),
			'message' => $back > 0
				? sprintf(
					/* translators: %s: how many were put back in the queue */
					_n( '%s put back in the queue.', '%s put back in the queue.', $back, 'dazont-ecom' ),
					number_format_i18n( $back )
				)
				: __( 'The writer is free. The queue is going again.', 'dazont-ecom' ),
		] );
	}

	/**
	 * CALLS A RUN OFF. The other half of "start it again", and the half that
	 * was missing: a press that only ever puts work back is not a control over
	 * the work.
	 *
	 * What it drops is decided by the queue, in one place — what waits its turn
	 * and what could not be written. Nothing written is ever thrown away here.
	 */
	public static function ajax_run_stop(): void {
		self::guard();
		if ( ! class_exists( 'DZE_Queue' ) || ! DZE_Modules::enabled( 'queue' ) ) {
			wp_send_json_error( [ 'message' => __( 'The writing queue is switched off.', 'dazont-ecom' ) ] );
		}
		$task = self::asked_task();
		$gone = (int) DZE_Queue::drop_waiting( self::my_kinds( $task ) );
		// AND THE REGISTER LETS THEM GO. Every page the catch-up queues is
		// stamped as worked on so the daily pass does not do it twice; dropped,
		// that stamp locks the page out of the very pass meant to mend it,
		// having had nothing written to it.
		self::free_pages( DZE_Queue::dropped_rows() );
		ob_start();
		self::render_run( $task );
		wp_send_json_success( [
			'task'    => $task,
			'run'     => (string) ob_get_clean(),
			'waiting' => self::waiting_html(),
			'message' => $gone > 0
				? sprintf(
					/* translators: %s: how many pages were dropped from the queue */
					_n( '%s called off. Nothing written was thrown away.', '%s called off. Nothing written was thrown away.', $gone, 'dazont-ecom' ),
					number_format_i18n( $gone )
				)
				: __( 'There was nothing left to call off.', 'dazont-ecom' ),
		] );
	}

	/** Which task's block a press came from — '' meaning every one of them. */
	private static function asked_task(): string {
		$id = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran.
		return isset( self::tasks()[ $id ] ) ? $id : '';
	}

	public static function ajax_run_state(): void {
		self::guard();
		$step = ! empty( $_POST['step'] );
		if ( $step && class_exists( 'DZE_Queue' ) && DZE_Modules::enabled( 'queue' ) ) {
			// One step. It never throws the screen away: a job that fails is
			// recorded on its own row and read back by the next poll.
			DZE_Queue::work();
		}
		$state = self::run_state();
		// A BAR PER TASK, keyed like the chips beside them: one lump of markup
		// could only ever be put in one place, and the press that produced it
		// happened in one particular block.
		$html  = [];
		$chips = [];
		foreach ( array_keys( self::tasks() ) as $id ) {
			ob_start();
			self::render_run( $id );
			$html[ $id ]  = (string) ob_get_clean();
			$chips[ $id ] = self::chips_html( $id );
		}
		wp_send_json_success( [
			'run'     => $html,
			'left'    => (int) $state['left'],
			'done'    => (int) $state['done'],
			'pct'     => (int) $state['pct'],
			'waiting' => self::waiting_html(),
			'chips'   => $chips,
		] );
	}

	/**
	 * THE LIST BEHIND THE CHIP.
	 *
	 * One reading, printed by the renderer the popup shows — never a second
	 * account of the same figure.
	 */
	public static function ajax_orphans(): void {
		self::guard();
		ob_start();
		self::render_orphans();
		wp_send_json_success( [ 'html' => (string) ob_get_clean() ] );
	}

	public static function ajax_catchup(): void {
		self::guard();
		$id = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		if ( ! self::task( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown task.', 'dazont-ecom' ) ] );
		}
		$res = self::catch_up( $id );
		wp_send_json_success( [
			'queued'  => (int) $res['queued'],
			'task'    => $id,
			'message' => self::catch_up_said( $res ),
			'state'   => self::block( $id ),
			'chips'   => self::chips_html( $id ),
			'waiting' => self::waiting_html(),
			'past'    => self::past_html(),
		] );
	}

	/**
	 * WHAT THE PRESS DID, in words, and whether there is more.
	 *
	 * A figure alone leaves the shop wondering whether that was the whole site
	 * or the first slice of it — which is the one thing somebody pressing this
	 * needs to know.
	 */
	public static function catch_up_said( array $res ): string {
		$n = (int) ( $res['queued'] ?? 0 );
		if ( $n < 1 ) {
			return self::reason_text( (string) ( $res['reason'] ?? 'none' ), (string) ( $res['task'] ?? '' ) );
		}
		// AND WHERE THE WORK HAPPENS. The press only puts the pages in the
		// queue; the writing is done by the queue itself, one at a time, with
		// nobody waiting — so the one thing to say is that this page can be
		// left now.
		$said = sprintf(
			/* translators: %s: how many pages were put in the writing queue */
			_n( '%s page queued — written in the background, one at a time.', '%s pages queued — written in the background, one at a time.', $n, 'dazont-ecom' ),
			number_format_i18n( $n )
		);
		return $said . ' ' . ( ! empty( $res['more'] )
			? __( 'There is more to do — press again once these are through.', 'dazont-ecom' )
			: __( 'That is every page that was short of links.', 'dazont-ecom' ) );
	}

	/**
	 * WHAT A DECISION CHANGED, and nothing else.
	 *
	 * Saying yes or no to a borrowed row moves two things on this screen: the
	 * figures on the task's own line, and the rows under it. What the pass
	 * would take NEXT is untouched by a decision, and that reading is the
	 * expensive half — so it is not re-done here. The register is, because a
	 * line of it says what became of the very job just decided.
	 */
	public static function ajax_state(): void {
		self::guard();
		$chips = [];
		foreach ( array_keys( self::tasks() ) as $id ) {
			$chips[ $id ] = self::chips_html( $id );
		}
		wp_send_json_success( [
			'chips'   => $chips,
			'waiting' => self::waiting_html(),
			'past'    => self::past_html(),
		] );
	}

	public static function ajax_undo(): void {
		self::guard();
		$tid  = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : 'term';
		if ( ! $tid || ! self::undo( $tid, 'post' === $what ? 'post' : 'term' ) ) {
			wp_send_json_error( [
				'message' => __( 'Nothing to put back on this category.', 'dazont-ecom' ),
				'past'    => self::past_html(),
			] );
		}
		wp_send_json_success( [
			'message' => __( 'Put back.', 'dazont-ecom' ),
			'past'    => self::past_html(),
		] );
	}
}
