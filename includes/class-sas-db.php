<?php
/**
 * Database table creation for Sanathan Astro Services.
 * Called on plugin activation via register_activation_hook.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_DB {

    /**
     * Create all 6 plugin tables using dbDelta.
     * Safe to call multiple times (idempotent).
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── 1. Predictions cache ──────────────────────────────────────────────
        $sql_predictions = "CREATE TABLE {$wpdb->prefix}sanathan_predictions (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            zodiac      VARCHAR(20)  NOT NULL COMMENT 'e.g. aries, taurus',
            lang        VARCHAR(5)   NOT NULL COMMENT 'e.g. en, hi, ta',
            cycle       ENUM('daily','weekly','yearly') NOT NULL,
            period_key  VARCHAR(20)  NOT NULL COMMENT 'daily=d/m/Y, weekly=Y_WN, yearly=Y',
            api_response LONGTEXT    NOT NULL COMMENT 'Raw JSON from VedicAstroAPI',
            fetched_at  DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_prediction (zodiac, lang, cycle, period_key)
        ) $charset;";

        // ── 2. Kundali storage ────────────────────────────────────────────────
        $sql_kundali = "CREATE TABLE {$wpdb->prefix}sanathan_kundali (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id         BIGINT(20) UNSIGNED NOT NULL,
            kundali_hash    VARCHAR(64) NOT NULL COMMENT 'sha256(dob+tob+lat+lon)',
            name            VARCHAR(200) DEFAULT NULL,
            dob             VARCHAR(15)  NOT NULL COMMENT 'd/m/Y',
            tob             VARCHAR(10)  NOT NULL COMMENT 'H:i',
            lat             DECIMAL(10,6) NOT NULL,
            lon             DECIMAL(10,6) NOT NULL,
            tz              DECIMAL(5,2) NOT NULL,
            location_name   VARCHAR(200) DEFAULT NULL,
            lang            VARCHAR(5)   NOT NULL DEFAULT 'en',
            tier            ENUM('core','full') NOT NULL DEFAULT 'core',
            core_data       LONGTEXT     DEFAULT NULL COMMENT 'JSON: 5 core endpoints',
            full_data       LONGTEXT     DEFAULT NULL COMMENT 'JSON: 6 dosha+ashtakvarga endpoints (premium)',
            qdrant_indexed  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = pushed to Qdrant for Guruji',
            created_at      DATETIME     NOT NULL,
            updated_at      DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_lang (user_id, lang),
            KEY idx_hash (kundali_hash),
            KEY idx_user (user_id)
        ) $charset;";

        // ── 3. Guruji chat sessions ───────────────────────────────────────────
        $sql_guruji_sessions = "CREATE TABLE {$wpdb->prefix}sanathan_guruji_sessions (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id         BIGINT(20) UNSIGNED NOT NULL,
            session_token   VARCHAR(64) NOT NULL COMMENT 'UUID v4',
            created_at      DATETIME     NOT NULL,
            last_active     DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_token (session_token),
            KEY idx_user (user_id)
        ) $charset;";

        // ── 4. Guruji chat messages ───────────────────────────────────────────
        $sql_guruji_messages = "CREATE TABLE {$wpdb->prefix}sanathan_guruji_messages (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id      BIGINT(20) UNSIGNED NOT NULL,
            role            ENUM('user','assistant') NOT NULL,
            message         TEXT        NOT NULL,
            context_source  ENUM('qdrant','llm','template') NOT NULL DEFAULT 'qdrant',
            qdrant_score    FLOAT       DEFAULT NULL COMMENT 'Confidence of vector match',
            created_at      DATETIME    NOT NULL,
            PRIMARY KEY (id),
            KEY idx_session (session_id)
        ) $charset;";

        // ── 5. User devices (FCM tokens) ──────────────────────────────────────
        $sql_devices = "CREATE TABLE {$wpdb->prefix}sanathan_user_devices (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT(20) UNSIGNED NOT NULL,
            fcm_token   TEXT        NOT NULL,
            platform    ENUM('android','ios','web') NOT NULL DEFAULT 'android',
            created_at  DATETIME    NOT NULL,
            updated_at  DATETIME    NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user (user_id)
        ) $charset;";

        // ── 6. Notification log ───────────────────────────────────────────────
        $sql_notifications = "CREATE TABLE {$wpdb->prefix}sanathan_notifications (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT(20) UNSIGNED NOT NULL,
            type        ENUM('daily_prediction','festival','auspicious','planetary','general') NOT NULL,
            title       VARCHAR(255) NOT NULL,
            body        TEXT         NOT NULL,
            data        LONGTEXT     DEFAULT NULL COMMENT 'Extra JSON payload',
            sent_at     DATETIME     NOT NULL,
            read_at     DATETIME     DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_sent (sent_at)
        ) $charset;";

        // ── 7. Guruji presets (avatar options seeded by plugin) ──────────────
        $sql_guruji_presets = "CREATE TABLE {$wpdb->prefix}sanathan_guruji_presets (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            preset_id       VARCHAR(40)  NOT NULL COMMENT 'e.g. sage_male, mata_female',
            display_name    VARCHAR(100) NOT NULL COMMENT 'e.g. White-bearded Sage',
            gender          ENUM('male','female') NOT NULL DEFAULT 'male',
            image_url       VARCHAR(500) NOT NULL COMMENT 'Bundled avatar image URL',
            default_name    VARCHAR(100) NOT NULL COMMENT 'e.g. Guruji, Mataji',
            default_personality ENUM('traditional','friendly','mystical','strict') NOT NULL DEFAULT 'traditional',
            sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_preset_id (preset_id)
        ) $charset;";

        // ── 8. Guruji user profiles (one per user) ────────────────────────────
        $sql_guruji_profiles = "CREATE TABLE {$wpdb->prefix}sanathan_guruji_profiles (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id             BIGINT(20) UNSIGNED NOT NULL,
            guruji_name         VARCHAR(100) NOT NULL DEFAULT 'Guruji',
            avatar_type         ENUM('preset','custom') NOT NULL DEFAULT 'preset',
            avatar_preset_id    VARCHAR(40)  DEFAULT NULL COMMENT 'FK → sanathan_guruji_presets.preset_id',
            avatar_url          VARCHAR(500) DEFAULT NULL COMMENT 'Custom upload URL',
            gender              ENUM('male','female') NOT NULL DEFAULT 'male',
            personality         ENUM('traditional','friendly','mystical','strict') NOT NULL DEFAULT 'traditional',
            reply_language      VARCHAR(5)   NOT NULL DEFAULT 'en',
            tts_enabled         TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = auto read aloud in Flutter',
            created_at          DATETIME     NOT NULL,
            updated_at          DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user (user_id),
            KEY idx_preset (avatar_preset_id)
        ) $charset;";

        dbDelta( $sql_predictions );
        dbDelta( $sql_kundali );
        dbDelta( $sql_guruji_sessions );
        dbDelta( $sql_guruji_messages );
        dbDelta( $sql_devices );
        dbDelta( $sql_notifications );
        dbDelta( $sql_guruji_presets );
        dbDelta( $sql_guruji_profiles );

        // Seed preset avatars (safe — INSERT IGNORE won't duplicate)
        self::seed_guruji_presets();

        // Store DB version for future migrations
        update_option( 'sas_db_version', SAS_VERSION );
    }

    /**
     * Seed the 8 built-in Guruji avatar presets.
     * Uses INSERT IGNORE so safe to call multiple times.
     */
    public static function seed_guruji_presets(): void {
        global $wpdb;
        $table    = $wpdb->prefix . 'sanathan_guruji_presets';
        $base_url = SAS_PLUGIN_URL . 'admin/images/guruji/';

        $presets = [
            [ 'sage_male',    'White-bearded Sage',      'male',   'sage_male.png',    'Guruji',   'traditional', 1 ],
            [ 'swami_male',   'Saffron Swami',           'male',   'swami_male.png',   'Swamiji',  'traditional', 2 ],
            [ 'pandit_male',  'Classical Pandit',        'male',   'pandit_male.png',  'Panditji', 'strict',      3 ],
            [ 'sadhu_male',   'Himalayan Sadhu',         'male',   'sadhu_male.png',   'Babaji',   'mystical',    4 ],
            [ 'modern_male',  'Modern Spiritual Teacher','male',   'modern_male.png',  'Gurudev',  'friendly',    5 ],
            [ 'mata_female',  'Spiritual Mother',        'female', 'mata_female.png',  'Mataji',   'traditional', 6 ],
            [ 'devi_female',  'Devi Guide',              'female', 'devi_female.png',  'Deviji',   'mystical',    7 ],
            [ 'modern_female','Modern Spiritual Teacher','female', 'modern_female.png','Guruma',   'friendly',    8 ],
        ];

        foreach ( $presets as $p ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO `{$table}`
                 (preset_id, display_name, gender, image_url, default_name, default_personality, sort_order)
                 VALUES (%s, %s, %s, %s, %s, %s, %d)",
                $p[0], $p[1], $p[2], $base_url . $p[3], $p[4], $p[5], $p[6]
            ) );
        }
    }

    /**
     * Drop all plugin tables. Called only from uninstall.php.
     */
    public static function drop_tables(): void {
        global $wpdb;
        $tables = [
            'sanathan_predictions',
            'sanathan_kundali',
            'sanathan_guruji_sessions',
            'sanathan_guruji_messages',
            'sanathan_user_devices',
            'sanathan_notifications',
            'sanathan_guruji_presets',
            'sanathan_guruji_profiles',
        ];
        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
        }
        delete_option( 'sas_db_version' );
        delete_option( 'sas_settings' );
    }
}
