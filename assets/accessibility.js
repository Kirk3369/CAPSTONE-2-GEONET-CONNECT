/* Progressive keyboard and screen-reader support shared by all portals. */
(() => {
    const focusable = 'a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),summary,[tabindex="0"]';
    let serial = 0, scheduled = false, activeDialog = null, returnFocus = null, lastTrigger = null;
    const visible = node => node.getClientRects().length > 0 && !node.closest('[inert]');
    const names = { outageFilter:'Outage status', subSearch:'Search subscribers', subStatus:'Subscriber status', subBarangay:'Subscriber barangay', ticketPriority:'Ticket priority', ticketStatus:'Ticket status', mapSubscriber:'Subscriber connection', coverageAreaSelect:'Coverage area', workStatus:'Work order status' };
    function labelControls() {
        document.querySelectorAll('input:not([type=hidden]),select,textarea').forEach(input => {
            if (['hidden', 'submit', 'button', 'reset', 'image'].includes(input.type) || input.labels?.length || input.hasAttribute('aria-label') || input.hasAttribute('aria-labelledby')) return;
            // Resolve the label before assigning an internal ID. Generated IDs
            // are association targets, not user-facing field names.
            const fieldName = names[input.id] || input.placeholder || input.title || input.name
                || (/^field-accessible-/.test(input.id) ? '' : input.id);
            if (!fieldName) return;
            if (!input.id) input.id = 'field-accessible-' + ++serial;
            const label = document.createElement('label');
            label.htmlFor = input.id;
            label.className = 'control-label';
            label.textContent = fieldName.replace(/([a-z])([A-Z])/g,'$1 $2').replace(/[_-]/g,' ');
            input.before(label);
        });
    }
    function charts() {
        if (!window.Chart?.getChart) return;
        document.querySelectorAll('canvas').forEach(canvas => {
            const chart = Chart.getChart(canvas);
            if (!chart) return;
            const title = canvas.closest('.chart-box')?.querySelector('h3')?.textContent || 'Chart';
            canvas.setAttribute('role','img'); canvas.setAttribute('aria-label', title + '. Data table follows.');
            let details = canvas.parentElement.querySelector('[data-chart-table="' + canvas.id + '"]');
            const source = JSON.stringify([chart.data.labels,chart.data.datasets.map(d=>({label:d.label,data:d.data}))]);
            if (details?.dataset.source === source) return;
            if (!details) { details = document.createElement('details'); details.className = 'chart-data'; details.dataset.chartTable=canvas.id; canvas.after(details); }
            details.dataset.source = source;
            details.replaceChildren();
            const summary = document.createElement('summary'); summary.textContent = 'View '+title.toLowerCase()+' data'; details.appendChild(summary);
            const wrap = document.createElement('div'); wrap.className='table-wrap';
            const table = document.createElement('table'); const caption = document.createElement('caption'); caption.className='sr-only';caption.textContent=title;table.appendChild(caption);
            const header = document.createElement('tr');
            ['Period / category',...chart.data.datasets.map(d=>d.label || 'Value')].forEach(text=>{const th=document.createElement('th');th.scope='col';th.textContent=text;header.appendChild(th);});
            const head=document.createElement('thead');head.appendChild(header);table.appendChild(head);
            const body=document.createElement('tbody');
            (chart.data.labels || []).forEach((label,index)=>{const tr=document.createElement('tr');[label,...chart.data.datasets.map(d=>d.data[index]??'Unavailable')].forEach((text,i)=>{const cell=document.createElement(i===0?'th':'td');if(i===0)cell.scope='row';cell.textContent=String(text);tr.appendChild(cell);});body.appendChild(tr);});
            table.appendChild(body);wrap.appendChild(table);details.appendChild(wrap);
        });
    }
    function enhance() {
        scheduled=false;
        labelControls();
        document.querySelectorAll('nav').forEach(nav=>{if(!nav.hasAttribute('aria-label'))nav.setAttribute('aria-label','Main navigation');});
        document.querySelectorAll('.nav-item').forEach(link=>{if(link.classList.contains('active'))link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');});
        document.querySelectorAll('th:not([scope])').forEach(th=>th.scope='col');
        document.querySelectorAll('.table-wrap').forEach(wrap=>{if(wrap.scrollWidth>wrap.clientWidth){wrap.tabIndex=0;wrap.setAttribute('role','region');wrap.setAttribute('aria-label','Scrollable data table');}});
        document.querySelectorAll('i[class*="fa-"],.map-lines,.dot').forEach(icon=>icon.setAttribute('aria-hidden','true'));
        document.querySelectorAll('button').forEach(button=>{
            if(button.textContent.trim() || button.hasAttribute('aria-label') || button.hasAttribute('aria-labelledby'))return;
            const name=button.title || ({togglePassword:'Show password',cancelNapPlacement:'Cancel NAP placement'}[button.id]);
            if(name)button.setAttribute('aria-label',name);
        });
        document.querySelectorAll('tr[data-id],tr[data-sub-id]').forEach(row=>{
            const cell=row.cells?.[0];
            if(!cell || cell.querySelector('button,a,input,select'))return;
            const button=document.createElement('button');button.type='button';button.className='row-action';button.textContent=cell.textContent;button.setAttribute('aria-label','View details for '+cell.textContent.trim());cell.replaceChildren(button);
        });
        document.querySelectorAll('.toast').forEach(toast=>{toast.setAttribute('role','status');toast.setAttribute('aria-live','polite');});
        document.querySelectorAll('.user-chip[aria-label]').forEach(chip=>chip.setAttribute('aria-label',chip.textContent.trim()+' — open profile'));
        document.querySelectorAll('#inboxHistory,.work-order-list').forEach(list=>{list.tabIndex=0;list.setAttribute('role','region');list.setAttribute('aria-label',list.id==='inboxHistory'?'Incoming SMS reports':'Maintenance work orders');});
        charts();
        const open = Array.from(document.querySelectorAll('.modal-back.open,.record-modal-back.open')).filter(visible).pop();
        if(open && activeDialog !== open) {
            if(activeDialog) releaseDialog();
            activeDialog=open;returnFocus=lastTrigger || document.activeElement;
            const dialog=open.querySelector('[role=dialog],.modal,.record-modal') || open;
            dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');dialog.tabIndex=-1;
            const heading=dialog.querySelector('h2,h3');if(heading){if(!heading.id)heading.id='dialog-title-'+ ++serial;dialog.setAttribute('aria-labelledby',heading.id);}
            (Array.from(dialog.querySelectorAll(focusable)).find(visible)||dialog).focus();
            const app=document.querySelector('.app');if(app && !app.contains(open)) {app.inert=true;app.dataset.dialogInert='true';}
        } else if(!open && activeDialog) releaseDialog();
    }
    function releaseDialog() {
        document.querySelectorAll('[data-dialog-inert]').forEach(app=>{app.inert=false;delete app.dataset.dialogInert;});
        activeDialog=null;
        if(returnFocus?.isConnected && visible(returnFocus))returnFocus.focus();
        returnFocus=null;
    }
    document.addEventListener('pointerdown',event=>{if(!event.target.closest('.modal-back,.record-modal-back'))lastTrigger=event.target.closest('button,a,input,select') || document.activeElement;},true);
    document.addEventListener('keydown',event=>{
        if(!activeDialog && ['Enter',' '].includes(event.key)) lastTrigger=event.target.closest('button,a') || document.activeElement;
        if(!activeDialog || event.defaultPrevented)return;
        const nodes=Array.from(activeDialog.querySelectorAll(focusable)).filter(visible);
        if(event.key==='Tab') {
            if(!nodes.length){event.preventDefault();return;}
            if(event.shiftKey && (document.activeElement===nodes[0] || !activeDialog.contains(document.activeElement))){event.preventDefault();nodes.at(-1).focus();}
            else if(!event.shiftKey && (document.activeElement===nodes.at(-1) || !activeDialog.contains(document.activeElement))){event.preventDefault();nodes[0].focus();}
        }
        if(event.key==='Escape') {
            const cancel=Array.from(activeDialog.querySelectorAll('button')).find(button=>/cancel|close/i.test(button.textContent+' '+button.getAttribute('aria-label')));
            if(cancel){event.preventDefault();cancel.click();}
        }
    });
    document.addEventListener('invalid',event=>{
        const input=event.target;if(!input.matches('input,select,textarea'))return;
        input.setAttribute('aria-invalid','true');
        if(!input.id)input.id='invalid-'+ ++serial;
        const id=input.id+'-error';let error=document.getElementById(id);
        if(!error){error=document.createElement('span');error.id=id;error.className='field-error';input.after(error);}
        error.textContent=input.validationMessage;
        const described=new Set((input.getAttribute('aria-describedby')||'').split(' ').filter(Boolean));described.add(id);input.setAttribute('aria-describedby',Array.from(described).join(' '));
    },true);
    document.addEventListener('input',event=>{
        const input=event.target;
        if(input.getAttribute('aria-invalid')==='true' && input.validity?.valid){input.removeAttribute('aria-invalid');const error=document.getElementById(input.id+'-error');if(error)error.textContent='';}
    });
    function init() {
        const main=document.querySelector('main,.content,.right-panel,.inbox-page');
        if(main){if(!main.id)main.id='main-content';main.tabIndex=-1;if(main.tagName!=='MAIN')main.setAttribute('role','main');const skip=document.createElement('a');skip.href='#'+main.id;skip.className='skip-link';skip.textContent='Skip to main content';document.body.prepend(skip);}
        document.querySelectorAll('.error-message').forEach(error=>error.setAttribute('role','alert'));
        ['signupMap','connectionMap'].forEach(id=>{
            const map=document.getElementById(id);if(!map)return;
            const details=document.createElement('details');details.className='map-coordinate-tools';
            details.innerHTML=`<summary>Enter service coordinates instead of clicking the map</summary><p>Select a barangay and NAP box first. Coordinates must be inside its service area.</p><div class="map-coordinate-fields"><label>Latitude<input type="number" step="any" min="-90" max="90" data-lat></label><label>Longitude<input type="number" step="any" min="-180" max="180" data-lng></label><button type="button" class="btn btn-outline">Set service location</button></div><p role="status"></p>`;
            map.after(details);
            details.querySelector('button').addEventListener('click',()=>{
                const lat=details.querySelector('[data-lat]'),lng=details.querySelector('[data-lng]');
                if(!lat.value||!lng.value||!lat.reportValidity()||!lng.reportValidity()){details.querySelector('[role=status]').textContent='Enter valid coordinates.';return;}
                map.dispatchEvent(new CustomEvent('geonet:location',{detail:{lat:Number(lat.value),lng:Number(lng.value)}}));
                details.querySelector('[role=status]').textContent='Location submitted for validation. Check the service location status above.';
            });
        });
        enhance();
        const observer=new MutationObserver(records=>{
            if(records.every(r=>r.type==='attributes' && !['class','hidden'].includes(r.attributeName)))return;
            if(!scheduled){scheduled=true;requestAnimationFrame(enhance);}
        });observer.observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['class','hidden']});
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
