<?php
defined( 'ABSPATH' ) || exit;

/**
 * The campaign autopilot: an enabled event becomes its emails by itself.
 *
 * The site follows an event on its own (the sale sync) and Google follows it
 * on its own (the GMC queue). This gives the third channel the same manners:
 * when a dated event is switched on, the plan is asked what the promotion
 * deserves in emails, each email is written, given its picture, filed as a
 * draft in Klaviyo, translated into the shop's languages, and — when the
 * setting says so — scheduled on its own day. The owner's part is creating
 * the event; everything after that is a state he can READ, on the event, and
 * override by hand at any moment.
 *
 * It adds no ability of its own. Every step is a function the screen's
 * buttons already call — plan_for, write_for, make_image, draft, the
 * translation pass, schedule — so what the pilot produces IS what the
 * buttons produce, and a fix to either fixes both. What this class owns is
 * only the order, the timing, and the record of what happened.
 *
 * Three rules keep it safe:
 *
 * 1. It only ever FILLS. An email with a subject or a body — written by the
 *    pilot or by hand — is never rewritten, and a picture chosen is never
 *    replaced. Deleting ONE email removes it and nothing else; deleting them
 *    ALL is the gesture that means "start this campaign over", and the plan
 *    is asked afresh. Stopping the pilot is the setting's Off.
 * 2. One step per pass, in the background, under a lock and the monthly
 *    budget guard. A step that fails is retried a few times and then left,
 *    with the failure written on the event — never an hourly model call
 *    burning money against the same wall.
 * 3. Nothing is scheduled for a day that is not safely ahead. A campaign
 *    dated in the past would go out the moment Klaviyo accepts the job, so
 *    an email whose day is today or gone stays a draft and the event says
 *    why.
 */
final class DZE_Klaviyo_Auto {

	/** One step of one promotion, run in the background. */
	public const STEP_HOOK = 'dze_klav_auto_step';

	/** The hourly look at every promotion, for the paths no save crosses. */
	public const SWEEP_HOOK = 'dze_klav_auto_sweep';

	/** Transient prefix: one lock per promotion while a step runs. */
	private const LOCK = 'dze_klav_auto_';

	/** Steps kept in the event's own log. */
	private const KEEP = 12;

