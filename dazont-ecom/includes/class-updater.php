<?php
defined( 'ABSPATH' ) || exit;

/**
 * Self-hosted update checker: pulls updates for this plugin directly from the
 * GitHub Releases of the repository. Public repo — no token required.
 *
 * Each release must carry a ZIP asset whose name contains the plugin slug and
 * whose top-level folder is the plugin slug, so WordPress installs it to the
 * correct directory. Handled by .github/workflows/release-dazont.yml.
 */
final class DZE_Updater {

	private const OWNER     = 'kenteush29';
	private const REPO      = 'Dazont-Ecom-for-WooCommerce';
	private const CACHE_KEY = 'dze_gh_latest_release';
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS; // short, so new releases surface the same day.

	private string $basename; // dazont-ecom/dazont-ecom.php
	private string $slug;     // dazont-ecom
	private string $version;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->basename = plugin_basename( DZE_FILE );
		$this->slug     = dirname( $this->basename );
		$this->version  = DZE_VERSION;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_details' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ], 10, 0 );
		// In-page "Check for updates" + dev-channel toggle on the Plugins row.
		add_filter( 'plugin_action_links_' . $this->basename, [ $this, 'action_links' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_check_script' ] );
		add_action( 'wp_ajax_dze_check_updates', [ $this, 'ajax_check' ] );
		add_action( 'wp_ajax_dze_dev_channel',  [ $this, 'ajax_dev_channel' ] );
	}

	/** True when the channel is forced by the constant (UI toggle then disabled). */
	private function channel_locked(): bool {
		return defined( 'DZE_DEV_CHANNEL' );
	}

	/** Delete the cached GitHub lookup so the next check re-fetches. */
	public static function flush(): void {
		delete_site_transient( self::CACHE_KEY );
		delete_site_transient( self::CACHE_KEY . '_dev' );
	}

	/**
	 * Dev channel opt-in: when on, the updater also offers GitHub pre-releases
	 * (the development builds). Enable on a TEST site only, via the
	 * DZE_DEV_CHANNEL constant in wp-config.php or the dze_dev_channel option.
	 * Production sites leave it off and only ever see stable releases.
	 */
	private function dev_channel(): bool {
		if ( defined( 'DZE_DEV_CHANNEL' ) ) {
			return (bool) DZE_DEV_CHANNEL;
		}
		return (bool) get_option( 'dze_dev_channel', false );
	}

	/** Cache key kept separate per channel so switching doesn't serve a stale result. */
	private function cache_key(): string {
		return self::CACHE_KEY . ( $this->dev_channel() ? '_dev' : '' );
	}

	public function action_links( array $links ): array {
		$links['dze_check'] = '<a href="#" class="dze-check-updates">' . esc_html__( 'Check for updates', 'dazont-ecom' ) . '</a>'
			. ' <span class="dze-check-result" role="status" aria-live="polite"></span>';

		// Dev-channel toggle (receive development pre-releases). Hidden when the
		// DZE_DEV_CHANNEL constant forces the choice.
		if ( ! $this->channel_locked() ) {
			$on = $this->dev_channel();
			$links['dze_dev'] = '<a href="#" class="dze-dev-toggle" data-on="' . ( $on ? '1' : '0' ) . '">'
				. esc_html__( 'Dev updates:', 'dazont-ecom' ) . ' <strong class="dze-dev-state">'
				. ( $on ? esc_html__( 'On', 'dazont-ecom' ) : esc_html__( 'Off', 'dazont-ecom' ) ) . '</strong></a>';
		}
		return $links;
	}

	/** Toggle the dev channel option (no wp-config edit needed). */
	public function ajax_dev_channel(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		check_ajax_referer( 'dze_check_updates', 'nonce' );
		if ( $this->channel_locked() ) {
			wp_send_json_error( [ 'message' => __( 'The channel is set by the DZE_DEV_CHANNEL constant.', 'dazont-ecom' ) ] );
		}
		$on = ! empty( $_POST['on'] );
		update_option( 'dze_dev_channel', $on ? 1 : 0, false );
		self::flush();
		delete_site_transient( 'update_plugins' );
		wp_send_json_success( [ 'on' => $on ] );
	}

	/** Inline the small checker script on the Plugins screen only. */
	public function enqueue_check_script( string $hook ): void {
		if ( 'plugins.php' !== $hook || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$data = [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'dze_check_updates' ),
			'i18n'    => [
				'checking' => __( 'Checking…', 'dazont-ecom' ),
				'error'    => __( 'Check failed — try again.', 'dazont-ecom' ),
				'on'       => __( 'On', 'dazont-ecom' ),
				'off'      => __( 'Off', 'dazont-ecom' ),
				'devConfirm' => __( 'Receive development builds on this site? Use on a test site only.', 'dazont-ecom' ),
			],
		];
		$js = 'window.dzeUpd=' . wp_json_encode( $data ) . ';'
			. '(function(){document.addEventListener("click",function(e){'
			. 'var a=e.target.closest(".dze-check-updates");'
			. 'if(a){e.preventDefault();'
			. 'var out=a.parentNode.querySelector(".dze-check-result");if(a.dataset.busy)return;a.dataset.busy="1";'
			. 'out.textContent=" "+dzeUpd.i18n.checking;out.style.color="#646970";'
			. 'var f=new FormData();f.append("action","dze_check_updates");f.append("nonce",dzeUpd.nonce);'
			. 'fetch(dzeUpd.ajaxUrl,{method:"POST",credentials:"same-origin",body:f}).then(function(r){return r.json();}).then(function(res){'
			. 'a.dataset.busy="";'
			. 'if(res&&res.success){out.innerHTML=" "+res.data.html;out.style.color=res.data.update?"#b32d2e":"#00794b";}'
			. 'else{out.textContent=" "+((res&&res.data&&res.data.message)||dzeUpd.i18n.error);out.style.color="#b32d2e";}'
			. '}).catch(function(){a.dataset.busy="";out.textContent=" "+dzeUpd.i18n.error;out.style.color="#b32d2e";});'
			. 'return;}'
			. 'var t=e.target.closest(".dze-dev-toggle");'
			. 'if(t){e.preventDefault();if(t.dataset.busy)return;'
			. 'var want=t.dataset.on==="1"?0:1;'
			. 'if(want&&!window.confirm(dzeUpd.i18n.devConfirm))return;'
			. 't.dataset.busy="1";'
			. 'var f2=new FormData();f2.append("action","dze_dev_channel");f2.append("nonce",dzeUpd.nonce);f2.append("on",want);'
			. 'fetch(dzeUpd.ajaxUrl,{method:"POST",credentials:"same-origin",body:f2}).then(function(r){return r.json();}).then(function(res){'
			. 't.dataset.busy="";'
			. 'if(res&&res.success){t.dataset.on=res.data.on?"1":"0";var s=t.querySelector(".dze-dev-state");if(s)s.textContent=res.data.on?dzeUpd.i18n.on:dzeUpd.i18n.off;}'
			. '}).catch(function(){t.dataset.busy="";});'
			. 'return;}'
			. '});})();';
		wp_register_script( 'dze-updater-check', false, [], DZE_VERSION, true );
		wp_enqueue_script( 'dze-updater-check' );
		wp_add_inline_script( 'dze-updater-check', $js );
	}

	/** Fresh GitHub check, in place. Returns a small HTML status; never navigates. */
	public function ajax_check(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		check_ajax_referer( 'dze_check_updates', 'nonce' );
		self::flush();
		// Also let WordPress re-evaluate, so the native "update now" row appears on reload.
		delete_site_transient( 'update_plugins' );
		$release = $this->get_latest_release();
		if ( ! $release || empty( $release['version'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not reach GitHub. Try again in a moment.', 'dazont-ecom' ) ] );
		}
		$update = version_compare( $release['version'], $this->version, '>' );
		if ( $update ) {
			$link = ' <a href="' . esc_url( self_admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'reload to update', 'dazont-ecom' ) . '</a>';
			/* translators: %s: new version number */
			$html = '⬆ ' . sprintf( esc_html__( 'Version %s available.', 'dazont-ecom' ), esc_html( $release['version'] ) ) . $link;
		} else {
			/* translators: %s: current version number */
			$html = '✓ ' . sprintf( esc_html__( 'Up to date (%s).', 'dazont-ecom' ), esc_html( $this->version ) );
		}
		wp_send_json_success( [ 'update' => $update, 'version' => $release['version'], 'html' => $html ] );
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		if ( version_compare( $release['version'], $this->version, '>' ) && $release['zip_url'] ) {
			$transient->response[ $this->basename ] = (object) [
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $release['version'],
				'url'         => $release['html_url'],
				'package'     => $release['zip_url'],
				'tested'      => get_bloginfo( 'version' ),
			];
		} else {
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

	public function plugin_details( $result, string $action, $args ) {
		if ( $action !== 'plugin_information' || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'Dazont Ecom',
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
				'description' => 'Restock backlog for WooCommerce: out-of-stock product-lines ranked by total sales.',
				'changelog'   => $release['body'] ? wpautop( wp_kses_post( $release['body'] ) ) : 'See GitHub releases.',
			],
		];
	}

	private function get_latest_release(): ?array {
		$cache_key = $this->cache_key();
		$cached    = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url      = sprintf( 'https://api.github.com/repos/%s/%s/releases', self::OWNER, self::REPO );
		$response = wp_remote_get( $url, [
			'timeout' => 15,
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'Dazont-Ecom-Updater',
			],
		] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_site_transient( $cache_key, [ 'version' => $this->version, 'zip_url' => '', 'html_url' => '', 'published_at' => '', 'body' => '' ], 30 * MINUTE_IN_SECONDS );
			return null;
		}

		$releases = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $releases ) ) {
			return null;
		}

		// Pick the release with the highest SEMANTIC version that ships an asset
		// for THIS plugin (asset name contains the slug). We must compare versions
		// ourselves: GitHub's /releases list is ordered by tag name, so "3.14.9"
		// sorts above "3.14.10" — taking the first item would miss newer releases.
		// Pre-releases are the dev channel: ignored unless this site opted in, so
		// production sites only ever see stable releases.
		$dev  = $this->dev_channel();
		$best = null;
		foreach ( $releases as $rel ) {
			if ( ! empty( $rel['draft'] ) || empty( $rel['tag_name'] ) ) {
				continue;
			}
			$is_pre = ! empty( $rel['prerelease'] );
			if ( $is_pre && ! $dev ) {
				continue;
			}
			$zip_url = '';
			foreach ( $rel['assets'] ?? [] as $asset ) {
				$name = $asset['name'] ?? '';
				if ( strpos( $name, $this->slug ) !== false && substr( $name, -4 ) === '.zip' ) {
					$zip_url = $asset['browser_download_url'] ?? '';
					break;
				}
			}
			if ( ! $zip_url ) {
				continue;
			}

			// Extract a semver from tags like "restock-v1.2.3" or "v1.2.3".
			if ( ! preg_match( '/(\d+\.\d+(?:\.\d+)?)/', (string) $rel['tag_name'], $m ) ) {
				continue;
			}

			$info = [
				'version'      => $m[1],
				'zip_url'      => $zip_url,
				'html_url'     => $rel['html_url'] ?? '',
				'published_at' => $rel['published_at'] ?? '',
				'body'         => $rel['body'] ?? '',
				'prerelease'   => $is_pre,
			];
			// Highest version wins; on an exact tie a stable release beats a
			// pre-release of the same version.
			if ( null === $best ) {
				$best = $info;
			} else {
				$cmp = version_compare( $info['version'], $best['version'] );
				if ( $cmp > 0 || ( 0 === $cmp && $best['prerelease'] && ! $is_pre ) ) {
					$best = $info;
				}
			}
		}

		if ( $best ) {
			set_site_transient( $cache_key, $best, self::CACHE_TTL );
			return $best;
		}

		return null;
	}

	public function clear_cache(): void {
		self::flush();
	}
}
