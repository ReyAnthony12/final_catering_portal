(() => {
    let active;
    const popup=document.createElement('div');popup.className='review-month-picker';popup.hidden=true;popup.setAttribute('role','dialog');popup.setAttribute('aria-label','Choose month');document.body.append(popup);
    const make=(text,action)=>{const b=document.createElement('button');b.type='button';b.textContent=text;b.addEventListener('click',action);return b;};
    function close(){popup.hidden=true;active?.focus();}
    document.querySelectorAll('.review-filter-form input[type="month"]').forEach(input=>{
        input.type='hidden';
        let year=Number(input.value.slice(0,4))||new Date().getFullYear();
        const control=make('',()=>{year=Number(input.value.slice(0,4))||new Date().getFullYear();render();active=control;popup.hidden=false;position();popup.querySelector('button').focus();});
        control.className='review-month-control';control.setAttribute('aria-haspopup','dialog');
        const label=document.querySelector('label[for="'+input.id+'"]');if(label){control.id=input.id+'-picker';label.htmlFor=control.id;control.setAttribute('aria-label',label.textContent);}
        input.after(control);
        function update(){if(input.value){const [y,m]=input.value.split('-').map(Number);control.textContent=new Date(y,m-1,1).toLocaleDateString('en-US',{month:'short',year:'numeric'});}else control.textContent='Select month';}
        function position(){const r=control.getBoundingClientRect();const w=Math.min(300,innerWidth-24);popup.style.width=w+'px';popup.style.left=Math.max(12,Math.min(r.left,innerWidth-w-12))+'px';popup.style.top=Math.max(12,Math.min(r.bottom+6,innerHeight-popup.offsetHeight-12))+'px';}
        function choose(value){input.value=value;update();input.dispatchEvent(new Event('change',{bubbles:true}));close();}
        function render(){popup.replaceChildren();const heading=document.createElement('div');heading.className='review-month-heading';heading.append(make('‹',()=>{year--;render();}),document.createTextNode(String(year)),make('›',()=>{year++;render();}));popup.append(heading);const grid=document.createElement('div');grid.className='review-month-grid';for(let m=0;m<12;m++){const value=year+'-'+String(m+1).padStart(2,'0');const item=make(new Date(year,m,1).toLocaleDateString('en-US',{month:'short'}),()=>choose(value));if(value===input.value)item.className='selected';grid.append(item);}const footer=document.createElement('div');footer.className='review-month-footer';footer.append(make('Clear',()=>choose('')),make('This month',()=>{const d=new Date();choose(d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0'));}));popup.append(grid,footer);}
        update();
    });
    document.addEventListener('pointerdown',e=>{if(!popup.hidden&&!popup.contains(e.target)&&e.target!==active)popup.hidden=true;});
    document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!popup.hidden)close();});
    window.addEventListener('resize',()=>popup.hidden=true);
})();
