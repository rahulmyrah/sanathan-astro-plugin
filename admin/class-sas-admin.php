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

        // AJAX: AI Tools Setup
        add_action( 'wp_ajax_sas_setup_ai_categories',    [ __CLASS__, 'handle_setup_ai_categories' ] );
        add_action( 'wp_ajax_sas_import_ai_tools',         [ __CLASS__, 'handle_import_ai_tools' ] );
        add_action( 'wp_ajax_sas_update_existing_ai_tools',[ __CLASS__, 'handle_update_existing_ai_tools' ] );
        add_action( 'wp_ajax_sas_generate_ai_tool_images', [ __CLASS__, 'handle_generate_ai_tool_images' ] );
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
        add_submenu_page( 'sas-dashboard', __( 'AI Tools Setup', 'sanathan-astro' ), __( 'AI Tools Setup', 'sanathan-astro' ), 'manage_options', 'sas-ai-tools-setup', [ __CLASS__, 'page_ai_tools_setup' ] );
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

    public static function page_ai_tools_setup(): void {
        require_once SAS_PLUGIN_DIR . 'admin/partials/ai-tools-setup.php';
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

        // ── Gemini ─────────────────────────────────────────────────────────────
        $clean['gemini_api_key'] = sanitize_text_field( $input['gemini_api_key'] ?? '' );

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

    // ── AI Tools Setup AJAX handlers ──────────────────────────────────────────

    /**
     * The 7 canonical categories for AI Tools.
     */
    private static function ai_tool_categories(): array {
        return [
            'Vedic Astrology',
            'Bhakti & Devotion',
            'Mantra & Prayer',
            'Hindu Scriptures',
            'Spiritual Life',
            'Vaastu & Remedies',
            'Festivals & Rituals',
        ];
    }

    /**
     * Detect category from form/post title keywords.
     */
    private static function detect_ai_tool_category( string $title ): string {
        $t   = strtolower( $title );
        $map = [
            'Vedic Astrology'    => [ 'rashi', 'kundali', 'astrology', 'horoscope', 'zodiac', 'nakshatra', 'dasha', 'planetary', 'jyotish', 'planet', 'birth chart', 'birth-chart' ],
            'Mantra & Prayer'    => [ 'mantra', 'aarti', 'chant', 'archana', 'namavali', 'nanavati', 'prayer', 'stotram' ],
            'Bhakti & Devotion'  => [ 'bhakti', 'bhajan', 'devotion', 'devotional', 'slokas', 'kirtan', 'spiritual practice', 'habit', 'challenge' ],
            'Hindu Scriptures'   => [ 'gita', 'bhagavad', 'geeta', 'upanishad', 'ramayana', 'mahabharat', 'purana', 'chapter' ],
            'Vaastu & Remedies'  => [ 'vaastu', 'vastu', 'remedy', 'remedies', 'dosh', 'dosha', 'mangal', 'gemstone', 'feng' ],
            'Festivals & Rituals'=> [ 'festival', 'puja', 'ritual', 'yatra', 'dham', 'daan', 'charity', 'pilgrimage' ],
        ];
        foreach ( $map as $category => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( str_contains( $t, $kw ) ) {
                    return $category;
                }
            }
        }
        return 'Spiritual Life';
    }

    /**
     * Auto-generate tags from title keywords.
     */
    private static function generate_ai_tool_tags( string $title, string $category ): array {
        $tags   = [];
        $words  = preg_split( '/[\s\/\-&]+/', strtolower( $title ) );
        $stop   = [ 'the', 'a', 'an', 'and', 'or', 'for', 'of', 'in', 'to', 'by', 'with', 'how', 'use', 'tool', 'guide', 'imported' ];
        foreach ( $words as $w ) {
            if ( strlen( $w ) > 3 && ! in_array( $w, $stop, true ) ) {
                $tags[] = ucfirst( $w );
            }
        }
        $tags[] = $category;
        return array_unique( $tags );
    }

    /**
     * Non-Sanathan form titles to skip.
     */
    private static function ai_tools_skip_list(): array {
        return [ 'blog post generator', 'customer support reply builder' ];
    }

    /**
     * Call Gemini text API to generate SEO "How to Use" content.
     */
    private static function gemini_generate_seo_content( string $title, string $category, string $api_key ): string {
        $prompt = "Write SEO-optimized HTML content for a Hindu spiritual AI tool called \"{$title}\" in the category \"{$category}\".\n"
            . "Include:\n"
            . "1. A 2-sentence introduction paragraph about this tool (use spiritual keywords naturally, wrap in <p class=\"sas-tool-intro\">)\n"
            . "2. <h2>How to Use {$title}</h2> with <ol> of 3-5 steps explaining how to use the form fields\n"
            . "3. <h2>What Results to Expect</h2> with a <p> describing what the AI generates\n"
            . "4. <h2>Tips for Best Results</h2> with <ul> of 3-4 tips\n"
            . "Keep tone: warm, spiritual, helpful. Target: Hindu spiritual seekers in India.\n"
            . "Return clean HTML only — no markdown, no code fences, no DOCTYPE.";

        $response = wp_remote_post(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . rawurlencode( $api_key ),
            [
                'timeout' => 30,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'contents' => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ],
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $html = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Strip any accidental markdown code fences
        $html = preg_replace( '/^```html?\s*/i', '', trim( $html ) );
        $html = preg_replace( '/\s*```$/', '', $html );

        return wp_kses_post( trim( $html ) );
    }

    /**
     * AJAX: Create the 7 AI Tool categories.
     */
    public static function handle_setup_ai_categories(): void {
        check_ajax_referer( 'sas_setup_ai_categories', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $taxonomy = 'ai_tool_category';
        $created  = 0;
        $existing = 0;

        foreach ( self::ai_tool_categories() as $name ) {
            if ( term_exists( $name, $taxonomy ) ) {
                $existing++;
            } else {
                $result = wp_insert_term( $name, $taxonomy );
                if ( ! is_wp_error( $result ) ) {
                    $created++;
                }
            }
        }

        wp_send_json_success( [
            'created'  => $created,
            'existing' => $existing,
            'total'    => count( self::ai_tool_categories() ),
        ] );
    }

    /**
     * AJAX: Bulk-import AIP forms as AI Tool posts.
     */
    public static function handle_import_ai_tools(): void {
        check_ajax_referer( 'sas_import_ai_tools', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        set_time_limit( 600 );

        $settings    = sas_get_settings();
        $gemini_key  = $settings['gemini_api_key'] ?? '';
        $skip_list   = self::ai_tools_skip_list();
        $cpt         = 'ai_tool';
        $taxonomy    = 'ai_tool_category';
        $tag_tax     = 'ai_tool_tag';

        // Get all AIP forms
        global $wpdb;
        $forms = $wpdb->get_results(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'wpaicg_form' AND post_status = 'publish' ORDER BY post_title ASC"
        );

        // Get existing AI Tool post titles (lowercase) to detect duplicates
        $existing_titles = $wpdb->get_col(
            "SELECT LOWER(post_title) FROM {$wpdb->posts} WHERE post_type = '{$cpt}' AND post_status != 'trash'"
        );

        $imported = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ( $forms as $form ) {
            $title_lower = strtolower( $form->post_title );

            // Skip non-Sanathan forms
            $is_skip = false;
            foreach ( $skip_list as $skip ) {
                if ( str_contains( $title_lower, $skip ) ) {
                    $is_skip = true;
                    break;
                }
            }
            if ( $is_skip ) {
                $skipped++;
                continue;
            }

            // Skip if already exists
            if ( in_array( $title_lower, $existing_titles, true ) ) {
                $skipped++;
                continue;
            }

            $category = self::detect_ai_tool_category( $form->post_title );
            $tags     = self::generate_ai_tool_tags( $form->post_title, $category );

            // Generate SEO content
            $seo_html = '';
            $excerpt  = '';
            if ( $gemini_key ) {
                $seo_html = self::gemini_generate_seo_content( $form->post_title, $category, $gemini_key );
                // Extract plain-text excerpt from intro paragraph
                if ( $seo_html ) {
                    preg_match( '/<p[^>]*class="sas-tool-intro"[^>]*>(.*?)<\/p>/is', $seo_html, $m );
                    $excerpt = $m[1] ?? '';
                    $excerpt = wp_strip_all_tags( $excerpt );
                }
            }

            $shortcode   = '[aipkit_ai_form id=' . $form->ID . ']';
            $post_content = $shortcode;
            if ( $seo_html ) {
                $post_content .= "\n\n<div class=\"sas-tool-guide\">\n" . $seo_html . "\n</div>";
            }

            $post_id = wp_insert_post( [
                'post_type'    => $cpt,
                'post_title'   => $form->post_title,
                'post_content' => $post_content,
                'post_excerpt' => $excerpt,
                'post_status'  => 'publish',
            ] );

            if ( is_wp_error( $post_id ) || ! $post_id ) {
                $errors++;
                continue;
            }

            // Assign category
            $term = get_term_by( 'name', $category, $taxonomy );
            if ( $term ) {
                wp_set_object_terms( $post_id, [ $term->term_id ], $taxonomy );
            }

            // Assign tags
            wp_set_object_terms( $post_id, $tags, $tag_tax );

            $imported++;
        }

        wp_send_json_success( [
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'total'    => count( $forms ),
        ] );
    }

    /**
     * AJAX: Update categories + tags on existing AI Tool posts that lack them.
     */
    public static function handle_update_existing_ai_tools(): void {
        check_ajax_referer( 'sas_update_existing_ai_tools', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        set_time_limit( 300 );

        $settings   = sas_get_settings();
        $gemini_key = $settings['gemini_api_key'] ?? '';
        $cpt        = 'ai_tool';
        $taxonomy   = 'ai_tool_category';
        $tag_tax    = 'ai_tool_tag';

        $posts = get_posts( [
            'post_type'      => $cpt,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ] );

        $updated = 0;
        $skipped = 0;

        foreach ( $posts as $post ) {
            $has_cat = ! empty( wp_get_object_terms( $post->ID, $taxonomy ) );
            $has_tag = ! empty( wp_get_object_terms( $post->ID, $tag_tax ) );

            $category = self::detect_ai_tool_category( $post->post_title );
            $tags     = self::generate_ai_tool_tags( $post->post_title, $category );

            if ( ! $has_cat ) {
                $term = get_term_by( 'name', $category, $taxonomy );
                if ( $term ) {
                    wp_set_object_terms( $post->ID, [ $term->term_id ], $taxonomy );
                }
            }

            if ( ! $has_tag ) {
                wp_set_object_terms( $post->ID, $tags, $tag_tax );
            }

            // Add SEO content if post_content has no .sas-tool-guide yet
            if ( $gemini_key && strpos( $post->post_content, 'sas-tool-guide' ) === false ) {
                $seo_html = self::gemini_generate_seo_content( $post->post_title, $category, $gemini_key );
                if ( $seo_html ) {
                    $new_content = $post->post_content . "\n\n<div class=\"sas-tool-guide\">\n" . $seo_html . "\n</div>";
                    preg_match( '/<p[^>]*class="sas-tool-intro"[^>]*>(.*?)<\/p>/is', $seo_html, $m );
                    $excerpt = wp_strip_all_tags( $m[1] ?? '' );

                    wp_update_post( [
                        'ID'           => $post->ID,
                        'post_content' => $new_content,
                        'post_excerpt' => $excerpt ?: $post->post_excerpt,
                    ] );
                }
            }

            if ( ! $has_cat || ! $has_tag ) {
                $updated++;
            } else {
                $skipped++;
            }
        }

        wp_send_json_success( [
            'updated' => $updated,
            'skipped' => $skipped,
            'total'   => count( $posts ),
        ] );
    }

    /**
     * AJAX: Generate featured images for AI Tool posts via Gemini Imagen API.
     */
    public static function handle_generate_ai_tool_images(): void {
        check_ajax_referer( 'sas_generate_ai_tool_images', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $settings   = sas_get_settings();
        $api_key    = $settings['gemini_api_key'] ?? '';

        if ( ! $api_key ) {
            wp_send_json_error( [ 'message' => 'Gemini API key not configured. Go to Settings first.' ] );
        }

        set_time_limit( 600 );
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $cpt = 'ai_tool';

        // Only posts without a featured image
        $posts = get_posts( [
            'post_type'      => $cpt,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [ [
                'key'     => '_thumbnail_id',
                'compare' => 'NOT EXISTS',
            ] ],
        ] );

        $generated = 0;
        $errors    = 0;
        $endpoint  = 'https://generativelanguage.googleapis.com/v1beta/models/imagen-3.0-generate-001:predict?key=' . rawurlencode( $api_key );

        foreach ( $posts as $post ) {
            $category = self::detect_ai_tool_category( $post->post_title );
            $prompt   = "Sacred Hindu spiritual illustration for \"{$post->post_title}\" — {$category} theme, warm saffron and gold tones, divine aesthetic, intricate mandala elements, no text, no watermark, photorealistic style";

            $response = wp_remote_post( $endpoint, [
                'timeout' => 60,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'instances'  => [ [ 'prompt' => $prompt ] ],
                    'parameters' => [ 'sampleCount' => 1 ],
                ] ),
            ] );

            if ( is_wp_error( $response ) ) {
                $errors++;
                continue;
            }

            $body     = json_decode( wp_remote_retrieve_body( $response ), true );
            $b64      = $body['predictions'][0]['bytesBase64Encoded'] ?? '';
            $mime     = $body['predictions'][0]['mimeType'] ?? 'image/png';
            $ext      = ( $mime === 'image/jpeg' ) ? 'jpg' : 'png';

            if ( ! $b64 ) {
                $errors++;
                continue;
            }

            $image_data = base64_decode( $b64 );
            $filename   = 'ai-tool-' . $post->ID . '.' . $ext;
            $upload     = wp_upload_bits( $filename, null, $image_data );

            if ( ! empty( $upload['error'] ) ) {
                $errors++;
                continue;
            }

            $attachment_id = wp_insert_attachment( [
                'post_mime_type' => $mime,
                'post_title'     => sanitize_file_name( $post->post_title ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ], $upload['file'], $post->ID );

            if ( is_wp_error( $attachment_id ) ) {
                $errors++;
                continue;
            }

            $attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
            wp_update_attachment_metadata( $attachment_id, $attach_data );
            set_post_thumbnail( $post->ID, $attachment_id );

            $generated++;
            usleep( 500000 ); // 0.5s rate-limit pause
        }

        wp_send_json_success( [
            'generated' => $generated,
            'errors'    => $errors,
            'total'     => count( $posts ),
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
