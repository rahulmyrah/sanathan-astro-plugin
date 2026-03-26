/**
 * Sanathan Home Chat — Sidebar + Chat interface
 * Powers [sas_home_chat] shortcode on the homepage.
 *
 * Sidebar:   Navigation links + quick-topic buttons.
 *            Mobile hamburger → slide-in panel.
 * Chat:      Guruji AI for logged-in users (full context including Kundali).
 *            Smart keyword routing for guests → real public API data + signup CTA.
 * @version 1.1.0
 */
(function () {
	'use strict';

	/* ── State ─────────────────────────────────────────────────────────────── */
	var sessionToken  = null;
	var isLoading     = false;
	var historyLoaded = false;
	var sidebarOpen   = false;

	/* ── Elements ───────────────────────────────────────────────────────────── */
	var elSidebar, elHamburger, elOverlay;
	var elWelcome, elMessages, elChips, elInput, elSend;

	/* ════════════════════════════════════════════════════════════════════════
	   SIDEBAR BEHAVIOUR
	════════════════════════════════════════════════════════════════════════ */

	function openSidebar() {
		sidebarOpen = true;
		elSidebar.classList.add('sas-hc-sidebar--open');
		elOverlay.classList.add('sas-hc-overlay--visible');
		elHamburger.classList.add('sas-hc-hamburger--open');
		elHamburger.setAttribute('aria-expanded', 'true');
	}

	function closeSidebar() {
		sidebarOpen = false;
		elSidebar.classList.remove('sas-hc-sidebar--open');
		elOverlay.classList.remove('sas-hc-overlay--visible');
		elHamburger.classList.remove('sas-hc-hamburger--open');
		elHamburger.setAttribute('aria-expanded', 'false');
	}

	function toggleSidebar() {
		if (sidebarOpen) { closeSidebar(); } else { openSidebar(); }
	}

	/** Remove active highlight from all sidebar items. */
	function clearActiveSidebarItems() {
		var items = elSidebar.querySelectorAll('.sas-hc-sidebar-item--active');
		items.forEach(function (el) { el.classList.remove('sas-hc-sidebar-item--active'); });
	}

	/** Handle clicks on sidebar items (event-delegated). */
	function handleSidebarClick(e) {
		var item = e.target.closest('.sas-hc-sidebar-item');
		if (!item) return;

		// ── External page link (navigates away) ──────────────────────────────
		if (item.tagName === 'A' || item.classList.contains('sas-hc-sb-link')) {
			closeSidebar();
			return; // let the browser navigate
		}

		// ── Locked feature (guest) ────────────────────────────────────────────
		if (item.classList.contains('sas-hc-locked')) {
			e.preventDefault();
			var feature = item.getAttribute('data-locked-feature') || 'this feature';
			closeSidebar();
			collapseWelcome();
			appendHtml(
				'🔒 <strong>' + escHtml(feature) + '</strong> requires a free account.<br><br>' +
				'Sign up in seconds to access personalized Kundali readings, yearly forecasts, and full Guruji chat history:' +
				ctaHtml()
			);
			return;
		}

		// ── Chat prompt button (data-prompt) ──────────────────────────────────
		var prompt = item.getAttribute('data-prompt');
		if (!prompt) return;

		clearActiveSidebarItems();
		item.classList.add('sas-hc-sidebar-item--active');

		// On mobile, close sidebar before starting the conversation
		if (window.innerWidth <= 768) closeSidebar();

		elInput.value = prompt;
		autoResize();
		sendMessage();
	}

	/* ════════════════════════════════════════════════════════════════════════
	   HELPERS
	════════════════════════════════════════════════════════════════════════ */

	function escHtml(str) {
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(String(str)));
		return d.innerHTML;
	}

	function ucFirst(str) {
		return str.charAt(0).toUpperCase() + str.slice(1);
	}

	/* ─────────────────────────────────────────────
	   Login-state helper — cookie fallback for
	   full-page-cache scenarios (same as float JS).
	───────────────────────────────────────────── */
	function getIsLoggedIn() {
		if (typeof sasConfig === 'undefined') return false;
		if (sasConfig.isLoggedIn) return true;
		if (document.cookie.indexOf('wordpress_logged_in_') === -1) return false;
		try {
			var KEY = 'sas_auth_reload';
			if (!sessionStorage.getItem(KEY)) {
				sessionStorage.setItem(KEY, '1');
				window.location.reload();
				return false;
			}
		} catch (e) {}
		return true;
	}

	/* ════════════════════════════════════════════════════════════════════════
	   API
	════════════════════════════════════════════════════════════════════════ */

	async function apiPost(endpoint, body) {
		try {
			var r = await fetch(sasConfig.restBase + endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': sasConfig.nonce },
				body: JSON.stringify(body),
			});
			return r.ok ? r.json() : null;
		} catch (e) { return null; }
	}

	async function apiGet(endpoint) {
		try {
			var r = await fetch(sasConfig.restBase + endpoint, {
				headers: { 'X-WP-Nonce': sasConfig.nonce },
			});
			return r.ok ? r.json() : null;
		} catch (e) { return null; }
	}

	async function publicGet(endpoint) {
		try {
			var r = await fetch(sasConfig.restBase + endpoint);
			return r.ok ? r.json() : null;
		} catch (e) { return null; }
	}

	/* ════════════════════════════════════════════════════════════════════════
	   UI — MESSAGE RENDERING
	════════════════════════════════════════════════════════════════════════ */

	function collapseWelcome() {
		if (elWelcome && !elWelcome.classList.contains('sas-hc-collapsed')) {
			elWelcome.classList.add('sas-hc-collapsed');
		}
		if (elChips && !elChips.classList.contains('sas-hc-hidden')) {
			elChips.classList.add('sas-hc-hidden');
		}
	}

	function scrollBottom() {
		if (elMessages) elMessages.scrollTop = elMessages.scrollHeight;
	}

	function removeEl(el) {
		if (el && el.parentNode) el.parentNode.removeChild(el);
	}

	/**
	 * Append XSS-safe text message (for user input + AI text replies).
	 * Newlines rendered as <br> using DOM API — never innerHTML.
	 */
	function appendText(role, text) {
		collapseWelcome();
		var wrap   = document.createElement('div');
		var bubble = document.createElement('div');
		wrap.className   = 'sas-hc-msg sas-hc-msg-' + role;
		bubble.className = 'sas-hc-msg-bubble';
		String(text).split('\n').forEach(function (line, i) {
			if (i > 0) bubble.appendChild(document.createElement('br'));
			bubble.appendChild(document.createTextNode(line));
		});
		wrap.appendChild(bubble);
		elMessages.appendChild(wrap);
		scrollBottom();
		return wrap;
	}

	/**
	 * Append HTML-formatted assistant message.
	 * ONLY called with server-controlled templates + escHtml()-sanitised API values.
	 * NEVER called with raw user input.
	 */
	function appendHtml(html) {
		collapseWelcome();
		var wrap   = document.createElement('div');
		var bubble = document.createElement('div');
		wrap.className   = 'sas-hc-msg sas-hc-msg-assistant';
		bubble.className = 'sas-hc-msg-bubble';
		bubble.innerHTML = html;
		wrap.appendChild(bubble);
		elMessages.appendChild(wrap);
		scrollBottom();
		return wrap;
	}

	function appendTyping() {
		var wrap   = document.createElement('div');
		var bubble = document.createElement('div');
		wrap.className   = 'sas-hc-msg sas-hc-msg-assistant';
		bubble.className = 'sas-hc-msg-bubble sas-hc-typing';
		bubble.setAttribute('aria-label', 'Guruji is thinking');
		for (var i = 0; i < 3; i++) bubble.appendChild(document.createElement('span'));
		wrap.appendChild(bubble);
		elMessages.appendChild(wrap);
		scrollBottom();
		return wrap;
	}

	/* ════════════════════════════════════════════════════════════════════════
	   GUEST CTA
	════════════════════════════════════════════════════════════════════════ */

	function ctaHtml() {
		return '<div class="sas-hc-signup-cta">' +
			'<a href="' + sasConfig.registerUrl + '" class="sas-hc-cta-primary">🙏 Create Free Account</a>' +
			'<a href="' + sasConfig.loginUrl    + '" class="sas-hc-cta-secondary">Sign In</a>' +
			'</div>';
	}

	/* ════════════════════════════════════════════════════════════════════════
	   DATA EXTRACTION
	════════════════════════════════════════════════════════════════════════ */

	/** Extract prediction text from /predictions REST response. */
	function extractPrediction(result) {
		if (!result || result.status !== 'ok') return null;
		var d = result.data;
		if (!d) return null;
		if (typeof d === 'string') return d;
		if (d.bot_response) return String(d.bot_response);
		if (d.response) {
			if (typeof d.response === 'string') return d.response;
			if (Array.isArray(d.response) && d.response.length > 0) {
				return d.response[0].bot_response || d.response[0].response || null;
			}
			if (typeof d.response === 'object') {
				return d.response.bot_response || d.response.daily || null;
			}
		}
		return null;
	}

	/** Extract a named field from Panchang API response (defensive, multi-key). */
	function panchangField(data, key) {
		if (!data) return null;
		var v = data[key];
		if (!v) return null;
		if (typeof v === 'string') return v;
		if (typeof v === 'object') {
			if (v.name) return String(v.name);
			var det = v.details;
			if (det) return det[key + '_name'] || det.name || null;
		}
		return null;
	}

	/* ════════════════════════════════════════════════════════════════════════
	   GUEST RESPONSE HANDLERS
	   Show real public-API data but cap the content and nudge to signup.
	════════════════════════════════════════════════════════════════════════ */

	async function guestPanchang() {
		var typing = appendTyping();
		var data   = await publicGet('/panchang');
		removeEl(typing);

		if (!data || data.error) {
			appendHtml('🗓️ <strong>Today\'s Panchang</strong><br><br>Unable to fetch Panchang right now. Please try again shortly.' + ctaHtml());
			return;
		}

		var fields = [
			{ key: 'tithi',     label: '📅 Tithi' },
			{ key: 'nakshatra', label: '⭐ Nakshatra' },
			{ key: 'yoga',      label: '🔯 Yoga' },
			{ key: 'karana',    label: '⚡ Karana' },
			{ key: 'vara',      label: '📆 Vara (Day)' },
		];
		var rows = '';
		fields.forEach(function (f) {
			var val = panchangField(data, f.key);
			if (val) rows += '<div class="sas-hc-prow"><span>' + f.label + '</span><strong>' + escHtml(val) + '</strong></div>';
		});

		var fRaw  = data.festival || data.festivals || (data.response && data.response.festival) || null;
		var fests = [];
		if (Array.isArray(fRaw)) {
			fRaw.slice(0, 3).forEach(function (f) {
				var n = typeof f === 'string' ? f : (f.name || f.festival_name || '');
				if (n) fests.push(n);
			});
		} else if (typeof fRaw === 'string' && fRaw) {
			fests.push(fRaw);
		}

		var html = '🗓️ <strong>Today\'s Panchang</strong><br><br>';
		if (rows) html += '<div class="sas-hc-panchang-grid">' + rows + '</div>';
		if (fests.length) html += '<br>🪔 <strong>Festivals:</strong> ' + fests.map(escHtml).join(' · ');
		html += '<br><br><small>Sign up to unlock Rahu Kaal, Sunrise/Sunset, personalised Muhurat alerts &amp; more:</small>' + ctaHtml();
		appendHtml(html);
	}

	async function guestHoroscope(zodiac) {
		var typing = appendTyping();
		var result = await publicGet('/predictions?zodiac=' + encodeURIComponent(zodiac) + '&lang=en&cycle=daily');
		removeEl(typing);

		var text = extractPrediction(result);
		if (!text) {
			appendHtml('🌟 Unable to fetch the ' + escHtml(ucFirst(zodiac)) + ' horoscope right now.' + ctaHtml());
			return;
		}

		var snippet = escHtml(text.substring(0, 320)) + (text.length > 320 ? '…' : '');
		appendHtml(
			'🌟 <strong>' + escHtml(ucFirst(zodiac)) + ' — Today\'s Horoscope</strong><br><br>' +
			snippet + '<br><br>' +
			'<small>Sign up to get the full reading, weekly forecast &amp; your personalized Kundali:</small>' + ctaHtml()
		);
	}

	function showZodiacPicker() {
		collapseWelcome();
		var wrap   = document.createElement('div');
		var bubble = document.createElement('div');
		wrap.className   = 'sas-hc-msg sas-hc-msg-assistant';
		bubble.className = 'sas-hc-msg-bubble';

		var label = document.createElement('p');
		label.textContent = '🌟 Which zodiac sign would you like a reading for?';
		bubble.appendChild(label);

		var grid = document.createElement('div');
		grid.className = 'sas-hc-zodiac-grid';

		[
			['aries','♈'],['taurus','♉'],['gemini','♊'],['cancer','♋'],
			['leo','♌'],['virgo','♍'],['libra','♎'],['scorpio','♏'],
			['sagittarius','♐'],['capricorn','♑'],['aquarius','♒'],['pisces','♓'],
		].forEach(function (s) {
			var btn = document.createElement('button');
			btn.className   = 'sas-hc-zodiac-btn';
			btn.type        = 'button';
			btn.textContent = s[1] + ' ' + ucFirst(s[0]);
			btn.addEventListener('click', function () {
				removeEl(wrap);
				appendText('user', s[1] + ' ' + ucFirst(s[0]));
				guestHoroscope(s[0]);
			});
			grid.appendChild(btn);
		});

		bubble.appendChild(grid);
		wrap.appendChild(bubble);
		elMessages.appendChild(wrap);
		scrollBottom();
	}

	async function guestMuhurat() {
		var typing = appendTyping();
		var data   = await publicGet('/muhurat');
		removeEl(typing);

		if (!data || data.error) {
			appendHtml('⏰ <strong>Auspicious Times Today</strong><br><br>Unable to fetch Muhurat data right now.' + ctaHtml());
			return;
		}

		var arr = data.day || data.day_choghadiya || data.choghadiya ||
		          (data.response && (data.response.day || data.response)) || [];
		var rows = '';
		if (Array.isArray(arr)) {
			arr.slice(0, 5).forEach(function (s) {
				var name = s.muhurta_name || s.name || s.type || '';
				var time = s.time || s.muhurta_time || (s.start && s.end ? s.start + '–' + s.end : '') || '';
				var good = /amrit|shubh|labh|char/i.test(name);
				if (name) {
					rows += '<div class="sas-hc-mrow' + (good ? ' sas-hc-mrow--good' : '') + '">' +
						'<span>' + (good ? '✅' : '⚠️') + ' ' + escHtml(name) + '</span>' +
						(time ? '<small>' + escHtml(time) + '</small>' : '') + '</div>';
				}
			});
		}

		appendHtml(
			'⏰ <strong>Today\'s Choghadiya (Auspicious Times)</strong><br><br>' +
			(rows || 'Muhurat data unavailable right now.') +
			'<br><br><small>Sign up for daily Muhurat alerts &amp; personalized timings:</small>' + ctaHtml()
		);
	}

	/** Route a guest message intelligently based on keywords. */
	async function handleGuestMessage(text) {
		var l = text.toLowerCase();

		if (/panchang|almanac|tithi|nakshatra|yoga|karana|samvat/.test(l)) {
			await guestPanchang();

		} else if (/horoscope|rashifal|rashi|zodiac|aries|taurus|gemini|cancer|leo|virgo|libra|scorpio|sagittarius|capricorn|aquarius|pisces/.test(l)) {
			var signs    = ['aries','taurus','gemini','cancer','leo','virgo','libra','scorpio','sagittarius','capricorn','aquarius','pisces'];
			var detected = null;
			for (var i = 0; i < signs.length; i++) {
				if (l.indexOf(signs[i]) !== -1) { detected = signs[i]; break; }
			}
			if (!detected && sasConfig.userZodiac) detected = sasConfig.userZodiac;
			if (detected) { await guestHoroscope(detected); } else { showZodiacPicker(); }

		} else if (/muhurat|auspicious|shubh|choghadiya|timing|good time/.test(l)) {
			await guestMuhurat();

		} else if (/festival|utsav|puja|pooja|tyohar|celebrat/.test(l)) {
			await guestPanchang(); // festivals come from panchang data

		} else if (/kundali|birth chart|janam|natal|jyotish/.test(l)) {
			appendHtml(
				'📿 <strong>Vedic Kundali — Your Cosmic Blueprint</strong><br><br>' +
				'Your Kundali is the precise map of the sky at your exact birth moment. It reveals:<br><br>' +
				'• ☀️ Sun Sign (Surya Rashi)<br>' +
				'• 🌙 Moon Sign (Chandra Rashi)<br>' +
				'• ⬆️ Ascendant / Lagna<br>' +
				'• 🪐 All 9 Planets &amp; their house placements<br>' +
				'• 🏠 12 Houses governing every area of life<br><br>' +
				'Create your free account to generate your complete Kundali:' + ctaHtml()
			);

		} else if (/what can|feature|capability|offer|help|do for|use for|tool/.test(l)) {
			appendHtml(
				'🙏 <strong>Jai Shri Ram! Welcome to Sanathan Guruji.</strong><br><br>' +
				'I\'m your personal Vedic AI guide. Here\'s what I can do for you:<br><br>' +
				'🗓️ <strong>Panchang</strong> — Daily Tithi, Nakshatra, Yoga &amp; Karana<br>' +
				'🌟 <strong>Horoscope</strong> — Daily, weekly &amp; yearly in 9 languages<br>' +
				'📿 <strong>Kundali</strong> — Full birth chart with planetary insights<br>' +
				'🪔 <strong>Festivals</strong> — Hindu celebrations, vrats &amp; timings<br>' +
				'⏰ <strong>Muhurat</strong> — Auspicious times for important activities<br>' +
				'💬 <strong>Personal Guidance</strong> — Dharma, spirituality &amp; life questions<br><br>' +
				'Sign up free to unlock your fully personalised experience:' + ctaHtml()
			);

		} else {
			// Generic: show a teaser then guide to register
			var snippet = text.length > 70 ? text.substring(0, 70) + '…' : text;
			appendHtml(
				'🙏 <strong>Jai Shri Ram!</strong><br><br>' +
				'I\'d love to guide you on <em>"' + escHtml(snippet) + '"</em>.<br><br>' +
				'For personalized Vedic guidance tailored to your birth chart, zodiac sign, and spiritual journey, ' +
				'please create your free account:' + ctaHtml()
			);
		}
	}

	/* ════════════════════════════════════════════════════════════════════════
	   LOGGED-IN: LOAD HISTORY
	════════════════════════════════════════════════════════════════════════ */

	async function loadHistory() {
		if (historyLoaded) return;
		historyLoaded = true;

		var endpoint = '/guruji/history';
		if (sessionToken) endpoint += '?session_token=' + encodeURIComponent(sessionToken);

		var history = await apiGet(endpoint);

		if (history && Array.isArray(history) && history.length > 0) {
			collapseWelcome();
			history.forEach(function (msg) {
				appendText(msg.role, msg.message);
			});
		}
		scrollBottom();
	}

	/* ════════════════════════════════════════════════════════════════════════
	   SEND MESSAGE
	════════════════════════════════════════════════════════════════════════ */

	async function sendMessage() {
		if (isLoading) return;
		var text = elInput.value.trim();
		if (!text) return;

		elInput.value = '';
		autoResize();
		isLoading = true;
		elSend.disabled = true;

		appendText('user', text);

		if (!getIsLoggedIn()) {
			// ── Guest: keyword routing + public API data ──────────────────────
			await handleGuestMessage(text);

		} else {
			// ── Logged-in: full Guruji AI (backend knows Kundali context) ────
			var typing = appendTyping();
			var data   = await apiPost('/guruji/chat', { message: text, session_token: sessionToken });
			removeEl(typing);

			if (data) {
				if (data.session_token) {
					sessionToken = data.session_token;
					try { sessionStorage.setItem('sas_guruji_session', sessionToken); } catch (e) {}
				}
				if (data.setup_required) {
					appendHtml(
						'🙏 <strong>Let\'s set up your personal Guruji first!</strong><br><br>' +
						'Choose your Guruji\'s name, personality, and language for a truly personalised experience.<br><br>' +
						'<a href="' + sasConfig.gurujiUrl + '" class="sas-hc-cta-primary">Set Up My Guruji →</a>'
					);
				} else {
					appendText('assistant', data.reply || '🙏 I am here. Please ask your question.');
				}
			} else {
				appendText('assistant', '🙏 I apologise — there was a connection issue. Please try again in a moment.');
			}
		}

		isLoading = false;
		elSend.disabled = false;
		elInput.focus();
	}

	/* ════════════════════════════════════════════════════════════════════════
	   CHIPS (mobile quick-start)
	════════════════════════════════════════════════════════════════════════ */

	function handleChipClick(e) {
		var btn = e.target.closest('[data-prompt]');
		if (!btn) return;
		var prompt = btn.getAttribute('data-prompt');
		if (!prompt) return;
		elInput.value = prompt;
		autoResize();
		sendMessage();
	}

	/* ════════════════════════════════════════════════════════════════════════
	   TEXTAREA AUTO-RESIZE
	════════════════════════════════════════════════════════════════════════ */

	function autoResize() {
		if (!elInput) return;
		elInput.style.height = 'auto';
		elInput.style.height = Math.min(elInput.scrollHeight, 120) + 'px';
	}

	/* ════════════════════════════════════════════════════════════════════════
	   BOOTSTRAP
	════════════════════════════════════════════════════════════════════════ */

	function init() {
		if (typeof sasConfig === 'undefined') return;

		// Sidebar elements
		elSidebar   = document.getElementById('sas-hc-sidebar');
		elHamburger = document.getElementById('sas-hc-hamburger');
		elOverlay   = document.getElementById('sas-hc-overlay');

		// Chat elements
		elWelcome  = document.getElementById('sas-hc-welcome');
		elMessages = document.getElementById('sas-hc-messages');
		elChips    = document.getElementById('sas-hc-chips');
		elInput    = document.getElementById('sas-hc-input');
		elSend     = document.getElementById('sas-hc-send');

		if (!elMessages || !elInput || !elSend) return;

		// ── Sidebar wiring ────────────────────────────────────────────────────
		if (elSidebar) {
			elSidebar.addEventListener('click', handleSidebarClick);
		}
		if (elHamburger) {
			elHamburger.addEventListener('click', toggleSidebar);
		}
		if (elOverlay) {
			elOverlay.addEventListener('click', closeSidebar);
		}

		// Close sidebar on Escape
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && sidebarOpen) closeSidebar();
		});

		// ── Chat wiring ───────────────────────────────────────────────────────
		elSend.addEventListener('click', sendMessage);

		elInput.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				sendMessage();
			}
		});

		elInput.addEventListener('input', autoResize);

		if (elChips) {
			elChips.addEventListener('click', handleChipClick);
		}

		// ── Restore session token ─────────────────────────────────────────────
		try {
			var stored = sessionStorage.getItem('sas_guruji_session');
			if (stored) sessionToken = stored;
		} catch (e) { /* sessionStorage unavailable */ }

		// ── Load history for logged-in users (non-blocking) ───────────────────
		if (getIsLoggedIn()) {
			loadHistory();
		}

		// Focus input on desktop (avoid zoom on mobile)
		if (window.innerWidth > 768) {
			elInput.focus();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

})();
