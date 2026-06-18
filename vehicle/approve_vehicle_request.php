<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
  echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
  exit;
}

function makeTripCode($employeeName) {
  $clean    = strtoupper(preg_replace('/\s+/', '', (string)$employeeName));
  if (strlen($clean) < 4) $clean = str_pad($clean, 4, "X");
  $namePart = substr($clean, 0, 4);
  $numPart  = strval(random_int(1000, 9999));
  return "#" . $namePart . $numPart;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  http_response_code(405);
  respond(false, "Method not allowed");
}

$raw  = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) respond(false, "Invalid JSON");

$request_id  = (int)($body["request_id"]  ?? 0);
$hod_comment = trim($body["hod_comment"]  ?? "");

if ($request_id <= 0) respond(false, "request_id required");

$fcmLoaded = false;
function loadFcm() {
  global $fcmLoaded;
  if (!$fcmLoaded) {
    require_once __DIR__ . "/../notifications/fcm_helper.php";
    $fcmLoaded = true;
  }
}

function getFcmToken($conn, int $employeeId): ?string {
  $st = $conn->prepare(
    "SELECT fcm_token FROM employees WHERE employee_id = ? LIMIT 1"
  );
  $st->bind_param("i", $employeeId);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  return !empty($row["fcm_token"]) ? $row["fcm_token"] : null;
}

// ── Check if employee is available (availability_status + no active leave today) ──
function isEmployeeAvailable($conn, int $empId): bool {
  $st = $conn->prepare(
    "SELECT availability_status FROM employees WHERE employee_id = ? LIMIT 1"
  );
  $st->bind_param("i", $empId);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$row) return false;
  if (strtolower(trim($row["availability_status"] ?? "available")) !== "available") return false;

  $today = date("Y-m-d");
  $st2 = $conn->prepare("
    SELECT COUNT(*) AS cnt FROM leave_requests
    WHERE employee_id = ?
      AND status IN ('APPROVED','RELIEVER_ACCEPTED')
      AND leave_start_date <= ? AND leave_end_date >= ?
  ");
  $st2->bind_param("iss", $empId, $today, $today);
  $st2->execute();
  $cnt = (int)$st2->get_result()->fetch_assoc()["cnt"];
  $st2->close();

  return $cnt === 0;
}

