<?php
defined( 'ABSPATH' ) || exit;

/**
 * Automation — the shop's own functions, run on a schedule, a few a day.
 *
 * It adds no ability of its own. Everything it launches is a function that is
 * already on the site, with its own screen, its own prompt and its own button:
 * the "Add internal links only" pass, the category writer. What this module
 * brings is the part a person cannot do at scale — looking at the WHOLE
 * catalogue, seeing where each page falls short of what it should be, and
 * working through the list a little at a time.
 *
 * Three rules hold it together:
 *
 * 1. It judges from the figures the owner sees. The panel on a category says
 *    "1116 words, 0 links — target 750 words and 5 links". Those figures come
 *    from DZE_Category_Content::state(), and that is what this module reads.
 *    Never a second reckoning that could disagree with the screen.
 * 2. It runs nothing itself. Work is handed to the writing queue — one job at
 *    a time, in the background, with its recovery and the monthly budget cap.
 * 3. It goes slowly, on purpose. One item per pass, spread over the day, a
 *    figure per day per task, and never the same category twice in a month.
 *    A catalogue whose fifty pages all change on the same afternoon does not
 *    look like a catalogue being looked after.
 *
 * Adding a task is adding a row to tasks(): what it is called, which module
 * owns it, which queue job it hands over, how a candidate is recognised.
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
				'kind'    => 'cat_links',
				'per_day' => 3,
				'apply'   => 1,
			],
			'cat_desc'  => [
				'label'   => __( 'Category descriptions', 'dazont-ecom' ),
				'what'    => __( 'Writes the description of a category that has none, or one far under the length its branch deserves. A rewrite replaces the whole text, so it is held for review by default — the writing queue keeps it until you accept it.', 'dazont-ecom' ),
				'module'  => 'category_content',
				'kind'    => 'cat_desc',
				'per_day' => 1,
				'apply'   => 0,
				'kw'      => true, // may be restricted to categories with their SEMrush file.
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
		if ( ! $t || ! class_exists( 'DZE_Queue' ) || ! class_exists( 'DZE_Category_Content' ) ) {
			return false;
		}
		if ( ! class_exists( 'DZE_Modules' ) ) {
			return true;
		}
		return DZE_Modules::enabled( 'queue' ) && DZE_Modules::enabled( (string) $t['module'] );
	}

	/** The saved settings of one task, filled in from its defaults. */
	public static function conf( string $id ): array {
		$t = self::task( $id );
		$c = (array) ( self::settings()['tasks'][ $id ] ?? [] );
		return [
			'on'      => ! empty( $c['on'] ),
			'per_day' => max( 1, min( 20, (int) ( $c['per_day'] ?? $t['per_day'] ?? 1 ) ) ),
			'apply'   => array_key_exists( 'apply', $c ) ? ! empty( $c['apply'] ) : ! empty( $t['apply'] ),
			'kw_only' => array_key_exists( 'kw_only', $c ) ? ! empty( $c['kw_only'] ) : ! empty( $t['kw'] ),
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
		return (int) max( HOUR_IN_SECONDS, floor( DAY_IN_SECONDS / max( 1, self::conf( $id )['per_day'] ) ) );
	}

	/** Passes this task made today — the cap is a day's worth, not a run's. */
	public static function done_today( string $id ): int {
		$s = self::state();
		if ( (string) ( $s['day'] ?? '' ) !== current_time( 'Y-m-d' ) ) {
			return 0;
		}
		return (int) ( ( (array) ( $s['count'] ?? [] ) )[ $id ] ?? 0 );
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
	 * The categories a task may work on, worst first.
	 *
	 * Cheap ranking, then the real figures on the head of the list only. The
	 * screen shows the same list the tick takes from, so what the owner reads
	 * is what will happen.
	 *
	 * @return array<int,array{tid:int,name:string,why:string}>
	 */
	public static function shortlist( string $id, int $n = 5 ): array {
		$rows = self::survey()['rows'];
		if ( ! $rows || ! self::task( $id ) || ! self::task_ready( $id ) ) {
			// Its module is off: the queue table may not even exist, and there
			// is nothing this task could be about to do anyway.
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
			$seen = self::seen( (int) $tid, $id );
			if ( $seen && (int) $seen['t'] > $cool ) {
				// A pass that changed nothing — a failed job, or a model that
				// found no room — must not lock the page out for a month.
				$moved = (int) $row['words'] !== (int) ( $seen['w'] ?? 0 ) || (int) $row['out'] !== (int) ( $seen['l'] ?? 0 );
				if ( $moved || (int) $seen['t'] > time() - self::RETRY * DAY_IN_SECONDS ) {
					continue;
				}
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

	/** What a task last did to a term: when, and the figures it left behind. */
	private static function seen( int $tid, string $id ): array {
		$all = get_term_meta( $tid, self::META_SEEN, true );
		$all = is_array( $all ) ? $all : [];
		$row = (array) ( $all[ $id ] ?? [] );
		return isset( $row['t'] ) ? $row : [];
	}

	private static function mark( int $tid, string $id, int $words, int $links ): void {
		$all = get_term_meta( $tid, self::META_SEEN, true );
		$all = is_array( $all ) ? $all : [];
		$all[ $id ] = [ 't' => time(), 'w' => $words, 'l' => $links ];
		update_term_meta( $tid, self::META_SEEN, $all );
	}

	// =========================================================================
	// The tick
	// =========================================================================

	/**
	 * One item, once an hour, task by task.
	 *
	 * @param string $only   Run this task alone (the button on its block).
	 * @param bool   $forced Asked for by hand: the daily figure still holds,
	 *                       the spacing between two passes does not.
	 *
	 * @return array{queued:int,task:string,reason:string}
	 */
	public static function tick( string $only = '', bool $forced = false ): array {
		$no  = static fn( string $why, string $task = '' ): array => [ 'queued' => 0, 'task' => $task, 'reason' => $why ];
		$ids = '' !== $only ? [ $only ] : array_keys( self::tasks() );
		$last_reason = 'none';
		foreach ( $ids as $id ) {
			if ( ! self::task( $id ) ) {
				continue;
			}
			if ( ! self::task_ready( $id ) ) {
				$last_reason = 'modules';
				continue;
			}
			if ( ! self::conf( $id )['on'] && ! $forced ) {
				$last_reason = 'off';
				continue;
			}
			if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
				return $no( 'budget', $id );
			}
			if ( self::done_today( $id ) >= self::conf( $id )['per_day'] ) {
				$last_reason = 'cap';
				continue;
			}
			$s    = self::state();
			$last = (int) ( ( (array) ( $s['last'] ?? [] ) )[ $id ] ?? 0 );
			if ( ! $forced && time() - $last < self::gap( $id ) ) {
				// Spread over the day rather than run off in the first hour:
				// three a day is one every eight hours.
				$last_reason = 'early';
				continue;
			}
			$pick = self::shortlist( $id, 1 );
			if ( ! $pick ) {
				$last_reason = 'none';
				continue;
			}
			$res = self::run( $id, (int) $pick[0]['tid'] );
			if ( $res['queued'] ) {
				return $res;
			}
			$last_reason = $res['reason'];
		}
		return $no( $last_reason, '' !== $only ? $only : '' );
	}

	/** Hands one category to the queue for one task. */
	public static function run( string $id, int $tid ): array {
		$conf = self::conf( $id );
		$task = self::task( $id );
		$term = get_term( $tid, 'product_cat' );
		if ( ! $task || ! $term || is_wp_error( $term ) ) {
			return [ 'queued' => 0, 'task' => $id, 'reason' => 'gone' ];
		}
		$desc  = (string) $term->description;
		$words = str_word_count( wp_strip_all_tags( $desc ) );
		$links = (int) preg_match_all( '/<a\s[^>]*href=/i', $desc );
		// What is applied straight to the shop keeps the text it replaced; what
		// waits for review is undone from the review screen, which holds both.
		if ( $conf['apply'] ) {
			update_term_meta( $tid, self::META_PREV, $desc );
		}
		self::mark( $tid, $id, $words, $links );
		if ( ! DZE_Queue::add( (string) $task['kind'], [ $tid ], (bool) $conf['apply'] ) ) {
			return [ 'queued' => 0, 'task' => $id, 'reason' => 'busy' ];
		}
		self::note( $id, $tid, (string) $term->name, $words, $links, (bool) $conf['apply'] );
		delete_transient( 'dze_auto_survey' ); // the shop is about to change.
		return [ 'queued' => 1, 'task' => $id, 'reason' => 'queued' ];
	}

	/** Files one pass in the log, and keeps the undo of the last ones only. */
	private static function note( string $id, int $tid, string $name, int $words, int $links, bool $applied ): void {
		$s     = self::state();
		$today = current_time( 'Y-m-d' );
		$fresh = (string) ( $s['day'] ?? '' ) === $today;
		$log   = (array) ( $s['log'] ?? [] );
		array_unshift( $log, [
			'task'  => $id,
			'tid'   => $tid,
			'name'  => $name,
			'at'    => time(),
			'words' => $words,
			'links' => $links,
			'auto'  => $applied ? 1 : 0,
		] );
		$kept  = array_slice( $log, 0, self::KEEP );
		$alive = array_map( 'intval', array_column( $kept, 'tid' ) );
		foreach ( array_slice( $log, self::KEEP ) as $old ) {
			// Out of the log, out of the database: a copy kept for an undo
			// nobody can reach any more is dead weight. Unless the same
			// category came back higher up — that copy is the live one.
			if ( ! in_array( (int) $old['tid'], $alive, true ) ) {
				delete_term_meta( (int) $old['tid'], self::META_PREV );
			}
		}
		$count = $fresh ? (array) ( $s['count'] ?? [] ) : [];
		$count[ $id ] = (int) ( $count[ $id ] ?? 0 ) + 1;
		$lastm = (array) ( $s['last'] ?? [] );
		$lastm[ $id ] = time();
		self::save_state( [ 'day' => $today, 'count' => $count, 'last' => $lastm, 'log' => $kept ] );
	}

	/** Puts back the description an automatic pass replaced. */
	public static function undo( int $tid ): bool {
		$prev = (string) get_term_meta( $tid, self::META_PREV, true );
		if ( '' === trim( $prev ) ) {
			return false;
		}
		if ( is_wp_error( wp_update_term( $tid, 'product_cat', [ 'description' => wp_kses_post( $prev ) ] ) ) ) {
			return false;
		}
		delete_term_meta( $tid, self::META_PREV );
		$s   = self::state();
		$log = (array) ( $s['log'] ?? [] );
		foreach ( $log as $i => $row ) {
			if ( (int) ( $row['tid'] ?? 0 ) === $tid ) {
				$log[ $i ]['undone'] = 1;
			}
		}
		$s['log'] = $log;
		self::save_state( $s );
		delete_transient( 'dze_auto_survey' );
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
			<?php esc_html_e( 'Runs the functions already on this site, on a schedule, a few items a day. It judges a page on the very figures its own panel shows — what it holds today against what it should hold — hands the work to the writing queue, and never touches the same category twice in a month.', 'dazont-ecom' ); ?>
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
							<label style="margin-left:18px;">
								<input type="number" name="<?php echo esc_attr( $name ); ?>[per_day]" class="small-text" min="1" max="20" value="<?php echo (int) $conf['per_day']; ?>" />
								<?php esc_html_e( 'per day', 'dazont-ecom' ); ?>
							</label>
							<label style="margin-left:18px;">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[apply]" value="1" <?php checked( $conf['apply'] ); ?> />
								<?php esc_html_e( 'Save it on the shop straight away', 'dazont-ecom' ); ?>
							</label>
							<?php if ( ! empty( $task['kw'] ) ) : ?>
								<br />
								<label style="display:inline-block;margin-top:8px;">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[kw_only]" value="1" <?php checked( $conf['kw_only'] ); ?> />
									<?php esc_html_e( 'Only categories that have their SEMrush file', 'dazont-ecom' ); ?>
								</label>
							<?php endif; ?>
							<p class="description">
								<?php echo $conf['apply']
									? esc_html__( 'Unticked, the result waits under "to review" in the writing queue instead of going live.', 'dazont-ecom' )
									: esc_html__( 'Ticked, the result goes live without being read first. Left as it is, it waits under "to review" in the writing queue.', 'dazont-ecom' ); ?>
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
				post( 'dze_auto_undo', { term: $b.data( 'term' ) }, $b, $b.closest( 'li' ).find( '.dze-auto-msg' ) );
			} );
		} );
		</script>
		<?php
	}

	/** Where one task stands, and what it is about to take. */
	public static function render_state( string $id ): void {
		$conf = self::conf( $id );
		$done = self::done_today( $id );
		$next = wp_next_scheduled( self::HOOK );
		echo '<p style="margin:0;">';
		printf(
			/* translators: 1: passes made today, 2: the daily figure */
			esc_html__( 'Today: %1$s of %2$s.', 'dazont-ecom' ),
			'<strong>' . esc_html( number_format_i18n( $done ) ) . '</strong>',
			esc_html( number_format_i18n( $conf['per_day'] ) )
		);
		if ( $conf['on'] && $next ) {
			echo ' ';
			printf(
				/* translators: %s: human-readable delay, e.g. "35 mins" */
				esc_html__( 'Next look in %s.', 'dazont-ecom' ),
				esc_html( human_time_diff( time(), (int) $next ) )
			);
		} elseif ( ! $conf['on'] ) {
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
			$bits[] = '<a href="' . esc_url( (string) get_edit_term_link( (int) $row['tid'], 'product_cat' ) ) . '">' . esc_html( (string) $row['name'] ) . '</a>'
				. ' <span style="color:#a7aaad;">(' . esc_html( (string) $row['why'] ) . ')</span>';
		}
		echo wp_kses_post( implode( ' · ', $bits ) ) . '</p>';
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
			$tid  = (int) ( $row['tid'] ?? 0 );
			$id   = (string) ( $row['task'] ?? '' );
			$st   = class_exists( 'DZE_Category_Content' ) ? DZE_Category_Content::state( $tid ) : null;
			$und  = ! empty( $row['undone'] );
			$can  = ! $und && '' !== trim( (string) get_term_meta( $tid, self::META_PREV, true ) );
			echo '<li style="margin:0 0 5px;">';
			echo '<strong>' . esc_html( (string) ( $tasks[ $id ]['label'] ?? $id ) ) . '</strong> · ';
			echo '<a href="' . esc_url( (string) get_edit_term_link( $tid, 'product_cat' ) ) . '">' . esc_html( (string) ( $row['name'] ?? '' ) ) . '</a> ';
			echo '<span class="description">';
			printf(
				/* translators: %s: how long ago */
				esc_html__( '%s ago', 'dazont-ecom' ),
				esc_html( human_time_diff( (int) ( $row['at'] ?? time() ), time() ) )
			);
			if ( $und ) {
				echo ' · ' . esc_html__( 'put back', 'dazont-ecom' );
			} elseif ( empty( $row['auto'] ) ) {
				echo ' · ' . esc_html__( 'waiting for you to review it', 'dazont-ecom' );
			} elseif ( $st && 'cat_links' === $id && $st['links'] > (int) ( $row['links'] ?? 0 ) ) {
				/* translators: 1: links before, 2: links now */
				echo ' · ' . esc_html( sprintf( __( '%1$d → %2$d links', 'dazont-ecom' ), (int) $row['links'], (int) $st['links'] ) );
			} elseif ( $st && 'cat_desc' === $id && $st['words'] !== (int) ( $row['words'] ?? 0 ) ) {
				/* translators: 1: words before, 2: words now */
				echo ' · ' . esc_html( sprintf( __( '%1$d → %2$d words', 'dazont-ecom' ), (int) $row['words'], (int) $st['words'] ) );
			} else {
				echo ' · ' . esc_html__( 'waiting for the queue', 'dazont-ecom' );
			}
			echo '</span>';
			if ( $can ) {
				echo ' <button type="button" class="button-link dze-auto-undo" data-term="' . esc_attr( (string) $tid ) . '">&#8634; ' . esc_html__( 'Undo', 'dazont-ecom' ) . '</button>';
			}
			echo ' <span class="dze-auto-msg" style="font-size:12px;"></span>';
			echo '</li>';
		}
		echo '</ul>';
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
		$tid = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		if ( ! $tid || ! self::undo( $tid ) ) {
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
