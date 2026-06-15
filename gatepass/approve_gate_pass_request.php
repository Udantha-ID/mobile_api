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

    $id         = isset($body["id"])         ? intval($body["id"])         : 0;
    $manager_id = isset($body["manager_id"]) ? intval($body["manager_id"]) : 0;

    if ($id         <= 0) { http_response_code(422); ob_clean(); echo json_encode(["success" => false, "message" => "id is required"]);         exit; }
    if ($manager_id <= 0) { http_response_code(422); ob_clean(); echo json_encode(["success" => false, "message" => "manager_id is required"]); exit; }

    // Verify request belongs to manager and is PENDING
    $check = $conn->prepare("
        SELECT id, employee_name
        FROM gate_pass_requests
        WHERE id = ? AND manager_id = ? AND status = 'PENDING' AND deleted_at IS NULL
    ");
    $check->bind_param("ii", $id, $manager_id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$row) {
        http_response_code(404);
        ob_clean();
        echo json_encode(["success" => false, "message" => "Request not found, already processed, or not assigned to you"]);
        exit;
    }

    // Generate gate_pass_code e.g. GP-INDU1234
    $name_part      = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $row["employee_name"]), 0, 4));
    $rand           = mt_rand(1000, 9999);
    $gate_pass_code = "GP-" . $name_part . $rand;

    // Ensure uniqueness
    $dup = $conn->prepare("SELECT id FROM gate_pass_requests WHERE gate_pass_code = ?");
    $dup->bind_param("s", $gate_pass_code);
    $dup->execute();
    if ($dup->get_result()->fetch_assoc()) {
        $gate_pass_code = "GP-" . $name_part . mt_rand(1000, 9999);
    }
    $dup->close();

    // Update to APPROVED
    $stmt = $conn->prepare("
        UPDATE gate_pass_requests
        SET status = 'APPROVED', gate_pass_code = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("si", $gate_pass_code, $id);
    $stmt->execute();
    $stmt->close();

    ob_clean();
    echo json_encode([
        "success"        => true,
        "message"        => "Gate pass approved successfully",
        "id"             => $id,
        "gate_pass_code" => $gate_pass_code,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    exit;
}