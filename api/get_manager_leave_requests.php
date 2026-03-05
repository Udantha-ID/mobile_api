<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

$manager_id = $_GET['manager_id'] ?? '';
if ($manager_id === '') {
  echo json_encode(["success" => false, "message" => "manager_id is required"]);
  exit;
}

try {
$sql = "
  SELECT
    lr.leave_request_id,
    lr.employee_id,
    lr.leave_policy_id,
    lr.leave_start_date,
    lr.leave_end_date,
    lr.number_of_days,
    lr.half_day_session,
    lr.reason,
    lr.oversee_member_id,
    lr.is_special_request,
    lr.address,
    lr.status,
    lr.manager_comment,
    lr.requested_at,
    lr.updated_at,
    lr.reliever_comment,

    p.name AS leave_policy_name,

    lrd.file_name AS attachment_name,
    lrd.file_path AS attachment_path,

    -- employee code + name (NEW STRUCTURE)
    e.employee_code,
    COALESCE(NULLIF(TRIM(e.preferred_name), ''), TRIM(e.full_name)) AS employee_name,

    -- job title
    jt.name AS job_title_name,

    -- reliever (oversee member) name (NEW STRUCTURE)
    COALESCE(
      NULLIF(TRIM(ov.preferred_name), ''),
      TRIM(ov.full_name)
    ) AS oversee_name

  FROM leave_requests lr

  JOIN employee_job ej
    ON ej.employee_id = lr.employee_id

  JOIN employees e
    ON e.employee_id = lr.employee_id

  LEFT JOIN leave_policies p
    ON p.leave_policy_id = lr.leave_policy_id

  LEFT JOIN job_titles jt
    ON jt.job_title_id = ej.job_title_id

  LEFT JOIN leave_request_documents lrd
    ON lrd.leave_request_id = lr.leave_request_id

  LEFT JOIN employees ov
    ON ov.employee_id = lr.oversee_member_id

  WHERE ej.reporting_manager_id = ?
    AND (
      lr.status = 'RELIEVER ACCEPTED'
      OR lr.is_special_request = 1
    )
    AND lr.status NOT IN ('APPROVED', 'REJECTED', 'CANCELED')

  ORDER BY lr.requested_at DESC
";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $manager_id);
  $stmt->execute();
  $res = $stmt->get_result();

  $rows = [];
  while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
  }

  echo json_encode(["success" => true, "data" => $rows]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
