<?php
/**
 * Admin Dashboard — overview stats + cron status.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$api_key_ok   = ! empty( sas_get_vedic_api_key() );
$cron_status  = SAS_Cron::status();
$pred_daily   = SAS_Predictions::count( 'daily' );
$pred_weekly  = SAS_Predictions::count( 'weekly' );
$pred_yearly  = SAS_Predictions::count( 'yearly' );
$k_core       = SAS_Kundali::count( SAS_TIER_CORE );
$k_full       = SAS_Kundali::count( SAS_TIER_FULL );
$k_total      = SAS_Kundali::count();
?>
<div class="wrap sas-wrap">
    <h1><?php esc_html_e( 'Astro Services — Dashboard', 'sanathan-astro' ); ?></h1>

    <?php
    $dash_message = sanitize_text_field( $_GET['message'] ?? '' );
    $dash_job     = sanitize_text_field( $_GET['job'] ?? '' );
    if ( $dash_message === 'queued' ) :
    ?>
    <div class="notice notice-info is-dismissible">
        <p><?php echo esc_html( sprintf( __( '⏳ %s predictions refresh queued — running in background. Reload in ~2 minutes to see updated counts.', 'sanathan-astro' ), ucfirst( $dash_job ) ) ); ?></p>
    </div>
    <?php endif; ?>

    <!-- API Key status -->
    <div class="sas-notice <?php echo $api_key_ok ? 'sas-ok' : 'sas-warn'; ?>">
        <?php if ( $api_key_ok ) : ?>
            ✅ <?php esc_html_e( 'VedicAstroAPI key is configured.', 'sanathan-astro' ); ?>
        <?php else : ?>
            ⚠️ <?php esc_html_e( 'VedicAstroAPI key is NOT set. Go to the existing VedicAstro plugin settings and enter your API key.', 'sanathan-astro' ); ?>
        <?php endif; ?>
    </div>

    <!-- Stats cards -->
    <div class="sas-cards">
        <div class="sas-card">
            <h3><?php esc_html_e( 'Predictions Cached', 'sanathan-astro' ); ?></h3>
            <table class="widefat striped">
                <tr><th><?php esc_html_e( 'Daily', 'sanathan-astro' ); ?></th><td><?php echo esc_html( number_format( $pred_daily ) ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Weekly', 'sanathan-astro' ); ?></th><td><?php echo esc_html( number_format( $pred_weekly ) ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Yearly', 'sanathan-astro' ); ?></th><td><?php echo esc_html( number_format( $pred_yearly ) ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Total', 'sanathan-astro' ); ?></th><td><strong><?php echo esc_html( number_format( $pred_daily + $pred_weekly + $pred_yearly ) ); ?></strong></td></tr>
            </table>
        </div>

        <div class="sas-card">
            <h3><?php esc_html_e( 'Kundali Records', 'sanathan-astro' ); ?></h3>
            <table class="widefat striped">
                <tr><th><?php esc_html_e( 'Core (Basic)', 'sanathan-astro' ); ?></th><td><?php echo esc_html( number_format( $k_core ) ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Full (Premium)', 'sanathan-astro' ); ?></th><td><?php echo esc_html( number_format( $k_full ) ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Total', 'sanathan-astro' ); ?></th><td><strong><?php echo esc_html( number_format( $k_total ) ); ?></strong></td></tr>
            </table>
        </div>

        <div class="sas-card">
            <h3><?php esc_html_e( 'Cron Schedule', 'sanathan-astro' ); ?></h3>
            <table class="widefat striped">
                <?php foreach ( $cron_status as $job => $info ) : ?>
                <tr>
                    <th><?php echo esc_html( ucfirst( $job ) ); ?></th>
                    <td>
                        <?php echo esc_html( $info['next_run'] ); ?><br>
                        <?php if ( ! empty( $info['last_run']['run_at'] ) ) : ?>
                            <small><?php esc_html_e( 'Last:', 'sanathan-astro' ); ?> <?php echo esc_html( $info['last_run']['run_at'] ); ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="sas-card">
        <h3><?php esc_html_e( 'Quick Actions', 'sanathan-astro' ); ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
            <?php wp_nonce_field( 'sas_refresh_predictions' ); ?>
            <input type="hidden" name="action" value="sas_refresh_predictions">
            <input type="hidden" name="job" value="daily">
            <button type="submit" class="button button-primary">⚡ <?php esc_html_e( 'Refresh Daily Predictions Now', 'sanathan-astro' ); ?></button>
        </form>
        &nbsp;
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
            <?php wp_nonce_field( 'sas_refresh_predictions' ); ?>
            <input type="hidden" name="action" value="sas_refresh_predictions">
            <input type="hidden" name="job" value="weekly">
            <button type="submit" class="button">🗓 <?php esc_html_e( 'Refresh Weekly Now', 'sanathan-astro' ); ?></button>
        </form>
        &nbsp;
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
            <?php wp_nonce_field( 'sas_refresh_predictions' ); ?>
            <input type="hidden" name="action" value="sas_refresh_predictions">
            <input type="hidden" name="job" value="yearly">
            <button type="submit" class="button">📅 <?php esc_html_e( 'Refresh Yearly Now', 'sanathan-astro' ); ?></button>
        </form>
    </div>

    <!-- REST API endpoints reference -->
    <div class="sas-card">
        <h3><?php esc_html_e( 'Flutter REST API Endpoints', 'sanathan-astro' ); ?></h3>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e( 'Method', 'sanathan-astro' ); ?></th><th><?php esc_html_e( 'Endpoint', 'sanathan-astro' ); ?></th><th><?php esc_html_e( 'Auth', 'sanathan-astro' ); ?></th></tr></thead>
            <tbody>
                <tr><td>GET</td><td><code>/wp-json/sanathan/v1/predictions?zodiac=aries&amp;cycle=daily&amp;lang=en</code></td><td><?php esc_html_e( 'None', 'sanathan-astro' ); ?></td></tr>
                <tr><td>POST</td><td><code>/wp-json/sanathan/v1/kundali</code></td><td><?php esc_html_e( 'Logged-in', 'sanathan-astro' ); ?></td></tr>
                <tr><td>GET</td><td><code>/wp-json/sanathan/v1/kundali</code></td><td><?php esc_html_e( 'Logged-in', 'sanathan-astro' ); ?></td></tr>
                <tr><td>POST</td><td><code>/wp-json/sanathan/v1/kundali/upgrade</code></td><td><?php esc_html_e( 'Logged-in', 'sanathan-astro' ); ?></td></tr>
                <tr><td>GET</td><td><code>/wp-json/sanathan/v1/user/tier</code></td><td><?php esc_html_e( 'Logged-in', 'sanathan-astro' ); ?></td></tr>
                <tr><td>POST</td><td><code>/wp-json/sanathan/v1/device/register</code></td><td><?php esc_html_e( 'Logged-in', 'sanathan-astro' ); ?></td></tr>
                <tr><td>GET</td><td><code>/wp-json/sanathan/v1/notifications</code></td><td><?php esc_html_e( 'Logged-in', 'sanathan-astro' ); ?></td></tr>
                <tr><td>POST</td><td><code>/wp-json/sanathan/v1/guruji/chat</code> <span class="sas-badge">Phase 2</span></td><td><?php esc_html_e( 'Logged-in', 'sanathan-astro' ); ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<style>
.sas-wrap { max-width: 1200px; }
.sas-notice { padding: 10px 15px; margin: 10px 0 20px; border-radius: 4px; border-left: 4px solid; }
.sas-ok   { background: #d4edda; border-color: #28a745; }
.sas-warn { background: #fff3cd; border-color: #ffc107; }
.sas-cards { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 20px; }
.sas-card { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px 20px; min-width: 300px; flex: 1; }
.sas-card h3 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 8px; }
.sas-badge { background: #0073aa; color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 10px; vertical-align: middle; }
</style>
