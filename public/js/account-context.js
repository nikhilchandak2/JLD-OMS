/**
 * Relationship mapping, competitive intelligence, and account issues (TASK 4).
 * Competitor intelligence is hidden unless the snapshot says view_competitors.
 * Intelligence type is always a visible marker — factual / reported / estimated are never merged.
 */
(function () {
    let meta = null;

    function esc(value) {
        if (typeof escapeHtml === 'function') return escapeHtml(value);
        const div = document.createElement('div');
        div.textContent = String(value == null ? '' : value);
        return div.innerHTML;
    }

    function optionsHtml(map, selected, includeBlank) {
        let html = includeBlank ? '<option value="">–</option>' : '';
        Object.keys(map || {}).forEach(function (key) {
            html += '<option value="' + esc(key) + '"' + (String(selected || '') === key ? ' selected' : '') + '>' + esc(map[key]) + '</option>';
        });
        return html;
    }

    function userOptionsHtml(users, selected) {
        let html = '<option value="">–</option>';
        (users || []).forEach(function (u) {
            html += '<option value="' + esc(u.id) + '"' + (String(selected || '') === String(u.id) ? ' selected' : '') + '>' + esc(u.name) + '</option>';
        });
        return html;
    }

    function intelMarker(type) {
        const label = (meta && meta.intelligence_types && meta.intelligence_types[type]) || type || 'unknown';
        const kind = type === 'factual' ? 'factual' : (type === 'reported' ? 'reported' : 'estimated');
        return '<span class="intel-marker intel-marker--' + kind + '">' + esc(label) + '</span>';
    }

    async function ensureMeta() {
        if (meta) return meta;
        const res = await apiCall('/api/crm/account-context/meta');
        meta = res.data || {};
        return meta;
    }

    function val(root, name) {
        const el = root.querySelector('[data-field="' + name + '"]');
        if (!el) return '';
        if (el.type === 'checkbox') return el.checked;
        return el.value;
    }

    function renderContactEditor(container, partyId, contacts, users, canEdit) {
        if (!container) return;
        const list = Array.isArray(contacts) ? contacts.slice() : [];
        if (list.length === 0 && !canEdit) {
            container.innerHTML = '<p class="text-muted small mb-0">Not yet recorded.</p>';
            return;
        }
        container.innerHTML = '<div data-contact-list></div>' +
            (canEdit ? '<button type="button" class="btn btn-sm btn-outline-primary mt-2" data-add-contact><i class="bi bi-plus me-1"></i>Add contact</button>' : '');
        const listEl = container.querySelector('[data-contact-list]');
        if (list.length === 0) {
            listEl.innerHTML = '<p class="text-muted small mb-2">No contacts yet. Add one in the list below — not a popup.</p>';
        }
        list.forEach(function (c) { listEl.appendChild(contactCard(partyId, c, users, canEdit)); });
        const addBtn = container.querySelector('[data-add-contact]');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                const emptyHint = listEl.querySelector('p.text-muted');
                if (emptyHint) emptyHint.remove();
                listEl.appendChild(contactCard(partyId, {}, users, canEdit));
            });
        }
    }

    function contactCard(partyId, contact, users, canEdit) {
        const wrap = document.createElement('div');
        wrap.className = 'crm-contact-editor-row';
        wrap.dataset.id = contact.id || '';
        const disabled = canEdit ? '' : ' disabled';
        wrap.innerHTML =
            '<div class="row g-2">' +
            '<div class="col-md-4"><label class="form-label small mb-0">Name *</label><input class="form-control form-control-sm" data-field="name" value="' + esc(contact.name || '') + '"' + disabled + '></div>' +
            '<div class="col-md-4"><label class="form-label small mb-0">Role</label><input class="form-control form-control-sm" data-field="role" value="' + esc(contact.role || '') + '"' + disabled + '></div>' +
            '<div class="col-md-2"><label class="form-label small mb-0">Influence</label><select class="form-select form-select-sm" data-field="influence_level"' + disabled + '>' + optionsHtml(meta.influence_levels, contact.influence_level || 'unknown', false) + '</select></div>' +
            '<div class="col-md-2"><label class="form-label small mb-0">Strength</label><select class="form-select form-select-sm" data-field="relationship_strength"' + disabled + '>' + optionsHtml(meta.relationship_strengths, contact.relationship_strength || 'unknown', false) + '</select></div>' +
            '<div class="col-md-3"><label class="form-label small mb-0">Phone</label><input class="form-control form-control-sm" data-field="phone" value="' + esc(contact.phone || '') + '"' + disabled + '></div>' +
            '<div class="col-md-3"><label class="form-label small mb-0">Email</label><input class="form-control form-control-sm" data-field="email" value="' + esc(contact.email || '') + '"' + disabled + '></div>' +
            '<div class="col-md-3"><label class="form-label small mb-0">Preferred channel</label><select class="form-select form-select-sm" data-field="preferred_channel"' + disabled + '>' + optionsHtml(meta.preferred_channels, contact.preferred_channel || '', true) + '</select></div>' +
            '<div class="col-md-3"><label class="form-label small mb-0">Language</label><input class="form-control form-control-sm" data-field="preferred_language" value="' + esc(contact.preferred_language || '') + '"' + disabled + '></div>' +
            '<div class="col-md-4"><label class="form-label small mb-0">Introduced by</label><select class="form-select form-select-sm" data-field="introduced_by_user_id"' + disabled + '>' + userOptionsHtml(users, contact.introduced_by_user_id) + '</select></div>' +
            '<div class="col-md-3"><label class="form-label small mb-0">Introduced on</label><input type="date" class="form-control form-control-sm" data-field="introduced_on" value="' + esc(contact.introduced_on || '') + '"' + disabled + '></div>' +
            '<div class="col-md-5 d-flex align-items-end"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" data-field="is_primary"' + (contact.is_primary ? ' checked' : '') + disabled + '><label class="form-check-label small">Primary</label></div></div>' +
            '<div class="col-12"><label class="form-label small mb-0">Context notes</label><textarea class="form-control form-control-sm" rows="2" data-field="context_notes"' + disabled + '>' + esc(contact.context_notes || '') + '</textarea></div>' +
            '</div>' +
            (canEdit ? '<div class="d-flex gap-2 mt-2"><button type="button" class="btn btn-sm btn-primary" data-save-contact>Save</button>' +
                (contact.id ? '<button type="button" class="btn btn-sm btn-outline-danger" data-delete-contact>Delete</button>' : '') +
                '<span class="small text-muted align-self-center" data-contact-status></span></div>' : '');

        if (!canEdit) return wrap;

        wrap.querySelector('[data-save-contact]').addEventListener('click', async function () {
            const status = wrap.querySelector('[data-contact-status]');
            const payload = {
                name: String(val(wrap, 'name')).trim(),
                role: String(val(wrap, 'role')).trim(),
                phone: String(val(wrap, 'phone')).trim(),
                email: String(val(wrap, 'email')).trim(),
                is_primary: !!val(wrap, 'is_primary'),
                influence_level: val(wrap, 'influence_level'),
                relationship_strength: val(wrap, 'relationship_strength'),
                preferred_channel: val(wrap, 'preferred_channel') || null,
                preferred_language: String(val(wrap, 'preferred_language')).trim() || null,
                introduced_by_user_id: val(wrap, 'introduced_by_user_id') || null,
                introduced_on: val(wrap, 'introduced_on') || null,
                context_notes: String(val(wrap, 'context_notes')).trim() || null
            };
            if (!payload.name) { showError('Contact name is required'); return; }
            try {
                const id = wrap.dataset.id;
                const res = id
                    ? await apiCall('/api/crm/contacts/' + id, { method: 'PUT', body: JSON.stringify(payload) })
                    : await apiCall('/api/crm/parties/' + partyId + '/contacts', { method: 'POST', body: JSON.stringify(payload) });
                wrap.dataset.id = String(res.data.id);
                if (!wrap.querySelector('[data-delete-contact]')) {
                    wrap.querySelector('[data-save-contact]').insertAdjacentHTML('afterend', '<button type="button" class="btn btn-sm btn-outline-danger" data-delete-contact>Delete</button>');
                    bindDelete(wrap, partyId);
                }
                status.textContent = 'Saved';
            } catch (e) {
                showError(e.message);
            }
        });
        bindDelete(wrap, partyId);
        return wrap;
    }

    function bindDelete(wrap, partyId) {
        const btn = wrap.querySelector('[data-delete-contact]');
        if (!btn || btn.dataset.bound) return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', async function () {
            const id = wrap.dataset.id;
            if (!id) { wrap.remove(); return; }
            if (!confirm('Delete this contact?')) return;
            try {
                await apiCall('/api/crm/contacts/' + id, { method: 'DELETE' });
                wrap.remove();
            } catch (e) {
                showError(e.message);
            }
        });
    }

    function renderCompetitorPanel(container, partyId, snapshot, canEdit) {
        if (!container) return;
        if (!snapshot || !snapshot.capabilities || !snapshot.capabilities.view_competitors) {
            container.classList.add('d-none');
            return;
        }
        container.classList.remove('d-none');
        const block = snapshot.competitors || { current: [], history: [], empty: true };
        const current = block.current || [];
        const history = block.history || [];

        let html = '';
        if (block.empty) {
            html += '<p class="text-muted small">Not yet recorded — we have not asked who else they buy from.</p>';
        } else {
            html += '<div class="mb-2"><strong class="small">Current positions</strong></div>';
            if (current.length === 0) {
                html += '<p class="text-muted small">No current position marked.</p>';
            } else {
                html += current.map(competitorRow).join('');
            }
            html += '<details class="mt-2"><summary class="small">History (' + history.length + ')</summary>';
            html += history.length ? history.map(competitorRow).join('') : '<p class="text-muted small mb-0 mt-2">No earlier positions.</p>';
            html += '</details>';
        }

        if (canEdit) {
            html += '<form class="border-top pt-3 mt-3" data-competitor-form>' +
                '<div class="small fw-semibold mb-2">Record a new position</div>' +
                '<div class="row g-2">' +
                '<div class="col-md-4"><label class="form-label small mb-0">Competitor *</label><input class="form-control form-control-sm" name="competitor_name" required></div>' +
                '<div class="col-md-2"><label class="form-label small mb-0">Grade</label><input class="form-control form-control-sm" name="grade_code"></div>' +
                '<div class="col-md-3"><label class="form-label small mb-0">Application</label><input class="form-control form-control-sm" name="application"></div>' +
                '<div class="col-md-3"><label class="form-label small mb-0">Est. share %</label><input type="number" min="0" max="100" class="form-control form-control-sm" name="estimated_share_pct" placeholder="if knowable"></div>' +
                '<div class="col-md-3"><label class="form-label small mb-0">Why they win</label><select class="form-select form-select-sm" name="reason_code">' + optionsHtml(meta.reason_codes, 'other', false) + '</select></div>' +
                '<div class="col-md-3"><label class="form-label small mb-0">Intelligence type *</label><select class="form-select form-select-sm" name="intelligence_type">' + optionsHtml(meta.intelligence_types, 'reported', false) + '</select></div>' +
                '<div class="col-12"><label class="form-label small mb-0">Note</label><textarea class="form-control form-control-sm" rows="2" name="reason_note"></textarea></div>' +
                '</div>' +
                '<button type="submit" class="btn btn-sm btn-primary mt-2">Add current position</button>' +
                '<div class="form-text">Adds a new row and marks the previous current position for this competitor + grade as history. Nothing is overwritten.</div>' +
                '</form>';
        }
        container.querySelector('[data-competitor-body]').innerHTML = html;

        const form = container.querySelector('[data-competitor-form]');
        if (form) {
            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                const fd = new FormData(form);
                const payload = Object.fromEntries(fd.entries());
                payload.is_current = true;
                if (payload.estimated_share_pct === '') payload.estimated_share_pct = null;
                try {
                    await apiCall('/api/crm/parties/' + partyId + '/competitors', { method: 'POST', body: JSON.stringify(payload) });
                    if (typeof window.reloadAccountContext === 'function') window.reloadAccountContext();
                } catch (err) {
                    showError(err.message);
                }
            });
        }
    }

    function competitorRow(row) {
        const share = row.estimated_share_pct == null ? 'share unknown' : (row.estimated_share_pct + '%');
        return '<div class="crm-competitor-row">' +
            '<div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">' +
            '<div><strong>' + esc(row.competitor_name) + '</strong> ' + intelMarker(row.intelligence_type) +
            (row.is_current ? ' <span class="badge bg-primary">Current</span>' : '') +
            '<div class="small text-muted">' + esc(row.grade_code || 'all grades') +
            (row.application ? ' · ' + esc(row.application) : '') +
            ' · ' + esc(share) +
            ' · ' + esc(row.reason_code_label || row.reason_code) + '</div>' +
            (row.reason_note ? '<div class="small mt-1">' + esc(row.reason_note) + '</div>' : '') +
            '</div>' +
            '<div class="small text-muted text-end">' + esc((row.recorded_at || '').slice(0, 10)) +
            (row.recorded_by_name ? '<br>' + esc(row.recorded_by_name) : '') + '</div>' +
            '</div></div>';
    }

    function renderIssuesPanel(container, partyId, dealId, snapshot, canEdit) {
        if (!container) return;
        const block = snapshot.issues || { items: [], empty: true };
        const items = block.items || [];
        let html = '';
        if (block.empty) {
            html += '<p class="text-muted small">Not yet recorded — no complaint or delivery issue has been logged.</p>';
        } else {
            html += items.map(function (issue) { return issueRow(issue, canEdit); }).join('');
        }
        if (canEdit) {
            html += '<form class="border-top pt-3 mt-3" data-issue-form>' +
                '<div class="small fw-semibold mb-2">Log an issue</div>' +
                '<div class="row g-2">' +
                '<div class="col-md-4"><label class="form-label small mb-0">Type</label><select class="form-select form-select-sm" name="issue_type">' + optionsHtml(meta.issue_types, 'quality_complaint', false) + '</select></div>' +
                '<div class="col-md-4"><label class="form-label small mb-0">Raised on</label><input type="date" class="form-control form-control-sm" name="raised_on"></div>' +
                '<div class="col-md-4"><label class="form-label small mb-0">Resolution window (days)</label><input type="number" min="1" class="form-control form-control-sm" name="resolution_window_days" value="' + esc(meta.default_resolution_window_days || 7) + '"></div>' +
                '<div class="col-12"><label class="form-label small mb-0">What happened *</label><textarea class="form-control form-control-sm" name="description" rows="2" required></textarea></div>' +
                '</div>' +
                '<button type="submit" class="btn btn-sm btn-primary mt-2">Log issue</button>' +
                '</form>';
        }
        container.querySelector('[data-issues-body]').innerHTML = html;
        container.querySelectorAll('[data-resolve-issue]').forEach(function (btn) {
            btn.addEventListener('click', function () { resolveIssue(btn.getAttribute('data-resolve-issue')); });
        });
        const form = container.querySelector('[data-issue-form]');
        if (form) {
            const raised = form.querySelector('[name="raised_on"]');
            if (raised && !raised.value) raised.value = new Date().toISOString().slice(0, 10);
            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                const fd = new FormData(form);
                const payload = Object.fromEntries(fd.entries());
                if (dealId) payload.deal_id = dealId;
                try {
                    await apiCall('/api/crm/parties/' + partyId + '/issues', { method: 'POST', body: JSON.stringify(payload) });
                    if (typeof window.reloadAccountContext === 'function') window.reloadAccountContext();
                } catch (err) {
                    showError(err.message);
                }
            });
        }
    }

    function issueRow(issue, canEdit) {
        const statusClass = issue.status === 'open' ? 'bg-danger' : (issue.status === 'escalated' ? 'bg-warning text-dark' : 'bg-secondary');
        return '<div class="crm-issue-row">' +
            '<div class="d-flex justify-content-between gap-2 flex-wrap">' +
            '<div><span class="badge ' + statusClass + ' text-capitalize">' + esc(issue.status_label || issue.status) + '</span> ' +
            '<span class="small fw-semibold">' + esc(issue.issue_type_label || issue.issue_type) + '</span>' +
            '<div class="small mt-1">' + esc(issue.description) + '</div>' +
            '<div class="small text-muted">Raised ' + esc(issue.raised_on) +
            (issue.deal_title ? ' · deal ' + esc(issue.deal_title) : '') +
            ' · window ' + esc(issue.resolution_window_days) + ' days</div>' +
            (issue.resolution_note ? '<div class="small mt-1">Resolution: ' + esc(issue.resolution_note) + '</div>' : '') +
            '</div>' +
            (canEdit && issue.status !== 'resolved' ? '<button type="button" class="btn btn-sm btn-outline-success" data-resolve-issue="' + esc(issue.id) + '">Resolve</button>' : '') +
            '</div></div>';
    }

    async function resolveIssue(id) {
        const note = prompt('Resolution note (required)');
        if (note === null) return;
        if (!String(note).trim()) { showError('A resolution note is required.'); return; }
        try {
            await apiCall('/api/crm/issues/' + id + '/resolve', { method: 'POST', body: JSON.stringify({ resolution_note: String(note).trim() }) });
            if (typeof window.reloadAccountContext === 'function') window.reloadAccountContext();
        } catch (e) {
            showError(e.message);
        }
    }

    function renderContextPanel(container, partyId, snapshot, canEdit) {
        if (!container) return;
        const ctx = snapshot.context || {};
        const body = container.querySelector('[data-context-body]');
        if (!canEdit && !ctx.filled) {
            body.innerHTML = '<p class="text-muted small mb-0">Not yet recorded.</p>';
            return;
        }
        body.innerHTML =
            '<div class="mb-2"><label class="form-label small">Production capacity</label>' +
            '<input class="form-control form-control-sm" data-field="production_capacity_note" value="' + esc(ctx.production_capacity_note || '') + '"' + (canEdit ? '' : ' disabled') + '></div>' +
            '<div class="mb-2"><label class="form-label small">Seasonality</label>' +
            '<textarea class="form-control form-control-sm" rows="2" data-field="seasonality_note"' + (canEdit ? '' : ' disabled') + '>' + esc(ctx.seasonality_note || '') + '</textarea></div>' +
            (canEdit ? '<button type="button" class="btn btn-sm btn-primary" data-save-context>Save</button>' : '') +
            (ctx.updated_at ? '<div class="small text-muted mt-2">Updated ' + esc(ctx.updated_at) + (ctx.updated_by_name ? ' · ' + esc(ctx.updated_by_name) : '') + '</div>' : '');
        const save = body.querySelector('[data-save-context]');
        if (save) {
            save.addEventListener('click', async function () {
                try {
                    await apiCall('/api/crm/parties/' + partyId + '/account-context', {
                        method: 'PUT',
                        body: JSON.stringify({
                            production_capacity_note: val(body, 'production_capacity_note'),
                            seasonality_note: val(body, 'seasonality_note')
                        })
                    });
                    if (typeof window.reloadAccountContext === 'function') window.reloadAccountContext();
                } catch (e) {
                    showError(e.message);
                }
            });
        }
    }

    function headerCount(label, filledText, empty) {
        return '<span>' + esc(label) + '</span><span class="badge ' + (empty ? 'bg-light text-muted border' : 'bg-primary') + '">' + esc(empty ? 'Not yet recorded' : filledText) + '</span>';
    }

    function renderDealPanels(container, deal) {
        if (!container) return;
        const snap = deal.account_context || {};
        const caps = snap.capabilities || {};
        const contacts = snap.contacts || { count: 0, empty: true };
        const issues = snap.issues || { open_count: 0, resolved_count: 0, empty: true };
        const ctx = snap.context || { filled: false };
        const competitors = snap.competitors;

        let html = '<div class="accordion" id="accountContextAccordion">';
        html += dealAccordionItem('accContacts', headerCount('Contacts', contacts.count + ' recorded', !!contacts.empty), contactSummary(contacts.items || []));
        if (caps.view_competitors && competitors) {
            html += dealAccordionItem('accCompetitors', headerCount('Competitors', competitors.current_count + ' current · ' + competitors.history_count + ' history', !!competitors.empty), competitorSummary(competitors));
        }
        html += dealAccordionItem('accIssues', headerCount('Issues', issues.open_count + ' open · ' + issues.resolved_count + ' resolved', !!issues.empty), issueSummary(issues.items || []));
        html += dealAccordionItem('accNotes', headerCount('Account notes', ctx.filled ? 'Documented' : '', !ctx.filled), contextSummary(ctx));
        html += '</div>';
        if (deal.party_id) {
            html += '<div class="small mt-2"><a href="/crm/parties/' + esc(deal.party_id) + '">Open full account record</a></div>';
        }
        container.innerHTML = html;
    }

    function dealAccordionItem(id, headerHtml, bodyHtml) {
        return '<div class="accordion-item">' +
            '<h2 class="accordion-header"><button class="accordion-button collapsed d-flex justify-content-between gap-2" type="button" data-bs-toggle="collapse" data-bs-target="#' + id + '">' + headerHtml + '</button></h2>' +
            '<div id="' + id + '" class="accordion-collapse collapse" data-bs-parent="#accountContextAccordion">' +
            '<div class="accordion-body small">' + bodyHtml + '</div></div></div>';
    }

    function contactSummary(items) {
        if (!items.length) return 'Not yet recorded.';
        return items.map(function (c) {
            return '<div class="mb-1"><strong>' + esc(c.name) + '</strong> · ' + esc(c.influence_level || 'unknown') + ' · ' + esc(c.relationship_strength || 'unknown') + '</div>';
        }).join('');
    }

    function competitorSummary(block) {
        if (!block || block.empty) return 'Not yet recorded — we have not asked who else they buy from.';
        const rows = (block.current || []).concat(block.history || []);
        return rows.map(function (row) {
            return '<div class="mb-2">' + esc(row.competitor_name) + ' ' + intelMarker(row.intelligence_type) +
                (row.is_current ? ' <span class="badge bg-primary">Current</span>' : '') +
                '<div class="text-muted">' + esc(row.grade_code || 'all grades') + ' · ' + esc(row.reason_code_label || '') + '</div></div>';
        }).join('');
    }

    function issueSummary(items) {
        if (!items.length) return 'Not yet recorded — no complaint has been logged for this account.';
        return items.map(function (i) {
            return '<div class="mb-2"><span class="text-capitalize">' + esc(i.status) + '</span> · ' + esc(i.issue_type_label || i.issue_type) +
                '<div>' + esc(i.description) + '</div></div>';
        }).join('');
    }

    function contextSummary(ctx) {
        if (!ctx || !ctx.filled) return 'Not yet recorded.';
        return '<div>Capacity: ' + esc(ctx.production_capacity_note || '—') + '</div><div>Seasonality: ' + esc(ctx.seasonality_note || '—') + '</div>';
    }

    async function mountParty(opts) {
        await ensureMeta();
        const partyId = opts.partyId;
        const users = opts.users || [];
        async function reload() {
            const res = await apiCall('/api/crm/parties/' + partyId + '/account-context');
            const snap = res.data;
            const caps = snap.capabilities || {};
            renderContactEditor(document.getElementById('contactEditor'), partyId, snap.contacts.items, users, !!caps.edit_contacts);
            renderCompetitorPanel(document.getElementById('competitorPanel'), partyId, snap, !!caps.edit_competitors);
            renderIssuesPanel(document.getElementById('issuesPanel'), partyId, null, snap, !!caps.edit_issues);
            renderContextPanel(document.getElementById('accountContextPanel'), partyId, snap, !!caps.edit_context);
        }
        window.reloadAccountContext = reload;
        await reload();
    }

    async function mountDeal(deal) {
        await ensureMeta();
        renderDealPanels(document.getElementById('dealAccountContext'), deal);
    }

    function mountSearch(opts) {
        const input = document.getElementById(opts.inputId);
        const results = document.getElementById(opts.resultsId);
        if (!input || !results) return;
        let timer = null;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { runSearch(input.value, results); }, 250);
        });
    }

    async function runSearch(q, results) {
        q = String(q || '').trim();
        if (q.length < 2) { results.innerHTML = ''; return; }
        try {
            const res = await apiCall('/api/crm/account-search?q=' + encodeURIComponent(q));
            const data = res.data || {};
            const blocks = [];
            (data.contacts || []).forEach(function (c) {
                blocks.push(searchHit('Contact', c.name, c.party_name, c.party_id, c.role));
            });
            (data.competitors || []).forEach(function (c) {
                blocks.push(searchHit('Competitor', c.competitor_name, c.party_name, c.party_id, c.intelligence_type));
            });
            (data.issues || []).forEach(function (c) {
                blocks.push(searchHit('Issue', c.description, c.party_name, c.party_id, c.issue_type_label));
            });
            results.innerHTML = blocks.length ? blocks.join('') : '<p class="text-muted small mb-0">No matches.</p>';
        } catch (e) {
            results.innerHTML = '<p class="text-danger small mb-0">' + esc(e.message) + '</p>';
        }
    }

    function searchHit(kind, title, party, partyId, extra) {
        return '<a class="list-group-item list-group-item-action" href="/crm/parties/' + esc(partyId) + '">' +
            '<div class="small text-muted">' + esc(kind) + (extra ? ' · ' + esc(extra) : '') + '</div>' +
            '<div>' + esc(title) + '</div>' +
            '<div class="small">' + esc(party) + '</div></a>';
    }

    window.AccountContext = { mountParty: mountParty, mountDeal: mountDeal, mountSearch: mountSearch, ensureMeta: ensureMeta };
})();
