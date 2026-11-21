document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.admin-workspace .finance-table:not(.workspace-records), .admin-workspace #usersTable').forEach(table => {
        const labels = Array.from(table.querySelectorAll('thead th'), header => header.textContent.trim());
        table.querySelectorAll('tbody > tr').forEach(row => {
            Array.from(row.cells).forEach((cell, index) => {
                if (cell.colSpan === 1) cell.dataset.label = labels[index] || '';
            });
        });
        table.classList.add('workspace-records');
    });
    document.querySelectorAll('.admin-workspace .report-card-header').forEach(header => {
        const card = header.closest('.report-card');
        const body = card.querySelector('.report-card-body');
        if (!body) return;
        body.id = `${card.id}-details`;
        header.setAttribute('role', 'button');
        header.tabIndex = 0;
        header.setAttribute('aria-controls', body.id);
        const sync = () => {
            const expanded = card.classList.contains('is-open');
            header.setAttribute('aria-expanded', String(expanded));
            body.inert = !expanded;
        };
        sync();
        new MutationObserver(sync).observe(card, { attributes: true, attributeFilter: ['class'] });
        header.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                header.click();
            }
        });
    });
});
