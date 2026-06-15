<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
  }

  $data = json_decode(file_get_contents("php://input"), true);

  $leaveRequestId = (int)($data["leaveRequestId"] ?? 0);
  $relieverId     = trim($data["relieverId"] ?? "");
  $comment        = trim($data["comment"] ?? "");

  if ($leaveRequestId <= 0 || $relieverId === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "leaveRequestId and relieverId required"]);
    exit;
  }

  // Make sure this request belongs to this reliever and is still pending
  $chk = $conn->prepare("
    SELECT leave_request_id, employee_id
    FROM leave_requests
    WHERE leave_request_id = ?
      AND oversee_member_id = ?
      AND status = 'PENDING'
    LIMIT 1
  ");
  $chk->bind_param("is", $leaveRequestId, $relieverId);
  $chk->execute();
  $found = $chk->get_result()->fetch_assoc();
  $chk->close();

  if (!$found) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Not allowed or request not pending"]);
    exit;
  }

  $applicantEmployeeId = (int)$found["employee_id"];

  // Update status + save reliever comment
  $stmt = $conn->prepare("
    UPDATE leave_requests
    SET status = 'RELIEVER ACCEPTED',
        reliever_comment = ?,
        updated_at = NOW()
    WHERE leave_request_id = ?
  ");
  $stmt->bind_param("si", $comment, $leaveRequestId);
  $stmt->execute();
  $stmt->close();

  // ---------- NOTIFY APPLICANT + REPORTING MANAGER (non-blocking) ----------
  try {
    require_once __DIR__ . "/../notifications/fcm_helper.php";

    // Reliever's display name
    $st = $conn->prepare("SELECT preferred_name, full_name FROM employees WHERE employee_id = ? LIMIT 1");
    $st->bind_param("s", $relieverId);
    $st->execute();
    $relieverRow = $st->get_result()->fetch_assoc();
    $st->close();

    $relieverName = trim((string)($relieverRow["preferred_name"] ?? ""));
    if ($relieverName === "") $relieverName = trim((string)($relieverRow["full_name"] ?? ""));
    if ($relieverName === "") $relieverName = "Your reliever";

    // Applicant's name + token + reporting manager id
    $st = $conn->prepare("
      SELECT e.preferred_name, e.full_name, e.fcm_token AS applicant_token,
             ej.reporting_manager_id
      FROM employees e
      LEFT JOIN employee_job ej ON ej.employee_id = e.employee_id
      WHERE e.employee_id = ?
      LIMIT 1
    ");
    $st->bind_param("i", $applicantEmployeeId);
    $st->execute();
    $applicantRow = $st->get_result()->fetch_assoc();
    $st->close();

    $applicantName = trim((string)($applicantRow["preferred_name"] ?? ""));
    if ($applicantName === "") $applicantName = trim((string)($applicantRow["full_name"] ?? ""));
    if ($applicantName === "") $applicantName = "An employee";

    // 1) Tell the applicant their reliever accepted
    if (!empty($applicantRow["applicant_token"])) {
      FcmHelper::send(
        $applicantRow["applicant_token"],
        "Reliever Accepted",
        "$relieverName has accepted your reliever request. Your leave has been forwarded to your manager.",
        ["type" => "reliever_accepted", "leaveRequestId" => $leaveRequestId]
      );
    }

    // 2) Notify the reporting manager that a request is ready for approval
    $managerId = $applicantRow["reporting_manager_id"] ?? null;
    if (!empty($managerId)) {
      $st = $conn->prepare("SELECT fcm_token FROM employees WHERE employee_id = ? LIMIT 1");
      $st->bind_param("i", $managerId);
      $st->execute();
      $mgrRow = $st->get_result()->fetch_assoc();
      $st->close();

      if (!empty($mgrRow["fcm_token"])) {
        FcmHelper::send(
          $mgrRow["fcm_token"],
          "Leave Request Awaiting Approval",
          "$applicantName's leave request has been accepted by the reliever and is awaiting your approval.",
          ["type" => "manager_leave_approval", "leaveRequestId" => $leaveRequestId]
        );
      }
    }
  } catch (Throwable $notifyError) {
    error_log("FCM notify (reliever_accept) failed: " . $notifyError->getMessage());
  }

  echo json_encode(["success" => true, "message" => "Accepted and forwarded to manager"]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "EXCEPTION: " . $e->getMessage()]);
}