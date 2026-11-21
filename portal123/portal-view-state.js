/* Remember view choices only; account actions and form submissions are never replayed. */
(() => {
    const user = document.currentScript?.dataset.user || '0';
    const params = new URLSearchParams(location.search);
    params.sort();
    const key = `portal-view:${user}:${location.pathname}?${params}`;
    function save() {
        const workspace = document.querySelector('.main-content');
        const settings = document.querySelector('.settings-section-button[aria-expanded="true"]');
        const state = {
            scroll: workspace?.scrollTop || 0,
            sidebar: document.querySelector('.sidebar-menu')?.scrollTop || 0,
            settings: settings?.getAttribute('aria-controls') || null,
            accounts: [...document.querySelectorAll('tr.is-expanded[data-account-id]')].map(row => row.dataset.accountId),
            reports: [...document.querySelectorAll('[id^="report-details-"]')].filter(row => !row.hidden).map(row => row.id),
            cards: [...document.querySelectorAll('.report-card.is-open[id]')].map(card => card.id),
            details: [...document.querySelectorAll('.main-content details[open][id]')].map(item => item.id),
            tables: [...document.querySelectorAll('.main-content .table-container, .main-content .finance-table-wrap')].map(item => item.scrollLeft)
        };
        try { sessionStorage.setItem(key, JSON.stringify(state)); } catch (_) {}
    }
    function restore() {
        let state;
        try { state = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (_) {}
        if (!state) return;
        if (state.settings && typeof openSettingsPanel === 'function') openSettingsPanel(state.settings);
        const accounts = new Set(state.accounts || []);
        document.querySelectorAll('tr[data-account-id]').forEach(row => {
            const open = accounts.has(row.dataset.accountId);
            row.classList.toggle('is-expanded', open);
            const toggle = row.querySelector('.account-name-toggle');
            toggle?.setAttribute('aria-expanded', String(open));
            (toggle?.getAttribute('aria-controls') || '').split(/\s+/).filter(Boolean).forEach(id => {
                document.getElementById(id)?.setAttribute('aria-hidden', String(!open));
            });
        });
        (state.reports || []).forEach(id => {
            const details = document.getElementById(id);
            if (!details) return;
            details.hidden = false;
            details.previousElementSibling?.classList.add('is-expanded');
            details.previousElementSibling?.querySelectorAll('[aria-expanded]').forEach(button => button.setAttribute('aria-expanded', 'true'));
        });
        (state.cards || []).forEach(id => document.getElementById(id)?.classList.add('is-open'));
        (state.details || []).forEach(id => {
            const details = document.getElementById(id);
            if (details?.tagName === 'DETAILS') details.open = true;
        });
        requestAnimationFrame(() => {
            const workspace = document.querySelector('.main-content');
            const sidebar = document.querySelector('.sidebar-menu');
            if (workspace) workspace.scrollTop = state.scroll || 0;
            if (sidebar) sidebar.scrollTop = state.sidebar || 0;
            document.querySelectorAll('.main-content .table-container, .main-content .finance-table-wrap').forEach((item, index) => { item.scrollLeft = state.tables?.[index] || 0; });
        });
    }
    window.addEventListener('load', restore, {once: true});
    window.addEventListener('pagehide', save);
    document.addEventListener('visibilitychange', () => { if (document.hidden) save(); });
})();
