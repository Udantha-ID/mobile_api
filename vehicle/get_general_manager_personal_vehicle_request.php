<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = []) {
  echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
  exit;
}

$userId = (int)($_GET["user_id"] ?? 0);
if ($userId <= 0) respond(false, "user_id required");

// ── Check availability + active leave today ───────────────────────────────
function isAvailableToday(mysqli $conn, int $empId): bool {
  // 1) Check availability_status column
  $st = $conn->prepare(
    "SELECT availability_status FROM employees WHERE employee_id = ? LIMIT 1"
  );
  $st->bind_param("i", $empId);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$row) return false;
  $status = strtolower(trim((string)($row["availability_status"] ?? "available")));
  if ($status !== "available") return false;

  // 2) Check approved leave covering today
  $today = date("Y-m-d");
  $st2 = $conn->prepare("
    SELECT COUNT(*) AS cnt FROM leave_requests
    WHERE employee_id = ?
      AND status IN ('APPROVED','RELIEVER_ACCEPTED')
      AND leave_start_date <= ?
      AND leave_end_date   >= ?
  ");
  $st2->bind_param("iss", $empId, $today, $today);
  $st2->execute();
  $cnt = (int)$st2->get_result()->fetch_assoc()["cnt"];
  $st2->close();

  return $cnt === 0;
}

try {

  // ── 1. Verify caller is a senior approver (GM or MD) ─────────────────
  $authStmt = $conn->prepare("
    SELECT jt.name AS title_name
    FROM employee_job ej
    JOIN job_titles jt ON jt.job_title_id = ej.job_title_id
    WHERE ej.employee_id = ?
    LIMIT 1
  ");
  $authStmt->bind_param("i", $userId);
  $authStmt->execute();
  $authRow = $authStmt->get_result()->fetch_assoc();
  $authStmt->close();

  $titleName = $authRow["title_name"] ?? "";
  $isSenior  = in_array($titleName, ["Group General Manager", "Managing Director"]);

  if (!$isSenior) {
    respond(false, "Access denied: only GM or MD can view these requests");
  }

  // ── 2. Availability check ─────────────────────────────────────────────
  // If this senior approver is unavailable/on leave, return empty.
  // The other senior approver (MD when GM is unavailable) will handle these.
  if (!isAvailableToday($conn, $userId)) {
    respond(true, "You are currently unavailable. Requests are being handled by the next available approver.", []);
  }

  // ── 3. Load HOD_APPROVED requests + own PENDING direct reports ────────
  $sql = "
    SELECT
      ts.id AS request_id,
      ts.employee_id,
      ts.manager_id,
      ts.status,
      ts.type,
      ts.vehicle_type,
      ts.vehicle_no,
      ts.vehicle_id,
      ts.chauffer_phone,
      ts.chauffer_name,
      ts.assigned_start_at,
      ts.assigned_end_at,
      ts.dropoff_location,
      ts.pickup_location,
      ts.trip_code,
      ts.created_at,
      ts.hod_comment,

      e.employee_code,
      COALESCE(NULLIF(TRIM(e.preferred_name),''), TRIM(e.full_name)) AS employee_name,

      jt.job_title_id,
      jt.name AS job_title_name,

      ts.attempt_number

    FROM transport_services ts
    JOIN employees e ON e.employee_id = ts.employee_id
    LEFT JOIN employee_job ej2 ON ej2.employee_id = ts.employee_id
    LEFT JOIN job_titles jt ON jt.job_title_id = ej2.job_title_id

    WHERE ts.type = 'personal'
      AND ts.deleted_at IS NULL
      AND (
        ts.status = 'HOD_APPROVED'
        OR (ts.status = 'PENDING' AND ts.manager_id = ?)
      )

    ORDER BY ts.created_at DESC
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $userId);
  $stmt->execute();
  $result = $stmt->get_result();

  $rows = [];
  while ($row = $result->fetch_assoc()) {
    $row["from_date"] = $row["assigned_start_at"]
      ? date("Y-m-d", strtotime($row["assigned_start_at"])) : "";
    $row["to_date"] = $row["assigned_end_at"]
      ? date("Y-m-d", strtotime($row["assigned_end_at"])) : "";
    $attempt = (int)($row["attempt_number"] ?? 0);
    if ($attempt <= 0) {
        $row["attempt_label"] = "—";
    } elseif ($attempt >= 6) {
        $row["attempt_label"] = "6th+ Attempt";
    } else {
        $suffixes = [1 => "st", 2 => "nd", 3 => "rd"];
        $suffix   = $suffixes[$attempt] ?? "th";
        $row["attempt_label"] = "{$attempt}{$suffix} Attempt";
    }
    $rows[] = $row;
  }

  respond(true, "Success", $rows);

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error: " . $e->getMessage());
}