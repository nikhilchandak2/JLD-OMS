# WheelsEye GPS Quick Start Guide

## 🚀 Quick Setup (3 Steps)

### Step 1: Add Your Vehicle
1. Go to **https://oms.jldminerals.com/vehicles**
2. Click **Add Vehicle**
3. Enter:
   - Vehicle Number (e.g., "Dumper-01")
   - Vehicle Type
   - **GPS Device IMEI** (from your WheelsEye device)
4. Click **Save Vehicle**

### Step 2: Configure WheelsEye Webhook

**Your Webhook URL:**
```
https://oms.jldminerals.com/api/gps/webhook
```

**How to configure:**
1. Log in to **WheelsEye Portal** (web or app)
2. Go to **Device Settings** → **Data Forwarding** or **Webhook**
3. Add webhook:
   - **URL**: `https://oms.jldminerals.com/api/gps/webhook`
   - **Method**: `POST`
   - **Format**: `JSON`
   - **Frequency**: Real-time or 30 seconds

### Step 3: Test It!

**Option A: Manual Test (using curl)**
```bash
curl -X POST https://oms.jldminerals.com/api/gps/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "imei": "YOUR_IMEI_HERE",
    "latitude": 28.6139,
    "longitude": 77.2090,
    "speed": 45.5,
    "timestamp": "2024-01-15 10:30:00"
  }'
```

**Option B: Check Dashboard**
1. Wait 1-2 minutes after configuring
2. Go to **Vehicles** page - check "Last Seen" timestamp
3. Go to **Live Tracking** page - see your vehicle on map!

## 📋 Required Data Fields

WheelsEye should send:
- `imei` or `device_id` (required)
- `latitude` or `lat` (required)
- `longitude` or `lng` (required)
- `timestamp` or `time` (optional but recommended)
- `speed`, `heading`, `ignition` (optional)

## ❓ Troubleshooting

**Vehicle not found?**
- Make sure IMEI in vehicle matches IMEI from device
- Check for typos or extra spaces

**No data appearing?**
- Verify webhook URL is correct
- Check WheelsEye portal for delivery status
- Check server logs: `tail -f /var/log/nginx/oms-error.log`

**Need help?**
- See full guide: `docs/WHEELSEYE_SETUP_GUIDE.md`
- Contact WheelsEye support for device configuration
