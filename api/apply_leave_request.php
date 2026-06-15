<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

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

  // Balance check only for Annual/Medical/Casual (policy 1,2,3)
  $mustCheckBalance = in_array($leavePolicyId, [1, 2, 3], true);

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
    case 1:
      $leaveTypeName = "Annual Leave";
      break;
    case 2:
      $leaveTypeName = "Medical Leave";
      break;
    case 3:
      $leaveTypeName = "Casual Leave";
      break;
  }

  $remaining = null;

  if ($mustCheckBalance) {

    // 1) Try current remaining from employee_leave_balances
    $sqlBal = "
      SELECT remaining
      FROM employee_leave_balances
      WHERE employee_id = ? AND leave_policy_id = ?
      LIMIT 1
    ";
    $st = $conn->prepare($sqlBal);
    $st->bind_param("ii", $employeeId, $leavePolicyId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row) {
      $remaining = (float)$row["remaining"];
    } else {
      // 2) Fallback: use yearly entitlement for new employees
      $sqlEnt = "
        SELECT leave_entitlement
        FROM employee_yearly_leave_balance
        WHERE employee_id = ? AND leave_policy_id = ?
        LIMIT 1
      ";
      $st = $conn->prepare($sqlEnt);
      $st->bind_param("ii", $employeeId, $leavePolicyId);
      $st->execute();
      $rowEnt = $st->get_result()->fetch_assoc();
      $st->close();

      $remaining = $rowEnt ? (float)$rowEnt["leave_entitlement"] : 0.0;
    }

    // 3) Validate
    if ($remaining <= 0) {
      respond(false, "You don't have available $leaveTypeName Balance");
    }

    if ($days > $remaining) {
      respond(false, "Not enough $leaveTypeName balance. Remaining: {$remaining} day(s).");
    }
  }

  // Reliever can be NULL
  $overseeMemberIdDb = ($overseeMemberId === "") ? null : $overseeMemberId;

  // ---------- 3) INSERT LEAVE REQUEST ----------
  $sqlIns = "
    INSERT INTO leave_requests
      (employee_id, leave_policy_id, leave_start_date, leave_end_date, number_of_days,
       half_day_session,
       reason, oversee_member_id, is_special_request, address, status, requested_at, updated_at)
    VALUES
      (?, ?, ?, ?, ?,
       ?,
       ?, ?, ?, ?, 'PENDING', NOW(), NOW())
  ";

  $stmt = $conn->prepare($sqlIns);

  $stmt->bind_param(
    "iissdsssis",
    $employeeId,        // i
    $leavePolicyId,     // i
    $startDate,         // s
    $endDate,           // s
    $days,              // d
    $halfDaySession,    // s (can be NULL)
    $reason,            // s
    $overseeMemberIdDb, // s (can be NULL)
    $isSpecial,         // i
    $address            // s
  );

  $stmt->execute();
  $leaveRequestId = (int)$conn->insert_id;
  $stmt->close();

    // ---------- 4) PUSH NOTIFICATION TO RELIEVER (non-blocking) ----------
  if ($overseeMemberIdDb !== null) {
    try {
      // Get reliever's FCM token
      $st = $conn->prepare("SELECT fcm_token FROM employees WHERE employee_id = ? LIMIT 1");
      $st->bind_param("s", $overseeMemberIdDb);
      $st->execute();
      $tokenRow = $st->get_result()->fetch_assoc();
      $st->close();

      $fcmToken = $tokenRow["fcm_token"] ?? null;

      if (!empty($fcmToken)) {
        // Get applicant's display name
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
          [
            "type" => "reliever_request",
            "leaveRequestId" => $leaveRequestId,
          ]
        );
      }
    } catch (Throwable $notifyError) {
      // Never let a notification failure break the leave request itself
      error_log("FCM send failed: " . $notifyError->getMessage());
    }
  }

  // Done
  respond(true, "Leave request submitted", [
    "leave_request_id" => $leaveRequestId,
    "remaining" => $remaining,
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "EXCEPTION: " . $e->getMessage());
}