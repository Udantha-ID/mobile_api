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
if (!is_array($body)) respond(false, "Invalid JSON");

// Required fields
$employee_id = (int)($body["employee_id"] ?? 0);
$manager_id  = (int)($body["manager_id"] ?? 0);
$vehicle_no  = trim($body["vehicle_no"] ?? "");
$from_date   = trim($body["from_date"] ?? "");
$to_date     = trim($body["to_date"] ?? "");
//$destination = trim($body["destination"] ?? "");
$reason      = trim($body["reason"] ?? "Office Service");
$vehicle_type = trim($body["vehicle_type"] ?? "-");

// New fields (employee name & phone)
$chauffer_phone = trim($body["chauffer_phone"] ?? "");
$chauffer_name  = trim($body["chauffer_name"] ?? "");

// New field: vehicle_id
$vehicle_id = (int)($body["vehicle_id"] ?? 0);


// Validate
if ($employee_id <= 0) respond(false, "employee_id required");
if ($manager_id <= 0) respond(false, "manager_id required");
if ($vehicle_no === "") respond(false, "vehicle_no required");
if ($from_date === "") respond(false, "from_date required");
if ($to_date === "") respond(false, "to_date required");
//if ($destination === "") respond(false, "destination required");
if ($chauffer_phone === "") respond(false, "chauffer_phone required");
if ($chauffer_name === "") respond(false, "chauffer_name required");
if ($vehicle_id <= 0) respond(false, "vehicle_id required");


// convert date-only to DATETIME
$assigned_start_at = $from_date . " 00:00:00";
$assigned_end_at   = $to_date   . " 23:59:59";

// defaults because your form doesn't have pickup
$pickup_location  = "Head Office";
$dropoff_location = $destination;

// type & status
$type = "personal";
$status = "PENDING";

try {
  $stmt = $conn->prepare("
      INSERT INTO transport_services
        (source_id, type, vehicle_type, vehicle_id, vehicle_no, chauffer_phone, chauffer_name,
        employee_id, manager_id, status,
        assigned_start_at, pickup_location, dropoff_location, assigned_end_at,
        passenger_count, trip_code, created_at, updated_at)
      VALUES
        (0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NULL, NOW(), NOW())
  ");

  // 11 params:
  // type(s), vehicle_no(s), chauffer_phone(s), chauffer_name(s),
  // employee_id(i), manager_id(i), status(s),
  // assigned_start_at(s), pickup_location(s), dropoff_location(s), assigned_end_at(s)
  $stmt->bind_param(
      "ssissssssssss",
      $type,           // type
      $vehicle_type,   // vehicle_type
      $vehicle_id,     // vehicle_id
      $vehicle_no,
      $chauffer_phone,
      $chauffer_name,
      $employee_id,
      $manager_id,
      $status,
      $assigned_start_at,
      $pickup_location,
      $dropoff_location,
      $assigned_end_at
  );

  $stmt->execute();
  $id = $stmt->insert_id;
  $stmt->close();

  respond(true, "Request created", ["id" => $id]);

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error: " . $e->getMessage());
}