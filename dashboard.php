<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/geonet.php";
geonet_require_login("admin");
$username = $_SESSION["display_name"] ?? $_SESSION["username"];
$profile = geonet_own_profile($_SESSION["user_id"] ?? null);
$csrfToken = $_SESSION["profile_csrf_token"] ?? bin2hex(random_bytes(32));
$_SESSION["profile_csrf_token"] = $csrfToken;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoNet Connect · Admin</title>
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/sms-inbox.css?v=<?php echo filemtime(__DIR__ . "/assets/sms-inbox.css"); ?>">
    <link rel="stylesheet" href="assets/app.css?v=<?php echo (int) filemtime(__DIR__ . "/assets/app.css"); ?>">
    <link rel="stylesheet" href="assets/design-system.css?v=<?php echo filemtime(__DIR__ . "/assets/design-system.css"); ?>">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon"><i class="fa-solid fa-location-dot"></i></div>
            <div>
                <h1>GeoNet Connect</h1>
                <p>R-Link Data and Solutions</p>
            </div>
        </div>
        <nav class="nav">
            <a class="nav-item" data-view="profile" href="#profile"><i class="fa-solid fa-id-card"></i> Profile</a>
            <a class="nav-item active" data-view="dashboard" href="#dashboard"><i class="fa-solid fa-map-location-dot"></i> Network Map</a>
            <a class="nav-item" data-view="subscribers" href="#subscribers"><i class="fa-solid fa-users"></i> Subscribers</a>
            <a class="nav-item" data-view="technicians" href="#technicians"><i class="fa-solid fa-helmet-safety"></i> Technicians</a>
            <a class="nav-item" data-view="maintenance" href="#maintenance"><i class="fa-solid fa-screwdriver-wrench"></i> Maintenance Support</a>
            <a class="nav-item" href="interruptions.php"><i class="fa-solid fa-wrench"></i> Interruption reports</a>
            <a class="nav-item" data-view="analytics" href="#analytics"><i class="fa-solid fa-chart-line"></i> Analytics</a>
            <a class="nav-item" data-view="sms" href="#sms"><i class="fa-solid fa-comment-sms"></i> SMS messages</a>
            <a class="nav-item" data-view="settings" href="#settings"><i class="fa-solid fa-gear"></i> Settings</a>
        </nav>
        <div class="sidebar-foot">San Miguel, Iloilo · GIS ops</div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div>
                <h2 id="pageTitle">Dashboard</h2>
                <p class="topbar-sub">Network visibility across San Miguel, Iloilo</p>
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
                <a class="user-chip" href="#profile" data-admin-profile-link aria-label="Edit admin profile">
                    <?php echo htmlspecialchars($username); ?>
                </a>
                <a class="btn btn-outline" href="interruptions.php">Interruption reports</a>
                <a class="btn btn-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </div>
        </header>

        <div class="content">
            <section id="view-dashboard" class="view active">
                <div class="stats">
                    <div class="card stat">
                        <div class="stat-label">Total subscribers</div>
                        <div class="stat-value" id="statSubscribers">0</div>
                        <div class="stat-hint">San Miguel, Iloilo coverage</div>
                    </div>
                    <div class="card stat">
                        <div class="stat-label">Active outages</div>
                        <div class="stat-value accent-red" id="statOutages">0</div>
                        <div class="stat-hint">Barangay clusters in the field</div>
                    </div>
                    <div class="card stat">
                        <div class="stat-label">Technicians on duty</div>
                        <div class="stat-value accent-blue" id="statTechs">0</div>
                        <div class="stat-hint">Available, on duty, or en route</div>
                    </div>
                    <div class="card stat">
                        <div class="stat-label">Online now</div>
                        <div class="stat-value accent-green" id="statOnline">0</div>
                        <div class="stat-hint">Green pins on the live map</div>
                    </div>
                </div>
                <div class="toolbar">
                    <label class="map-select-field" for="outageFilter"><span class="map-select-caption">Outage status</span><span class="map-select-control"><i class="fa-solid fa-filter" aria-hidden="true"></i><select id="outageFilter">
                        <option value="all">All outages</option>
                        <option value="active">Active</option>
                        <option value="resolved">Resolved</option>
                    </select></span></label>
                    <button class="btn btn-outline" type="button" id="toggleMapEditor"
                            aria-expanded="false" aria-controls="mapEditor">
                        <i class="fa-solid fa-pen-to-square"></i> Edit network map
                    </button>
                </div>
                <div class="dash-grid">
                    <div class="card map-card">
                        <div class="map-head">
                            <h3>San Miguel network map</h3>
                        </div>
                        <div class="map-editor" id="mapEditor" hidden>
                            <div class="map-editor-head">
                                <div>
                                    <h3>Map editing tools</h3>
                                    <p class="side-meta">Choose an item to edit or add it directly on the map.</p>
                                </div>
                            </div>
                            <div class="map-tools">
                                <button class="btn btn-outline" type="button" id="toggleSubscriberManager" aria-expanded="false" aria-controls="subscriberManager">Manage subscribers</button>
                                <button class="btn btn-outline" type="button" id="toggleNapManager">Manage NAP boxes</button>
                                <button class="btn btn-outline" type="button" id="toggleBarangayManager">Manage barangays</button>
                                <button class="btn btn-outline" type="button" id="toggleCoverageManager">Manage coverage</button>
                            </div>
                            <div class="card panel map-editor-section" id="subscriberManager" hidden>
                                <div class="panel-head"><div><h3>Subscribers</h3><p class="side-meta">Choose a connection, then place its pin on the network map.</p></div></div>
                                <div class="toolbar">
                                <label class="map-select-field" for="mapSubscriber"><span class="map-select-caption">Subscriber connection</span><span class="map-select-control"><i class="fa-solid fa-user" aria-hidden="true"></i><select id="mapSubscriber" aria-label="Subscriber to place">
                                    <option value="">Select subscriber…</option>
                                </select></span></label>
                                <button class="btn btn-outline" type="button" id="placeSubscriberPin">Place subscriber pin</button>
                                </div>
                            </div>
                            <div class="card panel map-editor-section" id="napManager" hidden>
                                <div class="panel-head">
                                    <div>
                                        <h3>NAP boxes</h3>
                                        <p class="side-meta">Click a NAP box to view its 300 m radius and available slots. Use Edit NAP box to move or rename it.</p>
                                    </div>
                                </div>
                                <div class="toolbar">
                                    <label class="map-select-field" for="napBoxSelect"><span class="map-select-caption">NAP box</span><span class="map-select-control"><i class="fa-solid fa-network-wired" aria-hidden="true"></i><select id="napBoxSelect"><option value="">Select a NAP box...</option></select></span></label>
                                    <button class="btn btn-outline" type="button" id="addNapPin">Add NAP box</button>
                                    <button class="btn btn-ghost" type="button" id="cancelNapPlacement" hidden>Cancel</button>
                                    <button class="btn btn-outline" type="button" id="editNapBox">Edit NAP box</button>
                                </div>
                            </div>
                            <div class="card panel map-editor-section" id="barangayManager" hidden>
                                <div class="panel-head"><div><h3>Barangays</h3><p class="side-meta">Select a barangay to locate it on the map. Drag its highlighted pin to move it.</p></div></div>
                                <div class="toolbar">
                                    <label class="map-select-field" for="barangaySelect"><span class="map-select-caption">Barangay</span><span class="map-select-control"><i class="fa-solid fa-location-dot" aria-hidden="true"></i><select id="barangaySelect"><option value="">Select a barangay...</option></select></span></label>
                                    <button class="btn btn-primary" type="button" id="addBarangayButton">Add barangay</button>
                                    <button class="btn btn-outline" type="button" id="editBarangayButton" disabled>Edit selected</button>
                                    <button class="btn btn-danger" type="button" id="removeBarangayButton" disabled>Remove selected</button>
                                </div>
                                <div class="barangay-nap-list" id="barangayNapBoxes" aria-live="polite"><p class="side-meta">Select a barangay to view its connected NAP boxes.</p></div>
                            </div>
                            <div class="card panel map-editor-section" id="coverageManager" hidden>
                                <div class="panel-head"><h3>Manage coverage area</h3></div>
                                <div class="toolbar">
                                    <label class="map-select-field" for="coverageAreaSelect"><span class="map-select-caption">Coverage area</span><span class="map-select-control"><i class="fa-solid fa-draw-polygon" aria-hidden="true"></i><select id="coverageAreaSelect" aria-label="Coverage area to edit">
                                        <option value="">Select coverage area…</option>
                                    </select></span></label>
                                    <button class="btn btn-primary" type="button" id="editCoverageArea" disabled>Edit selected area</button>
                                    <button class="btn btn-outline" type="button" id="drawCoverage">Draw coverage area</button>
                                    <button class="btn btn-ghost" type="button" id="undoCoveragePoint" disabled>Undo point</button>
                                    <button class="btn btn-primary" type="button" id="finishCoverage" disabled>Save area</button>
                                    <button class="btn btn-ghost" type="button" id="cancelMapAction" disabled>Cancel</button>
                                </div>
                                <p class="map-action-status" id="coverageActionStatus" role="status">Select an area to edit or draw a new coverage area.</p>
                            </div>
                        </div>
                        <p class="map-action-status" id="mapActionStatus" role="status" aria-live="polite"></p>
                        <details class="map-coordinate-tools" id="mapKeyboardTools"><summary>Map location controls (keyboard alternative)</summary>
                            <p class="side-meta">Choose a map action above, then enter coordinates to place a pin. You can also move a selected NAP box or barangay without dragging.</p>
                            <div class="map-coordinate-fields"><label>Latitude<input type="number" id="mapInputLat" step="any" min="-90" max="90" value="10.7794"></label><label>Longitude<input type="number" id="mapInputLng" step="any" min="-180" max="180" value="122.4668"></label><button type="button" class="btn btn-outline" id="copyMapCoordinates">Use map center</button><button type="button" class="btn btn-primary" id="applyMapCoordinates">Apply to current action</button></div>
                            <label for="mapCoordinateTarget">Action</label><select id="mapCoordinateTarget"><option value="point">Current placement / drawing action</option><option value="nap">Move selected NAP box</option><option value="barangay">Move selected barangay</option></select>
                            <p id="coordinateStatus" role="status" aria-live="polite"></p>
                            <details><summary>Edit coverage coordinates</summary><p class="side-meta">Choose an existing area in Manage coverage. Enter one latitude,longitude pair per line (at least three points), then save.</p><label for="coverageCoordinateText">Boundary coordinates</label><textarea id="coverageCoordinateText" rows="5"></textarea><div class="toolbar"><button id="loadCoverageCoordinates" type="button" class="btn btn-outline">Load selected boundary</button><button id="saveCoverageCoordinates" type="button" class="btn btn-primary">Save selected boundary</button></div></details>
                        </details>
                        <div id="dashMap" class="map"></div>
                    </div>
                    <div class="card panel dashboard-notification-feed">
                        <div class="panel-head">
                            <div>
                                <h3>Network activity</h3>
                                <p class="side-meta">Outages, interruption reports, subscriber requests, and network map updates.</p>
                            </div>
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
                    </div>
                </div>
            </section>

            <section id="view-subscribers" class="view">
                <div class="card panel subscription-request-panel">
                    <div class="panel-head">
                        <div>
                            <h3>Subscription requests</h3>
                            <p class="side-meta">Review new subscriptions and additional connection requests, including their required service work.</p>
                        </div>
                        <button class="btn btn-outline" type="button" id="refreshSubscriptionRequests">Refresh</button>
                    </div>
                    <div id="subscriptionRequests" class="subscription-request-list">
                        <p class="side-meta">Loading subscription requests…</p>
                    </div>
                </div>
                <div class="card panel subscription-request-panel" style="margin-top:16px">
                    <div class="panel-head">
                        <div>
                            <h3>Plan change requests</h3>
                            <p class="side-meta">Approve or reject subscriber requests to change service plans.</p>
                        </div>
                    </div>
                    <div id="planChangeRequests" class="subscription-request-list">
                        <p class="side-meta">Loading plan change requests…</p>
                    </div>
                </div>
                <div class="toolbar">
                    <button class="btn btn-primary" type="button" id="showAddSubscriber"><i class="fa-solid fa-user-plus"></i> Add subscriber</button>
                    <input id="subSearch" type="search" placeholder="Search name, ID, barangay…">
                    <select id="subStatus">
                        <option value="">All statuses</option>
                        <option value="online">Online</option>
                        <option value="offline">Offline</option>
                        <option value="intermittent">Intermittent</option>
                    </select>
                    <select id="subBarangay"></select>
                </div>
                <div class="split">
                    <div class="card table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Barangay</th>
                                    <th>Plan</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="subTable"></tbody>
                        </table>
                    </div>
                    <div class="card side-panel" id="subDetail">
                        <p class="side-meta">Select a subscriber to view their details.</p>
                    </div>
                </div>
            </section>

            <section id="view-technicians" class="view">
                <div class="toolbar">
                    <button class="btn btn-primary" type="button" id="showAddTechnician"><i class="fa-solid fa-user-plus"></i> Add technician</button>
                </div>
                <div class="tech-grid" id="techGrid"></div>
            </section>

            <section id="view-maintenance" class="view">
                <section class="card panel operations-panel">
                    <div class="panel-head"><div><h3>Scheduled maintenance</h3><p class="side-meta">Schedule inspections and repairs. Due dates use Manila time. Linked work orders do not close subscriber reports automatically.</p></div><a class="btn btn-outline" href="interruptions.php">Interruption reports</a></div>
                    <details><summary>Create work order</summary>
                    <form id="workOrderForm" class="operations-form">
                        <label>Task<input name="title" maxlength="160" placeholder="NAP inspection or fiber repair" required></label>
                        <label>Barangay<select name="barangay_id" id="workBarangay" required></select></label>
                        <label>NAP box (optional)<select name="nap_box_id" id="workNap"></select></label>
                        <label>Technician<select name="technician_id" id="workTechnician" required></select></label>
                        <label>Due date / time<input name="due_at" type="datetime-local" required></label>
                        <label>Priority<select name="priority"><option value="medium">Medium</option><option value="low">Low</option><option value="high">High</option><option value="critical">Critical</option></select></label>
                        <label>Linked interruption (optional)<select name="interruption_id" id="workInterruption"></select></label>
                        <button class="btn btn-primary" type="submit">Schedule work</button>
                    </form></details>
                    <div class="toolbar" style="margin-top:18px"><label>Status <select id="workStatus"><option value="">All work orders</option><option value="scheduled">Scheduled</option><option value="in_progress">In progress</option><option value="completed">Completed</option><option value="overdue">Overdue</option></select></label><button id="refreshWorkOrders" class="btn btn-outline" type="button">Refresh</button><span id="workSummary" class="side-meta"></span></div>
                    <p id="workFeedback" role="status" aria-live="polite"></p><div id="workOrderList" class="work-order-list"></div>
                </section>
                <h3>Support tickets</h3>
                <div class="stats">
                    <div class="card stat"><div class="stat-label">Open</div><div class="stat-value accent-red" id="tOpen">0</div></div>
                    <div class="card stat"><div class="stat-label">In progress</div><div class="stat-value accent-blue" id="tProgress">0</div></div>
                    <div class="card stat"><div class="stat-label">Scheduled</div><div class="stat-value accent-yellow" id="tScheduled">0</div></div>
                    <div class="card stat"><div class="stat-label">Closed</div><div class="stat-value accent-green" id="tClosed">0</div></div>
                </div>
                <div class="charts">
                    <div class="card chart-box">
                        <div class="panel-head"><h3>Ticket volume trend</h3></div>
                        <canvas id="trendChart"></canvas>
                    </div>
                    <div class="card chart-box">
                        <div class="panel-head"><h3>Incidents by component</h3></div>
                        <canvas id="componentChart"></canvas>
                    </div>
                </div>
                <div class="toolbar">
                    <select id="ticketPriority">
                        <option value="">All priorities</option>
                        <option value="critical">Critical</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>
                    <select id="ticketStatus">
                        <option value="">All statuses</option>
                        <option value="open">Open</option>
                        <option value="in_progress">In progress</option>
                        <option value="scheduled">Scheduled</option>
                        <option value="closed">Closed</option>
                    </select>
                    <button class="btn btn-outline" id="downloadReport"><i class="fa-solid fa-download"></i> Download report</button>
                </div>
                <div class="split">
                    <div class="card table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Ticket</th>
                                    <th>Opened</th>
                                    <th>Barangay</th>
                                    <th>Component</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="ticketTable"></tbody>
                        </table>
                    </div>
                    <div class="card side-panel" id="ticketDetail">
                        <p class="side-meta">Select a ticket sourced from outage and barangay analytics.</p>
                    </div>
                </div>
            </section>

            <section id="view-analytics" class="view">
                <form id="analyticsFilters" class="toolbar"><label>From<input type="date" id="analyticsFrom" required></label><label>Through<input type="date" id="analyticsTo" required></label><button class="btn btn-primary" type="submit">Apply dates</button><button class="btn btn-outline" type="button" id="analyticsExport" disabled>Download report CSV</button></form>
                <p id="analyticsFeedback" role="status" aria-live="polite"></p>
                <p class="side-meta">Incident charts include reports opened in the selected period. Capacity and subscriber status are current snapshots. Repair time ends at technician completion; it does not include subscriber confirmation. Uptime is unavailable without continuous monitoring.</p>
                <div id="operationalMetrics" class="stats"></div>
                <div class="charts"><section class="card chart-box"><h3>Subscriber interruptions by day</h3><canvas id="reportDayChart"></canvas></section><section class="card chart-box"><h3>Incidents by barangay</h3><canvas id="reportBarangayChart"></canvas></section></div>
                <div class="settings-grid"><section class="card panel"><h3>Current NAP capacity</h3><p class="side-meta">Occupied includes subscribers and pending applications. Near capacity means at least 80% occupied.</p><div id="napCapacityReport" class="table-wrap"></div></section><section class="card panel"><h3>Technician repair workload</h3><div id="technicianReport" class="table-wrap"></div><h3>Current subscriber status</h3><p class="side-meta">Based on reports and saved records, rather than live connectivity monitoring.</p><div id="subscriberStatusReport"></div></section></div>

            </section>

            <section id="view-settings" class="view">
                <?php $settingsSms = geonet_sms_config(); ?>
                <div class="settings-grid">
                    <section class="card panel">
                        <div class="panel-head"><div><h3>Default map view</h3><p class="side-meta">Choose where the Network Map opens for administrators. Applies on the next page load.</p></div></div>
                        <form id="mapSettingsForm" class="inbox-form">
                            <label>Center latitude<input id="settingsLatitude" type="number" step="any" min="-90" max="90" required value="<?php echo htmlspecialchars(geonet_setting('map_center_lat', '10.7794')); ?>"></label>
                            <label>Center longitude<input id="settingsLongitude" type="number" step="any" min="-180" max="180" required value="<?php echo htmlspecialchars(geonet_setting('map_center_lng', '122.4668')); ?>"></label>
                            <label>Default zoom<input id="settingsZoom" type="number" step="1" min="3" max="19" required value="<?php echo htmlspecialchars(geonet_setting('map_default_zoom', '14')); ?>"></label>
                            <div class="toolbar"><button class="btn btn-outline" id="useCurrentMapView" type="button">Use current map view</button><button class="btn btn-ghost" id="resetMapDefaults" type="button">Fill original defaults</button></div>
                            <p class="side-meta">Higher zoom shows more detail. Original defaults center on San Miguel, Iloilo.</p>
                            <button class="btn btn-primary" id="saveMapSettings" type="submit">Save map defaults</button>
                            <p id="mapSettingsStatus" role="status" aria-live="polite"></p>
                        </form>
                    </section>
                    <div>
                        <section class="card panel"><div class="panel-head"><h3>SMS readiness</h3></div>
                            <p>Notifications: <strong><?php echo $settingsSms['enabled'] ? 'Enabled' : 'Disabled'; ?></strong></p>
                            <p>Sending mode: <strong><?php echo $settingsSms['mock_mode'] ? 'Mock mode' : 'Live SMS'; ?></strong></p>
                            <p>Cloud credentials: <strong><?php echo $settingsSms['username'] !== '' && $settingsSms['password'] !== '' ? 'Saved' : 'Not configured'; ?></strong></p>
                            <p>Incoming signing key: <strong><?php echo $settingsSms['signing_key'] !== '' ? 'Saved' : 'Not configured'; ?></strong></p>
                            <p class="side-meta">Configuration status only. Delivery also requires your phone and connection to be available.</p>
                            <a class="btn btn-outline" href="#sms">Edit SMS API</a>
                        </section>
                        <section class="card panel" style="margin-top:20px"><div class="panel-head"><h3>Account and security</h3></div><p class="side-meta">Update your administrator details or change your password from your profile.</p><a class="btn btn-outline" href="#profile">Open profile</a></section>
                    </div>
                </div>
            </section>
            <section id="view-sms" class="view">
                <details class="sms-configuration"><summary>Edit SMS API and sending history</summary>
                <div class="settings-grid">
                    <div class="card panel">
                        <div class="panel-head"><h3>SMS API (SMSGate)</h3></div>

                        <p class="side-meta">Outage reports target only technicians assigned to the outage (or on-duty technicians when unassigned) and its affected subscribers. Live sends are processed by SMSGate; provider acceptance is not always final delivery confirmation.</p>
                        <form id="smsSettingsForm">
                            <div class="check">
                                <input type="checkbox" id="smsEnabled" checked>
                                <label for="smsEnabled">Enable SMS notifications</label>
                            </div>
                            <div class="check">
                                <input type="checkbox" id="smsMock" checked>
                                <label for="smsMock">Mock mode (log locally, do not send to carrier)</label>
                            </div>
                            <div class="field"><label for="smsUsername">SMSGate username</label><input id="smsUsername" type="text" maxlength="512" autocomplete="off"></div>
                            <div class="field"><label for="smsPassword">SMSGate password (blank keeps saved password)</label><input id="smsPassword" type="password" maxlength="512" autocomplete="off"></div>
                            <div class="field"><label for="smsDevice">Device ID (optional)</label><input id="smsDevice" type="text" maxlength="512" autocomplete="off"></div>
                            <div class="field"><label for="smsSigningKey">Webhook signing key (blank keeps saved key)</label><input id="smsSigningKey" type="password" maxlength="512" autocomplete="off"></div>
                            <p class="side-meta">Uses your SIM number and SMS allowance. Keep the phone online. Copy the signing key from SMSGate Settings &gt; Webhooks &gt; Signing Key. Register an sms:received webhook to your public HTTPS address + /api/smsgate-webhook.php (include /geonet_connect when hosted in that folder).</p>
                            <p id="smsSettingsMessage" class="availability-message" role="status" aria-live="polite"></p>
                            <button class="btn btn-primary" type="submit">Save SMS settings</button>
                        </form>
                    </div>
                    <div class="card panel">
                        <div class="panel-head">
                            <div>
                                <h3>Recent SMS log</h3>
                                <p class="side-meta">Submissions are queued. Check the SMSGate dashboard for final delivery status.</p>
                            </div>
                            <button class="btn btn-danger" type="button" id="clearSmsLogs">Clear log</button>
                        </div>
                        <p id="smsLogMessage" class="availability-message" role="status" aria-live="polite"></p>
                        <div id="smsLog"></div>
                    </div>
                </div>
                </details>
                <?php $smsEmbedded = true; require __DIR__ . '/sms-inbox.php'; unset($smsEmbedded); ?>
            </section>
            <section id="view-profile" class="view">
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
                            <span class="side-meta">Use an address ending in @admin.rlink.ph.</span>
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
                </section>
            </section>
        </div>
    </div>
