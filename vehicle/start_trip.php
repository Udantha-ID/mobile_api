<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ]);
    exit;
}

function writeLog($message) {
    $logDir = __DIR__ . "/logs";
    $logFile = $logDir . "/trip_start.log";

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $time = date("Y-m-d H:i:s");
    $entry = "[" . $time . "] " . $message . PHP_EOL;

    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

writeLog("Request received for trip start API");

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    writeLog("Method not allowed: " . ($_SERVER["REQUEST_METHOD"] ?? "UNKNOWN"));
    respond(false, "Method not allowed");
}

$transport_service_id = trim($_POST["transport_service_id"] ?? "");
$odometer = trim($_POST["odometer"] ?? "");
$fuel = trim($_POST["fuel_percent"] ?? "");

writeLog("Raw inputs - transport_service_id: {$transport_service_id}, odometer: {$odometer}, fuel_percent: {$fuel}");

if ($transport_service_id === "" || !ctype_digit($transport_service_id)) {
    writeLog("Validation failed: transport_service_id required numeric");
    respond(false, "transport_service_id required numeric");
}

if ($odometer === "" || !ctype_digit($odometer)) {
    writeLog("Validation failed: odometer required numeric");
    respond(false, "odometer required numeric");
}

if ($fuel === "" || !is_numeric($fuel) || $fuel < 0 || $fuel > 100) {
    writeLog("Validation failed: fuel_percent must be 0-100");
    respond(false, "fuel_percent must be 0-100");
}

if (!isset($_FILES["photo"]) || $_FILES["photo"]["error"] !== UPLOAD_ERR_OK) {
    $uploadError = $_FILES["photo"]["error"] ?? "photo_not_set";
    writeLog("Validation failed: photo is required. Upload error: " . $uploadError);
    respond(false, "photo is required");
}

$transport_service_id = (int)$transport_service_id;
$odometer = (int)$odometer;
$fuel = (float)$fuel;

try {
    writeLog("Starting DB transaction for transport_service_id: {$transport_service_id}");
    $conn->begin_transaction();

    // Check trip exists and status is START_TRIP / APPROVED
    $chk = $conn->prepare("SELECT status FROM transport_services WHERE id=? AND deleted_at IS NULL LIMIT 1");
    if (!$chk) {
        throw new Exception("Prepare failed for trip check: " . $conn->error);
    }

    $chk->bind_param("i", $transport_service_id);
    $chk->execute();
    $res = $chk->get_result();

    if ($res->num_rows === 0) {
        $chk->close();
        $conn->rollback();
        writeLog("Trip not found for transport_service_id: {$transport_service_id}");
        respond(false, "Trip not found");
    }

    $row = $res->fetch_assoc();
    $chk->close();

    $currentStatus = strtoupper(trim($row["status"] ?? ""));
    $allowedStatuses = ["APPROVED", "START_TRIP"];

    writeLog("Trip found. Current status: {$currentStatus}");

    if (!in_array($currentStatus, $allowedStatuses, true)) {
        $conn->rollback();
        writeLog("Trip status invalid for start. Current status: {$currentStatus}");
        respond(false, "Trip must be APPROVED or START_TRIP to start");
    }

    // Save image
    $uploadDir = __DIR__ . "/../uploads/trips/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
        writeLog("Created upload directory: {$uploadDir}");
    }

    $originalFileName = $_FILES["photo"]["name"] ?? "";
    $ext = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
    $allowedExtensions = ["jpg", "jpeg", "png", "webp"];

    writeLog("Uploaded file detected: {$originalFileName}, extension: {$ext}");

    if (!in_array($ext, $allowedExtensions, true)) {
        $conn->rollback();
        writeLog("Invalid image type: {$ext}");
        respond(false, "Invalid image type");
    }

    $fileName = "start_{$transport_service_id}_" . time() . "." . $ext;
    $filePath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES["photo"]["tmp_name"], $filePath)) {
        $conn->rollback();
        writeLog("Failed to move uploaded file to: {$filePath}");
        respond(false, "Failed to save photo");
    }

    // Store relative path in DB
    $dbPhotoPath = "uploads/trips/" . $fileName;
    writeLog("Photo saved successfully: {$dbPhotoPath}");

    // Insert trip_details
    $ins = $conn->prepare("
        INSERT INTO trip_details
        (
            transport_service_id,
            trip_start_datetime,
            trip_start_odometer,
            trip_start_odometer_photo,
            start_trip_fuel,
            created_at,
            updated_at
        )
        VALUES
        (?, NOW(), ?, ?, ?, NOW(), NOW())
    ");

    if (!$ins) {
        throw new Exception("Prepare failed for trip_details insert: " . $conn->error);
    }

    $ins->bind_param("iisd", $transport_service_id, $odometer, $dbPhotoPath, $fuel);
    $ins->execute();
    $trip_detail_id = $ins->insert_id;
    $ins->close();

    writeLog("trip_details inserted successfully. trip_detail_id: {$trip_detail_id}");

    // Update transport_services status -> IN_PROGRESS
    $upd = $conn->prepare("UPDATE transport_services SET status='IN_PROGRESS', updated_at=NOW() WHERE id=?");
    if (!$upd) {
        throw new Exception("Prepare failed for transport_services update: " . $conn->error);
    }

    $upd->bind_param("i", $transport_service_id);
    $upd->execute();
    $upd->close();

    writeLog("transport_services updated to IN_PROGRESS for ID: {$transport_service_id}");

    $conn->commit();
    writeLog("Transaction committed successfully for transport_service_id: {$transport_service_id}");

    respond(true, "Trip started", [
        "trip_detail_id" => $trip_detail_id,
        "transport_service_id" => $transport_service_id,
        "status" => "IN_PROGRESS",
        "photo" => $dbPhotoPath
    ]);

} catch (Throwable $e) {
    if ($conn && $conn->ping()) {
        $conn->rollback();
    }

    http_response_code(500);
    writeLog("ERROR: " . $e->getMessage());
    respond(false, "Server error: " . $e->getMessage());
}