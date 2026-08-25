<?php
defined( 'ABSPATH' ) || exit;

/**
 * Internal linking upkeep — the mesh maintained on its own, slowly.
 *
 * A catalogue is not linked once. Categories are added, descriptions are
 * rewritten, a branch grows: pages that nothing points at appear all the time,
 * and finding them by hand means re-reading the whole tree. This watches for
 * them and fixes them at a human pace.
 *
 * It writes nothing itself. The pass that adds links to a description already
 * exists — DZE_Category_Content::add_links(), the same one the "links only"
 * button runs — and the queue already knows how to run it in the background
 * without a browser waiting. This module only decides WHICH category deserves
 * the next pass, and WHEN. Two answers, and nothing else:
 *
 * - which: the category the rest of the shop points at least, among those
 *   whose description still has room for a link. Orphans first, always.
 * - when: at most one category per hour, at most X per day, and never the same
 *   category twice within a month. A shop whose fifty pages all change on the
 *   same afternoon does not look like a shop being looked after.
 *
 * Each pass keeps the description it replaced, so the last ten can be put back
 * with one click.
 */
final class DZE_Link_Mesh {

	public const HOOK = 'dze_mesh_tick';

	/** Form settings (the Categories tab saves them with its own button). */
	private const OPT = 'dze_mesh_settings';

	/** What it has done: counters and the last passes. Never a form. */
	private const STATE = 'dze_mesh_state';

	/** On the term: when the mesher last worked on it, and what it replaced. */
	public const META_AT   = '_dze_mesh_at';
	public const META_PREV = '_dze_mesh_prev';

	/** How many links the description carried when it was handed over. */
	public const META_OUT = '_dze_mesh_out';

	private const NONCE = 'dze_mesh';

	/** Days before the same category may be worked on again. */
	private const COOLDOWN = 30;

	/** Days before a pass that changed nothing is tried again. */
	private const RETRY = 3;

