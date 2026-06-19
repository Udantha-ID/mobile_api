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
$sender_type = trim($body["sender_type"] ?? "");
$sender_id   = trim($body["sender_id"]   ?? "");
$message     = trim($body["message"]     ?? "");

// ── Validate ──────────────────────────────────────────────────────────────────
if ($ticket_id  <= 0) respond(false, "ticket_id required");
if (!in_array($sender_type, ["user", "agent"], true)) respond(false, "sender_type must be 'user' or 'agent'");
if ($sender_id === "") respond(false, "sender_id required");
if ($message   === "") respond(false, "message required");

try {
    // ── Load ticket — verify it exists and is not resolved ────────────────────
    $stTicket = $conn->prepare("
        SELECT id, status, user_id, assigned_agent_id
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
        respond(false, "This ticket is resolved and cannot receive new messages");
    }

    $ticket_user_id  = (int)$ticket["user_id"];
    $ticket_agent_id = (int)($ticket["assigned_agent_id"] ?? 0);

    // ── Transaction: INSERT message + UPDATE ticket ───────────────────────────
    $conn->begin_transaction();

    try {
        // 1. Insert the message
        $stMsg = $conn->prepare("
            INSERT INTO ticket_messages
                (ticket_id, sender_type, sender_id, message, is_read, created_at)
            VALUES
                (?, ?, ?, ?, 0, NOW())
        ");
        $sender_id_int = (int)$sender_id;
        $stMsg->bind_param("isis", $ticket_id, $sender_type, $sender_id_int, $message);
        $stMsg->execute();
        $newMsgId = (int)$stMsg->insert_id;
        $stMsg->close();

        // 2. Update ticket timestamp and flip open → in_progress on first agent reply
        if ($sender_type === "agent") {
            $stUpd = $conn->prepare("
                UPDATE tickets
                SET status     = 'in_progress',
                    updated_at = NOW()
                WHERE id = ? AND status = 'open'
            ");
            $stUpd->bind_param("i", $ticket_id);
            $stUpd->execute();
            $stUpd->close();

            // Always bump updated_at even if status didn't change
            $stTouch = $conn->prepare(
                "UPDATE tickets SET updated_at = NOW() WHERE id = ?"
            );
            $stTouch->bind_param("i", $ticket_id);
            $stTouch->execute();
            $stTouch->close();
        } else {
            $stTouch = $conn->prepare(
                "UPDATE tickets SET updated_at = NOW() WHERE id = ?"
            );
            $stTouch->bind_param("i", $ticket_id);
            $stTouch->execute();
            $stTouch->close();
        }

        $conn->commit();

    } catch (Throwable $txErr) {
        $conn->rollback();
        throw $txErr;
    }

    // ── PUSH NOTIFICATION (non-blocking) ─────────────────────────────────────
    try {
        require_once __DIR__ . "/../notifications/fcm_helper.php";

        $ticketIdStr  = (string)$ticket_id;
        $msgPreview   = mb_strlen($message) > 80 ? mb_substr($message, 0, 80) . "…" : $message;

        if ($sender_type === "agent") {
            // Notify the employee who raised the ticket
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
                        "IT Support replied",
                        $msgPreview,
                        [
                            "type"     => "ticket_reply",
                            "ticketId" => $ticketIdStr,
                        ]
                    );
                }
            }
        } else {
            // Notify the assigned IT agent
            if ($ticket_agent_id > 0) {
                $stTok = $conn->prepare(
                    "SELECT fcm_token FROM employees WHERE employee_id = ? LIMIT 1"
                );
                $stTok->bind_param("i", $ticket_agent_id);
                $stTok->execute();
                $tokRow = $stTok->get_result()->fetch_assoc();
                $stTok->close();

                if (!empty($tokRow["fcm_token"])) {
                    FcmHelper::send(
                        $tokRow["fcm_token"],
                        "New reply on ticket #$ticket_id",
                        $msgPreview,
                        [
                            "type"     => "ticket_reply",
                            "ticketId" => $ticketIdStr,
                        ]
                    );
                }
            }
        }

    } catch (Throwable $notifyError) {
        error_log("FCM notify (send_ticket_message) failed: " . $notifyError->getMessage());
    }

    respond(true, "Message sent", ["id" => $newMsgId]);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}
