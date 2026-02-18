#!/bin/bash
# Test GPS Webhook Endpoint
# Usage: ./test_gps_webhook.sh <IMEI> <LAT> <LNG>

IMEI=${1:-"123456789012345"}
LAT=${2:-"28.6139"}
LNG=${3:-"77.2090"}

echo "Testing GPS Webhook..."
echo "IMEI: $IMEI"
echo "Location: $LAT, $LNG"
echo ""

curl -X POST https://oms.jldminerals.com/api/gps/webhook \
  -H "Content-Type: application/json" \
  -d "{
    \"imei\": \"$IMEI\",
    \"latitude\": $LAT,
    \"longitude\": $LNG,
    \"speed\": 45.5,
    \"heading\": 180,
    \"timestamp\": \"$(date '+%Y-%m-%d %H:%M:%S')\",
    \"ignition\": true,
    \"battery\": 85,
    \"signal\": 4
  }" \
  -w "\n\nHTTP Status: %{http_code}\n"

echo ""
echo "Done! Check your dashboard to see if the data was received."
