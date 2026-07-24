<?php
defined( 'ABSPATH' ) || exit;
/**
 * List screen shared by "Marketing Events" and "Discounts" (filtered by $mode).
 *
 * @var array       $rules
 * @var array       $type_labels
 * @var array       $languages
 * @var string|null $notice
 * @var string      $mode        'events' or 'discounts'
 * @var string      $menu_slug
 * @var string      $page_title
 */
$admin_post = admin_url( 'admin-post.php' );
$new_url    = add_query_arg( [ 'page' => $menu_slug, 'new' => 1 ], admin_url( 'admin.php' ) );
$controller = DZE_Discounts::instance();
$is_events  = ( 'events' === $mode );
$gmc        = ( $is_events && class_exists( 'DZE_Gmc' ) ) ? DZE_Gmc::instance() : null;
$gmc_on     = $gmc && $gmc->is_configured();
?>
<div class="wrap dze-wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html( $page_title ); ?></h1>
	<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php echo esc_html( $is_events ? __( 'Add event', 'dazont-ecom' ) : __( 'Add discount', 'dazont-ecom' ) ); ?></a>
	<hr class="wp-header-end" />

	<?php
	if ( ! empty( $events_tabs ) ) {
		echo $events_tabs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_* internally.
	}
	?>

	<?php if ( $is_events && class_exists( 'DZE_Marketing_Ai' ) ) : ?>
		<?php DZE_Marketing_Ai::instance()->render_calendar_panel(); ?>
		<hr style="margin:24px 0;" />
	<?php endif; ?>

	<?php if ( $gmc_on ) : ?>
		<p>
			<button type="button" class="button" id="dze-gmc-sync-selected"><?php esc_html_e( 'Sync selected with GMC', 'dazont-ecom' ); ?></button>
			<span id="dze-gmc-bulk-status" style="margin-left:8px;font-size:13px;color:#666;"></span>
		</p>
	<?php endif; ?>

	<?php if ( isset( $_GET['saved'] ) && $_GET['saved'] === '1' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Promotion saved.', 'dazont-ecom' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Promotion deleted.', 'dazont-ecom' ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! empty( $notice ) ) : ?>
		<div class="notice notice-warning is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<table class="widefat striped">
		<thead><tr>
			<th><?php esc_html_e( 'Title', 'dazont-ecom' ); ?></th>
			<th><?php esc_html_e( 'Type', 'dazont-ecom' ); ?></th>
			<th><?php esc_html_e( 'Discount', 'dazont-ecom' ); ?></th>
			<th><?php esc_html_e( 'Scope', 'dazont-ecom' ); ?></th>
			<?php if ( $is_events && ! empty( $languages ) ) : ?><th><?php esc_html_e( 'Languages', 'dazont-ecom' ); ?></th><?php endif; ?>
			<?php if ( $is_events ) : ?><th><?php esc_html_e( 'Dates', 'dazont-ecom' ); ?></th><?php endif; ?>
			<th><?php esc_html_e( 'Status', 'dazont-ecom' ); ?></th>
			<?php if ( $gmc_on ) : ?><th><?php esc_html_e( 'GMC sync', 'dazont-ecom' ); ?></th><?php endif; ?>
			<th></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $rules ) ) :
			$colspan = 6 + ( $is_events && ! empty( $languages ) ? 1 : 0 ) + ( $is_events ? 1 : 0 ) + ( $gmc_on ? 1 : 0 ); ?>
			<tr><td colspan="<?php echo (int) $colspan; ?>"><?php echo $is_events ? esc_html__( 'No events yet.', 'dazont-ecom' ) : esc_html__( 'No discounts yet.', 'dazont-ecom' ); ?></td></tr>
		<?php else : foreach ( $rules as $id => $r ) :
			$toggle_url = wp_nonce_url( add_query_arg( [ 'action' => 'dze_discount_toggle', 'rule' => $id ], $admin_post ), 'dze_discount_toggle' );
			$delete_url = wp_nonce_url( add_query_arg( [ 'action' => 'dze_discount_delete', 'rule' => $id ], $admin_post ), 'dze_discount_delete' );
			$edit_url   = add_query_arg( [ 'page' => $menu_slug, 'edit' => $id ], admin_url( 'admin.php' ) );
			$enabled    = ! empty( $r['enabled'] );
			if ( ( $r['type'] ?? '' ) === 'autobest' ) {
				$strat_labels = DZE_Discounts::auto_strategies();
				$strat_short  = [
					'newest'      => __( 'New arrivals', 'dazont-ecom' ),
					'slow'        => __( 'Slow movers', 'dazont-ecom' ),
					'bestsellers' => __( 'Best-sellers', 'dazont-ecom' ),
					'trending'    => __( 'Trending', 'dazont-ecom' ),
				];
				$strat = $strat_short[ $r['strategy'] ?? 'bestsellers' ] ?? __( 'Best-sellers', 'dazont-ecom' );
				$scope_txt = sprintf(
					/* translators: 1: strategy name, 2: number of products, 3: number of days */
					__( '%1$s · up to %2$d · %3$d-day window', 'dazont-ecom' ),
					$strat,
					(int) ( $r['top_n'] ?? 20 ),
					(int) ( $r['lookback_days'] ?? 30 )
				);
			} else {
				$scope_txt = ( $r['scope'] ?? 'all' ) === 'all' ? __( 'Whole store', 'dazont-ecom' ) : ( $r['scope'] === 'categories' ? __( 'Categories', 'dazont-ecom' ) : __( 'Products', 'dazont-ecom' ) );
			}
			$date_fmt   = get_option( 'date_format' ) ?: 'Y-m-d';
			$fmt_date   = static function ( $ymd ) use ( $date_fmt ) {
				$ts = ! empty( $ymd ) ? strtotime( $ymd . ' 00:00:00' ) : false;
				return $ts ? wp_date( $date_fmt, $ts ) : '';
			};
			$start_txt  = ! empty( $r['start'] ) ? esc_html( $fmt_date( $r['start'] ) ) : '<span style="color:#999;">' . esc_html__( 'no start', 'dazont-ecom' ) . '</span>';
			$end_txt    = ! empty( $r['end'] )   ? esc_html( $fmt_date( $r['end'] ) )   : '<span style="color:#999;">' . esc_html__( 'no end', 'dazont-ecom' ) . '</span>';

			$status = $controller->rule_status( $r );
			$status_map = [
				'active'    => [ '#0a7040', '● ' . __( 'Active', 'dazont-ecom' ) ],
				'scheduled' => [ '#b26a00', '◔ ' . __( 'Scheduled', 'dazont-ecom' ) ],
				'passed'    => [ '#787c82', '◌ ' . __( 'Passed', 'dazont-ecom' ) ],
				'inactive'  => [ '#999999', '○ ' . __( 'Inactive', 'dazont-ecom' ) ],
			];
			$st = $status_map[ $status ] ?? $status_map['inactive'];
		?>
			<tr>
				<td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $r['title'] !== '' ? $r['title'] : '(untitled)' ); ?></a></strong></td>
				<td><?php echo esc_html( $type_labels[ $r['type'] ] ?? $r['type'] ); ?></td>
				<td><?php
				$fmt_pct = static fn( $v ) => rtrim( rtrim( number_format( (float) $v, 2, '.', '' ), '0' ), '.' );
				if ( ( $r['type'] ?? '' ) === 'bulk_order' ) {
					$pcts = array_map( static fn( $t ) => (float) ( $t['percent'] ?? 0 ), (array) ( $r['tiers'] ?? [] ) );
					echo $pcts
						? esc_html( sprintf( __( 'up to %s%%', 'dazont-ecom' ), $fmt_pct( max( $pcts ) ) ) )
						: '<span style="color:#999;">—</span>';
				} else {
					echo esc_html( $fmt_pct( $r['percent'] ?? 0 ) ) . '%';
				}
				?></td>
				<td><?php echo esc_html( $scope_txt ); ?></td>
				<?php if ( $is_events && ! empty( $languages ) ) : ?>
				<td>
					<?php
					$flags = $controller->rule_language_flags( $r );
					if ( empty( $flags ) ) {
						echo '<span style="color:#999;">—</span>';
					} else {
						foreach ( $flags as $lang ) {
							if ( ! empty( $lang['flag'] ) ) {
								printf(
									'<img src="%1$s" alt="%2$s" title="%2$s" style="width:18px;height:12px;margin-right:3px;vertical-align:middle;border:1px solid #eee;" />',
									esc_url( $lang['flag'] ),
									esc_attr( $lang['native_name'] )
								);
							} else {
								echo '<span style="margin-right:5px;">' . esc_html( strtoupper( $lang['code'] ) ) . '</span>';
							}
						}
					}
					?>
				</td>
				<?php endif; ?>
				<?php if ( $is_events ) : ?>
				<td>
					<?php if ( ( $r['type'] ?? '' ) === 'sale' ) : ?>
						<?php echo wp_kses_post( $start_txt ); ?> → <?php echo wp_kses_post( $end_txt ); ?>
					<?php else : ?>
						<span style="color:#999;">—</span>
					<?php endif; ?>
				</td>
				<?php endif; ?>
				<td><span style="color:<?php echo esc_attr( $st[0] ); ?>;font-weight:600;"><?php echo esc_html( $st[1] ); ?></span></td>
				<?php if ( $gmc_on ) : ?>
				<td>
					<?php if ( ( $r['type'] ?? '' ) === 'sale' ) : ?>
						<label style="display:block;margin-bottom:3px;"><input type="checkbox" class="dze-gmc-cb" value="<?php echo esc_attr( $id ); ?>" /> <?php echo wp_kses_post( $gmc->sync_badges_html( $r ) ); ?></label>
						<a href="#" class="dze-gmc-sync-one" data-rule="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Sync now', 'dazont-ecom' ); ?></a>
						<span class="dze-gmc-feedback" style="font-size:12px;margin-left:6px;"></span>
					<?php else : ?>
						<span style="color:#999;">—</span>
					<?php endif; ?>
				</td>
				<?php endif; ?>
				<td style="text-align:right;white-space:nowrap;">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'dazont-ecom' ); ?></a> |
					<a href="<?php echo esc_url( $toggle_url ); ?>"><?php echo $enabled ? esc_html__( 'Disable', 'dazont-ecom' ) : esc_html__( 'Enable', 'dazont-ecom' ); ?></a> |
					<a href="<?php echo esc_url( $delete_url ); ?>" class="dze-danger" onclick="return confirm('<?php esc_attr_e( 'Delete this promotion?', 'dazont-ecom' ); ?>');"><?php esc_html_e( 'Delete', 'dazont-ecom' ); ?></a>
				</td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>

	<p class="description" style="margin-top:12px;">
		<?php if ( $is_events ) : ?>
			<?php esc_html_e( 'Only one marketing event can be active at a time — overlapping ones are kept disabled.', 'dazont-ecom' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Discounts are evergreen: enable a rule once and it keeps applying (no schedule). Bulk offers show in the cart and at checkout as a promo-code line — “Bundle” (Bulk offer per item) and “Wholesale” (Bulk order) — with no code to type. An Automatic product discount instead shows a struck-through price directly on the chosen products.', 'dazont-ecom' ); ?>
		<?php endif; ?>
	</p>

	<?php if ( ! $is_events ) : ?>
	<div class="notice notice-info inline" style="max-width:900px;margin-top:16px;">
		<p style="margin:.6em 0;"><strong><?php esc_html_e( 'How these stack with your other promotions', 'dazont-ecom' ); ?></strong></p>
		<ul style="list-style:disc;margin:0 0 .6em 18px;">
			<li><?php esc_html_e( 'Only one Marketing Event (scheduled sale) runs at a time.', 'dazont-ecom' ); ?></li>
			<li><?php esc_html_e( 'A scheduled sale and an Automatic product discount never stack on the same product — the marketing event always wins for the products it covers.', 'dazont-ecom' ); ?></li>
			<li><?php esc_html_e( 'Bulk coupons (Bundle / Wholesale) are calculated on the already-discounted price and add on top, like a wholesale incentive over a sale price.', 'dazont-ecom' ); ?></li>
			<li><?php esc_html_e( 'Classic coupons your customers type keep working alongside these. To stop a coupon from stacking on discounted products, tick “Exclude sale items” on that coupon.', 'dazont-ecom' ); ?></li>
		</ul>
	</div>

	<?php if ( isset( $_GET['resynced'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Sale-price sync queued — it runs in the background.', 'dazont-ecom' ); ?></p></div>
	<?php endif; ?>
	<hr style="margin:24px 0;" />
	<h2><?php esc_html_e( 'Automatic discounts in product data (for feeds)', 'dazont-ecom' ); ?></h2>
	<p class="description" style="max-width:900px;">
		<?php esc_html_e( 'Automatic product discounts (slow movers, best-sellers, new arrivals, trending) are written into each product’s native WooCommerce sale price, so weekly feeds/exports (e.g. your GMC WPML export) pick them up — and removed again when the product leaves the selection. Marketing events are NOT written here: they reach Google through the promotion API over your regular feed price, so writing them too would double-count. This reconciles automatically when a discount changes, and once a week. Use the button to force it now.', 'dazont-ecom' ); ?>
	</p>
	<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
		<input type="hidden" name="action" value="dze_sale_resync" />
		<?php wp_nonce_field( 'dze_sale_resync' ); ?>
		<?php submit_button( __( 'Resync sale prices now', 'dazont-ecom' ), 'secondary', 'submit', false ); ?>
	</form>

	<?php
	// Global "never discount" list.
	$excl      = DZE_Discounts::get_exclusions();
	$excl_cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 500 ] );
	?>
	<hr style="margin:24px 0;" />
	<h2><?php esc_html_e( 'Never discount these products', 'dazont-ecom' ); ?></h2>
	<p class="description" style="max-width:900px;">
		<?php esc_html_e( 'Products (or whole categories) listed here are skipped by EVERY promotion — automatic discounts, bulk offers and marketing-event sales. Use it for things like a “Priority processing” upsell that should always stay full price.', 'dazont-ecom' ); ?>
	</p>
	<?php if ( isset( $_GET['excl_saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Exclusions saved.', 'dazont-ecom' ); ?></p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( $admin_post ); ?>" style="max-width:900px;">
		<input type="hidden" name="action" value="dze_discount_exclusions" />
		<?php wp_nonce_field( 'dze_discount_exclusions' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dze-excl-products"><?php esc_html_e( 'Product IDs', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="text" id="dze-excl-products" name="excl_products" class="large-text" value="<?php echo esc_attr( implode( ', ', $excl['products'] ) ); ?>" placeholder="e.g. 123, 456" />
					<p class="description"><?php esc_html_e( 'Comma-separated product IDs. Tip: the Products gallery and the product list both show each product’s #ID.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-excl-cats"><?php esc_html_e( 'Categories', 'dazont-ecom' ); ?></label></th>
				<td>
					<select id="dze-excl-cats" name="excl_categories[]" multiple size="6" style="min-width:280px;">
						<?php if ( ! is_wp_error( $excl_cats ) ) : foreach ( $excl_cats as $c ) : ?>
							<option value="<?php echo esc_attr( $c->term_id ); ?>" <?php selected( in_array( (int) $c->term_id, $excl['categories'], true ) ); ?>><?php echo esc_html( $c->name ); ?></option>
						<?php endforeach; endif; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Ctrl/Cmd-click to select several. Every product in these categories is excluded.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save exclusions', 'dazont-ecom' ) ); ?>
	</form>
	<?php endif; ?>
</div>
