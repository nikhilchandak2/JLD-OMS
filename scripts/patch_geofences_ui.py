#!/usr/bin/env python3
from pathlib import Path

p = Path(__file__).resolve().parent.parent / "templates" / "geofences.php"
t = p.read_text(encoding="utf-8")
d = "d" + "iv"
c = "</" + d + ">"
o = "<" + d

# Remove premature toolbar close before coord row
old = "                            " + c + "\n                            " + c + "\n                            " + o + ' class="d-flex flex-wrap gap-2 mb-2">'
new = "                            " + o + ' class="d-flex flex-wrap gap-2 mt-2">'
if old in t:
    t = t.replace(old, new, 1)
    print("fixed toolbar close")
else:
    print("toolbar pattern not found")

# Close toolbar before map, empty help for JS
old2 = (
    '                            <' + d + ' id="geofence-map-help" class="small text-muted mb-2">\n'
    "                                Choose <strong>Circle</strong> or <strong>Polygon</strong> on the left, click <strong>Draw on Map</strong>, then draw on the map (polygon: click each corner; finish with double-click or click the first point).\n"
    "                            " + c + "\n"
    '                            <' + d + ' id="geofenceMapContainer">'
)
new2 = (
    '                            <' + d + ' id="geofence-map-help" class="small text-muted mt-2 mb-0"></' + d + ">\n"
    "                            " + c + "\n"
    '                            <' + d + ' id="geofenceMapContainer" class="geofence-map-container flex-grow-1">'
)
if old2 in t:
    t = t.replace(old2, new2, 1)
    print("fixed map container")
else:
    print("map pattern not found")

# Simplify coord input block
old3 = """                                <input
                                    type="text"
                                    id="geofenceCoordInput"
                                    class="form-control form-control-sm"
                                    style="max-width: 320px;"
                                    placeholder="Search coordinates: 23.0225, 72.5714"
                                >
                                <button class="btn btn-sm btn-outline-primary" type="button" onclick="searchGeofenceCoordinates()">
                                    <i class="bi bi-search me-1"></i>Go To Coordinates
                                </button>
                                <span class="small text-muted align-self-center">Enter latitude, longitude to jump and pin location.</span>"""
new3 = """                                <input type="text" id="geofenceCoordInput" class="form-control form-control-sm flex-grow-1" style="min-width: 140px;" placeholder="Lat, Lng e.g. 23.0225, 72.5714">
                                <button class="btn btn-sm btn-outline-primary geofence-tool-btn" type="button" onclick="searchGeofenceCoordinates()">
                                    <i class="bi bi-search me-1"></i>Go
                                </button>"""
if old3 in t:
    t = t.replace(old3, new3, 1)
    print("fixed coord row")

# CSS block replacement
old_css = """    #geofenceModal.map-modal-fullscreen .modal-body {
        overflow: hidden;
    }
    #geofenceModal.map-modal-fullscreen #geofenceFormColumn {
        display: none;
    }
    #geofenceModal.map-modal-fullscreen #geofenceMapColumn {
        width: 100%;
        max-width: 100%;
        flex: 0 0 100%;
    }
    #geofenceModal.map-modal-fullscreen #geofenceMapContainer {
        height: calc(100vh - 150px);
    }
    #geofenceModal.map-modal-fullscreen #geofenceMap {
        height: 100%;
    }
    #geofenceModal .leaflet-draw-toolbar,
    #geofenceModal .leaflet-top.leaflet-left {
        z-index: 1100;
    }"""

new_css = """    .geofence-modal-dialog {
        margin: 0.5rem auto;
    }
    .geofence-map-toolbar {
        flex-shrink: 0;
        overflow: visible;
        position: relative;
        z-index: 20;
    }
    .geofence-map-column {
        min-height: 0;
    }
    .geofence-map-container {
        min-height: 280px;
        position: relative;
        overflow: hidden;
    }
    .geofence-tool-btn {
        min-height: 2.5rem;
        touch-action: manipulation;
    }
    #geofenceModal .modal-body {
        overflow-x: hidden;
        overflow-y: auto;
    }
    #geofenceModal.map-modal-fullscreen .modal-body {
        display: flex;
        flex-direction: column;
        overflow: hidden;
        padding-bottom: env(safe-area-inset-bottom, 0);
    }
    #geofenceModal.map-modal-fullscreen .geofence-modal-row {
        flex: 1 1 auto;
        min-height: 0;
        margin: 0 !important;
    }
    #geofenceModal.map-modal-fullscreen #geofenceFormColumn {
        display: none !important;
    }
    #geofenceModal.map-modal-fullscreen #geofenceMapColumn {
        width: 100%;
        max-width: 100%;
        flex: 1 1 auto;
        height: 100%;
    }
    #geofenceModal.map-modal-fullscreen #geofenceMapToolbar {
        display: block !important;
        flex-shrink: 0;
        background: #fff;
        border-bottom: 1px solid var(--jld-border, #dee2e6);
        padding-bottom: 0.5rem;
        margin-bottom: 0.5rem !important;
    }
    #geofenceModal.map-modal-fullscreen #geofenceMapContainer {
        flex: 1 1 auto;
        height: auto !important;
        min-height: 0;
    }
    #geofenceModal.map-modal-fullscreen #geofenceMap {
        height: 100% !important;
        min-height: 200px;
    }
    #geofenceModal .leaflet-draw-toolbar,
    #geofenceModal .leaflet-top.leaflet-left,
    #geofenceModal .leaflet-draw-actions {
        z-index: 1200 !important;
    }
    #geofenceModal .leaflet-draw-actions a {
        min-width: 2.75rem;
        min-height: 2.75rem;
        touch-action: manipulation;
    }
    @media (max-width: 991.98px) {
        #geofenceMap {
            height: min(50vh, 420px);
        }
    }"""

