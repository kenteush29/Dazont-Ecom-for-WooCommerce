<?php
defined( 'ABSPATH' ) || exit;

/**
 * Email campaigns — a marketing event becomes a DRAFT campaign in Klaviyo.
 *
 * The shop already knows everything a promotion announcement needs: its
 * title, its percentage, its dates, and the line each market reads. What it
 * did not have was a way to say it by email without rebuilding the same
 * campaign by hand every time.
 *
 * So this does exactly what the owner does in Klaviyo, in the order he does
 * it, and stops where he would want to look:
 *
 *   1. the promo template he picked in the settings is CLONED (his original
 *      is never touched — one campaign, one template, as Klaviyo itself does
 *      when you edit a campaign's content);
 *   2. the markers the template carries — {{PROMO_TITLE}}, {{PROMO_PERCENT}},
 *      {{PROMO_END}} … — are replaced by the event's own figures, everywhere
 *      in the clone, in text as in buttons and links;
 *   3. a campaign is created for the audience chosen once in the settings —
 *      the same segment the shop's own campaigns go to, minus the same
 *      exclusion — with the subject and preview text of this event;
 *   4. the clone is assigned to it.
 *
 * It never sends and never schedules: what comes out is a draft, and the
 * decision to send stays where it belongs — in front of the campaign, in
 * Klaviyo. Language is not our business either: profiles carry the language
 * the shop assigned them, and Klaviyo's own translator serves each reader in
 * his. What this can add, when asked, is the ONE line no machine translator
 * writes as well as the shop does — the promotion title already adapted
 * market by market on the event itself.
 *
 * Footprint: admin only. Not a hook, not a query, not an option read on a
 * shop page. Every call to Klaviyo happens inside an explicit click.
 */
final class DZE_Klaviyo {

	public const OPT     = 'dze_klaviyo';         // settings (never autoloaded).
	public const OPT_MAP = 'dze_klaviyo_drafts';  // rule id => the draft it produced.

