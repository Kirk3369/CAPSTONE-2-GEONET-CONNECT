(function () {
    const legendItems = {
        online: '<span><i class="map-legend-marker online"></i>Online subscriber</span>',
        offline: '<span><i class="map-legend-marker offline"></i>Offline subscriber</span>',
        intermittent: '<span><i class="map-legend-marker intermittent"></i>Intermittent subscriber</span>',
        ownOnline: '<span><i class="map-legend-marker online"></i>Your location · online</span>',
        ownOffline: '<span><i class="map-legend-marker offline"></i>Your location · offline</span>',
        ownIntermittent: '<span><i class="map-legend-marker intermittent"></i>Your location · intermittent</span>',
        nap: '<span><i class="map-legend-marker nap"></i>NAP box</span>',
        barangay: '<span><i class="map-legend-marker barangay"></i>Barangay</span>',
        assignedOutage: '<span><i class="map-legend-marker offline"></i>Assigned outage</span>',
        outageActive: '<span><i class="map-legend-area outage-active"></i>Active outage</span>',
        outageResolved: '<span><i class="map-legend-area outage-resolved"></i>Resolved outage</span>',
        request: '<span><i class="map-legend-marker request"></i>Subscription request</span>',
        coverage: '<span><i class="map-legend-area coverage"></i>Coverage area</span>'
    };

    window.createGeoNetNapIcon = function () {
        return L.divIcon({
            className: "nap-map-icon",
            html: `
                <svg viewBox="0 0 36 36" role="img" aria-label="NAP box">
                    <path class="nap-cable" d="M18 1v5"/>
                    <path class="nap-case-shadow" d="M8 10h20v19H8z"/>
                    <path class="nap-lid" d="m6 10 12-5 12 5z"/>
                    <rect class="nap-case" x="8" y="10" width="20" height="19" rx="3"/>
                    <path class="nap-case-seam" d="M8 15h20"/>
                    <circle class="nap-screw" cx="11" cy="12.5" r="1"/>
                    <circle class="nap-screw" cx="25" cy="12.5" r="1"/>
                    <circle class="nap-status" cx="18" cy="22" r="2"/>
                    <path class="nap-case-seam" d="M13 26h10"/>
                </svg>`,
            iconSize: [28, 28],
            iconAnchor: [9, 15],
            popupAnchor: [0, -14]
        });
    };

    window.addGeoNetMapLegend = function (map, visibleItems) {
        const legend = L.control({ position: "topright" });
        legend.onAdd = () => {
            const container = L.DomUtil.create("div", "map-legend leaflet-control");
            container.setAttribute("aria-label", "Map legend");
            container.innerHTML = `
                <div class="map-legend-header">
                    <strong class="map-legend-title">Map legend</strong>
                    <button class="map-legend-toggle" type="button" aria-expanded="true" aria-controls="mapLegendItems" aria-label="Minimize map legend">Minimize</button>
                </div>
                <div class="map-legend-items" id="mapLegendItems">
                    ${visibleItems.map((item) => legendItems[item]).join("")}
                </div>`;
            const toggle = container.querySelector(".map-legend-toggle");
            const items = container.querySelector(".map-legend-items");
            toggle.addEventListener("click", () => {
                const expanded = toggle.getAttribute("aria-expanded") === "true";
                toggle.setAttribute("aria-expanded", String(!expanded));
                toggle.setAttribute("aria-label", expanded ? "Show map legend" : "Minimize map legend");
                toggle.textContent = expanded ? "Show" : "Minimize";
                items.hidden = expanded;
            });
            L.DomEvent.disableClickPropagation(container);
            L.DomEvent.disableScrollPropagation(container);
            return container;
        };
        legend.addTo(map);
    };
})();
