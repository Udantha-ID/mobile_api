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

// ── Fields ────────────────────────────────────────────────────────────────────
$ticket_id   = (int)  ($body["ticket_id"]   ?? 0);
$reader_type = trim($body["reader_type"] ?? "");

// ── Validate ──────────────────────────────────────────────────────────────────
if ($ticket_id  <= 0) respond(false, "ticket_id required");
if (!in_array($reader_type, ["user", "agent"], true)) {
    respond(false, "reader_type must be 'user' or 'agent'");
}

try {
    // When the agent reads → mark all user-sent messages as read
    // When the user reads  → mark all agent-sent messages as read
    $sender_to_mark = $reader_type === "agent" ? "user" : "agent";

    $stmt = $conn->prepare("
        UPDATE ticket_messages
        SET is_read = 1
        WHERE ticket_id   = ?
          AND sender_type = ?
          AND is_read     = 0
    ");
    $stmt->bind_param("is", $ticket_id, $sender_to_mark);
    $stmt->execute();
    $stmt->close();

    // When the agent opens a ticket, also mark the ticket itself as read
    // (tickets.is_read indicates the agent has viewed the ticket at least once)
    if ($reader_type === "agent") {
        $stTicket = $conn->prepare("
            UPDATE tickets SET is_read = 1 WHERE id = ? AND is_read = 0
        ");
        $stTicket->bind_param("i", $ticket_id);
        $stTicket->execute();
        $stTicket->close();
    }

    respond(true, "Marked as read", null);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}
