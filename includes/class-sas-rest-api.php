<?php
/**
 * REST API routes for the Sanathan Flutter app.
 *
 * Namespace: sanathan/v1
 *
 * Public endpoints (no auth):
 *   GET  /predictions
 *
 * Authenticated endpoints (require WP user login via cookie or Application Password):
 *   POST /kundali
 *   GET  /kundali
 *   POST /kundali/upgrade
 *   POST /device/register
 *   GET  /notifications
 *   GET  /user/tier
 *
 * Guruji endpoints (Phase 2 — stubs only):
 *   POST /guruji/chat
 *   GET  /guruji/history
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_Rest_Api {

    const NAMESPACE = 'sanathan/v1';

    public static function register_routes(): void {
        // ── Predictions ────────────────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/predictions', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_predictions' ],
            'permission_callback' => '__return_true', // Public
            'args'                => [
                'zodiac' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => [ __CLASS__, 'validate_zodiac' ],
                ],
                'cycle' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => [ __CLASS__, 'validate_cycle' ],
                ],
                'lang' => [
                    'default'           => 'en',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'date'  => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                'week'  => [ 'default' => 'thisweek', 'sanitize_callback' => 'sanitize_text_field' ],
                'year'  => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // ── Kundali ────────────────────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/kundali', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'create_kundali' ],
                'permission_callback' => [ __CLASS__, 'require_logged_in' ],
                'args'                => self::kundali_args(),
            ],
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'get_kundali' ],
                'permission_callback' => [ __CLASS__, 'require_logged_in' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/kundali/upgrade', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'upgrade_kundali' ],
            'permission_callback' => [ __CLASS__, 'require_logged_in' ],
            'args'                => [
                'lang' => [ 'default' => 'en', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // ── User tier ──────────────────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/user/tier', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_user_tier' ],
            'permission_callback' => [ __CLASS__, 'require_logged_in' ],
        ] );

        // ── Device / FCM registration ──────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/device/register', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'register_device' ],
            'permission_callback' => [ __CLASS__, 'require_logged_in' ],
            'args'                => [
                'fcm_token' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'platform' => [
                    'default'           => 'android',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        // ── Notifications log ──────────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/notifications', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_notifications' ],
            'permission_callback' => [ __CLASS__, 'require_logged_in' ],
            'args'                => [
                'page'     => [ 'default' => 1,  'sanitize_callback' => 'absint' ],
                'per_page' => [ 'default' => 20, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // ── Guruji (Phase 2 stubs) ─────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/guruji/chat', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'guruji_chat' ],
            'permission_callback' => [ __CLASS__, 'require_logged_in' ],
            'args'                => [
                'message'    => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'session_id' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/guruji/history', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'guruji_history' ],
            'permission_callback' => [ __CLASS__, 'require_logged_in' ],
            'args'                => [
                'session_id' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                'page'       => [ 'default' => 1, 'sanitize_callback' => 'absint' ],
            ],
        ] );
    }

    // ── Predictions ────────────────────────────────────────────────────────────

    public static function get_predictions( WP_REST_Request $req ): WP_REST_Response {
        $result = SAS_Predictions::get(
            $req->get_param( 'zodiac' ),
            $req->get_param( 'lang' ),
            $req->get_param( 'cycle' ),
            [
                'date' => $req->get_param( 'date' ),
                'week' => $req->get_param( 'week' ),
                'year' => $req->get_param( 'year' ),
            ]
        );

        if ( $result['status'] === 'error' ) {
            return new WP_REST_Response( $result, 400 );
        }

        return new WP_REST_Response( $result, 200 );
    }

    // ── Kundali ────────────────────────────────────────────────────────────────

    public static function create_kundali( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();

        $birth = [
            'name'          => $req->get_param( 'name' ) ?? '',
            'dob'           => $req->get_param( 'dob' ),
            'tob'           => $req->get_param( 'tob' ),
            'lat'           => $req->get_param( 'lat' ),
            'lon'           => $req->get_param( 'lon' ),
            'tz'            => $req->get_param( 'tz' ),
            'location_name' => $req->get_param( 'location_name' ) ?? '',
            'lang'          => $req->get_param( 'lang' ) ?? 'en',
        ];

        $result = SAS_Kundali::get_or_create( $user_id, $birth );

        if ( $result['status'] === 'error' ) {
            return new WP_REST_Response( $result, 400 );
        }

        return new WP_REST_Response( $result, 200 );
    }

    public static function get_kundali( WP_REST_Request $req ): WP_REST_Response {
        $user_id  = get_current_user_id();
        $kundalis = SAS_Kundali::get_all_for_user( $user_id );

        return new WP_REST_Response( [
            'status' => 'ok',
            'data'   => $kundalis,
        ], 200 );
    }

    public static function upgrade_kundali( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();
        $lang    = $req->get_param( 'lang' ) ?: 'en';

        $result = SAS_Kundali::upgrade( $user_id, $lang );

        $code = $result['status'] === 'ok' ? 200 : 403;
        return new WP_REST_Response( $result, $code );
    }

    // ── User tier ──────────────────────────────────────────────────────────────

    public static function get_user_tier( WP_REST_Request $req ): WP_REST_Response {
        $user_id  = get_current_user_id();
        $kundalis = SAS_Kundali::get_all_for_user( $user_id );

        // Determine highest tier across all stored Kundalis
        $tier = SAS_TIER_FREE;
        foreach ( $kundalis as $k ) {
            if ( $k['tier'] === SAS_TIER_FULL ) {
                $tier = SAS_TIER_FULL;
                break;
            }
            if ( $k['tier'] === SAS_TIER_CORE ) {
                $tier = SAS_TIER_CORE;
            }
        }

        $features = self::tier_features( $tier );

        return new WP_REST_Response( [
            'status'   => 'ok',
            'user_id'  => $user_id,
            'tier'     => $tier,
            'features' => $features,
        ], 200 );
    }

    // ── Device registration ────────────────────────────────────────────────────

    public static function register_device( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $user_id   = get_current_user_id();
        $fcm_token = $req->get_param( 'fcm_token' );
        $platform  = in_array( $req->get_param( 'platform' ), [ 'android', 'ios', 'web' ], true )
            ? $req->get_param( 'platform' )
            : 'android';

        $table = $wpdb->prefix . 'sanathan_user_devices';
        $now   = current_time( 'mysql', true );

        // Upsert: update if token already exists for this user
        $existing = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM `{$table}` WHERE user_id = %d AND fcm_token = %s LIMIT 1", $user_id, $fcm_token )
        );

        if ( $existing ) {
            $wpdb->update( $table, [ 'updated_at' => $now ], [ 'id' => $existing ], [ '%s' ], [ '%d' ] );
        } else {
            $wpdb->insert( $table, [
                'user_id'    => $user_id,
                'fcm_token'  => $fcm_token,
                'platform'   => $platform,
                'created_at' => $now,
                'updated_at' => $now,
            ], [ '%d', '%s', '%s', '%s', '%s' ] );
        }

        return new WP_REST_Response( [ 'status' => 'ok', 'message' => 'Device registered.' ], 200 );
    }

    // ── Notifications ──────────────────────────────────────────────────────────

    public static function get_notifications( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $user_id  = get_current_user_id();
        $page     = max( 1, $req->get_param( 'page' ) );
        $per_page = min( 50, max( 1, $req->get_param( 'per_page' ) ) );
        $offset   = ( $page - 1 ) * $per_page;
        $table    = $wpdb->prefix . 'sanathan_notifications';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, type, title, body, data, sent_at, read_at FROM `{$table}` WHERE user_id = %d ORDER BY sent_at DESC LIMIT %d OFFSET %d",
                $user_id, $per_page, $offset
            ),
            ARRAY_A
        ) ?: [];

        $total = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d", $user_id )
        );

        return new WP_REST_Response( [
            'status'     => 'ok',
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $per_page,
            'data'       => $rows,
        ], 200 );
    }

    // ── Guruji (Phase 2 stubs) ─────────────────────────────────────────────────

    public static function guruji_chat( WP_REST_Request $req ): WP_REST_Response {
        // Phase 2: integrate Qdrant RAG + LLM fallback
        return new WP_REST_Response( [
            'status'  => 'coming_soon',
            'message' => 'Personal Guruji is coming soon. Your Kundali is being prepared.',
        ], 200 );
    }

    public static function guruji_history( WP_REST_Request $req ): WP_REST_Response {
        return new WP_REST_Response( [
            'status' => 'coming_soon',
            'data'   => [],
        ], 200 );
    }

    // ── Permission callbacks ───────────────────────────────────────────────────

    public static function require_logged_in(): bool {
        return is_user_logged_in();
    }

    // ── Validators ────────────────────────────────────────────────────────────

    public static function validate_zodiac( string $value ): bool {
        return in_array( strtolower( $value ), SAS_ZODIACS, true );
    }

    public static function validate_cycle( string $value ): bool {
        return in_array( $value, [ 'daily', 'weekly', 'yearly' ], true );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function tier_features( string $tier ): array {
        $all = [
            SAS_TIER_FREE => [
                'daily_predictions'   => true,
                'weekly_predictions'  => true,
                'yearly_predictions'  => false,
                'kundali_core'        => false,
                'kundali_full'        => false,
                'guruji'              => false,
                'daily_alerts'        => false,
            ],
            SAS_TIER_CORE => [
                'daily_predictions'   => true,
                'weekly_predictions'  => true,
                'yearly_predictions'  => true,
                'kundali_core'        => true,
                'kundali_full'        => false,
                'guruji'              => true,
                'daily_alerts'        => false,
            ],
            SAS_TIER_FULL => [
                'daily_predictions'   => true,
                'weekly_predictions'  => true,
                'yearly_predictions'  => true,
                'kundali_core'        => true,
                'kundali_full'        => true,
                'guruji'              => true,
                'daily_alerts'        => true,
            ],
        ];

        return $all[ $tier ] ?? $all[ SAS_TIER_FREE ];
    }

    /** Kundali creation endpoint argument definitions */
    private static function kundali_args(): array {
        return [
            'name'          => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            'dob'           => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
            'tob'           => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
            'lat'           => [ 'required' => true,  'sanitize_callback' => 'floatval' ],
            'lon'           => [ 'required' => true,  'sanitize_callback' => 'floatval' ],
            'tz'            => [ 'required' => true,  'sanitize_callback' => 'floatval' ],
            'location_name' => [ 'default' => '',  'sanitize_callback' => 'sanitize_text_field' ],
            'lang'          => [ 'default' => 'en', 'sanitize_callback' => 'sanitize_text_field' ],
        ];
    }
}
