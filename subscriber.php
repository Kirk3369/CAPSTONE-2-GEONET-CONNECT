<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/geonet.php";
geonet_require_login("subscriber");
$display = $_SESSION["display_name"] ?? $_SESSION["username"];
$subId = $_SESSION["sub_id"] ?? "SUB-1042";
$profile = geonet_own_profile($_SESSION["user_id"] ?? null);
$connections = geonet_own_subscribers($_SESSION["user_id"] ?? null);
$planChangeRequests = geonet_own_plan_change_requests($_SESSION["user_id"] ?? null);
$pendingConnectionRequest = geonet_own_additional_connection_request($_SESSION["user_id"] ?? null);
$availablePlans = geonet_available_subscriber_plans();
$initialView = (string) ($_GET["view"] ?? "");
$csrfToken = $_SESSION["profile_csrf_token"] ?? bin2hex(random_bytes(32));
$_SESSION["profile_csrf_token"] = $csrfToken;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoNet Connect · Subscriber</title>
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/app.css?v=<?php echo (int) filemtime(__DIR__ . "/assets/app.css"); ?>">
    <link rel="stylesheet" href="assets/design-system.css?v=<?php echo filemtime(__DIR__ . "/assets/design-system.css"); ?>">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon"><i class="fa-solid fa-wifi"></i></div>
            <div>
                <h1>My connection</h1>
                <p>Subscriber portal</p>
            </div>
        </div>
        <nav class="nav">
            <a class="nav-item" data-subscriber-view="profile" href="#profile"><i class="fa-solid fa-id-card"></i> Profile</a>
            <a class="nav-item" data-subscriber-view="account" href="#account"><i class="fa-solid fa-house"></i> My account</a>
            <a class="nav-item" href="interruptions.php"><i class="fa-solid fa-wrench"></i> My interruption reports</a>
        </nav>
        <div class="sidebar-foot">San Miguel, Iloilo · R-Link</div>
    </aside>
    <div class="main">
        <header class="topbar">
            <div>
                <h2>Account overview</h2>
                <p class="topbar-sub">Your plan, status, and nearby network notices</p>
            </div>
            <div class="topbar-right">
                <div class="notification-center" data-notification-center
                     data-csrf-token="<?php echo htmlspecialchars($csrfToken); ?>">
                    <button class="notification-trigger" type="button" aria-expanded="false">
                        <i class="fa-regular fa-bell" aria-hidden="true"></i>
                        <span class="sr-only">Notifications</span>
                        <span class="notification-count" data-notification-count aria-live="polite" hidden></span>
                    </button>
                    <section class="notification-panel" data-notification-panel hidden aria-label="Notifications">
                        <div class="notification-panel-head">
                            <h3>Notifications</h3>
                            <div class="notification-panel-actions">
                                <button type="button" class="notification-mark-all" data-notification-mark-all>
                                    Mark all read
                                </button>
                                <button type="button" class="notification-clear-all" data-notification-clear-all>
                                    Clear
                                </button>
                            </div>
                        </div>
                        <div class="notification-list" data-notification-list></div>
                    </section>
                </div>
                <a class="user-chip" href="#profile" data-subscriber-profile-link>
                    <?php echo htmlspecialchars($display); ?> · Subscriber
                </a>
                <a class="btn btn-outline" href="interruptions.php">My interruption reports</a>
                <a class="btn btn-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </div>
        </header>
        <div class="content">
            <section id="subscriber-view-account" class="view active">
            <div class="stats">
                <div class="card stat">
                    <div class="stat-label">Status</div>
                    <div class="stat-value" id="statStatus">—</div>
                </div>
                <div class="card stat">
                    <div class="stat-label">Plan</div>
                    <div class="stat-value" id="statPlan" style="font-size:18px">—</div>
                </div>
                <div class="card stat">
                    <div class="stat-label">Account ID</div>
                    <div class="stat-value" id="statId" style="font-size:18px"><?php echo htmlspecialchars($subId); ?></div>
                </div>
                <div class="card stat">
                    <div class="stat-label">Active notices</div>
                    <div class="stat-value accent-red" id="statNotices">0</div>
                </div>
            </div>
            <div class="dash-grid">
                <div class="card map-card">
                    <div class="map-head"><h3>Your service location</h3></div>
                    <div id="subMap" class="map"></div>
                </div>
                <div class="card panel">
                    <div class="panel-head"><h3>Account details</h3></div>
                    <div id="accountDetail" class="event-list"></div>
                </div>
            </div>
            <div class="card panel" style="margin-top:16px">
                <div class="panel-head"><h3>Outage notices</h3></div>
                <div id="noticeList" class="event-list"></div>
            </div>
            </section>
            <section id="subscriber-view-profile" class="view">
                <section class="card panel profile-panel">
                    <div class="panel-head"><h3>Edit your details</h3></div>
                    <form id="profileForm" class="profile-form">
                        <input id="profileCsrfToken" type="hidden"
                               value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <label class="field">Full name
                            <input name="name" maxlength="120" required
                                   value="<?php echo htmlspecialchars($profile["name"]); ?>">
                        </label>
                        <label class="field">Email address
                            <input name="email" type="email" maxlength="190" required
                                   value="<?php echo htmlspecialchars($profile["email"]); ?>">
                            <span class="side-meta">Use an address ending in @sub.rlink.ph.</span>
                        </label>
                        <label class="field">Phone
                            <input name="phone" type="tel" maxlength="20" required
                                   value="<?php echo htmlspecialchars($profile["phone"] ?? ""); ?>">
                        </label>
                        <label class="field">Service address
                            <input name="address" maxlength="255" required
                                   value="<?php echo htmlspecialchars($profile["address"] ?? ""); ?>">
                        </label>
                        <div class="profile-password-section">
                            <button class="btn btn-outline profile-password-toggle" type="button"
                                    aria-expanded="false" aria-controls="profilePasswordFields">
                                <i class="fa-solid fa-key" aria-hidden="true"></i> Change password
                            </button>
                            <div class="profile-password-fields" id="profilePasswordFields" hidden>
                                <label class="field">New password
                                    <input name="password" type="password" minlength="8" autocomplete="new-password">
                                    <span class="side-meta">Minimum 8 characters.</span>
                                </label>
                                <label class="field">Confirm new password
                                    <input name="confirmPassword" type="password" minlength="8" autocomplete="new-password">
                                    <span class="side-meta">Enter the same new password again.</span>
                                </label>
                            </div>
                        </div>
                        <div class="profile-actions">
                            <button class="btn btn-primary" type="submit" id="saveProfile">Save changes</button>
                            <p id="profileMessage" class="availability-message" role="status" aria-live="polite"></p>
                        </div>
                    </form>
                    <details id="manageSubscription" class="card panel subscription-dropdown">
                    <summary><i class="fa-solid fa-file-contract" aria-hidden="true"></i> Manage subscription</summary>
                    <p class="side-meta">View your connections, request a plan change, or add another connection.</p>
                <div class="card panel">
                    <div class="panel-head"><h3>Your connections</h3></div>
                    <div id="subscriptionConnections" class="event-list"></div>
                </div>
                <div class="card panel" style="margin-top:16px">
                    <div class="panel-head"><h3>Change a connection’s plan</h3></div>
                    <form id="planChangeForm" class="profile-form">
                        <label class="field" for="planChangeConnection">Connection
                            <select id="planChangeConnection" required>
                                <?php foreach ($connections as $connection): ?>
                                    <option value="<?php echo htmlspecialchars($connection["id"]); ?>">
                                        <?php echo htmlspecialchars($connection["id"] . " · " . $connection["address"]); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field" for="planChangeSelect">Choose a new plan
                            <select id="planChangeSelect" name="plan" required>
                                <option value="">Select a plan…</option>
                                <?php foreach ($availablePlans as $plan): ?>
                                    <option value="<?php echo htmlspecialchars($plan); ?>">
                                        <?php echo htmlspecialchars($plan); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="profile-actions">
                            <button class="btn btn-primary" type="submit" id="submitPlanChange"
                                <?php echo $planChangeRequests ? "disabled" : ""; ?>>
                                Request plan change
                            </button>
                            <p id="planChangeMessage" class="availability-message" role="status" aria-live="polite">
                                <?php if ($planChangeRequests): ?>
                                    <?php foreach ($planChangeRequests as $request): ?>
                                        <?php echo htmlspecialchars($request["connectionId"] . ": " .
                                            $request["currentPlan"] . " → " . $request["requestedPlan"]); ?><br>
                                    <?php endforeach; ?>
                                    Awaiting admin approval.
                                <?php endif; ?>
                            </p>
                        </div>
                    </form>
                </div>
                <div class="card panel" style="margin-top:16px">
                    <div class="panel-head"><h3>Request another connection</h3></div>
                    <?php if ($pendingConnectionRequest): ?>
                        <p class="side-meta" id="connectionRequestMessage">
                            Your request is awaiting review: <?php echo htmlspecialchars($pendingConnectionRequest["plan"]); ?>
                            · <?php echo htmlspecialchars($pendingConnectionRequest["address"]); ?>
                            · <?php echo htmlspecialchars($pendingConnectionRequest["barangay"]); ?>.
                        </p>
                    <?php else: ?>
                        <form id="additionalConnectionForm" class="profile-form">
                            <label class="field" for="connectionBarangay">Barangay
                                <select id="connectionBarangay" name="barangay" required>
                                    <option value="">Choose a barangay</option>
                                </select>
                            </label>
                            <label class="field" for="connectionNapBox">NAP box
                                <select id="connectionNapBox" name="nap_box_id" required disabled>
                                    <option value="">Choose a barangay first</option>
                                </select>
                                <span class="side-meta">Only NAP boxes with available capacity in the selected barangay are shown.</span>
                            </label>
                            <label class="field" for="connectionAddress">Service address
                                <input id="connectionAddress" name="address" maxlength="255" required
                                       value="<?php echo htmlspecialchars($profile["address"] ?? ""); ?>">
                            </label>
                            <label class="field" for="connectionPhone">Phone
                                <input id="connectionPhone" name="phone" type="tel" maxlength="20" required
                                       value="<?php echo htmlspecialchars($profile["phone"] ?? ""); ?>">
                            </label>
                            <label class="field" for="connectionPlan">Plan
                                <select id="connectionPlan" name="plan" required>
                                    <option value="">Choose a plan</option>
                                    <?php foreach ($availablePlans as $plan): ?>
                                        <option value="<?php echo htmlspecialchars($plan); ?>">
                                            <?php echo htmlspecialchars($plan); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div class="field" id="connectionLocationPicker" hidden>
                                <label for="connectionMap">Pin this connection’s service location</label>
                                <p class="side-meta">Select a NAP box, then click within its <?php echo geonet_nap_box_coverage_radius_meters(); ?>-meter service radius and network coverage.</p>
                                <div id="connectionMap" class="signup-map"></div>
                                <p id="connectionMapStatus" class="map-pick-status" role="status">Choose a barangay and NAP box, then pin the service location.</p>
                                <input id="connectionLat" name="lat" type="hidden" required>
                                <input id="connectionLng" name="lng" type="hidden" required>
                            </div>
                            <div class="profile-actions">
                                <button class="btn btn-primary" type="submit" id="submitAdditionalConnection">
                                    Submit connection request
                                </button>
                                <p id="additionalConnectionMessage" class="availability-message"
                                   role="status" aria-live="polite"></p>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
                </details>
            </section>
            </section>
        </div>
    </div>
