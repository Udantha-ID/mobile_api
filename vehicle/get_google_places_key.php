<?php
/**
 * Returns the Google Maps / Places browser key for the mobile app.
 * ⚠️ Direct key used (ONLY for testing)
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// 🔴 Directly add your API key here
$key = 'AIzaSyB7vPsBQR4bp2RDm7Iz5u6hN1VzGnqtwsI';

// Check if key exists
if ($key === '' || $key === 'YOUR_KEY_HERE') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API key not set',
    ]);
    exit;
}

// Return response
echo json_encode([
    'success' => true,
    'api_key' => $key,
]);