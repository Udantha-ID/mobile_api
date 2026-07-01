<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function respond($success, $message, $data = null): void {
    ob_end_clean();
    echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
    exit;
}

// ── Check if an employee is available (not on leave today, not marked unavailable) ──
function isAvailable(mysqli $conn, int $empId): bool {
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

// ── Fetch name for a given employee ───────────────────────────────────────────
function getManagerInfo(mysqli $conn, int $empId): ?array {
    $st = $conn->prepare("
        SELECT employee_id,
               COALESCE(NULLIF(TRIM(preferred_name), ''), TRIM(full_name)) AS name
        FROM employees
        WHERE employee_id = ? LIMIT 1
    ");
    $st->bind_param("i", $empId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) return null;
    return [
        "id"   => (int)$row["employee_id"],
        "name" => (string)$row["name"],
    ];
}

try {
    // Fixed priority chain: HR (45) → GM (14) → MD (11)
    $chain = [45, 14, 11];

    $selected = null;

    foreach ($chain as $empId) {
        if (isAvailable($conn, $empId)) {
            $selected = getManagerInfo($conn, $empId);
            break;
        }
    }

    // Last resort: show MD regardless of availability so the form is never empty
    if ($selected === null) {
        $selected = getManagerInfo($conn, 11);
    }

    respond(true, "OK", [
        "managers" => $selected ? [$selected] : [],
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}
