window.GeoNetOperations = (() => {
    const escape = value => String(value ?? '').replace(/[&<>"']/g, ch => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ch]));
    let directory = null, analytics = null, charts = [], initialized = false, requestVersion = 0;
    const el = id => document.getElementById(id);
    async function request(query = '', body = null) {
        const response = await fetch('api/maintenance.php' + query, body ? {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type':'application/json' },
            body: JSON.stringify({ ...body, csrfToken: el('profileCsrfToken').value })
        } : { credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Could not load operations data.');
        return data;
    }
    const options = (items, placeholder) => `<option value="">${placeholder}</option>` + items.map(item => `<option value="${escape(item.id)}">${escape(item.name)}</option>`).join('');
    function relatedOptions() {
        const id = el('workBarangay').value;
        el('workNap').innerHTML = options(directory.naps.filter(n => String(n.barangay_id) === id), 'No specific NAP box');
        el('workInterruption').innerHTML = options(directory.interruptions.filter(i => String(i.barangay_id) === id).map(i => ({ id: i.id, name: `Report #${i.id} / ${i.public_id}` })), 'No linked interruption');
    }
    async function loadOrders() {
        setup();
        el('workFeedback').textContent = 'Loading work orders...';
        try {
            const data = await request();
            const priorBarangay = el('workBarangay').value, priorTech = el('workTechnician').value;
            const priorNap = el('workNap').value, priorReport = el('workInterruption').value;
            directory = data;
            el('workBarangay').innerHTML = options(data.barangays, 'Choose barangay');
            el('workTechnician').innerHTML = options(data.technicians, 'Assign technician');
            el('workBarangay').value = priorBarangay; el('workTechnician').value = priorTech;
            relatedOptions(); el('workNap').value = priorNap; el('workInterruption').value = priorReport;
            renderOrders(); el('workFeedback').textContent = '';
        } catch (error) { el('workFeedback').textContent = error.message; }
    }
    function renderOrders() {
        const orders = directory.orders, filter = el('workStatus').value;
        el('workSummary').textContent = `${orders.filter(o => Number(o.overdue)).length} overdue / ${orders.filter(o => o.status !== 'completed').length} unfinished`;
        el('workOrderList').innerHTML = orders.filter(o => !filter || (filter === 'overdue' ? Number(o.overdue) : o.status === filter)).map(order => `
            <article class="work-order ${Number(order.overdue) ? 'work-overdue' : ''}">
                <div class="panel-head"><h4>#${escape(order.id)} ${escape(order.title)}</h4><span>${escape(order.status.replaceAll('_',' '))}${Number(order.overdue) ? ' / Overdue' : ''}</span></div>
                <p>${escape(order.barangay)} / ${escape(order.nap_box || 'All areas')} / ${escape(order.priority)} priority</p>
                <p>Assigned: ${escape(order.technician)} / Due: ${escape(order.due_at)} (Manila)</p>
                ${order.interruption_id ? `<a href="interruptions.php">Linked interruption #${escape(order.interruption_id)}</a>` : ''}
                ${order.status === 'completed' ? `<p>Completed: ${escape(order.completed_at)}</p><p class="work-note">${escape(order.findings)}</p><p class="work-note">Materials: ${escape(order.materials || 'None recorded')}</p>` : `
                <form class="work-update" data-work-id="${escape(order.id)}">
                    <label>Findings / work performed<textarea name="findings" maxlength="4000" ${order.status === 'in_progress' ? 'required' : ''}>${escape(order.findings)}</textarea></label>
                    <label>Materials used<textarea name="materials" maxlength="4000">${escape(order.materials)}</textarea></label>
                    <button class="btn btn-outline" type="submit">${order.status === 'scheduled' ? 'Start work' : 'Complete work'}</button>
                </form>`}
            </article>`).join('') || '<p class="side-meta">No work orders match this status.</p>';
        el('workOrderList').querySelectorAll('.work-update').forEach(form => form.addEventListener('submit', async event => {
            event.preventDefault();
            const order = orders.find(o => String(o.id) === form.dataset.workId), button = form.querySelector('button');
            button.disabled = true;
            try {
                await request('', { action:'update', id:order.id, status:order.status === 'scheduled' ? 'in_progress' : 'completed', ...Object.fromEntries(new FormData(form)) });
                await loadOrders();
            } catch (error) { el('workFeedback').textContent = error.message; }
            finally { button.disabled = false; }
        }));
    }
    const table = (headers, rows) => `<table><thead><tr>${headers.map(h => `<th>${escape(h)}</th>`).join('')}</tr></thead><tbody>${rows.length ? rows.map(row => `<tr>${row.map(cell => `<td>${escape(cell)}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${headers.length}">No records available.</td></tr>`}</tbody></table>`;
    async function loadAnalytics() {
        setup();
        const version = ++requestVersion;
        el('analyticsFeedback').textContent = 'Loading report...'; el('analyticsExport').disabled = true;
        try {
            const data = await request('?action=analytics&' + new URLSearchParams({from:el('analyticsFrom').value,to:el('analyticsTo').value}));
            if (version !== requestVersion) return;
            analytics = data.analytics;
            const summary = analytics.summary;
            el('analyticsFeedback').textContent = `Reporting period: ${analytics.from} through ${analytics.to}`;
            el('operationalMetrics').innerHTML = [
                ['Interruption reports', summary.reports], ['Affected connections', summary.affected_connections], ['Repeat connections', summary.repeat_connections],
                ['Average repair time', summary.avg_repair_hours === null ? 'No data' : summary.avg_repair_hours + ' h'],
                ['Average acceptance time', summary.avg_acceptance_hours === null ? 'No data' : summary.avg_acceptance_hours + ' h']
            ].map(([label,value]) => `<div class="card stat"><div class="stat-label">${escape(label)}</div><div class="stat-value">${escape(value)}</div></div>`).join('');
            charts.forEach(chart => chart.destroy()); charts = [];
            if (typeof Chart !== 'undefined') for (const [id,series,label] of [['reportDayChart',analytics.by_day,'Reports'],['reportBarangayChart',analytics.by_barangay,'Reports']]) {
                charts.push(new Chart(el(id), { type:'bar', data:{labels:Object.keys(series),datasets:[{label,data:Object.values(series),backgroundColor:'#1f6ea8',borderRadius:5}]},options:{responsive:true,scales:{y:{beginAtZero:true,ticks:{precision:0}}},plugins:{legend:{display:false}}} }));
            }
            el('napCapacityReport').innerHTML = table(['NAP box','Barangay','Occupied','Available','Capacity status'],analytics.capacity.map(n => [n.name,n.barangay,`${n.occupied}/${n.capacity}`,n.available,Number(n.occupied)>=n.capacity ? 'Full' : Number(n.occupied)/n.capacity>=.8 ? 'Near capacity' : 'Available']));
            el('technicianReport').innerHTML = table(['Technician','Assigned reports','Completed repairs'],Object.entries(analytics.by_technician).map(([name,counts]) => [name,counts.reports,counts.completed]));
            el('subscriberStatusReport').innerHTML = analytics.subscriber_status.map(row => `<p>${escape(row.status)}: <strong>${escape(row.total)}</strong></p>`).join('') || '<p>No subscriber records.</p>';
            el('analyticsExport').disabled = false;
        } catch (error) { if (version === requestVersion) { analytics = null; el('analyticsFeedback').textContent = error.message; } }
    }
    function setup() {
        if (initialized) return; initialized = true;
        const today = new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Manila',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
        el('analyticsFrom').value = today.slice(0,7)+'-01'; el('analyticsTo').value = today;
        el('workBarangay').addEventListener('change', relatedOptions);
        el('workStatus').addEventListener('change', () => directory && renderOrders());
        el('refreshWorkOrders').addEventListener('click', loadOrders);
        el('workOrderForm').addEventListener('submit', async event => {
            event.preventDefault(); const form=event.currentTarget, button=form.querySelector('button[type=submit]'); button.disabled=true;
            try { await request('',{action:'create',...Object.fromEntries(new FormData(form))}); form.reset(); await loadOrders(); el('workFeedback').textContent='Work order scheduled.'; }
            catch(error) {el('workFeedback').textContent=error.message;} finally {button.disabled=false;}
        });
        el('analyticsFilters').addEventListener('submit', event => {event.preventDefault();loadAnalytics();});
        ['analyticsFrom','analyticsTo'].forEach(id => el(id).addEventListener('change', () => { el('analyticsExport').disabled=true; }));
        el('analyticsExport').addEventListener('click', () => {
            if (!analytics) return;
            const headers=['id','connection_id','barangay','nap_box','technician','status','reported_at','completed_at','resolved_at','acceptance_seconds','repair_seconds'];
            const cell=value => '"'+String(value??'').replace(/^[=+@\-\t\r]/,"'$&").replaceAll('"','""')+'"';
            const lines=[['Reporting period',analytics.from,analytics.to],headers,...analytics.reports.map(row => headers.map(key=>row[key])),[],['Summary','Value'],...Object.entries(analytics.summary),[],['Current NAP capacity','Barangay','Occupied','Available','Capacity'],...analytics.capacity.map(n=>[n.name,n.barangay,n.occupied,n.available,n.capacity])];
            const url=URL.createObjectURL(new Blob(['\uFEFF'+lines.map(row=>row.map(cell).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));
            const a=document.createElement('a');a.href=url;a.download=`geonet-analytics-${analytics.from}-${analytics.to}.csv`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
        });
    }
    return {loadOrders,loadAnalytics};
})();
