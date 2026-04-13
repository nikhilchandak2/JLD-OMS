<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-geo-fill me-2"></i>Geofences
            </h1>
            <p class="page-subtitle">Manage pit and stockpile geofences</p>
        </div>
        <button class="btn btn-primary" onclick="showAddGeofenceModal()">
            <i class="bi bi-plus-circle me-1"></i> Add Geofence
        </button>
    </div>
</div>

<div class="alert alert-light border mb-3 py-2 small">
    <strong>How geofences work:</strong> Geofences are <strong>portal-only</strong> and do not sync to WheelsEye. As soon as a vehicle's position is received (via WheelsEye webhook or Sync from WheelsEye), the portal checks if it entered or left a geofence and records entry/exit events. No need to configure anything on the device.
</div>
<div id="error-container" class="error-message"></div>
<div id="success-container" class="success-message"></div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status"></div>
    <p>Loading geofences...</p>
</div>

<!-- Geofences Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="geofencesTable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Shape</th>
                        <th>Material Type</th>
                        <th>Location</th>
                        <th>Boundary</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data will be populated by JavaScript -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Geofence Modal -->
<div class="modal fade" id="geofenceModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="geofenceModalTitle">Add Geofence</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="geofenceForm">
                    <input type="hidden" id="geofenceId">
                    <div class="row g-3">
                        <div class="col-lg-4">
                            <div class="mb-3">
                                <label class="form-label">Name *</label>
                                <input type="text" class="form-control" id="geofenceName" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Type *</label>
                                <select class="form-select" id="geofenceType" required>
                                    <option value="pit">Pit</option>
                                    <option value="stockpile">Stockpile</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="mb-3" id="materialTypeContainer" style="display: none;">
                                <label class="form-label">Material Type</label>
                                <select class="form-select" id="materialType">
                                    <option value="">Select Material</option>
                                    <option value="ball_clay_1st_grade">Ball Clay 1st Grade</option>
                                    <option value="ball_clay_2nd_grade">Ball Clay 2nd Grade</option>
                                    <option value="ball_clay_3rd_grade">Ball Clay 3rd Grade</option>
                                    <option value="overburden">Overburden</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Boundary Shape *</label>
                                <select class="form-select" id="shapeType">
                                    <option value="circle">Circle (Center + Radius)</option>
                                    <option value="polygon">Polygon (Irregular Boundary)</option>
                                </select>
                            </div>
                            <div id="circleFields">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Latitude *</label>
                                        <input type="number" step="0.00000001" class="form-control" id="latitude">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Longitude *</label>
                                        <input type="number" step="0.00000001" class="form-control" id="longitude">
                                    </div>
                                </div>
                                <div class="mb-3 mt-2">
                                    <label class="form-label">Radius (meters) *</label>
                                    <input type="number" step="0.01" class="form-control" id="radiusMeters">
                                </div>
                            </div>
                            <div id="polygonFields" style="display: none;">
                                <label class="form-label">Polygon Points</label>
                                <textarea class="form-control" id="polygonPointsPreview" rows="4" readonly placeholder="Draw polygon on map to capture boundary points"></textarea>
                                <small class="text-muted" id="polygonPointsCount">0 points selected</small>
                            </div>
                            <div class="mb-3 mt-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="isActive">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-8">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="form-label mb-0">Draw Boundary on Map</label>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="clearDrawnShape()">
                                        <i class="bi bi-eraser me-1"></i>Clear Shape
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="toggleGeofenceMapFullscreen()">
                                        <i class="bi bi-arrows-fullscreen me-1"></i>Fullscreen
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="rotateGeofenceMap(-15)">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Left
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="rotateGeofenceMap(15)">
                                        <i class="bi bi-arrow-clockwise me-1"></i>Right
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="resetGeofenceMapRotation()">
                                        Reset
                                    </button>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <input
                                    type="text"
                                    id="geofenceCoordInput"
                                    class="form-control form-control-sm"
                                    style="max-width: 320px;"
                                    placeholder="Search coordinates: 23.0225, 72.5714"
                                >
                                <button class="btn btn-sm btn-outline-primary" type="button" onclick="searchGeofenceCoordinates()">
                                    <i class="bi bi-search me-1"></i>Go To Coordinates
                                </button>
                                <span class="small text-muted align-self-center">Enter latitude, longitude to jump and pin location.</span>
                            </div>
                            <div id="geofence-map-help" class="small text-muted mb-2">
                                Use draw tools on the map: circle for simple area, polygon for irregular pit boundaries.
                            </div>
                            <div id="geofenceMapContainer">
                                <div id="geofenceMap"></div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveGeofence()">Save</button>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-rotate@0.2.8/dist/leaflet-rotate-src.js"></script>
