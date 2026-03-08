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

        // ── Birth Profile (v1.5.0) ─────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/kundali/birth-profile', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'get_birth_profile' ],
                'permission_callback' => [ __CLASS__, 'require_logged_in' ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE, // PUT
                'callback'            => [ __CLASS__, 'update_birth_profile' ],
                'permission_callback' => [ __CLASS__, 'require_logged_in' ],
                'args'                => self::birth_profile_args(),
            ],
        ] );

        // ── Location search (public — used by web form autocomplete + Flutter) ──
        register_rest_route( self::NAMESPACE, '/util/location-search', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'location_search' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'q' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function( $v ) {
                        return is_string( $v ) && strlen( trim( $v ) ) >= 3;
                    },
                ],
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

        // ── Guruji — Phase 2 ──────────────────────────────────────────────────

        // List available avatar presets (public — needed before user logs in)
        register_rest_route( self::NAMESPACE, '/guruji/presets', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'guruji_presets' ],
            'permission_callback' => '__return_true',
        ] );

        // Get Guruji profile
        register_rest_route( self::NAMESPACE, '/guruji/profile', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'guruji_get_profile' ],
                'permission_callback' => [ __CLASS__, 'require_logged_in' ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,  // PUT + PATCH
                'callback'            => [ __CLASS__, 'guruji_save_profile' ],
                'permission_callback' => [ __CLASS__, 'require_logged_in' ],
                'args'                => self::guruji_profile_args(),
            ],
        ] );

        // First-time setup (same as PUT /guruji/profile but POST-friendly)
        register_rest_route( self::NAMESPACE, '/guruji/setup', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'guruji_save_profile' ],
            'permission_callback' => [ __CLASS__, 'require_logged_in' ],
            'args'                => self::guruji_profile_args(),
        ] );

        // Upload custom avatar photo
        register_rest_route( self::NAMESPACE, '/guruji/avatar/upload', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'guruji_upload_avatar' ],
            'permission_callback' => [ __CLASS__, 'require_logged_in' ],
        ] );

        // Chat with Guruji
        register_rest_route( self::NAMESPACE, '/guruji/chat', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'guruji_chat' ],
            'permission_callback' => [ __CLASS__, 'require_logged_in' ],
            'args'                => [
                'message'       => [ 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ],
                'session_token' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Chat history
        register_rest_route( self::NAMESPACE, '/guruji/history', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'guruji_history' ],
                'permission_callback' => [ __CLASS__, 'require_logged_in' ],
                'args'                => [
                    'session_token' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                    'limit'         => [ 'default' => 50, 'sanitize_callback' => 'absint' ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ __CLASS__, 'guruji_clear_history' ],
                'permission_callback' => [ __CLASS__, 'require_logged_in' ],
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

    // ── Birth Profile (v1.5.0) ─────────────────────────────────────────────────

    /**
     * GET /kundali/birth-profile
     * Returns the user's stored birth details plus zodiac, moon sign, ascendant.
     */
    public static function get_birth_profile( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();
        $profile = SAS_Kundali::get_birth_profile( $user_id );
        return new WP_REST_Response( $profile, 200 );
    }

    /**
     * PUT /kundali/birth-profile
     * Update birth details (max 1 free edit; upgrade_required 403 after that).
     */
    public static function update_birth_profile( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();

        $params = [
            'name'          => $req->get_param( 'name' )          ?? '',
            'dob'           => $req->get_param( 'dob' ),
            'tob'           => $req->get_param( 'tob' ),
            'lat'           => (float) $req->get_param( 'lat' ),
            'lon'           => (float) $req->get_param( 'lon' ),
            'tz'            => (float) $req->get_param( 'tz' ),
            'location_name' => $req->get_param( 'location_name' ) ?? '',
        ];

        $error  = '';
        $result = SAS_Kundali::update_birth_profile( $user_id, $params, $error );

        if ( ! $result ) {
            if ( $error === 'upgrade_required' ) {
                return new WP_REST_Response( [
                    'code'        => 'upgrade_required',
                    'message'     => 'You have used your free edit. Upgrade to Premium to change birth details again.',
                    'upgrade_url' => home_url( '/membership-upgrade/' ),
                ], 403 );
            }
            return new WP_REST_Response( [
                'code'    => 'update_failed',
                'message' => $error ?: 'Failed to update birth profile. Please try again.',
            ], 400 );
        }

        // Return the updated profile on success
        $profile = SAS_Kundali::get_birth_profile( $user_id );
        return new WP_REST_Response( $profile, 200 );
    }

    /**
     * GET /util/location-search?q=Mumbai
     * Proxy to VedicAstro geo-search. Returns [{name,lat,lon,tz}].
     */
    public static function location_search( WP_REST_Request $req ): WP_REST_Response {
        $q      = trim( $req->get_param( 'q' ) );
        $client = new SAS_Api_Client();
        $loc    = $client->geo_search( $q );

        // geo_search returns a single result; wrap in array for consistency
        $results = [];
        if ( ! empty( $loc['name'] ) ) {
            $results[] = $loc;
        }

        return new WP_REST_Response( $results, 200 );
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

    // ── Guruji ────────────────────────────────────────────────────────────────

    /**
     * GET /guruji/presets
     * Lists all available preset avatar options.
     */
    public static function guruji_presets( WP_REST_Request $req ): WP_REST_Response {
        $presets = SAS_Guruji::get_presets();

        // Attach full image URLs
        foreach ( $presets as &$p ) {
            $p['image_url'] = SAS_PLUGIN_URL . 'admin/images/guruji/' . $p['preset_id'] . '.png';
        }
        unset( $p );

        return new WP_REST_Response( [
            'status' => 'ok',
            'data'   => $presets,
        ], 200 );
    }

    /**
     * GET /guruji/profile
     * Returns the current user's Guruji profile, or setup_required flag.
     */
    public static function guruji_get_profile( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();
        $profile = SAS_Guruji::get_profile( $user_id );

        if ( ! $profile ) {
            return new WP_REST_Response( [
                'status'        => 'not_setup',
                'setup_required'=> true,
                'message'       => 'Guruji not set up yet. Please complete setup.',
            ], 200 );
        }

        return new WP_REST_Response( [
            'status'  => 'ok',
            'profile' => $profile,
        ], 200 );
    }

    /**
     * POST /guruji/setup  or  PUT /guruji/profile
     * Create or update the Guruji profile.
     */
    public static function guruji_save_profile( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();

        $data = [
            'guruji_name'      => $req->get_param( 'guruji_name' ),
            'avatar_type'      => $req->get_param( 'avatar_type' ),
            'avatar_preset_id' => $req->get_param( 'avatar_preset_id' ),
            'avatar_url'       => $req->get_param( 'avatar_url' ),
            'gender'           => $req->get_param( 'gender' ),
            'personality'      => $req->get_param( 'personality' ),
            'reply_language'   => $req->get_param( 'reply_language' ),
            'tts_enabled'      => $req->get_param( 'tts_enabled' ),
        ];

        $result = SAS_Guruji::save_profile( $user_id, $data );

        return new WP_REST_Response( [
            'status'  => 'ok',
            'profile' => $result['profile'],
        ], 200 );
    }

    /**
     * POST /guruji/avatar/upload
     * Accept a base64-encoded image from Flutter and save it as a WP Media file.
     */
    public static function guruji_upload_avatar( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();

        $body = $req->get_json_params();
        $b64  = $body['image_base64'] ?? '';
        $mime = $body['mime_type']    ?? 'image/jpeg';

        if ( empty( $b64 ) ) {
            return new WP_REST_Response( [
                'status'  => 'error',
                'message' => 'No image data provided.',
            ], 400 );
        }

        // Decode base64
        $image_data = base64_decode( preg_replace( '#^data:[^;]+;base64,#', '', $b64 ) );
        if ( ! $image_data ) {
            return new WP_REST_Response( [
                'status'  => 'error',
                'message' => 'Invalid base64 image data.',
            ], 400 );
        }

        // Save to WP uploads
        $ext   = ( $mime === 'image/png' ) ? 'png' : 'jpg';
        $fname = 'guruji-avatar-' . $user_id . '-' . time() . '.' . $ext;
        $upload = wp_upload_bits( $fname, null, $image_data );

        if ( ! empty( $upload['error'] ) ) {
            return new WP_REST_Response( [
                'status'  => 'error',
                'message' => 'Upload failed: ' . $upload['error'],
            ], 500 );
        }

        $avatar_url = $upload['url'];

        // Optionally update the profile with this new avatar
        SAS_Guruji::save_profile( $user_id, [
            'avatar_type' => 'custom',
            'avatar_url'  => $avatar_url,
        ] );

        return new WP_REST_Response( [
            'status'     => 'ok',
            'avatar_url' => $avatar_url,
        ], 200 );
    }

    /**
     * POST /guruji/chat
     * Send a message to the user's personal Guruji AI.
     */
    public static function guruji_chat( WP_REST_Request $req ): WP_REST_Response {
        $user_id       = get_current_user_id();
        $message       = $req->get_param( 'message' );
        $session_token = $req->get_param( 'session_token' ) ?: '';

        if ( empty( trim( $message ) ) ) {
            return new WP_REST_Response( [
                'status'  => 'error',
                'message' => 'Message cannot be empty.',
            ], 400 );
        }

        $result = SAS_Guruji::chat( $user_id, $message, $session_token );

        if ( empty( $result['success'] ) ) {
            $code = ! empty( $result['setup_required'] ) ? 428 : 503;
            return new WP_REST_Response( [
                'status'         => 'error',
                'message'        => $result['error'] ?? 'Guruji is unavailable.',
                'setup_required' => $result['setup_required'] ?? false,
            ], $code );
        }

        return new WP_REST_Response( [
            'status'          => 'ok',
            'reply'           => $result['reply'],
            'guruji_name'     => $result['guruji_name'],
            'guruji_avatar'   => $result['guruji_avatar'],
            'reply_language'  => $result['reply_language'],
            'guruji_gender'   => $result['guruji_gender'],
            'tts_enabled'     => $result['tts_enabled'],
            'session_token'   => $result['session_token'],
        ], 200 );
    }

    /**
     * GET /guruji/history
     * Returns chat history for the user (most recent session or specified token).
     */
    public static function guruji_history( WP_REST_Request $req ): WP_REST_Response {
        $user_id       = get_current_user_id();
        $session_token = $req->get_param( 'session_token' ) ?: '';
        $limit         = min( 100, max( 1, (int) $req->get_param( 'limit' ) ) );

        $history = SAS_Guruji::get_history( $user_id, $session_token, $limit );

        return new WP_REST_Response( [
            'status' => 'ok',
            'count'  => count( $history ),
            'data'   => $history,
        ], 200 );
    }

    /**
     * DELETE /guruji/history
     * Clear all chat history for the user.
     */
    public static function guruji_clear_history( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();
        $deleted = SAS_Guruji::clear_history( $user_id );

        return new WP_REST_Response( [
            'status'  => 'ok',
            'deleted' => $deleted,
            'message' => "Cleared {$deleted} messages.",
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

    /** Birth profile PUT endpoint argument definitions */
    private static function birth_profile_args(): array {
        return [
            'name'          => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            'dob'           => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            'tob'           => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            'lat'           => [ 'required' => true, 'sanitize_callback' => 'floatval' ],
            'lon'           => [ 'required' => true, 'sanitize_callback' => 'floatval' ],
            'tz'            => [ 'required' => true, 'sanitize_callback' => 'floatval' ],
            'location_name' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
        ];
    }

    /** Guruji profile save endpoint argument definitions */
    private static function guruji_profile_args(): array {
        return [
            'guruji_name'      => [ 'default' => 'Guruji', 'sanitize_callback' => 'sanitize_text_field' ],
            'avatar_type'      => [ 'default' => 'preset',  'sanitize_callback' => 'sanitize_text_field' ],
            'avatar_preset_id' => [ 'default' => '',        'sanitize_callback' => 'sanitize_text_field' ],
            'avatar_url'       => [ 'default' => '',        'sanitize_callback' => 'esc_url_raw' ],
            'gender'           => [ 'default' => 'male',    'sanitize_callback' => 'sanitize_text_field' ],
            'personality'      => [ 'default' => 'traditional', 'sanitize_callback' => 'sanitize_text_field' ],
            'reply_language'   => [ 'default' => 'en',      'sanitize_callback' => 'sanitize_text_field' ],
            'tts_enabled'      => [ 'default' => 1,         'sanitize_callback' => 'absint' ],
        ];
    }
}
