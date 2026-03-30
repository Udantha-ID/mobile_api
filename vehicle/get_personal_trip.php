<?php

header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = []) {
  echo json_encode([
    "success" => $success,
    "message" => $message,
    "data" => $data
  ]);
  exit;
}

$employee_id = trim($_GET["employee_id"] ?? "");
$status      = strtoupper(trim($_GET["status"] ?? ""));

$allowed = ["PENDING", "HOD_APPROVED", "APPROVED", "START_TRIP", "IN_PROGRESS", "COMPLETED"];

if ($employee_id === "" || !ctype_digit($employee_id)) {
  respond(false, "employee_id is required and must be numeric");
}
if ($status === "" || !in_array($status, $allowed, true)) {
  respond(false, "status is required. Allowed: " . implode(", ", $allowed));
}

$employee_id = (int)$employee_id;

try {

  $sql = "
    SELECT
      ts.id,
      ts.status,
      ts.type,
      ts.vehicle_type,
      ts.vehicle_no,
      ts.vehicle_id,
      ts.is_vehicle_assigned,
      ts.chauffer_reason,
      ts.pickup_location,
      ts.dropoff_location,
      ts.passenger_count,
      ts.assigned_start_at,
      ts.assigned_end_at,
      ts.trip_code,
      ts.hod_comment,

      td.trip_start_datetime,
      td.trip_end_datetime,
      td.trip_start_odometer,
      td.trip_end_odometer,
      td.trip_start_odometer_photo,
      td.trip_end_odometer_photo,
      td.start_trip_fuel,
      td.end_trip_fuel,

      CASE
        WHEN td.trip_end_odometer IS NOT NULL AND td.trip_start_odometer IS NOT NULL
        THEN (td.trip_end_odometer - td.trip_start_odometer)
        ELSE NULL
      END AS distance_km

    FROM transport_services ts

    LEFT JOIN trip_details td
      ON td.trip_detail_id = (
        SELECT t2.trip_detail_id
        FROM trip_details t2
        WHERE t2.transport_service_id = ts.id
        ORDER BY t2.trip_detail_id DESC
        LIMIT 1
      )

    WHERE ts.employee_id = ?
      AND ts.type = 'personal'
      AND ts.status = ?
      AND ts.deleted_at IS NULL

    ORDER BY ts.id DESC
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("is", $employee_id, $status);
  $stmt->execute();

  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) $rows[] = $r;

  $stmt->close();

  respond(true, "OK", $rows);

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error: " . $e->getMessage());
}
