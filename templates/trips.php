<!-- Page Header -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="page-title">
                <i class="bi bi-arrow-left-right me-2"></i>Trips
            </h1>
            <p class="page-subtitle">Track trips from pit entry to stockpile entry</p>
        </div>
    </div>
</div>

<div id="error-container" class="error-message"></div>
<div id="success-container" class="success-message"></div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-funnel me-2"></i>Filters
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Start Date</label>
                <input type="date" class="form-control" id="filterStartDate" value="<?= date('Y-m-d', strtotime('-7 days')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">End Date</label>
                <input type="date" class="form-control" id="filterEndDate" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Material Type</label>
                <select class="form-select" id="filterMaterial">
                    <option value="">All Materials</option>
                    <option value="ball_clay_1st_grade">Ball Clay 1st Grade</option>
                    <option value="ball_clay_2nd_grade">Ball Clay 2nd Grade</option>
                    <option value="ball_clay_3rd_grade">Ball Clay 3rd Grade</option>
                    <option value="overburden">Overburden</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" id="filterStatus">
                    <option value="">All Status</option>
                    <option value="completed">Completed</option>
                    <option value="in_progress">In Progress</option>
                </select>
            </div>
            <div class="col-12">
                <button class="btn btn-primary" onclick="loadTrips()">
                    <i class="bi bi-search me-1"></i> Filter
                </button>
                <button class="btn btn-outline-primary ms-2" id="pullRebuildBtn" onclick="pullAndRebuildTrips()">
                    <i class="bi bi-arrow-repeat me-1"></i> Pull + Rebuild Trips
                </button>
                <label class="ms-3">
                    <input type="checkbox" id="autoRefreshTrips" onchange="toggleTripsAutoRefresh()" checked> Auto-refresh (45s)
                </label>
            </div>
        </div>
    </div>
</div>

<!-- Statistics -->
<div class="row mb-4" id="statistics">
    <!-- Will be populated by JavaScript -->
</div>

<!-- Daily / Vehicle Stoppage Summary -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-stopwatch me-2"></i>Start/Stop Summary</span>
        <span class="small text-muted">Based on current filters + today overall</span>
    </div>
    <div class="card-body">
        <div id="stoppageTodaySummary" class="row g-3 mb-3">
            <!-- Populated by JavaScript -->
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-sm">
                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Trips</th>
                        <th>Start/Stop Count</th>
                        <th>Total Stopped (min)</th>
                        <th>Avg Stop Duration (min)</th>
                    </tr>
                </thead>
                <tbody id="stoppageSummaryTableBody">
                    <tr><td colspan="5" class="text-center text-muted">Loading summary...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="loading" class="loading">
    <div class="spinner-border" role="status"></div>
    <p>Loading trips...</p>
</div>

<!-- Trips by vehicle (list of vehicles, expand to see trips) -->
<div id="tripsByVehicleList">
    <!-- Populated by JavaScript: vehicle cards with collapse per vehicle -->
</div>

<!-- Trip Stoppages Modal -->
<div class="modal fade" id="stoppagesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="stoppagesModalTitle">Trip Stoppages</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="stoppagesSummary" class="mb-3 small text-muted"></div>
                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Stop Start Time</th>
                                <th>Stop End Time</th>
                                <th>Duration (min)</th>
                                <th>Location</th>
                            </tr>
                        </thead>
                        <tbody id="stoppagesTableBody">
                            <tr>
                                <td colspan="5" class="text-center text-muted">No stoppages found</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let tripsAutoRefreshInterval = null;
let stoppagesModalInstance = null;
let isTripsLoading = false;

async function fetchJsonSafe(url, options = {}, timeoutMs = 90000) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
    try {
        const response = await fetch(url, { ...options, signal: controller.signal });
        const raw = await response.text();
        let data = null;
        try {
            data = raw ? JSON.parse(raw) : null;
        } catch (parseError) {
            throw new Error(`Server returned non-JSON response (${response.status})`);
        }
        if (!response.ok) {
            throw new Error((data && (data.error || data.message)) || `Request failed (${response.status})`);
        }
        return data;
    } catch (error) {
        if (error && (error.name === 'AbortError' || String(error.message || '').toLowerCase().includes('aborted'))) {
            throw new Error('Trips API is taking too long. Please retry in a few seconds.');
        }
        throw error;
    } finally {
        clearTimeout(timeoutId);
    }
}

