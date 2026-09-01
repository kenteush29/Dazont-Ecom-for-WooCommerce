<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI Marketing Assistant.
 *
 * Generates a marketing calendar (a set of promotion "events") for the shop
 * using the Anthropic Claude API, from context detected automatically from
 * the site itself (name, categories, sample products, price range, store
 * country) plus the site's own languages (WPML) and a per-language pool of
 * likely target countries. Each suggestion can be accepted (turned into a
 * real scheduled event in the Marketing Events module), edited, or refused.
 * A front-end shortcode renders the resulting calendar for the home page.
 *
 * Configuration (API key, country pools) lives under Settings → AI Marketing
 * Assistant. The generate/review workflow lives on the Marketing Events page
 * — this class only supplies the two render_*() methods those pages embed.
 *
 * The Anthropic API key is read from the DZE_ANTHROPIC_API_KEY constant
 * (wp-config.php) when defined, otherwise from a settings field. It is never
 * committed to the repository and never sent anywhere except api.anthropic.com.
 */
final class DZE_Marketing_Ai {

	public const NONCE           = 'dze_mai';
	public const OPT_SETTINGS    = 'dze_mai_settings';
	public const OPT_SUGGESTIONS = 'dze_mai_suggestions';
	public const MENU_SLUG       = 'dazont-ecom-ai';

	private const API_URL       = 'https://api.anthropic.com/v1/messages';
	private const API_VERSION   = '2023-06-01';
	private const MODEL         = 'claude-opus-4-8';

	/** Selectable Claude models (label shown in settings). */
	public const MODELS = [
		'claude-opus-4-8'  => 'Claude Opus 4.8 — best quality (default)',
		'claude-sonnet-5'  => 'Claude Sonnet 5 — faster, cheaper',
		'claude-haiku-4-5' => 'Claude Haiku 4.5 — fastest, cheapest',
	];
	private const SHORTCODE     = 'dze_marketing_calendar';
	/** Internal safety cap on one generation call — not exposed as a setting. */
	private const MAX_EVENTS = 20;

	/**
	 * Default, editable strategy guidance for the calendar generator. The plugin
	 * always appends the shop context, chosen language, date window and the JSON
	 * schema around this text, so users can rewrite the strategy without breaking
	 * the machinery.
	 */
	public const DEFAULT_EVENTS_PROMPT =
		"You are the marketing strategist of this shop. Build its promotional calendar out of the "
		. "commercial moments that are REAL for its customers, never out of moments invented to fill "
		. "the year:\n"
		. "- The public holidays and gift dates its market observes (Christmas, Valentine's Day, "
		. "Mother's and Father's Day, Halloween).\n"
		. "- The official sale periods and retail events of that market (les soldes in France, Black "
		. "Friday, Cyber Monday, Boxing Day).\n"
		. "- The seasons the whole trade runs on: the summer sale, the back-to-school return, the "
		. "winter sale.\n"
		. "- The dates that belong to what this shop sells, when they are dates its customers already "
		. "keep themselves.\n\n"
		. "How to judge a proposal: could a customer name that occasion on his own, without reading "
		. "the promotion? If not, it is not an occasion, and it does not go on the calendar. Four "
		. "honest moments beat twelve, and weeks with nothing in them are a normal answer.\n"
		. "Name the promotion after the occasion, in the words customers use for it — no invented "
		. "sale name, no slogan, no wordplay.\n"
		. "Give each moment the length it deserves rather than the shortest one that works.\n"
		. "This shop does not clear stock: nothing here is a clearance, a last chance on remaining "
		. "stock or a way to shift unsold goods. A promotion sells more, it does not empty a warehouse.\n"
		. "Mind the delivery time: a promotion people buy gifts in must end early enough for the "
		. "parcel to arrive before the date it is needed.";

	/** Default target-country pool per language, seeded on first use. */
	public const LANGUAGE_COUNTRY_POOLS = [
		'en' => [ 'US', 'GB', 'CA', 'IE', 'AU', 'NZ' ],
		'fr' => [ 'FR', 'BE', 'CH', 'LU', 'CA' ],
		'de' => [ 'DE', 'AT', 'CH' ],
		'es' => [ 'ES', 'MX', 'AR', 'CO', 'CL', 'PE' ],
		'it' => [ 'IT', 'CH' ],
		'pt' => [ 'PT', 'BR' ],
		'nl' => [ 'NL', 'BE' ],
		'pl' => [ 'PL' ],
		'sv' => [ 'SE' ],
		'da' => [ 'DK' ],
		'fi' => [ 'FI' ],
		'nb' => [ 'NO' ],
		'no' => [ 'NO' ],
		'el' => [ 'GR' ],
		'tr' => [ 'TR' ],
		'ru' => [ 'RU' ],
		'ja' => [ 'JP' ],
		'zh' => [ 'CN', 'TW', 'HK', 'SG' ],
		'ko' => [ 'KR' ],
		'ar' => [ 'AE', 'SA', 'EG' ],
		'cs' => [ 'CZ' ],
		'ro' => [ 'RO' ],
		'hu' => [ 'HU' ],
		'he' => [ 'IL' ],
		'th' => [ 'TH' ],
		'vi' => [ 'VN' ],
		'id' => [ 'ID' ],
		'hi' => [ 'IN' ],
	];

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Front end + admin: the calendar shortcode must render on the home page.
		add_shortcode( self::SHORTCODE, [ $this, 'render_calendar_shortcode' ] );

		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		// No widget on the WordPress home screen — see DZE_Dashboard.
		add_action( 'wp_ajax_dze_mai_generate',   [ $this, 'ajax_generate' ] );
		add_action( 'wp_ajax_dze_mai_accept',     [ $this, 'ajax_accept' ] );
		add_action( 'wp_ajax_dze_mai_save_event', [ $this, 'ajax_save_event' ] );
		add_action( 'wp_ajax_dze_mai_refuse',     [ $this, 'ajax_refuse' ] );
		add_action( 'wp_ajax_dze_mai_translate',  [ $this, 'ajax_translate' ] );
		add_action( 'wp_ajax_dze_mai_profile',    [ $this, 'ajax_profile_draft' ] );
		// Settings live on the "AI Assistant" submenu (register_menu →
		// render_settings_page); the generate/review UI renders inside the
		// Marketing Events page via render_calendar_panel().
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public static function get_settings(): array {
		$s = get_option( self::OPT_SETTINGS, [] );
		$s = is_array( $s ) ? $s : [];
		return wp_parse_args( $s, [
			'api_key'       => '',
			'model'         => self::MODEL,
			'events_prompt' => '',   // custom calendar guidance; empty = DEFAULT_EVENTS_PROMPT.
			'promo_i18n_prompt' => '', // how a promotion line is translated; empty = shipped.
			'promo_i18n_on'     => 1,  // translate a promotion when it is saved.
			// The countdown on a generated event, decided from the offer
			// itself rather than left to the model's mood. Defaults are the
			// CRO recommendation printed beside them.
			'timer_auto'        => 1,  // let the rule below decide the countdown.
			'timer_min_percent' => 20, // nothing under this is worth a deadline.
			'timer_max_days'    => 7,  // a deadline weeks away presses nobody.
			// The instructions for the picture that replaces the home page's
			// own during a big event. WHICH picture that is is not a setting:
			// it is read from the home page, because that is where it is
			// changed.
			'hero_prompt'       => '',
			'country_pools' => [], // lang_code => [ ISO-3166 alpha-2, ... ]
			'budget_month'  => 0,  // USD cap for ALL AI calls per month; 0 = no cap.
			'match_model'   => '', // keyword-matching model; empty = Haiku default.
			'insights_model'=> '', // Sourcing report model; empty = main model.
			'sourcing_minvol' => 10, // default "analyse vol ≥" threshold in the Sourcing Assistant.
			'match_rules'     => '', // keyword-matching rules override; empty = DZE_Keywords default.
			'report_guidance' => '', // sourcing-report instructions override; empty = DZE_Explorer default.
		] );
	}

	/** Model to call: wp-config constant overrides the settings choice. */
	public static function chosen_model(): string {
		if ( defined( 'DZE_ANTHROPIC_MODEL' ) && DZE_ANTHROPIC_MODEL ) {
			return (string) DZE_ANTHROPIC_MODEL;
		}
		$m = (string) ( self::get_settings()['model'] ?? '' );
		return ( $m !== '' && strpos( $m, 'claude' ) === 0 ) ? $m : self::MODEL;
	}

