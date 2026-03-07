/**
 * Sanathan AI Tools — Frontend JS
 * Archive: live search + category filter
 */
(function () {
    'use strict';

    /* ─── Archive Page ─────────────────────────────────────────── */
    function initArchive() {
        var grid      = document.querySelector('.sas-tools-grid');
        var searchEl  = document.getElementById('sas-search');
        var filterBtns= document.querySelectorAll('.sas-filter-btn');
        var countEl   = document.getElementById('sas-count-label');
        var noResults = document.getElementById('sas-no-results');

        if (!grid) return;

        var cards = Array.from(grid.querySelectorAll('.sas-tool-card'));
        var activeCategory = 'all';
        var searchTerm = '';

        function normalize(str) {
            return (str || '').toLowerCase().trim();
        }

        function updateCount(visible) {
            if (countEl) {
                countEl.innerHTML = 'Showing <strong>' + visible + '</strong> of <strong>' + cards.length + '</strong> tools';
            }
            if (noResults) {
                noResults.classList.toggle('visible', visible === 0);
            }
        }

        function applyFilters() {
            var q   = normalize(searchTerm);
            var cat = activeCategory;
            var visible = 0;

            cards.forEach(function (card) {
                var title   = normalize(card.dataset.title || '');
                var catSlug = normalize(card.dataset.category || '');
                var excerpt = normalize(card.dataset.excerpt || '');

                var matchCat    = (cat === 'all') || (catSlug === cat);
                var matchSearch = !q || title.includes(q) || excerpt.includes(q);

                var show = matchCat && matchSearch;
                card.classList.toggle('hidden', !show);
                if (show) visible++;
            });

            updateCount(visible);
        }

        /* Search */
        if (searchEl) {
            var debounceTimer;
            searchEl.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function () {
                    searchTerm = searchEl.value;
                    applyFilters();
                }, 200);
            });

            /* clear on Escape */
            searchEl.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    searchEl.value = '';
                    searchTerm = '';
                    applyFilters();
                }
            });
        }

        /* Category filter */
        filterBtns.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                filterBtns.forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                activeCategory = normalize(btn.dataset.cat || 'all');
                applyFilters();
            });
        });

        /* Initial count */
        updateCount(cards.length);
    }

    /* ─── Single Page ──────────────────────────────────────────── */
    function initSingle() {
        /* Smooth scroll for anchor links inside the guide */
        document.querySelectorAll('.sas-tool-guide a[href^="#"]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                var target = document.querySelector(a.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    }

    /* ─── Boot ─────────────────────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        initArchive();
        initSingle();
    });
})();
