<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap aics-wrap">
<h1><?php esc_html_e( 'AI Content Suite — Settings', 'ai-content-suite' ); ?></h1>

<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'ai-content-suite' ); ?></p></div>
<?php endif; ?>

<?php if ( isset( $_GET['aics_reset'] ) ) : ?>
<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Prompts reset to built-in defaults.', 'ai-content-suite' ); ?></p></div>
<?php endif; ?>

<form method="post" action="options.php">
<?php settings_fields( 'aics_options' ); ?>

<!-- ===== Store Context ===== -->
<h2 class="title"><?php esc_html_e( 'Store Context', 'ai-content-suite' ); ?></h2>
<p class="description"><?php esc_html_e( 'Describe your store, brand, niche, or audience. This text is injected wherever {{store_context}} appears in system prompts.', 'ai-content-suite' ); ?></p>
<table class="form-table" role="presentation">
<tr>
  <th scope="row"><label for="aics_store_context"><?php esc_html_e( 'Store context', 'ai-content-suite' ); ?></label></th>
  <td>
    <textarea id="aics_store_context" name="<?php echo esc_attr( AICS_Settings::OPT_STORE_CONTEXT ); ?>"
      rows="4" class="large-text"
      placeholder="<?php esc_attr_e( 'e.g. This is a military and tactical gear store targeting law enforcement, outdoor enthusiasts, and survivalists.', 'ai-content-suite' ); ?>"
    ><?php echo esc_textarea( $store_context ); ?></textarea>
    <p class="description"><?php esc_html_e( 'Leave blank to omit. Example: "We sell premium tactical outdoor gear for law enforcement and adventurers."', 'ai-content-suite' ); ?></p>
  </td>
</tr>
</table>

<!-- ===== API Key ===== -->
<h2 class="title"><?php esc_html_e( 'Anthropic API', 'ai-content-suite' ); ?></h2>
<table class="form-table" role="presentation">
<tr>
  <th scope="row"><label for="aics_api_key_input"><?php esc_html_e( 'API Key', 'ai-content-suite' ); ?></label></th>
  <td>
    <input type="password" id="aics_api_key_input"
      name="<?php echo esc_attr( AICS_Settings::OPT_API_KEY ); ?>"
      value="" class="regular-text" autocomplete="new-password"
      placeholder="<?php echo $api_key_set ? esc_attr__( '(key saved — enter new value to replace)', 'ai-content-suite' ) : 'sk-ant-...'; ?>" />
    <?php if ( $api_key_set ) : ?>
      <span style="color:#0a7040; margin-left:8px;">&#10003; <?php esc_html_e( 'Key is saved', 'ai-content-suite' ); ?></span>
    <?php endif; ?>
    <p class="description"><?php esc_html_e( 'Leave blank to keep the existing key. Paste a new value to replace it.', 'ai-content-suite' ); ?></p>
  </td>
</tr>
</table>

<!-- ===== Models ===== -->
<h2 class="title"><?php esc_html_e( 'Models', 'ai-content-suite' ); ?></h2>
<table class="form-table" role="presentation">
<tr>
  <th scope="row"><label for="aics_default_model"><?php esc_html_e( 'Default model', 'ai-content-suite' ); ?></label></th>
  <td>
    <select id="aics_default_model" name="<?php echo esc_attr( AICS_Settings::OPT_DEFAULT_MODEL ); ?>">
    <?php foreach ( $available_models as $id => $label ) : ?>
      <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $default_model, $id ); ?>><?php echo esc_html( $label ); ?></option>
    <?php endforeach; ?>
    </select>
  </td>
</tr>
<?php foreach ( $task_labels as $task => $task_label ) : $override = $model_overrides[ $task ] ?? ''; ?>
<tr>
  <th scope="row"><?php echo esc_html( sprintf( __( 'Model for: %s', 'ai-content-suite' ), $task_label ) ); ?></th>
  <td>
    <select name="<?php echo esc_attr( AICS_Settings::OPT_MODEL_OVERRIDES . '[' . $task . ']' ); ?>">
      <option value=""><?php esc_html_e( '— use default —', 'ai-content-suite' ); ?></option>
      <?php foreach ( $available_models as $id => $label ) : ?>
        <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $override, $id ); ?>><?php echo esc_html( $label ); ?></option>
      <?php endforeach; ?>
    </select>
  </td>
</tr>
<?php endforeach; ?>
</table>

