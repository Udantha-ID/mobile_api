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
    $end_odometer = trim($_POST["end_odometer"] ?? "");
    $end_fuel = trim($_POST["end_fuel_percent"] ?? "");

    if ($transport_service_id === "" || !ctype_digit($transport_service_id)) {
    respond(false, "transport_service_id required numeric");
    }
    if ($end_odometer === "" || !ctype_digit($end_odometer)) {
    respond(false, "end_odometer required numeric");
    }
    if ($end_fuel === "" || !is_numeric($end_fuel) || $end_fuel < 0 || $end_fuel > 100) {
    respond(false, "end_fuel_percent must be 0-100");
    }
    if (!isset($_FILES["photo"]) || $_FILES["photo"]["error"] !== UPLOAD_ERR_OK) {
    respond(false, "photo is required");
    }

    $transport_service_id = (int)$transport_service_id;
    $end_odometer = (int)$end_odometer;
    $end_fuel = (float)$end_fuel;

    try {
    $conn->begin_transaction();

    // trip must be IN_PROGRESS
    $chk = $conn->prepare("SELECT status FROM transport_services WHERE id=? AND deleted_at IS NULL LIMIT 1");
    $chk->bind_param("i", $transport_service_id);
    $chk->execute();
    $r = $chk->get_result();
    if ($r->num_rows === 0) {
        $conn->rollback();
        respond(false, "Trip not found");
    }
    $row = $r->fetch_assoc();
    $chk->close();

    if (strtoupper($row["status"] ?? "") !== "IN_PROGRESS") {
        $conn->rollback();
        respond(false, "Trip must be IN_PROGRESS to stop");
    }

    // get latest trip_details row (the active one)
    $q = $conn->prepare("
        SELECT trip_detail_id, trip_start_odometer
        FROM trip_details
        WHERE transport_service_id = ?
        ORDER BY trip_detail_id DESC
        LIMIT 1
    ");
    $q->bind_param("i", $transport_service_id);
    $q->execute();
    $res = $q->get_result();
    if ($res->num_rows === 0) {
        $conn->rollback();
        respond(false, "Trip details not found for this trip");
    }
    $td = $res->fetch_assoc();
    $q->close();

    $trip_detail_id = (int)$td["trip_detail_id"];
    $start_odometer = (int)$td["trip_start_odometer"];

    if ($end_odometer < $start_odometer) {
        $conn->rollback();
        respond(false, "End odometer cannot be less than start odometer ($start_odometer)");
    }

    $distance = $end_odometer - $start_odometer;

    // upload end photo
    $uploadDir = __DIR__ . "/../uploads/trips/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $ext = strtolower(pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION));
    $allowed = ["jpg","jpeg","png","webp"];
    if (!in_array($ext, $allowed, true)) {
        $conn->rollback();
        respond(false, "Invalid image type (jpg/png/webp only)");
    }

    $fileName = "end_{$transport_service_id}_" . time() . "." . $ext;
    $filePath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES["photo"]["tmp_name"], $filePath)) {
        $conn->rollback();
        respond(false, "Failed to save photo");
    }

    $dbPhotoPath = "uploads/trips/" . $fileName;

    // update trip_details end info
    $updTd = $conn->prepare("
        UPDATE trip_details
        SET
        trip_end_datetime = NOW(),
        trip_end_odometer = ?,
        trip_end_odometer_photo = ?,
        end_trip_fuel = ?,
        updated_at = NOW()
        WHERE trip_detail_id = ?
    ");
    $updTd->bind_param("isdi", $end_odometer, $dbPhotoPath, $end_fuel, $trip_detail_id);
    $updTd->execute();
    $updTd->close();

    // update transport_services -> COMPLETED
    $updTs = $conn->prepare("UPDATE transport_services SET status='COMPLETED', updated_at=NOW() WHERE id=?");
    $updTs->bind_param("i", $transport_service_id);
    $updTs->execute();
    $updTs->close();

    $conn->commit();

    respond(true, "Trip completed", [
        "trip_detail_id" => $trip_detail_id,
        "start_odometer" => $start_odometer,
        "end_odometer" => $end_odometer,
        "distance_km" => $distance,
        "end_photo" => $dbPhotoPath,
        "status" => "COMPLETED"
    ]);

    } catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}