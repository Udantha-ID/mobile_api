<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

$leave_request_id = $_POST['leave_request_id'] ?? '';
$manager_id = $_POST['manager_id'] ?? '';
$comment = $_POST['comment'] ?? '';

if ($leave_request_id === '' || $manager_id === '' || trim($comment) === '') {
  echo json_encode(["success" => false, "message" => "leave_request_id, manager_id, comment required"]);
  exit;
}

$check = "
  SELECT lr.leave_request_id
  FROM leave_requests lr
  JOIN employee_job ej ON ej.employee_id = lr.employee_id
  WHERE lr.leave_request_id = ?
    AND ej.reporting_manager_id = ?
  LIMIT 1
";
$stmt = $conn->prepare($check);
$stmt->bind_param("is", $leave_request_id, $manager_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
  echo json_encode(["success" => false, "message" => "Not allowed / not found"]);
  exit;
}

$sql = "UPDATE leave_requests SET status='REJECTED', manager_comment=?, updated_at=NOW() WHERE leave_request_id=?";
$stmt2 = $conn->prepare($sql);
$stmt2->bind_param("si", $comment, $leave_request_id);
$stmt2->execute();

echo json_encode(["success" => true, "message" => "Rejected"]);
