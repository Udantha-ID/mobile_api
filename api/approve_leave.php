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

  // 1) Get leave request + security check
  $q = "
    SELECT
      lr.leave_request_id,
      lr.employee_id,
      lr.leave_policy_id,
      lr.number_of_days,
      lr.status
    FROM leave_requests lr
    JOIN employee_job ej ON ej.employee_id = lr.employee_id
    WHERE lr.leave_request_id = ?
      AND ej.reporting_manager_id = ?
    FOR UPDATE
  ";
  $stmt = $conn->prepare($q);
  $stmt->bind_param("is", $leave_request_id, $manager_id);
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
  $days     = (float)$row["number_of_days"];

  // Half Day handling
  if ($policyId === 4) {
    $policyId = 3;   // map to Casual Leave
    $days = 0.5;     // deduct 0.5
  }

  if ($days <= 0) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Invalid number_of_days"]);
    exit;
  }

  // 2) Lock leave balance
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

  // If no balance row → create it
  if ($res2->num_rows === 0) {

    // Get yearly entitlement
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

    // Insert balance row
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

  if ($remaining < $days) {
    $conn->rollback();
    echo json_encode([
      "success" => false,
      "message" => "Not enough leave balance (remaining: $remaining, needed: $days)"
    ]);
    exit;
  }

  // 3) Update leave balance
  $q3 = "
    UPDATE employee_leave_balances
    SET total_taken = total_taken + ?,
        remaining  = remaining - ?,
        updated_at = NOW()
    WHERE employee_id = ?
      AND leave_policy_id = ?
  ";
  $stmt3 = $conn->prepare($q3);
  $stmt3->bind_param("ddsi", $days, $days, $empId, $policyId);
  $stmt3->execute();

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
  echo json_encode(["success" => true, "message" => "Approved + Leave balance updated"]);

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["success" => false, "message" => $e->getMessage()]);
}