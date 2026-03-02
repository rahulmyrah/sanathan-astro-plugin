<?php
/**
 * Predictions cache service.
 *
 * Stores and retrieves horoscope predictions (daily/weekly/yearly) from the
 * local DB table. Falls back to live API if the cache is cold.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_Predictions {

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Get a prediction — from cache or live API.
     *
     * @param string $zodiac  e.g. 'aries'
     * @param string $lang    e.g. 'en'
     * @param string $cycle   'daily' | 'weekly' | 'yearly'
     * @param array  $opts    Optional: { date (d/m/Y), week (thisweek|nextweek), year (YYYY) }
     * @return array { status, source (cache|api|error), data }
     */
    public static function get( string $zodiac, string $lang, string $cycle, array $opts = [] ): array {
        $period_key = self::build_period_key( $cycle, $opts );

        if ( empty( $period_key ) ) {
            return [ 'status' => 'error', 'message' => 'Invalid cycle or period params.' ];
        }

        // ── 1. Try cache ───────────────────────────────────────────────────────
        $cached = self::from_cache( $zodiac, $lang, $cycle, $period_key );
        if ( $cached !== null ) {
            return [
                'status' => 'ok',
                'source' => 'cache',
                'data'   => $cached,
            ];
        }

        // ── 2. Live API fallback ───────────────────────────────────────────────
        $api    = new SAS_Api_Client();
        $result = self::fetch_from_api( $api, $zodiac, $lang, $cycle, $opts, $period_key );

        if ( empty( $result ) ) {
            return [ 'status' => 'error', 'message' => 'API returned no data. Check your VedicAstroAPI key.' ];
        }

        return [
            'status' => 'ok',
            'source' => 'api',
            'data'   => $result,
        ];
    }

    /**
     * Pre-fetch and store predictions for all zodiacs × languages for a given cycle.
     * Used by cron jobs.
     *
     * @param string $cycle   'daily' | 'weekly' | 'yearly'
     * @param array  $opts    { date, week, year } depending on cycle
     * @param array  $langs   Override language list (defaults to all enabled)
     * @return array { fetched: int, skipped: int, errors: int }
     */
    public static function prefetch_all( string $cycle, array $opts = [], array $langs = [] ): array {
        $settings = sas_get_settings();
        if ( empty( $langs ) ) {
            $langs = $settings['enabled_languages'] ?? SAS_SUPPORTED_LANGS;
        }

        $zodiacs    = SAS_ZODIACS;
        $api        = new SAS_Api_Client();
        $period_key = self::build_period_key( $cycle, $opts );
        $fetched    = 0;
        $skipped    = 0;
        $errors     = 0;

        foreach ( $langs as $lang ) {
            foreach ( $zodiacs as $zodiac ) {
                // Skip if already cached
                if ( self::from_cache( $zodiac, $lang, $cycle, $period_key ) !== null ) {
                    $skipped++;
                    continue;
                }

                $result = self::fetch_from_api( $api, $zodiac, $lang, $cycle, $opts, $period_key );

                if ( ! empty( $result ) ) {
                    $fetched++;
                } else {
                    $errors++;
                }

                // 200 ms pause to respect rate limits
                usleep( 200000 );
            }
        }

        return compact( 'fetched', 'skipped', 'errors' );
    }

    /**
     * Count total cached predictions, optionally filtered by cycle.
     */
    public static function count( string $cycle = '' ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'sanathan_predictions';

        if ( $cycle ) {
            return (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE cycle = %s", $cycle )
            );
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
    }

    /**
     * Delete predictions older than N days (for daily cycle only).
     *
     * @param int $days  Default 7
     * @return int  Rows deleted
     */
    public static function prune( int $days = 7 ): int {
        global $wpdb;
        $table     = $wpdb->prefix . 'sanathan_predictions';
        $cutoff    = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE cycle = 'daily' AND fetched_at < %s",
                $cutoff
            )
        );
    }

    /**
     * Delete ALL cached predictions (admin action).
     */
    public static function flush(): int {
        global $wpdb;
        return (int) $wpdb->query( "DELETE FROM `{$wpdb->prefix}sanathan_predictions`" );
    }

    /**
     * Get recent cache entries for admin table view.
     *
     * @param int    $limit
     * @param int    $offset
     * @param string $cycle_filter  '' | 'daily' | 'weekly' | 'yearly'
     * @param string $lang_filter   '' | 'en' | …
     * @return array
     */
    public static function admin_list( int $limit = 50, int $offset = 0, string $cycle_filter = '', string $lang_filter = '' ): array {
        global $wpdb;
        $table  = $wpdb->prefix . 'sanathan_predictions';
        $where  = [];
        $params = [];

        if ( $cycle_filter ) {
            $where[]  = 'cycle = %s';
            $params[] = $cycle_filter;
        }
        if ( $lang_filter ) {
            $where[]  = 'lang = %s';
            $params[] = $lang_filter;
        }

        $where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';
        $params[]  = $limit;
        $params[]  = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, zodiac, lang, cycle, period_key, fetched_at FROM `{$table}` {$where_sql} ORDER BY fetched_at DESC LIMIT %d OFFSET %d",
                ...$params
            ),
            ARRAY_A
        ) ?: [];
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Try to retrieve a prediction from the cache table.
     *
     * @return array|null  Decoded api_response, or null if not found.
     */
    private static function from_cache( string $zodiac, string $lang, string $cycle, string $period_key ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sanathan_predictions';

        $row = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT api_response FROM `{$table}` WHERE zodiac = %s AND lang = %s AND cycle = %s AND period_key = %s LIMIT 1",
                $zodiac, $lang, $cycle, $period_key
            )
        );

        if ( $row === null ) {
            return null;
        }

        $decoded = json_decode( $row, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Fetch from API and persist to cache.
     *
     * @return array  The api_response array, or [] on failure.
     */
    private static function fetch_from_api(
        SAS_Api_Client $api,
        string $zodiac,
        string $lang,
        string $cycle,
        array $opts,
        string $period_key
    ): array {
        // Build the prediction type and params for each cycle
        switch ( $cycle ) {
            case 'daily':
                $type   = 'daily-sun';
                $params = [
                    'zodiac' => $zodiac,
                    'lang'   => $lang,
                    'date'   => $opts['date'] ?? gmdate( 'd/m/Y' ),
                ];
                break;

            case 'weekly':
                $type   = 'weekly-sun';
                $params = [
                    'zodiac' => $zodiac,
                    'lang'   => $lang,
                    'week'   => $opts['week'] ?? 'thisweek',
                ];
                break;

            case 'yearly':
                $type   = 'yearly';
                $params = [
                    'zodiac' => $zodiac,
                    'lang'   => $lang,
                    'year'   => $opts['year'] ?? gmdate( 'Y' ),
                ];
                break;

            default:
                return [];
        }

        $result = $api->fetch_prediction( $type, $params );

        if ( empty( $result ) || ( isset( $result['status'] ) && (int) $result['status'] !== 200 ) ) {
            return [];
        }

        // Persist to DB
        self::store( $zodiac, $lang, $cycle, $period_key, $result );

        return $result;
    }

    /**
     * Insert or replace a prediction cache row.
     */
    private static function store( string $zodiac, string $lang, string $cycle, string $period_key, array $data ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sanathan_predictions';

        $wpdb->replace(
            $table,
            [
                'zodiac'       => $zodiac,
                'lang'         => $lang,
                'cycle'        => $cycle,
                'period_key'   => $period_key,
                'api_response' => wp_json_encode( $data ),
                'fetched_at'   => current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Build the period_key string used as the cache key.
     *
     * Daily  → 'd/m/Y'  e.g. '02/03/2026'
     * Weekly → 'Y_WN'   e.g. '2026_W09'
     * Yearly → 'Y'      e.g. '2026'
     *
     * @return string  Empty string on invalid input.
     */
    public static function build_period_key( string $cycle, array $opts ): string {
        switch ( $cycle ) {
            case 'daily':
                $date = $opts['date'] ?? gmdate( 'd/m/Y' );
                // Validate d/m/Y format
                if ( preg_match( '/^\d{2}\/\d{2}\/\d{4}$/', $date ) ) {
                    return $date;
                }
                return gmdate( 'd/m/Y' );

            case 'weekly':
                // Use ISO week number of the provided date (or today)
                $ts   = isset( $opts['date'] )
                    ? strtotime( str_replace( '/', '-', $opts['date'] ) )
                    : time();
                $week = $opts['week'] ?? 'thisweek';
                if ( $week === 'nextweek' ) {
                    $ts += WEEK_IN_SECONDS;
                }
                return gmdate( 'Y', $ts ) . '_W' . gmdate( 'W', $ts );

            case 'yearly':
                $year = $opts['year'] ?? gmdate( 'Y' );
                return (string) intval( $year );

            default:
                return '';
        }
    }
}
