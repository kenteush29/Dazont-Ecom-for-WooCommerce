<?php
/**
 * Plugin Name:       AI Content Suite for WooCommerce
 * Plugin URI:        https://github.com/kenteush29/ai-suite-for-woocommerce
 * Description:       Generates product content via Claude API with ACF + WPML support.
 * Version:           1.2.3
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            AI Content Suite
 * License:           GPL-2.0-or-later
 * Text Domain:       ai-content-suite
 * Domain Path:       /languages
 * Update URI:        https://github.com/kenteush29/AI-suite-for-Woocommerce
 */

defined( 'ABSPATH' ) || exit;

// Constants.
define( 'AICS_VERSION',   '1.2.3' );
define( 'AICS_FILE',      __FILE__ );
define( 'AICS_DIR',       plugin_dir_path( __FILE__ ) );
define( 'AICS_URL',       plugin_dir_url( __FILE__ ) );
define( 'AICS_SLUG',      'ai-content-suite' );

// Autoloader.
spl_autoload_register( function ( string $class ): void {
	if ( strpos( $class, 'AICS_' ) !== 0 ) {
		return;
	}
	$file = AICS_DIR . 'includes/class-' . strtolower( str_replace( [ 'AICS_', '_' ], [ '', '-' ], $class ) ) . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Activation / deactivation hooks.
register_activation_hook( __FILE__, [ 'AICS_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'AICS_Plugin', 'deactivate' ] );

/**
 * Core bootstrap class.
 */
final class AICS_Plugin {

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
		// Update checker runs regardless of WooCommerce so updates always work.
		if ( is_admin() ) {
			AICS_Github_Updater::instance();
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ $this, 'notice_woo_missing' ] );
			return;
		}

		load_plugin_textdomain( 'ai-content-suite', false, dirname( plugin_basename( AICS_FILE ) ) . '/languages' );

		AICS_Settings::instance();
		AICS_Field_Mapper::instance();
		AICS_Logger::instance();
		AICS_Product_Metabox::instance();
		AICS_Product_Actions::instance();
		AICS_Restock::instance();
	}

	public static function activate(): void {
		// Nothing to set up on activation for now.
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		if ( class_exists( 'AICS_Restock' ) ) {
			AICS_Restock::clear_cron();
		}
		flush_rewrite_rules();
	}

	public function notice_woo_missing(): void {
		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'AI Content Suite requires WooCommerce to be active.', 'ai-content-suite' ) .
			'</p></div>';
	}
}

AICS_Plugin::instance();
