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
$ticket_id       = (int)  ($body["ticket_id"]       ?? 0);
$agent_id        = trim($body["agent_id"]        ?? "");
$resolution_note = trim($body["resolution_note"] ?? "");

// ── Validate ──────────────────────────────────────────────────────────────────
if ($ticket_id <= 0)  respond(false, "ticket_id required");
if ($agent_id === "")  respond(false, "agent_id required");

try {
    // ── Load ticket — verify it exists and is not already resolved ────────────
    $stTicket = $conn->prepare("
        SELECT id, title, status, user_id
        FROM tickets
        WHERE id = ?
        LIMIT 1
    ");
    $stTicket->bind_param("i", $ticket_id);
    $stTicket->execute();
    $ticket = $stTicket->get_result()->fetch_assoc();
    $stTicket->close();

    if (!$ticket) {
        respond(false, "Ticket not found");
    }
    if ($ticket["status"] === "resolved") {
        respond(false, "Ticket is already resolved");
    }

    $ticket_title   = $ticket["title"];
    $ticket_user_id = (int)$ticket["user_id"];
    $agent_id_int   = (int)$agent_id;

    // Use the resolution note as the final message, or a default
    $finalMessage = $resolution_note !== ""
        ? $resolution_note
        : "This ticket has been marked as resolved.";

    // ── Transaction: INSERT resolution message + UPDATE ticket ────────────────
    $conn->begin_transaction();

    try {
        // 1. Insert a final resolution message
        $stMsg = $conn->prepare("
            INSERT INTO ticket_messages
                (ticket_id, sender_type, sender_id, message, is_read, created_at)
            VALUES
                (?, 'agent', ?, ?, 0, NOW())
        ");
        $stMsg->bind_param("iis", $ticket_id, $agent_id_int, $finalMessage);
        $stMsg->execute();
        $stMsg->close();

        // 2. Mark ticket as resolved
        $stUpd = $conn->prepare("
            UPDATE tickets
            SET status          = 'resolved',
                resolved_at     = NOW(),
                resolution_note = ?,
                updated_at      = NOW()
            WHERE id = ?
        ");
        $noteForDb = $resolution_note !== "" ? $resolution_note : null;
        $stUpd->bind_param("si", $noteForDb, $ticket_id);
        $stUpd->execute();
        $stUpd->close();

        $conn->commit();

    } catch (Throwable $txErr) {
        $conn->rollback();
        throw $txErr;
    }

    // ── PUSH NOTIFICATION (non-blocking) ─────────────────────────────────────
    try {
        require_once __DIR__ . "/../notifications/fcm_helper.php";

        if ($ticket_user_id > 0) {
            $stTok = $conn->prepare(
                "SELECT fcm_token FROM employees WHERE employee_id = ? LIMIT 1"
            );
            $stTok->bind_param("i", $ticket_user_id);
            $stTok->execute();
            $tokRow = $stTok->get_result()->fetch_assoc();
            $stTok->close();

            if (!empty($tokRow["fcm_token"])) {
                FcmHelper::send(
                    $tokRow["fcm_token"],
                    "Ticket Resolved",
                    "Your ticket '$ticket_title' has been marked as resolved.",
                    [
                        "type"     => "ticket_resolved",
                        "ticketId" => (string)$ticket_id,
                    ]
                );
            }
        }

    } catch (Throwable $notifyError) {
        error_log("FCM notify (resolve_ticket) failed: " . $notifyError->getMessage());
    }

    respond(true, "Ticket resolved", null);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}