function toggleTripsAutoRefresh() {
    const enabled = document.getElementById('autoRefreshTrips').checked;
    if (enabled) {
        loadTrips();
        tripsAutoRefreshInterval = setInterval(loadTrips, 45000);
    } else {
        if (tripsAutoRefreshInterval) {
            clearInterval(tripsAutoRefreshInterval);
            tripsAutoRefreshInterval = null;
        }
    }
}

function loadTrips() {
    if (isTripsLoading) {
        return;
    }
    isTripsLoading = true;
    document.getElementById('loading').style.display = 'flex';
    
    const params = new URLSearchParams();
    if (document.getElementById('filterStartDate').value) {
        params.append('start_date', document.getElementById('filterStartDate').value);
    }
    if (document.getElementById('filterEndDate').value) {
        params.append('end_date', document.getElementById('filterEndDate').value);
    }
    if (document.getElementById('filterMaterial').value) {
        params.append('material_type', document.getElementById('filterMaterial').value);
    }
    if (document.getElementById('filterStatus').value) {
        params.append('status', document.getElementById('filterStatus').value);
    }
    
    fetchJsonSafe(`/api/trips?${params}`)
        .then(data => {
            document.getElementById('loading').style.display = 'none';
            if (data.success) {
                renderStatistics(data.statistics);
                renderStoppageSummary(data.stoppage_summary_by_vehicle || [], data.today_stoppage_summary || {});
                renderTrips(data.data, data.destination_breakdown_by_vehicle || {});
            } else {
                showError(data.error || 'Failed to load trips');
            }
        })
        .catch(e => {
            document.getElementById('loading').style.display = 'none';
            showError('Error loading trips: ' + e.message);
        })
        .finally(() => {
            isTripsLoading = false;
        });
}

function renderStatistics(stats) {
    const container = document.getElementById('statistics');
    container.innerHTML = `
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-primary">${stats.total_trips || 0}</h3>
                    <p class="text-muted mb-0">Total Trips</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-success">${stats.completed_trips || 0}</h3>
                    <p class="text-muted mb-0">Completed</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-info">${(Number(stats.total_distance) || 0).toFixed(2)}</h3>
                    <p class="text-muted mb-0">Total Distance (km)</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-warning">${(Number(stats.total_fuel_consumed) || 0).toFixed(2)}</h3>
                    <p class="text-muted mb-0">Total Fuel (L)</p>
                </div>
            </div>
        </div>
    `;
}

