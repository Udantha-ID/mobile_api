<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
  echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
  exit;
}

$employee_id = trim($_GET["employee_id"] ?? "");
if ($employee_id === "" || !ctype_digit($employee_id)) {
  respond(false, "employee_id is required and must be numeric");
}
$employee_id = (int)$employee_id;

// ── Is this employee available right now? ─────────────────────────────────
// Returns false if: availability_status != available OR on approved leave today
function isAvailable(mysqli $conn, int $empId): bool {
  // 1) availability_status column
  $st = $conn->prepare(
    "SELECT availability_status FROM employees WHERE employee_id = ? LIMIT 1"
  );
  $st->bind_param("i", $empId);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$row) return false;
  if (strtolower(trim($row["availability_status"] ?? "available")) !== "available") {
    return false;
  }

  // 2) Active approved leave covering today
  $today = date("Y-m-d");
  $st2   = $conn->prepare("
    SELECT COUNT(*) AS cnt FROM leave_requests
    WHERE employee_id = ?
      AND status IN ('APPROVED', 'RELIEVER_ACCEPTED')
      AND leave_start_date <= ?
      AND leave_end_date   >= ?
  ");
  $st2->bind_param("iss", $empId, $today, $today);
  $st2->execute();
  $cnt = (int)$st2->get_result()->fetch_assoc()["cnt"];
  $st2->close();

  return $cnt === 0;
}

// ── Fetch one employee's display info ─────────────────────────────────────
function getManagerInfo(mysqli $conn, int $empId): ?array {
  $st = $conn->prepare("
    SELECT employee_id, preferred_name, full_name
    FROM employees WHERE employee_id = ? LIMIT 1
  ");
  $st->bind_param("i", $empId);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$row) return null;

  $name = trim((string)($row["preferred_name"] ?? ""));
  if ($name === "") $name = trim((string)($row["full_name"] ?? ""));

  return ["id" => (int)$row["employee_id"], "name" => $name];
}

try {

  // ── 1. Get the employee's reporting manager ───────────────────────────
  $stmt = $conn->prepare(
    "SELECT reporting_manager_id FROM employee_job WHERE employee_id = ? LIMIT 1"
  );
  $stmt->bind_param("i", $employee_id);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res->num_rows === 0) respond(false, "employee_job row not found");
  $jobRow = $res->fetch_assoc();
  $stmt->close();

  $reportingManagerId = $jobRow["reporting_manager_id"]
    ? (int)$jobRow["reporting_manager_id"] : null;

  // ── 2. Build AVAILABLE managers list for office vehicle form ──────────
  // Includes: reporting manager + all GM/MD employees
  // BUT only those who are currently available (not on leave, not unavailable)
  $allManagers = [];
  $addedIds    = [];

  // Reporting manager — only if available
  if ($reportingManagerId !== null) {
    if (isAvailable($conn, $reportingManagerId)) {
      $info = getManagerInfo($conn, $reportingManagerId);
      if ($info) {
        $allManagers[]                 = $info;
        $addedIds[$reportingManagerId] = true;
      }
    }
  }

// HR Manager (10) + GM (14) + MD (11) — only available ones
// Add more employee IDs here anytime you need a new fixed approver
$fixedApproverIds = [45, 14, 11];

foreach ($fixedApproverIds as $fixedId) {
  if (isset($addedIds[$fixedId])) continue;      // skip if already added (e.g. reporting manager is one of these)
  if (!isAvailable($conn, $fixedId)) continue;   // skip unavailable / on leave

  $info = getManagerInfo($conn, $fixedId);
  if ($info) {
    $allManagers[]        = $info;
    $addedIds[$fixedId]   = true;
  }
}

  // ── 3. Resolve BEST single manager for personal vehicle form ──────────
  // Fallback chain (used when reporting manager is unavailable):
  //   Reporting Manager → HR Manager (10) → GM (14) → MD (11)
  $fallbackChain = [45, 14, 11];

  $resolvedId   = null;
  $resolvedInfo = null;

  // Try reporting manager first
  if ($reportingManagerId !== null && isAvailable($conn, $reportingManagerId)) {
    $resolvedId   = $reportingManagerId;
    $resolvedInfo = getManagerInfo($conn, $reportingManagerId);
  }

  // Walk fallback chain
  if ($resolvedId === null) {
    foreach ($fallbackChain as $fbId) {
      if (isAvailable($conn, $fbId)) {
        $resolvedId   = $fbId;
        $resolvedInfo = getManagerInfo($conn, $fbId);
        break;
      }
    }
  }

  // Absolute last resort: use last in chain regardless of availability
  // (someone must always be selectable)
  if ($resolvedId === null) {
    $lastId       = end($fallbackChain);
    $resolvedId   = $lastId;
    $resolvedInfo = getManagerInfo($conn, $lastId);
  }

  // Make sure resolved manager appears in the full list
  // (handles HR fallback who is not a GM/MD and wouldn't be added above)
  if ($resolvedInfo !== null && !isset($addedIds[$resolvedId])) {
    $allManagers[]         = $resolvedInfo;
    $addedIds[$resolvedId] = true;
  }

  // ── 4. Respond ────────────────────────────────────────────────────────
  respond(true, "OK", [
    // Personal vehicle form: filter managers list by this ID → shows 1 manager
    "reporting_manager_id"          => $resolvedId,

    // For debugging: the actual reporting manager (may differ from resolved)
    "original_reporting_manager_id" => $reportingManagerId,

    // Office vehicle form: shows all available managers as radio options
    // Personal vehicle form: filters this list by reporting_manager_id
    "managers"                      => $allManagers,
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error: " . $e->getMessage());
}