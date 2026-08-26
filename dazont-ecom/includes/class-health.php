<?php
defined( 'ABSPATH' ) || exit;

/**
 * Health — the plugin watches its own connections and says when one breaks.
 *
 * Everything this plugin does that can fail happens against somebody else's
 * service: Anthropic writes the texts, fal.ai the images, Klaviyo the
 * campaigns, Google the promotions. Those services change — an API revision
 * is retired, a key is rotated, a Cloud project loses a permission — and the
 * shop finds out weeks later, when a promotion did not go out.
 *
 * So two things run here. A LOG: every failed call to one of those services
 * records what it was doing, what came back, and when — bounded, so it can
 * never grow into a problem of its own. And a weekly CHECKUP: each connection
 * is asked one cheap question, and the answer is kept next to the last one. A
 * connection that was fine last week and is not today sends one email and
 * raises one notice, once — not every week for the same thing.
 *
 * What it does NOT do, and cannot: rewrite the plugin to match a provider's
 * new API. No plugin can. What it does instead is name the failure precisely
 * — the endpoint, the status, the provider's own words — and check whether a
 * newer release of this plugin is already waiting, which is where such a fix
 * would come from.
 *
 * Footprint: one weekly cron, one bounded option, one admin screen. Nothing on
 * the front end, and no call to anybody outside the cron or an explicit click.
 */
final class DZE_Health {

	public const OPT_LOG   = 'dze_health_log';   // the last failures, newest first.
	public const OPT_STATE = 'dze_health_state'; // what the last checkup found.
	public const CRON      = 'dze_health_check';

