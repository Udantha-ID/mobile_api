<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
    echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
    exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
    http_response_code(405);
    respond(false, "Method not allowed");
}

$ticket_id = (int)($_GET["ticket_id"] ?? 0);

if ($ticket_id <= 0) {
    respond(false, "ticket_id required");
}

try {
    $stmt = $conn->prepare("
        SELECT
            tm.id,
            tm.sender_type,
            tm.sender_id,
            COALESCE(e.preferred_name, e.full_name) AS sender_name,
            tm.message,
            tm.is_read,
            tm.created_at
        FROM ticket_messages tm
        LEFT JOIN employees e ON e.employee_id = tm.sender_id
        WHERE tm.ticket_id = ?
        ORDER BY tm.created_at ASC
    ");
    $stmt->bind_param("i", $ticket_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = [
            "id"         => (int)$row["id"],
            "senderType" => $row["sender_type"],
            "senderId"   => (string)$row["sender_id"],
            "senderName" => $row["sender_name"],
            "message"    => $row["message"],
            "isRead"     => (bool)$row["is_read"],
            "createdAt"  => $row["created_at"],
        ];
    }

    respond(true, "Messages loaded", $messages);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}
