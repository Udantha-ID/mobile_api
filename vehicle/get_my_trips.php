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
            ts.dropoff_location,
            ts.pickup_location,
            ts.type,
            ts.assigned_start_at,
            ts.assigned_end_at,
            ts.manager_id,
            ts.chauffer_name,
            ts.chauffer_phone,
            ts.companion_employee_ids,
            COALESCE(m.preferred_name, '') AS manager_name,
            td.trip_start_odometer,
            td.trip_end_odometer,
            CASE
                WHEN td.trip_end_odometer IS NOT NULL AND td.trip_start_odometer IS NOT NULL
                THEN (td.trip_end_odometer - td.trip_start_odometer)
                ELSE NULL
            END AS distance_km
        FROM transport_services ts
        LEFT JOIN employees m
            ON m.employee_id = ts.manager_id
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

        $rows[] = [
            "id"             => (int)    $r["id"],
            "status"         => (string) $r["status"],
            "vehicleNo"      => (string)($r["vehicle_no"]       ?? ""),
            "vehicleName"    => "",
            "tripCode"       => (string)($r["trip_code"]        ?? ""),
            "destination"    => (string)($r["dropoff_location"] ?? ""),
            "pickupLocation" => (string)($r["pickup_location"]  ?? ""),
            "reason"         => (string)($r["type"]             ?? ""),
            "approvedById"   => (int)   ($r["manager_id"]       ?? 0),
            "approvedByName" => (string)($r["manager_name"]     ?? ""),
            "fromDate"       => $r["assigned_start_at"]
                ? date("m/d/Y", strtotime($r["assigned_start_at"])) : "",
            "toDate"         => $r["assigned_end_at"]
                ? date("m/d/Y", strtotime($r["assigned_end_at"]))   : "",
            "chaufferName"   => (string)($r["chauffer_name"]    ?? ""),
            "chaufferPhone"  => (string)($r["chauffer_phone"]   ?? ""),
            "companions"     => $companions,
            "tripStartOdometer" => $r["trip_start_odometer"] !== null ? (float)$r["trip_start_odometer"] : null,
            "tripEndOdometer"   => $r["trip_end_odometer"]   !== null ? (float)$r["trip_end_odometer"]   : null,
            "distanceKm"        => $r["distance_km"]         !== null ? (float)$r["distance_km"]         : null,
        ];
    }

    $stmt->close();
    respond(true, "Trips loaded", $rows);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}