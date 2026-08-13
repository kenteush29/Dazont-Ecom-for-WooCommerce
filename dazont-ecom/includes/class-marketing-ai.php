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
		"You are an expert e-commerce marketing strategist. Build a well-paced promotional calendar "
		. "for the shop, anchored to genuine, widely-recognised commercial moments for the target market:\n"
		. "- Major public and retail holidays, and nationally-observed sale seasons (for example: les soldes "
		. "in France, Black Friday, Cyber Monday, Christmas, Boxing Day, Valentine's Day, Mother's/Father's Day, Halloween).\n"
		. "- Established seasonal sales that retailers run every year: Spring Sale, Summer Sale, Back-to-School, "
		. "Autumn/Fall Sale, End-of-Season Clearance, Winter Sale.\n\n"
		. "Pace the calendar so there are no long empty gaps: when two holidays are far apart, a well-known seasonal "
		. "sale in between is welcome and encouraged. Never invent vague, meaningless promotions (a 'random weekend "
		. "flash deal') just to reach a number — every event must map to a real occasion, which you name in its rationale. "
		. "Favour realism and good spacing over sheer quantity.";

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
			'use_catalog'   => true, // feed shop categories/best-sellers to the AI.
			'tone'          => '',   // brand voice, reused by future content tools.
			'events_prompt' => '',   // custom calendar guidance; empty = DEFAULT_EVENTS_PROMPT.
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
		if ( $events_prompt === self::DEFAULT_EVENTS_PROMPT ) {
			$events_prompt = '';
		}

		if ( 'general' === $section ) {
			// Key, model and budget live on the General tab.
			return array_merge( $existing, [
				'api_key'      => sanitize_text_field( $key ),
				'model'        => $model,
				'budget_month' => max( 0, (float) str_replace( ',', '.', (string) ( $in['budget_month'] ?? 0 ) ) ),
			] );
		}
		if ( 'sourcing' === $section ) {
			// Prompt overrides: a text identical to the shipped default is stored
			// empty, so future default improvements reach untouched installs.
			$match_rules = trim( sanitize_textarea_field( (string) ( $in['match_rules'] ?? '' ) ) );
			if ( class_exists( 'DZE_Keywords' ) && trim( DZE_Keywords::DEFAULT_MATCH_RULES ) === $match_rules ) {
				$match_rules = '';
			}
			$report_guidance = trim( sanitize_textarea_field( (string) ( $in['report_guidance'] ?? '' ) ) );
			if ( class_exists( 'DZE_Explorer' ) && trim( DZE_Explorer::DEFAULT_REPORT_GUIDANCE ) === $report_guidance ) {
				$report_guidance = '';
			}
			return array_merge( $existing, [
				'match_model'     => sanitize_text_field( (string) ( $in['match_model'] ?? '' ) ),
				'insights_model'  => sanitize_text_field( (string) ( $in['insights_model'] ?? '' ) ),
				'sourcing_minvol' => max( 0, (int) ( $in['sourcing_minvol'] ?? 10 ) ),
				'match_rules'     => $match_rules,
				'report_guidance' => $report_guidance,
			] );
		}
		if ( 'events' === $section ) {
			// The Marketing events tab carries everything except key + model.
			return array_merge( $existing, [
				'use_catalog'   => ! empty( $in['use_catalog'] ),
				'tone'          => sanitize_textarea_field( (string) ( $in['tone'] ?? '' ) ),
				'events_prompt' => sanitize_textarea_field( $events_prompt ),
				'country_pools' => $pools,
			] );
		}

		return [
			'api_key'       => sanitize_text_field( $key ),
			'model'         => $model,
			'use_catalog'   => ! empty( $in['use_catalog'] ),
			'tone'          => sanitize_textarea_field( (string) ( $in['tone'] ?? '' ) ),
			'events_prompt' => sanitize_textarea_field( $events_prompt ),
			'country_pools' => $pools,
		];
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
	public static function events_prompt(): string {
		$p = trim( (string) ( self::get_settings()['events_prompt'] ?? '' ) );
		return $p !== '' ? $p : self::DEFAULT_EVENTS_PROMPT;
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
		if ( $mod_on( 'pod' ) ) {
			$tabs['pod'] = __( 'POD', 'dazont-ecom' );
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
		$tabs['events']  = __( 'Marketing events', 'dazont-ecom' );
		$tabs['modules'] = __( 'Modules', 'dazont-ecom' );
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab navigation only.
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}

		echo '<div class="wrap dze-wrap">';
		echo '<h1>' . esc_html__( 'Settings', 'dazont-ecom' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px;">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => $key ], admin_url( 'admin.php' ) ) ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( 'general' === $tab ) {
			echo '<p class="description" style="max-width:820px;">' . esc_html__( 'API keys, models and monthly budget. The Anthropic key powers the text generation (content, marketing calendar, sourcing); the fal.ai key powers the image generation. Each key is only ever sent to its own provider.', 'dazont-ecom' ) . '</p>';
			echo '<h2>' . esc_html__( 'Anthropic (Claude)', 'dazont-ecom' ) . '</h2>';
			$this->render_settings_section( 'general' );
			// The fal key lives in Content settings but also powers POD.
			if ( class_exists( 'DZE_Content' ) && ( $mod_on( 'content' ) || $mod_on( 'pod' ) ) ) {
				echo '<hr style="margin:28px 0;" />';
				echo '<h2>' . esc_html__( 'fal.ai (image generation)', 'dazont-ecom' ) . '</h2>';
				DZE_Content::instance()->render_key_field();
			}
			// Price endings: not an API setting, but a shop-wide one shared by
			// Discounts and Product Content, and this is the only general tab.
			if ( class_exists( 'DZE_Price' ) && ( DZE_Modules::enabled( 'discounts' ) || DZE_Modules::enabled( 'content' ) ) ) {
				echo '<hr style="margin:28px 0;" />';
				echo '<h2>' . esc_html__( 'Price endings', 'dazont-ecom' ) . '</h2>';
				self::render_price_rounding();
			}
			echo '<hr style="margin:28px 0;" />';
			echo '<h2>' . esc_html__( 'API usage and spend', 'dazont-ecom' ) . '</h2>';
			DZE_Ai_Usage::render_graph();
		} elseif ( 'sourcing' === $tab ) {
			$this->render_sourcing_settings();
		} elseif ( 'content' === $tab ) {
			if ( class_exists( 'DZE_Content' ) && $mod_on( 'content' ) ) {
				DZE_Content::instance()->render_settings_section();
			}
		} elseif ( 'pod' === $tab ) {
			if ( class_exists( 'DZE_Pod' ) && $mod_on( 'pod' ) ) {
				DZE_Pod::instance()->render_settings();
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
		} elseif ( 'modules' === $tab ) {
			if ( class_exists( 'DZE_Modules' ) ) {
				DZE_Modules::instance()->render_tab();
			}
		} else { // events.
			$this->render_settings_section( 'events' );
		}
		echo '</div>';
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
		<p class="description" style="max-width:820px;">
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
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<script>
		jQuery( function ( $ ) {
			// Refill a prompt textarea with its SHIPPED default (saved on submit;
			// storing the exact default text is stored as "use the default").
			var dzeDefaults = {
				'dze-mai-match-rules': <?php echo wp_json_encode( class_exists( 'DZE_Keywords' ) ? DZE_Keywords::DEFAULT_MATCH_RULES : '' ); ?>,
				'dze-mai-report-guidance': <?php echo wp_json_encode( class_exists( 'DZE_Explorer' ) ? DZE_Explorer::DEFAULT_REPORT_GUIDANCE : '' ); ?>
			};
			$( '.dze-mai-restore' ).on( 'click', function () {
				var id = $( this ).data( 'target' );
				if ( dzeDefaults[ id ] ) { $( '#' + id ).val( dzeDefaults[ id ] ); }
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
			<p class="description" style="max-width:820px;">
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
			<p class="description" style="max-width:820px;">
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
		// Manual refresh of the auto-detected shop context.
		if ( isset( $_GET['dze_mai_refresh'] ) ) {
			delete_transient( self::CTX_TRANSIENT );
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
	private function shop_context_text(): string {
		$c     = $this->shop_context();
		$lines = [];
		if ( $c['name'] !== '' ) {
			$lines[] = sprintf( 'Store name: %s', $c['name'] );
		}
		if ( $c['tagline'] !== '' ) {
			$lines[] = sprintf( 'What the store sells (tagline): %s', $c['tagline'] );
		}
		$tone = trim( (string) ( self::get_settings()['tone'] ?? '' ) );
		if ( $tone !== '' ) {
			$lines[] = sprintf( 'Brand tone / voice: %s', $tone );
		}
		// Catalog / sales signals are optional — some shops find them noise for a
		// calendar driven by well-known commercial moments.
		if ( ! empty( self::get_settings()['use_catalog'] ) ) {
			if ( ! empty( $c['categories'] ) ) {
				$lines[] = sprintf( 'Product categories (best-selling first): %s', implode( ', ', $c['categories'] ) );
			}
			if ( ! empty( $c['products'] ) ) {
				$lines[] = sprintf( 'Best-selling products (highest first): %s', implode( ', ', $c['products'] ) );
			}
		}
		return implode( "\n", $lines );
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

		// One language per generation (keeps the calendar simple to read).
		$lang  = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
		$valid = array_map( static fn( $l ) => $l['code'], self::active_languages() );
		if ( ! in_array( $lang, $valid, true ) ) {
			$lang = self::primary_language();
		}
		// Optional country filter; empty = the language's configured pool (or worldwide).
		$countries = $this->list_codes( explode( ',', (string) wp_unslash( $_POST['countries'] ?? '' ) ), 2 );

		// The Claude call can take up to ~60s; give PHP room beyond typical
		// 30s shared-host limits so it isn't killed mid-request.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		try {
			$events = $this->generate_events( $start, $end, $lang, $countries );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}

		// Store as pending suggestions (prepend the newest).
		$existing = self::get_suggestions();
		foreach ( array_reverse( $events ) as $ev ) {
			$id = 'sug_' . substr( md5( $ev['title'] . '|' . $ev['start_date'] . '|' . wp_json_encode( $ev['countries'] ) ), 0, 10 );
			$ev['id'] = $id;
			$existing = [ $id => $ev ] + $existing;
		}
		self::save_suggestions( $existing );

		$count = count( $events );
		wp_send_json_success( [
			'count'   => $count,
			'message' => $count === 0
				? __( 'No notable commercial moment found in this window — nothing to suggest. Try a longer or different date range.', 'dazont-ecom' )
				/* translators: %d: number of generated marketing events */
				: sprintf( _n( '%d suggestion generated.', '%d suggestions generated.', $count, 'dazont-ecom' ), $count ),
		] );
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
		if ( empty( $countries ) ) {
			$countries = self::country_pool_for( $lang );
		}
		$country_line = $countries ? implode( ', ', $countries ) : 'all relevant markets worldwide for this language';

		$system = self::events_prompt() . "\n\nYou reply with JSON only.";

		$schema = '{"events":[{"title":string (<=60 chars),"type":"sale",'
			. '"start_date":"YYYY-MM-DD","end_date":"YYYY-MM-DD","percent":integer 5-70,'
			. '"email_subject":string (a marketing email subject line, <=80 chars),'
			. '"rationale":string (one short sentence naming the real occasion it maps to)}]}';

		$context = $this->shop_context_text();
		if ( $context === '' ) {
			$context = 'No shop details could be auto-detected.';
		}

		$user = sprintf(
			"Shop context (auto-detected from the website — trust it):\n%s\n\n"
			. "Write the calendar for ONE language only: %s (%s).\n"
			. "Target market / countries: %s.\n"
			. "All titles, email subjects and rationales must be written in %s.\n\n"
			. "Plan promotional events strictly between %s and %s (inclusive) — every date must "
			. "fall in this window.\n\n"
			. "Rules:\n"
			. "- Follow the strategy above. Every event must map to a real occasion (holiday or "
			. "well-known seasonal sale) named in its rationale.\n"
			. "- Space events out across the window — avoid long empty stretches when an obvious "
			. "seasonal sale could sit between two holidays.\n"
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
			$clean[] = [
				'title'         => mb_substr( $title, 0, 80 ),
				'start_date'    => $start,
				'end_date'      => $end,
				'percent'       => min( 90, max( 1, (int) round( (float) ( $ev['percent'] ?? 0 ) ) ) ),
				'countries'     => $countries,   // as chosen at generate time
				'languages'     => [ $lang ],    // one language per generation
				'email_subject' => mb_substr( sanitize_text_field( (string) ( $ev['email_subject'] ?? '' ) ), 0, 120 ),
				'rationale'     => mb_substr( sanitize_text_field( (string) ( $ev['rationale'] ?? '' ) ), 0, 240 ),
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
			throw new RuntimeException( $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
			throw new RuntimeException( sprintf( __( 'Anthropic API error: %s', 'dazont-ecom' ), $msg ) );
		}
		DZE_Ai_Usage::record( 'anthropic', (int) ( $data['usage']['input_tokens'] ?? 0 ), (int) ( $data['usage']['output_tokens'] ?? 0 ), $model );
		$text = '';
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
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

		$model    = '' !== $model ? $model : self::chosen_model();
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
			throw new RuntimeException( $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
			throw new RuntimeException( sprintf( __( 'Anthropic API error: %s', 'dazont-ecom' ), $msg ) );
		}
		DZE_Ai_Usage::record( 'anthropic', (int) ( $data['usage']['input_tokens'] ?? 0 ), (int) ( $data['usage']['output_tokens'] ?? 0 ), $model );
		$text = '';
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		return trim( $text );
	}

	private function call_claude( string $system, string $user ): string {
		if ( DZE_Ai_Usage::over_budget() ) {
			throw new RuntimeException( DZE_Ai_Usage::budget_message() );
		}
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
			throw new RuntimeException( $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
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
			'email_subject' => sanitize_text_field( wp_unslash( $_POST['email_subject'] ?? $src['email_subject'] ) ),
		];
		if ( $ev['start_date'] === '' || $ev['end_date'] === '' ) {
			wp_send_json_error( [ 'message' => __( 'This event needs a valid start and end date.', 'dazont-ecom' ) ] );
		}

		$rule_id = $this->create_sale_rule( $ev );

		unset( $suggestions[ $id ] );
		self::save_suggestions( $suggestions );

		wp_send_json_success( [
			'message'  => __( 'Added to your calendar (as a disabled event — review and enable it below).', 'dazont-ecom' ),
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
			'email_subject' => sanitize_text_field( wp_unslash( $_POST['email_subject'] ?? ( $src['email_subject'] ?? '' ) ) ),
		];
		if ( $ev['title'] === '' ) {
			wp_send_json_error( [ 'message' => __( 'Give the event a title.', 'dazont-ecom' ) ] );
		}
		if ( $ev['start_date'] === '' || $ev['end_date'] === '' ) {
			wp_send_json_error( [ 'message' => __( 'This event needs a valid start and end date.', 'dazont-ecom' ) ] );
		}

		$rule_id = $this->create_sale_rule( $ev );

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
			$only     = isset( $_POST['gmc_targets'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['gmc_targets'] ) ) : [];
			$statuses = DZE_Gmc::instance()->sync_rule( $rule_id, $only );
			$errors   = array_filter( $statuses, static fn( $s ) => ( $s['status'] ?? '' ) === 'error' );
			$message  = empty( $errors )
				? __( 'Event created, enabled and pushed to Google Merchant Center.', 'dazont-ecom' )
				: __( 'Event created and enabled, but some Merchant Center targets errored — check the GMC column.', 'dazont-ecom' );
		}

		wp_send_json_success( [ 'message' => $message, 'rule_id' => $rule_id ] );
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
			'enabled'       => false, // one active event at a time — enable manually.
			'percent'       => (float) $ev['percent'],
			'scope'         => 'all',
			'category_ids'  => [],
			'product_ids'   => [],
			'start'         => $ev['start_date'],
			'end'           => $ev['end_date'],
			'threshold'     => 0.0,
			'banner_enabled'   => true,
			'banner_text'      => $ev['title'],
			'banner_bg'        => '#111111',
			'banner_color'     => '#ffffff',
			'banner_location'  => 'top',
			'product_position' => 'before_product',
			'banner_hooks'     => '',
			'banner_timer'     => true,
			'banner_text_i18n' => [],
			'languages'        => $ev['languages'],
			'hero_swap_enabled'=> false,
			'hero_source_id'   => 0,
			'hero_event_id'    => 0,
			// Marketing-AI metadata (ignored by Discounts, used by the calendar).
			'source'         => 'ai',
			'email_subject'  => $ev['email_subject'],
		];
		update_option( DZE_Discounts::OPTION, $rules, false );
		return $id;
	}

	// =========================================================================
	// Marketing Events panel (rendered at the top of the Marketing Events page)
	// =========================================================================

	public function enqueue_assets( string $hook ): void {
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
			],
		] );
	}

	/** Generate button + date range + suggestions review table + calendar view. */
	public function render_calendar_panel(): void {
		$has_key     = $this->api_key() !== '';
		$suggestions = self::get_suggestions();
		$languages   = self::active_languages();
		$primary     = self::primary_language();
		$gmc         = class_exists( 'DZE_Gmc' ) ? DZE_Gmc::instance() : null;
		$gmc_on      = $gmc && $gmc->is_configured();
		$gmc_targets = $gmc_on ? $gmc->configured_targets() : [];
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
