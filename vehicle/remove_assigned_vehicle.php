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

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  respond(false, "Method not allowed");
}

$trip_id = trim($_POST["trip_id"] ?? "");
$reason  = trim($_POST["reason"] ?? "");

if ($trip_id === "" || !ctype_digit($trip_id)) {
  respond(false, "Valid trip_id required");
}

if ($reason === "") {
  respond(false, "Reason required");
}

$trip_id = (int)$trip_id;

try {

  $sql = "
    UPDATE transport_services
    SET
      vehicle_no = NULL,
      is_vehicle_assigned = 0,
      chauffer_reason = ?,
      updated_at = NOW()
    WHERE id = ?
      AND status = 'ASSIGNED'
      AND deleted_at IS NULL
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("si", $reason, $trip_id);
  $stmt->execute();

  if ($stmt->affected_rows > 0) {
    respond(true, "Vehicle removed successfully");
  } else {
    respond(false, "Trip not found or already updated");
  }

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error");
}