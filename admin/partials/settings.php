<?php
/**
 * Admin Settings — AIP, Predictions languages, FCM configuration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$message  = sanitize_text_field( $_GET['message'] ?? '' );
$settings = sas_get_settings();
$langs    = SAS_SUPPORTED_LANGS;

$lang_labels = [
    'en' => 'English',
    'hi' => 'Hindi',
    'ta' => 'Tamil',
    'te' => 'Telugu',
    'ka' => 'Kannada',
    'ml' => 'Malayalam',
    'be' => 'Bengali',
    'sp' => 'Spanish',
    'fr' => 'French',
];

$current_model = $settings['aip_model'] ?? 'gpt-4o-mini';
?>
<div class="wrap sas-wrap">
    <h1><?php esc_html_e( 'Astro Services — Settings', 'sanathan-astro' ); ?></h1>

    <?php if ( $message === 'saved' ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( '✅ Settings saved.', 'sanathan-astro' ); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'sas_save_settings' ); ?>
        <input type="hidden" name="action" value="sas_save_settings">

        <!-- ── Personal Guruji — AIP Integration ──────────────────────────── -->
        <h2>🧘 <?php esc_html_e( 'Personal Guruji — AI Power (AIP) Integration', 'sanathan-astro' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Guruji uses the AIP plugin (already installed) for all AI responses. Enter your AIP REST API key and choose which model Guruji will use.', 'sanathan-astro' ); ?>
            <br>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=aipkit-dashboard' ) ); ?>" target="_blank">
                <?php esc_html_e( '→ Open AIP Settings', 'sanathan-astro' ); ?>
            </a> &nbsp;|&nbsp;
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=aipkit-train' ) ); ?>" target="_blank">
                <?php esc_html_e( '→ AIP Knowledge Base (Train)', 'sanathan-astro' ); ?>
            </a>
        </p>

        <table class="form-table">
            <!-- AIP API Key -->
            <tr>
                <th><label for="aip_api_key"><?php esc_html_e( 'AIP REST API Key', 'sanathan-astro' ); ?></label></th>
                <td>
                    <input type="password" id="aip_api_key" name="sas_settings[aip_api_key]"
                        value="<?php echo esc_attr( $settings['aip_api_key'] ?? '' ); ?>"
                        class="regular-text" autocomplete="new-password"
                        placeholder="<?php esc_attr_e( 'Enter your AIP Public API Key', 'sanathan-astro' ); ?>">
                    <p class="description">
                        <?php esc_html_e( 'Find this in: AIP → Settings (gear icon) → API tab → Public API Key.', 'sanathan-astro' ); ?>
                    </p>
                </td>
            </tr>

            <!-- AIP Model Selector -->
            <tr>
                <th><label for="aip_model"><?php esc_html_e( 'Guruji AI Model', 'sanathan-astro' ); ?></label></th>
                <td>
                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <select id="aip_model" name="sas_settings[aip_model]" style="min-width:280px;">
                            <?php
                            $all_models = SAS_AIP_Client::KNOWN_MODELS;
                            foreach ( $all_models as $group_label => $models ) :
                                echo '<optgroup label="' . esc_attr( $group_label ) . '">';
                                foreach ( $models as $model_id => $model_name ) :
                            ?>
                                <option value="<?php echo esc_attr( $model_id ); ?>"
                                    <?php selected( $current_model, $model_id ); ?>>
                                    <?php echo esc_html( $model_name ); ?>
                                </option>
                            <?php
                                endforeach;
                                echo '</optgroup>';
                            endforeach;
                            ?>
                        </select>

                        <button type="button" id="sas-sync-models" class="button button-secondary">
                            🔄 <?php esc_html_e( 'Sync from AIP', 'sanathan-astro' ); ?>
                        </button>

                        <span id="sas-sync-status" style="color:#666;"></span>
                    </div>

                    <p class="description">
                        <?php esc_html_e( 'Choose the LLM model Guruji will use. Click "Sync from AIP" to fetch models currently configured in your AIP plugin.', 'sanathan-astro' ); ?>
                        <br>
                        <strong><?php esc_html_e( 'Cost tip:', 'sanathan-astro' ); ?></strong>
                        <?php esc_html_e( 'GPT-4o Mini and Claude Haiku are cheapest. Use them for development.', 'sanathan-astro' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Custom model text input (advanced) -->
            <tr>
                <th><label for="aip_model_custom"><?php esc_html_e( 'Custom Model ID', 'sanathan-astro' ); ?></label></th>
                <td>
                    <input type="text" id="aip_model_custom" name="sas_settings[aip_model_custom]"
                        value="<?php echo esc_attr( $settings['aip_model_custom'] ?? '' ); ?>"
                        class="regular-text"
                        placeholder="<?php esc_attr_e( 'Optional: override with any model ID e.g. gpt-4o', 'sanathan-astro' ); ?>">
                    <p class="description">
                        <?php esc_html_e( 'If filled, this overrides the dropdown above. Leave blank to use the dropdown selection.', 'sanathan-astro' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <!-- ── Prediction Languages ────────────────────────────────────────── -->
        <h2>🌐 <?php esc_html_e( 'Prediction Languages', 'sanathan-astro' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Select which languages to pre-fetch daily/weekly/yearly predictions for. More languages = more API calls per cron run.', 'sanathan-astro' ); ?></p>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Enabled Languages', 'sanathan-astro' ); ?></th>
                <td>
                    <?php foreach ( $langs as $code ) : ?>
                    <label style="margin-right:15px; display:inline-block; margin-bottom:6px;">
                        <input type="checkbox" name="sas_settings[enabled_languages][]"
                            value="<?php echo esc_attr( $code ); ?>"
                            <?php checked( in_array( $code, $settings['enabled_languages'], true ) ); ?>>
                        <?php echo esc_html( $lang_labels[ $code ] ?? strtoupper( $code ) ); ?>
                        (<?php echo esc_html( strtoupper( $code ) ); ?>)
                    </label>
                    <?php endforeach; ?>
                    <p class="description">
                        <?php
                        $enabled_count = count( $settings['enabled_languages'] );
                        echo esc_html( sprintf(
                            __( 'Currently %d language(s) enabled → %d API calls per daily cron run', 'sanathan-astro' ),
                            $enabled_count,
                            $enabled_count * 12
                        ) );
                        ?>
                    </p>
                </td>
            </tr>
        </table>

        <!-- ── Firebase FCM ───────────────────────────────────────────────── -->
        <h2>🔔 <?php esc_html_e( 'Push Notifications — Firebase FCM (Phase 3)', 'sanathan-astro' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="fcm_server_key"><?php esc_html_e( 'FCM Server Key', 'sanathan-astro' ); ?></label></th>
                <td>
                    <input type="password" id="fcm_server_key" name="sas_settings[fcm_server_key]"
                        value="<?php echo esc_attr( $settings['fcm_server_key'] ); ?>"
                        class="regular-text">
                    <p class="description"><?php esc_html_e( 'From Firebase Console → Project Settings → Cloud Messaging → Server key', 'sanathan-astro' ); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button( __( 'Save Settings', 'sanathan-astro' ) ); ?>
    </form>
</div>

<style>
.sas-wrap { max-width: 960px; }
</style>

<script>
jQuery(function($){
    $('#sas-sync-models').on('click', function(){
        var $btn    = $(this);
        var $status = $('#sas-sync-status');
        var apiKey  = $('#aip_api_key').val().trim();

        if (!apiKey) {
            $status.css('color','red').text('⚠ Please enter your AIP API Key first and save settings.');
            return;
        }

        $btn.prop('disabled', true).text('⏳ Syncing...');
        $status.css('color','#666').text('');

        $.post(ajaxurl, {
            action:  'sas_sync_aip_models',
            nonce:   '<?php echo esc_js( wp_create_nonce( 'sas_sync_aip_models' ) ); ?>',
            api_key: apiKey
        }, function(res){
            if (res.success && res.data && res.data.models) {
                var $select = $('#aip_model');
                var current = $select.val();
                $select.empty();

                $.each(res.data.models, function(group, models){
                    var $optgroup = $('<optgroup>').attr('label', group);
                    $.each(models, function(id, name){
                        var $opt = $('<option>').val(id).text(name);
                        if (id === current) $opt.prop('selected', true);
                        $optgroup.append($opt);
                    });
                    $select.append($optgroup);
                });

                // Restore current selection or pick first
                if (!$select.val()) $select.find('option:first').prop('selected', true);

                $status.css('color','green').text('✅ ' + res.data.count + ' models loaded.');
            } else {
                $status.css('color','orange').text('ℹ ' + (res.data?.message || 'Showing built-in model list.'));
            }
        }).always(function(){
            $btn.prop('disabled', false).text('🔄 Sync from AIP');
        });
    });
});
</script>
