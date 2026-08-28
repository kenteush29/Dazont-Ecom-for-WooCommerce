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
				'more'  => __( 'Four rule types: scheduled site-wide % sales with optional promo banners; "Bulk offer per item" (% off a product line once you buy N of the same product, shown as a Bundle line); tiered "Bulk order" discounts applied through an automatic Wholesale coupon; and automatic product discounts (new arrivals, slow movers, best-sellers or trending) refreshed weekly. Sale prices are written into the real product data, so on-sale pages, badges and Merchant Center all see them, and they follow the price ending chosen under Settings → General (rounded down, so the reduction is never smaller than the percentage announced). Front-end hooks are only registered while at least one rule is active. The banner\'s look is decided ONCE for the shop, not per event — background, text colour, text size and padding, set with WordPress\'s own colour picker so a brand colour can be typed as six characters rather than hunted on a gradient, with a strip beside the fields that IS the banner and follows every change. The face and the weight stay the theme\'s, and a text size left at zero leaves it there too. The banner is ONE sentence, written and translated as one — the discount announced inside it, in each market\'s own words and its own typography, because a French line with an English \'-10% OFF\' stuck on the end cannot be written well in either language. The figure stays live all the same: whatever percentage the sentence carries is rewritten to the promotion\'s own before it is shown, so changing 10 to 20 changes every market at once and none of them can drift, and a sentence that names no discount is flagged on the screen where it is written rather than patched in English. A big event can also take over the home page picture for its duration and give it back at the end. Which picture that is is the SHOP\'s answer, not the event\'s — it is the same for all of them — so it is READ from the home page itself — its featured image, the first image in it, or the first one its page builder names — and read again whenever that page is saved. There is nowhere to override it: the home page is where that picture is changed, and a second answer to a one-answer question is how a shop ends up swapping a picture it stopped showing months ago. Settings → Marketing events shows what was read, and links to the page to change it. It never mistakes an event picture for the page\'s own, which would have replaced the original with nothing the day the event ended. The picture that takes its place is chosen from the library or made on the spot from the home page\'s own, at the same shape: the instructions for it ship EMPTY, because what it should look like is not something this plugin has an opinion about, and the promotion\'s title and dates are sent whatever they say. A promotion is announced in every language the shop sells in: its banner line is translated as soon as the event is created or switched on — one call for all the languages at once, in the background, never inside the request that saved it — because a promotion with no wording in a language does not run in that language at all. A line written by hand is never overwritten. It has a switch of its own — \'Translate on save\', under Settings → Marketing events, on by default — sitting next to the instructions it follows; switched off, the per-language fields on an event stay yours to fill, and the event only runs in the main language until you fill them. Both screens that carry a promotion also carry the button: write the title, press Translate, read what came back in a list that folds away, correct anything, and save — the lines are shown before they are stored, never after. While a promotion is running, a product can say what it saves — "Save $12.00" beside the price, small and in the shop\'s own accent colour so it reads as a note on the two prices and not as a third one, worked out from the price struck through and the price charged, in the shop\'s own currency, and "Save up to" when a variable product\'s variations do not all save the same. The shop\'s own corner badge is left exactly as it is: a badge is a claim in a fixed little shape, sized by the theme for the word "Sale!", and a figure needs room. Where it speaks is a second choice: on the product being looked at, on the grids (a category, a search, the home page, and the related products at the foot of a product page, which is a grid like any other), or both. Settings → Discounts → General decides, and switched off the filter is not even hung.', 'dazont-ecom' ),
			],
			'gmc' => [
				'class' => 'DZE_Gmc',
				'group' => 'marketing',
				'label' => __( 'Google Merchant Center', 'dazont-ecom' ),
				'desc'  => __( 'Pushes your scheduled sale promotions to Merchant Center.', 'dazont-ecom' ),
				'more'  => __( 'No product feed involved. Each scheduled sale from the Discounts module is inserted as a Merchant Center PROMOTION through Google\'s Merchant API (the successor of the Content API), into one GMC account per language; the promotion data sources are found or created automatically per country/language. Authentication uses your connected Google account or a service account. Nothing has to be pushed by hand: a promotion that is switched on and dated goes to Merchant Center by itself shortly after it is saved, is re-sent when something Google would see changes (its title, its percentage, its dates, its markets) and is taken down there when it is switched off — the round-trip always happens in the background, never in the request that saved. The hourly round catches whatever the moment missed, including a promotion the shop has been running for days that Google has never heard of, and re-tries what an account refused; a promotion Google already holds is left alone rather than re-sent, and re-sending one can never duplicate it, since it is filed under an id derived from the promotion itself. It can be switched off in one place, on the module\'s own settings screen, and each promotion keeps its \'Sync now\' for an immediate push with the accounts\' answers on screen. The country a promotion runs in is never asked for: it is read from the account itself — the promotion data sources it holds, failing that its business address — because what Merchant Center accepts is decided in Merchant Center. Each account shows one thing on the settings screen: whether a Google Ads account is linked to it, read from the Merchant API\'s campaign-management service (the Content API answers for accounts that do not). What a Merchant Center connection is allowed to read is the LINK, never the spend. The sync can be told to skip the accounts that have none: a promotion pushed to an account no campaign reads is a promotion pushed nowhere. And because Google has no delete for promotions — a promotion is ended by refiling it with a period already past — the screen also asks each account what it is HOLDING and ends any of it in one click, whatever put it there. That is the recovery path for the promotion deleted here before it could be taken down there, which used to stay live and keep being served with nothing in this plugin able to see it.', 'dazont-ecom' ),
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
				'more'  => __( 'Builds a proposed marketing calendar from what the shop says about itself — the description written once under Settings → General → About this shop, which every module of the plugin reads — and the dates you want covered — nothing else to answer. One calendar for the shop, in its main language, valid in every language it sells in: a promotion that runs in one language and not the others is a bug the shop hears about from its customers. The countdown on a generated event is not left to the model\'s mood: the shop sets the bar once, under Settings → Marketing events — a discount from X%, running so many days at most — and an event clears it or it does not, in both directions, with the recommended figures (20% or more, a week at most) printed beside the fields and the reason they are what they are. A countdown on every promotion is decoration, not a deadline. It stays a tick box on each event either way. Every suggestion is reviewed by you — accepting turns it into a real scheduled event in Marketing Events; a shortcode renders the final calendar on the front. This module also hosts the shared Settings page (API keys, model choices, monthly spend cap) that the other modules read their configuration from. Its tabs are grouped the way a shop thinks rather than the way the plugin is built: \'Shop content\' holds the categories, the product content, the GMC activation and the reviews, \'Marketing events\' holds the events and their email campaigns, and the exact screen is picked from a quiet second row underneath — WordPress\'s own sub-navigation, so it is a place the owner has already been. Every link ever printed at one of those screens still lands on it. Wherever a screen carries several prompts, they are shut cards with one open at a time — the same list, and the same gesture, on Product content, Categories and Email campaigns. Everywhere the plugin is about to call a model — the calendar here, the sourcing report, the category writer, the product texts and images — a discreet pencil opens the exact instructions being sent and takes you straight to the field where they are edited, on the tab that owns them — with, beside every prompt in the plugin, what ELSE is sent with it (the product, the promotion, the other emails, the answer format) and a \'Draft one for my shop\' button. A blank box is the hardest part of a prompt and the shipped text is written for a plugin, not for a shop: that button asks for a first version built from what this shop sells, what this prompt is for and what the plugin hands it at run time. It lands in the box and nothing else happens — reading it, cutting it and saving it stay the shop\'s.', 'dazont-ecom' ),
			],
			'klaviyo' => [
				'class' => 'DZE_Klaviyo',
				'group' => 'marketing',
				'label' => __( 'Email campaigns (Klaviyo)', 'dazont-ecom' ),
				'desc'  => __( 'Writes the emails of a marketing event and files each one as a draft campaign in Klaviyo.', 'dazont-ecom' ),
				'more'  => __( 'A promotion the shop runs and nobody is told about is half a promotion — and one email is rarely the whole story of one. A promotion holds as many as it deserves: emails are added and removed one at a time, and each one has a TYPE rather than a name — Warm-up · J−2, Launch · J0, Reminder · J+5, Last chance · end − 2 — picked from a menu where every option carries the day it falls on, and picking it sets that day. The type is not decoration: it is what the writing is told this email is, alongside its place in the sequence and what the other emails of the same promotion already said, so a reminder is not written as a second announcement. The list of types is the SHOP\'S, edited under Settings → Email campaigns: a name and the day it goes out, counted from the promotion\'s start or from its end, never both. Add \'Mid-sale bestsellers, 6 days after it starts\', remove what this shop does not use, restore the shipped four in one click; an email whose type has been deleted falls back to the first of the list rather than losing anything. Two reminders or no warm-up are as ordinary as one of each, and an email ADDED takes the first moment the promotion has not used yet — it used to take the first of the list full stop, so the second and third emails of a promotion were more launches, each of them correctly written as the one announcing that the sale opens today; two of the same moment are still allowed and the rows say so where they sit. Nothing is a fixed slot, which is what lets the automation module build the whole run itself later. They are listed on the promotion\'s own screen with their date, their subject and a thumbnail of what they actually look like; the TYPE and the DAY are kept the moment they are chosen, like everything else here that changes an email, because they used to wait for the event\'s Save and were lost to any redraw — and, worse, the writing is told what an email IS from what is stored, so one set to \'Reminder\' on screen and still \'Launch\' underneath was written as a second announcement; each is written, tested and drafted on its own, and the writing is told which of the four it is — a warm-up carries no prices because nothing has started, a reminder does not repeat the announcement, a last chance is short — and each is told what to do with the shortlist as well as with the words, so a last call shows one to three products instead of the same grid of nine. All of them are saved by the page\'s own Save button, and the promotions list says how many a promotion carries and how many are already in Klaviyo. This writes the email that announces it and files it as a DRAFT campaign in the account that already sends the shop\'s campaigns. The header and the footer are FIXED — a frame that changes from one campaign to the next is a shop the reader stops recognising — and they are not drawn here: they are read out of Klaviyo, which makes it the one thing that has to be set up before the module will write anything at all. You keep one template in your account with your header, ONE empty section in the middle, and your footer; the plugin asks Klaviyo to render it and cuts it on that empty section, so what the shop sends inside is your real frame to the pixel, saved sections and all, with your own stylesheet still on it — headings come out in your font and links in your colour without anything being copied twice. Change the header in Klaviyo, press Read it again, and every following promotion has it. What is stored is HIS template and a marker — nothing of this plugin\'s own is frozen into that snapshot, because anything baked in stays as it was the day it was read and no later fix can reach it. Klaviyo’s own API cannot do this any other way: a saved section refuses to be read, refuses to be pointed at by a new template and refuses to be edited inside a copied one — the renderer is the one door that opens, and a template with no empty section is refused out loud, with what to do about it. The frame is shown as a preview, never as markup, and until one has been read the module says so and the email editor stays shut: an invented header sent under the shop\'s name is worse than no email. Two prompts run the campaign, one briefing the other, which is the whole of what an \'agent\' means here. The PLAN prompt is asked what this promotion is worth in emails — how many, on which days, and what each one says that the others do not — and it writes nothing: it creates the rows, each carrying its day and a one-sentence brief. The owner reads the plan, moves a date, drops one, and only then spends anything on writing them. \'Write them all\' then runs the EMAIL prompt on each row, one request per email, through the very endpoint a single Write button uses, so a batch cannot drift from what one click does and a host that cuts a request off costs one email rather than the campaign. Each brief is handed to the writing as its own section, so the second prompt knows what the first decided this email was for. And every email is shown THE OTHERS — their day and how many days that is from this one, their subject, their headings, how they opened, what their links say and which products they leaned on — because \'do not repeat the announcement\' means nothing to somebody who has not read it, and the repetition a reader notices first is the same photograph twice. That is the one rule of this screen whose effect nobody can see, so the screen shows it: \'What this email is told\' opens on the exact words handed over before the owner\'s own instructions — built by the very function that writes the email, asked for on demand, with no model call and nothing stored. An email that comes back looking like its neighbour is either one that was never shown the neighbour or one that ignored it, and those two have opposite fixes. And the products are not left to good intentions either: the shortlist is asked for wider, the products another email of the promotion has already put in front of the same reader fall to the END of it and are marked as used, and it is cut back to size — so what this email reads first is what nobody has been sent yet. Asked for in words it never held: the same nine best-sellers arrived in the same order every time, and the first three are the three anybody picks. Both are ordinary editable prompts with their own defaults. Inside that frame the content area is bare — 600 pixels edge to edge, no padding, no shape of ours — so the layout really is the prompt\'s. What is NOT the prompt\'s is what a product looks like: the writing never sees a card and never writes one — it puts [[PRODUCT 2]] where a product goes and the shop drops its own block there, so a card cannot be restyled by something that was never handed it, and the answer stays short enough to come back whole. Markers written one after another are laid out TOGETHER, as many per row as the settings say (one to four), and the shop builds that row rather than the writing — so a row of three holding one product and two holes is not a thing that can happen, and the last row takes the width it needs. They stack on a phone with no media query: each card is an inline-block that is 100% wide but no wider than its share of the column, so a narrow screen simply wraps them, and Outlook gets a real table through conditional comments. The card is built by the plugin in the shop\'s own style — headings font, text font and size, text colour, sale colour, the struck-through old price, the button\'s colour and its corners, the card\'s paper and border — every one of them READ from the theme rather than set here. The theme\'s own settings answer FIRST — that is the one place the owner goes to change a colour — and a theme that keeps a global palette has its variables resolved against it, so a setting that reads var(--ast-global-color-0) comes back as the colour it points at instead of being dropped. What the theme left unsaid is answered by WordPress\'s own standard: theme.json, which since 6.1 a classic theme contributes to as well, and the colour palette every theme declares to the block editor. WordPress\'s OWN default palette is never read — it belongs to WordPress, not to the shop, and mistaking one for the other is how a green nobody chose ends up in an email. Presets come back as CSS variables, which mean nothing in an inbox, so they are resolved to real values first, and the quiet colours — the struck-through old price, the card border, the text on a button — are WORKED OUT from the two the theme did state rather than invented. WooCommerce answers for the shop colour where it was set. A theme that keeps its appearance only in its own Customizer (Astra, GeneratePress, Kadence, OceanWP) is asked last and only about what the standard left blank, so adding another is one entry and nothing else. Change the shop and the next email follows, with nothing to keep in step. Settings shows the whole table — each value, a swatch, and WHERE it was read from — beside the very card the inbox receives, drawn by the same function, so a style nobody can trace is never a style nobody trusts. Inside it, ONE editable prompt decides the whole email — the words and the layout both. The plugin contributes no shape of its own: how many products to a row, whether they are grouped under headings, where the picture and the buttons go, how long the thing is, all of it is written in that prompt and all of it changes when the prompt changes, exactly as the product texts and the product images are steered. What the model may not decide is the facts. The shop hands it a shortlist of real products — what actually sold over the RIGHT window, restricted to the event\'s categories when it names any. A promotion is written weeks before it runs, and the products that sell in the week it is written are not the products that will sell in the week it opens — a New Year sale drafted in August, read off the last fortnight, is a summer catalogue with a December headline on it. So a promotion opening more than three weeks out is read from the same days of the year ONE YEAR BACK, widened by its own length and a week each side; one opening soon is read from the recent window you set. A shop too young to have that history falls back to recent sales, then to catalogue popularity, and the screen and the writing are both told which window answered — each with its name, its link, its photograph and two prices: what it was, and what this promotion makes it, computed with the very line the Discounts module prices with so the inbox and the product page cannot disagree. What comes back is checked against that list: a photograph that is not the shop\'s is removed rather than hotlinked, and an amount that was never handed over is named on the screen instead of being quietly corrected. Opening an email on a made photograph is a PERMISSION, not an order: allowed, the writing DESCRIBES the picture and nothing is made until somebody asks. The asking is a bench rather than a button: the description the writing came up with lands in a field the owner can rewrite, \'Generate test picture\' makes one from it that touches nothing, and it is looked at, adjusted and tried again until it is right — which is where the work actually is, and it used to cost an email each time round. When it is right, one tick box says the next writing makes the real one in the same pass, so the email comes out finished instead of half-made. A picture accepted is put where the writing left a hole for it, in the email, in what is stored, and in what the draft and the test send read — all three, because storing it beside the email and leaving the hole in the text is how a photograph got made, paid for and never seen. It is a switch, not a fact: it costs money and a minute, and it is only worth it when it is good — off, the writing is told not to place one and the promotion\'s own image fills the space if it has one. On, the picture has a PROMPT OF ITS OWN, beside the copy prompt and edited the same way. It used to be described by the copy prompt, email by email, which left nothing to work on: judging a picture cost a whole email, and improving it meant editing the instructions for the WORDS in the hope the picture followed. Now one text is written, tested on its own as many times as it takes — a test picture touches nothing and is thrown away — and kept. The promotion\'s title, dates and discount are appended to it whatever it says, and once the email is written, what that email IS and its subject line come too, which is why the picture is made after the words and not before: a warm-up and a last chance are not the same photograph. It is made from up to FOUR real photographs of the products THIS email leads on — the ones its own text shows, then the rest of its own shortlist — rather than one, and rather than the promotion\'s best-sellers, which were one list read afresh for every email of it and opened all of them on the same four packshots — nano-banana-2 is an edit model, and a single packshot with a loose brief gives it nothing to hold on to, so it invents gear the shop does not sell. A rule the owner\'s brief cannot override travels with every request: keep the products in the references exactly as they are, add nothing that is not in them. The frame is asked for wide (3:2), because an email opens on a banner and a square packshot was giving a square banner. It is a real photograph the shop owns — the one chosen for the event, or the product that is selling — put in the setting the occasion evokes with fal.ai, and hosted BY KLAVIYO — never filed in the shop\'s media library, which is for the shop\'s products: a picture made for one campaign, plus one for every test, is a library nobody would ever pick from. The account that sends the email is the account that holds its pictures; if the API key has no image access, the picture is used from the provider\'s own address and the screen says so rather than letting it stop loading in an inbox weeks later. Never a word written into the image: the title goes over it in HTML, where five markets can read it. The email is shown as it will look, on both screens, redrawing as you type — the HTML is one click away for the rare day it is wanted — and one button sends it to your own inbox through Klaviyo itself — Klaviyo\'s renderer, Klaviyo\'s sending — so what you read is what a customer would get. It is saved by the event\'s own Save button: the page has one form and one button. Creating the draft files the email in Klaviyo AS BLOCKS — a drag-and-drop template, not a lump of HTML — opens a campaign for the audience chosen once — one campaign for everybody, minus the exclusion you name — assigns the email to it, and stops. It never sends and never schedules: the draft carries its send DAY, and the hour is Klaviyo\'s — it works out, reader by reader, the moment that person actually opens his mail. So the screen asks for a day and nothing else — never earlier than TOMORROW, in the picker and again on the way in, because a campaign filed for today has already lost the hours anybody opens and one filed for a day gone by will never go out at all. It can tell you which days this list answers on: the opens of the last four weeks, by weekday, with the best one named. A rule of thumb about the best time to send is not the same thing as one shop\'s own readers. Language follows the account\'s own method: profiles carry the language assigned to them and Klaviyo\'s translator serves each reader in his, except the promotional line itself, which is taken market by market from the adapted titles the event already carries. That is only possible because the email arrives as BLOCKS. Klaviyo reads its per-language texts, links and pictures out of blocks, and a template filed as one lump of HTML has none — so every email this plugin sent used to go out in one language whatever the account was set to. The body the writing produced is read for what it IS — a heading, a paragraph, a picture, a button, a row of products — and each piece is filed as its own block, editable by hand in Klaviyo and answerable to the translations. The frame around them is not a copy: the owner\'s own template is read fresh at that moment and its EMPTY section is the one filled, so his header, his footer and his saved sections travel as they stand today. A template that is not a drag-and-drop one is refused where it is chosen rather than weeks later on a draft, because an email built from it could not be translated. Blocks are what MAKE the translation possible, and they are not what asks for it: Klaviyo holds the languages of a message in a collection of its own, and a message nobody declared one for has a single language however carefully it was built. So each campaign is opened for translation as it is filed — written in the shop\'s main language, offered in the others — and the list of those others is READ FROM WPML rather than typed a second time, with a field beside the switch for the day Klaviyo spells one of them differently. Asked again on a later hand-over, the languages are set on the collection that exists rather than a second one being made, so a market added between two emails of the same promotion reaches both. The settings screen shows one word — Translations: Activated, or Disabled — and a table of the shop\'s own languages beside the code each is sent as and how many of the account\'s contacts actually carry it (\'English 7204/100 sampled · French 222 · Polish nobody\'). There is no switch and no list to type: the languages are WPML\'s, and whether translating is worth anything is a fact about the account rather than a preference. Klaviyo serves each contact the language written on his profile, and WHICH property it reads is chosen in Klaviyo\'s own settings — not readable through the API, so the plugin does not pretend to check it: one sentence says where it is, and the table says what the contacts carry, which is the thing that actually decides. And each email says, on the promotion\'s own screen, what it actually went out in — \'EN + DE, ES, FR, PL\' or \'EN only — no translation\' — read from what was actually written, language by language, never from the setting, which says intent. The pass runs one language per request and runs them AT THE SAME TIME, a few at a time, driven from the browser: the screen says \'Writing DE, ES… (2 of 4) · FR, PL ✓\' and ends on \'Translated — 31 texts in FR, DE, ES, PL\'. One request for four languages is four model calls behind one click — minutes long, timing out, leaving the shop staring at a button — and four requests each writing to Klaviyo is four writers on one campaign. So the languages are written in parallel, put aside one basket each, and filed in a SINGLE call once they are all in: quicker to watch, and one write instead of four. The translating itself runs on the fastest model rather than the one that writes the emails: it is a mechanical job with strict rules and an answer that is checked against what was sent, and it is the difference between seconds and a minute a language. What is stored is what has actually been written, so a run where one language failed says the other three and names the one that did not. And because the preview beside the writing is a browser putting the body inside a snapshot of the frame — right enough to write against, not the thing itself — a second tab, \'As sent\', files the blocks and asks KLAVIYO to draw them: what it shows is the email an inbox receives, and the test send uses that very template. The audience is chosen once, and the screen that chooses it can act on the account rather than send you to it: segments Klaviyo hides from its own listing because they are inactive are offered anyway and marked as such, the chosen one can be switched back ON from here, and the exclusion every promotion wants can be BUILT from here — the buyers of the last N weeks, on this account\'s own order metric. An email that already has a picture keeps it when it is written again — the writing is handed the URL and told to use it as it stands, so a rewrite costs nothing in pictures and changes nothing about the one chosen. The screen says so, with the picture beside the sentence, and \'Take it off\' returns the email to a place with no picture in it rather than deleting a photograph that is hosted and paid for. Handing a promotion over is one gesture: every written email goes to Klaviyo oldest first, one campaign each, named after the event and its type, scheduled on its own day and all carrying the event\'s name as a tag — Klaviyo has no campaign that goes out on four days, and the tag is what makes the four read as one thing in the account. Doing it again rewrites the campaign each email already has instead of leaving six versions behind, and a campaign that is no longer a draft is left exactly as it is. The row then says where the draft is AND what is left to do with it — \'Draft in Klaviyo — schedule it there\' — because nothing is ever sent from this plugin and a link that says only where something is does not say who sends it. Klaviyo answers 200 to a day it then stores empty — asked for Smart Send Time with a date it keeps the method and drops the day, which is a draft with an empty calendar and no way to know it from the answer. So what it actually KEPT is read back, and when the day is gone it is simply sent again the way this account does keep it: a static send at nine in the morning IN EACH READER\'S OWN TIME ZONE, which is the shape every campaign this shop has ever sent already carries. A real hour on the right day beats a perfect hour on no day at all. What it kept after that is read back a second time and remembered: a draft it dated says nothing more, one it left undated says \'No date in Klaviyo — choose it there before scheduling\' on the row, every time it is looked at, rather than once in a message that has long scrolled away. An email filed by an older version carries no answer either way and says nothing, because silence is better than a guess. And it is SCHEDULED from here, in one click, without opening Klaviyo at all — which is the difference between a plugin that prepares a promotion and one that can run it. That was measured before it was written, on this shop\'s own account: a send job on a campaign carrying a future date does not send it, it schedules it, and Klaviyo says so in its own words. The campaign is read first — it must be a draft and it must hold a day, neither assumed — the job is created, and what Klaviyo holds AFTERWARDS is read again and kept: the row says \'Scheduled in Klaviyo for 28/09/2026\' from that, never from the day the shop typed, which is intent and not a fact. The send strategy is never touched on the way, which is the whole difference from \'send it now\': a campaign whose date is quietly replaced while being scheduled is a campaign that goes out on the wrong day. The same button unschedules it — reverting the job, never cancelling the campaign — so a day chosen by mistake is undone where it was chosen instead of sending the shop to Klaviyo to repair what this plugin did. Each email is put there as a DRAFT by default — Klaviyo\'s own Schedule button is what makes it go out on its day — and "send it now" is offered beside it for the one case the API can actually do: an email whose day is today, sent within minutes, asked for twice and never in bulk. Everything is reachable without a screen, so the day the shop automates its promotions the same function writes the same email. The private API key is only ever sent to Klaviyo, the account is only ever read or written behind a click, and no page of the shop calls it at all.', 'dazont-ecom' ),
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
				'more'  => __( 'The full product pipeline. A universal prompt registry (your own prompts, with the product data they receive as input and the field each one writes to); a Content chip on every row of the products list — how many photographs it has, in red under two, and an amber "to review" when something is waiting — opening the same popup for that product without leaving the list; the same flow on the product page, in one popup: tick what to generate, run it, write the note that travels with every image made for this product — the real fabric, the finish, what no photograph shows — where the run is started rather than in another popup, read the result in collapsible WordPress editors with the current content one click away, rewrite what is weak, choose where each image goes, accept; a rewrite is TOLD what the field says today and asked for another way in — same facts, another opening, another order — because the same instructions on the same product answer the same thing, and a button that returns what you already had looks broken (never on the attributes, where a different answer would mean different facts); a photograph handed in from outside can be the SUBJECT — a supplier shot to remake — or only a REFERENCE, one tick box apart, and that box is the ONLY question asked about the photographs: which one the model starts from is the prompt\'s business, so the badges and the arrows that used to rank them on the bench are gone. With the product\'s own photograph kept as the subject, what is added is read for the setting, the light and the styling and never for a colour, a pattern or a marking, which is how a scene is copied without losing the real product. An image prompt also says what SHAPE it wants — square, portrait, landscape, or the shape of the photograph it works from — asked of the provider itself, because a shape written in the instructions is a wish the provider never reads. Product photographs travel to the image model at 1024 px rather than 768: a buckle, a webbing pitch or a label stops being readable below that, and an unreadable detail is one the model paints from imagination — six angles at most on a gallery run, because a seventh divides attention over exactly what a technical product is judged on, and the instruction says plainly to leave a detail out of frame rather than approximate it. Beside every button that makes an image — the product popup, the one-function popup, the bulk panel, the promotion\'s picture bench — a running total says what that product (or that promotion) has already cost in images, counted after each generation from the number of billable units fal itself reports, at the price per image the shop gives once. A product the model keeps getting wrong is one to stop paying for, and that decision is taken while looking at it rather than at the end of the month. a bulk screen where each product keeps one line — green badges for what was written, one symbol for its state — and opens on demand into collapsible WordPress editors, with a button to write a single text again, all of them again, or ask for one more image without re-running the list; several image prompts can run in the same pass, on the product page as in bulk, and a bulk run works on several products at once (you choose how many); content generated but not yet accepted is kept on the product, so a closed tab loses nothing and the bulk screen offers to show everything still waiting for a decision; every photograph a product has is on screen — the main image, the gallery, and the ones its variations carry, each named after its colour — and any prompt, text or image, can tick \'the variation photographs\' among its inputs to send the other colourways as context, named in the request as other colours so the construction is read from them and never the colour; any text prompt can tick \'the product photographs\' among its inputs, and then the model READS the product off its own pictures — the material, the cut, the fastenings, the real colours — instead of inventing them from a supplier title (the featured image first, three at most, sent only for the prompts that asked for it); a text prompt can also be given a companion image meta key, and then the model LOOKS at the product photographs, picks the one showing a real particularity, writes that block\'s h2 and body about what is visible there, and stores the chosen attachment id next to the text (two such blocks with a full gallery, one when the photographs are few); a button on each block of the product screen — the title, the description, the short description, the main image — opening a small popup with that one function in it: the instructions readable and editable for a single run (or saved for good), the block as it stands today above what was just written, and one click to save it; the blocks that write to a custom field, which no screen shows, are listed in the Dazont Ecom box instead; an image workshop reached from the featured-image box and from the gallery — pick which photograph to work from, pick the recipe (remake a supplier photo: its text, its watermarks and its badges gone, square framing, sharpness restored; another angle of the same product, for a colour variant that inherited a single shot; the shop main image), pick where the result goes, and optionally delete the photograph it was made from, so a catalogue rebuilt here leaves neither the supplier\'s wording on the page nor their files on the disk; a Main image lane in the product popup — paste the images themselves (Ctrl+V, dropped on the box or picked from the computer — several at once, the first one the subject and the others there to say what it does not show), paste its address, or use the product\'s own photographs, pick the surface to shoot on, and one call turns it into the shop\'s catalogue shot (product straight-on, soft contact shadow). That surface is a FILE, not a description: a shelf of background images you keep — a studio backdrop, a floor to lay rugs on, a table top — picked when you generate and sent with the product, kept from the settings or straight from the product screen, and the plugin can draw a plain grey one for you if you have none; because two products asked for \'a light grey background\' come back on two different greys, shown next to the current main image before one click puts it in place and pushes the old one to the front of the gallery; image generation with a session gallery, native-style selection and SEO naming, fed with the product\'s own photographs, featured image first, AS MANY AS THE SHOP ASKS FOR and two unless it moved that number — an edit model handed six angles of one bag reads them as six things to reconcile and hands back a seventh, with the back view\'s mesh on the front and a buckle where no buckle is, while one clean photograph plus one more angle keeps the product and still shows what the first shot hides; the number is a field beside the fal.ai key, because a flat-lay and a garment on a model do not need the same one, and the photographs actually sent are named in the \'data sent\' panel; a run also carries ONE picture it made here before, so the next one is different from it rather than the same again — one and not several, since every image the model made is a chance for a detail it invented last time to come back as a reference this time; scenes — a shelf of fixed images sent alongside the product photographs: surfaces to shoot on (studio backdrop, table top, floor) and blank products to print on (a white tee, a hoodie, a mug), each one marked as one or the other so the request describes it for what it is — a product is PLACED on a surface, a design is PRINTED on a blank product, and a mockup told it was a backdrop came back as a t-shirt lying on a t-shirt. printing a design on a blank product is one of those: the blank product is a background of the shelf, the design is the image you paste in, and the prompt says the rest; variation images — a product sold in three colours and five sizes needs three photographs, not fifteen: the variations are grouped by the attribute that changes what the product looks like (the colour one when there is one, otherwise the first that is not a size), each group says how many variations it covers and whether it has a photograph of its own, and one generated image is written to every variation of its group. The colours with nothing of their own are ticked for you, an existing photograph of that colour is used as the subject when there is one, and when there is none the product\'s own photographs are used with the instruction to change the colour and nothing else. One popup holds all of it, opened from WooCommerce\'s own Variations panel or from the toolbox: every colour on its own line with the image it carries, and three ways to fill it — a photograph already in the library, one pasted or dropped from the desktop (filed with the shop\'s own file name, title and alt text, never left as DSC_0421.jpg), or a generated one. A pasted supplier shot is not filed on sight: it is shown, and you say whether it IS the photograph or the subject of a clean one — their logo, their play button and their framing gone, the product untouched. It is deliberately NOT a bulk pass: which colour of which product needs which photograph is a decision taken in front of the product, one line at a time. Each colour also carries a note of its own — the material, the exact camo, the shade — and the product itself carries one, written in the image workshop and sent with EVERY image made for it: what no photograph shows and no shared prompt can know. The prompt of the run is read and edited from the popup like any other. A photograph taken off a product — the old main image asked to leave, or a supplier shot retired by its remake — is replaced on the variations that were showing it, so nothing keeps it on the shop; price recalculation from cost (COGS × your price table, rounded up to the price ending chosen under Settings → General); and the multi-product bulk screen reached from the Products list, fed either by ticking rows there or by pasting a column of product IDs straight from a spreadsheet — commas, tabs or line breaks, whatever is not a digit separates, and any ID that is not a product is named back to you instead of being dropped in silence. Accepting is never all or nothing: every generated block carries a tick, so you can keep the images and leave the description behind. Fields that write a WooCommerce field carry WooCommerce\'s own name for it.', 'dazont-ecom' ),
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
				'label' => __( 'Product translation', 'dazont-ecom' ),
				'desc'  => __( 'Translates a product\'s written content into the site\'s other languages.', 'dazont-ecom' ),
				'more'  => __( 'Translates the WRITTEN content of a product — name, description, short description, SEO title and description — into every other language active on the site, and hands the result to WPML as a real translation, linked to the original. WPML\'s own automatic translation bills per word in credits; the same words go through the Anthropic key already configured here for a fraction of that, in the shop\'s voice, with a glossary of terms that must never be translated (brand, references, technical names). Price, stock, attributes, images and taxonomy are NOT touched: they belong to WooCommerce Multilingual, and when this module has to create a translation it asks WPML to run its own custom-field sync so the numbers arrive from the original rather than from us. Nothing is written blind: a translation is produced, shown next to the original AND next to what the translation holds today, edited in the WordPress editor if a word is wrong, and applied field by field — a block you untick is left out. A translation this module did not write says so before it can be replaced. The prompt and the glossary are editable under Settings → Translation, and every call is charged to the monthly budget like any other.', 'dazont-ecom' ),
			],
			'category_content' => [
				'class' => 'DZE_Category_Content',
				'group' => 'catalog',
				'label' => __( 'Category descriptions', 'dazont-ecom' ),
				'desc'  => __( 'Buying-guide category copy from your real queries, with internal links.', 'dazont-ecom' ),
				'more'  => __( 'Writes product category descriptions the way a shop assistant would advise in that aisle: short, concrete, useful. It reads the SEMrush keyword set already imported for the category — secondary queries (same intent, different wording) become H2 headings, real buyer questions become answered H2 questions — so the copy is built on measured demand instead of assumptions, and the key phrasings come back in bold. The file can be imported straight from the panel. Internal linking comes with it: the candidate URLs are read from your own site — the parent, sub-, sibling and main categories, plus the blog posts and pages that talk about the same subject, ranked by how much their wording overlaps the category and its queries, plus whatever else the sitemap knows about (picked up on its own from Rank Math, Yoast, SEOPress, All in One SEO or WordPress itself, with a warning when none can be read); the model may only use URLs from that list, so they always resolve, everything is worked on in the site\'s main language only — WPML translations are dropped from the pool whether the languages sit in sub-directories, on their own domains or in a query string, and opening a translated category says so and points at the original — it is told to link an article rather than cover its subject a second time, and every anchor has to name the page it points to — a category by its name, an article by the subject of its title rather than the title pasted whole, always inside a sentence that reads well without the link. Writing includes linking: the internal-linking pass runs as the last step of a description, so a text is never handed over half done and never has to be read a second time by the model to be finished. A rewrite is never applied blind: the description currently saved is one click away above the new one, with its word and link counts, before you decide. The links a description contains are listed on demand under the word count, each with its target, flagging any anchor that does not name it. Length and link count are not one figure for the whole shop: each category gets its own, from 600 to 2500 words and up to fourteen links, worked out from every product in its branch — sub-categories of sub-categories included — and from how many sub-categories it holds — a hub is written longer and points at more pages than a leaf, and the settings can still force a fixed figure. For the linking-only pass you tick the exact pages to link before anything is written, already-linked ones shown as such. Individual products are left out on purpose, the category page already lists them. A Word count column on Products → Categories shows the length, the links the description contains and the keyword count, and opens the writer: the existing description loads in the WordPress editor, generate to rewrite it, edit freely, save — or undo. A category that already reads well can be left alone and sent through the linking-only pass instead: the text stays as it is and only links are added, with the wording around an anchor adjusted by a few words so it matches the page it points to. Nothing is written to the category before you save.', 'dazont-ecom' ),
			],
			'diagnostic' => [
				'class' => 'DZE_Diagnostic',
				'group' => 'tech',
				'label' => __( 'Diagnostic', 'dazont-ecom' ),
				'desc'  => __( 'Reads the shop against your own standards and lists what is missing, page by page.', 'dazont-ecom' ),
				'more'  => __( 'The question that comes before every other one: WHERE. A shop of a thousand products has no memory of which of them is short of a paragraph, which has one photograph in its gallery and which never got its second custom block, and a catalogue audited once in a spreadsheet is out of date the week after. This reads the shop and says so — and it is a to-do list, not a machine: nothing here writes, generates, spends or decides, and every line points at the screen that already fixes that one thing. The criteria are not this plugin\'s opinions: they are a LIST YOU EDIT, presented as the cards the prompt library uses — shut, a criterion is its name and its rule in words (\'Product · description is less than 120 words\'); open, it is the three controls that make it. A criterion is a FIELD, a COMPARISON and a figure, in the vocabulary a product export is filtered with: is empty, is not empty, is less than, is at most, is more than, is at least, equals, does not equal, contains, does not contain — written as words or as symbols (&lt;, ≤, =), whichever you prefer, switched on the same screen. What can be read is deliberately wide: a product\'s title, description, short description, SEO title and description, SKU and any custom field; its main photograph AND that photograph\'s width, height and smallest side in pixels, so \'main photograph under 800px\' is a criterion and not a guess; its gallery, the links in its description, its price, sale price, stock, weight, categories, tags, attributes, variations, reviews, average rating and age in days; a category\'s description, internal links and how many products it holds; an article\'s links and length. A text is compared by its LENGTH — words for a description, characters for a title, because that is what each is actually short of — and by what it contains. Add your own, change a figure, restore the shipped list in one click. Nine ship, and they are rows like any other rather than code, so \'restore the default\' is putting those nine back and nothing else. Criteria saved before the comparisons existed keep meaning exactly what they meant. A criterion says WHICH POST TYPE it is about before it says anything else — Products, Product categories, Articles, Pages, and any custom type the site declares, read from WordPress rather than listed in this plugin — and everything below that follows from it: the field menu is cut to what that type actually has, and the custom-field box offers that type\'s own keys, read from the database, so the shop\'s branding blocks are in the list whatever wrote them. The scan then walks only the types some criterion asks about. There is no second source: what is on the Diagnostic is what is written in that list, in the order it was written. Criteria used to arrive from the prompt library as well — cleverly, and invisibly: they could not be found, edited or explained, and a screen that answers questions nobody asked is a screen nobody trusts. A block written by a prompt is checked the ordinary way, with an ordinary criterion (\'Product · custom field (text)\', key \'_bloc_text_2\', \'is empty\'), and the key box offers the fields this shop actually writes into, so it is picked from a list rather than remembered. Articles and pages are read in full — every published post and page, not the five hundred most recently touched, because the articles this screen is most often asked about are precisely the ones nobody has touched in a year. Thirteen things can be asked of one: its text, its title, its excerpt, its SEO title and description, any custom field, how long ago it was UPDATED and how long ago it was published, its featured image, the images and headings in the text, its comments, and its internal links — and the links are still held to the figure the linking pass works out, asked of the linking pass itself, so this screen and that one can never disagree about the same article. \'Article going stale — last updated more than 365 days ago\' ships switched on. A criterion you would rather not be counted against is unticked in the settings — nothing is deleted, it simply stops being called work. The reading is done once a night in cron and kept: a screen that counted a thousand products on every load is a screen nobody opens twice, and the count on the menu is read from what was stored. "Read the shop again" does it on demand, once, with a lock so two clicks are one reading. Each line gives its count, what share of the shop that is, and the list behind it — where every entry also says what ELSE that product is short of, so a product needing four things is opened once instead of four times. And each line NAMES THE TOOL that mends it and links straight at it — Bulk writing, Image lab, Categories — read from what the criterion looks at, so a criterion invented tomorrow arrives with its tool already attached; a to-do list that says what is wrong and not where to go is a list you read twice. Only what falls short is listed, worst first, grouped by the screen the work is done on; the criteria that found nothing are named under it, folded, so one that quietly stopped matching is still visible. A criterion is NAMED BY WHAT IT DOES and there is no box to call it something else: \'Gallery photographs is less than 3 photographs\' follows the rule as the figure is changed, where a hand-written title stops being true the moment somebody edits the row and says nothing the rule did not already say. What the shop writes instead is the one thing the rule cannot know — a DESCRIPTION of what to do about it (\'Add more photographs to these products, to improve the conversion rate\'), shown under the name on the Diagnostic and beside it on the card. And every criterion says what it is FOR: SEO, CRO, both, or neither. That is not decoration — a shop is never working on being found and on converting in the same month, and a list holding both is a list read twice. The Diagnostic opens on three buttons carrying their own figure — Everything, SEO, CRO — each counting the THINGS waiting for that goal, once each however many criteria they fall short of, so \'CRO 4,831\' is products to open rather than boxes ticked. Choosing one is in the address, so the quarter that is about conversion is a bookmark and not a setting to keep in step; every line carries its goals as tags, so a number is always attributable without opening the criteria. Variations are read too, and by ATTRIBUTE: \'variations with no photograph of their own\' with attribute_pa_couleur in the key box counts, product by product, the colours that are still showing the parent\'s photograph — the exact gap the image lab fills one colour at a time. A variation set to \'any colour\' belongs to no colour in particular and is not counted as one; the key box offers the shop\'s real attributes, read from the variations themselves, so it is picked rather than remembered. It ships switched off, because which attribute matters is the shop\'s answer and not ours. One grouped query for the whole catalogue, so a shop with forty thousand variations pays for one query and not forty thousand.', 'dazont-ecom' ),
			],
			'automation' => [
				'class' => 'DZE_Automation',
				'group' => 'tech',
				'label' => __( 'Automation', 'dazont-ecom' ),
				'desc'  => __( 'Runs the shop\'s own functions on a schedule, a few items a day.', 'dazont-ecom' ),
				'more'  => __( 'Adds no ability of its own: everything it launches is a function already on this site, with its own screen, its own prompt and its own button. What it brings is the part nobody can do by hand at scale — looking at the WHOLE site, seeing where each page falls short of what it should be, and working through the list a little at a time. It judges a page on the very figures that page\'s own panel prints: "1116 words, 0 links — target 750 words and 5 links" is read from the same place the panel reads it, so the screen and the robot can never disagree. Four tasks, each with its own switch, its own rhythm, and — where it writes to the shop — its own choice between saving straight away or waiting under "to review". Internal links on categories: the "Add internal links only" pass on the category the rest of the shop points at LEAST, inbound links counted from what the other descriptions actually contain, among those still under their link target. Internal links in articles and pages: the same pass the other way round, because an article that points at nothing is half a mesh — candidates are the published articles and pages carrying fewer links than their length calls for, and their targets are the product categories their subject actually touches, then the neighbouring articles, then what the sitemap knows; a category always outranks a page of equal closeness, since sending a reader somewhere he can buy is the point. Category descriptions: for a category with none, or one under two thirds of the length its branch deserves — held for review by default, and restricted to categories that have their SEMrush file unless you say otherwise. Marketing calendar: once a month, the commercial moments worth a promotion in the coming quarter, which land in the suggestion list where accepting one creates the event, disabled as always; moments already on the calendar are never proposed twice, and a pile of unanswered suggestions stops it asking for more. Work that writes text is handed to the Writing queue — one job at a time, in the background, under the monthly budget cap. The pace is the point: one item per pass, spread over its period, and never the same page twice within a month unless the pass changed nothing, in which case it comes back in three days. What was saved straight to the shop keeps the text it replaced: the last dozen passes are listed with what they changed, and one click puts any of them back. Adding a task later is adding a row to its list: a label, the module that owns the work, what counts as short, and how it is run.', 'dazont-ecom' ),
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
				'desc'  => __( 'Sends batches off to be written, then holds them for review (Products → Categories AI bulk).', 'dazont-ecom' ),
				'more'  => __( 'A description of two thousand words takes the model a minute or more, and a browser request that waits that long is cut off by the host — the HTTP 504 you would otherwise get. So nothing is written inside the request that asks for it: the selection is queued, a background worker takes one item at a time, and the screen only watches. Leave the page and it carries on (through Action Scheduler, which WooCommerce provides, or WP-Cron); stay on Products → Categories AI bulk, the screen it draws, and the items go by one by one, as WPML does for translations. What comes back waits under "to review": open it, read it against what the category holds today, edit it in the WordPress editor, then accept or discard. Nothing reaches the shop until you accept, unless the batch was sent with immediate saving. Bulk actions on Products → Categories feed it.', 'dazont-ecom' ),
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
				'desc'  => __( 'Watches the outside services this plugin depends on and says when one breaks (Settings → Health).', 'dazont-ecom' ),
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
			<?php if ( self::enabled( 'gmc_activation' ) && $product ) : ?>
				<button type="button" class="button dze-hub-btn" data-modal="dze-gmca-modal"><?php esc_html_e( 'GMC activation', 'dazont-ecom' ); ?></button>
			<?php endif; ?>
			<?php if ( self::enabled( 'translate' ) && class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active() ) : ?>
				<button type="button" class="button dze-hub-btn" data-modal="dze-tr-modal"><?php esc_html_e( 'Translate', 'dazont-ecom' ); ?></button>
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
			$( document ).on( 'click', '#dze-gmca-modal, #dze-tr-modal', function ( e ) {
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
