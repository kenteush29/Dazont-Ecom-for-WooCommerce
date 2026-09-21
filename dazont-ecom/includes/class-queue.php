<?php
defined( 'ABSPATH' ) || exit;

/**
 * Content to review — send a batch off to be written, review it, apply it.
 *
 * Why it exists: a description of two thousand words takes the model a minute
 * or more, and a browser request that waits that long is cut off by the host
 * (the 504 seen on this shop). The work therefore never happens inside the
 * request that asks for it. A job is queued, a background worker takes one at
 * a time, and the screen only watches.
 *
 * Two ways to run, one code path: leave the page and it keeps going (Action
 * Scheduler when WooCommerce provides it, WP-Cron otherwise), or stay on the
 * queue screen and watch the items go by one by one.
 *
 * Nothing is written to the shop until it is accepted, unless the batch was
 * explicitly sent with "apply as soon as it is written".
 */
final class DZE_Queue {

	public const NONCE      = 'dze_queue';
	public const MENU_SLUG  = 'dazont-ecom-queue';
	public const HOOK       = 'dze_queue_work';
	private const SCHEMA_OPT     = 'dze_queue_schema';
	private const SCHEMA_VERSION = 3;
	private const LOCK      = 'dze_queue_lock';
	/**
	 * Why the last write was refused. Written by every `apply()` call, so it
	 * describes the one that has just happened and never an older one.
	 */
	private static $refused = '';
	/**
	 * How long one step is given before the run that took the writer is
	 * presumed gone. Longer than any single step — a model answering slowly is
	 * a minute, not four — and short enough that a shop is not barred for the
	 * whole of the lock's own five minutes by a worker the host killed.
	 */
	private const STEP_BUDGET = 4 * MINUTE_IN_SECONDS;
	private const COUNT_KEY = 'dze_queue_review_count';