function renderTrips(trips, breakdownByVehicle = {}) {
    const container = document.getElementById('tripsByVehicleList');
    if (!container) return;
    container.innerHTML = '';

    if (trips.length === 0) {
        container.innerHTML = '<div class="card"><div class="card-body text-center text-muted">No trips found</div></div>';
        return;
    }

    const byVehicle = {};
    trips.forEach(trip => {
        const k = trip.vehicle_id;
        if (!byVehicle[k]) byVehicle[k] = { vehicle_number: trip.vehicle_number, trips: [] };
        byVehicle[k].trips.push(trip);
    });

    Object.keys(byVehicle).forEach((vehicleId, idx) => {
        const v = byVehicle[vehicleId];
        const destinationLabel = formatDestinationCountsFromBreakdown(breakdownByVehicle[vehicleId] || []);
        const collapseId = 'trips-vehicle-' + vehicleId;
        const card = document.createElement('div');
        card.className = 'card mb-2';
        card.innerHTML = `
            <div class="card-header d-flex justify-content-between align-items-center py-3" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#${collapseId}" aria-expanded="${idx === 0}">
                <strong>${escapeHtml(v.vehicle_number)}</strong>
                <div class="d-flex gap-2 align-items-center">
                    <span class="badge bg-primary">${v.trips.length} trip(s)</span>
                    <span class="badge bg-light text-dark">${escapeHtml(destinationLabel)}</span>
                </div>
            </div>
            <div id="${collapseId}" class="collapse ${idx === 0 ? 'show' : ''}">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Entered Pit (Start)</th>
                                    <th>Entered Stockpile (End)</th>
                                    <th>Source</th>
                                    <th>Destination</th>
                                    <th>Material</th>
                                    <th>Distance (km)</th>
                                    <th>Duration</th>
                                    <th>Stoppages</th>
                                    <th>Fuel (L)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>${v.trips.map(t => tripRow(t)).join('')}</tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
        container.appendChild(card);
    });
}

function renderStoppageSummary(rows, todaySummary) {
    const todayContainer = document.getElementById('stoppageTodaySummary');
    const totalStops = Number(todaySummary.total_stops || 0);
    const totalMinutes = Number(todaySummary.total_stoppage_minutes || 0);
    const totalTrips = Number(todaySummary.trip_count || 0);
    const totalVehicles = Number(todaySummary.vehicle_count || 0);

    todayContainer.innerHTML = `
        <div class="col-md-3">
            <div class="border rounded p-2 text-center bg-light">
                <div class="small text-muted">Today Stops</div>
                <div class="h5 mb-0">${totalStops}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded p-2 text-center bg-light">
                <div class="small text-muted">Today Stopped (min)</div>
                <div class="h5 mb-0">${totalMinutes.toFixed(1)}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded p-2 text-center bg-light">
                <div class="small text-muted">Today Trips</div>
                <div class="h5 mb-0">${totalTrips}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded p-2 text-center bg-light">
                <div class="small text-muted">Vehicles Active Today</div>
                <div class="h5 mb-0">${totalVehicles}</div>
            </div>
        </div>
    `;

    const tbody = document.getElementById('stoppageSummaryTableBody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No start/stop data found for selected filters.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map(row => {
        const stopCount = Number(row.total_stops || 0);
        const stopMinutes = Number(row.total_stoppage_minutes || 0);
        const avgStop = stopCount > 0 ? (stopMinutes / stopCount) : 0;
        return `
            <tr>
                <td><strong>${escapeHtml(row.vehicle_number)}</strong></td>
                <td>${Number(row.trip_count || 0)}</td>
                <td>${stopCount}</td>
                <td>${stopMinutes.toFixed(1)}</td>
                <td>${avgStop.toFixed(2)}</td>
            </tr>
        `;
    }).join('');
}

function tripRow(trip) {
    const stopCount = trip.stoppage_count != null && trip.stoppage_count !== '' ? Number(trip.stoppage_count) : null;
    const stopMin = trip.total_stoppage_minutes != null && trip.total_stoppage_minutes !== '' ? Number(trip.total_stoppage_minutes) : null;
    const stoppageText = (stopCount != null && stopCount > 0)
        ? `<button class="btn btn-sm btn-outline-secondary" onclick="viewTripStoppages(${trip.id}); event.stopPropagation();">${stopCount} (${(stopMin ?? 0).toFixed(1)} min)</button>`
        : (stopCount === 0 ? '0' : '-');
    return `
        <tr>
            <td>${new Date(trip.start_time).toLocaleString()}</td>
            <td>${trip.end_time ? new Date(trip.end_time).toLocaleString() : '-'}</td>
            <td>${escapeHtml(trip.source_geofence_name || 'N/A')}</td>
            <td>${escapeHtml(trip.destination_geofence_name || 'N/A')}</td>
            <td>${escapeHtml(trip.material_type || '-')}</td>
            <td>${trip.distance_km != null && trip.distance_km !== '' ? Number(trip.distance_km).toFixed(2) : '-'}</td>
            <td>${trip.duration_minutes != null && trip.duration_minutes !== '' ? trip.duration_minutes + ' min' : '-'}</td>
            <td>${stoppageText}</td>
            <td>${trip.fuel_consumed_liters != null && trip.fuel_consumed_liters !== '' ? Number(trip.fuel_consumed_liters).toFixed(2) : '-'}</td>
            <td><span class="badge bg-${trip.status === 'completed' ? 'success' : trip.status === 'in_progress' ? 'warning' : 'secondary'}">${escapeHtml(trip.status)}</span></td>
        </tr>
    `;
}

function formatDestinationCountsFromBreakdown(rows) {
    const labels = rows.map(row => `${row.destination_name}: ${row.trip_count}`);
    return labels.length ? labels.join(' | ') : 'No completed destination trips';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
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
    setTimeout(() => container.style.display = 'none', 7000);
}

function getSelectedRangeForRebuild() {
    const startDate = document.getElementById('filterStartDate').value || new Date().toISOString().slice(0, 10);
    const endDate = document.getElementById('filterEndDate').value || new Date().toISOString().slice(0, 10);
    return {
        start_time: `${startDate} 00:00:00`,
        end_time: `${endDate} 23:59:59`,
    };
}

async function pullAndRebuildTrips() {
    const btn = document.getElementById('pullRebuildBtn');
    if (!btn) return;

    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Processing...';

    try {
        const syncData = await fetchJsonSafe('/api/tracking/sync', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': '<?= $csrf_token ?>'
            }
        });
        if (!syncData.success) {
            throw new Error(syncData.message || syncData.error || 'Failed to pull latest WheelsEye data');
        }

        const rebuildPayload = getSelectedRangeForRebuild();
        const rebuildData = await fetchJsonSafe('/api/tracking/rebuild-trips', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?= $csrf_token ?>'
            },
            body: JSON.stringify(rebuildPayload)
        });
        if (!rebuildData.success) {
            throw new Error(rebuildData.error || 'Failed to rebuild trips');
        }

        const totals = rebuildData?.data?.totals || {};
        const errors = rebuildData?.data?.errors || [];
        const syncNote = Number(syncData.synced || 0);
        const vehiclesSynced = Number(syncData.vehicles_synced || 0);
        const skippedSync = Number(syncData.skipped || 0);
        const pointsProcessed = Number(totals.tracking_points_processed || 0);
        const pitEntries = Number(totals.pit_entries || 0);
        const pitExits = Number(totals.pit_exits || 0);
        const destEntries = Number(totals.destination_entries || 0);
        const message = `Pulled ${syncNote} GPS point(s) for ${vehiclesSynced} vehicle(s) (skipped ${skippedSync}); processed ${pointsProcessed} points; events: pit in ${pitEntries}, pit out ${pitExits}, destination in ${destEntries}; rebuilt trips: ${Number(totals.total_trips || 0)} total, ${Number(totals.completed_trips || 0)} completed.`;
        showSuccess(errors.length ? `${message} (${errors.length} vehicle error(s))` : message);
        loadTrips();
    } catch (error) {
        showError(error.message || 'Pull + rebuild failed');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

function viewTripStoppages(tripId) {
    if (!stoppagesModalInstance) {
        stoppagesModalInstance = new bootstrap.Modal(document.getElementById('stoppagesModal'));
    }
    document.getElementById('stoppagesTableBody').innerHTML = '<tr><td colspan="5" class="text-center text-muted">Loading stoppages...</td></tr>';
    document.getElementById('stoppagesSummary').textContent = '';
    document.getElementById('stoppagesModalTitle').textContent = `Trip #${tripId} Stoppages`;
    stoppagesModalInstance.show();

    fetchJsonSafe(`/api/trips/${tripId}/stoppages`)
        .then(data => {
            if (!data.success) {
                throw new Error(data.error || 'Failed to load stoppages');
            }
            renderTripStoppages(data.trip, data.data || []);
        })
        .catch(e => {
            document.getElementById('stoppagesTableBody').innerHTML = `<tr><td colspan="5" class="text-center text-danger">${escapeHtml(e.message)}</td></tr>`;
        });
}

function renderTripStoppages(trip, stoppages) {
    const title = trip?.vehicle_number
        ? `${trip.vehicle_number} - Trip #${trip.id} Stoppages`
        : `Trip #${trip.id} Stoppages`;
    document.getElementById('stoppagesModalTitle').textContent = title;
    document.getElementById('stoppagesSummary').textContent =
        `Count: ${trip?.stoppage_count ?? 0} | Total stopped: ${Number(trip?.total_stoppage_minutes || 0).toFixed(1)} min`;

    const tbody = document.getElementById('stoppagesTableBody');
    if (!stoppages.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No detailed stoppages found for this trip.</td></tr>';
        return;
    }

    tbody.innerHTML = stoppages.map((stop, idx) => {
        const location = (stop.latitude != null && stop.longitude != null)
            ? `${Number(stop.latitude).toFixed(6)}, ${Number(stop.longitude).toFixed(6)}`
            : '-';
        return `
            <tr>
                <td>${idx + 1}</td>
                <td>${new Date(stop.start_time).toLocaleString()}</td>
                <td>${new Date(stop.end_time).toLocaleString()}</td>
                <td>${Number(stop.duration_minutes).toFixed(2)}</td>
                <td>${location}</td>
            </tr>
        `;
    }).join('');
}

document.addEventListener('DOMContentLoaded', () => {
    toggleTripsAutoRefresh();
});
</script>
