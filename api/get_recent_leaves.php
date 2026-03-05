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
  $employeeId = trim($data["employeeId"] ?? "");

  if ($employeeId === "") {
    http_response_code(400);
    ob_clean();
    echo json_encode(["success" => false, "message" => "employeeId required"]);
    exit;
  }

    $sql = "
    SELECT
        lr.leave_request_id,
        lp.name AS leave_type,
        lr.reason,
        lr.leave_start_date,
        lr.leave_end_date,
        lr.number_of_days,
        lr.status,
        lr.manager_comment,
        lr.reliever_comment,
        lr.requested_at
    FROM leave_requests lr
    JOIN leave_policies lp ON lp.leave_policy_id = lr.leave_policy_id
    WHERE lr.employee_id = ?
    ORDER BY lr.requested_at DESC
    LIMIT 5
    ";


  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $employeeId);
  $stmt->execute();
  $res = $stmt->get_result();

  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
  }

  ob_clean();
  echo json_encode(["success" => true, "data" => $rows]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  ob_clean();
  echo json_encode(["success" => false, "message" => $e->getMessage()]);
  exit;
}
