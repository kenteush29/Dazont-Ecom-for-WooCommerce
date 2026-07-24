<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap aics-wrap">
<h1><?php esc_html_e( 'AI Content Suite - Field Mapping', 'ai-content-suite' ); ?></h1>
<p class="description"><?php esc_html_e( 'Map each content slot to the field where data comes from or should be written. This is your site-specific configuration.', 'ai-content-suite' ); ?></p>

<?php if ( isset( $_GET['saved'] ) ) : ?>
<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Mapping saved.', 'ai-content-suite' ); ?></p></div>
<?php endif; ?>

<?php if ( ! function_exists( 'acf_get_field_groups' ) ) : ?>
<div class="notice notice-warning"><p><?php esc_html_e( 'ACF is not active. ACF fields will not appear in the lists below.', 'ai-content-suite' ); ?></p></div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="aics_save_mapping" />
<?php wp_nonce_field( 'aics_save_mapping' ); ?>

<table class="form-table aics-mapping-table" role="presentation">
<thead><tr>
  <th><?php esc_html_e( 'Slot', 'ai-content-suite' ); ?></th>
  <th><?php esc_html_e( 'Mapped field', 'ai-content-suite' ); ?></th>
  <th><?php esc_html_e( 'Custom meta_key (override)', 'ai-content-suite' ); ?></th>
</tr></thead>
<tbody>
<?php foreach ( $slots as $slot => $slot_label ) :
  $current = $mapping[ $slot ] ?? null;
  $current_val = ''; $custom_meta = '';
  if ( $current ) {
    if ( $current['type'] === 'woo_native' )    { $current_val = 'woo|' . $current['field']; }
    elseif ( $current['type'] === 'acf' )       { $current_val = 'acf|' . $current['field_key'] . '|' . $current['field_name']; }
    elseif ( $current['type'] === 'seo_meta' )  { $current_val = 'seo|' . $current['meta_key']; }
    elseif ( $current['type'] === 'custom_meta' ) { $custom_meta = $current['meta_key']; }
  }
?>
<tr>
  <td><strong><?php echo esc_html( $slot_label ); ?></strong><br><code><?php echo esc_html( $slot ); ?></code></td>
  <td>
    <select name="aics_mapping[<?php echo esc_attr( $slot ); ?>]" class="aics-field-select">
      <option value=""><?php esc_html_e( '- not mapped -', 'ai-content-suite' ); ?></option>
      <?php foreach ( $field_groups as $group_label => $fields ) : ?>
      <optgroup label="<?php echo esc_attr( $group_label ); ?>">
        <?php foreach ( $fields as $value => $label ) : ?>
        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_val, $value ); ?>><?php echo esc_html( $label ); ?></option>
        <?php endforeach; ?>
      </optgroup>
      <?php endforeach; ?>
    </select>
  </td>
  <td>
    <input type="text" name="aics_custom_meta[<?php echo esc_attr( $slot ); ?>]" value="<?php echo esc_attr( $custom_meta ); ?>" placeholder="e.g. _my_custom_field" class="regular-text" />
    <p class="description"><?php esc_html_e( 'Overrides the dropdown above if filled in.', 'ai-content-suite' ); ?></p>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php submit_button( __( 'Save mapping', 'ai-content-suite' ) ); ?>
</form>

<hr />
<h2><?php esc_html_e( 'Available ACF fields (product post type)', 'ai-content-suite' ); ?></h2>
<?php if ( function_exists( 'acf_get_field_groups' ) ) :
  $acf_groups = acf_get_field_groups( [ 'post_type' => 'product' ] );
  if ( $acf_groups ) : ?>
  <table class="widefat striped">
    <thead><tr>
      <th><?php esc_html_e( 'Group', 'ai-content-suite' ); ?></th>
      <th><?php esc_html_e( 'Field label', 'ai-content-suite' ); ?></th>
      <th><?php esc_html_e( 'Field name', 'ai-content-suite' ); ?></th>
      <th><?php esc_html_e( 'Field key', 'ai-content-suite' ); ?></th>
      <th><?php esc_html_e( 'Type', 'ai-content-suite' ); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ( $acf_groups as $group ) :
      $fields = acf_get_fields( $group['key'] );
      if ( ! $fields ) continue;
      foreach ( $fields as $f ) : ?>
      <tr>
        <td><?php echo esc_html( $group['title'] ); ?></td>
        <td><?php echo esc_html( $f['label'] ); ?></td>
        <td><code><?php echo esc_html( $f['name'] ); ?></code></td>
        <td><code><?php echo esc_html( $f['key'] ); ?></code></td>
        <td><?php echo esc_html( $f['type'] ); ?></td>
      </tr>
    <?php endforeach; endforeach; ?>
    </tbody>
  </table>
<?php else : ?>
  <p><?php esc_html_e( 'No ACF field groups found for product post type.', 'ai-content-suite' ); ?></p>
<?php endif; else : ?>
  <p><?php esc_html_e( 'ACF not active.', 'ai-content-suite' ); ?></p>
<?php endif; ?>
</div>
