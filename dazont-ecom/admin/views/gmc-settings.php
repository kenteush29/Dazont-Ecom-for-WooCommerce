<?php
defined( 'ABSPATH' ) || exit;
/**
 * @var array  $accounts
 * @var array  $keys
 * @var array  $languages
 * @var bool   $has_creds
 * @var bool   $creds_locked
 */
$lang_names = [];
foreach ( $languages as $l ) {
	$lang_names[ $l['code'] ] = $l['native_name'];
}
?>
	<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'dazont-ecom' ); ?></p></div>
	<?php endif; ?>

	<p class="description" style="max-width:820px;">
		<?php esc_html_e( 'Push your scheduled sales to Google Merchant Center as merchant promotions (for Google Ads / free listings). One Merchant Center account is used per language.', 'dazont-ecom' ); ?>
	</p>

	<?php if ( isset( $_GET['gmc_connected'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google account connected.', 'dazont-ecom' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['gmc_error'] ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['gmc_error'] ) ) ); ?></p></div>
	<?php endif; ?>

	<h2 class="title"><?php esc_html_e( 'Connect with Google (recommended)', 'dazont-ecom' ); ?></h2>
	<div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:14px 18px;max-width:820px;">
		<?php if ( $connected ) : ?>
			<p style="margin-top:0;">
				<span style="color:#0a7040;font-weight:600;">&#10003; <?php esc_html_e( 'Connected', 'dazont-ecom' ); ?></span>
				<?php if ( ! empty( $connection['email'] ) ) : ?> — <code><?php echo esc_html( $connection['email'] ); ?></code><?php endif; ?>
			</p>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dze_gmc_disconnect' ), 'dze_gmc_disconnect' ) ); ?>" class="button"><?php esc_html_e( 'Disconnect', 'dazont-ecom' ); ?></a>
		<?php else : ?>
			<p style="margin-top:0;"><?php esc_html_e( 'Create an OAuth Client ID (type “Web application”) in Google Cloud → APIs & Services → Credentials, then paste its Client ID and Secret below and click Connect. You sign in with your own Google account — one connection covers every Merchant Center you have access to.', 'dazont-ecom' ); ?></p>
			<p>
				<label style="font-weight:600;"><?php esc_html_e( 'Authorized redirect URI to add to your OAuth client:', 'dazont-ecom' ); ?></label><br>
				<input type="text" readonly value="<?php echo esc_attr( $redirect_uri ); ?>" class="large-text code" onclick="this.select();" />
			</p>
			<?php if ( $oauth_ready ) : ?>
				<a href="<?php echo esc_url( $authorize_url ); ?>" class="button button-primary"><?php esc_html_e( 'Connect Google account', 'dazont-ecom' ); ?></a>
				<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Save the Client ID/Secret first if you just entered them.', 'dazont-ecom' ); ?></span>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Enter and save the Client ID and Secret below, then a “Connect” button appears here.', 'dazont-ecom' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<form method="post" action="options.php">
		<?php settings_fields( 'dze_gmc_options' ); ?>

		<h2 class="title"><?php esc_html_e( 'OAuth client', 'dazont-ecom' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dze-oauth-id"><?php esc_html_e( 'Client ID', 'dazont-ecom' ); ?></label></th>
				<td><input type="text" id="dze-oauth-id" name="<?php echo esc_attr( DZE_Gmc::OPT_OAUTH . '[client_id]' ); ?>" value="<?php echo esc_attr( $oauth['client_id'] ?? '' ); ?>" class="large-text" placeholder="xxxxxxxx.apps.googleusercontent.com" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-oauth-secret"><?php esc_html_e( 'Client Secret', 'dazont-ecom' ); ?></label></th>
				<td><input type="text" id="dze-oauth-secret" name="<?php echo esc_attr( DZE_Gmc::OPT_OAUTH . '[client_secret]' ); ?>" value="<?php echo esc_attr( $oauth['client_secret'] ?? '' ); ?>" class="large-text" placeholder="GOCSPX-…" /></td>
			</tr>
		</table>

		<details style="max-width:820px;margin:10px 0;">
			<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Advanced: service account instead of OAuth', 'dazont-ecom' ); ?></summary>
		<h2 class="title"><?php esc_html_e( 'Service account credentials', 'dazont-ecom' ); ?></h2>
		<?php if ( $creds_locked ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Credentials are provided by the DZE_GMC_SERVICE_ACCOUNT constant (wp-config.php). This is the recommended, most secure option.', 'dazont-ecom' ); ?></p></div>
		<?php else : ?>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Paste the JSON key of a Google Cloud service account that has access to your Merchant Center (Merchant API enabled). For maximum security you can instead define DZE_GMC_SERVICE_ACCOUNT in wp-config.php (a file path or the raw JSON) and leave this blank.', 'dazont-ecom' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dze-gmc-creds"><?php esc_html_e( 'Service account JSON', 'dazont-ecom' ); ?></label></th>
					<td>
						<textarea id="dze-gmc-creds" name="<?php echo esc_attr( DZE_Gmc::OPT_CREDENTIALS ); ?>" rows="6" class="large-text code" placeholder='{ "type": "service_account", "client_email": "...", "private_key": "...", "token_uri": "https://oauth2.googleapis.com/token" }'></textarea>
						<p class="description">
							<?php echo $has_creds
								? '<span style="color:#0a7040;">&#10003; ' . esc_html__( 'Credentials are set. Leave blank to keep them.', 'dazont-ecom' ) . '</span>'
								: esc_html__( 'No credentials set yet.', 'dazont-ecom' ); ?>
						</p>
					</td>
				</tr>
			</table>
		<?php endif; ?>
		</details>

		<h2 class="title"><?php esc_html_e( 'Advanced (parent) account', 'dazont-ecom' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dze-advanced-id"><?php esc_html_e( 'Advanced account ID', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="text" id="dze-advanced-id" name="<?php echo esc_attr( DZE_Gmc::OPT_ADVANCED ); ?>" value="<?php echo esc_attr( $advanced ); ?>" class="regular-text" placeholder="e.g. 5581304506" />
					<button type="button" class="button dze-gmc-register" data-target="dze-advanced-id"><?php esc_html_e( 'Register GCP', 'dazont-ecom' ); ?></button>
					<button type="button" class="button dze-gmc-verify" data-target="dze-advanced-id"><?php esc_html_e( 'Verify', 'dazont-ecom' ); ?></button>
					<span class="dze-gmc-verify-status" style="font-size:13px;margin-left:4px;"></span>
					<p class="description" style="max-width:820px;">
						<?php esc_html_e( 'If your Merchant Center is an advanced account with sub-accounts (one per domain/country, e.g. .fr, .de, .es, .com), enter the parent account ID here and click “Register GCP” once. Google requires the developer registration on the account where your user exists — the parent — not on each sub-account. After ~5 minutes, syncing to the sub-accounts below works.', 'dazont-ecom' ); ?><br>
						<?php esc_html_e( 'Save this field first, then click Register GCP.', 'dazont-ecom' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Merchant accounts per language', 'dazont-ecom' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php foreach ( $keys as $key ) :
				$acc      = $accounts[ $key ] ?? [];
				$is_lang  = $key !== 'default';
				$label    = $is_lang ? ( $lang_names[ $key ] ?? strtoupper( $key ) ) : __( 'Default (no WPML)', 'dazont-ecom' );
				$countries_val = implode( ', ', DZE_Gmc::account_countries( $acc ) );
			?>
			<tr>
				<th scope="row"><?php echo esc_html( $label ); ?><?php echo $is_lang ? ' <code>' . esc_html( $key ) . '</code>' : ''; ?></th>
				<td>
					<label><?php esc_html_e( 'Merchant ID', 'dazont-ecom' ); ?>
						<input type="text" id="dze-mid-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( DZE_Gmc::OPT_ACCOUNTS . '[' . $key . '][merchant_id]' ); ?>" value="<?php echo esc_attr( $acc['merchant_id'] ?? '' ); ?>" class="regular-text" placeholder="e.g. 123456789" />
					</label>
					<button type="button" class="button dze-gmc-verify" data-target="dze-mid-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Verify', 'dazont-ecom' ); ?></button>
					<span class="dze-gmc-verify-status" style="font-size:13px;margin-left:4px;"></span>
					<br style="line-height:2.4;">
					<label><?php esc_html_e( 'Target countries', 'dazont-ecom' ); ?>
						<input type="text" name="<?php echo esc_attr( DZE_Gmc::OPT_ACCOUNTS . '[' . $key . '][countries]' ); ?>" value="<?php echo esc_attr( $countries_val ); ?>" class="regular-text" placeholder="US, GB, CA, AU" />
					</label>
					<?php if ( ! $is_lang ) : ?>
					&nbsp;
					<label><?php esc_html_e( 'Content language', 'dazont-ecom' ); ?>
						<input type="text" name="<?php echo esc_attr( DZE_Gmc::OPT_ACCOUNTS . '[' . $key . '][language]' ); ?>" value="<?php echo esc_attr( $acc['language'] ?? '' ); ?>" size="4" maxlength="5" placeholder="en" />
					</label>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
		</table>
		<p class="description" style="max-width:820px;">
			<?php esc_html_e( 'Target countries = one or more 2-letter ISO codes, comma-separated. A Google promotion always targets a single country, so the plugin creates one promotion per country listed here.', 'dazont-ecom' ); ?><br>
			<?php esc_html_e( 'A language is not a country: for English, list every country you actually run promotions in (e.g. US, GB, CA, AU). Each country must be enabled in your Merchant Center Promotions program and the account must sell/ship there. Only the countries you list here are offered as sync targets in the promotions list.', 'dazont-ecom' ); ?>
		</p>
		<p class="description" style="max-width:820px;">
			<strong><?php esc_html_e( 'First time?', 'dazont-ecom' ); ?></strong>
			<?php esc_html_e( 'Register your Google Cloud project once, using the Advanced (parent) account above — or, for a standalone Merchant Center, using the single account ID. This is required before the Merchant API accepts calls. After registering, wait about 5 minutes, then use Verify / Sync.', 'dazont-ecom' ); ?>
		</p>

		<?php submit_button(); ?>
	</form>

	<hr />
	<h2><?php esc_html_e( 'Connection test', 'dazont-ecom' ); ?></h2>
	<p><?php esc_html_e( 'Verify the credentials by requesting a Google access token.', 'dazont-ecom' ); ?></p>
	<button type="button" id="dze-gmc-test" class="button button-secondary"><?php esc_html_e( 'Test connection', 'dazont-ecom' ); ?></button>
	<span id="dze-gmc-test-status" style="margin-left:8px;font-size:13px;"></span>

	<?php
	// Live authentication state read straight from storage, so what the test
	// evaluates is visible without having to click it.
	$diag = [
		__( 'OAuth client', 'dazont-ecom' )      => ( ! empty( $oauth['client_id'] ) && ! empty( $oauth['client_secret'] ) ) ? [ 'ok', __( 'present', 'dazont-ecom' ) ] : [ 'no', __( 'missing', 'dazont-ecom' ) ],
		__( 'Refresh token', 'dazont-ecom' )     => ! empty( $connection['refresh_token'] ) ? [ 'ok', __( 'stored', 'dazont-ecom' ) ] : [ 'no', __( 'missing', 'dazont-ecom' ) ],
		__( 'Connected account', 'dazont-ecom' ) => ! empty( $connection['email'] ) ? [ 'ok', $connection['email'] ] : [ 'na', '—' ],
		__( 'Service account', 'dazont-ecom' )   => $has_creds ? [ 'ok', $creds_locked ? __( 'constant', 'dazont-ecom' ) : __( 'option', 'dazont-ecom' ) ] : [ 'na', __( 'none', 'dazont-ecom' ) ],
	];
	?>
	<table class="widefat striped" style="max-width:520px;margin-top:12px;">
		<tbody>
		<?php foreach ( $diag as $label => $state ) :
			$color = $state[0] === 'ok' ? '#0a7040' : ( $state[0] === 'no' ? '#b32d2e' : '#666' );
			$mark  = $state[0] === 'ok' ? '&#10003;' : ( $state[0] === 'no' ? '&#10007;' : '•' );
		?>
			<tr>
				<td style="width:180px;font-weight:600;"><?php echo esc_html( $label ); ?></td>
				<td style="color:<?php echo esc_attr( $color ); ?>;"><?php echo $mark; // phpcs:ignore ?> <?php echo esc_html( $state[1] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p class="description" style="max-width:820px;margin-top:6px;">
		<?php esc_html_e( 'If “Refresh token” shows missing while the OAuth client is present, click “Connect Google account” above. Saving other settings no longer clears the connection.', 'dazont-ecom' ); ?>
	</p>

	<hr />
	<p class="description" style="max-width:820px;">
		<?php esc_html_e( 'Sync happens automatically every hour (WP-Cron) for active sales, and can be forced from the Marketing Events list (per promotion or in bulk). Note: GMC promotions apply store-wide (product/category scope is not yet mapped to GMC).', 'dazont-ecom' ); ?>
	</p>