</div>

<div class="modal-back" id="smsModal">
    <div class="modal">
        <h3>SMS outage report</h3>
        <p class="side-meta">Notify technicians and/or subscribers about an active cluster.</p>
        <div class="field">
            <label for="smsTemplate">Message template</label>
            <select id="smsTemplate">
                <option value="outage">Outage update</option>
                <option value="crew">Crew dispatched</option>
                <option value="custom">Custom message</option>
            </select>
        </div>
        <p id="smsModeNotice" class="side-meta" role="status"></p>
        <div class="field">
            <label for="smsOutageSelect">Outage</label>
            <select id="smsOutageSelect"></select>
        </div>
        <div class="field">
            <label for="smsAudience">Send to</label>
            <select id="smsAudience">
                <option value="both">Technicians and affected subscribers</option>
                <option value="technicians">Technicians only</option>
                <option value="subscribers">Affected subscribers only</option>
            </select>
        </div>
        <div class="sms-recipient-preview">
            <strong id="smsRecipientCount" role="status" aria-live="polite"></strong>
            <ul id="smsRecipientList"></ul>
        </div>
        <div class="field">
            <label for="smsMessage">Message</label>
            <textarea id="smsMessage" maxlength="1000" required></textarea>
            <span class="side-meta" id="smsCharacterCount">0 / 1,000 characters · long messages may use multiple SMS segments.</span>
        </div>
        <input id="smsCsrfToken" type="hidden" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="btn btn-ghost" type="button" id="smsCancel">Cancel</button>
            <button class="btn btn-primary" type="button" id="smsSend"><i class="fa-solid fa-paper-plane"></i> Send SMS</button>
        </div>
    </div>
