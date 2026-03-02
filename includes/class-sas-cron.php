<?php
/**
 * WP Cron scheduler for Sanathan Astro Services.
 *
 * Schedules:
 *   sas_daily_predictions  — Runs once daily at ~00:01 IST (18:31 UTC previous day)
 *   sas_weekly_predictions — Runs every Monday
 *   sas_yearly_predictions — Runs every Jan 1
 *   sas_daily_alerts       — Runs daily at ~07:00 IST (01:30 UTC)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_Cron {

    // ── Boot ───────────────────────────────────────────────────────────────────

    public static function init(): void {
        // Register custom intervals
        add_filter( 'cron_schedules', [ __CLASS__, 'add_intervals' ] );

        // Map hooks → handlers
        add_action( 'sas_daily_predictions',  [ __CLASS__, 'run_daily_predictions' ] );
        add_action( 'sas_weekly_predictions', [ __CLASS__, 'run_weekly_predictions' ] );
        add_action( 'sas_yearly_predictions', [ __CLASS__, 'run_yearly_predictions' ] );
        add_action( 'sas_daily_alerts',       [ __CLASS__, 'run_daily_alerts' ] );

        // Schedule events if not already scheduled
        self::maybe_schedule();
    }

    // ── Deactivation ───────────────────────────────────────────────────────────

    public static function deactivate(): void {
        foreach ( [ 'sas_daily_predictions', 'sas_weekly_predictions', 'sas_yearly_predictions', 'sas_daily_alerts' ] as $hook ) {
            $timestamp = wp_next_scheduled( $hook );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $hook );
            }
        }
    }

    // ── Intervals ─────────────────────────────────────────────────────────────

    public static function add_intervals( array $schedules ): array {
        // Weekly on Monday
        if ( ! isset( $schedules['sas_weekly'] ) ) {
            $schedules['sas_weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'sanathan-astro' ),
            ];
        }

        // Yearly (approximate — 365 days)
        if ( ! isset( $schedules['sas_yearly'] ) ) {
            $schedules['sas_yearly'] = [
                'interval' => YEAR_IN_SECONDS,
                'display'  => __( 'Once Yearly', 'sanathan-astro' ),
            ];
        }

        return $schedules;
    }

    // ── Scheduling ────────────────────────────────────────────────────────────

    private static function maybe_schedule(): void {
        // Daily predictions — 18:31 UTC (00:01 IST next day)
        if ( ! wp_next_scheduled( 'sas_daily_predictions' ) ) {
            $next = self::next_utc_time( 18, 31 );
            wp_schedule_event( $next, 'daily', 'sas_daily_predictions' );
        }

        // Daily alerts — 01:30 UTC (07:00 IST)
        if ( ! wp_next_scheduled( 'sas_daily_alerts' ) ) {
            $next = self::next_utc_time( 1, 30 );
            wp_schedule_event( $next, 'daily', 'sas_daily_alerts' );
        }

        // Weekly predictions — next Monday 18:00 UTC
        if ( ! wp_next_scheduled( 'sas_weekly_predictions' ) ) {
            $next = self::next_weekday_utc( 1, 18, 0 ); // 1 = Monday
            wp_schedule_event( $next, 'sas_weekly', 'sas_weekly_predictions' );
        }

        // Yearly predictions — next Jan 1 00:00 UTC
        if ( ! wp_next_scheduled( 'sas_yearly_predictions' ) ) {
            $next = mktime( 0, 0, 0, 1, 1, (int) gmdate( 'Y' ) + 1 );
            wp_schedule_event( $next, 'sas_yearly', 'sas_yearly_predictions' );
        }
    }

    // ── Cron handlers ─────────────────────────────────────────────────────────

    /**
     * Pre-fetch daily predictions for all zodiacs × languages (today + tomorrow).
     */
    public static function run_daily_predictions(): void {
        // Allow this long-running job to exceed PHP's default max_execution_time
        @set_time_limit( 0 );
        ignore_user_abort( true );

        $today    = gmdate( 'd/m/Y' );
        $tomorrow = gmdate( 'd/m/Y', strtotime( '+1 day' ) );

        // Today
        $result_today = SAS_Predictions::prefetch_all( 'daily', [ 'date' => $today ] );

        // Tomorrow (so they're ready at midnight)
        $result_tmrw  = SAS_Predictions::prefetch_all( 'daily', [ 'date' => $tomorrow ] );

        // Prune entries older than 7 days
        SAS_Predictions::prune( 7 );

        $summary = [
            'today'    => $result_today,
            'tomorrow' => $result_tmrw,
            'run_at'   => current_time( 'mysql', true ),
        ];

        update_option( 'sas_last_daily_cron', $summary );
        error_log( '[SAS Cron] Daily predictions: ' . wp_json_encode( $summary ) );
    }

    /**
     * Pre-fetch weekly predictions (this week + next week).
     */
    public static function run_weekly_predictions(): void {
        @set_time_limit( 0 );
        ignore_user_abort( true );

        $results = [];
        foreach ( [ 'thisweek', 'nextweek' ] as $week ) {
            $results[ $week ] = SAS_Predictions::prefetch_all( 'weekly', [ 'week' => $week ] );
        }

        $summary = array_merge( $results, [ 'run_at' => current_time( 'mysql', true ) ] );
        update_option( 'sas_last_weekly_cron', $summary );
        error_log( '[SAS Cron] Weekly predictions: ' . wp_json_encode( $summary ) );
    }

    /**
     * Pre-fetch yearly predictions for the current year.
     */
    public static function run_yearly_predictions(): void {
        @set_time_limit( 0 );
        ignore_user_abort( true );

        $result = SAS_Predictions::prefetch_all( 'yearly', [ 'year' => gmdate( 'Y' ) ] );

        $summary = array_merge( $result, [ 'run_at' => current_time( 'mysql', true ) ] );
        update_option( 'sas_last_yearly_cron', $summary );
        error_log( '[SAS Cron] Yearly predictions: ' . wp_json_encode( $summary ) );
    }

    /**
     * Send daily personalized alerts to premium users via FCM.
     * Placeholder — full implementation in Phase 3 (class-sas-notifications.php).
     */
    public static function run_daily_alerts(): void {
        // Phase 3: send FCM notifications to premium users
        // For now, just log
        error_log( '[SAS Cron] Daily alerts triggered at ' . current_time( 'mysql', true ) );
    }

    // ── Manual triggers (admin) ────────────────────────────────────────────────

    /**
     * Manually trigger any cron job immediately. Used by the admin dashboard.
     *
     * @param string $job  'daily' | 'weekly' | 'yearly'
     * @return array  Result summary
     */
    public static function run_now( string $job ): array {
        switch ( $job ) {
            case 'daily':
                self::run_daily_predictions();
                return get_option( 'sas_last_daily_cron', [] );

            case 'weekly':
                self::run_weekly_predictions();
                return get_option( 'sas_last_weekly_cron', [] );

            case 'yearly':
                self::run_yearly_predictions();
                return get_option( 'sas_last_yearly_cron', [] );

            default:
                return [ 'error' => 'Unknown job' ];
        }
    }

    /**
     * Return cron status info for the admin dashboard.
     */
    public static function status(): array {
        return [
            'daily'  => [
                'next_run'   => wp_next_scheduled( 'sas_daily_predictions' )
                    ? gmdate( 'Y-m-d H:i:s T', (int) wp_next_scheduled( 'sas_daily_predictions' ) )
                    : 'Not scheduled',
                'last_run'   => get_option( 'sas_last_daily_cron', null ),
            ],
            'weekly' => [
                'next_run'   => wp_next_scheduled( 'sas_weekly_predictions' )
                    ? gmdate( 'Y-m-d H:i:s T', (int) wp_next_scheduled( 'sas_weekly_predictions' ) )
                    : 'Not scheduled',
                'last_run'   => get_option( 'sas_last_weekly_cron', null ),
            ],
            'yearly' => [
                'next_run'   => wp_next_scheduled( 'sas_yearly_predictions' )
                    ? gmdate( 'Y-m-d H:i:s T', (int) wp_next_scheduled( 'sas_yearly_predictions' ) )
                    : 'Not scheduled',
                'last_run'   => get_option( 'sas_last_yearly_cron', null ),
            ],
        ];
    }

    // ── Time helpers ───────────────────────────────────────────────────────────

    /**
     * Get the next UNIX timestamp for a given UTC hour:minute (today or tomorrow).
     */
    private static function next_utc_time( int $hour, int $min ): int {
        $today = mktime( $hour, $min, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ), (int) gmdate( 'Y' ) );
        return $today > time() ? $today : $today + DAY_IN_SECONDS;
    }

    /**
     * Get the next occurrence of a weekday at a given UTC time.
     *
     * @param int $weekday  1=Mon … 7=Sun
     * @param int $hour     UTC hour
     * @param int $min      UTC minute
     */
    private static function next_weekday_utc( int $weekday, int $hour, int $min ): int {
        $now           = time();
        $current_day   = (int) gmdate( 'N' ); // 1=Mon … 7=Sun
        $days_ahead    = ( $weekday - $current_day + 7 ) % 7;
        if ( $days_ahead === 0 ) {
            $days_ahead = 7; // Always schedule at least 1 week out if today
        }
        return mktime( $hour, $min, 0 ) + $days_ahead * DAY_IN_SECONDS;
    }
}
