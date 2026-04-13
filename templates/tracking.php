<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-geo-alt me-2"></i>Live Tracking
            </h1>
            <p class="page-subtitle">Real-time vehicle location tracking</p>
        </div>
        <div>
            <button class="btn btn-outline-primary me-2" id="syncBtn" onclick="syncFromWheelsEye()" title="Fetch current locations from WheelsEye API">
                <i class="bi bi-cloud-download me-1"></i> Sync from WheelsEye
            </button>
            <button class="btn btn-primary" onclick="loadTracking()">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
            </button>
            <label class="ms-3">
                <input type="checkbox" id="autoRefresh" onchange="toggleAutoRefresh()" checked> Auto-refresh (15s)
            </label>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>

<div id="route-update-status" class="alert alert-secondary py-2 small mb-3" style="display: none;">
    <i class="bi bi-info-circle me-1"></i> <strong>Route update status:</strong> <span id="route-update-status-text">—</span>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <input
                type="text"
                id="trackingCoordInput"
                class="form-control form-control-sm"
                style="max-width: 320px;"
                placeholder="Search coordinates: 23.0225, 72.5714"
            >
            <button class="btn btn-sm btn-outline-primary" type="button" onclick="searchTrackingCoordinates()">
                <i class="bi bi-search me-1"></i>Go To Coordinates
            </button>
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="toggleTrackingMapFullscreen()">
                <i class="bi bi-arrows-fullscreen me-1"></i>Fullscreen
            </button>
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="rotateTrackingMap(-15)">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Rotate Left
            </button>
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="rotateTrackingMap(15)">
                <i class="bi bi-arrow-clockwise me-1"></i>Rotate Right
            </button>
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="resetTrackingMapRotation()">
                Reset Rotation
            </button>
            <span class="small text-muted">Enter latitude, longitude to jump and pin location.</span>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-9">
        <div class="card">
            <div class="card-body" style="height: 600px; padding: 0;">
                <div id="trackingMapContainer" style="width: 100%; height: 100%;">
                    <div id="map" style="width: 100%; height: 100%;"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-list-ul me-2"></i>Vehicles
            </div>
            <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                <div id="vehiclesList">
                    <div class="text-center text-muted">Loading vehicles...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-rotate@0.2.8/dist/leaflet-rotate-src.js"></script>
<style>
    #trackingMapContainer:fullscreen {
        background: #fff;
        padding: 0.5rem;
        z-index: 9999;
    }
    #trackingMapContainer:fullscreen #map {
        height: calc(100vh - 1rem) !important;
    }
    #trackingMapContainer.map-pseudo-fullscreen {
        position: fixed !important;
        inset: 0;
        width: 100vw !important;
        height: 100vh !important;
        background: #fff;
        padding: 0.5rem;
        z-index: 9999;
    }
    #trackingMapContainer.map-pseudo-fullscreen #map {
        height: calc(100vh - 1rem) !important;
    }
</style>
<script>
const mapboxToken = <?= json_encode($mapbox_token ?? '') ?>;
let map;
let markers = {};
let pathLayers = {};
let pathStartMarkers = {};
let geofenceLayers = [];
/** Per-vehicle path built in real time from each refresh (so path is always drawn as it grows) */
let sessionPathByVehicle = {};
/** Ignore stale map-matching results when a newer refresh has already run */
let pathUpdateGeneration = 0;
let autoRefreshInterval = null;
let trackingSearchMarker = null;
const PATH_COLORS = ['#e74c3c', '#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c', '#e67e22', '#34495e'];

const DEFAULT_ZOOM = 19;
const MIN_ZOOM = 18;
let mapboxStreetLayer, mapboxSatelliteLayer;

