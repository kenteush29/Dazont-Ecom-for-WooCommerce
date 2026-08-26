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
 * So the email is BUILT here, not fetched: the header, the footer and the
 * frame belong to the plugin and never change from one promotion to the next,
 * so nothing has to be kept in step in two places. What a promotion says —
 * a headline, a few sentences, the offer, a button, three products — is
 * written on the event's own screen in named fields, looked at through the
 * same function that builds the email itself, and only then handed to Klaviyo
 * as the HTML of a new template, assigned to a draft campaign for the audience
 * chosen once in the settings.
 *
 * It never sends and never schedules: what comes out is a draft, and the
 * decision to send stays where it belongs — in front of the campaign, in
 * Klaviyo. Language is not our business either: profiles carry the language
 * the shop assigned them, and Klaviyo's own translator serves each reader in
 * his. The one thing it adds is the ONE line no machine translator writes as
 * well as the shop does — the promotion title already adapted market by market
 * on the event itself, pushed as the subject in each language.
 *
 * Footprint: admin only. Not a hook, not a query, not an option read on a
 * shop page. Every call to Klaviyo happens inside an explicit click.
 */
final class DZE_Klaviyo {

	public const OPT     = 'dze_klaviyo';         // settings (never autoloaded).
	public const OPT_MAP  = 'dze_klaviyo_drafts'; // rule id => the draft it produced.
	public const OPT_COPY = 'dze_klaviyo_copy';   // rule id => the email written for it.

	private const API   = 'https://a.klaviyo.com/api/';
	private const REV   = '2025-07-15';      // stable API revision.
	private const REV_B = '2025-07-15.pre';  // the localisation endpoints are beta.
	private const NONCE = 'dze_klaviyo';
	private const CACHE = 'dze_klaviyo_cat'; // the account's audiences, as last read.

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
		add_action( 'wp_ajax_dze_klav_write',   [ __CLASS__, 'ajax_write' ] );
		add_action( 'wp_ajax_dze_klav_draft',   [ __CLASS__, 'ajax_draft' ] );
		add_action( 'wp_ajax_dze_klav_preview', [ __CLASS__, 'ajax_preview' ] );
		add_action( 'wp_ajax_dze_klav_activate', [ __CLASS__, 'ajax_activate' ] );
		add_action( 'wp_ajax_dze_klav_segment',  [ __CLASS__, 'ajax_make_segment' ] );
		// The email is written on the event's own screen and saved by the
		// event's own Save button — never by a second save of our own.
		add_action( 'dze_discount_saved', [ __CLASS__, 'save_copy' ], 10, 3 );
		// An event that no longer exists keeps no email: the rows follow the
		// promotions they belong to instead of piling up unread.
		add_action( 'dze_discount_deleted', [ __CLASS__, 'forget' ] );
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

	/** Everything a draft needs is answered: a key and an audience. */
	public function configured(): bool {
		return '' !== self::key() && '' !== (string) self::conf( 'included' );
	}

	/** What is missing before a draft can be made, in plain words. */
	public function missing(): string {
		if ( '' === self::key() ) {
			return __( 'the Klaviyo API key', 'dazont-ecom' );
		}
		if ( '' === (string) self::conf( 'included' ) ) {
			return __( 'the audience to send to', 'dazont-ecom' );
		}
		return '';
	}

	/**
	 * The parts of the email that never change from one promotion to the next.
	 *
	 * The shipped values are the shop's own reference email — the header and
	 * the footer of "Marketing Email 1" — so a fresh install already looks
	 * like the shop instead of looking like a plugin. Every one of them is a
	 * field on the settings tab.
	 *
	 * @return array{logo:string,accent:string,ink:string,paper:string,note:string,reassure:array}
	 */
	public static function design(): array {
		$hex = static function ( $v, string $fallback ): string {
			$v = is_string( $v ) ? trim( $v ) : '';
			return preg_match( '/^#[0-9a-fA-F]{6}$/', $v ) ? $v : $fallback;
		};
		$s   = self::settings();
		$out = [
			'logo'   => (string) ( $s['logo'] ?? '' ),
			'accent' => $hex( $s['accent'] ?? '', '#719D1A' ),
			'ink'    => $hex( $s['ink'] ?? '', '#111111' ),
			'paper'  => $hex( $s['paper'] ?? '', '#F4F4EE' ),
			'note'   => isset( $s['note'] ) ? (string) $s['note'] : __( 'We got you covered', 'dazont-ecom' ),
		];
		$shipped = self::default_reassure();
		$saved   = (array) ( $s['reassure'] ?? [] );
		$out['reassure'] = [];
		foreach ( [ 0, 1, 2 ] as $i ) {
			$one = (array) ( $saved[ $i ] ?? [] );
			$out['reassure'][ $i ] = [
				'icon'  => (string) ( $one['icon'] ?? ( $saved ? '' : ( $shipped[ $i ]['icon'] ?? '' ) ) ),
				'title' => (string) ( $one['title'] ?? ( $saved ? '' : ( $shipped[ $i ]['title'] ?? '' ) ) ),
				'line'  => (string) ( $one['line'] ?? ( $saved ? '' : ( $shipped[ $i ]['line'] ?? '' ) ) ),
			];
		}
		return $out;
	}

	/** The three promises the shop's own emails carry, as they stand today. */
	public static function default_reassure(): array {
		$cdn = 'https://d3k81ch9hvuctc.cloudfront.net/company/UNysVD/images/';
		return [
			[ 'icon' => $cdn . '74d7e758-2982-4642-b10b-45a492e0408f.png', 'title' => __( 'Worldwide Delivery', 'dazont-ecom' ), 'line' => __( '- Anywhere in the world -', 'dazont-ecom' ) ],
			[ 'icon' => $cdn . '1f414ab2-f6df-4d81-8dca-8ed7fd15dacf.png', 'title' => __( '5/7 Customer Support', 'dazont-ecom' ), 'line' => __( '- Ready to help you -', 'dazont-ecom' ) ],
			[ 'icon' => $cdn . 'f8320b08-de46-4345-ae02-704d0811b651.png', 'title' => __( 'Secure Payments', 'dazont-ecom' ), 'line' => __( '- Website fully protected -', 'dazont-ecom' ) ],
		];
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
		// The audience is only rewritten by the form that carries the two
		// pickers. Anything else posting this option — a future screen, a
		// filter, a half-submitted page — leaves the shop's audience alone,
		// because "who this shop emails" is not a field to lose in an update.
		if ( ! empty( $in['form'] ) ) {
			foreach ( [ 'included', 'excluded' ] as $id_field ) {
				if ( array_key_exists( $id_field, $in ) ) {
					$out[ $id_field ] = sanitize_text_field( (string) $in[ $id_field ] );
				}
			}
		}
		// The sender, the send moment and the per-market subject are not
		// questions: Klaviyo's own sender, the day the promotion opens, and
		// yes. Three settings whose only sane answer was already the default
		// are three ways to get it wrong, so they are gone — and the ones
		// stored before are dropped rather than left behind to confuse a
		// later reader.
		unset( $out['from_label'], $out['from_email'], $out['hour'], $out['lead_days'], $out['local'], $out['i18n_push'] );
		// The email is built here now, so the Klaviyo template that used to be
		// picked — and the markers it had to carry — are gone with it.
		unset( $out['template'], $out['template_name'] );
		if ( ! empty( $in['form'] ) ) {
			$out['logo']   = esc_url_raw( (string) ( $in['logo'] ?? '' ) );
			$out['accent'] = sanitize_text_field( (string) ( $in['accent'] ?? '' ) );
			$out['ink']    = sanitize_text_field( (string) ( $in['ink'] ?? '' ) );
			$out['paper']  = sanitize_text_field( (string) ( $in['paper'] ?? '' ) );
			$out['note']   = sanitize_text_field( (string) ( $in['note'] ?? '' ) );
			$rows = [];
			foreach ( [ 0, 1, 2 ] as $i ) {
				$one = (array) ( $in['reassure'][ $i ] ?? [] );
				$rows[ $i ] = [
					'icon'  => esc_url_raw( trim( (string) ( $one['icon'] ?? '' ) ) ),
					'title' => sanitize_text_field( (string) ( $one['title'] ?? '' ) ),
					'line'  => sanitize_text_field( (string) ( $one['line'] ?? '' ) ),
				];
			}
			$out['reassure'] = $rows;
		}
		if ( array_key_exists( 'email_prompt', $in ) ) {
			// Same treatment as every other prompt of the plugin: shipped text
			// saved as it stands means "no custom prompt".
			$text = trim( sanitize_textarea_field( (string) $in['email_prompt'] ) );
			$out['email_prompt'] = ( $text === trim( self::default_email_prompt() ) ) ? '' : $text;
			unset( $out['subject_prompt'] );
		}
		unset( $out['form'] );
		return $out;
	}

