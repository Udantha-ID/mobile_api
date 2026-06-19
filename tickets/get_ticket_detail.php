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
            t.id,
            t.title,
            t.description,
            t.status,
            t.created_at,
            t.resolved_at,
            t.resolution_note,
            t.user_id                                       AS employee_id,
            p.name                                          AS platform_name,
            ci.title                                        AS issue_title,
            COALESCE(e.preferred_name, e.full_name)         AS employee_name
        FROM tickets t
        LEFT JOIN platforms    p  ON p.id          = t.platform_id
        LEFT JOIN common_issues ci ON ci.id         = t.common_issue_id
        LEFT JOIN employees    e  ON e.employee_id  = t.user_id
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $ticket_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        respond(false, "Ticket not found");
    }

    respond(true, "Ticket loaded", [
        "id"             => (int)$row["id"],
        "title"          => $row["title"],
        "description"    => $row["description"],
        "status"         => $row["status"],
        "platformName"   => $row["platform_name"],
        "issueTitle"     => $row["issue_title"],
        "employeeId"     => (string)$row["employee_id"],
        "employeeName"   => $row["employee_name"],
        "createdAt"      => $row["created_at"],
        "resolvedAt"     => $row["resolved_at"],
        "resolutionNote" => $row["resolution_note"],
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}
