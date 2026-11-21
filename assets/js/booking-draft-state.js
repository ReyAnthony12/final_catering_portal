(function (root) {
    'use strict';
    function resetForNewVisit(storage, productId, userId, requestMethod, navigationType, origin) {
        if (requestMethod !== 'GET' || navigationType !== 'navigate') return false;
        try {
            const product = String(productId);
            ['step', 'inputs', 'addons', 'swaps', 'build_menu', 'custom_addon'].forEach(part => {
                storage.removeItem('booking_' + part + '_' + product);
            });
            const prefix = 'booking_view_' + userId + '_';
            const views = [];
            for (let i = 0; i < storage.length; i++) {
                const key = storage.key(i);
                if (!key?.startsWith(prefix)) continue;
                const url = new URL(key.slice(prefix.length), origin);
                if (url.searchParams.get('product_id') === product) views.push(key);
            }
            views.forEach(key => storage.removeItem(key));
            return true;
        } catch (_) {
            // Booking remains usable when browser storage is unavailable.
            return false;
        }
    }
    const api = {resetForNewVisit};
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.BookingDraftState = api;
})(typeof window === 'object' ? window : globalThis);
