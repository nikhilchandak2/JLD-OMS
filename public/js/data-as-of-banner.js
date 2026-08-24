/**
 * Shared DataAsOfBanner. Mount on every screen that shows ledger- or dispatch-derived figures.
 * Amber = past deadline (late). Red = more than one business day old, or a missing entity.
 * Figures are never live — the copy always says so.
 */
(function () {
    const TONE_CLASS = {
        fresh: 'data-as-of-banner--fresh',
        late: 'data-as-of-banner--late',
        stale: 'data-as-of-banner--stale'
    };

    function render(el, payload) {
        if (!el || !payload) return;
        const tone = payload.tone || 'stale';
        el.className = 'data-as-of-banner ' + (TONE_CLASS[tone] || TONE_CLASS.stale);
        el.setAttribute('data-state', payload.state || '');
        el.setAttribute('role', 'status');
        const icon = tone === 'fresh' ? 'bi-check-circle' : (tone === 'late' ? 'bi-exclamation-triangle' : 'bi-exclamation-octagon');
        el.innerHTML = '<i class="bi ' + icon + ' me-2"></i><span>' + escape(payload.message || '') + '</span>';
        el.style.display = 'block';
    }

    function escape(value) {
        const div = document.createElement('div');
        div.textContent = String(value == null ? '' : value);
        return div.innerHTML;
    }

    async function load(el, options) {
        const feedKey = (options && options.feedKey) || el.getAttribute('data-feed-key') || 'ledger';
        const group = options && options.group !== undefined
            ? options.group
            : el.getAttribute('data-mode') !== 'company';
        const companyId = (options && options.companyId) || el.getAttribute('data-company-id') || '';
        const params = new URLSearchParams({ feed_key: feedKey, group: group ? '1' : '0' });
        if (companyId) params.set('company_id', companyId);

        if (typeof apiCall !== 'function') return;
        try {
            const response = await apiCall('/api/data-feeds/as-of?' + params.toString());
            render(el, response.data);
        } catch (e) {
            render(el, {
                tone: 'stale',
                state: 'missing',
                message: 'Data freshness could not be loaded. Treat any outstanding or dispatch figure as unverified — not live.'
            });
        }
    }

    function hydrateAll(root) {
        const scope = root || document;
        scope.querySelectorAll('.data-as-of-banner[data-feed-key]').forEach(function (el) {
            load(el);
        });
    }

    window.DataAsOfBanner = { render: render, load: load, hydrateAll: hydrateAll };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { hydrateAll(); });
    } else {
        hydrateAll();
    }
})();
