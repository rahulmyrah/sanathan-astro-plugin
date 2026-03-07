<?php
/**
 * Admin page: AI Tools Setup — bulk import AIP forms, generate SEO content + featured images.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings    = sas_get_settings();
$gemini_ok   = ! empty( $settings['gemini_api_key'] );
$cpt         = 'ai-toolai_tool';
$taxonomy    = 'ai_tool_category';

// Live counts
global $wpdb;
$total_ai_tools = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = '{$cpt}' AND post_status = 'publish'"
);
$without_cat = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
     LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
     LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = '{$taxonomy}'
     WHERE p.post_type = '{$cpt}' AND p.post_status = 'publish' AND tt.term_id IS NULL"
);
$without_img = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} p
     WHERE p.post_type = '{$cpt}' AND p.post_status = 'publish'
     AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id')"
);
$without_guide = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts}
     WHERE post_type = '{$cpt}' AND post_status = 'publish' AND post_content NOT LIKE '%sas-tool-guide%'"
);
$total_aip_forms = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wpaicg_form' AND post_status = 'publish'"
);
$cat_count = wp_count_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
if ( is_wp_error( $cat_count ) ) $cat_count = 0;
?>
<div class="wrap sas-wrap">
    <h1>🛠 <?php esc_html_e( 'AI Tools Setup', 'sanathan-astro' ); ?></h1>
    <p class="description">
        <?php esc_html_e( 'Bulk-import AIP forms as AI Tool posts, generate SEO "How to Use" content via Gemini, and create featured images.', 'sanathan-astro' ); ?>
    </p>

    <!-- ── Status Cards ──────────────────────────────────────────────────────── -->
    <div style="display:flex; gap:16px; margin:20px 0; flex-wrap:wrap;">

        <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px 20px; min-width:180px;">
            <strong>🤖 Gemini API</strong><br>
            <?php if ( $gemini_ok ) : ?>
                <span style="color:green;">✅ Configured</span>
            <?php else : ?>
                <span style="color:red;">❌ Not set</span><br>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sas-settings' ) ); ?>"><?php esc_html_e( 'Go to Settings →', 'sanathan-astro' ); ?></a>
            <?php endif; ?>
        </div>

        <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px 20px; min-width:180px;">
            <strong>📋 AIP Forms</strong><br>
            <span style="color:#333;"><?php echo esc_html( $total_aip_forms ); ?> forms in AIP</span>
        </div>

        <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px 20px; min-width:180px;">
            <strong>🏷 Categories</strong><br>
            <span style="color:<?php echo $cat_count >= 7 ? 'green' : '#e67e00'; ?>;">
                <?php echo esc_html( $cat_count ); ?> / 7 created
            </span>
        </div>

        <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px 20px; min-width:180px;">
            <strong>🧰 AI Tool Posts</strong><br>
            <span style="color:#333;"><?php echo esc_html( $total_ai_tools ); ?> published</span>
        </div>

        <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px 20px; min-width:180px;">
            <strong>🖼 Missing Images</strong><br>
            <span style="color:<?php echo $without_img > 0 ? '#e67e00' : 'green'; ?>;">
                <?php echo esc_html( $without_img ); ?> posts without featured image
            </span>
        </div>

        <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px 20px; min-width:180px;">
            <strong>📝 Missing Guides</strong><br>
            <span style="color:<?php echo $without_guide > 0 ? '#e67e00' : 'green'; ?>;">
                <?php echo esc_html( $without_guide ); ?> posts without How-to-Use
            </span>
        </div>

    </div>

    <?php if ( ! $gemini_ok ) : ?>
    <div class="notice notice-warning">
        <p>
            ⚠ <?php esc_html_e( 'Gemini API key is not configured. SEO content and image generation will be skipped.', 'sanathan-astro' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sas-settings' ) ); ?>"><?php esc_html_e( 'Configure in Settings →', 'sanathan-astro' ); ?></a>
        </p>
    </div>
    <?php endif; ?>

    <!-- ── Step 1: Setup Categories ──────────────────────────────────────────── -->
    <div class="sas-setup-card">
        <h2 style="margin-top:0;">Step 1 — 🏷 <?php esc_html_e( 'Setup AI Tool Categories', 'sanathan-astro' ); ?></h2>
        <p><?php esc_html_e( 'Creates the 7 canonical categories in the ai_tool_category taxonomy:', 'sanathan-astro' ); ?></p>
        <ul style="margin-left:20px; list-style:disc;">
            <li>Vedic Astrology</li><li>Bhakti &amp; Devotion</li><li>Mantra &amp; Prayer</li>
            <li>Hindu Scriptures</li><li>Spiritual Life</li><li>Vaastu &amp; Remedies</li><li>Festivals &amp; Rituals</li>
        </ul>
        <button type="button" id="sas-setup-categories" class="button button-primary">
            🏷 <?php esc_html_e( 'Create Categories', 'sanathan-astro' ); ?>
        </button>
        <div id="sas-categories-result" style="margin-top:10px;"></div>
    </div>

    <!-- ── Step 2: Update Existing Posts ─────────────────────────────────────── -->
    <div class="sas-setup-card">
        <h2 style="margin-top:0;">Step 2 — 🔄 <?php esc_html_e( 'Update Existing AI Tool Posts', 'sanathan-astro' ); ?></h2>
        <p>
            <?php printf(
                esc_html__( '%d of %d existing posts are missing categories. This will assign categories, tags, and generate SEO "How to Use" content for posts that lack it.', 'sanathan-astro' ),
                $without_cat,
                $total_ai_tools
            ); ?>
        </p>
        <?php if ( ! $gemini_ok ) : ?>
        <p class="description" style="color:#e67e00;">⚠ <?php esc_html_e( 'Gemini key not set — SEO content will be skipped, only categories/tags will be assigned.', 'sanathan-astro' ); ?></p>
        <?php endif; ?>
        <button type="button" id="sas-update-existing" class="button button-secondary">
            🔄 <?php esc_html_e( 'Update Existing Posts', 'sanathan-astro' ); ?>
        </button>
        <div id="sas-update-progress" style="margin-top:10px; display:none;">
            <span class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span>
            <?php esc_html_e( 'Updating posts… Gemini calls may take a few minutes.', 'sanathan-astro' ); ?>
        </div>
        <div id="sas-update-result" style="margin-top:10px;"></div>
    </div>

    <!-- ── Step 3: Import AI Tool Posts ──────────────────────────────────────── -->
    <div class="sas-setup-card">
        <h2 style="margin-top:0;">Step 3 — 🚀 <?php esc_html_e( 'Import AIP Forms as AI Tool Posts', 'sanathan-astro' ); ?></h2>
        <p>
            <?php printf(
                esc_html__( 'Scans all %d AIP forms, skips non-Sanathan forms and already-imported ones, then creates AI Tool posts with auto-detected categories, tags, and Gemini-generated SEO content.', 'sanathan-astro' ),
                $total_aip_forms
            ); ?>
        </p>
        <p class="description">
            <?php esc_html_e( 'Skipped: Blog Post Generator, Customer Support Reply Builder, YouTube Script Writer, Respectful Community Message Rewriter. Safe to run multiple times — duplicates are detected by title.', 'sanathan-astro' ); ?>
        </p>
        <?php if ( ! $gemini_ok ) : ?>
        <p class="description" style="color:#e67e00;">⚠ <?php esc_html_e( 'Gemini key not set — posts will be created without SEO guide content.', 'sanathan-astro' ); ?></p>
        <?php endif; ?>
        <button type="button" id="sas-import-tools" class="button button-primary">
            🚀 <?php esc_html_e( 'Import AI Tool Posts', 'sanathan-astro' ); ?>
        </button>
        <div id="sas-import-progress" style="margin-top:10px; display:none;">
            <span class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span>
            <?php esc_html_e( 'Importing… this may take 5-10 minutes (Gemini API calls per post). Do not close this page.', 'sanathan-astro' ); ?>
        </div>
        <div id="sas-import-result" style="margin-top:10px;"></div>
    </div>

    <!-- ── Step 4: Generate Featured Images ──────────────────────────────────── -->
    <div class="sas-setup-card">
        <h2 style="margin-top:0;">Step 4 — 🖼 <?php esc_html_e( 'Generate Featured Images', 'sanathan-astro' ); ?></h2>
        <p>
            <?php printf(
                esc_html__( '%d posts currently have no featured image. Uses Gemini Imagen 3 API to generate a spiritual illustration for each.', 'sanathan-astro' ),
                $without_img
            ); ?>
        </p>
        <p class="description">
            <?php esc_html_e( 'Each image is generated, uploaded to Media Library, and set as the post\'s featured image. Rate-limited at 0.5s per image.', 'sanathan-astro' ); ?>
        </p>
        <button type="button" id="sas-generate-images" class="button button-secondary" <?php disabled( ! $gemini_ok ); ?>>
            🖼 <?php esc_html_e( 'Generate Featured Images', 'sanathan-astro' ); ?>
        </button>
        <div id="sas-images-progress" style="margin-top:10px; display:none;">
            <span class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span>
            <?php esc_html_e( 'Generating images… this may take several minutes. Do not close this page.', 'sanathan-astro' ); ?>
        </div>
        <div id="sas-images-result" style="margin-top:10px;"></div>
    </div>

    <!-- ── Step 5: Next Steps (Elementor) ────────────────────────────────────── -->
    <div class="sas-setup-card" style="background:#f9f9f9;">
        <h2 style="margin-top:0;">Step 5 — 🎨 <?php esc_html_e( 'Create Elementor Templates', 'sanathan-astro' ); ?></h2>
        <p><?php esc_html_e( 'After importing posts, create two Elementor Theme Builder templates:', 'sanathan-astro' ); ?></p>
        <ol>
            <li>
                <strong><?php esc_html_e( 'Archive Template', 'sanathan-astro' ); ?></strong> —
                <?php esc_html_e( 'Elementor → Theme Builder → Add New Template → Archive → apply to AI Tool archive.', 'sanathan-astro' ); ?>
                <br><em><?php esc_html_e( 'Layout: Hero + 3-column card grid with featured image, category, title, excerpt, "Use Tool →" button.', 'sanathan-astro' ); ?></em>
            </li>
            <li style="margin-top:10px;">
                <strong><?php esc_html_e( 'Single Template', 'sanathan-astro' ); ?></strong> —
                <?php esc_html_e( 'Elementor → Theme Builder → Add New Template → Single → apply to AI Tool post type.', 'sanathan-astro' ); ?>
                <br><em><?php esc_html_e( 'Layout: Hero image, category/tag chips, Post Content (renders the AIP form + How-to-Use guide), Related Tools sidebar.', 'sanathan-astro' ); ?></em>
            </li>
        </ol>
        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=elementor_library&tabs_group=theme' ) ); ?>" class="button button-secondary" target="_blank">
            🎨 <?php esc_html_e( 'Open Elementor Theme Builder →', 'sanathan-astro' ); ?>
        </a>
    </div>

</div>

<style>
.sas-wrap { max-width: 960px; }
.sas-setup-card {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 20px;
}
.sas-setup-card h2 { margin-bottom: 12px; }
</style>

<script>
jQuery(function($){

    function sas_ajax_btn(btnId, progressId, resultId, action, nonce, successMsg) {
        $(btnId).on('click', function(){
            var $btn = $(this);
            $btn.prop('disabled', true);
            $(progressId).show();
            $(resultId).html('');

            $.post(ajaxurl, { action: action, nonce: nonce }, function(res){
                $(progressId).hide();
                if (res.success && res.data) {
                    var d = res.data;
                    $(resultId).css('color', d.errors > 0 ? 'orange' : 'green').html(successMsg(d));
                } else {
                    $(resultId).css('color','red').text('❌ ' + (res.data?.message || 'Failed.'));
                }
            }).fail(function(){
                $(progressId).hide();
                $(resultId).css('color','red').text('❌ Request timed out. Try again.');
            }).always(function(){
                $btn.prop('disabled', false);
            });
        });
    }

    // Step 1: Setup categories
    $('#sas-setup-categories').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#sas-categories-result').html('');

        $.post(ajaxurl, {
            action: 'sas_setup_ai_categories',
            nonce:  '<?php echo esc_js( wp_create_nonce( 'sas_setup_ai_categories' ) ); ?>'
        }, function(res){
            if (res.success && res.data) {
                var d = res.data;
                $('#sas-categories-result').css('color','green').html(
                    '✅ Done! Created: <strong>' + d.created + '</strong>, Already existed: <strong>' + d.existing + '</strong> (total: ' + d.total + ')'
                );
            } else {
                $('#sas-categories-result').css('color','red').text('❌ ' + (res.data?.message || 'Failed.'));
            }
        }).always(function(){ $btn.prop('disabled', false); });
    });

    // Step 2: Update existing posts
    $('#sas-update-existing').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#sas-update-progress').show();
        $('#sas-update-result').html('');

        $.post(ajaxurl, {
            action: 'sas_update_existing_ai_tools',
            nonce:  '<?php echo esc_js( wp_create_nonce( 'sas_update_existing_ai_tools' ) ); ?>'
        }, function(res){
            $('#sas-update-progress').hide();
            if (res.success && res.data) {
                var d = res.data;
                $('#sas-update-result').css('color','green').html(
                    '✅ Updated: <strong>' + d.updated + '</strong> posts. Already complete: <strong>' + d.skipped + '</strong>. Total: ' + d.total
                );
            } else {
                $('#sas-update-result').css('color','red').text('❌ ' + (res.data?.message || 'Failed.'));
            }
        }).fail(function(){
            $('#sas-update-progress').hide();
            $('#sas-update-result').css('color','red').text('❌ Request timed out. Try again (safe to re-run).');
        }).always(function(){ $btn.prop('disabled', false); });
    });

    // Step 3: Import AI tools
    $('#sas-import-tools').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#sas-import-progress').show();
        $('#sas-import-result').html('');

        $.post(ajaxurl, {
            action: 'sas_import_ai_tools',
            nonce:  '<?php echo esc_js( wp_create_nonce( 'sas_import_ai_tools' ) ); ?>'
        }, function(res){
            $('#sas-import-progress').hide();
            if (res.success && res.data) {
                var d = res.data;
                var color = d.errors === 0 ? 'green' : 'orange';
                $('#sas-import-result').css('color', color).html(
                    '✅ Imported: <strong>' + d.imported + '</strong> new posts. ' +
                    'Skipped: <strong>' + d.skipped + '</strong> (duplicates/non-Sanathan). ' +
                    (d.errors > 0 ? '⚠ Errors: <strong>' + d.errors + '</strong>.' : '') +
                    ' (Total AIP forms: ' + d.total + ')'
                );
            } else {
                $('#sas-import-result').css('color','red').text('❌ ' + (res.data?.message || 'Failed.'));
            }
        }).fail(function(){
            $('#sas-import-progress').hide();
            $('#sas-import-result').css('color','red').text('❌ Request timed out. Try again — it skips already-imported posts.');
        }).always(function(){ $btn.prop('disabled', false); });
    });

    // Step 4: Generate images
    $('#sas-generate-images').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#sas-images-progress').show();
        $('#sas-images-result').html('');

        $.post(ajaxurl, {
            action: 'sas_generate_ai_tool_images',
            nonce:  '<?php echo esc_js( wp_create_nonce( 'sas_generate_ai_tool_images' ) ); ?>'
        }, function(res){
            $('#sas-images-progress').hide();
            if (res.success && res.data) {
                var d = res.data;
                var color = d.errors === 0 ? 'green' : 'orange';
                $('#sas-images-result').css('color', color).html(
                    '✅ Generated: <strong>' + d.generated + '</strong> images. ' +
                    (d.errors > 0 ? '⚠ Errors: <strong>' + d.errors + '</strong> (check PHP error log).' : '') +
                    ' Total processed: ' + d.total
                );
            } else {
                $('#sas-images-result').css('color','red').text('❌ ' + (res.data?.message || 'Failed.'));
            }
        }).fail(function(){
            $('#sas-images-progress').hide();
            $('#sas-images-result').css('color','red').text('❌ Request timed out. Try again — already-generated images are skipped.');
        }).always(function(){ $btn.prop('disabled', false); });
    });

});
</script>
