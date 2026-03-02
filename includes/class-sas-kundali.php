<?php
/**
 * Kundali storage service.
 *
 * One Kundali per WordPress user per language.
 * Always stores an English (en) record for Guruji AI.
 * If the user's preferred language differs from English, a second record is stored.
 *
 * Tiers:
 *   core — 5 horoscope endpoints (free / low-price)
 *   full — core + 6 dosha/ashtakvarga endpoints (premium)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_Kundali {

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Get or create a Kundali for the current logged-in user.
     *
     * If the user already has a Kundali for the given language, it is returned
     * directly from the DB (no API call). Otherwise, the API is called and the
     * result is stored permanently.
     *
     * A matching English record is always created alongside a non-English request
     * so the Guruji AI has language-independent data.
     *
     * @param int   $user_id
     * @param array $birth   { name, dob (d/m/Y), tob (H:i), lat, lon, tz, location_name, lang }
     * @return array { status, source (db|api|error), kundali_id, tier, lang, core_data, full_data }
     */
    public static function get_or_create( int $user_id, array $birth ): array {
        $lang = $birth['lang'] ?? 'en';

        // ── 1. Check DB ────────────────────────────────────────────────────────
        $existing = self::find( $user_id, $lang );
        if ( $existing ) {
            return self::format_response( $existing, 'db' );
        }

        // ── 2. Validate required fields ───────────────────────────────────────
        $required = [ 'dob', 'tob', 'lat', 'lon', 'tz' ];
        foreach ( $required as $field ) {
            if ( empty( $birth[ $field ] ) ) {
                return [ 'status' => 'error', 'message' => "Missing required field: {$field}" ];
            }
        }

        // ── 3. Fetch from API ─────────────────────────────────────────────────
        $api   = new SAS_Api_Client();
        $hash  = self::make_hash( $birth );

        $birth_params = [
            'dob' => $birth['dob'],
            'tob' => $birth['tob'],
            'lat' => (float) $birth['lat'],
            'lon' => (float) $birth['lon'],
            'tz'  => (float) $birth['tz'],
            'lang' => $lang,
        ];

        $core_data = $api->fetch_kundali_core( $birth_params );

        if ( empty( $core_data['planet_details'] ) ) {
            return [ 'status' => 'error', 'message' => 'API returned no data. Check your VedicAstroAPI key.' ];
        }

        // ── 4. Store the requested language record ────────────────────────────
        $kundali_id = self::insert( $user_id, $hash, $birth, $lang, $core_data );

        // ── 5. If not English, also fetch & store English for Guruji ─────────
        if ( $lang !== 'en' && ! self::find( $user_id, 'en' ) ) {
            $birth_en   = array_merge( $birth_params, [ 'lang' => 'en' ] );
            $core_en    = $api->fetch_kundali_core( $birth_en );
            if ( ! empty( $core_en['planet_details'] ) ) {
                self::insert( $user_id, $hash, $birth, 'en', $core_en );
            }
            usleep( 300000 );
        }

        $row = self::find_by_id( $kundali_id );

        return self::format_response( $row, 'api' );
    }

    /**
     * Upgrade a user's Kundali from core to full (fetch 6 dosha endpoints).
     *
     * @param int    $user_id
     * @param string $lang  Language of the record to upgrade ('en' or user's lang)
     * @return array { status, tier, full_data }
     */
    public static function upgrade( int $user_id, string $lang = 'en' ): array {
        // Allow payment plugins to gate the upgrade
        $can_upgrade = apply_filters( 'sas_can_upgrade_kundali', true, $user_id );
        if ( ! $can_upgrade ) {
            return [ 'status' => 'error', 'message' => 'Upgrade not permitted. Please subscribe to Premium.' ];
        }

        $row = self::find( $user_id, $lang );
        if ( ! $row ) {
            return [ 'status' => 'error', 'message' => 'No Kundali found for this user. Please generate it first.' ];
        }

        if ( $row['tier'] === SAS_TIER_FULL ) {
            return [
                'status'    => 'ok',
                'message'   => 'Already full tier.',
                'tier'      => SAS_TIER_FULL,
                'full_data' => json_decode( $row['full_data'], true ),
            ];
        }

        // Reconstruct birth params from stored row
        $birth_params = [
            'dob'  => $row['dob'],
            'tob'  => $row['tob'],
            'lat'  => (float) $row['lat'],
            'lon'  => (float) $row['lon'],
            'tz'   => (float) $row['tz'],
            'lang' => $lang,
        ];

        $api       = new SAS_Api_Client();
        $full_data = $api->fetch_kundali_full( $birth_params );

        if ( empty( $full_data ) ) {
            return [ 'status' => 'error', 'message' => 'Failed to fetch premium data from API.' ];
        }

        self::set_full_tier( $row['id'], $full_data );

        // If upgrading non-English, also upgrade the English record for Guruji
        if ( $lang !== 'en' ) {
            $en_row = self::find( $user_id, 'en' );
            if ( $en_row && $en_row['tier'] !== SAS_TIER_FULL ) {
                $birth_en  = array_merge( $birth_params, [ 'lang' => 'en' ] );
                $full_en   = $api->fetch_kundali_full( $birth_en );
                if ( ! empty( $full_en ) ) {
                    self::set_full_tier( $en_row['id'], $full_en );
                }
                usleep( 300000 );
            }
        }

        return [
            'status'    => 'ok',
            'tier'      => SAS_TIER_FULL,
            'full_data' => $full_data,
        ];
    }

    /**
     * Get all Kundalis for a user (all languages stored).
     *
     * @param int $user_id
     * @return array  Array of Kundali rows (without raw JSON to keep response small)
     */
    public static function get_all_for_user( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sanathan_kundali';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user_id, name, dob, tob, lat, lon, tz, location_name, lang, tier, qdrant_indexed, created_at FROM `{$table}` WHERE user_id = %d ORDER BY lang ASC",
                $user_id
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Count total stored Kundalis, optionally by tier.
     *
     * @param string $tier  '' | 'core' | 'full'
     */
    public static function count( string $tier = '' ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'sanathan_kundali';

        if ( $tier ) {
            return (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE tier = %s", $tier )
            );
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
    }

    /**
     * Admin: list Kundalis with pagination and optional search.
     */
    public static function admin_list( int $limit = 50, int $offset = 0, string $search = '' ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sanathan_kundali';

        if ( $search ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $rows  = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, user_id, name, dob, location_name, lang, tier, created_at FROM `{$table}` WHERE name LIKE %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $like, $limit, $offset
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, user_id, name, dob, location_name, lang, tier, created_at FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $limit, $offset
                ),
                ARRAY_A
            );
        }

        return $rows ?: [];
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /** Find a Kundali row for a user + language. */
    private static function find( int $user_id, string $lang ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sanathan_kundali';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE user_id = %d AND lang = %s LIMIT 1",
                $user_id, $lang
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /** Find a Kundali row by primary key. */
    private static function find_by_id( int $id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sanathan_kundali';

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Insert a new Kundali row.
     *
     * @return int  Inserted row ID.
     */
    private static function insert( int $user_id, string $hash, array $birth, string $lang, array $core_data ): int {
        global $wpdb;
        $now = current_time( 'mysql', true );

        $wpdb->insert(
            $wpdb->prefix . 'sanathan_kundali',
            [
                'user_id'       => $user_id,
                'kundali_hash'  => $hash,
                'name'          => sanitize_text_field( $birth['name'] ?? '' ),
                'dob'           => $birth['dob'],
                'tob'           => $birth['tob'],
                'lat'           => (float) $birth['lat'],
                'lon'           => (float) $birth['lon'],
                'tz'            => (float) $birth['tz'],
                'location_name' => sanitize_text_field( $birth['location_name'] ?? '' ),
                'lang'          => $lang,
                'tier'          => SAS_TIER_CORE,
                'core_data'     => wp_json_encode( $core_data ),
                'full_data'     => null,
                'qdrant_indexed' => 0,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', null, '%d', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /** Update a row to full tier with dosha data. */
    private static function set_full_tier( int $id, array $full_data ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'sanathan_kundali',
            [
                'tier'       => SAS_TIER_FULL,
                'full_data'  => wp_json_encode( $full_data ),
                'updated_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Generate a stable hash from birth params (dob + tob + lat + lon).
     * Language-independent — same person = same hash.
     */
    private static function make_hash( array $birth ): string {
        $key = implode( '|', [
            $birth['dob'] ?? '',
            $birth['tob'] ?? '',
            round( (float) ( $birth['lat'] ?? 0 ), 4 ),
            round( (float) ( $birth['lon'] ?? 0 ), 4 ),
        ] );
        return hash( 'sha256', $key );
    }

    /** Format a DB row into a consistent REST API response. */
    private static function format_response( array $row, string $source ): array {
        $core_data = json_decode( $row['core_data'] ?? '{}', true ) ?: [];
        $full_data = $row['full_data'] ? ( json_decode( $row['full_data'], true ) ?: [] ) : null;

        return [
            'status'      => 'ok',
            'source'      => $source,
            'kundali_id'  => (int) $row['id'],
            'user_id'     => (int) $row['user_id'],
            'name'        => $row['name'],
            'dob'         => $row['dob'],
            'lang'        => $row['lang'],
            'tier'        => $row['tier'],
            'core_data'   => $core_data,
            'full_data'   => $full_data,
            'created_at'  => $row['created_at'],
        ];
    }
}
