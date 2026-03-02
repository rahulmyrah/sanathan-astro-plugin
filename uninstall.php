<?php
/**
 * Runs on plugin uninstall (not deactivation).
 * Drops all sanathan_* tables and options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'sanathan-astro-services.php';
require_once SAS_PLUGIN_DIR . 'includes/class-sas-db.php';

SAS_DB::drop_tables();

delete_option( 'sas_settings' );
delete_option( 'sas_last_daily_cron' );
delete_option( 'sas_last_weekly_cron' );
delete_option( 'sas_last_yearly_cron' );
