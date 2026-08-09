<?php
defined( 'ABSPATH' ) || exit;

/**
 * Writing queue — send a batch off to be written, review it, apply it.
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
	private const SCHEMA_VERSION = 1;
	private const LOCK      = 'dze_queue_lock';
	private const COUNT_KEY = 'dze_queue_review_count';

	/** Job kinds: what each one writes, and who knows how to write it. */
	public static function kinds(): array {
		return [
			'cat_desc'  => [
				'label'  => __( 'Category description', 'dazont-ecom' ),
				'module' => 'category_content',
			],
			'cat_links' => [
				'label'  => __( 'Category internal links', 'dazont-ecom' ),
				'module' => 'category_content',
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
		add_action( 'wp_ajax_dze_q_status', [ $this, 'ajax_status' ] );
		add_action( 'wp_ajax_dze_q_run', [ $this, 'ajax_run' ] );
		add_action( 'wp_ajax_dze_q_review', [ $this, 'ajax_review' ] );
		add_action( 'wp_ajax_dze_q_decide', [ $this, 'ajax_decide' ] );
		add_action( 'wp_ajax_dze_q_clear', [ $this, 'ajax_clear' ] );
		add_action( 'wp_ajax_dze_q_add', [ $this, 'ajax_add' ] );
		add_action( 'wp_ajax_dze_q_job', [ $this, 'ajax_job' ] );
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
				'payload'    => $payload ? wp_json_encode( $payload ) : null,
				'created'    => $now,
				'updated'    => $now,
			] );
			$added++;
		}
		if ( $added ) {
			self::kick();
		}
		return $added;
	}

	/** Asks for the worker to run, without anybody waiting for it. */
	public static function kick(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::HOOK ) ) {
				as_enqueue_async_action( self::HOOK, [], 'dazont-ecom' );
			}
			return;
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
	public static function work(): void {
		global $wpdb;
		$table = self::table();
		self::recover();
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$job = $wpdb->get_row( "SELECT * FROM {$table} WHERE status IN ('queued','running') ORDER BY FIELD(status,'running','queued'), id ASC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
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
			$applied = ! empty( $job['auto_apply'] ) && self::apply( (string) $job['kind'], (int) $job['object_id'], $result );
			$wpdb->update( $table, [
				'status'  => ! empty( $job['auto_apply'] ) ? ( $applied ? 'applied' : 'failed' ) : 'review',
				'result'  => $result,
				'payload' => wp_json_encode( $payload ),
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
			gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 5 * MINUTE_IN_SECONDS ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- matches the stored site time.
		), ARRAY_A );
		foreach ( $stale as $r ) {
			$p            = $r['payload'] ? (array) json_decode( (string) $r['payload'], true ) : [];
			$p['retries'] = (int) ( $p['retries'] ?? 0 ) + 1;
			if ( $p['retries'] > 3 ) {
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
	}

	/** Writes one job's content. Throws with a readable reason on failure. */
	private static function produce( string $kind, int $object_id, array $payload ): string {
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
			$res = DZE_Category_Content::add_links( $object_id, (string) $term->description, (array) ( $payload['urls'] ?? [] ) );
			return (string) $res['html'];
		}
		throw new RuntimeException( __( 'Unknown job type.', 'dazont-ecom' ) );
	}

	/** Saves an accepted result onto the shop. */
	public static function apply( string $kind, int $object_id, string $html ): bool {
		if ( '' === trim( $html ) ) {
			return false;
		}
		if ( 'cat_desc' === $kind || 'cat_links' === $kind ) {
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

	public static function rows( int $limit = 200 ): array {
		global $wpdb;
		$table = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			"SELECT id, kind, object_id, status, error, payload, updated FROM {$table}
			 ORDER BY FIELD(status,'running','queued','review','failed','applied','skipped'), id ASC LIMIT %d",
			$limit
		), ARRAY_A );
	}

	/**
	 * What is waiting, per object, for the category kinds. One query for a
	 * whole list screen — a badge per row must never cost a query per row.
	 *
	 * @return array<int,array{status:string,id:int,kind:string}>
	 */
	public static function pending_map(): array {
		global $wpdb;
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			$cache = [];
			return $cache;
		}
		$cache = [];
		$rows  = (array) $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			"SELECT id, kind, object_id, status FROM {$table}
			 WHERE status IN ('queued','running','review') AND kind LIKE 'cat_%'
			 ORDER BY FIELD(status,'review','running','queued'), id DESC",
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			$oid = (int) $r['object_id'];
			if ( isset( $cache[ $oid ] ) ) {
				continue; // the most advanced one wins.
			}
			$cache[ $oid ] = [ 'status' => (string) $r['status'], 'id' => (int) $r['id'], 'kind' => (string) $r['kind'] ];
		}
		return $cache;
	}

	/** The job waiting on this object, if any. */
	public static function pending_for( int $object_id ): array {
		return self::pending_map()[ $object_id ] ?? [];
	}

	/**
	 * The owner saved this category by hand: whatever was waiting for review on
	 * it is settled, and must not keep asking.
	 */
	public static function settle( int $object_id ): void {
		self::forget_count();
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			"UPDATE " . self::table() . " SET status = 'applied', updated = %s WHERE object_id = %d AND status = 'review' AND kind LIKE 'cat_%%'",
			current_time( 'mysql' ),
			$object_id
		) );
	}

	public static function label_for( string $kind, int $object_id ): string {
		if ( 0 === strpos( $kind, 'cat_' ) ) {
			$t = get_term( $object_id, 'product_cat' );
			// Decoded here, escaped once by the screen: otherwise "Bags &
			// backpacks" comes out as "Bags &amp;amp; backpacks".
			return ( $t && ! is_wp_error( $t ) ) ? html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) : sprintf( '#%d', $object_id );
		}
		return sprintf( '#%d', $object_id );
	}

	// =========================================================================
	// Screen
	// =========================================================================

	/** The screen's address, in one place: the menu it hangs from can move. */
	public static function url( array $args = [] ): string {
		return add_query_arg(
			array_merge( [ 'post_type' => 'product', 'page' => self::MENU_SLUG ], $args ),
			admin_url( 'edit.php' )
		);
	}

	public function menu(): void {
		// Next to the products bulk screen, under Products: every job this queue
		// carries writes a product CATEGORY, and that is where categories are
		// worked on. The slug is unchanged, so old links still land here.
		$parent  = 'edit.php?post_type=product';
		// The count rides on the menu label: what is waiting for a decision
		// should be visible without opening the screen it waits on.
		$waiting = self::review_count();
		$label   = __( 'Categories AI bulk', 'dazont-ecom' );
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

	/** Any change of state can change the bubble. */
	public static function forget_count(): void {
		delete_transient( self::COUNT_KEY );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		wp_enqueue_editor();
		if ( class_exists( 'DZE_Prompts' ) ) {
			DZE_Prompts::print_assets(); // the review popup shows the prompt behind the job.
		}
		wp_enqueue_script( 'dze-queue', DZE_URL . 'admin/js/queue.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-queue', 'dzeQueue', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'error'    => __( 'Something went wrong.', 'dazont-ecom' ),
				'review'   => __( 'Review', 'dazont-ecom' ),
				'retry'    => __( 'Retry', 'dazont-ecom' ),
				'remove'   => __( 'Remove', 'dazont-ecom' ),
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
				'clearN'   => __( 'clear %s finished rows', 'dazont-ecom' ),
				/* translators: %s: number of rows ticked */
				'selected' => __( '%s selected:', 'dazont-ecom' ),
				'nowText'  => __( 'On the category today', 'dazont-ecom' ),
				'acceptOne'=> __( 'Accept: save this text onto the category', 'dazont-ecom' ),
				'refuseOne'=> __( 'Refuse: throw this text away', 'dazont-ecom' ),
				'dropOne'  => __( 'Drop this line from the queue', 'dazont-ecom' ),
				'confirmOne' => __( 'Save this text onto the category, as written? It replaces the description currently there.', 'dazont-ecom' ),
				'confirmRefuse' => __( 'Throw this text away? It cannot be recovered.', 'dazont-ecom' ),
				'compare'  => __( 'Current', 'dazont-ecom' ),
				'accept'   => __( 'Accept and save', 'dazont-ecom' ),
				'discardBtn' => __( 'Discard', 'dazont-ecom' ),
				/* translators: %s: number of words */
				'words'    => __( '%s words', 'dazont-ecom' ),
				/* translators: 1: words before, 2: words after */
				'wordsTo'  => __( '%1$s words → %2$s words', 'dazont-ecom' ),
				/* translators: %s: number of texts */
				'confirmAccept' => __( 'Save %s texts onto their categories, as written? Anything you wanted to edit should be opened one by one instead.', 'dazont-ecom' ),
				/* translators: %s: number of jobs */
				'confirmDrop'   => __( 'Drop %s jobs? What they wrote is lost.', 'dazont-ecom' ),
				'confirm'  => __( 'Remove every finished and failed job from this list?', 'dazont-ecom' ),
				'applying' => __( 'Saving…', 'dazont-ecom' ),
				'applied'  => __( 'Saved ✓', 'dazont-ecom' ),
				'discarded' => __( 'Discarded', 'dazont-ecom' ),
			],
		] );
		?>
		<div class="wrap dze-admin">
			<h1><?php esc_html_e( 'Categories AI bulk', 'dazont-ecom' ); ?></h1>
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
				<button type="button" class="button dze-q-bulk" data-do="discard"><?php esc_html_e( 'Discard', 'dazont-ecom' ); ?></button>
				<button type="button" class="button dze-q-bulk" data-do="retry"><?php esc_html_e( 'Retry', 'dazont-ecom' ); ?></button>
				<button type="button" class="button-link dze-q-bulk" data-do="remove" style="color:#b32d2e;"><?php esc_html_e( 'Remove', 'dazont-ecom' ); ?></button>
				<span id="dze-q-bulkstatus" class="description"></span>
			</p>
			<table class="wp-list-table widefat fixed striped" id="dze-q-table">
				<thead><tr>
					<td class="check-column" style="width:2.2em;padding:8px 0 8px 3px;"><input type="checkbox" id="dze-q-all" /></td>
					<th style="width:30%;"><?php esc_html_e( 'Item', 'dazont-ecom' ); ?></th>
					<th style="width:22%;"><?php esc_html_e( 'Job', 'dazont-ecom' ); ?></th>
					<th style="width:16%;"><?php esc_html_e( 'Status', 'dazont-ecom' ); ?></th>
					<th><?php esc_html_e( 'Action', 'dazont-ecom' ); ?></th>
				</tr></thead>
				<tbody><tr><td colspan="5"><span class="dze-cx-spin"></span></td></tr></tbody>
			</table>
		</div>
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

	public function ajax_status(): void {
		$this->guard();
		self::recover(); // a run the server killed must not sit here for ever.
		$rows = [];
		foreach ( self::rows() as $r ) {
			$p     = $r['payload'] ?? '';
			$p     = $p ? (array) json_decode( (string) $p, true ) : [];
			$total = isset( $p['plan']['sections'] ) ? count( (array) $p['plan']['sections'] ) : 0;
			$step  = (int) ( $p['step'] ?? -1 );
			$rows[] = [
				'id'       => (int) $r['id'],
				'label'    => self::label_for( (string) $r['kind'], (int) $r['object_id'] ),
				'kind'     => (string) ( self::kinds()[ $r['kind'] ]['label'] ?? $r['kind'] ),
				'status'   => (string) $r['status'],
				'error'    => (string) ( $r['error'] ?? '' ),
				'progress' => $total ? sprintf(
					/* translators: 1: section written, 2: sections in total */
					__( 'section %1$s of %2$s', 'dazont-ecom' ),
					number_format_i18n( max( 0, min( $total, $step + 1 ) ) ),
					number_format_i18n( $total )
				) : '',
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

	public function ajax_review(): void {
		$this->guard();
		global $wpdb;
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		if ( ! $job ) {
			wp_send_json_error( [ 'message' => __( 'Job not found.', 'dazont-ecom' ) ] );
		}
		$term = get_term( (int) $job['object_id'], 'product_cat' );
		$old  = ( $term && ! is_wp_error( $term ) ) ? (string) $term->description : '';
		wp_send_json_success( [
			'id'      => $id,
			'title'   => self::label_for( (string) $job['kind'], (int) $job['object_id'] ),
			// Which instruction block wrote this, so a disappointing result can
			// be traced back to its prompt from the review itself.
			'prompt'  => (string) $job['kind'],
			'html'    => (string) $job['result'],
			'current' => $old,
			'words'   => [ str_word_count( wp_strip_all_tags( $old ) ), str_word_count( wp_strip_all_tags( (string) $job['result'] ) ) ],
		] );
	}

	/** Accept (optionally edited), or discard. */
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
		if ( ! $accept ) {
			$wpdb->update( self::table(), [ 'status' => 'skipped', 'updated' => current_time( 'mysql' ) ], [ 'id' => $id ] );
			wp_send_json_success( [ 'status' => 'skipped' ] );
		}
		$html = '' !== trim( $html ) ? $html : (string) $job['result'];
		$ok   = self::apply( (string) $job['kind'], (int) $job['object_id'], $html );
		$wpdb->update( self::table(), [
			'status'  => $ok ? 'applied' : 'failed',
			'result'  => $html,
			'error'   => $ok ? null : __( 'Saving failed.', 'dazont-ecom' ),
			'updated' => current_time( 'mysql' ),
		], [ 'id' => $id ] );
		wp_send_json_success( [ 'status' => $ok ? 'applied' : 'failed' ] );
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
			'url'   => add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) ),
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
			self::work();
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
						'status'  => $saved ? 'applied' : 'failed',
						'error'   => $saved ? null : __( 'Saving failed.', 'dazont-ecom' ),
						'updated' => $now,
					], [ 'id' => $id ] );
					$saved ? $ok++ : $fail++;
					break;

				case 'discard':
					$wpdb->update( $table, [ 'status' => 'skipped', 'updated' => $now ], [ 'id' => $id ] );
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
		$n = (int) $wpdb->query( "DELETE FROM " . self::table() . " WHERE status IN ('applied','failed','skipped')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		wp_send_json_success( [ 'removed' => $n ] );
	}
}
