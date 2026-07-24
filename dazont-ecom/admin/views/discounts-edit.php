<?php
defined( 'ABSPATH' ) || exit;
/**
 * Create / edit screen shared by "Marketing Events" and "Discounts" ($mode).
 *
 * @var array       $rules
 * @var array       $type_labels
 * @var array       $languages
 * @var array|null  $editing
 * @var array       $categories
 * @var array       $product_positions
 * @var string      $mode        'events' or 'discounts'
 * @var string      $menu_slug
 * @var string      $page_title
 */
$admin_post = admin_url( 'admin-post.php' );
$list_url   = add_query_arg( [ 'page' => $menu_slug ], admin_url( 'admin.php' ) );
$is_events  = ( 'events' === $mode );
$e = static function ( $key, $default = '' ) use ( $editing ) {
	return ( is_array( $editing ) && isset( $editing[ $key ] ) ) ? $editing[ $key ] : $default;
};
$banner_location = (string) $e( 'banner_location', 'top' );
?>
<div class="wrap dze-wrap">
	<h1 class="wp-heading-inline"><?php
		if ( $editing ) {
			echo $is_events ? esc_html__( 'Edit event', 'dazont-ecom' ) : esc_html__( 'Edit discount', 'dazont-ecom' );
		} else {
			echo $is_events ? esc_html__( 'Add event', 'dazont-ecom' ) : esc_html__( 'Add discount', 'dazont-ecom' );
		}
	?></h1>
	<a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back to list', 'dazont-ecom' ); ?></a>
	<hr class="wp-header-end" />

	<form method="post" action="<?php echo esc_url( $admin_post ); ?>" id="dze-discount-form">
		<input type="hidden" name="action" value="dze_discount_save" />
		<input type="hidden" name="rule_id" value="<?php echo esc_attr( $e( 'id' ) ); ?>" />
		<?php wp_nonce_field( DZE_Discounts::SAVE_NONCE ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dze-title"><?php esc_html_e( 'Title', 'dazont-ecom' ); ?></label></th>
				<td><input type="text" id="dze-title" name="title" class="regular-text" value="<?php echo esc_attr( $e( 'title' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Summer sale -20%', 'dazont-ecom' ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Enabled', 'dazont-ecom' ); ?></th>
				<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! $editing || ! empty( $editing['enabled'] ) ); ?> /> <?php esc_html_e( 'Promotion is active', 'dazont-ecom' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-type"><?php esc_html_e( 'Type', 'dazont-ecom' ); ?></label></th>
				<td>
					<select id="dze-type" name="type">
						<?php foreach ( $type_labels as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $e( 'type', 'sale' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr class="dze-field-percent">
				<th scope="row"><label for="dze-percent"><?php esc_html_e( 'Discount (%)', 'dazont-ecom' ); ?></label></th>
				<td><input type="number" id="dze-percent" name="percent" min="0" max="100" step="0.01" class="small-text" value="<?php echo esc_attr( $e( 'percent', '10' ) ); ?>" /> %</td>
			</tr>


			<?php if ( ! $is_events ) : ?>
			<tr class="dze-field-threshold">
				<th scope="row"><label for="dze-threshold"><span class="dze-threshold-label"></span></label></th>
				<td>
					<input type="number" id="dze-threshold" name="threshold" min="0" step="1" class="small-text" value="<?php echo esc_attr( $e( 'threshold', '2' ) ); ?>" />
					<p class="description dze-threshold-help"></p>
				</td>
			</tr>

			<?php
			// Bulk order (tiered) fields.
			$tiers = (array) ( $editing['tiers'] ?? [] );
			if ( empty( $tiers ) ) {
				$tiers = DZE_Discounts::default_tiers();
			}
			$tiers = array_values( $tiers );
			for ( $i = count( $tiers ); $i < 4; $i++ ) {
				$tiers[] = [ 'qty' => 0, 'percent' => 0 ];
			}
			?>
			<tr class="dze-field-min-subtotal">
				<th scope="row"><label for="dze-min-subtotal"><?php esc_html_e( 'Minimum cart subtotal', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="number" id="dze-min-subtotal" name="min_subtotal" min="0" step="0.01" class="small-text" value="<?php echo esc_attr( $e( 'min_subtotal', '0' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'The cart must reach this amount before any tier applies. Leave 0 to ignore.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<tr class="dze-field-min-qty">
				<th scope="row"><label for="dze-min-qty"><?php esc_html_e( 'Minimum total quantity', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="number" id="dze-min-qty" name="min_qty" min="0" step="1" class="small-text" value="<?php echo esc_attr( $e( 'min_qty', '0' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Total items across all products, before any tier applies. Leave 0 to ignore.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<tr class="dze-field-tiers">
				<th scope="row"><?php esc_html_e( 'Quantity tiers', 'dazont-ecom' ); ?></th>
				<td>
					<?php foreach ( $tiers as $i => $tier ) : ?>
						<p style="margin:0 0 6px;">
							<?php esc_html_e( 'From', 'dazont-ecom' ); ?>
							<input type="number" name="tiers[<?php echo (int) $i; ?>][qty]" min="0" step="1" class="small-text" value="<?php echo esc_attr( (int) ( $tier['qty'] ?? 0 ) ); ?>" />
							<?php esc_html_e( 'items → discount', 'dazont-ecom' ); ?>
							<input type="number" name="tiers[<?php echo (int) $i; ?>][percent]" min="0" max="100" step="0.01" class="small-text" value="<?php echo esc_attr( (float) ( $tier['percent'] ?? 0 ) ); ?>" /> %
						</p>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'The highest tier reached applies (strongest discount, in the customer\'s favour). Set a percent to 0 to disable a tier.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>

			<?php // Automatic product discount fields. ?>
			<tr class="dze-field-strategy">
				<th scope="row"><label for="dze-strategy"><?php esc_html_e( 'Strategy', 'dazont-ecom' ); ?></label></th>
				<td>
					<select id="dze-strategy" name="strategy">
						<?php $cur_strategy = (string) $e( 'strategy', 'newest' );
						foreach ( DZE_Discounts::auto_strategies() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cur_strategy, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<div class="dze-strat-desc" data-strategy="newest">
						<p class="description"><strong><?php esc_html_e( 'Eligible products:', 'dazont-ecom' ); ?></strong> <?php esc_html_e( 'every published product whose publish date falls within the last “Time window” days. Ordered newest first, then capped at “How many products”. No sales data needed.', 'dazont-ecom' ); ?></p>
					</div>
					<div class="dze-strat-desc" data-strategy="slow">
						<p class="description"><strong><?php esc_html_e( 'Eligible products:', 'dazont-ecom' ); ?></strong> <?php esc_html_e( 'published products with ZERO recorded sales during the last “Time window” days (units sold = 0 in that window). Ordered newest first, then capped. Products that sold at least once in the window are excluded — this pushes the long tail, not what already sells.', 'dazont-ecom' ); ?></p>
					</div>
					<div class="dze-strat-desc" data-strategy="bestsellers">
						<p class="description"><strong><?php esc_html_e( 'Eligible products:', 'dazont-ecom' ); ?></strong> <?php esc_html_e( 'published products with the MOST units sold during the last “Time window” days. Ranked by units sold (highest first), then capped. Products with no sales in the window never qualify.', 'dazont-ecom' ); ?></p>
					</div>
					<div class="dze-strat-desc" data-strategy="trending">
						<p class="description"><strong><?php esc_html_e( 'Eligible products:', 'dazont-ecom' ); ?></strong> <?php esc_html_e( 'published products whose units sold in the most recent HALF of the window are higher than in the earlier half (sales accelerating). Ranked by the biggest positive difference, then capped. Flat or declining products are excluded.', 'dazont-ecom' ); ?></p>
					</div>
					<p class="description" style="margin-top:6px;border-top:1px solid #eee;padding-top:6px;">
						<?php esc_html_e( 'In every strategy: only Published products count; the list refreshes automatically once a week; and any product already covered by an active Marketing Event is skipped (the event always wins). Sales data comes from WooCommerce Analytics.', 'dazont-ecom' ); ?>
					</p>
				</td>
			</tr>
			<tr class="dze-field-top-n">
				<th scope="row"><label for="dze-top-n"><?php esc_html_e( 'How many products (cap)', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="number" id="dze-top-n" name="top_n" min="1" max="100000" step="1" class="small-text" value="<?php echo esc_attr( $e( 'top_n', '20' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Maximum number of products this rule discounts at once. There is no fixed limit — set it high (e.g. above your catalogue size) to discount every matching product.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<tr class="dze-field-lookback">
				<th scope="row"><label for="dze-lookback"><?php esc_html_e( 'Time window (days)', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="number" id="dze-lookback" name="lookback_days" min="1" max="365" step="1" class="small-text" value="<?php echo esc_attr( $e( 'lookback_days', '30' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'The window used by the strategy (recent sales, or how far back "new arrivals" reaches). The product list refreshes automatically, once a week.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<tr class="dze-field-priority">
				<th scope="row"><label for="dze-priority"><?php esc_html_e( 'When more products match than the cap', 'dazont-ecom' ); ?></label></th>
				<td>
					<select id="dze-priority" name="priority">
						<?php $cur_priority = (string) $e( 'priority', 'recent' );
						foreach ( DZE_Discounts::auto_priorities() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cur_priority, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'If e.g. 1000 products qualify but the cap is 150, this decides which 150 are picked. (Best-sellers & Trending are already ranked by sales, so this is only used for New arrivals & Slow movers.)', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<tr class="dze-field-autocount">
				<th scope="row"><?php esc_html_e( 'Preview', 'dazont-ecom' ); ?></th>
				<td>
					<button type="button" class="button" id="dze-auto-count"><?php esc_html_e( 'Count matching products', 'dazont-ecom' ); ?></button>
					<button type="button" class="button" id="dze-auto-list" style="display:none;"><?php esc_html_e( 'View list', 'dazont-ecom' ); ?></button>
					<span id="dze-auto-count-out" style="margin-left:8px;font-size:13px;color:#555;"></span>
					<p class="description"><?php esc_html_e( 'See how many products currently match, and open the exact list that would be discounted, before enabling.', 'dazont-ecom' ); ?></p>
					<div class="dze-auto-modal" id="dze-auto-modal" style="display:none;"><div class="dze-auto-modal__inner"></div></div>
				</td>
			</tr>
			<?php endif; ?>

			<?php if ( $is_events ) : ?>
			<tr class="dze-field-schedule">
				<th scope="row"><?php esc_html_e( 'Schedule', 'dazont-ecom' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Start', 'dazont-ecom' ); ?> <input type="date" name="start" value="<?php echo esc_attr( $e( 'start' ) ); ?>" /></label>
					&nbsp;
					<label><?php esc_html_e( 'End', 'dazont-ecom' ); ?> <input type="date" name="end" value="<?php echo esc_attr( $e( 'end' ) ); ?>" /></label>
					<p class="description"><?php esc_html_e( 'Day-granular: the sale runs from the start date at 00:00 through the end date at 23:59 (site timezone). Leave blank for no limit. Only one event can run at a time.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
		</table>

		<?php if ( $is_events && ! empty( $languages ) ) :
			$rule_langs = (array) ( $editing['languages'] ?? [] ); ?>
		<h3><?php esc_html_e( 'Languages', 'dazont-ecom' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable on languages', 'dazont-ecom' ); ?></th>
				<td>
					<?php foreach ( $languages as $lang ) : ?>
						<label style="margin-right:14px;">
							<input type="checkbox" name="languages[]" value="<?php echo esc_attr( $lang['code'] ); ?>" <?php checked( in_array( $lang['code'], $rule_langs, true ) ); ?> />
							<?php if ( ! empty( $lang['flag'] ) ) : ?><img src="<?php echo esc_url( $lang['flag'] ); ?>" alt="" style="width:18px;height:12px;vertical-align:middle;" /> <?php endif; ?>
							<?php echo esc_html( $lang['native_name'] ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Leave all unchecked to target every language. A non-default language only becomes active when the banner text below is translated for it.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
		</table>
		<?php endif; ?>

		<div class="dze-field-scope">
		<h3><?php esc_html_e( 'Scope', 'dazont-ecom' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Applies to', 'dazont-ecom' ); ?></th>
				<td>
					<?php $scope = $e( 'scope', 'all' ); ?>
					<label><input type="radio" name="scope" value="all" <?php checked( $scope, 'all' ); ?> class="dze-scope" /> <?php esc_html_e( 'Whole store', 'dazont-ecom' ); ?></label><br>
					<label><input type="radio" name="scope" value="categories" <?php checked( $scope, 'categories' ); ?> class="dze-scope" /> <?php esc_html_e( 'Specific categories', 'dazont-ecom' ); ?></label><br>
					<label><input type="radio" name="scope" value="products" <?php checked( $scope, 'products' ); ?> class="dze-scope" /> <?php esc_html_e( 'Specific products', 'dazont-ecom' ); ?></label>
				</td>
			</tr>
			<tr class="dze-field-categories">
				<th scope="row"><label for="dze-cats"><?php esc_html_e( 'Categories', 'dazont-ecom' ); ?></label></th>
				<td>
					<select id="dze-cats" name="category_ids[]" multiple size="6" style="min-width:280px;">
						<?php
						$selected_cats = array_map( 'intval', (array) ( $editing['category_ids'] ?? [] ) );
						if ( ! is_wp_error( $categories ) ) :
							foreach ( $categories as $cat ) : ?>
								<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( in_array( (int) $cat->term_id, $selected_cats, true ) ); ?>><?php echo esc_html( $cat->name ); ?></option>
							<?php endforeach;
						endif; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Ctrl/Cmd-click to select several.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<tr class="dze-field-products">
				<th scope="row"><label for="dze-prods"><?php esc_html_e( 'Product IDs', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="text" id="dze-prods" name="product_ids" class="regular-text" value="<?php echo esc_attr( implode( ', ', array_map( 'absint', (array) ( $editing['product_ids'] ?? [] ) ) ) ); ?>" placeholder="e.g. 123, 456, 789" />
					<p class="description"><?php esc_html_e( 'Comma-separated product IDs (parent product for variable products).', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
		</table>
		</div><?php // .dze-field-scope ?>

		<?php if ( $is_events ) : ?>
		<div class="dze-field-banner">
			<h3><?php esc_html_e( 'Promo banner', 'dazont-ecom' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Show banner', 'dazont-ecom' ); ?></th>
					<td><label><input type="checkbox" name="banner_enabled" value="1" <?php checked( ! empty( $editing['banner_enabled'] ) ); ?> /> <?php esc_html_e( 'Display a banner while this sale is active', 'dazont-ecom' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-banner-text"><?php esc_html_e( 'Banner text', 'dazont-ecom' ); ?></label></th>
					<td><input type="text" id="dze-banner-text" name="banner_text" class="large-text" value="<?php echo esc_attr( $e( 'banner_text' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. 🔥 Summer Sale — 20% off everything!', 'dazont-ecom' ); ?>" />
					<?php if ( ! empty( $languages ) ) : ?>
						<p class="description"><?php esc_html_e( 'Default-language text (used when a translation below is empty).', 'dazont-ecom' ); ?></p>
					<?php endif; ?>
					</td>
				</tr>
				<?php if ( ! empty( $languages ) ) :
					$i18n         = (array) ( $editing['banner_text_i18n'] ?? [] );
					$default_lang = DZE_Wpml::default_language();
					foreach ( $languages as $lang ) :
						// The default language uses the "Banner text" field above —
						// no need to duplicate it as a translation.
						if ( $lang['code'] === $default_lang ) {
							continue;
						}
						?>
				<tr>
					<th scope="row"><label>
						<?php if ( ! empty( $lang['flag'] ) ) : ?><img src="<?php echo esc_url( $lang['flag'] ); ?>" alt="" style="width:18px;height:12px;vertical-align:middle;margin-right:4px;" /><?php endif; ?>
						<?php echo esc_html( sprintf( __( 'Banner text (%s)', 'dazont-ecom' ), $lang['native_name'] ) ); ?></label></th>
					<td><input type="text" name="banner_text_i18n[<?php echo esc_attr( $lang['code'] ); ?>]" class="large-text" value="<?php echo esc_attr( $i18n[ $lang['code'] ] ?? '' ); ?>" placeholder="<?php echo esc_attr( sprintf( __( 'Translation for %s', 'dazont-ecom' ), $lang['native_name'] ) ); ?>" /></td>
				</tr>
					<?php endforeach;
				endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Colors', 'dazont-ecom' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Background', 'dazont-ecom' ); ?> <input type="color" name="banner_bg" value="<?php echo esc_attr( $e( 'banner_bg', '#111111' ) ); ?>" /></label>
						&nbsp;
						<label><?php esc_html_e( 'Text', 'dazont-ecom' ); ?> <input type="color" name="banner_color" value="<?php echo esc_attr( $e( 'banner_color', '#ffffff' ) ); ?>" /></label>
						<p class="description"><?php esc_html_e( 'Only background and colour are set — font size/style come from your theme.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Location', 'dazont-ecom' ); ?></th>
					<td>
						<?php
						$loc_choices = [
							'top'          => __( 'Top of site — above the header', 'dazont-ecom' ),
							'below_header' => __( 'Below the header — under the menu', 'dazont-ecom' ),
							'product'      => __( 'Product page', 'dazont-ecom' ),
						];
						foreach ( $loc_choices as $key => $label ) : ?>
							<label style="display:block;margin-bottom:4px;"><input type="radio" name="banner_location" value="<?php echo esc_attr( $key ); ?>" <?php checked( $banner_location, $key ); ?> class="dze-banner-loc" /> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Pick one location. “Below the header” uses the Astra astra_header_after hook.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr class="dze-field-product-position">
					<th scope="row"><label for="dze-product-position"><?php esc_html_e( 'Product page position', 'dazont-ecom' ); ?></label></th>
					<td>
						<select id="dze-product-position" name="product_position">
							<?php $cur_pos = $e( 'product_position', 'before_product' );
							foreach ( $product_positions as $key => $pos ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cur_pos, $key ); ?>><?php echo esc_html( $pos['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Where on the single product page the banner appears (standard WooCommerce positions).', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dze-banner-hooks"><?php esc_html_e( 'Custom hooks', 'dazont-ecom' ); ?></label></th>
					<td>
						<input type="text" id="dze-banner-hooks" name="banner_hooks" class="regular-text" value="<?php echo esc_attr( $e( 'banner_hooks' ) ); ?>" placeholder="astra_header_after" />
						<p class="description"><?php esc_html_e( 'Optional. Additional theme/plugin hook names to also print the banner on — full freedom to target any Astra hook.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Countdown timer', 'dazont-ecom' ); ?></th>
					<td><label><input type="checkbox" name="banner_timer" value="1" <?php checked( ! empty( $editing['banner_timer'] ) ); ?> /> <?php esc_html_e( 'Show a live countdown to the sale end date inside the banner', 'dazont-ecom' ); ?></label>
						<p class="description"><?php esc_html_e( 'Requires an End date in the schedule above.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Homepage image swap (big events)', 'dazont-ecom' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Swap an image', 'dazont-ecom' ); ?></th>
					<td><label><input type="checkbox" name="hero_swap_enabled" value="1" <?php checked( ! empty( $editing['hero_swap_enabled'] ) ); ?> /> <?php esc_html_e( 'Replace an image while this event is active (auto-reverts at the end)', 'dazont-ecom' ); ?></label></td>
				</tr>
				<?php
				$hero_source_id  = (int) $e( 'hero_source_id', 0 );
				$hero_event_id   = (int) $e( 'hero_event_id', 0 );
				$hero_source_url = $hero_source_id ? wp_get_attachment_image_url( $hero_source_id, [ 80, 80 ] ) : '';
				$hero_event_url  = $hero_event_id ? wp_get_attachment_image_url( $hero_event_id, [ 80, 80 ] ) : '';
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Current image', 'dazont-ecom' ); ?></th>
					<td class="dze-hero-picker" data-target="hero_source_id">
						<input type="hidden" name="hero_source_id" value="<?php echo esc_attr( $hero_source_id ); ?>" />
						<img class="dze-hero-preview" src="<?php echo esc_url( $hero_source_url ); ?>" alt="" style="<?php echo $hero_source_url ? '' : 'display:none;'; ?>width:80px;height:80px;object-fit:cover;border:1px solid #dcdcde;border-radius:4px;vertical-align:middle;margin-right:8px;" />
						<button type="button" class="button dze-hero-select"><?php esc_html_e( 'Select image', 'dazont-ecom' ); ?></button>
						<button type="button" class="button-link dze-hero-clear" style="<?php echo $hero_source_url ? '' : 'display:none;'; ?>margin-left:6px;"><?php esc_html_e( 'Remove', 'dazont-ecom' ); ?></button>
						<p class="description"><?php esc_html_e( 'The image currently displayed that you want to replace during the event.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Event image', 'dazont-ecom' ); ?></th>
					<td class="dze-hero-picker" data-target="hero_event_id">
						<input type="hidden" name="hero_event_id" value="<?php echo esc_attr( $hero_event_id ); ?>" />
						<img class="dze-hero-preview" src="<?php echo esc_url( $hero_event_url ); ?>" alt="" style="<?php echo $hero_event_url ? '' : 'display:none;'; ?>width:80px;height:80px;object-fit:cover;border:1px solid #dcdcde;border-radius:4px;vertical-align:middle;margin-right:8px;" />
						<button type="button" class="button dze-hero-select"><?php esc_html_e( 'Select image', 'dazont-ecom' ); ?></button>
						<button type="button" class="button-link dze-hero-clear" style="<?php echo $hero_event_url ? '' : 'display:none;'; ?>margin-left:6px;"><?php esc_html_e( 'Remove', 'dazont-ecom' ); ?></button>
						<p class="description"><?php esc_html_e( 'The event image (e.g. Black Friday) shown instead for the duration. Media Library images only.', 'dazont-ecom' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php endif; ?>

		<?php
		$save_label = $is_events ? __( 'Save event', 'dazont-ecom' ) : __( 'Save discount', 'dazont-ecom' );
		submit_button( $save_label, 'primary', 'submit', false );
		?>
		<?php if ( $is_events && class_exists( 'DZE_Gmc' ) && DZE_Gmc::instance()->is_configured() ) : ?>
			<button type="submit" name="push_gmc" value="1" class="button" style="margin-left:6px;"><?php esc_html_e( 'Save & Push to GMC', 'dazont-ecom' ); ?></button>
			<p class="description" style="margin-top:6px;"><?php esc_html_e( 'Pushes this event to every configured Merchant Center country/language after saving.', 'dazont-ecom' ); ?></p>
		<?php endif; ?>
	</form>
</div>
