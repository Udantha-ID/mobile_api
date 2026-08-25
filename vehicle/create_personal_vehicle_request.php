<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
    echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
    exit;
}

function writeLog($title, $data = null) {
    $file = __DIR__ . "/transport_sync_log.txt";
    $text = "[" . date("Y-m-d H:i:s") . "] " . $title . PHP_EOL;

    if ($data !== null) {
        $text .= print_r($data, true) . PHP_EOL;
    }

    $text .= str_repeat("-", 60) . PHP_EOL;
    file_put_contents($file, $text, FILE_APPEND);
}

function generateBookingNumber($type = "PERSONAL") {
    $type = strtoupper($type);

    $random = str_pad(rand(0, 99), 2, "0", STR_PAD_LEFT);

    return $type . "-" . $random;
}

function sendRentalToExploreDrive($payload) {
    //$url = "http://127.0.0.1:8000/api/rental-sync";
    $secret = "123456789";

    writeLog("Sending rental sync request", [
        "url" => $url,
        "payload" => $payload
    ]);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Accept: application/json",
            "X-SYNC-SECRET: " . $secret
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    $result = [
        "success" => $httpCode >= 200 && $httpCode < 300,
        "status" => $httpCode,
        "error" => $error,
        "raw" => $response,
        "decoded" => json_decode($response, true),
    ];

    writeLog("Rental sync response", $result);

    return $result;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    respond(false, "Method not allowed");
}

$raw  = file_get_contents("php://input");
$body = json_decode($raw, true);
writeLog("Incoming request", [
    "raw" => $raw,
    "decoded" => $body
]);

if (!is_array($body)) {
    respond(false, "Invalid JSON");
}

if (!is_array($body)) respond(false, "Invalid JSON");

// ── Required fields ───────────────────────────────────────────────────────
$employee_id    = (int)($body["employee_id"]    ?? 0);
$manager_id     = (int)($body["manager_id"]     ?? 0);
$vehicle_no     = trim($body["vehicle_no"]      ?? "");
$from_date      = trim($body["from_date"]       ?? "");
$to_date        = trim($body["to_date"]         ?? "");
$reason         = trim($body["reason"]          ?? "Office Service");
$vehicle_type   = trim($body["vehicle_type"]    ?? "-");
$chauffer_phone = trim($body["chauffer_phone"]  ?? "");
$chauffer_name  = trim($body["chauffer_name"]   ?? "");
$vehicle_id     = (int)($body["vehicle_id"]     ?? 0);
// ── Optional fields ───────────────────────────────────────────────────────
$remark         = (isset($body["remark"]) && $body["remark"] !== "")
    ? trim($body["remark"]) : null;
$attempt_number = (int)($body["attempt_number"] ?? 0);

// ── Validate ──────────────────────────────────────────────────────────────
if ($employee_id    <= 0) respond(false, "employee_id required");
if ($manager_id     <= 0) respond(false, "manager_id required");
if ($vehicle_no    === "") respond(false, "vehicle_no required");
if ($from_date     === "") respond(false, "from_date required");
if ($to_date       === "") respond(false, "to_date required");
if ($chauffer_phone === "") respond(false, "chauffer_phone required");
if ($chauffer_name  === "") respond(false, "chauffer_name required");
if ($vehicle_id     <= 0) respond(false, "vehicle_id required");
if ($attempt_number < 1 || $attempt_number > 6) respond(false, "Invalid attempt number");

// ── Build values ──────────────────────────────────────────────────────────
$assigned_start_at = $from_date . " 00:00:00";
$assigned_end_at   = $to_date   . " 23:59:59";
$pickup_location   = "Head Office";
$dropoff_location  = "-";
$type              = "personal";
$status            = "PENDING";