	private const API   = 'https://a.klaviyo.com/api/';
	private const REV   = '2025-07-15';      // stable API revision.
	private const REV_B = '2025-07-15.pre';  // the localisation endpoints are beta.
	private const NONCE = 'dze_klaviyo';
	private const CACHE = 'dze_klaviyo_cat'; // templates + audiences, as last read.

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! is_admin() ) {
			return; // a customer never pays for this.
		}
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_dze_klav_load',    [ __CLASS__, 'ajax_load' ] );
		add_action( 'wp_ajax_dze_klav_subject', [ __CLASS__, 'ajax_subject' ] );
		add_action( 'wp_ajax_dze_klav_draft',   [ __CLASS__, 'ajax_draft' ] );
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function settings(): array {
		$s = get_option( self::OPT, [] );
		return is_array( $s ) ? $s : [];
	}

	/** One setting, with the shipped default when nothing was chosen. */
	public static function conf( string $key, $default = '' ) {
		$s = self::settings();
		return array_key_exists( $key, $s ) && '' !== $s[ $key ] ? $s[ $key ] : $default;
	}

	public static function key(): string {
		if ( defined( 'DZE_KLAVIYO_API_KEY' ) && DZE_KLAVIYO_API_KEY ) {
			return (string) DZE_KLAVIYO_API_KEY;
		}
		return (string) ( self::settings()['api_key'] ?? '' );
	}

	/** Everything a draft needs is answered: key, template, audience. */
	public function configured(): bool {
		return '' !== self::key()
			&& '' !== (string) self::conf( 'template' )
			&& '' !== (string) self::conf( 'included' );
	}

	public function register_settings(): void {
		register_setting( 'dze_klaviyo_options', self::OPT, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize' ],
			'default'           => [],
		] );
	}

	/**
	 * Merges onto what is saved: a form that did not carry a field must not
	 * erase it, and a blank key field means "keep the key you have".
	 */
	public function sanitize( $in ): array {
		$old = self::settings();
		$in  = is_array( $in ) ? $in : [];

		$out = $old;
		$key = trim( (string) ( $in['api_key'] ?? '' ) );
		if ( '' !== $key ) {
			$out['api_key'] = sanitize_text_field( $key );
		}
		foreach ( [ 'template', 'included', 'excluded' ] as $id_field ) {
			if ( array_key_exists( $id_field, $in ) ) {
				$out[ $id_field ] = sanitize_text_field( (string) $in[ $id_field ] );
			}
		}
		// The sender, the send moment and the per-market subject are not
		// questions: Klaviyo's own sender, the day the promotion opens, and
		// yes. Three settings whose only sane answer was already the default
		// are three ways to get it wrong, so they are gone — and the ones
		// stored before are dropped rather than left behind to confuse a
		// later reader.
		unset( $out['from_label'], $out['from_email'], $out['hour'], $out['lead_days'], $out['local'], $out['i18n_push'] );
		if ( array_key_exists( 'subject_prompt', $in ) ) {
			// Same treatment as every other prompt of the plugin: shipped text
			// saved as it stands means "no custom prompt".
			$text = trim( sanitize_textarea_field( (string) $in['subject_prompt'] ) );
			$out['subject_prompt'] = ( $text === trim( self::default_subject_prompt() ) ) ? '' : $text;
		}
		unset( $out['form'] );
		return $out;
	}

	public static function subject_prompt(): string {
		$custom = trim( (string) ( self::settings()['subject_prompt'] ?? '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		return class_exists( 'DZE_Prompt_Defaults' )
			? DZE_Prompt_Defaults::pick( 'promo_email', self::default_subject_prompt() )
			: self::default_subject_prompt();
	}

	public static function default_subject_prompt(): string {
		return "Write the subject line and the preview text of the email announcing this promotion.\n"
			. "The subject is what decides whether the email is opened: say the offer, not the season. Six to nine words, no more — anything past that is cut off on a phone.\n"
			. "Name what is at stake (the discount, the deadline, what it covers). Figures are welcome, and they must be the ones given.\n"
			. "The preview text continues the subject, it does not repeat it: it is the second half of the sentence the reader sees in the inbox. Four to eight words.\n"
			. "No ALL CAPS, no exclamation stacking, no \"Don't miss out\", no emoji unless the promotion itself is a holiday one.\n"
			. "Never promise anything the promotion does not say — no free shipping, no gift, no extra code.";
	}

	// =========================================================================
	// Talking to Klaviyo
	// =========================================================================

	/**
	 * One request. Returns the decoded body, or a WP_Error carrying what
	 * Klaviyo itself said — an owner reading "HTTP 400" learns nothing.
	 *
	 * @return array|WP_Error
	 */
	public static function request( string $method, string $path, ?array $body = null, int $timeout = 25, bool $beta = false ) {
		$key = self::key();
		if ( '' === $key ) {
			return new WP_Error( 'dze_klav_key', __( 'No Klaviyo private API key is saved yet.', 'dazont-ecom' ) );
		}
		$args = [
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 2,
			'headers'     => [
				'Authorization' => 'Klaviyo-API-Key ' . $key,
				'revision'      => $beta ? self::REV_B : self::REV,
				'accept'        => 'application/vnd.api+json',
				'content-type'  => 'application/vnd.api+json',
			],
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$resp = wp_remote_request( self::API . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$raw  = (string) wp_remote_retrieve_body( $resp );
		$data = json_decode( $raw, true );
		if ( $code >= 200 && $code < 300 ) {
			return is_array( $data ) ? $data : [];
		}
		$detail = '';
		if ( is_array( $data ) && ! empty( $data['errors'][0]['detail'] ) ) {
			$detail = (string) $data['errors'][0]['detail'];
		}
		return new WP_Error(
			'dze_klav_http',
			sprintf(
				/* translators: 1: HTTP status, 2: the provider's own message */
				__( 'Klaviyo refused (HTTP %1$d)%2$s', 'dazont-ecom' ),
				$code,
				'' !== $detail ? ' — ' . mb_substr( $detail, 0, 220 ) : ''
			)
		);
	}

	/**
	 * The templates and audiences of the account, as last read.
	 *
	 * Never fetched while a page is being drawn: the settings screen shows
	 * what the cache holds and says so when it is empty, and the button
	 * behind it does the reading.
	 */
	public static function catalogue(): array {
		$c = get_transient( self::CACHE );
		return is_array( $c ) ? $c : [ 'templates' => [], 'audiences' => [], 'read' => 0 ];
	}

	/** Reads templates, lists and segments from the account. */
	public static function refresh(): array {
		$out    = [ 'templates' => [], 'audiences' => [], 'read' => time() ];
		$errors = [];

		foreach ( self::pages( 'templates/?fields[template]=name,updated&sort=-updated', $errors ) as $row ) {
			$out['templates'][ (string) $row['id'] ] = (string) ( $row['attributes']['name'] ?? $row['id'] );
		}
		if ( ! $out['templates'] && $errors ) {
			throw new RuntimeException( implode( ' ', $errors ) );
		}

		foreach ( [ 'segments' => __( 'Segment', 'dazont-ecom' ), 'lists' => __( 'List', 'dazont-ecom' ) ] as $kind => $label ) {
			// No page[size] of our own: Klaviyo caps it per endpoint, and a
			// number over that cap is a 400 — which is exactly how the audience
			// pickers came back empty while the templates filled in.
			foreach ( self::pages( $kind . '/?fields[' . rtrim( $kind, 's' ) . ']=name', $errors ) as $row ) {
				$out['audiences'][ (string) $row['id'] ] = $label . ' · ' . (string) ( $row['attributes']['name'] ?? $row['id'] );
			}
		}
		asort( $out['audiences'] );
		set_transient( self::CACHE, $out, 12 * HOUR_IN_SECONDS );
		$out['errors'] = $errors;
		return $out;
	}

	/**
	 * Every row of a listing, following Klaviyo's own next links.
	 *
	 * A refusal is not swallowed: it is added to $errors so the screen can say
	 * what the account answered — a key without list access reads "403", and
	 * that is a five-second fix once it is on screen instead of an empty list.
	 *
	 * @param array $errors Collected, by reference.
	 *
	 * @return array<int,array>
	 */
	private static function pages( string $path, array &$errors, int $max = 12 ): array {
		$rows = [];
		$next = self::API . ltrim( $path, '/' );
		for ( $i = 0; $i < $max && '' !== $next; $i++ ) {
			$res = self::request( 'GET', substr( $next, strlen( self::API ) ), null, 20 );
			if ( is_wp_error( $res ) ) {
				$errors[] = $res->get_error_message();
				break;
			}
			foreach ( (array) ( $res['data'] ?? [] ) as $row ) {
				if ( ! empty( $row['id'] ) ) {
					$rows[] = $row;
				}
			}
			$next = (string) ( $res['links']['next'] ?? '' );
			if ( '' !== $next && 0 !== strpos( $next, self::API ) ) {
				break; // never follow a link out of the API we called.
			}
		}
		return $rows;
	}

	// =========================================================================
	// A promotion, as the email says it
	// =========================================================================

	/**
	 * The markers a template may carry, filled from the event itself.
	 *
	 * @return array<string,string> marker => what it becomes.
	 */
	public static function variables( array $rule ): array {
		$title = trim( (string) ( $rule['banner_text'] ?? '' ) );
		if ( '' === $title ) {
			$title = trim( (string) ( $rule['title'] ?? '' ) );
		}
		$pct  = rtrim( rtrim( number_format( (float) ( $rule['percent'] ?? 0 ), 2, '.', '' ), '0' ), '.' );
		$fmt  = get_option( 'date_format' ) ?: 'Y-m-d';
		$date = static function ( $ymd ) use ( $fmt ): string {
			$ts = $ymd ? strtotime( (string) $ymd . ' 00:00:00' ) : false;
			return $ts ? (string) wp_date( $fmt, $ts ) : '';
		};
		return [
			'{{PROMO_TITLE}}'   => $title,
			'{{PROMO_PERCENT}}' => $pct . '%',
			'{{PROMO_START}}'   => $date( $rule['start'] ?? '' ),
			'{{PROMO_END}}'     => $date( $rule['end'] ?? '' ),
			'{{PROMO_URL}}'     => home_url( '/' ),
		];
	}

	/** Replaces the markers everywhere in a template definition. */
	private static function swap( $node, array $map ) {
		if ( is_string( $node ) ) {
			return strtr( $node, $map );
		}
		if ( is_array( $node ) ) {
			foreach ( $node as $k => $v ) {
				$node[ $k ] = self::swap( $v, $map );
			}
		}
		return $node;
	}

	/**
	 * When the email lands: the day the promotion opens, at nine.
	 *
	 * Not a setting. A promotion is announced when it starts, and the one
	 * campaign that wants another date has the field in front of it.
	 */
	public static function default_datetime( array $rule ): string {
		$day = (string) ( $rule['start'] ?? '' );
		$ts  = $day ? strtotime( $day . ' 00:00:00' ) : false;
		if ( ! $ts || $ts < time() + HOUR_IN_SECONDS ) {
			$ts = time() + DAY_IN_SECONDS; // a date already gone is not a send date.
		}
		return gmdate( 'Y-m-d', $ts ) . 'T09:00';
	}

	/**
	 * The send date as Klaviyo wants to read it: a wall clock, no offset —
	 * the campaign goes out at that hour in each reader's own time zone.
	 */
	private static function when( string $raw, array $rule ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			$raw = self::default_datetime( $rule );
		}
		$raw = str_replace( ' ', 'T', $raw );
		if ( 16 === strlen( $raw ) ) {
			$raw .= ':00'; // an <input type="datetime-local"> has no seconds.
		}
		// Always the reader's own time zone, as every campaign this shop
		// sends already does — so the datetime is a wall clock and carries no
		// offset of ours.
		return $raw;
	}

	/** The drafts this shop has already produced, per event. */
	public static function map(): array {
		$m = get_option( self::OPT_MAP, [] );
		return is_array( $m ) ? $m : [];
	}

	public static function draft_for( string $rule_id ): array {
		return (array) ( self::map()[ $rule_id ] ?? [] );
	}

	public static function campaign_url( string $campaign_id ): string {
		return 'https://www.klaviyo.com/campaign/' . rawurlencode( $campaign_id ) . '/wizard';
	}

	// =========================================================================
	// Creating the draft
	// =========================================================================

	/**
	 * Clone → fill in → campaign → assign. Nothing is ever sent.
	 *
	 * @param array $in name, subject, preview, datetime, vars (marker => value).
	 *
	 * @return array{campaign:string,message:string,template:string,url:string,warning:string}
	 * @throws RuntimeException with what failed, at the step it failed.
	 */
	public static function draft( string $rule_id, array $in ): array {
		$rules = class_exists( 'DZE_Discounts' ) ? DZE_Discounts::get_rules() : [];
		$rule  = (array) ( $rules[ $rule_id ] ?? [] );
		if ( ! $rule ) {
			throw new RuntimeException( __( 'That event no longer exists.', 'dazont-ecom' ) );
		}
		$source = (string) self::conf( 'template' );
		$inc    = (string) self::conf( 'included' );
		if ( '' === $source || '' === $inc ) {
			throw new RuntimeException( __( 'Pick the promotion template and the audience under Settings → Email campaigns first.', 'dazont-ecom' ) );
		}
		$name    = trim( (string) ( $in['name'] ?? '' ) ) ?: (string) ( $rule['title'] ?? __( 'Promotion', 'dazont-ecom' ) );
		$subject = trim( (string) ( $in['subject'] ?? '' ) );
		$preview = trim( (string) ( $in['preview'] ?? '' ) );
		$vars    = [];
		foreach ( (array) ( $in['vars'] ?? [] ) as $marker => $value ) {
			$marker = trim( (string) $marker );
			if ( '' !== $marker ) {
				$vars[ $marker ] = (string) $value;
			}
		}
		$warning = '';

		// 1. The owner's template is never touched: the campaign gets a copy.
		$clone = self::request( 'POST', 'template-clone/', [
			'data' => [ 'type' => 'template', 'id' => $source, 'attributes' => [ 'name' => mb_substr( $name, 0, 120 ) ] ],
		], 30 );
		if ( is_wp_error( $clone ) ) {
			throw new RuntimeException( $clone->get_error_message() );
		}
		$tpl_id = (string) ( $clone['data']['id'] ?? '' );
		if ( '' === $tpl_id ) {
			throw new RuntimeException( __( 'Klaviyo cloned nothing back.', 'dazont-ecom' ) );
		}

		// 2-4. The markers become this event's own figures, in the copy only.
		if ( $vars ) {
			$full = self::request( 'GET', 'templates/' . $tpl_id . '?additional-fields[template]=definition', null, 30 );
			if ( is_wp_error( $full ) ) {
				$warning = $full->get_error_message();
			} else {
				$def = $full['data']['attributes']['definition'] ?? null;
				if ( is_array( $def ) ) {
					$new = self::swap( $def, $vars );
					if ( $new !== $def ) {
						$patched = self::request( 'PATCH', 'templates/' . $tpl_id . '/', [
							'data' => [ 'type' => 'template', 'id' => $tpl_id, 'attributes' => [ 'definition' => $new ] ],
						], 45 );
						if ( is_wp_error( $patched ) ) {
							$warning = $patched->get_error_message();
						}
					}
				}
			}
		}

		// 5. The campaign itself — the audience answered once, in the settings.
		$exc  = (string) self::conf( 'excluded' );
		$body = [
			'data' => [
				'type'       => 'campaign',
				'attributes' => [
					'name'          => mb_substr( $name, 0, 120 ),
					'audiences'     => [
						'included' => [ $inc ],
						'excluded' => '' !== $exc ? [ $exc ] : [],
					],
					'send_strategy' => [
						'method'   => 'static',
						'datetime' => self::when( (string) ( $in['datetime'] ?? '' ), $rule ),
						'options'  => [
							'is_local'                         => true,
							'send_past_recipients_immediately' => true,
						],
					],
					'send_options'  => [ 'use_smart_sending' => true ],
					'campaign-messages' => [
						'data' => [
							[
								'type'       => 'campaign-message',
								'attributes' => [
									'definition' => [
										'channel' => 'email',
										'label'   => mb_substr( $name, 0, 120 ),
										// No sender of ours: the account sender is
										// the verified one, and the one every
										// other campaign of this shop goes out
										// with.
										'content' => array_filter( [
											'subject'      => $subject,
											'preview_text' => $preview,
										] ),
									],
								],
							],
						],
					],
				],
			],
		];
		$camp = self::request( 'POST', 'campaigns/', $body, 30 );
		if ( is_wp_error( $camp ) ) {
			throw new RuntimeException( $camp->get_error_message() );
		}
		$camp_id = (string) ( $camp['data']['id'] ?? '' );
		$msg_id  = (string) ( $camp['data']['relationships']['campaign-messages']['data'][0]['id'] ?? '' );
		if ( '' === $msg_id ) {
			foreach ( (array) ( $camp['included'] ?? [] ) as $row ) {
				if ( 'campaign-message' === ( $row['type'] ?? '' ) ) {
					$msg_id = (string) $row['id'];
					break;
				}
			}
		}

		// 6. The copy becomes the content of that campaign.
		if ( '' !== $msg_id ) {
			$assign = self::request( 'POST', 'campaign-message-assign-template/', [
				'data' => [
					'type'          => 'campaign-message',
					'id'            => $msg_id,
					'relationships' => [ 'template' => [ 'data' => [ 'type' => 'template', 'id' => $tpl_id ] ] ],
				],
			], 30 );
			if ( is_wp_error( $assign ) ) {
				$warning = $assign->get_error_message();
			}
		} else {
			$warning = __( 'The campaign was created but Klaviyo did not name its message, so the template was left unassigned.', 'dazont-ecom' );
		}

		// 7. The one line a machine translator writes worse than the shop does.
		if ( '' !== $msg_id ) {
			$pushed = self::push_subjects( $msg_id, $rule );
			if ( '' !== $pushed ) {
				$warning = trim( $warning . ' ' . $pushed );
			}
		}

		$map = self::map();
		$map[ $rule_id ] = [
			'campaign' => $camp_id,
			'message'  => $msg_id,
			'template' => $tpl_id,
			'name'     => $name,
			'at'       => time(),
		];
		update_option( self::OPT_MAP, $map, false );

		return [
			'campaign' => $camp_id,
			'message'  => $msg_id,
			'template' => $tpl_id,
			'url'      => self::campaign_url( $camp_id ),
			'warning'  => $warning,
		];
	}

	/**
	 * Writes the subject of the campaign in each market, from the lines the
	 * event already carries. Everything else in the email is left to
	 * Klaviyo's own translator, which reads the language on the profile.
	 *
	 * @return string '' when it worked, otherwise what to tell the owner.
	 */
	private static function push_subjects( string $msg_id, array $rule ): string {
		$i18n = (array) ( $rule['banner_text_i18n'] ?? [] );
		$i18n = array_filter( array_map( 'trim', array_map( 'strval', $i18n ) ) );
		if ( ! $i18n ) {
			return ''; // nothing adapted on this event: nothing to push, and no fault.
		}
		$source  = class_exists( 'DZE_Wpml' ) ? DZE_Wpml::default_language() : 'en';
		$targets = array_keys( $i18n );

		$made = self::request( 'POST', 'translations/', [
			'data' => [
				'type'          => 'translation',
				'attributes'    => [
					'source_locale'   => $source,
					'target_locales'  => $targets,
					'fallback_locale' => $source,
					'channel'         => 'email',
				],
				'relationships' => [
					'campaign-variation' => [ 'data' => [ 'type' => 'campaign-variation', 'id' => $msg_id ] ],
				],
			],
		], 30, true );
		$tr_id = is_wp_error( $made ) ? 'campaign-variation::email::' . $msg_id : (string) ( $made['data']['id'] ?? '' );
		if ( '' === $tr_id ) {
			return __( 'The campaign is ready, but Klaviyo would not open a localisation for it.', 'dazont-ecom' );
		}

		$read = self::request( 'GET', 'translations/' . $tr_id . '?additional-fields[translation]=values', null, 30, true );
		if ( is_wp_error( $read ) ) {
			return $read->get_error_message();
		}
		// One value out of the whole email: its subject. Everything else —
		// headings, buttons, the footer — is Klaviyo's translator's work, and
		// overwriting half of it with something else is how two voices end up
		// in the same email.
		$line = [];
		foreach ( $i18n as $code => $text ) {
			$line[ $code ] = mb_substr( $text, 0, 150 );
		}
		$values = [];
		foreach ( (array) ( $read['data']['attributes']['values'] ?? [] ) as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( '' !== $id && '::subject' === substr( $id, -9 ) ) {
				$values[] = [ 'id' => $id, 'translations' => $line ];
			}
		}
		if ( ! $values ) {
			return '';
		}
		$saved = self::request( 'PATCH', 'translations/' . $tr_id . '/', [
			'data' => [
				'type'       => 'translation',
				'id'         => $tr_id,
				'attributes' => [ 'values' => $values ],
			],
		], 30, true );
		if ( is_wp_error( $saved ) ) {
			return $saved->get_error_message();
		}
		return '';
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

	public static function ajax_load(): void {
		self::guard();
		try {
			$cat = self::refresh();
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		$message = sprintf(
			/* translators: 1: number of templates, 2: number of lists and segments */
			__( 'Read %1$d templates and %2$d audiences.', 'dazont-ecom' ),
			count( $cat['templates'] ),
			count( $cat['audiences'] )
		);
		// What the account refused is said, not swallowed: an empty picker
		// with no reason beside it is a bug hunt; "403 — the key has no list
		// access" is a five-second fix.
		if ( ! empty( $cat['errors'] ) ) {
			$message .= ' ' . implode( ' ', array_unique( (array) $cat['errors'] ) );
		}
		wp_send_json_success( [
			'templates' => $cat['templates'],
			'audiences' => $cat['audiences'],
			'partial'   => ! empty( $cat['errors'] ),
			'message'   => $message,
		] );
	}

	/** Subject + preview text for one promotion, written by Claude. */
	public static function ajax_subject(): void {
		self::guard();
		$rule_id = isset( $_POST['rule'] ) ? sanitize_key( wp_unslash( $_POST['rule'] ) ) : '';
		$rules   = class_exists( 'DZE_Discounts' ) ? DZE_Discounts::get_rules() : [];
		$rule    = (array) ( $rules[ $rule_id ] ?? [] );
		if ( ! $rule ) {
			wp_send_json_error( [ 'message' => __( 'That event no longer exists.', 'dazont-ecom' ) ] );
		}
		$vars = self::variables( $rule );
		$lang = class_exists( 'DZE_Content' ) ? DZE_Content::site_language() : 'English';
		$user = "--- THE PROMOTION ---\n"
			. 'Title: ' . $vars['{{PROMO_TITLE}}'] . "\n"
			. 'Discount: ' . $vars['{{PROMO_PERCENT}}'] . "\n"
			. 'Runs: ' . $vars['{{PROMO_START}}'] . ' → ' . $vars['{{PROMO_END}}'] . "\n"
			. "\n--- INSTRUCTIONS ---\n" . self::subject_prompt() . "\n"
			. "\n--- LANGUAGE ---\nWrite in " . $lang . ".\n"
			. "\n--- OUTPUT ---\nJSON only: {\"subject\":\"…\",\"preview\":\"…\"}. No other key, no comment.";

		DZE_Ai_Usage::unit( 'promo_email' );
		try {
			$out = DZE_Marketing_Ai::complete(
				'You write the subject lines of an online shop\'s promotional emails. You reply with JSON only.',
				$user,
				'',
				400,
				60
			);
		} catch ( \Throwable $e ) {
			DZE_Ai_Usage::unit();
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		DZE_Ai_Usage::unit();
		$json = json_decode( trim( (string) preg_replace( '/^```(?:json)?|```$/m', '', (string) $out ) ), true );
		if ( ! is_array( $json ) || '' === trim( (string) ( $json['subject'] ?? '' ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing usable came back — try again.', 'dazont-ecom' ) ] );
		}
		DZE_Ai_Usage::finished( 'promo_email' );
		wp_send_json_success( [
			'subject' => mb_substr( sanitize_text_field( (string) $json['subject'] ), 0, 150 ),
			'preview' => mb_substr( sanitize_text_field( (string) ( $json['preview'] ?? '' ) ), 0, 150 ),
		] );
	}

	public static function ajax_draft(): void {
		self::guard();
		$rule_id = isset( $_POST['rule'] ) ? sanitize_key( wp_unslash( $_POST['rule'] ) ) : '';
		$vars    = [];
		if ( isset( $_POST['vars'] ) ) {
			$raw = json_decode( (string) wp_unslash( $_POST['vars'] ), true );
			foreach ( (array) $raw as $marker => $value ) {
				$vars[ sanitize_text_field( (string) $marker ) ] = sanitize_text_field( (string) $value );
			}
		}
		try {
			$made = self::draft( $rule_id, [
				'name'     => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'subject'  => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
				'preview'  => isset( $_POST['preview'] ) ? sanitize_text_field( wp_unslash( $_POST['preview'] ) ) : '',
				'datetime' => isset( $_POST['datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['datetime'] ) ) : '',
				'vars'     => $vars,
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( $made );
	}

	// =========================================================================
	// Screens
	// =========================================================================

	public function enqueue( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab navigation only.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// The events screen only carries this once there is something to click.
		$events = class_exists( 'DZE_Discounts' ) && false !== strpos( $hook, DZE_Discounts::MENU_SLUG_EVENTS ) && $this->configured();
		$config = 'email' === $tab && class_exists( 'DZE_Marketing_Ai' ) && false !== strpos( $hook, DZE_Marketing_Ai::MENU_SLUG );
		if ( ! $events && ! $config ) {
			return;
		}
		wp_enqueue_script( 'dze-klaviyo', DZE_URL . 'admin/js/klaviyo.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-klaviyo', 'dzeKlav', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'loading'  => __( 'Reading your Klaviyo account…', 'dazont-ecom' ),
				'writing'  => __( 'Writing…', 'dazont-ecom' ),
				'creating' => __( 'Creating the draft in Klaviyo…', 'dazont-ecom' ),
				'made'     => __( 'Draft ready in Klaviyo — nothing was sent.', 'dazont-ecom' ),
				'error'    => __( 'Something went wrong.', 'dazont-ecom' ),
				'subject'  => __( 'Write a subject line first.', 'dazont-ecom' ),
				'open'     => __( 'Open draft ↗', 'dazont-ecom' ),
				'again'    => __( 'Again', 'dazont-ecom' ),
			],
		] );
	}

	/**
	 * The cell on a marketing event's row: create the draft, or open the one
	 * that already exists.
	 */
	public function render_cell( string $rule_id, array $rule ): void {
		$made = self::draft_for( $rule_id );
		$vars = self::variables( $rule );
		$made_link = ! empty( $made['campaign'] );
		echo '<div class="dze-klav-cell" data-rule="' . esc_attr( $rule_id ) . '"'
			. ' data-name="' . esc_attr( (string) ( $rule['title'] ?? '' ) ) . '"'
			. ' data-title="' . esc_attr( $vars['{{PROMO_TITLE}}'] ) . '"'
			. ' data-when="' . esc_attr( self::default_datetime( $rule ) ) . '"'
			. ' data-vars="' . esc_attr( (string) wp_json_encode( $vars ) ) . '">';
		if ( $made_link ) {
			printf(
				'<a href="%1$s" class="dze-klav-link" target="_blank" rel="noopener noreferrer">%2$s</a> <span style="color:#999;">|</span> ',
				esc_url( self::campaign_url( (string) $made['campaign'] ) ),
				esc_html__( 'Open draft ↗', 'dazont-ecom' )
			);
			echo '<a href="#" class="dze-klav-open">' . esc_html__( 'Again', 'dazont-ecom' ) . '</a>';
		} else {
			echo '<a href="#" class="dze-klav-open">' . esc_html__( 'Draft email', 'dazont-ecom' ) . '</a>';
		}
		echo '<span class="dze-klav-msg" style="display:block;font-size:12px;margin-top:2px;"></span>';
		echo '</div>';
	}

	/** The one popup, printed once at the bottom of the events screen. */
	public function render_panel(): void {
		?>
		<div class="dze-klav-modal" id="dze-klav-modal" style="display:none;">
			<div class="dze-klav-modal__inner">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Announce this promotion by email', 'dazont-ecom' ); ?></h2>
				<p class="description" style="max-width:640px;">
					<?php esc_html_e( 'A draft campaign is created in Klaviyo from your promotion template, for the audience chosen in the settings. It is never sent and never scheduled from here — you read it in Klaviyo and decide.', 'dazont-ecom' ); ?>
				</p>
				<input type="hidden" id="dze-klav-rule" value="" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dze-klav-name"><?php esc_html_e( 'Campaign name', 'dazont-ecom' ); ?></label></th>
						<td><input type="text" id="dze-klav-name" class="large-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="dze-klav-subject"><?php esc_html_e( 'Subject', 'dazont-ecom' ); ?></label></th>
						<td>
							<input type="text" id="dze-klav-subject" class="large-text" />
							<p style="margin:6px 0 0;">
								<button type="button" class="button-link" id="dze-klav-write">&#9998; <?php esc_html_e( 'Write the subject and the preview text', 'dazont-ecom' ); ?></button>
								<?php if ( class_exists( 'DZE_Prompts' ) ) { DZE_Prompts::the_button( 'promo_email' ); } ?>
								<span id="dze-klav-write-msg" class="description" style="margin-left:8px;"></span>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dze-klav-preview"><?php esc_html_e( 'Preview text', 'dazont-ecom' ); ?></label></th>
						<td><input type="text" id="dze-klav-preview" class="large-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="dze-klav-when"><?php esc_html_e( 'Send date on the draft', 'dazont-ecom' ); ?></label></th>
						<td>
							<input type="datetime-local" id="dze-klav-when" />
							<p class="description"><?php esc_html_e( 'Written on the draft so it is ready to schedule. Klaviyo sends nothing until you press send there.', 'dazont-ecom' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'What the template reads', 'dazont-ecom' ); ?></th>
						<td>
							<div id="dze-klav-vars"></div>
							<p class="description" style="max-width:640px;">
								<?php esc_html_e( 'Every one of these markers found in your template — in a heading, a paragraph, a button or a link — is replaced by the value beside it, in the copy made for this campaign. Your template itself is never modified.', 'dazont-ecom' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<p>
					<button type="button" class="button button-primary" id="dze-klav-go"><?php esc_html_e( 'Create the draft in Klaviyo', 'dazont-ecom' ); ?></button>
					<button type="button" class="button-link" id="dze-klav-cancel" style="margin-left:6px;"><?php esc_html_e( 'Cancel', 'dazont-ecom' ); ?></button>
					<span id="dze-klav-status" style="margin-left:8px;font-size:13px;"></span>
				</p>
			</div>
		</div>
		<style>
			.dze-klav-modal{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;padding:24px;}
			.dze-klav-modal__inner{background:#fff;border-radius:10px;width:min(720px,96vw);max-height:88vh;overflow:auto;padding:18px 24px;}
			.dze-klav-var{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
			.dze-klav-var code{min-width:170px;}
		</style>
		<?php
	}

	/** Settings → Email campaigns. */
	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$cat     = self::catalogue();
		$locked  = defined( 'DZE_KLAVIYO_API_KEY' ) && DZE_KLAVIYO_API_KEY;
		$has_key = '' !== self::key();
		$tpl     = (string) self::conf( 'template' );
		$inc     = (string) self::conf( 'included' );
		$exc     = (string) self::conf( 'excluded' );
		?>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Turns a marketing event into a draft campaign in Klaviyo: your promotion template is copied, its markers are filled with the event\'s own figures, and the draft is addressed to the audience you choose here. Nothing is ever sent from WordPress.', 'dazont-ecom' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_klaviyo_options' ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::OPT ); ?>[form]" value="1" />

			<h2 class="title"><?php esc_html_e( 'Klaviyo private API key', 'dazont-ecom' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dze-klav-key"><?php esc_html_e( 'API key', 'dazont-ecom' ); ?></label></th>
					<td>
						<?php echo DZE_Api_Keys::status_html( 'klaviyo', self::key(), (bool) $locked ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?>
						<?php if ( ! $locked ) : ?>
							<input type="password" id="dze-klav-key" name="<?php echo esc_attr( self::OPT . '[api_key]' ); ?>" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_key ? esc_attr__( 'Leave blank to keep the saved key', 'dazont-ecom' ) : 'pk_…'; ?>" />
							<p class="description">
								<?php esc_html_e( 'Klaviyo → Settings → API keys → Create private API key, with campaigns, templates and lists/segments enabled.', 'dazont-ecom' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'The promotion template and the audience', 'dazont-ecom' ); ?></h2>
			<p>
				<button type="button" class="button" id="dze-klav-refresh" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Read them from Klaviyo', 'dazont-ecom' ); ?></button>
				<span id="dze-klav-refresh-msg" style="margin-left:8px;font-size:13px;">
					<?php
					if ( empty( $cat['templates'] ) ) {
						esc_html_e( 'Nothing read yet — press the button once and the lists below fill in.', 'dazont-ecom' );
					} else {
						printf(
							/* translators: %s: when the account was last read */
							esc_html__( 'Last read %s ago.', 'dazont-ecom' ),
							esc_html( human_time_diff( (int) ( $cat['read'] ?? time() ) ) )
						);
					}
					?>
				</span>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dze-klav-tpl"><?php esc_html_e( 'Promotion template', 'dazont-ecom' ); ?></label></th>
					<td>
						<select id="dze-klav-tpl" name="<?php echo esc_attr( self::OPT . '[template]' ); ?>" style="min-width:340px;">
							<option value=""><?php esc_html_e( '— pick a template —', 'dazont-ecom' ); ?></option>
							<?php foreach ( (array) $cat['templates'] as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $tpl ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
							<?php if ( '' !== $tpl && ! isset( $cat['templates'][ $tpl ] ) ) : ?>
								<option value="<?php echo esc_attr( $tpl ); ?>" selected><?php echo esc_html( $tpl ); ?></option>
							<?php endif; ?>
						</select>
						<p class="description"><?php esc_html_e( 'The email every promotion is built from. Each campaign gets its own copy of it, so editing one campaign never changes the others.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-klav-inc"><?php esc_html_e( 'Send to', 'dazont-ecom' ); ?></label></th>
					<td>
						<select id="dze-klav-inc" name="<?php echo esc_attr( self::OPT . '[included]' ); ?>" style="min-width:340px;">
							<option value=""><?php esc_html_e( '— pick an audience —', 'dazont-ecom' ); ?></option>
							<?php foreach ( (array) $cat['audiences'] as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $inc ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
							<?php if ( '' !== $inc && ! isset( $cat['audiences'][ $inc ] ) ) : ?>
								<option value="<?php echo esc_attr( $inc ); ?>" selected><?php echo esc_html( $inc ); ?></option>
							<?php endif; ?>
						</select>
						<p class="description"><?php esc_html_e( 'One campaign for everybody: the language each reader gets is decided by the language on his profile, not by a separate campaign per market.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-klav-exc"><?php esc_html_e( 'Except', 'dazont-ecom' ); ?></label></th>
					<td>
						<select id="dze-klav-exc" name="<?php echo esc_attr( self::OPT . '[excluded]' ); ?>" style="min-width:340px;">
							<option value=""><?php esc_html_e( '— nobody —', 'dazont-ecom' ); ?></option>
							<?php foreach ( (array) $cat['audiences'] as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $exc ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
							<?php if ( '' !== $exc && ! isset( $cat['audiences'][ $exc ] ) ) : ?>
								<option value="<?php echo esc_attr( $exc ); ?>" selected><?php echo esc_html( $exc ); ?></option>
							<?php endif; ?>
						</select>
					</td>
				</tr>
			</table>

			<p class="description" style="max-width:880px;">
				<?php esc_html_e( 'The rest is not asked: the draft goes out under the sender verified on your Klaviyo account, it is dated the day the promotion opens (at nine, in each reader\'s own time zone, and the field is in front of you on the campaign itself), and the subject is written in every language from the lines the event already carries — Klaviyo\'s translator does the rest of the email.', 'dazont-ecom' ); ?>
			</p>

			<h2 class="title"><?php esc_html_e( 'Subject line instructions', 'dazont-ecom' ); ?></h2>
			<textarea id="dze-klav-prompt" name="<?php echo esc_attr( self::OPT . '[subject_prompt]' ); ?>" rows="8" class="large-text code" style="max-width:880px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;"><?php echo esc_textarea( self::subject_prompt() ); ?></textarea>
			<p>
				<button type="button" class="button-link" id="dze-klav-prompt-reset">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
				<?php if ( class_exists( 'DZE_Prompt_Defaults' ) ) { DZE_Prompt_Defaults::control( 'promo_email', '#dze-klav-prompt' ); } ?>
			</p>
			<script>
			(function () {
				var shipped = <?php echo wp_json_encode( self::default_subject_prompt() ); ?>;
				var btn = document.getElementById('dze-klav-prompt-reset');
				var ta  = document.getElementById('dze-klav-prompt');
				if ( btn && ta ) {
					btn.addEventListener('click', function () {
						ta.value = window.dzeDefaultFor ? window.dzeDefaultFor( 'promo_email', shipped ) : shipped;
						ta.focus();
					});
				}
			}());
			</script>

			<?php submit_button( __( 'Save email settings', 'dazont-ecom' ) ); ?>
		</form>
		<?php
	}
}