	/** Errors in a row before the pilot stops trying until somebody saves. */
	private const GIVE_UP = 3;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::STEP_HOOK, [ __CLASS__, 'step_event' ] );
		add_action( self::SWEEP_HOOK, [ __CLASS__, 'sweep' ] );
		// After DZE_Klaviyo::save_copy (priority 10): the emails the form
		// carried are stored before the pilot looks at what is missing.
		add_action( 'dze_discount_saved', [ __CLASS__, 'follow' ], 20 );
		if ( is_admin() && ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::SWEEP_HOOK );
		}
	}

	/** Switched off in Settings → Modules: the schedule goes with it. */
	public static function disable(): void {
		wp_clear_scheduled_hook( self::SWEEP_HOOK );
	}

	// =========================================================================
	// The setting
	// =========================================================================

	/**
	 * How far the pilot goes on its own: 'schedule' (all the way),
	 * 'prepare' (up to translated drafts in Klaviyo — the default), '' (off).
	 *
	 * The default is PREPARE, deliberately: a shop that never chose must not
	 * find an update sending marketing emails by itself. Going live is one
	 * explicit selection on the settings screen, made by a person — and made
	 * once, since the choice is stored.
	 */
	public static function mode(): string {
		$s = DZE_Klaviyo::settings();
		$m = array_key_exists( 'auto', $s ) ? (string) $s['auto'] : 'prepare';
		return in_array( $m, [ '', 'prepare', 'schedule' ], true ) ? $m : 'prepare';
	}

	// =========================================================================
	// When the pilot looks
	// =========================================================================

	/** A promotion was saved: the pilot follows, shortly, in the background. */
	public static function follow( string $rule_id ): void {
		// A human just touched the event, so a pilot that had given up on it
		// is given a fresh run: whatever blocked it may just have been fixed.
		$auto = self::auto_of( $rule_id );
		if ( ! empty( $auto['fails'] ) ) {
			self::write_auto( $rule_id, [ 'fails' => 0 ] );
		}
		self::kick( $rule_id );
	}

	/** Arms one background step for one promotion, if none is armed. */
	public static function kick( string $rule_id ): void {
		if ( '' === $rule_id || '' === self::mode() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::STEP_HOOK, [ $rule_id ] ) ) {
			wp_schedule_single_event( time() + 15, self::STEP_HOOK, [ $rule_id ] );
		}
	}

	/**
	 * The hourly sweep: promotions changed by paths that never save a form —
	 * accepted from the calendar, toggled on, or left mid-way by a failure.
	 * Deciding whether anything is missing is a local read, so looking at
	 * every promotion costs nothing until one actually needs a step.
	 */
	public static function sweep(): void {
		if ( '' === self::mode() || ! class_exists( 'DZE_Discounts' ) ) {
			return;
		}
		$ctx = self::context();
		foreach ( DZE_Discounts::get_rules() as $rule_id => $rule ) {
			$rule_id = (string) $rule_id;
			$auto    = self::auto_of( $rule_id );
			if ( (int) ( $auto['fails'] ?? 0 ) >= self::GIVE_UP ) {
				continue; // it said what is wrong; a save clears the way.
			}
			$next = self::next_step( (array) $rule, DZE_Klaviyo::emails_for( $rule_id, (array) $rule ), $auto, $ctx );
			if ( in_array( $next['do'], [ 'plan', 'write', 'image', 'draft', 'translate', 'schedule' ], true ) ) {
				self::kick( $rule_id );
			}
		}
	}

	// =========================================================================
	// What the pilot decides
	// =========================================================================

	/**
	 * The shop-level facts a decision needs, read once per pass.
	 *
	 * @return array{mode:string,images:bool,langs:string[],audience:bool,frame:bool,key:bool,budget:bool,today:string,tomorrow:string}
	 */
	public static function context(): array {
		$s = DZE_Klaviyo::settings();
		return [
			'mode'     => self::mode(),
			'images'   => DZE_Klaviyo::images_on(),
			'langs'    => DZE_Klaviyo::locales()[1],
			'audience' => '' !== (string) DZE_Klaviyo::conf( 'included' ),
			'frame'    => '' !== trim( (string) ( $s['shell'] ?? '' ) ),
			'key'      => '' !== DZE_Klaviyo::key(),
			'budget'   => ! class_exists( 'DZE_Ai_Usage' ) || ! DZE_Ai_Usage::over_budget(),
			'today'    => (string) wp_date( 'Y-m-d' ),
			'tomorrow' => DZE_Klaviyo::earliest_day(),
		];
	}

	/**
	 * What this promotion needs NEXT, decided from what it holds and nothing
	 * else — so the same promotion always gets the same answer, whether the
	 * question comes from the sweep, from a save, or from a test.
	 *
	 * @param array $rule   The promotion.
	 * @param array $emails Its emails, oldest first, as emails_for() returns them.
	 * @param array $auto   The pilot's own record on this promotion.
	 * @param array $ctx    context().
	 *
	 * @return array{do:string,email:string,say:string} do is one of
	 *         'off' | 'done' | 'blocked' | 'plan' | 'write' | 'image' |
	 *         'draft' | 'translate' | 'schedule'.
	 */
	public static function next_step( array $rule, array $emails, array $auto, array $ctx ): array {
		$no = static fn( string $do, string $say = '', string $email = '' ): array => [ 'do' => $do, 'say' => $say, 'email' => $email ];

		if ( '' === $ctx['mode'] ) {
			return $no( 'off' );
		}
		if ( ( $rule['type'] ?? '' ) !== 'sale' || empty( $rule['enabled'] ) ) {
			return $no( 'off' );
		}
		$start = self::day_of( (string) ( $rule['start'] ?? '' ) );
		$end   = self::day_of( (string) ( $rule['end'] ?? '' ) ) ?: $start;
		if ( '' === $start ) {
			return $no( 'blocked', __( 'The event has no dates — the emails are planned around them.', 'dazont-ecom' ) );
		}
		if ( $end < $ctx['today'] ) {
			return $no( 'done', __( 'The promotion is over.', 'dazont-ecom' ) );
		}
		if ( ! $ctx['key'] ) {
			return $no( 'blocked', __( 'No Klaviyo API key yet — Settings → Email campaigns.', 'dazont-ecom' ) );
		}
		if ( ! $ctx['budget'] ) {
			return $no( 'blocked', __( 'The monthly AI budget is spent; the pilot resumes next month.', 'dazont-ecom' ) );
		}

		// No emails at all: the plan is asked. Deleting every email of an
		// event IS the gesture that means "start this campaign over" — a
		// "planned once" flag used to stand in the way here, and the owner
		// who emptied his event to get a fresh campaign got silence instead.
		// (Curation is still respected: the plan only runs when the list is
		// EMPTY, so deleting one email of three removes it and nothing else.)
		if ( ! $emails ) {
			return $no( 'plan', __( 'Asking the plan what this promotion deserves in emails.', 'dazont-ecom' ) );
		}

		foreach ( $emails as $email_id => $mail ) {
			$email_id = (string) $email_id;
			$subject  = trim( (string) ( $mail['subject'] ?? '' ) );
			$body     = trim( (string) ( $mail['body'] ?? '' ) );

			if ( '' === $subject && '' === $body ) {
				return $no( 'write', '', $email_id );
			}
			if ( '' === $subject || '' === $body ) {
				// Half an email is somebody's work in progress. The pilot
				// neither finishes nor files it — it moves on and the status
				// line says which row is waiting for its author.
				continue;
			}
			if ( $ctx['images'] && '' === trim( (string) ( $mail['picture'] ?? '' ) )
				&& ( false !== strpos( (string) ( $mail['body'] ?? '' ), DZE_Klaviyo::PICTURE_MARK )
					|| ! empty( $mail['auto_made'] ) ) ) {
				// A pilot email gets its picture even when the writing forgot
				// to mark a place for one — it goes at the top then, exactly
				// as the browser's own fallback puts it. Only an email a
				// PERSON made without a marker is read as "no picture wanted":
				// forcing one there would be touching their work.
				return $no( 'image', '', $email_id );
			}
			if ( '' === (string) ( $mail['draft']['campaign'] ?? '' ) ) {
				if ( ! $ctx['audience'] || ! $ctx['frame'] ) {
					return $no( 'blocked', __( 'Emails are written, but the audience or the header/footer is not set up yet — Settings → Email campaigns.', 'dazont-ecom' ), $email_id );
				}
				return $no( 'draft', '', $email_id );
			}
			if ( $ctx['langs'] ) {
				$done    = array_values( array_filter( (array) ( $mail['draft']['done_langs'] ?? [] ), 'is_string' ) );
				$missing = array_values( array_diff( $ctx['langs'], $done ) );
				if ( $missing ) {
					return $no( 'translate', '', $email_id );
				}
			}
			if ( 'schedule' === $ctx['mode'] && empty( $mail['draft']['scheduled'] ) && empty( $mail['draft']['sent'] ) ) {
				if ( ! empty( $auto['legacy'] ) ) {
					// The pilot never schedules an email it did not prepare.
					// A promotion that already carried drafts when the pilot
					// first saw it — made by hand, or by a version before the
					// pilot existed — keeps them as drafts: their day was
					// chosen under different rules, and quietly sending them
					// is the one thing an update must never start doing.
					// Deleting the emails and saving hands the promotion to
					// the pilot, which replans and schedules its own.
					continue;
				}
				$day = self::day_of( (string) ( $mail['when'] ?? '' ) );
				if ( '' !== $day && $day >= $ctx['tomorrow'] ) {
					return $no( 'schedule', '', $email_id );
				}
				// Today or gone: scheduling would send it the moment Klaviyo
				// accepts the job. It stays a draft, and the event says so.
				continue;
			}
		}
		return $no( 'done' );
	}

	// =========================================================================
	// What the pilot does
	// =========================================================================

	/** The cron target: one step, then the next pass is armed if more remains. */
	public static function step_event( $rule_id ): void {
		$rule_id = sanitize_key( (string) $rule_id );
		if ( '' === $rule_id ) {
			return;
		}
		$did = self::step( $rule_id );
		if ( in_array( $did['do'], [ 'plan', 'write', 'image', 'draft', 'translate', 'schedule' ], true ) && '' === $did['error'] ) {
			// Something was done and more may remain: the next step follows
			// shortly, one request each, so no single pass runs for minutes.
			wp_schedule_single_event( time() + 30, self::STEP_HOOK, [ $rule_id ] );
		} elseif ( '' !== $did['error'] && (int) $did['fails'] < self::GIVE_UP ) {
			// A miss is retried after a pause — a model that timed out once
			// usually answers the second time. A wall is not: after a few, the
			// pilot stops and the event carries the reason until a save.
			wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, self::STEP_HOOK, [ $rule_id ] );
		}
	}

	/**
	 * Performs ONE step for one promotion and records what happened.
	 *
	 * @return array{do:string,email:string,error:string,fails:int}
	 */
	public static function step( string $rule_id ): array {
		$out   = [ 'do' => '', 'email' => '', 'error' => '', 'fails' => 0 ];
		$rules = class_exists( 'DZE_Discounts' ) ? DZE_Discounts::get_rules() : [];
		$rule  = (array) ( $rules[ $rule_id ] ?? [] );
		if ( ! $rule ) {
			return $out;
		}
		if ( false !== get_transient( self::LOCK . $rule_id ) ) {
			return $out; // another pass is on it right now.
		}
		set_transient( self::LOCK . $rule_id, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$auto   = self::auto_of( $rule_id );
			$emails = DZE_Klaviyo::emails_for( $rule_id, $rule );
			// First sight of a promotion that already has emails: they are
			// somebody else's work — a hand-built campaign, or one from before
			// the pilot existed. They are marked so the pilot completes them
			// (writes, drafts, translates) but never SCHEDULES them: see
			// next_step().
			if ( ! $auto && $emails ) {
				$auto = [ 'legacy' => 1 ];
				self::write_auto( $rule_id, $auto );
			}
			$next = self::next_step( $rule, $emails, $auto, self::context() );
			$out['do']    = $next['do'];
			$out['email'] = $next['email'];

			if ( 'blocked' === $next['do'] ) {
				self::write_auto( $rule_id, [ 'note' => $next['say'], 'at' => time() ] );
				return $out;
			}
			if ( ! in_array( $next['do'], [ 'plan', 'write', 'image', 'draft', 'translate', 'schedule' ], true ) ) {
				if ( 'done' === $next['do'] && '' === (string) ( $auto['note'] ?? '' ) . (string) ( $auto['at'] ?? '' ) ) {
					return $out; // nothing was ever done here: leave no record.
				}
				if ( 'done' === $next['do'] ) {
					self::write_auto( $rule_id, [ 'note' => '', 'at' => time() ] );
				}
				return $out;
			}

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			try {
				self::run( $rule_id, $rule, $next['do'], $next['email'] );
				self::write_auto( $rule_id, [
					'note'  => '',
					'fails' => 0,
					'at'    => time(),
					'log'   => self::log_line( $auto, $next['do'], $next['email'], '' ),
				] );
				if ( 'plan' === $next['do'] ) {
					// A campaign the pilot planned is the pilot's own: the
					// legacy mark, if one was ever set, ends here.
					self::write_auto( $rule_id, [ 'legacy' => 0 ] );
				}
			} catch ( \Throwable $e ) {
				$fails        = (int) ( $auto['fails'] ?? 0 ) + 1;
				$out['error'] = $e->getMessage();
				$out['fails'] = $fails;
				self::write_auto( $rule_id, [
					'note'  => $out['error'],
					'fails' => $fails,
					'at'    => time(),
					'log'   => self::log_line( $auto, $next['do'], $next['email'], $out['error'] ),
				] );
				if ( class_exists( 'DZE_Health' ) ) {
					DZE_Health::log( 'klaviyo_auto', $next['do'] . ' — ' . $out['error'] );
				}
			}
			return $out;
		} finally {
			delete_transient( self::LOCK . $rule_id );
		}
	}

	/**
	 * One step, run through the very functions the screen's buttons call.
	 * Nothing here writes an email its own way: put_email() and the existing
	 * step functions are the only writers, so the pilot cannot drift from
	 * what a click does.
	 */
	private static function run( string $rule_id, array $rule, string $do, string $email_id ): void {
		switch ( $do ) {
			case 'plan':
				DZE_Klaviyo::plan_for( $rule_id, $rule );
				return;
			case 'write':
				$made = DZE_Klaviyo::write_for( $rule_id, $rule, $email_id );
				DZE_Klaviyo::put_email( $rule_id, $email_id, [
					'subject' => (string) ( $made['subject'] ?? '' ),
					'preview' => (string) ( $made['preview'] ?? '' ),
					'body'    => (string) ( $made['body'] ?? '' ),
					// Made with nobody watching, so it asks to be READ: the
					// row says "To check" until a person marks it checked.
					// An email generated by a click is not flagged — its
					// author was sitting in front of it.
					'auto_made' => 1,
				] );
				return;
			case 'image':
				$made = DZE_Klaviyo::make_image(
					$rule,
					'',
					DZE_Klaviyo::email_for( $rule_id, $email_id, $rule ),
					DZE_Klaviyo::material_for( $rule_id, $rule, $email_id )
				);
				DZE_Klaviyo::keep_picture( $rule_id, $email_id, (string) $made['url'] );
				DZE_Klaviyo::charge_promo( $rule_id, class_exists( 'DZE_Content' ) ? DZE_Content::last_image_cost() : 0.0 );
				// A picture that arrives AFTER the draft was filed leaves that
				// draft showing yesterday's email. It is refiled in place —
				// the same campaign, rewritten by the same function a click
				// uses — and its translations are owed again, so the next
				// passes write them against the body Klaviyo now holds.
				$mail = DZE_Klaviyo::emails_for( $rule_id, $rule )[ $email_id ] ?? [];
				if ( '' !== (string) ( $mail['draft']['campaign'] ?? '' ) ) {
					DZE_Klaviyo::put_email( $rule_id, $email_id, [
						'draft' => array_merge( (array) ( $mail['draft'] ?? [] ), [ 'done_langs' => [] ] ),
					] );
					DZE_Klaviyo::draft( $rule_id, $email_id );
				}
				return;
			case 'draft':
				DZE_Klaviyo::draft( $rule_id, $email_id );
				return;
			case 'translate':
				// Every missing language, then ONE write — the same shape the
				// screen uses, minus the parallelism a browser brings. A
				// language that fails does not stop the others: what came back
				// is filed, and the missing one is simply still missing next
				// pass, where it is tried again.
				$mail    = DZE_Klaviyo::emails_for( $rule_id, $rule )[ $email_id ] ?? [];
				$done    = array_values( array_filter( (array) ( $mail['draft']['done_langs'] ?? [] ), 'is_string' ) );
				$missing = array_values( array_diff( DZE_Klaviyo::locales()[1], $done ) );
				$got     = 0;
				$last    = null;
				foreach ( $missing as $lang ) {
					try {
						DZE_Klaviyo::translate_language( $rule_id, $email_id, (string) $lang );
						$got++;
					} catch ( \Throwable $e ) {
						$last = $e;
					}
				}
				if ( 0 === $got ) {
					throw $last instanceof \Throwable ? $last : new RuntimeException( __( 'No language could be written.', 'dazont-ecom' ) );
				}
				DZE_Klaviyo::save_translations( $rule_id, $email_id );
				if ( $last instanceof \Throwable ) {
					throw $last; // the filed ones are safe; the miss is said.
				}
				return;
			case 'schedule':
				$mail = DZE_Klaviyo::emails_for( $rule_id, $rule )[ $email_id ] ?? [];
				$camp = (string) ( $mail['draft']['campaign'] ?? '' );
				[ $day, $said ] = DZE_Klaviyo::schedule( $camp );
				if ( '' !== $said ) {
					throw new RuntimeException( $said );
				}
				DZE_Klaviyo::put_email( $rule_id, $email_id, [
					'draft' => array_merge( (array) ( $mail['draft'] ?? [] ), [ 'scheduled' => time(), 'goes' => $day ] ),
				] );
				return;
		}
	}

	// =========================================================================
	// What the pilot says
	// =========================================================================

	/**
	 * One line for the event's own screen: where the campaign stands, and
	 * what happens next. Read from what is stored, never from intent.
	 */
	public static function status_line( string $rule_id, array $rule ): string {
		$mode = self::mode();
		if ( '' === $mode ) {
			return '';
		}
		$emails = DZE_Klaviyo::emails_for( $rule_id, $rule );
		$auto   = self::auto_of( $rule_id );
		$ctx    = self::context();
		$next   = self::next_step( $rule, $emails, $auto, $ctx );

		if ( 'off' === $next['do'] ) {
			return empty( $rule['enabled'] ) && ( $rule['type'] ?? '' ) === 'sale'
				? __( 'Autopilot: waiting — it starts when the event is switched on.', 'dazont-ecom' )
				: '';
		}
		if ( 'blocked' === $next['do'] ) {
			return __( 'Autopilot: stopped —', 'dazont-ecom' ) . ' ' . $next['say'];
		}
		if ( (int) ( $auto['fails'] ?? 0 ) >= self::GIVE_UP ) {
			return __( 'Autopilot: stopped after several failed tries —', 'dazont-ecom' ) . ' '
				. (string) ( $auto['note'] ?? '' ) . ' '
				. __( 'Saving the event makes it try again.', 'dazont-ecom' );
		}

		// What the mode MEANS, said every time — "on" told the owner nothing.
		$word = 'schedule' === $mode
			? __( 'Autopilot (prepares AND schedules — emails go out on their day):', 'dazont-ecom' )
			: __( 'Autopilot (prepares Klaviyo drafts — nothing is ever sent by itself):', 'dazont-ecom' );
		$total = count( $emails );
		if ( 0 === $total ) {
			return $word . ' ' . __( 'the campaign is planned as soon as the event is saved, enabled, with its dates.', 'dazont-ecom' );
		}
		$written = 0;
		$drafted = 0;
		$i18ned  = 0;
		$sched   = 0;
		$unread  = 0;
		foreach ( $emails as $mail ) {
			$done = '' !== trim( (string) ( $mail['subject'] ?? '' ) ) && '' !== trim( (string) ( $mail['body'] ?? '' ) );
			$written += $done ? 1 : 0;
			$drafted += '' !== (string) ( $mail['draft']['campaign'] ?? '' ) ? 1 : 0;
			$have     = array_filter( (array) ( $mail['draft']['done_langs'] ?? [] ), 'is_string' );
			$i18ned  += ( ! $ctx['langs'] || ! array_diff( $ctx['langs'], $have ) ) && '' !== (string) ( $mail['draft']['campaign'] ?? '' ) ? 1 : 0;
			$sched   += ( ! empty( $mail['draft']['scheduled'] ) || ! empty( $mail['draft']['sent'] ) ) ? 1 : 0;
			$unread  += ! empty( $mail['auto_made'] ) ? 1 : 0;
		}
		$bits = [ sprintf( _n( '%d email', '%d emails', $total, 'dazont-ecom' ), $total ) ];
		$bits[] = sprintf( __( 'written %1$d/%2$d', 'dazont-ecom' ), $written, $total );
		$bits[] = sprintf( __( 'in Klaviyo %1$d/%2$d', 'dazont-ecom' ), $drafted, $total );
		if ( $ctx['langs'] ) {
			$bits[] = sprintf( __( 'translated %1$d/%2$d', 'dazont-ecom' ), $i18ned, $total );
		}
		if ( 'schedule' === $mode ) {
			$bits[] = sprintf( __( 'scheduled %1$d/%2$d', 'dazont-ecom' ), $sched, $total );
		}
		if ( $unread > 0 ) {
			// The human quality control: what the pilot made and nobody read.
			$bits[] = sprintf( _n( '%d TO CHECK', '%d TO CHECK', $unread, 'dazont-ecom' ), $unread );
		}
		if ( ! $ctx['images'] ) {
			// The one reason an email comes out with no opening picture that
			// nothing else on this screen would ever say.
			$bits[] = __( 'pictures OFF in the settings', 'dazont-ecom' );
		}
		$line = $word . ' ' . implode( ' · ', $bits );
		if ( 'done' === $next['do'] ) {
			if ( 'schedule' === $mode && $sched < $total && ! empty( $auto['legacy'] ) ) {
				return $line . ' — ' . __( 'these emails were made before the autopilot, so it leaves them as drafts: schedule them on their rows, or delete them and save — it then replans the campaign and schedules its own.', 'dazont-ecom' );
			}
			return $line . ' — ' . ( 'schedule' === $mode && $sched < $total
				? __( 'the rest stays as drafts (their day is today or gone).', 'dazont-ecom' )
				: __( 'nothing left to do.', 'dazont-ecom' ) );
		}
		$doing = [
			'plan'      => __( 'planning the campaign', 'dazont-ecom' ),
			'write'     => __( 'writing the next email', 'dazont-ecom' ),
			'image'     => __( 'making a picture', 'dazont-ecom' ),
			'draft'     => __( 'filing a draft in Klaviyo', 'dazont-ecom' ),
			'translate' => __( 'translating', 'dazont-ecom' ),
			'schedule'  => __( 'scheduling', 'dazont-ecom' ),
		];
		return $line . ' — ' . __( 'next:', 'dazont-ecom' ) . ' ' . (string) ( $doing[ $next['do'] ] ?? $next['do'] ) . '…';
	}

	// =========================================================================
	// The pilot's own record, kept beside the emails it is about
	// =========================================================================

	/** @return array What the pilot knows about one promotion. */
	public static function auto_of( string $rule_id ): array {
		$all = get_option( DZE_Klaviyo::OPT_COPY, [] );
		return is_array( $all ) ? (array) ( $all[ $rule_id ]['auto'] ?? [] ) : [];
	}

	/** Merges fields into that record, touching nothing else on the row. */
	private static function write_auto( string $rule_id, array $fields ): void {
		$all = get_option( DZE_Klaviyo::OPT_COPY, [] );
		$all = is_array( $all ) ? $all : [];
		$one = (array) ( $all[ $rule_id ] ?? [] );
		$one['auto']     = array_merge( (array) ( $one['auto'] ?? [] ), $fields );
		$all[ $rule_id ] = $one;
		update_option( DZE_Klaviyo::OPT_COPY, $all, false );
	}

	/** The log with one more line, oldest dropped. @return array */
	private static function log_line( array $auto, string $do, string $email_id, string $error ): array {
		$log   = array_values( (array) ( $auto['log'] ?? [] ) );
		$log[] = [ 't' => time(), 'do' => $do, 'email' => $email_id, 'error' => mb_substr( $error, 0, 200 ) ];
		return array_slice( $log, -self::KEEP );
	}

	/** A date or datetime, cut to its day. */
	private static function day_of( string $when ): string {
		return DZE_Klaviyo::just_day( $when );
	}
}
