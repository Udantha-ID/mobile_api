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

$ticket_id   = (int)trim($body["ticket_id"]   ?? 0);
$employee_id = (int)trim($body["employee_id"] ?? 0);

if ($ticket_id   <= 0) respond(false, "ticket_id required");
if ($employee_id <= 0) respond(false, "employee_id required");

try {
    // ── Verify ticket exists, belongs to employee, and is still open ──────────
    $stCheck = $conn->prepare("
        SELECT id, status, user_id FROM tickets WHERE id = ? LIMIT 1
    ");
    $stCheck->bind_param("i", $ticket_id);
    $stCheck->execute();
    $ticket = $stCheck->get_result()->fetch_assoc();
    $stCheck->close();

    if (!$ticket) {
        respond(false, "Ticket not found");
    }
    if ((int)$ticket["user_id"] !== $employee_id) {
        http_response_code(403);
        respond(false, "You are not authorised to cancel this ticket");
    }
    if ($ticket["status"] !== "open") {
        respond(false, "Only open tickets can be cancelled");
    }

    // ── Delete messages first (FK), then the ticket ───────────────────────────
    $conn->begin_transaction();

    try {
        $stMsgs = $conn->prepare("DELETE FROM ticket_messages WHERE ticket_id = ?");
        $stMsgs->bind_param("i", $ticket_id);
        $stMsgs->execute();
        $stMsgs->close();

        $stTicket = $conn->prepare("DELETE FROM tickets WHERE id = ? AND status = 'open'");
        $stTicket->bind_param("i", $ticket_id);
        $stTicket->execute();
        $affected = $stTicket->affected_rows;
        $stTicket->close();

        if ($affected === 0) {
            $conn->rollback();
            respond(false, "Ticket could not be deleted — it may have been updated");
        }

        $conn->commit();
    } catch (Throwable $txErr) {
        $conn->rollback();
        throw $txErr;
    }

    respond(true, "Ticket cancelled successfully");

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}
