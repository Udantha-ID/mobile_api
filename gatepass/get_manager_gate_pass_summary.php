<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    $manager_id = isset($_GET["manager_id"]) ? intval($_GET["manager_id"]) : 0;

    if ($manager_id <= 0) {
        http_response_code(422);
        ob_clean();
        echo json_encode(["success" => false, "message" => "manager_id is required"]);
        exit;
    }

    // Only APPROVED records for this manager
    $sql = "
        SELECT
            gp.id,
            gp.employee_name,
            gp.gate_pass_date,
            gp.out_time,
            gp.return_time,
            gp.gate_pass_code,
            gp.status,
            jt.name AS job_title
        FROM gate_pass_requests gp
        LEFT JOIN employees    e  ON e.employee_id   = gp.employee_id
        LEFT JOIN employee_job ej ON ej.employee_id  = gp.employee_id
        LEFT JOIN job_titles   jt ON jt.job_title_id = ej.job_title_id
        WHERE gp.manager_id = ?
          AND gp.status     = 'APPROVED'
          AND gp.deleted_at IS NULL
        ORDER BY gp.gate_pass_date DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $manager_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = [
            "id"             => (int) $row["id"],
            "employee_name"  => $row["employee_name"],
            "job_title"      => $row["job_title"] ?? "Unknown",
            "gate_pass_date" => $row["gate_pass_date"],
            "out_time"       => $row["out_time"],
            "return_time"    => $row["return_time"],
            "gate_pass_code" => $row["gate_pass_code"],
            "status"         => $row["status"],
        ];
    }

    ob_clean();
    echo json_encode([
        "success" => true,
        "total"   => count($records),
        "records" => $records,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    exit;
}