<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

function respond($success, $message, $data = null) {
  echo json_encode([
    "success" => $success,
    "message" => $message,
    "data" => $data
  ]);
  exit;
}

$employee_id = (int)($_GET["employee_id"] ?? 0);
if ($employee_id <= 0) {
  respond(false, "employee_id required");
}

try {

    $stmt = $conn->prepare("
    SELECT usage_count
    FROM employee_personal_vehicle_usage
    WHERE employee_id = ?
    LIMIT 1
    ");

  $stmt->bind_param("i", $employee_id);
  $stmt->execute();

  $res = $stmt->get_result()->fetch_assoc();
  $count = (int)($res["usage_count"] ?? 0);

  $stmt->close();

  respond(true, "OK", [
    "count" => $count
  ]);

} catch (Throwable $e) {
  respond(false, "Server error: " . $e->getMessage());
}