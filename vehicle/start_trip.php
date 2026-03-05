<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

    ini_set("display_errors", 0);
    error_reporting(E_ALL);

    function respond($success, $message, $data = null) {
    echo json_encode(["success"=>$success, "message"=>$message, "data"=>$data]);
    exit;
    }

    if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    respond(false, "Method not allowed");
    }

    $transport_service_id = trim($_POST["transport_service_id"] ?? "");
    $odometer = trim($_POST["odometer"] ?? "");
    $fuel = trim($_POST["fuel_percent"] ?? "");

    if ($transport_service_id === "" || !ctype_digit($transport_service_id)) {
    respond(false, "transport_service_id required numeric");
    }
    if ($odometer === "" || !ctype_digit($odometer)) {
    respond(false, "odometer required numeric");
    }
    if ($fuel === "" || !is_numeric($fuel) || $fuel < 0 || $fuel > 100) {
    respond(false, "fuel_percent must be 0-100");
    }

    if (!isset($_FILES["photo"]) || $_FILES["photo"]["error"] !== UPLOAD_ERR_OK) {
    respond(false, "photo is required");
    }

    $transport_service_id = (int)$transport_service_id;
    $odometer = (int)$odometer;
    $fuel = (float)$fuel;

    try {
    $conn->begin_transaction();

    // Check trip exists and status is START_TRIP
    $chk = $conn->prepare("SELECT status FROM transport_services WHERE id=? AND deleted_at IS NULL LIMIT 1");
    $chk->bind_param("i", $transport_service_id);
    $chk->execute();
    $res = $chk->get_result();
    if ($res->num_rows === 0) {
        $conn->rollback();
        respond(false, "Trip not found");
    }
    $row = $res->fetch_assoc();
    $chk->close();

    $currentStatus = strtoupper(trim($row["status"] ?? ""));
    $allowed = ["APPROVED", "START_TRIP"];

    if (!in_array($currentStatus, $allowed, true)) {
    $conn->rollback();
    respond(false, "Trip must be APPROVED or START_TRIP to start");
    }

    // Save image
    $uploadDir = __DIR__ . "/../uploads/trips/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $ext = pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION);
    $ext = strtolower($ext);
    $allowed = ["jpg","jpeg","png","webp"];
    if (!in_array($ext, $allowed, true)) {
        $conn->rollback();
        respond(false, "Invalid image type");
    }

    $fileName = "start_{$transport_service_id}_" . time() . "." . $ext;
    $filePath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES["photo"]["tmp_name"], $filePath)) {
        $conn->rollback();
        respond(false, "Failed to save photo");
    }

    // store relative path in DB
    $dbPhotoPath = "uploads/trips/" . $fileName;

    // Insert trip_details
    $ins = $conn->prepare("
        INSERT INTO trip_details
        (transport_service_id, trip_start_datetime, trip_start_odometer, trip_start_odometer_photo, start_trip_fuel, created_at, updated_at)
        VALUES
        (?, NOW(), ?, ?, ?, NOW(), NOW())
    ");
    $ins->bind_param("iisd", $transport_service_id, $odometer, $dbPhotoPath, $fuel);
    $ins->execute();
    $trip_detail_id = $ins->insert_id;
    $ins->close();

    // Update transport_services status -> IN_PROGRESS
    $upd = $conn->prepare("UPDATE transport_services SET status='IN_PROGRESS', updated_at=NOW() WHERE id=?");
    $upd->bind_param("i", $transport_service_id);
    $upd->execute();
    $upd->close();

    $conn->commit();

    respond(true, "Trip started", [
        "trip_detail_id" => $trip_detail_id,
        "transport_service_id" => $transport_service_id,
        "status" => "IN_PROGRESS",
        "photo" => $dbPhotoPath
    ]);

    } catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}