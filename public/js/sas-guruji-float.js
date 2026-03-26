/**
 * Sanathan Guruji — Floating Chat Bubble
 * Powers the wp_footer-rendered #sas-guruji-float widget on all frontend pages.
 * Talks to the sanathan/v1 Guruji REST endpoints.
 * @version 1.0.0
 */
(function() {
	'use strict';

	/* ── State ── */
	var isOpen        = false;
	var sessionToken  = null;   // persisted in sessionStorage
	var isLoading     = false;  // prevent double-send
	var gurujiName    = 'Guruji'; // updated from profile API
	var historyLoaded = false;  // load history only once per page

	/* ── Element refs ── */
	var elBubble, elModal, elClose, elMessages, elInput, elSend, elInputRow;

	/* ─────────────────────────────────────────────
	   Login-state helper
	   wp_localize_script can be cached by full-page cache plugins (WP Rocket,
	   W3TC, LiteSpeed, etc.) with isLoggedIn:false even for a logged-in user.
	   Fall back to the WordPress login cookie, which is set on the client at
	   login time and is accessible from JS (it is NOT httpOnly).
	───────────────────────────────────────────── */

	function getIsLoggedIn() {
		// Trust the PHP value first — it is always correct on uncached pages.
		if (sasConfig.isLoggedIn) return true;

		// Fallback: WordPress sets 'wordpress_logged_in_<hash>' on login.
		// This cookie is readable by JS (it is NOT httpOnly).
		var cookieLoggedIn = document.cookie.indexOf('wordpress_logged_in_') !== -1;
		if (!cookieLoggedIn) return false;

		// Cookie says logged-in but the page was served from guest cache.
		// A cached page also has a stale nonce (generated for user_id=0),
		// so API calls would fail.  Reload once — most caching plugins skip
		// the cache when the 'wordpress_logged_in_*' cookie is present, so
		// the reload delivers the correct authenticated page with a fresh nonce.
		// sessionStorage prevents infinite reload loops.
		try {
			var RELOAD_KEY = 'sas_auth_reload';
			if (!sessionStorage.getItem(RELOAD_KEY)) {
				sessionStorage.setItem(RELOAD_KEY, '1');
				window.location.reload();
				return false; // Execution stops here; reload is in progress.
			}
		} catch (e) { /* sessionStorage unavailable — skip reload guard */ }

		return true;
	}

	/* ─────────────────────────────────────────────
	   API helpers
	───────────────────────────────────────────── */

	async function gurujiPost(endpoint, body) {
		try {
			var resp = await fetch(sasConfig.restBase + endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': sasConfig.nonce,
				},
				body: JSON.stringify(body),
			});
			if (!resp.ok) return null;
			return await resp.json();
		} catch (e) { return null; }
	}

	async function gurujiGet(endpoint) {
		try {
			var resp = await fetch(sasConfig.restBase + endpoint, {
				headers: { 'X-WP-Nonce': sasConfig.nonce },
			});
			if (!resp.ok) return null;
			return await resp.json();
		} catch (e) { return null; }
	}

	/* ─────────────────────────────────────────────
	   Message rendering helpers
	───────────────────────────────────────────── */

	/**
	 * Build a message bubble using createElement (XSS-safe).
	 * Newlines in assistant replies become <br> elements.
	 * @param {string} role  'user' | 'assistant'
	 * @param {string} text  Plain text content
	 */
	function appendMessage(role, text) {
		var wrap   = document.createElement('div');
		var bubble = document.createElement('div');

		wrap.className   = 'sas-guruji-msg sas-guruji-msg-' + role;
		bubble.className = 'sas-guruji-msg-bubble';

		// Safe newline→<br> handling: never use innerHTML for untrusted text.
		// Split on \n, insert text nodes separated by <br> elements.
		var lines = String(text).split('\n');
		for (var i = 0; i < lines.length; i++) {
			if (i > 0) {
				bubble.appendChild(document.createElement('br'));
			}
			bubble.appendChild(document.createTextNode(lines[i]));
		}

		wrap.appendChild(bubble);
		elMessages.appendChild(wrap);
		return wrap;
	}

	/**
	 * Append an animated typing indicator.
	 * @returns {HTMLElement} The indicator element (caller must remove it).
	 */
	function appendTypingIndicator() {
		var wrap   = document.createElement('div');
		var bubble = document.createElement('div');

		wrap.className   = 'sas-guruji-msg sas-guruji-msg-assistant';
		bubble.className = 'sas-guruji-msg-bubble sas-guruji-typing';
		bubble.setAttribute('aria-label', 'Guruji is typing');

		// Three dots — CSS animates them
		for (var i = 0; i < 3; i++) {
			var dot = document.createElement('span');
			bubble.appendChild(dot);
		}

		wrap.appendChild(bubble);
		elMessages.appendChild(wrap);
		return wrap;
	}

	function scrollToBottom() {
		if (elMessages) {
			elMessages.scrollTop = elMessages.scrollHeight;
		}
	}

	/* ─────────────────────────────────────────────
	   Guest prompt (logged-out users)
	───────────────────────────────────────────── */

	function showGuestPrompt() {
		// Hide the input row
		elInputRow.classList.add('sas-guruji-input-row--hidden');

		// Build prompt elements safely
		var wrap = document.createElement('div');
		wrap.className = 'sas-guruji-guest-prompt';

		var icon = document.createElement('div');
		icon.className = 'sas-guruji-guest-icon';
		icon.textContent = '🙏';

		var para = document.createElement('p');
		para.textContent = 'Sign in to chat with your personal Guruji AI — your Vedic spiritual advisor available 24/7.';

		var loginBtn = document.createElement('a');
		loginBtn.href      = sasConfig.loginUrl;
		loginBtn.className = 'sas-guruji-login-btn';
		loginBtn.textContent = 'Sign In to Chat';

		var registerBtn = document.createElement('a');
		registerBtn.href      = sasConfig.registerUrl;
		registerBtn.className = 'sas-guruji-register-btn';
		registerBtn.textContent = 'Create Free Account';

		wrap.appendChild(icon);
		wrap.appendChild(para);
		wrap.appendChild(loginBtn);
		wrap.appendChild(registerBtn);

		elMessages.appendChild(wrap);
	}

	/* ─────────────────────────────────────────────
	   Load history + profile on first open
	───────────────────────────────────────────── */

	async function loadHistoryAndGreet() {
		historyLoaded = true; // set early to avoid duplicate loads on rapid open/close

		// 1. Fetch profile — update gurujiName and modal avatar
		var profile = await gurujiGet('/guruji/profile');
		if (profile && !profile.setup_required) {
			if (profile.guruji_name) {
				gurujiName = profile.guruji_name;
				// Update modal title text
				var titleSpan = document.querySelector('.sas-guruji-modal-title > span:last-child');
				if (titleSpan) {
					titleSpan.textContent = gurujiName;
				}
			}
			if (profile.avatar_url) {
				var avatarEl = document.querySelector('.sas-guruji-modal-avatar');
				if (avatarEl) {
					var img = document.createElement('img');
					img.src = profile.avatar_url;
					img.alt = gurujiName;
					img.className = 'sas-guruji-avatar-img';
					// Replace emoji with image safely
					avatarEl.textContent = '';
					avatarEl.appendChild(img);
				}
				// Also update the bubble icon
				var bubbleIcon = document.querySelector('.sas-guruji-bubble-icon');
				if (bubbleIcon) {
					var bubbleImg = document.createElement('img');
					bubbleImg.src = profile.avatar_url;
					bubbleImg.alt = gurujiName;
					bubbleImg.className = 'sas-guruji-avatar-img';
					bubbleIcon.textContent = '';
					bubbleIcon.appendChild(bubbleImg);
				}
			}
		}

		// 2. Fetch history
		var historyEndpoint = '/guruji/history';
		if (sessionToken) {
			historyEndpoint += '?session_token=' + encodeURIComponent(sessionToken);
		}
		var history = await gurujiGet(historyEndpoint);

		if (history && Array.isArray(history) && history.length > 0) {
			// Render existing messages
			for (var i = 0; i < history.length; i++) {
				appendMessage(history[i].role, history[i].message);
			}
		} else {
			// No history — show welcome greeting
			appendMessage('assistant', '🙏 Jai Shri Ram! I am ' + gurujiName + ', your personal Vedic guide. How may I help you today?');
		}

		scrollToBottom();
	}

	/* ─────────────────────────────────────────────
	   Modal open / close
	───────────────────────────────────────────── */

	function openModal() {
		elModal.removeAttribute('hidden');
		isOpen = true;
		elBubble.setAttribute('aria-expanded', 'true');
		elInput.focus();

		if (!getIsLoggedIn()) {
			// Show guest prompt only once
			if (!elMessages.querySelector('.sas-guruji-guest-prompt')) {
				showGuestPrompt();
			}
		} else {
			// Load history only on first open
			if (!historyLoaded) {
				loadHistoryAndGreet();
			}
		}
	}

	function closeModal() {
		elModal.setAttribute('hidden', '');
		isOpen = false;
		elBubble.setAttribute('aria-expanded', 'false');
		elBubble.focus();
	}

	function toggleModal() {
		if (isOpen) {
			closeModal();
		} else {
			openModal();
		}
	}

	/* ─────────────────────────────────────────────
	   Send message
	───────────────────────────────────────────── */

	async function sendMessage() {
		if (isLoading) return;

		var text = elInput.value.trim();
		if (!text) return;

		// Clear input
		elInput.value = '';

		// Disable UI
		isLoading = true;
		elSend.disabled = true;

		// Render user bubble
		appendMessage('user', text);

		// Typing indicator
		var typingEl = appendTypingIndicator();
		scrollToBottom();

		// POST to backend
		var data = await gurujiPost('/guruji/chat', {
			message:       text,
			session_token: sessionToken,
		});

		// Remove typing indicator
		if (typingEl && typingEl.parentNode) {
			typingEl.parentNode.removeChild(typingEl);
		}

		if (data) {
			// Persist session token
			if (data.session_token) {
				sessionToken = data.session_token;
				try {
					sessionStorage.setItem('sas_guruji_session', sessionToken);
				} catch (e) { /* sessionStorage unavailable */ }
			}
			// Render assistant reply
			var reply = (data.reply && String(data.reply).trim())
				? data.reply
				: '🙏 I am here. Please ask your question again.';
			appendMessage('assistant', reply);
		} else {
			// Network / server error
			appendMessage('assistant', '🙏 I apologise — there was a problem connecting. Please try again in a moment.');
		}

		scrollToBottom();

		// Re-enable UI
		isLoading = false;
		elSend.disabled = false;
		elInput.focus();
	}

	/* ─────────────────────────────────────────────
	   Bootstrap on DOMContentLoaded
	───────────────────────────────────────────── */

	function init() {
		// Guard: sasConfig must exist
		if (typeof sasConfig === 'undefined') return;

		// Resolve elements
		elBubble    = document.getElementById('sas-guruji-bubble');
		elModal     = document.getElementById('sas-guruji-modal');
		elClose     = document.getElementById('sas-guruji-close');
		elMessages  = document.getElementById('sas-guruji-messages');
		elInput     = document.getElementById('sas-guruji-input');
		elSend      = document.getElementById('sas-guruji-send');
		elInputRow  = elModal ? elModal.querySelector('.sas-guruji-input-row') : null;

		// No-op guard — if any required element is missing, bail out
		if (!elBubble || !elModal || !elClose || !elMessages || !elInput || !elSend || !elInputRow) {
			return;
		}

		// Restore session token
		try {
			var stored = sessionStorage.getItem('sas_guruji_session');
			if (stored) sessionToken = stored;
		} catch (e) { /* sessionStorage unavailable */ }

		// Wire up events
		elBubble.addEventListener('click', toggleModal);
		elClose.addEventListener('click', closeModal);
		elSend.addEventListener('click', sendMessage);

		elInput.addEventListener('keydown', function(e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				sendMessage();
			}
		});

		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape' && isOpen) {
				closeModal();
			}
		});

		// Auto-open the chat modal when on the dedicated /guruji/ page.
		// sasConfig.isGurujiPage is set to true by PHP only on that page.
		if (sasConfig.isGurujiPage) {
			openModal();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		// DOM already ready (deferred script)
		init();
	}

})();
