# WheelsEye Vendor API (Current Location)

Details received from the GPS device vendor for **pull** API access (fetch current location from WheelsEye).

## Vendor details

| Item | Value |
|------|--------|
| **Device / Account ID** | WE8318053 |
| **API Account Name** | Jld Minerals API |
| **Reference / Contact** | 8387079292 |

## Current location API

- **Base URL (current location):**  
  `https://api.wheelseye.com/currentLoc?accessToken=<token>`

- **Access token (vendor-provided):**  
  `b6fbb5d6-fc43-44e9-884a-4323c0d56df3`

**Full URL (for reference):**
```
https://api.wheelseye.com/currentLoc?accessToken=b6fbb5d6-fc43-44e9-884a-4323c0d56df3
```

**Security:** For production, store the token in `.env` as `WHEELSEYE_ACCESS_TOKEN` and do not commit it. Use `env.example` only for variable names.

## Action required: share vehicle numbers with vendor

The vendor requested: **“Please find the details below and share the vehicle's number as well.”**

When you continue:

1. Export or list your **vehicle numbers** (and optionally IMEIs) from the OMS Vehicles page.
2. Share that list with the vendor (contact: 8387079292) so they can link devices to your vehicles.

## How to get data into your OMS

The link is a **pull API**: it returns current GPS position for all vehicles on the vendor account. You can get that data into your app in two ways.

### Recommended production architecture

1. **Webhook (primary)** — WheelsEye pushes GPS to `https://oms.jldminerals.com/api/gps/webhook` (real-time, best for trip accuracy).
2. **CLI systemd backup (secondary)** — Pull `currentLoc` every **2 minutes** without using PHP-FPM:
   ```bash
   sudo bash scripts/configure-wheelseye-production.sh
   ```
3. **Live Tracking UI** — Reads from the database only; use **Sync from WheelsEye** button for on-demand pull.

**Do not** schedule `curl https://oms.jldminerals.com/api/tracking/sync` — it competes with login/orders and caused 504 timeouts.

In production `.env`:
```env
WHEELSEYE_ALLOW_HTTP_SYNC=0
WHEELSEYE_ALLOW_LIVE_PAGE_SYNC=0
```

Logged-in **Sync from WheelsEye** still works; only URL/cron HTTP sync is blocked.

### Manual sync from dashboard

1. **Add the vehicle in OMS** (if not already):
   - Go to **Vehicles** → Add vehicle.
   - Set **Vehicle number** exactly as in WheelsEye (e.g. `RJ07GD5241` from the API).
   - Optionally set **GPS Device IMEI** to the device number from the API (e.g. `866992050999441`).
2. **Trigger a sync** (while logged in):
   - Click **Sync from WheelsEye** on Live Tracking, or open `/api/tracking/sync` while logged in.
3. The app will call WheelsEye, match vehicles by **vehicle number** or **device IMEI**, and save locations into **Live Tracking**.

### Option 2: Open the link in a browser

- Open:  
  `https://api.wheelseye.com/currentLoc?accessToken=b6fbb5d6-fc43-44e9-884a-4323c0d56df3`
- You will see raw JSON with `vehicleNumber`, `latitude`, `longitude`, `speed`, `ignition`, etc. This does **not** push data into your OMS; use Option 1 (or a cron calling the sync URL) for that.

### Matching vehicles

- Sync matches each API vehicle to an OMS vehicle by **vehicle number** (e.g. `RJ07GD5241`) or by **GPS device IMEI** (e.g. `866992050999441`).
- If a vehicle appears in the API but not in OMS, add it in Vehicles with the same **Vehicle number** (and optionally the same **GPS Device IMEI**), then run sync again.

### Automate: systemd CLI loop (recommended backup when webhook is active)

Runs `auto_sync_wheelseye.php` **outside PHP-FPM** (does not slow the website). Default **120 seconds** when webhook is primary.

```bash
sed -i 's/\r$//' /var/www/oms/scripts/wheelseye-sync-loop.sh /var/www/oms/scripts/install-wheelseye-systemd.sh /var/www/oms/scripts/configure-wheelseye-production.sh
chmod +x /var/www/oms/scripts/wheelseye-sync-loop.sh /var/www/oms/scripts/configure-wheelseye-production.sh
sudo bash scripts/configure-wheelseye-production.sh
```

If webhook is unreliable, use 30s CLI-only (never HTTP curl):

```bash
WHEELSEYE_SYNC_INTERVAL_SECONDS=30 sudo bash scripts/install-wheelseye-systemd.sh
```

Status / logs:

```bash
systemctl status oms-wheelseye-sync
journalctl -u oms-wheelseye-sync -f
```

### Legacy: cron every 30 seconds (CLI only)

```bash
sudo bash scripts/install-wheelseye-cron.sh
```

Do **not** run cron and systemd together. Do **not** use HTTP curl to `/api/tracking/sync`.

This script:
- Pulls latest data from WheelsEye and saves it to `gps_tracking_data`
- Runs your existing trip logic (`TripDetectionService::processTrackingData`) on each point
- Updates `storage/last_tracking_sync.json` with sync summary and `trip_count_delta`
- Appends every run (success/failure) to `storage/logs/wheelseye-sync.log` as JSON lines for persistent audit history

Quick checks:

```bash
tail -f /var/www/oms/storage/logs/wheelseye-cron.log
tail -f /var/www/oms/storage/logs/wheelseye-sync.log
cat /var/www/oms/storage/last_tracking_sync.json
```

### Deprecated: URL-based cron (causes 504s — do not use)

HTTP sync via `curl .../api/tracking/sync` uses PHP-FPM workers and can block the entire site when run every 30s. Use **CLI systemd** instead (see above).

If you must enable for debugging only, set `WHEELSEYE_ALLOW_HTTP_SYNC=1` in `.env` temporarily.

---

## Historical (yesterday) trip sync

If you need to backfill yesterday's trips for a vehicle, use:

- **Endpoint:** `GET /api/tracking/sync-yesterday-trips`
- **Auth:** logged-in session OR `TRACKING_SYNC_KEY`
- **Required:** `vehicle_id` **or** `vehicle_number`

Examples:

```bash
# By vehicle number
curl -s "https://oms.jldminerals.com/api/tracking/sync-yesterday-trips?vehicle_number=RJ07GD4606&key=YOUR_SECRET"

# By OMS vehicle ID
curl -s "https://oms.jldminerals.com/api/tracking/sync-yesterday-trips?vehicle_id=12&key=YOUR_SECRET"
```

Server `.env` options for vendor historical APIs:

```dotenv
WHEELSEYE_API_BASE_URL=https://api.wheelseye.com
WHEELSEYE_ACCESS_TOKEN=your-access-token
WHEELSEYE_ITINERARY_PATH=/getItinerary
WHEELSEYE_PATH_DETAIL_PATH=/getPathDetail
# Optional (default 5)
WHEELSEYE_POLYLINE_PRECISION=5
```

If your vendor account uses different historical endpoint paths, update:
- `WHEELSEYE_ITINERARY_PATH`
- `WHEELSEYE_PATH_DETAIL_PATH`

without changing application code.

---

## Next steps (when you continue)

- Add `WHEELSEYE_ACCESS_TOKEN` and `WHEELSEYE_API_BASE_URL` to `.env` for production (do not commit the token).
- Share **vehicle numbers** with the vendor (ref: 8387079292) so they can link devices correctly.
