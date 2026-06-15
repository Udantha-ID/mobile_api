<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

$manager_id = $_GET["manager_id"] ?? "";
if ($manager_id === "") {
    echo json_encode(["success" => false, "message" => "manager_id is required"]);
    exit;
}

try {

    $sql = "
        SELECT
            ts.id AS request_id,
            ts.employee_id,
            ts.manager_id,
            ts.status,
            ts.type,
            ts.vehicle_no,
            ts.chauffer_phone,
            ts.chauffer_name,
            ts.assigned_start_at,
            ts.assigned_end_at,
            ts.dropoff_location AS destination,
            ts.pickup_location,
            ts.trip_code,
            ts.companion_employee_ids,
            ts.created_at,

            e.employee_code,
            COALESCE(NULLIF(TRIM(e.preferred_name), ''), TRIM(e.full_name)) AS employee_name,
            jt.name AS job_title_name

        FROM transport_services ts
        JOIN employees e       ON e.employee_id      = ts.employee_id
        LEFT JOIN employee_job ej ON ej.employee_id  = ts.employee_id
        LEFT JOIN job_titles   jt ON jt.job_title_id = ej.job_title_id

        WHERE ts.manager_id = ?
          AND ts.status     = 'PENDING'
          AND ts.type       = 'office'
          AND ts.deleted_at IS NULL

        ORDER BY ts.created_at DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $manager_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];

    while ($row = $res->fetch_assoc()) {

        // Resolve companion names from JSON IDs
        $companion_ids = json_decode($row["companion_employee_ids"] ?? "[]", true) ?? [];
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

        $row["from_date"]  = $row["assigned_start_at"]
            ? date("Y-m-d", strtotime($row["assigned_start_at"])) : "";
        $row["to_date"]    = $row["assigned_end_at"]
            ? date("Y-m-d", strtotime($row["assigned_end_at"]))   : "";
        $row["companions"] = $companions;

        unset($row["companion_employee_ids"]); // already resolved
        $rows[] = $row;
    }

    $stmt->close();
    echo json_encode(["success" => true, "data" => $rows]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}