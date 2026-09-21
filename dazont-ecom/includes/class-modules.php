<?php
defined( 'ABSPATH' ) || exit;

/**
 * Module manager — the single catalog of every plugin function, grouped by
 * type. Each entry carries a short one-line description and a longer detailed
 * one (shown in a popup). Lives as a tab of the Settings page; keeps a
 * fallback submenu/top-level menu so it stays reachable whatever is disabled.
 * The plugin boots ONLY the enabled modules; the manager itself, the updater
 * and the API-key helper are always on.
 */
final class DZE_Modules {

	private const OPT   = 'dze_modules';
	private const NONCE = 'dze_modules';
	public const MENU_SLUG = 'dazont-ecom-modules';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'fallback_menu' ], 9 );
		// LOGS: one entry, the plugin's own. "Je veux un menu logs directement
		// dispo sur le menu wordpress dans le plugin." It is registered here
		// rather than by DZE_Health, because two of its three tabs — what was
		// asked of the models, and what it cost — are the plugin's own
		// accounting and must not disappear with that module.
		// Last in the menu: a log is what you go to when something is wrong,
		// never the first thing offered above the work.
		// SETUP: the plugin's own too, and for the same reason — it is the
		// screen that says what is NOT configured, so it must not disappear
		// with any of the modules it reports on. Just above the Logs: what is
		// set up, then what went wrong.
		add_action( 'admin_menu', [ 'DZE_Setup', 'register_menu' ], 29 );
		add_action( 'admin_notices', [ 'DZE_Setup', 'notice' ] );
		add_action( 'admin_menu', [ 'DZE_Health', 'register_menu' ], 30 );
		add_action( 'admin_init', [ 'DZE_Health', 'maybe_redirect' ] );
		// A hook no version of this plugin listens to any more, cleared here
		// rather than inside the module that used to own it: that module may be
		// switched off, and its migration gave up before reaching the line.
		add_action( 'admin_init', [ 'DZE_Cleanup', 'retire_hooks' ] );
		add_action( 'admin_menu', [ $this, 'submenu' ], 99 );
		// THE MENU IS READ IN ONE ORDER, set in one place — the work first,
		// the plumbing last — after every module has registered its entry.
		add_action( 'admin_menu', [ 'DZE_Screens', 'reorder_menu' ], 999 );
		add_action( 'wp_ajax_dze_modules_toggle', [ $this, 'ajax_toggle' ] );
		// Erasing data is never a side effect of switching a module off: it has
		// its own endpoints, its own buttons, its own confirmations.
		add_action( 'wp_ajax_dze_modules_purge', [ $this, 'ajax_purge' ] );
		add_action( 'wp_ajax_dze_modules_uninstall_flag', [ $this, 'ajax_uninstall_flag' ] );
		// ONE "Dazont Ecom" box on the product page compiles every product
		// function (buttons opening popups) instead of one box per module.
		add_action( 'add_meta_boxes', [ $this, 'hub_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'hub_assets' ] );
	}

	// =========================================================================
	// Catalog — id => class, group, label, short desc, detailed popup text.
	// Array order = boot order (historical instantiation order).
	// =========================================================================

	/** group id => label. */
	public static function groups(): array {
		return [
			'product'   => __( 'Product page', 'dazont-ecom' ),
			'catalog'   => __( 'Shop & catalogue', 'dazont-ecom' ),
			'marketing' => __( 'Marketing', 'dazont-ecom' ),
			'sourcing'  => __( 'Sourcing', 'dazont-ecom' ),
			'tech'      => __( 'Technical', 'dazont-ecom' ),
		];
	}

	public static function catalog(): array {
		return [
			'restock' => [
				'class' => 'DZE_Restock',
				'group' => 'catalog',
				'label' => __( 'Restock', 'dazont-ecom' ),
				'desc'  => __( 'Out-of-stock backlog ranked by lifetime sales.', 'dazont-ecom' ),
				'more'  => __( 'Lists the product lines (simple products or variable parents) that have at least one out-of-stock element, ranked by total lifetime sales — so restocking always starts with proven sellers. Sales figures are cached by a weekly WP-Cron recalculation and aggregated across all WPML languages. This module also hosts the top-level Dazont menu; if you switch it off, the module manager takes the menu over so nothing gets lost.', 'dazont-ecom' ),
			],
			'dashboard' => [
				'class' => 'DZE_Dashboard',
				'group' => 'tech',
				'label' => __( 'Dashboard', 'dazont-ecom' ),
				'desc'  => __( 'The plugin home screen: what waits for your decision, the shop at a glance, the calendar, what it cost.', 'dazont-ecom' ),
				'more'  => __( 'Four blocks: the top out-of-stock best-sellers waiting for restock, the monthly API spend per provider (and what one unit of work costs: a category description, a product run, an image), the planned marketing calendar (current and upcoming events), and the top product categories of the last 3 months with their last novelty-search date. It adds nothing to the WordPress home screen: those blocks query the shop, and a page opened for other reasons should not pay for them.', 'dazont-ecom' ),
			],
			'trending' => [
				'class' => 'DZE_Trending',
				'group' => 'catalog',
				'label' => __( 'Trending Products', 'dazont-ecom' ),
				'desc'  => __( 'The [time_bestsellers] shortcode: best-sellers grid.', 'dazont-ecom' ),
				'more'  => __( 'Computes the shop\'s best-sellers from the WooCommerce Analytics sales lookup table, then delegates the display to WooCommerce\'s own [products] shortcode — native grid, native columns, native pagination, zero custom markup to maintain. Results are cached 24 hours. Pages that don\'t use the shortcode pay no cost at all. Its tag, its attributes and its cache button are documented on Dazont Ecom → Shortcodes, next to every other shortcode the plugin publishes.', 'dazont-ecom' ),
			],
			'discounts' => [
				'class' => 'DZE_Discounts',
				'group' => 'marketing',
				'label' => __( 'Discounts & Marketing events', 'dazont-ecom' ),
				'desc'  => __( 'Scheduled sales, bulk offers, automatic discounts, banners.', 'dazont-ecom' ),
				'more'  => __( 'Four kinds of rule: a site-wide sale on a schedule with an optional banner, a bulk offer on a product line (buy N, get a percentage off), tiered quantities, and automatic discounts. Sale prices are written into the real product data, so the on-sale pages, the badges and Merchant Center all read the same figure, and they follow the price ending chosen under Settings → General. A promotion\'s banner line is translated into every language the shop sells in, in the background, as soon as the event is created. When a sale ends the original prices come back on their own.', 'dazont-ecom' ),
			],
			'gmc' => [
				'class' => 'DZE_Gmc',
				'group' => 'marketing',
				'label' => __( 'Google Merchant Center', 'dazont-ecom' ),
				'desc'  => __( 'Pushes your scheduled sale promotions to Merchant Center.', 'dazont-ecom' ),
				'more'  => __( 'No product feed involved. Each scheduled sale from the Discounts module is filed as a Merchant Center PROMOTION through Google\'s Merchant API, one account per language. Nothing is pushed by hand: a promotion that is switched on and dated goes by itself shortly after it is saved, and is sent again when something Google would see changes — its title, its percentage, its dates. Needs a Google account connected, or a service account, under Dazont Ecom → Marketing → Google Merchant Center. Whether the account is linked to Google Ads is read from Google rather than typed here.', 'dazont-ecom' ),
			],
			'gmc_activation' => [
				'class' => 'DZE_Gmc_Activation',
				'group' => 'marketing',
				'label' => __( 'GMC product activation', 'dazont-ecom' ),
				'desc'  => __( 'Chooses which products/variations go to Merchant Center.', 'dazont-ecom' ),
				'more'  => __( 'Manages the "_merchant_center_activation" flag your Merchant Center feed reads, with a ✔/✘ GMC column on the products list. Goal: one Merchant Center entry per real product photo. Automatic rules, applied product by product: simple products and variable parents on; variations with their own photo on (once per distinct photo, duplicates skipped); variations without any photo → one per colour (detected automatically). Per-product quick strategies — all variations, first of each chosen attribute, none — plus a manual variation picker with thumbnails for tricky cases (e.g. rugs). WPML: one decision per product, mirrored to every translation.', 'dazont-ecom' ),
			],
			'marketing_ai' => [
				'class' => 'DZE_Marketing_Ai',
				'group' => 'marketing',
				'label' => __( 'Marketing Assistant', 'dazont-ecom' ),
				'desc'  => __( 'Suggests a promotion calendar; hosts the Settings page.', 'dazont-ecom' ),
				'more'  => __( 'Builds a proposed marketing calendar from what the shop says about itself — the description written once under Settings → General → About this shop, which every module reads. It proposes events with their dates, their angle and their reduction; nothing is applied until you accept one, and an accepted event becomes an ordinary promotion the Discounts module runs. It also hosts the Settings page, so switching this module off is not a decision about settings: the page stays.', 'dazont-ecom' ),
			],
			'klaviyo' => [
				'classes' => [ 'DZE_Klaviyo', 'DZE_Klaviyo_Auto' ],
				'group' => 'marketing',
				'label' => __( 'Email campaigns (Klaviyo)', 'dazont-ecom' ),
				'desc'  => __( 'Writes the emails of a marketing event and files each one as a draft campaign in Klaviyo.', 'dazont-ecom' ),
				'more'  => __( 'Writes the emails of a marketing event and files each one in Klaviyo as a draft campaign. Pressed again it REWRITES the campaign it already made rather than adding a second, and a campaign whose id was lost is found again by name rather than duplicated — one that has already gone out is never touched, and a new one is made beside it. Each campaign is opened for translation as it is filed, into the languages read from WPML. Needs a Klaviyo API key under Settings → Email campaigns; if that key has no image access, pictures are served from the provider\'s address and the screen says so.', 'dazont-ecom' ),
			],
			'sourcing' => [
				'classes' => [ 'DZE_Explorer', 'DZE_Keywords' ],
				'group'   => 'sourcing',
				'label'   => __( 'Sourcing Assistant', 'dazont-ecom' ),
				'desc'    => __( 'Catalogue explorer, keyword workbench and the sourcing report.', 'dazont-ecom' ),
				'more'    => __( 'One assistant, three parts. The Product Explorer: a storefront-like full-screen view of the catalogue (big images, category rail, filters, zoom, focus mode). The keyword workbench: one SEMrush keyword set per category (tolerant CSV import, statuses, per-category metrics, keyword-to-product matching). And the sourcing report: ALL products ranked by real sales plus the keyword gaps are fed to the model, which returns product opportunities deduplicated against what the shop already sells.', 'dazont-ecom' ),
			],
			'content' => [
				'class' => 'DZE_Content',
				'group' => 'product',
				'label' => __( 'Product Content', 'dazont-ecom' ),
				'desc'  => __( 'Automatic edition of a product: texts, images, price.', 'dazont-ecom' ),
				'more'  => __( 'The full product pipeline, in one popup wherever you are — the products list, the product page. Tick what to generate (title, description, short description, photographs, price), run it, read the result in WordPress\'s own editors beside what the product holds today, and accept or refuse block by block. Every kind of work is a block with its own prompt and its own count, so what varies between a product and a category is WHICH blocks there are, never the shape around them. A note travels with every image made for a product — the real fabric, the finish, what no photograph shows. Nothing reaches the shop until you accept it.', 'dazont-ecom' ),
			],
			'image_lab' => [
				'class' => 'DZE_Image_Lab',
				'group' => 'product',
				'label' => __( 'Image lab', 'dazont-ecom' ),
				'desc'  => __( 'A bench for images that belong to no product yet.', 'dazont-ecom' ),
				'more'  => __( 'Everything else here makes an image FOR something — a product\'s main image, one colour\'s photograph, a POD mockup. This makes one for nothing in particular: a blank mockup to shoot future products on, a backdrop for the shelf, a prompt tried before it is trusted with a whole catalogue. Write what you want, put up to four images on the bench (pasted, dropped, chosen from a folder or picked from the library), generate, and keep what is good. It uses the fal.ai key already configured, records its calls in the same usage report and respects the same monthly budget as every other generation, and what you keep goes into the media library with the shop\'s naming and its alt text — ready to be picked as a background under Settings → Product content, or as the base photo of the POD module. Nothing is stored otherwise: the images you work from travel inside the request and are never written to the site, and the results stay at the provider until you ask for one. It adds no table, no option and no post meta, and no front-end code whatsoever.', 'dazont-ecom' ),
			],
			'translate' => [
				'class'   => 'DZE_Translate',
				'group'   => 'product',
				// Ships switched off: writing into WPML is the kind of thing you
				// try on a staging copy before letting it near a live catalogue.
				'default' => 0,
				'label' => __( 'WPML Translation Module', 'dazont-ecom' ),
				'desc'  => __( 'Translates products, articles, pages and every translatable taxonomy into the site\'s other languages, through WPML.', 'dazont-ecom' ),
				'more'  => __( 'A translation desk of its own, built the way WPML\'s own Translation Management is: a dashboard saying where the site stands language by language, a batch you choose and send, and a review before anything is written. WHAT MAY BE TRANSLATED IS WPML\'S ANSWER, never ours — the post types, the taxonomies and the custom fields are read from its settings. WPML\'s own automatic translation bills per word in credits; the same words go through the Anthropic key already configured here for a fraction of that, in the shop\'s voice. One step of undo on every object.', 'dazont-ecom' ),
			],
			'category_content' => [
				'class' => 'DZE_Category_Content',
				'group' => 'catalog',
				'label' => __( 'Category descriptions', 'dazont-ecom' ),
				'desc'  => __( 'Buying-guide category copy from your real queries, with internal links.', 'dazont-ecom' ),
				'more'  => __( 'Writes product category descriptions the way a shop assistant would advise in that aisle: short, concrete, useful. It reads the SEMrush keyword set already imported for the category — secondary queries become H2 headings, real buyer questions become answered ones — so the copy is built on what people actually search rather than on guesswork. Internal linking comes with it: the candidate pages are read from your own site and ranked by how close their subject really is. Nothing is written to the shop until you accept it.', 'dazont-ecom' ),
			],
			'diagnostic' => [
				'class' => 'DZE_Diagnostic',
				'group' => 'tech',
				'label' => __( 'Content diagnostic', 'dazont-ecom' ),
				'desc'  => __( 'Reads the shop against your own standards, lists what is missing, and hosts the work and the decisions (Dazont Ecom → Diagnostic).', 'dazont-ecom' ),
				'more'  => __( 'The question that comes before every other one: WHERE. A shop of a thousand products has no memory of which of them is short of a paragraph or has one photograph too few, and a catalogue audited once in a spreadsheet is out of date the week after. This reads the shop against a list of criteria YOU edit — a field, a comparison and a figure — and says what falls short, worst first, grouped by the screen the work is done on. It writes nothing and decides nothing: every line points at the screen that fixes that one thing. The reading is done once a night and kept, so opening the screen costs nothing.', 'dazont-ecom' ),
			],
			'mesh' => [
				'class' => 'DZE_Mesh',
				'group' => 'catalog',
				'label' => __( 'Internal linking', 'dazont-ecom' ),
				'desc'  => __( 'Reads who links to whom across the site, names the pages nobody points at, and says which pages should point at them (Dazont Ecom → Internal linking).', 'dazont-ecom' ),
				'more'  => __( 'Everything else on this site counted links; nothing knew who linked to whom. A category knew how many links its own description held, an article knew how many it held, and neither could say whether anybody in the shop pointed at it — so a page could be written, translated and forgotten, reachable from a menu and from nowhere else. This module reads the whole site once and writes the graph down: one row per internal link, from a page to a page, with the words the link was made of. Product categories, articles and pages; products are left out, since a product page already lists what it belongs to. From that graph it answers the two questions a mesh is judged on — which pages nobody points at, and which pages point at nothing — and, for a page that is short, which other pages should be the ones to link to it: a first pass on wording that ignores the words half the site uses, then a short reading that keeps only the pages genuinely about the same subject. Nothing is written by this module: the pages you tick are sent to the writing queue, where the same linking pass the rest of the plugin uses adds the sentence, and it comes back for review like everything else. Pages laid out with a page builder are read for their links and can be linked TO, but are never offered as a place to write one: their text is not in the post, so a link written there would be stored and never appear.', 'dazont-ecom' ),
			],
			'automation' => [
				'class' => 'DZE_Automation',
				'group' => 'tech',
				'label' => __( 'Automation', 'dazont-ecom' ),
				'desc'  => __( 'Runs the shop\'s own functions on a schedule, a few items a day.', 'dazont-ecom' ),
				'more'  => __( 'Adds no ability of its own: everything it launches is a function already on this site, with its own screen, its own prompt and its own button. What it brings is the part nobody can do by hand — a few items a day, every day, without being asked. Each switch now lives on the screen of the work it runs, so this page is only where the whole day is read side by side. What is written waits for your yes or no unless you tick "save without review", and nothing is ever started twice on the same page.', 'dazont-ecom' ),
			],
			'reviews' => [
				'class'   => 'DZE_Reviews',
				'group'   => 'product',
				'default' => 0, // testing tool: opt-in only.
				'label'   => __( 'Review generator (testing)', 'dazont-ecom' ),
				'desc'    => __( 'Writes sample customer reviews — staging catalogues only.', 'dazont-ecom' ),
				'more'    => __( 'Testing tool, off by default. Writes customer reviews with Claude from the product data and saves them as native WooCommerce reviews (rating, verified badge, plus the title and language meta WooCommerce Photo Reviews reads). New reviews land as PENDING, so they are moderated in the standard WooCommerce → Reviews screen. A Reviews column on the products list shows the count and opens a small panel — generate, read the drafts, push them to the moderation queue or discard, with the prompt editable in place. The "Generate reviews (Dazont)" bulk action runs on that same list, a spinner in each product\'s cell and a random number of reviews per product, writing straight to the moderation queue: individual generation is where the prompt gets calibrated, bulk is for volume once it is. Ratings are drawn by the plugin (70% five-star by default) instead of being alternated by the model, and reviews are written in the shop\'s main language. Publishing fabricated reviews on a live shop is illegal in the EU and under FTC rules — everything created here is tagged and deletable in one click.', 'dazont-ecom' ),
			],
			'queue' => [
				'class' => 'DZE_Queue',
				'group' => 'tech',
				'label' => __( 'Writing queue', 'dazont-ecom' ),
				'desc'  => __( 'Sends batches off to be written, then holds everything generated until you accept it — on the screen that asked for it.', 'dazont-ecom' ),
				'more'  => __( 'A description of two thousand words takes the model a minute or more, and a browser request that waits that long is cut off by the host — the HTTP 504 you would otherwise get. So nothing is written inside the request that asks for it: the selection is queued, a background worker takes one item at a time, and the screen only watches. Leave the page and it carries on (through Action Scheduler, which WooCommerce provides, or WP-Cron); stay on the screen that sent the work and the items go by one by one, as WPML does for translations. What comes back waits under "to review": open it, read it against what the category holds today, edit it in the WordPress editor, then accept or discard. Nothing reaches the shop until you accept, unless the batch was sent with immediate saving. Bulk actions on Products → Categories feed it. It carries photographs too, not only text: a product photograph waits here like everything else, reviewed as a picture — the new one beside the ones the product already shows, with Keep it, Make another, Throw it away. WHERE IT IS READ BACK IS THE SCREEN THAT ASKED FOR IT. There used to be one central list holding every kind of waiting work, under its own menu entry; it has been taken out — one more screen to remember for a question each module already answers at home. The internal linking screen has a "To review" tab, the writing bench lists its categories and its products under the bench itself, WPML Translations keeps its own, and the Overview names all three and sends you to whichever has something waiting. Every decision is SIGNED: the row says "Accepted by Marie" or "Discarded by Paul", read from what was stored when the button was pressed, and the products log names who decided each one — because a shop that hands this work to somebody else needs to see that a page was dealt with AND by whom. A pass that saved without review has nobody to name and says so rather than inventing one.', 'dazont-ecom' ),
			],
			'variation_split' => [
				'class' => 'DZE_Variation_Split',
				'group' => 'product',
				'label' => __( 'Variation Split (prototype)', 'dazont-ecom' ),
				'desc'  => __( 'One variation attribute → standalone draft products.', 'dazont-ecom' ),
				'more'  => __( 'Splits a chosen variation attribute of a variable product (e.g. colour) into standalone products, one per term — each independently searchable and rankable in SEO. Deliberately conservative: the new products are created as DRAFTS and never published automatically, the source product is left untouched, and each copy takes the description, categories, gallery, the representative variation\'s price and image, and keeps the term as a fixed attribute.', 'dazont-ecom' ),
			],
			'health' => [
				'class' => 'DZE_Health',
				'group' => 'tech',
				'label' => __( 'Health check', 'dazont-ecom' ),
				'desc'  => __( 'Watches the outside services this plugin depends on and says when one breaks (Dazont Ecom → Logs).', 'dazont-ecom' ),
				'more'  => __( 'Everything this plugin does that can fail happens against somebody else\'s service: Anthropic writes the texts, fal.ai the images, Klaviyo the campaigns, Google the promotions. Those services change — an API revision is retired, a key is rotated, a Cloud project loses a permission — and without this the shop finds out weeks later, when a promotion did not go out. Every failure this plugin puts on screen carries a link straight to that log, opening in a new tab — a message says WHAT broke, the log says what the service actually replied, when, and how often, and the owner should not have to remember a settings page exists to see the second half. The link comes from the failure class every screen of the plugin already uses, not from a hundred call sites that could each forget it; switch this module off and it disappears with the log it points at. Two things run here. A LOG: every failed call to one of those services is written down with what the plugin was doing and what came back, in the provider\'s own words, bounded to the last sixty entries and collapsing a repeat into one line with a count, so it can never grow into a problem of its own. And a weekly CHECKUP: each connection is asked one cheap question — the model list at Anthropic, the account at Klaviyo, the Merchant API for each account at Google, the endpoint at fal.ai — plus WooCommerce\'s analytics table (the one the best-sellers read), this plugin\'s own scheduled jobs, and whether a newer release is waiting. The answer is kept beside the last one, so a connection that worked last week and does not today sends ONE email to the shop\'s address and raises one notice in the admin, once, rather than repeating itself every week. What it does not do, and no plugin can: rewrite itself to match a provider\'s new API. What it does instead is name the failure precisely — the endpoint, the status, the words the service used — which is what a fix starts from, and then close the loop: one switch on the same screen lets the shop INSTALL that fix by itself the day it is published, through WordPress\'s own auto-update mechanism rather than an updater of our own running beside it. Nothing runs on the front end, and nobody is called outside the weekly cron or an explicit click on "Check now".', 'dazont-ecom' ),
			],
		];
	}

	// =========================================================================
	// State + boot
	// =========================================================================

	private static function states(): array {
		$s = get_option( self::OPT, [] );
		return is_array( $s ) ? $s : [];
	}

	/**
	 * A module is ON unless explicitly switched off — except entries carrying
	 * 'default' => 0, which stay off until switched on (testing tools).
	 */
	public static function enabled( string $id ): bool {
		$s = self::states();
		if ( isset( $s[ $id ] ) ) {
			return ! empty( $s[ $id ] );
		}
		$cat = self::catalog();
		return ! isset( $cat[ $id ]['default'] ) || ! empty( $cat[ $id ]['default'] );
	}

	/** Instantiate every ENABLED module, in the catalog (historical) order. */
	public static function boot(): void {
		foreach ( self::catalog() as $id => $m ) {
			if ( ! self::enabled( $id ) ) {
				continue;
			}
			// A module entry may cover several classes (e.g. Sourcing Assistant).
			foreach ( (array) ( $m['classes'] ?? $m['class'] ?? [] ) as $cls ) {
				if ( class_exists( $cls ) ) {
					$cls::instance();
				}
			}
		}
	}

	// =========================================================================
	// Menu — normally a tab of the Settings page. Fallbacks keep it reachable:
	// an own submenu when the Settings host module is off, and the top-level
	// Dazont menu itself when Restock (its owner) is off.
	// =========================================================================

	public function fallback_menu(): void {
		if ( self::enabled( 'restock' ) ) {
			return;
		}
		add_menu_page(
			__( 'Dazont Ecom', 'dazont-ecom' ),
			__( 'Dazont Ecom', 'dazont-ecom' ),
			'manage_woocommerce',
			DZE_Restock::MENU_SLUG,
			[ $this, 'render_page' ],
			'dashicons-cart',
			56
		);
	}

	public function submenu(): void {
		if ( self::enabled( 'marketing_ai' ) ) {
			return; // reachable as the Modules tab of the Settings page.
		}
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			DZE_Screens::label( 'modules' ),
			DZE_Screens::label( 'modules' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	// =========================================================================
	// Product-page hub: one box, one button per enabled product function
	// =========================================================================

	public function hub_meta_box(): void {
		if ( ! self::enabled( 'content' ) && ! self::enabled( 'gmc_activation' ) && ! self::enabled( 'translate' ) ) {
			return;
		}
		add_meta_box( 'dze-hub', __( 'Dazont Ecom', 'dazont-ecom' ), [ $this, 'render_hub' ], 'product', 'side', 'high' );
	}

	/** Shared admin styles (modal shells, notes) for the hub and its popups. */
	public function hub_assets( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type || ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		DZE_Assets::admin_css();
	}

	public function render_hub( $post ): void {
		$product  = wc_get_product( $post->ID );
		$variable = $product && $product->is_type( 'variable' );
		?>
		<div class="dze-admin dze-hub">
			<?php if ( self::enabled( 'content' ) ) : ?>
				<button type="button" class="button button-primary dze-hub-btn" id="dze-cx-open-auto"><?php esc_html_e( 'Generate content', 'dazont-ecom' ); ?></button>
			<?php endif; ?>
			<?php if ( self::enabled( 'gmc_activation' ) && $product ) : ?>
				<button type="button" class="button dze-hub-btn" data-modal="dze-gmca-modal"><?php esc_html_e( 'GMC activation', 'dazont-ecom' ); ?></button>
			<?php endif; ?>
			<?php if ( self::enabled( 'translate' ) && class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active() && $product ) : ?>
				<?php $dze_tro = DZE_Translate::obj( 'post', (int) $post->ID, 'product' ); ?>
				<?php if ( $dze_tro && DZE_Translate::obj_targets( $dze_tro ) ) : ?>
					<!-- ONE TRANSLATION SCREEN PER OBJECT, and it is not here.
					     This used to open a popup of ours on the product page —
					     a second per-object surface beside the module's own
					     screen, narrating its own plumbing. -->
					<a class="button dze-hub-btn" href="<?php echo esc_url( DZE_Translate::editor_url( $dze_tro ) ); ?>"><?php esc_html_e( 'Translate', 'dazont-ecom' ); ?></a>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<script>
		jQuery( function ( $ ) {
			// DELEGATED, because a button that opens one of these popups is no
			// longer only in the hub box: "Translate with Dazont Ecom" is
			// planted inside WPML's own Language box by a script that runs
			// after this one. Bound directly, it opened nothing.
			$( document ).on( 'click', '.dze-hub-btn[data-modal]', function () {
				$( '#' + $( this ).data( 'modal' ) ).addClass( 'is-open' );
			} );
			$( document ).on( 'click', '.dze-hub-close', function () {
				$( this ).closest( '.dze-cx-modal' ).removeClass( 'is-open' );
			} );
			$( document ).on( 'click', '#dze-gmca-modal', function ( e ) {
				if ( e.target === this ) { $( this ).removeClass( 'is-open' ); }
			} );
		} );
		</script>
		<?php
	}

	// =========================================================================
	// Screen
	// =========================================================================

	/** Standalone page (fallback menus only). */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		echo '<div class="wrap dze-wrap dze-admin"><h1>' . esc_html( DZE_Screens::label( 'modules' ) ) . '</h1>';
		$this->render_tab();
		echo '</div>';
	}

	/** Tab body (used by the Settings page tab AND the standalone page). */
	public function render_tab(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$groups = self::groups();
		$by     = [];
		foreach ( self::catalog() as $id => $m ) {
			$by[ $m['group'] ][ $id ] = $m;
		}
		?>
		<p class="description"><?php esc_html_e( 'Switch any function on or off. A change takes effect on the next page load. Click ? for the full description.', 'dazont-ecom' ); ?></p>
		<div class="dze-mod-groups">
		<?php foreach ( $groups as $gid => $glabel ) : ?>
			<?php if ( empty( $by[ $gid ] ) ) { continue; } ?>
			<div class="dze-mod-card">
				<h2><?php echo esc_html( $glabel ); ?></h2>
				<?php foreach ( $by[ $gid ] as $id => $m ) : $on = self::enabled( $id ); ?>
					<?php $foot = DZE_Cleanup::measure( $id ); ?>
					<div class="dze-mod-row">
						<label class="dze-switch">
							<input type="checkbox" class="dze-mod-toggle" data-module="<?php echo esc_attr( $id ); ?>" <?php checked( $on ); ?> />
							<span class="dze-switch-slider"></span>
						</label>
						<div class="dze-mod-info">
							<strong><?php echo esc_html( $m['label'] ); ?>
								<?php echo wp_kses_post( DZE_Hub::more_button( $id ) ); ?>
							</strong>
							<span class="dze-mod-desc"><?php echo esc_html( $m['desc'] ); ?></span>
							<span class="dze-mod-data" data-module="<?php echo esc_attr( $id ); ?>">
								<?php if ( ! $foot['declared'] ) : ?>
									<em class="dze-mod-undeclared"><?php esc_html_e( 'Data footprint not declared — see DZE_Cleanup::map().', 'dazont-ecom' ); ?></em>
								<?php elseif ( $foot['rows'] ) : ?>
									<span class="dze-mod-size"><?php
										printf(
											/* translators: 1: row count, 2: size, 3: what it is made of */
											esc_html__( 'In the database: %1$s rows, %2$s — %3$s', 'dazont-ecom' ),
											esc_html( number_format_i18n( $foot['rows'] ) ),
											esc_html( DZE_Cleanup::human_size( $foot['bytes'] ) ),
											esc_html( implode( ', ', $foot['detail'] ) )
										);
									?></span>
									<button type="button" class="button-link dze-mod-purge" data-module="<?php echo esc_attr( $id ); ?>" data-label="<?php echo esc_attr( $m['label'] ); ?>"><?php esc_html_e( 'Erase this data', 'dazont-ecom' ); ?></button>
								<?php else : ?>
									<span class="dze-mod-size"><?php esc_html_e( 'Nothing stored in the database.', 'dazont-ecom' ); ?></span>
								<?php endif; ?>
							</span>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
		</div>
		<?php $core = DZE_Cleanup::measure( 'core' ); ?>
		<div class="dze-mod-card dze-mod-clean">
			<h2><?php esc_html_e( 'Database cleanup', 'dazont-ecom' ); ?></h2>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'Switching a function off keeps its data, so you can switch it back on and find everything in place. Erasing is a separate decision, taken here, function by function — each "Erase this data" button above only removes what that one function wrote. Nothing of WooCommerce is ever touched: prices, products, images and real customer reviews stay untouched.', 'dazont-ecom' ); ?>
			</p>
			<p>
				<button type="button" class="button button-secondary" id="dze-mod-purge-all"><?php esc_html_e( 'Erase everything Dazont Ecom wrote', 'dazont-ecom' ); ?></button>
				<span class="dze-mod-size" style="margin-left:8px;"><?php
					printf(
						/* translators: %s: size of the plugin's own settings */
						esc_html__( 'plugin settings included (%s)', 'dazont-ecom' ),
						esc_html( DZE_Cleanup::human_size( $core['bytes'] ) )
					);
				?></span>
			</p>
			<p>
				<label>
					<input type="checkbox" id="dze-mod-uninstall" <?php checked( (bool) get_option( DZE_Cleanup::OPT_ON_UNINSTALL ) ); ?> />
					<?php esc_html_e( 'Also erase everything when the plugin is deleted from WordPress', 'dazont-ecom' ); ?>
				</label>
				<span class="dze-mod-desc"><?php esc_html_e( 'Off by default: deleting the plugin leaves your imported keyword sets and settings in place, so reinstalling finds them again. Deactivating never erases anything, whatever this box says.', 'dazont-ecom' ); ?></span>
			</p>
		</div>

		<p id="dze-mod-note" class="description" style="display:none;">
			<?php esc_html_e( 'Saved ✓ — the change applies on the next page load.', 'dazont-ecom' ); ?>
			<a href="#" onclick="window.location.reload();return false;"><?php esc_html_e( 'Reload now', 'dazont-ecom' ); ?></a>
		</p>
		<?php
		// The "?" beside every name and the popup behind it, from the one
		// function that owns them: the Automation screen wears the same pair.
		$dze_more = [];
		foreach ( self::catalog() as $mid => $mm ) {
			$dze_more[ $mid ] = [ 'title' => $mm['label'], 'text' => $mm['more'] ];
		}
		DZE_Hub::more_assets( $dze_more );
		?>
		<style>
		.dze-mod-groups { display: grid; grid-template-columns: repeat(auto-fill, minmax(430px, 1fr)); gap: 16px; margin-top: 14px; max-width: 1400px; }
		.dze-mod-card { background: #fff; border: 1px solid #e2e4e7; border-radius: 8px; padding: 16px 20px; }
		.dze-mod-card h2 { margin: 0 0 6px; font-size: 14px; text-transform: uppercase; letter-spacing: .4px; color: #50575e; }
		.dze-mod-row { display: flex; align-items: flex-start; gap: 12px; padding: 10px 0; border-top: 1px solid #f0f0f1; }
		.dze-mod-row:first-of-type { border-top: none; }
		.dze-mod-info strong { display: block; font-size: 13px; }
		.dze-mod-desc { display: block; color: #646970; font-size: 12px; margin-top: 2px; }
		.dze-switch { position: relative; display: inline-block; width: 36px; height: 20px; flex: 0 0 36px; margin-top: 2px; }
		.dze-switch input { opacity: 0; width: 0; height: 0; }
		.dze-switch-slider { position: absolute; inset: 0; background: #c3c4c7; border-radius: 999px; transition: background .15s; cursor: pointer; }
		.dze-switch-slider::before { content: ""; position: absolute; width: 16px; height: 16px; left: 2px; top: 2px; background: #fff; border-radius: 50%; transition: transform .15s; }
		.dze-switch input:checked + .dze-switch-slider { background: #00794b; }
		.dze-switch input:checked + .dze-switch-slider::before { transform: translateX(16px); }
		.dze-switch input:disabled + .dze-switch-slider { opacity: .5; cursor: wait; }
		.dze-mod-data { display: block; margin-top: 3px; font-size: 11px; }
		.dze-mod-size { color: #787c82; }
		.dze-mod-undeclared { color: #b32d2e; }
		.dze-mod-purge { font-size: 11px; margin-left: 6px; color: #b32d2e; }
		.dze-mod-purge:hover { color: #8a2424; }
		.dze-mod-clean { max-width: 1400px; margin-top: 16px; }
		</style>
		<script>
		jQuery( function ( $ ) {
			// Erasing is destructive and one-way: the exact wording of what is
			// about to go has to be read before it happens.
			function purge( module, label, $where ) {
				$.post( window.ajaxurl, {
					action: 'dze_modules_purge',
					nonce: '<?php echo esc_js( wp_create_nonce( DZE_Cleanup::NONCE ) ); ?>',
					module: module
				} ).done( function ( res ) {
					if ( res && res.success ) {
						$where.html( '<span class="dze-mod-size">' + res.data.message + '</span>' );
					} else {
						window.alert( ( res && res.data && res.data.message ) || '<?php echo esc_js( __( 'Something went wrong.', 'dazont-ecom' ) ); ?>' );
					}
				} ).fail( function () {
					window.alert( '<?php echo esc_js( __( 'Something went wrong.', 'dazont-ecom' ) ); ?>' );
				} );
			}
			$( document ).on( 'click', '.dze-mod-purge', function () {
				var $b = $( this ), mod = $b.data( 'module' );
				var txt = $b.closest( '.dze-mod-data' ).find( '.dze-mod-size' ).text();
				if ( ! window.confirm( '<?php echo esc_js( __( 'Erase the data of:', 'dazont-ecom' ) ); ?> ' + $b.data( 'label' ) + '\n\n' + txt + '\n\n<?php echo esc_js( __( 'This cannot be undone. The function itself stays available and will start again from nothing.', 'dazont-ecom' ) ); ?>' ) ) {
					return;
				}
				purge( mod, $b.data( 'label' ), $b.closest( '.dze-mod-data' ) );
			} );
			$( document ).on( 'click', '#dze-mod-purge-all', function () {
				if ( ! window.confirm( '<?php echo esc_js( __( 'Erase EVERYTHING Dazont Ecom has written: keyword sets, settings, prompts, generated reviews, and every flag it added to your products and categories.', 'dazont-ecom' ) ); ?>\n\n<?php echo esc_js( __( 'Your products, prices, images and real customer reviews are not touched. This cannot be undone.', 'dazont-ecom' ) ); ?>' ) ) {
					return;
				}
				if ( ! window.confirm( '<?php echo esc_js( __( 'Last check — erase everything now?', 'dazont-ecom' ) ); ?>' ) ) {
					return;
				}
				var $b = $( this ).prop( 'disabled', true );
				$.post( window.ajaxurl, {
					action: 'dze_modules_purge',
					nonce: '<?php echo esc_js( wp_create_nonce( DZE_Cleanup::NONCE ) ); ?>',
					module: '__all__'
				} ).done( function ( res ) {
					$b.prop( 'disabled', false );
					window.alert( ( res && res.data && res.data.message ) || '' );
					window.location.reload();
				} ).fail( function () {
					$b.prop( 'disabled', false );
					window.alert( '<?php echo esc_js( __( 'Something went wrong.', 'dazont-ecom' ) ); ?>' );
				} );
			} );
			$( document ).on( 'change', '#dze-mod-uninstall', function () {
				var on = $( this ).is( ':checked' ) ? 1 : 0;
				$.post( window.ajaxurl, {
					action: 'dze_modules_uninstall_flag',
					nonce: '<?php echo esc_js( wp_create_nonce( DZE_Cleanup::NONCE ) ); ?>',
					on: on
				} ).done( function () { $( '#dze-mod-note' ).show(); } );
			} );

			$( document ).on( 'change', '.dze-mod-toggle', function () {
				var $t = $( this ).prop( 'disabled', true );
				$.post( window.ajaxurl, {
					action: 'dze_modules_toggle',
					nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>',
					module: $t.data( 'module' ),
					on: $t.is( ':checked' ) ? 1 : 0
				} ).done( function ( res ) {
					$t.prop( 'disabled', false );
					if ( res && res.success ) { $( '#dze-mod-note' ).show(); }
					else {
						$t.prop( 'checked', ! $t.is( ':checked' ) );
						window.alert( ( res && res.data && res.data.message ) || '<?php echo esc_js( __( 'Something went wrong.', 'dazont-ecom' ) ); ?>' );
					}
				} ).fail( function () {
					$t.prop( 'disabled', false ).prop( 'checked', ! $t.is( ':checked' ) );
					window.alert( '<?php echo esc_js( __( 'Something went wrong.', 'dazont-ecom' ) ); ?>' );
				} );
			} );
		} );
		</script>
		<?php
	}

	/** Erases one module's data, or every module's, and reports what went. */
	public function ajax_purge(): void {
		check_ajax_referer( DZE_Cleanup::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$id = isset( $_POST['module'] ) ? sanitize_text_field( wp_unslash( $_POST['module'] ) ) : '';
		if ( '__all__' === $id ) {
			$rows = 0;
			foreach ( DZE_Cleanup::all_ids() as $mid ) {
				$rows += DZE_Cleanup::purge( $mid )['rows'];
			}
			wp_send_json_success( [
				/* translators: %s: number of database rows removed */
				'message' => sprintf( __( 'Everything erased — %s database rows removed.', 'dazont-ecom' ), number_format_i18n( $rows ) ),
			] );
		}
		$id = sanitize_key( $id );
		if ( ! isset( DZE_Cleanup::map()[ $id ] ) && 'core' !== $id ) {
			wp_send_json_error( [ 'message' => __( 'Unknown module.', 'dazont-ecom' ) ] );
		}
		$res = DZE_Cleanup::purge( $id );
		wp_send_json_success( [
			/* translators: %s: number of database rows removed */
			'message' => sprintf( __( 'Erased — %s rows removed.', 'dazont-ecom' ), number_format_i18n( $res['rows'] ) ),
			'rows'    => $res['rows'],
		] );
	}

	/** Opt-in: erase the data when WordPress deletes the plugin. */
	public function ajax_uninstall_flag(): void {
		check_ajax_referer( DZE_Cleanup::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		update_option( DZE_Cleanup::OPT_ON_UNINSTALL, empty( $_POST['on'] ) ? 0 : 1, false );
		wp_send_json_success();
	}

	public function ajax_toggle(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$id = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		if ( ! isset( self::catalog()[ $id ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown module.', 'dazont-ecom' ) ] );
		}
		$s        = self::states();
		$s[ $id ] = ! empty( $_POST['on'] ) ? 1 : 0;
		update_option( self::OPT, $s, false );
		// Switched off, a module stops being booted — but a cron event it
		// scheduled would go on firing into the void. A module with standing
		// work of its own says how to stand it down.
		if ( ! $s[ $id ] ) {
			foreach ( (array) ( self::catalog()[ $id ]['classes'] ?? self::catalog()[ $id ]['class'] ?? [] ) as $cls ) {
				if ( class_exists( $cls ) && method_exists( $cls, 'disable' ) ) {
					$cls::disable();
				}
			}
		}
		wp_send_json_success( [ 'module' => $id, 'on' => (bool) $s[ $id ] ] );
	}
}
