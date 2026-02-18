# WheelsEye GPS Device Setup Guide

## Webhook URL

Your GPS webhook endpoint is:
```
https://oms.jldminerals.com/api/gps/webhook
```

## Step 1: Add Vehicle in System

1. Go to **Vehicles** page in your dashboard
2. Click **Add Vehicle**
3. Enter vehicle details:
   - Vehicle Number (e.g., "Dumper-01")
   - Vehicle Type (Dumper, Excavator, etc.)
   - **Important**: Enter the **IMEI number** of your WheelsEye GPS device in the "GPS Device IMEI" field
4. Click **Save Vehicle**

The system will automatically register the GPS device when you save the vehicle.

## Step 2: Configure WheelsEye Device

### Option A: Via WheelsEye Web Portal/App

1. Log in to your WheelsEye account (web portal or mobile app)
2. Navigate to **Device Settings** or **Configuration**
3. Find your device by IMEI number
4. Go to **Data Forwarding** or **Webhook Settings**
5. Configure the webhook:
   - **URL**: `https://oms.jldminerals.com/api/gps/webhook`
   - **Method**: `POST`
   - **Content Type**: `application/json`
   - **Frequency**: Real-time or every 30 seconds (recommended)

### Option B: Via SMS Configuration (if supported)

Some WheelsEye devices can be configured via SMS. Contact WheelsEye support for SMS configuration commands.

## Step 3: Expected Data Format

The system accepts GPS data in the following format (flexible field mapping):

### Required Fields:
- `device_id` OR `imei` - Device identifier
- `latitude` OR `lat` - GPS latitude
- `longitude` OR `lng` - GPS longitude

### Optional Fields (recommended):
- `timestamp` OR `time` - Data timestamp
- `speed` - Vehicle speed (km/h)
- `heading` OR `course` - Direction/heading
- `altitude` OR `alt` - Altitude
- `ignition` OR `ignition_status` - Engine on/off
- `battery` OR `battery_level` - Device battery level
- `signal` OR `signal_strength` - Signal strength
- `satellites` OR `satellite_count` - Number of GPS satellites
- `odometer` OR `odometer_reading` - Odometer reading

### Example JSON Payload:
```json
{
  "imei": "123456789012345",
  "latitude": 28.6139,
  "longitude": 77.2090,
  "speed": 45.5,
  "heading": 180,
  "timestamp": "2024-01-15 10:30:00",
  "ignition": true,
  "battery": 85,
  "signal": 4
}
```

## Step 4: Test the Webhook

### Method 1: Manual Test (using curl)

On your server or local machine, run:

```bash
curl -X POST https://oms.jldminerals.com/api/gps/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "imei": "YOUR_IMEI_HERE",
    "latitude": 28.6139,
    "longitude": 77.2090,
    "speed": 45.5,
    "heading": 180,
    "timestamp": "2024-01-15 10:30:00",
    "ignition": true
  }'
```

Replace `YOUR_IMEI_HERE` with your actual device IMEI.

### Method 2: Check in Dashboard

1. After configuring the webhook, wait 1-2 minutes
2. Go to **Vehicles** page
3. Check if your vehicle shows:
   - GPS device status (should show the IMEI)
   - "Last Seen" timestamp (should update)
4. Go to **Live Tracking** page
5. You should see your vehicle on the map

### Method 3: Check Server Logs

On your server, monitor the webhook:

```bash
tail -f /var/log/nginx/oms-access.log | grep webhook
```

## Step 5: Verify Data Reception

1. **Check Vehicle Status**:
   - Go to Vehicles page
   - Your vehicle should show "Last Seen" updating

2. **Check Live Tracking**:
   - Go to Live Tracking page
   - Your vehicle should appear on the map
   - Location should update in real-time

3. **Check Database** (optional):
   ```bash
   mysql -u tracking_user -p order_processing_prod
   ```
   ```sql
   SELECT * FROM gps_tracking_data ORDER BY timestamp DESC LIMIT 10;
   SELECT * FROM gps_devices;
   ```

## Troubleshooting

### Vehicle Not Found Error

If you get "Vehicle not found for device":
- Make sure you entered the IMEI correctly in the Vehicles page
- The IMEI in the webhook data must match the IMEI in your vehicle record
- Check for any extra spaces or characters

### No Data Appearing

1. **Check Webhook Configuration**:
   - Verify the URL is correct: `https://oms.jldminerals.com/api/gps/webhook`
   - Ensure it's using POST method
   - Check if WheelsEye is sending data (check their portal for delivery status)

2. **Check Server Logs**:
   ```bash
   tail -50 /var/log/nginx/oms-error.log
   tail -50 /var/log/php8.2-fpm.log
   ```

3. **Test Webhook Manually**:
   Use the curl command above to test if the endpoint is working

4. **Check Device Status**:
   - Verify the GPS device is powered on
   - Check if it has GPS signal (should show in WheelsEye portal)
   - Ensure device has internet connectivity

### API Key Authentication

If you've configured an API key in your `.env` file, make sure WheelsEye includes it in the request headers:
```
X-API-Key: your_api_key_here
```

Or as a query parameter:
```
https://oms.jldminerals.com/api/gps/webhook?api_key=your_api_key_here
```

## Need Help?

- Check WheelsEye documentation for webhook configuration
- Contact WheelsEye support if you need help configuring the device
- Check server error logs for detailed error messages
