<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
  echo json_encode(["success"=>$success, "message"=>$message, "data"=>$data]);
  exit;
}

function makeTripCode($employeeName) {
  // remove spaces, uppercase
  $clean = strtoupper(preg_replace('/\s+/', '', (string)$employeeName));
  if (strlen($clean) < 4) $clean = str_pad($clean, 4, "X");
  $namePart = substr($clean, 0, 4);
  $numPart = strval(random_int(1000, 9999));
  return "#".$namePart.$numPart;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  http_response_code(405);
  respond(false, "Method not allowed");
}

$raw = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) respond(false, "Invalid JSON");

$request_id = (int)($body["request_id"] ?? 0);
if ($request_id <= 0) respond(false, "request_id required");

try {
  // 1) Load employee name for this request (only PENDING)
  $stmt = $conn->prepare("
    SELECT
      ts.id,
      ts.status,
      e.full_name,
      e.preferred_name
    FROM transport_services ts
    JOIN employees e ON e.employee_id = ts.employee_id
    WHERE ts.id = ?
      AND ts.status = 'PENDING'
      AND ts.deleted_at IS NULL
    LIMIT 1
  ");
  $stmt->bind_param("i", $request_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  $stmt->close();

  if (!$row) {
    respond(false, "Cannot approve. Only PENDING requests can be approved.");
  }

  $employeeName = trim($row["preferred_name"] ?: $row["full_name"]);
  if ($employeeName === "") $employeeName = "USER";

  // 2) Generate trip code
  $tripCode = makeTripCode($employeeName);

  // 3) Save approval + trip code
  $stmt2 = $conn->prepare("
    UPDATE transport_services
    SET status = 'APPROVED',
        trip_code = ?,
        updated_at = NOW()
    WHERE id = ?
      AND status = 'PENDING'
      AND deleted_at IS NULL
  ");
  $stmt2->bind_param("si", $tripCode, $request_id);
  $stmt2->execute();

  if ($stmt2->affected_rows <= 0) {
    $stmt2->close();
    respond(false, "Approval failed (request may not be pending).");
  }

  $stmt2->close();
  respond(true, "Approved", ["request_id" => $request_id, "trip_code" => $tripCode]);

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error: " . $e->getMessage());
}