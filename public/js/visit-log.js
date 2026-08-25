(function () {
    const DRAFT_KEY = 'jld.visit-draft';
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let usedVoice = false;
    let partyName = '';

    function todayIso() {
        const d = new Date();
        const z = d.getTimezoneOffset() * 60000;
        return new Date(d.getTime() - z).toISOString().slice(0, 10);
    }

    function loggedVia() {
        if (usedVoice) return 'voice';
        return window.matchMedia('(max-width: 768px)').matches ? 'mobile' : 'web';
    }

    function selectedContactIds() {
        return Array.from(document.querySelectorAll('#visitContactList input[type="checkbox"]:checked'))
            .map(function (el) { return parseInt(el.value, 10); })
            .filter(function (id) { return id > 0; });
    }

    function pendingNewContact() {
        const nameEl = document.getElementById('newContactName');
        if (!nameEl) return null;
        const name = nameEl.value.trim();
        if (!name) return null;
        return {
            name: name,
            role: document.getElementById('newContactRole').value.trim(),
            phone: document.getElementById('newContactPhone').value.trim()
        };
    }

    function payloadFromForm() {
        const noFollowup = document.getElementById('visitNoFollowup').checked;
        const pending = pendingNewContact();
        return {
            party_id: parseInt(document.getElementById('visitPartyId').value, 10) || 0,
            deal_id: document.getElementById('visitDealId').value || null,
            party_name: document.getElementById('visitPartySearch').value,
            visit_date: document.getElementById('visitDate').value,
            purpose: document.getElementById('visitPurpose').value,
            outcome: document.getElementById('visitOutcome').value,
            next_planned_touchpoint: noFollowup ? null : document.getElementById('visitTouchpoint').value,
            next_action: document.getElementById('visitNextAction').value,
            no_followup_needed: noFollowup,
            no_followup_reason: noFollowup ? document.getElementById('visitNoFollowupReason').value : null,
            contact_ids: selectedContactIds(),
            new_contacts: pending ? [pending] : [],
            logged_via: loggedVia()
        };
    }

    function saveDraft(payload, failed) {
        try {
            const stored = Object.assign({}, payload, { failed_submit: !!failed });
            localStorage.setItem(DRAFT_KEY, JSON.stringify(stored));
        } catch (e) { /* quota — never block */ }
    }

    function loadDraft() {
        try {
            const raw = localStorage.getItem(DRAFT_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function clearDraft() {
        try { localStorage.removeItem(DRAFT_KEY); } catch (e) { /* ignore */ }
    }

    function showDraftBanner(show) {
        document.getElementById('visitDraftBanner').classList.toggle('d-none', !show);
    }

    function fillForm(data) {
        if (!data) return;
        if (data.party_id) document.getElementById('visitPartyId').value = data.party_id;
        if (data.party_name) document.getElementById('visitPartySearch').value = data.party_name;
        if (data.deal_id) document.getElementById('visitDealId').value = data.deal_id;
        if (data.visit_date) document.getElementById('visitDate').value = data.visit_date;
        if (data.purpose) document.getElementById('visitPurpose').value = data.purpose;
        if (data.outcome) document.getElementById('visitOutcome').value = data.outcome;
        if (data.next_planned_touchpoint) document.getElementById('visitTouchpoint').value = data.next_planned_touchpoint;
        if (data.next_action) document.getElementById('visitNextAction').value = data.next_action;
        document.getElementById('visitNoFollowup').checked = !!data.no_followup_needed;
        toggleNoFollowup();
        if (data.no_followup_reason) document.getElementById('visitNoFollowupReason').value = data.no_followup_reason;
    }

    function toggleNoFollowup() {
        const on = document.getElementById('visitNoFollowup').checked;
        document.getElementById('visitTouchpoint').disabled = on;
        document.getElementById('visitNoFollowupReason').classList.toggle('d-none', !on);
    }

    function wireMic() {
        if (!SpeechRecognition) return;
        document.querySelectorAll('.visit-mic').forEach(function (btn) {
            btn.hidden = false;
            btn.addEventListener('click', function () {
                const target = document.getElementById(btn.getAttribute('data-target'));
                if (!target) return;
                const rec = new SpeechRecognition();
                rec.lang = 'en-IN';
                rec.interimResults = false;
                rec.onresult = function (event) {
                    usedVoice = true;
                    const text = event.results[0][0].transcript;
                    target.value = (target.value ? target.value + ' ' : '') + text;
                    saveDraft(payloadFromForm());
                };
                rec.onerror = function () { /* never block submit */ };
                try { rec.start(); } catch (e) { /* unsupported start — ignore */ }
            });
        });
    }

    async function loadContacts(partyId) {
        const box = document.getElementById('visitContactList');
        const addBtn = document.getElementById('btnInlineContact');
        if (!partyId) {
            box.innerHTML = '<p class="text-muted small mb-0">Pick a customer first.</p>';
            addBtn.disabled = true;
            return;
        }
        addBtn.disabled = false;
        try {
            const res = await apiCall('/api/crm/parties/' + partyId + '/contacts');
            const list = res.data || [];
            const draft = loadDraft();
            const selected = (draft && Number(draft.party_id) === Number(partyId)) ? (draft.contact_ids || []) : [];
            const preferName = (box.getAttribute('data-prefer-name') || '').toLowerCase();
            box.removeAttribute('data-prefer-name');
            if (list.length === 0) {
                box.innerHTML = '<p class="text-muted small mb-0">No contacts yet. Add one below.</p>';
                return;
            }
            box.innerHTML = list.map(function (c) {
                const matchName = preferName && String(c.name || '').toLowerCase() === preferName;
                const checked = selected.indexOf(c.id) !== -1 || matchName ? ' checked' : '';
                return '<label class="visit-contact-chip">' +
                    '<input type="checkbox" value="' + c.id + '"' + checked + '>' +
                    '<span>' + escapeHtml(c.name) + (c.role ? ' <span class="text-muted">· ' + escapeHtml(c.role) + '</span>' : '') + '</span>' +
                    '</label>';
            }).join('');
        } catch (e) {
            box.innerHTML = '<p class="text-danger small mb-0">' + escapeHtml(e.message) + '</p>';
        }
    }

    let partyCache = null;

    async function searchParties(q) {
        const results = document.getElementById('visitPartyResults');
        q = q.trim().toLowerCase();
        if (q.length < 2) { results.innerHTML = ''; return; }
        try {
            if (!partyCache) {
                const res = await apiCall('/api/parties');
                partyCache = res.data || [];
            }
            const list = partyCache.filter(function (p) {
                return String(p.name || '').toLowerCase().indexOf(q) !== -1;
            }).slice(0, 8);
            results.innerHTML = list.map(function (p) {
                return '<button type="button" class="list-group-item list-group-item-action" data-party-id="' + p.id + '" data-party-name="' + escapeHtml(p.name) + '">' + escapeHtml(p.name) + '</button>';
            }).join('') || '<div class="list-group-item text-muted">No matches</div>';
        } catch (e) {
            results.innerHTML = '<div class="list-group-item text-danger">' + escapeHtml(e.message) + '</div>';
        }
    }

    async function submitVisit() {
        const payload = payloadFromForm();
        if (!payload.party_id) { showError('Pick a customer.'); return; }
        if (payload.no_followup_needed) {
            if (!payload.no_followup_reason || !String(payload.no_followup_reason).trim()) {
                showError('Give a reason when no follow-up is needed.');
                return;
            }
        } else if (!payload.next_planned_touchpoint) {
            showError('Pick the next planned touchpoint, or choose “no follow-up needed”.');
            return;
        }
        saveDraft(payload);
        try {
            await apiCall('/api/crm/visits', { method: 'POST', body: JSON.stringify(payload) });
            clearDraft();
            showDraftBanner(false);
            const notice = document.getElementById('visitNotice');
            notice.textContent = 'Visit saved.';
            notice.classList.remove('d-none');
            const pid = payload.party_id;
            window.location.href = '/crm/parties/' + pid;
        } catch (e) {
            saveDraft(payload, true);
            showDraftBanner(true);
            showError(e.message + ' — draft kept on this phone. Tap Save to retry.');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('visitLogForm');
        if (!form) return;

        document.getElementById('visitDate').value = document.getElementById('visitDate').value || todayIso();
        document.getElementById('visitNoFollowup').addEventListener('change', function () {
            toggleNoFollowup();
            saveDraft(payloadFromForm());
        });
        wireMic();

        const search = document.getElementById('visitPartySearch');
        let timer = null;
        search.addEventListener('input', function () {
            if (search.readOnly) return;
            clearTimeout(timer);
            timer = setTimeout(function () { searchParties(search.value); }, 200);
        });
        document.getElementById('visitPartyResults').addEventListener('click', function (e) {
            const btn = e.target.closest('[data-party-id]');
            if (!btn) return;
            document.getElementById('visitPartyId').value = btn.getAttribute('data-party-id');
            partyName = btn.getAttribute('data-party-name') || '';
            search.value = partyName;
            document.getElementById('visitPartyName').textContent = partyName;
            document.getElementById('visitPartyResults').innerHTML = '';
            loadContacts(parseInt(btn.getAttribute('data-party-id'), 10));
        });

        document.getElementById('btnInlineContact').addEventListener('click', function () {
            document.getElementById('inlineContactFields').classList.toggle('d-none');
        });
        document.getElementById('btnSaveInlineContact').addEventListener('click', async function () {
            const partyId = parseInt(document.getElementById('visitPartyId').value, 10);
            const name = document.getElementById('newContactName').value.trim();
            if (!partyId || !name) { showError('Name is required for a new contact.'); return; }
            try {
                await apiCall('/api/crm/parties/' + partyId + '/contacts', {
                    method: 'POST',
                    body: JSON.stringify({
                        name: name,
                        role: document.getElementById('newContactRole').value.trim(),
                        phone: document.getElementById('newContactPhone').value.trim()
                    })
                });
                document.getElementById('newContactName').value = '';
                document.getElementById('newContactRole').value = '';
                document.getElementById('newContactPhone').value = '';
                document.getElementById('inlineContactFields').classList.add('d-none');
                document.getElementById('visitContactList').setAttribute('data-prefer-name', name);
                await loadContacts(partyId);
                saveDraft(payloadFromForm());
            } catch (e) {
                showError(e.message);
            }
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submitVisit();
        });
        document.getElementById('btnRetryDraft').addEventListener('click', submitVisit);

        const draft = loadDraft();
        const presetParty = parseInt(document.getElementById('visitPartyId').value, 10) || 0;
        if (draft && (!presetParty || Number(draft.party_id) === presetParty)) {
            fillForm(draft);
            showDraftBanner(!!draft.failed_submit);
        }
        const partyId = parseInt(document.getElementById('visitPartyId').value, 10) || 0;
        if (partyId) {
            loadContacts(partyId);
            apiCall('/api/parties/' + partyId).then(function (res) {
                const name = (res.data && res.data.name) || '';
                document.getElementById('visitPartySearch').value = name;
                document.getElementById('visitPartyName').textContent = name;
            }).catch(function () { /* ignore */ });
        }

        document.getElementById('visitContactList').addEventListener('change', function () {
            saveDraft(payloadFromForm());
        });
        ['visitPurpose', 'visitOutcome', 'visitNextAction', 'visitTouchpoint', 'visitDate', 'visitNoFollowupReason'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', function () { saveDraft(payloadFromForm()); });
        });
    });
})();