</div>

<div class="modal-back record-modal-back" id="addSubscriberModal" aria-hidden="true">
    <section class="modal record-modal" role="dialog" aria-modal="true" aria-labelledby="addSubscriberTitle">
        <header class="record-modal-head">
            <div>
                <span class="record-modal-kicker">CUSTOMER ACCOUNT</span>
                <h3 id="addSubscriberTitle">Add subscriber</h3>
                <p>Enter account details. You can place their map pin later.</p>
            </div>
            <button class="icon-button" type="button" id="cancelAddSubscriber" aria-label="Close form">&times;</button>
        </header>
        <form id="addSubscriberForm">
            <div class="record-form-grid">
                <label class="record-field">Full name
                    <input name="name" maxlength="120" autocomplete="name" required>
                </label>
                <label class="record-field">Barangay
                    <select name="barangay" id="addSubBarangay" required></select>
                </label>
                <label class="record-field">Email / login
                    <input name="email" type="email" maxlength="190" autocomplete="email" placeholder="name@sub.rlink.ph" required>
                    <small>Subscriber accounts must use @sub.rlink.ph.</small>
                </label>
                <label class="record-field">Phone
                    <input name="phone" maxlength="20" autocomplete="tel" required>
                </label>
                <label class="record-field record-field-wide">Service address
                    <input name="address" maxlength="255" autocomplete="street-address" required>
                </label>
                <label class="record-field">Plan
                    <input name="plan" maxlength="80" required>
                </label>
                <label class="record-field">Status
                    <select name="status" required>
                        <option value="online">Online</option>
                        <option value="offline">Offline</option>
                        <option value="intermittent">Intermittent</option>
                    </select>
                </label>
                <label class="record-field record-field-wide">Initial password
                    <input name="password" type="password" minlength="8" autocomplete="new-password" required>
                    <small>Use at least 8 characters.</small>
                </label>
            </div>
            <footer class="record-modal-actions">
                <button class="btn btn-ghost" type="button" id="cancelAddSubscriberFooter">Cancel</button>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-user-plus"></i> Create subscriber</button>
            </footer>
        </form>
    </section>
