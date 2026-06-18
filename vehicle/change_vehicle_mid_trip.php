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

$transport_service_id = (int)trim($_POST["transport_service_id"] ?? "");
$old_end_meter        = (int)trim($_POST["old_end_meter"]        ?? "");
$old_end_fuel         = (float)trim($_POST["old_end_fuel"]       ?? "");
$new_vehicle_no       = strtoupper(trim($_POST["new_vehicle_no"] ?? ""));
$new_start_meter      = (int)trim($_POST["new_start_meter"]      ?? "");
$new_start_fuel       = (float)trim($_POST["new_start_fuel"]     ?? "");
$remark               = trim($_POST["remark"]                    ?? "") ?: null;

if ($transport_service_id <= 0)     respond(false, "transport_service_id required");
if ($old_end_meter        <= 0)     respond(false, "old_end_meter required");
if ($old_end_fuel < 0 || $old_end_fuel > 100) respond(false, "old_end_fuel must be 0-100");
if ($new_vehicle_no       === "")   respond(false, "new_vehicle_no required");
if ($new_start_meter      <= 0)     respond(false, "new_start_meter required");
if ($new_start_fuel < 0 || $new_start_fuel > 100) respond(false, "new_start_fuel must be 0-100");

if (!isset($_FILES["old_end_photo"])   || $_FILES["old_end_photo"]["error"]   !== UPLOAD_ERR_OK)
    respond(false, "old_end_photo required");
if (!isset($_FILES["new_start_photo"]) || $_FILES["new_start_photo"]["error"] !== UPLOAD_ERR_OK)
    respond(false, "new_start_photo required");

$allowed = ["jpg", "jpeg", "png", "webp"];

