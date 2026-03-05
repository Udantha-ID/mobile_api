<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
  echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
  exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  http_response_code(405);
  respond(false, "Method not allowed");
}

$raw = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) {
  http_response_code(400);
  respond(false, "Invalid JSON body");
}

$trip_id   = trim($body["tripId"] ?? "");
$trip_code = trim($body["tripCode"] ?? "");

if ($trip_id === "" || !ctype_digit($trip_id)) {
  http_response_code(400);
  respond(false, "tripId is required and must be numeric");
}
if ($trip_code === "" || strlen($trip_code) > 30) {
  http_response_code(400);
  respond(false, "tripCode is required (max 30 chars)");
}

$trip_id = (int)$trip_id;

try {
  // Only allow generate when current status is ASSIGNED
  $checkSql = "SELECT status FROM transport_services
               WHERE id = ? AND (type = 'shuttle' OR type = 'transfer') AND deleted_at IS NULL
               LIMIT 1";
  $stmt = $conn->prepare($checkSql);
  $stmt->bind_param("i", $trip_id);
  $stmt->execute();
  $res = $stmt->get_result();

  if ($res->num_rows === 0) {
    respond(false, "Trip not found");
  }

  $row = $res->fetch_assoc();
  $current = strtoupper($row["status"] ?? "");
  $stmt->close();

  if ($current !== "ASSIGNED") {
    respond(false, "Trip status must be ASSIGNED to generate code");
  }

  // Update trip_code + status
  $updSql = "UPDATE transport_services
             SET trip_code = ?, status = 'START_TRIP', updated_at = NOW()
             WHERE id = ? AND status = 'ASSIGNED' AND deleted_at IS NULL";
  $stmt2 = $conn->prepare($updSql);
  $stmt2->bind_param("si", $trip_code, $trip_id);
  $stmt2->execute();

  if ($stmt2->affected_rows === 0) {
    $stmt2->close();
    respond(false, "Update failed (maybe already updated)");
  }

  $stmt2->close();
  respond(true, "Trip code generated", [
    "tripId" => $trip_id,
    "tripCode" => $trip_code,
    "status" => "START_TRIP",
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error: " . $e->getMessage());
}