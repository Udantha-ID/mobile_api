<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 1);
error_reporting(E_ALL);

function respond($success, $message, $data = []) {
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ]);
    exit;
}

try {

    // HARD CODED GM ID
    $loggedInUserId = 14;

    $sql = "
        SELECT
            ts.id AS request_id,
            ts.employee_id,
            ts.manager_id,
            ts.status,
            ts.type,
            ts.vehicle_no,
            ts.vehicle_id,
            ts.chauffer_phone,
            ts.chauffer_name,
            ts.assigned_start_at,
            ts.assigned_end_at,
            ts.dropoff_location,
            ts.pickup_location,
            ts.trip_code,
            ts.created_at,
            ts.hod_comment,

            e.employee_code,
            COALESCE(NULLIF(TRIM(e.preferred_name), ''), TRIM(e.full_name)) AS employee_name,

            jt.job_title_id,
            jt.name AS job_title_name

        FROM transport_services ts
        JOIN employees e ON e.employee_id = ts.employee_id
        LEFT JOIN employee_job ej ON ej.employee_id = ts.employee_id
        LEFT JOIN job_titles jt ON jt.job_title_id = ej.job_title_id

        WHERE ts.type = 'personal'
          AND ts.deleted_at IS NULL
          AND (
                ts.status = 'HOD_APPROVED'
                OR (ts.status = 'PENDING' AND ts.manager_id = ?)
          )

        ORDER BY ts.created_at DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $loggedInUserId);

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {

        $row["from_date"] = $row["assigned_start_at"]
            ? date("Y-m-d", strtotime($row["assigned_start_at"]))
            : "";

        $row["to_date"] = $row["assigned_end_at"]
            ? date("Y-m-d", strtotime($row["assigned_end_at"]))
            : "";

        $rows[] = $row;
    }

    respond(true, "Success", $rows);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}