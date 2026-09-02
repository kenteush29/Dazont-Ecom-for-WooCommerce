<?php
/**
 * Plugin Name:       Dazont Ecom
 * Plugin URI:        https://github.com/kenteush29/Dazont-Ecom-for-WooCommerce
 * Description:       Dazont Ecom toolkit for WooCommerce. Modules (each switchable under Settings → Modules): Restock, Trending Products, Discounts & Marketing events, Google Merchant Center promotions, Marketing Assistant, Sourcing Assistant, Product Content, POD image, Variation Split, Dashboard.
 * Version:           4.281.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Dazont
 * License:           GPL-2.0-or-later
 * Text Domain:       dazont-ecom
 * Update URI:        https://github.com/kenteush29/Dazont-Ecom-for-WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'DZE_VERSION', '4.281.0' );
define( 'DZE_FILE',    __FILE__ );
define( 'DZE_DIR',     plugin_dir_path( __FILE__ ) );
define( 'DZE_URL',     plugin_dir_url( __FILE__ ) );

// Autoloader: DZE_Class_Name → includes/class-class-name.php
spl_autoload_register( function ( string $class ): void {
	if ( strpos( $class, 'DZE_' ) !== 0 ) {
		return;
	}
	$file = DZE_DIR . 'includes/class-' . strtolower( str_replace( [ 'DZE_', '_' ], [ '', '-' ], $class ) ) . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

register_activation_hook( __FILE__, [ 'DZE_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'DZE_Plugin', 'deactivate' ] );

final class DZE_Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init' ] );
	}

	public function init(): void {
		load_plugin_textdomain( 'dazont-ecom', false, dirname( plugin_basename( DZE_FILE ) ) . '/languages' );

		// Update checker runs in admin regardless of WooCommerce so updates always work.
		if ( is_admin() ) {
			DZE_Updater::instance();
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ $this, 'notice_woo_missing' ] );
			return;
		}

		// The module manager boots every ENABLED module (Dazont Ecom → Modules).
		// Always on: the manager itself, the updater and the API-key helper.
		DZE_Modules::instance();
		DZE_Modules::boot();
		DZE_Api_Keys::init();
		// Always on, and never a module: a staging copy of the shop must not
		// be able to write into the real Klaviyo account or the real Merchant
		// Center, and a protection somebody can switch off is not one.
		DZE_Site::init();
		DZE_Price::init(); // charm rounding, shared by Discounts and Product Content.
		if ( is_admin() ) {
			DZE_Prompts::init();    // "see the prompt" buttons; admin-only by nature.
			DZE_Prompt_Defaults::init(); // "make this the default", beside every prompt.
			DZE_Shortcodes::init(); // one screen documenting every shortcode published.
			self::trim_autoload();
		}
		// Kept in reserve for later, not loaded:
		//   DZE_Fbt (Frequently Bought Together) — includes/class-fbt.php
		// The Gallery module was replaced by the Product Explorer.
	}

	/**
	 * Settings rows written before they were declared non-autoloading are still
	 * flagged as such in the database, so WordPress loads them — prompts and
	 * all — on every single request, shop pages included. This flips the flag
	 * once. Only settings no shop page ever reads are listed.
	 */
	private static function trim_autoload(): void {
		// The version is the flag: adding an option to the list below has to
		// run the pass again on shops where it already ran once.
		if ( '2' === (string) get_option( 'dze_autoload_trimmed', '' ) ) {
			return;
		}
		$options = [
			'dze_catcontent_settings',
			'dze_content_settings',
			'dze_pod_settings',
			'dze_reviews_settings',
			'dze_gmc_accounts',
			'dze_gmc_credentials',
			// Carries the email frame — a whole email's worth of HTML.
			'dze_klaviyo',
			'dze_klaviyo_copy',
			'dze_klaviyo_drafts',
		];
		foreach ( $options as $name ) {
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( $name, false );
			}
		}
		update_option( 'dze_autoload_trimmed', '2', true );
	}

	public static function activate(): void {
		// Schedule the weekly sales recalculation.
		if ( ! wp_next_scheduled( DZE_Restock::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', DZE_Restock::CRON_HOOK );
		}
		// Drop any stale update cache so a freshly (re)activated copy re-checks GitHub.
		if ( class_exists( 'DZE_Updater' ) ) {
			DZE_Updater::flush();
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( DZE_Restock::CRON_HOOK );
		if ( class_exists( 'DZE_Gmc' ) ) {
			DZE_Gmc::clear_cron();
		}
		if ( class_exists( 'DZE_Discounts' ) ) {
			DZE_Discounts::clear_sale_sync();
		}
		wp_clear_scheduled_hook( DZE_Automation::HOOK );
		if ( class_exists( 'DZE_Health' ) ) {
			DZE_Health::clear_cron();
		}
	}

	public function notice_woo_missing(): void {
		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'Dazont Ecom requires WooCommerce to be active.', 'dazont-ecom' ) .
			'</p></div>';
	}
}

DZE_Plugin::instance();