if old_css in t:
    t = t.replace(old_css, new_css, 1)
    print("fixed css")

# Add JS functions before updateShapeFieldVisibility if not present
if "function undoLastPolygonVertex" not in t:
    insert = """
function syncActivePolygonDrawHandler() {
    if (!geofenceMap || !geofenceMap._toolbars || !geofenceMap._toolbars.draw) {
        return;
    }
    const mode = geofenceMap._toolbars.draw._modes.polygon;
    if (mode && mode.handler && mode.handler.enabled && mode.handler.enabled()) {
        activeDrawHandler = mode.handler;
    }
}

function updatePolygonDrawUi() {
    const undoBtn = document.getElementById('undoPolygonVertexBtn');
    if (!undoBtn) {
        return;
    }
    const isPolygon = document.getElementById('shapeType').value === 'polygon';
    undoBtn.style.display = isPolygon ? '' : 'none';
}

function undoLastPolygonVertex() {
    syncActivePolygonDrawHandler();
    if (activeDrawHandler && typeof activeDrawHandler.deleteLastVertex === 'function') {
        activeDrawHandler.deleteLastVertex();
        if (activeDrawHandler._poly) {
            const ring = extractPolygonRing(activeDrawHandler._poly);
            updatePolygonPreview(ring.map(point => ({
                lat: Number(point.lat.toFixed(8)),
                lng: Number(point.lng.toFixed(8))
            })));
        }
        return;
    }
    showError('Start polygon drawing first (Draw button), then use Undo point.');
}

function previewPolygonFromActiveDraw() {
    if (activeDrawHandler && activeDrawHandler._poly) {
        const ring = extractPolygonRing(activeDrawHandler._poly);
        if (ring.length) {
            updatePolygonPreview(ring.map(point => ({
                lat: Number(point.lat.toFixed(8)),
                lng: Number(point.lng.toFixed(8))
            })));
        }
    }
}

"""
    t = t.replace("function updateShapeFieldVisibility(shapeType) {", insert + "function updateShapeFieldVisibility(shapeType) {", 1)
    print("added js helpers")

# Patch updateShapeFieldVisibility
t = t.replace(
    "    document.getElementById('polygonFields').style.display = isPolygon ? 'block' : 'none';\n}",
    "    document.getElementById('polygonFields').style.display = isPolygon ? 'block' : 'none';\n    updatePolygonDrawUi();\n}",
)

# Patch shapeType listener
if "updatePolygonDrawUi();" not in t.split("refreshDrawControl();")[1][:200]:
    t = t.replace(
        "    updateShapeFieldVisibility(this.value);\n    refreshDrawControl();",
        "    updateShapeFieldVisibility(this.value);\n    refreshDrawControl();\n    updatePolygonDrawUi();",
    )

# Patch initGeofenceMap events
if "L.Draw.Event.DRAWVERTEX" not in t:
    t = t.replace(
        "    geofenceMap.on(L.Draw.Event.CREATED, function (event) {\n        cancelActiveDraw();\n        applyDrawnLayer(event.layer);\n    });",
        """    geofenceMap.on(L.Draw.Event.DRAWSTART, function (e) {
        if (e.layerType === 'polygon') {
            syncActivePolygonDrawHandler();
            updatePolygonDrawUi();
        }
    });

    geofenceMap.on(L.Draw.Event.DRAWVERTEX, function () {
        syncActivePolygonDrawHandler();
        previewPolygonFromActiveDraw();
    });

    geofenceMap.on(L.Draw.Event.CREATED, function (event) {
        cancelActiveDraw();
        applyDrawnLayer(event.layer);
    });""",
    )

# startDrawForCurrentShapeType - call updatePolygonDrawUi
t = t.replace(
    "    activeDrawHandler.enable();\n    showSuccess(shapeType === 'polygon'",
    "    activeDrawHandler.enable();\n    updatePolygonDrawUi();\n    showSuccess(shapeType === 'polygon'",
)

# toggleGeofenceMapFullscreen - update button icon and invalidate
old_toggle = """function toggleGeofenceMapFullscreen() {
    setGeofenceModalPseudoFullscreen(!geofenceModalPseudoFullscreen);
    setTimeout(() => geofenceMap?.invalidateSize(), 120);
}"""
new_toggle = """function toggleGeofenceMapFullscreen() {
    setGeofenceModalPseudoFullscreen(!geofenceModalPseudoFullscreen);
    const btn = document.getElementById('geofenceFullscreenBtn');
    if (btn) {
        const icon = btn.querySelector('i');
        if (icon) {
            icon.className = geofenceModalPseudoFullscreen
                ? 'bi bi-fullscreen-exit me-1'
                : 'bi bi-arrows-fullscreen me-1';
        }
        btn.querySelectorAll('span').forEach(s => {
            s.textContent = geofenceModalPseudoFullscreen ? 'Exit' : 'Fullscreen';
        });
    }
    setTimeout(() => {
        geofenceMap?.invalidateSize();
        refreshDrawControl();
    }, 150);
}"""
if old_toggle in t:
    t = t.replace(old_toggle, new_toggle, 1)

# showModal - updatePolygonDrawUi
t = t.replace(
    "            updateGeofenceDrawHelp();\n            if (onReady) onReady();",
    "            updateGeofenceDrawHelp();\n            updatePolygonDrawUi();\n            if (onReady) onReady();",
)

# showAddGeofenceModal - updatePolygonDrawUi at end of callback optional

p.write_text(t, encoding="utf-8")
print("Saved", p)
