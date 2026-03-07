<?php
/**
 * Archive template for ai-toolai_tool CPT.
 * Served by SAS_AI_Tools_Frontend::maybe_override_template()
 *
 * Handles both:
 *   - Post-type archive  → /ai-tools/
 *   - Taxonomy term      → /ai-tool-category/{slug}/
 *
 * @package Sanathan_Astro_Services
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Active category filter (from URL or taxonomy term) ────────
$active_cat_slug = '';
$active_cat_name = '';
if ( is_tax( 'ai_tool_category' ) ) {
    $term            = get_queried_object();
    $active_cat_slug = $term->slug;
    $active_cat_name = $term->name;
}

// ── Fetch all tools (no pagination — client-side filtering) ───
$tools_query = new WP_Query( [
    'post_type'      => 'ai-toolai_tool',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
] );

// ── Fetch all categories with counts ──────────────────────────
$all_cats = get_terms( [
    'taxonomy'   => 'ai_tool_category',
    'hide_empty' => true,
    'orderby'    => 'count',
    'order'      => 'DESC',
] );
if ( is_wp_error( $all_cats ) ) {
    $all_cats = [];
}

$archive_url = get_post_type_archive_link( 'ai-toolai_tool' );

get_header();
?>

<div class="sas-ai-tools-page" id="sas-archive">

    <!-- ── Hero ─────────────────────────────────────────────── -->
    <section class="sas-hero">
        <div class="sas-hero-inner">
            <div class="sas-hero-eyebrow">
                <span>✦</span>
                <?php echo esc_html( $active_cat_name ?: __( 'DHARMA GUIDES', 'sanathan-astro' ) ); ?>
            </div>

            <h1>
                <?php if ( $active_cat_name ) : ?>
                    <?php echo esc_html( $active_cat_name ); ?> <span style="color:var(--sas-primary);"><?php esc_html_e( 'Sahayaks', 'sanathan-astro' ); ?></span>
                <?php else : ?>
                    <?php esc_html_e( 'Dharma', 'sanathan-astro' ); ?> <span style="color:var(--sas-primary);"><?php esc_html_e( 'Sahayaks', 'sanathan-astro' ); ?></span>
                <?php endif; ?>
            </h1>

            <p class="sas-hero-desc">
                <?php esc_html_e( 'Guides rooted in Vedic shastra — for your Kundali, your Mantra, your Vaastu, your Festivals, and every step of your Sanathan journey. In your own language.', 'sanathan-astro' ); ?>
            </p>

            <!-- Search -->
            <div class="sas-search-wrap">
                <input
                    type="search"
                    id="sas-search"
                    class="sas-search-input"
                    placeholder="<?php esc_attr_e( 'Search guides…', 'sanathan-astro' ); ?>"
                    autocomplete="off"
                    aria-label="<?php esc_attr_e( 'Search Dharma Guides', 'sanathan-astro' ); ?>"
                >
                <svg class="sas-search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </div>
            <p class="sas-search-count" id="sas-count-label" aria-live="polite"></p>
        </div>
    </section>

    <!-- ── Category Filter Bar ───────────────────────────────── -->
    <?php if ( ! empty( $all_cats ) ) : ?>
    <nav class="sas-filter-bar" aria-label="<?php esc_attr_e( 'Filter by category', 'sanathan-astro' ); ?>">
        <div class="sas-filter-inner">
            <button
                class="sas-filter-btn <?php echo ! $active_cat_slug ? 'active' : ''; ?>"
                data-cat="all"
                aria-pressed="<?php echo ! $active_cat_slug ? 'true' : 'false'; ?>"
            >
                <?php esc_html_e( 'All Guides', 'sanathan-astro' ); ?>
                <span class="sas-filter-count"><?php echo (int) $tools_query->found_posts; ?></span>
            </button>

            <?php foreach ( $all_cats as $cat ) : ?>
            <button
                class="sas-filter-btn <?php echo ( $active_cat_slug === $cat->slug ) ? 'active' : ''; ?>"
                data-cat="<?php echo esc_attr( $cat->slug ); ?>"
                aria-pressed="<?php echo ( $active_cat_slug === $cat->slug ) ? 'true' : 'false'; ?>"
            >
                <?php echo esc_html( $cat->name ); ?>
                <span class="sas-filter-count"><?php echo (int) $cat->count; ?></span>
            </button>
            <?php endforeach; ?>
        </div>
    </nav>
    <?php endif; ?>

    <!-- ── Grid ─────────────────────────────────────────────── -->
    <section class="sas-grid-section">
        <div class="sas-section-meta">
            <span class="sas-showing-label" id="sas-count-label" aria-live="polite">
                <?php
                printf(
                    esc_html__( 'Showing %1$s of %2$s guides', 'sanathan-astro' ),
                    '<strong>' . (int) $tools_query->found_posts . '</strong>',
                    '<strong>' . (int) $tools_query->found_posts . '</strong>'
                );
                ?>
            </span>
        </div>

        <div class="sas-tools-grid" role="list">

            <?php if ( $tools_query->have_posts() ) : ?>

                <?php while ( $tools_query->have_posts() ) : $tools_query->the_post(); ?>
                    <?php
                    $post_id   = get_the_ID();
                    $title     = get_the_title();
                    $permalink = get_the_permalink();
                    $excerpt   = get_the_excerpt();
                    $thumb_id  = get_post_thumbnail_id();
                    $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium_large' ) : '';

                    // Primary category
                    $post_cats     = wp_get_post_terms( $post_id, 'ai_tool_category', [ 'orderby' => 'count', 'order' => 'DESC' ] );
                    $primary_cat   = ( ! is_wp_error( $post_cats ) && ! empty( $post_cats ) ) ? $post_cats[0] : null;
                    $cat_name      = $primary_cat ? $primary_cat->name : '';
                    $cat_slug      = $primary_cat ? $primary_cat->slug : '';
                    $cat_link      = $primary_cat ? get_term_link( $primary_cat ) : '';

                    // Tags (first 2)
                    $post_tags = wp_get_post_terms( $post_id, 'ai_tool_tag', [ 'number' => 2 ] );
                    $tag_names = [];
                    if ( ! is_wp_error( $post_tags ) ) {
                        foreach ( $post_tags as $tag ) {
                            $tag_names[] = $tag->name;
                        }
                    }
                    ?>

                    <a
                        href="<?php echo esc_url( $permalink ); ?>"
                        class="sas-tool-card"
                        role="listitem"
                        data-title="<?php echo esc_attr( strtolower( $title ) ); ?>"
                        data-category="<?php echo esc_attr( $cat_slug ); ?>"
                        data-excerpt="<?php echo esc_attr( strtolower( $excerpt ) ); ?>"
                    >
                        <!-- Thumbnail -->
                        <div class="sas-card-thumb">
                            <?php if ( $thumb_url ) : ?>
                                <img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy">
                            <?php else : ?>
                                <div class="sas-card-thumb-placeholder">✦</div>
                            <?php endif; ?>

                            <?php if ( $cat_name ) : ?>
                                <span class="sas-card-cat-badge"><?php echo esc_html( $cat_name ); ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Body -->
                        <div class="sas-card-body">
                            <h2 class="sas-card-title"><?php echo esc_html( $title ); ?></h2>

                            <?php if ( $excerpt ) : ?>
                                <p class="sas-card-excerpt"><?php echo esc_html( wp_trim_words( $excerpt, 18, '…' ) ); ?></p>
                            <?php endif; ?>

                            <div class="sas-card-footer">
                                <span class="sas-card-cta">
                                    <?php esc_html_e( 'Try Now', 'sanathan-astro' ); ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                                    </svg>
                                </span>

                                <?php if ( ! empty( $tag_names ) ) : ?>
                                <div class="sas-card-tags">
                                    <?php foreach ( $tag_names as $tn ) : ?>
                                        <span class="sas-card-tag"><?php echo esc_html( $tn ); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>

                <?php endwhile; ?>
                <?php wp_reset_postdata(); ?>

            <?php else : ?>
                <div class="sas-no-results visible" id="sas-no-results">
                    <span class="sas-no-results-icon">✦</span>
                    <h3><?php esc_html_e( 'No guides found', 'sanathan-astro' ); ?></h3>
                    <p><?php esc_html_e( 'Check back soon — new guides are added regularly.', 'sanathan-astro' ); ?></p>
                </div>
            <?php endif; ?>

            <!-- JS inserts this when search/filter yields zero results -->
            <div class="sas-no-results" id="sas-no-results" aria-live="polite">
                <span class="sas-no-results-icon">🔍</span>
                <h3><?php esc_html_e( 'No guides match your search', 'sanathan-astro' ); ?></h3>
                <p><?php esc_html_e( 'Try a different keyword or browse all categories.', 'sanathan-astro' ); ?></p>
            </div>
        </div>
    </section>

</div><!-- .sas-ai-tools-page -->

<?php get_footer(); ?>