<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
<style>
    #geofenceMap {
        width: 100%;
        height: 430px;
        border: 1px solid var(--jld-border);
        border-radius: 0.5rem;
    }
    #geofenceMapContainer:fullscreen {
        background: #fff;
        padding: 0.5rem;
        z-index: 2000;
    }
    #geofenceMapContainer:fullscreen #geofenceMap {
        height: calc(100vh - 1rem) !important;
    }
    #geofenceMapContainer.map-pseudo-fullscreen {
        position: fixed !important;
        inset: 0;
        width: 100vw !important;
        height: 100vh !important;
        background: #fff;
        padding: 0.5rem;
        z-index: 2000;
    }
    #geofenceMapContainer.map-pseudo-fullscreen #geofenceMap {
        height: calc(100vh - 1rem) !important;
    }
</style>

<script>
let geofences = [];
let geofenceMap;
let drawnItems;
let drawControl;
let currentShapeLayer = null;
let geofenceSearchMarker = null;

const defaultMapCenter = [23.0225, 72.5714];
const defaultMapZoom = 14;

function initGeofenceMap() {
    if (geofenceMap) {
        return;
    }

    geofenceMap = L.map('geofenceMap', { rotate: true, touchRotate: true, bearing: 0, maxZoom: 22 }).setView(defaultMapCenter, defaultMapZoom);
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

    osmStandard.addTo(geofenceMap);
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
    ).addTo(geofenceMap);

    drawnItems = new L.FeatureGroup();
    geofenceMap.addLayer(drawnItems);

    drawControl = new L.Control.Draw({
        position: 'topright',
        draw: {
            polyline: false,
            rectangle: false,
            marker: false,
            circlemarker: false,
            polygon: {
                allowIntersection: false,
                showArea: true
            },
            circle: true
        },
        edit: {
            featureGroup: drawnItems,
            remove: false
        }
    });
    geofenceMap.addControl(drawControl);

    geofenceMap.on(L.Draw.Event.CREATED, function (event) {
        applyDrawnLayer(event.layer);
    });

    geofenceMap.on(L.Draw.Event.EDITED, function (event) {
        event.layers.eachLayer(layer => {
            currentShapeLayer = layer;
            syncFormFromLayer(layer);
        });
    });
}

document.getElementById('geofenceType').addEventListener('change', function() {
    document.getElementById('materialTypeContainer').style.display = 
        this.value === 'stockpile' ? 'block' : 'none';
});

document.getElementById('shapeType').addEventListener('change', function() {
    updateShapeFieldVisibility(this.value);
});

['latitude', 'longitude', 'radiusMeters'].forEach(id => {
    document.getElementById(id).addEventListener('input', syncCircleLayerFromInputs);
});

function loadGeofences() {
    document.getElementById('loading').style.display = 'flex';
    
    fetch('/api/geofences')
        .then(r => r.json())
        .then(data => {
            document.getElementById('loading').style.display = 'none';
            if (data.success) {
                geofences = data.data;
                renderGeofences();
            } else {
                showError(data.error || 'Failed to load geofences');
            }
        })
        .catch(e => {
            document.getElementById('loading').style.display = 'none';
            showError('Error: ' + e.message);
        });
}

