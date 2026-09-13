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
		return [
			'work' => __( 'Tasks', 'dazont-ecom' ),
			'past' => __( 'Past work', 'dazont-ecom' ),
		];
	}

	/** Which view is being asked for — always one that exists. */
	public static function tab_now( array $get ): string {
		$want = isset( $get['tab'] ) ? sanitize_key( (string) $get['tab'] ) : '';
		return isset( self::tabs()[ $want ] ) ? $want : 'work';
	}

	public static function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Automation', 'dazont-ecom' ),
			__( 'Automation', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab navigation only.
		$now = self::tab_now( (array) $_GET );
		echo '<div class="wrap dze-wrap"><h1>' . esc_html__( 'Automation', 'dazont-ecom' ) . '</h1>';
		echo '<h2 class="nav-tab-wrapper" style="margin:12px 0 18px;">';
		foreach ( self::tabs() as $key => $label ) {
			printf(
				'<a class="nav-tab%1$s" href="%2$s">%3$s</a>',
				$key === $now ? ' nav-tab-active' : '',
				esc_url( self::page_url( $key ) ),
				esc_html( $label )
			);
		}
		echo '</h2>';
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

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
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
		wp_clear_scheduled_hook( 'dze_mesh_tick' );
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
		if ( ! class_exists( 'DZE_Queue' ) || ! DZE_Modules::enabled( 'queue' ) ) {
			return [ 'n' => 0, 'url' => '' ];
		}
		return [
			'n'   => DZE_Queue::review_count_for( (array) ( $task['jobs'] ?? [] ) ),
			'url' => DZE_Queue::url(),
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
		if ( 'shop' !== ( $t['scope'] ?? '' ) ) {
			if ( ! class_exists( 'DZE_Queue' ) ) {
				return false;
			}
			if ( class_exists( 'DZE_Modules' ) && ! DZE_Modules::enabled( 'queue' ) ) {
				return false;
			}
		}
		if ( 'shop' !== ( $t['scope'] ?? '' ) && ! class_exists( 'DZE_Category_Content' ) ) {
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

	/** The quiet time between two passes of one task. */
	public static function gap( string $id ): int {
		$conf = self::conf( $id );
		if ( 'month' === $conf['cadence'] ) {
			return 30 * DAY_IN_SECONDS;
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
		if ( 'day' === $conf['cadence'] && self::done_today( $id ) >= $conf['per_day'] ) {
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
	public static function shortlist( string $id, int $n = 5 ): array {
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
			return self::mesh_shortlist( $id, $n );
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
	private static function mesh_shortlist( string $id, int $n, string $only = '' ): array {
		if ( ! class_exists( 'DZE_Mesh' ) ) {
			return [];
		}
		$cool = time() - self::COOLDOWN * DAY_IN_SECONDS;
		$out  = [];
		$seen = [];
		$take = static function ( array $row ) use ( &$out, &$seen, $id, $cool ): bool {
			$type = 'product_cat' === $row['kind'] ? 'term' : 'post';
			$key  = $row['kind'] . ':' . (int) $row['tid'];
			if ( isset( $seen[ $key ] ) || self::cooling( (int) $row['tid'], $id, $type, 0, 0, $cool ) ) {
				return false;
			}
			// A page already in the writing queue is not work: it would come
			// back a second text for the same page.
			if ( class_exists( 'DZE_Queue' ) && 'product_cat' === $row['kind'] && DZE_Queue::pending_for( (int) $row['tid'] ) ) {
				return false;
			}
			if ( class_exists( 'DZE_Queue' ) && 'product_cat' !== $row['kind'] && DZE_Queue::pending_for( (int) $row['tid'], 'post_' ) ) {
				return false;
			}
			$seen[ $key ] = true;
			$out[]        = $row;
			return true;
		};

		// PHASE ONE — the holes in the mesh: the pages the site points at
		// least, mended from the pages closest to them. Always first: a page
		// nobody can reach is worth more than a page that reads a little thin.
		foreach ( 'out' === $only ? [] : DZE_Mesh::plan( max( 1, $n ) * 3 ) as $row ) {
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

		// PHASE TWO — the work that used to be done by hand: the pages under
		// their own outgoing quota. No addresses travel with these: the page's
		// OWN pool decides, and on a shop that pool ranks product categories
		// above articles, which is the direction that earns the money.
		foreach ( 'in' === $only ? [] : DZE_Mesh::thin( max( 1, $n ) * 3 ) as $row ) {
			$take( [
				'tid'   => (int) $row['id'],
				'name'  => (string) $row['title'],
				'why'   => self::thin_said( (int) $row['short'] ),
				'kind'  => (string) $row['kind'],
				'urls'  => [],
				'phase' => 'out',
			] );
			if ( count( $out ) >= $n ) {
				break;
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

	/** The text one pass is about to replace, kept for the undo. */
	private static function keep_copy( int $oid, string $type, string $html ): void {
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
		$term = get_term( $oid, 'product_cat' );
		return ( $term && ! is_wp_error( $term ) )
			? [ 'name' => (string) $term->name, 'html' => (string) $term->description, 'type' => 'term' ]
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
				$reason = $why;
				continue;
			}
			$pick = self::shortlist( $id, 1 );
			if ( ! $pick ) {
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
		if ( ! DZE_Queue::add( $job, [ $oid ], (bool) $conf['apply'], $urls ? [ 'urls' => $urls ] : [] ) ) {
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
		} elseif ( is_wp_error( wp_update_term( $oid, 'product_cat', [ 'description' => wp_kses_post( $prev ) ] ) ) ) {
			return false;
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
		$chip  = static function ( string $class, string $icon, string $text, string $tip ): string {
			return '<span class="dze-auto-chip ' . esc_attr( $class ) . '" title="' . esc_attr( $tip ) . '">'
				. '<span class="dashicons dashicons-' . esc_attr( $icon ) . '"></span>'
				. esc_html( $text ) . '</span>';
		};
		// ON, and at what rhythm. Off says only that, because a rhythm nothing
		// runs at is a figure about nothing.
		if ( ! $conf['on'] ) {
			$out .= $chip( 'is-off', 'marker', __( 'Off', 'dazont-ecom' ), __( 'Nothing runs on its own', 'dazont-ecom' ) );
		} else {
			$out .= $chip(
				'is-on',
				'controls-play',
				'month' === $conf['cadence']
					? __( 'Monthly', 'dazont-ecom' )
					/* translators: %s: how many items a day */
					: sprintf( __( '%s a day', 'dazont-ecom' ), number_format_i18n( $conf['per_day'] ) ),
				__( 'Running on its own, at this rhythm', 'dazont-ecom' )
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
					. esc_html( number_format_i18n( $orph ) ) . '</button>';
			}
		}
		// WAITING FOR A PERSON — the figure this screen exists to surface.
		$left = self::waiting_for( $id );
		if ( $left['n'] > 0 ) {
			$out .= $chip( 'is-wait', 'visibility', number_format_i18n( $left['n'] ), __( 'Waiting for your yes or no', 'dazont-ecom' ) );
		}
		// AND WHAT WENT THROUGH. A task that has never written anything says
		// nothing rather than a nought, which reads as a task that failed.
		$done = self::done_count( $id );
		if ( $done > 0 ) {
			$out .= $chip( 'is-done', 'yes', number_format_i18n( $done ), __( 'Accepted and written to the shop', 'dazont-ecom' ) );
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
		if ( ! $task || 'shop' === (string) ( $task['scope'] ?? '' ) ) {
			return 0; // the calendar writes nothing to the shop on its own.
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

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// A BODY THAT MOVES TAKES ITS ASSETS WITH IT. The chips, the folds and
		// the to-do rows are all drawn with these styles, and nothing else on
		// this page asks for them: enqueued from a page hook somewhere else,
		// the one forgotten is always the screen that comes out unstyled.
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		self::$needs_review = false;
		?>
		<div class="dze-admin dze-auto">
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_auto_options' ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::OPT ); ?>[form]" value="1" />
			<?php foreach ( self::tasks() as $id => $task ) : ?>
				<?php
				$conf  = self::conf( $id );
				$ready = self::task_ready( $id );
				$name  = self::OPT . '[tasks][' . $id . ']';
				?>
				<details class="dze-set dze-auto-task">
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
							<label>
								<input type="number" name="<?php echo esc_attr( $name ); ?>[per_day]" class="small-text" min="1" max="20" value="<?php echo (int) $conf['per_day']; ?>" />
								<?php esc_html_e( 'a day', 'dazont-ecom' ); ?>
							</label>
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
							<?php esc_html_e( 'Switch its module and Content to review back on: the work is theirs, this only decides which page gets it, and when.', 'dazont-ecom' ); ?>
						</p>
					<?php endif; ?>
					<div class="dze-auto-state" data-task="<?php echo esc_attr( $id ); ?>"><?php self::render_state( $id ); ?></div>
				</details>
			<?php endforeach; ?>
			<?php submit_button( __( 'Save', 'dazont-ecom' ) ); ?>
		</form>

		<?php
		// WHAT IS WAITING FOR YOU IS THE WORK, not a setting. It used to be
		// folded away inside each task's own controls — "plutôt que de les
		// lister dans les paramètres de l'automatisme" — so seeing what three
		// tasks had left meant opening three blocks. It is ONE list, open, on
		// the page: every list of things waiting for a decision is one list.
		?>
		<h2 class="dze-auto-h2"><?php esc_html_e( 'To review', 'dazont-ecom' ); ?></h2>
		<div id="dze-auto-waiting"><?php self::render_waiting(); ?></div>
		<?php
		// The popup those three controls open, printed by the module that owns
		// it — and only where something is actually waiting to be decided.
		if ( self::$needs_review && class_exists( 'DZE_Queue' ) && DZE_Modules::enabled( 'queue' ) ) {
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
			? add_query_arg( [ 'page' => DZE_Diagnostic::MENU_SLUG, 'tab' => 'linking' ], admin_url( 'admin.php' ) )
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
		?>
		<script>
		jQuery( function ( $ ) {
			function post( action, extra, $btn, $msg ) {
				var data = $.extend( { action: action, nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>' }, extra || {} );
				$btn.prop( 'disabled', true );
				$msg.text( '' );
				$.post( window.ajaxurl, data ).done( function ( r ) {
					$btn.prop( 'disabled', false );
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
				} ).fail( function () { $btn.prop( 'disabled', false ); } );
			}
			$( document ).on( 'click', '.dze-auto-catchup', function () {
				// A PRESS THAT SPENDS SAYS WHAT IT WILL DO before it does it.
				if ( ! window.confirm( '<?php echo esc_js( __( 'Put every page that is short of links into the writing queue? Each one is a pass of its own, and nothing is saved to the shop until you accept it.', 'dazont-ecom' ) ); ?>' ) ) { return; }
				var $b = $( this );
				post( 'dze_auto_catchup', { task: $b.data( 'task' ) }, $b, $b.siblings( '.dze-auto-msg' ) );
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


		// The whole tool rests on this list, so it is shown, not described.
		$next_up = self::shortlist( $id, 5 );
		if ( ! $next_up ) {
			echo '<p class="description">' . esc_html__( 'Nothing is short of anything right now.', 'dazont-ecom' ) . '</p>';
			return;
		}
		echo '<p class="description dze-auto-next">' . esc_html__( 'Next in line:', 'dazont-ecom' ) . ' ';
		$bits = [];
		foreach ( $next_up as $row ) {
			$name = esc_html( (string) $row['name'] );
			$url  = self::edit_url( $conf['scope'], (int) $row['tid'] );
			$bits[] = ( '' !== $url ? '<a href="' . esc_url( $url ) . '">' . $name . '</a>' : $name )
				. ' <span class="dze-auto-why">(' . esc_html( (string) $row['why'] ) . ')</span>';
		}
		echo wp_kses_post( implode( ' · ', $bits ) ) . '</p>';
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
		$aside = [];  // tasks whose work waits somewhere else entirely.
		foreach ( self::tasks() as $id => $task ) {
			$left = self::waiting_for( $id );
			if ( $left['n'] < 1 ) {
				continue;
			}
			if ( 'shop' === (string) ( $task['scope'] ?? '' ) ) {
				$aside[] = [ 'label' => (string) $task['label'], 'n' => (int) $left['n'], 'url' => (string) $left['url'] ];
				continue;
			}
			$queue += (int) $left['n'];
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
			echo '<ul class="dze-auto-todo">';
			foreach ( $rows as $row ) {
				$jid = (int) $row['id'];
				$url = self::edit_url( 0 === strpos( (string) $row['kind'], 'cat_' ) ? 'category' : 'post', (int) $row['oid'] );
				$nm  = esc_html( (string) $row['label'] );
				echo '<li class="dze-auto-job" data-id="' . esc_attr( (string) $jid ) . '">';
				echo '<span class="dze-auto-jobname">' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '">' . $nm . '</a>' : $nm ) . '</span> ';
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
		if ( $rest > 0 && class_exists( 'DZE_Queue' ) && DZE_Modules::enabled( 'queue' ) ) {
			printf(
				'<p class="dze-auto-waiting"><a href="%1$s">%2$s</a></p>',
				esc_url( DZE_Queue::url() ),
				esc_html( sprintf(
					/* translators: %s: how many more pieces of work are waiting on the review screen */
					_n( '%s more in Content to review', '%s more in Content to review', $rest, 'dazont-ecom' ),
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
		if ( 'post' === $scope ) {
			return (string) get_edit_post_link( $oid, '' );
		}
		return (string) get_edit_term_link( $oid, 'product_cat' );
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
	public static function reason_text( string $reason ): string {
		switch ( $reason ) {
			case 'queued':
				return __( 'Queued — the writing queue does it in the background.', 'dazont-ecom' );
			case 'cap':
				return __( 'Today\'s figure is used up.', 'dazont-ecom' );
			case 'none':
				return __( 'Nothing is short of anything: every page has what its size calls for, or was worked on recently.', 'dazont-ecom' );
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
	public static function past( int $limit = 200 ): array {
		if ( ! class_exists( 'DZE_Queue' ) || ! DZE_Modules::enabled( 'queue' ) ) {
			return [];
		}
		$kinds = [];
		foreach ( self::tasks() as $task ) {
			foreach ( (array) ( $task['jobs'] ?? [] ) as $k ) {
				$kinds[ (string) $k ] = true;
			}
		}
		return $kinds ? DZE_Queue::applied_rows( $limit, array_keys( $kinds ) ) : [];
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
	public static function render_past(): void {
		$rows = self::past();
		if ( ! $rows ) {
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
		echo '<th>' . esc_html__( 'Started by', 'dazont-ecom' ) . '</th>';
		echo '<th>' . esc_html__( 'Accepted by', 'dazont-ecom' ) . '</th>';
		echo '<th class="dze-auto-whenth">' . esc_html__( 'When', 'dazont-ecom' ) . '</th>';
		echo '<th class="dze-auto-actth">' . esc_html__( 'Action', 'dazont-ecom' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$kind = (string) $row['kind'];
			$oid  = (int) $row['object_id'];
			$what = 0 === strpos( $kind, 'cat_' ) ? 'term' : 'post';
			$url  = self::edit_url( 'term' === $what ? 'category' : 'post', $oid );
			$name = esc_html( class_exists( 'DZE_Queue' ) ? DZE_Queue::label_for( $kind, $oid ) : (string) $oid );
			// THE UNDO IS OFFERED WHERE IT CAN ACT: the pass keeps the text it
			// replaced only for what it saved without review, and only while
			// that pass is still in its own register.
			$can  = $oid && '' !== trim( self::copy_of( $oid, $what ) );
			echo '<tr>';
			echo '<td><strong>' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '">' . $name . '</a>' : $name ) . '</strong></td>';
			echo wp_kses_post( DZE_Hub::id_td( $oid ) );
			echo '<td>' . esc_html( (string) ( $kinds[ $kind ]['label'] ?? $kind ) ) . '</td>';
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
			'message' => self::reason_text( (string) $res['reason'] ),
			'state'   => self::block( $id ),
			'chips'   => self::chips_html( $id ),
			'waiting' => self::waiting_html(),
			'past'    => self::past_html(),
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
			return self::reason_text( (string) ( $res['reason'] ?? 'none' ) );
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
