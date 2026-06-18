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

$transport_service_id = trim($_POST["transport_service_id"] ?? "");
$new_vehicle_no       = strtoupper(trim($_POST["new_vehicle_no"] ?? ""));
$start_meter          = trim($_POST["start_meter"]          ?? "");
$start_fuel           = trim($_POST["start_fuel"]           ?? "");
$remark               = trim($_POST["remark"]               ?? "") ?: null;
$destination          = trim($_POST["destination"]          ?? "") ?: null;

if ($transport_service_id === "" || !ctype_digit($transport_service_id))
    respond(false, "transport_service_id required numeric");
if ($new_vehicle_no === "")
    respond(false, "new_vehicle_no required");
if ($start_meter === "" || !ctype_digit($start_meter))
    respond(false, "start_meter required numeric");
if ($start_fuel === "" || !is_numeric($start_fuel) || $start_fuel < 0 || $start_fuel > 100)
    respond(false, "start_fuel must be 0-100");
if (!isset($_FILES["photo"]) || $_FILES["photo"]["error"] !== UPLOAD_ERR_OK)
    respond(false, "photo is required");

$transport_service_id = (int)$transport_service_id;
$start_meter          = (int)$start_meter;
$start_fuel           = (float)$start_fuel;

try {
    $conn->begin_transaction();

    // 1. Verify VEHICLE_CHANGING
    $chk = $conn->prepare(
        "SELECT status, trip_code FROM transport_services WHERE id = ? AND deleted_at IS NULL LIMIT 1"
    );
    $chk->bind_param("i", $transport_service_id);
    $chk->execute();
    $tsRow = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$tsRow) { $conn->rollback(); respond(false, "Trip not found"); }
    if (strtoupper($tsRow["status"]) !== "VEHICLE_CHANGING") {
        $conn->rollback();
        respond(false, "Trip must be in VEHICLE_CHANGING state to start new vehicle");
    }

    // 2. Get trip_details row
    $q = $conn->prepare("
        SELECT trip_detail_id, change_vehicle_no
        FROM trip_details
        WHERE transport_service_id = ?
        ORDER BY trip_detail_id DESC LIMIT 1
    ");
    $q->bind_param("i", $transport_service_id);
    $q->execute();
    $td = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$td) { $conn->rollback(); respond(false, "Trip details not found"); }
    if (!empty($td["change_vehicle_no"])) {
        $conn->rollback();
        respond(false, "New vehicle already started for this trip");
    }

    $trip_detail_id = (int)$td["trip_detail_id"];

    // 3. Build replacement trip code
    $originalTripCode = trim((string)($tsRow["trip_code"] ?? ""));
    $replacementCode  = $originalTripCode !== ""
        ? $originalTripCode . "-RT"
        : "RT-" . $transport_service_id;

    // 4. Save photo
    $uploadDir = __DIR__ . "/../uploads/trips/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $allowed = ["jpg", "jpeg", "png", "webp"];
    $ext     = strtolower(pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) { $conn->rollback(); respond(false, "Invalid photo type"); }

    $fileName = "vnew_{$transport_service_id}_" . time() . ".{$ext}";
    if (!move_uploaded_file($_FILES["photo"]["tmp_name"], $uploadDir . $fileName)) {
        $conn->rollback(); respond(false, "Failed to save photo");
    }
    $dbPhotoPath = "uploads/trips/" . $fileName;

    // 5. Store Vehicle B start data in trip_details
    $updTd = $conn->prepare("
        UPDATE trip_details SET
            change_vehicle_no             = ?,
            change_vehicle_start_odometer = ?,
            change_vehicle_start_photo    = ?,
            change_vehicle_start_fuel     = ?,
            change_vehicle_remark         = ?,
            updated_at                    = NOW()
        WHERE trip_detail_id = ?
    ");
    // types: s=new_vehicle_no, i=start_meter, s=photo, d=start_fuel, s=remark, i=trip_detail_id
    $updTd->bind_param("sisdsi",
        $new_vehicle_no,
        $start_meter,
        $dbPhotoPath,
        $start_fuel,
        $remark,
        $trip_detail_id
    );
    $updTd->execute();
    $updTd->close();

    // 6. Update transport_services: status → IN_PROGRESS, store new trip code & destination
    $updTs = $conn->prepare("
        UPDATE transport_services SET
            status                     = 'IN_PROGRESS',
            change_vehicle_trip_code   = ?,
            change_vehicle_destination = ?,
            updated_at                 = NOW()
        WHERE id = ?
    ");
    $updTs->bind_param("ssi", $replacementCode, $destination, $transport_service_id);
    $updTs->execute();
    $updTs->close();

    $conn->commit();

    respond(true, "New vehicle started successfully", [
        "trip_detail_id"        => $trip_detail_id,
        "new_vehicle_no"        => $new_vehicle_no,
        "replacement_trip_code" => $replacementCode,
        "destination"           => $destination,
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}
