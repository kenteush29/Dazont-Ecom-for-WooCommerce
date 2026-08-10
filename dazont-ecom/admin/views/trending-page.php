<?php
defined( 'ABSPATH' ) || exit;
/**
 * The Trending products card on the Shortcodes screen.
 *
 * @var bool $table_exists
 */
?>
<?php if ( ! $table_exists ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'The WooCommerce Analytics order-lookup table was not found. The shortcode will return nothing until WooCommerce Analytics data has synced (Analytics runs automatically on modern WooCommerce; force it via WooCommerce → Status → Tools → "Regenerate product/order lookup tables" if needed).', 'dazont-ecom' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<p class="description" style="max-width:820px;">
		<?php esc_html_e( 'This module ranks products by units sold and then hands off to WooCommerce\'s own [products] shortcode, so every native WooCommerce option — columns, pagination, ordering — works exactly as documented. There are no defaults to configure: everything is set per shortcode. By default the ranking is over all time; add time_period to narrow it. Results are cached for 24 hours.', 'dazont-ecom' ); ?>
	</p>

	<h3><?php esc_html_e( 'Usage', 'dazont-ecom' ); ?></h3>
	<p><?php esc_html_e( 'Add this shortcode to any page, post, or widget:', 'dazont-ecom' ); ?></p>
	<p><code>[<?php echo esc_html( DZE_Trending::SHORTCODE ); ?>]</code></p>

	<h4><?php esc_html_e( 'Our attribute', 'dazont-ecom' ); ?></h4>
	<ul style="list-style-type:disc;margin-left:20px;">
		<li><code>time_period</code> — <?php esc_html_e( 'look-back window in days. Omit it (or use "all") for the all-time ranking.', 'dazont-ecom' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Everything else is passed straight to WooCommerce [products]', 'dazont-ecom' ); ?></h4>
	<ul style="list-style-type:disc;margin-left:20px;">
		<li><code>limit</code> — <?php esc_html_e( 'products per page', 'dazont-ecom' ); ?></li>
		<li><code>columns</code> — <?php esc_html_e( 'columns (WooCommerce default if omitted)', 'dazont-ecom' ); ?></li>
		<li><code>paginate="true"</code> — <?php esc_html_e( 'enable pagination (limit then means "per page")', 'dazont-ecom' ); ?></li>
		<li><code>orderby</code>, <code>order</code>, <code>category</code>, <code>class</code>… — <?php esc_html_e( 'any standard [products] attribute.', 'dazont-ecom' ); ?></li>
	</ul>
	<p class="description">
		<?php esc_html_e( 'By default products stay in best-seller order (orderby="post__in"). Setting your own orderby overrides that.', 'dazont-ecom' ); ?>
	</p>

	<h4><?php esc_html_e( 'Examples', 'dazont-ecom' ); ?></h4>
	<ul style="list-style-type:disc;margin-left:20px;">
		<li><code>[<?php echo esc_html( DZE_Trending::SHORTCODE ); ?> paginate="true" limit="40" columns="5"]</code> — <?php esc_html_e( 'all-time best sellers, 40 per page, 5 columns, paginated', 'dazont-ecom' ); ?></li>
		<li><code>[<?php echo esc_html( DZE_Trending::SHORTCODE ); ?> time_period="7" limit="8"]</code> — <?php esc_html_e( 'trending this week, 8 products', 'dazont-ecom' ); ?></li>
		<li><code>[<?php echo esc_html( DZE_Trending::SHORTCODE ); ?> time_period="365" paginate="true" limit="40"]</code></li>
	</ul>
	<p class="description">
		<?php esc_html_e( 'Note: pagination needs a normal Page (not the site front page) and pretty permalinks, and only one paginated products block per page — this is standard WooCommerce behaviour.', 'dazont-ecom' ); ?>
	</p>

	<hr />

	<h3><?php esc_html_e( 'Cache', 'dazont-ecom' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Results are cached for 24 hours. Force a fresh computation now (e.g. right after a big sales day) instead of waiting for the cache to expire.', 'dazont-ecom' ); ?></p>
	<button type="button" id="dze-trending-clear-cache" class="button button-secondary"><?php esc_html_e( 'Clear cache', 'dazont-ecom' ); ?></button>
	<span id="dze-trending-clear-status" style="margin-left:8px;font-size:13px;color:#666;"></span>
