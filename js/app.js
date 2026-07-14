/* ============================================================
   Pickledraw - app.js
   ============================================================ */

(function () {
    'use strict';

    // ── File Drop Zone ────────────────────────────────────────────────────────
    const dropZone  = document.getElementById('dropZone');
    const fileInput = document.getElementById('csvFile');

    if (dropZone) {
        ['dragenter', 'dragover'].forEach(evt => {
            dropZone.addEventListener(evt, e => {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });
        });
        ['dragleave', 'drop'].forEach(evt => {
            dropZone.addEventListener(evt, () => dropZone.classList.remove('dragover'));
        });
        dropZone.addEventListener('drop', e => {
            e.preventDefault();
            const file = e.dataTransfer?.files[0];
            if (file && fileInput) {
                const dt = new DataTransfer();
                dt.items.add(file);
                fileInput.files = dt.files;
                updateFileLabel(file.name);
            }
        });
        if (fileInput) {
            fileInput.addEventListener('change', () => {
                if (fileInput.files[0]) updateFileLabel(fileInput.files[0].name);
            });
        }
    }

    function updateFileLabel(name) {
        const content = dropZone?.querySelector('.file-drop-content');
        if (content) {
            content.querySelector('.file-text').innerHTML = `<strong>${escapeHtml(name)}</strong> ready to import`;
            content.querySelector('.file-icon').textContent = '✓';
        }
    }

    // ── Sample Data ───────────────────────────────────────────────────────────
    window.loadSample = function () {
        const textarea = document.querySelector('textarea[name="csv_text"]');
        if (!textarea) return;

        const sample = `Date Created,Order ID,Purchaser User ID,Attendee ID,Attendee First Name,Attendee Last Name,Status,Ticket Class,Ticket Class Price,Is Guest,Checked in,Checked in Date,Name of partner(s),I am registered to play in a tournament at this level.
2024-03-01,ORD001,USR001,ATT001,Olivia,Young,Attending,Mixed Doubles,55.00,No,Yes,2024-03-15 09:05,Peter Adams,5.0
2024-03-01,ORD002,USR002,ATT002,Peter,Adams,Attending,Mixed Doubles,55.00,No,Yes,2024-03-15 09:07,Olivia Young,5.0
2024-03-02,ORD003,USR003,ATT003,Alice,Thompson,Attending,Mixed Doubles,55.00,No,No,,Bob Clarke,3.5
2024-03-02,ORD004,USR004,ATT004,Bob,Clarke,Attending,Mixed Doubles,55.00,No,No,,Alice Thompson,3.5
2024-03-03,ORD005,USR005,ATT005,Carol,Wright,Attending,Mixed Doubles,55.00,No,Yes,2024-03-15 08:50,Dan Fisher,4.0
2024-03-03,ORD006,USR006,ATT006,Dan,Fisher,Attending,Mixed Doubles,55.00,No,Yes,2024-03-15 08:52,Carol Wright,4.0
2024-03-04,ORD007,USR007,ATT007,Emma,Davis,Attending,Mixed Doubles,55.00,No,No,,Frank Hall,4.5
2024-03-04,ORD008,USR008,ATT008,Frank,Hall,Attending,Mixed Doubles,55.00,No,No,,Emma Davis,4.5
2024-03-05,ORD009,USR009,ATT009,Grace,Allen,Attending,Mixed Doubles,55.00,No,Yes,2024-03-15 09:10,Henry Baker,2.5
2024-03-05,ORD010,USR010,ATT010,Henry,Baker,Attending,Mixed Doubles,55.00,No,Yes,2024-03-15 09:12,Grace Allen,2.5
2024-03-06,ORD011,USR011,ATT011,Isla,Morris,Attending,Women's Doubles,55.00,No,No,,Jack Turner,3.5
2024-03-06,ORD012,USR012,ATT012,Jack,Turner,Attending,Men's Doubles,55.00,No,No,,Isla Morris,3.5
2024-03-07,ORD013,USR013,ATT013,Karen,White,Attending,Mixed Doubles,55.00,No,Yes,2024-03-15 09:20,,4.0
2024-03-07,ORD014,USR014,ATT014,Leo,King,Attending,Mixed Doubles,55.00,No,No,,Karen White,4.0
2024-03-08,ORD015,USR015,ATT015,Mia,Scott,Attending,Mixed Doubles,55.00,No,No,,Noel Reed,5.5
2024-03-08,ORD016,USR016,ATT016,Noel,Reed,Attending,Mixed Doubles,55.00,No,No,,Mia Scott,5.5
2024-03-09,ORD017,USR017,ATT017,Quinn,Carter,Attending,Mixed Doubles,55.00,No,Yes,2024-03-15 08:45,Rose Evans,3.0
2024-03-09,ORD018,USR018,ATT018,Rose,Evans,Attending,Mixed Doubles,55.00,No,Yes,2024-03-15 08:46,Quinn Carter,3.0
2024-03-10,ORD019,USR019,ATT019,Sam,Mitchell,Attending,Mixed Doubles,55.00,No,No,,,2.5
2024-03-10,ORD020,USR020,ATT020,Tina,Nelson,Attending,Mixed Doubles,55.00,No,No,,,2.5`;

        textarea.value = sample;
        textarea.style.borderColor = 'var(--accent)';
        setTimeout(() => { textarea.style.borderColor = ''; }, 800);
    };

    // ── Partner Select Dropdowns ──────────────────────────────────────────────
    //
    // ATTENDEES is injected by PHP as a global JS array:
    //   [{ idx, name, skill_raw, partner_matched }, ...]
    //
    // State: tracks which player each dropdown has selected so we can
    // show visual conflict warnings when the same person is picked twice.

    const selectedPartners = {}; // playerIdx -> partnerIdx string | 'auto'

    // Initialise state from existing selects on page load
    document.querySelectorAll('.partner-select').forEach(sel => {
        const playerIdx = sel.dataset.playerIdx;
        selectedPartners[playerIdx] = sel.value;
        applySelectStyle(sel);
    });

    // Called when any partner dropdown changes
    window.onPartnerChange = function (selectEl) {
        const playerIdx  = selectEl.dataset.playerIdx;
        const chosenIdx  = selectEl.value;

        selectedPartners[playerIdx] = chosenIdx;
        applySelectStyle(selectEl);

        // If a real person was chosen, mirror the selection on THEIR row too
        // (only if their row is still an unmatched dropdown, not a confirmed pair)
        if (chosenIdx !== 'auto' && chosenIdx !== '') {
            const mirrorSel = document.getElementById(`partner_select_${chosenIdx}`);
            if (mirrorSel) {
                // Set partner's dropdown to point back at this player
                mirrorSel.value = playerIdx;
                selectedPartners[chosenIdx] = playerIdx;
                applySelectStyle(mirrorSel);
                flashRow(mirrorSel.closest('tr'), 'flash-linked');
            }
        }

        // Warn about conflicts: highlight any other dropdown that also chose chosenIdx
        highlightConflicts();
        flashRow(selectEl.closest('tr'), 'flash-linked');
    };

    function applySelectStyle(selectEl) {
        const isAuto = selectEl.value === 'auto' || selectEl.value === '';
        selectEl.classList.toggle('has-selection', !isAuto);
    }

    function highlightConflicts() {
        // Build a frequency map of chosen partner indices
        const freq = {};
        Object.entries(selectedPartners).forEach(([pIdx, chosen]) => {
            if (chosen === 'auto' || chosen === '') return;
            // Don't count mirrored pairs as conflicts — a↔b is fine
            freq[chosen] = (freq[chosen] || []);
            freq[chosen].push(pIdx);
        });

        document.querySelectorAll('.partner-select').forEach(sel => {
            const chosen = sel.value;
            if (chosen === 'auto' || chosen === '') {
                sel.classList.remove('conflict');
                return;
            }
            const choosers = freq[chosen] || [];
            // Conflict if more than one non-mirrored player chose the same partner
            // Mirror: playerA chose playerB AND playerB chose playerA → not a conflict
            const isMirror = choosers.length === 1 ||
                (choosers.length === 2 &&
                    choosers.includes(chosen) &&
                    choosers.includes(sel.dataset.playerIdx));
            sel.classList.toggle('conflict', !isMirror && choosers.length > 1);
        });
    }

    // ── Unlink a confirmed pair ───────────────────────────────────────────────
    // Called by the ✕ button on confirmed pairing rows.
    // Replaces the static "Paired with X" display with a dropdown for both players,
    // without a full page reload (client-side only — the form POST will persist changes).
    window.unlinkPlayer = function (playerIdx) {
        const row = document.querySelector(`tr[data-player-idx="${playerIdx}"]`);
        if (!row) return;

        const pairingCell = row.querySelector('.td-pairing');
        if (!pairingCell) return;

        // Read hidden input for partner idx
        const hiddenInput = document.getElementById(`partner_input_${playerIdx}`);
        const partnerIdx  = hiddenInput ? hiddenInput.value : 'auto';

        // Replace confirmed display with a dropdown
        pairingCell.innerHTML = buildSelectHTML(playerIdx, 'auto');
        row.classList.remove('row-matched');
        row.classList.add('row-unmatched');

        // Re-initialise the new select
        const newSel = pairingCell.querySelector('.partner-select');
        if (newSel) {
            selectedPartners[playerIdx] = 'auto';
            applySelectStyle(newSel);
        }

        // Also unlink the partner's row if they're confirmed
        if (partnerIdx && partnerIdx !== 'auto') {
            const partnerRow = document.querySelector(`tr[data-player-idx="${partnerIdx}"]`);
            if (partnerRow) {
                const partnerCell = partnerRow.querySelector('.td-pairing');
                if (partnerCell && partnerCell.querySelector('.pairing-confirmed')) {
                    partnerCell.innerHTML = buildSelectHTML(partnerIdx, 'auto');
                    partnerRow.classList.remove('row-matched');
                    partnerRow.classList.add('row-unmatched');
                    const partnerSel = partnerCell.querySelector('.partner-select');
                    if (partnerSel) {
                        selectedPartners[partnerIdx] = 'auto';
                        applySelectStyle(partnerSel);
                    }
                }
            }
        }
    };

    function buildSelectHTML(playerIdx, selectedValue) {
        if (typeof ATTENDEES === 'undefined') return '';

        const options = ATTENDEES.map(a => {
            if (a.idx === parseInt(playerIdx)) return '';
            const sel   = String(a.idx) === String(selectedValue) ? ' selected' : '';
            const label = `${escapeHtml(a.name)} (${escapeHtml(a.skill_raw)})${a.partner_matched ? ' ✓paired' : ''}`;
            return `<option value="${a.idx}"${sel}>${label}</option>`;
        }).join('');

        return `
            <div class="pairing-select-wrap">
                <span class="status-dot unmatched"></span>
                <select name="manual_partner[${playerIdx}]"
                        id="partner_select_${playerIdx}"
                        class="partner-select"
                        data-player-idx="${playerIdx}"
                        onchange="onPartnerChange(this)">
                    <option value="auto">⟳ Auto-pair by skill</option>
                    <optgroup label="── Select a partner ──">
                        ${options}
                    </optgroup>
                </select>
            </div>`;
    }

    // ── Row flash animation ───────────────────────────────────────────────────
    function flashRow(row, cls) {
        if (!row) return;
        row.classList.add(cls);
        setTimeout(() => row.classList.remove(cls), 600);
    }

    // ── Score input auto-format ───────────────────────────────────────────────
    document.querySelectorAll('.score-input').forEach(input => {
        input.addEventListener('blur', () => {
            const val   = input.value.trim();
            const match = val.match(/^(\d+)\s*[-:]\s*(\d+)$/);
            if (match) {
                input.value = `${match[1]} - ${match[2]}`;
                input.style.color = '#3ddc84';
            }
        });
    });

    // ── Division select: update border colour and AJAX-save on change ──────────
    window.onDivisionChange = function (selectEl) {
        const bandClasses = ['division-band-4p','division-band-35','division-band-30','division-band-25','division-band-u25'];
        bandClasses.forEach(c => selectEl.classList.remove(c));
        if (selectEl.value) selectEl.classList.add('division-' + selectEl.value);
        selectEl.classList.add('division-manual');

        // Update source indicator
        const srcSpan = selectEl.closest('td')?.querySelector('.division-src');
        if (srcSpan) srcSpan.textContent = '✎';

        // Mirror into ATTENDEES for partner dropdown labels
        const row       = selectEl.closest('tr');
        const playerIdx = row ? parseInt(row.dataset.playerIdx) : null;
        if (playerIdx !== null && typeof ATTENDEES !== 'undefined') {
            const a = ATTENDEES.find(x => x.idx === playerIdx);
            if (a) a.skill_band = selectEl.value;
        }

        // AJAX save — does not depend on the main form
        saveField(playerIdx, 'division', selectEl.value, selectEl);
    };

    // ── DUPR input: style when a value is entered, AJAX-save on change ─────────
    window.onDuprChange = function (inputEl) {
        const val     = inputEl.value.trim();
        const isValid = val !== '' && !isNaN(parseFloat(val)) && parseFloat(val) > 0;
        inputEl.classList.toggle('has-value', isValid);
        inputEl.style.borderColor = (!isValid && val !== '') ? 'var(--red)' : '';

        const row       = inputEl.closest('tr');
        const playerIdx = row ? parseInt(row.dataset.playerIdx) : null;

        // Mirror into ATTENDEES
        if (playerIdx !== null && typeof ATTENDEES !== 'undefined') {
            const a = ATTENDEES.find(x => x.idx === playerIdx);
            if (a) a.dupr = isValid ? parseFloat(val) : null;
        }

        // Only save valid values (or intentionally blank ones)
        if (isValid || val === '') {
            saveField(playerIdx, 'dupr', val, inputEl);
        }
    };

    // ── Generic AJAX field save ────────────────────────────────────────────────
    function saveField(playerIdx, field, value, el) {
        if (playerIdx === null || playerIdx < 0) return;

        const body = new URLSearchParams({
            action: 'save_field',
            idx:    playerIdx,
            field:  field,
            value:  value,
        });

        fetch('index.php', { method: 'POST', body })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) {
                    console.warn('Field save failed:', data.error);
                }
            })
            .catch(err => {
                // Non-critical — the main form will still carry the value
                console.warn('Field save error:', err);
            });
    }

    // Initialise division selects on load (apply colour classes)
    document.querySelectorAll('.division-select').forEach(sel => {
        const band = sel.value;
        if (band) sel.classList.add('division-' + band);
        if (sel.closest('tr')?.querySelector('.division-src')?.textContent.trim() === '✎') {
            sel.classList.add('division-manual');
        }
    });

    // Initialise DUPR inputs on load
    document.querySelectorAll('.dupr-input').forEach(inp => {
        const val = inp.value.trim();
        if (val !== '' && !isNaN(parseFloat(val)) && parseFloat(val) > 0) {
            inp.classList.add('has-value');
        }
    });

    // ── Add Player Modal ──────────────────────────────────────────────────────
    const modal     = document.getElementById('addPlayerModal');
    const modalForm = document.getElementById('addPlayerForm');

    window.openAddPlayerModal = function () {
        if (!modal) return;
        modal.classList.add('open');
        // Focus first input
        setTimeout(() => document.getElementById('new_first_name')?.focus(), 50);
    };

    window.closeAddPlayerModal = function () {
        if (!modal) return;
        modal.classList.remove('open');
        // Clear form
        if (modalForm) {
            modalForm.reset();
            const errEl = document.getElementById('addPlayerError');
            if (errEl) errEl.style.display = 'none';
        }
    };

    // Close on overlay click
    if (modal) {
        modal.addEventListener('click', e => {
            if (e.target === modal) closeAddPlayerModal();
        });
    }

    // Close on Escape key
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && modal?.classList.contains('open')) {
            closeAddPlayerModal();
        }
    });

    // Client-side validation before submit
    if (modalForm) {
        modalForm.addEventListener('submit', e => {
            const first  = document.getElementById('new_first_name')?.value.trim() || '';
            const last   = document.getElementById('new_last_name')?.value.trim()  || '';
            const duprEl = document.getElementById('new_dupr');
            const duprVal = duprEl?.value.trim() || '';

            const errEl = document.getElementById('addPlayerError');

            if (!first && !last) {
                e.preventDefault();
                if (errEl) { errEl.textContent = 'Please enter at least a first or last name.'; errEl.style.display = 'block'; }
                document.getElementById('new_first_name')?.focus();
                return;
            }

            if (duprVal !== '' && (isNaN(parseFloat(duprVal)) || parseFloat(duprVal) <= 0)) {
                e.preventDefault();
                if (errEl) { errEl.textContent = 'DUPR must be a positive number (e.g. 3.42).'; errEl.style.display = 'block'; }
                duprEl?.focus();
                return;
            }

            if (errEl) errEl.style.display = 'none';
            // Allow submit — page will reload with new player in table
        });
    }

    // Sync division select border colour in modal
    window.syncModalDivision = function (selectEl) {
        const bandClasses = ['division-band-4p','division-band-35','division-band-30','division-band-25','division-band-u25'];
        bandClasses.forEach(c => selectEl.classList.remove(c));
        if (selectEl.value) selectEl.classList.add('division-' + selectEl.value);
    };

    // ── Delete Player ─────────────────────────────────────────────────────────
    window.confirmDeletePlayer = function (playerIdx, playerName) {
        // Animate the row out, then submit delete form
        const row = document.querySelector(`tr[data-player-idx="${playerIdx}"]`);

        const doDelete = () => {
            const form  = document.getElementById('deletePlayerForm');
            const input = document.getElementById('deletePlayerIdx');
            if (form && input) {
                // Save current pairing form state first so it isn't lost
                input.value = playerIdx;
                form.submit();
            }
        };

        if (row) {
            row.classList.add('row-deleting');
            setTimeout(doDelete, 340);
        } else {
            doDelete();
        }
    };

    // ── Highlight newly-added row on page load ────────────────────────────────
    const newRow = document.querySelector('tr[data-manual="1"]:last-child');
    if (newRow) {
        newRow.classList.add('row-new');
        setTimeout(() => {
            newRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 100);
    }

    // ── Auto-scroll logic ─────────────────────────────────────────────────────
    // Priority order:
    //   1. After successful save_pairings → scroll to Configure Draw (step-settings)
    //   2. After generating draw → scroll to draw output (step-draw)
    //   3. After add/delete player → scroll table into view
    const drawSection     = document.getElementById('step-draw');
    const configSection   = document.getElementById('step-settings');
    const scrollToCfg     = (typeof SCROLL_TO_CONFIGURE !== 'undefined') && SCROLL_TO_CONFIGURE;

    if (scrollToCfg && configSection) {
        setTimeout(() => {
            configSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 200);
    } else if (drawSection) {
        setTimeout(() => {
            drawSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 250);
    } else {
        const table = document.getElementById('attendeeTable');
        if (table && (document.referrer.includes('index.php') || performance?.navigation?.type === 1)) {
            setTimeout(() => table.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 100);
        }
    }

    // ── Court number inputs ───────────────────────────────────────────────────
    // Court inputs in match cards are editable. Changes are highlighted amber.
    // Double-clicking resets to the generated default.
    // Values survive within the page session via a Map keyed by input element.

    function initCourtInputs() {
        document.querySelectorAll('.court-input').forEach(input => {
            const defaultVal = input.dataset.default || input.value;

            // Mark as modified if value differs from default on init
            // (e.g. after a page reload with saved state — not applicable here but defensive)
            if (input.value !== defaultVal) {
                input.classList.add('court-modified');
            }

            input.addEventListener('input', () => {
                const changed = input.value.trim() !== defaultVal.trim();
                input.classList.toggle('court-modified', changed);
            });

            // Double-click to reset to generated default
            input.addEventListener('dblclick', () => {
                input.value = defaultVal;
                input.classList.remove('court-modified');
                // Brief flash to confirm reset
                input.style.transition = 'color 0.15s';
                input.style.color = 'var(--green)';
                setTimeout(() => { input.style.color = ''; }, 400);
            });

            // Select all text on focus for easy overtyping
            input.addEventListener('focus', () => {
                input.select();
            });

            // On blur, clean up empty values
            input.addEventListener('blur', () => {
                if (input.value.trim() === '') {
                    input.value = defaultVal;
                    input.classList.remove('court-modified');
                }
            });
        });
    }

    initCourtInputs();

    // ── Ctrl+Enter submits active form ────────────────────────────────────────
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                form.querySelector('[type="submit"]')?.click();
            }
        });
    });

    // ── Utility ───────────────────────────────────────────────────────────────
    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

})();