try {

    // ── Probation check ───────────────────────────────────────────────────
    $probationStmt = $conn->prepare("
        SELECT employment_level FROM employee_job
        WHERE employee_id = ? LIMIT 1
    ");
    $probationStmt->bind_param("i", $employee_id);
    $probationStmt->execute();
    $probationResult = $probationStmt->get_result();

    if ($probationResult->num_rows > 0) {
        $employee        = $probationResult->fetch_assoc();
        $employmentLevel = strtolower(trim($employee["employment_level"]));
        if ($employmentLevel !== "permanent") {
            respond(false, "Only permanent staff members can apply for Personal Vehicle requests.");
        }
    }
    $probationStmt->close();

    // ── Existing pending check ────────────────────────────────────────────
    $checkStmt = $conn->prepare("
        SELECT id FROM transport_services
        WHERE employee_id = ? AND status = 'PENDING' AND type = 'personal'
        LIMIT 1
    ");
    $checkStmt->bind_param("i", $employee_id);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        respond(false, "You already have a pending request. You can cancel your request or wait until it is completed.");
    }
    $checkStmt->close();

    // ── Duplicate slot check ──────────────────────────────────────────────
    $dupStmt = $conn->prepare("
        SELECT id FROM transport_services
        WHERE employee_id = ? AND attempt_number = ?
          AND type = 'personal'
          AND status NOT IN ('REJECTED','CANCELLED')
          AND deleted_at IS NULL
        LIMIT 1
    ");
    $dupStmt->bind_param("ii", $employee_id, $attempt_number);
    $dupStmt->execute();
    if ($dupStmt->get_result()->num_rows > 0) {
        respond(false, "This attempt slot is already used.");
    }
    $dupStmt->close();

    // ── Insert ────────────────────────────────────────────────────────────
    $stmt = $conn->prepare("
        INSERT INTO transport_services (
            source_id, type, attempt_number, vehicle_type, vehicle_id, vehicle_no,
            chauffer_phone, chauffer_name, employee_id, manager_id, status,
            assigned_start_at, pickup_location, dropoff_location, assigned_end_at,
            passenger_count, trip_code, remark, created_at, updated_at
        ) VALUES (
            0, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            1, NULL, ?, NOW(), NOW()
        )
    ");

    $stmt->bind_param(
        "siissssssssssss",  // 15 chars: type(s), attempt_number(i), vehicle_type(s), vehicle_id(i), ...
        $type,              // s
        $attempt_number,    // i
        $vehicle_type,      // s
        $vehicle_id,        // i
        $vehicle_no,        // s
        $chauffer_phone,    // s
        $chauffer_name,     // s
        $employee_id,       // s
        $manager_id,        // s
        $status,            // s
        $assigned_start_at, // s
        $pickup_location,   // s
        $dropoff_location,  // s
        $assigned_end_at,   // s
        $remark             // s
    );

    $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();

        writeLog("Transport created", [
        "transport_id" => $id,
        "vehicle_id" => $vehicle_id
    ]);

    // Sync to Laravel rentals table
    $payload = [
        "booking_number" => generateBookingNumber($type),
        "vehicle_id"     => $vehicle_id,
        "company_id"     => 1,
        "driver_name"    => $chauffer_name,
        "arrival_date"   => $assigned_start_at,
        "departure_date" => $assigned_end_at,
        "passengers"     => 1,
        "status"         => "booked",
        "created_by"     => 1,

        // optional extras
        "transport_id"   => $id,
        "vehicle_no"     => $vehicle_no,
        "reason"         => $reason,
        "contact_no"     => $chauffer_phone,
        "vehicle_type"   => $vehicle_type
    ];

    $rentalResult = sendRentalToExploreDrive($payload);

    if (!$rentalResult["success"]) {
        writeLog("Rental sync failed", [
            "transport_id" => $id,
            "rental" => $rentalResult
        ]);

        respond(false, "Transport created, but rental sync failed", [
            "id" => $id,
            "rental" => $rentalResult
        ]);
    }

    writeLog("Final success", [
        "transport_id" => $id,
        "rental" => $rentalResult
    ]);

    // ── NOTIFY MANAGER (non-blocking) ─────────────────────────────────────
    try {
        require_once __DIR__ . "/../notifications/fcm_helper.php";

        // Get applicant's display name
        $stName = $conn->prepare(
            "SELECT preferred_name, full_name FROM employees WHERE employee_id = ? LIMIT 1"
        );
        $stName->bind_param("i", $employee_id);
        $stName->execute();
        $nameRow = $stName->get_result()->fetch_assoc();
        $stName->close();

        $applicantName = trim((string)($nameRow["preferred_name"] ?? ""));
        if ($applicantName === "") $applicantName = trim((string)($nameRow["full_name"] ?? ""));
        if ($applicantName === "") $applicantName = "An employee";

        // Get manager's FCM token
        $stMgr = $conn->prepare(
            "SELECT fcm_token FROM employees WHERE employee_id = ? LIMIT 1"
        );
        $stMgr->bind_param("i", $manager_id);
        $stMgr->execute();
        $mgrRow = $stMgr->get_result()->fetch_assoc();
        $stMgr->close();

        if (!empty($mgrRow["fcm_token"])) {
            FcmHelper::send(
                $mgrRow["fcm_token"],
                "New Personal Vehicle Request",
                "$applicantName has submitted a personal vehicle request ($vehicle_no) for $from_date – $to_date. Please review and approve.",
                [
                    "type"   => "personal_vehicle_approval",
                    "tripId" => (string)$newId,
                ]
            );
        }

    } catch (Throwable $notifyError) {
        error_log("FCM notify (personal_vehicle_create) failed: " . $notifyError->getMessage());
    }

    respond(true, "Request created successfully", ["id" => $newId]);

} catch (Throwable $e) {

    writeLog("Fatal error", [
        "message" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}