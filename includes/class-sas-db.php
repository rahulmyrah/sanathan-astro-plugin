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

        dbDelta( $sql_predictions );
        dbDelta( $sql_kundali );
        dbDelta( $sql_guruji_sessions );
        dbDelta( $sql_guruji_messages );
        dbDelta( $sql_devices );
        dbDelta( $sql_notifications );

        // Store DB version for future migrations
        update_option( 'sas_db_version', SAS_VERSION );
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
        ];
        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
        }
        delete_option( 'sas_db_version' );
        delete_option( 'sas_settings' );
    }
}
