<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$leave_request_id = $_POST['leave_request_id'] ?? '';
$manager_id       = $_POST['manager_id'] ?? '';

if ($leave_request_id === '' || $manager_id === '') {
  echo json_encode(["success" => false, "message" => "leave_request_id and manager_id required"]);
  exit;
}

try {
  $conn->begin_transaction();

  // NEW — works with assigned manager_id OR reporting manager as fallback
  $q = "
    SELECT lr.leave_request_id, lr.employee_id, lr.leave_policy_id, lr.number_of_days,
          lr.paid_days, lr.status, lr.manager_id AS assigned_manager_id,
          ej.reporting_manager_id
    FROM leave_requests lr
    JOIN employee_job ej ON ej.employee_id = lr.employee_id
    WHERE lr.leave_request_id = ?
      AND (
        lr.manager_id = ?
        OR (lr.manager_id IS NULL AND ej.reporting_manager_id = ?)
      )
    FOR UPDATE
  ";
  $stmt = $conn->prepare($q);
  $stmt->bind_param("iss", $leave_request_id, $manager_id, $manager_id);
  $stmt->execute();
  $res = $stmt->get_result();

  if ($res->num_rows === 0) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Not allowed / not found"]);
    exit;
  }

  $row = $res->fetch_assoc();

  // Prevent double approval
  if (in_array($row["status"], ["APPROVED", "REJECTED", "CANCELED"])) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Already processed"]);
    exit;
  }

  $empId    = $row["employee_id"];
  $policyId = (int)$row["leave_policy_id"];
  $paidDays = (float)$row["paid_days"];  // only paid portion counts against balance

  // Half Day: map to Casual Leave, always deduct exactly 0.5
  if ($policyId === 4) {
    $policyId = 3;
    $paidDays = 0.5;
  }

  // Deduct from employee_leave_balances only for Annual/Medical/Casual (1,2,3)
  // and only the paid portion — no_pay_days are approved but NOT deducted from balance
  $mustDeductBalance = in_array($policyId, [1, 2, 3], true) && $paidDays > 0;

  if ($mustDeductBalance) {
    // 2) Lock leave balance row
    $q2 = "
      SELECT leave_balance_id, remaining
      FROM employee_leave_balances
      WHERE employee_id = ?
        AND leave_policy_id = ?
      FOR UPDATE
    ";
    $stmt2 = $conn->prepare($q2);
    $stmt2->bind_param("si", $empId, $policyId);
    $stmt2->execute();
    $res2 = $stmt2->get_result();

    // If no balance row exists yet → create it from yearly entitlement
    if ($res2->num_rows === 0) {
      $qy = "
        SELECT leave_entitlement
        FROM employee_yearly_leave_balance
        WHERE employee_id = ?
          AND leave_policy_id = ?
        LIMIT 1
      ";
      $sty = $conn->prepare($qy);
      $sty->bind_param("si", $empId, $policyId);
      $sty->execute();
      $ry = $sty->get_result();

      if ($ry->num_rows === 0) {
        $conn->rollback();
        echo json_encode([
          "success" => false,
          "message" => "Yearly entitlement not found for this employee/policy"
        ]);
        exit;
      }

      $y = $ry->fetch_assoc();
      $entitlement = (float)$y["leave_entitlement"];

      $qi = "
        INSERT INTO employee_leave_balances
          (employee_id, leave_policy_id, total_taken, remaining, updated_at)
        VALUES
          (?, ?, 0, ?, NOW())
      ";
      $si = $conn->prepare($qi);
      $si->bind_param("sid", $empId, $policyId, $entitlement);
      $si->execute();

      // Re-fetch with lock
      $stmt2->execute();
      $res2 = $stmt2->get_result();
    }

    $bal = $res2->fetch_assoc();
    $remaining = (float)$bal["remaining"];

    if ($remaining < $paidDays) {
      $conn->rollback();
      echo json_encode([
        "success" => false,
        "message" => "Not enough leave balance (remaining: $remaining, needed: $paidDays)"
      ]);
      exit;
    }

    // 3) Deduct only paid_days — no_pay_days are ignored here
    $q3 = "
      UPDATE employee_leave_balances
      SET total_taken = total_taken + ?,
          remaining  = remaining - ?,
          updated_at = NOW()
      WHERE employee_id = ?
        AND leave_policy_id = ?
    ";
    $stmt3 = $conn->prepare($q3);
    $stmt3->bind_param("ddsi", $paidDays, $paidDays, $empId, $policyId);
    $stmt3->execute();
  }

  // 4) Approve leave request
  $q4 = "
    UPDATE leave_requests
    SET status='APPROVED',
        manager_comment=NULL,
        updated_at=NOW()
    WHERE leave_request_id=?
  ";
  $stmt4 = $conn->prepare($q4);
  $stmt4->bind_param("i", $leave_request_id);
  $stmt4->execute();

  $conn->commit();

  // ---------- NOTIFY EMPLOYEE: LEAVE APPROVED (non-blocking) ----------
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
        "Leave Request Approved",
        "Your $leaveTypeName request ($from to $to) has been approved.",
        ["type" => "leave_approved", "leaveRequestId" => (string)$leave_request_id]
      );
    }
  } catch (Throwable $notifyError) {
    error_log("FCM notify (approve_leave) failed: " . $notifyError->getMessage());
  }

  echo json_encode(["success" => true, "message" => "Approved + Leave balance updated"]);

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["success" => false, "message" => $e->getMessage()]);
}