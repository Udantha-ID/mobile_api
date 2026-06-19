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

$employee_id = (int)($_GET["employee_id"] ?? 0);

if ($employee_id <= 0) {
    respond(false, "employee_id required");
}

try {
    $stmt = $conn->prepare("
        SELECT
            t.id,
            t.title,
            t.description,
            t.status,
            t.created_at,
            t.updated_at,
            p.name        AS platform_name,
            ci.title      AS issue_title,
            (
                SELECT COUNT(*)
                FROM ticket_messages tm
                WHERE tm.ticket_id   = t.id
                  AND tm.sender_type = 'agent'
                  AND tm.is_read     = 0
            ) AS unread_count
        FROM tickets t
        LEFT JOIN platforms    p  ON p.id  = t.platform_id
        LEFT JOIN common_issues ci ON ci.id = t.common_issue_id
        WHERE t.user_id = ?
        ORDER BY t.updated_at DESC
    ");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    $tickets = [];
    while ($row = $result->fetch_assoc()) {
        $tickets[] = [
            "id"           => (int)$row["id"],
            "title"        => $row["title"],
            "description"  => $row["description"] ?? "",
            "status"       => $row["status"],
            "platformName" => $row["platform_name"],
            "issueTitle"   => $row["issue_title"],
            "unreadCount"  => (int)$row["unread_count"],
            "createdAt"    => $row["created_at"],
            "updatedAt"    => $row["updated_at"],
        ];
    }

    respond(true, "Tickets loaded", $tickets);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}
