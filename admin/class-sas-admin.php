<?php
/**
 * Admin UI for Sanathan Astro Services.
 *
 * Adds the "Astro Services" top-level menu with sub-pages:
 *   Dashboard  — overview stats + cron status
 *   Predictions — cache viewer + manual refresh
 *   Kundali     — stored Kundalis + tier management
 *   Settings    — Qdrant, LLM, FCM configuration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_Admin {

    public static function init(): void {
        add_action( 'admin_menu',    [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init',    [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_post_sas_refresh_predictions', [ __CLASS__, 'handle_refresh_predictions' ] );
        add_action( 'admin_post_sas_save_settings',       [ __CLASS__, 'handle_save_settings' ] );
        add_action( 'admin_enqueue_scripts',              [ __CLASS__, 'enqueue_assets' ] );
    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public static function add_menu(): void {
        $icon = 'dashicons-star-filled';

        add_menu_page(
            __( 'Astro Services', 'sanathan-astro' ),
            __( 'Astro Services', 'sanathan-astro' ),
            'manage_options',
            'sas-dashboard',
            [ __CLASS__, 'page_dashboard' ],
            $icon,
            56
        );

        add_submenu_page( 'sas-dashboard', __( 'Dashboard', 'sanathan-astro' ),    __( 'Dashboard', 'sanathan-astro' ),    'manage_options', 'sas-dashboard',    [ __CLASS__, 'page_dashboard' ] );
        add_submenu_page( 'sas-dashboard', __( 'Predictions', 'sanathan-astro' ),  __( 'Predictions', 'sanathan-astro' ),  'manage_options', 'sas-predictions',  [ __CLASS__, 'page_predictions' ] );
        add_submenu_page( 'sas-dashboard', __( 'Kundali', 'sanathan-astro' ),      __( 'Kundali', 'sanathan-astro' ),      'manage_options', 'sas-kundali',      [ __CLASS__, 'page_kundali' ] );
        add_submenu_page( 'sas-dashboard', __( 'Settings', 'sanathan-astro' ),     __( 'Settings', 'sanathan-astro' ),     'manage_options', 'sas-settings',     [ __CLASS__, 'page_settings' ] );
    }

    // ── Pages ─────────────────────────────────────────────────────────────────

    public static function page_dashboard(): void {
        require_once SAS_PLUGIN_DIR . 'admin/partials/dashboard.php';
    }

    public static function page_predictions(): void {
        require_once SAS_PLUGIN_DIR . 'admin/partials/predictions.php';
    }

    public static function page_kundali(): void {
        require_once SAS_PLUGIN_DIR . 'admin/partials/kundali.php';
    }

    public static function page_settings(): void {
        require_once SAS_PLUGIN_DIR . 'admin/partials/settings.php';
    }

    // ── Settings registration ─────────────────────────────────────────────────

    public static function register_settings(): void {
        register_setting( 'sas_settings_group', 'sas_settings', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
        ] );
    }

    public static function sanitize_settings( $input ): array {
        $clean = [];

        $clean['qdrant_url']                  = esc_url_raw( $input['qdrant_url'] ?? '' );
        $clean['qdrant_api_key']              = sanitize_text_field( $input['qdrant_api_key'] ?? '' );
        $clean['llm_provider']                = sanitize_text_field( $input['llm_provider'] ?? 'claude' );
        $clean['llm_api_key']                 = sanitize_text_field( $input['llm_api_key'] ?? '' );
        $clean['llm_model']                   = sanitize_text_field( $input['llm_model'] ?? 'claude-haiku-4-5-20251001' );
        $clean['fcm_server_key']              = sanitize_text_field( $input['fcm_server_key'] ?? '' );
        $clean['qdrant_confidence_threshold'] = floatval( $input['qdrant_confidence_threshold'] ?? 0.75 );

        $langs_input = $input['enabled_languages'] ?? SAS_SUPPORTED_LANGS;
        $clean['enabled_languages'] = array_values(
            array_filter(
                (array) $langs_input,
                fn( $l ) => in_array( $l, SAS_SUPPORTED_LANGS, true )
            )
        );
        if ( empty( $clean['enabled_languages'] ) ) {
            $clean['enabled_languages'] = [ 'en' ];
        }

        return $clean;
    }

    // ── Action handlers ───────────────────────────────────────────────────────

    public static function handle_refresh_predictions(): void {
        check_admin_referer( 'sas_refresh_predictions' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sanathan-astro' ) );
        }

        $job = sanitize_text_field( $_POST['job'] ?? 'daily' );

        // Map job name to WP-Cron hook
        $hook_map = [
            'daily'  => 'sas_daily_predictions',
            'weekly' => 'sas_weekly_predictions',
            'yearly' => 'sas_yearly_predictions',
        ];
        $hook = $hook_map[ $job ] ?? 'sas_daily_predictions';

        // Schedule as a one-off background event (runs via WP-Cron, not this request)
        wp_schedule_single_event( time(), $hook );

        // Trigger WP-Cron non-blocking so it fires immediately in the background
        spawn_cron();

        wp_safe_redirect( add_query_arg( [
            'page'    => 'sas-predictions',
            'message' => 'queued',
            'job'     => $job,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_save_settings(): void {
        check_admin_referer( 'sas_save_settings' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sanathan-astro' ) );
        }

        $data = self::sanitize_settings( $_POST['sas_settings'] ?? [] );
        update_option( 'sas_settings', $data );

        wp_safe_redirect( add_query_arg( [
            'page'    => 'sas-settings',
            'message' => 'saved',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'sas-' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'sas-admin',
            SAS_PLUGIN_URL . 'admin/css/admin.css',
            [],
            SAS_VERSION
        );
    }
}
