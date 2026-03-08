/**
 * Sanathan Home Page JavaScript
 * - Scroll-triggered fade-in animations (IntersectionObserver)
 * - Animated stat counters
 * @version 1.4.8
 */
(function() {
	'use strict';

	/* ── Scroll animation ── */
	function initAnimations() {
		var els = document.querySelectorAll('.sas-animate-up');
		if ( ! els.length ) return;

		if ( 'IntersectionObserver' in window ) {
			var io = new IntersectionObserver(function(entries) {
				entries.forEach(function(entry, i) {
					if ( entry.isIntersecting ) {
						// Stagger: each card slightly delayed
						var delay = (i % 6) * 80;
						setTimeout(function() {
							entry.target.classList.add('sas-visible');
						}, delay);
						io.unobserve(entry.target);
					}
				});
			}, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

			els.forEach(function(el) { io.observe(el); });
		} else {
			// Fallback: show all immediately
			els.forEach(function(el) { el.classList.add('sas-visible'); });
		}
	}

	/* ── Stat counter animation ── */
	function animateCounter(el) {
		var target = parseInt(el.getAttribute('data-count'), 10);
		if ( ! target ) return;
		var start    = 0;
		var duration = 1200;
		var step     = Math.ceil(target / (duration / 16));
		el.textContent = '0';

		var timer = setInterval(function() {
			start += step;
			if ( start >= target ) {
				el.textContent = target;
				clearInterval(timer);
			} else {
				el.textContent = start;
			}
		}, 16);
	}

	function initCounters() {
		var counters = document.querySelectorAll('.sas-stat-num[data-count]');
		if ( ! counters.length ) return;

		if ( 'IntersectionObserver' in window ) {
			var io = new IntersectionObserver(function(entries) {
				entries.forEach(function(entry) {
					if ( entry.isIntersecting ) {
						animateCounter(entry.target);
						io.unobserve(entry.target);
					}
				});
			}, { threshold: 0.5 });
			counters.forEach(function(c) { io.observe(c); });
		} else {
			counters.forEach(function(c) {
				c.textContent = c.getAttribute('data-count');
			});
		}
	}

	/* ── Init ── */
	document.addEventListener('DOMContentLoaded', function() {
		initAnimations();
		initCounters();
	});

})();