</div>

<div class="modal-back record-modal-back" id="addTechnicianModal" aria-hidden="true">
    <section class="modal record-modal" role="dialog" aria-modal="true" aria-labelledby="addTechnicianTitle">
        <header class="record-modal-head">
            <div>
                <span class="record-modal-kicker">FIELD TEAM ACCOUNT</span>
                <h3 id="addTechnicianTitle">Add technician</h3>
                <p>Create a login and add a technician to the team roster.</p>
            </div>
            <button class="icon-button" type="button" id="cancelAddTechnician" aria-label="Close form">&times;</button>
        </header>
        <form id="addTechnicianForm">
            <div class="record-form-grid">
                <label class="record-field">Full name
                    <input name="name" maxlength="120" autocomplete="name" required>
                </label>
                <label class="record-field">Phone
                    <input name="phone" maxlength="20" autocomplete="tel" required>
                </label>
                <label class="record-field record-field-wide">Email / login
                    <div class="email-composite">
                        <input name="username" maxlength="64" pattern="[A-Za-z0-9._%+\-]+"
                               autocomplete="username" required>
                        <span aria-hidden="true">@tech.rlink.ph</span>
                    </div>
                    <small>Choose the username for the technician login.</small>
                </label>
                <label class="record-field">Job title
                    <input name="jobTitle" maxlength="80" required>
                </label>
                <label class="record-field record-field-wide">Initial password
                    <input name="password" type="password" minlength="8" autocomplete="new-password" required>
                    <small>Use at least 8 characters.</small>
                </label>
            </div>
            <footer class="record-modal-actions">
                <button class="btn btn-ghost" type="button" id="cancelAddTechnicianFooter">Cancel</button>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-user-plus"></i> Create technician</button>
            </footer>
        </form>
    </section>