function initMap() {
    map = L.map('map', { zoomControl: true, rotate: true, touchRotate: true, bearing: 0, maxZoom: 22 }).setView([23.0225, 72.5714], DEFAULT_ZOOM);

    if (mapboxToken) {
        mapboxStreetLayer = L.tileLayer(
            'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/256/{z}/{x}/{y}?access_token=' + mapboxToken,
            { attribution: '© Mapbox', maxNativeZoom: 22, maxZoom: 22 }
        );
        mapboxSatelliteLayer = L.tileLayer(
            'https://api.mapbox.com/styles/v1/mapbox/satellite-v9/tiles/256/{z}/{x}/{y}?access_token=' + mapboxToken,
            { attribution: '© Mapbox', maxNativeZoom: 22, maxZoom: 22 }
        );
        const mapboxSatelliteLabelledLayer = L.tileLayer(
            'https://api.mapbox.com/styles/v1/mapbox/satellite-streets-v12/tiles/256/{z}/{x}/{y}?access_token=' + mapboxToken,
            { attribution: '© Mapbox', maxNativeZoom: 22, maxZoom: 22 }
        );
        mapboxSatelliteLabelledLayer.addTo(map);
        L.control.layers(
            {
                'Mapbox Satellite + Labels': mapboxSatelliteLabelledLayer,
                'Mapbox Satellite': mapboxSatelliteLayer,
                'Mapbox Street': mapboxStreetLayer
            },
            null,
            { position: 'topright' }
        ).addTo(map);
    } else {
        const osmStandard = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxNativeZoom: 19,
            maxZoom: 22
        });
        const osmHot = L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors, HOT',
            maxNativeZoom: 19,
            maxZoom: 22
        });
        const cartoVoyager = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
            attribution: '© OpenStreetMap contributors, © CARTO',
            maxNativeZoom: 20,
            maxZoom: 22
        });
        const esriStreets = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
            attribution: 'Tiles © Esri',
            maxNativeZoom: 19,
            maxZoom: 22
        });
        const esriSatellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: 'Tiles © Esri',
            maxNativeZoom: 19,
            maxZoom: 22
        });
        const esriLabels = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
            attribution: 'Labels © Esri',
            maxNativeZoom: 19,
            maxZoom: 22
        });
        const esriTopo = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}', {
            attribution: 'Tiles © Esri',
            maxNativeZoom: 19,
            maxZoom: 22
        });
        const esriHybrid = L.layerGroup([esriSatellite, esriLabels]);

        osmStandard.addTo(map);
        L.control.layers(
            {
                'OpenStreetMap': osmStandard,
                'OSM Humanitarian': osmHot,
                'Carto Voyager': cartoVoyager,
                'Esri Streets': esriStreets,
                'Esri Topographic': esriTopo,
                'Esri Satellite': esriSatellite,
                'Esri Hybrid (Satellite + Labels)': esriHybrid
            },
            {
                'Place Labels': esriLabels
            },
            { position: 'topright' }
        ).addTo(map);
    }
}

function loadTracking() {
    Promise.all([
        fetch('/api/tracking/live?path_hours=24&path_limit=500', { credentials: 'same-origin' }).then(r => r.json()),
        fetch('/api/geofences', { credentials: 'same-origin' }).then(r => r.json())
    ]).then(([trackingRes, geofencesRes]) => {
        if (trackingRes.success) {
            const geofences = (geofencesRes.success && geofencesRes.data) ? geofencesRes.data : [];
            updateMap(trackingRes.data, geofences);
            updateVehiclesList(trackingRes.data);
        } else {
            showError(trackingRes.error || 'Failed to load tracking data');
        }
    }).catch(e => {
        showError('Error loading tracking: ' + e.message);
    });
    loadSyncStatus();
}

function loadSyncStatus() {
    fetch('/api/tracking/sync-status', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
            if (res.success && res.data) updateRouteUpdateStatus(res.data);
        })
        .catch(() => {});
}

function updateRouteUpdateStatus(data) {
    const box = document.getElementById('route-update-status');
    const text = document.getElementById('route-update-status-text');
    if (!box || !text) return;
    const lastRun = data.last_run ? formatTimeAgo(data.last_run) : '';
    let msg;
    if (data.success) {
        msg = lastRun
            ? (data.synced > 0 ? lastRun + ' — ' + data.synced + ' vehicle(s) updated' : lastRun + ' — no new locations')
            : (data.synced > 0 ? data.synced + ' vehicle(s) updated' : 'No new locations');
    } else {
        msg = (lastRun ? lastRun + ' — ' : '') + (data.message || 'Sync failed');
    }
    text.textContent = msg;
    box.style.display = 'block';
    box.className = 'alert py-2 small mb-3 ' + (data.success ? 'alert-secondary' : 'alert-danger');
}

function formatTimeAgo(iso) {
    try {
        const d = new Date(iso);
        const sec = Math.floor((Date.now() - d.getTime()) / 1000);
        if (sec < 60) return 'Just now';
        if (sec < 3600) return Math.floor(sec / 60) + ' min ago';
        if (sec < 86400) return Math.floor(sec / 3600) + ' hr ago';
        return d.toLocaleString();
    } catch (e) { return iso || '—'; }
}

