<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    // ---------------------------
    // READ INPUT (POST JSON or GET)
    // ---------------------------
    $method = $_SERVER["REQUEST_METHOD"] ?? "GET";

    $employeeId = "";
    $departmentId = "";
    $fromDate = "";
    $toDate = "";

    if ($method === "POST") {
        $data = json_decode(file_get_contents("php://input"), true);
        if (!is_array($data)) {
            http_response_code(400);
            ob_clean();
            echo json_encode(["success" => false, "message" => "Invalid JSON"]);
            exit;
        }

        $employeeId   = trim((string)($data["employeeId"] ?? ""));
        $departmentId = trim((string)($data["departmentId"] ?? ""));
        $fromDate     = trim((string)($data["fromDate"] ?? ""));
        $toDate       = trim((string)($data["toDate"] ?? ""));
    } elseif ($method === "GET") {
        $employeeId   = trim((string)($_GET["employeeId"] ?? ""));
        $departmentId = trim((string)($_GET["departmentId"] ?? ""));
        $fromDate     = trim((string)($_GET["fromDate"] ?? ""));
        $toDate       = trim((string)($_GET["toDate"] ?? ""));
    } else {
        http_response_code(405);
        ob_clean();
        echo json_encode(["success" => false, "message" => "Method not allowed"]);
        exit;
    }

    // ---------------------------
    // BASIC VALIDATION
    // ---------------------------
    if ($employeeId === "" || $departmentId === "" || $fromDate === "" || $toDate === "") {
        http_response_code(400);
        ob_clean();
        echo json_encode([
            "success" => false,
            "message" => "employeeId, departmentId, fromDate, toDate required"
        ]);
        exit;
    }

    if (!ctype_digit($employeeId) || !ctype_digit($departmentId)) {
        http_response_code(400);
        ob_clean();
        echo json_encode(["success" => false, "message" => "employeeId and departmentId must be integers"]);
        exit;
    }

    $datePattern = '/^\d{4}-\d{2}-\d{2}$/';
    if (!preg_match($datePattern, $fromDate) || !preg_match($datePattern, $toDate)) {
        http_response_code(400);
        ob_clean();
        echo json_encode(["success" => false, "message" => "fromDate and toDate must be YYYY-MM-DD"]);
        exit;
    }
    
    $sql = "
    SELECT
        e.employee_id,
        COALESCE(NULLIF(TRIM(e.preferred_name), ''), TRIM(e.full_name)) AS name
    FROM employees e
    JOIN employee_job ej ON ej.employee_id = e.employee_id
    WHERE ej.department_id = ?
        AND e.employment_status = 'Active'
        AND e.employee_id <> ?

        -- EXCLUDE reporting managers
        AND NOT EXISTS (
        SELECT 1
        FROM employee_job ej2
        WHERE ej2.reporting_manager_id = e.employee_id
        )

        -- EXCLUDE employees on overlapping leave
        AND NOT EXISTS (
        SELECT 1
        FROM leave_requests lr
        WHERE lr.employee_id = e.employee_id
            AND lr.status IN ('APPROVED', 'PENDING')
            AND NOT (lr.leave_end_date < ? OR lr.leave_start_date > ?)
        )

    ORDER BY name
    ";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param("ssss", $departmentId, $employeeId, $fromDate, $toDate);

    $stmt->execute();
    $res = $stmt->get_result();

    $members = [];
    while ($row = $res->fetch_assoc()) {
        $members[] = [
            "id" => $row["employee_id"],
            "name" => $row["name"],
        ];
    }

    ob_clean();
    echo json_encode([
        "success" => true,
        "message" => "Relievers loaded",
        "members" => $members
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}