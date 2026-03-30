<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 1);
error_reporting(E_ALL);

// Optional: logged-in user ID and job title (for filtering)
$loggedInUserId = $_GET["user_id"] ?? null; // e.g., pass current GM id
$jobTitleFilter = 2; // General Manager

try {
    $sql = "
    SELECT
        ts.id AS request_id,
        ts.employee_id,
        ts.manager_id AS hod_id,
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
        ts.created_at,
        ts.hod_comment,  -- include HOD comment

        e.employee_code,
        COALESCE(NULLIF(TRIM(e.preferred_name), ''), TRIM(e.full_name)) AS employee_name,
        jt.job_title_id,
        jt.name AS job_title_name

    FROM transport_services ts
    JOIN employees e ON e.employee_id = ts.employee_id
    LEFT JOIN employee_job ej ON ej.employee_id = ts.employee_id
    LEFT JOIN job_titles jt ON jt.job_title_id = ej.job_title_id

    WHERE ts.status = 'HOD_APPROVED'
      AND ts.type = 'personal'
      AND ts.deleted_at IS NULL
    ";

    // Add filter if logged-in user is GM
    $params = [];
    $types = "";
    if ($loggedInUserId) {
        $sql .= " AND e.employee_id = ?";
        $params[] = $loggedInUserId;
        $types .= "i";

        // Only include if job_title_id = 2
        $sql .= " AND jt.job_title_id = ?";
        $params[] = $jobTitleFilter;
        $types .= "i";
    }

    $sql .= " ORDER BY ts.created_at DESC";

    $stmt = $conn->prepare($sql);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $row["from_date"] = $row["assigned_start_at"] ? date("Y-m-d", strtotime($row["assigned_start_at"])) : "";
        $row["to_date"]   = $row["assigned_end_at"] ? date("Y-m-d", strtotime($row["assigned_end_at"])) : "";
        $rows[] = $row;
    }

    echo json_encode(["success" => true, "data" => $rows]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}