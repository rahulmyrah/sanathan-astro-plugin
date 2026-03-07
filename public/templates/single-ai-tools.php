<?php
/**
 * Single template for ai-toolai_tool CPT.
 * Served by SAS_AI_Tools_Frontend::maybe_override_template()
 *
 * Layout:
 *   - Hero banner (featured image + title + category)
 *   - Main column: AIP form + How-to-use guide
 *   - Sidebar: related tools + category list
 *
 * @package Sanathan_Astro_Services
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! have_posts() ) {
    get_header();
    echo '<p style="padding:40px;text-align:center;">' . esc_html__( 'Tool not found.', 'sanathan-astro' ) . '</p>';
    get_footer();
    return;
}

the_post();

$post_id   = get_the_ID();
$title     = get_the_title();
$thumb_id  = get_post_thumbnail_id();
$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'full' ) : '';
$archive_url = get_post_type_archive_link( 'ai-toolai_tool' );

// Primary category
$post_cats   = wp_get_post_terms( $post_id, 'ai_tool_category', [ 'orderby' => 'count', 'order' => 'DESC' ] );
$primary_cat = ( ! is_wp_error( $post_cats ) && ! empty( $post_cats ) ) ? $post_cats[0] : null;

// Split content: shortcode vs guide
$content_raw = get_the_content();

// Separate the AIP shortcode from the how-to-use guide
$form_shortcode = '';
$guide_html     = '';

// Extract [aipkit_ai_form ...] shortcode
if ( preg_match( '/\[aipkit_ai_form[^\]]*\]/i', $content_raw, $m ) ) {
    $form_shortcode = $m[0];
    $guide_html     = trim( str_replace( $form_shortcode, '', $content_raw ) );
} else {
    // Fallback: all content in guide area
    $guide_html = $content_raw;
}

// Related tools (same category, exclude current)
$related_args = [
    'post_type'      => 'ai-toolai_tool',
    'post_status'    => 'publish',
    'posts_per_page' => 6,
    'post__not_in'   => [ $post_id ],
    'orderby'        => 'rand',
];
if ( $primary_cat ) {
    $related_args['tax_query'] = [ [
        'taxonomy' => 'ai_tool_category',
        'field'    => 'term_id',
        'terms'    => $primary_cat->term_id,
    ] ];
}
$related_query = new WP_Query( $related_args );

// All categories for sidebar
$all_cats = get_terms( [
    'taxonomy'   => 'ai_tool_category',
    'hide_empty' => true,
    'orderby'    => 'count',
    'order'      => 'DESC',
] );
if ( is_wp_error( $all_cats ) ) {
    $all_cats = [];
}

get_header();
?>

<div class="sas-ai-tools-page" id="sas-single">

    <!-- ── Hero ─────────────────────────────────────────────── -->
    <section class="sas-single-hero">
        <?php if ( $thumb_url ) : ?>
            <div class="sas-single-hero-bg" style="background-image: url('<?php echo esc_url( $thumb_url ); ?>');" role="img" aria-label="<?php echo esc_attr( $title ); ?>"></div>
        <?php endif; ?>
        <div class="sas-single-hero-overlay"></div>

        <div class="sas-single-hero-content">
            <!-- Back button -->
            <a href="<?php echo esc_url( $archive_url ?: home_url( '/ai-tools/' ) ); ?>" class="sas-back-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 5 5 12 12 19"/></svg>
                <?php esc_html_e( 'All Tools', 'sanathan-astro' ); ?>
            </a>

            <!-- Breadcrumb -->
            <nav class="sas-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'sanathan-astro' ); ?>">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'sanathan-astro' ); ?></a>
                <span class="sas-breadcrumb-sep">›</span>
                <a href="<?php echo esc_url( $archive_url ?: home_url( '/ai-tools/' ) ); ?>"><?php esc_html_e( 'AI Tools', 'sanathan-astro' ); ?></a>
                <?php if ( $primary_cat ) : ?>
                    <span class="sas-breadcrumb-sep">›</span>
                    <a href="<?php echo esc_url( get_term_link( $primary_cat ) ); ?>"><?php echo esc_html( $primary_cat->name ); ?></a>
                <?php endif; ?>
                <span class="sas-breadcrumb-sep">›</span>
                <span class="sas-breadcrumb-current"><?php echo esc_html( $title ); ?></span>
            </nav>

            <!-- Title -->
            <h1 class="sas-single-title"><?php echo esc_html( $title ); ?></h1>

            <!-- Meta -->
            <div class="sas-single-meta">
                <?php if ( $primary_cat ) : ?>
                    <a href="<?php echo esc_url( get_term_link( $primary_cat ) ); ?>" class="sas-single-cat-badge">
                        <span>✦</span>
                        <?php echo esc_html( $primary_cat->name ); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ── Two-column layout ─────────────────────────────────── -->
    <div class="sas-single-layout">

        <!-- ── Main Column ───────────────────────────────────── -->
        <main class="sas-single-main">

            <!-- AIP Form Box -->
            <?php if ( $form_shortcode ) : ?>
            <div class="sas-tool-form-box">
                <div class="sas-tool-form-header">
                    <div class="sas-tool-form-header-icon">✦</div>
                    <div>
                        <h3><?php echo esc_html( $title ); ?></h3>
                        <p><?php esc_html_e( 'Fill in the details below to get your personalised reading', 'sanathan-astro' ); ?></p>
                    </div>
                </div>
                <div class="sas-tool-form-body">
                    <?php echo do_shortcode( $form_shortcode ); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- How-to-use Guide -->
            <?php if ( $guide_html ) : ?>
            <div class="sas-tool-guide">
                <?php
                // Apply standard WordPress content filters (wpautop etc.)
                echo wp_kses_post( apply_filters( 'the_content', $guide_html ) );
                ?>
            </div>
            <?php endif; ?>

        </main>

        <!-- ── Sidebar ───────────────────────────────────────── -->
        <aside class="sas-single-sidebar">

            <!-- Related Tools -->
            <?php if ( $related_query->have_posts() ) : ?>
            <div class="sas-sidebar-card">
                <div class="sas-sidebar-card-header">
                    <span class="sas-sidebar-card-icon">✦</span>
                    <h4><?php esc_html_e( 'Related Tools', 'sanathan-astro' ); ?></h4>
                </div>
                <nav class="sas-related-list" aria-label="<?php esc_attr_e( 'Related tools', 'sanathan-astro' ); ?>">
                    <?php while ( $related_query->have_posts() ) : $related_query->the_post(); ?>
                        <?php
                        $r_id      = get_the_ID();
                        $r_title   = get_the_title();
                        $r_link    = get_the_permalink();
                        $r_thumb   = get_post_thumbnail_id() ? wp_get_attachment_image_url( get_post_thumbnail_id(), 'thumbnail' ) : '';
                        $r_cats    = wp_get_post_terms( $r_id, 'ai_tool_category', [ 'number' => 1 ] );
                        $r_cat_name = ( ! is_wp_error( $r_cats ) && ! empty( $r_cats ) ) ? $r_cats[0]->name : '';
                        ?>
                        <a href="<?php echo esc_url( $r_link ); ?>" class="sas-related-item">
                            <div class="sas-related-thumb">
                                <?php if ( $r_thumb ) : ?>
                                    <img src="<?php echo esc_url( $r_thumb ); ?>" alt="<?php echo esc_attr( $r_title ); ?>" loading="lazy">
                                <?php else : ?>
                                    <div class="sas-related-thumb-placeholder">✦</div>
                                <?php endif; ?>
                            </div>
                            <div class="sas-related-info">
                                <div class="sas-related-name"><?php echo esc_html( $r_title ); ?></div>
                                <?php if ( $r_cat_name ) : ?>
                                    <div class="sas-related-cat"><?php echo esc_html( $r_cat_name ); ?></div>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endwhile; ?>
                    <?php wp_reset_postdata(); ?>
                </nav>
            </div>
            <?php endif; ?>

            <!-- Browse Categories -->
            <?php if ( ! empty( $all_cats ) ) : ?>
            <div class="sas-sidebar-card">
                <div class="sas-sidebar-card-header">
                    <span class="sas-sidebar-card-icon">☰</span>
                    <h4><?php esc_html_e( 'Browse Categories', 'sanathan-astro' ); ?></h4>
                </div>
                <nav class="sas-cat-list" aria-label="<?php esc_attr_e( 'Tool categories', 'sanathan-astro' ); ?>">
                    <a href="<?php echo esc_url( $archive_url ?: home_url( '/ai-tools/' ) ); ?>" class="sas-cat-item">
                        <span class="sas-cat-item-left">
                            <span class="sas-cat-dot" style="background:#E8891A;"></span>
                            <?php esc_html_e( 'All Tools', 'sanathan-astro' ); ?>
                        </span>
                    </a>
                    <?php
                    $cat_colors = [ '#E8891A', '#D4A843', '#7B2D8B', '#2E86AB', '#43A047', '#E53935', '#00897B' ];
                    $ci = 0;
                    foreach ( $all_cats as $cat ) :
                        $col = $cat_colors[ $ci % count( $cat_colors ) ];
                        $ci++;
                    ?>
                    <a href="<?php echo esc_url( get_term_link( $cat ) ); ?>" class="sas-cat-item <?php echo ( $primary_cat && $primary_cat->term_id === $cat->term_id ) ? 'active' : ''; ?>">
                        <span class="sas-cat-item-left">
                            <span class="sas-cat-dot" style="background:<?php echo esc_attr( $col ); ?>;"></span>
                            <?php echo esc_html( $cat->name ); ?>
                        </span>
                        <span class="sas-cat-count"><?php echo (int) $cat->count; ?></span>
                    </a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <?php endif; ?>

            <!-- CTA: Explore all tools -->
            <div style="text-align:center; padding: 4px 0;">
                <a href="<?php echo esc_url( $archive_url ?: home_url( '/ai-tools/' ) ); ?>" class="sas-archive-link">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    <?php esc_html_e( 'Explore All Tools', 'sanathan-astro' ); ?>
                </a>
            </div>

        </aside>
    </div>

</div><!-- .sas-ai-tools-page -->

<?php get_footer(); ?>