	public static function email_prompt(): string {
		$custom = trim( (string) ( self::settings()['email_prompt'] ?? self::settings()['subject_prompt'] ?? '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		return class_exists( 'DZE_Prompt_Defaults' )
			? DZE_Prompt_Defaults::pick( 'promo_email', self::default_email_prompt() )
			: self::default_email_prompt();
	}

	public static function default_email_prompt(): string {
		return "Write the email that announces this promotion.\n"
			. "The subject decides whether it is opened: say the offer, not the season. Six to nine words, no more — past that it is cut off on a phone. Figures are welcome, and they are the ones given.\n"
			. "The preview text continues the subject, it does not repeat it: it is the second half of the sentence read in the inbox. Four to eight words.\n"
			. "For every other piece asked for, write what its NAME says it is and nothing else — a heading is one line, a body is two or three short sentences, a button is two or three words in the imperative. Each piece has to stand on its own: the reader sees them laid out, not run together as a paragraph.\n"
			. "Say what the offer is, what it covers and when it ends. One idea per piece.\n"
			. "No ALL CAPS, no stacked exclamation marks, no \"Don't miss out\", no emoji unless the promotion is a holiday one.\n"
			. "Never promise anything the promotion does not say — no free shipping, no gift, no extra code. Never invent a product name.\n"
			. "Plain text only: no HTML, no markdown, no quotation marks around the result.";
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
	 * The lists and segments of the account, as last read.
	 *
	 * Never fetched while a page is being drawn: the settings screen shows
	 * what the cache holds and says so when it is empty, and the button
	 * behind it does the reading.
	 */
	public static function catalogue(): array {
		$c = get_transient( self::CACHE );
		return is_array( $c ) ? $c : [ 'audiences' => [], 'inactive' => [], 'read' => 0 ];
	}

	/** Reads the lists and segments the shop can address. */
	public static function refresh(): array {
		$out    = [ 'audiences' => [], 'inactive' => [], 'read' => time() ];
		$errors = [];

		// Segments: asked for WITH the inactive ones. Klaviyo's default listing
		// hides those, and a shop whose exclusion segment is one of them looks
		// at a picker that does not offer what its own campaigns use. They are
		// named as inactive here, because an inactive segment in Klaviyo is not
		// maintained — excluding it excludes nobody, and that is worth knowing
		// before pressing send.
		$out['inactive'] = [];
		$rows = self::pages( 'segments/?fields[segment]=name,is_active&filter=' . rawurlencode( 'any(is_active,[true,false])' ), $errors, 20 );
		foreach ( $rows as $row ) {
			$id   = (string) $row['id'];
			$name = (string) ( $row['attributes']['name'] ?? $id );
			if ( empty( $row['attributes']['is_active'] ) ) {
				$out['inactive'][] = $id;
				/* translators: %s: segment name */
				$name = sprintf( __( '%s — inactive', 'dazont-ecom' ), $name );
			}
			$out['audiences'][ $id ] = __( 'Segment', 'dazont-ecom' ) . ' · ' . $name;
		}
		// No page[size] of our own: Klaviyo caps it per endpoint, and a number
		// over that cap is a 400 — which is exactly how these pickers came back
		// empty once.
		foreach ( self::pages( 'lists/?fields[list]=name', $errors ) as $row ) {
			$out['audiences'][ (string) $row['id'] ] = __( 'List', 'dazont-ecom' ) . ' · ' . (string) ( $row['attributes']['name'] ?? $row['id'] );
		}
		asort( $out['audiences'] );
		if ( ! $out['audiences'] && $errors ) {
			throw new RuntimeException( implode( ' ', array_unique( $errors ) ) );
		}
		set_transient( self::CACHE, $out, 12 * HOUR_IN_SECONDS );
		$out['errors'] = $errors;
		return $out;
	}

	/**
	 * The email of one event, part by part — named, not marked up.
	 *
	 * There is no template to keep in step any more: the header, the footer
	 * and the frame belong to the plugin and never change from one promotion
	 * to the next, so what is left is the handful of lines a promotion
	 * actually says. Each one starts from what the event already knows.
	 *
	 * @return array<string,string|bool>
	 */
	public static function parts( array $rule, string $rule_id = '' ): array {
		$title = trim( (string) ( $rule['banner_text'] ?? '' ) );
		if ( '' === $title ) {
			$title = trim( (string) ( $rule['title'] ?? '' ) );
		}
		$pct  = rtrim( rtrim( number_format( (float) ( $rule['percent'] ?? 0 ), 2, '.', '' ), '0' ), '.' );
		$fmt  = get_option( 'date_format' ) ?: 'Y-m-d';
		$end  = ! empty( $rule['end'] ) ? (string) wp_date( $fmt, (int) strtotime( $rule['end'] . ' 00:00:00' ) ) : '';

		$out = [
			'headline'  => $title,
			'body'      => '',
			'offer'     => $end
				/* translators: 1: discount, 2: end date */
				? sprintf( __( '-%1$s%% on everything until %2$s', 'dazont-ecom' ), $pct, $end )
				/* translators: %s: discount */
				: sprintf( __( '-%s%% on everything', 'dazont-ecom' ), $pct ),
			'cta_label' => __( 'Shop the sale', 'dazont-ecom' ),
			'cta_url'   => home_url( '/' ),
			'hero'      => '',
			'products'  => true,
		];
		$saved = '' !== $rule_id ? self::copy_for( $rule_id ) : [];
		foreach ( (array) ( $saved['parts'] ?? [] ) as $k => $v ) {
			if ( 'products' === $k ) {
				$out['products'] = (bool) $v;
			} elseif ( isset( $out[ $k ] ) && '' !== trim( (string) $v ) ) {
				$out[ $k ] = (string) $v;
			} elseif ( 'hero' === $k || 'body' === $k ) {
				$out[ $k ] = (string) $v;
			}
		}
		return $out;
	}

	/** Which fields the editor draws, and how each one behaves. */
	public static function fields(): array {
		return [
			'headline'  => [ 'label' => __( 'Headline', 'dazont-ecom' ), 'type' => 'text',
				'help' => __( 'The big line at the top. Usually the name of the promotion.', 'dazont-ecom' ) ],
			'body'      => [ 'label' => __( 'Body', 'dazont-ecom' ), 'type' => 'textarea',
				'help' => __( 'Two or three short sentences. Line breaks are kept.', 'dazont-ecom' ) ],
			'offer'     => [ 'label' => __( 'The offer', 'dazont-ecom' ), 'type' => 'text',
				'help' => __( 'Shown in a box of its own — the discount and the deadline.', 'dazont-ecom' ) ],
			'cta_label' => [ 'label' => __( 'Button', 'dazont-ecom' ), 'type' => 'text',
				'help' => __( 'Two or three words.', 'dazont-ecom' ) ],
			'cta_url'   => [ 'label' => __( 'Button link', 'dazont-ecom' ), 'type' => 'url',
				'help' => __( 'Where the button goes — the shop, or the category the sale is about.', 'dazont-ecom' ) ],
		];
	}

	/** The three products the email shows, priced as the promotion prices them. */
	public static function products( int $limit = 3 ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}
		$found = wc_get_products( [
			'limit'    => $limit,
			'status'   => 'publish',
			'orderby'  => 'popularity',
			'order'    => 'DESC',
			'visibility' => 'catalog',
		] );
		$out = [];
		foreach ( (array) $found as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$img = wp_get_attachment_image_url( (int) $product->get_image_id(), 'medium' );
			$out[] = [
				'name'  => $product->get_name(),
				'url'   => (string) $product->get_permalink(),
				'image' => (string) ( $img ?: wc_placeholder_img_src( 'medium' ) ),
				'price' => (string) wp_strip_all_tags( (string) $product->get_price_html() ),
			];
		}
		return $out;
	}

	/**
	 * The whole email, as HTML.
	 *
	 * Tables and inline styles, because that is what mail clients read; 600
	 * pixels wide, because that is what a phone shows without pinching. The
	 * frame is the shop's — logo, colours, footer — and only the middle of it
	 * changes from one promotion to the next.
	 *
	 * @param bool $preview True to resolve Klaviyo's own tags into something
	 *                     readable, for the screen rather than the send.
	 */
	public static function layout( array $parts, bool $preview = false ): string {
		$d      = self::design();
		$name   = get_bloginfo( 'name' );
		$accent = $d['accent'];
		$ink    = $d['ink'];
		$band   = $d['paper'];
		$head   = "'Aldrich', Helvetica, Arial, sans-serif";
		$text   = 'Helvetica, Arial, sans-serif';
		$esc    = static fn( $v ): string => esc_html( (string) $v );
		$rows   = '';

		// Header — the shop's mark, centred, small, linked home. Same as the
		// reference email the shop already sends.
		$logo = $d['logo']
			? sprintf(
				'<a href="%1$s"><img src="%2$s" width="80" alt="%3$s" style="display:block;margin:0 auto;width:80px;max-width:80px;height:auto;border:0;" /></a>',
				esc_url( home_url( '/' ) ),
				esc_url( $d['logo'] ),
				esc_attr( $name )
			)
			: sprintf( '<div style="font:400 22px %1$s;color:%2$s;">%3$s</div>', $head, esc_attr( $ink ), $esc( $name ) );
		$rows .= '<tr><td align="center" style="padding:20px 15px 14px;">' . $logo . '</td></tr>';

		if ( ! empty( $parts['hero'] ) ) {
			$rows .= sprintf(
				'<tr><td style="padding:0;"><img src="%1$s" width="600" alt="" style="display:block;width:100%%;max-width:600px;height:auto;border:0;" /></td></tr>',
				esc_url( (string) $parts['hero'] )
			);
		}
		if ( ! empty( $parts['headline'] ) ) {
			$rows .= sprintf(
				'<tr><td align="center" style="padding:26px 28px 6px;font:400 32px/1.2 %1$s;color:%2$s;">%3$s</td></tr>',
				$head,
				esc_attr( $ink ),
				$esc( $parts['headline'] )
			);
		}
		if ( ! empty( $parts['body'] ) ) {
			$rows .= sprintf(
				'<tr><td align="center" style="padding:10px 34px 4px;font:400 18px/1.6 %1$s;color:%2$s;">%3$s</td></tr>',
				$text,
				esc_attr( $ink ),
				nl2br( $esc( $parts['body'] ) )
			);
		}
		if ( ! empty( $parts['offer'] ) ) {
			$rows .= sprintf(
				'<tr><td align="center" style="padding:20px 28px 4px;">'
				. '<div style="display:inline-block;border:2px solid %1$s;padding:13px 24px;'
				. 'font:400 20px/1.2 %2$s;color:%3$s;">%4$s</div></td></tr>',
				esc_attr( $accent ),
				$head,
				esc_attr( $ink ),
				$esc( $parts['offer'] )
			);
		}
		if ( ! empty( $parts['cta_label'] ) ) {
			$rows .= sprintf(
				'<tr><td align="center" style="padding:22px 18px 10px;">'
				. '<a href="%1$s" style="display:inline-block;background:%2$s;color:#ffffff;text-decoration:none;'
				. 'padding:15px 60px;font:400 16px %3$s;">%4$s</a></td></tr>',
				esc_url( (string) ( $parts['cta_url'] ?? home_url( '/' ) ) ),
				esc_attr( $accent ),
				$text,
				$esc( $parts['cta_label'] )
			);
		}

		if ( ! empty( $parts['products'] ) ) {
			$products = self::products( 3 );
			if ( $products ) {
				$cells = '';
				foreach ( $products as $p ) {
					$cells .= sprintf(
						'<td width="33%%" valign="top" align="center" style="padding:10px 6px;">'
						. '<a href="%1$s" style="text-decoration:none;color:%6$s;">'
						. '<img src="%2$s" width="170" alt="%3$s" style="display:block;width:100%%;max-width:170px;height:auto;border:0;" />'
						. '<div style="padding:10px 2px 2px;font:400 14px/1.35 %5$s;">%3$s</div>'
						. '<div style="padding-bottom:8px;font:400 14px %7$s;color:#666;">%4$s</div>'
						. '</a>'
						. '<a href="%1$s" style="display:inline-block;background:%8$s;color:#ffffff;text-decoration:none;padding:10px 15px;font:400 14px %7$s;">%9$s</a>'
						. '</td>',
						esc_url( $p['url'] ),
						esc_url( $p['image'] ),
						$esc( $p['name'] ),
						$esc( $p['price'] ),
						$head,
						esc_attr( $ink ),
						$text,
						esc_attr( $accent ),
						esc_html__( 'Shop now', 'dazont-ecom' )
					);
				}
				$rows .= '<tr><td style="padding:16px 12px 10px;">'
					. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . $cells . '</tr></table>'
					. '</td></tr>';
			}
		}

		// Footer band — the three promises the shop's own emails carry.
		$cells = '';
		foreach ( $d['reassure'] as $one ) {
			if ( '' === trim( (string) $one['title'] ) && '' === trim( (string) $one['icon'] ) ) {
				continue;
			}
			$icon = $one['icon'] ? sprintf(
				'<img src="%1$s" width="50" alt="" style="display:block;margin:0 auto 8px;width:50px;max-width:50px;height:auto;border:0;" />',
				esc_url( $one['icon'] )
			) : '';
			$cells .= sprintf(
				'<td width="33%%" valign="top" align="center" style="padding:10px 18px 20px;">%1$s'
				. '<div style="font:700 14px %2$s;color:%3$s;">%4$s</div>'
				. '<div style="font:400 14px %2$s;color:%3$s;">%5$s</div></td>',
				$icon,
				$text,
				esc_attr( $ink ),
				$esc( $one['title'] ),
				$esc( $one['line'] )
			);
		}
		if ( '' !== $cells || '' !== trim( $d['note'] ) ) {
			$rows .= sprintf( '<tr><td style="background:%1$s;padding:18px 0 0;">', esc_attr( $band ) );
			if ( '' !== trim( $d['note'] ) ) {
				$rows .= sprintf(
					'<div style="text-align:center;padding:0 18px 6px;font:400 20px %1$s;color:%2$s;">%3$s</div>',
					$head,
					esc_attr( $ink ),
					$esc( $d['note'] )
				);
			}
			if ( '' !== $cells ) {
				$rows .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . $cells . '</tr></table>';
			}
			$rows .= '</td></tr>';
		}

		// The lines the law and the mailbox both want, exactly where the shop
		// already puts them.
		$address = $preview
			? esc_html( $name . ' ' . (string) get_option( 'woocommerce_store_address', '' ) )
			: '{{ organization.name }} {{ organization.full_address }}';
		$unsub = $preview
			? '<span style="color:#1457d0;">' . esc_html__( 'Unsubscribe', 'dazont-ecom' ) . '</span>'
			: '{% unsubscribe %}';
		$rows .= sprintf(
			'<tr><td align="center" style="background:%1$s;padding:15px 18px 20px;font:400 11px/1.6 %2$s;color:#222222;">'
			. '<div>%3$s <span style="color:#1457d0;">%4$s</span>.</div><div>%5$s</div></td></tr>',
			esc_attr( $band ),
			$text,
			esc_html__( 'No longer want to receive these emails?', 'dazont-ecom' ),
			$unsub,
			$address
		);

		return '<!DOCTYPE html><html><head><meta charset="utf-8" />'
			. '<meta name="viewport" content="width=device-width,initial-scale=1" />'
			. '<title>' . $esc( $parts['headline'] ?? $name ) . '</title></head>'
			. sprintf( '<body style="margin:0;padding:0;background:%1$s;">', esc_attr( $band ) )
			. sprintf( '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" style="background:%1$s;"><tr><td align="center">', esc_attr( $band ) )
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;">'
			. $rows
			. '</table></td></tr></table></body></html>';
	}

	/** The email written for one event: subject, preview text, every piece. */
	public static function copy_for( string $rule_id ): array {
		$all = get_option( self::OPT_COPY, [] );
		$all = is_array( $all ) ? $all : [];
		$one = (array) ( $all[ $rule_id ] ?? [] );
		return [
			'subject' => (string) ( $one['subject'] ?? '' ),
			'preview' => (string) ( $one['preview'] ?? '' ),
			'parts'   => (array) ( $one['parts'] ?? [] ),
		];
	}

	/**
	 * Saved by the event's own Save button, on the event's own hook.
	 *
	 * Nothing here has a save of its own: the screen carries one form and one
	 * button, and what is typed in this section travels with everything else.
	 */
	public static function save_copy( string $rule_id, array $rule, array $in ): void {
		if ( ! isset( $in['dze_email'] ) || ! is_array( $in['dze_email'] ) ) {
			return; // the section was not on the screen: nothing to say about it.
		}
		$posted = $in['dze_email'];
		$parts  = [];
		foreach ( array_keys( self::fields() ) as $key ) {
			$value = (string) ( $posted[ $key ] ?? '' );
			$parts[ $key ] = 'cta_url' === $key
				? esc_url_raw( trim( $value ) )
				: trim( sanitize_textarea_field( $value ) );
		}
		$parts['hero']     = esc_url_raw( trim( (string) ( $posted['hero'] ?? '' ) ) );
		$parts['products'] = ! empty( $posted['products'] );

		$one = [
			'subject' => mb_substr( sanitize_text_field( (string) ( $posted['subject'] ?? '' ) ), 0, 150 ),
			'preview' => mb_substr( sanitize_text_field( (string) ( $posted['preview'] ?? '' ) ), 0, 150 ),
			'parts'   => $parts,
		];
		$all = get_option( self::OPT_COPY, [] );
		$all = is_array( $all ) ? $all : [];
		$all[ $rule_id ] = $one;
		update_option( self::OPT_COPY, $all, false );
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
		$inc = (string) self::conf( 'included' );
		if ( '' === $inc ) {
			throw new RuntimeException( __( 'Pick the audience under Settings → Email campaigns first.', 'dazont-ecom' ) );
		}
		$copy    = self::copy_for( $rule_id );
		$name    = trim( (string) ( $in['name'] ?? '' ) ) ?: (string) ( $rule['title'] ?? __( 'Promotion', 'dazont-ecom' ) );
		$subject = trim( (string) ( $in['subject'] ?? '' ) ) ?: $copy['subject'];
		$preview = trim( (string) ( $in['preview'] ?? '' ) ) ?: $copy['preview'];
		$html    = self::layout( self::parts( $rule, $rule_id ) );
		$warning = '';

		// 1. The email itself becomes a template in the account, so the campaign
		//    can be opened, read and edited in Klaviyo like any other.
		$made = self::request( 'POST', 'templates/', [
			'data' => [
				'type'       => 'template',
				'attributes' => [
					'name'        => mb_substr( $name, 0, 120 ),
					'editor_type' => 'CODE',
					'html'        => $html,
				],
			],
		], 40 );
		if ( is_wp_error( $made ) ) {
			throw new RuntimeException( $made->get_error_message() );
		}
		$tpl_id = (string) ( $made['data']['id'] ?? '' );
		if ( '' === $tpl_id ) {
			throw new RuntimeException( __( 'Klaviyo saved nothing back.', 'dazont-ecom' ) );
		}

		// 2. The campaign — the audience answered once, in the settings.
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

		// 3. The email becomes the content of that campaign.
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
			$warning = __( 'The campaign was created but Klaviyo did not name its message, so the email was left unassigned.', 'dazont-ecom' );
		}

		// 4. The one line a machine translator writes worse than the shop does.
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

	/** Drops what was written for an event that is gone. */
	public static function forget( string $rule_id ): void {
		foreach ( [ self::OPT_COPY, self::OPT_MAP ] as $option ) {
			$all = get_option( $option, [] );
			if ( is_array( $all ) && isset( $all[ $rule_id ] ) ) {
				unset( $all[ $rule_id ] );
				update_option( $option, $all, false );
			}
		}
	}

	/**
	 * Switches a segment back on in Klaviyo.
	 *
	 * An inactive segment is not maintained: it holds nobody, so excluding it
	 * excludes nobody. Klaviyo asks that a deactivation travel alone in the
	 * body; a reactivation is the same one attribute the other way round.
	 */
	public static function activate( string $segment_id ): void {
		$res = self::request( 'PATCH', 'segments/' . rawurlencode( $segment_id ) . '/', [
			'data' => [
				'type'       => 'segment',
				'id'         => $segment_id,
				'attributes' => [ 'is_active' => true ],
			],
		], 30 );
		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( $res->get_error_message() );
		}
		delete_transient( self::CACHE );
	}

	/**
	 * The id of the metric an order is recorded under, in this account.
	 *
	 * Never hard-coded: the same shop on another Klaviyo account has another
	 * id, and a segment built on the wrong metric silently matches nobody.
	 */
	private static function order_metric(): string {
		$errors = [];
		$best   = '';
		foreach ( self::pages( 'metrics/?fields[metric]=name,integration', $errors, 12 ) as $row ) {
			$name = strtolower( (string) ( $row['attributes']['name'] ?? '' ) );
			if ( 'placed order' !== $name && 'ordered product' !== $name ) {
				continue;
			}
			$from = strtolower( (string) ( $row['attributes']['integration']['name'] ?? '' ) );
			if ( 'placed order' === $name && false !== strpos( $from, 'woocommerce' ) ) {
				return (string) $row['id']; // the shop's own orders: nothing beats it.
			}
			if ( '' === $best && 'placed order' === $name ) {
				$best = (string) $row['id'];
			}
		}
		if ( '' === $best ) {
			throw new RuntimeException( __( 'No "Placed Order" metric was found in this Klaviyo account, so a buyers segment cannot be built.', 'dazont-ecom' ) );
		}
		return $best;
	}

	/**
	 * Creates the one segment a promotion email really wants: the people who
	 * have just bought.
	 *
	 * Announcing a sale to somebody who paid full price three days ago earns a
	 * refund request, not an order. It is the same definition the shop already
	 * uses — bought at least once in the last N weeks, and reachable by email.
	 *
	 * @return array{id:string,name:string}
	 */
	public static function make_buyers_segment( int $weeks ): array {
		$weeks = max( 1, min( 12, $weeks ) );
		$name  = sprintf(
			/* translators: %d: number of weeks */
			_n( 'Buyers from the last %d week', 'Buyers from the last %d weeks', $weeks, 'dazont-ecom' ),
			$weeks
		);
		$made = self::request( 'POST', 'segments/', [
			'data' => [
				'type'       => 'segment',
				'attributes' => [
					'name'       => $name,
					'definition' => [
						'condition_groups' => [
							[
								'conditions' => [
									[
										'type'               => 'profile-metric',
										'metric_id'          => self::order_metric(),
										'measurement'        => 'count',
										'measurement_filter' => [ 'type' => 'numeric', 'operator' => 'greater-than', 'value' => 0 ],
										'timeframe_filter'   => [ 'type' => 'date', 'operator' => 'in-the-last', 'unit' => 'week', 'quantity' => $weeks ],
									],
								],
							],
							[
								'conditions' => [
									[
										'type'    => 'profile-marketing-consent',
										'consent' => [
											'channel'               => 'email',
											'can_receive_marketing' => true,
											'consent_status'        => [ 'subscription' => 'any' ],
										],
									],
								],
							],
						],
					],
				],
			],
		], 40 );
		if ( is_wp_error( $made ) ) {
			throw new RuntimeException( $made->get_error_message() );
		}
		$id = (string) ( $made['data']['id'] ?? '' );
		if ( '' === $id ) {
			throw new RuntimeException( __( 'Klaviyo created nothing back.', 'dazont-ecom' ) );
		}
		delete_transient( self::CACHE );
		return [ 'id' => $id, 'name' => $name ];
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
			/* translators: %d: number of lists and segments read */
			_n( 'Read %d audience.', 'Read %d audiences.', count( $cat['audiences'] ), 'dazont-ecom' ),
			count( $cat['audiences'] )
		);
		// What the account refused is said, not swallowed: an empty picker
		// with no reason beside it is a bug hunt; "403 — the key has no list
		// access" is a five-second fix.
		if ( ! empty( $cat['errors'] ) ) {
			$message .= ' ' . implode( ' ', array_unique( (array) $cat['errors'] ) );
		}
		wp_send_json_success( [
			'audiences' => $cat['audiences'],
			'inactive'  => $cat['inactive'],
			'partial'   => ! empty( $cat['errors'] ),
			'message'   => $message,
		] );
	}

