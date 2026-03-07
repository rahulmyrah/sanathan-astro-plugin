<?php
/**
 * Plugin Name:       Sanathan Astro Services
 * Plugin URI:        https://sanathan.app
 * Description:       Cached Predictions, Kundali storage, Personal Guruji AI (Qdrant RAG), and FCM push notifications for the Sanathan Astrology platform. Powers the Flutter mobile app via REST API.
 * Version:           1.4.5
 * Author:            Sanathan App
 * Author URI:        https://sanathan.app
 * License:           GPL-2.0+
 * Text Domain:       sanathan-astro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Constants ────────────────────────────────────────────────────────────────

define( 'SAS_VERSION',     '1.4.5' );
define( 'SAS_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'SAS_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'SAS_PLUGIN_FILE', __FILE__ );

// Vedic Astro API base (same as existing plugin constant, defined here as fallback)
if ( ! defined( 'VEDIC_ASTRO_API_ROOT_URL' ) ) {
    define( 'VEDIC_ASTRO_API_ROOT_URL', 'https://api.vedicastroapi.com/v3-json/' );
}

// All 9 supported languages for predictions caching
define( 'SAS_SUPPORTED_LANGS', [
    'en', 'hi', 'ta', 'te', 'ka', 'ml', 'be', 'sp', 'fr',
] );

// All 12 Western zodiac signs (used for predictions)
define( 'SAS_ZODIACS', [
    'aries', 'taurus', 'gemini', 'cancer', 'leo', 'virgo',
    'libra', 'scorpio', 'sagittarius', 'capricorn', 'aquarius', 'pisces',
] );

// Tier constants
define( 'SAS_TIER_FREE', 'free' );
define( 'SAS_TIER_CORE', 'core' );
define( 'SAS_TIER_FULL', 'full' );

// ─── Load Dependencies ────────────────────────────────────────────────────────

require_once SAS_PLUGIN_DIR . 'includes/class-sas-db.php';
require_once SAS_PLUGIN_DIR . 'includes/class-sas-api-client.php';
require_once SAS_PLUGIN_DIR . 'includes/class-sas-predictions.php';
require_once SAS_PLUGIN_DIR . 'includes/class-sas-kundali.php';
require_once SAS_PLUGIN_DIR . 'includes/class-sas-cron.php';
require_once SAS_PLUGIN_DIR . 'includes/class-sas-aip-client.php';
require_once SAS_PLUGIN_DIR . 'includes/class-sas-qdrant.php';
require_once SAS_PLUGIN_DIR . 'includes/class-sas-knowledge.php';
require_once SAS_PLUGIN_DIR . 'includes/class-sas-guruji.php';
require_once SAS_PLUGIN_DIR . 'includes/class-sas-rest-api.php';
require_once SAS_PLUGIN_DIR . 'includes/class-sas-updater.php';
require_once SAS_PLUGIN_DIR . 'includes/class-sas-ai-tools-frontend.php';

if ( is_admin() ) {
    require_once SAS_PLUGIN_DIR . 'admin/class-sas-admin.php';
}

// ─── Activation / Deactivation ────────────────────────────────────────────────

register_activation_hook( __FILE__, [ 'SAS_DB', 'create_tables' ] );
register_deactivation_hook( __FILE__, [ 'SAS_Cron', 'deactivate' ] );

// ─── Boot ─────────────────────────────────────────────────────────────────────

add_action( 'plugins_loaded', 'sas_boot' );

function sas_boot() {
    // Register cron schedules + hooks
    SAS_Cron::init();

    // Register REST API routes
    add_action( 'rest_api_init', [ 'SAS_Rest_Api', 'register_routes' ] );

    // Admin UI
    if ( is_admin() ) {
        SAS_Admin::init();
    }

    // AI Tools frontend templates, assets, and taxonomy registration
    SAS_AI_Tools_Frontend::init();

    // ── GitHub auto-updater ───────────────────────────────────────────────────
    // Fetches plugin-info.json from GitHub main branch (repo root).
    // When SAS_VERSION < remote version, WordPress shows "Update Available".
    //
    // HOW TO RELEASE:
    //   1. Bump SAS_VERSION in this file + plugin-info.json (version + download_url tag).
    //   2. git add -A && git commit -m "Release vX.Y.Z" && git push
    //   3. git archive --format=zip --prefix=sanathan-app/ HEAD -o sanathan-app.zip
    //   4. GitHub → Releases → Draft new release → tag vX.Y.Z → upload sanathan-app.zip
    //   5. WP Admin → Plugins → "Update Available" appears. Click Update Now. Done.
    //
    new SAS_Updater(
        'https://raw.githubusercontent.com/rahulmyrah/sanathan-astro-plugin/main/plugin-info.json'
    );
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Return the plugin settings array.
 *
 * @return array
 */
function sas_get_settings(): array {
    $defaults = [
        // AIP (AI Power plugin) integration
        'aip_api_key'      => '',
        'aip_model'        => 'gpt-4o-mini',
        'aip_model_custom' => '',

        // Qdrant vector search
        'qdrant_url'     => '',
        'qdrant_api_key' => '',

        // Google Gemini API (image + text generation for AI Tools)
        'gemini_api_key' => '',

        // Firebase FCM (Phase 3)
        'fcm_server_key'   => '',

        // Prediction language caching
        'enabled_languages' => SAS_SUPPORTED_LANGS,
    ];

    $saved = get_option( 'sas_settings', [] );
    if ( ! is_array( $saved ) ) {
        $saved = [];
    }

    return array_merge( $defaults, $saved );
}

/**
 * Return the VedicAstroAPI key (reads from existing plugin's option first).
 *
 * @return string
 */
function sas_get_vedic_api_key(): string {
    // Try existing vedicastroapi plugin option
    $existing = get_option( 'vedicastro_setting', [] );
    if ( is_array( $existing ) && ! empty( $existing['vedicastro_apikey'] ) ) {
        return (string) $existing['vedicastro_apikey'];
    }

    // Fallback: own settings
    $settings = sas_get_settings();
    return (string) ( $settings['vedic_api_key'] ?? '' );
}
