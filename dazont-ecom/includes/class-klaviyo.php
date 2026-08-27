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
		add_action( 'wp_ajax_dze_klav_plan',    [ __CLASS__, 'ajax_plan' ] );
		add_action( 'wp_ajax_dze_klav_drop',    [ __CLASS__, 'ajax_drop' ] );
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
			[
				'label' => __( 'Take the header and footer from Klaviyo', 'dazont-ecom' ),
				'url'   => $tab . '#dze-klav-th',
				'done'  => '' !== trim( (string) ( self::settings()['shell'] ?? '' ) ),
				'note'  => __( 'In Klaviyo, make one template with your header, ONE empty section, and your footer. Read it here and every promotion goes out inside it.', 'dazont-ecom' ),
			],
		];
	}

	/**
	 * True when the three things a draft cannot be made without are answered.
	 *
	 * The frame is one of them, and that is the whole point of it being read
	 * from Klaviyo rather than drawn here. A plugin that quietly falls back to
	 * a header of its own invention when none was chosen sends a stranger's
	 * email under the shop's name, and does it silently. So this module does
	 * not start until the owner has pointed at his template: the checklist
	 * says so on the screen where he is trying to use it, and the editor does
	 * not appear until it is done.
	 */
	public static function ready(): bool {
		return '' !== self::key()
			&& '' !== (string) self::conf( 'included' )
			&& '' !== self::shell();
	}

	/**
	 * The shop's own style, read from the theme — type, colour, buttons, the
	 * shape of a product card.
	 *
	 * An email that does not look like the site it comes from is an email the
	 * reader does not recognise, and a card restyled by hand here is a second
	 * version of a decision the shop has already made once. So nothing is
	 * asked for: the theme is read. A block theme's global styles answer
	 * first, WooCommerce's own e-mail colours next, and Astra's settings last
	 * and loudest — Astra is what this shop runs, so what it says wins.
	 *
	 * Every value remembers WHERE it came from, so the settings screen can
	 * show the reader why his email looks the way it does instead of leaving
	 * him to guess. A style nobody can trace is a style nobody trusts.
	 *
	 * @return array{head:string,body:string,ink:string,link:string,size:int,muted:string,sale:string,btn_bg:string,btn_ink:string,radius:int,card:string,border:string}
	 */
	public static function theme_style(): array {
		return self::style()['value'];
	}

	/** Where each value of theme_style() was read from. */
	public static function style_sources(): array {
		return self::style()['from'];
	}

	/**
	 * Both at once, worked out once per request.
	 *
	 * WordPress HAS a standard for this and it is theme.json. Since 6.1 it is
	 * not a block-theme thing either: a classic theme's own theme.json is
	 * merged in, and a theme that has none still contributes through the
	 * add_theme_support() calls it made for the block editor, which WordPress
	 * folds into the same structure. So one reader answers for any theme, and
	 * a theme-specific bridge is only ever asked about what the standard left
	 * blank.
	 *
	 * Two things make the standard usable in an email. Presets come back as
	 * CSS variables — var(--wp--preset--color--primary) — which mean nothing
	 * in an inbox, so they are resolved to the real value first. And the
	 * quieter colours are DERIVED from the two the theme did state rather than
	 * invented: a struck-through price is the text colour faded towards the
	 * paper, a card border is the same at a tenth. Those follow the shop
	 * automatically instead of being two hex codes of ours.
	 *
	 * @return array{value:array<string,mixed>,from:array<string,string>}
	 */
	private static function style(): array {
		static $done = null;
		if ( null !== $done ) {
			return $done;
		}
		$shipped = __( 'Plugin default', 'dazont-ecom' );
		$out = [
			'head'    => "'Aldrich', Helvetica, Arial, sans-serif",
			'body'    => 'Helvetica, Arial, sans-serif',
			'ink'     => '#111111',
			'link'    => '#719D1A',
			'size'    => 16,
			'muted'   => '',
			'sale'    => '',
			'btn_bg'  => '',
			'btn_ink' => '',
			'radius'  => 0,
			'card'    => '#ffffff',
			'border'  => '',
		];
		$from = array_fill_keys( array_keys( $out ), $shipped );
		// One place that writes a value AND remembers who said it: two lines
		// that can disagree is how a screen ends up explaining the wrong thing.
		// First writer wins, so the ORDER of the readers below is the order of
		// authority and nothing else has to say so. A reader that runs later
		// cannot quietly overrule one the owner considers more authoritative.
		$set = static function ( string $key, $value, string $source ) use ( &$out, &$from, $shipped ): void {
			if ( '' === $value || null === $value || $from[ $key ] !== $shipped ) {
				return;
			}
			$out[ $key ]  = $value;
			$from[ $key ] = $source;
		};

		$settings = function_exists( 'wp_get_global_settings' ) ? (array) wp_get_global_settings() : [];
		$styles   = function_exists( 'wp_get_global_styles' ) ? (array) wp_get_global_styles() : [];

		// --- The two resolvers that make theme.json answerable in an email ---

		$palette = [];
		foreach ( [ 'theme', 'custom' ] as $origin ) {
			foreach ( (array) ( $settings['color']['palette'][ $origin ] ?? [] ) as $row ) {
				if ( ! empty( $row['slug'] ) && ! empty( $row['color'] ) ) {
					$palette[ (string) $row['slug'] ] = (string) $row['color'];
				}
			}
		}
		if ( ! $palette ) {
			// A classic theme that never shipped a theme.json still declared
			// its palette to the block editor. Same data, older door.
			// get_theme_support() answers false when the theme said nothing,
			// and false[0] is a warning waiting to happen on somebody else's
			// shop rather than an empty list.
			$declared = get_theme_support( 'editor-color-palette' );
			$declared = ( is_array( $declared ) && isset( $declared[0] ) && is_array( $declared[0] ) ) ? $declared[0] : [];
			foreach ( $declared as $row ) {
				if ( ! empty( $row['slug'] ) && ! empty( $row['color'] ) ) {
					$palette[ (string) $row['slug'] ] = (string) $row['color'];
				}
			}
		}
		$families = [];
		foreach ( [ 'theme', 'custom' ] as $origin ) {
			foreach ( (array) ( $settings['typography']['fontFamilies'][ $origin ] ?? [] ) as $row ) {
				if ( ! empty( $row['slug'] ) && ! empty( $row['fontFamily'] ) ) {
					$families[ (string) $row['slug'] ] = (string) $row['fontFamily'];
				}
			}
		}
		$sizes = [];
		foreach ( [ 'theme', 'custom' ] as $origin ) {
			foreach ( (array) ( $settings['typography']['fontSizes'][ $origin ] ?? [] ) as $row ) {
				if ( ! empty( $row['slug'] ) && isset( $row['size'] ) ) {
					$sizes[ (string) $row['slug'] ] = (string) $row['size'];
				}
			}
		}
		/** A theme.json value, with var(--wp--preset--…) turned back into a real one. */
		$preset = static function ( $v, array $map ) {
			$v = is_string( $v ) ? trim( $v ) : '';
			if ( '' === $v ) {
				return '';
			}
			if ( preg_match( '/var\(\s*--wp--preset--[a-z-]+--([a-z0-9-]+)\s*\)/i', $v, $m ) ) {
				return (string) ( $map[ strtolower( $m[1] ) ] ?? '' );
			}
			return $v;
		};
		$hex = static function ( $v ): string {
			$v = is_string( $v ) ? strtolower( trim( $v ) ) : '';
			if ( preg_match( '/^#[0-9a-f]{3}$/', $v ) ) {
				return '#' . $v[1] . $v[1] . $v[2] . $v[2] . $v[3] . $v[3];
			}
			if ( preg_match( '/^#[0-9a-f]{6}$/', $v ) ) {
				return $v;
			}
			// rgb()/rgba() is legal in theme.json and legal in an inbox only as
			// a hex, so it is converted rather than dropped.
			if ( preg_match( '/^rgba?\(\s*(\d+)\D+(\d+)\D+(\d+)/', $v, $m ) ) {
				return sprintf( '#%02x%02x%02x', min( 255, (int) $m[1] ), min( 255, (int) $m[2] ), min( 255, (int) $m[3] ) );
			}
			return '';
		};
		$stack = static function ( $v ): string {
			$v = is_string( $v ) ? trim( wp_strip_all_tags( $v ) ) : '';
			return ( '' !== $v && false === strpos( $v, 'var(' ) ) ? $v : '';
		};
		$px = static function ( $v ): int {
			$v = (string) $v;
			// clamp()/min()/max() are what a fluid size looks like; the last
			// plain px in it is the one a desktop inbox would land on.
			if ( preg_match_all( '/([0-9.]+)px/', $v, $m ) ) {
				return (int) round( (float) end( $m[1] ) );
			}
			if ( preg_match( '/^([0-9.]+)(rem|em)$/', $v, $m ) ) {
				return (int) round( (float) $m[1] * 16 );
			}
			return preg_match( '/^[0-9]+$/', trim( $v ) ) ? (int) $v : -1;
		};

		// --- The theme's own settings, first ---
		//
		// The order used to be the other way round and it was wrong. Where the
		// owner GOES to change a colour is where the colour lives: he opens
		// his theme's customizer, sets his palette, and the whole site
		// follows. A theme.json read cannot outrank that — and when it tried,
		// it answered with WordPress's OWN default palette and put a green
		// nobody had chosen into the email. The standard below still answers
		// for any theme; it just no longer overrules the one place the owner
		// actually edits.
		foreach ( self::theme_bridges() as $label => $reader ) {
			// One theme answers on any given shop; the rest are asked only
			// while something is still unspoken, so a shop running Astra never
			// pays for four option reads it has no use for.
			if ( ! array_filter( $from, static fn( $who ) => $who === $shipped ) ) {
				break;
			}
			foreach ( (array) $reader() as $key => $value ) {
				if ( isset( $out[ $key ] ) ) {
					$set( $key, is_string( $value ) && 0 === strpos( $value, '#' ) ? $hex( $value ) : $value, $label );
				}
			}
		}


		// --- The standard, for any theme ---

		if ( $settings || $styles ) {
			$src = __( 'Theme (theme.json / block editor)', 'dazont-ecom' );
			$set( 'ink',  $hex( $preset( $styles['color']['text'] ?? '', $palette ) ), $src );
			$set( 'card', $hex( $preset( $styles['color']['background'] ?? '', $palette ) ), $src );

			// The heading's own face is asked for BEFORE the page's, because
			// the first writer wins and the general one would otherwise claim
			// the slot the specific one was meant to fill.
			$set( 'head', $stack( $preset( $styles['blocks']['core/heading']['typography']['fontFamily'] ?? '', $families ) ), $src );
			$f = $stack( $preset( $styles['typography']['fontFamily'] ?? '', $families ) );
			if ( '' !== $f ) {
				$set( 'body', $f, $src );
				$set( 'head', $f, $src );
			}
			$s = $px( $preset( $styles['typography']['fontSize'] ?? '', $sizes ) );
			if ( $s >= 12 && $s <= 22 ) {
				$set( 'size', $s, $src );
			}
			$link = $hex( $preset( $styles['elements']['link']['color']['text'] ?? '', $palette ) );
			$set( 'link', $link, $src );
			$set( 'sale', $link, $src );

			$btn = $hex( $preset( $styles['elements']['button']['color']['background'] ?? '', $palette ) );
			$set( 'btn_bg', $btn, $src );
			$set( 'btn_ink', $hex( $preset( $styles['elements']['button']['color']['text'] ?? '', $palette ) ), $src );
			$r = $px( $styles['elements']['button']['border']['radius'] ?? '' );
			if ( $r >= 0 && $r <= 40 ) {
				$set( 'radius', $r, $src );
			}
		}
		// The palette answers what the styles did not: every theme that speaks
		// to the block editor names its own accent, and these are the slugs
		// WordPress itself suggests.
		if ( $palette ) {
			$src  = __( 'Theme colour palette', 'dazont-ecom' );
			$pick = static function ( array $names ) use ( $palette, $hex ): string {
				foreach ( $names as $n ) {
					if ( isset( $palette[ $n ] ) ) {
						$c = $hex( $palette[ $n ] );
						if ( '' !== $c ) {
							return $c;
						}
					}
				}
				return '';
			};
			$accent = $pick( [ 'primary', 'accent', 'accent-1', 'theme-color', 'link-color' ] );
			$set( 'link', $accent, $src );
			$set( 'sale', $accent, $src );
			$set( 'btn_bg', $accent, $src );
			$set( 'ink', $pick( [ 'foreground', 'contrast', 'text', 'base-3', 'dark' ] ), $src );
			$set( 'card', $pick( [ 'background', 'base', 'white' ] ), $src );
		}

		// WooCommerce already asked the owner what colour his shop is, for its
		// own transactional emails. A shop that answered there has answered,
		// and the question is the same question.
		$base = $hex( (string) get_option( 'woocommerce_email_base_color', '' ) );
		if ( '' !== $base ) {
			$src = __( 'WooCommerce → Emails', 'dazont-ecom' );
			foreach ( [ 'link', 'sale', 'btn_bg' ] as $k ) {
				$set( $k, $base, $src );
			}
		}

		// --- The quiet colours, derived rather than invented ---
		$mix = static function ( string $a, string $b, float $r ): string {
			if ( 7 !== strlen( $a ) || 7 !== strlen( $b ) ) {
				return $a;
			}
			$o = '#';
			for ( $i = 1; $i < 7; $i += 2 ) {
				$o .= sprintf( '%02x', (int) round( hexdec( substr( $a, $i, 2 ) ) * ( 1 - $r ) + hexdec( substr( $b, $i, 2 ) ) * $r ) );
			}
			return $o;
		};
		$derived = __( 'Worked out from the two above', 'dazont-ecom' );
		if ( '' === $out['muted'] ) {
			$set( 'muted', $mix( $out['ink'], $out['card'], 0.45 ), $derived );
		}
		if ( '' === $out['border'] ) {
			$set( 'border', $mix( $out['ink'], $out['card'], 0.88 ), $derived );
		}
		if ( '' === $out['sale'] ) {
			$set( 'sale', $out['link'], $derived );
		}
		if ( '' === $out['btn_bg'] ) {
			$set( 'btn_bg', $out['link'], $derived );
		}
		if ( '' === $out['btn_ink'] ) {
			// Black text on a dark button is a button nobody reads: the one
			// that stands out against what the shop chose wins.
			$lum = ( hexdec( substr( $out['btn_bg'], 1, 2 ) ) * 299
				+ hexdec( substr( $out['btn_bg'], 3, 2 ) ) * 587
				+ hexdec( substr( $out['btn_bg'], 5, 2 ) ) * 114 ) / 1000;
			$set( 'btn_ink', $lum > 150 ? '#111111' : '#ffffff', $derived );
		}

		$done = [ 'value' => $out, 'from' => $from ];
		return $done;
	}

	/**
	 * The shop's colour palette, as its theme holds it.
	 *
	 * Shown on the settings screen beside the values the email uses, so the
	 * owner can see his own palette and the email's colours side by side and
	 * tell in one look whether the right one was picked. Read from the theme's
	 * own palette first — that is the list he edits — and from the standard
	 * otherwise. WordPress's default palette is never included: it belongs to
	 * WordPress, not to the shop, and mistaking one for the other is what put
	 * a green nobody chose into an email.
	 *
	 * @return array{colors:string[],source:string}
	 */
	public static function palette(): array {
		$a = get_option( 'astra-settings', [] );
		$p = is_array( $a ) ? array_values( (array) ( $a['global-color-palette']['palette'] ?? [] ) ) : [];
		$p = array_values( array_filter( $p, static fn( $c ) => is_string( $c ) && preg_match( '/^#[0-9a-fA-F]{3,6}$/', trim( $c ) ) ) );
		if ( $p ) {
			return [ 'colors' => $p, 'source' => __( 'Astra → Global palette', 'dazont-ecom' ) ];
		}
		$out = [];
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$s = (array) wp_get_global_settings();
			foreach ( [ 'theme', 'custom' ] as $origin ) {
				foreach ( (array) ( $s['color']['palette'][ $origin ] ?? [] ) as $row ) {
					if ( ! empty( $row['color'] ) ) {
						$out[] = (string) $row['color'];
					}
				}
			}
		}
		return [ 'colors' => $out, 'source' => __( 'Theme palette (theme.json)', 'dazont-ecom' ) ];
	}

	/**
	 * The themes that keep their appearance somewhere of their own.
	 *
	 * Each reader hands back only what it knows, and is only ever asked about
	 * a value the standard left blank. Adding a theme here is adding one entry
	 * — nothing else in this file changes — and a shop running none of them
	 * loses nothing, because the standard above already answered.
	 *
	 * @return array<string,callable():array<string,mixed>>
	 */
	private static function theme_bridges(): array {
		return [
			__( 'Astra → Customizer', 'dazont-ecom' ) => static function (): array {
				$a = get_option( 'astra-settings', [] );
				if ( ! is_array( $a ) || ! $a ) {
					return [];
				}
				// Astra 4 keeps ONE global palette and every other setting
				// points at it — text-color is not "#5b594e", it is
				// "var(--ast-global-color-3)". Read as a colour that is not a
				// colour, which is why nothing from Astra used to come through
				// and WordPress's default palette answered instead. So the
				// palette is read first and every value resolved against it.
				$palette = (array) ( $a['global-color-palette']['palette'] ?? [] );
				$val = static function ( $v ) use ( $palette ) {
					$v = is_string( $v ) ? trim( $v ) : '';
					if ( '' === $v || 'inherit' === $v ) {
						return '';
					}
					if ( preg_match( '/--ast-global-color-(\d+)/', $v, $m ) ) {
						return (string) ( $palette[ (int) $m[1] ] ?? '' );
					}
					return $v;
				};
				// Astra has renamed several of these across its own versions.
				// Asking for every spelling it has used costs nothing and is
				// the difference between working next year and not.
				$first = static function ( array $keys ) use ( $a, $val ) {
					foreach ( $keys as $k ) {
						$v = $val( $a[ $k ] ?? '' );
						if ( '' !== $v ) {
							return $v;
						}
					}
					return '';
				};
				$size = $a['body-font-size']['desktop'] ?? ( $a['font-size-body']['desktop'] ?? ( $a['font-size-body'] ?? 0 ) );
				$link = $first( [ 'link-color', 'theme-color' ] );
				return array_filter( [
					'ink'     => $first( [ 'text-color', 'body-color' ] ),
					'link'    => $link,
					'sale'    => $link,
					'body'    => $first( [ 'body-font-family', 'font-family-body' ] ),
					'head'    => $first( [ 'headings-font-family', 'font-family-h2', 'font-family-h1' ] ),
					'size'    => ( (int) $size >= 12 && (int) $size <= 22 ) ? (int) $size : '',
					'btn_bg'  => $first( [ 'button-bg-color', 'theme-color' ] ),
					'btn_ink' => $first( [ 'button-color', 'button-text-color' ] ),
					'radius'  => $a['button-radius-fields']['global']['desktop'] ?? ( $a['button-radius'] ?? '' ),
					'card'    => $first( [ 'site-content-background-color', 'content-bg-color' ] ),
					'border'  => $first( [ 'shop-product-border-color', 'single-product-border-color' ] ),
				], static fn( $v ) => '' !== $v && null !== $v );
			},
			__( 'GeneratePress → Customizer', 'dazont-ecom' ) => static function (): array {
				$c = get_option( 'generate_settings', [] );
				return is_array( $c ) ? array_filter( [
					'ink'     => (string) ( $c['text_color'] ?? '' ),
					'link'    => (string) ( $c['link_color'] ?? '' ),
					'card'    => (string) ( $c['background_color'] ?? '' ),
				], static fn( $v ) => '' !== $v ) : [];
			},
			__( 'Kadence → Customizer', 'dazont-ecom' ) => static function (): array {
				$c = get_option( 'kadence_global_palette', '' );
				$c = is_string( $c ) ? json_decode( $c, true ) : $c;
				$p = (array) ( $c['palette'] ?? [] );
				return ! empty( $p[0]['color'] ) ? [ 'link' => (string) $p[0]['color'] ] : [];
			},
			__( 'OceanWP → Customizer', 'dazont-ecom' ) => static function (): array {
				$c = (string) get_theme_mod( 'ocean_primary_color', '' );
				return '' !== $c ? [ 'link' => $c ] : [];
			},
		];
	}

	/**
	 * Whether an email opens on a picture made for it.
	 *
	 * A PERMISSION, not an order. Allowed, the writing describes the picture
	 * an email should open with and the button beside it makes that picture
	 * when somebody presses it — never on its own, because a photograph costs
	 * money and a minute and firing one off on every rewrite spends both on
	 * emails nobody had decided to illustrate. Forbidden, the writing is not
	 * asked for one at all and the button is not drawn.
	 */
	public static function images_on(): bool {
		$s = self::settings();
		return ! array_key_exists( 'images', $s ) || (int) $s['images'] === 1;
	}

	/** How many products sit side by side on a desktop screen. */
	public static function per_row(): int {
		return max( 1, min( 4, (int) self::conf( 'per_row', 2 ) ) );
	}

	/**
	 * Turns [[PRODUCT n]] into the shop's own product block, laid out in rows.
	 *
	 * The writing never sees a card and never writes one: it says where a
	 * product goes and the shop puts it there. That is what makes the card
	 * unchangeable — a model cannot restyle HTML it was never given — and it
	 * is also what keeps the answer short enough to come back whole.
	 *
	 * Markers written one after another are laid out TOGETHER, as many per row
	 * as the settings say. The shop builds that row, not the writing, which is
	 * how "a row of three holding one product and two holes" stops being
	 * possible: a row is only ever as wide as the products actually in it, and
	 * the last one takes the width it needs.
	 *
	 * On a desktop each card is an inline-block no wider than its share of the
	 * column, so they sit side by side; on a narrow screen the second one
	 * wraps under the first. Wrapping alone is not enough, though — a card
	 * that stacks but keeps its 178px cap sits in the middle of a 360px phone
	 * with the screen empty on both sides. So the frame carries one rule that
	 * lifts the cap below 480px, and a stacked card takes the width it has.
	 * Outlook, which ignores inline-block and media queries alike, gets a real
	 * table through the conditional comments and never sees either.
	 *
	 * A marker pointing at a product that does not exist is dropped rather
	 * than left on screen: it is our syntax, not something a reader should
	 * ever meet.
	 *
	 * @param string[] $cards The blocks, in the order the writing was shown them.
	 */
	public static function place_products( string $html, array $cards ): string {
		// The guard has to be at least as forgiving as the pattern below, or a
		// marker written with a space in it survives into the inbox.
		if ( false === strpos( $html, '[[' ) ) {
			return $html;
		}
		$cards = array_values( $cards );
		$per   = self::per_row();
		return (string) preg_replace_callback(
			'/(?:\[\[\s*PRODUCT\s*\d+\s*\]\]\s*)+/i',
			static function ( array $m ) use ( $cards, $per ): string {
				preg_match_all( '/\d+/', $m[0], $nums );
				$run = [];
				foreach ( $nums[0] as $n ) {
					$card = $cards[ (int) $n - 1 ] ?? '';
					if ( '' !== $card ) {
						$run[] = $card;
					}
				}
				return $run ? self::product_rows( $run, $per ) : '';
			},
			$html
		);
	}

	/**
	 * A run of product blocks, laid out N to a row.
	 *
	 * @param string[] $run One card's HTML per product, in order.
	 */
	private static function product_rows( array $run, int $per ): string {
		// The column the body sits in is 600 wide less its 24px inset on each
		// side. Everything here is a share of that.
		$inner = 552;
		$out   = '';
		foreach ( array_chunk( $run, max( 1, $per ) ) as $row ) {
			$n     = count( $row );
			$width = max( 120, (int) floor( $inner / $n ) - 6 );
			$cells = '';
			$ghost = (int) floor( 100 / $n );
			foreach ( $row as $i => $card ) {
				$cells .= '<!--[if mso]><td width="' . $ghost . '%" valign="top"><![endif]-->'
					. '<div class="dze-card" style="display:inline-block;width:100%;max-width:' . $width . 'px;vertical-align:top;">'
					. $card
					. '</div>'
					. ( $i + 1 < $n ? '<!--[if mso]></td><![endif]-->' : '' );
			}
			$out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
				. '<tr><td align="center" style="font-size:0;padding:8px 0;">'
				// font-size:0 on the cell removes the whitespace an inline-block
				// pair would otherwise be pushed apart by; the cards set their
				// own type back.
				. '<!--[if mso]><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><![endif]-->'
				. $cells
				. '<!--[if mso]></td></tr></table><![endif]-->'
				. '</td></tr></table>';
		}
		return $out;
	}

	/**
	 * One product card, in the shop's own style.
	 *
	 * The ONLY place a product is dressed. The email uses it, the settings
	 * screen shows it, and there is nothing to keep in step: what the owner
	 * looks at under "Shop style" is the very block the inbox receives.
	 */
	public static function card_html( string $link, string $img, string $name, string $price ): string {
		$t = self::theme_style();
		$r = (int) $t['radius'];
		return sprintf(
			'<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0">'
			. '<tr><td align="center" style="background:%10$s;border:1px solid %11$s;border-radius:%12$dpx;padding:14px 10px 18px;">'
			. '<a href="%1$s" style="text-decoration:none;color:%6$s;">'
			. '<img src="%2$s" alt="%3$s" width="240" style="display:block;width:100%%;max-width:240px;height:auto;border:0;border-radius:%12$dpx;" />'
			. '<div style="padding:12px 2px 4px;font:400 15px/1.35 %5$s;color:%6$s;">%3$s</div>'
			. '<div style="padding:0 2px 12px;font:400 15px/1.4 %7$s;">%4$s</div></a>'
			. '<a href="%1$s" style="display:inline-block;background:%8$s;color:%13$s;text-decoration:none;'
			. 'padding:11px 20px;border-radius:%14$dpx;font:400 14px %7$s;">%9$s</a>'
			. '</td></tr></table>',
			esc_url( $link ),
			esc_url( $img ),
			esc_html( $name ),
			$price,
			$t['head'],
			esc_attr( $t['ink'] ),
			$t['body'],
			esc_attr( $t['btn_bg'] ),
			esc_html__( 'Shop now', 'dazont-ecom' ),
			esc_attr( $t['card'] ),
			esc_attr( $t['border'] ),
			// A card's corners follow the shop's, but a rounded photograph
			// inside a square card looks like a mistake, so they share one.
			min( $r, 12 ),
			esc_attr( $t['btn_ink'] ),
			$r
		);
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
				// The name travels with the id. Without it the screen has to
				// ask Klaviyo what its own saved setting is called, and says
				// "RpAZid" for twelve hours whenever the cache has expired.
				if ( array_key_exists( $id_field . '_name', $in ) ) {
					$out[ $id_field . '_name' ] = sanitize_text_field( (string) $in[ $id_field . '_name' ] );
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
		if ( array_key_exists( 'img_prompt', $in ) ) {
			$text = trim( sanitize_textarea_field( (string) $in['img_prompt'] ) );
			$out['img_prompt'] = ( $text === trim( self::default_image_prompt() ) ) ? '' : $text;
		}
		if ( array_key_exists( 'plan_prompt', $in ) ) {
			$text = trim( sanitize_textarea_field( (string) $in['plan_prompt'] ) );
			$out['plan_prompt'] = ( $text === trim( self::default_plan_prompt() ) ) ? '' : $text;
		}
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
		if ( array_key_exists( 'per_row', $in ) ) {
			$out['per_row'] = max( 1, min( 4, (int) $in['per_row'] ) );
		}
		// A checkbox the submitted section owns: unticked it posts nothing, so
		// it is read from the section's own marker rather than from itself.
		if ( ! empty( $in['form'] ) ) {
			$out['images'] = ! empty( $in['images'] ) ? 1 : 0;
			// The list of types belongs to this form and to no other. Emptied
			// on purpose, it means the shipped list — the same thing the
			// "Restore default" beside it does, so the two agree.
			$out['types'] = self::clean_types( (array) ( $in['types'] ?? [] ) );
		}
		if ( array_key_exists( 'test_to', $in ) ) {
			$to = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) $in['test_to'] ) ) ) );
			$out['test_to'] = implode( ', ', array_slice( $to, 0, 5 ) );
		}
		// The frame. It is not typed any more — it is read from Klaviyo by the
		// button beside it and carried back by the form, so the only thing
		// checked here is that what came back still has a place to put the
		// email in it. A frame with no such place is a header with nothing
		// under it, so the shop keeps the one it had and is told why.
		if ( array_key_exists( 'shell', $in ) ) {
			$shell = trim( (string) $in['shell'] );
			if ( '' === $shell ) {
				$out['shell'] = '';
			} elseif ( false === strpos( $shell, self::BODY_MARK ) ) {
				add_settings_error(
					self::OPT,
					'dze_klav_shell',
					__( 'The header and footer were not saved: what came back has nowhere to put the email. Read the template again.', 'dazont-ecom' )
				);
			} else {
				$out['shell'] = $shell;
				// Which template it came from, and when — the one line that
				// tells the owner whether he is looking at last month's header.
				if ( array_key_exists( 'frame_id', $in ) ) {
					$out['frame_id'] = sanitize_text_field( (string) $in['frame_id'] );
				}
				if ( array_key_exists( 'frame_name', $in ) ) {
					$out['frame_name'] = sanitize_text_field( (string) $in['frame_name'] );
				}
				if ( $shell !== trim( (string) ( $old['shell'] ?? '' ) ) ) {
					$out['frame_read'] = time();
				}
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
			. "- Return the body only: no <html>, <head>, <body>, no logo, no footer, no unsubscribe line. The shop's header and footer are added around what you write.\n"
			. "- Your canvas is about 550 pixels wide: the body sits in a column already inset by 24px on every side, so you never add an outer margin of your own. A picture given width=\"550\" fills it.\n"
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
	 * What to call a chosen list, segment or template on screen.
	 *
	 * The account's own name for it when the cache holds one, the name saved
	 * beside the choice when it does not, and only then the raw id. A screen
	 * that answers "RpAZid" has not lost the setting, but it has lost the
	 * owner, which comes to the same thing.
	 */
	public static function label_for( string $id, array $cat, string $remembered = '' ): string {
		if ( '' === $id ) {
			return '';
		}
		$known = (string) ( $cat['audiences'][ $id ] ?? ( $cat['templates'][ $id ] ?? '' ) );
		if ( '' !== $known ) {
			return $known;
		}
		return '' !== $remembered ? $remembered : $id;
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

	// =========================================================================
	// The shell — the header and the footer, as ONE piece of HTML with a
	// marker where the email's own content goes.
	//
	// It used to be drawn here: a logo row, three promises and a legal line,
	// this plugin's idea of the shop. It was a reconstruction, and a
	// reconstruction is a second version of something that already exists —
	// the day the real header changed, the emails kept the old one and nobody
	// could see why. So there is no version of ours any more. The frame is
	// read from the Klaviyo template the owner maintains, or the module says
	// it cannot work yet. One frame, one place, no drift.
	// =========================================================================

	/**
	 * The frame in force, or an empty string while none has been read.
	 *
	 * The frame is a SNAPSHOT of the owner's Klaviyo template, taken the day
	 * he pressed Read it. Anything of ours baked into that snapshot is frozen
	 * with it: the column the email is written into used to be stored inside
	 * the frame, so when its margin changed, every shop that had already read
	 * its template kept the old one for ever and had no way of knowing why.
	 * The frame holds the marker now and the column is put on at build time —
	 * and a frame saved the old way is unwrapped here, so nobody has to read
	 * his template again to get a fix.
	 */
	public static function shell(): string {
		$saved = trim( (string) ( self::settings()['shell'] ?? '' ) );
		if ( '' === $saved || false === strpos( $saved, self::BODY_MARK ) ) {
			return '';
		}
		return self::unwrap_slot( $saved );
	}

	/**
	 * Strips a column this plugin baked into a frame before it knew better.
	 *
	 * Exact rather than clever: the opening and the closing are our own
	 * constants, so the cut is the one we made. A frame that does not carry
	 * them is handed back untouched.
	 */
	private static function unwrap_slot( string $frame ): string {
		// Matched on the class, not on the whole opening tag: the styles inside
		// it are exactly what changes between versions, so a frame saved before
		// the margin existed would never match its own successor and would end
		// up wearing TWO columns, one padded and one not.
		$at = strpos( $frame, '<div class="' . self::SLOT_CLASS );
		if ( false === $at ) {
			return $frame;
		}
		$mark = strpos( $frame, self::BODY_MARK, $at );
		$end  = ( false === $mark ) ? false : strpos( $frame, self::SLOT_CLOSE, $mark );
		if ( false === $end ) {
			return $frame;
		}
		return substr( $frame, 0, $at ) . self::BODY_MARK . substr( $frame, $end + strlen( self::SLOT_CLOSE ) );
	}

	/**
	 * A stand-in body, so the frame preview shows a frame with something in it.
	 *
	 * It exists only to be looked at on the settings screen: it is never sent,
	 * never saved and never shown to a customer. So it asks the shop nothing —
	 * no query, no product, no option — because a preview that costs a
	 * database round-trip on every page load is a preview that costs more than
	 * it is worth.
	 *
	 * It is also the one honest picture of the convention the prompt writes
	 * against: the content area is 600 pixels edge to edge with no padding of
	 * ours, so a full-width band touches both sides and anything meant to be
	 * read sets its own inset.
	 */
	public static function sample_body(): string {
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
			. '<tr><td style="background:#e9e9e4;height:220px;text-align:center;vertical-align:middle;'
			. 'font:400 13px/1.4 Helvetica,Arial,sans-serif;color:#8a887e;">'
			. esc_html__( 'the email\'s picture, edge to edge', 'dazont-ecom' )
			. '</td></tr>'
			. '<tr><td style="padding:24px 24px 0;">'
			. '<h2 style="margin:0 0 12px;">' . esc_html__( 'The headline the email opens on', 'dazont-ecom' ) . '</h2>'
			. '<p style="margin:0 0 16px;font:400 16px/1.45 Helvetica,Arial,sans-serif;">'
			. esc_html__( 'Two or three sentences saying what the offer is, what it covers and when it ends. Everything on this band — the words, the products, the buttons and the spacing around them — is written by the email prompt.', 'dazont-ecom' )
			. '</p></td></tr>'
			. '<tr><td style="padding:0 24px 28px;">'
			. '<a href="#" style="display:inline-block;background:#5B594E;color:#ffffff;text-decoration:none;'
			. 'padding:12px 22px;font:400 14px Helvetica,Arial,sans-serif;">'
			. esc_html__( 'Shop the sale', 'dazont-ecom' ) . '</a>'
			. '</td></tr></table>';
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
		$ids   = self::best_sellers( self::window_days(), $limit, array_map( 'absint', (array) ( $rule['category_ids'] ?? [] ) ), $rule );
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
		$t = self::theme_style();
		return sprintf(
			'<span style="color:%3$s;text-decoration:line-through;">%1$s</span> <span style="color:%4$s;font-weight:700;">%2$s</span>',
			wp_kses_post( wc_price( $reg ) ),
			wp_kses_post( wc_price( $now ) ),
			esc_attr( $t['muted'] ),
			esc_attr( $t['sale'] )
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
	 * Which stretch of the shop's history a promotion should be read from.
	 *
	 * A promotion is written weeks before it runs, and the products that sell
	 * in the week it is WRITTEN are not the products that will sell in the
	 * week it OPENS. A New Year sale drafted in August, read off the last
	 * fortnight, is a summer catalogue with a December headline on it.
	 *
	 * So when a promotion opens in a different part of the year, the shop is
	 * asked about the SAME PART OF THE YEAR, one year earlier — 26 December
	 * looks at last 26 December, not at last August. The window is widened by
	 * the promotion's own length and a week on each side, because a sale is
	 * rarely the only week its goods sell in.
	 *
	 * A promotion opening soon is a different question: the recent window is
	 * the right one, and it is the one used.
	 *
	 * @return array{from:int,to:int,season:bool,label:string}
	 */
	public static function sellers_window( array $rule = [] ): array {
		$now   = (int) current_time( 'timestamp' );
		$days  = self::window_days();
		$plain = [
			'from'   => $now - $days * DAY_IN_SECONDS,
			'to'     => $now,
			'season' => false,
			'label'  => sprintf(
				/* translators: %d: number of days */
				_n( 'the last %d day', 'the last %d days', $days, 'dazont-ecom' ),
				$days
			),
		];
		$start = strtotime( self::just_day( (string) ( $rule['start'] ?? '' ) ) ?: '' );
		if ( ! $start ) {
			return $plain;
		}
		// Opening within three weeks: this season IS that season.
		if ( abs( $start - $now ) <= 21 * DAY_IN_SECONDS ) {
			return $plain;
		}
		$end  = strtotime( self::just_day( (string) ( $rule['end'] ?? '' ) ) ?: '' ) ?: $start;
		$from = strtotime( '-1 year', $start ) - 7 * DAY_IN_SECONDS;
		$to   = strtotime( '-1 year', $end ) + 7 * DAY_IN_SECONDS;
		if ( ! $from || ! $to || $to <= $from ) {
			return $plain;
		}
		return [
			'from'   => $from,
			'to'     => $to,
			'season' => true,
			'label'  => sprintf(
				/* translators: 1: first day, 2: last day */
				__( '%1$s to %2$s, a year ago', 'dazont-ecom' ),
				wp_date( 'j M', $from ),
				wp_date( 'j M Y', $to )
			),
		];
	}

	/** The products the shop sold between two moments, best first. */
	private static function sold_between( int $from, int $to, int $cap = 60 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT product_id FROM {$table} WHERE date_created >= %s AND date_created <= %s GROUP BY product_id ORDER BY SUM(product_qty) DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce's own table name.
			gmdate( 'Y-m-d H:i:s', $from ),
			gmdate( 'Y-m-d H:i:s', $to ),
			$cap
		) );
		return array_map( 'absint', (array) $rows );
	}

	/**
	 * The products the shop actually sold over the right window, best first.
	 *
	 * @param int[] $categories Restrict to these product categories, when the event names any.
	 * @param array $rule       The promotion, so the window can follow its season.
	 *
	 * @return int[]
	 */
	private static function best_sellers( int $days, int $limit, array $categories = [], array $rule = [] ): array {
		$limit  = max( 1, min( 9, $limit ) );
		$window = self::sellers_window( $rule );
		$ids    = self::sold_between( (int) $window['from'], (int) $window['to'] );

		// A shop younger than a year, or a quiet season last year, has nothing
		// to say about it. Recent sales answer rather than nothing — the wrong
		// season beats an empty email — and the screen says which was used.
		if ( ! $ids && $window['season'] ) {
			$now = (int) current_time( 'timestamp' );
			$ids = self::sold_between( $now - max( 1, $days ) * DAY_IN_SECONDS, $now );
		}
		// Nothing sold at all (a new shop, or Analytics not synced): the
		// catalogue's own popularity answers.
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
		$shell = self::shell();
		if ( '' === $shell ) {
			// Loudly, and only ever on a click: no frame means no email, and
			// the alternative — sending the body on its own, with no header
			// and no unsubscribe line — is worse than not sending at all.
			throw new RuntimeException( __( 'No header and footer yet. Settings → Email campaigns → Header and footer: choose your Klaviyo template and press Read it.', 'dazont-ecom' ) );
		}
		$html = self::with_mobile_rule( str_replace( self::BODY_MARK, self::slot( $body ), $shell ) );
		return $preview ? self::readable( $html ) : $html;
	}

	/**
	 * The one rule of ours the frame carries, put in its head at build time.
	 *
	 * A product card is an inline-block capped at its share of the column, so
	 * two or three sit side by side on a desktop and wrap on a phone. Wrapping
	 * is only half of it: a card that stacks and keeps its 178px cap sits in
	 * the middle of a 360px screen with the paper empty on both sides. Only a
	 * media query can lift that cap, and a media query only works in the
	 * document's HEAD — a <style> in the body is thrown away by Gmail.
	 *
	 * It goes in at build time rather than into the stored frame, for the
	 * reason the column is not stored either: a rule frozen into somebody's
	 * snapshot is a rule no later fix can reach.
	 */
	public static function with_mobile_rule( string $html ): string {
		if ( false !== strpos( $html, 'dze-mobile' ) || false === stripos( $html, '</head>' ) ) {
			return $html;
		}
		$style = '<style type="text/css" id="dze-mobile">'
			. '@media only screen and (max-width:480px){'
			. '.dze-card{max-width:100%!important;width:100%!important;}'
			. '}</style>';
		$at = stripos( $html, '</head>' );
		return substr( $html, 0, $at ) . $style . substr( $html, $at );
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
		$shell = self::shell();
		if ( '' === $shell ) {
			return '';
		}
		// The column goes on HERE, with the marker still inside it, so the
		// browser can keep doing the one thing it does — put the body where
		// the marker is — and still show the margin the email is sent with.
		$shell = self::with_mobile_rule( str_replace( self::BODY_MARK, self::slot( self::BODY_MARK ), $shell ) );
		$keep  = '@@DZE_BODY@@';
		return str_replace( $keep, self::BODY_MARK, self::readable( str_replace( self::BODY_MARK, $keep, $shell ) ) );
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

	/**
	 * The types an email of a promotion can be, in the order they happen.
	 *
	 * A list, not four fixed slots: the shop edits it under Settings → Email
	 * campaigns, because "warm-up, launch, reminder, last chance" is one
	 * shop's rhythm and not a law. Each type is a NAME and the day it falls
	 * on, said the only two ways a promotion can say it — so many days from
	 * the start, or so many days from the end. Never both: a date measured
	 * from both ends of a window is two dates.
	 *
	 * The type is what the writing is told this email is. It is chosen on the
	 * email, it is not deduced from its date — that was the old behaviour and
	 * it meant the choice on screen changed nothing at all.
	 */
	public static function shipped_kinds(): array {
		return [
			[ 'id' => 'warm',     'label' => __( 'Warm-up', 'dazont-ecom' ),     'anchor' => 'start', 'offset' => -2 ],
			[ 'id' => 'launch',   'label' => __( 'Launch', 'dazont-ecom' ),      'anchor' => 'start', 'offset' => 0 ],
			[ 'id' => 'reminder', 'label' => __( 'Reminder', 'dazont-ecom' ),    'anchor' => 'start', 'offset' => 5 ],
			[ 'id' => 'last',     'label' => __( 'Last chance', 'dazont-ecom' ), 'anchor' => 'end',   'offset' => -2 ],
		];
	}

	/** The types this shop uses, keyed by id. Empty settings = the shipped list. */
	public static function kinds(): array {
		$rows = self::settings()['types'] ?? null;
		$out  = self::kinds_from( is_array( $rows ) ? $rows : [] );
		return $out ?: self::kinds_from( self::shipped_kinds() );
	}

	/** @param array $rows Raw rows, from the settings or from the shipped list. */
	private static function kinds_from( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id    = sanitize_key( (string) ( $row['id'] ?? '' ) );
			$label = trim( (string) ( $row['label'] ?? '' ) );
			if ( '' === $id || '' === $label || isset( $out[ $id ] ) ) {
				continue;
			}
			$anchor = ( 'end' === ( $row['anchor'] ?? '' ) ) ? 'end' : 'start';
			$offset = max( -90, min( 90, (int) ( $row['offset'] ?? 0 ) ) );
			$out[ $id ] = [
				'label'  => $label,
				'anchor' => $anchor,
				'offset' => $offset,
				'when'   => self::when_caption( $anchor, $offset ),
			];
		}
		return $out;
	}

	/**
	 * The types as the settings form posted them.
	 *
	 * The form talks in whole days and a direction ("2 days before it ends"),
	 * because that is how a shop says it; what is stored is an anchor and a
	 * signed offset, because that is what a date is worked out from. One
	 * translation, in one place.
	 */
	public static function clean_types( array $rows ): array {
		$out  = [];
		$seen = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = trim( sanitize_text_field( (string) ( $row['label'] ?? '' ) ) );
			if ( '' === $label ) {
				continue; // a type with no name is not one.
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = sanitize_key( sanitize_title( $label ) );
			}
			if ( '' === $id ) {
				$id = 't' . ( count( $out ) + 1 );
			}
			while ( isset( $seen[ $id ] ) ) {
				$id .= '2';
			}
			$seen[ $id ] = true;
			$days = max( 0, min( 90, (int) ( $row['days'] ?? 0 ) ) );
			$mode = (string) ( $row['mode'] ?? 'sa' );
			$out[] = [
				'id'     => $id,
				'label'  => mb_substr( $label, 0, 60 ),
				'anchor' => 'eb' === $mode ? 'end' : 'start',
				'offset' => 'sa' === $mode ? $days : -$days,
			];
		}
		return $out;
	}

	/**
	 * What the plugin sends WITH this prompt, listed for the popup that shows
	 * it. Written beside the code that builds the call, so the list and the
	 * call are read and changed together.
	 *
	 * @return string[]
	 */
	public static function prompt_data( string $id ): array {
		if ( 'promo_email_img' === $id ) {
			return [
				__( 'The promotion: its title, the days it runs and its discount.', 'dazont-ecom' ),
				__( 'Which email the picture opens, and that email\'s subject line — once it is written.', 'dazont-ecom' ),
				__( 'Up to four real photographs of the promotion\'s own best-sellers, as the images to work from.', 'dazont-ecom' ),
				__( 'The rule no prompt overrides: keep those products exactly as they are, add nothing that is not in them, and no text anywhere in the image.', 'dazont-ecom' ),
			];
		}
		if ( 'promo_plan' === $id ) {
			return [
				__( 'The promotion: its title, its discount, the day it opens, the day it closes, how many days that is, whether it covers the whole shop or some categories, and today\'s date.', 'dazont-ecom' ),
				__( 'The shop, read from itself: its name, its tagline, its best-selling categories and products, its price range and its currency.', 'dazont-ecom' ),
				__( 'The answer format — one date and one angle per email, nothing else.', 'dazont-ecom' ),
			];
		}
		return [
			__( 'The promotion: its title, its discount, its dates, how many days it runs, what it covers, and the shop address.', 'dazont-ecom' ),
			__( 'Which email this is: its type, what that type means, the day it goes out, and its place in the sequence.', 'dazont-ecom' ),
			__( 'The angle the campaign plan wrote for this one, when a plan was run.', 'dazont-ecom' ),
			__( 'The other emails of the promotion: their subject, how they opened, and which products they leaned on — so this one repeats none of it.', 'dazont-ecom' ),
			__( 'The shop, read from itself: name, tagline, best-selling categories and products, price range, currency.', 'dazont-ecom' ),
			__( 'The products it may show: the best-sellers of the window set below, with their names, links, photographs and both prices — and the rule that a product is placed with [[PRODUCT n]] and never written by hand.', 'dazont-ecom' ),
			__( 'The opening picture: the one this email already has, or the permission to describe one.', 'dazont-ecom' ),
			__( 'Your theme\'s own type and colours: heading font, text font, text colour, link colour, text size.', 'dazont-ecom' ),
			__( 'The shop\'s own rules, which override the instructions: the header and footer already carry the service promises, the body is written inside the column it is given, a product is never written by hand.', 'dazont-ecom' ),
			__( 'The language to write in, and the answer format — subject, preview line, picture, body.', 'dazont-ecom' ),
		];
	}

	/** The id of the first type — what an email with no type of its own is. */
	public static function first_kind(): string {
		$kinds = self::kinds();
		return isset( $kinds['launch'] ) ? 'launch' : (string) ( array_key_first( $kinds ) ?? 'launch' );
	}

	/** When a type falls, in words, for the screen and for the writing. */
	public static function when_caption( string $anchor, int $offset ): string {
		if ( 'end' === $anchor ) {
			if ( 0 === $offset ) {
				return __( 'The day it closes', 'dazont-ecom' );
			}
			return $offset < 0
				/* translators: %d: number of days */
				? sprintf( _n( '%d day before it closes', '%d days before it closes', abs( $offset ), 'dazont-ecom' ), abs( $offset ) )
				/* translators: %d: number of days */
				: sprintf( _n( '%d day after it closes', '%d days after it closes', $offset, 'dazont-ecom' ), $offset );
		}
		if ( 0 === $offset ) {
			return __( 'The day it opens', 'dazont-ecom' );
		}
		return $offset < 0
			/* translators: %d: number of days */
			? sprintf( _n( '%d day before it opens', '%d days before it opens', abs( $offset ), 'dazont-ecom' ), abs( $offset ) )
			/* translators: %d: number of days */
			: sprintf( _n( '%d day in', '%d days in', $offset, 'dazont-ecom' ), $offset );
	}

	/**
	 * The rule a type follows, in the shorthand a shop owner reads at a glance.
	 *
	 * Shown on each option of the menu, because "Reminder" alone is not a
	 * choice — "Reminder · J+5" is. It is computed from the type's own two
	 * figures, so a type cannot be moved without its caption moving with it.
	 *
	 * @param array $meta One row of kinds().
	 */
	public static function day_rule( array $meta ): string {
		$offset = (int) ( $meta['offset'] ?? 0 );
		if ( 'end' === ( $meta['anchor'] ?? 'start' ) ) {
			if ( 0 === $offset ) {
				return __( 'end', 'dazont-ecom' );
			}
			return __( 'end', 'dazont-ecom' ) . ( $offset < 0 ? ' − ' : ' + ' ) . abs( $offset );
		}
		if ( 0 === $offset ) {
			return 'J0';
		}
		return $offset > 0 ? 'J+' . $offset : 'J−' . abs( $offset );
	}

	/**
	 * The moment a type falls on for this promotion, raw.
	 *
	 * Raw on purpose: default_when() pushes a day already gone forward,
	 * because a send date in the past is not one, but a comparison must be
	 * made on the real day or every past promotion has all its types landing
	 * on tomorrow.
	 */
	private static function type_ts( array $meta, array $rule ): int {
		$start = strtotime( (string) ( $rule['start'] ?? '' ) . ' 09:00:00' );
		$end   = strtotime( (string) ( $rule['end'] ?? '' ) . ' 09:00:00' );
		if ( ! $start ) {
			$start = time() + DAY_IN_SECONDS;
		}
		$offset = (int) ( $meta['offset'] ?? 0 ) * DAY_IN_SECONDS;
		if ( 'end' === ( $meta['anchor'] ?? 'start' ) ) {
			return (int) ( $end ? max( $start, $end + $offset ) : $start );
		}
		return (int) ( $start + $offset );
	}

	/** The day an email of this type goes out, from the promotion's own window. */
	public static function default_when( string $kind, array $rule ): string {
		// A type the shop has since deleted still has to answer with a day:
		// the promotion opens, and that is the day.
		$ts = self::type_ts( self::kinds()[ $kind ] ?? [ 'anchor' => 'start', 'offset' => 0 ], $rule );
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
				'kind'    => self::first_kind(),
				'when'    => self::default_when( self::first_kind(), $rule ),
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
			$when = self::just_day( (string) ( $email['when'] ?? '' ) );
			// The TYPE the email was given, not one deduced from its date.
			// Deducing it is what made the menu on screen decorative: an email
			// called a warm-up was written as a launch because its day said so.
			// A date only answers for an email that has no type any more —
			// written before this existed, or of a type the shop deleted.
			$kind = isset( $kinds[ (string) ( $email['kind'] ?? '' ) ] )
				? (string) $email['kind']
				: ( '' !== $when && $rule ? self::kind_for( $when, $rule ) : self::first_kind() );
			$out[ (string) $id ] = [
				'kind'    => $kind,
				'name'    => (string) ( $email['name'] ?? '' ),
				// Whether writing this one also makes its picture.
				'want_picture' => ! empty( $email['want_picture'] ),
				'angle'   => (string) ( $email['angle'] ?? '' ),
				'when'    => '' !== $when ? $when : self::default_when( $kind, $rule ),
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

	/**
	 * The other emails of the same promotion, as the writing needs to know them.
	 *
	 * A reminder that repeats the launch is the commonest way an email
	 * sequence goes wrong, and it cannot be prevented by instructions alone:
	 * "do not repeat the announcement" means nothing to somebody who has not
	 * read it. So each email is shown what its neighbours actually said —
	 * their subject, how they opened, and WHICH PRODUCTS they leaned on, which
	 * is the repetition a reader notices first.
	 *
	 * Short on purpose. The opening line and the product names carry almost
	 * all of the value; the whole body would multiply the cost of every email
	 * by the number of emails and buy very little more.
	 *
	 * @param array $mat The material, so a product can be recognised by its link.
	 */
	private static function siblings_brief( string $rule_id, array $rule, string $email_id, array $mat ): string {
		$all = self::emails_for( $rule_id, $rule );
		unset( $all[ $email_id ] );
		if ( ! $all ) {
			return '';
		}
		$fmt  = get_option( 'date_format' ) ?: 'Y-m-d';
		$out  = '';
		$n    = 0;
		foreach ( $all as $mail ) {
			if ( ++$n > 6 ) {
				break;
			}
			$ts   = strtotime( (string) ( $mail['when'] ?? '' ) );
			$line = $n . '. "' . self::email_name( $mail ) . '"'
				. ( $ts ? ' — ' . wp_date( $fmt, $ts ) : '' );
			$body = trim( wp_strip_all_tags( (string) ( $mail['body'] ?? '' ) ) );
			if ( '' === $body ) {
				$out .= $line . ' — ' . __( 'not written yet', 'dazont-ecom' ) . "\n";
				continue;
			}
			$subject = trim( (string) ( $mail['subject'] ?? '' ) );
			$out    .= $line . ( '' !== $subject ? ' — subject: "' . $subject . '"' : '' ) . "\n";
			$out    .= '   opens: ' . mb_substr( preg_replace( '/\s+/u', ' ', $body ), 0, 180 ) . "…\n";
			// Which of the shortlist it showed, found by the one thing a
			// product block always carries: the product's own link.
			$shown = [];
			foreach ( (array) ( $mat['links'] ?? [] ) as $name => $link ) {
				if ( '' !== $link && false !== strpos( (string) $mail['body'], $link ) ) {
					$shown[] = $name;
				}
			}
			if ( $shown ) {
				$out .= '   products shown: ' . implode( ', ', array_slice( $shown, 0, 8 ) ) . "\n";
			}
		}
		return $out;
	}

	/**
	 * Which TYPE a day falls closest to.
	 *
	 * Only ever asked about an email that has no type of its own: one written
	 * before types existed, one the plan has just dated, one whose type the
	 * shop has since deleted. It answers with a type that really is in the
	 * list — the one whose own day is nearest — rather than with a name
	 * hard-coded here that the shop may well have renamed.
	 */
	public static function kind_for( string $when, array $rule ): string {
		$day   = strtotime( self::just_day( $when ) ?: '' );
		$kinds = self::kinds();
		if ( ! $day || ! $kinds ) {
			return self::first_kind();
		}
		$best = '';
		$gap  = PHP_INT_MAX;
		foreach ( $kinds as $id => $meta ) {
			$ts = self::type_ts( $meta, $rule );
			if ( ! $ts ) {
				continue;
			}
			$this_gap = abs( $ts - $day );
			if ( $this_gap < $gap ) {
				$gap  = $this_gap;
				$best = (string) $id;
			}
		}
		return '' !== $best ? $best : self::first_kind();
	}

	/**
	 * What to call one email: its TYPE.
	 *
	 * There is no name beside the type any more. A free name and a menu of
	 * types are two ways of saying one thing, and the owner had to answer the
	 * same question twice — while the answer that mattered to the writing was
	 * the one he could not see.
	 */
	public static function email_name( array $mail ): string {
		$label = trim( (string) ( self::kinds()[ (string) ( $mail['kind'] ?? '' ) ]['label'] ?? '' ) );
		if ( '' !== $label ) {
			return $label;
		}
		// A type the shop has deleted, or an email written before types
		// existed: what it was called then, failing that its subject.
		foreach ( [ 'name', 'subject' ] as $key ) {
			$try = trim( (string) ( $mail[ $key ] ?? '' ) );
			if ( '' !== $try ) {
				return $try;
			}
		}
		return __( 'Untitled email', 'dazont-ecom' );
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
		// A screen that showed the emails says so, whether it ended up with
		// any or none. Without that marker an emptied list is indistinguishable
		// from a form that never carried the section, and the guard below —
		// which exists so another form cannot wipe the emails — would read
		// "the owner deleted the last one" as "this is not about emails" and
		// leave it in place. That is exactly what it did: deleting the only
		// email of a promotion never took, because deleting the row is what
		// removes dze_email from the form.
		$shown = ! empty( $in['dze_email_shown'] );
		$rows   = ( isset( $in['dze_email'] ) && is_array( $in['dze_email'] ) ) ? $in['dze_email'] : [];
		if ( ! $shown && ! $rows ) {
			return; // the section was not on the screen: nothing to say about it.
		}
		$kinds = self::kinds();
		$live  = self::emails_for( $rule_id, $rule );
		$out   = [];
		foreach ( $rows as $email_id => $posted ) {
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
			$when = array_key_exists( 'when', $posted )
				? self::just_day( sanitize_text_field( (string) $posted['when'] ) )
				: (string) ( $was['when'] ?? '' );
			// The type is a CHOICE, so it comes from the form — checked
			// against the list the shop actually has, never trusted as it
			// arrives. Only an email that carries none is dated into one.
			$posted_kind = array_key_exists( 'kind', $posted ) ? sanitize_key( (string) $posted['kind'] ) : (string) ( $was['kind'] ?? '' );
			$kind        = isset( $kinds[ $posted_kind ] ) ? $posted_kind : self::kind_for( $when, $rule );
			$body = array_key_exists( 'body', $posted ) ? self::clean_html( (string) $posted['body'] ) : (string) ( $was['body'] ?? '' );
			if ( '' === trim( $body ) ) {
				$body = (string) ( $was['body'] ?? '' );
			}
			$out[ $email_id ] = [
				'kind'    => $kind,
				// The brief the plan wrote. The screen never posts it, so it
				// survives every save of the email it belongs to.
				'angle'   => (string) ( $was['angle'] ?? '' ),
				'when'    => '' !== $when ? $when : self::default_when( $kind, $rule ),
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
				'want_picture' => array_key_exists( 'want_picture', $posted )
					? ! empty( $posted['want_picture'] )
					: ! empty( $was['want_picture'] ),
				'draft'   => (array) ( $was['draft'] ?? [] ),
			];
		}
		$all = get_option( self::OPT_COPY, [] );
		$all = is_array( $all ) ? $all : [];
		// MERGED, never substituted: what a promotion holds beside its emails
		// — what its pictures have cost so far — is not on this form, and a
		// save of the emails must not throw it away.
		$all[ $rule_id ] = array_merge( (array) ( $all[ $rule_id ] ?? [] ), [ 'emails' => $out ] );
		update_option( self::OPT_COPY, $all, false );
	}

	/**
	 * Removes one email of a promotion, at once.
	 *
	 * Deleting used to wait for the event's Save while WRITING an email saved
	 * itself immediately, and two gestures that disagree about when they take
	 * effect is one gesture too many: the row vanished, the owner reloaded,
	 * and the email the generation had already stored came back. One rule now
	 * — an email appears when it is written and disappears when it is
	 * removed, both without waiting for anything.
	 */
	public static function forget_email( string $rule_id, string $email_id ): void {
		$all = get_option( self::OPT_COPY, [] );
		$all = is_array( $all ) ? $all : [];
		$one = (array) ( $all[ $rule_id ] ?? [] );
		// Materialised first, exactly as put_email() does it, or a promotion
		// still carrying the legacy single copy would rebuild the email this
		// call is meant to take away.
		$one['emails'] = self::emails_for( $rule_id );
		unset( $one['subject'], $one['preview'], $one['body'], $one['picture'] );
		unset( $one['emails'][ $email_id ] );
		$all[ $rule_id ] = $one;
		update_option( self::OPT_COPY, $all, false );
	}

	/** Removes the email the screen just dropped. */
	public static function ajax_drop(): void {
		self::guard();
		[ $rule_id, , $email_id ] = self::target();
		if ( '' === $email_id ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to remove.', 'dazont-ecom' ) ] );
		}
		self::forget_email( $rule_id, $email_id );
		wp_send_json_success( [ 'removed' => $email_id ] );
	}

	/** Remembers the picture made for one email. */
	public static function keep_picture( string $rule_id, string $email_id, string $url ): void {
		$url   = esc_url_raw( $url );
		$write = [ 'picture' => $url ];
		// And into the body, where the writing left a hole for it. Storing the
		// picture beside the email and leaving "dze:picture" in the text meant
		// every later reader — the draft, the test send, the screen after a
		// reload — had to remember to put the two back together, and one of
		// them always forgot.
		$body = (string) ( self::email_for( $rule_id, $email_id )['body'] ?? '' );
		if ( '' !== $url && '' !== $body && false !== strpos( $body, self::PICTURE_MARK ) ) {
			$write['body'] = str_replace( self::PICTURE_MARK, $url, $body );
		}
		self::put_email( $rule_id, $email_id, $write );
	}

	/**
	 * What a promotion has spent on pictures, and how many it has made.
	 *
	 * Beside the button that spends the next one: a promotion whose pictures
	 * keep coming back wrong is a promotion to stop paying for, and that
	 * decision is taken while looking at the screen, not at a monthly total.
	 *
	 * @return array{shots:int,spend:float,label:string}
	 */
	public static function charge_promo( string $rule_id, float $cost ): array {
		$all = get_option( self::OPT_COPY, [] );
		$all = is_array( $all ) ? $all : [];
		$one = (array) ( $all[ $rule_id ] ?? [] );
		if ( $cost > 0 ) {
			$one['spend'] = round( (float) ( $one['spend'] ?? 0 ) + $cost, 4 );
			$one['shots'] = 1 + (int) ( $one['shots'] ?? 0 );
			$all[ $rule_id ] = $one;
			update_option( self::OPT_COPY, $all, false );
		}
		return self::promo_spend( $rule_id );
	}

	/** @return array{shots:int,spend:float,label:string} */
	public static function promo_spend( string $rule_id ): array {
		$all   = get_option( self::OPT_COPY, [] );
		$one   = is_array( $all ) ? (array) ( $all[ $rule_id ] ?? [] ) : [];
		$shots = (int) ( $one['shots'] ?? 0 );
		$spend = (float) ( $one['spend'] ?? 0 );
		return [
			'shots' => $shots,
			'spend' => round( $spend, 2 ),
			'label' => $shots ? sprintf(
				/* translators: 1: number of pictures, 2: what they cost */
				_n( '%1$d picture · %2$s', '%1$d pictures · %2$s', $shots, 'dazont-ecom' ),
				$shots,
				'$' . number_format_i18n( $spend, 2 )
			) : '',
		];
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
		// wp_kses judges every src against WordPress's list of allowed
		// protocols, and "dze:" is not on it: the marker came out as a bare
		// "picture", a relative URL pointing at nothing. The browser then
		// could not find the marker it was meant to swap, decided the email
		// had no picture in it and PREPENDED one — which is how an email ended
		// up with a photograph at the top and a broken image with the alt text
		// still sitting where the writing had put it. The protocol is allowed
		// for the length of this call and taken straight back out.
		$allow = static function ( array $protocols ): array {
			$protocols[] = strtok( self::PICTURE_MARK, ':' );
			return $protocols;
		};
		add_filter( 'kses_allowed_protocols', $allow );
		try {
			$clean = wp_kses( $html, $allowed );
		} finally {
			remove_filter( 'kses_allowed_protocols', $allow );
		}
		return trim( $clean );
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
			$day = self::default_when( self::first_kind(), $rule );
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
		$kind    = (string) ( $copy['kind'] ?? self::first_kind() );
		// The campaign carries the promotion's name AND which of its emails
		// this is, so the account's campaign list can be read at a glance.
		$name    = trim( (string) ( $rule['title'] ?? __( 'Promotion', 'dazont-ecom' ) ) )
			. ' — ' . self::email_name( $copy );
		// What is on screen wins over what was last saved: the draft is made of
		// the email the owner is looking at.
		$body    = ( null !== ( $in['body'] ?? null ) && '' !== trim( (string) $in['body'] ) )
			? (string) $in['body']
			: self::body_for( $rule, $rule_id, $email_id );
		$html    = self::layout( self::settle_picture( $body, $rule, (string) ( $copy['picture'] ?? '' ) ) );
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
			// The template menu is built when the page is drawn, so without
			// this the button filled the audiences and left it empty until
			// somebody thought to reload.
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
		$out = [ 'lines' => '', 'cards' => [], 'links' => [], 'images' => [], 'prices' => [] ];
		$t   = self::theme_style();
		$ids = self::best_sellers( self::window_days(), $limit, array_map( 'absint', (array) ( $rule['category_ids'] ?? [] ) ), $rule );
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
			// Its link, so an email written later can be told which products the
			// earlier ones already leaned on: the link is the one thing a
			// product block always carries, whatever the writing did around it.
			$out['links'][ $product->get_name() ] = (string) $product->get_permalink();
			// The block itself, built HERE and handed to the writing ready-made.
			// How many products, how they are grouped and where they sit is the
			// prompt's decision; what one of them LOOKS like is not, because a
			// card reinvented on every send is a shop whose products are
			// dressed differently in every email.
			$out['cards'][] = self::card_html(
				(string) $product->get_permalink(),
				$img,
				(string) $product->get_name(),
				self::price_html( $product, $rule )
			);
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

	// =========================================================================
	// Planning the campaign
	//
	// Two prompts, one feeding the other. The first is asked what this
	// promotion deserves — how many emails, on which days, and what each one
	// says that the others do not. The second, the one that already existed,
	// writes each of them and is handed that answer as its brief.
	//
	// It is the same idea as one person briefing another: the plan is a
	// short, readable thing the owner can look at and change before a single
	// email is written, and both halves are ordinary editable prompts. What it
	// is NOT is a second way of writing an email — the writing has one code
	// path, and the plan only decides what to ask it for.
	// =========================================================================

	/** The campaign plan prompt in force: the owner's own, or the shipped one. */
	/**
	 * The instructions the email's opening picture is made from.
	 *
	 * A prompt of its OWN, and not a sentence the email writing came up with.
	 * The description used to be invented by the copy prompt, email by email,
	 * which meant there was nothing to work on: judging it took a whole email,
	 * and improving it meant editing the instructions for the WORDS in the
	 * hope the picture followed. It is now one text the shop writes, tests on
	 * its own as many times as it likes, and keeps.
	 *
	 * The promotion's own facts are appended to it whatever it says, exactly
	 * like the language rule on the writing.
	 */
	public static function image_prompt(): string {
		$custom = trim( (string) ( self::settings()['img_prompt'] ?? '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		return class_exists( 'DZE_Prompt_Defaults' )
			? DZE_Prompt_Defaults::pick( 'promo_email_img', self::default_image_prompt() )
			: self::default_image_prompt();
	}

	public static function default_image_prompt(): string {
		return "Photograph the products in a scene that belongs to this promotion, as the opening picture of a marketing email.\n"
			. "\n"
			. "A COMPOSED photograph, not objects laid out on the ground. One clear subject, large in the frame, worn or held or in use — or, if nothing is being worn, arranged deliberately on a real surface at eye level, the way a catalogue shoots a still life. Everything else supports it and falls out of focus.\n"
			. "\n"
			. "Real light and real materials: the hour and the weather the promotion evokes, shadows that agree with them, a background that says where this is without competing with the product.\n"
			. "\n"
			. "No text of any kind in the image: no title, no price, no badge, no logo, no watermark. The words go over it in the email itself.";
	}

	public static function plan_prompt(): string {
		$custom = trim( (string) ( self::settings()['plan_prompt'] ?? '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		return class_exists( 'DZE_Prompt_Defaults' )
			? DZE_Prompt_Defaults::pick( 'promo_plan', self::default_plan_prompt() )
			: self::default_plan_prompt();
	}

	public static function default_plan_prompt(): string {
		return "Decide what this promotion is worth in emails.\n"
			. "\n"
			. "Not a fixed set. A three-day flash sale is one email, maybe two. A ten-day seasonal sale earns four. Ask what a reader would tolerate hearing about this offer, and stop there — one email too many costs more than one too few.\n"
			. "\n"
			. "The moments worth using, in the order they happen:\n"
			. "- A WARM-UP, a day or two before it opens: something is coming, no prices yet, nothing has started.\n"
			. "- The LAUNCH, on the opening day: the offer, plainly, with the products.\n"
			. "- A REMINDER while it runs: not the announcement again — a different way in. Another category, another argument, what is selling.\n"
			. "- A LAST CALL on the closing day or the one before: short, and about the ending.\n"
			. "\n"
			. "For each email, give:\n"
			. "- date: the day it goes out, YYYY-MM-DD, inside or just before the promotion's window.\n"
			. "- angle: one or two sentences telling the writer what THIS email does that the others do not. It is a brief, not a subject line: name the argument, the products to lean on, the tone.\n"
			. "\n"
			. "Two emails on the same day is a mistake. So is a warm-up dated after the sale opened.";
	}

	/**
	 * Asks the plan prompt what this promotion deserves, and creates the rows.
	 *
	 * Nothing is written here: what comes back is a list of empty emails, each
	 * with its day and its brief, ready for the writing prompt. That split is
	 * deliberate — the owner reads the plan, moves a date, drops one, and only
	 * then spends anything on writing them.
	 *
	 * @return array<string,array> the emails as they now stand.
	 */
	public static function plan_for( string $rule_id, array $rule ): array {
		$fmt  = 'Y-m-d';
		$pct  = rtrim( rtrim( number_format( (float) ( $rule['percent'] ?? 0 ), 2, '.', '' ), '0' ), '.' );
		$s_ts = strtotime( self::just_day( (string) ( $rule['start'] ?? '' ) ) ?: '' );
		$e_ts = strtotime( self::just_day( (string) ( $rule['end'] ?? '' ) ) ?: '' );
		if ( ! $s_ts ) {
			throw new RuntimeException( __( 'Give the promotion its dates first — a campaign is planned around them.', 'dazont-ecom' ) );
		}
		$days = ( $e_ts && $e_ts > $s_ts ) ? (int) round( ( $e_ts - $s_ts ) / DAY_IN_SECONDS ) + 1 : 1;

		$user = "--- THE PROMOTION ---\n"
			. 'Title: ' . (string) ( $rule['title'] ?? '' ) . "\n"
			. 'Discount: ' . $pct . "%\n"
			. 'Opens: ' . gmdate( $fmt, $s_ts ) . "\n"
			. 'Closes: ' . ( $e_ts ? gmdate( $fmt, $e_ts ) : gmdate( $fmt, $s_ts ) ) . "\n"
			. 'Length: ' . $days . " days\n"
			. 'It covers ' . ( ! empty( $rule['category_ids'] ) ? 'some categories only' : 'the whole shop' ) . ".\n"
			. 'Today: ' . gmdate( $fmt ) . "\n";
		if ( class_exists( 'DZE_Marketing_Ai' ) ) {
			$about = trim( (string) DZE_Marketing_Ai::instance()->shop_context_text() );
			if ( '' !== $about ) {
				$user .= "\n--- THE SHOP ---\n" . mb_substr( $about, 0, 1200 ) . "\n";
			}
		}
		$user .= "\n--- INSTRUCTIONS ---\n" . self::plan_prompt() . "\n"
			. "\n--- OUTPUT ---\nJSON only: {\"emails\":[{\"date\":\"YYYY-MM-DD\",\"angle\":\"…\"}]}. No other key, no comment, no markdown fence.";

		DZE_Ai_Usage::unit( 'promo_plan' );
		try {
			$out = DZE_Marketing_Ai::complete(
				'You plan the email campaign of an online shop\'s promotion. You answer with JSON only.',
				$user,
				'',
				1500,
				60
			);
		} finally {
			DZE_Ai_Usage::unit();
		}
		$json = json_decode( trim( (string) preg_replace( '/^```(?:json)?|```$/m', '', (string) $out ) ), true );
		$rows = is_array( $json ) ? (array) ( $json['emails'] ?? [] ) : [];
		if ( ! $rows ) {
			throw new RuntimeException( __( 'The plan came back unreadable — try again.', 'dazont-ecom' ) );
		}

		$emails = self::emails_for( $rule_id, $rule );
		// The days already taken count as seen. The plan ADDS to what a
		// promotion holds rather than replacing it, so without this a second
		// planning would put a second email on a day that already has one —
		// and two emails on the same morning is the one thing the plan prompt
		// is told never to do.
		$seen = [];
		foreach ( $emails as $had ) {
			$day = self::just_day( (string) ( $had['when'] ?? '' ) );
			if ( '' !== $day ) {
				$seen[ $day ] = true;
			}
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$when = self::just_day( (string) ( $row['date'] ?? '' ) );
			if ( '' === $when || isset( $seen[ $when ] ) ) {
				continue; // no day twice, and no email without one.
			}
			$seen[ $when ] = true;
			// Minted here rather than by the browser: the plan can be run by
			// cron one day, and an id that only exists when somebody has a
			// page open is an id the automation cannot make.
			$id   = 'e' . substr( md5( $rule_id . $when . microtime() ), 0, 10 );
			$kind = self::kind_for( $when, $rule );
			$emails[ $id ] = [
				// The plan decides WHEN each email goes out and what it is
				// for; which TYPE that makes it follows from the day, and the
				// owner can change it on the email like any other.
				'kind'    => $kind,
				'angle'   => mb_substr( sanitize_textarea_field( (string) ( $row['angle'] ?? '' ) ), 0, 600 ),
				'when'    => $when,
				'subject' => '',
				'preview' => '',
				'body'    => '',
				'picture' => '',
				'draft'   => [],
			];
		}
		uasort( $emails, static fn( array $a, array $b ): int => strcmp( (string) $a['when'], (string) $b['when'] ) );

		$all = get_option( self::OPT_COPY, [] );
		$all = is_array( $all ) ? $all : [];
		$all[ $rule_id ] = [ 'emails' => $emails ];
		update_option( self::OPT_COPY, $all, false );

		DZE_Ai_Usage::finished( 'promo_plan' );
		return $emails;
	}

	/** Plans the campaign and hands the rows back for the screen to draw. */
	public static function ajax_plan(): void {
		self::guard();
		[ $rule_id, $rule ] = self::target();
		try {
			$emails = self::plan_for( $rule_id, $rule );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'emails'  => array_map(
				static fn( string $id, array $m ): array => [
					'id'      => $id,
					'kind'    => (string) ( $m['kind'] ?? '' ),
					'name'    => self::email_name( $m ),
					'when'    => (string) $m['when'],
					'angle'   => (string) ( $m['angle'] ?? '' ),
					'subject' => (string) $m['subject'],
				],
				array_keys( $emails ),
				$emails
			),
			'message' => sprintf(
				/* translators: %d: how many emails the plan holds */
				_n( '%d email planned. Write them, then look at each one.', '%d emails planned. Write them, then look at each one.', count( $emails ), 'dazont-ecom' ),
				count( $emails )
			),
		] );
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
		$kind  = (string) ( $moment['kind'] ?? self::first_kind() );
		if ( isset( $kinds[ $kind ] ) ) {
			// Which one of the promotion's emails this is, said three ways:
			// its type, the day it goes out, and its place in the sequence.
			// "Reminder" alone leaves the writing to guess whether anything
			// has been said yet — and it guessed wrong, every time.
			$order = array_keys( self::emails_for( $rule_id, $rule ) );
			$at    = array_search( $email_id, $order, true );
			$user .= "\n--- WHICH EMAIL THIS IS ---\n"
				. 'Type: ' . $kinds[ $kind ]['label'] . ' — ' . $kinds[ $kind ]['when'] . ".\n"
				. ( '' !== (string) ( $moment['when'] ?? '' ) ? 'It goes out on ' . $date( $moment['when'] ) . ".\n" : '' )
				. ( false !== $at && count( $order ) > 1
					? sprintf( "It is email %d of %d in this promotion.\n", (int) $at + 1, count( $order ) )
					: '' )
				. self::kind_brief( $kind ) . "\n";
		}
		$angle = trim( (string) ( $moment['angle'] ?? '' ) );
		if ( '' !== $angle ) {
			// Written by the campaign plan, which was asked what each email of
			// this promotion should do that the others do not. One prompt
			// briefing another: this is that brief, and it outranks the
			// general description of the moment above.
			$user .= "\n--- WHAT THIS ONE IS FOR ---\n" . $angle . "\n";
		}
		// What the neighbours actually said. "Do not repeat the announcement"
		// means nothing to somebody who has not read it, so each email is shown
		// the others: their subject, how they opened, and which products they
		// leaned on — the repetition a reader notices first.
		$others = self::siblings_brief( $rule_id, $rule, $email_id, $mat );
		if ( '' !== $others ) {
			$user .= "\n--- THE OTHER EMAILS OF THIS PROMOTION ---\n"
				. "In the order they go out. Do not repeat their subject lines, do not open the way they opened, and lean on OTHER products than the ones they showed — a reader who gets the same photographs twice stops opening the third.\n\n"
				. $others;
		}
		if ( class_exists( 'DZE_Marketing_Ai' ) ) {
			$about = trim( (string) DZE_Marketing_Ai::instance()->shop_context_text() );
			if ( '' !== $about ) {
				$user .= "\n--- THE SHOP ---\n" . mb_substr( $about, 0, 1200 ) . "\n";
			}
		}
		$user .= "\n--- THE PICTURE ---\n";
		if ( '' !== $picture ) {
			$user .= 'This email already has its picture. Use this URL exactly as it stands, and leave the "picture" field empty: ' . $picture . "\n";
		} elseif ( self::images_on() ) {
			// Where it goes, not what it shows: the picture is made from a
			// prompt of its own, which the shop writes and tests separately.
			$user .= "This email opens on a photograph the shop makes itself. Leave the \"picture\" field empty and mark its place in the body with src=\"" . self::PICTURE_MARK . "\" — one image, at the top, full width.\n";
		} else {
			$user .= "This shop does not open its emails on a made photograph. Do NOT place an image of your own and leave the \"picture\" field empty: open on the words, and let the product blocks carry the pictures.\n";
		}
		$win   = self::sellers_window( $rule );
		$user .= "\n--- THE PRODUCTS YOU MAY SHOW ---\n"
			. 'What the shop actually sold over ' . $win['label']
			. ( $win['season'] ? ' — the same days of the year this promotion runs on, so the goods suit its season' : '' ) . ".\n"
			. ( '' !== $mat['lines']
				? "Use only these, with the name, the link, the image URL and the prices exactly as written. Show as many or as few as the email needs.\n\n" . $mat['lines']
				: "The shop returned no product. Write the email without a product.\n" );
		if ( ! empty( $mat['cards'] ) ) {
			$user .= sprintf(
				"\nDo NOT build a product yourself, and do NOT build a row or a table for them. Write [[PRODUCT n]] where product n should appear — [[PRODUCT 1]], [[PRODUCT 2]] — and the shop drops its own block there. Markers written one after another are laid out together, %d to a row, and the row stacks on a phone: that is done for you. How many products you show, in how many groups, and where those groups sit in the email is yours; what a product looks like and how a row is built is the shop's.\n",
				self::per_row()
			);
		}
		$user .= "\n--- THE SHOP'S OWN TYPE AND COLOUR ---\n"
			. 'Headings font-family: ' . $t['head'] . "\n"
			. 'Body font-family: ' . $t['body'] . "\n"
			. 'Text colour: ' . $t['ink'] . "\n"
			. 'Button and link colour: ' . $t['link'] . "\n"
			. 'Body text size: ' . (int) $t['size'] . "px\n";
		$user .= "\n--- INSTRUCTIONS ---\n" . self::email_prompt() . "\n"
			// The shop's own rules, added automatically. They are NOT put into
			// the editable prompt: a rule written there only reaches a shop
			// that never customised it, and the one shop that did is exactly
			// the one still getting it wrong. Same treatment as the language
			// constraint — the owner's prompt is never rewritten, it is
			// followed by what the shop requires whatever it says.
			. "\n--- THE SHOP'S OWN RULES, WHICH OVERRIDE THE INSTRUCTIONS ABOVE ---\n"
			. "- The header and the footer are added around your body. They ALREADY carry the shop's service promises — worldwide delivery, customer support, secure payment — as badges. Never write those promises in the body: not as a line, not as a reassurance, not as a closing sentence. The reader sees them once, under what you wrote.\n"
			. "- The body is placed in a column that is already inset from the edges of the card. Do not add an outer frame or a full-width coloured band of your own; write inside the space you are given.\n"
			. "- Never write the HTML of a product. [[PRODUCT n]] is how a product is placed, and it is the only way.\n"
			. "\n--- LANGUAGE ---\nWrite in " . $lang . ".\n"
			. "\n--- OUTPUT ---\nJSON only: {\"subject\":\"…\",\"preview\":\"…\",\"picture\":\"…\",\"body\":\"…\"}, where body is the HTML. No other key, no comment, no markdown fence.";

		DZE_Ai_Usage::unit( 'promo_email' );
		try {
			$out = DZE_Marketing_Ai::complete(
				'You write and lay out the promotional emails of an online shop. You answer with JSON only, and the body is email-ready HTML.',
				$user,
				'',
				6000,
				150
			);
		} finally {
			DZE_Ai_Usage::unit();
		}
		$json = json_decode( trim( (string) preg_replace( '/^```(?:json)?|```$/m', '', (string) $out ) ), true );
		$body = is_array( $json ) ? (string) ( $json['body'] ?? '' ) : '';
		if ( '' === trim( $body ) ) {
			// An answer that started well and stopped mid-sentence is a
			// different problem from an empty one, and saying "try again" to
			// both sends somebody round in circles.
			$cut = '' !== trim( (string) $out ) && ! is_array( $json );
			throw new RuntimeException( $cut
				? __( 'The answer came back cut off, so it could not be read. Shorten the email prompt, or ask for fewer products, and try again.', 'dazont-ecom' )
				: __( 'Nothing came back — try again.', 'dazont-ecom' ) );
		}
		[ $body, $warning ] = self::vouch( $body, $mat, $picture );

		DZE_Ai_Usage::finished( 'promo_email' );
		return [
			'subject' => mb_substr( sanitize_text_field( (string) ( $json['subject'] ?? '' ) ), 0, 150 ),
			'preview' => mb_substr( sanitize_text_field( (string) ( $json['preview'] ?? '' ) ), 0, 150 ),
			'body'    => self::place_products( self::clean_html( $body ), $mat['cards'] ),
			'warning' => $warning,
			// Whether this email left a place for a picture. The browser makes
			// it next, as a call of its own — one long request that a host cuts
			// off in the middle is not an email — from the shop's own picture
			// prompt, never from a sentence written here.
			'picture' => ( '' === $picture && false !== strpos( $body, self::PICTURE_MARK ) ) ? '1' : '',
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
	/**
	 * The picture this email already has — its OWN, and nothing else.
	 *
	 * It used to fall back to the event's image here, and that fallback was
	 * read further up as "this email already has its picture, use this URL and
	 * do not describe another". So a brand-new email inherited whatever the
	 * promotion happened to carry and was never once asked for a photograph of
	 * its own: four emails, one picture, chosen for none of them.
	 *
	 * A kept picture is a decision — somebody made it for THIS email, or put it
	 * there by hand — and a rewrite does not take it back. Nothing kept means
	 * nothing decided, and the writing is asked for one. The event's image is
	 * still the last resort, but at the end, in settle_picture(), where a
	 * fallback belongs: it fills a hole rather than preventing a choice.
	 */
	public static function picture_for( string $rule_id, array $rule, string $email_id = '' ): string {
		return (string) ( self::email_for( $rule_id, $email_id, $rule )['picture'] ?? '' );
	}

	/**
	 * The body as it goes out: any picture never made is settled here.
	 *
	 * fal.ai can be slow, refused or switched off, and an email carrying
	 * src="dze:picture" is a broken image in an inbox. So the marker is
	 * answered at the last moment — by THIS EMAIL'S own picture when one was
	 * made for it, failing that by the promotion's image, and by removing the
	 * picture altogether when there is neither. An email with one image fewer
	 * is an email; an email with a broken one is not.
	 *
	 * The email's own picture used to be missing from that list, which is the
	 * whole of a bug worth writing down: "Make the picture" made one, filed it
	 * on the email — and the marker in the body was still answered by the
	 * promotion's image, or by nothing. The photograph existed, was paid for,
	 * and never appeared.
	 *
	 * @param string $picture The picture this email holds, when it holds one.
	 */
	public static function settle_picture( string $html, array $rule, string $picture = '' ): string {
		$html = self::drop_broken_images( $html );
		if ( false === strpos( $html, self::PICTURE_MARK ) ) {
			return $html;
		}
		$fallback = '' !== trim( $picture ) ? trim( $picture ) : self::event_image( $rule );
		if ( '' !== $fallback ) {
			return str_replace( self::PICTURE_MARK, esc_url( $fallback ), $html );
		}
		return (string) preg_replace( '/<img[^>]*' . preg_quote( self::PICTURE_MARK, '/' ) . '[^>]*>/i', '', $html );
	}

	/**
	 * Images that lost their source, dropped rather than sent.
	 *
	 * An <img> with no src, or with the mangled remains of a marker, is a
	 * broken picture in an inbox and nothing else. Bodies written while the
	 * marker was being eaten by the sanitiser carry exactly that, so they are
	 * cleared on the way out instead of being left for somebody to notice.
	 */
	public static function drop_broken_images( string $html ): string {
		if ( false === stripos( $html, '<img' ) ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( array $m ): string {
				if ( ! preg_match( '/\ssrc\s*=\s*("|\x27)(.*?)\1/is', $m[0], $src ) ) {
					return ''; // no source at all.
				}
				$url = trim( html_entity_decode( $src[2] ) );
				// A relative "picture" is what wp_kses left of the marker.
				return ( '' === $url || 'picture' === strtolower( $url ) ) ? '' : $m[0];
			},
			$html
		);
	}

	/** What each moment of a promotion has to do that the others do not. */
	private static function kind_brief( string $kind ): string {
		// Read from WHEN the type falls, not from its id: a type the shop
		// invented — "Mid-sale bestsellers, 6 days in" — is briefed like the
		// moment it happens at, instead of falling through to the launch text
		// because nobody hard-coded its name here.
		$meta   = self::kinds()[ $kind ] ?? [];
		$anchor = (string) ( $meta['anchor'] ?? 'start' );
		$offset = (int) ( $meta['offset'] ?? 0 );
		if ( 'start' === $anchor && $offset < 0 ) {
			return 'It goes out BEFORE the promotion opens. It does not sell yet: it says something is coming and when, and it makes the reader want to be there on the day. No prices, no urgency, no countdown — nothing has started.';
		}
		if ( 'end' === $anchor && $offset <= 0 ) {
			return 'It goes out just before the promotion closes. It is short. It says the offer ends, when exactly, and nothing else. One idea, one button.';
		}
		if ( 'start' === $anchor && $offset > 0 ) {
			return 'The promotion is already running and this reader has not bought. He has seen the announcement, so do not repeat it: show him something else — other products, another angle on the same offer — and say plainly how long is left.';
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
		// Kept the moment it exists. Writing an email costs a call to the
		// model and a minute of waiting; losing it to a page reload, or to a
		// browser closed before somebody thought to press Save, is losing
		// something that cannot be got back by asking again — what comes back
		// the second time is a different email. It goes through put_email(),
		// the same writer the draft and the picture use, so there is no second
		// way for an email to reach the database.
		$keep = [
			'subject' => (string) ( $made['subject'] ?? '' ),
			'preview' => (string) ( $made['preview'] ?? '' ),
			'body'    => (string) ( $made['body'] ?? '' ),
		];
		self::put_email( $rule_id, $email_id, $keep );
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
	 * images. It is hosted by Klaviyo, where the email that carries it lives —
	 * never in the shop's media library, which is for the shop's products.
	 *
	 * @return array{url:string,full:string,warning:string}
	 * @throws RuntimeException
	 */
	public static function make_image( array $rule, string $prompt = '', array $email = [] ): array {
		if ( ! self::images_on() ) {
			throw new RuntimeException( __( 'Generated pictures are switched off under Settings → Email campaigns.', 'dazont-ecom' ) );
		}
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

		// What it works from. This used to be ONE photograph, and one is what
		// made the pictures generic: nano-banana-2 is an EDIT model, so a
		// single packshot plus a loose brief gives it nothing to hold on to
		// and it invents gear that the shop does not sell. Several real
		// photographs of the promotion's own best-sellers anchor it — the
		// products in the answer are then the products in the references.
		// Almost always product photographs, because a promotion usually has no
		// image of its own — making one is the whole point of being here. So
		// the references are packshots, and the brief has to say what to do
		// with a packshot rather than assume a scene it can extend.
		$sources = [];
		$hero    = (int) ( $rule['hero_event_id'] ?? 0 );
		if ( $hero && wp_attachment_is_image( $hero ) ) {
			$sources[] = $hero; // on the rare event that carries one already.
		}
		foreach ( self::best_sellers( self::window_days(), 6, array_map( 'absint', (array) ( $rule['category_ids'] ?? [] ) ), $rule ) as $pid ) {
			if ( count( $sources ) >= 4 ) {
				break; // four references is what the model composes well from.
			}
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
			$img     = $product instanceof WC_Product ? (int) $product->get_image_id() : 0;
			if ( $img && wp_attachment_is_image( $img ) && ! in_array( $img, $sources, true ) ) {
				$sources[] = $img;
			}
		}
		if ( ! $sources ) {
			throw new RuntimeException( __( 'No photograph to work from: pick an image for the event, or let the shop record a sale first.', 'dazont-ecom' ) );
		}

		// The shop's own picture prompt, and the promotion's facts appended to
		// it whatever it says — the title, the days it runs and the discount
		// are what the picture is FOR, and a prompt should not have to repeat
		// them to be correct. When the email is already written, what that
		// email is comes too: a warm-up and a last chance are not the same
		// photograph.
		$prompt = trim( $prompt );
		if ( '' === $prompt ) {
			$prompt = self::image_prompt();
		}
		$title = trim( (string) ( $rule['title'] ?? '' ) );
		$facts = '';
		if ( '' !== $title ) {
			$facts .= 'Promotion: ' . $title . "\n";
		}
		$start = strtotime( (string) ( $rule['start'] ?? '' ) );
		$end   = strtotime( (string) ( $rule['end'] ?? '' ) );
		if ( $start ) {
			$facts .= 'It runs ' . wp_date( 'j F', $start ) . ( $end ? ' → ' . wp_date( 'j F', $end ) : '' ) . "\n";
		}
		$pct = (float) ( $rule['percent'] ?? 0 );
		if ( $pct > 0 ) {
			$facts .= 'Discount: ' . rtrim( rtrim( number_format( $pct, 2, '.', '' ), '0' ), '.' ) . "%\n";
		}
		$kinds = self::kinds();
		$kind  = (string) ( $email['kind'] ?? '' );
		if ( isset( $kinds[ $kind ] ) ) {
			$facts .= 'This picture opens the "' . $kinds[ $kind ]['label'] . '" email of that promotion — ' . $kinds[ $kind ]['when'] . ".\n";
		}
		$subject = trim( (string) ( $email['subject'] ?? '' ) );
		if ( '' !== $subject ) {
			$facts .= 'Its subject line: ' . $subject . "\n";
		}
		if ( '' !== $facts ) {
			$prompt .= "\n\n--- THIS PROMOTION ---\n" . $facts;
		}
		// Appended to whatever was asked for, the way the language rule is
		// appended to the writing. A brief that says "carte blanche" means
		// carte blanche over the SETTING; it cannot mean carte blanche over
		// the goods, because a photograph of gear this shop does not sell is
		// worth less than no photograph at all.
		$prompt .= "\n\nThe reference images are real products from this shop, and most of them are catalogue shots on a plain background. Photograph those products somewhere real instead. Keep each one EXACTLY as it is — same shape, same colour, same pattern, same markings, same proportions — and build the scene around them. Do not redraw them, do not restyle them, do not add a single item that is not in the references, and do not invent goods this shop does not sell. Do not simply lay them on the floor: a product dropped on the ground is a photograph nobody would put in a catalogue. No text of any kind in the image: no title, no price, no badge, no logo, no watermark.";

		$refs = [];
		foreach ( $sources as $id ) {
			$refs[] = $content->fal_source_data_uri( $id, 'full' );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		DZE_Ai_Usage::unit( 'promo_email_img' );
		try {
			// 3:2 rather than the source's own ratio: an email opens on a
			// banner, and a square packshot was giving a square banner.
			$url = $content->fal_generate( $prompt, $refs, '3:2' );
		} finally {
			DZE_Ai_Usage::unit();
		}
		DZE_Ai_Usage::finished( 'promo_email_img' );

		// Hosted where the email lives, and NOWHERE on the shop. A picture
		// made for one campaign is not a product photograph: filing it in the
		// media library filled the shop's own library with pictures nobody
		// would ever pick from it, and every test made another one. Klaviyo
		// hosts the images of the emails Klaviyo sends; that is where it goes.
		$name = mb_substr( '' !== $title ? $title : __( 'Promotion email', 'dazont-ecom' ), 0, 80 );
		[ $hosted, $why ] = self::host_image( $url, $name );
		return [
			'url'  => $hosted ?: $url,
			// The zoom opens the same file: there is one, and it is not ours.
			'full' => $hosted ?: $url,
			// Not hosted is not a failure to hide: the picture works today,
			// from the provider's own address, and stops working the day that
			// address expires. The screen says so rather than finding out in
			// an inbox.
			'warning' => $hosted ? '' : sprintf(
				/* translators: %s: what Klaviyo answered */
				__( 'Klaviyo did not take the picture (%s), so it is used straight from the provider — give the API key image access, or it may stop loading later.', 'dazont-ecom' ),
				$why
			),
		];
	}

	/**
	 * Puts one picture in the Klaviyo account's own image library.
	 *
	 * The email is sent by Klaviyo, so its pictures belong there: the shop's
	 * media library is for the shop's products, and a campaign picture in it
	 * is clutter nobody asked for. Needs an API key with image access.
	 *
	 * @return array{0:string,1:string} the hosted URL, or '' and the reason.
	 */
	public static function host_image( string $url, string $name ): array {
		$res = self::request( 'POST', 'images/', [
			'data' => [
				'type'       => 'image',
				'attributes' => [
					'import_from_url' => $url,
					'name'            => mb_substr( $name, 0, 100 ),
					'hidden'          => false,
				],
			],
		], 90 );
		if ( is_wp_error( $res ) ) {
			return [ '', $res->get_error_message() ];
		}
		$hosted = (string) ( $res['data']['attributes']['image_url'] ?? '' );
		return [ $hosted, '' !== $hosted ? '' : __( 'it answered without an address', 'dazont-ecom' ) ];
	}

	public static function ajax_image(): void {
		self::guard();
		[ $rule_id, $rule, $email_id ] = self::target();
		// The instructions are the SHOP'S, read here — never posted by the
		// browser. A prompt that travels in the request is a prompt that can
		// be tested and then not be the one that runs.
		// A TEST picture is looked at and thrown away: it is how the prompt is
		// judged before an email is built on it. Only a picture asked for the
		// email is filed on the email.
		$test = ! empty( $_POST['test'] );
		try {
			$made = self::make_image( $rule, '', self::email_for( $rule_id, $email_id, $rule ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		if ( ! $test ) {
			self::keep_picture( $rule_id, $email_id, $made['url'] );
		}
		// Charged to the promotion, test or not: a test costs exactly what a
		// kept picture costs, and a counter that only counted the ones you
		// liked would be the wrong counter.
		$spend = self::charge_promo( $rule_id, class_exists( 'DZE_Content' ) ? DZE_Content::last_image_cost() : 0.0 );
		wp_send_json_success( [
			'url'     => $made['url'],
			'full'    => (string) ( $made['full'] ?? $made['url'] ),
			'warning' => (string) ( $made['warning'] ?? '' ),
			'spend'   => $spend,
			'test'    => $test,
		] );
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

		// The beta track. Sending a preview is not part of the stable revision:
		// asked for there, Klaviyo answers "No valid revisions found for
		// method" — a 404 that reads like a missing endpoint and is really a
		// request on the wrong track. The localisation endpoints are on the
		// same one.
		$sent = self::request( 'POST', 'template-preview-send-jobs/', [
			'data' => [
				'type'          => 'template-preview-send-job',
				'attributes'    => [ 'recipients' => $to ],
				'relationships' => [ 'template' => [ 'data' => [ 'type' => 'template', 'id' => $tpl ] ] ],
			],
		], 40, true );
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
			self::test_send( self::layout( self::settle_picture( $body, $rule, self::picture_for( $rule_id, $rule, $email_id ) ) ), $to );
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
	// Two attempts came before this one and both were wrong. The first asked
	// the owner to paste a marker into imported HTML by hand — it works once,
	// on one shop, by somebody who knows what a marker is. The second had the
	// plugin GUESS the seam: the logo row at the top, the unsubscribe row at
	// the bottom, throw away the middle. It guessed well on the templates it
	// was written against and would have guessed wrong on the next one.
	//
	// Klaviyo answers the question itself. A template built in its editor is a
	// stack of SECTIONS, and a section left empty comes out of the renderer
	// carrying "empty-column-placeholder" — Klaviyo saying, in the HTML, "this
	// is where content goes". So the frame is not deduced: it is read. What is
	// above the empty section is the header, what is below it is the footer,
	// and the email is written into the hole between them.
	//
	// It has to be the RENDERED template, not the stored one. A template made
	// of saved sections (Klaviyo's "universal content") holds only references
	// to them: the API refuses to read a saved section's contents, refuses to
	// create a template that points at one, and refuses to update a template
	// that carries one. The renderer is the one door that opens — it hands back
	// the finished email, universal sections and all, exactly as an inbox would
	// receive it.
	// =========================================================================

	/**
	 * Klaviyo's own rendering of one template: the finished HTML.
	 *
	 * @throws RuntimeException
	 */
	private static function render_template( string $id ): string {
		$got = self::request( 'POST', 'template-render/', [
			'data' => [
				'type'       => 'template',
				'id'         => $id,
				'attributes' => [ 'context' => new stdClass() ],
			],
		], 30 );
		if ( is_wp_error( $got ) ) {
			throw new RuntimeException( $got->get_error_message() );
		}
		$html = (string) ( $got['data']['attributes']['html'] ?? '' );
		if ( '' === trim( $html ) ) {
			throw new RuntimeException( __( 'Klaviyo returned nothing for that template.', 'dazont-ecom' ) );
		}
		return $html;
	}

	/**
	 * Cuts the rendered template on its empty section and puts the body marker
	 * in its place.
	 *
	 * The empty column is replaced rather than removed, so the section keeps
	 * the background, the width and the padding the owner gave it in Klaviyo:
	 * the email's content lands inside his own card, not beside it.
	 *
	 * @throws RuntimeException When the template has no empty section.
	 */
	public static function frame_from_render( string $html ): string {
		$at = strpos( $html, 'empty-column-placeholder' );
		if ( false === $at ) {
			throw new RuntimeException( __( 'That template has no empty section, so there is nowhere to put the email. In Klaviyo, open it, add a section between the header and the footer, leave it empty, save — then read it again here.', 'dazont-ecom' ) );
		}
		// Back up to the start of the tag carrying that class, forward to the
		// end of the element. The placeholder is empty by definition, so the
		// first closing tag after it is its own.
		$open = strrpos( substr( $html, 0, $at ), '<' );
		$gt   = strpos( $html, '>', $at );
		if ( false === $open || false === $gt ) {
			throw new RuntimeException( __( 'That template came back in a shape this plugin cannot read. Nothing was changed.', 'dazont-ecom' ) );
		}
		$close = strpos( $html, '</', $gt );
		$end   = ( false === $close ) ? false : strpos( $html, '>', $close );
		if ( false === $end ) {
			throw new RuntimeException( __( 'That template came back in a shape this plugin cannot read. Nothing was changed.', 'dazont-ecom' ) );
		}
		$frame = substr( $html, 0, $open )
			. '<div class="kl-column" style="display:table-cell;vertical-align:top;width:100%;">'
			. self::BODY_MARK
			. '</div>'
			. substr( $html, $end + 1 );
		// The renderer answers a template's links with the placeholders a SENT
		// email carries; a template being uploaded needs the tokens Klaviyo
		// resolves at send time instead. The unsubscribe line is the law's, not
		// ours, so it is put back rather than left to chance.
		$frame = strtr( $frame, [
			'[unsubscribe_tag]'        => '{% unsubscribe_link %}',
			'[manage_preferences_tag]' => '{% manage_preferences_link %}',
			'[view_in_browser_tag]'    => '{% web_view_link %}',
			'[web_view_tag]'           => '{% web_view_link %}',
		] );
		if ( false === strpos( $frame, self::BODY_MARK ) ) {
			throw new RuntimeException( __( 'The frame came back without a place to put the email in it. Nothing was changed.', 'dazont-ecom' ) );
		}
		return trim( $frame );
	}

	/**
	 * The column the email is written into, wearing the template's own type.
	 *
	 * The body the model writes is ordinary HTML — headings, paragraphs,
	 * images, a table of products. It is dropped into the same component
	 * wrapper Klaviyo uses for a text block, so the stylesheet the template
	 * already carries in its head styles it: headings come out in the shop's
	 * font, links in its colour, and the mobile rules apply.
	 *
	 * It carries ONE thing of its own: a 24px inset, the margin the shop's own
	 * Klaviyo marketing emails have. Leaving it to the prompt was tried and it
	 * does not hold — a body with no margin runs into the paper the first time
	 * the writing forgets, and "remember to pad your text" is exactly the kind
	 * of rule that is right nine times and wrong the tenth. A guaranteed
	 * margin costs a full-bleed photograph; a photograph is worth less than an
	 * email that is never malformed.
	 */
	/** What every version of the column has in common, and the only thing matched on. */
	public const SLOT_CLASS = 'mj-column-per-100 mj-outlook-group-fix component-wrapper kl-text-table-layout';

	public const SLOT_OPEN = '<div class="' . self::SLOT_CLASS . '" style="font-size:0px;text-align:left;direction:ltr;vertical-align:top;width:100%;">'
		. '<table border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;" width="100%"><tbody><tr>'
		. '<td align="left" class="kl-text" style="font-size:0px;padding:24px;word-break:break-word;">'
		. '<div style="font-family:\'Roboto\', Helvetica, Arial, sans-serif;font-size:16px;font-weight:400;line-height:1.3;text-align:left;">';

	public const SLOT_CLOSE = '</div></td></tr></tbody></table></div>';

	/** The body, dressed in the slot. */
	public static function slot( string $body ): string {
		return self::SLOT_OPEN . $body . self::SLOT_CLOSE;
	}

	/** Reads the chosen template and hands the frame back for the field. */
	public static function ajax_frame(): void {
		self::guard();
		$id = isset( $_POST['header'] ) ? sanitize_text_field( wp_unslash( $_POST['header'] ) ) : '';
		if ( '' === $id ) {
			wp_send_json_error( [ 'message' => __( 'Choose a template first.', 'dazont-ecom' ) ] );
		}
		try {
			$html = self::frame_from_render( self::render_template( $id ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		$names = (array) ( self::catalogue()['templates'] ?? [] );
		$name  = (string) ( $names[ $id ] ?? $id );
		wp_send_json_success( [
			'html'    => $html,
			'name'    => $name,
			'taken'   => sprintf(
				/* translators: 1: template name, 2: date */
				__( 'Header and footer from %1$s, read on %2$s.', 'dazont-ecom' ),
				$name,
				date_i18n( (string) get_option( 'date_format' ) )
			),
			'message' => __( 'Read. Look at the preview, then save.', 'dazont-ecom' ),
		] );
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
		// The plugin's own zoom, so a test picture is judged full size like
		// every other image it makes.
		wp_enqueue_style( 'dze-zoom', DZE_URL . 'admin/css/zoom.css', [], DZE_VERSION );
		wp_enqueue_script( 'dze-hzoom', DZE_URL . 'admin/js/hzoom.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-hzoom', 'dzeZoomI18n', [
			'zoom'  => __( 'See this image full size', 'dazont-ecom' ),
			'close' => __( 'Close', 'dazont-ecom' ),
			'prev'  => __( 'Previous image', 'dazont-ecom' ),
			'next'  => __( 'Next image', 'dazont-ecom' ),
		] );
		wp_enqueue_script( 'dze-klaviyo', DZE_URL . 'admin/js/klaviyo.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-klaviyo', 'dzeKlav', [
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE ),
			// The frame, handed over once, so both previews redraw as you type
			// instead of asking the server for a picture of what is already on
			// screen. It is the very frame the email is sent inside.
			'shell'    => self::preview_shell(),
			'mark'     => self::BODY_MARK,
			// The option name, so the browser can address the hidden fields
			// that carry a choice's NAME beside its id.
			'opt'      => self::OPT,
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
				'unnamed'  => __( 'Untitled email', 'dazont-ecom' ),
				'planning' => __( 'Asking what this promotion deserves…', 'dazont-ecom' ),
				'replan'   => __( 'Plan the campaign again? The emails already written are kept; the plan adds to them.', 'dazont-ecom' ),
				'writing1' => __( 'Writing %1$d of %2$d…', 'dazont-ecom' ),
				'allDone'  => __( 'All written. Read them, then save the event.', 'dazont-ecom' ),
				'nothing'  => __( 'No email to write yet — plan the campaign or add one.', 'dazont-ecom' ),
				'reading'  => __( 'Asking Klaviyo…', 'dazont-ecom' ),
				'whenOpen' => __( 'Which days work best?', 'dazont-ecom' ),
				'addMail'  => __( 'Add', 'dazont-ecom' ),
				'dropMail' => __( 'Remove this email? What was written for it is lost.', 'dazont-ecom' ),
				'pickedFrom' => __( 'The logo row and everything from the unsubscribe line down are kept; whatever the campaign had in between is dropped. Check the preview, then save.', 'dazont-ecom' ),
				'shooting' => __( 'Making the picture — this takes a minute…', 'dazont-ecom' ),
				'writing'  => __( 'Writing and laying out the email…', 'dazont-ecom' ),
				'shot'     => __( 'In the email. It is hosted by Klaviyo, not by the shop.', 'dazont-ecom' ),
				'shotTest' => __( 'Test picture — look at it, correct the picture prompt, try again.', 'dazont-ecom' ),
				'pictureReady' => __( 'Written, with a place for its picture — test one above, and tick the box to have the next writing make it.', 'dazont-ecom' ),
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
		$first  = (string) ( array_key_first( $emails ) ?? '' );
		// The day each moment would fall on for THIS promotion, so a new email
		// gets a sensible date the instant it is created rather than after a
		// round trip.
		$when_for = [];
		$names    = [];
		foreach ( $kinds as $kind => $meta ) {
			// Keyed by the type's ID, which is what the menu now carries: the
			// label is what the owner reads, the id is what the email stores,
			// and translating one into the other in the browser was one step
			// that could go wrong for nothing.
			$when_for[ $kind ] = self::default_when( $kind, $rule );
			$names[ $kind ]    = (string) $meta['label'];
		}
		?>
		<h3><?php esc_html_e( 'Emails', 'dazont-ecom' ); ?></h3>

		<div id="dze-klav-editor" data-rule="<?php echo esc_attr( $rule_id ); ?>" data-when="<?php echo esc_attr( (string) wp_json_encode( $when_for ) ); ?>" data-names="<?php echo esc_attr( (string) wp_json_encode( $names ) ); ?>" data-newkind="<?php echo esc_attr( self::first_kind() ); ?>" data-newday="<?php echo esc_attr( self::default_when( self::first_kind(), $rule ) ); ?>">
			<?php // This screen showed the emails, so an empty list means none — not "the form was not about emails". ?>
			<input type="hidden" name="dze_email_shown" value="1" />
			<div class="dze-mail-list">
				<?php foreach ( $emails as $mail_id => $mail ) :
					$kind = (string) ( $mail['kind'] ?? self::first_kind() );
					$when = (string) ( $mail['when'] ?? $when_for[ $kind ] ?? '' );
					$ts   = strtotime( $when );
					?>
					<div class="dze-mail" data-id="<?php echo esc_attr( $mail_id ); ?>">
						<div class="dze-mail-thumb"><iframe title="" sandbox="allow-same-origin" scrolling="no"></iframe></div>
						<div class="dze-mail-what">
							<strong class="dze-mail-name"><?php echo esc_html( self::email_name( $mail ) ); ?></strong>
							<span class="dze-mail-when"><?php echo esc_html( $ts ? wp_date( $fmt, $ts ) : $when ); ?><span class="dze-smart"><?php esc_html_e( 'Smart Send Time', 'dazont-ecom' ); ?></span></span>
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
							<button type="button" class="button button-small dze-mail-open"><?php esc_html_e( 'Open', 'dazont-ecom' ); ?></button>
							<button type="button" class="button-link dze-mail-drop" title="<?php esc_attr_e( 'Remove this email', 'dazont-ecom' ); ?>">&times;</button>
						</div>
						<?php // Every email keeps its fields in the form, so ONE Save keeps them all. ?>
						<input type="hidden" class="dze-f-exists" name="dze_email[<?php echo esc_attr( $mail_id ); ?>][exists]" value="1" />
						<input type="hidden" class="dze-f-kind" name="dze_email[<?php echo esc_attr( $mail_id ); ?>][kind]" value="<?php echo esc_attr( $kind ); ?>" />
						<input type="hidden" class="dze-f-picture" name="dze_email[<?php echo esc_attr( $mail_id ); ?>][picture]" value="<?php echo esc_attr( (string) ( $mail['picture'] ?? '' ) ); ?>" />
						<input type="hidden" class="dze-f-want" name="dze_email[<?php echo esc_attr( $mail_id ); ?>][want_picture]" value="<?php echo empty( $mail['want_picture'] ) ? '0' : '1'; ?>" />
						<input type="hidden" class="dze-f-subject" name="dze_email[<?php echo esc_attr( $mail_id ); ?>][subject]" value="<?php echo esc_attr( (string) ( $mail['subject'] ?? '' ) ); ?>" />
						<input type="hidden" class="dze-f-preview" name="dze_email[<?php echo esc_attr( $mail_id ); ?>][preview]" value="<?php echo esc_attr( (string) ( $mail['preview'] ?? '' ) ); ?>" />
						<input type="hidden" class="dze-f-when" name="dze_email[<?php echo esc_attr( $mail_id ); ?>][when]" value="<?php echo esc_attr( $when ); ?>" />
						<textarea class="dze-f-body" name="dze_email[<?php echo esc_attr( $mail_id ); ?>][body]" style="display:none;"><?php echo esc_textarea( (string) ( $mail['body'] ?? '' ) ); ?></textarea>
					</div>
				<?php endforeach; ?>
			</div>

			<p style="margin:10px 0 0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
				<button type="button" class="button" id="dze-mail-new">+ <?php esc_html_e( 'Add an email', 'dazont-ecom' ); ?></button>
				<span style="flex:1;"></span>
				<button type="button" class="button" id="dze-mail-plan"><?php esc_html_e( 'Plan the campaign', 'dazont-ecom' ); ?></button>
				<button type="button" class="button button-primary" id="dze-mail-all"><?php esc_html_e( 'Write them all', 'dazont-ecom' ); ?></button>
				<span id="dze-mail-plan-msg" style="font-size:13px;"></span>
			</p>

			<?php
			// The row a new email is made from. It is the SAME markup as the
			// rows above — one template, so a field added to an email cannot be
			// added to four rows and forgotten on the fifth.
			?>
			<script type="text/template" id="dze-mail-blank">
				<div class="dze-mail" data-id="__ID__">
					<div class="dze-mail-thumb"><iframe title="" sandbox="allow-same-origin" scrolling="no"></iframe></div>
					<div class="dze-mail-what">
						<strong class="dze-mail-name"></strong>
						<span class="dze-mail-when"><span class="dze-smart"><?php esc_html_e( 'Smart Send Time', 'dazont-ecom' ); ?></span></span>
						<span class="dze-mail-subject"></span>
					</div>
					<div class="dze-mail-state"></div>
					<div class="dze-mail-act">
						<button type="button" class="button button-small dze-mail-open"><?php esc_html_e( 'Open', 'dazont-ecom' ); ?></button>
						<button type="button" class="button-link dze-mail-drop" title="<?php esc_attr_e( 'Remove this email', 'dazont-ecom' ); ?>">&times;</button>
					</div>
					<input type="hidden" class="dze-f-exists" name="dze_email[__ID__][exists]" value="1" />
					<input type="hidden" class="dze-f-kind" name="dze_email[__ID__][kind]" value="" />
					<input type="hidden" class="dze-f-picture" name="dze_email[__ID__][picture]" value="" />
					<input type="hidden" class="dze-f-want" name="dze_email[__ID__][want_picture]" value="0" />
					<input type="hidden" class="dze-f-subject" name="dze_email[__ID__][subject]" value="" />
					<input type="hidden" class="dze-f-preview" name="dze_email[__ID__][preview]" value="" />
					<input type="hidden" class="dze-f-when" name="dze_email[__ID__][when]" value="" />
					<textarea class="dze-f-body" name="dze_email[__ID__][body]" style="display:none;"></textarea>
				</div>
			</script>

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
						<th scope="row"><label for="dze-klav-e-type"><?php esc_html_e( 'Type', 'dazont-ecom' ); ?></label></th>
						<td>
							<?php
							// The type is what this email IS: the writing is
							// told it, and told what the others of the same
							// promotion already said. Each option carries the
							// day it falls on, because "Reminder" alone is not
							// a choice. The list itself is the shop's, under
							// Settings → Email campaigns.
							?>
							<select id="dze-klav-e-type" style="min-width:280px;">
								<?php foreach ( self::kinds() as $dze_id => $dze_meta ) : ?>
									<option value="<?php echo esc_attr( $dze_id ); ?>">
										<?php echo esc_html( $dze_meta['label'] . '  ·  ' . self::day_rule( $dze_meta ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<span class="description" style="margin-left:8px;">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=dazont-ecom-ai&tab=email#dze-klav-types' ) ); ?>"><?php esc_html_e( 'Edit the types', 'dazont-ecom' ); ?></a>
							</span>
						</td>
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
						</td>
					</tr>
				</table>

				<?php if ( self::images_on() ) : ?>
					<?php
					// The picture, worked on BEFORE the email is finished.
					//
					// It used to be one button that made the real one and put
					// it in the email, so the only way to judge the result was
					// to spend a picture on the email and look at what arrived.
					// Now: a test picture as many times as it takes, on the
					// shop's own picture PROMPT — edited here, tested here —
					// and a tick box that says whether the next writing makes
					// the real one in the same pass, which is what you want
					// once that prompt is right.
					?>
					<div id="dze-klav-shot" style="border:1px solid #dcdcde;border-radius:5px;padding:10px 12px;margin:0 0 10px;max-width:880px;background:#fff;">
						<p style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0;">
							<label style="display:inline-flex;align-items:center;gap:6px;">
								<input type="checkbox" id="dze-klav-e-want" />
								<?php esc_html_e( 'Make the picture with the email', 'dazont-ecom' ); ?>
							</label>
							<button type="button" class="button" id="dze-klav-e-shot"><?php esc_html_e( 'Generate test picture', 'dazont-ecom' ); ?></button>
							<?php
							// The instructions that button follows, opened and
							// edited where it is pressed: read the result,
							// correct the prompt, press again.
							if ( class_exists( 'DZE_Prompts' ) ) {
								// The same pencil as everywhere else, with the
								// same word on it: one gesture, one wording.
								DZE_Prompts::the_button( 'promo_email_img' );
							}
							?>
							<span id="dze-klav-shot-msg" class="description"></span>
							<?php // What this promotion's pictures have cost so far. ?>
							<?php $dze_spend = (string) self::promo_spend( $rule_id )['label']; ?>
							<span class="dze-spend" id="dze-klav-spend" style="<?php echo '' !== $dze_spend ? 'display:inline-block;' : ''; ?>" title="<?php esc_attr_e( 'What this promotion has spent on pictures, at the price per image set under Settings → Product content.', 'dazont-ecom' ); ?>"><?php echo esc_html( $dze_spend ); ?></span>
						</p>
						<p id="dze-klav-shot-out" style="display:none;align-items:center;gap:10px;margin:8px 0 0;">
							<?php
							// The zoom every other strip in the plugin uses: a
							// button planted on the thumbnail itself, opening
							// it full size. A link beside the picture was a
							// second way of doing the one thing this plugin
							// already does one way.
							?>
							<?php
							// Two spans, not one: the zoom plants its button on
							// the image's PARENT and shows it when that parent
							// is hovered, so the group has to CONTAIN cells
							// rather than be one. Flattened, the button was
							// planted on the group itself and stayed invisible
							// — the rule that reveals it never matched.
							?>
							<span class="dze-zoomgroup" style="display:inline-block;line-height:0;">
								<span style="display:inline-block;line-height:0;">
									<img id="dze-klav-shot-img" src="" data-full="" alt="" style="width:120px;height:80px;object-fit:cover;border:1px solid #dcdcde;border-radius:4px;" />
								</span>
							</span>
							<button type="button" class="button button-small" id="dze-klav-e-usepic"><?php esc_html_e( 'Use it in this email', 'dazont-ecom' ); ?></button>
						</p>
					</div>
				<?php endif; ?>
				<p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
					<button type="button" class="button button-primary" id="dze-klav-e-write"><?php esc_html_e( 'Write the email', 'dazont-ecom' ); ?></button>
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
		$s       = self::settings();
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
		<?php
		// The list of everything in the account is a CACHE and it goes stale;
		// the one thing chosen out of it is a SETTING and must not. So the
		// name of each choice is remembered beside its id, and the screen
		// reads the same whether the cache is warm or cold.
		$dze_inc_name = self::label_for( $inc, $cat, (string) ( $s['included_name'] ?? '' ) );
		$dze_exc_name = self::label_for( $exc, $cat, (string) ( $s['excluded_name'] ?? '' ) );
		?>
		<p>
			<button type="button" class="button" id="dze-klav-refresh" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Read my Klaviyo account', 'dazont-ecom' ); ?></button>
			<span id="dze-klav-refresh-msg" style="margin-left:8px;font-size:13px;">
				<?php
				if ( ! empty( $cat['audiences'] ) ) {
					printf(
						/* translators: %s: when the account was last read */
						esc_html__( 'Last read %s ago.', 'dazont-ecom' ),
						esc_html( human_time_diff( (int) ( $cat['read'] ?? time() ) ) )
					);
				} elseif ( '' === $inc ) {
					esc_html_e( 'Not read yet — press the button once and the two menus fill in.', 'dazont-ecom' );
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
							<option value="<?php echo esc_attr( $inc ); ?>" selected><?php echo esc_html( $dze_inc_name ); ?></option>
						<?php endif; ?>
					</select>
					<input type="hidden" name="<?php echo esc_attr( self::OPT . '[included_name]' ); ?>" value="<?php echo esc_attr( $dze_inc_name ); ?>" />
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
							<option value="<?php echo esc_attr( $exc ); ?>" selected><?php echo esc_html( $dze_exc_name ); ?></option>
						<?php endif; ?>
					</select>
					<input type="hidden" name="<?php echo esc_attr( self::OPT . '[excluded_name]' ); ?>" value="<?php echo esc_attr( $dze_exc_name ); ?>" />
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

		<h2 class="title"><?php esc_html_e( 'Header and footer', 'dazont-ecom' ); ?><span style="margin-left:8px;font-size:12px;font-weight:400;color:#b26a00;"><?php esc_html_e( 'required', 'dazont-ecom' ); ?></span></h2>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Fixed on every promotion, and they come from Klaviyo — the template you keep there is the one the shop sends inside. In the Klaviyo editor, make one template with your header, ONE empty section in the middle for the email, and your footer. Read it here and every promotion goes out in it.', 'dazont-ecom' ); ?>
		</p>
		<?php if ( '' === self::shell() ) : ?>
			<p style="max-width:880px;padding:10px 12px;border-left:4px solid #dba617;background:#fcf9e8;">
				<strong><?php esc_html_e( 'No emails can be written until this is done.', 'dazont-ecom' ); ?></strong>
				<?php esc_html_e( 'This plugin draws no header of its own — a made-up frame under your shop\'s name is worse than none — so the email editor stays shut on your promotions until you read a template here.', 'dazont-ecom' ); ?>
			</p>
		<?php endif; ?>
		<?php
		$dze_tpls  = (array) ( $cat['templates'] ?? [] );
		$dze_from  = (string) ( $s['frame_name'] ?? '' );
		$dze_when  = (int) ( $s['frame_read'] ?? 0 );
		$dze_pick  = (string) ( $s['frame_id'] ?? '' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dze-klav-th"><?php esc_html_e( 'Take it from', 'dazont-ecom' ); ?></label></th>
				<td>
					<select id="dze-klav-th" style="min-width:340px;">
						<option value=""><?php esc_html_e( '— choose a Klaviyo template —', 'dazont-ecom' ); ?></option>
						<?php foreach ( $dze_tpls as $dze_id => $dze_name ) : ?>
							<option value="<?php echo esc_attr( $dze_id ); ?>" <?php selected( $dze_pick, (string) $dze_id ); ?>><?php echo esc_html( $dze_name ); ?></option>
						<?php endforeach; ?>
						<?php if ( '' !== $dze_pick && ! isset( $dze_tpls[ $dze_pick ] ) ) : ?>
							<option value="<?php echo esc_attr( $dze_pick ); ?>" selected><?php echo esc_html( self::label_for( $dze_pick, $cat, $dze_from ) ); ?></option>
						<?php endif; ?>
					</select>
					<button type="button" class="button" id="dze-klav-take" style="margin-left:8px;"><?php esc_html_e( 'Read it', 'dazont-ecom' ); ?></button>
					<input type="hidden" id="dze-klav-fid" name="<?php echo esc_attr( self::OPT . '[frame_id]' ); ?>" value="<?php echo esc_attr( $dze_pick ); ?>" />
					<input type="hidden" id="dze-klav-fname" name="<?php echo esc_attr( self::OPT . '[frame_name]' ); ?>" value="<?php echo esc_attr( $dze_from ); ?>" />
					<p class="description" id="dze-klav-tpl-hint">
						<?php
						if ( '' === $dze_from && ! $dze_tpls ) {
							esc_html_e( 'Press "Read my Klaviyo account" above to list your templates.', 'dazont-ecom' );
						} elseif ( '' !== $dze_from && $dze_when ) {
							printf(
								/* translators: 1: template name, 2: date */
								esc_html__( 'Header and footer from %1$s, read on %2$s. Read it again after you change it in Klaviyo.', 'dazont-ecom' ),
								'<strong>' . esc_html( $dze_from ) . '</strong>',
								esc_html( date_i18n( (string) get_option( 'date_format' ), $dze_when ) )
							);
						} else {
							esc_html_e( 'Nothing read yet. Choose your template and press Read it.', 'dazont-ecom' );
						}
						?>
					</p>
				</td>
			</tr>
		</table>
		<p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
			<strong style="font-size:13px;"><?php esc_html_e( 'What every email is sent inside', 'dazont-ecom' ); ?></strong>
			<span style="font-size:13px;color:#646970;"><?php esc_html_e( '— the empty band in the middle is where the email is written.', 'dazont-ecom' ); ?></span>
			<span id="dze-klav-shell-msg" style="font-size:13px;"></span>
		</p>
		<textarea id="dze-klav-shell" name="<?php echo esc_attr( self::OPT . '[shell]' ); ?>" style="display:none;"><?php echo esc_textarea( self::shell() ); ?></textarea>
		<iframe id="dze-klav-shell-frame" title="<?php esc_attr_e( 'Template preview', 'dazont-ecom' ); ?>" sandbox="allow-same-origin" style="width:100%;max-width:880px;height:640px;border:1px solid #dcdcde;background:#fff;"></iframe>

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
					<p class="description">
						<?php esc_html_e( 'A quiet window falls back to catalogue popularity.', 'dazont-ecom' ); ?>
						<?php esc_html_e( 'A promotion that opens more than three weeks from now is read from the same days of the year, one year back, instead — the products that sell in the week an email is written are not the ones that sell in the week it goes out.', 'dazont-ecom' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Opening picture', 'dazont-ecom' ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="dze-klav-images" name="<?php echo esc_attr( self::OPT . '[images]' ); ?>" value="1" <?php checked( self::images_on() ); ?> />
						<?php esc_html_e( 'Allow pictures made with fal.ai', 'dazont-ecom' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'A permission, not an order: an email is given one when you press Make the picture on it, never on its own.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-klav-row"><?php esc_html_e( 'Products per row', 'dazont-ecom' ); ?></label></th>
				<td>
					<select id="dze-klav-row" name="<?php echo esc_attr( self::OPT . '[per_row]' ); ?>">
						<?php foreach ( [ 1, 2, 3, 4 ] as $dze_n ) : ?>
							<option value="<?php echo esc_attr( (string) $dze_n ); ?>" <?php selected( $dze_n, self::per_row() ); ?>><?php echo esc_html( (string) $dze_n ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'On a phone they stack, whatever this says.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Shop style', 'dazont-ecom' ); ?></h2>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Nothing to set here. The email wears what your theme already says — this is only so you can see what it read and where it read it. Change it in your theme and the next email follows.', 'dazont-ecom' ); ?>
		</p>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Your theme\'s own settings come first — that is where you go to change a colour, so that is where the email takes it from, palette variables resolved. WordPress\'s standard (theme.json and the palette a theme declares to the block editor) answers for anything your theme left unsaid, and on a theme this plugin has never heard of it answers for everything. WordPress\'s OWN default palette is never used: it belongs to WordPress, not to your shop.', 'dazont-ecom' ); ?>
		</p>
		<?php
		$dze_t    = self::theme_style();
		$dze_from = self::style_sources();
		$dze_rows = [
			'head'    => __( 'Headings font', 'dazont-ecom' ),
			'body'    => __( 'Text font', 'dazont-ecom' ),
			'size'    => __( 'Text size', 'dazont-ecom' ),
			'ink'     => __( 'Text colour', 'dazont-ecom' ),
			'link'    => __( 'Link colour', 'dazont-ecom' ),
			'sale'    => __( 'Sale price', 'dazont-ecom' ),
			'muted'   => __( 'Old price, struck through', 'dazont-ecom' ),
			'btn_bg'  => __( 'Button', 'dazont-ecom' ),
			'btn_ink' => __( 'Button text', 'dazont-ecom' ),
			'radius'  => __( 'Corners', 'dazont-ecom' ),
			'card'    => __( 'Card background', 'dazont-ecom' ),
			'border'  => __( 'Card border', 'dazont-ecom' ),
		];
		?>
		<?php $dze_pal = self::palette(); ?>
		<?php if ( ! empty( $dze_pal['colors'] ) ) : ?>
			<p style="margin:0 0 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;max-width:880px;">
				<span style="font-size:13px;color:#646970;"><?php echo esc_html( $dze_pal['source'] ); ?> —</span>
				<?php foreach ( $dze_pal['colors'] as $dze_c ) : ?>
					<span title="<?php echo esc_attr( $dze_c ); ?>" style="display:inline-block;width:26px;height:26px;border:1px solid #c3c4c7;background:<?php echo esc_attr( $dze_c ); ?>;"></span>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>
		<div style="display:flex;gap:28px;flex-wrap:wrap;align-items:flex-start;max-width:880px;">
			<table class="widefat striped" style="flex:1 1 420px;min-width:380px;">
				<thead><tr>
					<th><?php esc_html_e( 'What', 'dazont-ecom' ); ?></th>
					<th><?php esc_html_e( 'Value', 'dazont-ecom' ); ?></th>
					<th><?php esc_html_e( 'Read from', 'dazont-ecom' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $dze_rows as $dze_k => $dze_label ) : ?>
					<?php $dze_v = (string) $dze_t[ $dze_k ]; ?>
					<tr>
						<td><?php echo esc_html( $dze_label ); ?></td>
						<td>
							<?php if ( 0 === strpos( $dze_v, '#' ) ) : ?>
								<span style="display:inline-block;width:14px;height:14px;vertical-align:-2px;margin-right:6px;border:1px solid #c3c4c7;background:<?php echo esc_attr( $dze_v ); ?>;"></span>
							<?php endif; ?>
							<code><?php echo esc_html( in_array( $dze_k, [ 'size', 'radius' ], true ) ? $dze_v . 'px' : $dze_v ); ?></code>
						</td>
						<td><?php echo esc_html( (string) ( $dze_from[ $dze_k ] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div style="flex:0 0 300px;">
				<p style="margin:0 0 8px;font-size:13px;color:#646970;">
					<?php esc_html_e( 'A product in an email, as it goes out:', 'dazont-ecom' ); ?>
				</p>
				<div style="padding:18px;background:#f6f7f7;border:1px solid #dcdcde;">
					<?php
					// The very function the email calls. A mock-up drawn beside
					// it would be a second version of the card, and the two
					// would drift the first time one of them changed.
					echo wp_kses_post(
						self::card_html(
							'#',
							// WooCommerce's own placeholder, so drawing this
							// panel costs the shop no query at all. What is on
							// show here is the STYLE, not the stock.
							(string) ( function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'medium' ) : '' ),
							__( 'A product from your shop', 'dazont-ecom' ),
							sprintf(
								'<span style="color:%1$s;text-decoration:line-through;">%3$s</span> <span style="color:%2$s;font-weight:700;">%4$s</span>',
								esc_attr( $dze_t['muted'] ),
								esc_attr( $dze_t['sale'] ),
								esc_html( function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( 222.90 ) ) : '222.90' ),
								esc_html( function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( 188.90 ) ) : '188.90' )
							)
						)
					);
					?>
				</div>
			</div>
		</div>

		<h2 class="title" id="dze-klav-types"><?php esc_html_e( 'Email types', 'dazont-ecom' ); ?></h2>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'The list an email is picked from — and what the writing is told this email is, so a reminder is not written like an announcement. A name, and the day it falls on: counted from the promotion\'s start OR from its end, never both.', 'dazont-ecom' ); ?>
		</p>
		<table class="widefat striped" style="max-width:880px;">
			<thead>
				<tr>
					<th style="width:40%;"><?php esc_html_e( 'Name', 'dazont-ecom' ); ?></th>
					<th><?php esc_html_e( 'Goes out', 'dazont-ecom' ); ?></th>
					<th style="width:40px;"></th>
				</tr>
			</thead>
			<tbody id="dze-klav-type-rows">
				<?php
				$dze_i = 0;
				foreach ( self::kinds() as $dze_id => $dze_meta ) :
					$dze_mode = 'end' === $dze_meta['anchor'] ? 'eb' : ( $dze_meta['offset'] < 0 ? 'sb' : 'sa' );
					?>
					<tr class="dze-type-row">
						<td>
							<input type="hidden" name="<?php echo esc_attr( self::OPT . '[types][' . $dze_i . '][id]' ); ?>" value="<?php echo esc_attr( $dze_id ); ?>" />
							<input type="text" class="large-text" name="<?php echo esc_attr( self::OPT . '[types][' . $dze_i . '][label]' ); ?>" value="<?php echo esc_attr( $dze_meta['label'] ); ?>" />
						</td>
						<td>
							<input type="number" min="0" max="90" step="1" style="width:70px;" name="<?php echo esc_attr( self::OPT . '[types][' . $dze_i . '][days]' ); ?>" value="<?php echo esc_attr( (string) abs( (int) $dze_meta['offset'] ) ); ?>" />
							<?php esc_html_e( 'days', 'dazont-ecom' ); ?>
							<select name="<?php echo esc_attr( self::OPT . '[types][' . $dze_i . '][mode]' ); ?>">
								<option value="sb" <?php selected( 'sb', $dze_mode ); ?>><?php esc_html_e( 'before it starts', 'dazont-ecom' ); ?></option>
								<option value="sa" <?php selected( 'sa', $dze_mode ); ?>><?php esc_html_e( 'after it starts', 'dazont-ecom' ); ?></option>
								<option value="eb" <?php selected( 'eb', $dze_mode ); ?>><?php esc_html_e( 'before it ends', 'dazont-ecom' ); ?></option>
							</select>
						</td>
						<td><button type="button" class="button-link dze-type-drop" title="<?php esc_attr_e( 'Remove this type', 'dazont-ecom' ); ?>">&times;</button></td>
					</tr>
					<?php
					$dze_i++;
				endforeach;
				?>
			</tbody>
		</table>
		<p>
			<button type="button" class="button" id="dze-klav-type-add"><?php esc_html_e( 'Add a type', 'dazont-ecom' ); ?></button>
			<button type="button" class="button-link" id="dze-klav-type-reset" style="margin-left:10px;">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
			<span class="description" style="margin-left:10px;"><?php esc_html_e( 'A type still used by an email keeps that email: it falls back to the first of the list.', 'dazont-ecom' ); ?></span>
		</p>
		<script>
		(function () {
			var opt = <?php echo wp_json_encode( self::OPT ); ?>,
				shipped = <?php echo wp_json_encode( array_values( self::shipped_kinds() ) ); ?>,
				$rows = jQuery('#dze-klav-type-rows');
			// The index only has to be unique inside this form; the ids the
			// emails point at are the hidden field, not the row number.
			function nextIndex() { return $rows.find('tr').length + Math.floor(Math.random() * 1000) + 100; }
			function field(i, key) { return opt + '[types][' + i + '][' + key + ']'; }
			function row(type) {
				var i = nextIndex(), id = type.id || ('t' + Date.now().toString(36) + i),
					mode = type.anchor === 'end' ? 'eb' : ((type.offset || 0) < 0 ? 'sb' : 'sa'),
					days = Math.abs(type.offset || 0);
				return jQuery('<tr class="dze-type-row"></tr>').append(
					jQuery('<td></td>')
						.append(jQuery('<input type="hidden"/>').attr('name', field(i, 'id')).val(id))
						.append(jQuery('<input type="text" class="large-text"/>').attr('name', field(i, 'label')).val(type.label || '')),
					jQuery('<td></td>')
						.append(jQuery('<input type="number" min="0" max="90" step="1" style="width:70px;"/>').attr('name', field(i, 'days')).val(days))
						.append(' <?php echo esc_js( __( 'days', 'dazont-ecom' ) ); ?> ')
						.append(jQuery('<select></select>').attr('name', field(i, 'mode'))
							.append(jQuery('<option value="sb"><?php echo esc_js( __( 'before it starts', 'dazont-ecom' ) ); ?></option>'))
							.append(jQuery('<option value="sa"><?php echo esc_js( __( 'after it starts', 'dazont-ecom' ) ); ?></option>'))
							.append(jQuery('<option value="eb"><?php echo esc_js( __( 'before it ends', 'dazont-ecom' ) ); ?></option>'))
							.val(mode)),
					jQuery('<td></td>').append(jQuery('<button type="button" class="button-link dze-type-drop">&times;</button>'))
				);
			}
			jQuery('#dze-klav-type-add').on('click', function () { $rows.append(row({ label: '', anchor: 'start', offset: 0 })); });
			jQuery(document).on('click', '.dze-type-drop', function () { jQuery(this).closest('tr').remove(); });
			jQuery('#dze-klav-type-reset').on('click', function () {
				$rows.empty();
				jQuery.each(shipped, function (i, t) { $rows.append(row(t)); });
			});
		}());
		</script>

		<h2 class="title"><?php esc_html_e( 'Prompts — what the plugin writes, and how', 'dazont-ecom' ); ?></h2>
		<?php
		// Shut cards, one per prompt — the presentation the Product content and
		// Categories screens use. The fields are unchanged: same names, same
		// ids, saved by this page's own Save button.
		$dze_card = class_exists( 'DZE_Prompts' );
		if ( $dze_card ) {
			DZE_Prompts::card_open( 'dze-klav-plan-card', __( 'Campaign plan', 'dazont-ecom' ), __( 'How many emails a promotion gets, on which days, and what each is for', 'dazont-ecom' ) );
		}
		?>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Decides how many emails a promotion gets, on which days, and what each one is for. It writes no email: it briefs the prompt below, one email at a time.', 'dazont-ecom' ); ?>
		</p>
			<textarea id="dze-klav-plan" name="<?php echo esc_attr( self::OPT . '[plan_prompt]' ); ?>" rows="10" class="large-text code" style="max-width:880px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;"><?php echo esc_textarea( self::plan_prompt() ); ?></textarea>
			<p>
				<button type="button" class="button-link" id="dze-klav-plan-reset">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
				<?php if ( class_exists( 'DZE_Prompt_Defaults' ) ) { DZE_Prompt_Defaults::control( 'promo_plan', '#dze-klav-plan' ); } ?>
			</p>
			<script>
			(function () {
				var shipped = <?php echo wp_json_encode( self::default_plan_prompt() ); ?>;
				var btn = document.getElementById('dze-klav-plan-reset');
				var ta  = document.getElementById('dze-klav-plan');
				if ( btn && ta ) { btn.addEventListener('click', function () { ta.value = shipped; }); }
			}());
			</script>

		<?php
		if ( $dze_card ) {
			DZE_Prompts::card_close();
			DZE_Prompts::card_open( 'dze-klav-prompt-card', __( 'Email copy', 'dazont-ecom' ), __( 'The whole email: the words and the layout', 'dazont-ecom' ) );
		}
		?>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Decides the whole email: the words and the layout. Products, photographs, links and prices are handed over by the shop and checked on the way back.', 'dazont-ecom' ); ?>
		</p>
		<p class="description" style="max-width:880px;padding:10px 12px;border-left:4px solid #c3c4c7;background:#f6f7f7;">
			<strong><?php esc_html_e( 'This is not the only thing the email is written from.', 'dazont-ecom' ); ?></strong><br />
			<?php
			printf(
				/* translators: %s: link to the shop profile field */
				esc_html__( 'Your %s is sent with every email as background on the shop. If a sentence keeps coming back that you never asked for — a delivery promise, a guarantee — it is almost certainly written there, not here.', 'dazont-ecom' ),
				'<a href="' . esc_url( add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG, 'tab' => 'general' ], admin_url( 'admin.php' ) ) ) . '">' .
					esc_html__( 'About this shop', 'dazont-ecom' ) . '</a>'
			);
			?><br />
			<?php esc_html_e( 'Two things are fixed and no prompt changes them: the product blocks are built by the shop in its own type and colour, and the service badges in the footer are never repeated in the body.', 'dazont-ecom' ); ?>
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

			<?php
			if ( self::images_on() ) :
				if ( $dze_card ) {
					DZE_Prompts::card_close();
					DZE_Prompts::card_open( 'dze-klav-img-card', __( 'Opening picture', 'dazont-ecom' ), __( 'What the photograph at the top of an email shows', 'dazont-ecom' ) );
				}
				?>
				<p class="description" style="max-width:880px;">
					<?php esc_html_e( 'Sent to fal.ai as it stands, with the promotion\'s title, dates and discount added — and, once the email is written, what that email is and its subject line. The products come as real photographs of the promotion\'s own best-sellers; keeping them exactly as they are is imposed and no prompt overrides it. Test it from any email, as many times as you like: a test picture touches nothing.', 'dazont-ecom' ); ?>
				</p>
				<textarea id="dze-klav-img-prompt" name="<?php echo esc_attr( self::OPT . '[img_prompt]' ); ?>" rows="8" class="large-text code" style="max-width:880px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;"><?php echo esc_textarea( self::image_prompt() ); ?></textarea>
				<p>
					<button type="button" class="button-link" id="dze-klav-img-reset">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
					<?php if ( class_exists( 'DZE_Prompt_Defaults' ) ) { DZE_Prompt_Defaults::control( 'promo_email_img', '#dze-klav-img-prompt' ); } ?>
				</p>
				<script>
				(function () {
					var shipped = <?php echo wp_json_encode( self::default_image_prompt() ); ?>;
					var btn = document.getElementById('dze-klav-img-reset');
					var ta  = document.getElementById('dze-klav-img-prompt');
					if ( btn && ta ) {
						btn.addEventListener('click', function () {
							ta.value = window.dzeDefaultFor ? window.dzeDefaultFor( 'promo_email_img', shipped ) : shipped;
							ta.focus();
						});
					}
				}());
				</script>
			<?php endif; ?>
			<?php
			if ( $dze_card ) {
				DZE_Prompts::card_close();
			}
			submit_button( __( 'Save email settings', 'dazont-ecom' ) );
			?>
		</form>
		<?php
	}
}
