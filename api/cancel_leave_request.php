<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    ob_clean();
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
  }

  $data = json_decode(file_get_contents("php://input"), true);
  if (!is_array($data)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(["success" => false, "message" => "Invalid JSON"]);
    exit;
  }

  $employeeId = trim($data["employeeId"] ?? "");
  $leaveRequestId = (int)($data["leaveRequestId"] ?? 0);

  if ($employeeId === "" || $leaveRequestId <= 0) {
    http_response_code(400);
    ob_clean();
    echo json_encode(["success" => false, "message" => "employeeId and leaveRequestId required"]);
    exit;
  }

  // Only delete if it's PENDING and belongs to employee
  $sql = "
    DELETE FROM leave_requests
    WHERE leave_request_id = ?
      AND employee_id = ?
      AND status IN ('PENDING', 'RELIEVER DECLINED', 'RELIEVER ACCEPTED')
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("is", $leaveRequestId, $employeeId);
  $stmt->execute();

  if ($stmt->affected_rows === 0) {
    http_response_code(400);
    ob_clean();
    echo json_encode([
      "success" => false,
      "message" => "Cannot delete (not pending / not yours / already approved)"
    ]);
    exit;
  }

  ob_clean();
  echo json_encode(["success" => true, "message" => "Request deleted"]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  ob_clean();
  echo json_encode(["success" => false, "message" => $e->getMessage()]);
  exit;
}
