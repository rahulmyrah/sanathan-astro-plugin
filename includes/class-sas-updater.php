<?php
/**
 * Self-hosted plugin updater.
 *
 * Hooks into WordPress's native update mechanism to check a remote JSON
 * metadata file (hosted on GitHub or any public URL) for a newer version.
 *
 * Workflow:
 *   1. Bump SAS_VERSION in sanathan-astro-services.php.
 *   2. Run make-zip.ps1 → sanathan-astro-services.zip.
 *   3. Create a GitHub Release and upload the ZIP as an asset.
 *   4. Update plugin-info.json on GitHub (or any static host).
 *   5. WordPress shows "Update Available" → click Update Now. Done.
 *
 * @package SAS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_Updater {

    /** Remote JSON metadata URL — set via constructor or filter. */
    private string $metadata_url;

    /** Plugin basename: folder/main-file.php */
    private string $plugin_file;

    /** Slug (folder name, no .php) */
    private string $plugin_slug;

    /** Transient cache key */
    private string $cache_key = 'sas_remote_update_info';

    /** Cache lifetime (default: 6 hours) */
    private int $cache_ttl;

    // ── Boot ───────────────────────────────────────────────────────────────────

    public function __construct( string $metadata_url, int $cache_ttl = 6 * HOUR_IN_SECONDS ) {
        $this->metadata_url = $metadata_url;
        $this->plugin_file  = plugin_basename( SAS_PLUGIN_FILE );
        $this->plugin_slug  = dirname( $this->plugin_file ); // 'sanathan-astro-services'
        $this->cache_ttl    = $cache_ttl;

        // Inject into WP update pipeline
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update'   ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info'    ], 20, 3 );
        add_filter( 'upgrader_source_selection',             [ $this, 'fix_source_dir' ], 10, 4 );

        // Add "View details" link in the plugin list
        add_filter( 'plugin_action_links_' . $this->plugin_file, [ $this, 'add_action_links' ] );

        // Invalidate cache when WP manually triggers an update check
        add_action( 'delete_site_transient_update_plugins', [ $this, 'clear_cache' ] );
    }

    // ── WP hooks ───────────────────────────────────────────────────────────────

    /**
     * Tell WordPress about a newer remote version (if available).
     */
    public function inject_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $remote = $this->fetch_metadata();
        if ( ! $remote || empty( $remote->version ) ) {
            return $transient;
        }

        if ( version_compare( SAS_VERSION, $remote->version, '<' ) ) {
            $transient->response[ $this->plugin_file ] = (object) [
                'id'            => $this->plugin_slug,
                'slug'          => $this->plugin_slug,
                'plugin'        => $this->plugin_file,
                'new_version'   => $remote->version,
                'url'           => $remote->homepage    ?? 'https://sanathan.app',
                'package'       => $remote->download_url,
                'icons'         => [],
                'banners'       => [],
                'requires'      => $remote->requires    ?? '5.8',
                'tested'        => $remote->tested      ?? '6.9',
                'requires_php'  => $remote->requires_php ?? '7.4',
            ];
        } else {
            // No update — make sure it's not in the "needs update" list
            if ( isset( $transient->response[ $this->plugin_file ] ) ) {
                unset( $transient->response[ $this->plugin_file ] );
            }
        }

        return $transient;
    }

    /**
     * Provide plugin details for the "View version X.X details" modal.
     */
    public function plugin_info( $result, string $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }
        if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
            return $result;
        }

        $remote = $this->fetch_metadata();
        if ( ! $remote ) {
            return $result;
        }

        return (object) [
            'name'            => $remote->name          ?? 'Sanathan Astro Services',
            'slug'            => $this->plugin_slug,
            'version'         => $remote->version,
            'author'          => $remote->author        ?? '<a href="https://sanathan.app">Sanathan App</a>',
            'homepage'        => $remote->homepage      ?? 'https://sanathan.app',
            'requires'        => $remote->requires      ?? '5.8',
            'tested'          => $remote->tested        ?? '6.9',
            'requires_php'    => $remote->requires_php  ?? '7.4',
            'download_link'   => $remote->download_url,
            'last_updated'    => $remote->last_updated  ?? gmdate( 'Y-m-d' ),
            'sections'        => [
                'description' => $remote->description  ?? 'Sanathan Astrology backend services.',
                'changelog'   => $remote->changelog    ?? 'See GitHub releases.',
            ],
        ];
    }

    /**
     * GitHub archives/zips often unpack as `{repo}-{tag}/` instead of the
     * expected plugin folder name. Rename the extracted directory so WP is happy.
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = [] ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
            return $source;
        }

        $correct_dir = trailingslashit( $remote_source ) . $this->plugin_slug . '/';

        if ( trailingslashit( $source ) !== $correct_dir ) {
            global $wp_filesystem;
            if ( $wp_filesystem && $wp_filesystem->is_dir( $source ) ) {
                $wp_filesystem->move( $source, $correct_dir );
                return $correct_dir;
            }
        }

        return $source;
    }

    /**
     * Add a "Check for updates" link in WP Admin → Plugins list.
     */
    public function add_action_links( array $links ): array {
        $check_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'update-core.php?force-check=1' ) ),
            esc_html__( 'Check for updates', 'sanathan-astro' )
        );
        array_unshift( $links, $check_link );
        return $links;
    }

    /**
     * Clear the cached metadata (called when WP flushes its update transient).
     */
    public function clear_cache(): void {
        delete_transient( $this->cache_key );
    }

    // ── Remote metadata fetch ─────────────────────────────────────────────────

    /**
     * Fetch and cache the remote metadata JSON.
     *
     * Expected JSON shape:
     * {
     *   "version":      "1.0.1",
     *   "download_url": "https://github.com/user/repo/releases/download/v1.0.1/sanathan-astro-services.zip",
     *   "requires":     "5.8",
     *   "tested":       "6.9.1",
     *   "requires_php": "7.4",
     *   "name":         "Sanathan Astro Services",
     *   "author":       "<a href=\"https://sanathan.app\">Sanathan App</a>",
     *   "homepage":     "https://sanathan.app",
     *   "last_updated": "2026-03-02",
     *   "description":  "Cached Predictions, Kundali, Guruji AI and push notifications.",
     *   "changelog":    "<h4>1.0.1</h4><ul><li>Fixed zodiac numeric ID for VedicAstroAPI</li></ul>"
     * }
     *
     * @return object|null  Decoded JSON object, or null on failure.
     */
    private function fetch_metadata(): ?object {
        $cached = get_transient( $this->cache_key );
        if ( $cached !== false ) {
            return ( $cached === 'none' ) ? null : $cached;
        }

        $response = wp_remote_get(
            $this->metadata_url,
            [
                'timeout'    => 10,
                'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
            ]
        );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Cache the failure for 30 min so we don't hammer on error
            set_transient( $this->cache_key, 'none', 30 * MINUTE_IN_SECONDS );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body );

        if ( ! is_object( $data ) || empty( $data->version ) || empty( $data->download_url ) ) {
            set_transient( $this->cache_key, 'none', 30 * MINUTE_IN_SECONDS );
            return null;
        }

        set_transient( $this->cache_key, $data, $this->cache_ttl );
        return $data;
    }
}
