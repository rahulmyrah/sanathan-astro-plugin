<?php
/**
 * Admin page: Knowledge Base — index Vedic content + User Kundalis into Qdrant.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings = sas_get_settings();
$qdrant   = new SAS_Qdrant();
$aip      = new SAS_AIP_Client();

$qdrant_ok  = $qdrant->is_configured();
$aip_ok     = $aip->has_api_key();
$ready      = $qdrant_ok && $aip_ok;

// Get live collection counts
$knowledge_count = $ready ? $qdrant->count( SAS_Qdrant::KNOWLEDGE_COLLECTION ) : null;
$kundali_count   = $ready ? $qdrant->count( SAS_Qdrant::KUNDALI_COLLECTION )   : null;
$doc_total       = SAS_Knowledge::document_count();
?>
<div class="wrap sas-wrap">
    <h1>📚 <?php esc_html_e( 'Guruji Knowledge Base', 'sanathan-astro' ); ?></h1>
    <p class="description">
        <?php esc_html_e( 'Index the built-in Vedic astrology knowledge into Qdrant so Guruji can search it in real-time when answering questions.', 'sanathan-astro' ); ?>
    </p>

    <!-- ── Status Cards ────────────────────────────────────────────────────── -->
    <div style="display:flex; gap:16px; margin:20px 0; flex-wrap:wrap;">

        <!-- AIP Status -->
        <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px 20px; min-width:200px;">
            <strong>🤖 AIP Plugin</strong><br>
            <?php if ( $aip_ok ) : ?>
                <span style="color:green;">✅ API Key configured</span>
            <?php else : ?>
                <span style="color:red;">❌ API key missing</span><br>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sas-settings' ) ); ?>"><?php esc_html_e( 'Go to Settings →', 'sanathan-astro' ); ?></a>
            <?php endif; ?>
        </div>

        <!-- Qdrant Status -->
        <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px 20px; min-width:200px;">
            <strong>⚡ Qdrant</strong><br>
            <?php if ( $qdrant_ok ) : ?>
                <span style="color:green;">✅ Configured</span>
            <?php else : ?>
                <span style="color:red;">❌ URL/Key missing</span><br>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sas-settings' ) ); ?>"><?php esc_html_e( 'Go to Settings →', 'sanathan-astro' ); ?></a>
            <?php endif; ?>
        </div>

        <!-- Knowledge Collection -->
        <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px 20px; min-width:200px;">
            <strong>📖 sanathan_knowledge</strong><br>
            <?php if ( $knowledge_count !== null ) : ?>
                <span style="color:<?php echo $knowledge_count > 0 ? 'green' : '#999'; ?>;">
                    <?php echo esc_html( $knowledge_count ); ?> / <?php echo esc_html( $doc_total ); ?> docs indexed
                </span>
            <?php else : ?>
                <span style="color:#999;"><?php esc_html_e( 'Not connected', 'sanathan-astro' ); ?></span>
            <?php endif; ?>
        </div>

        <!-- Kundali Collection -->
        <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px 20px; min-width:200px;">
            <strong>🌟 user_kundali</strong><br>
            <?php if ( $kundali_count !== null ) : ?>
                <span style="color:<?php echo $kundali_count > 0 ? 'green' : '#999'; ?>;">
                    <?php echo esc_html( $kundali_count ); ?> user Kundalis indexed
                </span>
            <?php else : ?>
                <span style="color:#999;"><?php esc_html_e( 'Not connected', 'sanathan-astro' ); ?></span>
            <?php endif; ?>
        </div>

    </div>

    <?php if ( ! $ready ) : ?>
    <div class="notice notice-warning">
        <p>
            <?php esc_html_e( '⚠ Cannot index: Please configure AIP API Key and Qdrant URL in', 'sanathan-astro' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sas-settings' ) ); ?>">
                <?php esc_html_e( 'Settings', 'sanathan-astro' ); ?>
            </a>.
        </p>
    </div>
    <?php endif; ?>

    <!-- ── Index Knowledge Base ─────────────────────────────────────────────── -->
    <div style="background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:24px; margin-bottom:20px;">
        <h2 style="margin-top:0;">📖 <?php esc_html_e( 'Index Vedic Knowledge Base', 'sanathan-astro' ); ?></h2>
        <p>
            <?php printf(
                esc_html__( 'Embeds %d built-in Vedic astrology documents (zodiac signs, planets, houses, doshas, remedies, concepts) into the %s Qdrant collection.', 'sanathan-astro' ),
                $doc_total,
                '<code>sanathan_knowledge</code>'
            ); ?>
        </p>
        <p class="description">
            <?php esc_html_e( 'This takes 2-5 minutes (one AIP embedding API call per document). You can run this multiple times — it upserts, not duplicates.', 'sanathan-astro' ); ?>
        </p>
        <button type="button" id="sas-index-knowledge" class="button button-primary" <?php disabled( ! $ready ); ?>>
            🚀 <?php esc_html_e( 'Index Knowledge Base Now', 'sanathan-astro' ); ?>
        </button>
        <div id="sas-knowledge-progress" style="margin-top:12px; display:none;">
            <span class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span>
            <?php esc_html_e( 'Indexing… this may take a few minutes. Do not close this page.', 'sanathan-astro' ); ?>
        </div>
        <div id="sas-knowledge-result" style="margin-top:12px;"></div>
    </div>

    <!-- ── Index User Kundalis ──────────────────────────────────────────────── -->
    <div style="background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:24px; margin-bottom:20px;">
        <h2 style="margin-top:0;">🌟 <?php esc_html_e( 'Index User Kundalis', 'sanathan-astro' ); ?></h2>
        <p>
            <?php printf(
                esc_html__( 'Embeds each user\'s English Kundali summary into the %s Qdrant collection for personalised Guruji responses.', 'sanathan-astro' ),
                '<code>user_kundali</code>'
            ); ?>
        </p>
        <p class="description">
            <?php esc_html_e( 'Kundalis are automatically indexed when created. Use this to backfill existing users.', 'sanathan-astro' ); ?>
        </p>
        <button type="button" id="sas-index-kundalis" class="button button-secondary" <?php disabled( ! $ready ); ?>>
            🔄 <?php esc_html_e( 'Index All User Kundalis', 'sanathan-astro' ); ?>
        </button>
        <div id="sas-kundali-progress" style="margin-top:12px; display:none;">
            <span class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span>
            <?php esc_html_e( 'Indexing user Kundalis…', 'sanathan-astro' ); ?>
        </div>
        <div id="sas-kundali-result" style="margin-top:12px;"></div>
    </div>

    <!-- ── How It Works ─────────────────────────────────────────────────────── -->
    <div style="background:#f9f9f9; border:1px solid #e0e0e0; border-radius:8px; padding:20px;">
        <h3 style="margin-top:0;">🔄 <?php esc_html_e( 'How RAG Works in Guruji', 'sanathan-astro' ); ?></h3>
        <ol>
            <li><?php esc_html_e( 'User sends a message to their Guruji.', 'sanathan-astro' ); ?></li>
            <li><?php esc_html_e( 'Plugin gets an embedding of the message from AIP.', 'sanathan-astro' ); ?></li>
            <li><?php esc_html_e( 'Searches sanathan_knowledge for the top 3 most relevant Vedic knowledge snippets.', 'sanathan-astro' ); ?></li>
            <li><?php esc_html_e( 'Injects those snippets + the user\'s Kundali into the system prompt.', 'sanathan-astro' ); ?></li>
            <li><?php esc_html_e( 'AIP generates a response grounded in actual Vedic knowledge — not hallucinated.', 'sanathan-astro' ); ?></li>
        </ol>
    </div>

</div>

<script>
jQuery(function($){

    // ── Index Knowledge Base ──────────────────────────────────────────────────
    $('#sas-index-knowledge').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#sas-knowledge-progress').show();
        $('#sas-knowledge-result').html('');

        $.post(ajaxurl, {
            action: 'sas_index_knowledge',
            nonce:  '<?php echo esc_js( wp_create_nonce( 'sas_index_knowledge' ) ); ?>'
        }, function(res){
            $('#sas-knowledge-progress').hide();
            if (res.success && res.data) {
                var d = res.data;
                var color = d.errors === 0 ? 'green' : 'orange';
                $('#sas-knowledge-result').css('color', color).html(
                    '✅ Indexed <strong>' + d.indexed + '</strong> of ' + d.total + ' documents.' +
                    (d.errors > 0 ? ' ⚠ ' + d.errors + ' errors (check PHP error log).' : '')
                );
            } else {
                $('#sas-knowledge-result').css('color','red').text('❌ ' + (res.data?.error || res.data?.message || 'Indexing failed.'));
            }
        }).fail(function(){
            $('#sas-knowledge-progress').hide();
            $('#sas-knowledge-result').css('color','red').text('❌ Request timed out. Try indexing again — it will skip already-indexed docs.');
        }).always(function(){
            $btn.prop('disabled', false);
        });
    });

    // ── Index User Kundalis ───────────────────────────────────────────────────
    $('#sas-index-kundalis').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#sas-kundali-progress').show();
        $('#sas-kundali-result').html('');

        $.post(ajaxurl, {
            action: 'sas_index_kundalis',
            nonce:  '<?php echo esc_js( wp_create_nonce( 'sas_index_kundalis' ) ); ?>'
        }, function(res){
            $('#sas-kundali-progress').hide();
            if (res.success && res.data) {
                var d = res.data;
                $('#sas-kundali-result').css('color','green').html(
                    '✅ Indexed <strong>' + d.indexed + '</strong> of ' + d.total + ' user Kundalis.' +
                    (d.errors > 0 ? ' ⚠ ' + d.errors + ' errors.' : '')
                );
            } else {
                $('#sas-kundali-result').css('color','red').text('❌ ' + (res.data?.message || 'Indexing failed.'));
            }
        }).fail(function(){
            $('#sas-kundali-progress').hide();
            $('#sas-kundali-result').css('color','red').text('❌ Request failed.');
        }).always(function(){
            $btn.prop('disabled', false);
        });
    });

});
</script>

<style>
.sas-wrap { max-width: 960px; }
</style>
