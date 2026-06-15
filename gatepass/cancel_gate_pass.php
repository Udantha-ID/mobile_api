<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
    echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
    exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    respond(false, "Method not allowed");
}

$raw  = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) respond(false, "Invalid JSON");

$id          = (int)   ($body["id"]          ?? 0);
$employee_id = (int)   ($body["employee_id"] ?? 0);

if ($id          <= 0) respond(false, "id is required");
if ($employee_id <= 0) respond(false, "employee_id is required");

try {

    // Only the owner can delete, and only when PENDING
    $stmt = $conn->prepare("
        DELETE FROM gate_pass_requests
        WHERE id          = ?
          AND employee_id = ?
          AND status      = 'PENDING'
          AND deleted_at  IS NULL
    ");
    $stmt->bind_param("ii", $id, $employee_id);
    $stmt->execute();

    if ($stmt->affected_rows <= 0) {
        $stmt->close();
        respond(false, "Cannot delete. Request may not be PENDING or does not belong to you.");
    }

    $stmt->close();
    respond(true, "Gate pass request deleted successfully", ["id" => $id]);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}