</div>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/data.js"></script>
<script src="assets/data-api.js"></script>
<script src="assets/map-legend.js?v=<?php echo (int) filemtime(__DIR__ . "/assets/map-legend.js"); ?>"></script>
<script>
(async function () {
    if (window.GEONET_LOAD) {
        await window.GEONET_LOAD;
    }
    const G = window.GEONET;
    const subId = <?php echo json_encode($subId); ?>;
    const initialView = <?php echo json_encode($initialView); ?>;
    const ownConnections = <?php echo json_encode($connections, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const planChangeRequests = <?php echo json_encode($planChangeRequests, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const sub = G.subscribers.find((s) => s.id === subId) || G.subscribers.find(
        (subscriber) => subscriber.id === ownConnections[0]?.id
    );
    const connectionIds = ownConnections.map((connection) => connection.id);
    const ownMapSubscribers = G.subscribers.filter((subscriber) => connectionIds.includes(subscriber.id));
    const notices = G.outages.filter((outage) =>
        outage.subscribers.some((subscriberId) => connectionIds.includes(subscriberId))
    );
    const active = notices.filter((o) => o.status === "active");
    let subMap = null;
    let connectionMap = null;

    document.getElementById("statStatus").textContent = sub?.status || "—";
    if (ownConnections.length > 1) {
        document.querySelector("#statPlan").previousElementSibling.textContent = "Connections";
        document.querySelector("#statStatus").previousElementSibling.textContent = "Connection status";
        document.querySelector("#statId").previousElementSibling.textContent = "Connection IDs";
    }
    document.getElementById("statPlan").textContent = ownConnections.length > 1
        ? String(ownConnections.length)
        : sub?.plan || "—";
    if (ownConnections.length > 1) {
        document.getElementById("statStatus").textContent = "See connection details";
        document.getElementById("statId").textContent = ownConnections
            .map((connection) => connection.id)
            .join(", ");
    } else {
        document.getElementById("statId").textContent = ownConnections[0]?.id || "—";
    }
    document.getElementById("statNotices").textContent = active.length;
    const planChangeSelect = document.getElementById("planChangeSelect");
    const planChangeConnection = document.getElementById("planChangeConnection");
    const pendingPlanChangeForConnection = (connectionId) =>
        planChangeRequests.find((request) => request.connectionId === connectionId);
    function updatePlanChangeSelection() {
        const selectedConnection = ownConnections.find(
            (connection) => connection.id === planChangeConnection.value
        );
        const pending = pendingPlanChangeForConnection(planChangeConnection.value);
        Array.from(planChangeSelect.options).forEach((option) => {
            option.disabled = Boolean(selectedConnection && option.value === selectedConnection.plan);
        });
        if (pending) {
            planChangeSelect.value = pending.requestedPlan;
            planChangeSelect.disabled = true;
            planChangeMessage.textContent =
                `Request pending for ${pending.connectionId}: ${pending.currentPlan} → ${pending.requestedPlan}.`;
            planChangeSubmit.disabled = true;
        } else {
            planChangeSelect.disabled = false;
            planChangeSelect.value = "";
            planChangeMessage.textContent = "";
            planChangeSubmit.disabled = false;
        }
    }
    planChangeConnection.addEventListener("change", updatePlanChangeSelection);

    document.getElementById("accountDetail").innerHTML = ownConnections.length
        ? ownConnections.map((connection) => `
            <div class="event">
                <strong>${escapeHtml(connection.name)} · ${escapeHtml(connection.id)}</strong>
                <p>${escapeHtml(connection.address)}<br>
                ${escapeHtml(connection.barangay)} · NAP box: ${escapeHtml(connection.napBox || "Not assigned")}<br>
                ${escapeHtml(connection.plan)} · ${escapeHtml(connection.status)}<br>
                Phone: ${escapeHtml(connection.phone)} · Connected ${escapeHtml(connection.connected)}</p>
            </div>
        `).join("")
        : "<p class='side-meta'>Account not found.</p>";
    document.getElementById("subscriptionConnections").innerHTML =
        document.getElementById("accountDetail").innerHTML;

    document.getElementById("noticeList").innerHTML = notices.length
        ? notices.map((o) => `
            <div class="event">
                <strong>${o.id} · ${o.status} · ${o.severity}</strong>
                <p>${o.barangay}<br>${o.suspected}<br>Started ${o.started}${o.resolved ? " · Restored " + o.resolved : ""}</p>
            </div>
        `).join("")
        : "<p class='side-meta' style='padding:12px'>No outage notices for your account right now.</p>";

    if (ownMapSubscribers.length) {
        subMap = L.map("subMap").setView([sub.lat, sub.lng], 15);
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 }).addTo(subMap);
        const subscriberLegendItems = Array.from(new Set(ownMapSubscribers.map((subscriber) =>
            subscriber.status === "online" ? "ownOnline"
                : subscriber.status === "offline" ? "ownOffline" : "ownIntermittent"
        )));
        if (G.coverageAreas.length) subscriberLegendItems.push("coverage");
        window.addGeoNetMapLegend(subMap, subscriberLegendItems);
        G.coverageAreas.forEach((area) => {
            L.polygon(area.coordinates, {
                color: "#7b4cc2",
                weight: 2,
                fillColor: "#8f63d2",
                fillOpacity: 0.2
            }).addTo(subMap).bindTooltip(area.name);
        });
        ownMapSubscribers.forEach((connection) => {
            const color = connection.status === "online"
                ? "#1f9d58"
                : connection.status === "offline" ? "#e24b42" : "#e0a21a";
            L.circleMarker([connection.lat, connection.lng], { radius: 12, color, fillOpacity: 0.9 })
                .addTo(subMap)
                .bindPopup(
                    `<strong>${escapeHtml(connection.name)} · ${escapeHtml(connection.id)}</strong><br>` +
                    `${escapeHtml(connection.plan)}<br>${escapeHtml(connection.status)}`
                );
        });
        if (ownMapSubscribers.length > 1) {
            subMap.fitBounds(ownMapSubscribers.map((connection) => [connection.lat, connection.lng]), {
                padding: [24, 24],
                maxZoom: 15
            });
        }
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, (character) => ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#39;"
        }[character]));
    }

    function showSubscriberView(viewName) {
        const subscriptionRequested = viewName === "subscription";
        const view = subscriptionRequested || viewName === "profile" ? "profile" : "account";
        if (subscriptionRequested) document.getElementById("manageSubscription").open = true;
        document.querySelectorAll(".content > .view").forEach((section) => {
            section.classList.toggle("active", section.id === `subscriber-view-${view}`);
        });
        document.querySelectorAll("[data-subscriber-view]").forEach((link) => {
            link.classList.toggle("active", link.dataset.subscriberView === view);
        });
        const viewTitles = {
            account: ["Account overview", "Your connection status and nearby network notices"],
            subscription: ["Manage subscription", "View connections, request plan changes, or add a connection"],
            profile: ["Profile", "Manage your account details"]
        };
        document.querySelector(".topbar h2").textContent = viewTitles[view][0];
        document.querySelector(".topbar-sub").textContent = viewTitles[view][1];
        if (view === "account" && subMap) {
            setTimeout(() => subMap.invalidateSize(), 100);
        }
        if (view === "profile" && document.getElementById("manageSubscription").open && connectionMap) {
            setTimeout(() => connectionMap.invalidateSize(), 100);
        }
    }
    document.querySelectorAll("[data-subscriber-view]").forEach((link) => {
        link.addEventListener("click", (event) => {
            event.preventDefault();
            history.replaceState(null, "", link.getAttribute("href"));
            showSubscriberView(link.dataset.subscriberView);
        });
    });
    document.querySelector("[data-subscriber-profile-link]").addEventListener("click", (event) => {
        event.preventDefault();
        history.replaceState(null, "", "#profile");
        showSubscriberView("profile");
    });
    window.addEventListener("hashchange", () => {
        showSubscriberView(location.hash.slice(1) || initialView);
    });
    document.getElementById("manageSubscription").addEventListener("toggle", (event) => {
        if (event.currentTarget.open && connectionMap) setTimeout(() => connectionMap.invalidateSize(), 100);
    });
    showSubscriberView(location.hash.slice(1) || initialView);

    const profileForm = document.getElementById("profileForm");
    const profileMessage = document.getElementById("profileMessage");
    const profileSave = document.getElementById("saveProfile");
    const profilePassword = profileForm.elements.password;
    const profileConfirmPassword = profileForm.elements.confirmPassword;
    function validateProfilePassword() {
        profileConfirmPassword.setCustomValidity(
            profileConfirmPassword.value && profileConfirmPassword.value !== profilePassword.value
                ? "The passwords do not match."
                : ""
        );
    }
    profilePassword.addEventListener("input", validateProfilePassword);
    profileConfirmPassword.addEventListener("input", validateProfilePassword);
    profileForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        validateProfilePassword();
        if (!profileForm.reportValidity()) return;
        profileSave.disabled = true;
        profileMessage.textContent = "";
        profileMessage.classList.remove("error");
        try {
            const response = await fetch("api/profile.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({
                    action: "update_profile",
                    csrfToken: document.getElementById("profileCsrfToken").value,
                    ...Object.fromEntries(new FormData(profileForm).entries())
                })
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error(result.error || "Could not update your profile.");
            }
            profilePassword.value = "";
            profileConfirmPassword.value = "";
            profileMessage.textContent = result.message;
            document.querySelector(".user-chip").textContent =
                `${result.profile.name} · Subscriber`;
        } catch (error) {
            profileMessage.textContent = error.message || "Could not update your profile.";
            profileMessage.classList.add("error");
        } finally {
            profileSave.disabled = false;
        }
    });

    const planChangeForm = document.getElementById("planChangeForm");
    const planChangeMessage = document.getElementById("planChangeMessage");
    const planChangeSubmit = document.getElementById("submitPlanChange");
    updatePlanChangeSelection();
    planChangeForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!planChangeForm.reportValidity()) return;
        planChangeSubmit.disabled = true;
        planChangeMessage.textContent = "";
        planChangeMessage.classList.remove("error");
        try {
            const response = await fetch("api/profile.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({
                    action: "request_plan_change",
                    csrfToken: document.getElementById("profileCsrfToken").value,
                    connectionId: planChangeConnection.value,
                    plan: planChangeSelect.value
                })
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error(result.error || "Could not request your plan change.");
            }
            planChangeSelect.disabled = true;
            planChangeMessage.textContent =
                `Request pending for ${result.request.currentPlan} → ${result.request.requestedPlan}.`;
            planChangeRequests.push({
                connectionId: planChangeConnection.value,
                currentPlan: result.request.currentPlan,
                requestedPlan: result.request.requestedPlan
            });
        } catch (error) {
            planChangeSubmit.disabled = false;
            planChangeMessage.textContent = error.message || "Could not request your plan change.";
            planChangeMessage.classList.add("error");
        }
    });

    const additionalConnectionForm = document.getElementById("additionalConnectionForm");
    if (additionalConnectionForm) {
        const barangaySelect = document.getElementById("connectionBarangay");
        const napBoxSelect = document.getElementById("connectionNapBox");
        const connectionLocationPicker = document.getElementById("connectionLocationPicker");
        const connectionMapStatus = document.getElementById("connectionMapStatus");
        const connectionLat = document.getElementById("connectionLat");
        const connectionLng = document.getElementById("connectionLng");
        const radiusMeters = Number(G.napBoxRadiusMeters || 150);
        let selectedNapMarker = null;
        let selectedNapRadius = null;
        let connectionPin = null;
        function initializeConnectionMap() {
            if (connectionMap) return;
            connectionMap = L.map("connectionMap").setView(G.center, 13);
            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                maxZoom: 19,
                attribution: "&copy; OpenStreetMap"
            }).addTo(connectionMap);
            G.coverageAreas.forEach((area) => {
                L.polygon(area.coordinates, {
                    color: "#7b4cc2",
                    weight: 2,
                    fillColor: "#8f63d2",
                    fillOpacity: 0.2
                }).addTo(connectionMap).bindTooltip(area.name);
            });
            document.getElementById("connectionMap").addEventListener("geonet:location", event => { connectionMap.fire("click", {latlng:L.latLng(event.detail.lat,event.detail.lng)}); });
            connectionMap.on("click", (event) => {
                const napBox = availableNapBoxes().find(
                    (item) => String(item.id) === napBoxSelect.value
                );
                if (!napBox) {
                    connectionMapStatus.textContent = "Choose an available NAP box first.";
                    return;
                }
                if (!isInsideCoverage(event.latlng.lat, event.latlng.lng)) {
                    connectionMapStatus.textContent = "Choose a pin inside network coverage.";
                    return;
                }
                if (connectionMap.distance(event.latlng, [napBox.lat, napBox.lng]) > radiusMeters) {
                    connectionMapStatus.textContent =
                        `Choose a pin within ${radiusMeters} meters of the selected NAP box.`;
                    return;
                }
                clearConnectionPin();
                connectionLat.value = event.latlng.lat.toFixed(6);
                connectionLng.value = event.latlng.lng.toFixed(6);
                connectionPin = L.marker(event.latlng).addTo(connectionMap)
                    .bindPopup("Additional connection location").openPopup();
                connectionMapStatus.textContent = "Service location selected.";
            });
        }
        G.barangays.forEach((barangay) => {
            const option = document.createElement("option");
            option.value = String(barangay.name);
            option.textContent = barangay.name;
            barangaySelect.appendChild(option);
        });
        function clearConnectionPin() {
            connectionLat.value = "";
            connectionLng.value = "";
            if (connectionPin) {
                connectionMap.removeLayer(connectionPin);
                connectionPin = null;
            }
        }
        function availableNapBoxes() {
            const barangay = G.barangays.find((item) => item.name === barangaySelect.value);
            return barangay
                ? G.landmarks.filter((landmark) =>
                    landmark.type === "nap" &&
                    Number(landmark.barangay_id) === Number(barangay.id) &&
                    Number(landmark.availableSlots) > 0
                )
                : [];
        }
        function refreshNapBoxes() {
            const preferred = napBoxSelect.value;
            const options = availableNapBoxes();
            napBoxSelect.replaceChildren();
            const placeholder = document.createElement("option");
            placeholder.value = "";
            placeholder.textContent = !barangaySelect.value
                ? "Choose a barangay first"
                : options.length
                    ? "Choose an available NAP box"
                    : "No available NAP boxes for this barangay";
            napBoxSelect.appendChild(placeholder);
            options.forEach((napBox) => {
                const option = document.createElement("option");
                option.value = String(napBox.id);
                option.textContent =
                    `${napBox.name} (${napBox.availableSlots} spots left)`;
                napBoxSelect.appendChild(option);
            });
            napBoxSelect.disabled = options.length === 0;
            if (options.some((napBox) => String(napBox.id) === preferred)) {
                napBoxSelect.value = preferred;
            } else {
                napBoxSelect.value = "";
            }
        }
        function showSelectedNap() {
            [selectedNapMarker, selectedNapRadius].forEach((layer) => {
                if (layer) connectionMap.removeLayer(layer);
            });
            selectedNapMarker = null;
            selectedNapRadius = null;
            const napBox = availableNapBoxes().find(
                (item) => String(item.id) === napBoxSelect.value
            );
            connectionLocationPicker.hidden = !napBox;
            if (!napBox) return;
            initializeConnectionMap();
            connectionMap.invalidateSize({ pan: false });
            selectedNapRadius = L.circle([napBox.lat, napBox.lng], {
                radius: radiusMeters,
                color: "#0f6b78",
                weight: 2,
                fillColor: "#22a6a1",
                fillOpacity: 0.18
            }).addTo(connectionMap);
            selectedNapMarker = L.marker([napBox.lat, napBox.lng])
                .addTo(connectionMap)
                .bindPopup(`<strong>${escapeHtml(napBox.name)} · NAP box</strong>`)
                .openPopup();
            connectionMap.flyTo([napBox.lat, napBox.lng], 16);
        }
        function isInsideCoverage(lat, lng) {
            return G.coverageAreas.some((area) => {
                const points = area.coordinates || [];
                let inside = false;
                for (let i = 0, j = points.length - 1; i < points.length; j = i++) {
                    const [latA, lngA] = points[i];
                    const [latB, lngB] = points[j];
                    const cross = (lng - lngA) * (latB - latA) -
                        (lat - latA) * (lngB - lngA);
                    if (
                        Math.abs(cross) < 1e-10 &&
                        lng >= Math.min(lngA, lngB) - 1e-10 &&
                        lng <= Math.max(lngA, lngB) + 1e-10 &&
                        lat >= Math.min(latA, latB) - 1e-10 &&
                        lat <= Math.max(latA, latB) + 1e-10
                    ) return true;
                    if ((latA > lat) !== (latB > lat)) {
                        const intersectionLng =
                            (lngB - lngA) * (lat - latA) / (latB - latA) + lngA;
                        if (lng < intersectionLng) inside = !inside;
                    }
                }
                return inside;
            });
        }
        barangaySelect.addEventListener("change", () => {
            clearConnectionPin();
            refreshNapBoxes();
            showSelectedNap();
            const barangay = G.barangays.find((item) => item.name === barangaySelect.value);
            if (barangay && connectionMap) connectionMap.setView([barangay.lat, barangay.lng], 15);
        });
        napBoxSelect.addEventListener("change", () => {
            clearConnectionPin();
            showSelectedNap();
            connectionMapStatus.textContent = napBoxSelect.value
                ? `NAP box selected. Pin within its ${radiusMeters}-meter area and network coverage.`
                : "Choose a NAP box before pinning the service location.";
        });
        refreshNapBoxes();

        additionalConnectionForm.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!additionalConnectionForm.reportValidity()) return;
            if (!connectionLat.value || !connectionLng.value) {
                connectionMapStatus.textContent = "Pin the service location on the map before submitting.";
                return;
            }
            const submitButton = document.getElementById("submitAdditionalConnection");
            const message = document.getElementById("additionalConnectionMessage");
            submitButton.disabled = true;
            message.textContent = "";
            message.classList.remove("error");
            try {
                const response = await fetch("api/profile.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    credentials: "same-origin",
                    body: JSON.stringify({
                        action: "request_additional_connection",
                        csrfToken: document.getElementById("profileCsrfToken").value,
                        ...Object.fromEntries(new FormData(additionalConnectionForm).entries())
                    })
                });
                const result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(result.error || "Could not request an additional connection.");
                }
                message.textContent = result.message;
                additionalConnectionForm.querySelectorAll("input, select, button").forEach((control) => {
                    control.disabled = true;
                });
            } catch (error) {
                submitButton.disabled = false;
                message.textContent = error.message || "Could not request an additional connection.";
                message.classList.add("error");
            }
        });
    }
})();
</script>
<script src="assets/profile-password.js?v=<?php echo (int) filemtime(__DIR__ . "/assets/profile-password.js"); ?>"></script>
<script src="assets/notifications.js?v=<?php echo (int) filemtime(__DIR__ . "/assets/notifications.js"); ?>"></script>
<script src="assets/accessibility.js?v=<?php echo filemtime(__DIR__ . "/assets/accessibility.js"); ?>"></script>
</body>
</html>