</div>

<div class="toast" id="toast"></div>

<script>
// Apply deep-linked navigation before scripts and API data finish loading.
(() => {
    const links = Array.from(document.querySelectorAll('.nav-item[data-view]'));
    const requested = location.hash.slice(1) || 'dashboard';
    const selected = links.find(link => link.dataset.view === requested)
        || links.find(link => link.dataset.view === 'dashboard');
    const view = selected.dataset.view;
    links.forEach(link => {
        const active = link === selected;
        link.classList.toggle('active', active);
        if (active) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
    });
    document.querySelectorAll('.view').forEach(section => {
        section.classList.toggle('active', section.id === 'view-' + view);
    });
    document.getElementById('pageTitle').textContent = view === 'dashboard'
        ? 'Dashboard' : selected.textContent.trim();
    document.querySelector('[data-notification-center]').hidden = view === 'dashboard';
})();
</script>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/vendor/chart/chart.umd.js"></script>
<script src="assets/data.js"></script>
<script src="assets/data-api.js"></script>
<script src="assets/map-legend.js?v=<?php echo (int) filemtime(__DIR__ . "/assets/map-legend.js"); ?>"></script>
<script src="assets/interruption-popup.js?v=<?php echo (int) filemtime(__DIR__ . "/assets/interruption-popup.js"); ?>"></script>
<script src="assets/operations.js?v=<?php echo filemtime(__DIR__ . "/assets/operations.js"); ?>"></script>
<script src="assets/app.js?v=<?php echo (int) filemtime(__DIR__ . "/assets/app.js"); ?>"></script>
<script src="assets/profile-password.js?v=<?php echo (int) filemtime(__DIR__ . "/assets/profile-password.js"); ?>"></script>
<script src="assets/notifications.js?v=<?php echo (int) filemtime(__DIR__ . "/assets/notifications.js"); ?>"></script>
<script src="assets/accessibility.js?v=<?php echo filemtime(__DIR__ . "/assets/accessibility.js"); ?>"></script>
</body>
</html>
