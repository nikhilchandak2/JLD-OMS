/**
 * New-rep account briefing. Empty panels say "not yet recorded" with a link.
 * Never looks like a confident "none".
 */
(function () {
    const cfg = window.BRIEFING || {};

    function esc(value) {
        if (typeof escapeHtml === 'function') return escapeHtml(value);
        const div = document.createElement('div');
        div.textContent = String(value == null ? '' : value);
        return div.innerHTML;
    }

    function emptyHtml(section) {
        const msg = esc(section.empty_message || 'not yet recorded');
        const link = section.add_url
            ? ' <a href="' + esc(section.add_url) + '">Add it</a>'
            : '';
        return '<div class="briefing-empty">' + msg + link + '</div>';
    }

    function card(title, inner) {
        return '<section class="briefing-card"><h2>' + esc(title) + '</h2>' + inner + '</section>';
    }

    function listSection(title, section, renderItem) {
        if (!section) return '';
        if (!section.recorded || !(section.items || []).length) {
            return card(title, emptyHtml(section));
        }
        return card(title, (section.items || []).map(function (item) {
            return '<div class="briefing-row">' + renderItem(item) + '</div>';
        }).join(''));
    }

    function render(data) {
        document.getElementById('briefingTitle').textContent = (data.party && data.party.name)
            ? ('Briefing — ' + data.party.name)
            : 'Account briefing';

        const bits = [];
        bits.push(listSection('Key contacts', data.contacts, function (c) {
            return '<strong>' + esc(c.name) + '</strong><div class="small">'
                + esc(c.influence_label) + ' · ' + esc(c.relationship_label)
                + (c.phone ? ' · ' + esc(c.phone) : '') + '</div>';
        }));

        bits.push(listSection('Competitor situation', data.competitors, function (c) {
            const share = c.estimated_share_pct != null ? (' · ~' + esc(c.estimated_share_pct) + '%') : '';
            const why = c.reason_note || c.reason_label || '';
            return '<strong>' + esc(c.competitor_name) + '</strong>'
                + (c.grade_code ? ' / ' + esc(c.grade_code) : '') + esc(share)
                + '<div class="small">Why: ' + esc(why) + ' · ' + esc(c.intelligence_label) + '</div>';
        }));

        bits.push(listSection('Open issues and complaint history', data.issues, function (i) {
            return '<strong>' + esc(i.issue_type_label) + '</strong> · ' + esc(i.status_label)
                + '<div class="small">' + esc(i.description || '') + '</div>';
        }));

        const visit = data.last_visit || {};
        if (!visit.recorded) {
            bits.push(card('Last visit', emptyHtml(visit)));
        } else {
            const v = visit.item || {};
            const who = (v.contacts || []).map(function (c) { return c.name; }).join(', ') || 'who was met: not recorded';
            bits.push(card('Last visit',
                '<div>' + esc(v.visit_date) + ' · ' + esc(who) + '</div>'
                + '<div class="small">Outcome: ' + esc(v.outcome || '—') + '</div>'
                + '<div class="small">Next touchpoint: ' + esc(v.next_planned_touchpoint || '—') + '</div>'
            ));
        }

        bits.push(listSection('Recent order pattern', data.order_pattern, function (r) {
            return esc(r.grade_code) + ' — <strong>' + esc(r.tonnes) + ' t</strong>';
        }));

        bits.push(listSection('This month forecast vs actual', data.forecast, function (r) {
            return esc(r.grade_code) + ' — forecast ' + esc(r.forecast_low) + '–' + esc(r.forecast_high)
                + ' t · actual ' + esc(r.actual_tonnes) + ' t';
        }));

        const credit = data.credit || {};
        bits.push(card('Credit status', credit.recorded
            ? ('<div>' + esc(credit.headroom_band_label) + '</div><div class="small">As-of '
                + esc(credit.ledger_as_of || 'unknown') + (credit.lagging_entity_name ? ' · lagging: ' + esc(credit.lagging_entity_name) : '')
                + '</div>')
            : emptyHtml(credit)
        ));

        bits.push(listSection('Open deals', data.open_deals, function (d) {
            return '<a href="' + esc(d.url) + '">' + esc(d.title || ('Deal #' + d.id)) + '</a> — ' + esc(d.stage_label);
        }));

        const notes = data.handover_notes || {};
        let notesInner = '<div class="briefing-transitional">Transitional bridge — not a permanent feature. Review by '
            + esc(notes.review_date || cfg.reviewDate || '') + '. Senior memory dump while structured data is still thin.</div>';
        if (!notes.recorded) {
            notesInner += emptyHtml(notes);
        } else {
            notesInner += (notes.items || []).map(function (n) {
                return '<div class="briefing-row">' + esc(n.note)
                    + '<div class="small text-muted">' + esc(n.author_name || '') + ' · ' + esc(n.created_at || '') + '</div></div>';
            }).join('');
        }
        if (cfg.canWriteHandover) {
            notesInner += '<label class="form-label small mt-2" for="handoverNote">Add a handover note</label>'
                + '<textarea class="form-control" id="handoverNote" rows="3"></textarea>'
                + '<button type="button" class="btn btn-primary mt-2" id="handoverSave">Save note</button>';
        }
        bits.push(card('Handover notes', notesInner));

        document.getElementById('briefingRoot').innerHTML = bits.join('');

        const save = document.getElementById('handoverSave');
        if (save) {
            save.addEventListener('click', async function () {
                try {
                    await apiCall('/api/crm/parties/' + cfg.partyId + '/handover-notes', {
                        method: 'POST',
                        body: JSON.stringify({ note: document.getElementById('handoverNote').value })
                    });
                    await load();
                } catch (e) {
                    const box = document.getElementById('briefingError');
                    box.textContent = e.message || 'Could not save note';
                    box.classList.remove('d-none');
                }
            });
        }
    }

    async function load() {
        try {
            const res = await apiCall('/api/crm/parties/' + cfg.partyId + '/briefing');
            render(res.data);
        } catch (e) {
            document.getElementById('briefingRoot').innerHTML = '<div class="alert alert-danger">' + esc(e.message || 'Failed to load') + '</div>';
        }
    }

    document.addEventListener('DOMContentLoaded', load);
})();
