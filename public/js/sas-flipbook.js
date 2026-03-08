/**
 * Sanathan Flipbook — StPageFlip.js initialisation + share buttons
 * Runs on Hindu Calendar, Pooja Guide, and Sloka Collection posts.
 *
 * The PHP content filter (SAS_Content_Engine::maybe_wrap_flipbook) wraps
 * post content in:
 *   <div class="sas-flipbook-wrap">
 *     <div class="sas-flipbook-container"> {post content with .sas-flip-page divs} </div>
 *   </div>
 *   <div class="sas-flipbook-nav">...</div>
 *   <div class="sas-share-bar">...</div>
 *
 * StPageFlip (St.PageFlip) expects the container to be a block element and
 * the pages to be its direct children.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        const container = document.querySelector('.sas-flipbook-container');
        if (!container) return;

        const pages = container.querySelectorAll('.sas-flip-page');
        if (pages.length < 1) return;

        // ── If only 1 page, don't initialise flipbook — just show content ─
        if (pages.length < 2) {
            container.classList.add('sas-single-page');
            return;
        }

        // ── Responsive dimensions ────────────────────────────────────────
        const vw       = window.innerWidth;
        const isMobile = vw < 640;
        const bookW    = Math.min(vw - 32, isMobile ? 380 : 520);
        const bookH    = Math.round(bookW * 1.3);

        // ── Initialise StPageFlip ─────────────────────────────────────────
        let pageFlip;
        try {
            // St is the global exported by page-flip.browser.min.js
            pageFlip = new St.PageFlip(container, {
                width:              bookW,
                height:             bookH,
                size:               'stretch',
                minWidth:           280,
                maxWidth:           600,
                minHeight:          380,
                maxHeight:          900,
                showCover:          true,
                mobileScrollSupport: true,
                swipeDistance:       20,
                usePortrait:         true,
                startPage:           0,
                autoSize:            true,
                drawShadow:          true,
                flippingTime:        600,
            });

            pageFlip.loadFromHTML(pages);
        } catch (e) {
            // StPageFlip failed (e.g. canvas not supported) — fallback to plain view
            console.warn('[SAS Flipbook] StPageFlip init failed:', e);
            container.classList.add('sas-flipbook-fallback');
            return;
        }

        // ── Page counter ──────────────────────────────────────────────────
        const counterEl = document.getElementById('sas-flip-counter');

        function updateCounter() {
            if (!counterEl) return;
            const cur   = pageFlip.getCurrentPageIndex() + 1;
            const total = pageFlip.getPageCount();
            counterEl.textContent = cur + ' / ' + total;
        }

        pageFlip.on('flip', updateCounter);
        pageFlip.on('init', updateCounter);
        // Fallback in case events don't fire immediately
        setTimeout(updateCounter, 300);

        // ── Navigation buttons ─────────────────────────────────────────────
        const prevBtn = document.getElementById('sas-flip-prev');
        const nextBtn = document.getElementById('sas-flip-next');

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                pageFlip.flipPrev();
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                pageFlip.flipNext();
            });
        }

        // ── Keyboard navigation ────────────────────────────────────────────
        document.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                pageFlip.flipNext();
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                pageFlip.flipPrev();
            }
        });

        // ── Share buttons ──────────────────────────────────────────────────
        document.querySelectorAll('.sas-share-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const pageUrl   = encodeURIComponent(window.location.href);
                const pageTitle = encodeURIComponent(document.title);
                const network   = this.getAttribute('data-network');

                if (network === 'whatsapp') {
                    window.open(
                        'https://wa.me/?text=' + pageTitle + '%20' + pageUrl,
                        '_blank',
                        'noopener'
                    );
                } else if (network === 'facebook') {
                    window.open(
                        'https://www.facebook.com/sharer/sharer.php?u=' + pageUrl,
                        '_blank',
                        'noopener,width=600,height=400'
                    );
                } else if (network === 'copy') {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(window.location.href).then(function () {
                            btn.textContent = '✓ Copied!';
                            setTimeout(function () {
                                btn.textContent = '📋 Copy Link';
                            }, 2000);
                        });
                    } else {
                        // Legacy fallback
                        const ta = document.createElement('textarea');
                        ta.value = window.location.href;
                        ta.style.position = 'fixed';
                        ta.style.opacity  = '0';
                        document.body.appendChild(ta);
                        ta.focus();
                        ta.select();
                        try {
                            document.execCommand('copy');
                            btn.textContent = '✓ Copied!';
                            setTimeout(function () { btn.textContent = '📋 Copy Link'; }, 2000);
                        } catch (ex) { /* silent */ }
                        document.body.removeChild(ta);
                    }
                }
            });
        });

        // ── Handle window resize ──────────────────────────────────────────
        let resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                const newVw = window.innerWidth;
                const newW  = Math.min(newVw - 32, newVw < 640 ? 380 : 520);
                const newH  = Math.round(newW * 1.3);
                try {
                    pageFlip.updateFromHTML(pages);
                } catch (e) { /* ignore */ }
            }, 300);
        });

    });

})();
