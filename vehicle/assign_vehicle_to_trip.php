<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message) {
  echo json_encode([
    "success" => $success,
    "message" => $message
  ]);
  exit;
}

function postJson($url, $payload, $token = null) {
  $ch = curl_init($url);

  $headers = [
    "Content-Type: application/json",
    "Accept: application/json",
  ];

  if ($token) {
    $headers[] = "Authorization: Bearer " . $token;
  }

  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 20,
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);
  curl_close($ch);

  return [
    "ok" => $curlError === "" && $httpCode >= 200 && $httpCode < 300,
    "http_code" => $httpCode,
    "response" => $response,
    "error" => $curlError,
  ];
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  http_response_code(405);
  respond(false, "Method not allowed");
}

$trip_id      = trim($_POST["trip_id"] ?? "");
$vehicle_id   = trim($_POST["vehicle_id"] ?? "");
$vehicle_type = trim($_POST["vehicle_type"] ?? "");
$vehicle_no   = trim($_POST["vehicle_no"] ?? "");
$reason       = trim($_POST["reason"] ?? "");

if ($trip_id === "" || !ctype_digit($trip_id)) {
  respond(false, "Valid trip_id is required");
}

if ($vehicle_id === "" || !ctype_digit($vehicle_id)) {
  respond(false, "Valid vehicle_id is required");
}

if ($vehicle_type === "") {
  respond(false, "Vehicle type is required");
}

if ($vehicle_no === "") {
  respond(false, "Vehicle number is required");
}

if ($reason === "") {
  respond(false, "Reason is required");
}

$allowedTypes = ["Car", "Van", "Bus", "SUV"];
if (!in_array($vehicle_type, $allowedTypes, true)) {
  respond(false, "Invalid vehicle type");
}

$trip_id = (int)$trip_id;
$vehicle_id = (int)$vehicle_id;

try {
  $selectSql = "
    SELECT id, source_id
    FROM transport_services
    WHERE id = ?
      AND status = 'ASSIGNED'
      AND type = 'transfers'
      AND deleted_at IS NULL
    LIMIT 1
  ";

  $selectStmt = $conn->prepare($selectSql);
  $selectStmt->bind_param("i", $trip_id);
  $selectStmt->execute();
  $result = $selectStmt->get_result();
  $tripRow = $result->fetch_assoc();
  $selectStmt->close();

  if (!$tripRow) {
    respond(false, "Trip not found");
  }

  $source_id = $tripRow["source_id"] ?? null;

  $sql = "
    UPDATE transport_services
    SET
      vehicle_id = ?,
      vehicle_type = ?,
      vehicle_no = ?,
      chauffer_reason = ?,
      is_vehicle_assigned = 1,
      updated_at = NOW()
    WHERE id = ?
      AND status = 'ASSIGNED'
      AND type = 'transfers'
      AND deleted_at IS NULL
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("isssi", $vehicle_id, $vehicle_type, $vehicle_no, $reason, $trip_id);
  $stmt->execute();

  if ($stmt->affected_rows <= 0) {
    $stmt->close();
    respond(false, "Trip not found or already updated");
  }

  $stmt->close();

  if ($source_id) {
    $fmsUrl = "https://srilankaautorentals.com/api/transport-services/sync-vehicle-assignment";
    $fmsToken = "123456789";

    $syncPayload = [
      "source_id" => (int) $source_id,
      "vehicle_id" => $vehicle_id,
      "reason" => $reason,
    ];

    $syncResult = postJson($fmsUrl, $syncPayload, $fmsToken);

    if (!$syncResult["ok"]) {
      http_response_code(500);
      respond(false, "ERP updated, but FMS sync failed");
    }
  }

  respond(true, "Vehicle assigned successfully");
} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error");
}