	private const NONCE = 'dze_health';
	private const KEEP  = 60; // entries. Enough to see a pattern, small enough to stay cheap.

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// The cron hook is registered everywhere — WP-Cron fires it from an
		// ordinary front request — but nothing else touches the front: even
		// the scheduling is done from the admin, where somebody is already
		// paying for the page.
		add_action( self::CRON, [ __CLASS__, 'run' ] );
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init', [ $this, 'schedule' ] );
		add_action( 'admin_notices', [ $this, 'notice' ] );
		add_action( 'wp_ajax_dze_health_run',   [ __CLASS__, 'ajax_run' ] );
		add_action( 'wp_ajax_dze_health_clear', [ __CLASS__, 'ajax_clear' ] );
		add_action( 'wp_ajax_dze_health_auto',  [ __CLASS__, 'ajax_auto' ] );
	}

	// =========================================================================
	// Installing the fix by itself
	//
	// When a provider changes something, the fix arrives as a release. Letting
	// the shop install it on its own is WordPress's own job — the same switch
	// the Plugins screen shows — so it is that switch that is flipped here, and
	// not a second updater of our own running beside it.
	// =========================================================================

	private static function basename(): string {
		return plugin_basename( DZE_FILE );
	}

	public static function auto_update_on(): bool {
		$list = get_site_option( 'auto_update_plugins', [] );
		return is_array( $list ) && in_array( self::basename(), $list, true );
	}

	private static function set_auto_update( bool $on ): void {
		$list = get_site_option( 'auto_update_plugins', [] );
		$list = is_array( $list ) ? $list : [];
		$me   = self::basename();
		$list = array_values( array_diff( $list, [ $me ] ) );
		if ( $on ) {
			$list[] = $me;
		}
		update_site_option( 'auto_update_plugins', $list );
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			// Not on the hour a hundred other things run: the day after
			// activation, at a quiet time.
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON );
		}
	}

	public static function clear_cron(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	// =========================================================================
	// The log
	// =========================================================================

	/**
	 * Records one failed call to somebody else's service.
	 *
	 * Called from the failure paths themselves, never from a success path: a
	 * shop that works writes nothing here, and the option is only touched when
	 * something actually went wrong.
	 *
	 * @param string $where   Which connection: 'klaviyo', 'gmc', 'anthropic', 'fal'.
	 * @param string $doing   What the plugin was doing, in plain words.
	 * @param string $said    What came back — the status and the provider's message.
	 */
	public static function log( string $where, string $doing, string $said ): void {
		// Called from other modules' failure paths, which know nothing about
		// this one. Switched off, it writes nothing at all — a disabled module
		// leaves no trace, not even a row in an option nobody can see.
		if ( class_exists( 'DZE_Modules' ) && ! DZE_Modules::enabled( 'health' ) ) {
			return;
		}
		$where = sanitize_key( $where );
		$said  = trim( wp_strip_all_tags( $said ) );
		if ( '' === $where || '' === $said ) {
			return;
		}
		$all = get_option( self::OPT_LOG, [] );
		$all = is_array( $all ) ? $all : [];

		// The same failure repeating is one line with a count, not fifty lines:
		// a page reloaded ten times must not push the rest of the week out.
		$first = $all[0] ?? null;
		if ( is_array( $first ) && ( $first['where'] ?? '' ) === $where && ( $first['said'] ?? '' ) === $said ) {
			$all[0]['count'] = (int) ( $first['count'] ?? 1 ) + 1;
			$all[0]['at']    = time();
		} else {
			array_unshift( $all, [
				'at'    => time(),
				'where' => $where,
				'doing' => mb_substr( trim( wp_strip_all_tags( $doing ) ), 0, 120 ),
				'said'  => mb_substr( $said, 0, 300 ),
				'count' => 1,
			] );
		}
		update_option( self::OPT_LOG, array_slice( $all, 0, self::KEEP ), false );
	}

	/** @return array<int,array> newest first. */
	public static function entries(): array {
		$all = get_option( self::OPT_LOG, [] );
		return is_array( $all ) ? $all : [];
	}

	public static function state(): array {
		$s = get_option( self::OPT_STATE, [] );
		return is_array( $s ) ? $s : [];
	}

	// =========================================================================
	// The checkup
	// =========================================================================

	/** Human labels for the connections that are checked. */
	public static function labels(): array {
		return [
			'anthropic' => __( 'Anthropic (the writing)', 'dazont-ecom' ),
			'fal'       => __( 'fal.ai (the images)', 'dazont-ecom' ),
			'klaviyo'   => __( 'Klaviyo (the campaigns)', 'dazont-ecom' ),
			'gmc'       => __( 'Google Merchant Center', 'dazont-ecom' ),
			'analytics' => __( 'WooCommerce analytics', 'dazont-ecom' ),
			'jobs'      => __( 'Scheduled work', 'dazont-ecom' ),
			'plugin'    => __( 'This plugin', 'dazont-ecom' ),
		];
	}

	/**
	 * Asks every connection one cheap question.
	 *
	 * @return array<string,array{state:string,message:string}> state: ok|warn|down|off
	 */
	public static function run(): array {
		$now  = time();
		$was  = self::state();
		$out  = [];

		foreach ( array_keys( self::labels() ) as $id ) {
			$method = 'check_' . $id;
			try {
				$out[ $id ] = self::$method();
			} catch ( \Throwable $e ) {
				$out[ $id ] = [ 'state' => 'down', 'message' => $e->getMessage() ];
			}
		}

		// A connection that has just broken is worth an email; the same one
		// still broken next week is not — it was already said.
		$broke = [];
		foreach ( $out as $id => $one ) {
			$before = (string) ( $was['checks'][ $id ]['state'] ?? '' );
			if ( 'down' === $one['state'] && 'down' !== $before ) {
				$broke[ $id ] = $one['message'];
			}
		}
		update_option( self::OPT_STATE, [ 'at' => $now, 'checks' => $out ], false );
		if ( $broke ) {
			self::tell( $broke );
		}
		return $out;
	}

	/** One email when something that worked stops working. */
	private static function tell( array $broke ): void {
		$to = (string) get_option( 'admin_email', '' );
		if ( '' === $to ) {
			return;
		}
		$labels = self::labels();
		$lines  = [];
		foreach ( $broke as $id => $message ) {
			$lines[] = '• ' . ( $labels[ $id ] ?? $id ) . ' — ' . $message;
		}
		$body = __( 'The weekly checkup found a connection that was working and is not any more:', 'dazont-ecom' ) . "\n\n"
			. implode( "\n", $lines ) . "\n\n"
			. __( 'Nothing has been changed automatically. What broke is named above as the service itself put it.', 'dazont-ecom' ) . "\n"
			. admin_url( 'admin.php?page=' . ( class_exists( 'DZE_Marketing_Ai' ) ? DZE_Marketing_Ai::MENU_SLUG : 'dazont-ecom' ) . '&tab=health' ) . "\n";
		wp_mail(
			$to,
			sprintf(
				/* translators: %s: shop name */
				__( '[%s] A connection stopped working', 'dazont-ecom' ),
				get_bloginfo( 'name' )
			),
			$body
		);
	}

	private static function check_anthropic(): array {
		$key = class_exists( 'DZE_Marketing_Ai' ) ? DZE_Marketing_Ai::api_key() : '';
		if ( '' === $key ) {
			return [ 'state' => 'off', 'message' => __( 'No key saved.', 'dazont-ecom' ) ];
		}
		$resp = wp_remote_get( 'https://api.anthropic.com/v1/models?limit=1', [
			'timeout' => 15,
			'headers' => [ 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ],
		] );
		return self::verdict( $resp, [ 200 ], __( 'The model list answers.', 'dazont-ecom' ) );
	}

	private static function check_fal(): array {
		$key = class_exists( 'DZE_Content' ) ? DZE_Content::fal_key() : '';
		if ( '' === $key ) {
			return [ 'state' => 'off', 'message' => __( 'No key saved.', 'dazont-ecom' ) ];
		}
		// An empty payload: a valid key gets a validation error and nothing is
		// generated or billed; a dead one gets 401/403.
		$resp = wp_remote_post( 'https://fal.run/fal-ai/nano-banana-2/edit', [
			'timeout' => 15,
			'headers' => [ 'Authorization' => 'Key ' . $key, 'content-type' => 'application/json' ],
			'body'    => '{}',
		] );
		return self::verdict( $resp, [ 200, 400, 422 ], __( 'The endpoint answers.', 'dazont-ecom' ) );
	}

	private static function check_klaviyo(): array {
		if ( ! class_exists( 'DZE_Klaviyo' ) || '' === DZE_Klaviyo::key() ) {
			return [ 'state' => 'off', 'message' => __( 'No key saved.', 'dazont-ecom' ) ];
		}
		$res = DZE_Klaviyo::request( 'GET', 'accounts/', null, 15 );
		if ( is_wp_error( $res ) ) {
			$said = $res->get_error_message();
			// The revision is the thing that goes stale on its own, so it is
			// named rather than left inside a generic refusal.
			$hint = false !== stripos( $said, 'revision' )
				? ' ' . __( 'Klaviyo is refusing the API revision this plugin pins — that is a plugin update, not a setting.', 'dazont-ecom' )
				: '';
			return [ 'state' => 'down', 'message' => $said . $hint ];
		}
		$name = (string) ( $res['data'][0]['attributes']['contact_information']['organization_name'] ?? '' );
		return [
			'state'   => 'ok',
			'message' => $name !== ''
				/* translators: %s: the Klaviyo account name */
				? sprintf( __( 'Connected to %s.', 'dazont-ecom' ), $name )
				: __( 'The account answers.', 'dazont-ecom' ),
		];
	}

	private static function check_gmc(): array {
		if ( ! class_exists( 'DZE_Gmc' ) || ! DZE_Gmc::instance()->is_configured() ) {
			return [ 'state' => 'off', 'message' => __( 'Not connected.', 'dazont-ecom' ) ];
		}
		$probe = DZE_Gmc::instance()->probe();
		return [
			'state'   => ! empty( $probe['ok'] ) ? 'ok' : 'down',
			'message' => (string) $probe['message'],
		];
	}

	private static function check_analytics(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [ 'state' => 'warn', 'message' => __( 'The WooCommerce analytics table is missing, so best-sellers fall back to catalogue popularity.', 'dazont-ecom' ) ];
		}
		$since = current_datetime()->modify( '-30 days' )->format( 'Y-m-d H:i:s' );
		$rows  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE date_created >= %s", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce's own table name.
		if ( $rows < 1 ) {
			return [ 'state' => 'warn', 'message' => __( 'Nothing recorded in the last 30 days — either a quiet month, or analytics has stopped syncing.', 'dazont-ecom' ) ];
		}
		return [
			'state'   => 'ok',
			/* translators: %s: number of order lines */
			'message' => sprintf( __( '%s order lines in the last 30 days.', 'dazont-ecom' ), number_format_i18n( $rows ) ),
		];
	}

	private static function check_jobs(): array {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ! function_exists( 'as_enqueue_async_action' ) ) {
			return [ 'state' => 'warn', 'message' => __( 'WP-Cron is disabled and Action Scheduler is not available: scheduled work only runs if a real cron calls wp-cron.php.', 'dazont-ecom' ) ];
		}
		$late = 0;
		foreach ( (array) _get_cron_array() as $stamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $_ ) {
				if ( 0 === strpos( (string) $hook, 'dze_' ) && $stamp < time() - 2 * DAY_IN_SECONDS ) {
					$late++;
				}
			}
		}
		if ( $late > 0 ) {
			return [
				'state'   => 'warn',
				/* translators: %d: number of overdue jobs */
				'message' => sprintf( _n( '%d of this plugin\'s jobs is more than two days overdue.', '%d of this plugin\'s jobs are more than two days overdue.', $late, 'dazont-ecom' ), $late ),
			];
		}
		return [ 'state' => 'ok', 'message' => __( 'Scheduled work is running on time.', 'dazont-ecom' ) ];
	}

	private static function check_plugin(): array {
		if ( ! class_exists( 'DZE_Updater' ) ) {
			return [ 'state' => 'off', 'message' => __( 'The updater is not loaded.', 'dazont-ecom' ) ];
		}
		$latest = DZE_Updater::latest_version();
		if ( '' === $latest ) {
			return [ 'state' => 'warn', 'message' => __( 'The release list could not be read.', 'dazont-ecom' ) ];
		}
		if ( version_compare( $latest, DZE_VERSION, '>' ) ) {
			return [
				'state'   => self::auto_update_on() ? 'ok' : 'warn',
				'message' => self::auto_update_on()
					/* translators: 1: available version, 2: installed version */
					? sprintf( __( 'Version %1$s is available and will install itself — this shop runs %2$s.', 'dazont-ecom' ), $latest, DZE_VERSION )
					/* translators: 1: available version, 2: installed version */
					: sprintf( __( 'Version %1$s is available — this shop runs %2$s. If something above is broken, the fix may already be in it.', 'dazont-ecom' ), $latest, DZE_VERSION ),
			];
		}
		return [
			'state'   => 'ok',
			'message' => self::auto_update_on()
				/* translators: %s: version number */
				? sprintf( __( 'Up to date (%s), and updating itself.', 'dazont-ecom' ), DZE_VERSION )
				/* translators: %s: version number */
				: sprintf( __( 'Up to date (%s).', 'dazont-ecom' ), DZE_VERSION ),
		];
	}

	/** Turns an HTTP answer into a verdict, in the service's own words. */
	private static function verdict( $resp, array $ok_codes, string $ok_message ): array {
		if ( is_wp_error( $resp ) ) {
			return [ 'state' => 'down', 'message' => $resp->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( in_array( $code, $ok_codes, true ) ) {
			return [ 'state' => 'ok', 'message' => $ok_message ];
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$said = '';
		if ( is_array( $body ) ) {
			$said = (string) ( $body['error']['message'] ?? ( is_string( $body['detail'] ?? null ) ? $body['detail'] : '' ) );
		}
		return [
			'state'   => 'down',
			'message' => 'HTTP ' . $code . ( '' !== $said ? ' — ' . mb_substr( $said, 0, 200 ) : '' ),
		];
	}

	// =========================================================================
	// Screens
	// =========================================================================

	/** One line at the top of the admin when a connection is down. */
	public function notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$state = self::state();
		$down  = [];
		foreach ( (array) ( $state['checks'] ?? [] ) as $id => $one ) {
			if ( 'down' === ( $one['state'] ?? '' ) ) {
				$down[] = (string) ( self::labels()[ $id ] ?? $id );
			}
		}
		if ( ! $down ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( sprintf(
				/* translators: %s: the connections that are down */
				__( 'Dazont Ecom: %s is not answering.', 'dazont-ecom' ),
				implode( ', ', $down )
			) ),
			esc_url( add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG, 'tab' => 'health' ], admin_url( 'admin.php' ) ) ),
			esc_html__( 'See what it said →', 'dazont-ecom' )
		);
	}

	public static function ajax_run(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		self::run();
		wp_send_json_success( [ 'reload' => true ] );
	}

	public static function ajax_clear(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		delete_option( self::OPT_LOG );
		wp_send_json_success( [ 'reload' => true ] );
	}

	public static function ajax_auto(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		// Installing code is an administrator's decision, not a shop manager's.
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		self::set_auto_update( ! empty( $_POST['on'] ) && 'false' !== (string) $_POST['on'] );
		wp_send_json_success( [ 'on' => self::auto_update_on() ] );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$state  = self::state();
		$labels = self::labels();
		$colors = [ 'ok' => '#0a7040', 'warn' => '#b26a00', 'down' => '#b32d2e', 'off' => '#787c82' ];
		$dots   = [ 'ok' => '●', 'warn' => '▲', 'down' => '✕', 'off' => '○' ];
		$next   = wp_next_scheduled( self::CRON );
		?>
		<p class="description" style="max-width:880px;">
			<?php esc_html_e( 'Everything this plugin does that can fail happens against somebody else\'s service. Once a week each one is asked a cheap question, and every failed call in between is written down. What breaks is named here as the service itself put it — nothing is changed automatically, because no plugin can rewrite itself to match a provider\'s new API.', 'dazont-ecom' ); ?>
		</p>
		<p>
			<button type="button" class="button button-primary" id="dze-health-run"><?php esc_html_e( 'Check now', 'dazont-ecom' ); ?></button>
			<span style="margin-left:10px;color:#646970;font-size:13px;">
				<?php
				if ( ! empty( $state['at'] ) ) {
					printf(
						/* translators: 1: time since the last checkup, 2: time until the next */
						esc_html__( 'Last checked %1$s ago. Next %2$s.', 'dazont-ecom' ),
						esc_html( human_time_diff( (int) $state['at'] ) ),
						$next ? esc_html( 'in ' . human_time_diff( (int) $next ) ) : esc_html__( 'not scheduled', 'dazont-ecom' )
					);
				} else {
					esc_html_e( 'Never checked yet.', 'dazont-ecom' );
				}
				?>
			</span>
			<span id="dze-health-msg" style="margin-left:8px;font-size:13px;"></span>
		</p>

		<?php if ( current_user_can( 'update_plugins' ) ) : ?>
			<p style="margin:-4px 0 18px;">
				<label>
					<input type="checkbox" id="dze-health-auto" <?php checked( self::auto_update_on() ); ?> />
					<?php esc_html_e( 'Install updates of this plugin by itself, as soon as one is published.', 'dazont-ecom' ); ?>
				</label>
				<span class="description" style="display:block;margin-left:24px;">
					<?php esc_html_e( 'When a provider changes something, the fix travels as a release — this is what makes the shop pick it up without waiting for somebody to click. It is WordPress\'s own auto-update switch, the same one the Plugins screen offers; nothing else installs anything here.', 'dazont-ecom' ); ?>
				</span>
			</p>
		<?php endif; ?>

		<table class="widefat striped" style="max-width:980px;">
			<thead><tr>
				<th style="width:240px;"><?php esc_html_e( 'Connection', 'dazont-ecom' ); ?></th>
				<th><?php esc_html_e( 'What it said', 'dazont-ecom' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $labels as $id => $label ) :
				$one   = (array) ( $state['checks'][ $id ] ?? [] );
				$st    = (string) ( $one['state'] ?? '' );
				$color = $colors[ $st ] ?? '#787c82';
				?>
				<tr>
					<td><strong><?php echo esc_html( $label ); ?></strong></td>
					<td>
						<span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600;margin-right:6px;"><?php echo esc_html( $dots[ $st ] ?? '·' ); ?></span>
						<?php echo esc_html( (string) ( $one['message'] ?? __( 'Not checked yet.', 'dazont-ecom' ) ) ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2 class="title" style="margin-top:26px;"><?php esc_html_e( 'What failed since last time', 'dazont-ecom' ); ?></h2>
		<?php $log = self::entries(); ?>
		<?php if ( ! $log ) : ?>
			<p class="description"><?php esc_html_e( 'Nothing. A shop that works writes nothing here.', 'dazont-ecom' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:980px;">
				<thead><tr>
					<th style="width:150px;"><?php esc_html_e( 'When', 'dazont-ecom' ); ?></th>
					<th style="width:150px;"><?php esc_html_e( 'Where', 'dazont-ecom' ); ?></th>
					<th style="width:220px;"><?php esc_html_e( 'Doing', 'dazont-ecom' ); ?></th>
					<th><?php esc_html_e( 'What came back', 'dazont-ecom' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $log as $row ) : ?>
					<tr>
						<td>
							<?php
							printf(
								/* translators: %s: how long ago */
								esc_html__( '%s ago', 'dazont-ecom' ),
								esc_html( human_time_diff( (int) ( $row['at'] ?? time() ) ) )
							);
							if ( (int) ( $row['count'] ?? 1 ) > 1 ) {
								echo ' <span style="color:#646970;">×' . (int) $row['count'] . '</span>';
							}
							?>
						</td>
						<td><?php echo esc_html( (string) ( $labels[ $row['where'] ?? '' ] ?? ( $row['where'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['doing'] ?? '' ) ); ?></td>
						<td style="font-size:12px;"><?php echo esc_html( (string) ( $row['said'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="dze-health-clear"><?php esc_html_e( 'Clear the log', 'dazont-ecom' ); ?></button></p>
		<?php endif; ?>

		<script>
		jQuery(function ($) {
			function call(action, $b) {
				$b.prop('disabled', true);
				$('#dze-health-msg').css('color', '#646970').text('…');
				$.post(ajaxurl, { action: action, nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>' })
					.done(function () { window.location.reload(); })
					.fail(function () {
						$b.prop('disabled', false);
						$('#dze-health-msg').css('color', '#b32d2e').text('<?php echo esc_js( __( 'Could not run it.', 'dazont-ecom' ) ); ?>');
					});
			}
			$('#dze-health-run').on('click', function () { call('dze_health_run', $(this)); });
			$('#dze-health-clear').on('click', function () { call('dze_health_clear', $(this)); });
			$('#dze-health-auto').on('change', function () {
				var $c = $(this), on = $c.is(':checked');
				$c.prop('disabled', true);
				$('#dze-health-msg').css('color', '#646970').text('…');
				$.post(ajaxurl, { action: 'dze_health_auto', on: on ? 1 : 0, nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>' })
					.done(function (r) {
						$c.prop('checked', !!(r && r.data && r.data.on));
						$('#dze-health-msg').css('color', '#0a7040').text('<?php echo esc_js( __( 'Saved.', 'dazont-ecom' ) ); ?>');
					})
					.fail(function () {
						$c.prop('checked', !on);
						$('#dze-health-msg').css('color', '#b32d2e').text('<?php echo esc_js( __( 'Could not save it.', 'dazont-ecom' ) ); ?>');
					})
					.always(function () { $c.prop('disabled', false); });
			});
		});
		</script>
		<?php
	}
}
