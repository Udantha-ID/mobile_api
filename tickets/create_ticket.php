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

// ── Required fields ───────────────────────────────────────────────────────────
$employee_id  = (int)($body["employee_id"]  ?? 0);
$platform_id  = (int)($body["platform_id"]  ?? 0);
$title        = trim($body["title"]        ?? "");
$description  = trim($body["description"]  ?? "");

// ── Optional fields ───────────────────────────────────────────────────────────
// common_issue_id is null when the employee chose "Other"
$common_issue_id = isset($body["common_issue_id"]) && (int)$body["common_issue_id"] > 0
    ? (int)$body["common_issue_id"]
    : null;

// ── Validate ──────────────────────────────────────────────────────────────────
if ($employee_id <= 0) respond(false, "employee_id required");
if ($platform_id <= 0) respond(false, "platform_id required");
if ($title      === "") respond(false, "title required");
if ($description === "") respond(false, "description required");

try {
    // ── Load the single IT agent from config ──────────────────────────────────
    $agentIds = require_once __DIR__ . "/../assets/config/it_agent_ids.php";
    $assigned_agent_id = !empty($agentIds) ? (int)$agentIds[0] : null;

    // ── INSERT ticket ─────────────────────────────────────────────────────────
    $stmt = $conn->prepare("
        INSERT INTO tickets
            (user_id, platform_id, common_issue_id, title, description,
             status, is_read, assigned_agent_id, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?,
             'open', 0, ?, NOW(), NOW())
    ");
    $stmt->bind_param(
        "iiissi",
        $employee_id,
        $platform_id,
        $common_issue_id,
        $title,
        $description,
        $assigned_agent_id
    );
    $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();

    // ── PUSH NOTIFICATION (non-blocking) ─────────────────────────────────────
    try {
        require_once __DIR__ . "/../notifications/fcm_helper.php";

        // Employee's display name
        $stName = $conn->prepare(
            "SELECT preferred_name, full_name FROM employees WHERE employee_id = ? LIMIT 1"
        );
        $stName->bind_param("i", $employee_id);
        $stName->execute();
        $nameRow = $stName->get_result()->fetch_assoc();
        $stName->close();

        $employeeName = trim((string)($nameRow["preferred_name"] ?? ""));
        if ($employeeName === "") $employeeName = trim((string)($nameRow["full_name"] ?? ""));
        if ($employeeName === "") $employeeName = "An employee";

        // Notify the assigned IT agent
        if ($assigned_agent_id !== null) {
            $stAgent = $conn->prepare(
                "SELECT fcm_token FROM employees WHERE employee_id = ? LIMIT 1"
            );
            $stAgent->bind_param("i", $assigned_agent_id);
            $stAgent->execute();
            $agentRow = $stAgent->get_result()->fetch_assoc();
            $stAgent->close();

            if (!empty($agentRow["fcm_token"])) {
                FcmHelper::send(
                    $agentRow["fcm_token"],
                    "New IT Support Ticket",
                    "$employeeName submitted a new ticket: $title",
                    [
                        "type"     => "new_ticket",
                        "ticketId" => (string)$newId,
                    ]
                );
            }
        }

    } catch (Throwable $notifyError) {
        error_log("FCM notify (create_ticket) failed: " . $notifyError->getMessage());
    }

    respond(true, "Ticket created", ["id" => $newId]);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}
