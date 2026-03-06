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

        <!-- ── Qdrant Vector Search ────────────────────────────────────────── -->
        <h2>⚡ <?php esc_html_e( 'Qdrant Vector Search (RAG Knowledge Base)', 'sanathan-astro' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Qdrant stores the Vedic knowledge base and user Kundali summaries. Guruji searches here before answering — giving contextually accurate responses.', 'sanathan-astro' ); ?>
            <br>
            <?php esc_html_e( 'Collections needed: ', 'sanathan-astro' ); ?>
            <code>sanathan_knowledge</code> (Global search, 1536 dims, Cosine) &nbsp;|&nbsp;
            <code>user_kundali</code> (Multitenancy, 1536 dims, Cosine, tenant: user_id)
        </p>

        <table class="form-table">
            <!-- Qdrant URL -->
            <tr>
                <th><label for="qdrant_url"><?php esc_html_e( 'Qdrant URL', 'sanathan-astro' ); ?></label></th>
                <td>
                    <input type="url" id="qdrant_url" name="sas_settings[qdrant_url]"
                        value="<?php echo esc_attr( $settings['qdrant_url'] ?? '' ); ?>"
                        class="regular-text"
                        placeholder="https://xxxx.qdrant.io:6333">
                    <p class="description"><?php esc_html_e( 'Your Qdrant Cloud URL (with port if needed), or self-hosted URL. E.g. https://abc123.us-east4-0.gcp.cloud.qdrant.io:6333', 'sanathan-astro' ); ?></p>
                </td>
            </tr>

            <!-- Qdrant API Key -->
            <tr>
                <th><label for="qdrant_api_key"><?php esc_html_e( 'Qdrant API Key', 'sanathan-astro' ); ?></label></th>
                <td>
                    <input type="password" id="qdrant_api_key" name="sas_settings[qdrant_api_key]"
                        value="<?php echo esc_attr( $settings['qdrant_api_key'] ?? '' ); ?>"
                        class="regular-text" autocomplete="new-password"
                        placeholder="<?php esc_attr_e( 'Qdrant Cloud API Key', 'sanathan-astro' ); ?>">
                    <p class="description"><?php esc_html_e( 'From Qdrant Cloud → your cluster → API Keys. For self-hosted, leave blank (or set if you configured auth).', 'sanathan-astro' ); ?></p>
                </td>
            </tr>

            <!-- Connection Test -->
            <tr>
                <th></th>
                <td>
                    <button type="button" id="sas-test-qdrant" class="button button-secondary">
                        🔌 <?php esc_html_e( 'Test Qdrant Connection', 'sanathan-astro' ); ?>
                    </button>
                    <span id="sas-qdrant-status" style="margin-left:12px; color:#666;"></span>
                </td>
            </tr>
        </table>

        <!-- ── Google Gemini API ──────────────────────────────────────────── -->
        <h2>🌟 <?php esc_html_e( 'Google Gemini API (AI Tools)', 'sanathan-astro' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Used for generating featured images (Imagen) and SEO "How to Use" content (Gemini Flash) for AI Tool posts.', 'sanathan-astro' ); ?>
            <br>
            <?php esc_html_e( 'Get your API key from: ', 'sanathan-astro' ); ?>
            <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio → API Keys</a>
        </p>
        <table class="form-table">
            <tr>
                <th><label for="gemini_api_key"><?php esc_html_e( 'Gemini API Key', 'sanathan-astro' ); ?></label></th>
                <td>
                    <input type="password" id="gemini_api_key" name="sas_settings[gemini_api_key]"
                        value="<?php echo esc_attr( $settings['gemini_api_key'] ?? '' ); ?>"
                        class="regular-text" autocomplete="new-password"
                        placeholder="<?php esc_attr_e( 'AIzaSy...', 'sanathan-astro' ); ?>">
                    <p class="description"><?php esc_html_e( 'Required for AI Tools Setup: image generation (Imagen 3) and How-to-Use content generation (Gemini Flash).', 'sanathan-astro' ); ?></p>
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

    // ── Qdrant connection test ────────────────────────────────────────────────
    $('#sas-test-qdrant').on('click', function(){
        var $btn    = $(this);
        var $status = $('#sas-qdrant-status');
        var url     = $('#qdrant_url').val().trim();
        var key     = $('#qdrant_api_key').val().trim();

        if (!url) {
            $status.css('color','red').text('⚠ Please enter Qdrant URL first.');
            return;
        }

        $btn.prop('disabled', true).text('⏳ Testing...');
        $status.css('color','#666').text('');

        $.post(ajaxurl, {
            action:      'sas_test_qdrant',
            nonce:       '<?php echo esc_js( wp_create_nonce( 'sas_test_qdrant' ) ); ?>',
            qdrant_url:  url,
            qdrant_key:  key
        }, function(res){
            if (res.success && res.data) {
                var d = res.data;
                $status.css('color','green').html(
                    '✅ Connected! knowledge: <strong>' + d.knowledge + '</strong> docs, ' +
                    'user_kundali: <strong>' + d.kundali + '</strong> docs.'
                );
            } else {
                $status.css('color','red').text('❌ ' + (res.data?.message || 'Connection failed. Check URL and API key.'));
            }
        }).fail(function(){
            $status.css('color','red').text('❌ Request failed.');
        }).always(function(){
            $btn.prop('disabled', false).text('🔌 Test Qdrant Connection');
        });
    });

    // ── AIP model sync ───────────────────────────────────────────────────────
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
