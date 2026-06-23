<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../assets/includes/db_connect.php";

date_default_timezone_set('Asia/Colombo');
$conn->query("SET time_zone = '+05:30'");

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
Expected POST fields:
- type (meeting|event|training)
- title
- description (optional)
- meeting_date (YYYY-MM-DD)
- start_time (HH:MM:SS)
- end_time (HH:MM:SS)
- location_type (physical|online)
- location
- members_ids (JSON array OR comma-separated string OR array)
- status (optional, default: scheduled)
- created_by
- response_status (optional)
- attachments (optional)
- attachment (optional file, PDF only)
*/

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Method not allowed"]);
        exit;
    }

    // Accept form-data OR raw JSON
    $input = $_POST;
    if (empty($input)) {
        $raw = json_decode(file_get_contents("php://input"), true);
        if (is_array($raw)) {
            $input = $raw;
        }
    }

    // Normalize fields
    $type          = strtolower(trim($input["type"] ?? "meeting"));
    $title         = trim($input["title"] ?? "");
    $description   = trim($input["description"] ?? "");
    $meeting_date  = trim($input["meeting_date"] ?? "");
    $start_time    = trim($input["start_time"] ?? "");
    $end_time      = trim($input["end_time"] ?? "");
    $location_type = strtolower(trim($input["location_type"] ?? "physical"));
    $location      = trim($input["location"] ?? "");
    $created_by    = (int)($input["created_by"] ?? ($input["user_id"] ?? 0));

    $status = strtolower(trim($input["status"] ?? "scheduled"));
    $allowedStatus = ["scheduled", "ongoing", "completed", "cancelled"];
    if (!in_array($status, $allowedStatus, true)) {
        $status = "scheduled";
    }

    $response_status = trim($input["response_status"] ?? "");
    $attachments     = trim($input["attachments"] ?? "");

    // --- members_ids: keep JSON in DB ---
    $membersRaw = $input["members_ids"] ?? [];
    $membersArr = [];

    if (is_array($membersRaw)) {
        $membersArr = $membersRaw;
    } else {
        $membersRaw = trim((string)$membersRaw);
        if ($membersRaw !== "") {
            $decoded = json_decode($membersRaw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $membersArr = $decoded;
            } else {
                $membersArr = array_map("trim", explode(",", $membersRaw));
            }
        }
    }

    // Keep numeric IDs only
    $membersArr = array_values(array_filter(array_map(function ($v) {
        $s = trim((string)$v);
        return preg_match("/^\d+$/", $s) ? (int)$s : null;
    }, $membersArr), function ($v) {
        return $v !== null;
    }));

    $membersIdsJson = json_encode($membersArr, JSON_UNESCAPED_UNICODE);
    if ($membersIdsJson === false) {
        throw new Exception("Failed to encode members_ids as JSON");
    }

    // Validation — Feature 1: "training" added
    if (!in_array($type, ["meeting", "event", "training"], true)) {
        http_response_code(422);
        echo json_encode([
            "success" => false,
            "message" => "Invalid type. Use 'meeting', 'event', or 'training'"
        ]);
        exit;
    }

    if (!in_array($location_type, ["physical", "online"], true)) {
        http_response_code(422);
        echo json_encode([
            "success" => false,
            "message" => "Invalid location_type. Use 'physical' or 'online'"
        ]);
        exit;
    }

    if (
        $title === "" ||
        $meeting_date === "" ||
        $start_time === "" ||
        $end_time === "" ||
        $location === "" ||
        $created_by <= 0 ||
        empty($membersArr)
    ) {
        http_response_code(422);
        echo json_encode(["success" => false, "message" => "Missing required fields"]);
        exit;
    }

    // INSERT
    $sql = "INSERT INTO meetings
        (type, title, description, meeting_date, start_time, end_time, location_type, location, members_ids, status, created_by, response_status, attachments, created_at, updated_at)
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $stmt,
        "ssssssssssiss",
        $type,
        $title,
        $description,
        $meeting_date,
        $start_time,
        $end_time,
        $location_type,
        $location,
        $membersIdsJson,
        $status,
        $created_by,
        $response_status,
        $attachments
    );

    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Execute failed: " . mysqli_stmt_error($stmt));
    }

    $meetingId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    // ── Feature 2: PDF attachment upload ─────────────────────────────────────
    if (
        isset($_FILES["attachment"]) &&
        $_FILES["attachment"]["error"] === UPLOAD_ERR_OK
    ) {
        $ext = strtolower(pathinfo($_FILES["attachment"]["name"], PATHINFO_EXTENSION));
        if ($ext === "pdf") {
            $uploadDir = __DIR__ . "/../../uploads/meetings/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fileName = "mtg_{$meetingId}_" . time() . ".pdf";
            $filePath = $uploadDir . $fileName;

            if (move_uploaded_file($_FILES["attachment"]["tmp_name"], $filePath)) {
                $attachmentPath  = "uploads/meetings/" . $fileName;
                $attachmentsJson = json_encode([$attachmentPath]);

                $updStmt = $conn->prepare(
                    "UPDATE meetings SET attachments = ? WHERE id = ?"
                );
                $updStmt->bind_param("si", $attachmentsJson, $meetingId);
                $updStmt->execute();
                $updStmt->close();
            }
        }
    }

    // ── Feature 3: FCM notification to invited members ───────────────────────
    try {
        require_once __DIR__ . "/../notifications/fcm_helper.php";

        $stCreator = $conn->prepare(
            "SELECT preferred_name, full_name FROM employees
             WHERE employee_id = ? LIMIT 1"
        );
        $stCreator->bind_param("i", $created_by);
        $stCreator->execute();
        $creatorRow = $stCreator->get_result()->fetch_assoc();
        $stCreator->close();

        $creatorName = trim($creatorRow["preferred_name"] ?? "");
        if ($creatorName === "") {
            $creatorName = trim($creatorRow["full_name"] ?? "An organizer");
        }

        $formattedDate = date("M d, Y", strtotime($meeting_date));

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
            $bindParams = array_merge($membersArr, [$created_by]);
            $bindTypes  = $types . "i";
            $stMembers->bind_param($bindTypes, ...$bindParams);
            $stMembers->execute();
            $memberResult = $stMembers->get_result();
            $stMembers->close();

            $notifTitle = ucfirst($type) . " Invitation";
            $notifBody  = "$creatorName invited you to \"$title\" on $formattedDate";

            while ($memberRow = $memberResult->fetch_assoc()) {
                if (!empty($memberRow["fcm_token"])) {
                    FcmHelper::send(
                        $memberRow["fcm_token"],
                        $notifTitle,
                        $notifBody,
                        [
                            "type"      => "meeting_invite",
                            "meetingId" => (string)$meetingId,
                        ]
                    );
                }
            }
        }
    } catch (Throwable $notifyErr) {
        error_log("FCM meeting invite failed: " . $notifyErr->getMessage());
    }

    ob_clean();
    echo json_encode([
        "success"    => true,
        "message"    => "Event created successfully",
        "meeting_id" => $meetingId,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode([
        "success" => false,
        "message" => "Failed to create event",
        "error"   => $e->getMessage(),
    ]);
    exit;
}
