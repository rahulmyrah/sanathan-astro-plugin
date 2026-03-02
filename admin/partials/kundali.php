<?php
/**
 * Admin Kundali — stored Kundalis table + tier management.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$search   = sanitize_text_field( $_GET['s'] ?? '' );
$page_num = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page = 50;
$offset   = ( $page_num - 1 ) * $per_page;

$rows     = SAS_Kundali::admin_list( $per_page, $offset, $search );
$k_core   = SAS_Kundali::count( SAS_TIER_CORE );
$k_full   = SAS_Kundali::count( SAS_TIER_FULL );
$k_total  = SAS_Kundali::count();
?>
<div class="wrap sas-wrap">
    <h1><?php esc_html_e( 'Stored Kundalis', 'sanathan-astro' ); ?></h1>

    <p>
        <strong><?php esc_html_e( 'Total:', 'sanathan-astro' ); ?></strong> <?php echo esc_html( number_format( $k_total ) ); ?> &nbsp;|&nbsp;
        <strong><?php esc_html_e( 'Core Tier:', 'sanathan-astro' ); ?></strong> <?php echo esc_html( number_format( $k_core ) ); ?> &nbsp;|&nbsp;
        <strong><?php esc_html_e( 'Full (Premium):', 'sanathan-astro' ); ?></strong> <?php echo esc_html( number_format( $k_full ) ); ?>
    </p>

    <!-- Search -->
    <form method="get" style="margin-bottom:15px;">
        <input type="hidden" name="page" value="sas-kundali">
        <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by name…', 'sanathan-astro' ); ?>" class="regular-text">
        <button type="submit" class="button"><?php esc_html_e( 'Search', 'sanathan-astro' ); ?></button>
        <?php if ( $search ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sas-kundali' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'sanathan-astro' ); ?></a>
        <?php endif; ?>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'ID', 'sanathan-astro' ); ?></th>
                <th><?php esc_html_e( 'User ID', 'sanathan-astro' ); ?></th>
                <th><?php esc_html_e( 'Name', 'sanathan-astro' ); ?></th>
                <th><?php esc_html_e( 'DOB', 'sanathan-astro' ); ?></th>
                <th><?php esc_html_e( 'Location', 'sanathan-astro' ); ?></th>
                <th><?php esc_html_e( 'Language', 'sanathan-astro' ); ?></th>
                <th><?php esc_html_e( 'Tier', 'sanathan-astro' ); ?></th>
                <th><?php esc_html_e( 'Created', 'sanathan-astro' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="8"><?php esc_html_e( 'No Kundali records yet.', 'sanathan-astro' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $rows as $row ) : ?>
                <?php
                    $user      = get_user_by( 'id', $row['user_id'] );
                    $user_name = $user ? $user->display_name . ' (' . $user->user_email . ')' : '#' . $row['user_id'];
                ?>
                <tr>
                    <td><?php echo esc_html( $row['id'] ); ?></td>
                    <td><?php echo esc_html( $user_name ); ?></td>
                    <td><?php echo esc_html( $row['name'] ?: '—' ); ?></td>
                    <td><?php echo esc_html( $row['dob'] ); ?></td>
                    <td><?php echo esc_html( $row['location_name'] ?: '—' ); ?></td>
                    <td><?php echo esc_html( strtoupper( $row['lang'] ) ); ?></td>
                    <td>
                        <span class="sas-tier-badge sas-tier-<?php echo esc_attr( $row['tier'] ); ?>">
                            <?php echo esc_html( strtoupper( $row['tier'] ) ); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html( $row['created_at'] ); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php
    $total_pages = max( 1, (int) ceil( $k_total / $per_page ) );
    if ( $total_pages > 1 ) :
        echo paginate_links( [
            'base'    => add_query_arg( 'paged', '%#%' ),
            'format'  => '',
            'current' => $page_num,
            'total'   => $total_pages,
        ] );
    endif;
    ?>

    <!-- Schema info -->
    <div style="margin-top:30px; padding:15px; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;">
        <h4><?php esc_html_e( 'Tier Information', 'sanathan-astro' ); ?></h4>
        <table class="widefat" style="max-width:600px;">
            <tr>
                <th><?php esc_html_e( 'Tier', 'sanathan-astro' ); ?></th>
                <th><?php esc_html_e( 'Data Included', 'sanathan-astro' ); ?></th>
            </tr>
            <tr>
                <td><span class="sas-tier-badge sas-tier-core">CORE</span></td>
                <td><?php esc_html_e( 'Planet Details, Personal Characteristics, Birth Chart Image, Maha Dasha, Antar Dasha', 'sanathan-astro' ); ?></td>
            </tr>
            <tr>
                <td><span class="sas-tier-badge sas-tier-full">FULL</span></td>
                <td><?php esc_html_e( 'Core + Ashtakvarga, Kaalsarp Dosh, Mangal Dosh, Manglik Dosh, Pitra Dosh, Papasamaya', 'sanathan-astro' ); ?></td>
            </tr>
        </table>
    </div>
</div>

<style>
.sas-wrap { max-width: 1200px; }
.sas-tier-badge { padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
.sas-tier-core { background: #0073aa; color: #fff; }
.sas-tier-full { background: #f0a500; color: #fff; }
</style>
