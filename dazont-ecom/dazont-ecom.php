<?php
/**
 * Plugin Name:       Dazont Ecom
 * Plugin URI:        https://github.com/kenteush29/Dazont-Ecom-for-WooCommerce
 * Description:       Dazont Ecom toolkit for WooCommerce. Modules: Restock (out-of-stock backlog), Trending Products (best-sellers shortcode), Marketing Events (scheduled sales, banners, AI-generated calendar, GMC sync), Discounts (evergreen bulk cart coupons + automatic product discounts), Product Explorer (full-screen catalogue browser), AI Product Images (Google Gemini image generation on the product page) and the AI Marketing Assistant. More modules coming.
 * Version:           3.21.6
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Dazont
 * License:           GPL-2.0-or-later
 * Text Domain:       dazont-ecom
 * Update URI:        https://github.com/kenteush29/Dazont-Ecom-for-WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'DZE_VERSION', '3.21.6' );
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

		DZE_Restock::instance();
		DZE_Dashboard::instance();
		DZE_Trending::instance();
		DZE_Discounts::instance();
		DZE_Gmc::instance();
		DZE_Marketing_Ai::instance();
		DZE_Explorer::instance();
		DZE_Keywords::instance();
		DZE_Product_Images::instance();
		DZE_Content::instance();
		DZE_Variation_Split::instance();
		DZE_Api_Keys::init();
		// Kept in reserve for later, not loaded:
		//   DZE_Fbt (Frequently Bought Together) — includes/class-fbt.php
		// The Gallery module was replaced by the Product Explorer.
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
	}

	public function notice_woo_missing(): void {
		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'Dazont Ecom requires WooCommerce to be active.', 'dazont-ecom' ) .
			'</p></div>';
	}
}

DZE_Plugin::instance();
