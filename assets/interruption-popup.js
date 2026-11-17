(function () {
    const escape = value => String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);
    const labels = { reported: 'Needs technician assignment', assigned: 'Awaiting technician response',
        accepted: 'Technician accepted', en_route: 'Technician on route',
        awaiting_confirmation: 'Awaiting subscriber confirmation', resolved: 'Resolved' };
    window.geonetInterruptionPopup = (subscriber, report, technicians) => {
        const base = `<strong>${escape(subscriber.name)}</strong><span>${escape(subscriber.id)} · ${escape(subscriber.status)}</span>
            <span>${escape(subscriber.address)} · ${escape(subscriber.barangay)}</span>`;
        if (!report) return `<div class="request-map-popup">${base}<span>No active SMS interruption report for this connection.</span>
            <a href="interruptions.php">View interruption reports</a></div>`;
        const canAssign = ['reported', 'assigned', 'accepted', 'en_route'].includes(report.status);
        return `<div class="request-map-popup">${base}
            <strong>Interruption report #${escape(report.id)}</strong>
            <span>${escape(labels[report.status] || report.status)}</span>
            <span>Reported: ${escape(report.reported_at)} (server time)</span>
            <span>Technician: ${escape(report.technician_name || 'Unassigned')}</span>
            ${report.technician_note ? `<span>Reason: ${escape(report.technician_note)}</span>` : ''}
            ${report.status === 'awaiting_confirmation' ? `<span>Automatic confirmation: ${escape(report.confirmation_due_at)} (server time). Only the subscriber can confirm restoration before this deadline.</span>` : ''}
            ${report.resolution_method === 'automatic' ? '<span>Automatically confirmed after five hours without a subscriber response.</span>' : ''}
            ${canAssign ? `<label>Assign technician
                <select data-map-interruption-technician aria-label="Technician for interruption ${escape(report.id)}">
                    <option value="">Choose a technician</option>
                    ${technicians.map(tech => `<option value="${escape(tech.id)}">${escape(tech.full_name)} · ${escape(tech.availability.replaceAll('_', ' '))}</option>`).join('')}
                </select></label>
                <button class="btn btn-primary" type="button" data-map-assign-interruption="${escape(report.id)}" ${technicians.length ? '' : 'disabled'}>
                    ${report.assigned_technician_id ? 'Reassign technician' : 'Assign technician'}
                </button>` : ''}
            <a href="interruptions.php?report=${encodeURIComponent(report.id)}">View report and history</a>
        </div>`;
    };
})();
