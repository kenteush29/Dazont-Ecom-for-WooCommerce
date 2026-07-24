<?php
defined( 'ABSPATH' ) || exit;
/**
 * "Recommendations" (Frequently Bought Together) settings.
 *
 * @var array          $settings
 * @var array          $placements
 * @var array          $attributes   wc_get_attribute_taxonomies()
 * @var array|WP_Error $categories
 */
$opt        = DZE_Fbt::OPT_SETTINGS;
$sel_attrs  = (array) $settings['match_attributes'];
$pairs      = (array) $settings['category_pairs'];
$cat_list   = is_wp_error( $categories ) ? [] : $categories;
// Render existing pairs + a few empty rows to add more (no JS needed).
$rows  = array_values( $pairs );
$start = count( $rows );
for ( $i = $start; $i < $start + 3; $i++ ) {
	$rows[] = [ 'from' => 0, 'to' => [] ];
}
?>
<div class="wrap dze-wrap">
	<h1><?php esc_html_e( 'Product recommendations', 'dazont-ecom' ); ?></h1>
	<p class="description" style="max-width:860px;">
		<?php esc_html_e( 'A “Frequently bought together” block on each product page. Recommendations are automatic and work without any sales history: they are built from the product’s own attributes and categories — for example a Multicam jacket suggests Multicam pants.', 'dazont-ecom' ); ?>
	</p>

	<form method="post" action="options.php">
		<?php settings_fields( 'dze_fbt_options' ); ?>

		<h2 class="title"><?php esc_html_e( 'Display', 'dazont-ecom' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable', 'dazont-ecom' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $opt . '[enabled]' ); ?>" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> /> <?php esc_html_e( 'Show the recommendations block on product pages', 'dazont-ecom' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-fbt-heading"><?php esc_html_e( 'Block heading', 'dazont-ecom' ); ?></label></th>
				<td><input type="text" id="dze-fbt-heading" name="<?php echo esc_attr( $opt . '[heading]' ); ?>" value="<?php echo esc_attr( $settings['heading'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-fbt-limit"><?php esc_html_e( 'How many products', 'dazont-ecom' ); ?></label></th>
				<td><input type="number" id="dze-fbt-limit" name="<?php echo esc_attr( $opt . '[limit]' ); ?>" value="<?php echo esc_attr( (int) $settings['limit'] ); ?>" min="1" max="12" class="small-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-fbt-position"><?php esc_html_e( 'Placement on the product page', 'dazont-ecom' ); ?></label></th>
				<td>
					<select id="dze-fbt-position" name="<?php echo esc_attr( $opt . '[position]' ); ?>">
						<?php foreach ( $placements as $key => $p ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['position'], $key ); ?>><?php echo esc_html( $p['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Matching attributes', 'dazont-ecom' ); ?></h2>
		<p class="description" style="max-width:860px;">
			<?php esc_html_e( 'Pick the product attribute(s) that define “the same style”. Recommended products will share the same value — e.g. tick “Camo” so a Multicam item only suggests other Multicam items. Leave all unticked to recommend purely by category.', 'dazont-ecom' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Attributes', 'dazont-ecom' ); ?></th>
				<td>
					<?php if ( empty( $attributes ) ) : ?>
						<p class="description"><?php esc_html_e( 'No global product attributes found. Create them under Products → Attributes (e.g. “Camo”).', 'dazont-ecom' ); ?></p>
					<?php else : foreach ( $attributes as $attr ) :
						$tax = wc_attribute_taxonomy_name( $attr->attribute_name ); ?>
						<label style="display:inline-block;margin:0 16px 6px 0;">
							<input type="checkbox" name="<?php echo esc_attr( $opt . '[match_attributes][]' ); ?>" value="<?php echo esc_attr( $tax ); ?>" <?php checked( in_array( $tax, $sel_attrs, true ) ); ?> />
							<?php echo esc_html( $attr->attribute_label ); ?> <code><?php echo esc_html( $tax ); ?></code>
						</label>
					<?php endforeach; endif; ?>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Complementary categories', 'dazont-ecom' ); ?></h2>
		<p class="description" style="max-width:860px;">
			<?php esc_html_e( 'Pair categories that go together, so one suggests the other — e.g. Jackets → Pants. Pairs work both ways (viewing pants will also suggest jackets). Combined with a matching attribute, this yields “Multicam jacket → Multicam pants”.', 'dazont-ecom' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<?php foreach ( $rows as $i => $row ) :
				$from = (int) ( $row['from'] ?? 0 );
				$to   = array_map( 'intval', (array) ( $row['to'] ?? [] ) ); ?>
				<tr>
					<th scope="row"><?php echo 0 === $i ? esc_html__( 'Pairs', 'dazont-ecom' ) : ''; ?></th>
					<td>
						<select name="<?php echo esc_attr( $opt . '[category_pairs][' . $i . '][from]' ); ?>">
							<option value="0"><?php esc_html_e( '— category —', 'dazont-ecom' ); ?></option>
							<?php foreach ( $cat_list as $c ) : ?>
								<option value="<?php echo esc_attr( $c->term_id ); ?>" <?php selected( $from, (int) $c->term_id ); ?>><?php echo esc_html( $c->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<span style="margin:0 6px;">→</span>
						<select name="<?php echo esc_attr( $opt . '[category_pairs][' . $i . '][to][]' ); ?>" multiple size="4" style="min-width:220px;vertical-align:middle;">
							<?php foreach ( $cat_list as $c ) : ?>
								<option value="<?php echo esc_attr( $c->term_id ); ?>" <?php selected( in_array( (int) $c->term_id, $to, true ) ); ?>><?php echo esc_html( $c->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<p class="description"><?php esc_html_e( 'Ctrl/Cmd-click to pick several “to” categories. Save to add more empty rows.', 'dazont-ecom' ); ?></p>

		<?php submit_button( __( 'Save recommendations', 'dazont-ecom' ) ); ?>
	</form>
</div>
