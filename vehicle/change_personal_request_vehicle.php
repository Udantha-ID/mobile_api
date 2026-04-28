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

$request_id = trim($_POST["request_id"] ?? "");
$vehicle_id = trim($_POST["vehicle_id"] ?? "");
$vehicle_type = trim($_POST["vehicle_type"] ?? "");
$vehicle_no = trim($_POST["vehicle_no"] ?? "");
$reason = trim($_POST["reason"] ?? "Changed by manager");

if ($request_id === "" || !ctype_digit($request_id)) {
  respond(false, "Valid request_id is required");
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

$allowedTypes = ["Car", "Van", "Bus", "SUV"];
if (!in_array($vehicle_type, $allowedTypes, true)) {
  respond(false, "Invalid vehicle type");
}

$request_id = (int)$request_id;
$vehicle_id = (int)$vehicle_id;

try {
  $checkSql = "
    SELECT id
    FROM transport_services
    WHERE id = ?
      AND type = 'personal'
      AND deleted_at IS NULL
    LIMIT 1
  ";
  $checkStmt = $conn->prepare($checkSql);
  $checkStmt->bind_param("i", $request_id);
  $checkStmt->execute();
  $checkRes = $checkStmt->get_result();
  if (!$checkRes || $checkRes->num_rows === 0) {
    respond(false, "Request not found");
  }
  $checkStmt->close();

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
      AND type = 'personal'
      AND deleted_at IS NULL
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("isssi", $vehicle_id, $vehicle_type, $vehicle_no, $reason, $request_id);
  $stmt->execute();

  if ($stmt->errno) {
    respond(false, "Update failed");
  }

  if ($stmt->affected_rows > 0) {
    respond(true, "Personal request vehicle updated successfully");
  } else {
    respond(true, "No changes detected, request remains updated");
  }

  $stmt->close();
} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error");
}

