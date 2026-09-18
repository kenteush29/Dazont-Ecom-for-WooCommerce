<?php
defined( 'ABSPATH' ) || exit;

/**
 * THE ONE PLACE THAT PUTS THIS PLUGIN'S ADMIN STYLESHEET ON A SCREEN.
 *
 * `content.css` was enqueued from FIFTEEN files, fourteen of them hanging
 * `DZE_VERSION` on it. WordPress registers a handle once: whichever screen
 * asked first decided the address for the whole request, and the fifteenth
 * caller's version was thrown away without a word.
 *
 * That is not a tidiness problem. The server sends this file with
 * `Cache-Control: max-age=31557600` — a year — so an address that does not
 * change is a stylesheet that never changes either. An afternoon was spent
 * looking at a screen built by new markup and painted by a stylesheet from the
 * day before, and every server-side check said the file was correct, because
 * it was.
 *
 * One function, one version, and the version is the file's own modification
 * time: the address changes when the contents do, which is the only thing a
 * cache can act on.
 */
final class DZE_Assets {

	/** The shared admin stylesheet's handle. */
	public const CSS = 'dze-content';

	/**
	 * The version to hang on one of this plugin's files.
	 *
	 * Falls back to the plugin's version when the file cannot be read, which
	 * is no worse than what every call site did before.
	 *
	 * @param string $rel Path inside the plugin, e.g. 'admin/css/content.css'.
	 */
	public static function ver( string $rel ): string {
		$path = defined( 'DZE_DIR' ) ? DZE_DIR . ltrim( $rel, '/' ) : '';
		$when = ( '' !== $path && file_exists( $path ) ) ? (int) filemtime( $path ) : 0;
		return $when ? DZE_VERSION . '.' . $when : DZE_VERSION;
	}

	/** Puts the shared admin stylesheet on the screen being drawn. */
	public static function admin_css(): void {
		wp_enqueue_style( self::CSS, DZE_URL . 'admin/css/content.css', [], self::ver( 'admin/css/content.css' ) );
	}

	/**
	 * A script of this plugin's own, versioned the same way.
	 *
	 * @param string   $handle Script handle.
	 * @param string   $rel    Path inside the plugin.
	 * @param string[] $deps   What it needs first.
	 */
	public static function admin_js( string $handle, string $rel, array $deps = [ 'jquery' ] ): void {
		wp_enqueue_script( $handle, DZE_URL . ltrim( $rel, '/' ), $deps, self::ver( $rel ), true );
	}
}
