/**
 * Sanathan Home Chat — Full-page Guruji AI interface
 * Powers the [sas_home_chat] shortcode on the homepage.
 *
 * Guests:      Smart keyword detection → fetch real data from public REST endpoints
 *              (Panchang, predictions, muhurat) + guided signup funnel.
 * Logged-in:   Full Guruji AI chat via /guruji/chat with session history.
 * @version 1.0.0
 */
(function () {
	'use strict';

	/* ── State ─────────────────────────────────────────────────────────────── */
	var sessionToken  = null;   // persisted in sessionStorage
	var isLoading     = false;  // prevent double-send
	var historyLoaded = false;  // load history only once per mount

	/* ── Elements ───────────────────────────────────────────────────────────── */
	var elWelcome, elMessages, elChips, elInput, elSend;

	/* ── Helpers ────────────────────────────────────────────────────────────── */

	/** HTML-escape a string for safe interpolation into innerHTML template strings. */
	function escHtml(str) {
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(String(str)));
		return d.innerHTML;
	}

	/** Capitalise first letter. */
	function ucFirst(str) {
		return str.charAt(0).toUpperCase() + str.slice(1);
	}

	/* ─────────────────────────────────────────────
	   Login-state helper — same cookie-fallback
	   pattern as sas-guruji-float.js.
	───────────────────────────────────────────── */
	function getIsLoggedIn() {
		if (typeof sasConfig === 'undefined') return false;
		if (sasConfig.isLoggedIn) return true;

		// Cookie fallback for full-page cache scenarios
		if (document.cookie.indexOf('wordpress_logged_in_') === -1) return false;

		// Stale guest-cached page — reload once to get authenticated version
		try {
			var KEY = 'sas_auth_reload';
			if (!sessionStorage.getItem(KEY)) {
				sessionStorage.setItem(KEY, '1');
				window.location.reload();
				return false;
			}
		} catch (e) { /* sessionStorage unavailable */ }

		return true;
	}

	/* ── API helpers ─────────────────────────────────────────────────────────── */

	async function apiPost(endpoint, body) {
		try {
			var r = await fetch(sasConfig.restBase + endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': sasConfig.nonce,
				},
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

	/** Public (no-auth) GET — for panchang, predictions, muhurat. */
	async function publicGet(endpoint) {
		try {
			var r = await fetch(sasConfig.restBase + endpoint);
			return r.ok ? r.json() : null;
		} catch (e) { return null; }
	}

	/* ── UI transitions ──────────────────────────────────────────────────────── */

	/** Collapse the welcome header and hide suggestion chips. */
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

	/* ── Message rendering ───────────────────────────────────────────────────── */

	/**
	 * Append a text-only message bubble (XSS-safe via textContent).
	 * Used for user input and Guruji AI text replies.
	 */
	function appendText(role, text) {
		collapseWelcome();
		var wrap   = document.createElement('div');
		var bubble = document.createElement('div');
		wrap.className   = 'sas-hc-msg sas-hc-msg-' + role;
		bubble.className = 'sas-hc-msg-bubble';

		// Newline → <br> without innerHTML
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
	 * Append an HTML-formatted assistant message.
	 * ONLY called with server-controlled template strings or escHtml()-sanitised data.
	 * NEVER called with raw user input or unescaped API strings.
	 */
	function appendHtml(html) {
		collapseWelcome();
		var wrap   = document.createElement('div');
		var bubble = document.createElement('div');
		wrap.className   = 'sas-hc-msg sas-hc-msg-assistant';
		bubble.className = 'sas-hc-msg-bubble';
		bubble.innerHTML = html; // safe: only our own templates + escHtml() values
		wrap.appendChild(bubble);
		elMessages.appendChild(wrap);
		scrollBottom();
		return wrap;
	}

	/** Append an animated typing indicator. Returns the element so caller can remove it. */
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

	/* ── Guest CTA builder ───────────────────────────────────────────────────── */

	function ctaHtml() {
		return '<div class="sas-hc-signup-cta">' +
			'<a href="' + sasConfig.registerUrl + '" class="sas-hc-cta-primary">🙏 Create Free Account</a>' +
			'<a href="' + sasConfig.loginUrl    + '" class="sas-hc-cta-secondary">Sign In</a>' +
			'</div>';
	}

	/* ── Data extraction helpers ─────────────────────────────────────────────── */

	/**
	 * Extract prediction text from the /predictions REST response.
	 * Response shape: { status:'ok', source:'cache', data: <raw_vedic_api_json> }
	 * VedicAstroAPI shapes: { bot_response }, { response:[{bot_response}] }, { response:{bot_response} }
	 */
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

	/**
	 * Extract a named field from the Panchang API response.
	 * Handles: direct string, { name }, { details: { <key>_name } }.
	 */
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

	/* ── Guest response handlers ─────────────────────────────────────────────── */

	async function guestPanchang() {
		var typing = appendTyping();
		var data   = await publicGet('/panchang');
		removeEl(typing);

		if (!data || data.error) {
			appendHtml('🗓️ <strong>Today\'s Panchang</strong><br><br>Unable to fetch Panchang right now — please try again in a moment.' + ctaHtml());
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
			if (val) {
				rows += '<div class="sas-hc-prow"><span>' + f.label + '</span><strong>' + escHtml(val) + '</strong></div>';
			}
		});

		// Festivals from multiple possible keys
		var fRaw = data.festival || data.festivals || (data.response && data.response.festival) || null;
		var festNames = [];
		if (Array.isArray(fRaw)) {
			fRaw.slice(0, 3).forEach(function (f) {
				var n = typeof f === 'string' ? f : (f.name || f.festival_name || '');
				if (n) festNames.push(n);
			});
		} else if (typeof fRaw === 'string' && fRaw) {
			festNames.push(fRaw);
		}

		var html = '🗓️ <strong>Today\'s Panchang</strong><br><br>';
		if (rows) html += '<div class="sas-hc-panchang-grid">' + rows + '</div>';
		if (festNames.length) {
			html += '<br>🪔 <strong>Festivals Today:</strong> ' + festNames.map(escHtml).join(' · ');
		}
		html += '<br><br><small>Sign up for the full Panchang with Rahu Kaal, Sunrise/Sunset &amp; Muhurat alerts:</small>' + ctaHtml();
		appendHtml(html);
	}

	async function guestHoroscope(zodiac) {
		var typing = appendTyping();
		var result = await publicGet('/predictions?zodiac=' + encodeURIComponent(zodiac) + '&lang=en&cycle=daily');
		removeEl(typing);

		var text = extractPrediction(result);
		if (!text) {
			appendHtml('🌟 Unable to fetch the ' + escHtml(ucFirst(zodiac)) + ' horoscope right now. Please try again shortly.' + ctaHtml());
			return;
		}

		var snippet = escHtml(text.substring(0, 300)) + (text.length > 300 ? '…' : '');
		appendHtml(
			'🌟 <strong>' + escHtml(ucFirst(zodiac)) + ' — Today\'s Horoscope</strong><br><br>' +
			snippet + '<br><br>' +
			'<small>Sign up for the full reading, weekly forecast &amp; personalized Kundali:</small>' + ctaHtml()
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

		// Choghadiya slots — multiple possible response shapes
		var arr = data.day || data.day_choghadiya || data.choghadiya ||
		          (data.response && (data.response.day || data.response)) || [];
		var slots = [];
		if (Array.isArray(arr)) {
			arr.slice(0, 5).forEach(function (s) {
				var name = s.muhurta_name || s.name || s.type || '';
				var time = s.time || s.muhurta_time || (s.start && s.end ? s.start + '–' + s.end : '') || '';
				var good = /amrit|shubh|labh|char/i.test(name);
				if (name) slots.push({ name: name, time: time, good: good });
			});
		}

		var rows = '';
		slots.forEach(function (s) {
			rows += '<div class="sas-hc-mrow' + (s.good ? ' sas-hc-mrow--good' : '') + '">' +
				'<span>' + (s.good ? '✅' : '⚠️') + ' ' + escHtml(s.name) + '</span>' +
				(s.time ? '<small>' + escHtml(s.time) + '</small>' : '') + '</div>';
		});

		appendHtml(
			'⏰ <strong>Today\'s Choghadiya (Auspicious Times)</strong><br><br>' +
			(rows || 'Muhurat data unavailable right now.') +
			'<br><br><small>Sign up for daily Muhurat alerts &amp; personalized timings:</small>' + ctaHtml()
		);
	}

	/** Route a guest message to the appropriate handler based on keyword detection. */
	async function handleGuestMessage(text) {
		var l = text.toLowerCase();

		if (/panchang|almanac|tithi|nakshatra|yoga|karana|samvat/.test(l)) {
			await guestPanchang();

		} else if (/horoscope|rashifal|rashi|zodiac|aries|taurus|gemini|cancer|leo|virgo|libra|scorpio|sagittarius|capricorn|aquarius|pisces/.test(l)) {
			// Auto-detect zodiac sign from message
			var signs = ['aries','taurus','gemini','cancer','leo','virgo','libra','scorpio','sagittarius','capricorn','aquarius','pisces'];
			var detected = null;
			for (var i = 0; i < signs.length; i++) {
				if (l.indexOf(signs[i]) !== -1) { detected = signs[i]; break; }
			}
			// Fall back to user's Kundali zodiac (injected by PHP if logged-in-ish)
			if (!detected && sasConfig.userZodiac) detected = sasConfig.userZodiac;
			if (detected) {
				await guestHoroscope(detected);
			} else {
				showZodiacPicker();
			}

		} else if (/muhurat|auspicious|shubh|choghadiya|timing|good time/.test(l)) {
			await guestMuhurat();

		} else if (/festival|utsav|puja|pooja|tyohar|celebrat/.test(l)) {
			// Panchang includes festival data
			await guestPanchang();

		} else if (/kundali|birth chart|janam|natal|jyotish/.test(l)) {
			appendHtml(
				'📿 <strong>Vedic Kundali (Birth Chart)</strong><br><br>' +
				'Your Kundali is the cosmic blueprint of your soul — the precise map of the sky at your exact birth moment. It reveals:<br><br>' +
				'• ☀️ <strong>Sun Sign</strong> (Surya Rashi)<br>' +
				'• 🌙 <strong>Moon Sign</strong> (Chandra Rashi)<br>' +
				'• ⬆️ <strong>Ascendant / Lagna</strong><br>' +
				'• 🪐 <strong>All 9 Planets</strong> and their house placements<br>' +
				'• 🏠 <strong>12 Houses</strong> governing every area of life<br><br>' +
				'Create your free account to generate your complete Kundali:' + ctaHtml()
			);

		} else if (/what can|feature|capability|offer|help|do for|use for|tool/.test(l)) {
			appendHtml(
				'🙏 <strong>Jai Shri Ram! Welcome to Sanathan Guruji.</strong><br><br>' +
				'I\'m your personal Vedic AI guide. Here\'s what I can do for you:<br><br>' +
				'🗓️ <strong>Daily Panchang</strong> — Tithi, Nakshatra, Yoga, Karana<br>' +
				'🌟 <strong>Zodiac Readings</strong> — Daily, weekly &amp; yearly in 9 languages<br>' +
				'📿 <strong>Kundali</strong> — Full birth chart with planetary insights<br>' +
				'🪔 <strong>Hindu Festivals</strong> — Upcoming celebrations, vrats &amp; timings<br>' +
				'⏰ <strong>Muhurat</strong> — Auspicious times for important activities<br>' +
				'💬 <strong>Personal Guidance</strong> — Dharma, spirituality &amp; life questions<br><br>' +
				'Sign up free to unlock your fully personalised experience:' + ctaHtml()
			);

		} else {
			// Generic response — show relevant teaser + signup prompt
			var snippet = text.length > 70 ? text.substring(0, 70) + '…' : text;
			appendHtml(
				'🙏 <strong>Jai Shri Ram!</strong><br><br>' +
				'I\'d love to guide you on <em>"' + escHtml(snippet) + '"</em>.<br><br>' +
				'For personalized Vedic guidance tailored to your birth chart, zodiac sign, and spiritual journey — ' +
				'create your free account:' + ctaHtml()
			);
		}
	}

	/* ── Logged-in: history ──────────────────────────────────────────────────── */

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

	/* ── Send ────────────────────────────────────────────────────────────────── */

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
			// ── Guest: smart keyword routing
			await handleGuestMessage(text);

		} else {
			// ── Logged-in: real Guruji AI
			var typing = appendTyping();
			var data   = await apiPost('/guruji/chat', { message: text, session_token: sessionToken });
			removeEl(typing);

			if (data) {
				if (data.session_token) {
					sessionToken = data.session_token;
					try { sessionStorage.setItem('sas_guruji_session', sessionToken); } catch (e) {}
				}
				if (data.setup_required) {
					// Profile not set up yet — guide them to the profile setup
					appendHtml(
						'🙏 <strong>Let\'s set up your personal Guruji first!</strong><br><br>' +
						'Choose your Guruji\'s name, personality, and preferred language for a truly personalized experience.<br><br>' +
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

	/* ── Chips ───────────────────────────────────────────────────────────────── */

	function handleChipClick(e) {
		var btn = e.target.closest('[data-prompt]');
		if (!btn) return;
		var prompt = btn.getAttribute('data-prompt');
		if (!prompt) return;
		elInput.value = prompt;
		autoResize();
		sendMessage();
	}

	/* ── Textarea auto-resize ────────────────────────────────────────────────── */

	function autoResize() {
		if (!elInput) return;
		elInput.style.height = 'auto';
		elInput.style.height = Math.min(elInput.scrollHeight, 120) + 'px';
	}

	/* ── Bootstrap ───────────────────────────────────────────────────────────── */

	function init() {
		if (typeof sasConfig === 'undefined') return;

		elWelcome  = document.getElementById('sas-hc-welcome');
		elMessages = document.getElementById('sas-hc-messages');
		elChips    = document.getElementById('sas-hc-chips');
		elInput    = document.getElementById('sas-hc-input');
		elSend     = document.getElementById('sas-hc-send');

		if (!elMessages || !elInput || !elSend) return;

		// Restore session token from previous session
		try {
			var stored = sessionStorage.getItem('sas_guruji_session');
			if (stored) sessionToken = stored;
		} catch (e) { /* sessionStorage unavailable */ }

		// Load history for logged-in users (non-blocking)
		if (getIsLoggedIn()) {
			loadHistory();
		}

		// Wire up events
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

		// Focus input on load (desktop only — avoid zooming on mobile)
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