<!-- ===== Behaviour ===== -->
<h2 class="title"><?php esc_html_e( 'Behaviour', 'ai-content-suite' ); ?></h2>
<table class="form-table" role="presentation">
<tr>
  <th scope="row"><?php esc_html_e( 'Preview before saving', 'ai-content-suite' ); ?></th>
  <td>
    <label>
      <input type="checkbox" name="<?php echo esc_attr( AICS_Settings::OPT_PREVIEW_MODE ); ?>" value="1" <?php checked( $preview_mode ); ?> />
      <?php esc_html_e( 'Show generated text in a preview panel before writing to the field.', 'ai-content-suite' ); ?>
    </label>
  </td>
</tr>
</table>

<!-- ===== Cost Guardrails ===== -->
<h2 class="title"><?php esc_html_e( 'Cost Guardrails', 'ai-content-suite' ); ?></h2>
<p class="description"><?php esc_html_e( 'Set 0 to disable a limit.', 'ai-content-suite' ); ?></p>
<table class="form-table" role="presentation">
<tr>
  <th scope="row"><label for="aics_max_hour"><?php esc_html_e( 'Max API calls per hour', 'ai-content-suite' ); ?></label></th>
  <td><input type="number" id="aics_max_hour" name="<?php echo esc_attr( AICS_Settings::OPT_MAX_CALLS_HOUR ); ?>" value="<?php echo esc_attr( $max_hour ); ?>" min="0" class="small-text" /></td>
</tr>
<tr>
  <th scope="row"><label for="aics_max_day"><?php esc_html_e( 'Max API calls per day', 'ai-content-suite' ); ?></label></th>
  <td><input type="number" id="aics_max_day" name="<?php echo esc_attr( AICS_Settings::OPT_MAX_CALLS_DAY ); ?>" value="<?php echo esc_attr( $max_day ); ?>" min="0" class="small-text" /></td>
</tr>
</table>

<!-- ===== Prompt Templates ===== -->
<h2 class="title"><?php esc_html_e( 'Prompt Templates', 'ai-content-suite' ); ?></h2>
<p class="description">
  <?php esc_html_e( 'Placeholders available: {{product_name}}, {{supplier_data}}, {{store_context}}. Leave a field blank to use the built-in default.', 'ai-content-suite' ); ?>
</p>
<p>
  <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=aics-settings&aics_action=reset_prompts' ), 'aics_reset_prompts' ) ); ?>"
     class="button button-secondary"
     onclick="return confirm('<?php esc_attr_e( 'Reset all prompt templates to the built-in defaults? Your customisations will be lost.', 'ai-content-suite' ); ?>');">
    <?php esc_html_e( '↺ Reset all prompts to defaults', 'ai-content-suite' ); ?>
  </a>
  <span class="description" style="margin-left:8px;"><?php esc_html_e( 'Restores the optimised built-in prompts and discards any custom edits.', 'ai-content-suite' ); ?></span>
</p>

<?php foreach ( $task_labels as $task => $task_label ) :
  // Show a stored value only when it is a real customisation; otherwise the default.
  $p = [
    'system'        => ! empty( $stored_prompts[ $task ]['system'] )        ? $stored_prompts[ $task ]['system']        : ( $default_prompts[ $task ]['system'] ?? '' ),
    'user_template' => ! empty( $stored_prompts[ $task ]['user_template'] ) ? $stored_prompts[ $task ]['user_template'] : ( $default_prompts[ $task ]['user_template'] ?? '' ),
  ];
  $is_custom = ! empty( $stored_prompts[ $task ]['system'] ) || ! empty( $stored_prompts[ $task ]['user_template'] );