	/**
	 * Selectable Claude models, id => label. Pulled live from the Anthropic API
	 * (cached 12h) so the list stays current; falls back to the built-in set
	 * when no key is set or the request fails.
	 */
	public static function available_models(): array {
		$key = self::api_key();
		if ( $key === '' ) {
			return self::MODELS;
		}
		$cached = get_transient( 'dze_mai_models' );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}
		$resp = wp_remote_get( 'https://api.anthropic.com/v1/models?limit=100', [
			'timeout' => 15,
			'headers' => [
				'x-api-key'         => $key,
				'anthropic-version' => self::API_VERSION,
			],
		] );
		if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			return self::MODELS;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$out  = [];
		foreach ( (array) ( $body['data'] ?? [] ) as $m ) {
			$id = (string) ( $m['id'] ?? '' );
			if ( $id === '' || strpos( $id, 'claude' ) !== 0 ) {
				continue;
			}
			$out[ $id ] = (string) ( $m['display_name'] ?? $id ); // API lists newest first.
		}
		if ( empty( $out ) ) {
			return self::MODELS;
		}
		set_transient( 'dze_mai_models', $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/** Primary site language code (WPML default, else the site locale). */
	public static function primary_language(): string {
		if ( class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active() ) {
			$d = DZE_Wpml::default_language();
			if ( $d ) {
				return $d;
			}
		}
		return strtolower( substr( get_locale(), 0, 2 ) );
	}

	public static function api_key(): string {
		if ( defined( 'DZE_ANTHROPIC_API_KEY' ) && DZE_ANTHROPIC_API_KEY ) {
			return (string) DZE_ANTHROPIC_API_KEY;
		}
		return (string) ( self::get_settings()['api_key'] ?? '' );
	}

	public function register_settings(): void {
		register_setting( 'dze_mai_options', self::OPT_SETTINGS, [ 'sanitize_callback' => [ $this, 'sanitize_settings' ], 'autoload' => false ] );
	}

	public function sanitize_settings( $value ): array {
		$in       = is_array( $value ) ? $value : [];
		$existing = self::get_settings();

		// The Settings page saves per tab: only overwrite the fields the
		// submitted section actually carries, keep everything else as-is.
		//
		// "Carries" is checked, not assumed. A branch that writes a key the
		// form never contained writes an EMPTY value over a real one, and the
		// shop finds out weeks later — the country pools and the shop's own
		// description were both being wiped that way. So every write below is
		// guarded by $has(), and the only keys written unconditionally are the
		// checkboxes of the section being saved, which post nothing when
		// unticked and would otherwise be impossible to switch off.
		$has = static fn( string $k ): bool => array_key_exists( $k, $in );
		$section = (string) ( $in['section'] ?? 'all' );

		// Keep the stored key when the field is left blank (so it isn't wiped).
		$key = trim( (string) ( $in['api_key'] ?? '' ) );
		if ( $key === '' ) {
			$key = (string) $existing['api_key'];
		}

		$pools = [];
		foreach ( (array) ( $in['country_pools'] ?? [] ) as $lang => $codes ) {
			$lang  = sanitize_key( $lang );
			$clean = [];
			foreach ( (array) $codes as $c ) {
				$c = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $c ) );
				if ( strlen( $c ) === 2 ) {
					$clean[ $c ] = $c;
				}
			}
			// Free-text "add more countries" field for this language, if posted.
			if ( ! empty( $in['country_pools_extra'][ $lang ] ) ) {
				foreach ( preg_split( '/[\s,;]+/', (string) $in['country_pools_extra'][ $lang ] ) as $c ) {
					$c = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $c ) );
					if ( strlen( $c ) === 2 ) {
						$clean[ $c ] = $c;
					}
				}
			}
			if ( ! empty( $clean ) ) {
				$pools[ $lang ] = array_values( $clean );
			}
		}

		$model = (string) ( $in['model'] ?? '' );
		if ( $model === '' || strpos( $model, 'claude' ) !== 0 ) {
			$model = self::MODEL;
		}

		// A new key may unlock a different model list — refresh it next read.
		if ( $key !== (string) $existing['api_key'] ) {
			delete_transient( 'dze_mai_models' );
		}

		// A rewritten prompt that matches the default is stored empty, so future
		// tweaks to the default keep flowing through.
		$events_prompt = trim( (string) ( $in['events_prompt'] ?? '' ) );
		if ( $events_prompt === trim( self::default_events_prompt() ) ) {
			$events_prompt = '';
		}

		if ( 'shop' === $section ) {
			// Its own form, its own button, its own section: a page where the
			// field you just typed in is saved by a button two screens below
			// it is a page where nothing gets saved.
			return array_merge( $existing, [
				'shop_profile' => trim( sanitize_textarea_field( (string) ( $in['shop_profile'] ?? '' ) ) ),
			] );
		}
		if ( 'general' === $section ) {
			// Key, model, budget — and what the shop IS, which every module of
			// the plugin reads from here.
			$write = [ 'api_key' => sanitize_text_field( $key ) ];
			if ( $has( 'model' ) ) {
				$write['model'] = $model;
			}
			if ( $has( 'budget_month' ) ) {
				$write['budget_month'] = max( 0, (float) str_replace( ',', '.', (string) $in['budget_month'] ) );
			}
			// The shop's description has its own form, with section=shop. This
			// one does not carry it, and writing it here emptied what every
			// module of the plugin reads.
			if ( $has( 'shop_profile' ) ) {
				$write['shop_profile'] = trim( sanitize_textarea_field( (string) $in['shop_profile'] ) );
			}
			return array_merge( $existing, $write );
		}
		if ( 'sourcing' === $section ) {
			// Prompt overrides: a text identical to the shipped default is stored
			// empty, so future default improvements reach untouched installs.
			$match_rules = trim( sanitize_textarea_field( (string) ( $in['match_rules'] ?? '' ) ) );
			if ( class_exists( 'DZE_Keywords' ) && trim( DZE_Keywords::default_match_rules() ) === $match_rules ) {
				$match_rules = '';
			}
			$report_guidance = trim( sanitize_textarea_field( (string) ( $in['report_guidance'] ?? '' ) ) );
			if ( class_exists( 'DZE_Explorer' ) && trim( DZE_Explorer::default_report_guidance() ) === $report_guidance ) {
				$report_guidance = '';
			}
			$write = [];
			if ( $has( 'match_model' ) ) {
				$write['match_model'] = sanitize_text_field( (string) $in['match_model'] );
			}
			if ( $has( 'insights_model' ) ) {
				$write['insights_model'] = sanitize_text_field( (string) $in['insights_model'] );
			}
			if ( $has( 'sourcing_minvol' ) ) {
				$write['sourcing_minvol'] = max( 0, (int) $in['sourcing_minvol'] );
			}
			if ( $has( 'match_rules' ) ) {
				$write['match_rules'] = $match_rules;
			}
			if ( $has( 'report_guidance' ) ) {
				$write['report_guidance'] = $report_guidance;
			}
			return array_merge( $existing, $write );
		}
		if ( 'events' === $section ) {
			// The Marketing events tab carries everything except key + model.
			$promo_i18n = trim( sanitize_textarea_field( (string) ( $in['promo_i18n_prompt'] ?? '' ) ) );
			if ( $promo_i18n === trim( self::default_promo_i18n_prompt() ) ) {
				$promo_i18n = '';
			}
			// The switch is this form's own: unticked it posts nothing, so it
			// is the one key written whether or not it came back.
			$write = [ 'promo_i18n_on' => empty( $in['promo_i18n_on'] ) ? 0 : 1 ];
			// Same reasoning for the countdown switch: this form owns it.
			$write['timer_auto'] = empty( $in['timer_auto'] ) ? 0 : 1;
			if ( $has( 'timer_min_percent' ) ) {
				$write['timer_min_percent'] = min( 90, max( 1, (int) $in['timer_min_percent'] ) );
			}
			if ( $has( 'timer_max_days' ) ) {
				$write['timer_max_days'] = min( 120, max( 1, (int) $in['timer_max_days'] ) );
			}
			if ( $has( 'hero_prompt' ) ) {
				$write['hero_prompt'] = sanitize_textarea_field( (string) $in['hero_prompt'] );
			}
			if ( $has( 'events_prompt' ) ) {
				$write['events_prompt'] = sanitize_textarea_field( $events_prompt );
			}
			if ( $has( 'promo_i18n_prompt' ) ) {
				$write['promo_i18n_prompt'] = $promo_i18n;
			}
			// This tab has no country-pool fields: writing them here emptied
			// the pools the Merchant Center sync reads.
			if ( $has( 'country_pools' ) ) {
				$write['country_pools'] = $pools;
			}
			if ( $has( 'banner_bg' ) ) {
				$write['banner_bg'] = DZE_Discounts::hex( $in['banner_bg'], '#111111' );
			}
			if ( $has( 'banner_color' ) ) {
				$write['banner_color'] = DZE_Discounts::hex( $in['banner_color'], '#ffffff' );
			}
			if ( $has( 'banner_size' ) ) {
				$write['banner_size'] = DZE_Discounts::px( $in['banner_size'], 0, 40 );
			}
			if ( $has( 'banner_pad' ) ) {
				$write['banner_pad'] = DZE_Discounts::px( $in['banner_pad'], 0, 60 );
			}
			if ( $has( 'banner_location' ) ) {
				$write['banner_location'] = array_key_exists( (string) $in['banner_location'], DZE_Discounts::locations() )
					? (string) $in['banner_location']
					: 'below_header';
			}
			return array_merge( $existing, $write );
		}

		// No section named: the form that posted this carried only part of the
		// settings, so what it did not carry is KEPT. Returning a fresh array
		// here wiped the budget, the models and every prompt but one — the
		// exact trap the sectioned branches above exist to avoid.
		$write = [ 'api_key' => sanitize_text_field( $key ) ];
		if ( $has( 'model' ) ) {
			$write['model'] = $model;
		}
		if ( $has( 'events_prompt' ) ) {
			$write['events_prompt'] = sanitize_textarea_field( $events_prompt );
		}
		if ( $has( 'country_pools' ) ) {
			$write['country_pools'] = $pools;
		}
		return array_merge( $existing, $write );
	}

	/** This module's entry on the Shortcodes screen. */
	public static function shortcode_card(): array {
		return [
			'tag'     => self::SHORTCODE,
			'title'   => __( 'Marketing calendar', 'dazont-ecom' ),
			'summary' => __( 'The scheduled sales of the shop, as a month grid or as a list.', 'dazont-ecom' ),
			'body'    => [ self::class, 'render_shortcode_card' ],
		];
	}

	public static function render_shortcode_card(): void {
		?>
		<p class="description"><?php esc_html_e( 'Renders the events you have scheduled under Marketing Events. Only enabled events are shown, so a draft calendar stays private until you switch its events on.', 'dazont-ecom' ); ?></p>
		<h4><?php esc_html_e( 'Attributes', 'dazont-ecom' ); ?></h4>
		<ul style="list-style-type:disc;margin-left:20px;">
			<li><code>view</code> — <?php esc_html_e( '"calendar" (default): a single month with Prev/Next buttons. "list": the events one after the other.', 'dazont-ecom' ); ?></li>
			<li><code>limit</code> — <?php esc_html_e( 'list view only: how many events at most (12 by default).', 'dazont-ecom' ); ?></li>
			<li><code>past</code> — <?php esc_html_e( 'set to 1 to keep events that are already over.', 'dazont-ecom' ); ?></li>
		</ul>
		<h4><?php esc_html_e( 'Examples', 'dazont-ecom' ); ?></h4>
		<ul style="list-style-type:disc;margin-left:20px;">
			<li><code>[<?php echo esc_html( self::SHORTCODE ); ?>]</code></li>
			<li><code>[<?php echo esc_html( self::SHORTCODE ); ?> view="list" limit="6"]</code></li>
		</ul>
		<?php
	}

	/** The effective calendar guidance: the user's custom text, or the default. */
	/**
	 * What the plugin sends WITH this prompt, listed for the popup that shows
	 * it. Written beside the code that builds the call, so the list and the
	 * call are read and changed together.
	 *
	 * @return string[]
	 */
	public static function prompt_data( string $id ): array {
		if ( 'hero_image' === $id ) {
			return [
				__( 'The promotion: its title, and the days it runs.', 'dazont-ecom' ),
				__( 'The picture the home page shows today, as the image to work from — so what comes back fits the same place, at the same shape.', 'dazont-ecom' ),
			];
		}
		if ( 'promo_i18n' === $id ) {
			return [
				__( 'The line to adapt, exactly as it stands on the promotion.', 'dazont-ecom' ),
				__( 'The markets to write it for: language code and language name.', 'dazont-ecom' ),
				__( 'The answer format — one line per language code, nothing else.', 'dazont-ecom' ),
			];
		}
		return [
			__( 'The shop, read from itself: its name, its tagline, its best-selling categories and products, how many products it has, its price range, its currency and its country.', 'dazont-ecom' ),
			__( 'The language the calendar is written in, and the markets it is written for.', 'dazont-ecom' ),
			__( 'The date range you asked for, and the largest number of events that may come back.', 'dazont-ecom' ),
			__( 'The rules added whatever your text says: every event maps to a real occasion named in its rationale, the title names that occasion in the words customers use, a stretch with no occasion in it comes back empty.', 'dazont-ecom' ),
			__( 'The answer format — title, dates, percentage, countdown, one-sentence rationale.', 'dazont-ecom' ),
		];
	}

	/**
	 * The instructions for the picture that replaces the home page's own
	 * during a big event. Shipped EMPTY on purpose: what that picture should
	 * look like is the shop's business, and a default of ours would be a
	 * house style nobody asked for. The promotion's title and dates are sent
	 * whatever it says.
	 */
	public static function hero_prompt(): string {
		return (string) ( self::get_settings()['hero_prompt'] ?? '' );
	}

	public static function default_hero_prompt(): string {
		return class_exists( 'DZE_Prompt_Defaults' ) ? DZE_Prompt_Defaults::pick( 'hero_image', '' ) : '';
	}

	/** Is the countdown decided by the rule below rather than by the model? */
	public static function timer_auto_on(): bool {
		return ! empty( self::get_settings()['timer_auto'] );
	}

	/** The bar an event has to clear: [ percent, days at most ]. */
	public static function timer_rule(): array {
		$s = self::get_settings();
		return [
			max( 1, (int) $s['timer_min_percent'] ),
			max( 1, (int) $s['timer_max_days'] ),
		];
	}

	/**
	 * Does this generated event carry a countdown?
	 *
	 * The model's own answer while the rule is off, the rule's answer while it
	 * is on — and the rule answers in BOTH directions, so an event under the
	 * bar loses the countdown the model felt like giving it. That is the whole
	 * point: a countdown on every promotion is not a deadline any more, it is
	 * decoration, and the customer stops reading it exactly when the shop
	 * needs him to.
	 *
	 * @param string $start     YYYY-MM-DD.
	 * @param string $end       YYYY-MM-DD.
	 * @param int    $percent   The discount announced.
	 * @param bool   $suggested What the model asked for.
	 */
	public static function timer_for( string $start, string $end, int $percent, bool $suggested ): bool {
		if ( ! self::timer_auto_on() ) {
			return $suggested;
		}
		$days = self::span_days( $start, $end );
		if ( $days < 1 ) {
			return false;
		}
		[ $min_pc, $max_days ] = self::timer_rule();
		return $percent >= $min_pc && $days <= $max_days;
	}

	/** Days a promotion runs, both ends counted — the way a shop counts them. */
	public static function span_days( string $start, string $end ): int {
		$a = strtotime( $start . ' 00:00:00' );
		$b = strtotime( $end . ' 00:00:00' );
		if ( ! $a || ! $b || $b < $a ) {
			return 0;
		}
		return (int) floor( ( $b - $a ) / DAY_IN_SECONDS ) + 1;
	}

	public static function events_prompt(): string {
		$p = trim( (string) ( self::get_settings()['events_prompt'] ?? '' ) );
		return $p !== '' ? $p : self::default_events_prompt();
	}

	/**
	 * How a promotion line is written for the shop's other markets.
	 *
	 * Not a translation, and not product copy: a banner line is four words
	 * that have to sound like a shop in that country. Rendered word for word,
	 * an English sale line reads like an import — which is exactly what came
	 * back before these instructions said otherwise.
	 */
	/**
	 * Is a promotion translated when it is saved?
	 *
	 * On by default, because a promotion with no wording in a language does
	 * not run in that language at all — but it writes to the shop on its own,
	 * so it says plainly that it is on, and it can be switched off.
	 */
	public static function promo_i18n_on(): bool {
		// get_settings() fills the shipped default (on) for a shop that has
		// never seen the switch.
		return ! empty( self::get_settings()['promo_i18n_on'] );
	}

	public static function promo_i18n_prompt(): string {
		$p = trim( (string) ( self::get_settings()['promo_i18n_prompt'] ?? '' ) );
		return '' !== $p ? $p : self::default_promo_i18n_prompt();
	}

	public static function default_promo_i18n_prompt(): string {
		$shipped = "- Do NOT translate. Write the line a shop in that market would write to announce the SAME offer. Same promotion, same tone, native wording. A word-for-word rendering of the original is a failure, even when it is correct.\n"
			. "- Use that market's own commercial vocabulary. What a shop calls a discount campaign differs by country, and the literal word is usually the wrong one.\n"
			. "- Watch the regulated words. In France \"soldes\" may only be used during the official sales periods set by law; outside them a shop writes \"promo\", \"offre\" or the name of its own event. Prefer a neutral commercial word when in doubt.\n"
			. "- Keep the figures exactly as they are: the percentage, the dates, the product names, the brand name.\n"
			. "- The DISCOUNT is part of the line and has to be said in that market's own way — the figure unchanged, the words and the typography local. \"-20% OFF\" left in English inside a French sentence is the failure this line exists to prevent.\n"
			. "- Keep it the same length or shorter. A banner line that wraps is a broken banner.\n"
			. "- Keep the register and the punctuation of the original, emoji included. Add nothing it does not say: no extra exclamation mark, no invented urgency, no \"limited time\" the original never claimed.\n"
			. '- Never quote the result and never explain it: the line itself, nothing else.';
		return class_exists( 'DZE_Prompt_Defaults' )
			? DZE_Prompt_Defaults::pick( 'promo_i18n', $shipped )
			: $shipped;
	}

	/** The default: the shop's own when it set one, the shipped text otherwise. */
	public static function default_events_prompt(): string {
		return class_exists( 'DZE_Prompt_Defaults' )
			? DZE_Prompt_Defaults::pick( 'events', self::DEFAULT_EVENTS_PROMPT )
			: self::DEFAULT_EVENTS_PROMPT;
	}

	/** Active site languages: WPML's list if active, else the single site locale. */
	public static function active_languages(): array {
		if ( class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active() ) {
			return DZE_Wpml::get_active_languages();
		}
		$code = strtolower( substr( get_locale(), 0, 2 ) );
		return [ [ 'code' => $code, 'native_name' => strtoupper( $code ), 'flag' => '' ] ];
	}

	/** Countries configured (or, first time, defaulted) for one language. */
	public static function country_pool_for( string $lang ): array {
		$saved = self::get_settings()['country_pools'][ $lang ] ?? null;
		if ( is_array( $saved ) && ! empty( $saved ) ) {
			return $saved;
		}
		return self::LANGUAGE_COUNTRY_POOLS[ $lang ] ?? [];
	}

	// =========================================================================
	// AI Assistant admin page (own submenu under the Dazont Ecom menu)
	// =========================================================================

	public function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Settings', 'dazont-ecom' ),
			__( 'Settings', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Central "Settings" page, one tab per AI-powered function:
	 *   General          — API keys, models, monthly API usage graph.
	 *   Product content  — upcoming content tools (placeholder).
	 *   Product images   — image prompt templates.
	 *   Marketing events — calendar languages, countries, context and prompt.
	 */
	public function render_settings_page(): void {
		// A disabled module leaves NO trace: its settings tab disappears with it.
		$mod_on = static fn( string $id ): bool => ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( $id );
		$tabs   = [ 'general' => __( 'General', 'dazont-ecom' ) ];
		if ( $mod_on( 'sourcing' ) ) {
			$tabs['sourcing'] = __( 'Sourcing Assistant', 'dazont-ecom' );
		}
		if ( $mod_on( 'content' ) ) {
			$tabs['content'] = __( 'Product content', 'dazont-ecom' );
		}

		if ( $mod_on( 'gmc_activation' ) ) {
			$tabs['gmc_activation'] = __( 'GMC activation', 'dazont-ecom' );
		}
		if ( $mod_on( 'category_content' ) ) {
			$tabs['categories'] = __( 'Categories', 'dazont-ecom' );
		}
		if ( $mod_on( 'reviews' ) ) {
			$tabs['reviews'] = __( 'Reviews', 'dazont-ecom' );
		}
		if ( $mod_on( 'translate' ) ) {
			$tabs['translate'] = __( 'Translation', 'dazont-ecom' );
		}
		if ( $mod_on( 'image_lab' ) ) {
			$tabs['lab'] = __( 'Image lab', 'dazont-ecom' );
		}
		if ( $mod_on( 'discounts' ) ) {
			$tabs['discounts'] = __( 'Discounts', 'dazont-ecom' );
		}
		$tabs['events']  = __( 'Marketing events', 'dazont-ecom' );
		if ( $mod_on( 'klaviyo' ) ) {
			$tabs['email'] = __( 'Email campaigns', 'dazont-ecom' );
		}
		if ( $mod_on( 'diagnostic' ) ) {
			$tabs['diagnostic'] = __( 'Diagnostic', 'dazont-ecom' );
		}
		if ( $mod_on( 'automation' ) ) {
			$tabs['automation'] = __( 'Automation', 'dazont-ecom' );
		}
		if ( $mod_on( 'health' ) ) {
			$tabs['health'] = __( 'Health', 'dazont-ecom' );
		}
		$tabs['modules'] = __( 'Modules', 'dazont-ecom' );
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab navigation only.
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}

		// Tabs that belong together stand together. Sixteen tabs in one row
		// is a row nobody reads: what a shop actually looks for is "the
		// content of my shop" or "my promotions", and the exact screen is
		// picked underneath — the way WooCommerce's own settings do it. The
		// tab keys do not change, so every link ever printed at one of these
		// screens still lands on it.
		$groups = [
			'shop'    => [
				'label' => __( 'Shop content', 'dazont-ecom' ),
				'tabs'  => [ 'categories', 'content', 'gmc_activation', 'reviews' ],
			],
			'promo'   => [
				'label' => __( 'Discounts', 'dazont-ecom' ),
				'tabs'  => [ 'discounts', 'events', 'email' ],
			],
		];
		// Sections read the same as the tab they were, except where the group
		// already says it: "Marketing events → Marketing events" says it twice.
		$section_labels = [
			'events'    => __( 'Events', 'dazont-ecom' ),
			'discounts' => __( 'General', 'dazont-ecom' ),
		];

		echo '<div class="wrap dze-wrap">';
		echo '<h1>' . esc_html__( 'Settings', 'dazont-ecom' ) . '</h1>';
		$link = static fn( string $key ): string => esc_url( add_query_arg(
			[ 'page' => self::MENU_SLUG, 'tab' => $key ],
			admin_url( 'admin.php' )
		) );
		// Which group each tab that is in one belongs to, and which tabs each
		// group really has on this shop — a group whose modules are all off is
		// not drawn at all.
		$group_of = [];
		$members  = [];
		foreach ( $groups as $gid => $group ) {
			foreach ( $group['tabs'] as $key ) {
				if ( isset( $tabs[ $key ] ) ) {
					$group_of[ $key ] = $gid;
					$members[ $gid ][] = $key;
				}
			}
		}
		$here = $group_of[ $tab ] ?? '';

		echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px;">';
		$drawn = [];
		foreach ( $tabs as $key => $label ) {
			$gid = $group_of[ $key ] ?? '';
			if ( '' !== $gid ) {
				if ( isset( $drawn[ $gid ] ) ) {
					continue; // its group is already on the row.
				}
				$drawn[ $gid ] = true;
				printf(
					'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
					$link( (string) $members[ $gid ][0] ),
					$gid === $here ? ' nav-tab-active' : '',
					esc_html( (string) $groups[ $gid ]['label'] )
				);
				continue;
			}
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				$link( (string) $key ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		// The screens inside the group, in WordPress's own quiet sub-navigation.
		if ( '' !== $here && count( (array) $members[ $here ] ) > 1 ) {
			echo '<ul class="subsubsub" style="margin:-8px 0 16px;float:none;">';
			$last = end( $members[ $here ] );
			foreach ( $members[ $here ] as $key ) {
				printf(
					'<li><a href="%1$s"%2$s>%3$s</a>%4$s</li>',
					$link( (string) $key ),
					$key === $tab ? ' class="current"' : '',
					esc_html( (string) ( $section_labels[ $key ] ?? $tabs[ $key ] ) ),
					$key === $last ? '' : ' | '
				);
			}
			echo '</ul><div style="clear:both;"></div>';
		}

		// A tab that dies takes the whole screen with it, and a white page
		// tells nobody anything — not the owner, who sees a broken plugin, and
		// not whoever has to fix it, who sees nothing at all. PHP lets an
		// Error be caught like any other throwable, so it is: the screen says
		// which tab failed, what the message was and at which file and line,
		// the rest of the page stays usable, and the weekly checkup gets a
		// copy. It cannot catch a memory or a timeout death, and it says that
		// too rather than pretending otherwise.
		try {
			$this->render_tab_body( $tab, $mod_on );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'DZE_Health' ) ) {
				DZE_Health::log( 'plugin', 'settings tab: ' . $tab, $e->getMessage() );
			}
			// The paragraph carries the plugin's own failure class, so the link
			// to the log lands here like it does on every other failure.
			echo '<div class="notice notice-error"><p class="is-ko"><strong>' .
				esc_html(
					sprintf(
						/* translators: %s: the settings tab that failed */
						__( 'The "%s" tab could not be drawn.', 'dazont-ecom' ),
						$tab
					)
				) . '</strong></p><p><code>' . esc_html( $e->getMessage() ) . '</code></p><p>' .
				esc_html( $e->getFile() . ' : ' . $e->getLine() ) . '</p><p class="description">' .
				esc_html__( 'Send this to the plugin author. The other tabs still work.', 'dazont-ecom' ) .
				'</p></div>';
		}
		// A link that lands on a field inside a shut block would land on nothing:
		// every ancestor of the target is opened, then it is scrolled to and
		// flashed. This is what keeps "edit these instructions" working now that
		// the settings are folded away by default.
		?>
		<script>
		jQuery( function ( $ ) {
			function reveal() {
				var id = window.location.hash.replace( '#', '' );
				if ( ! id ) { return; }
				var el = document.getElementById( id );
				if ( ! el ) { return; }
				$( el ).parents( 'details' ).prop( 'open', true );
				// A prompt card is not a <details>: it opens on its own button.
				var $card = $( el ).closest( '.dze-prb' );
				if ( $card.length && ! $card.hasClass( 'is-open' ) ) { $card.find( '.dze-prb-toggle' ).trigger( 'click' ); }
				window.setTimeout( function () {
					el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					$( el ).addClass( 'dze-flash' );
					window.setTimeout( function () { $( el ).removeClass( 'dze-flash' ); }, 1600 );
				}, 60 );
			}
			reveal();
			$( window ).on( 'hashchange', reveal );
		} );
		</script>
		<?php
		echo '</div>';
	}

	/**
	 * One settings tab, drawn. Split out so the screen above can survive it
	 * failing: a tab is a module's own code, and a module can be broken.
	 *
	 * @param callable $mod_on Tells whether a module is enabled.
	 */
	private function render_tab_body( string $tab, callable $mod_on ): void {
		if ( 'general' === $tab ) {
			echo '<h2>' . esc_html__( 'About this shop', 'dazont-ecom' ) . '</h2>';
			$this->render_shop_profile();
			echo '<hr style="margin:28px 0;" />';
			echo '<p class="description">' . esc_html__( 'API keys, models and monthly budget. The Anthropic key powers the text generation (content, marketing calendar, sourcing); the fal.ai key powers the image generation. Each key is only ever sent to its own provider.', 'dazont-ecom' ) . '</p>';
			echo '<h2>' . esc_html__( 'Anthropic (Claude)', 'dazont-ecom' ) . '</h2>';
			$this->render_settings_section( 'general' );
			// The fal key lives in Content settings but also powers POD.
			if ( class_exists( 'DZE_Content' ) && $mod_on( 'content' ) ) {
				echo '<hr style="margin:28px 0;" />';
				echo '<h2>' . esc_html__( 'fal.ai (image generation)', 'dazont-ecom' ) . '</h2>';
				DZE_Content::instance()->render_key_field();
			}
			// Price endings used to sit here for want of anywhere better. They
			// belong with the rest of what decides a price, under
			// Discounts → General — unless Discounts is off, in which case
			// Product Content still computes prices and this is again the only
			// general tab there is.
			if ( class_exists( 'DZE_Price' ) && ! DZE_Modules::enabled( 'discounts' ) && DZE_Modules::enabled( 'content' ) ) {
				echo '<hr style="margin:28px 0;" />';
				echo '<h2>' . esc_html__( 'Price endings', 'dazont-ecom' ) . '</h2>';
				self::render_price_rounding();
			}
			echo '<hr style="margin:28px 0;" />';
			echo '<h2>' . esc_html__( 'API usage and spend', 'dazont-ecom' ) . '</h2>';
			DZE_Ai_Usage::render_graph();
			echo '<hr style="margin:28px 0;" />';
			echo '<h2 id="dze-ai-trace">' . esc_html__( 'Last AI calls', 'dazont-ecom' ) . '</h2>';
			DZE_Ai_Usage::render_trace();
		} elseif ( 'discounts' === $tab ) {
			// Everything the Discounts module decides about the shop that is
			// not one promotion: the badge, and the ending every computed price
			// lands on. The promotions themselves are the two tabs beside this
			// one.
			if ( class_exists( 'DZE_Discounts' ) && $mod_on( 'discounts' ) ) {
				DZE_Discounts::render_general_settings();
			}
			if ( class_exists( 'DZE_Price' ) ) {
				echo '<hr style="margin:28px 0;" />';
				echo '<h2>' . esc_html__( 'Price endings', 'dazont-ecom' ) . '</h2>';
				self::render_price_rounding();
			}
		} elseif ( 'sourcing' === $tab ) {
			$this->render_sourcing_settings();
		} elseif ( 'content' === $tab ) {
			if ( class_exists( 'DZE_Content' ) && $mod_on( 'content' ) ) {
				DZE_Content::instance()->render_settings_section();
			}

		} elseif ( 'lab' === $tab ) {
			if ( class_exists( 'DZE_Image_Lab' ) && $mod_on( 'image_lab' ) ) {
				DZE_Image_Lab::instance()->render();
			}
		} elseif ( 'gmc_activation' === $tab ) {
			if ( class_exists( 'DZE_Gmc_Activation' ) && $mod_on( 'gmc_activation' ) ) {
				DZE_Gmc_Activation::instance()->render_settings();
			}
		} elseif ( 'categories' === $tab ) {
			if ( class_exists( 'DZE_Category_Content' ) && $mod_on( 'category_content' ) ) {
				DZE_Category_Content::instance()->render_settings();
			}
		} elseif ( 'reviews' === $tab ) {
			if ( class_exists( 'DZE_Reviews' ) && $mod_on( 'reviews' ) ) {
				DZE_Reviews::instance()->render_settings();
			}
		} elseif ( 'translate' === $tab ) {
			if ( class_exists( 'DZE_Translate' ) && $mod_on( 'translate' ) ) {
				DZE_Translate::render_settings();
			}
		} elseif ( 'email' === $tab ) {
			if ( class_exists( 'DZE_Klaviyo' ) && $mod_on( 'klaviyo' ) ) {
				DZE_Klaviyo::render_settings();
			}
		} elseif ( 'diagnostic' === $tab ) {
			if ( class_exists( 'DZE_Diagnostic' ) && $mod_on( 'diagnostic' ) ) {
				DZE_Diagnostic::render_settings();
			}
		} elseif ( 'automation' === $tab ) {
			if ( class_exists( 'DZE_Automation' ) && $mod_on( 'automation' ) ) {
				DZE_Automation::render_settings();
			}
		} elseif ( 'health' === $tab ) {
			if ( class_exists( 'DZE_Health' ) && $mod_on( 'health' ) ) {
				DZE_Health::render();
			}
		} elseif ( 'modules' === $tab ) {
			if ( class_exists( 'DZE_Modules' ) ) {
				DZE_Modules::instance()->render_tab();
			}
		} else { // events.
			$this->render_settings_section( 'events' );
		}
	}

	/** Settings for the Sourcing Assistant module (keyword analysis + report). */
	public function render_sourcing_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s   = self::get_settings();
		$opt = self::OPT_SETTINGS;
		$explorer_url = class_exists( 'DZE_Explorer' ) ? add_query_arg( [ 'page' => DZE_Explorer::MENU_SLUG ], admin_url( 'admin.php' ) ) : '';
		$models = self::available_models();
		// Keep a saved-but-unlisted id selectable.
		$match_cur = (string) ( $s['match_model'] ?? '' );
		$ins_cur   = (string) ( $s['insights_model'] ?? '' );
		$match_models = ( $match_cur !== '' && ! array_key_exists( $match_cur, $models ) ) ? [ $match_cur => $match_cur ] + $models : $models;
		$ins_models   = ( $ins_cur !== '' && ! array_key_exists( $ins_cur, $models ) ) ? [ $ins_cur => $ins_cur ] + $models : $models;
		?>
		<p class="description">
			<?php esc_html_e( 'Controls for the Sourcing Assistant: which models judge keyword coverage and write the sourcing report, and the default volume threshold for the keyword analysis.', 'dazont-ecom' ); ?>
			<?php if ( $explorer_url ) : ?><a href="<?php echo esc_url( $explorer_url ); ?>"><?php esc_html_e( 'Open the Sourcing Assistant →', 'dazont-ecom' ); ?></a><?php endif; ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_mai_options' ); ?>
			<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[section]" value="sourcing" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dze-mai-match-model"><?php esc_html_e( 'Keyword-matching model', 'dazont-ecom' ); ?></label></th>
					<td>
						<select id="dze-mai-match-model" name="<?php echo esc_attr( $opt ); ?>[match_model]">
							<option value=""<?php selected( '', $match_cur ); ?>><?php esc_html_e( 'Default (Haiku — fast & cheap)', 'dazont-ecom' ); ?></option>
							<?php foreach ( $match_models as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>"<?php selected( $id, $match_cur ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Judges which product/category covers each keyword — a simple, repetitive task. Haiku is ~10× cheaper and is the default when left empty.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-mai-ins-model"><?php esc_html_e( 'Sourcing report model', 'dazont-ecom' ); ?></label></th>
					<td>
						<select id="dze-mai-ins-model" name="<?php echo esc_attr( $opt ); ?>[insights_model]">
							<option value=""<?php selected( '', $ins_cur ); ?>><?php /* translators: %s: main model name */ echo esc_html( sprintf( __( 'Default (main model — %s)', 'dazont-ecom' ), self::chosen_model() ) ); ?></option>
							<?php foreach ( $ins_models as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>"<?php selected( $id, $ins_cur ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Writes the "sourcing opportunities" report — needs quality. Empty = the main Claude model from the General tab.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-mai-minvol"><?php esc_html_e( 'Default volume threshold', 'dazont-ecom' ); ?></label></th>
					<td>
						<input type="number" id="dze-mai-minvol" name="<?php echo esc_attr( $opt ); ?>[sourcing_minvol]" value="<?php echo esc_attr( (int) ( $s['sourcing_minvol'] ?? 10 ) ); ?>" min="0" style="width:90px;" />
						<p class="description"><?php esc_html_e( 'Keywords below this monthly search volume are skipped by the analysis by default (faster, cheaper). You can still change it per run in the Sourcing Assistant. 0 = analyse everything.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-mai-match-rules"><?php esc_html_e( 'Keyword-matching rules (prompt)', 'dazont-ecom' ); ?></label>
						<p class="description" style="font-weight:normal;"><?php esc_html_e( 'The rules the AI follows to decide covered / variation / gap for each query.', 'dazont-ecom' ); ?></p>
					</th>
					<td>
						<textarea id="dze-mai-match-rules" name="<?php echo esc_attr( $opt ); ?>[match_rules]" rows="14" class="large-text code"><?php echo esc_textarea( class_exists( 'DZE_Keywords' ) ? DZE_Keywords::match_rules() : '' ); ?></textarea>
						<details style="margin-top:6px;">
							<summary class="description" style="cursor:pointer;"><?php esc_html_e( 'Fixed parts added automatically (read-only)', 'dazont-ecom' ); ?></summary>
							<pre class="description" style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:8px 10px;">BEFORE your rules: the category name, the product list (id | title | attributes) and the query batch (id. query (volume)).
AFTER your rules: Output: JSON array of {"id":&lt;query id&gt;,"t":"category|product|info","s":"covered|variation|gap|ignored","p":[product ids]} for every query id listed.</pre>
						</details>
						<p class="description">
							<?php esc_html_e( 'Leave the text identical to the default to keep receiving default improvements with plugin updates.', 'dazont-ecom' ); ?>
							<button type="button" class="button-link dze-mai-restore" data-target="dze-mai-match-rules">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
							<?php if ( class_exists( 'DZE_Prompt_Defaults' ) ) { DZE_Prompt_Defaults::control( 'keyword_match', '#dze-mai-match-rules' ); } ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-mai-report-guidance"><?php esc_html_e( 'Sourcing report instructions (prompt)', 'dazont-ecom' ); ?></label>
						<p class="description" style="font-weight:normal;"><?php esc_html_e( 'Persona and analysis guidance for the "sourcing opportunities" report — including how sales figures are used and the no-duplicates rule.', 'dazont-ecom' ); ?></p>
					</th>
					<td>
						<textarea id="dze-mai-report-guidance" name="<?php echo esc_attr( $opt ); ?>[report_guidance]" rows="10" class="large-text code"><?php echo esc_textarea( class_exists( 'DZE_Explorer' ) ? DZE_Explorer::report_guidance() : '' ); ?></textarea>
						<details style="margin-top:6px;">
							<summary class="description" style="cursor:pointer;"><?php esc_html_e( 'Fixed parts added automatically (read-only)', 'dazont-ecom' ); ?></summary>
							<pre class="description" style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:8px 10px;">DATA: category path, ALL products with lifetime units sold (best-sellers first), and the top uncovered queries (gaps) by volume.
OUTPUT FORMAT (fixed so the report always renders):
"summary": 2-3 sentences — contents today, what sells best, biggest weaknesses.
"source_list": every uncovered query grouped into concrete products to source {product, queries, volume}, sorted by volume.
"ideas": 5-15 product ideas absent from BOTH the catalogue and the query list {product, why}.
A safety filter also removes suggestions matching an existing product title.</pre>
						</details>
						<p class="description">
							<?php esc_html_e( 'Leave the text identical to the default to keep receiving default improvements with plugin updates.', 'dazont-ecom' ); ?>
							<button type="button" class="button-link dze-mai-restore" data-target="dze-mai-report-guidance">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
							<?php if ( class_exists( 'DZE_Prompt_Defaults' ) ) { DZE_Prompt_Defaults::control( 'sourcing_report', '#dze-mai-report-guidance' ); } ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<script>
		jQuery( function ( $ ) {
			// The default of a prompt is the shop's own when it set one, and it
			// can be set from this very page without reloading it.
			function dzeDef( id, shipped ) {
				return window.dzeDefaultFor ? window.dzeDefaultFor( id, shipped ) : shipped;
			}
			// Refill a prompt textarea with its default (a text saved exactly as
			// the default is stored as "use the default").
			var dzeShipped = {
				'dze-mai-match-rules': <?php echo wp_json_encode( class_exists( 'DZE_Keywords' ) ? DZE_Keywords::default_match_rules() : '' ); ?>,
				'dze-mai-report-guidance': <?php echo wp_json_encode( class_exists( 'DZE_Explorer' ) ? DZE_Explorer::default_report_guidance() : '' ); ?>
			};
			var dzeMaiId = { 'dze-mai-match-rules': 'keyword_match', 'dze-mai-report-guidance': 'sourcing_report' };
			$( '.dze-mai-restore' ).on( 'click', function () {
				var id = $( this ).data( 'target' ), d = dzeDef( dzeMaiId[ id ], dzeShipped[ id ] || '' );
				if ( d ) { $( '#' + id ).val( d ); }
			} );
		} );
		</script>
		<?php
	}

	/**
	 * The shared price-ending control. Its own form and its own option: it
	 * belongs to no module in particular, and both Discounts and Product
	 * Content read it.
	 */
	private static function render_price_rounding(): void {
		$current = DZE_Price::mode();
		?>
		<form method="post" action="options.php" class="dze-admin">
			<?php settings_fields( 'dze_price_options' ); ?>
			<p class="description">
				<?php esc_html_e( 'Computed prices land on the ending you pick instead of whatever the arithmetic produces. Sale prices round DOWN, so the reduction is never smaller than the percentage announced; selling prices computed from a cost round UP, so no margin is lost to the presentation.', 'dazont-ecom' ); ?>
			</p>
			<p>
				<select name="<?php echo esc_attr( DZE_Price::OPTION ); ?>">
					<?php foreach ( DZE_Price::endings() as $dze_k => $dze_e ) : ?>
						<option value="<?php echo esc_attr( $dze_k ); ?>" <?php selected( $dze_k, $current ); ?>><?php echo esc_html( $dze_e['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Save', 'dazont-ecom' ), 'secondary', 'submit', false ); ?>
			</p>
			<?php $dze_pv = DZE_Price::preview(); ?>
			<?php if ( $dze_pv ) : ?>
				<p class="description"><strong><?php echo esc_html( $dze_pv ); ?></strong></p>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'Existing prices are untouched: the ending applies to what the plugin computes from now on. Endings at the unit level (.90, .95, .99) can give away up to one unit on a sale price — the ten-cent endings give away at most ten cents.', 'dazont-ecom' ); ?>
			</p>
		</form>
		<?php
	}

	/** @param string $dze_section 'all', 'general' (key+model) or 'events' (the rest). */
	public function render_settings_section( string $dze_section = 'all' ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$settings   = self::get_settings();
		$key_locked = defined( 'DZE_ANTHROPIC_API_KEY' );
		$has_key    = $this->api_key() !== '';
		$languages  = self::active_languages();
		$context    = 'general' === $dze_section ? '' : $this->shop_context_text();
		require DZE_DIR . 'admin/views/marketing-ai-settings.php';
	}

	// =========================================================================
	// Shop context auto-detection
	// =========================================================================

	public const CTX_TRANSIENT = 'dze_mai_shop_context';

	/**
	 * Structured, auto-detected facts about the shop. Categories and products
	 * are ranked by real sales volume (WooCommerce Analytics lookup table),
	 * descending, with graceful fallbacks when analytics hasn't synced. Cached
	 * for an hour; cleared on demand from the settings tab.
	 */
	private function shop_context(): array {
		$cached = get_transient( self::CTX_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$categories = $this->top_categories_by_sales( 15 ) ?: $this->fallback_categories( 15 );
		$products   = $this->top_products_by_sales( 12 ) ?: $this->fallback_products( 12 );
		[ $price_min, $price_max ] = $this->price_range();

		$product_count = 0;
		$counts = wp_count_posts( 'product' );
		if ( $counts && isset( $counts->publish ) ) {
			$product_count = (int) $counts->publish;
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$country  = '';
		if ( function_exists( 'wc_get_base_location' ) ) {
			$loc     = wc_get_base_location();
			$country = (string) ( $loc['country'] ?? '' );
		}

		$context = [
			'name'          => get_bloginfo( 'name' ),
			'tagline'       => get_bloginfo( 'description' ),
			'categories'    => $categories, // best-selling first
			'products'      => $products,   // best-selling first
			'product_count' => $product_count,
			'price_min'     => $price_min,
			'price_max'     => $price_max,
			'currency'      => $currency,
			'country'       => $country,
		];
		set_transient( self::CTX_TRANSIENT, $context, HOUR_IN_SECONDS );
		return $context;
	}

	/** WooCommerce Analytics product-lookup table name, or null if unavailable. */
	private function analytics_table(): ?string {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) ? $table : null;
	}

	/** Product categories ranked by units sold (all-time), descending. */
	private function top_categories_by_sales( int $limit ): array {
		$table = $this->analytics_table();
		if ( ! $table ) {
			return [];
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is derived from $wpdb->prefix.
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT t.name
			 FROM {$table} l
			 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = l.product_id
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
			 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			 WHERE t.slug != 'uncategorized'
			 GROUP BY t.term_id
			 ORDER BY SUM(l.product_qty) DESC
			 LIMIT %d",
			$limit
		) );
		return array_values( array_filter( array_map( 'trim', (array) $rows ) ) );
	}

	/** Products ranked by units sold (all-time), descending. */
	private function top_products_by_sales( int $limit ): array {
		$table = $this->analytics_table();
		if ( ! $table ) {
			return [];
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is derived from $wpdb->prefix.
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.post_title
			 FROM {$table} l
			 INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
			 WHERE p.post_status = 'publish' AND p.post_type = 'product'
			 GROUP BY l.product_id
			 ORDER BY SUM(l.product_qty) DESC
			 LIMIT %d",
			$limit
		) );
		return array_values( array_filter( array_map( 'trim', (array) $rows ) ) );
	}

	private function fallback_categories( int $limit ): array {
		$out   = [];
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => $limit, 'orderby' => 'count', 'order' => 'DESC' ] );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( $t->slug !== 'uncategorized' ) {
					$out[] = $t->name;
				}
			}
		}
		return $out;
	}

	private function fallback_products( int $limit ): array {
		$out = [];
		if ( function_exists( 'wc_get_products' ) ) {
			foreach ( wc_get_products( [ 'limit' => $limit, 'status' => 'publish', 'orderby' => 'popularity', 'order' => 'DESC' ] ) as $p ) {
				$out[] = $p->get_name();
			}
		}
		return $out;
	}

	/** Min/max published product price, ignoring free (0) items. */
	private function price_range(): array {
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT MIN(CAST(pm.meta_value AS DECIMAL(10,2))) AS min_p, MAX(CAST(pm.meta_value AS DECIMAL(10,2))) AS max_p
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_price' AND pm.meta_value != '' AND CAST(pm.meta_value AS DECIMAL(10,2)) > 0
			   AND p.post_status = 'publish' AND p.post_type = 'product'"
		);
		if ( $row && $row->min_p !== null ) {
			return [ round( (float) $row->min_p, 2 ), round( (float) $row->max_p, 2 ) ];
		}
		return [ null, null ];
	}

	/**
	 * Human-readable version of shop_context(), sent to Claude and shown as a
	 * preview. Deliberately lean: the store name, its tagline, plus (optionally)
	 * the product categories/best-sellers. Legal address, catalog size and price
	 * range are intentionally left out — they don't help a calendar built around
	 * commercial moments and often mislead.
	 */
	/**
	 * WHAT THIS SHOP IS, in the owner's own words.
	 *
	 * One text for the whole plugin: the calendar, the product texts, the
	 * category pages, the reviews, the sourcing report. There used to be two —
	 * a line typed under Product content, and a list of names the plugin
	 * assembled by itself (store name, fifteen categories, twelve best
	 * sellers). The second one described a catalogue, not a business, and no
	 * amount of best-seller names says "an online shop selling tactical and
	 * military gear".
	 *
	 * Empty here, the old Product content line is used, so nothing that was
	 * written before is lost while it has not been moved.
	 */
	public static function shop_profile(): string {
		$mine = trim( (string) ( self::get_settings()['shop_profile'] ?? '' ) );
		if ( '' !== $mine ) {
			return $mine;
		}
		$old = class_exists( 'DZE_Content' )
			? trim( (string) ( DZE_Content::get_settings()['store_context'] ?? '' ) )
			: '';
		return $old;
	}

	/**
	 * The shop, as context for a generation: its own description and nothing
	 * else.
	 *
	 * It used to add a brand-tone field and, optionally, fifteen category names
	 * and twelve best-seller names — a second description of the shop, kept in
	 * a second place, saying less than the first. What the shop is, how it
	 * speaks and what it sells is one text now, written under Settings →
	 * General → About this shop.
	 */
	public function shop_context_text(): string {
		$mine = self::shop_profile();
		if ( '' !== $mine ) {
			return $mine;
		}
		// Nothing written yet: the two facts WordPress does know stand in until
		// something is.
		$c     = $this->shop_context();
		$lines = [];
		if ( $c['name'] !== '' ) {
			$lines[] = sprintf( 'Store name: %s', $c['name'] );
		}
		if ( $c['tagline'] !== '' ) {
			$lines[] = sprintf( 'What the store sells (tagline): %s', $c['tagline'] );
		}
		return implode( "\n", $lines );
	}

	/**
	 * A first version of the shop's description, written from the shop.
	 *
	 * The catalogue is a poor DESCRIPTION of a business, but it is a decent
	 * thing to write one FROM: the model reads the name, the tagline, the
	 * categories and the best sellers once, and hands back a few lines the
	 * owner corrects and saves. After that the text is his, and the catalogue
	 * is never consulted again for it.
	 */
	public function ajax_profile_draft(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		if ( '' === $this->api_key() ) {
			wp_send_json_error( [ 'message' => __( 'Add your Anthropic API key first.', 'dazont-ecom' ) ] );
		}
		// WHAT THE SHOP STOCKS, not what it once sold.
		//
		// The first version of this read the best-selling categories and the
		// best-selling products — lifetime sales, which describe the shop's
		// PAST. A catalogue that has moved on comes back described by the theme
		// that sold three years ago, as a specialisation it no longer has. What
		// says what a shop is today is the shape of its catalogue: every
		// category, with how many products it holds.
		$facts = [];
		$name  = get_bloginfo( 'name' );
		$tag   = get_bloginfo( 'description' );
		if ( '' !== trim( (string) $name ) ) {
			$facts[] = 'Name: ' . $name;
		}
		if ( '' !== trim( (string) $tag ) ) {
			$facts[] = 'Tagline: ' . $tag;
		}
		// THE HOME PAGE, first of all.
		//
		// It is the shop's own shop window, written by the owner for his
		// customers: it says what the place is far better than any figure the
		// catalogue can produce. The catalogue comes after it, for the breadth
		// alone.
		$home = self::front_page_text();
		if ( '' !== $home ) {
			$facts[] = "The shop's own home page, which is how it presents itself:\n" . $home;
		}
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'number'     => 40,
			'orderby'    => 'count',
			'order'      => 'DESC',
		] );
		$lines = [];
		$total = 0;
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( 'uncategorized' === $t->slug ) {
					continue;
				}
				$lines[] = $t->name . ' (' . (int) $t->count . ')';
				$total  += (int) $t->count;
			}
		}
		if ( $lines ) {
			$facts[] = 'Catalogue, every category with how many products it holds: ' . implode( ', ', $lines );
		}
		$counts = wp_count_posts( 'product' );
		if ( $counts && ! empty( $counts->publish ) ) {
			$facts[] = 'Published products in total: ' . (int) $counts->publish;
		}
		if ( ! $facts ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to read: this shop has no name, no tagline and no products yet.', 'dazont-ecom' ) ] );
		}
		$system = 'You describe an online shop in a few plain sentences, for another AI that will write its product texts and its marketing.';
		$user   = "Here is what is known about the shop:\n" . implode( "\n", $facts ) . "\n\n"
			. "The home page, when there is one, is the shop speaking about itself: follow it. The category list is only there to say how wide the range is.\n"
			. "Write 3 to 5 short lines saying WHAT THIS SHOP IS: what it sells, to whom, and how wide its range is. "
			. "Start with one line of the form \"Online shop selling X (Name).\" "
			. "Describe the range AS A WHOLE, in product families. "
			. "Do NOT turn a recurring word into a speciality: a theme is worth naming only if it covers a large share of the catalogue, "
			. "and even then as one part of the range, never as what the shop is about. "
			. "Do not name individual products, do not talk about best sellers, do not guess at history, "
			. "and write nothing the facts above do not support. "
			. 'Write in ' . ( class_exists( 'DZE_Content' ) ? DZE_Content::site_language() : 'English' ) . '. '
			. 'Plain lines, no markdown, no heading, no preamble — the text only.';
		try {
			$text = self::complete( $system, $user, '', 400, 60 );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'text' => trim( $text ) ] );
	}

	/**
	 * The text of the shop's front page, plain.
	 *
	 * A static front page when there is one, the WooCommerce shop page
	 * otherwise. Shortcodes and markup are stripped and the whole thing is
	 * capped: this is read once, to write a few lines from.
	 *
	 * A home page built entirely by a page builder keeps its text in its own
	 * meta and comes back empty here — the catalogue then has to do, which is
	 * exactly what it is there for.
	 */
	public static function front_page_text( int $max = 5000 ): string {
		$pid = 0;
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$pid = (int) get_option( 'page_on_front' );
		}
		if ( ! $pid && function_exists( 'wc_get_page_id' ) ) {
			$pid = (int) wc_get_page_id( 'shop' );
		}
		if ( $pid <= 0 ) {
			return '';
		}
		$raw = (string) get_post_field( 'post_content', $pid );
		if ( '' === trim( $raw ) ) {
			return '';
		}
		$txt = wp_strip_all_tags( strip_shortcodes( $raw ) );
		$txt = trim( (string) preg_replace( "/\n{3,}/", "\n\n", (string) preg_replace( '/[ \t]+/', ' ', $txt ) ) );
		return mb_substr( $txt, 0, $max );
	}

	// =========================================================================
	// AJAX: generate the calendar
	// =========================================================================

	public function ajax_generate(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		if ( $this->api_key() === '' ) {
			wp_send_json_error( [ 'message' => __( 'Add your Anthropic API key first, under Settings → AI Marketing Assistant.', 'dazont-ecom' ) ] );
		}

		$start = $this->clean_date( wp_unslash( $_POST['start_date'] ?? '' ) );
		$end   = $this->clean_date( wp_unslash( $_POST['end_date'] ?? '' ) );
		if ( $start === '' || $end === '' ) {
			wp_send_json_error( [ 'message' => __( 'Pick a start and end date for the calendar.', 'dazont-ecom' ) ] );
		}
		if ( $start > $end ) {
			wp_send_json_error( [ 'message' => __( 'The start date must be before the end date.', 'dazont-ecom' ) ] );
		}

		// ONE calendar, for the shop.
		//
		// The screen used to ask for a language and a list of countries before
		// it would generate anything. Nothing is generated per country, and a
		// promotion running in one language only is not what a shop wants — it
		// is what an owner discovers on the day his other customers do not see
		// the sale. The wording is written in the shop's main language; which
		// commercial moments matter comes from what the shop says about itself.
		// The Claude call can take up to ~60s; give PHP room beyond typical
		// 30s shared-host limits so it isn't killed mid-request.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		try {
			$count = self::propose( $start, $end )['added'];
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}

		wp_send_json_success( [
			'count'   => $count,
			'message' => $count === 0
				? __( 'No real commercial moment in this window, so nothing is proposed — an empty stretch is an answer, not a failure. Widen the range if you expected one.', 'dazont-ecom' )
				/* translators: %d: number of generated marketing events */
				: sprintf( _n( '%d suggestion generated.', '%d suggestions generated.', $count, 'dazont-ecom' ), $count ),
		] );
	}

	/**
	 * Proposes a calendar for a window and files what comes back.
	 *
	 * The one way suggestions are ever created — the button on the screen and
	 * the monthly automation both come through here, so both dedupe the same
	 * way and both leave the result waiting for a yes or a no. Nothing this
	 * writes reaches the shop: a suggestion becomes an event only when it is
	 * accepted, and even then it is created disabled.
	 *
	 * @return array{added:int,skipped:int}
	 */
	public static function propose( string $start, string $end ): array {
		$self   = self::instance();
		$events = $self->generate_events( $start, $end, self::primary_language(), [] );

		// Translated here, not at acceptance: what the owner reviews is the
		// event as it will exist — its title in every language he sells in,
		// under his eyes before he says yes. One call for the whole calendar.
		$langs = ( self::promo_i18n_on() && class_exists( 'DZE_Discounts' ) ) ? DZE_Discounts::promo_langs() : [];
		if ( $langs && $events ) {
			try {
				$lines = DZE_Discounts::translate_lines( array_column( $events, 'title' ), $langs );
				foreach ( $lines as $i => $one ) {
					if ( isset( $events[ $i ] ) ) {
						$events[ $i ]['i18n'] = $one;
					}
				}
			} catch ( \Throwable $e ) {
				// A calendar without its translations is still a calendar: the
				// popup and the accept path ask again.
				unset( $e );
			}
		}

		// A moment already on the calendar is not a suggestion: an event
		// accepted last month must not come back every month.
		$taken = [];
		if ( class_exists( 'DZE_Discounts' ) ) {
			foreach ( DZE_Discounts::get_rules() as $rule ) {
				if ( 'sale' === ( $rule['type'] ?? '' ) ) {
					$taken[ mb_strtolower( trim( (string) ( $rule['title'] ?? '' ) ) ) . '|' . (string) ( $rule['start'] ?? '' ) ] = true;
				}
			}
		}
		$existing = self::get_suggestions();
		$added    = 0;
		$skipped  = 0;
		foreach ( array_reverse( $events ) as $ev ) {
			if ( isset( $taken[ mb_strtolower( trim( (string) $ev['title'] ) ) . '|' . (string) $ev['start_date'] ] ) ) {
				$skipped++;
				continue;
			}
			$id       = 'sug_' . substr( md5( $ev['title'] . '|' . $ev['start_date'] . '|' . wp_json_encode( $ev['countries'] ) ), 0, 10 );
			$ev['id'] = $id;
			$existing = [ $id => $ev ] + $existing;
			$added++;
		}
		self::save_suggestions( $existing );
		return [ 'added' => $added, 'skipped' => $skipped ];
	}

	/** How far ahead the accepted calendar already runs, as a timestamp (0 = nothing). */
	public static function covered_until(): int {
		$last = 0;
		if ( class_exists( 'DZE_Discounts' ) ) {
			foreach ( DZE_Discounts::get_rules() as $rule ) {
				if ( 'sale' !== ( $rule['type'] ?? '' ) ) {
					continue;
				}
				$end = strtotime( (string) ( $rule['end'] ?? '' ) );
				if ( $end && $end > $last ) {
					$last = $end;
				}
			}
		}
		return $last;
	}

	/**
	 * Builds the prompt, calls Claude, returns a validated list of events — for
	 * a single language, optionally restricted to given countries.
	 */
	private function generate_events( string $start_date, string $end_date, string $lang, array $countries ): array {
		$native = strtoupper( $lang );
		foreach ( self::active_languages() as $l ) {
			if ( $l['code'] === $lang ) {
				$native = $l['native_name'];
				break;
			}
		}
		// No country is asked for any more. What the shop sells and to whom is
		// in its own description; a market only matters here when it changes
		// which commercial moments are real, and that is the shop's to say.
		$country_line = $countries
			? implode( ', ', $countries )
			: 'the shop as a whole — every market it sells to. Propose the commercial moments that are real for its customers, and skip a holiday that is specific to a country the shop has not mentioned.';

		$system = self::events_prompt() . "\n\nYou reply with JSON only.";

		$schema = '{"events":[{"title":string (<=60 chars),"type":"sale",'
			. '"start_date":"YYYY-MM-DD","end_date":"YYYY-MM-DD","percent":integer 5-70,'
			. '"timer":boolean,'
			. '"rationale":string (one short sentence naming the real occasion it maps to)}]}';

		$context = $this->shop_context_text();
		if ( $context === '' ) {
			$context = 'No shop details could be auto-detected.';
		}

		$user = sprintf(
			"Shop context (auto-detected from the website — trust it):\n%s\n\n"
			. "Write the calendar for ONE language only: %s (%s).\n"
			. "Target market / countries: %s.\n"
			. "All titles and rationales must be written in %s.\n\n"
			. "Plan promotional events strictly between %s and %s (inclusive) — every date must "
			. "fall in this window.\n\n"
			. "Rules:\n"
			. "- Follow the strategy above. Every event must map to a real occasion (holiday or "
			. "well-known seasonal sale) named in its rationale.\n"
			. "- A stretch of the window with no real occasion in it gets NO event. Coming back with "
			. "three events, or with none at all, is a correct answer; inventing an occasion to fill "
			. "a gap is not. Never build a promotion on a theme of your own making, and never dress "
			. "an ordinary week as an event.\n"
			. "- The title NAMES the occasion, in the plainest words this shop's customers already "
			. "use for it — the words they would type into a search box. No invented name for a sale, "
			. "no slogan, no wordplay: the title alone must say what the promotion is.\n"
			. "- Give each occasion the length it deserves rather than the shortest one that works: a "
			. "dated holiday runs the days around it, a season runs two to three weeks, Black Friday "
			. "to Cyber Monday is one block. A real occasion cut to a few days sells less than it should.\n"
			. "- A promotion customers buy gifts for must END early enough for the parcel to arrive "
			. "before the date, counting this shop's own delivery time.\n"
			. "- \"timer\" is a countdown shown to the customer. Give it to the two or three "
			. "moments of the year a deadline really presses on — Black Friday, the last days "
			. "before Christmas delivery, the end of an official sale period. On an ordinary "
			. "sale it is noise, and a shop whose every banner counts down is a shop nobody "
			. "hurries for: leave it false.\n"
			. "- Events must not overlap in time (each has a clear start and end date).\n"
			. "- Pick a realistic discount percentage for the occasion and this shop's positioning.\n"
			. "- Order events chronologically by start_date. Hard maximum: %d events.\n\n"
			. "Respond with ONLY a JSON object of this exact shape, no markdown, no commentary:\n%s",
			$context,
			$native,
			strtoupper( $lang ),
			$country_line,
			$native,
			$start_date,
			$end_date,
			self::MAX_EVENTS,
			$schema
		);

		$raw    = $this->call_claude( $system, $user );
		$parsed = $this->parse_json( $raw );
		if ( ! is_array( $parsed ) || ! isset( $parsed['events'] ) || ! is_array( $parsed['events'] ) ) {
			throw new RuntimeException( __( 'The AI response could not be understood. Please try again.', 'dazont-ecom' ) );
		}
		// An empty list is a legitimate answer: no notable commercial moment in
		// this window. Let the caller report it plainly instead of erroring.
		if ( empty( $parsed['events'] ) ) {
			return [];
		}

		$clean = [];
		foreach ( $parsed['events'] as $ev ) {
			if ( ! is_array( $ev ) ) {
				continue;
			}
			$start = $this->clean_date( $ev['start_date'] ?? '' );
			$end   = $this->clean_date( $ev['end_date'] ?? '' );
			$title = sanitize_text_field( (string) ( $ev['title'] ?? '' ) );
			if ( $title === '' || $start === '' || $end === '' ) {
				continue;
			}
			// Defensive: drop anything the model placed outside the requested window.
			if ( $start < $start_date || $end > $end_date ) {
				continue;
			}
			$percent = min( 90, max( 1, (int) round( (float) ( $ev['percent'] ?? 0 ) ) ) );
			$clean[] = [
				'title'         => mb_substr( $title, 0, 80 ),
				'start_date'    => $start,
				'end_date'      => $end,
				'percent'       => $percent,
				'countries'     => $countries,
				// EVERY language of the shop. An empty list is what the
				// discounts module reads as "no language restriction", which
				// is the only sane default for a sale: a promotion that runs
				// in French and not in English is a bug the shop finds out
				// about from its customers.
				'languages'     => [],
				// Decided from the offer itself when the shop has set that
				// rule; the model's own answer otherwise.
				'timer'         => self::timer_for( $start, $end, $percent, ! empty( $ev['timer'] ) ),
				'rationale'     => mb_substr( sanitize_text_field( (string) ( $ev['rationale'] ?? '' ) ), 0, 240 ),
				// Filled by propose() once the batch comes back.
				'i18n'          => [],
			];
		}
		if ( empty( $clean ) ) {
			throw new RuntimeException( __( 'The AI returned no usable events in this date range. Try a wider range.', 'dazont-ecom' ) );
		}
		return $clean;
	}

	/**
	 * Reusable text completion for other modules (content generation, …). Shares
	 * the API key, budget guard, model list and usage tracking. Throws on error.
	 */
	public static function complete( string $system, string $user, string $model = '', int $max_tokens = 2000, int $timeout = 90 ): string {
		if ( DZE_Ai_Usage::over_budget() ) {
			throw new RuntimeException( DZE_Ai_Usage::budget_message() );
		}
		$key = self::api_key();
		if ( '' === $key ) {
			throw new RuntimeException( __( 'Add your Anthropic API key under Settings first.', 'dazont-ecom' ) );
		}
		$model    = '' !== $model ? $model : self::chosen_model();
		// The trace holds the exchange as the model reads it, so a wrong
		// answer can be traced to the words that produced it.
		$asked = "SYSTEM:\n" . $system . "\n\nUSER:\n" . $user;
		$t0    = microtime( true );
		$response = wp_remote_post( self::API_URL, [
			'timeout' => max( 30, $timeout ),
			'headers' => [
				'x-api-key'         => $key,
				'anthropic-version' => self::API_VERSION,
				'content-type'      => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model'      => $model,
				'max_tokens' => max( 64, $max_tokens ),
				'system'     => $system,
				'messages'   => [ [ 'role' => 'user', 'content' => $user ] ],
			] ),
		] );
		if ( is_wp_error( $response ) ) {
			DZE_Health::log( 'anthropic', 'POST /v1/messages', $response->get_error_message() );
			DZE_Ai_Usage::trace( 'anthropic', $model, $asked, 'ERROR — ' . $response->get_error_message(), microtime( true ) - $t0 );
			throw new RuntimeException( $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
			DZE_Health::log( 'anthropic', 'POST /v1/messages', 'HTTP ' . $code . ' — ' . $msg );
			DZE_Ai_Usage::trace( 'anthropic', $model, $asked, 'ERROR — HTTP ' . $code . ' — ' . $msg, microtime( true ) - $t0 );
			throw new RuntimeException( sprintf( __( 'Anthropic API error: %s', 'dazont-ecom' ), $msg ) );
		}
		DZE_Ai_Usage::record( 'anthropic', (int) ( $data['usage']['input_tokens'] ?? 0 ), (int) ( $data['usage']['output_tokens'] ?? 0 ), $model );
		$text = '';
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		DZE_Ai_Usage::trace( 'anthropic', $model, $asked, trim( $text ), microtime( true ) - $t0 );
		return trim( $text );
	}

	/**
	 * Same completion, with photographs attached to the message.
	 *
	 * Judging what a picture SHOWS cannot be done from the product's text: the
	 * model has to see it. Images travel as base64 blocks before the text, the
	 * order Anthropic recommends when the text refers to them.
	 *
	 * @param array<int,array{media:string,data:string}> $images Base64 payloads.
	 */
	public static function complete_with_images( string $system, string $user, array $images, string $model = '', int $max_tokens = 1500, int $timeout = 120 ): string {
		if ( DZE_Ai_Usage::over_budget() ) {
			throw new RuntimeException( DZE_Ai_Usage::budget_message() );
		}
		$key = self::api_key();
		if ( '' === $key ) {
			throw new RuntimeException( __( 'Add your Anthropic API key under Settings first.', 'dazont-ecom' ) );
		}
		$content = [];
		foreach ( $images as $i => $img ) {
			// Numbered out loud: the answer refers to "image 2", and a silent
			// ordering would be guesswork on both sides.
			$content[] = [ 'type' => 'text', 'text' => sprintf( 'Image %d:', $i + 1 ) ];
			$content[] = [
				'type'   => 'image',
				'source' => [
					'type'       => 'base64',
					'media_type' => (string) $img['media'],
					'data'       => (string) $img['data'],
				],
			];
		}
		$content[] = [ 'type' => 'text', 'text' => $user ];

		$model = '' !== $model ? $model : self::chosen_model();
		// Base64 photographs would be megabytes of noise in the trace: they
		// are counted instead, and the words travel whole.
		$asked = sprintf( "SYSTEM:\n%s\n\n[%d photograph(s) attached]\n\nUSER:\n%s", $system, count( $images ), $user );
		$t0    = microtime( true );
		$response = wp_remote_post( self::API_URL, [
			'timeout' => max( 30, $timeout ),
			'headers' => [
				'x-api-key'         => $key,
				'anthropic-version' => self::API_VERSION,
				'content-type'      => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model'      => $model,
				'max_tokens' => max( 64, $max_tokens ),
				'system'     => $system,
				'messages'   => [ [ 'role' => 'user', 'content' => $content ] ],
			] ),
		] );
		if ( is_wp_error( $response ) ) {
			DZE_Health::log( 'anthropic', 'POST /v1/messages', $response->get_error_message() );
			DZE_Ai_Usage::trace( 'anthropic', $model, $asked, 'ERROR — ' . $response->get_error_message(), microtime( true ) - $t0 );
			throw new RuntimeException( $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
			DZE_Health::log( 'anthropic', 'POST /v1/messages', 'HTTP ' . $code . ' — ' . $msg );
			DZE_Ai_Usage::trace( 'anthropic', $model, $asked, 'ERROR — HTTP ' . $code . ' — ' . $msg, microtime( true ) - $t0 );
			throw new RuntimeException( sprintf( __( 'Anthropic API error: %s', 'dazont-ecom' ), $msg ) );
		}
		DZE_Ai_Usage::record( 'anthropic', (int) ( $data['usage']['input_tokens'] ?? 0 ), (int) ( $data['usage']['output_tokens'] ?? 0 ), $model );
		$text = '';
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		DZE_Ai_Usage::trace( 'anthropic', $model, $asked, trim( $text ), microtime( true ) - $t0 );
		return trim( $text );
	}

	private function call_claude( string $system, string $user ): string {
		if ( DZE_Ai_Usage::over_budget() ) {
			throw new RuntimeException( DZE_Ai_Usage::budget_message() );
		}
		$dze_asked = "SYSTEM:\n" . $system . "\n\nUSER:\n" . $user;
		$dze_t0    = microtime( true );
		$response = wp_remote_post( self::API_URL, [
			'timeout' => 90,
			'headers' => [
				'x-api-key'         => $this->api_key(),
				'anthropic-version' => self::API_VERSION,
				'content-type'      => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model'      => self::chosen_model(),
				'max_tokens' => 8000,
				'system'     => $system,
				'messages'   => [ [ 'role' => 'user', 'content' => $user ] ],
			] ),
		] );
		if ( is_wp_error( $response ) ) {
			DZE_Health::log( 'anthropic', 'POST /v1/messages', $response->get_error_message() );
			DZE_Ai_Usage::trace( 'anthropic', self::chosen_model(), $dze_asked, 'ERROR — ' . $response->get_error_message(), microtime( true ) - $dze_t0 );
			throw new RuntimeException( $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
			DZE_Health::log( 'anthropic', 'POST /v1/messages', 'HTTP ' . $code . ' — ' . $msg );
			DZE_Ai_Usage::trace( 'anthropic', self::chosen_model(), $dze_asked, 'ERROR — HTTP ' . $code . ' — ' . $msg, microtime( true ) - $dze_t0 );
			throw new RuntimeException( sprintf( __( 'Anthropic API error: %s', 'dazont-ecom' ), $msg ) );
		}
		DZE_Ai_Usage::record( 'anthropic', (int) ( $data['usage']['input_tokens'] ?? 0 ), (int) ( $data['usage']['output_tokens'] ?? 0 ), self::chosen_model() );
		// Concatenate all returned text blocks.
		$text = '';
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		DZE_Ai_Usage::trace( 'anthropic', self::chosen_model(), $dze_asked, trim( $text ), microtime( true ) - $dze_t0 );
		return $text;
	}

	/** Tolerant JSON extraction: handles code fences and surrounding prose. */
	private function parse_json( string $raw ): ?array {
		$raw = trim( $raw );
		$raw = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $raw );
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		// Fall back to the first {...} block.
		if ( preg_match( '/\{.*\}/s', $raw, $m ) ) {
			$decoded = json_decode( $m[0], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	private function clean_date( $value ): string {
		$value = trim( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	private function list_codes( $value, int $len ): array {
		$out = [];
		foreach ( (array) $value as $c ) {
			$c = preg_replace( '/[^A-Za-z]/', '', (string) $c );
			if ( $c !== '' ) {
				$out[] = $len === 2 ? strtoupper( substr( $c, 0, 2 ) ) : strtolower( substr( $c, 0, 2 ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	// =========================================================================
	// Suggestions store
	// =========================================================================

	public static function get_suggestions(): array {
		$s = get_option( self::OPT_SUGGESTIONS, [] );
		return is_array( $s ) ? $s : [];
	}

	/**
	 * How many suggested events are waiting for a yes or a no.
	 *
	 * Read on every admin page to draw the menu bubble, so it must cost almost
	 * nothing: the suggestions are a single option row, and the count is just
	 * its size. The row does not autoload, so this is one query on the admin
	 * pages that draw the menu — and none at all on the front.
	 */
	public static function pending_count(): int {
		return count( self::get_suggestions() );
	}

	private static function save_suggestions( array $s ): void {
		update_option( self::OPT_SUGGESTIONS, $s, false );
	}

	// =========================================================================
	// AJAX: accept / refuse
	// =========================================================================

	public function ajax_accept(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$id          = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$suggestions = self::get_suggestions();
		if ( $id === '' || ! isset( $suggestions[ $id ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown suggestion.', 'dazont-ecom' ) ] );
		}

		// The row may carry user edits (accept/modify): trust posted fields, fall
		// back to the stored suggestion.
		$src = $suggestions[ $id ];
		$ev  = [
			'title'         => sanitize_text_field( wp_unslash( $_POST['title'] ?? $src['title'] ) ),
			'percent'       => min( 90, max( 1, (int) ( $_POST['percent'] ?? $src['percent'] ) ) ),
			'start_date'    => $this->clean_date( wp_unslash( $_POST['start_date'] ?? $src['start_date'] ) ),
			'end_date'      => $this->clean_date( wp_unslash( $_POST['end_date'] ?? $src['end_date'] ) ),
			'languages'     => $this->list_codes( explode( ',', (string) ( $_POST['languages'] ?? implode( ',', $src['languages'] ) ) ), 5 ),
			'timer'         => isset( $_POST['timer'] ) ? ! empty( $_POST['timer'] ) : ! empty( $src['timer'] ),
		];
		if ( $ev['start_date'] === '' || $ev['end_date'] === '' ) {
			wp_send_json_error( [ 'message' => __( 'This event needs a valid start and end date.', 'dazont-ecom' ) ] );
		}

		$ev['i18n'] = $this->posted_i18n();
		$rule_id    = $this->create_sale_rule( $ev );

		unset( $suggestions[ $id ] );
		self::save_suggestions( $suggestions );

		wp_send_json_success( [
			'message'  => __( 'Added to your calendar and running — switch it off below if you need to.', 'dazont-ecom' ),
			'rule_id'  => $rule_id,
		] );
	}

	/**
	 * Unified create/accept endpoint used by the row "Accept", the "Accept &
	 * modify" popup and the "New event" popup. Creates a scheduled-sale event
	 * from the posted fields; if a suggestion id is given it is consumed; if
	 * push_gmc is set the event is also pushed to Google Merchant Center (to the
	 * chosen targets, or all configured ones).
	 */
	public function ajax_save_event(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}

		$suggestions = self::get_suggestions();
		$sug_id      = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$src         = ( $sug_id !== '' && isset( $suggestions[ $sug_id ] ) ) ? $suggestions[ $sug_id ] : [];

		$ev = [
			'title'         => sanitize_text_field( wp_unslash( $_POST['title'] ?? ( $src['title'] ?? '' ) ) ),
			'percent'       => min( 90, max( 1, (int) ( $_POST['percent'] ?? ( $src['percent'] ?? 10 ) ) ) ),
			'start_date'    => $this->clean_date( wp_unslash( $_POST['start_date'] ?? ( $src['start_date'] ?? '' ) ) ),
			'end_date'      => $this->clean_date( wp_unslash( $_POST['end_date'] ?? ( $src['end_date'] ?? '' ) ) ),
			'languages'     => $this->list_codes( explode( ',', (string) ( $_POST['languages'] ?? implode( ',', (array) ( $src['languages'] ?? [] ) ) ) ), 5 ),
			'timer'         => isset( $_POST['timer'] ) ? ! empty( $_POST['timer'] ) : ! empty( $src['timer'] ),
		];
		if ( $ev['title'] === '' ) {
			wp_send_json_error( [ 'message' => __( 'Give the event a title.', 'dazont-ecom' ) ] );
		}
		if ( $ev['start_date'] === '' || $ev['end_date'] === '' ) {
			wp_send_json_error( [ 'message' => __( 'This event needs a valid start and end date.', 'dazont-ecom' ) ] );
		}

		$ev['i18n'] = $this->posted_i18n();
		$rule_id    = $this->create_sale_rule( $ev );

		if ( $sug_id !== '' && isset( $suggestions[ $sug_id ] ) ) {
			unset( $suggestions[ $sug_id ] );
			self::save_suggestions( $suggestions );
		}

		$message = __( 'Event added to your calendar (disabled — review and enable it).', 'dazont-ecom' );

		if ( ! empty( $_POST['push_gmc'] ) && class_exists( 'DZE_Gmc' ) && DZE_Gmc::instance()->is_configured() ) {
			// Enable it so it can go live in Google, then push.
			$rules = DZE_Discounts::get_rules();
			if ( isset( $rules[ $rule_id ] ) ) {
				$rules[ $rule_id ]['enabled'] = true;
				update_option( DZE_Discounts::OPTION, $rules, false );
				DZE_Discounts::instance()->queue_sale_sync();
			}
			$statuses = DZE_Gmc::instance()->sync_rule( $rule_id );
			$errors   = array_filter( $statuses, static fn( $s ) => ( $s['status'] ?? '' ) === 'error' );
			$message  = empty( $errors )
				? __( 'Event created, enabled and pushed to Google Merchant Center.', 'dazont-ecom' )
				: __( 'Event created and enabled, but some Merchant Center targets errored — check the GMC column.', 'dazont-ecom' );
		}

		wp_send_json_success( [ 'message' => $message, 'rule_id' => $rule_id ] );
	}

	/**
	 * The translations the popup carried, if any.
	 *
	 * Typed or asked for on screen, they arrive with the event: the owner saw
	 * them before saving, so they are his, not something written behind him.
	 *
	 * @return array<string,string>
	 */
	private function posted_i18n(): array {
		$raw = isset( $_POST['i18n'] ) ? wp_unslash( $_POST['i18n'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller checked it.
		$in  = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $in ) ) {
			$in = [];
		}
		$langs = class_exists( 'DZE_Discounts' ) ? DZE_Discounts::promo_langs() : [];
		$out   = [];
		foreach ( $langs as $code => $name ) {
			$text = sanitize_text_field( (string) ( $in[ $code ] ?? '' ) );
			if ( '' !== trim( $text ) ) {
				$out[ $code ] = mb_substr( $text, 0, 120 );
			}
		}
		if ( $out || ! self::promo_i18n_on() ) {
			return $out;
		}
		// Accepted straight from the list, with no popup opened: the lines are
		// written now rather than later. One short call, and the event is
		// ready to run in every language the moment it is switched on.
		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller checked it.
		try {
			return class_exists( 'DZE_Discounts' ) ? DZE_Discounts::translate_line( $title, $langs ) : [];
		} catch ( \Throwable $e ) {
			return []; // the event is worth more than its translations.
		}
	}

	/** Translates a promotion title on demand, for the event popup. */
	public function ajax_translate(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		if ( '' === trim( $title ) ) {
			wp_send_json_error( [ 'message' => __( 'Write the title first.', 'dazont-ecom' ) ] );
		}
		$langs = class_exists( 'DZE_Discounts' ) ? DZE_Discounts::promo_langs() : [];
		if ( ! $langs ) {
			wp_send_json_error( [ 'message' => __( 'This shop sells in one language.', 'dazont-ecom' ) ] );
		}
		try {
			$lines = DZE_Discounts::translate_line( $title, $langs );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		if ( ! $lines ) {
			wp_send_json_error( [ 'message' => __( 'Nothing came back — try again.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'i18n' => $lines ] );
	}

	public function ajax_refuse(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$id          = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$suggestions = self::get_suggestions();
		unset( $suggestions[ $id ] );
		self::save_suggestions( $suggestions );
		wp_send_json_success();
	}

	/**
	 * Creates a scheduled-sale rule in the Discounts store (shown on the
	 * Marketing Events page) from an accepted event. Saved DISABLED: only one
	 * event can be active at a time, so the user reviews and enables it there.
	 */
	private function create_sale_rule( array $ev ): string {
		if ( ! class_exists( 'DZE_Discounts' ) ) {
			throw new RuntimeException( __( 'The Marketing Events module is unavailable.', 'dazont-ecom' ) );
		}
		$rules = DZE_Discounts::get_rules();
		$id    = 'ai' . uniqid();

		$rules[ $id ] = [
			'id'            => $id,
			'created_at'    => time(),
			'title'         => $ev['title'],
			'type'          => 'sale',
			// An accepted event is a real one: it runs. The switch on the list
			// is there to stop it, not to start it — an event created in a
			// disabled state is an event the shop forgets to turn on.
			'enabled'       => true,
			'percent'       => (float) $ev['percent'],
			'scope'         => 'all',
			'category_ids'  => [],
			'product_ids'   => [],
			'start'         => $ev['start_date'],
			'end'           => $ev['end_date'],
			'threshold'     => 0.0,
			'banner_enabled'   => true,
			'banner_text'      => $ev['title'],
			'banner_location'  => DZE_Discounts::default_location(),
			'product_position' => 'before_product',
			'banner_hooks'     => '',
			// A countdown belongs to the few moments of the year that carry a
			// deadline people act on, not to every sale.
			'banner_timer'     => ! empty( $ev['timer'] ),
			'banner_text_i18n' => (array) ( $ev['i18n'] ?? [] ),
			'languages'        => $ev['languages'],
			'hero_swap_enabled'=> false,
			'hero_source_id'   => 0,
			'hero_event_id'    => 0,
			// Marketing-AI metadata (ignored by Discounts, used by the calendar).
			'source'         => 'ai',
		];
		// The one guard that matters is applied here too: writing a rule
		// straight into the option must not put two promotions on the shop at
		// the same time.
		if ( class_exists( 'DZE_Discounts' ) ) {
			$clash = DZE_Discounts::instance()->clash_for( $rules[ $id ], $rules );
			if ( '' !== $clash ) {
				$rules[ $id ]['enabled'] = false;
				set_transient( 'dze_discount_notice', sprintf(
					/* translators: 1: the new event, 2: the promotion already running */
					__( '"%1$s" was added but left off: its dates overlap "%2$s", and only one promotion runs at a time.', 'dazont-ecom' ),
					(string) $ev['title'],
					$clash
				), 60 );
			}
		}
		update_option( DZE_Discounts::OPTION, $rules, false );
		// An accepted event is a running one, and its channels follow at once
		// rather than on the next hourly look: the emails start being planned
		// in the background exactly as a saved event's would.
		if ( class_exists( 'DZE_Modules' ) && DZE_Modules::enabled( 'klaviyo' ) && class_exists( 'DZE_Klaviyo_Auto' ) ) {
			DZE_Klaviyo_Auto::follow( $id );
		}
		return $id;
	}

	// =========================================================================
	// Marketing Events panel (rendered at the top of the Marketing Events page)
	// =========================================================================

	public function enqueue_assets( string $hook ): void {
		// The settings screen carries the banner's two colour fields, and they
		// are WordPress's own picker: it takes a hex typed by hand, which the
		// browser's native control does not. Nothing else of ours is needed
		// there, so nothing else is loaded.
		if ( strpos( $hook, self::MENU_SLUG ) !== false ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );
		}
		if ( ! class_exists( 'DZE_Discounts' ) || strpos( $hook, DZE_Discounts::MENU_SLUG_EVENTS ) === false ) {
			return;
		}
		wp_enqueue_script( 'dze-marketing-ai', DZE_URL . 'admin/js/marketing-ai.js', [ 'jquery' ], DZE_VERSION, true );
		wp_localize_script( 'dze-marketing-ai', 'dzeMai', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'generating' => __( 'Generating…', 'dazont-ecom' ),
				'accepting'  => __( 'Adding…', 'dazont-ecom' ),
				'error'      => __( 'Error', 'dazont-ecom' ),
				'confirmRef'     => __( 'Discard this suggestion?', 'dazont-ecom' ),
				'confirmRefBulk' => __( 'Discard the selected suggestions?', 'dazont-ecom' ),
				'needDates'      => __( 'Pick a start and end date first.', 'dazont-ecom' ),
				'saving'         => __( 'Saving…', 'dazont-ecom' ),
				'modifyTitle'    => __( 'Accept & modify event', 'dazont-ecom' ),
				'newTitle'       => __( 'New marketing event', 'dazont-ecom' ),
				'titleFirst'     => __( 'Write the title first.', 'dazont-ecom' ),
				'translating'    => __( 'Writing…', 'dazont-ecom' ),
				'translated'     => __( 'Written — check them, then save.', 'dazont-ecom' ),
			],
		] );
	}

	/**
	 * "About this shop" — the description every module of the plugin reads.
	 *
	 * Its own form with its own Save, at the top of the General tab: it belongs
	 * to no API key and to no model, and it is the first thing to write when
	 * the plugin is installed.
	 */
	public function render_shop_profile(): void {
		$profile = (string) ( self::get_settings()['shop_profile'] ?? '' );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_mai_options' ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[section]" value="shop" />
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'A few lines saying what this shop is: what it sells, to whom, what makes it what it is. Sent with EVERY generation of the plugin — product texts, category pages, reviews, marketing calendar, sourcing report — so it is worth writing properly once.', 'dazont-ecom' ); ?>
			</p>
			<textarea id="dze-mai-profile" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[shop_profile]" rows="6" class="large-text" style="max-width:820px;" placeholder="<?php esc_attr_e( 'e.g. Online shop selling tactical and military gear (Kula Tactical). Patches, headwear, camo clothing, gloves, chest rigs and outdoor equipment, with a wide catalogue. Customers: airsoft players, collectors, outdoor and tactical-style buyers. Sharp, factual, no-nonsense tone.', 'dazont-ecom' ); ?>"><?php echo esc_textarea( $profile ); ?></textarea>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'dazont-ecom' ); ?></button>
				<button type="button" class="button" id="dze-mai-profile-draft"><?php esc_html_e( 'Draft it from my shop', 'dazont-ecom' ); ?></button>
				<span class="description" id="dze-mai-profile-state"><?php esc_html_e( 'The draft reads your home page and the shape of your catalogue — correct it, then save.', 'dazont-ecom' ); ?></span>
			</p>
		</form>
		<script>
		(function () {
			var btn = document.getElementById('dze-mai-profile-draft');
			var ta  = document.getElementById('dze-mai-profile');
			var st  = document.getElementById('dze-mai-profile-state');
			if (!btn || !ta) { return; }
			var busy = <?php echo wp_json_encode( __( 'Reading the shop…', 'dazont-ecom' ) ); ?>;
			var done = <?php echo wp_json_encode( __( 'Read it, correct it, then press Save.', 'dazont-ecom' ) ); ?>;
			var over = <?php echo wp_json_encode( __( 'Replace what is written with a fresh draft?', 'dazont-ecom' ) ); ?>;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			btn.addEventListener('click', function () {
				if (ta.value.trim() && !window.confirm(over)) { return; }
				btn.disabled = true;
				st.textContent = busy;
				var body = new URLSearchParams({ action: 'dze_mai_profile', nonce: nonce });
				window.fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (r) { return r.json(); })
					.then(function (r) {
						btn.disabled = false;
						if (r && r.success) { ta.value = r.data.text || ''; st.textContent = done; }
						else { st.textContent = (r && r.data && r.data.message) || 'error'; }
					})
					.catch(function () { btn.disabled = false; st.textContent = 'error'; });
			});
		}());
		</script>
		<?php
	}

	/** Generate button + date range + suggestions review table + calendar view. */
	public function render_calendar_panel(): void {
		$has_key     = $this->api_key() !== '';
		$suggestions = self::get_suggestions();
		$languages   = self::active_languages();
		$primary     = self::primary_language();
		$gmc         = class_exists( 'DZE_Gmc' ) ? DZE_Gmc::instance() : null;
		$gmc_on      = $gmc && $gmc->is_configured();
		require DZE_DIR . 'admin/views/marketing-ai-panel.php';

		echo '<h2 class="title" style="margin-top:24px;">' . esc_html__( 'Calendar', 'dazont-ecom' ) . '</h2>';
		echo $this->calendar_grid_html( 4 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with per-value escaping internally.
	}

	// =========================================================================
	// Calendar grid (shared by the Marketing Events page and the Dashboard widget)
	// =========================================================================

	/** Scheduled sale rules flattened into calendar events. */
	private function sale_events(): array {
		$events = [];
		if ( ! class_exists( 'DZE_Discounts' ) ) {
			return $events;
		}
		foreach ( DZE_Discounts::get_rules() as $rule ) {
			if ( ( $rule['type'] ?? '' ) !== 'sale' ) {
				continue;
			}
			$s = (string) ( $rule['start'] ?? '' );
			$e = (string) ( $rule['end'] ?? '' );
			if ( $s === '' || $e === '' ) {
				continue;
			}
			$events[] = [
				'start'   => $s,
				'end'     => $e,
				'title'   => (string) ( $rule['title'] ?? '' ),
				'percent' => (int) round( (float) ( $rule['percent'] ?? 0 ) ),
				'enabled' => ! empty( $rule['enabled'] ),
			];
		}
		return $events;
	}

	/**
	 * Renders a single-month calendar (the current month) with Prev/Next buttons
	 * to page through months client-side. Scheduled events are drawn as coloured
	 * chips on the days they run. Self-contained: markup + data + a scoped inline
	 * script, so it works in the dashboard widget, the Marketing Events page and
	 * the front-end shortcode alike. The `$months` argument is ignored (kept for
	 * backward compatibility with earlier callers).
	 */
	public function calendar_grid_html( int $months = 1 ): string {
		$events = $this->sale_events();

		$palette = [ '#2563eb', '#0a7040', '#b26a00', '#7c3aed', '#b32d2e', '#0e7490', '#be185d', '#4d7c0f' ];
		usort( $events, static fn( $a, $b ) => strcmp( $a['start'], $b['start'] ) );
		$data_events = [];
		foreach ( $events as $i => $ev ) {
			$data_events[] = [
				'start'   => (string) $ev['start'],
				'end'     => (string) $ev['end'],
				'title'   => (string) $ev['title'],
				'percent' => (int) $ev['percent'],
				'enabled' => ! empty( $ev['enabled'] ),
				'color'   => $palette[ $i % count( $palette ) ],
			];
		}

		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );
		$sow = (int) get_option( 'start_of_week', 1 );

		global $wp_locale;
		$weekdays = [];
		$months_n = [];
		for ( $d = 0; $d < 7; $d++ ) {
			$idx        = ( $sow + $d ) % 7;
			$weekdays[] = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $idx ) ) : (string) $idx;
		}
		for ( $mo = 1; $mo <= 12; $mo++ ) {
			$months_n[] = $wp_locale ? $wp_locale->get_month( $mo ) : (string) $mo;
		}

		$uid  = 'dze-cal-' . wp_rand( 1000, 9999 ) . '-' . substr( (string) time(), -4 );
		$data = [
			'events'   => $data_events,
			'sow'      => $sow,
			'weekdays' => $weekdays,
			'months'   => $months_n,
			'today'    => $now->format( 'Y-m-d' ),
			'year'     => (int) $now->format( 'Y' ),
			'month'    => (int) $now->format( 'n' ),
			'i18n'     => [
				'off'   => __( 'disabled', 'dazont-ecom' ),
				'empty' => __( 'No scheduled events yet — accept an AI suggestion or add an event.', 'dazont-ecom' ),
				'today' => __( 'Today', 'dazont-ecom' ),
			],
		];

		ob_start();
		?>
		<div class="dze-cal" id="<?php echo esc_attr( $uid ); ?>">
			<div class="dze-cal__head">
				<button type="button" class="button dze-cal__nav" data-dir="-1" aria-label="Previous month">‹</button>
				<strong class="dze-cal__label"></strong>
				<button type="button" class="button dze-cal__nav" data-dir="1" aria-label="Next month">›</button>
				<button type="button" class="button dze-cal__today"><?php esc_html_e( 'Today', 'dazont-ecom' ); ?></button>
			</div>
			<div class="dze-cal__body"></div>
		</div>
		<style>
			.dze-cal__head{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
			.dze-cal__label{font-size:15px;min-width:150px;text-align:center;}
			.dze-cal__nav{font-weight:700;line-height:1;}
			.dze-cal__today{margin-left:auto;}
			.dze-cal__grid{width:100%;border-collapse:collapse;table-layout:fixed;}
			.dze-cal__grid th{font-size:11px;color:#888;font-weight:600;padding:4px 2px;text-align:center;}
			.dze-cal__grid td{border:1px solid #eee;height:74px;vertical-align:top;padding:3px;overflow:hidden;}
			.dze-cal__day.is-today{background:#fff8e1;}
			.dze-cal__empty{background:#fafafa;}
			.dze-cal__num{color:#777;font-size:11px;}
			.dze-cal__chip{display:block;color:#fff;border-radius:3px;padding:1px 5px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:11px;line-height:1.6;}
			.dze-cal__chip.is-off{opacity:.5;}
			.dze-cal__none{color:#777;}
		</style>
		<script>
		(function () {
			var D = <?php echo wp_json_encode( $data ); ?>;
			var root = document.getElementById(<?php echo wp_json_encode( $uid ); ?>);
			if ( ! root ) { return; }
			var body = root.querySelector('.dze-cal__body');
			var label = root.querySelector('.dze-cal__label');
			var y = D.year, m = D.month; // m is 1-12

			function pad(n){ return (n < 10 ? '0' : '') + n; }

			function render() {
				label.textContent = D.months[m - 1] + ' ' + y;
				if ( ! D.events.length ) {
					body.innerHTML = '<p class="dze-cal__none">' + D.i18n.empty + '</p>';
					return;
				}
				var first = new Date(y, m - 1, 1);
				var daysInMonth = new Date(y, m, 0).getDate();
				var firstDow = first.getDay(); // 0=Sun
				var lead = (firstDow - D.sow + 7) % 7;

				var html = '<table class="dze-cal__grid"><thead><tr>';
				for (var w = 0; w < 7; w++) { html += '<th>' + D.weekdays[w] + '</th>'; }
				html += '</tr></thead><tbody><tr>';

				var col = 0, b;
				for (b = 0; b < lead; b++) { html += '<td class="dze-cal__empty"></td>'; col++; }
				for (var day = 1; day <= daysInMonth; day++) {
					if (col === 7) { html += '</tr><tr>'; col = 0; }
					var ymd = y + '-' + pad(m) + '-' + pad(day);
					html += '<td class="dze-cal__day' + (ymd === D.today ? ' is-today' : '') + '">';
					html += '<span class="dze-cal__num">' + day + '</span>';
					for (var i = 0; i < D.events.length; i++) {
						var ev = D.events[i];
						if (ev.start <= ymd && ymd <= ev.end) {
							var tip = ev.title + ' (-' + ev.percent + '%)' + (ev.enabled ? '' : ' — ' + D.i18n.off);
							html += '<span class="dze-cal__chip' + (ev.enabled ? '' : ' is-off') + '" style="background:' + ev.color + '" title="' + tip.replace(/"/g, '&quot;') + '">' + ev.title.replace(/</g, '&lt;') + '</span>';
						}
					}
					html += '</td>'; col++;
				}
				while (col < 7) { html += '<td class="dze-cal__empty"></td>'; col++; }
				html += '</tr></tbody></table>';
				body.innerHTML = html;
			}

			root.querySelectorAll('.dze-cal__nav').forEach(function (btn) {
				btn.addEventListener('click', function () {
					m += parseInt(btn.getAttribute('data-dir'), 10);
					if (m < 1) { m = 12; y--; }
					if (m > 12) { m = 1; y++; }
					render();
				});
			});
			root.querySelector('.dze-cal__today').addEventListener('click', function () {
				y = D.year; m = D.month; render();
			});
			render();
		}());
		</script>
		<?php
		return (string) ob_get_clean();
	}

	// =========================================================================
	// Front-end calendar shortcode
	// =========================================================================

	/**
	 * [dze_marketing_calendar] — renders the scheduled sales as a marketing
	 * calendar. Attributes:
	 *   view  "calendar" (default) — the single-month grid with Prev/Next;
	 *         "list" — the older card list of upcoming events.
	 *   limit (list view only) max cards, default 12.
	 *   past  (list view only) include finished events, "0"/"1", default 0.
	 */
	public function render_calendar_shortcode( $atts ): string {
		if ( ! class_exists( 'DZE_Discounts' ) ) {
			return '';
		}
		$atts = shortcode_atts( [ 'view' => 'calendar', 'limit' => 12, 'past' => 0 ], $atts, self::SHORTCODE );

		// Default: the same single-month calendar used in the admin, month switcher included.
		if ( 'list' !== $atts['view'] ) {
			return '<div class="dze-mktcal-wrap" style="max-width:920px;">' . $this->calendar_grid_html( 1 ) . '</div>';
		}

		$limit    = max( 1, (int) $atts['limit'] );
		$show_past = ! empty( $atts['past'] );
		$today    = current_time( 'Y-m-d' );

		$events = [];
		foreach ( DZE_Discounts::get_rules() as $rule ) {
			if ( ( $rule['type'] ?? '' ) !== 'sale' ) {
				continue;
			}
			$start = (string) ( $rule['start'] ?? '' );
			$end   = (string) ( $rule['end'] ?? '' );
			if ( $start === '' || $end === '' ) {
				continue;
			}
			if ( ! $show_past && $end < $today ) {
				continue;
			}
			$events[] = $rule;
		}
		if ( empty( $events ) ) {
			return '';
		}
		usort( $events, static fn( $a, $b ) => strcmp( (string) $a['start'], (string) $b['start'] ) );
		$events = array_slice( $events, 0, $limit );

		$fmt = static function ( string $ymd ): string {
			$ts = strtotime( $ymd . ' 00:00:00' );
			return $ts ? wp_date( get_option( 'date_format' ), $ts ) : $ymd;
		};

		ob_start();
		echo '<div class="dze-mktcal">';
		foreach ( $events as $rule ) {
			$live    = ( $rule['start'] <= $today && $today <= $rule['end'] && ! empty( $rule['enabled'] ) );
			$percent = (int) round( (float) ( $rule['percent'] ?? 0 ) );
			printf(
				'<div class="dze-mktcal__item%s">'
					. '<div class="dze-mktcal__dates">%s → %s</div>'
					. '<div class="dze-mktcal__title">%s</div>'
					. '<div class="dze-mktcal__meta"><span class="dze-mktcal__pct">-%d%%</span>%s</div>'
					. '</div>',
				$live ? ' is-live' : '',
				esc_html( $fmt( (string) $rule['start'] ) ),
				esc_html( $fmt( (string) $rule['end'] ) ),
				esc_html( (string) ( $rule['title'] ?? '' ) ),
				$percent,
				$live ? ' <span class="dze-mktcal__live">' . esc_html__( 'Live now', 'dazont-ecom' ) . '</span>' : ''
			);
		}
		echo '</div>';
		echo '<style>'
			. '.dze-mktcal{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));}'
			. '.dze-mktcal__item{border:1px solid #e2e2e2;border-radius:10px;padding:14px 16px;background:#fff;}'
			. '.dze-mktcal__item.is-live{border-color:#0a7040;box-shadow:0 0 0 2px rgba(10,112,64,.12);}'
			. '.dze-mktcal__dates{font-size:12px;color:#666;}'
			. '.dze-mktcal__title{font-weight:600;margin:4px 0 8px;}'
			. '.dze-mktcal__meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}'
			. '.dze-mktcal__pct{background:#111;color:#fff;border-radius:6px;padding:2px 8px;font-weight:700;font-size:13px;}'
			. '.dze-mktcal__live{color:#0a7040;font-weight:600;font-size:12px;}'
			. '</style>';
		return (string) ob_get_clean();
	}
}
