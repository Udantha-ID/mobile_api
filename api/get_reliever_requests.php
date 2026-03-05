<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

register_shutdown_function(function () {
  $err = error_get_last();
  if ($err && in_array($err["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    http_response_code(500);
    echo json_encode([
      "success" => false,
      "message" => "FATAL: " . $err["message"],
      "file" => basename($err["file"]),
      "line" => $err["line"],
    ]);
  }
});

set_error_handler(function ($severity, $message, $file, $line) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => "PHP: $message",
    "file" => basename($file),
    "line" => $line,
  ]);
  exit;
});

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
  }

  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);

  if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON body"]);
    exit;
  }

  $employeeId = trim($data["employeeId"] ?? "");
  if ($employeeId === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "employeeId required"]);
    exit;
  }

  $sql = "
    SELECT
      lr.leave_request_id,
      lr.employee_id,
      lr.leave_policy_id,
      p.name AS leave_type_name,
      lr.leave_start_date,
      lr.leave_end_date,
      lr.number_of_days,
      lr.reason,
      lr.address,
      lr.status,
      lr.manager_comment,
      lr.requested_at,

      ej.job_title_id,
      jt.name AS job_title_name,

      e.employee_code,
      e.preferred_name,
      e.full_name

    FROM leave_requests lr
    JOIN employees e ON e.employee_id = lr.employee_id
    LEFT JOIN employee_job ej ON ej.employee_id = e.employee_id
    LEFT JOIN job_titles jt ON jt.job_title_id = ej.job_title_id
    JOIN leave_policies p ON p.leave_policy_id = lr.leave_policy_id

    WHERE lr.oversee_member_id = ?
      AND lr.status = 'PENDING'

    ORDER BY lr.requested_at DESC
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $employeeId);
  $stmt->execute();
  $res = $stmt->get_result();

  $requests = [];

  while ($row = $res->fetch_assoc()) {
    // your new name fields
    $name = trim((string)($row["preferred_name"] ?? ""));
    if ($name === "") $name = trim((string)($row["full_name"] ?? ""));

    $requests[] = [
      "leaveRequestId" => (int)$row["leave_request_id"],
      "employeeId" => $row["employee_id"],
      "role" => $row["job_title_id"] ?? "",
      "job_title_name" => $row["job_title_name"] ?? "",
      "name" => $name,
      "empNo" => $row["employee_code"] ?? "",
      "leaveType" => $row["leave_type_name"] ?? "",
      "from" => $row["leave_start_date"],
      "to" => $row["leave_end_date"],
      "days" => (string)$row["number_of_days"],
      "reason" => $row["reason"] ?? "",
      "address" => $row["address"] ?? "",
      "applyOn" => $row["requested_at"],

      // UI label for Flutter
      "status" => "Awaiting Your Response",

      "managerComment" => $row["manager_comment"] ?? ""
    ];
  }

  echo json_encode([
    "success" => true,
    "message" => "Reliever requests loaded",
    "requests" => $requests
  ]);

  $stmt->close();
  $conn->close();
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => "EXCEPTION: " . $e->getMessage(),
    "file" => basename($e->getFile()),
    "line" => $e->getLine(),
  ]);
}