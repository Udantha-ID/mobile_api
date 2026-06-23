<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . "/../assets/includes/db_connect.php";

date_default_timezone_set('Asia/Colombo');
$conn->query("SET time_zone = '+05:30'");

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Decodes JSON fields and computes live status for a row
function processRow(array $row, string $baseUrl): array {
    $row["members_ids"]     = !empty($row["members_ids"])     ? (json_decode($row["members_ids"],     true) ?: []) : [];
    $row["response_status"] = !empty($row["response_status"]) ? (json_decode($row["response_status"], true) ?: []) : [];
    $attachments            = !empty($row["attachments"])      ? (json_decode($row["attachments"],     true) ?: []) : [];
    $row["attachments"]     = $attachments;

    // Full attachment URL
    $row["attachment_url"] = !empty($attachments)
        ? $baseUrl . $attachments[0]
        : null;

    // Compute live status
    $nowTs   = time();
    $startTs = strtotime($row["meeting_date"] . " " . $row["start_time"]);
    $endTs   = strtotime($row["meeting_date"] . " " . $row["end_time"]);

    $currentStatus = strtolower((string)($row["status"] ?? "scheduled"));
    if (!in_array($currentStatus, ["completed", "cancelled"], true)) {
        if ($nowTs < $startTs) {
            $row["status"] = "scheduled";
        } elseif ($nowTs >= $startTs && $nowTs <= $endTs) {
            $row["status"] = "ongoing";
        } else {
            $row["status"] = "completed";
        }
    }

    $row["live_status"] = $row["status"];
    return $row;
}

try {

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        http_response_code(405);
        ob_clean();
        echo json_encode(["success" => false, "message" => "Method not allowed"]);
        exit;
    }

    $employee_id = (int)($_GET["employee_id"] ?? 0);
    if ($employee_id <= 0) {
        http_response_code(422);
        ob_clean();
        echo json_encode(["success" => false, "message" => "employee_id is required"]);
        exit;
    }

    $baseUrl = "http://10.0.2.2/mobile-api/";

    // Query 1 — meetings created by this employee
    $stCreated = $conn->prepare(
        "SELECT * FROM meetings
         WHERE deleted_at IS NULL
           AND created_by = ?
         ORDER BY meeting_date DESC, start_time DESC"
    );
    $stCreated->bind_param("i", $employee_id);
    $stCreated->execute();
    $resCreated = $stCreated->get_result();
    $stCreated->close();

    $created = [];
    while ($row = $resCreated->fetch_assoc()) {
        $created[] = processRow($row, $baseUrl);
    }

    // Query 2 — meetings this employee is invited to (not creator)
    // json_encode(int) produces "26" — a string MySQL parses as a JSON integer,
    // which is what JSON_CONTAINS requires for its second argument.
    $empIdJson = json_encode((int)$employee_id);
    $stInvited = $conn->prepare(
        "SELECT * FROM meetings
         WHERE deleted_at IS NULL
           AND JSON_CONTAINS(members_ids, ?)
           AND created_by != ?
         ORDER BY meeting_date DESC, start_time DESC"
    );
    $stInvited->bind_param("si", $empIdJson, $employee_id);
    $stInvited->execute();
    $resInvited = $stInvited->get_result();
    $stInvited->close();

    $invited = [];
    while ($row = $resInvited->fetch_assoc()) {
        $invited[] = processRow($row, $baseUrl);
    }

    ob_clean();
    echo json_encode([
        "success" => true,
        "message" => "Meetings fetched successfully",
        "created" => $created,
        "invited" => $invited,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    exit;
}
