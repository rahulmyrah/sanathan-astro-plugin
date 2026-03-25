/**
 * Sanathan Astro — Daily Dashboard JavaScript
 * - Tab switching (panchang, zodiac, festivals, times)
 * - Panchang + festivals panel population
 * - Choghadiya/Muhurat panel population
 * - Zodiac prediction panel (daily/weekly/yearly)
 * @version 1.0.0
 */
(function() {
	'use strict';

	/* ─────────────────────────────────────────────
	   UTILITY FUNCTIONS
	───────────────────────────────────────────── */

	/**
	 * Returns today's date as YYYY-MM-DD in IST (UTC+5:30).
	 * @returns {string}
	 */
	function getTodayIST() {
		var now = new Date();
		var ist = new Date( now.getTime() + ( 5.5 * 60 * 60 * 1000 ) );
		return ist.toISOString().split( 'T' )[ 0 ];
	}

	/**
	 * Returns today's date formatted for display, e.g. "Wednesday, 25 March 2026".
	 * Uses IST date so it matches getTodayIST().
	 * @returns {string}
	 */
	function getFormattedDateIST() {
		var now = new Date();
		var ist = new Date( now.getTime() + ( 5.5 * 60 * 60 * 1000 ) );
		return ist.toLocaleDateString( 'en-IN', {
			weekday:  'long',
			day:      'numeric',
			month:    'long',
			year:     'numeric',
			timeZone: 'Asia/Kolkata',
		} );
	}

	/**
	 * Generic authenticated REST fetch.
	 * Returns parsed JSON on success, null on any error.
	 * @param {string} url
	 * @returns {Promise<object|null>}
	 */
	async function apiFetch( url ) {
		try {
			var resp = await fetch( url, {
				headers: { 'X-WP-Nonce': ( window.sasConfig && sasConfig.nonce ) || '' },
			} );
			if ( ! resp.ok ) return null;
			return await resp.json();
		} catch ( e ) {
			return null;
		}
	}

	/** Zodiac → Unicode symbol map. */
	function getZodiacEmoji( zodiac ) {
		var map = {
			aries:       '♈',
			taurus:      '♉',
			gemini:      '♊',
			cancer:      '♋',
			leo:         '♌',
			virgo:       '♍',
			libra:       '♎',
			scorpio:     '♏',
			sagittarius: '♐',
			capricorn:   '♑',
			aquarius:    '♒',
			pisces:      '♓',
		};
		return map[ zodiac ] || '✨';
	}

	/** Capitalise first character of a string. */
	function capitalize( s ) {
		return s ? s[ 0 ].toUpperCase() + s.slice( 1 ) : '';
	}

	/* ─────────────────────────────────────────────
	   PANCHANG HELPERS
	───────────────────────────────────────────── */

	/**
	 * Defensively extracts a displayable string for a panchang field.
	 * Tries several common API response shapes.
	 * @param {object} data   Full panchang API response.
	 * @param {string} field  Key name (e.g. 'tithi').
	 * @returns {string|null}
	 */
	function extractPanchangField( data, field ) {
		if ( ! data || typeof data !== 'object' ) return null;

		var val = data[ field ];
		if ( val === undefined || val === null ) return null;

		// Plain string / number
		if ( typeof val === 'string' ) return val.trim() || null;
		if ( typeof val === 'number' ) return String( val );

		// Object — try common name-bearing keys
		if ( typeof val === 'object' ) {
			var candidate =
				val.name     ||
				val.details  ||
				val.en_name  ||
				val.full_name ||
				val.title    ||
				val.id       ||
				null;
			if ( candidate !== null ) return String( candidate ).trim() || null;

			// Some APIs return { tithi: { tithi_name: '...', ... } }
			var fieldNameKey = field + '_name';
			if ( val[ fieldNameKey ] ) return String( val[ fieldNameKey ] ).trim();

			// Fallback: first string-valued property
			var keys = Object.keys( val );
			for ( var i = 0; i < keys.length; i++ ) {
				if ( typeof val[ keys[ i ] ] === 'string' && val[ keys[ i ] ].trim() ) {
					return val[ keys[ i ] ].trim();
				}
			}
		}

		return null;
	}

	/**
	 * Collects festival / special-day names from a panchang API response.
	 * Returns a deduplicated array of strings (max 10 items).
	 * @param {object} data  Full panchang API response.
	 * @returns {string[]}
	 */
	function collectFestivals( data ) {
		if ( ! data || typeof data !== 'object' ) return [];

		var KEYS = [
			'special_day',
			'hindu_maah',
			'vrat_festival',
			'festivals',
			'important_festival',
			'festival',
			'vrat',
			'event',
		];

		var collected = [];

		KEYS.forEach( function( key ) {
			var val = data[ key ];
			if ( ! val ) return;

			// Plain non-empty string
			if ( typeof val === 'string' && val.trim() ) {
				collected.push( val.trim() );
				return;
			}

			// Array of strings or objects
			if ( Array.isArray( val ) ) {
				val.forEach( function( item ) {
					if ( ! item ) return;
					if ( typeof item === 'string' && item.trim() ) {
						collected.push( item.trim() );
					} else if ( typeof item === 'object' ) {
						var name = item.name || item.festival_name || item.title || item.en_name || '';
						if ( name && name.trim() ) collected.push( name.trim() );
					}
				} );
				return;
			}

			// Object with a name / festival_name property
			if ( typeof val === 'object' ) {
				var name = val.name || val.festival_name || val.title || val.en_name || '';
				if ( name && name.trim() ) {
					collected.push( name.trim() );
				} else {
					// Check if it's a map of events
					Object.values( val ).forEach( function( v ) {
						if ( typeof v === 'string' && v.trim() ) collected.push( v.trim() );
					} );
				}
			}
		} );

		// Deduplicate and cap at 10
		var seen = {};
		var unique = [];
		collected.forEach( function( f ) {
			var key = f.toLowerCase();
			if ( ! seen[ key ] ) {
				seen[ key ] = true;
				unique.push( f );
			}
		} );

		return unique.slice( 0, 10 );
	}

	/* ─────────────────────────────────────────────
	   MUHURAT HELPERS
	───────────────────────────────────────────── */

	/**
	 * Extracts choghadiya slots from a muhurat API response.
	 * Handles multiple response shapes and combines day + night arrays.
	 * @param {object} data  Muhurat API response.
	 * @returns {object[]}   Flat array of slot objects.
	 */
	function collectMuhuratSlots( data ) {
		if ( ! data || typeof data !== 'object' ) return [];

		// Unwrap a top-level 'data' envelope if present
		var root = ( data.data && typeof data.data === 'object' && ! Array.isArray( data.data ) )
			? data.data
			: data;

		// Candidates for day/night arrays
		var day   = root.day              || root.choghadiya_day   || root.Day   || [];
		var night = root.night            || root.choghadiya_night || root.Night || [];

		// If those are still empty, try a second unwrap level
		if ( ! day.length && ! night.length && root.data && typeof root.data === 'object' ) {
			day   = root.data.day              || root.data.choghadiya_day   || [];
			night = root.data.night            || root.data.choghadiya_night || [];
		}

		var slots = [].concat(
			Array.isArray( day )   ? day   : [],
			Array.isArray( night ) ? night : []
		);

		// Last resort: if root itself looks like a map of slot objects, extract values
		if ( slots.length === 0 ) {
			var values = Object.values( root );
			if ( values.length && typeof values[ 0 ] === 'object' && values[ 0 ] !== null ) {
				values.forEach( function( v ) {
					if ( typeof v === 'object' && ( v.name || v.muhurat_name ) ) {
						slots.push( v );
					}
				} );
			}
		}

		return slots;
	}

	/* ─────────────────────────────────────────────
	   RENDER FUNCTIONS
	───────────────────────────────────────────── */

	/**
	 * Fills #sas-panchang-grid with five info chips.
	 * @param {object} data  Panchang API response.
	 */
	function renderPanchang( data ) {
		var grid    = document.getElementById( 'sas-panchang-grid' );
		var loading = document.querySelector( '#sas-tab-panchang .sas-dash-loading' );
		if ( ! grid ) return;

		var fields = [
			{ label: 'Tithi',     icon: '🌙', value: extractPanchangField( data, 'tithi' ) },
			{ label: 'Nakshatra', icon: '⭐', value: extractPanchangField( data, 'nakshatra' ) },
			{ label: 'Yoga',      icon: '🔯', value: extractPanchangField( data, 'yoga' ) },
			{ label: 'Karana',    icon: '☀️', value: extractPanchangField( data, 'karana' ) },
			{ label: 'Vara',      icon: '📅', value: extractPanchangField( data, 'vara' ) },
		];

		grid.innerHTML = fields.map( function( f ) {
			return (
				'<div class="sas-panchang-chip">' +
					'<span class="sas-panchang-chip-icon">' + f.icon + '</span>' +
					'<span class="sas-panchang-chip-label">' + f.label + '</span>' +
					'<span class="sas-panchang-chip-value">' + ( f.value || '—' ) + '</span>' +
				'</div>'
			);
		} ).join( '' );

		if ( loading ) loading.hidden = true;
		grid.hidden = false;
	}

	/**
	 * Fills #sas-festivals-list from the panchang response.
	 * @param {object} data  Panchang API response.
	 */
	function renderFestivals( data ) {
		var list    = document.getElementById( 'sas-festivals-list' );
		var loading = document.querySelector( '#sas-tab-festivals .sas-dash-loading' );
		if ( ! list ) return;

		var festivals = collectFestivals( data );

		if ( festivals.length === 0 ) {
			list.innerHTML = '<p class="sas-dash-empty">No special festivals today. 🙏</p>';
		} else {
			list.innerHTML = festivals.map( function( f ) {
				return (
					'<div class="sas-festival-item">' +
						'<span class="sas-festival-icon">🪔</span>' +
						'<span class="sas-festival-name">' + f + '</span>' +
					'</div>'
				);
			} ).join( '' );
		}

		if ( loading ) loading.hidden = true;
		list.hidden = false;
	}

	/**
	 * Fills #sas-muhurat-list with choghadiya slots.
	 * @param {object|null} data  Muhurat API response (null on fetch error).
	 */
	function renderMuhurat( data ) {
		var list    = document.getElementById( 'sas-muhurat-list' );
		var loading = document.querySelector( '.sas-muhurat-loading' );
		if ( ! list ) return;

		if ( ! data ) {
			list.innerHTML = '<p class="sas-dash-empty">Muhurat data unavailable today.</p>';
			if ( loading ) loading.hidden = true;
			list.hidden = false;
			return;
		}

		var auspicious = [ 'amrit', 'shubh', 'labh', 'char' ];
		var slots = collectMuhuratSlots( data );

		if ( slots.length === 0 ) {
			list.innerHTML = '<p class="sas-dash-empty">No muhurat data available for today.</p>';
		} else {
			list.innerHTML = slots.map( function( slot ) {
				var name        = slot.name || slot.muhurat_name || 'Unknown';
				var isAuspicious = auspicious.indexOf( name.toLowerCase() ) !== -1;
				var typeClass   = isAuspicious ? 'auspicious' : 'inauspicious';
				var typeIcon    = isAuspicious ? '✅' : '⚠️';
				var startTime   = slot.start_time || slot.start || '';
				var endTime     = slot.end_time   || slot.end   || '';
				return (
					'<div class="sas-muhurat-slot ' + typeClass + '">' +
						'<div class="sas-muhurat-name">' + typeIcon + ' ' + name + '</div>' +
						'<div class="sas-muhurat-time">' + startTime + ' – ' + endTime + '</div>' +
					'</div>'
				);
			} ).join( '' );
		}

		if ( loading ) loading.hidden = true;
		list.hidden = false;
	}

	/* ─────────────────────────────────────────────
	   DATA LOADERS
	───────────────────────────────────────────── */

	/**
	 * Fetches panchang for today (IST) and populates panchang + festivals panels.
	 */
	async function loadPanchang() {
		var today = getTodayIST();
		var url   = sasConfig.restBase + '/panchang?date=' + today;
		var data  = await apiFetch( url );
		if ( ! data ) {
			// Show error state in both panels
			var panchangGrid = document.getElementById( 'sas-panchang-grid' );
			var festivalList = document.getElementById( 'sas-festivals-list' );
			var panchangLoading = document.querySelector( '#sas-tab-panchang .sas-dash-loading' );
			var festivalLoading = document.querySelector( '#sas-tab-festivals .sas-dash-loading' );

			if ( panchangGrid ) {
				panchangGrid.innerHTML = '<p class="sas-dash-error">Could not load panchang data. Please refresh.</p>';
				if ( panchangLoading ) panchangLoading.hidden = true;
				panchangGrid.hidden = false;
			}
			if ( festivalList ) {
				festivalList.innerHTML = '<p class="sas-dash-error">Could not load festival data. Please refresh.</p>';
				if ( festivalLoading ) festivalLoading.hidden = true;
				festivalList.hidden = false;
			}
			return;
		}

		// Unwrap common envelope shapes: { status, data: {...} } or { response: {...} }
		var payload = data;
		if ( data.data && typeof data.data === 'object' && ! Array.isArray( data.data ) ) {
			payload = data.data;
		} else if ( data.response && typeof data.response === 'object' ) {
			payload = data.response;
		}

		renderPanchang( payload );
		renderFestivals( payload );
	}

	/**
	 * Fetches choghadiya for today (IST) and populates the muhurat/times panel.
	 */
	async function loadMuhurat() {
		var today = getTodayIST();
		var url   = sasConfig.restBase + '/muhurat?date=' + today;
		var data  = await apiFetch( url );
		renderMuhurat( data );
	}

	/**
	 * Fetches a zodiac prediction and populates the zodiac panel.
	 * All three params are optional — falls back to current DOM state.
	 * @param {string=} zodiac
	 * @param {string=} lang
	 * @param {string=} cycle
	 */
	async function loadZodiac( zodiac, lang, cycle ) {
		var zodiacEl = document.getElementById( 'sas-zodiac-select' );
		var langEl   = document.getElementById( 'sas-lang-select' );
		var cycleEl  = document.querySelector( '.sas-cycle-btn--active' );

		zodiac = zodiac || ( zodiacEl && zodiacEl.value )   || '';
		lang   = lang   || ( langEl   && langEl.value )     || 'en';
		cycle  = cycle  || ( cycleEl  && cycleEl.dataset.cycle ) || 'daily';

		var result = document.getElementById( 'sas-zodiac-result' );
		if ( ! result ) return;

		if ( ! zodiac ) {
			result.innerHTML = '<p class="sas-dash-empty">Select your zodiac sign above to see your reading. 🔮</p>';
			return;
		}

		result.innerHTML = '<div class="sas-dash-loading"><span class="sas-spinner"></span> Loading prediction…</div>';

		var url  = sasConfig.restBase + '/predictions?zodiac=' + zodiac + '&cycle=' + cycle + '&lang=' + lang;
		var data = await apiFetch( url );

		if ( ! data || ! data.data ) {
			result.innerHTML = '<p class="sas-dash-error">Could not load prediction. Please try again.</p>';
			return;
		}

		// API shape: { status, source, data: { prediction_text | bot_response | response | <string> } }
		var text =
			data.data.prediction_text ||
			data.data.bot_response    ||
			data.data.response        ||
			( typeof data.data === 'string' ? data.data : null );

		if ( ! text ) {
			result.innerHTML = '<p class="sas-dash-empty">No prediction available for this selection.</p>';
			return;
		}

		result.innerHTML =
			'<div class="sas-zodiac-card">' +
				'<div class="sas-zodiac-sign-badge">' + getZodiacEmoji( zodiac ) + ' ' + capitalize( zodiac ) + '</div>' +
				'<div class="sas-zodiac-prediction-text">' + text + '</div>' +
			'</div>';
	}

	/* ─────────────────────────────────────────────
	   TAB SWITCHING
	───────────────────────────────────────────── */

	/**
	 * Activates a tab by its data-tab value.
	 * @param {string} tabName  e.g. 'panchang'
	 */
	function activateTab( tabName ) {
		// Update tab buttons
		document.querySelectorAll( '.sas-dash-tab' ).forEach( function( btn ) {
			var isActive = btn.dataset.tab === tabName;
			btn.classList.toggle( 'sas-dash-tab--active', isActive );
			btn.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
		} );

		// Update panels
		document.querySelectorAll( '.sas-dash-panel' ).forEach( function( panel ) {
			if ( panel.id === 'sas-tab-' + tabName ) {
				panel.removeAttribute( 'hidden' );
			} else {
				panel.setAttribute( 'hidden', '' );
			}
		} );
	}

	function initTabs() {
		document.querySelectorAll( '.sas-dash-tab' ).forEach( function( btn ) {
			btn.addEventListener( 'click', function() {
				activateTab( btn.dataset.tab );
			} );
		} );
	}

	/* ─────────────────────────────────────────────
	   EVENT LISTENERS
	───────────────────────────────────────────── */

	function initZodiacControls() {
		var zodiacSel = document.getElementById( 'sas-zodiac-select' );
		var langSel   = document.getElementById( 'sas-lang-select' );

		if ( zodiacSel ) {
			zodiacSel.addEventListener( 'change', function() {
				loadZodiac();
			} );
		}

		if ( langSel ) {
			langSel.addEventListener( 'change', function() {
				loadZodiac();
			} );
		}

		document.querySelectorAll( '.sas-cycle-btn' ).forEach( function( btn ) {
			btn.addEventListener( 'click', function() {
				// Update active button
				document.querySelectorAll( '.sas-cycle-btn' ).forEach( function( b ) {
					b.classList.remove( 'sas-cycle-btn--active' );
				} );
				btn.classList.add( 'sas-cycle-btn--active' );

				loadZodiac();
			} );
		} );
	}

	/* ─────────────────────────────────────────────
	   INITIALISATION
	───────────────────────────────────────────── */

	function init() {
		// Safety guard — ensure sasConfig is available
		if ( typeof sasConfig === 'undefined' || ! sasConfig.restBase ) {
			return;
		}

		// 1. Fill today's date display
		var dateEl = document.getElementById( 'sas-today-date' );
		if ( dateEl ) {
			dateEl.textContent = getFormattedDateIST();
		}

		// 2. Set up tab switching
		initTabs();

		// 3. Pre-select zodiac from user's account if available
		var zodiacSel = document.getElementById( 'sas-zodiac-select' );
		if ( zodiacSel && sasConfig.userZodiac ) {
			zodiacSel.value = sasConfig.userZodiac;
		}

		// 4. Set language dropdown to site default
		var langSel = document.getElementById( 'sas-lang-select' );
		if ( langSel && sasConfig.defaultLang ) {
			langSel.value = sasConfig.defaultLang;
		}

		// 5. Attach event listeners for zodiac controls
		initZodiacControls();

		// 6. Load panchang (fills panchang tab + festivals tab)
		loadPanchang();

		// 7. Load muhurat (fills times tab)
		loadMuhurat();

		// 8. Load zodiac prediction if a zodiac is already selected
		if ( sasConfig.userZodiac || ( zodiacSel && zodiacSel.value ) ) {
			loadZodiac();
		} else {
			// Show prompt to select zodiac
			var result = document.getElementById( 'sas-zodiac-result' );
			if ( result ) {
				result.innerHTML = '<p class="sas-dash-empty">Select your zodiac sign above to see your reading. 🔮</p>';
			}
		}
	}

	// Kick off after the DOM is fully parsed
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		// DOM already ready (script loaded with defer/async and DOM is parsed)
		init();
	}

})();
