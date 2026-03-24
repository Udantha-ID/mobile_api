<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
  echo json_encode([
    "success" => $success,
    "message" => $message,
    "data" => $data
  ]);
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
  $checkSql = "
    SELECT
      id,
      status,
      type,
      vehicle_no,
      is_vehicle_assigned
    FROM transport_services
    WHERE id = ?
      AND (type = 'shuttle' OR type = 'transfers')
      AND deleted_at IS NULL
    LIMIT 1
  ";

  $stmt = $conn->prepare($checkSql);
  $stmt->bind_param("i", $trip_id);
  $stmt->execute();
  $res = $stmt->get_result();

  if ($res->num_rows === 0) {
    $stmt->close();
    respond(false, "Trip not found");
  }

  $trip = $res->fetch_assoc();
  $stmt->close();

  $currentStatus = strtoupper(trim($trip["status"] ?? ""));
  $vehicleNo = trim($trip["vehicle_no"] ?? "");
  $isVehicleAssigned = (int)($trip["is_vehicle_assigned"] ?? 0);

  if ($currentStatus !== "ASSIGNED") {
    respond(false, "Trip status must be ASSIGNED to generate code");
  }

  if ($isVehicleAssigned !== 1 || $vehicleNo === "") {
    respond(false, "Vehicle is not assigned to this trip");
  }

  $conflictSql = "
    SELECT
      ts.id,
      ts.status,
      ts.type,
      COALESCE(NULLIF(TRIM(e.preferred_name), ''), NULLIF(TRIM(e.full_name), ''), CONCAT('Employee ID ', ts.employee_id)) AS employee_name
    FROM transport_services ts
    LEFT JOIN employees e
      ON e.employee_id = ts.employee_id
    WHERE ts.vehicle_no = ?
      AND ts.id <> ?
      AND ts.deleted_at IS NULL
      AND ts.status IN ('ASSIGNED', 'START_TRIP', 'IN_PROGRESS')
    ORDER BY ts.id DESC
    LIMIT 1
  ";

    $stmt2 = $conn->prepare($conflictSql);
    $stmt2->bind_param("si", $vehicleNo, $trip_id);
    $stmt2->execute();
    $res2 = $stmt2->get_result();

   if ($res2->num_rows > 0) {
    $conflict = $res2->fetch_assoc();
    $stmt2->close();

    $conflictStatus = strtoupper(trim($conflict["status"] ?? ""));
    $employeeName = trim($conflict["employee_name"] ?? "Unknown");
    $tripTypeRaw = strtolower(trim($conflict["type"] ?? "trip"));
    $tripType = ucfirst($tripTypeRaw);

    if ($conflictStatus === "ASSIGNED") {
      respond(false, "Vehicle $vehicleNo is already assigned to $tripType trip, Chauffeur - $employeeName.");
    }

    if ($conflictStatus === "START_TRIP") {
      respond(false, "Vehicle $vehicleNo is already reserved for $tripType trip, Chauffeur - $employeeName.");
    }

    if ($conflictStatus === "IN_PROGRESS") {
      respond(false, "Vehicle $vehicleNo is already in progress on $tripType trip, Chauffeur - $employeeName.");
    }

    respond(false, "Vehicle $vehicleNo is not available.");
  }

  $stmt2->close();

  $updSql = "
    UPDATE transport_services
    SET
      trip_code = ?,
      status = 'START_TRIP',
      updated_at = NOW()
    WHERE id = ?
      AND status = 'ASSIGNED'
      AND deleted_at IS NULL
  ";

  $stmt3 = $conn->prepare($updSql);
  $stmt3->bind_param("si", $trip_code, $trip_id);
  $stmt3->execute();

  if ($stmt3->affected_rows === 0) {
    $stmt3->close();
    respond(false, "Update failed (maybe already updated)");
  }

  $stmt3->close();

  respond(true, "Trip code generated", [
    "tripId" => $trip_id,
    "tripCode" => $trip_code,
    "status" => "START_TRIP"
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error: " . $e->getMessage());
}