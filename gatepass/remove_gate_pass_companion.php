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

    $gate_pass_id      = isset($body["gate_pass_id"])      ? intval($body["gate_pass_id"])      : 0;
    $companion_id      = isset($body["companion_id"])      ? intval($body["companion_id"])      : 0;
    $manager_id        = isset($body["manager_id"])        ? intval($body["manager_id"])        : 0;

    if ($gate_pass_id <= 0) { http_response_code(422); ob_clean(); echo json_encode(["success" => false, "message" => "gate_pass_id is required"]); exit; }
    if ($companion_id <= 0) { http_response_code(422); ob_clean(); echo json_encode(["success" => false, "message" => "companion_id is required"]); exit; }
    if ($manager_id   <= 0) { http_response_code(422); ob_clean(); echo json_encode(["success" => false, "message" => "manager_id is required"]);   exit; }

    // ── Fetch the current companion list ──────────────────────────────────
    $check = $conn->prepare("
        SELECT id, companion_employee_ids
        FROM gate_pass_requests
        WHERE id         = ?
          AND manager_id = ?
          AND status     = 'PENDING'
          AND deleted_at IS NULL
    ");
    $check->bind_param("ii", $gate_pass_id, $manager_id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$row) {
        http_response_code(404);
        ob_clean();
        echo json_encode(["success" => false, "message" => "Request not found or not in PENDING status"]);
        exit;
    }

    // ── Remove companion from JSON array ──────────────────────────────────
    $current = json_decode($row["companion_employee_ids"] ?? "[]", true) ?? [];

    // Filter out the companion to remove
    $updated = array_values(array_filter($current, fn($id) => (int)$id !== $companion_id));

    $updated_json = count($updated) > 0 ? json_encode($updated) : null;

    // ── Save updated list ─────────────────────────────────────────────────
    $stmt = $conn->prepare("
        UPDATE gate_pass_requests
        SET companion_employee_ids = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("si", $updated_json, $gate_pass_id);
    $stmt->execute();
    $stmt->close();

    ob_clean();
    echo json_encode([
        "success"                => true,
        "message"                => "Companion removed successfully",
        "companion_employee_ids" => $updated,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    exit;
}