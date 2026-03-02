<?php
/**
 * Admin Settings — Qdrant, LLM, FCM, language configuration.
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

        <!-- ── Languages ───────────────────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Prediction Languages', 'sanathan-astro' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Select which languages to pre-fetch daily/weekly/yearly predictions for. More languages = more API calls per cron run.', 'sanathan-astro' ); ?></p>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Enabled Languages', 'sanathan-astro' ); ?></th>
                <td>
                    <?php foreach ( $langs as $code ) : ?>
                    <label style="margin-right:15px;">
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

        <!-- ── Personal Guruji (Qdrant) ───────────────────────────────────── -->
        <h2><?php esc_html_e( 'Personal Guruji — Qdrant Vector DB (Phase 2)', 'sanathan-astro' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Configure Qdrant for the AI Guruji knowledge base. Self-host Qdrant or use Qdrant Cloud.', 'sanathan-astro' ); ?></p>
        <table class="form-table">
            <tr>
                <th><label for="qdrant_url"><?php esc_html_e( 'Qdrant URL', 'sanathan-astro' ); ?></label></th>
                <td>
                    <input type="url" id="qdrant_url" name="sas_settings[qdrant_url]"
                        value="<?php echo esc_attr( $settings['qdrant_url'] ); ?>"
                        class="regular-text" placeholder="http://localhost:6333">
                    <p class="description"><?php esc_html_e( 'e.g. http://localhost:6333 or https://your-cluster.qdrant.io', 'sanathan-astro' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="qdrant_api_key"><?php esc_html_e( 'Qdrant API Key', 'sanathan-astro' ); ?></label></th>
                <td>
                    <input type="password" id="qdrant_api_key" name="sas_settings[qdrant_api_key]"
                        value="<?php echo esc_attr( $settings['qdrant_api_key'] ); ?>"
                        class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank for self-hosted without auth', 'sanathan-astro' ); ?>">
                </td>
            </tr>
            <tr>
                <th><label for="qdrant_threshold"><?php esc_html_e( 'Confidence Threshold', 'sanathan-astro' ); ?></label></th>
                <td>
                    <input type="number" id="qdrant_threshold" name="sas_settings[qdrant_confidence_threshold]"
                        value="<?php echo esc_attr( $settings['qdrant_confidence_threshold'] ); ?>"
                        min="0" max="1" step="0.05" class="small-text">
                    <p class="description"><?php esc_html_e( 'Score above this → answer from Qdrant (no LLM). Below → use LLM. Default: 0.75', 'sanathan-astro' ); ?></p>
                </td>
            </tr>
        </table>

        <!-- ── LLM / AI Fallback ──────────────────────────────────────────── -->
        <h2><?php esc_html_e( 'LLM Fallback (Phase 2)', 'sanathan-astro' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Used only when Qdrant cannot answer. Default: Claude Haiku (cheapest, fast).', 'sanathan-astro' ); ?></p>
        <table class="form-table">
            <tr>
                <th><label for="llm_provider"><?php esc_html_e( 'LLM Provider', 'sanathan-astro' ); ?></label></th>
                <td>
                    <select id="llm_provider" name="sas_settings[llm_provider]">
                        <option value="claude" <?php selected( $settings['llm_provider'], 'claude' ); ?>>Anthropic Claude</option>
                        <option value="openai" <?php selected( $settings['llm_provider'], 'openai' ); ?>>OpenAI</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="llm_api_key"><?php esc_html_e( 'LLM API Key', 'sanathan-astro' ); ?></label></th>
                <td>
                    <input type="password" id="llm_api_key" name="sas_settings[llm_api_key]"
                        value="<?php echo esc_attr( $settings['llm_api_key'] ); ?>"
                        class="regular-text">
                </td>
            </tr>
            <tr>
                <th><label for="llm_model"><?php esc_html_e( 'LLM Model', 'sanathan-astro' ); ?></label></th>
                <td>
                    <input type="text" id="llm_model" name="sas_settings[llm_model]"
                        value="<?php echo esc_attr( $settings['llm_model'] ); ?>"
                        class="regular-text" placeholder="claude-haiku-4-5-20251001">
                    <p class="description"><?php esc_html_e( 'Claude: claude-haiku-4-5-20251001 (cheapest) | OpenAI: gpt-4o-mini', 'sanathan-astro' ); ?></p>
                </td>
            </tr>
        </table>

        <!-- ── Firebase FCM ───────────────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Push Notifications — Firebase FCM (Phase 3)', 'sanathan-astro' ); ?></h2>
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
.sas-wrap { max-width: 900px; }
</style>
