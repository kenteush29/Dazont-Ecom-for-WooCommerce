<?php
defined( 'ABSPATH' ) || exit;

/**
 * Self-hosted update checker: makes WordPress pull updates for this plugin
 * directly from the GitHub Releases of the repository. No external library.
 *
 * Requirements on the GitHub side (handled by .github/workflows/release.yml):
 *   - Each release is tagged "vX.Y.Z" (e.g. v1.2.0)
 *   - Each release has a ZIP asset whose top-level folder is the plugin slug
 *     (ai-content-suite/…) so WordPress extracts it to the right directory.
 */
final class AICS_Github_Updater {

	private const OWNER     = 'kenteush29';
	private const REPO      = 'AI-suite-for-Woocommerce';
	private const CACHE_KEY = 'aics_gh_latest_release';
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	private string $plugin_file;   // absolute path to main plugin file
	private string $basename;      // ai-content-suite/ai-content-suite.php
	private string $slug;          // ai-content-suite
	private string $version;       // current installed version

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->plugin_file = AICS_FILE;
		$this->basename    = plugin_basename( AICS_FILE );
		$this->slug        = dirname( $this->basename );
		$this->version     = AICS_VERSION;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_details' ], 10, 3 );
		// Clear our cache right after any update completes.
		add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ], 10, 0 );
	}

	// -------------------------------------------------------------------------
	// Update injection
	// -------------------------------------------------------------------------

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		// WordPress calls this with an empty `checked` list sometimes; guard it.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version = $release['version'];
		if ( version_compare( $remote_version, $this->version, '>' ) && $release['zip_url'] ) {
			$transient->response[ $this->basename ] = (object) [
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $remote_version,
				'url'         => $release['html_url'],
				'package'     => $release['zip_url'],
				'tested'      => get_bloginfo( 'version' ),
			];
		} else {
			// Ensure it shows up as "no update" cleanly.
			$transient->no_update[ $this->basename ] = (object) [
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $this->version,
				'url'         => $release['html_url'] ?? '',
				'package'     => '',
			];
		}

		return $transient;
	}

	// -------------------------------------------------------------------------
	// "View details" popup
	// -------------------------------------------------------------------------

	public function plugin_details( $result, string $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'AI Content Suite for WooCommerce',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/' . self::OWNER . '">' . esc_html( self::OWNER ) . '</a>',
			'homepage'      => $release['html_url'],
			'download_link' => $release['zip_url'],
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'tested'        => get_bloginfo( 'version' ),
			'last_updated'  => $release['published_at'],
			'sections'      => [
				'description' => 'Generates product content via Claude API with ACF + WPML support.',
				'changelog'   => $release['body'] ? wpautop( wp_kses_post( $release['body'] ) ) : 'See GitHub releases.',
			],
		];
	}

	// -------------------------------------------------------------------------
	// GitHub API
	// -------------------------------------------------------------------------

	/**
	 * Returns normalised latest-release info, cached in a transient.
	 * [ version, zip_url, html_url, published_at, body ] or null.
	 */
	private function get_latest_release(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url      = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', self::OWNER, self::REPO );
		$response = wp_remote_get( $url, [
			'timeout' => 15,
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'AI-Content-Suite-Updater',
			],
		] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			// Cache the failure briefly to avoid hammering the API.
			set_site_transient( self::CACHE_KEY, [ 'version' => $this->version, 'zip_url' => '', 'html_url' => '', 'published_at' => '', 'body' => '' ], 30 * MINUTE_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}

		// Find the plugin ZIP asset (skip GitHub's auto source zipball).
		$zip_url = '';
		foreach ( $data['assets'] ?? [] as $asset ) {
			if ( ! empty( $asset['browser_download_url'] ) && substr( $asset['name'], -4 ) === '.zip' ) {
				$zip_url = $asset['browser_download_url'];
				break;
			}
		}

		$info = [
			'version'      => ltrim( $data['tag_name'], 'vV' ),
			'zip_url'      => $zip_url,
			'html_url'     => $data['html_url'] ?? '',
			'published_at' => $data['published_at'] ?? '',
			'body'         => $data['body'] ?? '',
		];

		set_site_transient( self::CACHE_KEY, $info, self::CACHE_TTL );
		return $info;
	}

	public function clear_cache(): void {
		delete_site_transient( self::CACHE_KEY );
	}
}
