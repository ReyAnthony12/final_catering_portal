(() => {
    const date = document.getElementById('rescheduleDate');
    const time = document.querySelector('[name="requested_order_time"]');
    if (!date || !time) return;
    const pickers = [];
    function shell(select, placeholder) {
        const wrap = document.createElement('div');
        wrap.className = 'reschedule-picker';
        select.before(wrap);
        wrap.append(select);
        select.classList.add('reschedule-picker-source');
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'reschedule-picker-trigger';
        trigger.textContent = placeholder;
        trigger.setAttribute('aria-expanded', 'false');
        const panel = document.createElement('div');
        panel.className = 'reschedule-picker-panel';
        panel.hidden = true;
        wrap.append(trigger, panel);
        const close = () => { panel.hidden = true; trigger.setAttribute('aria-expanded','false'); };
        trigger.onclick = () => {
            const opening = panel.hidden;
            pickers.forEach(p => p.close());
            if (opening) { render(); panel.hidden = false; trigger.setAttribute('aria-expanded','true'); }
        };
        function sync() { trigger.textContent = select.value ? select.selectedOptions[0].textContent : placeholder; }
        function choose(value) { select.value = value; select.dispatchEvent(new Event('change',{bubbles:true})); sync(); close(); trigger.focus(); }
        let render = () => {};
        select.addEventListener('change',sync);
        select.addEventListener('invalid', () => { close(); trigger.focus(); });
        const picker = {wrap, panel, trigger, close, choose, setRender(fn) { render = fn; }};
        pickers.push(picker);
        return picker;
    }
    const calendar = shell(date, 'Select your event date');
    let month = null;
    calendar.setRender(() => {
        const options = Array.from(date.options).filter(o => o.value && !o.disabled);
        if (!month) month = new Date((date.value || options[0]?.value || new Date().toISOString().slice(0,10)) + 'T12:00:00');
        month.setDate(1);
        calendar.panel.replaceChildren();
        const header = document.createElement('div'); header.className = 'reschedule-calendar-header';
        const title = document.createElement('strong'); title.textContent = month.toLocaleDateString('en-US',{month:'long',year:'numeric'});
        const nav = document.createElement('div');
        [-1,1].forEach(delta => {
            const button = document.createElement('button'); button.type = 'button'; button.textContent = delta < 0 ? '‹' : '›';
            button.setAttribute('aria-label',delta < 0 ? 'Previous month' : 'Next month');
            button.onclick = () => { month.setMonth(month.getMonth()+delta); calendar.trigger.click(); calendar.trigger.click(); };
            nav.append(button);
        });
        header.append(title,nav); calendar.panel.append(header);
        const grid = document.createElement('div'); grid.className = 'reschedule-calendar-grid';
        ['Su','Mo','Tu','We','Th','Fr','Sa'].forEach(day => { const label = document.createElement('span'); label.textContent = day; grid.append(label); });
        for (let i=0;i<month.getDay();i++) grid.append(document.createElement('span'));
        const available = new Set(options.map(o => o.value));
        const count = new Date(month.getFullYear(),month.getMonth()+1,0).getDate();
        for (let day=1;day<=count;day++) {
            const key = month.getFullYear()+'-'+String(month.getMonth()+1).padStart(2,'0')+'-'+String(day).padStart(2,'0');
            const button = document.createElement('button'); button.type='button'; button.textContent=day;
            button.disabled = !available.has(key); button.classList.toggle('selected',date.value===key);
            button.setAttribute('aria-label',new Date(key+'T12:00:00').toLocaleDateString('en-US',{dateStyle:'full'}));
            button.onclick=()=>calendar.choose(key); grid.append(button);
        }
        calendar.panel.append(grid);
    });
    const clock = shell(time,'Select event time');
    clock.setRender(() => {
        clock.panel.replaceChildren();
        const heading=document.createElement('strong'); heading.textContent='Available event times'; clock.panel.append(heading);
        const list=document.createElement('div'); list.className='reschedule-time-options';
        Array.from(time.options).filter(o=>o.value).forEach(option=>{
            const button=document.createElement('button'); button.type='button'; button.textContent=option.textContent;
            button.classList.toggle('selected',time.value===option.value); button.onclick=()=>clock.choose(option.value); list.append(button);
        });
        clock.panel.append(list);
    });
    new MutationObserver(()=>{ if (!calendar.panel.hidden) { calendar.trigger.click(); calendar.trigger.click(); } }).observe(date,{childList:true});
    document.addEventListener('click',event=>pickers.forEach(p=>{if(!p.wrap.contains(event.target))p.close();}));
    document.addEventListener('keydown',event=>{if(event.key==='Escape')pickers.forEach(p=>p.close());});
})();
