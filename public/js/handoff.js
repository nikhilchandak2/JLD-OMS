/**
 * Handoff packets (TASK 8). Receiving views are read-only plus Acknowledge.
 * No packet field is a re-entry input for the receiving team.
 */
(function () {
    const termsLabel = { ex_works: 'Ex-works', for: 'FOR', freight: 'Freight' };

    function esc(value) {
        if (typeof escapeHtml === 'function') return escapeHtml(value);
        const div = document.createElement('div');
        div.textContent = String(value == null ? '' : value);
        return div.innerHTML;
    }

    function showBox(id, message, isError) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = message;
        el.classList.remove('d-none');
        const other = document.getElementById(isError ? 'handoffNotice' : 'handoffError');
        if (other) other.classList.add('d-none');
    }

    function payloadRows(packet) {
        const p = packet.payload || {};
        if (packet.packet_type === 'sales_to_dispatch') {
            const grades = (p.grades || []).map(function (g) {
                return esc(g.grade_code) + ' — ' + esc(g.spec);
            }).join('<br>');
            return [
                ['Confirmed grade(s) + spec', grades || '—'],
                ['Quantity (tonnes)', esc(p.quantity_tonnes)],
                ['Packing', esc(p.packing)],
                ['Agreed delivery timeline', esc(p.delivery_timeline)],
                ['Delivery terms', esc(termsLabel[p.delivery_terms] || p.delivery_terms)],
                ['Special handling notes', esc(p.special_handling_notes || '—')]
            ];
        }
        return [
            ['Dispatch / delivery date', esc(p.delivery_date)],
            ['Linked quote', esc(p.quote_reference)],
            ['Agreed terms', esc(p.agreed_terms)],
            ['Invoice reference', esc(p.invoice_reference)]
        ];
    }

    function readonlyTable(packet) {
        const rows = payloadRows(packet).map(function (pair) {
            return '<tr><th class="small text-muted" style="width:40%">' + pair[0] + '</th><td>' + pair[1] + '</td></tr>';
        }).join('');
        const ack = packet.acknowledged_at
            ? '<span class="badge bg-success">Acknowledged</span> <span class="small text-muted">' + esc(packet.acknowledged_at) + '</span>'
            : '<span class="badge bg-warning text-dark">Awaiting acknowledgement</span>';
        const superseded = packet.superseded_by_packet_id
            ? ' <span class="badge bg-secondary">Superseded by #' + packet.superseded_by_packet_id + '</span>'
            : '';
        return '<div class="small text-muted mb-2">Packet #' + packet.id + ' · schema v' + packet.schema_version
            + ' · ' + ack + superseded + '</div>'
            + '<table class="table table-sm table-bordered mb-2"><tbody>' + rows + '</tbody></table>';
    }

    function actions(packet, opts) {
        const bits = [];
        bits.push('<a class="btn btn-sm btn-outline-secondary" href="/api/crm/handoffs/' + packet.id + '/pdf">Print PDF</a>');
        const canAck = packet.packet_type === 'sales_to_dispatch' ? opts.canAckSales : opts.canAckAccounts;
        if (canAck && !packet.acknowledged_at && !packet.superseded_by_packet_id) {
            bits.push('<button type="button" class="btn btn-sm btn-primary js-ack" data-id="' + packet.id + '">Acknowledge</button>');
        }
        if (opts.canSupersede && !packet.superseded_by_packet_id) {
            bits.push('<button type="button" class="btn btn-sm btn-outline-warning js-super" data-id="' + packet.id + '">Supersede</button>');
        }
        return '<div class="d-flex flex-wrap gap-2">' + bits.join('') + '</div>';
    }

    async function ack(id, onDone) {
        try {
            const res = await apiCall('/api/crm/handoffs/' + id + '/acknowledge', { method: 'POST', body: '{}' });
            showBox('handoffNotice', res.message || 'Acknowledged.', false);
            if (onDone) onDone();
        } catch (e) {
            showBox('handoffError', e.message || 'Acknowledge failed', true);
        }
    }

    async function supersedePrompt(id, packet, onDone) {
        const reason = window.prompt('Reason for replacing this packet?');
        if (reason === null) return;
        if (String(reason).trim() === '') {
            showBox('handoffError', 'A reason is required.', true);
            return;
        }
        try {
            await apiCall('/api/crm/handoffs/' + id + '/supersede', {
                method: 'POST',
                body: JSON.stringify({ reason: reason, payload: packet.payload })
            });
            showBox('handoffNotice', 'Replacement packet created. Edit it from the form if the payload also needs to change.', false);
            if (onDone) onDone();
        } catch (e) {
            showBox('handoffError', e.message || 'Supersede failed', true);
        }
    }

    function bindCard(root, packet, opts, onDone) {
        root.querySelectorAll('.js-ack').forEach(function (btn) {
            btn.addEventListener('click', function () { ack(packet.id, onDone); });
        });
        root.querySelectorAll('.js-super').forEach(function (btn) {
            btn.addEventListener('click', function () { supersedePrompt(packet.id, packet, onDone); });
        });
    }

    function cardHtml(packet, opts) {
        const heading = packet.packet_type === 'sales_to_dispatch'
            ? (packet.party_name || packet.deal_title || ('Deal #' + (packet.deal_id || '')))
            : (packet.order_no || ('Order #' + (packet.order_id || '')));
        return '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-body">'
            + '<div class="fw-semibold mb-1">' + esc(heading) + '</div>'
            + readonlyTable(packet)
            + actions(packet, opts)
            + '</div></div></div>';
    }

    async function mountDeal(deal) {
        const root = document.getElementById('dealHandoffRoot');
        if (!root || !deal) return;
        const packet = deal.handoff_packet;
        const stage = Number(deal.stage);
        const opts = { canAckSales: false, canAckAccounts: false, canSupersede: stage >= 6 && deal.status === 'active' };

        if (packet) {
            root.innerHTML = '<p class="small text-muted">Receiving teams see this read-only. They do not re-type these fields.</p>'
                + readonlyTable(packet) + actions(packet, opts);
            bindCard(root, packet, opts, function () { if (typeof loadDeal === 'function') loadDeal(); });
            if (packet.superseded_by_packet_id || !opts.canSupersede) {
                return;
            }
        }

        if (deal.status !== 'active' || stage < 6) {
            if (!packet) {
                root.innerHTML = '<p class="text-muted small mb-0">A Sales→Dispatch packet is required at Stage 6 before Dispatch sees the order.</p>';
            }
            return;
        }
        if (!packet && stage !== 6) {
            return;
        }

        const form = document.createElement('div');
        form.innerHTML = (packet ? '<hr><h6>Replace with a new packet</h6>' : '<p class="small text-muted">Fill this once. Dispatch will acknowledge it as written.</p>')
            + '<div class="row g-2">'
            + '<div class="col-md-6"><label class="form-label small">Grade code</label><input class="form-control" id="hfGrade" value="' + esc((deal.grades && deal.grades[0] && (deal.grades[0].grade_code || deal.grades[0])) || 'J-11') + '"></div>'
            + '<div class="col-md-6"><label class="form-label small">Spec</label><input class="form-control" id="hfSpec" value=""></div>'
            + '<div class="col-md-4"><label class="form-label small">Quantity (tonnes)</label><input type="number" step="0.001" min="0.001" class="form-control" id="hfQty" value="' + esc(deal.indicative_quantity_tonnes || '') + '"></div>'
            + '<div class="col-md-4"><label class="form-label small">Packing</label><input class="form-control" id="hfPack" placeholder="50 kg bags"></div>'
            + '<div class="col-md-4"><label class="form-label small">Delivery terms</label><select class="form-select" id="hfTerms"><option value="ex_works">Ex-works</option><option value="for">FOR</option><option value="freight">Freight</option></select></div>'
            + '<div class="col-12"><label class="form-label small">Agreed delivery timeline</label><input class="form-control" id="hfTimeline"></div>'
            + '<div class="col-12"><label class="form-label small">Special handling notes</label><textarea class="form-control" id="hfNotes" rows="2" placeholder="Bagging, labelling, site requirements — or None"></textarea></div>'
            + '</div>'
            + '<button type="button" class="btn btn-primary mt-3" id="hfSave">' + (packet ? 'Supersede with this payload' : 'Create packet') + '</button>';
        if (!packet) {
            root.innerHTML = '';
        }
        root.appendChild(form);
        document.getElementById('hfSave').addEventListener('click', async function () {
            const payload = {
                grades: [{ grade_code: document.getElementById('hfGrade').value, spec: document.getElementById('hfSpec').value }],
                quantity_tonnes: Number(document.getElementById('hfQty').value),
                packing: document.getElementById('hfPack').value,
                delivery_timeline: document.getElementById('hfTimeline').value,
                delivery_terms: document.getElementById('hfTerms').value,
                special_handling_notes: document.getElementById('hfNotes').value
            };
            try {
                if (packet) {
                    const reason = window.prompt('Reason for replacing this packet?');
                    if (reason === null || String(reason).trim() === '') {
                        showBox('dealError', 'A reason is required to supersede.', true);
                        return;
                    }
                    await apiCall('/api/crm/handoffs/' + packet.id + '/supersede', {
                        method: 'POST',
                        body: JSON.stringify({ reason: reason, payload: payload })
                    });
                } else {
                    await apiCall('/api/crm/handoffs', {
                        method: 'POST',
                        body: JSON.stringify({
                            packet_type: 'sales_to_dispatch',
                            deal_id: deal.id,
                            payload: payload
                        })
                    });
                }
                if (typeof loadDeal === 'function') await loadDeal();
            } catch (e) {
                if (typeof fail === 'function') fail(e);
                else showBox('dealError', e.message || 'Save failed', true);
            }
        });
    }

    async function mountOrder() {
        const root = document.getElementById('orderHandoffRoot');
        if (!root) return;
        const orderId = Number(root.getAttribute('data-order-id') || 0);
        if (!orderId) return;
        const opts = {
            canAckSales: false,
            canAckAccounts: true,
            canSupersede: false
        };
        try {
            const res = await apiCall('/api/crm/handoffs?order_id=' + orderId + '&packet_type=dispatch_to_accounts&current_only=1');
            const rows = res.data || [];
            if (rows.length) {
                root.innerHTML = '<p class="small text-muted">Receiving teams do not re-type these fields.</p>' + readonlyTable(rows[0]) + actions(rows[0], opts);
                bindCard(root, rows[0], opts, mountOrder);
                return;
            }
        } catch (e) {
            root.innerHTML = '<p class="text-danger small">' + esc(e.message || 'Could not load packet') + '</p>';
            return;
        }

        root.innerHTML = '<p class="small text-muted">Create the Dispatch→Accounts packet on delivery confirmation. It is the source of truth for the Busy manual bridge.</p>'
            + '<div class="row g-2">'
            + '<div class="col-md-4"><label class="form-label small">Delivery date</label><input type="date" class="form-control" id="odDate"></div>'
            + '<div class="col-md-4"><label class="form-label small">Invoice reference</label><input class="form-control" id="odInv"></div>'
            + '<div class="col-md-4"><label class="form-label small">Linked quote</label><input class="form-control" id="odQuote"></div>'
            + '<div class="col-12"><label class="form-label small">Agreed terms</label><input class="form-control" id="odTerms"></div>'
            + '</div>'
            + '<button type="button" class="btn btn-primary btn-sm mt-2" id="odSave">Create packet</button>';
        document.getElementById('odSave').addEventListener('click', async function () {
            try {
                await apiCall('/api/crm/handoffs', {
                    method: 'POST',
                    body: JSON.stringify({
                        packet_type: 'dispatch_to_accounts',
                        order_id: orderId,
                        payload: {
                            delivery_date: document.getElementById('odDate').value,
                            invoice_reference: document.getElementById('odInv').value,
                            quote_reference: document.getElementById('odQuote').value,
                            agreed_terms: document.getElementById('odTerms').value
                        }
                    })
                });
                await mountOrder();
            } catch (e) {
                showBox('handoffError', e.message || 'Save failed', true);
                if (typeof showError === 'function') showError(e.message);
            }
        });
    }

    async function mountDispatchPage() {
        if (!window.HANDOFF_PAGE) return;
        const flags = window.HANDOFF_PAGE;
        const salesOpts = { canAckSales: flags.canAckSales, canAckAccounts: false, canSupersede: false };
        const accOpts = { canAckSales: false, canAckAccounts: flags.canAckAccounts, canSupersede: flags.canCreateAccounts };

        async function loadSales() {
            const box = document.getElementById('salesInbox');
            if (!box) return;
            const res = await apiCall('/api/crm/handoffs?packet_type=sales_to_dispatch&current_only=1');
            const rows = res.data || [];
            if (!rows.length) {
                box.innerHTML = '<p class="text-muted">No current Sales→Dispatch packets.</p>';
                return;
            }
            box.innerHTML = rows.map(function (p) { return cardHtml(p, salesOpts); }).join('');
            rows.forEach(function (p, i) {
                bindCard(box.children[i], p, salesOpts, loadSales);
            });
        }

        async function loadAccounts() {
            const box = document.getElementById('accountsInbox');
            if (!box) return;
            const res = await apiCall('/api/crm/handoffs?packet_type=dispatch_to_accounts&current_only=1');
            const rows = res.data || [];
            if (!rows.length) {
                box.innerHTML = '<p class="text-muted">No current Dispatch→Accounts packets.</p>';
                return;
            }
            box.innerHTML = rows.map(function (p) { return cardHtml(p, accOpts); }).join('');
            rows.forEach(function (p, i) {
                bindCard(box.children[i], p, accOpts, loadAccounts);
            });
        }

        const createBtn = document.getElementById('btnCreateAccounts');
        if (createBtn) {
            createBtn.addEventListener('click', async function () {
                try {
                    await apiCall('/api/crm/handoffs', {
                        method: 'POST',
                        body: JSON.stringify({
                            packet_type: 'dispatch_to_accounts',
                            order_id: Number(document.getElementById('accOrderId').value),
                            dispatch_id: document.getElementById('accDispatchId').value || null,
                            payload: {
                                delivery_date: document.getElementById('accDeliveryDate').value,
                                invoice_reference: document.getElementById('accInvoice').value,
                                quote_reference: document.getElementById('accQuote').value,
                                agreed_terms: document.getElementById('accTerms').value
                            }
                        })
                    });
                    showBox('handoffNotice', 'Packet created.', false);
                    await loadAccounts();
                } catch (e) {
                    showBox('handoffError', e.message || 'Create failed', true);
                }
            });
        }

        try {
            await loadSales();
            await loadAccounts();
        } catch (e) {
            showBox('handoffError', e.message || 'Failed to load packets', true);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        mountDispatchPage();
        mountOrder();
    });

    window.HandoffUI = { mountDeal: mountDeal };
})();
