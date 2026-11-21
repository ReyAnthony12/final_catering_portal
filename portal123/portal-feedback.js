/* Native dialogs provide keyboard focus trapping, Escape and focus restoration. */
(() => {
    const notificationStateKey = 'portal-notifications-open:' + (document.currentScript?.dataset.user || '0');
    function saveNotificationOpen(open) {
        try {
            if (open) sessionStorage.setItem(notificationStateKey, '1');
            else sessionStorage.removeItem(notificationStateKey);
        } catch (_) {}
    }
    const queue = [];
    let showing = false;
    function showNext() {
        if (showing || !queue.length || !document.body) return;
        showing = true;
        const {message, confirmation, resolve} = queue.shift();
        const dialog = document.createElement('dialog');
        dialog.className = 'portal-dialog';
        dialog.innerHTML = '<div class="portal-dialog-heading"><h2 id="portal-feedback-title"></h2></div><div class="portal-dialog-copy" id="portal-feedback-copy"></div><div class="portal-dialog-actions"></div>';
        dialog.setAttribute('aria-labelledby', 'portal-feedback-title');
        dialog.setAttribute('aria-describedby', 'portal-feedback-copy');
        dialog.querySelector('h2').textContent = confirmation ? 'Confirm action' : 'Notification';
        dialog.querySelector('.portal-dialog-copy').textContent = String(message);
        const actions = dialog.querySelector('.portal-dialog-actions');
        if (confirmation) {
            const cancel = document.createElement('button');
            cancel.textContent = 'Cancel'; cancel.className = 'portal-dialog-cancel';
            cancel.onclick = () => dialog.close(); actions.append(cancel);
        }
        const ok = document.createElement('button');
        ok.textContent = confirmation ? 'Confirm' : 'OK';
        ok.onclick = () => dialog.close('confirmed'); actions.append(ok);
        dialog.addEventListener('close', () => {
            const result = dialog.returnValue === 'confirmed';
            dialog.remove(); showing = false; resolve(result); showNext();
        }, {once: true});
        document.body.append(dialog); dialog.showModal();
        (confirmation ? actions.firstElementChild : ok).focus();
    }
    function feedback(message, confirmation = false) {
        return new Promise(resolve => { queue.push({message, confirmation, resolve}); showNext(); });
    }
    window.alert = message => { feedback(message); };
    window.portalConfirm = message => feedback(message, true);
    const approved = new WeakSet();
    window.portalConfirmForm = (event, form, message) => {
        if (approved.has(form)) { approved.delete(form); return true; }
        event.preventDefault(); const submitter = event.submitter;
        portalConfirm(message).then(ok => { if (ok) { approved.add(form); form.requestSubmit(submitter || undefined); } });
        return false;
    };
    window.portalConfirmButton = (event, button, message) => {
        if (approved.has(button)) { approved.delete(button); return true; }
        event.preventDefault();
        portalConfirm(message).then(ok => { if (ok) { approved.add(button); button.click(); } });
        return false;
    };
    window.openPortalNotifications = () => {
        let dialog = document.getElementById('portal-notifications-dialog');
        if (!dialog) {
            dialog = document.createElement('dialog'); dialog.id = 'portal-notifications-dialog'; dialog.className = 'portal-dialog';
            dialog.setAttribute('aria-labelledby', 'portal-notifications-title');
            dialog.innerHTML = '<div class="portal-dialog-heading"><h2 id="portal-notifications-title">Notifications</h2><button type="button" aria-label="Close notifications">Close</button></div>';
            dialog.querySelector('button').onclick = () => { saveNotificationOpen(false); dialog.close(); };
            dialog.append(document.getElementById('portalNotifDropdown')); document.body.append(dialog);
            dialog.addEventListener('cancel', event => event.preventDefault());
        }
        if (!dialog.open) { dialog.showModal(); saveNotificationOpen(true); loadPortalNotifications(); }
    };
    document.addEventListener('DOMContentLoaded', () => {
        try {
            if (sessionStorage.getItem(notificationStateKey) === '1') window.openPortalNotifications();
        } catch (_) {}
        showNext();
        document.querySelectorAll('.main-content > .alert, .main-content .alert-success, .main-content .alert-error, .ur-alert, .pricing-message').forEach(notice => {
            if (!notice.querySelector('form, input, button, a') && notice.textContent.trim()) {
                feedback(notice.textContent.trim()); notice.hidden = true; notice.style.setProperty('display', 'none', 'important');
            }
        });
    });
})();
