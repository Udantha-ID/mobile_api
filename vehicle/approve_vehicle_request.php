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

function makeTripCode($employeeName) {
  $clean = strtoupper(preg_replace('/\s+/', '', (string)$employeeName));
  if (strlen($clean) < 4) $clean = str_pad($clean, 4, "X");
  $namePart = substr($clean, 0, 4);
  $numPart = strval(random_int(1000, 9999));
  return "#" . $namePart . $numPart;
}

// Only POST
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  http_response_code(405);
  respond(false, "Method not allowed");
}

// Get JSON
$raw = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) respond(false, "Invalid JSON");

$request_id = (int)($body["request_id"] ?? 0);
if ($request_id <= 0) respond(false, "request_id required");

try {

  // 1. Load request (ONLY PENDING)
  $stmt = $conn->prepare("
    SELECT
      ts.id,
      ts.status,
      ts.type,
      ts.employee_id,
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

  // 2. Prepare name
  $employeeName = trim($row["preferred_name"] ?: $row["full_name"]);
  if ($employeeName === "") $employeeName = "USER";

  // 3. Generate trip code
  $tripCode = makeTripCode($employeeName);

  // 4. Approve request
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

  // ============================================================
  // 5. UPDATE PERSONAL VEHICLE USAGE (ONLY type = personal)
  // ============================================================
  if ($row["type"] === "personal") {

    $empId = (int)$row["employee_id"];

    //Check existing record
    $check = $conn->prepare("
      SELECT id, usage_count
      FROM employee_personal_vehicle_usage
      WHERE employee_id = ?
      LIMIT 1
    ");
    $check->bind_param("i", $empId);
    $check->execute();
    $resCheck = $check->get_result();
    $existing = $resCheck->fetch_assoc();
    $check->close();

    if ($existing) {
      //Update usage_count
      $newCount = (int)$existing["usage_count"] + 1;

      $upd = $conn->prepare("
        UPDATE employee_personal_vehicle_usage
        SET usage_count = ?, updated_at = NOW()
        WHERE employee_id = ?
      ");
      $upd->bind_param("ii", $newCount, $empId);
      $upd->execute();
      $upd->close();

    } else {
      //Insert new record
      $defaultCharge = 0; // you can change later

      $ins = $conn->prepare("
        INSERT INTO employee_personal_vehicle_usage
          (employee_id, usage_count, charge_per_request, created_at, updated_at)
        VALUES
          (?, 1, ?, NOW(), NOW())
      ");
      $ins->bind_param("id", $empId, $defaultCharge);
      $ins->execute();
      $ins->close();
    }
  }

  //6. Response
  respond(true, "Approved", [
    "request_id" => $request_id,
    "trip_code" => $tripCode
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error: " . $e->getMessage());
}