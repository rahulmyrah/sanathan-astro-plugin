<?php
/**
 * Panchang & Muhurat data — fetch with WP transient caching.
 *
 * Mirrors the pattern used by SAS_Predictions:
 *   check transient → on miss, call SAS_Api_Client → cache result → return
 *
 * No API key access here. All HTTP calls go through SAS_Api_Client.
 *
 * @package SananthanAstroServices
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SAS_Panchang {

    /** Transient TTL: 25 hours (covers the full day with buffer) */
    const CACHE_TTL = 90000;

    /**
     * Get today's Panchang data.
     * Returns: tithi, nakshatra, yoga, karana, vara (from API response), plus festivals[].
     * Cached as WP transient 'sas_panchang_YYYY-MM-DD'.
     *
     * @param string $date  Optional date in YYYY-MM-DD format. Defaults to today (Asia/Kolkata).
     * @return array
     */
    public static function get_today( string $date = '' ): array {
        if ( empty( $date ) ) {
            $date = ( new DateTime( 'now', new DateTimeZone( 'Asia/Kolkata' ) ) )->format( 'Y-m-d' );
        }
        $key = 'sas_panchang_' . $date;
        $cached = get_transient( $key );
        if ( $cached !== false ) {
            return $cached;
        }

        $dmy = DateTime::createFromFormat( 'Y-m-d', $date )->format( 'd/m/Y' );
        $api  = new SAS_Api_Client();
        if ( ! $api->has_api_key() ) {
            return [];
        }
        $raw = $api->fetch_panchang( $dmy );
        if ( empty( $raw ) ) {
            return [];
        }

        // Normalise: pull the inner 'response' layer if present (API wraps data)
        $data = isset( $raw['response'] ) ? $raw['response'] : $raw;
        set_transient( $key, $data, self::CACHE_TTL );
        return $data;
    }

    /**
     * Get today's Choghadiya (auspicious/inauspicious time slots).
     * Cached as WP transient 'sas_muhurat_YYYY-MM-DD'.
     *
     * @param string $date  Optional date in YYYY-MM-DD format. Defaults to today (IST).
     * @return array
     */
    public static function get_muhurat( string $date = '' ): array {
        if ( empty( $date ) ) {
            $date = ( new DateTime( 'now', new DateTimeZone( 'Asia/Kolkata' ) ) )->format( 'Y-m-d' );
        }
        $key = 'sas_muhurat_' . $date;
        $cached = get_transient( $key );
        if ( $cached !== false ) {
            return $cached;
        }

        $dmy = DateTime::createFromFormat( 'Y-m-d', $date )->format( 'd/m/Y' );
        $api  = new SAS_Api_Client();
        if ( ! $api->has_api_key() ) {
            return [];
        }
        $raw = $api->fetch_choghadiya( $dmy );
        if ( empty( $raw ) ) {
            return [];
        }

        $data = isset( $raw['response'] ) ? $raw['response'] : $raw;
        set_transient( $key, $data, self::CACHE_TTL );
        return $data;
    }
}
