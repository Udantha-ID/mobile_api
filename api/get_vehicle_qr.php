<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

function respond($status, $message, $data = null, $debug = null) {
    echo json_encode([
        "status" => $status,
        "message" => $message,
        "data" => $data,
        "debug" => $debug
    ]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed");
}

$employee_id = trim($_POST["employee_id"] ?? "");
$preferred_name = trim($_POST["preferred_name"] ?? "");
$vehicle_number = trim($_POST["vehicle_number"] ?? "");

if ($employee_id === "" || $preferred_name === "" || $vehicle_number === "") {
    respond(false, "Missing required fields");
}

if (!ctype_digit($employee_id)) {
    respond(false, "Invalid employee_id");
}

$vehicle_number = strtoupper($vehicle_number);

$apiUrl = "https://exploredrive.lk/api/vehicle-details/" . urlencode($vehicle_number);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER => [
        "Accept: application/json"
    ]
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || !empty($curlError) || $httpCode !== 200) {
    respond(false, "Vehicle not found");
}

$decoded = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
    respond(false, "Vehicle not found");
}

if (
    !isset($decoded["status"]) ||
    $decoded["status"] != true ||
    !isset($decoded["data"]) ||
    !is_array($decoded["data"])
) {
    respond(false, "Vehicle not found");
}

$returnData = [
    "vehicle_number" => $decoded["data"]["vehicle_number"] ?? "",
    "company_name"   => $decoded["data"]["company_name"] ?? "",
    "image"          => $decoded["data"]["image"] ?? null,
];

$image = $decoded["data"]["image"] ?? null;
$is_qr_found = !empty($image) ? 1 : 0;
$api_message = !empty($image) ? "SUCCESS" : "QR_IMAGE_NOT_UPLOADED";

$sql = "INSERT INTO vehicle_qr_search_logs
        (employee_id, preferred_name, vehicle_number, searched_at, is_qr_found, api_message)
        VALUES (?, ?, ?, NOW(), ?, ?)";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("issis", $employee_id, $preferred_name, $vehicle_number, $is_qr_found, $api_message);
    $stmt->execute();
    $stmt->close();
}

if ($is_qr_found === 1) {
    respond(true, "QR Loaded ✔️", $returnData);
} else {
    respond(true, "Vehicle found, but QR image not uploaded yet", $returnData);
}
?>