// Mapbox Map Matching: snap GPS path to roads (max 100 points per request). Points: array of [lat, lng] or {lat, lng}.
function getMapMatchedPath(pathPoints, token) {
    if (!pathPoints || pathPoints.length < 2 || !token) return Promise.resolve(null);
    let points = pathPoints.map(p => Array.isArray(p) ? { lat: p[0], lng: p[1] } : p);
    if (points.length > 100) {
        const step = Math.ceil(points.length / 100);
        points = points.filter((_, i) => i % step === 0 || i === points.length - 1);
    }
    const coords = points.map(p => p.lng + ',' + p.lat).join(';');
    const url = 'https://api.mapbox.com/matching/v5/mapbox/driving/' + encodeURIComponent(coords) + '.json?access_token=' + encodeURIComponent(token) + '&geometries=geojson';
    return fetch(url)
        .then(r => r.json())
        .then(data => {
            if (data.matchings && data.matchings[0] && data.matchings[0].geometry && data.matchings[0].geometry.coordinates) {
                return data.matchings[0].geometry.coordinates.map(c => [c[1], c[0]]);
            }
            return null;
        })
        .catch(() => null);
}

function syncFromWheelsEye() {
    const btn = document.getElementById('syncBtn');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Syncing...';
    fetch('/api/tracking/sync', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : '' }
    })
        .then(r => r.json())
        .then(data => {
            updateRouteUpdateStatus({ ...data, last_run: new Date().toISOString().slice(0, 19).replace('T', ' ') });
            if (data.success) {
                showError(''); // clear any previous error
                const msg = data.synced > 0
                    ? 'Synced ' + data.synced + ' vehicle(s). Refreshing map.'
                    : (data.message || 'Sync completed. No new locations matched.');
                if (data.synced > 0) loadTracking();
                alert(msg + (data.errors && data.errors.length ? '\n\nNotes: ' + data.errors.join('; ') : ''));
            } else {
                showError(data.message || data.error || 'Sync failed');
            }
        })
        .catch(e => {
            showError('Sync failed: ' + e.message);
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        });
}

// Quiet sync (no alert, no button change) – used by auto-refresh every 15s to pull fresh data from WheelsEye
function syncFromWheelsEyeQuiet() {
    return fetch('/api/tracking/sync', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : '' }
    })
        .then(r => r.json())
        .then(data => {
            updateRouteUpdateStatus({ ...data, last_run: new Date().toISOString().slice(0, 19).replace('T', ' ') });
            if (!data.success) showError(data.message || data.error || 'Sync failed');
            return data;
        })
        .catch(e => {
            showError('Sync failed: ' + e.message);
            return { success: false };
        });
}

function parseCoordinates(input) {
    if (!input) return null;
    const parts = String(input).trim().split(/[,\s]+/).filter(Boolean);
    if (parts.length < 2) return null;
    const lat = Number(parts[0]);
    const lng = Number(parts[1]);
    if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
        return null;
    }
    return { lat, lng };
}

function searchTrackingCoordinates() {
    const input = document.getElementById('trackingCoordInput');
    const parsed = parseCoordinates(input.value);
    if (!parsed) {
        showError('Invalid coordinates. Use format: latitude, longitude');
        return;
    }

    if (trackingSearchMarker && map.hasLayer(trackingSearchMarker)) {
        map.removeLayer(trackingSearchMarker);
    }
    trackingSearchMarker = L.marker([parsed.lat, parsed.lng]).addTo(map);
    trackingSearchMarker.bindPopup(
        `<strong>Searched Coordinates</strong><br>Lat: ${parsed.lat.toFixed(8)}<br>Lng: ${parsed.lng.toFixed(8)}`
    ).openPopup();
    map.setView([parsed.lat, parsed.lng], Math.max(map.getZoom(), 16));
}

function toggleTrackingMapFullscreen() {
    const container = document.getElementById('trackingMapContainer');
    if (isTrackingMapFullscreen(container)) {
        if (!exitAnyFullscreen()) {
            container.classList.remove('map-pseudo-fullscreen');
        }
        setTimeout(() => map?.invalidateSize(), 120);
        return;
    }

    if (!requestElementFullscreen(container)) {
        container.classList.add('map-pseudo-fullscreen');
    }
    setTimeout(() => map?.invalidateSize(), 120);
}

function isTrackingMapFullscreen(container) {
    return document.fullscreenElement === container
        || document.webkitFullscreenElement === container
        || container.classList.contains('map-pseudo-fullscreen');
}