?>
<details style="margin-bottom:12px; border:1px solid #ddd; border-radius:4px; padding:8px 12px;">
  <summary style="font-weight:600; cursor:pointer; padding:4px 0;">
    <?php echo esc_html( $task_label ); ?>
    <?php if ( $is_custom ) : ?>
      <span style="color:#2271b1; font-weight:normal; font-size:12px;">— <?php esc_html_e( 'customised', 'ai-content-suite' ); ?></span>
    <?php else : ?>
      <span style="color:#999; font-weight:normal; font-size:12px;">— <?php esc_html_e( 'default', 'ai-content-suite' ); ?></span>
    <?php endif; ?>
  </summary>
  <table class="form-table" role="presentation" style="margin-top:8px;">
    <tr>
      <th scope="row" style="width:160px; vertical-align:top; padding-top:14px;"><?php esc_html_e( 'System prompt', 'ai-content-suite' ); ?></th>
      <td>
        <textarea
          name="<?php echo esc_attr( AICS_Settings::OPT_PROMPTS . '[' . $task . '][system]' ); ?>"
          rows="10" class="large-text code"
          style="width:100%; min-height:200px; font-family:Menlo,Consolas,monospace; font-size:13px; line-height:1.5;"
        ><?php echo esc_textarea( $p['system'] ); ?></textarea>
      </td>
    </tr>
    <tr>
      <th scope="row" style="vertical-align:top; padding-top:14px;"><?php esc_html_e( 'User prompt template', 'ai-content-suite' ); ?></th>
      <td>
        <textarea
          name="<?php echo esc_attr( AICS_Settings::OPT_PROMPTS . '[' . $task . '][user_template]' ); ?>"
          rows="6" class="large-text code"
          style="width:100%; min-height:120px; font-family:Menlo,Consolas,monospace; font-size:13px; line-height:1.5;"
        ><?php echo esc_textarea( $p['user_template'] ); ?></textarea>
        <p class="description"><?php esc_html_e( 'Use {{product_name}} and {{supplier_data}} as placeholders.', 'ai-content-suite' ); ?></p>
      </td>
    </tr>
  </table>
</details>
<?php endforeach; ?>

<?php submit_button(); ?>
</form>

<hr />
<!-- ===== Connection Test ===== -->
<h2><?php esc_html_e( 'API Connection Test', 'ai-content-suite' ); ?></h2>
<p><?php esc_html_e( 'Sends a trivial prompt to Claude using the saved key and default model.', 'ai-content-suite' ); ?></p>
<button type="button" id="aics-test-api" class="button button-secondary"><?php esc_html_e( 'Test API connection', 'ai-content-suite' ); ?></button>
<span id="aics-test-spinner" class="spinner" style="float:none;vertical-align:middle;margin-left:4px;"></span>
<div id="aics-test-result" style="margin-top:12px;"></div>

<hr />
<!-- ===== Log ===== -->
<h2><?php esc_html_e( 'API Call Log', 'ai-content-suite' ); ?></h2>
<?php if ( empty( $log ) ) : ?>
  <p><?php esc_html_e( 'No calls logged yet.', 'ai-content-suite' ); ?></p>
<?php else : ?>
  <button type="button" id="aics-clear-log" class="button button-link-delete" style="margin-bottom:8px;"><?php esc_html_e( 'Clear log', 'ai-content-suite' ); ?></button>
  <table class="widefat striped aics-log-table" style="max-width:100%;overflow-x:auto;display:block;">
    <thead><tr>
      <th><?php esc_html_e( 'Date', 'ai-content-suite' ); ?></th>
      <th><?php esc_html_e( 'Product', 'ai-content-suite' ); ?></th>
      <th><?php esc_html_e( 'Prompt', 'ai-content-suite' ); ?></th>
      <th><?php esc_html_e( 'Model', 'ai-content-suite' ); ?></th>
      <th><?php esc_html_e( 'Tokens in', 'ai-content-suite' ); ?></th>
      <th><?php esc_html_e( 'Tokens out', 'ai-content-suite' ); ?></th>
      <th><?php esc_html_e( 'Cost (USD)', 'ai-content-suite' ); ?></th>
      <th><?php esc_html_e( 'Status', 'ai-content-suite' ); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ( $log as $entry ) : ?>
    <tr>
      <td><?php echo esc_html( $entry['timestamp'] ?? '' ); ?></td>
      <td><?php $pid = $entry['product_id'] ?? 0;
        if ( $pid ) { echo '<a href="' . esc_url( get_edit_post_link( $pid ) ) . '">' . esc_html( get_the_title( $pid ) ?: "#$pid" ) . '</a>'; }
        else { echo '-'; } ?></td>
      <td><?php echo esc_html( $entry['prompt_slug'] ?? '-' ); ?></td>
      <td><?php echo esc_html( $entry['model'] ?? '-' ); ?></td>
      <td><?php echo esc_html( $entry['tokens_in'] ?? 0 ); ?></td>
      <td><?php echo esc_html( $entry['tokens_out'] ?? 0 ); ?></td>
      <td>$<?php echo esc_html( number_format( (float)( $entry['cost_usd'] ?? 0 ), 6 ) ); ?></td>
      <td><?php $status = $entry['status'] ?? ''; $cls = $status === 'success' ? 'aics-status-ok' : 'aics-status-err';
        echo '<span class="' . esc_attr( $cls ) . '">' . esc_html( $status ) . '</span>';
        if ( ! empty( $entry['message'] ) ) { echo '<br><small>' . esc_html( $entry['message'] ) . '</small>'; } ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
