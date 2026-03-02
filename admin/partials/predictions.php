<?php
/**
 * Admin Predictions — cache table + manual refresh buttons.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$message      = sanitize_text_field( $_GET['message'] ?? '' );
$job_done     = sanitize_text_field( $_GET['job'] ?? '' );

$cycle_filter = sanitize_text_field( $_GET['cycle'] ?? '' );
$lang_filter  = sanitize_text_field( $_GET['lang'] ?? '' );
$page_num     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page     = 50;
$offset       = ( $page_num - 1 ) * $per_page;

$rows     = SAS_Predictions::admin_list( $per_page, $offset, $cycle_filter, $lang_filter );
$total_d  = SAS_Predictions::count( 'daily' );
$total_w  = SAS_Predictions::count( 'weekly' );
$total_y  = SAS_Predictions::count( 'yearly' );
?>
<div class="wrap sas-wrap">
    <h1><?php esc_html_e( 'Predictions Cache', 'sanathan-astro' ); ?></h1>

    <?php if ( $message === 'refreshed' ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html( sprintf( __( '✅ %s predictions refreshed successfully.', 'sanathan-astro' ), ucfirst( $job_done ) ) ); ?></p>
        </div>
    <?php elseif ( $message === 'queued' ) : ?>
        <div class="notice notice-info is-dismissible">
            <p><?php echo esc_html( sprintf( __( '⏳ %s predictions refresh queued — running in background (9 langs × 12 zodiacs). Reload this page in ~2 minutes to see the cached rows.', 'sanathan-astro' ), ucfirst( $job_done ) ) ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Summary -->
    <p>
        <strong><?php esc_html_e( 'Daily:', 'sanathan-astro' ); ?></strong> <?php echo esc_html( number_format( $total_d ) ); ?> &nbsp;|&nbsp;
        <strong><?php esc_html_e( 'Weekly:', 'sanathan-astro' ); ?></strong> <?php echo esc_html( number_format( $total_w ) ); ?> &nbsp;|&nbsp;
        <strong><?php esc_html_e( 'Yearly:', 'sanathan-astro' ); ?></strong> <?php echo esc_html( number_format( $total_y ) ); ?>
    </p>

    <!-- Manual refresh buttons -->
    <div style="margin-bottom:15px; display:flex; gap:10px; flex-wrap:wrap;">
        <?php foreach ( [ 'daily', 'weekly', 'yearly' ] as $job ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'sas_refresh_predictions' ); ?>
            <input type="hidden" name="action" value="sas_refresh_predictions">
            <input type="hidden" name="job" value="<?php echo esc_attr( $job ); ?>">
            <button type="submit" class="button button-primary">
                ⚡ <?php echo esc_html( sprintf( __( 'Refresh %s', 'sanathan-astro' ), ucfirst( $job ) ) ); ?>
            </button>
        </form>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <form method="get" style="margin-bottom:15px;">
        <input type="hidden" name="page" value="sas-predictions">
        <select name="cycle">
            <option value=""><?php esc_html_e( 'All Cycles', 'sanathan-astro' ); ?></option>
            <?php foreach ( [ 'daily', 'weekly', 'yearly' ] as $c ) : ?>
                <option value="<?php echo esc_attr( $c ); ?>" <?php selected( $cycle_filter, $c ); ?>><?php echo esc_html( ucfirst( $c ) ); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="lang">
            <option value=""><?php esc_html_e( 'All Languages', 'sanathan-astro' ); ?></option>
            <?php foreach ( SAS_SUPPORTED_LANGS as $l ) : ?>
                <option value="<?php echo esc_attr( $l ); ?>" <?php selected( $lang_filter, $l ); ?>><?php echo esc_html( strtoupper( $l ) ); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button"><?php esc_html_e( 'Filter', 'sanathan-astro' ); ?></button>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sas-predictions' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'sanathan-astro' ); ?></a>
    </form>

    <!-- Cache table -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'ID', 'sanathan-astro' ); ?></th>
                <th><?php esc_html_e( 'Zodiac', 'sanathan-astro' ); ?></th>
                <th><?php esc_html_e( 'Language', 'sanathan-astro' ); ?></th>
                <th><?php esc_html_e( 'Cycle', 'sanathan-astro' ); ?></th>
                <th><?php esc_html_e( 'Period Key', 'sanathan-astro' ); ?></th>
                <th><?php esc_html_e( 'Fetched At', 'sanathan-astro' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="6"><?php esc_html_e( 'No cached predictions yet. Click a Refresh button above to populate the cache.', 'sanathan-astro' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $rows as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $row['id'] ); ?></td>
                    <td><?php echo esc_html( ucfirst( $row['zodiac'] ) ); ?></td>
                    <td><?php echo esc_html( strtoupper( $row['lang'] ) ); ?></td>
                    <td><?php echo esc_html( ucfirst( $row['cycle'] ) ); ?></td>
                    <td><code><?php echo esc_html( $row['period_key'] ); ?></code></td>
                    <td><?php echo esc_html( $row['fetched_at'] ); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php
    $total_filtered = SAS_Predictions::count( $cycle_filter );
    $total_pages    = max( 1, (int) ceil( $total_filtered / $per_page ) );
    if ( $total_pages > 1 ) :
        echo paginate_links( [
            'base'    => add_query_arg( 'paged', '%#%' ),
            'format'  => '',
            'current' => $page_num,
            'total'   => $total_pages,
        ] );
    endif;
    ?>
</div>

<style>
.sas-wrap { max-width: 1200px; }
</style>
