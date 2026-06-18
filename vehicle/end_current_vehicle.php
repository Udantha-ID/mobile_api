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
$end_meter            = trim($_POST["end_meter"]            ?? "");
$end_fuel             = trim($_POST["end_fuel"]             ?? "");

if ($transport_service_id === "" || !ctype_digit($transport_service_id))
    respond(false, "transport_service_id required numeric");
if ($end_meter === "" || !ctype_digit($end_meter))
    respond(false, "end_meter required numeric");
if ($end_fuel === "" || !is_numeric($end_fuel) || $end_fuel < 0 || $end_fuel > 100)
    respond(false, "end_fuel must be 0-100");
if (!isset($_FILES["photo"]) || $_FILES["photo"]["error"] !== UPLOAD_ERR_OK)
    respond(false, "photo is required");

$transport_service_id = (int)$transport_service_id;
$end_meter            = (int)$end_meter;
$end_fuel             = (float)$end_fuel;

try {
    $conn->begin_transaction();

    // ── 1. Verify IN_PROGRESS ─────────────────────────────────────────────
    $chk = $conn->prepare(
        "SELECT status FROM transport_services WHERE id = ? AND deleted_at IS NULL LIMIT 1"
    );
    $chk->bind_param("i", $transport_service_id);
    $chk->execute();
    $tsRow = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$tsRow) { $conn->rollback(); respond(false, "Trip not found"); }
    if (strtoupper($tsRow["status"]) !== "IN_PROGRESS") {
        $conn->rollback();
        respond(false, "Trip must be IN_PROGRESS to change vehicle");
    }

    // ── 2. Get current trip_details row ───────────────────────────────────
    $q = $conn->prepare("
        SELECT trip_detail_id, trip_start_odometer, change_vehicle_no
        FROM trip_details
        WHERE transport_service_id = ?
        ORDER BY trip_detail_id DESC LIMIT 1
    ");
    $q->bind_param("i", $transport_service_id);
    $q->execute();
    $td = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$td) { $conn->rollback(); respond(false, "Trip details not found"); }

    // Already changed once — cannot change again
    if (!empty($td["change_vehicle_no"])) {
        $conn->rollback();
        respond(false, "Vehicle already changed once for this trip");
    }

    // Validate meter
    if ($end_meter < (int)$td["trip_start_odometer"]) {
        $conn->rollback();
        respond(false, "End meter cannot be less than start meter ({$td['trip_start_odometer']})");
    }

    $trip_detail_id = (int)$td["trip_detail_id"];

    // ── 3. Save photo ─────────────────────────────────────────────────────
    $uploadDir = __DIR__ . "/../uploads/trips/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $allowed = ["jpg", "jpeg", "png", "webp"];
    $ext     = strtolower(pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) { $conn->rollback(); respond(false, "Invalid photo type"); }

    $fileName = "vend_{$transport_service_id}_" . time() . ".{$ext}";
    if (!move_uploaded_file($_FILES["photo"]["tmp_name"], $uploadDir . $fileName)) {
        $conn->rollback(); respond(false, "Failed to save photo");
    }
    $dbPhotoPath = "uploads/trips/" . $fileName;

    // ── 4. Record Vehicle A end in trip_details ───────────────────────────
    $upd = $conn->prepare("
        UPDATE trip_details SET
            trip_end_datetime       = NOW(),
            trip_end_odometer       = ?,
            trip_end_odometer_photo = ?,
            end_trip_fuel           = ?,
            change_vehicle_datetime = NOW(),
            updated_at              = NOW()
        WHERE trip_detail_id = ?
    ");
    $upd->bind_param("isdi", $end_meter, $dbPhotoPath, $end_fuel, $trip_detail_id);
    $upd->execute();
    $upd->close();

    // ── 5. Update status to VEHICLE_CHANGING ─────────────────────────────
    $updTs = $conn->prepare(
        "UPDATE transport_services SET status = 'VEHICLE_CHANGING', updated_at = NOW() WHERE id = ?"
    );
    $updTs->bind_param("i", $transport_service_id);
    $updTs->execute();
    $updTs->close();

    $conn->commit();

    respond(true, "Vehicle A recorded. Start new vehicle to continue.", [
        "trip_detail_id" => $trip_detail_id,
        "end_meter"      => $end_meter,
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}