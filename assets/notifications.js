(() => {
    const center = document.querySelector("[data-notification-center]");
    const lists = Array.from(document.querySelectorAll("[data-notification-list]"));
    const markAllButtons = Array.from(document.querySelectorAll("[data-notification-mark-all]"));
    const clearAllButtons = Array.from(document.querySelectorAll("[data-notification-clear-all]"));
    if (!center && lists.length === 0) return;

    const trigger = center?.querySelector(".notification-trigger");
    const panel = center?.querySelector("[data-notification-panel]");
    const count = center?.querySelector("[data-notification-count]");
    const csrfToken = center?.dataset.csrfToken;
    let loading = false;
    let assigning = false;
    let refreshAfterLoad = false;

    function setUnreadCount(value) {
        if (!count || !trigger) return;
        const unreadCount = Number(value) || 0;
        count.hidden = unreadCount === 0;
        count.textContent = unreadCount > 99 ? "99+" : String(unreadCount);
        trigger.setAttribute(
            "aria-label",
            unreadCount ? `Notifications, ${unreadCount} unread` : "Notifications"
        );
    }

    function makeMessage(text, className = "notification-empty") {
        const message = document.createElement("p");
        message.className = className;
        message.textContent = text;
        return message;
    }

    function renderNotifications(notifications, technicians = []) {
        lists.forEach((list) => {
            list.replaceChildren();
            if (!notifications.length) {
                list.append(makeMessage("You're all caught up."));
                return;
            }

            notifications.forEach((notification) => {
                const unread = Number(notification.unread) === 1;
                const assignment = list.closest('.dashboard-notification-feed') ? notification.assignment : null;
                const item = document.createElement('article');
                const content = document.createElement(notification.link ? 'a' : 'button');
                content.className = 'notification-content';
                item.className = `notification-item${unread ? " unread" : ""}`;
                if (notification.link) {
                    content.href = notification.link;
                } else {
                    content.type = "button";
                }
                if (notification.interruption_id) item.dataset.interruptionId = notification.interruption_id;
                if (notification.id !== null && notification.id !== undefined) {
                    item.dataset.notificationId = notification.id;
                }

                const title = document.createElement("strong");
                title.className = "notification-title";
                title.textContent = notification.title;
                const body = document.createElement("span");
                body.className = "notification-message";
                body.textContent = notification.message;
                const date = document.createElement("time");
                date.className = "notification-time";
                date.dateTime = notification.created_at;
                const parsedDate = new Date(notification.created_at);
                date.textContent = Number.isNaN(parsedDate.getTime())
                    ? notification.created_at
                    : parsedDate.toLocaleString();
                content.append(title, body, date);
                item.appendChild(content);
                if (assignment) {
                    const form = document.createElement('form');
                    form.className = 'notification-assignment';
                    form.dataset.activityAssignment = '';
                    const select = document.createElement('select');
                    select.required = true;
                    select.setAttribute('aria-label', `Technician for ${notification.title}`);
                    const placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent = technicians.length ? 'Choose a technician' : 'No active technician accounts';
                    select.appendChild(placeholder);
                    technicians.forEach(technician => {
                        const option = document.createElement('option');
                        option.value = technician.id;
                        option.textContent = `${technician.full_name} · ${technician.availability.replaceAll('_', ' ')}`;
                        select.appendChild(option);
                    });
                    const submit = document.createElement('button');
                    submit.type = 'submit';
                    submit.className = 'btn btn-primary';
                    submit.textContent = assignment.assigned ? 'Reassign technician' : 'Assign technician';
                    submit.disabled = !technicians.length;
                    select.disabled = !technicians.length;
                    const status = document.createElement('p');
                    status.className = 'notification-assignment-status';
                    status.setAttribute('role', 'status');
                    form.append(select, submit, status);
                    form.addEventListener('submit', async event => {
                        event.preventDefault();
                        event.stopPropagation();
                        if (assigning || !select.value) return;
                        assigning = true;
                        select.disabled = true;
                        submit.disabled = true;
                        status.textContent = 'Assigning technician…';
                        try {
                            const response = await fetch('api/notifications.php', {
                                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'assign_technician', kind: assignment.kind,
                                    id: assignment.id, technicianId: select.value, csrfToken })
                            });
                            const result = await response.json();
                            if (!response.ok || !result.ok) throw new Error(result.error || 'Could not assign technician.');
                            assigning = false;
                            await loadNotifications(true);
                            window.dispatchEvent(new Event('geonet:activity-assigned'));
                        } catch (error) {
                            status.textContent = error.message;
                            select.disabled = false;
                            submit.disabled = false;
                        } finally { assigning = false; }
                    });
                    item.appendChild(form);
                }
                const dismiss = document.createElement('button');
                dismiss.type = 'button'; dismiss.className = 'notification-dismiss';
                dismiss.textContent = 'Dismiss';
                dismiss.setAttribute('aria-label', 'Dismiss ' + notification.title);
                dismiss.dataset.dismissNotification = '';
                dismiss.addEventListener('click', async event => {
                    event.preventDefault(); event.stopPropagation();
                    dismiss.disabled = true;
                    const index = Array.from(list.children).indexOf(item);
                    try {
                        await updateNotifications('clear_one', notification.id, notification.activity_key);
                        const next = list.children[index] || list.lastElementChild;
                        (next?.querySelector('button,a') || trigger)?.focus();
                    } catch (error) {
                        dismiss.disabled = false;
                        let feedback = item.querySelector('.notification-dismiss-error');
                        if (!feedback) {feedback=makeMessage('', 'notification-dismiss-error');feedback.setAttribute('role','alert');item.appendChild(feedback);}
                        feedback.textContent = error.message || 'Could not dismiss notification.';
                    }
                });
                item.appendChild(dismiss);
                list.append(item);
            });
        });
    }

    async function loadNotifications(force = false) {
        if (loading) { if (force) refreshAfterLoad = true; return; }
        if (assigning || !center) return;
        if (!force && lists.some(list => list.querySelector('.notification-assignment select:focus') ||
            Array.from(list.querySelectorAll('.notification-assignment select')).some(select => select.value))) return;
        loading = true;
        try {
            const response = await fetch("api/notifications.php", {
                credentials: "same-origin",
                headers: { Accept: "application/json" }
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error(result.error || "Could not load notifications.");
            }
            setUnreadCount(result.unread_count);
            if (assigning || (!force && lists.some(list => list.querySelector('.notification-assignment select:focus') ||
                Array.from(list.querySelectorAll('.notification-assignment select')).some(select => select.value)))) return;
            renderNotifications(result.activity || result.notifications || [], result.assignment_technicians || []);
        } catch (error) {
            lists.forEach((list) => list.replaceChildren(makeMessage(
                error.message || "Could not load notifications.",
                "notification-error"
            )));
        } finally {
            loading = false;
            if (refreshAfterLoad) { refreshAfterLoad = false; loadNotifications(true); }
        }
    }

    async function updateNotifications(action, notificationId, activityKey) {
        const response = await fetch("api/notifications.php", {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json"
            },
            body: JSON.stringify({ action, notificationId, activityKey, csrfToken })
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
            throw new Error(result.error || "Could not update notifications.");
        }
        await loadNotifications(true);
    }

    if (trigger && panel) {
        trigger.addEventListener("click", async () => {
            const willOpen = panel.hidden;
            panel.hidden = !willOpen;
            trigger.setAttribute("aria-expanded", String(willOpen));
            if (willOpen) await loadNotifications();
        });
    }

    markAllButtons.forEach((button) => button.addEventListener("click", async () => {
        try {
            await updateNotifications("mark_all_read");
        } catch (error) {
            lists.forEach((list) => list.replaceChildren(makeMessage(
                error.message || "Could not update notifications.",
                "notification-error"
            )));
        }
    }));

    clearAllButtons.forEach((button) => button.addEventListener("click", async () => {
        if (!window.confirm("Clear all notifications and activity from this feed?")) return;
        try {
            await updateNotifications("clear_all");
        } catch (error) {
            lists.forEach((list) => list.replaceChildren(makeMessage(
                error.message || "Could not clear notifications.",
                "notification-error"
            )));
        }
    }));

    lists.forEach((list) => list.addEventListener("click", async (event) => {
        if (event.target.closest('[data-activity-assignment],[data-dismiss-notification]')) return;
        const interruption = event.target.closest('[data-interruption-id]');
        if (interruption && document.getElementById('dashMap')) {
            event.preventDefault();
            window.dispatchEvent(new CustomEvent('geonet:focus-interruption', { detail: { id: interruption.dataset.interruptionId } }));
            return;
        }
        const item = event.target.closest("[data-notification-id]");
        if (!item) return;
        event.preventDefault();
        const href = item.querySelector(".notification-content")?.getAttribute("href");
        try {
            await updateNotifications("mark_read", item.dataset.notificationId);
            if (href) window.location.assign(href);
        } catch (error) {
            list.replaceChildren(makeMessage(
                error.message || "Could not update notifications.",
                "notification-error"
            ));
        }
    }));

    if (center && panel && trigger) {
        window.addEventListener('geonet:refresh-notifications', () => loadNotifications());
        document.addEventListener("click", (event) => {
            if (!center.contains(event.target) && !panel.hidden) {
                panel.hidden = true;
                trigger.setAttribute("aria-expanded", "false");
            }
        });
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape" && !panel.hidden) {
                panel.hidden = true;
                trigger.setAttribute("aria-expanded", "false");
                trigger.focus();
            }
        });

        loadNotifications();
        window.setInterval(loadNotifications, 30000);
    }
})();
