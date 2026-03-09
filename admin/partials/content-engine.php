<?php
/**
 * Admin page: Content Engine
 * Bulk-generates Hindu Calendar / Pooja Guides / Slokas & Mantras posts via Gemini AI.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$settings  = sas_get_settings();
$api_key   = $settings['gemini_api_key'] ?? '';
$counts    = SAS_Content_Engine::get_counts();
$langs     = SAS_Content_Engine::LANG_NAMES;
$countries = array_keys( SAS_Content_Engine::COUNTRIES );
$poojas    = SAS_Content_Engine::POOJAS;
$deities   = SAS_Content_Engine::SLOKA_DEITIES;
$months    = SAS_Content_Engine::MONTHS;
?>

<div class="wrap sas-wrap" id="sas-content-engine">
    <h1>&#128218; Content Engine</h1>
    <p class="sas-subtitle">Bulk-generate SEO content using Gemini AI — Hindu Calendar, Pooja Guides, Slokas &amp; Mantras in 9 languages. All posts are FREE &amp; public with beautiful flipbook display.</p>

    <?php if ( ! $api_key ) : ?>
    <div class="notice notice-error">
        <p>&#9888;&#65039; <strong>Gemini API key is not configured.</strong> Go to <a href="<?php echo admin_url( 'admin.php?page=sas-settings' ); ?>">Astro Services → Settings</a> and add your Gemini API key first.</p>
    </div>
    <?php endif; ?>

    <!-- ── Status Cards ──────────────────────────────────────────────── -->
    <div class="sas-status-row">
        <div class="sas-stat-card">
            <div class="sas-stat-num" id="stat-calendar"><?php echo esc_html( $counts['calendar'] ); ?>/27</div>
            <div class="sas-stat-label">Hindu Calendar Books</div>
            <div class="sas-mini-bar"><div class="sas-mini-fill" style="width:<?php echo min( 100, round( $counts['calendar'] / 27 * 100 ) ); ?>%"></div></div>
        </div>
        <div class="sas-stat-card">
            <div class="sas-stat-num" id="stat-pooja"><?php echo esc_html( $counts['pooja'] ); ?>/180</div>
            <div class="sas-stat-label">Pooja Guide Posts</div>
            <div class="sas-mini-bar"><div class="sas-mini-fill" style="width:<?php echo min( 100, round( $counts['pooja'] / 180 * 100 ) ); ?>%"></div></div>
        </div>
        <div class="sas-stat-card">
            <div class="sas-stat-num" id="stat-sloka"><?php echo esc_html( $counts['sloka'] ); ?>/90</div>
            <div class="sas-stat-label">Sloka Collection Posts</div>
            <div class="sas-mini-bar"><div class="sas-mini-fill" style="width:<?php echo min( 100, round( $counts['sloka'] / 90 * 100 ) ); ?>%"></div></div>
        </div>
        <div class="sas-stat-card">
            <div class="sas-stat-num" id="stat-total"><?php echo esc_html( $counts['calendar'] + $counts['pooja'] + $counts['sloka'] ); ?>/297</div>
            <div class="sas-stat-label">Total Posts Generated</div>
            <div class="sas-mini-bar"><div class="sas-mini-fill" style="width:<?php echo min( 100, round( ( $counts['calendar'] + $counts['pooja'] + $counts['sloka'] ) / 297 * 100 ) ); ?>%"></div></div>
        </div>
    </div>

    <!-- ── Setup Step ────────────────────────────────────────────────── -->
    <div class="sas-ce-section">
        <h2>&#9654; Step 0 — Setup Categories</h2>
        <p>Creates the WordPress category hierarchy: <em>Hindu Calendar &gt; India/UK/USA</em>, <em>Pooja Guides &gt; deity names</em>, <em>Slokas &amp; Mantras &gt; deity names</em>. Safe to run multiple times.</p>
        <button id="btn-setup-cats" class="button button-secondary <?php echo ! $api_key ? 'disabled' : ''; ?>">&#128193; Setup Categories</button>
        <span id="setup-cats-status" class="sas-inline-status"></span>
    </div>

    <!-- ── Tabs ──────────────────────────────────────────────────────── -->
    <div class="sas-tabs">
        <button class="sas-tab-btn sas-tab-active" data-tab="calendar">&#128197; Hindu Calendar</button>
        <button class="sas-tab-btn" data-tab="pooja">&#129384; Pooja Guides</button>
        <button class="sas-tab-btn" data-tab="sloka">&#128302; Slokas &amp; Mantras</button>
    </div>

    <!-- ─────────────────────── TAB 1: Hindu Calendar ─────────────── -->
    <div class="sas-tab-panel" id="tab-calendar">
        <h2>&#128197; Generate Hindu Calendar Books</h2>
        <p>Generates <strong>complete year flipbooks</strong> — one book per country &times; language (27 total). Each book has a cover page + 12 monthly pages with festivals, Ekadashi/Purnima/Amavasya dates, auspicious muhurats, and slokas. All 12 months are generated in a single Gemini call per book.</p>

        <table class="form-table sas-gen-form">
            <tr>
                <th>Year</th>
                <td>
                    <select id="cal-year">
                        <option value="2026" selected>2026</option>
                        <option value="2027">2027</option>
                        <option value="2025">2025</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>Countries</th>
                <td>
                    <?php foreach ( $countries as $c ) : ?>
                    <label style="margin-right:1.5rem">
                        <input type="checkbox" class="cal-country" value="<?php echo esc_attr( $c ); ?>" checked>
                        <?php echo esc_html( $c ); ?>
                    </label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th>Languages</th>
                <td>
                    <?php foreach ( $langs as $code => $name ) : ?>
                    <label style="margin-right:1rem">
                        <input type="checkbox" class="cal-lang" value="<?php echo esc_attr( $code ); ?>" checked>
                        <?php echo esc_html( $name ); ?>
                    </label>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>

        <div style="margin-bottom:1rem">
            <button id="btn-gen-calendar" class="button button-primary <?php echo ! $api_key ? 'disabled' : ''; ?>">&#9889; Generate Calendar Books</button>
            <button id="btn-gen-cal-images" class="button button-secondary <?php echo ! $api_key ? 'disabled' : ''; ?>">&#127748; Generate Featured Images</button>
        </div>

        <div id="cal-progress-wrap" style="display:none" class="sas-progress-wrap">
            <div class="sas-progress-bar"><div class="sas-progress-fill" id="cal-progress-fill"></div></div>
            <div id="cal-status" class="sas-status-text">Starting...</div>
        </div>
        <div id="cal-result" class="sas-result"></div>
    </div>

    <!-- ─────────────────────── TAB 2: Pooja Guides ────────────────── -->
    <div class="sas-tab-panel" id="tab-pooja" style="display:none">
        <h2>&#129384; Generate Pooja Guides</h2>
        <p>Step-by-step puja guides with materials list, mantras in Devanagari with translations, and aarti instructions — in all 9 languages.</p>

        <table class="form-table sas-gen-form">
            <tr>
                <th>Languages</th>
                <td>
                    <?php foreach ( $langs as $code => $name ) : ?>
                    <label style="margin-right:1rem">
                        <input type="checkbox" class="pooja-lang" value="<?php echo esc_attr( $code ); ?>" checked>
                        <?php echo esc_html( $name ); ?>
                    </label>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>

        <p class="description">Generates guides for all 20 poojas: <?php echo esc_html( implode( ', ', $poojas ) ); ?></p>

        <div style="margin-bottom:1rem">
            <button id="btn-gen-pooja" class="button button-primary <?php echo ! $api_key ? 'disabled' : ''; ?>">&#9889; Generate Pooja Guides</button>
            <button id="btn-gen-pooja-images" class="button button-secondary <?php echo ! $api_key ? 'disabled' : ''; ?>">&#127748; Generate Featured Images</button>
        </div>

        <div id="pooja-progress-wrap" style="display:none" class="sas-progress-wrap">
            <div class="sas-progress-bar"><div class="sas-progress-fill" id="pooja-progress-fill"></div></div>
            <div id="pooja-status" class="sas-status-text">Starting...</div>
        </div>
        <div id="pooja-result" class="sas-result"></div>
    </div>

    <!-- ─────────────────────── TAB 3: Slokas ──────────────────────── -->
    <div class="sas-tab-panel" id="tab-sloka" style="display:none">
        <h2>&#128302; Generate Sloka Collections</h2>
        <p>Curated collections of 12 key slokas per deity — Sanskrit in Devanagari, IAST transliteration, translations, and benefits — in all 9 languages.</p>

        <table class="form-table sas-gen-form">
            <tr>
                <th>Languages</th>
                <td>
                    <?php foreach ( $langs as $code => $name ) : ?>
                    <label style="margin-right:1rem">
                        <input type="checkbox" class="sloka-lang" value="<?php echo esc_attr( $code ); ?>" checked>
                        <?php echo esc_html( $name ); ?>
                    </label>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>

        <p class="description">Generates collections for: <?php echo esc_html( implode( ', ', $deities ) ); ?></p>

        <div style="margin-bottom:1rem">
            <button id="btn-gen-sloka" class="button button-primary <?php echo ! $api_key ? 'disabled' : ''; ?>">&#9889; Generate Sloka Collections</button>
            <button id="btn-gen-sloka-images" class="button button-secondary <?php echo ! $api_key ? 'disabled' : ''; ?>">&#127748; Generate Featured Images</button>
        </div>

        <div id="sloka-progress-wrap" style="display:none" class="sas-progress-wrap">
            <div class="sas-progress-bar"><div class="sas-progress-fill" id="sloka-progress-fill"></div></div>
            <div id="sloka-status" class="sas-status-text">Starting...</div>
        </div>
        <div id="sloka-result" class="sas-result"></div>
    </div>

    <!-- ── Next Steps ────────────────────────────────────────────────── -->
    <div class="sas-ce-section sas-next-steps" style="margin-top:2rem">
        <h3>&#10024; After Generation</h3>
        <ol>
            <li><strong>View Posts:</strong> <a href="<?php echo admin_url( 'edit.php' ); ?>">Posts → All Posts</a> — filter by category Hindu Calendar / Pooja Guides / Slokas &amp; Mantras</li>
            <li><strong>Flipbook:</strong> Each post automatically renders as an interactive flipbook with WhatsApp + copy-link sharing</li>
            <li><strong>SEO:</strong> Posts are public, indexed by Google. Category archive pages at <code>/category/pooja-guides/</code> etc. are auto-generated</li>
            <li><strong>Featured Images:</strong> Run "Generate Featured Images" per tab to add Gemini Imagen illustrations (can be done later, one post at a time)</li>
        </ol>
    </div>

</div><!-- .sas-wrap -->

<style>
.sas-wrap { max-width: 960px; }
.sas-subtitle { color: #666; margin-bottom: 1.5rem; }

/* Status cards */
.sas-status-row { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 2rem; }
.sas-stat-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 1rem 1.5rem; min-width: 160px; flex: 1; }
.sas-stat-num { font-size: 1.6rem; font-weight: 700; color: #b5451b; }
.sas-stat-label { font-size: 0.8rem; color: #888; margin-top: 0.2rem; }
.sas-mini-bar { background: #f0f0f0; height: 6px; border-radius: 3px; margin-top: 0.5rem; }
.sas-mini-fill { background: #b5451b; height: 6px; border-radius: 3px; transition: width 0.3s; }

/* Section boxes */
.sas-ce-section { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 1.2rem 1.5rem; margin-bottom: 1.5rem; }
.sas-ce-section h2 { margin-top: 0; }

/* Tabs */
.sas-tabs { display: flex; gap: 0; margin-bottom: 0; border-bottom: 2px solid #b5451b; }
.sas-tab-btn { background: #f7f7f7; border: 1px solid #ccc; border-bottom: none; padding: 0.6rem 1.2rem; cursor: pointer; font-size: 0.95rem; border-radius: 4px 4px 0 0; margin-right: 4px; }
.sas-tab-btn.sas-tab-active { background: #b5451b; color: #fff; border-color: #b5451b; }
.sas-tab-panel { background: #fff; border: 1px solid #e0e0e0; border-top: none; border-radius: 0 0 6px 6px; padding: 1.5rem; }

/* Form */
.sas-gen-form th { width: 120px; padding: 0.5rem 1rem 0.5rem 0; font-weight: 600; vertical-align: top; }
.sas-gen-form td { padding: 0.5rem 0; }

/* Progress */
.sas-progress-wrap { margin: 1rem 0; }
.sas-progress-bar { background: #f0f0f0; height: 20px; border-radius: 4px; overflow: hidden; }
.sas-progress-fill { background: linear-gradient(90deg, #b5451b, #e8740c); height: 100%; width: 0; transition: width 0.2s; }
.sas-status-text { font-size: 0.85rem; color: #555; margin-top: 0.4rem; }
.sas-result { margin-top: 0.5rem; font-size: 0.9rem; }
.sas-result .sas-ok  { color: #1e7e34; }
.sas-result .sas-err { color: #c82333; }

/* Inline status */
.sas-inline-status { margin-left: 0.75rem; font-size: 0.9rem; color: #555; }

/* Disabled buttons */
.button.disabled { opacity: 0.5; pointer-events: none; }

/* Next steps */
.sas-next-steps { background: #f9f6f0; border-color: #e8740c; }
.sas-next-steps h3 { margin-top: 0; color: #b5451b; }
</style>

<script>
jQuery(function ($) {

    const ajaxurl = '<?php echo admin_url( 'admin-ajax.php' ); ?>';
    const nonce   = '<?php echo wp_create_nonce( "sas_content_engine" ); ?>';

    // ── Tabs ────────────────────────────────────────────────────────
    $('.sas-tab-btn').on('click', function () {
        $('.sas-tab-btn').removeClass('sas-tab-active');
        $(this).addClass('sas-tab-active');
        $('.sas-tab-panel').hide();
        $('#tab-' + $(this).data('tab')).show();
    });

    // ── Helpers ─────────────────────────────────────────────────────
    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

    async function ajaxPost(action, data) {
        return new Promise((resolve) => {
            $.post(ajaxurl, { action, nonce, ...data })
                .done(r => resolve(r))
                .fail(() => resolve({ success: false, data: { message: 'Network error' } }));
        });
    }

    async function runBatch(items, action, progressFill, statusEl, resultEl, imageAction, imageKeyFn) {
        let done = 0, created = 0, skipped = 0, errors = 0;
        const total = items.length;
        resultEl.html('');

        for (const item of items) {
            const res = await ajaxPost(action, item);
            done++;
            if (res.success) {
                res.data.status === 'skipped' ? skipped++ : created++;
            } else {
                errors++;
            }
            const pct = Math.round(done / total * 100);
            progressFill.css('width', pct + '%');
            statusEl.text(`${done}/${total} — ✅ Created: ${created}  ⏭ Skipped: ${skipped}  ❌ Errors: ${errors}`);
            await sleep(2000); // 2s between calls — calendar books are large single calls; pooja/sloka also benefit
        }

        resultEl.html(`<span class="sas-ok">✅ Done! Created: <strong>${created}</strong>  Skipped: <strong>${skipped}</strong>  Errors: <strong>${errors}</strong> out of ${total} items.</span>`);
        return { created, skipped, errors };
    }

    // ── Setup Categories ────────────────────────────────────────────
    $('#btn-setup-cats').on('click', async function () {
        const btn = $(this);
        btn.prop('disabled', true).text('Setting up...');
        $('#setup-cats-status').text('Working...');
        const res = await ajaxPost('sas_setup_content_categories', {});
        if (res.success) {
            const d = res.data;
            $('#setup-cats-status').html(`<span style="color:#1e7e34">✅ Done — Created: ${d.created}, Already existed: ${d.existing}</span>`);
        } else {
            $('#setup-cats-status').html(`<span style="color:#c82333">❌ ${res.data?.message || 'Error'}</span>`);
        }
        btn.prop('disabled', false).text('✅ Setup Categories (re-run safe)');
    });

    // ── Calendar Generation ─────────────────────────────────────────
    $('#btn-gen-calendar').on('click', async function () {
        const year      = parseInt($('#cal-year').val());
        const countries = $('.cal-country:checked').map((i, el) => el.value).get();
        const langs     = $('.cal-lang:checked').map((i, el) => el.value).get();

        if (!countries.length || !langs.length) {
            alert('Please select at least one country and one language.');
            return;
        }

        // 27 items max (3 countries × 9 langs) — each item = one full-year flipbook (13 pages)
        const items = [];
        for (const country of countries) {
            for (const lang of langs) {
                items.push({ year, country, lang });
            }
        }

        $(this).prop('disabled', true).text('Generating...');
        $('#cal-progress-wrap').show();
        // 3s delay between books — each Gemini call generates 12 months, needs a bit of breathing room
        await runBatch(items, 'sas_generate_calendar_post',
            $('#cal-progress-fill'), $('#cal-status'), $('#cal-result'));
        $(this).prop('disabled', false).text('⚡ Generate Calendar Books');
        updateStats();
    });

    $('#btn-gen-cal-images').on('click', async function () {
        $(this).prop('disabled', true).text('Generating images...');
        const res = await ajaxPost('sas_generate_content_images_batch', { type: 'hindu_calendar' });
        if (res.success) {
            $('#cal-result').html(`<span class="sas-ok">✅ Images: Generated: ${res.data.generated}, Errors: ${res.data.errors}</span>`);
        }
        $(this).prop('disabled', false).text('🌸 Generate Featured Images');
    });

    // ── Pooja Generation ────────────────────────────────────────────
    $('#btn-gen-pooja').on('click', async function () {
        const langs  = $('.pooja-lang:checked').map((i, el) => el.value).get();
        if (!langs.length) { alert('Select at least one language.'); return; }

        const poojas = <?php echo json_encode( SAS_Content_Engine::POOJAS ); ?>;
        const items  = [];
        for (const pooja of poojas) {
            for (const lang of langs) {
                items.push({ pooja, lang });
            }
        }

        $(this).prop('disabled', true).text('Generating...');
        $('#pooja-progress-wrap').show();
        await runBatch(items, 'sas_generate_pooja_post',
            $('#pooja-progress-fill'), $('#pooja-status'), $('#pooja-result'));
        $(this).prop('disabled', false).text('⚡ Generate Pooja Guides');
        updateStats();
    });

    $('#btn-gen-pooja-images').on('click', async function () {
        $(this).prop('disabled', true).text('Generating images...');
        const res = await ajaxPost('sas_generate_content_images_batch', { type: 'pooja_guide' });
        if (res.success) {
            $('#pooja-result').html(`<span class="sas-ok">✅ Images: Generated: ${res.data.generated}, Errors: ${res.data.errors}</span>`);
        }
        $(this).prop('disabled', false).text('🌸 Generate Featured Images');
    });

    // ── Sloka Generation ────────────────────────────────────────────
    $('#btn-gen-sloka').on('click', async function () {
        const langs   = $('.sloka-lang:checked').map((i, el) => el.value).get();
        if (!langs.length) { alert('Select at least one language.'); return; }

        const deities = <?php echo json_encode( SAS_Content_Engine::SLOKA_DEITIES ); ?>;
        const items   = [];
        for (const deity of deities) {
            for (const lang of langs) {
                items.push({ deity, lang });
            }
        }

        $(this).prop('disabled', true).text('Generating...');
        $('#sloka-progress-wrap').show();
        await runBatch(items, 'sas_generate_sloka_post',
            $('#sloka-progress-fill'), $('#sloka-status'), $('#sloka-result'));
        $(this).prop('disabled', false).text('⚡ Generate Sloka Collections');
        updateStats();
    });

    $('#btn-gen-sloka-images').on('click', async function () {
        $(this).prop('disabled', true).text('Generating images...');
        const res = await ajaxPost('sas_generate_content_images_batch', { type: 'sloka_collection' });
        if (res.success) {
            $('#sloka-result').html(`<span class="sas-ok">✅ Images: Generated: ${res.data.generated}, Errors: ${res.data.errors}</span>`);
        }
        $(this).prop('disabled', false).text('🌸 Generate Featured Images');
    });

    // ── Refresh stat cards ──────────────────────────────────────────
    async function updateStats() {
        const res = await ajaxPost('sas_get_content_counts', {});
        if (!res.success) return;
        const d = res.data;
        $('#stat-calendar').text(d.calendar + '/27');
        $('#stat-pooja').text(d.pooja + '/180');
        $('#stat-sloka').text(d.sloka + '/90');
        $('#stat-total').text((d.calendar + d.pooja + d.sloka) + '/297');
    }

});
</script>
