<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null)
{
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ]);
    exit;
}

// Allow only POST
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    respond(false, "Method not allowed");
}

// Read JSON body
$raw = file_get_contents("php://input");
$body = json_decode($raw, true);

if (!is_array($body)) {
    respond(false, "Invalid JSON");
}

// Required fields
$employee_id  = (int)($body["employee_id"] ?? 0);
$manager_id   = (int)($body["manager_id"] ?? 0);
$vehicle_no   = trim($body["vehicle_no"] ?? "");
$from_date    = trim($body["from_date"] ?? "");
$to_date      = trim($body["to_date"] ?? "");
$reason       = trim($body["reason"] ?? "Office Service");
$vehicle_type = trim($body["vehicle_type"] ?? "-");

$chauffer_phone = trim($body["chauffer_phone"] ?? "");
$chauffer_name  = trim($body["chauffer_name"] ?? "");

$vehicle_id = (int)($body["vehicle_id"] ?? 0);

// Validate
if ($employee_id <= 0) {
    respond(false, "employee_id required");
}

if ($manager_id <= 0) {
    respond(false, "manager_id required");
}

if ($vehicle_no === "") {
    respond(false, "vehicle_no required");
}

if ($from_date === "") {
    respond(false, "from_date required");
}

if ($to_date === "") {
    respond(false, "to_date required");
}

if ($chauffer_phone === "") {
    respond(false, "chauffer_phone required");
}

if ($chauffer_name === "") {
    respond(false, "chauffer_name required");
}

if ($vehicle_id <= 0) {
    respond(false, "vehicle_id required");
}

// Convert dates
$assigned_start_at = $from_date . " 00:00:00";
$assigned_end_at   = $to_date . " 23:59:59";

// Defaults
$pickup_location  = "Head Office";
$dropoff_location = "-";

// Request details
$type   = "personal";
$status = "PENDING";

try {

    // =========================================================
    // CHECK EMPLOYEE PROBATION STATUS
    // =========================================================

    $probationStmt = $conn->prepare("
        SELECT employment_level
        FROM employee_job
        WHERE employee_id = ?
        LIMIT 1
    ");

    $probationStmt->bind_param("i", $employee_id);
    $probationStmt->execute();

    $probationResult = $probationStmt->get_result();

    if ($probationResult->num_rows > 0) {

        $employee = $probationResult->fetch_assoc();

        $employmentLevel = strtolower(trim($employee["employment_level"]));

        // Allow only permanent employees
        if ($employmentLevel !== "permanent") {

            respond(
                false,
                "Only permanent staff members can apply for Personal Vehicle requests."
            );
        }
    }

    $probationStmt->close();

    // =========================================================
    // CHECK EXISTING PENDING REQUEST
    // =========================================================

    $checkStmt = $conn->prepare("
        SELECT id
        FROM transport_services
        WHERE employee_id = ?
        AND status = 'PENDING'
        AND type = 'personal'
        LIMIT 1
    ");

    $checkStmt->bind_param("i", $employee_id);
    $checkStmt->execute();

    $result = $checkStmt->get_result();

    if ($result->num_rows > 0) {

        respond(
            false,
            "You already have a pending request. You can cancel your request or wait until it is completed."
        );
    }

    $checkStmt->close();

    // =========================================================
    // INSERT REQUEST
    // =========================================================

    $stmt = $conn->prepare("
        INSERT INTO transport_services
        (
            source_id,
            type,
            vehicle_type,
            vehicle_id,
            vehicle_no,
            chauffer_phone,
            chauffer_name,
            employee_id,
            manager_id,
            status,
            assigned_start_at,
            pickup_location,
            dropoff_location,
            assigned_end_at,
            passenger_count,
            trip_code,
            created_at,
            updated_at
        )
        VALUES
        (
            0,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            1,
            NULL,
            NOW(),
            NOW()
        )
    ");

    $stmt->bind_param(
        "ssissssssssss",
        $type,
        $vehicle_type,
        $vehicle_id,
        $vehicle_no,
        $chauffer_phone,
        $chauffer_name,
        $employee_id,
        $manager_id,
        $status,
        $assigned_start_at,
        $pickup_location,
        $dropoff_location,
        $assigned_end_at
    );

    $stmt->execute();

    $id = $stmt->insert_id;

    $stmt->close();

    respond(
        true,
        "Request created successfully",
        ["id" => $id]
    );

} catch (Throwable $e) {

    http_response_code(500);

    respond(
        false,
        "Server error: " . $e->getMessage()
    );
}
?>