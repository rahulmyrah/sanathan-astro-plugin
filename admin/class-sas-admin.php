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

        // AJAX: sync AIP models into the model dropdown
        add_action( 'wp_ajax_sas_sync_aip_models', [ __CLASS__, 'handle_sync_aip_models' ] );

        // AJAX: test Qdrant connection
        add_action( 'wp_ajax_sas_test_qdrant',     [ __CLASS__, 'handle_test_qdrant' ] );

        // AJAX: index knowledge base into Qdrant
        add_action( 'wp_ajax_sas_index_knowledge', [ __CLASS__, 'handle_index_knowledge' ] );

        // AJAX: index all user Kundalis into Qdrant
        add_action( 'wp_ajax_sas_index_kundalis',  [ __CLASS__, 'handle_index_kundalis' ] );
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
        add_submenu_page( 'sas-dashboard', __( 'Settings', 'sanathan-astro' ),      __( 'Settings', 'sanathan-astro' ),      'manage_options', 'sas-settings',     [ __CLASS__, 'page_settings' ] );
        add_submenu_page( 'sas-dashboard', __( 'Knowledge Base', 'sanathan-astro' ), __( 'Knowledge Base', 'sanathan-astro' ), 'manage_options', 'sas-knowledge',    [ __CLASS__, 'page_knowledge' ] );
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

    public static function page_knowledge(): void {
        require_once SAS_PLUGIN_DIR . 'admin/partials/knowledge.php';
    }

    // ── Settings registration ─────────────────────────────────────────────────

    public static function register_settings(): void {
        register_setting( 'sas_settings_group', 'sas_settings', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
        ] );
    }

    public static function sanitize_settings( $input ): array {
        $clean = [];

        // ── AIP Integration ────────────────────────────────────────────────────
        $clean['aip_api_key']      = sanitize_text_field( $input['aip_api_key']      ?? '' );
        $clean['aip_model']        = sanitize_text_field( $input['aip_model']        ?? 'gpt-4o-mini' );
        $clean['aip_model_custom'] = sanitize_text_field( $input['aip_model_custom'] ?? '' );

        // ── Qdrant ─────────────────────────────────────────────────────────────
        $clean['qdrant_url']     = esc_url_raw( $input['qdrant_url']     ?? '' );
        $clean['qdrant_api_key'] = sanitize_text_field( $input['qdrant_api_key'] ?? '' );

        // ── FCM ────────────────────────────────────────────────────────────────
        $clean['fcm_server_key']  = sanitize_text_field( $input['fcm_server_key']  ?? '' );

        // ── Prediction languages ───────────────────────────────────────────────
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

    // ── AJAX: Sync AIP Models ─────────────────────────────────────────────────

    /**
     * AJAX handler: fetch available models from AIP and return as JSON.
     * Called by the "Sync from AIP" button in Settings.
     */
    public static function handle_sync_aip_models(): void {
        check_ajax_referer( 'sas_sync_aip_models', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        // Temporarily use the API key sent from the browser (user may not have saved yet)
        $api_key_from_ui = sanitize_text_field( $_POST['api_key'] ?? '' );

        // Inject the key temporarily so SAS_AIP_Client picks it up
        if ( $api_key_from_ui ) {
            add_filter( 'sas_aip_api_key_override', fn() => $api_key_from_ui );
        }

        $client = new SAS_AIP_Client();
        $models = $client->fetch_models();

        if ( empty( $models ) ) {
            wp_send_json_error( [
                'message' => 'Could not fetch models from AIP. Showing built-in list.',
                'models'  => SAS_AIP_Client::KNOWN_MODELS,
                'count'   => self::count_models( SAS_AIP_Client::KNOWN_MODELS ),
            ] );
        }

        wp_send_json_success( [
            'models' => $models,
            'count'  => self::count_models( $models ),
        ] );
    }

    /**
     * AJAX handler: test Qdrant connection using URL + key from the browser.
     */
    public static function handle_test_qdrant(): void {
        check_ajax_referer( 'sas_test_qdrant', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        // Temporarily inject URL + key so SAS_Qdrant picks them up
        $url = esc_url_raw( $_POST['qdrant_url'] ?? '' );
        $key = sanitize_text_field( $_POST['qdrant_key'] ?? '' );

        add_filter( 'sas_qdrant_url_override',     fn() => $url );
        add_filter( 'sas_qdrant_api_key_override', fn() => $key );

        $qdrant = new SAS_Qdrant();
        $result = $qdrant->health_check();

        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ?? 'Connection failed.' ] );
        }

        wp_send_json_success( [
            'knowledge' => $result['collections']['knowledge'] ?? 0,
            'kundali'   => $result['collections']['kundali']   ?? 0,
        ] );
    }

    /**
     * AJAX handler: index all Vedic knowledge into Qdrant.
     */
    public static function handle_index_knowledge(): void {
        check_ajax_referer( 'sas_index_knowledge', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $result = SAS_Knowledge::index_all();

        if ( empty( $result['success'] ) && ! isset( $result['indexed'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ?? 'Indexing failed.' ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX handler: index all existing user Kundalis into Qdrant.
     */
    public static function handle_index_kundalis(): void {
        check_ajax_referer( 'sas_index_kundalis', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        global $wpdb;
        $table    = $wpdb->prefix . 'sanathan_kundali';
        $user_ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM `{$table}` WHERE lang = 'en'" );

        $indexed = 0;
        $errors  = 0;

        foreach ( $user_ids as $uid ) {
            if ( SAS_Knowledge::index_kundali( (int) $uid ) ) {
                $indexed++;
            } else {
                $errors++;
            }
        }

        wp_send_json_success( [
            'indexed' => $indexed,
            'errors'  => $errors,
            'total'   => count( $user_ids ),
        ] );
    }

    /**
     * Count total models across all groups.
     */
    private static function count_models( array $grouped ): int {
        return (int) array_sum( array_map( 'count', $grouped ) );
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

        // jQuery is always available in WP admin — no need to enqueue separately.
    }
}
