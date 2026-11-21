(() => {
    const popup = document.createElement('div');
    popup.className = 'report-safe-picker';
    popup.hidden = true;
    popup.setAttribute('role', 'dialog');
    document.body.append(popup);
    let trigger;
    function close() { popup.hidden = true; trigger?.focus(); }
    function show(button) {
        trigger = button;
        popup.hidden = false;
        const rect = button.getBoundingClientRect();
        const width = Math.min(320, window.innerWidth - 24);
        popup.style.width = width + 'px';
        popup.style.left = Math.max(12, Math.min(rect.left, window.innerWidth - width - 12)) + 'px';
        popup.style.top = Math.max(12, Math.min(rect.bottom + 6, window.innerHeight - Math.min(popup.scrollHeight, window.innerHeight - 24) - 12)) + 'px';
        popup.querySelector('button')?.focus();
    }
    function button(label, action) {
        const el = document.createElement('button'); el.type = 'button'; el.textContent = label;
        el.addEventListener('click', action); return el;
    }
    document.querySelectorAll('.reports-page input[type="date"]').forEach(input => {
        input.type = 'hidden';
        const control = button('', () => { render(); show(control); });
        control.className = 'report-picker-control';
        control.setAttribute('aria-label', input.id === 'start_date' ? 'From date' : 'To date');
        control.setAttribute('aria-haspopup', 'dialog');
        input.after(control);
        function update() { control.textContent = input.value || 'Select date'; }
        let month;
        function render() {
            if (!month) { const parts = input.value.split('-').map(Number); month = parts.length === 3 ? new Date(parts[0],parts[1]-1,1) : new Date(new Date().getFullYear(),new Date().getMonth(),1); }
            popup.replaceChildren();
            const header = document.createElement('div'); header.className = 'report-calendar-heading';
            header.append(button('‹', () => { month.setMonth(month.getMonth()-1); render(); }), document.createTextNode(month.toLocaleDateString('en-US',{month:'long',year:'numeric'})), button('›', () => { month.setMonth(month.getMonth()+1); render(); }));
            popup.append(header);
            const grid = document.createElement('div'); grid.className = 'report-calendar-grid';
            ['Su','Mo','Tu','We','Th','Fr','Sa'].forEach(day => {const el = document.createElement('span'); el.textContent=day;grid.append(el);});
            for(let i=0;i<month.getDay();i++) grid.append(document.createElement('span'));
            for(let day=1;day<=new Date(month.getFullYear(),month.getMonth()+1,0).getDate();day++) {
                const value = month.getFullYear()+'-'+String(month.getMonth()+1).padStart(2,'0')+'-'+String(day).padStart(2,'0');
                const item=button(String(day),()=>{input.value=value;update();input.dispatchEvent(new Event('change',{bubbles:true}));close();});
                if(value===input.value)item.className='selected';grid.append(item);
            }
            popup.append(grid,button('Close',close));
        }
        update();
    });
    document.querySelectorAll('.report-picker select').forEach(select => {
        select.hidden = true;
        const control=button('',()=>{
            if (!popup.hidden && trigger === control) { close(); return; }
            popup.replaceChildren();
            Array.from(select.options).forEach(option=>{const item=button(option.textContent,()=>{select.value=option.value;select.dispatchEvent(new Event('change',{bubbles:true}));update();close();});if(option.selected)item.className='selected';popup.append(item);});
            show(control);
        });
        control.className='report-picker-control';control.setAttribute('aria-label','View report');control.setAttribute('aria-haspopup','dialog');select.after(control);
        function update(){control.textContent='View report ▾'; control.title=select.selectedOptions[0]?.textContent || 'View report';}
        select.addEventListener('change',update);update();
        const filters = document.querySelector('.reports-page .filter-bar');
        if (filters) { filters.append(control); select.closest('.report-picker').hidden = true; }
    });
    document.addEventListener('pointerdown',event=>{if(!popup.hidden&&!popup.contains(event.target)&&event.target!==trigger)popup.hidden=true;});
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!popup.hidden)close();});
    window.addEventListener('resize',()=>{popup.hidden=true;});
})();
