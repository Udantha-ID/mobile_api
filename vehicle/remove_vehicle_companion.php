<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function respond($success, $message, $data = null) {
    ob_clean();
    echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
    exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    respond(false, "Method not allowed");
}

$body = json_decode(file_get_contents("php://input"), true);
if (!is_array($body)) respond(false, "Invalid JSON");

$transport_service_id = (int)($body["transport_service_id"] ?? 0);
$companion_id         = (int)($body["companion_id"]         ?? 0);
$manager_id           = (int)($body["manager_id"]           ?? 0);

if ($transport_service_id <= 0) respond(false, "transport_service_id is required");
if ($companion_id         <= 0) respond(false, "companion_id is required");
if ($manager_id           <= 0) respond(false, "manager_id is required");

try {

    // Verify request belongs to this manager and is PENDING
    $check = $conn->prepare("
        SELECT id, companion_employee_ids
        FROM transport_services
        WHERE id         = ?
          AND manager_id = ?
          AND status     = 'PENDING'
          AND deleted_at IS NULL
    ");
    $check->bind_param("ii", $transport_service_id, $manager_id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$row) {
        respond(false, "Request not found or not in PENDING status");
    }

    // Remove companion from JSON array
    $current = json_decode($row["companion_employee_ids"] ?? "[]", true) ?? [];
    $updated = array_values(array_filter($current, fn($id) => (int)$id !== $companion_id));
    $updated_json = count($updated) > 0 ? json_encode($updated) : null;

    $stmt = $conn->prepare("
        UPDATE transport_services
        SET companion_employee_ids = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("si", $updated_json, $transport_service_id);
    $stmt->execute();
    $stmt->close();

    respond(true, "Companion removed successfully", [
        "companion_employee_ids" => $updated,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, $e->getMessage());
}