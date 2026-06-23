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

    // Accept JSON body
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

    // Fetch full meeting details before deleting (needed for FCM notification)
    $stFetch = $conn->prepare(
        "SELECT id, created_by, title, type, meeting_date, members_ids
         FROM meetings WHERE id = ? LIMIT 1"
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
        echo json_encode(["success" => false, "message" => "Not authorized to delete this meeting"]);
        exit;
    }

    // Hard delete — remove the row completely
    $stDel = $conn->prepare("DELETE FROM meetings WHERE id = ?");
    $stDel->bind_param("i", $meeting_id);

    if (!$stDel->execute()) {
        throw new Exception("Failed to delete meeting: " . $stDel->error);
    }
    $stDel->close();

    // ── FCM: notify members that the meeting was cancelled ───────────────────
    try {
        require_once __DIR__ . "/../notifications/fcm_helper.php";

        // Creator's display name
        $stCreator = $conn->prepare(
            "SELECT preferred_name, full_name FROM employees
             WHERE employee_id = ? LIMIT 1"
        );
        $stCreator->bind_param("i", $employee_id);
        $stCreator->execute();
        $creatorRow = $stCreator->get_result()->fetch_assoc();
        $stCreator->close();

        $creatorName = trim($creatorRow["preferred_name"] ?? "");
        if ($creatorName === "") {
            $creatorName = trim($creatorRow["full_name"] ?? "The organizer");
        }

        $membersArr = json_decode($meeting["members_ids"] ?? "[]", true) ?? [];
        $membersArr = array_values(array_filter(array_map('intval', $membersArr)));

        $formattedDate = date("M d, Y", strtotime($meeting["meeting_date"]));
        $meetingTitle  = $meeting["title"] ?? "Meeting";
        $meetingType   = ucfirst($meeting["type"] ?? "meeting");

        if (!empty($membersArr)) {
            $placeholders = implode(",", array_fill(0, count($membersArr), "?"));
            $types        = str_repeat("i", count($membersArr));

            $stMembers = $conn->prepare(
                "SELECT employee_id, fcm_token FROM employees
                 WHERE employee_id IN ($placeholders)
                   AND employee_id != ?
                   AND fcm_token IS NOT NULL
                   AND fcm_token != ''"
            );
            $bindParams = array_merge($membersArr, [$employee_id]);
            $bindTypes  = $types . "i";
            $stMembers->bind_param($bindTypes, ...$bindParams);
            $stMembers->execute();
            $memberResult = $stMembers->get_result();
            $stMembers->close();

            $notifTitle = "$meetingType Cancelled";
            $notifBody  = "$creatorName cancelled \"$meetingTitle\" scheduled on $formattedDate";

            while ($memberRow = $memberResult->fetch_assoc()) {
                if (!empty($memberRow["fcm_token"])) {
                    FcmHelper::send(
                        $memberRow["fcm_token"],
                        $notifTitle,
                        $notifBody,
                        [
                            "type"      => "meeting_cancelled",
                            "meetingId" => (string)$meeting_id,
                        ]
                    );
                }
            }
        }
    } catch (Throwable $notifyErr) {
        error_log("FCM meeting cancel failed: " . $notifyErr->getMessage());
    }

    ob_clean();
    echo json_encode(["success" => true, "message" => "Meeting deleted successfully"]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    exit;
}
