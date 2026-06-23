<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . "/../assets/includes/db_connect.php";

date_default_timezone_set('Asia/Colombo');
$conn->query("SET time_zone = '+05:30'");

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        http_response_code(405);
        ob_clean();
        echo json_encode(["success" => false, "message" => "Method not allowed"]);
        exit;
    }

    $body        = json_decode(file_get_contents("php://input"), true) ?? [];
    $meeting_id  = (int)($body["meeting_id"]  ?? 0);
    $employee_id = (int)($body["employee_id"] ?? 0);

    if ($meeting_id <= 0) {
        http_response_code(422);
        ob_clean();
        echo json_encode(["success" => false, "message" => "meeting_id is required"]);
        exit;
    }

    if ($employee_id <= 0) {
        http_response_code(422);
        ob_clean();
        echo json_encode(["success" => false, "message" => "employee_id is required"]);
        exit;
    }

    // Fetch meeting
    $stFetch = $conn->prepare(
        "SELECT id, created_by, status, deleted_at FROM meetings WHERE id = ? LIMIT 1"
    );
    $stFetch->bind_param("i", $meeting_id);
    $stFetch->execute();
    $meeting = $stFetch->get_result()->fetch_assoc();
    $stFetch->close();

    if (!$meeting) {
        http_response_code(404);
        ob_clean();
        echo json_encode(["success" => false, "message" => "Meeting not found"]);
        exit;
    }

    if ((int)$meeting["created_by"] !== $employee_id) {
        http_response_code(403);
        ob_clean();
        echo json_encode(["success" => false, "message" => "Not authorized to cancel this meeting"]);
        exit;
    }

    if ($meeting["deleted_at"] !== null) {
        ob_clean();
        echo json_encode(["success" => false, "message" => "Meeting has been deleted"]);
        exit;
    }

    if ($meeting["status"] === "cancelled") {
        ob_clean();
        echo json_encode(["success" => false, "message" => "Meeting is already cancelled"]);
        exit;
    }

    // Update status to cancelled
    $stCancel = $conn->prepare(
        "UPDATE meetings SET status = 'cancelled', updated_at = NOW() WHERE id = ?"
    );
    $stCancel->bind_param("i", $meeting_id);

    if (!$stCancel->execute()) {
        throw new Exception("Failed to cancel meeting: " . $stCancel->error);
    }
    $stCancel->close();

    ob_clean();
    echo json_encode(["success" => true, "message" => "Meeting cancelled successfully"]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    exit;
}
