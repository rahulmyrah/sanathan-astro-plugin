/**
 * Pricing Table  —  sas-pricing.js
 * v1.5.1  |  Sanathan Astro Services
 *
 * Handles the monthly / annual billing toggle:
 *   - Swaps price amounts
 *   - Swaps billing period text
 *   - Updates CTA href to the annual checkout level
 *   - Animates the toggle knob and highlights active label
 */

(function () {
    'use strict';

    var toggleBtn = document.getElementById('sas-pt-toggle');
    if (!toggleBtn) return;

    var isAnnual = false;

    toggleBtn.addEventListener('click', function () {
        isAnnual = !isAnnual;
        toggleBtn.setAttribute('aria-checked', isAnnual ? 'true' : 'false');

        // ── Price amounts ─────────────────────────────────────
        document.querySelectorAll('.sas-pt-monthly-price').forEach(function (el) {
            el.hidden = isAnnual;
        });
        document.querySelectorAll('.sas-pt-annual-price').forEach(function (el) {
            el.hidden = !isAnnual;
        });

        // ── Period text ───────────────────────────────────────
        document.querySelectorAll('.sas-pt-monthly-period').forEach(function (el) {
            el.hidden = isAnnual;
        });
        document.querySelectorAll('.sas-pt-annual-period').forEach(function (el) {
            el.hidden = !isAnnual;
        });

        // ── CTA links ─────────────────────────────────────────
        document.querySelectorAll('[data-monthly][data-annual]').forEach(function (el) {
            el.href = isAnnual ? el.dataset.annual : el.dataset.monthly;
        });

        // ── Label emphasis ────────────────────────────────────
        var monthlyLbl = document.getElementById('sas-pt-monthly-lbl');
        var annualLbl  = document.getElementById('sas-pt-annual-lbl');
        if (monthlyLbl) {
            monthlyLbl.style.opacity = isAnnual ? '0.55' : '1';
            monthlyLbl.style.fontWeight = isAnnual ? '400' : '700';
        }
        if (annualLbl) {
            annualLbl.style.opacity = isAnnual ? '1' : '0.55';
            annualLbl.style.fontWeight = isAnnual ? '700' : '400';
        }
    });

})();
