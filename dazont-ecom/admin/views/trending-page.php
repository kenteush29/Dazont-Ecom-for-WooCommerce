<?php
defined( 'ABSPATH' ) || exit;
/**
 * @var int  $time_period
 * @var int  $limit
 * @var int  $columns
 * @var int  $cache_hours
 * @var bool $table_exists
 */
?>
<div class="wrap dze-wrap">
	<h1><?php esc_html_e( 'Trending Products', 'dazont-ecom' ); ?></h1>

	<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'dazont-ecom' ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $table_exists ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'The WooCommerce Analytics order-lookup table was not found. The shortcode will return nothing until WooCommerce Analytics data has synced (Analytics runs automatically on modern WooCommerce; force it via WooCommerce → Status → Tools → "Regenerate product/order lookup tables" if needed).', 'dazont-ecom' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<h2 class="title"><?php esc_html_e( 'Default settings', 'dazont-ecom' ); ?></h2>
	<p class="description"><?php esc_html_e( 'These apply when a shortcode attribute is omitted.', 'dazont-ecom' ); ?></p>

	<form method="post" action="options.php">
		<?php settings_fields( 'dze_trending_options' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dze-time-period"><?php esc_html_e( 'Time period (days)', 'dazont-ecom' ); ?></label></th>
				<td><input type="number" id="dze-time-period" name="<?php echo esc_attr( DZE_Trending::OPT_TIME_PERIOD ); ?>" value="<?php echo esc_attr( $time_period ); ?>" min="1" max="365" class="small-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-limit"><?php esc_html_e( 'Number of products', 'dazont-ecom' ); ?></label></th>
				<td><input type="number" id="dze-limit" name="<?php echo esc_attr( DZE_Trending::OPT_LIMIT ); ?>" value="<?php echo esc_attr( $limit ); ?>" min="1" max="100" class="small-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-columns"><?php esc_html_e( 'Columns', 'dazont-ecom' ); ?></label></th>
				<td><input type="number" id="dze-columns" name="<?php echo esc_attr( DZE_Trending::OPT_COLUMNS ); ?>" value="<?php echo esc_attr( $columns ); ?>" min="1" max="6" class="small-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-cache-hours"><?php esc_html_e( 'Cache duration (hours)', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="number" id="dze-cache-hours" name="<?php echo esc_attr( DZE_Trending::OPT_CACHE_HOURS ); ?>" value="<?php echo esc_attr( $cache_hours ); ?>" min="1" max="168" class="small-text" />
					<p class="description"><?php esc_html_e( 'How long results are cached before being recomputed.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Cache', 'dazont-ecom' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Force a fresh computation on the next page view (e.g. right after a sale event) instead of waiting for the cache to expire.', 'dazont-ecom' ); ?></p>
	<button type="button" id="dze-trending-clear-cache" class="button button-secondary"><?php esc_html_e( 'Clear cache', 'dazont-ecom' ); ?></button>
	<span id="dze-trending-clear-status" style="margin-left:8px;font-size:13px;color:#666;"></span>

	<hr />

	<h2><?php esc_html_e( 'Usage', 'dazont-ecom' ); ?></h2>
	<p><?php esc_html_e( 'Add this shortcode to any page, post, or widget:', 'dazont-ecom' ); ?></p>
	<p><code>[<?php echo esc_html( DZE_Trending::SHORTCODE ); ?>]</code></p>

	<h3><?php esc_html_e( 'Available attributes', 'dazont-ecom' ); ?></h3>
	<ul style="list-style-type:disc;margin-left:20px;">
		<li><code>time_period</code> — <?php esc_html_e( 'days to look back (default: the setting above)', 'dazont-ecom' ); ?></li>
		<li><code>limit</code> — <?php esc_html_e( 'number of products to display', 'dazont-ecom' ); ?></li>
		<li><code>columns</code> — <?php esc_html_e( 'number of columns', 'dazont-ecom' ); ?></li>
	</ul>

	<h3><?php esc_html_e( 'Examples', 'dazont-ecom' ); ?></h3>
	<ul style="list-style-type:disc;margin-left:20px;">
		<li><code>[<?php echo esc_html( DZE_Trending::SHORTCODE ); ?> time_period="7"]</code> — <?php esc_html_e( 'trending this week', 'dazont-ecom' ); ?></li>
		<li><code>[<?php echo esc_html( DZE_Trending::SHORTCODE ); ?> time_period="90" limit="6" columns="3"]</code></li>
	</ul>

	<p class="description">
		<?php esc_html_e( 'Ranking is based on total units ordered per product over the chosen period (WooCommerce Analytics data).', 'dazont-ecom' ); ?>
	</p>
</div>
