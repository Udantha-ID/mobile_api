<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";
require_once __DIR__ . "/../assets/includes/leave_balance_helper.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Fix: Set Sri Lanka timezone for this DB session
$conn->query("SET time_zone = '+05:30'");

function respond($ok, $msg, $extra = []) {
  echo json_encode(array_merge(["success" => $ok, "message" => $msg], $extra));
  exit;
}

try {
  // Only POST
  if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    respond(false, "Method not allowed");
  }

  // Read JSON
  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);

  if (!is_array($data)) {
    http_response_code(400);
    respond(false, "Invalid JSON");
  }

  // Inputs
  $employeeId      = (int)($data["employeeId"] ?? 0);
  $leavePolicyId   = (int)($data["leavePolicyId"] ?? 0);
  $startDate       = trim((string)($data["startDate"] ?? ""));
  $endDate         = trim((string)($data["endDate"] ?? ""));
  $days            = (float)($data["numberOfDays"] ?? 0);
  $reason          = trim((string)($data["reason"] ?? ""));
  $overseeMemberId = trim((string)($data["overseeMemberId"] ?? ""));
  $isSpecial       = (int)($data["isSpecialRequest"] ?? 0);
  $address         = trim((string)($data["address"] ?? ""));
  $halfDaySession  = strtoupper(trim((string)($data["halfDaySession"] ?? ""))); // MORNING/EVENING or ""
  $managerId = (int)($data["managerId"] ?? 0);
  $acknowledgeNoPay = (int)($data["acknowledgeNoPay"] ?? 0);

  // Required
  if ($employeeId <= 0 || $leavePolicyId <= 0 || $startDate === "" || $reason === "") {
    http_response_code(400);
    respond(false, "Missing required fields");
  }

  // Half day rules
  $isHalfDay = ($halfDaySession !== "");
  if ($isHalfDay) {
    if (!in_array($halfDaySession, ["MORNING", "EVENING"], true)) {
      http_response_code(400);
      respond(false, "Invalid half day session");
    }
    // force same day
    $endDate = $startDate;
    $days = ($days > 0 ? $days : 1.0);
  } else {
    // normal leave must have endDate and days
    if ($endDate === "" || $days <= 0) {
      http_response_code(400);
      respond(false, "Missing endDate/numberOfDays");
    }
    $halfDaySession = null; // store NULL in DB
  }

  // Balance check for Annual/Medical/Casual (1,2,3) and Half Day (4 → Casual Leave 3)
  $mustCheckBalance = in_array($leavePolicyId, [1, 2, 3, 4], true);

  // ---------- 1) OVERLAP CHECK (block only PENDING / APPROVED) ----------
  $sqlOverlap = "
    SELECT COUNT(*) AS cnt
    FROM leave_requests
    WHERE employee_id = ?
      AND status IN ('PENDING','APPROVED','RELIEVER_ACCEPTED')
      AND (leave_start_date <= ? AND leave_end_date >= ?)
  ";
  $st = $conn->prepare($sqlOverlap);
  $st->bind_param("iss", $employeeId, $endDate, $startDate);
  $st->execute();
  $cnt = (int)$st->get_result()->fetch_assoc()["cnt"];
  $st->close();

  if ($cnt > 0) {
    respond(false, "You already have a leave for these dates. Please select another date range.");
  }

  // ---------- 2) BALANCE CHECK (only for 1,2,3) ----------
  $leaveTypeName = "Leave";

  switch ($leavePolicyId) {
    case 1: $leaveTypeName = "Annual Leave"; break;
    case 2: $leaveTypeName = "Medical Leave"; break;
    case 3: $leaveTypeName = "Casual Leave"; break;
  }

  $paidDays  = $days;
  $noPayDays = 0.0;
  $remaining = null;

  if ($mustCheckBalance) {
      // Half Day (4) borrows from Casual Leave (3) balance — always 0.5 days
      $checkPolicyId = ($leavePolicyId === 4) ? 3 : $leavePolicyId;
      $checkDays     = ($leavePolicyId === 4) ? 0.5 : $days;
      $balanceName   = ($leavePolicyId === 4) ? "Casual Leave" : $leaveTypeName;

      $bal = getLeaveRemaining($conn, $employeeId, $checkPolicyId);
      $remaining = $bal["remaining"];

      [$paidDays, $noPayDays] = splitPaidNoPay($checkDays, $remaining);

      if ($noPayDays > 0 && $acknowledgeNoPay !== 1) {
          respond(false,
              "You have {$remaining} day(s) of $balanceName remaining. " .
              "{$paidDays} day(s) will be paid and {$noPayDays} day(s) will be " .
              "submitted as No Pay Leave. Please confirm to proceed.",
              [
                  "requires_no_pay_confirmation" => true,
                  "remaining"   => $remaining,
                  "paid_days"   => $paidDays,
                  "no_pay_days" => $noPayDays,
              ]
          );
      }
  }

  // Reliever can be NULL
  $overseeMemberIdDb = ($overseeMemberId === "") ? null : $overseeMemberId;

  // ---------- 3) INSERT LEAVE REQUEST ----------
  $sqlIns = "
    INSERT INTO leave_requests
      (employee_id, leave_policy_id, leave_start_date, leave_end_date, number_of_days,
      paid_days, no_pay_days,
      half_day_session, reason, oversee_member_id, manager_id,
      is_special_request, address, status, requested_at, updated_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?,
      ?, ?, ?, ?,
      ?, ?, 'PENDING', NOW(), NOW())
  ";

  $stmt = $conn->prepare($sqlIns);
  $stmt->bind_param(
      "iissdddsssiis",  // ← 13 chars
      $employeeId,      // i
      $leavePolicyId,   // i
      $startDate,       // s
      $endDate,         // s
      $days,            // d
      $paidDays,        // d
      $noPayDays,       // d
      $halfDaySession,  // s
      $reason,          // s
      $overseeMemberIdDb, // s
      $managerId,       // i
      $isSpecial,       // i
      $address          // s
  );
  $stmt->execute();
  $leaveRequestId = (int)$conn->insert_id;
  $stmt->close();

  // After INSERT succeeds...

  // Notify reliever (existing)
  if ($overseeMemberIdDb !== null) {
    try {
      $st = $conn->prepare("SELECT fcm_token FROM employees WHERE employee_id = ? LIMIT 1");
      $st->bind_param("s", $overseeMemberIdDb);
      $st->execute();
      $tokenRow = $st->get_result()->fetch_assoc();
      $st->close();
      $fcmToken = $tokenRow["fcm_token"] ?? null;

      if (!empty($fcmToken)) {
        $st2 = $conn->prepare("SELECT preferred_name, full_name FROM employees WHERE employee_id = ? LIMIT 1");
        $st2->bind_param("i", $employeeId);
        $st2->execute();
        $nameRow = $st2->get_result()->fetch_assoc();
        $st2->close();

        $applicantName = trim((string)($nameRow["preferred_name"] ?? ""));
        if ($applicantName === "") $applicantName = trim((string)($nameRow["full_name"] ?? ""));
        if ($applicantName === "") $applicantName = "An employee";

        require_once __DIR__ . "/../notifications/fcm_helper.php";

        FcmHelper::send(
          $fcmToken,
          "New Reliever Request",
          "$applicantName has requested you as a reliever for $leaveTypeName ($startDate to $endDate).",
          ["type" => "reliever_request", "leaveRequestId" => $leaveRequestId]
        );
      }
    } catch (Throwable $n) {
      error_log("FCM reliever notify failed: " . $n->getMessage());
    }
  }

  // Notify the assigned manager (NEW)
  if ($managerId > 0) {
    try {
      require_once __DIR__ . "/../notifications/fcm_helper.php";

      // Get applicant name if not already fetched
      if (!isset($applicantName)) {
        $st = $conn->prepare("SELECT preferred_name, full_name FROM employees WHERE employee_id = ? LIMIT 1");
        $st->bind_param("i", $employeeId);
        $st->execute();
        $nameRow = $st->get_result()->fetch_assoc();
        $st->close();
        $applicantName = trim((string)($nameRow["preferred_name"] ?? ""));
        if ($applicantName === "") $applicantName = trim((string)($nameRow["full_name"] ?? ""));
        if ($applicantName === "") $applicantName = "An employee";
      }

      $stMgr = $conn->prepare("SELECT fcm_token FROM employees WHERE employee_id = ? LIMIT 1");
      $stMgr->bind_param("i", $managerId);
      $stMgr->execute();
      $mgrToken = $stMgr->get_result()->fetch_assoc()["fcm_token"] ?? null;
      $stMgr->close();

      if (!empty($mgrToken)) {
        FcmHelper::send(
          $mgrToken,
          "New Leave Request",
          "$applicantName has submitted a $leaveTypeName request ($startDate to $endDate).",
          ["type" => "manager_leave_approval", "leaveRequestId" => $leaveRequestId]
        );
      }
    } catch (Throwable $n) {
      error_log("FCM manager notify failed: " . $n->getMessage());
    }
  }

  // Done
  respond(true, "Leave request submitted", [
    "leave_request_id" => $leaveRequestId,
    "remaining"  => $remaining,
    "paid_days"  => $paidDays,
    "no_pay_days" => $noPayDays,
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "EXCEPTION: " . $e->getMessage());
}