function requestElementFullscreen(element) {
    if (element.requestFullscreen) {
        element.requestFullscreen();
        return true;
    }
    if (element.webkitRequestFullscreen) {
        element.webkitRequestFullscreen();
        return true;
    }
    return false;
}

function exitAnyFullscreen() {
    if (document.fullscreenElement && document.exitFullscreen) {
        document.exitFullscreen();
        return true;
    }
    if (document.webkitFullscreenElement && document.webkitExitFullscreen) {
        document.webkitExitFullscreen();
        return true;
    }
    return false;
}

function trackingMapSupportsRotation() {
    return map && typeof map.setBearing === 'function' && typeof map.getBearing === 'function';
}

function rotateTrackingMap(deltaDegrees) {
    if (!trackingMapSupportsRotation()) {
        showError('Map rotation is not supported in this browser.');
        return;
    }
    const current = Number(map.getBearing()) || 0;
    const next = (current + deltaDegrees + 360) % 360;
    map.setBearing(next);
}

function resetTrackingMapRotation() {
    if (!trackingMapSupportsRotation()) {
        showError('Map rotation is not supported in this browser.');
        return;
    }
    map.setBearing(0);
}

async function updateMap(vehicles, geofences) {
    pathUpdateGeneration++;
    const thisUpdateGen = pathUpdateGeneration;
    geofences = geofences || [];
    // Clear existing geofence circles
    geofenceLayers.forEach(layer => { if (map.hasLayer(layer)) map.removeLayer(layer); });
    geofenceLayers = [];
    // Clear existing path polylines and path start markers
    Object.values(pathLayers).forEach(layer => { if (map.hasLayer(layer)) map.removeLayer(layer); });
    pathLayers = {};
    Object.values(pathStartMarkers).forEach(m => { if (map.hasLayer(m)) map.removeLayer(m); });
    pathStartMarkers = {};
    // Clear existing markers
    Object.values(markers).forEach(marker => map.removeLayer(marker));
    markers = {};
    
    const vehicleViewPoints = [];
    
    // Draw geofences (active only, from API) – do NOT use for map view so we stay focused on vehicle
    geofences.forEach(g => {
        const lat = Number(g.latitude);
        const lng = Number(g.longitude);
        const radius = Number(g.radius_meters) || 100;
        const shapeType = g.shape_type || 'circle';
        if (isNaN(lat) || isNaN(lng)) return;
        const isPit = g.geofence_type === 'pit';
        const color = isPit ? '#2563eb' : '#059669';
        let geofenceLayer = null;

        if (shapeType === 'polygon' && Array.isArray(g.polygon_points) && g.polygon_points.length >= 3) {
            const latLngs = g.polygon_points.map(p => [Number(p.lat), Number(p.lng)]);
            geofenceLayer = L.polygon(latLngs, {
                color: color,
                fillColor: color,
                fillOpacity: 0.12,
                weight: 2
            }).addTo(map);
            geofenceLayer.bindPopup('<strong>' + escapeHtml(g.name || 'Geofence') + '</strong><br>' +
                (g.geofence_type ? escapeHtml(g.geofence_type) : '') + (g.material_type ? ' – ' + escapeHtml(g.material_type) : '') + '<br>Boundary: ' + g.polygon_points.length + ' points');
        } else {
            geofenceLayer = L.circle([lat, lng], {
                radius: radius,
                color: color,
                fillColor: color,
                fillOpacity: 0.12,
                weight: 2,
            }).addTo(map);
            geofenceLayer.bindPopup('<strong>' + escapeHtml(g.name || 'Geofence') + '</strong><br>' +
                (g.geofence_type ? escapeHtml(g.geofence_type) : '') + (g.material_type ? ' – ' + escapeHtml(g.material_type) : '') + '<br>Radius: ' + radius + ' m');
        }

        geofenceLayers.push(geofenceLayer);
    });
    
    // Build path per vehicle: merge API path_points with session buffer and current position (so path draws in real time)
    vehicles.forEach(vehicle => {
        const pathPoints = vehicle.path_points || [];
        const latest = vehicle.latest_tracking;
        const current = (latest && latest.latitude != null && latest.longitude != null)
            ? [Number(latest.latitude), Number(latest.longitude)] : null;
        let points = sessionPathByVehicle[vehicle.id] || [];
        if (pathPoints.length >= 2) {
            const apiPoints = pathPoints.map(p => [Number(p.lat), Number(p.lng)]);
            if (apiPoints.length > points.length) points = apiPoints;
        }
        if (current) {
            const last = points[points.length - 1];
            if (!last || last[0] !== current[0] || last[1] !== current[1]) {
                points.push(current);
            }
        }
        if (points.length > 2000) points = points.slice(-1500);
        sessionPathByVehicle[vehicle.id] = points;
    });
    // Draw paths: raw line first (visible immediately), then snap to roads when Mapbox token is set
    vehicles.forEach((vehicle, idx) => {
        const points = sessionPathByVehicle[vehicle.id] || [];
        if (points.length < 2) return;
        const color = PATH_COLORS[idx % PATH_COLORS.length];
        const polyline = L.polyline(points, {
            color: color,
            weight: 5,
            opacity: 0.9,
        }).addTo(map);
        polyline.bindPopup('<strong>' + escapeHtml(vehicle.vehicle_number) + '</strong> – route (live)');
        pathLayers[vehicle.id] = polyline;
        const startLatLng = points[0];
        const startIcon = L.divIcon({
            className: 'path-start-marker',
            html: '<div style="background:#22c55e; color:#fff; width:24px; height:24px; border-radius:50%; border:2px solid #fff; box-shadow:0 2px 6px rgba(0,0,0,0.4); display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:bold;">S</div>',
            iconSize: [24, 24],
            iconAnchor: [12, 12]
        });
        const startMarker = L.marker(startLatLng, { icon: startIcon }).addTo(map);
        startMarker.bindPopup('<strong>' + escapeHtml(vehicle.vehicle_number) + '</strong> – Start');
        pathStartMarkers[vehicle.id] = startMarker;
        // Snap path to roads (Mapbox Map Matching) only when the result is good (don't replace with a bad/short match)
        if (mapboxToken) {
            const rawPointCount = points.length;
            getMapMatchedPath(points, mapboxToken).then(matched => {
                if (thisUpdateGen !== pathUpdateGeneration) return;
                if (!pathLayers[vehicle.id] || !map.hasLayer(pathLayers[vehicle.id])) return;
                if (!matched || matched.length < 2) return;
                const minPoints = Math.max(2, Math.floor(rawPointCount * 0.15));
                if (matched.length < minPoints) return;
                const rawStart = points[0], rawEnd = points[points.length - 1];
                const matchedStart = matched[0], matchedEnd = matched[matched.length - 1];
                const distStart = Math.abs(matchedStart[0] - rawStart[0]) + Math.abs(matchedStart[1] - rawStart[1]);
                const distEnd = Math.abs(matchedEnd[0] - rawEnd[0]) + Math.abs(matchedEnd[1] - rawEnd[1]);
                if (distStart > 0.05 || distEnd > 0.05) return;
                pathLayers[vehicle.id].setLatLngs(matched);
            });
        }
    });
    
    vehicles.forEach((vehicle, idx) => {
        if (vehicle.latest_tracking && vehicle.latest_tracking.latitude && vehicle.latest_tracking.longitude) {
            const lat = vehicle.latest_tracking.latitude;
            const lng = vehicle.latest_tracking.longitude;
            vehicleViewPoints.push([lat, lng]);
            const color = PATH_COLORS[idx % PATH_COLORS.length];
            
            const icon = L.divIcon({
                className: 'vehicle-marker',
                html: `<div style="background: ${color}; width: 22px; height: 22px; border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.4);"></div>`,
                iconSize: [22, 22]
            });
            
            const marker = L.marker([lat, lng], { icon }).addTo(map);
            
            const popup = `
                <strong>${escapeHtml(vehicle.vehicle_number)}</strong><br>
                Type: ${escapeHtml(vehicle.vehicle_type)}<br>
                Speed: ${vehicle.latest_tracking.speed ? vehicle.latest_tracking.speed + ' km/h' : 'N/A'}<br>
                Status: ${escapeHtml(vehicle.status)}<br>
                Last Update: ${new Date(vehicle.latest_tracking.timestamp).toLocaleString()}
            `;
            marker.bindPopup(popup);
            
            markers[vehicle.id] = marker;
        }
    });
    
    // View follows vehicle(s) only – stay zoomed in, pan as vehicle moves (no path/geofence in view calc)
    if (vehicleViewPoints.length > 0) {
        const bounds = L.latLngBounds(vehicleViewPoints);
        const center = bounds.getCenter();
        if (vehicleViewPoints.length === 1 || bounds.getNorthEast().distanceTo(bounds.getSouthWest()) < 0.005) {
            map.setView(center, DEFAULT_ZOOM);
        } else {
            map.fitBounds(bounds.pad(0.2), { maxZoom: DEFAULT_ZOOM });
            if (map.getZoom() < MIN_ZOOM) map.setZoom(MIN_ZOOM);
        }
    }
}

