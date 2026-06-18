<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
    echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
    exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
    http_response_code(405);
    respond(false, "Method not allowed");
}

$employee_id = (int)($_GET["employee_id"] ?? 0);
if ($employee_id <= 0) respond(false, "employee_id required");

try {

    $stmt = $conn->prepare("
        SELECT
            ts.id,
            ts.status,
            ts.vehicle_no,
            ts.trip_code,
            ts.change_vehicle_trip_code,       -- ← new
            ts.change_vehicle_destination,     -- ← new
            ts.dropoff_location,
            ts.pickup_location,
            ts.type,
            ts.assigned_start_at,
            ts.assigned_end_at,
            ts.manager_id,
            ts.chauffer_name,
            ts.chauffer_phone,
            ts.companion_employee_ids,
            ts.created_at,
            COALESCE(m.preferred_name, '') AS manager_name,
            td.trip_start_odometer,
            td.trip_end_odometer,
            td.end_trip_fuel,
            td.change_vehicle_no,
            td.change_vehicle_start_odometer,
            td.change_vehicle_start_fuel,
            td.change_vehicle_end_odometer,
            td.change_vehicle_remark
        FROM transport_services ts
        LEFT JOIN employees m ON m.employee_id = ts.manager_id
        LEFT JOIN trip_details td
            ON td.trip_detail_id = (
                SELECT t2.trip_detail_id
                FROM trip_details t2
                WHERE t2.transport_service_id = ts.id
                ORDER BY t2.trip_detail_id DESC
                LIMIT 1
            )
        WHERE ts.employee_id = ?
        AND ts.type        = 'office'
        AND ts.deleted_at  IS NULL
        ORDER BY ts.created_at DESC
    ");

    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($r = $result->fetch_assoc()) {

        // Resolve companion names from JSON IDs
        $companion_ids = json_decode($r["companion_employee_ids"] ?? "[]", true) ?? [];
        $companions    = [];

        if (!empty($companion_ids) && is_array($companion_ids)) {
            $placeholders = implode(",", array_fill(0, count($companion_ids), "?"));
            $types        = str_repeat("i", count($companion_ids));

            $cStmt = $conn->prepare("
                SELECT
                    employee_id,
                    COALESCE(NULLIF(TRIM(preferred_name), ''), TRIM(full_name)) AS name
                FROM employees
                WHERE employee_id IN ($placeholders)
            ");
            $cStmt->bind_param($types, ...$companion_ids);
            $cStmt->execute();
            $cResult = $cStmt->get_result();
            $cStmt->close();

            while ($c = $cResult->fetch_assoc()) {
                $companions[] = [
                    "employee_id" => (int)    $c["employee_id"],
                    "name"        => (string) $c["name"],
                ];
            }
        }

        // Determine if this trip had a vehicle change
        $status         = (string)($r["status"] ?? "");
        $isVehicleChanging = strtoupper($status) === "VEHICLE_CHANGING";
        $changeVehicleNo   = (string)($r["change_vehicle_no"] ?? "");
        $isChanged         = $changeVehicleNo !== "";

        // For changed-vehicle trips, the FINAL end meter is in change_vehicle_end_odometer.
        // For normal trips, it's in trip_end_odometer.
        $tripEndOdometer = $isChanged && $r["change_vehicle_end_odometer"] !== null
            ? (int)$r["change_vehicle_end_odometer"]
            : ($r["trip_end_odometer"] !== null ? (int)$r["trip_end_odometer"] : null);

        // Vehicle A's end meter — present during VEHICLE_CHANGING and COMPLETED-with-change
        $oldVehicleEndMeter = ($isVehicleChanging || $isChanged) && $r["trip_end_odometer"] !== null
            ? (int)$r["trip_end_odometer"] : null;
        $oldVehicleEndFuel  = ($isVehicleChanging || $isChanged) && $r["end_trip_fuel"] !== null
            ? (float)$r["end_trip_fuel"] : null;

        // Total distance calculation
        if ($isChanged
            && $r["trip_end_odometer"]               !== null
            && $r["change_vehicle_start_odometer"]   !== null
            && $r["change_vehicle_end_odometer"]     !== null) {
            $seg1       = max(0, (int)$r["trip_end_odometer"] - (int)($r["trip_start_odometer"] ?? 0));
            $seg2       = max(0, (int)$r["change_vehicle_end_odometer"] - (int)$r["change_vehicle_start_odometer"]);
            $distanceKm = $seg1 + $seg2;
        } elseif (!$isChanged && $r["trip_end_odometer"] !== null && $r["trip_start_odometer"] !== null) {
            $distanceKm = max(0, (int)$r["trip_end_odometer"] - (int)$r["trip_start_odometer"]);
        } else {
            $distanceKm = null;
        }

        $rows[] = [
            "id"             => (int)    $r["id"],
            "status"         => $status,
            "vehicleNo"      => (string)($r["vehicle_no"]       ?? ""),
            "vehicleName"    => "",
            "tripCode"       => (string)($r["trip_code"]        ?? ""),
            "destination"    => (string)($r["dropoff_location"] ?? ""),
            "pickupLocation" => (string)($r["pickup_location"]  ?? ""),
            "reason"         => (string)($r["type"]             ?? ""),
            "created_at"     => $r["created_at"] ?? "",
            "approvedById"   => (int)   ($r["manager_id"]       ?? 0),
            "approvedByName" => (string)($r["manager_name"]     ?? ""),
            "fromDate"       => $r["assigned_start_at"]
                ? date("m/d/Y", strtotime($r["assigned_start_at"])) : "",
            "toDate"         => $r["assigned_end_at"]
                ? date("m/d/Y", strtotime($r["assigned_end_at"])) : "",
            "chaufferName"   => (string)($r["chauffer_name"]    ?? ""),
            "chaufferPhone"  => (string)($r["chauffer_phone"]   ?? ""),
            "companions"     => $companions,

            // Odometer / fuel fields
            "startMeter"            => $r["trip_start_odometer"] !== null ? (int)$r["trip_start_odometer"]   : null,
            "tripStartOdometer"     => $r["trip_start_odometer"] !== null ? (float)$r["trip_start_odometer"] : null,
            "tripEndOdometer"       => $tripEndOdometer,
            "distanceKm"            => $distanceKm,

            // Vehicle-change fields
            "changeVehicleNo"         => $changeVehicleNo,
            "oldVehicleEndMeter"      => $oldVehicleEndMeter,
            "oldVehicleEndFuel"       => $oldVehicleEndFuel,
            "changeVehicleStartMeter" => $r["change_vehicle_start_odometer"] !== null ? (int)$r["change_vehicle_start_odometer"] : null,
            "changeVehicleEndMeter"   => $r["change_vehicle_end_odometer"]   !== null ? (int)$r["change_vehicle_end_odometer"]   : null,
            "changeVehicleRemark"     => (string)($r["change_vehicle_remark"] ?? ""),

            "changeVehicleTripCode"    => (string)($r["change_vehicle_trip_code"]   ?? ""),
            "changeVehicleDestination" => (string)($r["change_vehicle_destination"] ?? ""),
        ];
    }

    $stmt->close();
    respond(true, "Trips loaded", $rows);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}
