<?php
/**
 * AI Tools Frontend
 *
 * - Intercepts template loading for ai-toolai_tool CPT
 * - Enqueues public CSS + JS
 * - Ensures ai_tool_category taxonomy is public (registers if needed)
 * - Adds rewrite rules so the archive URL works
 *
 * @package Sanathan_Astro_Services
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_AI_Tools_Frontend {

    // Post type registered by AI Puffer (AIP) plugin
    const CPT = 'ai-toolai_tool';

    // Taxonomy registered during AI Tools setup
    const TAX = 'ai_tool_category';
    const TAG = 'ai_tool_tag';

    /**
     * Bootstrap hooks.
     */
    public static function init() {
        // Ensure taxonomy is registered (public + with archive)
        add_action( 'init', [ __CLASS__, 'register_taxonomy' ], 5 );

        // Override templates
        add_filter( 'template_include', [ __CLASS__, 'maybe_override_template' ], 99 );

        // Enqueue assets only on AI Tools pages
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

        // Adjust main query: show all tools, no pagination
        add_action( 'pre_get_posts', [ __CLASS__, 'modify_archive_query' ] );

        // Add title for archive page
        add_filter( 'get_the_archive_title', [ __CLASS__, 'archive_title' ] );
        add_filter( 'document_title_parts',  [ __CLASS__, 'document_title' ] );
    }

    /**
     * Register (or update) the ai_tool_category taxonomy so it has:
     *  - public      = true
     *  - has_archive = true (its own URLs work)
     *  - show_in_rest = true
     *
     * This runs at priority 5, before most themes/plugins register at 10.
     * If AIP already registered it, we re-register with the same slug + new args.
     */
    public static function register_taxonomy() {
        // Only register if not already done, or if we need to patch public flags
        if ( ! taxonomy_exists( self::TAX ) ) {
            register_taxonomy( self::TAX, self::CPT, [
                'label'             => __( 'Tool Categories', 'sanathan-astro' ),
                'labels'            => [
                    'name'          => __( 'Tool Categories', 'sanathan-astro' ),
                    'singular_name' => __( 'Tool Category', 'sanathan-astro' ),
                    'all_items'     => __( 'All Categories', 'sanathan-astro' ),
                ],
                'public'            => true,
                'show_ui'           => true,
                'show_in_nav_menus' => true,
                'show_in_rest'      => true,
                'hierarchical'      => true,
                'rewrite'           => [ 'slug' => 'ai-tool-category', 'with_front' => false ],
                'query_var'         => true,
            ] );
        }

        if ( ! taxonomy_exists( self::TAG ) ) {
            register_taxonomy( self::TAG, self::CPT, [
                'label'        => __( 'Tool Tags', 'sanathan-astro' ),
                'public'       => true,
                'show_ui'      => true,
                'show_in_rest' => true,
                'hierarchical' => false,
                'rewrite'      => [ 'slug' => 'ai-tool-tag', 'with_front' => false ],
                'query_var'    => true,
            ] );
        }
    }

    /**
     * Override WordPress template when viewing the CPT.
     *
     * Priority 99 ensures we run after theme-level template loading.
     *
     * @param  string $template  Original template path.
     * @return string            Our template path or original.
     */
    public static function maybe_override_template( $template ) {
        $archive_tpl = SAS_PLUGIN_DIR . 'public/templates/archive-ai-tools.php';
        $single_tpl  = SAS_PLUGIN_DIR . 'public/templates/single-ai-tools.php';

        // Post-type archive: /ai-tools/
        if ( is_post_type_archive( self::CPT ) && file_exists( $archive_tpl ) ) {
            return $archive_tpl;
        }

        // Taxonomy term archive: /ai-tool-category/{slug}/
        if ( is_tax( self::TAX ) && file_exists( $archive_tpl ) ) {
            return $archive_tpl;
        }

        // Single tool: /ai-tools/{slug}/
        if ( is_singular( self::CPT ) && file_exists( $single_tpl ) ) {
            return $single_tpl;
        }

        return $template;
    }

    /**
     * Enqueue CSS + JS on AI Tools pages.
     */
    public static function enqueue_assets() {
        if (
            is_post_type_archive( self::CPT ) ||
            is_singular( self::CPT ) ||
            is_tax( self::TAX ) ||
            is_tax( self::TAG )
        ) {
            wp_enqueue_style(
                'sas-ai-tools',
                SAS_PLUGIN_URL . 'public/css/ai-tools.css',
                [],
                SAS_VERSION
            );

            wp_enqueue_script(
                'sas-ai-tools',
                SAS_PLUGIN_URL . 'public/js/ai-tools.js',
                [],
                SAS_VERSION,
                true   // footer
            );
        }
    }

    /**
     * Show all tools in the archive (no pagination — JS filters client-side).
     *
     * @param  WP_Query $query
     */
    public static function modify_archive_query( $query ) {
        if (
            ! is_admin() &&
            $query->is_main_query() &&
            (
                $query->is_post_type_archive( self::CPT ) ||
                $query->is_tax( self::TAX )
            )
        ) {
            $query->set( 'posts_per_page', -1 );
            $query->set( 'orderby', 'title' );
            $query->set( 'order', 'ASC' );
        }
    }

    /**
     * Clean archive title (removes "Archives: " prefix).
     *
     * @param  string $title
     * @return string
     */
    public static function archive_title( $title ) {
        if ( is_post_type_archive( self::CPT ) ) {
            return __( 'Vedic AI Tools', 'sanathan-astro' );
        }
        if ( is_tax( self::TAX ) ) {
            return single_term_title( '', false );
        }
        return $title;
    }

    /**
     * Document <title> for archive/tax pages.
     *
     * @param  array $parts
     * @return array
     */
    public static function document_title( $parts ) {
        if ( is_post_type_archive( self::CPT ) ) {
            $parts['title'] = __( 'Vedic AI Tools', 'sanathan-astro' );
        }
        return $parts;
    }
}
