document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('inboxForm');
    const status = document.getElementById('inboxStatus');
    const messageId = document.getElementById('inboxMessageId');
    const newId = () => { messageId.value = 'local-' + crypto.randomUUID(); };
    async function request(options) {
        const response = await fetch('api/sms-inbox.php', { credentials: 'same-origin', ...options });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'SMS inbox request failed.');
        return data;
    }
    async function refresh() {
        const data = await request();
        const history = document.getElementById('inboxHistory');
        history.replaceChildren();
        document.getElementById('inboxTotal').textContent = data.entries.length;
        if (!data.entries.length) { const empty = document.createElement('div'); empty.className = 'inbox-empty'; empty.textContent = 'No reports yet. Incoming subscriber messages will appear here.'; history.appendChild(empty); }
        for (const entry of data.entries) {
            const card = document.createElement('article');
            card.className = 'inbox-entry';
            const title = document.createElement('strong');
            title.textContent = entry.sender;
            const badge = document.createElement('span');
            badge.className = 'report-badge ' + (entry.result === 'processed' ? 'report-success' : entry.result === 'rejected' ? 'report-rejected' : '');
            badge.textContent = entry.result;
            title.appendChild(badge);
            card.appendChild(title);
            for (const text of [entry.message, entry.note,
                entry.previous_status ? `${entry.previous_status} → ${entry.new_status}` : '',
                `${entry.received_at} / ${entry.source} / ${entry.connection_public_id || 'Unmatched connection'}`]) {
                if (!text) continue;
                const line = document.createElement('p');
                line.textContent = text;
                card.appendChild(line);
            }
            history.appendChild(card);
        }
    }
    document.getElementById('inboxRefresh').addEventListener('click', () => {
        refresh().catch(error => { status.textContent = error.message; });
    });
    if (form) {
        newId();
        document.getElementById('inboxNewId').addEventListener('click', newId);
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = document.getElementById('inboxSubmit');
            submit.disabled = true;
            try {
                const data = await request({ method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ sender: document.getElementById('inboxSender').value,
                        message: document.getElementById('inboxMessage').value,
                        messageId: messageId.value, csrfToken: window.geonetInboxCsrf }) });
                status.textContent = data.duplicate ? 'Duplicate delivery ignored. No changes were repeated.' : data.entry.note;
                await refresh();
            } catch (error) {
                status.textContent = error.message;
            } finally {
                submit.disabled = false;
            }
        });
    }
    const sendForm = document.getElementById('subscriberSmsForm');
    if (sendForm) {
        const recipients = document.getElementById('sendSubscribers');
        const message = document.getElementById('subscriberSmsMessage');
        const feedback = document.getElementById('subscriberSmsStatus');
        const selectedIds = () => Array.from(recipients.querySelectorAll('input:checked'), input => input.value);
        const updateSelection = () => {
            document.getElementById('subscriberSmsCount').textContent = `${selectedIds().length} subscriber(s) selected. Shared numbers receive one SMS.`;
        };
        recipients.addEventListener('change', updateSelection);
        document.getElementById('clearRecipients').addEventListener('click', () => {
            recipients.querySelectorAll('input:checked').forEach(input => { input.checked = false; }); updateSelection();
        });
        document.getElementById('recipientSearch').addEventListener('input', event => {
            const query = event.target.value.trim().toLowerCase();
            const rows = Array.from(recipients.querySelectorAll('.recipient-row'));
            rows.forEach(row => { row.hidden = !row.dataset.search.includes(query); });
            document.getElementById('recipientEmpty').hidden = rows.some(row => !row.hidden);
        });
        const updateCharacters = () => { document.getElementById('composeCharacterCount').textContent = `${Array.from(message.value).length} / 1,000 characters`; };
        message.addEventListener('input', updateCharacters);
        document.getElementById('subscriberSmsTemplate').addEventListener('change', event => {
            if (event.target.value === 'restoration') message.value = 'GeoNet: Internet service has been restored. Please check your connection. Reply RESTORED followed by your connection ID to confirm, or DOWN followed by your connection ID if issues remain.';
            if (event.target.value === 'outage') message.value = 'GeoNet: We are investigating a service interruption affecting your connection. Our team is working to restore service. We will provide further updates.';
            updateCharacters();
        });
        sendForm.addEventListener('submit', async event => {
            event.preventDefault();
            const ids = selectedIds();
            if (!ids.length) { feedback.textContent = 'Select at least one subscriber.'; return; }
            if (!message.value.trim()) return;
            if (!window.confirm(`Send this SMS to ${ids.length} selected subscriber(s)? Your SIM allowance or carrier charges apply.`)) return;
            const button = document.getElementById('subscriberSmsSend');
            button.disabled = true;
            feedback.textContent = 'Submitting to SMSGate...';
            try {
                const response = await fetch('api/sms.php', {
                    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'send', context: 'subscribers', subscriberIds: ids,
                        message: message.value.trim(), expectedMock: false, csrfToken: window.geonetInboxCsrf })
                });
                const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.error || 'Could not submit SMS. Check the SMSGate dashboard before retrying.');
                feedback.textContent = data.message;
            } catch (error) {
                feedback.textContent = error.message;
            } finally { button.disabled = false; }
        });
    }
    refresh().catch(error => { status.textContent = error.message; });
});