	/** How many passes keep an undo, and how many the log shows. */
	private const KEEP = 10;

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
		add_action( 'wp_ajax_dze_mesh_run', [ __CLASS__, 'ajax_run' ] );
		add_action( 'wp_ajax_dze_mesh_undo', [ __CLASS__, 'ajax_undo' ] );
	}

	/** Switched off in Settings → Modules: the hourly tick goes with it. */
	public static function disable(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public function register_settings(): void {
		// Registered in the Categories group: one tab, one Save button, and
		// WordPress's own save — never a second mechanism of our own.
		register_setting( 'dze_catcontent_options', self::OPT, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
			'default'           => [],
			'autoload'          => false,
		] );
	}

	public static function sanitize( $in ): array {
		$in  = is_array( $in ) ? $in : [];
		$out = self::settings();
		if ( isset( $in['form'] ) ) {
			$out['on'] = empty( $in['on'] ) ? 0 : 1;
		}
		if ( isset( $in['per_day'] ) ) {
			$out['per_day'] = max( 1, min( 20, (int) $in['per_day'] ) );
		}
		return $out;
	}

	public static function settings(): array {
		$s = get_option( self::OPT, [] );
		return is_array( $s ) ? $s : [];
	}

	public static function on(): bool {
		return ! empty( self::settings()['on'] );
	}

	public static function per_day(): int {
		$n = (int) ( self::settings()['per_day'] ?? 0 );
		return $n > 0 ? min( 20, $n ) : 3;
	}

	private static function state(): array {
		$s = get_option( self::STATE, [] );
		return is_array( $s ) ? $s : [];
	}

	private static function save_state( array $s ): void {
		update_option( self::STATE, $s, false );
	}

	/** The quiet time between two automatic passes. */
	public static function gap(): int {
		return (int) max( HOUR_IN_SECONDS, floor( DAY_IN_SECONDS / max( 1, self::per_day() ) ) );
	}

	/** Passes made today — the cap is a day's worth, not a run's worth. */
	public static function done_today(): int {
		$s = self::state();
		return ( (string) ( $s['day'] ?? '' ) === current_time( 'Y-m-d' ) ) ? (int) ( $s['count'] ?? 0 ) : 0;
	}

	// =========================================================================
	// What the shop points at — the census
	// =========================================================================

	/**
	 * Every category, what its description links to, and what links to it.
	 *
	 * One term query and no per-term product count: this is read to RANK
	 * candidates, and a ranking that costs a query per category is a ranking
	 * nobody can afford to run. The expensive figure — how many links a
	 * category is allowed — is asked only of the handful at the top.
	 *
	 * @return array{time:int,rows:array<int,array{name:string,words:int,out:int,in:int}>}
	 */
	public static function survey( bool $force = false ): array {
		$cached = $force ? false : get_transient( 'dze_mesh_survey' );
		if ( is_array( $cached ) && isset( $cached['rows'] ) ) {
			return $cached;
		}
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'exclude'    => [ (int) get_option( 'default_product_cat' ) ],
		] );
		$rows = [];
		$byurl = [];
		if ( ! is_wp_error( $terms ) ) {
			$default_lang = class_exists( 'DZE_Category_Content' ) ? DZE_Category_Content::default_lang() : '';
			foreach ( $terms as $t ) {
				// Translations belong to WPML: the shop is written, and linked,
				// in its main language.
				if ( '' !== $default_lang && class_exists( 'DZE_Category_Content' )
					&& DZE_Category_Content::lang_code( (int) $t->term_id ) !== $default_lang ) {
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
		// The descriptions themselves are not worth carrying in a transient.
		foreach ( $rows as $tid => $row ) {
			unset( $rows[ $tid ]['desc'] );
		}
		$out = [ 'time' => time(), 'rows' => $rows ];
		set_transient( 'dze_mesh_survey', $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * The category that needs the next pass, or 0.
	 *
	 * Ranked on what the census already knows — inbound links first, then the
	 * emptiest description in link terms — and only then checked against the
	 * costly question: does its size allow another link at all?
	 */
	public static function next_candidate(): int {
		foreach ( array_slice( self::ranked(), 0, 25 ) as $row ) {
			$tid = (int) $row['tid'];
			if ( class_exists( 'DZE_Queue' ) && DZE_Queue::pending_for( $tid ) ) {
				continue; // already waiting to be written or reviewed.
			}
			$max = (int) DZE_Category_Content::size_for( $tid )['links'];
			if ( $max > 0 && (int) $row['out'] < $max ) {
				return $tid;
			}
		}
		return 0;
	}

	/** The head of that same ranking, for the screen to show. */
	public static function shortlist( int $n = 5 ): array {
		return array_slice( self::ranked(), 0, max( 1, $n ) );
	}

	/**
	 * Every category that may be worked on, worst-linked first.
	 *
	 * One ranking, used by the tick and by the screen: what the owner reads is
	 * exactly what the next pass will take.
	 *
	 * @return array<int,array{tid:int,name:string,in:int,out:int}>
	 */
	private static function ranked(): array {
		$rows = self::survey()['rows'];
		if ( ! $rows ) {
			return [];
		}
		$cool = time() - self::COOLDOWN * DAY_IN_SECONDS;
		$list = [];
		foreach ( $rows as $tid => $row ) {
			// Nothing to weave links into: this tool maintains a mesh, it does
			// not write the pages. An empty category is the writer's job.
			if ( $row['words'] < 120 ) {
				continue;
			}
			$last = (int) get_term_meta( $tid, self::META_AT, true );
			if ( $last > $cool ) {
				// A pass that added nothing — the queue failed, or the model
				// found no place for one — must not lock the page out for a
				// month: it is offered again in a few days.
				$had = get_term_meta( $tid, self::META_OUT, true );
				if ( '' === $had || (int) $row['out'] > (int) $had ) {
					continue;
				}
				if ( $last > time() - self::RETRY * DAY_IN_SECONDS ) {
					continue;
				}
			}
			$list[] = [
				'tid'  => (int) $tid,
				'name' => (string) $row['name'],
				'in'   => (int) $row['in'],
				'out'  => (int) $row['out'],
			];
		}
		// Least pointed at, then least pointing out: the orphans of the shop.
		usort( $list, static fn( $a, $b ) => [ $a['in'], $a['out'] ] <=> [ $b['in'], $b['out'] ] );
		return $list;
	}

	// =========================================================================
	// The tick
	// =========================================================================

	/**
	 * One category, once an hour, up to the daily cap.
	 *
	 * The work itself is handed to the queue — the same job the "links only"
	 * button queues — so it runs in the background, one at a time, with the
	 * recovery and the budget guard that already sit there.
	 *
	 * @param bool $forced Asked for by hand: the daily figure still holds, the
	 *                     spacing between two passes does not.
	 *
	 * @return array{queued:int,reason:string}
	 */
	public static function tick( bool $forced = false ): array {
		$no = static fn( string $why ): array => [ 'queued' => 0, 'reason' => $why ];
		if ( ! self::on() && ! $forced ) {
			return $no( 'off' );
		}
		if ( ! class_exists( 'DZE_Category_Content' ) || ! class_exists( 'DZE_Queue' )
			|| ! class_exists( 'DZE_Modules' )
			|| ! DZE_Modules::enabled( 'category_content' ) || ! DZE_Modules::enabled( 'queue' ) ) {
			return $no( 'modules' );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			return $no( 'budget' );
		}
		if ( self::done_today() >= self::per_day() ) {
			return $no( 'cap' );
		}
		// Spread over the day rather than run off in the first hour: three a
		// day is one every eight hours, not three before breakfast.
		if ( ! $forced && time() - (int) ( self::state()['last'] ?? 0 ) < self::gap() ) {
			return $no( 'early' );
		}
		$tid = self::next_candidate();
		if ( ! $tid ) {
			return $no( 'none' );
		}
		$term = get_term( $tid, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return $no( 'gone' );
		}
		// The text as it stands, kept so this pass can be put back — and the
		// number of links it holds, so a pass that changes nothing is seen.
		$before = (int) preg_match_all( '/<a\s[^>]*href=/i', (string) $term->description );
		update_term_meta( $tid, self::META_PREV, (string) $term->description );
		update_term_meta( $tid, self::META_AT, time() );
		update_term_meta( $tid, self::META_OUT, $before );

		if ( ! DZE_Queue::add( 'cat_links', [ $tid ], true ) ) {
			return $no( 'busy' );
		}
		self::note( $tid, (string) $term->name, $before );
		delete_transient( 'dze_mesh_survey' ); // the mesh is about to change.
		return [ 'queued' => 1, 'reason' => 'queued' ];
	}

	/** Files one pass in the log, and keeps the undo of the last ten only. */
	private static function note( int $tid, string $name, int $before ): void {
		$s     = self::state();
		$today = current_time( 'Y-m-d' );
		$log   = (array) ( $s['log'] ?? [] );
		array_unshift( $log, [ 'tid' => $tid, 'name' => $name, 'at' => time(), 'before' => $before ] );
		$alive = array_map( 'intval', array_column( array_slice( $log, 0, self::KEEP ), 'tid' ) );
		foreach ( array_slice( $log, self::KEEP ) as $old ) {
			// Out of the log, out of the database: a description kept for an
			// undo nobody can reach any more is dead weight. Unless the same
			// category came back higher up the list — that undo is the live one.
			if ( ! in_array( (int) $old['tid'], $alive, true ) ) {
				delete_term_meta( (int) $old['tid'], self::META_PREV );
			}
		}
		self::save_state( [
			'day'   => $today,
			'count' => ( (string) ( $s['day'] ?? '' ) === $today ? (int) ( $s['count'] ?? 0 ) : 0 ) + 1,
			'last'  => time(),
			'log'   => array_slice( $log, 0, self::KEEP ),
		] );
	}

	/** Puts back the description one automatic pass replaced. */
	public static function undo( int $tid ): bool {
		$prev = (string) get_term_meta( $tid, self::META_PREV, true );
		if ( '' === trim( $prev ) ) {
			return false;
		}
		$res = wp_update_term( $tid, 'product_cat', [ 'description' => wp_kses_post( $prev ) ] );
		if ( is_wp_error( $res ) ) {
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
		delete_transient( 'dze_mesh_survey' );
		return true;
	}

	// =========================================================================
	// The section on Settings → Categories
	// =========================================================================

	public static function render_section(): void {
		$per   = self::per_day();
		$ready = class_exists( 'DZE_Queue' ) && class_exists( 'DZE_Modules' )
			&& DZE_Modules::enabled( 'queue' ) && DZE_Modules::enabled( 'category_content' );
		?>
		<h2 class="title"><?php esc_html_e( 'Automatic internal linking', 'dazont-ecom' ); ?></h2>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Keeps the mesh between your categories up to date on its own. It looks for the category the rest of the shop points at least, and runs the same linking-only pass as the button on that category — the text is left as it is, links are added. One category per hour at most, and never the same one twice within a month.', 'dazont-ecom' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<input type="hidden" name="<?php echo esc_attr( self::OPT ); ?>[form]" value="1" />
			<tr>
				<th scope="row"><?php esc_html_e( 'Upkeep', 'dazont-ecom' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[on]" value="1" <?php checked( self::on() ); ?> />
						<?php esc_html_e( 'Add missing internal links automatically', 'dazont-ecom' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Descriptions are edited on the shop without being reviewed first. The last ten passes can be undone below.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-mesh-per-day"><?php esc_html_e( 'Categories per day', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="number" id="dze-mesh-per-day" name="<?php echo esc_attr( self::OPT ); ?>[per_day]" class="small-text" min="1" max="20" value="<?php echo (int) $per; ?>" />
					<p class="description"><?php esc_html_e( 'The whole point of the tool: a catalogue that improves at a steady pace instead of being rewritten in an afternoon. Three a day is a hundred categories in a month.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'State', 'dazont-ecom' ); ?></th>
				<td>
					<div id="dze-mesh-state"><?php self::render_state(); ?></div>
					<p style="margin:10px 0 0;">
						<button type="button" class="button" id="dze-mesh-run"<?php echo $ready ? '' : ' disabled'; ?>><?php esc_html_e( 'Run one now', 'dazont-ecom' ); ?></button>
						<span id="dze-mesh-msg" style="margin-left:8px;font-size:13px;"></span>
					</p>
					<p style="margin:6px 0 0;">
						<span class="description"><?php esc_html_e( 'Takes the next category in line and queues its pass — one real pass, on the shop, whether the upkeep is on or off. It counts against today\'s figure.', 'dazont-ecom' ); ?></span>
					</p>
					<?php if ( ! $ready ) : ?>
						<p class="description" style="color:#b32d2e;">
							<?php esc_html_e( 'Needs the Category descriptions module and the Writing queue: the pass is theirs, this only decides which category gets it.', 'dazont-ecom' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<script>
		jQuery( function ( $ ) {
			function post( action, extra, $btn ) {
				var data = $.extend( { action: action, nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>' }, extra || {} );
				$btn.prop( 'disabled', true );
				$( '#dze-mesh-msg' ).text( '' );
				$.post( window.ajaxurl, data ).done( function ( r ) {
					$btn.prop( 'disabled', false );
					var d = ( r && r.data ) || {};
					if ( d.html ) { $( '#dze-mesh-state' ).html( d.html ); }
					// Nothing happening is an answer too, and it says which one.
					if ( d.message ) {
						$( '#dze-mesh-msg' ).text( d.message )
							.css( 'color', d.queued ? '#0a7040' : '#646970' );
					}
				} ).fail( function () { $btn.prop( 'disabled', false ); } );
			}
			$( '#dze-mesh-run' ).on( 'click', function () { post( 'dze_mesh_run', {}, $( this ) ); } );
			$( document ).on( 'click', '.dze-mesh-undo', function () {
				post( 'dze_mesh_undo', { term: $( this ).data( 'term' ) }, $( this ) );
			} );
		} );
		</script>
		<?php
	}

	/** The live half of the section: what it has done, and what it will do. */
	public static function render_state(): void {
		$s    = self::state();
		$done = self::done_today();
		$per  = self::per_day();
		$next = wp_next_scheduled( self::HOOK );
		echo '<p style="margin:0;">';
		printf(
			/* translators: 1: passes made today, 2: the daily figure */
			esc_html__( 'Today: %1$s of %2$s.', 'dazont-ecom' ),
			'<strong>' . esc_html( number_format_i18n( $done ) ) . '</strong>',
			esc_html( number_format_i18n( $per ) )
		);
		if ( self::on() && $next ) {
			echo ' ';
			printf(
				/* translators: %s: human-readable delay, e.g. "35 mins" */
				esc_html__( 'Next look in %s.', 'dazont-ecom' ),
				esc_html( human_time_diff( time(), (int) $next ) )
			);
		} elseif ( ! self::on() ) {
			echo ' ' . esc_html__( 'Switched off — nothing is edited.', 'dazont-ecom' );
		}
		echo '</p>';

		// What it is about to work on. The whole tool rests on this ranking,
		// so it is shown rather than described.
		$next = self::shortlist( 5 );
		if ( $next ) {
			echo '<p class="description" style="margin:6px 0 0;">' . esc_html__( 'Least linked to, so next in line:', 'dazont-ecom' ) . ' ';
			$bits = [];
			foreach ( $next as $row ) {
				$bits[] = '<a href="' . esc_url( get_edit_term_link( (int) $row['tid'], 'product_cat' ) ) . '">' . esc_html( (string) $row['name'] ) . '</a> '
					. '<span style="color:#a7aaad;">(' . esc_html( sprintf(
						/* translators: %d: number of category descriptions linking to it */
						_n( '%d link in', '%d links in', (int) $row['in'], 'dazont-ecom' ),
						(int) $row['in']
					) ) . ')</span>';
			}
			echo wp_kses_post( implode( ' · ', $bits ) ) . '</p>';
		}

		$log = (array) ( $s['log'] ?? [] );
		if ( ! $log ) {
			echo '<p class="description" style="margin:6px 0 0;">' . esc_html__( 'No category has been worked on yet.', 'dazont-ecom' ) . '</p>';
			return;
		}
		echo '<ul style="margin:8px 0 0;">';
		foreach ( $log as $row ) {
			$tid    = (int) ( $row['tid'] ?? 0 );
			$before = (int) ( $row['before'] ?? 0 );
			$now    = class_exists( 'DZE_Category_Content' ) ? DZE_Category_Content::links_in_description( $tid ) : $before;
			$undone = ! empty( $row['undone'] );
			$can    = ! $undone && '' !== trim( (string) get_term_meta( $tid, self::META_PREV, true ) );
			echo '<li style="margin:0 0 4px;">';
			echo '<a href="' . esc_url( get_edit_term_link( $tid, 'product_cat' ) ) . '">' . esc_html( (string) ( $row['name'] ?? '' ) ) . '</a> ';
			echo '<span class="description">';
			echo esc_html( sprintf(
				/* translators: %s: how long ago */
				__( '%s ago', 'dazont-ecom' ),
				human_time_diff( (int) ( $row['at'] ?? time() ), time() )
			) );
			if ( $undone ) {
				echo ' · ' . esc_html__( 'put back', 'dazont-ecom' );
			} elseif ( $now > $before ) {
				/* translators: 1: links before, 2: links after */
				echo ' · ' . esc_html( sprintf( __( '%1$d → %2$d links', 'dazont-ecom' ), $before, $now ) );
			} else {
				echo ' · ' . esc_html__( 'waiting for the queue', 'dazont-ecom' );
			}
			echo '</span>';
			if ( $can ) {
				echo ' <button type="button" class="button-link dze-mesh-undo" data-term="' . esc_attr( (string) $tid ) . '">&#8634; ' . esc_html__( 'Undo', 'dazont-ecom' ) . '</button>';
			}
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
	 * Why a run did, or did not, queue anything.
	 *
	 * A button that answers nothing when it does nothing is a button that
	 * gets clicked five times.
	 */
	public static function reason_text( string $reason ): string {
		switch ( $reason ) {
			case 'queued':
				return __( 'Queued — the writing queue does it in the background.', 'dazont-ecom' );
			case 'cap':
				return __( 'Today\'s figure is used up.', 'dazont-ecom' );
			case 'none':
				return __( 'No category needs a pass: they all have their links, or were worked on recently.', 'dazont-ecom' );
			case 'budget':
				return __( 'The monthly AI budget is spent.', 'dazont-ecom' );
			case 'modules':
				return __( 'The Category descriptions module or the Writing queue is switched off.', 'dazont-ecom' );
			case 'busy':
				return __( 'That category is already waiting in the queue.', 'dazont-ecom' );
			case 'off':
				return __( 'The upkeep is switched off.', 'dazont-ecom' );
		}
		return __( 'Nothing was queued.', 'dazont-ecom' );
	}

	/** The state block as the screen shows it, after an action. */
	private static function state_html(): string {
		ob_start();
		self::render_state();
		return (string) ob_get_clean();
	}

	public static function ajax_run(): void {
		self::guard();
		// "Run one now" is the tick, asked for by hand: same rules, same caps,
		// same code — a button that behaves differently from the schedule is a
		// button that tests nothing.
		$res = self::tick( true );
		wp_send_json_success( [
			'queued'  => (int) $res['queued'],
			'reason'  => (string) $res['reason'],
			'message' => self::reason_text( (string) $res['reason'] ),
			'html'    => self::state_html(),
		] );
	}

	public static function ajax_undo(): void {
		self::guard();
		$tid = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		if ( ! $tid || ! self::undo( $tid ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to put back on this category.', 'dazont-ecom' ), 'html' => self::state_html() ] );
		}
		wp_send_json_success( [ 'html' => self::state_html() ] );
	}
}
