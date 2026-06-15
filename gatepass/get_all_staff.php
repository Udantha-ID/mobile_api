<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    $sql = "
        SELECT 
            e.employee_id,
            COALESCE(NULLIF(TRIM(e.preferred_name), ''), TRIM(e.full_name)) AS name,
            ej.job_title_id,
            jt.name AS job_title
        FROM employees e
        LEFT JOIN employee_job ej 
            ON ej.employee_id = e.employee_id
        LEFT JOIN job_titles jt 
            ON jt.job_title_id = ej.job_title_id
        WHERE e.employment_status = 'Active'
        ORDER BY name ASC
    ";

    $result = $conn->query($sql);

    $members = [];

    while ($row = $result->fetch_assoc()) {

        $members[] = [
            "employee_id" => $row["employee_id"],
            "name" => $row["name"],
            "job_title_id" => $row["job_title_id"],
            "job_title" => $row["job_title"] ?? "Unknown"
        ];
    }

    ob_clean();
    echo json_encode([
        "success" => true,
        "message" => "Staff with job titles loaded",
        "members" => $members
    ]);
    exit;

} catch (Throwable $e) {

    http_response_code(500);
    ob_clean();
    echo json_encode([
        "success" => false,
        "message" => "Server error"
    ]);
    exit;
}