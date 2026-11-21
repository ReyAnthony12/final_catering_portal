(() => {
    let busy = false;
    let currentGroups = {};
    let token = '';
    let orderCustomers = {};
    let loaded = false;
    const earlyClicks = new Set();
    const pendingReads = new Map();
    const detailId = location.pathname.endsWith('/payments.php') ? Number(new URLSearchParams(location.search).get('id')) : 0;
    function dot(parent, show) {
        if (!parent) return;
        let badge = parent.querySelector(':scope > .payment-unread-dot');
        if (!show) { if (badge) badge.remove(); return; }
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'nav-alert-dot payment-unread-dot';
            badge.style.cssText = 'display:inline-block;flex-shrink:0;width:9px;height:9px;border-radius:50%;background:#ff4d61;margin-left:8px;box-shadow:0 0 0 3px rgba(255,77,97,.12)';
            badge.title = 'Unread payment update';
            badge.setAttribute('aria-label', badge.title);
            parent.appendChild(badge);
        }
    }
    function render(groups) {
        currentGroups = groups;
        const visibleGroups = {};
        Object.entries(groups).forEach(([id, ids]) => {
            const unread = ids.filter(notice => !pendingReads.get(id)?.has(notice));
            if (unread.length) visibleGroups[id] = unread;
        });
        document.querySelectorAll('a[href="payments.php"]').forEach(link => {
            link.querySelectorAll('.nav-alert-dot:not(.payment-unread-dot)').forEach(el => el.remove());
            dot(link, Object.keys(visibleGroups).length > 0);
        });
        document.querySelectorAll('.payment-booking').forEach(card => {
            const link = card.querySelector('a[href*="payments.php?"]');
            const id = link ? new URL(link.href).searchParams.get('id') : '';
            dot(card.querySelector('.booking-top'), Boolean(visibleGroups[id]?.length));
        });
        document.querySelectorAll('details.payment-customer').forEach(customer => {
            // Customer headings already display a numeric payment badge.
            dot(customer.querySelector('summary .customer-heading'), false);
        });
    }
    function readCustomer(customerId) {
        Object.entries(currentGroups).forEach(([id, ids]) => {
            if (String(orderCustomers[id]) !== customerId) return;
            if (!pendingReads.has(id)) pendingReads.set(id, new Set());
            ids.forEach(notice => pendingReads.get(id).add(notice));
        });
    }
    async function refresh() {
        if (busy || document.hidden) return;
        busy = true;
        let followUp = false;
        try {
            if (pendingReads.size && token) {
                const snapshot = new Map(Array.from(pendingReads, ([id, ids]) => [id, new Set(ids)]));
                const saved = await fetch('../api/payment-notifications.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({order_ids: [...snapshot.keys()], ids: [...snapshot.values()].flatMap(ids => [...ids]), token})
                });
                const result = await saved.json();
                if (!saved.ok || !result.success) throw new Error('Unable to save payment read state');
                snapshot.forEach((ids, id) => {
                    ids.forEach(notice => pendingReads.get(id)?.delete(notice));
                    if (!pendingReads.get(id)?.size) pendingReads.delete(id);
                });
            }
            let response = await fetch('../api/payment-notifications.php', {cache: 'no-store'});
            let data = await response.json();
            if (!data.success) return;
            token = data.token;
            orderCustomers = data.order_customers || {};
            currentGroups = data.groups;
            loaded = true;
            earlyClicks.forEach(readCustomer);
            earlyClicks.clear();
            followUp = pendingReads.size > 0;
            // Acknowledge only the IDs actually received for this open booking.
            if (detailId && data.groups[detailId]?.length) {
                response = await fetch('../api/payment-notifications.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({order_id: detailId, ids: data.groups[detailId], token: data.token})
                });
                data = await response.json();
            }
            if (data.success) render(data.groups);
        } catch (error) { console.error('Unable to refresh payment badges', error); }
        finally {
            busy = false;
            if (followUp) refresh();
        }
    }
    // Clicking a customer acknowledges only the notifications already shown
    // for that customer's bookings, leaving later arrivals unread.
    document.addEventListener('click', event => {
        const summary = event.target.closest('details.payment-customer > summary');
        if (!summary) return;
        const customerId = summary.parentElement.dataset.customerId;
        if (!customerId) return;
        if (!loaded) earlyClicks.add(customerId);
        else readCustomer(customerId);
        render(currentGroups);
        refresh();
    });
    document.addEventListener('DOMContentLoaded', refresh);
    document.addEventListener('visibilitychange', refresh);
    window.addEventListener('focus', refresh);
    setInterval(refresh, 2000);
})();
