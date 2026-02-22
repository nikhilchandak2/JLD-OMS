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

### Option 1: Sync from dashboard (recommended)

1. **Add the vehicle in OMS** (if not already):
   - Go to **Vehicles** → Add vehicle.
   - Set **Vehicle number** exactly as in WheelsEye (e.g. `RJ07GD5241` from the API).
   - Optionally set **GPS Device IMEI** to the device number from the API (e.g. `866992050999441`).
2. **Trigger a sync** (while logged in):
   - Open: **https://oms.jldminerals.com/api/tracking/sync** (GET or POST).
   - Or use: `curl -b "your-cookies" https://oms.jldminerals.com/api/tracking/sync`
3. The app will call WheelsEye, match vehicles by **vehicle number** or **device IMEI**, and save locations into **Live Tracking**. Refresh the **Live Tracking** page to see the data.

### Option 2: Open the link in a browser

- Open:  
  `https://api.wheelseye.com/currentLoc?accessToken=b6fbb5d6-fc43-44e9-884a-4323c0d56df3`
- You will see raw JSON with `vehicleNumber`, `latitude`, `longitude`, `speed`, `ignition`, etc. This does **not** push data into your OMS; use Option 1 (or a cron calling the sync URL) for that.

### Matching vehicles

- Sync matches each API vehicle to an OMS vehicle by **vehicle number** (e.g. `RJ07GD5241`) or by **GPS device IMEI** (e.g. `866992050999441`).
- If a vehicle appears in the API but not in OMS, add it in Vehicles with the same **Vehicle number** (and optionally the same **GPS Device IMEI**), then run sync again.

### Optional: automate with cron (no need to click Sync)

1. In your `.env` on the server, set a secret:  
   `TRACKING_SYNC_KEY=your-random-secret-string`
2. Call the sync URL every few minutes (e.g. every 5 min). No login needed when the key is correct:

```bash
# Every 5 minutes (replace YOUR_SECRET with the value of TRACKING_SYNC_KEY)
*/5 * * * * curl -s "https://oms.jldminerals.com/api/tracking/sync?key=YOUR_SECRET"
```

Then the portal will have fresh locations without you clicking Sync. Turn on **Auto-refresh (30s)** on the Live Tracking page to refresh the map from the latest saved data.

---

## Next steps (when you continue)

- Add `WHEELSEYE_ACCESS_TOKEN` and `WHEELSEYE_API_BASE_URL` to `.env` for production (do not commit the token).
- Share **vehicle numbers** with the vendor (ref: 8387079292) so they can link devices correctly.
