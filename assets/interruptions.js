document.addEventListener('DOMContentLoaded', () => {
    const { role, csrfToken } = window.geonetReports;
    const list = document.getElementById('reportsList');
    const message = document.getElementById('reportsMessage');
    let busy = false;
    const requestedReport = new URLSearchParams(location.search).get('report');
    let initialReportFocused = false;
    const labels = { reported: 'Needs assignment', assigned: 'Waiting for technician', accepted: 'Accepted',
        en_route: 'On route', awaiting_confirmation: 'Waiting for subscriber confirmation', resolved: 'Resolved' };
    function node(tag, text, className) {
        const element = document.createElement(tag);
        element.textContent = text;
        if (className) element.className = className;
        return element;
    }
    async function fetchReports(body) {
        const response = await fetch('api/interruptions.php', { credentials: 'same-origin',
            ...(body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrfToken }) } : {}) });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Could not load interruption reports.');
        return data;
    }
    async function act(report, action, extra = {}) {
        if (busy) return;
        busy = true;
        list.querySelectorAll('button,select').forEach(element => { element.disabled = true; });
        try {
            render(await fetchReports({ id: report.id, action, ...extra }));
            message.textContent = 'Interruption report updated.';
        } catch (error) { message.textContent = error.message; }
        finally {
            busy = false;
            list.querySelectorAll('button,select').forEach(element => { element.disabled = false; });
        }
    }
    function button(container, text, callback) {
        const control = node('button', text, 'btn btn-outline');
        control.type = 'button';
        control.addEventListener('click', callback);
        container.appendChild(control);
    }
    function render(data) {
        document.getElementById('reportsWorker').textContent = role === 'admin' && data.localWorkerExpected
            ? (data.localWorkerActive ? 'Automatic restoration confirmation is active.'
                : 'Automatic confirmation needs attention: start the local background worker to process deadlines while the app is closed.') : '';
        list.replaceChildren();
        const summary = {
            reportsOpen: data.reports.filter(report => report.status !== 'resolved').length,
            reportsProgress: data.reports.filter(report => ['assigned','accepted','en_route'].includes(report.status)).length,
            reportsWaiting: data.reports.filter(report => report.status === 'awaiting_confirmation').length,
            reportsResolved: data.reports.filter(report => report.status === 'resolved').length
        };
        for (const [id, value] of Object.entries(summary)) document.getElementById(id).textContent = value;
        if (!data.reports.length) list.appendChild(node('div', 'No interruption reports to show. New reports will appear here.', 'card reports-empty'));
        for (const report of data.reports) {
            const card = node('section', '', 'card panel');
            card.id = `interruption-report-${report.id}`;
            const header = node('div', '', 'panel-head');
            header.appendChild(node('h3', `Report #${report.id} · ${report.connection_id}`));
            const state = node('span', labels[report.status] || report.status, `badge report-state ${report.status}`);
            state.dataset.state = report.status;
            header.appendChild(state);
            card.appendChild(header);
            const detailsGrid = node('dl', '', 'report-summary');
            for (const [label, value] of [['Subscriber', report.full_name], ['Service location', `${report.address}, ${report.barangay}`],
                ['Assigned technician', report.technician_name || 'Unassigned'], ['Connection status', report.connection_status]]) {
                const field = document.createElement('div');
                field.append(node('dt', label), node('dd', value));
                detailsGrid.appendChild(field);
            }
            card.appendChild(detailsGrid);
            if (report.technician_note) card.appendChild(node('p', `Technician's reason: ${report.technician_note}`, 'report-note'));
            if (report.status === 'awaiting_confirmation') {
                const deadline = node('p', '', 'report-deadline');
                deadline.dataset.deadline = report.confirmation_due_epoch;
                card.appendChild(deadline);
            }
            if (report.resolution_method) {
                card.appendChild(node('p', report.resolution_method === 'automatic'
                    ? 'Automatically confirmed after five hours without a subscriber response.'
                    : 'Restoration confirmed by the subscriber.'));
            }
            const actions = node('div', '', 'reports-actions');
            if (role === 'admin' && ['reported','assigned','accepted','en_route'].includes(report.status)) {
                const select = document.createElement('select');
                select.setAttribute('aria-label', `Technician for report ${report.id}`);
                const placeholder = node('option', 'Choose a technician');
                placeholder.value = '';
                select.appendChild(placeholder);
                for (const tech of data.technicians) {
                    const option = node('option', `${tech.full_name} · ${tech.availability.replaceAll('_', ' ')}`);
                    option.value = tech.id;
                    select.appendChild(option);
                }
                actions.appendChild(select);
                button(actions, report.assigned_technician_id ? 'Reassign technician' : 'Assign technician', () => {
                    if (!select.value) { message.textContent = 'Choose a technician first.'; return; }
                    act(report, 'assign', { technicianId: select.value });
                });
            }
            if (role === 'technician') {
                if (report.status === 'assigned') {
                    button(actions, 'Accept repair', () => act(report, 'accept'));
                    button(actions, 'Decline', () => {
                        const note = prompt('Why are you declining this repair?');
                        if (note?.trim()) act(report, 'decline', { note });
                    });
                }
                if (report.status === 'accepted') button(actions, 'Mark on route', () => act(report, 'en_route'));
                if (report.status === 'en_route') button(actions, 'Mark repair complete', () => {
                    if (confirm('Start the subscriber’s five-hour confirmation window? The connection remains offline until confirmation.')) act(report, 'complete');
                });
                if (['accepted','en_route'].includes(report.status)) button(actions, 'Cannot complete', () => {
                    const note = prompt('Explain why this repair cannot be completed.');
                    if (note?.trim()) act(report, 'not_completed', { note });
                });
            }
            if (role === 'subscriber' && report.status !== 'resolved') {
                button(actions, 'My connection is restored', () => {
                    if (confirm('Confirm that this connection is working again?')) act(report, 'confirm');
                });
                if (report.status === 'awaiting_confirmation') button(actions, 'Still no connection', () => act(report, 'still_down'));
            }
            card.appendChild(actions);
            const details = node('details', '', 'report-history');
            details.appendChild(node('summary', 'Report history (server time)'));
            const events = node('ul', '', 'report-events');
            for (const event of report.events) events.appendChild(node('li', `${event.created_at} · ${event.event.replaceAll('_', ' ')}${event.note ? '\n' + event.note : ''}`));
            details.appendChild(events);
            card.appendChild(details);
            list.appendChild(card);
        }
        updateCountdowns();
        if (!initialReportFocused && requestedReport && /^\d+$/.test(requestedReport)) {
            const target = document.getElementById(`interruption-report-${requestedReport}`);
            if (target) {
                target.tabIndex = -1;
                target.focus({ preventScroll: true });
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                initialReportFocused = true;
            }
        }
    }
    function updateCountdowns() {
        document.querySelectorAll('[data-deadline]').forEach(element => {
            const seconds = Math.max(0, Math.ceil(Number(element.dataset.deadline) - Date.now() / 1000));
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor(seconds % 3600 / 60);
            element.textContent = seconds ? `Automatic restoration confirmation in ${hours}h ${minutes}m ${seconds % 60}s. The subscriber can confirm or report that it is still down.`
                : 'The response window has expired. Waiting for automatic confirmation.';
        });
    }
    async function refresh() {
        if (busy) return;
        // Preserve an administrator's selected technician while choosing an assignment.
        if (list.querySelector('select:focus') || Array.from(list.querySelectorAll('select')).some(select => select.value)) return;
        try { render(await fetchReports()); } catch (error) { message.textContent = error.message; }
    }
    document.getElementById('reportsRefresh').addEventListener('click', async () => {
        if (busy) return;
        try { render(await fetchReports()); } catch (error) { message.textContent = error.message; }
    });
    refresh();
    setInterval(refresh, 30000);
    setInterval(updateCountdowns, 1000);
});
