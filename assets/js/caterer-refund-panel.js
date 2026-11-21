(() => {
    'use strict';
    const card = document.querySelector('.caterer-refund-card');
    const header = document.querySelector('.conversation-view .modern-modal-header');
    if (!card || !header) return;
    const marker = document.createComment('Refund card desktop location');
    card.before(marker);
    const column = marker.parentElement;
    const dialog = document.createElement('dialog');
    dialog.id = 'catererRefundPanel';
    dialog.className = 'sage-notice-dialog caterer-refund-dialog';
    dialog.setAttribute('aria-labelledby', 'catererRefundPanelTitle');
    dialog.innerHTML = '<div class="caterer-refund-heading"><h2 id="catererRefundPanelTitle">Refund request</h2><button type="button" aria-label="Close refund request">&#215;</button></div>';
    dialog.querySelector('button').addEventListener('click', () => dialog.close());
    const open = document.createElement('button');
    open.type = 'button';
    open.className = 'caterer-refund-open';
    open.textContent = 'Refund request';
    open.addEventListener('click', () => dialog.showModal());
    dialog.addEventListener('close', () => open.focus({preventScroll:true}));
    const mobile = matchMedia('(max-width:768px)');
    function arrange() {
        if (mobile.matches) {
            document.body.append(dialog);
            dialog.append(card);
            header.append(open);
            column.classList.toggle('refund-panel-empty', !column.querySelector('.modern-card'));
        } else {
            if (dialog.open) dialog.close();
            marker.after(card);
            column.classList.remove('refund-panel-empty');
            open.remove(); dialog.remove();
        }
    }
    mobile.addEventListener('change', arrange);
    arrange();
})();
