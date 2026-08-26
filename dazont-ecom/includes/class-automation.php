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
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'schedule' ] );
		add_action( 'admin_init', [ __CLASS__, 'migrate' ] );
		add_action( 'wp_ajax_dze_auto_run', [ __CLASS__, 'ajax_run' ] );
		add_action( 'wp_ajax_dze_auto_undo', [ __CLASS__, 'ajax_undo' ] );
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
		$old = get_option( 'dze_mesh_settings', null );
		if ( null === $old ) {
			return;
		}
		$old = is_array( $old ) ? $old : [];
		$new = self::settings();
		if ( empty( $new['tasks']['cat_links'] ) ) {
			$new['tasks']['cat_links'] = [
				'on'      => empty( $old['on'] ) ? 0 : 1,
				'per_day' => max( 1, min( 20, (int) ( $old['per_day'] ?? 3 ) ) ),
				'apply'   => 1,
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
	public static function tasks(): array {
		return [
			'cat_links' => [
				'label'   => __( 'Internal links on categories', 'dazont-ecom' ),
				'what'    => __( 'Runs the "Add internal links only" pass on the category the rest of the shop points at least, among those under their link target. The text is left exactly as it is — only links are added.', 'dazont-ecom' ),
				'module'  => 'category_content',
				'scope'   => 'category',
				'kind'    => 'cat_links',
				'per_day' => 3,
				'apply'   => 1,
			],
			'post_links' => [
				'label'   => __( 'Internal links in articles and pages', 'dazont-ecom' ),
				'what'    => __( 'The same pass, the other way round: an article that points at nothing is half a mesh. Candidates are the published articles and pages carrying fewer links than their length calls for, and the targets are the product categories their subject actually touches, then the neighbouring articles. It uses the internal-linking prompt from the Categories tab.', 'dazont-ecom' ),
				'module'  => 'category_content',
				'scope'   => 'post',
				'kind'    => 'post_links',
				'per_day' => 2,
				'apply'   => 1,
			],
			'cat_desc'  => [
				'label'   => __( 'Category descriptions', 'dazont-ecom' ),
				'what'    => __( 'Writes the description of a category that has none, or one far under the length its branch deserves. A rewrite replaces the whole text, so it is held for review by default — the writing queue keeps it until you accept it.', 'dazont-ecom' ),
				'module'  => 'category_content',
				'scope'   => 'category',
				'kind'    => 'cat_desc',
				'per_day' => 1,
				'apply'   => 0,
				'kw'      => true, // may be restricted to categories with their SEMrush file.
			],
			'events'    => [
				'label'   => __( 'Marketing calendar', 'dazont-ecom' ),
				'what'    => __( 'Once a month, asks for the commercial moments worth a promotion in the coming quarter. Nothing reaches the shop: what comes back waits in the suggestion list on Marketing events, where accepting one creates the event — disabled, as always. Moments already on your calendar are not proposed again.', 'dazont-ecom' ),
				'module'  => 'marketing_ai',
				'scope'   => 'shop',
				'cadence' => 'month',
				'apply'   => 0,
			],
		];
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
		// A task that hands its work to the queue needs the queue; one that
		// runs a call of its own does not.
		if ( ! empty( $t['kind'] ) ) {
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
			foreach ( $rows as $tid => $row ) {
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
		return 'post' === $scope ? self::post_shortlist( $id, $n ) : self::cat_shortlist( $id, $n );
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
			if ( 'cat_links' === $id && $row['words'] < 120 ) {
				// Nothing to weave a link into: an empty category is the
				// writer's job, not the linker's.
				continue;
			}
			if ( self::cooling( (int) $tid, $id, 'term', (int) $row['words'], (int) $row['out'], $cool ) ) {
				continue;
			}
			$pool[] = [ 'tid' => (int) $tid, 'name' => (string) $row['name'], 'in' => (int) $row['in'], 'out' => (int) $row['out'], 'words' => (int) $row['words'] ];
		}
		if ( 'cat_links' === $id ) {
			// Least pointed at, then least pointing out: the orphans first.
			usort( $pool, static fn( $a, $b ) => [ $a['in'], $a['out'] ] <=> [ $b['in'], $b['out'] ] );
		} else {
			// Emptiest first: no description at all, then the thinnest.
			usort( $pool, static fn( $a, $b ) => $a['words'] <=> $b['words'] );
		}
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
		if ( 'cat_links' === $id ) {
			if ( ! $st['has_desc'] || $st['links_target'] < 1 || $st['links'] >= $st['links_target'] ) {
				return '';
			}
			return sprintf(
				/* translators: 1: links in the description, 2: link target */
				__( '%1$d of %2$d links', 'dazont-ecom' ),
				(int) $st['links'],
				(int) $st['links_target']
			);
		}
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
	 * @param bool   $forced Asked for by hand: the caps of the period still
	 *                       hold, the spacing inside it does not.
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
			$res = self::run( $id, (int) $pick[0]['tid'] );
			if ( $res['queued'] ) {
				return $res;
			}
			$reason = $res['reason'];
		}
		return [ 'queued' => 0, 'task' => $only, 'reason' => $reason ];
	}

	/** Sets one piece of work going, whatever kind of work it is. */
	public static function run( string $id, int $oid ): array {
		$task = self::task( $id );
		$conf = self::conf( $id );
		$no   = static fn( string $why ): array => [ 'queued' => 0, 'task' => $id, 'reason' => $why ];
		if ( ! $task ) {
			return $no( 'gone' );
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
		// What goes straight to the shop keeps the text it replaced; what waits
		// for review is undone from the review screen, which holds both.
		if ( $conf['apply'] ) {
			self::keep_copy( $oid, $sub['type'], $sub['html'] );
		}
		self::mark( $oid, $id, $sub['type'], $words, $links );
		if ( ! DZE_Queue::add( (string) $task['kind'], [ $oid ], (bool) $conf['apply'] ) ) {
			return $no( 'busy' );
		}
		self::note( $id, $oid, (string) $sub['name'], $sub['type'], $words, $links, (bool) $conf['apply'] );
		delete_transient( 'dze_auto_survey' ); // the shop is about to change.
		delete_transient( 'dze_pl_census' );
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

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="dze-admin">
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Runs the functions already on this site, on a schedule, a few items a day. It judges a page on the very figures its own panel shows — what it holds today against what it should hold — hands the writing to the queue, and never comes back to the same page within a month.', 'dazont-ecom' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_auto_options' ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::OPT ); ?>[form]" value="1" />
			<?php foreach ( self::tasks() as $id => $task ) : ?>
				<?php
				$conf  = self::conf( $id );
				$ready = self::task_ready( $id );
				$name  = self::OPT . '[tasks][' . $id . ']';
				?>
				<h2 class="title"><?php echo esc_html( (string) $task['label'] ); ?></h2>
				<p class="description" style="max-width:880px;"><?php echo esc_html( (string) $task['what'] ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Run it', 'dazont-ecom' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[on]" value="1" <?php checked( $conf['on'] ); ?> <?php disabled( ! $ready ); ?> />
								<?php esc_html_e( 'On', 'dazont-ecom' ); ?>
							</label>
							<?php if ( 'month' === $conf['cadence'] ) : ?>
								<span style="margin-left:18px;color:#646970;"><?php esc_html_e( 'once a month', 'dazont-ecom' ); ?></span>
							<?php else : ?>
								<label style="margin-left:18px;">
									<input type="number" name="<?php echo esc_attr( $name ); ?>[per_day]" class="small-text" min="1" max="20" value="<?php echo (int) $conf['per_day']; ?>" />
									<?php esc_html_e( 'per day', 'dazont-ecom' ); ?>
								</label>
							<?php endif; ?>
							<?php if ( 'shop' !== $conf['scope'] ) : ?>
								<label style="margin-left:18px;">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[apply]" value="1" <?php checked( $conf['apply'] ); ?> />
									<?php esc_html_e( 'Save it on the shop straight away', 'dazont-ecom' ); ?>
								</label>
							<?php endif; ?>
							<?php if ( ! empty( $task['kw'] ) ) : ?>
								<br />
								<label style="display:inline-block;margin-top:8px;">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[kw_only]" value="1" <?php checked( $conf['kw_only'] ); ?> />
									<?php esc_html_e( 'Only categories that have their SEMrush file', 'dazont-ecom' ); ?>
								</label>
							<?php endif; ?>
							<p class="description">
								<?php
								if ( 'shop' === $conf['scope'] ) {
									esc_html_e( 'Nothing here reaches the shop on its own: what comes back waits for your yes or no.', 'dazont-ecom' );
								} elseif ( $conf['apply'] ) {
									esc_html_e( 'Unticked, the result waits under "to review" in the writing queue instead of going live.', 'dazont-ecom' );
								} else {
									esc_html_e( 'Ticked, the result goes live without being read first. Left as it is, it waits under "to review" in the writing queue.', 'dazont-ecom' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'State', 'dazont-ecom' ); ?></th>
						<td>
							<div class="dze-auto-state" data-task="<?php echo esc_attr( $id ); ?>"><?php self::render_state( $id ); ?></div>
							<p style="margin:10px 0 0;">
								<button type="button" class="button dze-auto-run" data-task="<?php echo esc_attr( $id ); ?>" <?php disabled( ! $ready ); ?>><?php esc_html_e( 'Run one now', 'dazont-ecom' ); ?></button>
								<span class="dze-auto-msg" style="margin-left:8px;font-size:13px;"></span>
							</p>
							<?php if ( ! $ready ) : ?>
								<p class="description" style="color:#b32d2e;">
									<?php esc_html_e( 'Needs its own module and the Writing queue: the work is theirs, this only decides which page gets it, and when.', 'dazont-ecom' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			<?php endforeach; ?>
			<?php submit_button( __( 'Save automation settings', 'dazont-ecom' ) ); ?>
		</form>

		<h2 class="title"><?php esc_html_e( 'What it has done', 'dazont-ecom' ); ?></h2>
		<div id="dze-auto-log"><?php self::render_log(); ?></div>
		</div>
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
					if ( d.log ) { $( '#dze-auto-log' ).html( d.log ); }
					// Nothing happening is an answer too, and it says which one.
					if ( d.message ) { $msg.text( d.message ).css( 'color', d.queued ? '#0a7040' : '#646970' ); }
				} ).fail( function () { $btn.prop( 'disabled', false ); } );
			}
			$( document ).on( 'click', '.dze-auto-run', function () {
				var $b = $( this );
				post( 'dze_auto_run', { task: $b.data( 'task' ) }, $b, $b.siblings( '.dze-auto-msg' ) );
			} );
			$( document ).on( 'click', '.dze-auto-undo', function () {
				var $b = $( this );
				post( 'dze_auto_undo', { term: $b.data( 'term' ), what: $b.data( 'what' ) }, $b, $b.closest( 'li' ).find( '.dze-auto-msg' ) );
			} );
		} );
		</script>
		<?php
	}

	/** Where one task stands, and what it is about to take. */
	public static function render_state( string $id ): void {
		$conf = self::conf( $id );
		$next = wp_next_scheduled( self::HOOK );
		echo '<p style="margin:0;">';
		if ( 'month' === $conf['cadence'] ) {
			$last = self::last_run( $id );
			echo esc_html( $last
				/* translators: %s: how long ago */
				? sprintf( __( 'Last run %s ago.', 'dazont-ecom' ), human_time_diff( $last, time() ) )
				: __( 'Never run yet.', 'dazont-ecom' ) );
			if ( $conf['on'] ) {
				echo ' ' . esc_html( $last && $last + self::gap( $id ) > time()
					/* translators: %s: how long until the next run */
					? sprintf( __( 'Next in %s.', 'dazont-ecom' ), human_time_diff( time(), $last + self::gap( $id ) ) )
					: __( 'Due now.', 'dazont-ecom' ) );
			}
		} else {
			printf(
				/* translators: 1: passes made today, 2: the daily figure */
				esc_html__( 'Today: %1$s of %2$s.', 'dazont-ecom' ),
				'<strong>' . esc_html( number_format_i18n( self::done_today( $id ) ) ) . '</strong>',
				esc_html( number_format_i18n( $conf['per_day'] ) )
			);
			if ( $conf['on'] && $next ) {
				echo ' ';
				printf(
					/* translators: %s: human-readable delay, e.g. "35 mins" */
					esc_html__( 'Next look in %s.', 'dazont-ecom' ),
					esc_html( human_time_diff( time(), (int) $next ) )
				);
			}
		}
		if ( ! $conf['on'] ) {
			echo ' ' . esc_html__( 'Off — nothing runs on its own.', 'dazont-ecom' );
		}
		echo '</p>';

		// The whole tool rests on this list, so it is shown, not described.
		$next_up = self::shortlist( $id, 5 );
		if ( ! $next_up ) {
			echo '<p class="description" style="margin:6px 0 0;">' . esc_html__( 'Nothing is short of anything right now.', 'dazont-ecom' ) . '</p>';
			return;
		}
		echo '<p class="description" style="margin:6px 0 0;">' . esc_html__( 'Next in line:', 'dazont-ecom' ) . ' ';
		$bits = [];
		foreach ( $next_up as $row ) {
			$name = esc_html( (string) $row['name'] );
			$url  = self::edit_url( $conf['scope'], (int) $row['tid'] );
			$bits[] = ( '' !== $url ? '<a href="' . esc_url( $url ) . '">' . $name . '</a>' : $name )
				. ' <span style="color:#a7aaad;">(' . esc_html( (string) $row['why'] ) . ')</span>';
		}
		echo wp_kses_post( implode( ' · ', $bits ) ) . '</p>';
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

	public static function render_log(): void {
		$log = (array) ( self::state()['log'] ?? [] );
		if ( ! $log ) {
			echo '<p class="description">' . esc_html__( 'Nothing has been done yet.', 'dazont-ecom' ) . '</p>';
			return;
		}
		$tasks = self::tasks();
		echo '<ul style="margin:0;">';
		foreach ( $log as $row ) {
			$oid  = (int) ( $row['tid'] ?? 0 );
			$id   = (string) ( $row['task'] ?? '' );
			$what = (string) ( $row['what'] ?? 'term' );
			$und  = ! empty( $row['undone'] );
			$can  = ! $und && $oid && '' !== trim( self::copy_of( $oid, $what ) );
			$url  = self::edit_url( 'post' === $what ? 'post' : 'category', $oid );
			$name = esc_html( (string) ( $row['name'] ?? '' ) );
			echo '<li style="margin:0 0 5px;">';
			echo '<strong>' . esc_html( (string) ( $tasks[ $id ]['label'] ?? $id ) ) . '</strong> · ';
			echo '' !== $url ? '<a href="' . esc_url( $url ) . '">' . $name . '</a> ' : $name . ' ';
			echo '<span class="description">';
			printf(
				/* translators: %s: how long ago */
				esc_html__( '%s ago', 'dazont-ecom' ),
				esc_html( human_time_diff( (int) ( $row['at'] ?? time() ), time() ) )
			);
			echo ' · ' . esc_html( self::outcome( $row ) );
			echo '</span>';
			if ( $can ) {
				echo ' <button type="button" class="button-link dze-auto-undo" data-term="' . esc_attr( (string) $oid ) . '" data-what="' . esc_attr( $what ) . '">&#8634; ' . esc_html__( 'Undo', 'dazont-ecom' ) . '</button>';
			}
			echo ' <span class="dze-auto-msg" style="font-size:12px;"></span>';
			echo '</li>';
		}
		echo '</ul>';
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
		if ( in_array( $id, [ 'cat_links', 'post_links' ], true ) && $links > (int) ( $row['links'] ?? 0 ) ) {
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

	private static function log_html(): string {
		ob_start();
		self::render_log();
		return (string) ob_get_clean();
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
			'log'     => self::log_html(),
		] );
	}

	public static function ajax_undo(): void {
		self::guard();
		$tid  = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : 'term';
		if ( ! $tid || ! self::undo( $tid, 'post' === $what ? 'post' : 'term' ) ) {
			wp_send_json_error( [
				'message' => __( 'Nothing to put back on this category.', 'dazont-ecom' ),
				'log'     => self::log_html(),
			] );
		}
		wp_send_json_success( [
			'message' => __( 'Put back.', 'dazont-ecom' ),
			'log'     => self::log_html(),
		] );
	}
}
