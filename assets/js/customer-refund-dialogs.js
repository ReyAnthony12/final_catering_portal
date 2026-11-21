(() => {
    'use strict';
    const dialog = document.getElementById('refundConfirmNotice');
    if (!dialog) return;
    let pendingForm = null, pendingButton = null;
    const approved = new WeakSet();
    document.addEventListener('submit', event => {
        const button = event.submitter;
        if (!button || button.name !== 'send_refund_request') return;
        if (approved.has(event.target)) { approved.delete(event.target); return; }
        event.preventDefault();
        pendingForm = event.target; pendingButton = button;
        if (!dialog.open) dialog.showModal();
    });
    dialog.querySelector('[data-refund-cancel]').addEventListener('click', () => dialog.close());
    dialog.querySelector('[data-refund-confirm]').addEventListener('click', () => {
        const form = pendingForm, button = pendingButton;
        if (!form?.isConnected || !button?.isConnected) { dialog.close(); return; }
        approved.add(form);
        dialog.close();
        form.requestSubmit(button);
    });
    dialog.addEventListener('close', () => {
        pendingButton?.focus({preventScroll:true});
        pendingForm = pendingButton = null;
    });
    const result = document.getElementById('refundResultNotice');
    if (result) {
        result.showModal();
        const url = new URL(location.href);
        url.searchParams.delete('refund_sent');
        history.replaceState(history.state, '', url.pathname + url.search + url.hash);
    }
})();
