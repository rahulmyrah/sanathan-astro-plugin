/**
 * Kundali Birth Profile Form  —  sas-kundali-form.js
 * v1.5.0  |  Sanathan Astro Services
 *
 * Handles:
 *  - Location autocomplete (debounced fetch to /util/location-search)
 *  - "I don't know exact time" checkbox
 *  - Form submission (create via POST /kundali or edit via PUT /kundali/birth-profile)
 *  - Upgrade-required modal on 403 response
 *  - Edit panel toggle
 */

(function () {
    'use strict';

    /* ── DOM refs ────────────────────────────────────────────── */
    const form       = document.getElementById('sas-kf-form');
    const submitBtn  = document.getElementById('sas-kf-submit');
    const msgEl      = document.getElementById('sas-kf-msg');
    const locInput   = document.getElementById('sas-kf-location');
    const locDrop    = document.getElementById('sas-kf-location-dropdown');
    const latInput   = document.getElementById('sas-kf-lat');
    const lonInput   = document.getElementById('sas-kf-lon');
    const tzInput    = document.getElementById('sas-kf-tz');
    const tobInput   = document.getElementById('sas-kf-tob');
    const tobUnknown = document.getElementById('sas-kf-tob-unknown');
    const editToggle = document.getElementById('sas-kf-edit-toggle');
    const editPanel  = document.getElementById('sas-kf-edit-panel');

    /* ── Helpers ─────────────────────────────────────────────── */
    function showMsg(type, text) {
        if (!msgEl) return;
        msgEl.className = 'sas-kf-msg sas-kf-msg--' + type;
        msgEl.textContent = text;
        msgEl.style.display = 'block';
    }

    function hideMsg() {
        if (msgEl) { msgEl.style.display = 'none'; }
    }

    function setLoading(on) {
        if (!submitBtn) return;
        submitBtn.disabled = on;
        if (on) {
            submitBtn.dataset.origText = submitBtn.textContent;
            submitBtn.textContent = 'Processing\u2026';
        } else {
            submitBtn.textContent = submitBtn.dataset.origText || submitBtn.textContent;
        }
    }

    /* ── Edit panel toggle ───────────────────────────────────── */
    if (editToggle && editPanel) {
        editToggle.addEventListener('click', function () {
            var isOpen = !editPanel.hasAttribute('hidden');
            if (isOpen) {
                editPanel.setAttribute('hidden', '');
                editToggle.textContent = '\u270F\uFE0F Edit Birth Details';
            } else {
                editPanel.removeAttribute('hidden');
                editToggle.textContent = '\u25B2 Close Edit Panel';
            }
        });
    }

    /* ── "Don't know time" checkbox ──────────────────────────── */
    if (tobUnknown && tobInput) {
        tobUnknown.addEventListener('change', function () {
            if (this.checked) {
                tobInput.value    = '12:00';
                tobInput.disabled = true;
            } else {
                tobInput.disabled = false;
                tobInput.value    = '';
            }
        });
    }

    /* ── Location autocomplete ───────────────────────────────── */
    var debounceTimer = null;

    if (locInput && locDrop) {
        locInput.addEventListener('keyup', function () {
            var q = this.value.trim();
            clearTimeout(debounceTimer);
            if (q.length < 3) {
                closeDropdown();
                return;
            }
            debounceTimer = setTimeout(function () { fetchLocations(q); }, 320);
        });

        locInput.addEventListener('blur', function () {
            // Delay so click on dropdown item fires first
            setTimeout(closeDropdown, 200);
        });
    }

    function fetchLocations(q) {
        fetch(sasKF.restUrl + 'util/location-search?q=' + encodeURIComponent(q))
            .then(function (r) { return r.json(); })
            .then(renderDropdown)
            .catch(function () { closeDropdown(); });
    }

    function renderDropdown(locations) {
        if (!locDrop) return;
        locDrop.innerHTML = '';

        if (!Array.isArray(locations) || locations.length === 0) {
            locDrop.style.display = 'none';
            return;
        }

        locations.forEach(function (loc) {
            var item = document.createElement('div');
            item.className = 'sas-kf-dropdown-item';
            item.textContent = loc.name;
            item.setAttribute('role', 'option');
            item.addEventListener('mousedown', function (e) {
                // Use mousedown so it fires before blur
                e.preventDefault();
                selectLocation(loc);
            });
            locDrop.appendChild(item);
        });

        locDrop.style.display = 'block';
    }

    function selectLocation(loc) {
        if (locInput)  locInput.value  = loc.name;
        if (latInput)  latInput.value  = loc.lat;
        if (lonInput)  lonInput.value  = loc.lon;
        if (tzInput)   tzInput.value   = loc.tz;
        closeDropdown();
    }

    function closeDropdown() {
        if (locDrop) {
            locDrop.style.display = 'none';
            locDrop.innerHTML     = '';
        }
    }

    // Close on outside click
    document.addEventListener('click', function (e) {
        if (locInput && locDrop && !locInput.contains(e.target) && !locDrop.contains(e.target)) {
            closeDropdown();
        }
    });

    /* ── Form submission ─────────────────────────────────────── */
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            handleSubmit();
        });
    }

    function handleSubmit() {
        var mode     = form ? (form.dataset.mode || 'create') : 'create';
        var nameVal  = document.getElementById('sas-kf-name')     ? document.getElementById('sas-kf-name').value.trim()     : '';
        var dobVal   = document.getElementById('sas-kf-dob')      ? document.getElementById('sas-kf-dob').value              : '';
        var tobVal   = tobInput ? (tobInput.value || '12:00')                                                                 : '12:00';
        var locVal   = locInput ? locInput.value.trim()                                                                       : '';
        var latVal   = latInput ? latInput.value                                                                               : '';
        var lonVal   = lonInput ? lonInput.value                                                                               : '';
        var tzVal    = tzInput  ? tzInput.value                                                                                : '';

        hideMsg();

        /* Client-side validation */
        if (!nameVal) {
            showMsg('error', 'Please enter your full name.');
            return;
        }
        if (!dobVal) {
            showMsg('error', 'Please select your date of birth.');
            return;
        }
        if (!locVal) {
            showMsg('error', 'Please enter your place of birth.');
            return;
        }
        if (!latVal || !lonVal) {
            showMsg('error', 'Please select a location from the dropdown suggestions so we can get exact coordinates.');
            return;
        }

        /* Convert Y-m-d (HTML date input) to d/m/Y (API format) */
        var parts = dobVal.split('-');
        var dobFormatted = parts[2] + '/' + parts[1] + '/' + parts[0];

        var body = {
            name:          nameVal,
            dob:           dobFormatted,
            tob:           tobVal,
            location_name: locVal,
            lat:           parseFloat(latVal),
            lon:           parseFloat(lonVal),
            tz:            parseFloat(tzVal)
        };

        var url, method;
        if (mode === 'create') {
            url    = sasKF.restUrl + 'kundali';
            method = 'POST';
            body.lang = 'en';
        } else {
            url    = sasKF.restUrl + 'kundali/birth-profile';
            method = 'PUT';
        }

        setLoading(true);
        showMsg('info', '\u23F3 Generating your Kundali, please wait\u2026');

        fetch(url, {
            method:  method,
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   sasKF.nonce
            },
            body: JSON.stringify(body)
        })
        .then(function (res) {
            return res.json().then(function (data) {
                return { ok: res.ok, status: res.status, data: data };
            });
        })
        .then(function (result) {
            setLoading(false);

            if (!result.ok) {
                var errData = result.data;
                /* Upgrade required */
                if (
                    errData.code === 'upgrade_required' ||
                    (errData.data && errData.data.code === 'upgrade_required')
                ) {
                    showUpgradeModal();
                    return;
                }
                var msg = errData.message || 'Something went wrong. Please try again.';
                showMsg('error', '\u274C ' + msg);
                return;
            }

            showMsg('success', '\u2705 Success! Loading your Kundali\u2026');
            setTimeout(function () { window.location.reload(); }, 1400);
        })
        .catch(function (err) {
            setLoading(false);
            showMsg('error', '\u274C Network error. Please check your connection and try again.');
        });
    }

    /* ── Upgrade required modal ──────────────────────────────── */
    function showUpgradeModal() {
        /* Remove any existing modal */
        var existing = document.querySelector('.sas-kf-modal-overlay');
        if (existing) { existing.remove(); }

        var overlay = document.createElement('div');
        overlay.className = 'sas-kf-modal-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');

        overlay.innerHTML =
            '<div class="sas-kf-modal">' +
                '<h3>\uD83D\uDD12 Premium Required</h3>' +
                '<p>You have used your free edit. Upgrade to Premium to change your birth details again and unlock the full Kundali analysis.</p>' +
                '<div class="sas-kf-modal-actions">' +
                    '<a href="' + sasKF.upgradeUrl + '" class="sas-kf-btn sas-kf-btn--upgrade">\u2728 Upgrade to Premium</a>' +
                    '<button type="button" class="sas-kf-btn sas-kf-btn--ghost sas-kf-modal-close">Cancel</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(overlay);

        /* Close on cancel or overlay click */
        overlay.querySelector('.sas-kf-modal-close').addEventListener('click', function () {
            overlay.remove();
        });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { overlay.remove(); }
        });
    }

})();