	/** The whole email for one promotion — subject, preview and every field. */
	public static function ajax_write(): void {
		self::guard();
		$rule_id = isset( $_POST['rule'] ) ? sanitize_key( wp_unslash( $_POST['rule'] ) ) : '';
		$rules   = class_exists( 'DZE_Discounts' ) ? DZE_Discounts::get_rules() : [];
		$rule    = (array) ( $rules[ $rule_id ] ?? [] );
		if ( ! $rule ) {
			wp_send_json_error( [ 'message' => __( 'That event no longer exists.', 'dazont-ecom' ) ] );
		}
		$parts = self::parts( $rule, $rule_id );
		$fmt   = get_option( 'date_format' ) ?: 'Y-m-d';
		$date  = static function ( $ymd ) use ( $fmt ): string {
			$ts = $ymd ? strtotime( (string) $ymd . ' 00:00:00' ) : false;
			return $ts ? (string) wp_date( $fmt, $ts ) : '';
		};
		$pct  = rtrim( rtrim( number_format( (float) ( $rule['percent'] ?? 0 ), 2, '.', '' ), '0' ), '.' );
		$lang = class_exists( 'DZE_Content' ) ? DZE_Content::site_language() : 'English';

		// The link is not the model's to invent, and neither are the figures.
		$ask = [ 'subject', 'preview', 'headline', 'body', 'offer', 'cta_label' ];
		$user = "--- THE PROMOTION ---\n"
			. 'Title: ' . (string) ( $rule['title'] ?? '' ) . "\n"
			. 'Discount: ' . $pct . "%\n"
			. 'Runs: ' . $date( $rule['start'] ?? '' ) . ' → ' . $date( $rule['end'] ?? '' ) . "\n";
		if ( class_exists( 'DZE_Marketing_Ai' ) ) {
			$about = trim( (string) DZE_Marketing_Ai::instance()->shop_context_text() );
			if ( '' !== $about ) {
				$user .= "\n--- THE SHOP ---\n" . mb_substr( $about, 0, 1200 ) . "\n";
			}
		}
		$user .= "\n--- INSTRUCTIONS ---\n" . self::email_prompt() . "\n"
			. "\n--- LANGUAGE ---\nWrite in " . $lang . ".\n"
			. "\n--- THE PIECES TO WRITE ---\n"
			. "subject — the inbox line, 6 to 9 words\n"
			. "preview — the line after it, 4 to 8 words\n"
			. "headline — the big line at the top of the email, one short line\n"
			. "body — two or three short sentences, plain text, line breaks allowed\n"
			. "offer — the discount and the deadline, one line, in a box of its own\n"
			. "cta_label — the button, two or three words in the imperative\n"
			. "\n--- OUTPUT ---\nJSON only: one key per piece named exactly as listed above. No other key, no comment.";

		DZE_Ai_Usage::unit( 'promo_email' );
		try {
			$out = DZE_Marketing_Ai::complete(
				'You write the promotional emails of an online shop. You reply with JSON only.',
				$user,
				'',
				1200,
				90
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
		$written = [];
		foreach ( $ask as $key ) {
			$text = trim( sanitize_textarea_field( (string) ( $json[ $key ] ?? '' ) ) );
			if ( '' !== $text ) {
				$written[ $key ] = mb_substr( $text, 0, 'body' === $key ? 800 : 150 );
			}
		}
		unset( $parts );
		DZE_Ai_Usage::finished( 'promo_email' );
		wp_send_json_success( [
			'subject' => (string) ( $written['subject'] ?? '' ),
			'preview' => (string) ( $written['preview'] ?? '' ),
			'parts'   => array_diff_key( $written, [ 'subject' => 1, 'preview' => 1 ] ),
		] );
	}

	/**
	 * The email as it will actually look — built by the same function that
	 * builds the one Klaviyo receives.
	 *
	 * One layout, one code path: a preview drawn by a second piece of code
	 * would drift from the email the day one of the two is touched.
	 */
	public static function ajax_preview(): void {
		self::guard();
		$rule_id = isset( $_POST['rule'] ) ? sanitize_key( wp_unslash( $_POST['rule'] ) ) : '';
		$rules   = class_exists( 'DZE_Discounts' ) ? DZE_Discounts::get_rules() : [];
		$rule    = (array) ( $rules[ $rule_id ] ?? [] );
		$parts   = self::parts( $rule, $rule_id );

		// What is on screen right now, not what was saved last time.
		if ( isset( $_POST['parts'] ) ) {
			$raw = json_decode( (string) wp_unslash( $_POST['parts'] ), true );
			foreach ( (array) $raw as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( 'products' === $key ) {
					$parts['products'] = ! empty( $value );
				} elseif ( 'cta_url' === $key || 'hero' === $key ) {
					$parts[ $key ] = esc_url_raw( trim( (string) $value ) );
				} elseif ( array_key_exists( $key, $parts ) ) {
					$parts[ $key ] = trim( sanitize_textarea_field( (string) $value ) );
				}
			}
		}
		wp_send_json_success( [ 'html' => self::layout( $parts, true ) ] );
	}

	/** Switches the chosen exclusion back on, without leaving the page. */
	public static function ajax_activate(): void {
		self::guard();
		$id = isset( $_POST['segment'] ) ? sanitize_text_field( wp_unslash( $_POST['segment'] ) ) : '';
		if ( '' === $id ) {
			wp_send_json_error( [ 'message' => __( 'Pick a segment first.', 'dazont-ecom' ) ] );
		}
		try {
			self::activate( $id );
			$cat = self::refresh();
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'audiences' => $cat['audiences'],
			'inactive'  => $cat['inactive'],
			'message'   => __( 'Switched back on in Klaviyo. It fills again within a few minutes.', 'dazont-ecom' ),
		] );
	}

	/** Builds the recent-buyers segment and hands it back, ready to be chosen. */
	public static function ajax_make_segment(): void {
		self::guard();
		$weeks = isset( $_POST['weeks'] ) ? (int) $_POST['weeks'] : 3;
		try {
			$made = self::make_buyers_segment( $weeks );
			$cat  = self::refresh();
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'id'        => $made['id'],
			'audiences' => $cat['audiences'],
			'inactive'  => $cat['inactive'],
			/* translators: %s: the segment's name */
			'message'   => sprintf( __( '"%s" created and chosen. Klaviyo fills it within a few minutes.', 'dazont-ecom' ), $made['name'] ),
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
		// The events screens — the list and one event — carry this once there
		// is something to click.
		$events = class_exists( 'DZE_Discounts' ) && false !== strpos( $hook, DZE_Discounts::MENU_SLUG_EVENTS ) && $this->configured();
		$config = 'email' === $tab && class_exists( 'DZE_Marketing_Ai' ) && false !== strpos( $hook, DZE_Marketing_Ai::MENU_SLUG );
		if ( ! $events && ! $config ) {
			return;
		}
		// The logo and the event image are picked in the media library, so its
		// own script has to be there.
		wp_enqueue_media();
		wp_enqueue_script( 'dze-klaviyo', DZE_URL . 'admin/js/klaviyo.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-klaviyo', 'dzeKlav', [
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE ),
			// The segments Klaviyo is not maintaining, so the settings screen
			// can offer to switch the chosen one back on.
			'inactive' => array_values( (array) ( self::catalogue()['inactive'] ?? [] ) ),
			'i18n'    => [
				'loading'  => __( 'Reading your Klaviyo account…', 'dazont-ecom' ),
				'writing'  => __( 'Writing…', 'dazont-ecom' ),
				'creating' => __( 'Creating the draft in Klaviyo…', 'dazont-ecom' ),
				'made'     => __( 'Draft ready in Klaviyo — nothing was sent.', 'dazont-ecom' ),
				'error'    => __( 'Something went wrong.', 'dazont-ecom' ),
				'subject'  => __( 'Write a subject line first.', 'dazont-ecom' ),
				'open'     => __( 'Open draft ↗', 'dazont-ecom' ),
				'again'    => __( 'Again', 'dazont-ecom' ),
				'written'  => __( 'Written — read it, then save the event.', 'dazont-ecom' ),
				'rendering'=> __( 'Drawing it…', 'dazont-ecom' ),
				'pick'     => __( 'Choose an image', 'dazont-ecom' ),
				'working'  => __( 'Talking to Klaviyo…', 'dazont-ecom' ),
				'thenSave' => __( 'Save the settings below to keep it.', 'dazont-ecom' ),
			],
		] );
	}

	/**
	 * The cell on a marketing event's row: create the draft, or open the one
	 * that already exists.
	 */
	public function render_cell( string $rule_id, array $rule ): void {
		$made = self::draft_for( $rule_id );
		// What was written on the event's own screen is what the popup opens
		// with: the two screens read the same email, never two versions of it.
		$copy  = self::copy_for( $rule_id );
		$parts = self::parts( $rule, $rule_id );
		$made_link = ! empty( $made['campaign'] );
		echo '<div class="dze-klav-cell" data-rule="' . esc_attr( $rule_id ) . '"'
			. ' data-name="' . esc_attr( (string) ( $rule['title'] ?? '' ) ) . '"'
			. ' data-subject="' . esc_attr( $copy['subject'] ?: (string) $parts['headline'] ) . '"'
			. ' data-preview="' . esc_attr( $copy['preview'] ) . '"'
			. ' data-when="' . esc_attr( self::default_datetime( $rule ) ) . '">';
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

	/**
	 * The email of one event, on the event's own screen: write it, look at
	 * it, save it with everything else.
	 *
	 * Named fields, not markers: the header, the footer and the frame belong
	 * to the plugin and never change from one promotion to the next, so what
	 * is asked here is only what a promotion actually says.
	 */
	public function render_editor( string $rule_id, array $rule ): void {
		if ( '' === $rule_id ) {
			// Nothing to write about yet: the email is written from the event's
			// own figures, and this one has none until it is saved once.
			echo '<h3>' . esc_html__( 'The email that announces it', 'dazont-ecom' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Save the event first — the email is written from its title, its discount and its dates.', 'dazont-ecom' ) . '</p>';
			return;
		}
		$parts = self::parts( $rule, $rule_id );
		$copy  = self::copy_for( $rule_id );
		?>
		<h3><?php esc_html_e( 'The email that announces it', 'dazont-ecom' ); ?></h3>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Written here, saved with the event, and sent to Klaviyo as a draft campaign when you press "Draft email" on the events list. The logo, the colours and the footer are the shop\'s, set once under Settings → Email campaigns.', 'dazont-ecom' ); ?>
		</p>
		<div id="dze-klav-editor" data-rule="<?php echo esc_attr( $rule_id ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dze-klav-e-subject"><?php esc_html_e( 'Subject', 'dazont-ecom' ); ?></label></th>
					<td><input type="text" id="dze-klav-e-subject" name="dze_email[subject]" class="large-text" value="<?php echo esc_attr( $copy['subject'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-klav-e-preview"><?php esc_html_e( 'Preview text', 'dazont-ecom' ); ?></label></th>
					<td>
						<input type="text" id="dze-klav-e-preview" name="dze_email[preview]" class="large-text" value="<?php echo esc_attr( $copy['preview'] ); ?>" />
						<p class="description"><?php esc_html_e( 'The line the inbox shows after the subject.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<?php foreach ( self::fields() as $key => $field ) : ?>
					<tr>
						<th scope="row"><label for="dze-klav-f-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
						<td>
							<?php if ( 'textarea' === $field['type'] ) : ?>
								<textarea id="dze-klav-f-<?php echo esc_attr( $key ); ?>" name="dze_email[<?php echo esc_attr( $key ); ?>]" class="large-text dze-klav-f" data-part="<?php echo esc_attr( $key ); ?>" rows="4"><?php echo esc_textarea( (string) $parts[ $key ] ); ?></textarea>
							<?php else : ?>
								<input type="<?php echo 'url' === $field['type'] ? 'url' : 'text'; ?>" id="dze-klav-f-<?php echo esc_attr( $key ); ?>" name="dze_email[<?php echo esc_attr( $key ); ?>]" class="large-text dze-klav-f" data-part="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $parts[ $key ] ); ?>" />
							<?php endif; ?>
							<p class="description"><?php echo esc_html( $field['help'] ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Image', 'dazont-ecom' ); ?></th>
					<td class="dze-klav-hero">
						<input type="hidden" id="dze-klav-f-hero" name="dze_email[hero]" class="dze-klav-f" data-part="hero" value="<?php echo esc_attr( (string) $parts['hero'] ); ?>" />
						<img class="dze-klav-hero-img" src="<?php echo esc_url( (string) $parts['hero'] ); ?>" alt="" style="<?php echo $parts['hero'] ? '' : 'display:none;'; ?>max-width:220px;height:auto;border:1px solid #dcdcde;border-radius:4px;vertical-align:middle;margin-right:8px;" />
						<button type="button" class="button dze-klav-hero-pick"><?php esc_html_e( 'Choose an image', 'dazont-ecom' ); ?></button>
						<button type="button" class="button-link dze-klav-hero-clear" style="<?php echo $parts['hero'] ? '' : 'display:none;'; ?>margin-left:6px;"><?php esc_html_e( 'Remove', 'dazont-ecom' ); ?></button>
						<p class="description"><?php esc_html_e( 'Optional, full width under the logo. Media library only, so the address keeps working.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Products', 'dazont-ecom' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="dze_email[products]" class="dze-klav-f" data-part="products" value="1" <?php checked( ! empty( $parts['products'] ) ); ?> />
							<?php esc_html_e( 'Show three of the shop\'s best-sellers under the button', 'dazont-ecom' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Read from WooCommerce when the draft is made, at the price the promotion gives them.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button" id="dze-klav-e-write">&#9998; <?php esc_html_e( 'Write it with Claude', 'dazont-ecom' ); ?></button>
				<?php if ( class_exists( 'DZE_Prompts' ) ) { DZE_Prompts::the_button( 'promo_email' ); } ?>
				<button type="button" class="button" id="dze-klav-e-preview-btn"><?php esc_html_e( 'Preview the email', 'dazont-ecom' ); ?></button>
				<span id="dze-klav-e-msg" style="margin-left:8px;font-size:13px;"></span>
			</p>
			<div id="dze-klav-e-frame" style="display:none;margin-top:12px;">
				<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
					<strong><?php esc_html_e( 'The email as it will be sent', 'dazont-ecom' ); ?></strong>
					<span class="description" id="dze-klav-e-subject-echo"></span>
					<button type="button" class="button-link" id="dze-klav-e-close" style="margin-left:auto;"><?php esc_html_e( 'Close', 'dazont-ecom' ); ?></button>
				</div>
				<iframe id="dze-klav-e-iframe" title="<?php esc_attr_e( 'Email preview', 'dazont-ecom' ); ?>" sandbox="" style="width:100%;height:720px;border:1px solid #dcdcde;border-radius:6px;background:#fff;"></iframe>
			</div>
		</div>
		<?php
	}

	/** The one popup, printed once at the bottom of the events screen. */
	public function render_panel(): void {
		?>
		<div class="dze-klav-modal" id="dze-klav-modal" style="display:none;">
			<div class="dze-klav-modal__inner">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Announce this promotion by email', 'dazont-ecom' ); ?></h2>
				<p class="description" style="max-width:640px;">
					<?php esc_html_e( 'The email written on the event\'s own screen goes to Klaviyo as a draft campaign, for the audience chosen in the settings. It is never sent and never scheduled from here — you read it in Klaviyo and decide.', 'dazont-ecom' ); ?>
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
		$inc     = (string) self::conf( 'included' );
		$exc     = (string) self::conf( 'excluded' );
		?>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Turns a marketing event into a draft campaign in Klaviyo. The email itself is built here — written on the event, framed by the design below — and addressed to the audience you choose. Nothing is ever sent from WordPress.', 'dazont-ecom' ); ?>
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

			<h2 class="title"><?php esc_html_e( 'Who it goes to', 'dazont-ecom' ); ?></h2>
		<p>
			<button type="button" class="button" id="dze-klav-refresh" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Read them from Klaviyo', 'dazont-ecom' ); ?></button>
			<span id="dze-klav-refresh-msg" style="margin-left:8px;font-size:13px;">
				<?php
				if ( empty( $cat['audiences'] ) ) {
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
					<p class="description" style="max-width:820px;margin-bottom:8px;">
						<strong><?php esc_html_e( 'Recommended on every promotion:', 'dazont-ecom' ); ?></strong>
						<?php esc_html_e( 'the people who have just bought. Announcing a sale to somebody who paid full price three days ago earns a refund request, not an order.', 'dazont-ecom' ); ?>
					</p>
					<p class="dze-klav-seg-tools" style="margin:0 0 6px;">
						<button type="button" class="button" id="dze-klav-activate" style="<?php echo in_array( $exc, (array) ( $cat['inactive'] ?? [] ), true ) ? '' : 'display:none;'; ?>">
							&#9889; <?php esc_html_e( 'Switch it back on in Klaviyo', 'dazont-ecom' ); ?>
						</button>
						<span style="margin-left:10px;">
							<?php esc_html_e( 'or build one:', 'dazont-ecom' ); ?>
							<label>
								<?php esc_html_e( 'buyers of the last', 'dazont-ecom' ); ?>
								<input type="number" id="dze-klav-weeks" value="3" min="1" max="12" class="small-text" />
								<?php esc_html_e( 'weeks', 'dazont-ecom' ); ?>
							</label>
							<button type="button" class="button" id="dze-klav-make-seg" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Create it', 'dazont-ecom' ); ?></button>
						</span>
						<span id="dze-klav-seg-msg" style="margin-left:8px;font-size:13px;"></span>
					</p>
					<p class="description" style="max-width:820px;">
						<?php esc_html_e( 'Segments marked inactive are listed too, because Klaviyo hides them from its own listing and your campaigns may well use one. An inactive segment is not maintained: excluding it excludes nobody until it is switched back on — which the button above does, in Klaviyo, without leaving this page.', 'dazont-ecom' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'What every promotion email looks like', 'dazont-ecom' ); ?></h2>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'The frame around the writing: the mark at the top, the colours, the line at the bottom. Set once — the promotions only change what is inside it.', 'dazont-ecom' ); ?>
		</p>
		<?php $design = self::design(); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Logo', 'dazont-ecom' ); ?></th>
				<td class="dze-klav-logo">
					<input type="hidden" id="dze-klav-logo" name="<?php echo esc_attr( self::OPT . '[logo]' ); ?>" value="<?php echo esc_attr( $design['logo'] ); ?>" />
					<img class="dze-klav-logo-img" src="<?php echo esc_url( $design['logo'] ); ?>" alt="" style="<?php echo $design['logo'] ? '' : 'display:none;'; ?>max-width:150px;height:auto;vertical-align:middle;margin-right:10px;" />
					<button type="button" class="button dze-klav-logo-pick"><?php esc_html_e( 'Choose an image', 'dazont-ecom' ); ?></button>
					<button type="button" class="button-link dze-klav-logo-clear" style="<?php echo $design['logo'] ? '' : 'display:none;'; ?>margin-left:6px;"><?php esc_html_e( 'Remove', 'dazont-ecom' ); ?></button>
					<p class="description"><?php esc_html_e( 'Shown 150px wide at the top. Left empty, the shop\'s name is written instead.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Colours', 'dazont-ecom' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Button and frame', 'dazont-ecom' ); ?> <input type="color" name="<?php echo esc_attr( self::OPT . '[accent]' ); ?>" value="<?php echo esc_attr( $design['accent'] ); ?>" /></label>
					&nbsp;
					<label><?php esc_html_e( 'Text', 'dazont-ecom' ); ?> <input type="color" name="<?php echo esc_attr( self::OPT . '[ink]' ); ?>" value="<?php echo esc_attr( $design['ink'] ); ?>" /></label>
					&nbsp;
					<label><?php esc_html_e( 'Footer band', 'dazont-ecom' ); ?> <input type="color" name="<?php echo esc_attr( self::OPT . '[paper]' ); ?>" value="<?php echo esc_attr( $design['paper'] ); ?>" /></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-klav-note"><?php esc_html_e( 'Footer heading', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="text" id="dze-klav-note" name="<?php echo esc_attr( self::OPT . '[note]' ); ?>" value="<?php echo esc_attr( $design['note'] ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'e.g. We got you covered', 'dazont-ecom' ); ?>" />
					<p class="description"><?php esc_html_e( 'The line above the three promises. Empty removes it.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'The three promises', 'dazont-ecom' ); ?></th>
				<td>
					<?php foreach ( $design['reassure'] as $dze_i => $dze_one ) : ?>
						<div class="dze-klav-re" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
							<input type="hidden" class="dze-klav-re-url" name="<?php echo esc_attr( self::OPT . '[reassure][' . $dze_i . '][icon]' ); ?>" value="<?php echo esc_attr( $dze_one['icon'] ); ?>" />
							<img class="dze-klav-re-img" src="<?php echo esc_url( $dze_one['icon'] ); ?>" alt="" style="<?php echo $dze_one['icon'] ? '' : 'display:none;'; ?>width:34px;height:34px;object-fit:contain;" />
							<button type="button" class="button dze-klav-re-pick"><?php esc_html_e( 'Icon', 'dazont-ecom' ); ?></button>
							<input type="text" name="<?php echo esc_attr( self::OPT . '[reassure][' . $dze_i . '][title]' ); ?>" value="<?php echo esc_attr( $dze_one['title'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Worldwide Delivery', 'dazont-ecom' ); ?>" />
							<input type="text" name="<?php echo esc_attr( self::OPT . '[reassure][' . $dze_i . '][line]' ); ?>" value="<?php echo esc_attr( $dze_one['line'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( '- Anywhere in the world -', 'dazont-ecom' ); ?>" />
						</div>
					<?php endforeach; ?>
					<p class="description" style="max-width:820px;">
						<?php esc_html_e( 'The band at the bottom of every promotion email, as your own campaigns already carry it. Leave a title empty to drop that column. Below it, the shop name, the address and the unsubscribe link are added by law, not by choice.', 'dazont-ecom' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'How the email is written', 'dazont-ecom' ); ?></h2>
			<textarea id="dze-klav-prompt" name="<?php echo esc_attr( self::OPT . '[email_prompt]' ); ?>" rows="8" class="large-text code" style="max-width:880px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;"><?php echo esc_textarea( self::email_prompt() ); ?></textarea>
			<p>
				<button type="button" class="button-link" id="dze-klav-prompt-reset">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
				<?php if ( class_exists( 'DZE_Prompt_Defaults' ) ) { DZE_Prompt_Defaults::control( 'promo_email', '#dze-klav-prompt' ); } ?>
			</p>
			<script>
			(function () {
				var shipped = <?php echo wp_json_encode( self::default_email_prompt() ); ?>;
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