// ── Get the senior approver IDs from job titles (GM + MD) ────────────────
function getSeniorApproverIds($conn): array {
  $st = $conn->prepare("
    SELECT ej.employee_id FROM employee_job ej
    JOIN job_titles jt ON jt.job_title_id = ej.job_title_id
    WHERE jt.name IN ('Group General Manager', 'Managing Director')
  ");
  $st->execute();
  $res = $st->get_result();
  $ids = [];
  while ($r = $res->fetch_assoc()) {
    $ids[] = (int)$r["employee_id"];
  }
  $st->close();
  return $ids;
}

// ── Find the best available final approver (GM first, MD as fallback) ────
// Returns ["employee_id" => int, "fcm_token" => ?string]
function getFinalApprover($conn): ?array {
  // Order: GM first, then MD
  $st = $conn->prepare("
    SELECT e.employee_id, e.fcm_token, jt.name AS title_name
    FROM employees e
    JOIN employee_job ej ON ej.employee_id = e.employee_id
    JOIN job_titles jt ON jt.job_title_id = ej.job_title_id
    WHERE jt.name IN ('Group General Manager', 'Managing Director')
    ORDER BY FIELD(jt.name, 'Group General Manager', 'Managing Director')
  ");
  $st->execute();
  $candidates = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();

  // Return first available candidate
  foreach ($candidates as $c) {
    if (isEmployeeAvailable($conn, (int)$c["employee_id"])) {
      return [
        "employee_id" => (int)$c["employee_id"],
        "fcm_token"   => $c["fcm_token"] ?? null,
      ];
    }
  }

  // Last resort: return first candidate regardless (someone must be able to approve)
  return !empty($candidates)
    ? ["employee_id" => (int)$candidates[0]["employee_id"], "fcm_token" => $candidates[0]["fcm_token"] ?? null]
    : null;
}

function recordUsage($conn, int $employeeId): void {
  $u = $conn->prepare("
    INSERT INTO employee_personal_vehicle_usage
        (employee_id, usage_count, charge_per_request, created_at, updated_at)
    VALUES (?, 1, 0.00, NOW(), NOW())
    ON DUPLICATE KEY UPDATE usage_count = usage_count + 1, updated_at = NOW()
  ");
  $u->bind_param("i", $employeeId);
  $u->execute();
  $u->close();
}

try {

  // ── 1. Load request ───────────────────────────────────────────────────
  $stmt = $conn->prepare("
    SELECT ts.id, ts.status, ts.type,
           ts.employee_id, ts.manager_id,
           ts.assigned_start_at, ts.assigned_end_at,
           e.full_name, e.preferred_name
    FROM transport_services ts
    JOIN employees e ON e.employee_id = ts.employee_id
    WHERE ts.id = ?
      AND ts.status IN ('PENDING','HOD_APPROVED')
      AND ts.deleted_at IS NULL
    LIMIT 1
  ");
  $stmt->bind_param("i", $request_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) respond(false, "Request not found or already processed");

  $employeeName = trim($row["preferred_name"] ?: $row["full_name"]);
  if ($employeeName === "") $employeeName = "USER";

  $empId     = (int)$row["employee_id"];
  $managerId = (int)$row["manager_id"];
  $fromDate  = date("Y-m-d", strtotime($row["assigned_start_at"]));
  $toDate    = date("Y-m-d", strtotime($row["assigned_end_at"]));

  // ── 2. Is the stored manager a senior approver (GM or MD)? ───────────
  // This covers BOTH cases:
  //   - Reporting manager is GM directly
  //   - Reporting manager was GM but unavailable, so MD was selected via fallback
  $seniorIds        = getSeniorApproverIds($conn);
  $isSeniorApprover = in_array($managerId, $seniorIds);

  // ============================================================
  // 3. PERSONAL FLOW
  // ============================================================
  if ($row["type"] === "personal") {

    // ── PENDING state ─────────────────────────────────────────
    if ($row["status"] === "PENDING") {

      // Senior approver (GM or MD) → direct one-step approval
      if ($isSeniorApprover) {
        $tripCode = makeTripCode($employeeName);

        $stmt2 = $conn->prepare("
          UPDATE transport_services
          SET status = 'APPROVED', trip_code = ?, updated_at = NOW()
          WHERE id = ? AND status = 'PENDING' AND deleted_at IS NULL
        ");
        $stmt2->bind_param("si", $tripCode, $request_id);
        $stmt2->execute();
        if ($stmt2->affected_rows <= 0) { $stmt2->close(); respond(false, "Direct approval failed"); }
        $stmt2->close();

        recordUsage($conn, $empId);

        try {
          loadFcm();
          $token = getFcmToken($conn, $empId);
          if ($token) {
            FcmHelper::send(
              $token,
              "Personal Vehicle Approved ✓",
              "Your personal vehicle request ($fromDate – $toDate) has been approved. Trip code: $tripCode.",
              ["type" => "personal_vehicle_approved", "tripId" => (string)$request_id, "tripCode" => $tripCode]
            );
          }
        } catch (Throwable $n) { error_log("FCM direct senior approve: " . $n->getMessage()); }

        respond(true, "Approved", ["trip_code" => $tripCode]);
      }

      // HOD or HR Manager → forward to best available final approver (GM or MD)
      $stmt2 = $conn->prepare("
        UPDATE transport_services
        SET status = 'HOD_APPROVED', hod_comment = ?, updated_at = NOW()
        WHERE id = ? AND status = 'PENDING' AND deleted_at IS NULL
      ");
      $stmt2->bind_param("si", $hod_comment, $request_id);
      $stmt2->execute();
      if ($stmt2->affected_rows <= 0) { $stmt2->close(); respond(false, "HOD approval failed"); }
      $stmt2->close();

      try {
        loadFcm();

        // Notify employee: forwarded
        $empToken = getFcmToken($conn, $empId);
        if ($empToken) {
          FcmHelper::send(
            $empToken,
            "Request Forwarded",
            "Your personal vehicle request ($fromDate – $toDate) has been forwarded for final approval.",
            ["type" => "personal_vehicle_forwarded", "tripId" => (string)$request_id]
          );
        }

        // Notify best available final approver (GM → MD fallback)
        $finalApprover = getFinalApprover($conn);
        if ($finalApprover && !empty($finalApprover["fcm_token"])) {
          FcmHelper::send(
            $finalApprover["fcm_token"],
            "Personal Vehicle Request",
            "$employeeName's personal vehicle request ($fromDate – $toDate) needs your approval.",
            ["type" => "personal_vehicle_gm_approval", "tripId" => (string)$request_id]
          );
        }
      } catch (Throwable $n) { error_log("FCM HOD forward: " . $n->getMessage()); }

      respond(true, "Request forwarded for final approval");
    }

    // ── HOD_APPROVED state → final approver (GM or MD) ───────
    else if ($row["status"] === "HOD_APPROVED") {
      $tripCode = makeTripCode($employeeName);

      $stmt2 = $conn->prepare("
        UPDATE transport_services
        SET status = 'APPROVED', trip_code = ?, updated_at = NOW()
        WHERE id = ? AND status = 'HOD_APPROVED' AND deleted_at IS NULL
      ");
      $stmt2->bind_param("si", $tripCode, $request_id);
      $stmt2->execute();
      if ($stmt2->affected_rows <= 0) { $stmt2->close(); respond(false, "Final approval failed"); }
      $stmt2->close();

      recordUsage($conn, $empId);

      try {
        loadFcm();
        $token = getFcmToken($conn, $empId);
        if ($token) {
          FcmHelper::send(
            $token,
            "Personal Vehicle Approved ✓",
            "Your personal vehicle request ($fromDate – $toDate) has been fully approved. Trip code: $tripCode.",
            ["type" => "personal_vehicle_approved", "tripId" => (string)$request_id, "tripCode" => $tripCode]
          );
        }
      } catch (Throwable $n) { error_log("FCM GM final approve: " . $n->getMessage()); }

      respond(true, "Request fully approved", ["trip_code" => $tripCode]);
    }

    else { respond(false, "Invalid status"); }
  }

  // ============================================================
  // 4. NON-PERSONAL FLOW (office / transfer)
  // ============================================================
  else {
    if ($row["status"] !== "PENDING") respond(false, "Only PENDING requests can be approved");

    $tripCode = makeTripCode($employeeName);

    $stmt2 = $conn->prepare("
      UPDATE transport_services
      SET status = 'APPROVED', trip_code = ?, updated_at = NOW()
      WHERE id = ? AND status = 'PENDING' AND deleted_at IS NULL
    ");
    $stmt2->bind_param("si", $tripCode, $request_id);
    $stmt2->execute();
    if ($stmt2->affected_rows <= 0) { $stmt2->close(); respond(false, "Approval failed"); }
    $stmt2->close();

    try {
      loadFcm();
      $token = getFcmToken($conn, $empId);
      if ($token) {
        FcmHelper::send(
          $token,
          "Vehicle Request Approved ✓",
          "Your vehicle request ($fromDate – $toDate) has been approved. Trip code: $tripCode.",
          ["type" => "vehicle_approved", "tripId" => (string)$request_id, "tripCode" => $tripCode]
        );
      }
    } catch (Throwable $n) { error_log("FCM office approve: " . $n->getMessage()); }

    respond(true, "Approved", ["trip_code" => $tripCode]);
  }

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error: " . $e->getMessage());
}