function updateVehiclesList(vehicles) {
    const container = document.getElementById('vehiclesList');
    
    if (vehicles.length === 0) {
        container.innerHTML = '<div class="text-center text-muted">No vehicles found. Add vehicles in the Vehicles page.</div>';
        return;
    }
    
    const withLocation = vehicles.filter(v => v.latest_tracking && v.latest_tracking.latitude);
    if (withLocation.length === 0) {
        container.innerHTML = `
            <div class="alert alert-info small mb-3">
                <strong>No location data yet.</strong><br>
                Click <strong>Sync from WheelsEye</strong> above to fetch current GPS positions.<br>
                <small>Ensure the vehicle is <strong>Active</strong> and its <strong>Vehicle number</strong> in OMS matches WheelsEye (e.g. RJ07GD5241).</small>
            </div>
            ${vehicles.map(v => vehicleListItem(v)).join('')}
        `;
        return;
    }
    
    container.innerHTML = vehicles.map(v => vehicleListItem(v)).join('');
}

function vehicleListItem(v) {
    const hasLocation = v.latest_tracking && v.latest_tracking.latitude;
    return `
        <div class="mb-3 p-2 border rounded" style="cursor: pointer;" onclick="focusVehicle(${v.id})">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong>${escapeHtml(v.vehicle_number)}</strong>
                    <span class="badge bg-${getStatusBadgeColor(v.status)} ms-2">${escapeHtml(v.status)}</span>
                </div>
            </div>
            <div class="text-muted small mt-1">
                ${hasLocation ?
                    `<i class="bi bi-geo-alt"></i> ${v.latest_tracking.speed ? v.latest_tracking.speed + ' km/h' : 'Stationary'}<br>
                     <small>${new Date(v.latest_tracking.timestamp).toLocaleString()}</small>` :
                    '<span class="text-muted">No location data</span>'
                }
            </div>
        </div>
    `;
}