function renderGeofences() {
    const tbody = document.querySelector('#geofencesTable tbody');
    tbody.innerHTML = '';
    
    if (geofences.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center">No geofences found</td></tr>';
        return;
    }
    
    geofences.forEach(g => {
        const shapeType = g.shape_type || 'circle';
        const locationText = g.latitude != null && g.longitude != null
            ? (Number(g.latitude).toFixed(6) + ', ' + Number(g.longitude).toFixed(6))
            : '-';
        const boundaryText = shapeType === 'polygon'
            ? ((g.polygon_points && g.polygon_points.length ? g.polygon_points.length : 0) + ' points')
            : ((g.radius_meters != null ? g.radius_meters : 0) + 'm radius');

        const row = document.createElement('tr');
        row.innerHTML = `
            <td><strong>${escapeHtml(g.name)}</strong></td>
            <td><span class="badge bg-${g.geofence_type === 'pit' ? 'primary' : 'info'}">${escapeHtml(g.geofence_type)}</span></td>
            <td><span class="badge bg-${shapeType === 'polygon' ? 'warning text-dark' : 'secondary'}">${escapeHtml(shapeType)}</span></td>
            <td>${escapeHtml(g.material_type || '-')}</td>
            <td>${locationText}</td>
            <td>${escapeHtml(boundaryText)}</td>
            <td><span class="badge bg-${g.is_active ? 'success' : 'secondary'}">${g.is_active ? 'Active' : 'Inactive'}</span></td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="editGeofence(${g.id})">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteGeofence(${g.id})">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function showAddGeofenceModal() {
    document.getElementById('geofenceModalTitle').textContent = 'Add Geofence';
    document.getElementById('geofenceForm').reset();
    document.getElementById('geofenceId').value = '';
    document.getElementById('materialTypeContainer').style.display = 'none';
    document.getElementById('shapeType').value = 'circle';
    updateShapeFieldVisibility('circle');
    clearDrawnShape();
    showModalAndPrepareMap();
}

function editGeofence(id) {
    const geofence = geofences.find(g => g.id === id);
    if (!geofence) return;
    
    document.getElementById('geofenceModalTitle').textContent = 'Edit Geofence';
    document.getElementById('geofenceId').value = geofence.id;
    document.getElementById('geofenceName').value = geofence.name;
    document.getElementById('geofenceType').value = geofence.geofence_type;
    document.getElementById('materialType').value = geofence.material_type || '';
    document.getElementById('shapeType').value = geofence.shape_type || 'circle';
    document.getElementById('latitude').value = geofence.latitude ?? '';
    document.getElementById('longitude').value = geofence.longitude ?? '';
    document.getElementById('radiusMeters').value = geofence.radius_meters ?? '';
    document.getElementById('isActive').value = geofence.is_active ? '1' : '0';
    document.getElementById('materialTypeContainer').style.display = 
        geofence.geofence_type === 'stockpile' ? 'block' : 'none';
    updateShapeFieldVisibility(geofence.shape_type || 'circle');
    
    showModalAndPrepareMap(() => {
        loadShapeOnMap(geofence);
    });
}

function saveGeofence() {
    const shapeType = document.getElementById('shapeType').value;
    const id = document.getElementById('geofenceId').value;
    const data = {
        name: document.getElementById('geofenceName').value,
        geofence_type: document.getElementById('geofenceType').value,
        material_type: document.getElementById('geofenceType').value === 'stockpile' ? 
            document.getElementById('materialType').value : null,
        shape_type: shapeType,
        is_active: document.getElementById('isActive').value === '1' ? 1 : 0
    };

    if (shapeType === 'polygon') {
        const points = getPolygonPointsFromLayer();
        if (points.length < 3) {
            showError('Please draw a polygon with at least 3 points for irregular boundaries.');
            return;
        }
        data.polygon_points = points;
    } else {
        const latitude = parseFloat(document.getElementById('latitude').value);
        const longitude = parseFloat(document.getElementById('longitude').value);
        const radius = parseFloat(document.getElementById('radiusMeters').value);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || !Number.isFinite(radius) || radius <= 0) {
            showError('Please provide valid latitude, longitude and radius for circle geofence.');
            return;
        }
        data.latitude = latitude;
        data.longitude = longitude;
        data.radius_meters = radius;
        data.polygon_points = null;
    }
    
    const url = id ? `/api/geofences/${id}` : '/api/geofences';
    const method = id ? 'PUT' : 'POST';
    
    fetch(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?= $csrf_token ?>'
        },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('geofenceModal')).hide();
            showSuccess(id ? 'Geofence updated' : 'Geofence created');
            loadGeofences();
        } else {
            showError(data.error || 'Failed to save geofence');
        }
    })
    .catch(e => showError('Error: ' + e.message));
}

function deleteGeofence(id) {
    if (!confirm('Are you sure you want to delete this geofence?')) return;
    
    fetch(`/api/geofences/${id}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-Token': '<?= $csrf_token ?>'
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showSuccess('Geofence deleted');
            loadGeofences();
        } else {
            showError(data.error || 'Failed to delete geofence');
        }
    })
    .catch(e => showError('Error: ' + e.message));
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showModalAndPrepareMap(onReady) {
    const modalEl = document.getElementById('geofenceModal');
    const modal = new bootstrap.Modal(modalEl);

    modalEl.addEventListener('shown.bs.modal', function handleShown() {
        modalEl.removeEventListener('shown.bs.modal', handleShown);
        initGeofenceMap();
        setTimeout(() => {
            geofenceMap.invalidateSize();
            if (onReady) onReady();
        }, 80);
    });

    modal.show();
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

function searchGeofenceCoordinates() {
    if (!geofenceMap) {
        return;
    }
    const input = document.getElementById('geofenceCoordInput');
    const parsed = parseCoordinates(input.value);
    if (!parsed) {
        showError('Invalid coordinates. Use format: latitude, longitude');
        return;
    }

    if (geofenceSearchMarker && geofenceMap.hasLayer(geofenceSearchMarker)) {
        geofenceMap.removeLayer(geofenceSearchMarker);
    }
    geofenceSearchMarker = L.marker([parsed.lat, parsed.lng]).addTo(geofenceMap);
    geofenceSearchMarker.bindPopup(
        `<strong>Searched Coordinates</strong><br>Lat: ${parsed.lat.toFixed(8)}<br>Lng: ${parsed.lng.toFixed(8)}`
    ).openPopup();
    geofenceMap.setView([parsed.lat, parsed.lng], Math.max(geofenceMap.getZoom(), 16));

    if (document.getElementById('shapeType').value === 'circle') {
        document.getElementById('latitude').value = parsed.lat.toFixed(8);
        document.getElementById('longitude').value = parsed.lng.toFixed(8);
        syncCircleLayerFromInputs();
    }
}

function toggleGeofenceMapFullscreen() {
    const container = document.getElementById('geofenceMapContainer');
    if (isGeofenceMapFullscreen(container)) {
        if (!exitAnyFullscreen()) {
            container.classList.remove('map-pseudo-fullscreen');
        }
        setTimeout(() => geofenceMap?.invalidateSize(), 120);
        return;
    }

    if (!requestElementFullscreen(container)) {
        container.classList.add('map-pseudo-fullscreen');
    }
    setTimeout(() => geofenceMap?.invalidateSize(), 120);
}

function isGeofenceMapFullscreen(container) {
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

function geofenceMapSupportsRotation() {
    return geofenceMap && typeof geofenceMap.setBearing === 'function' && typeof geofenceMap.getBearing === 'function';
}

function rotateGeofenceMap(deltaDegrees) {
    if (!geofenceMapSupportsRotation()) {
        showError('Map rotation is not supported in this browser.');
        return;
    }
    const current = Number(geofenceMap.getBearing()) || 0;
    const next = (current + deltaDegrees + 360) % 360;
    geofenceMap.setBearing(next);
}

function resetGeofenceMapRotation() {
    if (!geofenceMapSupportsRotation()) {
        showError('Map rotation is not supported in this browser.');
        return;
    }
    geofenceMap.setBearing(0);
}

function updateShapeFieldVisibility(shapeType) {
    const isPolygon = shapeType === 'polygon';
    document.getElementById('circleFields').style.display = isPolygon ? 'none' : 'block';
    document.getElementById('polygonFields').style.display = isPolygon ? 'block' : 'none';
}

function clearDrawnShape() {
    if (drawnItems) {
        drawnItems.clearLayers();
    }
    currentShapeLayer = null;
    updatePolygonPreview([]);
}

function applyDrawnLayer(layer) {
    if (!drawnItems) return;
    drawnItems.clearLayers();
    drawnItems.addLayer(layer);
    currentShapeLayer = layer;
    syncFormFromLayer(layer);
}

function syncFormFromLayer(layer) {
    if (layer instanceof L.Circle) {
        document.getElementById('shapeType').value = 'circle';
        updateShapeFieldVisibility('circle');
        const center = layer.getLatLng();
        document.getElementById('latitude').value = center.lat.toFixed(8);
        document.getElementById('longitude').value = center.lng.toFixed(8);
        document.getElementById('radiusMeters').value = layer.getRadius().toFixed(2);
        updatePolygonPreview([]);
        return;
    }

    if (layer instanceof L.Polygon) {
        document.getElementById('shapeType').value = 'polygon';
        updateShapeFieldVisibility('polygon');
        updatePolygonPreview(getPolygonPointsFromLayer());
    }
}

function syncCircleLayerFromInputs() {
    if (!drawnItems || document.getElementById('shapeType').value !== 'circle') {
        return;
    }
    const lat = parseFloat(document.getElementById('latitude').value);
    const lng = parseFloat(document.getElementById('longitude').value);
    const radius = parseFloat(document.getElementById('radiusMeters').value);
    if (!Number.isFinite(lat) || !Number.isFinite(lng) || !Number.isFinite(radius) || radius <= 0) {
        return;
    }

    const circle = L.circle([lat, lng], { radius });
    applyDrawnLayer(circle);
    geofenceMap.panTo([lat, lng]);
}

function loadShapeOnMap(geofence) {
    if (!geofenceMap || !drawnItems) return;

    clearDrawnShape();
    const shapeType = geofence.shape_type || 'circle';
    if (shapeType === 'polygon' && Array.isArray(geofence.polygon_points) && geofence.polygon_points.length >= 3) {
        const latLngs = geofence.polygon_points.map(point => [Number(point.lat), Number(point.lng)]);
        const polygon = L.polygon(latLngs);
        applyDrawnLayer(polygon);
        geofenceMap.fitBounds(polygon.getBounds(), { padding: [20, 20] });
        return;
    }

    if (geofence.latitude != null && geofence.longitude != null && geofence.radius_meters != null) {
        const circle = L.circle([Number(geofence.latitude), Number(geofence.longitude)], {
            radius: Number(geofence.radius_meters)
        });
        applyDrawnLayer(circle);
        geofenceMap.fitBounds(circle.getBounds(), { padding: [20, 20] });
    }
}

function getPolygonPointsFromLayer() {
    if (!(currentShapeLayer instanceof L.Polygon) || (currentShapeLayer instanceof L.Circle)) {
        return [];
    }
    return currentShapeLayer.getLatLngs()[0].map(point => ({
        lat: Number(point.lat.toFixed(8)),
        lng: Number(point.lng.toFixed(8))
    }));
}

function updatePolygonPreview(points) {
    const preview = document.getElementById('polygonPointsPreview');
    const count = document.getElementById('polygonPointsCount');
    preview.value = points.length
        ? points.map((p, i) => `${i + 1}. ${p.lat}, ${p.lng}`).join('\n')
        : '';
    count.textContent = `${points.length} point${points.length === 1 ? '' : 's'} selected`;
}

function showError(msg) {
    const container = document.getElementById('error-container');
    container.textContent = msg;
    container.style.display = 'block';
    setTimeout(() => container.style.display = 'none', 5000);
}

function showSuccess(msg) {
    const container = document.getElementById('success-container');
    container.textContent = msg;
    container.style.display = 'block';
    setTimeout(() => container.style.display = 'none', 5000);
}

document.addEventListener('DOMContentLoaded', loadGeofences);
document.addEventListener('DOMContentLoaded', () => {
    const coordInput = document.getElementById('geofenceCoordInput');
    coordInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchGeofenceCoordinates();
        }
    });
    const onFullscreenChanged = () => {
        if (geofenceMap) {
            setTimeout(() => geofenceMap.invalidateSize(), 120);
        }
        const container = document.getElementById('geofenceMapContainer');
        const nativeFullscreenActive = document.fullscreenElement === container || document.webkitFullscreenElement === container;
        if (!nativeFullscreenActive) {
            container.classList.remove('map-pseudo-fullscreen');
        }
    };
    document.addEventListener('fullscreenchange', onFullscreenChanged);
    document.addEventListener('webkitfullscreenchange', onFullscreenChanged);
});
</script>
