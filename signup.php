<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/geonet.php";

if (isset($_SESSION["logged_in"])) {
    header("Location: " . geonet_home($_SESSION["role"] ?? "admin"));
    exit();
}

$error = "";
$signupMapData = [
    "center" => [10.7794, 122.4668],
    "barangays" => [],
    "coverageAreas" => [],
    "napBoxes" => [],
];
$requestSubmitted = false;
$availablePlans = geonet_available_subscriber_plans();
try {
    $signupMapData = geonet_signup_map_data();
} catch (Throwable $e) {
    $error = "Sign-up is temporarily unavailable. Please try again later.";
}

if (isset($_SESSION["subscription_request_submitted"])) {
    unset($_SESSION["subscription_request_submitted"]);
    $requestSubmitted = true;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $username = trim((string) ($_POST["username"] ?? ""));
        if ($username === "" || strlen($username) > 64 || !preg_match('/^[A-Za-z0-9._%+-]+$/D', $username)) {
            throw new InvalidArgumentException("Enter a valid username using letters, numbers, dots, underscores, or hyphens.");
        }
        $email = strtolower($username) . "@sub.rlink.ph";
        $subscriberInput = $_POST;
        $subscriberInput["email"] = $email;
        geonet_create_subscription_request($subscriberInput);
        $_SESSION["subscription_request_submitted"] = true;
        header("Location: signup.php?submitted=1");
        exit();
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = "Sign-up could not be completed. Please try again later.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoNet Connect - Subscriber Sign-up</title>
    <link rel="stylesheet" href="style.css?v=<?php echo (int) filemtime(__DIR__ . "/style.css"); ?>">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/design-system.css?v=<?php echo filemtime(__DIR__ . "/assets/design-system.css"); ?>">
</head>
<body>
<div class="login-container signup-container">
    <div class="left-panel">
        <div class="grid-overlay"></div>
        <svg class="network-lines" viewBox="0 0 700 700" preserveAspectRatio="none">
            <polyline points="70,100 130,165 225,150 310,215 375,180 440,250 510,230" fill="none"/>
            <polyline points="35,250 115,270 195,235 275,305 355,275 425,335 520,315" fill="none"/>
            <polyline points="85,415 165,400 245,435 325,405 405,445 490,425" fill="none"/>
            <polyline points="165,40 175,115 205,190 250,270 275,400" fill="none"/>
            <polyline points="340,55 310,215 340,285 375,465" fill="none"/>
            <polyline points="455,70 440,150 470,230 425,395 440,480" fill="none"/>
        </svg>
        <div class="logo">
            <div class="logo-icon"><i class="fa-solid fa-location-dot"></i></div>
            <div><h3>GeoNet Connect</h3><span>R-Link Data and Solutions</span></div>
        </div>
        <div class="hero-content">
            <h1>Join the<br>R-Link network.</h1>
            <p>Create your subscriber account to view your service information and network notices.</p>
        </div>
    </div>

    <div class="right-panel">
        <div class="login-box login-box-wide">
            <h1>Request subscriber service</h1>
            <p class="subtitle">Request service in your area. An administrator will review your request.</p>
            <?php if ($requestSubmitted): ?>
                <div class="success-message" role="status">
                    <i class="fa-solid fa-circle-check"></i>
                    Your subscription request was submitted. We’ll review your service location and contact you.
                </div>
            <?php elseif ($error !== ""): ?>
                <div class="error-message">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <?php if (!$requestSubmitted): ?>
            <form method="POST" action="signup.php" id="subscriptionRequestForm">
                <div class="signup-form-layout">
                    <div class="form-group signup-map-column">
                        <label for="signupMap">Pin your service location</label>
                        <p class="form-hint">Choose your barangay and NAP box, then pin your location inside the shaded NAP service area and network coverage.</p>
                        <div id="signupMap" class="signup-map"></div>
                        <div class="map-pick-status" id="mapPickStatus" role="status">Choose a barangay and NAP box, then place your pin inside the shaded area.</div>
                        <input type="hidden" name="lat" id="signupLat"
                               value="<?php echo htmlspecialchars((string) ($_POST["lat"] ?? "")); ?>">
                        <input type="hidden" name="lng" id="signupLng"
                               value="<?php echo htmlspecialchars((string) ($_POST["lng"] ?? "")); ?>">
                    </div>
                    <div class="signup-details-column">
                        <div class="form-group">
                            <label for="signupName">Full name</label>
                            <input type="text" name="name" id="signupName" maxlength="120" autocomplete="name"
                                   value="<?php echo htmlspecialchars((string) ($_POST["name"] ?? "")); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="signupUsername">Email username</label>
                            <div class="email-composite">
                                <input type="text" name="username" id="signupUsername" maxlength="64"
                                       pattern="[A-Za-z0-9._%+\-]+" autocomplete="username"
                                       value="<?php echo htmlspecialchars((string) ($_POST["username"] ?? "")); ?>" required>
                                <span aria-hidden="true">@sub.rlink.ph</span>
                            </div>
                            <small class="form-hint">Choose the username you want to use to sign in.</small>
                        </div>
                        <div class="form-group">
                            <label for="signupPassword">Password</label>
                            <input type="password" name="password" id="signupPassword" minlength="8"
                                   autocomplete="new-password" required>
                        </div>
                        <div class="form-group">
                            <label for="signupBarangay">Barangay</label>
                            <select name="barangay" id="signupBarangay" required <?php echo $signupMapData["barangays"] ? "" : "disabled"; ?>>
                                <option value="">Choose your barangay</option>
                                <?php foreach ($signupMapData["barangays"] as $barangay): ?>
                                    <option value="<?php echo htmlspecialchars($barangay["name"]); ?>"
                                        <?php echo (($_POST["barangay"] ?? "") === $barangay["name"]) ? "selected" : ""; ?>>
                                        <?php echo htmlspecialchars($barangay["name"]); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="signupNapBox">NAP box</label>
                            <select name="nap_box_id" id="signupNapBox" required disabled>
                                <option value="">Choose a barangay first</option>
                            </select>
                            <small class="form-hint">Each NAP box can serve up to <?php echo geonet_nap_box_capacity(); ?> clients. Pending requests also hold a spot.</small>
                            <small class="form-hint">Select a NAP box to show its location and approximate <?php echo geonet_nap_box_coverage_radius_meters(); ?>-meter service area on the map.</small>
                        </div>
                        <div class="form-group">
                            <label for="signupAddress">Service address</label>
                            <input type="text" name="address" id="signupAddress" maxlength="255"
                                   autocomplete="street-address"
                                   value="<?php echo htmlspecialchars((string) ($_POST["address"] ?? "")); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="signupPhone">Phone</label>
                            <input type="tel" name="phone" id="signupPhone" maxlength="20" autocomplete="tel"
                                   value="<?php echo htmlspecialchars((string) ($_POST["phone"] ?? "")); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="signupPlan">Requested plan</label>
                            <select name="plan" id="signupPlan" required>
                                <option value="">Choose an available plan</option>
                                <?php foreach ($availablePlans as $plan): ?>
                                    <option value="<?php echo htmlspecialchars($plan); ?>"
                                        <?php echo (($_POST["plan"] ?? "") === $plan) ? "selected" : ""; ?>>
                                        <?php echo htmlspecialchars($plan); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="login-button"
                                <?php echo $signupMapData["barangays"] && $signupMapData["napBoxes"] ? "" : "disabled"; ?>>
                            Submit subscription request
                        </button>
                    </div>
                </div>
            </form>
            <?php else: ?>
                <p class="request-access"><a href="signup.php">Submit another request</a></p>
            <?php endif; ?>
            <p class="request-access">Already have an account? <a href="index.php">Sign in</a></p>
        </div>
        <div class="footer">© 2026 R-Link Data and Solutions · San Miguel, Iloilo</div>
        <button type="button" class="help-button" aria-label="Open sign-up help" aria-controls="helpModal" aria-expanded="false">?</button>
    </div>
</div>
<div class="help-modal-back" id="helpModal" aria-hidden="true" inert>
    <div class="help-modal" role="dialog" aria-modal="true" aria-labelledby="helpModalTitle" tabindex="-1">
        <button type="button" class="help-modal-close" aria-label="Close help">&times;</button>
        <h2 id="helpModalTitle">Need help signing up?</h2>
        <p>Submit a service request for an administrator to review.</p>
        <ul>
            <li>Choose your barangay, then select a NAP box with an available spot. You can also select a NAP box on the map.</li>
            <li>Pin your service location inside both the network coverage and the selected NAP box's shaded <?php echo geonet_nap_box_coverage_radius_meters(); ?>-meter service area.</li>
            <li>Enter your details and choose a plan. Your sign-in email will be your chosen username followed by @sub.rlink.ph. Use a password with at least 8 characters.</li>
            <li>Submit your request and wait for an administrator to review your service location and contact you. If no NAP boxes have space, contact R-Link for assistance.</li>
        </ul>
        <div class="help-modal-footer">
            <span>Already have an account?</span>
            <a href="index.php">Sign in</a>
        </div>
    </div>
</div>
<script src="script.js?v=<?php echo (int) filemtime(__DIR__ . "/script.js"); ?>"></script>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/map-legend.js?v=<?php echo (int) filemtime(__DIR__ . "/assets/map-legend.js"); ?>"></script>
<?php if (!$requestSubmitted && $signupMapData["barangays"]): ?>
<script>
(function () {
    const data = <?php echo json_encode($signupMapData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const map = L.map("signupMap").setView(data.center, 13);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: "&copy; OpenStreetMap"
    }).addTo(map);

    data.coverageAreas.forEach((area) => {
        const label = L.DomUtil.create("span");
        label.textContent = area.name;
        L.polygon(area.coordinates, {
            color: "#7b4cc2",
            weight: 2,
            fillColor: "#8f63d2",
            fillOpacity: 0.2
        }).addTo(map).bindTooltip(label);
    });
    const barangaySelect = document.getElementById("signupBarangay");
    const napBoxSelect = document.getElementById("signupNapBox");
    const latitude = document.getElementById("signupLat");
    const longitude = document.getElementById("signupLng");
    const status = document.getElementById("mapPickStatus");
    let pin = null;
    let napBoxPin = null;
    let napBoxArea = null;
    const barangayNapBoxLayer = L.layerGroup().addTo(map);
    const barangayNapBoxMarkers = new Map();
    const napBoxRadiusMeters = data.napBoxRadiusMeters;
    const napBoxRadiusLabel = `${napBoxRadiusMeters}-meter`;
    const renderNapBoxOptions = (preferredId = "") => {
        const selectedBarangay = data.barangays.find(
            (item) => item.name === barangaySelect.value
        );
        const matchingNapBoxes = selectedBarangay
            ? data.napBoxes.filter(
                (item) => String(item.barangayId) === String(selectedBarangay.id)
            )
            : [];
        napBoxSelect.replaceChildren();
        const placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = !selectedBarangay
            ? "Choose a barangay first"
            : matchingNapBoxes.length
                ? matchingNapBoxes.some((item) => item.available > 0)
                    ? "Choose a NAP box connected to this barangay"
                    : "All NAP boxes for this barangay are full"
                : "No NAP boxes are connected to this barangay";
        napBoxSelect.appendChild(placeholder);
        matchingNapBoxes.forEach((napBox) => {
            const option = document.createElement("option");
            option.value = String(napBox.id);
            option.textContent = napBox.available > 0
                ? `${napBox.name} (${napBox.occupied}/${data.napBoxCapacity || 16} clients, ` +
                    `${napBox.available} spots left)`
                : `${napBox.name} (Full)`;
            option.disabled = napBox.available <= 0;
            napBoxSelect.appendChild(option);
        });
        napBoxSelect.disabled = !matchingNapBoxes.some((item) => item.available > 0);
        if (matchingNapBoxes.some((item) => String(item.id) === String(preferredId))) {
            napBoxSelect.value = String(preferredId);
        } else {
            napBoxSelect.value = "";
        }
    };
    const showSelectedNapBox = (centerMap) => {
        if (napBoxPin) {
            map.closePopup();
            napBoxPin = null;
        }
        if (napBoxArea) {
            map.removeLayer(napBoxArea);
            napBoxArea = null;
        }
        const napBox = data.napBoxes.find((item) => String(item.id) === napBoxSelect.value);
        if (!napBox) return;
        napBoxArea = L.circle([napBox.lat, napBox.lng], {
            radius: napBoxRadiusMeters,
            color: "#0f6b78",
            weight: 2,
            fillColor: "#22a6a1",
            fillOpacity: 0.18
        }).addTo(map);
        napBoxPin = barangayNapBoxMarkers.get(String(napBox.id)) || null;
        if (napBoxPin) napBoxPin.openPopup();
        if (centerMap) map.flyTo([napBox.lat, napBox.lng], 16);
    };
    const renderBarangayNapBoxMarkers = () => {
        barangayNapBoxLayer.clearLayers();
        barangayNapBoxMarkers.clear();
        const selectedBarangay = data.barangays.find(
            (item) => item.name === barangaySelect.value
        );
        if (!selectedBarangay) return [];
        const matchingNapBoxes = data.napBoxes.filter(
            (item) => String(item.barangayId) === String(selectedBarangay.id)
        );
        matchingNapBoxes.forEach((napBox) => {
            const marker = L.marker([napBox.lat, napBox.lng], {
                icon: window.createGeoNetNapIcon(),
                title: napBox.name,
                riseOnHover: true
            }).addTo(barangayNapBoxLayer);
            const popup = L.DomUtil.create("div");
            const name = L.DomUtil.create("strong", "", popup);
            name.textContent = napBox.name + " · NAP box";
            const capacity = L.DomUtil.create("div", "", popup);
            capacity.textContent = napBox.available > 0
                ? `${napBox.occupied}/${data.napBoxCapacity || 16} clients · ${napBox.available} spots left`
                : `${napBox.occupied}/${data.napBoxCapacity || 16} clients · Full`;
            marker.bindPopup(popup);
            marker.bindTooltip(napBox.name);
            marker.on("click", () => {
                if (napBox.available <= 0) return;
                napBoxSelect.value = String(napBox.id);
                napBoxSelect.dispatchEvent(new Event("change"));
            });
            barangayNapBoxMarkers.set(String(napBox.id), marker);
        });
        return matchingNapBoxes;
    };

    const distanceMeters = (latA, lngA, latB, lngB) => {
        const toRadians = (degrees) => degrees * Math.PI / 180;
        const latDifference = toRadians(latB - latA);
        const lngDifference = toRadians(lngB - lngA);
        const a = Math.sin(latDifference / 2) ** 2 +
            Math.cos(toRadians(latA)) * Math.cos(toRadians(latB)) *
            Math.sin(lngDifference / 2) ** 2;
        const boundedA = Math.max(0, Math.min(1, a));
        return 6371000 * 2 * Math.atan2(Math.sqrt(boundedA), Math.sqrt(1 - boundedA));
    };
    const selectedNapBox = () =>
        data.napBoxes.find((item) => String(item.id) === napBoxSelect.value);
    const isWithinSelectedNapBox = (lat, lng) => {
        const napBox = selectedNapBox();
        return napBox && distanceMeters(lat, lng, napBox.lat, napBox.lng) <= napBoxRadiusMeters;
    };
    const clearServicePin = () => {
        latitude.value = "";
        longitude.value = "";
        if (pin) {
            map.removeLayer(pin);
            pin = null;
        }
    };

    napBoxSelect.addEventListener("change", () => {
        clearServicePin();
        showSelectedNapBox(true);
        status.textContent = `NAP box selected. Pin your service location inside its shaded ${napBoxRadiusLabel} area.`;
    });

    const isInsideCoverage = (lat, lng) => data.coverageAreas.some((area) => {
        const points = area.coordinates || [];
        if (points.length < 3) return false;
        let inside = false;
        for (let i = 0, j = points.length - 1; i < points.length; j = i++) {
            const [latA, lngA] = points[i];
            const [latB, lngB] = points[j];
            const cross = (lng - lngA) * (latB - latA) - (lat - latA) * (lngB - lngA);
            if (
                Math.abs(cross) < 1e-10 &&
                lng >= Math.min(lngA, lngB) - 1e-10 &&
                lng <= Math.max(lngA, lngB) + 1e-10 &&
                lat >= Math.min(latA, latB) - 1e-10 &&
                lat <= Math.max(latA, latB) + 1e-10
            ) return true;
            if ((latA > lat) !== (latB > lat)) {
                const intersectionLng = (lngB - lngA) * (lat - latA) /
                    (latB - latA) + lngA;
                if (lng < intersectionLng) inside = !inside;
            }
        }
        return inside;
    });

    renderNapBoxOptions(<?php echo json_encode((string) ($_POST["nap_box_id"] ?? "")); ?>);
    const selectedBarangay = data.barangays.find((item) => item.name === barangaySelect.value);
    if (selectedBarangay) {
        const napBoxes = renderBarangayNapBoxMarkers();
        if (napBoxes.length) {
            map.fitBounds(napBoxes.map((napBox) => [napBox.lat, napBox.lng]), {
                padding: [30, 30],
                maxZoom: 15
            });
        } else {
            map.setView([selectedBarangay.lat, selectedBarangay.lng], 15);
        }
    }
    showSelectedNapBox(Boolean(napBoxSelect.value));

    if (latitude.value && longitude.value) {
        const lat = Number(latitude.value);
        const lng = Number(longitude.value);
        if (isInsideCoverage(lat, lng) && isWithinSelectedNapBox(lat, lng)) {
            pin = L.marker([lat, lng]).addTo(map);
            status.textContent = "Service location selected within the NAP box service area. Click inside the shaded area to move your pin.";
        } else {
            clearServicePin();
            status.textContent = "Choose a location inside both network coverage and the selected NAP box service area.";
        }
    }

    barangaySelect.addEventListener("change", () => {
        const barangay = data.barangays.find((item) => item.name === barangaySelect.value);
        renderNapBoxOptions();
        clearServicePin();
        showSelectedNapBox(false);
        if (!barangay) {
            renderBarangayNapBoxMarkers();
            status.textContent = "Choose a barangay and NAP box, then place your pin inside the shaded service area.";
            map.setView(data.center, 13);
            return;
        }
        const napBoxes = renderBarangayNapBoxMarkers();
        const napBox = selectedNapBox();
        status.textContent = napBox
            ? `Barangay selected. Pin within network coverage and the shaded ${napBoxRadiusLabel} NAP service area.`
            : napBoxes.length
                ? "NAP boxes for this barangay are shown on the map. Select one to show its service area."
                : "No NAP boxes are connected to this barangay yet.";
        if (napBoxes.length) {
            map.fitBounds(napBoxes.map((item) => [item.lat, item.lng]), {
                padding: [30, 30],
                maxZoom: 15
            });
        } else {
            map.flyTo([barangay.lat, barangay.lng], 15);
        }
    });

    document.getElementById("signupMap").addEventListener("geonet:location", event => { map.fire("click", {latlng:L.latLng(event.detail.lat,event.detail.lng)}); });
            map.on("click", (event) => {
        const selected = data.barangays.find((item) => item.name === barangaySelect.value);
        if (!selected) {
            status.textContent = "Choose a barangay before placing your pin.";
            return;
        }
        if (!selectedNapBox()) {
            status.textContent = "Choose a NAP box before placing your service pin.";
            return;
        }
        if (!isInsideCoverage(event.latlng.lat, event.latlng.lng)) {
            status.textContent = "Choose a location inside the network coverage area.";
            return;
        }
        if (!isWithinSelectedNapBox(event.latlng.lat, event.latlng.lng)) {
            status.textContent = `Choose a location inside the shaded ${napBoxRadiusLabel} area around the selected NAP box.`;
            return;
        }
        latitude.value = event.latlng.lat.toFixed(6);
        longitude.value = event.latlng.lng.toFixed(6);
        if (!pin) pin = L.marker(event.latlng).addTo(map);
        else pin.setLatLng(event.latlng);
        pin.bindPopup("Requested service location").openPopup();
        status.textContent = "Service location pinned inside network coverage and the NAP box service area. Click inside the shaded area to adjust it.";
    });

    document.getElementById("subscriptionRequestForm").addEventListener("submit", (event) => {
        if (
            !latitude.value ||
            !longitude.value ||
            !isInsideCoverage(Number(latitude.value), Number(longitude.value)) ||
            !isWithinSelectedNapBox(Number(latitude.value), Number(longitude.value))
        ) {
            event.preventDefault();
            status.textContent = "Choose a NAP box and pin your service location inside both its 300-meter area and network coverage before submitting.";
            document.getElementById("signupMap").scrollIntoView({ behavior: "smooth", block: "center" });
        }
    });
})();
</script>
<?php endif; ?>
<script src="assets/accessibility.js?v=<?php echo filemtime(__DIR__ . "/assets/accessibility.js"); ?>"></script>
</body>
</html>
