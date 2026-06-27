<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    // ALL approved PERSONAL transport requests
    $sql = "
        SELECT
            ts.id,
            ts.type,
            ts.vehicle_no,
            ts.vehicle_type,
            ts.trip_code,
            ts.status,
            ts.assigned_start_at,
            ts.assigned_end_at,
            e.preferred_name AS preferred_name,
            jt.name AS job_title
        FROM transport_services ts

        LEFT JOIN employees e
            ON e.employee_id = ts.employee_id

        LEFT JOIN employee_job ej
            ON ej.employee_id = ts.employee_id

        LEFT JOIN job_titles jt
            ON jt.job_title_id = ej.job_title_id

        WHERE ts.type = 'personal'
          AND ts.status = 'APPROVED'
          AND ts.deleted_at IS NULL

        ORDER BY ts.created_at DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute();

    $result = $stmt->get_result();
    $stmt->close();

    $records = [];

    while ($row = $result->fetch_assoc()) {

        $records[] = [
            "id"                => (int) $row["id"],
            "employee_name"     => $row["preferred_name"] ?? "Unknown",
            "job_title"         => $row["job_title"] ?? "Unknown",
            "type"              => $row["type"],
            "vehicle_no"        => $row["vehicle_no"],
            "vehicle_type"      => $row["vehicle_type"],
            "trip_code"         => $row["trip_code"],
            "assigned_start_at" => $row["assigned_start_at"],
            "assigned_end_at"   => $row["assigned_end_at"],
            "status"            => $row["status"],
        ];
    }

    ob_clean();

    echo json_encode([
        "success" => true,
        "total"   => count($records),
        "records" => $records
    ]);

    exit;

} catch (Throwable $e) {

    http_response_code(500);

    ob_clean();

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);

    exit;
}
?>