	/** Job kinds: what each one writes, and who knows how to write it. */
	public static function kinds(): array {
		return [
			// Two names each: what the job IS, for a list of jobs, and what it
			// DOES, for a button. "Fix" told the shop nothing about what was
			// about to run.
			'cat_desc'  => [
				'label'  => __( 'Category description', 'dazont-ecom' ),
				'does'   => __( 'Write the description', 'dazont-ecom' ),
				'module' => 'category_content',
			],
			'cat_links' => [
				'label'  => __( 'Category internal links', 'dazont-ecom' ),
				'does'   => __( 'Add internal links', 'dazont-ecom' ),
				'module' => 'category_content',
			],
			'post_links' => [
				'label'  => __( 'Article internal links', 'dazont-ecom' ),
				'does'   => __( 'Add internal links', 'dazont-ecom' ),
				'module' => 'automation',
			],
			// A photograph is not text, so its review is not a diff: the row
			// shows the picture, and accepting it files it on the product.
			// Nothing about the product changes before that click.
			'product_shot' => [
				'label'  => __( 'Product photograph', 'dazont-ecom' ),
				'does'   => __( 'Make a photograph', 'dazont-ecom' ),
				'module' => 'content',
				'image'  => true,
			],
		];
	}

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::HOOK, [ __CLASS__, 'work' ] );
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init', [ $this, 'maybe_install' ] );
		add_action( 'admin_menu', [ $this, 'menu' ], 21 ); // right after Products AI bulk.
		add_action( 'admin_init', [ $this, 'moved' ] );
		add_action( 'wp_ajax_dze_q_status', [ $this, 'ajax_status' ] );
		add_action( 'wp_ajax_dze_q_run', [ $this, 'ajax_run' ] );
		add_action( 'wp_ajax_dze_q_review', [ $this, 'ajax_review' ] );
		add_action( 'wp_ajax_dze_q_decide', [ $this, 'ajax_decide' ] );
		add_action( 'wp_ajax_dze_q_clear', [ $this, 'ajax_clear' ] );
		add_action( 'wp_ajax_dze_q_add', [ $this, 'ajax_add' ] );
		add_action( 'wp_ajax_dze_q_job', [ $this, 'ajax_job' ] );
		// UN VRAI LIEN, PAS UN ONGLET VIDE QU'ON REMPLIT APRÈS.
		//
		// « about:blank ». Le bouton ouvrait un onglet vierge puis lui donnait
		// son adresse une fois la réponse revenue — donc une page blanche
		// pendant le travail, et une page blanche pour toujours si quoi que ce
		// soit échouait. Un lien ordinaire n'a pas ce défaut : le navigateur
		// charge une adresse dès le clic et montre son propre chargement.
		add_action( 'admin_post_dze_q_preview', [ $this, 'preview_page' ] );
		add_action( 'wp_ajax_dze_q_action', [ $this, 'ajax_job_action' ] );
		add_action( 'wp_ajax_dze_q_bulk', [ $this, 'ajax_bulk' ] );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dze_queue';
	}

	public function maybe_install(): void {
		if ( (int) get_option( self::SCHEMA_OPT, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kind VARCHAR(32) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'queued',
			auto_apply TINYINT(1) NOT NULL DEFAULT 0,
			payload LONGTEXT NULL,
			result LONGTEXT NULL,
			error TEXT NULL,
			created DATETIME NOT NULL,
			updated DATETIME NOT NULL,
			-- WHO SAID YES OR NO. Nothing recorded it, so a shop with more
			-- than one pair of hands could see that a page had been dealt
			-- with and never by whom — which is the first thing anybody asks
			-- once the work is handed to somebody else. 0 means the plugin
			-- itself: a task that saves without review has nobody to name.
			decided_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			-- AND WHO ASKED FOR IT. The decider was recorded and the
			-- person who started the work was not, so a row that came back
			-- wrong could be traced to whoever accepted it and never to
			-- whoever ordered it. It is read from the current user at the
			-- moment the job is inserted, so every path in the plugin fills
			-- it without being told — and 0 is the scheduled pass, which is
			-- an ORIGIN and not a person: naming it says where the job came
			-- from, it does not invent somebody.
			made_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY object (kind,object_id)
		) {$charset};" );
		update_option( self::SCHEMA_OPT, self::SCHEMA_VERSION, false );
	}

	// =========================================================================
	// Queueing
	// =========================================================================

	/**
	 * Adds jobs, skipping what is already waiting for the same thing.
	 *
	 * @return int number of jobs actually added.
	 */
	public static function add( string $kind, array $ids, bool $auto_apply = false, array $payload = [] ): int {
		self::forget_count();
		global $wpdb;
		if ( ! isset( self::kinds()[ $kind ] ) ) {
			return 0;
		}
		$table = self::table();
		$now   = current_time( 'mysql' );
		$added = 0;
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( ! $id ) {
				continue;
			}
			$busy = (int) $wpdb->get_var( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
				"SELECT COUNT(*) FROM {$table} WHERE kind = %s AND object_id = %d AND status IN ('queued','running','review')",
				$kind,
				$id
			) );
			if ( $busy ) {
				continue;
			}
			$wpdb->insert( $table, [
				'kind'       => $kind,
				'object_id'  => $id,
				'status'     => 'queued',
				'auto_apply' => $auto_apply ? 1 : 0,
				// Whoever pressed it, read here rather than passed in by every
				// caller: a list of writers somebody keeps in step always has
				// one forgotten entry, and in cron there is no user, which is
				// exactly the answer.
				'made_by'    => self::decider(),
				'payload'    => $payload ? wp_json_encode( $payload ) : null,
				'created'    => $now,
				'updated'    => $now,
			] );
			$added++;
		}
		if ( $added ) {
			// What is waiting on an object has just changed, and the pass that
			// asks is the one deciding whether to queue it a second time.
			self::forget_count();
			self::kick();
		}
		return $added;
	}

	/** Asks for the worker to run, without anybody waiting for it. */
	public static function kick(): void {
		// A PASS ALREADY WAITING ITS TURN NEEDS NOTHING ADDED; a pass Action
		// Scheduler believes is RUNNING may be a worker the host killed, and
		// `as_has_scheduled_action()` cannot tell those two apart — it answers
		// true for both. Returning on it made every later kick a no-op, with
		// the loopback below skipped too, so a wedged scheduler stopped the
		// queue for good. `as_next_scheduled_action()` does tell them apart: a
		// timestamp is one waiting, true is one in progress.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$next = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( self::HOOK ) : false;
			if ( false === $next ) {
				as_enqueue_async_action( self::HOOK, [], 'dazont-ecom' );
				return;
			}
			if ( true !== $next ) {
				return; // one is waiting its turn.
			}
			// One is in progress, or was. The loopback below costs a
			// non-blocking request and the writer's own lock makes it harmless:
			// a step that is genuinely running answers it in microseconds.
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
		// And a fire-and-forget request so the first item starts now rather than
		// at the next cron tick. Nothing waits on the answer.
		wp_remote_post( admin_url( 'admin-ajax.php' ), [
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => false,
			'body'      => [ 'action' => 'dze_q_run', 'nonce' => wp_create_nonce( self::NONCE ) ],
			'cookies'   => $_COOKIE, // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		] );
	}

	// =========================================================================
	// The worker
	// =========================================================================

	/**
	 * Takes ONE STEP of one job — a plan, or a single section — and hands over.
	 *
	 * A step, not a job: writing a long description in one request means a
	 * request of several minutes, and a host kills that whatever PHP is told.
	 * Every step here finishes in seconds, saves what it produced, and asks for
	 * the next one. Nothing is ever lost to a timeout.
	 */
	public static function work( int $only = 0 ): void {
		global $wpdb;
		$table = self::table();
		self::recover();
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, time(), 5 * MINUTE_IN_SECONDS );

		// THE JOB SOMEBODY IS WATCHING IS THE JOB THAT MOVES. A screen polling
		// its own run called work(), which took the OLDEST job in the whole
		// queue — so a run somebody was standing in front of could sit at
		// "waiting for the writer… 33s" while every one of its polls stepped
		// something else entirely: "et puis c'est bugé, il ne se passe encore
		// absolument rien." Asked for one job, it takes that one; asked for
		// nothing — cron, the kick — it takes the queue in order, as before.
		// A ROW SOMEBODY IS STILL WORKING ON IS NOT FREE. `running` with a
		// stamp younger than the step budget means a run has it and has not
		// come back yet; older than that, `recover()` above has already put it
		// back to `queued`. Without this the lock was the only guard, and the
		// lock is deliberately let go by age.
		$fresh = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::STEP_BUDGET ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- matches the stored site time.
		$job   = $only
			? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND ( status = 'queued' OR ( status = 'running' AND updated < %s ) )", $only, $fresh ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			: $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'queued' OR ( status = 'running' AND updated < %s ) ORDER BY FIELD(status,'running','queued'), id ASC LIMIT 1", $fresh ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		if ( ! $job ) {
			delete_transient( self::LOCK );
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- nobody is waiting on this request.
		}
		$id      = (int) $job['id'];
		$payload = $job['payload'] ? (array) json_decode( (string) $job['payload'], true ) : [];
		$done    = false;
		$err     = '';
		$result  = (string) ( $job['result'] ?? '' );

		// A JOB IS CLAIMED BEFORE IT IS WORKED, OR IT OUTLIVES THE WORKER.
		// "Nothing has moved for 31 minutes. The writer may be held by a run
		// the server stopped." — 0%, 0 of 1, on a screen polling every second
		// and a half for half an hour.
		//
		// Nothing was written between taking the row and finishing the job, so
		// a request that died mid-call — the host's own time limit on a slow
		// model answer, a 502 — left the row exactly as it found it: still
		// `queued`, its `updated` untouched, the writer's lock standing.
		// `recover()` only looks at `running` rows and could not see it, so no
		// try was counted and nothing ever failed; the lock went by age four
		// minutes later, the next poll took the SAME job, and it died the same
		// way. A one-step kind never passes through `running` on its own, so
		// that loop had no end: the head of the queue was immortal and every
		// figure on the screen was frozen because `updated` never moved.
		//
		// Claiming costs one write and answers all of it: the row says a run
		// has it, the stamp moves on every attempt, and the try is counted
		// where `recover()` reads it — so three deaths fail the job with a
		// sentence a person can read and the queue goes on to the next page.
		$payload['tries'] = (int) ( $payload['tries'] ?? 0 ) + 1;
		$wpdb->update( $table, [
			'status'  => 'running',
			'payload' => wp_json_encode( $payload ),
			'updated' => current_time( 'mysql' ),
		], [ 'id' => $id ] );

		try {
			if ( 'cat_desc' === $job['kind'] ) {
				// Step 0 is the plan, then one step per section.
				if ( empty( $payload['plan'] ) ) {
					$payload['plan'] = DZE_Category_Content::plan( (int) $job['object_id'], (string) ( $payload['prompt'] ?? '' ) );
					$payload['step'] = -1; // the opening comes next.
				} else {
					$step  = (int) ( $payload['step'] ?? -1 );
					$piece = DZE_Category_Content::write_part(
						(int) $job['object_id'],
						$step,
						(array) $payload['plan'],
						(string) ( $payload['prompt'] ?? '' )
					);
					$result         .= ( '' !== $result ? "\n\n" : '' ) . $piece;
					$payload['step'] = $step + 1;
					$written         = $payload['step'] >= count( (array) $payload['plan']['sections'] );
					// A description without its internal links is half a job. It
					// used to be finished by hand, with a second five-minute run
					// on a text that had just been written; the linking pass is
					// now the last step of writing, not a separate errand.
					if ( $written && empty( $payload['linked'] ) ) {
						$payload['linked'] = 1;
						try {
							$linked = DZE_Category_Content::add_links( (int) $job['object_id'], $result );
							if ( ! empty( $linked['html'] ) ) {
								$result            = (string) $linked['html'];
								$payload['links']  = (int) ( $linked['after'] ?? 0 );
							}
						} catch ( \Throwable $e ) {
							// The description is written and worth keeping; the
							// links can be added from the review screen.
							$payload['link_error'] = $e->getMessage();
						}
					}
					$done = $written;
				}
			} else {
				$result = self::produce( (string) $job['kind'], (int) $job['object_id'], $payload );
				$done   = true;
			}
		} catch ( DZE_Nothing_To_Do $e ) {
			// UNE CONCLUSION, PAS UNE PANNE.
			//
			// « Sniper veils et Tactical backpack covers sont en review avec
			// des erreurs. Ça sème la confusion, ça dit que le module ne
			// fonctionne pas bien, du point de vue utilisateur. » Les deux
			// lignes rouges étaient les garde-fous qui avaient refusé du
			// mauvais travail, et un modèle qui avait jugé qu'aucune page
			// n'était proche. Le module avait bien travaillé ; l'écran disait
			// le contraire, à côté de seize pages parfaites.
			//
			// Rangé en « rien à faire » : la page est vue, la raison reste
			// lisible, et la liste ne garde que ce qui attend vraiment une
			// décision. `decided_by` reste à zéro — c'est ce qui distingue
			// cette conclusion d'un refus de la boutique, qui porte un nom.
			$wpdb->update(
				$table,
				[ 'status' => 'skipped', 'error' => $e->getMessage(), 'decided_by' => 0, 'updated' => current_time( 'mysql' ) ],
				[ 'id' => $id ]
			);
			self::forget_count();
			delete_transient( self::LOCK );
			// La file continue : une page sans rien à poser ne doit pas
			// arrêter les suivantes.
			if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'queued'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
				self::kick();
			}
			return;
		} catch ( \Throwable $e ) {
			$err = $e->getMessage();
		}

		self::forget_count();
		if ( '' !== $err ) {
			$wpdb->update( $table, [ 'status' => 'failed', 'error' => $err, 'updated' => current_time( 'mysql' ) ], [ 'id' => $id ] );
		} elseif ( $done ) {
			// One finished piece of work, whatever number of calls it took: that
			// is what "a category description costs X" is measured against.
			if ( class_exists( 'DZE_Ai_Usage' ) ) {
				DZE_Ai_Usage::finished( (string) $job['kind'] );
			}
			$applied = ! empty( $job['auto_apply'] ) && self::apply( (string) $job['kind'], (int) $job['object_id'], $result, $payload );
			$wpdb->update( $table, [
				'status'  => ! empty( $job['auto_apply'] ) ? ( $applied ? 'applied' : 'failed' ) : 'review',
				'result'  => $result,
				'payload' => wp_json_encode( $payload ),
				// A pass that runs with nobody watching is the one that most
				// needs to say why it stopped.
				'error'   => ( ! empty( $job['auto_apply'] ) && ! $applied ) ? ( self::refusal() ?: null ) : null,
				'updated' => current_time( 'mysql' ),
			], [ 'id' => $id ] );
		} else {
			$wpdb->update( $table, [
				'status'  => 'running',
				'result'  => $result,
				'payload' => wp_json_encode( $payload ),
				'updated' => current_time( 'mysql' ),
			], [ 'id' => $id ] );
		}

		delete_transient( self::LOCK );
		if ( '' === $err && ! $done ) {
			self::kick(); // straight on to the next step.
			return;
		}
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'queued'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			self::kick();
		}
	}

	/**
	 * A step that never came back — the host killed the request mid-way — must
	 * not leave a job spinning for ever. After five idle minutes it is put back
	 * in the queue, keeping what it had already written; after three of those,
	 * it is called failed and says so.
	 */
	public static function recover(): void {
		global $wpdb;
		$table = self::table();
		$stale = (array) $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			"SELECT id, payload FROM {$table} WHERE status = 'running' AND updated < %s",
			// Same clock as the column: it is written with current_time().
			// ONE CLOCK, NOT TWO. The writer's lock is let go once it is older
			// than the step budget; a row presumed abandoned only a minute
			// LATER left a window where the lock was free and the row was still
			// treated as somebody's — so the same job was taken a second time
			// while the first run was still in flight, and the shop paid the
			// model twice for one page. Now that every job is claimed, that
			// window was on every job rather than on the few that reach
			// `running` by themselves.
			gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::STEP_BUDGET ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- matches the stored site time.
		), ARRAY_A );
		foreach ( $stale as $r ) {
			$p            = $r['payload'] ? (array) json_decode( (string) $r['payload'], true ) : [];
			$p['retries'] = (int) ( $p['retries'] ?? 0 ) + 1;
			// The claim counts the attempts the row never came back from, and
			// this counts the ones it was found abandoned on. Either is a run
			// the server stopped, and three of them is a job to give up on
			// rather than a queue to block.
			if ( max( (int) $p['retries'], (int) ( $p['tries'] ?? 0 ) ) > 3 ) {
				$wpdb->update( $table, [
					'status'  => 'failed',
					'error'   => __( 'The server stopped this run three times. Try a shorter target length, or a faster model in Settings.', 'dazont-ecom' ),
					'payload' => wp_json_encode( $p ),
					'updated' => current_time( 'mysql' ),
				], [ 'id' => (int) $r['id'] ] );
				continue;
			}
			$wpdb->update( $table, [
				'status'  => 'queued',
				'payload' => wp_json_encode( $p ),
				'updated' => current_time( 'mysql' ),
			], [ 'id' => (int) $r['id'] ] );
		}
		if ( $stale ) {
			delete_transient( self::LOCK );
		}
		// AND A LOCK LEFT BEHIND BY A RUN THAT LEFT NO ROW BEHIND. "c'est
		// bloqué." Two hundred pages queued, the bar at 0%, nothing written and
		// nothing said. The writer is barred while this lock stands, and it is
		// let go at the end of a step — which a run the host kills mid-call
		// never reaches. Above, the lock went only where a stale `running` row
		// had been found, and the kinds that screen queues never pass through
		// `running`: a linking pass is written in ONE step, queued → review. So
		// the lock stood, every step returned at once having done nothing, and
		// the one function able to clear it could not see it.
		// It is timed, not guessed: longer than the step budget means the run
		// that took it is gone, and a step in its fourth second is a step.
		$held = self::held_for();
		if ( $held > self::STEP_BUDGET ) {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * How long the writer has been held, in seconds — 0 when it is free.
	 *
	 * A screen cannot tell a writer busy for two seconds from one abandoned ten
	 * minutes ago, and the difference is the whole of "c'est bloqué".
	 */
	public static function held_for(): int {
		$at = get_transient( self::LOCK );
		if ( ! $at ) {
			return 0;
		}
		$at = (int) $at;
		// A lock written by an earlier version holds the figure 1, which reads
		// as 1970 and would make every writer look abandoned the moment this
		// version lands. Unknown age is read as "just taken": it expires on its
		// own within the five minutes it was set for.
		if ( $at < 1000000000 ) {
			return 1;
		}
		return max( 1, time() - $at );
	}

	/**
	 * The one figure that decides whether a run is still going or is gone.
	 *
	 * Read rather than repeated: the lock, the row and the gate all ask it, so
	 * there is no second number to keep in step.
	 */
	public static function step_budget(): int {
		return (int) self::STEP_BUDGET;
	}

	/** Lets the writer go. Nothing is in flight that this could interrupt. */
	public static function unlock(): void {
		delete_transient( self::LOCK );
	}

	/**
	 * How long since anything of these kinds moved, in seconds.
	 *
	 * The one reading that tells a run in progress from a run that has stopped:
	 * a figure that is not changing is the same markup as a figure that is
	 * about to. Every row carries when it last moved, so the database answers
	 * it rather than the browser guessing from two polls that looked alike.
	 * Nothing of those kinds at all answers 0 — an empty queue is not a silence,
	 * and read the other way it would put a stuck warning on every shop that
	 * has finished its work.
	 */
	public static function idle_for( array $kinds ): int {
		global $wpdb;
		$kinds = self::clean_kinds( $kinds );
		if ( ! $kinds ) {
			return 0;
		}
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		$in   = "'" . implode( "','", $kinds ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, kinds sanitised above.
		$last = (string) $wpdb->get_var( "SELECT MAX(updated) FROM {$table} WHERE kind IN ({$in})" );
		if ( '' === $last ) {
			return 0;
		}
		// Same clock as the column: it is written with current_time().
		return max( 0, (int) current_time( 'timestamp' ) - (int) strtotime( $last ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- matches the stored site time.
	}

	/**
	 * Puts every failed row of these kinds back in the queue. Returns how many.
	 *
	 * ONE WRITE FOR THE WHOLE PRESS, never a read-modify-write per row — two
	 * hundred of those inside one request is how a log comes back holding only
	 * the last twelve lines of the press that filled it. And the writer is
	 * freed by the same press: putting the rows back while the lock still
	 * stands is a press that answers "3 put back" and changes nothing.
	 */
	public static function retry_failed( array $kinds ): int {
		global $wpdb;
		$kinds = self::clean_kinds( $kinds );
		if ( ! $kinds ) {
			return 0;
		}
		$table = self::table();
		$in    = "'" . implode( "','", $kinds ) . "'";
		$n     = (int) $wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, kinds sanitised above.
			"UPDATE {$table} SET status = 'queued', error = NULL, updated = %s WHERE status = 'failed' AND kind IN ({$in})",
			current_time( 'mysql' )
		) );
		self::unlock();
		self::forget_count();
		if ( $n > 0 ) {
			self::kick();
		}
		return $n;
	}

	/**
	 * Calls a run off: drops what has not been written, of these kinds.
	 *
	 * "Start it again > Il faut une option aussi pour annuler." The block had
	 * one control and it put the work BACK, so a run started by mistake — or
	 * one whose pages keep coming back wrong — could be restarted for ever and
	 * never called off.
	 *
	 * It takes the two states that hold nothing: WAITING ITS TURN, and COULD
	 * NOT BE WRITTEN. A row waiting for a yes or no holds a finished text and
	 * an applied row is the only record that the shop was worked on — dropping
	 * either would be this press destroying the very work it was pressed to
	 * stop making. A step already in flight is left to land: it is one page,
	 * and it is already paid for.
	 */
	public static function drop_waiting( array $kinds ): int {
		global $wpdb;
		self::$dropped = [];
		$kinds = self::clean_kinds( $kinds );
		if ( ! $kinds ) {
			return 0;
		}
		$table = self::table();
		$in    = "'" . implode( "','", $kinds ) . "'";
		// WHICH PAGES, READ BEFORE THEY GO. A page queued by the automatic pass
		// is stamped as worked on so the daily pass does not do it twice —
		// true while the row is there, a lie the moment it is dropped. The
		// register that holds that stamp cannot let go of what it is never
		// told about, and after the DELETE there is nothing left to tell it.
		self::$dropped = (array) $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, kinds sanitised above.
			"SELECT kind, object_id FROM {$table} WHERE status IN ('queued','failed') AND kind IN ({$in})",
			ARRAY_A
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, kinds sanitised above.
		$n = (int) $wpdb->query( "DELETE FROM {$table} WHERE status IN ('queued','failed') AND kind IN ({$in})" );
		// The writer goes with them, or the next press is barred for the whole
		// of the lock's own five minutes by a run that no longer exists.
		self::unlock();
		self::forget_count();
		return $n;
	}

	/**
	 * The rows the last `drop_waiting()` removed — kind and object, no more.
	 *
	 * @var array<int,array{kind:string,object_id:int}>
	 */
	private static array $dropped = [];

	/** @return array<int,array{kind:string,object_id:int}> */
	public static function dropped_rows(): array {
		return self::$dropped;
	}

	/** Why the last few runs of these kinds could not be written. */
	public static function failures( array $kinds, int $limit = 3 ): array {
		global $wpdb;
		$kinds = self::clean_kinds( $kinds );
		if ( ! $kinds ) {
			return [];
		}
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		$in = "'" . implode( "','", $kinds ) . "'";
		return (array) $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, kinds sanitised above.
			"SELECT kind, object_id, error FROM {$table} WHERE status = 'failed' AND kind IN ({$in}) ORDER BY updated DESC LIMIT %d",
			max( 1, $limit )
		), ARRAY_A );
	}

	/** The kinds a caller asked about, kept to what a kind can be spelled as. */
	private static function clean_kinds( array $kinds ): array {
		return array_values( array_unique( array_filter( array_map(
			static fn( $k ): string => preg_replace( '/[^a-z_]/', '', strtolower( (string) $k ) ),
			$kinds
		) ) ) );
	}

	/** Writes one job's content. Throws with a readable reason on failure. */
	/**
	 * @param array $payload By reference: a job may learn something while it
	 *                       runs that its acceptance needs later — where a
	 *                       photograph is meant to land, and which recipe made
	 *                       it. Recomputing that at acceptance time would be a
	 *                       second answer to one question.
	 *
	 * Public, and for the same reason `shoot()` is a function rather than a
	 * handler: what a job SENDS is the half that goes wrong silently, and a
	 * step nobody can call is a step nobody can exercise. The chosen link
	 * targets travelled with the job for a whole release without ever being
	 * passed on, and every screen said the work was done.
	 */
	public static function produce( string $kind, int $object_id, array &$payload ): string {
		if ( ! class_exists( 'DZE_Category_Content' ) ) {
			throw new RuntimeException( __( 'The Category descriptions module is switched off.', 'dazont-ecom' ) );
		}
		if ( 'cat_desc' === $kind ) {
			return DZE_Category_Content::generate( $object_id, (string) ( $payload['prompt'] ?? '' ) );
		}
		if ( 'cat_links' === $kind ) {
			$term = get_term( $object_id, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				throw new RuntimeException( __( 'Category not found.', 'dazont-ecom' ) );
			}
			// The text the press was made on, when the screen sent one; the
			// stored description otherwise, which is what an automatic pass
			// and a queued row from the Linking screen mean.
			$body = trim( (string) ( $payload['html'] ?? '' ) );
			if ( '' === $body ) {
				// READ FROM THE TABLES. WPML filters get_term() to the CURRENT
				// language, so $term above can be the FRENCH translation of the
				// category this job is about — and the pass then worked on the
				// French text while writing the answer back onto the English
				// term. Same trap as the name it was called by.
				$row  = class_exists( 'DZE_Category_Content' ) ? DZE_Category_Content::term_row( $object_id ) : null;
				$body = (string) ( $row['description'] ?? $term->description );
			}
			$res = DZE_Category_Content::add_links( $object_id, $body, (array) ( $payload['urls'] ?? [] ) );
			return (string) $res['html'];
		}
		if ( 'post_links' === $kind ) {
			if ( ! class_exists( 'DZE_Post_Links' ) ) {
				throw new RuntimeException( __( 'The article linking pass is unavailable.', 'dazont-ecom' ) );
			}
			// The chosen targets travel with the job: a link asked for on the
			// Linking screen is that link, not whatever the article would have
			// picked on its own.
			return DZE_Post_Links::add_links( $object_id, (array) ( $payload['urls'] ?? [] ) );
		}
		if ( 'product_shot' === $kind ) {
			if ( ! class_exists( 'DZE_Content' ) ) {
				throw new RuntimeException( __( 'The Product content module is switched off.', 'dazont-ecom' ) );
			}
			// The SAME function the button on the product page calls, with the
			// same names — never a second assembly of the same prompt. "defer"
			// is its own word for "make it, file nothing": the picture waits
			// on the review screen like every other job here.
			$in = [
				'post'     => $object_id,
				'template' => (int) ( $payload['template'] ?? 0 ),
				'mode'     => 'defer',
			];
			if ( ! empty( $payload['target'] ) ) {
				$in['target'] = (string) $payload['target'];
			}
			$made = DZE_Content::instance()->shoot( $in );
			$url  = (string) ( $made['url'] ?? '' );
			if ( '' === $url ) {
				throw new RuntimeException( __( 'The picture service returned nothing.', 'dazont-ecom' ) );
			}
			// Where it goes, decided ONCE — here, by the recipe that made it.
			$payload['target'] = (string) ( $made['target'] ?? 'gallery' );
			$payload['recipe'] = (string) ( $made['recipe'] ?? '' );
			return $url;
		}
		throw new RuntimeException( __( 'Unknown job type.', 'dazont-ecom' ) );
	}

	/**
	 * THE SAME FIGURES, FOR ONE SET OF JOB KINDS.
	 *
	 * A screen that shows SOME of the queue must count the same some of it:
	 * the Automation page listed what its own three tasks had left and put a
	 * bar above it reading the WHOLE queue, so one screen said "3 pages are
	 * waiting below" over a list saying "nothing is waiting". A photograph
	 * generated from the bulk screen is in the queue and is not this page's
	 * work.
	 *
	 * @param array<int,string> $kinds
	 * @return array{queued:int,running:int,review:int,applied:int,failed:int,skipped:int}
	 */
	public static function counts_for( array $kinds ): array {
		global $wpdb;
		$out   = [ 'queued' => 0, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
		$kinds = self::clean_kinds( $kinds );
		if ( ! $kinds ) {
			return $out;
		}
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return $out;
		}
		$in = "'" . implode( "','", $kinds ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, kinds sanitised above.
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} WHERE kind IN ({$in}) GROUP BY status", ARRAY_A ) as $r ) {
			$out[ (string) $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/** Saves an accepted result onto the shop. */
	public static function apply( string $kind, int $object_id, string $html, array $payload = [] ): bool {
		self::$refused = '';
		if ( '' === trim( $html ) ) {
			return false;
		}
		if ( 'product_shot' === $kind ) {
			if ( ! class_exists( 'DZE_Content' ) ) {
				return false;
			}
			// Added to the product, never over anything: a photograph the shop
			// already has is not this pass's to replace.
			$att = DZE_Content::instance()->sideload_seo(
				$html,
				$object_id,
				(string) ( $payload['target'] ?? 'gallery' ),
				(string) ( $payload['recipe'] ?? '' ),
				true
			);
			return $att > 0;
		}
		if ( 'post_links' === $kind ) {
			// THE LAST THING THE WRITE DOES IS LOOK. Three articles on this
			// shop lost content in one day and only the first was the model's
			// doing: the other two were damaged AFTER every production guard
			// had passed them, by the review popup's own visual editor on the
			// way back from Accept. A guard that lives only where the text is
			// made protects the automatic pass and nothing else, so the same
			// reading is asked again here — the one place every path writes
			// through, including the ones built next year.
			if ( ! self::writable( $kind, $object_id, $html ) ) {
				return false;
			}
			// Only the links changed: the title, the status, the dates and
			// everything else about the post are none of our business.
			$done = wp_update_post( [ 'ID' => $object_id, 'post_content' => wp_kses_post( $html ) ], true );
			if ( is_wp_error( $done ) ) {
				return false;
			}
			delete_transient( 'dze_pl_census' );
			return true;
		}
		if ( 'cat_desc' === $kind || 'cat_links' === $kind ) {
			// The same question, asked of a description too: a plain HTML text
			// carries no block delimiters and answers "nothing to protect",
			// which costs one regular expression and leaves no path unasked.
			if ( ! self::writable( $kind, $object_id, $html ) ) {
				return false;
			}
			$res = wp_update_term( $object_id, 'product_cat', [ 'description' => wp_kses_post( $html ) ] );
			if ( is_wp_error( $res ) ) {
				return false;
			}
			if ( class_exists( 'DZE_Category_Content' ) ) {
				update_term_meta( $object_id, DZE_Category_Content::GEN_META, 1 );
			}
			return true;
		}
		return false;
	}

	/**
	 * WHY THE LAST WRITE WAS REFUSED, in words a person reads on the row.
	 *
	 * "Saving failed." is the sentence that sent this shop looking in the wrong
	 * place for a day. Written by every `apply()` call, so it always describes
	 * the one that has just happened and never an older one.
	 */
	public static function refusal(): string {
		return self::$refused;
	}

	/**
	 * May this text be written over what the object holds today?
	 *
	 * One question, one owner: `DZE_Blocks` holds what a WordPress article may
	 * not come back as, and answers here and where the text is produced alike.
	 */
	private static function writable( string $kind, int $object_id, string $html ): bool {
		$damage = DZE_Blocks::damage( self::holds_now( $kind, $object_id ), $html );
		if ( '' === $damage ) {
			return true;
		}
		self::$refused = sprintf(
			/* translators: %s: what would have happened to the document */
			__( 'Not saved: it would have come back with %s.', 'dazont-ecom' ),
			$damage
		);
		return false;
	}

	// =========================================================================
	// Reading
	// =========================================================================

	public static function counts(): array {
		global $wpdb;
		$table = self::table();
		$out   = [ 'queued' => 0, 'running' => 0, 'review' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0 ];
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			$out[ (string) $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * The queue, or the part of it one screen is responsible for.
	 *
	 * SCOPE IS THE WHOLE POINT. One list holding categories, articles,
	 * photographs and linking passes is one list nobody can read: the screen
	 * about internal linking shows linking, and "Cancel all" on it cannot
	 * reach a photograph somebody is still thinking about. An empty scope is
	 * everything, which is what the one inbox wants.
	 *
	 * @param array<int,string> $kinds
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( int $limit = 200, array $kinds = [], bool $all = false ): array {
		global $wpdb;
		$table = self::table();
		$kinds = self::clean_kinds( $kinds );
		$where = $kinds ? "WHERE kind IN ('" . implode( "','", $kinds ) . "')" : '';
		// WHAT IS DECIDED LEAVES THE LIST.
		//
		// "Les tâches acceptées restent dans la liste, j'en vois plein des
		// saved accepted." A screen called "To review" holding rows that have
		// been reviewed is a screen where the remaining work has to be hunted
		// for. Accepted and discarded rows are the RECORD of what happened —
		// Logs → Automatic passes holds that, with the undo — so this list
		// shows what still wants a person, and says how many it has put away.
		$done  = [ 'applied', 'skipped' ];
		if ( ! $all ) {
			$where .= ( '' === $where ? 'WHERE ' : ' AND ' ) . "status NOT IN ('" . implode( "','", $done ) . "')";
		}
		return (array) $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, kinds sanitised above.
			"SELECT id, kind, object_id, status, error, payload, created, updated, decided_by, made_by FROM {$table}
			 {$where}
			 ORDER BY FIELD(status,'running','queued','review','failed','applied','skipped'), id ASC LIMIT %d",
			$limit
		), ARRAY_A );
	}

	/**
	 * What is waiting, per object, for one family of kinds. One query for a
	 * whole list screen — a badge per row must never cost a query per row.
	 *
	 * The family matters: a category and an article can carry the same number
	 * without being the same thing, and "is something waiting on #42" has to
	 * know which #42 is being asked about.
	 *
	 * @return array<int,array{status:string,id:int,kind:string}>
	 */
	/**
	 * What was ACCEPTED on each of these objects, most recent first.
	 *
	 * The record of what this plugin actually did: one query for a whole page,
	 * because a lookup per row is fifty queries to draw a list.
	 *
	 * @param int[] $ids
	 * @return array<int,array{kind:string,when:string,id:int}>
	 */
	public static function done_map( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = (array) $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, ids cast to int above.
			"SELECT id, kind, object_id, updated, decided_by FROM " . self::table() . "
			 WHERE status = 'applied' AND object_id IN ( {$in} ) ORDER BY id DESC",
			ARRAY_A
		);
		$out = [];
		foreach ( $rows as $r ) {
			$oid = (int) $r['object_id'];
			// The most recent only: the row is one line, and "what was done to
			// this product" is the last thing that was done to it.
			if ( isset( $out[ $oid ] ) ) {
				continue;
			}
			$out[ $oid ] = [
				'kind' => (string) $r['kind'],
				'when' => (string) $r['updated'],
				'id'   => (int) $r['id'],
				// AND WHO SAID YES. A page that was worked on, when, and by
				// nobody in particular is three quarters of an answer.
				'who'  => self::decided_by( (int) ( $r['decided_by'] ?? 0 ) ),
			];
		}
		return $out;
	}

	public static function pending_map( string $family = 'cat_' ): array {
		global $wpdb;
		$family = preg_replace( '/[^a-z_]/', '', strtolower( $family ) );
		if ( isset( self::$pending_cache[ $family ] ) ) {
			return self::$pending_cache[ $family ];
		}
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			self::$pending_cache[ $family ] = [];
			return self::$pending_cache[ $family ];
		}
		$map  = [];
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			// `made_by` comes along: the bench that draws from this map has to
			// be able to say WHOSE run is under way, not only that one is.
			"SELECT id, kind, object_id, status, made_by FROM {$table}
			 WHERE status IN ('queued','running','review') AND kind LIKE %s
			 ORDER BY FIELD(status,'review','running','queued'), id DESC",
			$wpdb->esc_like( $family ) . '%'
		), ARRAY_A );
		foreach ( $rows as $r ) {
			$oid = (int) $r['object_id'];
			if ( isset( $map[ $oid ] ) ) {
				continue; // the most advanced one wins.
			}
			$map[ $oid ] = [
				'status' => (string) $r['status'],
				'id'     => (int) $r['id'],
				'kind'   => (string) $r['kind'],
				'by'     => self::started_by( (int) ( $r['made_by'] ?? 0 ) ),
			];
		}
		self::$pending_cache[ $family ] = $map;
		return $map;
	}

	/** The job waiting on this object, if any. */
	public static function pending_for( int $object_id, string $family = 'cat_' ): array {
		return self::pending_map( $family )[ $object_id ] ?? [];
	}

	/**
	 * The owner saved this category by hand: whatever was waiting for review on
	 * it is settled, and must not keep asking.
	 */
	public static function settle( int $object_id, bool $accept = true ): void {
		self::forget_count();
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			// A DECISION IS SIGNED, and a REFUSAL IS A DECISION. This wrote
			// 'applied' with nobody's name on it, and had no counterpart at
			// all for a refusal — so "Put back what was there" left the row
			// waiting for ever and the panel announced the same text again
			// every time it opened: "le bouton reste ensuite bloqué sur ce
			// texte." One function, both answers.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			"UPDATE " . self::table() . " SET status = %s, decided_by = %d, updated = %s WHERE object_id = %d AND status = 'review' AND kind LIKE 'cat_%%'",
			$accept ? 'applied' : 'skipped',
			self::decider(),
			current_time( 'mysql' ),
			$object_id
		) );
	}

	/**
	 * EVERY PAGE THIS QUEUE HAS WRITTEN, newest first.
	 *
	 * The applied rows are the durable record that a page was worked on, when,
	 * and that somebody said yes — which is why Clear must never delete one.
	 * Nothing read them across the whole table until the shop asked for one
	 * register: "ce serait bien d'avoir un registre commun."
	 *
	 * This is a READ and nothing else. The queue goes on owning its own rows;
	 * a second store copying them is two accounts of one thing that drift.
	 *
	 * @return array<int,array{kind:string,object_id:int,when:int,by:int}>
	 */
	public static function applied_rows( int $limit = 200, array $kinds = [] ): array {
		global $wpdb;
		if ( ! $wpdb ) {
			return [];
		}
		$limit = max( 1, min( 500, $limit ) );
		// ONE READER, narrowed where a screen is about one kind of work. The
		// automation's own record of what it published is this same query with
		// its own job kinds named — never a second store beside it.
		$kinds = array_values( array_filter( array_map(
			static fn( $k ): string => preg_replace( '/[^a-z_]/', '', strtolower( (string) $k ) ),
			$kinds
		) ) );
		$only  = $kinds ? " AND kind IN ( '" . implode( "','", $kinds ) . "' )" : '';
		$rows  = (array) $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, kinds stripped to [a-z_] above.
			// L'IDENTIFIANT DU TRAVAIL VIENT AVEC, et sa demande.
			//
			// « Je ne sais pas ce qui devait être fait et ce qui n'a pas été
			// fait au final. » Une ligne disant qu'une passe a eu lieu, sans
			// dire ce qu'elle a posé ni ce qu'il en reste, ne se vérifie pas.
			// Avec le numéro du travail, l'écran peut aller relire ce qu'elle
			// a produit et le confronter au texte d'aujourd'hui.
			"SELECT id, kind, object_id, updated, decided_by, made_by, payload FROM " . self::table() . "
			  WHERE status = 'applied'{$only} ORDER BY id DESC LIMIT %d",
			$limit
		), ARRAY_A );
		$out  = [];
		$seen = [];
		foreach ( $rows as $r ) {
			// One line per thing written: a page written twice is the same
			// page, at the date of the last time.
			$key = (string) $r['kind'] . ':' . (int) $r['object_id'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$ask = json_decode( (string) ( $r['payload'] ?? '' ), true );
			$out[] = [
				'id'        => (int) $r['id'],
				'kind'      => (string) $r['kind'],
				'object_id' => (int) $r['object_id'],
				'when'      => (int) strtotime( (string) $r['updated'] . ' UTC' ),
				'by'        => (int) ( $r['decided_by'] ?? 0 ),
				// WHO ASKED FOR THE WORK, beside who accepted it: on a record
				// of what a pass published, 0 is the pass itself.
				'from'      => (int) ( $r['made_by'] ?? 0 ),
				// POURQUOI CET OBJET A ÉTÉ CHOISI, quand la passe l'a écrit en
				// posant le travail. Absent sur les travaux d'avant : l'écran
				// le dit plutôt que d'inventer une raison.
				'why'       => is_array( $ask ) ? (string) ( $ask['why'] ?? '' ) : '',
			];
		}
		return $out;
	}

	/**
	 * The two answers, in the words they wear everywhere.
	 *
	 * The list draws its rows in the browser and reads them from its localized
	 * config; the Automation screen prints the same three controls from PHP.
	 * Written out twice they drift, and the same button then says two things
	 * on two screens.
	 *
	 * @return array{accept:string,refuse:string}
	 */
	public static function decide_words(): array {
		return [
			// Not "onto the category": these rows are categories AND articles,
			// and a button that names the wrong kind of page is a button
			// nobody presses twice.
			'accept' => __( 'Accept: save this text onto the page it was written for', 'dazont-ecom' ),
			'refuse' => __( 'Refuse: throw this text away', 'dazont-ecom' ),
		];
	}

	/**
	 * WHAT THE OBJECT HOLDS TODAY — the "before" of every before / after.
	 *
	 * This read `get_term( $id, 'product_cat' )` whatever the job was. On an
	 * ARTICLE that term does not exist, so the before came back empty and the
	 * popup said "0 words → 1224 words · 0 links → 4 links" over a post with
	 * twelve hundred words in it: "encore une anomalie, pour les articles de
	 * blog le avant/après est faux". Nothing errored, and the figure that was
	 * wrong is the one the whole screen is for.
	 *
	 * A job's kind already says where its result is WRITTEN — that is how
	 * `apply()` knows — so it says where the before is READ from too, and the
	 * two are answered in one place so they cannot drift. A kind added next
	 * year and forgotten here answers with an empty before, which is why the
	 * gate asserts every kind that is not an image.
	 */
	public static function holds_now( string $kind, int $object_id ): string {
		if ( 0 === strpos( $kind, 'cat_' ) ) {
			// LE TEXTE DE CET OBJET-LA. holds_now() sert a comparer ce qui est en
			// base avec ce qui attend une decision : compare a la traduction, il
			// declarait « le texte a change depuis » sur des lignes intactes.
			$row = class_exists( 'DZE_Category_Content' ) ? DZE_Category_Content::term_row( $object_id ) : null;
			return $row ? (string) $row['description'] : '';
		}
		if ( 0 === strpos( $kind, 'post_' ) || 0 === strpos( $kind, 'product_' ) ) {
			$post = get_post( $object_id );
			return $post ? (string) $post->post_content : '';
		}
		return '';
	}

	/**
	 * Where this object is CHANGED, and where a reader SEES it.
	 *
	 * A job's kind already says what it is about — that is how `holds_now()`
	 * and `apply()` know — so it says where the object lives too, in one place
	 * rather than in each list that draws a row.
	 */
	public static function edit_link( string $kind, int $object_id ): string {
		if ( ! $object_id ) {
			return '';
		}
		if ( 0 === strpos( $kind, 'cat_' ) ) {
			$url = get_edit_term_link( $object_id, 'product_cat' );
			return is_string( $url ) ? $url : '';
		}
		return (string) get_edit_post_link( $object_id, '' );
	}

	public static function view_link( string $kind, int $object_id ): string {
		if ( ! $object_id ) {
			return '';
		}
		$url = 0 === strpos( $kind, 'cat_' )
			? get_term_link( $object_id, 'product_cat' )
			: get_permalink( $object_id );
		return ( is_string( $url ) && '' !== $url ) ? $url : '';
	}

	public static function label_for( string $kind, int $object_id ): string {
		if ( 0 === strpos( $kind, 'cat_' ) ) {
			$t = get_term( $object_id, 'product_cat' );
			// Decoded here, escaped once by the screen: otherwise "Bags &
			// backpacks" comes out as "Bags &amp;amp; backpacks".
			return ( $t && ! is_wp_error( $t ) ) ? html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) : sprintf( '#%d', $object_id );
		}
		if ( 0 === strpos( $kind, 'product_' ) || 0 === strpos( $kind, 'post_' ) ) {
			$title = get_the_title( $object_id );
			return '' !== $title ? html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) : sprintf( '#%d', $object_id );
		}
		return sprintf( '#%d', $object_id );
	}

	// =========================================================================
	// Screen
	// =========================================================================

	/**
	 * Is this screen a TAB of Dazont Ecom → Content rather than a page?
	 *
	 * It is, whenever the module that hosts the tabs is on. One entry in the
	 * menu for one subject — diagnose, do, review — instead of three the owner
	 * has to remember and connect himself. When that module is off it goes
	 * back to being its own page: a module switched off must never take a
	 * function with it that has nothing to do with it.
	 */
	/**
	 * NOT HOSTED ANYWHERE ANY MORE. It is the inbox, and it has its own entry.
	 *
	 * It used to live as a tab of the diagnostic screen, so its address was
	 * that screen's — and when the diagnostic module was switched off, the one
	 * list holding every kind of waiting work became reachable from nowhere at
	 * all. Kept as a function because callers ask it, and it now answers the
	 * only true thing: no.
	 */
	public static function hosted(): bool {
		return false;
	}

	/** The screen's address, in one place. */
	public static function url( array $args = [] ): string {
		return add_query_arg(
			array_merge( [ 'page' => self::MENU_SLUG ], $args ),
			admin_url( 'admin.php' )
		);
	}

	/** Where it USED to live, so a bookmark or an old link still lands. */
	public static function old_url( array $args = [] ): string {
		return add_query_arg(
			array_merge( [ 'post_type' => 'product', 'page' => self::MENU_SLUG ], $args ),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * An old address, sent to the new one.
	 *
	 * The screen moved out of Products and into Dazont Ecom — "ça porte à
	 * confusion, ça devrait plutôt se trouver dans l'onglet de dazont ecom" —
	 * and a page that is no longer registered under Products does not answer
	 * "not found": WordPress answers "you are not allowed to access this
	 * page", which reads as a permission the shop has lost. Every link this
	 * plugin has ever printed still lands.
	 */
	public function moved(): void {
		global $pagenow;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading which screen was asked for.
		if ( ! isset( $_GET['page'] ) || self::MENU_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		// Under Products, where it used to live; or on its own page, now that
		// it is a tab. Both are addresses this plugin has printed, and both
		// have to land.
		if ( 'edit.php' !== $pagenow && ! ( 'admin.php' === $pagenow && self::hosted() ) ) {
			return;
		}
		$args = array_diff_key( $_GET, array_flip( [ 'page', 'post_type' ] ) );
		// phpcs:enable
		wp_safe_redirect( self::url( array_map( 'sanitize_text_field', array_map( 'strval', $args ) ) ) );
		exit;
	}

	public function menu(): void {
		// A tab of Content, normally: no second entry in the menu for it.
		if ( self::hosted() ) {
			return;
		}
		// UNDER DAZONT ECOM, not under Products. It holds categories, products
		// AND articles — "ça porte à confusion, ça devrait plutôt se trouver
		// dans l'onglet de dazont ecom" — and a screen about everything the
		// plugin has written does not belong inside one of the things it
		// writes. The slug is unchanged and the old address redirects, so
		// every link ever printed at it still lands.
		$parent  = class_exists( 'DZE_Restock' ) ? DZE_Restock::MENU_SLUG : 'dazont-ecom';
		// The count rides on the menu label: what is waiting for a decision
		// should be visible without opening the screen it waits on.
		// CE QUE CET ÉCRAN PEUT MONTRER, ET RIEN D'AUTRE.
		//
		// « Ça fausse le comptage des pastilles. En fait ces 5 devraient être
		// affichés sur bulk writing et pas sur review. » La pastille ajoutait
		// les produits du banc, qui ne sont pas dans cette liste — d'où une
		// notice sous le titre expliquant l'écart au lieu de le supprimer.
		// Le banc porte son propre compte maintenant ; celui-ci ne compte plus
		// que ses propres lignes.
		$waiting = self::review_count();
		// ONE NAME FOR THE ONE SCREEN. "Writing queue » / bulk produit >
		// Pourquoi pas dans un onglet réuni (catégorie + produits + blog) sous
		// le nom Content to review ?" — so this is that screen: categories,
		// products and articles, everything the shop has generated and not yet
		// decided on, in one place. A menu named after one of the things on it
		// is a menu the other things are hidden behind.
		$label   = DZE_Screens::label( 'review' );
		$menu    = $waiting
			? $label . ' <span class="update-plugins count-' . (int) $waiting . '"><span class="plugin-count">'
				. esc_html( number_format_i18n( $waiting ) ) . '</span></span>'
			: $label;
		add_submenu_page(
			$parent,
			$label,
			$menu,
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render' ]
		);
		// AND IT STAYS IN THE MENU. It was taken out of it earlier today, on
		// the reasoning that each work screen shows its own part — which left
		// the shop with three lists of three different scopes and NO list of
		// everything, the one thing "je ne comprends pas là où il faut donner
		// de l'attention" actually asks for. It is the inbox: one entry, one
		// table, one count, filtered by a rail rather than split across
		// screens. Its rail is printed by `body()`.
	}

	/**
	 * Is THIS the one screen that lists what is waiting for a decision?
	 *
	 * True while the module is on, and then the product bulk screen takes its
	 * own entry out of the menu — one question, one place, one count. False
	 * when the module is switched off, and the bulk screen keeps its menu
	 * because otherwise switching a module off would hide a function that has
	 * nothing to do with it.
	 */
	public static function owns_review(): bool {
		return ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( 'queue' );
	}

	/**
	 * How many PRODUCTS are holding a generated result nobody has decided on.
	 *
	 * The product bulk screen keeps its work on the products themselves, not
	 * in this queue's table, and the two lived under two menus — so "what is
	 * waiting for me" had two answers and neither said so. They are two
	 * stores and stay two stores; what changes is that ONE screen names
	 * everything waiting, and each row goes to the place its own decision is
	 * taken.
	 */
	public static function bulk_waiting(): int {
		return ( class_exists( 'DZE_Content' ) && method_exists( 'DZE_Content', 'pending_count' ) )
			? (int) DZE_Content::pending_count()
			: 0;
	}

	/**
	 * How many finished jobs are waiting for a yes or a no.
	 *
	 * Read on every admin page to draw the bubble, so it is one COUNT on an
	 * indexed column held in a transient, dropped whenever a job changes state.
	 */
	public static function review_count(): int {
		$n = get_transient( self::COUNT_KEY );
		if ( false !== $n ) {
			return (int) $n;
		}
		global $wpdb;
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'review'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		set_transient( self::COUNT_KEY, $n, MINUTE_IN_SECONDS );
		return $n;
	}

	/**
	 * How many of ONE kind of job are waiting for a decision.
	 *
	 * The screen that STARTED the work is where somebody asks "and what did it
	 * leave me?" — so the figure is answered per kind, one query, rather than
	 * making them go and count on the list itself.
	 *
	 * @param string[] $kinds
	 */
	public static function review_count_for( array $kinds ): int {
		global $wpdb;
		$kinds = array_values( array_filter( array_map(
			static fn( $k ): string => preg_replace( '/[^a-z_]/', '', strtolower( (string) $k ) ),
			$kinds
		) ) );
		if ( ! $kinds ) {
			return 0;
		}
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		$in = "'" . implode( "','", $kinds ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, kinds stripped to [a-z_] above.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'review' AND kind IN ( {$in} )" );
	}

	/**
	 * WHAT IS WAITING, ROW BY ROW, for one task's own kinds of job.
	 *
	 * `review_count_for()` answers HOW MANY; a screen that offers to settle
	 * them where the work was started needs WHICH. It is the same question of
	 * the same table, so it is the same query beside it — never a reading of
	 * its own somewhere else, which is how two screens come to disagree about
	 * what is waiting.
	 *
	 * Oldest first, the order the review list itself uses: what has waited
	 * longest is what is offered first.
	 *
	 * @param string[] $kinds
	 * @return array<int,array{id:int,kind:string,oid:int,label:string,job:string,from:string,when:string}>
	 */
	/**
	 * THE ROW BEING WRITTEN RIGHT NOW — or, failing that, the one next up.
	 *
	 * "Ici je veux plus d'info sur le post qui est en cours de travail. Pendant
	 * que ça charge je veux savoir ce que ça charge." The bar gave a figure and
	 * a percentage and never once named the page it was on, while this table
	 * has known all along.
	 *
	 * A row in flight and a row waiting its turn are different answers and the
	 * screen says which: `running` is true only for the first. Ordered by id,
	 * which is the order the writer takes them in.
	 *
	 * @return array{kind:string,oid:int,label:string,job:string,running:bool}
	 *               Empty when nothing of these kinds is waiting or in flight.
	 */
	public static function in_flight( array $kinds ): array {
		global $wpdb;
		$kinds = array_values( array_filter( array_map(
			static fn( $k ): string => preg_replace( '/[^a-z_]/', '', strtolower( (string) $k ) ),
			$kinds
		) ) );
		if ( ! $kinds ) {
			return [];
		}
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		$in = "'" . implode( "','", $kinds ) . "'";
		// Running first whatever its id: the writer may have taken a later row
		// while an earlier one waits on something.
		$row = (array) $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, kinds stripped to [a-z_] above.
			"SELECT kind, object_id, status FROM {$table}
			 WHERE status IN ( 'running', 'queued' ) AND kind IN ( {$in} )
			 ORDER BY FIELD( status, 'running', 'queued' ), id ASC LIMIT 1",
			ARRAY_A
		);
		if ( ! $row ) {
			return [];
		}
		$kind = (string) ( $row['kind'] ?? '' );
		$defs = self::kinds();
		return [
			'kind'    => $kind,
			'oid'     => (int) ( $row['object_id'] ?? 0 ),
			'label'   => self::label_for( $kind, (int) ( $row['object_id'] ?? 0 ) ),
			'job'     => (string) ( $defs[ $kind ]['label'] ?? $kind ),
			'running' => 'running' === (string) ( $row['status'] ?? '' ),
		];
	}

	public static function review_rows_for( array $kinds, int $limit = 10 ): array {
		global $wpdb;
		$kinds = array_values( array_filter( array_map(
			static fn( $k ): string => preg_replace( '/[^a-z_]/', '', strtolower( (string) $k ) ),
			$kinds
		) ) );
		if ( ! $kinds ) {
			return [];
		}
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		$in = "'" . implode( "','", $kinds ) . "'";
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, kinds stripped to [a-z_] above.
			"SELECT id, kind, object_id, updated, made_by FROM {$table}
			 WHERE status = 'review' AND kind IN ( {$in} ) ORDER BY id ASC LIMIT %d",
			max( 1, $limit )
		), ARRAY_A );
		$defs = self::kinds();
		$out  = [];
		foreach ( $rows as $r ) {
			$kind  = (string) $r['kind'];
			$out[] = [
				'id'    => (int) $r['id'],
				'kind'  => $kind,
				'oid'   => (int) $r['object_id'],
				'label' => self::label_for( $kind, (int) $r['object_id'] ),
				'job'   => (string) ( $defs[ $kind ]['label'] ?? $kind ),
				// WHO ASKED FOR IT AND WHEN IT LAST MOVED, said by the same
				// two functions the review list uses, so one row cannot read
				// two ways on two screens.
				'from'  => self::started_by( (int) ( $r['made_by'] ?? 0 ) ),
				'when'  => self::moment( (string) $r['updated'] ),
			];
		}
		return $out;
	}

	/**
	 * How many jobs of these kinds were ACCEPTED and written.
	 *
	 * The other half of "what has this task done for me": one figure says what
	 * is waiting, this one says what came through. Both belong to the screen
	 * that started the work.
	 *
	 * @param string[] $kinds
	 */
	public static function applied_count_for( array $kinds ): int {
		global $wpdb;
		$kinds = array_values( array_filter( array_map(
			static fn( $k ): string => preg_replace( '/[^a-z_]/', '', strtolower( (string) $k ) ),
			$kinds
		) ) );
		if ( ! $kinds ) {
			return 0;
		}
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		$in = "'" . implode( "','", $kinds ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, kinds stripped to [a-z_] above.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'applied' AND kind IN ( {$in} )" );
	}

	/** Any change of state can change the bubble. */
	/**
	 * What is waiting on each object, kept for the length of one request.
	 *
	 * @var array<string,array<int,array>>
	 */
	private static array $pending_cache = [];

	/**
	 * A CACHE HELD FOR A WHOLE REQUEST ANSWERS THE FIRST QUESTION FOR EVER.
	 *
	 * `pending_map()` was a function-local static, so a page queued a moment
	 * ago still read as "nothing waiting on it" for the rest of the request —
	 * and the pass that decides whether a page has already been taken in hand
	 * asks exactly that. Anything that changes the queue empties it, at the one
	 * place every such change already passes.
	 */
	public static function forget_count(): void {
		delete_transient( self::COUNT_KEY );
		self::$pending_cache = [];
	}

	/**
	 * The screen on its own, when it has one.
	 *
	 * It normally lives as a TAB of Dazont Ecom → Content, beside the
	 * diagnostic that finds the work — one subject, several views, which is
	 * WordPress's own idiom and the owner's own way of thinking about it. It
	 * keeps a page of its own only for the case where the module that hosts
	 * the tabs is switched off, because switching a module off must never
	 * hide a function that has nothing to do with it.
	 */
	/**
	 * ONE TABLE, ONE FILTER — WordPress's own Comments pattern.
	 *
	 * Three screens each drawing the same table with its own scope is three
	 * counts that can disagree and no way to clear the day in one place. The
	 * rail is built from the kinds catalogue, so a kind added tomorrow appears
	 * here without anybody remembering to add it, and each entry carries its
	 * own count — read in one query for the whole rail.
	 *
	 * @param array<int,string> $active
	 */
	public static function rail( array $active ): void {
		$kinds = self::kinds();
		$all   = array_keys( $kinds );
		// The families a shop actually thinks in, named by what waits in them.
		$groups = [
			'' => [ 'label' => __( 'All', 'dazont-ecom' ), 'kinds' => $all ],
		];
		foreach ( $all as $k ) {
			$groups[ (string) $k ] = [
				'label' => (string) ( $kinds[ $k ]['label'] ?? $k ),
				'kinds' => [ (string) $k ],
			];
		}
		$now  = implode( ',', $active );
		$base = add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) );
		$out  = [];
		foreach ( $groups as $key => $one ) {
			$n = (int) ( self::counts_for( $one['kinds'] )['review'] ?? 0 );
			// A family nothing has ever waited in is not a filter, it is noise.
			if ( '' !== $key && 0 === $n && ! in_array( $key, $active, true ) ) {
				continue;
			}
			$here = ( '' === $key && ! $active ) || ( $now === $key );
			$out[] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( '' === $key ? $base : add_query_arg( [ 'kind' => $key ], $base ) ),
				$here ? ' class="current"' : '',
				esc_html( (string) $one['label'] ),
				esc_html( number_format_i18n( $n ) )
			);
		}
		if ( count( $out ) < 2 ) {
			return; // one family is not a choice.
		}
		echo '<ul class="subsubsub" style="float:none;margin:0 0 12px;">';
		echo '<li>' . implode( ' |</li><li>', $out ) . '</li>';
		echo '</ul>';
	}
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="wrap dze-admin"><h1>' . esc_html( DZE_Screens::label( 'review' ) ) . '</h1>';
		// THE FILTER IS IN THE ADDRESS, so a filtered view is a bookmark and
		// a link — which is what lets every other screen point AT this list
		// instead of drawing a second copy of it.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$want = isset( $_GET['kind'] ) ? sanitize_text_field( wp_unslash( $_GET['kind'] ) ) : '';
		$want = self::clean_kinds( array_map( 'sanitize_key', explode( ',', $want ) ) );
		self::rail( $want );
		$this->body( array_values( $want ) );
		echo '</div>';
	}

	/**
	 * Everything the screen holds, without a page around it.
	 *
	 * Printed by render() on its own page and by the Content tabs alike: one
	 * body, so the two can never drift into two different screens.
	 */
	/**
	 * The review list, limited to the work the host screen is about.
	 *
	 * THE HOST DECIDES THE SCOPE, because the host is the only one that
	 * knows what its screen is for. An empty scope is everything, which is
	 * what the one inbox asks for.
	 *
	 * @param array<int,string> $kinds
	 */
	public function body( array $kinds = [] ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="dze-admin">
			<p class="description" style="max-width:900px;">
				<?php esc_html_e( 'Texts are written one at a time, and this page keeps the queue moving while it is open — leave it open and watch, or come back later and pick up what is waiting. Nothing is saved to the shop until you accept it.', 'dazont-ecom' ); ?>
			</p>
			<?php
			// No "start", no "watch": the page runs the queue by itself while it
			// is open. The only control worth a button is stopping, and it only
			// appears while something is actually running.
			?>
			<p>
				<span id="dze-q-counts" class="description"></span>
				<button type="button" class="button button-small" id="dze-q-pause" style="display:none;"><?php esc_html_e( 'Pause', 'dazont-ecom' ); ?></button>
				<button type="button" class="button-link" id="dze-q-clear" style="display:none;margin-left:10px;color:#646970;"></button>
			</p>
			<p id="dze-q-bulkbar" style="display:none;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:8px 12px;">
				<strong id="dze-q-selcount"></strong>
				<button type="button" class="button button-primary dze-q-bulk" data-do="accept"><?php esc_html_e( 'Accept and save', 'dazont-ecom' ); ?></button>
				<button type="button" class="button dze-q-bulk" data-do="discard" title="<?php esc_attr_e( 'Say no to what was written for the ticked rows. It is thrown away and the pages are left exactly as they are.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Cancel', 'dazont-ecom' ); ?></button>
				<button type="button" class="button dze-q-bulk" data-do="retry"><?php esc_html_e( 'Retry', 'dazont-ecom' ); ?></button>
				<button type="button" class="button-link dze-q-bulk" data-do="remove" style="color:#b32d2e;"><?php esc_html_e( 'Remove', 'dazont-ecom' ); ?></button>
				<span id="dze-q-bulkstatus" class="description"></span>
			</p>
			<?php
			// THE PRODUCT HALF OF "WHAT IS WAITING FOR ME" LIVES ELSEWHERE, and
			// this screen has to say so.
			//
			// It used to be a tab beside this one, so a notice pointing at it
			// was a screen describing itself. It is its own menu entry now —
			// and the bubble on "To review" counts those products, so the
			// screen said 7 and listed nothing: "pastille indique 7, sur la
			// page il n'y a rien." One line, with the way there, only when
			// there is something to say.
			// ET LA MOITIÉ « TRADUCTIONS » AUSSI.
			//
			// « La page to review est cassée et ne reprend pas la liste wpml.
			// Que maillage interne. »
			//
			// Elle n'était pas cassée, elle était incomplète : une traduction
			// ne passe pas par cette file — elle attend sur l'objet source, à
			// côté des mots qu'elle remplace — donc cet écran n'en a jamais rien
			// su. Trois choses attendent une décision dans ce plugin et une
			// seule se voyait ici. Une ligne, avec le chemin, comme pour le banc
			// d'écriture juste dessous : une seule liste par sujet, et aucune
			// qui se cache.
			$dze_tr = ( class_exists( 'DZE_Translate' ) && is_callable( [ 'DZE_Translate', 'review_count' ] ) )
				? (int) DZE_Translate::review_count()
				: 0;
			if ( $dze_tr > 0 && class_exists( 'DZE_Screens' ) ) {
				printf(
					'<div class="notice notice-info inline" style="margin:0 0 14px;"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
					esc_html( sprintf(
						/* translators: %s: how many objects */
						_n(
							'%s translation is waiting for your yes or no. It is not in this list: a translation waits on the object it belongs to, beside the words it replaces.',
							'%s translations are waiting for your yes or no. They are not in this list: a translation waits on the object it belongs to, beside the words it replaces.',
							$dze_tr,
							'dazont-ecom'
						),
						number_format_i18n( $dze_tr )
					) ),
					esc_url( DZE_Screens::url( 'translations', 'review' ) ),
					esc_html__( 'Read them →', 'dazont-ecom' )
				);
			}
			$dze_bulk = self::bulk_waiting();
			if ( $dze_bulk > 0 && class_exists( 'DZE_Content' ) ) {
				printf(
					'<div class="notice notice-info inline" style="margin:0 0 14px;"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
					esc_html( sprintf(
						/* translators: %s: how many products */
						_n(
							'%s product on the writing bench is holding a result nobody has decided on. It is not in this list: a product is accepted on the bench, beside its photographs.',
							'%s products on the writing bench are holding results nobody has decided on. They are not in this list: a product is accepted on the bench, beside its photographs.',
							$dze_bulk,
							'dazont-ecom'
						),
						number_format_i18n( $dze_bulk )
					) ),
					esc_url( DZE_Content::bulk_url() ),
					esc_html__( 'Open the bench →', 'dazont-ecom' )
				);
			}
			?>
			<table class="wp-list-table widefat fixed striped" id="dze-q-table">
				<thead><tr>
					<td class="check-column" style="width:2.2em;padding:8px 0 8px 3px;"><input type="checkbox" id="dze-q-all" /></td>
					<?php
					// THE LAST COLUMN GETS WHAT IS LEFT, so what is left must be
					// worth having. This table is laid out FIXED (WordPress's
					// own `.fixed`), and two of its columns are a fixed number
					// of pixels — the tick box and the id — so percentages
					// spent on the others come out of the same width: 80% of
					// them left Action with "the rest minus 123px", which is
					// nearly nothing on a narrower window. Every button in it
					// then stacked one under the other and the table ran off
					// the side of the page. Two columns are sized here and the
					// four after them share what remains, equally, so they
					// narrow together instead of starving the last one.
					?>
					<th class="dze-q-item" style="width:24%;"><?php esc_html_e( 'Item', 'dazont-ecom' ); ?></th>
					<?php echo wp_kses_post( DZE_Hub::id_th() ); ?>
					<th class="dze-q-job"><?php esc_html_e( 'Job', 'dazont-ecom' ); ?></th>
					<?php // Who ordered the work, and when it last moved. ?>
					<th class="dze-q-from"><?php esc_html_e( 'Started by', 'dazont-ecom' ); ?></th>
					<th class="dze-q-whenth"><?php esc_html_e( 'When', 'dazont-ecom' ); ?></th>
					<th class="dze-q-stateth"><?php esc_html_e( 'Status', 'dazont-ecom' ); ?></th>
					<th class="dze-q-actth"><?php esc_html_e( 'Action', 'dazont-ecom' ); ?></th>
				</tr></thead>
				<tbody><tr><td colspan="8"><span class="dze-cx-spin"></span></td></tr></tbody>
			</table>
		</div>
		<?php
		// AND WHAT WAS PUT AWAY IS SAID, with the way to it — a list that
		// quietly drops rows is a list nobody trusts.
		$counts = self::counts_for( $kinds ?: array_keys( self::kinds() ) );
		$done   = (int) ( $counts['applied'] ?? 0 ) + (int) ( $counts['skipped'] ?? 0 );
		if ( $done && class_exists( 'DZE_Screens' ) ) {
			printf(
				'<p class="description" style="margin:12px 0 0;">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html( sprintf(
					/* translators: %s: how many were decided */
					_n( '%s decision has already been taken.', '%s decisions have already been taken.', $done, 'dazont-ecom' ),
					number_format_i18n( $done )
				) ),
				esc_url( DZE_Screens::url( 'logs', 'past' ) ),
				esc_html__( 'See what was published, and undo it →', 'dazont-ecom' )
			);
		}
		self::review_assets( $kinds );
	}

	/**
	 * WHAT THE REVIEW POPUP NEEDS, wherever it is opened from.
	 *
	 * A BODY THAT MOVES TAKES ITS ASSETS WITH IT. These rows are no longer
	 * only on Content to review: a task's own block on the Automation screen
	 * lists what that task left waiting and settles it in place — "ici ce
	 * serait bien de pouvoir review la task directement sans partir". A second
	 * popup beside this one is two surfaces for one decision, which is how two
	 * screens start disagreeing, so there is ONE: the script, its words and
	 * its markup come from here, and a screen that borrows the rows next year
	 * has nothing to remember.
	 */
	public static function review_assets( array $kinds = [] ): void {
		DZE_Assets::admin_css();
		wp_enqueue_editor();
		if ( class_exists( 'DZE_Prompts' ) ) {
			DZE_Prompts::print_assets(); // the review popup shows the prompt behind the job.
		}
		// A BODY TAKES ITS ASSETS WITH IT, and the rows here are drawn by the
		// shared machinery now: without hub.js on the page, the first row would
		// die on `window.dzeHub` being undefined and the whole list with it.
		DZE_Hub::assets();
		wp_enqueue_script( 'dze-queue', DZE_URL . 'admin/js/queue.js', [ 'jquery', 'dze-hub' ], DZE_VERSION, true );
		wp_localize_script( 'dze-queue', 'dzeQueue', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			// The screen's own work, so every call it makes is already limited
			// to it and nothing has to remember to pass a scope.
			'kinds'   => array_values( self::clean_kinds( $kinds ) ),
			'i18n'    => [
				'error'    => __( 'Something went wrong.', 'dazont-ecom' ),
				'review'   => __( 'Review', 'dazont-ecom' ),
				// L'ARTICLE TEL QU'IL SERAIT, par l'aperçu de WordPress.
				// « Difficile à relire à cause du format. »
				'preview'  => __( 'Preview', 'dazont-ecom' ),
				'prevTip'  => __( 'Opens the page as a reader would see it, with these links in place. Nothing is saved.', 'dazont-ecom' ),
				'retry'    => __( 'Retry', 'dazont-ecom' ),
				'remove'   => __( 'Remove', 'dazont-ecom' ),
				// The states in words, and translatable: they were written
				// into the JavaScript, so no shop could read them in its own
				// language and a row arriving looked like an empty line.
				'sQueued'  => __( 'Waiting its turn', 'dazont-ecom' ),
				'sRunning' => __( 'Being written…', 'dazont-ecom' ),
				'sReview'  => __( 'To review', 'dazont-ecom' ),
				'sApplied' => __( 'Saved', 'dazont-ecom' ),
				'sFailed'  => __( 'Failed', 'dazont-ecom' ),
				'sSkipped' => __( 'Discarded', 'dazont-ecom' ),
				// « Ça dit que le module ne fonctionne pas bien. » Une page ou
				// il n y avait rien a poser n est ni une panne ni un rejet.
				'sNothing' => __( 'Nothing to link here', 'dazont-ecom' ),
				'empty'    => __( 'Nothing in the queue.', 'dazont-ecom' ),
				'idle'     => __( 'Nothing waiting.', 'dazont-ecom' ),
				'pause'    => __( 'Pause', 'dazont-ecom' ),
				'resume'   => __( 'Resume', 'dazont-ecom' ),
				/* translators: %s: number of jobs */
				'cWaiting' => __( '%s waiting', 'dazont-ecom' ),
				/* translators: %s: number of jobs */
				'cWriting' => __( '%s being written', 'dazont-ecom' ),
				/* translators: %s: number of jobs */
				'cReview'  => __( '%s to review', 'dazont-ecom' ),
				/* translators: %s: number of jobs */
				'cSaved'   => __( '%s saved', 'dazont-ecom' ),
				/* translators: %s: number of jobs */
				'cFailed'  => __( '%s failed', 'dazont-ecom' ),
				/* translators: %s: number of finished rows */
				'clearN'   => __( 'clear %s failed rows', 'dazont-ecom' ),
				/* translators: %s: number of rows ticked */
				'selected' => __( '%s selected:', 'dazont-ecom' ),
				'nowText'  => __( 'On the category today', 'dazont-ecom' ),
				'acceptOne'=> self::decide_words()['accept'],
				'refuseOne'=> self::decide_words()['refuse'],
				'dropOne'  => __( 'Drop this line from the queue', 'dazont-ecom' ),
				'confirmOne' => __( 'Save this text onto the page it was written for, as written? It replaces what is there now.', 'dazont-ecom' ),
				'confirmRefuse' => __( 'Throw this text away? It cannot be recovered.', 'dazont-ecom' ),
				'compare'  => __( 'Current', 'dazont-ecom' ),
				'accept'   => __( 'Accept and save', 'dazont-ecom' ),
				'discardBtn' => __( 'Cancel', 'dazont-ecom' ),
				/* translators: %s: number of words */
				'words'    => __( '%s words', 'dazont-ecom' ),
				/* translators: 1: words before, 2: words after */
				'wordsTo'  => __( '%1$s words → %2$s words', 'dazont-ecom' ),
				/* translators: 1: links before, 2: links after */
				'linksTo'  => __( '%1$s links → %2$s links', 'dazont-ecom' ),
				// A photograph's own three answers, said as answers and not as
				// database words: keep it, make another, throw it away.
				'alreadyHas'  => __( 'What this product already shows', 'dazont-ecom' ),
				'keepShot'    => __( 'Keep it', 'dazont-ecom' ),
				'againShot'   => __( 'Make another', 'dazont-ecom' ),
				'dropShot'    => __( 'Throw it away', 'dazont-ecom' ),
				// The same word the server-printed lists put on that symbol.
				'visitTip'    => DZE_Hub::visit_word(),
				'openProduct' => __( 'Open the product', 'dazont-ecom' ),
				/* translators: %s: number of texts */
				'confirmAccept' => __( 'Save %s texts onto their categories, as written? Anything you wanted to edit should be opened one by one instead.', 'dazont-ecom' ),
				/* translators: %s: number of jobs */
				'confirmDrop'   => __( 'Drop %s jobs? What they wrote is lost.', 'dazont-ecom' ),
				'confirm'  => __( 'Remove every failed and skipped job from this list? What was accepted is kept, so there is always a record of what was done.', 'dazont-ecom' ),
				'applying' => __( 'Saving…', 'dazont-ecom' ),
				'applied'  => __( 'Saved ✓', 'dazont-ecom' ),
				'discarded' => __( 'Discarded', 'dazont-ecom' ),
			],
		] );
		?>
		<div class="dze-cx-modal" id="dze-q-modal"><div class="dze-cx-dialog" style="width:min(860px,94vw);">
			<div class="dze-cx-head"><h2 id="dze-q-title"><?php esc_html_e( 'Review', 'dazont-ecom' ); ?></h2>
				<button type="button" class="dze-prompt-peek" id="dze-q-prompt" data-prompt="" style="display:none;" title="<?php esc_attr_e( 'See the instructions sent to the model, and edit them', 'dazont-ecom' ); ?>">&#9998; <?php esc_html_e( 'prompt', 'dazont-ecom' ); ?></button>
				<button type="button" class="button dze-hub-close" style="margin-left:auto;"><?php esc_html_e( 'Close', 'dazont-ecom' ); ?></button></div>
			<div class="dze-cx-body" id="dze-q-body"></div>
		</div></div>
		<?php
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	private function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
	}

	/**
	 * The kinds the calling screen says it is responsible for.
	 *
	 * Sanitised against the catalogue, so a hand-written request cannot ask
	 * for a kind that does not exist — and an empty answer means "everything",
	 * which is what the one inbox sends.
	 *
	 * @return array<int,string>
	 */
	private static function asked_kinds(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller has run guard() first.
		$raw = isset( $_POST['kinds'] ) ? wp_unslash( $_POST['kinds'] ) : [];
		$raw = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		return array_values( self::clean_kinds( array_map( 'sanitize_key', $raw ) ) );
	}
	public function ajax_status(): void {
		$this->guard();
		self::recover(); // a run the server killed must not sit here for ever.
		$rows = [];
		foreach ( self::rows( 200, self::asked_kinds() ) as $r ) {
			$p     = $r['payload'] ?? '';
			$p     = $p ? (array) json_decode( (string) $p, true ) : [];
			$total = isset( $p['plan']['sections'] ) ? count( (array) $p['plan']['sections'] ) : 0;
			$step  = (int) ( $p['step'] ?? -1 );
			$rows[] = [
				'id'       => (int) $r['id'],
				'label'    => self::label_for( (string) $r['kind'], (int) $r['object_id'] ),
				// ITS ID, on every list that names objects. The row is drawn in
				// the browser, so the figure has to travel — a screen cannot
				// print what it was never sent.
				'oid'      => (int) $r['object_id'],
				// AND THE TWO WAYS TO IT. This list named an object and offered
				// no way to it at all — not even its editor: the label was
				// plain text. "Tu as oublié le bouton lien pour aller voir la
				// page on site. Ça devrait être automatique." A row drawn in
				// the browser can only print what it was sent.
				'edit'     => self::edit_link( (string) $r['kind'], (int) $r['object_id'] ),
				'view'     => self::view_link( (string) $r['kind'], (int) $r['object_id'] ),
				'kind'     => (string) ( self::kinds()[ $r['kind'] ]['label'] ?? $r['kind'] ),
				// L'ADRESSE DE L'APERÇU, ou rien. Le serveur seul sait si
				// l'objet est un document ou une description de terme — le
				// script ne reçoit que le LIBELLÉ du genre — et lui seul peut
				// signer le nonce. Une adresse vide veut dire « pas de bouton ».
				'preview'  => ( 'review' === (string) $r['status'] && get_post( (int) $r['object_id'] ) )
					? self::preview_link( (int) $r['id'] )
					: '',
				'status'   => (string) $r['status'],
				// QUI A DECIDE, OU PERSONNE. Un refus de la boutique porte un
				// nom ; une conclusion du module — « rien a poser ici » — n en a
				// pas. Les deux finissent en « skipped », et c est ce zero qui
				// les distingue a l ecran.
				'own'      => (int) ( $r['decided_by'] ?? 0 ) > 0,
				'error'    => (string) ( $r['error'] ?? '' ),
				'progress' => $total ? sprintf(
					/* translators: 1: section written, 2: sections in total */
					__( 'section %1$s of %2$s', 'dazont-ecom' ),
					number_format_i18n( max( 0, min( $total, $step + 1 ) ) ),
					number_format_i18n( $total )
				) : '',
				// WHO DECIDED IT, said on the row it belongs to. A shop that
				// hands this work to somebody else needs to see that a page
				// was dealt with AND by whom; the sentence is built here, in
				// PHP, so it is not English on every shop.
				'who'      => self::said_by( (string) $r['status'], (int) ( $r['decided_by'] ?? 0 ) ),
				// WHO ASKED FOR IT, and WHEN it last moved: two columns of
				// their own, because a list of work with neither cannot answer
				// "when was that done" or "who ordered this".
				'from'     => self::started_by( (int) ( $r['made_by'] ?? 0 ) ),
				'when'     => self::moment( (string) ( $r['updated'] ?? '' ) ),
			];
		}
		wp_send_json_success( [ 'rows' => $rows, 'counts' => self::counts() ] );
	}

	/** Put a job back in the queue, or drop it, from the screen. */
	public function ajax_job_action(): void {
		$this->guard();
		global $wpdb;
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Job not found.', 'dazont-ecom' ) ] );
		}
		if ( 'remove' === $do ) {
			$wpdb->delete( self::table(), [ 'id' => $id ] );
			delete_transient( self::LOCK );
			wp_send_json_success( [ 'removed' => 1 ] );
		}
		if ( 'retry' === $do ) {
			// Start this one over: the plan and the sections already written are
			// dropped, so a run that went wrong does not poison the next one.
			$wpdb->update( self::table(), [
				'status'  => 'queued',
				'result'  => null,
				'error'   => null,
				'payload' => null,
				'updated' => current_time( 'mysql' ),
			], [ 'id' => $id ] );
			delete_transient( self::LOCK );
			self::kick();
			wp_send_json_success( [ 'requeued' => 1 ] );
		}
		wp_send_json_error( [ 'message' => __( 'Unknown action.', 'dazont-ecom' ) ] );
	}

	/** Kicks the worker. Returns at once: the work does not happen here. */
	public function ajax_run(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [], 403 );
		}
		self::work();
		wp_send_json_success( self::counts() );
	}

	/** How many links a text carries, counted the way the linking pass does. */
	private static function links_in( string $html ): int {
		return (int) preg_match_all( '/<a\s[^>]*href=/i', $html );
	}

	public function ajax_review(): void {
		$this->guard();
		global $wpdb;
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		if ( ! $job ) {
			wp_send_json_error( [ 'message' => __( 'Job not found.', 'dazont-ecom' ) ] );
		}
		// A photograph is not a text, and its review is not a diff: the new
		// picture, the ones the product already has beside it, and two
		// answers. Nothing about the product has changed at this point.
		if ( ! empty( self::kinds()[ $job['kind'] ]['image'] ) ) {
			$pid  = (int) $job['object_id'];
			$has  = [];
			if ( function_exists( 'wc_get_product' ) ) {
				$one = wc_get_product( $pid );
				if ( $one && is_object( $one ) ) {
					foreach ( array_merge( [ (int) $one->get_image_id() ], (array) $one->get_gallery_image_ids() ) as $att ) {
						$url = $att ? (string) wp_get_attachment_image_url( (int) $att, 'medium' ) : '';
						if ( '' !== $url ) {
							$has[] = $url;
						}
					}
				}
			}
			wp_send_json_success( [
				'id'    => $id,
				'title' => self::label_for( (string) $job['kind'], $pid ),
				'image' => true,
				'shot'  => (string) $job['result'],
				'has'   => $has,
				'edit'  => (string) get_edit_post_link( $pid, '' ),
			] );
		}
		$old = self::holds_now( (string) $job['kind'], (int) $job['object_id'] );
		wp_send_json_success( [
			'id'      => $id,
			'title'   => self::label_for( (string) $job['kind'], (int) $job['object_id'] ),
			// Which instruction block wrote this, so a disappointing result can
			// be traced back to its prompt from the review itself.
			'prompt'  => (string) $job['kind'],
			'html'    => (string) $job['result'],
			'current' => $old,
			'words'   => [ str_word_count( wp_strip_all_tags( $old ) ), str_word_count( wp_strip_all_tags( (string) $job['result'] ) ) ],
			// AND THE LINKS. On a linking pass the word count is identical by
			// design — "1094 words → 1094 words" is the whole of what the
			// screen said about a job whose entire purpose is the other
			// figure. Counted the same way the linking pass counts them.
			'links'   => [ self::links_in( $old ), self::links_in( (string) $job['result'] ) ],
		] );
	}

	/** Accept (optionally edited), or discard. */
	/**
	 * L'ARTICLE TEL QU'IL SERAIT, PAR LE MÉCANISME DE WORDPRESS LUI-MÊME.
	 *
	 * « Articles de blog : difficile à relire à cause du format. Possible
	 * peut-être d'activer un bouton qui redirige vers une preview générée
	 * instantanément ? Comme dans le rédacteur WordPress… pour imiter le reste
	 * des posts, qui sont tous visibles avant publication des changements. »
	 *
	 * Relire du HTML dans une boîte marche pour une description de catégorie
	 * de dix lignes. Sur un article de trente mille caractères, avec ses
	 * titres, ses listes et ses images, personne ne peut juger un lien au
	 * milieu de tout ça.
	 *
	 * Alors on ne fabrique pas un aperçu : on utilise CELUI de WordPress. Le
	 * texte proposé est écrit dans une sauvegarde automatique de l'article —
	 * exactement ce que fait l'éditeur quand on clique « Prévisualiser les
	 * modifications » — et l'adresse rendue est l'adresse d'aperçu standard.
	 * Le thème, les blocs, les polices : tout est celui du site, parce que
	 * c'est le site qui l'affiche.
	 *
	 * La sauvegarde est retirée dès que le travail est décidé, pour qu'un
	 * « une sauvegarde plus récente existe » ne vienne pas hanter l'éditeur.
	 */
	/**
	 * L'adresse du bouton d'aperçu d'un travail, nonce compris — BRUTE.
	 *
	 * Pas `wp_nonce_url()` : celui-là rend une adresse déjà échappée pour le
	 * HTML, avec des `&amp;`. Elle part d'ici en JSON, le script l'échappe une
	 * seconde fois avant de l'écrire dans un href, et le navigateur reçoit
	 * `&amp;amp;` — les paramètres sont perdus, le nonce avec eux, et le clic
	 * tombe sur « lien expiré ». Ce qui traverse du JSON doit être brut ; c'est
	 * celui qui écrit le HTML qui échappe, une fois.
	 */
	public static function preview_link( int $job_id ): string {
		return add_query_arg(
			[
				'action'   => 'dze_q_preview',
				'job'      => $job_id,
				'_wpnonce' => wp_create_nonce( 'dze_q_preview_' . $job_id ),
			],
			admin_url( 'admin-post.php' )
		);
	}

	public function preview_page(): void {
		$id = isset( $_GET['job'] ) ? absint( $_GET['job'] ) : 0;
		check_admin_referer( 'dze_q_preview_' . $id );
		global $wpdb;
		$job = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		if ( ! $job ) {
			wp_die( esc_html__( 'Job not found.', 'dazont-ecom' ) );
		}
		$pid  = (int) $job['object_id'];
		$post = get_post( $pid );
		if ( ! $post ) {
			// UNE CATEGORIE N'A PAS D'APERÇU : elle n'est pas un document, et
			// dire pourquoi vaut mieux qu'un bouton qui ne fait rien.
			wp_die( esc_html__( 'Only a post or a page can be previewed. A category description is read here.', 'dazont-ecom' ) );
		}
		if ( ! current_user_can( 'edit_post', $pid ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ), 403 );
		}
		$html = (string) $job['result'];
		if ( '' === trim( $html ) ) {
			wp_die( esc_html__( 'This job holds no text to preview.', 'dazont-ecom' ) );
		}

		// LA PAGE D'ATTENTE D'ABORD, ET ENVOYÉE TOUT DE SUITE.
		//
		// « Sur WordPress, c'est le logo WordPress qui charge à l'écran pour
		// montrer qu'il se passe quelque chose. » C'est ce que fait l'éditeur
		// de blocs : il ouvre l'onglet sur un message d'attente, écrit la
		// sauvegarde, puis remplace l'adresse. On fait pareil — avec le logo et
		// l'image d'attente de WordPress, pas les nôtres.
		//
		// Envoyée AVANT le travail, et les tampons vidés : autrement elle
		// arriverait en même temps que la redirection, c'est-à-dire jamais.
		self::preview_waiting_page();
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		flush();

		require_once ABSPATH . 'wp-admin/includes/post.php';
		$saved = wp_create_post_autosave( [
			'post_ID'      => $pid,
			'post_type'    => (string) $post->post_type,
			'post_title'   => (string) $post->post_title,
			'post_content' => $html,
			'post_excerpt' => (string) $post->post_excerpt,
		] );
		if ( is_wp_error( $saved ) ) {
			printf( '<p class="dze-pv-err">%s</p></div></body></html>', esc_html( $saved->get_error_message() ) );
			exit;
		}
		$url = (string) get_preview_post_link( $pid, [
			'preview_id'    => $pid,
			'preview_nonce' => wp_create_nonce( 'post_preview_' . $pid ),
		] );
		// LE SCHEMA DU SITE, PAS CELUI QUE LE PERMALIEN A SOUS LA MAIN. Sur
		// cette boutique `home` est en https et `siteurl` en http, et le lien
		// d'aperçu sortait en http : le cookie de session est marqué « secure »,
		// il ne serait pas envoyé, et l'aperçu répondrait « vous n'avez pas
		// l'autorisation » sur un nonce parfaitement valide.
		$scheme = (string) wp_parse_url( (string) home_url(), PHP_URL_SCHEME );
		if ( '' !== $scheme ) {
			$url = (string) set_url_scheme( $url, $scheme );
		}
		// `replace` et non `href` : cette page d'attente ne reste pas dans
		// l'historique, donc « précédent » ramène à l'écran de relecture.
		printf(
			'<script>window.location.replace(%1$s);</script>'
				. '<noscript><meta http-equiv="refresh" content="0;url=%2$s" />'
				. '<p><a href="%2$s">%3$s</a></p></noscript></div></body></html>',
			wp_json_encode( $url ),
			esc_url( $url ),
			esc_html__( 'Open the preview', 'dazont-ecom' )
		);
		exit;
	}

	/**
	 * Le message d'attente, avec les images de WordPress et rien d'autre.
	 *
	 * `wordpress-logo.svg` et `spinner-2x.gif` sont dans wp-admin depuis
	 * toujours : c'est le logo que la boutique voit pendant une mise à jour, et
	 * l'image d'attente de tous ses écrans. Les styles sont en ligne parce que
	 * cette page vit une demi-seconde, et qu'une feuille de style de plus est
	 * une requête de plus avant de montrer quoi que ce soit.
	 */
	private static function preview_waiting_page(): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php esc_html_e( 'Generating preview…', 'dazont-ecom' ); ?></title>