function focusVehicle(id) {
    if (markers[id]) {
        map.panTo(markers[id].getLatLng());
        markers[id].openPopup();
    }
}

function getStatusColor(status) {
    switch(status) {
        case 'active': return '#28a745';
        case 'maintenance': return '#ffc107';
        default: return '#6c757d';
    }
}

function getStatusBadgeColor(status) {
    switch(status) {
        case 'active': return 'success';
        case 'maintenance': return 'warning';
        default: return 'secondary';
    }
}

function doAutoRefreshCycle() {
    syncFromWheelsEyeQuiet().then(() => loadTracking());
}

function toggleAutoRefresh() {
    const enabled = document.getElementById('autoRefresh').checked;
    
    if (enabled) {
        doAutoRefreshCycle(); // run once immediately
        autoRefreshInterval = setInterval(doAutoRefreshCycle, 15000); // then every 15s: sync from WheelsEye then refresh map
    } else {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showError(msg) {
    const container = document.getElementById('error-container');
    if (!container) return;
    if (!msg || String(msg).trim() === '') {
        container.style.display = 'none';
        container.textContent = '';
        return;
    }
    container.textContent = msg;
    container.style.display = 'block';
    setTimeout(() => { container.style.display = 'none'; container.textContent = ''; }, 5000);
}

// Initialize map and load data
document.addEventListener('DOMContentLoaded', () => {
    initMap();
    loadTracking();
    loadSyncStatus();
    toggleAutoRefresh();
    const coordInput = document.getElementById('trackingCoordInput');
    coordInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchTrackingCoordinates();
        }
    });
    const onFullscreenChanged = () => {
        if (map) {
            setTimeout(() => map.invalidateSize(), 120);
        }
        const container = document.getElementById('trackingMapContainer');
        const nativeFullscreenActive = document.fullscreenElement === container || document.webkitFullscreenElement === container;
        if (!nativeFullscreenActive) {
            container.classList.remove('map-pseudo-fullscreen');
        }
    };
    document.addEventListener('fullscreenchange', onFullscreenChanged);
    document.addEventListener('webkitfullscreenchange', onFullscreenChanged);
});
</script>
