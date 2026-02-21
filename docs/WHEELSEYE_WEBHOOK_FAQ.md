# WheelsEye Webhook - Will It Work Automatically?

## Yes, it will work automatically once these conditions are met:

### 1. **Add Your Vehicle First** (Required)

Before WheelsEye sends data, you **must** add the vehicle in your OMS:

1. Go to **Vehicles** page
2. Click **Add Vehicle**
3. Enter vehicle details
4. **Important**: Enter the **GPS Device IMEI** in the "GPS Device IMEI" field
5. Save

**Why?** The system matches incoming data to vehicles by IMEI. If no vehicle has that IMEI, you'll get "Vehicle not found for device" error.

---

### 2. **Configure WheelsEye to Send Data**

WheelsEye must be configured to push data to your webhook URL:

**Webhook URL:**
```
https://oms.jldminerals.com/api/gps/webhook
```

**Configuration (in WheelsEye portal/app):**
- **URL**: `https://oms.jldminerals.com/api/gps/webhook`
- **Method**: POST
- **Content-Type**: application/json
- **Frequency**: Real-time or every 30-60 seconds

Contact WheelsEye support if you need help finding these settings.

---

### 3. **Data Flow (Automatic)**

Once both steps above are done:

```
WheelsEye Device → Sends GPS data → Your Server → Saves to database → Appears in dashboard
```

**What happens automatically:**
1. WheelsEye sends POST request with GPS data
2. System receives and validates the data
3. System finds your vehicle by IMEI
4. Tracking data is saved to database
5. "Last Seen" updates on Vehicles page
6. Location appears on Live Tracking map
7. Trip detection runs (if geofences are set up)

**No manual action needed** - it's fully automatic.

---

### 4. **Required Data Fields**

WheelsEye must send at least:
- `imei` or `device_id` (must match what you entered in vehicle)
- `latitude` or `lat`
- `longitude` or `lng`

Optional but recommended: `timestamp`, `speed`, `heading`, `ignition`

---

### 5. **API Key (Optional)**

By default, **no API key is required**. The webhook accepts all requests.

If you want extra security, add to your `.env` file:
```
GPS_FUEL_API_KEY=your_secret_key_here
```

Then configure WheelsEye to send the key in request header:
```
X-API-Key: your_secret_key_here
```

---

### 6. **Testing**

**Manual test** (replace YOUR_IMEI with actual IMEI):
```bash
curl -X POST https://oms.jldminerals.com/api/gps/webhook \
  -H "Content-Type: application/json" \
  -d '{"imei":"YOUR_IMEI","latitude":28.6139,"longitude":77.2090,"speed":45}'
```

**Expected success response:**
```json
{"success":true,"message":"GPS data received","vehicle_id":1}
```

**If vehicle not found:**
```json
{"error":"Vehicle not found for device","device_id":"123456789"}
```
→ Add the vehicle with that IMEI first.

---

### 7. **Troubleshooting**

| Issue | Solution |
|-------|----------|
| "Vehicle not found" | Add vehicle with correct IMEI in Vehicles page |
| "Unauthorized" | Check if GPS_FUEL_API_KEY is set - either remove it or configure WheelsEye to send it |
| No data appearing | Verify WheelsEye is sending to correct URL; check server logs |
| 404/500 errors | Check server is running; verify URL is correct |

---

## Summary

**Yes, it works automatically** - no coding or manual intervention needed. Just:
1. Add vehicle with IMEI
2. Configure WheelsEye webhook URL
3. Data flows automatically