<style>
html,body{height:100%;margin:0;background:#f0f0f1;color:#3c434a;
font:400 14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",sans-serif}
.dze-pv{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;text-align:center}
.dze-pv .logo{width:84px;height:84px;opacity:.85}
.dze-pv p{margin:0}
.dze-pv .wait{display:inline-block;vertical-align:middle;width:20px;height:20px;margin-right:6px}
.dze-pv-err{color:#b32d2e;max-width:34em}
@media (prefers-color-scheme:dark){html,body{background:#1d2327;color:#f0f0f1}}
</style>
</head>
<body>
<div class="dze-pv">
<img class="logo" src="<?php echo esc_url( admin_url( 'images/wordpress-logo.svg' ) ); ?>" alt="" />
<p><img class="wait" src="<?php echo esc_url( admin_url( 'images/spinner-2x.gif' ) ); ?>" alt="" />
<?php esc_html_e( 'Generating preview…', 'dazont-ecom' ); ?></p>
		<?php
	}

	/**
	 * Retire la sauvegarde automatique posée pour l'aperçu.
	 *
	 * Sans cela l'éditeur accueillerait la boutique avec « il existe une
	 * sauvegarde automatique plus récente que cet article » — un avertissement
	 * juste, pour une raison que personne ne pourrait deviner.
	 */
	private static function drop_preview( int $post_id ): void {
		if ( $post_id < 1 || ! function_exists( 'wp_get_post_autosave' ) ) {
			return;
		}
		$auto = wp_get_post_autosave( $post_id, get_current_user_id() );
		if ( $auto ) {
			wp_delete_post_revision( (int) $auto->ID );
		}
	}

	public function ajax_decide(): void {
		self::forget_count();
		$this->guard();
		global $wpdb;
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$accept = ! empty( $_POST['accept'] );
		$html   = isset( $_POST['html'] ) ? wp_kses_post( wp_unslash( $_POST['html'] ) ) : '';
		$job    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		if ( ! $job ) {
			wp_send_json_error( [ 'message' => __( 'Job not found.', 'dazont-ecom' ) ] );
		}
		// DECIDE, DONC PLUS D'APERÇU A TRAINER. Voir drop_preview().
		self::drop_preview( (int) $job['object_id'] );
		if ( ! $accept ) {
			$wpdb->update( self::table(), [
				'status'     => 'skipped',
				'decided_by' => self::decider(),
				'updated'    => current_time( 'mysql' ),
			], [ 'id' => $id ] );
			wp_send_json_success( [ 'status' => 'skipped' ] );
		}
		$html = '' !== trim( $html ) ? $html : (string) $job['result'];
		$ok   = self::apply(
			(string) $job['kind'],
			(int) $job['object_id'],
			$html,
			$job['payload'] ? (array) json_decode( (string) $job['payload'], true ) : []
		);
		$wpdb->update( self::table(), [
			'status'     => $ok ? 'applied' : 'failed',
			'result'     => $html,
			'error'      => $ok ? null : ( self::refusal() ?: __( 'Saving failed.', 'dazont-ecom' ) ),
			'decided_by' => self::decider(),
			'updated'    => current_time( 'mysql' ),
		], [ 'id' => $id ] );
		wp_send_json_success( [ 'status' => $ok ? 'applied' : 'failed' ] );
	}

	/**
	 * WHO IS DECIDING, right now.
	 *
	 * 0 when nobody is: a scheduled pass that saves without review has no
	 * person behind it, and naming one would be a lie on the row.
	 */
	private static function decider(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * WHO ASKED FOR THIS WORK — a name, or where it came from.
	 *
	 * Not the same question as who decided it: a decision has nobody when a
	 * scheduled pass saved without review, and saying "by the shop" there would
	 * invent a person. An ORIGIN always has an answer, and "Automatic" is that
	 * answer — the row came from the pass that runs on its own.
	 */
	public static function started_by( int $user_id ): string {
		$who = self::decided_by( $user_id );
		return '' !== $who ? $who : __( 'Automatic', 'dazont-ecom' );
	}

	/**
	 * WHEN, on the row, in the shop's own date and time format.
	 *
	 * "Il faudra impérativement une date affichée sur chaque action. Pour
	 * savoir quand ça a été fait." One moment per row and no second figure
	 * beside it: the last time this job moved, which is when it was written for
	 * a row waiting, and when it was accepted or refused for one that is done.
	 */
	public static function moment( string $mysql ): string {
		$mysql = trim( $mysql );
		if ( '' === $mysql || '0000-00-00 00:00:00' === $mysql ) {
			return '';
		}
		return (string) mysql2date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$mysql
		);
	}

	/**
	 * The person behind a decision, as a name to print.
	 *
	 * The display name the shop already knows, never an id: "12" on a row is
	 * a number somebody has to go and look up. An account deleted since keeps
	 * its decision — the work was still done — and says so.
	 */
	public static function decided_by( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$who = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
		return ( $who && ! empty( $who->display_name ) )
			? (string) $who->display_name
			/* translators: %d: a WordPress user id */
			: sprintf( __( 'a deleted account (%d)', 'dazont-ecom' ), $user_id );
	}

	/**
	 * "Accepted by Marie", in one sentence, or nothing at all.
	 *
	 * Only for a job that has actually been decided: a row still waiting has
	 * nobody to name, and a scheduled pass that saved without review has
	 * nobody either — saying "by the shop" there would be inventing a person.
	 */
	public static function said_by( string $status, int $user_id ): string {
		$who = self::decided_by( $user_id );
		if ( '' === $who ) {
			return '';
		}
		if ( 'applied' === $status ) {
			/* translators: %s: the person who accepted it */
			return sprintf( __( 'Accepted by %s', 'dazont-ecom' ), $who );
		}
		if ( 'skipped' === $status ) {
			/* translators: %s: the person who threw it away */
			return sprintf( __( 'Discarded by %s', 'dazont-ecom' ), $who );
		}
		return '';
	}

	/** Queue one item from wherever it is being looked at. */
	public function ajax_add(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$urls = isset( $_POST['urls'] ) && is_array( $_POST['urls'] )
			? array_map( 'esc_url_raw', array_map( 'wp_unslash', $_POST['urls'] ) )
			: [];
		global $wpdb;
		$payload = [];
		if ( $urls ) {
			$payload['urls'] = $urls;
		}
		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		if ( '' !== trim( $prompt ) ) {
			$payload['prompt'] = $prompt;
		}
		// WHAT IS ON SCREEN IS WHAT TRAVELS. A panel writes into an editor and
		// saves nothing until Update is pressed, so a pass that works on the
		// STORED text works on something the person is not looking at — and on
		// a category whose description has never been saved, on nothing at
		// all. ABSENT means "as it stands", which is what an automatic pass
		// sends; present means "this exact text".
		$html = isset( $_POST['html'] ) ? wp_kses_post( wp_unslash( $_POST['html'] ) ) : '';
		if ( '' !== trim( $html ) ) {
			$payload['html'] = $html;
		}
		$n = self::add( $kind, [ $id ], false, $payload );
		// Follow this exact job, whether it was just added or already waiting.
		$job = (int) $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			"SELECT id FROM " . self::table() . " WHERE kind = %s AND object_id = %d AND status IN ('queued','running','review') ORDER BY id DESC LIMIT 1",
			$kind,
			$id
		) );
		wp_send_json_success( [
			'added' => $n,
			'job'   => $job,
			'url'   => self::url(),
		] );
	}

	/**
	 * One job's state — and, while a panel is watching, the engine that moves
	 * it along.
	 *
	 * Cron and loopback requests are not reliable on every host; an open panel
	 * is. So a poll that finds work to do runs ONE step itself and returns the
	 * result. Each poll is therefore a short request, well inside any limit,
	 * and the description advances section by section in front of you.
	 */
	public function ajax_job(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		global $wpdb;
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		if ( ! $job ) {
			wp_send_json_error( [ 'message' => __( 'Job not found.', 'dazont-ecom' ) ] );
		}
		// Nothing else has taken it? Take it here, one step.
		$idle = strtotime( (string) $job['updated'] ) < ( current_time( 'timestamp' ) - 20 ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- compared with a site-time column.
		if ( 'queued' === $job['status'] || ( 'running' === $job['status'] && $idle ) ) {
			self::work( $id );
			$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		}
		$p     = $job['payload'] ? (array) json_decode( (string) $job['payload'], true ) : [];
		$total = isset( $p['plan']['sections'] ) ? count( (array) $p['plan']['sections'] ) : 0;
		$step  = (int) ( $p['step'] ?? -1 );
		wp_send_json_success( [
			'status'   => (string) $job['status'],
			'html'     => in_array( $job['status'], [ 'review', 'applied' ], true ) ? (string) $job['result'] : '',
			'error'    => (string) ( $job['error'] ?? '' ),
			'step'     => max( 0, $step + 1 ),
			'total'    => $total,
			// HOW MANY RUNS ARE IN FRONT OF THIS ONE. A screen that has been
			// saying "waiting" for half a minute has to be able to say what it
			// is waiting for, or it reads as a broken button.
			'ahead'    => (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ('queued','running') AND id < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
				$id
			) ),
			'progress' => $total ? sprintf(
				/* translators: 1: section written, 2: sections in total */
				__( 'section %1$s of %2$s', 'dazont-ecom' ),
				number_format_i18n( max( 0, min( $total, $step + 1 ) ) ),
				number_format_i18n( $total )
			) : __( 'planning the page…', 'dazont-ecom' ),
		] );
	}

	/**
	 * The same decisions, taken on several jobs at once. Accepting in bulk
	 * saves each result exactly as it was written — the per-item review is
	 * where editing happens.
	 */
	public function ajax_bulk(): void {
		self::forget_count();
		$this->guard();
		global $wpdb;
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] )
			? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['ids'] ) ) ) )
			: [];
		if ( ! $ids ) {
			wp_send_json_error( [ 'message' => __( 'Nothing selected.', 'dazont-ecom' ) ] );
		}
		$table = self::table();
		$now   = current_time( 'mysql' );
		$ok    = 0;
		$fail  = 0;

		foreach ( $ids as $id ) {
			$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			if ( ! $job ) {
				continue;
			}
			switch ( $do ) {
				case 'accept':
					if ( 'review' !== $job['status'] ) {
						continue 2;
					}
					$saved = self::apply( (string) $job['kind'], (int) $job['object_id'], (string) $job['result'] );
					$wpdb->update( $table, [
						'status'     => $saved ? 'applied' : 'failed',
						'error'      => $saved ? null : ( self::refusal() ?: __( 'Saving failed.', 'dazont-ecom' ) ),
						'decided_by' => self::decider(),
						'updated'    => $now,
					], [ 'id' => $id ] );
					$saved ? $ok++ : $fail++;
					break;

				case 'discard':
					$wpdb->update( $table, [
						'status'     => 'skipped',
						'decided_by' => self::decider(),
						'updated'    => $now,
					], [ 'id' => $id ] );
					$ok++;
					break;

				case 'retry':
					$wpdb->update( $table, [
						'status'  => 'queued',
						'result'  => null,
						'error'   => null,
						'payload' => null,
						'updated' => $now,
					], [ 'id' => $id ] );
					$ok++;
					break;

				case 'remove':
					$wpdb->delete( $table, [ 'id' => $id ] );
					$ok++;
					break;

				default:
					wp_send_json_error( [ 'message' => __( 'Unknown action.', 'dazont-ecom' ) ] );
			}
		}
		if ( in_array( $do, [ 'retry', 'remove' ], true ) ) {
			delete_transient( self::LOCK );
			if ( 'retry' === $do ) {
				self::kick();
			}
		}
		wp_send_json_success( [
			'done'    => $ok,
			'failed'  => $fail,
			/* translators: 1: jobs handled, 2: jobs that failed */
			'message' => sprintf( __( '%1$s done, %2$s failed', 'dazont-ecom' ), number_format_i18n( $ok ), number_format_i18n( $fail ) ),
		] );
	}

	public function ajax_clear(): void {
		self::forget_count();
		$this->guard();
		global $wpdb;
		// NEVER 'applied'. What was accepted is the record of what this plugin
		// did to the shop — the only place that says a product was worked on,
		// when, by which job, and that somebody said yes to it. Wiping it left
		// no way to check anything after the fact, which is the whole reason
		// nothing here could be trusted without reopening the product.
		// ONLY THIS SCREEN'S OWN. "Clear" on the linking screen tidying away
		// a failed photograph is a button that does more than it says.
		$kinds = self::asked_kinds();
		$scope = $kinds ? " AND kind IN ('" . implode( "','", $kinds ) . "')" : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, kinds sanitised.
		$n = (int) $wpdb->query( "DELETE FROM " . self::table() . " WHERE status IN ('failed','skipped')" . $scope );
		wp_send_json_success( [ 'removed' => $n ] );
	}
}
