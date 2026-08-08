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
		add_action( 'admin_menu', [ $this, 'submenu' ], 99 );
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
				'desc'  => __( 'The plugin home screen: stock, spend, calendar, categories.', 'dazont-ecom' ),
				'more'  => __( 'Four blocks: the top out-of-stock best-sellers waiting for restock, the monthly API spend per provider, the planned marketing calendar (current and upcoming events), and the top product categories of the last 3 months with their last novelty-search date. It adds nothing to the WordPress home screen: those blocks query the shop, and a page opened for other reasons should not pay for them.', 'dazont-ecom' ),
			],
			'trending' => [
				'class' => 'DZE_Trending',
				'group' => 'catalog',
				'label' => __( 'Trending Products', 'dazont-ecom' ),
				'desc'  => __( 'The [time_bestsellers] shortcode: best-sellers grid.', 'dazont-ecom' ),
				'more'  => __( 'Computes the shop\'s best-sellers from the WooCommerce Analytics sales lookup table, then delegates the display to WooCommerce\'s own [products] shortcode — native grid, native columns, native pagination, zero custom markup to maintain. Results are cached 24 hours. Pages that don\'t use the shortcode pay no cost at all.', 'dazont-ecom' ),
			],
			'discounts' => [
				'class' => 'DZE_Discounts',
				'group' => 'marketing',
				'label' => __( 'Discounts & Marketing events', 'dazont-ecom' ),
				'desc'  => __( 'Scheduled sales, bulk offers, automatic discounts, banners.', 'dazont-ecom' ),
				'more'  => __( 'Four rule types: scheduled site-wide % sales with optional promo banners; "Bulk offer per item" (% off a product line once you buy N of the same product, shown as a Bundle line); tiered "Bulk order" discounts applied through an automatic Wholesale coupon; and automatic product discounts (new arrivals, slow movers, best-sellers or trending) refreshed weekly. Sale prices are written into the real product data, so on-sale pages, badges and Merchant Center all see them, and they follow the price ending chosen under Settings → General (rounded down, so the reduction is never smaller than the percentage announced). Front-end hooks are only registered while at least one rule is active.', 'dazont-ecom' ),
			],
			'gmc' => [
				'class' => 'DZE_Gmc',
				'group' => 'marketing',
				'label' => __( 'Google Merchant Center', 'dazont-ecom' ),
				'desc'  => __( 'Pushes your scheduled sale promotions to Merchant Center.', 'dazont-ecom' ),
				'more'  => __( 'No product feed involved. Each scheduled sale from the Discounts module is inserted as a Merchant Center PROMOTION through Google\'s Merchant API (the successor of the Content API), into one GMC account per language; the promotion data sources are found or created automatically per country/language. Authentication uses your connected Google account or a service account. A cron keeps the promotions in sync with your events.', 'dazont-ecom' ),
			],
			'gmc_activation' => [
				'class' => 'DZE_Gmc_Activation',
				'group' => 'marketing',
				'label' => __( 'GMC product activation', 'dazont-ecom' ),
				'desc'  => __( 'Chooses which products/variations go to Merchant Center.', 'dazont-ecom' ),
				'more'  => __( 'Manages the "_merchant_center_activation" flag your Merchant Center feed reads, with a ✔/✘ GMC column on the products list. Goal: one Merchant Center entry per real product photo. Automatic rules (whole catalogue or per product): simple products and variable parents on; variations with their own photo on (once per distinct photo, duplicates skipped); variations without any photo → one per colour (detected automatically). Per-product quick strategies — all variations, first of each chosen attribute, none — plus a manual variation picker with thumbnails for tricky cases (e.g. rugs). WPML: one decision per product, mirrored to every translation; the catalogue run walks original-language products only.', 'dazont-ecom' ),
			],
			'marketing_ai' => [
				'class' => 'DZE_Marketing_Ai',
				'group' => 'marketing',
				'label' => __( 'Marketing Assistant', 'dazont-ecom' ),
				'desc'  => __( 'Suggests a promotion calendar; hosts the Settings page.', 'dazont-ecom' ),
				'more'  => __( 'Builds a proposed marketing calendar from your own shop data: site name, categories, sample products, price range, languages (WPML) and per-language target countries. Every suggestion is reviewed by you — accepting turns it into a real scheduled event in Marketing Events; a shortcode renders the final calendar on the front. This module also hosts the shared Settings page (API keys, model choices, monthly spend cap) that the other modules read their configuration from.', 'dazont-ecom' ),
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
				'more'  => __( 'The full product pipeline. A universal prompt registry (your own prompts, with the product data they receive as input and the field each one writes to); a Content chip on every row of the products list — how many photographs it has, in red under two, and an amber "to review" when something is waiting — opening the same popup for that product without leaving the list; the same flow on the product page, in one popup: tick what to generate, run it, read the result in collapsible WordPress editors with the current content one click away, rewrite what is weak, choose where each image goes, accept; a bulk screen where each product keeps one line — green badges for what was written, one symbol for its state — and opens on demand into collapsible WordPress editors, with a button to write a single text again, all of them again, or ask for one more image without re-running the list; several image prompts can run in the same pass, on the product page as in bulk, and a bulk run works on several products at once (you choose how many); content generated but not yet accepted is kept on the product, so a closed tab loses nothing and the bulk screen offers to show everything still waiting for a decision; a text prompt can also be given a companion image meta key, and then the model LOOKS at the product photographs, picks the one showing a real particularity, writes that block\'s h2 and body about what is visible there, and stores the chosen attachment id next to the text (two such blocks with a full gallery, one when the photographs are few); image generation with a session gallery, native-style selection and SEO naming, fed with every photograph of the product (featured image and gallery, up to six) so the model has nothing left to invent; scenes — a fixed support or background image (studio backdrop, table top, garment mockup) sent alongside every product photo with the instruction to keep the product untouched, so a catalogue shot by a dozen suppliers comes back in one visual identity; price recalculation from cost (COGS × your price table, rounded up to the price ending chosen under Settings → General); and the multi-product bulk screen reached from the Products list.', 'dazont-ecom' ),
			],
			'pod' => [
				'class' => 'DZE_Pod',
				'group' => 'product',
				'label' => __( 'POD image', 'dazont-ecom' ),
				'desc'  => __( 'Prints a per-product design on your base mockup.', 'dazont-ecom' ),
				'more'  => __( 'Print on demand only. Upload the design on the product (PNG with transparent background, ideally 4500×5400 px), store the photo of your blank product once under Settings → POD, and one dedicated editable prompt renders the printed product through fal.ai. You review the result, then set it as the main image (the previous main moves to the front of the gallery) or add it to the gallery — with the standard SEO naming.', 'dazont-ecom' ),
			],
			'category_content' => [
				'class' => 'DZE_Category_Content',
				'group' => 'catalog',
				'label' => __( 'Category descriptions', 'dazont-ecom' ),
				'desc'  => __( 'Buying-guide category copy from your real queries, with internal links.', 'dazont-ecom' ),
				'more'  => __( 'Writes product category descriptions the way a shop assistant would advise in that aisle: short, concrete, useful. It reads the SEMrush keyword set already imported for the category — secondary queries (same intent, different wording) become H2 headings, real buyer questions become answered H2 questions — so the copy is built on measured demand instead of assumptions, and the key phrasings come back in bold. The file can be imported straight from the panel. Internal linking comes with it: the candidate URLs are read from your own site — the parent, sub-, sibling and main categories, plus the blog posts and pages that talk about the same subject, ranked by how much their wording overlaps the category and its queries, plus whatever else the sitemap knows about (picked up on its own from Rank Math, Yoast, SEOPress, All in One SEO or WordPress itself, with a warning when none can be read); the model may only use URLs from that list, so they always resolve, everything is worked on in the site\'s main language only — WPML translations are dropped from the pool whether the languages sit in sub-directories, on their own domains or in a query string, and opening a translated category says so and points at the original — it is told to link an article rather than cover its subject a second time, and every anchor has to name the page it points to — a category by its name, an article by the subject of its title rather than the title pasted whole, always inside a sentence that reads well without the link. A rewrite is never applied blind: the result is compared with the description currently saved, word by word, before you decide. The links a description contains are listed on demand under the word count, each with its target, flagging any anchor that does not name it. Length and link count are not one figure for the whole shop: each category gets its own, from 600 to 2500 words and up to fourteen links, worked out from every product in its branch — sub-categories of sub-categories included — and from how many sub-categories it holds — a hub is written longer and points at more pages than a leaf, and the settings can still force a fixed figure. For the linking-only pass you tick the exact pages to link before anything is written, already-linked ones shown as such. Individual products are left out on purpose, the category page already lists them. A Word count column on Products → Categories shows the length, the links the description contains and the keyword count, and opens the writer: the existing description loads in the WordPress editor, generate to rewrite it, edit freely, save — or undo. A category that already reads well can be left alone and sent through the linking-only pass instead: the text stays as it is and only links are added, with the wording around an anchor adjusted by a few words so it matches the page it points to. Nothing is written to the category before you save.', 'dazont-ecom' ),
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
				'desc'  => __( 'Sends batches off to be written, then holds them for review.', 'dazont-ecom' ),
				'more'  => __( 'A description of two thousand words takes the model a minute or more, and a browser request that waits that long is cut off by the host — the HTTP 504 you would otherwise get. So nothing is written inside the request that asks for it: the selection is queued, a background worker takes one item at a time, and the screen only watches. Leave the page and it carries on (through Action Scheduler, which WooCommerce provides, or WP-Cron); stay on Dazont Ecom → Writing queue and the items go by one by one, as WPML does for translations. What comes back waits under "to review": open it, read it against what the category holds today, edit it in the WordPress editor, then accept or discard. Nothing reaches the shop until you accept, unless the batch was sent with immediate saving. Bulk actions on Products → Categories feed it.', 'dazont-ecom' ),
			],
			'variation_split' => [
				'class' => 'DZE_Variation_Split',
				'group' => 'product',
				'label' => __( 'Variation Split (prototype)', 'dazont-ecom' ),
				'desc'  => __( 'One variation attribute → standalone draft products.', 'dazont-ecom' ),
				'more'  => __( 'Splits a chosen variation attribute of a variable product (e.g. colour) into standalone products, one per term — each independently searchable and rankable in SEO. Deliberately conservative: the new products are created as DRAFTS and never published automatically, the source product is left untouched, and each copy takes the description, categories, gallery, the representative variation\'s price and image, and keeps the term as a fixed attribute.', 'dazont-ecom' ),
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
			__( 'Modules', 'dazont-ecom' ),
			__( 'Modules', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	// =========================================================================
	// Product-page hub: one box, one button per enabled product function
	// =========================================================================

	public function hub_meta_box(): void {
		if ( ! self::enabled( 'content' ) && ! self::enabled( 'pod' ) && ! self::enabled( 'gmc_activation' ) ) {
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
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
	}

	public function render_hub( $post ): void {
		$product  = wc_get_product( $post->ID );
		$variable = $product && $product->is_type( 'variable' );
		?>
		<div class="dze-admin dze-hub">
			<?php if ( self::enabled( 'content' ) ) : ?>
				<button type="button" class="button button-primary dze-hub-btn" id="dze-cx-open-auto"><?php esc_html_e( 'Generate content', 'dazont-ecom' ); ?></button>
			<?php endif; ?>
			<?php if ( self::enabled( 'pod' ) ) : ?>
				<button type="button" class="button dze-hub-btn" data-modal="dze-pod-modal"><?php esc_html_e( 'POD image', 'dazont-ecom' ); ?></button>
			<?php endif; ?>
			<?php if ( self::enabled( 'gmc_activation' ) && $product ) : ?>
				<button type="button" class="button dze-hub-btn" data-modal="dze-gmca-modal"><?php esc_html_e( 'GMC activation', 'dazont-ecom' ); ?></button>
			<?php endif; ?>
		</div>
		<script>
		jQuery( function ( $ ) {
			$( '.dze-hub-btn[data-modal]' ).on( 'click', function () {
				$( '#' + $( this ).data( 'modal' ) ).addClass( 'is-open' );
			} );
			$( document ).on( 'click', '.dze-hub-close', function () {
				$( this ).closest( '.dze-cx-modal' ).removeClass( 'is-open' );
			} );
			$( document ).on( 'click', '#dze-pod-modal, #dze-gmca-modal', function ( e ) {
				if ( e.target === this ) { $( this ).removeClass( 'is-open' ); }
			} );
			// Shared hover zoom: any img.dze-hzoom shows a floating large preview.
			var $hz = null;
			$( document ).on( 'mouseenter', 'img.dze-hzoom', function () {
				var src = $( this ).data( 'full' ) || this.src;
				if ( $hz ) { $hz.remove(); }
				$hz = $( '<div class="dze-hzoom-pop"><img src="' + src + '" alt="" /></div>' ).appendTo( 'body' );
			} );
			$( document ).on( 'mousemove', 'img.dze-hzoom', function ( e ) {
				if ( ! $hz ) { return; }
				$hz.css( {
					left: Math.min( e.clientX + 24, window.innerWidth - 360 ) + 'px',
					top: Math.max( 10, Math.min( e.clientY - 170, window.innerHeight - 360 ) ) + 'px'
				} );
			} );
			$( document ).on( 'mouseleave', 'img.dze-hzoom', function () {
				if ( $hz ) { $hz.remove(); $hz = null; }
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
		echo '<div class="wrap dze-wrap dze-admin"><h1>' . esc_html__( 'Dazont Ecom — Modules', 'dazont-ecom' ) . '</h1>';
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
								<button type="button" class="dze-mod-more" data-module="<?php echo esc_attr( $id ); ?>" title="<?php esc_attr_e( 'Full description', 'dazont-ecom' ); ?>">?</button>
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
		<div class="dze-mod-popup" id="dze-mod-popup">
			<div class="dze-mod-popup-box">
				<h3 id="dze-mod-popup-title"></h3>
				<p id="dze-mod-popup-text"></p>
				<p style="text-align:right;margin:14px 0 0;"><button type="button" class="button" id="dze-mod-popup-close"><?php esc_html_e( 'Close', 'dazont-ecom' ); ?></button></p>
			</div>
		</div>
		<style>
		.dze-mod-groups { display: grid; grid-template-columns: repeat(auto-fill, minmax(430px, 1fr)); gap: 16px; margin-top: 14px; max-width: 1400px; }
		.dze-mod-card { background: #fff; border: 1px solid #e2e4e7; border-radius: 8px; padding: 16px 20px; }
		.dze-mod-card h2 { margin: 0 0 6px; font-size: 14px; text-transform: uppercase; letter-spacing: .4px; color: #50575e; }
		.dze-mod-row { display: flex; align-items: flex-start; gap: 12px; padding: 10px 0; border-top: 1px solid #f0f0f1; }
		.dze-mod-row:first-of-type { border-top: none; }
		.dze-mod-info strong { display: block; font-size: 13px; }
		.dze-mod-desc { display: block; color: #646970; font-size: 12px; margin-top: 2px; }
		.dze-mod-more {
			display: inline-block; width: 16px; height: 16px; line-height: 14px; text-align: center; padding: 0;
			border: 1px solid #c3c4c7; border-radius: 50%; background: #f6f7f7; color: #646970;
			font-size: 10px; font-weight: 700; cursor: pointer; vertical-align: 1px; margin-left: 4px;
		}
		.dze-mod-more:hover { border-color: #2271b1; color: #2271b1; }
		.dze-switch { position: relative; display: inline-block; width: 36px; height: 20px; flex: 0 0 36px; margin-top: 2px; }
		.dze-switch input { opacity: 0; width: 0; height: 0; }
		.dze-switch-slider { position: absolute; inset: 0; background: #c3c4c7; border-radius: 999px; transition: background .15s; cursor: pointer; }
		.dze-switch-slider::before { content: ""; position: absolute; width: 16px; height: 16px; left: 2px; top: 2px; background: #fff; border-radius: 50%; transition: transform .15s; }
		.dze-switch input:checked + .dze-switch-slider { background: #00794b; }
		.dze-switch input:checked + .dze-switch-slider::before { transform: translateX(16px); }
		.dze-switch input:disabled + .dze-switch-slider { opacity: .5; cursor: wait; }
		.dze-mod-popup { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 100001; display: none; align-items: center; justify-content: center; }
		.dze-mod-popup.is-open { display: flex; }
		.dze-mod-popup-box { background: #fff; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,.3); max-width: 560px; width: 92vw; padding: 20px 24px; }
		.dze-mod-popup-box h3 { margin: 0 0 10px; }
		.dze-mod-popup-box p { margin: 0; line-height: 1.6; color: #3c434a; }
		.dze-mod-data { display: block; margin-top: 3px; font-size: 11px; }
		.dze-mod-size { color: #787c82; }
		.dze-mod-undeclared { color: #b32d2e; }
		.dze-mod-purge { font-size: 11px; margin-left: 6px; color: #b32d2e; }
		.dze-mod-purge:hover { color: #8a2424; }
		.dze-mod-clean { max-width: 1400px; margin-top: 16px; }
		</style>
		<script>
		jQuery( function ( $ ) {
			var moreTexts = <?php
				$pop = [];
				foreach ( self::catalog() as $mid => $mm ) {
					$pop[ $mid ] = [ 'title' => $mm['label'], 'text' => $mm['more'] ];
				}
				echo wp_json_encode( $pop );
			?>;
			$( document ).on( 'click', '.dze-mod-more', function () {
				var m = moreTexts[ $( this ).data( 'module' ) ];
				if ( ! m ) { return; }
				$( '#dze-mod-popup-title' ).text( m.title );
				$( '#dze-mod-popup-text' ).text( m.text );
				$( '#dze-mod-popup' ).addClass( 'is-open' );
			} );
			$( document ).on( 'click', '#dze-mod-popup-close', function () { $( '#dze-mod-popup' ).removeClass( 'is-open' ); } );
			$( document ).on( 'click', '#dze-mod-popup', function ( e ) { if ( e.target === this ) { $( this ).removeClass( 'is-open' ); } } );
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
		wp_send_json_success( [ 'module' => $id, 'on' => (bool) $s[ $id ] ] );
	}
}
