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
$stmt2->close();

// ---------- NOTIFY EMPLOYEE: LEAVE REJECTED (non-blocking) ----------
try {
  require_once __DIR__ . "/../notifications/fcm_helper.php";

  $stN = $conn->prepare("
    SELECT lr.leave_start_date, lr.leave_end_date, lp.name AS leave_type_name,
           e.fcm_token
    FROM leave_requests lr
    JOIN leave_policies lp ON lp.leave_policy_id = lr.leave_policy_id
    JOIN employees e ON e.employee_id = lr.employee_id
    WHERE lr.leave_request_id = ?
    LIMIT 1
  ");
  $stN->bind_param("i", $leave_request_id);
  $stN->execute();
  $notifyRow = $stN->get_result()->fetch_assoc();
  $stN->close();

  if (!empty($notifyRow["fcm_token"])) {
    $leaveTypeName = $notifyRow["leave_type_name"] ?? "Leave";
    $from = $notifyRow["leave_start_date"];
    $to   = $notifyRow["leave_end_date"];

    FcmHelper::send(
      $notifyRow["fcm_token"],
      "Leave Request Rejected",
      "Your $leaveTypeName request ($from to $to) was rejected.",
      ["type" => "leave_rejected", "leaveRequestId" => (string)$leave_request_id]
    );
  }
} catch (Throwable $notifyError) {
  error_log("FCM notify (reject_leave) failed: " . $notifyError->getMessage());
}

echo json_encode(["success" => true, "message" => "Rejected"]);