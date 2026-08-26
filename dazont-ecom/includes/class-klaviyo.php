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
	// Where the drafts used to be recorded, one per event. Each email now keeps
	// its own draft beside itself; the row stays declared so a shop that has
	// one can still erase it.
	public const OPT_MAP  = 'dze_klaviyo_drafts';
	public const OPT_COPY = 'dze_klaviyo_copy';   // rule id => the email written for it.

	private const API   = 'https://a.klaviyo.com/api/';
	// The API revision Klaviyo answers under. It is a DATE, and Klaviyo retires
	// old ones: pinned at 2025-07-15 this plugin started getting "revision date
	// requested is before the earliest available" — a 404 that looks like a
	// missing endpoint and is really an expired pin. It is the single thing in
	// this file that goes stale on its own, which is why the weekly checkup
	// names it by hand when Klaviyo refuses.
	private const REV   = '2026-07-15';      // stable API revision.
	private const REV_B = '2026-07-15.pre';  // the localisation endpoints are beta.
	private const NONCE = 'dze_klaviyo';
	private const CACHE = 'dze_klaviyo_cat'; // the account's audiences, as last read.

	/**
	 * Where the email's own content goes inside the stored frame.
	 *
	 * Written by hand, once, into an imported Klaviyo template: it is the one
	 * gesture that turns somebody else's HTML into this shop's shell, and it
	 * beats any attempt of ours to guess where a drag-and-drop export stops
	 * being a header and starts being content.
	 */
	public const BODY_MARK = '{{ BODY }}';

	/**
	 * Where the email's own photograph goes while it is still being made.
	 *
	 * The writing describes the picture and places it in one go, but the
	 * photograph itself takes a minute to come back from fal.ai. So the body
	 * comes out carrying this in the src, and the real URL replaces it as soon
	 * as there is one. Nobody ever sees it: it is gone before the email is.
	 */
	public const PICTURE_MARK = 'dze:picture';

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
		add_action( 'wp_ajax_dze_klav_activate', [ __CLASS__, 'ajax_activate' ] );
		add_action( 'wp_ajax_dze_klav_segment',  [ __CLASS__, 'ajax_make_segment' ] );
		add_action( 'wp_ajax_dze_klav_image',    [ __CLASS__, 'ajax_image' ] );
		add_action( 'wp_ajax_dze_klav_test',     [ __CLASS__, 'ajax_test' ] );
		add_action( 'wp_ajax_dze_klav_frame',    [ __CLASS__, 'ajax_frame' ] );
		add_action( 'wp_ajax_dze_klav_hours',    [ __CLASS__, 'ajax_hours' ] );
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
		foreach ( self::setup_items() as $item ) {
			if ( empty( $item['done'] ) ) {
				return (string) $item['label'];
			}
		}
		return '';
	}

	/**
	 * What this module needs before it can do anything, as a checklist.
	 *
	 * Shown on the screen where somebody is trying to use it, not left to be
	 * discovered: a setting nobody knows about is a function nobody has.
	 *
	 * @return array<int,array{label:string,url:string,done:bool,note:string}>
	 */
	public static function setup_items(): array {
		$tab = class_exists( 'DZE_Marketing_Ai' )
			? add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG, 'tab' => 'email' ], admin_url( 'admin.php' ) )
			: '';
		return [
			[
				'label' => __( 'Add your Klaviyo API key', 'dazont-ecom' ),
				'url'   => $tab . '#dze-klav-key',
				'done'  => '' !== self::key(),
				'note'  => __( 'Klaviyo → Settings → API keys, with campaigns, templates and lists enabled.', 'dazont-ecom' ),
			],
			[
				'label' => __( 'Choose who the emails go to', 'dazont-ecom' ),
				'url'   => $tab . '#dze-klav-inc',
				'done'  => '' !== (string) self::conf( 'included' ),
				'note'  => __( 'Normally all your contacts, minus your recent buyers.', 'dazont-ecom' ),
			],
			[
				'label' => __( 'Leave out your recent buyers', 'dazont-ecom' ),
				'url'   => $tab . '#dze-klav-exc',
				'done'  => '' !== (string) self::conf( 'excluded' ),
				'note'  => __( 'Recommended, not required: a sale announced to somebody who paid full price three days ago earns a refund request.', 'dazont-ecom' ),
			],
		];
	}

	/** True when the two things a draft cannot be made without are answered. */
	public static function ready(): bool {
		return '' !== self::key() && '' !== (string) self::conf( 'included' );
	}

	/**
	 * The shop's own type and colour, read from the theme.
	 *
	 * An email that does not look like the site it comes from is an email the
	 * reader does not recognise. Nothing is asked for here: a block theme's
	 * global styles answer first, Astra's own settings next — Astra is what
	 * this shop runs — and a plain, safe pair of stacks last.
	 *
	 * @return array{head:string,body:string,ink:string,link:string,size:int}
	 */
	public static function theme_style(): array {
		$out = [
			'head' => "'Aldrich', Helvetica, Arial, sans-serif",
			'body' => 'Helvetica, Arial, sans-serif',
			'ink'  => '#111111',
			'link' => '#719D1A',
			'size' => 16,
		];
		$hex = static function ( $v ): string {
			$v = is_string( $v ) ? trim( $v ) : '';
			return preg_match( '/^#[0-9a-fA-F]{6}$/', $v ) ? $v : '';
		};
		$stack = static function ( $v ): string {
			$v = is_string( $v ) ? trim( wp_strip_all_tags( $v ) ) : '';
			// A CSS variable is meaningless in an email: only a real stack is.
			return ( '' !== $v && false === strpos( $v, 'var(' ) ) ? $v : '';
		};

		// A block theme says it in theme.json, and WordPress hands it over.
		if ( function_exists( 'wp_get_global_styles' ) ) {
			$g = wp_get_global_styles();
			$c = $hex( $g['color']['text'] ?? '' );
			if ( '' !== $c ) {
				$out['ink'] = $c;
			}
			$f = $stack( $g['typography']['fontFamily'] ?? '' );
			if ( '' !== $f ) {
				$out['body'] = $f;
				$out['head'] = $f;
			}
			$h = $stack( $g['blocks']['core/heading']['typography']['fontFamily'] ?? '' );
			if ( '' !== $h ) {
				$out['head'] = $h;
			}
			$l = $hex( $g['elements']['link']['color']['text'] ?? '' );
			if ( '' !== $l ) {
				$out['link'] = $l;
			}
		}

		// Astra keeps its own, and this shop runs Astra.
		$astra = get_option( 'astra-settings', [] );
		if ( is_array( $astra ) && $astra ) {
			$c = $hex( $astra['text-color'] ?? '' );
			if ( '' !== $c ) {
				$out['ink'] = $c;
			}
			$l = $hex( $astra['link-color'] ?? ( $astra['theme-color'] ?? '' ) );
			if ( '' !== $l ) {
				$out['link'] = $l;
			}
			$fb = $stack( $astra['body-font-family'] ?? '' );
			if ( '' !== $fb ) {
				$out['body'] = $fb;
			}
			$fh = $stack( $astra['headings-font-family'] ?? '' );
			if ( '' !== $fh ) {
				$out['head'] = $fh;
			}
			$size = $astra['font-size-body']['desktop'] ?? ( $astra['font-size-body'] ?? 0 );
			if ( (int) $size >= 12 && (int) $size <= 22 ) {
				$out['size'] = (int) $size;
			}
		}
		return $out;
	}

	/** The shop's mark, as WordPress holds it. */
	private static function logo_url(): string {
		$id = (int) get_theme_mod( 'custom_logo' );
		if ( $id ) {
			$url = wp_get_attachment_image_url( $id, 'medium' );
			if ( $url ) {
				return (string) $url;
			}
		}
		return '';
	}

	public function register_settings(): void {
		register_setting( 'dze_klaviyo_options', self::OPT, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize' ],
			'default'           => [],
			// The frame is a whole email's worth of HTML. No page of the shop
			// reads any of this, and an autoloaded row is read on EVERY request.
			'autoload'          => false,
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
		// The frame is not a setting any more: the header, the footer and the
		// type come from the shop itself. What was stored for them goes.
		unset( $out['logo'], $out['accent'], $out['ink'], $out['paper'], $out['note'], $out['reassure'] );
		// The picture is described by the writing itself now, so the prompt
		// that used to describe it separately is gone, and what was stored for
		// it goes rather than sitting in the database meaning nothing.
		unset( $out['image_prompt'] );
		if ( array_key_exists( 'email_prompt', $in ) ) {
			// Same treatment as every other prompt of the plugin: shipped text
			// saved as it stands means "no custom prompt".
			$text = trim( sanitize_textarea_field( (string) $in['email_prompt'] ) );
			$out['email_prompt'] = ( $text === trim( self::default_email_prompt() ) ) ? '' : $text;
			unset( $out['subject_prompt'] );
		}
		if ( array_key_exists( 'days', $in ) ) {
			$out['days'] = max( 1, min( 365, (int) $in['days'] ) );
		}
		if ( array_key_exists( 'test_to', $in ) ) {
			$to = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) $in['test_to'] ) ) ) );
			$out['test_to'] = implode( ', ', array_slice( $to, 0, 5 ) );
		}
		// The frame. A frame with no place to put the email in it is not a
		// frame: rather than saving something that would come out as a header
		// with nothing under it, the marker is checked and the shop keeps what
		// it had — said out loud, on the screen that asked.
		if ( array_key_exists( 'shell', $in ) ) {
			$shell = trim( (string) $in['shell'] );
			// Typed by hand, so it is met halfway: {{BODY}}, {{ body }} and
			// {{  BODY  }} all mean the same thing and are all made canonical
			// here rather than refused for a missing space.
			$shell = (string) preg_replace( '/\{\{\s*BODY\s*\}\}/i', self::BODY_MARK, $shell );
			if ( '' === $shell || $shell === trim( self::default_shell() ) ) {
				$out['shell'] = '';
			} elseif ( false === strpos( $shell, self::BODY_MARK ) ) {
				add_settings_error(
					self::OPT,
					'dze_klav_shell',
					sprintf(
						/* translators: %s: the body marker */
						__( 'The frame was not saved: it has to carry %s once, on the line where the email\'s own content goes.', 'dazont-ecom' ),
						self::BODY_MARK
					)
				);
			} else {
				$out['shell'] = $shell;
			}
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
		return "Write the email that announces this promotion — the words AND the layout.\n"
			. "\n"
			. "SUBJECT: it decides whether the email is opened. Say the offer, not the season. Six to nine words, no more — past that a phone cuts it off. Figures are welcome, and they are the ones given.\n"
			. "PREVIEW TEXT: it continues the subject, it does not repeat it — the second half of the sentence read in the inbox. Four to eight words.\n"
			. "\n"
			. "BODY: you decide everything about it. What comes first, how many paragraphs, how many products in a row and how many rows, what gets a heading and what does not, where the buttons go. An email of four parts and an email of fourteen are both right if the promotion deserves it.\n"
			. "\n"
			. "What makes a good one:\n"
			. "- It OPENS on the picture, with the title reading with it, not fighting it.\n"
			. "- It says what the offer is, what it covers and when it ends — early, plainly, once.\n"
			. "- It SHOWS. A promotion described in three paragraphs is a promotion nobody pictures: the products are the argument. Give them room, and come back to them.\n"
			. "- It breathes: a short line, something to look at, another short line. Never a wall of prose followed by a grid.\n"
			. "- Somewhere to click before the fold, and again at the end.\n"
			. "- Group products under a small heading only when the groups are really different from one another. Two rows of the same kind of thing under two labels is worse than one row.\n"
			. "- One idea per paragraph. Two or three short sentences at a time. Buttons are two or three words in the imperative.\n"
			. "- No ALL CAPS, no stacked exclamation marks, no \"Don't miss out\", no emoji unless the promotion is a holiday one.\n"
			. "- Never promise anything the promotion does not say: no free shipping, no gift, no discount code, no extra offer.\n"
			. "\n"
			. "THE HTML, because an inbox is not a browser:\n"
			. "- Return the body only: no <html>, <head>, <body>, no logo, no footer, no unsubscribe line. The shop's header and footer are added around what you write, and the whole thing already sits in a 600px column with 28px of padding — so your widest element is 544px.\n"
			. "- Tables for anything side by side (<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">), never floats, never flexbox, never a class of your own, never a <style> block. Inline styles only.\n"
			. "- Every row you build must be full. Three products in a row of three, two in a row of two — never a row of three holding one product and two holes.\n"
			. "- Images: width and a max-width in the style, height:auto, display:block, border:0.\n"
			. "- Use the shop's fonts and colours as given above, and nothing else.\n"
			. "\n"
			. "THE PICTURE: the email opens on one photograph, and you describe it. Write that description in the \"picture\" field — one paragraph, addressed to a photographer: the shop's own product in the setting the promotion evokes, real light, real ground. NO TEXT of any kind in the image, no title, no price, no badge, no logo: the title is written over it in the email, where five languages can read it. Nothing that dates it. Then, in the body, place it with src=\"dze:picture\" and the plugin puts the real photograph there.\n"
			. "\n"
			. "THE FACTS ARE NOT YOURS: use only the products listed, with the name, the link, the image URL and the prices exactly as they are written. Show the old price struck through beside the new one — that is the whole point of a sale. Never invent a product, a price, a photograph or a link, and never show a product the list does not contain.";
	}

	/**
	 * How far back the product rows look.
	 *
	 * It was 14 days written into the code, which is exactly the kind of
	 * decision that looks like a hidden prompt from the outside. It is one
	 * number, and it belongs on the settings screen.
	 */
	public static function window_days(): int {
		return max( 1, min( 365, (int) self::conf( 'days', 14 ) ) );
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
		$doing = $method . ' ' . ltrim( $path, '/' );
		$resp  = wp_remote_request( self::API . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $resp ) ) {
			DZE_Health::log( 'klaviyo', $doing, $resp->get_error_message() );
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
		DZE_Health::log( 'klaviyo', $doing, 'HTTP ' . $code . ( '' !== $detail ? ' — ' . $detail : '' ) );
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
		$c = is_array( $c ) ? $c : [];
		return $c + [ 'audiences' => [], 'inactive' => [], 'templates' => [], 'read' => 0 ];
	}

	/** Reads the lists and segments the shop can address. */
	public static function refresh(): array {
		$out    = [ 'audiences' => [], 'inactive' => [], 'templates' => [], 'read' => time() ];
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
		// The templates too: the header and the footer are chosen among them,
		// and one button that reads the account should read all of it.
		$out['templates'] = [];
		foreach ( self::pages( 'templates/?fields[template]=name&sort=-updated', $errors, 4 ) as $row ) {
			$out['templates'][ (string) $row['id'] ] = (string) ( $row['attributes']['name'] ?? $row['id'] );
		}
		if ( ! $out['audiences'] && $errors ) {
			throw new RuntimeException( implode( ' ', array_unique( $errors ) ) );
		}
		set_transient( self::CACHE, $out, 12 * HOUR_IN_SECONDS );
		$out['errors'] = $errors;
		return $out;
	}

	/**
	 * The head of every promotion email, as this plugin draws it.
	 *
	 * It is the SHIPPED head: what the shell falls back to before the owner
	 * imports his own. Fixed from one campaign to the next either way — a
	 * header that changes is a shop the reader stops recognising.
	 */
	public static function header_html(): string {
		$t    = self::theme_style();
		$name = get_bloginfo( 'name' );
		$logo = self::logo_url();
		$mark = $logo
			? sprintf(
				'<a href="%1$s"><img src="%2$s" width="80" alt="%3$s" style="display:block;margin:0 auto;width:80px;max-width:80px;height:auto;border:0;" /></a>',
				esc_url( home_url( '/' ) ),
				esc_url( $logo ),
				esc_attr( $name )
			)
			: sprintf( '<div style="font:400 22px %1$s;color:%2$s;">%3$s</div>', $t['head'], esc_attr( $t['ink'] ), esc_html( $name ) );
		return '<tr><td align="center" style="padding:20px 15px 14px;">' . $mark . '</td></tr>';
	}

	/**
	 * The fixed foot: the three promises the shop's emails carry, then the
	 * lines the law wants.
	 */
	public static function footer_html(): string {
		$t    = self::theme_style();
		$cdn  = 'https://d3k81ch9hvuctc.cloudfront.net/company/UNysVD/images/';
		$rows = [
			[ $cdn . '74d7e758-2982-4642-b10b-45a492e0408f.png', __( 'Worldwide Delivery', 'dazont-ecom' ), __( '- Anywhere in the world -', 'dazont-ecom' ) ],
			[ $cdn . '1f414ab2-f6df-4d81-8dca-8ed7fd15dacf.png', __( '5/7 Customer Support', 'dazont-ecom' ), __( '- Ready to help you -', 'dazont-ecom' ) ],
			[ $cdn . 'f8320b08-de46-4345-ae02-704d0811b651.png', __( 'Secure Payments', 'dazont-ecom' ), __( '- Website fully protected -', 'dazont-ecom' ) ],
		];
		$cells = '';
		foreach ( $rows as $one ) {
			$cells .= sprintf(
				'<td width="33%%" valign="top" align="center" style="padding:10px 18px 20px;">'
				. '<img src="%1$s" width="50" alt="" style="display:block;margin:0 auto 8px;width:50px;height:auto;border:0;" />'
				. '<div style="font:700 14px %2$s;color:%3$s;">%4$s</div>'
				. '<div style="font:400 14px %2$s;color:%3$s;">%5$s</div></td>',
				esc_url( $one[0] ),
				$t['body'],
				esc_attr( $t['ink'] ),
				esc_html( $one[1] ),
				esc_html( $one[2] )
			);
		}

		return sprintf( '<tr><td style="background:#F4F4EE;padding:18px 0 0;">'
				. '<div style="text-align:center;padding:0 18px 6px;font:400 20px %1$s;color:%2$s;">%3$s</div>'
				. '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0"><tr>%4$s</tr></table>'
				. '</td></tr>',
				$t['head'],
				esc_attr( $t['ink'] ),
				esc_html__( 'We got you covered', 'dazont-ecom' ),
				$cells
			)
			. sprintf(
				'<tr><td align="center" style="background:#F4F4EE;padding:15px 18px 20px;font:400 11px/1.6 %1$s;color:#222222;">'
				. '<div>%2$s <span style="color:#1457d0;">{%% unsubscribe %%}</span>.</div>'
				. '<div>{{ organization.name }} {{ organization.full_address }}</div></td></tr>',
				$t['body'],
				esc_html__( 'No longer want to receive these emails?', 'dazont-ecom' )
			);
	}

	// =========================================================================
	// The shell — the header and the footer, as ONE stored piece of HTML with
	// a marker where the email's own content goes.
	//
	// They were built by the two functions above and nothing else, which meant
	// the owner could only have the header this plugin happened to draw. Now
	// they are a setting: shipped as those functions draw them, importable
	// from a template that already exists in the Klaviyo account — so the
	// frame becomes the shop's real one, to the pixel, rather than a
	// reconstruction of ours — and editable by hand afterwards.
	// =========================================================================

	/** The frame as this plugin draws it when nothing else was chosen. */
	public static function default_shell(): string {
		$t    = self::theme_style();
		$name = get_bloginfo( 'name' );
		return '<!DOCTYPE html><html><head><meta charset="utf-8" />'
			. '<meta name="viewport" content="width=device-width,initial-scale=1" />'
			. '<title>' . esc_html( $name ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#F4F4EE;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F4EE;"><tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;">'
			. self::header_html()
			. sprintf(
				'<tr><td style="padding:8px 28px 22px;font:400 %1$dpx/1.6 %2$s;color:%3$s;">%4$s</td></tr>',
				(int) $t['size'],
				$t['body'],
				esc_attr( $t['ink'] ),
				self::BODY_MARK
			)
			. self::footer_html()
			. '</table></td></tr></table></body></html>';
	}

	/** The frame in force: the owner's own when he saved one, ours otherwise. */
	public static function shell(): string {
		$saved = trim( (string) ( self::settings()['shell'] ?? '' ) );
		return ( '' !== $saved && false !== strpos( $saved, self::BODY_MARK ) )
			? $saved
			: self::default_shell();
	}

	/**
	 * A plain row of products — the fallback body of an event nobody has
	 * written yet, and what fills the template preview.
	 *
	 * The email the shop actually sends is laid out by the model, not here.
	 * This exists so a screen is never empty, which is a different job.
	 */
	public static function products_html( array $rule = [], int $limit = 3 ): string {
		$t     = self::theme_style();
		$limit = max( 1, min( 4, $limit ) );
		$ids   = self::best_sellers( self::window_days(), $limit, array_map( 'absint', (array) ( $rule['category_ids'] ?? [] ) ) );
		if ( ! $ids || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$products = [];
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof WC_Product ) {
				$products[] = $product;
			}
		}
		if ( ! $products ) {
			return '';
		}
		// The width follows the count. A row of three holding one product and
		// two empty cells is what "lignes sans produits" looked like, and it
		// was the row's fault, not the shop's.
		$width = (int) floor( 100 / count( $products ) );
		$cells = '';
		foreach ( $products as $product ) {
			$img    = wp_get_attachment_image_url( (int) $product->get_image_id(), 'medium' );
			$cells .= sprintf(
				'<td width="%10$d%%" valign="top" align="center" style="padding:10px 6px;">'
				. '<a href="%1$s" style="text-decoration:none;color:%6$s;">'
				. '<img src="%2$s" width="170" alt="%3$s" style="display:block;width:100%%;max-width:170px;height:auto;border:0;" />'
				. '<div style="padding:10px 2px 2px;font:400 14px/1.35 %5$s;">%3$s</div>'
				. '<div style="padding-bottom:8px;font:400 14px %7$s;">%4$s</div></a>'
				. '<a href="%1$s" style="display:inline-block;background:%8$s;color:#ffffff;text-decoration:none;padding:10px 15px;font:400 14px %7$s;">%9$s</a>'
				. '</td>',
				esc_url( (string) $product->get_permalink() ),
				esc_url( (string) ( $img ?: wc_placeholder_img_src( 'medium' ) ) ),
				esc_html( $product->get_name() ),
				self::price_html( $product, $rule ),
				$t['head'],
				esc_attr( $t['ink'] ),
				$t['body'],
				esc_attr( $t['link'] ),
				esc_html__( 'Shop now', 'dazont-ecom' ),
				$width
			);
		}
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . $cells . '</tr></table>';
	}

	/**
	 * The price as the promotion makes it: what it was, struck through, and
	 * what it becomes.
	 *
	 * The email is read before the shop is opened, so it is here that the
	 * saving has to be visible — a single price says nothing about a sale.
	 * The new price is computed the way the shop computes it, charm endings
	 * included, so the figure in the inbox is the figure on the page.
	 */
	private static function price_html( WC_Product $product, array $rule ): string {
		$pct = (float) ( $rule['percent'] ?? 0 );
		$reg   = $product->is_type( 'variable' )
			? (float) $product->get_variation_regular_price( 'min', true )
			: (float) $product->get_regular_price();
		if ( $pct <= 0 || $reg <= 0 || ! function_exists( 'wc_price' ) ) {
			// No promotion on this row: the shop's own price, as it prints it.
			return esc_html( wp_strip_all_tags( (string) $product->get_price_html() ) );
		}
		// Exactly the line the Discounts module prices with — same rounding,
		// same direction. Any other arithmetic here would put a figure in the
		// inbox that the product page then contradicts.
		$now = self::sale_price( $reg, $pct );
		return sprintf(
			'<span style="color:#8a8a8a;text-decoration:line-through;">%1$s</span> <span style="color:#b32d2e;font-weight:700;">%2$s</span>',
			wp_kses_post( wc_price( $reg ) ),
			wp_kses_post( wc_price( $now ) )
		);
	}

	/** A category named by a block, as a term id. Accepts an id, a slug or a name. */
	private static function category_id( $category ): int {
		$category = is_scalar( $category ) ? trim( (string) $category ) : '';
		if ( '' === $category ) {
			return 0;
		}
		if ( ctype_digit( $category ) ) {
			return (int) $category;
		}
		foreach ( [ 'slug', 'name' ] as $by ) {
			$term = get_term_by( $by, $category, 'product_cat' );
			if ( $term instanceof WP_Term ) {
				return (int) $term->term_id;
			}
		}
		return 0;
	}

	/**
	 * The categories the model may name in a products block: the event's own
	 * first, then the shop's biggest. It picks from a real list or names
	 * nothing — it never invents a section the catalogue cannot fill.
	 *
	 * @return string[]
	 */
	public static function category_names( array $rule, int $limit = 12 ): array {
		$out = [];
		foreach ( array_map( 'absint', (array) ( $rule['category_ids'] ?? [] ) ) as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( $term instanceof WP_Term ) {
				$out[ $term->slug ] = $term->name;
			}
		}
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => $limit,
		] );
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			if ( $term instanceof WP_Term ) {
				$out[ $term->slug ] = $term->name;
			}
		}
		return array_slice( $out, 0, $limit, true );
	}

	/**
	 * The products the shop actually sold over a window, best first.
	 *
	 * @param int[] $categories Restrict to these product categories, when the event names any.
	 *
	 * @return int[]
	 */
	private static function best_sellers( int $days, int $limit, array $categories = [] ): array {
		global $wpdb;
		$limit = max( 1, min( 9, $limit ) );
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		$ids   = [];
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$since = current_datetime()->modify( '-' . max( 1, $days ) . ' days' )->format( 'Y-m-d H:i:s' );
			$rows  = $wpdb->get_col( $wpdb->prepare(
				"SELECT product_id FROM {$table} WHERE date_created >= %s GROUP BY product_id ORDER BY SUM(product_qty) DESC LIMIT 60", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce's own table name.
				$since
			) );
			$ids = array_map( 'absint', (array) $rows );
		}
		// Nothing sold in the window (a quiet fortnight, or Analytics not
		// synced): the catalogue's own popularity answers rather than nothing.
		if ( ! $ids && function_exists( 'wc_get_products' ) ) {
			$ids = (array) wc_get_products( [
				'limit'      => 60,
				'status'     => 'publish',
				'orderby'    => 'popularity',
				'order'      => 'DESC',
				'visibility' => 'catalog',
				'return'     => 'ids',
			] );
		}
		if ( $categories && $ids ) {
			$in = array_values( array_filter( $ids ) );
			$ok = [];
			foreach ( $in as $id ) {
				if ( has_term( $categories, 'product_cat', $id ) ) {
					$ok[] = $id;
				}
			}
			if ( $ok ) {
				$ids = $ok;
			}
		}
		$out = [];
		foreach ( $ids as $id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( $product instanceof WC_Product && $product->is_visible() ) {
				$out[] = (int) $id;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The whole email: the stored frame, and inside it the body written for
	 * this promotion.
	 *
	 * @param bool $preview True to read Klaviyo's own tags as a person would.
	 */
	public static function layout( string $body, bool $preview = false ): string {
		$html = str_replace( self::BODY_MARK, $body, self::shell() );
		return $preview ? self::readable( $html ) : $html;
	}

	/**
	 * The email as a person would see it, not as Klaviyo receives it.
	 *
	 * On screen, Klaviyo's tags have nobody to fill them in. Rather than
	 * showing raw {% … %}, the ones that mean something here are answered and
	 * the rest are cleared — the frame then looks like what lands in an inbox,
	 * whoever wrote it.
	 */
	public static function readable( string $html ): string {
		$html = str_replace(
			[ '{% unsubscribe %}', '{{ organization.name }}', '{{ organization.full_address }}' ],
			[
				esc_html__( 'Unsubscribe', 'dazont-ecom' ),
				esc_html( get_bloginfo( 'name' ) ),
				esc_html( (string) get_option( 'woocommerce_store_address', '' ) ),
			],
			$html
		);
		$html = (string) preg_replace( '/\{%.*?%\}/s', '', $html );
		return (string) preg_replace( '/\{\{.*?\}\}/s', '', $html );
	}

	/**
	 * The frame, readable, with the marker still in it.
	 *
	 * Handed to the browser so both previews redraw as you type instead of
	 * asking the server for a picture of what is already on screen. The frame
	 * still comes from ONE place — this function — so the preview cannot drift
	 * from the email that is sent.
	 */
	public static function preview_shell(): string {
		$keep = '@@DZE_BODY@@';
		return str_replace( $keep, self::BODY_MARK, self::readable( str_replace( self::BODY_MARK, $keep, self::shell() ) ) );
	}

	// =========================================================================
	// The emails of a promotion
	//
	// A promotion is not one email. It is a warm-up before it opens, the
	// announcement on the day, a reminder while it runs and a last call before
	// it closes — and which of those a promotion deserves is a decision, not a
	// setting. So an event holds a LIST of emails, each with its own moment,
	// its own subject and its own body, and each drafted into Klaviyo on its
	// own. What was written before this existed becomes the launch email of
	// its promotion, so nothing written is ever lost to the change.
	// =========================================================================

	/** The moments a promotion can be announced at, in the order they happen. */
	public static function kinds(): array {
		return [
			'warm'     => [
				'label' => __( 'Warm-up', 'dazont-ecom' ),
				'when'  => __( 'Before it opens', 'dazont-ecom' ),
				'days'  => -2,
			],
			'launch'   => [
				'label' => __( 'Launch', 'dazont-ecom' ),
				'when'  => __( 'The day it opens', 'dazont-ecom' ),
				'days'  => 0,
			],
			'reminder' => [
				'label' => __( 'Reminder', 'dazont-ecom' ),
				'when'  => __( 'While it runs', 'dazont-ecom' ),
				'days'  => 'mid', // halfway through the promotion.
			],
			'last'     => [
				'label' => __( 'Last chance', 'dazont-ecom' ),
				'when'  => __( 'Before it closes', 'dazont-ecom' ),
				'days'  => 'end',
			],
		];
	}

	/** The day an email of this kind goes out, from the promotion's own window. */
	public static function default_when( string $kind, array $rule ): string {
		$start = strtotime( (string) ( $rule['start'] ?? '' ) . ' 09:00:00' );
		$end   = strtotime( (string) ( $rule['end'] ?? '' ) . ' 09:00:00' );
		// Read with a default of 0, never with ?? on a value that can legitimately
		// be something other than a number: a sentinel that coalesces away is a
		// sentinel that silently becomes "the day it opens".
		$days  = self::kinds()[ $kind ]['days'] ?? 0;
		if ( ! $start ) {
			$start = time() + DAY_IN_SECONDS;
		}
		if ( 'end' === $days ) {
			$ts = $end ?: $start;
		} elseif ( 'mid' === $days ) {
			$ts = ( $end && $end > $start ) ? (int) ( ( $start + $end ) / 2 ) : $start;
		} else {
			$ts = $start + ( (int) $days * DAY_IN_SECONDS );
		}
		// A date already gone is not a send date.
		if ( $ts < time() + HOUR_IN_SECONDS ) {
			$ts = time() + DAY_IN_SECONDS;
		}
		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * A send moment is a DAY.
	 *
	 * The hour belongs to Klaviyo: it works out, person by person, when that
	 * reader opens his mail. Offering an hour here was offering a choice that
	 * never reached the campaign — and a field whose consequence is invisible
	 * is worse than no field at all. Anything stored when this asked for an
	 * hour is read back as its day.
	 */
	public static function just_day( string $when ): string {
		return preg_match( '/^(\d{4}-\d{2}-\d{2})/', trim( $when ), $m ) ? $m[1] : '';
	}

	/**
	 * Every email of a promotion, in the order they go out.
	 *
	 * @return array<string,array> keyed by email id.
	 */
	public static function emails_for( string $rule_id, array $rule = [] ): array {
		$all  = get_option( self::OPT_COPY, [] );
		$all  = is_array( $all ) ? $all : [];
		$one  = (array) ( $all[ $rule_id ] ?? [] );
		$list = (array) ( $one['emails'] ?? [] );

		// Written before a promotion could hold several: it is the launch one.
		if ( ! $list && ( '' !== trim( (string) ( $one['body'] ?? '' ) ) || '' !== trim( (string) ( $one['subject'] ?? '' ) ) ) ) {
			$list = [ 'launch' => [
				'kind'    => 'launch',
				'when'    => self::default_when( 'launch', $rule ),
				'subject' => (string) ( $one['subject'] ?? '' ),
				'preview' => (string) ( $one['preview'] ?? '' ),
				'body'    => (string) ( $one['body'] ?? '' ),
				'picture' => (string) ( $one['picture'] ?? '' ),
			] ];
		}

		$out   = [];
		$kinds = self::kinds();
		foreach ( $list as $id => $email ) {
			if ( ! is_array( $email ) ) {
				continue;
			}
			$kind = isset( $kinds[ $email['kind'] ?? '' ] ) ? (string) $email['kind'] : 'launch';
			$out[ (string) $id ] = [
				'kind'    => $kind,
				'when'    => self::just_day( (string) ( $email['when'] ?? '' ) ) ?: self::default_when( $kind, $rule ),
				'subject' => (string) ( $email['subject'] ?? '' ),
				'preview' => (string) ( $email['preview'] ?? '' ),
				'body'    => (string) ( $email['body'] ?? '' ),
				'picture' => (string) ( $email['picture'] ?? '' ),
				'draft'   => (array) ( $email['draft'] ?? [] ),
			];
		}
		uasort( $out, static fn( array $a, array $b ): int => strcmp( $a['when'], $b['when'] ) );
		return $out;
	}

	/** One email of a promotion, empty when it does not exist. */
	public static function email_for( string $rule_id, string $email_id, array $rule = [] ): array {
		return (array) ( self::emails_for( $rule_id, $rule )[ $email_id ] ?? [] );
	}

	/** Writes one email back, keeping everything it did not carry. */
	public static function put_email( string $rule_id, string $email_id, array $fields ): void {
		$all = get_option( self::OPT_COPY, [] );
		$all = is_array( $all ) ? $all : [];
		$one = (array) ( $all[ $rule_id ] ?? [] );
		// The migration has to be materialised before a single email is
		// touched, or writing to "launch" would create a second one beside the
		// legacy copy and the screen would show the same email twice.
		$one['emails'] = self::emails_for( $rule_id );
		unset( $one['subject'], $one['preview'], $one['body'], $one['picture'] );
		$one['emails'][ $email_id ] = array_merge( (array) ( $one['emails'][ $email_id ] ?? [] ), $fields );
		$all[ $rule_id ]            = $one;
		update_option( self::OPT_COPY, $all, false );
	}

	/**
	 * Saved by the event's own Save button, on the event's own hook.
	 *
	 * Every email of the promotion travels in one form. A field is written only
	 * if the form carried it, and an email already written is never replaced by
	 * an empty one.
	 */
	public static function save_copy( string $rule_id, array $rule, array $in ): void {
		if ( ! isset( $in['dze_email'] ) || ! is_array( $in['dze_email'] ) ) {
			return; // the section was not on the screen: nothing to say about it.
		}
		$kinds = self::kinds();
		$live  = self::emails_for( $rule_id, $rule );
		$out   = [];
		foreach ( (array) $in['dze_email'] as $email_id => $posted ) {
			$email_id = sanitize_key( (string) $email_id );
			if ( '' === $email_id || ! is_array( $posted ) ) {
				continue;
			}
			// A moment the promotion does not use is not an empty email: it is
			// not one at all, and it leaves nothing behind.
			if ( empty( $posted['exists'] ) ) {
				continue;
			}
			$was  = (array) ( $live[ $email_id ] ?? [] );
			$kind = isset( $kinds[ $posted['kind'] ?? '' ] ) ? (string) $posted['kind'] : (string) ( $was['kind'] ?? 'launch' );
			$body = array_key_exists( 'body', $posted ) ? self::clean_html( (string) $posted['body'] ) : (string) ( $was['body'] ?? '' );
			if ( '' === trim( $body ) ) {
				$body = (string) ( $was['body'] ?? '' );
			}
			$out[ $email_id ] = [
				'kind'    => $kind,
				'when'    => array_key_exists( 'when', $posted )
					? self::just_day( sanitize_text_field( (string) $posted['when'] ) )
					: (string) ( $was['when'] ?? self::default_when( $kind, $rule ) ),
				'subject' => array_key_exists( 'subject', $posted )
					? mb_substr( sanitize_text_field( (string) $posted['subject'] ), 0, 150 )
					: (string) ( $was['subject'] ?? '' ),
				'preview' => array_key_exists( 'preview', $posted )
					? mb_substr( sanitize_text_field( (string) $posted['preview'] ), 0, 150 )
					: (string) ( $was['preview'] ?? '' ),
				'body'    => $body,
				'picture' => array_key_exists( 'picture', $posted )
					? esc_url_raw( (string) $posted['picture'] )
					: (string) ( $was['picture'] ?? '' ),
				'draft'   => (array) ( $was['draft'] ?? [] ),
			];
		}
		$all = get_option( self::OPT_COPY, [] );
		$all = is_array( $all ) ? $all : [];
		$all[ $rule_id ] = [ 'emails' => $out ];
		update_option( self::OPT_COPY, $all, false );
	}

	/** Remembers the picture made for one email. */
	public static function keep_picture( string $rule_id, string $email_id, string $url ): void {
		self::put_email( $rule_id, $email_id, [ 'picture' => esc_url_raw( $url ) ] );
	}

	/** How many emails a promotion carries, and how many are already in Klaviyo. */
	public static function counts( string $rule_id, array $rule = [] ): array {
		$emails = self::emails_for( $rule_id, $rule );
		$drafts = 0;
		foreach ( $emails as $email ) {
			if ( ! empty( $email['draft']['campaign'] ) ) {
				$drafts++;
			}
		}
		return [ 'emails' => count( $emails ), 'drafts' => $drafts ];
	}

	/**
	 * The HTML an email may carry.
	 *
	 * Email HTML is table-and-inline-style HTML: WordPress's own post rules
	 * allow the tags but not the attributes those tables live on, so they are
	 * added here. Scripts, iframes and forms are not on the list.
	 */
	public static function clean_html( string $html ): string {
		$attrs = [
			'style' => true, 'class' => true, 'id' => true, 'align' => true, 'valign' => true,
			'width' => true, 'height' => true, 'border' => true, 'cellpadding' => true,
			'cellspacing' => true, 'bgcolor' => true, 'colspan' => true, 'rowspan' => true, 'role' => true,
		];
		$allowed = [
			'table' => $attrs, 'thead' => $attrs, 'tbody' => $attrs, 'tr' => $attrs, 'td' => $attrs, 'th' => $attrs,
			'div' => $attrs, 'p' => $attrs, 'span' => $attrs, 'strong' => $attrs, 'em' => $attrs, 'b' => $attrs,
			'i' => $attrs, 'u' => $attrs, 'br' => $attrs, 'hr' => $attrs, 'center' => $attrs,
			'h1' => $attrs, 'h2' => $attrs, 'h3' => $attrs, 'h4' => $attrs,
			'ul' => $attrs, 'ol' => $attrs, 'li' => $attrs, 'blockquote' => $attrs,
			'a'   => $attrs + [ 'href' => true, 'target' => true, 'rel' => true, 'title' => true ],
			'img' => $attrs + [ 'src' => true, 'alt' => true, 'title' => true ],
		];
		return trim( wp_kses( $html, $allowed ) );
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
	 * When the draft is set to go out.
	 *
	 * The DAY is the shop's decision; the hour is Klaviyo's. It works out, for
	 * each person on the list, the moment that person actually opens his mail
	 * — from what he has done before, not from an average — which is the same
	 * idea as the shop's own "off-peak beats on-peak" rule, done properly and
	 * per reader.
	 */
	private static function strategy( array $in, array $rule ): array {
		$day = self::just_day( (string) ( $in['datetime'] ?? '' ) );
		if ( '' === $day ) {
			$day = self::default_when( 'launch', $rule );
		}
		return [ 'method' => 'smart_send_time', 'date' => $day ];
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
	public static function draft( string $rule_id, string $email_id, array $in = [] ): array {
		$rules = class_exists( 'DZE_Discounts' ) ? DZE_Discounts::get_rules() : [];
		$rule  = (array) ( $rules[ $rule_id ] ?? [] );
		if ( ! $rule ) {
			throw new RuntimeException( __( 'That event no longer exists.', 'dazont-ecom' ) );
		}
		$inc = (string) self::conf( 'included' );
		if ( '' === $inc ) {
			throw new RuntimeException( __( 'Pick the audience under Settings → Email campaigns first.', 'dazont-ecom' ) );
		}
		$copy = self::email_for( $rule_id, $email_id, $rule );
		if ( ! $copy ) {
			throw new RuntimeException( __( 'That email no longer exists.', 'dazont-ecom' ) );
		}
		$subject = trim( (string) ( $copy['subject'] ?? '' ) );
		if ( '' === $subject ) {
			throw new RuntimeException( __( 'Write the email first: a campaign with no subject is not one.', 'dazont-ecom' ) );
		}
		$preview = (string) ( $copy['preview'] ?? '' );
		$kinds   = self::kinds();
		$kind    = (string) ( $copy['kind'] ?? 'launch' );
		// The campaign carries the promotion's name AND which of its emails
		// this is, so the account's campaign list can be read at a glance.
		$name    = trim( (string) ( $rule['title'] ?? __( 'Promotion', 'dazont-ecom' ) ) )
			. ' — ' . (string) ( $kinds[ $kind ]['label'] ?? $kind );
		// What is on screen wins over what was last saved: the draft is made of
		// the email the owner is looking at.
		$body    = ( null !== ( $in['body'] ?? null ) && '' !== trim( (string) $in['body'] ) )
			? (string) $in['body']
			: self::body_for( $rule, $rule_id, $email_id );
		$html    = self::layout( $body );
		$in      = $in + [ 'datetime' => (string) ( $copy['when'] ?? '' ) ];
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
					'send_strategy' => self::strategy( $in, $rule ),
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
		if ( is_wp_error( $camp ) && 'smart_send_time' === ( $body['data']['attributes']['send_strategy']['method'] ?? '' ) ) {
			// Smart Send Time needs history Klaviyo may decide this account does
			// not have yet. A draft that does not exist is a worse answer than a
			// draft carrying a plain hour, so it is made the other way and the
			// refusal is reported instead of thrown.
			$warning = $camp->get_error_message();
			// Nine in the morning, in each reader's own time zone: the plain
			// answer for an account Klaviyo will not work the hour out for yet.
			$body['data']['attributes']['send_strategy'] = [
				'method'   => 'static',
				'datetime' => self::strategy( $in, $rule )['date'] . 'T09:00:00',
				'options'  => [ 'is_local' => true, 'send_past_recipients_immediately' => true ],
			];
			$camp = self::request( 'POST', 'campaigns/', $body, 30 );
		}
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
				$warning = trim( $warning . ' ' . $assign->get_error_message() );
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

		self::put_email( $rule_id, $email_id, [
			'draft' => [
				'campaign' => $camp_id,
				'message'  => $msg_id,
				'template' => $tpl_id,
				'name'     => $name,
				'at'       => time(),
			],
		] );

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

	// =========================================================================
	// When this shop's readers actually open
	//
	// Klaviyo already picks the hour reader by reader — that is Smart Send
	// Time, and it is what a draft goes out with. This is the other question,
	// the one a person asks before choosing a fixed hour: what does MY list do?
	// Read from the account's own "Opened Email" events, hour by hour, and
	// answered as a shape rather than a number.
	// =========================================================================

	private const HOURS_CACHE = 'dze_klaviyo_hours';
	private const HOURS_DAYS  = 28; // four whole weeks: four samples of each weekday.

	/**
	 * Opens per day of the week, Monday first, over the recent past.
	 *
	 * It used to answer by hour. The hour stopped being a question the moment
	 * Klaviyo was left to work it out per reader — and an answer to a question
	 * nobody asks is clutter. The DAY is what this screen chooses, so the day
	 * is what it reports.
	 *
	 * @return array{days:int[],peak:int,total:int,window:int,read:int}
	 * @throws RuntimeException
	 */
	public static function open_hours( bool $fresh = false ): array {
		$cached = get_transient( self::HOURS_CACHE );
		if ( ! $fresh && is_array( $cached ) ) {
			return $cached;
		}
		$metric = self::opens_metric();
		$tz     = self::timezone();
		$until  = current_datetime()->setTime( 0, 0 );
		$from   = $until->modify( '-' . self::HOURS_DAYS . ' days' );

		$res = self::request( 'POST', 'metric-aggregates/', [
			'data' => [
				'type'       => 'metric-aggregate',
				'attributes' => [
					'metric_id'    => $metric,
					'measurements' => [ 'count' ],
					'interval'     => 'day',
					'timezone'     => $tz,
					'page_size'    => 500,
					'filter'       => [
						'greater-or-equal(datetime,' . $from->format( 'Y-m-d\TH:i:s' ) . ')',
						'less-than(datetime,' . $until->format( 'Y-m-d\TH:i:s' ) . ')',
					],
				],
			],
		], 40 );
		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( $res->get_error_message() );
		}
		$dates  = (array) ( $res['data']['attributes']['dates'] ?? [] );
		$counts = (array) ( $res['data']['attributes']['data'][0]['measurements']['count'] ?? [] );
		if ( ! $dates || ! $counts ) {
			throw new RuntimeException( __( 'Klaviyo returned no opens for the last few weeks — there is nothing to read a best hour from yet.', 'dazont-ecom' ) );
		}
		// Monday first, as a European week is read.
		$week  = array_fill( 0, 7, 0 );
		$total = 0;
		foreach ( $dates as $i => $stamp ) {
			$day = strtotime( substr( (string) $stamp, 0, 10 ) );
			if ( ! $day ) {
				continue;
			}
			$slot          = ( (int) gmdate( 'N', $day ) ) - 1; // 1..7 → 0..6
			$n             = (int) ( $counts[ $i ] ?? 0 );
			$week[ $slot ] += $n;
			$total         += $n;
		}
		if ( $total < 1 ) {
			throw new RuntimeException( __( 'No opens in the last four weeks — nothing to read a best day from yet.', 'dazont-ecom' ) );
		}
		$out = [
			'days'   => $week,
			'peak'   => (int) array_search( max( $week ), $week, true ),
			'total'  => $total,
			'window' => self::HOURS_DAYS,
			'read'   => time(),
		];
		set_transient( self::HOURS_CACHE, $out, 7 * DAY_IN_SECONDS );
		return $out;
	}

	/** The account's own email-open metric. */
	private static function opens_metric(): string {
		$errors = [];
		foreach ( self::pages( 'metrics/?fields[metric]=name', $errors, 12 ) as $row ) {
			if ( 'opened email' === strtolower( (string) ( $row['attributes']['name'] ?? '' ) ) ) {
				return (string) $row['id'];
			}
		}
		throw new RuntimeException( __( 'No "Opened Email" metric was found in this Klaviyo account.', 'dazont-ecom' ) );
	}

	/**
	 * The shop's timezone, as Klaviyo will accept it.
	 *
	 * WordPress happily answers "+02:00" when the site is set by offset rather
	 * than by city; Klaviyo validates against the IANA list and refuses that,
	 * so anything that is not Region/City falls back to UTC.
	 */
	private static function timezone(): string {
		$tz = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '';
		return preg_match( '#^[A-Za-z]+/[A-Za-z_+\-/]+$#', $tz ) ? $tz : 'UTC';
	}

	public static function ajax_hours(): void {
		self::guard();
		try {
			$out = self::open_hours( ! empty( $_POST['fresh'] ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		// Named with WordPress's own weekday names, so the answer arrives in the
		// language the admin is being read in.
		global $wp_locale;
		$names = [];
		for ( $i = 0; $i < 7; $i++ ) {
			$names[] = $wp_locale instanceof WP_Locale
				? $wp_locale->get_weekday( ( $i + 1 ) % 7 )
				: gmdate( 'l', strtotime( 'Monday +' . $i . ' days' ) );
		}
		wp_send_json_success( $out + [
			'names'   => $names,
			'message' => sprintf(
				/* translators: 1: the weekday, 2: number of days read */
				__( 'Your readers open most on %1$s — from the last %2$d days of opens on this account.', 'dazont-ecom' ),
				$names[ $out['peak'] ] ?? '',
				(int) $out['window']
			),
		] );
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
			/* translators: 1: number of lists and segments, 2: number of templates */
			__( 'Read %1$d audiences and %2$d templates.', 'dazont-ecom' ),
			count( $cat['audiences'] ),
			count( (array) ( $cat['templates'] ?? [] ) )
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
			// The two template menus are built when the page is drawn, so
			// without this the button filled the audiences and left them
			// empty until somebody thought to reload.
			'templates' => (array) ( $cat['templates'] ?? [] ),
			'partial'   => ! empty( $cat['errors'] ),
			'message'   => $message,
		] );
	}

	/** The body the shop falls back on when nothing has been written yet. */
	public static function body_for( array $rule, string $rule_id, string $email_id = '' ): string {
		$saved = self::email_for( $rule_id, $email_id, $rule );
		if ( '' !== trim( (string) ( $saved['body'] ?? '' ) ) ) {
			return (string) $saved['body'];
		}
		$title = trim( (string) ( $rule['banner_text'] ?? $rule['title'] ?? '' ) );
		$t     = self::theme_style();
		return sprintf(
			'<h1 style="text-align:center;font-family:%1$s;">%2$s</h1>',
			$t['head'],
			esc_html( $title )
		) . self::products_html( $rule, 3 );
	}

	/**
	 * The material the email is written FROM: the shop's real products, priced
	 * as this promotion prices them.
	 *
	 * Handed to the model as plain lines it can copy verbatim — name, link,
	 * photograph, the two prices. It lays them out however the prompt tells it
	 * to; what it may never do is invent one, and it does not have to, because
	 * everything it needs is written out here.
	 *
	 * @return array{lines:string,images:string[],prices:string[]}
	 */
	public static function material( array $rule, int $limit = 9 ): array {
		$out = [ 'lines' => '', 'images' => [], 'prices' => [] ];
		$ids = self::best_sellers( self::window_days(), $limit, array_map( 'absint', (array) ( $rule['category_ids'] ?? [] ) ) );
		if ( ! $ids || ! function_exists( 'wc_get_product' ) ) {
			return $out;
		}
		$n = 0;
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$img = (string) ( wp_get_attachment_image_url( (int) $product->get_image_id(), 'medium' )
				?: ( function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'medium' ) : '' ) );
			$was = '';
			$now = '';
			$pct = (float) ( $rule['percent'] ?? 0 );
			$reg = $product->is_type( 'variable' )
				? (float) $product->get_variation_regular_price( 'min', true )
				: (float) $product->get_regular_price();
			if ( $pct > 0 && $reg > 0 && function_exists( 'wc_price' ) ) {
				$was = wp_strip_all_tags( wc_price( $reg ) );
				$now = wp_strip_all_tags( wc_price( self::sale_price( $reg, $pct ) ) );
			} else {
				$now = wp_strip_all_tags( (string) $product->get_price_html() );
			}
			$terms = get_the_terms( $id, 'product_cat' );
			$cat   = ( is_array( $terms ) && isset( $terms[0] ) ) ? $terms[0]->name : '';

			$n++;
			$out['lines'] .= $n . '. ' . $product->get_name() . "\n"
				. '   link: ' . $product->get_permalink() . "\n"
				. '   image: ' . $img . "\n"
				. ( '' !== $was ? '   was: ' . $was . '   now: ' . $now . "\n" : '   price: ' . $now . "\n" )
				. ( '' !== $cat ? '   category: ' . $cat . "\n" : '' );
			if ( '' !== $img ) {
				$out['images'][] = $img;
			}
			foreach ( [ $was, $now ] as $p ) {
				if ( '' !== $p ) {
					$out['prices'][] = $p;
				}
			}
		}
		return $out;
	}

	/** The price this promotion makes, the way the shop itself computes it. */
	public static function sale_price( float $regular, float $percent ): float {
		return class_exists( 'DZE_Price' )
			? DZE_Price::charm( $regular * ( 1 - $percent / 100 ), 'down' )
			: round( $regular * ( 1 - $percent / 100 ), 2 );
	}

	/**
	 * The whole email for one promotion — subject, preview line and body.
	 *
	 * The model writes the HTML itself, and the prompt above it is the only
	 * thing that decides what that HTML looks like. There is no shape of ours
	 * left in the middle: the day the owner wants four products to a row, or
	 * no products at all, or the picture at the bottom, he writes it in the
	 * prompt and it happens — the same way the product texts and the product
	 * images are steered.
	 *
	 * What is NOT left to it: the products, their photographs, their links and
	 * their prices. Those are handed over as facts and checked on the way back.
	 *
	 * Nothing here needs a screen, so the day promotions are automated the same
	 * function writes the same email.
	 *
	 * @return array{subject:string,preview:string,body:string,warning:string}
	 * @throws RuntimeException When the model answers with nothing usable.
	 */
	public static function write_for( string $rule_id, array $rule, string $email_id = '' ): array {
		$fmt  = get_option( 'date_format' ) ?: 'Y-m-d';
		$date = static function ( $ymd ) use ( $fmt ): string {
			$ts = $ymd ? strtotime( (string) $ymd . ' 00:00:00' ) : false;
			return $ts ? (string) wp_date( $fmt, $ts ) : '';
		};
		$pct  = rtrim( rtrim( number_format( (float) ( $rule['percent'] ?? 0 ), 2, '.', '' ), '0' ), '.' );
		$lang = class_exists( 'DZE_Content' ) ? DZE_Content::site_language() : 'English';
		$t    = self::theme_style();
		$days = 0;
		$s_ts = strtotime( (string) ( $rule['start'] ?? '' ) );
		$e_ts = strtotime( (string) ( $rule['end'] ?? '' ) );
		if ( $s_ts && $e_ts ) {
			$days = max( 1, (int) round( ( $e_ts - $s_ts ) / DAY_IN_SECONDS ) + 1 );
		}
		$picture = self::picture_for( $rule_id, $rule, $email_id );
		$moment  = self::email_for( $rule_id, $email_id, $rule );
		$mat     = self::material( $rule );

		$user = "--- THE PROMOTION ---\n"
			. 'Title: ' . (string) ( $rule['title'] ?? '' ) . "\n"
			. 'Discount: ' . $pct . "%\n"
			. 'Runs: ' . $date( $rule['start'] ?? '' ) . ' → ' . $date( $rule['end'] ?? '' )
			. ( $days ? ' (' . $days . ' days)' : '' ) . "\n"
			. 'It covers ' . ( ! empty( $rule['category_ids'] ) ? 'the categories listed with the products below' : 'the whole shop' ) . ".\n"
			. 'Shop address: ' . home_url( '/' ) . "\n";
		$kinds = self::kinds();
		$kind  = (string) ( $moment['kind'] ?? 'launch' );
		if ( isset( $kinds[ $kind ] ) ) {
			$user .= "\n--- WHICH EMAIL THIS IS ---\n"
				. $kinds[ $kind ]['label'] . ' — ' . $kinds[ $kind ]['when'] . ".\n"
				. self::kind_brief( $kind ) . "\n";
		}
		if ( class_exists( 'DZE_Marketing_Ai' ) ) {
			$about = trim( (string) DZE_Marketing_Ai::instance()->shop_context_text() );
			if ( '' !== $about ) {
				$user .= "\n--- THE SHOP ---\n" . mb_substr( $about, 0, 1200 ) . "\n";
			}
		}
		$user .= "\n--- THE PICTURE ---\n"
			. ( '' !== $picture
				? 'This email already has its picture. Use this URL exactly as it stands, and leave the "picture" field empty: ' . $picture . "\n"
				: "This email has no picture yet: describe the one it should open with in the \"picture\" field, and place it in the body with src=\"" . self::PICTURE_MARK . "\".\n" );
		$user .= "\n--- THE PRODUCTS YOU MAY SHOW ---\n"
			. ( '' !== $mat['lines']
				? "Use only these, with the name, the link, the image URL and the prices exactly as written. Show as many or as few as the email needs.\n\n" . $mat['lines']
				: "The shop returned no product. Write the email without a product.\n" );
		$user .= "\n--- THE SHOP'S OWN TYPE AND COLOUR ---\n"
			. 'Headings font-family: ' . $t['head'] . "\n"
			. 'Body font-family: ' . $t['body'] . "\n"
			. 'Text colour: ' . $t['ink'] . "\n"
			. 'Button and link colour: ' . $t['link'] . "\n"
			. 'Body text size: ' . (int) $t['size'] . "px\n";
		$user .= "\n--- INSTRUCTIONS ---\n" . self::email_prompt() . "\n"
			. "\n--- LANGUAGE ---\nWrite in " . $lang . ".\n"
			. "\n--- OUTPUT ---\nJSON only: {\"subject\":\"…\",\"preview\":\"…\",\"picture\":\"…\",\"body\":\"…\"}, where body is the HTML. No other key, no comment, no markdown fence.";

		DZE_Ai_Usage::unit( 'promo_email' );
		try {
			$out = DZE_Marketing_Ai::complete(
				'You write and lay out the promotional emails of an online shop. You answer with JSON only, and the body is email-ready HTML.',
				$user,
				'',
				4000,
				150
			);
		} finally {
			DZE_Ai_Usage::unit();
		}
		$json = json_decode( trim( (string) preg_replace( '/^```(?:json)?|```$/m', '', (string) $out ) ), true );
		$body = is_array( $json ) ? (string) ( $json['body'] ?? '' ) : '';
		if ( '' === trim( $body ) ) {
			throw new RuntimeException( __( 'Nothing usable came back — try again.', 'dazont-ecom' ) );
		}
		[ $body, $warning ] = self::vouch( $body, $mat, $picture );

		DZE_Ai_Usage::finished( 'promo_email' );
		return [
			'subject' => mb_substr( sanitize_text_field( (string) ( $json['subject'] ?? '' ) ), 0, 150 ),
			'preview' => mb_substr( sanitize_text_field( (string) ( $json['preview'] ?? '' ) ), 0, 150 ),
			'body'    => self::clean_html( $body ),
			'warning' => $warning,
			// What the picture should show, in the writing's own words. The
			// browser asks for it next, as a call of its own — one long request
			// that a host cuts off in the middle is not an email.
			'picture' => ( '' === $picture && false !== strpos( $body, self::PICTURE_MARK ) )
				? mb_substr( sanitize_textarea_field( (string) ( $json['picture'] ?? '' ) ), 0, 1200 )
				: '',
		];
	}

	/**
	 * Checks what came back against what was handed over.
	 *
	 * Freedom over the layout is not freedom over the facts. A photograph that
	 * is not one of the shop's is removed outright — an email must never
	 * hotlink something nobody chose. A price that is not one of the prices
	 * given is left in place but SAID, because silently correcting a figure
	 * would hide the fact that the model made one up.
	 *
	 * @return array{0:string,1:string} the body, and what to tell the owner.
	 */
	private static function vouch( string $body, array $mat, string $picture ): array {
		$allowed = array_values( array_unique( array_merge(
			$mat['images'],
			array_filter( [ $picture, self::logo_url(), self::PICTURE_MARK ] )
		) ) );
		$notes   = [];

		$stripped = 0;
		$body = (string) preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( $m ) use ( $allowed, &$stripped ): string {
				if ( preg_match( '/\\bsrc\\s*=\\s*["\\\']([^"\\\']+)["\\\']/i', $m[0], $src ) ) {
					$url = html_entity_decode( $src[1], ENT_QUOTES );
					foreach ( $allowed as $ok ) {
						// Compared without the size suffix: the model is given a
						// "medium" URL and may ask for the same file plainly.
						if ( $url === $ok || self::same_file( $url, $ok ) ) {
							return $m[0];
						}
					}
				}
				$stripped++;
				return '';
			},
			$body
		);
		if ( $stripped > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of images */
				_n( '%d picture that is not the shop\'s was removed.', '%d pictures that are not the shop\'s were removed.', $stripped, 'dazont-ecom' ),
				$stripped
			);
		}

		// Prices: every amount printed must be one that was handed over.
		if ( $mat['prices'] ) {
			$seen = [];
			if ( preg_match_all( '/[\\p{Sc}][\\s]?[0-9][0-9.,\\s]*/u', wp_strip_all_tags( $body ), $found ) ) {
				foreach ( $found[0] as $one ) {
					$one = trim( $one );
					if ( '' !== $one && ! in_array( $one, $mat['prices'], true ) ) {
						$seen[ $one ] = $one;
					}
				}
			}
			if ( $seen ) {
				$notes[] = sprintf(
					/* translators: %s: the amounts */
					__( 'These amounts are not the shop\'s: %s. Read them before sending.', 'dazont-ecom' ),
					implode( ', ', array_slice( array_values( $seen ), 0, 6 ) )
				);
			}
		}
		return [ $body, implode( ' ', $notes ) ];
	}

	/** Two URLs pointing at the same uploaded file, size suffix aside. */
	private static function same_file( string $a, string $b ): bool {
		$bare = static function ( string $u ): string {
			$path = (string) wp_parse_url( $u, PHP_URL_PATH );
			return (string) preg_replace( '/-\\d+x\\d+(\\.[a-z]{3,4})$/i', '$1', $path );
		};
		return '' !== $bare( $a ) && $bare( $a ) === $bare( $b );
	}

	/**
	 * The picture this email opens with.
	 *
	 * The event's own, one made earlier for this event, or a new one made now.
	 * An email whose main picture has to be asked for separately is an email
	 * that goes out without one, which is what kept happening.
	 */
	public static function picture_for( string $rule_id, array $rule, string $email_id = '' ): string {
		$kept = (string) ( self::email_for( $rule_id, $email_id, $rule )['picture'] ?? '' );
		if ( '' !== $kept ) {
			return $kept;
		}
		return self::event_image( $rule );
	}

	/** What each moment of a promotion has to do that the others do not. */
	private static function kind_brief( string $kind ): string {
		switch ( $kind ) {
			case 'warm':
				return 'It goes out BEFORE the promotion opens. It does not sell yet: it says something is coming and when, and it makes the reader want to be there on the day. No prices, no urgency, no countdown — nothing has started.';
			case 'reminder':
				return 'The promotion is already running and this reader has not bought. He has seen the announcement, so do not repeat it: show him something else — other products, another angle on the same offer — and say plainly how long is left.';
			case 'last':
				return 'It goes out just before the promotion closes. It is short. It says the offer ends, when exactly, and nothing else. One idea, one button.';
		}
		return 'It announces the promotion on the day it opens. This is the one that carries the whole offer: what it is, what it covers, when it ends.';
	}

	/** The promotion and the email an AJAX call is about. @return array{0:string,1:array,2:string} */
	private static function target(): array {
		$rule_id  = isset( $_POST['rule'] ) ? sanitize_key( wp_unslash( $_POST['rule'] ) ) : '';
		$email_id = isset( $_POST['email'] ) ? sanitize_key( wp_unslash( $_POST['email'] ) ) : '';
		$rules    = class_exists( 'DZE_Discounts' ) ? DZE_Discounts::get_rules() : [];
		$rule     = (array) ( $rules[ $rule_id ] ?? [] );
		if ( ! $rule ) {
			wp_send_json_error( [ 'message' => __( 'That event no longer exists.', 'dazont-ecom' ) ] );
		}
		return [ $rule_id, $rule, $email_id ];
	}

	public static function ajax_write(): void {
		self::guard();
		[ $rule_id, $rule, $email_id ] = self::target();
		try {
			$made = self::write_for( $rule_id, $rule, $email_id );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( $made );
	}

	/**
	 * The picture that belongs to this event.
	 *
	 * The one the event already carries for the homepage swap comes first —
	 * it was chosen FOR this promotion — then the shop's own best-seller
	 * photograph, so an email is never sent with a hole in it.
	 */
	public static function event_image( array $rule ): string {
		$id = (int) ( $rule['hero_event_id'] ?? 0 );
		if ( $id ) {
			$url = wp_get_attachment_image_url( $id, 'large' );
			if ( $url ) {
				return (string) $url;
			}
		}
		return '';
	}

	public static function ajax_draft(): void {
		self::guard();
		[ $rule_id, $rule, $email_id ] = self::target();
		try {
			$made = self::draft( $rule_id, $email_id, [
				'body' => isset( $_POST['body'] ) ? self::clean_html( (string) wp_unslash( $_POST['body'] ) ) : null,
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( $made );
	}

	// =========================================================================
	// The picture
	// =========================================================================

	/**
	 * Generates the opening picture of the email with fal.ai.
	 *
	 * An email that opens on a product cut out on white is an email that looks
	 * like a catalogue page. This takes a real photograph the shop already
	 * owns — the picture chosen for the event, or the product that is actually
	 * selling — and puts it in the setting the promotion evokes, through the
	 * same generator, the same key and the same budget guard as the product
	 * images. It lands in the media library like any other image, so it can be
	 * reused, replaced, or simply looked at later.
	 *
	 * @return array{url:string,id:int}
	 * @throws RuntimeException
	 */
	public static function make_image( array $rule, string $prompt = '' ): array {
		if ( ! class_exists( 'DZE_Content' ) || ! DZE_Modules::enabled( 'content' ) ) {
			throw new RuntimeException( __( 'Product content is switched off, and it is what talks to fal.ai.', 'dazont-ecom' ) );
		}
		if ( '' === DZE_Content::fal_key() ) {
			throw new RuntimeException( __( 'Add your fal.ai key under Settings → General first.', 'dazont-ecom' ) );
		}
		if ( DZE_Ai_Usage::over_budget() ) {
			throw new RuntimeException( DZE_Ai_Usage::budget_message() );
		}
		$content = DZE_Content::instance();

		// What it works from: the event's own picture first — somebody chose it
		// FOR this promotion — then the photograph of what is selling best.
		$source = (int) ( $rule['hero_event_id'] ?? 0 );
		if ( ! $source || ! wp_attachment_is_image( $source ) ) {
			$source = 0;
			foreach ( self::best_sellers( self::window_days(), 1, array_map( 'absint', (array) ( $rule['category_ids'] ?? [] ) ) ) as $pid ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
				$img     = $product instanceof WC_Product ? (int) $product->get_image_id() : 0;
				if ( $img && wp_attachment_is_image( $img ) ) {
					$source = $img;
					break;
				}
			}
		}
		if ( ! $source ) {
			throw new RuntimeException( __( 'No photograph to work from: pick an image for the event, or let the shop record a sale first.', 'dazont-ecom' ) );
		}

		// The description comes from the writing itself — the email and its
		// picture are one idea, and one prompt decides both. Nothing to fall
		// back on but the promotion, for the button that asks for another one
		// before anything has been written.
		$prompt = trim( $prompt );
		if ( '' === $prompt ) {
			$title  = trim( (string) ( $rule['title'] ?? '' ) );
			$prompt = 'Photograph this product in the setting this promotion evokes, as the opening picture of a marketing email'
				. ( '' !== $title ? ': ' . $title : '' ) . '. '
				. 'One wide photograph, the product unchanged and sharp, real light and real ground. '
				. 'No text of any kind in the image — no title, no price, no badge, no logo, no watermark.';
			$start = strtotime( (string) ( $rule['start'] ?? '' ) );
			if ( $start ) {
				$prompt .= ' It runs in ' . wp_date( 'F', $start ) . '.';
			}
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		DZE_Ai_Usage::unit( 'promo_email_img' );
		try {
			$url = $content->fal_generate( $prompt, [ $content->fal_source_data_uri( $source, 'full' ) ] );
		} finally {
			DZE_Ai_Usage::unit();
		}
		DZE_Ai_Usage::finished( 'promo_email_img' );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			throw new RuntimeException( $tmp->get_error_message() );
		}
		$name = mb_substr( '' !== $title ? $title : __( 'Promotion email', 'dazont-ecom' ), 0, 80 );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$att  = $content->file_to_library(
			(string) $tmp,
			strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ),
			sanitize_title( $name ),
			$name
		);
		return [
			'id'  => (int) $att,
			'url' => (string) ( wp_get_attachment_image_url( (int) $att, 'large' ) ?: $url ),
		];
	}

	public static function ajax_image(): void {
		self::guard();
		[ $rule_id, $rule, $email_id ] = self::target();
		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		try {
			$made = self::make_image( $rule, $prompt );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		self::keep_picture( $rule_id, $email_id, $made['url'] );
		wp_send_json_success( [ 'url' => $made['url'] ] );
	}

	// =========================================================================
	// Reading it in a real inbox
	// =========================================================================

	/**
	 * Sends the email as it stands to a handful of addresses, through Klaviyo.
	 *
	 * The preview in the admin is drawn by a browser; an inbox is not a
	 * browser. This goes out through Klaviyo's own renderer and Klaviyo's own
	 * sending, so what arrives is what a customer would receive — the merge
	 * tags resolved, the images fetched, Gmail's own treatment applied.
	 *
	 * ONE template is used for every test and rewritten each time: an account
	 * that fills up with "test 1", "test 2"… is an account nobody can find
	 * anything in.
	 *
	 * @param string[] $to Up to five addresses; Klaviyo refuses more.
	 * @throws RuntimeException
	 */
	public static function test_send( string $html, array $to ): void {
		$to = array_values( array_filter( array_map( 'sanitize_email', $to ) ) );
		if ( ! $to ) {
			throw new RuntimeException( __( 'Say who the test goes to first.', 'dazont-ecom' ) );
		}
		$to = array_slice( $to, 0, 5 );

		$name    = sprintf(
			/* translators: %s: the shop name */
			__( '%s — Dazont test send', 'dazont-ecom' ),
			get_bloginfo( 'name' )
		);
		$tpl     = trim( (string) ( self::settings()['test_template'] ?? '' ) );
		$payload = [
			'data' => [
				'type'       => 'template',
				'attributes' => [ 'name' => mb_substr( $name, 0, 120 ), 'editor_type' => 'CODE', 'html' => $html ],
			],
		];
		$made = null;
		if ( '' !== $tpl ) {
			$payload['data']['id'] = $tpl;
			$made = self::request( 'PATCH', 'templates/' . rawurlencode( $tpl ) . '/', $payload, 40 );
			if ( is_wp_error( $made ) ) {
				$made = null; // deleted in Klaviyo since: make a new one rather than fail.
				unset( $payload['data']['id'] );
			}
		}
		if ( null === $made ) {
			$made = self::request( 'POST', 'templates/', $payload, 40 );
			if ( is_wp_error( $made ) ) {
				throw new RuntimeException( $made->get_error_message() );
			}
			$tpl = (string) ( $made['data']['id'] ?? '' );
			if ( '' === $tpl ) {
				throw new RuntimeException( __( 'Klaviyo saved nothing back.', 'dazont-ecom' ) );
			}
			self::remember( [ 'test_template' => $tpl ] );
		}

		$sent = self::request( 'POST', 'template-preview-send-jobs/', [
			'data' => [
				'type'          => 'template-preview-send-job',
				'attributes'    => [ 'recipients' => $to ],
				'relationships' => [ 'template' => [ 'data' => [ 'type' => 'template', 'id' => $tpl ] ] ],
			],
		], 40 );
		if ( is_wp_error( $sent ) ) {
			throw new RuntimeException( $sent->get_error_message() );
		}
	}

	public static function ajax_test(): void {
		self::guard();
		[ $rule_id, $rule, $email_id ] = self::target();
		$body = isset( $_POST['body'] )
			? self::clean_html( (string) wp_unslash( $_POST['body'] ) )
			: self::body_for( $rule, $rule_id, $email_id );
		$to      = isset( $_POST['to'] ) ? (string) wp_unslash( $_POST['to'] ) : '';
		$to      = array_map( 'trim', explode( ',', $to ) );
		if ( ! array_filter( $to ) ) {
			$to = [ (string) get_option( 'admin_email', '' ) ];
		}
		try {
			self::test_send( self::layout( $body ), $to );
			self::remember( [ 'test_to' => implode( ', ', array_filter( array_map( 'sanitize_email', $to ) ) ) ] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'message' => sprintf(
				/* translators: %s: the addresses the test went to */
				__( 'Sent to %s — read it in the inbox.', 'dazont-ecom' ),
				implode( ', ', array_filter( $to ) )
			),
		] );
	}

	// =========================================================================
	// Taking the header and the footer from a Klaviyo template
	//
	// The first attempt asked the owner to paste a marker into imported HTML by
	// hand, which he rightly refused: it works once, on one shop, by somebody
	// who knows what a marker is. So the plugin finds the seam itself.
	//
	// An email is a stack of rows. Two of them are never in doubt: the one
	// carrying the logo at the top, and the one carrying the unsubscribe line
	// at the bottom — the law puts it there and Klaviyo writes it. Everything
	// between the two is the content of whatever campaign the template was
	// built for, and that is exactly what is thrown away.
	// =========================================================================

	/**
	 * Cuts a template into what comes before the content and what comes after.
	 *
	 * @return array{header:DOMElement[],footer:DOMElement[],container:DOMNode,cell:?DOMElement,doc:DOMDocument}
	 * @throws RuntimeException When the template has no seam to cut on.
	 */
	private static function seam( string $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			throw new RuntimeException( __( 'This server has no HTML reader (the DOM extension), so a template cannot be taken apart here.', 'dazont-ecom' ) );
		}
		$doc = new DOMDocument();
		$was = libxml_use_internal_errors( true );
		// Email HTML is never valid enough to load quietly; the meta forces the
		// encoding so accented footers do not come back as mojibake.
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $was );

		$xp = new DOMXPath( $doc );

		// The unsubscribe row: the deepest <tr> that carries it, so its
		// SIBLINGS are the top-level rows of the email.
		$foot = null;
		foreach ( $xp->query( '//tr' ) as $tr ) {
			$text = strtolower( $tr->textContent . ' ' . $doc->saveHTML( $tr ) );
			if ( false !== strpos( $text, 'unsubscribe' ) && ( null === $foot || self::depth( $tr ) > self::depth( $foot ) ) ) {
				$foot = $tr;
			}
		}
		if ( null === $foot ) {
			throw new RuntimeException( __( 'No unsubscribe line was found in that template, so there is no footer to take from it. Pick a template that was actually sent as a campaign.', 'dazont-ecom' ) );
		}
		$container = $foot->parentNode;
		$rows      = [];
		foreach ( $container->childNodes as $node ) {
			if ( $node instanceof DOMElement && 'tr' === strtolower( $node->nodeName ) ) {
				$rows[] = $node;
			}
		}
		$foot_at = array_search( $foot, $rows, true );
		if ( false === $foot_at || count( $rows ) < 2 ) {
			throw new RuntimeException( __( 'That template is not laid out as a stack of rows, so its header and footer cannot be told apart.', 'dazont-ecom' ) );
		}

		// The footer is not only the legal line: the band above it belongs to
		// it too. A footer sits on its own background colour, so the rows are
		// walked backwards while that colour holds.
		$band = self::background( $foot );
		$from = (int) $foot_at;
		if ( '' !== $band ) {
			while ( $from > 1 && self::background( $rows[ $from - 1 ] ) === $band ) {
				$from--;
			}
		}

		// The header is the leading rows that show something and say almost
		// nothing — a logo, a rule, a spacer. The first row that starts talking
		// is already content.
		$to = 0;
		for ( $i = 0; $i < $from; $i++ ) {
			$text = trim( preg_replace( '/\s+/', ' ', $rows[ $i ]->textContent ) );
			$img  = $rows[ $i ]->getElementsByTagName( 'img' )->length > 0;
			if ( 0 === $i || ( mb_strlen( $text ) < 120 && ( $img || '' === $text ) ) ) {
				$to = $i;
				continue;
			}
			break;
		}

		$header = array_slice( $rows, 0, $to + 1 );
		$footer = array_slice( $rows, $from );
		// The cell the content used to sit in: its padding and its type are the
		// ones the template was designed with, and they are worth keeping.
		$cell = null;
		for ( $i = $to + 1; $i < $from; $i++ ) {
			$tds = $rows[ $i ]->getElementsByTagName( 'td' );
			if ( $tds->length > 0 ) {
				$cell = $tds->item( 0 );
				break;
			}
		}
		return [ 'header' => $header, 'footer' => $footer, 'container' => $container, 'cell' => $cell, 'doc' => $doc ];
	}

	private static function depth( DOMNode $n ): int {
		$d = 0;
		while ( $n->parentNode ) {
			$d++;
			$n = $n->parentNode;
		}
		return $d;
	}

	/** The background colour declared on a row or on its first cell, if any. */
	private static function background( DOMElement $tr ): string {
		$style = (string) $tr->getAttribute( 'style' );
		$tds   = $tr->getElementsByTagName( 'td' );
		if ( $tds->length > 0 ) {
			$style .= ';' . (string) $tds->item( 0 )->getAttribute( 'style' );
			$style .= ';background-color:' . (string) $tds->item( 0 )->getAttribute( 'bgcolor' );
		}
		if ( preg_match( '/background(?:-color)?\s*:\s*([^;]+)/i', $style, $m ) ) {
			$hex = strtolower( trim( $m[1] ) );
			return preg_match( '/^#?[0-9a-f]{3,8}$/', $hex ) || 0 === strpos( $hex, 'rgb' ) ? $hex : '';
		}
		return '';
	}

	/**
	 * Builds the shop's frame out of one or two Klaviyo templates.
	 *
	 * @param string $header_html The template the header comes from.
	 * @param string $footer_html The template the footer comes from; the same one when only one was chosen.
	 * @throws RuntimeException
	 */
	public static function frame_from( string $header_html, string $footer_html ): string {
		$a = self::seam( $header_html );
		$b = ( $footer_html === $header_html ) ? $a : self::seam( $footer_html );

		$doc       = $a['doc'];
		$container = $a['container'];

		// Everything between the two seams goes, and one row takes its place —
		// wearing the padding and the type of the row it replaces.
		$rows = [];
		foreach ( $container->childNodes as $node ) {
			if ( $node instanceof DOMElement && 'tr' === strtolower( $node->nodeName ) ) {
				$rows[] = $node;
			}
		}
		$keep_head = [];
		foreach ( $a['header'] as $tr ) {
			$keep_head[ spl_object_id( $tr ) ] = true;
		}
		$keep_foot = ( $b === $a ) ? $a['footer'] : [];
		$keep_ids  = [];
		foreach ( $keep_foot as $tr ) {
			$keep_ids[ spl_object_id( $tr ) ] = true;
		}

		$anchor = null;
		foreach ( $rows as $tr ) {
			$id = spl_object_id( $tr );
			if ( isset( $keep_head[ $id ] ) ) {
				$anchor = $tr;
				continue;
			}
			if ( isset( $keep_ids[ $id ] ) ) {
				continue;
			}
			$container->removeChild( $tr );
		}

		$slot = $doc->createElement( 'tr' );
		$td   = $doc->createElement( 'td' );
		$t    = self::theme_style();
		$td->setAttribute( 'style', $a['cell'] instanceof DOMElement && '' !== $a['cell']->getAttribute( 'style' )
			? (string) $a['cell']->getAttribute( 'style' )
			: sprintf( 'padding:8px 28px 22px;font:400 %dpx/1.6 %s;color:%s;', (int) $t['size'], $t['body'], $t['ink'] ) );
		$td->appendChild( $doc->createTextNode( self::BODY_MARK ) );
		$slot->appendChild( $td );
		if ( $anchor instanceof DOMElement && $anchor->nextSibling ) {
			$container->insertBefore( $slot, $anchor->nextSibling );
		} else {
			$container->appendChild( $slot );
		}

		// A footer borrowed from another template is brought across whole.
		if ( $b !== $a ) {
			foreach ( $b['footer'] as $tr ) {
				$container->appendChild( $doc->importNode( $tr, true ) );
			}
		}

		$html = (string) $doc->saveHTML();
		$html = str_replace( '<?xml encoding="utf-8" ?>', '', $html );
		// saveHTML() escapes nothing inside a text node it wrote, but the
		// marker must come out as it went in.
		$html = str_replace( htmlspecialchars( self::BODY_MARK, ENT_QUOTES ), self::BODY_MARK, $html );
		if ( false === strpos( $html, self::BODY_MARK ) ) {
			throw new RuntimeException( __( 'The frame came back without a place to put the email in it. Nothing was changed.', 'dazont-ecom' ) );
		}
		return trim( $html );
	}

	/** Builds the frame from the chosen templates and hands it back for the field. */
	public static function ajax_frame(): void {
		self::guard();
		$head = isset( $_POST['header'] ) ? sanitize_text_field( wp_unslash( $_POST['header'] ) ) : '';
		$foot = isset( $_POST['footer'] ) ? sanitize_text_field( wp_unslash( $_POST['footer'] ) ) : $head;
		if ( '' === $head ) {
			wp_send_json_error( [ 'message' => __( 'Choose a template first.', 'dazont-ecom' ) ] );
		}
		$foot = '' !== $foot ? $foot : $head;
		try {
			$html = self::frame_from( self::template_html( $head ), $head === $foot ? self::template_html( $head ) : self::template_html( $foot ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'html'    => $html,
			'message' => __( 'Header and footer taken. Look at the preview, then save.', 'dazont-ecom' ),
		] );
	}

	/** One template's HTML, as Klaviyo holds it. */
	private static function template_html( string $id ): string {
		$got = self::request( 'GET', 'templates/' . rawurlencode( $id ) . '/', null, 30 );
		if ( is_wp_error( $got ) ) {
			throw new RuntimeException( $got->get_error_message() );
		}
		$html = (string) ( $got['data']['attributes']['html'] ?? '' );
		if ( '' === trim( $html ) ) {
			throw new RuntimeException( __( 'Klaviyo returned no HTML for that template.', 'dazont-ecom' ) );
		}
		return $html;
	}

	// =========================================================================
	// Settings written without a form
	// =========================================================================

	/**
	 * Writes a few keys of our own settings without going through the form.
	 *
	 * The sanitizer is shaped for FORM input; a programmatic save has to keep
	 * everything it did not touch, so it reads, merges and writes with the
	 * filter removed — the same discipline the rest of the plugin uses.
	 */
	private static function remember( array $keys ): void {
		// register_setting() hangs the sanitizer on sanitize_option_<name>, and
		// that sanitizer is shaped for form input: left in place it would read
		// this write as a half-submitted page. Removed around the write, put
		// back straight after — the same road every programmatic save in this
		// plugin takes.
		$me  = self::instance();
		$tag = 'sanitize_option_' . self::OPT;
		remove_filter( $tag, [ $me, 'sanitize' ] );
		update_option( self::OPT, array_merge( self::settings(), $keys ), false );
		add_filter( $tag, [ $me, 'sanitize' ] );
	}




	// =========================================================================
	// Screens
	// =========================================================================

	public function enqueue( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab navigation only.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// The events screens — the list and one event — carry this once there
		// is something to click.
		$events = class_exists( 'DZE_Discounts' ) && false !== strpos( $hook, DZE_Discounts::MENU_SLUG_EVENTS );
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
			// The frame, handed over once, so both previews redraw as you type
			// instead of asking the server for a picture of what is already on
			// screen. It is the very frame the email is sent inside.
			'shell'    => self::preview_shell(),
			'mark'     => self::BODY_MARK,
			'pictureMark' => self::PICTURE_MARK,
			'shopName' => get_bloginfo( 'name' ),
			'sample'   => $config ? self::sample_body() : '',
			// The segments Klaviyo is not maintaining, so the settings screen
			// can offer to switch the chosen one back on.
			'inactive' => array_values( (array) ( self::catalogue()['inactive'] ?? [] ) ),
			'i18n'    => [
				'unsub'    => __( 'Unsubscribe', 'dazont-ecom' ),
				'loading'  => __( 'Reading your Klaviyo account…', 'dazont-ecom' ),
				'creating' => __( 'Creating the draft in Klaviyo…', 'dazont-ecom' ),
				'made'     => __( 'Draft ready in Klaviyo — nothing was sent.', 'dazont-ecom' ),
				'error'    => __( 'Something went wrong.', 'dazont-ecom' ),
				'subject'  => __( 'Write a subject line first.', 'dazont-ecom' ),
				'open'     => __( 'Open draft ↗', 'dazont-ecom' ),
				'again'    => __( 'Again', 'dazont-ecom' ),
				'written'  => __( 'Written — read it, then save the event.', 'dazont-ecom' ),
				'working'  => __( 'Talking to Klaviyo…', 'dazont-ecom' ),
				'thenSave' => __( 'Save the settings below to keep it.', 'dazont-ecom' ),
				'pickTpl'  => __( 'Choose the template the header comes from.', 'dazont-ecom' ),
				'openMail' => __( 'Open', 'dazont-ecom' ),
				'reading'  => __( 'Asking Klaviyo…', 'dazont-ecom' ),
				'whenOpen' => __( 'Which days work best?', 'dazont-ecom' ),
				'addMail'  => __( 'Add', 'dazont-ecom' ),
				'dropMail' => __( 'Remove this email from the promotion? What was written for it is lost when you save.', 'dazont-ecom' ),
				'pickedFrom' => __( 'The logo row and everything from the unsubscribe line down are kept; whatever the campaign had in between is dropped. Check the preview, then save.', 'dazont-ecom' ),
				'shooting' => __( 'Making the picture — this takes a minute…', 'dazont-ecom' ),
				'writing'  => __( 'Writing and laying out the email…', 'dazont-ecom' ),
				'shot'     => __( 'Made, and filed in the media library.', 'dazont-ecom' ),
				'sending'  => __( 'Handing it to Klaviyo…', 'dazont-ecom' ),
			],
		] );
	}

	/**
	 * The Email column of the promotions list: how many emails this promotion
	 * carries, and how many are already drafts in Klaviyo.
	 *
	 * No button here any more. Writing an email needs the promotion's dates,
	 * its products and a place to read what comes back — none of which fits in
	 * a table cell, which is why the one that used to be here could be pressed
	 * and led nowhere useful.
	 */
	public function render_cell( string $rule_id, array $rule ): void {
		$n = self::counts( $rule_id, $rule );
		if ( ! $n['emails'] ) {
			echo '<span style="color:#a7aaad;">—</span>';
			return;
		}
		printf(
			'<span title="%1$s">✉ %2$s</span>',
			esc_attr__( 'Emails written for this promotion', 'dazont-ecom' ),
			esc_html( sprintf(
				/* translators: %d: number of emails */
				_n( '%d email', '%d emails', $n['emails'], 'dazont-ecom' ),
				$n['emails']
			) )
		);
		if ( $n['drafts'] ) {
			printf(
				'<span style="display:block;font-size:12px;color:#0a7040;">%s</span>',
				esc_html( sprintf(
					/* translators: %d: number of drafts */
					_n( '%d in Klaviyo', '%d in Klaviyo', $n['drafts'], 'dazont-ecom' ),
					$n['drafts']
				) )
			);
		}
	}

	/**
	 * The emails of one promotion, on the promotion's own screen.
	 *
	 * A promotion is announced more than once: a warm-up before it opens, the
	 * launch on the day, a reminder while it runs, a last call before it
	 * closes. Each is listed with its date and its subject, each is written and
	 * drafted on its own, and all of them are saved by the page's own Save
	 * button — the four moments ARE the four emails, so there are no ids to
	 * invent and nothing to keep in step.
	 */
	public function render_editor( string $rule_id, array $rule ): void {
		if ( '' === $rule_id ) {
			echo '<h3>' . esc_html__( 'Emails', 'dazont-ecom' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Save the event first — its emails are written from its dates and its discount.', 'dazont-ecom' ) . '</p>';
			return;
		}
		$emails = self::emails_for( $rule_id, $rule );
		$kinds  = self::kinds();
		$fmt    = get_option( 'date_format' ) ?: 'Y-m-d';
		$first  = '';
		foreach ( $kinds as $kind => $meta ) {
			if ( isset( $emails[ $kind ] ) && '' === $first ) {
				$first = $kind;
			}
		}
		?>
		<h3><?php esc_html_e( 'Emails', 'dazont-ecom' ); ?></h3>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'One promotion, several emails. Each is saved by the Save button at the bottom of this page, and sent to Klaviyo as a draft — never sent from here.', 'dazont-ecom' ); ?>
		</p>

		<div id="dze-klav-editor" data-rule="<?php echo esc_attr( $rule_id ); ?>">
			<div class="dze-mail-list">
				<?php foreach ( $kinds as $kind => $meta ) :
					$has  = isset( $emails[ $kind ] );
					$mail = (array) ( $emails[ $kind ] ?? [] );
					$when = (string) ( $mail['when'] ?? self::default_when( $kind, $rule ) );
					$ts   = strtotime( str_replace( 'T', ' ', $when ) );
					?>
					<div class="dze-mail<?php echo $has ? '' : ' is-empty'; ?>" data-kind="<?php echo esc_attr( $kind ); ?>">
						<div class="dze-mail-thumb"><iframe title="" sandbox="allow-same-origin" scrolling="no"></iframe></div>
						<div class="dze-mail-what">
							<strong><?php echo esc_html( $meta['label'] ); ?></strong>
							<span class="dze-mail-when"><?php echo esc_html( $ts ? wp_date( $fmt, $ts ) : $meta['when'] ); ?><span class="dze-smart"><?php esc_html_e( 'Smart Send Time', 'dazont-ecom' ); ?></span></span>
							<span class="dze-mail-subject"><?php echo esc_html( (string) ( $mail['subject'] ?? '' ) ); ?></span>
						</div>
						<div class="dze-mail-state">
							<?php if ( ! empty( $mail['draft']['campaign'] ) ) : ?>
								<a href="<?php echo esc_url( self::campaign_url( (string) $mail['draft']['campaign'] ) ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Draft in Klaviyo ↗', 'dazont-ecom' ); ?>
								</a>
							<?php endif; ?>
						</div>
						<div class="dze-mail-act">
							<?php if ( $has ) : ?>
								<button type="button" class="button button-small dze-mail-open"><?php esc_html_e( 'Open', 'dazont-ecom' ); ?></button>
								<button type="button" class="button-link dze-mail-drop" title="<?php esc_attr_e( 'Remove this email from the promotion', 'dazont-ecom' ); ?>">&times;</button>
							<?php else : ?>
								<button type="button" class="button button-small dze-mail-add"><?php esc_html_e( 'Add', 'dazont-ecom' ); ?></button>
							<?php endif; ?>
						</div>
						<?php // Every email keeps its fields in the form, so ONE Save keeps them all. ?>
						<input type="hidden" class="dze-f-exists" name="dze_email[<?php echo esc_attr( $kind ); ?>][exists]" value="<?php echo $has ? '1' : ''; ?>" />
						<input type="hidden" name="dze_email[<?php echo esc_attr( $kind ); ?>][kind]" value="<?php echo esc_attr( $kind ); ?>" />
						<input type="hidden" class="dze-f-picture" name="dze_email[<?php echo esc_attr( $kind ); ?>][picture]" value="<?php echo esc_attr( (string) ( $mail['picture'] ?? '' ) ); ?>" />
						<input type="hidden" class="dze-f-subject" name="dze_email[<?php echo esc_attr( $kind ); ?>][subject]" value="<?php echo esc_attr( (string) ( $mail['subject'] ?? '' ) ); ?>" />
						<input type="hidden" class="dze-f-preview" name="dze_email[<?php echo esc_attr( $kind ); ?>][preview]" value="<?php echo esc_attr( (string) ( $mail['preview'] ?? '' ) ); ?>" />
						<input type="hidden" class="dze-f-when" name="dze_email[<?php echo esc_attr( $kind ); ?>][when]" value="<?php echo esc_attr( $when ); ?>" />
						<textarea class="dze-f-body" name="dze_email[<?php echo esc_attr( $kind ); ?>][body]" style="display:none;"><?php echo esc_textarea( (string) ( $mail['body'] ?? '' ) ); ?></textarea>
					</div>
				<?php endforeach; ?>
			</div>

			<div id="dze-mail-edit" style="<?php echo '' === $first ? 'display:none;' : ''; ?>">
				<h4 id="dze-mail-title" style="margin:18px 0 6px;"></h4>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dze-klav-e-subject"><?php esc_html_e( 'Subject', 'dazont-ecom' ); ?></label></th>
						<td><input type="text" id="dze-klav-e-subject" class="large-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="dze-klav-e-preview"><?php esc_html_e( 'Preview text', 'dazont-ecom' ); ?></label></th>
						<td><input type="text" id="dze-klav-e-preview" class="large-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="dze-klav-e-when"><?php esc_html_e( 'Sends on', 'dazont-ecom' ); ?></label></th>
						<td>
							<?php // The day is the shop's decision; the hour is Klaviyo's. ?>
							<?php // Said BESIDE the field, not in small print under it: a
							// note nobody reads is a note that is not there. ?>
							<input type="date" id="dze-klav-e-when" />
							<span class="dze-smart" title="<?php esc_attr_e( 'Klaviyo works out, for each person on the list, the hour that reader actually opens his mail.', 'dazont-ecom' ); ?>">
								<?php esc_html_e( 'Hour: Klaviyo Smart Send Time', 'dazont-ecom' ); ?>
							</span>
							<button type="button" class="button-link" id="dze-klav-hours" style="margin-left:10px;"><?php esc_html_e( 'Which days work best?', 'dazont-ecom' ); ?></button>
							<div id="dze-klav-hours-out" style="display:none;margin:8px 0 0;"></div>
							<p class="description"><?php esc_html_e( 'Nothing goes out until you press send in Klaviyo.', 'dazont-ecom' ); ?></p>
						</td>
					</tr>
				</table>

				<p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
					<button type="button" class="button button-primary" id="dze-klav-e-write"><?php esc_html_e( 'Write the email', 'dazont-ecom' ); ?></button>
					<button type="button" class="button" id="dze-klav-e-shot"><?php esc_html_e( 'Change the picture', 'dazont-ecom' ); ?></button>
					<?php if ( class_exists( 'DZE_Prompts' ) ) { DZE_Prompts::the_button( 'promo_email' ); } ?>
					<span style="flex:1;"></span>
					<span class="dze-klav-switch">
						<button type="button" class="button dze-klav-tab is-on" data-tab="view"><?php esc_html_e( 'Preview', 'dazont-ecom' ); ?></button><button type="button" class="button dze-klav-tab" data-tab="code"><?php esc_html_e( 'HTML', 'dazont-ecom' ); ?></button>
					</span>
				</p>
				<textarea id="dze-klav-e-body" rows="18" class="large-text code" style="display:none;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;"></textarea>
				<iframe id="dze-klav-e-iframe" title="<?php esc_attr_e( 'Email preview', 'dazont-ecom' ); ?>" sandbox="allow-same-origin" style="width:100%;height:700px;border:1px solid #dcdcde;background:#fff;"></iframe>

				<p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
					<button type="button" class="button" id="dze-klav-e-draft"><?php esc_html_e( 'Create the draft in Klaviyo', 'dazont-ecom' ); ?></button>
					<span style="flex:1;"></span>
					<label for="dze-klav-e-to"><?php esc_html_e( 'Test to', 'dazont-ecom' ); ?></label>
					<input type="text" id="dze-klav-e-to" class="regular-text" value="<?php echo esc_attr( (string) self::conf( 'test_to', (string) get_option( 'admin_email', '' ) ) ); ?>" />
					<button type="button" class="button" id="dze-klav-e-test"><?php esc_html_e( 'Send', 'dazont-ecom' ); ?></button>
				</p>
				<p><span id="dze-klav-e-msg" style="font-size:13px;"></span></p>
			</div>

			<style>
				.dze-mail-list{max-width:880px;border:1px solid #dcdcde;border-radius:4px;overflow:hidden;background:#fff;}
				.dze-mail{display:flex;align-items:center;gap:12px;padding:8px 12px;border-bottom:1px solid #f0f0f1;}
				.dze-mail:last-child{border-bottom:0;}
				.dze-mail.is-on{background:#f6f7f7;box-shadow:inset 3px 0 0 #2271b1;}
				.dze-mail.is-empty{opacity:.55;}
				.dze-mail-thumb{width:76px;height:56px;flex:0 0 76px;border:1px solid #e6e6e6;background:#fff;overflow:hidden;position:relative;}
				.dze-mail-thumb iframe{position:absolute;top:0;left:0;width:600px;height:440px;border:0;transform:scale(.1266);transform-origin:0 0;pointer-events:none;}
				.dze-mail-what{flex:1;min-width:0;}
				.dze-mail-what strong{margin-right:8px;}
				.dze-mail-when{color:#646970;font-size:12px;}
				.dze-smart{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:9px;background:#eef4fb;color:#2271b1;font-size:11px;white-space:nowrap;vertical-align:1px;}
				.dze-mail-subject{display:block;color:#50575e;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
				.dze-mail-state{font-size:12px;white-space:nowrap;}
				.dze-mail-act{white-space:nowrap;}
				.dze-mail-drop{color:#b32d2e;text-decoration:none;font-size:16px;margin-left:6px;}
				.dze-klav-switch .button{border-radius:0;margin:0;}
				.dze-klav-switch .button:first-child{border-radius:3px 0 0 3px;}
				.dze-klav-switch .button:last-child{border-radius:0 3px 3px 0;margin-left:-1px;}
				.dze-klav-switch .button.is-on{background:#2271b1;border-color:#2271b1;color:#fff;}
				.dze-hours{display:flex;align-items:flex-end;gap:6px;height:56px;max-width:360px;}
				.dze-hour{flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%;}
				.dze-hour i{display:block;width:100%;background:#c3c4c7;}
				.dze-hour b{font:400 11px/1.4 inherit;color:#646970;text-align:center;}
				.dze-hour.is-peak i{background:#2271b1;}
				.dze-hour.is-peak b{color:#2271b1;font-weight:600;}
			</style>
		</div>
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
			<?php esc_html_e( 'Turns a marketing event into a draft campaign in Klaviyo. Nothing is ever sent from WordPress.', 'dazont-ecom' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_klaviyo_options' ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::OPT ); ?>[form]" value="1" />

			<h2 class="title"><?php esc_html_e( 'API key', 'dazont-ecom' ); ?></h2>
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

			<h2 class="title"><?php esc_html_e( 'Audience', 'dazont-ecom' ); ?></h2>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Answered once, used by every promotion.', 'dazont-ecom' ); ?>
		</p>
		<p>
			<button type="button" class="button" id="dze-klav-refresh" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Read my Klaviyo account', 'dazont-ecom' ); ?></button>
			<span id="dze-klav-refresh-msg" style="margin-left:8px;font-size:13px;">
				<?php
				if ( empty( $cat['audiences'] ) ) {
					esc_html_e( 'Not read yet — press the button once and the two menus fill in.', 'dazont-ecom' );
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
						<option value=""><?php esc_html_e( '— pick a list or a segment —', 'dazont-ecom' ); ?></option>
						<?php foreach ( (array) $cat['audiences'] as $id => $label ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $inc ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
						<?php if ( '' !== $inc && ! isset( $cat['audiences'][ $inc ] ) ) : ?>
							<option value="<?php echo esc_attr( $inc ); ?>" selected><?php echo esc_html( $inc ); ?></option>
						<?php endif; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Normally all your contacts. Klaviyo serves each reader in his own language, so one campaign covers every market.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-klav-exc"><?php esc_html_e( 'But not', 'dazont-ecom' ); ?></label></th>
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
						<?php esc_html_e( 'Put your recent buyers here — a sale announced to somebody who paid full price three days ago earns a refund request. No segment for it yet?', 'dazont-ecom' ); ?>
					</p>
					<p class="dze-klav-seg-tools" style="margin:0 0 6px;">
						<label>
							<?php esc_html_e( 'people who bought in the last', 'dazont-ecom' ); ?>
							<input type="number" id="dze-klav-weeks" value="3" min="1" max="12" class="small-text" />
							<?php esc_html_e( 'weeks', 'dazont-ecom' ); ?>
						</label>
						<button type="button" class="button" id="dze-klav-make-seg" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Create this segment', 'dazont-ecom' ); ?></button>
						<button type="button" class="button" id="dze-klav-activate" style="margin-left:10px;<?php echo in_array( $exc, (array) ( $cat['inactive'] ?? [] ), true ) ? '' : 'display:none;'; ?>">
							&#9889; <?php esc_html_e( 'This one is paused in Klaviyo — switch it back on', 'dazont-ecom' ); ?>
						</button>
						<span id="dze-klav-seg-msg" style="margin-left:8px;font-size:13px;"></span>
					</p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Email template', 'dazont-ecom' ); ?></h2>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'The header and the footer, identical on every promotion. Shipped ready to use, or taken from one of your own Klaviyo templates below.', 'dazont-ecom' ); ?>
		</p>
		<?php $dze_tpls = (array) ( $cat['templates'] ?? [] ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dze-klav-th"><?php esc_html_e( 'Header from', 'dazont-ecom' ); ?></label></th>
				<td>
					<select id="dze-klav-th" style="min-width:340px;">
						<option value=""><?php esc_html_e( '— keep the current one —', 'dazont-ecom' ); ?></option>
						<?php foreach ( $dze_tpls as $dze_id => $dze_name ) : ?>
							<option value="<?php echo esc_attr( $dze_id ); ?>"><?php echo esc_html( $dze_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-klav-tf"><?php esc_html_e( 'Footer from', 'dazont-ecom' ); ?></label></th>
				<td>
					<select id="dze-klav-tf" style="min-width:340px;">
						<option value=""><?php esc_html_e( '— the same template —', 'dazont-ecom' ); ?></option>
						<?php foreach ( $dze_tpls as $dze_id => $dze_name ) : ?>
							<option value="<?php echo esc_attr( $dze_id ); ?>"><?php echo esc_html( $dze_name ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button" id="dze-klav-take" style="margin-left:8px;"><?php esc_html_e( 'Take them', 'dazont-ecom' ); ?></button>
					<p class="description" id="dze-klav-tpl-hint">
						<?php
						echo $dze_tpls
							? esc_html__( 'The logo row and everything from the unsubscribe line down are kept; whatever the campaign had in between is dropped. Check the preview, then save.', 'dazont-ecom' )
							: esc_html__( 'Press "Read my Klaviyo account" above to list your templates.', 'dazont-ecom' );
						?>
					</p>
				</td>
			</tr>
		</table>
		<p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
			<button type="button" class="button-link" id="dze-klav-shell-reset">&#8634; <?php esc_html_e( 'Restore the shipped template', 'dazont-ecom' ); ?></button>
			<span id="dze-klav-shell-msg" style="font-size:13px;"></span>
			<span style="flex:1;"></span>
			<span class="dze-klav-switch">
				<button type="button" class="button dze-klav-stab is-on" data-tab="view"><?php esc_html_e( 'Preview', 'dazont-ecom' ); ?></button><button type="button" class="button dze-klav-stab" data-tab="code"><?php esc_html_e( 'HTML', 'dazont-ecom' ); ?></button>
			</span>
		</p>
		<textarea id="dze-klav-shell" name="<?php echo esc_attr( self::OPT . '[shell]' ); ?>" rows="10" class="large-text code" style="display:none;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;"><?php echo esc_textarea( self::shell() ); ?></textarea>
		<iframe id="dze-klav-shell-frame" title="<?php esc_attr_e( 'Template preview', 'dazont-ecom' ); ?>" sandbox="allow-same-origin" style="width:100%;max-width:880px;height:640px;border:1px solid #dcdcde;background:#fff;"></iframe>
		<script>
		(function () {
			var shipped = <?php echo wp_json_encode( self::default_shell() ); ?>;
			var btn = document.getElementById('dze-klav-shell-reset');
			var ta  = document.getElementById('dze-klav-shell');
			if ( btn && ta ) {
				btn.addEventListener('click', function () {
					ta.value = shipped;
					// The preview watches the field, so it redraws on its own.
					ta.dispatchEvent(new Event('input', { bubbles: true }));
				});
			}
		}());
		</script>

		<h2 class="title"><?php esc_html_e( 'Products', 'dazont-ecom' ); ?></h2>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Best-sellers over the window below, in the promotion\'s categories when it names any. Same window, same products — widen it to reach further back.', 'dazont-ecom' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dze-klav-days"><?php esc_html_e( 'Best-sellers of the last', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="number" id="dze-klav-days" name="<?php echo esc_attr( self::OPT . '[days]' ); ?>" value="<?php echo esc_attr( (string) self::window_days() ); ?>" min="1" max="365" class="small-text" />
					<?php esc_html_e( 'days', 'dazont-ecom' ); ?>
					<p class="description"><?php esc_html_e( 'A quiet window falls back to catalogue popularity.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Email copy prompt', 'dazont-ecom' ); ?></h2>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Decides the whole email: the words and the layout. Products, photographs, links and prices are handed over by the shop and checked on the way back.', 'dazont-ecom' ); ?>
		</p>
			<textarea id="dze-klav-prompt" name="<?php echo esc_attr( self::OPT . '[email_prompt]' ); ?>" rows="10" class="large-text code" style="max-width:880px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;"><?php echo esc_textarea( self::email_prompt() ); ?></textarea>
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
