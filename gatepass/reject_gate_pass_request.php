<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    $body = json_decode(file_get_contents("php://input"), true);
    if (!$body || !is_array($body)) {
        http_response_code(400);
        ob_clean();
        echo json_encode(["success" => false, "message" => "Invalid JSON body"]);
        exit;
    }

    $id            = isset($body["id"])            ? intval($body["id"])            : 0;
    $manager_id    = isset($body["manager_id"])    ? intval($body["manager_id"])    : 0;
    $reject_reason = isset($body["reject_reason"]) ? trim($body["reject_reason"])   : "";

    if ($id            <= 0)  { http_response_code(422); ob_clean(); echo json_encode(["success" => false, "message" => "id is required"]);            exit; }
    if ($manager_id    <= 0)  { http_response_code(422); ob_clean(); echo json_encode(["success" => false, "message" => "manager_id is required"]);    exit; }
    if ($reject_reason === "") { http_response_code(422); ob_clean(); echo json_encode(["success" => false, "message" => "reject_reason is required"]); exit; }

    // Verify request belongs to manager and is PENDING
    // Also fetch employee_id and dates needed for the notification
    $check = $conn->prepare("
        SELECT id, employee_id, gate_pass_date, out_time, return_time
        FROM gate_pass_requests
        WHERE id = ? AND manager_id = ? AND status = 'PENDING' AND deleted_at IS NULL
    ");
    $check->bind_param("ii", $id, $manager_id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$row) {
        http_response_code(404);
        ob_clean();
        echo json_encode(["success" => false, "message" => "Request not found, already processed, or not assigned to you"]);
        exit;
    }

    // Update to REJECTED
    $stmt = $conn->prepare("
        UPDATE gate_pass_requests
        SET status = 'REJECTED', reject_reason = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("si", $reject_reason, $id);
    $stmt->execute();
    $stmt->close();

    // ── NOTIFY APPLICANT: GATE PASS REJECTED (non-blocking) ──────────────
    try {
        require_once __DIR__ . "/../notifications/fcm_helper.php";

        $applicantId = (int)$row["employee_id"];
        $dateDisplay = $row["gate_pass_date"];
        $outTime     = $row["out_time"];
        $returnTime  = $row["return_time"];

        $stToken = $conn->prepare(
            "SELECT fcm_token FROM employees WHERE employee_id = ? LIMIT 1"
        );
        $stToken->bind_param("i", $applicantId);
        $stToken->execute();
        $tokenRow = $stToken->get_result()->fetch_assoc();
        $stToken->close();

        if (!empty($tokenRow["fcm_token"])) {
            FcmHelper::send(
                $tokenRow["fcm_token"],
                "Gate Pass Rejected",
                "Your gate pass for $dateDisplay ($outTime – $returnTime) was rejected.",
                [
                    "type"       => "gate_pass_rejected",
                    "gatePassId" => (string)$id,
                ]
            );
        }
    } catch (Throwable $notifyError) {
        error_log("FCM notify (gate_pass_reject) failed: " . $notifyError->getMessage());
    }

    ob_clean();
    echo json_encode([
        "success" => true,
        "message" => "Gate pass rejected",
        "id"      => $id,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    exit;
}