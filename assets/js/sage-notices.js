(() => {
    'use strict';
    document.querySelectorAll('dialog[data-auto-notice]').forEach(notice => {
        notice.querySelectorAll('[data-notice-close]').forEach(button => button.addEventListener('click', () => notice.close()));
        notice.showModal();
        const parameter = notice.dataset.clearParameter;
        if (parameter) {
            const url = new URL(location.href);
            url.searchParams.delete(parameter);
            history.replaceState(history.state, '', url.pathname + url.search + url.hash);
        }
    });
    const forms = document.querySelectorAll('form[data-confirm-message]');
    if (!forms.length) return;
    const dialog = document.createElement('dialog');
    dialog.className = 'sage-notice-dialog';
    dialog.id = 'sageActionConfirm';
    dialog.setAttribute('aria-labelledby', 'sageActionTitle');
    dialog.innerHTML = '<h2 id="sageActionTitle"></h2><p></p><div class="sage-notice-actions"><button type="button" class="sage-notice-secondary" data-action-cancel>Cancel</button><button type="button" data-action-confirm>Confirm</button></div>';
    document.body.append(dialog);
    const approved = new WeakSet();
    let pending = null;
    forms.forEach(form => form.addEventListener('submit', event => {
        if (approved.has(form)) { approved.delete(form); return; }
        event.preventDefault();
        pending = {form, button:event.submitter};
        dialog.querySelector('h2').textContent = form.dataset.confirmTitle || 'Confirm action';
        dialog.querySelector('p').textContent = form.dataset.confirmMessage;
        dialog.querySelector('[data-action-confirm]').textContent = form.dataset.confirmButton || 'Confirm';
        if (!dialog.open) dialog.showModal();
    }));
    dialog.querySelector('[data-action-cancel]').addEventListener('click', () => dialog.close());
    dialog.querySelector('[data-action-confirm]').addEventListener('click', () => {
        const action = pending;
        if (!action?.form.isConnected) { dialog.close(); return; }
        approved.add(action.form);
        dialog.close();
        if (action.button) action.form.requestSubmit(action.button);
        else action.form.requestSubmit();
    });
    dialog.addEventListener('close', () => { pending?.button?.focus({preventScroll:true}); pending = null; });
})();
