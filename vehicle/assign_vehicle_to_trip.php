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

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  http_response_code(405);
  respond(false, "Method not allowed");
}

$trip_id      = trim($_POST["trip_id"] ?? "");
$vehicle_type = trim($_POST["vehicle_type"] ?? "");
$vehicle_no   = trim($_POST["vehicle_no"] ?? "");
$reason       = trim($_POST["reason"] ?? "");

if ($trip_id === "" || !ctype_digit($trip_id)) {
  respond(false, "Valid trip_id is required");
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

try {
  $sql = "
    UPDATE transport_services
    SET
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
  $stmt->bind_param("sssi", $vehicle_type, $vehicle_no, $reason, $trip_id);
  $stmt->execute();

  if ($stmt->affected_rows > 0) {
    respond(true, "Vehicle assigned successfully");
  } else {
    respond(false, "Trip not found or already updated");
  }

  $stmt->close();
} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error");
}