try {
    $conn->begin_transaction();

    // ── 1. Verify trip is IN_PROGRESS ─────────────────────────────────────
    $chk = $conn->prepare(
        "SELECT status FROM transport_services WHERE id = ? AND deleted_at IS NULL LIMIT 1"
    );
    $chk->bind_param("i", $transport_service_id);
    $chk->execute();
    $tsRow = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$tsRow) { $conn->rollback(); respond(false, "Trip not found"); }
    if (strtoupper($tsRow["status"]) !== "IN_PROGRESS") {
        $conn->rollback(); respond(false, "Trip must be IN_PROGRESS to change vehicle");
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

    if (!$td) { $conn->rollback(); respond(false, "No active trip segment found"); }

    // Only allow one vehicle change per trip row
    if (!empty($td["change_vehicle_no"])) {
        $conn->rollback();
        respond(false, "Vehicle already changed once for this trip. Stop the trip to complete.");
    }

    if ($old_end_meter < (int)$td["trip_start_odometer"]) {
        $conn->rollback();
        respond(false, "End meter cannot be less than start meter ({$td['trip_start_odometer']})");
    }

    $trip_detail_id = (int)$td["trip_detail_id"];

    // ── 3. Save photos ────────────────────────────────────────────────────
    $uploadDir = __DIR__ . "/../uploads/trips/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    // Old vehicle end photo
    $oldExt = strtolower(pathinfo($_FILES["old_end_photo"]["name"], PATHINFO_EXTENSION));
    if (!in_array($oldExt, $allowed)) { $conn->rollback(); respond(false, "Invalid old_end_photo type"); }
    $oldFileName = "change_old_{$transport_service_id}_" . time() . ".{$oldExt}";
    if (!move_uploaded_file($_FILES["old_end_photo"]["tmp_name"], $uploadDir . $oldFileName)) {
        $conn->rollback(); respond(false, "Failed to save old end photo");
    }
    $oldPhotoPath = "uploads/trips/" . $oldFileName;

    // New vehicle start photo
    $newExt = strtolower(pathinfo($_FILES["new_start_photo"]["name"], PATHINFO_EXTENSION));
    if (!in_array($newExt, $allowed)) { $conn->rollback(); respond(false, "Invalid new_start_photo type"); }
    $newFileName = "change_new_{$transport_service_id}_" . time() . ".{$newExt}";
    if (!move_uploaded_file($_FILES["new_start_photo"]["tmp_name"], $uploadDir . $newFileName)) {
        $conn->rollback(); respond(false, "Failed to save new start photo");
    }
    $newPhotoPath = "uploads/trips/" . $newFileName;

    // ── 4. Update trip_details with change vehicle data ───────────────────
    // Original vehicle end columns + change vehicle start columns
    $upd = $conn->prepare("
        UPDATE trip_details SET
            trip_end_odometer             = ?,
            trip_end_odometer_photo       = ?,
            end_trip_fuel                 = ?,
            change_vehicle_no             = ?,
            change_vehicle_datetime       = NOW(),
            change_vehicle_start_odometer = ?,
            change_vehicle_start_photo    = ?,
            change_vehicle_start_fuel     = ?,
            change_vehicle_remark         = ?,
            updated_at                    = NOW()
        WHERE trip_detail_id = ?
    ");
    $upd->bind_param("isdsisssi",
        $old_end_meter,
        $oldPhotoPath,
        $old_end_fuel,
        $new_vehicle_no,
        $new_start_meter,
        $newPhotoPath,
        $new_start_fuel,
        $remark,
        $trip_detail_id
    );
    $upd->execute();
    $upd->close();
    $conn->commit();
    

    // ── 5. PUSH NOTIFICATION to manager (non-blocking) ────────────────────
    try {
        require_once __DIR__ . "/../notifications/fcm_helper.php";

        // Get driver (employee) display name
        $stEmp = $conn->prepare(
            "SELECT preferred_name, full_name FROM employees WHERE employee_id = (
                SELECT employee_id FROM transport_services WHERE id = ? LIMIT 1
             ) LIMIT 1"
        );
        $stEmp->bind_param("i", $transport_service_id);
        $stEmp->execute();
        $empRow = $stEmp->get_result()->fetch_assoc();
        $stEmp->close();

        $driverName = trim((string)($empRow["preferred_name"] ?? ""));
        if ($driverName === "") $driverName = trim((string)($empRow["full_name"] ?? ""));
        if ($driverName === "") $driverName = "The driver";

        // Get original vehicle_no and trip_code from transport_services
        $stTs = $conn->prepare(
            "SELECT vehicle_no, trip_code, manager_id FROM transport_services WHERE id = ? LIMIT 1"
        );
        $stTs->bind_param("i", $transport_service_id);
        $stTs->execute();
        $tsData = $stTs->get_result()->fetch_assoc();
        $stTs->close();

        $originalVehicle = (string)($tsData["vehicle_no"] ?? "");
        $tripCode        = (string)($tsData["trip_code"]  ?? "");
        $managerId       = (int)($tsData["manager_id"]    ?? 0);

        // Get manager FCM token
        if ($managerId > 0) {
            $stMgr = $conn->prepare(
                "SELECT fcm_token FROM employees WHERE employee_id = ? LIMIT 1"
            );
            $stMgr->bind_param("i", $managerId);
            $stMgr->execute();
            $mgrRow = $stMgr->get_result()->fetch_assoc();
            $stMgr->close();

            if (!empty($mgrRow["fcm_token"])) {
                FcmHelper::send(
                    $mgrRow["fcm_token"],
                    "Vehicle Changed Mid Trip",
                    "$driverName changed vehicle from $originalVehicle to $new_vehicle_no" .
                        ($tripCode !== "" ? " (Trip: $tripCode)" : "") . ".",
                    [
                        "type"   => "vehicle_changed_mid_trip",
                        "tripId" => (string)$transport_service_id,
                    ]
                );
            }
        }

    } catch (Throwable $notifyError) {
        error_log("FCM notify (change_vehicle_mid_trip) failed: " . $notifyError->getMessage());
    }

    respond(true, "Vehicle changed successfully", [
        "trip_detail_id" => $trip_detail_id,
        "new_vehicle_no" => $new_vehicle_no,
    ]);

    respond(true, "Vehicle changed successfully", [
        "trip_detail_id" => $trip_detail_id,
        "new_vehicle_no" => $new_vehicle_no,
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}