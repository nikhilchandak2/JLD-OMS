<?php
/**
 * Test script to simulate Busy software webhook
 * This demonstrates how Busy software should send invoice data to create automatic dispatches
 */

// Sample invoice data that Busy software should send
$invoiceData = [
    'invoice_no' => 'INV-2025-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT),
    'invoice_date' => date('Y-m-d'),
    'party_name' => 'Test Construction Company',
    'product_name' => 'Sand',
    'quantity' => rand(1, 10),
    'vehicle_no' => 'MH12AB' . rand(1000, 9999),
    'company_name' => 'JLD Minerals Pvt. Ltd.',
    'remarks' => 'Test dispatch from webhook integration'
];

// API endpoint URL
$webhookUrl = 'http://localhost:8000/api/busy/webhook';

// API key for authentication (you should set this in your environment)
$apiKey = 'your-secret-api-key-here';

// Prepare the request
$postData = json_encode($invoiceData);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $webhookUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-API-KEY: ' . $apiKey
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// Execute the request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Display the results
echo "=== Busy Software Webhook Test ===\n\n";
echo "Sent Invoice Data:\n";
echo json_encode($invoiceData, JSON_PRETTY_PRINT) . "\n\n";

echo "Response (HTTP $httpCode):\n";
echo $response . "\n\n";

if ($httpCode === 200) {
    echo "✅ SUCCESS: Dispatch created automatically!\n";
    $responseData = json_decode($response, true);
    if ($responseData && $responseData['success']) {
        echo "Order No: " . $responseData['data']['order_no'] . "\n";
        echo "Dispatch ID: " . $responseData['data']['dispatch_id'] . "\n";
        echo "Quantity: " . $responseData['data']['dispatch_qty'] . " trucks\n";
    }
} else {
    echo "❌ ERROR: Failed to create dispatch\n";
    $errorData = json_decode($response, true);
    if ($errorData && isset($errorData['error'])) {
        echo "Error: " . $errorData['error'] . "\n";
        if (isset($errorData['message'])) {
            echo "Message: " . $errorData['message'] . "\n";
        }
    }
}

echo "\n=== Integration Instructions ===\n";
echo "1. Configure Busy software to send POST requests to: $webhookUrl\n";
echo "2. Include API key in header: X-API-KEY: your-secret-api-key-here\n";
echo "3. Send invoice data in JSON format as shown above\n";
echo "4. The system will automatically:\n";
echo "   - Find or create the party and product\n";
echo "   - Find existing pending order or create new one\n";
echo "   - Create dispatch with the invoice quantity\n";
echo "   - Adjust scheduled deliveries if it's a recurring order\n";
?>


