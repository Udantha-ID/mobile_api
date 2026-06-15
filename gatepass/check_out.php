<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    $body = json_decode(file_get_contents("php://input"), true);

    if (!$body || !is_array($body)) {
        http_response_code(400);
        ob_clean();
        echo json_encode(["success" => false, "message" => "Invalid JSON body"]);
        exit;
    }

    $id          = isset($body["id"])          ? intval($body["id"])          : 0;
    $employee_id = isset($body["employee_id"]) ? intval($body["employee_id"]) : 0;

    if ($id          <= 0) { http_response_code(422); ob_clean(); echo json_encode(["success" => false, "message" => "id is required"]);          exit; }
    if ($employee_id <= 0) { http_response_code(422); ob_clean(); echo json_encode(["success" => false, "message" => "employee_id is required"]); exit; }

    // ── Verify request belongs to employee and is APPROVED ────────────────
    $check = $conn->prepare("
        SELECT id, gate_pass_date, out_time, return_time
        FROM gate_pass_requests
        WHERE id          = ?
          AND employee_id = ?
          AND status      = 'APPROVED'
          AND deleted_at  IS NULL
    ");
    $check->bind_param("ii", $id, $employee_id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$row) {
        http_response_code(404);
        ob_clean();
        echo json_encode([
            "success" => false,
            "message" => "Request not found or not in APPROVED status"
        ]);
        exit;
    }

    $tz = new DateTimeZone('Asia/Colombo');
    $now = new DateTime('now', $tz);
    $checked_out_at = $now->format('Y-m-d H:i:s');

    // ── Update to CHECKED_OUT ─────────────────────────────────────────────
    $stmt = $conn->prepare("
        UPDATE gate_pass_requests
        SET
            status          = 'CHECKED_OUT',
            checked_out_at  = ?,
            checked_out_by  = ?,
            updated_at      = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("sii", $checked_out_at, $employee_id, $id);
    $stmt->execute();
    $stmt->close();

    ob_clean();
    echo json_encode([
        "success"        => true,
        "message"        => "Checked out successfully",
        "id"             => $id,
        "checked_out_at" => $checked_out_at,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    exit;
}